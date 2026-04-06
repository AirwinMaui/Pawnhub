<?php
/**
 * tenant_subscription.php — Tenant Subscription Management Page
 *
 * HOW TO USE:
 *   This is a standalone page but follows your tenant.php pattern.
 *   Include/require this file inside your tenant.php page routing, OR
 *   access it directly: /{slug}?page=subscription
 *
 *   In your tenant.php, inside the page routing section, add:
 *     case 'subscription': require __DIR__ . '/tenant_subscription.php'; break;
 *
 *   OR just link directly to: tenant_subscription.php
 *   (it does its own session check)
 */

require_once __DIR__ . '/session_helper.php';
pawnhub_session_start('admin');
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/theme_helper.php';

if (empty($_SESSION['user'])) { header('Location: home.php'); exit; }
$u   = $_SESSION['user'];
$tid = (int)$u['tenant_id'];
if (!in_array($u['role'], ['admin','manager'])) { header('Location: home.php'); exit; }

$success_msg = $error_msg = '';

// ── Load tenant info ──────────────────────────────────────────────────────
$tenant = $pdo->prepare("SELECT * FROM tenants WHERE id = ? LIMIT 1");
$tenant->execute([$tid]);
$tenant = $tenant->fetch();
if (!$tenant) { die('Tenant not found.'); }

$theme    = getTenantTheme($pdo, $tid);
$slug     = $tenant['slug'] ?? '';
$plan     = $tenant['plan'] ?? 'Starter';
$sub_end  = $tenant['subscription_end'] ?? null;
$sub_stat = $tenant['subscription_status'] ?? 'active';
$days_left = $sub_end ? (int)ceil((strtotime($sub_end) - time()) / 86400) : null;

// ── Load existing pending renewal ─────────────────────────────────────────
$pending_renewal = $pdo->prepare("
    SELECT * FROM subscription_renewals
    WHERE tenant_id = ? AND status = 'pending'
    ORDER BY requested_at DESC LIMIT 1
");
$pending_renewal->execute([$tid]);
$pending_renewal = $pending_renewal->fetch();

// ── Load renewal history ──────────────────────────────────────────────────
$renewal_history = $pdo->prepare("
    SELECT sr.*, u.fullname AS reviewed_by_name
    FROM subscription_renewals sr
    LEFT JOIN users u ON sr.reviewed_by = u.id
    WHERE sr.tenant_id = ?
    ORDER BY sr.requested_at DESC
    LIMIT 10
");
$renewal_history->execute([$tid]);
$renewal_history = $renewal_history->fetchAll();

// ── POST: Submit renewal request ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'request_renewal') {
    if ($pending_renewal) {
        $error_msg = 'You already have a pending renewal request. Please wait for admin approval.';
    } else {
        $billing_cycle    = in_array($_POST['billing_cycle'] ?? '', ['monthly','quarterly','annually'])
                            ? $_POST['billing_cycle'] : 'monthly';
        $payment_method   = trim($_POST['payment_method']   ?? '');
        $payment_ref      = trim($_POST['payment_reference'] ?? '');
        $notes            = trim($_POST['notes']            ?? '');

        // Amount calculation (can be loaded from system_settings)
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

                // Send confirmation to tenant
                sendRenewalRequestReceived($tenant['email'], $tenant['owner_name'], $tenant['business_name'], $plan, $slug);

                // Audit log
                try {
                    $pdo->prepare("
                        INSERT INTO audit_logs (tenant_id,actor_user_id,actor_username,actor_role,action,entity_type,entity_id,message,ip_address,created_at)
                        VALUES (?,?,?,?,'RENEWAL_REQUEST','subscription',?,?,?,NOW())
                    ")->execute([$tid, $u['id'], $u['username'], $u['role'], $tid,
                        "Renewal request submitted for {$tenant['business_name']} ({$plan}, {$billing_cycle}).",
                        $_SERVER['REMOTE_ADDR'] ?? '::1']);
                } catch (Throwable $e) {}

                $success_msg = '✅ Renewal request submitted! Admin will review within 24 hours. A confirmation email has been sent.';

                // Reload
                $pending_renewal = $pdo->prepare("SELECT * FROM subscription_renewals WHERE tenant_id=? AND status='pending' ORDER BY requested_at DESC LIMIT 1");
                $pending_renewal->execute([$tid]);
                $pending_renewal = $pending_renewal->fetch();

            } catch (PDOException $e) {
                $error_msg = 'Database error: ' . $e->getMessage();
            }
        }
    }
}

// ── Status helpers ────────────────────────────────────────────────────────
$status_badge = match($sub_stat) {
    'active'        => ['label' => 'Active',         'color' => '#15803d', 'bg' => '#f0fdf4', 'border' => '#bbf7d0'],
    'expiring_soon' => ['label' => 'Expiring Soon',  'color' => '#d97706', 'bg' => '#fffbeb', 'border' => '#fde68a'],
    'expired'       => ['label' => 'Expired',        'color' => '#dc2626', 'bg' => '#fef2f2', 'border' => '#fecaca'],
    'cancelled'     => ['label' => 'Cancelled',      'color' => '#64748b', 'bg' => '#f8fafc', 'border' => '#e2e8f0'],
    default         => ['label' => ucfirst($sub_stat),'color' => '#475569', 'bg' => '#f8fafc', 'border' => '#e2e8f0'],
};
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
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
body { background: #0f172a; font-family: 'Plus Jakarta Sans', sans-serif; color: #f8fafc; min-height: 100vh; }
.page-wrap { max-width: 760px; margin: 0 auto; padding: 36px 20px 60px; }
.back-link { display: inline-flex; align-items: center; gap: 6px; color: rgba(255,255,255,.5); font-size: .82rem; text-decoration: none; margin-bottom: 24px; transition: color .2s; }
.back-link:hover { color: #fff; }
.material-symbols-outlined { font-variation-settings: 'FILL' 1,'wght' 400,'GRAD' 0,'opsz' 24; vertical-align: middle; }
.page-title { font-size: 1.6rem; font-weight: 800; margin-bottom: 4px; }
.page-sub   { color: rgba(255,255,255,.45); font-size: .84rem; margin-bottom: 28px; }
.card { background: rgba(255,255,255,.06); border: 1px solid rgba(255,255,255,.10); border-radius: 16px; padding: 24px; margin-bottom: 20px; }
.card-title { font-size: .8rem; font-weight: 700; text-transform: uppercase; letter-spacing: .08em; color: rgba(255,255,255,.4); margin-bottom: 16px; }
.stat-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 16px; margin-bottom: 20px; }
.stat-box { background: rgba(255,255,255,.05); border: 1px solid rgba(255,255,255,.08); border-radius: 12px; padding: 18px; }
.stat-label { font-size: .71rem; font-weight: 700; text-transform: uppercase; letter-spacing: .07em; color: rgba(255,255,255,.4); margin-bottom: 8px; }
.stat-value { font-size: 1.25rem; font-weight: 800; color: #fff; }
.stat-hint  { font-size: .73rem; color: rgba(255,255,255,.35); margin-top: 3px; }
.badge { display: inline-block; padding: 3px 12px; border-radius: 100px; font-size: .74rem; font-weight: 700; }
.alert { border-radius: 12px; padding: 14px 18px; font-size: .84rem; margin-bottom: 20px; }
.alert-success { background: #f0fdf4; border: 1px solid #bbf7d0; color: #15803d; }
.alert-error   { background: #fef2f2; border: 1px solid #fecaca; color: #dc2626; }
.alert-warn    { background: #fffbeb; border: 1px solid #fde68a; color: #d97706; }
.alert-info    { background: rgba(37,99,235,.12); border: 1px solid rgba(37,99,235,.25); color: #93c5fd; }
.form-label { display: block; font-size: .71rem; font-weight: 700; text-transform: uppercase; letter-spacing: .08em; color: rgba(255,255,255,.5); margin-bottom: 6px; }
.form-input, .form-select { width: 100%; background: rgba(255,255,255,.07); border: 1.5px solid rgba(255,255,255,.12); border-radius: 10px; color: #fff; font-family: inherit; font-size: .88rem; padding: 11px 14px; outline: none; transition: border-color .2s, background .2s; }
.form-input:focus, .form-select:focus { border-color: var(--t-primary,#2563eb); background: rgba(255,255,255,.10); }
.form-input::placeholder { color: rgba(255,255,255,.3); }
.form-select option { background: #1e293b; color: #fff; }
.form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 16px; }
@media (max-width:560px) { .form-grid { grid-template-columns: 1fr; } }
.form-group { margin-bottom: 16px; }
.btn { display: inline-flex; align-items: center; gap: 7px; padding: 11px 24px; border-radius: 10px; font-family: inherit; font-size: .88rem; font-weight: 700; cursor: pointer; border: none; transition: transform .15s, box-shadow .15s; }
.btn-primary { background: var(--t-primary,#2563eb); color: #fff; box-shadow: 0 4px 14px rgba(37,99,235,.3); }
.btn-primary:hover { transform: translateY(-1px); }
.btn-sm { padding: 7px 16px; font-size: .8rem; }
.history-table { width: 100%; border-collapse: collapse; font-size: .82rem; }
.history-table th { text-align: left; padding: 8px 12px; color: rgba(255,255,255,.4); font-size: .7rem; text-transform: uppercase; letter-spacing: .07em; border-bottom: 1px solid rgba(255,255,255,.08); }
.history-table td { padding: 10px 12px; border-bottom: 1px solid rgba(255,255,255,.05); color: rgba(255,255,255,.8); vertical-align: middle; }
.history-table tr:last-child td { border-bottom: none; }
.pending-box { background: rgba(37,99,235,.12); border: 1px solid rgba(37,99,235,.25); border-radius: 12px; padding: 18px; }
.expiry-bar-wrap { background: rgba(255,255,255,.08); border-radius: 100px; height: 8px; margin-top: 12px; overflow: hidden; }
.expiry-bar { height: 100%; border-radius: 100px; transition: width .5s; }
</style>
</head>
<body>
<div class="page-wrap">

  <a href="javascript:history.back()" class="back-link">
    <span class="material-symbols-outlined" style="font-size:18px;font-variation-settings:'FILL' 0,'wght' 400,'GRAD' 0,'opsz' 24;">arrow_back</span>
    Back to Dashboard
  </a>

  <h1 class="page-title">
    <span class="material-symbols-outlined" style="font-size:1.5rem;margin-right:6px;">workspace_premium</span>
    Subscription
  </h1>
  <p class="page-sub">Manage your PawnHub subscription and billing.</p>

  <?php if ($success_msg): ?>
  <div class="alert alert-success"><?= htmlspecialchars($success_msg) ?></div>
  <?php endif; ?>
  <?php if ($error_msg): ?>
  <div class="alert alert-error"><?= htmlspecialchars($error_msg) ?></div>
  <?php endif; ?>

  <!-- ── Current Subscription Status ─────────────────────────── -->
  <div class="card">
    <div class="card-title">Current Subscription</div>

    <?php if ($sub_stat === 'expired'): ?>
    <div class="alert alert-error" style="margin-bottom:18px;">
      <strong>⚠️ Your subscription has expired.</strong> Submit a renewal request below to restore full access.
    </div>
    <?php elseif ($sub_stat === 'expiring_soon'): ?>
    <div class="alert alert-warn" style="margin-bottom:18px;">
      <strong>⏰ Your subscription is expiring soon.</strong> Renew now to avoid service interruption.
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
          <span class="badge" style="color:<?= $status_badge['color'] ?>;background:<?= $status_badge['bg'] ?>;border:1px solid <?= $status_badge['border'] ?>;">
            <?= $status_badge['label'] ?>
          </span>
        </div>
      </div>
      <div class="stat-box">
        <div class="stat-label">Expiry Date</div>
        <div class="stat-value" style="font-size:1rem;">
          <?= $sub_end ? date('M d, Y', strtotime($sub_end)) : '—' ?>
        </div>
        <?php if ($days_left !== null): ?>
        <div class="stat-hint" style="color:<?= $days_left <= 3 ? '#fca5a5' : ($days_left <= 7 ? '#fde68a' : 'rgba(255,255,255,.35)') ?>;">
          <?= $days_left > 0 ? "{$days_left} day(s) remaining" : 'Expired' ?>
        </div>
        <?php if ($days_left > 0 && $days_left <= 30): ?>
        <div class="expiry-bar-wrap" style="margin-top:10px;">
          <div class="expiry-bar" style="width:<?= min(100, round(($days_left/30)*100)) ?>%;background:<?= $days_left <= 3 ? '#dc2626' : ($days_left <= 7 ? '#d97706' : '#2563eb') ?>;"></div>
        </div>
        <?php endif; ?>
        <?php endif; ?>
      </div>
      <div class="stat-box">
        <div class="stat-label">Business</div>
        <div class="stat-value" style="font-size:.95rem;"><?= htmlspecialchars($tenant['business_name']) ?></div>
      </div>
    </div>
  </div>

  <!-- ── Pending Renewal ──────────────────────────────────────── -->
  <?php if ($pending_renewal): ?>
  <div class="card">
    <div class="card-title">Pending Renewal Request</div>
    <div class="pending-box">
      <p style="color:#93c5fd;font-size:.88rem;margin-bottom:10px;">
        <strong>✅ Your renewal request has been submitted.</strong><br>
        Our admin is reviewing your payment. You will receive an email once approved.
      </p>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;font-size:.82rem;color:rgba(255,255,255,.6);">
        <div><strong style="color:rgba(255,255,255,.8);">Plan:</strong> <?= htmlspecialchars($pending_renewal['plan']) ?></div>
        <div><strong style="color:rgba(255,255,255,.8);">Billing:</strong> <?= ucfirst($pending_renewal['billing_cycle']) ?></div>
        <div><strong style="color:rgba(255,255,255,.8);">Payment Method:</strong> <?= htmlspecialchars($pending_renewal['payment_method']) ?></div>
        <div><strong style="color:rgba(255,255,255,.8);">Reference:</strong> <?= htmlspecialchars($pending_renewal['payment_reference'] ?: '—') ?></div>
        <div><strong style="color:rgba(255,255,255,.8);">Submitted:</strong> <?= date('M d, Y h:i A', strtotime($pending_renewal['requested_at'])) ?></div>
        <?php if ($pending_renewal['amount'] > 0): ?>
        <div><strong style="color:rgba(255,255,255,.8);">Amount:</strong> ₱<?= number_format($pending_renewal['amount'], 2) ?></div>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <?php else: ?>

  <!-- ── Renewal Request Form ─────────────────────────────────── -->
  <div class="card">
    <div class="card-title">Request Subscription Renewal</div>

    <?php if ($plan === 'Starter'): ?>
    <div class="alert alert-info" style="margin-bottom:20px;">
      ℹ️ You are on the <strong>Starter (Free)</strong> plan. Submitting a renewal request will extend your access period.
    </div>
    <?php endif; ?>

    <form method="POST" action="">
      <input type="hidden" name="action" value="request_renewal"/>

      <div class="form-grid">
        <div>
          <label class="form-label">Current Plan</label>
          <input type="text" class="form-input" value="<?= htmlspecialchars($plan) ?>" disabled/>
        </div>
        <div>
          <label class="form-label">Billing Cycle</label>
          <select name="billing_cycle" class="form-select" required>
            <option value="monthly">Monthly</option>
            <option value="quarterly">Quarterly (save ~10%)</option>
            <option value="annually">Annually (save ~20%)</option>
          </select>
        </div>
      </div>

      <div class="form-group">
        <label class="form-label">Payment Method</label>
        <select name="payment_method" class="form-select" required>
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
        <label class="form-label">Payment Reference / Transaction No. <span style="color:rgba(255,255,255,.3);font-weight:400;">(optional)</span></label>
        <input type="text" name="payment_reference" class="form-input" placeholder="e.g. GCash ref #1234567890"/>
      </div>

      <div class="form-group">
        <label class="form-label">Additional Notes <span style="color:rgba(255,255,255,.3);font-weight:400;">(optional)</span></label>
        <textarea name="notes" class="form-input" rows="3" placeholder="Any notes for the admin..." style="height:auto;resize:vertical;"></textarea>
      </div>

      <button type="submit" class="btn btn-primary">
        <span class="material-symbols-outlined" style="font-size:18px;">send</span>
        Submit Renewal Request
      </button>
    </form>
  </div>
  <?php endif; ?>

  <!-- ── Renewal History ──────────────────────────────────────── -->
  <?php if (!empty($renewal_history)): ?>
  <div class="card">
    <div class="card-title">Renewal History</div>
    <div style="overflow-x:auto;">
      <table class="history-table">
        <thead>
          <tr>
            <th>Date</th>
            <th>Plan</th>
            <th>Billing</th>
            <th>Method</th>
            <th>Status</th>
            <th>New Expiry</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($renewal_history as $r): ?>
          <?php
            $rstat_color = match($r['status']) {
              'approved' => '#15803d', 'rejected' => '#dc2626', default => '#d97706'
            };
            $rstat_bg = match($r['status']) {
              'approved' => '#f0fdf4', 'rejected' => '#fef2f2', default => '#fffbeb'
            };
          ?>
          <tr>
            <td><?= date('M d, Y', strtotime($r['requested_at'])) ?></td>
            <td><?= htmlspecialchars($r['plan']) ?></td>
            <td><?= ucfirst($r['billing_cycle']) ?></td>
            <td><?= htmlspecialchars($r['payment_method'] ?: '—') ?></td>
            <td>
              <span class="badge" style="color:<?= $rstat_color ?>;background:<?= $rstat_bg ?>;border:1px solid <?= $rstat_bg ?>;">
                <?= ucfirst($r['status']) ?>
              </span>
            </td>
            <td><?= $r['new_subscription_end'] ? date('M d, Y', strtotime($r['new_subscription_end'])) : '—' ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>

</div><!-- /page-wrap -->
</body>
</html>