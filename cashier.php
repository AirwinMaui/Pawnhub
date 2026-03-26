<?php
session_start();
require 'db.php';
require 'theme_helper.php';

function write_audit(PDO $pdo, $actor_id, $actor_username, $actor_role, string $action, string $entity_type = '', string $entity_id = '', string $message = '', $tenant_id = null): void {
    try {
        $pdo->prepare("INSERT INTO audit_logs (tenant_id,actor_user_id,actor_username,actor_role,action,entity_type,entity_id,message,ip_address,created_at) VALUES (?,?,?,?,?,?,?,?,?,NOW())")
            ->execute([$tenant_id,$actor_id,$actor_username,$actor_role,$action,$entity_type,$entity_id,$message,$_SERVER['REMOTE_ADDR']??'::1',date('Y-m-d H:i:s')]);
    } catch (PDOException $e) {}
}

if (empty($_SESSION['user'])) { header('Location: login.php'); exit; }
$u = $_SESSION['user'];
if ($u['role'] !== 'cashier') { header('Location: login.php'); exit; }

$tid         = $u['tenant_id'];
$active_page = $_GET['page'] ?? 'dashboard';
$success_msg = '';
$error_msg   = '';

$theme    = getTenantTheme($pdo, $tid);
$sys_name = $theme['system_name'] ?? 'PawnHub';
$logo_text = $theme['logo_text'] ?: $sys_name;
$logo_url  = $theme['logo_url']  ?? '';

$tenant = null;
if ($tid) {
    $stmt = $pdo->prepare("SELECT * FROM tenants WHERE id=?");
    $stmt->execute([$tid]);
    $tenant = $stmt->fetch();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    if ($_POST['action'] === 'process_payment') {
        $ticket_no  = trim($_POST['ticket_no']     ?? '');
        $pay_action = trim($_POST['pay_action']    ?? 'release');
        $amount_due = floatval($_POST['amount_due']    ?? 0);
        $cash_recv  = floatval($_POST['cash_received'] ?? 0);
        $or_no      = trim($_POST['or_no']         ?? '');
        $change     = max(0, $cash_recv - $amount_due);

        if ($ticket_no && $amount_due > 0 && $cash_recv >= $amount_due) {
            $pdo->prepare("INSERT INTO payment_transactions (tenant_id,ticket_no,action,or_no,amount_due,cash_received,change_amount,staff_user_id,staff_username,staff_role) VALUES (?,?,?,?,?,?,?,?,?,'cashier')")
                ->execute([$tid,$ticket_no,$pay_action,$or_no,$amount_due,$cash_recv,$change,$u['id'],$u['username']]);
            $new_status = $pay_action === 'release' ? 'Released' : 'Renewed';
            $pdo->prepare("UPDATE pawn_transactions SET status=? WHERE ticket_no=? AND tenant_id=?")
                ->execute([$new_status,$ticket_no,$tid]);
            $pdo->prepare("UPDATE item_inventory SET status=? WHERE ticket_no=? AND tenant_id=?")
                ->execute([$pay_action==='release'?'redeemed':'pawned',$ticket_no,$tid]);
            $pdo->prepare("INSERT INTO audit_logs (tenant_id,actor_user_id,actor_username,actor_role,action,entity_type,entity_id,message,ip_address,created_at) VALUES (?,?,?,?,'PAYMENT_PROCESS','pawn_transaction',?,?,?)")
                ->execute([$tid,$u['id'],$u['username'],'cashier',$ticket_no,"Payment: $new_status — ₱".number_format($amount_due,2),$_SERVER['REMOTE_ADDR']??'::1']);
            $success_msg = "Payment processed! Ticket $ticket_no marked as $new_status.";
            $active_page = 'tickets';
        } else {
            if ($cash_recv < $amount_due) {
                $error_msg = 'Cash received is less than amount due.';
            } else {
                $error_msg = 'Please fill all payment fields.';
            }
        }
    }
}

$today = date('Y-m-d');

$collections_today = $pdo->prepare("SELECT COALESCE(SUM(amount_due),0) FROM payment_transactions WHERE tenant_id=? AND staff_user_id=? AND DATE(created_at)=?");
$collections_today->execute([$tid,$u['id'],$today]);
$collections_today = $collections_today->fetchColumn();

$txn_today = $pdo->prepare("SELECT COUNT(*) FROM payment_transactions WHERE tenant_id=? AND staff_user_id=? AND DATE(created_at)=?");
$txn_today->execute([$tid,$u['id'],$today]);
$txn_today = $txn_today->fetchColumn();

$active_count = $pdo->prepare("SELECT COUNT(*) FROM pawn_transactions WHERE tenant_id=? AND status='Stored'");
$active_count->execute([$tid]);
$active_count = $active_count->fetchColumn();

$overdue_count = $pdo->prepare("SELECT COUNT(*) FROM pawn_transactions WHERE tenant_id=? AND status='Stored' AND maturity_date < ?");
$overdue_count->execute([$tid,$today]);
$overdue_count = $overdue_count->fetchColumn();

$active_tickets = $pdo->prepare("SELECT * FROM pawn_transactions WHERE tenant_id=? AND status='Stored' ORDER BY maturity_date ASC");
$active_tickets->execute([$tid]);
$active_tickets = $active_tickets->fetchAll();

$all_tickets = $pdo->prepare("SELECT * FROM pawn_transactions WHERE tenant_id=? ORDER BY created_at DESC LIMIT 100");
$all_tickets->execute([$tid]);
$all_tickets = $all_tickets->fetchAll();

$my_payments = $pdo->prepare("SELECT * FROM payment_transactions WHERE tenant_id=? AND staff_user_id=? ORDER BY created_at DESC LIMIT 30");
$my_payments->execute([$tid,$u['id']]);
$my_payments = $my_payments->fetchAll();

$inventory_list = $pdo->prepare("SELECT * FROM item_inventory WHERE tenant_id=? ORDER BY received_at DESC");
$inventory_list->execute([$tid]);
$inventory_list = $inventory_list->fetchAll();

$business_name = $tenant['business_name'] ?? 'My Branch';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title><?=htmlspecialchars($business_name)?> — Cashier</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet">
<?= renderThemeCSS($theme) ?>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
:root{
  --sw:265px;
  --green:#10b981;
  --blue-acc:var(--t-primary,#2563eb);
  --bg:#080d10;
  --text:#f1f5f9;
  --text-m:rgba(255,255,255,.65);
  --text-dim:rgba(255,255,255,.35);
  --danger:#ef4444;
  --warning:#f59e0b;
}
body{font-family:'Inter',sans-serif;background:var(--bg);color:var(--text);display:flex;min-height:100vh;overflow:hidden;}
.bg-scene{position:fixed;inset:0;z-index:0;}
.bg-scene img{width:100%;height:100%;object-fit:cover;opacity:.1;filter:brightness(0.4) saturate(0.6);}
.bg-overlay{position:absolute;inset:0;background:linear-gradient(135deg,rgba(8,13,16,.98) 0%,rgba(8,13,16,.88) 60%,rgba(6,78,59,.08) 100%);}

.sidebar{
  width:var(--sw);min-height:100vh;
  background:rgba(6,10,14,0.88);
  backdrop-filter:blur(40px);-webkit-backdrop-filter:blur(40px);
  border-right:1px solid rgba(255,255,255,.05);
  display:flex;flex-direction:column;
  position:fixed;left:0;top:0;bottom:0;z-index:100;overflow-y:auto;
}
.sb-brand{padding:22px 18px 14px;border-bottom:1px solid rgba(255,255,255,.06);display:flex;align-items:center;gap:11px;}
.sb-logo{width:38px;height:38px;background:linear-gradient(135deg,#10b981,#059669);border-radius:11px;display:flex;align-items:center;justify-content:center;flex-shrink:0;overflow:hidden;box-shadow:0 4px 14px rgba(16,185,129,.3);}
.sb-logo img{width:100%;height:100%;object-fit:cover;}
.sb-logo svg{width:19px;height:19px;}
.sb-name{font-size:.92rem;font-weight:800;color:#fff;letter-spacing:-.02em;}
.sb-subtitle{font-size:.58rem;color:rgba(255,255,255,.25);font-weight:600;letter-spacing:.1em;text-transform:uppercase;margin-top:1px;}

.sb-tenant-card{margin:10px 10px 0;background:rgba(16,185,129,.06);border:1px solid rgba(16,185,129,.12);border-radius:12px;padding:12px 14px;}
.sb-tenant-label{font-size:.58rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:rgba(110,231,183,.4);margin-bottom:5px;}
.sb-tenant-name{font-size:.85rem;font-weight:700;color:#fff;}
.sb-tenant-badge{display:inline-flex;align-items:center;gap:4px;font-size:.66rem;font-weight:700;background:rgba(16,185,129,.2);color:#6ee7b7;padding:2px 8px;border-radius:100px;margin-top:5px;}

.sb-user{padding:10px 18px;border-bottom:1px solid rgba(255,255,255,.05);display:flex;align-items:center;gap:9px;margin-top:8px;}
.sb-avatar{width:30px;height:30px;border-radius:50%;background:linear-gradient(135deg,#10b981,#059669);display:flex;align-items:center;justify-content:center;font-size:.74rem;font-weight:700;color:#fff;flex-shrink:0;}
.sb-uname{font-size:.79rem;font-weight:700;color:#fff;}
.sb-urole{font-size:.62rem;color:rgba(255,255,255,.3);}
.sb-status{display:inline-flex;align-items:center;gap:3px;font-size:.6rem;font-weight:700;background:rgba(16,185,129,.2);color:#6ee7b7;padding:2px 7px;border-radius:100px;margin-top:3px;}

.sb-nav{flex:1;padding:10px 0;}
.sb-section{font-size:.58rem;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:rgba(255,255,255,.2);padding:12px 16px 4px;}
.sb-item{display:flex;align-items:center;gap:10px;padding:9px 14px;margin:1px 8px;border-radius:10px;cursor:pointer;color:rgba(255,255,255,.4);font-size:.82rem;font-weight:500;text-decoration:none;transition:all .18s;}
.sb-item:hover{background:rgba(16,185,129,.08);color:rgba(255,255,255,.9);}
.sb-item.active{background:rgba(16,185,129,.15);color:#6ee7b7;font-weight:600;}
.sb-item .material-symbols-outlined{font-size:18px;flex-shrink:0;}

.sb-footer{padding:12px 14px;border-top:1px solid rgba(255,255,255,.05);}
.sb-logout{display:flex;align-items:center;gap:9px;font-size:.8rem;color:rgba(255,255,255,.3);text-decoration:none;padding:9px 10px;border-radius:10px;transition:all .18s;}
.sb-logout:hover{color:#f87171;background:rgba(239,68,68,.1);}
.sb-logout .material-symbols-outlined{font-size:18px;}

.main{margin-left:var(--sw);flex:1;display:flex;flex-direction:column;position:relative;z-index:10;height:100vh;overflow-y:auto;}
.topbar{height:60px;padding:0 26px;background:rgba(6,10,14,.75);backdrop-filter:blur(20px);border-bottom:1px solid rgba(255,255,255,.05);display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:50;}
.topbar-title{font-size:.97rem;font-weight:700;color:#fff;}
.cashier-chip{font-size:.68rem;font-weight:700;background:rgba(16,185,129,.12);color:#6ee7b7;padding:3px 11px;border-radius:100px;border:1px solid rgba(16,185,129,.2);}
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
.card-title{font-size:.78rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:rgba(255,255,255,.4);margin-bottom:14px;}

table{width:100%;border-collapse:collapse;}
th{font-size:.63rem;font-weight:700;letter-spacing:.07em;text-transform:uppercase;color:rgba(255,255,255,.3);padding:8px 12px;text-align:left;border-bottom:1px solid rgba(255,255,255,.06);}
td{padding:11px 12px;font-size:.81rem;color:rgba(255,255,255,.7);border-bottom:1px solid rgba(255,255,255,.04);vertical-align:middle;}
tr:last-child td{border-bottom:none;}
tr:hover td{background:rgba(255,255,255,.03);}
.ticket-tag{font-family:monospace;font-size:.76rem;color:var(--t-primary,#60a5fa);font-weight:700;}
.badge{display:inline-flex;align-items:center;gap:3px;font-size:.63rem;font-weight:700;padding:3px 9px;border-radius:100px;}
.b-blue{background:rgba(59,130,246,.2);color:#93c5fd;}.b-green{background:rgba(16,185,129,.2);color:#6ee7b7;}.b-red{background:rgba(239,68,68,.2);color:#fca5a5;}.b-yellow{background:rgba(245,158,11,.2);color:#fcd34d;}.b-gray{background:rgba(255,255,255,.07);color:rgba(255,255,255,.5);}
.b-dot{width:4px;height:4px;border-radius:50%;background:currentColor;}

.btn-sm{padding:5px 11px;border-radius:7px;font-size:.72rem;font-weight:600;cursor:pointer;border:1px solid rgba(255,255,255,.1);background:rgba(255,255,255,.06);color:rgba(255,255,255,.6);text-decoration:none;display:inline-flex;align-items:center;gap:4px;transition:all .15s;font-family:inherit;}
.btn-sm:hover{background:rgba(255,255,255,.12);}
.btn-success{background:rgba(16,185,129,.8);color:#fff;border-color:transparent;}

.flabel{display:block;font-size:.73rem;font-weight:600;color:rgba(255,255,255,.45);margin-bottom:5px;}
.finput{width:100%;border:1.5px solid rgba(255,255,255,.1);border-radius:10px;padding:9px 12px;font-family:inherit;font-size:.85rem;color:#fff;outline:none;background:rgba(255,255,255,.06);transition:border .2s;}
.finput:focus{border-color:#10b981;box-shadow:0 0 0 3px rgba(16,185,129,.15);}
.finput::placeholder{color:rgba(255,255,255,.2);}
.finput option{background:#080d10;color:#fff;}
.fgroup{margin-bottom:12px;}

.btn-pay{width:100%;background:linear-gradient(135deg,#059669,#047857);color:#fff;border:none;border-radius:11px;padding:14px;font-family:inherit;font-size:.93rem;font-weight:700;cursor:pointer;box-shadow:0 4px 18px rgba(5,150,105,.3);transition:all .2s;margin-top:4px;}
.btn-pay:hover{transform:translateY(-1px);filter:brightness(1.08);}

.alert{padding:11px 16px;border-radius:12px;font-size:.82rem;margin-bottom:18px;display:flex;align-items:center;gap:9px;}
.alert-success{background:rgba(16,185,129,.12);border:1px solid rgba(16,185,129,.25);color:#6ee7b7;}
.alert-error{background:rgba(239,68,68,.12);border:1px solid rgba(239,68,68,.25);color:#fca5a5;}

.empty-state{text-align:center;padding:48px 20px;color:rgba(255,255,255,.25);}
.empty-state .material-symbols-outlined{font-size:46px;display:block;margin:0 auto 14px;opacity:.3;}
.empty-state p{font-size:.82rem;}

.receipt-row{display:flex;justify-content:space-between;margin-bottom:5px;font-size:.79rem;}
.receipt-row span:first-child{color:rgba(255,255,255,.35);}
.receipt-row span:last-child{font-weight:600;color:rgba(255,255,255,.75);}

.pay-grid{display:grid;grid-template-columns:1.1fr 1fr;gap:18px;margin-bottom:18px;}

@media(max-width:1000px){.stats-row{grid-template-columns:repeat(2,1fr);}.pay-grid{grid-template-columns:1fr;}}
</style>
</head>
<body>
<div class="bg-scene">
  <img src="https://images.unsplash.com/photo-1563013544-824ae1b704d3?w=1600&auto=format&fit=crop&q=60" alt="">
  <div class="bg-overlay"></div>
</div>

<aside class="sidebar">
  <div class="sb-brand">
    <div class="sb-logo">
      <?php if($logo_url): ?>
        <img src="<?=htmlspecialchars($logo_url)?>" alt="logo">
      <?php else: ?>
        <svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2.5"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
      <?php endif; ?>
    </div>
    <div>
      <div class="sb-name"><?=htmlspecialchars($business_name)?></div>
      <div class="sb-subtitle">Cashier Station</div>
    </div>
  </div>

  <?php if($tenant): ?>
  <div class="sb-tenant-card">
    <div class="sb-tenant-label">My Branch</div>
    <div class="sb-tenant-name"><?=htmlspecialchars($tenant['business_name'])?></div>
    <div class="sb-tenant-badge">Tenant #<?=$tenant['id']?></div>
  </div>
  <?php endif; ?>

  <div class="sb-user">
    <div class="sb-avatar"><?=strtoupper(substr($u['name'],0,1))?></div>
    <div>
      <div class="sb-uname"><?=htmlspecialchars(explode(' ',$u['name'])[0]??$u['name'])?></div>
      <div class="sb-urole">Cashier</div>
      <div class="sb-status">● ON SHIFT</div>
    </div>
  </div>

  <nav class="sb-nav">
    <div class="sb-section">Main</div>
    <a href="?page=dashboard" class="sb-item <?=$active_page==='dashboard'?'active':''?>">
      <span class="material-symbols-outlined">dashboard</span>Dashboard
    </a>
    <a href="?page=process" class="sb-item <?=$active_page==='process'?'active':''?>">
      <span class="material-symbols-outlined">credit_card</span>Process Payment
    </a>
    <div class="sb-section">Records</div>
    <a href="?page=tickets" class="sb-item <?=$active_page==='tickets'?'active':''?>">
      <span class="material-symbols-outlined">receipt_long</span>Active Tickets
    </a>
    <a href="?page=history" class="sb-item <?=$active_page==='history'?'active':''?>">
      <span class="material-symbols-outlined">history</span>My Transactions
    </a>
    <a href="?page=inventory" class="sb-item <?=$active_page==='inventory'?'active':''?>">
      <span class="material-symbols-outlined">inventory_2</span>View Inventory
    </a>
  </nav>
  <div class="sb-footer">
    <a href="logout.php" class="sb-logout">
      <span class="material-symbols-outlined">logout</span>Sign Out
    </a>
  </div>
</aside>

<div class="main">
  <header class="topbar">
    <div style="display:flex;align-items:center;gap:10px;">
      <span class="topbar-title"><?php $titles=['dashboard'=>'Cashier Dashboard','process'=>'Process Payment','tickets'=>'Active Tickets','history'=>'My Transactions','inventory'=>'View Inventory'];echo $titles[$active_page]??'Dashboard';?></span>
      <span class="cashier-chip">Cashier<?php if($tenant): ?> · <?=htmlspecialchars($tenant['business_name'])?><?php endif;?></span>
    </div>
    <span style="font-size:.75rem;color:rgba(255,255,255,.3);">📅 <?=date('M d, Y')?></span>
  </header>

  <div class="content">
  <?php if($success_msg):?><div class="alert alert-success"><span class="material-symbols-outlined" style="font-size:17px;">check_circle</span><?=htmlspecialchars($success_msg)?></div><?php endif;?>
  <?php if($error_msg):?><div class="alert alert-error"><span class="material-symbols-outlined" style="font-size:17px;">warning</span><?=htmlspecialchars($error_msg)?></div><?php endif;?>

  <?php if(!$tid): ?>
    <div style="background:rgba(245,158,11,.1);border:1px solid rgba(245,158,11,.2);border-radius:14px;padding:26px;text-align:center;color:#fcd34d;">
      <div style="font-size:1.1rem;font-weight:700;margin-bottom:8px;">⚠️ No Tenant Assigned</div>
      <p style="font-size:.85rem;opacity:.7;">Your account has not been assigned to a branch yet. Please contact your Admin.</p>
    </div>

  <?php elseif($active_page==='dashboard'): ?>
    <div class="page-hdr">
      <div>
        <h2>Good <?=date('G')<12?'Morning':(date('G')<17?'Afternoon':'Evening')?>, <?=htmlspecialchars(explode(' ',$u['name'])[0])?>! 💳</h2>
        <p>Your cashier summary for today — <?=date('F j, Y')?>.</p>
      </div>
    </div>

    <?php if($tenant): ?>
    <div style="background:linear-gradient(135deg,#064e3b,#059669);border-radius:14px;padding:18px 22px;margin-bottom:20px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;border:1px solid rgba(16,185,129,.2);">
      <div>
        <div style="font-size:.65rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:rgba(255,255,255,.5);margin-bottom:4px;">Your Branch</div>
        <div style="font-size:1.05rem;font-weight:800;color:#fff;"><?=htmlspecialchars($tenant['business_name'])?></div>
        <div style="font-size:.76rem;color:rgba(255,255,255,.6);margin-top:2px;"><?=$tenant['plan']?> Plan · Tenant #<?=$tid?></div>
      </div>
      <div style="text-align:right;">
        <div style="font-size:.65rem;color:rgba(255,255,255,.5);margin-bottom:3px;">Active Tickets</div>
        <div style="font-size:1.5rem;font-weight:800;color:#fff;"><?=$active_count?></div>
      </div>
    </div>
    <?php endif; ?>

    <div class="stats-row">
      <div class="stat-card"><div class="stat-top"><div class="stat-icon" style="background:rgba(16,185,129,.15);"><span class="material-symbols-outlined" style="color:#6ee7b7;">payments</span></div></div><div class="stat-value">₱<?=number_format($collections_today,0)?></div><div class="stat-label">My Collections Today</div></div>
      <div class="stat-card"><div class="stat-top"><div class="stat-icon" style="background:rgba(59,130,246,.15);"><span class="material-symbols-outlined" style="color:#93c5fd;">credit_card</span></div></div><div class="stat-value"><?=$txn_today?></div><div class="stat-label">Transactions Today</div></div>
      <div class="stat-card"><div class="stat-top"><div class="stat-icon" style="background:rgba(245,158,11,.15);"><span class="material-symbols-outlined" style="color:#fcd34d;">receipt_long</span></div></div><div class="stat-value"><?=$active_count?></div><div class="stat-label">Active Tickets</div></div>
      <div class="stat-card"><div class="stat-top"><div class="stat-icon" style="background:rgba(239,68,68,.15);"><span class="material-symbols-outlined" style="color:#fca5a5;">warning</span></div></div><div class="stat-value" style="color:<?=$overdue_count>0?'#fca5a5':'#fff'?>;"><?=$overdue_count?></div><div class="stat-label">Overdue Tickets</div></div>
    </div>

    <div style="display:grid;grid-template-columns:260px 1fr;gap:16px;">
      <div class="card" style="display:flex;flex-direction:column;gap:10px;">
        <div class="card-title">⚡ Quick Actions</div>
        <a href="?page=process" style="display:flex;align-items:center;gap:11px;padding:13px 15px;background:linear-gradient(135deg,#059669,#047857);color:#fff;border-radius:11px;text-decoration:none;font-size:.84rem;font-weight:600;transition:all .2s;">
          <span class="material-symbols-outlined" style="font-size:18px;">credit_card</span>Process Payment
        </a>
        <a href="?page=tickets" style="display:flex;align-items:center;gap:11px;padding:13px 15px;background:rgba(255,255,255,.05);color:rgba(255,255,255,.7);border:1px solid rgba(255,255,255,.08);border-radius:11px;text-decoration:none;font-size:.84rem;font-weight:600;transition:all .2s;">
          <span class="material-symbols-outlined" style="color:#93c5fd;font-size:18px;">receipt_long</span>View Active Tickets
        </a>
        <a href="?page=history" style="display:flex;align-items:center;gap:11px;padding:13px 15px;background:rgba(255,255,255,.05);color:rgba(255,255,255,.7);border:1px solid rgba(255,255,255,.08);border-radius:11px;text-decoration:none;font-size:.84rem;font-weight:600;transition:all .2s;">
          <span class="material-symbols-outlined" style="color:#6ee7b7;font-size:18px;">history</span>Transaction History
        </a>
      </div>
      <div class="card">
        <div class="card-title">Recent Transactions</div>
        <?php if(empty($my_payments)): ?>
        <div class="empty-state"><span class="material-symbols-outlined">receipt_long</span><p>No transactions yet today.</p></div>
        <?php else: ?>
        <table><thead><tr><th>Ticket</th><th>Action</th><th>Amount</th><th>OR No.</th><th>Time</th></tr></thead><tbody>
        <?php foreach(array_slice($my_payments,0,5) as $p): ?>
        <tr><td><span class="ticket-tag"><?=htmlspecialchars($p['ticket_no'])?></span></td><td><span class="badge <?=$p['action']==='release'?'b-green':'b-yellow'?>"><?=ucfirst($p['action'])?></span></td><td style="font-weight:700;color:#fff;">₱<?=number_format($p['amount_due'],2)?></td><td style="font-size:.73rem;color:rgba(255,255,255,.35);"><?=htmlspecialchars($p['or_no']??'—')?></td><td style="font-size:.73rem;color:rgba(255,255,255,.35);"><?=date('h:i A',strtotime($p['created_at']))?></td></tr>
        <?php endforeach; ?></tbody></table>
        <?php endif; ?>
      </div>
    </div>

  <?php elseif($active_page==='process'): ?>
    <div class="page-hdr"><div><h2>Process Payment</h2><p>Accept payments for active pawn tickets</p></div></div>
    <div class="pay-grid">
      <div class="card">
        <div class="card-title">Payment Form</div>
        <?php if(empty($active_tickets)): ?>
        <div class="empty-state"><span class="material-symbols-outlined">credit_card</span><p>No active tickets to process.</p></div>
        <?php else: ?>
        <form method="POST">
          <input type="hidden" name="action" value="process_payment">
          <div class="fgroup"><label class="flabel">Select Ticket *</label>
            <select name="ticket_no" class="finput" required onchange="fillPayment(this)">
              <option value="">-- Select Active Ticket --</option>
              <?php foreach($active_tickets as $t): ?>
              <option value="<?=htmlspecialchars($t['ticket_no'])?>"
                data-customer="<?=htmlspecialchars($t['customer_name'])?>"
                data-item="<?=htmlspecialchars($t['item_category'])?> - <?=htmlspecialchars(substr($t['item_description']??'',0,25))?>"
                data-loan="<?=$t['loan_amount']?>"
                data-interest="<?=$t['interest_amount']?>"
                data-total="<?=$t['total_redeem']?>"
                data-maturity="<?=$t['maturity_date']?>">
                <?=$t['ticket_no']?> — <?=htmlspecialchars($t['customer_name'])?> (₱<?=number_format($t['total_redeem'],2)?>)
              </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="fgroup"><label class="flabel">Action *</label>
            <select name="pay_action" class="finput" required>
              <option value="release">Release (Full Redemption)</option>
              <option value="renew">Renew / Extension</option>
            </select>
          </div>
          <div class="fgroup"><label class="flabel">OR Number</label><input type="text" name="or_no" class="finput" placeholder="OR-YYYYMMDD-XXXXX"></div>
          <div class="fgroup"><label class="flabel">Amount Due (₱)</label><input type="number" name="amount_due" id="p_due" class="finput" placeholder="0.00" step="0.01" oninput="calcChange()" required></div>
          <div class="fgroup"><label class="flabel">Cash Received (₱) *</label><input type="number" name="cash_received" id="p_cash" class="finput" placeholder="0.00" step="0.01" oninput="calcChange()" required></div>
          <div style="background:rgba(16,185,129,.08);border:1px solid rgba(16,185,129,.2);border-radius:10px;padding:11px 14px;font-size:.8rem;margin-bottom:14px;display:flex;justify-content:space-between;align-items:center;">
            <span style="color:rgba(110,231,183,.7);">Change:</span>
            <span id="p_change" style="font-weight:800;font-size:.96rem;color:#6ee7b7;">₱0.00</span>
          </div>
          <button type="submit" class="btn-pay">✓ Process Payment</button>
        </form>
        <?php endif; ?>
      </div>

      <div class="card" style="background:rgba(255,255,255,.02);">
        <div class="card-title">Receipt Preview</div>
        <div style="background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.08);border-radius:12px;padding:16px;font-size:.79rem;">
          <div style="text-align:center;margin-bottom:12px;">
            <div style="font-weight:800;font-size:.94rem;color:#fff;"><?=htmlspecialchars($sys_name)?></div>
            <div style="font-size:.71rem;color:rgba(255,255,255,.4);"><?=htmlspecialchars($tenant['business_name']??'Branch')?></div>
            <div style="font-size:.71rem;color:rgba(255,255,255,.3);">Tenant #<?=$tid?></div>
            <div style="font-size:.71rem;color:rgba(255,255,255,.3);"><?=date('M d, Y h:i A')?></div>
          </div>
          <hr style="border:none;border-top:1px dashed rgba(255,255,255,.08);margin:10px 0;">
          <div class="receipt-row"><span>Ticket</span><span id="r_ticket">—</span></div>
          <div class="receipt-row"><span>Customer</span><span id="r_customer">—</span></div>
          <div class="receipt-row"><span>Item</span><span id="r_item">—</span></div>
          <div class="receipt-row"><span>Maturity</span><span id="r_maturity">—</span></div>
          <hr style="border:none;border-top:1px dashed rgba(255,255,255,.08);margin:10px 0;">
          <div class="receipt-row"><span>Principal</span><span id="r_loan">₱0.00</span></div>
          <div class="receipt-row"><span>Interest</span><span id="r_interest">₱0.00</span></div>
          <div class="receipt-row" style="font-weight:700;"><span style="color:rgba(255,255,255,.6);">Total Due</span><span id="r_total" style="color:#fff;">₱0.00</span></div>
          <hr style="border:none;border-top:1px dashed rgba(255,255,255,.08);margin:10px 0;">
          <div class="receipt-row"><span>Cash Received</span><span id="r_cash">₱0.00</span></div>
          <div class="receipt-row" style="font-weight:700;"><span style="color:#6ee7b7;">Change</span><span id="r_change2" style="color:#6ee7b7;">₱0.00</span></div>
          <hr style="border:none;border-top:1px dashed rgba(255,255,255,.08);margin:10px 0;">
          <div style="text-align:center;font-size:.7rem;color:rgba(255,255,255,.3);">Cashier: <?=htmlspecialchars($u['name'])?></div>
          <div style="text-align:center;font-size:.7rem;color:rgba(255,255,255,.25);margin-top:2px;">Thank you for choosing <?=htmlspecialchars($sys_name)?>!</div>
        </div>
        <button onclick="window.print()" style="width:100%;margin-top:10px;background:rgba(255,255,255,.05);color:rgba(255,255,255,.6);border:1px solid rgba(255,255,255,.1);border-radius:10px;padding:10px;font-family:inherit;font-size:.82rem;font-weight:600;cursor:pointer;transition:all .2s;">🖨️ Print Receipt</button>
      </div>
    </div>

  <?php elseif($active_page==='tickets'): ?>
    <div class="page-hdr"><div><h2>Active Tickets</h2><p><?=count($active_tickets)?> active tickets in branch</p></div><a href="?page=process" class="btn-sm btn-success">💳 Process Payment</a></div>
    <div class="card" style="overflow-x:auto;">
      <?php if(empty($active_tickets)): ?>
      <div class="empty-state"><span class="material-symbols-outlined">receipt_long</span><p>No active tickets.</p></div>
      <?php else: ?>
      <table><thead><tr><th>Ticket No.</th><th>Customer</th><th>Contact</th><th>Item</th><th>Loan</th><th>Total Redeem</th><th>Maturity</th><th>Action</th></tr></thead><tbody>
      <?php foreach($active_tickets as $t): ?>
      <tr>
        <td><span class="ticket-tag"><?=htmlspecialchars($t['ticket_no'])?></span></td>
        <td style="font-weight:600;color:#fff;"><?=htmlspecialchars($t['customer_name'])?></td>
        <td style="font-family:monospace;font-size:.75rem;"><?=htmlspecialchars($t['contact_number'])?></td>
        <td><?=htmlspecialchars($t['item_category'])?></td>
        <td>₱<?=number_format($t['loan_amount'],2)?></td>
        <td style="font-weight:700;color:#fff;">₱<?=number_format($t['total_redeem'],2)?></td>
        <td style="font-size:.73rem;color:<?=strtotime($t['maturity_date'])<time()?'#fca5a5':'rgba(255,255,255,.35)'?>;">
          <?=$t['maturity_date']?><?=strtotime($t['maturity_date'])<time()?' ⚠️':''?>
        </td>
        <td><a href="?page=process" class="btn-sm btn-success" style="font-size:.7rem;">Pay</a></td>
      </tr>
      <?php endforeach; ?></tbody></table>
      <?php endif; ?>
    </div>

  <?php elseif($active_page==='history'): ?>
    <div class="page-hdr"><div><h2>My Transactions</h2><p>All payments processed by you</p></div></div>
    <div class="card" style="overflow-x:auto;">
      <?php if(empty($my_payments)): ?>
      <div class="empty-state"><span class="material-symbols-outlined">history</span><p>No transactions processed yet.</p></div>
      <?php else: ?>
      <table><thead><tr><th>Date</th><th>Ticket</th><th>Action</th><th>Amount Due</th><th>Cash Received</th><th>Change</th><th>OR No.</th></tr></thead><tbody>
      <?php foreach($my_payments as $p): ?>
      <tr>
        <td style="font-size:.72rem;color:rgba(255,255,255,.35);"><?=date('M d, Y h:i A',strtotime($p['created_at']))?></td>
        <td><span class="ticket-tag"><?=htmlspecialchars($p['ticket_no'])?></span></td>
        <td><span class="badge <?=$p['action']==='release'?'b-green':'b-yellow'?>"><?=ucfirst($p['action'])?></span></td>
        <td style="font-weight:700;color:#fff;">₱<?=number_format($p['amount_due'],2)?></td>
        <td>₱<?=number_format($p['cash_received'],2)?></td>
        <td style="color:#6ee7b7;">₱<?=number_format($p['change_amount'],2)?></td>
        <td style="font-size:.73rem;color:rgba(255,255,255,.35);"><?=htmlspecialchars($p['or_no']??'—')?></td>
      </tr>
      <?php endforeach; ?></tbody></table>
      <?php endif; ?>
    </div>

  <?php elseif($active_page==='inventory'): ?>
    <div class="page-hdr"><div><h2>Item Inventory</h2><p>View only — contact staff to add items</p></div></div>
    <div class="card" style="overflow-x:auto;">
      <?php if(empty($inventory_list)): ?>
      <div class="empty-state"><span class="material-symbols-outlined">inventory_2</span><p>No items in inventory.</p></div>
      <?php else: ?>
      <table><thead><tr><th>Ticket</th><th>Item</th><th>Category</th><th>Appraisal</th><th>Loan</th><th>Status</th><th>Received</th></tr></thead><tbody>
      <?php foreach($inventory_list as $i): $sc=['pawned'=>'b-blue','redeemed'=>'b-green','voided'=>'b-red','auctioned'=>'b-gray']; ?>
      <tr>
        <td><span class="ticket-tag"><?=htmlspecialchars($i['ticket_no'])?></span></td>
        <td style="color:#fff;"><?=htmlspecialchars($i['item_name']??'—')?></td>
        <td><?=htmlspecialchars($i['item_category']??'—')?></td>
        <td>₱<?=number_format($i['appraisal_value']??0,2)?></td>
        <td style="color:var(--t-primary,#60a5fa);font-weight:600;">₱<?=number_format($i['loan_amount']??0,2)?></td>
        <td><span class="badge <?=$sc[$i['status']]??'b-yellow'?>"><span class="b-dot"></span><?=ucfirst($i['status'])?></span></td>
        <td style="font-size:.72rem;color:rgba(255,255,255,.35);"><?=date('M d, Y',strtotime($i['received_at']))?></td>
      </tr>
      <?php endforeach; ?></tbody></table>
      <?php endif; ?>
    </div>
  <?php endif; ?>
  </div>
</div>

<script>
function fillPayment(sel) {
  const o = sel.options[sel.selectedIndex];
  const total = parseFloat(o.dataset.total) || 0;
  document.getElementById('p_due').value = total.toFixed(2);
  document.getElementById('r_ticket').textContent   = o.value || '—';
  document.getElementById('r_customer').textContent = o.dataset.customer || '—';
  document.getElementById('r_item').textContent     = o.dataset.item || '—';
  document.getElementById('r_maturity').textContent = o.dataset.maturity || '—';
  document.getElementById('r_loan').textContent     = '₱' + (parseFloat(o.dataset.loan)||0).toFixed(2);
  document.getElementById('r_interest').textContent = '₱' + (parseFloat(o.dataset.interest)||0).toFixed(2);
  document.getElementById('r_total').textContent    = '₱' + total.toFixed(2);
  calcChange();
}
function calcChange() {
  const due  = parseFloat(document.getElementById('p_due')?.value)  || 0;
  const cash = parseFloat(document.getElementById('p_cash')?.value) || 0;
  const ch   = Math.max(0, cash - due);
  document.getElementById('p_change').textContent  = '₱' + ch.toFixed(2);
  document.getElementById('r_cash').textContent    = '₱' + cash.toFixed(2);
  document.getElementById('r_change2').textContent = '₱' + ch.toFixed(2);
}
</script>
</body>
</html>