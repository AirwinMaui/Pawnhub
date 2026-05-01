<?php
/**
 * permit_verify_starter.php
 * ─────────────────────────────────────────────────────────────
 * Called after Starter plan signup (free plan).
 *
 * RESULTS:
 *   ai_approved   → auto-activate tenant ✅
 *   ai_rejected   → block activation, show error ❌
 *   manual_review → block activation, SA will review 🔍
 * ─────────────────────────────────────────────────────────────
 */

session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/paymongo_config.php';
require_once __DIR__ . '/permit_verify.php';

$tenant_id = intval($_GET['tenant'] ?? 0);
$user_id   = intval($_GET['user']   ?? 0);

if (!$tenant_id || !$user_id) {
    header('Location: signup.php');
    exit;
}

// Fetch tenant
$stmt = $pdo->prepare("SELECT * FROM tenants WHERE id = ? LIMIT 1");
$stmt->execute([$tenant_id]);
$tenant = $stmt->fetch();

if (!$tenant) {
    header('Location: signup.php');
    exit;
}

$permit_result    = null;
$permit_ai_status = 'pending';
$is_active        = false;

// Only run AI if not yet processed
if ($tenant['status'] !== 'active') {

    // ── Run Gemini AI Verification ────────────────────────────
    try {
        $permit_result    = verifyBusinessPermit($tenant_id, $pdo);
        $permit_ai_status = $permit_result['status'];
        saveVerificationResult($tenant_id, $permit_result, $pdo);
    } catch (Throwable $e) {
        error_log("[StarterVerify] AI error: " . $e->getMessage());
        $permit_ai_status = 'manual_review';
        $permit_result    = [
            'status' => 'manual_review',
            'reason' => 'AI verification unavailable. Flagged for manual review.',
            'data'   => []
        ];
        saveVerificationResult($tenant_id, $permit_result, $pdo);
    }

    // ── ONLY activate if AI APPROVED ─────────────────────────
    // ai_rejected = blocked ❌
    // manual_review = blocked, pending SA review 🔍
    if ($permit_ai_status === 'ai_approved') {

        // Generate slug
        $base_slug = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $tenant['business_name']));
        $slug      = $base_slug;
        $ctr       = 1;
        while (true) {
            $chk = $pdo->prepare("SELECT id FROM tenants WHERE slug = ? AND id != ?");
            $chk->execute([$slug, $tenant_id]);
            if (!$chk->fetch()) break;
            $slug = $base_slug . $ctr++;
        }

        // Activate tenant
        $pdo->prepare("
            UPDATE tenants SET
                status              = 'active',
                slug                = ?,
                payment_status      = 'free',
                subscription_start  = CURDATE(),
                subscription_end    = DATE_ADD(CURDATE(), INTERVAL 1 MONTH),
                subscription_status = 'active'
            WHERE id = ?
        ")->execute([$slug, $tenant_id]);

        // Activate user
        $pdo->prepare("
            UPDATE users SET status = 'approved', approved_at = NOW()
            WHERE id = ?
        ")->execute([$user_id]);

        // Send welcome email
        try {
            require_once __DIR__ . '/mailer.php';
            sendTenantApproved(
                $tenant['email'],
                $tenant['owner_name'],
                $tenant['business_name'],
                $slug
            );
        } catch (Throwable $e) {
            error_log("[StarterVerify] Email error: " . $e->getMessage());
        }

        $is_active = true;

    } else {
        // ── ai_rejected OR manual_review → DO NOT ACTIVATE ───
        // Tenant stays as 'pending'
        // User stays as 'pending'
        // SA will see it in dashboard with permit_status badge
        $is_active = false;
    }

} else {
    // Already active
    $permit_ai_status = 'ai_approved';
    $is_active        = true;
}

// Re-fetch for display
$stmt = $pdo->prepare("SELECT * FROM tenants WHERE id = ? LIMIT 1");
$stmt->execute([$tenant_id]);
$tenant = $stmt->fetch();

$biz_name_display = htmlspecialchars($tenant['business_name'] ?? 'Your Business');
$tenant_email     = htmlspecialchars($tenant['email'] ?? '');
$rejection_reason = htmlspecialchars(
    $permit_result['reason'] ?? $tenant['rejection_reason'] ?? ''
);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>Registration Status — PawnHub</title>
<script src="https://cdn.tailwindcss.com"></script>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet"/>
<style>body { font-family: 'Inter', sans-serif; }</style>
</head>
<body class="min-h-screen bg-gray-950 flex items-center justify-center text-white p-6">

<div class="bg-gray-900 border border-gray-800 rounded-3xl p-10 max-w-lg w-full text-center shadow-2xl">

  <!-- Icon -->
  <div class="w-20 h-20 rounded-full flex items-center justify-center mx-auto mb-6
    <?php if ($is_active): ?>bg-green-500/15
    <?php elseif ($permit_ai_status === 'ai_rejected'): ?>bg-red-500/15
    <?php else: ?>bg-yellow-500/15<?php endif; ?>">
    <?php if ($is_active): ?>
      <svg class="w-10 h-10 text-green-400" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
      </svg>
    <?php elseif ($permit_ai_status === 'ai_rejected'): ?>
      <svg class="w-10 h-10 text-red-400" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
      </svg>
    <?php else: ?>
      <svg class="w-10 h-10 text-yellow-400" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
      </svg>
    <?php endif; ?>
  </div>

  <!-- Title -->
  <h1 class="text-2xl font-extrabold mb-2">
    <?php if ($is_active): ?>Account Activated! 🎉
    <?php elseif ($permit_ai_status === 'ai_rejected'): ?>Permit Rejected ❌
    <?php else: ?>Application Under Review 🔍
    <?php endif; ?>
  </h1>

  <!-- Subtitle -->
  <p class="text-gray-400 text-sm leading-relaxed mb-6">
    <?php if ($is_active): ?>
      Welcome, <strong class="text-white"><?= $biz_name_display ?></strong>!
      Your <strong class="text-green-400">Starter Plan</strong> is now active.
      Check your email to log in!
    <?php elseif ($permit_ai_status === 'ai_rejected'): ?>
      Sorry, <strong class="text-white"><?= $biz_name_display ?></strong>.
      Your business permit was <strong class="text-red-400">not accepted</strong>.
      Your account has <strong class="text-red-400">not been activated</strong>.
    <?php else: ?>
      Thank you, <strong class="text-white"><?= $biz_name_display ?></strong>!
      Your application is pending manual review.
      Your account will be activated once our admin approves it.
    <?php endif; ?>
  </p>

  <!-- Steps -->
  <div class="bg-gray-800 rounded-2xl p-6 mb-6 text-left space-y-4">
    <p class="text-xs font-bold uppercase tracking-widest text-gray-500">Verification Status</p>

    <!-- Step 1: Registration -->
    <div class="flex items-start gap-3">
      <div class="w-7 h-7 rounded-full bg-green-500 flex items-center justify-center flex-shrink-0 mt-0.5">
        <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
        </svg>
      </div>
      <div>
        <p class="text-sm font-semibold text-white">Registration saved ✅</p>
        <p class="text-xs text-gray-400 mt-0.5">Your account details were saved successfully.</p>
      </div>
    </div>

    <!-- Step 2: AI Permit Check -->
    <div class="flex items-start gap-3">
      <?php if ($is_active): ?>
        <div class="w-7 h-7 rounded-full bg-green-500 flex items-center justify-center flex-shrink-0 mt-0.5">
          <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
          </svg>
        </div>
        <div>
          <p class="text-sm font-semibold text-white">Business permit verified ✅</p>
          <p class="text-xs text-gray-400 mt-0.5">AI confirmed your permit is valid and pawnshop-related.</p>
        </div>
      <?php elseif ($permit_ai_status === 'ai_rejected'): ?>
        <div class="w-7 h-7 rounded-full bg-red-500 flex items-center justify-center flex-shrink-0 mt-0.5">
          <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
          </svg>
        </div>
        <div>
          <p class="text-sm font-semibold text-red-300">Permit rejected by AI ❌</p>
          <p class="text-xs text-red-400 mt-0.5 font-medium"><?= $rejection_reason ?: 'Your permit did not pass verification.' ?></p>
        </div>
      <?php else: ?>
        <div class="w-7 h-7 rounded-full bg-yellow-500/20 border-2 border-yellow-500/50 flex items-center justify-center flex-shrink-0 mt-0.5">
          <div class="w-2 h-2 rounded-full bg-yellow-400 animate-pulse"></div>
        </div>
        <div>
          <p class="text-sm font-semibold text-yellow-300">Flagged for manual review 🔍</p>
          <p class="text-xs text-gray-400 mt-0.5"><?= $rejection_reason ?: 'Admin will manually verify your permit within 24 hours.' ?></p>
        </div>
      <?php endif; ?>
    </div>

    <!-- Step 3: Account Access -->
    <div class="flex items-start gap-3">
      <?php if ($is_active): ?>
        <div class="w-7 h-7 rounded-full bg-blue-500/20 border-2 border-blue-500/50 flex items-center justify-center flex-shrink-0 mt-0.5">
          <div class="w-2 h-2 rounded-full bg-blue-400 animate-pulse"></div>
        </div>
        <div>
          <p class="text-sm font-semibold text-blue-300">Check your email 📧</p>
          <p class="text-xs text-gray-400 mt-0.5">Login link sent to <strong class="text-white"><?= $tenant_email ?></strong></p>
        </div>
      <?php elseif ($permit_ai_status === 'ai_rejected'): ?>
        <div class="w-7 h-7 rounded-full bg-red-500/20 flex items-center justify-center flex-shrink-0 mt-0.5">
          <span class="text-red-400 text-xs font-bold">✕</span>
        </div>
        <div>
          <p class="text-sm font-semibold text-red-300">Account NOT activated</p>
          <p class="text-xs text-gray-500 mt-0.5">Please re-register with a valid, current business permit.</p>
        </div>
      <?php else: ?>
        <div class="w-7 h-7 rounded-full bg-gray-700 flex items-center justify-center flex-shrink-0 mt-0.5">
          <span class="text-gray-400 text-xs font-bold">3</span>
        </div>
        <div>
          <p class="text-sm font-semibold text-gray-400">Waiting for admin approval</p>
          <p class="text-xs text-gray-500 mt-0.5">Email will be sent to <strong class="text-gray-300"><?= $tenant_email ?></strong> once approved.</p>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Info / action box -->
  <?php if ($is_active): ?>
    <div class="bg-green-500/10 border border-green-500/20 rounded-xl p-4 text-sm text-green-300 mb-6 text-left">
      <div class="flex items-start gap-2">
        <span>📧</span>
        <span>Can't find the email? Check your <strong>spam or junk folder</strong>.</span>
      </div>
    </div>
    <a href="login.php" class="block bg-green-600 hover:bg-green-500 text-white font-semibold py-3 px-8 rounded-xl transition-colors text-sm text-center">
      Go to Login →
    </a>

  <?php elseif ($permit_ai_status === 'ai_rejected'): ?>
    <div class="bg-red-500/10 border border-red-500/20 rounded-xl p-4 text-sm text-red-300 mb-6 text-left">
      <div class="flex items-start gap-2">
        <span>⚠️</span>
        <div>
          <p class="font-semibold mb-1">Common reasons for rejection:</p>
          <ul class="text-xs text-gray-400 space-y-1 list-disc list-inside">
            <li>Permit is expired — must be valid for <?= date('Y') ?></li>
            <li>Not a Philippine Business / Mayor's Permit</li>
            <li>Nature of Business is not pawnshop-related</li>
            <li>Document is blurry or unreadable</li>
          </ul>
        </div>
      </div>
    </div>
    <a href="signup.php" class="block bg-blue-600 hover:bg-blue-500 text-white font-semibold py-3 px-8 rounded-xl transition-colors text-sm text-center">
      ← Re-register with Valid Permit
    </a>

  <?php else: ?>
    <div class="bg-yellow-500/10 border border-yellow-500/20 rounded-xl p-4 text-sm text-yellow-300 mb-6 text-left">
      <div class="flex items-start gap-2">
        <span>🔍</span>
        <span>Our admin will review your permit and activate your account within <strong>24 hours</strong>. Check your email at <strong><?= $tenant_email ?></strong>.</span>
      </div>
    </div>
    <a href="login.php" class="block bg-gray-700 hover:bg-gray-600 text-white font-semibold py-3 px-8 rounded-xl transition-colors text-sm text-center">
      ← Back to Login
    </a>
  <?php endif; ?>

</div>
</body>
</html>