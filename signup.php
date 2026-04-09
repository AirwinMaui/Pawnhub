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

    // Payment fields
    $payment_method    = trim($_POST['payment_method']    ?? '');
    $payment_reference = trim($_POST['payment_reference'] ?? '');
    // Credit card fields (stored masked — never store raw CVV)
    $cc_name   = trim($_POST['cc_name']   ?? '');
    $cc_number = trim($_POST['cc_number'] ?? '');  // will be masked before storing
    $cc_expiry = trim($_POST['cc_expiry'] ?? '');
    // CVV intentionally NOT stored

    if (!$fullname || !$email || !$username || !$pass || !$biz_name) {
        $error = 'Please fill in all required fields.';
    } elseif ($pass !== $conf) {
        $error = 'Passwords do not match.';
    } elseif (strlen($pass) < 8) {
        $error = 'Password must be at least 8 characters.';
    } elseif (!$payment_method) {
        $error = 'Please select a payment method.';
    } elseif ($payment_method === 'Credit Card' && (!$cc_name || !$cc_number || !$cc_expiry)) {
        $error = 'Please fill in all credit card details.';
    } elseif (empty($_FILES['business_permit']['name'])) {
        $error = 'Please upload your Business Permit.';
    } else {
        // ── Handle business permit upload ──────────────────────
        $allowed_types = ['image/jpeg','image/jpg','image/png','application/pdf'];
        $file_type     = $_FILES['business_permit']['type'];
        $file_size     = $_FILES['business_permit']['size'];
        $file_tmp      = $_FILES['business_permit']['tmp_name'];
        $file_name     = $_FILES['business_permit']['name'];

        if (!in_array($file_type, $allowed_types)) {
            $error = 'Business permit must be JPG, PNG, or PDF only.';
        } elseif ($file_size > 5 * 1024 * 1024) { // 5MB max
            $error = 'Business permit file must be less than 5MB.';
        } else {
            $chk = $pdo->prepare("SELECT id FROM users WHERE username=? OR email=?");
            $chk->execute([$username, $email]);
            if ($chk->fetch()) {
                $error = 'Username or email already exists.';
            } else {
                // Save file
                $upload_dir = __DIR__ . '/uploads/permits/';
                if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
                $ext          = pathinfo($file_name, PATHINFO_EXTENSION);
                $safe_name    = 'permit_' . time() . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
                $upload_path  = $upload_dir . $safe_name;
                $permit_url   = 'uploads/permits/' . $safe_name;

                if (!move_uploaded_file($file_tmp, $upload_path)) {
                    $error = 'Failed to upload file. Please try again.';
                } else {
                    // Mask credit card number (keep last 4 digits only)
                    $cc_masked = '';
                    if ($payment_method === 'Credit Card' && $cc_number) {
                        $digits_only = preg_replace('/\D/', '', $cc_number);
                        $cc_masked   = 'XXXX-XXXX-XXXX-' . substr($digits_only, -4);
                    }

                    // Build payment info JSON for storage
                    $payment_info = json_encode([
                        'method'    => $payment_method,
                        'reference' => $payment_reference,
                        'cc_name'   => $payment_method === 'Credit Card' ? $cc_name   : '',
                        'cc_number' => $payment_method === 'Credit Card' ? $cc_masked : '',
                        'cc_expiry' => $payment_method === 'Credit Card' ? $cc_expiry : '',
                    ]);

                    $pdo->beginTransaction();
                    $pdo->prepare("INSERT INTO tenants (business_name,owner_name,email,phone,address,plan,branches,status,business_permit_url,payment_info) VALUES (?,?,?,?,?,?,?,'pending',?,?)")
                        ->execute([$biz_name, $fullname, $email, $phone, $address, $plan, $branches, $permit_url, $payment_info]);
                    $new_tid = $pdo->lastInsertId();
                    $new_uid = null;
                    $pdo->prepare("INSERT INTO users (tenant_id,fullname,email,username,password,role,status) VALUES (?,?,?,?,?,'admin','pending')")
                        ->execute([$new_tid, $fullname, $email, $username, password_hash($pass, PASSWORD_BCRYPT)]);
                    $new_uid = $pdo->lastInsertId();
                    $pdo->commit();

                    // ── Redirect paid plans to PayMongo checkout ──────────
                    if (in_array($plan, ['Pro', 'Enterprise'])) {
                        $_SESSION['pending_tenant_id'] = $new_tid;
                        $_SESSION['pending_user_id']   = $new_uid;
                        $_SESSION['pending_plan']      = $plan;
                        $_SESSION['pending_email']     = $email;
                        $_SESSION['pending_biz_name']  = $biz_name;
                        header('Location: paymongo_pay.php');
                        exit;
                    }

                    $success = true;   // Starter plan — no payment needed
                }
            }
        }
    }
}

$plans = [
    'Starter'    => ['max_staff' => 3,  'branches' => 1],
    'Pro'        => ['max_staff' => 0,  'branches' => 3],
    'Enterprise' => ['max_staff' => 0,  'branches' => 10],
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

/* Fix browser autofill white background override */
.glass-input:-webkit-autofill,
.glass-input:-webkit-autofill:hover,
.glass-input:-webkit-autofill:focus,
.glass-input:-webkit-autofill:active {
    -webkit-box-shadow: 0 0 0 9999px rgba(255,255,255,0.08) inset !important;
    box-shadow: 0 0 0 9999px rgba(255,255,255,0.08) inset !important;
    -webkit-text-fill-color: #fff !important;
    caret-color: #fff;
    border-color: rgba(255,255,255,0.12) !important;
    transition: background-color 99999s ease-in-out 0s;
}
.glass-input:-webkit-autofill:focus {
    -webkit-box-shadow: 0 0 0 9999px rgba(255,255,255,0.13) inset, 0 0 0 3px rgba(59,130,246,0.15) !important;
    border-color: rgba(59,130,246,0.6) !important;
}
/* Fix for Firefox */
.glass-input:autofill {
    background: rgba(255,255,255,0.08) !important;
    color: #fff !important;
}
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
/* Credit Card visual */
.cc-card-preview {
    background: linear-gradient(135deg, #1e3a8a, #7c3aed);
    border-radius: 14px;
    padding: 20px 22px;
    color: #fff;
    margin-bottom: 16px;
    position: relative;
    overflow: hidden;
    min-height: 120px;
}
.cc-card-preview::before {
    content: '';
    position: absolute;
    top: -30px; right: -30px;
    width: 120px; height: 120px;
    border-radius: 50%;
    background: rgba(255,255,255,0.07);
}
.cc-card-preview::after {
    content: '';
    position: absolute;
    bottom: -40px; left: -20px;
    width: 150px; height: 150px;
    border-radius: 50%;
    background: rgba(255,255,255,0.05);
}
.file-upload-zone {
    border: 2px dashed rgba(255,255,255,0.2);
    border-radius: 12px;
    padding: 20px;
    text-align: center;
    cursor: pointer;
    transition: all 0.2s;
    background: rgba(255,255,255,0.04);
}
.file-upload-zone:hover, .file-upload-zone.drag-over {
    border-color: rgba(59,130,246,0.6);
    background: rgba(59,130,246,0.08);
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
      <span class="material-symbols-outlined" style="color:#22c55e;font-size:36px;font-variation-settings:'FILL' 1,'wght' 400,'GRAD' 0,'opsz' 24;">check_circle</span>
    </div>
    <h2 class="text-2xl font-extrabold text-white mb-3">Application Submitted! 🎉</h2>
    <p class="text-white/60 text-sm leading-relaxed mb-6">
      Your pawnshop registration has been submitted successfully.<br><br>
      Our Super Admin will review your <strong style="color:#86efac;">Business Permit</strong> and <strong style="color:#86efac;">Payment Details</strong>.<br>
      Once approved, you can login using your username and password.
    </p>
    <div style="background:rgba(34,197,94,0.1);border:1px solid rgba(34,197,94,0.25);border-radius:10px;padding:12px 16px;font-size:0.8rem;color:#86efac;margin-bottom:24px;">
      📋 The Super Admin will verify your submitted documents before activating your account.
    </div>
    <a href="login.php" style="display:inline-block;background:#3b82f6;color:#fff;text-decoration:none;padding:13px 32px;border-radius:12px;font-size:0.92rem;font-weight:700;transition:all 0.2s;" onmouseover="this.style.background='#2563eb'" onmouseout="this.style.background='#3b82f6'">
      Go to Login →
    </a>
  </div>

  <?php else: ?>
  <!-- REGISTRATION FORM -->
  <div class="glass-panel w-full max-w-xl rounded-3xl shadow-2xl" style="padding: 36px 36px 28px;">

    <!-- Header -->
    <div style="margin-bottom:22px;">
      <div style="display:inline-flex;align-items:center;gap:5px;background:linear-gradient(135deg,#1d4ed8,#7c3aed);color:#fff;font-size:0.65rem;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;padding:3px 10px;border-radius:100px;margin-bottom:12px;">
        <span class="material-symbols-outlined" style="font-size:11px;font-variation-settings:'FILL' 1,'wght' 400,'GRAD' 0,'opsz' 24;">storefront</span>
        Tenant Registration
      </div>
      <h1 style="font-size:1.8rem;font-weight:800;color:#fff;letter-spacing:-0.03em;line-height:1.1;margin-bottom:6px;">Register Your Pawnshop</h1>
      <p style="font-size:0.81rem;color:rgba(255,255,255,0.5);">Fill in your details, upload your business permit, and provide payment information.</p>
    </div>

    <!-- Plan Selector -->
    <div style="margin-bottom:18px;">
      <div style="background:rgba(255,255,255,0.04);border:1px solid rgba(255,255,255,0.08);border-radius:10px;padding:10px 12px;font-size:0.72rem;color:rgba(255,255,255,0.35);margin-bottom:10px;">
        📍 <strong style="color:rgba(255,255,255,0.5);">1 subscription = 1 branch.</strong> Need another branch? Subscribe again with any plan.
      </div>
      <div class="flex gap-2 flex-wrap">
        <button type="button" onclick="selectPlan('Starter')"    id="pill-Starter"    class="plan-pill <?= $selected_plan==='Starter'    ? 'active' : '' ?>">Starter — Free</button>
        <button type="button" onclick="selectPlan('Pro')"        id="pill-Pro"        class="plan-pill <?= $selected_plan==='Pro'        ? 'active' : '' ?>">Pro — ₱999/mo</button>
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

    <form method="POST" id="regForm" enctype="multipart/form-data">
      <input type="hidden" name="plan"     id="plan_input"     value="<?= htmlspecialchars($selected_plan) ?>">
      <input type="hidden" name="branches" id="branches_input" value="<?= $plans[$selected_plan]['branches'] ?>">

      <div style="display:flex;flex-direction:column;gap:16px;">

        <!-- ── SECTION 1: Business Info ──────────────────────── -->
        <div style="border-top:1px solid rgba(255,255,255,0.08);padding-top:16px;">
          <p style="font-size:0.7rem;font-weight:700;text-transform:uppercase;letter-spacing:0.1em;color:rgba(255,255,255,0.4);margin-bottom:12px;">📋 Business Information</p>

          <div style="display:flex;flex-direction:column;gap:12px;">
            <div>
              <label style="display:block;font-size:0.67rem;font-weight:700;text-transform:uppercase;letter-spacing:0.09em;color:rgba(255,255,255,0.5);margin-bottom:6px;">Business Name *</label>
              <input type="text" name="business_name" class="glass-input" placeholder="e.g. GoldKing Pawnshop"
                value="<?= htmlspecialchars($_POST['business_name'] ?? '') ?>" required>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
              <div>
                <label style="display:block;font-size:0.67rem;font-weight:700;text-transform:uppercase;letter-spacing:0.09em;color:rgba(255,255,255,0.5);margin-bottom:6px;">Phone Number</label>
                <input type="text" name="phone" class="glass-input" placeholder="09XXXXXXXXX"
                  value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>">
              </div>
              <div>
                <label style="display:block;font-size:0.67rem;font-weight:700;text-transform:uppercase;letter-spacing:0.09em;color:rgba(255,255,255,0.5);margin-bottom:6px;">Address</label>
                <input type="text" name="address" class="glass-input" placeholder="City, Province"
                  value="<?= htmlspecialchars($_POST['address'] ?? '') ?>">
              </div>
            </div>
          </div>
        </div>

        <!-- ── SECTION 2: Business Permit Upload ─────────────── -->
        <div style="border-top:1px solid rgba(255,255,255,0.08);padding-top:16px;">
          <p style="font-size:0.7rem;font-weight:700;text-transform:uppercase;letter-spacing:0.1em;color:rgba(255,255,255,0.4);margin-bottom:12px;">📄 Business Permit <span style="color:#f87171;">*</span></p>

          <div class="file-upload-zone" id="permitDropZone" onclick="document.getElementById('business_permit').click()">
            <input type="file" name="business_permit" id="business_permit" accept=".jpg,.jpeg,.png,.pdf" style="display:none;" onchange="handlePermitFile(this)">
            <div id="permitPlaceholder">
              <span class="material-symbols-outlined" style="font-size:32px;color:rgba(255,255,255,0.3);margin-bottom:8px;display:block;font-variation-settings:'FILL' 0,'wght' 400,'GRAD' 0,'opsz' 24;">upload_file</span>
              <p style="font-size:0.82rem;font-weight:600;color:rgba(255,255,255,0.5);margin-bottom:4px;">Click or drag & drop your Business Permit</p>
              <p style="font-size:0.72rem;color:rgba(255,255,255,0.3);">JPG, PNG, or PDF · Max 5MB</p>
            </div>
            <div id="permitPreview" style="display:none;">
              <span class="material-symbols-outlined" style="font-size:28px;color:#34d399;margin-bottom:6px;display:block;font-variation-settings:'FILL' 1,'wght' 400,'GRAD' 0,'opsz' 24;">task</span>
              <p id="permitFileName" style="font-size:0.82rem;font-weight:700;color:#86efac;"></p>
              <p style="font-size:0.7rem;color:rgba(255,255,255,0.35);margin-top:2px;">Click to change file</p>
            </div>
          </div>
          <p style="font-size:0.71rem;color:rgba(255,255,255,0.3);margin-top:6px;">📌 This will be reviewed by the Super Admin before your account is approved.</p>
        </div>

        <!-- ── SECTION 3: Owner Info ──────────────────────────── -->
        <div style="border-top:1px solid rgba(255,255,255,0.08);padding-top:16px;">
          <p style="font-size:0.7rem;font-weight:700;text-transform:uppercase;letter-spacing:0.1em;color:rgba(255,255,255,0.4);margin-bottom:12px;">👤 Owner / Account Information</p>

          <div style="display:flex;flex-direction:column;gap:12px;">
            <div>
              <label style="display:block;font-size:0.67rem;font-weight:700;text-transform:uppercase;letter-spacing:0.09em;color:rgba(255,255,255,0.5);margin-bottom:6px;">Full Name *</label>
              <input type="text" name="fullname" class="glass-input" placeholder="Juan Dela Cruz"
                value="<?= htmlspecialchars($_POST['fullname'] ?? '') ?>" required>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
              <div>
                <label style="display:block;font-size:0.67rem;font-weight:700;text-transform:uppercase;letter-spacing:0.09em;color:rgba(255,255,255,0.5);margin-bottom:6px;">Email *</label>
                <input type="email" name="email" class="glass-input" placeholder="owner@example.com"
                  value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required>
              </div>
              <div>
                <label style="display:block;font-size:0.67rem;font-weight:700;text-transform:uppercase;letter-spacing:0.09em;color:rgba(255,255,255,0.5);margin-bottom:6px;">Username *</label>
                <input type="text" name="username" class="glass-input" placeholder="yourUsername"
                  value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" required>
              </div>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
              <div>
                <label style="display:block;font-size:0.67rem;font-weight:700;text-transform:uppercase;letter-spacing:0.09em;color:rgba(255,255,255,0.5);margin-bottom:6px;">Password * (min. 8)</label>
                <input type="password" name="password" class="glass-input" placeholder="••••••••" required>
              </div>
              <div>
                <label style="display:block;font-size:0.67rem;font-weight:700;text-transform:uppercase;letter-spacing:0.09em;color:rgba(255,255,255,0.5);margin-bottom:6px;">Confirm Password *</label>
                <input type="password" name="confirm" class="glass-input" placeholder="••••••••" required>
              </div>
            </div>
          </div>
        </div>

        <!-- ── SECTION 4: Payment ─────────────────────────────── -->
        <div style="border-top:1px solid rgba(255,255,255,0.08);padding-top:16px;">
          <p style="font-size:0.7rem;font-weight:700;text-transform:uppercase;letter-spacing:0.1em;color:rgba(255,255,255,0.4);margin-bottom:12px;">💳 Payment Information <span style="color:#f87171;">*</span></p>

          <div style="display:flex;flex-direction:column;gap:12px;">
            <!-- Payment Method Select -->
            <div>
              <label style="display:block;font-size:0.67rem;font-weight:700;text-transform:uppercase;letter-spacing:0.09em;color:rgba(255,255,255,0.5);margin-bottom:6px;">Payment Method *</label>
              <select name="payment_method" id="payment_method_sel" class="glass-input" required onchange="handlePaymentMethodChange(this.value)">
                <option value="">— Select Payment Method —</option>
                <option value="Credit Card" <?= ($_POST['payment_method']??'')==='Credit Card'?'selected':'' ?>>💳 Credit Card</option>
                <option value="GCash" <?= ($_POST['payment_method']??'')==='GCash'?'selected':'' ?>>📱 GCash</option>
                <option value="Maya" <?= ($_POST['payment_method']??'')==='Maya'?'selected':'' ?>>📱 Maya (PayMaya)</option>
                <option value="Bank Transfer - BDO" <?= ($_POST['payment_method']??'')==='Bank Transfer - BDO'?'selected':'' ?>>🏦 Bank Transfer — BDO</option>
                <option value="Bank Transfer - BPI" <?= ($_POST['payment_method']??'')==='Bank Transfer - BPI'?'selected':'' ?>>🏦 Bank Transfer — BPI</option>
                <option value="Bank Transfer - UnionBank" <?= ($_POST['payment_method']??'')==='Bank Transfer - UnionBank'?'selected':'' ?>>🏦 Bank Transfer — UnionBank</option>
                <option value="Bank Transfer - Metrobank" <?= ($_POST['payment_method']??'')==='Bank Transfer - Metrobank'?'selected':'' ?>>🏦 Bank Transfer — Metrobank</option>
                <option value="Cash" <?= ($_POST['payment_method']??'')==='Cash'?'selected':'' ?>>💵 Cash (walk-in)</option>
              </select>
            </div>

            <!-- Credit Card Fields (shown only when Credit Card selected) -->
            <div id="cc_fields" style="display:none;background:rgba(124,58,237,0.08);border:1px solid rgba(124,58,237,0.2);border-radius:12px;padding:16px;">
              <!-- Card Preview -->
              <div class="cc-card-preview" id="cc_preview">
                <div style="font-size:0.6rem;font-weight:700;letter-spacing:0.15em;text-transform:uppercase;opacity:0.6;margin-bottom:14px;">Credit / Debit Card</div>
                <div style="font-family:monospace;font-size:1.1rem;font-weight:700;letter-spacing:0.15em;margin-bottom:14px;" id="cc_preview_num">•••• •••• •••• ••••</div>
                <div style="display:flex;justify-content:space-between;align-items:flex-end;">
                  <div>
                    <div style="font-size:0.55rem;opacity:0.5;text-transform:uppercase;letter-spacing:0.1em;">Card Holder</div>
                    <div style="font-size:0.78rem;font-weight:600;" id="cc_preview_name">YOUR NAME</div>
                  </div>
                  <div style="text-align:right;">
                    <div style="font-size:0.55rem;opacity:0.5;text-transform:uppercase;letter-spacing:0.1em;">Expires</div>
                    <div style="font-size:0.78rem;font-weight:600;" id="cc_preview_expiry">MM/YY</div>
                  </div>
                </div>
              </div>

              <div style="display:flex;flex-direction:column;gap:10px;">
                <div>
                  <label style="display:block;font-size:0.67rem;font-weight:700;text-transform:uppercase;letter-spacing:0.09em;color:rgba(255,255,255,0.5);margin-bottom:6px;">Name on Card *</label>
                  <input type="text" name="cc_name" id="cc_name_inp" class="glass-input" placeholder="Juan Dela Cruz"
                    value="<?= htmlspecialchars($_POST['cc_name'] ?? '') ?>"
                    oninput="document.getElementById('cc_preview_name').textContent=this.value.toUpperCase()||'YOUR NAME'">
                </div>
                <div>
                  <label style="display:block;font-size:0.67rem;font-weight:700;text-transform:uppercase;letter-spacing:0.09em;color:rgba(255,255,255,0.5);margin-bottom:6px;">Card Number *</label>
                  <input type="text" name="cc_number" id="cc_num_inp" class="glass-input" placeholder="1234 5678 9012 3456"
                    maxlength="19" autocomplete="cc-number"
                    value="<?= htmlspecialchars($_POST['cc_number'] ?? '') ?>"
                    oninput="formatCardNumber(this)">
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
                  <div>
                    <label style="display:block;font-size:0.67rem;font-weight:700;text-transform:uppercase;letter-spacing:0.09em;color:rgba(255,255,255,0.5);margin-bottom:6px;">Expiry Date *</label>
                    <input type="text" name="cc_expiry" id="cc_exp_inp" class="glass-input" placeholder="MM/YY"
                      maxlength="5" autocomplete="cc-exp"
                      value="<?= htmlspecialchars($_POST['cc_expiry'] ?? '') ?>"
                      oninput="formatExpiry(this)">
                  </div>
                  <div>
                    <label style="display:block;font-size:0.67rem;font-weight:700;text-transform:uppercase;letter-spacing:0.09em;color:rgba(255,255,255,0.5);margin-bottom:6px;">CVV *</label>
                    <input type="password" name="cc_cvv" class="glass-input" placeholder="•••" maxlength="4" autocomplete="cc-csc">
                  </div>
                </div>
                <div style="background:rgba(255,255,255,0.04);border-radius:8px;padding:9px 12px;font-size:0.72rem;color:rgba(255,255,255,0.35);">
                  🔒 Your card details are encrypted. CVV is never stored.
                </div>
              </div>
            </div>

            <!-- Reference Number (for non-CC methods) -->
            <div id="reference_field">
              <label style="display:block;font-size:0.67rem;font-weight:700;text-transform:uppercase;letter-spacing:0.09em;color:rgba(255,255,255,0.5);margin-bottom:6px;">Reference / Transaction Number <span style="color:rgba(255,255,255,0.25);font-weight:400;">(if applicable)</span></label>
              <input type="text" name="payment_reference" class="glass-input" placeholder="e.g. GCash ref #1234567890"
                value="<?= htmlspecialchars($_POST['payment_reference'] ?? '') ?>">
            </div>
          </div>
        </div>

        <!-- Plan Summary -->
        <div id="plan_summary" style="background:rgba(59,130,246,0.1);border:1px solid rgba(59,130,246,0.25);border-radius:12px;padding:14px 16px;font-size:0.82rem;">
          <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;">
            <div style="color:rgba(255,255,255,0.7);">Selected Plan: <strong style="color:#93c5fd;" id="summary_plan"><?= htmlspecialchars($selected_plan) ?></strong></div>
            <div style="font-size:0.75rem;color:rgba(255,255,255,0.35);" id="summary_price"><?= $selected_plan === 'Starter' ? 'Free' : ($selected_plan === 'Pro' ? '₱999/mo' : '₱2,499/mo') ?></div>
          </div>
          <div id="summary_desc" style="font-size:0.76rem;color:rgba(147,197,253,0.8);line-height:1.6;">
            <?php if($selected_plan === 'Starter'): ?>
              ✅ 1 Manager &nbsp;·&nbsp; Up to 3 Staff/Cashier &nbsp;·&nbsp; Basic Reports &nbsp;·&nbsp; Core pawnshop features
            <?php elseif($selected_plan === 'Pro'): ?>
              ✅ 1 Manager &nbsp;·&nbsp; Unlimited Staff &nbsp;·&nbsp; Advanced Reports &nbsp;·&nbsp; Custom Branding &nbsp;·&nbsp; Priority Support
            <?php else: ?>
              ✅ 1 Manager &nbsp;·&nbsp; Unlimited Staff &nbsp;·&nbsp; White-Label &nbsp;·&nbsp; Data Export &nbsp;·&nbsp; Dedicated Account Manager
            <?php endif; ?>
          </div>
        </div>

        <p style="font-size:0.74rem;color:rgba(255,255,255,0.35);font-style:italic;line-height:1.6;">
          ℹ️ The Super Admin will review your Business Permit and Payment details. Once verified and approved, you'll receive your login link.
        </p>

        <button type="submit" style="width:100%;padding:14px;background:#3b82f6;color:#fff;border:none;border-radius:12px;font-family:'Inter',sans-serif;font-size:0.95rem;font-weight:700;cursor:pointer;box-shadow:0 4px 20px rgba(59,130,246,0.3);transition:all 0.2s;"
          onmouseover="this.style.background='#2563eb';this.style.transform='translateY(-1px)'"
          onmouseout="this.style.background='#3b82f6';this.style.transform='translateY(0)'">
          Submit Application →
        </button>

      </div>
    </form>

    <div style="margin-top:22px;padding-top:16px;border-top:1px solid rgba(255,255,255,0.08);text-align:center;">
      <p style="font-size:0.85rem;color:rgba(255,255,255,0.5);">Already have an account? <a href="login.php" style="color:#60a5fa;font-weight:700;text-decoration:none;">Sign In</a></p>
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
const planData = {
  Starter:    { price: 'Free',      desc: '✅ 1 Manager &nbsp;·&nbsp; Up to 3 Staff/Cashier &nbsp;·&nbsp; Basic Reports &nbsp;·&nbsp; Core pawnshop features' },
  Pro:        { price: '₱999/mo',   desc: '✅ 1 Manager &nbsp;·&nbsp; Unlimited Staff &nbsp;·&nbsp; Advanced Reports &nbsp;·&nbsp; Custom Branding &nbsp;·&nbsp; Priority Support' },
  Enterprise: { price: '₱2,499/mo', desc: '✅ 1 Manager &nbsp;·&nbsp; Unlimited Staff &nbsp;·&nbsp; White-Label &nbsp;·&nbsp; Data Export &nbsp;·&nbsp; Dedicated Account Manager' },
};

function selectPlan(name) {
  const p = planData[name];
  document.getElementById('plan_input').value          = name;
  document.getElementById('branches_input').value      = 1;
  document.getElementById('summary_plan').textContent  = name;
  document.getElementById('summary_price').textContent = p.price;
  document.getElementById('summary_desc').innerHTML    = p.desc;
  ['Starter','Pro','Enterprise'].forEach(n => {
    document.getElementById('pill-' + n).classList.toggle('active', n === name);
  });
}

// ── Business Permit File Handling ─────────────────────────────
function handlePermitFile(input) {
  const file = input.files[0];
  if (!file) return;
  document.getElementById('permitPlaceholder').style.display = 'none';
  document.getElementById('permitFileName').textContent = file.name + ' (' + (file.size/1024).toFixed(1) + ' KB)';
  document.getElementById('permitPreview').style.display = 'block';
}

// Drag & drop
const dz = document.getElementById('permitDropZone');
dz.addEventListener('dragover', e => { e.preventDefault(); dz.classList.add('drag-over'); });
dz.addEventListener('dragleave', () => dz.classList.remove('drag-over'));
dz.addEventListener('drop', e => {
  e.preventDefault(); dz.classList.remove('drag-over');
  const dt = new DataTransfer();
  dt.items.add(e.dataTransfer.files[0]);
  document.getElementById('business_permit').files = dt.files;
  handlePermitFile(document.getElementById('business_permit'));
});

// ── Payment Method Toggle ─────────────────────────────────────
function handlePaymentMethodChange(method) {
  const ccFields  = document.getElementById('cc_fields');
  const refField  = document.getElementById('reference_field');
  const ccInputs  = ccFields.querySelectorAll('input');

  if (method === 'Credit Card') {
    ccFields.style.display = 'block';
    refField.style.display = 'none';
    ccInputs.forEach(inp => { if (inp.name !== 'cc_cvv') inp.required = true; });
  } else {
    ccFields.style.display = 'none';
    refField.style.display = 'block';
    ccInputs.forEach(inp => inp.required = false);
  }
}

// Initialize on page load if error and CC was selected
<?php if (($_POST['payment_method'] ?? '') === 'Credit Card'): ?>
handlePaymentMethodChange('Credit Card');
<?php endif; ?>

// ── Credit Card Formatting ────────────────────────────────────
function formatCardNumber(input) {
  let v = input.value.replace(/\D/g, '').substring(0, 16);
  input.value = v.replace(/(.{4})/g, '$1 ').trim();
  // Update preview
  const masked = v.length > 0
    ? (v.substring(0,4) + (v.length > 4 ? ' ' + v.substring(4,8).replace(/./g,'•') : '') +
       (v.length > 8 ? ' ' + v.substring(8,12).replace(/./g,'•') : '') +
       (v.length > 12 ? ' ' + v.substring(12,16) : ''))
    : '•••• •••• •••• ••••';
  document.getElementById('cc_preview_num').textContent = masked || '•••• •••• •••• ••••';
}

function formatExpiry(input) {
  let v = input.value.replace(/\D/g, '').substring(0, 4);
  if (v.length >= 2) v = v.substring(0,2) + '/' + v.substring(2);
  input.value = v;
  document.getElementById('cc_preview_expiry').textContent = v || 'MM/YY';
}
</script>
</body>
</html>