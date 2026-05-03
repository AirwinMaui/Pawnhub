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
 *     For SIGNUP:  AUTO-APPROVES tenant immediately after payment.
 *                  Activates tenant + user, sends login email automatically.
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
    $payment_type  = $metadata['type']                 ?? 'signup';   // 'signup', 'renewal', 'upgrade', 'downgrade', 'reactivation'

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

    if (!$tenant_id) {
        error_log("[Webhook] Missing tenant_id in metadata. session_id={$session_id}");
        http_response_code(200);
        echo json_encode(['received' => true]);
        exit;
    }

    // Also extract upgrade/downgrade metadata
    $current_plan              = $metadata['current_plan']              ?? $plan;
    $proration_credit_centavos = intval($metadata['proration_credit_centavos'] ?? 0);
    $proration_credit_pesos    = $proration_credit_centavos / 100;

    // ── Fetch all Super Admin accounts for notifications ──────
    $sa_admins = [];
    try {
        $sa_stmt = $pdo->query("SELECT email, fullname FROM users WHERE role='super_admin' AND status='approved' LIMIT 10");
        $sa_admins = $sa_stmt->fetchAll();
    } catch (Throwable $e) {
        error_log("[Webhook] Could not fetch SA emails: " . $e->getMessage());
    }

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
                // ── A. UPGRADE PAYMENT — AUTO APPROVE ────────
                try {
                    $billing_months_map = ['monthly' => 1, 'quarterly' => 3, 'annually' => 12];
                    $months = $billing_months_map[$billing_cycle] ?? 1;
                    $new_sub_end = date('Y-m-d', strtotime("+{$months} months"));

                    // Switch plan immediately
                    $pdo->prepare("
                        UPDATE tenants SET
                            plan                = ?,
                            status              = 'active',
                            subscription_start  = CURDATE(),
                            subscription_end    = ?,
                            subscription_status = 'active',
                            renewal_reminded_7d = 0,
                            renewal_reminded_3d = 0,
                            renewal_reminded_1d = 0
                        WHERE id = ?
                    ")->execute([$plan, $new_sub_end, $tenant_id]);

                    $upgrade_notes = "PLAN UPGRADE: {$current_plan} → {$plan} ({$billing_cycle}) via PayMongo. Auto-approved."
                        . ($proration_credit_pesos > 0 ? " Proration credit: ₱{$proration_credit_pesos}." : '');

                    try {
                        $pdo->prepare("
                            INSERT INTO subscription_renewals
                                (tenant_id, plan, billing_cycle, payment_method, payment_reference,
                                 amount, notes, status, requested_at, reviewed_at, new_subscription_end, is_upgrade, upgrade_from, upgrade_to, proration_credit)
                            VALUES (?, ?, ?, ?, ?, ?, ?, 'approved', NOW(), NOW(), ?, 1, ?, ?, ?)
                        ")->execute([
                            $tenant_id, $plan, $billing_cycle,
                            'PayMongo — ' . $payment_method,
                            $session_id, $amount_paid,
                            $upgrade_notes, $new_sub_end,
                            $current_plan, $plan, $proration_credit_pesos,
                        ]);
                    } catch (PDOException $e) {
                        $pdo->prepare("
                            INSERT INTO subscription_renewals
                                (tenant_id, plan, billing_cycle, payment_method, payment_reference, amount, notes, status, requested_at, reviewed_at, new_subscription_end)
                            VALUES (?, ?, ?, ?, ?, ?, ?, 'approved', NOW(), NOW(), ?)
                        ")->execute([
                            $tenant_id, $plan, $billing_cycle,
                            'PayMongo — ' . $payment_method,
                            $session_id, $amount_paid, $upgrade_notes, $new_sub_end,
                        ]);
                    }

                    // Send renewal/upgrade email
                    try {
                        require_once __DIR__ . '/mailer.php';
                        $t_info = $pdo->prepare("SELECT business_name, owner_name, email, slug FROM tenants WHERE id=? LIMIT 1");
                        $t_info->execute([$tenant_id]);
                        $t_info = $t_info->fetch();
                        if ($t_info && function_exists('sendSubscriptionRenewed')) {
                            sendSubscriptionRenewed(
                                $t_info['email'], $t_info['owner_name'],
                                $t_info['business_name'], $plan,
                                $new_sub_end, $t_info['slug']
                            );
                        }
                    } catch (Throwable $mail_err) {
                        error_log("[Webhook] Upgrade email error: " . $mail_err->getMessage());
                    }

                    // Audit log
                    try {
                        $pdo->prepare("
                            INSERT INTO audit_logs
                                (tenant_id,actor_user_id,actor_username,actor_role,action,entity_type,entity_id,message,ip_address,created_at)
                            VALUES (?,NULL,'system','system','PLAN_UPGRADED','subscription',?,?,?,NOW())
                        ")->execute([
                            $tenant_id, $tenant_id,
                            "Plan auto-upgraded via PayMongo: {$current_plan} → {$plan}, Cycle: {$billing_cycle}, New expiry: {$new_sub_end}, Session: {$session_id}.",
                            '::webhook',
                        ]);
                    } catch (Throwable $e) {}

                    // ── Notify Super Admins ───────────────────
                    try {
                        require_once __DIR__ . '/mailer.php';
                        $up_t = $pdo->prepare("SELECT business_name, owner_name FROM tenants WHERE id=? LIMIT 1");
                        $up_t->execute([$tenant_id]);
                        $up_t = $up_t->fetch();
                        if ($up_t && function_exists('sendSuperAdminPaymentNotif')) {
                            foreach ($sa_admins as $sa) {
                                sendSuperAdminPaymentNotif(
                                    $sa['email'], $sa['fullname'],
                                    $up_t['business_name'], $up_t['owner_name'],
                                    $plan, 'upgrade', $amount_paid,
                                    $billing_cycle, 'PayMongo — ' . $payment_method, $new_sub_end, $tenant_id
                                );
                            }
                        }
                    } catch (Throwable $sa_err) {
                        error_log("[Webhook] SA notif error (upgrade): " . $sa_err->getMessage());
                    }

                    error_log("[Webhook] UPGRADE AUTO-APPROVED: tenant_id={$tenant_id}, {$current_plan}→{$plan}, cycle={$billing_cycle}, new_end={$new_sub_end}, method={$payment_method}");
                } catch (Throwable $up_err) {
                    error_log("[Webhook] Upgrade error for tenant_id={$tenant_id}: " . $up_err->getMessage());
                }

            } elseif ($payment_type === 'downgrade') {
                // ── B. DOWNGRADE PAYMENT — AUTO APPROVE ──────
                try {
                    $billing_months_map = ['monthly' => 1, 'quarterly' => 3, 'annually' => 12];
                    $months = $billing_months_map[$billing_cycle] ?? 1;
                    $new_sub_end = date('Y-m-d', strtotime("+{$months} months"));

                    // Switch to lower plan immediately
                    $pdo->prepare("
                        UPDATE tenants SET
                            plan                = ?,
                            status              = 'active',
                            subscription_start  = CURDATE(),
                            subscription_end    = ?,
                            subscription_status = 'active',
                            renewal_reminded_7d = 0,
                            renewal_reminded_3d = 0,
                            renewal_reminded_1d = 0
                        WHERE id = ?
                    ")->execute([$plan, $new_sub_end, $tenant_id]);

                    $dg_notes = "PLAN DOWNGRADE: {$current_plan} → {$plan} ({$billing_cycle}) via PayMongo. Auto-approved.";
                    try {
                        $pdo->prepare("
                            INSERT INTO subscription_renewals
                                (tenant_id, plan, billing_cycle, payment_method, payment_reference,
                                 amount, notes, status, requested_at, reviewed_at, new_subscription_end, is_upgrade, upgrade_from, upgrade_to, proration_credit)
                            VALUES (?, ?, ?, ?, ?, ?, ?, 'approved', NOW(), NOW(), ?, 0, ?, ?, 0)
                        ")->execute([
                            $tenant_id, $plan, $billing_cycle,
                            'PayMongo — ' . $payment_method,
                            $session_id, $amount_paid,
                            $dg_notes, $new_sub_end,
                            $current_plan, $plan,
                        ]);
                    } catch (PDOException $e) {
                        $pdo->prepare("
                            INSERT INTO subscription_renewals
                                (tenant_id, plan, billing_cycle, payment_method, payment_reference, amount, notes, status, requested_at, reviewed_at, new_subscription_end)
                            VALUES (?, ?, ?, ?, ?, ?, ?, 'approved', NOW(), NOW(), ?)
                        ")->execute([
                            $tenant_id, $plan, $billing_cycle,
                            'PayMongo — ' . $payment_method,
                            $session_id, $amount_paid, $dg_notes, $new_sub_end,
                        ]);
                    }

                    // Send confirmation email
                    try {
                        require_once __DIR__ . '/mailer.php';
                        $t_info = $pdo->prepare("SELECT business_name, owner_name, email, slug FROM tenants WHERE id=? LIMIT 1");
                        $t_info->execute([$tenant_id]);
                        $t_info = $t_info->fetch();
                        if ($t_info && function_exists('sendSubscriptionRenewed')) {
                            sendSubscriptionRenewed(
                                $t_info['email'], $t_info['owner_name'],
                                $t_info['business_name'], $plan,
                                $new_sub_end, $t_info['slug']
                            );
                        }
                    } catch (Throwable $mail_err) {
                        error_log("[Webhook] Downgrade email error: " . $mail_err->getMessage());
                    }

                    // Audit log
                    try {
                        $pdo->prepare("
                            INSERT INTO audit_logs
                                (tenant_id,actor_user_id,actor_username,actor_role,action,entity_type,entity_id,message,ip_address,created_at)
                            VALUES (?,NULL,'system','system','PLAN_DOWNGRADED','subscription',?,?,?,NOW())
                        ")->execute([
                            $tenant_id, $tenant_id,
                            "Plan auto-downgraded via PayMongo: {$current_plan} → {$plan}, Cycle: {$billing_cycle}, New expiry: {$new_sub_end}, Session: {$session_id}.",
                            '::webhook',
                        ]);
                    } catch (Throwable $e) {}

                    // ── Notify Super Admins ───────────────────
                    try {
                        require_once __DIR__ . '/mailer.php';
                        if ($t_info && function_exists('sendSuperAdminPaymentNotif')) {
                            foreach ($sa_admins as $sa) {
                                sendSuperAdminPaymentNotif(
                                    $sa['email'], $sa['fullname'],
                                    $t_info['business_name'], $t_info['owner_name'],
                                    $plan, 'downgrade', $amount_paid,
                                    $billing_cycle, 'PayMongo — ' . $payment_method, $new_sub_end, $tenant_id
                                );
                            }
                        }
                    } catch (Throwable $sa_err) {
                        error_log("[Webhook] SA notif error (downgrade): " . $sa_err->getMessage());
                    }

                    error_log("[Webhook] DOWNGRADE AUTO-APPROVED: tenant_id={$tenant_id}, {$current_plan}→{$plan}, cycle={$billing_cycle}, new_end={$new_sub_end}, method={$payment_method}");
                } catch (Throwable $dg_err) {
                    error_log("[Webhook] Downgrade error for tenant_id={$tenant_id}: " . $dg_err->getMessage());
                }

            } elseif ($payment_type === 'renewal') {
                // ── C. RENEWAL PAYMENT — AUTO APPROVE ────────
                try {
                    $billing_months_map = ['monthly' => 1, 'quarterly' => 3, 'annually' => 12];
                    $months = $billing_months_map[$billing_cycle] ?? 1;

                    // Extend from current subscription_end if still active, else from today
                    $t_now = $pdo->prepare("SELECT subscription_end, subscription_status FROM tenants WHERE id=? LIMIT 1");
                    $t_now->execute([$tenant_id]);
                    $t_now = $t_now->fetch();
                    $base_date = ($t_now && $t_now['subscription_end'] && strtotime($t_now['subscription_end']) > time())
                        ? $t_now['subscription_end']
                        : date('Y-m-d');
                    $new_sub_end = date('Y-m-d', strtotime("+{$months} months", strtotime($base_date)));

                    // Extend subscription
                    $pdo->prepare("
                        UPDATE tenants SET
                            status              = 'active',
                            plan                = ?,
                            subscription_end    = ?,
                            subscription_status = 'active',
                            renewal_reminded_7d = 0,
                            renewal_reminded_3d = 0,
                            renewal_reminded_1d = 0
                        WHERE id = ?
                    ")->execute([$plan, $new_sub_end, $tenant_id]);

                    // Record as approved
                    $pdo->prepare("
                        INSERT INTO subscription_renewals
                            (tenant_id, plan, billing_cycle, payment_method, payment_reference,
                             amount, status, requested_at, reviewed_at, new_subscription_end, notes)
                        VALUES (?, ?, ?, ?, ?, ?, 'approved', NOW(), NOW(), ?, 'Auto-approved: Renewal via PayMongo.')
                    ")->execute([
                        $tenant_id, $plan, $billing_cycle,
                        'PayMongo — ' . $payment_method,
                        $session_id, $amount_paid, $new_sub_end,
                    ]);

                    // Send renewal confirmation email
                    try {
                        require_once __DIR__ . '/mailer.php';
                        $t_info = $pdo->prepare("SELECT business_name, owner_name, email, slug FROM tenants WHERE id=? LIMIT 1");
                        $t_info->execute([$tenant_id]);
                        $t_info = $t_info->fetch();
                        if ($t_info && function_exists('sendSubscriptionRenewed')) {
                            sendSubscriptionRenewed(
                                $t_info['email'], $t_info['owner_name'],
                                $t_info['business_name'], $plan,
                                $new_sub_end, $t_info['slug']
                            );
                        }
                    } catch (Throwable $mail_err) {
                        error_log("[Webhook] Renewal email error: " . $mail_err->getMessage());
                    }

                    // Audit log
                    try {
                        $pdo->prepare("
                            INSERT INTO audit_logs
                                (tenant_id,actor_user_id,actor_username,actor_role,action,entity_type,entity_id,message,ip_address,created_at)
                            VALUES (?,NULL,'system','system','SUBSCRIPTION_RENEWED','subscription',?,?,?,NOW())
                        ")->execute([
                            $tenant_id, $tenant_id,
                            "Subscription auto-renewed via PayMongo. Plan: {$plan}, Cycle: {$billing_cycle}, New expiry: {$new_sub_end}, Session: {$session_id}.",
                            '::webhook',
                        ]);
                    } catch (Throwable $e) {}

                    // ── Notify Super Admins ───────────────────
                    try {
                        if ($t_info && function_exists('sendSuperAdminPaymentNotif')) {
                            foreach ($sa_admins as $sa) {
                                sendSuperAdminPaymentNotif(
                                    $sa['email'], $sa['fullname'],
                                    $t_info['business_name'], $t_info['owner_name'],
                                    $plan, 'renewal', $amount_paid,
                                    $billing_cycle, 'PayMongo — ' . $payment_method, $new_sub_end, $tenant_id
                                );
                            }
                        }
                    } catch (Throwable $sa_err) {
                        error_log("[Webhook] SA notif error (renewal): " . $sa_err->getMessage());
                    }

                    error_log("[Webhook] RENEWAL AUTO-APPROVED: tenant_id={$tenant_id}, plan={$plan}, cycle={$billing_cycle}, new_end={$new_sub_end}, method={$payment_method}");
                } catch (Throwable $renew_err) {
                    error_log("[Webhook] Renewal error for tenant_id={$tenant_id}: " . $renew_err->getMessage());
                }

            } elseif ($payment_type === 'reactivation') {
                // ── D. REACTIVATION PAYMENT — AUTO APPROVE ────
                // Tenant was inactive/deactivated and paid to reactivate.
                // Auto-activate immediately — no SA approval needed.
                try {
                    $billing_months_map = ['monthly' => 1, 'quarterly' => 3, 'annually' => 12];
                    $months = $billing_months_map[$billing_cycle] ?? 1;
                    $new_sub_end = date('Y-m-d', strtotime("+{$months} months"));

                    // Reactivate tenant
                    $pdo->prepare("
                        UPDATE tenants SET
                            status              = 'active',
                            plan                = ?,
                            subscription_start  = CURDATE(),
                            subscription_end    = ?,
                            subscription_status = 'active',
                            renewal_reminded_7d = 0,
                            renewal_reminded_3d = 0,
                            renewal_reminded_1d = 0
                        WHERE id = ?
                    ")->execute([$plan, $new_sub_end, $tenant_id]);

                    // Unsuspend all users
                    $pdo->prepare("
                        UPDATE users SET is_suspended=0, suspended_at=NULL, suspension_reason=NULL
                        WHERE tenant_id=? AND is_suspended=1
                    ")->execute([$tenant_id]);

                    // Record as approved renewal in subscription_renewals
                    $pdo->prepare("
                        INSERT INTO subscription_renewals
                            (tenant_id, plan, billing_cycle, payment_method, payment_reference,
                             amount, status, requested_at, reviewed_at, new_subscription_end, notes)
                        VALUES (?, ?, ?, ?, ?, ?, 'approved', NOW(), NOW(), ?, 'Auto-approved: Tenant reactivation via PayMongo.')
                    ")->execute([
                        $tenant_id, $plan, $billing_cycle,
                        'PayMongo — ' . $payment_method,
                        $session_id, $amount_paid, $new_sub_end,
                    ]);

                    // Record in subscription_notifications
                    try {
                        $pdo->prepare("INSERT INTO subscription_notifications (tenant_id, notif_type) VALUES (?, 'renewed')")->execute([$tenant_id]);
                    } catch (PDOException $e) {}

                    // Send renewal confirmation email to tenant
                    try {
                        require_once __DIR__ . '/mailer.php';
                        $t_info = $pdo->prepare("SELECT business_name, owner_name, email, slug FROM tenants WHERE id=? LIMIT 1");
                        $t_info->execute([$tenant_id]);
                        $t_info = $t_info->fetch();
                        if ($t_info && function_exists('sendSubscriptionRenewed')) {
                            sendSubscriptionRenewed(
                                $t_info['email'], $t_info['owner_name'],
                                $t_info['business_name'], $plan,
                                $new_sub_end, $t_info['slug']
                            );
                        }
                    } catch (Throwable $mail_err) {
                        error_log("[Webhook] Reactivation email error: " . $mail_err->getMessage());
                    }

                    // Audit log
                    try {
                        $pdo->prepare("
                            INSERT INTO audit_logs
                                (tenant_id,actor_user_id,actor_username,actor_role,action,entity_type,entity_id,message,ip_address,created_at)
                            VALUES (?,NULL,'system','system','TENANT_REACTIVATED','subscription',?,?,?,NOW())
                        ")->execute([
                            $tenant_id, $tenant_id,
                            "Tenant auto-reactivated via PayMongo payment. Plan: {$plan}, Cycle: {$billing_cycle}, New expiry: {$new_sub_end}, Session: {$session_id}.",
                            '::webhook',
                        ]);
                    } catch (Throwable $e) {}

                    // ── Notify Super Admins ───────────────────
                    try {
                        if ($t_info && function_exists('sendSuperAdminPaymentNotif')) {
                            foreach ($sa_admins as $sa) {
                                sendSuperAdminPaymentNotif(
                                    $sa['email'], $sa['fullname'],
                                    $t_info['business_name'], $t_info['owner_name'],
                                    $plan, 'reactivation', $amount_paid,
                                    $billing_cycle, 'PayMongo — ' . $payment_method, $new_sub_end, $tenant_id
                                );
                            }
                        }
                    } catch (Throwable $sa_err) {
                        error_log("[Webhook] SA notif error (reactivation): " . $sa_err->getMessage());
                    }

                    error_log("[Webhook] REACTIVATION AUTO-APPROVED: tenant_id={$tenant_id}, plan={$plan}, cycle={$billing_cycle}, new_end={$new_sub_end}, method={$payment_method}");
                } catch (Throwable $react_err) {
                    error_log("[Webhook] Reactivation error for tenant_id={$tenant_id}: " . $react_err->getMessage());
                }

            } else {
                // ── D. INITIAL SIGNUP PAYMENT — AUTO APPROVE ─
                // Payment confirmed by PayMongo — auto-activate tenant (and user if exists).
                // NOTE: For SA-sent payment links, user_id may be 0 (no user account yet).
                try {
                    // Fetch tenant info
                    $t_row = $pdo->prepare("SELECT * FROM tenants WHERE id = ? LIMIT 1");
                    $t_row->execute([$tenant_id]);
                    $t_row = $t_row->fetch();

                    if ($t_row) {
                        // Generate slug if missing
                        $slug = $t_row['slug'] ?? '';
                        if (empty($slug)) {
                            $base_slug = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $t_row['business_name']));
                            $slug = $base_slug;
                            $ctr  = 1;
                            while (true) {
                                $chk = $pdo->prepare("SELECT id FROM tenants WHERE slug = ? AND id != ?");
                                $chk->execute([$slug, $tenant_id]);
                                if (!$chk->fetch()) break;
                                $slug = $base_slug . $ctr++;
                            }
                        }

                        // Activate tenant
                        $pdo->prepare("
                            UPDATE tenants SET
                                status              = 'active',
                                slug                = ?,
                                subscription_start  = CURDATE(),
                                subscription_end    = DATE_ADD(CURDATE(), INTERVAL 1 MONTH),
                                subscription_status = 'active',
                                renewal_reminded_7d = 0,
                                renewal_reminded_3d = 0,
                                renewal_reminded_1d = 0
                            WHERE id = ?
                        ")->execute([$slug, $tenant_id]);

                        // Activate user only if one exists (SA-added tenants may not have a user yet)
                        if ($user_id) {
                            $pdo->prepare("UPDATE users SET status = 'approved', approved_at = NOW() WHERE id = ?")->execute([$user_id]);
                        }

                        // Record initial subscription payment
                        $plan_amounts = ['Starter' => 0, 'Pro' => 999, 'Enterprise' => 2499];
                        $sub_amount   = $plan_amounts[$plan] ?? 0;
                        if ($sub_amount > 0) {
                            try {
                                $pdo->prepare("
                                    INSERT INTO subscription_renewals
                                        (tenant_id, plan, billing_cycle, payment_method, payment_reference, amount, status, requested_at, reviewed_at, new_subscription_end)
                                    VALUES (?, ?, 'monthly', ?, ?, ?, 'approved', NOW(), NOW(), DATE_ADD(CURDATE(), INTERVAL 1 MONTH))
                                ")->execute([$tenant_id, $plan, 'PayMongo — ' . $payment_method, $session_id, $sub_amount]);
                            } catch (PDOException $e) {}
                        }

                        // Send login credentials email
                        if (!empty($t_row['email']) && !empty($slug)) {
                            try {
                                require_once __DIR__ . '/mailer.php';
                                // Check for SA-invited tenant (any token — renew if expired)
                                $inv = $pdo->prepare("SELECT id, token, status FROM tenant_invitations WHERE tenant_id = ? ORDER BY created_at DESC LIMIT 1");
                                $inv->execute([$tenant_id]);
                                $inv_row = $inv->fetch();
                                if ($inv_row && $inv_row['status'] !== 'used') {
                                    // Renew token so the setup link is always fresh
                                    $new_tok = bin2hex(random_bytes(32));
                                    $new_exp = date('Y-m-d H:i:s', strtotime('+24 hours'));
                                    $pdo->prepare("UPDATE tenant_invitations SET token=?, expires_at=?, status='pending', used_at=NULL WHERE id=?")
                                        ->execute([$new_tok, $new_exp, $inv_row['id']]);
                                    sendTenantInvitation($t_row['email'], $t_row['owner_name'], $t_row['business_name'], $new_tok, $slug);
                                } else {
                                    sendTenantApproved($t_row['email'], $t_row['owner_name'], $t_row['business_name'], $slug);
                                }
                            } catch (Throwable $mail_err) {
                                error_log("[Webhook] Auto-approve email error: " . $mail_err->getMessage());
                            }
                        }

                        // ── Notify Super Admins ───────────────
                        try {
                            if (function_exists('sendSuperAdminPaymentNotif')) {
                                $signup_amount = $plan_amounts[$plan] ?? 0;
                                foreach ($sa_admins as $sa) {
                                    sendSuperAdminPaymentNotif(
                                        $sa['email'], $sa['fullname'],
                                        $t_row['business_name'], $t_row['owner_name'],
                                        $plan, 'signup', (float)$signup_amount,
                                        'monthly', 'PayMongo — ' . $payment_method,
                                        date('Y-m-d', strtotime('+1 month')), $tenant_id
                                    );
                                }
                            }
                        } catch (Throwable $sa_err) {
                            error_log("[Webhook] SA notif error (signup): " . $sa_err->getMessage());
                        }

                        error_log("[Webhook] SIGNUP AUTO-APPROVED: tenant_id={$tenant_id}, user_id={$user_id}, plan={$plan}, method={$payment_method}, slug={$slug}");
                    } else {
                        error_log("[Webhook] Tenant not found for auto-approve: tenant_id={$tenant_id}");
                    }
                } catch (Throwable $approve_err) {
                    error_log("[Webhook] Auto-approve error for tenant_id={$tenant_id}: " . $approve_err->getMessage());
                }
            }
        }

        // ── Log to payment_logs (non-fatal, all types) ────────
        try {
            // Attempt with method column (present in some deployments)
            $pdo->prepare("
                INSERT INTO payment_logs
                    (tenant_id, user_id, session_id, plan, amount, method, status, created_at)
                VALUES (?, ?, ?, ?, ?, ?, 'paid', NOW())
            ")->execute([$tenant_id, $user_id, $session_id, $plan, $amount_paid, 'PayMongo — ' . $payment_method]);
        } catch (PDOException $e) {
            // Fallback: insert without method column
            try {
                $pdo->prepare("
                    INSERT INTO payment_logs
                        (tenant_id, user_id, session_id, plan, amount, status, created_at)
                    VALUES (?, ?, ?, ?, ?, 'paid', NOW())
                ")->execute([$tenant_id, $user_id, $session_id, $plan, $amount_paid]);
            } catch (PDOException $e2) {
                error_log('[Webhook] payment_logs insert skipped: ' . $e2->getMessage());
            }
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