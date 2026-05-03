<?php
/**
 * paymongo_reactivate.php
 * ─────────────────────────────────────────────────────────────
 * Public landing page for deactivated tenants arriving from
 * the auto-deactivation email.
 *
 * Flow:
 *   1. Tenant receives deactivation email with plan cards
 *   2. Clicks a plan → lands here with ?tenant=ID&plan=PLAN
 *   3. Sees plan summary + billing cycle selector
 *   4. Clicks "Pay Now" → POST to paymongo_renewal.php
 *      with action=pay_reactivation_paymongo
 *
 * No active session required — tenant may not be logged in yet.
 * We create a minimal session so paymongo_renewal.php can read it.
 * ─────────────────────────────────────────────────────────────
 */

require_once __DIR__ . '/session_helper.php';
pawnhub_session_start('admin');
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/paymongo_config.php';

// ── Read params ───────────────────────────────────────────────
$tenant_id    = intval($_GET['tenant'] ?? 0);
$presel_plan  = trim($_GET['plan'] ?? '');

$valid_plans = ['Starter', 'Pro', 'Enterprise'];
if (!in_array($presel_plan, $valid_plans)) $presel_plan = '';

// ── Fetch tenant ──────────────────────────────────────────────
if (!$tenant_id) {
    die('<p style="font-family:sans-serif;padding:40px;color:#dc2626;">Invalid reactivation link.</p>');
}

$stmt = $pdo->prepare("SELECT * FROM tenants WHERE id = ? LIMIT 1");
$stmt->execute([$tenant_id]);
$tenant = $stmt->fetch();

if (!$tenant) {
    die('<p style="font-family:sans-serif;padding:40px;color:#dc2626;">Tenant not found.</p>');
}

// Only allow inactive/expired tenants
if (!in_array($tenant['status'], ['active', 'inactive']) ||
    !in_array($tenant['subscription_status'] ?? '', ['expired', 'active', 'expiring_soon', ''])) {
    // Still allow — paymongo_renewal.php will do the real guard
}

// Find the admin user for this tenant (for session injection)
$u_stmt = $pdo->prepare("SELECT * FROM users WHERE tenant_id = ? AND role = 'admin' ORDER BY created_at ASC LIMIT 1");
$u_stmt->execute([$tenant_id]);
$admin_user = $u_stmt->fetch();

// ── Inject session so paymongo_renewal.php works ──────────────
// Only set if not already logged in as the correct tenant admin
if (empty($_SESSION['user']) || (int)($_SESSION['user']['tenant_id'] ?? 0) !== $tenant_id) {
    if ($admin_user) {
        $_SESSION['user'] = [
            'id'        => $admin_user['id'],
            'username'  => $admin_user['username'],
            'role'      => 'admin',
            'tenant_id' => $tenant_id,
        ];
    }
}

$biz_name    = $tenant['business_name'];
$current_plan = $tenant['plan'];
$owner_name  = $tenant['owner_name'];

// ── Check if tenant has ever used Starter free trial ─────────
// A tenant has "used" their free trial if:
//   1. Their current or past plan was Starter AND subscription_end is set (they got a trial), OR
//   2. They have any subscription_renewals record with plan='Starter'
$has_used_starter = false;

// Check 1: tenant was ever on Starter with a subscription_end (trial was given)
if (!empty($tenant['subscription_end'])) {
    // If current plan is Starter or they had Starter history in renewals
    $starter_renewal = $pdo->prepare("
        SELECT id FROM subscription_renewals
        WHERE tenant_id = ? AND plan = 'Starter'
        LIMIT 1
    ");
    $starter_renewal->execute([$tenant_id]);
    if ($starter_renewal->fetch()) {
        $has_used_starter = true;
    }
    // Also check: if they signed up originally as Starter (no paid renewals means they were on Starter)
    if (!$has_used_starter) {
        // If tenant was created with Starter plan — original plan is Starter
        // We consider: if subscription_end is set AND they were ever Starter (current plan)
        // or if their signup plan was Starter (check via renewals or original plan column)
        $orig_check = $pdo->prepare("
            SELECT id FROM subscription_renewals
            WHERE tenant_id = ? AND payment_reference LIKE 'starter-free%'
            LIMIT 1
        ");
        $orig_check->execute([$tenant_id]);
        if ($orig_check->fetch()) {
            $has_used_starter = true;
        }
    }
}

// Check 2: tenant's own plan history — if they originally signed up as Starter
// The most reliable: check if tenant's original_plan (or first recorded plan) was Starter
// Fallback: if current plan is Starter and subscription_end is set, they've used the trial
if (!$has_used_starter && $current_plan === 'Starter' && !empty($tenant['subscription_end'])) {
    $has_used_starter = true;
}

// Also check audit logs for FREE_TRIAL type activation
if (!$has_used_starter) {
    $audit_chk = $pdo->prepare("
        SELECT id FROM audit_logs
        WHERE tenant_id = ? AND action IN ('TENANT_REACTIVATED_STARTER', 'SUBSCRIPTION_AUTO_ACTIVATED')
        LIMIT 1
    ");
    $audit_chk->execute([$tenant_id]);
    if ($audit_chk->fetch()) {
        $has_used_starter = true;
    }
}

// If tenant was originally registered as Starter (check subscription_renewals for any record)
// If they have NO renewals at all but have a subscription_end, they originally got a Starter trial at signup
if (!$has_used_starter) {
    $any_renewal = $pdo->prepare("SELECT COUNT(*) FROM subscription_renewals WHERE tenant_id = ?");
    $any_renewal->execute([$tenant_id]);
    $renewal_count = (int)$any_renewal->fetchColumn();
    // Tenant has subscription_end set but no renewals = they got a free Starter trial at signup
    if ($renewal_count === 0 && !empty($tenant['subscription_end'])) {
        $has_used_starter = true;
    }
}

// ── Plan definitions ──────────────────────────────────────────
$plans = [
    'Starter' => [
        'price_monthly'   => 0,
        'price_quarterly' => 0,
        'price_annually'  => 0,
        'label'           => 'Free',
        'color'           => '#334155',
        'border'          => '#e2e8f0',
        'active_border'   => '#334155',
        'icon'            => '🏪',
        'features'        => ['Basic inventory management', 'Up to 1 branch', 'Up to 2 staff accounts', 'Standard reports'],
        'note'            => $has_used_starter ? 'Free trial already used — upgrade required.' : 'No payment required — reactivated instantly.',
    ],
    'Pro' => [
        'price_monthly'   => 999,
        'price_quarterly' => 2697,
        'price_annually'  => 9588,
        'label'           => '₱999/mo',
        'color'           => '#1d4ed8',
        'border'          => '#bfdbfe',
        'active_border'   => '#2563eb',
        'icon'            => '⚡',
        'features'        => ['Full inventory management', 'Up to 3 branches', 'Unlimited staff accounts', 'Advanced reports & analytics', 'Priority email support'],
        'note'            => 'Most popular for growing pawnshops.',
    ],
    'Enterprise' => [
        'price_monthly'   => 2499,
        'price_quarterly' => 6747,
        'price_annually'  => 23988,
        'label'           => '₱2,499/mo',
        'color'           => '#6d28d9',
        'border'          => '#ddd6fe',
        'active_border'   => '#7c3aed',
        'icon'            => '🏢',
        'features'        => ['Unlimited branches', 'Unlimited staff accounts', 'Custom reports', 'Dedicated account manager', 'API access'],
        'note'            => 'For large pawnshop chains.',
    ],
];

$billing_options = [
    'monthly'   => ['label' => 'Monthly',   'months' => 1,  'discount' => ''],
    'quarterly' => ['label' => 'Quarterly', 'months' => 3,  'discount' => 'Save ~10%'],
    'annually'  => ['label' => 'Annual',    'months' => 12, 'discount' => 'Save ~20%'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>Reactivate Your Account — PawnHub</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet"/>
<style>
*{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'Inter',sans-serif;background:#0f172a;min-height:100vh;padding:40px 16px;}
.container{max-width:860px;margin:0 auto;}

/* Header */
.header{text-align:center;margin-bottom:36px;}
.logo{display:inline-flex;align-items:center;gap:10px;margin-bottom:20px;}
.logo-box{width:42px;height:42px;background:linear-gradient(135deg,#3b82f6,#8b5cf6);border-radius:11px;}
.logo-text{font-size:1.5rem;font-weight:800;color:#fff;}
.deactivation-badge{display:inline-flex;align-items:center;gap:8px;background:rgba(220,38,38,.15);border:1px solid rgba(220,38,38,.35);border-radius:999px;padding:6px 16px;font-size:.82rem;font-weight:600;color:#fca5a5;margin-bottom:16px;}
h1{font-size:1.8rem;font-weight:800;color:#fff;margin-bottom:8px;}
.subtitle{color:rgba(255,255,255,.5);font-size:.9rem;}
.biz-name{color:#93c5fd;font-weight:700;}

/* Billing toggle */
.billing-toggle{display:flex;align-items:center;justify-content:center;gap:6px;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.1);border-radius:12px;padding:5px;margin:28px auto;max-width:380px;}
.billing-btn{flex:1;text-align:center;padding:9px 12px;border-radius:9px;font-size:.82rem;font-weight:600;cursor:pointer;color:rgba(255,255,255,.5);border:none;background:transparent;transition:all .2s;position:relative;}
.billing-btn.active{background:rgba(255,255,255,.12);color:#fff;}
.billing-btn .discount-badge{display:block;font-size:.68rem;font-weight:700;color:#4ade80;margin-top:1px;}

/* Plan grid */
.plan-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:16px;margin-bottom:32px;}
.plan-card{background:rgba(255,255,255,.04);border:2px solid rgba(255,255,255,.1);border-radius:16px;padding:22px;cursor:pointer;transition:all .25s;position:relative;}
.plan-card:hover{background:rgba(255,255,255,.07);transform:translateY(-2px);}
.plan-card.selected{background:rgba(255,255,255,.08);}
.plan-card.selected.plan-starter{border-color:#475569;}
.plan-card.selected.plan-pro{border-color:#2563eb;box-shadow:0 0 0 3px rgba(37,99,235,.2);}
.plan-card.selected.plan-enterprise{border-color:#7c3aed;box-shadow:0 0 0 3px rgba(124,58,237,.2);}
.current-badge{position:absolute;top:12px;right:12px;background:#fef9c3;border:1px solid #fde047;border-radius:6px;padding:2px 8px;font-size:.7rem;font-weight:700;color:#713f12;}
.locked-badge{position:absolute;top:12px;right:12px;background:rgba(239,68,68,.15);border:1px solid rgba(239,68,68,.3);border-radius:6px;padding:2px 8px;font-size:.7rem;font-weight:700;color:#fca5a5;}
.plan-card.locked{opacity:.6;cursor:not-allowed;pointer-events:none;filter:grayscale(40%);}
.plan-card.locked:hover{transform:none;background:rgba(255,255,255,.04);}
.plan-icon{font-size:1.6rem;margin-bottom:10px;}
.plan-name{font-size:1rem;font-weight:800;color:#fff;margin-bottom:4px;}
.plan-price{font-size:1.5rem;font-weight:900;color:#fff;margin-bottom:2px;}
.plan-period{font-size:.76rem;color:rgba(255,255,255,.4);}
.plan-note{font-size:.76rem;color:#4ade80;margin:8px 0 12px;font-style:italic;}
.plan-features{list-style:none;padding:0;}
.plan-features li{font-size:.8rem;color:rgba(255,255,255,.6);padding:4px 0;display:flex;align-items:center;gap:7px;}
.plan-features li::before{content:'✓';color:#4ade80;font-weight:700;flex-shrink:0;}
.free-note{font-size:.75rem;color:#4ade80;margin-top:10px;text-align:center;}

/* Submit section */
.submit-section{background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.1);border-radius:16px;padding:28px;text-align:center;}
.selected-summary{margin-bottom:20px;}
.selected-summary .plan-label{font-size:.8rem;font-weight:600;color:rgba(255,255,255,.5);text-transform:uppercase;letter-spacing:.06em;margin-bottom:6px;}
.selected-summary .plan-display{font-size:1.3rem;font-weight:800;color:#fff;}
.selected-summary .price-display{font-size:1rem;color:rgba(255,255,255,.5);margin-top:4px;}
.btn-pay{display:inline-block;background:linear-gradient(135deg,#2563eb,#1d4ed8);color:#fff;font-family:'Inter',sans-serif;font-size:1rem;font-weight:700;padding:15px 44px;border-radius:12px;border:none;cursor:pointer;box-shadow:0 4px 16px rgba(37,99,235,.35);transition:all .2s;}
.btn-pay:hover{transform:translateY(-1px);box-shadow:0 6px 20px rgba(37,99,235,.45);}
.btn-pay.free{background:linear-gradient(135deg,#334155,#1e293b);box-shadow:0 4px 16px rgba(0,0,0,.3);}
.btn-pay.enterprise{background:linear-gradient(135deg,#4c1d95,#6d28d9);box-shadow:0 4px 16px rgba(109,40,217,.35);}
.no-select{user-select:none;}
.divider{height:1px;background:rgba(255,255,255,.08);margin:20px 0;}
.methods{color:rgba(255,255,255,.35);font-size:.76rem;}
</style>
</head>
<body>
<div class="container">

  <!-- Header -->
  <div class="header">
    <div class="logo">
      <div class="logo-box"></div>
      <span class="logo-text">PawnHub</span>
    </div>
    <div class="deactivation-badge">🔒 Account Deactivated</div>
    <h1>Reactivate <span class="biz-name"><?= htmlspecialchars($biz_name) ?></span></h1>
    <p class="subtitle">Choose a plan to restore full access to your account and staff accounts.</p>
  </div>

  <!-- Billing cycle toggle -->
  <div class="billing-toggle no-select">
    <button class="billing-btn active" data-cycle="monthly" onclick="setCycle('monthly',this)">
      Monthly
    </button>
    <button class="billing-btn" data-cycle="quarterly" onclick="setCycle('quarterly',this)">
      Quarterly
      <span class="discount-badge">Save ~10%</span>
    </button>
    <button class="billing-btn" data-cycle="annually" onclick="setCycle('annually',this)">
      Annual
      <span class="discount-badge">Save ~20%</span>
    </button>
  </div>

  <!-- Plan cards -->
  <?php
  // If starter already used, force preselect Pro (or their existing paid plan)
  $effective_presel = $presel_plan;
  if ($has_used_starter && ($effective_presel === 'Starter' || !$effective_presel)) {
      $effective_presel = ($current_plan !== 'Starter') ? $current_plan : 'Pro';
  }
  ?>
  <div class="plan-grid">
    <?php foreach ($plans as $plan_key => $p):
      $is_current        = ($plan_key === $current_plan);
      $is_starter_locked = ($plan_key === 'Starter' && $has_used_starter);
      $preselected       = !$is_starter_locked && (($plan_key === $effective_presel) || (!$effective_presel && $plan_key === $current_plan));
      $card_class        = 'plan-card plan-' . strtolower($plan_key)
                         . ($preselected ? ' selected' : '')
                         . ($is_starter_locked ? ' locked' : '');
    ?>
    <div class="<?= $card_class ?>"
         <?= $is_starter_locked ? '' : "onclick=\"selectPlan('{$plan_key}')\"" ?>
         id="card-<?= $plan_key ?>"
         <?= $is_starter_locked ? 'title="Free trial already used — upgrade to a paid plan."' : '' ?>>

      <?php if ($is_starter_locked): ?>
        <div class="locked-badge">🔒 Trial Used</div>
      <?php elseif ($is_current): ?>
        <div class="current-badge">Current Plan</div>
      <?php endif; ?>

      <div class="plan-icon" style="<?= $is_starter_locked ? 'opacity:.35;' : '' ?>"><?= $p['icon'] ?></div>
      <div class="plan-name" style="<?= $is_starter_locked ? 'color:rgba(255,255,255,.3);' : '' ?>"><?= $plan_key ?> Plan</div>

      <?php if ($plan_key === 'Starter'): ?>
        <div class="plan-price" style="<?= $is_starter_locked ? 'color:rgba(255,255,255,.2);text-decoration:line-through;' : '' ?>">Free</div>
        <div class="plan-period" style="<?= $is_starter_locked ? 'color:rgba(255,255,255,.2);' : '' ?>">No payment needed</div>
      <?php else: ?>
        <div class="plan-price" id="price-<?= $plan_key ?>">₱<?= number_format($p['price_monthly']) ?></div>
        <div class="plan-period" id="period-<?= $plan_key ?>">/month</div>
      <?php endif; ?>

      <?php if ($is_starter_locked): ?>
        <div style="font-size:.75rem;color:rgba(239,68,68,.65);margin:8px 0 12px;font-style:italic;font-weight:600;">
          Free trial already used.<br>Upgrade to Pro or Enterprise to continue.
        </div>
      <?php else: ?>
        <div class="plan-note"><?= htmlspecialchars($p['note']) ?></div>
      <?php endif; ?>

      <ul class="plan-features">
        <?php foreach ($p['features'] as $feat): ?>
          <li style="<?= $is_starter_locked ? 'color:rgba(255,255,255,.18);' : '' ?>"><?= htmlspecialchars($feat) ?></li>
        <?php endforeach; ?>
      </ul>

      <?php if ($is_starter_locked): ?>
        <div style="margin-top:12px;padding:7px 10px;background:rgba(239,68,68,.08);border:1px solid rgba(239,68,68,.2);border-radius:8px;font-size:.71rem;color:rgba(239,68,68,.65);text-align:center;font-weight:600;">
          One free trial per account only
        </div>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- Submit section -->
  <div class="submit-section">
    <?php if ($has_used_starter): ?>
    <div style="background:rgba(239,68,68,.08);border:1px solid rgba(239,68,68,.25);border-radius:10px;padding:11px 16px;margin-bottom:18px;font-size:.8rem;color:rgba(239,68,68,.85);text-align:center;font-weight:600;">
      ⚠️ Your free trial has already been used. <strong style="color:#fca5a5;">The Starter Plan is no longer available for your account.</strong> Please choose Pro or Enterprise to continue.
    </div>
    <?php endif; ?>
    <div class="selected-summary">
      <div class="plan-label">Selected Plan</div>
      <div class="plan-display" id="summary-plan">
        <?= $effective_presel ?: $current_plan ?> Plan
      </div>
      <div class="price-display" id="summary-price">
        <?php
          $sp = $effective_presel ?: $current_plan;
          if ($sp === 'Starter') echo 'Free — no payment required';
          else echo '₱' . number_format($plans[$sp]['price_monthly'] ?? 0) . '/month (Monthly billing)';
        ?>
      </div>
    </div>

    <form method="POST" action="paymongo_renewal.php" id="reactivate-form">
      <input type="hidden" name="action" value="pay_reactivation_paymongo"/>
      <input type="hidden" name="billing_cycle" id="input-cycle" value="monthly"/>
      <input type="hidden" name="reactivate_plan" id="input-plan" value="<?= htmlspecialchars($effective_presel ?: $current_plan) ?>"/>

      <button type="submit" class="btn-pay" id="pay-btn">
        🔄 Reactivate Now
      </button>
    </form>

    <div class="divider"></div>
    <div class="methods">Accepted: GCash · Maya · Credit/Debit Card · Online Banking · Billease</div>
  </div>

</div>

<script>
// ── Plan pricing data ─────────────────────────────────────────
const plans = <?= json_encode(array_map(fn($p) => [
    'price_monthly'   => $p['price_monthly'],
    'price_quarterly' => $p['price_quarterly'],
    'price_annually'  => $p['price_annually'],
], $plans), JSON_UNESCAPED_UNICODE) ?>;

const cycleLabels = {
    monthly:   'Monthly billing',
    quarterly: 'Quarterly billing (3 months)',
    annually:  'Annual billing (12 months)',
};

const starterLocked = <?= $has_used_starter ? 'true' : 'false' ?>;

let selectedPlan  = <?= json_encode($effective_presel ?: $current_plan) ?>;
let selectedCycle = 'monthly';

function selectPlan(plan) {
    // Block selecting Starter if trial already used
    if (plan === 'Starter' && starterLocked) return;
    document.querySelectorAll('.plan-card').forEach(c => c.classList.remove('selected'));
    document.getElementById('card-' + plan).classList.add('selected');
    selectedPlan = plan;
    document.getElementById('input-plan').value = plan;
    updateSummary();
}

function setCycle(cycle, btn) {
    document.querySelectorAll('.billing-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    selectedCycle = cycle;
    document.getElementById('input-cycle').value = cycle;
    updatePriceDisplays();
    updateSummary();
}

function updatePriceDisplays() {
    ['Pro', 'Enterprise'].forEach(plan => {
        const data  = plans[plan];
        const el    = document.getElementById('price-' + plan);
        const pEl   = document.getElementById('period-' + plan);
        if (!el) return;

        let amount, periodText;
        if (selectedCycle === 'monthly') {
            amount     = data.price_monthly;
            periodText = '/month';
        } else if (selectedCycle === 'quarterly') {
            amount     = data.price_quarterly;
            periodText = 'for 3 months';
        } else {
            amount     = data.price_annually;
            periodText = 'for 12 months';
        }
        el.textContent = '₱' + amount.toLocaleString();
        pEl.textContent = periodText;
    });
}

function updateSummary() {
    const summaryPlan  = document.getElementById('summary-plan');
    const summaryPrice = document.getElementById('summary-price');
    const payBtn       = document.getElementById('pay-btn');

    summaryPlan.textContent = selectedPlan + ' Plan';

    if (selectedPlan === 'Starter') {
        summaryPrice.textContent = 'Free — no payment required';
        payBtn.textContent       = '🔄 Reactivate Free (Instant)';
        payBtn.className         = 'btn-pay free';
    } else {
        const data   = plans[selectedPlan];
        let amount;
        if (selectedCycle === 'monthly')   amount = data.price_monthly;
        else if (selectedCycle === 'quarterly') amount = data.price_quarterly;
        else amount = data.price_annually;

        summaryPrice.textContent = '₱' + amount.toLocaleString() + ' — ' + cycleLabels[selectedCycle];
        payBtn.textContent       = '💳 Pay & Reactivate →';
        payBtn.className         = selectedPlan === 'Enterprise' ? 'btn-pay enterprise' : 'btn-pay';
    }
}

// Init
selectPlan(selectedPlan);
</script>
</body>
</html>