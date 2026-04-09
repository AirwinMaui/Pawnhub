<?php
require_once __DIR__ . '/session_helper.php';
pawnhub_session_start('admin');
require 'db.php';
require 'theme_helper.php';

// ── Session guard ──────────────────────────────────────────────
function redirectToTenantLogin(): void {
    $slug = $_SESSION['user']['tenant_slug'] ?? '';
    header('Location: ' . ($slug ? '/' . rawurlencode($slug) . '?login=1' : '/login.php'));
    exit;
}
if (empty($_SESSION['user'])) { redirectToTenantLogin(); }
$u = $_SESSION['user'];
if ($u['role'] !== 'admin') { redirectToTenantLogin(); }

$tid         = $u['tenant_id'];
$active_page = $_GET['page'] ?? 'dashboard';
$success_msg = $error_msg = '';

// ── Block if tenant is deactivated ────────────────────────────
try {
    $chk = $pdo->prepare("SELECT status FROM tenants WHERE id=? LIMIT 1");
    $chk->execute([$tid]);
    $t_status = $chk->fetchColumn();
    if ($t_status === 'inactive') {
        session_unset(); session_destroy();
        header('Location: login.php?deactivated=1'); exit;
    }
} catch (Throwable $e) {}

// ── Plan Features — what each plan can access ─────────────────
// Load tenant plan
$plan_row = $pdo->prepare("SELECT plan FROM tenants WHERE id=? LIMIT 1");
$plan_row->execute([$tid]);
$tenant_plan = strtolower($plan_row->fetchColumn() ?? 'starter');

// Define features per plan
$plan_features = [
    'starter'    => [
        'theme_branding' => false,  // No Theme & Branding
        'managers'       => false,  // No invite managers
        'audit_logs'     => false,  // No audit logs
        'reports'        => false,  // No advanced reports
        'data_export'    => false,  // No data export
        'white_label'    => false,  // No white label
    ],
    'pro'        => [
        'theme_branding' => true,
        'managers'       => true,
        'audit_logs'     => true,
        'reports'        => true,
        'data_export'    => false,  // No data export
        'white_label'    => false,
    ],
    'enterprise' => [
        'theme_branding' => true,
        'managers'       => true,
        'audit_logs'     => true,
        'reports'        => true,
        'data_export'    => true,
        'white_label'    => true,
    ],
];
$features = $plan_features[$tenant_plan] ?? $plan_features['starter'];

// Block direct URL access to restricted pages
$restricted_pages = [];
if (!$features['theme_branding']) $restricted_pages[] = 'settings';
if (!$features['managers'])       $restricted_pages[] = 'users';
if (!$features['audit_logs'])     $restricted_pages[] = 'audit';
if (!$features['data_export'])    $restricted_pages[] = 'export';

if (in_array($active_page, $restricted_pages)) {
    $active_page = 'dashboard';
    $error_msg   = '⚠️ This feature is not available on your current plan. Please upgrade to access it.';
}

// Load theme
$theme = getTenantTheme($pdo, $tid);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    // Invite Staff or Cashier via Email
    if ($_POST['action'] === 'invite_staff') {
        if (!$features['managers']) {
            $error_msg = 'Inviting Managers is not available on your current plan. Please upgrade to Pro or Enterprise.';
        } else {
        $email = trim($_POST['email'] ?? '');
        $name  = trim($_POST['name']  ?? '');
        // Admin/Owner can ONLY invite Managers — Managers handle Staff/Cashier themselves
        $role  = 'manager';

        if (!$email || !$name) {
            $error_msg = 'Please fill in name and email.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error_msg = 'Invalid email address.';
        } else {
            $chk = $pdo->prepare("SELECT id FROM users WHERE email=? AND tenant_id=?");
            $chk->execute([$email, $tid]);
            if ($chk->fetch()) {
                $error_msg = 'This email already has an account in your branch.';
            } else {
                // Expire only existing pending manager invitations for this email
                $pdo->prepare("UPDATE tenant_invitations SET status='expired' WHERE email=? AND tenant_id=? AND status='pending' AND role='manager'")
                    ->execute([$email, $tid]);

                $token      = bin2hex(random_bytes(32));
                $expires_at = date('Y-m-d H:i:s', strtotime('+24 hours'));

                $pdo->prepare("INSERT INTO tenant_invitations (tenant_id, email, owner_name, role, token, status, expires_at, created_by) VALUES (?,?,?,?,?,'pending',?,?)")
                    ->execute([$tid, $email, $name, $role, $token, $expires_at, $u['id']]);

                // Get tenant slug AND business name in one query
                $slug_row = $pdo->prepare("SELECT slug, business_name FROM tenants WHERE id=? LIMIT 1");
                $slug_row->execute([$tid]);
                $slug_row          = $slug_row->fetch();
                $slug              = $slug_row['slug'] ?? '';
                $biz_name_for_mail = $slug_row['business_name'] ?: 'PawnHub';

                try {
                    require_once __DIR__ . '/mailer.php';
                    // Pass slug so after registration manager lands on correct branch login
                    sendManagerInvitation($email, $name, $biz_name_for_mail, $token, $slug);
                    $success_msg = "Manager invitation sent to {$email}!";
                } catch (Throwable $e) {
                    $emailErr = $e->getMessage();
                    error_log('Invite email failed: ' . $emailErr);
                    $error_msg = 'Invitation created but email failed to send. Error: ' . htmlspecialchars($emailErr);
                }
                $active_page = 'users';
            }
        }
        } // end plan check
    }

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

    if ($_POST['action'] === 'approve_applicant') {
        $apid = intval($_POST['applicant_id']);
        $row  = $pdo->prepare("SELECT * FROM tenant_applicants WHERE id=? AND tenant_id=? AND status='pending' LIMIT 1");
        $row->execute([$apid,$tid]); $applicant = $row->fetch();
        if ($applicant) {
            // Create user account
            $pdo->prepare("INSERT INTO users (tenant_id,fullname,username,email,password,role,contact_number,status,is_suspended,created_at)
                VALUES (?,?,?,?,?,'".addslashes($applicant['role'])."',?,?,0,NOW())")
                ->execute([$tid,$applicant['fullname'],$applicant['username'],$applicant['email'],
                           $applicant['password_hash'],$applicant['contact_number']??null,'approved']);
            $pdo->prepare("UPDATE tenant_applicants SET status='approved',decided_at=NOW(),decided_by=? WHERE id=?")
                ->execute([$u['id'],$apid]);
            $success_msg = "✅ {$applicant['fullname']} has been approved and their account is now active.";
        }
        $active_page = 'applicants';
    }

    if ($_POST['action'] === 'reject_applicant') {
        $apid = intval($_POST['applicant_id']);
        $row  = $pdo->prepare("SELECT fullname FROM tenant_applicants WHERE id=? AND tenant_id=? LIMIT 1");
        $row->execute([$apid,$tid]); $applicant = $row->fetch();
        $pdo->prepare("UPDATE tenant_applicants SET status='rejected',decided_at=NOW(),decided_by=? WHERE id=?")
            ->execute([$u['id'],$apid]);
        $success_msg = "Application from ".htmlspecialchars($applicant['fullname']??'applicant')." has been rejected.";
        $active_page = 'applicants';
    }

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

    if ($_POST['action'] === 'approve_renewal') {
        $rrid = intval($_POST['renewal_id']);
        $pdo->prepare("UPDATE renewal_requests SET verification_status='verified',verified_by_admin_id=?,verified_at=NOW() WHERE id=? AND tenant_id=?")->execute([$u['id'],$rrid,$tid]);
        // Notify mobile
        try {
            $rr = $pdo->prepare("SELECT old_ticket_no FROM renewal_requests WHERE id=? LIMIT 1");
            $rr->execute([$rrid]); $rrow = $rr->fetch();
            if ($rrow) write_pawn_update($pdo, $tid, $rrow['old_ticket_no'], 'RENEWAL_APPROVED',
                "Your renewal request for ticket #{$rrow['old_ticket_no']} has been approved! Please proceed to the branch for processing.");
        } catch(Throwable $e) {}
        $success_msg = 'Renewal approved.'; $active_page = 'renewals';
    }
    if ($_POST['action'] === 'reject_renewal') {
        $rrid = intval($_POST['renewal_id']);
        $pdo->prepare("UPDATE renewal_requests SET verification_status='rejected',verified_by_admin_id=?,verified_at=NOW() WHERE id=? AND tenant_id=?")->execute([$u['id'],$rrid,$tid]);
        // Notify mobile
        try {
            $rr = $pdo->prepare("SELECT old_ticket_no FROM renewal_requests WHERE id=? LIMIT 1");
            $rr->execute([$rrid]); $rrow = $rr->fetch();
            if ($rrow) write_pawn_update($pdo, $tid, $rrow['old_ticket_no'], 'RENEWAL_REJECTED',
                "Your renewal request for ticket #{$rrow['old_ticket_no']} was not approved. Please contact the branch for details.");
        } catch(Throwable $e) {}
        $success_msg = 'Renewal rejected.'; $active_page = 'renewals';
    }

    if ($_POST['action'] === 'save_theme') {
        if (!$features['theme_branding']) {
            $error_msg = 'Theme & Branding is not available on your current plan. Please upgrade to Pro or Enterprise.';
        } else {
        $primary   = preg_match('/^#[0-9a-fA-F]{6}$/', $_POST['primary_color']??'')   ? $_POST['primary_color']   : '#2563eb';
        $secondary = preg_match('/^#[0-9a-fA-F]{6}$/', $_POST['secondary_color']??'') ? $_POST['secondary_color'] : '#1e3a8a';
        $accent    = preg_match('/^#[0-9a-fA-F]{6}$/', $_POST['accent_color']??'')    ? $_POST['accent_color']    : '#10b981';
        $sidebar   = preg_match('/^#[0-9a-fA-F]{6}$/', $_POST['sidebar_color']??'')   ? $_POST['sidebar_color']   : '#0f172a';
        $sysname   = trim($_POST['system_name'] ?? 'PawnHub') ?: 'PawnHub';
        $logotext  = trim($_POST['logo_text']   ?? '');

        $logourl = $theme['logo_url'] ?? '';
        if (!empty($_FILES['logo_file']['name'])) {
            $allowed = ['image/jpeg','image/png','image/gif','image/webp','image/svg+xml'];
            $ftype   = mime_content_type($_FILES['logo_file']['tmp_name']);
            if (in_array($ftype, $allowed) && $_FILES['logo_file']['size'] <= 2*1024*1024) {
                $ext      = pathinfo($_FILES['logo_file']['name'], PATHINFO_EXTENSION);
                $filename = 'logo_' . $tid . '_' . time() . '.' . $ext;
                $uploaddir= __DIR__ . '/uploads/';
                if (!is_dir($uploaddir)) mkdir($uploaddir, 0755, true);
                if ($logourl && strpos($logourl, '/uploads/') !== false) {
                    $oldfile = __DIR__ . parse_url($logourl, PHP_URL_PATH);
                    if (file_exists($oldfile)) unlink($oldfile);
                }
                if (move_uploaded_file($_FILES['logo_file']['tmp_name'], $uploaddir . $filename)) {
                    $logourl = '/uploads/' . $filename;
                } else {
                    $error_msg = 'Failed to upload logo. Please try again.';
                }
            } else {
                $error_msg = 'Invalid file. Please upload JPG, PNG, GIF, or WebP under 2MB.';
            }
        } elseif (isset($_POST['remove_logo']) && $_POST['remove_logo'] === '1') {
            if ($logourl && file_exists(__DIR__ . $logourl)) unlink(__DIR__ . $logourl);
            $logourl = '';
        }

        // ── Background image upload/remove ──────────────────────
        $bgrow    = $pdo->prepare("SELECT bg_image_url FROM tenants WHERE id=? LIMIT 1");
        $bgrow->execute([$tid]);
        $bgurl    = $bgrow->fetchColumn() ?: '';

        if (!empty($_FILES['bg_file']['name'])) {
            $bgAllowed = ['image/jpeg','image/png','image/webp'];
            $bgType    = mime_content_type($_FILES['bg_file']['tmp_name']);
            if (in_array($bgType, $bgAllowed) && $_FILES['bg_file']['size'] <= 5*1024*1024) {
                $bgExt  = pathinfo($_FILES['bg_file']['name'], PATHINFO_EXTENSION);
                $bgName = 'bg_' . $tid . '_' . time() . '.' . $bgExt;
                $uploaddir = __DIR__ . '/uploads/';
                if (!is_dir($uploaddir)) mkdir($uploaddir, 0755, true);
                // Delete old bg file if it was locally uploaded
                if ($bgurl && strpos($bgurl, '/uploads/') === 0 && file_exists(__DIR__ . $bgurl)) {
                    unlink(__DIR__ . $bgurl);
                }
                if (move_uploaded_file($_FILES['bg_file']['tmp_name'], $uploaddir . $bgName)) {
                    $bgurl = '/uploads/' . $bgName;
                } else {
                    $error_msg = 'Failed to upload background. Please try again.';
                }
            } else {
                $error_msg = 'Invalid background file. Use JPG, PNG, or WebP under 5MB.';
            }
        } elseif (isset($_POST['remove_bg']) && $_POST['remove_bg'] === '1') {
            if ($bgurl && strpos($bgurl, '/uploads/') === 0 && file_exists(__DIR__ . $bgurl)) {
                unlink(__DIR__ . $bgurl);
            }
            $bgurl = '';
        }

        if (!$error_msg) {
            // Save bg_image_url to tenants table
            $pdo->prepare("UPDATE tenants SET bg_image_url=? WHERE id=?")->execute([$bgurl ?: null, $tid]);
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
        } // end plan check
        $active_page = 'settings';
    }
}

// ── Fetch data ────────────────────────────────────────────────
$tenant       = $pdo->prepare("SELECT * FROM tenants WHERE id=?"); $tenant->execute([$tid]); $tenant=$tenant->fetch();
$my_users     = $pdo->prepare("SELECT * FROM users WHERE tenant_id=? AND role IN ('manager','staff','cashier') ORDER BY role,fullname"); $my_users->execute([$tid]); $my_users=$my_users->fetchAll();
$tickets      = $pdo->prepare("SELECT * FROM pawn_transactions WHERE tenant_id=? ORDER BY created_at DESC LIMIT 100"); $tickets->execute([$tid]); $tickets=$tickets->fetchAll();
$customers    = $pdo->prepare("SELECT * FROM customers WHERE tenant_id=? ORDER BY full_name"); $customers->execute([$tid]); $customers=$customers->fetchAll();
$inventory    = $pdo->prepare("SELECT * FROM item_inventory WHERE tenant_id=? ORDER BY received_at DESC"); $inventory->execute([$tid]); $inventory=$inventory->fetchAll();
$void_reqs    = $pdo->prepare("SELECT v.*,u.fullname as req_name FROM pawn_void_requests v JOIN users u ON v.requested_by=u.id WHERE v.tenant_id=? ORDER BY v.requested_at DESC"); $void_reqs->execute([$tid]); $void_reqs=$void_reqs->fetchAll();
$renewals     = $pdo->prepare("SELECT * FROM renewal_requests WHERE tenant_id=? ORDER BY created_at DESC"); $renewals->execute([$tid]); $renewals=$renewals->fetchAll();
$audit        = $pdo->prepare("SELECT * FROM audit_logs WHERE tenant_id=? AND actor_role IN ('manager','staff','cashier') ORDER BY created_at DESC LIMIT 200"); $audit->execute([$tid]); $audit=$audit->fetchAll();

$pending_voids    = array_filter($void_reqs, fn($v)=>$v['status']==='pending');
$pending_renewals = array_filter($renewals,  fn($r)=>$r['verification_status']==='pending');
$total_tickets    = count($tickets);
$active_tickets   = count(array_filter($tickets, fn($t)=>$t['status']==='Stored'));
$total_customers  = count($customers);
$total_revenue    = array_sum(array_column(array_filter($tickets, fn($t)=>$t['status']==='Released'), 'total_redeem'));

// Walk-in applicants
$applicants = $pdo->prepare("SELECT * FROM tenant_applicants WHERE tenant_id=? ORDER BY applied_at DESC"); $applicants->execute([$tid]); $applicants=$applicants->fetchAll();
$pending_applicants = array_filter($applicants, fn($a)=>$a['status']==='pending');

$sys_name = $theme['system_name'] ?? 'PawnHub';
$logo_text = $theme['logo_text'] ?: $sys_name;
$logo_url  = $theme['logo_url']  ?? '';
// Normalize local upload paths (fix old records without leading slash)
if ($logo_url && strpos($logo_url,'http') !== 0 && $logo_url[0] !== '/') $logo_url = '/' . $logo_url;
$business_name = $tenant['business_name'] ?? 'My Branch';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title><?=htmlspecialchars($business_name)?> — Admin</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet">
<?= renderThemeCSS($theme) ?>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
:root{
  --sw:272px;
  --blue-acc:var(--t-primary,#2563eb);
  --bg:#0f1117;
  --card-glass:rgba(255,255,255,0.04);
  --card-border:rgba(255,255,255,0.08);
  --text:#f1f5f9;
  --text-m:rgba(255,255,255,0.65);
  --text-dim:rgba(255,255,255,0.35);
  --success:#10b981;
  --danger:#ef4444;
  --warning:#f59e0b;
  --sidebar-bg:rgba(15,23,42,0.75);
}
body{font-family:'Inter',sans-serif;background:var(--bg);color:var(--text);display:flex;min-height:100vh;overflow:hidden;}

/* ── BG ── */
.bg-scene{position:fixed;inset:0;z-index:0;}
.bg-scene img{width:100%;height:100%;object-fit:cover;opacity:.18;filter:brightness(0.6);}
.bg-overlay{position:absolute;inset:0;background:linear-gradient(135deg,rgba(15,23,42,.95) 0%,rgba(15,23,42,.75) 60%,rgba(30,58,138,.15) 100%);}

/* ── SIDEBAR ── */
.sidebar{
  width:var(--sw);min-height:100vh;
  background:rgba(10,14,26,0.8);
  backdrop-filter:blur(40px);-webkit-backdrop-filter:blur(40px);
  border-right:1px solid rgba(255,255,255,0.06);
  display:flex;flex-direction:column;
  position:fixed;left:0;top:0;bottom:0;z-index:100;overflow-y:auto;
}
.sb-brand{padding:22px 18px 16px;border-bottom:1px solid rgba(255,255,255,.06);display:flex;align-items:center;gap:11px;}
.sb-logo{width:40px;height:40px;background:linear-gradient(135deg,var(--t-primary,#3b82f6),var(--t-secondary,#1e3a8a));border-radius:12px;display:flex;align-items:center;justify-content:center;flex-shrink:0;overflow:hidden;box-shadow:0 4px 16px rgba(37,99,235,.4);}
.sb-logo img{width:100%;height:100%;object-fit:cover;}
.sb-logo svg{width:20px;height:20px;}
.sb-name{font-size:.95rem;font-weight:800;color:#fff;letter-spacing:-.02em;}
.sb-subtitle{font-size:.6rem;color:rgba(255,255,255,.3);font-weight:600;letter-spacing:.1em;text-transform:uppercase;margin-top:1px;}
.sb-user{padding:12px 18px;border-bottom:1px solid rgba(255,255,255,.06);display:flex;align-items:center;gap:9px;}
.sb-avatar{width:32px;height:32px;border-radius:50%;background:linear-gradient(135deg,var(--t-primary,#3b82f6),var(--t-secondary,#8b5cf6));display:flex;align-items:center;justify-content:center;font-size:.76rem;font-weight:700;color:#fff;flex-shrink:0;}
.sb-uname{font-size:.8rem;font-weight:700;color:#fff;}
.sb-urole{font-size:.62rem;color:rgba(255,255,255,.35);margin-top:1px;}
.sb-nav{flex:1;padding:10px 0;}
.sb-section{font-size:.58rem;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:rgba(255,255,255,.2);padding:12px 16px 4px;}
.sb-item{display:flex;align-items:center;gap:10px;padding:9px 14px;margin:1px 8px;border-radius:10px;cursor:pointer;color:rgba(255,255,255,.45);font-size:.82rem;font-weight:500;text-decoration:none;transition:all .18s;}
.sb-item:hover{background:rgba(255,255,255,.07);color:rgba(255,255,255,.9);}
.sb-item.active{background:rgba(255,255,255,.12);color:#fff;font-weight:600;}
.sb-item .material-symbols-outlined{font-size:18px;flex-shrink:0;opacity:.7;}
.sb-item.active .material-symbols-outlined{opacity:1;}
.sb-pill{margin-left:auto;background:#ef4444;color:#fff;font-size:.6rem;font-weight:700;padding:1px 6px;border-radius:100px;}
.sb-footer{padding:12px 14px;border-top:1px solid rgba(255,255,255,.06);}
.sb-logout{display:flex;align-items:center;gap:9px;font-size:.8rem;color:rgba(255,255,255,.3);text-decoration:none;padding:9px 10px;border-radius:10px;transition:all .18s;}
.sb-logout:hover{color:#f87171;background:rgba(239,68,68,.1);}
.sb-logout .material-symbols-outlined{font-size:18px;}

/* ── MAIN ── */
.main{margin-left:var(--sw);flex:1;display:flex;flex-direction:column;position:relative;z-index:10;height:100vh;overflow-y:auto;}
.topbar{height:64px;padding:0 28px;background:rgba(10,14,26,.6);backdrop-filter:blur(20px);-webkit-backdrop-filter:blur(20px);border-bottom:1px solid rgba(255,255,255,.06);display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:50;}
.topbar-title{font-size:1rem;font-weight:700;color:#fff;letter-spacing:-.02em;}
.tenant-chip{font-size:.69rem;font-weight:700;background:rgba(255,255,255,.08);color:rgba(255,255,255,.7);padding:4px 12px;border-radius:100px;border:1px solid rgba(255,255,255,.12);}
.topbar-right{display:flex;align-items:center;gap:12px;}
.topbar-icon{width:36px;height:36px;border-radius:9px;background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.08);display:flex;align-items:center;justify-content:center;cursor:pointer;color:rgba(255,255,255,.5);transition:all .15s;position:relative;}
.topbar-icon:hover{background:rgba(255,255,255,.1);color:#fff;}
.topbar-icon .material-symbols-outlined{font-size:18px;}
.notif-dot{position:absolute;top:6px;right:6px;width:7px;height:7px;background:var(--t-primary,#3b82f6);border-radius:50%;border:2px solid rgba(10,14,26,1);}
.topbar-user{display:flex;align-items:center;gap:9px;padding-left:12px;border-left:1px solid rgba(255,255,255,.08);}
.topbar-user-name{font-size:.82rem;font-weight:600;color:#fff;}
.topbar-user-role{font-size:.67rem;color:rgba(255,255,255,.4);}
.topbar-avatar{width:34px;height:34px;border-radius:50%;background:linear-gradient(135deg,var(--t-primary,#3b82f6),var(--t-secondary,#1e3a8a));display:flex;align-items:center;justify-content:center;font-size:.76rem;font-weight:700;color:#fff;overflow:hidden;}
.topbar-avatar img{width:100%;height:100%;object-fit:cover;}

/* ── CONTENT ── */
.content{padding:24px 28px;flex:1;}

/* ── GLASS CARD ── */
.glass-card{background:rgba(255,255,255,.04);backdrop-filter:blur(16px);-webkit-backdrop-filter:blur(16px);border:1px solid rgba(255,255,255,.08);border-radius:16px;transition:all .25s;}
.glass-card:hover{background:rgba(255,255,255,.07);}
.card{background:rgba(255,255,255,.04);backdrop-filter:blur(16px);border:1px solid rgba(255,255,255,.08);border-radius:16px;padding:20px;}

/* ── STATS ── */
.stats-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:22px;}
.stat-card{background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.08);border-radius:16px;padding:18px 20px;display:flex;flex-direction:column;justify-content:space-between;min-height:130px;}
.stat-icon-wrap{width:40px;height:40px;border-radius:11px;display:flex;align-items:center;justify-content:center;}
.stat-icon-wrap .material-symbols-outlined{font-size:20px;}
.stat-value{font-size:1.7rem;font-weight:800;color:#fff;letter-spacing:-.03em;margin-top:10px;}
.stat-label{font-size:.68rem;font-weight:600;color:rgba(255,255,255,.4);text-transform:uppercase;letter-spacing:.07em;margin-top:4px;}
.stat-badge{font-size:.65rem;font-weight:700;padding:2px 8px;border-radius:100px;}

/* ── TABLE ── */
table{width:100%;border-collapse:collapse;}
th{font-size:.64rem;font-weight:700;letter-spacing:.07em;text-transform:uppercase;color:rgba(255,255,255,.3);padding:8px 12px;text-align:left;border-bottom:1px solid rgba(255,255,255,.07);}
td{padding:11px 12px;font-size:.81rem;color:rgba(255,255,255,.75);border-bottom:1px solid rgba(255,255,255,.04);vertical-align:middle;}
tr:last-child td{border-bottom:none;}
tr:hover td{background:rgba(255,255,255,.03);}

/* ── BADGE ── */
.badge{display:inline-flex;align-items:center;gap:3px;font-size:.64rem;font-weight:700;padding:3px 9px;border-radius:100px;}
.b-blue{background:rgba(59,130,246,.2);color:#93c5fd;}
.b-green{background:rgba(16,185,129,.2);color:#6ee7b7;}
.b-red{background:rgba(239,68,68,.2);color:#fca5a5;}
.b-yellow{background:rgba(245,158,11,.2);color:#fcd34d;}
.b-purple{background:rgba(139,92,246,.2);color:#c4b5fd;}
.b-gray{background:rgba(255,255,255,.07);color:rgba(255,255,255,.5);}
.b-dot{width:4px;height:4px;border-radius:50%;background:currentColor;}

/* ── BUTTONS ── */
.btn-sm{padding:6px 13px;border-radius:8px;font-size:.73rem;font-weight:600;cursor:pointer;border:1px solid rgba(255,255,255,.12);background:rgba(255,255,255,.06);color:rgba(255,255,255,.7);text-decoration:none;display:inline-flex;align-items:center;gap:5px;transition:all .15s;margin-right:4px;font-family:inherit;}
.btn-sm:hover{background:rgba(255,255,255,.12);}
.btn-primary{background:var(--t-primary,#2563eb);color:#fff;border-color:var(--t-primary,#2563eb);}
.btn-primary:hover{filter:brightness(1.1);}
.btn-success{background:rgba(16,185,129,.8);color:#fff;border-color:transparent;}
.btn-danger{background:rgba(239,68,68,.8);color:#fff;border-color:transparent;}
.ticket-tag{font-family:monospace;font-size:.76rem;color:var(--t-primary,#60a5fa);font-weight:700;}

/* ── ALERTS ── */
.alert{padding:11px 16px;border-radius:12px;font-size:.82rem;margin-bottom:18px;display:flex;align-items:center;gap:9px;}
.alert-success{background:rgba(16,185,129,.15);border:1px solid rgba(16,185,129,.3);color:#6ee7b7;}
.alert-error{background:rgba(239,68,68,.15);border:1px solid rgba(239,68,68,.3);color:#fca5a5;}

/* ── EMPTY STATE ── */
.empty-state{text-align:center;padding:50px 20px;color:rgba(255,255,255,.3);}
.empty-state .material-symbols-outlined{font-size:48px;display:block;margin:0 auto 16px;opacity:.3;}
.empty-state p{font-size:.83rem;}

/* ── CARD HEADER ── */
.card-hdr{display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;flex-wrap:wrap;gap:8px;}
.card-title{font-size:.78rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:rgba(255,255,255,.5);}

/* ── MODAL ── */
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.7);z-index:999;align-items:center;justify-content:center;backdrop-filter:blur(6px);}
.modal-overlay.open{display:flex;}
.modal{background:#0f1117;border:1px solid rgba(255,255,255,.1);border-radius:20px;width:500px;max-width:95vw;max-height:92vh;overflow-y:auto;box-shadow:0 24px 80px rgba(0,0,0,.6);animation:mIn .25s ease both;}
@keyframes mIn{from{opacity:0;transform:translateY(16px)}to{opacity:1;transform:none}}
.mhdr{padding:22px 24px 0;display:flex;align-items:center;justify-content:space-between;}
.mtitle{font-size:1rem;font-weight:800;color:#fff;}
.mclose{width:30px;height:30px;border-radius:8px;border:1px solid rgba(255,255,255,.1);background:rgba(255,255,255,.04);cursor:pointer;display:flex;align-items:center;justify-content:center;color:rgba(255,255,255,.4);}
.mclose .material-symbols-outlined{font-size:16px;}
.mbody{padding:18px 24px 24px;}

/* ── FORMS ── */
.fg2{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px;}
.flabel{display:block;font-size:.73rem;font-weight:600;color:rgba(255,255,255,.45);margin-bottom:5px;}
.finput{width:100%;border:1.5px solid rgba(255,255,255,.1);border-radius:10px;padding:9px 13px;font-family:inherit;font-size:.84rem;color:#fff;outline:none;background:rgba(255,255,255,.06);transition:border .2s;}
.finput:focus{border-color:var(--t-primary,#3b82f6);box-shadow:0 0 0 3px rgba(59,130,246,.15);}
.finput::placeholder{color:rgba(255,255,255,.2);}

/* ── THEME SETTINGS ── */
.theme-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:18px;}
.color-picker-wrap{display:flex;align-items:center;gap:10px;}
.color-picker-wrap input[type=color]{width:44px;height:36px;border:1.5px solid rgba(255,255,255,.1);border-radius:8px;padding:2px;cursor:pointer;background:transparent;}
.color-preview{width:100%;height:36px;border-radius:8px;border:1px solid rgba(255,255,255,.1);display:flex;align-items:center;justify-content:center;font-size:.72rem;font-weight:600;color:#fff;text-shadow:0 1px 2px rgba(0,0,0,.5);}
.preset-row{display:flex;gap:8px;flex-wrap:wrap;margin-top:6px;}
.preset{width:28px;height:28px;border-radius:50%;cursor:pointer;border:2px solid transparent;transition:all .15s;}
.preset:hover,.preset.active{border-color:#fff;box-shadow:0 0 0 2px rgba(255,255,255,.3);}

/* ── QUICK ACTIONS ── */
.quick-btn{display:flex;align-items:center;justify-content:center;gap:8px;padding:14px;border-radius:14px;background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.08);cursor:pointer;text-decoration:none;font-size:.81rem;font-weight:600;color:rgba(255,255,255,.7);transition:all .2s;flex-direction:column;}
.quick-btn:hover{background:rgba(255,255,255,.1);color:#fff;transform:translateY(-2px);}
.quick-btn .material-symbols-outlined{font-size:22px;}
.quick-actions-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:10px;}

/* ── ATTENTION ALERTS ── */
.attn-card{background:rgba(245,158,11,.08);border:1px solid rgba(245,158,11,.2);border-radius:12px;padding:14px 16px;text-decoration:none;text-align:center;display:block;}
.attn-card:hover{background:rgba(245,158,11,.14);}
.attn-val{font-size:1.6rem;font-weight:800;color:#fcd34d;}
.attn-label{font-size:.74rem;color:rgba(255,255,255,.5);font-weight:600;margin-top:3px;}
.attn-card.blue{background:rgba(59,130,246,.08);border-color:rgba(59,130,246,.2);}
.attn-card.blue .attn-val{color:#93c5fd;}

@media(max-width:1200px){.stats-grid{grid-template-columns:repeat(2,1fr);}.theme-grid{grid-template-columns:1fr;}}
@media(max-width:600px){.stats-grid{grid-template-columns:1fr;}}
</style>
</head>
<body>

<!-- Background Scene -->
<div class="bg-scene">
  <?php
    $rawBgScene = $tenant['bg_image_url'] ?? '';
    if ($rawBgScene && strpos($rawBgScene,'http') !== 0 && $rawBgScene[0] !== '/') {
        $rawBgScene = '/' . $rawBgScene;
    }
    $bgScene = !empty($rawBgScene)
        ? htmlspecialchars($rawBgScene)
        : 'https://images.unsplash.com/photo-1605100804763-247f67b3557e?w=1600&auto=format&fit=crop&q=60';
  ?>
  <img src="<?= $bgScene ?>" alt="">
  <div class="bg-overlay"></div>
</div>

<!-- Sidebar -->
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
      <div class="sb-name"><?=htmlspecialchars($business_name)?></div>
      <div class="sb-subtitle">Admin Terminal</div>
    </div>
  </div>
  <div class="sb-user">
    <div class="sb-avatar"><?=strtoupper(substr($u['name'],0,1))?></div>
    <div>
      <div class="sb-uname"><?=htmlspecialchars(explode(' ',$u['name'])[0]??$u['name'])?></div>
      <div class="sb-urole">Branch Admin</div>
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
    <a href="?page=inventory" class="sb-item <?=$active_page==='inventory'?'active':''?>">
      <span class="material-symbols-outlined">inventory_2</span>Inventory
    </a>


    <div class="sb-section">Team</div>
    <?php if($features['managers']):?>
    <a href="?page=users" class="sb-item <?=$active_page==='users'?'active':''?>">
      <span class="material-symbols-outlined">badge</span>Managers 
    </a>
    <?php endif;?>
    <a href="?page=applicants" class="sb-item <?=$active_page==='applicants'?'active':''?>">
      <span class="material-symbols-outlined">person_search</span>Applicants
      <?php if(count($pending_applicants)>0):?><span class="sb-pill"><?=count($pending_applicants)?></span><?php endif;?>
    </a>
    <?php if($features['audit_logs']):?>
    <a href="?page=audit" class="sb-item <?=$active_page==='audit'?'active':''?>">
      <span class="material-symbols-outlined">manage_search</span>Audit Logs
    </a>
    <?php endif;?>
    <?php if($features['theme_branding']):?>
    <div class="sb-section">Customize</div>
    <a href="?page=settings" class="sb-item <?=$active_page==='settings'?'active':''?>">
      <span class="material-symbols-outlined">palette</span>Theme &amp; Branding
    </a>
    <?php else:?>
    <div class="sb-section">Customize</div>
    <div style="margin:2px 8px;padding:9px 14px;border-radius:10px;background:rgba(255,255,255,.03);border:1px dashed rgba(255,255,255,.08);cursor:default;">
      <div style="display:flex;align-items:center;gap:10px;color:rgba(255,255,255,.2);font-size:.82rem;">
        <span class="material-symbols-outlined" style="font-size:18px;">lock</span>
        Theme &amp; Branding
      </div>
      <div style="font-size:.62rem;color:rgba(255,255,255,.15);margin-top:3px;margin-left:28px;">Upgrade to Pro</div>
    </div>
    <?php endif;?>

    <?php if($features['data_export']):?>
    <div class="sb-section">Export</div>
    <a href="?page=export" class="sb-item <?=$active_page==='export'?'active':''?>">
      <span class="material-symbols-outlined">download</span>Export to PDF
    </a>
    <?php endif;?>
  </nav>
  <div class="sb-footer">
    <a href="tenant_subscription.php" class="sb-item <?=$active_page==='subscription'?'active':''?>" style="margin:0 0 6px 0;">
      <span class="material-symbols-outlined">workspace_premium</span>Subscription
    </a>
    <a href="logout.php?role=admin&slug=<?= rawurlencode($u['tenant_slug'] ?? '') ?>" class="sb-logout">
      <span class="material-symbols-outlined">logout</span>Sign Out
    </a>
  </div>
</aside>

<!-- Main -->
<div class="main">
  <header class="topbar">
    <div style="display:flex;align-items:center;gap:10px;">
      <span class="topbar-title"><?php $titles=['dashboard'=>'Dashboard','tickets'=>'Pawn Tickets','customers'=>'Customers','inventory'=>'Inventory','users'=>'Team — Managers, Staff & Cashier','audit'=>'Audit Logs','settings'=>'Theme & Branding','export'=>'Export to PDF','applicants'=>'Walk-in Applicants'];echo $titles[$active_page]??'Dashboard';?></span>
      <span class="tenant-chip"><?=htmlspecialchars($business_name)?></span>
    </div>
    <div class="topbar-right">
      <?php if($active_page==='users'):?>
      <button onclick="document.getElementById('addUserModal').classList.add('open')" class="btn-sm btn-primary" style="padding:7px 14px;font-size:.78rem;">
        <span class="material-symbols-outlined" style="font-size:15px;">person_add</span>Invite Manager
      </button>
      <?php endif;?>
      <div class="topbar-icon">
        <span class="material-symbols-outlined">notifications</span>
        <span class="notif-dot"></span>
      </div>
      <div class="topbar-icon"><span class="material-symbols-outlined">settings</span></div>
      <div class="topbar-user">
        <div style="text-align:right;">
          <div class="topbar-user-name"><?=htmlspecialchars(explode(' ',$u['name'])[0]??$u['name'])?></div>
          <div class="topbar-user-role">Branch Admin</div>
        </div>
        <div class="topbar-avatar"><?=strtoupper(substr($u['name'],0,1))?></div>
      </div>
    </div>
  </header>

  <div class="content">
  <?php if($success_msg):?><div class="alert alert-success"><span class="material-symbols-outlined" style="font-size:18px;">check_circle</span><?=htmlspecialchars($success_msg)?></div><?php endif;?>
  <?php if($error_msg):?><div class="alert alert-error"><span class="material-symbols-outlined" style="font-size:18px;">warning</span><?=htmlspecialchars($error_msg)?></div><?php endif;?>

  <?php if($active_page==='dashboard'): ?>
    <?php if($tenant_plan === 'starter'): ?>
    <div style="background:linear-gradient(135deg,rgba(245,158,11,.15),rgba(234,88,12,.1));border:1px solid rgba(245,158,11,.3);border-radius:12px;padding:12px 18px;margin-bottom:16px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;">
      <div style="display:flex;align-items:center;gap:10px;">
        <span style="font-size:1.2rem;">⭐</span>
        <div>
          <div style="font-size:.8rem;font-weight:700;color:#fcd34d;">You're on the Starter Plan</div>
          <div style="font-size:.72rem;color:rgba(255,255,255,.4);margin-top:2px;">Upgrade to Pro to unlock Theme & Branding, Manager invitations, Audit Logs and more.</div>
        </div>
      </div>
      <div style="font-size:.72rem;font-weight:700;color:#fcd34d;background:rgba(245,158,11,.2);padding:5px 14px;border-radius:100px;border:1px solid rgba(245,158,11,.3);white-space:nowrap;">
        <?=htmlspecialchars($tenant['plan'])?> Plan
      </div>
    </div>
    <?php endif;?>
    <!-- Branch Info Banner -->
    <div style="background:linear-gradient(135deg,rgba(30,58,138,.6),rgba(37,99,235,.3));border:1px solid rgba(59,130,246,.2);border-radius:14px;padding:16px 22px;margin-bottom:18px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;">
      <div>
        <div style="font-size:.63rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:rgba(255,255,255,.4);margin-bottom:4px;">Your Branch</div>
        <div style="font-size:1.05rem;font-weight:800;color:#fff;"><?=htmlspecialchars($business_name)?></div>
        <div style="font-size:.76rem;color:rgba(255,255,255,.45);margin-top:2px;"><?=$tenant['plan']?> Plan &middot; Branch Admin</div>
        <div style="font-size:.72rem;color:rgba(255,255,255,.3);margin-top:4px;font-family:monospace;">Tenant #<?=str_pad($tid,4,'0',STR_PAD_LEFT)?></div>
        <?php if(!empty($tenant['phone'])):?>
        <div style="font-size:.74rem;color:rgba(255,255,255,.55);margin-top:5px;display:flex;align-items:center;gap:5px;">
          <span class="material-symbols-outlined" style="font-size:14px;font-variation-settings:'FILL' 1,'wght' 400,'GRAD' 0,'opsz' 24;">call</span>
          <?=htmlspecialchars($tenant['phone'])?>
        </div>
        <?php endif;?>
        <?php if(!empty($tenant['address'])):?>
        <div style="font-size:.74rem;color:rgba(255,255,255,.55);margin-top:3px;display:flex;align-items:center;gap:5px;">
          <span class="material-symbols-outlined" style="font-size:14px;font-variation-settings:'FILL' 1,'wght' 400,'GRAD' 0,'opsz' 24;">location_on</span>
          <?=htmlspecialchars($tenant['address'])?>
        </div>
        <?php endif;?>
      </div>
      <div style="text-align:right;">
        <div style="font-size:.63rem;color:rgba(255,255,255,.35);margin-bottom:3px;">Team Members</div>
        <div style="font-size:1.5rem;font-weight:800;color:#fff;"><?=count($my_users)?></div>
      </div>
    </div>

    <!-- KPI Grid -->
    <div class="stats-grid">
      <div class="stat-card">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;">
          <div class="stat-icon-wrap" style="background:rgba(59,130,246,.15);">
            <span class="material-symbols-outlined" style="color:#93c5fd;">confirmation_number</span>
          </div>
          <span class="stat-badge" style="background:rgba(16,185,129,.15);color:#6ee7b7;">+0%</span>
        </div>
        <div>
          <div class="stat-value"><?=$total_tickets?></div>
          <div class="stat-label">Total Tickets</div>
          <div style="font-size:.69rem;color:rgba(255,255,255,.3);margin-top:2px;"><?=$active_tickets?> active</div>
        </div>
      </div>
      <div class="stat-card">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;">
          <div class="stat-icon-wrap" style="background:rgba(245,158,11,.15);">
            <span class="material-symbols-outlined" style="color:#fcd34d;">payments</span>
          </div>
          <span style="font-size:.65rem;color:rgba(255,255,255,.3);font-weight:600;">MTD</span>
        </div>
        <div>
          <div class="stat-value">₱<?=number_format($total_revenue,0)?></div>
          <div class="stat-label">Total Revenue</div>
        </div>
      </div>
      <div class="stat-card">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;">
          <div class="stat-icon-wrap" style="background:rgba(139,92,246,.15);">
            <span class="material-symbols-outlined" style="color:#c4b5fd;">person_search</span>
          </div>
          <span style="font-size:.65rem;color:rgba(255,255,255,.3);font-weight:600;">Unique</span>
        </div>
        <div>
          <div class="stat-value"><?=$total_customers?></div>
          <div class="stat-label">Total Customers</div>
        </div>
      </div>
      <div class="stat-card">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;">
          <div class="stat-icon-wrap" style="background:rgba(239,68,68,.15);">
            <span class="material-symbols-outlined" style="color:#fca5a5;">engineering</span>
          </div>
          <div style="display:flex;gap:-4px;">
            <?php foreach(array_slice($my_users,0,3) as $mu):?>
            <div style="width:22px;height:22px;border-radius:50%;background:var(--t-primary,#3b82f6);display:flex;align-items:center;justify-content:center;font-size:.62rem;font-weight:700;color:#fff;border:2px solid rgba(10,14,26,1);margin-left:-4px;"><?=strtoupper(substr($mu['fullname'],0,1))?></div>
            <?php endforeach;?>
          </div>
        </div>
        <div>
          <div class="stat-value"><?=count($my_users)?></div>
          <div class="stat-label">Active Team Members</div>
        </div>
      </div>
    </div>



    <!-- Main Grid -->
    <div style="display:grid;grid-template-columns:1fr 320px;gap:18px;">
      <!-- Recent Tickets -->
      <div class="card">
        <div class="card-hdr">
          <span class="card-title">Recent Tickets</span>
          <a href="?page=tickets" style="font-size:.73rem;color:var(--t-primary,#60a5fa);font-weight:600;text-decoration:none;display:flex;align-items:center;gap:4px;">View All <span class="material-symbols-outlined" style="font-size:15px;">arrow_forward</span></a>
        </div>
        <?php if(empty($tickets)):?>
        <div class="empty-state">
          <span class="material-symbols-outlined">article</span>
          <p>No tickets yet.</p>
        </div>
        <?php else:?><div style="overflow-x:auto;"><table><thead><tr><th>Ticket</th><th>Customer</th><th>Item</th><th>Loan</th><th>Status</th><th>Date</th></tr></thead><tbody>
        <?php foreach(array_slice($tickets,0,8) as $t): $sc=['Stored'=>'b-blue','Released'=>'b-green','Renewed'=>'b-yellow','Voided'=>'b-red','Auctioned'=>'b-purple'];?>
        <tr><td><span class="ticket-tag"><?=htmlspecialchars($t['ticket_no'])?></span></td><td style="font-weight:600;color:#fff;"><?=htmlspecialchars($t['customer_name'])?></td><td><?=htmlspecialchars($t['item_category'])?></td><td>₱<?=number_format($t['loan_amount'],2)?></td><td><span class="badge <?=$sc[$t['status']]??'b-gray'?>"><?=$t['status']?></span></td><td style="font-size:.73rem;color:rgba(255,255,255,.35);"><?=date('M d, Y',strtotime($t['created_at']))?></td></tr>
        <?php endforeach;?></tbody></table></div><?php endif;?>
      </div>

      <!-- Right Panel -->
      <div style="display:flex;flex-direction:column;gap:16px;">
        <!-- Store Actions -->
        <div class="card">
          <div class="card-title" style="margin-bottom:14px;">Store Actions</div>
          <div class="quick-actions-grid">
            <a href="?page=tickets" class="quick-btn">
              <span class="material-symbols-outlined" style="color:#93c5fd;">add_circle</span>
              <span>New Loan</span>
            </a>

            <a href="?page=tickets" class="quick-btn">
              <span class="material-symbols-outlined" style="color:#6ee7b7;">shopping_bag</span>
              <span>Redeem</span>
            </a>
            <a href="?page=audit" class="quick-btn">
              <span class="material-symbols-outlined" style="color:#fca5a5;">analytics</span>
              <span>Reports</span>
            </a>
          </div>
        </div>

        <!-- Inventory Mix -->
        <div class="card" style="position:relative;overflow:hidden;">
          <div style="position:absolute;right:-16px;bottom:-16px;opacity:.06;">
            <span class="material-symbols-outlined" style="font-size:100px;">diamond</span>
          </div>
          <div class="card-title" style="margin-bottom:16px;">Inventory Mix</div>
          <?php
            $inv_cats=['Jewelry'=>0,'Luxury Watches'=>0,'Electronics'=>0];
            $total_inv=count($inventory);
            foreach($inventory as $item){
              if(stripos($item['item_category'],'jewelry')!==false||stripos($item['item_category'],'gold')!==false||stripos($item['item_category'],'silver')!==false) $inv_cats['Jewelry']++;
              elseif(stripos($item['item_category'],'watch')!==false) $inv_cats['Luxury Watches']++;
              elseif(stripos($item['item_category'],'gadget')!==false||stripos($item['item_category'],'electron')!==false||stripos($item['item_category'],'appliance')!==false) $inv_cats['Electronics']++;
            }
            $colors=['#93c5fd','#fcd34d','#6ee7b7'];$ci=0;
          ?>
          <?php foreach($inv_cats as $cat=>$cnt):$pct=$total_inv>0?round($cnt/$total_inv*100):0;$col=$colors[$ci++];?>
          <div style="margin-bottom:12px;">
            <div style="display:flex;justify-content:space-between;margin-bottom:5px;">
              <span style="font-size:.7rem;color:rgba(255,255,255,.4);font-weight:600;text-transform:uppercase;letter-spacing:.05em;"><?=$cat?></span>
              <span style="font-size:.74rem;font-weight:700;color:#fff;"><?=$pct?>%</span>
            </div>
            <div style="width:100%;height:3px;background:rgba(255,255,255,.07);border-radius:100px;overflow:hidden;">
              <div style="height:100%;background:<?=$col?>;width:<?=$pct?>%;border-radius:100px;transition:width .5s;"></div>
            </div>
          </div>
          <?php endforeach;?>
          <p style="font-size:.71rem;color:rgba(255,255,255,.25);font-style:italic;margin-top:10px;"><?=$total_inv?> item<?=$total_inv!==1?'s':''?> in vault.</p>
        </div>
      </div>
    </div>

  <?php elseif($active_page==='tickets'): ?>
    <div class="card" style="overflow-x:auto;">
      <?php if(empty($tickets)):?><div class="empty-state"><span class="material-symbols-outlined">receipt_long</span><p>No pawn tickets.</p></div>
      <?php else:?><table><thead><tr><th>Ticket No.</th><th>Customer</th><th>Contact</th><th>Item</th><th>Loan</th><th>Total Redeem</th><th>Maturity</th><th>Expiry</th><th>Status</th></tr></thead><tbody>
      <?php foreach($tickets as $t): $sc=['Stored'=>'b-blue','Released'=>'b-green','Renewed'=>'b-yellow','Voided'=>'b-red','Auctioned'=>'b-purple'];?>
      <tr><td><span class="ticket-tag"><?=htmlspecialchars($t['ticket_no'])?></span></td><td style="font-weight:600;color:#fff;"><?=htmlspecialchars($t['customer_name'])?></td><td style="font-family:monospace;font-size:.75rem;"><?=htmlspecialchars($t['contact_number'])?></td><td><?=htmlspecialchars($t['item_category'])?></td><td>₱<?=number_format($t['loan_amount'],2)?></td><td style="font-weight:700;color:#fff;">₱<?=number_format($t['total_redeem'],2)?></td><td style="font-size:.73rem;color:<?=strtotime($t['maturity_date'])<time()&&$t['status']==='Stored'?'#fca5a5':'rgba(255,255,255,.35)'?>;"><?=$t['maturity_date']?></td><td style="font-size:.73rem;color:rgba(255,255,255,.3);"><?=$t['expiry_date']?></td><td><span class="badge <?=$sc[$t['status']]??'b-gray'?>"><?=$t['status']?></span></td></tr>
      <?php endforeach;?></tbody></table><?php endif;?>
    </div>

  <?php elseif($active_page==='customers'): ?>
    <div class="card" style="overflow-x:auto;">
      <?php if(empty($customers)):?><div class="empty-state"><span class="material-symbols-outlined">group</span><p>No customers yet.</p></div>
      <?php else:?><table><thead><tr><th>Name</th><th>Contact</th><th>Email</th><th>Gender</th><th>ID Type</th><th>Registered</th></tr></thead><tbody>
      <?php foreach($customers as $c):?>
      <tr><td style="font-weight:600;color:#fff;"><?=htmlspecialchars($c['full_name'])?></td><td style="font-family:monospace;font-size:.75rem;"><?=htmlspecialchars($c['contact_number'])?></td><td style="font-size:.75rem;color:rgba(255,255,255,.4);"><?=htmlspecialchars($c['email']??'—')?></td><td><?=$c['gender']?></td><td><?=htmlspecialchars($c['valid_id_type']??'—')?></td><td style="font-size:.73rem;color:rgba(255,255,255,.35);"><?=date('M d, Y',strtotime($c['registered_at']))?></td></tr>
      <?php endforeach;?></tbody></table><?php endif;?>
    </div>

  <?php elseif($active_page==='inventory'): ?>
    <div class="card" style="overflow-x:auto;">
      <?php if(empty($inventory)):?><div class="empty-state"><span class="material-symbols-outlined">inventory_2</span><p>No inventory items.</p></div>
      <?php else:?><table><thead><tr><th>Ticket</th><th>Item</th><th>Category</th><th>Appraisal</th><th>Loan</th><th>Status</th><th>Received</th></tr></thead><tbody>
      <?php foreach($inventory as $i): $sc=['pawned'=>'b-blue','redeemed'=>'b-green','voided'=>'b-red','auctioned'=>'b-purple','sold'=>'b-yellow'];?>
      <tr><td><span class="ticket-tag"><?=htmlspecialchars($i['ticket_no'])?></span></td><td style="color:#fff;"><?=htmlspecialchars($i['item_name']??'—')?></td><td><?=htmlspecialchars($i['item_category']??'—')?></td><td>₱<?=number_format($i['appraisal_value']??0,2)?></td><td>₱<?=number_format($i['loan_amount']??0,2)?></td><td><span class="badge <?=$sc[$i['status']]??'b-gray'?>"><?=ucfirst($i['status'])?></span></td><td style="font-size:.72rem;color:rgba(255,255,255,.35);"><?=date('M d, Y',strtotime($i['received_at']))?></td></tr>
      <?php endforeach;?></tbody></table><?php endif;?>
    </div>





  <?php elseif($active_page==='users'): ?>
    <div class="card" style="overflow-x:auto;">
      <?php if(empty($my_users)):?><div class="empty-state"><span class="material-symbols-outlined">badge</span><p>No managers, staff, or cashiers yet.</p></div>
      <?php else:?><table><thead><tr><th>Name</th><th>Username</th><th>Role</th><th>Status</th><th>Added</th><th>Actions</th></tr></thead><tbody>
      <?php foreach($my_users as $usr):
        $role_badge = match($usr['role']) { 'manager'=>'b-green', 'cashier'=>'b-purple', default=>'b-blue' };
        $avatar_bg  = match($usr['role']) { 'manager'=>'rgba(16,185,129,.4)', 'cashier'=>'rgba(139,92,246,.4)', default=>'rgba(59,130,246,.4)' };
      ?>
      <tr>
        <td><div style="display:flex;align-items:center;gap:9px;"><div style="width:28px;height:28px;border-radius:50%;background:<?=$avatar_bg?>;display:flex;align-items:center;justify-content:center;font-size:.68rem;font-weight:700;color:#fff;"><?=strtoupper(substr($usr['fullname'],0,1))?></div><span style="font-weight:600;color:#fff;"><?=htmlspecialchars($usr['fullname'])?></span></div></td>
        <td style="font-family:monospace;font-size:.76rem;color:var(--t-primary,#60a5fa);"><?=htmlspecialchars($usr['username'])?></td>
        <td><span class="badge <?=$role_badge?>"><?=ucfirst($usr['role'])?></span></td>
        <td><span class="badge <?=$usr['is_suspended']?'b-red':'b-green'?>"><span class="b-dot"></span><?=$usr['is_suspended']?'Suspended':'Active'?></span></td>
        <td style="font-size:.72rem;color:rgba(255,255,255,.35);"><?=date('M d, Y',strtotime($usr['created_at']))?></td>
        <td><form method="POST" style="display:inline;"><input type="hidden" name="action" value="toggle_user"><input type="hidden" name="user_id" value="<?=$usr['id']?>"><input type="hidden" name="is_suspended" value="<?=$usr['is_suspended']?>"><button type="submit" class="btn-sm <?=$usr['is_suspended']?'btn-success':'btn-danger'?>" style="font-size:.7rem;" onclick="return confirm('<?=$usr['is_suspended']?'Unsuspend':'Suspend'?> this user?')"><?=$usr['is_suspended']?'Unsuspend':'Suspend'?></button></form></td>
      </tr>
      <?php endforeach;?></tbody></table><?php endif;?>
    </div>

  <?php elseif($active_page==='audit'): ?>
    <div class="page-hdr"><div><h2>Audit Logs</h2><p>Activity from your branch team (managers, staff, cashiers)</p></div></div>
    <div class="card" style="overflow-x:auto;">
      <?php if(empty($audit)):?>
        <div class="empty-state"><span class="material-symbols-outlined">manage_search</span><p>No audit logs yet.</p></div>
      <?php else:?>
      <table><thead><tr><th>Date</th><th>Actor</th><th>Role</th><th>Action</th><th>Ticket #</th><th>Message</th></tr></thead><tbody>
      <?php foreach($audit as $a):
        $role_colors = ['manager'=>'background:rgba(139,92,246,.25);color:#c4b5fd;','staff'=>'background:rgba(16,185,129,.2);color:#6ee7b7;','cashier'=>'background:rgba(245,158,11,.2);color:#fcd34d;'];
        $rbadge = $role_colors[$a['actor_role']??''] ?? 'background:rgba(255,255,255,.1);color:rgba(255,255,255,.5);';
      ?>
      <tr>
        <td style="font-size:.72rem;color:rgba(255,255,255,.35);white-space:nowrap;"><?=date('M d, Y h:i A',strtotime($a['created_at']))?></td>
        <td style="font-weight:600;color:#fff;font-size:.78rem;"><?=htmlspecialchars(ucfirst($a['actor_username']??''))?></td>
        <td><span style="font-size:.62rem;font-weight:700;padding:2px 8px;border-radius:100px;text-transform:uppercase;letter-spacing:.05em;<?=$rbadge?>"><?=$a['actor_role']??''?></span></td>
        <td style="font-family:monospace;font-size:.72rem;color:#fcd34d;"><?=htmlspecialchars($a['action']??'')?></td>
        <td><span class="ticket-tag" style="font-size:.72rem;"><?=htmlspecialchars($a['entity_id']??'—')?></span></td>
        <td style="font-size:.75rem;color:rgba(255,255,255,.45);max-width:320px;"><?=htmlspecialchars($a['message']??'')?></td>
      </tr>
      <?php endforeach;?></tbody></table>
      <?php endif;?>
    </div>

  <?php elseif($active_page==='settings'): ?>
    <form method="POST" enctype="multipart/form-data">
      <input type="hidden" name="action" value="save_theme">
      <div class="theme-grid">

        <div>
          <div class="card" style="margin-bottom:16px;">
            <div class="card-hdr"><span class="card-title">🎨 Color Scheme</span></div>
            <div style="margin-bottom:18px;">
              <div class="flabel" style="margin-bottom:8px;">Quick Presets</div>
              <div class="preset-row">
                <div class="preset" style="background:#2563eb;" onclick="applyPreset('#2563eb','#1e3a8a','#10b981','#0f172a')" title="Blue"></div>
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
              <div><label class="flabel">Primary Color</label><div class="color-picker-wrap"><input type="color" name="primary_color" id="cp_primary" value="<?=htmlspecialchars($theme['primary_color']??'#2563eb')?>" oninput="updatePreview()"><div class="color-preview" id="prev_primary" style="background:<?=htmlspecialchars($theme['primary_color']??'#2563eb')?>;">Primary</div></div></div>
              <div><label class="flabel">Secondary Color</label><div class="color-picker-wrap"><input type="color" name="secondary_color" id="cp_secondary" value="<?=htmlspecialchars($theme['secondary_color']??'#1e3a8a')?>" oninput="updatePreview()"><div class="color-preview" id="prev_secondary" style="background:<?=htmlspecialchars($theme['secondary_color']??'#1e3a8a')?>;">Secondary</div></div></div>
              <div><label class="flabel">Accent Color</label><div class="color-picker-wrap"><input type="color" name="accent_color" id="cp_accent" value="<?=htmlspecialchars($theme['accent_color']??'#10b981')?>" oninput="updatePreview()"><div class="color-preview" id="prev_accent" style="background:<?=htmlspecialchars($theme['accent_color']??'#10b981')?>;">Accent</div></div></div>
              <div><label class="flabel">Sidebar Color</label><div class="color-picker-wrap"><input type="color" name="sidebar_color" id="cp_sidebar" value="<?=htmlspecialchars($theme['sidebar_color']??'#0f172a')?>" oninput="updatePreview()"><div class="color-preview" id="prev_sidebar" style="background:<?=htmlspecialchars($theme['sidebar_color']??'#0f172a')?>;">Sidebar</div></div></div>
            </div>
          </div>

          <div class="card">
            <div class="card-hdr"><span class="card-title">🏷️ Branding</span></div>
            <div style="margin-bottom:12px;"><label class="flabel">System Name (title & browser tab)</label><input type="text" name="system_name" class="finput" placeholder="PawnHub" value="<?=htmlspecialchars($theme['system_name']??'PawnHub')?>"></div>
            <div style="margin-bottom:12px;"><label class="flabel">Logo Text (shown in sidebar)</label><input type="text" name="logo_text" class="finput" placeholder="e.g. GoldKing" value="<?=htmlspecialchars($theme['logo_text']??'')?>"></div>
            <div>
              <label class="flabel">Logo Image</label>
              <?php if($logo_url): ?>
              <div style="display:flex;align-items:center;gap:10px;margin-bottom:10px;background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.1);border-radius:10px;padding:10px 13px;">
                <img src="<?=htmlspecialchars($logo_url)?>" style="width:40px;height:40px;object-fit:cover;border-radius:8px;">
                <div style="flex:1;"><div style="font-size:.76rem;font-weight:600;color:rgba(255,255,255,.6);">Current Logo</div><div style="font-size:.69rem;color:rgba(255,255,255,.3);">Upload a new one to replace</div></div>
                <label style="display:inline-flex;align-items:center;gap:4px;cursor:pointer;font-size:.7rem;color:#fca5a5;font-weight:600;"><input type="checkbox" name="remove_logo" value="1" style="margin:0;"> Remove</label>
              </div>
              <?php endif; ?>
              <div id="logo-drop-zone" style="border:2px dashed rgba(255,255,255,.12);border-radius:12px;padding:24px;text-align:center;cursor:pointer;transition:all .2s;background:rgba(255,255,255,.03);" onclick="document.getElementById('logo_file_input').click()" ondragover="event.preventDefault();this.style.borderColor='var(--t-primary,#3b82f6)';" ondragleave="this.style.borderColor='rgba(255,255,255,.12)'" ondrop="handleLogoDrop(event)">
                <div id="logo-preview-wrap" style="display:none;margin-bottom:10px;"><img id="logo-preview-img" style="width:60px;height:60px;object-fit:cover;border-radius:10px;border:1px solid rgba(255,255,255,.1);margin:0 auto;display:block;"></div>
                <span class="material-symbols-outlined" style="font-size:28px;color:rgba(255,255,255,.25);display:block;margin-bottom:8px;">upload</span>
                <div style="font-size:.8rem;font-weight:600;color:rgba(255,255,255,.5);margin-bottom:3px;">Click to upload or drag & drop</div>
                <div style="font-size:.71rem;color:rgba(255,255,255,.25);">PNG, JPG, WebP, SVG · Max 2MB</div>
                <input type="file" id="logo_file_input" name="logo_file" accept="image/*" style="display:none;" onchange="previewLogo(this)">
              </div>
            </div>
          </div>

          <!-- Background Image Card -->
          <div class="card">
            <div class="card-hdr"><span class="card-title">🖼️ Dashboard & Login Background</span></div>
            <div style="font-size:.76rem;color:rgba(255,255,255,.4);margin-bottom:12px;line-height:1.6;">
              This image appears as the background on the <strong style="color:rgba(255,255,255,.6);">Login Page</strong>, <strong style="color:rgba(255,255,255,.6);">Staff Dashboard</strong>, and <strong style="color:rgba(255,255,255,.6);">Cashier Dashboard</strong>.
            </div>

            <?php
              $currentBg = $tenant['bg_image_url'] ?? '';
            if ($currentBg && strpos($currentBg,'http') !== 0 && $currentBg[0] !== '/') $currentBg = '/' . $currentBg;
            ?>
            <?php if($currentBg): ?>
            <div style="position:relative;border-radius:10px;overflow:hidden;margin-bottom:10px;height:120px;border:1px solid rgba(255,255,255,.1);">
              <img src="<?=htmlspecialchars($currentBg)?>" style="width:100%;height:100%;object-fit:cover;display:block;">
              <div style="position:absolute;inset:0;background:rgba(0,0,0,.35);display:flex;align-items:center;justify-content:center;">
                <span style="color:#fff;font-size:.76rem;font-weight:600;background:rgba(0,0,0,.5);padding:4px 12px;border-radius:100px;">Current Background</span>
              </div>
            </div>
            <label style="display:inline-flex;align-items:center;gap:6px;cursor:pointer;font-size:.72rem;color:#fca5a5;font-weight:600;margin-bottom:10px;">
              <input type="checkbox" name="remove_bg" value="1" style="margin:0;accent-color:#ef4444;">
              Remove current background
            </label>
            <?php endif; ?>

            <div style="border:2px dashed rgba(255,255,255,.12);border-radius:12px;padding:24px;text-align:center;cursor:pointer;transition:all .2s;background:rgba(255,255,255,.03);"
              onclick="document.getElementById('bg_file_input').click()"
              ondragover="event.preventDefault();this.style.borderColor='var(--t-primary,#3b82f6)';"
              ondragleave="this.style.borderColor='rgba(255,255,255,.12)'"
              ondrop="handleBgDrop(event)">
              <div id="bg-preview-wrap" style="display:none;margin-bottom:10px;">
                <img id="bg-preview-img" style="width:100%;max-height:100px;object-fit:cover;border-radius:8px;border:1px solid rgba(255,255,255,.1);">
              </div>
              <span class="material-symbols-outlined" style="font-size:28px;color:rgba(255,255,255,.25);display:block;margin-bottom:8px;">image</span>
              <div style="font-size:.8rem;font-weight:600;color:rgba(255,255,255,.5);margin-bottom:3px;">Click to upload or drag & drop</div>
              <div style="font-size:.71rem;color:rgba(255,255,255,.25);">PNG, JPG, WebP · Recommended 1920×1080 · Max 5MB</div>
              <input type="file" id="bg_file_input" name="bg_file" accept="image/jpeg,image/png,image/webp" style="display:none;" onchange="previewBg(this)">
            </div>
          </div>
        </div>

        <div>
          <div class="card" style="margin-bottom:16px;">
            <div class="card-hdr"><span class="card-title">👁️ Live Preview</span></div>
            <div id="theme-preview-box" style="border-radius:14px;overflow:hidden;border:1px solid rgba(255,255,255,.1);">
              <div id="prev_sidebar_box" style="background:linear-gradient(135deg,<?=htmlspecialchars($theme['sidebar_color']??'#0f172a')?>,<?=htmlspecialchars($theme['secondary_color']??'#1e3a8a')?>);padding:16px;">
                <div style="display:flex;align-items:center;gap:9px;margin-bottom:12px;">
                  <div id="prev_logo_box" style="width:32px;height:32px;border-radius:9px;background:linear-gradient(135deg,<?=htmlspecialchars($theme['primary_color']??'#3b82f6')?>,<?=htmlspecialchars($theme['secondary_color']??'#8b5cf6')?>);display:flex;align-items:center;justify-content:center;">
                    <svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2.5" style="width:16px;height:16px;"><rect x="3" y="9" width="18" height="12"/><polyline points="3 9 12 3 21 9"/></svg>
                  </div>
                  <div><div style="font-size:.84rem;font-weight:800;color:#fff;" id="prev_sysname"><?=htmlspecialchars($theme['system_name']??'PawnHub')?></div><div style="font-size:.61rem;color:rgba(255,255,255,.35);">Admin Terminal</div></div>
                </div>
                <div style="display:flex;flex-direction:column;gap:4px;">
                  <div id="prev_active_item" style="display:flex;align-items:center;gap:7px;padding:8px 10px;border-radius:8px;background:rgba(255,255,255,.15);color:#fff;font-size:.78rem;font-weight:600;">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:13px;height:13px;"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/></svg>Dashboard
                  </div>
                  <div style="display:flex;align-items:center;gap:7px;padding:8px 10px;border-radius:8px;color:rgba(255,255,255,.4);font-size:.78rem;">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:13px;height:13px;"><path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/></svg>Pawn Tickets
                  </div>
                </div>
              </div>
              <div style="padding:14px;background:rgba(255,255,255,.03);">
                <div id="prev_btn" style="display:inline-flex;align-items:center;gap:5px;padding:7px 13px;border-radius:8px;background:<?=htmlspecialchars($theme['primary_color']??'#2563eb')?>;color:#fff;font-size:.77rem;font-weight:700;">
                  + Add Staff / Cashier
                </div>
                <div style="margin-top:11px;background:rgba(255,255,255,.04);border-radius:8px;padding:11px 13px;border:1px solid rgba(255,255,255,.07);">
                  <div style="font-size:.66rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:rgba(255,255,255,.3);margin-bottom:6px;">Sample Ticket</div>
                  <div style="display:flex;align-items:center;gap:8px;">
                    <span style="font-family:monospace;font-size:.76rem;font-weight:700;color:<?=htmlspecialchars($theme['primary_color']??'#2563eb')?>" id="prev_ticket_tag">TP-20240314-AB1C</span>
                    <span style="font-size:.65rem;background:rgba(59,130,246,.2);color:#93c5fd;padding:2px 7px;border-radius:100px;font-weight:700;">Stored</span>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <div style="background:rgba(59,130,246,.08);border:1px solid rgba(59,130,246,.2);border-radius:12px;padding:14px 16px;font-size:.78rem;color:rgba(147,197,253,.8);margin-bottom:16px;line-height:1.7;">
            ℹ️ <strong style="color:#93c5fd;">How it works:</strong><br>
            When you save, your Staff and Cashier dashboards will automatically use these colors and branding.
          </div>

          <button type="submit" style="width:100%;background:linear-gradient(135deg,var(--t-primary,#2563eb),var(--t-secondary,#1d4ed8));color:#fff;border:none;border-radius:12px;padding:14px;font-family:inherit;font-size:.94rem;font-weight:700;cursor:pointer;box-shadow:0 4px 20px rgba(37,99,235,.35);">
            💾 Save Theme & Branding
          </button>
        </div>
      </div>
    </form>

  <?php elseif($active_page==='export'): ?>
    <?php
      $exp_type      = $_GET['exp_type'] ?? 'tickets';
      $exp_from      = $_GET['exp_from'] ?? date('Y-m-01');
      $exp_to        = $_GET['exp_to']   ?? date('Y-m-d');
      $valid_exp_types = ['tickets','customers','inventory','audit','payments'];
      if (!in_array($exp_type, $valid_exp_types)) $exp_type = 'tickets';

      $exp_rows = []; $exp_cols = []; $exp_title = '';
      try {
        switch ($exp_type) {
          case 'tickets':
            $exp_title = 'Pawn Tickets';
            $exp_cols  = ['Ticket No.','Customer','Contact','Category','Description','Loan Amount','Total Redeem','Maturity Date','Status','Date'];
            $s = $pdo->prepare("SELECT ticket_no,customer_name,contact_number,item_category,item_description,loan_amount,total_redeem,maturity_date,status,created_at FROM pawn_transactions WHERE tenant_id=? AND DATE(created_at) BETWEEN ? AND ? ORDER BY created_at DESC");
            $s->execute([$tid,$exp_from,$exp_to]); $exp_rows=$s->fetchAll(); break;
          case 'customers':
            $exp_title = 'Customer Records';
            $exp_cols  = ['Full Name','Contact','Email','Gender','Address','ID Type','ID Number','Registered'];
            $s = $pdo->prepare("SELECT full_name,contact_number,email,gender,address,valid_id_type,valid_id_number,created_at FROM customers WHERE tenant_id=? AND DATE(created_at) BETWEEN ? AND ? ORDER BY full_name");
            $s->execute([$tid,$exp_from,$exp_to]); $exp_rows=$s->fetchAll(); break;
          case 'inventory':
            $exp_title = 'Item Inventory';
            $exp_cols  = ['Ticket No.','Item','Category','Serial No.','Condition','Appraisal','Loan Amount','Status','Date'];
            $s = $pdo->prepare("SELECT ticket_no,item_name,item_category,serial_no,condition_notes,appraisal_value,loan_amount,status,created_at FROM item_inventory WHERE tenant_id=? AND DATE(created_at) BETWEEN ? AND ? ORDER BY created_at DESC");
            $s->execute([$tid,$exp_from,$exp_to]); $exp_rows=$s->fetchAll(); break;
          case 'audit':
            $exp_title = 'Audit Logs';
            $exp_cols  = ['Date & Time','Actor','Role','Action','Ref #','Message'];
            $s = $pdo->prepare("SELECT created_at,actor_username,actor_role,action,entity_id,message FROM audit_logs WHERE tenant_id=? AND actor_role IN ('manager','staff','cashier') AND DATE(created_at) BETWEEN ? AND ? ORDER BY created_at DESC");
            $s->execute([$tid,$exp_from,$exp_to]); $exp_rows=$s->fetchAll(); break;
          case 'payments':
            $exp_title = 'Payment History';
            $exp_cols  = ['Date','Ticket No.','Action','OR No.','Amount Due','Cash Received','Change','Staff'];
            $s = $pdo->prepare("SELECT created_at,ticket_no,action,or_no,amount_due,cash_received,change_amount,staff_username FROM payment_transactions WHERE tenant_id=? AND DATE(created_at) BETWEEN ? AND ? ORDER BY created_at DESC");
            $s->execute([$tid,$exp_from,$exp_to]); $exp_rows=$s->fetchAll(); break;
        }
      } catch(Throwable $e) { $error_msg = 'Export error: '.$e->getMessage(); }
      $exp_primary = $theme['primary_color'] ?? '#2563eb';
      $exp_secondary = $theme['secondary_color'] ?? '#1e3a8a';
    ?>
    <style>
      @media print {
        .sidebar,.topbar,.content > .alert,.export-controls,.sb-footer { display:none !important; }
        .main { margin-left:0 !important; }
        .export-doc { box-shadow:none !important; border-radius:0 !important; }
      }
    </style>
    <!-- Controls -->
    <div class="export-controls" style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:18px;">
      <form method="GET" id="exp-form" style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;flex:1;">
        <input type="hidden" name="page" value="export">
        <div>
          <div style="font-size:.62rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:rgba(255,255,255,.35);margin-bottom:4px;">Report Type</div>
          <select name="exp_type" class="finput" style="width:auto;padding:7px 12px;font-size:.82rem;" onchange="document.getElementById('exp-form').submit()">
            <option value="tickets"   <?=$exp_type==='tickets'  ?'selected':''?>>📋 Pawn Tickets</option>
            <option value="customers" <?=$exp_type==='customers'?'selected':''?>>👥 Customers</option>
            <option value="inventory" <?=$exp_type==='inventory'?'selected':''?>>📦 Inventory</option>
            <option value="audit"     <?=$exp_type==='audit'    ?'selected':''?>>🔍 Audit Logs</option>
            <option value="payments"  <?=$exp_type==='payments' ?'selected':''?>>💳 Payment History</option>
          </select>
        </div>
        <div>
          <div style="font-size:.62rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:rgba(255,255,255,.35);margin-bottom:4px;">Date From</div>
          <input type="date" name="exp_from" class="finput" style="width:auto;padding:7px 12px;font-size:.82rem;" value="<?=htmlspecialchars($exp_from)?>" onchange="document.getElementById('exp-form').submit()">
        </div>
        <div>
          <div style="font-size:.62rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:rgba(255,255,255,.35);margin-bottom:4px;">Date To</div>
          <input type="date" name="exp_to" class="finput" style="width:auto;padding:7px 12px;font-size:.82rem;" value="<?=htmlspecialchars($exp_to)?>" onchange="document.getElementById('exp-form').submit()">
        </div>
      </form>
      <button onclick="window.print()" style="padding:10px 22px;background:linear-gradient(135deg,<?=$exp_secondary?>,<?=$exp_primary?>);color:#fff;border:none;border-radius:10px;font-size:.85rem;font-weight:700;cursor:pointer;display:flex;align-items:center;gap:7px;font-family:inherit;white-space:nowrap;">
        <span class="material-symbols-outlined" style="font-size:17px;">print</span>Print / Save as PDF
      </button>
    </div>

    <!-- Export Document -->
    <div class="export-doc card" style="padding:0;overflow:hidden;">
      <!-- Doc Header -->
      <div style="background:linear-gradient(135deg,<?=$exp_secondary?>,<?=$exp_primary?>);padding:24px 28px;display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:10px;">
        <div>
          <div style="font-size:1.1rem;font-weight:800;color:#fff;"><?=htmlspecialchars($business_name)?></div>
          <div style="font-size:.72rem;color:rgba(255,255,255,.6);margin-top:3px;">PawnHub — Branch Report</div>
          <?php if(!empty($tenant['phone'])):?><div style="font-size:.7rem;color:rgba(255,255,255,.5);margin-top:6px;">📞 <?=htmlspecialchars($tenant['phone'])?></div><?php endif;?>
          <?php if(!empty($tenant['address'])):?><div style="font-size:.7rem;color:rgba(255,255,255,.5);margin-top:2px;">📍 <?=htmlspecialchars($tenant['address'])?></div><?php endif;?>
        </div>
        <div style="text-align:right;">
          <div style="font-size:1.3rem;font-weight:800;color:#fff;"><?=htmlspecialchars($exp_title)?></div>
          <div style="font-size:.72rem;color:rgba(255,255,255,.6);margin-top:3px;">📅 <?=date('M d, Y',strtotime($exp_from))?> — <?=date('M d, Y',strtotime($exp_to))?></div>
          <div style="font-size:.67rem;color:rgba(255,255,255,.4);margin-top:2px;">Generated: <?=date('F j, Y g:i A')?></div>
        </div>
      </div>
      <!-- Meta -->
      <div style="padding:10px 24px;background:rgba(255,255,255,.03);border-bottom:1px solid rgba(255,255,255,.06);display:flex;gap:20px;flex-wrap:wrap;">
        <span style="font-size:.74rem;color:rgba(255,255,255,.4);">Total Records: <strong style="color:#fff;"><?=count($exp_rows)?></strong></span>
        <span style="font-size:.74rem;color:rgba(255,255,255,.4);">Prepared by: <strong style="color:#fff;"><?=htmlspecialchars($u['name'])?></strong> (<?=ucfirst($u['role'])?>)</span>
        <span style="font-size:.74rem;color:rgba(255,255,255,.4);">Branch: <strong style="color:#fff;"><?=htmlspecialchars($business_name)?></strong></span>
      </div>
      <!-- Table -->
      <div style="padding:16px 20px;overflow-x:auto;">
        <?php if(empty($exp_rows)):?>
        <div class="empty-state"><span class="material-symbols-outlined">inbox</span><p>No <?=strtolower($exp_title)?> found for the selected period.</p></div>
        <?php else:?>
        <table>
          <thead><tr><?php foreach($exp_cols as $c):?><th><?=htmlspecialchars($c)?></th><?php endforeach;?></tr></thead>
          <tbody>
          <?php foreach($exp_rows as $row): $vals=array_values($row); ?>
          <tr>
            <?php foreach($vals as $i=>$val):
              $col = strtolower($exp_cols[$i] ?? '');
              if(str_contains($col,'ticket no')):
                echo '<td><span class="ticket-tag">'.htmlspecialchars($val??'—').'</span></td>';
              elseif(str_contains($col,'status')):
                $sc=['stored'=>'b-blue','released'=>'b-green','renewed'=>'b-yellow','voided'=>'b-red','auctioned'=>'b-purple','pawned'=>'b-blue','redeemed'=>'b-green'];
                $cls=$sc[strtolower($val??'')] ?? 'b-gray';
                echo '<td><span class="badge '.$cls.'">'.htmlspecialchars($val??'—').'</span></td>';
              elseif(str_contains($col,'amount')||str_contains($col,'loan')||str_contains($col,'redeem')||str_contains($col,'cash')||str_contains($col,'change')||str_contains($col,'appraisal')):
                echo '<td>₱'.number_format((float)($val??0),2).'</td>';
              elseif(str_contains($col,'date')||str_contains($col,'registered')||str_contains($col,'time')||str_contains($col,'at')):
                echo '<td style="font-size:.73rem;color:rgba(255,255,255,.4);">'.($val ? date(str_contains($col,'time')?'M d, Y h:i A':'M d, Y',strtotime($val)) : '—').'</td>';
              else:
                echo '<td>'.htmlspecialchars($val??'—').'</td>';
              endif;
            endforeach;?>
          </tr>
          <?php endforeach;?>
          </tbody>
        </table>
        <?php endif;?>
      </div>
      <!-- Footer -->
      <div style="padding:12px 24px;border-top:1px solid rgba(255,255,255,.06);display:flex;justify-content:space-between;font-size:.69rem;color:rgba(255,255,255,.25);">
        <span>© <?=date('Y')?> <?=htmlspecialchars($business_name)?> · Powered by PawnHub</span>
        <span><?=count($exp_rows)?> record<?=count($exp_rows)!==1?'s':''?> · <?=date('F j, Y g:i A')?></span>
      </div>
    </div>

  <?php elseif($active_page==='applicants'): ?>
    <?php
      $applicants_stmt = $pdo->prepare("SELECT * FROM tenant_applicants WHERE tenant_id=? ORDER BY FIELD(status,'pending','approved','rejected'), applied_at DESC");
      $applicants_stmt->execute([$tid]); $all_applicants = $applicants_stmt->fetchAll();
      $pend_cnt = count(array_filter($all_applicants, fn($a)=>$a['status']==='pending'));
    ?>
    <?php if($pend_cnt > 0): ?>
    <div style="background:linear-gradient(135deg,rgba(245,158,11,.1),rgba(234,88,12,.07));border:1px solid rgba(245,158,11,.25);border-radius:12px;padding:12px 18px;margin-bottom:18px;display:flex;align-items:center;gap:10px;">
      <span class="material-symbols-outlined" style="color:#fcd34d;font-size:20px;">pending_actions</span>
      <span style="font-size:.82rem;font-weight:600;color:#fcd34d;"><?= $pend_cnt ?> pending application<?= $pend_cnt!==1?'s':''?> awaiting your review.</span>
    </div>
    <?php endif; ?>
    <div class="card" style="overflow-x:auto;">
      <?php if(empty($all_applicants)):?>
      <div class="empty-state">
        <span class="material-symbols-outlined">person_search</span>
        <p>No applicants yet. When someone applies from your public page, they'll appear here.</p>
      </div>
      <?php else:?>
      <table>
        <thead><tr>
          <th>Applicant</th><th>Role</th><th>Contact</th><th>Note</th>
          <th>Resume / Photo</th><th>Status</th><th>Applied</th><th>Actions</th>
        </tr></thead>
        <tbody>
        <?php foreach($all_applicants as $ap):
          $role_badge = match($ap['role']) { 'manager'=>'b-green','cashier'=>'b-purple',default=>'b-blue' };
          $st_badge   = match($ap['status']) { 'approved'=>'b-green','rejected'=>'b-red',default=>'b-yellow' };
          $st_icon    = match($ap['status']) { 'approved'=>'check_circle','rejected'=>'cancel',default=>'pending' };
        ?>
        <tr>
          <td>
            <div style="display:flex;align-items:center;gap:9px;">
              <div style="width:32px;height:32px;border-radius:50%;background:rgba(255,255,255,.08);display:flex;align-items:center;justify-content:center;font-size:.76rem;font-weight:700;color:#fff;flex-shrink:0;">
                <?= strtoupper(substr($ap['fullname'],0,1)) ?>
              </div>
              <div>
                <div style="font-weight:700;color:#fff;font-size:.84rem;"><?= htmlspecialchars($ap['fullname']) ?></div>
                <div style="font-size:.72rem;color:rgba(255,255,255,.4);"><?= htmlspecialchars($ap['email']) ?></div>
                <div style="font-family:monospace;font-size:.7rem;color:var(--t-primary,#60a5fa);"><?= htmlspecialchars($ap['username']) ?></div>
              </div>
            </div>
          </td>
          <td><span class="badge <?= $role_badge ?>"><?= ucfirst($ap['role']) ?></span></td>
          <td style="font-family:monospace;font-size:.74rem;"><?= htmlspecialchars($ap['contact_number']??'—') ?></td>
          <td style="font-size:.77rem;color:rgba(255,255,255,.5);max-width:180px;">
            <?= $ap['note'] ? htmlspecialchars(mb_strimwidth($ap['note'],0,80,'…')) : '<span style="color:rgba(255,255,255,.2);">—</span>' ?>
          </td>
          <td>
            <?php if($ap['resume_path']): ?>
              <a href="<?= htmlspecialchars($ap['resume_path']) ?>" target="_blank" class="btn-sm" style="font-size:.7rem;">
                <span class="material-symbols-outlined" style="font-size:13px;">open_in_new</span>View
              </a>
            <?php else: ?>
              <span style="font-size:.74rem;color:rgba(255,255,255,.25);">—</span>
            <?php endif; ?>
          </td>
          <td>
            <span class="badge <?= $st_badge ?>">
              <span class="material-symbols-outlined" style="font-size:12px;font-variation-settings:'FILL' 1,'wght' 400,'GRAD' 0,'opsz' 24;"><?= $st_icon ?></span>
              <?= ucfirst($ap['status']) ?>
            </span>
          </td>
          <td style="font-size:.72rem;color:rgba(255,255,255,.35);white-space:nowrap;"><?= date('M d, Y', strtotime($ap['applied_at'])) ?></td>
          <td>
            <?php if($ap['status']==='pending'): ?>
            <form method="POST" style="display:inline;">
              <input type="hidden" name="action" value="approve_applicant">
              <input type="hidden" name="applicant_id" value="<?= $ap['id'] ?>">
              <button type="submit" class="btn-sm btn-success" onclick="return confirm('Approve <?= addslashes(htmlspecialchars($ap['fullname'])) ?> as <?= $ap['role'] ?>? This will create their login account.')" style="font-size:.7rem;">
                <span class="material-symbols-outlined" style="font-size:13px;">check</span>Approve
              </button>
            </form>
            <form method="POST" style="display:inline;">
              <input type="hidden" name="action" value="reject_applicant">
              <input type="hidden" name="applicant_id" value="<?= $ap['id'] ?>">
              <button type="submit" class="btn-sm btn-danger" onclick="return confirm('Reject this application?')" style="font-size:.7rem;">
                <span class="material-symbols-outlined" style="font-size:13px;">close</span>Reject
              </button>
            </form>
            <?php else: ?>
              <span style="font-size:.72rem;color:rgba(255,255,255,.25);">Decided</span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>

  <?php endif;?>
  </div>
</div>

<!-- INVITE MODAL -->
<div class="modal-overlay" id="addUserModal">
  <div class="modal">
    <div class="mhdr">
      <div class="mtitle">Invite Branch Manager</div>
      <button class="mclose" onclick="document.getElementById('addUserModal').classList.remove('open')">
        <span class="material-symbols-outlined">close</span>
      </button>
    </div>
    <div class="mbody">
      <form method="POST">
        <input type="hidden" name="action" value="invite_staff">
        <input type="hidden" name="role" value="manager">
        <div style="margin-bottom:12px;">
          <label class="flabel">Role</label>
          <div style="display:flex;align-items:center;gap:8px;background:rgba(16,185,129,.08);border:1px solid rgba(16,185,129,.25);border-radius:10px;padding:10px 14px;">
            <span class="material-symbols-outlined" style="font-size:18px;color:#6ee7b7;">manage_accounts</span>
            <span style="font-size:.85rem;font-weight:700;color:#6ee7b7;">Branch Manager</span>
          </div>
        </div>
        <div style="margin-bottom:12px;"><label class="flabel">Full Name *</label><input type="text" name="name" class="finput" placeholder="Maria Santos" required></div>
        <div style="margin-bottom:14px;"><label class="flabel">Email Address *</label><input type="email" name="email" class="finput" placeholder="manager@example.com" required><div style="font-size:.71rem;color:rgba(255,255,255,.25);margin-top:5px;">An invitation link will be sent to this email.</div></div>
        <div style="background:rgba(16,185,129,.08);border:1px solid rgba(16,185,129,.2);border-radius:10px;padding:11px 13px;font-size:.76rem;color:rgba(110,231,183,.8);margin-bottom:14px;line-height:1.6;">
          📧 The Manager will receive an email to set up their account credentials.<br>
          <strong style="color:rgba(110,231,183,1);">Manager</strong> — can invite and manage their own staff &amp; cashiers.
        </div>
        <div style="display:flex;justify-content:flex-end;gap:9px;">
          <button type="button" class="btn-sm" onclick="document.getElementById('addUserModal').classList.remove('open')">Cancel</button>
          <button type="submit" class="btn-sm btn-primary">Send Manager Invitation</button>
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

  document.getElementById('prev_sidebar_box').style.background = `linear-gradient(135deg,${sb},${s})`;
  document.getElementById('prev_logo_box').style.background    = `linear-gradient(135deg,${p},${s})`;
  document.getElementById('prev_btn').style.background         = p;
  document.getElementById('prev_ticket_tag').style.color       = p;

  document.documentElement.style.setProperty('--t-primary',   p);
  document.documentElement.style.setProperty('--t-secondary', s);
  document.documentElement.style.setProperty('--t-accent',    a);
  document.documentElement.style.setProperty('--t-sidebar',   sb);
}

function applyPreset(p, s, a, sb) {
  document.getElementById('cp_primary').value   = p;
  document.getElementById('cp_secondary').value = s;
  document.getElementById('cp_accent').value    = a;
  document.getElementById('cp_sidebar').value   = sb;
  updatePreview();
}

function previewLogo(input) {
  if (input.files && input.files[0]) {
    const reader = new FileReader();
    reader.onload = e => {
      document.getElementById('logo-preview-img').src = e.target.result;
      document.getElementById('logo-preview-wrap').style.display = 'block';
      document.getElementById('prev_logo_box').innerHTML = '<img src="' + e.target.result + '" style="width:100%;height:100%;object-fit:cover;border-radius:9px;">';
    };
    reader.readAsDataURL(input.files[0]);
  }
}
function handleLogoDrop(e) {
  e.preventDefault();
  document.getElementById('logo-drop-zone').style.borderColor = 'rgba(255,255,255,.12)';
  const file = e.dataTransfer.files[0];
  if (file && file.type.startsWith('image/')) {
    const dt = new DataTransfer();
    dt.items.add(file);
    document.getElementById('logo_file_input').files = dt.files;
    previewLogo(document.getElementById('logo_file_input'));
  }
}

function previewBg(input) {
  if (input.files && input.files[0]) {
    const reader = new FileReader();
    reader.onload = e => {
      document.getElementById('bg-preview-img').src = e.target.result;
      document.getElementById('bg-preview-wrap').style.display = 'block';
    };
    reader.readAsDataURL(input.files[0]);
  }
}
function handleBgDrop(e) {
  e.preventDefault();
  e.currentTarget.style.borderColor = 'rgba(255,255,255,.12)';
  const file = e.dataTransfer.files[0];
  if (file && (file.type === 'image/jpeg' || file.type === 'image/png' || file.type === 'image/webp')) {
    const dt = new DataTransfer();
    dt.items.add(file);
    document.getElementById('bg_file_input').files = dt.files;
    previewBg(document.getElementById('bg_file_input'));
  }
}

document.querySelector('input[name="system_name"]')?.addEventListener('input', function() {
  document.getElementById('prev_sysname').textContent = this.value || 'PawnHub';
});
</script>
</body>
</html>