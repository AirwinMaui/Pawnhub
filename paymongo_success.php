<?php
/**
 * paymongo_success.php
 * ─────────────────────────────────────────────────────────────
 * User lands here after successful PayMongo payment.
 *
 * This is a UI confirmation page ONLY.
 * The actual payment recording happens in paymongo_webhook.php
 * (server-to-server, more reliable).
 *
 * The tenant is NOT activated here — the Super Admin must still
 * review the Business Permit + payment and click "Approve".
 * ─────────────────────────────────────────────────────────────
 */

session_start();
require 'db.php';

$tenant_id = intval($_GET['tenant'] ?? 0);
$user_id   = intval($_GET['user']   ?? 0);

// Clear pending session data
unset(
    $_SESSION['pending_tenant_id'],
    $_SESSION['pending_user_id'],
    $_SESSION['pending_plan'],
    $_SESSION['pending_email'],
    $_SESSION['pending_biz_name']
);

// Fetch tenant info for display
$tenant = null;
if ($tenant_id) {
    $stmt = $pdo->prepare("SELECT business_name, plan, status, payment_status FROM tenants WHERE id = ?");
    $stmt->execute([$tenant_id]);
    $tenant = $stmt->fetch();
}

$biz_name = htmlspecialchars($tenant['business_name'] ?? 'Your Business');
$plan     = htmlspecialchars($tenant['plan'] ?? '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>Payment Successful — PawnHub</title>
<script src="https://cdn.tailwindcss.com"></script>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet"/>
<style>
  body { font-family: 'Inter', sans-serif; }
</style>
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
    Thank you, <strong class="text-white"><?= $biz_name ?></strong>!<br>
    Your <strong class="text-blue-400"><?= $plan ?> Plan</strong> payment has been recorded.
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

    <!-- Step 2: Pending -->
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
  </div>

  <!-- Info box -->
  <div class="bg-blue-500/10 border border-blue-500/20 rounded-xl p-4 text-sm text-blue-300 mb-6 text-left">
    <div class="flex items-start gap-2">
      <span class="text-base mt-0.5">📧</span>
      <span>
        You'll receive a confirmation email once your account is approved — usually <strong>within 24 hours</strong>.
        Check your spam folder if you don't see it.
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