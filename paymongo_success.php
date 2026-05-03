<?php
/**
 * paymongo_success.php
 * ─────────────────────────────────────────────────────────────
 * User lands here after successful PayMongo payment (signup).
 *
 * Flow:
 *   • Webhook (paymongo_webhook.php) already auto-activated the
 *     tenant and sent the login email before this page loads.
 *   • This page is UI confirmation only — just show success.
 *   • If webhook hasn't fired yet (race condition), we do a
 *     lightweight fallback: mark paid + activate directly.
 *
 *   NOTE: Permit is still reviewed by SA after activation.
 *         If fake/expired → SA deactivates manually.
 *         No refund per Terms & Conditions.
 * ─────────────────────────────────────────────────────────────
 */

session_start();
require 'db.php';
require 'paymongo_config.php';

$tenant_id = intval($_GET['tenant'] ?? 0);
$user_id   = intval($_GET['user']   ?? 0);

// ── Fetch tenant ──────────────────────────────────────────────
$tenant = null;
if ($tenant_id) {
    $stmt = $pdo->prepare("SELECT * FROM tenants WHERE id = ?");
    $stmt->execute([$tenant_id]);
    $tenant = $stmt->fetch();
}

$plan           = $tenant['plan']                ?? '';
$payment_status = $tenant['payment_status']      ?? 'pending';
$session_id     = $tenant['paymongo_session_id'] ?? '';

// ── WEBHOOK FALLBACK ──────────────────────────────────────────
// Runs if webhook hasn't fired yet OR if it partially failed
// (paid in DB but tenant not yet active / email not sent).
$needs_activation = (
    $tenant_id && $session_id &&
    ($payment_status !== 'paid' || $tenant['status'] !== 'active')
);
if ($needs_activation) {

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

        $payments       = $cs['data']['attributes']['payments'] ?? [];
        $payment_method = '';
        if (!empty($payments[0])) {
            $pm             = $payments[0]['attributes']['payment_method_used'] ?? '';
            $payment_method = strtoupper($pm);
        }

        try {
            // Mark paid
            $pdo->prepare("
                UPDATE tenants
                SET payment_status   = 'paid',
                    paymongo_paid_at = NOW()
                WHERE id = ?
            ")->execute([$tenant_id]);

            // Deduplicate check
            $dup = $pdo->prepare("SELECT id FROM subscription_renewals WHERE tenant_id = ? AND payment_reference = ? LIMIT 1");
            $dup->execute([$tenant_id, $session_id]);

            if (!$dup->fetch()) {

                // Generate slug if missing
                $slug = $tenant['slug'] ?? '';
                if (empty($slug)) {
                    $base_slug = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $tenant['business_name']));
                    $slug = $base_slug; $ctr = 1;
                    while (true) {
                        $chk = $pdo->prepare("SELECT id FROM tenants WHERE slug = ? AND id != ?");
                        $chk->execute([$slug, $tenant_id]);
                        if (!$chk->fetch()) break;
                        $slug = $base_slug . $ctr++;
                    }
                    $pdo->prepare("UPDATE tenants SET slug = ? WHERE id = ?")->execute([$slug, $tenant_id]);
                }

                // Auto-activate tenant
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
                if ($user_id) {
                    $pdo->prepare("UPDATE users SET status = 'approved', approved_at = NOW() WHERE id = ?")->execute([$user_id]);
                }

                // Record payment in subscription_renewals
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

                error_log("[SuccessPage] Fallback auto-activate: tenant_id={$tenant_id}, plan={$plan}");

            } else {
                // Already have a record — just make sure slug is set for email below
                $slug = $tenant['slug'] ?? '';
            }

            // ── Always send login email if tenant has email + slug ──
            // (regardless of dedup — email may have failed on first attempt)
            $slug = $slug ?? $tenant['slug'] ?? '';
            if (!empty($tenant['email']) && !empty($slug)) {
                try {
                    require_once __DIR__ . '/mailer.php';
                    sendTenantApproved($tenant['email'], $tenant['owner_name'], $tenant['business_name'], $slug);
                } catch (Throwable $e) {
                    error_log("[SuccessPage] Email error: " . $e->getMessage());
                }
            }
        } catch (Throwable $e) {
            error_log("[SuccessPage] Fallback error: " . $e->getMessage());
        }
    }
}

// Re-fetch for display
if ($tenant_id) {
    $stmt = $pdo->prepare("SELECT business_name, plan FROM tenants WHERE id = ?");
    $stmt->execute([$tenant_id]);
    $tenant = $stmt->fetch();
}

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

  <h1 class="text-2xl font-extrabold mb-2">Payment Received &amp; Account Activated! 🎉</h1>
  <p class="text-gray-400 text-sm leading-relaxed mb-6">
    Thank you, <strong class="text-white"><?= $biz_name_display ?></strong>!<br>
    Your <strong class="text-green-400"><?= $plan_display ?> Plan</strong> is now active.
    Check your email to log in to your account.
  </p>

  <!-- Steps -->
  <div class="bg-gray-800 rounded-2xl p-6 mb-6 text-left">
    <p class="text-xs font-bold uppercase tracking-widest text-gray-500 mb-4">What just happened</p>

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

    <!-- Step 2: Auto-activated -->
    <div class="flex items-start gap-3 mb-4">
      <div class="w-7 h-7 rounded-full bg-green-500 flex items-center justify-center flex-shrink-0 mt-0.5">
        <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
        </svg>
      </div>
      <div>
        <p class="text-sm font-semibold text-white">Account automatically activated ✅</p>
        <p class="text-xs text-gray-400 mt-0.5">Your <strong class="text-green-400"><?= $plan_display ?> Plan</strong> is live and ready to use.</p>
      </div>
    </div>

    <!-- Step 3: Check email -->
    <div class="flex items-start gap-3">
      <div class="w-7 h-7 rounded-full bg-blue-500/20 border-2 border-blue-500/50 flex items-center justify-center flex-shrink-0 mt-0.5">
        <div class="w-2 h-2 rounded-full bg-blue-400 animate-pulse"></div>
      </div>
      <div>
        <p class="text-sm font-semibold text-blue-300">Check your email &amp; log in</p>
        <p class="text-xs text-gray-400 mt-0.5">A login link has been sent to your registered email address.</p>
      </div>
    </div>
  </div>

  <!-- Permit review notice -->
  <div class="bg-yellow-500/10 border border-yellow-500/20 rounded-xl p-4 text-sm text-yellow-300 mb-4 text-left">
    <div class="flex items-start gap-2">
      <span class="text-base mt-0.5">⚠️</span>
      <span>
        Your <strong>Business Permit</strong> will still be reviewed by our Super Admin.
        If it is found to be <strong>fake or expired</strong>, your account will be
        deactivated <strong>without refund</strong> as stated in our Terms &amp; Conditions.
      </span>
    </div>
  </div>

  <!-- Email tip -->
  <div class="bg-green-500/10 border border-green-500/20 rounded-xl p-4 text-sm text-green-300 mb-6 text-left">
    <div class="flex items-start gap-2">
      <span class="text-base mt-0.5">📧</span>
      <div>
        <span>Your login link has been sent to your registered email address.<br>
        Can't find it? Check your <strong>spam or junk folder</strong>.</span>
      </div>
    </div>
  </div>

  <p class="text-xs text-gray-500">You may now close this tab and check your email to log in.</p>

</div>
</body>
</html>