<?php
session_start();
require 'db.php';
$error = $success = '';

// ── Handle AI permit extraction (AJAX) ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'extract_permit') {
    header('Content-Type: application/json');
    $imageData = $_POST['image_data'] ?? '';
    if (!$imageData) { echo json_encode(['error' => 'No image data']); exit; }

    // Strip base64 header
    $base64 = preg_replace('/^data:image\/\w+;base64,/', '', $imageData);

    $payload = json_encode([
        'model'      => 'claude-sonnet-4-20250514',
        'max_tokens' => 1000,
        'messages'   => [[
            'role'    => 'user',
            'content' => [
                [
                    'type'   => 'image',
                    'source' => ['type' => 'base64', 'media_type' => 'image/jpeg', 'data' => $base64]
                ],
                [
                    'type' => 'text',
                    'text' => 'This is a Philippine business permit or DTI/SEC registration document. Extract the following information and respond ONLY with a valid JSON object, no other text:
{
  "business_name": "exact business name from permit",
  "owner_name": "owner or registrant name",
  "address": "full business address",
  "phone": "phone number if present, else empty string",
  "permit_number": "permit or registration number",
  "validity": "expiry or validity date if present",
  "is_valid_permit": true or false (true if this looks like a real PH business permit/DTI/SEC/Mayor\'s permit),
  "confidence": 0-100 (confidence score),
  "rejection_reason": "reason if is_valid_permit is false, else empty string"
}'
                ]
            ]
        ]]
    ]);

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'x-api-key: ' . (defined('ANTHROPIC_API_KEY') ? ANTHROPIC_API_KEY : getenv('ANTHROPIC_API_KEY')),
            'anthropic-version: 2023-06-01'
        ],
        CURLOPT_TIMEOUT        => 30,
    ]);
    $resp = curl_exec($ch);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err) { echo json_encode(['error' => 'AI service unavailable: ' . $err]); exit; }

    $aiResp = json_decode($resp, true);
    $text   = $aiResp['content'][0]['text'] ?? '';

    // Extract JSON from response
    preg_match('/\{.*\}/s', $text, $matches);
    $extracted = $matches[0] ?? '{}';
    $data = json_decode($extracted, true);

    if (!$data) { echo json_encode(['error' => 'Could not parse permit data']); exit; }

    echo json_encode(['success' => true, 'data' => $data]);
    exit;
}

// ── Handle form submission ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['action'])) {
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
    $permit_data_raw = $_POST['permit_data'] ?? '';

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
            // Handle permit image upload
            $permit_url = '';
            $permit_status = 'none';
            $permit_ai_data = null;
            $permit_confidence = null;

            if (!empty($_FILES['business_permit']['name'])) {
                $allowed = ['image/jpeg','image/png','image/webp','application/pdf'];
                $ftype   = mime_content_type($_FILES['business_permit']['tmp_name']);
                if (in_array($ftype, $allowed) && $_FILES['business_permit']['size'] <= 10*1024*1024) {
                    $ext      = pathinfo($_FILES['business_permit']['name'], PATHINFO_EXTENSION);
                    $filename = 'permit_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                    $uploaddir = __DIR__ . '/uploads/permits/';
                    if (!is_dir($uploaddir)) mkdir($uploaddir, 0755, true);
                    if (move_uploaded_file($_FILES['business_permit']['tmp_name'], $uploaddir . $filename)) {
                        $permit_url = 'uploads/permits/' . $filename;
                        $permit_status = 'pending_review';
                    }
                }
            }

            // Parse AI permit data if provided
            if ($permit_data_raw) {
                $parsed = json_decode($permit_data_raw, true);
                if ($parsed) {
                    $permit_ai_data    = $permit_data_raw;
                    $permit_confidence = $parsed['confidence'] ?? null;
                    $permit_status     = ($parsed['is_valid_permit'] ?? false) ? 'ai_approved' : 'ai_rejected';
                }
            }

            $pdo->beginTransaction();
            $pdo->prepare("INSERT INTO tenants (business_name, owner_name, email, phone, address, plan, branches, status,
                            business_permit_url, business_permit_status, business_permit_data, ai_confidence, ai_reviewed_at)
                           VALUES (?,?,?,?,?,?,?,'pending', ?,?,?,?,NOW())")
                ->execute([$biz_name, $fullname, $email, $phone, $address, $plan, $branches,
                           $permit_url ?: null, $permit_status,
                           $permit_ai_data, $permit_confidence]);
            $new_tid = $pdo->lastInsertId();

            $pdo->prepare("INSERT INTO users (tenant_id,fullname,email,username,password,role,status)
                           VALUES (?,?,?,?,?,'admin','pending')")
                ->execute([$new_tid, $fullname, $email, $username, password_hash($pass, PASSWORD_BCRYPT)]);
            $pdo->commit();
            $success = true;
        }
    }
}

$plans = [
    'Starter'    => ['max_staff' => 3,  'branches' => 1, 'price' => 'Free'],
    'Pro'        => ['max_staff' => 0,  'branches' => 3, 'price' => '₱999/mo'],
    'Enterprise' => ['max_staff' => 0,  'branches' => 10,'price' => '₱2,499/mo'],
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
.glass-panel { background:rgba(0,0,0,0.5); backdrop-filter:blur(24px); -webkit-backdrop-filter:blur(24px); border:1px solid rgba(255,255,255,0.1); }
.glass-input { width:100%; background:rgba(255,255,255,0.08); border:1px solid rgba(255,255,255,0.12); border-radius:12px; padding:12px 16px; color:#fff; font-family:"Inter",sans-serif; font-size:0.875rem; outline:none; transition:all 0.2s; }
.glass-input:focus { background:rgba(255,255,255,0.13); border-color:rgba(59,130,246,0.6); box-shadow:0 0 0 3px rgba(59,130,246,0.15); }
.glass-input::placeholder { color:rgba(255,255,255,0.35); }
.glass-input option { background:#1e293b; color:#fff; }
.plan-pill { cursor:pointer; padding:6px 16px; border-radius:100px; font-size:0.78rem; font-weight:700; border:1.5px solid rgba(255,255,255,0.15); color:rgba(255,255,255,0.5); background:rgba(255,255,255,0.06); transition:all 0.2s; }
.plan-pill.active { background:#3b82f6; border-color:#3b82f6; color:#fff; }
.hero-bg { background-image:linear-gradient(rgba(0,0,0,0.55),rgba(0,0,0,0.65)), url('https://lh3.googleusercontent.com/aida-public/AB6AXuDVdOMy67RcI3OmEXQ5Ob4N9qbUXkHC8UCa3Ni6E2dPvn8N_9Kg_FuGSOcP4mhYkmmhNphJ8vQukLbFjfnVrv-wy716m8LpTRmRrql1K07LpfXVuqMeCMwQRftqZXZWikKdGhSBaHJEhrAn431mN9EQqELqupcBMhVrkknDFPIyVKW_l8bfki8PfvWSkOTQ129Z5jOMGF5My-stQnfPndc_y1X0jUHBEmlH0AVE04q2vpa87PHKNSxAOHabM4n8c9W6UcgA91Cs-1c'); background-size:cover; background-position:center; background-attachment:fixed; }

/* Permit upload area */
.permit-drop { border:2px dashed rgba(255,255,255,0.2); border-radius:14px; padding:28px 20px; text-align:center; cursor:pointer; transition:all 0.25s; background:rgba(255,255,255,0.04); }
.permit-drop:hover, .permit-drop.drag-over { border-color:#3b82f6; background:rgba(59,130,246,0.08); }
.permit-preview { position:relative; border-radius:10px; overflow:hidden; }
.permit-preview img { width:100%; max-height:200px; object-fit:contain; border-radius:10px; background:rgba(0,0,0,0.3); }
.ai-badge { display:inline-flex; align-items:center; gap:5px; padding:4px 10px; border-radius:100px; font-size:0.7rem; font-weight:700; }
.ai-badge.analyzing { background:rgba(59,130,246,0.2); color:#93c5fd; border:1px solid rgba(59,130,246,0.3); }
.ai-badge.approved  { background:rgba(34,197,94,0.2);  color:#86efac; border:1px solid rgba(34,197,94,0.3); }
.ai-badge.rejected  { background:rgba(239,68,68,0.2);  color:#fca5a5; border:1px solid rgba(239,68,68,0.3); }
.field-autofilled { border-color:rgba(34,197,94,0.6) !important; background:rgba(34,197,94,0.08) !important; }
@keyframes pulse-ring { 0%,100%{opacity:1}50%{opacity:0.4} }
.ai-pulse { animation: pulse-ring 1.2s ease-in-out infinite; }
</style>
</head>
<body class="min-h-screen flex flex-col text-white hero-bg">

<!-- NAV -->
<header class="w-full sticky top-0 z-50" style="background:rgba(0,0,0,0.3);backdrop-filter:blur(16px);border-bottom:1px solid rgba(255,255,255,0.07);">
  <div class="flex justify-between items-center px-8 py-5 max-w-7xl mx-auto">
    <a href="home.php" class="flex items-center gap-2">
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
      <?php if (!empty($_POST['permit_data'])): $pd = json_decode($_POST['permit_data'],true); ?>
        <?php if ($pd['is_valid_permit'] ?? false): ?>
          ✅ Your business permit was <strong style="color:#86efac;">verified by AI</strong> — your application may be auto-approved faster!
        <?php else: ?>
          ⚠️ Your permit needs manual review. Our team will check it soon.
        <?php endif; ?>
      <?php else: ?>
        Our Super Admin will review and approve your account.
      <?php endif; ?>
    </p>
    <div style="background:rgba(34,197,94,0.1);border:1px solid rgba(34,197,94,0.25);border-radius:10px;padding:12px 16px;font-size:0.8rem;color:#86efac;margin-bottom:24px;">
      📧 Once approved, you will receive an email with your personal login link.
    </div>
    <a href="login.php" style="display:inline-block;background:#3b82f6;color:#fff;text-decoration:none;padding:13px 32px;border-radius:12px;font-size:0.92rem;font-weight:700;">
      Go to Login →
    </a>
  </div>

  <?php else: ?>
  <!-- REGISTRATION FORM -->
  <div class="glass-panel w-full max-w-lg p-8 md:p-10 rounded-3xl shadow-2xl">
    <div class="mb-6">
      <h1 class="text-3xl font-extrabold tracking-tight text-white mb-2">PawnHub Partnership</h1>
      <p class="text-white/60 text-sm leading-relaxed">Register your pawnshop. Upload your business permit for instant AI verification and faster approval.</p>
    </div>

    <!-- Plan Selector -->
    <div class="mb-6">
      <label class="block text-xs font-bold uppercase tracking-widest text-white/50 mb-3">Select Plan</label>
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
      <input type="hidden" name="plan"        id="plan_input"     value="<?= htmlspecialchars($selected_plan) ?>">
      <input type="hidden" name="branches"    id="branches_input" value="1">
      <input type="hidden" name="permit_data" id="permit_data_hidden" value="">

      <div class="space-y-5">

        <!-- ══ BUSINESS PERMIT UPLOAD ══ -->
        <div>
          <label class="block text-xs font-bold uppercase tracking-widest text-white/50 mb-2">
            📋 Business Permit / DTI / SEC Registration
            <span style="color:#60a5fa;font-weight:400;text-transform:none;letter-spacing:0;font-size:0.72rem;"> — Upload for AI auto-fill & faster approval</span>
          </label>

          <!-- Drop Zone -->
          <div id="permitDrop" class="permit-drop" onclick="document.getElementById('permitFile').click()" ondragover="event.preventDefault();this.classList.add('drag-over')" ondragleave="this.classList.remove('drag-over')" ondrop="handlePermitDrop(event)">
            <div id="dropContent">
              <span class="material-symbols-outlined" style="font-size:36px;color:rgba(255,255,255,0.3);display:block;margin-bottom:8px;">upload_file</span>
              <div style="font-size:0.85rem;color:rgba(255,255,255,0.5);font-weight:600;">Drop your permit here or click to upload</div>
              <div style="font-size:0.73rem;color:rgba(255,255,255,0.25);margin-top:4px;">JPG, PNG, PDF · Max 10MB</div>
            </div>
          </div>
          <input type="file" id="permitFile" name="business_permit" accept="image/*,application/pdf" class="hidden" onchange="handlePermitFile(this)">

          <!-- Preview & AI Status -->
          <div id="permitPreviewWrap" class="hidden mt-3">
            <div class="permit-preview mb-2">
              <img id="permitPreviewImg" src="" alt="Permit preview">
              <button type="button" onclick="clearPermit()" style="position:absolute;top:8px;right:8px;background:rgba(0,0,0,0.6);border:none;border-radius:50%;width:28px;height:28px;cursor:pointer;display:flex;align-items:center;justify-content:center;color:#fff;">
                <span class="material-symbols-outlined" style="font-size:15px;">close</span>
              </button>
            </div>
            <div id="aiStatusWrap">
              <div id="aiStatusAnalyzing" class="ai-badge analyzing hidden">
                <span class="material-symbols-outlined ai-pulse" style="font-size:13px;">psychology</span>
                AI is reading your permit...
              </div>
              <div id="aiStatusApproved" class="ai-badge approved hidden">
                <span class="material-symbols-outlined" style="font-size:13px;">verified</span>
                Valid Permit Detected — Form auto-filled!
              </div>
              <div id="aiStatusRejected" class="ai-badge rejected hidden">
                <span class="material-symbols-outlined" style="font-size:13px;">warning</span>
                <span id="aiRejectMsg">Could not verify permit — please check fields manually</span>
              </div>
              <div id="aiConfidenceWrap" class="hidden mt-1" style="font-size:0.7rem;color:rgba(255,255,255,0.4);">
                AI Confidence: <span id="aiConfidence">—</span>%
              </div>
            </div>
          </div>
        </div>

        <!-- Business Info -->
        <div>
          <label class="block text-xs font-bold uppercase tracking-widest text-white/50 mb-1.5 ml-1">Business Name *</label>
          <input type="text" name="business_name" id="f_business_name" class="glass-input" placeholder="e.g. GoldKing Pawnshop"
            value="<?= htmlspecialchars($_POST['business_name'] ?? '') ?>" required>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div>
            <label class="block text-xs font-bold uppercase tracking-widest text-white/50 mb-1.5 ml-1">Phone Number</label>
            <input type="text" name="phone" id="f_phone" class="glass-input" placeholder="09XXXXXXXXX"
              value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>">
          </div>
          <div>
            <label class="block text-xs font-bold uppercase tracking-widest text-white/50 mb-1.5 ml-1">Address</label>
            <input type="text" name="address" id="f_address" class="glass-input" placeholder="City, Province"
              value="<?= htmlspecialchars($_POST['address'] ?? '') ?>">
          </div>
        </div>

        <div style="border-top:1px solid rgba(255,255,255,0.08);padding-top:18px;">
          <p class="text-xs font-bold uppercase tracking-widest text-white/50 mb-4">Owner / Account Information</p>
          <div class="space-y-4">
            <div>
              <label class="block text-xs font-bold uppercase tracking-widest text-white/50 mb-1.5 ml-1">Full Name *</label>
              <input type="text" name="fullname" id="f_fullname" class="glass-input" placeholder="Juan Dela Cruz"
                value="<?= htmlspecialchars($_POST['fullname'] ?? '') ?>" required>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div>
                <label class="block text-xs font-bold uppercase tracking-widest text-white/50 mb-1.5 ml-1">Email *</label>
                <input type="email" name="email" id="f_email" class="glass-input" placeholder="owner@example.com"
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
        <div id="plan_summary" style="background:rgba(59,130,246,0.1);border:1px solid rgba(59,130,246,0.25);border-radius:12px;padding:14px 16px;font-size:0.82rem;">
          <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;">
            <span style="color:rgba(255,255,255,0.7);">Selected Plan: <strong style="color:#93c5fd;" id="summary_plan"><?= htmlspecialchars($selected_plan) ?></strong></span>
            <span style="font-size:0.75rem;color:rgba(255,255,255,0.35);" id="summary_price"><?= $plans[$selected_plan]['price'] ?></span>
          </div>
          <div id="summary_desc" style="font-size:0.76rem;color:rgba(147,197,253,0.8);line-height:1.6;"></div>
        </div>

        <button type="submit" id="submitBtn" style="width:100%;padding:14px;background:#3b82f6;color:#fff;border:none;border-radius:12px;font-family:'Inter',sans-serif;font-size:0.95rem;font-weight:700;cursor:pointer;box-shadow:0 4px 20px rgba(59,130,246,0.3);transition:all 0.2s;">
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
    <span class="text-white/40">© <?= date('Y') ?> PawnHub. All rights reserved.</span>
  </div>
</footer>

<script>
const planData = {
  Starter:    { price:'Free',      desc:'✅ 1 Manager · Up to 3 Staff/Cashier · Basic Reports · Core pawnshop features', branches:1 },
  Pro:        { price:'₱999/mo',   desc:'✅ 1 Manager · Unlimited Staff · Advanced Reports · Custom Branding · Priority Support', branches:3 },
  Enterprise: { price:'₱2,499/mo', desc:'✅ 1 Manager · Unlimited Staff · White-Label · Data Export · Dedicated Account Manager', branches:10 },
};

function selectPlan(name) {
  const p = planData[name];
  document.getElementById('plan_input').value          = name;
  document.getElementById('branches_input').value      = p.branches;
  document.getElementById('summary_plan').textContent  = name;
  document.getElementById('summary_price').textContent = p.price;
  document.getElementById('summary_desc').textContent  = p.desc;
  ['Starter','Pro','Enterprise'].forEach(n => {
    document.getElementById('pill-'+n).classList.toggle('active', n === name);
  });
}
selectPlan(document.getElementById('plan_input').value || 'Starter');

// ── Permit upload handling ────────────────────────────────────
let permitBase64 = '';

function handlePermitDrop(e) {
  e.preventDefault();
  document.getElementById('permitDrop').classList.remove('drag-over');
  const file = e.dataTransfer.files[0];
  if (file) processPermitFile(file);
}

function handlePermitFile(input) {
  const file = input.files[0];
  if (file) processPermitFile(file);
}

function processPermitFile(file) {
  const reader = new FileReader();
  reader.onload = function(e) {
    permitBase64 = e.target.result;
    // Show preview
    document.getElementById('permitDrop').classList.add('hidden');
    const wrap = document.getElementById('permitPreviewWrap');
    wrap.classList.remove('hidden');
    if (file.type.startsWith('image/')) {
      document.getElementById('permitPreviewImg').src = permitBase64;
    } else {
      document.getElementById('permitPreviewImg').src = '';
      document.getElementById('permitPreviewImg').alt = '📄 PDF Uploaded: ' + file.name;
    }
    // Start AI extraction
    runAIExtraction(permitBase64);
  };
  reader.readAsDataURL(file);
}

function clearPermit() {
  permitBase64 = '';
  document.getElementById('permitFile').value = '';
  document.getElementById('permitDrop').classList.remove('hidden');
  document.getElementById('permitPreviewWrap').classList.add('hidden');
  document.getElementById('permit_data_hidden').value = '';
  ['aiStatusAnalyzing','aiStatusApproved','aiStatusRejected','aiConfidenceWrap'].forEach(id => {
    document.getElementById(id).classList.add('hidden');
  });
  // Remove autofill highlighting
  ['f_business_name','f_phone','f_address','f_fullname'].forEach(id => {
    document.getElementById(id).classList.remove('field-autofilled');
  });
}

async function runAIExtraction(imageData) {
  // Show analyzing
  ['aiStatusApproved','aiStatusRejected'].forEach(id => document.getElementById(id).classList.add('hidden'));
  document.getElementById('aiStatusAnalyzing').classList.remove('hidden');
  document.getElementById('aiConfidenceWrap').classList.add('hidden');

  try {
    const formData = new FormData();
    formData.append('action', 'extract_permit');
    formData.append('image_data', imageData);

    const resp = await fetch(window.location.href, { method:'POST', body:formData });
    const result = await resp.json();

    document.getElementById('aiStatusAnalyzing').classList.add('hidden');

    if (result.success && result.data) {
      const d = result.data;
      document.getElementById('permit_data_hidden').value = JSON.stringify(d);

      // Show confidence
      if (d.confidence) {
        document.getElementById('aiConfidence').textContent = d.confidence;
        document.getElementById('aiConfidenceWrap').classList.remove('hidden');
      }

      if (d.is_valid_permit) {
        document.getElementById('aiStatusApproved').classList.remove('hidden');
        // Auto-fill fields
        autoFill('f_business_name', d.business_name);
        autoFill('f_fullname',      d.owner_name);
        autoFill('f_phone',         d.phone);
        autoFill('f_address',       d.address);
      } else {
        document.getElementById('aiRejectMsg').textContent = d.rejection_reason || 'Could not verify permit — please check fields manually';
        document.getElementById('aiStatusRejected').classList.remove('hidden');
      }
    } else {
      document.getElementById('aiRejectMsg').textContent = result.error || 'AI extraction failed';
      document.getElementById('aiStatusRejected').classList.remove('hidden');
    }
  } catch(err) {
    document.getElementById('aiStatusAnalyzing').classList.add('hidden');
    document.getElementById('aiRejectMsg').textContent = 'Could not connect to AI — fill manually';
    document.getElementById('aiStatusRejected').classList.remove('hidden');
  }
}

function autoFill(fieldId, value) {
  if (!value) return;
  const el = document.getElementById(fieldId);
  if (el && !el.value) {
    el.value = value;
    el.classList.add('field-autofilled');
    setTimeout(() => el.classList.remove('field-autofilled'), 3000);
  }
}
</script>
</body>
</html>