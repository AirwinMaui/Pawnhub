<?php
session_start();
require 'db.php';
$error = $success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // ── Detect silent POST truncation (post_max_size exceeded) ──────────────
    // When total POST body > post_max_size, PHP clears $_POST AND $_FILES
    // entirely — no error is raised. Catch it here before any validation.
    $content_length = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    $post_max_str   = ini_get('post_max_size');
    $post_max_bytes = (function($v) {
        $v = trim($v);
        $last = strtolower(substr($v, -1));
        $n    = (int)$v;
        if ($last === 'g') $n *= 1024 * 1024 * 1024;
        elseif ($last === 'm') $n *= 1024 * 1024;
        elseif ($last === 'k') $n *= 1024;
        return $n;
    })($post_max_str);
    if ($content_length > 0 && empty($_POST) && $post_max_bytes > 0 && $content_length > $post_max_bytes) {
        $error = 'Your upload is too large for the server. Max total form size: ' . $post_max_str . '. Please compress or resize your file.';
    }

    if (!$error):

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

    $needs_payment = in_array($plan, ['Pro', 'Enterprise']);

    if (!$fullname || !$email || !$username || !$pass || !$biz_name) {
        $error = 'Please fill in all required fields.';
    } elseif ($pass !== $conf) {
        $error = 'Passwords do not match.';
    } elseif (strlen($pass) < 8) {
        $error = 'Password must be at least 8 characters.';
    } elseif (empty($_FILES['business_permit']['name']) || ($_FILES['business_permit']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        $error = 'Please upload your Business Permit.';
    } elseif (($_FILES['business_permit']['error'] ?? 0) !== UPLOAD_ERR_OK) {
        $upload_errors = [
            UPLOAD_ERR_INI_SIZE   => 'File exceeds server upload limit (' . ini_get('upload_max_filesize') . 'B). Please compress or resize it.',
            UPLOAD_ERR_FORM_SIZE  => 'File exceeds the form size limit.',
            UPLOAD_ERR_PARTIAL    => 'File was only partially uploaded. Please try again.',
            UPLOAD_ERR_NO_TMP_DIR => 'Server configuration error (no temp folder). Contact support.',
            UPLOAD_ERR_CANT_WRITE => 'Server could not save the file. Contact support.',
            UPLOAD_ERR_EXTENSION  => 'Upload blocked by server extension.',
        ];
        $err_code = $_FILES['business_permit']['error'];
        $error = $upload_errors[$err_code] ?? 'File upload failed (error ' . $err_code . '). Please try again.';
    } else {
        $file_size = $_FILES['business_permit']['size'];
        $file_tmp  = $_FILES['business_permit']['tmp_name'];
        $file_name = $_FILES['business_permit']['name'];

        if ($file_size > 5 * 1024 * 1024) {
            $error = 'Business permit file must be less than 5MB.';
        } else {
            $chk = $pdo->prepare("SELECT id FROM users WHERE username=? OR email=?");
            $chk->execute([$username, $email]);
            if ($chk->fetch()) {
                $error = 'Username or email already exists.';
            } else {
                $upload_dir = __DIR__ . '/uploads/permits/';
                if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
                $ext         = pathinfo($file_name, PATHINFO_EXTENSION);
                $safe_name   = 'permit_' . time() . '_' . bin2hex(random_bytes(6)) . ($ext ? '.' . $ext : '');
                $upload_path = $upload_dir . $safe_name;
                $permit_url  = 'uploads/permits/' . $safe_name;

                if (!move_uploaded_file($file_tmp, $upload_path)) {
                    $error = 'Failed to upload file. Please try again.';
                } else {
                    $pdo->beginTransaction();
                    try {
                        $pdo->prepare("INSERT INTO tenants (business_name,owner_name,email,phone,address,plan,branches,status,business_permit_url) VALUES (?,?,?,?,?,?,?,'pending',?)")
                            ->execute([$biz_name, $fullname, $email, $phone, $address, $plan, $branches, $permit_url]);
                        $new_tid = $pdo->lastInsertId();
                        $pdo->prepare("INSERT INTO users (tenant_id,fullname,email,username,password,role,status) VALUES (?,?,?,?,?,'admin','pending')")
                            ->execute([$new_tid, $fullname, $email, $username, password_hash($pass, PASSWORD_BCRYPT)]);
                        $new_uid = $pdo->lastInsertId();
                        $pdo->commit();
                    } catch (Throwable $dbErr) {
                        $pdo->rollBack();
                        @unlink($upload_path); // clean up uploaded file
                        error_log('[Signup] DB error: ' . $dbErr->getMessage());
                        $error = 'Registration failed due to a server error. Please try again.';
                        goto end_processing;
                    }

                    if ($needs_payment) {
                        $_SESSION['pending_tenant_id'] = $new_tid;
                        $_SESSION['pending_user_id']   = $new_uid;
                        $_SESSION['pending_plan']      = $plan;
                        $_SESSION['pending_email']     = $email;
                        $_SESSION['pending_biz_name']  = $biz_name;
                        header('Location: paymongo_pay.php');
                        exit;
                    }

                    $success = true;
                    end_processing:
                }
            }
        }
    }
    endif; // end if (!$error) — post_max_size guard
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
* { box-sizing: border-box; }
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
    background: rgba(255,255,255,0.08) !important;
    border: 1.5px solid rgba(255,255,255,0.25) !important;
    border-radius: 12px;
    padding: 12px 16px;
    color: #fff !important;
    font-family: "Inter", sans-serif;
    font-size: 0.875rem;
    outline: none;
    transition: all 0.2s;
    -webkit-text-fill-color: #fff !important;
}
.glass-input:hover {
    border-color: rgba(255,255,255,0.4) !important;
}
.glass-input:focus {
    background: rgba(255,255,255,0.13) !important;
    border-color: rgba(59,130,246,0.8) !important;
    box-shadow: 0 0 0 3px rgba(59,130,246,0.2) !important;
    color: #fff !important;
    -webkit-text-fill-color: #fff !important;
}
.glass-input::placeholder {
    color: rgba(255,255,255,0.35) !important;
    -webkit-text-fill-color: rgba(255,255,255,0.35) !important;
    opacity: 1;
}
.glass-input option { background: #1e293b; color: #fff; }

/* Fix browser autofill */
.glass-input:-webkit-autofill,
.glass-input:-webkit-autofill:hover,
.glass-input:-webkit-autofill:focus,
.glass-input:-webkit-autofill:active {
    -webkit-box-shadow: 0 0 0 9999px rgba(30,41,59,0.95) inset !important;
    box-shadow: 0 0 0 9999px rgba(30,41,59,0.95) inset !important;
    -webkit-text-fill-color: #fff !important;
    caret-color: #fff;
    border-color: rgba(255,255,255,0.25) !important;
    transition: background-color 99999s ease-in-out 0s;
}
.glass-input:-webkit-autofill:focus {
    -webkit-box-shadow: 0 0 0 9999px rgba(30,41,59,0.95) inset, 0 0 0 3px rgba(59,130,246,0.2) !important;
    border-color: rgba(59,130,246,0.8) !important;
}
select.glass-input {
    -webkit-text-fill-color: #fff !important;
    color: #fff !important;
}
select.glass-input option {
    background: #1e293b;
    color: #fff;
}
.plan-pill {
    cursor: pointer;
    padding: 6px 16px;
    border-radius: 100px;
    font-size: 0.78rem;
    font-weight: 700;
    border: 1.5px solid rgba(255,255,255,0.2);
    color: rgba(255,255,255,0.55);
    background: rgba(255,255,255,0.06);
    transition: all 0.2s;
}
.plan-pill:hover {
    border-color: rgba(255,255,255,0.4);
    color: rgba(255,255,255,0.8);
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
.file-upload-zone {
    border: 2px dashed rgba(255,255,255,0.25);
    border-radius: 12px;
    padding: 20px;
    text-align: center;
    cursor: pointer;
    transition: all 0.2s;
    background: rgba(255,255,255,0.04);
}
.file-upload-zone:hover, .file-upload-zone.drag-over {
    border-color: rgba(59,130,246,0.7);
    background: rgba(59,130,246,0.08);
}

/* Label style */
.field-label {
    display: block;
    font-size: 0.67rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.09em;
    color: rgba(255,255,255,0.6);
    margin-bottom: 6px;
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

    <form method="POST" id="regForm" enctype="multipart/form-data" autocomplete="off">
      <input type="hidden" name="plan"     id="plan_input"     value="<?= htmlspecialchars($selected_plan) ?>">
      <input type="hidden" name="branches" id="branches_input" value="<?= $plans[$selected_plan]['branches'] ?>">

      <div style="display:flex;flex-direction:column;gap:16px;">

        <!-- ── SECTION 1: Business Info ──────────────────────── -->
        <div style="border-top:1px solid rgba(255,255,255,0.08);padding-top:16px;">
          <p style="font-size:0.7rem;font-weight:700;text-transform:uppercase;letter-spacing:0.1em;color:rgba(255,255,255,0.4);margin-bottom:12px;">📋 Business Information</p>
          <div style="display:flex;flex-direction:column;gap:12px;">
            <div>
              <label class="field-label">Business Name *</label>
              <input type="text" name="business_name" class="glass-input" placeholder="e.g. GoldKing Pawnshop"
                autocomplete="off" value="<?= htmlspecialchars($_POST['business_name'] ?? '') ?>" required>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
              <div>
                <label class="field-label">Phone Number</label>
                <input type="text" name="phone" class="glass-input" placeholder="09XXXXXXXXX"
                  autocomplete="off" value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>">
              </div>
              <div>
                <label class="field-label">Address</label>
                <input type="text" name="address" class="glass-input" placeholder="City, Province"
                  autocomplete="off" value="<?= htmlspecialchars($_POST['address'] ?? '') ?>">
              </div>
            </div>
          </div>
        </div>

        <!-- ── SECTION 2: Business Permit Upload ─────────────── -->
        <div style="border-top:1px solid rgba(255,255,255,0.08);padding-top:16px;">
          <p style="font-size:0.7rem;font-weight:700;text-transform:uppercase;letter-spacing:0.1em;color:rgba(255,255,255,0.4);margin-bottom:12px;">📄 Business Permit <span style="color:#f87171;">*</span></p>
          <div class="file-upload-zone" id="permitDropZone" onclick="document.getElementById('business_permit').click()">
            <input type="file" name="business_permit" id="business_permit" accept="*/*" style="display:none;" onchange="handlePermitFile(this)">
            <div id="permitPlaceholder">
              <span class="material-symbols-outlined" style="font-size:32px;color:rgba(255,255,255,0.3);margin-bottom:8px;display:block;font-variation-settings:'FILL' 0,'wght' 400,'GRAD' 0,'opsz' 24;">upload_file</span>
              <p style="font-size:0.82rem;font-weight:600;color:rgba(255,255,255,0.5);margin-bottom:4px;">Click or drag & drop your Business Permit</p>
              <p style="font-size:0.72rem;color:rgba(255,255,255,0.3);">Any file format accepted · Max 5MB</p>
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
              <label class="field-label">Full Name *</label>
              <input type="text" name="fullname" class="glass-input" placeholder="Juan Dela Cruz"
                autocomplete="off" value="<?= htmlspecialchars($_POST['fullname'] ?? '') ?>" required>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
              <div>
                <label class="field-label">Email *</label>
                <input type="email" name="email" class="glass-input" placeholder="owner@example.com"
                  autocomplete="off" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required>
              </div>
              <div>
                <label class="field-label">Username *</label>
                <input type="text" name="username" class="glass-input" placeholder="yourUsername"
                  autocomplete="off" value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" required>
              </div>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
              <div>
                <label class="field-label">Password * (min. 8)</label>
                <input type="password" name="password" class="glass-input" placeholder="••••••••" autocomplete="new-password" required>
              </div>
              <div>
                <label class="field-label">Confirm Password *</label>
                <input type="password" name="confirm" class="glass-input" placeholder="••••••••" autocomplete="new-password" required>
              </div>
            </div>
          </div>
        </div>

        <!-- ── SECTION 4: Payment ─────────────────────────────── -->
        <div id="payment_section" style="border-top:1px solid rgba(255,255,255,0.08);padding-top:16px;<?= $selected_plan === 'Starter' ? 'display:none;' : '' ?>">
          <p style="font-size:0.7rem;font-weight:700;text-transform:uppercase;letter-spacing:0.1em;color:rgba(255,255,255,0.4);margin-bottom:12px;">💳 Payment</p>
          <div style="background:rgba(59,130,246,0.08);border:1px solid rgba(59,130,246,0.25);border-radius:12px;padding:16px;">
            <div style="display:flex;align-items:flex-start;gap:12px;">
              <div style="font-size:1.6rem;line-height:1;margin-top:2px;">🔒</div>
              <div>
                <p style="font-size:0.85rem;font-weight:700;color:#93c5fd;margin-bottom:4px;">Secure Payment via PayMongo</p>
                <p style="font-size:0.78rem;color:rgba(255,255,255,0.55);line-height:1.6;margin-bottom:10px;">
                  After submitting this form, you'll be redirected to PayMongo's secure checkout page to complete your payment.
                </p>
                <div style="display:flex;flex-wrap:wrap;gap:8px;">
                  <span style="background:rgba(255,255,255,0.08);border:1px solid rgba(255,255,255,0.15);border-radius:6px;padding:4px 10px;font-size:0.72rem;color:rgba(255,255,255,0.6);">💳 Credit / Debit Card</span>
                  <span style="background:rgba(255,255,255,0.08);border:1px solid rgba(255,255,255,0.15);border-radius:6px;padding:4px 10px;font-size:0.72rem;color:rgba(255,255,255,0.6);">📱 GCash</span>
                  <span style="background:rgba(255,255,255,0.08);border:1px solid rgba(255,255,255,0.15);border-radius:6px;padding:4px 10px;font-size:0.72rem;color:rgba(255,255,255,0.6);">🏦 Online Banking</span>
                  <span style="background:rgba(255,255,255,0.08);border:1px solid rgba(255,255,255,0.15);border-radius:6px;padding:4px 10px;font-size:0.72rem;color:rgba(255,255,255,0.6);">💸 BillEase</span>
                </div>
              </div>
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

  // Show/hide payment section for paid plans
  const paySection = document.getElementById('payment_section');
  const isPaid     = (name === 'Pro' || name === 'Enterprise');
  paySection.style.display = isPaid ? 'block' : 'none';
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

// On page load, show payment section for paid plans
(function() {
  const plan = document.getElementById('plan_input').value;
  if (plan === 'Pro' || plan === 'Enterprise') {
    document.getElementById('payment_section').style.display = 'block';
  }
})();
</script>
</body>
</html>