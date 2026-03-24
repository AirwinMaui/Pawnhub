<?php
session_start();
require 'db.php';
require 'mailer.php';

if (empty($_SESSION['user'])) { header('Location: login.php'); exit; }
$u = $_SESSION['user'];
if ($u['role'] !== 'super_admin') { header('Location: login.php'); exit; }

$active_page = $_GET['page'] ?? 'dashboard';
$success_msg = $error_msg = '';

// ── POST ACTIONS ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    // ── ADD TENANT + SEND INVITE EMAIL ────────────────────────
    if ($_POST['action'] === 'add_tenant') {
        $bname    = trim($_POST['business_name'] ?? '');
        $oname    = trim($_POST['owner_name']    ?? '');
        $email    = trim($_POST['email']         ?? '');
        $phone    = trim($_POST['phone']         ?? '');
        $address  = trim($_POST['address']       ?? '');
        $plan     = in_array($_POST['plan']??'', ['Starter','Pro','Enterprise']) ? $_POST['plan'] : 'Starter';
        $branches = intval($_POST['branches']    ?? 1);

        if ($bname && $oname && $email) {
            $chk = $pdo->prepare("SELECT id FROM users WHERE email=?");
            $chk->execute([$email]);
            if ($chk->fetch()) {
                $error_msg = 'Email already registered in the system.';
            } else {
                try {
                    $pdo->beginTransaction();
                    $pdo->prepare("INSERT INTO tenants (business_name,owner_name,email,phone,address,plan,branches,status) VALUES (?,?,?,?,?,?,?,'pending')")
                        ->execute([$bname,$oname,$email,$phone,$address,$plan,$branches]);
                    $new_tid    = $pdo->lastInsertId();
                    $token      = bin2hex(random_bytes(32));
                    $expires_at = date('Y-m-d H:i:s', strtotime('+24 hours'));
                    $pdo->prepare("INSERT INTO tenant_invitations (tenant_id,email,owner_name,token,status,expires_at,created_by) VALUES (?,?,?,?,'pending',?,?)")
                        ->execute([$new_tid,$email,$oname,$token,$expires_at,$u['id']]);
                    $pdo->commit();
                    try { $pdo->prepare("INSERT INTO audit_logs (tenant_id,actor_user_id,actor_username,actor_role,action,entity_type,entity_id,message,ip_address,created_at) VALUES (?,?,?,?,'TENANT_INVITE','tenant',?,?,?,NOW())")->execute([$new_tid,$u['id'],$u['username'],'super_admin',$new_tid,"Super Admin added tenant \"$bname\" and sent invitation to $email.",$_SERVER['REMOTE_ADDR']??'::1']); } catch(PDOException $e){}
                    $sent = sendTenantInvitation($email, $oname, $bname, $token);
                    $success_msg = $sent
                        ? "✅ Tenant \"$bname\" created! Invitation sent to $email."
                        : "⚠️ Tenant created but email failed. Token: $token — Check mailer.php settings.";
                    $active_page = 'tenants';
                } catch (PDOException $e) {
                    $pdo->rollBack();
                    $error_msg = 'Database error: ' . $e->getMessage();
                }
            }
        } else {
            $error_msg = 'Fill in all required fields.';
        }
    }

    // ── RESEND INVITATION ─────────────────────────────────────
    if ($_POST['action'] === 'resend_invite') {
        $inv_id = intval($_POST['inv_id']);
        $inv    = $pdo->prepare("SELECT i.*,t.business_name FROM tenant_invitations i JOIN tenants t ON i.tenant_id=t.id WHERE i.id=?");
        $inv->execute([$inv_id]); $inv = $inv->fetch();
        if ($inv && $inv['status'] === 'pending') {
            $token      = bin2hex(random_bytes(32));
            $expires_at = date('Y-m-d H:i:s', strtotime('+24 hours'));
            $pdo->prepare("UPDATE tenant_invitations SET token=?,expires_at=? WHERE id=?")->execute([$token,$expires_at,$inv_id]);
            $sent = sendTenantInvitation($inv['email'],$inv['owner_name'],$inv['business_name'],$token);
            $success_msg = $sent ? "📧 Invitation resent to {$inv['email']}!" : "⚠️ Failed to send. Check mailer.php settings.";
        }
        $active_page = 'invitations';
    }

    // ── CHANGE TENANT PLAN ────────────────────────────────────
    if ($_POST['action'] === 'change_plan') {
        $tid_p    = intval($_POST['tenant_id']);
        $new_plan = in_array($_POST['new_plan']??'', ['Starter','Pro','Enterprise']) ? $_POST['new_plan'] : 'Starter';
        $plan_limits = ['Starter'=>3,'Pro'=>999,'Enterprise'=>999];
        $new_limit   = $plan_limits[$new_plan] ?? 3;
        $pdo->prepare("UPDATE tenants SET plan=? WHERE id=?")->execute([$new_plan,$tid_p]);
        if ($new_limit < 999) {
            $sl = $pdo->prepare("SELECT id FROM users WHERE tenant_id=? AND role IN ('staff','cashier') AND is_suspended=0 ORDER BY created_at ASC");
            $sl->execute([$tid_p]); $sl = $sl->fetchAll();
            if (count($sl) > $new_limit) {
                $excess = array_slice($sl, $new_limit);
                foreach ($excess as $ex) {
                    $pdo->prepare("UPDATE users SET is_suspended=1,suspended_at=NOW(),suspension_reason='Plan downgraded — staff limit exceeded.' WHERE id=?")->execute([$ex['id']]);
                }
                $success_msg = "Plan changed to <strong>$new_plan</strong>. " . count($excess) . " staff suspended due to new limit.";
            } else {
                $success_msg = "Plan updated to <strong>$new_plan</strong> successfully!";
            }
        } else {
            $pdo->prepare("UPDATE users SET is_suspended=0,suspended_at=NULL,suspension_reason=NULL WHERE tenant_id=? AND suspension_reason='Plan downgraded — staff limit exceeded.'")->execute([$tid_p]);
            $success_msg = "Plan upgraded to <strong>$new_plan</strong>! Previously suspended staff have been restored.";
        }
        try { $pdo->prepare("INSERT INTO audit_logs (tenant_id,actor_user_id,actor_username,actor_role,action,entity_type,entity_id,message,ip_address,created_at) VALUES (?,?,?,?,'CHANGE_PLAN','tenant',?,?,?,NOW())")->execute([$tid_p,$u['id'],$u['username'],'super_admin',$tid_p,"Plan changed to $new_plan for tenant ID $tid_p.",$_SERVER['REMOTE_ADDR']??'::1']); } catch(PDOException $e){}
        $active_page = 'tenants';
    }

    if ($_POST['action'] === 'save_system_settings') {
        $sys_name    = trim($_POST['system_name']    ?? 'PawnHub');
        $sys_tagline = trim($_POST['system_tagline'] ?? 'Multi-Tenant Pawnshop Management');
        $plan_starter_staff    = max(1, intval($_POST['starter_staff']    ?? 3));
        $plan_starter_branches = max(1, intval($_POST['starter_branches'] ?? 1));
        $plan_pro_staff        = intval($_POST['pro_staff']        ?? 0);
        $plan_pro_branches     = max(1, intval($_POST['pro_branches']     ?? 3));
        $plan_ent_staff        = intval($_POST['ent_staff']        ?? 0);
        $plan_ent_branches     = max(1, intval($_POST['ent_branches']     ?? 10));
        $plan_starter_price    = trim($_POST['starter_price'] ?? 'Free');
        $plan_pro_price        = trim($_POST['pro_price']     ?? '₱999/mo');
        $plan_ent_price        = trim($_POST['ent_price']     ?? '₱2,499/mo');

        try {
            $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES
                ('system_name',?),('system_tagline',?),
                ('starter_staff',?),('starter_branches',?),
                ('pro_staff',?),('pro_branches',?),
                ('ent_staff',?),('ent_branches',?),
                ('starter_price',?),('pro_price',?),('ent_price',?)
                ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)")
                ->execute([$sys_name,$sys_tagline,
                    $plan_starter_staff,$plan_starter_branches,
                    $plan_pro_staff,$plan_pro_branches,
                    $plan_ent_staff,$plan_ent_branches,
                    $plan_starter_price,$plan_pro_price,$plan_ent_price]);
            try { $pdo->prepare("INSERT INTO audit_logs (tenant_id,actor_user_id,actor_username,actor_role,action,entity_type,entity_id,message,ip_address,created_at) VALUES (NULL,?,?,?,'SETTINGS_UPDATE','system','1','Super Admin updated system settings.',?,NOW())")->execute([$u['id'],$u['username'],'super_admin',$_SERVER['REMOTE_ADDR']??'::1']); } catch(PDOException $e){}
            $success_msg = 'System settings saved successfully!';
        } catch (PDOException $e) {
            $error_msg = 'Error saving settings: ' . $e->getMessage();
        }
        $active_page = 'settings';
    }

    if ($_POST['action'] === 'approve_tenant') {
        $tid = intval($_POST['tenant_id']);
        $uid = intval($_POST['user_id']);
        $pdo->prepare("UPDATE tenants SET status='active' WHERE id=?")->execute([$tid]);
        $pdo->prepare("UPDATE users SET status='approved', approved_by=?, approved_at=NOW() WHERE id=?")->execute([$u['id'], $uid]);
        try { $pdo->prepare("INSERT INTO audit_logs (actor_id,actor_username,actor_role,action,entity_type,entity_id,message,created_at) VALUES (?,?,?,'APPROVE_TENANT','tenant',?,?,NOW())")->execute([$u['id'],$u['username'],'super_admin',$tid,"Approved tenant ID $tid"]); } catch(PDOException $e){}
        $success_msg = 'Tenant approved successfully. They can now login.';
        $active_page = 'tenants';
    }

    if ($_POST['action'] === 'reject_tenant') {
        $tid    = intval($_POST['tenant_id']);
        $uid    = intval($_POST['user_id']);
        $reason = trim($_POST['reject_reason'] ?? 'Application rejected.');
        $pdo->prepare("UPDATE tenants SET status='rejected' WHERE id=?")->execute([$tid]);
        $pdo->prepare("UPDATE users SET status='rejected', rejected_reason=? WHERE id=?")->execute([$reason, $uid]);
        try { $pdo->prepare("INSERT INTO audit_logs (actor_id,actor_username,actor_role,action,entity_type,entity_id,message,created_at) VALUES (?,?,?,'REJECT_TENANT','tenant',?,?,NOW())")->execute([$u['id'],$u['username'],'super_admin',$tid,"Rejected tenant ID $tid. Reason: $reason"]); } catch(PDOException $e){}
        $success_msg = 'Tenant application rejected.';
        $active_page = 'tenants';
    }

    if ($_POST['action'] === 'deactivate_tenant') {
        $tid = intval($_POST['tenant_id']);
        $pdo->prepare("UPDATE tenants SET status='inactive' WHERE id=?")->execute([$tid]);
        try { $pdo->prepare("INSERT INTO audit_logs (actor_id,actor_username,actor_role,action,entity_type,entity_id,message,created_at) VALUES (?,?,?,'DEACTIVATE_TENANT','tenant',?,?,NOW())")->execute([$u['id'],$u['username'],'super_admin',$tid,"Deactivated tenant ID $tid"]); } catch(PDOException $e){}
        $success_msg = 'Tenant deactivated.';
        $active_page = 'tenants';
    }

    if ($_POST['action'] === 'activate_tenant') {
        $tid = intval($_POST['tenant_id']);
        $pdo->prepare("UPDATE tenants SET status='active' WHERE id=?")->execute([$tid]);
        try { $pdo->prepare("INSERT INTO audit_logs (actor_id,actor_username,actor_role,action,entity_type,entity_id,message,created_at) VALUES (?,?,?,'ACTIVATE_TENANT','tenant',?,?,NOW())")->execute([$u['id'],$u['username'],'super_admin',$tid,"Activated tenant ID $tid"]); } catch(PDOException $e){}
        $success_msg = 'Tenant activated.';
        $active_page = 'tenants';
    }
}

// ── FETCH CORE DATA ──────────────────────────────────────────
try {
    $tenants = $pdo->query("
        SELECT t.*,
            (SELECT COUNT(*) FROM users u WHERE u.tenant_id=t.id AND u.role != 'super_admin') AS user_count,
            (SELECT COUNT(*) FROM users u WHERE u.tenant_id=t.id AND u.status='pending') AS pending_users,
            (SELECT u.fullname FROM users u WHERE u.tenant_id=t.id AND u.role='admin' AND u.status='approved' LIMIT 1) AS admin_name,
            (SELECT u.id FROM users u WHERE u.tenant_id=t.id AND u.role='admin' LIMIT 1) AS admin_uid,
            (SELECT status FROM tenant_invitations ti WHERE ti.tenant_id=t.id ORDER BY ti.created_at DESC LIMIT 1) AS invite_status
        FROM tenants t ORDER BY t.created_at DESC
    ")->fetchAll();
} catch (PDOException $e) {
    $tenants = [];
    $error_msg = 'Error loading tenants: ' . $e->getMessage();
}

try {
    $invitations = $pdo->query("SELECT i.*,t.business_name,t.plan FROM tenant_invitations i JOIN tenants t ON i.tenant_id=t.id ORDER BY i.created_at DESC")->fetchAll();
} catch (PDOException $e) { $invitations = []; }

$pending_inv = count(array_filter($invitations, fn($i) => $i['status'] === 'pending'));

$total_tenants    = count($tenants);
$active_tenants   = count(array_filter($tenants, fn($t) => $t['status'] === 'active'));
$inactive_tenants = count(array_filter($tenants, fn($t) => $t['status'] === 'inactive'));
$pending_tenants  = count(array_filter($tenants, fn($t) => $t['status'] === 'pending'));

try {
    $total_users    = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role != 'super_admin'")->fetchColumn();
    $active_users   = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE status='approved' AND is_suspended=0 AND role != 'super_admin'")->fetchColumn();
    $inactive_users = $total_users - $active_users;
} catch (PDOException $e) { $total_users = $active_users = $inactive_users = 0; }

try {
    $monthly_regs = $pdo->query("
        SELECT DATE_FORMAT(created_at,'%b %Y') AS month_label,
               DATE_FORMAT(created_at,'%Y-%m') AS month_key, COUNT(*) AS count
        FROM users WHERE role != 'super_admin'
          AND created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
        GROUP BY month_key, month_label ORDER BY month_key ASC
    ")->fetchAll();
} catch (PDOException $e) { $monthly_regs = []; }

try {
    $monthly_tenants = $pdo->query("
        SELECT DATE_FORMAT(created_at,'%b %Y') AS month_label,
               DATE_FORMAT(created_at,'%Y-%m') AS month_key, COUNT(*) AS count
        FROM tenants WHERE created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
        GROUP BY month_key, month_label ORDER BY month_key ASC
    ")->fetchAll();
} catch (PDOException $e) { $monthly_tenants = []; }

$plan_dist = ['Starter' => 0, 'Pro' => 0, 'Enterprise' => 0];
foreach ($tenants as $t) { if (isset($plan_dist[$t['plan']])) $plan_dist[$t['plan']]++; }

// ── FETCH SYSTEM SETTINGS ─────────────────────────────────────
$sys_settings = [];
try {
    $rows = $pdo->query("SELECT setting_key, setting_value FROM system_settings")->fetchAll();
    foreach ($rows as $row) { $sys_settings[$row['setting_key']] = $row['setting_value']; }
} catch (PDOException $e) { }

$ss = array_merge([
    'system_name'       => 'PawnHub',
    'system_tagline'    => 'Multi-Tenant Pawnshop Management',
    'starter_staff'     => 3,
    'starter_branches'  => 1,
    'pro_staff'         => 0,
    'pro_branches'      => 3,
    'ent_staff'         => 0,
    'ent_branches'      => 10,
    'starter_price'     => 'Free',
    'pro_price'         => '₱999/mo',
    'ent_price'         => '₱2,499/mo',
], $sys_settings);

// ── FILTERS ──────────────────────────────────────────────────
$report_type      = $_GET['report_type']    ?? 'tenant_activity';
$filter_date_from = $_GET['date_from']      ?? date('Y-m-01');
$filter_date_to   = $_GET['date_to']        ?? date('Y-m-d');
$filter_tenant    = intval($_GET['filter_tenant'] ?? 0);
$filter_status    = $_GET['filter_status']  ?? '';

$sales_period     = $_GET['sales_period']   ?? 'monthly';
$sales_date_from  = $_GET['sales_from']     ?? date('Y-m-01');
$sales_date_to    = $_GET['sales_to']       ?? date('Y-m-d');
$sales_tenant     = intval($_GET['sales_tenant'] ?? 0);

$audit_date_from  = $_GET['audit_from']     ?? date('Y-m-01');
$audit_date_to    = $_GET['audit_to']       ?? date('Y-m-d');
$audit_action     = $_GET['audit_action']   ?? '';
$audit_actor      = trim($_GET['audit_actor'] ?? '');
$audit_page       = max(1, intval($_GET['audit_page'] ?? 1));
$audit_per_page   = 50;

// ── REPORT DATA ───────────────────────────────────────────────
$report_data = [];
if ($active_page === 'reports') {
    try {
        if ($report_type === 'tenant_activity') {
            $q = "SELECT t.id, t.business_name, t.owner_name, t.email, t.phone,
                    t.plan, t.status, t.branches, t.created_at,
                    COUNT(DISTINCT u.id) AS user_count,
                    COUNT(DISTINCT CASE WHEN u.role='admin'   THEN u.id END) AS admin_count,
                    COUNT(DISTINCT CASE WHEN u.role='staff'   THEN u.id END) AS staff_count,
                    COUNT(DISTINCT CASE WHEN u.role='cashier' THEN u.id END) AS cashier_count
                  FROM tenants t LEFT JOIN users u ON u.tenant_id=t.id AND u.role != 'super_admin'
                  WHERE DATE(t.created_at) BETWEEN ? AND ?";
            $params = [$filter_date_from, $filter_date_to];
            if ($filter_status) { $q .= " AND t.status=?"; $params[] = $filter_status; }
            if ($filter_tenant) { $q .= " AND t.id=?";     $params[] = $filter_tenant; }
            $q .= " GROUP BY t.id ORDER BY t.created_at DESC";
            $s = $pdo->prepare($q); $s->execute($params); $report_data = $s->fetchAll();

        } elseif ($report_type === 'user_registration') {
            $q = "SELECT u.id, u.fullname, u.username, u.email, u.role,
                    u.status, u.is_suspended, u.created_at, t.business_name
                  FROM users u LEFT JOIN tenants t ON u.tenant_id=t.id
                  WHERE u.role != 'super_admin' AND DATE(u.created_at) BETWEEN ? AND ?";
            $params = [$filter_date_from, $filter_date_to];
            if ($filter_status) { $q .= " AND u.status=?";    $params[] = $filter_status; }
            if ($filter_tenant) { $q .= " AND u.tenant_id=?"; $params[] = $filter_tenant; }
            $q .= " ORDER BY u.created_at DESC";
            $s = $pdo->prepare($q); $s->execute($params); $report_data = $s->fetchAll();

        } elseif ($report_type === 'usage_statistics') {
            $q = "SELECT t.id, t.business_name, t.plan, t.status, t.branches,
                    COUNT(DISTINCT u.id) AS total_users,
                    COUNT(DISTINCT CASE WHEN u.role='admin'   THEN u.id END) AS admin_count,
                    COUNT(DISTINCT CASE WHEN u.role='staff'   THEN u.id END) AS staff_count,
                    COUNT(DISTINCT CASE WHEN u.role='cashier' THEN u.id END) AS cashier_count,
                    COUNT(DISTINCT CASE WHEN u.status='approved' AND u.is_suspended=0 THEN u.id END) AS active_users,
                    COUNT(DISTINCT CASE WHEN u.is_suspended=1 THEN u.id END) AS suspended_users
                  FROM tenants t LEFT JOIN users u ON u.tenant_id=t.id AND u.role != 'super_admin'
                  WHERE 1=1";
            $params = [];
            if ($filter_tenant) { $q .= " AND t.id=?"; $params[] = $filter_tenant; }
            $q .= " GROUP BY t.id ORDER BY total_users DESC";
            $s = $pdo->prepare($q); $s->execute($params); $report_data = $s->fetchAll();
        }
    } catch (PDOException $e) { $error_msg = 'Report error: ' . $e->getMessage(); }
}

// ── SALES REPORT DATA ─────────────────────────────────────────
$sales_data = $sales_summary = $sales_per_tenant = $top_tenants = $tx_history = [];
$sales_chart_labels = $sales_chart_data = [];

if ($active_page === 'sales_report') {
    try {
        $sq = "SELECT COUNT(*) AS total_transactions, COUNT(DISTINCT tenant_id) AS active_tenants,
                 COALESCE(SUM(loan_amount),0) AS total_revenue,
                 COALESCE(AVG(loan_amount),0) AS avg_transaction
               FROM pawn_transactions WHERE DATE(created_at) BETWEEN ? AND ?";
        $sp = [$sales_date_from, $sales_date_to];
        if ($sales_tenant) { $sq .= " AND tenant_id=?"; $sp[] = $sales_tenant; }
        $s = $pdo->prepare($sq); $s->execute($sp); $sales_summary = $s->fetch();

        if ($sales_period === 'daily') {
            $tq = "SELECT DATE(created_at) AS period_label, COUNT(*) AS tx_count, COALESCE(SUM(loan_amount),0) AS revenue FROM pawn_transactions WHERE DATE(created_at) BETWEEN ? AND ?";
        } elseif ($sales_period === 'weekly') {
            $tq = "SELECT CONCAT(YEAR(created_at),'-W',LPAD(WEEK(created_at),2,'0')) AS period_label, COUNT(*) AS tx_count, COALESCE(SUM(loan_amount),0) AS revenue FROM pawn_transactions WHERE DATE(created_at) BETWEEN ? AND ?";
        } else {
            $tq = "SELECT DATE_FORMAT(created_at,'%b %Y') AS period_label, DATE_FORMAT(created_at,'%Y-%m') AS sort_key, COUNT(*) AS tx_count, COALESCE(SUM(loan_amount),0) AS revenue FROM pawn_transactions WHERE DATE(created_at) BETWEEN ? AND ?";
        }
        $tp = [$sales_date_from, $sales_date_to];
        if ($sales_tenant) { $tq .= " AND tenant_id=?"; $tp[] = $sales_tenant; }
        $tq .= $sales_period === 'monthly' ? " GROUP BY sort_key, period_label ORDER BY sort_key ASC" : " GROUP BY period_label ORDER BY period_label ASC";
        $ts = $pdo->prepare($tq); $ts->execute($tp); $sales_data = $ts->fetchAll();
        $sales_chart_labels = array_column($sales_data, 'period_label');
        $sales_chart_data   = array_column($sales_data, 'revenue');

        $ptq = "SELECT t.business_name, t.plan,
                  COUNT(pt.id) AS tx_count, COALESCE(SUM(pt.loan_amount),0) AS revenue,
                  COALESCE(AVG(pt.loan_amount),0) AS avg_tx, MAX(pt.created_at) AS last_tx
                FROM tenants t LEFT JOIN pawn_transactions pt ON pt.tenant_id=t.id
                  AND DATE(pt.created_at) BETWEEN ? AND ?
                WHERE t.status='active'";
        $ptp = [$sales_date_from, $sales_date_to];
        if ($sales_tenant) { $ptq .= " AND t.id=?"; $ptp[] = $sales_tenant; }
        $ptq .= " GROUP BY t.id ORDER BY revenue DESC";
        $pts = $pdo->prepare($ptq); $pts->execute($ptp);
        $sales_per_tenant = $pts->fetchAll();
        $top_tenants      = array_slice($sales_per_tenant, 0, 5);

        $thq = "SELECT pt.*, t.business_name FROM pawn_transactions pt LEFT JOIN tenants t ON t.id=pt.tenant_id WHERE DATE(pt.created_at) BETWEEN ? AND ?";
        $thp = [$sales_date_from, $sales_date_to];
        if ($sales_tenant) { $thq .= " AND pt.tenant_id=?"; $thp[] = $sales_tenant; }
        $thq .= " ORDER BY pt.created_at DESC LIMIT 100";
        $ths = $pdo->prepare($thq); $ths->execute($thp); $tx_history = $ths->fetchAll();

    } catch (PDOException $e) {
        $error_msg = 'Sales report error: ' . $e->getMessage();
        $sales_summary = ['total_transactions'=>0,'active_tenants'=>0,'total_revenue'=>0,'avg_transaction'=>0];
    }
}

// ── AUDIT LOG DATA ────────────────────────────────────────────
$audit_logs = []; $audit_total = 0; $audit_total_pages = 1;

if ($active_page === 'audit_logs') {
    try {
        $aq = "SELECT * FROM audit_logs WHERE DATE(created_at) BETWEEN ? AND ?";
        $ap = [$audit_date_from, $audit_date_to];
        if ($audit_action) { $aq .= " AND action=?";              $ap[] = $audit_action; }
        if ($audit_actor)  { $aq .= " AND actor_username LIKE ?"; $ap[] = "%$audit_actor%"; }
        $count_q = str_replace("SELECT *", "SELECT COUNT(*)", $aq);
        $cs = $pdo->prepare($count_q); $cs->execute($ap);
        $audit_total       = (int)$cs->fetchColumn();
        $audit_total_pages = max(1, ceil($audit_total / $audit_per_page));
        $audit_page        = min($audit_page, $audit_total_pages);
        $offset            = ($audit_page - 1) * $audit_per_page;
        $aq .= " ORDER BY created_at DESC LIMIT $audit_per_page OFFSET $offset";
        $as = $pdo->prepare($aq); $as->execute($ap); $audit_logs = $as->fetchAll();
    } catch (PDOException $e) { $error_msg = 'Audit log error: ' . $e->getMessage(); }
}

$audit_actions_list = [];
try { $audit_actions_list = $pdo->query("SELECT DISTINCT action FROM audit_logs ORDER BY action ASC")->fetchAll(PDO::FETCH_COLUMN); } catch(PDOException $e){}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Digital Atelier — Super Admin</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
:root{
  --primary:#3b82f6;
  --primary-dark:#1e3a8a;
  --sidebar-w:256px;
  --white-5:rgba(255,255,255,0.05);
  --white-10:rgba(255,255,255,0.1);
  --white-15:rgba(255,255,255,0.15);
  --white-20:rgba(255,255,255,0.2);
  --white-30:rgba(255,255,255,0.3);
  --white-50:rgba(255,255,255,0.5);
  --white-60:rgba(255,255,255,0.6);
  --white-70:rgba(255,255,255,0.7);
}
html,body{min-height:100vh;}
body{
  font-family:'Inter',sans-serif;
  background-image:linear-gradient(rgba(0,0,0,0.45),rgba(0,0,0,0.45)),
    url('https://lh3.googleusercontent.com/aida-public/AB6AXuDVdOMy67RcI3OmEXQ5Ob4N9qbUXkHC8UCa3Ni6E2dPvn8N_9Kg_FuGSOcP4mhYkmmhNphJ8vQukLbFjfnVrv-wy716m8LpTRmRrql1K07LpfXVuqMeCMwQRftqZXZWikKdGhSBaHJEhrAn431mN9EQqELqupcBMhVrkknDFPIyVKW_l8bfki8PfvWSkOTQ129Z5jOMGF5My-stQnfPndc_y1X0jUHBEmlH0AVE04q2vpa87PHKNSxAOHabM4n8c9W6UcgA91Cs-1c');
  background-size:cover;
  background-position:center;
  background-attachment:fixed;
  color:#ffffff;
  display:flex;
  min-height:100vh;
  -webkit-font-smoothing:antialiased;
}

/* ── SIDEBAR ── */
.sidebar{
  width:var(--sidebar-w);
  min-height:100vh;
  position:fixed;left:0;top:0;bottom:0;z-index:100;
  display:flex;flex-direction:column;padding:24px;gap:8px;
  background:rgba(255,255,255,0.05);
  backdrop-filter:blur(20px);-webkit-backdrop-filter:blur(20px);
  border-right:1px solid var(--white-10);
}
.sb-brand{display:flex;align-items:center;gap:12px;margin-bottom:24px;}
.sb-logo{
  width:40px;height:40px;border-radius:12px;flex-shrink:0;
  background:rgba(59,130,246,0.2);
  border:1px solid var(--white-20);
  display:flex;align-items:center;justify-content:center;
}
.sb-logo .material-symbols-outlined{font-size:20px;color:#fff;}
.sb-name{font-size:1rem;font-weight:900;letter-spacing:-0.02em;color:#fff;line-height:1.1;}
.sb-sub{font-size:9px;text-transform:uppercase;letter-spacing:0.12em;font-weight:700;color:var(--white-50);}
.sb-nav{flex:1;display:flex;flex-direction:column;gap:4px;}
.sb-item{
  display:flex;align-items:center;gap:12px;padding:10px 12px;border-radius:12px;
  color:var(--white-60);font-size:13px;font-weight:500;text-decoration:none;
  transition:all 0.2s;cursor:pointer;position:relative;
}
.sb-item:hover{background:var(--white-20);color:#fff;transform:translateX(2px);}
.sb-item.active{background:var(--white-10);color:#fff;font-weight:600;box-shadow:0 0 0 1px var(--white-10);}
.sb-item .material-symbols-outlined{font-size:20px;flex-shrink:0;}
.sb-pill{
  margin-left:auto;background:#ef4444;color:#fff;
  font-size:10px;font-weight:700;padding:2px 7px;border-radius:100px;
}
.sb-divider{height:1px;background:var(--white-10);margin:8px 0;}
.sb-footer{display:flex;flex-direction:column;gap:4px;}
.sb-cta-btn{
  width:100%;padding:11px;background:var(--primary);color:#fff;
  border:none;border-radius:12px;font-family:'Inter',sans-serif;
  font-size:13px;font-weight:700;cursor:pointer;margin-bottom:8px;
  transition:all 0.2s;
}
.sb-cta-btn:hover{background:#2563eb;}
.sb-cta-btn:active{transform:scale(0.98);}
.sb-foot-link{
  display:flex;align-items:center;gap:12px;padding:10px 12px;border-radius:12px;
  color:var(--white-60);font-size:13px;font-weight:500;text-decoration:none;transition:all 0.2s;
}
.sb-foot-link:hover{color:#fff;}
.sb-foot-link .material-symbols-outlined{font-size:20px;}

/* ── MAIN ── */
.main{margin-left:var(--sidebar-w);flex:1;display:flex;flex-direction:column;min-height:100vh;}

/* ── TOPBAR ── */
.topbar{
  position:sticky;top:0;z-index:50;height:64px;
  display:flex;align-items:center;justify-content:space-between;padding:0 32px;
  background:rgba(255,255,255,0.05);
  backdrop-filter:blur(16px);-webkit-backdrop-filter:blur(16px);
  border-bottom:1px solid var(--white-10);
}
.topbar-search{
  position:relative;display:flex;align-items:center;
}
.topbar-search .material-symbols-outlined{
  position:absolute;left:12px;font-size:18px;color:var(--white-50);pointer-events:none;
}
.topbar-search input{
  background:var(--white-10);border:1px solid var(--white-10);border-radius:12px;
  padding:8px 16px 8px 38px;color:#fff;font-family:'Inter',sans-serif;font-size:13px;
  width:256px;outline:none;transition:all 0.2s;
}
.topbar-search input::placeholder{color:var(--white-50);}
.topbar-search input:focus{width:300px;border-color:rgba(59,130,246,0.5);background:var(--white-15);}
.topbar-actions{display:flex;align-items:center;gap:8px;}
.topbar-icon-btn{
  width:36px;height:36px;border-radius:8px;background:transparent;border:none;
  display:flex;align-items:center;justify-content:center;cursor:pointer;color:var(--white-60);
  transition:all 0.15s;
}
.topbar-icon-btn:hover{background:var(--white-10);color:#fff;}
.topbar-icon-btn .material-symbols-outlined{font-size:20px;}
.topbar-sep{width:1px;height:32px;background:var(--white-10);margin:0 4px;}
.topbar-user{display:flex;align-items:center;gap:10px;}
.topbar-user-info{text-align:right;}
.topbar-user-name{font-size:13px;font-weight:700;color:#fff;line-height:1.2;}
.topbar-user-role{font-size:10px;color:var(--white-50);font-weight:500;}
.topbar-avatar{
  width:40px;height:40px;border-radius:12px;object-fit:cover;
  border:2px solid var(--white-20);
}

/* ── CONTENT ── */
.content{padding:32px;flex:1;}

/* ── PAGE HEADER ── */
.page-header{margin-bottom:40px;}
.page-title{font-size:42px;font-weight:900;letter-spacing:-0.03em;color:#fff;line-height:1.1;margin-bottom:6px;}
.page-sub{font-size:16px;color:var(--white-70);font-weight:400;}

/* ── ALERTS ── */
.alert{
  padding:12px 18px;border-radius:12px;font-size:13px;margin-bottom:20px;
  display:flex;align-items:center;gap:8px;
  backdrop-filter:blur(10px);
}
.alert-success{background:rgba(34,197,94,0.15);border:1px solid rgba(34,197,94,0.3);color:#4ade80;}
.alert-error{background:rgba(239,68,68,0.15);border:1px solid rgba(239,68,68,0.3);color:#f87171;}

/* ── GLASS CARD ── */
.glass-card{
  background:var(--white-10);
  backdrop-filter:blur(12px);-webkit-backdrop-filter:blur(12px);
  border:1px solid var(--white-10);
  border-radius:16px;
  transition:transform 0.3s;
}
.glass-card:hover{transform:translateY(-2px);}

/* ── STATS GRID ── */
.stats-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:20px;margin-bottom:32px;}
.stat-card{padding:24px;border-radius:16px;position:relative;overflow:hidden;}
.stat-card-normal{
  background:var(--white-10);
  backdrop-filter:blur(12px);-webkit-backdrop-filter:blur(12px);
  border:1px solid var(--white-10);
  transition:transform 0.3s;
}
.stat-card-normal:hover{transform:translateY(-3px);}
.stat-card-featured{
  background:rgba(59,130,246,0.2);
  backdrop-filter:blur(12px);-webkit-backdrop-filter:blur(12px);
  border:1px solid var(--white-20);
  transition:transform 0.3s;
}
.stat-card-featured:hover{transform:translateY(-3px);}
.stat-card-featured .stat-glow{
  position:absolute;right:-16px;bottom:-16px;
  width:96px;height:96px;background:var(--white-10);border-radius:50%;filter:blur(20px);
  transition:transform 0.7s;
}
.stat-card-featured:hover .stat-glow{transform:scale(1.5);}
.stat-header{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:16px;position:relative;z-index:1;}
.stat-icon{
  width:44px;height:44px;border-radius:10px;background:var(--white-10);
  display:flex;align-items:center;justify-content:center;
}
.stat-icon .material-symbols-outlined{font-size:20px;}
.stat-badge{
  font-size:11px;font-weight:700;padding:3px 8px;border-radius:6px;
  position:relative;z-index:1;
}
.stat-badge-green{background:rgba(74,222,128,0.1);color:#4ade80;}
.stat-badge-blue{background:rgba(96,165,250,0.1);color:#60a5fa;}
.stat-badge-red{background:rgba(248,113,113,0.1);color:#f87171;}
.stat-badge-white{background:var(--white-20);color:#fff;}
.stat-label{font-size:13px;font-weight:500;color:var(--white-60);margin-bottom:4px;position:relative;z-index:1;}
.stat-value{font-size:30px;font-weight:800;color:#fff;letter-spacing:-0.02em;line-height:1;position:relative;z-index:1;}
.stat-icon-primary .material-symbols-outlined{color:var(--primary);}
.stat-icon-secondary .material-symbols-outlined{color:#bdc5eb;}
.stat-icon-orange .material-symbols-outlined{color:#ffb691;}
.stat-icon-white .material-symbols-outlined{color:#fff;}

/* ── MAIN GRID ── */
.main-grid{display:grid;grid-template-columns:2fr 1fr;gap:24px;margin-bottom:32px;}

/* ── TABLE CARD ── */
.table-card{
  background:var(--white-10);
  backdrop-filter:blur(12px);-webkit-backdrop-filter:blur(12px);
  border:1px solid var(--white-10);
  border-radius:20px;overflow:hidden;
}
.card-header{
  padding:28px 32px 20px;display:flex;justify-content:space-between;align-items:center;
  border-bottom:1px solid var(--white-10);
}
.card-title{font-size:17px;font-weight:700;color:#fff;}
.card-sub{font-size:12px;color:var(--white-50);margin-top:2px;}
.card-link{
  font-size:13px;font-weight:700;color:var(--primary);
  display:flex;align-items:center;gap:4px;text-decoration:none;
  transition:opacity 0.15s;
}
.card-link:hover{opacity:0.75;}
.card-link .material-symbols-outlined{font-size:16px;}
.glass-table{width:100%;border-collapse:collapse;}
.glass-table thead tr{background:rgba(255,255,255,0.03);}
.glass-table th{
  padding:14px 32px;font-size:10px;text-transform:uppercase;letter-spacing:0.1em;
  font-weight:800;color:rgba(255,255,255,0.35);text-align:left;white-space:nowrap;
}
.glass-table th:last-child{text-align:right;}
.glass-table td{
  padding:18px 32px;font-size:13px;vertical-align:middle;
  border-top:1px solid rgba(255,255,255,0.04);
}
.glass-table tr:hover td{background:rgba(255,255,255,0.03);}
.glass-table td:last-child{text-align:right;}
.tenant-row{display:flex;align-items:center;gap:12px;}
.tenant-icon{
  width:32px;height:32px;border-radius:8px;background:var(--white-10);
  border:1px solid var(--white-10);display:flex;align-items:center;justify-content:center;flex-shrink:0;
}
.tenant-icon .material-symbols-outlined{font-size:14px;color:var(--primary);}
.tenant-name{font-size:13px;font-weight:700;color:#fff;}
.action-text{font-size:13px;color:var(--white-60);}
.ts-text{font-size:12px;color:var(--white-50);font-weight:500;}

/* ── BADGES ── */
.badge{display:inline-flex;align-items:center;gap:4px;font-size:11px;font-weight:700;padding:3px 10px;border-radius:100px;}
.badge-dot{width:4px;height:4px;border-radius:50%;background:currentColor;}
.b-green{background:rgba(74,222,128,0.15);color:#4ade80;}
.b-yellow{background:rgba(251,191,36,0.15);color:#fbbf24;}
.b-blue{background:rgba(96,165,250,0.15);color:#60a5fa;}
.b-red{background:rgba(248,113,113,0.15);color:#f87171;}
.b-purple{background:rgba(167,139,250,0.15);color:#a78bfa;}
.b-gray{background:rgba(148,163,184,0.15);color:#94a3b8;}
.b-orange{background:rgba(251,146,60,0.15);color:#fb923c;}
.b-teal{background:rgba(52,211,153,0.15);color:#34d399;}
.plan-ent{background:rgba(96,165,250,0.15);color:#60a5fa;border:1px solid rgba(96,165,250,0.2);}
.plan-pro{background:rgba(251,191,36,0.15);color:#fbbf24;}
.plan-starter{background:rgba(148,163,184,0.15);color:#94a3b8;}

/* ── CHART CARD ── */
.chart-card{
  background:var(--white-10);
  backdrop-filter:blur(12px);-webkit-backdrop-filter:blur(12px);
  border:1px solid var(--white-10);
  border-radius:20px;padding:28px;
}
.chart-card-title{font-size:17px;font-weight:700;color:#fff;margin-bottom:4px;}
.chart-card-sub{font-size:12px;color:var(--white-50);margin-bottom:28px;}
.bar-chart-wrap{display:flex;align-items:flex-end;justify-content:space-between;height:160px;gap:6px;}
.bar-col{flex:1;display:flex;flex-direction:column;align-items:center;justify-content:flex-end;position:relative;group:true;}
.bar-bg{width:100%;background:rgba(255,255,255,0.04);border-radius:6px 6px 0 0;position:relative;cursor:pointer;transition:all 0.2s;}
.bar-bg:hover{background:rgba(59,130,246,0.3);}
.bar-fill{position:absolute;bottom:0;width:100%;border-radius:6px 6px 0 0;transition:all 0.3s;}
.bar-tooltip{
  display:none;position:absolute;top:-30px;left:50%;transform:translateX(-50%);
  background:#fff;color:#000;font-size:10px;font-weight:700;padding:3px 7px;border-radius:4px;
  white-space:nowrap;
}
.bar-col:hover .bar-tooltip{display:block;}
.bar-days{display:flex;justify-content:space-between;margin-top:12px;}
.bar-day{flex:1;text-align:center;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;color:rgba(255,255,255,0.25);}
.bar-day.today{color:var(--primary);font-weight:900;}
.chart-footer{margin-top:24px;padding:14px 16px;background:rgba(255,255,255,0.04);border-radius:12px;border:1px solid rgba(255,255,255,0.04);}
.chart-footer-top{display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;}
.chart-footer-label{font-size:12px;font-weight:700;color:#fff;}
.chart-footer-value{font-size:12px;font-weight:700;color:var(--primary);}
.progress-bar{width:100%;background:var(--white-10);height:5px;border-radius:100px;overflow:hidden;}
.progress-fill{height:100%;background:var(--primary);border-radius:100px;}

/* ── BOTTOM SECTION ── */
.bottom-grid{display:grid;grid-template-columns:1fr 2fr;gap:24px;}
.side-cards{display:flex;flex-direction:column;gap:20px;}
.side-card{
  background:var(--white-10);
  backdrop-filter:blur(12px);-webkit-backdrop-filter:blur(12px);
  border:1px solid var(--white-10);
  border-radius:16px;padding:22px;
}
.side-card-header{display:flex;align-items:center;gap:14px;margin-bottom:16px;}
.side-card-icon{
  width:48px;height:48px;border-radius:12px;background:rgba(59,130,246,0.15);
  border:1px solid var(--white-10);display:flex;align-items:center;justify-content:center;
  flex-shrink:0;
}
.side-card-icon .material-symbols-outlined{font-size:22px;color:var(--primary);}
.side-card-title{font-size:14px;font-weight:700;color:#fff;}
.side-card-status{font-size:11px;font-weight:700;color:#4ade80;}
.side-card-body{font-size:12px;color:var(--white-60);line-height:2;}
.pending-avatars{display:flex;margin-bottom:12px;}
.pending-avatars img,.pending-avatars .av-extra{
  width:40px;height:40px;border-radius:50%;border:2px solid rgba(0,0,0,0.4);
  margin-right:-10px;flex-shrink:0;
}
.pending-avatars .av-extra{
  background:var(--white-10);display:flex;align-items:center;justify-content:center;
  font-size:10px;font-weight:700;color:var(--white-60);
}
.pending-note{font-size:11px;color:var(--white-50);margin-top:8px;line-height:1.6;}
.featured-card{
  background:rgba(0,0,0,0.3);
  backdrop-filter:blur(24px);-webkit-backdrop-filter:blur(24px);
  border:1px solid var(--white-10);
  border-radius:24px;padding:32px;
  position:relative;overflow:hidden;min-height:280px;
  display:flex;flex-direction:column;justify-content:space-between;
}
.featured-bg{
  position:absolute;inset:0;width:100%;height:100%;object-fit:cover;
  mix-blend-mode:overlay;opacity:0.25;
}
.featured-tag{
  position:relative;z-index:1;display:inline-block;
  padding:4px 12px;background:var(--white-20);border-radius:100px;
  font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:0.1em;
  color:#fff;margin-bottom:16px;
}
.featured-title{
  position:relative;z-index:1;font-size:32px;font-weight:900;
  letter-spacing:-0.03em;line-height:1.1;color:#fff;margin-bottom:14px;max-width:360px;
}
.featured-desc{
  position:relative;z-index:1;font-size:13px;color:var(--white-70);
  max-width:380px;line-height:1.7;
}
.featured-footer{position:relative;z-index:1;display:flex;align-items:center;gap:24px;margin-top:24px;}
.featured-stat{}
.featured-stat-label{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;color:var(--white-50);}
.featured-stat-value{font-size:18px;font-weight:700;color:#fff;}
.featured-divider{width:1px;height:40px;background:var(--white-20);}
.featured-btn{
  padding:10px 22px;background:#fff;color:#000;border:none;border-radius:12px;
  font-family:'Inter',sans-serif;font-size:13px;font-weight:700;cursor:pointer;
  box-shadow:0 8px 24px rgba(0,0,0,0.3);transition:all 0.2s;
}
.featured-btn:hover{background:rgba(255,255,255,0.9);}
.featured-btn:active{transform:scale(0.97);}

/* ── FAB ── */
.fab{
  position:fixed;bottom:32px;right:32px;z-index:50;
  width:64px;height:64px;background:var(--primary);color:#fff;
  border:1px solid var(--white-20);border-radius:18px;
  display:flex;align-items:center;justify-content:center;
  box-shadow:0 8px 32px rgba(59,130,246,0.4);cursor:pointer;
  transition:all 0.3s;
}
.fab:hover{transform:translateY(-6px);}
.fab .material-symbols-outlined{font-size:24px;transition:transform 0.5s;}
.fab:hover .material-symbols-outlined{transform:rotate(90deg);}

/* ── INNER PAGES — white-on-dark tables ── */
.inner-card{
  background:var(--white-10);
  backdrop-filter:blur(12px);-webkit-backdrop-filter:blur(12px);
  border:1px solid var(--white-10);
  border-radius:16px;margin-bottom:20px;overflow:hidden;
}
.inner-card-pad{padding:22px 24px;}
.inner-card-title{font-size:13px;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;color:var(--white-60);}
.inner-card table{width:100%;border-collapse:collapse;}
.inner-card th{
  padding:10px 16px;font-size:10px;text-transform:uppercase;letter-spacing:0.08em;
  font-weight:700;color:rgba(255,255,255,0.3);text-align:left;
  background:rgba(255,255,255,0.03);border-bottom:1px solid var(--white-10);
}
.inner-card td{
  padding:13px 16px;font-size:13px;color:var(--white-70);
  border-bottom:1px solid rgba(255,255,255,0.04);vertical-align:middle;
}
.inner-card tr:last-child td{border-bottom:none;}
.inner-card tr:hover td{background:rgba(255,255,255,0.03);}
.inner-card tfoot td{
  background:rgba(255,255,255,0.05);font-weight:700;font-size:12px;color:#fff;
}

/* ── FILTER BAR ── */
.filter-bar{
  display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:20px;
  background:var(--white-10);backdrop-filter:blur(12px);
  border:1px solid var(--white-10);border-radius:12px;padding:14px 18px;
}
.filter-bar label{font-size:12px;font-weight:600;color:var(--white-50);white-space:nowrap;}
.filter-select,.filter-input{
  background:var(--white-10);border:1px solid var(--white-10);border-radius:8px;
  padding:7px 11px;font-family:'Inter',sans-serif;font-size:12px;color:#fff;
  outline:none;transition:border 0.2s;
}
.filter-select option{background:#1e293b;color:#fff;}
.filter-select:focus,.filter-input:focus{border-color:rgba(59,130,246,0.5);}
.filter-input::placeholder{color:var(--white-30);}

/* ── SUMMARY CARDS ── */
.summary-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:20px;}
.summary-grid-3{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-bottom:20px;}
.summary-item{
  background:var(--white-10);border:1px solid var(--white-10);border-radius:12px;
  padding:16px;text-align:center;
  backdrop-filter:blur(12px);
}
.summary-num{font-size:24px;font-weight:800;color:#fff;}
.summary-lbl{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;color:var(--white-50);margin-top:4px;}

/* ── BUTTONS ── */
.btn-sm{
  padding:6px 13px;border-radius:8px;font-size:12px;font-weight:600;cursor:pointer;
  border:1px solid var(--white-20);background:var(--white-10);color:#fff;
  text-decoration:none;display:inline-flex;align-items:center;gap:5px;
  transition:all 0.15s;font-family:'Inter',sans-serif;
}
.btn-sm:hover{background:var(--white-20);}
.btn-primary{background:var(--primary);border-color:var(--primary);color:#fff;}
.btn-primary:hover{background:#2563eb;border-color:#2563eb;}
.btn-success{background:rgba(22,163,74,0.3);border-color:rgba(22,163,74,0.4);color:#4ade80;}
.btn-success:hover{background:rgba(22,163,74,0.5);}
.btn-danger{background:rgba(220,38,38,0.3);border-color:rgba(220,38,38,0.4);color:#f87171;}
.btn-danger:hover{background:rgba(220,38,38,0.5);}
.btn-warning{background:rgba(217,119,6,0.3);border-color:rgba(217,119,6,0.4);color:#fbbf24;}
.btn-warning:hover{background:rgba(217,119,6,0.5);}

/* ── EMPTY STATE ── */
.empty-state{text-align:center;padding:48px 20px;color:var(--white-30);}
.empty-state .material-symbols-outlined{font-size:40px;margin-bottom:12px;display:block;opacity:0.4;}
.empty-state p{font-size:14px;}

/* ── REPORT TABS ── */
.report-tabs{display:flex;gap:8px;margin-bottom:20px;flex-wrap:wrap;}
.report-tab{
  padding:9px 18px;border-radius:10px;font-size:13px;font-weight:700;
  text-decoration:none;border:1.5px solid var(--white-20);
  background:var(--white-10);color:var(--white-60);
  transition:all 0.15s;backdrop-filter:blur(8px);
}
.report-tab:hover{background:var(--white-20);color:#fff;}
.report-tab.active{border-color:var(--primary);background:rgba(59,130,246,0.15);color:#60a5fa;}

/* ── PAGINATION ── */
.pagination{display:flex;align-items:center;gap:6px;margin-top:16px;flex-wrap:wrap;}
.page-btn{
  padding:5px 12px;border-radius:7px;font-size:12px;font-weight:600;
  border:1px solid var(--white-20);background:var(--white-10);color:var(--white-60);
  text-decoration:none;transition:all 0.15s;
}
.page-btn:hover{background:var(--white-20);color:#fff;}
.page-btn.active{background:var(--primary);border-color:var(--primary);color:#fff;}

/* ── MODALS ── */
.modal-overlay{
  display:none;position:fixed;inset:0;z-index:999;
  align-items:center;justify-content:center;
  background:rgba(0,0,0,0.6);backdrop-filter:blur(8px);
}
.modal-overlay.open{display:flex;}
.modal{
  background:rgba(15,23,42,0.95);
  backdrop-filter:blur(24px);-webkit-backdrop-filter:blur(24px);
  border:1px solid var(--white-15);border-radius:20px;
  width:480px;max-width:95vw;max-height:92vh;overflow-y:auto;
  box-shadow:0 24px 80px rgba(0,0,0,0.5);
  animation:mIn 0.22s ease both;
}
.modal-wide{width:560px;}
@keyframes mIn{from{opacity:0;transform:translateY(14px)}to{opacity:1;transform:none}}
.mhdr{padding:22px 24px 0;display:flex;align-items:flex-start;justify-content:space-between;}
.mtitle{font-size:17px;font-weight:800;color:#fff;}
.msub{font-size:12px;color:var(--white-50);margin-top:3px;}
.mclose{
  width:30px;height:30px;border-radius:8px;border:1px solid var(--white-20);
  background:var(--white-10);cursor:pointer;display:flex;align-items:center;justify-content:center;
  color:var(--white-60);flex-shrink:0;
}
.mclose:hover{background:var(--white-20);color:#fff;}
.mclose .material-symbols-outlined{font-size:16px;}
.mbody{padding:20px 24px 24px;}
.flabel{display:block;font-size:12px;font-weight:600;color:var(--white-60);margin-bottom:5px;}
.finput{
  width:100%;background:var(--white-10);border:1.5px solid var(--white-10);
  border-radius:10px;padding:10px 13px;font-family:'Inter',sans-serif;
  font-size:13px;color:#fff;outline:none;transition:border 0.2s;
}
.finput:focus{border-color:rgba(59,130,246,0.5);background:var(--white-15);}
.finput::placeholder{color:var(--white-30);}
select.finput{cursor:pointer;}
select.finput option{background:#1e293b;}
.form-hint{font-size:11px;color:var(--white-40,rgba(255,255,255,0.4));margin-top:4px;}
.modal-info{
  padding:12px 14px;border-radius:10px;font-size:12px;line-height:1.7;margin-bottom:16px;
}
.modal-info-green{background:rgba(34,197,94,0.1);border:1px solid rgba(34,197,94,0.2);color:#4ade80;}
.modal-info-blue{background:rgba(59,130,246,0.1);border:1px solid rgba(59,130,246,0.2);color:#93c5fd;}
.modal-info-red{background:rgba(239,68,68,0.1);border:1px solid rgba(239,68,68,0.2);color:#f87171;}

/* ── SETTINGS ── */
.settings-section{
  background:var(--white-10);backdrop-filter:blur(12px);
  border:1px solid var(--white-10);border-radius:16px;padding:24px;margin-bottom:20px;
}
.settings-section-title{font-size:13px;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;color:var(--white-60);margin-bottom:20px;}
.plan-card-wrap{border:1.5px solid var(--white-10);border-radius:14px;overflow:hidden;}
.plan-card-head{padding:14px 18px;border-bottom:1px solid var(--white-10);}
.plan-card-body{padding:18px;display:flex;flex-direction:column;gap:14px;}
.plan-features{background:var(--white-5);border-radius:10px;padding:12px 14px;font-size:12px;color:var(--white-60);line-height:2;}

/* ── CHART CANVAS ── */
.chart-wrap{position:relative;height:220px;}
.donut-wrap{position:relative;height:200px;display:flex;align-items:center;justify-content:center;}
.legend-row{display:flex;align-items:center;gap:8px;font-size:12px;color:var(--white-60);margin-bottom:5px;}
.legend-dot{width:10px;height:10px;border-radius:50%;flex-shrink:0;}
.two-col{display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:20px;}

/* ── AUDIT ACTION BADGES ── */
.act-activate{background:rgba(34,197,94,0.15);color:#4ade80;}
.act-reject{background:rgba(239,68,68,0.15);color:#f87171;}
.act-login{background:rgba(167,139,250,0.15);color:#a78bfa;}
.act-logout{background:rgba(148,163,184,0.15);color:#94a3b8;}
.act-create{background:rgba(52,211,153,0.15);color:#34d399;}
.act-update{background:rgba(251,191,36,0.15);color:#fbbf24;}
.act-other{background:rgba(148,163,184,0.15);color:#94a3b8;}

/* ── RANK ── */
.rank-1{background:rgba(251,191,36,0.15);color:#fbbf24;border:1px solid rgba(251,191,36,0.2);}
.rank-2{background:rgba(148,163,184,0.15);color:#94a3b8;border:1px solid rgba(148,163,184,0.2);}
.rank-3{background:rgba(251,146,60,0.15);color:#fb923c;border:1px solid rgba(251,146,60,0.2);}

/* ── PROGRESS BARS (tenant status) ── */
.progress-row{margin-bottom:12px;}
.progress-row-head{display:flex;justify-content:space-between;font-size:13px;font-weight:600;margin-bottom:5px;color:#fff;}
.progress-row-val{font-weight:700;}
.progress-bg{height:7px;background:rgba(255,255,255,0.08);border-radius:100px;overflow:hidden;}
.progress-inner{height:100%;border-radius:100px;}

.ticket-tag{font-family:monospace;font-size:12px;color:#60a5fa;font-weight:700;}

@media(max-width:1200px){
  .stats-grid,.summary-grid{grid-template-columns:repeat(2,1fr);}
  .main-grid,.two-col,.bottom-grid{grid-template-columns:1fr;}
}
@media(max-width:600px){
  .stats-grid,.summary-grid,.summary-grid-3{grid-template-columns:1fr;}
  .content{padding:16px;}
  .sidebar{display:none;}
  .main{margin-left:0;}
}
</style>
</head>
<body>

<!-- ══ SIDEBAR ══════════════════════════════════════════════════ -->
<aside class="sidebar">
  <div class="sb-brand">
    <div class="sb-logo"><span class="material-symbols-outlined">diamond</span></div>
    <div>
      <div class="sb-name">Atelier Admin</div>
      <div class="sb-sub">Fintech Gallery</div>
    </div>
  </div>

  <nav class="sb-nav">
    <a href="?page=dashboard" class="sb-item <?= $active_page==='dashboard'?'active':'' ?>">
      <span class="material-symbols-outlined">dashboard</span>Dashboard
    </a>
    <a href="?page=tenants" class="sb-item <?= $active_page==='tenants'?'active':'' ?>">
      <span class="material-symbols-outlined">storefront</span>Assets
      <?php if($pending_tenants>0):?><span class="sb-pill"><?=$pending_tenants?></span><?php endif;?>
    </a>
    <a href="?page=invitations" class="sb-item <?= $active_page==='invitations'?'active':'' ?>">
      <span class="material-symbols-outlined">payments</span>Loans
      <?php if($pending_inv>0):?><span class="sb-pill"><?=$pending_inv?></span><?php endif;?>
    </a>
    <a href="?page=reports" class="sb-item <?= $active_page==='reports'?'active':'' ?>">
      <span class="material-symbols-outlined">gavel</span>Appraisals
    </a>
    <a href="?page=sales_report" class="sb-item <?= $active_page==='sales_report'?'active':'' ?>">
      <span class="material-symbols-outlined">group</span>Users
    </a>
    <a href="?page=audit_logs" class="sb-item <?= $active_page==='audit_logs'?'active':'' ?>">
      <span class="material-symbols-outlined">analytics</span>Reports
    </a>
  </nav>

  <div class="sb-divider"></div>
  <div class="sb-footer">
    <button class="sb-cta-btn" onclick="document.getElementById('addTenantModal').classList.add('open')">
      New Appraisal
    </button>
    <a href="#" class="sb-foot-link"><span class="material-symbols-outlined">help</span>Support</a>
    <a href="logout.php" class="sb-foot-link"><span class="material-symbols-outlined">logout</span>Logout</a>
  </div>
</aside>

<!-- ══ MAIN ══════════════════════════════════════════════════════ -->
<div class="main">

  <!-- TOP BAR -->
  <header class="topbar">
    <div class="topbar-search">
      <span class="material-symbols-outlined">search</span>
      <input type="text" placeholder="Search the atelier...">
    </div>
    <div class="topbar-actions">
      <button class="topbar-icon-btn"><span class="material-symbols-outlined">notifications</span></button>
      <button class="topbar-icon-btn"><span class="material-symbols-outlined">account_balance_wallet</span></button>
      <button class="topbar-icon-btn"><span class="material-symbols-outlined">settings</span></button>
      <div class="topbar-sep"></div>
      <div class="topbar-user">
        <div class="topbar-user-info">
          <div class="topbar-user-name">Super Admin</div>
          <div class="topbar-user-role">Platform Controller</div>
        </div>
        <img class="topbar-avatar" src="https://lh3.googleusercontent.com/aida-public/AB6AXuBofIEhgwY1dmHA-jeaGTArW7UseE_KpTLhMi9Q9WJOo0tkFMtESE9vIKHoSPYUvsDthhTDRvYDTZSHZ3FFR9fWgnIfFHUtp69ukhpPno7qy2cFmb_zg5Sw4CC67ZM_4CapI5gdpt9rulcfK8lC0QlMmSUxJoGj1PyWLvh0bmQjClY4XLnaMPvke5krnMznHbkI0GsR85BGftYRKCUDggH9WM6z_Upmmszb2RgtP8J4qdC7jVNTazcJerIJMFO-Nb3iWdXLy0VROGs" alt="Admin">
      </div>
    </div>
  </header>

  <!-- CONTENT -->
  <div class="content">

    <?php if($success_msg):?><div class="alert alert-success"><?=htmlspecialchars($success_msg)?></div><?php endif;?>
    <?php if($error_msg):?><div class="alert alert-error"><?=htmlspecialchars($error_msg)?></div><?php endif;?>

    <!-- ══ DASHBOARD ═══════════════════════════════════════════ -->
    <?php if($active_page==='dashboard'): ?>

    <section class="page-header">
      <h2 class="page-title">Platform Overview</h2>
      <p class="page-sub">Real-time performance metrics across the Digital Atelier ecosystem.</p>
    </section>

    <!-- STATS BENTO -->
    <div class="stats-grid">
      <div class="stat-card stat-card-normal glass-card">
        <div class="stat-header">
          <div class="stat-icon stat-icon-primary"><span class="material-symbols-outlined">storefront</span></div>
          <span class="stat-badge stat-badge-green">+12%</span>
        </div>
        <div class="stat-label">Total Pawnshops</div>
        <div class="stat-value"><?= number_format($total_tenants) ?></div>
      </div>
      <div class="stat-card stat-card-normal glass-card">
        <div class="stat-header">
          <div class="stat-icon stat-icon-secondary"><span class="material-symbols-outlined">badge</span></div>
          <span class="stat-badge stat-badge-blue">+4</span>
        </div>
        <div class="stat-label">Admin Users</div>
        <div class="stat-value"><?= $total_users ?></div>
      </div>
      <div class="stat-card stat-card-normal glass-card">
        <div class="stat-header">
          <div class="stat-icon stat-icon-orange"><span class="material-symbols-outlined">confirmation_number</span></div>
          <span class="stat-badge stat-badge-red">-2.4%</span>
        </div>
        <div class="stat-label">Active Tickets</div>
        <div class="stat-value"><?= number_format($active_tenants) ?></div>
      </div>
      <div class="stat-card stat-card-featured">
        <div class="stat-glow"></div>
        <div class="stat-header">
          <div class="stat-icon stat-icon-white"><span class="material-symbols-outlined">payments</span></div>
          <span class="stat-badge stat-badge-white">+24%</span>
        </div>
        <div class="stat-label" style="color:rgba(255,255,255,0.7);">Platform Revenue</div>
        <div class="stat-value">$2.4M</div>
      </div>
    </div>

    <!-- MAIN GRID: TABLE + CHART -->
    <div class="main-grid">

      <!-- RECENT TENANT ACTIVITY TABLE -->
      <div class="table-card">
        <div class="card-header">
          <div>
            <div class="card-title">Recent Tenant Activity</div>
            <div class="card-sub">Monitoring ecosystem interactions</div>
          </div>
          <a href="?page=tenants" class="card-link">View All Logs <span class="material-symbols-outlined">arrow_forward</span></a>
        </div>
        <table class="glass-table">
          <thead>
            <tr>
              <th>Tenant / Pawnshop</th>
              <th>Action</th>
              <th>Status</th>
              <th>Timestamp</th>
            </tr>
          </thead>
          <tbody>
            <?php if(empty($tenants)): ?>
            <tr><td colspan="4" style="text-align:center;padding:32px;color:var(--white-50);">No tenants yet.</td></tr>
            <?php else: foreach(array_slice($tenants,0,5) as $t): ?>
            <tr>
              <td>
                <div class="tenant-row">
                  <div class="tenant-icon"><span class="material-symbols-outlined">store</span></div>
                  <span class="tenant-name"><?=htmlspecialchars($t['business_name'])?></span>
                </div>
              </td>
              <td><span class="action-text"><?=htmlspecialchars($t['status']==='active'?'Active Tenant':($t['status']==='pending'?'Pending Approval':'Inactive'))?></span></td>
              <td>
                <span class="badge <?=$t['status']==='active'?'b-green':($t['status']==='pending'?'b-yellow':'b-red')?>">
                  <span class="badge-dot"></span><?=ucfirst($t['status'])?>
                </span>
              </td>
              <td><span class="ts-text"><?=date('M d, Y',strtotime($t['created_at']))?></span></td>
            </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>

      <!-- SYSTEM ACTIVITY CHART -->
      <div class="chart-card">
        <div class="chart-card-title">System Activity</div>
        <div class="chart-card-sub">Weekly transaction volume</div>
        <div class="bar-chart-wrap" id="barChartWrap">
          <?php
          $bars=[['45%','45k'],['65%','65k'],['35%','35k'],['85%','85k'],['55%','55k'],['70%','70k'],['95%','92k']];
          $days=['Mon','Tue','Wed','Thu','Fri','Sat','Sun'];
          foreach($bars as $i=>[$h,$lbl]):
            $isToday=($i===6);
            $bg=$isToday?'rgba(59,130,246,0.6)':'rgba(59,130,246,0.25)';
            $fill=$isToday?'rgba(255,255,255,0.2)':'rgba(59,130,246,0.5)';
          ?>
          <div class="bar-col">
            <div class="bar-bg" style="height:160px;background:rgba(255,255,255,0.04);border-radius:6px 6px 0 0;width:100%;position:relative;">
              <div class="bar-fill" style="height:<?=$h?>;background:<?=$bg?>;bottom:0;position:absolute;width:100%;border-radius:6px 6px 0 0;"></div>
              <?php if($isToday):?><div style="position:absolute;top:-28px;left:50%;transform:translateX(-50%);background:#fff;color:#000;font-size:10px;font-weight:700;padding:2px 6px;border-radius:4px;white-space:nowrap;"><?=$lbl?></div><?php endif;?>
              <div class="bar-tooltip"><?=$lbl?></div>
            </div>
          </div>
          <?php endforeach;?>
        </div>
        <div class="bar-days">
          <?php foreach($days as $i=>$d):?>
          <div class="bar-day <?=$i===6?'today':''?>"><?=$d?></div>
          <?php endforeach;?>
        </div>
        <div class="chart-footer">
          <div class="chart-footer-top">
            <span class="chart-footer-label">Total Volume</span>
            <span class="chart-footer-value">$412,800.00</span>
          </div>
          <div class="progress-bar"><div class="progress-fill" style="width:78%;"></div></div>
        </div>
      </div>

    </div><!-- /main-grid -->

    <!-- BOTTOM SECTION -->
    <div class="bottom-grid">
      <div class="side-cards">

        <!-- SYSTEM HEALTH -->
        <div class="side-card">
          <div class="side-card-header">
            <div class="side-card-icon"><span class="material-symbols-outlined">trending_up</span></div>
            <div>
              <div class="side-card-title">System Health</div>
              <div class="side-card-status">Optimal Performance</div>
            </div>
          </div>
          <div class="side-card-body">
            Latency: 24ms<br>
            Uptime: 99.998%<br>
            Last Audit: Today, 04:00 AM
          </div>
        </div>

        <!-- PENDING VERIFICATIONS -->
        <div class="side-card">
          <div class="side-card-title" style="margin-bottom:14px;">Pending Verifications</div>
          <div class="pending-avatars">
            <img src="https://lh3.googleusercontent.com/aida-public/AB6AXuBeVwpvhp8DHl08Zu3HZPuZdj_3VAYpITWDKaYEOoM_ccsaEA_ugNbxg3IUk67gJaxdIfFSRFllIouyZhpXQ6pM4VGJQF4nezo-p8_uOvEsHVT4vFi4iJX0QnO__QkkI36atMKUX03X6KoBxCEY61In1xvBTx9l3SUMtHbSZbmVxjT1wrhaEAmtcN83zXY7kIZa4zYIWI0LOzIHfe8gWalZR8TnZhQcHBdsaazhUgE7sdrdDZ2Tqi6co5mUUfz5AU4_IYwvKRNgWPQ" alt="">
            <img src="https://lh3.googleusercontent.com/aida-public/AB6AXuD8QblRL-RTebXUXK1yzvId33x7gUEBNQBYZRZRi2ZPVgybee54CAPmhNxPvaO8wD9iNnNT8V2usG4Ks5pMxnDvjia8iig6tu1M3kP4zth0Yvc8BZpy-jav09hYmYM37mtrYZdByWrGdb0BQWoI0LEFm0-7Yak-fsPDijZBBbA23Qf-MezzHdesbz2tzBXj8QIf3x2wKu6pe78Yb7ybixpqh06ybf0uoRsDf76KBTNx_oSFjPaYcC3S33gw7cpcUhyluWBLtJGUkVA" alt="">
            <img src="https://lh3.googleusercontent.com/aida-public/AB6AXuCAFtFVcLiC4owMchYV5nlOhpf-UvqHNR1P6HgJnLz-wpsi5aGhGywD4DSzv7UHvHW9f5nzN3gS4uH7HJuagMql6je36c7UmmgjRItfUWHcJq-85QQEGtAiGz__UiuFCIoW4QTcgeZroadTgdKjf_BAJrQsuGe4bWFZkoSacsQJYTmxMiG2qZSTCBstJaMxfiLPwzRSvWJa45I0M2Xo6mtuPkZAvI-pNM7B1bR0LgoaRC8g49pNHGPODTS5rdQPsgPkHFqPrmvzfbw" alt="">
            <div class="av-extra">+18</div>
          </div>
          <div class="pending-note">Click to review identification documents for high-tier pawn shop applicants.</div>
        </div>

      </div>

      <!-- FEATURED HIGHLIGHT -->
      <div class="featured-card">
        <img class="featured-bg" src="https://lh3.googleusercontent.com/aida-public/AB6AXuBWpTdsvEPMlA5_sn4oQnRwO8WVUaOL0AYv1As0hTSlX-YzPMdq1xbmf_rc1B-7RFbowlRexOxuP0KAn50siBOa_QbltpxkXsov7e2fZ5CkdKDSqaTmyZ7T9pcHNdnxRwm-JA4Wuf0mAYOq3IV1wfk703HgI5DmQZg1fMfdfrfZ4d0HtPcNCmiAJTksMpYY_i_uTE19zpQpZBgRP63BGrpX5L8UFQcj7PLihhAJ0mFAq9OThA-uxJ1CZh0oLR9VRo04HnrOSTxcvRo" alt="">
        <div>
          <div class="featured-tag">Featured Highlight</div>
          <h4 class="featured-title">Digitalizing the World's Heritage Assets</h4>
          <p class="featured-desc">We are currently processing the authentication of the Mediterranean Jewelry Collection. The estimated ecosystem value injection is projected at $14.2M.</p>
        </div>
        <div class="featured-footer">
          <div class="featured-stat">
            <div class="featured-stat-label">Global Status</div>
            <div class="featured-stat-value">Verifying...</div>
          </div>
          <div class="featured-divider"></div>
          <button class="featured-btn">Deep Audit File</button>
        </div>
      </div>

    </div><!-- /bottom-grid -->

    <!-- ADDITIONAL DASHBOARD CHARTS -->
    <div class="two-col" style="margin-top:24px;">
      <div class="inner-card inner-card-pad">
        <div class="inner-card-title" style="margin-bottom:16px;">👥 User Growth (6 Months)</div>
        <div class="chart-wrap"><canvas id="userGrowthChart"></canvas></div>
      </div>
      <div class="inner-card inner-card-pad">
        <div class="inner-card-title" style="margin-bottom:16px;">🏢 New Tenants (6 Months)</div>
        <div class="chart-wrap"><canvas id="tenantActivityChart"></canvas></div>
      </div>
    </div>
    <div class="two-col">
      <div class="inner-card inner-card-pad">
        <div class="inner-card-title" style="margin-bottom:16px;">👤 User Role Distribution</div>
        <?php try{$rc=$pdo->query("SELECT role,COUNT(*) AS cnt FROM users WHERE role!='super_admin' GROUP BY role")->fetchAll(PDO::FETCH_KEY_PAIR);}catch(PDOException $e){$rc=[];}
        $rca=(int)($rc['admin']??0);$rcs=(int)($rc['staff']??0);$rcc=(int)($rc['cashier']??0);?>
        <div style="display:flex;align-items:center;gap:24px;">
          <div class="donut-wrap" style="flex:1;"><canvas id="roleDonutChart"></canvas></div>
          <div>
            <div class="legend-row"><span class="legend-dot" style="background:#3b82f6;"></span>Admin — <?=$rca?></div>
            <div class="legend-row"><span class="legend-dot" style="background:#4ade80;"></span>Staff — <?=$rcs?></div>
            <div class="legend-row"><span class="legend-dot" style="background:#fbbf24;"></span>Cashier — <?=$rcc?></div>
            <div style="margin-top:10px;font-size:11px;color:var(--white-50);border-top:1px solid var(--white-10);padding-top:8px;">Total: <?=$rca+$rcs+$rcc?> users</div>
          </div>
        </div>
      </div>
      <div class="inner-card inner-card-pad">
        <div class="inner-card-title" style="margin-bottom:16px;">⭐ Plan Distribution</div>
        <div style="display:flex;align-items:center;gap:24px;">
          <div class="donut-wrap" style="flex:1;"><canvas id="planDonutChart"></canvas></div>
          <div>
            <div class="legend-row"><span class="legend-dot" style="background:#94a3b8;"></span>Starter — <?=$plan_dist['Starter']?></div>
            <div class="legend-row"><span class="legend-dot" style="background:#fbbf24;"></span>Pro — <?=$plan_dist['Pro']?></div>
            <div class="legend-row"><span class="legend-dot" style="background:#a78bfa;"></span>Enterprise — <?=$plan_dist['Enterprise']?></div>
            <div style="margin-top:10px;font-size:11px;color:var(--white-50);border-top:1px solid var(--white-10);padding-top:8px;">Total: <?=$total_tenants?> tenants</div>
          </div>
        </div>
      </div>
    </div>

    <script>
    const chartDefaults={plugins:{legend:{display:false}},scales:{x:{grid:{display:false},ticks:{font:{size:10},color:'rgba(255,255,255,0.3)'}},y:{grid:{color:'rgba(255,255,255,0.05)'},ticks:{font:{size:10},color:'rgba(255,255,255,0.3)'},beginAtZero:true}}};
    new Chart(document.getElementById('userGrowthChart'),{type:'bar',data:{labels:<?=json_encode(array_column($monthly_regs,'month_label'))?>,datasets:[{data:<?=json_encode(array_column($monthly_regs,'count'))?>,backgroundColor:'rgba(59,130,246,0.2)',borderColor:'#3b82f6',borderWidth:2,borderRadius:6}]},options:{...chartDefaults,responsive:true,maintainAspectRatio:false}});
    new Chart(document.getElementById('tenantActivityChart'),{type:'bar',data:{labels:<?=json_encode(array_column($monthly_tenants,'month_label'))?>,datasets:[{data:<?=json_encode(array_column($monthly_tenants,'count'))?>,backgroundColor:'rgba(74,222,128,0.15)',borderColor:'#4ade80',borderWidth:2,borderRadius:6}]},options:{...chartDefaults,responsive:true,maintainAspectRatio:false}});
    new Chart(document.getElementById('roleDonutChart'),{type:'doughnut',data:{labels:['Admin','Staff','Cashier'],datasets:[{data:[<?=$rca?>,<?=$rcs?>,<?=$rcc?>],backgroundColor:['#3b82f6','#4ade80','#fbbf24'],borderWidth:0}]},options:{responsive:true,maintainAspectRatio:false,cutout:'68%',plugins:{legend:{display:false}}}});
    new Chart(document.getElementById('planDonutChart'),{type:'doughnut',data:{labels:['Starter','Pro','Enterprise'],datasets:[{data:[<?=$plan_dist['Starter']?>,<?=$plan_dist['Pro']?>,<?=$plan_dist['Enterprise']?>],backgroundColor:['#94a3b8','#fbbf24','#a78bfa'],borderWidth:0}]},options:{responsive:true,maintainAspectRatio:false,cutout:'68%',plugins:{legend:{display:false}}}});
    </script>

    <!-- ══ TENANT MANAGEMENT ════════════════════════════════════ -->
    <?php elseif($active_page==='tenants'): ?>
    <section class="page-header">
      <h2 class="page-title">Tenant Management</h2>
      <p class="page-sub">Manage all pawnshop tenants across the platform.</p>
    </section>
    <div class="stats-grid">
      <div class="stat-card stat-card-normal"><div class="stat-header"><div class="stat-icon stat-icon-primary"><span class="material-symbols-outlined">storefront</span></div></div><div class="stat-label">Total</div><div class="stat-value"><?=$total_tenants?></div></div>
      <div class="stat-card stat-card-normal"><div class="stat-header"><div class="stat-icon" style="background:rgba(74,222,128,0.15);"><span class="material-symbols-outlined" style="color:#4ade80;">check_circle</span></div></div><div class="stat-label">Active</div><div class="stat-value" style="color:#4ade80;"><?=$active_tenants?></div></div>
      <div class="stat-card stat-card-normal"><div class="stat-header"><div class="stat-icon" style="background:rgba(251,191,36,0.15);"><span class="material-symbols-outlined" style="color:#fbbf24;">schedule</span></div></div><div class="stat-label">Pending</div><div class="stat-value" style="color:#fbbf24;"><?=$pending_tenants?></div></div>
      <div class="stat-card stat-card-normal"><div class="stat-header"><div class="stat-icon" style="background:rgba(248,113,113,0.15);"><span class="material-symbols-outlined" style="color:#f87171;">cancel</span></div></div><div class="stat-label">Inactive</div><div class="stat-value" style="color:#f87171;"><?=$inactive_tenants?></div></div>
    </div>

    <?php $pts=array_filter($tenants,fn($t)=>$t['status']==='pending');if(!empty($pts)):?>
    <div class="inner-card" style="border-color:rgba(251,191,36,0.3);margin-bottom:20px;">
      <div class="inner-card-pad" style="border-bottom:1px solid rgba(251,191,36,0.15);">
        <div class="inner-card-title" style="color:#fbbf24;">⏳ Pending Approval (<?=count($pts)?>)</div>
      </div>
      <div style="overflow-x:auto;"><table>
        <thead><tr><th>Business Name</th><th>Owner</th><th>Email</th><th>Plan</th><th>Applied</th><th>Actions</th></tr></thead>
        <tbody>
        <?php foreach($pts as $t):?>
        <tr>
          <td style="font-weight:600;color:#fff;"><?=htmlspecialchars($t['business_name'])?></td>
          <td><?=htmlspecialchars($t['owner_name'])?></td>
          <td style="font-size:12px;"><?=htmlspecialchars($t['email'])?></td>
          <td><span class="badge <?=$t['plan']==='Enterprise'?'plan-ent':($t['plan']==='Pro'?'plan-pro':'plan-starter')?>"><?=$t['plan']?></span></td>
          <td style="font-size:12px;"><?=date('M d, Y',strtotime($t['created_at']))?></td>
          <td>
            <button onclick="openApproveModal(<?=$t['id']?>,<?=(int)$t['admin_uid']?>,'<?=htmlspecialchars($t['business_name'],ENT_QUOTES)?>')" class="btn-sm btn-success">✓ Approve</button>
            <button onclick="openRejectModal(<?=$t['id']?>,<?=(int)$t['admin_uid']?>,'<?=htmlspecialchars($t['business_name'],ENT_QUOTES)?>')" class="btn-sm btn-danger">✗ Reject</button>
          </td>
        </tr>
        <?php endforeach;?>
        </tbody>
      </table></div>
    </div>
    <?php endif;?>

    <div class="inner-card">
      <div class="inner-card-pad" style="border-bottom:1px solid var(--white-10);display:flex;justify-content:space-between;align-items:center;">
        <div class="inner-card-title">🏢 All Tenants</div>
        <span style="font-size:12px;color:var(--white-50);"><?=$total_tenants?> total</span>
      </div>
      <?php if(empty($tenants)):?>
      <div class="empty-state"><span class="material-symbols-outlined">storefront</span><p>No tenants yet.</p></div>
      <?php else:?>
      <div style="overflow-x:auto;"><table>
        <thead><tr><th>ID</th><th>Business Name</th><th>Owner</th><th>Email</th><th>Plan</th><th>Status</th><th>Users</th><th>Registered</th><th>Actions</th></tr></thead>
        <tbody>
        <?php foreach($tenants as $t):?>
        <tr>
          <td style="color:var(--white-50);font-size:12px;">#<?=$t['id']?></td>
          <td style="font-weight:600;color:#fff;"><?=htmlspecialchars($t['business_name'])?></td>
          <td><?=htmlspecialchars($t['owner_name'])?></td>
          <td style="font-size:12px;"><?=htmlspecialchars($t['email'])?></td>
          <td><span class="badge <?=$t['plan']==='Enterprise'?'plan-ent':($t['plan']==='Pro'?'plan-pro':'plan-starter')?>"><?=$t['plan']?></span></td>
          <td><span class="badge <?=$t['status']==='active'?'b-green':($t['status']==='pending'?'b-yellow':($t['status']==='inactive'?'b-red':'b-gray'))?>"><span class="badge-dot"></span><?=ucfirst($t['status'])?></span></td>
          <td><?=$t['user_count']?></td>
          <td style="font-size:12px;"><?=date('M d, Y',strtotime($t['created_at']))?></td>
          <td>
            <?php if($t['status']==='active'):?>
              <form method="POST" style="display:inline;" onsubmit="return confirm('Deactivate this tenant?')"><input type="hidden" name="action" value="deactivate_tenant"><input type="hidden" name="tenant_id" value="<?=$t['id']?>"><button type="submit" class="btn-sm btn-danger">Deactivate</button></form>
              <button onclick="openPlanModal(<?=$t['id']?>,'<?=htmlspecialchars($t['business_name'],ENT_QUOTES)?>','<?=$t['plan']?>')" class="btn-sm btn-warning">⭐ Plan</button>
            <?php elseif($t['status']==='inactive'):?>
              <form method="POST" style="display:inline;"><input type="hidden" name="action" value="activate_tenant"><input type="hidden" name="tenant_id" value="<?=$t['id']?>"><button type="submit" class="btn-sm btn-success">Activate</button></form>
              <button onclick="openPlanModal(<?=$t['id']?>,'<?=htmlspecialchars($t['business_name'],ENT_QUOTES)?>','<?=$t['plan']?>')" class="btn-sm btn-warning">⭐ Plan</button>
            <?php elseif($t['status']==='pending'):?>
              <button onclick="openApproveModal(<?=$t['id']?>,<?=(int)$t['admin_uid']?>,'<?=htmlspecialchars($t['business_name'],ENT_QUOTES)?>')" class="btn-sm btn-success">✓ Approve</button>
              <button onclick="openRejectModal(<?=$t['id']?>,<?=(int)$t['admin_uid']?>,'<?=htmlspecialchars($t['business_name'],ENT_QUOTES)?>')" class="btn-sm btn-danger">✗ Reject</button>
            <?php else:?><span style="font-size:12px;color:var(--white-50);">—</span><?php endif;?>
          </td>
        </tr>
        <?php endforeach;?>
        </tbody>
      </table></div>
      <?php endif;?>
    </div>

    <!-- ══ REPORTS ══════════════════════════════════════════════ -->
    <?php elseif($active_page==='reports'): ?>
    <section class="page-header">
      <h2 class="page-title">Reports</h2>
      <p class="page-sub">Analyze platform-wide activity and usage statistics.</p>
    </section>
    <div class="report-tabs">
      <?php foreach(['tenant_activity'=>'🏢 Tenant Activity','user_registration'=>'👤 User Registration','usage_statistics'=>'📊 Usage Statistics'] as $rk=>$rl):?>
      <a href="?page=reports&report_type=<?=$rk?>&date_from=<?=$filter_date_from?>&date_to=<?=$filter_date_to?>" class="report-tab <?=$report_type===$rk?'active':''?>"><?=$rl?></a>
      <?php endforeach;?>
    </div>
    <form method="GET"><input type="hidden" name="page" value="reports"><input type="hidden" name="report_type" value="<?=htmlspecialchars($report_type)?>">
      <div class="filter-bar">
        <label>From:</label><input type="date" name="date_from" class="filter-input" value="<?=htmlspecialchars($filter_date_from)?>">
        <label>To:</label><input type="date" name="date_to" class="filter-input" value="<?=htmlspecialchars($filter_date_to)?>">
        <?php if(in_array($report_type,['tenant_activity','user_registration'])):?>
        <label>Status:</label><select name="filter_status" class="filter-select"><option value="">All</option>
          <?php if($report_type==='user_registration'):?><option value="approved" <?=$filter_status==='approved'?'selected':''?>>Approved</option><option value="pending" <?=$filter_status==='pending'?'selected':''?>>Pending</option><option value="rejected" <?=$filter_status==='rejected'?'selected':''?>>Rejected</option>
          <?php else:?><option value="active" <?=$filter_status==='active'?'selected':''?>>Active</option><option value="inactive" <?=$filter_status==='inactive'?'selected':''?>>Inactive</option><option value="pending" <?=$filter_status==='pending'?'selected':''?>>Pending</option><?php endif;?></select>
        <?php endif;?>
        <label>Tenant:</label><select name="filter_tenant" class="filter-select"><option value="0">All</option><?php foreach($tenants as $t):?><option value="<?=$t['id']?>" <?=$filter_tenant===(int)$t['id']?'selected':''?>><?=htmlspecialchars($t['business_name'])?></option><?php endforeach;?></select>
        <button type="submit" class="btn-sm btn-primary">Apply</button><a href="?page=reports&report_type=<?=$report_type?>" class="btn-sm">Reset</a>
      </div>
    </form>

    <?php if($report_type==='tenant_activity'):
      $rt=count($report_data);$ru=array_sum(array_column($report_data,'user_count'));$rat=count(array_filter($report_data,fn($r)=>$r['status']==='active'));?>
      <div class="summary-grid-3">
        <div class="summary-item"><div class="summary-num"><?=$rt?></div><div class="summary-lbl">Tenants</div></div>
        <div class="summary-item"><div class="summary-num" style="color:#4ade80;"><?=$rat?></div><div class="summary-lbl">Active</div></div>
        <div class="summary-item"><div class="summary-num"><?=$ru?></div><div class="summary-lbl">Total Users</div></div>
      </div>
      <div class="inner-card">
        <div class="inner-card-pad" style="border-bottom:1px solid var(--white-10);display:flex;justify-content:space-between;">
          <div class="inner-card-title">🏢 Tenant Activity Report</div>
          <span style="font-size:12px;color:var(--white-50);"><?=htmlspecialchars($filter_date_from)?> — <?=htmlspecialchars($filter_date_to)?></span>
        </div>
        <?php if(empty($report_data)):?><div class="empty-state"><span class="material-symbols-outlined">analytics</span><p>No data found.</p></div>
        <?php else:?><div style="overflow-x:auto;"><table>
          <thead><tr><th>#</th><th>Business</th><th>Owner</th><th>Email</th><th>Plan</th><th>Status</th><th>Branches</th><th>Users</th><th>Admins</th><th>Staff</th><th>Cashiers</th><th>Registered</th></tr></thead>
          <tbody><?php foreach($report_data as $i=>$r):?><tr>
            <td style="color:var(--white-50);font-size:12px;"><?=$i+1?></td>
            <td style="font-weight:600;color:#fff;"><?=htmlspecialchars($r['business_name'])?></td>
            <td><?=htmlspecialchars($r['owner_name'])?></td>
            <td style="font-size:12px;"><?=htmlspecialchars($r['email'])?></td>
            <td><span class="badge <?=$r['plan']==='Enterprise'?'plan-ent':($r['plan']==='Pro'?'plan-pro':'plan-starter')?>"><?=$r['plan']?></span></td>
            <td><span class="badge <?=$r['status']==='active'?'b-green':($r['status']==='pending'?'b-yellow':'b-red')?>"><span class="badge-dot"></span><?=ucfirst($r['status'])?></span></td>
            <td><?=$r['branches']?></td><td style="font-weight:700;color:#fff;"><?=$r['user_count']?></td>
            <td><?=$r['admin_count']?></td><td><?=$r['staff_count']?></td><td><?=$r['cashier_count']?></td>
            <td style="font-size:12px;"><?=date('M d, Y',strtotime($r['created_at']))?></td>
          </tr><?php endforeach;?></tbody>
          <tfoot><tr><td colspan="7" style="font-weight:700;font-size:12px;">TOTALS</td><td style="font-weight:800;color:#fff;"><?=$ru?></td><td style="font-weight:800;color:#fff;"><?=array_sum(array_column($report_data,'admin_count'))?></td><td style="font-weight:800;color:#fff;"><?=array_sum(array_column($report_data,'staff_count'))?></td><td style="font-weight:800;color:#fff;"><?=array_sum(array_column($report_data,'cashier_count'))?></td><td></td></tr></tfoot>
        </table></div><?php endif;?>
      </div>

    <?php elseif($report_type==='user_registration'):
      $rt=count($report_data);$ra=count(array_filter($report_data,fn($r)=>$r['status']==='approved'));$rp=count(array_filter($report_data,fn($r)=>$r['status']==='pending'));?>
      <div class="summary-grid-3">
        <div class="summary-item"><div class="summary-num"><?=$rt?></div><div class="summary-lbl">Registrations</div></div>
        <div class="summary-item"><div class="summary-num" style="color:#4ade80;"><?=$ra?></div><div class="summary-lbl">Approved</div></div>
        <div class="summary-item"><div class="summary-num" style="color:#fbbf24;"><?=$rp?></div><div class="summary-lbl">Pending</div></div>
      </div>
      <div class="inner-card">
        <div class="inner-card-pad" style="border-bottom:1px solid var(--white-10);">
          <div class="inner-card-title">👤 User Registration Report</div>
        </div>
        <?php if(empty($report_data)):?><div class="empty-state"><span class="material-symbols-outlined">group</span><p>No data found.</p></div>
        <?php else:?><div style="overflow-x:auto;"><table>
          <thead><tr><th>#</th><th>Name</th><th>Username</th><th>Email</th><th>Role</th><th>Tenant</th><th>Status</th><th>Suspended</th><th>Registered</th></tr></thead>
          <tbody><?php foreach($report_data as $i=>$r):?><tr>
            <td style="color:var(--white-50);font-size:12px;"><?=$i+1?></td>
            <td style="font-weight:600;color:#fff;"><?=htmlspecialchars($r['fullname'])?></td>
            <td class="ticket-tag"><?=htmlspecialchars($r['username'])?></td>
            <td style="font-size:12px;"><?=htmlspecialchars($r['email'])?></td>
            <td><span class="badge <?=['admin'=>'b-blue','staff'=>'b-green','cashier'=>'b-yellow'][$r['role']]??'b-gray'?>"><?=ucfirst($r['role'])?></span></td>
            <td><?=htmlspecialchars($r['business_name']??'—')?></td>
            <td><span class="badge <?=$r['status']==='approved'?'b-green':($r['status']==='pending'?'b-yellow':'b-red')?>"><?=ucfirst($r['status'])?></span></td>
            <td><?=$r['is_suspended']?'<span class="badge b-red">Yes</span>':'<span class="badge b-green">No</span>'?></td>
            <td style="font-size:12px;"><?=date('M d, Y',strtotime($r['created_at']))?></td>
          </tr><?php endforeach;?></tbody>
        </table></div><?php endif;?>
      </div>

    <?php elseif($report_type==='usage_statistics'):
      $rtu=array_sum(array_column($report_data,'total_users'));$rau=array_sum(array_column($report_data,'active_users'));$rsu=array_sum(array_column($report_data,'suspended_users'));?>
      <div class="summary-grid-3">
        <div class="summary-item"><div class="summary-num"><?=$rtu?></div><div class="summary-lbl">Total Users</div></div>
        <div class="summary-item"><div class="summary-num" style="color:#4ade80;"><?=$rau?></div><div class="summary-lbl">Active</div></div>
        <div class="summary-item"><div class="summary-num" style="color:#f87171;"><?=$rsu?></div><div class="summary-lbl">Suspended</div></div>
      </div>
      <div class="inner-card">
        <div class="inner-card-pad" style="border-bottom:1px solid var(--white-10);">
          <div class="inner-card-title">📊 Usage Statistics</div>
        </div>
        <?php if(empty($report_data)):?><div class="empty-state"><span class="material-symbols-outlined">analytics</span><p>No data found.</p></div>
        <?php else:?><div style="overflow-x:auto;"><table>
          <thead><tr><th>#</th><th>Tenant</th><th>Plan</th><th>Status</th><th>Branches</th><th>Total</th><th>Admins</th><th>Staff</th><th>Cashiers</th><th>Active</th><th>Suspended</th></tr></thead>
          <tbody><?php foreach($report_data as $i=>$r):?><tr>
            <td style="color:var(--white-50);font-size:12px;"><?=$i+1?></td>
            <td style="font-weight:600;color:#fff;"><?=htmlspecialchars($r['business_name'])?></td>
            <td><span class="badge <?=$r['plan']==='Enterprise'?'plan-ent':($r['plan']==='Pro'?'plan-pro':'plan-starter')?>"><?=$r['plan']?></span></td>
            <td><span class="badge <?=$r['status']==='active'?'b-green':($r['status']==='pending'?'b-yellow':'b-red')?>"><span class="badge-dot"></span><?=ucfirst($r['status'])?></span></td>
            <td><?=$r['branches']?></td><td style="font-weight:700;color:#fff;"><?=$r['total_users']?></td>
            <td><?=$r['admin_count']?></td><td><?=$r['staff_count']?></td><td><?=$r['cashier_count']?></td>
            <td><span class="badge b-green"><?=$r['active_users']?></span></td>
            <td><span class="badge <?=$r['suspended_users']>0?'b-red':'b-gray'?>"><?=$r['suspended_users']?></span></td>
          </tr><?php endforeach;?></tbody>
          <tfoot><tr><td colspan="5" style="font-weight:700;font-size:12px;">TOTALS</td><td style="font-weight:800;color:#fff;"><?=$rtu?></td><td style="font-weight:800;color:#fff;"><?=array_sum(array_column($report_data,'admin_count'))?></td><td style="font-weight:800;color:#fff;"><?=array_sum(array_column($report_data,'staff_count'))?></td><td style="font-weight:800;color:#fff;"><?=array_sum(array_column($report_data,'cashier_count'))?></td><td style="font-weight:800;color:#4ade80;"><?=$rau?></td><td style="font-weight:800;color:#f87171;"><?=$rsu?></td></tr></tfoot>
        </table></div><?php endif;?>
      </div>
    <?php endif;?>

    <!-- ══ SALES REPORT ═════════════════════════════════════════ -->
    <?php elseif($active_page==='sales_report'): ?>
    <section class="page-header">
      <h2 class="page-title">Sales Report</h2>
      <p class="page-sub">Platform-wide transaction and revenue analytics.</p>
    </section>
    <form method="GET"><input type="hidden" name="page" value="sales_report">
      <div class="filter-bar">
        <label>Period:</label>
        <select name="sales_period" class="filter-select">
          <option value="daily" <?=$sales_period==='daily'?'selected':''?>>Daily</option>
          <option value="weekly" <?=$sales_period==='weekly'?'selected':''?>>Weekly</option>
          <option value="monthly" <?=$sales_period==='monthly'?'selected':''?>>Monthly</option>
        </select>
        <label>From:</label><input type="date" name="sales_from" class="filter-input" value="<?=htmlspecialchars($sales_date_from)?>">
        <label>To:</label><input type="date" name="sales_to" class="filter-input" value="<?=htmlspecialchars($sales_date_to)?>">
        <label>Tenant:</label>
        <select name="sales_tenant" class="filter-select"><option value="0">All Tenants</option>
          <?php foreach($tenants as $t):?><option value="<?=$t['id']?>" <?=$sales_tenant===(int)$t['id']?'selected':''?>><?=htmlspecialchars($t['business_name'])?></option><?php endforeach;?>
        </select>
        <button type="submit" class="btn-sm btn-primary">Apply</button>
        <a href="?page=sales_report" class="btn-sm">Reset</a>
      </div>
    </form>
    <div class="summary-grid">
      <div class="summary-item"><div class="summary-num" style="color:#4ade80;">₱<?=number_format($sales_summary['total_revenue']??0,2)?></div><div class="summary-lbl">Total Revenue</div></div>
      <div class="summary-item"><div class="summary-num" style="color:#60a5fa;"><?=number_format($sales_summary['total_transactions']??0)?></div><div class="summary-lbl">Total Transactions</div></div>
      <div class="summary-item"><div class="summary-num"><?=$sales_summary['active_tenants']??0?></div><div class="summary-lbl">Active Tenants</div></div>
      <div class="summary-item"><div class="summary-num" style="color:#a78bfa;">₱<?=number_format($sales_summary['avg_transaction']??0,2)?></div><div class="summary-lbl">Avg. Transaction</div></div>
    </div>
    <div class="inner-card" style="margin-bottom:20px;padding:22px 24px;">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
        <div class="inner-card-title">📈 Revenue Trend — <?=ucfirst($sales_period)?></div>
        <span style="font-size:12px;color:var(--white-50);"><?=htmlspecialchars($sales_date_from)?> — <?=htmlspecialchars($sales_date_to)?></span>
      </div>
      <?php if(empty($sales_data)):?><div class="empty-state"><span class="material-symbols-outlined">trending_up</span><p>No transaction data for the selected period.</p></div>
      <?php else:?><div style="position:relative;height:240px;"><canvas id="salesTrendChart"></canvas></div>
      <script>
      new Chart(document.getElementById('salesTrendChart'),{type:'line',data:{labels:<?=json_encode($sales_chart_labels)?>,datasets:[{label:'Revenue (₱)',data:<?=json_encode(array_map('floatval',$sales_chart_data))?>,borderColor:'#3b82f6',backgroundColor:'rgba(59,130,246,0.08)',borderWidth:2.5,tension:0.4,fill:true,pointRadius:3,pointBackgroundColor:'#3b82f6'},{label:'Transactions',data:<?=json_encode(array_map('intval',array_column($sales_data,'tx_count')))?>,borderColor:'#4ade80',backgroundColor:'transparent',borderWidth:2,tension:0.4,pointRadius:3,pointBackgroundColor:'#4ade80',yAxisID:'y2'}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:true,labels:{font:{size:11},color:'rgba(255,255,255,0.5)',boxWidth:12}}},scales:{x:{grid:{display:false},ticks:{font:{size:10},color:'rgba(255,255,255,0.3)'}},y:{grid:{color:'rgba(255,255,255,0.05)'},ticks:{font:{size:10},color:'rgba(255,255,255,0.3)'},beginAtZero:true},y2:{position:'right',grid:{display:false},ticks:{font:{size:10},color:'rgba(74,222,128,0.5)'},beginAtZero:true}}}});
      </script><?php endif;?>
    </div>
    <div class="two-col">
      <div class="inner-card">
        <div class="inner-card-pad" style="border-bottom:1px solid var(--white-10);"><div class="inner-card-title">🏢 Sales Per Tenant</div></div>
        <?php if(empty($sales_per_tenant)):?><div class="empty-state"><p>No data.</p></div>
        <?php else:?><div style="overflow-x:auto;"><table>
          <thead><tr><th>Rank</th><th>Tenant</th><th>Plan</th><th>Tx</th><th>Revenue</th><th>Avg</th><th>Last Tx</th></tr></thead>
          <tbody><?php foreach($sales_per_tenant as $i=>$r):?><tr>
            <td><span class="badge <?=$i===0?'rank-1':($i===1?'rank-2':($i===2?'rank-3':'b-gray'))?>">#<?=$i+1?></span></td>
            <td style="font-weight:600;color:#fff;"><?=htmlspecialchars($r['business_name'])?></td>
            <td><span class="badge <?=$r['plan']==='Enterprise'?'plan-ent':($r['plan']==='Pro'?'plan-pro':'plan-starter')?>"><?=$r['plan']?></span></td>
            <td style="font-weight:700;color:#fff;"><?=number_format($r['tx_count'])?></td>
            <td style="font-weight:700;color:#4ade80;">₱<?=number_format($r['revenue'],2)?></td>
            <td>₱<?=number_format($r['avg_tx'],2)?></td>
            <td style="font-size:12px;"><?=$r['last_tx']?date('M d, Y',strtotime($r['last_tx'])):'—'?></td>
          </tr><?php endforeach;?></tbody>
          <tfoot><tr><td colspan="3">TOTALS</td><td style="font-weight:800;color:#fff;"><?=number_format(array_sum(array_column($sales_per_tenant,'tx_count')))?></td><td style="font-weight:800;color:#4ade80;">₱<?=number_format(array_sum(array_column($sales_per_tenant,'revenue')),2)?></td><td colspan="2"></td></tr></tfoot>
        </table></div><?php endif;?>
      </div>
      <div class="inner-card inner-card-pad">
        <div class="inner-card-title" style="margin-bottom:18px;">🏆 Top Performing Tenants</div>
        <?php if(empty($top_tenants)):?><div class="empty-state"><p>No data.</p></div>
        <?php else:$mx=max(array_column($top_tenants,'revenue'));$bar_colors=['#3b82f6','#4ade80','#fbbf24','#a78bfa','#f87171'];
        foreach($top_tenants as $i=>$r):$bp=$mx>0?round($r['revenue']/$mx*100):0;$bc=$bar_colors[$i]??'#94a3b8';?>
        <div style="margin-bottom:16px;">
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:5px;">
            <div style="display:flex;align-items:center;gap:8px;">
              <span style="font-size:11px;font-weight:800;color:<?=$bc?>;background:<?=$bc?>22;width:22px;height:22px;border-radius:50%;display:flex;align-items:center;justify-content:center;"><?=$i+1?></span>
              <span style="font-size:13px;font-weight:600;color:#fff;"><?=htmlspecialchars($r['business_name'])?></span>
            </div>
            <span style="font-size:13px;font-weight:700;color:#4ade80;">₱<?=number_format($r['revenue'],0)?></span>
          </div>
          <div style="height:6px;background:rgba(255,255,255,0.07);border-radius:100px;overflow:hidden;"><div style="height:100%;width:<?=$bp?>%;background:<?=$bc?>;border-radius:100px;"></div></div>
          <div style="font-size:11px;color:var(--white-50);margin-top:3px;"><?=number_format($r['tx_count'])?> transactions</div>
        </div>
        <?php endforeach;endif;?>
      </div>
    </div>
    <div class="inner-card">
      <div class="inner-card-pad" style="border-bottom:1px solid var(--white-10);display:flex;justify-content:space-between;">
        <div class="inner-card-title">📋 Transaction History (Latest 100)</div>
        <span style="font-size:12px;color:var(--white-50);"><?=htmlspecialchars($sales_date_from)?> — <?=htmlspecialchars($sales_date_to)?></span>
      </div>
      <?php if(empty($tx_history)):?><div class="empty-state"><span class="material-symbols-outlined">receipt_long</span><p>No transactions found.</p></div>
      <?php else:?><div style="overflow-x:auto;"><table>
        <thead><tr><th>#</th><th>Tenant</th><th>Ticket No.</th><th>Amount</th><th>Status</th><th>Date</th></tr></thead>
        <tbody><?php foreach($tx_history as $i=>$tx):$ts=$tx['status']??'';?><tr>
          <td style="color:var(--white-50);font-size:12px;"><?=$i+1?></td>
          <td style="font-weight:600;color:#fff;"><?=htmlspecialchars($tx['business_name']??'—')?></td>
          <td class="ticket-tag"><?=htmlspecialchars($tx['ticket_no']??$tx['id'])?></td>
          <td style="font-weight:700;color:#4ade80;">₱<?=number_format($tx['loan_amount']??0,2)?></td>
          <td><span class="badge <?=$ts==='active'?'b-green':($ts==='redeemed'?'b-blue':($ts==='forfeited'?'b-red':'b-gray'))?>"><?=ucfirst($ts)?></span></td>
          <td style="font-size:12px;"><?=date('M d, Y h:i A',strtotime($tx['created_at']))?></td>
        </tr><?php endforeach;?></tbody>
      </table></div><?php endif;?>
    </div>

    <!-- ══ SETTINGS ═══════════════════════════════════════════ -->
    <?php elseif($active_page==='settings'): ?>
    <section class="page-header">
      <h2 class="page-title">System Settings</h2>
      <p class="page-sub">Configure platform-wide settings and subscription plans.</p>
    </section>
    <form method="POST">
      <input type="hidden" name="action" value="save_system_settings">
      <div class="settings-section">
        <div class="settings-section-title">🎨 System Branding</div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
          <div>
            <label class="flabel">System Name</label>
            <input type="text" name="system_name" class="finput" value="<?=htmlspecialchars($ss['system_name'])?>" placeholder="PawnHub">
            <div class="form-hint">Shown in the browser title and sidebar.</div>
          </div>
          <div>
            <label class="flabel">System Tagline</label>
            <input type="text" name="system_tagline" class="finput" value="<?=htmlspecialchars($ss['system_tagline'])?>" placeholder="Multi-Tenant Pawnshop Management">
            <div class="form-hint">Shown on the login page.</div>
          </div>
        </div>
      </div>
      <div class="settings-section">
        <div class="settings-section-title">📦 Subscription Plan Limits</div>
        <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:16px;">
          <!-- STARTER -->
          <div class="plan-card-wrap">
            <div class="plan-card-head" style="background:rgba(148,163,184,0.1);border-bottom:1px solid var(--white-10);">
              <div style="font-size:15px;font-weight:800;color:#94a3b8;">Starter</div>
              <div style="font-size:12px;color:var(--white-50);margin-top:2px;">Basic pawnshop operations</div>
            </div>
            <div class="plan-card-body">
              <div><label class="flabel">Price / Label</label><input type="text" name="starter_price" class="finput" value="<?=htmlspecialchars($ss['starter_price'])?>" placeholder="Free"></div>
              <div><label class="flabel">Max Branches</label><input type="number" name="starter_branches" class="finput" value="<?=(int)$ss['starter_branches']?>" min="1" max="99"></div>
              <div><label class="flabel">Max Staff + Cashiers</label><input type="number" name="starter_staff" class="finput" value="<?=(int)$ss['starter_staff']?>" min="1" max="999"><div class="form-hint">Combined staff + cashier limit</div></div>
              <div class="plan-features">✅ Pawn tickets<br>✅ Customer management<br>✅ Basic reports<br><span style="color:#f87171;">❌ Multi-branch</span></div>
            </div>
          </div>
          <!-- PRO -->
          <div class="plan-card-wrap" style="border-color:rgba(59,130,246,0.3);">
            <div class="plan-card-head" style="background:rgba(59,130,246,0.1);border-bottom:1px solid rgba(59,130,246,0.2);">
              <div style="font-size:15px;font-weight:800;color:#60a5fa;">Pro</div>
              <div style="font-size:12px;color:rgba(147,197,253,0.6);margin-top:2px;">Growing pawnshop business</div>
            </div>
            <div class="plan-card-body">
              <div><label class="flabel">Price / Label</label><input type="text" name="pro_price" class="finput" value="<?=htmlspecialchars($ss['pro_price'])?>" placeholder="₱999/mo"></div>
              <div><label class="flabel">Max Branches</label><input type="number" name="pro_branches" class="finput" value="<?=(int)$ss['pro_branches']?>" min="1" max="99"></div>
              <div><label class="flabel">Max Staff + Cashiers <span style="color:var(--white-50);font-weight:400;">(0 = unlimited)</span></label><input type="number" name="pro_staff" class="finput" value="<?=(int)$ss['pro_staff']?>" min="0"><div class="form-hint">0 means no limit</div></div>
              <div class="plan-features">✅ Everything in Starter<br>✅ Multi-branch support<br>✅ Unlimited staff<br>✅ Advanced reports</div>
            </div>
          </div>
          <!-- ENTERPRISE -->
          <div class="plan-card-wrap" style="border-color:rgba(167,139,250,0.3);">
            <div class="plan-card-head" style="background:rgba(167,139,250,0.1);border-bottom:1px solid rgba(167,139,250,0.2);">
              <div style="font-size:15px;font-weight:800;color:#a78bfa;">Enterprise</div>
              <div style="font-size:12px;color:rgba(196,181,253,0.6);margin-top:2px;">Large pawnshop chains</div>
            </div>
            <div class="plan-card-body">
              <div><label class="flabel">Price / Label</label><input type="text" name="ent_price" class="finput" value="<?=htmlspecialchars($ss['ent_price'])?>" placeholder="₱2,499/mo"></div>
              <div><label class="flabel">Max Branches</label><input type="number" name="ent_branches" class="finput" value="<?=(int)$ss['ent_branches']?>" min="1" max="999"></div>
              <div><label class="flabel">Max Staff + Cashiers <span style="color:var(--white-50);font-weight:400;">(0 = unlimited)</span></label><input type="number" name="ent_staff" class="finput" value="<?=(int)$ss['ent_staff']?>" min="0"></div>
              <div class="plan-features">✅ Everything in Pro<br>✅ Dedicated support<br>✅ Custom branding<br>✅ Priority processing</div>
            </div>
          </div>
        </div>
      </div>
      <div class="settings-section">
        <div class="settings-section-title">👤 User Role Permissions</div>
        <div style="overflow-x:auto;">
          <table>
            <thead><tr><th>Permission</th><th style="text-align:center;">Super Admin</th><th style="text-align:center;">Admin</th><th style="text-align:center;">Staff</th><th style="text-align:center;">Cashier</th></tr></thead>
            <tbody>
              <?php $perms=[['Manage Tenants',true,false,false,false],['Approve/Reject Tenants',true,false,false,false],['View Sales Report',true,false,false,false],['View Audit Logs',true,false,false,false],['System Settings',true,false,false,false],['Manage Staff/Cashiers',false,true,false,false],['Approve Void Requests',false,true,false,false],['Theme & Branding',false,true,false,false],['Create Pawn Tickets',false,false,true,false],['Register Customers',false,false,true,false],['Process Payment',false,false,true,true],['View Ticket Status',false,true,true,true]];
              foreach($perms as [$label,$sa,$ad,$st,$ca]):?>
              <tr><td style="font-size:13px;"><?=$label?></td>
              <?php foreach([$sa,$ad,$st,$ca] as $allowed):?>
              <td style="text-align:center;"><?=$allowed?'<span style="color:#4ade80;font-size:16px;">✓</span>':'<span style="color:rgba(255,255,255,0.1);">—</span>'?></td>
              <?php endforeach;?></tr>
              <?php endforeach;?>
            </tbody>
          </table>
        </div>
        <div style="margin-top:12px;font-size:12px;color:var(--white-50);background:var(--white-5);border-radius:8px;padding:10px 14px;">
          ℹ️ Role permissions are fixed by the system. Contact the developer to modify role-level access.
        </div>
      </div>
      <div style="display:flex;justify-content:flex-end;margin-bottom:20px;">
        <button type="submit" class="btn-sm btn-primary" style="padding:11px 28px;font-size:14px;">💾 Save Settings</button>
      </div>
    </form>

    <!-- ══ AUDIT LOGS ══════════════════════════════════════════ -->
    <?php elseif($active_page==='audit_logs'): ?>
    <section class="page-header">
      <h2 class="page-title">Audit Logs</h2>
      <p class="page-sub">Track all system actions and administrative activity.</p>
    </section>
    <form method="GET"><input type="hidden" name="page" value="audit_logs">
      <div class="filter-bar">
        <label>From:</label><input type="date" name="audit_from" class="filter-input" value="<?=htmlspecialchars($audit_date_from)?>">
        <label>To:</label><input type="date" name="audit_to" class="filter-input" value="<?=htmlspecialchars($audit_date_to)?>">
        <label>Action:</label>
        <select name="audit_action" class="filter-select"><option value="">All Actions</option>
          <?php foreach($audit_actions_list as $act):?><option value="<?=htmlspecialchars($act)?>" <?=$audit_action===$act?'selected':''?>><?=htmlspecialchars($act)?></option><?php endforeach;?>
        </select>
        <label>Actor:</label><input type="text" name="audit_actor" class="filter-input" placeholder="Search username..." value="<?=htmlspecialchars($audit_actor)?>" style="width:150px;">
        <button type="submit" class="btn-sm btn-primary">Apply</button>
        <a href="?page=audit_logs" class="btn-sm">Reset</a>
      </div>
    </form>
    <div class="summary-grid-3">
      <div class="summary-item"><div class="summary-num"><?=number_format($audit_total)?></div><div class="summary-lbl">Total Entries</div></div>
      <div class="summary-item"><div class="summary-num" style="color:#60a5fa;"><?=$audit_page?>/<?=$audit_total_pages?></div><div class="summary-lbl">Page</div></div>
      <div class="summary-item"><div class="summary-num" style="font-size:14px;"><?=htmlspecialchars($audit_date_from)?> — <?=htmlspecialchars($audit_date_to)?></div><div class="summary-lbl">Date Range</div></div>
    </div>
    <div class="inner-card">
      <div class="inner-card-pad" style="border-bottom:1px solid var(--white-10);display:flex;justify-content:space-between;align-items:center;">
        <div class="inner-card-title">📋 Audit Logs</div>
        <span style="font-size:12px;color:var(--white-50);">Showing <?=count($audit_logs)?> of <?=number_format($audit_total)?> entries</span>
      </div>
      <?php if(empty($audit_logs)):?>
      <div class="empty-state"><span class="material-symbols-outlined">description</span><p>No audit log entries found.</p></div>
      <?php else:?>
      <div style="overflow-x:auto;"><table>
        <thead><tr><th>Date & Time</th><th>Actor</th><th>Role</th><th>Action</th><th>Entity</th><th>Message</th></tr></thead>
        <tbody>
        <?php foreach($audit_logs as $log):
          $av=strtoupper($log['action']??'');
          if(str_contains($av,'APPROVE')||str_contains($av,'ACTIVATE')) $ac='act-activate';
          elseif(str_contains($av,'REJECT')||str_contains($av,'DELETE')||str_contains($av,'DEACTIVATE')) $ac='act-reject';
          elseif(str_contains($av,'LOGIN')) $ac='act-login';
          elseif(str_contains($av,'LOGOUT')) $ac='act-logout';
          elseif(str_contains($av,'CREATE')||str_contains($av,'ADD')) $ac='act-create';
          elseif(str_contains($av,'UPDATE')||str_contains($av,'EDIT')) $ac='act-update';
          else $ac='act-other';
        ?>
        <tr>
          <td style="font-size:12px;white-space:nowrap;"><?=date('M d, Y h:i A',strtotime($log['created_at']))?></td>
          <td style="font-weight:600;color:#fff;"><?=htmlspecialchars($log['actor_username']??'—')?></td>
          <td><span class="badge <?=['super_admin'=>'b-purple','admin'=>'b-blue','staff'=>'b-green','cashier'=>'b-yellow'][$log['actor_role']??'']??'b-gray'?>"><?=ucwords(str_replace('_',' ',$log['actor_role']??'—'))?></span></td>
          <td><span class="badge <?=$ac?>" style="font-size:10px;"><?=htmlspecialchars($log['action']??'—')?></span></td>
          <td style="font-size:12px;"><?php if(!empty($log['entity_type'])):?><?=htmlspecialchars(ucfirst($log['entity_type']))?><?php if(!empty($log['entity_id'])):?> <span class="ticket-tag">#<?=htmlspecialchars($log['entity_id'])?></span><?php endif;?><?php else:?>—<?php endif;?></td>
          <td style="font-size:12px;max-width:260px;"><?=htmlspecialchars($log['message']??'—')?></td>
        </tr>
        <?php endforeach;?>
        </tbody>
      </table></div>
      <?php if($audit_total_pages>1):
        $bu="?page=audit_logs&audit_from=".urlencode($audit_date_from)."&audit_to=".urlencode($audit_date_to)."&audit_action=".urlencode($audit_action)."&audit_actor=".urlencode($audit_actor);?>
      <div class="pagination" style="padding:16px;">
        <?php if($audit_page>1):?><a href="<?=$bu?>&audit_page=<?=$audit_page-1?>" class="page-btn">← Prev</a><?php endif;?>
        <?php $ps=max(1,$audit_page-2);$pe=min($audit_total_pages,$audit_page+2);
        if($ps>1){echo '<a href="'.$bu.'&audit_page=1" class="page-btn">1</a>';if($ps>2)echo '<span style="color:var(--white-50);padding:0 4px;">...</span>';}
        for($p=$ps;$p<=$pe;$p++):?><a href="<?=$bu?>&audit_page=<?=$p?>" class="page-btn <?=$p===$audit_page?'active':''?>"><?=$p?></a><?php endfor;
        if($pe<$audit_total_pages){if($pe<$audit_total_pages-1)echo '<span style="color:var(--white-50);padding:0 4px;">...</span>';echo '<a href="'.$bu.'&audit_page='.$audit_total_pages.'" class="page-btn">'.$audit_total_pages.'</a>';}?>
        <?php if($audit_page<$audit_total_pages):?><a href="<?=$bu?>&audit_page=<?=$audit_page+1?>" class="page-btn">Next →</a><?php endif;?>
        <span style="font-size:12px;color:var(--white-50);margin-left:6px;">Page <?=$audit_page?> of <?=$audit_total_pages?></span>
      </div>
      <?php endif;?>
      <?php endif;?>
    </div>

    <!-- ══ INVITATIONS ════════════════════════════════════════ -->
    <?php elseif($active_page==='invitations'): ?>
    <section class="page-header">
      <h2 class="page-title">Email Invitations</h2>
      <p class="page-sub">Manage all tenant onboarding invitations.</p>
    </section>
    <div class="inner-card">
      <div class="inner-card-pad" style="border-bottom:1px solid var(--white-10);display:flex;justify-content:space-between;align-items:center;">
        <div class="inner-card-title">📧 Email Invitations</div>
        <span style="font-size:12px;color:var(--white-50);"><?=count($invitations)?> total · <?=$pending_inv?> pending</span>
      </div>
      <?php if(empty($invitations)):?>
      <div class="empty-state"><span class="material-symbols-outlined">mail</span><p>No invitations sent yet. Click "New Appraisal" to get started.</p></div>
      <?php else:?>
      <div style="overflow-x:auto;"><table>
        <thead><tr><th>Tenant</th><th>Owner</th><th>Email</th><th>Plan</th><th>Status</th><th>Expires</th><th>Sent</th><th>Actions</th></tr></thead>
        <tbody>
        <?php foreach($invitations as $inv):?>
        <tr>
          <td style="font-weight:600;color:#fff;"><?=htmlspecialchars($inv['business_name'])?></td>
          <td><?=htmlspecialchars($inv['owner_name'])?></td>
          <td style="font-family:monospace;font-size:12px;"><?=htmlspecialchars($inv['email'])?></td>
          <td><span class="badge <?=$inv['plan']==='Enterprise'?'plan-ent':($inv['plan']==='Pro'?'plan-pro':'plan-starter')?>"><?=$inv['plan']?></span></td>
          <td><span class="badge <?=$inv['status']==='used'?'b-green':($inv['status']==='pending'?'b-yellow':'b-red')?>"><span class="badge-dot"></span><?=ucfirst($inv['status'])?></span></td>
          <td style="font-size:12px;color:<?=strtotime($inv['expires_at'])<time()&&$inv['status']==='pending'?'#f87171':'var(--white-50)'?>;"><?=date('M d, Y h:i A',strtotime($inv['expires_at']))?></td>
          <td style="font-size:12px;"><?=date('M d, Y',strtotime($inv['created_at']))?></td>
          <td>
            <?php if($inv['status']==='pending'):?>
            <form method="POST" style="display:inline;">
              <input type="hidden" name="action" value="resend_invite">
              <input type="hidden" name="inv_id" value="<?=$inv['id']?>">
              <button type="submit" class="btn-sm btn-warning">📧 Resend</button>
            </form>
            <?php else:?><span style="font-size:12px;color:var(--white-50);">✓ Registered</span><?php endif;?>
          </td>
        </tr>
        <?php endforeach;?>
        </tbody>
      </table></div>
      <?php endif;?>
    </div>
    <?php endif;?>

  </div><!-- /content -->
</div><!-- /main -->

<!-- ══ FAB ══════════════════════════════════════════════════════ -->
<button class="fab" onclick="document.getElementById('addTenantModal').classList.add('open')">
  <span class="material-symbols-outlined">add</span>
</button>

<!-- ══ ADD TENANT MODAL ═══════════════════════════════════════ -->
<div class="modal-overlay" id="addTenantModal">
  <div class="modal modal-wide">
    <div class="mhdr">
      <div><div class="mtitle">➕ Add Tenant + Send Invite</div><div class="msub">An invitation link will be sent to the owner's Gmail.</div></div>
      <button class="mclose" onclick="document.getElementById('addTenantModal').classList.remove('open')"><span class="material-symbols-outlined">close</span></button>
    </div>
    <div class="mbody">
      <div class="modal-info modal-info-green" style="margin-bottom:16px;">📧 <strong>Flow:</strong> Fill form → Token generated → Email sent → Owner clicks link → Owner sets credentials → Access granted ✅</div>
      <form method="POST">
        <input type="hidden" name="action" value="add_tenant">
        <div style="font-size:10px;font-weight:700;letter-spacing:0.08em;text-transform:uppercase;color:var(--white-50);margin-bottom:12px;">Business Information</div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:16px;">
          <div style="grid-column:1/-1;"><label class="flabel">Business Name *</label><input type="text" name="business_name" class="finput" placeholder="GoldKing Pawnshop" required></div>
          <div><label class="flabel">Owner Full Name *</label><input type="text" name="owner_name" class="finput" placeholder="Juan Dela Cruz" required></div>
          <div><label class="flabel">Owner Gmail *</label><input type="email" name="email" class="finput" placeholder="owner@gmail.com" required></div>
          <div><label class="flabel">Phone</label><input type="text" name="phone" class="finput" placeholder="09XXXXXXXXX"></div>
          <div><label class="flabel">Address</label><input type="text" name="address" class="finput" placeholder="Street, City, Province"></div>
          <div><label class="flabel">Plan *</label><select name="plan" class="finput"><option value="Starter">Starter — Free</option><option value="Pro">Pro — ₱999/mo</option><option value="Enterprise">Enterprise — ₱2,499/mo</option></select></div>
          <div><label class="flabel">Branches</label><input type="number" name="branches" class="finput" value="1" min="1"></div>
        </div>
        <div class="modal-info modal-info-blue" style="margin-bottom:16px;">ℹ️ No password needed — the owner will set their own via the invitation link sent to their Gmail.</div>
        <div style="display:flex;justify-content:flex-end;gap:10px;">
          <button type="button" class="btn-sm" onclick="document.getElementById('addTenantModal').classList.remove('open')">Cancel</button>
          <button type="submit" class="btn-sm btn-primary">Create + Send Invite</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ══ CHANGE PLAN MODAL ══════════════════════════════════════ -->
<div class="modal-overlay" id="planModal">
  <div class="modal">
    <div class="mhdr">
      <div><div class="mtitle">⭐ Change Tenant Plan</div><div class="msub" id="plan_modal_sub"></div></div>
      <button class="mclose" onclick="document.getElementById('planModal').classList.remove('open')"><span class="material-symbols-outlined">close</span></button>
    </div>
    <div class="mbody">
      <form method="POST">
        <input type="hidden" name="action" value="change_plan">
        <input type="hidden" name="tenant_id" id="plan_tid">
        <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:16px;">
          <?php foreach(['Starter'=>['Free','#94a3b8','rgba(148,163,184,0.15)','1 Branch, 3 Staff'],'Pro'=>['₱999/mo','#60a5fa','rgba(59,130,246,0.15)','3 Branches, Unlimited'],'Enterprise'=>['₱2,499/mo','#a78bfa','rgba(167,139,250,0.15)','10 Branches, Unlimited']] as $pn=>[$pp,$pc,$pbg,$pf]):?>
          <label style="cursor:pointer;">
            <input type="radio" name="new_plan" value="<?=$pn?>" id="plan_<?=$pn?>" style="display:none;" onchange="updatePlanCard('<?=$pn?>')">
            <div id="plan_card_<?=$pn?>" style="border:1.5px solid var(--white-10);border-radius:12px;padding:14px;text-align:center;transition:all .15s;cursor:pointer;">
              <div style="font-size:13px;font-weight:800;color:<?=$pc?>;margin-bottom:2px;"><?=$pn?></div>
              <div style="font-size:12px;font-weight:700;color:<?=$pc?>;margin-bottom:6px;"><?=$pp?></div>
              <div style="font-size:11px;color:var(--white-50);"><?=$pf?></div>
            </div>
          </label>
          <?php endforeach;?>
        </div>
        <div class="modal-info modal-info-blue" style="margin-bottom:16px;">ℹ️ Plan takes effect immediately. Downgrading may suspend excess staff automatically.</div>
        <div style="display:flex;justify-content:flex-end;gap:10px;">
          <button type="button" class="btn-sm" onclick="document.getElementById('planModal').classList.remove('open')">Cancel</button>
          <button type="submit" class="btn-sm btn-primary" style="background:#a78bfa;border-color:#a78bfa;">Save Plan Change</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ══ APPROVE MODAL ══════════════════════════════════════════ -->
<div class="modal-overlay" id="approveModal">
  <div class="modal">
    <div class="mhdr">
      <div><div class="mtitle">✓ Approve Tenant</div><div class="msub" id="approve_sub"></div></div>
      <button class="mclose" onclick="document.getElementById('approveModal').classList.remove('open')"><span class="material-symbols-outlined">close</span></button>
    </div>
    <div class="mbody">
      <div class="modal-info modal-info-green" style="margin-bottom:16px;">✅ Approving will set status to <strong>Active</strong> and allow immediate login.</div>
      <form method="POST">
        <input type="hidden" name="action" value="approve_tenant">
        <input type="hidden" name="tenant_id" id="approve_tid">
        <input type="hidden" name="user_id" id="approve_uid">
        <div style="display:flex;justify-content:flex-end;gap:10px;">
          <button type="button" class="btn-sm" onclick="document.getElementById('approveModal').classList.remove('open')">Cancel</button>
          <button type="submit" class="btn-sm btn-success">✓ Confirm Approval</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ══ REJECT MODAL ═══════════════════════════════════════════ -->
<div class="modal-overlay" id="rejectModal">
  <div class="modal">
    <div class="mhdr">
      <div><div class="mtitle">✗ Reject Tenant</div><div class="msub" id="reject_sub"></div></div>
      <button class="mclose" onclick="document.getElementById('rejectModal').classList.remove('open')"><span class="material-symbols-outlined">close</span></button>
    </div>
    <div class="mbody">
      <form method="POST">
        <input type="hidden" name="action" value="reject_tenant">
        <input type="hidden" name="tenant_id" id="reject_tid">
        <input type="hidden" name="user_id" id="reject_uid">
        <div style="margin-bottom:14px;">
          <label class="flabel">Reason for Rejection</label>
          <textarea name="reject_reason" class="finput" rows="3" placeholder="e.g. Incomplete information, duplicate account..." style="resize:vertical;"></textarea>
        </div>
        <div class="modal-info modal-info-red" style="margin-bottom:16px;">⚠️ This action cannot be undone.</div>
        <div style="display:flex;justify-content:flex-end;gap:10px;">
          <button type="button" class="btn-sm" onclick="document.getElementById('rejectModal').classList.remove('open')">Cancel</button>
          <button type="submit" class="btn-sm btn-danger">✗ Confirm Rejection</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
// Close modals on overlay click
['approveModal','rejectModal','addTenantModal','planModal'].forEach(id=>{
  const el=document.getElementById(id);
  if(el) el.addEventListener('click',function(e){if(e.target===this)this.classList.remove('open');});
});

function openApproveModal(tid,uid,name){
  document.getElementById('approve_tid').value=tid;
  document.getElementById('approve_uid').value=uid;
  document.getElementById('approve_sub').textContent='Business: '+name;
  document.getElementById('approveModal').classList.add('open');
}
function openRejectModal(tid,uid,name){
  document.getElementById('reject_tid').value=tid;
  document.getElementById('reject_uid').value=uid;
  document.getElementById('reject_sub').textContent='Business: '+name;
  document.getElementById('rejectModal').classList.add('open');
}
function openPlanModal(tid,name,currentPlan){
  document.getElementById('plan_tid').value=tid;
  document.getElementById('plan_modal_sub').textContent=name;
  const radio=document.getElementById('plan_'+currentPlan);
  if(radio){radio.checked=true;updatePlanCard(currentPlan);}
  document.getElementById('planModal').classList.add('open');
}
function updatePlanCard(selected){
  const colors={Starter:'#94a3b8',Pro:'#60a5fa',Enterprise:'#a78bfa'};
  const bgs={Starter:'rgba(148,163,184,0.15)',Pro:'rgba(59,130,246,0.15)',Enterprise:'rgba(167,139,250,0.15)'};
  const borders={Starter:'rgba(148,163,184,0.4)',Pro:'rgba(59,130,246,0.5)',Enterprise:'rgba(167,139,250,0.5)'};
  ['Starter','Pro','Enterprise'].forEach(p=>{
    const card=document.getElementById('plan_card_'+p);
    if(!card)return;
    if(p===selected){card.style.borderColor=borders[p];card.style.background=bgs[p];}
    else{card.style.borderColor='rgba(255,255,255,0.1)';card.style.background='transparent';}
  });
}
</script>
</body>
</html>