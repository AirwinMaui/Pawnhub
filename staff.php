<?php
session_start();
require 'db.php';
require 'theme_helper.php';

// ── Audit Helper ─────────────────────────────────────────────
function write_audit(PDO $pdo, $aid, $aun, $ar, string $action, string $et='', string $ei='', string $msg='', $tid=null): void {
    try { $pdo->prepare("INSERT INTO audit_logs (tenant_id,actor_user_id,actor_username,actor_role,action,entity_type,entity_id,message,ip_address,created_at) VALUES (?,?,?,?,?,?,?,?,?,NOW())")->execute([$tid,$aid,$aun,$ar,$action,$et,$ei,$msg,$_SERVER['REMOTE_ADDR']??'::1']); } catch(PDOException $e){}
}

if (empty($_SESSION['user'])) { header('Location: login.php'); exit; }
$u = $_SESSION['user'];
if ($u['role'] !== 'staff') { header('Location: login.php'); exit; }

$tid         = $u['tenant_id'];
$active_page = $_GET['page'] ?? 'dashboard';
$success_msg = '';
$error_msg   = '';

// Load tenant theme
$theme     = getTenantTheme($pdo, $tid);
$sys_name  = $theme['system_name'] ?? 'PawnHub';
$logo_text = $theme['logo_text'] ?: $sys_name;
$logo_url  = $theme['logo_url']  ?? '';

// ── Fetch tenant info ─────────────────────────────────────────
$tenant = null;
if ($tid) {
    $stmt = $pdo->prepare("SELECT * FROM tenants WHERE id = ?");
    $stmt->execute([$tid]);
    $tenant = $stmt->fetch();
}

// ── POST Actions ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    // Register Customer
    if ($_POST['action'] === 'register_customer') {
        $full_name    = trim($_POST['full_name']    ?? '');
        $contact      = trim($_POST['contact_number'] ?? '');
        $email        = trim($_POST['email']        ?? '');
        $birthdate    = trim($_POST['birthdate']    ?? '');
        $address      = trim($_POST['address']      ?? '');
        $gender       = trim($_POST['gender']       ?? '');
        $nationality  = trim($_POST['nationality']  ?? 'Filipino');
        $birthplace   = trim($_POST['birthplace']   ?? '');
        $src_income   = trim($_POST['source_of_income'] ?? '');
        $nature_work  = trim($_POST['nature_of_work']   ?? '');
        $occupation   = trim($_POST['occupation']   ?? '');
        $business     = trim($_POST['business_office_school'] ?? '');
        $id_type      = trim($_POST['valid_id_type'] ?? '');
        $id_number    = trim($_POST['valid_id_number'] ?? '');

        if ($full_name && $contact) {
            $pdo->prepare("INSERT INTO customers (tenant_id,full_name,contact_number,email,birthdate,address,gender,nationality,birthplace,source_of_income,nature_of_work,occupation,business_office_school,valid_id_type,valid_id_number,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
                ->execute([$tid,$full_name,$contact,$email,$birthdate?:null,$address,$gender,$nationality,$birthplace,$src_income,$nature_work,$occupation,$business,$id_type,$id_number,$u['id']]);
            $success_msg = "Customer \"$full_name\" registered successfully!";
            $active_page = 'customers';
        } else {
            $error_msg = 'Full name and contact number are required.';
        }
    }

    // Create Pawn Ticket
    if ($_POST['action'] === 'create_ticket') {
        $customer_name  = trim($_POST['customer_name']   ?? '');
        $contact_number = trim($_POST['contact_number']  ?? '');
        $email          = trim($_POST['email']           ?? '');
        $address        = trim($_POST['address']         ?? '');
        $birthdate      = trim($_POST['birthdate']       ?? '');
        $gender         = trim($_POST['gender']          ?? 'Male');
        $nationality    = trim($_POST['nationality']     ?? 'Filipino');
        $birthplace     = trim($_POST['birthplace']      ?? '');
        $src_income     = trim($_POST['source_of_income']?? '');
        $nature_work    = trim($_POST['nature_of_work']  ?? '');
        $occupation     = trim($_POST['occupation']      ?? '');
        $business       = trim($_POST['business_office_school'] ?? '');
        $valid_id_type  = trim($_POST['valid_id_type']   ?? '');
        $valid_id_no    = trim($_POST['valid_id_number'] ?? '');
        $item_category  = trim($_POST['item_category']   ?? '');
        $item_desc      = trim($_POST['item_description']?? '');
        $item_condition = trim($_POST['item_condition']  ?? 'Excellent');
        $item_weight    = floatval($_POST['item_weight'] ?? 0);
        $item_karat     = trim($_POST['item_karat']      ?? '');
        $serial_number  = trim($_POST['serial_number']   ?? '');
        $appraisal      = floatval($_POST['appraisal_value'] ?? 0);
        $loan_amount    = floatval($_POST['loan_amount']     ?? 0);
        $interest_rate  = floatval($_POST['interest_rate']   ?? 0.02);
        $claim_term     = trim($_POST['claim_term']      ?? '1-15');

        $term_days = match($claim_term) {
            '1-15'  => 15, '16-30' => 30,
            '2m'    => 60, '3m'    => 90, '4m' => 120, default => 30,
        };
        $pawn_date       = date('Y-m-d');
        $maturity_date   = date('Y-m-d', strtotime("+$term_days days"));
        $expiry_date     = date('Y-m-d', strtotime("+".($term_days + 90)." days"));
        $interest_amount = round($loan_amount * $interest_rate, 2);
        $total_redeem    = $loan_amount + $interest_amount;
        $ticket_no       = 'TP-' . date('Ymd') . '-' . strtoupper(substr(md5(uniqid()), 0, 6));

        if ($customer_name && $item_category && $appraisal > 0 && $loan_amount > 0) {
            $pdo->prepare("INSERT INTO pawn_transactions (tenant_id,ticket_no,customer_name,contact_number,email,address,birthdate,gender,nationality,birthplace,source_of_income,nature_of_work,occupation,business_office_school,valid_id_type,valid_id_number,item_category,item_description,item_condition,item_weight,item_karat,serial_number,appraisal_value,loan_amount,interest_rate,claim_term,interest_amount,total_redeem,pawn_date,maturity_date,expiry_date,status,created_by,assigned_staff_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'Stored',?,?)")
                ->execute([$tid,$ticket_no,$customer_name,$contact_number,$email,$address,$birthdate?:null,$gender,$nationality,$birthplace,$src_income,$nature_work,$occupation,$business,$valid_id_type,$valid_id_no,$item_category,$item_desc,$item_condition,$item_weight,$item_karat,$serial_number,$appraisal,$loan_amount,$interest_rate,$claim_term,$interest_amount,$total_redeem,$pawn_date,$maturity_date,$expiry_date,$u['id'],$u['id']]);

            $inv_id = $pdo->lastInsertId();
            $pdo->prepare("INSERT INTO item_inventory (tenant_id,pawn_id,ticket_no,item_name,item_category,serial_no,condition_notes,appraisal_value,loan_amount,status) VALUES (?,?,?,?,?,?,?,?,?,'pawned')")
                ->execute([$tid,$inv_id,$ticket_no,$item_desc,$item_category,$serial_number,$item_condition,$appraisal,$loan_amount]);

            $pdo->prepare("INSERT INTO audit_logs (tenant_id,actor_user_id,actor_username,actor_role,action,entity_type,entity_id,message,ip_address) VALUES (?,?,?,?,'PAWN_CREATE','pawn_transaction',?,?,?)")
                ->execute([$tid,$u['id'],$u['username'],'staff',$ticket_no,"Created pawn ticket",$_SERVER['REMOTE_ADDR']??'::1']);

            $success_msg = "Pawn ticket $ticket_no created successfully!";
            $active_page = 'tickets';
        } else {
            $error_msg = 'Please fill all required fields.';
        }
    }

    // Submit void request
    if ($_POST['action'] === 'void_request') {
        $ticket_no = trim($_POST['ticket_no'] ?? '');
        $reason    = trim($_POST['reason']    ?? '');
        if ($ticket_no && $reason) {
            $pdo->prepare("INSERT INTO pawn_void_requests (tenant_id,ticket_no,requested_by,reason,status) VALUES (?,?,?,?,'pending')")
                ->execute([$tid,$ticket_no,$u['id'],$reason]);
            $pdo->prepare("INSERT INTO audit_logs (tenant_id,actor_user_id,actor_username,actor_role,action,entity_type,entity_id,message,ip_address) VALUES (?,?,?,?,'PAWN_VOID_REQUEST','pawn_transaction',?,?,?)")
                ->execute([$tid,$u['id'],$u['username'],'staff',$ticket_no,"Void request: $reason",$_SERVER['REMOTE_ADDR']??'::1']);
            $success_msg = 'Void request submitted for admin approval.';
            $active_page = 'tickets';
        }
    }

}

// ── Fetch Data (tenant-scoped) ────────────────────────────────
$today = date('Y-m-d');
$my_tickets_today = $pdo->prepare("SELECT COUNT(*) FROM pawn_transactions WHERE tenant_id=? AND created_by=? AND DATE(created_at)=?"); $my_tickets_today->execute([$tid,$u['id'],$today]); $my_tickets_today=$my_tickets_today->fetchColumn();
$active_count     = $pdo->prepare("SELECT COUNT(*) FROM pawn_transactions WHERE tenant_id=? AND assigned_staff_id=? AND status='Stored'"); $active_count->execute([$tid,$u['id']]); $active_count=$active_count->fetchColumn();


$all_tickets  = $pdo->prepare("SELECT * FROM pawn_transactions WHERE tenant_id=? ORDER BY created_at DESC LIMIT 100"); $all_tickets->execute([$tid]); $all_tickets=$all_tickets->fetchAll();
$my_active    = $pdo->prepare("SELECT * FROM pawn_transactions WHERE tenant_id=? AND assigned_staff_id=? AND status='Stored' ORDER BY maturity_date ASC"); $my_active->execute([$tid,$u['id']]); $my_active=$my_active->fetchAll();
$customers    = $pdo->prepare("SELECT * FROM customers WHERE tenant_id=? ORDER BY full_name"); $customers->execute([$tid]); $customers=$customers->fetchAll();

$my_void_reqs = $pdo->prepare("SELECT * FROM pawn_void_requests WHERE tenant_id=? AND requested_by=? ORDER BY requested_at DESC"); $my_void_reqs->execute([$tid,$u['id']]); $my_void_reqs=$my_void_reqs->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title><?=htmlspecialchars($sys_name)?> — Staff</title>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<?= renderThemeCSS($theme) ?>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
:root{--sw:222px;--blue-acc:#2563eb;--bg:#f5f7fa;--card:#fff;--border:#e8ecf0;--text:#1a2332;--text-m:#4a5568;--text-dim:#9aa5b4;--success:#10b981;--danger:#ef4444;--warning:#f59e0b;}
body{font-family:'Plus Jakarta Sans',sans-serif;background:var(--bg);color:var(--text);display:flex;min-height:100vh;font-size:14px;}
.sidebar{width:var(--sw);min-height:100vh;background:#fff;border-right:1px solid var(--border);display:flex;flex-direction:column;position:fixed;left:0;top:0;bottom:0;z-index:100;overflow-y:auto;}
.sb-brand{padding:15px 16px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:9px;flex-shrink:0;}
.sb-logo{width:32px;height:32px;background:var(--blue-acc);border-radius:8px;display:flex;align-items:center;justify-content:center;flex-shrink:0;}
.sb-logo svg{width:17px;height:17px;}
.sb-brand-name{font-size:.9rem;font-weight:800;color:var(--text);}

/* Tenant Info Card in Sidebar */
.sb-tenant-info{margin:10px 10px 0;background:linear-gradient(135deg,#eff6ff,#f0fdf4);border:1px solid #bfdbfe;border-radius:10px;padding:11px 13px;}
.sb-tenant-label{font-size:.6rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#2563eb;margin-bottom:4px;}
.sb-tenant-name{font-size:.83rem;font-weight:700;color:#1e293b;margin-bottom:3px;}
.sb-tenant-id{display:inline-flex;align-items:center;gap:4px;font-size:.68rem;font-weight:700;background:#2563eb;color:#fff;padding:2px 8px;border-radius:100px;}
.sb-tenant-plan{font-size:.68rem;color:#475569;margin-top:3px;}

.sb-user{padding:10px 16px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:8px;flex-shrink:0;margin-top:8px;}
.sb-avatar{width:30px;height:30px;border-radius:50%;background:linear-gradient(135deg,#667eea,#764ba2);display:flex;align-items:center;justify-content:center;font-size:.76rem;font-weight:700;color:#fff;flex-shrink:0;}
.sb-uname{font-size:.79rem;font-weight:700;color:var(--text);}
.sb-urole{font-size:.65rem;color:var(--text-dim);}
.sb-status{display:inline-flex;align-items:center;gap:3px;font-size:.61rem;font-weight:700;background:#dcfce7;color:#15803d;padding:2px 6px;border-radius:100px;margin-top:2px;}
.sb-nav{flex:1;padding:10px 0;}
.sb-section{font-size:.6rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--text-dim);padding:10px 14px 4px;}
.sb-item{display:flex;align-items:center;gap:9px;padding:8px 14px;margin:1px 7px;border-radius:8px;cursor:pointer;color:var(--text-m);font-size:.81rem;font-weight:500;text-decoration:none;transition:all .15s;}
.sb-item:hover{background:#f0f4ff;color:var(--blue-acc);}
.sb-item.active{background:var(--blue-acc);color:#fff;font-weight:600;}
.sb-item svg{width:15px;height:15px;flex-shrink:0;}
.sb-footer{padding:10px 14px;border-top:1px solid var(--border);flex-shrink:0;}
.sb-logout{display:flex;align-items:center;gap:8px;font-size:.79rem;color:var(--text-dim);text-decoration:none;padding:7px 8px;border-radius:8px;transition:all .15s;}
.sb-logout:hover{color:var(--danger);background:#fff1f2;}
.sb-logout svg{width:14px;height:14px;}
.main{margin-left:var(--sw);flex:1;display:flex;flex-direction:column;}
.topbar{height:55px;padding:0 22px;background:#fff;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:50;}
.topbar-left{display:flex;align-items:center;gap:8px;}
.topbar-title{font-size:.97rem;font-weight:700;}
.tenant-badge{font-size:.69rem;font-weight:700;background:#eff6ff;color:var(--blue-acc);padding:3px 9px;border-radius:100px;border:1px solid #bfdbfe;}
.content{padding:20px 22px;flex:1;}
.page-hdr{display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:18px;flex-wrap:wrap;gap:10px;}
.page-hdr h2{font-size:1.15rem;font-weight:800;}
.page-hdr p{font-size:.79rem;color:var(--text-dim);margin-top:2px;}
.stats-row{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:18px;}
.stat-card{background:#fff;border:1px solid var(--border);border-radius:12px;padding:14px 16px;}
.stat-top{display:flex;align-items:center;justify-content:space-between;margin-bottom:9px;}
.stat-icon{width:34px;height:34px;border-radius:9px;display:flex;align-items:center;justify-content:center;}
.stat-icon svg{width:16px;height:16px;}
.stat-value{font-size:1.45rem;font-weight:800;color:var(--text);margin-bottom:2px;}
.stat-label{font-size:.71rem;color:var(--text-dim);}
.main-grid{display:grid;grid-template-columns:1fr 1.6fr;gap:14px;margin-bottom:14px;}
.card{background:#fff;border:1px solid var(--border);border-radius:12px;padding:16px;}
.card-title{font-size:.79rem;font-weight:800;color:var(--text);margin-bottom:12px;}
.qa-btn{display:flex;align-items:center;gap:9px;padding:10px 13px;border-radius:9px;font-family:inherit;font-size:.82rem;font-weight:600;cursor:pointer;border:none;width:100%;text-align:left;transition:all .15s;margin-bottom:7px;text-decoration:none;}
.qa-primary{background:var(--blue-acc);color:#fff;}
.qa-primary:hover{background:#1d40af;}
.qa-secondary{background:#f8fafc;color:var(--text-m);border:1.5px solid var(--border);}
.qa-secondary:hover{background:#f0f4ff;color:var(--blue-acc);border-color:#bfdbfe;}
.qa-icon{width:26px;height:26px;border-radius:7px;display:flex;align-items:center;justify-content:center;flex-shrink:0;}
table{width:100%;border-collapse:collapse;}
th{font-size:.67rem;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:var(--text-dim);padding:7px 10px;text-align:left;border-bottom:1px solid var(--border);white-space:nowrap;}
td{padding:9px 10px;font-size:.8rem;color:var(--text);border-bottom:1px solid #f5f7fa;vertical-align:middle;}
tr:last-child td{border-bottom:none;}
tr:hover td{background:#fafbfc;}
.ticket-tag{font-family:monospace;font-size:.76rem;color:var(--blue-acc);font-weight:700;}
.badge{display:inline-flex;align-items:center;gap:3px;font-size:.66rem;font-weight:700;padding:2px 8px;border-radius:100px;}
.b-blue{background:#dbeafe;color:#1d4ed8;} .b-green{background:#dcfce7;color:#15803d;} .b-red{background:#fee2e2;color:#dc2626;} .b-yellow{background:#fef3c7;color:#b45309;} .b-gray{background:#f1f5f9;color:#475569;}
.b-dot{width:4px;height:4px;border-radius:50%;background:currentColor;}
.btn-xs{padding:4px 10px;border-radius:6px;font-size:.73rem;font-weight:600;cursor:pointer;border:1px solid var(--border);background:#fff;color:var(--text-m);text-decoration:none;display:inline-flex;align-items:center;gap:4px;transition:all .15s;margin-right:3px;}
.btn-xs:hover{background:var(--bg);}
.btn-primary-xs{background:var(--blue-acc);color:#fff;border-color:var(--blue-acc);}
.btn-danger-xs{background:var(--danger);color:#fff;border-color:var(--danger);}
.flabel{display:block;font-size:.74rem;font-weight:600;color:var(--text-m);margin-bottom:4px;}
.finput{width:100%;border:1.5px solid var(--border);border-radius:8px;padding:8px 11px;font-family:inherit;font-size:.84rem;color:var(--text);outline:none;background:#fff;transition:border .2s;}
.finput:focus{border-color:var(--blue-acc);box-shadow:0 0 0 3px rgba(37,99,235,.1);}
.finput::placeholder{color:#c8d0db;}
.alert{padding:10px 14px;border-radius:9px;font-size:.81rem;margin-bottom:16px;display:flex;align-items:center;gap:8px;}
.alert-success{background:#f0fdf4;border:1px solid #bbf7d0;color:#15803d;}
.alert-error{background:#fef2f2;border:1px solid #fecaca;color:#dc2626;}
.empty-state{text-align:center;padding:36px 20px;color:var(--text-dim);}
.empty-state p{font-size:.82rem;}
.form-grid2{display:grid;grid-template-columns:1fr 1fr;gap:12px;}
.fgroup{margin-bottom:11px;}
/* Modal */
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(15,23,42,.55);z-index:999;align-items:center;justify-content:center;backdrop-filter:blur(3px);}
.modal-overlay.open{display:flex;}
.modal{background:#fff;border-radius:16px;width:560px;max-width:95vw;max-height:92vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.2);animation:mIn .25s ease both;}
@keyframes mIn{from{opacity:0;transform:translateY(16px)}to{opacity:1;transform:none}}
.mhdr{padding:18px 20px 0;display:flex;align-items:center;justify-content:space-between;}
.mtitle{font-size:.97rem;font-weight:800;}
.mclose{width:28px;height:28px;border-radius:7px;border:1.5px solid var(--border);background:#fff;cursor:pointer;display:flex;align-items:center;justify-content:center;color:var(--text-dim);}
.mclose svg{width:13px;height:13px;}
.mbody{padding:16px 20px 20px;}
@media(max-width:1000px){.stats-row{grid-template-columns:repeat(2,1fr);}.main-grid{grid-template-columns:1fr;}}
</style>
</head>
<body>
<aside class="sidebar">
  <div class="sb-brand">
    <div class="sb-logo">
      <?php if($logo_url): ?>
        <img src="<?=htmlspecialchars($logo_url)?>" alt="logo" style="width:100%;height:100%;object-fit:cover;border-radius:8px;">
      <?php else: ?>
        <svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2.5"><rect x="3" y="9" width="18" height="12"/><polyline points="3 9 12 3 21 9"/></svg>
      <?php endif; ?>
    </div>
    <span class="sb-brand-name"><?=htmlspecialchars($logo_text)?></span>
  </div>

  <!-- ── TENANT INFO CARD ── -->
  <?php if($tenant): ?>
  <div class="sb-tenant-info">
    <div class="sb-tenant-label">My Branch</div>
    <div class="sb-tenant-name"><?=htmlspecialchars($tenant['business_name'])?></div>
    <div class="sb-tenant-id">Tenant #<?=$tenant['id']?></div>
    <div class="sb-tenant-plan"><?=$tenant['plan']?> Plan · <?=$tenant['branches']?> Branch<?=$tenant['branches']>1?'es':''?></div>
  </div>
  <?php else: ?>
  <div style="margin:10px 10px 0;background:#fef3c7;border:1px solid #fde68a;border-radius:10px;padding:10px 13px;font-size:.76rem;color:#92400e;">⚠️ No tenant assigned. Contact your admin.</div>
  <?php endif; ?>

  <div class="sb-user">
    <div class="sb-avatar"><?=strtoupper(substr($u['name'],0,1))?></div>
    <div>
      <div class="sb-uname"><?=htmlspecialchars(explode(' ',$u['name'])[0]??$u['name'])?></div>
      <div class="sb-urole">Staff</div>
      <div class="sb-status">● ONLINE</div>
    </div>
  </div>

  <nav class="sb-nav">
    <div class="sb-section">Main</div>
    <a href="?page=dashboard"         class="sb-item <?=$active_page==='dashboard'?'active':''?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>Dashboard</a>
    <a href="?page=create_ticket"     class="sb-item <?=$active_page==='create_ticket'?'active':''?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/></svg>Create Pawn Ticket</a>

    <div class="sb-section">Records</div>
    <a href="?page=tickets"           class="sb-item <?=$active_page==='tickets'?'active':''?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/></svg>All Tickets</a>
    <a href="?page=customers"         class="sb-item <?=$active_page==='customers'?'active':''?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>Customers</a>
    <a href="?page=register_customer" class="sb-item <?=$active_page==='register_customer'?'active':''?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" y1="8" x2="19" y2="14"/><line x1="22" y1="11" x2="16" y2="11"/></svg>Register Customer</a>

    <a href="?page=void_requests"     class="sb-item <?=$active_page==='void_requests'?'active':''?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>My Void Requests</a>
  </nav>
  <div class="sb-footer">
    <a href="logout.php" class="sb-logout"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>Sign Out</a>
  </div>
</aside>

<div class="main">
  <header class="topbar">
    <div class="topbar-left">
      <span class="topbar-title"><?php $titles=['dashboard'=>'Staff Dashboard','create_ticket'=>'Create Pawn Ticket','tickets'=>'All Tickets','customers'=>'Customers','register_customer'=>'Register Customer','payments'=>'My Payments','void_requests'=>'My Void Requests'];echo $titles[$active_page]??'Dashboard';?></span>
      <?php if($tenant): ?><span class="tenant-badge"><?=htmlspecialchars($tenant['business_name'])?> · Tenant #<?=$tid?></span><?php endif;?>
    </div>
    <span style="font-size:.76rem;color:var(--text-dim);">📅 <?=date('M d, Y')?></span>
  </header>

  <div class="content">
  <?php if($success_msg):?><div class="alert alert-success">✓ <?=htmlspecialchars($success_msg)?></div><?php endif;?>
  <?php if($error_msg):?><div class="alert alert-error">⚠ <?=htmlspecialchars($error_msg)?></div><?php endif;?>

  <?php if(!$tid): ?>
    <div style="background:#fef3c7;border:1px solid #fde68a;border-radius:12px;padding:24px;text-align:center;color:#92400e;">
      <div style="font-size:1.1rem;font-weight:700;margin-bottom:8px;">⚠️ No Tenant Assigned</div>
      <p style="font-size:.85rem;">Your account has not been assigned to a branch yet. Please contact your Super Admin.</p>
    </div>
  <?php elseif($active_page==='dashboard'): ?>

    <div class="page-hdr">
      <div>
        <h2>Welcome back, <?=htmlspecialchars(explode(' ',$u['name'])[0])?>! 👋</h2>
        <p>Here's your branch activity for today — <?=date('F j, Y')?>.</p>
      </div>
    </div>

    <!-- Tenant Info Banner -->
    <div style="background:linear-gradient(135deg,#1e3a8a,#2563eb);border-radius:12px;padding:16px 20px;margin-bottom:18px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;">
      <div>
        <div style="font-size:.7rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:rgba(255,255,255,.6);margin-bottom:4px;">Your Branch</div>
        <div style="font-size:1.05rem;font-weight:800;color:#fff;"><?=htmlspecialchars($tenant['business_name'])?></div>
        <div style="font-size:.78rem;color:rgba(255,255,255,.7);margin-top:2px;"><?=$tenant['plan']?> Plan · <?=$tenant['branches']?> Branch<?=$tenant['branches']>1?'es':''?></div>
      </div>
      <div style="text-align:right;">
        <div style="font-size:.7rem;color:rgba(255,255,255,.6);margin-bottom:4px;">Tenant ID</div>
        <div style="font-size:1.4rem;font-weight:800;color:#fff;">#<?=$tid?></div>
        <div style="font-size:.72rem;color:rgba(255,255,255,.6);">Your identifier</div>
      </div>
    </div>

    <div class="stats-row">
      <div class="stat-card"><div class="stat-top"><div class="stat-icon" style="background:#dbeafe;"><svg viewBox="0 0 24 24" fill="none" stroke="#2563eb" stroke-width="2"><path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/></svg></div></div><div class="stat-value"><?=$my_tickets_today?></div><div class="stat-label">Tickets Today</div></div>
      <div class="stat-card"><div class="stat-top"><div class="stat-icon" style="background:#fce7f3;"><svg viewBox="0 0 24 24" fill="none" stroke="#db2777" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg></div></div><div class="stat-value"><?=$active_count?></div><div class="stat-label">My Active Tickets</div></div>
      <?php $cust_today=(int)$pdo->query("SELECT COUNT(*) FROM customers WHERE tenant_id=$tid AND created_by={$u['id']} AND DATE(registered_at)='$today'")->fetchColumn(); ?>
      <div class="stat-card"><div class="stat-top"><div class="stat-icon" style="background:#d1fae5;"><svg viewBox="0 0 24 24" fill="none" stroke="#059669" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" y1="8" x2="19" y2="14"/><line x1="22" y1="11" x2="16" y2="11"/></svg></div></div><div class="stat-value"><?=$cust_today?></div><div class="stat-label">Customers Today</div></div>
      <div class="stat-card"><div class="stat-top"><div class="stat-icon" style="background:#fce7f3;"><svg viewBox="0 0 24 24" fill="none" stroke="#db2777" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg></div></div><div class="stat-value"><?=count($my_void_reqs)?></div><div class="stat-label">My Void Requests</div></div>
    </div>

    <div class="main-grid">
      <div>
        <div class="card" style="margin-bottom:12px;">
          <div class="card-title">⚡ Quick Actions</div>
          <a href="?page=create_ticket"     class="qa-btn qa-primary"><div class="qa-icon" style="background:rgba(255,255,255,.2);"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg></div>New Pawn Ticket</a>

          <a href="?page=register_customer" class="qa-btn qa-secondary"><div class="qa-icon" style="background:#f0fdf4;"><svg viewBox="0 0 24 24" fill="none" stroke="#059669" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" y1="8" x2="19" y2="14"/><line x1="22" y1="11" x2="16" y2="11"/></svg></div>Register Customer</a>
        </div>
        <div style="background:#fffbeb;border:1px solid #fde68a;border-radius:10px;padding:13px;">
          <div style="font-size:.77rem;font-weight:700;color:#92400e;margin-bottom:5px;">📋 Branch Note</div>
          <p style="font-size:.74rem;color:#78350f;line-height:1.6;">You are assigned to <strong><?=htmlspecialchars($tenant['business_name'])?></strong> (Tenant #<?=$tid?>). All your tickets and customers are saved under this branch.</p>
        </div>
      </div>
      <div class="card">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;">
          <div class="card-title" style="margin:0;">My Active Tickets</div>
          <a href="?page=tickets" style="font-size:.73rem;color:var(--blue-acc);font-weight:600;text-decoration:none;">View All</a>
        </div>
        <?php if(empty($my_active)): ?><div class="empty-state"><p>No active tickets yet.</p></div>
        <?php else: ?>
        <div style="overflow-x:auto;"><table><thead><tr><th>Ticket</th><th>Customer</th><th>Item</th><th>Loan</th><th>Maturity</th></tr></thead><tbody>
        <?php foreach(array_slice($my_active,0,6) as $t): ?>
        <tr><td><span class="ticket-tag"><?=htmlspecialchars($t['ticket_no'])?></span></td><td style="font-weight:600;"><?=htmlspecialchars($t['customer_name'])?></td><td><?=htmlspecialchars($t['item_category'])?></td><td>₱<?=number_format($t['loan_amount'],2)?></td><td style="font-size:.74rem;color:<?=strtotime($t['maturity_date'])<time()?'var(--danger)':'var(--text-dim)'?>;"><?=$t['maturity_date']?></td></tr>
        <?php endforeach;?></tbody></table></div>
        <?php endif;?>
      </div>
    </div>

  <?php elseif($active_page==='create_ticket'): ?>
    <form method="POST">
      <input type="hidden" name="action" value="create_ticket">
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;max-width:1000px;">
        <div>
          <div class="card" style="margin-bottom:14px;">
            <div class="card-title">Customer Information</div>
            <div class="form-grid2">
              <div class="fgroup"><label class="flabel">Customer Name *</label><input type="text" name="customer_name" class="finput" placeholder="Last, First M." required></div>
              <div class="fgroup"><label class="flabel">Contact Number *</label><input type="text" name="contact_number" class="finput" placeholder="+63XXXXXXXXX" required></div>
              <div class="fgroup"><label class="flabel">Email</label><input type="email" name="email" class="finput" placeholder="email@example.com"></div>
              <div class="fgroup"><label class="flabel">Birthdate</label><input type="date" name="birthdate" class="finput"></div>
              <div class="fgroup" style="grid-column:1/-1;"><label class="flabel">Address</label><input type="text" name="address" class="finput" placeholder="Street, City, Province"></div>
              <div class="fgroup"><label class="flabel">Gender</label><select name="gender" class="finput"><option>Male</option><option>Female</option><option>Other</option></select></div>
              <div class="fgroup"><label class="flabel">Nationality</label><input type="text" name="nationality" class="finput" value="Filipino"></div>
              <div class="fgroup"><label class="flabel">Birthplace</label><input type="text" name="birthplace" class="finput" placeholder="City, Province"></div>
              <div class="fgroup"><label class="flabel">Source of Income</label><input type="text" name="source_of_income" class="finput" placeholder="Salary / Business"></div>
              <div class="fgroup"><label class="flabel">Nature of Work</label><input type="text" name="nature_of_work" class="finput" placeholder="Private / Government"></div>
              <div class="fgroup"><label class="flabel">Occupation</label><input type="text" name="occupation" class="finput" placeholder="e.g. Teacher"></div>
              <div class="fgroup"><label class="flabel">Business / School</label><input type="text" name="business_office_school" class="finput" placeholder="Employer / School"></div>
              <div class="fgroup"><label class="flabel">Valid ID Type *</label><select name="valid_id_type" class="finput"><option>Passport</option><option>Driver's License</option><option>PhilSys ID</option><option>UMID</option><option>Voter's ID</option><option>Postal ID</option></select></div>
              <div class="fgroup"><label class="flabel">ID Number *</label><input type="text" name="valid_id_number" class="finput" placeholder="ID Number" required></div>
            </div>
          </div>
        </div>
        <div>
          <div class="card" style="margin-bottom:14px;">
            <div class="card-title">Item Information</div>
            <div class="form-grid2">
              <div class="fgroup" style="grid-column:1/-1;"><label class="flabel">Item Description *</label><input type="text" name="item_description" class="finput" placeholder="e.g. Gold Ring 18k 5g" required></div>
              <div class="fgroup"><label class="flabel">Category *</label><select name="item_category" class="finput" required><option>Jewelry</option><option>Gold</option><option>Silver</option><option>Gadget</option><option>Appliance</option><option>Others</option></select></div>
              <div class="fgroup"><label class="flabel">Condition</label><select name="item_condition" class="finput"><option>Excellent</option><option>Good</option><option>Fair</option><option>Poor</option></select></div>
              <div class="fgroup"><label class="flabel">Weight (g)</label><input type="number" name="item_weight" class="finput" placeholder="0.00" step="0.01"></div>
              <div class="fgroup"><label class="flabel">Karat</label><input type="text" name="item_karat" class="finput" placeholder="18k / 24k"></div>
              <div class="fgroup" style="grid-column:1/-1;"><label class="flabel">Serial No.</label><input type="text" name="serial_number" class="finput" placeholder="Serial / Reference No."></div>
            </div>
          </div>
          <div class="card" style="margin-bottom:14px;">
            <div class="card-title">Loan Details</div>
            <div class="form-grid2">
              <div class="fgroup"><label class="flabel">Appraisal Value (₱) *</label><input type="number" name="appraisal_value" id="appraisal" class="finput" placeholder="0.00" step="0.01" oninput="calcLoan()" required></div>
              <div class="fgroup"><label class="flabel">Loan Amount (₱) *</label><input type="number" name="loan_amount" id="loan_amt" class="finput" placeholder="0.00" step="0.01" oninput="calcSummary()" required></div>
              <div class="fgroup"><label class="flabel">Interest Rate</label><select name="interest_rate" id="irate" class="finput" onchange="calcSummary()"><option value="0.02">2%</option><option value="0.04">4%</option><option value="0.10">10%</option><option value="0.16">16%</option><option value="0.22">22%</option></select></div>
              <div class="fgroup"><label class="flabel">Claim Term</label><select name="claim_term" class="finput"><option value="1-15">1–15 days</option><option value="16-30">16–30 days</option><option value="2m">2 months</option><option value="3m">3 months</option><option value="4m">4 months</option></select></div>
            </div>
            <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:9px;padding:11px;font-size:.79rem;">
              <div style="display:flex;justify-content:space-between;margin-bottom:4px;"><span style="color:#166534;">Appraisal</span><span id="d_a" style="font-weight:700;color:#15803d;">₱0.00</span></div>
              <div style="display:flex;justify-content:space-between;margin-bottom:4px;"><span style="color:#166534;">Loan</span><span id="d_l" style="font-weight:700;color:#15803d;">₱0.00</span></div>
              <div style="display:flex;justify-content:space-between;margin-bottom:4px;"><span style="color:#166534;">Interest</span><span id="d_i" style="font-weight:700;color:#15803d;">₱0.00</span></div>
              <div style="display:flex;justify-content:space-between;border-top:1px solid #bbf7d0;padding-top:6px;margin-top:4px;"><span style="color:#166534;font-weight:700;">Total Redeem</span><span id="d_t" style="font-weight:800;color:#15803d;font-size:.9rem;">₱0.00</span></div>
            </div>
          </div>
          <button type="submit" style="width:100%;background:var(--blue-acc);color:#fff;border:none;border-radius:9px;padding:12px;font-family:inherit;font-size:.9rem;font-weight:700;cursor:pointer;box-shadow:0 4px 14px rgba(37,99,235,.25);">Issue Pawn Ticket</button>
        </div>
      </div>
    </form>

  <?php elseif($active_page==='tickets'): ?>
    <div class="page-hdr"><div><h2>All Tickets</h2><p><?=count($all_tickets)?> records under Tenant #<?=$tid?></p></div><a href="?page=create_ticket" class="btn-xs btn-primary-xs" style="padding:7px 14px;">+ New Ticket</a></div>
    <div class="card" style="overflow-x:auto;">
      <?php if(empty($all_tickets)): ?><div class="empty-state"><p>No tickets yet.</p></div>
      <?php else: ?><table><thead><tr><th>Ticket No.</th><th>Customer</th><th>Contact</th><th>Item</th><th>Loan</th><th>Total Redeem</th><th>Maturity</th><th>Status</th><th>Action</th></tr></thead><tbody>
      <?php foreach($all_tickets as $t): $sc=['Stored'=>'b-blue','Released'=>'b-green','Renewed'=>'b-yellow','Voided'=>'b-red','Auctioned'=>'b-gray'];?>
      <tr><td><span class="ticket-tag"><?=htmlspecialchars($t['ticket_no'])?></span></td><td style="font-weight:600;"><?=htmlspecialchars($t['customer_name'])?></td><td style="font-family:monospace;font-size:.76rem;"><?=htmlspecialchars($t['contact_number'])?></td><td><?=htmlspecialchars($t['item_category'])?></td><td>₱<?=number_format($t['loan_amount'],2)?></td><td style="font-weight:700;">₱<?=number_format($t['total_redeem'],2)?></td><td style="font-size:.74rem;color:<?=strtotime($t['maturity_date'])<time()&&$t['status']==='Stored'?'var(--danger)':'var(--text-dim)'?>;"><?=$t['maturity_date']?></td><td><span class="badge <?=$sc[$t['status']]??'b-gray'?>"><?=$t['status']?></span></td>
      <td>
        <?php if($t['status']==='Stored' && $t['assigned_staff_id']==$u['id']):?>
          <button onclick="openVoid('<?=htmlspecialchars($t['ticket_no'])?>')" class="btn-xs btn-danger-xs" style="font-size:.7rem;">🚫 Void Req</button>
        <?php elseif($t['status']==='Stored'):?>
          <span style="font-size:.72rem;color:var(--text-dim);">View only</span>
        <?php else:?>—<?php endif;?>
      </td></tr>
      <?php endforeach;?></tbody></table><?php endif;?>
    </div>

  <?php elseif($active_page==='customers'): ?>
    <div class="page-hdr"><div><h2>Customers</h2><p><?=count($customers)?> under Tenant #<?=$tid?></p></div><a href="?page=register_customer" class="btn-xs btn-primary-xs" style="padding:7px 14px;">+ Register</a></div>
    <div class="card" style="overflow-x:auto;">
      <?php if(empty($customers)):?><div class="empty-state"><p>No customers yet.</p></div>
      <?php else:?><table><thead><tr><th>Name</th><th>Contact</th><th>Email</th><th>Gender</th><th>ID Type</th><th>Registered</th></tr></thead><tbody>
      <?php foreach($customers as $c):?>
      <tr><td style="font-weight:600;"><?=htmlspecialchars($c['full_name'])?></td><td style="font-family:monospace;font-size:.76rem;"><?=htmlspecialchars($c['contact_number'])?></td><td style="font-size:.76rem;color:var(--text-dim);"><?=htmlspecialchars($c['email']??'—')?></td><td><?=$c['gender']?></td><td><?=htmlspecialchars($c['valid_id_type']??'—')?></td><td style="font-size:.74rem;color:var(--text-dim);"><?=date('M d, Y',strtotime($c['registered_at']))?></td></tr>
      <?php endforeach;?></tbody></table><?php endif;?>
    </div>

  <?php elseif($active_page==='register_customer'): ?>
    <div style="max-width:680px;">
      <div class="card">
        <form method="POST">
          <input type="hidden" name="action" value="register_customer">
          <div class="card-title">Register New Customer — Tenant #<?=$tid?></div>
          <div class="form-grid2">
            <div class="fgroup" style="grid-column:1/-1;"><label class="flabel">Full Name * (Last, First M.)</label><input type="text" name="full_name" class="finput" placeholder="Rivera, Leala Vieann P." required></div>
            <div class="fgroup"><label class="flabel">Contact Number *</label><input type="text" name="contact_number" class="finput" placeholder="+63XXXXXXXXX" required></div>
            <div class="fgroup"><label class="flabel">Email</label><input type="email" name="email" class="finput" placeholder="email@example.com"></div>
            <div class="fgroup"><label class="flabel">Birthdate</label><input type="date" name="birthdate" class="finput"></div>
            <div class="fgroup"><label class="flabel">Gender</label><select name="gender" class="finput"><option>Male</option><option>Female</option><option>Other</option></select></div>
            <div class="fgroup" style="grid-column:1/-1;"><label class="flabel">Address</label><input type="text" name="address" class="finput" placeholder="Street, City, Province"></div>
            <div class="fgroup"><label class="flabel">Nationality</label><input type="text" name="nationality" class="finput" value="Filipino"></div>
            <div class="fgroup"><label class="flabel">Birthplace</label><input type="text" name="birthplace" class="finput" placeholder="City, Province"></div>
            <div class="fgroup"><label class="flabel">Source of Income</label><input type="text" name="source_of_income" class="finput" placeholder="Salary / Business / Allowance"></div>
            <div class="fgroup"><label class="flabel">Nature of Work</label><input type="text" name="nature_of_work" class="finput" placeholder="Private / Government / Student"></div>
            <div class="fgroup"><label class="flabel">Occupation</label><input type="text" name="occupation" class="finput" placeholder="e.g. Teacher / Student"></div>
            <div class="fgroup"><label class="flabel">Business / Office / School</label><input type="text" name="business_office_school" class="finput" placeholder="Employer or School name"></div>
            <div class="fgroup"><label class="flabel">Valid ID Type</label><select name="valid_id_type" class="finput"><option>Passport</option><option>Driver's License</option><option>PhilSys ID</option><option>UMID</option><option>Voter's ID</option><option>Postal ID</option></select></div>
            <div class="fgroup"><label class="flabel">ID Number</label><input type="text" name="valid_id_number" class="finput" placeholder="ID Number"></div>
          </div>
          <div style="display:flex;justify-content:flex-end;gap:9px;margin-top:6px;">
            <a href="?page=customers" class="btn-xs">Cancel</a>
            <button type="submit" class="btn-xs btn-primary-xs" style="padding:7px 16px;">Save Customer</button>
          </div>
        </form>
      </div>
    </div>

  <?php elseif($active_page==='void_requests'): ?>
    <div class="page-hdr"><div><h2>My Void Requests</h2><p>Void requests you've submitted</p></div></div>
    <div class="card" style="overflow-x:auto;">
      <?php if(empty($my_void_reqs)):?><div class="empty-state"><p>No void requests yet.</p></div>
      <?php else:?><table><thead><tr><th>Ticket</th><th>Reason</th><th>Status</th><th>Submitted</th><th>Decided</th></tr></thead><tbody>
      <?php foreach($my_void_reqs as $v):?>
      <tr><td><span class="ticket-tag"><?=htmlspecialchars($v['ticket_no'])?></span></td><td style="max-width:200px;font-size:.78rem;"><?=htmlspecialchars($v['reason'])?></td><td><span class="badge <?=$v['status']==='approved'?'b-green':($v['status']==='pending'?'b-yellow':'b-red')?>"><?=ucfirst($v['status'])?></span></td><td style="font-size:.73rem;color:var(--text-dim);"><?=date('M d, Y h:i A',strtotime($v['requested_at']))?></td><td style="font-size:.73rem;color:var(--text-dim);"><?=$v['decided_at']?date('M d, Y h:i A',strtotime($v['decided_at'])):'—'?></td></tr>
      <?php endforeach;?></tbody></table><?php endif;?>
    </div>
  <?php endif;?>
  </div>
</div>

<!-- VOID REQUEST MODAL -->
<div class="modal-overlay" id="voidModal">
  <div class="modal" style="width:440px;">
    <div class="mhdr"><div class="mtitle">Submit Void Request</div><button class="mclose" onclick="document.getElementById('voidModal').classList.remove('open')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button></div>
    <div class="mbody">
      <form method="POST">
        <input type="hidden" name="action" value="void_request">
        <input type="hidden" name="ticket_no" id="void_ticket_no">
        <div class="fgroup"><label class="flabel">Ticket No.</label><input type="text" id="void_display" class="finput" readonly style="background:#f8fafc;"></div>
        <div class="fgroup"><label class="flabel">Reason *</label><textarea name="reason" class="finput" rows="3" placeholder="Enter reason..." required style="resize:vertical;"></textarea></div>
        <div style="background:#fef3c7;border:1px solid #fde68a;border-radius:8px;padding:10px 12px;font-size:.76rem;color:#92400e;margin-bottom:14px;">⚠️ Requires admin approval before the ticket is voided.</div>
        <div style="display:flex;justify-content:flex-end;gap:9px;">
          <button type="button" class="btn-xs" onclick="document.getElementById('voidModal').classList.remove('open')">Cancel</button>
          <button type="submit" class="btn-xs btn-danger-xs">Submit Void Request</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
function openVoid(tn){document.getElementById('void_ticket_no').value=tn;document.getElementById('void_display').value=tn;document.getElementById('voidModal').classList.add('open');}
document.getElementById('voidModal').addEventListener('click',function(e){if(e.target===this)this.classList.remove('open');});
function calcLoan(){const a=parseFloat(document.getElementById('appraisal')?.value)||0;const lf=document.getElementById('loan_amt');if(lf&&!lf.value)lf.value=(a*0.70).toFixed(2);calcSummary();}
function calcSummary(){const a=parseFloat(document.getElementById('appraisal')?.value)||0;const l=parseFloat(document.getElementById('loan_amt')?.value)||0;const r=parseFloat(document.getElementById('irate')?.value)||0.02;const i=l*r;document.getElementById('d_a').textContent='₱'+a.toFixed(2);document.getElementById('d_l').textContent='₱'+l.toFixed(2);document.getElementById('d_i').textContent='₱'+i.toFixed(2);document.getElementById('d_t').textContent='₱'+(l+i).toFixed(2);}

</script>
</body>
</html>