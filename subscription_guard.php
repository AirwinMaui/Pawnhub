<?php
/**
 * subscription_guard.php — PawnHub Subscription Access Guard
 *
 * I-require sa SIMULA ng tenant.php, manager.php, staff.php, cashier.php
 * PAGKATAPOS ng session check at db.php load.
 *
 * REQUIRES: $pdo, $u (from $_SESSION['user'])
 */

// Super admin — never blocked
if (!empty($u['role']) && $u['role'] === 'super_admin') return;

$tid_guard = (int)($u['tenant_id'] ?? 0);
if (!$tid_guard) return;

// ── Load tenant subscription info ─────────────────────────────
try {
    $guard_stmt = $pdo->prepare("
        SELECT subscription_end, subscription_status, plan, business_name, slug, email, owner_name, status
        FROM tenants WHERE id = ? LIMIT 1
    ");
    $guard_stmt->execute([$tid_guard]);
    $guard_tenant = $guard_stmt->fetch();
} catch (Throwable $e) {
    return; // fail open on DB error — don't lock out if DB has issues
}

if (!$guard_tenant) return;

// No subscription_end set = skip guard (tenant not yet on billing)
if (empty($guard_tenant['subscription_end'])) return;

$sub_end   = strtotime($guard_tenant['subscription_end']);
$now       = time();
$days_left = (int)ceil(($sub_end - $now) / 86400);

define('SUBSCRIPTION_GRACE_DAYS',   3);  // days after expiry before hard lock
define('SUBSCRIPTION_WARNING_DAYS', 14); // days before expiry to show banner

$grace_end = $sub_end + (SUBSCRIPTION_GRACE_DAYS * 86400);

// ── HARD LOCK (expired + grace period over) ───────────────────
if ($now > $grace_end) {
    $slug       = $guard_tenant['slug'] ?? '';
    $plan       = $guard_tenant['plan'] ?? 'Pro';
    $biz_name   = $guard_tenant['business_name'] ?? 'Your Business';
    $expiry_fmt = date('F d, Y', $sub_end);
    $role       = $u['role'] ?? 'admin';

    // Allow these through even when expired
    $script = basename($_SERVER['SCRIPT_FILENAME'] ?? '');
    $page   = $_GET['page'] ?? '';
    if ($script === 'logout.php') return;
    if ($script === 'tenant_subscription.php') return;
    if ($page === 'subscription') return; // tenant.php?page=subscription (fallback)

    http_response_code(402);
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1"/>
<title>Subscription Expired — <?= htmlspecialchars($biz_name) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800&display=swap" rel="stylesheet"/>
<style>
*{box-sizing:border-box;margin:0;padding:0;}
body{background:#0f172a;font-family:'Plus Jakarta Sans',sans-serif;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px;}
.card{background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.10);border-radius:22px;padding:44px 40px;max-width:520px;width:100%;text-align:center;}
h1{font-size:1.6rem;font-weight:800;color:#fff;margin:16px 0 8px;}
.sub{color:rgba(255,255,255,.5);font-size:.88rem;line-height:1.7;margin-bottom:24px;}
.detail-box{background:rgba(220,38,38,.08);border:1px solid rgba(220,38,38,.25);border-radius:12px;padding:16px 20px;margin-bottom:24px;text-align:left;}
.detail-box p{font-size:.82rem;color:rgba(255,255,255,.65);line-height:1.9;margin:0;}
.detail-box strong{color:#fca5a5;}
.steps{background:rgba(255,255,255,.04);border-radius:12px;padding:16px 20px;margin-bottom:24px;text-align:left;}
.steps-title{font-size:.78rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:rgba(255,255,255,.4);margin-bottom:10px;}
.steps ol{color:rgba(255,255,255,.65);font-size:.84rem;line-height:1.9;padding-left:18px;}
.steps ol li strong{color:#93c5fd;}
.btn-row{display:flex;gap:12px;justify-content:center;flex-wrap:wrap;}
.btn{display:inline-flex;align-items:center;gap:7px;padding:12px 26px;border-radius:10px;font-family:inherit;font-size:.88rem;font-weight:700;cursor:pointer;text-decoration:none;border:none;}
.btn-primary{background:linear-gradient(135deg,#2563eb,#1d4ed8);color:#fff;box-shadow:0 4px 14px rgba(37,99,235,.3);}
.btn-ghost{background:rgba(255,255,255,.07);color:rgba(255,255,255,.7);border:1px solid rgba(255,255,255,.12);}
</style>
</head>
<body>
<div class="card">
  <div style="font-size:3.5rem;">🔒</div>
  <h1>Subscription Expired</h1>
  <p class="sub">Your <strong style="color:#fff;"><?= htmlspecialchars($biz_name) ?></strong> subscription has expired. Please renew to restore full access to your dashboard.</p>
  <div class="detail-box">
    <p>
      <strong>Plan:</strong> <?= htmlspecialchars($plan) ?><br>
      <strong>Expired on:</strong> <?= $expiry_fmt ?><br>
      <strong>Account Owner:</strong> <?= htmlspecialchars($guard_tenant['owner_name'] ?? '') ?>
    </p>
  </div>
  <div class="steps">
    <div class="steps-title">How to Renew</div>
    <ol>
      <li>Click <strong>Renew Subscription</strong> below</li>
      <li>Select billing cycle and payment method</li>
      <li>Submit your payment reference</li>
      <li>Wait for admin approval <em>(usually within 24 hours)</em></li>
    </ol>
  </div>
  <div class="btn-row">
    <?php if ($role === 'admin'): ?>
    <a href="/tenant_subscription.php" class="btn btn-primary">🔄 Renew Subscription</a>
    <?php else: ?>
    <div style="background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.10);border-radius:12px;padding:14px 18px;font-size:.82rem;color:rgba(255,255,255,.6);text-align:center;max-width:340px;">
      Please contact your branch administrator to renew your subscription.
    </div>
    <?php endif; ?>
    <a href="logout.php?role=<?= htmlspecialchars($role) ?>" class="btn btn-ghost">Sign Out</a>
  </div>
</div>
</body>
</html>
    <?php
    exit;
}

// ── SOFT WARNING BANNER (expiring soon or in grace period) ────
if ($days_left <= SUBSCRIPTION_WARNING_DAYS || $now > $sub_end) {
    $GLOBALS['subscription_warning'] = [
        'days_left'       => $days_left,
        'expiry_date'     => date('F d, Y', $sub_end),
        'slug'            => $guard_tenant['slug'] ?? '',
        'in_grace'        => ($now > $sub_end && $now <= $grace_end),
        'grace_days_left' => max(0, (int)ceil(($grace_end - $now) / 86400)),
        'role'            => $u['role'] ?? 'admin',
    ];
}

/**
 * Render subscription warning banner.
 *
 * I-call sa layout PAGKATAPOS ng </header> sa tenant.php, manager.php, staff.php, cashier.php:
 *   <?php if (function_exists('renderSubscriptionBanner')) echo renderSubscriptionBanner(); ?>
 */
function renderSubscriptionBanner(): string {
    $w = $GLOBALS['subscription_warning'] ?? null;
    if (!$w) return '';

    $dl   = $w['days_left'];
    $slug = $w['slug'];
    $role = $w['role'] ?? 'admin';
    // Only admin can renew — staff/manager/cashier just see the warning
    $link = '/' . urlencode($slug) . '?page=subscription';

    if ($w['in_grace']) {
        $msg = "⚠️ Your subscription has expired. <strong>{$w['grace_days_left']} day(s)</strong> of grace period left before your account is locked.";
        $bg = 'rgba(127,29,29,0.97)'; $border = '#dc2626'; $btn = '#dc2626';
    } elseif ($dl <= 1) {
        $msg = "🚨 <strong>URGENT:</strong> Your subscription expires <strong>TOMORROW!</strong> Renew now to avoid interruption.";
        $bg = 'rgba(127,29,29,0.97)'; $border = '#dc2626'; $btn = '#dc2626';
    } elseif ($dl <= 3) {
        $msg = "⚠️ Your subscription expires in <strong>{$dl} days</strong> ({$w['expiry_date']}). Please renew soon!";
        $bg = 'rgba(120,53,15,0.97)'; $border = '#d97706'; $btn = '#d97706';
    } elseif ($dl <= 7) {
        $msg = "⏰ Your subscription expires in <strong>{$dl} days</strong> ({$w['expiry_date']}).";
        $bg = 'rgba(120,53,15,0.97)'; $border = '#d97706'; $btn = '#d97706';
    } else {
        $msg = "📅 Your subscription expires on <strong>{$w['expiry_date']}</strong> ({$dl} days remaining).";
        $bg = 'rgba(30,58,95,0.97)'; $border = '#2563eb'; $btn = '#2563eb';
    }

    $renew_btn = $role === 'admin'
        ? "<a href='/tenant_subscription.php' style='display:inline-flex;align-items:center;gap:5px;background:{$btn};color:#fff;text-decoration:none;padding:6px 16px;border-radius:8px;font-size:.78rem;font-weight:700;white-space:nowrap;flex-shrink:0;'>🔄 Renew Now</a>"
        : "<span style='font-size:.76rem;color:rgba(255,255,255,.5);flex-shrink:0;'>Contact your Admin to renew.</span>";

    return "
    <div style='background:{$bg};border-bottom:2px solid {$border};padding:9px 24px;display:flex;align-items:center;justify-content:space-between;gap:14px;flex-wrap:wrap;'>
      <p style='color:#fff;font-size:.81rem;line-height:1.5;margin:0;'>{$msg}</p>
      {$renew_btn}
    </div>";
}