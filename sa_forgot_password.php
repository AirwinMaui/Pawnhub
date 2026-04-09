<?php
/**
 * sa_forgot_password.php — PawnHub Super Admin Forgot Password
 *
 * Flow:
 *   1. SA enters their username or email
 *   2. System looks up the user (must be role=super_admin, status=approved)
 *   3. Generates a secure token, stores in password_resets table (expires 1 hour)
 *   4. Sends reset email via sendSuperAdminPasswordReset()
 *   5. Shows generic success message (no user enumeration)
 *   6. SA clicks link in email → sa_setup_password.php?token=...
 *   7. Sets new password → redirected to login.php
 */

require_once __DIR__ . '/session_helper.php';
pawnhub_session_start('');
require 'db.php';
require 'mailer.php';

$step  = 'form'; // form | sent
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $identifier = trim($_POST['identifier'] ?? '');

    if ($identifier === '') {
        $error = 'Please enter your username or email address.';
    } else {
        // Look up super_admin user only — don't reveal if user exists
        $stmt = $pdo->prepare("
            SELECT id, fullname, username, email
            FROM users
            WHERE (username = ? OR email = ?)
              AND role = 'super_admin'
              AND status = 'approved'
              AND is_suspended = 0
            LIMIT 1
        ");
        $stmt->execute([$identifier, $identifier]);
        $user = $stmt->fetch();

        if ($user) {
            // Invalidate any existing unused tokens for this user
            $pdo->prepare("UPDATE password_resets SET used = 1 WHERE user_id = ? AND used = 0")
                ->execute([$user['id']]);

            // Generate secure token
            $token      = bin2hex(random_bytes(32));
            $expires_at = date('Y-m-d H:i:s', strtotime('+1 hour'));

            // Store in password_resets
            $pdo->prepare("
                INSERT INTO password_resets (user_id, token, expires_at, used, created_at)
                VALUES (?, ?, ?, 0, NOW())
            ")->execute([$user['id'], $token, $expires_at]);

            // Send email
            sendSuperAdminPasswordReset(
                $user['email'],
                $user['fullname'],
                $user['username'],
                $token
            );

            // Audit log (best effort)
            try {
                $pdo->prepare("
                    INSERT INTO audit_logs (tenant_id, actor_user_id, actor_username, actor_role, action, entity_type, entity_id, message, ip_address, created_at)
                    VALUES (NULL, ?, ?, 'super_admin', 'SA_PASSWORD_RESET_REQUEST', 'user', ?, 'Super Admin requested a password reset.', ?, NOW())
                ")->execute([$user['id'], $user['username'], $user['id'], $_SERVER['REMOTE_ADDR'] ?? '::1']);
            } catch (Throwable $e) {}
        }

        // Always show "sent" — never reveal if email/username exists
        $step = 'sent';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>PawnHub — Forgot Password</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet"/>
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet"/>
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
html { width: 100%; height: 100%; font-family: 'Inter', sans-serif; }
body { width: 100%; min-height: 100vh; font-family: 'Inter', sans-serif; overflow-x: hidden; }
.bg { position: fixed; inset: 0; z-index: 0; }
.bg img { width: 100%; height: 100%; object-fit: cover; display: block; }
.bg-ov { position: absolute; inset: 0; background: rgba(10,20,60,0.48); }
.nav {
  position: fixed; top: 0; left: 0; right: 0; z-index: 50; height: 64px;
  display: flex; align-items: center; justify-content: space-between; padding: 0 36px;
  background: rgba(255,255,255,0.07); backdrop-filter: blur(14px); -webkit-backdrop-filter: blur(14px);
  border-bottom: 1px solid rgba(255,255,255,0.08);
}
.nav-logo { display: flex; align-items: center; gap: 9px; text-decoration: none; }
.nav-logo-icon { width: 32px; height: 32px; background: linear-gradient(135deg,#3b82f6,#8b5cf6); border-radius: 9px; display: flex; align-items: center; justify-content: center; }
.nav-logo-text { font-size: 1.15rem; font-weight: 800; color: #fff; letter-spacing: -0.02em; }
.page { position: relative; z-index: 10; width: 100%; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding-top: 84px; padding-bottom: 24px; }
.panel { width: 460px; min-width: 460px; display: flex; flex-direction: column; align-items: center; padding: 0 40px; }
.card { width: 100%; background: rgba(255,255,255,0.91); backdrop-filter: blur(28px); -webkit-backdrop-filter: blur(28px); border-radius: 22px; padding: 34px 30px 26px; box-shadow: 0 18px 48px rgba(10,20,60,0.20); border: 1px solid rgba(255,255,255,0.26); }
.material-symbols-outlined { font-variation-settings: 'FILL' 1,'wght' 400,'GRAD' 0,'opsz' 24; }
.sa-badge { display: inline-flex; align-items: center; gap: 5px; background: linear-gradient(135deg,#1d4ed8,#7c3aed); color: #fff; font-size: 0.65rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.08em; padding: 3px 10px; border-radius: 100px; margin-bottom: 14px; }
.card-title { font-size: 1.9rem; font-weight: 800; color: #111827; letter-spacing: -0.03em; line-height: 1.1; margin-bottom: 5px; }
.card-sub { font-size: 0.81rem; color: #64748b; line-height: 1.5; margin-bottom: 20px; }
.err { display: flex; align-items: center; gap: 8px; background: #fef2f2; border: 1px solid #fecaca; border-radius: 9px; padding: 9px 12px; font-size: 0.79rem; color: #dc2626; margin-bottom: 16px; }
.err .material-symbols-outlined { font-size: 15px; flex-shrink: 0; }
.form { display: flex; flex-direction: column; gap: 14px; }
.lbl { display: block; font-size: 0.67rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.09em; color: #64748b; margin-bottom: 5px; }
.inp { width: 100%; height: 46px; padding: 0 15px; background: rgba(218,218,224,0.38); border: 1.5px solid transparent; border-radius: 10px; font-family: 'Inter', sans-serif; font-size: 0.87rem; color: #111827; outline: none; transition: background .2s, border-color .2s, box-shadow .2s; }
.inp:focus { background: #fff; border-color: rgba(30,58,138,0.28); box-shadow: 0 0 0 3px rgba(30,58,138,0.09); }
.inp::placeholder { color: #b0b8c5; }
.btn { width: 100%; height: 46px; background: linear-gradient(135deg,#1e3a8a,#2563eb); color: #fff; border: none; border-radius: 10px; font-family: 'Inter', sans-serif; font-size: 0.91rem; font-weight: 700; cursor: pointer; box-shadow: 0 5px 16px rgba(30,58,138,0.28); transition: transform .15s, box-shadow .15s; }
.btn:hover { transform: translateY(-1px); box-shadow: 0 7px 22px rgba(30,58,138,0.36); }
.btn:active { transform: translateY(0); }
.back-link { text-align: center; margin-top: 14px; }
.back-link a { font-size: 0.79rem; color: #2563eb; font-weight: 600; text-decoration: none; display: inline-flex; align-items: center; gap: 4px; transition: color .2s; }
.back-link a:hover { color: #1d4ed8; text-decoration: underline; }
.back-link .material-symbols-outlined { font-size: 14px; font-variation-settings: 'FILL' 0,'wght' 400,'GRAD' 0,'opsz' 24; }
/* Sent state */
.sent-icon { font-size: 3.2rem; text-align: center; margin-bottom: 12px; }
.sent-title { font-size: 1.75rem; font-weight: 800; color: #111827; letter-spacing: -0.03em; margin-bottom: 8px; }
.sent-sub { font-size: 0.83rem; color: #64748b; line-height: 1.65; margin-bottom: 22px; }
.info-box { background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 10px; padding: 13px 16px; font-size: 0.8rem; color: #1d4ed8; line-height: 1.6; margin-bottom: 20px; }
.btn-back { display: flex; align-items: center; justify-content: center; gap: 6px; text-decoration: none; width: 100%; height: 46px; background: linear-gradient(135deg,#1e3a8a,#2563eb); color: #fff; border-radius: 10px; font-size: 0.91rem; font-weight: 700; box-shadow: 0 5px 16px rgba(30,58,138,0.28); transition: transform .15s, box-shadow .15s; }
.btn-back:hover { transform: translateY(-1px); box-shadow: 0 7px 22px rgba(30,58,138,0.36); }
.legal { margin-top: 12px; display: flex; gap: 16px; padding-left: 2px; }
.legal a { font-size: 0.68rem; color: rgba(255,255,255,0.44); text-decoration: none; transition: color .2s; }
.legal a:hover { color: #fff; }
@media (max-width: 560px) {
  .panel { width: 100%; min-width: unset; padding: 0 16px; }
  .card { padding: 26px 20px 22px; }
  .card-title, .sent-title { font-size: 1.6rem; }
}
</style>
</head>
<body>

<div class="bg">
  <img src="https://lh3.googleusercontent.com/aida-public/AB6AXuA5_TIJZ7gPS7TJbOhT3mlXkiGTUvK43P5Q8JmtLOQPLEnW8MKgHVTqL5442kQYiDWY2QRo_pnnF1X6G1YizmlZKqXAbLflQBQVaeL_HbIOwxlElZ3gGQ_OPy-TLgjSmD_GDGGtrS4x6rwlP9ctf92uKuFXsjFkkcdS5LHGxcoOTSJskN5b3c9_KXjKPDKJjJgRT9FPsydoU9KGPFwWC1sGixVh4AqRUtT9Yfj6XN0cZG7WRmxqeAScFuFEr6EXTcva1GIdW5wthlI" alt="PawnHub background"/>
  <div class="bg-ov"></div>
</div>

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
</header>

<main class="page">
  <div class="panel">
    <div class="card">

      <?php if ($step === 'sent'): ?>
      <!-- ══ EMAIL SENT STATE ══════════════════════════════════ -->
      <div class="sent-icon">📬</div>
      <div class="sent-title">Check Your Email</div>
      <p class="sent-sub">
        If a Super Admin account exists with that username or email, we've sent a password reset link to the registered email address.
      </p>
      <div class="info-box">
        🔒 The reset link expires in <strong>1 hour</strong>.<br>
        Check your spam folder if you don't see it within a few minutes.
      </div>
      <a href="login.php" class="btn-back">
        <span class="material-symbols-outlined" style="font-size:17px;font-variation-settings:'FILL' 0,'wght' 400,'GRAD' 0,'opsz' 24;">arrow_back</span>
        Back to Login
      </a>

      <?php else: ?>
      <!-- ══ FORM STATE ════════════════════════════════════════ -->
      <div style="margin-bottom:12px;">
        <span class="material-symbols-outlined" style="font-size:2rem;color:#1e3a8a;">lock_reset</span>
      </div>

      <div class="sa-badge">
        <span class="material-symbols-outlined" style="font-size:11px;">verified_user</span>
        Super Admin Portal
      </div>

      <h1 class="card-title">Forgot Password?</h1>
      <p class="card-sub">Enter your Super Admin username or email address and we'll send you a link to reset your password.</p>

      <?php if ($error): ?>
      <div class="err">
        <span class="material-symbols-outlined">error</span>
        <?= htmlspecialchars($error) ?>
      </div>
      <?php endif; ?>

      <form method="POST" action="" class="form">
        <div>
          <label class="lbl">Username or Email Address</label>
          <input
            type="text"
            name="identifier"
            class="inp"
            placeholder="Enter your username or email"
            value="<?= htmlspecialchars($_POST['identifier'] ?? '') ?>"
            autocomplete="username"
            required>
        </div>
        <button type="submit" class="btn">Send Reset Link →</button>
      </form>

      <div class="back-link">
        <a href="login.php">
          <span class="material-symbols-outlined">arrow_back</span>
          Back to Login
        </a>
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

</body>
</html>