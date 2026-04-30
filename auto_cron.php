<?php
/**
 * auto_cron.php — PawnHub Daily Auto Cron
 * ─────────────────────────────────────────────────────────────
 * This file runs the full subscription lifecycle logic daily:
 *   ✅ Send 7d, 3d, 1d expiry warning emails
 *   ✅ Mark expired subscriptions
 *   ✅ Auto-deactivate tenants expired 7+ days
 *
 * ── HOW TO SET UP ON AZURE APP SERVICE ──────────────────────
 * Option A — Azure WebJob (recommended):
 *   1. Create file: /site/wwwroot/App_Data/jobs/triggered/daily_cron/run.php
 *      Contents: <?php require '/home/site/wwwroot/auto_cron.php'; ?>
 *   2. Create schedule file: settings.job
 *      Contents: {"schedule": "0 0 8 * * *"}   ← runs 8AM daily (server time)
 *
 * Option B — cron-job.org (free, easiest for shared hosting):
 *   1. Go to https://cron-job.org and create free account
 *   2. Add new cronjob:
 *      URL: https://yourdomain.com/auto_cron.php?secret=pawnhub_cron_2026_secret
 *      Schedule: Every day at 8:00 AM
 *
 * Option C — UptimeRobot (also keeps site alive):
 *   URL: https://yourdomain.com/auto_cron.php?secret=pawnhub_cron_2026_secret
 *   Interval: Every 24 hours
 * ─────────────────────────────────────────────────────────────
 */

// ⚠️  CHANGE THIS — must match CRON_SECRET in subscription_cron.php
define('CRON_SECRET', 'pawnhub_cron_2026_secret');

// ── Security: block unauthorized HTTP access ──────────────────
$is_cli = (php_sapi_name() === 'cli');
if (!$is_cli) {
    $given = $_GET['secret'] ?? $_SERVER['HTTP_X_CRON_SECRET'] ?? '';
    if (!hash_equals(CRON_SECRET, $given)) {
        http_response_code(403);
        exit('Forbidden.');
    }
}

// ── Prevent overlapping runs via lock file ─────────────────────
// Lock expires after 1 hour regardless — this guards against stale locks
// caused by crashes or PHP fatal errors (register_shutdown_function below
// ensures the lock is always cleaned up even on fatal error).
$lock_file = sys_get_temp_dir() . '/pawnhub_cron.lock';
$lock_age  = file_exists($lock_file) ? (time() - filemtime($lock_file)) : 9999;

if ($lock_age < 3600) {
    http_response_code(200);
    exit('Already ran recently. Skipped.');
}

file_put_contents($lock_file, date('Y-m-d H:i:s'));

// Always release lock — even on fatal error / uncaught exception
register_shutdown_function(function () use ($lock_file) {
    @unlink($lock_file);
});

// ── Includes ──────────────────────────────────────────────────
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/mailer.php';

$log = [];
$now = new DateTime();

// ── Helper: deduplicate check ─────────────────────────────────
// Supports both subscription_notifications table (preferred) AND the
// simple boolean flags on the tenants row (renewal_reminded_7d etc.)
// so this works regardless of which approach is in the DB schema.
function already_notified(PDO $pdo, int $tenant_id, string $notif_type): bool
{
    // Try subscription_notifications table first
    try {
        $chk = $pdo->prepare("
            SELECT 1 FROM subscription_notifications
            WHERE tenant_id = ? AND notif_type = ? AND DATE(sent_at) = CURDATE()
            LIMIT 1
        ");
        $chk->execute([$tenant_id, $notif_type]);
        return (bool)$chk->fetchColumn();
    } catch (Throwable $e) {
        // Table may not exist — fall back to tenant flags
        $flag_map = [
            'expiring_7d'    => 'renewal_reminded_7d',
            'expiring_3d'    => 'renewal_reminded_3d',
            'expiring_1d'    => 'renewal_reminded_1d',
        ];
        if (isset($flag_map[$notif_type])) {
            try {
                $col = $flag_map[$notif_type];
                $f   = $pdo->prepare("SELECT `{$col}` FROM tenants WHERE id = ? LIMIT 1");
                $f->execute([$tenant_id]);
                return (bool)$f->fetchColumn();
            } catch (Throwable $e2) {}
        }
        return false;
    }
}

function mark_notified(PDO $pdo, int $tenant_id, string $notif_type): void
{
    // Try subscription_notifications table first
    try {
        $pdo->prepare("INSERT INTO subscription_notifications (tenant_id, notif_type) VALUES (?, ?)")
            ->execute([$tenant_id, $notif_type]);
        return;
    } catch (Throwable $e) {}

    // Fallback: update boolean flag on tenants row
    $flag_map = [
        'expiring_7d' => 'renewal_reminded_7d',
        'expiring_3d' => 'renewal_reminded_3d',
        'expiring_1d' => 'renewal_reminded_1d',
    ];
    if (isset($flag_map[$notif_type])) {
        try {
            $col = $flag_map[$notif_type];
            $pdo->prepare("UPDATE tenants SET `{$col}` = 1 WHERE id = ?")
                ->execute([$tenant_id]);
        } catch (Throwable $e) {}
    }
}

// ─────────────────────────────────────────────────────────────
// STEP 1 — Expiry reminder emails: 7 days, 3 days, 1 day
// ─────────────────────────────────────────────────────────────
$reminder_windows = [
    7 => 'expiring_7d',
    3 => 'expiring_3d',
    1 => 'expiring_1d',
];

foreach ($reminder_windows as $days => $notif_type) {
    $target_date = (clone $now)->modify("+{$days} days")->format('Y-m-d');

    try {
        $stmt = $pdo->prepare("
            SELECT t.id, t.business_name, t.email, t.owner_name, t.plan, t.subscription_end, t.slug
            FROM tenants t
            WHERE t.status = 'active'
              AND t.plan != 'Starter'
              AND t.subscription_end IS NOT NULL
              AND DATE(t.subscription_end) = ?
        ");
        $stmt->execute([$target_date]);
        $tenants_to_notify = $stmt->fetchAll();
    } catch (Throwable $e) {
        $log[] = "[ERROR] Could not query tenants for {$days}d reminder: " . $e->getMessage();
        continue;
    }

    foreach ($tenants_to_notify as $tenant) {
        if (already_notified($pdo, (int)$tenant['id'], $notif_type)) {
            $log[] = "[SKIP] {$days}d reminder already sent today to {$tenant['business_name']}";
            continue;
        }

        $sent = function_exists('sendSubscriptionExpiring')
            ? sendSubscriptionExpiring(
                $tenant['email'], $tenant['owner_name'], $tenant['business_name'],
                $tenant['plan'], $tenant['subscription_end'], $days, $tenant['slug']
              )
            : false;

        if ($sent) {
            mark_notified($pdo, (int)$tenant['id'], $notif_type);
            // Also update subscription_status so dashboard banner shows
            try {
                $pdo->prepare("UPDATE tenants SET subscription_status='expiring_soon' WHERE id=?")
                    ->execute([$tenant['id']]);
            } catch (Throwable $e) {}
            $log[] = "[OK] Sent {$days}d warning to {$tenant['business_name']} ({$tenant['email']})";
        } else {
            $log[] = "[FAIL] Could not send {$days}d warning to {$tenant['email']}";
        }
    }
}

// ─────────────────────────────────────────────────────────────
// STEP 2 — Mark & notify expired tenants (grace period starts)
// ─────────────────────────────────────────────────────────────
try {
    $expired_stmt = $pdo->prepare("
        SELECT t.id, t.business_name, t.email, t.owner_name, t.plan, t.subscription_end, t.slug
        FROM tenants t
        WHERE t.status = 'active'
          AND t.plan != 'Starter'
          AND t.subscription_end IS NOT NULL
          AND DATE(t.subscription_end) < CURDATE()
          AND (t.subscription_status IS NULL OR t.subscription_status NOT IN ('expired', 'cancelled'))
    ");
    $expired_stmt->execute();
    $expired_tenants = $expired_stmt->fetchAll();
} catch (Throwable $e) {
    $expired_tenants = [];
    $log[] = "[ERROR] Could not query expired tenants: " . $e->getMessage();
}

foreach ($expired_tenants as $tenant) {
    try {
        $pdo->prepare("UPDATE tenants SET subscription_status='expired' WHERE id=?")
            ->execute([$tenant['id']]);
    } catch (Throwable $e) {}

    if (!already_notified($pdo, (int)$tenant['id'], 'expired')) {
        $sent = function_exists('sendSubscriptionExpired')
            ? sendSubscriptionExpired(
                $tenant['email'], $tenant['owner_name'], $tenant['business_name'],
                $tenant['plan'], $tenant['slug']
              )
            : false;

        if ($sent) {
            mark_notified($pdo, (int)$tenant['id'], 'expired');
            $log[] = "[OK] Sent EXPIRED notice to {$tenant['business_name']} ({$tenant['email']})";
        } else {
            $log[] = "[FAIL] Could not send expired notice to {$tenant['email']}";
        }
    }

    try {
        $pdo->prepare("
            INSERT INTO audit_logs (tenant_id,actor_user_id,actor_username,actor_role,action,entity_type,entity_id,message,ip_address,created_at)
            VALUES (?,NULL,'system','system','SUBSCRIPTION_EXPIRED','tenant',?,?,'::1',NOW())
        ")->execute([$tenant['id'], $tenant['id'], "Subscription expired for {$tenant['business_name']}."]);
    } catch (Throwable $e) {}

    $log[] = "[MARKED] {$tenant['business_name']} subscription marked as expired.";
}

// ─────────────────────────────────────────────────────────────
// STEP 3 — Auto-deactivate tenants expired for 7+ days
// ─────────────────────────────────────────────────────────────
try {
    $deact_stmt = $pdo->prepare("
        SELECT t.id, t.business_name, t.email, t.owner_name, t.plan, t.subscription_end, t.slug
        FROM tenants t
        WHERE t.status = 'active'
          AND t.plan != 'Starter'
          AND t.subscription_end IS NOT NULL
          AND t.subscription_status = 'expired'
          AND DATEDIFF(CURDATE(), DATE(t.subscription_end)) >= 7
    ");
    $deact_stmt->execute();
    $deact_tenants = $deact_stmt->fetchAll();
} catch (Throwable $e) {
    $deact_tenants = [];
    $log[] = "[ERROR] Could not query tenants for auto-deactivation: " . $e->getMessage();
}

foreach ($deact_tenants as $tenant) {
    // Deactivate tenant
    try {
        $pdo->prepare("UPDATE tenants SET status='inactive' WHERE id=?")
            ->execute([$tenant['id']]);
    } catch (Throwable $e) {
        $log[] = "[ERROR] Could not deactivate {$tenant['business_name']}: " . $e->getMessage();
        continue;
    }

    // Suspend all non-admin users
    try {
        $pdo->prepare("
            UPDATE users SET
                is_suspended       = 1,
                suspended_at       = NOW(),
                suspension_reason  = 'Subscription expired — auto-deactivated after 7-day grace period.'
            WHERE tenant_id = ? AND role != 'admin'
        ")->execute([$tenant['id']]);
    } catch (Throwable $e) {}

    // Send deactivation email (once)
    if (!already_notified($pdo, (int)$tenant['id'], 'auto_deactivated')) {
        $sent = function_exists('sendSubscriptionAutoDeactivated')
            ? sendSubscriptionAutoDeactivated(
                $tenant['email'], $tenant['owner_name'], $tenant['business_name'],
                $tenant['plan'], $tenant['slug'], (int)$tenant['id']
              )
            : false;

        if ($sent) {
            mark_notified($pdo, (int)$tenant['id'], 'auto_deactivated');
            $log[] = "[OK] Sent AUTO-DEACTIVATED notice to {$tenant['business_name']} ({$tenant['email']})";
        } else {
            $log[] = "[FAIL] Could not send auto-deactivated notice to {$tenant['email']}";
        }
    }

    try {
        $pdo->prepare("
            INSERT INTO audit_logs (tenant_id,actor_user_id,actor_username,actor_role,action,entity_type,entity_id,message,ip_address,created_at)
            VALUES (?,NULL,'system','system','SUBSCRIPTION_AUTO_DEACTIVATED','tenant',?,?,'::1',NOW())
        ")->execute([
            $tenant['id'], $tenant['id'],
            "Tenant \"{$tenant['business_name']}\" auto-deactivated after 7-day grace period (expired: {$tenant['subscription_end']}).",
        ]);
    } catch (Throwable $e) {}

    $log[] = "[AUTO-DEACTIVATED] {$tenant['business_name']} — expired {$tenant['subscription_end']}";
}

// ── Respond ───────────────────────────────────────────────────
// (lock is released by register_shutdown_function above)
http_response_code(200);
header('Content-Type: text/plain; charset=utf-8');
echo "PawnHub Auto Cron — " . date('Y-m-d H:i:s') . "\n";
echo "Results: " . count($log) . " action(s)\n";
echo implode("\n", $log) . "\n";
if (empty($log)) echo "Nothing to process.\n";