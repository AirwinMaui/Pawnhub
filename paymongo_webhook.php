<?php
/**
 * paymongo_webhook.php
 * ─────────────────────────────────────────────────────────────
 * PayMongo calls this URL automatically after payment is
 * confirmed on their end. This is where you ACTUALLY update
 * your DB and activate the tenant.
 *
 * Register this URL in PayMongo Dashboard → Developers → Webhooks
 * URL: https://yourdomain.com/paymongo_webhook.php
 * Events to listen: checkout_session.payment.paid
 * ─────────────────────────────────────────────────────────────
 */

require 'db.php';
require 'paymongo_config.php';

// ── 1. Only accept POST ──────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method Not Allowed');
}

// ── 2. Read raw body ─────────────────────────────────────────
$raw_body = file_get_contents('php://input');

// ── 3. Verify webhook signature ──────────────────────────────
// PayMongo sends: paymongo-signature: t=timestamp,te=hash,li=hash
$sig_header = $_SERVER['HTTP_PAYMONGO_SIGNATURE'] ?? '';

if (!verifyPayMongoSignature($raw_body, $sig_header, PAYMONGO_WEBHOOK_SECRET)) {
    http_response_code(400);
    error_log('[Webhook] Invalid signature: ' . $sig_header);
    exit('Invalid signature');
}

// ── 4. Parse event ───────────────────────────────────────────
$event = json_decode($raw_body, true);
$event_type = $event['data']['attributes']['type'] ?? '';

// ── 5. Handle checkout_session.payment.paid ──────────────────
if ($event_type === 'checkout_session.payment.paid') {
    $cs_data    = $event['data']['attributes']['data'] ?? [];
    $session_id = $cs_data['id'] ?? '';
    $metadata   = $cs_data['attributes']['metadata'] ?? [];

    $tenant_id = intval($metadata['tenant_id'] ?? 0);
    $user_id   = intval($metadata['user_id']   ?? 0);
    $plan      = $metadata['plan'] ?? '';

    if ($tenant_id && $user_id) {
        try {
            // Determine expiry (1 month from now)
            $expires_at = date('Y-m-d H:i:s', strtotime('+1 month'));

            // Resolve amount from plan
            $plan_amounts = ['Pro' => 99900, 'Enterprise' => 249900];
            $amount_centavos = $plan_amounts[$plan] ?? 0;

            // Activate tenant and user
            $pdo->prepare("UPDATE tenants SET status='active', plan=?, subscription_expires_at=?, paymongo_paid_at=NOW() WHERE id=? AND paymongo_session_id=?")
                ->execute([$plan, $expires_at, $tenant_id, $session_id]);

            $pdo->prepare("UPDATE users SET status='active' WHERE id=? AND tenant_id=?")
                ->execute([$user_id, $tenant_id]);

            // Log payment
            $pdo->prepare("INSERT INTO payment_logs (tenant_id, user_id, session_id, plan, amount, status, created_at) VALUES (?,?,?,?,?,'paid',NOW())")
                ->execute([$tenant_id, $user_id, $session_id, $plan, $amount_centavos]);

            error_log("[Webhook] Activated tenant_id={$tenant_id}, plan={$plan}");
        } catch (Throwable $e) {
            error_log("[Webhook] DB error for tenant_id={$tenant_id}: " . $e->getMessage());
            // Return 500 so PayMongo retries
            http_response_code(500);
            echo json_encode(['error' => 'db_error']);
            exit;
        }
    }
}

// ── 6. Always return 200 so PayMongo stops retrying ─────────
http_response_code(200);
echo json_encode(['received' => true]);
exit;


// ────────────────────────────────────────────────────────────
// Helper: Verify PayMongo Webhook Signature
// ────────────────────────────────────────────────────────────
function verifyPayMongoSignature(string $body, string $sigHeader, string $secret): bool
{
    if (!$sigHeader || !$secret) return false;

    // Parse header: t=...,te=...,li=...
    $parts = [];
    foreach (explode(',', $sigHeader) as $part) {
        [$k, $v] = explode('=', $part, 2) + [null, null];
        if ($k && $v) $parts[$k] = $v;
    }

    $timestamp = $parts['t'] ?? '';
    $te_hash   = $parts['te'] ?? '';   // test mode hash
    $li_hash   = $parts['li'] ?? '';   // live mode hash

    if (!$timestamp) return false;

    // Reconstruct the signed payload
    $signed_payload = $timestamp . '.' . $body;

    // Check either test or live hash
    $expected = hash_hmac('sha256', $signed_payload, $secret);

    return hash_equals($expected, $te_hash) || hash_equals($expected, $li_hash);
}