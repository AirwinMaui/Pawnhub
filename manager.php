<?php
require_once __DIR__ . '/session_helper.php';
pawnhub_session_start('manager');
require 'db.php';
require 'theme_helper.php';

function write_audit(PDO $pdo, $actor_id, $actor_username, $actor_role, string $action, string $entity_type = '', string $entity_id = '', string $message = '', $tenant_id = null): void {
    try {
        $pdo->prepare("INSERT INTO audit_logs (tenant_id,actor_user_id,actor_username,actor_role,action,entity_type,entity_id,message,ip_address,created_at) VALUES (?,?,?,?,?,?,?,?,?,NOW())")
            ->execute([$tenant_id,$actor_id,$actor_username,$actor_role,$action,$entity_type,$entity_id,$message,$_SERVER['REMOTE_ADDR']??'::1']);
    } catch (PDOException $e) {}
}

function redirectToTenantLogin(): void {
    $slug = $_SESSION['user']['tenant_slug'] ?? '';
    header('Location: ' . ($slug ? '/' . rawurlencode($slug) : '/login.php'));
    exit;
}
if (empty($_SESSION['user'])) { redirectToTenantLogin(); }
$u = $_SESSION['user'];
if ($u['role'] !== 'manager') { redirectToTenantLogin(); }

$tid         = $u['tenant_id'];
$active_page = $_GET['page'] ?? 'dashboard';
$success_msg = '';
$error_msg   = '';

// ── Block if tenant is deactivated ────────────────────────────
try {
    $chk = $pdo->prepare("SELECT status FROM tenants WHERE id=? LIMIT 1");
    $chk->execute([$tid]);
    $t_status = $chk->fetchColumn();
    if ($t_status === 'inactive') {
        session_unset(); session_destroy();
        redirectToTenantLogin();
    }
} catch (Throwable $e) {}

$theme     = getTenantTheme($pdo, $tid);
$sys_name  = $theme['system_name'] ?? 'PawnHub';
$logo_text = $theme['logo_text'] ?: $sys_name;
$logo_url  = $theme['logo_url']  ?? '';

$tenant = null;
if ($tid) {
    $stmt = $pdo->prepare("SELECT * FROM tenants WHERE id = ?");
    $stmt->execute([$tid]);
    $tenant = $stmt->fetch();
}

// ── POST ACTIONS ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    // Invite Staff or Cashier only (Manager CANNOT invite another Manager)
    if ($_POST['action'] === 'invite_staff') {
        $email = trim($_POST['email'] ?? '');
        $name  = trim($_POST['name']  ?? '');
        $role  = in_array($_POST['role'], ['staff','cashier']) ? $_POST['role'] : 'staff';

        if (!$email || !$name) {
            $error_msg = 'Please fill in name and email.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error_msg = 'Invalid email address.';
        } else {
            $chk = $pdo->prepare("SELECT id FROM users WHERE email=? AND tenant_id=?");
            $chk->execute([$email, $tid]);
            if ($chk->fetch()) {
                $error_msg = 'This email already has an account in this branch.';
            } else {
                $pdo->prepare("UPDATE tenant_invitations SET status='expired' WHERE email=? AND tenant_id=? AND status='pending' AND role IN ('staff','cashier')")
                    ->execute([$email, $tid]);

                $token      = bin2hex(random_bytes(32));
                $expires_at = date('Y-m-d H:i:s', strtotime('+24 hours'));

                $pdo->prepare("INSERT INTO tenant_invitations (tenant_id, email, owner_name, role, token, status, expires_at, created_by) VALUES (?,?,?,?,?,'pending',?,?)")
                    ->execute([$tid, $email, $name, $role, $token, $expires_at, $u['id']]);

                try {
                    require_once __DIR__ . '/mailer.php';
                    $biz_name_for_mail = $tenant['business_name'] ?? 'PawnHub';
                    sendStaffInvitation($email, $name, $biz_name_for_mail, $role, $token);
                    $success_msg = ucfirst($role) . " invitation sent to {$email}!";
                    write_audit($pdo, $u['id'], $u['username'], 'manager', 'STAFF_INVITE', 'user', '', "Manager invited $role: $name ($email)", $tid);
                } catch (Throwable $e) {
                    error_log('Invite email failed: ' . $e->getMessage());
                    $error_msg = 'Invitation created but email failed. Error: ' . htmlspecialchars($e->getMessage());
                }
                $active_page = 'team';
            }
        }
    }

    // Suspend / Unsuspend — Manager can only affect staff/cashier
    if ($_POST['action'] === 'toggle_user') {
        $uid  = intval($_POST['user_id']);
        $susp = intval($_POST['is_suspended']);

        $target = $pdo->prepare("SELECT * FROM users WHERE id=? AND tenant_id=? AND role IN ('staff','cashier') LIMIT 1");
        $target->execute([$uid, $tid]);
        $target = $target->fetch();

        if ($target) {
            if ($susp) {
                $pdo->prepare("UPDATE users SET is_suspended=0,suspended_at=NULL,suspension_reason=NULL WHERE id=?")->execute([$uid]);
                $success_msg = 'User unsuspended.';
                write_audit($pdo, $u['id'], $u['username'], 'manager', 'USER_UNSUSPEND', 'user', (string)$uid, "Unsuspended {$target['role']}: {$target['fullname']}", $tid);
            } else {
                $pdo->prepare("UPDATE users SET is_suspended=1,suspended_at=NOW(),suspension_reason='Suspended by manager.' WHERE id=?")->execute([$uid]);
                $success_msg = 'User suspended.';
                write_audit($pdo, $u['id'], $u['username'], 'manager', 'USER_SUSPEND', 'user', (string)$uid, "Suspended {$target['role']}: {$target['fullname']}", $tid);
            }
        } else {
            $error_msg = 'You do not have permission to modify this user.';
        }
        $active_page = 'team';
    }

    // Approve void
    if ($_POST['action'] === 'approve_void') {
        $vrid      = intval($_POST['void_id']);
        $ticket_no = trim($_POST['ticket_no']);
        $pdo->prepare("UPDATE pawn_void_requests SET status='approved',decided_by=?,decided_at=NOW() WHERE id=? AND tenant_id=?")->execute([$u['id'],$vrid,$tid]);
        $pdo->prepare("UPDATE pawn_transactions SET status='Voided' WHERE ticket_no=? AND tenant_id=?")->execute([$ticket_no,$tid]);
        $pdo->prepare("UPDATE item_inventory SET status='voided' WHERE ticket_no=? AND tenant_id=?")->execute([$ticket_no,$tid]);
        write_audit($pdo, $u['id'], $u['username'], 'manager', 'PAWN_VOID_APPROVE', 'pawn_transaction', $ticket_no, "Void approved: $ticket_no", $tid);
        $success_msg = 'Void approved.';
        $active_page = 'void_requests';
    }

    if ($_POST['action'] === 'reject_void') {
        $vrid = intval($_POST['void_id']);
        $pdo->prepare("UPDATE pawn_void_requests SET status='rejected',decided_by=?,decided_at=NOW() WHERE id=? AND tenant_id=?")->execute([$u['id'],$vrid,$tid]);
        $success_msg = 'Void rejected.';
        $active_page = 'void_requests';
    }
}

// ── Fetch data ─────────────────────────────────────────────────
$today = date('Y-m-d');

$my_team     = $pdo->prepare("SELECT * FROM users WHERE tenant_id=? AND role IN ('staff','cashier') ORDER BY role,fullname");
$my_team->execute([$tid]); $my_team = $my_team->fetchAll();

$all_tickets = $pdo->prepare("SELECT * FROM pawn_transactions WHERE tenant_id=? ORDER BY created_at DESC LIMIT 100");
$all_tickets->execute([$tid]); $all_tickets = $all_tickets->fetchAll();

$customers   = $pdo->prepare("SELECT * FROM customers WHERE tenant_id=? ORDER BY full_name");
$customers->execute([$tid]); $customers = $customers->fetchAll();

$void_reqs   = $pdo->prepare("SELECT v.*,u.fullname as req_name FROM pawn_void_requests v JOIN users u ON v.requested_by=u.id WHERE v.tenant_id=? ORDER BY v.requested_at DESC");
$void_reqs->execute([$tid]); $void_reqs = $void_reqs->fetchAll();

$audit_logs  = $pdo->prepare("SELECT * FROM audit_logs WHERE tenant_id=? AND actor_role IN ('manager','staff','cashier') ORDER BY created_at DESC LIMIT 200");
$audit_logs->execute([$tid]); $audit_logs = $audit_logs->fetchAll();

$tickets_today  = count(array_filter($all_tickets, fn($t)=>substr($t['created_at'],0,10)===$today));
$active_tickets = count(array_filter($all_tickets, fn($t)=>$t['status']==='Stored'));
$pending_voids  = array_filter($void_reqs, fn($v)=>$v['status']==='pending');

$business_name = $tenant['business_name'] ?? 'My Branch';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title><?=htmlspecialchars($business_name)?> — Manager</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet">
<?= renderThemeCSS($theme) ?>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
:root{
  --sw:268px;
  --g:var(--t-primary,#059669); --gd:var(--t-primary-d,#047857);
  --bg:#070d0a; --text:#f1f5f9;
  --text-m:rgba(255,255,255,.65); --text-dim:rgba(255,255,255,.35);
}
body{font-family:'Inter',sans-serif;background:var(--bg);color:var(--text);display:flex;min-height:100vh;overflow:hidden;}
.bg-scene{position:fixed;inset:0;z-index:0;}
.bg-scene img{width:100%;height:100%;object-fit:cover;opacity:.09;filter:brightness(.5) saturate(.5);}
.bg-overlay{position:absolute;inset:0;background:linear-gradient(135deg,rgba(6,78,59,.18) 0%,rgba(7,13,10,.97) 45%);}

/* SIDEBAR */
.sidebar{width:var(--sw);min-height:100vh;background:rgba(4,14,9,.9);backdrop-filter:blur(40px);border-right:1px solid rgba(var(--t-primary,5,150,105),.1);display:flex;flex-direction:column;position:fixed;left:0;top:0;bottom:0;z-index:100;overflow-y:auto;}
.sb-brand{padding:22px 18px 14px;border-bottom:1px solid rgba(255,255,255,.06);display:flex;align-items:center;gap:11px;}
.sb-logo{width:38px;height:38px;background:linear-gradient(135deg,var(--t-primary,#059669),var(--t-secondary,#064e3b));border-radius:11px;display:flex;align-items:center;justify-content:center;flex-shrink:0;overflow:hidden;box-shadow:0 4px 14px rgba(0,0,0,.4);}
.sb-logo img{width:100%;height:100%;object-fit:cover;}
.sb-logo svg{width:19px;height:19px;}
.sb-name{font-size:.92rem;font-weight:800;color:#fff;letter-spacing:-.02em;}
.sb-subtitle{font-size:.58rem;color:rgba(255,255,255,.3);font-weight:600;letter-spacing:.1em;text-transform:uppercase;margin-top:1px;}

.sb-role-card{margin:10px 10px 0;background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.08);border-radius:12px;padding:12px 14px;}
.sb-role-label{font-size:.58rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:rgba(255,255,255,.3);margin-bottom:4px;}
.sb-role-name{font-size:.85rem;font-weight:700;color:#fff;}
.sb-role-badge{display:inline-flex;align-items:center;gap:4px;font-size:.66rem;font-weight:700;background:rgba(255,255,255,.08);color:rgba(255,255,255,.6);padding:2px 8px;border-radius:100px;margin-top:5px;}

.sb-user{padding:10px 18px;border-bottom:1px solid rgba(255,255,255,.05);display:flex;align-items:center;gap:9px;margin-top:8px;}
.sb-avatar{width:30px;height:30px;border-radius:50%;background:linear-gradient(135deg,var(--t-primary,#059669),var(--t-secondary,#064e3b));display:flex;align-items:center;justify-content:center;font-size:.74rem;font-weight:700;color:#fff;flex-shrink:0;}
.sb-uname{font-size:.79rem;font-weight:700;color:#fff;}
.sb-urole{font-size:.62rem;color:rgba(255,255,255,.3);}
.sb-status{display:inline-flex;align-items:center;gap:3px;font-size:.6rem;font-weight:700;background:rgba(16,185,129,.18);color:#6ee7b7;padding:2px 7px;border-radius:100px;margin-top:3px;}

.sb-nav{flex:1;padding:10px 0;}
.sb-section{font-size:.58rem;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:rgba(255,255,255,.2);padding:12px 16px 4px;}
.sb-item{display:flex;align-items:center;gap:10px;padding:9px 14px;margin:1px 8px;border-radius:10px;color:rgba(255,255,255,.4);font-size:.82rem;font-weight:500;text-decoration:none;transition:all .18s;}
.sb-item:hover{background:rgba(255,255,255,.06);color:rgba(255,255,255,.9);}
.sb-item.active{background:color-mix(in srgb,var(--t-primary,#059669) 20%,transparent);color:var(--t-accent,#6ee7b7);font-weight:600;}
.sb-item .material-symbols-outlined{font-size:18px;flex-shrink:0;}
.sb-pill{margin-left:auto;background:#ef4444;color:#fff;font-size:.6rem;font-weight:700;padding:1px 7px;border-radius:100px;}
.sb-footer{padding:12px 14px;border-top:1px solid rgba(255,255,255,.05);}
.sb-logout{display:flex;align-items:center;gap:9px;font-size:.8rem;color:rgba(255,255,255,.3);text-decoration:none;padding:9px 10px;border-radius:10px;transition:all .18s;}
.sb-logout:hover{color:#f87171;background:rgba(239,68,68,.1);}
.sb-logout .material-symbols-outlined{font-size:18px;}

/* MAIN */
.main{margin-left:var(--sw);flex:1;display:flex;flex-direction:column;position:relative;z-index:10;height:100vh;overflow-y:auto;}
.topbar{height:60px;padding:0 26px;background:rgba(4,14,9,.8);backdrop-filter:blur(20px);border-bottom:1px solid rgba(255,255,255,.06);display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:50;}
.topbar-title{font-size:.97rem;font-weight:700;color:#fff;}
.mgr-chip{font-size:.68rem;font-weight:700;background:color-mix(in srgb,var(--t-primary,#059669) 15%,transparent);color:var(--t-accent,#6ee7b7);padding:3px 11px;border-radius:100px;border:1px solid color-mix(in srgb,var(--t-primary,#059669) 30%,transparent);}
.content{padding:22px 26px;flex:1;}

.card{background:rgba(255,255,255,.04);backdrop-filter:blur(16px);border:1px solid rgba(255,255,255,.07);border-radius:16px;padding:18px 20px;}
.stats-row{display:grid;grid-template-columns:repeat(4,1fr);gap:13px;margin-bottom:20px;}
.stat-card{background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.07);border-radius:14px;padding:16px 18px;}
.stat-top{display:flex;align-items:center;justify-content:space-between;margin-bottom:9px;}
.stat-icon{width:36px;height:36px;border-radius:10px;display:flex;align-items:center;justify-content:center;}
.stat-icon .material-symbols-outlined{font-size:18px;}
.stat-value{font-size:1.5rem;font-weight:800;color:#fff;letter-spacing:-.03em;}
.stat-label{font-size:.68rem;color:rgba(255,255,255,.35);margin-top:3px;}

.page-hdr{display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:18px;flex-wrap:wrap;gap:10px;}
.page-hdr h2{font-size:1.1rem;font-weight:800;color:#fff;}
.page-hdr p{font-size:.78rem;color:rgba(255,255,255,.35);margin-top:2px;}

table{width:100%;border-collapse:collapse;}
th{font-size:.63rem;font-weight:700;letter-spacing:.07em;text-transform:uppercase;color:rgba(255,255,255,.3);padding:8px 12px;text-align:left;border-bottom:1px solid rgba(255,255,255,.06);}
td{padding:11px 12px;font-size:.81rem;color:rgba(255,255,255,.7);border-bottom:1px solid rgba(255,255,255,.04);vertical-align:middle;}
tr:last-child td{border-bottom:none;}
tr:hover td{background:rgba(255,255,255,.02);}
.ticket-tag{font-family:monospace;font-size:.76rem;color:var(--t-accent,#34d399);font-weight:700;}
.badge{display:inline-flex;align-items:center;gap:3px;font-size:.63rem;font-weight:700;padding:3px 9px;border-radius:100px;}
.b-blue{background:rgba(59,130,246,.2);color:#93c5fd;}
.b-green{background:rgba(16,185,129,.2);color:#6ee7b7;}
.b-red{background:rgba(239,68,68,.2);color:#fca5a5;}
.b-yellow{background:rgba(245,158,11,.2);color:#fcd34d;}
.b-purple{background:rgba(139,92,246,.2);color:#c4b5fd;}
.b-gray{background:rgba(255,255,255,.07);color:rgba(255,255,255,.5);}
.b-dot{width:4px;height:4px;border-radius:50%;background:currentColor;}

.btn-sm{padding:6px 12px;border-radius:8px;font-size:.75rem;font-weight:600;cursor:pointer;border:1px solid rgba(255,255,255,.1);background:rgba(255,255,255,.06);color:rgba(255,255,255,.6);text-decoration:none;display:inline-flex;align-items:center;gap:5px;transition:all .15s;font-family:inherit;}
.btn-sm:hover{background:rgba(255,255,255,.11);}
.btn-primary{background:var(--t-primary,#059669);color:#fff;border-color:transparent;}
.btn-primary:hover{background:var(--t-primary-d,#047857);}
.btn-danger{background:rgba(239,68,68,.8);color:#fff;border-color:transparent;}
.btn-success{background:rgba(16,185,129,.8);color:#fff;border-color:transparent;}

.flabel{display:block;font-size:.73rem;font-weight:600;color:rgba(255,255,255,.45);margin-bottom:5px;}
.finput{width:100%;border:1.5px solid rgba(255,255,255,.1);border-radius:10px;padding:9px 12px;font-family:inherit;font-size:.84rem;color:#fff;outline:none;background:rgba(255,255,255,.06);transition:border .2s;}
.finput:focus{border-color:var(--t-primary,#059669);box-shadow:0 0 0 3px color-mix(in srgb,var(--t-primary,#059669) 20%,transparent);}
.finput::placeholder{color:rgba(255,255,255,.2);}
.finput option{background:#0a150e;color:#fff;}
.fgroup{margin-bottom:12px;}

.alert{padding:11px 16px;border-radius:12px;font-size:.82rem;margin-bottom:18px;display:flex;align-items:center;gap:9px;}
.alert-success{background:rgba(16,185,129,.12);border:1px solid rgba(16,185,129,.25);color:#6ee7b7;}
.alert-error{background:rgba(239,68,68,.12);border:1px solid rgba(239,68,68,.25);color:#fca5a5;}

.empty-state{text-align:center;padding:48px 20px;color:rgba(255,255,255,.25);}
.empty-state .material-symbols-outlined{font-size:46px;display:block;margin:0 auto 14px;opacity:.3;}
.empty-state p{font-size:.82rem;}

.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.78);z-index:999;align-items:center;justify-content:center;backdrop-filter:blur(6px);}
.modal-overlay.open{display:flex;}
.modal{background:#070d0a;border:1px solid rgba(5,150,105,.15);border-radius:20px;width:480px;max-width:95vw;max-height:92vh;overflow-y:auto;box-shadow:0 24px 80px rgba(0,0,0,.7);animation:mIn .25s ease both;}
@keyframes mIn{from{opacity:0;transform:translateY(16px)}to{opacity:1;transform:none}}
.mhdr{padding:22px 24px 0;display:flex;align-items:center;justify-content:space-between;}
.mtitle{font-size:1rem;font-weight:800;color:#fff;}
.mclose{width:30px;height:30px;border-radius:8px;border:1px solid rgba(255,255,255,.1);background:rgba(255,255,255,.04);cursor:pointer;display:flex;align-items:center;justify-content:center;color:rgba(255,255,255,.4);}
.mclose .material-symbols-outlined{font-size:16px;}
.mbody{padding:18px 24px 24px;}
.card-title{font-size:.78rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:rgba(255,255,255,.35);margin-bottom:14px;}
.qa-btn{display:flex;align-items:center;gap:10px;padding:12px 14px;border-radius:12px;font-family:inherit;font-size:.83rem;font-weight:600;cursor:pointer;border:none;width:100%;text-align:left;transition:all .18s;margin-bottom:8px;text-decoration:none;color:#fff;}
.qa-primary{background:var(--g);}
.qa-primary:hover{background:var(--gd);}
.qa-secondary{background:rgba(255,255,255,.05);color:rgba(255,255,255,.7);border:1px solid rgba(255,255,255,.08);}
.qa-secondary:hover{background:rgba(255,255,255,.1);color:#fff;}
.qa-icon{width:28px;height:28px;border-radius:8px;display:flex;align-items:center;justify-content:center;flex-shrink:0;}
.qa-icon .material-symbols-outlined{font-size:16px;}
.material-symbols-outlined{font-variation-settings:'FILL' 0,'wght' 400,'GRAD' 0,'opsz' 24;}
@media(max-width:1000px){.stats-row{grid-template-columns:repeat(2,1fr);}}
</style>
</head>
<body>
<?php $bgImg = getTenantBgImage($theme, 'https://images.unsplash.com/photo-1554224155-8d04cb21cd6c?w=1600&auto=format&fit=crop&q=60'); ?>
<div class="bg-scene">
  <img src="<?=$bgImg?>" alt="">
  <div class="bg-overlay"></div>
</div>

<aside class="sidebar">
  <div class="sb-brand">
    <div class="sb-logo">
      <?php if($logo_url):?><img src="<?=htmlspecialchars($logo_url)?>" alt="logo">
      <?php else:?><svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2.5"><rect x="3" y="9" width="18" height="12"/><polyline points="3 9 12 3 21 9"/></svg><?php endif;?>
    </div>
    <div>
      <div class="sb-name"><?=htmlspecialchars($business_name)?></div>
      <div class="sb-subtitle">Manager Portal</div>
    </div>
  </div>

  <?php if($tenant):?>
  <div class="sb-role-card">
    <div class="sb-role-label">My Branch</div>
    <div class="sb-role-name"><?=htmlspecialchars($tenant['business_name'])?></div>
    <div class="sb-role-badge">
      <span class="material-symbols-outlined" style="font-size:11px;font-variation-settings:'FILL' 1,'wght' 400,'GRAD' 0,'opsz' 24;">manage_accounts</span>
      Branch Manager
    </div>
  </div>
  <?php endif;?>

  <div class="sb-user">
    <div class="sb-avatar"><?=strtoupper(substr($u['name'],0,1))?></div>
    <div>
      <div class="sb-uname"><?=htmlspecialchars(explode(' ',$u['name'])[0]??$u['name'])?></div>
      <div class="sb-urole">Branch Manager</div>
      <div class="sb-status">● ONLINE</div>
    </div>
  </div>

  <nav class="sb-nav">
    <div class="sb-section">Overview</div>
    <a href="?page=dashboard" class="sb-item <?=$active_page==='dashboard'?'active':''?>">
      <span class="material-symbols-outlined">dashboard</span>Dashboard
    </a>

    <div class="sb-section">Branch Records</div>
    <a href="?page=tickets" class="sb-item <?=$active_page==='tickets'?'active':''?>">
      <span class="material-symbols-outlined">receipt_long</span>Pawn Tickets
    </a>
    <a href="?page=customers" class="sb-item <?=$active_page==='customers'?'active':''?>">
      <span class="material-symbols-outlined">group</span>Customers
    </a>

    <div class="sb-section">Approvals</div>
    <a href="?page=void_requests" class="sb-item <?=$active_page==='void_requests'?'active':''?>">
      <span class="material-symbols-outlined">cancel_presentation</span>Void Requests
      <?php if(count($pending_voids)>0):?><span class="sb-pill"><?=count($pending_voids)?></span><?php endif;?>
    </a>

    <div class="sb-section">Team Management</div>
    <a href="?page=team" class="sb-item <?=$active_page==='team'?'active':''?>">
      <span class="material-symbols-outlined">badge</span>Staff &amp; Cashier
    </a>
    <a href="?page=invite" class="sb-item <?=$active_page==='invite'?'active':''?>">
      <span class="material-symbols-outlined">person_add</span>Invite Member
    </a>

    <div class="sb-section">Reports</div>
    <a href="?page=audit" class="sb-item <?=$active_page==='audit'?'active':''?>">
      <span class="material-symbols-outlined">manage_search</span>Audit Logs
    </a>
  </nav>

  <div class="sb-footer">
    <a href="logout.php?role=manager" class="sb-logout">      <span class="material-symbols-outlined">logout</span>Sign Out
    </a>
  </div>
</aside>

<div class="main">
  <header class="topbar">
    <div style="display:flex;align-items:center;gap:10px;">
      <?php $titles=['dashboard'=>'Manager Dashboard','tickets'=>'Pawn Tickets','customers'=>'Customers','void_requests'=>'Void Requests','team'=>'Staff & Cashier Team','invite'=>'Invite Team Member','audit'=>'Audit Logs']; ?>
      <span class="topbar-title"><?=htmlspecialchars($titles[$active_page]??'Dashboard')?></span>
      <?php if($tenant):?><span class="mgr-chip"><?=htmlspecialchars($tenant['business_name'])?></span><?php endif;?>
    </div>
    <div style="display:flex;align-items:center;gap:10px;">
      <div style="display:flex;align-items:center;gap:7px;background:rgba(5,150,105,.1);border:1px solid rgba(5,150,105,.18);padding:5px 11px;border-radius:100px;">
        <span style="width:8px;height:8px;border-radius:50%;background:var(--t-primary,#059669);display:inline-block;animation:pulse 2s infinite;"></span>
        <span style="font-size:.69rem;color:#6ee7b7;font-weight:600;">Manager</span>
      </div>
      <span style="font-size:.72rem;color:rgba(255,255,255,.3);">📅 <?=date('M d, Y')?></span>
    </div>
  </header>

  <div class="content">
  <?php if($success_msg):?><div class="alert alert-success"><span class="material-symbols-outlined" style="font-size:17px;font-variation-settings:'FILL' 1,'wght' 400,'GRAD' 0,'opsz' 24;">check_circle</span><?=htmlspecialchars($success_msg)?></div><?php endif;?>
  <?php if($error_msg):?><div class="alert alert-error"><span class="material-symbols-outlined" style="font-size:17px;">warning</span><?=htmlspecialchars($error_msg)?></div><?php endif;?>

  <?php if($active_page==='dashboard'): ?>
    <div class="page-hdr">
      <div>
        <h2>Welcome, <?=htmlspecialchars(explode(' ',$u['name'])[0])?>! 🧑‍💼</h2>
        <p>Branch overview for <?=date('F j, Y')?>.</p>
      </div>
      <button onclick="document.getElementById('inviteModal').classList.add('open')" class="btn-sm btn-primary">
        <span class="material-symbols-outlined" style="font-size:15px;">person_add</span>Invite Staff / Cashier
      </button>
    </div>

    <!-- Branch banner -->
    <div style="background:linear-gradient(135deg,var(--t-secondary,#064e3b),var(--t-primary,#059669));border-radius:14px;padding:18px 22px;margin-bottom:20px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;border:1px solid rgba(255,255,255,.1);">
      <div>
        <div style="font-size:.65rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:rgba(255,255,255,.5);margin-bottom:4px;">Your Branch</div>
        <div style="font-size:1.05rem;font-weight:800;color:#fff;"><?=htmlspecialchars($tenant['business_name']??'—')?></div>
        <div style="font-size:.76rem;color:rgba(255,255,255,.5);margin-top:2px;"><?=$tenant['plan']?> Plan &middot; Branch Manager</div>
        <div style="font-size:.72rem;color:rgba(255,255,255,.35);margin-top:4px;font-family:monospace;">Tenant #<?=str_pad($tid,4,'0',STR_PAD_LEFT)?></div>
        <?php if(!empty($tenant['phone'])):?>
        <div style="font-size:.74rem;color:rgba(255,255,255,.6);margin-top:5px;display:flex;align-items:center;gap:5px;">
          <span class="material-symbols-outlined" style="font-size:14px;font-variation-settings:'FILL' 1,'wght' 400,'GRAD' 0,'opsz' 24;">call</span>
          <?=htmlspecialchars($tenant['phone'])?>
        </div>
        <?php endif;?>
        <?php if(!empty($tenant['address'])):?>
        <div style="font-size:.74rem;color:rgba(255,255,255,.6);margin-top:3px;display:flex;align-items:center;gap:5px;">
          <span class="material-symbols-outlined" style="font-size:14px;font-variation-settings:'FILL' 1,'wght' 400,'GRAD' 0,'opsz' 24;">location_on</span>
          <?=htmlspecialchars($tenant['address'])?>
        </div>
        <?php endif;?>
      </div>
      <div style="text-align:right;">
        <div style="font-size:.65rem;color:rgba(255,255,255,.4);margin-bottom:3px;">Team Members</div>
        <div style="font-size:1.5rem;font-weight:800;color:#fff;"><?=count($my_team)?></div>
      </div>
    </div>

    <!-- Stats -->
    <div class="stats-row">
      <div class="stat-card">
        <div class="stat-top"><div class="stat-icon" style="background:rgba(5,150,105,.15);"><span class="material-symbols-outlined" style="color:#6ee7b7;">confirmation_number</span></div></div>
        <div class="stat-value"><?=$tickets_today?></div><div class="stat-label">Tickets Today</div>
      </div>
      <div class="stat-card">
        <div class="stat-top"><div class="stat-icon" style="background:rgba(59,130,246,.15);"><span class="material-symbols-outlined" style="color:#93c5fd;">shield</span></div></div>
        <div class="stat-value"><?=$active_tickets?></div><div class="stat-label">Active Tickets</div>
      </div>
      <div class="stat-card">
        <div class="stat-top"><div class="stat-icon" style="background:rgba(245,158,11,.15);"><span class="material-symbols-outlined" style="color:#fcd34d;">cancel_presentation</span></div></div>
        <div class="stat-value"><?=count($pending_voids)?></div><div class="stat-label">Pending Voids</div>
      </div>
      <div class="stat-card">
        <div class="stat-top"><div class="stat-icon" style="background:rgba(139,92,246,.15);"><span class="material-symbols-outlined" style="color:#c4b5fd;">badge</span></div></div>
        <div class="stat-value"><?=count($my_team)?></div><div class="stat-label">Staff &amp; Cashiers</div>
      </div>
    </div>

    <div style="display:grid;grid-template-columns:260px 1fr;gap:16px;">
      <!-- Quick actions + mini team -->
      <div>
        <div class="card" style="margin-bottom:14px;">
          <div class="card-title">⚡ Quick Actions</div>
          <button onclick="document.getElementById('inviteModal').classList.add('open')" class="qa-btn qa-primary">
            <div class="qa-icon" style="background:rgba(255,255,255,.15);"><span class="material-symbols-outlined">person_add</span></div>Invite Staff / Cashier
          </button>
          <a href="?page=void_requests" class="qa-btn qa-secondary">
            <div class="qa-icon" style="background:rgba(245,158,11,.12);"><span class="material-symbols-outlined" style="color:#fcd34d;">cancel_presentation</span></div>Review Voids <?php if(count($pending_voids)):?><span style="background:#ef4444;color:#fff;font-size:.6rem;font-weight:700;padding:1px 6px;border-radius:100px;margin-left:4px;"><?=count($pending_voids)?></span><?php endif;?>
          </a>
          <a href="?page=team" class="qa-btn qa-secondary">
            <div class="qa-icon" style="background:rgba(5,150,105,.12);"><span class="material-symbols-outlined" style="color:#6ee7b7;">badge</span></div>Manage Team
          </a>
        </div>

        <?php if(!empty($my_team)):?>
        <div class="card">
          <div class="card-title">👥 My Team</div>
          <?php foreach(array_slice($my_team,0,5) as $m):?>
          <div style="display:flex;align-items:center;gap:9px;padding:7px 0;border-bottom:1px solid rgba(255,255,255,.04);">
            <div style="width:28px;height:28px;border-radius:50%;background:<?=$m['role']==='cashier'?'rgba(139,92,246,.4)':'rgba(59,130,246,.4)'?>;display:flex;align-items:center;justify-content:center;font-size:.7rem;font-weight:700;color:#fff;flex-shrink:0;"><?=strtoupper(substr($m['fullname'],0,1))?></div>
            <div style="flex:1;min-width:0;">
              <div style="font-size:.8rem;font-weight:600;color:#fff;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?=htmlspecialchars($m['fullname'])?></div>
              <div style="font-size:.68rem;color:rgba(255,255,255,.3);"><?=ucfirst($m['role'])?></div>
            </div>
            <span class="badge <?=$m['is_suspended']?'b-red':'b-green'?>"><?=$m['is_suspended']?'Susp':'Active'?></span>
          </div>
          <?php endforeach;?>
          <?php if(count($my_team)>5):?><a href="?page=team" style="display:block;text-align:center;font-size:.74rem;color:#6ee7b7;margin-top:10px;text-decoration:none;">All <?=count($my_team)?> members →</a><?php endif;?>
        </div>
        <?php endif;?>
      </div>

      <!-- Recent tickets -->
      <div class="card">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;">
          <div class="card-title" style="margin:0;">Recent Pawn Tickets</div>
          <a href="?page=tickets" style="font-size:.72rem;color:#6ee7b7;font-weight:600;text-decoration:none;">View All →</a>
        </div>
        <?php if(empty($all_tickets)):?>
          <div class="empty-state"><span class="material-symbols-outlined">receipt_long</span><p>No tickets yet.</p></div>
        <?php else:?>
        <div style="overflow-x:auto;"><table><thead><tr><th>Ticket</th><th>Customer</th><th>Item</th><th>Loan</th><th>Status</th><th>Maturity</th></tr></thead><tbody>
        <?php foreach(array_slice($all_tickets,0,8) as $t):
          $sc=['Stored'=>'b-blue','Released'=>'b-green','Renewed'=>'b-yellow','Voided'=>'b-red','Auctioned'=>'b-gray'];?>
        <tr>
          <td><span class="ticket-tag"><?=htmlspecialchars($t['ticket_no'])?></span></td>
          <td style="font-weight:600;color:#fff;"><?=htmlspecialchars($t['customer_name'])?></td>
          <td><?=htmlspecialchars($t['item_category'])?></td>
          <td>₱<?=number_format($t['loan_amount'],2)?></td>
          <td><span class="badge <?=$sc[$t['status']]??'b-gray'?>"><?=$t['status']?></span></td>
          <td style="font-size:.73rem;color:<?=strtotime($t['maturity_date'])<time()&&$t['status']==='Stored'?'#fca5a5':'rgba(255,255,255,.35)'?>;"><?=$t['maturity_date']?></td>
        </tr>
        <?php endforeach;?></tbody></table></div>
        <?php endif;?>
      </div>
    </div>

  <?php elseif($active_page==='tickets'): ?>
    <div class="page-hdr"><div><h2>Pawn Tickets</h2><p><?=count($all_tickets)?> records</p></div></div>
    <div class="card" style="overflow-x:auto;">
      <?php if(empty($all_tickets)):?><div class="empty-state"><span class="material-symbols-outlined">receipt_long</span><p>No tickets yet.</p></div>
      <?php else:?>
      <table><thead><tr><th>Ticket No.</th><th>Customer</th><th>Contact</th><th>Item</th><th>Loan</th><th>Total Redeem</th><th>Maturity</th><th>Status</th></tr></thead><tbody>
      <?php foreach($all_tickets as $t):
        $sc=['Stored'=>'b-blue','Released'=>'b-green','Renewed'=>'b-yellow','Voided'=>'b-red','Auctioned'=>'b-gray'];?>
      <tr>
        <td><span class="ticket-tag"><?=htmlspecialchars($t['ticket_no'])?></span></td>
        <td style="font-weight:600;color:#fff;"><?=htmlspecialchars($t['customer_name'])?></td>
        <td style="font-family:monospace;font-size:.75rem;"><?=htmlspecialchars($t['contact_number'])?></td>
        <td><?=htmlspecialchars($t['item_category'])?></td>
        <td>₱<?=number_format($t['loan_amount'],2)?></td>
        <td style="font-weight:700;color:#fff;">₱<?=number_format($t['total_redeem'],2)?></td>
        <td style="font-size:.73rem;color:<?=strtotime($t['maturity_date'])<time()&&$t['status']==='Stored'?'#fca5a5':'rgba(255,255,255,.35)'?>;"><?=$t['maturity_date']?></td>
        <td><span class="badge <?=$sc[$t['status']]??'b-gray'?>"><?=$t['status']?></span></td>
      </tr>
      <?php endforeach;?></tbody></table>
      <?php endif;?>
    </div>

  <?php elseif($active_page==='customers'): ?>
    <div class="page-hdr"><div><h2>Customers</h2><p><?=count($customers)?> records</p></div></div>
    <div class="card" style="overflow-x:auto;">
      <?php if(empty($customers)):?><div class="empty-state"><span class="material-symbols-outlined">group</span><p>No customers yet.</p></div>
      <?php else:?>
      <table><thead><tr><th>Name</th><th>Contact</th><th>Email</th><th>Gender</th><th>ID Type</th><th>Registered</th></tr></thead><tbody>
      <?php foreach($customers as $c):?>
      <tr>
        <td style="font-weight:600;color:#fff;"><?=htmlspecialchars($c['full_name'])?></td>
        <td style="font-family:monospace;font-size:.75rem;"><?=htmlspecialchars($c['contact_number'])?></td>
        <td style="font-size:.75rem;color:rgba(255,255,255,.4);"><?=htmlspecialchars($c['email']??'—')?></td>
        <td><?=$c['gender']?></td>
        <td><?=htmlspecialchars($c['valid_id_type']??'—')?></td>
        <td style="font-size:.73rem;color:rgba(255,255,255,.35);"><?=date('M d, Y',strtotime($c['registered_at']))?></td>
      </tr>
      <?php endforeach;?></tbody></table>
      <?php endif;?>
    </div>

  <?php elseif($active_page==='void_requests'): ?>
    <div class="page-hdr"><div><h2>Void Requests</h2><p>Approve or reject staff void requests</p></div></div>
    <div class="card" style="overflow-x:auto;">
      <?php if(empty($void_reqs)):?><div class="empty-state"><span class="material-symbols-outlined">cancel_presentation</span><p>No void requests yet.</p></div>
      <?php else:?>
      <table><thead><tr><th>Ticket</th><th>Requested By</th><th>Reason</th><th>Status</th><th>Date</th><th>Action</th></tr></thead><tbody>
      <?php foreach($void_reqs as $v):?>
      <tr>
        <td><span class="ticket-tag"><?=htmlspecialchars($v['ticket_no'])?></span></td>
        <td style="font-weight:600;color:#fff;"><?=htmlspecialchars($v['req_name'])?></td>
        <td style="max-width:180px;font-size:.78rem;"><?=htmlspecialchars($v['reason'])?></td>
        <td><span class="badge <?=$v['status']==='approved'?'b-green':($v['status']==='pending'?'b-yellow':'b-red')?>"><?=ucfirst($v['status'])?></span></td>
        <td style="font-size:.72rem;color:rgba(255,255,255,.35);"><?=date('M d, Y h:i A',strtotime($v['requested_at']))?></td>
        <td>
          <?php if($v['status']==='pending'):?>
          <form method="POST" style="display:inline;">
            <input type="hidden" name="action" value="approve_void">
            <input type="hidden" name="void_id" value="<?=$v['id']?>">
            <input type="hidden" name="ticket_no" value="<?=htmlspecialchars($v['ticket_no'])?>">
            <button type="submit" class="btn-sm btn-success" onclick="return confirm('Approve void for <?=htmlspecialchars($v['ticket_no'])?>?')" style="font-size:.7rem;">Approve</button>
          </form>
          <form method="POST" style="display:inline;">
            <input type="hidden" name="action" value="reject_void">
            <input type="hidden" name="void_id" value="<?=$v['id']?>">
            <button type="submit" class="btn-sm btn-danger" onclick="return confirm('Reject this void request?')" style="font-size:.7rem;">Reject</button>
          </form>
          <?php else:?>—<?php endif;?>
        </td>
      </tr>
      <?php endforeach;?></tbody></table>
      <?php endif;?>
    </div>

  <?php elseif($active_page==='team'): ?>
    <div class="page-hdr">
      <div><h2>Staff &amp; Cashier Team</h2><p><?=count($my_team)?> member<?=count($my_team)!==1?'s':''?></p></div>
      <button onclick="document.getElementById('inviteModal').classList.add('open')" class="btn-sm btn-primary">
        <span class="material-symbols-outlined" style="font-size:15px;">person_add</span>Invite Member
      </button>
    </div>
    <div class="card" style="overflow-x:auto;">
      <?php if(empty($my_team)):?>
        <div class="empty-state">
          <span class="material-symbols-outlined">badge</span>
          <p>No staff or cashiers yet.<br>Use the Invite button to add team members.</p>
        </div>
      <?php else:?>
      <table><thead><tr><th>Name</th><th>Username</th><th>Role</th><th>Status</th><th>Joined</th><th>Actions</th></tr></thead><tbody>
      <?php foreach($my_team as $m):
        $role_badge=$m['role']==='cashier'?'b-purple':'b-blue';
        $avatar_bg=$m['role']==='cashier'?'rgba(139,92,246,.4)':'rgba(59,130,246,.4)';?>
      <tr>
        <td>
          <div style="display:flex;align-items:center;gap:9px;">
            <div style="width:28px;height:28px;border-radius:50%;background:<?=$avatar_bg?>;display:flex;align-items:center;justify-content:center;font-size:.68rem;font-weight:700;color:#fff;"><?=strtoupper(substr($m['fullname'],0,1))?></div>
            <div>
              <div style="font-weight:600;color:#fff;font-size:.83rem;"><?=htmlspecialchars($m['fullname'])?></div>
              <div style="font-size:.7rem;color:rgba(255,255,255,.3);"><?=htmlspecialchars($m['email']??'')?></div>
            </div>
          </div>
        </td>
        <td style="font-family:monospace;font-size:.76rem;color:#6ee7b7;"><?=htmlspecialchars($m['username'])?></td>
        <td><span class="badge <?=$role_badge?>"><?=ucfirst($m['role'])?></span></td>
        <td><span class="badge <?=$m['is_suspended']?'b-red':'b-green'?>"><span class="b-dot"></span><?=$m['is_suspended']?'Suspended':'Active'?></span></td>
        <td style="font-size:.72rem;color:rgba(255,255,255,.35);"><?=date('M d, Y',strtotime($m['created_at']))?></td>
        <td>
          <form method="POST" style="display:inline;">
            <input type="hidden" name="action" value="toggle_user">
            <input type="hidden" name="user_id" value="<?=$m['id']?>">
            <input type="hidden" name="is_suspended" value="<?=$m['is_suspended']?>">
            <button type="submit" class="btn-sm <?=$m['is_suspended']?'btn-success':'btn-danger'?>" style="font-size:.7rem;" onclick="return confirm('<?=$m['is_suspended']?'Unsuspend':'Suspend'?> <?=htmlspecialchars($m['fullname'])?>?')">
              <?=$m['is_suspended']?'Unsuspend':'Suspend'?>
            </button>
          </form>
        </td>
      </tr>
      <?php endforeach;?></tbody></table>
      <?php endif;?>
    </div>

  <?php elseif($active_page==='invite'): ?>
    <div class="page-hdr"><div><h2>Invite Team Member</h2><p>Send an invitation email to a new staff or cashier.</p></div></div>
    <div style="max-width:500px;">
      <div class="card">
        <form method="POST">
          <input type="hidden" name="action" value="invite_staff">
          <div class="card-title">New Invitation</div>
          <div class="fgroup">
            <label class="flabel">Role *</label>
            <select name="role" class="finput" required>
              <option value="staff">Staff</option>
              <option value="cashier">Cashier</option>
            </select>
          </div>
          <div class="fgroup">
            <label class="flabel">Full Name *</label>
            <input type="text" name="name" class="finput" placeholder="Maria Santos" required>
          </div>
          <div class="fgroup">
            <label class="flabel">Email Address *</label>
            <input type="email" name="email" class="finput" placeholder="staff@example.com" required>
            <div style="font-size:.71rem;color:rgba(255,255,255,.25);margin-top:5px;">A secure invitation link will be sent here.</div>
          </div>
          <div style="background:rgba(5,150,105,.08);border:1px solid rgba(5,150,105,.18);border-radius:10px;padding:11px 13px;font-size:.76rem;color:rgba(110,231,183,.8);margin-bottom:14px;line-height:1.6;">
            📧 They'll receive a link to set up their credentials. After registering, they'll be directed to the branch login page.
          </div>
          <div style="background:rgba(245,158,11,.07);border:1px solid rgba(245,158,11,.15);border-radius:10px;padding:11px 13px;font-size:.75rem;color:#fcd34d;margin-bottom:14px;">
            ⚠️ As Manager, you can only invite <strong>Staff</strong> and <strong>Cashier</strong> roles. To add another Manager, contact the Branch Owner (Admin).
          </div>
          <button type="submit" class="btn-sm btn-primary" style="width:100%;padding:11px;justify-content:center;font-size:.88rem;">
            <span class="material-symbols-outlined" style="font-size:16px;">send</span>Send Invitation
          </button>
        </form>
      </div>
    </div>

  <?php elseif($active_page==='audit'): ?>
    <div class="page-hdr"><div><h2>Audit Logs</h2><p>Activity logs for your branch team</p></div></div>
    <div class="card" style="overflow-x:auto;">
      <?php if(empty($audit_logs)):?>
        <div style="text-align:center;padding:40px 20px;color:rgba(255,255,255,.3);">
          <span class="material-symbols-outlined" style="font-size:3rem;display:block;margin-bottom:10px;">manage_search</span>
          <p>No audit logs yet.</p>
        </div>
      <?php else:?>
      <table>
        <thead><tr><th>Date</th><th>Actor</th><th>Role</th><th>Action</th><th>Ref #</th><th>Message</th></tr></thead>
        <tbody>
        <?php foreach($audit_logs as $a):
          $role_colors = ['manager'=>'background:rgba(139,92,246,.25);color:#c4b5fd;','staff'=>'background:rgba(16,185,129,.2);color:#6ee7b7;','cashier'=>'background:rgba(245,158,11,.2);color:#fcd34d;'];
          $rbadge = $role_colors[$a['actor_role']??''] ?? 'background:rgba(255,255,255,.1);color:rgba(255,255,255,.5);';
        ?>
        <tr>
          <td style="font-size:.72rem;color:rgba(255,255,255,.35);white-space:nowrap;"><?=date('M d, Y h:i A',strtotime($a['created_at']))?></td>
          <td style="font-weight:600;color:#fff;font-size:.78rem;"><?=htmlspecialchars(ucfirst($a['actor_username']??''))?></td>
          <td><span style="font-size:.62rem;font-weight:700;padding:2px 8px;border-radius:100px;text-transform:uppercase;letter-spacing:.05em;<?=$rbadge?>"><?=$a['actor_role']??''?></span></td>
          <td style="font-family:monospace;font-size:.72rem;color:#fcd34d;"><?=htmlspecialchars($a['action']??'')?></td>
          <td style="font-size:.72rem;color:rgba(255,255,255,.4);"><?=htmlspecialchars($a['entity_id']??'—')?></td>
          <td style="font-size:.75rem;color:rgba(255,255,255,.4);max-width:300px;"><?=htmlspecialchars($a['message']??'')?></td>
        </tr>
        <?php endforeach;?>
        </tbody>
      </table>
      <?php endif;?>
    </div>

  <?php endif;?>
  </div>
</div>

<!-- INVITE MODAL -->
<div class="modal-overlay" id="inviteModal">
  <div class="modal">
    <div class="mhdr">
      <div class="mtitle">Invite Staff / Cashier</div>
      <button class="mclose" onclick="document.getElementById('inviteModal').classList.remove('open')">
        <span class="material-symbols-outlined">close</span>
      </button>
    </div>
    <div class="mbody">
      <form method="POST">
        <input type="hidden" name="action" value="invite_staff">
        <div class="fgroup">
          <label class="flabel">Role *</label>
          <select name="role" class="finput" required>
            <option value="staff">Staff</option>
            <option value="cashier">Cashier</option>
          </select>
        </div>
        <div class="fgroup">
          <label class="flabel">Full Name *</label>
          <input type="text" name="name" class="finput" placeholder="Maria Santos" required>
        </div>
        <div class="fgroup">
          <label class="flabel">Email Address *</label>
          <input type="email" name="email" class="finput" placeholder="staff@example.com" required>
          <div style="font-size:.71rem;color:rgba(255,255,255,.25);margin-top:5px;">An invitation link will be sent to this email.</div>
        </div>
        <div style="background:rgba(5,150,105,.08);border:1px solid rgba(5,150,105,.18);border-radius:10px;padding:11px 13px;font-size:.76rem;color:rgba(110,231,183,.8);margin-bottom:14px;line-height:1.6;">
          📧 They will receive a secure link to create their account credentials.
        </div>
        <div style="display:flex;justify-content:flex-end;gap:9px;">
          <button type="button" class="btn-sm" onclick="document.getElementById('inviteModal').classList.remove('open')">Cancel</button>
          <button type="submit" class="btn-sm btn-primary">Send Invitation</button>
        </div>
      </form>
    </div>
  </div>
</div>

<style>
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.35}}
</style>
<script>
document.getElementById('inviteModal').addEventListener('click', function(e) {
  if (e.target === this) this.classList.remove('open');
});
</script>
</body>
</html>