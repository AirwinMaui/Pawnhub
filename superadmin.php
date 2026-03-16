<?php
session_start();
require 'db.php';
require 'mailer.php';

if (empty($_SESSION['user'])) { header('Location: login.php'); exit; }
$u = $_SESSION['user'];
if ($u['role'] !== 'super_admin') { header('Location: login.php'); exit; }

$active_page = $_GET['page'] ?? 'dashboard';
$success_msg = $error_msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    if ($_POST['action'] === 'add_tenant') {
        $bname    = trim($_POST['business_name'] ?? '');
        $oname    = trim($_POST['owner_name']    ?? '');
        $email    = trim($_POST['email']         ?? '');
        $phone    = trim($_POST['phone']         ?? '');
        $address  = trim($_POST['address']       ?? '');
        $plan     = $_POST['plan']               ?? 'Starter';
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

                    $sent = sendTenantInvitation($email, $oname, $bname, $token);
                    if ($sent) {
                        $success_msg = "✅ Tenant \"$bname\" created! Invitation sent to $email.";
                    } else {
                        $success_msg = "⚠️ Tenant created but email failed. Token: $token — Check mailer.php settings.";
                    }
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

    if ($_POST['action'] === 'resend_invite') {
        $inv_id = intval($_POST['inv_id']);
        $inv    = $pdo->prepare("SELECT i.*,t.business_name FROM tenant_invitations i JOIN tenants t ON i.tenant_id=t.id WHERE i.id=?");
        $inv->execute([$inv_id]); $inv=$inv->fetch();
        if ($inv && $inv['status']==='pending') {
            $token      = bin2hex(random_bytes(32));
            $expires_at = date('Y-m-d H:i:s', strtotime('+24 hours'));
            $pdo->prepare("UPDATE tenant_invitations SET token=?,expires_at=? WHERE id=?")->execute([$token,$expires_at,$inv_id]);
            $sent = sendTenantInvitation($inv['email'],$inv['owner_name'],$inv['business_name'],$token);
            $success_msg = $sent ? "📧 Invitation resent to {$inv['email']}!" : "⚠️ Failed to send. Check mailer.php settings.";
        }
        $active_page = 'invitations';
    }

    if ($_POST['action'] === 'toggle_tenant') {
        $tid  = intval($_POST['tenant_id']);
        $stat = $_POST['current_status']==='active' ? 'inactive' : 'active';
        $pdo->prepare("UPDATE tenants SET status=? WHERE id=?")->execute([$stat,$tid]);
        $success_msg = 'Tenant status updated.';
    }

    if ($_POST['action'] === 'approve_user') {
        $uid         = intval($_POST['user_id']);
        $assign_tid  = intval($_POST['assign_tenant_id'] ?? 0);
        $assign_role = in_array($_POST['assign_role']??'',['admin','staff','cashier']) ? $_POST['assign_role'] : 'admin';

        $ap = $pdo->prepare("SELECT * FROM users WHERE id=?");
        $ap->execute([$uid]);
        $ap = $ap->fetch();

        if ($assign_tid === 0) {
            if ($ap && $ap['tenant_id']) {
                $assign_tid  = $ap['tenant_id'];
                $assign_role = 'admin';
                $pdo->prepare("UPDATE tenants SET status='active' WHERE id=?")->execute([$assign_tid]);
            } else {
                $pdo->prepare("INSERT INTO tenants (business_name,owner_name,email,status) VALUES (?,?,?,'active')")
                    ->execute([$ap['fullname']."'s Branch", $ap['fullname'], $ap['email']]);
                $assign_tid = $pdo->lastInsertId();
            }
        } else {
            $pdo->prepare("UPDATE tenants SET status='active' WHERE id=? AND status='pending'")->execute([$assign_tid]);
        }

        $pdo->prepare("UPDATE users SET status='approved',role=?,tenant_id=?,approved_by=?,approved_at=NOW() WHERE id=?")
            ->execute([$assign_role, $assign_tid, $u['id'], $uid]);
        $success_msg = 'User approved! They can now login immediately.';
        $active_page = 'pending';
    }

    if ($_POST['action'] === 'reject_user') {
        $uid    = intval($_POST['user_id']);
        $reason = trim($_POST['reject_reason'] ?? 'Application rejected.');
        $pdo->prepare("UPDATE users SET status='rejected',rejected_reason=? WHERE id=?")->execute([$reason,$uid]);
        $success_msg = 'User rejected.';
        $active_page = 'pending';
    }

    // ── CHANGE TENANT PLAN ────────────────────────────────────
    if ($_POST['action'] === 'change_plan') {
        $tid_p    = intval($_POST['tenant_id']);
        $new_plan = in_array($_POST['new_plan']??'', ['Starter','Pro','Enterprise']) ? $_POST['new_plan'] : 'Starter';
        $plan_staff_limits = ['Starter'=>3,'Pro'=>999,'Enterprise'=>999];
        $new_limit = $plan_staff_limits[$new_plan] ?? 3;

        $pdo->prepare("UPDATE tenants SET plan=? WHERE id=?")->execute([$new_plan, $tid_p]);

        // If downgrading, suspend excess staff (keep admins intact)
        if ($new_limit < 999) {
            $staff_list = $pdo->prepare("SELECT id FROM users WHERE tenant_id=? AND role IN ('staff','cashier') AND is_suspended=0 ORDER BY created_at ASC");
            $staff_list->execute([$tid_p]);
            $staff_list = $staff_list->fetchAll();
            if (count($staff_list) > $new_limit) {
                $excess = array_slice($staff_list, $new_limit);
                foreach ($excess as $ex) {
                    $pdo->prepare("UPDATE users SET is_suspended=1, suspended_at=NOW(), suspension_reason='Plan downgraded — staff limit exceeded.' WHERE id=?")->execute([$ex['id']]);
                }
                $suspended_count = count($excess);
                $success_msg = "Plan changed to <strong>{$new_plan}</strong>. {$suspended_count} staff suspended due to new limit.";
            } else {
                $success_msg = "Plan updated to <strong>{$new_plan}</strong> successfully!";
            }
        } else {
            // Upgrading — unsuspend all previously suspended due to plan limit
            $pdo->prepare("UPDATE users SET is_suspended=0, suspended_at=NULL, suspension_reason=NULL WHERE tenant_id=? AND suspension_reason='Plan downgraded — staff limit exceeded.'")->execute([$tid_p]);
            $success_msg = "Plan upgraded to <strong>{$new_plan}</strong>! Previously suspended staff have been restored.";
        }
        $active_page = 'tenants';
    }

    if ($_POST['action'] === 'suspend_user') {
        $uid    = intval($_POST['user_id']);
        $reason = trim($_POST['reason'] ?? 'Suspended by super admin.');
        $pdo->prepare("UPDATE users SET is_suspended=1,suspended_at=NOW(),suspension_reason=? WHERE id=?")->execute([$reason,$uid]);
        $success_msg = 'User suspended.'; $active_page='all_users';
    }
    if ($_POST['action'] === 'unsuspend_user') {
        $uid = intval($_POST['user_id']);
        $pdo->prepare("UPDATE users SET is_suspended=0,suspended_at=NULL,suspension_reason=NULL WHERE id=?")->execute([$uid]);
        $success_msg = 'User unsuspended.'; $active_page='all_users';
    }
}

// Fetch data
try {
    $tenants = $pdo->query("SELECT t.*, 
        (SELECT COUNT(*) FROM users u WHERE u.tenant_id=t.id) as user_count, 
        (SELECT COUNT(*) FROM pawn_transactions pt WHERE pt.tenant_id=t.id) as ticket_count, 
        (SELECT status FROM tenant_invitations ti WHERE ti.tenant_id=t.id ORDER BY ti.created_at DESC LIMIT 1) as invite_status 
        FROM tenants t ORDER BY t.created_at DESC")->fetchAll();
} catch (PDOException $e) {
    $tenants = [];
    $error_msg = 'Error loading tenants: ' . $e->getMessage();
}

try {
    $pending_users = $pdo->query("SELECT u.*,t.business_name FROM users u LEFT JOIN tenants t ON u.tenant_id=t.id WHERE u.status='pending' ORDER BY u.created_at DESC")->fetchAll();
} catch (PDOException $e) {
    $pending_users = [];
}

try {
    $all_users = $pdo->query("SELECT u.*,t.business_name FROM users u LEFT JOIN tenants t ON u.tenant_id=t.id ORDER BY u.created_at DESC")->fetchAll();
} catch (PDOException $e) {
    $all_users = [];
}

try {
    $invitations = $pdo->query("SELECT i.*,t.business_name,t.plan FROM tenant_invitations i JOIN tenants t ON i.tenant_id=t.id ORDER BY i.created_at DESC")->fetchAll();
} catch (PDOException $e) {
    $invitations = [];
}

$total_tenants  = count($tenants);
$active_tenants = count(array_filter($tenants,fn($t)=>$t['status']==='active'));
$pending_inv    = count(array_filter($invitations,fn($i)=>$i['status']==='pending'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>PawnHub — Super Admin</title>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
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
.sb-item:hover{background:rgba(255,255,255,.08);color:#fff;}
.sb-item.active{background:rgba(37,99,235,.25);color:#60a5fa;font-weight:600;}
.sb-item svg{width:15px;height:15px;flex-shrink:0;}
.sb-pill{margin-left:auto;background:#ef4444;color:#fff;font-size:.62rem;font-weight:700;padding:1px 6px;border-radius:100px;}
.sb-footer{padding:12px 14px;border-top:1px solid rgba(255,255,255,.08);}
.sb-logout{display:flex;align-items:center;gap:8px;font-size:.8rem;color:rgba(255,255,255,.35);text-decoration:none;padding:7px 8px;border-radius:8px;transition:all .15s;}
.sb-logout:hover{color:#f87171;background:rgba(239,68,68,.1);}
.sb-logout svg{width:14px;height:14px;}
.main{margin-left:var(--sw);flex:1;display:flex;flex-direction:column;}
.topbar{height:58px;padding:0 26px;background:#fff;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:50;}
.topbar-title{font-size:1rem;font-weight:700;}
.super-chip{font-size:.7rem;font-weight:700;background:linear-gradient(135deg,#1d4ed8,#7c3aed);color:#fff;padding:3px 10px;border-radius:100px;}
.content{padding:22px 26px;flex:1;}
.stats-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:22px;}
.stat-card{background:var(--card);border:1px solid var(--border);border-radius:12px;padding:16px 18px;display:flex;align-items:flex-start;gap:12px;}
.stat-icon{width:40px;height:40px;border-radius:10px;display:flex;align-items:center;justify-content:center;flex-shrink:0;}
.stat-icon svg{width:18px;height:18px;}
.stat-label{font-size:.7rem;font-weight:600;color:var(--text-dim);text-transform:uppercase;letter-spacing:.05em;margin-bottom:3px;}
.stat-value{font-size:1.5rem;font-weight:800;color:var(--text);line-height:1;}
.stat-sub{font-size:.71rem;color:var(--text-dim);margin-top:2px;}
.card{background:var(--card);border:1px solid var(--border);border-radius:12px;padding:18px;margin-bottom:16px;}
.card-hdr{display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;flex-wrap:wrap;gap:8px;}
.card-title{font-size:.8rem;font-weight:700;text-transform:uppercase;letter-spacing:.04em;}
table{width:100%;border-collapse:collapse;}
th{font-size:.67rem;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:var(--text-dim);padding:7px 11px;text-align:left;border-bottom:1px solid var(--border);white-space:nowrap;}
td{padding:10px 11px;font-size:.81rem;border-bottom:1px solid #f1f5f9;vertical-align:middle;}
tr:last-child td{border-bottom:none;} tr:hover td{background:#f8fafc;}
.badge{display:inline-flex;align-items:center;gap:3px;font-size:.67rem;font-weight:700;padding:2px 8px;border-radius:100px;}
.b-blue{background:#dbeafe;color:#1d4ed8;} .b-green{background:#dcfce7;color:#15803d;} .b-red{background:#fee2e2;color:#dc2626;} .b-yellow{background:#fef3c7;color:#b45309;} .b-purple{background:#f3e8ff;color:#7c3aed;} .b-gray{background:#f1f5f9;color:#475569;}
.plan-ent{background:linear-gradient(135deg,#dbeafe,#ede9fe);color:#4338ca;border:1px solid #c7d2fe;} .plan-pro{background:#fef3c7;color:#b45309;} .plan-starter{background:#f1f5f9;color:#475569;}
.b-dot{width:4px;height:4px;border-radius:50%;background:currentColor;}
.btn-sm{padding:5px 12px;border-radius:7px;font-size:.73rem;font-weight:600;cursor:pointer;border:1px solid var(--border);background:#fff;color:var(--text-m);text-decoration:none;display:inline-flex;align-items:center;gap:5px;transition:all .15s;margin-right:4px;}
.btn-sm:hover{background:var(--bg);} .btn-primary{background:var(--blue-acc);color:#fff;border-color:var(--blue-acc);} .btn-success{background:var(--success);color:#fff;border-color:var(--success);} .btn-danger{background:var(--danger);color:#fff;border-color:var(--danger);} .btn-warning{background:var(--warning);color:#fff;border-color:var(--warning);}
.alert{padding:10px 16px;border-radius:10px;font-size:.82rem;margin-bottom:18px;display:flex;align-items:center;gap:8px;}
.alert-success{background:#f0fdf4;border:1px solid #bbf7d0;color:#15803d;} .alert-error{background:#fef2f2;border:1px solid #fecaca;color:#dc2626;}
.empty-state{text-align:center;padding:40px 20px;color:var(--text-dim);}
.empty-state svg{width:34px;height:34px;margin:0 auto 9px;display:block;opacity:.3;} .empty-state p{font-size:.83rem;}
.ticket-tag{font-family:monospace;font-size:.77rem;color:var(--blue-acc);font-weight:700;}
.flow-steps{display:flex;align-items:center;gap:4px;margin-bottom:22px;flex-wrap:wrap;}
.flow-step{display:flex;align-items:center;gap:7px;background:#fff;border:1px solid var(--border);border-radius:10px;padding:9px 14px;font-size:.79rem;font-weight:600;color:var(--text-m);}
.step-num{width:22px;height:22px;border-radius:50%;background:var(--blue-acc);color:#fff;font-size:.72rem;font-weight:800;display:flex;align-items:center;justify-content:center;flex-shrink:0;}
.flow-arrow{color:var(--text-dim);font-size:1rem;margin:0 2px;}
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(15,23,42,.55);z-index:999;align-items:center;justify-content:center;backdrop-filter:blur(3px);}
.modal-overlay.open{display:flex;}
.modal{background:#fff;border-radius:16px;width:560px;max-width:95vw;max-height:92vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.2);animation:mIn .25s ease both;}
@keyframes mIn{from{opacity:0;transform:translateY(14px)}to{opacity:1;transform:none}}
.mhdr{padding:20px 22px 0;display:flex;align-items:center;justify-content:space-between;}
.mtitle{font-size:1rem;font-weight:800;} .msub{font-size:.78rem;color:var(--text-dim);margin-top:2px;}
.mclose{width:28px;height:28px;border-radius:7px;border:1.5px solid var(--border);background:#fff;cursor:pointer;display:flex;align-items:center;justify-content:center;color:var(--text-dim);}
.mclose svg{width:13px;height:13px;}
.mbody{padding:18px 22px 22px;}
.fg2{display:grid;grid-template-columns:1fr 1fr;gap:12px;}
.flabel{display:block;font-size:.74rem;font-weight:600;color:var(--text-m);margin-bottom:4px;}
.finput{width:100%;border:1.5px solid var(--border);border-radius:8px;padding:9px 11px;font-family:inherit;font-size:.85rem;color:var(--text);outline:none;background:#fff;transition:border .2s;}
.finput:focus{border-color:var(--blue-acc);box-shadow:0 0 0 3px rgba(37,99,235,.1);}
.finput::placeholder{color:#c8d0db;} select.finput{cursor:pointer;}
.slabel{font-size:.7rem;font-weight:700;letter-spacing:.07em;text-transform:uppercase;color:var(--text-dim);margin-bottom:10px;display:block;}
@media(max-width:1200px){.stats-grid{grid-template-columns:repeat(2,1fr);}}
@media(max-width:560px){.fg2{grid-template-columns:1fr;}.flow-steps{flex-direction:column;align-items:flex-start;}}
</style>
</head>
<body>
<aside class="sidebar">
  <div class="sb-brand">
    <div class="sb-logo"><svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2.5"><rect x="3" y="9" width="18" height="12"/><polyline points="3 9 12 3 21 9"/></svg></div>
    <div><div class="sb-name">PawnHub</div><div class="sb-badge">Super Admin</div></div>
  </div>
  <div class="sb-user">
    <div class="sb-avatar"><?=strtoupper(substr($u['name'],0,1))?></div>
    <div><div class="sb-uname"><?=htmlspecialchars($u['name'])?></div><div class="sb-urole">Super Administrator</div></div>
  </div>
  <nav class="sb-nav">
    <div class="sb-section">Overview</div>
    <a href="?page=dashboard"   class="sb-item <?=$active_page==='dashboard'?'active':''?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>Dashboard</a>
    <div class="sb-section">Tenants</div>
    <a href="?page=tenants"     class="sb-item <?=$active_page==='tenants'?'active':''?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="9" width="18" height="12"/><polyline points="3 9 12 3 21 9"/></svg>All Tenants</a>
    <a href="?page=invitations" class="sb-item <?=$active_page==='invitations'?'active':''?>">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>Email Invitations
      <?php if($pending_inv>0):?><span class="sb-pill"><?=$pending_inv?></span><?php endif;?>
    </a>
    <a href="?page=pending"     class="sb-item <?=$active_page==='pending'?'active':''?>">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>Pending Signups
      <?php if(count($pending_users)>0):?><span class="sb-pill"><?=count($pending_users)?></span><?php endif;?>
    </a>
    <div class="sb-section">System</div>
    <a href="?page=all_users"   class="sb-item <?=$active_page==='all_users'?'active':''?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>All Users</a>
    <a href="?page=audit"       class="sb-item <?=$active_page==='audit'?'active':''?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/></svg>Audit Logs</a>
  </nav>
  <div class="sb-footer"><a href="logout.php" class="sb-logout"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>Sign Out</a></div>
</aside>

<div class="main">
  <header class="topbar">
    <div style="display:flex;align-items:center;gap:10px;">
      <span class="topbar-title"><?php $titles=['dashboard'=>'System Dashboard','tenants'=>'Tenant Management','invitations'=>'Email Invitations','pending'=>'Pending Signups','all_users'=>'All Users','audit'=>'Audit Logs'];echo $titles[$active_page]??'Dashboard';?></span>
      <span class="super-chip">SUPER ADMIN</span>
    </div>
    <button onclick="document.getElementById('addModal').classList.add('open')" class="btn-sm btn-primary">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="width:13px;height:13px;"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>Add Tenant + Invite
    </button>
  </header>

  <div class="content">
  <?php if($success_msg):?><div class="alert alert-success"><?=htmlspecialchars($success_msg)?></div><?php endif;?>
  <?php if($error_msg):?><div class="alert alert-error">⚠ <?=htmlspecialchars($error_msg)?></div><?php endif;?>

  <?php if($active_page==='dashboard'): ?>
    <div class="flow-steps">
      <div class="flow-step"><span class="step-num">1</span>Super Admin adds tenant</div><span class="flow-arrow">→</span>
      <div class="flow-step"><span class="step-num">2</span>Token generated</div><span class="flow-arrow">→</span>
      <div class="flow-step"><span class="step-num">3</span>Email sent via Gmail</div><span class="flow-arrow">→</span>
      <div class="flow-step"><span class="step-num">4</span>Tenant clicks link</div><span class="flow-arrow">→</span>
      <div class="flow-step"><span class="step-num">5</span>Tenant registers</div><span class="flow-arrow">→</span>
      <div class="flow-step"><span class="step-num">6</span>✅ Access granted</div>
    </div>
    <div class="stats-grid">
      <div class="stat-card"><div class="stat-icon" style="background:#dbeafe;"><svg viewBox="0 0 24 24" fill="none" stroke="#2563eb" stroke-width="2"><rect x="3" y="9" width="18" height="12"/><polyline points="3 9 12 3 21 9"/></svg></div><div><div class="stat-label">Total Tenants</div><div class="stat-value"><?=$total_tenants?></div><div class="stat-sub"><?=$active_tenants?> active</div></div></div>
      <div class="stat-card"><div class="stat-icon" style="background:#fef3c7;"><svg viewBox="0 0 24 24" fill="none" stroke="#d97706" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg></div><div><div class="stat-label">Invitations</div><div class="stat-value"><?=count($invitations)?></div><div class="stat-sub"><?=$pending_inv?> pending</div></div></div>
      <div class="stat-card"><div class="stat-icon" style="background:#dcfce7;"><svg viewBox="0 0 24 24" fill="none" stroke="#16a34a" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg></div><div><div class="stat-label">Total Users</div><div class="stat-value"><?=count($all_users)?></div><div class="stat-sub"><?=count($pending_users)?> pending</div></div></div>
      <div class="stat-card"><div class="stat-icon" style="background:#f3e8ff;"><svg viewBox="0 0 24 24" fill="none" stroke="#7c3aed" stroke-width="2"><path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/></svg></div><div><div class="stat-label">Total Tickets</div><div class="stat-value"><?=$pdo->query("SELECT COUNT(*) FROM pawn_transactions")->fetchColumn()?></div></div></div>
    </div>
    <div class="card">
      <div class="card-hdr"><span class="card-title">Recent Tenants</span><a href="?page=tenants" style="font-size:.74rem;color:var(--blue-acc);font-weight:600;text-decoration:none;">View All →</a></div>
      <?php if(empty($tenants)):?><div class="empty-state"><p>No tenants yet. Click "Add Tenant + Invite" to get started!</p></div>
      <?php else:?><div style="overflow-x:auto;"><table><thead><tr><th>Business</th><th>Owner</th><th>Plan</th><th>Status</th><th>Invite</th><th>Created</th></tr></thead><tbody>
      <?php foreach(array_slice($tenants,0,8) as $t):?>
      <tr><td style="font-weight:600;"><?=htmlspecialchars($t['business_name'])?></td><td><?=htmlspecialchars($t['owner_name'])?></td><td><span class="badge <?=$t['plan']==='Enterprise'?'plan-ent':($t['plan']==='Pro'?'plan-pro':'plan-starter')?>"><?=$t['plan']?></span></td><td><span class="badge <?=$t['status']==='active'?'b-green':($t['status']==='pending'?'b-yellow':'b-red')?>"><span class="b-dot"></span><?=ucfirst($t['status'])?></span></td><td><span class="badge <?=$t['invite_status']==='used'?'b-green':($t['invite_status']==='pending'?'b-yellow':'b-gray')?>"><?=ucfirst($t['invite_status']??'—')?></span></td><td style="font-size:.74rem;color:var(--text-dim);"><?=date('M d, Y',strtotime($t['created_at']))?></td></tr>
      <?php endforeach;?></tbody></table></div><?php endif;?>
    </div>

  <?php elseif($active_page==='tenants'): ?>
    <div class="card" style="overflow-x:auto;">
      <?php if(empty($tenants)):?><div class="empty-state"><p>No tenants yet.</p></div>
      <?php else:?><table><thead><tr><th>ID</th><th>Business Name</th><th>Owner</th><th>Email</th><th>Plan</th><th>Status</th><th>Invite</th><th>Users</th><th>Staff Used</th><th>Tickets</th><th>Actions</th></tr></thead><tbody>
      <?php foreach($tenants as $t):?>
      <tr><td style="color:var(--text-dim);">#<?=$t['id']?></td><td style="font-weight:600;"><?=htmlspecialchars($t['business_name'])?></td><td><?=htmlspecialchars($t['owner_name'])?></td><td style="font-size:.76rem;color:var(--text-dim);"><?=htmlspecialchars($t['email'])?></td><td><span class="badge <?=$t['plan']==='Enterprise'?'plan-ent':($t['plan']==='Pro'?'plan-pro':'plan-starter')?>"><?=$t['plan']?></span></td><td><span class="badge <?=$t['status']==='active'?'b-green':($t['status']==='pending'?'b-yellow':'b-red')?>"><span class="b-dot"></span><?=ucfirst($t['status'])?></span></td><td><span class="badge <?=$t['invite_status']==='used'?'b-green':($t['invite_status']==='pending'?'b-yellow':'b-gray')?>"><?=ucfirst($t['invite_status']??'—')?></span></td><td><?=$t['user_count']?></td><td>
<?php
$sl=['Starter'=>3,'Pro'=>999,'Enterprise'=>999];
$lim=$sl[$t['plan']]??3;
$sc2=(int)$pdo->query("SELECT COUNT(*) FROM users WHERE tenant_id={$t['id']} AND role IN ('staff','cashier') AND is_suspended=0")->fetchColumn();
$pct2=$lim===999?0:($lim>0?min(100,round($sc2/$lim*100)):0);
$bc=$pct2>=100?'#dc2626':($pct2>=75?'#d97706':'#16a34a');
?>
<div style="font-size:.75rem;font-weight:700;color:<?=$bc?>;"><?=$sc2?>/<?=$lim===999?'∞':$lim?></div>
<?php if($lim!==999): ?><div style="height:4px;background:#e2e8f0;border-radius:100px;width:60px;overflow:hidden;margin-top:2px;"><div style="height:100%;width:<?=$pct2?>%;background:<?=$bc?>;border-radius:100px;"></div></div><?php endif; ?>
</td><td><?=$t['ticket_count']?></td>
      <td>
        <form method="POST" style="display:inline;">
          <input type="hidden" name="action" value="toggle_tenant">
          <input type="hidden" name="tenant_id" value="<?=$t['id']?>">
          <input type="hidden" name="current_status" value="<?=$t['status']?>">
          <button type="submit" class="btn-sm <?=$t['status']==='active'?'btn-danger':'btn-success'?>" style="font-size:.7rem;"><?=$t['status']==='active'?'Deactivate':'Activate'?></button>
        </form>
        <button onclick="openPlanModal(<?=$t['id']?>,'<?=htmlspecialchars($t['business_name'],ENT_QUOTES)?>','<?=$t['plan']?>')" class="btn-sm btn-warning" style="font-size:.7rem;">⭐ Plan</button>
      </td></tr>
      <?php endforeach;?></tbody></table><?php endif;?>
    </div>

  <?php elseif($active_page==='invitations'): ?>
    <div class="card" style="overflow-x:auto;">
      <?php if(empty($invitations)):?><div class="empty-state"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/></svg><p>No invitations sent yet.</p></div>
      <?php else:?><table><thead><tr><th>Tenant</th><th>Owner</th><th>Email</th><th>Plan</th><th>Status</th><th>Expires</th><th>Sent</th><th>Actions</th></tr></thead><tbody>
      <?php foreach($invitations as $inv):?>
      <tr><td style="font-weight:600;"><?=htmlspecialchars($inv['business_name'])?></td><td><?=htmlspecialchars($inv['owner_name'])?></td><td style="font-family:monospace;font-size:.76rem;"><?=htmlspecialchars($inv['email'])?></td><td><span class="badge <?=$inv['plan']==='Enterprise'?'plan-ent':($inv['plan']==='Pro'?'plan-pro':'plan-starter')?>"><?=$inv['plan']?></span></td><td><span class="badge <?=$inv['status']==='used'?'b-green':($inv['status']==='pending'?'b-yellow':'b-red')?>"><span class="b-dot"></span><?=ucfirst($inv['status'])?></span></td><td style="font-size:.75rem;color:<?=strtotime($inv['expires_at'])<time()&&$inv['status']==='pending'?'var(--danger)':'var(--text-dim)'?>;"><?=date('M d, Y h:i A',strtotime($inv['expires_at']))?></td><td style="font-size:.74rem;color:var(--text-dim);"><?=date('M d, Y',strtotime($inv['created_at']))?></td>
      <td><?php if($inv['status']==='pending'):?><form method="POST" style="display:inline;"><input type="hidden" name="action" value="resend_invite"><input type="hidden" name="inv_id" value="<?=$inv['id']?>"><button type="submit" class="btn-sm btn-warning" style="font-size:.7rem;">📧 Resend</button></form><?php else:?><span style="font-size:.74rem;color:var(--text-dim);">✓ Registered</span><?php endif;?></td></tr>
      <?php endforeach;?></tbody></table><?php endif;?>
    </div>

  <?php elseif($active_page==='pending'): ?>
    <div class="card" style="overflow-x:auto;">
      <?php if(empty($pending_users)):?><div class="empty-state"><p>🎉 No pending signups!</p></div>
      <?php else:?><table><thead><tr><th>Name</th><th>Username</th><th>Email</th><th>Applied</th><th>Actions</th></tr></thead><tbody>
      <?php foreach($pending_users as $pu):?>
      <tr><td style="font-weight:600;"><?=htmlspecialchars($pu['fullname'])?></td><td style="font-family:monospace;font-size:.78rem;color:var(--blue-acc);"><?=htmlspecialchars($pu['username'])?></td><td style="font-size:.77rem;color:var(--text-dim);"><?=htmlspecialchars($pu['email'])?></td><td style="font-size:.74rem;color:var(--text-dim);"><?=date('M d, Y h:i A',strtotime($pu['created_at']))?></td>
      <td><button onclick="openApproveModal(<?=$pu['id']?>,'<?=htmlspecialchars($pu['fullname'],ENT_QUOTES)?>')" class="btn-sm btn-success">Approve</button><button onclick="openRejectModal(<?=$pu['id']?>,'<?=htmlspecialchars($pu['fullname'],ENT_QUOTES)?>')" class="btn-sm btn-danger">Reject</button></td></tr>
      <?php endforeach;?></tbody></table><?php endif;?>
    </div>

  <?php elseif($active_page==='all_users'): ?>
    <div class="card" style="overflow-x:auto;">
      <table><thead><tr><th>Name</th><th>Username</th><th>Role</th><th>Tenant</th><th>Status</th><th>Suspended</th><th>Created</th><th>Actions</th></tr></thead><tbody>
      <?php foreach($all_users as $usr):?>
      <tr><td style="font-weight:600;"><?=htmlspecialchars($usr['fullname'])?></td><td style="font-family:monospace;font-size:.77rem;color:var(--blue-acc);"><?=htmlspecialchars($usr['username'])?></td><td><?php if($usr['role']):?><span class="badge <?=['super_admin'=>'b-purple','admin'=>'b-blue','staff'=>'b-green','cashier'=>'b-yellow'][$usr['role']]??'b-gray'?>"><?=ucwords(str_replace('_',' ',$usr['role']))?></span><?php else:?><span style="font-size:.74rem;color:var(--text-dim);">—</span><?php endif;?></td><td style="font-size:.78rem;"><?=htmlspecialchars($usr['business_name']??'System')?></td><td><span class="badge <?=$usr['status']==='approved'?'b-green':($usr['status']==='pending'?'b-yellow':'b-red')?>"><?=ucfirst($usr['status'])?></span></td><td><?=$usr['is_suspended']?'<span class="badge b-red">Suspended</span>':'<span class="badge b-green">Active</span>'?></td><td style="font-size:.74rem;color:var(--text-dim);"><?=date('M d, Y',strtotime($usr['created_at']))?></td>
      <td><?php if($usr['id']!=$u['id']): if(!$usr['is_suspended']):?><form method="POST" style="display:inline;"><input type="hidden" name="action" value="suspend_user"><input type="hidden" name="user_id" value="<?=$usr['id']?>"><input type="hidden" name="reason" value="Suspended by Super Admin."><button type="submit" class="btn-sm btn-danger" style="font-size:.7rem;" onclick="return confirm('Suspend?')">Suspend</button></form><?php else:?><form method="POST" style="display:inline;"><input type="hidden" name="action" value="unsuspend_user"><input type="hidden" name="user_id" value="<?=$usr['id']?>"><button type="submit" class="btn-sm btn-success" style="font-size:.7rem;">Unsuspend</button></form><?php endif;endif;?></td></tr>
      <?php endforeach;?></tbody></table>
    </div>

  <?php elseif($active_page==='audit'): ?>
    <?php
    try {
        $audit = $pdo->query("SELECT * FROM audit_logs ORDER BY created_at DESC LIMIT 100")->fetchAll();
    } catch (PDOException $e) {
        $audit = [];
    }
    ?>
    <div class="card" style="overflow-x:auto;">
      <?php if(empty($audit)):?><div class="empty-state"><p>No audit logs yet.</p></div>
      <?php else:?>
      <table><thead><tr><th>Date</th><th>Actor</th><th>Role</th><th>Action</th><th>Entity</th><th>Message</th></tr></thead><tbody>
      <?php foreach($audit as $a):?><tr><td style="font-size:.73rem;color:var(--text-dim);white-space:nowrap;"><?=date('M d, Y h:i A',strtotime($a['created_at']))?></td><td style="font-weight:600;font-size:.79rem;"><?=htmlspecialchars($a['actor_username']??'')?></td><td><span class="badge b-blue"><?=htmlspecialchars($a['actor_role']??'')?></span></td><td style="font-family:monospace;font-size:.74rem;color:var(--warning);"><?=htmlspecialchars($a['action']??'')?></td><td class="ticket-tag" style="font-size:.73rem;"><?=htmlspecialchars($a['entity_id']??'—')?></td><td style="font-size:.77rem;color:var(--text-dim);"><?=htmlspecialchars($a['message']??'')?></td></tr><?php endforeach;?>
      </tbody></table>
      <?php endif;?>
    </div>
  <?php endif;?>
  </div>
</div>

<!-- ADD TENANT MODAL -->
<div class="modal-overlay" id="addModal">
  <div class="modal">
    <div class="mhdr"><div><div class="mtitle">➕ Add Tenant + Send Invite</div><div class="msub">An invitation link will be sent to the owner's Gmail.</div></div><button class="mclose" onclick="document.getElementById('addModal').classList.remove('open')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button></div>
    <div class="mbody">
      <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:9px;padding:11px 14px;font-size:.78rem;color:#15803d;margin-bottom:16px;line-height:1.8;">📧 <strong>Flow:</strong> Fill form → Token generated → Email sent to owner → Owner clicks link → Owner sets username & password → Owner accesses system ✅</div>
      <form method="POST">
        <input type="hidden" name="action" value="add_tenant">
        <span class="slabel">Business Information</span>
        <div class="fg2" style="margin-bottom:12px;">
          <div style="margin-bottom:11px;"><label class="flabel">Business Name *</label><input type="text" name="business_name" class="finput" placeholder="GoldKing Pawnshop" required></div>
          <div style="margin-bottom:11px;"><label class="flabel">Owner Full Name *</label><input type="text" name="owner_name" class="finput" placeholder="Juan Dela Cruz" required></div>
          <div style="margin-bottom:11px;"><label class="flabel">Owner Gmail Address *</label><input type="email" name="email" class="finput" placeholder="owner@gmail.com" required></div>
          <div style="margin-bottom:11px;"><label class="flabel">Phone</label><input type="text" name="phone" class="finput" placeholder="09XXXXXXXXX"></div>
          <div style="grid-column:1/-1;margin-bottom:11px;"><label class="flabel">Address</label><input type="text" name="address" class="finput" placeholder="Street, City, Province"></div>
          <div style="margin-bottom:11px;"><label class="flabel">Plan *</label><select name="plan" class="finput"><option value="Starter">Starter</option><option value="Pro">Pro</option><option value="Enterprise">Enterprise</option></select></div>
          <div style="margin-bottom:11px;"><label class="flabel">Branches</label><input type="number" name="branches" class="finput" value="1" min="1"></div>
        </div>
        <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:10px 14px;font-size:.77rem;color:#1d4ed8;margin-bottom:14px;">ℹ️ No password needed — the owner will set their own via the invitation link sent to their Gmail.</div>
        <div style="display:flex;justify-content:flex-end;gap:9px;">
          <button type="button" class="btn-sm" onclick="document.getElementById('addModal').classList.remove('open')">Cancel</button>
          <button type="submit" class="btn-sm btn-primary"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="width:13px;height:13px;"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>Create + Send Invite</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- APPROVE MODAL -->
<div class="modal-overlay" id="approveModal">
  <div class="modal"><div class="mhdr"><div><div class="mtitle">Approve Application</div><div class="msub" id="approve_sub"></div></div><button class="mclose" onclick="document.getElementById('approveModal').classList.remove('open')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button></div>
  <div class="mbody"><form method="POST"><input type="hidden" name="action" value="approve_user"><input type="hidden" name="user_id" id="approve_uid">
    <div style="margin-bottom:13px;"><label class="flabel">Role *</label><select name="assign_role" class="finput" required><option value="admin">Admin</option><option value="staff">Staff</option><option value="cashier">Cashier</option></select></div>
    <div style="margin-bottom:13px;"><label class="flabel">Assign to Tenant</label><select name="assign_tenant_id" class="finput"><option value="0">-- Create New Tenant Automatically --</option><?php foreach($tenants as $t):?><option value="<?=$t['id']?>"><?=htmlspecialchars($t['business_name'])?></option><?php endforeach;?></select></div>
    <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:10px 13px;font-size:.77rem;color:#15803d;margin-bottom:14px;">✅ User will be approved and can login immediately.</div>
    <div style="display:flex;justify-content:flex-end;gap:9px;"><button type="button" class="btn-sm" onclick="document.getElementById('approveModal').classList.remove('open')">Cancel</button><button type="submit" class="btn-sm btn-success">Approve & Assign</button></div>
  </form></div></div>
</div>

<!-- REJECT MODAL -->
<div class="modal-overlay" id="rejectModal">
  <div class="modal" style="width:420px;"><div class="mhdr"><div><div class="mtitle">Reject Application</div><div class="msub" id="reject_sub"></div></div><button class="mclose" onclick="document.getElementById('rejectModal').classList.remove('open')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button></div>
  <div class="mbody"><form method="POST"><input type="hidden" name="action" value="reject_user"><input type="hidden" name="user_id" id="reject_uid">
    <div style="margin-bottom:13px;"><label class="flabel">Reason</label><textarea name="reject_reason" class="finput" rows="3" placeholder="e.g. Duplicate account..." style="resize:vertical;"></textarea></div>
    <div style="background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:10px 13px;font-size:.77rem;color:#dc2626;margin-bottom:14px;">⚠️ This cannot be undone.</div>
    <div style="display:flex;justify-content:flex-end;gap:9px;"><button type="button" class="btn-sm" onclick="document.getElementById('rejectModal').classList.remove('open')">Cancel</button><button type="submit" class="btn-sm btn-danger">Reject</button></div>
  </form></div></div>
</div>

<script>
['addModal','approveModal','rejectModal'].forEach(id=>{document.getElementById(id).addEventListener('click',function(e){if(e.target===this)this.classList.remove('open');});});
function openApproveModal(uid,name){document.getElementById('approve_uid').value=uid;document.getElementById('approve_sub').textContent='Approving: '+name;document.getElementById('approveModal').classList.add('open');}
function openRejectModal(uid,name){document.getElementById('reject_uid').value=uid;document.getElementById('reject_sub').textContent='Rejecting: '+name;document.getElementById('rejectModal').classList.add('open');}
</script>

<!-- CHANGE PLAN MODAL -->
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
          <?php foreach(['Starter'=>['Free','#475569','#f1f5f9','1 Branch, 3 Staff'],'Pro'=>['₱999/mo','#1d4ed8','#eff6ff','3 Branches, Unlimited'],'Enterprise'=>['₱2,499/mo','#7c3aed','#f3e8ff','10 Branches, Unlimited']] as $pn=>[$pp,$pc,$pbg,$pf]): ?>
          <label style="cursor:pointer;">
            <input type="radio" name="new_plan" value="<?=$pn?>" id="plan_<?=$pn?>" style="display:none;" onchange="updatePlanSelection('<?=$pn?>')">
            <div id="plan_card_<?=$pn?>" style="border:2px solid #e2e8f0;border-radius:10px;padding:12px;text-align:center;transition:all .15s;">
              <div style="font-size:.82rem;font-weight:800;color:<?=$pc?>;margin-bottom:2px;"><?=$pn?></div>
              <div style="font-size:.75rem;font-weight:700;color:<?=$pc?>;margin-bottom:6px;"><?=$pp?></div>
              <div style="font-size:.68rem;color:#94a3b8;"><?=$pf?></div>
            </div>
          </label>
          <?php endforeach; ?>
        </div>
        <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:9px;padding:11px;font-size:.78rem;color:#1d4ed8;margin-bottom:14px;line-height:1.6;">
          ℹ️ Changing the plan takes effect immediately. If downgrading, existing staff over the new limit will be suspended automatically.
        </div>
        <div style="display:flex;justify-content:flex-end;gap:9px;">
          <button type="button" class="btn-sm" onclick="document.getElementById('planModal').classList.remove('open')">Cancel</button>
          <button type="submit" class="btn-sm btn-primary" style="background:#7c3aed;border-color:#7c3aed;">Save Plan Change</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
function openPlanModal(tid, name, currentPlan) {
  document.getElementById('plan_tid').value = tid;
  document.getElementById('plan_modal_sub').textContent = name;
  // Select current plan
  const radio = document.getElementById('plan_' + currentPlan);
  if (radio) { radio.checked = true; updatePlanSelection(currentPlan); }
  document.getElementById('planModal').classList.add('open');
}
function updatePlanSelection(selected) {
  const colors = {Starter:'#475569',Pro:'#1d4ed8',Enterprise:'#7c3aed'};
  ['Starter','Pro','Enterprise'].forEach(p => {
    const card = document.getElementById('plan_card_' + p);
    if (!card) return;
    if (p === selected) {
      card.style.borderColor = colors[p];
      card.style.background  = p==='Starter'?'#f1f5f9':p==='Pro'?'#eff6ff':'#f3e8ff';
    } else {
      card.style.borderColor = '#e2e8f0';
      card.style.background  = '#fff';
    }
  });
}
document.getElementById('planModal').addEventListener('click',function(e){if(e.target===this)this.classList.remove('open');});
</script>
</body>
</html>