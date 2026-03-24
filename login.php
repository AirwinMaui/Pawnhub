<?php
session_start();
require 'db.php';

$error = '';

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
            session_regenerate_id(true);

            $_SESSION['user'] = [
                'id'          => $user['id'],
                'name'        => $user['fullname'],
                'username'    => $user['username'],
                'role'        => $user['role'],
                'tenant_id'   => $user['tenant_id'],
                'tenant_name' => $user['tenant_name'],
            ];

            if ($user['role'] === 'super_admin') { header('Location: superadmin.php'); exit; }
            if ($user['role'] === 'admin')        { header('Location: tenant.php');     exit; }
            if ($user['role'] === 'staff')        { header('Location: staff.php');      exit; }
            if ($user['role'] === 'cashier')      { header('Location: cashier.php');    exit; }

            session_unset();
            session_destroy();
            $error = 'Unknown user role.';
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
<meta content="width=device-width, initial-scale=1.0" name="viewport"/>
<title>PawnHub — Sign In</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet"/>
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet"/>
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

html, body {
  width: 100%;
  height: 100%;
  font-family: 'Inter', sans-serif;
  overflow: hidden;
}

/* ── BACKGROUND ── */
.bg-layer {
  position: fixed;
  inset: 0;
  z-index: 0;
}
.bg-layer img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  display: block;
}
.bg-overlay {
  position: absolute;
  inset: 0;
  background: rgba(15, 30, 80, 0.45);
  backdrop-filter: brightness(0.7);
}

/* ── NAV ── */
.nav {
  position: fixed;
  top: 0;
  left: 0;
  right: 0;
  z-index: 50;
  height: 72px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 0 40px;
  background: rgba(255,255,255,0.06);
  backdrop-filter: blur(16px);
  -webkit-backdrop-filter: blur(16px);
  border-bottom: 1px solid rgba(255,255,255,0.08);
}
.nav-logo {
  display: flex;
  align-items: center;
  gap: 10px;
  text-decoration: none;
}
.nav-logo-icon {
  width: 34px;
  height: 34px;
  background: linear-gradient(135deg, #3b82f6, #8b5cf6);
  border-radius: 10px;
  display: flex;
  align-items: center;
  justify-content: center;
}
.nav-logo-text {
  font-size: 1.2rem;
  font-weight: 800;
  color: #fff;
  letter-spacing: -0.02em;
}
.nav-links {
  display: flex;
  align-items: center;
  gap: 32px;
}
.nav-links a {
  font-size: 0.82rem;
  font-weight: 500;
  color: rgba(255,255,255,0.65);
  text-decoration: none;
  transition: color 0.2s;
}
.nav-links a:hover { color: #fff; }

/* ── MAIN LAYOUT ── */
.page-main {
  position: relative;
  z-index: 10;
  width: 100%;
  height: 100vh;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 80px 24px 24px; /* top padding = nav height */
}

/* ── CARD ── */
.card {
  width: 100%;
  max-width: 440px;
  background: rgba(255,255,255,0.88);
  backdrop-filter: blur(32px);
  -webkit-backdrop-filter: blur(32px);
  border-radius: 28px;
  padding: 44px 40px 36px;
  box-shadow: 0 24px 60px rgba(15,30,80,0.22), 0 4px 16px rgba(0,0,0,0.08);
  border: 1px solid rgba(255,255,255,0.3);
}

/* ── CARD HEADER ── */
.card-icon {
  margin-bottom: 22px;
}
.material-symbols-outlined {
  font-variation-settings: 'FILL' 1, 'wght' 400, 'GRAD' 0, 'opsz' 24;
}
.card-title {
  font-size: 2.4rem;
  font-weight: 800;
  color: #111827;
  letter-spacing: -0.03em;
  line-height: 1.1;
  margin-bottom: 8px;
}
.card-subtitle {
  font-size: 0.84rem;
  color: #64748b;
  line-height: 1.55;
  margin-bottom: 30px;
}

/* ── ERROR ── */
.error-box {
  background: #fef2f2;
  border: 1px solid #fecaca;
  border-radius: 10px;
  padding: 11px 14px;
  font-size: 0.82rem;
  color: #dc2626;
  margin-bottom: 22px;
  display: flex;
  align-items: center;
  gap: 8px;
}
.error-box .material-symbols-outlined {
  font-size: 16px;
  flex-shrink: 0;
  font-variation-settings: 'FILL' 1, 'wght' 400, 'GRAD' 0, 'opsz' 24;
}

/* ── FORM ── */
.form { display: flex; flex-direction: column; gap: 20px; }

.field-label {
  display: block;
  font-size: 0.7rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.09em;
  color: #64748b;
  margin-bottom: 7px;
  margin-left: 2px;
}

.field-input {
  width: 100%;
  height: 52px;
  padding: 0 18px;
  background: rgba(226,226,228,0.4);
  border: 1.5px solid transparent;
  border-radius: 12px;
  font-family: 'Inter', sans-serif;
  font-size: 0.9rem;
  color: #111827;
  outline: none;
  transition: background 0.2s, border-color 0.2s, box-shadow 0.2s;
}
.field-input:focus {
  background: #fff;
  border-color: rgba(30,58,138,0.3);
  box-shadow: 0 0 0 3px rgba(30,58,138,0.1);
}
.field-input::placeholder { color: #adb5bd; }

.pw-wrap { position: relative; }
.pw-wrap .field-input { padding-right: 48px; }
.pw-toggle {
  position: absolute;
  right: 13px;
  top: 50%;
  transform: translateY(-50%);
  background: none;
  border: none;
  cursor: pointer;
  color: #94a3b8;
  display: flex;
  align-items: center;
  padding: 0;
  transition: color 0.2s;
}
.pw-toggle:hover { color: #475569; }
.pw-toggle .material-symbols-outlined {
  font-size: 20px;
  font-variation-settings: 'FILL' 0, 'wght' 400, 'GRAD' 0, 'opsz' 24;
}

.remember-row {
  display: flex;
  align-items: center;
  gap: 10px;
}
.remember-row input[type="checkbox"] {
  width: 17px;
  height: 17px;
  border-radius: 5px;
  accent-color: #1e3a8a;
  cursor: pointer;
  flex-shrink: 0;
}
.remember-row label {
  font-size: 0.84rem;
  color: #64748b;
  cursor: pointer;
  user-select: none;
}

.btn-signin {
  width: 100%;
  height: 52px;
  background: linear-gradient(135deg, #1e3a8a 0%, #2563eb 100%);
  color: #fff;
  border: none;
  border-radius: 12px;
  font-family: 'Inter', sans-serif;
  font-size: 0.94rem;
  font-weight: 700;
  cursor: pointer;
  letter-spacing: 0.01em;
  box-shadow: 0 6px 22px rgba(30,58,138,0.3);
  transition: transform 0.15s, box-shadow 0.15s;
}
.btn-signin:hover {
  transform: translateY(-1px);
  box-shadow: 0 8px 28px rgba(30,58,138,0.38);
}
.btn-signin:active {
  transform: translateY(0);
}

/* ── CARD FOOTER ── */
.card-footer {
  margin-top: 28px;
  padding-top: 22px;
  border-top: 1px solid rgba(0,0,0,0.07);
}
.card-footer-row {
  display: flex;
  align-items: flex-start;
  gap: 10px;
}
.card-footer-row .material-symbols-outlined {
  font-size: 18px;
  color: #94a3b8;
  margin-top: 1px;
  font-variation-settings: 'FILL' 0, 'wght' 400, 'GRAD' 0, 'opsz' 24;
}
.card-footer p { font-size: 0.78rem; line-height: 1.55; }
.card-footer p strong { color: #475569; font-weight: 600; display: block; }
.card-footer p span { color: #94a3b8; }
.card-footer a { color: #1e3a8a; font-weight: 700; text-decoration: none; }
.card-footer a:hover { text-decoration: underline; }

/* ── LEGAL LINKS ── */
.legal-links {
  margin-top: 20px;
  display: flex;
  gap: 20px;
  padding-left: 4px;
}
.legal-links a {
  font-size: 0.73rem;
  color: rgba(255,255,255,0.5);
  text-decoration: none;
  transition: color 0.2s;
}
.legal-links a:hover { color: #fff; }

/* ── SYSTEM BADGE ── */
.system-badge {
  position: fixed;
  bottom: 28px;
  right: 28px;
  z-index: 20;
  display: flex;
  align-items: center;
  gap: 9px;
  background: rgba(255,255,255,0.09);
  backdrop-filter: blur(14px);
  -webkit-backdrop-filter: blur(14px);
  padding: 9px 18px;
  border-radius: 100px;
  border: 1px solid rgba(255,255,255,0.12);
}
.badge-dot {
  width: 8px;
  height: 8px;
  border-radius: 50%;
  background: #34d399;
  animation: pulse 2s infinite;
}
.badge-text {
  font-size: 0.68rem;
  font-weight: 600;
  color: rgba(255,255,255,0.85);
  text-transform: uppercase;
  letter-spacing: 0.1em;
}

@keyframes pulse {
  0%, 100% { opacity: 1; }
  50% { opacity: 0.35; }
}

@media (max-width: 600px) {
  .nav-links { display: none; }
  .card { padding: 32px 24px 28px; }
  .card-title { font-size: 2rem; }
  .system-badge { display: none; }
}
</style>
</head>
<body>

<!-- BACKGROUND -->
<div class="bg-layer">
  <img src="https://lh3.googleusercontent.com/aida-public/AB6AXuA5_TIJZ7gPS7TJbOhT3mlXkiGTUvK43P5Q8JmtLOQPLEnW8MKgHVTqL5442kQYiDWY2QRo_pnnF1X6G1YizmlZKqXAbLflQBQVaeL_HbIOwxlElZ3gGQ_OPy-TLgjSmD_GDGGtrS4x6rwlP9ctf92uKuFXsjFkkcdS5LHGxcoOTSJskN5b3c9_KXjKPDKJjJgRT9FPsydoU9KGPFwWC1sGixVh4AqRUtT9Yfj6XN0cZG7WRmxqeAScFuFEr6EXTcva1GIdW5wthlI" alt="PawnHub background"/>
  <div class="bg-overlay"></div>
</div>

<!-- NAV -->
<header class="nav">
  <a href="index.php" class="nav-logo">
    <div class="nav-logo-icon">
      <svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2.5" style="width:16px;height:16px;">
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

<!-- MAIN -->
<main class="page-main">
  <div style="width:100%;max-width:440px;">

    <!-- CARD -->
    <div class="card">

      <div class="card-icon">
        <span class="material-symbols-outlined" style="font-size:2.4rem;color:#1e3a8a;">diamond</span>
      </div>

      <h1 class="card-title">Welcome Back</h1>
      <p class="card-subtitle">Enter your credentials to access the PawnHub platform.</p>

      <?php if ($error): ?>
      <div class="error-box">
        <span class="material-symbols-outlined">error</span>
        <?= htmlspecialchars($error) ?>
      </div>
      <?php endif; ?>

      <form method="POST" action="" class="form">

        <div>
          <label class="field-label">Username</label>
          <input type="text" name="username" class="field-input"
            placeholder="Enter your username"
            value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" required>
        </div>

        <div>
          <label class="field-label">Password</label>
          <div class="pw-wrap">
            <input type="password" name="password" id="pw" class="field-input"
              placeholder="••••••••" required>
            <button type="button" class="pw-toggle"
              onclick="const f=document.getElementById('pw');f.type=f.type==='password'?'text':'password';this.querySelector('span').textContent=f.type==='password'?'visibility':'visibility_off'">
              <span class="material-symbols-outlined">visibility</span>
            </button>
          </div>
        </div>

        <div class="remember-row">
          <input type="checkbox" id="remember">
          <label for="remember">Remember this device</label>
        </div>

        <button type="submit" class="btn-signin">Sign In</button>

      </form>

      <div class="card-footer">
        <div class="card-footer-row">
          <span class="material-symbols-outlined">info</span>
          <p>
            <strong>New to the platform?</strong>
            <span><a href="signup.php">Register your pawnshop</a> to apply for access.</span>
          </p>
        </div>
      </div>

    </div><!-- /card -->

    <!-- LEGAL LINKS -->
    <div class="legal-links">
      <a href="#">Privacy Policy</a>
      <a href="#">Terms of Service</a>
      <a href="#">Support</a>
    </div>

  </div>
</main>

<!-- SYSTEM BADGE -->
<div class="system-badge">
  <span class="badge-dot"></span>
  <span class="badge-text">System Online</span>
</div>

</body>
</html>