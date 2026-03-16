<?php
session_start();
require 'db.php';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if ($username && $password) {
        $stmt = $pdo->prepare("
            SELECT u.*, t.business_name as tenant_name
            FROM users u
            LEFT JOIN tenants t ON u.tenant_id = t.id
            WHERE u.username = ? AND u.status = 'approved' AND u.is_suspended = 0
            LIMIT 1
        ");
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {
            $_SESSION['user'] = [
                'id'          => $user['id'],
                'name'        => $user['fullname'],
                'username'    => $user['username'],
                'role'        => $user['role'],
                'tenant_id'   => $user['tenant_id'],
                'tenant_name' => $user['tenant_name'],
            ];
            switch ($user['role']) {
                case 'super_admin': header('Location: superadmin.php'); break;
                case 'admin':       header('Location: tenant.php');     break;
                case 'staff':       header('Location: staff.php');      break;
                case 'cashier':     header('Location: cashier.php');    break;
                default:            header('Location: login.php');
            }
            exit;
        } else {
            $chk = $pdo->prepare("SELECT status, is_suspended FROM users WHERE username = ? LIMIT 1");
            $chk->execute([$username]);
            $chk = $chk->fetch();
            if (!$chk)                    $error = 'Username not found.';
            elseif ($chk['is_suspended']) $error = 'Your account has been suspended.';
            elseif ($chk['status'] === 'pending')  $error = 'Your account is pending approval.';
            elseif ($chk['status'] === 'rejected') $error = 'Your account was rejected.';
            else                          $error = 'Incorrect password.';
        }
    } else {
        $error = 'Please fill in all fields.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>PawnHub — Login</title>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'Plus Jakarta Sans',sans-serif;min-height:100vh;display:flex;}
.left{width:45%;min-height:100vh;background:linear-gradient(155deg,#0f172a,#1e3a8a,#1e293b);display:flex;flex-direction:column;justify-content:center;padding:50px;position:relative;overflow:hidden;}
.left::before{content:'';position:absolute;inset:0;background:url('https://images.unsplash.com/photo-1611974789855-9c2a0a7236a3?w=800&q=60') center/cover;opacity:.07;}
.lp{position:relative;z-index:1;}
.logo{display:flex;align-items:center;gap:12px;margin-bottom:48px;}
.logo-icon{width:44px;height:44px;background:linear-gradient(135deg,#3b82f6,#8b5cf6);border-radius:12px;display:flex;align-items:center;justify-content:center;}
.logo-icon svg{width:22px;height:22px;}
.logo-text{font-size:1.4rem;font-weight:800;color:#fff;}
h1{font-size:2rem;font-weight:800;color:#fff;line-height:1.3;margin-bottom:14px;}
h1 span{color:#60a5fa;}
.sub{font-size:.88rem;color:rgba(255,255,255,.5);line-height:1.7;}
.feats{margin-top:40px;display:flex;flex-direction:column;gap:13px;}
.feat{display:flex;align-items:center;gap:11px;color:rgba(255,255,255,.7);font-size:.84rem;}
.feat-icon{width:30px;height:30px;border-radius:8px;background:rgba(255,255,255,.08);display:flex;align-items:center;justify-content:center;flex-shrink:0;}
.feat-icon svg{width:14px;height:14px;}
.right{flex:1;display:flex;align-items:center;justify-content:center;background:#f8fafc;padding:40px;}
.box{width:100%;max-width:400px;}
.title{font-size:1.45rem;font-weight:800;color:#0f172a;margin-bottom:5px;}
.desc{font-size:.84rem;color:#64748b;margin-bottom:30px;}
.fg{margin-bottom:17px;}
.fg label{display:block;font-size:.77rem;font-weight:600;color:#374151;margin-bottom:5px;}
.iw{position:relative;}
.iw>svg{position:absolute;left:12px;top:50%;transform:translateY(-50%);width:15px;height:15px;color:#94a3b8;}
.finput{width:100%;border:1.5px solid #e2e8f0;border-radius:10px;padding:11px 14px 11px 38px;font-family:inherit;font-size:.89rem;color:#0f172a;outline:none;background:#fff;transition:border .2s,box-shadow .2s;}
.finput:focus{border-color:#2563eb;box-shadow:0 0 0 3px rgba(37,99,235,.1);}
.finput::placeholder{color:#c8d0db;}
.toggle{position:absolute;right:11px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:#94a3b8;}
.toggle svg{width:15px;height:15px;}
.err{background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:10px 13px;font-size:.81rem;color:#dc2626;margin-bottom:17px;display:flex;align-items:center;gap:7px;}
.err svg{width:14px;height:14px;flex-shrink:0;}
.btn{width:100%;background:linear-gradient(135deg,#2563eb,#1d4ed8);color:#fff;border:none;border-radius:10px;padding:13px;font-family:inherit;font-size:.94rem;font-weight:700;cursor:pointer;box-shadow:0 4px 14px rgba(37,99,235,.3);transition:all .2s;margin-top:4px;}
.btn:hover{transform:translateY(-1px);box-shadow:0 6px 20px rgba(37,99,235,.4);}
.footer{margin-top:22px;text-align:center;font-size:.77rem;color:#94a3b8;}
.footer a{color:#2563eb;font-weight:600;text-decoration:none;}
.badges{display:flex;gap:8px;justify-content:center;margin-top:18px;flex-wrap:wrap;}
.badge{font-size:.65rem;font-weight:600;color:#94a3b8;background:#f1f5f9;padding:3px 9px;border-radius:100px;border:1px solid #e2e8f0;}
@media(max-width:768px){.left{display:none;}.right{padding:22px;}}
</style>
</head>
<body>
<div class="left">
  <div class="lp">
    <div class="logo"><div class="logo-icon"><svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2.5"><rect x="3" y="9" width="18" height="12"/><polyline points="3 9 12 3 21 9"/></svg></div><span class="logo-text">PawnHub</span></div>
    <h1>Multi-Tenant<br>Pawnshop <span>Management</span></h1>
    <p class="sub">One platform for all your pawnshop branches — manage tickets, loans, and staff with ease.</p>
    <div class="feats">
      <div class="feat"><div class="feat-icon"><svg viewBox="0 0 24 24" fill="none" stroke="#60a5fa" stroke-width="2"><path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/></svg></div>Pawn Ticket Management</div>
      <div class="feat"><div class="feat-icon"><svg viewBox="0 0 24 24" fill="none" stroke="#34d399" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg></div>Multi-Tenant Support</div>
      <div class="feat"><div class="feat-icon"><svg viewBox="0 0 24 24" fill="none" stroke="#f472b6" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg></div>Role-Based Access Control</div>
    </div>
  </div>
</div>
<div class="right">
  <div class="box">
    <div class="title">Welcome back 👋</div>
    <div class="desc">Sign in to your PawnHub account</div>
    <?php if($error): ?>
    <div class="err"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg><?=htmlspecialchars($error)?></div>
    <?php endif; ?>
    <form method="POST">
      <div class="fg"><label>Username</label>
        <div class="iw"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
        <input type="text" name="username" class="finput" placeholder="Enter your username" value="<?=htmlspecialchars($_POST['username']??'')?>" required></div></div>
      <div class="fg"><label>Password</label>
        <div class="iw"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
        <input type="password" name="password" id="pw" class="finput" placeholder="Enter your password" required>
        <button type="button" class="toggle" onclick="const f=document.getElementById('pw');f.type=f.type==='password'?'text':'password'"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg></button>
        </div></div>
      <button type="submit" class="btn">Sign In</button>
    </form>
    <div class="footer">Don't have an account? <a href="signup.php">Apply for access</a></div>
    <div class="badges"><span class="badge">🔒 SSL Secured</span><span class="badge">📋 BSP Compliant</span><span class="badge">🛡️ Data Protected</span></div>
  </div>
</div>
</body>
</html>