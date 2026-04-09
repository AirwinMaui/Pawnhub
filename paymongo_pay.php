<?php
/**
 * paymongo_pay.php
 * ─────────────────────────────────────────────────────────────
 * Called after signup form submit (for Pro / Enterprise plans).
 * Creates a PayMongo Checkout Session and redirects the user.
 * ─────────────────────────────────────────────────────────────
 */

session_start();
require 'db.php';
require 'paymongo_config.php';   // your secret key lives here

// ── 1. Grab data from session (set right after DB insert) ────
$tenant_id   = $_SESSION['pending_tenant_id']   ?? null;
$user_id     = $_SESSION['pending_user_id']      ?? null;
$plan        = $_SESSION['pending_plan']         ?? 'Pro';
$email       = $_SESSION['pending_email']        ?? '';
$biz_name    = $_SESSION['pending_biz_name']     ?? 'PawnHub Tenant';

if (!$tenant_id || !$user_id) {
    die('Session expired. Please register again.');
}

// ── 2. Resolve plan price ────────────────────────────────────
$prices = [
    'Pro'        => 99900,    // ₱999.00  → in centavos
    'Enterprise' => 249900,   // ₱2,499.00
];
$amount = $prices[$plan] ?? 99900;

// ── 3. Build Checkout Session payload ───────────────────────
// NOTE: 'maya' was removed — it is not a valid payment_method_type in PayMongo.
//       Maya/PayMaya payments go through 'card' or 'dob_ubp' depending on integration.
//       Valid types: card, gcash, dob, billease, brankas_atlas, brankas_eastwest, brankas_robinson
$payload = [
    'data' => [
        'attributes' => [
            'billing' => [
                'email' => $email,
                'name'  => $biz_name,
            ],
            'line_items' => [[
                'currency'    => 'PHP',
                'amount'      => $amount,
                'name'        => "PawnHub {$plan} Plan — Monthly Subscription",
                'quantity'    => 1,
            ]],
            'payment_method_types' => ['card', 'gcash', 'dob', 'billease'],
            'success_url' => PAYMONGO_SUCCESS_URL . '?tenant=' . $tenant_id . '&user=' . $user_id,
            'cancel_url'  => PAYMONGO_CANCEL_URL,
            'metadata'    => [
                'tenant_id' => $tenant_id,
                'user_id'   => $user_id,
                'plan'      => $plan,
            ],
        ],
    ],
];

// ── 4. Call PayMongo API ─────────────────────────────────────
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
    // Log the error then show user-friendly message
    error_log('[PayMongo] Create session failed: ' . $raw);
    die('<p style="font-family:sans-serif;padding:30px;color:#dc2626;">
        Payment gateway error. Please try again or contact support.<br>
        <a href="signup.php">← Back to Registration</a>
    </p>');
}

// ── 5. Save checkout session ID in DB for webhook matching ───
$session_id = $response['data']['id'];
$pdo->prepare("UPDATE tenants SET paymongo_session_id=? WHERE id=?")
    ->execute([$session_id, $tenant_id]);

// ── 6. Redirect user to PayMongo hosted checkout page ───────
$checkout_url = $response['data']['attributes']['checkout_url'];
header('Location: ' . $checkout_url);
exit;