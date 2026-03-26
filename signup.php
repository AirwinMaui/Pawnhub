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
    $plan       = in_array($_POST['plan'] ?? '', ['Starter','Pro','Enterprise']) ? $_POST['plan'] : 'Starter';
    $branches   = intval($_POST['branches'] ?? 1);

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
    'Starter'    => ['branches' => 1],
    'Pro'        => ['branches' => 3],
    'Enterprise' => ['branches' => 10],
];
$selected_plan = $_POST['plan'] ?? ($_GET['plan'] ?? 'Starter');
if (!array_key_exists($selected_plan, $plans)) $selected_plan = 'Starter';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8"/>
<meta content="width=device-width, initial-scale=1.0" name="viewport"/>
<title>PawnHub — Register Your Pawnshop</title>
<script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet"/>
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet"/>
<style>
body { font-family: "Inter", sans-serif; }
.material-symbols-outlined { font-variation-settings: "FILL" 0, "wght" 400, "GRAD" 0, "opsz" 24; }
.glass-panel {
    background: rgba(0,0,0,0.5);
    backdrop-filter: blur(24px);
    -webkit-backdrop-filter: blur(24px);
    border: 1px solid rgba(255,255,255,0.1);
}
.glass-input {
    width: 100%;
    background: rgba(255,255,255,0.08);
    border: 1px solid rgba(255,255,255,0.12);
    border-radius: 12px;
    padding: 12px 16px;
    color: #fff;
    font-family: "Inter", sans-serif;
    font-size: 0.875rem;
    outline: none;
    transition: all 0.2s;
}
.glass-input:focus {
    background: rgba(255,255,255,0.13);
    border-color: rgba(59,130,246,0.6);
    box-shadow: 0 0 0 3px rgba(59,130,246,0.15);
}
.glass-input::placeholder { color: rgba(255,255,255,0.35); }
.glass-input option { background: #1e293b; color: #fff; }
.plan-pill {
    cursor: pointer;
    padding: 6px 16px;
    border-radius: 100px;
    font-size: 0.78rem;
    font-weight: 700;
    border: 1.5px solid rgba(255,255,255,0.15);
    color: rgba(255,255,255,0.5);
    background: rgba(255,255,255,0.06);
    transition: all 0.2s;
}
.plan-pill.active {
    background: #3b82f6;
    border-color: #3b82f6;
    color: #fff;
}
.hero-bg {
    background-image: linear-gradient(rgba(0,0,0,0.55), rgba(0,0,0,0.65)),
        url('https://lh3.googleusercontent.com/aida-public/AB6AXuDVdOMy67RcI3OmEXQ5Ob4N9qbUXkHC8UCa3Ni6E2dPvn8N_9Kg_FuGSOcP4mhYkmmhNphJ8vQukLbFjfnVrv-wy716m8LpTRmRrql1K07LpfXVuqMeCMwQRftqZXZWikKdGhSBaHJEhrAn431mN9EQqELqupcBMhVrkknDFPIyVKW_l8bfki8PfvWSkOTQ129Z5jOMGF5My-stQnfPndc_y1X0jUHBEmlH0AVE04q2vpa87PHKNSxAOHabM4n8c9W6UcgA91Cs-1c');
    background-size: cover;
    background-position: center;
    background-attachment: fixed;
}
</style>
</head>
<body class="min-h-screen flex flex-col text-white hero-bg">

<!-- NAV -->
<header class="w-full sticky top-0 z-50" style="background:rgba(0,0,0,0.3);backdrop-filter:blur(16px);border-bottom:1px solid rgba(255,255,255,0.07);">
  <div class="flex justify-between items-center px-8 py-5 max-w-7xl mx-auto">
    <a href="index.php" class="flex items-center gap-2">
      <div class="w-8 h-8 bg-gradient-to-br from-blue-500 to-purple-600 rounded-lg flex items-center justify-center">
        <svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2.5" style="width:16px;height:16px;"><rect x="3" y="9" width="18" height="12"/><polyline points="3 9 12 3 21 9"/></svg>
      </div>
      <span class="text-xl font-bold tracking-tight text-white">PawnHub</span>
    </a>
    <a href="login.php" class="text-sm font-semibold text-white/70 hover:text-white transition-colors px-5 py-2 rounded-xl border border-white/15 hover:border-white/30" style="background:rgba(255,255,255,0.07);">
      Sign In
    </a>
  </div>
</header>

<main class="flex-grow flex items-center justify-center md:justify-end px-6 py-12 max-w-7xl mx-auto w-full">

  <?php if ($success): ?>
  <!-- SUCCESS STATE -->
  <div class="glass-panel w-full max-w-md p-10 rounded-3xl shadow-2xl text-center">
    <div style="width:72px;height:72px;background:rgba(34,197,94,0.15);border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 20px;">
      <span class="material-symbols-outlined" style="color:#22c55e;font-size:36px;">check_circle</span>
    </div>
    <h2 class="text-2xl font-extrabold text-white mb-3">Application Submitted! 🎉</h2>
    <p class="text-white/60 text-sm leading-relaxed mb-6">
      Your pawnshop registration has been submitted successfully.<br><br>
      Our Super Admin will review and approve your account.<br>
      Once approved, you can login using your username and password.
    </p>
    <div style="background:rgba(34,197,94,0.1);border:1px solid rgba(34,197,94,0.25);border-radius:10px;padding:12px 16px;font-size:0.8rem;color:#86efac;margin-bottom:24px;">
      📧 Once approved, you will receive an email with your personal login link. No invitation link needed!
    </div>
    <a href="login.php" style="display:inline-block;background:#3b82f6;color:#fff;text-decoration:none;padding:13px 32px;border-radius:12px;font-size:0.92rem;font-weight:700;transition:all 0.2s;" onmouseover="this.style.background='#2563eb'" onmouseout="this.style.background='#3b82f6'">
      Go to Login →
    </a>
  </div>

  <?php else: ?>
  <!-- REGISTRATION FORM -->
  <div class="glass-panel w-full max-w-lg p-8 md:p-10 rounded-3xl shadow-2xl">

    <!-- Header -->
    <div class="mb-8">
      <h1 class="text-3xl font-extrabold tracking-tight text-white mb-2">PawnHub Partnership</h1>
      <p class="text-white/60 text-sm leading-relaxed">Register your pawnshop to access the PawnHub ecosystem. Your application will be reviewed by our team.</p>
    </div>

    <!-- Plan Selector -->
    <div class="mb-7">
      <label class="block text-xs font-bold uppercase tracking-widest text-white/50 mb-3">Select Plan</label>
      <div class="flex gap-2">
        <button type="button" onclick="selectPlan('Starter')"   id="pill-Starter"    class="plan-pill <?= $selected_plan==='Starter'    ? 'active' : '' ?>">Starter — Free</button>
        <button type="button" onclick="selectPlan('Pro')"       id="pill-Pro"        class="plan-pill <?= $selected_plan==='Pro'        ? 'active' : '' ?>">Pro — ₱999/mo</button>
        <button type="button" onclick="selectPlan('Enterprise')" id="pill-Enterprise" class="plan-pill <?= $selected_plan==='Enterprise' ? 'active' : '' ?>">Enterprise — ₱2,499/mo</button>
      </div>
    </div>

    <!-- Error -->
    <?php if ($error): ?>
    <div style="background:rgba(220,38,38,0.15);border:1px solid rgba(220,38,38,0.3);border-radius:10px;padding:11px 14px;font-size:0.82rem;color:#fca5a5;margin-bottom:18px;display:flex;align-items:center;gap:8px;">
      <span class="material-symbols-outlined" style="font-size:16px;flex-shrink:0;">error</span>
      <?= htmlspecialchars($error) ?>
    </div>
    <?php endif; ?>

    <form method="POST" id="regForm">
      <input type="hidden" name="plan"     id="plan_input"     value="<?= htmlspecialchars($selected_plan) ?>">
      <input type="hidden" name="branches" id="branches_input" value="<?= $plans[$selected_plan]['branches'] ?>">

      <div class="space-y-5">

        <!-- Business Info -->
        <div>
          <label class="block text-xs font-bold uppercase tracking-widest text-white/50 mb-1.5 ml-1">Business Name *</label>
          <input type="text" name="business_name" class="glass-input" placeholder="e.g. GoldKing Pawnshop"
            value="<?= htmlspecialchars($_POST['business_name'] ?? '') ?>" required>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div>
            <label class="block text-xs font-bold uppercase tracking-widest text-white/50 mb-1.5 ml-1">Phone Number</label>
            <input type="text" name="phone" class="glass-input" placeholder="09XXXXXXXXX"
              value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>">
          </div>
          <div>
            <label class="block text-xs font-bold uppercase tracking-widest text-white/50 mb-1.5 ml-1">Address</label>
            <input type="text" name="address" class="glass-input" placeholder="City, Province"
              value="<?= htmlspecialchars($_POST['address'] ?? '') ?>">
          </div>
        </div>

        <div style="border-top:1px solid rgba(255,255,255,0.08);padding-top:18px;">
          <p class="text-xs font-bold uppercase tracking-widest text-white/50 mb-4">Owner / Account Information</p>

          <div class="space-y-4">
            <div>
              <label class="block text-xs font-bold uppercase tracking-widest text-white/50 mb-1.5 ml-1">Full Name *</label>
              <input type="text" name="fullname" class="glass-input" placeholder="Juan Dela Cruz"
                value="<?= htmlspecialchars($_POST['fullname'] ?? '') ?>" required>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div>
                <label class="block text-xs font-bold uppercase tracking-widest text-white/50 mb-1.5 ml-1">Email *</label>
                <input type="email" name="email" class="glass-input" placeholder="owner@example.com"
                  value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required>
              </div>
              <div>
                <label class="block text-xs font-bold uppercase tracking-widest text-white/50 mb-1.5 ml-1">Username *</label>
                <input type="text" name="username" class="glass-input" placeholder="yourUsername"
                  value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" required>
              </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div>
                <label class="block text-xs font-bold uppercase tracking-widest text-white/50 mb-1.5 ml-1">Password * (min. 8)</label>
                <input type="password" name="password" class="glass-input" placeholder="••••••••" required>
              </div>
              <div>
                <label class="block text-xs font-bold uppercase tracking-widest text-white/50 mb-1.5 ml-1">Confirm Password *</label>
                <input type="password" name="confirm" class="glass-input" placeholder="••••••••" required>
              </div>
            </div>
          </div>
        </div>

        <!-- Plan Summary -->
        <div id="plan_summary" style="background:rgba(59,130,246,0.1);border:1px solid rgba(59,130,246,0.25);border-radius:12px;padding:12px 16px;display:flex;align-items:center;justify-content:space-between;font-size:0.82rem;">
          <div style="color:rgba(255,255,255,0.7);">Selected Plan: <strong style="color:#93c5fd;" id="summary_plan"><?= htmlspecialchars($selected_plan) ?></strong></div>
          <div style="color:rgba(255,255,255,0.45);" id="summary_branches"><?= $plans[$selected_plan]['branches'] ?> branch<?= $plans[$selected_plan]['branches'] > 1 ? 'es' : '' ?></div>
        </div>

        <p class="text-xs text-white/35 italic leading-relaxed">
          ℹ️ After submission, the Super Admin will review your application. Once approved, you will receive an email with your personal login link — just use the username and password you set here!
        </p>

        <button type="submit" style="width:100%;padding:14px;background:#3b82f6;color:#fff;border:none;border-radius:12px;font-family:'Inter',sans-serif;font-size:0.95rem;font-weight:700;cursor:pointer;box-shadow:0 4px 20px rgba(59,130,246,0.3);transition:all 0.2s;"
          onmouseover="this.style.background='#2563eb';this.style.transform='translateY(-1px)'"
          onmouseout="this.style.background='#3b82f6';this.style.transform='translateY(0)'">
          Submit Application →
        </button>

      </div>
    </form>

    <div class="mt-8 pt-6 border-t text-center" style="border-color:rgba(255,255,255,0.08);">
      <p class="text-sm text-white/50">Already have an account? <a href="login.php" class="text-blue-400 font-bold hover:text-blue-300 transition-colors ml-1">Sign In</a></p>
    </div>
  </div>
  <?php endif; ?>

</main>

<footer class="w-full mt-auto" style="background:rgba(0,0,0,0.3);border-top:1px solid rgba(255,255,255,0.07);">
  <div class="flex flex-col md:flex-row justify-between items-center px-8 py-6 max-w-7xl mx-auto text-sm">
    <div class="text-white/80 font-bold text-base mb-3 md:mb-0">PawnHub</div>
    <div class="flex gap-6 text-white/40">
      <span>© <?= date('Y') ?> PawnHub. All rights reserved.</span>
    </div>
  </div>
</footer>

<script>
const planBranches = { Starter: 1, Pro: 3, Enterprise: 10 };

function selectPlan(name) {
  document.getElementById('plan_input').value    = name;
  document.getElementById('branches_input').value = planBranches[name];
  document.getElementById('summary_plan').textContent = name;
  const b = planBranches[name];
  document.getElementById('summary_branches').textContent = b + ' branch' + (b > 1 ? 'es' : '');
  ['Starter','Pro','Enterprise'].forEach(p => {
    document.getElementById('pill-' + p).classList.toggle('active', p === name);
  });
}
</script>
</body>
</html>