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

            if (!$chk)                            $error = 'Username not found.';
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
<script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet"/>
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet"/>
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: 'Inter', sans-serif; overflow: hidden; }
.material-symbols-outlined { font-variation-settings: 'FILL' 0, 'wght' 400, 'GRAD' 0, 'opsz' 24; }
.field-input {
    width: 100%;
    height: 56px;
    padding: 0 20px;
    background: rgba(226,226,228,0.45);
    border: none;
    border-radius: 12px;
    font-family: 'Inter', sans-serif;
    font-size: 0.92rem;
    color: #1a1c1d;
    outline: none;
    transition: all 0.2s;
}
.field-input:focus {
    background: #fff;
    box-shadow: 0 0 0 2px rgba(0,35,111,0.2);
}
.field-input::placeholder { color: #9ea0a8; }
</style>
</head>
<body class="bg-gray-100 text-gray-900">

<!-- BACKGROUND -->
<div class="fixed inset-0 z-0">
  <img class="w-full h-full object-cover"
    src="https://lh3.googleusercontent.com/aida-public/AB6AXuA5_TIJZ7gPS7TJbOhT3mlXkiGTUvK43P5Q8JmtLOQPLEnW8MKgHVTqL5442kQYiDWY2QRo_pnnF1X6G1YizmlZKqXAbLflQBQVaeL_HbIOwxlElZ3gGQ_OPy-TLgjSmD_GDGGtrS4x6rwlP9ctf92uKuFXsjFkkcdS5LHGxcoOTSJskN5b3c9_KXjKPDKJjJgRT9FPsydoU9KGPFwWC1sGixVh4AqRUtT9Yfj6XN0cZG7WRmxqeAScFuFEr6EXTcva1GIdW5wthlI"
    alt="PawnHub background"/>
  <div class="absolute inset-0" style="background:rgba(30,58,138,0.18);backdrop-filter:brightness(0.72);"></div>
</div>

<!-- NAV -->
<header class="fixed top-0 w-full z-50 px-8 h-20 flex justify-between items-center" style="background:rgba(255,255,255,0.08);backdrop-filter:blur(14px);">
  <a href="index.php" class="flex items-center gap-2">
    <div class="w-8 h-8 bg-gradient-to-br from-blue-500 to-purple-600 rounded-lg flex items-center justify-center">
      <svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2.5" style="width:16px;height:16px;"><rect x="3" y="9" width="18" height="12"/><polyline points="3 9 12 3 21 9"/></svg>
    </div>
    <span class="text-xl font-bold tracking-tight text-white">PawnHub</span>
  </a>
  <div class="hidden md:flex items-center gap-8">
    <a href="#" class="text-white/70 hover:text-white transition-colors text-sm font-medium">Platform Status</a>
    <a href="#" class="text-white/70 hover:text-white transition-colors text-sm font-medium">Security</a>
  </div>
</header>

<!-- MAIN -->
<main class="relative z-10 min-h-screen flex items-center px-6 md:px-24 py-20">
  <div class="w-full max-w-md">

    <!-- CARD -->
    <div style="background:rgba(255,255,255,0.82);backdrop-filter:blur(28px);-webkit-backdrop-filter:blur(28px);padding:40px;border-radius:32px;box-shadow:0 20px 40px rgba(30,58,138,0.14);border:1px solid rgba(255,255,255,0.25);">

      <!-- Logo / Icon -->
      <div class="mb-10">
        <div class="mb-6">
          <span class="material-symbols-outlined" style="font-size:2.5rem;color:#1e3a8a;font-variation-settings:'FILL' 1;">diamond</span>
        </div>
        <h1 style="font-size:2.6rem;font-weight:800;color:#1a1c1d;line-height:1.1;margin-bottom:8px;">Welcome Back</h1>
        <p style="font-size:0.85rem;color:#64748b;line-height:1.6;">Enter your credentials to access the PawnHub platform.</p>
      </div>

      <!-- ERROR -->
      <?php if ($error): ?>
      <div style="background:#fef2f2;border:1px solid #fecaca;border-radius:10px;padding:11px 14px;font-size:0.82rem;color:#dc2626;margin-bottom:20px;display:flex;align-items:center;gap:8px;">
        <span class="material-symbols-outlined" style="font-size:16px;flex-shrink:0;">error</span>
        <?= htmlspecialchars($error) ?>
      </div>
      <?php endif; ?>

      <!-- FORM -->
      <form method="POST" action="" style="display:flex;flex-direction:column;gap:22px;">

        <div>
          <label style="display:block;font-size:0.72rem;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;color:#64748b;margin-bottom:7px;margin-left:4px;">Username</label>
          <input type="text" name="username" class="field-input"
            placeholder="Enter your username"
            value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" required>
        </div>

        <div>
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:7px;margin-left:4px;">
            <label style="font-size:0.72rem;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;color:#64748b;">Password</label>
          </div>
          <div style="position:relative;">
            <input type="password" name="password" id="pw" class="field-input"
              placeholder="••••••••" required style="padding-right:50px;">
            <button type="button"
              onclick="const f=document.getElementById('pw');f.type=f.type==='password'?'text':'password';this.querySelector('span').textContent=f.type==='password'?'visibility':'visibility_off'"
              style="position:absolute;right:14px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:#94a3b8;display:flex;align-items:center;">
              <span class="material-symbols-outlined" style="font-size:20px;">visibility</span>
            </button>
          </div>
        </div>

        <div style="display:flex;align-items:center;gap:10px;margin-left:4px;">
          <input type="checkbox" id="remember" style="width:18px;height:18px;border-radius:5px;accent-color:#1e3a8a;cursor:pointer;">
          <label for="remember" style="font-size:0.85rem;color:#64748b;cursor:pointer;user-select:none;">Remember this device</label>
        </div>

        <button type="submit"
          style="width:100%;height:56px;background:linear-gradient(135deg,#1e3a8a,#2563eb);color:#fff;border:none;border-radius:12px;font-family:'Inter',sans-serif;font-size:0.96rem;font-weight:700;cursor:pointer;box-shadow:0 6px 20px rgba(30,58,138,0.25);transition:all 0.2s;"
          onmouseover="this.style.transform='scale(1.02)'" onmouseout="this.style.transform='scale(1)'">
          Sign In
        </button>

      </form>

      <!-- FOOTER -->
      <div style="margin-top:36px;padding-top:24px;border-top:1px solid rgba(0,0,0,0.07);">
        <div style="display:flex;align-items:flex-start;gap:10px;margin-bottom:16px;">
          <span class="material-symbols-outlined" style="font-size:20px;color:#94a3b8;margin-top:1px;">info</span>
          <div>
            <p style="font-size:0.78rem;color:#64748b;font-weight:600;">New to the platform?</p>
            <p style="font-size:0.78rem;color:#94a3b8;line-height:1.5;">
              <a href="signup.php" style="color:#1e3a8a;font-weight:700;text-decoration:none;">Register your pawnshop</a> to apply for access.
            </p>
          </div>
        </div>
      </div>

    </div>

    <!-- LEGAL LINKS -->
    <div style="margin-top:24px;display:flex;gap:24px;padding-left:8px;">
      <a href="#" style="font-size:0.75rem;color:rgba(255,255,255,0.55);text-decoration:none;transition:color 0.2s;" onmouseover="this.style.color='#fff'" onmouseout="this.style.color='rgba(255,255,255,0.55)'">Privacy Policy</a>
      <a href="#" style="font-size:0.75rem;color:rgba(255,255,255,0.55);text-decoration:none;transition:color 0.2s;" onmouseover="this.style.color='#fff'" onmouseout="this.style.color='rgba(255,255,255,0.55)'">Terms of Service</a>
      <a href="#" style="font-size:0.75rem;color:rgba(255,255,255,0.55);text-decoration:none;transition:color 0.2s;" onmouseover="this.style.color='#fff'" onmouseout="this.style.color='rgba(255,255,255,0.55)'">Support</a>
    </div>

  </div>
</main>

<!-- BOTTOM RIGHT BADGE -->
<div class="fixed bottom-8 right-8 z-10 hidden md:block">
  <div style="display:flex;align-items:center;gap:10px;background:rgba(255,255,255,0.1);backdrop-filter:blur(12px);padding:10px 20px;border-radius:100px;border:1px solid rgba(255,255,255,0.12);">
    <span style="width:8px;height:8px;border-radius:50%;background:#34d399;display:inline-block;animation:pulse 2s infinite;"></span>
    <span style="font-size:0.7rem;font-weight:600;color:rgba(255,255,255,0.85);text-transform:uppercase;letter-spacing:0.1em;">System Online</span>
  </div>
</div>

<style>
@keyframes pulse {
  0%, 100% { opacity: 1; }
  50% { opacity: 0.4; }
}
</style>

</body>
</html>