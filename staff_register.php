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
    $stmt = $pdo->prepare("
        SELECT i.*, t.business_name, t.plan, t.id AS tenant_id, t.slug
        FROM tenant_invitations i
        JOIN tenants t ON i.tenant_id = t.id
        WHERE i.token = ? AND i.status = 'pending'
        AND i.role IN ('staff','cashier')
        LIMIT 1
    ");
    $stmt->execute([$token]);
    $inv = $stmt->fetch();

    if (!$inv) {
        $error = 'This invitation link is invalid or has already been used.';
    } elseif (strtotime($inv['expires_at']) < time()) {
        $error = 'This invitation link has expired. Please contact your branch admin.';
        $pdo->prepare("UPDATE tenant_invitations SET status='expired' WHERE token=?")->execute([$token]);
        $inv = null;
    }
}

// ── Handle registration form ──────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $inv && !$error) {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');
    $confirm  = trim($_POST['confirm']  ?? '');
    $fullname = trim($_POST['fullname'] ?? $inv['owner_name']);

    if (!$username || !$password || !$fullname) {
        $error = 'Please fill in all required fields.';
    } elseif ($password !== $confirm) {
        $error = 'Passwords do not match.';
    } elseif (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters.';
    } else {
        $chk = $pdo->prepare("SELECT id FROM users WHERE username = ?");
        $chk->execute([$username]);
        if ($chk->fetch()) {
            $error = 'Username already taken. Please choose another.';
        } else {
            $pdo->beginTransaction();

            // 1. Create staff/cashier user
            $pdo->prepare("
                INSERT INTO users (tenant_id, fullname, email, username, password, role, status, approved_by, approved_at)
                VALUES (?, ?, ?, ?, ?, ?, 'approved', ?, NOW())
            ")->execute([
                $inv['tenant_id'],
                $fullname,
                $inv['email'],
                $username,
                password_hash($password, PASSWORD_BCRYPT),
                $inv['role'],
                $inv['created_by'] ?? null
            ]);
            $new_uid = $pdo->lastInsertId();

            // 2. Mark invitation as used
            $pdo->prepare("UPDATE tenant_invitations SET status='used', used_at=NOW() WHERE token=?")
                ->execute([$token]);

            $pdo->commit();

            // 3. Send welcome email with login page link
            try {
                require_once __DIR__ . '/mailer.php';
                if (!empty($inv['slug'])) {
                    sendStaffWelcome(
                        $inv['email'],
                        $fullname,
                        $inv['business_name'],
                        $inv['role'],
                        $inv['slug']
                    );
                }
            } catch (Throwable $e) {
                error_log('Staff welcome email failed: ' . $e->getMessage());
            }

            // 4. Auto login
            session_regenerate_id(true);
            $_SESSION['user'] = [
                'id'          => $new_uid,
                'name'        => $fullname,
                'username'    => $username,
                'role'        => $inv['role'],
                'tenant_id'   => $inv['tenant_id'],
                'tenant_name' => $inv['business_name'],
            ];

            $success      = true;
            $redirect_url = !empty($inv['slug']) ? '/' . $inv['slug'] : '/tenant_login.php';
            header('refresh:2;url=' . $redirect_url);
        }
    }
}

$role_label = ucfirst($inv['role'] ?? 'Staff');
$biz_name   = htmlspecialchars($inv['business_name'] ?? 'PawnHub');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title><?= $biz_name ?> — Set Up Your Account</title>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'Plus Jakarta Sans',sans-serif;min-height:100vh;background:linear-gradient(135deg,#0f172a,#1e3a8a);display:flex;align-items:center;justify-content:center;padding:32px 16px;}
.box{background:#fff;border-radius:20px;box-shadow:0 24px 60px rgba(0,0,0,.25);width:100%;max-width:480px;overflow:hidden;}
.box-header{padding:28px 32px;}
.box-header.staff  { background:linear-gradient(135deg,#1e3a8a,#2563eb); }
.box-header.cashier{ background:linear-gradient(135deg,#4c1d95,#7c3aed); }
.logo{display:flex;align-items:center;gap:10px;margin-bottom:16px;}
.logo-icon{width:38px;height:38px;background:rgba(255,255,255,.2);border-radius:10px;display:flex;align-items:center;justify-content:center;}
.logo-icon svg{width:20px;height:20px;}
.logo-name{font-size:1.2rem;font-weight:800;color:#fff;}
.hdr-title{font-size:1.1rem;font-weight:800;color:#fff;margin-bottom:4px;}
.hdr-sub{font-size:.82rem;color:rgba(255,255,255,.65);}
.role-badge{display:inline-flex;align-items:center;gap:6px;background:rgba(255,255,255,.15);border:1px solid rgba(255,255,255,.25);border-radius:8px;padding:6px 12px;font-size:.8rem;color:#fff;font-weight:600;margin-top:10px;}
.box-body{padding:28px 32px;}
.fg{margin-bottom:15px;}
.fg label{display:block;font-size:.75rem;font-weight:600;color:#374151;margin-bottom:5px;}
.fg input{width:100%;border:1.5px solid #e2e8f0;border-radius:9px;padding:10px 12px;font-family:inherit;font-size:.87rem;color:#0f172a;outline:none;transition:border .2s;}
.fg input:focus{border-color:#2563eb;box-shadow:0 0 0 3px rgba(37,99,235,.1);}
.fg input::placeholder{color:#c8d0db;}
.fg input[readonly]{background:#f8fafc;border:1.5px solid #e2e8f0;color:#94a3b8;cursor:not-allowed;}
.iw{position:relative;}
.iw>svg{position:absolute;left:11px;top:50%;transform:translateY(-50%);width:15px;height:15px;color:#94a3b8;}
.iw input{padding-left:36px;}
.err{background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:10px 13px;font-size:.81rem;color:#dc2626;margin-bottom:16px;display:flex;align-items:center;gap:7px;}
.btn{width:100%;border:none;border-radius:10px;padding:13px;font-family:inherit;font-size:.94rem;font-weight:700;cursor:pointer;box-shadow:0 4px 14px rgba(37,99,235,.3);transition:all .2s;margin-top:4px;color:#fff;}
.btn.staff  { background:linear-gradient(135deg,#2563eb,#1d4ed8); }
.btn.cashier{ background:linear-gradient(135deg,#7c3aed,#4c1d95); }
.btn:hover{transform:translateY(-1px);}
.hint{font-size:.73rem;color:#94a3b8;margin-top:5px;}
.success-box{text-align:center;padding:32px;}
.success-icon{width:64px;height:64px;background:#dcfce7;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 16px;}
.success-icon svg{width:30px;height:30px;color:#15803d;}
.err-box{text-align:center;padding:32px;}
.err-icon{width:64px;height:64px;background:#fee2e2;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 16px;}
.err-icon svg{width:30px;height:30px;color:#dc2626;}
</style>
</head>
<body>
<div class="box">

  <?php if ($error && !$inv): ?>
  <!-- Error State -->
  <div class="box-body err-box">
    <div class="err-icon">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
    </div>
    <div style="font-size:1.1rem;font-weight:800;color:#0f172a;margin-bottom:8px;">Invalid Invitation</div>
    <p style="font-size:.85rem;color:#64748b;line-height:1.6;margin-bottom:20px;"><?= htmlspecialchars($error) ?></p>
    <a href="login.php" style="display:inline-block;background:#2563eb;color:#fff;text-decoration:none;padding:10px 24px;border-radius:9px;font-size:.88rem;font-weight:700;">Back to Login</a>
  </div>

  <?php elseif ($success): ?>
  <!-- Success State -->
  <div class="box-body success-box">
    <div class="success-icon">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><polyline points="9 12 11 14 15 10"/></svg>
    </div>
    <div style="font-size:1.1rem;font-weight:800;color:#0f172a;margin-bottom:8px;">Account Created! 🎉</div>
    <p style="font-size:.84rem;color:#64748b;line-height:1.6;margin-bottom:20px;">
      Welcome to <strong><?= $biz_name ?></strong>!<br>
      Your <strong><?= $role_label ?></strong> account is ready.<br><br>
      Redirecting you to your dashboard...
    </p>
    <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:9px;padding:12px;font-size:.81rem;color:#15803d;">
      ⏳ Redirecting in 2 seconds...<br>
      <a href="<?= htmlspecialchars($redirect_url) ?>" style="color:#2563eb;font-weight:600;">Click here if not redirected</a>
    </div>
  </div>

  <?php else: ?>
  <!-- Registration Form -->
  <div class="box-header <?= htmlspecialchars($inv['role'] ?? 'staff') ?>">
    <div class="logo">
      <div class="logo-icon">
        <svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2.5"><rect x="3" y="9" width="18" height="12"/><polyline points="3 9 12 3 21 9"/></svg>
      </div>
      <span class="logo-name"><?= $biz_name ?></span>
    </div>
    <div class="hdr-title">Set Up Your Account</div>
    <div class="hdr-sub">You've been invited to join <?= $biz_name ?> as a team member.</div>
    <div class="role-badge">
      <?php if ($inv['role'] === 'cashier'): ?>
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:14px;height:14px;"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>
        Cashier Account
      <?php else: ?>
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:14px;height:14px;"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
        Staff Account
      <?php endif; ?>
      · <?= $biz_name ?>
    </div>
  </div>

  <div class="box-body">
    <?php if ($error): ?>
    <div class="err">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:14px;height:14px;flex-shrink:0;"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
      <?= htmlspecialchars($error) ?>
    </div>
    <?php endif; ?>

    <form method="POST">
      <div class="fg">
        <label>Full Name *</label>
        <div class="iw">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
          <input type="text" name="fullname" placeholder="Your full name"
            value="<?= htmlspecialchars($_POST['fullname'] ?? $inv['owner_name']) ?>" required>
        </div>
      </div>

      <div class="fg">
        <label>Email</label>
        <input type="email" value="<?= htmlspecialchars($inv['email']) ?>" readonly>
        <div class="hint">This is the email your invitation was sent to.</div>
      </div>

      <div class="fg">
        <label>Username *</label>
        <div class="iw">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
          <input type="text" name="username" placeholder="Choose a username"
            value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" required>
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

      <button type="submit" class="btn <?= htmlspecialchars($inv['role'] ?? 'staff') ?>">
        Create My Account & Sign In →
      </button>
      <p style="text-align:center;font-size:.75rem;color:#94a3b8;margin-top:12px;">
        By registering, you agree to PawnHub's terms of service.
      </p>
    </form>
  </div>
  <?php endif; ?>

</div>
</body>
</html>