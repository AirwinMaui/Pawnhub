<?php
session_start();
require 'db.php';

$token   = trim($_GET['token'] ?? '');
$error   = '';
$success = false;
$inv     = null;

// ── Validate token ────────────────────────────────────────────
if (!$token) {
    $error = 'Invalid or missing invitation link.';
} else {
    try {
        $stmt = $pdo->prepare("
            SELECT i.*, t.business_name, t.id AS tenant_id, t.slug
            FROM tenant_invitations i
            JOIN tenants t ON i.tenant_id = t.id
            WHERE i.token = ?
              AND i.status = 'pending'
              AND i.role = 'manager'
            LIMIT 1
        ");
        $stmt->execute([$token]);
        $inv = $stmt->fetch();

        if (!$inv) {
            $error = 'This invitation link is invalid or has already been used.';
        } elseif (strtotime($inv['expires_at']) < time()) {
            $error = 'This invitation link has expired. Please ask your branch owner to resend.';
            $pdo->prepare("UPDATE tenant_invitations SET status='expired' WHERE token=?")->execute([$token]);
            $inv = null;
        }
    } catch (Throwable $e) {
        $error = 'A database error occurred. Please try again later.';
        error_log('manager_register token lookup failed: ' . $e->getMessage());
    }
}

// ── Handle form submission ────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $inv && !$error) {
    $fullname = trim($_POST['fullname'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');
    $confirm  = trim($_POST['confirm']  ?? '');

    if (!$fullname || !$username || !$password) {
        $error = 'Please fill in all required fields.';
    } elseif ($password !== $confirm) {
        $error = 'Passwords do not match.';
    } elseif (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters.';
    } else {
        try {
            // Check username uniqueness
            $chk = $pdo->prepare("SELECT id FROM users WHERE username = ?");
            $chk->execute([$username]);
            if ($chk->fetch()) {
                $error = 'Username already taken. Please choose another.';
            } else {
                $pdo->beginTransaction();

                // 1. Create manager user
                $pdo->prepare("
                    INSERT INTO users
                        (tenant_id, fullname, email, username, password, role, status, approved_by, approved_at)
                    VALUES
                        (?, ?, ?, ?, ?, 'manager', 'approved', ?, NOW())
                ")->execute([
                    $inv['tenant_id'],
                    $fullname,
                    $inv['email'],
                    $username,
                    password_hash($password, PASSWORD_BCRYPT),
                    $inv['created_by'] ?? null,
                ]);
                $new_uid = $pdo->lastInsertId();

                // 2. Mark invitation as used
                $pdo->prepare("UPDATE tenant_invitations SET status='used', used_at=NOW() WHERE token=?")
                    ->execute([$token]);

                $pdo->commit();

                // 3. Send welcome email (non-fatal if it fails)
                try {
                    $mailer_path = __DIR__ . '/mailer.php';
                    if (file_exists($mailer_path)) {
                        require_once $mailer_path;
                        if (!empty($inv['slug']) && function_exists('sendManagerWelcome')) {
                            sendManagerWelcome($inv['email'], $fullname, $inv['business_name'], $inv['slug']);
                        }
                    }
                } catch (Throwable $e) {
                    error_log('Manager welcome email failed: ' . $e->getMessage());
                }

                // 4. Auto-login
                session_regenerate_id(true);
                $_SESSION['user'] = [
                    'id'          => $new_uid,
                    'name'        => $fullname,
                    'username'    => $username,
                    'role'        => 'manager',
                    'tenant_id'   => $inv['tenant_id'],
                    'tenant_name' => $inv['business_name'],
                    'tenant_slug' => $inv['slug'] ?? '',
                ];

                $success = true;
                // Redirect to manager dashboard after 2 seconds
                header('refresh:2;url=/manager.php');
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('manager_register registration failed: ' . $e->getMessage());
            $error = 'Registration failed due to a server error. Please try again.';
        }
    }
}

// ── View variables ────────────────────────────────────────────
$biz_name  = htmlspecialchars($inv['business_name'] ?? 'PawnHub');
$inv_email = $inv['email'] ?? '';
$inv_name  = $inv['owner_name'] ?? '';
$inv_slug  = $inv['slug'] ?? '';
$login_href = $inv_slug ? '/' . htmlspecialchars($inv_slug) : '/login.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title><?= $biz_name ?> — Manager Account Setup</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'Inter',sans-serif;min-height:100vh;overflow-x:hidden;position:relative;}
/* Background */
.bg-fixed{position:fixed;inset:0;z-index:0;}
.bg-fixed img{width:100%;height:100%;object-fit:cover;display:block;}
.bg-fixed-ov{position:absolute;inset:0;background:rgba(0,35,111,0.22);backdrop-filter:brightness(0.75);}
/* Nav */
.topnav{position:fixed;top:0;left:0;width:100%;z-index:50;display:flex;justify-content:space-between;align-items:center;padding:22px 32px;}
.topnav-brand{font-size:1.5rem;font-weight:900;color:#fff;letter-spacing:-.04em;}
.topnav-right{display:flex;align-items:center;gap:14px;}
.topnav-right span{font-size:.65rem;font-weight:600;text-transform:uppercase;letter-spacing:.14em;color:rgba(255,255,255,.7);}
/* Page */
.page{position:relative;z-index:10;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:100px 24px;}
/* Card */
.card{width:100%;max-width:520px;background:rgba(255,255,255,0.78);backdrop-filter:blur(24px);-webkit-backdrop-filter:blur(24px);border:1px solid rgba(255,255,255,0.35);border-radius:16px;box-shadow:0 20px 40px rgba(30,58,138,0.1);padding:40px 44px;}
/* Badge */
.role-badge{display:inline-block;background:linear-gradient(135deg,#064e3b,#059669);color:#fff;font-size:.6rem;font-weight:700;text-transform:uppercase;letter-spacing:.12em;padding:4px 12px;border-radius:6px;margin-bottom:6px;}
.step-line{display:flex;align-items:center;gap:8px;margin-bottom:14px;}
.step-div{height:1px;width:28px;background:rgba(0,0,0,.12);}
.step-txt{font-size:.75rem;font-weight:500;color:#757682;}
.card-title{font-size:1.75rem;font-weight:800;color:#064e3b;letter-spacing:-.03em;line-height:1.15;margin-bottom:8px;}
.card-sub{font-size:.82rem;color:#444651;display:flex;align-items:center;gap:5px;margin-bottom:28px;}
/* Fields */
.fg{margin-bottom:14px;}
.fg label{display:block;font-size:.65rem;font-weight:700;text-transform:uppercase;letter-spacing:.12em;color:#444651;margin-bottom:6px;padding-left:2px;}
.fin{width:100%;background:#e2e2e4;border:none;border-radius:10px;padding:13px 16px;font-family:'Inter',sans-serif;font-size:.875rem;color:#1a1c1d;outline:none;transition:background .2s,box-shadow .2s;}
.fin:focus{background:#fff;box-shadow:0 0 0 2px rgba(5,150,105,.3);}
.fin::placeholder{color:rgba(0,0,0,.35);}
.fin[readonly]{background:#eeeef0;color:#757682;cursor:not-allowed;}
.fin-wrap{position:relative;}
.fin-wrap .ms-ico{position:absolute;left:13px;top:50%;transform:translateY(-50%);font-size:17px;color:#757682;pointer-events:none;font-family:'Material Symbols Outlined';font-variation-settings:'FILL' 0,'wght' 400,'GRAD' 0,'opsz' 24;}
.fin-wrap .fin{padding-left:40px;}
.fin-wrap .pw-toggle{position:absolute;right:13px;top:50%;transform:translateY(-50%);font-size:18px;color:#757682;cursor:pointer;background:none;border:none;padding:0;display:flex;font-family:'Material Symbols Outlined';font-variation-settings:'FILL' 0,'wght' 400,'GRAD' 0,'opsz' 24;}
.grid2{display:grid;grid-template-columns:1fr 1fr;gap:12px;}
.hint{font-size:.7rem;color:#757682;margin-top:4px;padding-left:2px;}
/* Error */
.alert-err{background:#ffdad6;border:1px solid #ffb4ab;border-radius:10px;padding:11px 14px;font-size:.8rem;color:#93000a;display:flex;align-items:center;gap:8px;margin-bottom:16px;}
/* Submit */
.btn-submit{width:100%;background:linear-gradient(135deg,#064e3b,#059669);color:#fff;border:none;border-radius:10px;padding:15px;font-family:'Inter',sans-serif;font-size:.94rem;font-weight:700;cursor:pointer;box-shadow:0 4px 18px rgba(5,150,105,.25);transition:all .2s;display:flex;align-items:center;justify-content:center;gap:8px;margin-top:6px;}
.btn-submit:hover{transform:translateY(-1px);box-shadow:0 6px 22px rgba(5,150,105,.4);}
.btn-submit:active{transform:scale(.98);}
/* Footer link */
.card-foot{margin-top:20px;text-align:center;font-size:.8rem;color:#757682;}
.card-foot a{color:#064e3b;font-weight:700;text-decoration:none;}
.card-foot a:hover{text-decoration:underline;}
/* State boxes */
.state-box{text-align:center;padding:20px 0;}
.state-icon{width:68px;height:68px;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 16px;}
.state-icon.ok{background:#dcfce7;}
.state-icon.err{background:#fee2e2;}
.state-icon .ms{font-size:32px;font-family:'Material Symbols Outlined';font-variation-settings:'FILL' 1,'wght' 400,'GRAD' 0,'opsz' 24;line-height:1;}
.state-icon.ok .ms{color:#15803d;}
.state-icon.err .ms{color:#dc2626;}
.state-title{font-size:1.2rem;font-weight:800;color:#1a1c1d;margin-bottom:8px;}
.state-sub{font-size:.84rem;color:#444651;line-height:1.65;margin-bottom:20px;}
.state-redirect{background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px;padding:13px;font-size:.8rem;color:#15803d;margin-top:4px;}
.state-redirect a{color:#2563eb;font-weight:600;}
.btn-back{display:inline-block;background:#064e3b;color:#fff;text-decoration:none;padding:11px 26px;border-radius:10px;font-size:.88rem;font-weight:700;}
/* Bento */
.bento-row{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-top:16px;}
.bento{background:rgba(255,255,255,.72);backdrop-filter:blur(16px);border:1px solid rgba(255,255,255,.35);border-radius:10px;padding:12px 8px;text-align:center;}
.bento .ms{font-size:22px;color:#064e3b;display:block;margin-bottom:3px;font-family:'Material Symbols Outlined';font-variation-settings:'FILL' 1,'wght' 400,'GRAD' 0,'opsz' 24;line-height:1;}
.bento p{font-size:.55rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:#064e3b;}
/* Footer bar */
.footer-bar{position:fixed;bottom:0;left:0;width:100%;z-index:40;display:flex;justify-content:space-between;align-items:center;padding:18px 48px;backdrop-filter:blur(12px);background:rgba(255,255,255,.06);}
.footer-bar span{font-size:.65rem;font-weight:500;text-transform:uppercase;letter-spacing:.12em;color:rgba(255,255,255,.55);}
.footer-bar nav{display:flex;gap:28px;}
.footer-bar nav a{font-size:.65rem;font-weight:500;text-transform:uppercase;letter-spacing:.12em;color:rgba(255,255,255,.55);text-decoration:none;}
@media(max-width:600px){
  .card{padding:28px 22px;}
  .grid2{grid-template-columns:1fr;}
  .footer-bar nav{display:none;}
}
</style>
</head>
<body>

<!-- Background -->
<div class="bg-fixed">
  <img src="https://lh3.googleusercontent.com/aida-public/AB6AXuCx2DpF3DhIT8TMkI77WjrdPvL6YVSpVpWmOEXSGYEKlgSNatvfUPOuV3QNXsel_47FDOEDJ99WDIO4ESDYlrYK-ERBoWVC3c-LXv1bOADmUcIWror3a9k9pousLqJjChv08FrIrBVwj8x-1jR1uBrrxeP6SIDEKNxL1OxGXCIGuHnIVKd8KPfKebyipejNKaBy12kucRMfr0_Og_bv9bc1_Ikfu9Airs60mBJVLIZs4vDeoJzDvCWs3p9cdGZ4TtDQqH6R7tA3fHI" alt="Pawnshop background"/>
  <div class="bg-fixed-ov"></div>
</div>

<!-- Top Nav -->
<header class="topnav">
  <div class="topnav-brand">PawnHub</div>
  <div class="topnav-right">
    <span>Security Protocol Active</span>
  </div>
</header>

<!-- Page -->
<main class="page">
  <div style="width:100%;max-width:520px;">

  <?php if ($error && !$inv): ?>
  <!-- Error State -->
  <div class="card">
    <div class="state-box">
      <div class="state-icon err"><span class="ms">cancel</span></div>
      <div class="state-title">Invalid Invitation</div>
      <p class="state-sub"><?= htmlspecialchars($error) ?></p>
      <a href="/login.php" class="btn-back">Back to Login</a>
    </div>
  </div>

  <?php elseif ($success): ?>
  <!-- Success State -->
  <div class="card">
    <div class="state-box">
      <div class="state-icon ok"><span class="ms">check_circle</span></div>
      <div class="state-title">Manager Account Created! 🎉</div>
      <p class="state-sub">Welcome to <strong><?= $biz_name ?></strong>!<br>Your Manager account is ready.<br><br>Redirecting you to your dashboard...</p>
      <div class="state-redirect">
        ⏳ Redirecting in 2 seconds...<br>
        <a href="/manager.php">Click here if not redirected</a>
      </div>
    </div>
  </div>

  <?php else: ?>
  <!-- Registration Form -->
  <div class="card">
    <div class="step-line">
      <span class="role-badge">Manager Portal</span>
      <div class="step-div"></div>
      <span class="step-txt">Step 02 of 02</span>
    </div>
    <h1 class="card-title">Set Up Your Account</h1>
    <p class="card-sub">
      <span style="font-family:'Material Symbols Outlined';font-size:15px;color:#757682;font-variation-settings:'FILL' 0,'wght' 400,'GRAD' 0,'opsz' 24;">storefront</span>
      <?= $biz_name ?> · <strong style="color:#059669;">Manager Account</strong>
    </p>

    <?php if ($error): ?>
    <div class="alert-err">
      <span style="font-family:'Material Symbols Outlined';font-size:16px;flex-shrink:0;font-variation-settings:'FILL' 0,'wght' 400,'GRAD' 0,'opsz' 24;">error</span>
      <?= htmlspecialchars($error) ?>
    </div>
    <?php endif; ?>

    <form method="POST">
      <!-- Full Name -->
      <div class="fg">
        <label>Full Name *</label>
        <div class="fin-wrap">
          <span class="ms-ico">person</span>
          <input class="fin" type="text" name="fullname" placeholder="Your full name"
            value="<?= htmlspecialchars($_POST['fullname'] ?? $inv_name) ?>" required>
        </div>
      </div>

      <!-- Email & Username -->
      <div class="grid2">
        <div class="fg">
          <label>Email</label>
          <input class="fin" type="email" value="<?= htmlspecialchars($inv_email) ?>" readonly>
          <div class="hint">Invitation email address.</div>
        </div>
        <div class="fg">
          <label>Username *</label>
          <div class="fin-wrap">
            <span class="ms-ico">account_circle</span>
            <input class="fin" type="text" name="username" placeholder="Choose a username"
              value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" required>
          </div>
        </div>
      </div>

      <!-- Password -->
      <div class="grid2">
        <div class="fg">
          <label>Password * (min. 8)</label>
          <div class="fin-wrap">
            <input class="fin" type="password" id="pw1" name="password" placeholder="••••••••" required>
            <button type="button" class="pw-toggle" onclick="togglePw('pw1',this)">visibility</button>
          </div>
        </div>
        <div class="fg">
          <label>Confirm Password *</label>
          <div class="fin-wrap">
            <input class="fin" type="password" id="pw2" name="confirm" placeholder="••••••••" required>
            <button type="button" class="pw-toggle" onclick="togglePw('pw2',this)">visibility</button>
          </div>
        </div>
      </div>

      <!-- TOS -->
      <div style="display:flex;align-items:flex-start;gap:10px;margin:14px 0 18px;">
        <input type="checkbox" id="tos" required style="margin-top:2px;width:15px;height:15px;accent-color:#059669;flex-shrink:0;">
        <label for="tos" style="font-size:.75rem;color:#444651;line-height:1.55;cursor:pointer;">
          I agree to the <a href="#" style="color:#064e3b;font-weight:700;">Terms of Service</a> and
          <a href="#" style="color:#064e3b;font-weight:700;">Privacy Policy</a> including data processing for administrative purposes.
        </label>
      </div>

      <button type="submit" class="btn-submit">
        Create My Account &amp; Sign In
        <span style="font-family:'Material Symbols Outlined';font-size:18px;font-variation-settings:'FILL' 0,'wght' 400,'GRAD' 0,'opsz' 24;">arrow_forward</span>
      </button>
    </form>

    <div class="card-foot">
      Already registered for this branch? <a href="<?= $login_href ?>">Log in here</a>
    </div>
  </div>

  <!-- Bento badges -->
  <div class="bento-row">
    <div class="bento"><span class="ms">encrypted</span><p>256-Bit SSL</p></div>
    <div class="bento"><span class="ms">verified_user</span><p>Identity Verified</p></div>
    <div class="bento"><span class="ms">cloud_done</span><p>Cloud Sync</p></div>
  </div>

  <?php endif; ?>
  </div>
</main>

<footer class="footer-bar">
  <span>© <?= date('Y') ?> PawnHub. All rights reserved.</span>
  <nav>
    <a href="#">Privacy Policy</a>
    <a href="#">Terms of Service</a>
    <a href="#">Branch Directory</a>
  </nav>
</footer>

<script>
function togglePw(id, btn) {
  const f = document.getElementById(id);
  const show = f.type === 'password';
  f.type = show ? 'text' : 'password';
  btn.textContent = show ? 'visibility_off' : 'visibility';
}
</script>
</body>
</html>