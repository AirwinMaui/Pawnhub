<?php
require_once __DIR__ . '/session_helper.php';
require 'db.php';
require 'theme_helper.php';

// Detect which session is active based on cookie, then start the right one
$_detected_session = '';
foreach (['cashier', 'staff'] as $_sname) {
    if (!empty($_COOKIE['pawnhub_' . $_sname])) {
        $_detected_session = $_sname;
        break;
    }
}
if ($_detected_session) {
    pawnhub_session_start($_detected_session);
} else {
    pawnhub_session_start('staff');
    if (empty($_SESSION['user'])) {
        session_write_close();
        pawnhub_session_start('cashier');
    }
}

// Must be logged in as staff or cashier
if (empty($_SESSION['user'])) {
    header('Location: /'); exit;
}
$u = $_SESSION['user'];
if (!in_array($u['role'], ['staff','cashier'])) {
    $slug = $u['tenant_slug'] ?? '';
    header('Location: ' . ($slug ? '/' . rawurlencode($slug) . '?login=1' : '/login.php')); exit;
}

$tid         = $u['tenant_id'];
$success_msg = '';
$error_msg   = '';

$theme      = getTenantTheme($pdo, $tid);
$sys_name   = $theme['system_name'] ?? 'PawnHub';
$logo_text  = $theme['logo_text'] ?: $sys_name;
$logo_url   = $theme['logo_url']  ?? '';

$tenant = null;
$stmt = $pdo->prepare("SELECT * FROM tenants WHERE id=? LIMIT 1");
$stmt->execute([$tid]);
$tenant = $stmt->fetch();
$business_name = $tenant['business_name'] ?? $sys_name;
$tenant_slug   = $tenant['slug'] ?? '';

// Back URL based on role
$back_url = $tenant_slug ? "/{$tenant_slug}?login=1" : '/login.php';
if ($u['role'] === 'staff')   $back_url = 'staff.php';
if ($u['role'] === 'cashier') $back_url = 'cashier.php';

// ── Handle POST ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'change_password') {
    $current  = $_POST['current_password']  ?? '';
    $new_pwd  = $_POST['new_password']       ?? '';
    $confirm  = $_POST['confirm_password']   ?? '';

    // Fetch current hash
    $row = $pdo->prepare("SELECT password FROM users WHERE id=? AND tenant_id=? LIMIT 1");
    $row->execute([$u['id'], $tid]);
    $row = $row->fetch();

    if (!$row) {
        $error_msg = 'User not found.';
    } elseif (!password_verify($current, $row['password'])) {
        $error_msg = 'Current password is incorrect.';
    } elseif (strlen($new_pwd) < 8) {
        $error_msg = 'New password must be at least 8 characters.';
    } elseif (!preg_match('/[A-Z]/', $new_pwd)) {
        $error_msg = 'New password must contain at least one uppercase letter.';
    } elseif (!preg_match('/[a-z]/', $new_pwd)) {
        $error_msg = 'New password must contain at least one lowercase letter.';
    } elseif (!preg_match('/[0-9]/', $new_pwd)) {
        $error_msg = 'New password must contain at least one number.';
    } elseif (!preg_match('/[\W_]/', $new_pwd)) {
        $error_msg = 'New password must contain at least one special character (@, #, !, $, etc.).';
    } elseif ($new_pwd !== $confirm) {
        $error_msg = 'New passwords do not match.';
    } elseif (password_verify($new_pwd, $row['password'])) {
        $error_msg = 'New password must be different from your current password.';
    } else {
        $hashed = password_hash($new_pwd, PASSWORD_BCRYPT);
        $pdo->prepare("UPDATE users SET password=? WHERE id=? AND tenant_id=?")->execute([$hashed, $u['id'], $tid]);
        // Audit log
        try {
            $pdo->prepare("INSERT INTO audit_logs (tenant_id,actor_user_id,actor_username,actor_role,action,entity_type,entity_id,message,ip_address,created_at) VALUES (?,?,?,?,'CHANGE_PASSWORD','user',?,?,?,NOW())")
                ->execute([$tid, $u['id'], $u['username'], $u['role'], (string)$u['id'], ucfirst($u['role']).' changed their password.', $_SERVER['REMOTE_ADDR'] ?? '::1']);
        } catch (Throwable $e) {}
        $success_msg = '✅ Password changed successfully!';
    }
}

// ── Theme colors ───────────────────────────────────────────────
$primary   = $theme['primary_color']   ?? '#2563eb';
$accent    = $theme['accent_color']    ?? '#10b981';
$sidebar_c = $theme['sidebar_color']   ?? '#0f172a';
$bg_url    = $tenant['bg_image_url']   ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Change Password — <?=htmlspecialchars($business_name)?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet">
  <style>
    *,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
    body{font-family:'Inter',sans-serif;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px;
      background:#0f172a;
      <?php if($bg_url): ?>background-image:url('<?=htmlspecialchars($bg_url)?>');background-size:cover;background-position:center;<?php endif;?>
    }
    .overlay{position:fixed;inset:0;background:rgba(0,0,0,.55);backdrop-filter:blur(4px);z-index:0;}
    .card{position:relative;z-index:1;background:#1a1d26;border:1px solid rgba(255,255,255,.08);border-radius:20px;width:100%;max-width:440px;box-shadow:0 24px 60px rgba(0,0,0,.5);overflow:hidden;}
    .card-header{padding:24px 28px 20px;background:linear-gradient(135deg,<?=htmlspecialchars($primary)?>,<?=htmlspecialchars($accent)?>);display:flex;align-items:center;gap:14px;}
    .card-header .icon{width:46px;height:46px;border-radius:12px;background:rgba(255,255,255,.15);display:flex;align-items:center;justify-content:center;}
    .card-header h1{font-size:1.15rem;font-weight:800;color:#fff;line-height:1.2;}
    .card-header p{font-size:.73rem;color:rgba(255,255,255,.65);margin-top:3px;}
    .card-body{padding:24px 28px 28px;}
    .flabel{display:block;font-size:.74rem;font-weight:600;color:rgba(255,255,255,.5);margin-bottom:6px;letter-spacing:.4px;text-transform:uppercase;}
    .finput{width:100%;background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.1);border-radius:10px;padding:10px 14px;font-size:.88rem;color:#f0f2f7;font-family:inherit;outline:none;transition:border-color .2s;}
    .finput:focus{border-color:<?=htmlspecialchars($primary)?>;background:rgba(255,255,255,.07);}
    .fgroup{margin-bottom:14px;}
    .pwd-wrap{position:relative;}
    .pwd-toggle{position:absolute;right:12px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:rgba(255,255,255,.35);display:flex;align-items:center;padding:2px;}
    .btn-primary{display:flex;align-items:center;justify-content:center;gap:7px;width:100%;padding:12px;background:<?=htmlspecialchars($primary)?>;color:#fff;font-weight:700;font-size:.9rem;border:none;border-radius:12px;cursor:pointer;font-family:inherit;transition:opacity .18s;}
    .btn-primary:hover{opacity:.9;}
    .btn-secondary{display:flex;align-items:center;justify-content:center;gap:7px;width:100%;padding:11px;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.1);color:rgba(240,242,247,.6);font-weight:600;font-size:.85rem;border-radius:12px;cursor:pointer;font-family:inherit;text-decoration:none;transition:background .18s;margin-top:10px;}
    .btn-secondary:hover{background:rgba(255,255,255,.1);}
    .alert{border-radius:10px;padding:11px 14px;font-size:.8rem;margin-bottom:16px;line-height:1.5;}
    .alert-error{background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.25);color:#fca5a5;}
    .alert-success{background:rgba(16,185,129,.1);border:1px solid rgba(16,185,129,.25);color:#6ee7b7;}
    .pwd-strength{margin-top:6px;display:none;}
    .str-bars{display:flex;gap:4px;margin-bottom:6px;}
    .str-bar{flex:1;height:4px;border-radius:2px;background:rgba(255,255,255,.1);transition:background .2s;}
    .req-list{font-size:.68rem;line-height:1.8;color:rgba(255,255,255,.35);}
    .role-badge{display:inline-flex;align-items:center;gap:5px;background:rgba(16,185,129,.1);border:1px solid rgba(16,185,129,.2);border-radius:8px;padding:4px 10px;font-size:.74rem;font-weight:700;color:#6ee7b7;margin-bottom:18px;}
  </style>
</head>
<body>
<div class="overlay"></div>
<div class="card">
  <div class="card-header">
    <div class="icon"><span class="material-symbols-outlined" style="color:#fff;font-size:22px;">lock_reset</span></div>
    <div>
      <h1>Change Password</h1>
      <p><?=htmlspecialchars($business_name)?> · <?=htmlspecialchars(ucfirst($u['role']))?></p>
    </div>
  </div>
  <div class="card-body">

    <div class="role-badge">
      <span class="material-symbols-outlined" style="font-size:14px;">badge</span>
      <?=htmlspecialchars($u['fullname'] ?? $u['username'])?> · <?=htmlspecialchars(ucfirst($u['role']))?>
    </div>

    <?php if($error_msg): ?>
      <div class="alert alert-error"><strong>Error:</strong> <?=htmlspecialchars($error_msg)?></div>
    <?php endif; ?>
    <?php if($success_msg): ?>
      <div class="alert alert-success"><?=htmlspecialchars($success_msg)?></div>
    <?php endif; ?>

    <?php if(!$success_msg): ?>
    <form method="POST">
      <input type="hidden" name="action" value="change_password">

      <div class="fgroup">
        <label class="flabel">Current Password</label>
        <div class="pwd-wrap">
          <input type="password" name="current_password" id="inp_current" class="finput" placeholder="Enter your current password" required style="padding-right:40px;">
          <button type="button" class="pwd-toggle" onclick="togglePwd('inp_current',this)">
            <span class="material-symbols-outlined" style="font-size:18px;">visibility</span>
          </button>
        </div>
      </div>

      <div class="fgroup">
        <label class="flabel">New Password <span style="font-size:.65rem;color:rgba(255,255,255,.3);font-weight:400;">min. 8 chars · upper, lower, number, special</span></label>
        <div class="pwd-wrap">
          <input type="password" name="new_password" id="inp_new" class="finput" placeholder="Set a strong new password" minlength="8" required style="padding-right:40px;" oninput="checkStr(this.value)">
          <button type="button" class="pwd-toggle" onclick="togglePwd('inp_new',this)">
            <span class="material-symbols-outlined" style="font-size:18px;">visibility</span>
          </button>
        </div>
        <div class="pwd-strength" id="str_wrap">
          <div class="str-bars">
            <div class="str-bar" id="sb1"></div>
            <div class="str-bar" id="sb2"></div>
            <div class="str-bar" id="sb3"></div>
            <div class="str-bar" id="sb4"></div>
          </div>
          <div class="req-list">
            <div id="r_len">✗ At least 8 characters</div>
            <div id="r_upper">✗ Uppercase letter (A–Z)</div>
            <div id="r_lower">✗ Lowercase letter (a–z)</div>
            <div id="r_num">✗ Number (0–9)</div>
            <div id="r_special">✗ Special character (@, #, !, $, etc.)</div>
          </div>
        </div>
      </div>

      <div class="fgroup">
        <label class="flabel">Confirm New Password</label>
        <div class="pwd-wrap">
          <input type="password" name="confirm_password" id="inp_confirm" class="finput" placeholder="Re-enter new password" required style="padding-right:40px;" oninput="checkMatch()">
          <button type="button" class="pwd-toggle" onclick="togglePwd('inp_confirm',this)">
            <span class="material-symbols-outlined" style="font-size:18px;">visibility</span>
          </button>
        </div>
        <div id="match_hint" style="font-size:.71rem;margin-top:5px;display:none;"></div>
      </div>

      <button type="submit" class="btn-primary">
        <span class="material-symbols-outlined" style="font-size:17px;">lock_reset</span>Change Password
      </button>
    </form>
    <?php else: ?>
      <div style="text-align:center;padding:8px 0 4px;">
        <span class="material-symbols-outlined" style="font-size:48px;color:#6ee7b7;">check_circle</span>
        <p style="font-size:.85rem;color:rgba(255,255,255,.55);margin-top:8px;">Your password has been updated. You can now use your new password to log in.</p>
      </div>
    <?php endif; ?>

    <a href="<?=htmlspecialchars($back_url)?>" class="btn-secondary">
      <span class="material-symbols-outlined" style="font-size:16px;">arrow_back</span>
      Back to Dashboard
    </a>
  </div>
</div>

<script>
function togglePwd(id, btn) {
  const el = document.getElementById(id);
  const icon = btn.querySelector('.material-symbols-outlined');
  if (el.type === 'password') {
    el.type = 'text';
    icon.textContent = 'visibility_off';
  } else {
    el.type = 'password';
    icon.textContent = 'visibility';
  }
}

function checkStr(val) {
  const wrap = document.getElementById('str_wrap');
  wrap.style.display = val.length > 0 ? 'block' : 'none';
  const checks = {
    r_len:     val.length >= 8,
    r_upper:   /[A-Z]/.test(val),
    r_lower:   /[a-z]/.test(val),
    r_num:     /[0-9]/.test(val),
    r_special: /[\W_]/.test(val),
  };
  for (const [id, pass] of Object.entries(checks)) {
    const el = document.getElementById(id);
    el.textContent = (pass ? '✓ ' : '✗ ') + el.textContent.replace(/^[✓✗] /, '');
    el.style.color = pass ? '#6ee7b7' : 'rgba(255,255,255,.35)';
  }
  const passed = Object.values(checks).filter(Boolean).length;
  const colors = ['#ef4444','#f59e0b','#eab308','#22c55e'];
  ['sb1','sb2','sb3','sb4'].forEach((id,i) => {
    document.getElementById(id).style.background = i < passed ? colors[Math.min(passed-1,3)] : 'rgba(255,255,255,.1)';
  });
  checkMatch();
}

function checkMatch() {
  const nv = document.getElementById('inp_new').value;
  const cv = document.getElementById('inp_confirm').value;
  const hint = document.getElementById('match_hint');
  if (!cv) { hint.style.display = 'none'; return; }
  hint.style.display = 'block';
  if (nv === cv) {
    hint.textContent = '✓ Passwords match';
    hint.style.color = '#6ee7b7';
  } else {
    hint.textContent = '✗ Passwords do not match';
    hint.style.color = '#fca5a5';
  }
}
</script>
</body>
</html>