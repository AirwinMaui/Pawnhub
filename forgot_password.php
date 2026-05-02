<?php
/**
 * forgot_password.php — PawnHub Forgot Password
 * Works for: tenant admin, manager, staff, cashier
 * Flow:
 *   1. User enters username + email → token generated → email sent
 *   2. User clicks link → enters new password → updated in DB
 */

require_once __DIR__ . '/session_helper.php';
pawnhub_session_start('');
require 'db.php';
require 'mailer.php';

$slug    = trim($_GET['slug'] ?? $_POST['slug'] ?? '');
$token   = trim($_GET['token']  ?? '');
$step    = $token ? 'reset' : 'request'; // step 1 = request, step 2 = reset
$success = false;
$error   = '';

// ── Load tenant from slug ─────────────────────────────────────
$tenant = null;
if ($slug) {
    $stmt = $pdo->prepare("SELECT * FROM tenants WHERE slug=? LIMIT 1");
    $stmt->execute([$slug]);
    $tenant = $stmt->fetch();
}

// ── Validate reset token (step 2) ────────────────────────────
$reset_user = null;
if ($step === 'reset' && $token) {
    $stmt = $pdo->prepare("
        SELECT pr.*, u.fullname, u.email, u.role, u.tenant_id
        FROM password_resets pr
        JOIN users u ON pr.user_id = u.id
        WHERE pr.token = ?
          AND pr.used = 0
          AND pr.expires_at > NOW()
        LIMIT 1
    ");
    $stmt->execute([$token]);
    $reset_user = $stmt->fetch();
    if (!$reset_user) {
        $error = 'This reset link is invalid or has already expired. Please request a new one.';
        $step  = 'request';
    }
    // Load tenant from reset user if not from slug
    if ($reset_user && !$tenant) {
        $stmt2 = $pdo->prepare("SELECT * FROM tenants WHERE id=? LIMIT 1");
        $stmt2->execute([$reset_user['tenant_id']]);
        $tenant = $stmt2->fetch();
        $slug   = $tenant['slug'] ?? '';
    }
}

// ── STEP 1 POST — Request reset ───────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_type'] ?? '') === 'request') {
    $email = trim(strtolower($_POST['email'] ?? ''));

    if (!$email) {
        $error = 'Please enter your email address.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } else {
        // Find the user — email only, no username required
        $q = "SELECT u.* FROM users u WHERE u.email = ? AND u.status = 'approved' AND u.is_suspended = 0";
        $params = [$email];

        // If we have a tenant slug, scope to that tenant
        if ($tenant) {
            $q .= " AND u.tenant_id = ?";
            $params[] = $tenant['id'];
        }
        $q .= " LIMIT 1";
        $stmt = $pdo->prepare($q);
        $stmt->execute($params);
        $found_user = $stmt->fetch();

        if ($found_user) {
            // Delete any existing unused tokens for this user
            $pdo->prepare("DELETE FROM password_resets WHERE user_id=?")->execute([$found_user['id']]);

            // Generate token
            $reset_token  = bin2hex(random_bytes(32));
            $expires_at   = date('Y-m-d H:i:s', strtotime('+1 hour'));

            $pdo->prepare("INSERT INTO password_resets (user_id, token, expires_at, used, created_at) VALUES (?,?,?,0,NOW())")
                ->execute([$found_user['id'], $reset_token, $expires_at]);

            // Build reset URL
            $base_url   = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];
            $tenant_slug = $tenant['slug'] ?? '';
            $reset_url  = $base_url . '/forgot_password.php?slug=' . urlencode($tenant_slug) . '&token=' . $reset_token;

            // Send email
            $business = $tenant['business_name'] ?? 'PawnHub';
            $html = '
<!DOCTYPE html><html><body style="font-family:Inter,sans-serif;background:#f8fafc;margin:0;padding:30px;">
<div style="max-width:520px;margin:0 auto;background:#fff;border-radius:16px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,.08);">
  <div style="background:linear-gradient(135deg,#1e3a8a,#2563eb);padding:28px 32px;">
    <div style="font-size:1.3rem;font-weight:800;color:#fff;">🔐 Reset Your Password</div>
    <div style="font-size:.85rem;color:rgba(255,255,255,.7);margin-top:4px;">' . htmlspecialchars($business) . ' — PawnHub</div>
  </div>
  <div style="padding:28px 32px;">
    <p style="font-size:.95rem;color:#374151;margin-bottom:8px;">Hi <strong>' . htmlspecialchars($found_user['fullname']) . '</strong>,</p>
    <p style="font-size:.88rem;color:#6b7280;line-height:1.6;margin-bottom:20px;">
      We received a request to reset your password for your <strong>' . ucfirst($found_user['role']) . '</strong> account.
      Click the button below to set a new password. This link expires in <strong>1 hour</strong>.
    </p>
    <a href="' . $reset_url . '" style="display:inline-block;background:linear-gradient(135deg,#1e3a8a,#2563eb);color:#fff;text-decoration:none;padding:13px 28px;border-radius:10px;font-weight:700;font-size:.9rem;">Reset My Password</a>
    <p style="font-size:.78rem;color:#9ca3af;margin-top:20px;line-height:1.6;">
      If you did not request this, you can safely ignore this email.<br>
      This link will expire on <strong>' . date('F j, Y g:i A', strtotime($expires_at)) . '</strong>.
    </p>
    <hr style="border:none;border-top:1px solid #f1f5f9;margin:20px 0;">
    <p style="font-size:.75rem;color:#d1d5db;">PawnHub · ' . htmlspecialchars($business) . '</p>
  </div>
</div>
</body></html>';

            $sent = sendMail($found_user['email'], $found_user['fullname'], 'PawnHub — Password Reset Request', $html);
            $success = true; // Show success regardless (security best practice)
        } else {
            // Always show success to prevent username/email enumeration
            $success = true;
        }
    }
}

// ── STEP 2 POST — Set new password ───────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_type'] ?? '') === 'reset') {
    $token      = trim($_POST['token']    ?? '');
    $password   = trim($_POST['password'] ?? '');
    $confirm    = trim($_POST['confirm']  ?? '');

    if (!$password || !$confirm) {
        $error = 'Please fill in all fields.';
    } elseif (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters.';
    } elseif ($password !== $confirm) {
        $error = 'Passwords do not match.';
    } else {
        // Re-validate token
        $stmt = $pdo->prepare("
            SELECT pr.*, u.fullname, u.email, u.role, u.tenant_id
            FROM password_resets pr
            JOIN users u ON pr.user_id = u.id
            WHERE pr.token = ?
              AND pr.used = 0
              AND pr.expires_at > NOW()
            LIMIT 1
        ");
        $stmt->execute([$token]);
        $reset_user = $stmt->fetch();

        if (!$reset_user) {
            $error = 'This reset link is invalid or has expired.';
        } else {
            $hash = password_hash($password, PASSWORD_BCRYPT);
            // Update password
            $pdo->prepare("UPDATE users SET password=? WHERE id=?")->execute([$hash, $reset_user['user_id']]);
            // Mark token as used
            $pdo->prepare("UPDATE password_resets SET used=1 WHERE token=?")->execute([$token]);
            // Audit log
            try {
                $pdo->prepare("INSERT INTO audit_logs (tenant_id,actor_user_id,actor_username,actor_role,action,entity_type,entity_id,message,ip_address,created_at) VALUES (?,?,?,?,'PASSWORD_RESET','user',?,?,?,NOW())")
                    ->execute([$reset_user['tenant_id'],$reset_user['user_id'],$reset_user['username'] ?? '',$reset_user['role'],(string)$reset_user['user_id'],'Password reset via email link.',$_SERVER['REMOTE_ADDR']??'::1']);
            } catch (Throwable $e) {}

            // Get slug for redirect
            if (!$tenant) {
                $stmt2 = $pdo->prepare("SELECT * FROM tenants WHERE id=? LIMIT 1");
                $stmt2->execute([$reset_user['tenant_id']]);
                $tenant = $stmt2->fetch();
                $slug   = $tenant['slug'] ?? '';
            }
            $success = true;
            $step    = 'done';
        }
    }
}

// ── Theme ─────────────────────────────────────────────────────
require 'theme_helper.php';
$theme    = $tenant ? getTenantTheme($pdo, $tenant['id']) : [];
$primary  = $theme['primary_color']   ?? '#2563eb';
$secondary= $theme['secondary_color'] ?? '#1e3a8a';
$sys_name = (!empty($theme['system_name']) ? $theme['system_name'] : null) ?? ($tenant['business_name'] ?? 'PawnHub');
$logo_url = $theme['logo_url']        ?? '';
$bg_url   = $theme['bg_image_url']    ?? '';
$login_href = $slug ? '/' . htmlspecialchars($slug) . '?login=1' : '/login.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1.0"/>
<title><?=htmlspecialchars($sys_name)?> — Reset Password</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet"/>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
html,body{width:100%;min-height:100%;font-family:'Inter',sans-serif;}
body{min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px;position:relative;overflow-x:hidden;}
.bg{position:fixed;inset:0;z-index:0;}
.bg img{width:100%;height:100%;object-fit:cover;display:block;}
.bg-ov{position:absolute;inset:0;background:rgba(10,20,60,.52);}
.wrap{position:relative;z-index:10;width:100%;max-width:440px;}
.card{background:rgba(255,255,255,.93);backdrop-filter:blur(28px);border-radius:22px;padding:36px 32px 28px;box-shadow:0 18px 48px rgba(10,20,60,.22);border:1px solid rgba(255,255,255,.28);}
.brand{display:flex;align-items:center;gap:10px;margin-bottom:20px;}
.brand-logo{width:36px;height:36px;border-radius:10px;background:linear-gradient(135deg,<?=$primary?>,<?=$secondary?>);display:flex;align-items:center;justify-content:center;overflow:hidden;flex-shrink:0;}
.brand-logo img{width:100%;height:100%;object-fit:cover;}
.brand-name{font-size:1rem;font-weight:800;color:#111827;letter-spacing:-.02em;}
h1{font-size:1.7rem;font-weight:800;color:#111827;letter-spacing:-.03em;margin-bottom:6px;}
.sub{font-size:.83rem;color:#6b7280;line-height:1.55;margin-bottom:22px;}
.err{display:flex;align-items:flex-start;gap:8px;background:#fef2f2;border:1px solid #fecaca;border-radius:10px;padding:10px 13px;font-size:.8rem;color:#dc2626;margin-bottom:16px;line-height:1.5;}
.suc{display:flex;align-items:flex-start;gap:8px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px;padding:10px 13px;font-size:.8rem;color:#15803d;margin-bottom:16px;line-height:1.5;}
.lbl{display:block;font-size:.67rem;font-weight:700;text-transform:uppercase;letter-spacing:.09em;color:#6b7280;margin-bottom:5px;}
.inp{width:100%;height:46px;padding:0 14px;background:rgba(218,218,224,.35);border:1.5px solid transparent;border-radius:10px;font-family:'Inter',sans-serif;font-size:.87rem;color:#111827;outline:none;transition:border .2s,box-shadow .2s;}
.inp:focus{background:#fff;border-color:<?=$primary?>;box-shadow:0 0 0 3px <?=$primary?>22;}
.inp::placeholder{color:#b0b8c5;}
.pw-wrap{position:relative;}
.pw-wrap .inp{padding-right:42px;}
.pw-btn{position:absolute;right:11px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:#94a3b8;font-size:18px;display:flex;align-items:center;}
.fgroup{margin-bottom:14px;}
.btn{width:100%;height:46px;background:linear-gradient(135deg,<?=$secondary?>,<?=$primary?>);color:#fff;border:none;border-radius:10px;font-family:'Inter',sans-serif;font-size:.91rem;font-weight:700;cursor:pointer;box-shadow:0 5px 16px <?=$primary?>44;transition:transform .15s,box-shadow .15s;margin-top:4px;}
.btn:hover{transform:translateY(-1px);box-shadow:0 7px 22px <?=$primary?>55;}
.back{display:block;text-align:center;margin-top:16px;font-size:.8rem;color:#6b7280;text-decoration:none;}
.back:hover{color:#111827;}
.back span{color:<?=$primary?>;font-weight:600;}
.done-icon{font-size:3rem;text-align:center;margin-bottom:12px;}
.strength{height:4px;border-radius:100px;margin-top:6px;background:#e5e7eb;overflow:hidden;}
.strength-bar{height:100%;border-radius:100px;transition:width .3s,background .3s;width:0;}
.strength-lbl{font-size:.68rem;margin-top:4px;color:#9ca3af;}
</style>
</head>
<body>

<div class="bg">
  <?php if($bg_url):?>
  <img src="<?=htmlspecialchars($bg_url)?>" alt=""/>
  <?php else:?>
  <img src="https://lh3.googleusercontent.com/aida-public/AB6AXuA5_TIJZ7gPS7TJbOhT3mlXkiGTUvK43P5Q8JmtLOQPLEnW8MKgHVTqL5442kQYiDWY2QRo_pnnF1X6G1YizmlZKqXAbLflQBQVaeL_HbIOwxlElZ3gGQ_OPy-TLgjSmD_GDGGtrS4x6rwlP9ctf92uKuFXsjFkkcdS5LHGxcoOTSJskN5b3c9_KXjKPDKJjJgRT9FPsydoU9KGPFwWC1sGixVh4AqRUtT9Yfj6XN0cZG7WRmxqeAScFuFEr6EXTcva1GIdW5wthlI" alt=""/>
  <?php endif;?>
  <div class="bg-ov"></div>
</div>

<div class="wrap">
  <div class="card">

    <!-- Brand -->
    <div class="brand">
      <div class="brand-logo">
        <?php if($logo_url):?>
        <img src="<?=htmlspecialchars($logo_url)?>" alt=""/>
        <?php else:?>
        <svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2.5" style="width:16px;height:16px;">
          <rect x="3" y="9" width="18" height="12"/><polyline points="3 9 12 3 21 9"/>
        </svg>
        <?php endif;?>
      </div>
      <span class="brand-name"><?=htmlspecialchars($sys_name)?></span>
    </div>

    <?php if($step === 'done'): ?>
    <!-- ── STEP 3: SUCCESS ── -->
    <div class="done-icon">🎉</div>
    <h1>Password Updated!</h1>
    <p class="sub">Your password has been successfully changed. You can now log in with your new password.</p>
    <a href="<?=$login_href?>" class="btn" style="display:flex;align-items:center;justify-content:center;text-decoration:none;">Go to Login Page</a>

    <?php elseif($step === 'reset' && $reset_user && !$success): ?>
    <!-- ── STEP 2: SET NEW PASSWORD ── -->
    <h1>Set New Password</h1>
    <p class="sub">Hi <strong><?=htmlspecialchars($reset_user['fullname'])?></strong>! Enter your new password below.</p>

    <?php if($error):?><div class="err">⚠️ <?=htmlspecialchars($error)?></div><?php endif;?>

    <form method="POST">
      <input type="hidden" name="form_type" value="reset">
      <input type="hidden" name="token" value="<?=htmlspecialchars($token)?>">

      <div class="fgroup">
        <label class="lbl">New Password</label>
        <div class="pw-wrap">
          <input type="password" name="password" id="pw1" class="inp" placeholder="Min. 8 characters" required oninput="checkStrength(this.value)">
          <button type="button" class="pw-btn" onclick="togglePw('pw1',this)">👁</button>
        </div>
        <div class="strength"><div class="strength-bar" id="str-bar"></div></div>
        <div class="strength-lbl" id="str-lbl"></div>
      </div>
      <div class="fgroup">
        <label class="lbl">Confirm New Password</label>
        <div class="pw-wrap">
          <input type="password" name="confirm" id="pw2" class="inp" placeholder="Re-enter password" required>
          <button type="button" class="pw-btn" onclick="togglePw('pw2',this)">👁</button>
        </div>
      </div>
      <button type="submit" class="btn">Update Password</button>
    </form>
    <a href="<?=$login_href?>" class="back">← Back to <span>Login</span></a>

    <?php elseif($success && $step === 'request'): ?>
    <!-- ── STEP 1 SUCCESS ── -->
    <div class="done-icon">📧</div>
    <h1>Check Your Email</h1>
    <p class="sub">
      If your email address matches our records, you'll receive a password reset link shortly.
      The link will expire in <strong>1 hour</strong>.
    </p>
    <p style="font-size:.78rem;color:#9ca3af;margin-bottom:20px;">Didn't receive it? Check your spam folder or try again.</p>
    <a href="<?=$login_href?>" class="btn" style="display:flex;align-items:center;justify-content:center;text-decoration:none;">Back to Login</a>

    <?php else: ?>
    <!-- ── STEP 1: REQUEST RESET ── -->
    <h1>Forgot Password?</h1>
    <p class="sub">Enter your registered email address and we'll send you a password reset link.</p>

    <?php if($error):?><div class="err">⚠️ <?=htmlspecialchars($error)?></div><?php endif;?>

    <form method="POST" action="/forgot_password.php?slug=<?=urlencode($slug)?>">
      <input type="hidden" name="form_type" value="request">
      <input type="hidden" name="slug" value="<?=htmlspecialchars($slug)?>">
      <div class="fgroup">
        <label class="lbl">Email Address</label>
        <input type="email" name="email" class="inp" placeholder="your@email.com" value="<?=htmlspecialchars($_POST['email']??'')?>" autocomplete="email" required>
      </div>
      <button type="submit" class="btn">Send Reset Link</button>
    </form>
    <a href="<?=$login_href?>" class="back">← Back to <span>Login</span></a>

    <?php endif;?>

  </div>
</div>

<script>
function togglePw(id, btn) {
    const f = document.getElementById(id);
    f.type = f.type === 'password' ? 'text' : 'password';
    btn.textContent = f.type === 'password' ? '👁' : '🙈';
}
function checkStrength(pw) {
    const bar = document.getElementById('str-bar');
    const lbl = document.getElementById('str-lbl');
    if (!bar) return;
    let score = 0;
    if (pw.length >= 8)  score++;
    if (pw.length >= 12) score++;
    if (/[A-Z]/.test(pw)) score++;
    if (/[0-9]/.test(pw)) score++;
    if (/[^A-Za-z0-9]/.test(pw)) score++;
    const configs = [
        ['0%',   '#e5e7eb', ''],
        ['25%',  '#ef4444', 'Weak'],
        ['50%',  '#f97316', 'Fair'],
        ['75%',  '#eab308', 'Good'],
        ['100%', '#22c55e', 'Strong'],
        ['100%', '#16a34a', 'Very Strong'],
    ];
    const c = configs[score] ?? configs[0];
    bar.style.width = c[0]; bar.style.background = c[1];
    lbl.textContent = c[2]; lbl.style.color = c[1];
}
</script>
</body>
</html>