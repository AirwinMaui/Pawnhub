<?php
session_start();
require 'db.php';

$slug  = trim($_GET['slug'] ?? '');
$error = '';
$tenant = null;

if ($slug) {
    $stmt = $pdo->prepare("SELECT * FROM tenants WHERE slug = ? AND status = 'active' LIMIT 1");
    $stmt->execute([$slug]);
    $tenant = $stmt->fetch();
}

if (!$tenant) {
    header('Location: login.php');
    exit;
}

// Handle login POST
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
            ];
            if ($user['role'] === 'admin')   { header('Location: tenant.php');  exit; }
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

$primary = htmlspecialchars($tenant['primary_color'] ?? '#1e3a8a');
$accent  = htmlspecialchars($tenant['accent_color']  ?? '#2563eb');
$bizName = htmlspecialchars($tenant['business_name'] ?? 'PawnHub');
$bgImg   = !empty($tenant['bg_image_url'])
    ? htmlspecialchars($tenant['bg_image_url'])
    : 'https://lh3.googleusercontent.com/aida-public/AB6AXuA5_TIJZ7gPS7TJbOhT3mlXkiGTUvK43P5Q8JmtLOQPLEnW8MKgHVTqL5442kQYiDWY2QRo_pnnF1X6G1YizmlZKqXAbLflQBQVaeL_HbIOwxlElZ3gGQ_OPy-TLgjSmD_GDGGtrS4x6rwlP9ctf92uKuFXsjFkkcdS5LHGxcoOTSJskN5b3c9_KXjKPDKJjJgRT9FPsydoU9KGPFwWC1sGixVh4AqRUtT9Yfj6XN0cZG7WRmxqeAScFuFEr6EXTcva1GIdW5wthlI';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title><?= $bizName ?> — Sign In</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet"/>
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet"/>
<style>
:root {
  --primary: <?= $primary ?>;
  --accent:  <?= $accent ?>;
}
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
html, body { width: 100%; height: 100%; font-family: 'Inter', sans-serif; overflow: hidden; }

.bg { position: fixed; inset: 0; z-index: 0; }
.bg img { width: 100%; height: 100%; object-fit: cover; display: block; }
.bg-ov { position: absolute; inset: 0; background: rgba(10,20,60,0.52); }

.nav {
  position: fixed; top: 0; left: 0; right: 0; z-index: 50;
  height: 64px; display: flex; align-items: center; justify-content: space-between;
  padding: 0 36px;
  background: rgba(255,255,255,0.07);
  backdrop-filter: blur(14px); -webkit-backdrop-filter: blur(14px);
  border-bottom: 1px solid rgba(255,255,255,0.08);
}
.nav-logo { display: flex; align-items: center; gap: 9px; text-decoration: none; }
.nav-logo-icon {
  width: 32px; height: 32px;
  background: linear-gradient(135deg, var(--primary), var(--accent));
  border-radius: 9px; display: flex; align-items: center; justify-content: center;
}
.nav-logo-text { font-size: 1.15rem; font-weight: 800; color: #fff; letter-spacing: -0.02em; }

.page {
  position: relative; z-index: 10; width: 100%; height: 100vh;
  display: flex; align-items: center; justify-content: center;
  padding-top: 64px;
}
.panel {
  width: 460px; min-width: 460px;
  display: flex; flex-direction: column; align-items: center;
  padding: 0 40px;
}
.card {
  width: 100%;
  background: rgba(255,255,255,0.91);
  backdrop-filter: blur(28px); -webkit-backdrop-filter: blur(28px);
  border-radius: 22px; padding: 34px 30px 26px;
  box-shadow: 0 18px 48px rgba(10,20,60,0.20);
  border: 1px solid rgba(255,255,255,0.26);
}
.card-icon { margin-bottom: 12px; }
.material-symbols-outlined { font-variation-settings: 'FILL' 1,'wght' 400,'GRAD' 0,'opsz' 24; }
.card-title { font-size: 1.9rem; font-weight: 800; color: #111827; letter-spacing: -0.03em; line-height: 1.1; margin-bottom: 5px; }
.card-sub { font-size: 0.81rem; color: #64748b; line-height: 1.5; margin-bottom: 20px; }

.err {
  display: flex; align-items: center; gap: 8px;
  background: #fef2f2; border: 1px solid #fecaca;
  border-radius: 9px; padding: 9px 12px;
  font-size: 0.79rem; color: #dc2626; margin-bottom: 16px;
}
.err .material-symbols-outlined { font-size: 15px; flex-shrink: 0; }

.form { display: flex; flex-direction: column; gap: 14px; }
.lbl { display: block; font-size: 0.67rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.09em; color: #64748b; margin-bottom: 5px; }
.inp {
  width: 100%; height: 46px; padding: 0 15px;
  background: rgba(218,218,224,0.38);
  border: 1.5px solid transparent; border-radius: 10px;
  font-family: 'Inter', sans-serif; font-size: 0.87rem; color: #111827;
  outline: none; transition: background .2s, border-color .2s, box-shadow .2s;
}
.inp:focus {
  background: #fff;
  border-color: var(--primary);
  box-shadow: 0 0 0 3px color-mix(in srgb, var(--primary) 15%, transparent);
}
.inp::placeholder { color: #b0b8c5; }

.pw-wrap { position: relative; }
.pw-wrap .inp { padding-right: 42px; }
.pw-btn {
  position: absolute; right: 11px; top: 50%; transform: translateY(-50%);
  background: none; border: none; cursor: pointer; color: #94a3b8;
  display: flex; align-items: center; padding: 0; transition: color .2s;
}
.pw-btn:hover { color: #475569; }
.pw-btn .material-symbols-outlined { font-size: 18px; font-variation-settings: 'FILL' 0,'wght' 400,'GRAD' 0,'opsz' 24; }

.rem { display: flex; align-items: center; gap: 8px; }
.rem input[type="checkbox"] { width: 16px; height: 16px; border-radius: 4px; accent-color: var(--primary); cursor: pointer; }
.rem label { font-size: 0.81rem; color: #64748b; cursor: pointer; user-select: none; }

.btn {
  width: 100%; height: 46px;
  background: linear-gradient(135deg, var(--primary), var(--accent));
  color: #fff; border: none; border-radius: 10px;
  font-family: 'Inter', sans-serif; font-size: 0.91rem; font-weight: 700; cursor: pointer;
  box-shadow: 0 5px 16px rgba(0,0,0,0.2);
  transition: transform .15s, box-shadow .15s;
}
.btn:hover { transform: translateY(-1px); box-shadow: 0 7px 22px rgba(0,0,0,0.28); }
.btn:active { transform: translateY(0); }

.tenant-badge {
  display: inline-flex; align-items: center; gap: 6px;
  background: color-mix(in srgb, var(--primary) 10%, transparent);
  border: 1px solid color-mix(in srgb, var(--primary) 30%, transparent);
  border-radius: 8px; padding: 5px 10px;
  font-size: 0.72rem; font-weight: 700;
  color: var(--primary);
  margin-bottom: 14px;
}

.card-foot { margin-top: 18px; padding-top: 14px; border-top: 1px solid rgba(0,0,0,0.07); }
.card-foot-row { display: flex; align-items: flex-start; gap: 8px; }
.card-foot-row .material-symbols-outlined { font-size: 16px; color: #94a3b8; margin-top: 1px; font-variation-settings: 'FILL' 0,'wght' 400,'GRAD' 0,'opsz' 24; }
.card-foot p { font-size: 0.75rem; line-height: 1.55; }
.card-foot strong { color: #475569; font-weight: 600; display: block; }
.card-foot span { color: #94a3b8; }
.card-foot a { color: var(--primary); font-weight: 700; text-decoration: none; }
.card-foot a:hover { text-decoration: underline; }

.legal { margin-top: 12px; display: flex; gap: 16px; padding-left: 2px; }
.legal a { font-size: 0.68rem; color: rgba(255,255,255,0.44); text-decoration: none; transition: color .2s; }
.legal a:hover { color: #fff; }

.badge {
  position: fixed; bottom: 22px; right: 22px; z-index: 20;
  display: flex; align-items: center; gap: 8px;
  background: rgba(255,255,255,0.08);
  backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px);
  padding: 7px 15px; border-radius: 100px;
  border: 1px solid rgba(255,255,255,0.10);
}
.bdot { width: 7px; height: 7px; border-radius: 50%; background: #34d399; animation: pulse 2s infinite; }
.btxt { font-size: 0.63rem; font-weight: 600; color: rgba(255,255,255,0.82); text-transform: uppercase; letter-spacing: 0.1em; }
@keyframes pulse { 0%,100%{opacity:1}50%{opacity:.35} }

@media (max-width: 560px) {
  .panel { width: 100%; min-width: unset; padding: 0 16px; }
  .card { padding: 26px 20px 22px; }
  .card-title { font-size: 1.6rem; }
  .badge { display: none; }
}
</style>
</head>
<body>

<div class="bg">
  <img src="<?= $bgImg ?>" alt="<?= $bizName ?> background"/>
  <div class="bg-ov"></div>
</div>

<header class="nav">
  <a href="#" class="nav-logo">
    <div class="nav-logo-icon">
      <svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2.5" style="width:14px;height:14px;">
        <rect x="3" y="9" width="18" height="12"/>
        <polyline points="3 9 12 3 21 9"/>
      </svg>
    </div>
    <span class="nav-logo-text"><?= $bizName ?></span>
  </a>
</header>

<main class="page">
  <div class="panel">
    <div class="card">

      <div class="card-icon">
        <span class="material-symbols-outlined" style="font-size:2rem;color:<?= $primary ?>;">diamond</span>
      </div>

      <div class="tenant-badge">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:12px;height:12px;">
          <rect x="3" y="9" width="18" height="12"/><polyline points="3 9 12 3 21 9"/>
        </svg>
        <?= $bizName ?>
      </div>

      <h1 class="card-title">Welcome Back</h1>
      <p class="card-sub">Sign in to access the <?= $bizName ?> management system.</p>

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