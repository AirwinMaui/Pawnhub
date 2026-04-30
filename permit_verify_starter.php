<?php
/**
 * permit_verify_starter.php
 * ─────────────────────────────────────────────────────────────
 * Called automatically after Starter plan signup (free plan).
 * Since Starter is free — walang PayMongo, so i-verify natin
 * ang permit dito agad after form submit.
 *
 * Flow:
 *  1. Tenant submits signup form (Starter plan)
 *  2. DB insert happens in signup.php
 *  3. Redirect to this page with ?tenant=X&user=Y
 *  4. AI verifies permit via Gemini
 *  5. If approved → tenant status = 'active', user = 'approved'
 *  6. If rejected → tenant status = 'pending', flag for SA review
 *  7. Show result UI
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

// Only process if still pending
$already_processed = ($tenant['status'] === 'active');
$permit_ai_status  = $tenant['business_permit_status'] ?? 'pending';
$permit_result     = null;

if (!$already_processed) {
    // Run Gemini AI verification
    try {
        $permit_result    = verifyBusinessPermit($tenant_id, $pdo);
        $permit_ai_status = $permit_result['status'];
        saveVerificationResult($tenant_id, $permit_result, $pdo);
    } catch (Throwable $e) {
        error_log("[StarterVerify] AI error: " . $e->getMessage());
        $permit_ai_status = 'manual_review';
        $permit_result    = ['status' => 'manual_review', 'reason' => 'AI verification failed. Flagged for manual review.', 'data' => []];
        saveVerificationResult($tenant_id, $permit_result, $pdo);
    }

    if ($permit_ai_status === 'ai_approved') {
        // Generate slug
        $slug = $tenant['slug'] ?? '';
        if (empty($slug)) {
            $base_slug = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $tenant['business_name']));
            $slug      = $base_slug;
            $ctr       = 1;
            while (true) {
                $chk = $pdo->prepare("SELECT id FROM tenants WHERE slug = ? AND id != ?");
                $chk->execute([$slug, $tenant_id]);
                if (!$chk->fetch()) break;
                $slug = $base_slug . $ctr++;
            }
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

        // Send email
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

        $already_processed = true;

    } else {
        // Keep as pending — SA will review
        // No activation yet
        $already_processed = false;
    }

    // Re-fetch
    $stmt = $pdo->prepare("SELECT * FROM tenants WHERE id = ? LIMIT 1");
    $stmt->execute([$tenant_id]);
    $tenant = $stmt->fetch();
}

$biz_name_display = htmlspecialchars($tenant['business_name'] ?? 'Your Business');
$tenant_email     = htmlspecialchars($tenant['email'] ?? '');
$rejection_reason = htmlspecialchars($permit_result['reason'] ?? $tenant['rejection_reason'] ?? '');
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
  <div class="w-20 h-20 <?= $already_processed ? 'bg-green-500/15' : ($permit_ai_status === 'ai_rejected' ? 'bg-red-500/15' : 'bg-yellow-500/15') ?> rounded-full flex items-center justify-center mx-auto mb-6">
    <?php if ($already_processed): ?>
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

  <h1 class="text-2xl font-extrabold mb-2">
    <?php if ($already_processed): ?>Account Activated! 🎉
    <?php elseif ($permit_ai_status === 'ai_rejected'): ?>Permit Issue Detected ⚠️
    <?php else: ?>Application Submitted! 🔍
    <?php endif; ?>
  </h1>

  <p class="text-gray-400 text-sm leading-relaxed mb-6">
    <?php if ($already_processed): ?>
      Welcome, <strong class="text-white"><?= $biz_name_display ?></strong>!
      Your <strong class="text-green-400">Starter Plan</strong> account is now active.
      Check your email to log in!
    <?php elseif ($permit_ai_status === 'ai_rejected'): ?>
      Your application for <strong class="text-white"><?= $biz_name_display ?></strong>
      was received but there's an issue with your business permit.
    <?php else: ?>
      Your application for <strong class="text-white"><?= $biz_name_display ?></strong>
      has been submitted and is under review by our admin team.
    <?php endif; ?>
  </p>

  <!-- Status steps -->
  <div class="bg-gray-800 rounded-2xl p-6 mb-6 text-left">
    <p class="text-xs font-bold uppercase tracking-widest text-gray-500 mb-4">Verification Status</p>

    <!-- Step 1: Registration -->
    <div class="flex items-start gap-3 mb-4">
      <div class="w-7 h-7 rounded-full bg-green-500 flex items-center justify-center flex-shrink-0 mt-0.5">
        <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
        </svg>
      </div>
      <div>
        <p class="text-sm font-semibold text-white">Registration received ✅</p>
        <p class="text-xs text-gray-400 mt-0.5">Your account details have been saved successfully.</p>
      </div>
    </div>

    <!-- Step 2: Permit AI Check -->
    <div class="flex items-start gap-3 mb-4">
      <?php if ($already_processed): ?>
      <div class="w-7 h-7 rounded-full bg-green-500 flex items-center justify-center flex-shrink-0 mt-0.5">
        <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
        </svg>
      </div>
      <div>
        <p class="text-sm font-semibold text-white">Business permit verified ✅</p>
        <p class="text-xs text-gray-400 mt-0.5">AI automatically verified your business permit.</p>
      </div>
      <?php elseif ($permit_ai_status === 'ai_rejected'): ?>
      <div class="w-7 h-7 rounded-full bg-red-500 flex items-center justify-center flex-shrink-0 mt-0.5">
        <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
        </svg>
      </div>
      <div>
        <p class="text-sm font-semibold text-red-300">Permit issue found ❌</p>
        <p class="text-xs text-gray-400 mt-0.5"><?= $rejection_reason ?></p>
      </div>
      <?php else: ?>
      <div class="w-7 h-7 rounded-full bg-yellow-500/20 border-2 border-yellow-500/50 flex items-center justify-center flex-shrink-0 mt-0.5">
        <div class="w-2 h-2 rounded-full bg-yellow-400 animate-pulse"></div>
      </div>
      <div>
        <p class="text-sm font-semibold text-yellow-300">Permit flagged for manual review 🔍</p>
        <p class="text-xs text-gray-400 mt-0.5">Our admin will verify your permit — usually within 24 hours.</p>
      </div>
      <?php endif; ?>
    </div>

    <!-- Step 3: Account Access -->
    <div class="flex items-start gap-3">
      <?php if ($already_processed): ?>
      <div class="w-7 h-7 rounded-full bg-blue-500/20 border-2 border-blue-500/50 flex items-center justify-center flex-shrink-0 mt-0.5">
        <div class="w-2 h-2 rounded-full bg-blue-400 animate-pulse"></div>
      </div>
      <div>
        <p class="text-sm font-semibold text-blue-300">Check your email</p>
        <p class="text-xs text-gray-400 mt-0.5">Login link sent to <strong class="text-white"><?= $tenant_email ?></strong></p>
      </div>
      <?php else: ?>
      <div class="w-7 h-7 rounded-full bg-gray-700 flex items-center justify-center flex-shrink-0 mt-0.5">
        <span class="text-gray-400 text-xs font-bold">3</span>
      </div>
      <div>
        <p class="text-sm font-semibold text-gray-300">Account activation</p>
        <p class="text-xs text-gray-500 mt-0.5">
          <?= $permit_ai_status === 'ai_rejected'
            ? 'Please contact support to resolve the permit issue.'
            : 'You\'ll receive an email once your account is approved.' ?>
        </p>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Info box -->
  <?php if ($already_processed): ?>
  <div class="bg-green-500/10 border border-green-500/20 rounded-xl p-4 text-sm text-green-300 mb-6 text-left">
    <div class="flex items-start gap-2">
      <span>📧</span>
      <span>Can't find the email? Check your <strong>spam folder</strong>.</span>
    </div>
  </div>
  <?php elseif ($permit_ai_status === 'ai_rejected'): ?>
  <div class="bg-red-500/10 border border-red-500/20 rounded-xl p-4 text-sm text-red-300 mb-6 text-left">
    <div class="flex items-start gap-2">
      <span>⚠️</span>
      <span>Please <strong>re-register</strong> with a valid, current Business Permit issued for the current year with pawnshop as nature of business.</span>
    </div>
  </div>
  <?php else: ?>
  <div class="bg-yellow-500/10 border border-yellow-500/20 rounded-xl p-4 text-sm text-yellow-300 mb-6 text-left">
    <div class="flex items-start gap-2">
      <span>🔍</span>
      <span>Our admin is reviewing your permit. You'll receive an email at <strong><?= $tenant_email ?></strong> once approved — usually <strong>within 24 hours</strong>.</span>
    </div>
  </div>
  <?php endif; ?>

  <?php if ($permit_ai_status === 'ai_rejected'): ?>
  <a href="signup.php" class="inline-block bg-blue-600 hover:bg-blue-500 text-white font-semibold py-3 px-8 rounded-xl transition-colors text-sm">
    ← Re-register with valid permit
  </a>
  <?php else: ?>
  <a href="login.php" class="inline-block bg-gray-700 hover:bg-gray-600 text-white font-semibold py-3 px-8 rounded-xl transition-colors text-sm">
    ← Back to Login
  </a>
  <?php endif; ?>

</div>
</body>
</html>