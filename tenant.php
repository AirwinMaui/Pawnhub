<?php
session_start();
require 'db.php';
require 'theme_helper.php';
if (empty($_SESSION['user'])) { header('Location: login.php'); exit; }
$u = $_SESSION['user'];
if ($u['role'] !== 'admin') { header('Location: login.php'); exit; }

$tid         = $u['tenant_id'];
$active_page = $_GET['page'] ?? 'dashboard';
$success_msg = $error_msg = '';

// Load theme
$theme = getTenantTheme($pdo, $tid);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    // Invite Staff or Cashier via Email
    if ($_POST['action'] === 'invite_staff') {
        $email    = trim($_POST['email']  ?? '');
        $name     = trim($_POST['name']   ?? '');
        $role     = in_array($_POST['role'], ['staff','cashier']) ? $_POST['role'] : 'staff';

        if (!$email || !$name) {
            $error_msg = 'Please fill in name and email.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error_msg = 'Invalid email address.';
        } else {
            // Check if email already has an account in this tenant
            $chk = $pdo->prepare("SELECT id FROM users WHERE email=? AND tenant_id=?");
            $chk->execute([$email, $tid]);
            if ($chk->fetch()) {
                $error_msg = 'This email already has an account in your branch.';
            } else {
                // Cancel any pending invitations for same email+tenant
                $pdo->prepare("UPDATE tenant_invitations SET status='expired' WHERE email=? AND tenant_id=? AND status='pending' AND role IN ('staff','cashier')")
                    ->execute([$email, $tid]);

                // Create new invitation token
                $token      = bin2hex(random_bytes(32));
                $expires_at = date('Y-m-d H:i:s', strtotime('+24 hours'));

                $pdo->prepare("INSERT INTO tenant_invitations (tenant_id, email, owner_name, role, token, status, expires_at, created_by) VALUES (?,?,?,?,?,'pending',?,?)")
                    ->execute([$tid, $email, $name, $role, $token, $expires_at, $u['id']]);

                // Get tenant slug for login link
                $slug_row = $pdo->prepare("SELECT slug FROM tenants WHERE id=? LIMIT 1");
                $slug_row->execute([$tid]);
                $slug_row = $slug_row->fetch();
                $slug     = $slug_row['slug'] ?? '';

                // Send invitation email
                try {
                    require_once __DIR__ . '/mailer.php';
                    sendStaffInvitation($email, $name, $tenant['business_name'], $role, $token);
                    $success_msg = ucfirst($role) . " invitation sent to {$email}!";
                } catch (Throwable $e) {
                    error_log('Staff invite email failed: ' . $e->getMessage());
                    $error_msg = 'Invitation created but email failed to send. Check mailer config.';
                }
                $active_page = 'users';
            }
        }
    }

    // Toggle user
    if ($_POST['action'] === 'toggle_user') {
        $uid  = intval($_POST['user_id']);
        $susp = intval($_POST['is_suspended']);
        if ($susp) {
            $pdo->prepare("UPDATE users SET is_suspended=0,suspended_at=NULL,suspension_reason=NULL WHERE id=? AND tenant_id=?")->execute([$uid,$tid]);
            $success_msg = 'User unsuspended.';
        } else {
            $pdo->prepare("UPDATE users SET is_suspended=1,suspended_at=NOW(),suspension_reason='Suspended by admin.' WHERE id=? AND tenant_id=?")->execute([$uid,$tid]);
            $success_msg = 'User suspended.';
        }
        $active_page = 'users';
    }

    // Approve void request
    if ($_POST['action'] === 'approve_void') {
        $vrid = intval($_POST['void_id']); $ticket_no = trim($_POST['ticket_no']);
        $pdo->prepare("UPDATE pawn_void_requests SET status='approved',decided_by=?,decided_at=NOW() WHERE id=? AND tenant_id=?")->execute([$u['id'],$vrid,$tid]);
        $pdo->prepare("UPDATE pawn_transactions SET status='Voided' WHERE ticket_no=? AND tenant_id=?")->execute([$ticket_no,$tid]);
        $pdo->prepare("UPDATE item_inventory SET status='voided' WHERE ticket_no=? AND tenant_id=?")->execute([$ticket_no,$tid]);
        $success_msg = 'Void approved.'; $active_page = 'void_requests';
    }
    if ($_POST['action'] === 'reject_void') {
        $vrid = intval($_POST['void_id']);
        $pdo->prepare("UPDATE pawn_void_requests SET status='rejected',decided_by=?,decided_at=NOW() WHERE id=? AND tenant_id=?")->execute([$u['id'],$vrid,$tid]);
        $success_msg = 'Void rejected.'; $active_page = 'void_requests';
    }

    // Approve renewal
    if ($_POST['action'] === 'approve_renewal') {
        $rrid = intval($_POST['renewal_id']);
        $pdo->prepare("UPDATE renewal_requests SET verification_status='verified',verified_by_admin_id=?,verified_at=NOW() WHERE id=? AND tenant_id=?")->execute([$u['id'],$rrid,$tid]);
        $success_msg = 'Renewal approved.'; $active_page = 'renewals';
    }
    if ($_POST['action'] === 'reject_renewal') {
        $rrid = intval($_POST['renewal_id']);
        $pdo->prepare("UPDATE renewal_requests SET verification_status='rejected',verified_by_admin_id=?,verified_at=NOW() WHERE id=? AND tenant_id=?")->execute([$u['id'],$rrid,$tid]);
        $success_msg = 'Renewal rejected.'; $active_page = 'renewals';
    }

    // ── SAVE THEME SETTINGS ───────────────────────────────────
    if ($_POST['action'] === 'save_theme') {
        $primary   = preg_match('/^#[0-9a-fA-F]{6}$/', $_POST['primary_color']??'')   ? $_POST['primary_color']   : '#2563eb';
        $secondary = preg_match('/^#[0-9a-fA-F]{6}$/', $_POST['secondary_color']??'') ? $_POST['secondary_color'] : '#1e3a8a';
        $accent    = preg_match('/^#[0-9a-fA-F]{6}$/', $_POST['accent_color']??'')    ? $_POST['accent_color']    : '#10b981';
        $sidebar   = preg_match('/^#[0-9a-fA-F]{6}$/', $_POST['sidebar_color']??'')   ? $_POST['sidebar_color']   : '#0f172a';
        $sysname   = trim($_POST['system_name'] ?? 'PawnHub') ?: 'PawnHub';
        $logotext  = trim($_POST['logo_text']   ?? '');

        // Handle logo file upload
        $logourl = $theme['logo_url'] ?? ''; // keep existing if no new upload
        if (!empty($_FILES['logo_file']['name'])) {
            $allowed = ['image/jpeg','image/png','image/gif','image/webp','image/svg+xml'];
            $ftype   = mime_content_type($_FILES['logo_file']['tmp_name']);
            if (in_array($ftype, $allowed) && $_FILES['logo_file']['size'] <= 2*1024*1024) {
                $ext      = pathinfo($_FILES['logo_file']['name'], PATHINFO_EXTENSION);
                $filename = 'logo_' . $tid . '_' . time() . '.' . $ext;
                $uploaddir= __DIR__ . '/uploads/';
                if (!is_dir($uploaddir)) mkdir($uploaddir, 0755, true);
                // Delete old logo file if exists
                if ($logourl && strpos($logourl, '/uploads/') !== false) {
                    $oldfile = __DIR__ . parse_url($logourl, PHP_URL_PATH);
                    if (file_exists($oldfile)) unlink($oldfile);
                }
                if (move_uploaded_file($_FILES['logo_file']['tmp_name'], $uploaddir . $filename)) {
                    $logourl = 'uploads/' . $filename;
                } else {
                    $error_msg = 'Failed to upload logo. Please try again.';
                }
            } else {
                $error_msg = 'Invalid file. Please upload JPG, PNG, GIF, or WebP under 2MB.';
            }
        } elseif (isset($_POST['remove_logo']) && $_POST['remove_logo'] === '1') {
            // Remove logo
            if ($logourl && file_exists(__DIR__ . '/' . $logourl)) unlink(__DIR__ . '/' . $logourl);
            $logourl = '';
        }

        if (!$error_msg) {
            $pdo->prepare("INSERT INTO tenant_settings (tenant_id,primary_color,secondary_color,accent_color,sidebar_color,system_name,logo_text,logo_url)
                VALUES (?,?,?,?,?,?,?,?)
                ON DUPLICATE KEY UPDATE
                primary_color=VALUES(primary_color),
                secondary_color=VALUES(secondary_color),
                accent_color=VALUES(accent_color),
                sidebar_color=VALUES(sidebar_color),
                system_name=VALUES(system_name),
                logo_text=VALUES(logo_text),
                logo_url=VALUES(logo_url),
                updated_at=NOW()")
                ->execute([$tid,$primary,$secondary,$accent,$sidebar,$sysname,$logotext,$logourl]);

            $success_msg = '✅ Theme saved! Staff and cashier dashboards will now reflect the new design.';
            $theme = getTenantTheme($pdo, $tid);
        }
        $active_page = 'settings';
    }
}

// ── Fetch data ────────────────────────────────────────────────
$tenant       = $pdo->prepare("SELECT * FROM tenants WHERE id=?"); $tenant->execute([$tid]); $tenant=$tenant->fetch();
$my_users     = $pdo->prepare("SELECT * FROM users WHERE tenant_id=? AND role IN ('staff','cashier') ORDER BY role,fullname"); $my_users->execute([$tid]); $my_users=$my_users->fetchAll();
$tickets      = $pdo->prepare("SELECT * FROM pawn_transactions WHERE tenant_id=? ORDER BY created_at DESC LIMIT 100"); $tickets->execute([$tid]); $tickets=$tickets->fetchAll();
$customers    = $pdo->prepare("SELECT * FROM customers WHERE tenant_id=? ORDER BY full_name"); $customers->execute([$tid]); $customers=$customers->fetchAll();
$inventory    = $pdo->prepare("SELECT * FROM item_inventory WHERE tenant_id=? ORDER BY received_at DESC"); $inventory->execute([$tid]); $inventory=$inventory->fetchAll();
$void_reqs    = $pdo->prepare("SELECT v.*,u.fullname as req_name FROM pawn_void_requests v JOIN users u ON v.requested_by=u.id WHERE v.tenant_id=? ORDER BY v.requested_at DESC"); $void_reqs->execute([$tid]); $void_reqs=$void_reqs->fetchAll();
$renewals     = $pdo->prepare("SELECT * FROM renewal_requests WHERE tenant_id=? ORDER BY created_at DESC"); $renewals->execute([$tid]); $renewals=$renewals->fetchAll();
$audit        = $pdo->prepare("SELECT * FROM audit_logs WHERE tenant_id=? ORDER BY created_at DESC LIMIT 50"); $audit->execute([$tid]); $audit=$audit->fetchAll();

$pending_voids    = array_filter($void_reqs, fn($v)=>$v['status']==='pending');
$pending_renewals = array_filter($renewals,  fn($r)=>$r['verification_status']==='pending');
$total_tickets    = count($tickets);
$active_tickets   = count(array_filter($tickets, fn($t)=>$t['status']==='Stored'));
$total_customers  = count($customers);

$sys_name = $theme['system_name'] ?? 'PawnHub';
$logo_text = $theme['logo_text'] ?: $sys_name;
$logo_url  = $theme['logo_url']  ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title><?=htmlspecialchars($sys_name)?> — Admin</title>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<?= renderThemeCSS($theme) ?>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
:root{--sw:245px;--blue:#1e3a8a;--blue-acc:var(--t-primary,#2563eb);--bg:#f1f5f9;--card:#fff;--border:#e2e8f0;--text:#1e293b;--text-m:#475569;--text-dim:#94a3b8;--success:#16a34a;--danger:#dc2626;--warning:#d97706;}
body{font-family:'Plus Jakarta Sans',sans-serif;background:var(--bg);color:var(--text);display:flex;min-height:100vh;}
.sidebar{width:var(--sw);min-height:100vh;background:linear-gradient(175deg,var(--t-sidebar,#0f172a),var(--t-secondary,#1e3a8a));display:flex;flex-direction:column;position:fixed;left:0;top:0;bottom:0;z-index:100;overflow-y:auto;}
.sb-brand{padding:18px 16px;border-bottom:1px solid rgba(255,255,255,.08);display:flex;align-items:center;gap:9px;}
.sb-logo{width:36px;height:36px;background:linear-gradient(135deg,var(--t-primary,#3b82f6),var(--t-secondary,#8b5cf6));border-radius:9px;display:flex;align-items:center;justify-content:center;flex-shrink:0;overflow:hidden;}
.sb-logo img{width:100%;height:100%;object-fit:cover;}
.sb-logo svg{width:18px;height:18px;}
.sb-name{font-size:.9rem;font-weight:800;color:#fff;}
.sb-tenant{font-size:.65rem;color:rgba(255,255,255,.4);margin-top:1px;max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.sb-user{padding:11px 16px;border-bottom:1px solid rgba(255,255,255,.08);display:flex;align-items:center;gap:8px;}
.sb-avatar{width:30px;height:30px;border-radius:50%;background:linear-gradient(135deg,var(--t-primary,#3b82f6),var(--t-secondary,#8b5cf6));display:flex;align-items:center;justify-content:center;font-size:.74rem;font-weight:700;color:#fff;flex-shrink:0;}
.sb-uname{font-size:.78rem;font-weight:600;color:#fff;}
.sb-urole{font-size:.62rem;color:rgba(255,255,255,.4);}
.sb-nav{flex:1;padding:10px 0;}
.sb-section{font-size:.59rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:rgba(255,255,255,.25);padding:10px 14px 4px;}
.sb-item{display:flex;align-items:center;gap:9px;padding:8px 14px;margin:1px 7px;border-radius:8px;cursor:pointer;color:rgba(255,255,255,.55);font-size:.81rem;font-weight:500;text-decoration:none;transition:all .15s;}
.sb-item:hover{background:rgba(255,255,255,.09);color:#fff;}
.sb-item.active{background:rgba(255,255,255,.15);color:#fff;font-weight:600;}
.sb-item svg{width:15px;height:15px;flex-shrink:0;}
.sb-pill{margin-left:auto;background:#ef4444;color:#fff;font-size:.62rem;font-weight:700;padding:1px 6px;border-radius:100px;}
.sb-footer{padding:10px 13px;border-top:1px solid rgba(255,255,255,.08);}
.sb-logout{display:flex;align-items:center;gap:8px;font-size:.79rem;color:rgba(255,255,255,.35);text-decoration:none;padding:7px 8px;border-radius:8px;transition:all .15s;}
.sb-logout:hover{color:#f87171;background:rgba(239,68,68,.1);}
.sb-logout svg{width:14px;height:14px;}
.main{margin-left:var(--sw);flex:1;display:flex;flex-direction:column;}
.topbar{height:56px;padding:0 24px;background:#fff;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:50;}
.topbar-title{font-size:.98rem;font-weight:700;}
.tenant-chip{font-size:.7rem;font-weight:700;background:#eff6ff;color:var(--t-primary,#2563eb);padding:3px 10px;border-radius:100px;border:1px solid #bfdbfe;}
.content{padding:20px 24px;flex:1;}
.stats-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:13px;margin-bottom:20px;}
.stat-card{background:var(--card);border:1px solid var(--border);border-radius:12px;padding:15px 17px;display:flex;align-items:flex-start;gap:11px;}
.stat-icon{width:38px;height:38px;border-radius:10px;display:flex;align-items:center;justify-content:center;flex-shrink:0;}
.stat-icon svg{width:17px;height:17px;}
.stat-label{font-size:.7rem;font-weight:600;color:var(--text-dim);text-transform:uppercase;letter-spacing:.05em;margin-bottom:3px;}
.stat-value{font-size:1.45rem;font-weight:800;color:var(--text);line-height:1;}
.card{background:var(--card);border:1px solid var(--border);border-radius:12px;padding:17px;margin-bottom:15px;}
.card-hdr{display:flex;align-items:center;justify-content:space-between;margin-bottom:13px;flex-wrap:wrap;gap:8px;}
.card-title{font-size:.79rem;font-weight:700;text-transform:uppercase;letter-spacing:.04em;}
table{width:100%;border-collapse:collapse;}
th{font-size:.67rem;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:var(--text-dim);padding:7px 10px;text-align:left;border-bottom:1px solid var(--border);white-space:nowrap;}
td{padding:9px 10px;font-size:.8rem;border-bottom:1px solid #f1f5f9;vertical-align:middle;}
tr:last-child td{border-bottom:none;}
tr:hover td{background:#f8fafc;}
.badge{display:inline-flex;align-items:center;gap:3px;font-size:.66rem;font-weight:700;padding:2px 8px;border-radius:100px;}
.b-blue{background:#dbeafe;color:#1d4ed8;} .b-green{background:#dcfce7;color:#15803d;} .b-red{background:#fee2e2;color:#dc2626;} .b-yellow{background:#fef3c7;color:#b45309;} .b-purple{background:#f3e8ff;color:#7c3aed;} .b-gray{background:#f1f5f9;color:#475569;}
.b-dot{width:4px;height:4px;border-radius:50%;background:currentColor;}
.btn-sm{padding:4px 11px;border-radius:6px;font-size:.72rem;font-weight:600;cursor:pointer;border:1px solid var(--border);background:#fff;color:var(--text-m);text-decoration:none;display:inline-flex;align-items:center;gap:4px;transition:all .15s;margin-right:4px;}
.btn-sm:hover{background:var(--bg);}
.btn-primary{background:var(--t-primary,#2563eb);color:#fff;border-color:var(--t-primary,#2563eb);}
.btn-success{background:var(--success);color:#fff;border-color:var(--success);}
.btn-danger{background:var(--danger);color:#fff;border-color:var(--danger);}
.alert{padding:10px 14px;border-radius:9px;font-size:.81rem;margin-bottom:16px;display:flex;align-items:center;gap:8px;}
.alert-success{background:#f0fdf4;border:1px solid #bbf7d0;color:#15803d;}
.alert-error{background:#fef2f2;border:1px solid #fecaca;color:#dc2626;}
.empty-state{text-align:center;padding:38px 20px;color:var(--text-dim);}
.empty-state p{font-size:.82rem;}
.ticket-tag{font-family:monospace;font-size:.76rem;color:var(--t-primary,#2563eb);font-weight:700;}
/* Modal */
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(15,23,42,.55);z-index:999;align-items:center;justify-content:center;backdrop-filter:blur(3px);}
.modal-overlay.open{display:flex;}
.modal{background:#fff;border-radius:16px;width:480px;max-width:95vw;max-height:92vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.2);animation:mIn .25s ease both;}
@keyframes mIn{from{opacity:0;transform:translateY(14px)}to{opacity:1;transform:none}}
.mhdr{padding:18px 20px 0;display:flex;align-items:center;justify-content:space-between;}
.mtitle{font-size:.97rem;font-weight:800;}
.mclose{width:28px;height:28px;border-radius:7px;border:1.5px solid var(--border);background:#fff;cursor:pointer;display:flex;align-items:center;justify-content:center;color:var(--text-dim);}
.mclose svg{width:13px;height:13px;}
.mbody{padding:16px 20px 20px;}
.fg2{display:grid;grid-template-columns:1fr 1fr;gap:11px;margin-bottom:11px;}
.flabel{display:block;font-size:.73rem;font-weight:600;color:var(--text-m);margin-bottom:4px;}
.finput{width:100%;border:1.5px solid var(--border);border-radius:8px;padding:8px 11px;font-family:inherit;font-size:.84rem;color:var(--text);outline:none;background:#fff;transition:border .2s;}
.finput:focus{border-color:var(--t-primary,#2563eb);box-shadow:0 0 0 3px rgba(37,99,235,.1);}
.finput::placeholder{color:#c8d0db;}
/* Theme Settings */
.theme-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:16px;}
.color-picker-wrap{display:flex;align-items:center;gap:10px;}
.color-picker-wrap input[type=color]{width:44px;height:36px;border:1.5px solid var(--border);border-radius:8px;padding:2px;cursor:pointer;background:#fff;}
.color-preview{width:100%;height:36px;border-radius:8px;border:1.5px solid var(--border);display:flex;align-items:center;justify-content:center;font-size:.72rem;font-weight:600;color:#fff;text-shadow:0 1px 2px rgba(0,0,0,.3);}
.preset-row{display:flex;gap:8px;flex-wrap:wrap;margin-top:6px;}
.preset{width:28px;height:28px;border-radius:50%;cursor:pointer;border:2px solid transparent;transition:all .15s;}
.preset:hover,.preset.active{border-color:#fff;box-shadow:0 0 0 2px var(--t-primary,#2563eb);}
.theme-preview{background:linear-gradient(135deg,var(--t-sidebar,#0f172a),var(--t-secondary,#1e3a8a));border-radius:12px;padding:16px;color:#fff;}
@media(max-width:1200px){.stats-grid{grid-template-columns:repeat(2,1fr);}.theme-grid{grid-template-columns:1fr;}}
@media(max-width:540px){.fg2{grid-template-columns:1fr;}}
</style>
</head>
<body>
<aside class="sidebar">
  <div class="sb-brand">
    <div class="sb-logo">
      <?php if($logo_url): ?>
        <img src="<?=htmlspecialchars($logo_url)?>" alt="logo">
      <?php else: ?>
        <svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2.5"><rect x="3" y="9" width="18" height="12"/><polyline points="3 9 12 3 21 9"/></svg>
      <?php endif; ?>
    </div>
    <div>
      <div class="sb-name"><?=htmlspecialchars($logo_text)?></div>
      <div class="sb-tenant"><?=htmlspecialchars($tenant['business_name']??'My Branch')?></div>
    </div>
  </div>
  <div class="sb-user">
    <div class="sb-avatar"><?=strtoupper(substr($u['name'],0,1))?></div>
    <div><div class="sb-uname"><?=htmlspecialchars(explode(' ',$u['name'])[0]??$u['name'])?></div><div class="sb-urole">Branch Admin</div></div>
  </div>
  <nav class="sb-nav">
    <div class="sb-section">Overview</div>
    <a href="?page=dashboard"     class="sb-item <?=$active_page==='dashboard'?'active':''?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>Dashboard</a>
    <div class="sb-section">Branch Records (View Only)</div>
    <a href="?page=tickets"       class="sb-item <?=$active_page==='tickets'?'active':''?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/></svg>Pawn Tickets</a>
    <a href="?page=customers"     class="sb-item <?=$active_page==='customers'?'active':''?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>Customers</a>
    <a href="?page=inventory"     class="sb-item <?=$active_page==='inventory'?'active':''?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/></svg>Inventory</a>
    <div class="sb-section">Approvals</div>
    <a href="?page=void_requests" class="sb-item <?=$active_page==='void_requests'?'active':''?>">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/></svg>Void Requests
      <?php if(count($pending_voids)>0):?><span class="sb-pill"><?=count($pending_voids)?></span><?php endif;?>
    </a>
    <a href="?page=renewals"      class="sb-item <?=$active_page==='renewals'?'active':''?>">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>Renewals
      <?php if(count($pending_renewals)>0):?><span class="sb-pill"><?=count($pending_renewals)?></span><?php endif;?>
    </a>
    <div class="sb-section">Team</div>
    <a href="?page=users"         class="sb-item <?=$active_page==='users'?'active':''?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>Staff & Cashier</a>
    <a href="?page=audit"         class="sb-item <?=$active_page==='audit'?'active':''?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/></svg>Audit Logs</a>
    <div class="sb-section">Customize</div>
    <a href="?page=settings"      class="sb-item <?=$active_page==='settings'?'active':''?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>Theme & Branding</a>
  </nav>
  <div class="sb-footer"><a href="logout.php" class="sb-logout"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>Sign Out</a></div>
</aside>

<div class="main">
  <header class="topbar">
    <div style="display:flex;align-items:center;gap:9px;">
      <span class="topbar-title"><?php $titles=['dashboard'=>'Dashboard','tickets'=>'Pawn Tickets','customers'=>'Customers','inventory'=>'Inventory','void_requests'=>'Void Requests','renewals'=>'Renewal Requests','users'=>'Staff & Cashier','audit'=>'Audit Logs','settings'=>'Theme & Branding'];echo $titles[$active_page]??'Dashboard';?></span>
      <span class="tenant-chip"><?=htmlspecialchars($tenant['business_name']??'Branch')?></span>
    </div>
    <?php if($active_page==='users'):?>
    <button onclick="document.getElementById('addUserModal').classList.add('open')" class="btn-sm btn-primary" style="font-size:.78rem;padding:6px 13px;">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="width:13px;height:13px;"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>Invite Staff / Cashier
    </button>
    <?php endif;?>
  </header>

  <div class="content">
  <?php if($success_msg):?><div class="alert alert-success"><?=htmlspecialchars($success_msg)?></div><?php endif;?>
  <?php if($error_msg):?><div class="alert alert-error">⚠ <?=htmlspecialchars($error_msg)?></div><?php endif;?>

  <?php if($active_page==='dashboard'): ?>
    <div class="stats-grid">
      <div class="stat-card"><div class="stat-icon" style="background:#dbeafe;"><svg viewBox="0 0 24 24" fill="none" stroke="#2563eb" stroke-width="2"><path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/></svg></div><div><div class="stat-label">Total Tickets</div><div class="stat-value"><?=$total_tickets?></div><div style="font-size:.71rem;color:var(--text-dim);margin-top:2px;"><?=$active_tickets?> active</div></div></div>
      <div class="stat-card"><div class="stat-icon" style="background:#dcfce7;"><svg viewBox="0 0 24 24" fill="none" stroke="#16a34a" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg></div><div><div class="stat-label">Revenue</div><div class="stat-value">₱<?=number_format($total_revenue,0)?></div></div></div>
      <div class="stat-card"><div class="stat-icon" style="background:#f3e8ff;"><svg viewBox="0 0 24 24" fill="none" stroke="#7c3aed" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg></div><div><div class="stat-label">Customers</div><div class="stat-value"><?=$total_customers?></div></div></div>
      <div class="stat-card"><div class="stat-icon" style="background:#fef3c7;"><svg viewBox="0 0 24 24" fill="none" stroke="#d97706" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg></div><div><div class="stat-label">Team Members</div><div class="stat-value"><?=count($my_users)?></div></div></div>
    </div>
    <?php if(count($pending_voids)||count($pending_renewals)): ?>
    <div class="card">
      <div class="card-hdr"><span class="card-title">⚠️ Needs Attention</span></div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
        <a href="?page=void_requests" style="background:#fef3c7;border:1px solid #fde68a;border-radius:10px;padding:14px;text-decoration:none;text-align:center;"><div style="font-size:1.4rem;font-weight:800;color:#b45309;"><?=count($pending_voids)?></div><div style="font-size:.77rem;color:#b45309;font-weight:600;">Pending Void Requests</div></a>
        <a href="?page=renewals" style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:10px;padding:14px;text-decoration:none;text-align:center;"><div style="font-size:1.4rem;font-weight:800;color:#2563eb;"><?=count($pending_renewals)?></div><div style="font-size:.77rem;color:#2563eb;font-weight:600;">Pending Renewals</div></a>
      </div>
    </div>
    <?php endif;?>
    <div class="card">
      <div class="card-hdr"><span class="card-title">Recent Tickets</span><a href="?page=tickets" style="font-size:.74rem;color:var(--t-primary,#2563eb);font-weight:600;text-decoration:none;">View All</a></div>
      <?php if(empty($tickets)):?><div class="empty-state"><p>No tickets yet.</p></div>
      <?php else:?><div style="overflow-x:auto;"><table><thead><tr><th>Ticket</th><th>Customer</th><th>Item</th><th>Loan</th><th>Status</th><th>Date</th></tr></thead><tbody>
      <?php foreach(array_slice($tickets,0,8) as $t): $sc=['Stored'=>'b-blue','Released'=>'b-green','Renewed'=>'b-yellow','Voided'=>'b-red','Auctioned'=>'b-purple'];?>
      <tr><td><span class="ticket-tag"><?=htmlspecialchars($t['ticket_no'])?></span></td><td style="font-weight:600;"><?=htmlspecialchars($t['customer_name'])?></td><td><?=htmlspecialchars($t['item_category'])?></td><td>₱<?=number_format($t['loan_amount'],2)?></td><td><span class="badge <?=$sc[$t['status']]??'b-gray'?>"><?=$t['status']?></span></td><td style="font-size:.74rem;color:var(--text-dim);"><?=date('M d, Y',strtotime($t['created_at']))?></td></tr>
      <?php endforeach;?></tbody></table></div><?php endif;?>
    </div>

  <?php elseif($active_page==='tickets'): ?>
    <div class="card" style="overflow-x:auto;">
      <?php if(empty($tickets)):?><div class="empty-state"><p>No pawn tickets.</p></div>
      <?php else:?><table><thead><tr><th>Ticket No.</th><th>Customer</th><th>Contact</th><th>Item</th><th>Loan</th><th>Total Redeem</th><th>Maturity</th><th>Expiry</th><th>Status</th></tr></thead><tbody>
      <?php foreach($tickets as $t): $sc=['Stored'=>'b-blue','Released'=>'b-green','Renewed'=>'b-yellow','Voided'=>'b-red','Auctioned'=>'b-purple'];?>
      <tr><td><span class="ticket-tag"><?=htmlspecialchars($t['ticket_no'])?></span></td><td style="font-weight:600;"><?=htmlspecialchars($t['customer_name'])?></td><td style="font-family:monospace;font-size:.76rem;"><?=htmlspecialchars($t['contact_number'])?></td><td><?=htmlspecialchars($t['item_category'])?></td><td>₱<?=number_format($t['loan_amount'],2)?></td><td style="font-weight:700;">₱<?=number_format($t['total_redeem'],2)?></td><td style="font-size:.74rem;color:<?=strtotime($t['maturity_date'])<time()&&$t['status']==='Stored'?'var(--danger)':'var(--text-dim)'?>;"><?=$t['maturity_date']?></td><td style="font-size:.74rem;color:var(--text-dim);"><?=$t['expiry_date']?></td><td><span class="badge <?=$sc[$t['status']]??'b-gray'?>"><?=$t['status']?></span></td></tr>
      <?php endforeach;?></tbody></table><?php endif;?>
    </div>

  <?php elseif($active_page==='customers'): ?>
    <div class="card" style="overflow-x:auto;">
      <?php if(empty($customers)):?><div class="empty-state"><p>No customers yet.</p></div>
      <?php else:?><table><thead><tr><th>Name</th><th>Contact</th><th>Email</th><th>Gender</th><th>ID Type</th><th>Registered</th></tr></thead><tbody>
      <?php foreach($customers as $c):?>
      <tr><td style="font-weight:600;"><?=htmlspecialchars($c['full_name'])?></td><td style="font-family:monospace;font-size:.76rem;"><?=htmlspecialchars($c['contact_number'])?></td><td style="font-size:.76rem;color:var(--text-dim);"><?=htmlspecialchars($c['email']??'—')?></td><td><?=$c['gender']?></td><td><?=htmlspecialchars($c['valid_id_type']??'—')?></td><td style="font-size:.74rem;color:var(--text-dim);"><?=date('M d, Y',strtotime($c['registered_at']))?></td></tr>
      <?php endforeach;?></tbody></table><?php endif;?>
    </div>

  <?php elseif($active_page==='inventory'): ?>
    <div class="card" style="overflow-x:auto;">
      <?php if(empty($inventory)):?><div class="empty-state"><p>No inventory items.</p></div>
      <?php else:?><table><thead><tr><th>Ticket</th><th>Item</th><th>Category</th><th>Appraisal</th><th>Loan</th><th>Status</th><th>Received</th></tr></thead><tbody>
      <?php foreach($inventory as $i): $sc=['pawned'=>'b-blue','redeemed'=>'b-green','voided'=>'b-red','auctioned'=>'b-purple','sold'=>'b-yellow'];?>
      <tr><td><span class="ticket-tag"><?=htmlspecialchars($i['ticket_no'])?></span></td><td><?=htmlspecialchars($i['item_name']??'—')?></td><td><?=htmlspecialchars($i['item_category']??'—')?></td><td>₱<?=number_format($i['appraisal_value']??0,2)?></td><td>₱<?=number_format($i['loan_amount']??0,2)?></td><td><span class="badge <?=$sc[$i['status']]??'b-gray'?>"><?=ucfirst($i['status'])?></span></td><td style="font-size:.73rem;color:var(--text-dim);"><?=date('M d, Y',strtotime($i['received_at']))?></td></tr>
      <?php endforeach;?></tbody></table><?php endif;?>
    </div>

  <?php elseif($active_page==='void_requests'): ?>
    <div class="card" style="overflow-x:auto;">
      <?php if(empty($void_reqs)):?><div class="empty-state"><p>No void requests.</p></div>
      <?php else:?><table><thead><tr><th>Ticket</th><th>Requested By</th><th>Reason</th><th>Status</th><th>Requested</th><th>Actions</th></tr></thead><tbody>
      <?php foreach($void_reqs as $v):?>
      <tr><td><span class="ticket-tag"><?=htmlspecialchars($v['ticket_no'])?></span></td><td style="font-weight:600;"><?=htmlspecialchars($v['req_name'])?></td><td style="max-width:180px;font-size:.77rem;"><?=htmlspecialchars($v['reason'])?></td><td><span class="badge <?=$v['status']==='approved'?'b-green':($v['status']==='pending'?'b-yellow':'b-red')?>"><?=ucfirst($v['status'])?></span></td><td style="font-size:.73rem;color:var(--text-dim);"><?=date('M d, Y h:i A',strtotime($v['requested_at']))?></td>
      <td><?php if($v['status']==='pending'):?>
        <form method="POST" style="display:inline;"><input type="hidden" name="action" value="approve_void"><input type="hidden" name="void_id" value="<?=$v['id']?>"><input type="hidden" name="ticket_no" value="<?=htmlspecialchars($v['ticket_no'])?>"><button type="submit" class="btn-sm btn-success" onclick="return confirm('Approve void?')">Approve</button></form>
        <form method="POST" style="display:inline;"><input type="hidden" name="action" value="reject_void"><input type="hidden" name="void_id" value="<?=$v['id']?>"><button type="submit" class="btn-sm btn-danger" onclick="return confirm('Reject?')">Reject</button></form>
      <?php else:?>—<?php endif;?></td></tr>
      <?php endforeach;?></tbody></table><?php endif;?>
    </div>

  <?php elseif($active_page==='renewals'): ?>
    <div class="card" style="overflow-x:auto;">
      <?php if(empty($renewals)):?><div class="empty-state"><p>No renewal requests.</p></div>
      <?php else:?><table><thead><tr><th>Old Ticket</th><th>New Ticket</th><th>Customer</th><th>Channel</th><th>Payment</th><th>Verification</th><th>Date</th><th>Actions</th></tr></thead><tbody>
      <?php foreach($renewals as $r):?>
      <tr><td><span class="ticket-tag"><?=htmlspecialchars($r['old_ticket_no'])?></span></td><td><?=$r['new_ticket_no']?'<span class="ticket-tag">'.htmlspecialchars($r['new_ticket_no']).'</span>':'—'?></td><td style="font-weight:600;"><?=htmlspecialchars($r['customer_name']??'—')?></td><td><span class="badge <?=$r['channel']==='online'?'b-blue':'b-gray'?>"><?=ucfirst($r['channel'])?></span></td><td><span class="badge <?=$r['payment_status']==='paid'?'b-green':'b-yellow'?>"><?=ucfirst($r['payment_status'])?></span></td><td><span class="badge <?=$r['verification_status']==='verified'?'b-green':($r['verification_status']==='pending'?'b-yellow':'b-red')?>"><?=ucfirst($r['verification_status'])?></span></td><td style="font-size:.73rem;color:var(--text-dim);"><?=date('M d, Y',strtotime($r['created_at']))?></td>
      <td><?php if($r['verification_status']==='pending'):?>
        <form method="POST" style="display:inline;"><input type="hidden" name="action" value="approve_renewal"><input type="hidden" name="renewal_id" value="<?=$r['id']?>"><button type="submit" class="btn-sm btn-success" onclick="return confirm('Approve renewal?')">Approve</button></form>
        <form method="POST" style="display:inline;"><input type="hidden" name="action" value="reject_renewal"><input type="hidden" name="renewal_id" value="<?=$r['id']?>"><button type="submit" class="btn-sm btn-danger" onclick="return confirm('Reject?')">Reject</button></form>
      <?php else:?>—<?php endif;?></td></tr>
      <?php endforeach;?></tbody></table><?php endif;?>
    </div>

  <?php elseif($active_page==='users'): ?>
    <div class="card" style="overflow-x:auto;">
      <?php if(empty($my_users)):?><div class="empty-state"><p>No staff or cashiers yet.</p></div>
      <?php else:?><table><thead><tr><th>Name</th><th>Username</th><th>Role</th><th>Status</th><th>Added</th><th>Actions</th></tr></thead><tbody>
      <?php foreach($my_users as $usr):?>
      <tr>
        <td><div style="display:flex;align-items:center;gap:8px;"><div style="width:26px;height:26px;border-radius:50%;background:<?=$usr['role']==='cashier'?'#7c3aed':'var(--t-primary,#2563eb)'?>;display:flex;align-items:center;justify-content:center;font-size:.68rem;font-weight:700;color:#fff;"><?=strtoupper(substr($usr['fullname'],0,1))?></div><span style="font-weight:600;"><?=htmlspecialchars($usr['fullname'])?></span></div></td>
        <td style="font-family:monospace;font-size:.77rem;color:var(--t-primary,#2563eb);"><?=htmlspecialchars($usr['username'])?></td>
        <td><span class="badge <?=$usr['role']==='cashier'?'b-purple':'b-blue'?>"><?=ucfirst($usr['role'])?></span></td>
        <td><span class="badge <?=$usr['is_suspended']?'b-red':'b-green'?>"><span class="b-dot"></span><?=$usr['is_suspended']?'Suspended':'Active'?></span></td>
        <td style="font-size:.73rem;color:var(--text-dim);"><?=date('M d, Y',strtotime($usr['created_at']))?></td>
        <td><form method="POST" style="display:inline;"><input type="hidden" name="action" value="toggle_user"><input type="hidden" name="user_id" value="<?=$usr['id']?>"><input type="hidden" name="is_suspended" value="<?=$usr['is_suspended']?>"><button type="submit" class="btn-sm <?=$usr['is_suspended']?'btn-success':'btn-danger'?>" style="font-size:.7rem;" onclick="return confirm('<?=$usr['is_suspended']?'Unsuspend':'Suspend'?> this user?')"><?=$usr['is_suspended']?'Unsuspend':'Suspend'?></button></form></td>
      </tr>
      <?php endforeach;?></tbody></table><?php endif;?>
    </div>

  <?php elseif($active_page==='audit'): ?>
    <div class="card" style="overflow-x:auto;">
      <?php if(empty($audit)):?><div class="empty-state"><p>No audit logs.</p></div>
      <?php else:?><table><thead><tr><th>Date</th><th>Actor</th><th>Role</th><th>Action</th><th>Ticket</th><th>Message</th></tr></thead><tbody>
      <?php foreach($audit as $a):?>
      <tr><td style="font-size:.73rem;color:var(--text-dim);white-space:nowrap;"><?=date('M d, Y h:i A',strtotime($a['created_at']))?></td><td style="font-weight:600;font-size:.78rem;"><?=htmlspecialchars($a['actor_username']??'')?></td><td><span class="badge b-blue" style="font-size:.63rem;"><?=$a['actor_role']??''?></span></td><td style="font-family:monospace;font-size:.73rem;color:var(--warning);"><?=htmlspecialchars($a['action']??'')?></td><td><span class="ticket-tag" style="font-size:.73rem;"><?=htmlspecialchars($a['entity_id']??'—')?></span></td><td style="font-size:.76rem;color:var(--text-dim);"><?=htmlspecialchars($a['message']??'')?></td></tr>
      <?php endforeach;?></tbody></table><?php endif;?>
    </div>

  <?php elseif($active_page==='settings'): ?>
    <!-- ── THEME & BRANDING SETTINGS ── -->
    <form method="POST" enctype="multipart/form-data">
      <input type="hidden" name="action" value="save_theme">
      <div class="theme-grid">

        <!-- Left: Colors -->
        <div>
          <div class="card" style="margin-bottom:14px;">
            <div class="card-hdr"><span class="card-title">🎨 Color Scheme</span></div>

            <!-- Presets -->
            <div style="margin-bottom:18px;">
              <div class="flabel" style="margin-bottom:8px;">Quick Presets</div>
              <div class="preset-row">
                <div class="preset" style="background:#2563eb;" onclick="applyPreset('#2563eb','#1e3a8a','#10b981','#0f172a')" title="Blue (Default)"></div>
                <div class="preset" style="background:#7c3aed;" onclick="applyPreset('#7c3aed','#4c1d95','#f59e0b','#1a0533')" title="Purple"></div>
                <div class="preset" style="background:#059669;" onclick="applyPreset('#059669','#064e3b','#3b82f6','#022c22')" title="Green"></div>
                <div class="preset" style="background:#dc2626;" onclick="applyPreset('#dc2626','#7f1d1d','#f59e0b','#1c0a0a')" title="Red"></div>
                <div class="preset" style="background:#d97706;" onclick="applyPreset('#d97706','#78350f','#2563eb','#1c1207')" title="Amber"></div>
                <div class="preset" style="background:#0891b2;" onclick="applyPreset('#0891b2','#164e63','#10b981','#061a20')" title="Cyan"></div>
                <div class="preset" style="background:#be185d;" onclick="applyPreset('#be185d','#500724','#f59e0b','#200010')" title="Pink"></div>
                <div class="preset" style="background:#374151;" onclick="applyPreset('#374151','#111827','#6ee7b7','#030712')" title="Dark"></div>
              </div>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:13px;">
              <div>
                <label class="flabel">Primary Color</label>
                <div class="color-picker-wrap">
                  <input type="color" name="primary_color" id="cp_primary" value="<?=htmlspecialchars($theme['primary_color']??'#2563eb')?>" oninput="updatePreview()">
                  <div class="color-preview" id="prev_primary" style="background:<?=htmlspecialchars($theme['primary_color']??'#2563eb')?>;">Primary</div>
                </div>
              </div>
              <div>
                <label class="flabel">Secondary Color</label>
                <div class="color-picker-wrap">
                  <input type="color" name="secondary_color" id="cp_secondary" value="<?=htmlspecialchars($theme['secondary_color']??'#1e3a8a')?>" oninput="updatePreview()">
                  <div class="color-preview" id="prev_secondary" style="background:<?=htmlspecialchars($theme['secondary_color']??'#1e3a8a')?>;">Secondary</div>
                </div>
              </div>
              <div>
                <label class="flabel">Accent Color</label>
                <div class="color-picker-wrap">
                  <input type="color" name="accent_color" id="cp_accent" value="<?=htmlspecialchars($theme['accent_color']??'#10b981')?>" oninput="updatePreview()">
                  <div class="color-preview" id="prev_accent" style="background:<?=htmlspecialchars($theme['accent_color']??'#10b981')?>;">Accent</div>
                </div>
              </div>
              <div>
                <label class="flabel">Sidebar Color</label>
                <div class="color-picker-wrap">
                  <input type="color" name="sidebar_color" id="cp_sidebar" value="<?=htmlspecialchars($theme['sidebar_color']??'#0f172a')?>" oninput="updatePreview()">
                  <div class="color-preview" id="prev_sidebar" style="background:<?=htmlspecialchars($theme['sidebar_color']??'#0f172a')?>;">Sidebar</div>
                </div>
              </div>
            </div>
          </div>

          <div class="card">
            <div class="card-hdr"><span class="card-title">🏷️ Branding</span></div>
            <div style="margin-bottom:11px;">
              <label class="flabel">System Name (shown in title & browser tab)</label>
              <input type="text" name="system_name" class="finput" placeholder="PawnHub" value="<?=htmlspecialchars($theme['system_name']??'PawnHub')?>">
            </div>
            <div style="margin-bottom:11px;">
              <label class="flabel">Logo Text (shown in sidebar)</label>
              <input type="text" name="logo_text" class="finput" placeholder="e.g. GoldKing" value="<?=htmlspecialchars($theme['logo_text']??'')?>">
            </div>
            <div style="margin-bottom:11px;">
              <label class="flabel">Logo Image</label>
              <?php if($logo_url): ?>
              <div style="display:flex;align-items:center;gap:10px;margin-bottom:8px;background:#f8fafc;border:1.5px solid var(--border);border-radius:9px;padding:10px 12px;">
                <img src="<?=htmlspecialchars($logo_url)?>" style="width:40px;height:40px;object-fit:cover;border-radius:8px;border:1px solid var(--border);">
                <div style="flex:1;">
                  <div style="font-size:.76rem;font-weight:600;color:var(--text-m);">Current Logo</div>
                  <div style="font-size:.69rem;color:var(--text-dim);">Upload a new one to replace</div>
                </div>
                <label style="display:inline-flex;align-items:center;gap:4px;cursor:pointer;font-size:.7rem;color:#dc2626;font-weight:600;">
                  <input type="checkbox" name="remove_logo" value="1" style="margin:0;"> Remove
                </label>
              </div>
              <?php endif; ?>
              <div id="logo-drop-zone" style="border:2px dashed var(--border);border-radius:10px;padding:20px;text-align:center;cursor:pointer;transition:all .2s;background:#fafbfc;" onclick="document.getElementById('logo_file_input').click()" ondragover="event.preventDefault();this.style.borderColor='var(--t-primary,#2563eb)';this.style.background='#eff6ff'" ondragleave="this.style.borderColor='var(--border)';this.style.background='#fafbfc'" ondrop="handleLogoDrop(event)">
                <div id="logo-preview-wrap" style="display:none;margin-bottom:10px;">
                  <img id="logo-preview-img" style="width:60px;height:60px;object-fit:cover;border-radius:10px;border:1px solid var(--border);margin:0 auto;display:block;">
                </div>
                <svg viewBox="0 0 24 24" fill="none" stroke="#94a3b8" stroke-width="1.5" style="width:28px;height:28px;margin:0 auto 8px;display:block;"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                <div style="font-size:.8rem;font-weight:600;color:var(--text-m);margin-bottom:3px;">Click to upload or drag & drop</div>
                <div style="font-size:.71rem;color:var(--text-dim);">PNG, JPG, WebP, SVG · Max 2MB</div>
                <input type="file" id="logo_file_input" name="logo_file" accept="image/*" style="display:none;" onchange="previewLogo(this)">
              </div>
            </div>
          </div>
        </div>

        <!-- Right: Preview -->
        <div>
          <div class="card" style="margin-bottom:14px;">
            <div class="card-hdr"><span class="card-title">👁️ Live Preview</span></div>
            <div id="theme-preview-box" style="border-radius:12px;overflow:hidden;border:1px solid var(--border);">
              <!-- Sidebar preview -->
              <div id="prev_sidebar_box" style="background:linear-gradient(135deg,<?=htmlspecialchars($theme['sidebar_color']??'#0f172a')?>,<?=htmlspecialchars($theme['secondary_color']??'#1e3a8a')?>);padding:14px 16px;">
                <div style="display:flex;align-items:center;gap:9px;margin-bottom:12px;">
                  <div id="prev_logo_box" style="width:30px;height:30px;border-radius:7px;background:linear-gradient(135deg,<?=htmlspecialchars($theme['primary_color']??'#3b82f6')?>,<?=htmlspecialchars($theme['secondary_color']??'#8b5cf6')?>);display:flex;align-items:center;justify-content:center;">
                    <svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2.5" style="width:14px;height:14px;"><rect x="3" y="9" width="18" height="12"/><polyline points="3 9 12 3 21 9"/></svg>
                  </div>
                  <div>
                    <div style="font-size:.82rem;font-weight:800;color:#fff;" id="prev_sysname"><?=htmlspecialchars($theme['system_name']??'PawnHub')?></div>
                    <div style="font-size:.62rem;color:rgba(255,255,255,.4);"><?=htmlspecialchars($tenant['business_name']??'My Branch')?></div>
                  </div>
                </div>
                <div style="display:flex;flex-direction:column;gap:4px;">
                  <div id="prev_active_item" style="display:flex;align-items:center;gap:7px;padding:7px 10px;border-radius:7px;background:rgba(255,255,255,.15);color:#fff;font-size:.78rem;font-weight:600;">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:13px;height:13px;"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/></svg>Dashboard
                  </div>
                  <div style="display:flex;align-items:center;gap:7px;padding:7px 10px;border-radius:7px;color:rgba(255,255,255,.5);font-size:.78rem;">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:13px;height:13px;"><path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/></svg>Pawn Tickets
                  </div>
                </div>
              </div>
              <!-- Main preview -->
              <div style="padding:12px;background:#f1f5f9;">
                <div id="prev_btn" style="display:inline-flex;align-items:center;gap:5px;padding:6px 12px;border-radius:7px;background:<?=htmlspecialchars($theme['primary_color']??'#2563eb')?>;color:#fff;font-size:.76rem;font-weight:700;">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="width:11px;height:11px;"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                  Add Staff / Cashier
                </div>
                <div style="margin-top:10px;background:#fff;border-radius:8px;padding:10px 12px;border:1px solid #e2e8f0;">
                  <div style="font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#94a3b8;margin-bottom:6px;">Sample Ticket</div>
                  <div style="display:flex;align-items:center;gap:8px;">
                    <span style="font-family:monospace;font-size:.75rem;font-weight:700;color:<?=htmlspecialchars($theme['primary_color']??'#2563eb')?>" id="prev_ticket_tag">TP-20240314-AB1C</span>
                    <span style="font-size:.68rem;background:#dbeafe;color:#1d4ed8;padding:1px 7px;border-radius:100px;font-weight:700;">Stored</span>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:10px;padding:13px 16px;font-size:.78rem;color:#1d4ed8;margin-bottom:14px;line-height:1.7;">
            ℹ️ <strong>How it works:</strong><br>
            When you save, your Staff and Cashier dashboards will automatically use these colors and branding. No need to edit their files!
          </div>

          <button type="submit" style="width:100%;background:linear-gradient(135deg,var(--t-primary,#2563eb),var(--t-secondary,#1d4ed8));color:#fff;border:none;border-radius:10px;padding:13px;font-family:inherit;font-size:.94rem;font-weight:700;cursor:pointer;box-shadow:0 4px 14px rgba(37,99,235,.25);">
            💾 Save Theme & Branding
          </button>
        </div>
      </div>
    </form>
  <?php endif;?>
  </div>
</div>

<!-- INVITE STAFF/CASHIER MODAL -->
<div class="modal-overlay" id="addUserModal">
  <div class="modal">
    <div class="mhdr">
      <div class="mtitle">Invite Staff / Cashier</div>
      <button class="mclose" onclick="document.getElementById('addUserModal').classList.remove('open')">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
      </button>
    </div>
    <div class="mbody">
      <form method="POST">
        <input type="hidden" name="action" value="invite_staff">

        <div style="margin-bottom:11px;">
          <label class="flabel">Role *</label>
          <select name="role" class="finput" required>
            <option value="staff">Staff</option>
            <option value="cashier">Cashier</option>
          </select>
        </div>

        <div style="margin-bottom:11px;">
          <label class="flabel">Full Name *</label>
          <input type="text" name="name" class="finput" placeholder="Maria Santos" required>
        </div>

        <div style="margin-bottom:14px;">
          <label class="flabel">Email Address *</label>
          <input type="email" name="email" class="finput" placeholder="staff@example.com" required>
          <div style="font-size:.72rem;color:#94a3b8;margin-top:4px;">An invitation link will be sent to this email.</div>
        </div>

        <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:10px 12px;font-size:.76rem;color:#1d4ed8;margin-bottom:14px;line-height:1.6;">
          📧 <strong>How it works:</strong><br>
          The staff/cashier will receive an email with a link to set up their own username and password. After registering, they'll also get their branch login page link.
        </div>

        <div style="display:flex;justify-content:flex-end;gap:9px;">
          <button type="button" class="btn-sm" onclick="document.getElementById('addUserModal').classList.remove('open')">Cancel</button>
          <button type="submit" class="btn-sm btn-primary">Send Invitation</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
document.getElementById('addUserModal').addEventListener('click',function(e){if(e.target===this)this.classList.remove('open');});

function updatePreview() {
  const p  = document.getElementById('cp_primary').value;
  const s  = document.getElementById('cp_secondary').value;
  const a  = document.getElementById('cp_accent').value;
  const sb = document.getElementById('cp_sidebar').value;

  document.getElementById('prev_primary').style.background   = p;
  document.getElementById('prev_secondary').style.background = s;
  document.getElementById('prev_accent').style.background    = a;
  document.getElementById('prev_sidebar').style.background   = sb;

  // Update live preview
  document.getElementById('prev_sidebar_box').style.background = `linear-gradient(135deg,${sb},${s})`;
  document.getElementById('prev_logo_box').style.background    = `linear-gradient(135deg,${p},${s})`;
  document.getElementById('prev_btn').style.background         = p;
  document.getElementById('prev_ticket_tag').style.color       = p;

  // Update page theme in real time
  document.documentElement.style.setProperty('--t-primary',   p);
  document.documentElement.style.setProperty('--t-secondary', s);
  document.documentElement.style.setProperty('--t-accent',    a);
  document.documentElement.style.setProperty('--t-sidebar',   sb);
  document.querySelector('.sidebar').style.background = `linear-gradient(175deg,${sb},${s})`;
}

function applyPreset(p, s, a, sb) {
  document.getElementById('cp_primary').value   = p;
  document.getElementById('cp_secondary').value = s;
  document.getElementById('cp_accent').value    = a;
  document.getElementById('cp_sidebar').value   = sb;
  updatePreview();
}

// Logo upload preview
function previewLogo(input) {
  if (input.files && input.files[0]) {
    const reader = new FileReader();
    reader.onload = e => {
      document.getElementById('logo-preview-img').src = e.target.result;
      document.getElementById('logo-preview-wrap').style.display = 'block';
      document.getElementById('prev_logo_box').innerHTML = '<img src="' + e.target.result + '" style="width:100%;height:100%;object-fit:cover;border-radius:7px;">';
    };
    reader.readAsDataURL(input.files[0]);
  }
}
function handleLogoDrop(e) {
  e.preventDefault();
  document.getElementById('logo-drop-zone').style.borderColor = 'var(--border)';
  document.getElementById('logo-drop-zone').style.background = '#fafbfc';
  const file = e.dataTransfer.files[0];
  if (file && file.type.startsWith('image/')) {
    const dt = new DataTransfer();
    dt.items.add(file);
    document.getElementById('logo_file_input').files = dt.files;
    previewLogo(document.getElementById('logo_file_input'));
  }
}

// Sync system name preview
document.querySelector('input[name="system_name"]')?.addEventListener('input', function() {
  document.getElementById('prev_sysname').textContent = this.value || 'PawnHub';
});
</script>
</body>
</html>