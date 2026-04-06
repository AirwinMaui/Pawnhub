<?php
/**
 * subscription_cron.php — PawnHub Subscription Expiry Checker
 *
 * HOW TO RUN:
 *   Option A — Manual (run in browser or CLI):
 *     https://yoursite.com/subscription_cron.php?cron_secret=CHANGE_THIS_SECRET
 *
 *   Option B — Azure Scheduler / Linux cron:
 *     0 8 * * * php /home/site/wwwroot/subscription_cron.php --cron
 *
 *   Option C — Call it from superadmin.php manually (button)
 *
 * This script:
 *   1. Finds tenants expiring in 7, 3, 1 days — sends reminder email once each
 *   2. Finds tenants already expired — marks them & sends expired notice
 *   3. Never sends duplicate notifications (checks subscription_notifications)
 */

// ── Security ──────────────────────────────────────────────────────────────
define('CRON_SECRET', 'CHANGE_THIS_TO_A_RANDOM_SECRET_STRING');

$is_cli  = (php_sapi_name() === 'cli');
$is_web  = !$is_cli;

if ($is_web) {
    $given_secret = $_GET['cron_secret'] ?? '';
    if ($given_secret !== CRON_SECRET) {
        http_response_code(403);
        exit('Forbidden.');
    }
}

// ── Includes ──────────────────────────────────────────────────────────────
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/mailer.php';

$log = [];
$now = new DateTime();

// ── 1. Find tenants expiring in 7, 3, 1 days ─────────────────────────────
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
          AND t.subscription_status IN ('active','expiring_soon')
          AND DATE(t.subscription_end) = ?
          AND NOT EXISTS (
              SELECT 1 FROM subscription_notifications sn
              WHERE sn.tenant_id = t.id
                AND sn.notif_type = ?
                AND DATE(sn.sent_at) = CURDATE()
          )
    ");
    $stmt->execute([$target_date, $notif_type]);
    $tenants = $stmt->fetchAll();

    foreach ($tenants as $tenant) {
        $sent = sendSubscriptionExpiring(
            $tenant['email'],
            $tenant['owner_name'],
            $tenant['business_name'],
            $tenant['plan'],
            $tenant['subscription_end'],
            $days,
            $tenant['slug']
        );

        if ($sent) {
            // Log the notification
            $pdo->prepare("INSERT INTO subscription_notifications (tenant_id, notif_type) VALUES (?, ?)")
                ->execute([$tenant['id'], $notif_type]);

            // Mark subscription_status as expiring_soon
            $pdo->prepare("UPDATE tenants SET subscription_status='expiring_soon' WHERE id=?")
                ->execute([$tenant['id']]);

            $log[] = "[OK] Sent {$days}d warning to {$tenant['business_name']} ({$tenant['email']})";
        } else {
            $log[] = "[FAIL] Could not send {$days}d warning to {$tenant['email']}";
        }
    }
}

// ── 2. Mark expired tenants ───────────────────────────────────────────────
$expired_stmt = $pdo->prepare("
    SELECT t.id, t.business_name, t.email, t.owner_name, t.plan, t.subscription_end, t.slug
    FROM tenants t
    WHERE t.status = 'active'
      AND t.subscription_end IS NOT NULL
      AND t.subscription_end < CURDATE()
      AND t.subscription_status != 'expired'
");
$expired_stmt->execute();
$expired_tenants = $expired_stmt->fetchAll();

foreach ($expired_tenants as $tenant) {
    // Mark as expired
    $pdo->prepare("UPDATE tenants SET subscription_status='expired' WHERE id=?")
        ->execute([$tenant['id']]);

    // Check if expired email was already sent today
    $already_notified = $pdo->prepare("
        SELECT id FROM subscription_notifications
        WHERE tenant_id = ? AND notif_type = 'expired' AND DATE(sent_at) = CURDATE()
        LIMIT 1
    ");
    $already_notified->execute([$tenant['id']]);

    if (!$already_notified->fetch()) {
        $sent = sendSubscriptionExpired(
            $tenant['email'],
            $tenant['owner_name'],
            $tenant['business_name'],
            $tenant['plan'],
            $tenant['slug']
        );

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
            INSERT INTO audit_logs (tenant_id, actor_user_id, actor_username, actor_role, action, entity_type, entity_id, message, ip_address, created_at)
            VALUES (?, NULL, 'system', 'system', 'SUBSCRIPTION_EXPIRED', 'tenant', ?, ?, '::1', NOW())
        ")->execute([$tenant['id'], $tenant['id'], "Subscription expired for {$tenant['business_name']}."]);
    } catch (Throwable $e) {}
}

// ── 3. Output ─────────────────────────────────────────────────────────────
if ($is_cli) {
    echo implode("\n", $log) . "\n";
    echo count($log) === 0 ? "Nothing to do today.\n" : '';
} else {
    header('Content-Type: text/plain');
    echo "PawnHub Subscription Cron — " . date('Y-m-d H:i:s') . "\n";
    echo "==============================\n";
    echo implode("\n", $log) . "\n";
    echo count($log) === 0 ? "Nothing to process.\n" : '';
}