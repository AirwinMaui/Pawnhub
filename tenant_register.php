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
        SELECT i.*, t.business_name, t.plan, t.id as tenant_id
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

            $pdo->commit();

            // 4. Auto login
            $_SESSION['user'] = [
                'id'          => $pdo->lastInsertId(),
                'name'        => $fullname,
                'username'    => $username,
                'role'        => 'admin',
                'tenant_id'   => $inv['tenant_id'],
                'tenant_name' => $inv['business_name'],
            ];

            $success = true;
        }
    }
}

if ($success) {
    // Get tenant slug for branded redirect
    $slug_stmt = $pdo->prepare("SELECT slug FROM tenants WHERE id = ? LIMIT 1");
    $slug_stmt->execute([$inv['tenant_id']]);
    $slug_row = $slug_stmt->fetch();
    $redirect_url = !empty($slug_row['slug']) ? '/' . $slug_row['slug'] : '/tenant.php';
    // Redirect to tenant dashboard after 2 seconds
    header('refresh:2;url=' . $redirect_url);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>PawnHub — Complete Your Registration</title>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'Plus Jakarta Sans',sans-serif;min-height:100vh;background:linear-gradient(135deg,#0f172a,#1e3a8a);display:flex;align-items:center;justify-content:center;padding:32px 16px;}
.box{background:#fff;border-radius:20px;box-shadow:0 24px 60px rgba(0,0,0,.25);width:100%;max-width:480px;overflow:hidden;}
.box-header{background:linear-gradient(135deg,#1e3a8a,#2563eb);padding:28px 32px;}
.logo{display:flex;align-items:center;gap:10px;margin-bottom:16px;}
.logo-icon{width:38px;height:38px;background:rgba(255,255,255,.2);border-radius:10px;display:flex;align-items:center;justify-content:center;}
.logo-icon svg{width:20px;height:20px;}
.logo-name{font-size:1.2rem;font-weight:800;color:#fff;}
.hdr-title{font-size:1.1rem;font-weight:800;color:#fff;margin-bottom:4px;}
.hdr-sub{font-size:.82rem;color:rgba(255,255,255,.65);}
.tenant-badge{display:inline-flex;align-items:center;gap:6px;background:rgba(255,255,255,.15);border:1px solid rgba(255,255,255,.25);border-radius:8px;padding:6px 12px;font-size:.8rem;color:#fff;font-weight:600;margin-top:10px;}
.box-body{padding:28px 32px;}
.fg{margin-bottom:15px;}
.fg label{display:block;font-size:.75rem;font-weight:600;color:#374151;margin-bottom:5px;}
.fg input{width:100%;border:1.5px solid #e2e8f0;border-radius:9px;padding:10px 12px;font-family:inherit;font-size:.87rem;color:#0f172a;outline:none;transition:border .2s;}
.fg input:focus{border-color:#2563eb;box-shadow:0 0 0 3px rgba(37,99,235,.1);}
.fg input::placeholder{color:#c8d0db;}
.iw{position:relative;}
.iw>svg{position:absolute;left:11px;top:50%;transform:translateY(-50%);width:15px;height:15px;color:#94a3b8;}
.iw input{padding-left:36px;}
.err{background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:10px 13px;font-size:.81rem;color:#dc2626;margin-bottom:16px;display:flex;align-items:center;gap:7px;}
.err svg{width:14px;height:14px;flex-shrink:0;}
.btn{width:100%;background:linear-gradient(135deg,#2563eb,#1d4ed8);color:#fff;border:none;border-radius:10px;padding:13px;font-family:inherit;font-size:.94rem;font-weight:700;cursor:pointer;box-shadow:0 4px 14px rgba(37,99,235,.3);transition:all .2s;margin-top:4px;}
.btn:hover{transform:translateY(-1px);}
.hint{font-size:.73rem;color:#94a3b8;margin-top:5px;}
.success-box{text-align:center;padding:32px;}
.success-icon{width:64px;height:64px;background:#dcfce7;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 16px;}
.success-icon svg{width:30px;height:30px;color:#15803d;}
.success-title{font-size:1.1rem;font-weight:800;color:#0f172a;margin-bottom:8px;}
.success-sub{font-size:.84rem;color:#64748b;line-height:1.6;margin-bottom:20px;}
.err-box{text-align:center;padding:32px;}
.err-icon{width:64px;height:64px;background:#fee2e2;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 16px;}
.err-icon svg{width:30px;height:30px;color:#dc2626;}
</style>
</head>
<body>
<div class="box">
  <?php if($error): ?>
    <!-- Error State -->
    <div class="box-body err-box">
      <div class="err-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg></div>
      <div style="font-size:1.1rem;font-weight:800;color:#0f172a;margin-bottom:8px;">Invalid Invitation</div>
      <p style="font-size:.85rem;color:#64748b;line-height:1.6;margin-bottom:20px;"><?=htmlspecialchars($error)?></p>
      <a href="login.php" style="display:inline-block;background:var(--blue-acc,#2563eb);color:#fff;text-decoration:none;padding:10px 24px;border-radius:9px;font-size:.88rem;font-weight:700;">Back to Login</a>
    </div>

  <?php elseif($success): ?>
    <!-- Success State -->
    <div class="box-body success-box">
      <div class="success-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><polyline points="9 12 11 14 15 10"/></svg></div>
      <div class="success-title">Welcome to PawnHub! 🎉</div>
      <p class="success-sub">Your account has been created and your branch <strong><?=htmlspecialchars($inv['business_name'])?></strong> is now active.<br><br>Redirecting you to your dashboard...</p>
      <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:9px;padding:12px;font-size:.81rem;color:#15803d;">
        ⏳ Redirecting to dashboard in 2 seconds...<br>
        <a href="<?=$redirect_url?>" style="color:#2563eb;font-weight:600;">Click here if not redirected</a>
      </div>
    </div>

  <?php else: ?>
    <!-- Registration Form -->
    <div class="box-header">
      <div class="logo">
        <div class="logo-icon"><svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2.5"><rect x="3" y="9" width="18" height="12"/><polyline points="3 9 12 3 21 9"/></svg></div>
        <span class="logo-name">PawnHub</span>
      </div>
      <div class="hdr-title">Complete Your Registration</div>
      <div class="hdr-sub">You've been invited to join PawnHub as a branch admin.</div>
      <div class="tenant-badge">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:14px;height:14px;"><rect x="3" y="9" width="18" height="12"/><polyline points="3 9 12 3 21 9"/></svg>
        <?=htmlspecialchars($inv['business_name'])?> · <?=$inv['plan']?> Plan
      </div>
    </div>
    <div class="box-body">
      <?php if($error && !empty($error)): ?>
      <div class="err"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg><?=htmlspecialchars($error)?></div>
      <?php endif; ?>

      <form method="POST">
        <div class="fg">
          <label>Full Name *</label>
          <div class="iw">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
            <input type="text" name="fullname" placeholder="Your full name" value="<?=htmlspecialchars($_POST['fullname'] ?? $inv['owner_name'])?>" required>
          </div>
        </div>
        <div class="fg">
          <label>Email</label>
          <input type="email" value="<?=htmlspecialchars($inv['email'])?>" readonly style="background:#f8fafc;border:1.5px solid #e2e8f0;border-radius:9px;padding:10px 12px;width:100%;font-family:inherit;font-size:.87rem;color:#64748b;">
          <div class="hint">This is the email your invitation was sent to.</div>
        </div>
        <div class="fg">
          <label>Username *</label>
          <div class="iw">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
            <input type="text" name="username" placeholder="Choose a username" value="<?=htmlspecialchars($_POST['username']??'')?>" required>
          </div>
        </div>
        <div class="fg">
          <label>Password * (min. 8 characters)</label>
          <div class="iw">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
            <input type="password" name="password" placeholder="Create a strong password" required>
          </div>
        </div>
        <div class="fg">
          <label>Confirm Password *</label>
          <div class="iw">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
            <input type="password" name="confirm" placeholder="Repeat your password" required>
          </div>
        </div>
        <button type="submit" class="btn">Create My Account & Access System →</button>
        <p style="text-align:center;font-size:.75rem;color:#94a3b8;margin-top:12px;">By registering, you agree to PawnHub's terms of service.</p>
      </form>
    </div>
  <?php endif; ?>
</div>
</body>
</html>