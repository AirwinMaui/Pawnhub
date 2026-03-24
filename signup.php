<?php
session_start();
require 'db.php';
$error = $success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fullname   = trim($_POST['fullname']      ?? '');
    $email      = trim($_POST['email']         ?? '');
    $username   = trim($_POST['username']      ?? '');
    $pass       = trim($_POST['password']      ?? '');
    $conf       = trim($_POST['confirm']       ?? '');
    $biz_name   = trim($_POST['business_name'] ?? '');
    $phone      = trim($_POST['phone']         ?? '');
    $address    = trim($_POST['address']       ?? '');
    $plan       = in_array($_POST['plan']??'', ['Starter','Pro','Enterprise']) ? $_POST['plan'] : 'Starter';
    $branches   = intval($_POST['branches']    ?? 1);

    if (!$fullname || !$email || !$username || !$pass || !$biz_name) {
        $error = 'Please fill in all required fields.';
    } elseif ($pass !== $conf) {
        $error = 'Passwords do not match.';
    } elseif (strlen($pass) < 8) {
        $error = 'Password must be at least 8 characters.';
    } else {
        $chk = $pdo->prepare("SELECT id FROM users WHERE username=? OR email=?");
        $chk->execute([$username, $email]);
        if ($chk->fetch()) {
            $error = 'Username or email already exists.';
        } else {
            $pdo->beginTransaction();
            $pdo->prepare("INSERT INTO tenants (business_name,owner_name,email,phone,address,plan,branches,status) VALUES (?,?,?,?,?,?,?,'pending')")
                ->execute([$biz_name, $fullname, $email, $phone, $address, $plan, $branches]);
            $new_tid = $pdo->lastInsertId();
            $pdo->prepare("INSERT INTO users (tenant_id,fullname,email,username,password,role,status) VALUES (?,?,?,?,?,'admin','pending')")
                ->execute([$new_tid, $fullname, $email, $username, password_hash($pass, PASSWORD_BCRYPT)]);
            $pdo->commit();
            $success = true;
        }
    }
}

$plans = [
    'Starter'    => ['price' => 'Free',      'color' => '#475569', 'bg' => '#f1f5f9', 'border' => '#e2e8f0', 'branches' => 1,  'features' => ['1 Branch','Up to 3 Staff','Basic Reports','Email Support']],
    'Pro'        => ['price' => '₱999/mo',   'color' => '#1d4ed8', 'bg' => '#eff6ff', 'border' => '#bfdbfe', 'branches' => 3,  'features' => ['Up to 3 Branches','Unlimited Staff','Advanced Reports','Priority Support']],
    'Enterprise' => ['price' => '₱2,499/mo', 'color' => '#7c3aed', 'bg' => '#f3e8ff', 'border' => '#ddd6fe', 'branches' => 10, 'features' => ['Up to 10 Branches','Unlimited Everything','Custom Branding','Dedicated Support']],
];
$selected_plan = $_POST['plan'] ?? ($_GET['plan'] ?? 'Starter');
if (!array_key_exists($selected_plan, $plans)) $selected_plan = 'Starter';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1.0"/>
<title>PawnHub — Register Your Pawnshop</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet"/>
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet"/>
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

html { scroll-behavior: smooth; }
body {
  font-family: 'Inter', sans-serif;
  min-height: 100vh;
  background: linear-gradient(135deg, #0f172a 0%, #1e3a8a 50%, #0f172a 100%);
  background-attachment: fixed;
}

/* ── NAV ── */
.nav {
  position: sticky;
  top: 0;
  z-index: 50;
  height: 64px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 0 36px;
  background: rgba(15,23,42,0.70);
  backdrop-filter: blur(14px);
  -webkit-backdrop-filter: blur(14px);
  border-bottom: 1px solid rgba(255,255,255,0.08);
}
.nav-logo {
  display: flex; align-items: center; gap: 10px;
  text-decoration: none;
}
.nav-logo-icon {
  width: 34px; height: 34px;
  background: linear-gradient(135deg, #3b82f6, #8b5cf6);
  border-radius: 10px;
  display: flex; align-items: center; justify-content: center;
}
.nav-logo-text {
  font-size: 1.18rem; font-weight: 800;
  color: #fff; letter-spacing: -0.02em;
}
.nav-right {
  display: flex; align-items: center; gap: 10px;
}
.nav-signin {
  font-size: 0.82rem; font-weight: 600; color: rgba(255,255,255,0.65);
  text-decoration: none; padding: 7px 16px; border-radius: 8px;
  transition: all .2s;
}
.nav-signin:hover { color: #fff; background: rgba(255,255,255,0.08); }
.nav-login-btn {
  font-size: 0.82rem; font-weight: 700; color: #fff;
  text-decoration: none; padding: 7px 18px;
  background: rgba(37,99,235,0.85);
  border-radius: 8px; transition: all .2s;
}
.nav-login-btn:hover { background: #2563eb; }

/* ── WRAPPER ── */
.wrapper {
  width: 100%;
  max-width: 900px;
  margin: 0 auto;
  padding: 36px 20px 48px;
}

/* ── PAGE HEADER ── */
.page-header { text-align: center; margin-bottom: 28px; }
.page-header h1 {
  font-size: 1.65rem; font-weight: 800;
  color: #fff; margin-bottom: 6px; letter-spacing: -0.02em;
}
.page-header p { font-size: 0.86rem; color: rgba(255,255,255,0.55); }

/* ── PLAN CARDS ── */
.plans-row {
  display: grid;
  grid-template-columns: repeat(3,1fr);
  gap: 12px;
  margin-bottom: 22px;
}
.plan-card {
  background: #fff;
  border-radius: 14px;
  padding: 18px;
  cursor: pointer;
  border: 2.5px solid transparent;
  transition: all .2s;
  position: relative;
}
.plan-card:hover { transform: translateY(-2px); box-shadow: 0 8px 24px rgba(0,0,0,.18); }
.plan-card.selected {
  border-color: var(--plan-color);
  box-shadow: 0 0 0 4px color-mix(in srgb, var(--plan-color) 15%, transparent);
}
.plan-badge {
  display: inline-block;
  font-size: 0.63rem; font-weight: 700;
  padding: 2px 8px; border-radius: 100px;
  margin-bottom: 8px;
}
.plan-name { font-size: 0.93rem; font-weight: 800; color: #0f172a; margin-bottom: 2px; }
.plan-price { font-size: 1.2rem; font-weight: 800; margin-bottom: 10px; }
.plan-features { list-style: none; display: flex; flex-direction: column; gap: 5px; }
.plan-features li { font-size: 0.75rem; color: #475569; display: flex; align-items: center; gap: 6px; }
.plan-features li::before { content: '✓'; font-weight: 700; font-size: 0.71rem; }
.plan-check {
  position: absolute; top: 12px; right: 12px;
  width: 20px; height: 20px; border-radius: 50%;
  display: none; align-items: center; justify-content: center;
}
.plan-card.selected .plan-check { display: flex; }

/* ── FORM BOX ── */
.form-box {
  background: #fff;
  border-radius: 18px;
  padding: 28px 30px;
  box-shadow: 0 20px 60px rgba(0,0,0,.28);
}

.section-title {
  font-size: 0.7rem; font-weight: 700;
  letter-spacing: 0.08em; text-transform: uppercase;
  color: #94a3b8; margin-bottom: 14px;
  padding-bottom: 8px; border-bottom: 1px solid #f1f5f9;
}

.form-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 13px;
  margin-bottom: 14px;
}
.fg { margin-bottom: 0; }
.fg.full { grid-column: 1 / -1; }
.fg label {
  display: block; font-size: 0.73rem; font-weight: 600;
  color: #374151; margin-bottom: 4px;
}
.fg input, .fg select {
  width: 100%;
  border: 1.5px solid #e2e8f0;
  border-radius: 9px;
  padding: 9px 12px;
  font-family: 'Inter', sans-serif;
  font-size: 0.85rem; color: #0f172a;
  outline: none;
  transition: border .2s, box-shadow .2s;
  background: #fff;
}
.fg input:focus, .fg select:focus {
  border-color: #2563eb;
  box-shadow: 0 0 0 3px rgba(37,99,235,.1);
}
.fg input::placeholder { color: #c8d0db; }

/* ERROR */
.err {
  background: #fef2f2; border: 1px solid #fecaca;
  border-radius: 10px; padding: 10px 13px;
  font-size: 0.8rem; color: #dc2626;
  margin-bottom: 16px;
  display: flex; align-items: center; gap: 8px;
}
.err svg { width: 15px; height: 15px; flex-shrink: 0; }

/* PLAN SUMMARY */
.plan-summary {
  background: #f8fafc; border: 1.5px solid #e2e8f0;
  border-radius: 10px; padding: 11px 15px;
  margin: 14px 0;
  display: flex; align-items: center; justify-content: space-between;
  font-size: 0.81rem;
}

/* INFO BOX */
.info-box {
  background: #eff6ff; border: 1px solid #bfdbfe;
  border-radius: 9px; padding: 10px 13px;
  font-size: 0.77rem; color: #1d4ed8;
  margin-bottom: 16px; line-height: 1.7;
}

/* SUBMIT */
.submit-btn {
  width: 100%;
  background: linear-gradient(135deg, #1e3a8a, #2563eb);
  color: #fff; border: none; border-radius: 10px;
  padding: 13px; font-family: 'Inter', sans-serif;
  font-size: 0.94rem; font-weight: 700; cursor: pointer;
  box-shadow: 0 4px 14px rgba(30,58,138,.3);
  transition: all .2s; margin-top: 4px;
}
.submit-btn:hover { transform: translateY(-1px); box-shadow: 0 6px 20px rgba(30,58,138,.4); }

/* FOOTER */
.page-footer {
  text-align: center;
  font-size: 0.79rem;
  color: rgba(255,255,255,.45);
  margin-top: 16px;
}
.page-footer a { color: #93c5fd; font-weight: 600; text-decoration: none; }
.page-footer a:hover { text-decoration: underline; }

/* SUCCESS */
.success-box {
  background: #fff; border-radius: 18px;
  padding: 48px 32px; text-align: center;
  box-shadow: 0 20px 60px rgba(0,0,0,.28);
}
.success-icon {
  width: 72px; height: 72px;
  background: #dcfce7; border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  margin: 0 auto 18px;
}
.success-icon svg { width: 34px; height: 34px; color: #15803d; }
.success-title { font-size: 1.3rem; font-weight: 800; color: #0f172a; margin-bottom: 10px; }
.success-body { font-size: 0.86rem; color: #64748b; line-height: 1.85; margin-bottom: 20px; }
.success-note {
  background: #f0fdf4; border: 1px solid #bbf7d0;
  border-radius: 10px; padding: 13px;
  font-size: 0.81rem; color: #15803d;
  margin-bottom: 22px;
}
.success-btn {
  display: inline-block;
  background: linear-gradient(135deg, #1e3a8a, #2563eb);
  color: #fff; text-decoration: none;
  padding: 12px 28px; border-radius: 10px;
  font-size: 0.9rem; font-weight: 700;
}

@media (max-width: 700px) {
  .plans-row { grid-template-columns: 1fr; }
  .form-grid { grid-template-columns: 1fr; }
  .nav { padding: 0 18px; }
  .wrapper { padding: 24px 14px 40px; }
  .form-box { padding: 22px 18px; }
}
</style>
</head>
<body>

<!-- NAV -->
<header class="nav">
  <a href="home.php" class="nav-logo">
    <div class="nav-logo-icon">
      <svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2.5" style="width:16px;height:16px;">
        <rect x="3" y="9" width="18" height="12"/>
        <polyline points="3 9 12 3 21 9"/>
      </svg>
    </div>
    <span class="nav-logo-text">PawnHub</span>
  </a>
  <div class="nav-right">
    <a href="login.php" class="nav-signin">Sign In</a>
    <a href="login.php" class="nav-login-btn">Go to Login →</a>
  </div>
</header>

<div class="wrapper">

  <!-- PAGE HEADER -->
  <div class="page-header">
    <h1>Register Your Pawnshop</h1>
    <p>Choose a plan and create your account. Your application will be reviewed by our team.</p>
  </div>

  <?php if ($success): ?>
  <!-- SUCCESS STATE -->
  <div class="success-box">
    <div class="success-icon">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
        <circle cx="12" cy="12" r="10"/>
        <polyline points="9 12 11 14 15 10"/>
      </svg>
    </div>
    <div class="success-title">Application Submitted! 🎉</div>
    <p class="success-body">
      Your pawnshop registration has been submitted successfully.<br>
      Our Super Admin will review and approve your account.<br><br>
      <strong>Once approved, you can login using your username and password.</strong><br>
      You will be notified via email.
    </p>
    <div class="success-note">
      ✅ No need to wait for an invitation link — your account is ready once approved!
    </div>
    <a href="login.php" class="success-btn">Go to Login →</a>
  </div>

  <?php else: ?>

  <!-- PLAN SELECTION -->
  <div class="plans-row">
    <?php foreach ($plans as $pname => $pdata): ?>
    <div class="plan-card <?= $selected_plan === $pname ? 'selected' : '' ?>"
         style="--plan-color:<?= $pdata['color'] ?>;"
         onclick="selectPlan('<?= $pname ?>', this)">
      <span class="plan-badge" style="background:<?= $pdata['bg'] ?>;color:<?= $pdata['color'] ?>;border:1px solid <?= $pdata['border'] ?>;"><?= $pname ?></span>
      <div class="plan-name"><?= $pname ?> Plan</div>
      <div class="plan-price" style="color:<?= $pdata['color'] ?>"><?= $pdata['price'] ?></div>
      <ul class="plan-features">
        <?php foreach ($pdata['features'] as $f): ?>
        <li style="color:<?= $pdata['color'] ?>"><?= $f ?></li>
        <?php endforeach; ?>
      </ul>
      <div class="plan-check" style="background:<?= $pdata['color'] ?>;">
        <svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="3" style="width:11px;height:11px;"><polyline points="20 6 9 17 4 12"/></svg>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- REGISTRATION FORM -->
  <div class="form-box">

    <?php if ($error): ?>
    <div class="err">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <circle cx="12" cy="12" r="10"/>
        <line x1="12" y1="8" x2="12" y2="12"/>
        <line x1="12" y1="16" x2="12.01" y2="16"/>
      </svg>
      <?= htmlspecialchars($error) ?>
    </div>
    <?php endif; ?>

    <form method="POST" id="regForm">
      <input type="hidden" name="plan"     id="plan_input"     value="<?= htmlspecialchars($selected_plan) ?>">
      <input type="hidden" name="branches" id="branches_input" value="<?= $plans[$selected_plan]['branches'] ?>">

      <!-- BUSINESS INFO -->
      <div class="section-title">🏢 Business Information</div>
      <div class="form-grid">
        <div class="fg full">
          <label>Business Name *</label>
          <input type="text" name="business_name" placeholder="e.g. GoldKing Pawnshop"
            value="<?= htmlspecialchars($_POST['business_name'] ?? '') ?>" required>
        </div>
        <div class="fg">
          <label>Phone Number</label>
          <input type="text" name="phone" placeholder="09XXXXXXXXX"
            value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>">
        </div>
        <div class="fg">
          <label>Address</label>
          <input type="text" name="address" placeholder="Street, City, Province"
            value="<?= htmlspecialchars($_POST['address'] ?? '') ?>">
        </div>
      </div>

      <!-- OWNER / ACCOUNT INFO -->
      <div class="section-title" style="margin-top:20px;">👤 Owner / Account Information</div>
      <div class="form-grid">
        <div class="fg full">
          <label>Full Name *</label>
          <input type="text" name="fullname" placeholder="Juan Dela Cruz"
            value="<?= htmlspecialchars($_POST['fullname'] ?? '') ?>" required>
        </div>
        <div class="fg">
          <label>Email Address *</label>
          <input type="email" name="email" placeholder="owner@example.com"
            value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required>
        </div>
        <div class="fg">
          <label>Username *</label>
          <input type="text" name="username" placeholder="yourUsername"
            value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" required>
        </div>
        <div class="fg">
          <label>Password * <span style="font-weight:400;color:#94a3b8;">(min. 8 characters)</span></label>
          <input type="password" name="password" placeholder="Strong password" required>
        </div>
        <div class="fg">
          <label>Confirm Password *</label>
          <input type="password" name="confirm" placeholder="Repeat password" required>
        </div>
      </div>

      <!-- PLAN SUMMARY -->
      <div class="plan-summary">
        <div>
          <span style="font-weight:700;color:#0f172a;">Selected Plan: </span>
          <span id="summary_plan" style="font-weight:800;color:#2563eb;"><?= htmlspecialchars($selected_plan) ?></span>
        </div>
        <div style="color:#64748b;">
          <span id="summary_branches"><?= $plans[$selected_plan]['branches'] ?></span>
          branch<?= $plans[$selected_plan]['branches'] > 1 ? 'es' : '' ?>
        </div>
      </div>

      <!-- INFO NOTE -->
      <div class="info-box">
        ℹ️ After submission, the Super Admin will review your application. Once approved, you can login immediately with your username and password — <strong>no invitation email needed!</strong>
      </div>

      <button type="submit" class="submit-btn">Submit Application →</button>
    </form>
  </div>

  <?php endif; ?>

  <div class="page-footer">
    Already have an account? <a href="login.php">Sign in here</a>
  </div>

</div><!-- /wrapper -->

<script>
const plans = <?= json_encode($plans) ?>;

function selectPlan(name, el) {
  document.getElementById('plan_input').value     = name;
  document.getElementById('branches_input').value = plans[name].branches;
  document.querySelectorAll('.plan-card').forEach(c => c.classList.remove('selected'));
  el.classList.add('selected');
  document.getElementById('summary_plan').textContent = name;
  document.getElementById('summary_plan').style.color = plans[name].color;
  const b = plans[name].branches;
  document.getElementById('summary_branches').textContent = b + ' branch' + (b > 1 ? 'es' : '');
}
</script>
</body>
</html>