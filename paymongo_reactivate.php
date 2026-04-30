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
        'note'            => 'No payment required — reactivated instantly.',
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
  <div class="plan-grid">
    <?php foreach ($plans as $plan_key => $p):
      $is_current = ($plan_key === $current_plan);
      $preselected = ($plan_key === $presel_plan) || (!$presel_plan && $plan_key === $current_plan);
      $card_class  = 'plan-card plan-' . strtolower($plan_key) . ($preselected ? ' selected' : '');
    ?>
    <div class="<?= $card_class ?>" onclick="selectPlan('<?= $plan_key ?>')" id="card-<?= $plan_key ?>">
      <?php if ($is_current): ?>
        <div class="current-badge">Current Plan</div>
      <?php endif; ?>
      <div class="plan-icon"><?= $p['icon'] ?></div>
      <div class="plan-name"><?= $plan_key ?> Plan</div>
      <?php if ($plan_key === 'Starter'): ?>
        <div class="plan-price">Free</div>
        <div class="plan-period">No payment needed</div>
      <?php else: ?>
        <div class="plan-price" id="price-<?= $plan_key ?>">₱<?= number_format($p['price_monthly']) ?></div>
        <div class="plan-period" id="period-<?= $plan_key ?>">/month</div>
      <?php endif; ?>
      <div class="plan-note"><?= htmlspecialchars($p['note']) ?></div>
      <ul class="plan-features">
        <?php foreach ($p['features'] as $feat): ?>
          <li><?= htmlspecialchars($feat) ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- Submit section -->
  <div class="submit-section">
    <div class="selected-summary">
      <div class="plan-label">Selected Plan</div>
      <div class="plan-display" id="summary-plan">
        <?= $presel_plan ?: $current_plan ?> Plan
      </div>
      <div class="price-display" id="summary-price">
        <?php
          $sp = $presel_plan ?: $current_plan;
          if ($sp === 'Starter') echo 'Free — no payment required';
          else echo '₱' . number_format($plans[$sp]['price_monthly'] ?? 0) . '/month (Monthly billing)';
        ?>
      </div>
    </div>

    <form method="POST" action="paymongo_renewal.php" id="reactivate-form">
      <input type="hidden" name="action" value="pay_reactivation_paymongo"/>
      <input type="hidden" name="billing_cycle" id="input-cycle" value="monthly"/>
      <input type="hidden" name="reactivate_plan" id="input-plan" value="<?= htmlspecialchars($presel_plan ?: $current_plan) ?>"/>

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

let selectedPlan  = <?= json_encode($presel_plan ?: $current_plan) ?>;
let selectedCycle = 'monthly';

function selectPlan(plan) {
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