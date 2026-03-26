<?php
session_start();
require 'db.php';

$token   = trim($_GET['token'] ?? '');
$error   = '';
$success = false;
$inv     = null;

// ── Validate token ─────────────────────────────────────────────
if (!$token) {
    $error = 'Invalid or missing invitation link.';
} else {
    $stmt = $pdo->prepare("
        SELECT i.*, t.business_name, t.plan, t.id AS tenant_id, t.slug
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
        $pdo->prepare("UPDATE tenant_invitations SET status='expired' WHERE token=?")->execute([$token]);
        $error = 'This invitation link has expired. Please contact your Super Admin to resend.';
    }
}

// ── Handle POST ────────────────────────────────────────────────
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
            try {
                $sa_stmt = $pdo->query("SELECT id FROM users WHERE role='super_admin' LIMIT 1");
                $sa_row  = $sa_stmt->fetch();
                $sa_id   = $sa_row ? $sa_row['id'] : null;

                $pdo->beginTransaction();

                // 1. Create admin user — auto approved (super admin invited them)
                $pdo->prepare("
                    INSERT INTO users (tenant_id, fullname, email, username, password, role, status, approved_by, approved_at)
                    VALUES (?, ?, ?, ?, ?, 'admin', 'approved', ?, NOW())
                ")->execute([
                    $inv['tenant_id'],
                    $fullname,
                    $inv['email'],
                    $username,
                    password_hash($password, PASSWORD_BCRYPT),
                    $sa_id,
                ]);

                // 2. Activate tenant
                $pdo->prepare("UPDATE tenants SET status='active', owner_name=? WHERE id=?")
                    ->execute([$fullname, $inv['tenant_id']]);

                // 3. Mark invitation as used
                $pdo->prepare("UPDATE tenant_invitations SET status='used', used_at=NOW() WHERE token=?")
                    ->execute([$token]);

                $pdo->commit();

                // 4. Send welcome email with login URL
                try {
                    require_once __DIR__ . '/mailer.php';
                    if (!empty($inv['slug'])) {
                        sendTenantWelcome($inv['email'], $fullname, $inv['business_name'], $inv['slug']);
                    }
                } catch (Throwable $e) {
                    error_log('Welcome email failed: ' . $e->getMessage());
                }

                $success = true;

            } catch (PDOException $e) {
                $pdo->rollBack();
                $error = 'Registration failed. Please try again.';
                error_log('tenant_register error: ' . $e->getMessage());
            }
        }
    }
}

$redirect_url = '/tenant.php';
if ($success && !empty($inv['slug'])) {
    $redirect_url = '/' . $inv['slug'];
}
if ($success) {
    header('refresh:2;url=' . $redirect_url);
}

$biz_name = htmlspecialchars($inv['business_name'] ?? 'PawnHub');
$plan     = htmlspecialchars($inv['plan'] ?? 'Starter');
$email    = htmlspecialchars($inv['email'] ?? '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>PawnHub — Complete Your Registration</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet">
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
html, body { height: 100%; font-family: 'Inter', sans-serif; }
body {
    min-height: 100vh;
    background-image: linear-gradient(rgba(0,0,0,0.60), rgba(0,0,0,0.70)),
        url('https://lh3.googleusercontent.com/aida-public/AB6AXuCx2DpF3DhIT8TMkI77WjrdPvL6YVSpVpWmOEXSGYEKlgSNatvfUPOuV3QNXsel_47FDOEDJ99WDIO4ESDYlrYK-ERBoWVC3c-LXv1bOADmUcIWror3a9k9pousLqJjChv08FrIrBVwj8x-1jR1uBrrxeP6SIDEKNxL1OxGXCIGuHnIVKd8KPfKebyipejNKaBy12kucRMfr0_Og_bv9bc1_Ikfu9Airs60mBJVLIZs4vDeoJzDvCWs3p9cdGZ4TtDQqH6R7tA3fHI');
    background-size: cover;
    background-position: center;
    background-attachment: fixed;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 32px 16px;
    color: #fff;
}
.topnav {
    position: fixed; top: 0; left: 0; right: 0; z-index: 50;
    display: flex; align-items: center; justify-content: space-between;
    padding: 18px 36px;
    background: rgba(0,0,0,0.25);
    backdrop-filter: blur(14px);
    border-bottom: 1px solid rgba(255,255,255,0.07);
}
.nav-brand { display: flex; align-items: center; gap: 9px; text-decoration: none; }
.nav-logo-icon {
    width: 32px; height: 32px;
    background: linear-gradient(135deg, #2563eb, #7c3aed);
    border-radius: 9px;
    display: flex; align-items: center; justify-content: center;
}
.nav-logo-icon svg { width: 15px; height: 15px; }
.nav-brand-name { font-size: 1.1rem; font-weight: 800; color: #fff; }
.card {
    width: 100%; max-width: 460px;
    background: rgba(255,255,255,0.10);
    backdrop-filter: blur(28px);
    -webkit-backdrop-filter: blur(28px);
    border: 1px solid rgba(255,255,255,0.15);
    border-radius: 20px;
    padding: 36px 34px 28px;
    box-shadow: 0 24px 64px rgba(0,0,0,0.4);
    margin-top: 72px;
}
.tenant-badge {
    display: inline-flex; align-items: center; gap: 6px;
    background: rgba(37,99,235,0.25);
    border: 1px solid rgba(37,99,235,0.4);
    border-radius: 8px;
    padding: 5px 11px;
    font-size: 0.74rem; font-weight: 700; color: #93c5fd;
    margin-bottom: 14px;
}
.plan-chip {
    background: rgba(139,92,246,0.25);
    border: 1px solid rgba(139,92,246,0.35);
    border-radius: 100px;
    padding: 1px 8px;
    font-size: 0.65rem; font-weight: 700; color: #c4b5fd;
    margin-left: 4px;
}
.card-title { font-size: 1.5rem; font-weight: 800; color: #fff; letter-spacing: -.03em; margin-bottom: 5px; }
.card-sub { font-size: 0.81rem; color: rgba(255,255,255,0.45); margin-bottom: 22px; line-height: 1.55; }
.fg { margin-bottom: 13px; }
.flabel {
    display: block; font-size: 0.66rem; font-weight: 700;
    text-transform: uppercase; letter-spacing: 0.09em;
    color: rgba(255,255,255,0.45); margin-bottom: 5px;
}
.finput {
    width: 100%;
    background: rgba(255,255,255,0.08);
    border: 1.5px solid rgba(255,255,255,0.12);
    border-radius: 10px;
    padding: 11px 14px;
    font-family: 'Inter', sans-serif;
    font-size: 0.86rem; color: #fff;
    outline: none;
    transition: all .2s;
}
.finput:focus { background: rgba(255,255,255,0.13); border-color: #3b82f6; box-shadow: 0 0 0 3px rgba(59,130,246,0.2); }
.finput::placeholder { color: rgba(255,255,255,0.22); }
.finput[readonly] { background: rgba(255,255,255,0.04); color: rgba(255,255,255,0.3); cursor: not-allowed; }
.pw-wrap { position: relative; }
.pw-wrap .finput { padding-right: 42px; }
.pw-btn {
    position: absolute; right: 11px; top: 50%; transform: translateY(-50%);
    background: none; border: none; cursor: pointer;
    color: rgba(255,255,255,0.3); display: flex; align-items: center;
    transition: color .2s; padding: 0;
}
.pw-btn:hover { color: rgba(255,255,255,0.7); }
.pw-btn .material-symbols-outlined { font-size: 18px; font-variation-settings: 'FILL' 0,'wght' 400,'GRAD' 0,'opsz' 24; }
.grid2 { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
.alert-err {
    background: rgba(220,38,38,0.15); border: 1px solid rgba(220,38,38,0.3);
    border-radius: 10px; padding: 10px 14px;
    font-size: 0.81rem; color: #fca5a5;
    display: flex; align-items: center; gap: 8px;
    margin-bottom: 16px;
}
.alert-err .material-symbols-outlined { font-size: 16px; flex-shrink: 0; font-variation-settings: 'FILL' 0,'wght' 400,'GRAD' 0,'opsz' 24; }
.btn-submit {
    width: 100%;
    background: linear-gradient(135deg, #2563eb, #1d4ed8);
    color: #fff; border: none; border-radius: 11px;
    padding: 13px; font-family: 'Inter', sans-serif;
    font-size: 0.91rem; font-weight: 700; cursor: pointer;
    box-shadow: 0 4px 18px rgba(37,99,235,0.35);
    transition: all .2s; margin-top: 4px;
}
.btn-submit:hover { transform: translateY(-1px); filter: brightness(1.08); }
.btn-submit:active { transform: scale(.98); }
.state-box { text-align: center; padding: 10px 0; }
.state-icon {
    width: 68px; height: 68px; border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    margin: 0 auto 16px;
}
.state-icon.ok { background: rgba(34,197,94,0.15); }
.state-icon.err { background: rgba(220,38,38,0.15); }
.state-icon .material-symbols-outlined { font-size: 34px; font-variation-settings: 'FILL' 1,'wght' 400,'GRAD' 0,'opsz' 24; }
.state-icon.ok .material-symbols-outlined { color: #22c55e; }
.state-icon.err .material-symbols-outlined { color: #f87171; }
.state-title { font-size: 1.25rem; font-weight: 800; color: #fff; margin-bottom: 8px; }
.state-sub { font-size: 0.84rem; color: rgba(255,255,255,0.5); line-height: 1.65; margin-bottom: 18px; }
.state-redirect {
    background: rgba(34,197,94,0.1); border: 1px solid rgba(34,197,94,0.25);
    border-radius: 10px; padding: 12px 16px;
    font-size: 0.8rem; color: #86efac;
}
.state-redirect a { color: #93c5fd; font-weight: 600; }
.btn-back {
    display: inline-block; background: #2563eb; color: #fff;
    text-decoration: none; padding: 11px 28px; border-radius: 10px;
    font-size: 0.88rem; font-weight: 700; margin-top: 6px;
}
.card-foot {
    margin-top: 16px; padding-top: 14px;
    border-top: 1px solid rgba(255,255,255,0.08);
    text-align: center; font-size: 0.78rem; color: rgba(255,255,255,0.3);
}
.card-foot a { color: #60a5fa; font-weight: 600; text-decoration: none; }
.page-footer { margin-top: 20px; font-size: 0.64rem; color: rgba(255,255,255,0.2); }
@media (max-width: 520px) {
    .card { padding: 26px 20px 22px; margin-top: 76px; }
    .grid2 { grid-template-columns: 1fr; }
}
</style>
</head>
<body>

<header class="topnav">
    <a href="home.php" class="nav-brand">
        <div class="nav-logo-icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2.5">
                <rect x="3" y="9" width="18" height="12"/><polyline points="3 9 12 3 21 9"/>
            </svg>
        </div>
        <span class="nav-brand-name">PawnHub</span>
    </a>
    <span style="font-size:0.63rem;font-weight:600;text-transform:uppercase;letter-spacing:0.12em;color:rgba(255,255,255,0.4);">Security Protocol Active</span>
</header>

<div class="card">

    <?php if ($error): ?>
    <div class="state-box">
        <div class="state-icon err"><span class="material-symbols-outlined">cancel</span></div>
        <div class="state-title">Invalid Invitation</div>
        <p class="state-sub"><?= htmlspecialchars($error) ?></p>
        <a href="login.php" class="btn-back">Back to Login</a>
    </div>

    <?php elseif ($success): ?>
    <div class="state-box">
        <div class="state-icon ok"><span class="material-symbols-outlined">check_circle</span></div>
        <div class="state-title">Welcome to PawnHub! 🎉</div>
        <p class="state-sub">
            Your account is ready and <strong style="color:rgba(255,255,255,0.8);"><?= $biz_name ?></strong> is now active.<br><br>
            Redirecting to your dashboard...
        </p>
        <div class="state-redirect">
            ⏳ Redirecting in 2 seconds...<br>
            <a href="<?= htmlspecialchars($redirect_url) ?>">Click here if not redirected</a>
        </div>
    </div>

    <?php else: ?>
    <div>
        <div class="tenant-badge">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:12px;height:12px;">
                <rect x="3" y="9" width="18" height="12"/><polyline points="3 9 12 3 21 9"/>
            </svg>
            <?= $biz_name ?><span class="plan-chip"><?= $plan ?></span>
        </div>
    </div>

    <h1 class="card-title">Complete Your Registration</h1>
    <p class="card-sub">You've been invited to join PawnHub. Set up your login credentials below.</p>

    <?php if ($error): ?>
    <div class="alert-err">
        <span class="material-symbols-outlined">error</span>
        <?= htmlspecialchars($error) ?>
    </div>
    <?php endif; ?>

    <form method="POST">

        <div class="fg">
            <label class="flabel">Full Name *</label>
            <input type="text" name="fullname" class="finput"
                placeholder="Your full name"
                value="<?= htmlspecialchars($_POST['fullname'] ?? $inv['owner_name']) ?>"
                required>
        </div>

        <div class="fg">
            <label class="flabel">Email</label>
            <input type="email" class="finput" value="<?= $email ?>" readonly>
            <div style="font-size:0.68rem;color:rgba(255,255,255,0.25);margin-top:4px;">Your invitation email address.</div>
        </div>

        <div class="fg">
            <label class="flabel">Username *</label>
            <input type="text" name="username" class="finput"
                placeholder="Choose a username"
                value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
                required>
        </div>

        <div class="grid2">
            <div class="fg">
                <label class="flabel">Password * (min. 8)</label>
                <div class="pw-wrap">
                    <input type="password" name="password" id="pw1" class="finput" placeholder="••••••••" required>
                    <button type="button" class="pw-btn"
                        onclick="const f=document.getElementById('pw1');f.type=f.type==='password'?'text':'password';this.querySelector('span').textContent=f.type==='password'?'visibility':'visibility_off'">
                        <span class="material-symbols-outlined">visibility</span>
                    </button>
                </div>
            </div>
            <div class="fg">
                <label class="flabel">Confirm Password *</label>
                <div class="pw-wrap">
                    <input type="password" name="confirm" id="pw2" class="finput" placeholder="••••••••" required>
                    <button type="button" class="pw-btn"
                        onclick="const f=document.getElementById('pw2');f.type=f.type==='password'?'text':'password';this.querySelector('span').textContent=f.type==='password'?'visibility':'visibility_off'">
                        <span class="material-symbols-outlined">visibility</span>
                    </button>
                </div>
            </div>
        </div>

        <button type="submit" class="btn-submit">
            Create My Account & Access System →
        </button>
    </form>

    <div class="card-foot">
        Already registered? <a href="login.php">Sign in here</a>
    </div>
    <?php endif; ?>

</div>

<div class="page-footer">© <?= date('Y') ?> PawnHub. All rights reserved.</div>

</body>
</html>