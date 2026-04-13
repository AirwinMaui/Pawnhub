<?php
/**
 * subscription_cron.php — PawnHub Subscription Expiry Checker
 *
 * HOW TO RUN:
 *   Option A — From browser (with secret):
 *     https://yoursite.com/subscription_cron.php?cron_secret=YOUR_SECRET_HERE
 *
 *   Option B — Run Expiry Check button in superadmin.php:
 *     Automatically called — the button POSTs to superadmin.php
 *     which redirects here with the correct secret.
 *
 *   Option C — Azure App Service / Linux cron (CLI):
 *     0 8 * * * php /home/site/wwwroot/subscription_cron.php --cron
 *
 * ⚠️  IMPORTANT: Change CRON_SECRET below — same value in superadmin.php
 */

// ── CHANGE THIS — same value must be in superadmin.php run_sub_cron action ──
define('CRON_SECRET', 'pawnhub_cron_2026_secret');

// ── Security ──────────────────────────────────────────────────
$is_cli = (php_sapi_name() === 'cli');
$is_web = !$is_cli;

if ($is_web) {
    $given = $_GET['cron_secret'] ?? $_POST['cron_secret'] ?? '';
    if ($given !== CRON_SECRET) {
        http_response_code(403);
        exit('Forbidden. Wrong or missing cron_secret.');
    }
}

// ── Includes ──────────────────────────────────────────────────
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/mailer.php';

$log = [];
$now = new DateTime();

// ── 1. Expiry reminder emails: 7 days, 3 days, 1 day ─────────
$reminder_windows = [
    7 => 'expiring_7d',
    3 => 'expiring_3d',
    1 => 'expiring_1d',
];

foreach ($reminder_windows as $days => $notif_type) {
    $target_date = (clone $now)->modify("+{$days} days")->format('Y-m-d');

    $stmt = $pdo->prepare("
        SELECT t.id, t.business_name, t.email, t.owner_name, t.plan, t.subscription_end, t.slug
        FROM tenants t
        WHERE t.status = 'active'
          AND t.subscription_end IS NOT NULL
          AND DATE(t.subscription_end) = ?
          AND NOT EXISTS (
              SELECT 1 FROM subscription_notifications sn
              WHERE sn.tenant_id = t.id
                AND sn.notif_type = ?
                AND DATE(sn.sent_at) = CURDATE()
          )
    ");
    $stmt->execute([$target_date, $notif_type]);
    $tenants_to_notify = $stmt->fetchAll();

    foreach ($tenants_to_notify as $tenant) {
        if (function_exists('sendSubscriptionExpiring')) {
            $sent = sendSubscriptionExpiring(
                $tenant['email'],
                $tenant['owner_name'],
                $tenant['business_name'],
                $tenant['plan'],
                $tenant['subscription_end'],
                $days,
                $tenant['slug']
            );
        } else {
            $sent = false;
            $log[] = "[WARN] sendSubscriptionExpiring() not found — check mailer.php";
        }

        if ($sent) {
            $pdo->prepare("INSERT INTO subscription_notifications (tenant_id, notif_type) VALUES (?, ?)")
                ->execute([$tenant['id'], $notif_type]);
            $pdo->prepare("UPDATE tenants SET subscription_status='expiring_soon' WHERE id=?")
                ->execute([$tenant['id']]);
            $log[] = "[OK] Sent {$days}d warning to {$tenant['business_name']} ({$tenant['email']})";
        } else {
            $log[] = "[FAIL] Could not send {$days}d warning to {$tenant['email']}";
        }
    }
}

// ── 2. Mark & notify expired tenants ─────────────────────────
$expired_stmt = $pdo->prepare("
    SELECT t.id, t.business_name, t.email, t.owner_name, t.plan, t.subscription_end, t.slug
    FROM tenants t
    WHERE t.status = 'active'
      AND t.subscription_end IS NOT NULL
      AND DATE(t.subscription_end) < CURDATE()
      AND t.subscription_status != 'expired'
");
$expired_stmt->execute();
$expired_tenants = $expired_stmt->fetchAll();

foreach ($expired_tenants as $tenant) {
    // Mark as expired
    $pdo->prepare("UPDATE tenants SET subscription_status='expired' WHERE id=?")
        ->execute([$tenant['id']]);

    // Only send expired email once per day
    $already = $pdo->prepare("
        SELECT id FROM subscription_notifications
        WHERE tenant_id = ? AND notif_type = 'expired' AND DATE(sent_at) = CURDATE()
        LIMIT 1
    ");
    $already->execute([$tenant['id']]);

    if (!$already->fetch()) {
        if (function_exists('sendSubscriptionExpired')) {
            $sent = sendSubscriptionExpired(
                $tenant['email'],
                $tenant['owner_name'],
                $tenant['business_name'],
                $tenant['plan'],
                $tenant['slug']
            );
        } else {
            $sent = false;
            $log[] = "[WARN] sendSubscriptionExpired() not found — check mailer.php";
        }

        if ($sent) {
            $pdo->prepare("INSERT INTO subscription_notifications (tenant_id, notif_type) VALUES (?, 'expired')")
                ->execute([$tenant['id']]);
            $log[] = "[OK] Sent EXPIRED notice to {$tenant['business_name']} ({$tenant['email']})";
        } else {
            $log[] = "[FAIL] Could not send expired notice to {$tenant['email']}";
        }
    }

    // Audit log
    try {
        $pdo->prepare("
            INSERT INTO audit_logs (tenant_id,actor_user_id,actor_username,actor_role,action,entity_type,entity_id,message,ip_address,created_at)
            VALUES (?, NULL, 'system', 'system', 'SUBSCRIPTION_EXPIRED', 'tenant', ?, ?, '::1', NOW())
        ")->execute([$tenant['id'], $tenant['id'], "Subscription expired for {$tenant['business_name']}."]);
    } catch (Throwable $e) {}

    $log[] = "[MARKED] {$tenant['business_name']} subscription marked as expired.";
}

// ── 3. Auto-deactivate tenants expired for 7+ days ───────────
$auto_deactivate_stmt = $pdo->prepare("
    SELECT t.id, t.business_name, t.email, t.owner_name, t.plan, t.subscription_end, t.slug
    FROM tenants t
    WHERE t.status = 'active'
      AND t.subscription_end IS NOT NULL
      AND t.subscription_status = 'expired'
      AND DATEDIFF(CURDATE(), DATE(t.subscription_end)) >= 7
");
$auto_deactivate_stmt->execute();
$auto_deactivate_tenants = $auto_deactivate_stmt->fetchAll();

foreach ($auto_deactivate_tenants as $tenant) {
    // Deactivate tenant
    $pdo->prepare("UPDATE tenants SET status='inactive' WHERE id=?")
        ->execute([$tenant['id']]);

    // Suspend all non-admin users
    $pdo->prepare("
        UPDATE users SET is_suspended=1, suspended_at=NOW(),
        suspension_reason='Subscription expired and auto-deactivated after 7 days.'
        WHERE tenant_id=? AND role != 'admin'
    ")->execute([$tenant['id']]);

    // Send auto-deactivated email once
    $already_notif = $pdo->prepare("
        SELECT id FROM subscription_notifications
        WHERE tenant_id = ? AND notif_type = 'auto_deactivated' AND DATE(sent_at) = CURDATE()
        LIMIT 1
    ");
    $already_notif->execute([$tenant['id']]);

    if (!$already_notif->fetch()) {
        if (function_exists('sendSubscriptionAutoDeactivated')) {
            $sent = sendSubscriptionAutoDeactivated(
                $tenant['email'],
                $tenant['owner_name'],
                $tenant['business_name'],
                $tenant['plan'],
                $tenant['slug']
            );
        } else {
            $sent = false;
            $log[] = "[WARN] sendSubscriptionAutoDeactivated() not found — check mailer.php";
        }

        if ($sent) {
            $pdo->prepare("INSERT INTO subscription_notifications (tenant_id, notif_type) VALUES (?, 'auto_deactivated')")
                ->execute([$tenant['id']]);
            $log[] = "[OK] Sent AUTO-DEACTIVATED notice to {$tenant['business_name']} ({$tenant['email']})";
        } else {
            $log[] = "[FAIL] Could not send auto-deactivated notice to {$tenant['email']}";
        }
    }

    // Audit log
    try {
        $pdo->prepare("
            INSERT INTO audit_logs (tenant_id,actor_user_id,actor_username,actor_role,action,entity_type,entity_id,message,ip_address,created_at)
            VALUES (?, NULL, 'system', 'system', 'SUBSCRIPTION_AUTO_DEACTIVATED', 'tenant', ?, ?, '::1', NOW())
        ")->execute([
            $tenant['id'],
            $tenant['id'],
            "Tenant \"{$tenant['business_name']}\" auto-deactivated after 7 days of expired subscription (expired: {$tenant['subscription_end']})."
        ]);
    } catch (Throwable $e) {}

    $log[] = "[AUTO-DEACTIVATED] {$tenant['business_name']} deactivated after 7 days expired.";
}

// ── 4. Output ─────────────────────────────────────────────────
if ($is_cli) {
    echo "PawnHub Subscription Cron — " . date('Y-m-d H:i:s') . "\n";
    echo "==============================\n";
    echo implode("\n", $log) . "\n";
    if (empty($log)) echo "Nothing to process.\n";
} else {
    header('Content-Type: text/plain; charset=utf-8');
    echo "PawnHub Subscription Cron — " . date('Y-m-d H:i:s') . "\n";
    echo "==============================\n";
    if (empty($log)) {
        echo "Nothing to process.\n";
    } else {
        echo implode("\n", $log) . "\n";
    }
}