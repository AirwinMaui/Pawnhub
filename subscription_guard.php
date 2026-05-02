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

// ── STARTER PLAN FREE TRIAL ───────────────────────────────────
// Starter tenants get 1 month free trial. After expiry:
//   - No grace period — hard lock immediately
//   - Must upgrade to Pro or Enterprise to continue
if (($guard_tenant['plan'] ?? '') === 'Starter' && $now > $sub_end) {
    $slug     = $guard_tenant['slug'] ?? '';
    $biz_name = $guard_tenant['business_name'] ?? 'Your Business';
    $role     = $u['role'] ?? 'admin';
    $script   = basename($_SERVER['SCRIPT_FILENAME'] ?? '');
    $page     = $_GET['page'] ?? '';
    if ($script === 'logout.php') return;
    if ($script === 'tenant_subscription.php') return;
    if ($page === 'subscription') return;

    http_response_code(402);
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1"/>
<title>Free Trial Ended — <?= htmlspecialchars($biz_name) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800&display=swap" rel="stylesheet"/>
<style>
*{box-sizing:border-box;margin:0;padding:0;}
body{background:#0f172a;font-family:'Plus Jakarta Sans',sans-serif;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px;}
.card{background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.10);border-radius:22px;padding:44px 40px;max-width:540px;width:100%;text-align:center;}
h1{font-size:1.6rem;font-weight:800;color:#fff;margin:16px 0 8px;}
.sub{color:rgba(255,255,255,.5);font-size:.88rem;line-height:1.7;margin-bottom:24px;}
.trial-badge{display:inline-flex;align-items:center;gap:6px;background:rgba(234,179,8,.12);border:1px solid rgba(234,179,8,.3);border-radius:100px;padding:5px 14px;font-size:.75rem;font-weight:700;color:#fbbf24;margin-bottom:16px;}
.plans{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:24px;}
.plan-card{background:rgba(255,255,255,.05);border:1.5px solid rgba(255,255,255,.10);border-radius:16px;padding:20px 16px;text-align:left;position:relative;transition:border-color .2s;}
.plan-card.popular{border-color:#2563eb;background:rgba(37,99,235,.08);}
.popular-tag{position:absolute;top:-11px;left:50%;transform:translateX(-50%);background:#2563eb;color:#fff;font-size:.62rem;font-weight:800;text-transform:uppercase;letter-spacing:.07em;padding:3px 12px;border-radius:100px;white-space:nowrap;}
.plan-name{font-size:.88rem;font-weight:800;color:#fff;margin-bottom:4px;}
.plan-price{font-size:1.5rem;font-weight:800;color:#fff;line-height:1.1;}
.plan-price span{font-size:.75rem;font-weight:500;color:rgba(255,255,255,.45);}
.plan-desc{font-size:.73rem;color:rgba(255,255,255,.45);margin-top:6px;line-height:1.5;}
.btn-row{display:flex;gap:12px;justify-content:center;flex-wrap:wrap;margin-bottom:16px;}
.btn{display:inline-flex;align-items:center;gap:7px;padding:12px 28px;border-radius:10px;font-family:inherit;font-size:.88rem;font-weight:700;cursor:pointer;text-decoration:none;border:none;transition:all .18s;}
.btn-primary{background:linear-gradient(135deg,#2563eb,#1d4ed8);color:#fff;box-shadow:0 4px 14px rgba(37,99,235,.35);}
.btn-primary:hover{box-shadow:0 6px 20px rgba(37,99,235,.5);transform:translateY(-1px);}
.btn-ghost{background:rgba(255,255,255,.07);color:rgba(255,255,255,.7);border:1px solid rgba(255,255,255,.12);}
.note{font-size:.74rem;color:rgba(255,255,255,.3);line-height:1.6;}
@media(max-width:480px){.plans{grid-template-columns:1fr;}.card{padding:32px 22px;}}
</style>
</head>
<body>
<div class="card">
  <div style="font-size:3rem;">⏳</div>
  <div class="trial-badge">🎁 Free Trial Ended</div>
  <h1>Your Free Trial Has Expired</h1>
  <p class="sub">
    Thank you for trying <strong style="color:#fff;"><?= htmlspecialchars($biz_name) ?></strong> on PawnHub!<br>
    Your <strong style="color:#fbbf24;">1-month free trial</strong> has ended. Upgrade to a paid plan to continue using your dashboard.
  </p>

  <div class="plans">
    <div class="plan-card popular">
      <div class="popular-tag">⭐ Most Popular</div>
      <div class="plan-name">Pro</div>
      <div class="plan-price">₱999 <span>/ mo</span></div>
      <div class="plan-desc">Full features for growing pawnshops. Unlimited staff, reports & more.</div>
    </div>
    <div class="plan-card">
      <div class="plan-name">Enterprise</div>
      <div class="plan-price">₱2,499 <span>/ mo</span></div>
      <div class="plan-desc">Multi-branch management, priority support & advanced analytics.</div>
    </div>
  </div>

  <div class="btn-row">
    <?php if ($role === 'admin'): ?>
    <a href="/tenant_subscription.php" class="btn btn-primary">🚀 Upgrade Now</a>
    <?php else: ?>
    <div style="background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.10);border-radius:12px;padding:14px 18px;font-size:.82rem;color:rgba(255,255,255,.6);max-width:340px;">
      Please contact your branch administrator to upgrade the subscription.
    </div>
    <?php endif; ?>
    <a href="logout.php?role=<?= htmlspecialchars($role) ?>" class="btn btn-ghost">Sign Out</a>
  </div>

  <p class="note">Questions? Email us at <a href="mailto:mendozakiaro@gmail.com" style="color:#93c5fd;">mendozakiaro@gmail.com</a></p>
</div>
</body>
</html>
    <?php
    exit;
}

// ── HARD LOCK (expired + grace period over) — non-Starter plans ──
if ($now > $grace_end && ($guard_tenant['plan'] ?? '') !== 'Starter') {
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
$is_starter = (($guard_tenant['plan'] ?? '') === 'Starter');
// For Starter: show banner for last 7 days of trial (no grace period)
$warning_days = $is_starter ? 7 : SUBSCRIPTION_WARNING_DAYS;
if ($days_left <= $warning_days || (!$is_starter && $now > $sub_end)) {
    $GLOBALS['subscription_warning'] = [
        'days_left'       => $days_left,
        'expiry_date'     => date('F d, Y', $sub_end),
        'slug'            => $guard_tenant['slug'] ?? '',
        'in_grace'        => (!$is_starter && $now > $sub_end && $now <= $grace_end),
        'grace_days_left' => max(0, (int)ceil(($grace_end - $now) / 86400)),
        'role'            => $u['role'] ?? 'admin',
        'is_starter'      => $is_starter,
        'plan'            => $guard_tenant['plan'] ?? '',
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

    $dl         = $w['days_left'];
    $role       = $w['role'] ?? 'admin';
    $is_starter = $w['is_starter'] ?? false;

    if ($is_starter) {
        // Starter free trial expiry banners
        if ($dl <= 1) {
            $msg = "🚨 <strong>FREE TRIAL ENDS TODAY!</strong> Upgrade to Pro or Enterprise now to avoid losing access.";
            $bg = 'rgba(127,29,29,0.97)'; $border = '#dc2626'; $btn = '#dc2626';
        } elseif ($dl <= 3) {
            $msg = "⚠️ Your <strong>free trial</strong> expires in <strong>{$dl} days</strong> ({$w['expiry_date']}). Upgrade to keep full access!";
            $bg = 'rgba(120,53,15,0.97)'; $border = '#d97706'; $btn = '#d97706';
        } else {
            $msg = "🎁 Your <strong>1-month free trial</strong> expires on <strong>{$w['expiry_date']}</strong> ({$dl} days left). Upgrade to Pro or Enterprise to continue!";
            $bg = 'rgba(30,58,95,0.97)'; $border = '#2563eb'; $btn = '#2563eb';
        }
        $renew_btn = $role === 'admin'
            ? "<a href='/tenant_subscription.php' style='display:inline-flex;align-items:center;gap:5px;background:{$btn};color:#fff;text-decoration:none;padding:6px 16px;border-radius:8px;font-size:.78rem;font-weight:700;white-space:nowrap;flex-shrink:0;'>🚀 Upgrade Now</a>"
            : "<span style='font-size:.76rem;color:rgba(255,255,255,.5);flex-shrink:0;'>Contact your Admin to upgrade.</span>";
    } else {
        // Paid plan expiry banners
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
    }

    return "
    <div style='background:{$bg};border-bottom:2px solid {$border};padding:9px 24px;display:flex;align-items:center;justify-content:space-between;gap:14px;flex-wrap:wrap;'>
      <p style='color:#fff;font-size:.81rem;line-height:1.5;margin:0;'>{$msg}</p>
      {$renew_btn}
    </div>";
}