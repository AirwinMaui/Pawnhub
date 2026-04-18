<?php
/**
 * paymongo_webhook.php
 * ─────────────────────────────────────────────────────────────
 * PayMongo calls this URL automatically after payment is
 * confirmed on their end.
 *
 * Handles TWO payment types via metadata.type:
 *   • (empty / 'signup') → Initial tenant registration payment
 *   • 'renewal'          → Subscription renewal payment
 *
 * ⚠️  IMPORTANT FLOW:
 *     For SIGNUP:  sets payment_status='paid' on tenants row.
 *                  SA still approves to activate.
 *     For RENEWAL: inserts subscription_renewals row (status='pending')
 *                  + sets payment_status='paid'.
 *                  SA still approves in Subscriptions page to extend.
 *
 * Register this URL in PayMongo Dashboard → Developers → Webhooks
 * URL: https://yourdomain.com/paymongo_webhook.php
 * Events: checkout_session.payment.paid
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

    $tenant_id     = intval($metadata['tenant_id']     ?? 0);
    $user_id       = intval($metadata['user_id']       ?? 0);
    $plan          = $metadata['plan']                 ?? '';
    $billing_cycle = $metadata['billing_cycle']        ?? 'monthly';
    $payment_type  = $metadata['type']                 ?? 'signup';   // 'signup' or 'renewal'

    // Resolve payment method used
    $payment_method = '';
    $payments = $attr['payments'] ?? [];
    if (!empty($payments[0])) {
        $pm = $payments[0]['attributes']['payment_method_used'] ?? '';
        $payment_method = strtoupper($pm);
    }

    // Resolve amount from plan + billing cycle
    $billing_amounts = [
        'Pro'        => ['monthly' => 999,  'quarterly' => 2697,  'annually' => 9588],
        'Enterprise' => ['monthly' => 2499, 'quarterly' => 6747,  'annually' => 23988],
        'Starter'    => ['monthly' => 0,    'quarterly' => 0,     'annually' => 0],
    ];
    $amount_paid = $billing_amounts[$plan][$billing_cycle] ?? ($billing_amounts[$plan]['monthly'] ?? 0);

    if (!$tenant_id || !$user_id) {
        error_log("[Webhook] Missing tenant_id or user_id in metadata. session_id={$session_id}");
        http_response_code(200);
        echo json_encode(['received' => true]);
        exit;
    }

    // Also extract upgrade/downgrade metadata
    $current_plan              = $metadata['current_plan']              ?? $plan;
    $proration_credit_centavos = intval($metadata['proration_credit_centavos'] ?? 0);
    $proration_credit_pesos    = $proration_credit_centavos / 100;

    try {
        // ── Mark tenant payment_status = paid (all types) ─────
        $pdo->prepare("
            UPDATE tenants
            SET payment_status = 'paid',
                paymongo_paid_at = NOW(),
                paymongo_session_id = ?
            WHERE id = ?
        ")->execute([$session_id, $tenant_id]);

        // ── Deduplicate check ─────────────────────────────────
        $dup = $pdo->prepare("
            SELECT id FROM subscription_renewals
            WHERE tenant_id = ? AND payment_reference = ? AND status = 'pending'
            LIMIT 1
        ");
        $dup->execute([$tenant_id, $session_id]);

        if (!$dup->fetch()) {

            if ($payment_type === 'upgrade') {
                // ── A. UPGRADE PAYMENT ────────────────────────
                // Insert as upgrade row — SA approves to switch plan
                $upgrade_notes = "PLAN UPGRADE: {$current_plan} → {$plan} ({$billing_cycle}) via PayMongo."
                    . ($proration_credit_pesos > 0 ? " Proration credit: ₱{$proration_credit_pesos}." : '');
                try {
                    $pdo->prepare("
                        INSERT INTO subscription_renewals
                            (tenant_id, plan, billing_cycle, payment_method, payment_reference,
                             amount, notes, status, requested_at, is_upgrade, upgrade_from, upgrade_to, proration_credit)
                        VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', NOW(), 1, ?, ?, ?)
                    ")->execute([
                        $tenant_id, $plan, $billing_cycle,
                        'PayMongo — ' . $payment_method,
                        $session_id, $amount_paid,
                        $upgrade_notes,
                        $current_plan, $plan, $proration_credit_pesos,
                    ]);
                } catch (PDOException $e) {
                    // Fallback without new columns
                    $pdo->prepare("
                        INSERT INTO subscription_renewals
                            (tenant_id, plan, billing_cycle, payment_method, payment_reference, amount, notes, status, requested_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', NOW())
                    ")->execute([
                        $tenant_id, $plan, $billing_cycle,
                        'PayMongo — ' . $payment_method,
                        $session_id, $amount_paid, $upgrade_notes,
                    ]);
                }
                error_log("[Webhook] UPGRADE payment recorded for tenant_id={$tenant_id}, {$current_plan}→{$plan}, cycle={$billing_cycle}, method={$payment_method}. Awaiting SA approval.");

            } elseif ($payment_type === 'downgrade') {
                // ── B. DOWNGRADE PAYMENT ──────────────────────
                // Insert as downgrade row — SA approves to switch plan
                $dg_notes = "PLAN DOWNGRADE: {$current_plan} → {$plan} ({$billing_cycle}) via PayMongo.";
                try {
                    $pdo->prepare("
                        INSERT INTO subscription_renewals
                            (tenant_id, plan, billing_cycle, payment_method, payment_reference,
                             amount, notes, status, requested_at, is_upgrade, upgrade_from, upgrade_to, proration_credit)
                        VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', NOW(), 0, ?, ?, 0)
                    ")->execute([
                        $tenant_id, $plan, $billing_cycle,
                        'PayMongo — ' . $payment_method,
                        $session_id, $amount_paid,
                        $dg_notes,
                        $current_plan, $plan,
                    ]);
                } catch (PDOException $e) {
                    $pdo->prepare("
                        INSERT INTO subscription_renewals
                            (tenant_id, plan, billing_cycle, payment_method, payment_reference, amount, notes, status, requested_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', NOW())
                    ")->execute([
                        $tenant_id, $plan, $billing_cycle,
                        'PayMongo — ' . $payment_method,
                        $session_id, $amount_paid, $dg_notes,
                    ]);
                }
                error_log("[Webhook] DOWNGRADE payment recorded for tenant_id={$tenant_id}, {$current_plan}→{$plan}, cycle={$billing_cycle}, method={$payment_method}. Awaiting SA approval.");

            } elseif ($payment_type === 'renewal') {
                // ── C. RENEWAL PAYMENT ────────────────────────
                $pdo->prepare("
                    INSERT INTO subscription_renewals
                        (tenant_id, plan, billing_cycle, payment_method, payment_reference, amount, status, requested_at)
                    VALUES (?, ?, ?, ?, ?, ?, 'pending', NOW())
                ")->execute([
                    $tenant_id, $plan, $billing_cycle,
                    'PayMongo — ' . $payment_method,
                    $session_id, $amount_paid,
                ]);
                error_log("[Webhook] RENEWAL payment recorded for tenant_id={$tenant_id}, plan={$plan}, cycle={$billing_cycle}, method={$payment_method}. Awaiting SA approval.");

            } else {
                // ── D. INITIAL SIGNUP PAYMENT ─────────────────
                // No subscription_renewals row — just mark paid, SA reviews permit + payment
                error_log("[Webhook] SIGNUP payment recorded for tenant_id={$tenant_id}, plan={$plan}, method={$payment_method}. Awaiting SA approval.");
            }
        }

        // ── Log to payment_logs (non-fatal, all types) ────────
        try {
            $pdo->prepare("
                INSERT INTO payment_logs
                    (tenant_id, user_id, session_id, plan, amount, payment_method, status, created_at)
                VALUES (?, ?, ?, ?, ?, ?, 'paid', NOW())
            ")->execute([$tenant_id, $user_id, $session_id, $plan, $amount_paid, 'PayMongo — ' . $payment_method]);
        } catch (PDOException $e) {
            error_log('[Webhook] payment_logs insert skipped: ' . $e->getMessage());
        }

    } catch (Throwable $e) {
        error_log("[Webhook] DB error for tenant_id={$tenant_id}: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'db_error']);
        exit;
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

    $parts = [];
    foreach (explode(',', $sigHeader) as $part) {
        [$k, $v] = explode('=', $part, 2) + [null, null];
        if ($k && $v) $parts[$k] = $v;
    }

    $timestamp = $parts['t']  ?? '';
    $te_hash   = $parts['te'] ?? '';
    $li_hash   = $parts['li'] ?? '';

    if (!$timestamp) return false;

    $signed_payload = $timestamp . '.' . $body;
    $expected       = hash_hmac('sha256', $signed_payload, $secret);

    return hash_equals($expected, $te_hash) || hash_equals($expected, $li_hash);
}