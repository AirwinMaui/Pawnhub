<?php
/**
 * paymongo_renewal.php
 * ─────────────────────────────────────────────────────────────
 * Called when a tenant clicks "Pay via PayMongo" on the
 * tenant_subscription.php page.
 *
 * Creates a PayMongo Checkout Session for subscription renewal
 * and redirects the tenant to the hosted checkout page.
 *
 * On success → paymongo_renewal_success.php
 * Webhook    → paymongo_webhook.php (handles both signup & renewal)
 * ─────────────────────────────────────────────────────────────
 */

require_once __DIR__ . '/session_helper.php';
pawnhub_session_start('admin');
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/paymongo_config.php';

// ── Auth: only tenant admins ──────────────────────────────────
if (empty($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
    header('Location: login.php'); exit;
}

$u   = $_SESSION['user'];
$tid = (int)($u['tenant_id'] ?? 0);
$uid = (int)($u['id'] ?? 0);

if (!$tid || !$uid) {
    die('Session error. Please log in again.');
}

// ── Validate POST ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || ($_POST['action'] ?? '') !== 'pay_via_paymongo') {
    header('Location: tenant_subscription.php'); exit;
}

$billing_cycle = in_array($_POST['billing_cycle'] ?? '', ['monthly', 'quarterly', 'annually'])
    ? $_POST['billing_cycle']
    : 'monthly';

// ── Fetch tenant ───────────────────────────────────────────────
$stmt = $pdo->prepare("SELECT * FROM tenants WHERE id = ? LIMIT 1");
$stmt->execute([$tid]);
$tenant = $stmt->fetch();

if (!$tenant || $tenant['status'] !== 'active') {
    die('Tenant account not found or inactive.');
}

$plan     = $tenant['plan'];
$email    = $tenant['email'];
$biz_name = $tenant['business_name'];

// ── Check for existing pending renewal ───────────────────────
$pending_chk = $pdo->prepare("
    SELECT id FROM subscription_renewals
    WHERE tenant_id = ? AND status = 'pending'
    LIMIT 1
");
$pending_chk->execute([$tid]);
if ($pending_chk->fetch()) {
    header('Location: tenant_subscription.php?error=already_pending'); exit;
}

// ── Plan pricing (in centavos) ────────────────────────────────
$billing_amounts_centavos = [
    'Pro'        => ['monthly' => 99900,   'quarterly' => 269700,  'annually' => 958800],
    'Enterprise' => ['monthly' => 249900,  'quarterly' => 674700,  'annually' => 2398800],
];

$billing_labels = [
    'monthly'   => 'Monthly',
    'quarterly' => 'Quarterly (3 months)',
    'annually'  => 'Annual (12 months)',
];

// Starter is free — should not reach here, but guard anyway
if (!isset($billing_amounts_centavos[$plan])) {
    header('Location: tenant_subscription.php?error=free_plan'); exit;
}

$amount_centavos = $billing_amounts_centavos[$plan][$billing_cycle] ?? 99900;
$amount_pesos    = $amount_centavos / 100;

// ── Build Checkout Session payload ────────────────────────────
$payload = [
    'data' => [
        'attributes' => [
            'billing' => [
                'email' => $email,
                'name'  => $biz_name,
            ],
            'line_items' => [[
                'currency' => 'PHP',
                'amount'   => $amount_centavos,
                'name'     => "PawnHub {$plan} Plan — {$billing_labels[$billing_cycle]} Renewal",
                'quantity' => 1,
            ]],
            'payment_method_types' => ['card', 'gcash', 'dob', 'billease'],
            'success_url' => PAYMONGO_SUCCESS_URL_RENEWAL . '?tenant=' . $tid . '&user=' . $uid . '&cycle=' . $billing_cycle,
            'cancel_url'  => PAYMONGO_CANCEL_URL_RENEWAL,
            'metadata'    => [
                'tenant_id'     => $tid,
                'user_id'       => $uid,
                'plan'          => $plan,
                'billing_cycle' => $billing_cycle,
                'type'          => 'renewal',   // ← distinguishes from initial signup
            ],
        ],
    ],
];

// ── Call PayMongo API ─────────────────────────────────────────
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
]);
$raw      = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$response = json_decode($raw, true);

if ($httpCode !== 200 || empty($response['data']['attributes']['checkout_url'])) {
    error_log('[PayMongo Renewal] Create session failed: ' . $raw);
    header('Location: tenant_subscription.php?error=paymongo_fail'); exit;
}

// ── Save checkout session ID to session ───────────────────────
$session_id = $response['data']['id'];
$_SESSION['renewal_paymongo_session']  = $session_id;
$_SESSION['renewal_billing_cycle']     = $billing_cycle;
$_SESSION['renewal_amount']            = $amount_pesos;

// ── Log the attempt ───────────────────────────────────────────
try {
    $pdo->prepare("
        INSERT INTO audit_logs
            (tenant_id,actor_user_id,actor_username,actor_role,action,entity_type,entity_id,message,ip_address,created_at)
        VALUES (?,?,?,?,'RENEWAL_PAYMONGO_INITIATED','subscription',?,?,?,NOW())
    ")->execute([
        $tid, $uid, $u['username'], 'admin', $tid,
        "PayMongo renewal checkout initiated for {$biz_name} ({$plan}, {$billing_cycle}). Session: {$session_id}.",
        $_SERVER['REMOTE_ADDR'] ?? '::1',
    ]);
} catch (Throwable $e) {}

// ── Redirect to PayMongo hosted checkout ──────────────────────
$checkout_url = $response['data']['attributes']['checkout_url'];
header('Location: ' . $checkout_url);
exit;