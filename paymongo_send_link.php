<?php
/**
 * paymongo_send_link.php
 * ─────────────────────────────────────────────────────────────
 * Super Admin clicks "Send Payment Link" on a pending tenant.
 *
 * Flow:
 *   1. Validates tenant is pending + has no payment yet
 *   2. Creates PayMongo Checkout Session
 *   3. Generates a QR code image (data URI) of the checkout URL
 *   4. Sends email to tenant with QR + clickable button
 *   5. Saves paymongo_session_id to tenants row
 *   6. Redirects back to SA dashboard with success/error message
 *
 * Called via POST from superadmin.php tenant list.
 * ─────────────────────────────────────────────────────────────
 */

require_once __DIR__ . '/session_helper.php';
pawnhub_session_start('super_admin');
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/paymongo_config.php';
require_once __DIR__ . '/mailer.php';

// ── Auth: Super Admin only ────────────────────────────────────
if (empty($_SESSION['user']) || $_SESSION['user']['role'] !== 'super_admin') {
    header('Location: login.php'); exit;
}

$u = $_SESSION['user'];

// ── Validate POST ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || ($_POST['action'] ?? '') !== 'send_payment_link') {
    header('Location: superadmin.php'); exit;
}

$tenant_id = intval($_POST['tenant_id'] ?? 0);
if (!$tenant_id) {
    $_SESSION['sa_error'] = 'Invalid tenant ID.';
    header('Location: superadmin.php?page=tenants'); exit;
}

// ── Fetch tenant ──────────────────────────────────────────────
$stmt = $pdo->prepare("SELECT * FROM tenants WHERE id = ? LIMIT 1");
$stmt->execute([$tenant_id]);
$tenant = $stmt->fetch();

if (!$tenant) {
    $_SESSION['sa_error'] = 'Tenant not found.';
    header('Location: superadmin.php?page=tenants'); exit;
}

// Starter plan is free — no payment needed
if ($tenant['plan'] === 'Starter') {
    $_SESSION['sa_error'] = 'Starter plan is free — no payment link needed.';
    header('Location: superadmin.php?page=tenants'); exit;
}

// Already paid
if ($tenant['payment_status'] === 'paid') {
    $_SESSION['sa_error'] = "This tenant already has a recorded payment.";
    header('Location: superadmin.php?page=tenants'); exit;
}

$email    = $tenant['email'];
$biz_name = $tenant['business_name'];
$owner    = $tenant['owner_name'];
$plan     = $tenant['plan'];

// Find the admin user for this tenant (may be 0 for SA-added tenants not yet registered)
$u_stmt = $pdo->prepare("SELECT id FROM users WHERE tenant_id = ? AND role = 'admin' LIMIT 1");
$u_stmt->execute([$tenant_id]);
$t_user  = $u_stmt->fetch();
$user_id = $t_user['id'] ?? 0;

// ── Plan pricing (centavos) ───────────────────────────────────
$prices = [
    'Pro'        => 99900,    // ₱999
    'Enterprise' => 249900,   // ₱2,499
];
$amount = $prices[$plan] ?? 99900;

// ── Create PayMongo Checkout Session ─────────────────────────
$payload = [
    'data' => [
        'attributes' => [
            'billing' => [
                'email' => $email,
                'name'  => $biz_name,
            ],
            'line_items' => [[
                'currency' => 'PHP',
                'amount'   => $amount,
                'name'     => "PawnHub {$plan} Plan — Monthly Subscription",
                'quantity' => 1,
            ]],
            'payment_method_types' => ['card', 'gcash', 'paymaya', 'dob', 'billease'],
            'success_url' => PAYMONGO_SUCCESS_URL . '?tenant=' . $tenant_id . '&user=' . $user_id,
            'cancel_url'  => PAYMONGO_CANCEL_URL,
            'metadata'    => [
                'tenant_id' => $tenant_id,
                'user_id'   => $user_id,
                'plan'      => $plan,
                'type'      => 'signup',
            ],
        ],
    ],
];

$ch = curl_init('https://api.paymongo.com/v1/checkout_sessions');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'Accept: application/json',
        'Authorization: Basic ' . base64_encode(PAYMONGO_SECRET_KEY . ':'),
    ],
    CURLOPT_POSTFIELDS => json_encode($payload),
    CURLOPT_TIMEOUT    => 20,
]);
$raw      = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$response = json_decode($raw, true);

if ($httpCode !== 200 || empty($response['data']['attributes']['checkout_url'])) {
    error_log('[SendPaymentLink] PayMongo session failed: ' . $raw);
    $_SESSION['sa_error'] = 'PayMongo error — could not create checkout session. Try again.';
    header('Location: superadmin.php?page=tenants'); exit;
}

$session_id   = $response['data']['id'];
$checkout_url = $response['data']['attributes']['checkout_url'];

// ── Save session ID to tenant row ─────────────────────────────
$pdo->prepare("UPDATE tenants SET paymongo_session_id = ? WHERE id = ?")
    ->execute([$session_id, $tenant_id]);

// ── Generate QR code (data URI) ───────────────────────────────
// Uses the free Google Chart API — no key required, no installation needed
// Format: https://chart.googleapis.com/chart?cht=qr&chs=300x300&chl=<URL>
$qr_url        = 'https://chart.googleapis.com/chart?cht=qr&chs=300x300&chl=' . urlencode($checkout_url) . '&choe=UTF-8';
$qr_image_data = @file_get_contents($qr_url);
$qr_data_uri   = $qr_image_data
    ? 'data:image/png;base64,' . base64_encode($qr_image_data)
    : null;   // fallback: embed link only

// ── Send email to tenant ──────────────────────────────────────
$email_sent = sendPaymentLink(
    $email,
    $owner,
    $biz_name,
    $plan,
    $checkout_url,
    $qr_data_uri,
    $amount / 100   // in pesos for display
);

// ── Audit log ─────────────────────────────────────────────────
$log_msg = "SA sent PayMongo payment link to {$biz_name} ({$email}) for {$plan} plan. Session: {$session_id}. Email sent: " . ($email_sent ? 'yes' : 'no');
try {
    $pdo->prepare("
        INSERT INTO audit_logs
            (tenant_id,actor_user_id,actor_username,actor_role,action,entity_type,entity_id,message,ip_address,created_at)
        VALUES (?,?,?,?,'PAYMENT_LINK_SENT','tenant',?,?,?,NOW())
    ")->execute([
        $tenant_id, $u['id'], $u['username'], 'super_admin',
        $tenant_id, $log_msg, $_SERVER['REMOTE_ADDR'] ?? '::1',
    ]);
} catch (Throwable $e) {}

// ── Redirect with result ──────────────────────────────────────
if ($email_sent) {
    $_SESSION['sa_success'] = "✅ Payment link sent to <strong>{$email}</strong> for {$biz_name}.";
} else {
    $_SESSION['sa_error'] = "⚠️ PayMongo session created but email failed to send. Session ID: {$session_id}";
}
header('Location: superadmin.php?page=tenants'); exit;