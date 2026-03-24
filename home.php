<?php
session_start();

// Kung naka-login na, i-redirect sa tamang dashboard
if (!empty($_SESSION['user'])) {
    $role = $_SESSION['user']['role'] ?? '';
    if ($role === 'super_admin') { header('Location: superadmin.php'); exit; }
    if ($role === 'admin')       { header('Location: tenant.php');     exit; }
    if ($role === 'staff')       { header('Location: staff.php');      exit; }
    if ($role === 'cashier')     { header('Location: cashier.php');    exit; }
}

// Live stats mula sa database
$total_tenants = $total_users = $total_tickets = 0;
try {
    require_once 'db.php';
    $total_tenants = (int)$pdo->query("SELECT COUNT(*) FROM tenants WHERE status='active'")->fetchColumn();
    $total_users   = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE status='approved'")->fetchColumn();
    $total_tickets = (int)$pdo->query("SELECT COUNT(*) FROM pawn_transactions")->fetchColumn();
} catch (Throwable $e) {}
?>
<!DOCTYPE html>
<html class="scroll-smooth" lang="en">
<head>
<meta charset="utf-8"/>
<meta content="width=device-width, initial-scale=1.0" name="viewport"/>
<title>PawnHub | The Digital Atelier for Modern Pawnshops</title>
<script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@100..900&display=swap" rel="stylesheet"/>
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet"/>
<script id="tailwind-config">
tailwind.config = {
  darkMode: "class",
  theme: {
    extend: {
      colors: {
        "primary": "#3b82f6",
        "primary-dark": "#1d4ed8",
        "primary-container": "#1e3a8a",
        "surface-variant": "rgba(255,255,255,0.1)",
        "surface-container": "rgba(255,255,255,0.12)",
        "on-surface": "#ffffff"
      },
      fontFamily: {
        "headline": ["Inter"],
        "body": ["Inter"],
        "label": ["Inter"]
      },
      borderRadius: {
        "DEFAULT": "0.25rem",
        "lg": "0.5rem",
        "xl": "0.75rem",
        "full": "9999px"
      }
    }
  }
}
</script>
<style>
.material-symbols-outlined {
    font-variation-settings: 'FILL' 0, 'wght' 400, 'GRAD' 0, 'opsz' 24;
}
.glass-effect {
    backdrop-filter: blur(12px);
    -webkit-backdrop-filter: blur(12px);
    background: rgba(255, 255, 255, 0.1);
    border: 1px solid rgba(255, 255, 255, 0.2);
}
.glass-dark {
    backdrop-filter: blur(16px);
    -webkit-backdrop-filter: blur(16px);
    background: rgba(0, 0, 0, 0.4);
    border: 1px solid rgba(255, 255, 255, 0.1);
}
.asymmetric-grid {
    grid-template-columns: 1.2fr 0.8fr;
}
@media (max-width: 768px) {
    .asymmetric-grid { grid-template-columns: 1fr; }
}
.bg-pawn-shop {
    background-image: linear-gradient(rgba(0,0,0,0.65), rgba(0,0,0,0.75)),
        url('https://lh3.googleusercontent.com/aida-public/AB6AXuDVdOMy67RcI3OmEXQ5Ob4N9qbUXkHC8UCa3Ni6E2dPvn8N_9Kg_FuGSOcP4mhYkmmhNphJ8vQukLbFjfnVrv-wy716m8LpTRmRrql1K07LpfXVuqMeCMwQRftqZXZWikKdGhSBaHJEhrAn431mN9EQqELqupcBMhVrkknDFPIyVKW_l8bfki8PfvWSkOTQ129Z5jOMGF5My-stQnfPndc_y1X0jUHBEmlH0AVE04q2vpa87PHKNSxAOHabM4n8c9W6UcgA91Cs-1c');
    background-attachment: fixed;
    background-size: cover;
    background-position: center;
}
section[id] { scroll-margin-top: 80px; }
#mobile-menu { display: none; }
#mobile-menu.open { display: flex; }
</style>
</head>
<body class="bg-pawn-shop text-white font-body">

<!-- NAV -->
<nav class="fixed top-0 w-full z-50 glass-effect border-none shadow-none">
  <div class="max-w-7xl mx-auto px-6 h-20 flex items-center justify-between">
    <a href="home.php" class="flex items-center gap-3">
      <div class="w-9 h-9 bg-gradient-to-br from-blue-500 to-purple-600 rounded-xl flex items-center justify-center">
        <svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2.5" class="w-5 h-5"><rect x="3" y="9" width="18" height="12"/><polyline points="3 9 12 3 21 9"/></svg>
      </div>
      <span class="text-2xl font-bold tracking-tighter text-white">PawnHub</span>
    </a>
    <div class="hidden md:flex items-center gap-10">
      <a href="#features" class="text-white/80 hover:text-white transition-all font-medium">Features</a>
      <a href="#roles"    class="text-white/80 hover:text-white transition-all font-medium">Roles</a>
      <a href="#pricing"  class="text-white/80 hover:text-white transition-all font-medium">Pricing</a>
      <a href="#stats"    class="text-white/80 hover:text-white transition-all font-medium">Stats</a>
    </div>
    <div class="flex items-center gap-3">
      <a href="login.php"  class="hidden sm:block text-white font-semibold hover:opacity-80 transition-all px-4 py-2 rounded-xl hover:bg-white/10">Sign In</a>
      <a href="signup.php" class="bg-blue-500 text-white px-5 py-2.5 rounded-xl font-semibold hover:bg-blue-600 transition-all">Get Started</a>
      <button onclick="document.getElementById('mobile-menu').classList.toggle('open')" class="md:hidden text-white p-2 rounded-lg hover:bg-white/10">
        <span class="material-symbols-outlined">menu</span>
      </button>
    </div>
  </div>
  <div id="mobile-menu" class="flex-col glass-dark border-t border-white/10 px-6 py-4 gap-3 md:hidden">
    <a href="#features" class="text-white/80 hover:text-white py-2 font-medium">Features</a>
    <a href="#roles"    class="text-white/80 hover:text-white py-2 font-medium">Roles</a>
    <a href="#pricing"  class="text-white/80 hover:text-white py-2 font-medium">Pricing</a>
    <div class="flex gap-3 pt-2 border-t border-white/10">
      <a href="login.php"  class="flex-1 text-center py-2.5 rounded-xl glass-effect font-semibold">Sign In</a>
      <a href="signup.php" class="flex-1 text-center py-2.5 rounded-xl bg-blue-500 font-semibold">Register</a>
    </div>
  </div>
</nav>

<main class="pt-20">

<!-- HERO -->
<section class="relative min-h-[90vh] flex items-center overflow-hidden px-6">
  <div class="max-w-7xl mx-auto w-full relative z-10 grid asymmetric-grid gap-12 items-center">
    <div>
      <span class="inline-block px-4 py-1.5 rounded-full bg-blue-500/20 text-blue-400 text-xs font-bold tracking-widest uppercase mb-8 border border-blue-500/30">Next-Gen Pawnshop Management</span>
      <h1 class="text-5xl md:text-7xl font-bold tracking-tighter text-white mb-8 leading-[1.1]">
        The Future of <br/><span class="text-blue-400">Pawn is Here.</span>
      </h1>
      <p class="text-xl text-white/80 max-w-xl mb-10 leading-relaxed">Empower your pawnshop business with enterprise-grade security, real-time analytics, and multi-branch management.</p>
      <div class="flex flex-wrap items-center gap-6">
        <a href="signup.php" class="px-8 py-4 bg-blue-500 text-white rounded-xl font-bold text-lg shadow-2xl hover:-translate-y-1 transition-all hover:bg-blue-600">Register Your Pawnshop</a>
        <a href="login.php"  class="flex items-center gap-2 text-white font-bold text-lg hover:opacity-70 transition-opacity glass-effect px-6 py-4 rounded-xl">
          <span class="material-symbols-outlined text-xl">login</span>Sign In
        </a>
      </div>
      <div class="flex flex-wrap gap-3 mt-8">
        <span class="text-xs text-white/50 glass-effect px-3 py-1.5 rounded-full">🔒 SSL Secured</span>
        <span class="text-xs text-white/50 glass-effect px-3 py-1.5 rounded-full">📋 BSP Compliant</span>
        <span class="text-xs text-white/50 glass-effect px-3 py-1.5 rounded-full">🛡️ Data Protected</span>
        <span class="text-xs text-white/50 glass-effect px-3 py-1.5 rounded-full">⚡ Real-Time Sync</span>
      </div>
    </div>
    <div class="hidden md:block relative">
      <div class="aspect-square glass-effect rounded-3xl overflow-hidden shadow-2xl p-4">
        <img alt="Professional pawnshop" class="w-full h-full object-cover rounded-2xl"
          src="https://lh3.googleusercontent.com/aida-public/AB6AXuDVdOMy67RcI3OmEXQ5Ob4N9qbUXkHC8UCa3Ni6E2dPvn8N_9Kg_FuGSOcP4mhYkmmhNphJ8vQukLbFjfnVrv-wy716m8LpTRmRrql1K07LpfXVuqMeCMwQRftqZXZWikKdGhSBaHJEhrAn431mN9EQqELqupcBMhVrkknDFPIyVKW_l8bfki8PfvWSkOTQ129Z5jOMGF5My-stQnfPndc_y1X0jUHBEmlH0AVE04q2vpa87PHKNSxAOHabM4n8c9W6UcgA91Cs-1c"/>
      </div>
      <div class="absolute -bottom-6 -left-6 glass-dark p-5 rounded-2xl shadow-xl">
        <div class="flex items-center gap-4 mb-1">
          <div class="w-10 h-10 rounded-full bg-blue-500/20 flex items-center justify-center">
            <span class="material-symbols-outlined text-blue-400">trending_up</span>
          </div>
          <div>
            <p class="text-xs text-white/60 font-medium">Monthly Volume</p>
            <p class="text-xl font-bold text-white">+124.5%</p>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- LIVE STATS -->
<section id="stats" class="py-16">
  <div class="max-w-5xl mx-auto px-6">
    <div class="glass-dark rounded-[2rem] p-10">
      <div class="text-center mb-8">
        <span class="text-xs font-bold text-blue-400 uppercase tracking-widest">Live System Stats</span>
        <h2 class="text-2xl font-bold text-white mt-2">Trusted by Pawnshops Across the Philippines</h2>
      </div>
      <div class="grid grid-cols-3 gap-8 text-center">
        <div>
          <div class="text-4xl md:text-5xl font-extrabold text-blue-400 mb-2"><?php echo number_format($total_tenants); ?></div>
          <div class="text-white/60 text-sm font-medium">Active Pawnshops</div>
          <div class="text-white/30 text-xs mt-1">Registered &amp; Verified</div>
        </div>
        <div class="border-x border-white/10">
          <div class="text-4xl md:text-5xl font-extrabold text-green-400 mb-2"><?php echo number_format($total_users); ?></div>
          <div class="text-white/60 text-sm font-medium">Active Users</div>
          <div class="text-white/30 text-xs mt-1">Admins, Staff &amp; Cashiers</div>
        </div>
        <div>
          <div class="text-4xl md:text-5xl font-extrabold text-purple-400 mb-2"><?php echo number_format($total_tickets); ?></div>
          <div class="text-white/60 text-sm font-medium">Pawn Tickets</div>
          <div class="text-white/30 text-xs mt-1">Processed in System</div>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- FEATURES -->
<section id="features" class="py-32">
  <div class="max-w-7xl mx-auto px-6">
    <div class="flex flex-col md:flex-row md:items-end justify-between gap-8 mb-20">
      <div class="max-w-2xl">
        <span class="text-xs font-bold text-blue-400 uppercase tracking-widest">Why PawnHub</span>
        <h2 class="text-4xl font-bold tracking-tight text-white mt-3 mb-4">Built for Modern Pawnshops</h2>
        <p class="text-lg text-white/70">Reimagined pawnshop management — focusing on the three pillars of modern digital commerce.</p>
      </div>
    </div>
    <div class="grid md:grid-cols-3 gap-8">
      <div class="glass-dark p-10 rounded-[2rem] hover:border-blue-500/50 border border-transparent transition-all group">
        <div class="w-14 h-14 bg-blue-500/20 rounded-2xl flex items-center justify-center mb-8 group-hover:bg-blue-500 transition-colors">
          <span class="material-symbols-outlined text-blue-400 group-hover:text-white text-3xl">hub</span>
        </div>
        <h3 class="text-2xl font-bold text-white mb-4">Multi-Tenant Architecture</h3>
        <p class="text-white/70 leading-relaxed">Each pawnshop gets their own branded dashboard — staff, cashier, and admin with isolated data.</p>
      </div>
      <div class="glass-dark p-10 rounded-[2rem] hover:border-blue-500/50 border border-transparent transition-all group">
        <div class="w-14 h-14 bg-blue-500/20 rounded-2xl flex items-center justify-center mb-8 group-hover:bg-blue-500 transition-colors">
          <span class="material-symbols-outlined text-blue-400 group-hover:text-white text-3xl">analytics</span>
        </div>
        <h3 class="text-2xl font-bold text-white mb-4">Real-Time Analytics</h3>
        <p class="text-white/70 leading-relaxed">Instant insights into inventory turnover, loan health, and cash flow with live dashboards.</p>
      </div>
      <div class="glass-dark p-10 rounded-[2rem] hover:border-blue-500/50 border border-transparent transition-all group">
        <div class="w-14 h-14 bg-blue-500/20 rounded-2xl flex items-center justify-center mb-8 group-hover:bg-blue-500 transition-colors">
          <span class="material-symbols-outlined text-blue-400 group-hover:text-white text-3xl">gpp_good</span>
        </div>
        <h3 class="text-2xl font-bold text-white mb-4">Enterprise Security</h3>
        <p class="text-white/70 leading-relaxed">SSL encryption, role-based access control, and full audit logs keep your data secure.</p>
      </div>
    </div>
    <div class="grid md:grid-cols-4 gap-6 mt-8">
      <div class="glass-dark p-6 rounded-2xl"><span class="material-symbols-outlined text-blue-400 text-2xl mb-3 block">receipt_long</span><h4 class="font-bold text-white text-sm mb-1">Pawn Ticket Management</h4><p class="text-white/50 text-xs leading-relaxed">Create, track, and manage pawn tickets.</p></div>
      <div class="glass-dark p-6 rounded-2xl"><span class="material-symbols-outlined text-blue-400 text-2xl mb-3 block">payments</span><h4 class="font-bold text-white text-sm mb-1">Payment Processing</h4><p class="text-white/50 text-xs leading-relaxed">Handle release, renewal, and void transactions.</p></div>
      <div class="glass-dark p-6 rounded-2xl"><span class="material-symbols-outlined text-blue-400 text-2xl mb-3 block">inventory_2</span><h4 class="font-bold text-white text-sm mb-1">Inventory Control</h4><p class="text-white/50 text-xs leading-relaxed">Track all pawned items with full audit trail.</p></div>
      <div class="glass-dark p-6 rounded-2xl"><span class="material-symbols-outlined text-blue-400 text-2xl mb-3 block">person_add</span><h4 class="font-bold text-white text-sm mb-1">Customer Registry</h4><p class="text-white/50 text-xs leading-relaxed">Maintain complete customer profiles and history.</p></div>
    </div>
  </div>
</section>

<!-- ROLES -->
<section id="roles" class="py-32">
  <div class="max-w-7xl mx-auto px-6 text-center mb-20">
    <span class="text-xs font-bold text-blue-400 uppercase tracking-widest">Role-Based Access</span>
    <h2 class="text-4xl font-bold tracking-tight text-white mt-3 mb-4">One Platform, Four Roles</h2>
    <p class="text-lg text-white/70 max-w-2xl mx-auto">Tailored experiences for every level of your organization.</p>
  </div>
  <div class="max-w-7xl mx-auto px-6 grid md:grid-cols-4 gap-6">
    <div class="relative group h-full">
      <div class="absolute inset-0 bg-blue-500/20 rounded-[2rem] rotate-2 group-hover:rotate-0 transition-transform"></div>
      <div class="relative glass-dark p-8 rounded-[2rem] h-full flex flex-col items-center text-center">
        <div class="w-16 h-16 rounded-full bg-blue-500/20 flex items-center justify-center mb-6 border border-blue-500/40"><span class="material-symbols-outlined text-blue-400 text-3xl">admin_panel_settings</span></div>
        <h4 class="text-xl font-bold text-white mb-4">Super Admin</h4>
        <ul class="space-y-2 text-left w-full">
          <li class="flex items-start gap-2 text-white/65 text-sm"><span class="w-1.5 h-1.5 rounded-full bg-blue-400 mt-1.5 flex-shrink-0"></span>Global Multi-Tenant Control</li>
          <li class="flex items-start gap-2 text-white/65 text-sm"><span class="w-1.5 h-1.5 rounded-full bg-blue-400 mt-1.5 flex-shrink-0"></span>Invite &amp; Manage Tenants</li>
          <li class="flex items-start gap-2 text-white/65 text-sm"><span class="w-1.5 h-1.5 rounded-full bg-blue-400 mt-1.5 flex-shrink-0"></span>System Audit Logs</li>
        </ul>
      </div>
    </div>
    <div class="relative group h-full">
      <div class="absolute inset-0 bg-purple-500/20 rounded-[2rem] -rotate-1 group-hover:rotate-0 transition-transform"></div>
      <div class="relative glass-dark p-8 rounded-[2rem] h-full flex flex-col items-center text-center">
        <div class="w-16 h-16 rounded-full bg-purple-500/20 flex items-center justify-center mb-6 border border-purple-500/40"><span class="material-symbols-outlined text-purple-400 text-3xl">store</span></div>
        <h4 class="text-xl font-bold text-white mb-4">Pawnshop Admin</h4>
        <ul class="space-y-2 text-left w-full">
          <li class="flex items-start gap-2 text-white/65 text-sm"><span class="w-1.5 h-1.5 rounded-full bg-purple-400 mt-1.5 flex-shrink-0"></span>Staff Management</li>
          <li class="flex items-start gap-2 text-white/65 text-sm"><span class="w-1.5 h-1.5 rounded-full bg-purple-400 mt-1.5 flex-shrink-0"></span>Void &amp; Renewal Approvals</li>
          <li class="flex items-start gap-2 text-white/65 text-sm"><span class="w-1.5 h-1.5 rounded-full bg-purple-400 mt-1.5 flex-shrink-0"></span>Custom Theme &amp; Branding</li>
        </ul>
      </div>
    </div>
    <div class="relative group h-full">
      <div class="absolute inset-0 bg-green-500/20 rounded-[2rem] rotate-3 group-hover:rotate-0 transition-transform"></div>
      <div class="relative glass-dark p-8 rounded-[2rem] h-full flex flex-col items-center text-center">
        <div class="w-16 h-16 rounded-full bg-green-500/20 flex items-center justify-center mb-6 border border-green-500/40"><span class="material-symbols-outlined text-green-400 text-3xl">badge</span></div>
        <h4 class="text-xl font-bold text-white mb-4">Staff</h4>
        <ul class="space-y-2 text-left w-full">
          <li class="flex items-start gap-2 text-white/65 text-sm"><span class="w-1.5 h-1.5 rounded-full bg-green-400 mt-1.5 flex-shrink-0"></span>Create Pawn Tickets</li>
          <li class="flex items-start gap-2 text-white/65 text-sm"><span class="w-1.5 h-1.5 rounded-full bg-green-400 mt-1.5 flex-shrink-0"></span>Register Customers</li>
          <li class="flex items-start gap-2 text-white/65 text-sm"><span class="w-1.5 h-1.5 rounded-full bg-green-400 mt-1.5 flex-shrink-0"></span>Request Void / Renewals</li>
        </ul>
      </div>
    </div>
    <div class="relative group h-full">
      <div class="absolute inset-0 bg-yellow-500/20 rounded-[2rem] -rotate-2 group-hover:rotate-0 transition-transform"></div>
      <div class="relative glass-dark p-8 rounded-[2rem] h-full flex flex-col items-center text-center">
        <div class="w-16 h-16 rounded-full bg-yellow-500/20 flex items-center justify-center mb-6 border border-yellow-500/40"><span class="material-symbols-outlined text-yellow-400 text-3xl">point_of_sale</span></div>
        <h4 class="text-xl font-bold text-white mb-4">Cashier</h4>
        <ul class="space-y-2 text-left w-full">
          <li class="flex items-start gap-2 text-white/65 text-sm"><span class="w-1.5 h-1.5 rounded-full bg-yellow-400 mt-1.5 flex-shrink-0"></span>Process Payments</li>
          <li class="flex items-start gap-2 text-white/65 text-sm"><span class="w-1.5 h-1.5 rounded-full bg-yellow-400 mt-1.5 flex-shrink-0"></span>Release &amp; Renew Tickets</li>
          <li class="flex items-start gap-2 text-white/65 text-sm"><span class="w-1.5 h-1.5 rounded-full bg-yellow-400 mt-1.5 flex-shrink-0"></span>Daily Payment Summary</li>
        </ul>
      </div>
    </div>
  </div>
</section>

<!-- PRICING -->
<section id="pricing" class="py-32">
  <div class="max-w-7xl mx-auto px-6 text-center mb-20">
    <span class="text-xs font-bold text-blue-400 uppercase tracking-widest">Pricing Plans</span>
    <h2 class="text-4xl font-bold tracking-tight text-white mt-3 mb-4">Simple, Transparent Pricing</h2>
    <p class="text-lg text-white/70 max-w-2xl mx-auto">Choose the plan that fits your business. Upgrade anytime as you grow.</p>
  </div>
  <div class="max-w-5xl mx-auto px-6 grid md:grid-cols-3 gap-8">
    <div class="glass-dark p-8 rounded-[2rem] flex flex-col border border-white/10 hover:border-white/20 transition-all">
      <div class="mb-6"><div class="text-xs font-bold text-white/50 uppercase tracking-widest mb-3">Starter</div><div class="text-4xl font-extrabold text-white">Free</div><div class="text-white/40 text-sm mt-1">Perfect for new pawnshops</div></div>
      <ul class="space-y-3 text-white/65 text-sm mb-8 flex-1">
        <li class="flex items-center gap-2"><span class="material-symbols-outlined text-green-400 text-base">check_circle</span>1 Branch</li>
        <li class="flex items-center gap-2"><span class="material-symbols-outlined text-green-400 text-base">check_circle</span>Up to 3 Staff/Cashier</li>
        <li class="flex items-center gap-2"><span class="material-symbols-outlined text-green-400 text-base">check_circle</span>All Core Features</li>
        <li class="flex items-center gap-2"><span class="material-symbols-outlined text-green-400 text-base">check_circle</span>Email Support</li>
      </ul>
      <a href="signup.php?plan=Starter" class="w-full py-3 rounded-xl font-bold text-center glass-effect text-white hover:bg-white/20 transition-all border border-white/20 block">Get Started Free</a>
    </div>
    <div class="relative glass-dark p-8 rounded-[2rem] flex flex-col border-2 border-blue-500 shadow-2xl shadow-blue-500/20">
      <div class="absolute -top-4 left-1/2 -translate-x-1/2 bg-blue-500 text-white text-xs font-bold px-5 py-1.5 rounded-full">⭐ Most Popular</div>
      <div class="mb-6"><div class="text-xs font-bold text-blue-400 uppercase tracking-widest mb-3">Pro</div><div class="text-4xl font-extrabold text-white">₱999<span class="text-lg font-normal text-white/40">/mo</span></div><div class="text-white/40 text-sm mt-1">For growing businesses</div></div>
      <ul class="space-y-3 text-white/65 text-sm mb-8 flex-1">
        <li class="flex items-center gap-2"><span class="material-symbols-outlined text-green-400 text-base">check_circle</span>Up to 3 Branches</li>
        <li class="flex items-center gap-2"><span class="material-symbols-outlined text-green-400 text-base">check_circle</span>Unlimited Staff</li>
        <li class="flex items-center gap-2"><span class="material-symbols-outlined text-green-400 text-base">check_circle</span>Advanced Reports</li>
        <li class="flex items-center gap-2"><span class="material-symbols-outlined text-green-400 text-base">check_circle</span>Priority Support</li>
        <li class="flex items-center gap-2"><span class="material-symbols-outlined text-green-400 text-base">check_circle</span>Custom Branding</li>
      </ul>
      <a href="signup.php?plan=Pro" class="w-full py-3 rounded-xl font-bold text-center bg-blue-500 text-white hover:bg-blue-600 transition-all block">Get Pro</a>
    </div>
    <div class="glass-dark p-8 rounded-[2rem] flex flex-col border border-purple-500/30 hover:border-purple-500/50 transition-all">
      <div class="mb-6"><div class="text-xs font-bold text-purple-400 uppercase tracking-widest mb-3">Enterprise</div><div class="text-4xl font-extrabold text-white">₱2,499<span class="text-lg font-normal text-white/40">/mo</span></div><div class="text-white/40 text-sm mt-1">For large operations</div></div>
      <ul class="space-y-3 text-white/65 text-sm mb-8 flex-1">
        <li class="flex items-center gap-2"><span class="material-symbols-outlined text-green-400 text-base">check_circle</span>Up to 10 Branches</li>
        <li class="flex items-center gap-2"><span class="material-symbols-outlined text-green-400 text-base">check_circle</span>Unlimited Everything</li>
        <li class="flex items-center gap-2"><span class="material-symbols-outlined text-green-400 text-base">check_circle</span>Dedicated Support</li>
        <li class="flex items-center gap-2"><span class="material-symbols-outlined text-green-400 text-base">check_circle</span>API Access</li>
        <li class="flex items-center gap-2"><span class="material-symbols-outlined text-green-400 text-base">check_circle</span>Custom Branding</li>
      </ul>
      <a href="signup.php?plan=Enterprise" class="w-full py-3 rounded-xl font-bold text-center text-white transition-all border border-purple-400/40 hover:bg-purple-500/20 block">Get Enterprise</a>
    </div>
  </div>
</section>

<!-- CTA -->
<section class="py-32 relative overflow-hidden">
  <div class="max-w-4xl mx-auto px-6 text-center glass-dark py-16 px-8 rounded-[3rem]">
    <div class="w-16 h-16 bg-blue-500/20 rounded-2xl flex items-center justify-center mx-auto mb-6">
      <span class="material-symbols-outlined text-blue-400 text-3xl">rocket_launch</span>
    </div>
    <h2 class="text-4xl md:text-5xl font-extrabold tracking-tighter text-white mb-6">Ready to Transform Your Pawnshop?</h2>
    <p class="text-lg text-white/70 mb-10 max-w-2xl mx-auto leading-relaxed">Join <?php echo number_format($total_tenants); ?> active pawnshops already using PawnHub. Start your free Starter plan today — no credit card required.</p>
    <div class="flex flex-col sm:flex-row items-center justify-center gap-4">
      <a href="signup.php" class="w-full sm:w-auto px-10 py-4 bg-blue-500 text-white rounded-2xl font-bold text-lg shadow-2xl hover:bg-blue-600 transition-all text-center">Get Started for Free</a>
      <a href="login.php"  class="w-full sm:w-auto px-10 py-4 glass-effect text-white rounded-2xl font-bold text-lg transition-all hover:bg-white/20 text-center border border-white/20">Sign In to Dashboard</a>
    </div>
    <p class="text-white/30 text-sm mt-6">✓ Free Starter Plan &nbsp; ✓ No Setup Fee &nbsp; ✓ BSP Compliant</p>
  </div>
</section>

</main>

<!-- FOOTER -->
<footer class="w-full glass-dark border-t border-white/10">
  <div class="max-w-7xl mx-auto py-14 px-6 grid grid-cols-2 md:grid-cols-4 gap-8">
    <div class="col-span-2">
      <div class="flex items-center gap-3 mb-4">
        <div class="w-8 h-8 bg-gradient-to-br from-blue-500 to-purple-600 rounded-lg flex items-center justify-center">
          <svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2.5" class="w-4 h-4"><rect x="3" y="9" width="18" height="12"/><polyline points="3 9 12 3 21 9"/></svg>
        </div>
        <span class="text-lg font-bold text-white">PawnHub</span>
      </div>
      <p class="text-sm text-white/50 max-w-xs leading-relaxed">The premier management ecosystem for modern pawnshops. Built for performance, security, and trust.</p>
    </div>
    <div class="flex flex-col gap-3">
      <p class="font-bold text-blue-400 mb-1 text-sm uppercase tracking-wider">Platform</p>
      <a href="#features" class="text-sm text-white/55 hover:text-white transition-colors">Features</a>
      <a href="#roles"    class="text-sm text-white/55 hover:text-white transition-colors">Roles</a>
      <a href="#pricing"  class="text-sm text-white/55 hover:text-white transition-colors">Pricing</a>
    </div>
    <div class="flex flex-col gap-3">
      <p class="font-bold text-blue-400 mb-1 text-sm uppercase tracking-wider">Account</p>
      <a href="login.php"               class="text-sm text-white/55 hover:text-white transition-colors">Sign In</a>
      <a href="signup.php"              class="text-sm text-white/55 hover:text-white transition-colors">Register</a>
      <a href="signup.php?plan=Starter"    class="text-sm text-white/55 hover:text-white transition-colors">Starter Plan</a>
      <a href="signup.php?plan=Pro"        class="text-sm text-white/55 hover:text-white transition-colors">Pro Plan</a>
      <a href="signup.php?plan=Enterprise" class="text-sm text-white/55 hover:text-white transition-colors">Enterprise Plan</a>
    </div>
  </div>
  <div class="border-t border-white/10">
    <div class="max-w-7xl mx-auto px-6 py-5 flex flex-col sm:flex-row items-center justify-between gap-3">
      <p class="text-sm text-white/30">© <?php echo date('Y'); ?> PawnHub. All rights reserved.</p>
      <div class="flex items-center gap-2 text-xs text-white/25">
        <span class="w-2 h-2 rounded-full bg-green-400 animate-pulse inline-block"></span>
        System Online · <?php echo number_format($total_tenants); ?> active branches
      </div>
    </div>
  </div>
</footer>

<script>
document.querySelectorAll('a[href^="#"]').forEach(a => {
  a.addEventListener('click', function(e) {
    e.preventDefault();
    const t = document.querySelector(this.getAttribute('href'));
    if (t) t.scrollIntoView({ behavior: 'smooth' });
  });
});
</script>
</body>
</html>