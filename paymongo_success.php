<?php
/**
 * paymongo_success.php
 * ─────────────────────────────────────────────────────────────
 * User lands here after successful PayMongo payment.
 *
 * Acts as a WEBHOOK FALLBACK for test/local environments where
 * PayMongo cannot reach your server via webhook.
 *
 * Logic:
 *  1. Verify payment with PayMongo API (fetch checkout session)
 *  2. If paid and not yet processed → auto-approve tenant (signup)
 *  3. Show confirmation UI
 * ─────────────────────────────────────────────────────────────
 */

session_start();
require 'db.php';
require 'paymongo_config.php';

$tenant_id = intval($_GET['tenant'] ?? 0);
$user_id   = intval($_GET['user']   ?? 0);

// ── Fetch tenant from DB ──────────────────────────────────────
$tenant = null;
if ($tenant_id) {
    $stmt = $pdo->prepare("SELECT * FROM tenants WHERE id = ?");
    $stmt->execute([$tenant_id]);
    $tenant = $stmt->fetch();
}

$biz_name      = $tenant['business_name'] ?? 'Your Business';
$plan          = $tenant['plan']          ?? '';
$payment_status = $tenant['payment_status'] ?? 'unpaid';
$session_id    = $tenant['paymongo_session_id'] ?? '';

// ── WEBHOOK FALLBACK: only run if not yet paid ────────────────
$auto_processed = false;
$process_error  = '';

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

    $cs = json_decode($raw, true);
    $payment_status_pm = $cs['data']['attributes']['payment_status'] ?? '';

    if ($httpCode === 200 && $payment_status_pm === 'paid') {
        // Payment confirmed by PayMongo — process it now (webhook fallback)

        // Resolve payment method
        $payments = $cs['data']['attributes']['payments'] ?? [];
        $payment_method = '';
        if (!empty($payments[0])) {
            $pm = $payments[0]['attributes']['payment_method_used'] ?? '';
            $payment_method = strtoupper($pm);
        }

        try {
            // Mark payment_status = paid
            $pdo->prepare("
                UPDATE tenants
                SET payment_status = 'paid',
                    paymongo_paid_at = NOW()
                WHERE id = ?
            ")->execute([$tenant_id]);

            // Check for duplicate
            $dup = $pdo->prepare("
                SELECT id FROM subscription_renewals
                WHERE tenant_id = ? AND payment_reference = ?
                LIMIT 1
            ");
            $dup->execute([$tenant_id, $session_id]);

            if (!$dup->fetch()) {
                // Fetch user row
                $u_row = $pdo->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
                $u_row->execute([$user_id]);
                $u_row = $u_row->fetch();

                if ($tenant && $u_row) {
                    // Generate slug if missing
                    $slug = $tenant['slug'] ?? '';
                    if (empty($slug)) {
                        $base_slug = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $tenant['business_name']));
                        $slug = $base_slug;
                        $ctr  = 1;
                        while (true) {
                            $chk = $pdo->prepare("SELECT id FROM tenants WHERE slug = ? AND id != ?");
                            $chk->execute([$slug, $tenant_id]);
                            if (!$chk->fetch()) break;
                            $slug = $base_slug . $ctr++;
                        }
                    }

                    // Activate tenant
                    $pdo->prepare("
                        UPDATE tenants SET
                            status              = 'active',
                            slug                = ?,
                            subscription_start  = CURDATE(),
                            subscription_end    = DATE_ADD(CURDATE(), INTERVAL 1 MONTH),
                            subscription_status = 'active',
                            renewal_reminded_7d = 0,
                            renewal_reminded_3d = 0,
                            renewal_reminded_1d = 0
                        WHERE id = ?
                    ")->execute([$slug, $tenant_id]);

                    // Activate user
                    $pdo->prepare("
                        UPDATE users SET status = 'approved', approved_at = NOW() WHERE id = ?
                    ")->execute([$user_id]);

                    // Record subscription payment
                    $plan_amounts = ['Starter' => 0, 'Pro' => 999, 'Enterprise' => 2499];
                    $sub_amount   = $plan_amounts[$plan] ?? 0;
                    if ($sub_amount > 0) {
                        try {
                            $pdo->prepare("
                                INSERT INTO subscription_renewals
                                    (tenant_id, plan, billing_cycle, payment_method, payment_reference, amount, status, requested_at, reviewed_at, new_subscription_end)
                                VALUES (?, ?, 'monthly', ?, ?, ?, 'approved', NOW(), NOW(), DATE_ADD(CURDATE(), INTERVAL 1 MONTH))
                            ")->execute([$tenant_id, $plan, 'PayMongo — ' . $payment_method, $session_id, $sub_amount]);
                        } catch (PDOException $e) {}
                    }

                    // Send activation email
                    try {
                        require_once __DIR__ . '/mailer.php';
                        $inv = $pdo->prepare("SELECT token FROM tenant_invitations WHERE tenant_id = ? AND status = 'pending' ORDER BY created_at DESC LIMIT 1");
                        $inv->execute([$tenant_id]);
                        $inv_row = $inv->fetch();
                        if ($inv_row) {
                            sendTenantInvitation($tenant['email'], $tenant['owner_name'], $tenant['business_name'], $inv_row['token'], $slug);
                        } else {
                            sendTenantApproved($tenant['email'], $tenant['owner_name'], $tenant['business_name'], $slug);
                        }
                    } catch (Throwable $mail_err) {
                        error_log("[SuccessFallback] Email error: " . $mail_err->getMessage());
                    }

                    $auto_processed = true;

                    // Re-fetch tenant for display
                    $stmt = $pdo->prepare("SELECT * FROM tenants WHERE id = ?");
                    $stmt->execute([$tenant_id]);
                    $tenant = $stmt->fetch();
                    $plan   = $tenant['plan'] ?? $plan;
                }
            } else {
                // Already processed (maybe webhook did fire)
                $auto_processed = true;
            }

        } catch (Throwable $e) {
            $process_error = $e->getMessage();
            error_log("[SuccessFallback] Error: " . $e->getMessage());
        }
    }
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

  <!-- Success icon -->
  <div class="w-20 h-20 bg-green-500/15 rounded-full flex items-center justify-center mx-auto mb-6">
    <svg class="w-10 h-10 text-green-400" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
      <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
    </svg>
  </div>

  <h1 class="text-2xl font-extrabold mb-2">Payment Received! 🎉</h1>
  <p class="text-gray-400 text-sm leading-relaxed mb-6">
    Thank you, <strong class="text-white"><?= $biz_name_display ?></strong>!<br>
    Your <strong class="text-blue-400"><?= $plan_display ?> Plan</strong>
    <?php if ($auto_processed): ?>
      is now active. Check your email to set up your account!
    <?php else: ?>
      payment has been recorded.
    <?php endif; ?>
  </p>

  <!-- Step indicator -->
  <div class="bg-gray-800 rounded-2xl p-6 mb-6 text-left">
    <p class="text-xs font-bold uppercase tracking-widest text-gray-500 mb-4">What happens next</p>

    <!-- Step 1: Done -->
    <div class="flex items-start gap-3 mb-4">
      <div class="w-7 h-7 rounded-full bg-green-500 flex items-center justify-center flex-shrink-0 mt-0.5">
        <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
        </svg>
      </div>
      <div>
        <p class="text-sm font-semibold text-white">Payment confirmed</p>
        <p class="text-xs text-gray-400 mt-0.5">Your payment has been received and recorded.</p>
      </div>
    </div>

    <?php if ($auto_processed): ?>
    <!-- Step 2: Done (auto-approved) -->
    <div class="flex items-start gap-3 mb-4">
      <div class="w-7 h-7 rounded-full bg-green-500 flex items-center justify-center flex-shrink-0 mt-0.5">
        <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
        </svg>
      </div>
      <div>
        <p class="text-sm font-semibold text-white">Account setup email sent</p>
        <p class="text-xs text-gray-400 mt-0.5">A setup link was sent to <strong class="text-white"><?= htmlspecialchars($tenant['email'] ?? '') ?></strong> — click it to set your username &amp; password.</p>
      </div>
    </div>

    <!-- Step 3: Active -->
    <div class="flex items-start gap-3">
      <div class="w-7 h-7 rounded-full bg-blue-500/20 border-2 border-blue-500/50 flex items-center justify-center flex-shrink-0 mt-0.5">
        <div class="w-2 h-2 rounded-full bg-blue-400 animate-pulse"></div>
      </div>
      <div>
        <p class="text-sm font-semibold text-blue-300">Check your email &amp; set up your account</p>
        <p class="text-xs text-gray-400 mt-0.5">Open the setup link in your email to create your login credentials and access your PawnHub dashboard.</p>
      </div>
    </div>

    <?php else: ?>
    <!-- Step 2: Pending SA review -->
    <div class="flex items-start gap-3 mb-4">
      <div class="w-7 h-7 rounded-full bg-yellow-500/20 border-2 border-yellow-500/50 flex items-center justify-center flex-shrink-0 mt-0.5">
        <div class="w-2 h-2 rounded-full bg-yellow-400 animate-pulse"></div>
      </div>
      <div>
        <p class="text-sm font-semibold text-yellow-300">Super Admin review</p>
        <p class="text-xs text-gray-400 mt-0.5">Our admin will verify your Business Permit and payment details.</p>
      </div>
    </div>

    <!-- Step 3: Upcoming -->
    <div class="flex items-start gap-3">
      <div class="w-7 h-7 rounded-full bg-gray-700 flex items-center justify-center flex-shrink-0 mt-0.5">
        <span class="text-gray-400 text-xs font-bold">3</span>
      </div>
      <div>
        <p class="text-sm font-semibold text-gray-300">Account activated</p>
        <p class="text-xs text-gray-500 mt-0.5">Once approved, you'll receive your login link by email.</p>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <!-- Info box -->
  <div class="bg-blue-500/10 border border-blue-500/20 rounded-xl p-4 text-sm text-blue-300 mb-6 text-left">
    <div class="flex items-start gap-2">
      <span class="text-base mt-0.5">📧</span>
      <span>
        <?php if ($auto_processed): ?>
          Can't find the email? Check your <strong>spam or junk folder</strong>. The setup link expires in <strong>24 hours</strong>.
        <?php else: ?>
          You'll receive a confirmation email once your account is approved — usually <strong>within 24 hours</strong>. Check your spam folder if you don't see it.
        <?php endif; ?>
      </span>
    </div>
  </div>

  <a href="login.php"
     class="inline-block bg-gray-700 hover:bg-gray-600 text-white font-semibold py-3 px-8 rounded-xl transition-colors text-sm">
    ← Back to Login
  </a>

</div>
</body>
</html>