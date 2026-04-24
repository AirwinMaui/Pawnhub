<?php
// ── Session config — only if not already set by router.php ────
if (session_status() === PHP_SESSION_NONE) {
    require_once __DIR__ . '/session_helper.php';
    pawnhub_session_start(''); // generic tenant session for login page
}
require 'db.php';

$slug  = trim($_GET['slug'] ?? '');
$token = trim($_GET['token'] ?? '');
$error = '';
$tenant = null;
$inv = null;
$mode = 'login'; // 'login' or 'register'

// ── Load tenant ───────────────────────────────────────────────
if ($slug) {
    $stmt = $pdo->prepare("SELECT * FROM tenants WHERE slug = ? LIMIT 1");
    $stmt->execute([$slug]);
    $tenant = $stmt->fetch();
}

if (!$tenant) {
    header('Location: login.php');
    exit;
}

// Block login if tenant is deactivated OR subscription expired 7+ days ago
// (covers cases where cron hasn't run yet but tenant should be locked out)
$is_deactivated = false;
$sub_expired_days = 0;

if (isset($tenant['status']) && $tenant['status'] === 'inactive') {
    $is_deactivated = true;
} elseif (
    !empty($tenant['subscription_end']) &&
    $tenant['plan'] !== 'Starter' &&
    ($tenant['subscription_status'] === 'expired' ||
     strtotime($tenant['subscription_end']) < time())
) {
    $days_overdue = (int)floor((time() - strtotime($tenant['subscription_end'])) / 86400);
    if ($days_overdue >= 7) {
        $is_deactivated   = true;
        $sub_expired_days = $days_overdue;

        // Also auto-deactivate now in case cron missed it
        try {
            $pdo->prepare("UPDATE tenants SET status='inactive' WHERE id=?")
                ->execute([$tenant['id']]);
            $pdo->prepare("
                UPDATE users SET is_suspended=1, suspended_at=NOW(),
                suspension_reason='Subscription expired and auto-deactivated after 7 days.'
                WHERE tenant_id=? AND role != 'admin'
            ")->execute([$tenant['id']]);
        } catch (Throwable $e) {}
    }
}

$sa_contact_email = '';
$sa_contact_name  = '';
if ($is_deactivated) {
    try {
        $sa_row = $pdo->query("SELECT fullname, email FROM users WHERE role='super_admin' AND status='approved' ORDER BY id ASC LIMIT 1")->fetch();
        $sa_contact_email = $sa_row['email']    ?? '';
        $sa_contact_name  = $sa_row['fullname'] ?? 'PawnHub Support';
    } catch (Throwable $e) {}
}

// ── Check if token is present → registration mode ─────────────
if ($token) {
    // Step 1: Find the invitation by token alone (no tenant_id restriction)
    // This prevents false "invalid" errors from slug/tenant_id mismatches
    $inv_stmt = $pdo->prepare("
        SELECT i.*, t.business_name, t.plan, t.id AS tenant_id, t.slug AS tenant_slug
        FROM tenant_invitations i
        JOIN tenants t ON i.tenant_id = t.id
        WHERE i.token = ?
        LIMIT 1
    ");
    $inv_stmt->execute([$token]);
    $inv = $inv_stmt->fetch();

    if (!$inv) {
        // Token doesn't exist at all
        $error = 'Invalid invitation link. Please ask your administrator to resend the invitation.';
    } elseif ($inv['status'] === 'used') {
        // Token was already used — check if the user just needs to log in
        $error = 'This invitation link has already been used. If you already set up your account, please log in below.';
    } elseif ($inv['status'] === 'expired') {
        $error = 'This invitation link has expired. Please ask your administrator to resend the invitation.';
    } elseif ($inv['status'] !== 'pending') {
        $error = 'This invitation link is no longer valid. Please contact your administrator.';
    } elseif (strtotime($inv['expires_at']) < time()) {
        // Pending but expired by time — auto-mark as expired
        $pdo->prepare("UPDATE tenant_invitations SET status='expired' WHERE token=?")->execute([$token]);
        $error = 'This invitation link has expired. Please ask your administrator to resend the invitation.';
    } else {
        // Valid pending token — allow registration regardless of which slug URL they used
        // If token belongs to a different tenant than the current slug, redirect to correct branch URL
        if ($inv['tenant_id'] !== $tenant['id'] && !empty($inv['tenant_slug'])) {
            header('Location: /' . urlencode($inv['tenant_slug']) . '?login=1&token=' . urlencode($token));
            exit;
        }
        $mode = 'register';
    }
}

// ── Handle REGISTRATION POST ──────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['form_type']) && $_POST['form_type'] === 'register' && $inv) {
    $fullname = trim($_POST['fullname'] ?? $inv['owner_name']);
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');
    $confirm  = trim($_POST['confirm']  ?? '');

    if (!$fullname || !$username || !$password) {
        $error = 'Please fill in all required fields.';
        $mode  = 'register';
    } elseif ($password !== $confirm) {
        $error = 'Passwords do not match.';
        $mode  = 'register';
    } elseif (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters.';
        $mode  = 'register';
    } else {
        $chk = $pdo->prepare("SELECT id FROM users WHERE username = ?");
        $chk->execute([$username]);
        if ($chk->fetch()) {
            $error = 'Username already taken. Please choose another.';
            $mode  = 'register';
        } else {
            $sa_stmt = $pdo->query("SELECT id FROM users WHERE role='super_admin' LIMIT 1");
            $sa_row  = $sa_stmt->fetch();
            $sa_id   = $sa_row ? $sa_row['id'] : null;

            $pdo->beginTransaction();
            $pdo->prepare("INSERT INTO users (tenant_id,fullname,email,username,password,role,status,approved_by,approved_at) VALUES (?,?,?,?,?,'admin','approved',?,NOW())")
                ->execute([$tenant['id'], $fullname, $inv['email'], $username, password_hash($password, PASSWORD_BCRYPT), $sa_id]);
            $new_uid = $pdo->lastInsertId();
            $pdo->prepare("UPDATE tenants SET status='active', owner_name=? WHERE id=?")->execute([$fullname, $tenant['id']]);
            $pdo->prepare("UPDATE tenant_invitations SET status='used', used_at=NOW() WHERE token=?")->execute([$token]);
            $pdo->commit();

            // Send welcome email with their dedicated login page link
            try {
                require_once __DIR__ . '/mailer.php';
                sendTenantWelcome($inv['email'], $fullname, $tenant['business_name'], $slug);
            } catch (Throwable $e) {
                error_log('Welcome email failed: ' . $e->getMessage());
            }

            // ── Audit log: new tenant admin registered ───────
            try {
                $reg_msg = 'Tenant admin "' . $username . '" (' . $fullname . ') registered account for ' . $tenant['business_name'] . '.';
                $pdo->prepare("INSERT INTO audit_logs (tenant_id,actor_user_id,actor_username,actor_role,action,entity_type,entity_id,message,ip_address,created_at) VALUES (?,?,?,'admin','TENANT_ADMIN_REGISTER','user',?,?,?,NOW())")
                    ->execute([$tenant['id'], $new_uid, $username, (string)$new_uid, $reg_msg, $_SERVER['REMOTE_ADDR'] ?? '::1']);
            } catch (Throwable $e) {}
            // Redirect to tenant LOGIN page after registration — user still needs to sign in
            // ?registered=1 shows the "Account created successfully!" green banner
            $login_url = '/' . urlencode($slug) . '?login=1&registered=1';
            header('Location: ' . $login_url);
            exit;
        }
    }
}

// ── Handle LOGIN POST ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['form_type']) && $_POST['form_type'] === 'login'
    && !$is_deactivated) {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if ($username === '' || $password === '') {
        $error = 'Please fill in all fields.';
    } else {
        $stmt = $pdo->prepare("
            SELECT u.*, t.business_name AS tenant_name
            FROM users u
            LEFT JOIN tenants t ON u.tenant_id = t.id
            WHERE u.username = ?
              AND u.tenant_id = ?
              AND u.status = 'approved'
              AND u.is_suspended = 0
            LIMIT 1
        ");
        $stmt->execute([$username, $tenant['id']]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {
            // Switch to role-specific session so multiple roles can coexist
            session_write_close();
            pawnhub_session_start($user['role']);
            session_regenerate_id(true);
            $_SESSION['user'] = [
                'id'          => $user['id'],
                'name'        => $user['fullname'],
                'username'    => $user['username'],
                'role'        => $user['role'],
                'tenant_id'   => $user['tenant_id'],
                'tenant_name' => $user['tenant_name'],
                'tenant_slug' => $tenant['slug'] ?? $slug,
            ];
            // ── Audit log: user login ─────────────────────────
            try {
                $role_label = ucfirst($user['role']);
                $login_msg = $role_label . ' "' . $user['username'] . '" (' . $user['fullname'] . ') logged in to ' . $tenant['business_name'] . '.';
                $pdo->prepare("INSERT INTO audit_logs (tenant_id,actor_user_id,actor_username,actor_role,action,entity_type,entity_id,message,ip_address,created_at) VALUES (?,?,?,?,'USER_LOGIN','user',?,?,?,NOW())")
                    ->execute([$user['tenant_id'], $user['id'], $user['username'], $user['role'], (string)$user['id'], $login_msg, $_SERVER['REMOTE_ADDR'] ?? '::1']);
            } catch (Throwable $e) {}
            if ($user['role'] === 'admin')   { header('Location: tenant.php');  exit; }
            if ($user['role'] === 'manager') { header('Location: manager.php'); exit; }
            if ($user['role'] === 'staff')   { header('Location: staff.php');   exit; }
            if ($user['role'] === 'cashier') { header('Location: cashier.php'); exit; }
            session_unset(); session_destroy();
            $error = 'Unknown user role.';
        } else {
            $chk = $pdo->prepare("SELECT status, is_suspended FROM users WHERE username = ? AND tenant_id = ? LIMIT 1");
            $chk->execute([$username, $tenant['id']]);
            $row = $chk->fetch();
            if (!$row)                               $error = 'Username not found.';
            elseif ((int)$row['is_suspended'] === 1) $error = 'Your account has been suspended.';
            elseif ($row['status'] === 'pending')    $error = 'Your account is pending approval.';
            elseif ($row['status'] === 'rejected')   $error = 'Your account was rejected.';
            else                                     $error = 'Incorrect password.';
        }
    }
}

// ── Load tenant theme via theme_helper ────────────────────────
require_once __DIR__ . '/theme_helper.php';
$theme   = getTenantTheme($pdo, $tenant['id']);
$primary = htmlspecialchars($theme['primary_color']   ?? '#1e3a8a');
$accent  = htmlspecialchars($theme['accent_color']    ?? '#2563eb');
$sidebar = htmlspecialchars($theme['secondary_color'] ?? '#1e3a8a');

// Use system_name only if tenant explicitly set a custom one (not the default 'PawnHub')
// Always fall back to the actual business_name from tenants table
$customSysName = $theme['system_name'] ?? '';
$bizName = htmlspecialchars(
    ($customSysName && $customSysName !== 'PawnHub')
        ? $customSysName
        : ($tenant['business_name'] ?? 'PawnHub')
);
$rawBg   = $tenant['bg_image_url'] ?? '';
// Normalize: ensure leading slash for local upload paths
if ($rawBg && strpos($rawBg, 'http') !== 0 && $rawBg[0] !== '/') {
    $rawBg = '/' . $rawBg;
}
$bgImg   = !empty($rawBg)
    ? htmlspecialchars($rawBg)
    : 'https://lh3.googleusercontent.com/aida-public/AB6AXuA5_TIJZ7gPS7TJbOhT3mlXkiGTUvK43P5Q8JmtLOQPLEnW8MKgHVTqL5442kQYiDWY2QRo_pnnF1X6G1YizmlZKqXAbLflQBQVaeL_HbIOwxlElZ3gGQ_OPy-TLgjSmD_GDGGtrS4x6rwlP9ctf92uKuFXsjFkkcdS5LHGxcoOTSJskN5b3c9_KXjKPDKJjJgRT9FPsydoU9KGPFwWC1sGixVh4AqRUtT9Yfj6XN0cZG7WRmxqeAScFuFEr6EXTcva1GIdW5wthlI';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover, maximum-scale=1.0"/>
<title><?= $bizName ?> — <?= $mode === 'register' ? 'Set Up Account' : 'Sign In' ?></title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet"/>
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet"/>
<style>
:root { --primary: <?= $primary ?>; --accent: <?= $accent ?>; --secondary: <?= $sidebar ?>; }
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
html { width: 100%; height: 100%; font-family: 'Inter', sans-serif; }
body { width: 100%; min-height: 100%; font-family: 'Inter', sans-serif; overflow-x: hidden; overflow-y: auto; }
.bg { position: fixed; inset: 0; z-index: 0; }
.bg img { width: 100%; height: 100%; object-fit: cover; display: block; }
.bg-ov { position: absolute; inset: 0; background: rgba(10,20,60,0.52); }
.nav { position: fixed; top: 0; left: 0; right: 0; z-index: 50; height: 64px; display: flex; align-items: center; justify-content: space-between; padding: 0 36px; background: rgba(255,255,255,0.07); backdrop-filter: blur(14px); -webkit-backdrop-filter: blur(14px); border-bottom: 1px solid rgba(255,255,255,0.08); }
.nav-logo { display: flex; align-items: center; gap: 9px; text-decoration: none; }
.nav-logo-icon { width: 32px; height: 32px; background: linear-gradient(135deg, var(--primary), var(--accent)); border-radius: 9px; display: flex; align-items: center; justify-content: center; }
.nav-logo-text { font-size: 1.15rem; font-weight: 800; color: #fff; letter-spacing: -0.02em; }
.nav-back { display: flex; align-items: center; gap: 6px; text-decoration: none; font-size: 0.8rem; font-weight: 600; color: rgba(255,255,255,0.65); background: rgba(255,255,255,0.1); border: 1px solid rgba(255,255,255,0.15); padding: 6px 12px; border-radius: 10px; transition: all .18s; line-height: 1; }
.nav-back:hover { color: #fff; background: rgba(255,255,255,0.18); }
.nav-back .material-symbols-outlined { font-size: 15px !important; font-variation-settings: 'FILL' 0,'wght' 400,'GRAD' 0,'opsz' 20; line-height: 1; width: 15px; height: 15px; display: flex; align-items: center; }
.page { position: relative; z-index: 10; width: 100%; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding-top: 84px; padding-bottom: 24px; padding-left: 16px; padding-right: 16px; }
.panel { width: 100%; max-width: 460px; display: flex; flex-direction: column; align-items: center; padding: 0; }
.card { width: 100%; background: rgba(255,255,255,0.91); backdrop-filter: blur(28px); -webkit-backdrop-filter: blur(28px); border-radius: 22px; padding: 34px 30px 26px; box-shadow: 0 18px 48px rgba(10,20,60,0.20); border: 1px solid rgba(255,255,255,0.26); }
.card-icon { margin-bottom: 12px; }
.material-symbols-outlined { font-variation-settings: 'FILL' 1,'wght' 400,'GRAD' 0,'opsz' 24; line-height: 1; }
.card-title { font-size: 1.7rem; font-weight: 800; color: #111827; letter-spacing: -0.03em; line-height: 1.1; margin-bottom: 5px; }
.card-sub { font-size: 0.81rem; color: #64748b; line-height: 1.5; margin-bottom: 20px; }
.tenant-badge { display: inline-flex; align-items: center; gap: 6px; background: color-mix(in srgb, var(--primary) 10%, transparent); border: 1px solid color-mix(in srgb, var(--primary) 30%, transparent); border-radius: 8px; padding: 5px 10px; font-size: 0.72rem; font-weight: 700; color: var(--primary); margin-bottom: 14px; }
.reg-badge { display: inline-flex; align-items: center; gap: 5px; background: linear-gradient(135deg,#16a34a,#15803d); color: #fff; font-size: 0.65rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.08em; padding: 3px 10px; border-radius: 100px; margin-bottom: 14px; }
.err { display: flex; align-items: center; gap: 8px; background: #fef2f2; border: 1px solid #fecaca; border-radius: 9px; padding: 9px 12px; font-size: 0.79rem; color: #dc2626; margin-bottom: 16px; }
.err .material-symbols-outlined { font-size: 15px; flex-shrink: 0; }
.form { display: flex; flex-direction: column; gap: 14px; }
.lbl { display: block; font-size: 0.67rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.09em; color: #64748b; margin-bottom: 5px; }
.inp { width: 100%; height: 46px; padding: 0 15px; background: rgba(218,218,224,0.38); border: 1.5px solid transparent; border-radius: 10px; font-family: 'Inter', sans-serif; font-size: 0.87rem; color: #111827; outline: none; transition: background .2s, border-color .2s, box-shadow .2s; }
.inp:focus { background: #fff; border-color: var(--primary); box-shadow: 0 0 0 3px color-mix(in srgb, var(--primary) 15%, transparent); }
.inp::placeholder { color: #b0b8c5; }
.inp[readonly] { background: rgba(218,218,224,0.2); color: #94a3b8; cursor: not-allowed; }
.pw-wrap { position: relative; }
.pw-wrap .inp { padding-right: 42px; }
.pw-btn { position: absolute; right: 11px; top: 50%; transform: translateY(-50%); background: none; border: none; cursor: pointer; color: #94a3b8; display: flex; align-items: center; padding: 0; transition: color .2s; }
.pw-btn:hover { color: #475569; }
.pw-btn .material-symbols-outlined { font-size: 18px; font-variation-settings: 'FILL' 0,'wght' 400,'GRAD' 0,'opsz' 24; }
.rem { display: flex; align-items: center; gap: 8px; }
.rem input[type="checkbox"] { width: 16px; height: 16px; border-radius: 4px; accent-color: var(--primary); cursor: pointer; }
.rem label { font-size: 0.81rem; color: #64748b; cursor: pointer; user-select: none; }
.btn { width: 100%; height: 46px; background: linear-gradient(135deg, var(--primary), var(--accent)); color: #fff; border: none; border-radius: 10px; font-family: 'Inter', sans-serif; font-size: 0.91rem; font-weight: 700; cursor: pointer; box-shadow: 0 5px 16px rgba(0,0,0,0.2); transition: transform .15s, box-shadow .15s; }
.btn:hover { transform: translateY(-1px); box-shadow: 0 7px 22px rgba(0,0,0,0.28); }
.btn:active { transform: translateY(0); }
.card-foot { margin-top: 18px; padding-top: 14px; border-top: 1px solid rgba(0,0,0,0.07); }
.card-foot-row { display: flex; align-items: flex-start; gap: 8px; }
.card-foot-row .material-symbols-outlined { font-size: 16px; color: #94a3b8; margin-top: 1px; font-variation-settings: 'FILL' 0,'wght' 400,'GRAD' 0,'opsz' 24; }
.card-foot p { font-size: 0.75rem; line-height: 1.55; }
.card-foot strong { color: #475569; font-weight: 600; display: block; }
.card-foot span { color: #94a3b8; }
.legal { margin-top: 12px; display: flex; gap: 16px; padding-left: 2px; }
.legal a { font-size: 0.68rem; color: rgba(255,255,255,0.44); text-decoration: none; transition: color .2s; }
.legal a:hover { color: #fff; }
.badge { position: fixed; bottom: 22px; right: 22px; z-index: 20; display: flex; align-items: center; gap: 8px; background: rgba(255,255,255,0.08); backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px); padding: 7px 15px; border-radius: 100px; border: 1px solid rgba(255,255,255,0.10); }
.bdot { width: 7px; height: 7px; border-radius: 50%; background: #34d399; animation: pulse 2s infinite; }
.btxt { font-size: 0.63rem; font-weight: 600; color: rgba(255,255,255,0.82); text-transform: uppercase; letter-spacing: 0.1em; }
@keyframes pulse { 0%,100%{opacity:1}50%{opacity:.35} }
@media (max-width: 560px) {
  .nav { padding: 0 16px; height: 56px; }
  .card { padding: 22px 18px 20px; }
  .card-title { font-size: 1.5rem; }
  .badge { display: none; }
  .nav-logo-text { font-size: 1rem; }
  .nav-logo-icon { width: 28px; height: 28px; }
}

/* ===== MOBILE / iOS COMPATIBILITY FIXES ===== */
* { -webkit-tap-highlight-color: transparent; }
html { -webkit-text-size-adjust: 100%; }
/* iOS safe area support */
.safe-top    { padding-top:    env(safe-area-inset-top,    0px); }
.safe-bottom { padding-bottom: env(safe-area-inset-bottom, 0px); }
/* iOS overflow scroll */
.overflow-y-auto, .overflow-auto { -webkit-overflow-scrolling: touch; }
/* Prevent iOS zoom on input focus — only for actual inputs, not buttons */
input:not([type="submit"]):not([type="button"]):not([type="checkbox"]):not([type="radio"]),
select, textarea { font-size: max(16px, 1em) !important; }
/* Smooth scrolling on mobile */
html { scroll-behavior: smooth; }
/* Android Material Symbols fix */
.material-symbols-outlined { line-height: 1 !important; }
@media (max-width: 560px) {
  .nav-back { padding: 5px 10px; font-size: 0.75rem; }
  .nav-back .material-symbols-outlined { font-size: 14px !important; }
}
</style>
</head>
<body>

<div class="bg">
  <img src="<?= $bgImg ?>" alt="<?= $bizName ?>"/>
  <div class="bg-ov"></div>
</div>

<header class="nav">
  <?php
    $login_logo_url = $theme['logo_url'] ?? '';
    if ($login_logo_url && strpos($login_logo_url,'http') !== 0 && $login_logo_url[0] !== '/') {
        $login_logo_url = '/' . $login_logo_url;
    }
  ?>
  <a href="/<?= htmlspecialchars(rawurlencode($slug)) ?>" class="nav-logo">
    <div class="nav-logo-icon">
      <?php if($login_logo_url): ?>
        <img src="<?= htmlspecialchars($login_logo_url) ?>" alt="logo" style="width:100%;height:100%;object-fit:cover;border-radius:9px;">
      <?php else: ?>
        <svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2.5" style="width:14px;height:14px;">
          <rect x="3" y="9" width="18" height="12"/><polyline points="3 9 12 3 21 9"/>
        </svg>
      <?php endif; ?>
    </div>
    <span class="nav-logo-text"><?= $bizName ?></span>
  </a>
  <a href="/<?= htmlspecialchars(rawurlencode($slug)) ?>" class="nav-back">
    <span class="material-symbols-outlined">arrow_back</span>
    Back to Shop
  </a>
</header>

<main class="page">
  <div class="panel">
    <div class="card">

      <div class="card-icon">
        <span class="material-symbols-outlined" style="font-size:2rem;color:<?= $primary ?>;">
          <?= $mode === 'register' ? 'how_to_reg' : 'diamond' ?>
        </span>
      </div>

      <?php if ($mode === 'register'): ?>

        <!-- ══ REGISTRATION FORM ══════════════════════════════ -->
        <div class="reg-badge">
          <span class="material-symbols-outlined" style="font-size:11px;font-variation-settings:'FILL' 1,'wght' 400,'GRAD' 0,'opsz' 24;">verified</span>
          Account Setup
        </div>
        <h1 class="card-title">Set Up Your Account</h1>
        <p class="card-sub">Welcome to <?= $bizName ?>! Create your login credentials to get started.</p>

        <?php if ($error): ?>
        <div class="err">
          <span class="material-symbols-outlined">error</span>
          <?= htmlspecialchars($error) ?>
        </div>
        <?php endif; ?>

        <form method="POST" action="/<?= htmlspecialchars($slug) ?>?login=1&token=<?= htmlspecialchars($token) ?>" class="form">
          <input type="hidden" name="form_type" value="register">

          <div>
            <label class="lbl">Full Name *</label>
            <input type="text" name="fullname" class="inp" placeholder="Your full name"
              value="<?= htmlspecialchars($_POST['fullname'] ?? $inv['owner_name']) ?>" required>
          </div>

          <div>
            <label class="lbl">Email</label>
            <input type="email" class="inp" value="<?= htmlspecialchars($inv['email']) ?>" readonly>
          </div>

          <div>
            <label class="lbl">Username *</label>
            <input type="text" name="username" class="inp" placeholder="Choose a username"
              value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" required>
          </div>

          <div>
            <label class="lbl">Password * (min. 8 characters)</label>
            <div class="pw-wrap">
              <input type="password" name="password" id="pw1" class="inp" placeholder="Create a strong password" required oninput="checkStrength(this.value)">
              <button type="button" class="pw-btn"
                onclick="const f=document.getElementById('pw1');f.type=f.type==='password'?'text':'password';this.querySelector('span').textContent=f.type==='password'?'visibility':'visibility_off'">
                <span class="material-symbols-outlined">visibility</span>
              </button>
            </div>
            <!-- Password Strength Meter -->
            <div style="margin-top:6px;">
              <div style="height:5px;background:#e2e2e4;border-radius:99px;overflow:hidden;">
                <div id="str_bar" style="height:100%;width:0;border-radius:99px;transition:width .3s,background .3s;"></div>
              </div>
              <div style="display:flex;justify-content:space-between;align-items:center;margin-top:4px;">
                <span id="str_lbl" style="font-size:.68rem;font-weight:700;color:#aaa;transition:color .3s;"></span>
                <span style="font-size:.65rem;color:#999;">8+ chars, uppercase, numbers & symbols</span>
              </div>
            </div>
          </div>

          <div>
            <label class="lbl">Confirm Password *</label>
            <div class="pw-wrap">
              <input type="password" name="confirm" id="pw2" class="inp" placeholder="Repeat your password" required>
              <button type="button" class="pw-btn"
                onclick="const f=document.getElementById('pw2');f.type=f.type==='password'?'text':'password';this.querySelector('span').textContent=f.type==='password'?'visibility':'visibility_off'">
                <span class="material-symbols-outlined">visibility</span>
              </button>
            </div>
          </div>

          <button type="submit" class="btn">Create Account & Sign In →</button>
        </form>

      <?php else: ?>

        <?php if ($is_deactivated): ?>
        <!-- ══ DEACTIVATED SCREEN ══════════════════════════════ -->
        <div style="text-align:center;margin-bottom:18px;">
          <div style="width:64px;height:64px;border-radius:50%;background:#fef2f2;border:2px solid #fecaca;display:flex;align-items:center;justify-content:center;margin:0 auto 14px;">
            <span class="material-symbols-outlined" style="font-size:32px;color:#dc2626;">lock</span>
          </div>
          <h1 class="card-title" style="font-size:1.35rem;color:#111827;">Account Deactivated</h1>
          <p class="card-sub" style="margin-bottom:0;">Access to <strong><?= $bizName ?></strong> has been suspended due to an expired or inactive subscription.</p>
        </div>

        <div style="background:#fef2f2;border:1.5px solid #fecaca;border-radius:14px;padding:18px 18px 14px;margin-bottom:16px;">
          <div style="font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.09em;color:#dc2626;margin-bottom:10px;">What happened?</div>
          <ul style="font-size:.81rem;color:#7f1d1d;line-height:1.8;padding-left:18px;margin:0;">
            <li>Your subscription has expired<?= $sub_expired_days > 0 ? ' <strong>(' . $sub_expired_days . ' days ago)</strong>' : '' ?></li>
            <li>Your account was automatically deactivated</li>
            <li>All staff access has been temporarily suspended</li>
          </ul>
        </div>

        <div style="background:linear-gradient(135deg,#eff6ff,#f0fdf4);border:1.5px solid #bfdbfe;border-radius:14px;padding:18px;margin-bottom:16px;">
          <div style="font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.09em;color:#1d4ed8;margin-bottom:12px;">🔄 How to Restore Access</div>
          <ol style="font-size:.81rem;color:#1e3a5f;line-height:2;padding-left:18px;margin:0 0 14px;">
            <li>Contact PawnHub Admin to renew your subscription</li>
            <li>Admin will extend your subscription (1 month or more)</li>
            <li>Your account will be automatically re-activated</li>
            <li>All your staff can log in again immediately</li>
          </ol>

          <?php if ($sa_contact_email): ?>
          <div style="border-top:1px solid #bfdbfe;padding-top:12px;display:flex;flex-direction:column;gap:9px;">
            <div style="font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.09em;color:#1d4ed8;margin-bottom:2px;">📬 Contact Admin</div>
            <a href="mailto:<?= htmlspecialchars($sa_contact_email) ?>?subject=Subscription%20Renewal%20Request%20—%20<?= urlencode($bizName) ?>&body=Hi%20<?= urlencode($sa_contact_name) ?>%2C%0A%0AI%20would%20like%20to%20renew%20the%20subscription%20for%20<?= urlencode($bizName) ?>.%0A%0APlease%20assist%20us%20with%20reactivating%20our%20account.%0A%0AThank%20you."
              style="display:flex;align-items:center;gap:10px;background:#fff;border:1.5px solid #bfdbfe;border-radius:10px;padding:11px 14px;text-decoration:none;transition:all .18s;"
              onmouseover="this.style.background='#eff6ff'" onmouseout="this.style.background='#fff'">
              <div style="width:36px;height:36px;background:linear-gradient(135deg,#2563eb,#1d4ed8);border-radius:9px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                <span class="material-symbols-outlined" style="font-size:18px;color:#fff;">mail</span>
              </div>
              <div>
                <div style="font-size:.82rem;font-weight:700;color:#1d4ed8;"><?= htmlspecialchars($sa_contact_email) ?></div>
                <div style="font-size:.7rem;color:#64748b;">Tap to send a renewal request email</div>
              </div>
              <span class="material-symbols-outlined" style="font-size:16px;color:#93c5fd;margin-left:auto;">open_in_new</span>
            </a>
          </div>
          <?php else: ?>
          <div style="border-top:1px solid #bfdbfe;padding-top:12px;font-size:.8rem;color:#1d4ed8;font-weight:600;">
            📞 Please contact PawnHub support to renew your subscription.
          </div>
          <?php endif; ?>
        </div>

        <div style="background:#fffbeb;border:1px solid #fde68a;border-radius:10px;padding:11px 14px;font-size:.77rem;color:#92400e;line-height:1.6;">
          ⏱️ <strong>Once renewed</strong>, your account will be restored within minutes and all staff can log in again without any further action.
        </div>

        <?php else: ?>
        <!-- ══ LOGIN FORM ═════════════════════════════════════ -->
        <div class="tenant-badge">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:12px;height:12px;">
            <rect x="3" y="9" width="18" height="12"/><polyline points="3 9 12 3 21 9"/>
          </svg>
          <?= $bizName ?>
        </div>

        <h1 class="card-title">Welcome Back</h1>
        <p class="card-sub">Sign in to access the <?= $bizName ?> management system.</p>

        <?php if (!empty($_GET['registered'])): ?>
        <div style="display:flex;align-items:flex-start;gap:10px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px;padding:11px 14px;font-size:.81rem;color:#15803d;margin-bottom:16px;">
          <span class="material-symbols-outlined" style="font-size:17px;flex-shrink:0;font-variation-settings:'FILL' 1,'wght' 400,'GRAD' 0,'opsz' 24;">check_circle</span>
          <div><strong>Account ready!</strong> You can now sign in with your username and password.</div>
        </div>
        <?php endif; ?>

        <?php if ($error && $token): ?>
        <!-- Token-related error: show warning but keep login form accessible -->
        <div style="display:flex;align-items:flex-start;gap:10px;background:#fff7ed;border:1px solid #fed7aa;border-radius:10px;padding:11px 14px;font-size:.81rem;color:#c2410c;margin-bottom:16px;">
          <span class="material-symbols-outlined" style="font-size:17px;flex-shrink:0;font-variation-settings:'FILL' 1,'wght' 400,'GRAD' 0,'opsz' 24;">warning</span>
          <div><?= htmlspecialchars($error) ?></div>
        </div>
        <?php elseif ($error): ?>
        <div class="err">
          <span class="material-symbols-outlined">error</span>
          <?= htmlspecialchars($error) ?>
        </div>
        <?php endif; ?>

        <form method="POST" action="/<?= htmlspecialchars($slug) ?>?login=1" class="form">
          <input type="hidden" name="form_type" value="login">

          <div>
            <label class="lbl">Username</label>
            <input type="text" name="username" class="inp" placeholder="Enter your username"
              value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" required>
          </div>

          <div>
            <label class="lbl">Password</label>
            <div class="pw-wrap">
              <input type="password" name="password" id="pw" class="inp" placeholder="••••••••" required>
              <button type="button" class="pw-btn"
                onclick="const f=document.getElementById('pw');f.type=f.type==='password'?'text':'password';this.querySelector('span').textContent=f.type==='password'?'visibility':'visibility_off'">
                <span class="material-symbols-outlined">visibility</span>
              </button>
            </div>
          </div>

          <div class="rem">
            <input type="checkbox" id="rem">
            <label for="rem">Remember this device</label>
          </div>

          <button type="submit" class="btn">Sign In</button>

          <div style="text-align:center;margin-top:12px;">
            <a href="/forgot_password.php?slug=<?=urlencode($slug)?>" style="font-size:.78rem;color:var(--t-primary,#2563eb);font-weight:600;text-decoration:none;">Forgot your password?</a>
          </div>
        </form>

        <div class="card-foot">
          <div class="card-foot-row">
            <span class="material-symbols-outlined">info</span>
            <p>
              <strong>Need help?</strong>
              <span>Contact your branch administrator for account issues.</span>
            </p>
          </div>
        </div>

        <?php endif; // end $is_deactivated check ?>

      <?php endif; ?>

    </div>

    <div class="legal">
      <a href="#">Privacy Policy</a>
      <a href="#">Terms of Service</a>
      <a href="#">Support</a>
    </div>
  </div>
</main>

<div class="badge">
  <span class="bdot"></span>
  <span class="btxt">System Online</span>
</div>

<?php if ($mode === 'register' && $inv): ?>
<script>
function checkStrength(pw) {
  const bar = document.getElementById('str_bar');
  const lbl = document.getElementById('str_lbl');
  if (!bar) return;
  let score = 0;
  if (pw.length >= 8)           score++;
  if (pw.length >= 12)          score++;
  if (/[A-Z]/.test(pw))         score++;
  if (/[0-9]/.test(pw))         score++;
  if (/[^A-Za-z0-9]/.test(pw))  score++;
  const cfg = [
    { w: '0%',   c: '#e2e2e4', l: '' },
    { w: '25%',  c: '#ef4444', l: 'Weak' },
    { w: '50%',  c: '#f97316', l: 'Fair' },
    { w: '75%',  c: '#eab308', l: 'Good' },
    { w: '100%', c: '#22c55e', l: 'Strong' },
    { w: '100%', c: '#16a34a', l: 'Very Strong' },
  ];
  const s = cfg[score] ?? cfg[0];
  bar.style.width      = s.w;
  bar.style.background = s.c;
  lbl.textContent      = s.l;
  lbl.style.color      = s.c;
}

// ── Auto-suggest username with @slug suffix ───────────────────
(function() {
  const slugSuffix = '@<?= addslashes($slug ?? '') ?>.com';
  const usernameInput = document.querySelector('input[name="username"]');
  if (!usernameInput || usernameInput.value) return;

  const ownerName = '<?= addslashes(strtolower(preg_replace('/\s+/', '', $inv['owner_name'] ?? ''))) ?>';
  if (ownerName) usernameInput.value = ownerName + slugSuffix;

  usernameInput.addEventListener('input', function () {
    const val = this.value;
    if (!val.endsWith(slugSuffix)) {
      const base = val.replace(/@[^@]*$/, '');
      this.value = base + slugSuffix;
      this.setSelectionRange(base.length, base.length);
    }
  });
  usernameInput.addEventListener('keydown', function (e) {
    const prot = this.value.length - slugSuffix.length;
    if (this.selectionStart > prot && (e.key === 'Backspace' || e.key === 'Delete')) e.preventDefault();
  });
})();
</script>
<?php endif; ?>

</body>
</html>