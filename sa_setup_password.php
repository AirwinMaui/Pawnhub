<?php
/**
 * sa_setup_password.php — PawnHub Super Admin Password Setup
 *
 * Flow:
 *   1. Newly invited Super Admin receives email with ?token=...
 *   2. They open this page, see their name/username, set a password
 *   3. Password is saved, user status set to 'approved', token marked used
 *   4. Redirect to login.php with success message
 *
 * Security:
 *   - Token is single-use and expires in 24 hours
 *   - Only works for users with role='super_admin' AND status='pending'
 *   - After use, token is invalidated and cannot be reused
 */

require_once __DIR__ . '/session_helper.php';
pawnhub_session_start('');
require 'db.php';

$token      = trim($_GET['token'] ?? '');
$step       = 'setup'; // setup | done | invalid
$error      = '';
$setup_user = null;

// ── Validate token ────────────────────────────────────────────
if (!$token) {
    $step = 'invalid';
} else {
    // Check super_admin_invitations table first, fallback to password_resets
    $setup_user = null;

    // Try super_admin_invitations table
    try {
        $stmt = $pdo->prepare("
            SELECT sai.*, u.fullname, u.username, u.email, u.role, u.status
            FROM super_admin_invitations sai
            JOIN users u ON sai.user_id = u.id
            WHERE sai.token = ?
              AND sai.used = 0
              AND sai.expires_at > NOW()
              AND u.role = 'super_admin'
            LIMIT 1
        ");
        $stmt->execute([$token]);
        $row = $stmt->fetch();
        if ($row) {
            $setup_user = $row;
            $setup_user['_source'] = 'sa_invitations';
        }
    } catch (PDOException $e) {
        // Table may not exist yet — fall through to password_resets
    }

    // Fallback: try password_resets table
    if (!$setup_user) {
        try {
            $stmt = $pdo->prepare("
                SELECT pr.*, u.fullname, u.username, u.email, u.role, u.status
                FROM password_resets pr
                JOIN users u ON pr.user_id = u.id
                WHERE pr.token = ?
                  AND pr.used = 0
                  AND pr.expires_at > NOW()
                  AND u.role = 'super_admin'
                LIMIT 1
            ");
            $stmt->execute([$token]);
            $row = $stmt->fetch();
            if ($row) {
                $setup_user = $row;
                $setup_user['_source'] = 'password_resets';
            }
        } catch (PDOException $e) {}
    }

    if (!$setup_user) {
        $step = 'invalid';
    }
}

// ── Handle password submission ────────────────────────────────
if ($step === 'setup' && $setup_user && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = trim($_POST['password'] ?? '');
    $confirm  = trim($_POST['confirm']  ?? '');

    if (!$password || !$confirm) {
        $error = 'Please fill in both password fields.';
    } elseif (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters.';
    } elseif ($password !== $confirm) {
        $error = 'Passwords do not match.';
    } else {
        $hashed = password_hash($password, PASSWORD_BCRYPT);

        try {
            $pdo->beginTransaction();

            // Set password and activate account
            $pdo->prepare("UPDATE users SET password = ?, status = 'approved' WHERE id = ? AND role = 'super_admin'")
                ->execute([$hashed, $setup_user['user_id']]);

            // Mark token as used
            if ($setup_user['_source'] === 'sa_invitations') {
                $pdo->prepare("UPDATE super_admin_invitations SET used = 1, used_at = NOW() WHERE token = ?")
                    ->execute([$token]);
            } else {
                $pdo->prepare("UPDATE password_resets SET used = 1 WHERE token = ?")
                    ->execute([$token]);
            }

            // Audit log
            try {
                $pdo->prepare("INSERT INTO audit_logs (tenant_id, actor_user_id, actor_username, actor_role, action, entity_type, entity_id, message, ip_address, created_at)
                    VALUES (NULL, ?, ?, 'super_admin', 'SA_SETUP_PASSWORD', 'user', ?, 'New Super Admin set up their password and activated their account.', ?, NOW())")
                    ->execute([$setup_user['user_id'], $setup_user['username'], $setup_user['user_id'], $_SERVER['REMOTE_ADDR'] ?? '::1']);
            } catch (PDOException $e) {}

            $pdo->commit();
            $step = 'done';

        } catch (PDOException $e) {
            $pdo->rollBack();
            $error = 'Something went wrong. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>PawnHub — Super Admin Setup</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet"/>
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet"/>
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
html, body { width: 100%; min-height: 100vh; font-family: 'Inter', sans-serif; }
body {
  min-height: 100vh;
  display: flex;
  align-items: center;
  justify-content: center;
  background: linear-gradient(135deg, #0f172a 0%, #1e3a8a 50%, #0f172a 100%);
  padding: 24px 16px;
}
.card {
  width: 100%;
  max-width: 460px;
  background: rgba(255,255,255,0.95);
  backdrop-filter: blur(28px);
  border-radius: 22px;
  padding: 40px 36px 32px;
  box-shadow: 0 20px 60px rgba(0,0,0,0.35);
  border: 1px solid rgba(255,255,255,0.3);
}
.logo {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-bottom: 24px;
}
.logo-icon {
  width: 38px;
  height: 38px;
  background: linear-gradient(135deg, #3b82f6, #8b5cf6);
  border-radius: 10px;
  display: flex;
  align-items: center;
  justify-content: center;
}
.logo-text {
  font-size: 1.15rem;
  font-weight: 800;
  color: #111827;
  letter-spacing: -0.02em;
}
.sa-badge {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  background: linear-gradient(135deg,#4338ca,#7c3aed);
  color: #fff;
  font-size: 0.64rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.09em;
  padding: 3px 10px;
  border-radius: 100px;
  margin-bottom: 14px;
}
.material-symbols-outlined {
  font-variation-settings: 'FILL' 1,'wght' 400,'GRAD' 0,'opsz' 24;
}
h1 {
  font-size: 1.8rem;
  font-weight: 800;
  color: #111827;
  letter-spacing: -0.03em;
  margin-bottom: 6px;
  line-height: 1.1;
}
.sub {
  font-size: 0.83rem;
  color: #64748b;
  line-height: 1.6;
  margin-bottom: 24px;
}
.user-box {
  background: linear-gradient(135deg,#eff6ff,#f3e8ff);
  border: 1px solid #c4b5fd;
  border-radius: 12px;
  padding: 14px 16px;
  margin-bottom: 22px;
  display: flex;
  align-items: center;
  gap: 12px;
}
.user-box-icon {
  width: 40px;
  height: 40px;
  background: linear-gradient(135deg,#4338ca,#7c3aed);
  border-radius: 10px;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}
.user-box-name {
  font-size: 0.94rem;
  font-weight: 700;
  color: #1e1b4b;
}
.user-box-username {
  font-size: 0.78rem;
  color: #6d28d9;
  font-family: monospace;
}
.user-box-email {
  font-size: 0.74rem;
  color: #6b7280;
  margin-top: 2px;
}
.err {
  display: flex;
  align-items: flex-start;
  gap: 8px;
  background: #fef2f2;
  border: 1px solid #fecaca;
  border-radius: 9px;
  padding: 10px 12px;
  font-size: 0.8rem;
  color: #dc2626;
  margin-bottom: 16px;
  line-height: 1.5;
}
.form { display: flex; flex-direction: column; gap: 14px; }
.lbl {
  display: block;
  font-size: 0.67rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.09em;
  color: #64748b;
  margin-bottom: 5px;
}
.inp {
  width: 100%;
  height: 46px;
  padding: 0 42px 0 15px;
  background: rgba(218,218,224,0.38);
  border: 1.5px solid transparent;
  border-radius: 10px;
  font-family: 'Inter', sans-serif;
  font-size: 0.87rem;
  color: #111827;
  outline: none;
  transition: background .2s, border-color .2s, box-shadow .2s;
}
.inp:focus {
  background: #fff;
  border-color: rgba(109,40,217,0.35);
  box-shadow: 0 0 0 3px rgba(109,40,217,0.1);
}
.inp::placeholder { color: #b0b8c5; }
.pw-wrap { position: relative; }
.pw-btn {
  position: absolute;
  right: 11px;
  top: 50%;
  transform: translateY(-50%);
  background: none;
  border: none;
  cursor: pointer;
  color: #94a3b8;
  display: flex;
  align-items: center;
  padding: 0;
  transition: color .2s;
}
.pw-btn:hover { color: #475569; }
.pw-btn .material-symbols-outlined {
  font-size: 18px;
  font-variation-settings: 'FILL' 0,'wght' 400,'GRAD' 0,'opsz' 24;
}
.strength { height: 4px; border-radius: 100px; margin-top: 6px; background: #e5e7eb; overflow: hidden; }
.strength-bar { height: 100%; border-radius: 100px; transition: width .3s, background .3s; width: 0; }
.strength-lbl { font-size: 0.68rem; margin-top: 4px; color: #9ca3af; }
.btn {
  width: 100%;
  height: 48px;
  background: linear-gradient(135deg, #4338ca, #7c3aed);
  color: #fff;
  border: none;
  border-radius: 10px;
  font-family: 'Inter', sans-serif;
  font-size: 0.92rem;
  font-weight: 700;
  cursor: pointer;
  box-shadow: 0 5px 16px rgba(109,40,217,0.35);
  transition: transform .15s, box-shadow .15s;
  margin-top: 4px;
}
.btn:hover { transform: translateY(-1px); box-shadow: 0 7px 22px rgba(109,40,217,0.45); }
.btn:active { transform: translateY(0); }
/* Done / Invalid states */
.done-icon { font-size: 3.5rem; text-align: center; margin-bottom: 14px; }
.done-title { font-size: 1.7rem; font-weight: 800; color: #111827; letter-spacing: -0.03em; margin-bottom: 8px; }
.done-sub { font-size: 0.85rem; color: #64748b; line-height: 1.6; margin-bottom: 24px; }
.btn-go {
  display: flex;
  align-items: center;
  justify-content: center;
  text-decoration: none;
  width: 100%;
  height: 48px;
  background: linear-gradient(135deg, #1e3a8a, #2563eb);
  color: #fff;
  border-radius: 10px;
  font-size: 0.92rem;
  font-weight: 700;
  box-shadow: 0 5px 16px rgba(30,58,138,0.3);
  transition: transform .15s, box-shadow .15s;
}
.btn-go:hover { transform: translateY(-1px); box-shadow: 0 7px 22px rgba(30,58,138,0.4); }
.info-box {
  background: #eff6ff;
  border: 1px solid #bfdbfe;
  border-radius: 10px;
  padding: 12px 15px;
  font-size: 0.8rem;
  color: #1d4ed8;
  line-height: 1.6;
  margin-bottom: 20px;
}
</style>
</head>
<body>

<div class="card">

  <?php if ($step === 'done'): ?>
  <!-- ══ SUCCESS ══════════════════════════════════════════════ -->
  <div class="logo">
    <div class="logo-icon">
      <svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2.5" style="width:16px;height:16px;">
        <rect x="3" y="9" width="18" height="12"/>
        <polyline points="3 9 12 3 21 9"/>
      </svg>
    </div>
    <span class="logo-text">PawnHub</span>
  </div>

  <div class="done-icon">🎉</div>
  <div class="done-title">Password Set!</div>
  <p class="done-sub">
    Your Super Admin account is now <strong>active</strong>.<br>
    You can now log in using your username and the password you just created.
  </p>
  <a href="login.php" class="btn-go">
    <span class="material-symbols-outlined" style="font-size:18px;margin-right:6px;">login</span>
    Go to Super Admin Login
  </a>

  <?php elseif ($step === 'invalid'): ?>
  <!-- ══ INVALID / EXPIRED TOKEN ══════════════════════════════ -->
  <div class="logo">
    <div class="logo-icon">
      <svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2.5" style="width:16px;height:16px;">
        <rect x="3" y="9" width="18" height="12"/>
        <polyline points="3 9 12 3 21 9"/>
      </svg>
    </div>
    <span class="logo-text">PawnHub</span>
  </div>

  <div class="done-icon">⛔</div>
  <div class="done-title">Link Invalid</div>
  <p class="done-sub">
    This setup link is <strong>invalid or has expired</strong>.<br>
    Links are only valid for <strong>24 hours</strong> after they are sent.<br><br>
    Please ask the Super Admin who invited you to send a new invitation.
  </p>
  <a href="login.php" class="btn-go" style="background:linear-gradient(135deg,#475569,#64748b);box-shadow:0 5px 16px rgba(71,85,105,.3);">
    Back to Login
  </a>

  <?php else: ?>
  <!-- ══ SETUP FORM ════════════════════════════════════════════ -->
  <div class="logo">
    <div class="logo-icon">
      <svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2.5" style="width:16px;height:16px;">
        <rect x="3" y="9" width="18" height="12"/>
        <polyline points="3 9 12 3 21 9"/>
      </svg>
    </div>
    <span class="logo-text">PawnHub</span>
  </div>

  <div class="sa-badge">
    <span class="material-symbols-outlined" style="font-size:11px;">verified_user</span>
    Super Admin Setup
  </div>

  <h1><?= isset($setup_user['_source']) && $setup_user['_source'] === 'password_resets' && ($setup_user['status'] ?? '') === 'approved' ? 'Reset Your Password' : 'Set Your Password' ?></h1>
  <p class="sub"><?= isset($setup_user['_source']) && $setup_user['_source'] === 'password_resets' && ($setup_user['status'] ?? '') === 'approved' ? 'Enter a new password for your Super Admin account.' : 'Welcome! Create a strong password to activate your Super Admin account.' ?></p>

  <!-- User identity box -->
  <div class="user-box">
    <div class="user-box-icon">
      <span class="material-symbols-outlined" style="color:#fff;font-size:20px;">admin_panel_settings</span>
    </div>
    <div>
      <div class="user-box-name"><?= htmlspecialchars($setup_user['fullname']) ?></div>
      <div class="user-box-username">@<?= htmlspecialchars($setup_user['username']) ?></div>
      <div class="user-box-email"><?= htmlspecialchars($setup_user['email']) ?></div>
    </div>
  </div>

  <div class="info-box">
    🔒 Choose a strong password that you haven't used before.<br>
    After setting your password, you'll be directed to the login page.
  </div>

  <?php if ($error): ?>
  <div class="err">
    <span class="material-symbols-outlined" style="font-size:16px;flex-shrink:0;font-variation-settings:'FILL' 1,'wght' 400,'GRAD' 0,'opsz' 24;">error</span>
    <?= htmlspecialchars($error) ?>
  </div>
  <?php endif; ?>

  <form method="POST" class="form">
    <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">

    <div>
      <label class="lbl">New Password <span style="font-size:.63rem;color:#94a3b8;">(min. 8 characters)</span></label>
      <div class="pw-wrap">
        <input type="password" name="password" id="pw1" class="inp" placeholder="Create a strong password" required oninput="checkStrength(this.value)">
        <button type="button" class="pw-btn" onclick="togglePw('pw1',this)">
          <span class="material-symbols-outlined">visibility</span>
        </button>
      </div>
      <div class="strength"><div class="strength-bar" id="str-bar"></div></div>
      <div class="strength-lbl" id="str-lbl"></div>
    </div>

    <div>
      <label class="lbl">Confirm Password</label>
      <div class="pw-wrap">
        <input type="password" name="confirm" id="pw2" class="inp" placeholder="Re-enter your password" required>
        <button type="button" class="pw-btn" onclick="togglePw('pw2',this)">
          <span class="material-symbols-outlined">visibility</span>
        </button>
      </div>
    </div>

    <button type="submit" class="btn"><?= isset($setup_user['_source']) && $setup_user['_source'] === 'password_resets' && ($setup_user['status'] ?? '') === 'approved' ? '🔑 Reset My Password' : '🛡️ Activate My Account' ?></button>
  </form>
  <?php endif; ?>

</div>

<script>
function togglePw(id, btn) {
  const f = document.getElementById(id);
  f.type = f.type === 'password' ? 'text' : 'password';
  btn.querySelector('span').textContent = f.type === 'password' ? 'visibility' : 'visibility_off';
}
function checkStrength(pw) {
  const bar = document.getElementById('str-bar');
  const lbl = document.getElementById('str-lbl');
  if (!bar) return;
  let score = 0;
  if (pw.length >= 8)          score++;
  if (pw.length >= 12)         score++;
  if (/[A-Z]/.test(pw))        score++;
  if (/[0-9]/.test(pw))        score++;
  if (/[^A-Za-z0-9]/.test(pw)) score++;
  const cfg = [
    ['0%',   '#e5e7eb', ''],
    ['25%',  '#ef4444', 'Weak'],
    ['50%',  '#f97316', 'Fair'],
    ['75%',  '#eab308', 'Good'],
    ['100%', '#22c55e', 'Strong'],
    ['100%', '#16a34a', 'Very Strong'],
  ];
  const c = cfg[score] ?? cfg[0];
  bar.style.width = c[0];
  bar.style.background = c[1];
  lbl.textContent = c[2];
  lbl.style.color = c[1];
}
</script>
</body>
</html>