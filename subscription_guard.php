<?php
/**
 * subscription_guard.php — PawnHub Subscription Access Guard
 *
 * HOW TO USE:
 *   I-require ito sa SIMULA ng tenant.php, manager.php, staff.php, cashier.php
 *   PAGKATAPOS ng session check at db.php load.
 *
 *   Example (sa tenant.php, pagkatapos ng session check):
 *     require_once __DIR__ . '/subscription_guard.php';
 *
 *   The guard will:
 *     1. Check if the tenant's subscription is expired
 *     2. If expired → show a locked page with renewal instructions
 *     3. If expiring soon → show a warning banner (page still loads)
 *     4. If active → do nothing (page loads normally)
 *
 *   REQUIRES: $pdo, $_SESSION['user'] with tenant_id, $u['role']
 *
 *   GRACE PERIOD: Super admin can set SUBSCRIPTION_GRACE_DAYS below.
 *   During grace period, page still loads but with a big warning.
 */

// ── CONFIG ─────────────────────────────────────────────────────────────────
define('SUBSCRIPTION_GRACE_DAYS', 3); // Days after expiry before hard lock
define('SUBSCRIPTION_WARNING_DAYS', 14); // Days before expiry to show banner

// Super admin is never affected by subscription guard
if (!empty($u['role']) && $u['role'] === 'super_admin') return;

// ── Load tenant subscription info ─────────────────────────────────────────
$tid_guard = (int)($u['tenant_id'] ?? 0);
if (!$tid_guard) return; // no tenant = skip

try {
    $guard_stmt = $pdo->prepare("SELECT subscription_end, subscription_status, plan, business_name, slug, email, owner_name FROM tenants WHERE id = ? LIMIT 1");
    $guard_stmt->execute([$tid_guard]);
    $guard_tenant = $guard_stmt->fetch();
} catch (Throwable $e) {
    return; // DB error — fail open (don't lock out tenant on DB issues)
}

if (!$guard_tenant || empty($guard_tenant['subscription_end'])) return;

$sub_end   = strtotime($guard_tenant['subscription_end']);
$now       = time();
$days_left = (int)ceil(($sub_end - $now) / 86400);
$grace_end = $sub_end + (SUBSCRIPTION_GRACE_DAYS * 86400);

// ── HARD LOCK (expired + grace period over) ───────────────────────────────
if ($now > $grace_end) {
    $slug      = $guard_tenant['slug'] ?? '';
    $plan      = $guard_tenant['plan'] ?? 'Pro';
    $biz_name  = $guard_tenant['business_name'] ?? 'Your Business';
    $owner     = $guard_tenant['owner_name'] ?? '';
    $expiry_fmt= date('F d, Y', $sub_end);

    // Allow logout so they're not completely stuck
    $req_uri = $_SERVER['REQUEST_URI'] ?? '';
    if (str_contains($req_uri, 'logout.php')) return;

    // Also allow the subscription page itself
    $script = basename($_SERVER['SCRIPT_FILENAME'] ?? '');
    if ($script === 'tenant_subscription.php') return;
    $page = $_GET['page'] ?? '';
    if ($page === 'subscription') return;

    http_response_code(402); // Payment Required
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
.icon{font-size:3.5rem;margin-bottom:16px;}
h1{font-size:1.6rem;font-weight:800;color:#fff;margin-bottom:8px;}
.sub{color:rgba(255,255,255,.5);font-size:.88rem;line-height:1.7;margin-bottom:24px;}
.detail-box{background:rgba(220,38,38,.08);border:1px solid rgba(220,38,38,.25);border-radius:12px;padding:16px 20px;margin-bottom:24px;text-align:left;}
.detail-box p{font-size:.82rem;color:rgba(255,255,255,.65);line-height:1.8;margin:0;}
.detail-box strong{color:#fca5a5;}
.steps{background:rgba(255,255,255,.04);border-radius:12px;padding:16px 20px;margin-bottom:24px;text-align:left;}
.steps p{font-size:.78rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:rgba(255,255,255,.4);margin-bottom:10px;}
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
  <div class="icon">🔒</div>
  <h1>Subscription Expired</h1>
  <p class="sub">
    Your <strong style="color:#fff;"><?= htmlspecialchars($biz_name) ?></strong> subscription has expired.
    Please renew to restore full access to your PawnHub dashboard.
  </p>
  <div class="detail-box">
    <p>
      <strong>Plan:</strong> <?= htmlspecialchars($plan) ?><br>
      <strong>Expired on:</strong> <?= $expiry_fmt ?><br>
      <strong>Account:</strong> <?= htmlspecialchars($owner) ?>
    </p>
  </div>
  <div class="steps">
    <p>How to Renew</p>
    <ol>
      <li>Go to <strong>Subscription Page</strong> below</li>
      <li>Click <strong>Request Renewal</strong></li>
      <li>Fill in your payment details</li>
      <li>Wait for admin approval <em>(usually within 24 hours)</em></li>
    </ol>
  </div>
  <div class="btn-row">
    <a href="/<?= urlencode($slug) ?>?page=subscription" class="btn btn-primary">🔄 Renew Subscription</a>
    <a href="logout.php?role=admin" class="btn btn-ghost">Sign Out</a>
  </div>
</div>
</body>
</html>
    <?php
    exit;
}

// ── SOFT WARNING BANNER (expiring soon or in grace period) ────────────────
// This sets a global variable that your layout can render as a banner
if ($days_left <= SUBSCRIPTION_WARNING_DAYS) {
    $GLOBALS['subscription_warning'] = [
        'days_left'  => $days_left,
        'expiry_date'=> date('F d, Y', $sub_end),
        'slug'       => $guard_tenant['slug'] ?? '',
        'in_grace'   => ($now > $sub_end && $now <= $grace_end),
        'grace_days_left' => (int)ceil(($grace_end - $now) / 86400),
    ];
}

/**
 * Call this function in your layout header (tenant.php, manager.php, etc.)
 * to render the subscription warning banner.
 *
 * Usage: echo renderSubscriptionBanner();
 */
function renderSubscriptionBanner(): string {
    $w = $GLOBALS['subscription_warning'] ?? null;
    if (!$w) return '';

    $dl    = $w['days_left'];
    $slug  = $w['slug'];
    $link  = '/' . urlencode($slug) . '?page=subscription';

    if ($w['in_grace']) {
        $msg   = "⚠️ Your subscription expired. You have <strong>{$w['grace_days_left']} day(s)</strong> of grace period remaining before your account is locked.";
        $bg    = '#7f1d1d'; $border = '#dc2626'; $btn_bg = '#dc2626';
    } elseif ($dl <= 1) {
        $msg   = "🚨 <strong>URGENT:</strong> Your subscription expires <strong>tomorrow!</strong> Renew now to avoid interruption.";
        $bg    = '#7f1d1d'; $border = '#dc2626'; $btn_bg = '#dc2626';
    } elseif ($dl <= 3) {
        $msg   = "⚠️ Your subscription expires in <strong>{$dl} days</strong> ({$w['expiry_date']}). Renew soon!";
        $bg    = '#78350f'; $border = '#d97706'; $btn_bg = '#d97706';
    } elseif ($dl <= 7) {
        $msg   = "⏰ Your subscription expires in <strong>{$dl} days</strong> ({$w['expiry_date']}).";
        $bg    = '#78350f'; $border = '#d97706'; $btn_bg = '#d97706';
    } else {
        $msg   = "📅 Your subscription expires on <strong>{$w['expiry_date']}</strong> ({$dl} days remaining).";
        $bg    = '#1e3a5f'; $border = '#2563eb'; $btn_bg = '#2563eb';
    }

    return "
    <div style='background:{$bg};border-bottom:2px solid {$border};padding:10px 20px;display:flex;align-items:center;justify-content:space-between;gap:14px;flex-wrap:wrap;'>
      <p style='color:#fff;font-size:.82rem;line-height:1.5;margin:0;'>{$msg}</p>
      <a href='{$link}' style='display:inline-flex;align-items:center;gap:5px;background:{$btn_bg};color:#fff;text-decoration:none;padding:7px 16px;border-radius:8px;font-size:.78rem;font-weight:700;white-space:nowrap;flex-shrink:0;'>
        🔄 Renew Now
      </a>
    </div>";
}