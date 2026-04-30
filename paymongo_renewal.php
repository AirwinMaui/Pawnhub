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
$allowed_actions = ['pay_via_paymongo', 'pay_upgrade_paymongo', 'pay_downgrade_paymongo'];
$action = $_POST['action'] ?? '';
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !in_array($action, $allowed_actions)) {
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

$current_plan = $tenant['plan'];
$email        = $tenant['email'];
$biz_name     = $tenant['business_name'];
$sub_end      = $tenant['subscription_end'] ?? null;

// ── Determine payment type and target plan ────────────────────
$payment_type = 'renewal';
$target_plan  = $current_plan;
$line_label   = '';

$plan_hierarchy = ['Starter' => 0, 'Pro' => 1, 'Enterprise' => 2];

if ($action === 'pay_upgrade_paymongo') {
    $upgrade_to = trim($_POST['upgrade_to'] ?? '');
    if (!isset($plan_hierarchy[$upgrade_to]) || $plan_hierarchy[$upgrade_to] <= ($plan_hierarchy[$current_plan] ?? 0)) {
        header('Location: tenant_subscription.php?error=invalid_upgrade'); exit;
    }
    $target_plan  = $upgrade_to;
    $payment_type = 'upgrade';
    $line_label   = "PawnHub Plan Upgrade: {$current_plan} → {$upgrade_to}";

} elseif ($action === 'pay_downgrade_paymongo') {
    $downgrade_to = trim($_POST['downgrade_to'] ?? '');
    if (!isset($plan_hierarchy[$downgrade_to]) || $plan_hierarchy[$downgrade_to] >= ($plan_hierarchy[$current_plan] ?? 99)) {
        header('Location: tenant_subscription.php?error=invalid_downgrade'); exit;
    }
    // Block downgrade if subscription still active
    if ($sub_end && strtotime($sub_end) > time()) {
        $days_left = (int)ceil((strtotime($sub_end) - time()) / 86400);
        $expiry    = date('F d, Y', strtotime($sub_end));
        die("<p style='font-family:sans-serif;padding:30px;color:#dc2626;'>
            ⛔ You cannot downgrade while your <strong>{$current_plan}</strong> subscription is still active.<br>
            It expires on <strong>{$expiry}</strong> ({$days_left} day(s) remaining).<br>
            <a href='tenant_subscription.php'>← Back to Subscription</a>
        </p>");
    }
    // Starter is free — should not pay via PayMongo
    if ($downgrade_to === 'Starter') {
        header('Location: tenant_subscription.php?error=starter_free'); exit;
    }
    $target_plan  = $downgrade_to;
    $payment_type = 'downgrade';
    $line_label   = "PawnHub Plan Downgrade: {$current_plan} → {$downgrade_to}";

} else {
    // Normal renewal
    $payment_type = 'renewal';
    $target_plan  = $current_plan;
    $line_label   = '';
}

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

// Starter is free — guard
if (!isset($billing_amounts_centavos[$target_plan])) {
    header('Location: tenant_subscription.php?error=free_plan'); exit;
}

$amount_centavos = $billing_amounts_centavos[$target_plan][$billing_cycle] ?? 99900;
$amount_pesos    = $amount_centavos / 100;

// For upgrades: apply proration credit
$proration_credit_centavos = 0;
if ($payment_type === 'upgrade' && $sub_end && strtotime($sub_end) > time()) {
    $days_left_upgrade = (int)ceil((strtotime($sub_end) - time()) / 86400);
    $current_monthly_centavos = $billing_amounts_centavos[$current_plan]['monthly'] ?? 0;
    $daily_rate_centavos = $current_monthly_centavos / 30;
    $proration_credit_centavos = (int)round($daily_rate_centavos * $days_left_upgrade);
    $amount_centavos = max(0, $amount_centavos - $proration_credit_centavos);
    $amount_pesos    = $amount_centavos / 100;
}

if (empty($line_label)) {
    $plan = $target_plan;
    $line_label = "PawnHub {$plan} Plan — {$billing_labels[$billing_cycle]} Renewal";
}

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
                'amount'   => max(100, $amount_centavos), // PayMongo min = ₱1
                'name'     => $line_label,
                'quantity' => 1,
            ]],
            'payment_method_types' => ['card', 'gcash', 'paymaya', 'dob', 'billease'],
            'success_url' => PAYMONGO_SUCCESS_URL_RENEWAL . '?tenant=' . $tid . '&user=' . $uid . '&cycle=' . $billing_cycle . '&type=' . $payment_type,
            'cancel_url'  => PAYMONGO_CANCEL_URL_RENEWAL,
            'metadata'    => [
                'tenant_id'                => $tid,
                'user_id'                  => $uid,
                'plan'                     => $target_plan,
                'current_plan'             => $current_plan,
                'billing_cycle'            => $billing_cycle,
                'type'                     => $payment_type,  // 'renewal', 'upgrade', 'downgrade'
                'proration_credit_centavos'=> $proration_credit_centavos,
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
$_SESSION['renewal_type']              = $payment_type;

// ── Log the attempt ───────────────────────────────────────────
$action_map = [
    'renewal'   => 'RENEWAL_PAYMONGO_INITIATED',
    'upgrade'   => 'UPGRADE_PAYMONGO_INITIATED',
    'downgrade' => 'DOWNGRADE_PAYMONGO_INITIATED',
];
$log_action = $action_map[$payment_type] ?? 'RENEWAL_PAYMONGO_INITIATED';
$log_msg    = match($payment_type) {
    'upgrade'   => "PayMongo upgrade checkout initiated for {$biz_name} ({$current_plan} → {$target_plan}, {$billing_cycle}). Amount: ₱{$amount_pesos}. Session: {$session_id}.",
    'downgrade' => "PayMongo downgrade checkout initiated for {$biz_name} ({$current_plan} → {$target_plan}, {$billing_cycle}). Amount: ₱{$amount_pesos}. Session: {$session_id}.",
    default     => "PayMongo renewal checkout initiated for {$biz_name} ({$target_plan}, {$billing_cycle}). Amount: ₱{$amount_pesos}. Session: {$session_id}.",
};
try {
    $pdo->prepare("
        INSERT INTO audit_logs
            (tenant_id,actor_user_id,actor_username,actor_role,action,entity_type,entity_id,message,ip_address,created_at)
        VALUES (?,?,?,?,?,'subscription',?,?,?,NOW())
    ")->execute([
        $tid, $uid, $u['username'], 'admin', $log_action, $tid,
        $log_msg,
        $_SERVER['REMOTE_ADDR'] ?? '::1',
    ]);
} catch (Throwable $e) {}

// ── Redirect to PayMongo hosted checkout ──────────────────────
$checkout_url = $response['data']['attributes']['checkout_url'];
header('Location: ' . $checkout_url);
exit;