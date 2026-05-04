<?php
/**
 * paymongo_renewal_success.php
 * ─────────────────────────────────────────────────────────────
 * Tenant lands here after successful PayMongo renewal payment.
 *
 * This is a UI confirmation page ONLY.
 * The actual subscription_renewals record is created by:
 *     paymongo_webhook.php  ← metadata.type = 'renewal'
 *
 * The Super Admin must still click "Approve" in the Subscriptions
 * page. That is what extends subscription_end and sends the email.
 * ─────────────────────────────────────────────────────────────
 */

require_once __DIR__ . '/session_helper.php';
pawnhub_session_start('admin');
require_once __DIR__ . '/db.php';

$tenant_id     = intval($_GET['tenant'] ?? 0);
$billing_cycle = $_GET['cycle'] ?? 'monthly';
$payment_type  = $_GET['type']  ?? 'renewal';
$is_reactivation = ($payment_type === 'reactivation');

// Fetch tenant for display
$tenant = null;
if ($tenant_id) {
    $stmt = $pdo->prepare("SELECT business_name, plan, subscription_end, slug FROM tenants WHERE id = ?");
    $stmt->execute([$tenant_id]);
    $tenant = $stmt->fetch();
}

$biz_name  = htmlspecialchars($tenant['business_name'] ?? 'Your Business');
$plan      = htmlspecialchars($tenant['plan'] ?? '');
$slug      = $tenant['slug'] ?? '';
$login_url = $slug ? '/' . rawurlencode($slug) . '?login=1' : 'login.php';

// Billing label
$billing_labels = [
    'monthly'   => 'Monthly',
    'quarterly' => 'Quarterly (3 months)',
    'annually'  => 'Annual (12 months)',
];
$cycle_label = $billing_labels[$billing_cycle] ?? 'Monthly';

// Clear renewal session vars
unset(
    $_SESSION['renewal_paymongo_session'],
    $_SESSION['renewal_billing_cycle'],
    $_SESSION['renewal_amount']
);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title><?= $is_reactivation ? 'Account Reactivated' : 'Renewal Payment Received' ?> — PawnHub</title>
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

  <h1 class="text-2xl font-extrabold mb-2"><?= $is_reactivation ? 'Account Reactivated! 🎉' : 'Renewal Payment Received! 🎉' ?></h1>
  <p class="text-gray-400 text-sm leading-relaxed mb-6">
    Thank you, <strong class="text-white"><?= $biz_name ?></strong>!<br>
    <?php if ($is_reactivation): ?>
      Your <strong class="text-green-400"><?= $plan ?> Plan</strong> has been reactivated. Your account and all staff access have been restored!
    <?php else: ?>
      Your <strong class="text-blue-400"><?= $plan ?> Plan</strong> — <strong class="text-blue-300"><?= htmlspecialchars($cycle_label) ?></strong> payment has been confirmed and your subscription is now updated!
    <?php endif; ?>
  </p>

  <!-- Steps -->
  <div class="bg-gray-800 rounded-2xl p-6 mb-6 text-left">
    <p class="text-xs font-bold uppercase tracking-widest text-gray-500 mb-4">
      <?= $is_reactivation ? 'What happened' : 'What happens next' ?>
    </p>

    <!-- Step 1 -->
    <div class="flex items-start gap-3 mb-4">
      <div class="w-7 h-7 rounded-full bg-green-500 flex items-center justify-center flex-shrink-0 mt-0.5">
        <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
        </svg>
      </div>
      <div>
        <p class="text-sm font-semibold text-white">Payment confirmed</p>
        <p class="text-xs text-gray-400 mt-0.5">Your payment has been received and logged in our system.</p>
      </div>
    </div>

    <!-- Step 2 -->
    <div class="flex items-start gap-3 mb-4">
      <div class="w-7 h-7 rounded-full bg-green-500 flex items-center justify-center flex-shrink-0 mt-0.5">
        <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
        </svg>
      </div>
      <div>
        <?php if ($is_reactivation): ?>
          <p class="text-sm font-semibold text-white">Account automatically reactivated ✅</p>
          <p class="text-xs text-gray-400 mt-0.5">Your tenant account and all staff accounts have been unsuspended immediately.</p>
        <?php else: ?>
          <p class="text-sm font-semibold text-white">Subscription automatically updated ✅</p>
          <p class="text-xs text-gray-400 mt-0.5">Your subscription has been extended/updated immediately. No manual approval needed.</p>
        <?php endif; ?>
      </div>
    </div>

    <!-- Step 3 -->
    <div class="flex items-start gap-3">
      <?php if ($is_reactivation): ?>
      <div class="w-7 h-7 rounded-full bg-blue-500/20 border-2 border-blue-500/50 flex items-center justify-center flex-shrink-0 mt-0.5">
        <div class="w-2 h-2 rounded-full bg-blue-400 animate-pulse"></div>
      </div>
      <div>
        <p class="text-sm font-semibold text-blue-300">Go to your dashboard</p>
        <p class="text-xs text-gray-400 mt-0.5">You can now log in and resume normal operations. A confirmation email has been sent.</p>
      </div>
      <?php else: ?>
      <div class="w-7 h-7 rounded-full bg-blue-500/20 border-2 border-blue-500/50 flex items-center justify-center flex-shrink-0 mt-0.5">
        <div class="w-2 h-2 rounded-full bg-blue-400 animate-pulse"></div>
      </div>
      <div>
        <p class="text-sm font-semibold text-blue-300">Go to your dashboard</p>
        <p class="text-xs text-gray-400 mt-0.5">Your subscription is active. Log in and continue using PawnHub. A confirmation email has been sent.</p>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="<?= $is_reactivation ? 'bg-green-500/10 border-green-500/20 text-green-300' : 'bg-green-500/10 border-green-500/20 text-green-300' ?> border rounded-xl p-4 text-sm mb-6 text-left">
    <div class="flex items-start gap-2">
      <span class="text-base mt-0.5">✅</span>
      <span>
        <?php if ($is_reactivation): ?>
          Your account is now <strong>fully active</strong>. You'll also receive a confirmation email shortly.
        <?php else: ?>
          Your subscription is now <strong>active and updated</strong>. A confirmation email has been sent to your registered address.
        <?php endif; ?>
      </span>
    </div>
  </div>

  <a href="<?= htmlspecialchars($login_url) ?>"
     class="inline-block bg-blue-600 hover:bg-blue-500 text-white font-semibold py-3 px-8 rounded-xl transition-colors text-sm">
    → Go to Tenant Login
  </a>
</div>
</body>
</html><?php
/**
 * paymongo_renewal_success.php
 * ─────────────────────────────────────────────────────────────
 * Tenant lands here after successful PayMongo renewal payment.
 *
 * This is a UI confirmation page ONLY.
 * The actual subscription_renewals record is created by:
 *     paymongo_webhook.php  ← metadata.type = 'renewal'
 *
 * The Super Admin must still click "Approve" in the Subscriptions
 * page. That is what extends subscription_end and sends the email.
 * ─────────────────────────────────────────────────────────────
 */

require_once __DIR__ . '/session_helper.php';
pawnhub_session_start('admin');
require_once __DIR__ . '/db.php';

$tenant_id     = intval($_GET['tenant'] ?? 0);
$billing_cycle = $_GET['cycle'] ?? 'monthly';
$payment_type  = $_GET['type']  ?? 'renewal';
$is_reactivation = ($payment_type === 'reactivation');

// Fetch tenant for display
$tenant = null;
if ($tenant_id) {
    $stmt = $pdo->prepare("SELECT business_name, plan, subscription_end, slug FROM tenants WHERE id = ?");
    $stmt->execute([$tenant_id]);
    $tenant = $stmt->fetch();
}

$biz_name  = htmlspecialchars($tenant['business_name'] ?? 'Your Business');
$plan      = htmlspecialchars($tenant['plan'] ?? '');
$slug      = $tenant['slug'] ?? '';
$login_url = $slug ? '/' . rawurlencode($slug) . '?login=1' : 'login.php';

// Billing label
$billing_labels = [
    'monthly'   => 'Monthly',
    'quarterly' => 'Quarterly (3 months)',
    'annually'  => 'Annual (12 months)',
];
$cycle_label = $billing_labels[$billing_cycle] ?? 'Monthly';

// Clear renewal session vars
unset(
    $_SESSION['renewal_paymongo_session'],
    $_SESSION['renewal_billing_cycle'],
    $_SESSION['renewal_amount']
);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title><?= $is_reactivation ? 'Account Reactivated' : 'Renewal Payment Received' ?> — PawnHub</title>
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

  <h1 class="text-2xl font-extrabold mb-2"><?= $is_reactivation ? 'Account Reactivated! 🎉' : 'Renewal Payment Received! 🎉' ?></h1>
  <p class="text-gray-400 text-sm leading-relaxed mb-6">
    Thank you, <strong class="text-white"><?= $biz_name ?></strong>!<br>
    <?php if ($is_reactivation): ?>
      Your <strong class="text-green-400"><?= $plan ?> Plan</strong> has been reactivated. Your account and all staff access have been restored!
    <?php else: ?>
      Your <strong class="text-blue-400"><?= $plan ?> Plan</strong> — <strong class="text-blue-300"><?= htmlspecialchars($cycle_label) ?></strong> payment has been confirmed and your subscription is now updated!
    <?php endif; ?>
  </p>

  <!-- Steps -->
  <div class="bg-gray-800 rounded-2xl p-6 mb-6 text-left">
    <p class="text-xs font-bold uppercase tracking-widest text-gray-500 mb-4">
      <?= $is_reactivation ? 'What happened' : 'What happens next' ?>
    </p>

    <!-- Step 1 -->
    <div class="flex items-start gap-3 mb-4">
      <div class="w-7 h-7 rounded-full bg-green-500 flex items-center justify-center flex-shrink-0 mt-0.5">
        <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
        </svg>
      </div>
      <div>
        <p class="text-sm font-semibold text-white">Payment confirmed</p>
        <p class="text-xs text-gray-400 mt-0.5">Your payment has been received and logged in our system.</p>
      </div>
    </div>

    <!-- Step 2 -->
    <div class="flex items-start gap-3 mb-4">
      <div class="w-7 h-7 rounded-full bg-green-500 flex items-center justify-center flex-shrink-0 mt-0.5">
        <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
        </svg>
      </div>
      <div>
        <?php if ($is_reactivation): ?>
          <p class="text-sm font-semibold text-white">Account automatically reactivated ✅</p>
          <p class="text-xs text-gray-400 mt-0.5">Your tenant account and all staff accounts have been unsuspended immediately.</p>
        <?php else: ?>
          <p class="text-sm font-semibold text-white">Subscription automatically updated ✅</p>
          <p class="text-xs text-gray-400 mt-0.5">Your subscription has been extended/updated immediately. No manual approval needed.</p>
        <?php endif; ?>
      </div>
    </div>

    <!-- Step 3 -->
    <div class="flex items-start gap-3">
      <?php if ($is_reactivation): ?>
      <div class="w-7 h-7 rounded-full bg-blue-500/20 border-2 border-blue-500/50 flex items-center justify-center flex-shrink-0 mt-0.5">
        <div class="w-2 h-2 rounded-full bg-blue-400 animate-pulse"></div>
      </div>
      <div>
        <p class="text-sm font-semibold text-blue-300">Go to your dashboard</p>
        <p class="text-xs text-gray-400 mt-0.5">You can now log in and resume normal operations. A confirmation email has been sent.</p>
      </div>
      <?php else: ?>
      <div class="w-7 h-7 rounded-full bg-blue-500/20 border-2 border-blue-500/50 flex items-center justify-center flex-shrink-0 mt-0.5">
        <div class="w-2 h-2 rounded-full bg-blue-400 animate-pulse"></div>
      </div>
      <div>
        <p class="text-sm font-semibold text-blue-300">Go to your dashboard</p>
        <p class="text-xs text-gray-400 mt-0.5">Your subscription is active. Log in and continue using PawnHub. A confirmation email has been sent.</p>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="<?= $is_reactivation ? 'bg-green-500/10 border-green-500/20 text-green-300' : 'bg-green-500/10 border-green-500/20 text-green-300' ?> border rounded-xl p-4 text-sm mb-6 text-left">
    <div class="flex items-start gap-2">
      <span class="text-base mt-0.5">✅</span>
      <span>
        <?php if ($is_reactivation): ?>
          Your account is now <strong>fully active</strong>. You'll also receive a confirmation email shortly.
        <?php else: ?>
          Your subscription is now <strong>active and updated</strong>. A confirmation email has been sent to your registered address.
        <?php endif; ?>
      </span>
    </div>
  </div>

  <a href="<?= htmlspecialchars($login_url) ?>"
     class="inline-block bg-blue-600 hover:bg-blue-500 text-white font-semibold py-3 px-8 rounded-xl transition-colors text-sm">
    → Go to Tenant Login
  </a>
</div>
</body>
</html>