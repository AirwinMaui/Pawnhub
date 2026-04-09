<?php
/**
 * paymongo_webhook.php
 * ─────────────────────────────────────────────────────────────
 * PayMongo calls this URL automatically after payment is
 * confirmed on their end.
 *
 * ⚠️  IMPORTANT FLOW CHANGE:
 *     This webhook NO LONGER auto-activates the tenant.
 *     It only records that payment was received
 *     (payment_status = 'paid' on the tenants row).
 *
 *     The Super Admin still must review the Business Permit
 *     + payment proof and click "Approve" in superadmin.php.
 *     THAT is what sets status = 'active' and sends the email.
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
$event      = json_decode($raw_body, true);
$event_type = $event['data']['attributes']['type'] ?? '';

// ── 5. Handle checkout_session.payment.paid ──────────────────
if ($event_type === 'checkout_session.payment.paid') {
    $cs_data    = $event['data']['attributes']['data'] ?? [];
    $session_id = $cs_data['id'] ?? '';
    $metadata   = $cs_data['attributes']['metadata'] ?? [];
    $attr       = $cs_data['attributes'] ?? [];

    $tenant_id = intval($metadata['tenant_id'] ?? 0);
    $user_id   = intval($metadata['user_id']   ?? 0);
    $plan      = $metadata['plan'] ?? '';

    // Gather payment details for SA review
    $payment_method = '';
    $payments = $attr['payments'] ?? [];
    if (!empty($payments[0])) {
        $pm = $payments[0]['attributes']['payment_method_used'] ?? '';
        $payment_method = strtoupper($pm);
    }

    $plan_amounts   = ['Pro' => 99900, 'Enterprise' => 249900];
    $amount_paid    = $plan_amounts[$plan] ?? 0;

    if ($tenant_id && $user_id) {
        try {
            // ── Mark payment received ONLY — do NOT activate tenant ──
            // Tenant status stays 'pending' until Super Admin approves.
            $pdo->prepare("
                UPDATE tenants
                SET payment_status      = 'paid',
                    paymongo_paid_at    = NOW(),
                    paymongo_session_id = ?
                WHERE id = ?
            ")->execute([$session_id, $tenant_id]);

            // Log to payment_logs if the table exists
            try {
                $pdo->prepare("
                    INSERT INTO payment_logs
                        (tenant_id, user_id, session_id, plan, amount, payment_method, status, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, 'paid', NOW())
                ")->execute([$tenant_id, $user_id, $session_id, $plan, $amount_paid, $payment_method]);
            } catch (PDOException $e) {
                // payment_logs table may not exist — non-fatal
                error_log('[Webhook] payment_logs insert skipped: ' . $e->getMessage());
            }

            error_log("[Webhook] Payment recorded for tenant_id={$tenant_id}, plan={$plan}, method={$payment_method}. Awaiting SA approval.");

        } catch (Throwable $e) {
            error_log("[Webhook] DB error for tenant_id={$tenant_id}: " . $e->getMessage());
            // Return 500 so PayMongo retries
            http_response_code(500);
            echo json_encode(['error' => 'db_error']);
            exit;
        }
    } else {
        error_log("[Webhook] Missing tenant_id or user_id in metadata. session_id={$session_id}");
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

    $timestamp = $parts['t']  ?? '';
    $te_hash   = $parts['te'] ?? '';   // test mode hash
    $li_hash   = $parts['li'] ?? '';   // live mode hash

    if (!$timestamp) return false;

    // Reconstruct the signed payload
    $signed_payload = $timestamp . '.' . $body;

    // Check either test or live hash
    $expected = hash_hmac('sha256', $signed_payload, $secret);

    return hash_equals($expected, $te_hash) || hash_equals($expected, $li_hash);
}