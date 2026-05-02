<?php
require_once __DIR__ . '/session_helper.php';
pawnhub_session_start('super_admin');
require 'db.php';

$error = '';
$success = '';

// ── NORMAL LOGIN ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
              AND u.status = 'approved'
              AND u.is_suspended = 0
            LIMIT 1
        ");
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {
            if ($user['role'] !== 'super_admin') {
                $slug_stmt = $pdo->prepare("SELECT slug FROM tenants WHERE id = ? LIMIT 1");
                $slug_stmt->execute([$user['tenant_id']]);
                $tenant_row = $slug_stmt->fetch();
                if ($tenant_row && !empty($tenant_row['slug'])) {
                    $error = 'This page is for Super Admin only. Please use your branch login page: /' . htmlspecialchars($tenant_row['slug']);
                } else {
                    $error = 'This page is for Super Admin only. Please use your branch login page.';
                }
            } else {
                session_regenerate_id(true);
                $_SESSION['user'] = [
                    'id'          => $user['id'],
                    'name'        => $user['fullname'],
                    'username'    => $user['username'],
                    'role'        => $user['role'],
                    'tenant_id'   => $user['tenant_id'],
                    'tenant_name' => $user['tenant_name'],
                ];
                // ── Audit log: Super Admin login ──────────────────
                try {
                    $login_msg = 'Super Admin "' . $user['username'] . '" logged in.';
                    $pdo->prepare("INSERT INTO audit_logs (tenant_id,actor_user_id,actor_username,actor_role,action,entity_type,entity_id,message,ip_address,created_at) VALUES (NULL,?,?,?,'SUPER_ADMIN_LOGIN','user',?,?,?,NOW())")
                        ->execute([$user['id'], $user['username'], 'super_admin', $user['id'], $login_msg, $_SERVER['REMOTE_ADDR'] ?? '::1']);
                } catch (Throwable $e) {}
                header('Location: superadmin.php'); exit;
            }
        } else {
            $chk = $pdo->prepare("SELECT status, is_suspended FROM users WHERE username = ? LIMIT 1");
            $chk->execute([$username]);
            $chk = $chk->fetch();
            if (!$chk)                               $error = 'Username not found.';
            elseif ((int)$chk['is_suspended'] === 1) $error = 'Your account has been suspended.';
            elseif ($chk['status'] === 'pending')    $error = 'Your account is pending approval.';
            elseif ($chk['status'] === 'rejected')   $error = 'Your account was rejected.';
            else                                     $error = 'Incorrect password.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>PawnHub — Super Admin Sign In</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet"/>
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet"/>
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
html { width: 100%; height: 100%; font-family: 'Inter', sans-serif; }
body { width: 100%; min-height: 100vh; font-family: 'Inter', sans-serif; overflow-x: hidden; overflow-y: auto; background: #0b1120; }

/* ── Blurred background ── */
.bg {
  position: fixed; inset: 0; z-index: 0;
  background-image: url('https://images.unsplash.com/photo-1553729459-efe14ef6055d?w=1600&q=80');
  background-size: cover; background-position: center;
  filter: blur(7px) brightness(0.4) saturate(0.65);
  transform: scale(1.06);
}
.bg-ov {
  position: fixed; inset: 0; z-index: 1;
  background: radial-gradient(ellipse at center, rgba(5,10,30,0.4) 0%, rgba(5,10,30,0.85) 100%);
}
.nav {
  position: fixed; top: 0; left: 0; right: 0; z-index: 100; height: 64px;
  display: flex; align-items: center; justify-content: space-between; padding: 0 36px;
  background: rgba(255,255,255,0.07); backdrop-filter: blur(14px); -webkit-backdrop-filter: blur(14px);
  border-bottom: 1px solid rgba(255,255,255,0.08);
}
.nav-logo { display: flex; align-items: center; gap: 9px; text-decoration: none; }
.nav-logo-icon { width: 32px; height: 32px; background: linear-gradient(135deg,#3b82f6,#8b5cf6); border-radius: 9px; display: flex; align-items: center; justify-content: center; }
.nav-logo-text { font-size: 1.15rem; font-weight: 800; color: #fff; letter-spacing: -0.02em; }
.nav-links { display: flex; align-items: center; gap: 28px; }
.nav-links a { font-size: 0.8rem; font-weight: 500; color: rgba(255,255,255,0.6); text-decoration: none; transition: color .2s; }
.nav-links a:hover { color: #fff; }
.page { position: relative; z-index: 10; width: 100%; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding-top: 84px; padding-bottom: 24px; padding-left: 16px; padding-right: 16px; }
.panel {
  width: 100%; max-width: 460px;
  display: flex; flex-direction: column; align-items: center; padding: 0;
  filter: drop-shadow(0 0 40px rgba(59,130,246,0.15));
}
.card {
  width: 100%;
  background: rgba(255,255,255,0.93);
  backdrop-filter: blur(32px); -webkit-backdrop-filter: blur(32px);
  border-radius: 22px; padding: 36px 32px 28px;
  box-shadow: 0 24px 60px rgba(0,0,0,0.45), 0 0 0 1px rgba(255,255,255,0.18);
  border: 1px solid rgba(255,255,255,0.28);
}
.card-icon { margin-bottom: 12px; }
.material-symbols-outlined { font-variation-settings: 'FILL' 1,'wght' 400,'GRAD' 0,'opsz' 24; }
.sa-badge { display: inline-flex; align-items: center; gap: 5px; background: linear-gradient(135deg,#1d4ed8,#7c3aed); color: #fff; font-size: 0.65rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.08em; padding: 3px 10px; border-radius: 100px; margin-bottom: 14px; }
.card-title { font-size: 1.9rem; font-weight: 800; color: #111827; letter-spacing: -0.03em; line-height: 1.1; margin-bottom: 5px; }
.card-sub { font-size: 0.81rem; color: #64748b; line-height: 1.5; margin-bottom: 20px; }
.err { display: flex; align-items: center; gap: 8px; background: #fef2f2; border: 1px solid #fecaca; border-radius: 9px; padding: 9px 12px; font-size: 0.79rem; color: #dc2626; margin-bottom: 16px; }
.err .material-symbols-outlined { font-size: 15px; flex-shrink: 0; }
.succ { display: flex; align-items: center; gap: 8px; background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 9px; padding: 9px 12px; font-size: 0.79rem; color: #15803d; margin-bottom: 16px; }
.form { display: flex; flex-direction: column; gap: 14px; }
.lbl { display: block; font-size: 0.67rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.09em; color: #64748b; margin-bottom: 5px; }
.inp { width: 100%; height: 46px; padding: 0 15px; background: rgba(218,218,224,0.38); border: 1.5px solid transparent; border-radius: 10px; font-family: 'Inter', sans-serif; font-size: 0.87rem; color: #111827; outline: none; transition: background .2s, border-color .2s, box-shadow .2s; }
.inp:focus { background: #fff; border-color: rgba(30,58,138,0.28); box-shadow: 0 0 0 3px rgba(30,58,138,0.09); }
.inp::placeholder { color: #b0b8c5; }
.pw-wrap { position: relative; }
.pw-wrap .inp { padding-right: 42px; }
.pw-btn { position: absolute; right: 11px; top: 50%; transform: translateY(-50%); background: none; border: none; cursor: pointer; color: #94a3b8; display: flex; align-items: center; padding: 0; transition: color .2s; }
.pw-btn:hover { color: #475569; }
.pw-btn .material-symbols-outlined { font-size: 18px; font-variation-settings: 'FILL' 0,'wght' 400,'GRAD' 0,'opsz' 24; }
.rem { display: flex; align-items: center; gap: 8px; }
.rem input[type="checkbox"] { width: 16px; height: 16px; border-radius: 4px; accent-color: #1e3a8a; cursor: pointer; }
.rem label { font-size: 0.81rem; color: #64748b; cursor: pointer; user-select: none; }
.btn { width: 100%; height: 46px; background: linear-gradient(135deg,#1e3a8a,#2563eb); color: #fff; border: none; border-radius: 10px; font-family: 'Inter', sans-serif; font-size: 0.91rem; font-weight: 700; cursor: pointer; box-shadow: 0 5px 16px rgba(30,58,138,0.28); transition: transform .15s, box-shadow .15s; }
.btn:hover { transform: translateY(-1px); box-shadow: 0 7px 22px rgba(30,58,138,0.36); }
.btn:active { transform: translateY(0); }
.toggle-link { text-align: center; margin-top: 14px; }
.toggle-link a { font-size: 0.79rem; color: #2563eb; font-weight: 600; text-decoration: none; cursor: pointer; transition: color .2s; }
.toggle-link a:hover { color: #1d4ed8; text-decoration: underline; }
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
  .nav-links { display: none; }
  .card { padding: 22px 18px 20px; }
  .card-title { font-size: 1.5rem; }
  .badge { display: none; }
  .nav { padding: 0 16px; }
}
</style>
</head>
<body>

<div class="bg"></div>
<div class="bg-ov"></div>

<header class="nav">
  <a href="home.php" class="nav-logo">
    <div class="nav-logo-icon">
      <svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2.5" style="width:14px;height:14px;">
        <rect x="3" y="9" width="18" height="12"/>
        <polyline points="3 9 12 3 21 9"/>
      </svg>
    </div>
    <span class="nav-logo-text">PawnHub</span>
  </a>
  <nav class="nav-links">
    <a href="#">Platform Status</a>
    <a href="#">Security</a>
  </nav>
</header>

<main class="page">
  <div class="panel">
    <div class="card">

      <div class="card-icon">
        <span class="material-symbols-outlined" style="font-size:2rem;color:#1e3a8a;">shield</span>
      </div>

      <div class="sa-badge">
        <span class="material-symbols-outlined" style="font-size:11px;font-variation-settings:'FILL' 1,'wght' 400,'GRAD' 0,'opsz' 24;">verified_user</span>
        Super Admin Portal
      </div>

      <h1 class="card-title">Welcome Back</h1>
      <p class="card-sub">This portal is for Super Admin access only. Tenant staff should use their branch login page.</p>

      <?php if ($success): ?>
      <div class="succ">
        <span class="material-symbols-outlined" style="font-size:15px;flex-shrink:0;">check_circle</span>
        <?= htmlspecialchars($success) ?>
      </div>
      <?php endif; ?>

      <?php if ($error): ?>
      <div class="err">
        <span class="material-symbols-outlined">error</span>
        <?= htmlspecialchars($error) ?>
      </div>
      <?php endif; ?>

      <form method="POST" action="" class="form">
        <div>
          <label class="lbl">Username</label>
          <input type="text" name="username" class="inp"
            placeholder="Enter your username"
            value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" required>
        </div>
        <div>
          <label class="lbl">Password</label>
          <div class="pw-wrap">
            <input type="password" name="password" id="pw" class="inp" placeholder="••••••••" required>
            <button type="button" class="pw-btn" onclick="togglePw('pw',this)">
              <span class="material-symbols-outlined">visibility</span>
            </button>
          </div>
        </div>
        <div style="display:flex;align-items:center;justify-content:space-between;">
          <div class="rem">
            <input type="checkbox" id="rem">
            <label for="rem">Remember this device</label>
          </div>
          <a href="sa_forgot_password.php" style="font-size:0.79rem;color:#2563eb;font-weight:600;text-decoration:none;">Forgot password?</a>
        </div>
        <button type="submit" class="btn">Sign In</button>
      </form>

      <div class="card-foot">
        <div class="card-foot-row">
          <span class="material-symbols-outlined">info</span>
          <p>
            <strong>Not the Super Admin?</strong>
            <span>Use the login link provided by your branch administrator.</span>
          </p>
        </div>
      </div>

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

<script>
function togglePw(id, btn) {
  const f = document.getElementById(id);
  f.type = f.type === 'password' ? 'text' : 'password';
  btn.querySelector('span').textContent = f.type === 'password' ? 'visibility' : 'visibility_off';
}
</script>

</body>
</html>