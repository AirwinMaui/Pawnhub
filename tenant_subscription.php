<?php
/**
 * tenant_subscription.php — Tenant Subscription Page
 * Tenants can view their plan and renew via PayMongo OR manually.
 */

require_once __DIR__ . '/session_helper.php';
pawnhub_session_start('admin');
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/theme_helper.php';

// ── Auth check ────────────────────────────────────────────────
if (empty($_SESSION['user'])) { header('Location: home.php'); exit; }
$u   = $_SESSION['user'];
$tid = (int)$u['tenant_id'];
if ($u['role'] !== 'admin') { header('Location: home.php'); exit; }

$success_msg = $error_msg = '';

// ── URL feedback ──────────────────────────────────────────────
if (($_GET['error'] ?? '') === 'paymongo_fail') {
    $error_msg = '⚠️ PayMongo payment failed or was cancelled. You can try again or use a manual payment method.';
} elseif (($_GET['error'] ?? '') === 'already_pending') {
    $error_msg = 'You already have a pending renewal request. Please wait for admin approval.';
} elseif (($_GET['cancelled'] ?? '') === '1') {
    $error_msg = 'Payment was cancelled. No charge was made.';
}

// ── Load tenant ───────────────────────────────────────────────
$tenant_stmt = $pdo->prepare("SELECT * FROM tenants WHERE id = ? LIMIT 1");
$tenant_stmt->execute([$tid]);
$tenant = $tenant_stmt->fetch();
if (!$tenant) { die('Tenant not found.'); }

$theme     = getTenantTheme($pdo, $tid);
$slug      = $tenant['slug'] ?? '';
$plan      = $tenant['plan'] ?? 'Starter';
$sub_end   = $tenant['subscription_end'] ?? null;
$sub_stat  = $tenant['subscription_status'] ?? 'active';
$sub_start = $tenant['subscription_start'] ?? null;
$days_left = $sub_end ? (int)ceil((strtotime($sub_end) - time()) / 86400) : null;

$logo_url  = $theme['logo_url'] ?? null;
$logo_text = $theme['logo_text'] ?: ($theme['system_name'] ?? 'PawnHub');

// ── Load pending renewal ──────────────────────────────────────
$pending_renewal_stmt = $pdo->prepare("
    SELECT * FROM subscription_renewals
    WHERE tenant_id = ? AND status = 'pending'
    ORDER BY requested_at DESC LIMIT 1
");
$pending_renewal_stmt->execute([$tid]);
$pending_renewal = $pending_renewal_stmt->fetch();

// ── Load renewal history ──────────────────────────────────────
$history_stmt = $pdo->prepare("
    SELECT sr.*, u.fullname AS reviewed_by_name
    FROM subscription_renewals sr
    LEFT JOIN users u ON sr.reviewed_by = u.id
    WHERE sr.tenant_id = ?
    ORDER BY sr.requested_at DESC
    LIMIT 15
");
$history_stmt->execute([$tid]);
$renewal_history = $history_stmt->fetchAll();

// ── POST: Submit MANUAL renewal request ──────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'request_renewal_manual') {
    if ($pending_renewal) {
        $error_msg = 'You already have a pending renewal request. Please wait for admin approval.';
    } else {
        $billing_cycle  = in_array($_POST['billing_cycle'] ?? '', ['monthly','quarterly','annually'])
                          ? $_POST['billing_cycle'] : 'monthly';
        $payment_method = trim($_POST['payment_method']    ?? '');
        $payment_ref    = trim($_POST['payment_reference'] ?? '');
        $notes          = trim($_POST['notes']             ?? '');

        $billing_amounts = [
            'Starter'    => ['monthly' => 0,    'quarterly' => 0,    'annually' => 0],
            'Pro'        => ['monthly' => 999,  'quarterly' => 2697, 'annually' => 9588],
            'Enterprise' => ['monthly' => 2499, 'quarterly' => 6747, 'annually' => 23988],
        ];
        $amount = $billing_amounts[$plan][$billing_cycle] ?? 0;

        if (!$payment_method) {
            $error_msg = 'Please select a payment method.';
        } else {
            try {
                $pdo->prepare("
                    INSERT INTO subscription_renewals
                        (tenant_id, plan, billing_cycle, amount, payment_method, payment_reference, notes, status, requested_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', NOW())
                ")->execute([$tid, $plan, $billing_cycle, $amount, $payment_method, $payment_ref, $notes]);

                if (function_exists('sendRenewalRequestReceived')) {
                    sendRenewalRequestReceived($tenant['email'], $tenant['owner_name'], $tenant['business_name'], $plan, $slug);
                }

                try {
                    $pdo->prepare("
                        INSERT INTO audit_logs (tenant_id,actor_user_id,actor_username,actor_role,action,entity_type,entity_id,message,ip_address,created_at)
                        VALUES (?,?,?,?,'RENEWAL_REQUEST_MANUAL','subscription',?,?,?,NOW())
                    ")->execute([$tid, $u['id'], $u['username'], $u['role'], $tid,
                        "Manual renewal request submitted for {$tenant['business_name']} ({$plan}, {$billing_cycle}, method: {$payment_method}).",
                        $_SERVER['REMOTE_ADDR'] ?? '::1']);
                } catch (Throwable $e) {}

                $success_msg = '✅ Renewal request submitted! Admin will review within 24 hours.';

                // Reload pending
                $pending_renewal_stmt->execute([$tid]);
                $pending_renewal = $pending_renewal_stmt->fetch();

            } catch (PDOException $e) {
                $error_msg = 'Database error: ' . $e->getMessage();
            }
        }
    }
}

// ── POST: Submit UPGRADE request ─────────────────────────────
// Logic: tenant wants to move from current plan → a higher plan.
// If subscription still active (≤7 days left): scheduled upgrade — takes effect after current sub ends.
//   No proration applied for scheduled upgrades since they're paying for the new period.
// If already expired: immediate upgrade — full price for new plan, no proration.
// If >7 days left: not allowed to schedule yet (too early).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'request_upgrade') {
    if ($pending_renewal) {
        $error_msg = 'You already have a pending renewal/upgrade request. Please wait for admin approval.';
    } else {
        $upgrade_to     = trim($_POST['upgrade_to']     ?? '');
        $billing_cycle  = in_array($_POST['billing_cycle'] ?? '', ['monthly','quarterly','annually'])
                          ? $_POST['billing_cycle'] : 'monthly';
        $payment_method = trim($_POST['payment_method']    ?? '');
        $payment_ref    = trim($_POST['payment_reference'] ?? '');
        $notes          = trim($_POST['notes']             ?? '');

        $plan_hierarchy = ['Starter' => 0, 'Pro' => 1, 'Enterprise' => 2];
        $billing_amounts = [
            'Starter'    => ['monthly' => 0,    'quarterly' => 0,    'annually' => 0],
            'Pro'        => ['monthly' => 999,  'quarterly' => 2697, 'annually' => 9588],
            'Enterprise' => ['monthly' => 2499, 'quarterly' => 6747, 'annually' => 23988],
        ];

        $valid_targets = ['Pro', 'Enterprise'];
        $is_valid_upgrade = isset($plan_hierarchy[$upgrade_to])
            && isset($plan_hierarchy[$plan])
            && $plan_hierarchy[$upgrade_to] > $plan_hierarchy[$plan];

        // Determine scheduling context
        $upg_sub_end_ts      = $sub_end ? strtotime($sub_end) : 0;
        $upg_sub_active      = $upg_sub_end_ts > time();
        $upg_days_left       = $upg_sub_active ? (int) ceil(($upg_sub_end_ts - time()) / 86400) : 0;
        $upg_in_window       = $upg_sub_active && $upg_days_left <= 7;  // 1–7 days left
        $upg_too_early       = $upg_sub_active && $upg_days_left > 7;   // >7 days left
        // Scheduled: submitted while still active in the 7-day window
        $is_scheduled_upg    = $upg_in_window;

        if (!in_array($upgrade_to, $valid_targets) || !$is_valid_upgrade) {
            $error_msg = 'Invalid upgrade target. You can only upgrade to a higher plan.';
        } elseif ($upg_too_early) {
            $expiry_fmt_upg = date('F d, Y', $upg_sub_end_ts);
            $error_msg = "⚠️ You can schedule an upgrade within 7 days of your subscription expiry. "
                       . "Your <strong>{$plan}</strong> plan expires on <strong>{$expiry_fmt_upg}</strong> ({$upg_days_left} days remaining). "
                       . "You may submit an upgrade request when you have 7 or fewer days left.";
        } elseif (!$payment_method) {
            $error_msg = 'Please select a payment method.';
        } else {
            // ── Proration: only apply if upgrading immediately (not scheduled) ──
            $proration_credit = 0;
            $proration_note   = '';
            if (!$is_scheduled_upg && $sub_end && $days_left > 0 && $is_paid_plan) {
                $current_monthly  = $billing_amounts[$plan]['monthly'] ?? 0;
                $daily_rate       = $current_monthly / 30;
                $proration_credit = round($daily_rate * $days_left, 2);
                $proration_note   = "Proration credit of ₱{$proration_credit} applied "
                    . "({$days_left} unused days × ₱" . number_format($daily_rate, 2) . "/day on {$plan} plan).";
            }

            $new_price   = $billing_amounts[$upgrade_to][$billing_cycle] ?? 0;
            $amount_due  = max(0, $new_price - $proration_credit);

            $scheduled_note_upg = $is_scheduled_upg
                ? " [SCHEDULED — effective after current subscription ends on " . date('M d, Y', $upg_sub_end_ts) . "]"
                : '';

            $upgrade_notes = "PLAN UPGRADE: {$plan} → {$upgrade_to} ({$billing_cycle}).{$scheduled_note_upg}"
                . ($proration_note ? " {$proration_note}" : '')
                . ($notes ? " Notes: {$notes}" : '');

            try {
                $pdo->prepare("
                    INSERT INTO subscription_renewals
                        (tenant_id, plan, billing_cycle, amount, payment_method, payment_reference,
                         notes, status, requested_at, is_upgrade, upgrade_from, upgrade_to, proration_credit)
                    VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', NOW(),
                        1, ?, ?, ?)
                ")->execute([
                    $tid, $upgrade_to, $billing_cycle, $amount_due,
                    $payment_method, $payment_ref, $upgrade_notes,
                    $plan, $upgrade_to, $proration_credit
                ]);

                try {
                    $pdo->prepare("
                        INSERT INTO audit_logs
                            (tenant_id,actor_user_id,actor_username,actor_role,action,entity_type,entity_id,message,ip_address,created_at)
                        VALUES (?,?,?,?,'PLAN_UPGRADE_REQUEST','subscription',?,?,?,NOW())
                    ")->execute([
                        $tid, $u['id'], $u['username'], $u['role'], $tid,
                        "Upgrade request submitted: {$plan} → {$upgrade_to} ({$billing_cycle}). Amount due: ₱{$amount_due}.{$scheduled_note_upg} {$proration_note}",
                        $_SERVER['REMOTE_ADDR'] ?? '::1'
                    ]);
                } catch (Throwable $e) {}

                if ($is_scheduled_upg) {
                    $success_msg = "📅 Scheduled upgrade request submitted! Your plan will switch to <strong>{$upgrade_to}</strong> after your current <strong>{$plan}</strong> subscription ends on <strong>" . date('F d, Y', $upg_sub_end_ts) . "</strong>. Admin will review and confirm.";
                } else {
                    $success_msg = "✅ Upgrade request submitted! Admin will review and activate your <strong>{$upgrade_to}</strong> plan within 24 hours.";
                }

                // Reload pending renewal
                $pending_renewal_stmt->execute([$tid]);
                $pending_renewal = $pending_renewal_stmt->fetch();

            } catch (PDOException $e) {
                // Fallback: column may not exist yet — retry without new columns
                try {
                    $pdo->prepare("
                        INSERT INTO subscription_renewals
                            (tenant_id, plan, billing_cycle, amount, payment_method, payment_reference, notes, status, requested_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', NOW())
                    ")->execute([
                        $tid, $upgrade_to, $billing_cycle, $amount_due,
                        $payment_method, $payment_ref, $upgrade_notes
                    ]);
                    if ($is_scheduled_upg) {
                        $success_msg = "📅 Scheduled upgrade request submitted! Your plan will switch to <strong>{$upgrade_to}</strong> after your current subscription ends. Admin will review and confirm.";
                    } else {
                        $success_msg = "✅ Upgrade request submitted! Admin will review and activate your <strong>{$upgrade_to}</strong> plan within 24 hours.";
                    }
                    $pending_renewal_stmt->execute([$tid]);
                    $pending_renewal = $pending_renewal_stmt->fetch();
                } catch (PDOException $e2) {
                    $error_msg = 'Database error: ' . $e2->getMessage();
                }
            }
        }
    }
}

// ── POST: Submit DOWNGRADE request ───────────────────────────
// Rule: Tenant can downgrade within 7 days before expiry (scheduled — takes effect
//       after current subscription ends) OR immediately after expiry (pay now).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'request_downgrade') {
    if ($pending_renewal) {
        $error_msg = 'You already have a pending renewal/downgrade request. Please wait for admin approval.';
    } else {
        $downgrade_to   = trim($_POST['downgrade_to']    ?? '');
        $billing_cycle  = in_array($_POST['billing_cycle'] ?? '', ['monthly','quarterly','annually'])
                          ? $_POST['billing_cycle'] : 'monthly';
        $payment_method = trim($_POST['payment_method']   ?? '');
        $payment_ref    = trim($_POST['payment_reference'] ?? '');
        $notes          = trim($_POST['notes']             ?? '');

        $plan_hierarchy_dg = ['Starter' => 0, 'Pro' => 1, 'Enterprise' => 2];
        $current_rank_dg   = $plan_hierarchy_dg[$plan] ?? 0;
        $target_rank_dg    = $plan_hierarchy_dg[$downgrade_to] ?? 99;

        $is_valid_downgrade = isset($plan_hierarchy_dg[$downgrade_to])
            && $target_rank_dg < $current_rank_dg;

        // Determine scheduling context
        $sub_end_ts       = $sub_end ? strtotime($sub_end) : 0;
        $sub_still_active = $sub_end_ts > time();
        $days_left_dg     = $sub_still_active ? (int) ceil(($sub_end_ts - time()) / 86400) : 0;
        // Allow scheduled downgrade only within the 7-day window before expiry
        $in_schedule_window = $sub_still_active && $days_left_dg <= 7;
        // If more than 7 days left, still block downgrade (too early)
        $too_early = $sub_still_active && $days_left_dg > 7;
        // is_scheduled = request was submitted while subscription still active (takes effect after expiry)
        $is_scheduled_dg = $in_schedule_window;

        // Normalize: free plan needs no payment method
        if ($downgrade_to === 'Starter' && !$payment_method) {
            $payment_method = 'N/A (Free Plan)';
        }

        if (!$is_valid_downgrade) {
            $error_msg = 'Invalid downgrade target. You can only downgrade to a lower plan.';
        } elseif ($too_early) {
            $expiry_fmt_dg = date('F d, Y', $sub_end_ts);
            $error_msg = "⚠️ You can only request a downgrade within 7 days of your subscription expiry. "
                       . "Your <strong>{$plan}</strong> plan expires on <strong>{$expiry_fmt_dg}</strong> ({$days_left_dg} days remaining). "
                       . "You may submit a downgrade request when you have 7 or fewer days left.";
        } elseif (!$sub_still_active && !$payment_method && $downgrade_to !== 'Starter') {
            $error_msg = 'Please select a payment method.';
        } elseif ($in_schedule_window && !$payment_method && $downgrade_to !== 'Starter') {
            $error_msg = 'Please select a payment method for your scheduled downgrade.';
        } else {
            $billing_amounts_dg = [
                'Starter'    => ['monthly' => 0,    'quarterly' => 0,    'annually' => 0],
                'Pro'        => ['monthly' => 999,   'quarterly' => 2697, 'annually' => 9588],
                'Enterprise' => ['monthly' => 2499,  'quarterly' => 6747, 'annually' => 23988],
            ];
            $amount_dg = $billing_amounts_dg[$downgrade_to][$billing_cycle] ?? 0;

            $scheduled_note = $is_scheduled_dg
                ? " [SCHEDULED — effective after current subscription ends on " . date('M d, Y', $sub_end_ts) . "]"
                : '';
            $dg_notes = "PLAN DOWNGRADE: {$plan} → {$downgrade_to} ({$billing_cycle}).{$scheduled_note}"
                      . ($notes ? " Notes: {$notes}" : '');

            try {
                $pdo->prepare("
                    INSERT INTO subscription_renewals
                        (tenant_id, plan, billing_cycle, amount, payment_method, payment_reference,
                         notes, status, requested_at, is_upgrade, upgrade_from, upgrade_to, proration_credit)
                    VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', NOW(), 0, ?, ?, 0)
                ")->execute([
                    $tid, $downgrade_to, $billing_cycle, $amount_dg,
                    $payment_method, $payment_ref, $dg_notes,
                    $plan, $downgrade_to
                ]);

                try {
                    $pdo->prepare("
                        INSERT INTO audit_logs
                            (tenant_id,actor_user_id,actor_username,actor_role,action,entity_type,entity_id,message,ip_address,created_at)
                        VALUES (?,?,?,?,'PLAN_DOWNGRADE_REQUEST','subscription',?,?,?,NOW())
                    ")->execute([
                        $tid, $u['id'], $u['username'], $u['role'], $tid,
                        "Downgrade request submitted: {$plan} → {$downgrade_to} ({$billing_cycle}).{$scheduled_note}",
                        $_SERVER['REMOTE_ADDR'] ?? '::1'
                    ]);
                } catch (Throwable $e) {}

                if ($is_scheduled_dg) {
                    $success_msg = "📅 Scheduled downgrade request submitted! Your plan will switch to <strong>{$downgrade_to}</strong> after your current <strong>{$plan}</strong> subscription ends on <strong>" . date('F d, Y', $sub_end_ts) . "</strong>. Admin will review and confirm.";
                } else {
                    $success_msg = "✅ Downgrade request submitted! Admin will review and switch you to the <strong>{$downgrade_to}</strong> plan within 24 hours.";
                }

                $pending_renewal_stmt->execute([$tid]);
                $pending_renewal = $pending_renewal_stmt->fetch();

            } catch (PDOException $e) {
                // Fallback without new columns
                try {
                    $pdo->prepare("
                        INSERT INTO subscription_renewals
                            (tenant_id, plan, billing_cycle, amount, payment_method, payment_reference, notes, status, requested_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', NOW())
                    ")->execute([
                        $tid, $downgrade_to, $billing_cycle, $amount_dg,
                        $payment_method, $payment_ref, $dg_notes
                    ]);
                    if ($is_scheduled_dg) {
                        $success_msg = "📅 Scheduled downgrade request submitted! Your plan will switch to <strong>{$downgrade_to}</strong> after your current <strong>{$plan}</strong> subscription ends. Admin will review and confirm.";
                    } else {
                        $success_msg = "✅ Downgrade request submitted! Admin will review and switch you to the <strong>{$downgrade_to}</strong> plan within 24 hours.";
                    }
                    $pending_renewal_stmt->execute([$tid]);
                    $pending_renewal = $pending_renewal_stmt->fetch();
                } catch (PDOException $e2) {
                    $error_msg = 'Database error: ' . $e2->getMessage();
                }
            }
        }
    }
}


// ── Status badge ──────────────────────────────────────────────
$status_map = [
    'active'        => ['Active',        '#15803d', '#f0fdf4', '#bbf7d0'],
    'expiring_soon' => ['Expiring Soon', '#d97706', '#fffbeb', '#fde68a'],
    'expired'       => ['Expired',       '#dc2626', '#fef2f2', '#fecaca'],
    'cancelled'     => ['Cancelled',     '#64748b', '#f8fafc', '#e2e8f0'],
];
$sb = $status_map[$sub_stat] ?? ['Unknown', '#64748b', '#f8fafc', '#e2e8f0'];

// ── Plan amounts for JS ───────────────────────────────────────
$plan_prices_js = [
    'Starter'    => ['monthly' => 0,    'quarterly' => 0,    'annually' => 0],
    'Pro'        => ['monthly' => 999,  'quarterly' => 2697, 'annually' => 9588],
    'Enterprise' => ['monthly' => 2499, 'quarterly' => 6747, 'annually' => 23988],
];
$is_paid_plan = in_array($plan, ['Pro', 'Enterprise']);

// ── Upgrade options — plans higher than current ───────────────
$plan_hierarchy = ['Starter' => 0, 'Pro' => 1, 'Enterprise' => 2];
$current_rank   = $plan_hierarchy[$plan] ?? 0;
$upgrade_targets   = array_filter(['Pro', 'Enterprise'], fn($p) => ($plan_hierarchy[$p] ?? 0) > $current_rank);
$downgrade_targets = array_filter(['Starter', 'Pro'],    fn($p) => ($plan_hierarchy[$p] ?? 0) < $current_rank);

// Can tenant downgrade right now? Allowed after expiry OR within 7-day window.
$sub_expired_for_downgrade = empty($sub_end) || (strtotime($sub_end) <= time());
// Is tenant in the 7-day scheduling window? (1-7 days left)
$days_left_for_ui = $days_left ?? 0;
$in_7day_window   = ($days_left !== null && $days_left > 0 && $days_left <= 7);
// Too early to schedule (>7 days left and sub still active)
$too_early_to_change = ($days_left !== null && $days_left > 7);

// Proration preview: credit for unused days on current paid billing
$proration_preview = 0;
if ($sub_end && $days_left > 0 && $is_paid_plan) {
    $current_monthly     = $plan_prices_js[$plan]['monthly'] ?? 0;
    $proration_preview   = round(($current_monthly / 30) * $days_left, 2);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover, maximum-scale=1.0"/>
<title>Subscription — <?= htmlspecialchars($tenant['business_name']) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet"/>
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet"/>
<?= renderThemeCSS($theme) ?>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
:root{--sw:220px;}
body{background:#f0f2f5;font-family:'Plus Jakarta Sans',sans-serif;color:#1c1e21;min-height:100vh;display:flex;}
.material-symbols-outlined{font-variation-settings:'FILL' 1,'wght' 400,'GRAD' 0,'opsz' 24;vertical-align:middle;}
.sidebar{width:var(--sw);min-height:100vh;background:var(--t-sidebar,#ffffff);border-right:1px solid #e4e6eb;display:flex;flex-direction:column;position:fixed;left:0;top:0;bottom:0;z-index:100;}
.sb-brand{padding:18px 16px;border-bottom:1px solid #e4e6eb;display:flex;align-items:center;gap:9px;}
.sb-logo{width:32px;height:32px;background:linear-gradient(135deg,var(--t-primary,#2563eb),var(--t-secondary,#1e3a8a));border-radius:9px;display:flex;align-items:center;justify-content:center;flex-shrink:0;overflow:hidden;}
.sb-logo img{width:100%;height:100%;object-fit:cover;}
.sb-name{font-size:.88rem;font-weight:800;color:#1c1e21;}
.sb-user{padding:10px 16px;border-bottom:1px solid #e4e6eb;display:flex;align-items:center;gap:8px;}
.sb-avatar{width:28px;height:28px;border-radius:50%;background:linear-gradient(135deg,var(--t-primary,#2563eb),var(--t-secondary,#1e3a8a));display:flex;align-items:center;justify-content:center;font-size:.72rem;font-weight:700;color:#fff;flex-shrink:0;}
.sb-uname{font-size:.76rem;font-weight:600;color:#1c1e21;}
.sb-urole{font-size:.6rem;color:#65676b;}
.sb-nav{flex:1;padding:8px 0;}
.sb-section{font-size:.58rem;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:#8a8d91;padding:10px 14px 4px;}
.sb-item{display:flex;align-items:center;gap:9px;padding:8px 12px;margin:1px 6px;border-radius:9px;cursor:pointer;color:#65676b;font-size:.8rem;font-weight:500;text-decoration:none;transition:all .15s;}
.sb-item:hover{background:#f2f2f2;color:#1c1e21;}
.sb-item.active{background:color-mix(in srgb,var(--t-primary,#2563eb) 10%,transparent);color:var(--t-primary,#2563eb);font-weight:700;}
.sb-item .material-symbols-outlined{font-size:16px;flex-shrink:0;}
.sb-footer{padding:10px 12px;border-top:1px solid #e4e6eb;}
.sb-logout{display:flex;align-items:center;gap:8px;font-size:.78rem;color:#65676b;text-decoration:none;padding:7px 8px;border-radius:8px;transition:all .15s;}
.sb-logout:hover{color:#ef4444;background:rgba(239,68,68,.08);}
.main{margin-left:var(--sw);flex:1;display:flex;flex-direction:column;}
.topbar{height:60px;padding:0 26px;background:#ffffff;border-bottom:1px solid #e4e6eb;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:50;}
.content{padding:24px 28px;flex:1;}
.page-title{font-size:1.3rem;font-weight:800;margin-bottom:4px;}
.page-sub{color:#65676b;font-size:.82rem;margin-bottom:24px;}
.card{background:#ffffff;border:1px solid #e4e6eb;border-radius:16px;padding:22px;margin-bottom:18px;}
.card-label{font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.09em;color:#8a8d91;margin-bottom:14px;}
.stat-row{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:14px;}
.stat-box{background:#f7f8fa;border:1px solid #e4e6eb;border-radius:12px;padding:16px;}
.stat-label{font-size:.67rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:#8a8d91;margin-bottom:6px;}
.stat-value{font-size:1.1rem;font-weight:800;color:#1c1e21;}
.stat-hint{font-size:.71rem;color:#8a8d91;margin-top:2px;}
.badge{display:inline-block;padding:3px 11px;border-radius:100px;font-size:.72rem;font-weight:700;}
.alert{border-radius:11px;padding:12px 16px;font-size:.82rem;margin-bottom:18px;line-height:1.6;}
.alert-success{background:rgba(16,185,129,.1);border:1px solid rgba(16,185,129,.25);color:#059669;}
.alert-error{background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.25);color:#dc2626;}
.alert-warn{background:rgba(245,158,11,.1);border:1px solid rgba(245,158,11,.25);color:#d97706;}
.alert-info{background:rgba(37,99,235,.08);border:1px solid rgba(37,99,235,.2);color:#1d4ed8;}
.flabel{display:block;font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:#65676b;margin-bottom:5px;}
.finput,.fselect{width:100%;background:#f7f8fa;border:1.5px solid #e4e6eb;border-radius:9px;color:#1c1e21;font-family:inherit;font-size:.86rem;padding:10px 13px;outline:none;transition:border-color .2s;}
.finput:focus,.fselect:focus{border-color:var(--t-primary,#2563eb);background:#ffffff;box-shadow:0 0 0 3px color-mix(in srgb,var(--t-primary,#2563eb) 12%,transparent);}
.finput::placeholder{color:#8a8d91;}
.fselect option{background:#ffffff;color:#1c1e21;}
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px;}
.form-group{margin-bottom:14px;}
/* Payment method tabs */
.pay-tabs{display:flex;gap:0;border:1.5px solid #e4e6eb;border-radius:11px;overflow:hidden;margin-bottom:20px;}
.pay-tab{flex:1;padding:13px 10px;text-align:center;cursor:pointer;background:transparent;border:none;color:#65676b;font-family:inherit;font-size:.82rem;font-weight:600;transition:all .2s;border-right:1px solid #e4e6eb;}
.pay-tab:last-child{border-right:none;}
.pay-tab.active{background:var(--t-primary,#2563eb);color:#fff;}
.pay-tab:hover:not(.active){background:#f2f2f2;color:#1c1e21;}
.pay-panel{display:none;}
.pay-panel.active{display:block;}
/* Buttons */
.btn{display:inline-flex;align-items:center;gap:7px;padding:11px 24px;border-radius:10px;font-family:inherit;font-size:.86rem;font-weight:700;cursor:pointer;border:none;transition:all .15s;}
.btn-paymongo{background:linear-gradient(135deg,#2563eb,#7c3aed);color:#fff;box-shadow:0 4px 18px rgba(37,99,235,.3);width:100%;justify-content:center;font-size:.92rem;padding:14px;}
.btn-paymongo:hover{transform:translateY(-1px);box-shadow:0 6px 22px rgba(37,99,235,.4);}
.btn-primary{background:var(--t-primary,#2563eb);color:#fff;}
.btn-primary:hover{transform:translateY(-1px);}
.amount-display{background:#f0f2f5;border:1px solid #e4e6eb;border-radius:9px;padding:11px 14px;font-size:1.05rem;font-weight:800;color:var(--t-primary,#2563eb);margin-bottom:14px;}
.pending-box{background:color-mix(in srgb,var(--t-primary,#2563eb) 6%,transparent);border:1px solid color-mix(in srgb,var(--t-primary,#2563eb) 18%,transparent);border-radius:12px;padding:18px;}
.paymongo-badge{display:inline-flex;align-items:center;gap:6px;background:color-mix(in srgb,var(--t-primary,#2563eb) 10%,transparent);border:1px solid color-mix(in srgb,var(--t-primary,#2563eb) 20%,transparent);border-radius:8px;padding:4px 10px;font-size:.72rem;font-weight:700;color:var(--t-primary,#1d4ed8);}
.history-table{width:100%;border-collapse:collapse;font-size:.8rem;}
.history-table th{text-align:left;padding:7px 11px;color:#8a8d91;font-size:.67rem;text-transform:uppercase;letter-spacing:.07em;border-bottom:1px solid #e4e6eb;}
.history-table td{padding:9px 11px;border-bottom:1px solid #f0f2f5;color:#1c1e21;vertical-align:middle;}
.history-table tr:last-child td{border-bottom:none;}
.expiry-bar-wrap{background:#e4e6eb;border-radius:100px;height:6px;margin-top:10px;overflow:hidden;}
.expiry-bar{height:100%;border-radius:100px;}
.divider{display:flex;align-items:center;gap:12px;color:#8a8d91;font-size:.75rem;font-weight:600;margin:18px 0;}
.divider::before,.divider::after{content:'';flex:1;height:1px;background:#e4e6eb;}
@media(max-width:768px){
  .sidebar{transform:translateX(-100%);transition:transform .3s ease;box-shadow:none;}
  .sidebar.mobile-open{transform:translateX(0);box-shadow:4px 0 20px rgba(0,0,0,.15);}
  .main{margin-left:0!important;width:100%;}
  .topbar{padding:0 14px;}
  #mob-menu-btn{display:flex!important;}
  .mob-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:99;}
  .mob-overlay.open{display:block;}
  .content{padding:16px;}
}
@media(max-width:600px){.form-grid{grid-template-columns:1fr;}.pay-tabs{flex-direction:column;}.pay-tab{border-right:none;border-bottom:1px solid #e4e6eb;}}

/* ===== MOBILE / iOS COMPATIBILITY FIXES ===== */
* { -webkit-tap-highlight-color: transparent; }
html { -webkit-text-size-adjust: 100%; }
/* iOS safe area support */
.safe-top    { padding-top:    env(safe-area-inset-top,    0px); }
.safe-bottom { padding-bottom: env(safe-area-inset-bottom, 0px); }
/* iOS overflow scroll */
.overflow-y-auto, .overflow-auto { -webkit-overflow-scrolling: touch; }
/* Prevent iOS zoom on input focus */
input, select, textarea { font-size: max(16px, 1rem) !important; }
/* Mobile sidebar fix */
@media (max-width: 768px) {
  .sidebar-fixed { position: fixed !important; z-index: 50; height: 100dvh; }
  .main-content  { margin-left: 0 !important; width: 100% !important; }
}
/* Smooth scrolling on mobile */
html { scroll-behavior: smooth; }

/* Form mobile fixes */
@media (max-width: 480px) {
    .panel, .card { 
        width: 100% !important; 
        max-width: 100% !important; 
        margin: 0 !important;
        border-radius: 0 !important;
        min-height: 100dvh !important;
    }
    .page { padding: 0 !important; align-items: flex-start !important; }
}

/* ===== RESPONSIVE TABLES ===== */
.table-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; width: 100%; }
table { width: 100%; border-collapse: collapse; min-width: 480px; }
@media (max-width: 768px) {
    .table-wrap::before { content: '← Swipe →'; display: block; text-align: center; font-size: .65rem; color: #8a8d91; padding: 3px 0; }
    table { font-size: .74rem !important; }
    th, td { padding: 7px 9px !important; white-space: nowrap; }
}
@media (max-width: 480px) {
    .content, .page-content { padding: 10px 8px !important; }
}
</style>
</head>
<body>

<!-- ── Sidebar ──────────────────────────────────────────────── -->
<aside class="sidebar">
  <div class="sb-brand">
    <div class="sb-logo">
      <?php if($logo_url): ?><img src="<?= htmlspecialchars($logo_url) ?>" alt="logo">
      <?php else: ?><svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2.5"><rect x="3" y="9" width="18" height="12"/><polyline points="3 9 12 3 21 9"/></svg><?php endif; ?>
    </div>
    <div><div class="sb-name"><?= htmlspecialchars($logo_text) ?></div></div>
  </div>
  <div class="sb-user">
    <div class="sb-avatar"><?= strtoupper(substr($u['name'] ?? 'A', 0, 1)) ?></div>
    <div>
      <div class="sb-uname"><?= htmlspecialchars($u['name'] ?? '') ?></div>
      <div class="sb-urole">Admin</div>
    </div>
  </div>
  <nav class="sb-nav">
    <div class="sb-section">Navigation</div>
    <a href="tenant.php?page=dashboard" class="sb-item">
      <span class="material-symbols-outlined">dashboard</span>Dashboard
    </a>
    <a href="tenant.php?page=tickets" class="sb-item">
      <span class="material-symbols-outlined">receipt_long</span>Pawn Tickets
    </a>
    <a href="tenant.php?page=customers" class="sb-item">
      <span class="material-symbols-outlined">group</span>Customers
    </a>
    <div class="sb-section">Account</div>
    <a href="tenant_subscription.php" class="sb-item active">
      <span class="material-symbols-outlined">workspace_premium</span>Subscription
    </a>
    <a href="tenant.php?page=settings" class="sb-item">
      <span class="material-symbols-outlined">palette</span>Settings
    </a>
  </nav>
  <div class="sb-footer">
    <a href="logout.php?role=admin" class="sb-logout">
      <span class="material-symbols-outlined" style="font-variation-settings:'FILL' 0,'wght' 400,'GRAD' 0,'opsz' 24;">logout</span>Sign Out
    </a>
  </div>
</aside>

<!-- ── Main ─────────────────────────────────────────────────── -->
<div class="main">
  <header class="topbar">
    <div style="display:flex;align-items:center;gap:10px;">
      <button id="mob-menu-btn" onclick="toggleSidebar()" style="display:none;width:34px;height:34px;border:1px solid #e4e6eb;border-radius:8px;background:#f0f2f5;cursor:pointer;align-items:center;justify-content:center;flex-shrink:0;color:#1c1e21;">
        <span class="material-symbols-outlined" style="font-size:18px;">menu</span>
      </button>
      <a href="tenant.php?page=dashboard" style="color:#65676b;text-decoration:none;display:flex;align-items:center;gap:5px;font-size:.8rem;">
        <span class="material-symbols-outlined" style="font-size:16px;font-variation-settings:'FILL' 0,'wght' 400,'GRAD' 0,'opsz' 24;">arrow_back</span>
        Back to Dashboard
      </a>
    </div>
    <div style="font-size:.78rem;color:#8a8d91;"><?= date('F d, Y') ?></div>
  </header>

  <div class="content">

    <h1 class="page-title">
      <span class="material-symbols-outlined" style="font-size:1.3rem;margin-right:6px;color:var(--t-primary,#2563eb);">workspace_premium</span>
      Subscription
    </h1>
    <p class="page-sub">Manage your PawnHub subscription and billing for <?= htmlspecialchars($tenant['business_name']) ?>.</p>

    <?php if ($success_msg): ?>
    <div class="alert alert-success"><?= $success_msg ?></div>
    <?php endif; ?>
    <?php if ($error_msg): ?>
    <div class="alert alert-error"><?= htmlspecialchars($error_msg) ?></div>
    <?php endif; ?>

    <!-- ── Current Subscription ────────────────────────────── -->
    <div class="card">
      <div class="card-label">Current Subscription</div>

      <?php if ($sub_stat === 'expired' || ($days_left !== null && $days_left <= 0)): ?>
      <div class="alert alert-error" style="margin-bottom:16px;">
        ⛔ <strong>Your subscription has expired.</strong> Renew below to restore full access.
      </div>
      <?php elseif ($sub_stat === 'expiring_soon' || ($days_left !== null && $days_left <= 7)): ?>
      <div class="alert alert-warn" style="margin-bottom:16px;">
        ⏰ <strong>Your subscription is expiring soon.</strong> Renew now to avoid interruption.
      </div>
      <?php endif; ?>

      <div class="stat-row">
        <div class="stat-box">
          <div class="stat-label">Plan</div>
          <div class="stat-value"><?= htmlspecialchars($plan) ?></div>
        </div>
        <div class="stat-box">
          <div class="stat-label">Status</div>
          <div class="stat-value">
            <span class="badge" style="color:<?= $sb[1] ?>;background:<?= $sb[2] ?>;border:1px solid <?= $sb[3] ?>;"><?= $sb[0] ?></span>
          </div>
        </div>
        <div class="stat-box">
          <div class="stat-label">Start Date</div>
          <div class="stat-value" style="font-size:.9rem;"><?= $sub_start ? date('M d, Y', strtotime($sub_start)) : '—' ?></div>
        </div>
        <div class="stat-box">
          <div class="stat-label">Expiry Date</div>
          <div class="stat-value" style="font-size:.9rem;"><?= $sub_end ? date('M d, Y', strtotime($sub_end)) : '—' ?></div>
          <?php if ($days_left !== null): ?>
          <div class="stat-hint" style="color:<?= $days_left <= 0 ? '#fca5a5' : ($days_left <= 7 ? '#fcd34d' : 'rgba(255,255,255,.3)') ?>;">
            <?= $days_left <= 0 ? 'Expired ' . abs($days_left) . 'd ago' : $days_left . ' day(s) left' ?>
          </div>
          <?php if ($days_left > 0 && $days_left <= 30): ?>
          <div class="expiry-bar-wrap">
            <div class="expiry-bar" style="width:<?= min(100, round(($days_left/30)*100)) ?>%;background:<?= $days_left <= 3 ? '#dc2626' : ($days_left <= 7 ? '#d97706' : 'var(--t-primary,#2563eb)') ?>;"></div>
          </div>
          <?php endif; ?>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- ── Pending Renewal ─────────────────────────────────── -->
    <?php if ($pending_renewal): ?>
    <div class="card">
      <div class="card-label">Pending Renewal Request</div>
      <div class="pending-box">
        <p style="color:#93c5fd;font-size:.86rem;font-weight:600;margin-bottom:12px;">
          📋 Your renewal request has been submitted and is under review.
        </p>
        <p style="color:rgba(255,255,255,.5);font-size:.8rem;margin-bottom:14px;">
          Our admin will verify your payment and activate your subscription within 24 hours.
          You'll receive an email once approved.
        </p>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;font-size:.8rem;">
          <div><span style="color:rgba(255,255,255,.4);">Plan:</span> <strong><?= htmlspecialchars($pending_renewal['plan']) ?></strong></div>
          <div><span style="color:rgba(255,255,255,.4);">Billing:</span> <strong><?= ucfirst($pending_renewal['billing_cycle']) ?></strong></div>
          <div>
            <span style="color:rgba(255,255,255,.4);">Payment:</span>
            <strong><?= htmlspecialchars($pending_renewal['payment_method']) ?></strong>
            <?php if (str_starts_with($pending_renewal['payment_method'], 'PayMongo')): ?>
            <span class="paymongo-badge" style="margin-left:6px;">⚡ PayMongo</span>
            <?php endif; ?>
          </div>
          <div><span style="color:rgba(255,255,255,.4);">Ref #:</span> <strong><?= htmlspecialchars($pending_renewal['payment_reference'] ?: '—') ?></strong></div>
          <div><span style="color:rgba(255,255,255,.4);">Submitted:</span> <strong><?= date('M d, Y h:i A', strtotime($pending_renewal['requested_at'])) ?></strong></div>
          <?php if ($pending_renewal['amount'] > 0): ?>
          <div><span style="color:rgba(255,255,255,.4);">Amount:</span> <strong>₱<?= number_format($pending_renewal['amount'], 2) ?></strong></div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <?php else: ?>

    <!-- ── Renewal Form ────────────────────────────────────── -->
    <div class="card">
      <div class="card-label">Request Subscription Renewal</div>

      <!-- Billing Cycle selector (shared by both payment methods) -->
      <div class="form-grid" style="margin-bottom:16px;">
        <div>
          <label class="flabel">Current Plan</label>
          <input type="text" class="finput" value="<?= htmlspecialchars($plan) ?>" disabled/>
        </div>
        <div>
          <label class="flabel">Billing Cycle</label>
          <select id="billing_cycle_shared" class="fselect" onchange="syncBillingCycle(this.value)">
            <option value="monthly">Monthly</option>
            <option value="quarterly">Quarterly (save ~10%)</option>
            <option value="annually">Annually (save ~20%)</option>
          </select>
        </div>
      </div>

      <?php if ($is_paid_plan): ?>
      <div class="amount-display" id="amount-display">₱999.00</div>
      <?php endif; ?>

      <!-- Payment method tabs -->
      <?php if ($is_paid_plan): ?>
      <div class="alert alert-info" style="margin-bottom:18px;">
        ✅ <strong>Secure payment powered by PayMongo.</strong> Pay instantly via GCash, Maya, Credit/Debit Card, or Online Banking.
        Your renewal request will be recorded automatically after payment.
      </div>
      <div style="background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.07);border-radius:10px;padding:14px 16px;margin-bottom:18px;">
        <p style="font-size:.76rem;color:rgba(255,255,255,.4);line-height:1.8;margin:0;">
          🔒 <strong style="color:rgba(255,255,255,.6);">Accepted:</strong> GCash, Maya (PayMaya), Credit Card, Debit Card, Online Banking (BPI &amp; more)<br>
          After payment, our admin will approve your renewal within 24 hours.
        </p>
      </div>
      <form method="POST" action="paymongo_renewal.php" id="form-paymongo">
        <input type="hidden" name="action" value="pay_via_paymongo"/>
        <input type="hidden" name="billing_cycle" id="pm_billing_cycle" value="monthly"/>
        <button type="submit" class="btn btn-paymongo">
          ⚡ Pay Now via PayMongo
        </button>
      </form>
      <?php endif; ?>

    </div>
    <?php endif; ?>

    <!-- ── Upgrade Plan ───────────────────────────────────────── -->
    <?php if (!empty($upgrade_targets) && !$pending_renewal): ?>
    <div class="card" style="border-color:rgba(139,92,246,.25);background:rgba(139,92,246,.05);">
      <div class="card-label" style="color:rgba(196,181,253,.8);">
        <span class="material-symbols-outlined" style="font-size:14px;vertical-align:middle;margin-right:4px;">rocket_launch</span>
        Upgrade Your Plan
      </div>

      <!-- Plan comparison cards -->
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:14px;margin-bottom:20px;">
        <?php
        $plan_details = [
          'Pro' => [
            'price_monthly' => 999,
            'color'  => '#3b82f6',
            'icon'   => 'workspace_premium',
            'perks'  => ['Theme & Branding','Manager Invitations','Audit Logs','Advanced Reports','Priority Support'],
          ],
          'Enterprise' => [
            'price_monthly' => 2499,
            'color'  => '#a78bfa',
            'icon'   => 'diamond',
            'perks'  => ['Everything in Pro','Data Export (PDF/Excel)','White Label Branding','Dedicated Account Manager','Custom Integrations'],
          ],
        ];
        foreach ($upgrade_targets as $target):
          $pd = $plan_details[$target];
        ?>
        <div style="background:rgba(255,255,255,.04);border:1.5px solid color-mix(in srgb,<?= $pd['color'] ?> 35%,transparent);border-radius:14px;padding:18px;position:relative;overflow:hidden;">
          <div style="position:absolute;top:-20px;right:-20px;opacity:.06;">
            <span class="material-symbols-outlined" style="font-size:100px;color:<?= $pd['color'] ?>"><?= $pd['icon'] ?></span>
          </div>
          <div style="display:flex;align-items:center;gap:8px;margin-bottom:12px;">
            <span class="material-symbols-outlined" style="font-size:18px;color:<?= $pd['color'] ?>;font-variation-settings:'FILL' 1,'wght' 400,'GRAD' 0,'opsz' 24;"><?= $pd['icon'] ?></span>
            <span style="font-size:.9rem;font-weight:800;color:#fff;"><?= $target ?></span>
          </div>
          <div style="margin-bottom:12px;">
            <span style="font-size:1.4rem;font-weight:800;color:#fff;">₱<?= number_format($pd['price_monthly']) ?></span>
            <span style="font-size:.72rem;color:rgba(255,255,255,.4);">/month</span>
          </div>
          <?php foreach ($pd['perks'] as $perk): ?>
          <div style="display:flex;align-items:center;gap:7px;font-size:.78rem;color:rgba(255,255,255,.6);margin-bottom:5px;">
            <span class="material-symbols-outlined" style="font-size:14px;color:<?= $pd['color'] ?>;font-variation-settings:'FILL' 1,'wght' 400,'GRAD' 0,'opsz' 24;flex-shrink:0;">check_circle</span>
            <?= htmlspecialchars($perk) ?>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endforeach; ?>
      </div>

      <?php if ($proration_preview > 0 && !$in_7day_window): ?>
      <div class="alert alert-info" style="margin-bottom:16px;">
        💡 <strong>Proration Credit:</strong> You have <strong><?= $days_left ?> days remaining</strong> on your <?= htmlspecialchars($plan) ?> plan.
        We'll apply a credit of <strong>₱<?= number_format($proration_preview, 2) ?></strong> toward your upgrade — so you only pay the difference!
      </div>
      <?php elseif ($in_7day_window): ?>
      <div class="alert alert-info" style="margin-bottom:16px;">
        📅 <strong>Schedule your upgrade now.</strong> Your <strong><?= htmlspecialchars($plan) ?></strong> subscription expires in
        <strong><?= $days_left ?> day(s)</strong> (<?= date('F d, Y', strtotime($sub_end)) ?>).<br>
        Your upgrade will take effect <strong>after your current subscription ends</strong> — you keep full access until then.
        You pay the full price for the new plan (no proration for scheduled upgrades).
      </div>
      <?php endif; ?>

      <!-- Plan + Billing selectors (shared for both payment tabs) -->
      <div class="form-grid" style="margin-bottom:14px;">
        <div>
          <label class="flabel">Upgrade To</label>
          <select id="upgrade_to_shared" class="fselect" onchange="syncUpgradePlan(this.value); updateUpgradeAmount();" required>
            <option value="">— Select Target Plan —</option>
            <?php foreach ($upgrade_targets as $target): ?>
            <option value="<?= htmlspecialchars($target) ?>"><?= htmlspecialchars($target) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="flabel">Billing Cycle</label>
          <select id="upgrade_billing_shared" class="fselect" onchange="syncUpgradeCycle(this.value); updateUpgradeAmount();">
            <option value="monthly">Monthly</option>
            <option value="quarterly">Quarterly (save ~10%)</option>
            <option value="annually">Annually (save ~20%)</option>
          </select>
        </div>
      </div>

      <!-- Amount Due Preview -->
      <div id="upgrade-amount-box" style="display:none;background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.1);border-radius:10px;padding:14px 16px;margin-bottom:16px;">
        <div style="font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:rgba(255,255,255,.35);margin-bottom:8px;">Payment Summary</div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;font-size:.82rem;">
          <div style="color:rgba(255,255,255,.45);">New Plan Price:</div>
          <div style="color:#fff;font-weight:700;text-align:right;" id="upg-new-price">—</div>
          <?php if ($proration_preview > 0): ?>
          <div style="color:rgba(255,255,255,.45);">Proration Credit:</div>
          <div style="color:#6ee7b7;font-weight:700;text-align:right;" id="upg-credit">— ₱<?= number_format($proration_preview, 2) ?></div>
          <?php endif; ?>
          <div style="color:rgba(255,255,255,.7);font-weight:700;border-top:1px solid rgba(255,255,255,.08);padding-top:6px;margin-top:2px;">Amount Due:</div>
          <div style="font-size:1.05rem;font-weight:800;color:#a78bfa;text-align:right;border-top:1px solid rgba(255,255,255,.08);padding-top:6px;margin-top:2px;" id="upg-total">—</div>
        </div>
      </div>

      <!-- PayMongo only for upgrade -->
      <div class="alert alert-info" style="margin-bottom:16px;">
        ✅ <strong>Secure payment powered by PayMongo.</strong> Pay instantly via GCash, Maya, Credit/Debit Card, or Online Banking.
        Your upgrade will be recorded automatically after payment.
      </div>
      <form method="POST" action="paymongo_renewal.php" id="upgradeFormPM">
        <input type="hidden" name="action"        value="pay_upgrade_paymongo"/>
        <input type="hidden" name="upgrade_to"    id="upg_pm_plan"    value=""/>
        <input type="hidden" name="billing_cycle" id="upg_pm_cycle"   value="monthly"/>
        <button type="submit" class="btn btn-paymongo" style="margin-top:4px;"
          onclick="return validateUpgradeSelect()">
          ⚡ <?= $in_7day_window ? 'Schedule & Pay via PayMongo' : 'Pay Now via PayMongo' ?>
        </button>
      </form>
    </div>
    <?php elseif ($plan === 'Enterprise'): ?>
    <div class="card" style="text-align:center;padding:28px;">
      <span class="material-symbols-outlined" style="font-size:40px;color:#a78bfa;display:block;margin-bottom:10px;font-variation-settings:'FILL' 1,'wght' 400,'GRAD' 0,'opsz' 24;">diamond</span>
      <div style="font-size:.9rem;font-weight:700;color:#fff;margin-bottom:4px;">You're on the Enterprise Plan</div>
      <div style="font-size:.78rem;color:rgba(255,255,255,.4);">You already have access to all features. No upgrades available.</div>
    </div>
    <?php endif; ?>

    <!-- ── Downgrade Plan ──────────────────────────────────────── -->
    <?php if (!empty($downgrade_targets) && !$pending_renewal): ?>
    <div class="card" style="border-color:rgba(100,116,139,.25);background:rgba(100,116,139,.05);">
      <div class="card-label" style="color:rgba(148,163,184,.8);">
        <span class="material-symbols-outlined" style="font-size:14px;vertical-align:middle;margin-right:4px;">arrow_downward</span>
        Downgrade Plan
      </div>

      <?php if ($too_early_to_change): ?>
      <!-- State 1: Too early (>7 days left) — locked -->
      <div style="background:rgba(245,158,11,.08);border:1px solid rgba(245,158,11,.3);border-radius:14px;padding:22px;text-align:center;">
        <span class="material-symbols-outlined" style="font-size:36px;color:#fcd34d;display:block;margin-bottom:10px;font-variation-settings:'FILL' 1,'wght' 400,'GRAD' 0,'opsz' 24;">lock_clock</span>
        <div style="font-size:.95rem;font-weight:800;color:#fcd34d;margin-bottom:8px;">Downgrade Available Soon</div>
        <div style="font-size:.82rem;color:rgba(255,255,255,.6);line-height:1.8;margin-bottom:14px;">
          You can schedule a downgrade within the last <strong style="color:#fff;">7 days</strong> of your subscription.<br>
          Your <strong style="color:#fff;"><?= htmlspecialchars($plan) ?></strong> plan is still active for <strong style="color:#fcd34d;"><?= $days_left ?> more day(s)</strong>.<br>
          Come back when you have 7 or fewer days remaining.
        </div>
        <div style="display:inline-flex;align-items:center;gap:10px;background:rgba(245,158,11,.12);border:1px solid rgba(245,158,11,.25);border-radius:10px;padding:10px 20px;">
          <span class="material-symbols-outlined" style="font-size:18px;color:#fcd34d;font-variation-settings:'FILL' 1,'wght' 400,'GRAD' 0,'opsz' 24;">calendar_month</span>
          <div style="text-align:left;">
            <div style="font-size:.7rem;color:rgba(255,255,255,.4);font-weight:700;text-transform:uppercase;letter-spacing:.07em;">Downgrade Available From</div>
            <div style="font-size:.9rem;font-weight:800;color:#fff;"><?= date('F d, Y', strtotime($sub_end . ' -7 days')) ?></div>
          </div>
        </div>
      </div>

      <?php elseif ($in_7day_window || $sub_expired_for_downgrade): ?>
      <?php
      // ── Unified State 2 + State 3 — single set of IDs, no duplicates ──
      $dg_is_scheduled = $in_7day_window; // true = schedule (State 2), false = immediate (State 3)
      $dg_sub_end_fmt  = ($sub_end && $dg_is_scheduled) ? date('M d, Y', strtotime($sub_end)) : null;

      if ($dg_is_scheduled): ?>
      <div class="alert" style="background:rgba(37,99,235,.1);border:1px solid rgba(37,99,235,.3);color:#93c5fd;margin-bottom:16px;">
        📅 <strong>Schedule your downgrade now.</strong> Your <strong><?= htmlspecialchars($plan) ?></strong> subscription expires in
        <strong><?= $days_left ?> day(s)</strong> (<?= date('F d, Y', strtotime($sub_end)) ?>).<br>
        Your downgrade will take effect <strong>after your current subscription ends</strong> — you keep full access until then.
        <?php if (count($downgrade_targets) > 0 && !in_array('Starter', array_values($downgrade_targets))): ?>
        You still need to pay for the new plan to activate it.
        <?php endif; ?>
      </div>
      <?php else: ?>
      <div class="alert" style="background:rgba(100,116,139,.1);border:1px solid rgba(100,116,139,.25);color:rgba(148,163,184,.9);margin-bottom:16px;">
        📉 Your subscription has expired. You may now switch to a lower plan and pay for the new period.
        <strong>Note:</strong> Downgrading will reduce your available features.
      </div>
      <?php endif; ?>

      <?php
      $downgrade_plan_details = [
        'Starter' => [
          'price_monthly' => 0,
          'color'  => '#64748b',
          'icon'   => 'inventory_2',
          'perks'  => ['Basic pawn ticket management', 'Customer records', 'Staff accounts', 'Shop page'],
          'loses'  => ['Theme & Branding', 'Manager Invitations', 'Audit Logs', 'Advanced Reports'],
        ],
        'Pro' => [
          'price_monthly' => 999,
          'color'  => '#3b82f6',
          'icon'   => 'workspace_premium',
          'perks'  => ['Theme & Branding', 'Manager Invitations', 'Audit Logs', 'Advanced Reports'],
          'loses'  => ['Data Export (PDF/Excel)', 'White Label Branding', 'Dedicated Account Manager'],
        ],
      ];
      ?>
      <!-- Downgrade target cards -->
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:14px;margin-bottom:20px;">
        <?php foreach ($downgrade_targets as $dtarget):
          $dpd = $downgrade_plan_details[$dtarget] ?? null;
          if (!$dpd) continue;
        ?>
        <div style="background:rgba(255,255,255,.03);border:1.5px solid color-mix(in srgb,<?= $dpd['color'] ?> 25%,transparent);border-radius:14px;padding:18px;position:relative;overflow:hidden;">
          <div style="position:absolute;top:-20px;right:-20px;opacity:.05;">
            <span class="material-symbols-outlined" style="font-size:100px;color:<?= $dpd['color'] ?>"><?= $dpd['icon'] ?></span>
          </div>
          <div style="display:flex;align-items:center;gap:8px;margin-bottom:10px;">
            <span class="material-symbols-outlined" style="font-size:18px;color:<?= $dpd['color'] ?>;font-variation-settings:'FILL' 1,'wght' 400,'GRAD' 0,'opsz' 24;"><?= $dpd['icon'] ?></span>
            <span style="font-size:.9rem;font-weight:800;color:#fff;"><?= $dtarget ?></span>
            <?php if ($dpd['price_monthly'] === 0): ?>
            <span style="font-size:.65rem;font-weight:700;background:rgba(100,116,139,.2);color:#94a3b8;padding:2px 8px;border-radius:100px;">Free</span>
            <?php else: ?>
            <span style="font-size:.65rem;font-weight:700;background:rgba(59,130,246,.15);color:#93c5fd;padding:2px 8px;border-radius:100px;">₱<?= number_format($dpd['price_monthly']) ?>/mo</span>
            <?php endif; ?>
          </div>
          <div style="margin-bottom:8px;">
            <div style="font-size:.67rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:rgba(255,255,255,.3);margin-bottom:4px;">Keeps</div>
            <?php foreach ($dpd['perks'] as $perk): ?>
            <div style="display:flex;align-items:center;gap:6px;font-size:.76rem;color:rgba(255,255,255,.55);margin-bottom:3px;">
              <span class="material-symbols-outlined" style="font-size:13px;color:<?= $dpd['color'] ?>;font-variation-settings:'FILL' 1,'wght' 400,'GRAD' 0,'opsz' 24;flex-shrink:0;">check_circle</span>
              <?= htmlspecialchars($perk) ?>
            </div>
            <?php endforeach; ?>
          </div>
          <div>
            <div style="font-size:.67rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:rgba(239,68,68,.4);margin-bottom:4px;">Loses</div>
            <?php foreach ($dpd['loses'] as $lose): ?>
            <div style="display:flex;align-items:center;gap:6px;font-size:.76rem;color:rgba(255,255,255,.3);margin-bottom:3px;text-decoration:line-through;">
              <span class="material-symbols-outlined" style="font-size:13px;color:rgba(239,68,68,.4);font-variation-settings:'FILL' 1,'wght' 400,'GRAD' 0,'opsz' 24;flex-shrink:0;">cancel</span>
              <?= htmlspecialchars($lose) ?>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
        <?php endforeach; ?>
      </div>

      <!-- ── Unified Plan + Billing selectors (single set of IDs) ── -->
      <div class="form-grid" style="margin-bottom:14px;">
        <div>
          <label class="flabel">Downgrade To</label>
          <select id="downgrade_to_shared" class="fselect" onchange="syncDgPlan(this.value); updateDowngradeAmount();" required>
            <option value="">— Select Plan —</option>
            <?php foreach ($downgrade_targets as $dtarget): ?>
            <option value="<?= htmlspecialchars($dtarget) ?>"><?= htmlspecialchars($dtarget) ?>
              <?= $dtarget === 'Starter' ? '(Free)' : '(₱999/mo)' ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div id="dg-billing-wrap">
          <label class="flabel">Billing Cycle</label>
          <select id="dg_billing_shared" class="fselect" onchange="syncDgCycle(this.value); updateDowngradeAmount();">
            <option value="monthly">Monthly</option>
            <option value="quarterly">Quarterly</option>
            <option value="annually">Annually</option>
          </select>
        </div>
      </div>

      <!-- Amount due preview -->
      <div id="dg-amount-box" style="display:none;background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.08);border-radius:10px;padding:14px 16px;margin-bottom:16px;">
        <div style="font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:rgba(255,255,255,.35);margin-bottom:8px;">Payment Summary</div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;font-size:.82rem;">
          <div style="color:rgba(255,255,255,.45);">New Plan Price:</div>
          <div style="color:#fff;font-weight:700;text-align:right;" id="dg-new-price">—</div>
          <div style="color:rgba(255,255,255,.7);font-weight:700;border-top:1px solid rgba(255,255,255,.08);padding-top:6px;margin-top:2px;">Amount Due:</div>
          <div style="font-size:1.05rem;font-weight:800;color:#94a3b8;text-align:right;border-top:1px solid rgba(255,255,255,.08);padding-top:6px;margin-top:2px;" id="dg-total">—</div>
        </div>
        <div style="font-size:.72rem;color:rgba(255,255,255,.25);margin-top:8px;line-height:1.5;">
          <?php if ($dg_is_scheduled && $dg_sub_end_fmt): ?>
          📅 Scheduled: This activates the new plan <strong>after</strong> your current subscription ends on <?= $dg_sub_end_fmt ?>.
          <?php endif; ?>
          Free (Starter) downgrades require no payment.
        </div>
      </div>

      <!-- PayMongo only for downgrade -->
      <div id="dg-payment-section">
        <div class="alert alert-info" style="margin-bottom:16px;">
          ✅ <strong>Secure payment powered by PayMongo.</strong> Pay instantly via GCash, Maya, Credit/Debit Card, or Online Banking.
          <?php if ($dg_is_scheduled && $dg_sub_end_fmt): ?>
          Your plan will switch after <?= $dg_sub_end_fmt ?>.
          <?php endif; ?>
        </div>
        <form method="POST" action="paymongo_renewal.php" id="downgradeFormPM">
          <input type="hidden" name="action"        value="pay_downgrade_paymongo"/>
          <input type="hidden" name="downgrade_to"  id="dg_pm_plan"  value=""/>
          <input type="hidden" name="billing_cycle" id="dg_pm_cycle" value="monthly"/>
          <button type="submit" class="btn btn-paymongo" style="margin-top:4px;"
            onclick="return validateDgSelect()">
            <?= $dg_is_scheduled ? '⚡ Schedule & Pay via PayMongo' : '⚡ Pay Now via PayMongo' ?>
          </button>
        </form>
      </div>

      <!-- Free/Starter downgrade — no payment needed -->
      <div id="dg-free-section" style="display:none;">
        <div class="alert" style="background:rgba(100,116,139,.1);border:1px solid rgba(100,116,139,.3);color:#94a3b8;margin-bottom:14px;">
          ℹ️ Downgrading to <strong>Starter</strong> is free — no payment required.
          <?php if ($dg_is_scheduled && $dg_sub_end_fmt): ?>
          Your plan will switch after <?= $dg_sub_end_fmt ?>.
          <?php endif; ?>
          Admin will confirm within 24 hours.
        </div>
        <form method="POST" id="downgradeFormFree">
          <input type="hidden" name="action"         value="request_downgrade"/>
          <input type="hidden" name="downgrade_to"   id="dg_free_plan"  value="Starter"/>
          <input type="hidden" name="billing_cycle"  id="dg_free_cycle" value="monthly"/>
          <input type="hidden" name="payment_method" value="N/A (Free Plan)"/>
          <div class="form-group">
            <label class="flabel">Notes <span style="color:rgba(255,255,255,.25);font-weight:400;">(optional)</span></label>
            <textarea name="notes" class="finput" rows="2" style="height:auto;resize:vertical;" placeholder="Any notes for the admin..."></textarea>
          </div>
          <button type="submit" class="btn" style="background:rgba(100,116,139,.4);border:1px solid rgba(100,116,139,.5);color:#cbd5e1;width:100%;justify-content:center;font-size:.92rem;padding:14px;"
            onclick="return confirmDowngrade()">
            <span class="material-symbols-outlined" style="font-size:17px;"><?= $dg_is_scheduled ? 'schedule' : 'arrow_downward' ?></span>
            <?= $dg_is_scheduled ? 'Schedule Downgrade to Starter (Free)' : 'Submit Downgrade to Starter (Free)' ?>
          </button>
        </form>
      </div>

      <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- ── Renewal History ─────────────────────────────────── -->
    <?php if (!empty($renewal_history)): ?>
    <div class="card">
      <div class="card-label">Renewal &amp; Upgrade History</div>
      <div class="table-wrap"><table class="history-table">
          <thead>
            <tr><th>Date</th><th>Type</th><th>Plan</th><th>Billing</th><th>Method</th><th>Amount</th><th>Status</th><th>New Expiry</th></tr>
          </thead>
          <tbody>
            <?php foreach ($renewal_history as $r):
              $rc_color = match($r['status']) { 'approved' => '#15803d', 'rejected' => '#dc2626', default => '#d97706' };
              $rc_bg    = match($r['status']) { 'approved' => '#f0fdf4', 'rejected' => '#fef2f2', default => '#fffbeb' };
              $is_pm    = str_starts_with($r['payment_method'] ?? '', 'PayMongo');
              $is_upg   = !empty($r['is_upgrade']);
              $is_dg    = !$is_upg && str_contains($r['notes'] ?? '', 'PLAN DOWNGRADE');
            ?>
            <tr>
              <td style="font-size:.76rem;color:rgba(255,255,255,.4);"><?= date('M d, Y', strtotime($r['requested_at'])) ?></td>
              <td>
                <?php if ($is_upg): ?>
                <span style="font-size:.65rem;font-weight:700;padding:2px 8px;border-radius:100px;background:rgba(139,92,246,.2);color:#c4b5fd;">Upgrade</span>
                <?php elseif ($is_dg): ?>
                <span style="font-size:.65rem;font-weight:700;padding:2px 8px;border-radius:100px;background:rgba(100,116,139,.2);color:#94a3b8;">Downgrade</span>
                <?php else: ?>
                <span style="font-size:.65rem;font-weight:700;padding:2px 8px;border-radius:100px;background:rgba(59,130,246,.15);color:#93c5fd;">Renewal</span>
                <?php endif; ?>
              </td>
              <td>
                <?= htmlspecialchars($r['plan']) ?>
                <?php if ($is_upg && !empty($r['upgrade_from'])): ?>
                <span style="font-size:.65rem;color:rgba(255,255,255,.3);"> (from <?= htmlspecialchars($r['upgrade_from']) ?>)</span>
                <?php endif; ?>
              </td>
              <td><?= ucfirst($r['billing_cycle']) ?></td>
              <td>
                <?= htmlspecialchars($r['payment_method'] ?: '—') ?>
                <?php if ($is_pm): ?><span class="paymongo-badge" style="margin-left:4px;font-size:.65rem;">⚡ PM</span><?php endif; ?>
              </td>
              <td>
                ₱<?= number_format($r['amount'], 2) ?>
                <?php if (!empty($r['proration_credit']) && $r['proration_credit'] > 0): ?>
                <div style="font-size:.65rem;color:#6ee7b7;">Credit: ₱<?= number_format($r['proration_credit'], 2) ?></div>
                <?php endif; ?>
              </td>
              <td><span class="badge" style="color:<?= $rc_color ?>;background:<?= $rc_bg ?>;"><?= ucfirst($r['status']) ?></span></td>
              <td style="font-size:.76rem;color:rgba(255,255,255,.5);"><?= $r['new_subscription_end'] ? date('M d, Y', strtotime($r['new_subscription_end'])) : '—' ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php endif; ?>

  </div><!-- /content -->
</div><!-- /main -->

<script>
// Pricing table
const prices = {
  Starter:    { monthly: 0,    quarterly: 0,    annually: 0 },
  Pro:        { monthly: 999,  quarterly: 2697, annually: 9588 },
  Enterprise: { monthly: 2499, quarterly: 6747, annually: 23988 },
};
const plan = '<?= addslashes($plan) ?>';
const prorationCredit = <?= json_encode($proration_preview) ?>;

function fmt(n) {
  return '₱' + parseFloat(n).toLocaleString('en-PH', { minimumFractionDigits: 2 });
}

function updateAmount(cycle) {
  const amt = prices[plan]?.[cycle] ?? 0;
  const el = document.getElementById('amount-display');
  if (el) {
    el.textContent = amt > 0
      ? '₱' + amt.toLocaleString('en-PH', { minimumFractionDigits: 2 })
      : 'Free';
  }
}

function syncBillingCycle(val) {
  const pm = document.getElementById('pm_billing_cycle');
  if (pm) pm.value = val;
  updateAmount(val);
}

// Manual payment tabs removed — PayMongo only

// ── Upgrade helpers ───────────────────────────────────────────
function syncUpgradePlan(val) {
  ['upg_pm_plan','upg_mn_plan'].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.value = val;
  });
}
function syncUpgradeCycle(val) {
  ['upg_pm_cycle','upg_mn_cycle'].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.value = val;
  });
}
// Upgrade manual tab removed — PayMongo only
function validateUpgradeSelect() {
  const plan = document.getElementById('upgrade_to_shared')?.value;
  if (!plan) { alert('Please select a plan to upgrade to.'); return false; }
  return true;
}
function updateUpgradeAmount() {
  const targetPlan = document.getElementById('upgrade_to_shared')?.value;
  const cycle      = document.getElementById('upgrade_billing_shared')?.value || 'monthly';
  const box        = document.getElementById('upgrade-amount-box');
  const newPriceEl = document.getElementById('upg-new-price');
  const creditEl   = document.getElementById('upg-credit');
  const totalEl    = document.getElementById('upg-total');
  if (!box) return;
  if (!targetPlan) { box.style.display = 'none'; return; }
  const newPrice = prices[targetPlan]?.[cycle] ?? 0;
  const credit   = prorationCredit || 0;
  const amtDue   = Math.max(0, newPrice - credit);
  if (newPriceEl) newPriceEl.textContent = fmt(newPrice);
  if (creditEl)   creditEl.textContent   = '− ' + fmt(credit);
  if (totalEl)    totalEl.textContent    = fmt(amtDue);
  box.style.display = 'block';
  syncUpgradePlan(targetPlan);
  syncUpgradeCycle(cycle);
}

// ── Downgrade helpers ─────────────────────────────────────────
function syncDgPlan(val) {
  ['dg_pm_plan','dg_mn_plan','dg_free_plan'].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.value = val;
  });
}
function syncDgCycle(val) {
  ['dg_pm_cycle','dg_mn_cycle','dg_free_cycle'].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.value = val;
  });
}
// Downgrade manual tab removed — PayMongo only
function validateDgSelect() {
  const plan = document.getElementById('downgrade_to_shared')?.value;
  if (!plan) { alert('Please select a plan to downgrade to.'); return false; }
  return true;
}
function updateDowngradeAmount() {
  const targetPlan  = document.getElementById('downgrade_to_shared')?.value;
  const cycle       = document.getElementById('dg_billing_shared')?.value || 'monthly';
  const box         = document.getElementById('dg-amount-box');
  const newPriceEl  = document.getElementById('dg-new-price');
  const totalEl     = document.getElementById('dg-total');
  const paySection  = document.getElementById('dg-payment-section');
  const freeSection = document.getElementById('dg-free-section');
  const billingWrap = document.getElementById('dg-billing-wrap');
  if (!box) return;
  if (!targetPlan) { box.style.display = 'none'; return; }
  const newPrice = prices[targetPlan]?.[cycle] ?? 0;
  const isFree   = newPrice === 0;
  if (newPriceEl) newPriceEl.textContent = isFree ? 'Free' : fmt(newPrice);
  if (totalEl)    totalEl.textContent    = isFree ? 'Free' : fmt(newPrice);
  box.style.display = 'block';
  if (paySection)  paySection.style.display  = isFree ? 'none' : 'block';
  if (freeSection) freeSection.style.display = isFree ? 'block' : 'none';
  if (billingWrap) billingWrap.style.display = isFree ? 'none' : 'block';
  syncDgPlan(targetPlan);
  syncDgCycle(cycle);
}
function confirmDowngrade() {
  const targetPlan = document.getElementById('downgrade_to_shared')?.value;
  if (!targetPlan) { alert('Please select a plan to downgrade to.'); return false; }
  return confirm(
    `Are you sure you want to downgrade to the ${targetPlan} plan?\n\n` +
    `You will lose access to features not included in ${targetPlan}. ` +
    `This will take effect after admin approval.`
  );
}

// Init
updateAmount('monthly');
updateUpgradeAmount();
updateDowngradeAmount();
</script>
<div class="mob-overlay" id="mobOverlay" onclick="toggleSidebar()"></div>
<script>
function toggleSidebar(){
  document.querySelector('.sidebar').classList.toggle('mobile-open');
  document.getElementById('mobOverlay').classList.toggle('open');
}
</script>
</body>
</html>