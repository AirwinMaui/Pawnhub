<?php
/**
 * paymongo_success.php
 * ─────────────────────────────────────────────────────────────
 * User lands here after successful PayMongo payment.
 * NOTE: This is just a UI confirmation. The REAL activation
 *       happens in paymongo_webhook.php (server-to-server).
 * ─────────────────────────────────────────────────────────────
 */

session_start();
require 'db.php';

$tenant_id = intval($_GET['tenant'] ?? 0);
$user_id   = intval($_GET['user']   ?? 0);

// Clear pending session data
unset($_SESSION['pending_tenant_id'], $_SESSION['pending_user_id'],
      $_SESSION['pending_plan'], $_SESSION['pending_email'], $_SESSION['pending_biz_name']);

// Fetch tenant info for display
$tenant = null;
if ($tenant_id) {
    $stmt = $pdo->prepare("SELECT business_name, plan, status FROM tenants WHERE id = ?");
    $stmt->execute([$tenant_id]);
    $tenant = $stmt->fetch();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>Payment Successful — PawnHub</title>
<script src="https://cdn.tailwindcss.com"></script>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet"/>
<style>body{font-family:'Inter',sans-serif;}</style>
</head>
<body class="min-h-screen bg-gray-950 flex items-center justify-center text-white p-6">
<div class="bg-gray-900 border border-gray-800 rounded-3xl p-10 max-w-md w-full text-center shadow-2xl">

  <div class="w-20 h-20 bg-green-500/15 rounded-full flex items-center justify-center mx-auto mb-6">
    <svg class="w-10 h-10 text-green-400" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
      <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
    </svg>
  </div>

  <h1 class="text-2xl font-extrabold mb-2">Payment Received! 🎉</h1>
  <p class="text-gray-400 text-sm leading-relaxed mb-6">
    Thank you<?= $tenant ? ', <strong class="text-white">' . htmlspecialchars($tenant['business_name']) . '</strong>' : '' ?>!<br>
    Your <strong class="text-blue-400"><?= htmlspecialchars($tenant['plan'] ?? '') ?></strong> plan payment was processed.<br><br>
    Our Super Admin will verify your Business Permit and activate your account within 24 hours.
  </p>

  <div class="bg-green-500/10 border border-green-500/20 rounded-xl p-4 text-sm text-green-300 mb-6">
    📧 You'll receive a confirmation email once your account is activated.
  </div>

  <a href="login.php" class="inline-block bg-blue-600 hover:bg-blue-700 text-white font-bold py-3 px-8 rounded-xl transition-colors">
    Go to Login →
  </a>
</div>
</body>
</html>