<?php
session_start();
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
            header('Location: /' . urlencode($inv['tenant_slug']) . '?token=' . urlencode($token));
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

            // Redirect to tenant login page with success message — do NOT auto-login
            $login_url = '/' . urlencode($slug) . '?registered=1';
            header('Location: ' . $login_url);
            exit;
        }
    }
}

// ── Handle LOGIN POST ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['form_type']) && $_POST['form_type'] === 'login') {
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
$bgImg   = !empty($tenant['bg_image_url'])
    ? htmlspecialchars($tenant['bg_image_url'])
    : 'https://lh3.googleusercontent.com/aida-public/AB6AXuA5_TIJZ7gPS7TJbOhT3mlXkiGTUvK43P5Q8JmtLOQPLEnW8MKgHVTqL5442kQYiDWY2QRo_pnnF1X6G1YizmlZKqXAbLflQBQVaeL_HbIOwxlElZ3gGQ_OPy-TLgjSmD_GDGGtrS4x6rwlP9ctf92uKuFXsjFkkcdS5LHGxcoOTSJskN5b3c9_KXjKPDKJjJgRT9FPsydoU9KGPFwWC1sGixVh4AqRUtT9Yfj6XN0cZG7WRmxqeAScFuFEr6EXTcva1GIdW5wthlI';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
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
.nav { position: fixed; top: 0; left: 0; right: 0; z-index: 50; height: 64px; display: flex; align-items: center; padding: 0 36px; background: rgba(255,255,255,0.07); backdrop-filter: blur(14px); -webkit-backdrop-filter: blur(14px); border-bottom: 1px solid rgba(255,255,255,0.08); }
.nav-logo { display: flex; align-items: center; gap: 9px; text-decoration: none; }
.nav-logo-icon { width: 32px; height: 32px; background: linear-gradient(135deg, var(--primary), var(--accent)); border-radius: 9px; display: flex; align-items: center; justify-content: center; }
.nav-logo-text { font-size: 1.15rem; font-weight: 800; color: #fff; letter-spacing: -0.02em; }
.page { position: relative; z-index: 10; width: 100%; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding-top: 84px; padding-bottom: 24px; }
.panel { width: 460px; min-width: 460px; display: flex; flex-direction: column; align-items: center; padding: 0 40px; }
.card { width: 100%; background: rgba(255,255,255,0.91); backdrop-filter: blur(28px); -webkit-backdrop-filter: blur(28px); border-radius: 22px; padding: 34px 30px 26px; box-shadow: 0 18px 48px rgba(10,20,60,0.20); border: 1px solid rgba(255,255,255,0.26); }
.card-icon { margin-bottom: 12px; }
.material-symbols-outlined { font-variation-settings: 'FILL' 1,'wght' 400,'GRAD' 0,'opsz' 24; }
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
  .panel { width: 100%; min-width: unset; padding: 0 16px; }
  .card { padding: 26px 20px 22px; }
  .card-title { font-size: 1.5rem; }
  .badge { display: none; }
}
</style>
</head>
<body>

<div class="bg">
  <img src="<?= $bgImg ?>" alt="<?= $bizName ?>"/>
  <div class="bg-ov"></div>
</div>

<header class="nav">
  <a href="#" class="nav-logo">
    <div class="nav-logo-icon">
      <svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2.5" style="width:14px;height:14px;">
        <rect x="3" y="9" width="18" height="12"/><polyline points="3 9 12 3 21 9"/>
      </svg>
    </div>
    <span class="nav-logo-text"><?= $bizName ?></span>
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

        <form method="POST" action="/<?= htmlspecialchars($slug) ?>?token=<?= htmlspecialchars($token) ?>" class="form">
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
              <input type="password" name="password" id="pw1" class="inp" placeholder="Create a strong password" required>
              <button type="button" class="pw-btn"
                onclick="const f=document.getElementById('pw1');f.type=f.type==='password'?'text':'password';this.querySelector('span').textContent=f.type==='password'?'visibility':'visibility_off'">
                <span class="material-symbols-outlined">visibility</span>
              </button>
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
          <div><strong>Account created successfully!</strong> You can now sign in with your new username and password.</div>
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

        <form method="POST" action="/<?= htmlspecialchars($slug) ?>" class="form">
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

</body>
</html>