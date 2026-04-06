<?php
/**
 * tenant_subscription.php — Tenant Subscription Page
 *
 * Accessible via: /{slug}?page=subscription  (inside tenant.php)
 * OR as standalone: /tenant_subscription.php
 *
 * Para gamitin sa loob ng tenant.php, i-add sa page routing:
 *   } elseif ($active_page === 'subscription') {
 *       require __DIR__ . '/tenant_subscription_content.php';
 *   }
 *
 * PERO mas madali: i-access directly bilang standalone page.
 * Nag-handle ito ng sariling session check.
 */

require_once __DIR__ . '/session_helper.php';
pawnhub_session_start('admin');
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/theme_helper.php';

// ── Auth check ────────────────────────────────────────────────
if (empty($_SESSION['user'])) {
    header('Location: home.php'); exit;
}
$u   = $_SESSION['user'];
$tid = (int)$u['tenant_id'];

if ($u['role'] !== 'admin') {
    header('Location: home.php'); exit;
}

$success_msg = $error_msg = '';

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

// ── POST: Submit renewal request ──────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'request_renewal') {
    if ($pending_renewal) {
        $error_msg = 'You already have a pending renewal request. Please wait for admin approval.';
    } else {
        $billing_cycle  = in_array($_POST['billing_cycle'] ?? '', ['monthly','quarterly','annually'])
                          ? $_POST['billing_cycle'] : 'monthly';
        $payment_method = trim($_POST['payment_method']    ?? '');
        $payment_ref    = trim($_POST['payment_reference'] ?? '');
        $notes          = trim($_POST['notes']             ?? '');

        // Plan pricing
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

                // Confirmation email to tenant
                if (function_exists('sendRenewalRequestReceived')) {
                    sendRenewalRequestReceived($tenant['email'], $tenant['owner_name'], $tenant['business_name'], $plan, $slug);
                }

                // Audit log
                try {
                    $pdo->prepare("
                        INSERT INTO audit_logs (tenant_id,actor_user_id,actor_username,actor_role,action,entity_type,entity_id,message,ip_address,created_at)
                        VALUES (?,?,?,?,'RENEWAL_REQUEST','subscription',?,?,?,NOW())
                    ")->execute([$tid, $u['id'], $u['username'], $u['role'], $tid,
                        "Renewal request submitted for {$tenant['business_name']} ({$plan}, {$billing_cycle}).",
                        $_SERVER['REMOTE_ADDR'] ?? '::1']);
                } catch (Throwable $e) {}

                $success_msg = '✅ Renewal request submitted! Admin will review within 24 hours. A confirmation email has been sent to ' . htmlspecialchars($tenant['email']) . '.';

                // Reload pending
                $pending_renewal_stmt->execute([$tid]);
                $pending_renewal = $pending_renewal_stmt->fetch();

            } catch (PDOException $e) {
                $error_msg = 'Database error: ' . $e->getMessage();
            }
        }
    }
}

// ── Status badge helpers ──────────────────────────────────────
$status_map = [
    'active'        => ['Active',        '#15803d', '#f0fdf4', '#bbf7d0'],
    'expiring_soon' => ['Expiring Soon', '#d97706', '#fffbeb', '#fde68a'],
    'expired'       => ['Expired',       '#dc2626', '#fef2f2', '#fecaca'],
    'cancelled'     => ['Cancelled',     '#64748b', '#f8fafc', '#e2e8f0'],
];
$sb = $status_map[$sub_stat] ?? ['Unknown', '#64748b', '#f8fafc', '#e2e8f0'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1"/>
<title>Subscription — <?= htmlspecialchars($tenant['business_name']) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet"/>
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet"/>
<?= renderThemeCSS($theme) ?>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
:root{--sw:220px;}
body{background:#0f172a;font-family:'Plus Jakarta Sans',sans-serif;color:#f8fafc;min-height:100vh;display:flex;}
.material-symbols-outlined{font-variation-settings:'FILL' 1,'wght' 400,'GRAD' 0,'opsz' 24;vertical-align:middle;}
/* Sidebar */
.sidebar{width:var(--sw);min-height:100vh;background:rgba(15,23,42,.97);backdrop-filter:blur(24px);-webkit-backdrop-filter:blur(24px);border-right:1px solid rgba(255,255,255,.07);display:flex;flex-direction:column;position:fixed;left:0;top:0;bottom:0;z-index:100;}
.sb-brand{padding:18px 16px;border-bottom:1px solid rgba(255,255,255,.07);display:flex;align-items:center;gap:9px;}
.sb-logo{width:32px;height:32px;background:linear-gradient(135deg,var(--t-primary,#2563eb),var(--t-secondary,#1e3a8a));border-radius:9px;display:flex;align-items:center;justify-content:center;flex-shrink:0;overflow:hidden;}
.sb-logo img{width:100%;height:100%;object-fit:cover;}
.sb-name{font-size:.88rem;font-weight:800;color:#fff;}
.sb-user{padding:10px 16px;border-bottom:1px solid rgba(255,255,255,.07);display:flex;align-items:center;gap:8px;}
.sb-avatar{width:28px;height:28px;border-radius:50%;background:linear-gradient(135deg,var(--t-primary,#2563eb),var(--t-secondary,#1e3a8a));display:flex;align-items:center;justify-content:center;font-size:.72rem;font-weight:700;color:#fff;flex-shrink:0;}
.sb-uname{font-size:.76rem;font-weight:600;color:#fff;}
.sb-urole{font-size:.6rem;color:rgba(255,255,255,.35);}
.sb-nav{flex:1;padding:8px 0;}
.sb-section{font-size:.58rem;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:rgba(255,255,255,.2);padding:10px 14px 4px;}
.sb-item{display:flex;align-items:center;gap:9px;padding:8px 12px;margin:1px 6px;border-radius:9px;cursor:pointer;color:rgba(255,255,255,.45);font-size:.8rem;font-weight:500;text-decoration:none;transition:all .15s;}
.sb-item:hover{background:rgba(255,255,255,.07);color:rgba(255,255,255,.9);}
.sb-item.active{background:rgba(255,255,255,.1);color:#fff;font-weight:700;}
.sb-item .material-symbols-outlined{font-size:16px;flex-shrink:0;}
.sb-footer{padding:10px 12px;border-top:1px solid rgba(255,255,255,.07);}
.sb-logout{display:flex;align-items:center;gap:8px;font-size:.78rem;color:rgba(255,255,255,.35);text-decoration:none;padding:7px 8px;border-radius:8px;transition:all .15s;}
.sb-logout:hover{color:#f87171;background:rgba(239,68,68,.1);}
/* Main */
.main{margin-left:var(--sw);flex:1;display:flex;flex-direction:column;}
.topbar{height:60px;padding:0 26px;background:rgba(10,14,26,.7);backdrop-filter:blur(20px);border-bottom:1px solid rgba(255,255,255,.06);display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:50;}
.content{padding:24px 28px;flex:1;}
.page-title{font-size:1.3rem;font-weight:800;margin-bottom:4px;}
.page-sub{color:rgba(255,255,255,.4);font-size:.82rem;margin-bottom:24px;}
.card{background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.09);border-radius:16px;padding:22px;margin-bottom:18px;}
.card-label{font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.09em;color:rgba(255,255,255,.35);margin-bottom:14px;}
.stat-row{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:14px;margin-bottom:4px;}
.stat-box{background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.07);border-radius:12px;padding:16px;}
.stat-label{font-size:.67rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:rgba(255,255,255,.35);margin-bottom:6px;}
.stat-value{font-size:1.1rem;font-weight:800;color:#fff;}
.stat-hint{font-size:.71rem;color:rgba(255,255,255,.3);margin-top:2px;}
.badge{display:inline-block;padding:3px 11px;border-radius:100px;font-size:.72rem;font-weight:700;}
.alert{border-radius:11px;padding:12px 16px;font-size:.82rem;margin-bottom:18px;line-height:1.6;}
.alert-success{background:rgba(21,128,61,.12);border:1px solid rgba(21,128,61,.3);color:#86efac;}
.alert-error{background:rgba(220,38,38,.1);border:1px solid rgba(220,38,38,.25);color:#fca5a5;}
.alert-warn{background:rgba(217,119,6,.1);border:1px solid rgba(217,119,6,.25);color:#fcd34d;}
.alert-info{background:rgba(37,99,235,.1);border:1px solid rgba(37,99,235,.25);color:#93c5fd;}
.flabel{display:block;font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:rgba(255,255,255,.4);margin-bottom:5px;}
.finput,.fselect{width:100%;background:rgba(255,255,255,.07);border:1.5px solid rgba(255,255,255,.1);border-radius:9px;color:#fff;font-family:inherit;font-size:.86rem;padding:10px 13px;outline:none;transition:border-color .2s,background .2s;}
.finput:focus,.fselect:focus{border-color:var(--t-primary,#2563eb);background:rgba(255,255,255,.1);}
.finput::placeholder{color:rgba(255,255,255,.25);}
.fselect option{background:#1e293b;color:#fff;}
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px;}
.form-group{margin-bottom:14px;}
.btn{display:inline-flex;align-items:center;gap:7px;padding:10px 22px;border-radius:9px;font-family:inherit;font-size:.86rem;font-weight:700;cursor:pointer;border:none;transition:transform .15s;}
.btn-primary{background:var(--t-primary,#2563eb);color:#fff;box-shadow:0 4px 14px rgba(37,99,235,.25);}
.btn-primary:hover{transform:translateY(-1px);}
.btn-ghost{background:rgba(255,255,255,.07);border:1px solid rgba(255,255,255,.12);color:rgba(255,255,255,.7);}
.pending-box{background:rgba(37,99,235,.08);border:1px solid rgba(37,99,235,.2);border-radius:12px;padding:18px;}
.history-table{width:100%;border-collapse:collapse;font-size:.8rem;}
.history-table th{text-align:left;padding:7px 11px;color:rgba(255,255,255,.35);font-size:.67rem;text-transform:uppercase;letter-spacing:.07em;border-bottom:1px solid rgba(255,255,255,.07);}
.history-table td{padding:9px 11px;border-bottom:1px solid rgba(255,255,255,.04);color:rgba(255,255,255,.75);vertical-align:middle;}
.history-table tr:last-child td{border-bottom:none;}
.expiry-bar-wrap{background:rgba(255,255,255,.08);border-radius:100px;height:6px;margin-top:10px;overflow:hidden;}
.expiry-bar{height:100%;border-radius:100px;}
@media(max-width:600px){.form-grid{grid-template-columns:1fr;}.sidebar{display:none;}.main{margin-left:0;}}
</style>
</head>
<body>

<!-- ── Sidebar ─────────────────────────────────────────────── -->
<aside class="sidebar">
  <div class="sb-brand">
    <div class="sb-logo">
      <?php if($logo_url): ?><img src="<?= htmlspecialchars($logo_url) ?>" alt="logo">
      <?php else: ?><svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2.5"><rect x="3" y="9" width="18" height="12"/><polyline points="3 9 12 3 21 9"/></svg><?php endif; ?>
    </div>
    <div>
      <div class="sb-name"><?= htmlspecialchars($logo_text) ?></div>
    </div>
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

<!-- ── Main ───────────────────────────────────────────────── -->
<div class="main">
  <header class="topbar">
    <div style="display:flex;align-items:center;gap:10px;">
      <a href="tenant.php?page=dashboard" style="color:rgba(255,255,255,.4);text-decoration:none;display:flex;align-items:center;gap:5px;font-size:.8rem;">
        <span class="material-symbols-outlined" style="font-size:16px;font-variation-settings:'FILL' 0,'wght' 400,'GRAD' 0,'opsz' 24;">arrow_back</span>
        Back to Dashboard
      </a>
    </div>
    <div style="font-size:.78rem;color:rgba(255,255,255,.3);"><?= date('F d, Y') ?></div>
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

    <!-- ── Current Subscription Status ──────────────────────── -->
    <div class="card">
      <div class="card-label">Current Subscription</div>

      <?php if ($sub_stat === 'expired' || ($days_left !== null && $days_left < 0)): ?>
      <div class="alert alert-error" style="margin-bottom:16px;">
        ⛔ <strong>Your subscription has expired.</strong> Submit a renewal request below to restore full access.
      </div>
      <?php elseif ($sub_stat === 'expiring_soon' || ($days_left !== null && $days_left <= 7)): ?>
      <div class="alert alert-warn" style="margin-bottom:16px;">
        ⏰ <strong>Your subscription is expiring soon.</strong> Renew now to avoid service interruption.
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

    <!-- ── Pending Renewal ──────────────────────────────────── -->
    <?php if ($pending_renewal): ?>
    <div class="card">
      <div class="card-label">Pending Renewal Request</div>
      <div class="pending-box">
        <p style="color:#93c5fd;font-size:.86rem;font-weight:600;margin-bottom:12px;">
          📋 Your renewal request has been submitted and is under review.
        </p>
        <p style="color:rgba(255,255,255,.5);font-size:.8rem;margin-bottom:12px;">
          Our admin will review your payment and activate your subscription within 24 hours. You will receive an email once approved.
        </p>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;font-size:.8rem;">
          <div><span style="color:rgba(255,255,255,.4);">Plan:</span> <strong><?= htmlspecialchars($pending_renewal['plan']) ?></strong></div>
          <div><span style="color:rgba(255,255,255,.4);">Billing:</span> <strong><?= ucfirst($pending_renewal['billing_cycle']) ?></strong></div>
          <div><span style="color:rgba(255,255,255,.4);">Payment:</span> <strong><?= htmlspecialchars($pending_renewal['payment_method']) ?></strong></div>
          <div><span style="color:rgba(255,255,255,.4);">Ref #:</span> <strong><?= htmlspecialchars($pending_renewal['payment_reference'] ?: '—') ?></strong></div>
          <div><span style="color:rgba(255,255,255,.4);">Submitted:</span> <strong><?= date('M d, Y h:i A', strtotime($pending_renewal['requested_at'])) ?></strong></div>
          <?php if ($pending_renewal['amount'] > 0): ?>
          <div><span style="color:rgba(255,255,255,.4);">Amount:</span> <strong>₱<?= number_format($pending_renewal['amount'], 2) ?></strong></div>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <?php else: ?>

    <!-- ── Renewal Request Form ──────────────────────────────── -->
    <div class="card">
      <div class="card-label">Request Subscription Renewal</div>

      <?php if ($plan === 'Starter'): ?>
      <div class="alert alert-info" style="margin-bottom:18px;">
        ℹ️ You are on the <strong>Starter Plan</strong>. Submit a renewal request to extend your access period.
      </div>
      <?php endif; ?>

      <form method="POST" action="">
        <input type="hidden" name="action" value="request_renewal"/>

        <div class="form-grid">
          <div>
            <label class="flabel">Current Plan</label>
            <input type="text" class="finput" value="<?= htmlspecialchars($plan) ?>" disabled/>
          </div>
          <div>
            <label class="flabel">Billing Cycle</label>
            <select name="billing_cycle" class="fselect" required id="billing_cycle_sel" onchange="updateAmount()">
              <option value="monthly">Monthly</option>
              <option value="quarterly">Quarterly (save ~10%)</option>
              <option value="annually">Annually (save ~20%)</option>
            </select>
          </div>
        </div>

        <?php if ($plan !== 'Starter'): ?>
        <div class="form-group">
          <label class="flabel">Estimated Amount</label>
          <div id="amount-display" style="background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.08);border-radius:9px;padding:10px 14px;font-size:1rem;font-weight:800;color:#60a5fa;">
            ₱999.00
          </div>
        </div>
        <?php endif; ?>

        <div class="form-group">
          <label class="flabel">Payment Method</label>
          <select name="payment_method" class="fselect" required>
            <option value="">— Select Payment Method —</option>
            <option value="GCash">GCash</option>
            <option value="Maya">Maya (PayMaya)</option>
            <option value="Bank Transfer - BDO">Bank Transfer — BDO</option>
            <option value="Bank Transfer - BPI">Bank Transfer — BPI</option>
            <option value="Bank Transfer - UnionBank">Bank Transfer — UnionBank</option>
            <option value="Bank Transfer - Metrobank">Bank Transfer — Metrobank</option>
            <option value="Cash">Cash (walk-in)</option>
            <option value="Other">Other</option>
          </select>
        </div>

        <div class="form-group">
          <label class="flabel">Payment Reference / Transaction No. <span style="color:rgba(255,255,255,.25);font-weight:400;">(optional)</span></label>
          <input type="text" name="payment_reference" class="finput" placeholder="e.g. GCash ref #1234567890"/>
        </div>

        <div class="form-group">
          <label class="flabel">Additional Notes <span style="color:rgba(255,255,255,.25);font-weight:400;">(optional)</span></label>
          <textarea name="notes" class="finput" rows="3" style="height:auto;resize:vertical;" placeholder="Any notes for the admin..."></textarea>
        </div>

        <div style="background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.07);border-radius:10px;padding:14px 16px;margin-bottom:18px;">
          <p style="font-size:.76rem;color:rgba(255,255,255,.4);line-height:1.7;margin:0;">
            📌 <strong style="color:rgba(255,255,255,.6);">Payment Instructions:</strong><br>
            After submitting, please send your payment to our GCash/bank account and include your business name as reference.
            Our admin will verify and activate your subscription within 24 hours.
          </p>
        </div>

        <button type="submit" class="btn btn-primary">
          <span class="material-symbols-outlined" style="font-size:17px;">send</span>
          Submit Renewal Request
        </button>
      </form>
    </div>
    <?php endif; ?>

    <!-- ── Renewal History ──────────────────────────────────── -->
    <?php if (!empty($renewal_history)): ?>
    <div class="card">
      <div class="card-label">Renewal History</div>
      <div style="overflow-x:auto;">
        <table class="history-table">
          <thead>
            <tr>
              <th>Date</th><th>Plan</th><th>Billing</th><th>Method</th><th>Amount</th><th>Status</th><th>New Expiry</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($renewal_history as $r):
              $rc_color = match($r['status']) { 'approved' => '#15803d', 'rejected' => '#dc2626', default => '#d97706' };
              $rc_bg    = match($r['status']) { 'approved' => '#f0fdf4', 'rejected' => '#fef2f2', default => '#fffbeb' };
            ?>
            <tr>
              <td style="font-size:.76rem;color:rgba(255,255,255,.4);"><?= date('M d, Y', strtotime($r['requested_at'])) ?></td>
              <td><?= htmlspecialchars($r['plan']) ?></td>
              <td><?= ucfirst($r['billing_cycle']) ?></td>
              <td style="color:rgba(255,255,255,.5);"><?= htmlspecialchars($r['payment_method'] ?: '—') ?></td>
              <td>₱<?= number_format($r['amount'], 2) ?></td>
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
function updateAmount() {
    const cycle = document.getElementById('billing_cycle_sel')?.value || 'monthly';
    const amt = prices[plan]?.[cycle] ?? 0;
    const el = document.getElementById('amount-display');
    if (el) {
        el.textContent = amt > 0 ? '₱' + amt.toLocaleString('en-PH', {minimumFractionDigits:2}) : 'Free';
    }
}
updateAmount();
</script>
</body>
</html>