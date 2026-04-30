<?php
/**
 * auto_cron.php — PawnHub Daily Auto Cron
 * ─────────────────────────────────────────────────────────────
 * This file is called automatically every day by Azure App Service
 * OR by any HTTP ping (UptimeRobot, cron-job.org, etc.)
 *
 * It runs the full subscription_cron.php logic:
 *   ✅ Send 7d, 3d, 1d expiry warning emails
 *   ✅ Mark expired subscriptions
 *   ✅ Auto-deactivate tenants expired 7+ days
 *
 * ── HOW TO SET UP ON AZURE APP SERVICE ──────────────────────
 * Option A — Azure WebJob (recommended):
 *   1. Create file: /site/wwwroot/App_Data/jobs/triggered/daily_cron/run.php
 *      Contents: <?php require '/home/site/wwwroot/auto_cron.php'; ?>
 *   2. Create schedule file: settings.job
 *      Contents: {"schedule": "0 0 8 * * *"}   ← runs 8AM daily
 *
 * Option B — cron-job.org (free, easiest):
 *   1. Go to https://cron-job.org and create free account
 *   2. Add new cronjob:
 *      URL: https://yourdomain.com/auto_cron.php?secret=pawnhub_cron_2026_secret
 *      Schedule: Every day at 8:00 AM
 *
 * Option C — UptimeRobot (also pings to keep site alive):
 *   URL: https://yourdomain.com/auto_cron.php?secret=pawnhub_cron_2026_secret
 *   Interval: Every 24 hours
 * ─────────────────────────────────────────────────────────────
 */

define('CRON_SECRET', 'pawnhub_cron_2026_secret'); // same as subscription_cron.php

// ── Security: block unauthorized access ──────────────────────
$is_cli = (php_sapi_name() === 'cli');

if (!$is_cli) {
    $given = $_GET['secret'] ?? $_SERVER['HTTP_X_CRON_SECRET'] ?? '';
    if ($given !== CRON_SECRET) {
        http_response_code(403);
        exit('Forbidden.');
    }
}

// ── Prevent overlapping runs (lock file) ─────────────────────
$lock_file = sys_get_temp_dir() . '/pawnhub_cron.lock';
$lock_age   = file_exists($lock_file) ? (time() - filemtime($lock_file)) : 999;

if ($lock_age < 3600) {
    // Already ran within the last hour — skip
    http_response_code(200);
    exit('Already ran recently. Skipped.');
}

file_put_contents($lock_file, date('Y-m-d H:i:s'));

// ── Run the cron ──────────────────────────────────────────────
$_SERVER['CRON_AUTO'] = true; // flag so subscription_cron.php knows it's auto

ob_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/mailer.php';

// ── Inline cron logic (same as subscription_cron.php) ────────
$log = [];
$now = new DateTime();

// 1. Expiry reminder emails: 7 days, 3 days, 1 day
$reminder_windows = [7 => 'expiring_7d', 3 => 'expiring_3d', 1 => 'expiring_1d'];

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
              WHERE sn.tenant_id = t.id AND sn.notif_type = ?
                AND DATE(sn.sent_at) = CURDATE()
          )
    ");
    $stmt->execute([$target_date, $notif_type]);

    foreach ($stmt->fetchAll() as $tenant) {
        $sent = function_exists('sendSubscriptionExpiring')
            ? sendSubscriptionExpiring($tenant['email'], $tenant['owner_name'], $tenant['business_name'], $tenant['plan'], $tenant['subscription_end'], $days, $tenant['slug'])
            : false;

        if ($sent) {
            $pdo->prepare("INSERT INTO subscription_notifications (tenant_id, notif_type) VALUES (?, ?)")->execute([$tenant['id'], $notif_type]);
            $pdo->prepare("UPDATE tenants SET subscription_status='expiring_soon' WHERE id=?")->execute([$tenant['id']]);
            $log[] = "[OK] Sent {$days}d warning to {$tenant['business_name']}";
        } else {
            $log[] = "[FAIL] {$days}d warning to {$tenant['email']}";
        }
    }
}

// 2. Mark expired tenants
$expired_stmt = $pdo->prepare("
    SELECT t.id, t.business_name, t.email, t.owner_name, t.plan, t.subscription_end, t.slug
    FROM tenants t
    WHERE t.status = 'active'
      AND t.subscription_end IS NOT NULL
      AND DATE(t.subscription_end) < CURDATE()
      AND t.subscription_status != 'expired'
");
$expired_stmt->execute();

foreach ($expired_stmt->fetchAll() as $tenant) {
    $pdo->prepare("UPDATE tenants SET subscription_status='expired' WHERE id=?")->execute([$tenant['id']]);

    $already = $pdo->prepare("SELECT id FROM subscription_notifications WHERE tenant_id=? AND notif_type='expired' AND DATE(sent_at)=CURDATE() LIMIT 1");
    $already->execute([$tenant['id']]);

    if (!$already->fetch()) {
        $sent = function_exists('sendSubscriptionExpired')
            ? sendSubscriptionExpired($tenant['email'], $tenant['owner_name'], $tenant['business_name'], $tenant['plan'], $tenant['slug'])
            : false;
        if ($sent) {
            $pdo->prepare("INSERT INTO subscription_notifications (tenant_id, notif_type) VALUES (?, 'expired')")->execute([$tenant['id']]);
            $log[] = "[OK] Sent EXPIRED notice to {$tenant['business_name']}";
        }
    }

    try {
        $pdo->prepare("INSERT INTO audit_logs (tenant_id,actor_user_id,actor_username,actor_role,action,entity_type,entity_id,message,ip_address,created_at) VALUES (?,NULL,'system','system','SUBSCRIPTION_EXPIRED','tenant',?,?,'::1',NOW())")
            ->execute([$tenant['id'], $tenant['id'], "Subscription expired for {$tenant['business_name']}."]);
    } catch (Throwable $e) {}

    $log[] = "[EXPIRED] {$tenant['business_name']}";
}

// 3. Auto-deactivate tenants expired 7+ days
$deact_stmt = $pdo->prepare("
    SELECT t.id, t.business_name, t.email, t.owner_name, t.plan, t.subscription_end, t.slug
    FROM tenants t
    WHERE t.status = 'active'
      AND t.subscription_end IS NOT NULL
      AND t.subscription_status = 'expired'
      AND DATEDIFF(CURDATE(), DATE(t.subscription_end)) >= 7
");
$deact_stmt->execute();

foreach ($deact_stmt->fetchAll() as $tenant) {
    // Deactivate tenant
    $pdo->prepare("UPDATE tenants SET status='inactive' WHERE id=?")->execute([$tenant['id']]);

    // Suspend all users
    $pdo->prepare("
        UPDATE users SET is_suspended=1, suspended_at=NOW(),
        suspension_reason='Subscription expired — auto-deactivated after 7-day grace period.'
        WHERE tenant_id=? AND role != 'admin'
    ")->execute([$tenant['id']]);

    $already_notif = $pdo->prepare("SELECT id FROM subscription_notifications WHERE tenant_id=? AND notif_type='auto_deactivated' AND DATE(sent_at)=CURDATE() LIMIT 1");
    $already_notif->execute([$tenant['id']]);

    if (!$already_notif->fetch()) {
        $sent = function_exists('sendSubscriptionAutoDeactivated')
            ? sendSubscriptionAutoDeactivated($tenant['email'], $tenant['owner_name'], $tenant['business_name'], $tenant['plan'], $tenant['slug'])
            : false;
        if ($sent) {
            $pdo->prepare("INSERT INTO subscription_notifications (tenant_id, notif_type) VALUES (?, 'auto_deactivated')")->execute([$tenant['id']]);
            $log[] = "[OK] Sent AUTO-DEACTIVATED notice to {$tenant['business_name']}";
        }
    }

    try {
        $pdo->prepare("INSERT INTO audit_logs (tenant_id,actor_user_id,actor_username,actor_role,action,entity_type,entity_id,message,ip_address,created_at) VALUES (?,NULL,'system','system','SUBSCRIPTION_AUTO_DEACTIVATED','tenant',?,?,'::1',NOW())")
            ->execute([$tenant['id'], $tenant['id'], "Tenant \"{$tenant['business_name']}\" auto-deactivated after 7-day grace period (expired: {$tenant['subscription_end']})."]);
    } catch (Throwable $e) {}

    $log[] = "[AUTO-DEACTIVATED] {$tenant['business_name']} — expired {$tenant['subscription_end']}";
}

ob_end_clean();

// ── Release lock ──────────────────────────────────────────────
@unlink($lock_file);

// ── Respond ───────────────────────────────────────────────────
http_response_code(200);
header('Content-Type: text/plain');
echo "PawnHub Auto Cron — " . date('Y-m-d H:i:s') . "\n";
echo "Results: " . count($log) . " action(s)\n";
echo implode("\n", $log) . "\n";
if (empty($log)) echo "Nothing to process.\n";