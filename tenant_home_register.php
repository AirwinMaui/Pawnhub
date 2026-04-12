<?php
/**
 * tenant_home_register.php — Walk-in Applicant Registration
 * Accessible via /{slug}?register=1 from the tenant home page
 * Roles: manager, staff, cashier
 * Requires approval from the tenant admin (tenant.php > Applicants page)
 */

require 'db.php';
require 'theme_helper.php';
require_once __DIR__ . '/session_helper.php';

$slug = trim($_GET['slug'] ?? '');
if (!$slug) { header('Location: /'); exit; }

// Load tenant
$stmt = $pdo->prepare("SELECT * FROM tenants WHERE slug=? AND status='active' LIMIT 1");
$stmt->execute([$slug]);
$tenant = $stmt->fetch();
if (!$tenant) { header('Location: /'); exit; }

$tid   = $tenant['id'];
$theme = getTenantTheme($pdo, $tid);

$primary   = $theme['primary_color']   ?? '#2563eb';
$secondary = $theme['secondary_color'] ?? '#1e3a8a';
$accent    = $theme['accent_color']    ?? '#10b981';
$logo_url  = $theme['logo_url']        ?? '';
// Shop background: query shop_bg_url from DB, fallback to bg_image_url
try {
    $sbq = $pdo->prepare("SELECT shop_bg_url FROM tenant_settings WHERE tenant_id=? LIMIT 1");
    $sbq->execute([$tid]);
    $bg_url = $sbq->fetchColumn() ?: '';
} catch (Throwable $e) { $bg_url = ''; }
if (!$bg_url) $bg_url = $tenant['bg_image_url'] ?? '';
// Normalize local upload paths (fix old records without leading slash)
if ($bg_url   && strpos($bg_url,  'http') !== 0 && $bg_url[0]   !== '/') $bg_url   = '/' . $bg_url;
if ($logo_url && strpos($logo_url,'http') !== 0 && $logo_url[0] !== '/') $logo_url = '/' . $logo_url;
$biz_name  = htmlspecialchars($tenant['business_name']);
$login_url = '/' . rawurlencode($slug) . '?login=1';
$home_url  = '/' . rawurlencode($slug);

$success_msg = '';
$error_msg   = '';
$submitted   = false;

// ── Handle POST ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fullname = trim($_POST['fullname']  ?? '');
    $email    = trim($_POST['email']     ?? '');
    $username = trim($_POST['username']  ?? '');
    $password = trim($_POST['password']  ?? '');
    $confirm  = trim($_POST['confirm']   ?? '');
    $contact  = trim($_POST['contact']   ?? '');
    $role     = trim($_POST['role']      ?? '');
    $note     = trim($_POST['note']      ?? '');

    $valid_roles = ['manager', 'staff', 'cashier'];

    // Build expected suffix and strip it to get the base username for validation
    $slug_suffix = '@' . $slug . '.com';
    $base_username = '';
    if (str_ends_with($username, $slug_suffix)) {
        $base_username = substr($username, 0, strlen($username) - strlen($slug_suffix));
    }

    if (!$fullname || !$email || !$username || !$password || !$role) {
        $error_msg = 'Please fill in all required fields.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error_msg = 'Invalid email address.';
    } elseif (!in_array($role, $valid_roles)) {
        $error_msg = 'Invalid role selected.';
    } elseif (strlen($password) < 8) {
        $error_msg = 'Password must be at least 8 characters.';
    } elseif ($password !== $confirm) {
        $error_msg = 'Passwords do not match.';
    } elseif (
        !str_ends_with($username, $slug_suffix) ||
        !preg_match('/^[a-zA-Z0-9_]{1,30}$/', $base_username)
    ) {
        $error_msg = 'Username must be in the format: yourname@' . $slug . '.com (only letters, numbers, underscores before the @)';
    } else {
        // Check uniqueness
        $chk = $pdo->prepare("SELECT id FROM users WHERE (email=? OR username=?) AND tenant_id=? LIMIT 1");
        $chk->execute([$email, $username, $tid]);
        if ($chk->fetch()) {
            $error_msg = 'Email or username is already registered in this branch.';
        } else {
            // Check applicants table too
            $chk2 = $pdo->prepare("SELECT id FROM tenant_applicants WHERE (email=? OR username=?) AND tenant_id=? AND status='pending' LIMIT 1");
            $chk2->execute([$email, $username, $tid]);
            if ($chk2->fetch()) {
                $error_msg = 'You already have a pending application at this branch.';
            }
        }
    }

    // Handle resume/photo upload
    $resume_path = null;
    if (!$error_msg && !empty($_FILES['resume']['name'])) {
        $allowed_types = ['image/jpeg','image/png','image/webp','application/pdf'];
        $ftype = mime_content_type($_FILES['resume']['tmp_name']);
        if (!in_array($ftype, $allowed_types)) {
            $error_msg = 'Resume/photo must be JPG, PNG, WebP, or PDF (max 5MB).';
        } elseif ($_FILES['resume']['size'] > 5 * 1024 * 1024) {
            $error_msg = 'File is too large. Max 5MB.';
        } else {
            $ext     = pathinfo($_FILES['resume']['name'], PATHINFO_EXTENSION);
            $fname   = 'applicant_' . $tid . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
            $dir     = __DIR__ . '/uploads/applicants/';
            if (!is_dir($dir)) mkdir($dir, 0755, true);
            if (move_uploaded_file($_FILES['resume']['tmp_name'], $dir . $fname)) {
                $resume_path = 'uploads/applicants/' . $fname;
            } else {
                $error_msg = 'Failed to upload file. Please try again.';
            }
        }
    }

    if (!$error_msg) {
        try {
            $pdo->prepare("
                INSERT INTO tenant_applicants
                    (tenant_id, fullname, email, username, password_hash, contact_number, role, note, resume_path, status, applied_at)
                VALUES (?,?,?,?,?,?,?,?,?,'pending',NOW())
            ")->execute([
                $tid, $fullname, $email, $username,
                password_hash($password, PASSWORD_BCRYPT),
                $contact ?: null, $role, $note ?: null, $resume_path
            ]);
            $submitted = true;
        } catch (Throwable $e) {
            error_log('Applicant insert failed: ' . $e->getMessage());
            $error_msg = 'Registration failed. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Join <?= $biz_name ?> — Apply Now</title>
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700;800&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet">
<style>
:root {
  --primary:   <?= $primary ?>;
  --secondary: <?= $secondary ?>;
  --accent:    <?= $accent ?>;
  --bg:        #060810;
  --glass:     rgba(255,255,255,0.05);
  --glass2:    rgba(255,255,255,0.09);
  --border:    rgba(255,255,255,0.09);
  --text:      #eef0f8;
  --text-m:    rgba(238,240,248,0.6);
  --text-dim:  rgba(238,240,248,0.3);
  --radius:    18px;
  --danger:    #ef4444;
  --success:   #10b981;
}

*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
html { scroll-behavior: smooth; }

body {
  font-family: 'Sora', sans-serif;
  background: var(--bg);
  color: var(--text);
  min-height: 100vh;
  overflow-x: hidden;
}

.ms { font-family: 'Material Symbols Outlined'; font-variation-settings: 'FILL' 0,'wght' 400,'GRAD' 0,'opsz' 24; }
.ms-fill { font-variation-settings: 'FILL' 1,'wght' 400,'GRAD' 0,'opsz' 24; }

/* ── BG ── */
.bg-scene { position: fixed; inset: 0; z-index: 0; pointer-events: none; }
.bg-scene-img { width: 100%; height: 100%; object-fit: cover; opacity: .1; filter: saturate(0.3) brightness(0.5); }
.bg-gradient {
  position: absolute; inset: 0;
  background:
    radial-gradient(ellipse 70% 50% at 80% 10%, color-mix(in srgb, var(--primary) 22%, transparent), transparent 70%),
    radial-gradient(ellipse 50% 40% at 10% 80%, color-mix(in srgb, var(--accent) 14%, transparent), transparent 70%),
    linear-gradient(180deg, rgba(6,8,16,.2) 0%, #060810 55%);
}
.noise {
  position: absolute; inset: 0; opacity: .025;
  background-image: url("data:image/svg+xml,%3Csvg viewBox='0 0 256 256' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.9' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)'/%3E%3C/svg%3E");
}

/* ── NAV ── */
nav {
  position: fixed; top: 0; left: 0; right: 0; z-index: 100; height: 66px;
  display: flex; align-items: center; justify-content: space-between;
  padding: 0 clamp(16px,4vw,48px);
  background: rgba(6,8,16,.75); backdrop-filter: blur(20px);
  border-bottom: 1px solid var(--border);
}
.nav-brand { display: flex; align-items: center; gap: 10px; text-decoration: none; }
.nav-logo {
  width: 38px; height: 38px; border-radius: 11px;
  background: linear-gradient(135deg, var(--primary), var(--secondary));
  display: flex; align-items: center; justify-content: center; overflow: hidden;
  box-shadow: 0 4px 14px color-mix(in srgb, var(--primary) 40%, transparent);
}
.nav-logo img { width: 100%; height: 100%; object-fit: cover; }
.nav-logo svg { width: 18px; height: 18px; }
.nav-name { font-size: 1.05rem; font-weight: 700; color: #fff; }
.nav-right { display: flex; align-items: center; gap: 10px; }
.nav-back {
  display: flex; align-items: center; gap: 6px; font-size: .83rem; font-weight: 600;
  color: var(--text-m); text-decoration: none; padding: 7px 14px; border-radius: 10px;
  border: 1px solid var(--border); background: var(--glass); transition: all .18s;
}
.nav-back:hover { color: #fff; background: var(--glass2); }
.nav-signin {
  display: flex; align-items: center; gap: 6px; font-size: .83rem; font-weight: 700;
  color: #fff; text-decoration: none; padding: 8px 18px; border-radius: 10px;
  background: var(--primary); transition: all .2s;
  box-shadow: 0 3px 14px color-mix(in srgb, var(--primary) 35%, transparent);
}
.nav-signin:hover { filter: brightness(1.1); }

/* ── PAGE LAYOUT ── */
.page-wrap {
  min-height: 100vh; display: flex; align-items: center; justify-content: center;
  padding: 86px 16px 48px; position: relative; z-index: 10;
}

/* ── CARD ── */
.reg-card {
  width: 100%; max-width: 680px;
  background: rgba(255,255,255,.04);
  border: 1px solid var(--border);
  border-radius: 24px;
  overflow: hidden;
  box-shadow: 0 32px 80px rgba(0,0,0,.6), 0 0 0 1px rgba(255,255,255,.04);
}

/* ── CARD HEADER ── */
.card-head {
  background: linear-gradient(135deg, var(--secondary), var(--primary));
  padding: 32px 36px 28px; position: relative; overflow: hidden;
}
.card-head::before {
  content: '';
  position: absolute; top: -50px; right: -50px;
  width: 180px; height: 180px; border-radius: 50%;
  background: rgba(255,255,255,.07);
}
.card-head::after {
  content: '';
  position: absolute; bottom: -70px; left: -30px;
  width: 200px; height: 200px; border-radius: 50%;
  background: rgba(255,255,255,.04);
}
.card-head-inner { position: relative; z-index: 1; display: flex; align-items: center; gap: 16px; }
.card-logo {
  width: 56px; height: 56px; border-radius: 15px; flex-shrink: 0;
  background: rgba(255,255,255,.15); display: flex; align-items: center; justify-content: center;
  overflow: hidden; backdrop-filter: blur(8px);
  border: 1px solid rgba(255,255,255,.2);
}
.card-logo img { width: 100%; height: 100%; object-fit: cover; }
.card-logo svg { width: 26px; height: 26px; }
.card-head-title { font-size: 1.4rem; font-weight: 800; color: #fff; line-height: 1.2; }
.card-head-sub { font-size: .8rem; color: rgba(255,255,255,.65); margin-top: 4px; }

/* ── CARD BODY ── */
.card-body { padding: 32px 36px 36px; }

/* ── ROLE SELECTOR ── */
.role-grid { display: grid; grid-template-columns: repeat(3,1fr); gap: 10px; margin-bottom: 24px; }
.role-opt { display: none; }
.role-label {
  display: flex; flex-direction: column; align-items: center; gap: 8px;
  padding: 14px 10px; border-radius: 14px; cursor: pointer;
  background: var(--glass); border: 1.5px solid var(--border);
  transition: all .2s; text-align: center; user-select: none;
}
.role-label:hover { background: var(--glass2); border-color: rgba(255,255,255,.2); }
.role-opt:checked + .role-label {
  background: color-mix(in srgb, var(--primary) 18%, transparent);
  border-color: var(--primary);
  box-shadow: 0 0 0 3px color-mix(in srgb, var(--primary) 18%, transparent);
}
.role-icon {
  width: 42px; height: 42px; border-radius: 12px; display: flex; align-items: center; justify-content: center;
  font-size: 20px; transition: background .2s;
}
.role-opt:checked + .role-label .role-icon {
  background: color-mix(in srgb, var(--primary) 25%, transparent);
  color: var(--primary);
}
.role-name { font-size: .78rem; font-weight: 700; color: rgba(255,255,255,.7); transition: color .2s; }
.role-opt:checked + .role-label .role-name { color: #fff; }
.role-desc { font-size: .67rem; color: var(--text-dim); line-height: 1.4; }

/* ── FORM ── */
.form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
.form-full { grid-column: 1 / -1; }
.fg { display: flex; flex-direction: column; gap: 5px; }
.flabel {
  font-size: .72rem; font-weight: 600; letter-spacing: .04em; text-transform: uppercase;
  color: var(--text-dim);
}
.flabel span { color: var(--danger); margin-left: 2px; }
.finput, .fselect, .ftextarea {
  width: 100%; background: var(--glass); border: 1.5px solid var(--border);
  border-radius: 11px; padding: 10px 14px;
  font-family: 'Sora', sans-serif; font-size: .85rem; color: var(--text);
  outline: none; transition: border .2s, box-shadow .2s;
}
.finput::placeholder, .ftextarea::placeholder { color: var(--text-dim); }
.finput:focus, .fselect:focus, .ftextarea:focus {
  border-color: var(--primary);
  box-shadow: 0 0 0 3px color-mix(in srgb, var(--primary) 15%, transparent);
  background: color-mix(in srgb, var(--primary) 5%, rgba(255,255,255,.04));
}
.fselect { appearance: none; cursor: pointer; }
.ftextarea { resize: vertical; min-height: 90px; }
.pass-wrap { position: relative; }
.pass-wrap .finput { padding-right: 44px; }
.pass-toggle {
  position: absolute; right: 13px; top: 50%; transform: translateY(-50%);
  color: var(--text-dim); cursor: pointer; font-size: 18px; background: none; border: none;
  display: flex; align-items: center; justify-content: center;
  transition: color .15s;
}
.pass-toggle:hover { color: var(--text-m); }

/* ── FILE UPLOAD ── */
.file-drop {
  border: 2px dashed var(--border); border-radius: 13px; padding: 22px;
  text-align: center; cursor: pointer; transition: all .2s;
  background: var(--glass);
}
.file-drop:hover, .file-drop.dragover {
  border-color: var(--primary);
  background: color-mix(in srgb, var(--primary) 8%, transparent);
}
.file-drop input[type=file] { display: none; }
.file-drop-icon { font-size: 32px; color: var(--text-dim); margin-bottom: 8px; display: block; }
.file-drop-title { font-size: .82rem; font-weight: 600; color: var(--text-m); margin-bottom: 3px; }
.file-drop-hint { font-size: .7rem; color: var(--text-dim); }
.file-preview {
  display: none; align-items: center; gap: 10px;
  background: color-mix(in srgb, var(--accent) 10%, transparent);
  border: 1px solid color-mix(in srgb, var(--accent) 25%, transparent);
  border-radius: 11px; padding: 10px 14px; margin-top: 10px;
}
.file-preview-icon { font-size: 20px; color: var(--accent); }
.file-preview-name { font-size: .8rem; font-weight: 600; color: #fff; flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.file-preview-rm { color: var(--text-dim); font-size: 16px; cursor: pointer; background: none; border: none; display: flex; }
.file-preview-rm:hover { color: var(--danger); }

/* ── DIVIDER ── */
.divider { height: 1px; background: var(--border); margin: 20px 0; }

/* ── SECTION LABEL ── */
.section-tag {
  font-size: .65rem; font-weight: 700; letter-spacing: .1em; text-transform: uppercase;
  color: color-mix(in srgb, var(--accent) 90%, #fff);
  display: flex; align-items: center; gap: 6px; margin-bottom: 14px;
}
.section-tag::after { content: ''; flex: 1; height: 1px; background: color-mix(in srgb, var(--accent) 20%, transparent); }

/* ── SUBMIT ── */
.btn-submit {
  width: 100%; padding: 14px;
  background: linear-gradient(135deg, var(--primary), color-mix(in srgb, var(--primary) 70%, var(--secondary)));
  color: #fff; font-family: 'Sora', sans-serif; font-size: .95rem; font-weight: 700;
  border: none; border-radius: 13px; cursor: pointer;
  box-shadow: 0 6px 24px color-mix(in srgb, var(--primary) 40%, transparent);
  transition: all .22s; display: flex; align-items: center; justify-content: center; gap: 9px;
  margin-top: 24px;
}
.btn-submit:hover { filter: brightness(1.1); transform: translateY(-2px); }
.btn-submit:active { transform: translateY(0); }
.btn-submit:disabled { opacity: .5; pointer-events: none; }
.btn-submit .ms { font-size: 20px; }

/* ── ALERTS ── */
.alert {
  display: flex; align-items: flex-start; gap: 10px;
  padding: 13px 16px; border-radius: 13px; font-size: .83rem; line-height: 1.6;
  margin-bottom: 20px;
}
.alert-error { background: rgba(239,68,68,.12); border: 1px solid rgba(239,68,68,.3); color: #fca5a5; }
.alert-success { background: rgba(16,185,129,.12); border: 1px solid rgba(16,185,129,.3); color: #6ee7b7; }
.alert .ms { font-size: 20px; flex-shrink: 0; margin-top: 1px; }

/* ── SUCCESS STATE ── */
.success-state { text-align: center; padding: 20px 0 10px; }
.success-icon {
  width: 72px; height: 72px; border-radius: 50%;
  background: color-mix(in srgb, var(--accent) 18%, transparent);
  border: 2px solid color-mix(in srgb, var(--accent) 35%, transparent);
  display: flex; align-items: center; justify-content: center;
  margin: 0 auto 18px; font-size: 36px; color: var(--accent);
}
.success-title { font-size: 1.5rem; font-weight: 800; color: #fff; margin-bottom: 8px; }
.success-sub { font-size: .88rem; color: var(--text-m); line-height: 1.7; max-width: 380px; margin: 0 auto 24px; }
.success-chip {
  display: inline-flex; align-items: center; gap: 6px;
  font-size: .75rem; font-weight: 600;
  background: color-mix(in srgb, var(--accent) 12%, transparent);
  border: 1px solid color-mix(in srgb, var(--accent) 25%, transparent);
  color: color-mix(in srgb, var(--accent) 90%, #fff);
  padding: 6px 14px; border-radius: 100px; margin-bottom: 20px;
}
.btn-back {
  display: inline-flex; align-items: center; gap: 7px; text-decoration: none;
  font-size: .88rem; font-weight: 700; color: var(--text-m);
  padding: 11px 22px; border-radius: 12px;
  border: 1px solid var(--border); background: var(--glass); transition: all .18s;
}
.btn-back:hover { color: #fff; background: var(--glass2); }

/* ── PASS STRENGTH ── */
.pass-strength-bar { display: flex; gap: 4px; margin-top: 6px; }
.pass-seg { flex: 1; height: 3px; border-radius: 100px; background: rgba(255,255,255,.1); transition: background .3s; }
.pass-seg.weak { background: #ef4444; }
.pass-seg.fair { background: #f59e0b; }
.pass-seg.good { background: #10b981; }
.pass-seg.strong { background: #3b82f6; }
.pass-hint { font-size: .69rem; color: var(--text-dim); margin-top: 4px; }

/* ── USERNAME MONO ── */
.finput.mono { font-family: 'DM Mono', monospace; letter-spacing: .01em; }

@media (max-width: 580px) {
  .form-grid { grid-template-columns: 1fr; }
  .form-full { grid-column: auto; }
  .card-body { padding: 24px 20px 28px; }
  .card-head { padding: 24px 20px 20px; }
  .role-grid { grid-template-columns: 1fr; }
}
</style>
</head>
<body>

<!-- BG -->
<div class="bg-scene">
  <?php if($bg_url): ?>
  <img class="bg-scene-img" src="<?= htmlspecialchars($bg_url) ?>" alt="">
  <?php endif; ?>
  <div class="bg-gradient"></div>
  <div class="noise"></div>
</div>

<!-- NAV -->
<nav>
  <a href="<?= htmlspecialchars($home_url) ?>" class="nav-brand">
    <div class="nav-logo">
      <?php if($logo_url): ?>
        <img src="<?= htmlspecialchars($logo_url) ?>" alt="logo">
      <?php else: ?>
        <svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2.5"><rect x="3" y="9" width="18" height="12"/><polyline points="3 9 12 3 21 9"/></svg>
      <?php endif; ?>
    </div>
    <span class="nav-name"><?= $biz_name ?></span>
  </a>
  <div class="nav-right">
    <a href="<?= htmlspecialchars($home_url) ?>" class="nav-back">
      <span class="ms">arrow_back</span> Back
    </a>
    <a href="<?= htmlspecialchars($login_url) ?>" class="nav-signin">
      <span class="ms">login</span> Sign In
    </a>
  </div>
</nav>

<!-- MAIN -->
<div class="page-wrap">
  <div class="reg-card">
    <!-- Header -->
    <div class="card-head">
      <div class="card-head-inner">
        <div class="card-logo">
          <?php if($logo_url): ?>
            <img src="<?= htmlspecialchars($logo_url) ?>" alt="logo">
          <?php else: ?>
            <svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2.5"><rect x="3" y="9" width="18" height="12"/><polyline points="3 9 12 3 21 9"/></svg>
          <?php endif; ?>
        </div>
        <div>
          <div class="card-head-title">Apply to Join <?= $biz_name ?></div>
          <div class="card-head-sub">Your application will be reviewed and you'll be notified by email once approved.</div>
        </div>
      </div>
    </div>

    <!-- Body -->
    <div class="card-body">
      <?php if($submitted): ?>
      <!-- SUCCESS -->
      <div class="success-state">
        <div class="success-icon"><span class="ms ms-fill">check_circle</span></div>
        <div class="success-title">Application Submitted!</div>
        <div class="success-sub">
          Your application has been received. The branch admin will review it and send you an email once you're approved. You'll be able to sign in after approval.
        </div>
        <div class="success-chip">
          <span class="ms ms-fill" style="font-size:14px;">schedule</span>
          Pending Admin Approval
        </div>
        <br>
        <a href="<?= htmlspecialchars($home_url) ?>" class="btn-back">
          <span class="ms">arrow_back</span> Back to <?= $biz_name ?>
        </a>
      </div>

      <?php else: ?>

      <?php if($error_msg): ?>
      <div class="alert alert-error">
        <span class="ms">error</span>
        <?= htmlspecialchars($error_msg) ?>
      </div>
      <?php endif; ?>

      <form method="POST" enctype="multipart/form-data" id="regForm" onsubmit="return validateForm()">

        <!-- Role Selection -->
        <div class="section-tag"><span class="ms" style="font-size:15px;">badge</span>Select Your Role</div>
        <div class="role-grid">
          <div>
            <input type="radio" name="role" id="role_manager" class="role-opt" value="manager" <?= ($_POST['role']??'')==='manager'?'checked':'' ?>>
            <label for="role_manager" class="role-label">
              <div class="role-icon" style="background:rgba(16,185,129,.12);color:#6ee7b7;">
                <span class="ms">manage_accounts</span>
              </div>
              <span class="role-name">Manager</span>
              <span class="role-desc">Oversees staff & operations</span>
            </label>
          </div>
          <div>
            <input type="radio" name="role" id="role_staff" class="role-opt" value="staff" <?= ($_POST['role']??'')==='staff'||!isset($_POST['role'])?'checked':'' ?>>
            <label for="role_staff" class="role-label">
              <div class="role-icon" style="background:rgba(59,130,246,.12);color:#93c5fd;">
                <span class="ms">badge</span>
              </div>
              <span class="role-name">Staff</span>
              <span class="role-desc">Creates tickets & customers</span>
            </label>
          </div>
          <div>
            <input type="radio" name="role" id="role_cashier" class="role-opt" value="cashier" <?= ($_POST['role']??'')==='cashier'?'checked':'' ?>>
            <label for="role_cashier" class="role-label">
              <div class="role-icon" style="background:rgba(139,92,246,.12);color:#c4b5fd;">
                <span class="ms">point_of_sale</span>
              </div>
              <span class="role-name">Cashier</span>
              <span class="role-desc">Processes payments</span>
            </label>
          </div>
        </div>

        <div class="divider"></div>

        <!-- Personal Info -->
        <div class="section-tag"><span class="ms" style="font-size:15px;">person</span>Personal Information</div>
        <div class="form-grid">
          <div class="fg form-full">
            <label class="flabel" for="fullname">Full Name <span>*</span></label>
            <input type="text" id="fullname" name="fullname" class="finput" placeholder="Juan dela Cruz" required value="<?= htmlspecialchars($_POST['fullname']??'') ?>">
          </div>
          <div class="fg">
            <label class="flabel" for="email">Email Address <span>*</span></label>
            <input type="email" id="email" name="email" class="finput" placeholder="juan@example.com" required value="<?= htmlspecialchars($_POST['email']??'') ?>">
          </div>
          <div class="fg">
            <label class="flabel" for="contact">Contact Number</label>
            <input type="tel" id="contact" name="contact" class="finput" placeholder="09XXXXXXXXX" value="<?= htmlspecialchars($_POST['contact']??'') ?>">
          </div>
        </div>

        <div class="divider"></div>

        <!-- Account Credentials -->
        <div class="section-tag"><span class="ms" style="font-size:15px;">lock</span>Account Credentials</div>
        <div class="form-grid">
          <div class="fg form-full">
            <label class="flabel" for="username">Username <span>*</span></label>
            <input type="text" id="username" name="username" class="finput mono" placeholder="juandelacruz"
              required value="<?= htmlspecialchars($_POST['username']??'') ?>">
            <span style="font-size:.69rem;color:var(--text-dim);margin-top:4px;">Your username will be in the format <strong style="color:var(--text-m);">yourname@<?= htmlspecialchars($slug) ?>.com</strong></span>
          </div>
          <div class="fg">
            <label class="flabel" for="password">Password <span>*</span></label>
            <div class="pass-wrap">
              <input type="password" id="password" name="password" class="finput" placeholder="Min. 8 characters" required minlength="8" oninput="checkStrength()">
              <button type="button" class="pass-toggle" onclick="togglePass('password',this)"><span class="ms">visibility</span></button>
            </div>
            <div class="pass-strength-bar" id="strengthBar">
              <div class="pass-seg" id="seg1"></div>
              <div class="pass-seg" id="seg2"></div>
              <div class="pass-seg" id="seg3"></div>
              <div class="pass-seg" id="seg4"></div>
            </div>
            <span class="pass-hint" id="passHint">Enter a password</span>
          </div>
          <div class="fg">
            <label class="flabel" for="confirm">Confirm Password <span>*</span></label>
            <div class="pass-wrap">
              <input type="password" id="confirm" name="confirm" class="finput" placeholder="Re-enter password" required minlength="8">
              <button type="button" class="pass-toggle" onclick="togglePass('confirm',this)"><span class="ms">visibility</span></button>
            </div>
            <span class="pass-hint" id="confirmHint"></span>
          </div>
        </div>

        <div class="divider"></div>

        <!-- Resume / Photo -->
        <div class="section-tag"><span class="ms" style="font-size:15px;">upload_file</span>Resume or ID Photo</div>
        <div class="file-drop" id="dropZone" onclick="document.getElementById('resumeInput').click()"
          ondragover="event.preventDefault();this.classList.add('dragover')"
          ondragleave="this.classList.remove('dragover')"
          ondrop="handleDrop(event)">
          <span class="ms file-drop-icon">upload_file</span>
          <div class="file-drop-title">Click to upload or drag & drop</div>
          <div class="file-drop-hint">JPG, PNG, WebP, or PDF · Max 5MB</div>
          <input type="file" id="resumeInput" name="resume" accept=".jpg,.jpeg,.png,.webp,.pdf" onchange="previewFile(this)">
        </div>
        <div class="file-preview" id="filePreview">
          <span class="ms ms-fill file-preview-icon">description</span>
          <span class="file-preview-name" id="fileName">—</span>
          <button type="button" class="file-preview-rm" onclick="removeFile()"><span class="ms">close</span></button>
        </div>

        <div class="divider"></div>

        <!-- Note -->
        <div class="section-tag"><span class="ms" style="font-size:15px;">chat_bubble</span>Note to Admin <span style="font-size:.65rem;color:var(--text-dim);font-weight:400;text-transform:none;letter-spacing:0;">(optional)</span></div>
        <div class="fg">
          <textarea name="note" class="ftextarea finput" placeholder="Tell us a bit about your experience or why you'd like to join..."><?= htmlspecialchars($_POST['note']??'') ?></textarea>
        </div>

        <!-- Terms notice -->
        <div style="display:flex;align-items:flex-start;gap:10px;padding:12px 14px;background:color-mix(in srgb,var(--primary) 8%,transparent);border:1px solid color-mix(in srgb,var(--primary) 20%,transparent);border-radius:12px;margin-top:18px;">
          <span class="ms ms-fill" style="color:var(--primary);font-size:18px;flex-shrink:0;margin-top:1px;">info</span>
          <p style="font-size:.77rem;color:var(--text-m);line-height:1.65;">
            Your application will be reviewed by <strong style="color:#fff;"><?= $biz_name ?></strong>. You'll receive an email once your account is approved. You will not be able to sign in until approval.
          </p>
        </div>

        <button type="submit" class="btn-submit" id="submitBtn">
          <span class="ms">send</span> Submit Application
        </button>

      </form>
      <?php endif; ?>
    </div>
  </div>
</div>

<script>
// ── Toggle Password Visibility ───────────────────────────────
function togglePass(id, btn) {
  const inp = document.getElementById(id);
  const icon = btn.querySelector('.ms');
  if (inp.type === 'password') {
    inp.type = 'text';
    icon.textContent = 'visibility_off';
  } else {
    inp.type = 'password';
    icon.textContent = 'visibility';
  }
}

// ── Password Strength ────────────────────────────────────────
function checkStrength() {
  const pw = document.getElementById('password').value;
  const segs = [document.getElementById('seg1'),document.getElementById('seg2'),document.getElementById('seg3'),document.getElementById('seg4')];
  const hint = document.getElementById('passHint');
  segs.forEach(s => { s.className = 'pass-seg'; });
  let score = 0;
  if (pw.length >= 8) score++;
  if (/[A-Z]/.test(pw)) score++;
  if (/[0-9]/.test(pw)) score++;
  if (/[^a-zA-Z0-9]/.test(pw)) score++;
  const labels = ['', 'Weak', 'Fair', 'Good', 'Strong'];
  const cls    = ['', 'weak', 'fair', 'good', 'strong'];
  for (let i = 0; i < score; i++) segs[i].classList.add(cls[score]);
  hint.textContent = pw.length === 0 ? 'Enter a password' : labels[score] || 'Too short';
  checkConfirm();
}

function checkConfirm() {
  const pw = document.getElementById('password').value;
  const cf = document.getElementById('confirm').value;
  const hint = document.getElementById('confirmHint');
  if (!cf) { hint.textContent = ''; return; }
  if (pw === cf) {
    hint.textContent = '✓ Passwords match';
    hint.style.color = 'var(--success)';
  } else {
    hint.textContent = '✗ Passwords do not match';
    hint.style.color = 'var(--danger)';
  }
}
document.getElementById('confirm')?.addEventListener('input', checkConfirm);

// ── File Upload ──────────────────────────────────────────────
function previewFile(input) {
  if (input.files && input.files[0]) {
    document.getElementById('fileName').textContent = input.files[0].name;
    document.getElementById('filePreview').style.display = 'flex';
    document.querySelector('.file-drop .file-drop-title').textContent = 'File selected (click to change)';
  }
}
function removeFile() {
  document.getElementById('resumeInput').value = '';
  document.getElementById('filePreview').style.display = 'none';
  document.querySelector('.file-drop .file-drop-title').textContent = 'Click to upload or drag & drop';
}
function handleDrop(e) {
  e.preventDefault();
  document.getElementById('dropZone').classList.remove('dragover');
  const file = e.dataTransfer.files[0];
  if (!file) return;
  const allowed = ['image/jpeg','image/png','image/webp','application/pdf'];
  if (!allowed.includes(file.type)) { alert('Only JPG, PNG, WebP or PDF allowed.'); return; }
  const dt = new DataTransfer();
  dt.items.add(file);
  const input = document.getElementById('resumeInput');
  input.files = dt.files;
  previewFile(input);
}

// ── Form Validation ──────────────────────────────────────────
function validateForm() {
  const pw = document.getElementById('password').value;
  const cf = document.getElementById('confirm').value;
  if (pw !== cf) { alert('Passwords do not match.'); return false; }
  if (pw.length < 8) { alert('Password must be at least 8 characters.'); return false; }
  document.getElementById('submitBtn').disabled = true;
  document.getElementById('submitBtn').innerHTML = '<span class="ms">hourglass_top</span> Submitting...';
  return true;
}

// ── Username field with @slug suffix ────────────────────────
(function() {
  const slugSuffix = '@<?= addslashes($slug) ?>.com';
  const usernameInput = document.getElementById('username');
  if (!usernameInput) return;

  // On page load: if value already has the suffix (POST re-render), leave it.
  // If it has some other @suffix or is empty, normalise it.
  function normalise(val) {
    // Strip any existing @... suffix so we only keep the base part
    const base = val.replace(/@.*$/, '');
    return base + slugSuffix;
  }

  // Initialise on load
  if (usernameInput.value) {
    // Already has the correct suffix → leave alone; otherwise normalise
    if (!usernameInput.value.endsWith(slugSuffix)) {
      usernameInput.value = normalise(usernameInput.value);
    }
  }

  // Auto-suggest from Full Name (only when field is blank / just has the suffix)
  const fullnameInput = document.getElementById('fullname');
  if (fullnameInput) {
    fullnameInput.addEventListener('blur', function () {
      const current = usernameInput.value;
      if (!current || current === slugSuffix) {
        const base = this.value.toLowerCase().replace(/[^a-z0-9_]/g, '').slice(0, 20);
        if (base) usernameInput.value = base + slugSuffix;
      }
    });
  }

  // While typing: keep suffix locked at the end
  usernameInput.addEventListener('input', function () {
    const val = this.value;
    if (!val.endsWith(slugSuffix)) {
      // Get cursor position before we modify the string
      const selStart = this.selectionStart;
      const base = val.replace(/@.*$/, '').replace(/[^a-zA-Z0-9_]/g, '');
      this.value = base + slugSuffix;
      // Restore cursor to end of the base part
      const pos = Math.min(selStart, base.length);
      this.setSelectionRange(pos, pos);
    }
  });

  // Prevent deleting the suffix with Backspace / Delete
  usernameInput.addEventListener('keydown', function (e) {
    const protectedStart = this.value.length - slugSuffix.length;
    if (protectedStart < 0) return;
    if (e.key === 'Backspace' && this.selectionStart <= protectedStart && this.selectionEnd <= protectedStart) return;
    if ((e.key === 'Backspace' || e.key === 'Delete') && this.selectionStart >= protectedStart) {
      e.preventDefault();
    }
  });

  // On focus: place cursor before the suffix
  usernameInput.addEventListener('focus', function () {
    if (!this.value || this.value === slugSuffix) {
      this.value = slugSuffix;
    }
    const pos = this.value.length - slugSuffix.length;
    setTimeout(() => this.setSelectionRange(pos, pos), 0);
  });
})();
</script>
</body>
</html>