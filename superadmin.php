<?php
session_start();
require 'db.php';

if (empty($_SESSION['user'])) { header('Location: login.php'); exit; }
$u = $_SESSION['user'];
if ($u['role'] !== 'super_admin') { header('Location: login.php'); exit; }

$active_page = $_GET['page'] ?? 'dashboard';
$success_msg = $error_msg = '';

// ── POST ACTIONS ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    // Approve pending tenant signup
    if ($_POST['action'] === 'approve_tenant') {
        $tid = intval($_POST['tenant_id']);
        $uid = intval($_POST['user_id']);
        $pdo->prepare("UPDATE tenants SET status='active' WHERE id=?")->execute([$tid]);
        $pdo->prepare("UPDATE users SET status='approved', approved_by=?, approved_at=NOW() WHERE id=?")->execute([$u['id'], $uid]);
        $success_msg = 'Tenant approved successfully. They can now login.';
        $active_page = 'tenants';
    }

    // Reject pending tenant signup
    if ($_POST['action'] === 'reject_tenant') {
        $tid    = intval($_POST['tenant_id']);
        $uid    = intval($_POST['user_id']);
        $reason = trim($_POST['reject_reason'] ?? 'Application rejected.');
        $pdo->prepare("UPDATE tenants SET status='rejected' WHERE id=?")->execute([$tid]);
        $pdo->prepare("UPDATE users SET status='rejected', rejected_reason=? WHERE id=?")->execute([$reason, $uid]);
        $success_msg = 'Tenant application rejected.';
        $active_page = 'tenants';
    }

    // Deactivate tenant
    if ($_POST['action'] === 'deactivate_tenant') {
        $tid = intval($_POST['tenant_id']);
        $pdo->prepare("UPDATE tenants SET status='inactive' WHERE id=?")->execute([$tid]);
        $success_msg = 'Tenant deactivated.';
        $active_page = 'tenants';
    }

    // Activate tenant
    if ($_POST['action'] === 'activate_tenant') {
        $tid = intval($_POST['tenant_id']);
        $pdo->prepare("UPDATE tenants SET status='active' WHERE id=?")->execute([$tid]);
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
            (SELECT COUNT(*) FROM pawn_transactions pt WHERE pt.tenant_id=t.id) AS ticket_count,
            (SELECT COALESCE(SUM(pt.principal_amount),0) FROM pawn_transactions pt WHERE pt.tenant_id=t.id) AS total_loans,
            (SELECT u.fullname FROM users u WHERE u.tenant_id=t.id AND u.role='admin' AND u.status='approved' LIMIT 1) AS admin_name,
            (SELECT u.id FROM users u WHERE u.tenant_id=t.id AND u.role='admin' LIMIT 1) AS admin_uid
        FROM tenants t ORDER BY t.created_at DESC
    ")->fetchAll();
} catch (PDOException $e) {
    $tenants = [];
    $error_msg = 'Error loading tenants: ' . $e->getMessage();
}

// Counts
$total_tenants    = count($tenants);
$active_tenants   = count(array_filter($tenants, fn($t) => $t['status'] === 'active'));
$inactive_tenants = count(array_filter($tenants, fn($t) => $t['status'] === 'inactive'));
$pending_tenants  = count(array_filter($tenants, fn($t) => $t['status'] === 'pending'));

try {
    $total_users   = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role != 'super_admin'")->fetchColumn();
    $active_users  = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE status='approved' AND is_suspended=0 AND role != 'super_admin'")->fetchColumn();
    $inactive_users = $total_users - $active_users;
} catch (PDOException $e) {
    $total_users = $active_users = $inactive_users = 0;
}

try {
    $total_tickets = (int)$pdo->query("SELECT COUNT(*) FROM pawn_transactions")->fetchColumn();
    $total_loans   = (float)$pdo->query("SELECT COALESCE(SUM(principal_amount),0) FROM pawn_transactions")->fetchColumn();
} catch (PDOException $e) {
    $total_tickets = 0; $total_loans = 0;
}

// Monthly user registrations (last 6 months)
try {
    $monthly_regs = $pdo->query("
        SELECT DATE_FORMAT(created_at,'%b %Y') AS month_label,
               DATE_FORMAT(created_at,'%Y-%m') AS month_key,
               COUNT(*) AS count
        FROM users
        WHERE role != 'super_admin'
          AND created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
        GROUP BY month_key, month_label
        ORDER BY month_key ASC
    ")->fetchAll();
} catch (PDOException $e) { $monthly_regs = []; }

// Monthly tenant registrations (last 6 months)
try {
    $monthly_tenants = $pdo->query("
        SELECT DATE_FORMAT(created_at,'%b %Y') AS month_label,
               DATE_FORMAT(created_at,'%Y-%m') AS month_key,
               COUNT(*) AS count
        FROM tenants
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
        GROUP BY month_key, month_label
        ORDER BY month_key ASC
    ")->fetchAll();
} catch (PDOException $e) { $monthly_tenants = []; }

// Daily activity last 30 days (transactions)
try {
    $daily_activity = $pdo->query("
        SELECT DATE(created_at) AS day, COUNT(*) AS count
        FROM pawn_transactions
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        GROUP BY day ORDER BY day ASC
    ")->fetchAll();
} catch (PDOException $e) { $daily_activity = []; }

// Plan distribution
$plan_dist = ['Starter' => 0, 'Pro' => 0, 'Enterprise' => 0];
foreach ($tenants as $t) {
    if (isset($plan_dist[$t['plan']])) $plan_dist[$t['plan']]++;
}

// Reports filters
$report_type    = $_GET['report_type']    ?? 'tenant_activity';
$filter_date_from = $_GET['date_from']   ?? date('Y-m-01');
$filter_date_to   = $_GET['date_to']     ?? date('Y-m-d');
$filter_tenant    = intval($_GET['filter_tenant'] ?? 0);
$filter_status    = $_GET['filter_status'] ?? '';

// Report data
$report_data = [];
if ($active_page === 'reports') {
    try {
        if ($report_type === 'tenant_activity') {
            $q = "SELECT t.id, t.business_name, t.owner_name, t.plan, t.status, t.created_at,
                    COUNT(DISTINCT u.id) AS user_count,
                    COUNT(DISTINCT pt.id) AS ticket_count,
                    COALESCE(SUM(pt.principal_amount),0) AS total_loans
                  FROM tenants t
                  LEFT JOIN users u ON u.tenant_id=t.id
                  LEFT JOIN pawn_transactions pt ON pt.tenant_id=t.id
                    AND DATE(pt.created_at) BETWEEN ? AND ?
                  WHERE DATE(t.created_at) <= ?";
            $params = [$filter_date_from, $filter_date_to, $filter_date_to];
            if ($filter_status) { $q .= " AND t.status=?"; $params[] = $filter_status; }
            if ($filter_tenant) { $q .= " AND t.id=?"; $params[] = $filter_tenant; }
            $q .= " GROUP BY t.id ORDER BY ticket_count DESC";
            $s = $pdo->prepare($q); $s->execute($params);
            $report_data = $s->fetchAll();

        } elseif ($report_type === 'user_registration') {
            $q = "SELECT u.id, u.fullname, u.username, u.email, u.role, u.status, u.is_suspended,
                    u.created_at, t.business_name
                  FROM users u
                  LEFT JOIN tenants t ON u.tenant_id=t.id
                  WHERE u.role != 'super_admin'
                    AND DATE(u.created_at) BETWEEN ? AND ?";
            $params = [$filter_date_from, $filter_date_to];
            if ($filter_status) { $q .= " AND u.status=?"; $params[] = $filter_status; }
            if ($filter_tenant) { $q .= " AND u.tenant_id=?"; $params[] = $filter_tenant; }
            $q .= " ORDER BY u.created_at DESC";
            $s = $pdo->prepare($q); $s->execute($params);
            $report_data = $s->fetchAll();

        } elseif ($report_type === 'usage_statistics') {
            $q = "SELECT t.id, t.business_name, t.plan, t.status,
                    COUNT(DISTINCT u.id) AS total_users,
                    COUNT(DISTINCT CASE WHEN u.role='staff' THEN u.id END) AS staff_count,
                    COUNT(DISTINCT CASE WHEN u.role='cashier' THEN u.id END) AS cashier_count,
                    COUNT(DISTINCT pt.id) AS total_tickets,
                    COUNT(DISTINCT CASE WHEN pt.status='active' THEN pt.id END) AS active_tickets,
                    COUNT(DISTINCT CASE WHEN pt.status='redeemed' THEN pt.id END) AS redeemed_tickets,
                    COALESCE(SUM(pt.principal_amount),0) AS total_loan_amount
                  FROM tenants t
                  LEFT JOIN users u ON u.tenant_id=t.id AND u.role != 'super_admin'
                  LEFT JOIN pawn_transactions pt ON pt.tenant_id=t.id
                    AND DATE(pt.created_at) BETWEEN ? AND ?
                  WHERE 1=1";
            $params = [$filter_date_from, $filter_date_to];
            if ($filter_tenant) { $q .= " AND t.id=?"; $params[] = $filter_tenant; }
            $q .= " GROUP BY t.id ORDER BY total_tickets DESC";
            $s = $pdo->prepare($q); $s->execute($params);
            $report_data = $s->fetchAll();
        }
    } catch (PDOException $e) {
        $error_msg = 'Report error: ' . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>PawnHub — Super Admin</title>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
:root{
  --sw:252px;--navy:#0f172a;--blue-acc:#2563eb;--bg:#f1f5f9;
  --card:#fff;--border:#e2e8f0;--text:#1e293b;--text-m:#475569;
  --text-dim:#94a3b8;--success:#16a34a;--danger:#dc2626;--warning:#d97706;
}
body{font-family:'Plus Jakarta Sans',sans-serif;background:var(--bg);color:var(--text);display:flex;min-height:100vh;}

/* ── SIDEBAR ── */
.sidebar{width:var(--sw);min-height:100vh;background:var(--navy);display:flex;flex-direction:column;position:fixed;left:0;top:0;bottom:0;z-index:100;overflow-y:auto;}
.sb-brand{padding:20px 18px;border-bottom:1px solid rgba(255,255,255,.08);display:flex;align-items:center;gap:10px;}
.sb-logo{width:36px;height:36px;background:linear-gradient(135deg,#1d4ed8,#7c3aed);border-radius:9px;display:flex;align-items:center;justify-content:center;flex-shrink:0;}
.sb-logo svg{width:18px;height:18px;}
.sb-name{font-size:.93rem;font-weight:800;color:#fff;}
.sb-badge{font-size:.58rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;background:linear-gradient(135deg,#1d4ed8,#7c3aed);color:#fff;padding:2px 7px;border-radius:100px;display:inline-block;margin-top:2px;}
.sb-user{padding:12px 18px;border-bottom:1px solid rgba(255,255,255,.08);display:flex;align-items:center;gap:9px;}
.sb-avatar{width:30px;height:30px;border-radius:50%;background:linear-gradient(135deg,#1d4ed8,#7c3aed);display:flex;align-items:center;justify-content:center;font-size:.75rem;font-weight:700;color:#fff;flex-shrink:0;}
.sb-uname{font-size:.78rem;font-weight:600;color:#fff;}
.sb-urole{font-size:.62rem;color:rgba(255,255,255,.35);}
.sb-nav{flex:1;padding:10px 0;}
.sb-section{font-size:.6rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:rgba(255,255,255,.25);padding:10px 16px 4px;}
.sb-item{display:flex;align-items:center;gap:9px;padding:8px 16px;margin:1px 8px;border-radius:8px;cursor:pointer;color:rgba(255,255,255,.55);font-size:.82rem;font-weight:500;text-decoration:none;transition:all .15s;}
.sb-item:hover{background:rgba(255,255,255,.08);color:#fff;}
.sb-item.active{background:rgba(37,99,235,.25);color:#60a5fa;font-weight:600;}
.sb-item svg{width:15px;height:15px;flex-shrink:0;}
.sb-pill{margin-left:auto;background:#ef4444;color:#fff;font-size:.62rem;font-weight:700;padding:1px 6px;border-radius:100px;}
.sb-footer{padding:12px 14px;border-top:1px solid rgba(255,255,255,.08);}
.sb-logout{display:flex;align-items:center;gap:8px;font-size:.8rem;color:rgba(255,255,255,.35);text-decoration:none;padding:7px 8px;border-radius:8px;transition:all .15s;}
.sb-logout:hover{color:#f87171;background:rgba(239,68,68,.1);}
.sb-logout svg{width:14px;height:14px;}

/* ── MAIN ── */
.main{margin-left:var(--sw);flex:1;display:flex;flex-direction:column;}
.topbar{height:58px;padding:0 26px;background:#fff;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:50;}
.topbar-title{font-size:1rem;font-weight:700;}
.super-chip{font-size:.7rem;font-weight:700;background:linear-gradient(135deg,#1d4ed8,#7c3aed);color:#fff;padding:3px 10px;border-radius:100px;}
.content{padding:22px 26px;flex:1;}

/* ── STAT CARDS ── */
.stats-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:22px;}
.stat-card{background:var(--card);border:1px solid var(--border);border-radius:12px;padding:16px 18px;display:flex;align-items:flex-start;gap:12px;}
.stat-icon{width:40px;height:40px;border-radius:10px;display:flex;align-items:center;justify-content:center;flex-shrink:0;}
.stat-icon svg{width:18px;height:18px;}
.stat-label{font-size:.7rem;font-weight:600;color:var(--text-dim);text-transform:uppercase;letter-spacing:.05em;margin-bottom:3px;}
.stat-value{font-size:1.5rem;font-weight:800;color:var(--text);line-height:1;}
.stat-sub{font-size:.71rem;color:var(--text-dim);margin-top:2px;}

/* ── CARDS ── */
.card{background:var(--card);border:1px solid var(--border);border-radius:12px;padding:18px;margin-bottom:16px;}
.card-hdr{display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;flex-wrap:wrap;gap:8px;}
.card-title{font-size:.8rem;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:var(--text);}
.two-col{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px;}
.three-col{display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px;margin-bottom:16px;}

/* ── TABLE ── */
table{width:100%;border-collapse:collapse;}
th{font-size:.67rem;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:var(--text-dim);padding:7px 11px;text-align:left;border-bottom:1px solid var(--border);white-space:nowrap;}
td{padding:10px 11px;font-size:.81rem;border-bottom:1px solid #f1f5f9;vertical-align:middle;}
tr:last-child td{border-bottom:none;}
tr:hover td{background:#f8fafc;}

/* ── BADGES ── */
.badge{display:inline-flex;align-items:center;gap:3px;font-size:.67rem;font-weight:700;padding:2px 8px;border-radius:100px;}
.b-blue{background:#dbeafe;color:#1d4ed8;}
.b-green{background:#dcfce7;color:#15803d;}
.b-red{background:#fee2e2;color:#dc2626;}
.b-yellow{background:#fef3c7;color:#b45309;}
.b-purple{background:#f3e8ff;color:#7c3aed;}
.b-gray{background:#f1f5f9;color:#475569;}
.b-orange{background:#ffedd5;color:#c2410c;}
.plan-ent{background:linear-gradient(135deg,#dbeafe,#ede9fe);color:#4338ca;border:1px solid #c7d2fe;}
.plan-pro{background:#fef3c7;color:#b45309;}
.plan-starter{background:#f1f5f9;color:#475569;}
.b-dot{width:4px;height:4px;border-radius:50%;background:currentColor;}

/* ── BUTTONS ── */
.btn-sm{padding:5px 12px;border-radius:7px;font-size:.73rem;font-weight:600;cursor:pointer;border:1px solid var(--border);background:#fff;color:var(--text-m);text-decoration:none;display:inline-flex;align-items:center;gap:5px;transition:all .15s;margin-right:4px;}
.btn-sm:hover{background:var(--bg);}
.btn-primary{background:var(--blue-acc);color:#fff;border-color:var(--blue-acc);}
.btn-success{background:var(--success);color:#fff;border-color:var(--success);}
.btn-danger{background:var(--danger);color:#fff;border-color:var(--danger);}
.btn-warning{background:var(--warning);color:#fff;border-color:var(--warning);}

/* ── ALERTS ── */
.alert{padding:10px 16px;border-radius:10px;font-size:.82rem;margin-bottom:18px;display:flex;align-items:center;gap:8px;}
.alert-success{background:#f0fdf4;border:1px solid #bbf7d0;color:#15803d;}
.alert-error{background:#fef2f2;border:1px solid #fecaca;color:#dc2626;}

/* ── EMPTY ── */
.empty-state{text-align:center;padding:40px 20px;color:var(--text-dim);}
.empty-state svg{width:34px;height:34px;margin:0 auto 9px;display:block;opacity:.3;}
.empty-state p{font-size:.83rem;}

/* ── MISC ── */
.ticket-tag{font-family:monospace;font-size:.77rem;color:var(--blue-acc);font-weight:700;}
.chart-wrap{position:relative;height:220px;}
.donut-wrap{position:relative;height:200px;display:flex;align-items:center;justify-content:center;}
.legend-row{display:flex;align-items:center;gap:6px;font-size:.74rem;color:var(--text-m);margin-bottom:4px;}
.legend-dot{width:10px;height:10px;border-radius:50%;flex-shrink:0;}
.section-hdr{font-size:.72rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--text-dim);padding-bottom:8px;border-bottom:1px solid var(--border);margin-bottom:14px;}

/* ── MODAL ── */
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(15,23,42,.55);z-index:999;align-items:center;justify-content:center;backdrop-filter:blur(3px);}
.modal-overlay.open{display:flex;}
.modal{background:#fff;border-radius:16px;width:480px;max-width:95vw;max-height:92vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.2);animation:mIn .22s ease both;}
@keyframes mIn{from{opacity:0;transform:translateY(12px)}to{opacity:1;transform:none}}
.mhdr{padding:20px 22px 0;display:flex;align-items:center;justify-content:space-between;}
.mtitle{font-size:1rem;font-weight:800;}
.msub{font-size:.78rem;color:var(--text-dim);margin-top:2px;}
.mclose{width:28px;height:28px;border-radius:7px;border:1.5px solid var(--border);background:#fff;cursor:pointer;display:flex;align-items:center;justify-content:center;color:var(--text-dim);}
.mclose svg{width:13px;height:13px;}
.mbody{padding:18px 22px 22px;}
.flabel{display:block;font-size:.74rem;font-weight:600;color:var(--text-m);margin-bottom:4px;}
.finput{width:100%;border:1.5px solid var(--border);border-radius:8px;padding:9px 11px;font-family:inherit;font-size:.85rem;color:var(--text);outline:none;background:#fff;transition:border .2s;}
.finput:focus{border-color:var(--blue-acc);box-shadow:0 0 0 3px rgba(37,99,235,.1);}
.finput::placeholder{color:#c8d0db;}
select.finput{cursor:pointer;}

/* ── FILTERS ── */
.filter-bar{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:16px;background:#fff;border:1px solid var(--border);border-radius:10px;padding:12px 16px;}
.filter-bar label{font-size:.74rem;font-weight:600;color:var(--text-dim);white-space:nowrap;}
.filter-select,.filter-input{border:1.5px solid var(--border);border-radius:7px;padding:6px 10px;font-family:inherit;font-size:.81rem;color:var(--text);outline:none;background:#fff;transition:border .2s;}
.filter-select:focus,.filter-input:focus{border-color:var(--blue-acc);}

/* ── REPORT SUMMARY CARDS ── */
.summary-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:16px;}
.summary-item{background:var(--bg);border:1px solid var(--border);border-radius:10px;padding:14px 16px;text-align:center;}
.summary-num{font-size:1.4rem;font-weight:800;color:var(--text);}
.summary-lbl{font-size:.7rem;font-weight:600;color:var(--text-dim);text-transform:uppercase;letter-spacing:.04em;margin-top:2px;}

@media(max-width:1200px){.stats-grid{grid-template-columns:repeat(2,1fr)}.two-col,.three-col{grid-template-columns:1fr;}}
@media(max-width:600px){.stats-grid{grid-template-columns:1fr;}.filter-bar{flex-direction:column;align-items:flex-start;}}
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
    <div>
      <div class="sb-uname"><?= htmlspecialchars($u['name']) ?></div>
      <div class="sb-urole">Super Administrator</div>
    </div>
  </div>
  <nav class="sb-nav">
    <div class="sb-section">Overview</div>
    <a href="?page=dashboard" class="sb-item <?= $active_page === 'dashboard' ? 'active' : '' ?>">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
      Dashboard
    </a>
    <div class="sb-section">Management</div>
    <a href="?page=tenants" class="sb-item <?= $active_page === 'tenants' ? 'active' : '' ?>">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="9" width="18" height="12"/><polyline points="3 9 12 3 21 9"/></svg>
      Tenant Management
      <?php if ($pending_tenants > 0): ?><span class="sb-pill"><?= $pending_tenants ?></span><?php endif; ?>
    </a>
    <div class="sb-section">Analytics</div>
    <a href="?page=reports" class="sb-item <?= $active_page === 'reports' ? 'active' : '' ?>">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
      Reports
    </a>
  </nav>
  <div class="sb-footer">
    <a href="logout.php" class="sb-logout">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
      Sign Out
    </a>
  </div>
</aside>

<!-- ══ MAIN ══════════════════════════════════════════════════════ -->
<div class="main">
  <header class="topbar">
    <div style="display:flex;align-items:center;gap:10px;">
      <span class="topbar-title">
        <?php $titles = ['dashboard' => 'System Dashboard', 'tenants' => 'Tenant Management', 'reports' => 'Reports'];
        echo $titles[$active_page] ?? 'Dashboard'; ?>
      </span>
      <span class="super-chip">SUPER ADMIN</span>
    </div>
    <div style="font-size:.78rem;color:var(--text-dim);"><?= date('F d, Y') ?></div>
  </header>

  <div class="content">
    <?php if ($success_msg): ?>
      <div class="alert alert-success">✅ <?= htmlspecialchars($success_msg) ?></div>
    <?php endif; ?>
    <?php if ($error_msg): ?>
      <div class="alert alert-error">⚠ <?= htmlspecialchars($error_msg) ?></div>
    <?php endif; ?>

    <!-- ══════════════════════════════════════════════════════════
         PAGE: DASHBOARD
    ══════════════════════════════════════════════════════════════ -->
    <?php if ($active_page === 'dashboard'): ?>

      <!-- Stat Cards -->
      <div class="stats-grid">
        <div class="stat-card">
          <div class="stat-icon" style="background:#dbeafe;">
            <svg viewBox="0 0 24 24" fill="none" stroke="#2563eb" stroke-width="2"><rect x="3" y="9" width="18" height="12"/><polyline points="3 9 12 3 21 9"/></svg>
          </div>
          <div>
            <div class="stat-label">Total Tenants</div>
            <div class="stat-value"><?= $total_tenants ?></div>
            <div class="stat-sub"><?= $active_tenants ?> active · <?= $pending_tenants ?> pending</div>
          </div>
        </div>
        <div class="stat-card">
          <div class="stat-icon" style="background:#dcfce7;">
            <svg viewBox="0 0 24 24" fill="none" stroke="#16a34a" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
          </div>
          <div>
            <div class="stat-label">Total Users</div>
            <div class="stat-value"><?= $total_users ?></div>
            <div class="stat-sub"><?= $active_users ?> active · <?= $inactive_users ?> inactive</div>
          </div>
        </div>
        <div class="stat-card">
          <div class="stat-icon" style="background:#fef3c7;">
            <svg viewBox="0 0 24 24" fill="none" stroke="#d97706" stroke-width="2"><path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/></svg>
          </div>
          <div>
            <div class="stat-label">Total Tickets</div>
            <div class="stat-value"><?= number_format($total_tickets) ?></div>
            <div class="stat-sub">Across all tenants</div>
          </div>
        </div>
        <div class="stat-card">
          <div class="stat-icon" style="background:#f3e8ff;">
            <svg viewBox="0 0 24 24" fill="none" stroke="#7c3aed" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
          </div>
          <div>
            <div class="stat-label">Total Loans</div>
            <div class="stat-value" style="font-size:1.15rem;">₱<?= number_format($total_loans, 0) ?></div>
            <div class="stat-sub">Principal amount</div>
          </div>
        </div>
      </div>

      <!-- Charts Row -->
      <div class="two-col">
        <!-- User Growth Chart -->
        <div class="card">
          <div class="card-hdr">
            <span class="card-title">👥 User Growth (6 Months)</span>
          </div>
          <div class="chart-wrap">
            <canvas id="userGrowthChart"></canvas>
          </div>
        </div>
        <!-- Tenant Activity Chart -->
        <div class="card">
          <div class="card-hdr">
            <span class="card-title">🏢 New Tenants (6 Months)</span>
          </div>
          <div class="chart-wrap">
            <canvas id="tenantActivityChart"></canvas>
          </div>
        </div>
      </div>

      <!-- Sales Trend + Plan Distribution -->
      <div class="two-col">
        <!-- Daily Activity -->
        <div class="card">
          <div class="card-hdr">
            <span class="card-title">📈 Daily Ticket Activity (30 Days)</span>
          </div>
          <div class="chart-wrap">
            <canvas id="dailyActivityChart"></canvas>
          </div>
        </div>
        <!-- Plan Distribution Donut -->
        <div class="card">
          <div class="card-hdr">
            <span class="card-title">⭐ Plan Distribution</span>
          </div>
          <div style="display:flex;align-items:center;gap:24px;">
            <div class="donut-wrap" style="flex:1;">
              <canvas id="planDonutChart"></canvas>
            </div>
            <div style="flex-shrink:0;">
              <div class="legend-row"><span class="legend-dot" style="background:#475569;"></span>Starter — <?= $plan_dist['Starter'] ?></div>
              <div class="legend-row"><span class="legend-dot" style="background:#b45309;"></span>Pro — <?= $plan_dist['Pro'] ?></div>
              <div class="legend-row"><span class="legend-dot" style="background:#4338ca;"></span>Enterprise — <?= $plan_dist['Enterprise'] ?></div>
              <div style="margin-top:10px;font-size:.72rem;color:var(--text-dim);border-top:1px solid var(--border);padding-top:8px;">
                <div>Total: <?= $total_tenants ?> tenants</div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Tenant Status Overview + Recent Tenants -->
      <div class="two-col">
        <div class="card">
          <div class="card-hdr"><span class="card-title">📊 Tenant Status Overview</span></div>
          <div style="display:flex;flex-direction:column;gap:10px;">
            <?php foreach ([
              ['Active',   $active_tenants,   $total_tenants, '#16a34a', '#dcfce7'],
              ['Pending',  $pending_tenants,  $total_tenants, '#d97706', '#fef3c7'],
              ['Inactive', $inactive_tenants, $total_tenants, '#dc2626', '#fee2e2'],
            ] as [$lbl, $val, $tot, $color, $bg]): ?>
            <div>
              <div style="display:flex;justify-content:space-between;font-size:.78rem;font-weight:600;margin-bottom:4px;">
                <span><?= $lbl ?></span>
                <span style="color:<?= $color ?>;"><?= $val ?> / <?= $tot ?></span>
              </div>
              <div style="height:7px;background:#f1f5f9;border-radius:100px;overflow:hidden;">
                <?php $pct = $tot > 0 ? round($val / $tot * 100) : 0; ?>
                <div style="height:100%;width:<?= $pct ?>%;background:<?= $color ?>;border-radius:100px;transition:width .5s;"></div>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
        </div>

        <div class="card">
          <div class="card-hdr">
            <span class="card-title">🕐 Recent Tenants</span>
            <a href="?page=tenants" style="font-size:.74rem;color:var(--blue-acc);font-weight:600;text-decoration:none;">View All →</a>
          </div>
          <?php if (empty($tenants)): ?>
            <div class="empty-state"><p>No tenants yet.</p></div>
          <?php else: ?>
            <div style="overflow-x:auto;">
              <table>
                <thead><tr><th>Business</th><th>Plan</th><th>Status</th><th>Users</th><th>Date</th></tr></thead>
                <tbody>
                  <?php foreach (array_slice($tenants, 0, 6) as $t): ?>
                  <tr>
                    <td style="font-weight:600;"><?= htmlspecialchars($t['business_name']) ?></td>
                    <td><span class="badge <?= $t['plan'] === 'Enterprise' ? 'plan-ent' : ($t['plan'] === 'Pro' ? 'plan-pro' : 'plan-starter') ?>"><?= $t['plan'] ?></span></td>
                    <td><span class="badge <?= $t['status'] === 'active' ? 'b-green' : ($t['status'] === 'pending' ? 'b-yellow' : 'b-red') ?>"><span class="b-dot"></span><?= ucfirst($t['status']) ?></span></td>
                    <td><?= $t['user_count'] ?></td>
                    <td style="font-size:.73rem;color:var(--text-dim);"><?= date('M d, Y', strtotime($t['created_at'])) ?></td>
                  </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- Charts JS -->
      <script>
      const chartDefaults = {
        plugins: { legend: { display: false } },
        scales: {
          x: { grid: { display: false }, ticks: { font: { size: 10 }, color: '#94a3b8' } },
          y: { grid: { color: '#f1f5f9' }, ticks: { font: { size: 10 }, color: '#94a3b8' }, beginAtZero: true }
        }
      };

      // User Growth
      new Chart(document.getElementById('userGrowthChart'), {
        type: 'bar',
        data: {
          labels: <?= json_encode(array_column($monthly_regs, 'month_label')) ?>,
          datasets: [{
            label: 'Users',
            data: <?= json_encode(array_column($monthly_regs, 'count')) ?>,
            backgroundColor: 'rgba(37,99,235,0.15)',
            borderColor: '#2563eb',
            borderWidth: 2,
            borderRadius: 6,
          }]
        },
        options: { ...chartDefaults, responsive: true, maintainAspectRatio: false }
      });

      // Tenant Activity
      new Chart(document.getElementById('tenantActivityChart'), {
        type: 'bar',
        data: {
          labels: <?= json_encode(array_column($monthly_tenants, 'month_label')) ?>,
          datasets: [{
            label: 'Tenants',
            data: <?= json_encode(array_column($monthly_tenants, 'count')) ?>,
            backgroundColor: 'rgba(16,185,129,0.15)',
            borderColor: '#10b981',
            borderWidth: 2,
            borderRadius: 6,
          }]
        },
        options: { ...chartDefaults, responsive: true, maintainAspectRatio: false }
      });

      // Daily Activity
      new Chart(document.getElementById('dailyActivityChart'), {
        type: 'line',
        data: {
          labels: <?= json_encode(array_column($daily_activity, 'day')) ?>,
          datasets: [{
            label: 'Tickets',
            data: <?= json_encode(array_column($daily_activity, 'count')) ?>,
            borderColor: '#7c3aed',
            backgroundColor: 'rgba(124,58,237,0.08)',
            borderWidth: 2,
            tension: 0.4,
            fill: true,
            pointRadius: 2,
          }]
        },
        options: { ...chartDefaults, responsive: true, maintainAspectRatio: false }
      });

      // Plan Donut
      new Chart(document.getElementById('planDonutChart'), {
        type: 'doughnut',
        data: {
          labels: ['Starter', 'Pro', 'Enterprise'],
          datasets: [{
            data: [<?= $plan_dist['Starter'] ?>, <?= $plan_dist['Pro'] ?>, <?= $plan_dist['Enterprise'] ?>],
            backgroundColor: ['#94a3b8','#d97706','#4338ca'],
            borderWidth: 0,
            hoverOffset: 4,
          }]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          cutout: '68%',
          plugins: { legend: { display: false } }
        }
      });
      </script>

    <!-- ══════════════════════════════════════════════════════════
         PAGE: TENANT MANAGEMENT
    ══════════════════════════════════════════════════════════════ -->
    <?php elseif ($active_page === 'tenants'): ?>

      <!-- Tenant Stats -->
      <div class="stats-grid">
        <div class="stat-card">
          <div class="stat-icon" style="background:#dbeafe;"><svg viewBox="0 0 24 24" fill="none" stroke="#2563eb" stroke-width="2"><rect x="3" y="9" width="18" height="12"/><polyline points="3 9 12 3 21 9"/></svg></div>
          <div><div class="stat-label">Total Tenants</div><div class="stat-value"><?= $total_tenants ?></div></div>
        </div>
        <div class="stat-card">
          <div class="stat-icon" style="background:#dcfce7;"><svg viewBox="0 0 24 24" fill="none" stroke="#16a34a" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="9 12 11 14 15 10"/></svg></div>
          <div><div class="stat-label">Active</div><div class="stat-value" style="color:var(--success);"><?= $active_tenants ?></div></div>
        </div>
        <div class="stat-card">
          <div class="stat-icon" style="background:#fef3c7;"><svg viewBox="0 0 24 24" fill="none" stroke="#d97706" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg></div>
          <div><div class="stat-label">Pending</div><div class="stat-value" style="color:var(--warning);"><?= $pending_tenants ?></div></div>
        </div>
        <div class="stat-card">
          <div class="stat-icon" style="background:#fee2e2;"><svg viewBox="0 0 24 24" fill="none" stroke="#dc2626" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/></svg></div>
          <div><div class="stat-label">Inactive</div><div class="stat-value" style="color:var(--danger);"><?= $inactive_tenants ?></div></div>
        </div>
      </div>

      <!-- Pending Signups Block -->
      <?php
      $pending_signup_tenants = array_filter($tenants, fn($t) => $t['status'] === 'pending');
      if (!empty($pending_signup_tenants)):
      ?>
      <div class="card" style="border-color:#fde68a;">
        <div class="card-hdr">
          <span class="card-title" style="color:#b45309;">⏳ Pending Approval (<?= count($pending_signup_tenants) ?>)</span>
          <span style="font-size:.75rem;color:var(--text-dim);">These tenants registered via signup and are awaiting review.</span>
        </div>
        <div style="overflow-x:auto;">
          <table>
            <thead>
              <tr><th>Business Name</th><th>Owner</th><th>Email</th><th>Plan</th><th>Applied</th><th>Actions</th></tr>
            </thead>
            <tbody>
              <?php foreach ($pending_signup_tenants as $t): ?>
              <tr>
                <td style="font-weight:600;"><?= htmlspecialchars($t['business_name']) ?></td>
                <td><?= htmlspecialchars($t['owner_name']) ?></td>
                <td style="font-size:.76rem;color:var(--text-dim);"><?= htmlspecialchars($t['email']) ?></td>
                <td><span class="badge <?= $t['plan'] === 'Enterprise' ? 'plan-ent' : ($t['plan'] === 'Pro' ? 'plan-pro' : 'plan-starter') ?>"><?= $t['plan'] ?></span></td>
                <td style="font-size:.73rem;color:var(--text-dim);"><?= date('M d, Y', strtotime($t['created_at'])) ?></td>
                <td>
                  <button onclick="openApproveModal(<?= $t['id'] ?>, <?= (int)$t['admin_uid'] ?>, '<?= htmlspecialchars($t['business_name'], ENT_QUOTES) ?>')"
                          class="btn-sm btn-success" style="font-size:.7rem;">✓ Approve</button>
                  <button onclick="openRejectModal(<?= $t['id'] ?>, <?= (int)$t['admin_uid'] ?>, '<?= htmlspecialchars($t['business_name'], ENT_QUOTES) ?>')"
                          class="btn-sm btn-danger" style="font-size:.7rem;">✗ Reject</button>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
      <?php endif; ?>

      <!-- All Tenants Table -->
      <div class="card" style="overflow-x:auto;">
        <div class="card-hdr">
          <span class="card-title">🏢 All Tenants</span>
          <span style="font-size:.75rem;color:var(--text-dim);"><?= $total_tenants ?> total</span>
        </div>
        <?php if (empty($tenants)): ?>
          <div class="empty-state">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="9" width="18" height="12"/><polyline points="3 9 12 3 21 9"/></svg>
            <p>No tenants registered yet.</p>
          </div>
        <?php else: ?>
          <table>
            <thead>
              <tr>
                <th>ID</th><th>Business Name</th><th>Owner</th><th>Email</th>
                <th>Plan</th><th>Status</th><th>Users</th><th>Tickets</th>
                <th>Total Loans</th><th>Registered</th><th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($tenants as $t): ?>
              <tr>
                <td style="color:var(--text-dim);font-size:.74rem;">#<?= $t['id'] ?></td>
                <td style="font-weight:600;"><?= htmlspecialchars($t['business_name']) ?></td>
                <td style="font-size:.79rem;"><?= htmlspecialchars($t['owner_name']) ?></td>
                <td style="font-size:.74rem;color:var(--text-dim);"><?= htmlspecialchars($t['email']) ?></td>
                <td><span class="badge <?= $t['plan'] === 'Enterprise' ? 'plan-ent' : ($t['plan'] === 'Pro' ? 'plan-pro' : 'plan-starter') ?>"><?= $t['plan'] ?></span></td>
                <td>
                  <span class="badge <?= $t['status'] === 'active' ? 'b-green' : ($t['status'] === 'pending' ? 'b-yellow' : ($t['status'] === 'inactive' ? 'b-red' : 'b-gray')) ?>">
                    <span class="b-dot"></span><?= ucfirst($t['status']) ?>
                  </span>
                </td>
                <td><?= $t['user_count'] ?></td>
                <td><?= number_format($t['ticket_count']) ?></td>
                <td style="font-size:.78rem;font-weight:600;color:var(--success);">₱<?= number_format($t['total_loans'], 0) ?></td>
                <td style="font-size:.73rem;color:var(--text-dim);"><?= date('M d, Y', strtotime($t['created_at'])) ?></td>
                <td>
                  <?php if ($t['status'] === 'active'): ?>
                    <form method="POST" style="display:inline;" onsubmit="return confirm('Deactivate this tenant?')">
                      <input type="hidden" name="action" value="deactivate_tenant">
                      <input type="hidden" name="tenant_id" value="<?= $t['id'] ?>">
                      <button type="submit" class="btn-sm btn-danger" style="font-size:.7rem;">Deactivate</button>
                    </form>
                  <?php elseif ($t['status'] === 'inactive'): ?>
                    <form method="POST" style="display:inline;">
                      <input type="hidden" name="action" value="activate_tenant">
                      <input type="hidden" name="tenant_id" value="<?= $t['id'] ?>">
                      <button type="submit" class="btn-sm btn-success" style="font-size:.7rem;">Activate</button>
                    </form>
                  <?php elseif ($t['status'] === 'pending'): ?>
                    <button onclick="openApproveModal(<?= $t['id'] ?>, <?= (int)$t['admin_uid'] ?>, '<?= htmlspecialchars($t['business_name'], ENT_QUOTES) ?>')"
                            class="btn-sm btn-success" style="font-size:.7rem;">✓ Approve</button>
                    <button onclick="openRejectModal(<?= $t['id'] ?>, <?= (int)$t['admin_uid'] ?>, '<?= htmlspecialchars($t['business_name'], ENT_QUOTES) ?>')"
                            class="btn-sm btn-danger" style="font-size:.7rem;">✗ Reject</button>
                  <?php else: ?>
                    <span style="font-size:.73rem;color:var(--text-dim);">—</span>
                  <?php endif; ?>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>

    <!-- ══════════════════════════════════════════════════════════
         PAGE: REPORTS
    ══════════════════════════════════════════════════════════════ -->
    <?php elseif ($active_page === 'reports'): ?>

      <!-- Report Type Tabs -->
      <div style="display:flex;gap:8px;margin-bottom:16px;flex-wrap:wrap;">
        <?php
        $report_types = [
          'tenant_activity'  => ['🏢 Tenant Activity Report', '#2563eb', '#eff6ff'],
          'user_registration'=> ['👤 User Registration Report', '#16a34a', '#f0fdf4'],
          'usage_statistics' => ['📊 Usage Statistics', '#7c3aed', '#f3e8ff'],
        ];
        foreach ($report_types as $rkey => [$rlbl, $rc, $rbg]): ?>
        <a href="?page=reports&report_type=<?= $rkey ?>&date_from=<?= $filter_date_from ?>&date_to=<?= $filter_date_to ?>"
           style="padding:8px 16px;border-radius:9px;font-size:.8rem;font-weight:700;text-decoration:none;border:2px solid <?= $report_type === $rkey ? $rc : 'var(--border)' ?>;background:<?= $report_type === $rkey ? $rbg : '#fff' ?>;color:<?= $report_type === $rkey ? $rc : 'var(--text-m)' ?>;">
          <?= $rlbl ?>
        </a>
        <?php endforeach; ?>
      </div>

      <!-- Filters -->
      <form method="GET" action="">
        <input type="hidden" name="page" value="reports">
        <input type="hidden" name="report_type" value="<?= htmlspecialchars($report_type) ?>">
        <div class="filter-bar">
          <label>Date From:</label>
          <input type="date" name="date_from" class="filter-input" value="<?= htmlspecialchars($filter_date_from) ?>">
          <label>Date To:</label>
          <input type="date" name="date_to" class="filter-input" value="<?= htmlspecialchars($filter_date_to) ?>">
          <?php if ($report_type === 'tenant_activity' || $report_type === 'user_registration'): ?>
          <label>Status:</label>
          <select name="filter_status" class="filter-select">
            <option value="">All Status</option>
            <?php if ($report_type === 'user_registration'): ?>
            <option value="approved" <?= $filter_status === 'approved' ? 'selected' : '' ?>>Approved</option>
            <option value="pending"  <?= $filter_status === 'pending'  ? 'selected' : '' ?>>Pending</option>
            <option value="rejected" <?= $filter_status === 'rejected' ? 'selected' : '' ?>>Rejected</option>
            <?php else: ?>
            <option value="active"   <?= $filter_status === 'active'   ? 'selected' : '' ?>>Active</option>
            <option value="inactive" <?= $filter_status === 'inactive' ? 'selected' : '' ?>>Inactive</option>
            <option value="pending"  <?= $filter_status === 'pending'  ? 'selected' : '' ?>>Pending</option>
            <?php endif; ?>
          </select>
          <?php endif; ?>
          <label>Tenant:</label>
          <select name="filter_tenant" class="filter-select">
            <option value="0">All Tenants</option>
            <?php foreach ($tenants as $t): ?>
            <option value="<?= $t['id'] ?>" <?= $filter_tenant === (int)$t['id'] ? 'selected' : '' ?>><?= htmlspecialchars($t['business_name']) ?></option>
            <?php endforeach; ?>
          </select>
          <button type="submit" class="btn-sm btn-primary">Apply Filter</button>
          <a href="?page=reports&report_type=<?= $report_type ?>" class="btn-sm">Reset</a>
        </div>
      </form>

      <!-- Report: Tenant Activity -->
      <?php if ($report_type === 'tenant_activity'): ?>
        <?php
        $rpt_total   = count($report_data);
        $rpt_tickets = array_sum(array_column($report_data, 'ticket_count'));
        $rpt_loans   = array_sum(array_column($report_data, 'total_loans'));
        $rpt_users   = array_sum(array_column($report_data, 'user_count'));
        ?>
        <div class="summary-grid">
          <div class="summary-item"><div class="summary-num"><?= $rpt_total ?></div><div class="summary-lbl">Tenants</div></div>
          <div class="summary-item"><div class="summary-num"><?= $rpt_users ?></div><div class="summary-lbl">Total Users</div></div>
          <div class="summary-item"><div class="summary-num"><?= number_format($rpt_tickets) ?></div><div class="summary-lbl">Tickets Issued</div></div>
        </div>
        <div class="card" style="overflow-x:auto;">
          <div class="card-hdr">
            <span class="card-title">🏢 Tenant Activity Report</span>
            <span style="font-size:.74rem;color:var(--text-dim);"><?= htmlspecialchars($filter_date_from) ?> — <?= htmlspecialchars($filter_date_to) ?></span>
          </div>
          <?php if (empty($report_data)): ?>
            <div class="empty-state"><p>No data found for the selected filters.</p></div>
          <?php else: ?>
            <table>
              <thead><tr><th>#</th><th>Business Name</th><th>Owner</th><th>Plan</th><th>Status</th><th>Users</th><th>Tickets</th><th>Total Loans (₱)</th><th>Registered</th></tr></thead>
              <tbody>
                <?php foreach ($report_data as $i => $r): ?>
                <tr>
                  <td style="color:var(--text-dim);font-size:.73rem;"><?= $i+1 ?></td>
                  <td style="font-weight:600;"><?= htmlspecialchars($r['business_name']) ?></td>
                  <td><?= htmlspecialchars($r['owner_name']) ?></td>
                  <td><span class="badge <?= $r['plan'] === 'Enterprise' ? 'plan-ent' : ($r['plan'] === 'Pro' ? 'plan-pro' : 'plan-starter') ?>"><?= $r['plan'] ?></span></td>
                  <td><span class="badge <?= $r['status'] === 'active' ? 'b-green' : ($r['status'] === 'pending' ? 'b-yellow' : 'b-red') ?>"><span class="b-dot"></span><?= ucfirst($r['status']) ?></span></td>
                  <td><?= $r['user_count'] ?></td>
                  <td style="font-weight:700;"><?= number_format($r['ticket_count']) ?></td>
                  <td style="font-weight:700;color:var(--success);">₱<?= number_format($r['total_loans'], 2) ?></td>
                  <td style="font-size:.73rem;color:var(--text-dim);"><?= date('M d, Y', strtotime($r['created_at'])) ?></td>
                </tr>
                <?php endforeach; ?>
              </tbody>
              <tfoot>
                <tr style="background:#f8fafc;">
                  <td colspan="6" style="font-weight:700;font-size:.78rem;color:var(--text-m);">TOTALS</td>
                  <td style="font-weight:800;"><?= number_format($rpt_tickets) ?></td>
                  <td style="font-weight:800;color:var(--success);">₱<?= number_format($rpt_loans, 2) ?></td>
                  <td></td>
                </tr>
              </tfoot>
            </table>
          <?php endif; ?>
        </div>

      <!-- Report: User Registration -->
      <?php elseif ($report_type === 'user_registration'): ?>
        <?php
        $rpt_total    = count($report_data);
        $rpt_approved = count(array_filter($report_data, fn($r) => $r['status'] === 'approved'));
        $rpt_pending  = count(array_filter($report_data, fn($r) => $r['status'] === 'pending'));
        ?>
        <div class="summary-grid">
          <div class="summary-item"><div class="summary-num"><?= $rpt_total ?></div><div class="summary-lbl">Total Registrations</div></div>
          <div class="summary-item"><div class="summary-num" style="color:var(--success);"><?= $rpt_approved ?></div><div class="summary-lbl">Approved</div></div>
          <div class="summary-item"><div class="summary-num" style="color:var(--warning);"><?= $rpt_pending ?></div><div class="summary-lbl">Pending</div></div>
        </div>
        <div class="card" style="overflow-x:auto;">
          <div class="card-hdr">
            <span class="card-title">👤 User Registration Report</span>
            <span style="font-size:.74rem;color:var(--text-dim);"><?= htmlspecialchars($filter_date_from) ?> — <?= htmlspecialchars($filter_date_to) ?></span>
          </div>
          <?php if (empty($report_data)): ?>
            <div class="empty-state"><p>No registrations found for the selected filters.</p></div>
          <?php else: ?>
            <table>
              <thead><tr><th>#</th><th>Full Name</th><th>Username</th><th>Email</th><th>Role</th><th>Tenant</th><th>Status</th><th>Suspended</th><th>Registered</th></tr></thead>
              <tbody>
                <?php foreach ($report_data as $i => $r): ?>
                <tr>
                  <td style="color:var(--text-dim);font-size:.73rem;"><?= $i+1 ?></td>
                  <td style="font-weight:600;"><?= htmlspecialchars($r['fullname']) ?></td>
                  <td style="font-family:monospace;font-size:.77rem;color:var(--blue-acc);"><?= htmlspecialchars($r['username']) ?></td>
                  <td style="font-size:.74rem;color:var(--text-dim);"><?= htmlspecialchars($r['email']) ?></td>
                  <td>
                    <span class="badge <?= ['admin'=>'b-blue','staff'=>'b-green','cashier'=>'b-yellow'][$r['role']] ?? 'b-gray' ?>">
                      <?= ucfirst($r['role']) ?>
                    </span>
                  </td>
                  <td style="font-size:.78rem;"><?= htmlspecialchars($r['business_name'] ?? '—') ?></td>
                  <td><span class="badge <?= $r['status'] === 'approved' ? 'b-green' : ($r['status'] === 'pending' ? 'b-yellow' : 'b-red') ?>"><?= ucfirst($r['status']) ?></span></td>
                  <td><?= $r['is_suspended'] ? '<span class="badge b-red">Yes</span>' : '<span class="badge b-green">No</span>' ?></td>
                  <td style="font-size:.73rem;color:var(--text-dim);"><?= date('M d, Y', strtotime($r['created_at'])) ?></td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          <?php endif; ?>
        </div>

      <!-- Report: Usage Statistics -->
      <?php elseif ($report_type === 'usage_statistics'): ?>
        <?php
        $rpt_total_tickets   = array_sum(array_column($report_data, 'total_tickets'));
        $rpt_active_tickets  = array_sum(array_column($report_data, 'active_tickets'));
        $rpt_loan_amount     = array_sum(array_column($report_data, 'total_loan_amount'));
        ?>
        <div class="summary-grid">
          <div class="summary-item"><div class="summary-num"><?= number_format($rpt_total_tickets) ?></div><div class="summary-lbl">Total Tickets</div></div>
          <div class="summary-item"><div class="summary-num" style="color:var(--success);"><?= number_format($rpt_active_tickets) ?></div><div class="summary-lbl">Active Tickets</div></div>
          <div class="summary-item"><div class="summary-num" style="color:#7c3aed;">₱<?= number_format($rpt_loan_amount, 0) ?></div><div class="summary-lbl">Total Loan Amount</div></div>
        </div>
        <div class="card" style="overflow-x:auto;">
          <div class="card-hdr">
            <span class="card-title">📊 Usage Statistics</span>
            <span style="font-size:.74rem;color:var(--text-dim);"><?= htmlspecialchars($filter_date_from) ?> — <?= htmlspecialchars($filter_date_to) ?></span>
          </div>
          <?php if (empty($report_data)): ?>
            <div class="empty-state"><p>No data found for the selected filters.</p></div>
          <?php else: ?>
            <table>
              <thead><tr><th>#</th><th>Tenant</th><th>Plan</th><th>Status</th><th>Total Users</th><th>Staff</th><th>Cashiers</th><th>Total Tickets</th><th>Active</th><th>Redeemed</th><th>Loan Amount (₱)</th></tr></thead>
              <tbody>
                <?php foreach ($report_data as $i => $r): ?>
                <tr>
                  <td style="color:var(--text-dim);font-size:.73rem;"><?= $i+1 ?></td>
                  <td style="font-weight:600;"><?= htmlspecialchars($r['business_name']) ?></td>
                  <td><span class="badge <?= $r['plan'] === 'Enterprise' ? 'plan-ent' : ($r['plan'] === 'Pro' ? 'plan-pro' : 'plan-starter') ?>"><?= $r['plan'] ?></span></td>
                  <td><span class="badge <?= $r['status'] === 'active' ? 'b-green' : ($r['status'] === 'pending' ? 'b-yellow' : 'b-red') ?>"><span class="b-dot"></span><?= ucfirst($r['status']) ?></span></td>
                  <td><?= $r['total_users'] ?></td>
                  <td><?= $r['staff_count'] ?></td>
                  <td><?= $r['cashier_count'] ?></td>
                  <td style="font-weight:700;"><?= number_format($r['total_tickets']) ?></td>
                  <td><span class="badge b-green"><?= $r['active_tickets'] ?></span></td>
                  <td><span class="badge b-blue"><?= $r['redeemed_tickets'] ?></span></td>
                  <td style="font-weight:700;color:var(--success);">₱<?= number_format($r['total_loan_amount'], 2) ?></td>
                </tr>
                <?php endforeach; ?>
              </tbody>
              <tfoot>
                <tr style="background:#f8fafc;">
                  <td colspan="7" style="font-weight:700;font-size:.78rem;color:var(--text-m);">TOTALS</td>
                  <td style="font-weight:800;"><?= number_format($rpt_total_tickets) ?></td>
                  <td style="font-weight:800;color:var(--success);"><?= number_format($rpt_active_tickets) ?></td>
                  <td></td>
                  <td style="font-weight:800;color:var(--success);">₱<?= number_format($rpt_loan_amount, 2) ?></td>
                </tr>
              </tfoot>
            </table>
          <?php endif; ?>
        </div>
      <?php endif; ?>

    <?php endif; ?>
  </div><!-- /content -->
</div><!-- /main -->

<!-- ══ APPROVE MODAL ══════════════════════════════════════════ -->
<div class="modal-overlay" id="approveModal">
  <div class="modal">
    <div class="mhdr">
      <div><div class="mtitle">✓ Approve Tenant</div><div class="msub" id="approve_sub"></div></div>
      <button class="mclose" onclick="document.getElementById('approveModal').classList.remove('open')">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
      </button>
    </div>
    <div class="mbody">
      <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:9px;padding:11px 14px;font-size:.8rem;color:#15803d;margin-bottom:16px;line-height:1.7;">
        ✅ Approving this tenant will set their status to <strong>Active</strong> and allow them to login immediately.
      </div>
      <form method="POST">
        <input type="hidden" name="action" value="approve_tenant">
        <input type="hidden" name="tenant_id" id="approve_tid">
        <input type="hidden" name="user_id" id="approve_uid">
        <div style="display:flex;justify-content:flex-end;gap:9px;">
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
      <button class="mclose" onclick="document.getElementById('rejectModal').classList.remove('open')">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
      </button>
    </div>
    <div class="mbody">
      <form method="POST">
        <input type="hidden" name="action" value="reject_tenant">
        <input type="hidden" name="tenant_id" id="reject_tid">
        <input type="hidden" name="user_id" id="reject_uid">
        <div style="margin-bottom:13px;">
          <label class="flabel">Reason for Rejection</label>
          <textarea name="reject_reason" class="finput" rows="3" placeholder="e.g. Incomplete information, duplicate account..." style="resize:vertical;"></textarea>
        </div>
        <div style="background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:10px 13px;font-size:.78rem;color:#dc2626;margin-bottom:14px;">
          ⚠️ This action cannot be undone. The applicant will be notified.
        </div>
        <div style="display:flex;justify-content:flex-end;gap:9px;">
          <button type="button" class="btn-sm" onclick="document.getElementById('rejectModal').classList.remove('open')">Cancel</button>
          <button type="submit" class="btn-sm btn-danger">✗ Confirm Rejection</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
// Close modals on backdrop click
['approveModal','rejectModal'].forEach(id => {
  document.getElementById(id).addEventListener('click', function(e) {
    if (e.target === this) this.classList.remove('open');
  });
});

function openApproveModal(tid, uid, name) {
  document.getElementById('approve_tid').value = tid;
  document.getElementById('approve_uid').value = uid;
  document.getElementById('approve_sub').textContent = 'Business: ' + name;
  document.getElementById('approveModal').classList.add('open');
}

function openRejectModal(tid, uid, name) {
  document.getElementById('reject_tid').value = tid;
  document.getElementById('reject_uid').value = uid;
  document.getElementById('reject_sub').textContent = 'Business: ' + name;
  document.getElementById('rejectModal').classList.add('open');
}
</script>
</body>
</html>