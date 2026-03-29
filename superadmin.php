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

                    // ── AUTO-GENERATE SLUG FROM BUSINESS NAME ─
                    $base_slug = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $bname));
                    $slug = $base_slug;
                    $slug_counter = 1;
                    while (true) {
                        $slug_chk = $pdo->prepare("SELECT id FROM tenants WHERE slug = ? AND id != ?");
                        $slug_chk->execute([$slug, $new_tid]);
                        if (!$slug_chk->fetch()) break;
                        $slug = $base_slug . $slug_counter++;
                    }
                    $pdo->prepare("UPDATE tenants SET slug = ? WHERE id = ?")->execute([$slug, $new_tid]);
                    // ──────────────────────────────────────────

                    $token      = bin2hex(random_bytes(32));
                    $expires_at = date('Y-m-d H:i:s', strtotime('+24 hours'));
                    $pdo->prepare("INSERT INTO tenant_invitations (tenant_id,email,owner_name,token,status,expires_at,created_by) VALUES (?,?,?,?,'pending',?,?)")
                        ->execute([$new_tid,$email,$oname,$token,$expires_at,$u['id']]);
                    $pdo->commit();
                    try { $pdo->prepare("INSERT INTO audit_logs (tenant_id,actor_user_id,actor_username,actor_role,action,entity_type,entity_id,message,ip_address,created_at) VALUES (?,?,?,?,'TENANT_INVITE','tenant',?,?,?,NOW())")->execute([$new_tid,$u['id'],$u['username'],'super_admin',$new_tid,"Super Admin added tenant \"$bname\" and sent invitation to $email.",$_SERVER['REMOTE_ADDR']??'::1']); } catch(PDOException $e){}
                    $sent = sendTenantInvitation($email, $oname, $bname, $token, $slug);
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
        $inv    = $pdo->prepare("SELECT i.*,t.business_name,t.slug FROM tenant_invitations i JOIN tenants t ON i.tenant_id=t.id WHERE i.id=?");
        $inv->execute([$inv_id]); $inv = $inv->fetch();
        if ($inv && in_array($inv['status'], ['pending', 'expired'])) {
            // Generate a fresh token and reset status to 'pending'
            $token      = bin2hex(random_bytes(32));
            $expires_at = date('Y-m-d H:i:s', strtotime('+24 hours'));
            $pdo->prepare("UPDATE tenant_invitations SET token=?, expires_at=?, status='pending', used_at=NULL WHERE id=?")
                ->execute([$token, $expires_at, $inv_id]);
            $inv_slug = $inv['slug'] ?? '';
            $sent = sendTenantInvitation($inv['email'],$inv['owner_name'],$inv['business_name'],$token,$inv_slug);
            $success_msg = $sent ? "📧 Invitation resent to {$inv['email']}!" : "⚠️ Failed to send. Check mailer.php settings.";
        } elseif ($inv && $inv['status'] === 'used') {
            $error_msg = "This invitation was already used. The tenant already has an account.";
        } else {
            $error_msg = "Invitation not found.";
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
        $plan_pro_staff        = intval($_POST['pro_staff']        ?? 0); // 0 = unlimited
        $plan_pro_branches     = max(1, intval($_POST['pro_branches']     ?? 3));
        $plan_ent_staff        = intval($_POST['ent_staff']        ?? 0);
        $plan_ent_branches     = max(1, intval($_POST['ent_branches']     ?? 10));
        $plan_starter_price    = trim($_POST['starter_price'] ?? 'Free');
        $plan_pro_price        = trim($_POST['pro_price']     ?? '₱999/mo');
        $plan_ent_price        = trim($_POST['ent_price']     ?? '₱2,499/mo');

        try {
            // Upsert into system_settings table
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

        // Fetch tenant info for email + slug generation
        $t_stmt = $pdo->prepare("SELECT * FROM tenants WHERE id = ? LIMIT 1");
        $t_stmt->execute([$tid]);
        $t_row = $t_stmt->fetch();

        // Auto-generate slug if missing (signup.php tenants have no slug yet)
        $slug = $t_row['slug'] ?? '';
        if (empty($slug) && !empty($t_row['business_name'])) {
            $base_slug    = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $t_row['business_name']));
            $slug         = $base_slug;
            $slug_counter = 1;
            while (true) {
                $slug_chk = $pdo->prepare("SELECT id FROM tenants WHERE slug = ? AND id != ?");
                $slug_chk->execute([$slug, $tid]);
                if (!$slug_chk->fetch()) break;
                $slug = $base_slug . $slug_counter++;
            }
            $pdo->prepare("UPDATE tenants SET slug = ? WHERE id = ?")->execute([$slug, $tid]);
        }

        // Approve tenant + user
        $pdo->prepare("UPDATE tenants SET status='active' WHERE id=?")->execute([$tid]);
        $pdo->prepare("UPDATE users SET status='approved', approved_by=?, approved_at=NOW() WHERE id=?")->execute([$u['id'], $uid]);

        // Send approval email only for self-registered tenants (signup.php flow)
        // Invited tenants get their URL via sendTenantWelcome() in tenant_login.php after they register
        $inv_chk = $pdo->prepare("SELECT id FROM tenant_invitations WHERE tenant_id = ? LIMIT 1");
        $inv_chk->execute([$tid]);
        if (!$inv_chk->fetch() && $t_row && !empty($t_row['email'])) {
            try {
                sendTenantApproved($t_row['email'], $t_row['owner_name'], $t_row['business_name'], $slug);
            } catch (Throwable $mail_err) {
                error_log('Approval email failed: ' . $mail_err->getMessage());
            }
        }

        try { $pdo->prepare("INSERT INTO audit_logs (actor_id,actor_username,actor_role,action,entity_type,entity_id,message,created_at) VALUES (?,?,?,'APPROVE_TENANT','tenant',?,?,NOW())")->execute([$u['id'],$u['username'],'super_admin',$tid,"Approved tenant ID $tid"]); } catch(PDOException $e){}
        $success_msg = 'Tenant approved! They will receive an email with their login link.';
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
} catch (PDOException $e) { /* table may not exist yet */ }

// Defaults if not set
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
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>PawnHub — Super Admin</title>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
:root{--sw:252px;--navy:#0f172a;--blue-acc:#2563eb;--bg:#f1f5f9;--card:#fff;--border:#e2e8f0;--text:#1e293b;--text-m:#475569;--text-dim:#94a3b8;--success:#16a34a;--danger:#dc2626;--warning:#d97706;}
body{font-family:'Plus Jakarta Sans',sans-serif;background:var(--bg);color:var(--text);display:flex;min-height:100vh;}
.sidebar{width:var(--sw);min-height:100vh;background:var(--navy);display:flex;flex-direction:column;position:fixed;left:0;top:0;bottom:0;z-index:100;overflow-y:auto;}
.sb-brand{padding:20px 18px;border-bottom:1px solid rgba(255,255,255,.08);display:flex;align-items:center;gap:10px;}
.sb-logo{width:36px;height:36px;background:linear-gradient(135deg,#1d4ed8,#7c3aed);border-radius:9px;display:flex;align-items:center;justify-content:center;flex-shrink:0;}
.sb-logo svg{width:18px;height:18px;}
.sb-name{font-size:.93rem;font-weight:800;color:#fff;}
.sb-badge{font-size:.58rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;background:linear-gradient(135deg,#1d4ed8,#7c3aed);color:#fff;padding:2px 7px;border-radius:100px;display:inline-block;margin-top:2px;}
.sb-user{padding:12px 18px;border-bottom:1px solid rgba(255,255,255,.08);display:flex;align-items:center;gap:9px;}
.sb-avatar{width:30px;height:30px;border-radius:50%;background:linear-gradient(135deg,#1d4ed8,#7c3aed);display:flex;align-items:center;justify-content:center;font-size:.75rem;font-weight:700;color:#fff;flex-shrink:0;}
.sb-uname{font-size:.78rem;font-weight:600;color:#fff;} .sb-urole{font-size:.62rem;color:rgba(255,255,255,.35);}
.sb-nav{flex:1;padding:10px 0;}
.sb-section{font-size:.6rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:rgba(255,255,255,.25);padding:10px 16px 4px;}
.sb-item{display:flex;align-items:center;gap:9px;padding:8px 16px;margin:1px 8px;border-radius:8px;cursor:pointer;color:rgba(255,255,255,.55);font-size:.82rem;font-weight:500;text-decoration:none;transition:all .15s;}
.sb-item:hover{background:rgba(255,255,255,.08);color:#fff;} .sb-item.active{background:rgba(37,99,235,.25);color:#60a5fa;font-weight:600;} .sb-item svg{width:15px;height:15px;flex-shrink:0;}
.sb-pill{margin-left:auto;background:#ef4444;color:#fff;font-size:.62rem;font-weight:700;padding:1px 6px;border-radius:100px;}
.sb-footer{padding:12px 14px;border-top:1px solid rgba(255,255,255,.08);}
.sb-logout{display:flex;align-items:center;gap:8px;font-size:.8rem;color:rgba(255,255,255,.35);text-decoration:none;padding:7px 8px;border-radius:8px;transition:all .15s;}
.sb-logout:hover{color:#f87171;background:rgba(239,68,68,.1);} .sb-logout svg{width:14px;height:14px;}
.main{margin-left:var(--sw);flex:1;display:flex;flex-direction:column;}
.topbar{height:58px;padding:0 26px;background:#fff;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:50;}
.topbar-title{font-size:1rem;font-weight:700;}
.super-chip{font-size:.7rem;font-weight:700;background:linear-gradient(135deg,#1d4ed8,#7c3aed);color:#fff;padding:3px 10px;border-radius:100px;}
.content{padding:22px 26px;flex:1;}
.stats-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:22px;}
.stat-card{background:var(--card);border:1px solid var(--border);border-radius:12px;padding:16px 18px;display:flex;align-items:flex-start;gap:12px;}
.stat-icon{width:40px;height:40px;border-radius:10px;display:flex;align-items:center;justify-content:center;flex-shrink:0;} .stat-icon svg{width:18px;height:18px;}
.stat-label{font-size:.7rem;font-weight:600;color:var(--text-dim);text-transform:uppercase;letter-spacing:.05em;margin-bottom:3px;}
.stat-value{font-size:1.5rem;font-weight:800;color:var(--text);line-height:1;} .stat-sub{font-size:.71rem;color:var(--text-dim);margin-top:2px;}
.card{background:var(--card);border:1px solid var(--border);border-radius:12px;padding:18px;margin-bottom:16px;}
.card-hdr{display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;flex-wrap:wrap;gap:8px;}
.card-title{font-size:.8rem;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:var(--text);}
.two-col{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px;}
table{width:100%;border-collapse:collapse;}
th{font-size:.67rem;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:var(--text-dim);padding:7px 11px;text-align:left;border-bottom:1px solid var(--border);white-space:nowrap;}
td{padding:10px 11px;font-size:.81rem;border-bottom:1px solid #f1f5f9;vertical-align:middle;}
tr:last-child td{border-bottom:none;} tr:hover td{background:#f8fafc;}
.badge{display:inline-flex;align-items:center;gap:3px;font-size:.67rem;font-weight:700;padding:2px 8px;border-radius:100px;}
.b-blue{background:#dbeafe;color:#1d4ed8;} .b-green{background:#dcfce7;color:#15803d;} .b-red{background:#fee2e2;color:#dc2626;} .b-yellow{background:#fef3c7;color:#b45309;} .b-purple{background:#f3e8ff;color:#7c3aed;} .b-gray{background:#f1f5f9;color:#475569;} .b-orange{background:#ffedd5;color:#c2410c;} .b-teal{background:#ccfbf1;color:#0f766e;}
.plan-ent{background:linear-gradient(135deg,#dbeafe,#ede9fe);color:#4338ca;border:1px solid #c7d2fe;} .plan-pro{background:#fef3c7;color:#b45309;} .plan-starter{background:#f1f5f9;color:#475569;}
.b-dot{width:4px;height:4px;border-radius:50%;background:currentColor;}
.btn-sm{padding:5px 12px;border-radius:7px;font-size:.73rem;font-weight:600;cursor:pointer;border:1px solid var(--border);background:#fff;color:var(--text-m);text-decoration:none;display:inline-flex;align-items:center;gap:5px;transition:all .15s;margin-right:4px;}
.btn-sm:hover{background:var(--bg);} .btn-primary{background:var(--blue-acc);color:#fff;border-color:var(--blue-acc);} .btn-success{background:var(--success);color:#fff;border-color:var(--success);} .btn-danger{background:var(--danger);color:#fff;border-color:var(--danger);} .btn-warning{background:var(--warning);color:#fff;border-color:var(--warning);}
.alert{padding:10px 16px;border-radius:10px;font-size:.82rem;margin-bottom:18px;display:flex;align-items:center;gap:8px;}
.alert-success{background:#f0fdf4;border:1px solid #bbf7d0;color:#15803d;} .alert-error{background:#fef2f2;border:1px solid #fecaca;color:#dc2626;}
.empty-state{text-align:center;padding:40px 20px;color:var(--text-dim);}
.empty-state svg{width:34px;height:34px;margin:0 auto 9px;display:block;opacity:.3;} .empty-state p{font-size:.83rem;}
.ticket-tag{font-family:monospace;font-size:.77rem;color:var(--blue-acc);font-weight:700;}
.chart-wrap{position:relative;height:220px;}
.donut-wrap{position:relative;height:200px;display:flex;align-items:center;justify-content:center;}
.legend-row{display:flex;align-items:center;gap:6px;font-size:.74rem;color:var(--text-m);margin-bottom:4px;}
.legend-dot{width:10px;height:10px;border-radius:50%;flex-shrink:0;}
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(15,23,42,.55);z-index:999;align-items:center;justify-content:center;backdrop-filter:blur(3px);}
.modal-overlay.open{display:flex;}
.modal{background:#fff;border-radius:16px;width:480px;max-width:95vw;max-height:92vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.2);animation:mIn .22s ease both;}
@keyframes mIn{from{opacity:0;transform:translateY(12px)}to{opacity:1;transform:none}}
.mhdr{padding:20px 22px 0;display:flex;align-items:center;justify-content:space-between;}
.mtitle{font-size:1rem;font-weight:800;} .msub{font-size:.78rem;color:var(--text-dim);margin-top:2px;}
.mclose{width:28px;height:28px;border-radius:7px;border:1.5px solid var(--border);background:#fff;cursor:pointer;display:flex;align-items:center;justify-content:center;color:var(--text-dim);}
.mclose svg{width:13px;height:13px;} .mbody{padding:18px 22px 22px;}
.flabel{display:block;font-size:.74rem;font-weight:600;color:var(--text-m);margin-bottom:4px;}
.finput{width:100%;border:1.5px solid var(--border);border-radius:8px;padding:9px 11px;font-family:inherit;font-size:.85rem;color:var(--text);outline:none;background:#fff;transition:border .2s;}
.finput:focus{border-color:var(--blue-acc);box-shadow:0 0 0 3px rgba(37,99,235,.1);} .finput::placeholder{color:#c8d0db;} select.finput{cursor:pointer;}
.filter-bar{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:16px;background:#fff;border:1px solid var(--border);border-radius:10px;padding:12px 16px;}
.filter-bar label{font-size:.74rem;font-weight:600;color:var(--text-dim);white-space:nowrap;}
.filter-select,.filter-input{border:1.5px solid var(--border);border-radius:7px;padding:6px 10px;font-family:inherit;font-size:.81rem;color:var(--text);outline:none;background:#fff;transition:border .2s;}
.filter-select:focus,.filter-input:focus{border-color:var(--blue-acc);}
.summary-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:16px;}
.summary-grid-3{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:16px;}
.summary-item{background:var(--bg);border:1px solid var(--border);border-radius:10px;padding:14px 16px;text-align:center;}
.summary-num{font-size:1.3rem;font-weight:800;color:var(--text);} .summary-lbl{font-size:.7rem;font-weight:600;color:var(--text-dim);text-transform:uppercase;letter-spacing:.04em;margin-top:2px;}
/* Audit action badge colors */
.act-approve,.act-activate{background:#dcfce7;color:#15803d;} .act-reject,.act-deactivate,.act-delete{background:#fee2e2;color:#dc2626;}
.act-login{background:#f3e8ff;color:#7c3aed;} .act-logout{background:#f1f5f9;color:#475569;}
.act-create,.act-add{background:#ccfbf1;color:#0f766e;} .act-update,.act-edit{background:#fef3c7;color:#b45309;} .act-other{background:#f1f5f9;color:#475569;}
/* Rank badges */
.rank-1{background:linear-gradient(135deg,#fef3c7,#fde68a);color:#92400e;border:1px solid #fcd34d;}
.rank-2{background:#f1f5f9;color:#475569;border:1px solid #e2e8f0;}
.rank-3{background:#fff7ed;color:#c2410c;border:1px solid #fed7aa;}
/* Pagination */
.pagination{display:flex;align-items:center;gap:6px;margin-top:16px;flex-wrap:wrap;}
.page-btn{padding:5px 11px;border-radius:7px;font-size:.74rem;font-weight:600;border:1.5px solid var(--border);background:#fff;color:var(--text-m);text-decoration:none;transition:all .15s;}
.page-btn:hover{background:var(--bg);} .page-btn.active{background:var(--blue-acc);color:#fff;border-color:var(--blue-acc);}
@media(max-width:1200px){.stats-grid,.summary-grid{grid-template-columns:repeat(2,1fr)}.two-col{grid-template-columns:1fr;}}
@media(max-width:600px){.stats-grid,.summary-grid,.summary-grid-3{grid-template-columns:1fr;}.filter-bar{flex-direction:column;align-items:flex-start;}}
</style>
</head>
<body>

<!-- ══ SIDEBAR ══════════════════════════════════════════════════ -->
<aside class="sidebar">
  <div class="sb-brand">
    <div class="sb-logo"><svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2.5"><rect x="3" y="9" width="18" height="12"/><polyline points="3 9 12 3 21 9"/></svg></div>
    <div><div class="sb-name">PawnHub</div><div class="sb-badge">Super Admin</div></div>
  </div>
  <div class="sb-user">
    <div class="sb-avatar"><?= strtoupper(substr($u['name'], 0, 1)) ?></div>
    <div><div class="sb-uname"><?= htmlspecialchars($u['name']) ?></div><div class="sb-urole">Super Administrator</div></div>
  </div>
  <nav class="sb-nav">
    <div class="sb-section">Overview</div>
    <a href="?page=dashboard" class="sb-item <?= $active_page==='dashboard'?'active':'' ?>">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>Dashboard
    </a>
    <div class="sb-section">Management</div>
    <a href="?page=tenants" class="sb-item <?= $active_page==='tenants'?'active':'' ?>">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="9" width="18" height="12"/><polyline points="3 9 12 3 21 9"/></svg>Tenant Management
      <?php if($pending_tenants>0):?><span class="sb-pill"><?=$pending_tenants?></span><?php endif;?>
    </a>
    <a href="?page=invitations" class="sb-item <?= $active_page==='invitations'?'active':'' ?>">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>Email Invitations
      <?php if($pending_inv>0):?><span class="sb-pill"><?=$pending_inv?></span><?php endif;?>
    </a>
    <div class="sb-section">Analytics</div>
    <a href="?page=reports" class="sb-item <?= $active_page==='reports'?'active':'' ?>">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>Reports
    </a>
    <a href="?page=sales_report" class="sb-item <?= $active_page==='sales_report'?'active':'' ?>">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>Sales Report
    </a>
    <div class="sb-section">System</div>
    <a href="?page=audit_logs" class="sb-item <?= $active_page==='audit_logs'?'active':'' ?>">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>Audit Logs
    </a>
    <a href="?page=settings" class="sb-item <?= $active_page==='settings'?'active':'' ?>">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>Settings
    </a>
  </nav>
  <div class="sb-footer">
    <a href="logout.php" class="sb-logout">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>Sign Out
    </a>
  </div>
</aside>

<!-- ══ MAIN ══════════════════════════════════════════════════════ -->
<div class="main">
  <header class="topbar">
    <div style="display:flex;align-items:center;gap:10px;">
      <span class="topbar-title">
        <?php $titles=['dashboard'=>'System Dashboard','tenants'=>'Tenant Management','invitations'=>'Email Invitations','reports'=>'Reports','sales_report'=>'Sales Report','audit_logs'=>'Audit Logs','settings'=>'System Settings'];
        echo $titles[$active_page]??'Dashboard'; ?>
      </span>
      <span class="super-chip">SUPER ADMIN</span>
    </div>
    <div style="display:flex;align-items:center;gap:10px;">
      <button onclick="document.getElementById('addTenantModal').classList.add('open')" class="btn-sm btn-primary">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="width:13px;height:13px;"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>Add Tenant + Invite
      </button>
      <div style="font-size:.78rem;color:var(--text-dim);"><?= date('F d, Y') ?></div>
    </div>
  </header>

  <div class="content">
    <?php if($success_msg):?><div class="alert alert-success">✅ <?=htmlspecialchars($success_msg)?></div><?php endif;?>
    <?php if($error_msg):?><div class="alert alert-error">⚠ <?=htmlspecialchars($error_msg)?></div><?php endif;?>

    <!-- ══ DASHBOARD ═══════════════════════════════════════════ -->
    <?php if($active_page==='dashboard'): ?>
      <div class="stats-grid">
        <div class="stat-card">
          <div class="stat-icon" style="background:#dbeafe;"><svg viewBox="0 0 24 24" fill="none" stroke="#2563eb" stroke-width="2"><rect x="3" y="9" width="18" height="12"/><polyline points="3 9 12 3 21 9"/></svg></div>
          <div><div class="stat-label">Total Tenants</div><div class="stat-value"><?=$total_tenants?></div><div class="stat-sub"><?=$active_tenants?> active · <?=$pending_tenants?> pending</div></div>
        </div>
        <div class="stat-card">
          <div class="stat-icon" style="background:#dcfce7;"><svg viewBox="0 0 24 24" fill="none" stroke="#16a34a" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg></div>
          <div><div class="stat-label">Total Users</div><div class="stat-value"><?=$total_users?></div><div class="stat-sub"><?=$active_users?> active · <?=$inactive_users?> inactive</div></div>
        </div>
        <div class="stat-card">
          <div class="stat-icon" style="background:#fef3c7;"><svg viewBox="0 0 24 24" fill="none" stroke="#d97706" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg></div>
          <div><div class="stat-label">Pending Approvals</div><div class="stat-value" style="color:var(--warning);"><?=$pending_tenants?></div><div class="stat-sub">Tenants awaiting review</div></div>
        </div>
        <?php try{$sc=(int)$pdo->query("SELECT COUNT(*) FROM users WHERE is_suspended=1 AND role!='super_admin'")->fetchColumn();}catch(PDOException $e){$sc=0;}?>
        <div class="stat-card">
          <div class="stat-icon" style="background:#fee2e2;"><svg viewBox="0 0 24 24" fill="none" stroke="#dc2626" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="17" y1="8" x2="23" y2="14"/><line x1="23" y1="8" x2="17" y2="14"/></svg></div>
          <div><div class="stat-label">Suspended Users</div><div class="stat-value" style="color:var(--danger);"><?=$sc?></div><div class="stat-sub">Across all tenants</div></div>
        </div>
      </div>

      <div class="two-col">
        <div class="card"><div class="card-hdr"><span class="card-title">👥 User Growth (6 Months)</span></div><div class="chart-wrap"><canvas id="userGrowthChart"></canvas></div></div>
        <div class="card"><div class="card-hdr"><span class="card-title">🏢 New Tenants (6 Months)</span></div><div class="chart-wrap"><canvas id="tenantActivityChart"></canvas></div></div>
      </div>

      <div class="two-col">
        <div class="card">
          <div class="card-hdr"><span class="card-title">👤 User Role Distribution</span></div>
          <?php try{$rc=$pdo->query("SELECT role,COUNT(*) AS cnt FROM users WHERE role!='super_admin' GROUP BY role")->fetchAll(PDO::FETCH_KEY_PAIR);}catch(PDOException $e){$rc=[];}
          $rca=(int)($rc['admin']??0);$rcs=(int)($rc['staff']??0);$rcc=(int)($rc['cashier']??0);?>
          <div style="display:flex;align-items:center;gap:24px;">
            <div class="donut-wrap" style="flex:1;"><canvas id="roleDonutChart"></canvas></div>
            <div style="flex-shrink:0;">
              <div class="legend-row"><span class="legend-dot" style="background:#2563eb;"></span>Admin — <?=$rca?></div>
              <div class="legend-row"><span class="legend-dot" style="background:#16a34a;"></span>Staff — <?=$rcs?></div>
              <div class="legend-row"><span class="legend-dot" style="background:#d97706;"></span>Cashier — <?=$rcc?></div>
              <div style="margin-top:10px;font-size:.72rem;color:var(--text-dim);border-top:1px solid var(--border);padding-top:8px;">Total: <?=$rca+$rcs+$rcc?> users</div>
            </div>
          </div>
        </div>
        <div class="card">
          <div class="card-hdr"><span class="card-title">⭐ Plan Distribution</span></div>
          <div style="display:flex;align-items:center;gap:24px;">
            <div class="donut-wrap" style="flex:1;"><canvas id="planDonutChart"></canvas></div>
            <div style="flex-shrink:0;">
              <div class="legend-row"><span class="legend-dot" style="background:#475569;"></span>Starter — <?=$plan_dist['Starter']?></div>
              <div class="legend-row"><span class="legend-dot" style="background:#b45309;"></span>Pro — <?=$plan_dist['Pro']?></div>
              <div class="legend-row"><span class="legend-dot" style="background:#4338ca;"></span>Enterprise — <?=$plan_dist['Enterprise']?></div>
              <div style="margin-top:10px;font-size:.72rem;color:var(--text-dim);border-top:1px solid var(--border);padding-top:8px;">Total: <?=$total_tenants?> tenants</div>
            </div>
          </div>
        </div>
      </div>

      <div class="two-col">
        <div class="card">
          <div class="card-hdr"><span class="card-title">📊 Tenant Status Overview</span></div>
          <div style="display:flex;flex-direction:column;gap:10px;">
            <?php foreach([['Active',$active_tenants,$total_tenants,'#16a34a'],['Pending',$pending_tenants,$total_tenants,'#d97706'],['Inactive',$inactive_tenants,$total_tenants,'#dc2626']] as [$lbl,$val,$tot,$color]):?>
            <div><div style="display:flex;justify-content:space-between;font-size:.78rem;font-weight:600;margin-bottom:4px;"><span><?=$lbl?></span><span style="color:<?=$color?>;"><?=$val?>/<?=$tot?></span></div><div style="height:7px;background:#f1f5f9;border-radius:100px;overflow:hidden;"><?php $pct=$tot>0?round($val/$tot*100):0;?><div style="height:100%;width:<?=$pct?>%;background:<?=$color?>;border-radius:100px;"></div></div></div>
            <?php endforeach;?>
          </div>
        </div>
        <div class="card">
          <div class="card-hdr"><span class="card-title">🕐 Recent Tenants</span><a href="?page=tenants" style="font-size:.74rem;color:var(--blue-acc);font-weight:600;text-decoration:none;">View All →</a></div>
          <?php if(empty($tenants)):?><div class="empty-state"><p>No tenants yet.</p></div>
          <?php else:?><div style="overflow-x:auto;"><table><thead><tr><th>Business</th><th>Plan</th><th>Status</th><th>Users</th><th>Date</th></tr></thead><tbody>
          <?php foreach(array_slice($tenants,0,6) as $t):?>
          <tr><td style="font-weight:600;"><?=htmlspecialchars($t['business_name'])?></td><td><span class="badge <?=$t['plan']==='Enterprise'?'plan-ent':($t['plan']==='Pro'?'plan-pro':'plan-starter')?>"><?=$t['plan']?></span></td><td><span class="badge <?=$t['status']==='active'?'b-green':($t['status']==='pending'?'b-yellow':'b-red')?>"><span class="b-dot"></span><?=ucfirst($t['status'])?></span></td><td><?=$t['user_count']?></td><td style="font-size:.73rem;color:var(--text-dim);"><?=date('M d, Y',strtotime($t['created_at']))?></td></tr>
          <?php endforeach;?></tbody></table></div><?php endif;?>
        </div>
      </div>

      <script>
      const cd={plugins:{legend:{display:false}},scales:{x:{grid:{display:false},ticks:{font:{size:10},color:'#94a3b8'}},y:{grid:{color:'#f1f5f9'},ticks:{font:{size:10},color:'#94a3b8'},beginAtZero:true}}};
      new Chart(document.getElementById('userGrowthChart'),{type:'bar',data:{labels:<?=json_encode(array_column($monthly_regs,'month_label'))?>,datasets:[{data:<?=json_encode(array_column($monthly_regs,'count'))?>,backgroundColor:'rgba(37,99,235,0.15)',borderColor:'#2563eb',borderWidth:2,borderRadius:6}]},options:{...cd,responsive:true,maintainAspectRatio:false}});
      new Chart(document.getElementById('tenantActivityChart'),{type:'bar',data:{labels:<?=json_encode(array_column($monthly_tenants,'month_label'))?>,datasets:[{data:<?=json_encode(array_column($monthly_tenants,'count'))?>,backgroundColor:'rgba(16,185,129,0.15)',borderColor:'#10b981',borderWidth:2,borderRadius:6}]},options:{...cd,responsive:true,maintainAspectRatio:false}});
      new Chart(document.getElementById('roleDonutChart'),{type:'doughnut',data:{labels:['Admin','Staff','Cashier'],datasets:[{data:[<?=$rca?>,<?=$rcs?>,<?=$rcc?>],backgroundColor:['#2563eb','#16a34a','#d97706'],borderWidth:0}]},options:{responsive:true,maintainAspectRatio:false,cutout:'68%',plugins:{legend:{display:false}}}});
      new Chart(document.getElementById('planDonutChart'),{type:'doughnut',data:{labels:['Starter','Pro','Enterprise'],datasets:[{data:[<?=$plan_dist['Starter']?>,<?=$plan_dist['Pro']?>,<?=$plan_dist['Enterprise']?>],backgroundColor:['#94a3b8','#d97706','#4338ca'],borderWidth:0}]},options:{responsive:true,maintainAspectRatio:false,cutout:'68%',plugins:{legend:{display:false}}}});
      </script>

    <!-- ══ TENANT MANAGEMENT ════════════════════════════════════ -->
    <?php elseif($active_page==='tenants'): ?>
      <div class="stats-grid">
        <div class="stat-card"><div class="stat-icon" style="background:#dbeafe;"><svg viewBox="0 0 24 24" fill="none" stroke="#2563eb" stroke-width="2"><rect x="3" y="9" width="18" height="12"/><polyline points="3 9 12 3 21 9"/></svg></div><div><div class="stat-label">Total</div><div class="stat-value"><?=$total_tenants?></div></div></div>
        <div class="stat-card"><div class="stat-icon" style="background:#dcfce7;"><svg viewBox="0 0 24 24" fill="none" stroke="#16a34a" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="9 12 11 14 15 10"/></svg></div><div><div class="stat-label">Active</div><div class="stat-value" style="color:var(--success);"><?=$active_tenants?></div></div></div>
        <div class="stat-card"><div class="stat-icon" style="background:#fef3c7;"><svg viewBox="0 0 24 24" fill="none" stroke="#d97706" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg></div><div><div class="stat-label">Pending</div><div class="stat-value" style="color:var(--warning);"><?=$pending_tenants?></div></div></div>
        <div class="stat-card"><div class="stat-icon" style="background:#fee2e2;"><svg viewBox="0 0 24 24" fill="none" stroke="#dc2626" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/></svg></div><div><div class="stat-label">Inactive</div><div class="stat-value" style="color:var(--danger);"><?=$inactive_tenants?></div></div></div>
      </div>

      <?php $pts=array_filter($tenants,fn($t)=>$t['status']==='pending');if(!empty($pts)):?>
      <div class="card" style="border-color:#fde68a;">
        <div class="card-hdr"><span class="card-title" style="color:#b45309;">⏳ Pending Approval (<?=count($pts)?>)</span></div>
        <div style="overflow-x:auto;"><table><thead><tr><th>Business Name</th><th>Owner</th><th>Email</th><th>Plan</th><th>Applied</th><th>Actions</th></tr></thead><tbody>
        <?php foreach($pts as $t):?>
        <tr><td style="font-weight:600;"><?=htmlspecialchars($t['business_name'])?></td><td><?=htmlspecialchars($t['owner_name'])?></td><td style="font-size:.76rem;color:var(--text-dim);"><?=htmlspecialchars($t['email'])?></td><td><span class="badge <?=$t['plan']==='Enterprise'?'plan-ent':($t['plan']==='Pro'?'plan-pro':'plan-starter')?>"><?=$t['plan']?></span></td><td style="font-size:.73rem;color:var(--text-dim);"><?=date('M d, Y',strtotime($t['created_at']))?></td>
        <td><button onclick="openApproveModal(<?=$t['id']?>,<?=(int)$t['admin_uid']?>,'<?=htmlspecialchars($t['business_name'],ENT_QUOTES)?>')" class="btn-sm btn-success" style="font-size:.7rem;">✓ Approve</button><button onclick="openRejectModal(<?=$t['id']?>,<?=(int)$t['admin_uid']?>,'<?=htmlspecialchars($t['business_name'],ENT_QUOTES)?>')" class="btn-sm btn-danger" style="font-size:.7rem;">✗ Reject</button></td></tr>
        <?php endforeach;?></tbody></table></div>
      </div>
      <?php endif;?>

      <div class="card" style="overflow-x:auto;">
        <div class="card-hdr"><span class="card-title">🏢 All Tenants</span><span style="font-size:.75rem;color:var(--text-dim);"><?=$total_tenants?> total</span></div>
        <?php if(empty($tenants)):?><div class="empty-state"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="9" width="18" height="12"/><polyline points="3 9 12 3 21 9"/></svg><p>No tenants yet.</p></div>
        <?php else:?><table><thead><tr><th>ID</th><th>Business Name</th><th>Owner</th><th>Email</th><th>Plan</th><th>Status</th><th>Users</th><th>Registered</th><th>Actions</th></tr></thead><tbody>
        <?php foreach($tenants as $t):?>
        <tr>
          <td style="color:var(--text-dim);font-size:.74rem;">#<?=$t['id']?></td>
          <td style="font-weight:600;"><?=htmlspecialchars($t['business_name'])?></td>
          <td style="font-size:.79rem;"><?=htmlspecialchars($t['owner_name'])?></td>
          <td style="font-size:.74rem;color:var(--text-dim);"><?=htmlspecialchars($t['email'])?></td>
          <td><span class="badge <?=$t['plan']==='Enterprise'?'plan-ent':($t['plan']==='Pro'?'plan-pro':'plan-starter')?>"><?=$t['plan']?></span></td>
          <td><span class="badge <?=$t['status']==='active'?'b-green':($t['status']==='pending'?'b-yellow':($t['status']==='inactive'?'b-red':'b-gray'))?>"><span class="b-dot"></span><?=ucfirst($t['status'])?></span></td>
          <td><?=$t['user_count']?></td>
          <td style="font-size:.73rem;color:var(--text-dim);"><?=date('M d, Y',strtotime($t['created_at']))?></td>
          <td>
            <?php if($t['status']==='active'):?><form method="POST" style="display:inline;" onsubmit="return confirm('Deactivate? Their users cannot login until reactivated.')"><input type="hidden" name="action" value="deactivate_tenant"><input type="hidden" name="tenant_id" value="<?=$t['id']?>"><button type="submit" class="btn-sm btn-danger" style="font-size:.7rem;">Deactivate</button></form>
            <button onclick="openPlanModal(<?=$t['id']?>,'<?=htmlspecialchars($t['business_name'],ENT_QUOTES)?>','<?=$t['plan']?>')" class="btn-sm btn-warning" style="font-size:.7rem;">⭐ Plan</button>
            <?php elseif($t['status']==='inactive'):?><form method="POST" style="display:inline;"><input type="hidden" name="action" value="activate_tenant"><input type="hidden" name="tenant_id" value="<?=$t['id']?>"><button type="submit" class="btn-sm btn-success" style="font-size:.7rem;">Activate</button></form>
            <button onclick="openPlanModal(<?=$t['id']?>,'<?=htmlspecialchars($t['business_name'],ENT_QUOTES)?>','<?=$t['plan']?>')" class="btn-sm btn-warning" style="font-size:.7rem;">⭐ Plan</button>
            <?php elseif($t['status']==='pending'):?><button onclick="openApproveModal(<?=$t['id']?>,<?=(int)$t['admin_uid']?>,'<?=htmlspecialchars($t['business_name'],ENT_QUOTES)?>')" class="btn-sm btn-success" style="font-size:.7rem;">✓ Approve</button><button onclick="openRejectModal(<?=$t['id']?>,<?=(int)$t['admin_uid']?>,'<?=htmlspecialchars($t['business_name'],ENT_QUOTES)?>')" class="btn-sm btn-danger" style="font-size:.7rem;">✗ Reject</button>
            <?php else:?><span style="font-size:.73rem;color:var(--text-dim);">—</span><?php endif;?>
          </td>
        </tr>
        <?php endforeach;?></tbody></table><?php endif;?>
      </div>

    <!-- ══ REPORTS ══════════════════════════════════════════════ -->
    <?php elseif($active_page==='reports'): ?>
      <div style="display:flex;gap:8px;margin-bottom:16px;flex-wrap:wrap;">
        <?php foreach(['tenant_activity'=>['🏢 Tenant Activity','#2563eb','#eff6ff'],'user_registration'=>['👤 User Registration','#16a34a','#f0fdf4'],'usage_statistics'=>['📊 Usage Statistics','#7c3aed','#f3e8ff']] as $rk=>[$rl,$rc2,$rb]):?>
        <a href="?page=reports&report_type=<?=$rk?>&date_from=<?=$filter_date_from?>&date_to=<?=$filter_date_to?>" style="padding:8px 16px;border-radius:9px;font-size:.8rem;font-weight:700;text-decoration:none;border:2px solid <?=$report_type===$rk?$rc2:'var(--border)'?>;background:<?=$report_type===$rk?$rb:'#fff'?>;color:<?=$report_type===$rk?$rc2:'var(--text-m)'?>;"><?=$rl?></a>
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
        <div class="summary-grid-3"><div class="summary-item"><div class="summary-num"><?=$rt?></div><div class="summary-lbl">Tenants</div></div><div class="summary-item"><div class="summary-num" style="color:var(--success);"><?=$rat?></div><div class="summary-lbl">Active</div></div><div class="summary-item"><div class="summary-num"><?=$ru?></div><div class="summary-lbl">Total Users</div></div></div>
        <div class="card" style="overflow-x:auto;"><div class="card-hdr"><span class="card-title">🏢 Tenant Activity Report</span><span style="font-size:.74rem;color:var(--text-dim);"><?=htmlspecialchars($filter_date_from)?> — <?=htmlspecialchars($filter_date_to)?></span></div>
        <?php if(empty($report_data)):?><div class="empty-state"><p>No data found.</p></div>
        <?php else:?><table><thead><tr><th>#</th><th>Business</th><th>Owner</th><th>Email</th><th>Plan</th><th>Status</th><th>Branches</th><th>Users</th><th>Admins</th><th>Staff</th><th>Cashiers</th><th>Registered</th></tr></thead><tbody>
        <?php foreach($report_data as $i=>$r):?><tr><td style="color:var(--text-dim);font-size:.73rem;"><?=$i+1?></td><td style="font-weight:600;"><?=htmlspecialchars($r['business_name'])?></td><td><?=htmlspecialchars($r['owner_name'])?></td><td style="font-size:.74rem;color:var(--text-dim);"><?=htmlspecialchars($r['email'])?></td><td><span class="badge <?=$r['plan']==='Enterprise'?'plan-ent':($r['plan']==='Pro'?'plan-pro':'plan-starter')?>"><?=$r['plan']?></span></td><td><span class="badge <?=$r['status']==='active'?'b-green':($r['status']==='pending'?'b-yellow':'b-red')?>"><span class="b-dot"></span><?=ucfirst($r['status'])?></span></td><td><?=$r['branches']?></td><td style="font-weight:700;"><?=$r['user_count']?></td><td><?=$r['admin_count']?></td><td><?=$r['staff_count']?></td><td><?=$r['cashier_count']?></td><td style="font-size:.73rem;color:var(--text-dim);"><?=date('M d, Y',strtotime($r['created_at']))?></td></tr><?php endforeach;?>
        </tbody><tfoot><tr style="background:#f8fafc;"><td colspan="7" style="font-weight:700;font-size:.78rem;color:var(--text-m);">TOTALS</td><td style="font-weight:800;"><?=$ru?></td><td style="font-weight:800;"><?=array_sum(array_column($report_data,'admin_count'))?></td><td style="font-weight:800;"><?=array_sum(array_column($report_data,'staff_count'))?></td><td style="font-weight:800;"><?=array_sum(array_column($report_data,'cashier_count'))?></td><td></td></tr></tfoot></table><?php endif;?></div>

      <?php elseif($report_type==='user_registration'):
        $rt=count($report_data);$ra=count(array_filter($report_data,fn($r)=>$r['status']==='approved'));$rp=count(array_filter($report_data,fn($r)=>$r['status']==='pending'));?>
        <div class="summary-grid-3"><div class="summary-item"><div class="summary-num"><?=$rt?></div><div class="summary-lbl">Registrations</div></div><div class="summary-item"><div class="summary-num" style="color:var(--success);"><?=$ra?></div><div class="summary-lbl">Approved</div></div><div class="summary-item"><div class="summary-num" style="color:var(--warning);"><?=$rp?></div><div class="summary-lbl">Pending</div></div></div>
        <div class="card" style="overflow-x:auto;"><div class="card-hdr"><span class="card-title">👤 User Registration Report</span><span style="font-size:.74rem;color:var(--text-dim);"><?=htmlspecialchars($filter_date_from)?> — <?=htmlspecialchars($filter_date_to)?></span></div>
        <?php if(empty($report_data)):?><div class="empty-state"><p>No data found.</p></div>
        <?php else:?><table><thead><tr><th>#</th><th>Name</th><th>Username</th><th>Email</th><th>Role</th><th>Tenant</th><th>Status</th><th>Suspended</th><th>Registered</th></tr></thead><tbody>
        <?php foreach($report_data as $i=>$r):?><tr><td style="color:var(--text-dim);font-size:.73rem;"><?=$i+1?></td><td style="font-weight:600;"><?=htmlspecialchars($r['fullname'])?></td><td style="font-family:monospace;font-size:.77rem;color:var(--blue-acc);"><?=htmlspecialchars($r['username'])?></td><td style="font-size:.74rem;color:var(--text-dim);"><?=htmlspecialchars($r['email'])?></td><td><span class="badge <?=['admin'=>'b-blue','staff'=>'b-green','cashier'=>'b-yellow'][$r['role']]??'b-gray'?>"><?=ucfirst($r['role'])?></span></td><td style="font-size:.78rem;"><?=htmlspecialchars($r['business_name']??'—')?></td><td><span class="badge <?=$r['status']==='approved'?'b-green':($r['status']==='pending'?'b-yellow':'b-red')?>"><?=ucfirst($r['status'])?></span></td><td><?=$r['is_suspended']?'<span class="badge b-red">Yes</span>':'<span class="badge b-green">No</span>'?></td><td style="font-size:.73rem;color:var(--text-dim);"><?=date('M d, Y',strtotime($r['created_at']))?></td></tr><?php endforeach;?>
        </tbody></table><?php endif;?></div>

      <?php elseif($report_type==='usage_statistics'):
        $rtu=array_sum(array_column($report_data,'total_users'));$rau=array_sum(array_column($report_data,'active_users'));$rsu=array_sum(array_column($report_data,'suspended_users'));?>
        <div class="summary-grid-3"><div class="summary-item"><div class="summary-num"><?=$rtu?></div><div class="summary-lbl">Total Users</div></div><div class="summary-item"><div class="summary-num" style="color:var(--success);"><?=$rau?></div><div class="summary-lbl">Active</div></div><div class="summary-item"><div class="summary-num" style="color:var(--danger);"><?=$rsu?></div><div class="summary-lbl">Suspended</div></div></div>
        <div class="card" style="overflow-x:auto;"><div class="card-hdr"><span class="card-title">📊 Usage Statistics — User Breakdown per Tenant</span></div>
        <?php if(empty($report_data)):?><div class="empty-state"><p>No data found.</p></div>
        <?php else:?><table><thead><tr><th>#</th><th>Tenant</th><th>Plan</th><th>Status</th><th>Branches</th><th>Total</th><th>Admins</th><th>Staff</th><th>Cashiers</th><th>Active</th><th>Suspended</th></tr></thead><tbody>
        <?php foreach($report_data as $i=>$r):?><tr><td style="color:var(--text-dim);font-size:.73rem;"><?=$i+1?></td><td style="font-weight:600;"><?=htmlspecialchars($r['business_name'])?></td><td><span class="badge <?=$r['plan']==='Enterprise'?'plan-ent':($r['plan']==='Pro'?'plan-pro':'plan-starter')?>"><?=$r['plan']?></span></td><td><span class="badge <?=$r['status']==='active'?'b-green':($r['status']==='pending'?'b-yellow':'b-red')?>"><span class="b-dot"></span><?=ucfirst($r['status'])?></span></td><td><?=$r['branches']?></td><td style="font-weight:700;"><?=$r['total_users']?></td><td><?=$r['admin_count']?></td><td><?=$r['staff_count']?></td><td><?=$r['cashier_count']?></td><td><span class="badge b-green"><?=$r['active_users']?></span></td><td><span class="badge <?=$r['suspended_users']>0?'b-red':'b-gray'?>"><?=$r['suspended_users']?></span></td></tr><?php endforeach;?>
        </tbody><tfoot><tr style="background:#f8fafc;"><td colspan="5" style="font-weight:700;font-size:.78rem;color:var(--text-m);">TOTALS</td><td style="font-weight:800;"><?=$rtu?></td><td style="font-weight:800;"><?=array_sum(array_column($report_data,'admin_count'))?></td><td style="font-weight:800;"><?=array_sum(array_column($report_data,'staff_count'))?></td><td style="font-weight:800;"><?=array_sum(array_column($report_data,'cashier_count'))?></td><td style="font-weight:800;color:var(--success);"><?=$rau?></td><td style="font-weight:800;color:var(--danger);"><?=$rsu?></td></tr></tfoot></table><?php endif;?></div>
      <?php endif;?>

    <!-- ══ SALES REPORT ═════════════════════════════════════════ -->
    <?php elseif($active_page==='sales_report'): ?>

      <form method="GET"><input type="hidden" name="page" value="sales_report">
        <div class="filter-bar">
          <label>Period:</label>
          <select name="sales_period" class="filter-select">
            <option value="daily"   <?=$sales_period==='daily'  ?'selected':''?>>Daily</option>
            <option value="weekly"  <?=$sales_period==='weekly' ?'selected':''?>>Weekly</option>
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

      <!-- Summary Cards -->
      <div class="summary-grid">
        <div class="summary-item"><div class="summary-num" style="color:var(--success);">₱<?=number_format($sales_summary['total_revenue']??0,2)?></div><div class="summary-lbl">Total Revenue</div></div>
        <div class="summary-item"><div class="summary-num" style="color:var(--blue-acc);"><?=number_format($sales_summary['total_transactions']??0)?></div><div class="summary-lbl">Total Transactions</div></div>
        <div class="summary-item"><div class="summary-num"><?=$sales_summary['active_tenants']??0?></div><div class="summary-lbl">Active Tenants</div></div>
        <div class="summary-item"><div class="summary-num" style="color:#7c3aed;">₱<?=number_format($sales_summary['avg_transaction']??0,2)?></div><div class="summary-lbl">Avg. Transaction</div></div>
      </div>

      <!-- Revenue Trend Chart -->
      <div class="card">
        <div class="card-hdr"><span class="card-title">📈 Revenue Trend — <?=ucfirst($sales_period)?></span><span style="font-size:.74rem;color:var(--text-dim);"><?=htmlspecialchars($sales_date_from)?> — <?=htmlspecialchars($sales_date_to)?></span></div>
        <?php if(empty($sales_data)):?><div class="empty-state"><p>No transaction data for the selected period.</p></div>
        <?php else:?><div class="chart-wrap" style="height:260px;"><canvas id="salesTrendChart"></canvas></div>
        <script>
        new Chart(document.getElementById('salesTrendChart'),{type:'line',data:{labels:<?=json_encode($sales_chart_labels)?>,datasets:[{label:'Revenue (₱)',data:<?=json_encode(array_map('floatval',$sales_chart_data))?>,borderColor:'#2563eb',backgroundColor:'rgba(37,99,235,0.08)',borderWidth:2.5,tension:0.4,fill:true,pointRadius:3,pointBackgroundColor:'#2563eb'},{label:'Transactions',data:<?=json_encode(array_map('intval',array_column($sales_data,'tx_count')))?>,borderColor:'#10b981',backgroundColor:'transparent',borderWidth:2,tension:0.4,pointRadius:3,pointBackgroundColor:'#10b981',yAxisID:'y2'}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:true,labels:{font:{size:11},boxWidth:12}}},scales:{x:{grid:{display:false},ticks:{font:{size:10},color:'#94a3b8'}},y:{grid:{color:'#f1f5f9'},ticks:{font:{size:10},color:'#94a3b8'},beginAtZero:true,title:{display:true,text:'Revenue (₱)',font:{size:10},color:'#94a3b8'}},y2:{position:'right',grid:{display:false},ticks:{font:{size:10},color:'#10b981'},beginAtZero:true,title:{display:true,text:'Transactions',font:{size:10},color:'#10b981'}}}}});
        </script><?php endif;?>
      </div>

      <div class="two-col">
        <!-- Sales Per Tenant Table -->
        <div class="card" style="overflow-x:auto;">
          <div class="card-hdr"><span class="card-title">🏢 Sales Per Tenant</span></div>
          <?php if(empty($sales_per_tenant)):?><div class="empty-state"><p>No data.</p></div>
          <?php else:?><table><thead><tr><th>Rank</th><th>Tenant</th><th>Plan</th><th>Tx Count</th><th>Revenue (₱)</th><th>Avg (₱)</th><th>Last Tx</th></tr></thead><tbody>
          <?php foreach($sales_per_tenant as $i=>$r):?>
          <tr><td><span class="badge <?=$i===0?'rank-1':($i===1?'rank-2':($i===2?'rank-3':'b-gray'))?>">#<?=$i+1?></span></td><td style="font-weight:600;"><?=htmlspecialchars($r['business_name'])?></td><td><span class="badge <?=$r['plan']==='Enterprise'?'plan-ent':($r['plan']==='Pro'?'plan-pro':'plan-starter')?>"><?=$r['plan']?></span></td><td style="font-weight:700;"><?=number_format($r['tx_count'])?></td><td style="font-weight:700;color:var(--success);">₱<?=number_format($r['revenue'],2)?></td><td>₱<?=number_format($r['avg_tx'],2)?></td><td style="font-size:.73rem;color:var(--text-dim);"><?=$r['last_tx']?date('M d, Y',strtotime($r['last_tx'])):'—'?></td></tr>
          <?php endforeach;?></tbody>
          <tfoot><tr style="background:#f8fafc;"><td colspan="3" style="font-weight:700;font-size:.78rem;color:var(--text-m);">TOTALS</td><td style="font-weight:800;"><?=number_format(array_sum(array_column($sales_per_tenant,'tx_count')))?></td><td style="font-weight:800;color:var(--success);">₱<?=number_format(array_sum(array_column($sales_per_tenant,'revenue')),2)?></td><td colspan="2"></td></tr></tfoot>
          </table><?php endif;?>
        </div>

        <!-- Top 5 Tenants Bar -->
        <div class="card">
          <div class="card-hdr"><span class="card-title">🏆 Top Performing Tenants</span></div>
          <?php if(empty($top_tenants)):?><div class="empty-state"><p>No data.</p></div>
          <?php else:$mx=max(array_column($top_tenants,'revenue'));$bar_colors=['#2563eb','#10b981','#d97706','#7c3aed','#dc2626'];
          foreach($top_tenants as $i=>$r):$bp=$mx>0?round($r['revenue']/$mx*100):0;$bc=$bar_colors[$i]??'#94a3b8';?>
          <div style="margin-bottom:14px;">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px;">
              <div style="display:flex;align-items:center;gap:8px;"><span style="font-size:.7rem;font-weight:800;color:<?=$bc?>;background:<?=$bc?>20;width:20px;height:20px;border-radius:50%;display:flex;align-items:center;justify-content:center;"><?=$i+1?></span><span style="font-size:.8rem;font-weight:600;"><?=htmlspecialchars($r['business_name'])?></span></div>
              <span style="font-size:.78rem;font-weight:700;color:var(--success);">₱<?=number_format($r['revenue'],0)?></span>
            </div>
            <div style="height:7px;background:#f1f5f9;border-radius:100px;overflow:hidden;"><div style="height:100%;width:<?=$bp?>%;background:<?=$bc?>;border-radius:100px;"></div></div>
            <div style="font-size:.7rem;color:var(--text-dim);margin-top:2px;"><?=number_format($r['tx_count'])?> transactions</div>
          </div>
          <?php endforeach;endif;?>
        </div>
      </div>

      <!-- Transaction History -->
      <div class="card" style="overflow-x:auto;">
        <div class="card-hdr"><span class="card-title">📋 Transaction History (Latest 100)</span><span style="font-size:.74rem;color:var(--text-dim);"><?=htmlspecialchars($sales_date_from)?> — <?=htmlspecialchars($sales_date_to)?></span></div>
        <?php if(empty($tx_history)):?><div class="empty-state"><p>No transactions found.</p></div>
        <?php else:?><table><thead><tr><th>#</th><th>Tenant</th><th>Ticket No.</th><th>Amount (₱)</th><th>Status</th><th>Date</th></tr></thead><tbody>
        <?php foreach($tx_history as $i=>$tx):$ts=$tx['status']??'';?>
        <tr><td style="color:var(--text-dim);font-size:.73rem;"><?=$i+1?></td><td style="font-weight:600;font-size:.79rem;"><?=htmlspecialchars($tx['business_name']??'—')?></td><td class="ticket-tag"><?=htmlspecialchars($tx['ticket_no']??$tx['id'])?></td><td style="font-weight:700;color:var(--success);">₱<?=number_format($tx['loan_amount']??0,2)?></td><td><span class="badge <?=$ts==='active'?'b-green':($ts==='redeemed'?'b-blue':($ts==='forfeited'?'b-red':'b-gray'))?>"><?=ucfirst($ts)?></span></td><td style="font-size:.73rem;color:var(--text-dim);"><?=date('M d, Y h:i A',strtotime($tx['created_at']))?></td></tr>
        <?php endforeach;?></tbody></table><?php endif;?>
      </div>

    <!-- ══ SETTINGS PAGE ═══════════════════════════════════════ -->
    <?php elseif($active_page==='settings'): ?>

      <form method="POST">
        <input type="hidden" name="action" value="save_system_settings">

        <!-- System Branding -->
        <div class="card" style="margin-bottom:16px;">
          <div class="card-hdr"><span class="card-title">🎨 System Branding</span></div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
            <div>
              <label class="flabel">System Name</label>
              <input type="text" name="system_name" class="finput" value="<?=htmlspecialchars($ss['system_name'])?>" placeholder="PawnHub">
              <div style="font-size:.71rem;color:var(--text-dim);margin-top:4px;">Shown in the browser title and sidebar.</div>
            </div>
            <div>
              <label class="flabel">System Tagline</label>
              <input type="text" name="system_tagline" class="finput" value="<?=htmlspecialchars($ss['system_tagline'])?>" placeholder="Multi-Tenant Pawnshop Management">
              <div style="font-size:.71rem;color:var(--text-dim);margin-top:4px;">Shown on the login page.</div>
            </div>
          </div>
        </div>

        <!-- Subscription Plan Settings -->
        <div class="card" style="margin-bottom:16px;">
          <div class="card-hdr">
            <span class="card-title">📦 Subscription Plan Limits</span>
            <span style="font-size:.74rem;color:var(--text-dim);">These limits are enforced per tenant based on their plan.</span>
          </div>

          <!-- Plan Cards -->
          <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:16px;">

            <!-- STARTER -->
            <div style="border:2px solid #e2e8f0;border-radius:12px;overflow:hidden;">
              <div style="background:#f1f5f9;padding:14px 16px;border-bottom:1px solid #e2e8f0;">
                <div style="font-size:.9rem;font-weight:800;color:#475569;">Starter</div>
                <div style="font-size:.75rem;color:#94a3b8;margin-top:2px;">Basic pawnshop operations</div>
              </div>
              <div style="padding:16px;display:flex;flex-direction:column;gap:12px;">
                <div>
                  <label class="flabel">Price / Label</label>
                  <input type="text" name="starter_price" class="finput" value="<?=htmlspecialchars($ss['starter_price'])?>" placeholder="Free">
                </div>
                <div>
                  <label class="flabel">Max Staff + Cashiers</label>
                  <input type="number" name="starter_staff" class="finput" value="<?=(int)$ss['starter_staff']?>" min="1" max="999">
                  <div style="font-size:.7rem;color:var(--text-dim);margin-top:3px;">Combined staff + cashier limit per branch</div>
                </div>
                <div style="background:#f8fafc;border-radius:8px;padding:10px;font-size:.75rem;color:var(--text-m);">
                  <div>✅ 1 Branch (fixed)</div>
                  <div>✅ Pawn tickets</div>
                  <div>✅ Customer management</div>
                  <div>✅ Basic reports</div>
                </div>
              </div>
            </div>

            <!-- PRO -->
            <div style="border:2px solid #bfdbfe;border-radius:12px;overflow:hidden;">
              <div style="background:#eff6ff;padding:14px 16px;border-bottom:1px solid #bfdbfe;">
                <div style="font-size:.9rem;font-weight:800;color:#1d4ed8;">Pro</div>
                <div style="font-size:.75rem;color:#93c5fd;margin-top:2px;">Growing pawnshop business</div>
              </div>
              <div style="padding:16px;display:flex;flex-direction:column;gap:12px;">
                <div>
                  <label class="flabel">Price / Label</label>
                  <input type="text" name="pro_price" class="finput" value="<?=htmlspecialchars($ss['pro_price'])?>" placeholder="₱999/mo">
                </div>
                <div>
                  <label class="flabel">Max Staff + Cashiers <span style="color:#94a3b8;">(0 = unlimited)</span></label>
                  <input type="number" name="pro_staff" class="finput" value="<?=(int)$ss['pro_staff']?>" min="0">
                  <div style="font-size:.7rem;color:var(--text-dim);margin-top:3px;">0 means no limit. Per branch.</div>
                </div>
                <div style="background:#f8fafc;border-radius:8px;padding:10px;font-size:.75rem;color:var(--text-m);">
                  <div>✅ Up to 3 Branches</div>
                  <div>✅ Everything in Starter</div>
                  <div>✅ Unlimited staff</div>
                  <div>✅ Advanced reports</div>
                </div>
              </div>
            </div>

            <!-- ENTERPRISE -->
            <div style="border:2px solid #ddd6fe;border-radius:12px;overflow:hidden;">
              <div style="background:#f3e8ff;padding:14px 16px;border-bottom:1px solid #ddd6fe;">
                <div style="font-size:.9rem;font-weight:800;color:#7c3aed;">Enterprise</div>
                <div style="font-size:.75rem;color:#c4b5fd;margin-top:2px;">Large pawnshop chains</div>
              </div>
              <div style="padding:16px;display:flex;flex-direction:column;gap:12px;">
                <div>
                  <label class="flabel">Price / Label</label>
                  <input type="text" name="ent_price" class="finput" value="<?=htmlspecialchars($ss['ent_price'])?>" placeholder="₱2,499/mo">
                </div>
                <div>
                  <label class="flabel">Max Staff + Cashiers <span style="color:#94a3b8;">(0 = unlimited)</span></label>
                  <input type="number" name="ent_staff" class="finput" value="<?=(int)$ss['ent_staff']?>" min="0">
                  <div style="font-size:.7rem;color:var(--text-dim);margin-top:3px;">0 means no limit. Per branch.</div>
                </div>
                <div style="background:#f8fafc;border-radius:8px;padding:10px;font-size:.75rem;color:var(--text-m);">
                  <div>✅ Up to 10 Branches</div>
                  <div>✅ Everything in Pro</div>
                  <div>✅ Dedicated support</div>
                  <div>✅ Custom branding</div>
                  <div>✅ Priority processing</div>
                </div>
              </div>
            </div>

          </div><!-- /plan grid -->
        </div>

        <!-- User Role Permissions Info -->
        <div class="card" style="margin-bottom:16px;">
          <div class="card-hdr"><span class="card-title">👤 User Role Permissions</span></div>
          <div style="overflow-x:auto;">
            <table>
              <thead><tr><th>Permission</th><th style="text-align:center;">Super Admin</th><th style="text-align:center;">Admin (Owner)</th><th style="text-align:center;">Manager</th><th style="text-align:center;">Staff</th><th style="text-align:center;">Cashier</th></tr></thead>
              <tbody>
                <?php
                // [label, super_admin, admin/owner, manager, staff, cashier]
                $perms = [
                  ['Manage Tenants',          true,  false, false, false, false],
                  ['Approve/Reject Tenants',  true,  false, false, false, false],
                  ['View Sales Report',       true,  false, false, false, false],
                  ['View Audit Logs',         true,  false, false, false, false],
                  ['System Settings',         true,  false, false, false, false],
                  ['Invite Managers',         false, true,  false, false, false],
                  ['Theme & Branding',        false, true,  false, false, false],
                  ['View Tenant Reports',     false, true,  false, false, false],
                  ['Manage Staff/Cashiers',   false, false, true,  false, false],
                  ['Approve Void Requests',   false, false, true,  false, false],
                  ['Approve Renewals',        false, false, true,  false, false],
                  ['Create Pawn Tickets',     false, false, false, true,  false],
                  ['Register Customers',      false, false, false, true,  false],
                  ['Request Void',            false, false, false, true,  false],
                  ['Process Payment',         false, false, false, true,  true ],
                  ['View Ticket Status',      false, true,  true,  true,  true ],
                ];
                foreach($perms as [$label,$sa,$ow,$mg,$st,$ca]):?>
                <tr>
                  <td style="font-size:.8rem;font-weight:500;"><?=$label?></td>
                  <?php foreach([$sa,$ow,$mg,$st,$ca] as $allowed):?>
                  <td style="text-align:center;">
                    <?php if($allowed):?>
                      <span style="color:#16a34a;font-size:1rem;">✓</span>
                    <?php else:?>
                      <span style="color:#e2e8f0;font-size:1rem;">—</span>
                    <?php endif;?>
                  </td>
                  <?php endforeach;?>
                </tr>
                <?php endforeach;?>
              </tbody>
            </table>
          </div>
          <div style="margin-top:12px;font-size:.75rem;color:var(--text-dim);background:#f8fafc;border-radius:8px;padding:10px 14px;">
            ℹ️ Role permissions are fixed by the system. Contact the developer to modify role-level access.
          </div>
        </div>

        <div style="display:flex;justify-content:flex-end;margin-bottom:20px;">
          <button type="submit" class="btn-sm btn-primary" style="padding:10px 24px;font-size:.88rem;">
            💾 Save Settings
          </button>
        </div>
      </form>

    <?php elseif($active_page==='audit_logs'): ?>

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
        <div class="summary-item"><div class="summary-num" style="color:var(--blue-acc);"><?=$audit_page?>/<?=$audit_total_pages?></div><div class="summary-lbl">Page</div></div>
        <div class="summary-item"><div class="summary-num" style="font-size:.85rem;color:var(--text-dim);"><?=htmlspecialchars($audit_date_from)?> — <?=htmlspecialchars($audit_date_to)?></div><div class="summary-lbl">Date Range</div></div>
      </div>

      <div class="card" style="overflow-x:auto;">
        <div class="card-hdr"><span class="card-title">📋 Audit Logs</span><span style="font-size:.74rem;color:var(--text-dim);">Showing <?=count($audit_logs)?> of <?=number_format($audit_total)?> entries</span></div>
        <?php if(empty($audit_logs)):?>
          <div class="empty-state">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
            <p>No audit log entries found for the selected filters.</p>
            <p style="font-size:.75rem;margin-top:4px;color:#b0b8c8;">Logs are recorded automatically as Super Admin actions are performed.</p>
          </div>
        <?php else:?>
          <table>
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
              <td style="font-size:.73rem;color:var(--text-dim);white-space:nowrap;"><?=date('M d, Y h:i A',strtotime($log['created_at']))?></td>
              <td style="font-weight:600;font-size:.79rem;"><?=htmlspecialchars($log['actor_username']??'—')?></td>
              <td><span class="badge <?=['super_admin'=>'b-purple','admin'=>'b-blue','staff'=>'b-green','cashier'=>'b-yellow'][$log['actor_role']??'']??'b-gray'?>"><?=ucwords(str_replace('_',' ',$log['actor_role']??'—'))?></span></td>
              <td><span class="badge <?=$ac?>" style="font-size:.65rem;letter-spacing:.03em;"><?=htmlspecialchars($log['action']??'—')?></span></td>
              <td style="font-size:.74rem;"><?php if(!empty($log['entity_type'])):?><span style="color:var(--text-dim);"><?=htmlspecialchars(ucfirst($log['entity_type']))?></span><?php if(!empty($log['entity_id'])):?> <span class="ticket-tag">#<?=htmlspecialchars($log['entity_id'])?></span><?php endif;?><?php else:?>—<?php endif;?></td>
              <td style="font-size:.77rem;color:var(--text-m);max-width:280px;"><?=htmlspecialchars($log['message']??'—')?></td>
            </tr>
            <?php endforeach;?>
            </tbody>
          </table>

          <?php if($audit_total_pages>1):
            $bu="?page=audit_logs&audit_from=".urlencode($audit_date_from)."&audit_to=".urlencode($audit_date_to)."&audit_action=".urlencode($audit_action)."&audit_actor=".urlencode($audit_actor);?>
          <div class="pagination">
            <?php if($audit_page>1):?><a href="<?=$bu?>&audit_page=<?=$audit_page-1?>" class="page-btn">← Prev</a><?php endif;?>
            <?php $ps=max(1,$audit_page-2);$pe=min($audit_total_pages,$audit_page+2);
            if($ps>1){echo '<a href="'.$bu.'&audit_page=1" class="page-btn">1</a>';if($ps>2)echo '<span style="color:var(--text-dim);padding:0 4px;">...</span>';}
            for($p=$ps;$p<=$pe;$p++):?><a href="<?=$bu?>&audit_page=<?=$p?>" class="page-btn <?=$p===$audit_page?'active':''?>"><?=$p?></a><?php endfor;
            if($pe<$audit_total_pages){if($pe<$audit_total_pages-1)echo '<span style="color:var(--text-dim);padding:0 4px;">...</span>';echo '<a href="'.$bu.'&audit_page='.$audit_total_pages.'" class="page-btn">'.$audit_total_pages.'</a>';}?>
            <?php if($audit_page<$audit_total_pages):?><a href="<?=$bu?>&audit_page=<?=$audit_page+1?>" class="page-btn">Next →</a><?php endif;?>
            <span style="font-size:.73rem;color:var(--text-dim);margin-left:6px;">Page <?=$audit_page?> of <?=$audit_total_pages?> · <?=number_format($audit_total)?> total</span>
          </div>
          <?php endif;?>
        <?php endif;?>
      </div>

    <!-- ══ INVITATIONS PAGE ════════════════════════════════════ -->
    <?php elseif($active_page==='invitations'): ?>
      <div class="card" style="overflow-x:auto;">
        <div class="card-hdr">
          <span class="card-title">📧 Email Invitations</span>
          <span style="font-size:.75rem;color:var(--text-dim);"><?=count($invitations)?> total · <?=$pending_inv?> pending</span>
        </div>
        <?php if(empty($invitations)):?>
          <div class="empty-state">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/></svg>
            <p>No invitations sent yet. Click "Add Tenant + Invite" to get started.</p>
          </div>
        <?php else:?>
          <table>
            <thead><tr><th>Tenant</th><th>Owner</th><th>Email</th><th>Plan</th><th>Status</th><th>Expires</th><th>Sent</th><th>Actions</th></tr></thead>
            <tbody>
            <?php foreach($invitations as $inv):?>
            <tr>
              <td style="font-weight:600;"><?=htmlspecialchars($inv['business_name'])?></td>
              <td><?=htmlspecialchars($inv['owner_name'])?></td>
              <td style="font-family:monospace;font-size:.76rem;"><?=htmlspecialchars($inv['email'])?></td>
              <td><span class="badge <?=$inv['plan']==='Enterprise'?'plan-ent':($inv['plan']==='Pro'?'plan-pro':'plan-starter')?>"><?=$inv['plan']?></span></td>
              <td><span class="badge <?=$inv['status']==='used'?'b-green':($inv['status']==='pending'?'b-yellow':'b-red')?>"><span class="b-dot"></span><?=ucfirst($inv['status'])?></span></td>
              <td style="font-size:.75rem;color:<?=strtotime($inv['expires_at'])<time()&&$inv['status']==='pending'?'var(--danger)':'var(--text-dim)'?>;"><?=date('M d, Y h:i A',strtotime($inv['expires_at']))?></td>
              <td style="font-size:.74rem;color:var(--text-dim);"><?=date('M d, Y',strtotime($inv['created_at']))?></td>
              <td>
                <?php if($inv['status']==='pending'):?>
                <form method="POST" style="display:inline;">
                  <input type="hidden" name="action" value="resend_invite">
                  <input type="hidden" name="inv_id" value="<?=$inv['id']?>">
                  <button type="submit" class="btn-sm btn-warning" style="font-size:.7rem;">📧 Resend</button>
                </form>
                <?php else:?><span style="font-size:.74rem;color:var(--text-dim);">✓ Registered</span><?php endif;?>
              </td>
            </tr>
            <?php endforeach;?>
            </tbody>
          </table>
        <?php endif;?>
      </div>

    <?php endif;?>
  </div><!-- /content -->
</div><!-- /main -->

<!-- ══ ADD TENANT MODAL ════════════════════════════════════════ -->
<div class="modal-overlay" id="addTenantModal">
  <div class="modal" style="width:560px;">
    <div class="mhdr">
      <div><div class="mtitle">➕ Add Tenant + Send Invite</div><div class="msub">An invitation link will be sent to the owner's Gmail.</div></div>
      <button class="mclose" onclick="document.getElementById('addTenantModal').classList.remove('open')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
    </div>
    <div class="mbody">
      <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:9px;padding:11px 14px;font-size:.78rem;color:#15803d;margin-bottom:16px;line-height:1.8;">📧 <strong>Flow:</strong> Fill form → Token generated → Email sent to owner → Owner clicks link → Owner sets username & password → Owner accesses system ✅</div>
      <form method="POST">
        <input type="hidden" name="action" value="add_tenant">
        <div style="font-size:.7rem;font-weight:700;letter-spacing:.07em;text-transform:uppercase;color:var(--text-dim);margin-bottom:10px;display:block;">Business Information</div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px;">
          <div style="grid-column:1/-1;"><label class="flabel">Business Name *</label><input type="text" name="business_name" class="finput" placeholder="GoldKing Pawnshop" required></div>
          <div><label class="flabel">Owner Full Name *</label><input type="text" name="owner_name" class="finput" placeholder="Juan Dela Cruz" required></div>
          <div><label class="flabel">Owner Gmail *</label><input type="email" name="email" class="finput" placeholder="owner@gmail.com" required></div>
          <div><label class="flabel">Phone</label><input type="text" name="phone" class="finput" placeholder="09XXXXXXXXX"></div>
          <div><label class="flabel">Address</label><input type="text" name="address" class="finput" placeholder="Street, City, Province"></div>
          <div><label class="flabel">Plan *</label><select name="plan" class="finput"><option value="Starter">Starter — Free</option><option value="Pro">Pro — ₱999/mo</option><option value="Enterprise">Enterprise — ₱2,499/mo</option></select></div>
          <div><label class="flabel">Branches</label><input type="number" name="branches" class="finput" value="1" min="1"></div>
        </div>
        <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:10px 14px;font-size:.77rem;color:#1d4ed8;margin-bottom:14px;">ℹ️ No password needed — the owner will set their own via the invitation link sent to their Gmail.</div>
        <div style="display:flex;justify-content:flex-end;gap:9px;">
          <button type="button" class="btn-sm" onclick="document.getElementById('addTenantModal').classList.remove('open')">Cancel</button>
          <button type="submit" class="btn-sm btn-primary">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="width:13px;height:13px;"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>Create + Send Invite
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ══ CHANGE PLAN MODAL ═══════════════════════════════════════ -->
<div class="modal-overlay" id="planModal">
  <div class="modal" style="width:440px;">
    <div class="mhdr">
      <div><div class="mtitle">⭐ Change Tenant Plan</div><div class="msub" id="plan_modal_sub"></div></div>
      <button class="mclose" onclick="document.getElementById('planModal').classList.remove('open')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
    </div>
    <div class="mbody">
      <form method="POST">
        <input type="hidden" name="action" value="change_plan">
        <input type="hidden" name="tenant_id" id="plan_tid">
        <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:16px;">
          <?php foreach(['Starter'=>['Free','#475569','#f1f5f9','1 Branch, 3 Staff'],'Pro'=>['₱999/mo','#1d4ed8','#eff6ff','3 Branches, Unlimited'],'Enterprise'=>['₱2,499/mo','#7c3aed','#f3e8ff','10 Branches, Unlimited']] as $pn=>[$pp,$pc,$pbg,$pf]):?>
          <label style="cursor:pointer;">
            <input type="radio" name="new_plan" value="<?=$pn?>" id="plan_<?=$pn?>" style="display:none;" onchange="updatePlanCard('<?=$pn?>')">
            <div id="plan_card_<?=$pn?>" style="border:2px solid #e2e8f0;border-radius:10px;padding:12px;text-align:center;transition:all .15s;">
              <div style="font-size:.82rem;font-weight:800;color:<?=$pc?>;margin-bottom:2px;"><?=$pn?></div>
              <div style="font-size:.75rem;font-weight:700;color:<?=$pc?>;margin-bottom:6px;"><?=$pp?></div>
              <div style="font-size:.68rem;color:#94a3b8;"><?=$pf?></div>
            </div>
          </label>
          <?php endforeach;?>
        </div>
        <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:9px;padding:11px;font-size:.78rem;color:#1d4ed8;margin-bottom:14px;line-height:1.6;">ℹ️ Changing the plan takes effect immediately. If downgrading, excess staff will be suspended automatically.</div>
        <div style="display:flex;justify-content:flex-end;gap:9px;">
          <button type="button" class="btn-sm" onclick="document.getElementById('planModal').classList.remove('open')">Cancel</button>
          <button type="submit" class="btn-sm btn-primary" style="background:#7c3aed;border-color:#7c3aed;">Save Plan Change</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ══ APPROVE MODAL ══════════════════════════════════════════ -->
<div class="modal-overlay" id="approveModal">
  <div class="modal">
    <div class="mhdr"><div><div class="mtitle">✓ Approve Tenant</div><div class="msub" id="approve_sub"></div></div><button class="mclose" onclick="document.getElementById('approveModal').classList.remove('open')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button></div>
    <div class="mbody">
      <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:9px;padding:11px 14px;font-size:.8rem;color:#15803d;margin-bottom:16px;line-height:1.7;">✅ Approving this tenant will set their status to <strong>Active</strong> and allow them to login immediately.</div>
      <form method="POST"><input type="hidden" name="action" value="approve_tenant"><input type="hidden" name="tenant_id" id="approve_tid"><input type="hidden" name="user_id" id="approve_uid">
        <div style="display:flex;justify-content:flex-end;gap:9px;"><button type="button" class="btn-sm" onclick="document.getElementById('approveModal').classList.remove('open')">Cancel</button><button type="submit" class="btn-sm btn-success">✓ Confirm Approval</button></div>
      </form>
    </div>
  </div>
</div>

<!-- ══ REJECT MODAL ═══════════════════════════════════════════ -->
<div class="modal-overlay" id="rejectModal">
  <div class="modal">
    <div class="mhdr"><div><div class="mtitle">✗ Reject Tenant</div><div class="msub" id="reject_sub"></div></div><button class="mclose" onclick="document.getElementById('rejectModal').classList.remove('open')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button></div>
    <div class="mbody">
      <form method="POST"><input type="hidden" name="action" value="reject_tenant"><input type="hidden" name="tenant_id" id="reject_tid"><input type="hidden" name="user_id" id="reject_uid">
        <div style="margin-bottom:13px;"><label class="flabel">Reason for Rejection</label><textarea name="reject_reason" class="finput" rows="3" placeholder="e.g. Incomplete information, duplicate account..." style="resize:vertical;"></textarea></div>
        <div style="background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:10px 13px;font-size:.78rem;color:#dc2626;margin-bottom:14px;">⚠️ This action cannot be undone.</div>
        <div style="display:flex;justify-content:flex-end;gap:9px;"><button type="button" class="btn-sm" onclick="document.getElementById('rejectModal').classList.remove('open')">Cancel</button><button type="submit" class="btn-sm btn-danger">✗ Confirm Rejection</button></div>
      </form>
    </div>
  </div>
</div>

<script>
['approveModal','rejectModal','addTenantModal','planModal'].forEach(id=>{
  const el = document.getElementById(id);
  if(el) el.addEventListener('click',function(e){if(e.target===this)this.classList.remove('open');});
});
function openApproveModal(tid,uid,name){document.getElementById('approve_tid').value=tid;document.getElementById('approve_uid').value=uid;document.getElementById('approve_sub').textContent='Business: '+name;document.getElementById('approveModal').classList.add('open');}
function openRejectModal(tid,uid,name){document.getElementById('reject_tid').value=tid;document.getElementById('reject_uid').value=uid;document.getElementById('reject_sub').textContent='Business: '+name;document.getElementById('rejectModal').classList.add('open');}
function openPlanModal(tid,name,currentPlan){
  document.getElementById('plan_tid').value=tid;
  document.getElementById('plan_modal_sub').textContent=name;
  const radio=document.getElementById('plan_'+currentPlan);
  if(radio){radio.checked=true;updatePlanCard(currentPlan);}
  document.getElementById('planModal').classList.add('open');
}
function updatePlanCard(selected){
  const colors={Starter:'#475569',Pro:'#1d4ed8',Enterprise:'#7c3aed'};
  const bgs={Starter:'#f1f5f9',Pro:'#eff6ff',Enterprise:'#f3e8ff'};
  ['Starter','Pro','Enterprise'].forEach(p=>{
    const card=document.getElementById('plan_card_'+p);
    if(!card)return;
    if(p===selected){card.style.borderColor=colors[p];card.style.background=bgs[p];}
    else{card.style.borderColor='#e2e8f0';card.style.background='#fff';}
  });
}
</script>
</body>
</html>