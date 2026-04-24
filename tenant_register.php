<?php
session_start();
require 'db.php';

$token   = trim($_GET['token'] ?? '');
$error   = '';
$success = false;
$inv     = null;
$tenant  = null;

// ── Validate token ────────────────────────────────────────────
if (!$token) {
    $error = 'Invalid or missing invitation link.';
} else {
    $stmt = $pdo->prepare("
        SELECT i.*, t.business_name, t.plan, t.id as tenant_id, t.slug
        FROM tenant_invitations i
        JOIN tenants t ON i.tenant_id = t.id
        WHERE i.token = ? AND i.status = 'pending'
        LIMIT 1
    ");
    $stmt->execute([$token]);
    $inv = $stmt->fetch();

    if (!$inv) {
        $error = 'This invitation link is invalid or has already been used.';
    } elseif (strtotime($inv['expires_at']) < time()) {
        $error = 'This invitation link has expired. Please contact your Super Admin to resend.';
        // Mark as expired
        $pdo->prepare("UPDATE tenant_invitations SET status='expired' WHERE token=?")->execute([$token]);
    }
}

// ── Handle registration form ──────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $inv && !$error) {
    $username = trim($_POST['username']  ?? '');
    $password = trim($_POST['password']  ?? '');
    $confirm  = trim($_POST['confirm']   ?? '');
    $fullname = trim($_POST['fullname']  ?? $inv['owner_name']);

    if (!$username || !$password || !$fullname) {
        $error = 'Please fill in all required fields.';
    } elseif ($password !== $confirm) {
        $error = 'Passwords do not match.';
    } elseif (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters.';
    } else {
        // Check username unique
        $chk = $pdo->prepare("SELECT id FROM users WHERE username=?");
        $chk->execute([$username]);
        if ($chk->fetch()) {
            $error = 'Username already taken. Please choose another.';
        } else {
            $sa_stmt = $pdo->query("SELECT id FROM users WHERE role='super_admin' LIMIT 1");
            $sa_row  = $sa_stmt->fetch();
            $sa_id   = $sa_row ? $sa_row['id'] : null;

            $pdo->beginTransaction();

            // 1. Create admin user for this tenant
            $pdo->prepare("INSERT INTO users (tenant_id,fullname,email,username,password,role,status,approved_by,approved_at) VALUES (?,?,?,?,?,'admin','approved',?,NOW())")
                ->execute([$inv['tenant_id'], $fullname, $inv['email'], $username, password_hash($password, PASSWORD_BCRYPT), $sa_id]);

            // 2. Activate tenant
            $pdo->prepare("UPDATE tenants SET status='active', owner_name=? WHERE id=?")
                ->execute([$fullname, $inv['tenant_id']]);

            // 3. Mark invitation as used
            $pdo->prepare("UPDATE tenant_invitations SET status='used', used_at=NOW() WHERE token=?")
                ->execute([$token]);

            $new_user_id = $pdo->lastInsertId();
            $pdo->commit();

            // 4. Send welcome email with their dedicated login page link
            try {
                require_once __DIR__ . '/mailer.php';
                $slug_for_mail = $pdo->prepare("SELECT slug FROM tenants WHERE id = ? LIMIT 1");
                $slug_for_mail->execute([$inv['tenant_id']]);
                $slug_row_mail = $slug_for_mail->fetch();
                if (!empty($slug_row_mail['slug'])) {
                    sendTenantWelcome($inv['email'], $fullname, $inv['business_name'], $slug_row_mail['slug']);
                }
            } catch (Throwable $e) {
                error_log('Welcome email failed: ' . $e->getMessage());
            }

            $success = true;
        }
    }
}

if ($success) {
    // After setup, redirect tenant owner to their public HOME page (not login).
    // The home page is a friendlier landing — they can sign in from there.
    $slug_stmt = $pdo->prepare("SELECT slug FROM tenants WHERE id = ? LIMIT 1");
    $slug_stmt->execute([$inv['tenant_id']]);
    $slug_row    = $slug_stmt->fetch();
    $tenant_slug = $slug_row['slug'] ?? '';
    $login_url   = !empty($tenant_slug)
        ? '/' . urlencode($tenant_slug) . '?login=1'
        : 'home.php';
    // Redirect to tenant login page after 4 seconds
    header('refresh:4;url=' . $login_url);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover, maximum-scale=1.0"/>
<title><?= htmlspecialchars($inv['business_name'] ?? 'PawnHub') ?> — Complete Your Registration</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@100;300;400;500;600;700;800;900&display=swap" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'Inter',sans-serif;min-height:100vh;background:#f9f9fb;color:#1a1c1d;overflow-x:hidden;position:relative;}
.bg-fixed{position:fixed;inset:0;z-index:0;}
.bg-fixed img{width:100%;height:100%;object-fit:cover;display:block;}
.bg-fixed-ov{position:absolute;inset:0;background:rgba(0,35,111,0.22);backdrop-filter:brightness(0.75);}
.topnav{position:fixed;top:0;left:0;width:100%;z-index:50;display:flex;justify-content:space-between;align-items:center;padding:22px 32px;}
.topnav-brand{font-size:1.5rem;font-weight:900;color:#fff;letter-spacing:-.04em;max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.topnav-right{display:flex;align-items:center;gap:14px;}
.topnav-right span{font-size:.65rem;font-weight:600;text-transform:uppercase;letter-spacing:.14em;color:rgba(255,255,255,.7);}
.topnav-right .ico{font-size:22px;color:#fff;cursor:pointer;padding:7px;border-radius:50%;transition:background .2s;font-family:'Material Symbols Outlined';font-variation-settings:'FILL' 0,'wght' 400,'GRAD' 0,'opsz' 24;line-height:1;width:36px;height:36px;display:flex;align-items:center;justify-content:center;}
.topnav-right .ico:hover{background:rgba(255,255,255,.12);}
.page{position:relative;z-index:10;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:100px 24px 100px;}
.card{width:100%;max-width:520px;background:rgba(255,255,255,0.78);backdrop-filter:blur(24px);-webkit-backdrop-filter:blur(24px);border:1px solid rgba(255,255,255,0.35);border-radius:16px;box-shadow:0 20px 40px rgba(30,58,138,0.1);padding:40px 44px;}
.card-meta{display:flex;align-items:center;gap:8px;margin-bottom:14px;}
.card-meta-badge{background:#1e3a8a;color:#fff;font-size:.6rem;font-weight:700;text-transform:uppercase;letter-spacing:.12em;padding:3px 10px;border-radius:6px;}
.card-meta-step{font-size:.75rem;font-weight:500;color:#757682;}
.card-meta-div{height:1px;width:28px;background:rgba(0,0,0,.12);}
.card-title{font-size:1.75rem;font-weight:800;color:#00236f;letter-spacing:-.03em;line-height:1.15;margin-bottom:8px;}
.card-sub{font-size:.82rem;color:#444651;display:flex;align-items:center;gap:5px;margin-bottom:28px;flex-wrap:wrap;}
.fg{margin-bottom:14px;}
.fg label{display:block;font-size:.65rem;font-weight:700;text-transform:uppercase;letter-spacing:.12em;color:#444651;margin-bottom:6px;padding-left:2px;}
.fin{width:100%;background:#e2e2e4;border:none;border-radius:10px;padding:13px 16px;font-family:'Inter',sans-serif;font-size:.875rem;color:#1a1c1d;outline:none;transition:background .2s,box-shadow .2s;}
.fin:focus{background:#fff;box-shadow:0 0 0 2px rgba(0,35,111,.2);}
.fin::placeholder{color:rgba(0,0,0,.35);}
.fin[readonly]{background:#eeeef0;color:#757682;cursor:not-allowed;}
.fin-wrap{position:relative;}
.fin-wrap .ms-ico{position:absolute;left:13px;top:50%;transform:translateY(-50%);font-size:17px;color:#757682;pointer-events:none;font-family:'Material Symbols Outlined';font-variation-settings:'FILL' 0,'wght' 400,'GRAD' 0,'opsz' 24;}
.fin-wrap .fin{padding-left:40px;}
.fin-wrap .pw-toggle{position:absolute;right:13px;top:50%;transform:translateY(-50%);font-size:18px;color:#757682;cursor:pointer;transition:color .2s;background:none;border:none;padding:0;display:flex;font-family:'Material Symbols Outlined';font-variation-settings:'FILL' 0,'wght' 400,'GRAD' 0,'opsz' 24;}
.fin-wrap .pw-toggle:hover{color:#1a1c1d;}
.grid2{display:grid;grid-template-columns:1fr 1fr;gap:12px;}
.hint{font-size:.7rem;color:#757682;margin-top:4px;padding-left:2px;}
.alert-err{background:#ffdad6;border:1px solid #ffb4ab;border-radius:10px;padding:11px 14px;font-size:.8rem;color:#93000a;display:flex;align-items:center;gap:8px;margin-bottom:16px;}
.btn-submit{width:100%;background:linear-gradient(135deg,#1e3a8a,#2563eb);color:#fff;border:none;border-radius:10px;padding:15px;font-family:'Inter',sans-serif;font-size:.94rem;font-weight:700;cursor:pointer;box-shadow:0 4px 18px rgba(30,58,138,.25);transition:all .2s;display:flex;align-items:center;justify-content:center;gap:8px;margin-top:6px;}
.btn-submit:hover{transform:translateY(-1px);box-shadow:0 6px 22px rgba(30,58,138,.35);}
.btn-submit:active{transform:scale(.98);}
.card-foot{margin-top:20px;text-align:center;font-size:.8rem;color:#757682;}
.card-foot a{color:#00236f;font-weight:700;text-decoration:none;}
.card-foot a:hover{text-decoration:underline;}
.bento-row{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-top:16px;}
.bento{background:rgba(255,255,255,.72);backdrop-filter:blur(16px);border:1px solid rgba(255,255,255,.35);border-radius:10px;padding:12px 8px;text-align:center;}
.bento .ms{font-size:22px;color:#00236f;display:block;margin-bottom:3px;font-family:'Material Symbols Outlined';font-variation-settings:'FILL' 1,'wght' 400,'GRAD' 0,'opsz' 24;}
.bento p{font-size:.55rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:#1e3a8a;}
.state-box{text-align:center;padding:20px 0;}
.state-icon{width:68px;height:68px;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 16px;}
.state-icon.ok{background:#dcfce7;}
.state-icon.err{background:#fee2e2;}
.state-icon .ms{font-size:32px;font-family:'Material Symbols Outlined';font-variation-settings:'FILL' 1,'wght' 400,'GRAD' 0,'opsz' 24;}
.state-icon.ok .ms{color:#15803d;}
.state-icon.err .ms{color:#dc2626;}
.state-title{font-size:1.2rem;font-weight:800;color:#1a1c1d;margin-bottom:8px;}
.state-sub{font-size:.84rem;color:#444651;line-height:1.65;margin-bottom:20px;}
.state-redirect{background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px;padding:13px;font-size:.8rem;color:#15803d;}
.state-redirect a{color:#2563eb;font-weight:600;}
.btn-back{display:inline-block;background:#00236f;color:#fff;text-decoration:none;padding:11px 26px;border-radius:10px;font-size:.88rem;font-weight:700;}
.footer-bar{position:fixed;bottom:0;left:0;width:100%;z-index:40;display:flex;justify-content:space-between;align-items:center;padding:18px 48px;backdrop-filter:blur(12px);background:rgba(255,255,255,.06);}
.footer-bar span{font-size:.65rem;font-weight:500;text-transform:uppercase;letter-spacing:.12em;color:rgba(255,255,255,.55);}
.footer-bar nav{display:flex;gap:28px;}
.footer-bar nav a{font-size:.65rem;font-weight:500;text-transform:uppercase;letter-spacing:.12em;color:rgba(255,255,255,.55);text-decoration:none;transition:color .2s;}
.footer-bar nav a:hover{color:#fff;}
.ms-inline{font-family:'Material Symbols Outlined';font-variation-settings:'FILL' 0,'wght' 400,'GRAD' 0,'opsz' 24;font-size:15px;vertical-align:middle;}
@media(max-width:600px){
  .card{padding:20px 16px;}
  .grid2{grid-template-columns:1fr;}
  .footer-bar nav{display:none;}
  .topnav-right span{display:none;}
  .topnav{padding:14px 16px;}
  .topnav-brand{font-size:1.1rem;}
  .topnav-right .ico{font-size:18px !important;width:30px;height:30px;padding:5px;}
  .page{padding:80px 12px 80px;}
  .bento-row{grid-template-columns:repeat(3,1fr);}
}
@media(max-width:380px){
  .bento-row{grid-template-columns:1fr;}
}

/* ===== MOBILE / iOS COMPATIBILITY FIXES ===== */
* { -webkit-tap-highlight-color: transparent; }
html { -webkit-text-size-adjust: 100%; }
/* iOS safe area support */
.safe-top    { padding-top:    env(safe-area-inset-top,    0px); }
.safe-bottom { padding-bottom: env(safe-area-inset-bottom, 0px); }
/* iOS overflow scroll */
.overflow-y-auto, .overflow-auto { -webkit-overflow-scrolling: touch; }
/* Prevent iOS zoom on input focus */
input, select, textarea { font-size: max(16px, 1rem) !important; }
/* Android Material Symbols fix - prevent large icon rendering */
.material-symbols-outlined,
[class*="material-symbols"],
.ico, .ms, .ms-ico, .ms-inline {
  font-variation-settings: 'FILL' 0, 'wght' 400, 'GRAD' 0, 'opsz' 20 !important;
  line-height: 1 !important;
}
/* Mobile sidebar fix */
@media (max-width: 768px) {
  .sidebar-fixed { position: fixed !important; z-index: 50; height: 100dvh; }
  .main-content  { margin-left: 0 !important; width: 100% !important; }
}
/* Smooth scrolling on mobile */
html { scroll-behavior: smooth; }

/* Form mobile fixes */
@media (max-width: 480px) {
    .panel, .card { 
        width: 100% !important; 
        max-width: 100% !important; 
        margin: 0 !important;
        border-radius: 0 !important;
        min-height: 100dvh !important;
    }
    .page { padding: 0 !important; align-items: flex-start !important; }
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
  <div class="topnav-brand"><?= $inv ? htmlspecialchars($inv['business_name']) : 'PawnHub' ?></div>
  <div class="topnav-right">
    <span>Security Protocol Active</span>
    <span class="ico">help_outline</span>
  </div>
</header>

<!-- Page -->
<main class="page">
  <div style="width:100%;max-width:520px;">

  <?php if($error): ?>
  <!-- ── Error State ── -->
  <div class="card">
    <div class="state-box">
      <div class="state-icon err"><span class="ms">cancel</span></div>
      <div class="state-title">Invalid Invitation</div>
      <p class="state-sub"><?= htmlspecialchars($error) ?></p>
      <a href="login.php" class="btn-back">Back to Login</a>
    </div>
  </div>

  <?php elseif($success): ?>
  <!-- ── Success State ── -->
  <div class="card">
    <div class="state-box">
      <div class="state-icon ok"><span class="ms">check_circle</span></div>
      <div class="state-title">Account Created! 🎉</div>
      <p class="state-sub">
        Your account has been set up and your branch <strong><?= htmlspecialchars($inv['business_name']) ?></strong> is now active.<br><br>
        You can now sign in using the username and password you just created.
      </p>
      <div class="state-redirect">
        ⏳ Redirecting to your shop home page in 4 seconds...<br>
        <a href="<?= htmlspecialchars($login_url) ?>">Go to Your Shop →</a>
      </div>
    </div>
  </div>

  <?php else: ?>
  <!-- ── Registration Form ── -->
  <div class="card">
    <div class="card-meta">
      <span class="card-meta-badge">Member Portal</span>
      <div class="card-meta-div"></div>
      <span class="card-meta-step">Step 02 of 02</span>
    </div>
    <h1 class="card-title">Complete Your Registration</h1>
    <p class="card-sub">
      <span class="ms-inline">storefront</span>
      <?= htmlspecialchars($inv['business_name']) ?> · <strong style="color:#2563eb;"><?= htmlspecialchars($inv['plan']) ?> Plan</strong>
    </p>

    <?php if($error && !empty($error)): ?>
    <div class="alert-err">
      <span style="font-family:'Material Symbols Outlined';font-size:16px;flex-shrink:0;">error</span>
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
            value="<?= htmlspecialchars($_POST['fullname'] ?? $inv['owner_name']) ?>" required>
        </div>
      </div>

      <!-- Email & Username -->
      <div class="grid2">
        <div class="fg">
          <label>Email</label>
          <input class="fin" type="email" value="<?= htmlspecialchars($inv['email']) ?>" readonly>
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

      <!-- Password & Confirm -->
      <div class="grid2">
        <div class="fg">
          <label>Password * (min. 8)</label>
          <div class="fin-wrap">
            <input class="fin" type="password" id="pw1" name="password" placeholder="••••••••" required oninput="checkStrength(this.value)">
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

      <!-- Password Strength Meter -->
      <div style="margin:-6px 0 14px;">
        <div style="height:5px;background:#e2e2e4;border-radius:99px;overflow:hidden;">
          <div id="str_bar" style="height:100%;width:0;border-radius:99px;transition:width .3s,background .3s;"></div>
        </div>
        <div style="display:flex;justify-content:space-between;align-items:center;margin-top:4px;">
          <span id="str_lbl" style="font-size:.68rem;font-weight:700;color:#aaa;transition:color .3s;"></span>
          <span style="font-size:.65rem;color:#999;">Use 8+ chars, uppercase, numbers & symbols</span>
        </div>
      </div>

      <!-- TOS -->
      <div style="display:flex;align-items:flex-start;gap:10px;margin:14px 0 18px;">
        <input type="checkbox" id="tos" required style="margin-top:2px;width:15px;height:15px;accent-color:#00236f;flex-shrink:0;">
        <label for="tos" style="font-size:.75rem;color:#444651;line-height:1.55;cursor:pointer;">
          I agree to the <a href="#" style="color:#00236f;font-weight:700;">Terms of Service</a> and <a href="#" style="color:#00236f;font-weight:700;">Privacy Policy</a> including data processing for administrative purposes.
        </label>
      </div>

      <button type="submit" class="btn-submit">
        Create My Account &amp; Access System
        <span style="font-family:'Material Symbols Outlined';font-size:18px;font-variation-settings:'FILL' 0,'wght' 400,'GRAD' 0,'opsz' 24;">arrow_forward</span>
      </button>
    </form>

    <div class="card-foot">
      Already registered for this branch? <a href="<?= !empty($inv['slug']) ? '/' . htmlspecialchars($inv['slug']) : 'login.php' ?>">Log in here</a>
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

<!-- Footer -->
<footer class="footer-bar">
  <span>© <?= date('Y') ?> <?= htmlspecialchars($inv['business_name'] ?? 'PawnHub') ?>. All rights reserved.</span>
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
  const slugSuffix = '@<?= addslashes($inv['slug'] ?? '') ?>.com';
  const usernameInput = document.querySelector('input[name="username"]');
  if (!usernameInput || usernameInput.value) return; // skip if already filled (POST error)

  // Pre-fill with owner name + slug suffix on page load
  const ownerName = '<?= addslashes(strtolower(preg_replace('/\s+/', '', $inv['owner_name'] ?? ''))) ?>';
  if (ownerName) {
    usernameInput.value = ownerName + slugSuffix;
  }

  // When user types, ensure @slug suffix stays
  usernameInput.addEventListener('input', function () {
    const val = this.value;
    if (!val.endsWith(slugSuffix)) {
      // Strip any existing @... suffix and re-add
      const base = val.replace(/@[^@]*$/, '');
      this.value = base + slugSuffix;
      // Move cursor before @slug
      const pos = base.length;
      this.setSelectionRange(pos, pos);
    }
  });

  // Prevent user from deleting the suffix
  usernameInput.addEventListener('keydown', function (e) {
    const val = this.value;
    const cursorPos = this.selectionStart;
    const protectedStart = val.length - slugSuffix.length;
    if (cursorPos > protectedStart) {
      if (['Backspace','Delete','ArrowLeft'].includes(e.key) && e.key !== 'ArrowLeft') {
        e.preventDefault();
      }
    }
  });
})();
</script>
</body>
</html>