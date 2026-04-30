<?php
/**
 * paymongo_success.php
 * ─────────────────────────────────────────────────────────────
 * User lands here after successful PayMongo payment.
 *
 * UPDATED FLOW (with AI permit verification):
 *  1. Fetch checkout session from PayMongo API to verify payment
 *  2. If paid and not yet processed → mark payment as paid
 *  3. Tenant status = 'pending' (NOT auto-activated yet)
 *  4. Trigger Gemini AI permit verification (permit_verify.php)
 *  5. If AI approved  → auto-activate tenant ✅
 *  6. If AI rejected  → keep pending, notify SA to review ❌
 *  7. If manual_review → keep pending, SA will check 🔍
 *  8. Show appropriate confirmation UI
 * ─────────────────────────────────────────────────────────────
 */

session_start();
require 'db.php';
require 'paymongo_config.php';
require_once __DIR__ . '/permit_verify.php';  // Gemini verifier

$tenant_id = intval($_GET['tenant'] ?? 0);
$user_id   = intval($_GET['user']   ?? 0);

// ── Fetch tenant ──────────────────────────────────────────────
$tenant = null;
if ($tenant_id) {
    $stmt = $pdo->prepare("SELECT * FROM tenants WHERE id = ?");
    $stmt->execute([$tenant_id]);
    $tenant = $stmt->fetch();
}

$plan              = $tenant['plan']                ?? '';
$payment_status    = $tenant['payment_status']      ?? 'pending';
$session_id        = $tenant['paymongo_session_id'] ?? '';
$permit_status     = $tenant['business_permit_status'] ?? 'pending';
$auto_processed    = false;
$permit_result     = null;
$permit_ai_status  = null;

// ── WEBHOOK FALLBACK ─────────────────────────────────────────
if ($tenant_id && $user_id && $payment_status !== 'paid' && $session_id) {

    // 1. Verify with PayMongo API
    $ch = curl_init("https://api.paymongo.com/v1/checkout_sessions/{$session_id}");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Accept: application/json',
            'Authorization: Basic ' . base64_encode(PAYMONGO_SECRET_KEY . ':'),
        ],
    ]);
    $raw      = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $cs                = json_decode($raw, true);
    $pm_payment_status = $cs['data']['attributes']['payment_status'] ?? '';

    if ($httpCode === 200 && $pm_payment_status === 'paid') {

        // Resolve payment method
        $payments       = $cs['data']['attributes']['payments'] ?? [];
        $payment_method = '';
        if (!empty($payments[0])) {
            $pm             = $payments[0]['attributes']['payment_method_used'] ?? '';
            $payment_method = strtoupper($pm);
        }

        try {
            // 2. Mark payment_status = paid
            $pdo->prepare("
                UPDATE tenants
                SET payment_status   = 'paid',
                    paymongo_paid_at = NOW()
                WHERE id = ?
            ")->execute([$tenant_id]);

            // 3. Deduplicate check
            $dup = $pdo->prepare("
                SELECT id FROM subscription_renewals
                WHERE tenant_id = ? AND payment_reference = ?
                LIMIT 1
            ");
            $dup->execute([$tenant_id, $session_id]);

            if (!$dup->fetch()) {

                $u_row = $pdo->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
                $u_row->execute([$user_id]);
                $u_row = $u_row->fetch();

                if ($tenant && $u_row) {

                    // 4. Generate slug if missing
                    $slug = $tenant['slug'] ?? '';
                    if (empty($slug)) {
                        $base_slug = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $tenant['business_name']));
                        $slug      = $base_slug;
                        $ctr       = 1;
                        while (true) {
                            $chk = $pdo->prepare("SELECT id FROM tenants WHERE slug = ? AND id != ?");
                            $chk->execute([$slug, $tenant_id]);
                            if (!$chk->fetch()) break;
                            $slug = $base_slug . $ctr++;
                        }
                        $pdo->prepare("UPDATE tenants SET slug = ? WHERE id = ?")->execute([$slug, $tenant_id]);
                    }

                    // 5. Record payment in subscription_renewals
                    $plan_amounts = ['Starter' => 0, 'Pro' => 999, 'Enterprise' => 2499];
                    $sub_amount   = $plan_amounts[$plan] ?? 0;
                    if ($sub_amount > 0) {
                        try {
                            $pdo->prepare("
                                INSERT INTO subscription_renewals
                                    (tenant_id, plan, billing_cycle, payment_method, payment_reference,
                                     amount, status, requested_at, reviewed_at, new_subscription_end)
                                VALUES (?, ?, 'monthly', ?, ?, ?, 'pending', NOW(), NULL, NULL)
                            ")->execute([
                                $tenant_id, $plan,
                                'PayMongo — ' . $payment_method,
                                $session_id, $sub_amount,
                            ]);
                        } catch (PDOException $e) {
                            error_log("[SuccessFlow] subscription_renewals insert error: " . $e->getMessage());
                        }
                    }

                    // ── 6. Run AI Permit Verification ─────────────────
                    try {
                        $permit_result    = verifyBusinessPermit($tenant_id, $pdo);
                        $permit_ai_status = $permit_result['status'];
                        saveVerificationResult($tenant_id, $permit_result, $pdo);
                    } catch (Throwable $aiErr) {
                        error_log("[PermitVerify] AI error: " . $aiErr->getMessage());
                        $permit_ai_status = 'manual_review';
                    }

                    // ── 7. Activate if AI approved, else keep pending ──
                    if ($permit_ai_status === 'ai_approved') {

                        // Auto-activate! ✅
                        $pdo->prepare("
                            UPDATE tenants SET
                                status              = 'active',
                                subscription_start  = CURDATE(),
                                subscription_end    = DATE_ADD(CURDATE(), INTERVAL 1 MONTH),
                                subscription_status = 'active',
                                renewal_reminded_7d = 0,
                                renewal_reminded_3d = 0,
                                renewal_reminded_1d = 0
                            WHERE id = ?
                        ")->execute([$tenant_id]);

                        $pdo->prepare("
                            UPDATE users SET status = 'approved', approved_at = NOW()
                            WHERE id = ?
                        ")->execute([$user_id]);

                        // Update renewal record to approved
                        try {
                            $pdo->prepare("
                                UPDATE subscription_renewals
                                SET status = 'approved',
                                    reviewed_at = NOW(),
                                    new_subscription_end = DATE_ADD(CURDATE(), INTERVAL 1 MONTH)
                                WHERE tenant_id = ? AND payment_reference = ?
                            ")->execute([$tenant_id, $session_id]);
                        } catch (Throwable $e) {}

                        // Send activation email
                        try {
                            require_once __DIR__ . '/mailer.php';
                            $inv = $pdo->prepare("
                                SELECT token FROM tenant_invitations
                                WHERE tenant_id = ? AND status = 'pending'
                                ORDER BY created_at DESC LIMIT 1
                            ");
                            $inv->execute([$tenant_id]);
                            $inv_row = $inv->fetch();

                            if ($inv_row) {
                                sendTenantInvitation(
                                    $tenant['email'], $tenant['owner_name'],
                                    $tenant['business_name'], $inv_row['token'], $slug
                                );
                            } else {
                                sendTenantApproved(
                                    $tenant['email'], $tenant['owner_name'],
                                    $tenant['business_name'], $slug
                                );
                            }
                        } catch (Throwable $mail_err) {
                            error_log("[SuccessFlow] Email error: " . $mail_err->getMessage());
                        }

                        $auto_processed = true;

                    } else {
                        // AI rejected or manual review → stay as 'pending'
                        // Super Admin will review in dashboard
                        // Optionally notify SA via email here
                        try {
                            require_once __DIR__ . '/mailer.php';
                            // Notify tenant that payment received, verification pending
                            if (function_exists('sendPaymentReceivedPendingVerification')) {
                                sendPaymentReceivedPendingVerification(
                                    $tenant['email'],
                                    $tenant['owner_name'],
                                    $tenant['business_name'],
                                    $permit_ai_status
                                );
                            }
                        } catch (Throwable $e) {}

                        $auto_processed = false;
                    }

                    // Re-fetch tenant for display
                    $stmt   = $pdo->prepare("SELECT * FROM tenants WHERE id = ?");
                    $stmt->execute([$tenant_id]);
                    $tenant = $stmt->fetch();
                    $plan   = $tenant['plan'] ?? $plan;
                }

            } else {
                // Already processed by webhook
                $permit_ai_status = $tenant['business_permit_status'] ?? 'pending';
                $auto_processed   = ($tenant['status'] === 'active');
            }

        } catch (Throwable $e) {
            error_log("[SuccessFlow] Error: " . $e->getMessage());
        }
    }

} elseif ($payment_status === 'paid') {
    $permit_ai_status = $tenant['business_permit_status'] ?? 'pending';
    $auto_processed   = ($tenant['status'] === 'active');
}

// Clear pending session data
unset(
    $_SESSION['pending_tenant_id'],
    $_SESSION['pending_user_id'],
    $_SESSION['pending_plan'],
    $_SESSION['pending_email'],
    $_SESSION['pending_biz_name']
);

$biz_name_display = htmlspecialchars($tenant['business_name'] ?? 'Your Business');
$plan_display     = htmlspecialchars($tenant['plan'] ?? $plan);
$tenant_email     = htmlspecialchars($tenant['email'] ?? '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>Payment Successful — PawnHub</title>
<script src="https://cdn.tailwindcss.com"></script>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet"/>
<style>body { font-family: 'Inter', sans-serif; }</style>
</head>
<body class="min-h-screen bg-gray-950 flex items-center justify-center text-white p-6">

<div class="bg-gray-900 border border-gray-800 rounded-3xl p-10 max-w-lg w-full text-center shadow-2xl">

  <!-- Icon -->
  <div class="w-20 h-20 <?= $auto_processed ? 'bg-green-500/15' : ($permit_ai_status === 'ai_rejected' ? 'bg-red-500/15' : 'bg-yellow-500/15') ?> rounded-full flex items-center justify-center mx-auto mb-6">
    <?php if ($auto_processed): ?>
    <svg class="w-10 h-10 text-green-400" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
      <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
    </svg>
    <?php elseif ($permit_ai_status === 'ai_rejected'): ?>
    <svg class="w-10 h-10 text-red-400" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
      <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v4m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/>
    </svg>
    <?php else: ?>
    <svg class="w-10 h-10 text-yellow-400" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
      <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
    </svg>
    <?php endif; ?>
  </div>

  <h1 class="text-2xl font-extrabold mb-2">
    <?php if ($auto_processed): ?>
      Payment Received &amp; Account Activated! 🎉
    <?php elseif ($permit_ai_status === 'ai_rejected'): ?>
      Payment Received — Permit Issue ⚠️
    <?php else: ?>
      Payment Received! 🎉
    <?php endif; ?>
  </h1>

  <p class="text-gray-400 text-sm leading-relaxed mb-6">
    Thank you, <strong class="text-white"><?= $biz_name_display ?></strong>!<br>
    <?php if ($auto_processed): ?>
      Your <strong class="text-green-400"><?= $plan_display ?> Plan</strong> is now active. Check your email to set up your account!
    <?php elseif ($permit_ai_status === 'ai_rejected'): ?>
      Your payment was received, but there was an issue with your business permit. Please see details below.
    <?php else: ?>
      Your <strong class="text-blue-400"><?= $plan_display ?> Plan</strong> payment has been recorded. Your permit is under review.
    <?php endif; ?>
  </p>

  <!-- Steps -->
  <div class="bg-gray-800 rounded-2xl p-6 mb-6 text-left">
    <p class="text-xs font-bold uppercase tracking-widest text-gray-500 mb-4">What happens next</p>

    <!-- Step 1: Payment -->
    <div class="flex items-start gap-3 mb-4">
      <div class="w-7 h-7 rounded-full bg-green-500 flex items-center justify-center flex-shrink-0 mt-0.5">
        <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
        </svg>
      </div>
      <div>
        <p class="text-sm font-semibold text-white">Payment confirmed ✅</p>
        <p class="text-xs text-gray-400 mt-0.5">Your payment has been received and recorded.</p>
      </div>
    </div>

    <!-- Step 2: Permit Verification -->
    <div class="flex items-start gap-3 mb-4">
      <?php if ($auto_processed): ?>
      <div class="w-7 h-7 rounded-full bg-green-500 flex items-center justify-center flex-shrink-0 mt-0.5">
        <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
        </svg>
      </div>
      <div>
        <p class="text-sm font-semibold text-white">Business permit verified ✅</p>
        <p class="text-xs text-gray-400 mt-0.5">Your permit was automatically verified by our AI system.</p>
      </div>
      <?php elseif ($permit_ai_status === 'ai_rejected'): ?>
      <div class="w-7 h-7 rounded-full bg-red-500 flex items-center justify-center flex-shrink-0 mt-0.5">
        <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
        </svg>
      </div>
      <div>
        <p class="text-sm font-semibold text-red-300">Permit issue detected ❌</p>
        <p class="text-xs text-gray-400 mt-0.5">
          <?= htmlspecialchars($permit_result['reason'] ?? 'There was an issue with your business permit.') ?>
        </p>
      </div>
      <?php else: ?>
      <div class="w-7 h-7 rounded-full bg-yellow-500/20 border-2 border-yellow-500/50 flex items-center justify-center flex-shrink-0 mt-0.5">
        <div class="w-2 h-2 rounded-full bg-yellow-400 animate-pulse"></div>
      </div>
      <div>
        <p class="text-sm font-semibold text-yellow-300">Permit under review 🔍</p>
        <p class="text-xs text-gray-400 mt-0.5">Our admin will manually verify your business permit — usually within 24 hours.</p>
      </div>
      <?php endif; ?>
    </div>

    <!-- Step 3: Account Activation -->
    <div class="flex items-start gap-3">
      <?php if ($auto_processed): ?>
      <div class="w-7 h-7 rounded-full bg-blue-500/20 border-2 border-blue-500/50 flex items-center justify-center flex-shrink-0 mt-0.5">
        <div class="w-2 h-2 rounded-full bg-blue-400 animate-pulse"></div>
      </div>
      <div>
        <p class="text-sm font-semibold text-blue-300">Check your email &amp; set up your account</p>
        <p class="text-xs text-gray-400 mt-0.5">Open the setup link in your email to create your login credentials.</p>
      </div>
      <?php elseif ($permit_ai_status === 'ai_rejected'): ?>
      <div class="w-7 h-7 rounded-full bg-gray-700 flex items-center justify-center flex-shrink-0 mt-0.5">
        <span class="text-gray-400 text-xs font-bold">3</span>
      </div>
      <div>
        <p class="text-sm font-semibold text-gray-300">Contact support</p>
        <p class="text-xs text-gray-500 mt-0.5">Please contact PawnHub support to resolve the permit issue and activate your account.</p>
      </div>
      <?php else: ?>
      <div class="w-7 h-7 rounded-full bg-gray-700 flex items-center justify-center flex-shrink-0 mt-0.5">
        <span class="text-gray-400 text-xs font-bold">3</span>
      </div>
      <div>
        <p class="text-sm font-semibold text-gray-300">Account activated</p>
        <p class="text-xs text-gray-500 mt-0.5">Once permit is verified and approved, you'll receive your login link by email.</p>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Info box -->
  <?php if ($auto_processed): ?>
  <div class="bg-green-500/10 border border-green-500/20 rounded-xl p-4 text-sm text-green-300 mb-6 text-left">
    <div class="flex items-start gap-2">
      <span class="text-base mt-0.5">📧</span>
      <span>Can't find the email? Check your <strong>spam or junk folder</strong>. The setup link expires in <strong>24 hours</strong>.</span>
    </div>
  </div>
  <?php elseif ($permit_ai_status === 'ai_rejected'): ?>
  <div class="bg-red-500/10 border border-red-500/20 rounded-xl p-4 text-sm text-red-300 mb-6 text-left">
    <div class="flex items-start gap-2">
      <span class="text-base mt-0.5">⚠️</span>
      <span>Your payment is <strong>safe and recorded</strong>. Please contact our support team with a valid, current business permit to resolve this issue. Your account will be activated once the permit is verified.</span>
    </div>
  </div>
  <?php else: ?>
  <div class="bg-blue-500/10 border border-blue-500/20 rounded-xl p-4 text-sm text-blue-300 mb-6 text-left">
    <div class="flex items-start gap-2">
      <span class="text-base mt-0.5">📧</span>
      <span>You'll receive a confirmation email once your account is approved — usually <strong>within 24 hours</strong>. Check your spam folder if you don't see it.</span>
    </div>
  </div>
  <?php endif; ?>

  <a href="login.php" class="inline-block bg-gray-700 hover:bg-gray-600 text-white font-semibold py-3 px-8 rounded-xl transition-colors text-sm">
    ← Back to Login
  </a>

</div>
</body>
</html>