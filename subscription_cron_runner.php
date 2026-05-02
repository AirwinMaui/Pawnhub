<?php
/**
 * subscription_cron_runner.php
 * ─────────────────────────────────────────────────────────────
 * Silent inline cron — included by tenant_login.php on every
 * admin login (max once per 12 hours via temp flag file).
 *
 * This is the FALLBACK trigger when no external cron is set up.
 * All errors are silently swallowed — login is NEVER blocked.
 *
 * REQUIRES: $pdo already initialized (from db.php)
 * REQUIRES: mailer.php functions available
 * ─────────────────────────────────────────────────────────────
 */

if (!isset($pdo)) return;

try { require_once __DIR__ . '/mailer.php'; } catch (Throwable $e) { return; }

// ── Step 1: Expiry reminder emails (7d / 3d / 1d) — ALL plans ─
$_reminder_windows = [
    7 => 'renewal_reminded_7d',
    3 => 'renewal_reminded_3d',
    1 => 'renewal_reminded_1d',
];

foreach ($_reminder_windows as $_days => $_flag) {
    try {
        $_stmt = $pdo->prepare("
            SELECT id, business_name, email, owner_name, plan, subscription_end, slug
            FROM tenants
            WHERE status = 'active'
              AND subscription_end IS NOT NULL
              AND DATE(subscription_end) >= CURDATE()
              AND DATE(subscription_end) <= DATE_ADD(CURDATE(), INTERVAL ? DAY)
              AND `{$_flag}` = 0
        ");
        $_stmt->execute([$_days]);
        $_tenants = $_stmt->fetchAll();
    } catch (Throwable $e) { continue; }

    foreach ($_tenants as $_t) {
        try {
            $_days_left = max(0, (int)ceil((strtotime($_t['subscription_end']) - time()) / 86400));
            $_is_starter = ($_t['plan'] === 'Starter');

            if ($_is_starter) {
                // Free trial reminder — use trial-specific function or fallback
                $_sent = function_exists('sendFreeTrialExpiring')
                    ? sendFreeTrialExpiring($_t['email'], $_t['owner_name'], $_t['business_name'], $_t['subscription_end'], $_days_left, $_t['slug'])
                    : (function_exists('sendSubscriptionExpiring')
                        ? sendSubscriptionExpiring($_t['email'], $_t['owner_name'], $_t['business_name'], 'Free Trial', $_t['subscription_end'], $_days_left, $_t['slug'])
                        : false);
            } else {
                $_sent = function_exists('sendSubscriptionExpiring')
                    ? sendSubscriptionExpiring($_t['email'], $_t['owner_name'], $_t['business_name'], $_t['plan'], $_t['subscription_end'], $_days_left, $_t['slug'])
                    : false;
            }

            if ($_sent) {
                $_status = $_is_starter ? 'trial_expiring_soon' : 'expiring_soon';
                $pdo->prepare("UPDATE tenants SET `{$_flag}` = 1, subscription_status = '{$_status}' WHERE id = ?")
                    ->execute([$_t['id']]);
                try {
                    $_notif_map = [7 => 'expiring_7d', 3 => 'expiring_3d', 1 => 'expiring_1d'];
                    $pdo->prepare("INSERT INTO subscription_notifications (tenant_id, notif_type) VALUES (?, ?)")
                        ->execute([$_t['id'], $_notif_map[$_days] ?? 'expiring_7d']);
                } catch (Throwable $e) {}
            }
        } catch (Throwable $e) {}
    }
}

// ── Step 1b: Mark expired Starter free trials ─────────────────
try {
    $_starter_exp = $pdo->prepare("
        SELECT id, business_name, email, owner_name, plan, subscription_end, slug
        FROM tenants
        WHERE status = 'active'
          AND plan = 'Starter'
          AND subscription_end IS NOT NULL
          AND DATE(subscription_end) < CURDATE()
          AND (subscription_status IS NULL OR subscription_status NOT IN ('expired','trial_expired','cancelled'))
    ");
    $_starter_exp->execute();
    $_starter_expired = $_starter_exp->fetchAll();
} catch (Throwable $e) { $_starter_expired = []; }

foreach ($_starter_expired as $_t) {
    try {
        $pdo->prepare("UPDATE tenants SET subscription_status='trial_expired' WHERE id=?")->execute([$_t['id']]);
        $_already_trial = false;
        try {
            $_chk_t = $pdo->prepare("SELECT 1 FROM subscription_notifications WHERE tenant_id=? AND notif_type='trial_expired' AND DATE(sent_at)=CURDATE() LIMIT 1");
            $_chk_t->execute([$_t['id']]);
            $_already_trial = (bool)$_chk_t->fetchColumn();
        } catch (Throwable $e) {}
        if (!$_already_trial) {
            $_sent_t = false;
            if (function_exists('sendFreeTrialExpired')) {
                $_sent_t = sendFreeTrialExpired($_t['email'], $_t['owner_name'], $_t['business_name'], $_t['slug']);
            } elseif (function_exists('sendSubscriptionExpired')) {
                $_sent_t = sendSubscriptionExpired($_t['email'], $_t['owner_name'], $_t['business_name'], 'Free Trial', $_t['slug']);
            }
            if ($_sent_t) {
                try { $pdo->prepare("INSERT INTO subscription_notifications (tenant_id, notif_type) VALUES (?, 'trial_expired')")->execute([$_t['id']]); } catch (Throwable $e) {}
            }
        }
        try {
            $pdo->prepare("INSERT INTO audit_logs (tenant_id,actor_user_id,actor_username,actor_role,action,entity_type,entity_id,message,ip_address,created_at) VALUES (?,NULL,'system','system','FREE_TRIAL_EXPIRED','tenant',?,?,'::1',NOW())")
                ->execute([$_t['id'], $_t['id'], "Free trial expired for {$_t['business_name']}."]);
        } catch (Throwable $e) {}
    } catch (Throwable $e) {}
}


// ── Step 2: Mark expired subscriptions ───────────────────────
try {
    $_exp_stmt = $pdo->prepare("
        SELECT id, business_name, email, owner_name, plan, subscription_end, slug
        FROM tenants
        WHERE status = 'active'
          AND plan != 'Starter'
          AND subscription_end IS NOT NULL
          AND DATE(subscription_end) < CURDATE()
          AND (subscription_status IS NULL OR subscription_status NOT IN ('expired','cancelled'))
    ");
    $_exp_stmt->execute();
    $_expired = $_exp_stmt->fetchAll();
} catch (Throwable $e) { $_expired = []; }

foreach ($_expired as $_t) {
    try {
        $pdo->prepare("UPDATE tenants SET subscription_status='expired' WHERE id=?")->execute([$_t['id']]);

        // Send expired email — check subscription_notifications first, fallback to flag
        $_already = false;
        try {
            $_chk = $pdo->prepare("SELECT 1 FROM subscription_notifications WHERE tenant_id=? AND notif_type='expired' AND DATE(sent_at)=CURDATE() LIMIT 1");
            $_chk->execute([$_t['id']]);
            $_already = (bool)$_chk->fetchColumn();
        } catch (Throwable $e) {}

        if (!$_already && function_exists('sendSubscriptionExpired')) {
            $_sent = sendSubscriptionExpired($_t['email'], $_t['owner_name'], $_t['business_name'], $_t['plan'], $_t['slug']);
            if ($_sent) {
                try {
                    $pdo->prepare("INSERT INTO subscription_notifications (tenant_id, notif_type) VALUES (?, 'expired')")->execute([$_t['id']]);
                } catch (Throwable $e) {}
            }
        }

        try {
            $pdo->prepare("INSERT INTO audit_logs (tenant_id,actor_user_id,actor_username,actor_role,action,entity_type,entity_id,message,ip_address,created_at) VALUES (?,NULL,'system','system','SUBSCRIPTION_EXPIRED','tenant',?,?,'::1',NOW())")
                ->execute([$_t['id'], $_t['id'], "Subscription expired for {$_t['business_name']}."]);
        } catch (Throwable $e) {}
    } catch (Throwable $e) {}
}

// ── Step 3: Auto-deactivate tenants expired 7+ days ──────────
try {
    $_deact_stmt = $pdo->prepare("
        SELECT id, business_name, email, owner_name, plan, subscription_end, slug
        FROM tenants
        WHERE status = 'active'
          AND plan != 'Starter'
          AND subscription_end IS NOT NULL
          AND subscription_status = 'expired'
          AND DATEDIFF(CURDATE(), DATE(subscription_end)) >= 7
    ");
    $_deact_stmt->execute();
    $_deact = $_deact_stmt->fetchAll();
} catch (Throwable $e) { $_deact = []; }

foreach ($_deact as $_t) {
    try {
        $pdo->prepare("UPDATE tenants SET status='inactive' WHERE id=?")->execute([$_t['id']]);
        $pdo->prepare("
            UPDATE users SET is_suspended=1, suspended_at=NOW(),
            suspension_reason='Subscription expired — auto-deactivated after 7-day grace period.'
            WHERE tenant_id=? AND role != 'admin'
        ")->execute([$_t['id']]);

        $_already2 = false;
        try {
            $_chk2 = $pdo->prepare("SELECT 1 FROM subscription_notifications WHERE tenant_id=? AND notif_type='auto_deactivated' AND DATE(sent_at)=CURDATE() LIMIT 1");
            $_chk2->execute([$_t['id']]);
            $_already2 = (bool)$_chk2->fetchColumn();
        } catch (Throwable $e) {}

        if (!$_already2 && function_exists('sendSubscriptionAutoDeactivated')) {
            $_sent2 = sendSubscriptionAutoDeactivated($_t['email'], $_t['owner_name'], $_t['business_name'], $_t['plan'], $_t['slug'], (int)$_t['id']);
            if ($_sent2) {
                try {
                    $pdo->prepare("INSERT INTO subscription_notifications (tenant_id, notif_type) VALUES (?, 'auto_deactivated')")->execute([$_t['id']]);
                } catch (Throwable $e) {}
            }
        }

        try {
            $pdo->prepare("INSERT INTO audit_logs (tenant_id,actor_user_id,actor_username,actor_role,action,entity_type,entity_id,message,ip_address,created_at) VALUES (?,NULL,'system','system','SUBSCRIPTION_AUTO_DEACTIVATED','tenant',?,?,'::1',NOW())")
                ->execute([$_t['id'], $_t['id'], "Tenant \"{$_t['business_name']}\" auto-deactivated after 7-day grace period (expired: {$_t['subscription_end']})."]);
        } catch (Throwable $e) {}
    } catch (Throwable $e) {}
}

// Clean up loop variables so they don't leak into tenant_login scope
unset($_reminder_windows, $_days, $_flag, $_stmt, $_tenants, $_t, $_days_left, $_sent,
      $_is_starter, $_status, $_notif_map,
      $_starter_exp, $_starter_expired, $_already_trial, $_chk_t, $_sent_t,
      $_exp_stmt, $_expired, $_already, $_chk, $_deact_stmt, $_deact, $_already2, $_chk2, $_sent2);