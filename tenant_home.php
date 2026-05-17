<?php
/**
 * tenant_home.php — Public-facing home page for each tenant/pawnshop
 * Accessible via /{slug} (router.php routes here instead of tenant_login.php)
 * Shows shop items, business info, and a Sign In button
 */

require 'db.php';
require 'theme_helper.php';

// Get slug from router or GET param
$slug = trim($_GET['slug'] ?? '');
if (!$slug) { header('Location: /home.php'); exit; }

// Load tenant by slug
$stmt = $pdo->prepare("SELECT * FROM tenants WHERE slug=? AND status='active' LIMIT 1");
$stmt->execute([$slug]);
$tenant = $stmt->fetch();

if (!$tenant) {
    http_response_code(404);
    header('Location: /home.php'); exit;
}

$tid = $tenant['id'];
$theme = getTenantTheme($pdo, $tid);

// Shop items — visible only
$items_stmt = $pdo->prepare("
    SELECT i.*, c.name AS cat_name, c.icon AS cat_icon
    FROM item_inventory i
    LEFT JOIN shop_categories c ON c.id = i.category_id
    WHERE i.tenant_id = ? AND i.is_shop_visible = 1 AND i.stock_qty > 0
    ORDER BY i.is_featured DESC, i.sort_order ASC, i.updated_at DESC
    LIMIT 60
");
$items_stmt->execute([$tid]);
$items = $items_stmt->fetchAll();

// Categories with items
$cats_stmt = $pdo->prepare("
    SELECT c.*, COUNT(i.id) AS item_count
    FROM shop_categories c
    JOIN item_inventory i ON i.category_id = c.id AND i.tenant_id = c.tenant_id AND i.is_shop_visible=1 AND i.stock_qty > 0
    WHERE c.tenant_id = ?
    GROUP BY c.id
    ORDER BY c.sort_order ASC, c.name ASC
");
$cats_stmt->execute([$tid]);
$categories = $cats_stmt->fetchAll();

// Featured items
$featured = array_filter($items, fn($i) => (int)$i['is_featured'] === 1);

// Counts
$total_items = count($items);

$primary   = $theme['primary_color']   ?? $tenant['primary_color']   ?? '#2563eb';
$secondary = $theme['secondary_color'] ?? '#1e3a8a';
$accent    = $theme['accent_color']    ?? $tenant['accent_color']    ?? '#10b981';
$logo_url  = $theme['logo_url']        ?? $tenant['logo_url']        ?? '';
$sys_name  = $theme['system_name']     ?? $tenant['business_name'];
// Shop background + access_code from tenant_settings
try {
    $sbq = $pdo->prepare("SELECT shop_bg_url, access_code FROM tenant_settings WHERE tenant_id=? LIMIT 1");
    $sbq->execute([$tid]);
    $ts_row = $sbq->fetch() ?: [];
    $shop_bg_raw  = $ts_row['shop_bg_url']  ?? '';
    $access_code  = $ts_row['access_code']  ?? '';
} catch (Throwable $e) { $shop_bg_raw = ''; $access_code = ''; }
if (!$shop_bg_raw) $shop_bg_raw = $tenant['bg_image_url'] ?? '';
$bg_url = $shop_bg_raw;
// Normalize local upload paths (fix old records without leading slash)
if ($bg_url   && strpos($bg_url,  'http') !== 0 && $bg_url[0]   !== '/') $bg_url   = '/' . $bg_url;
if ($logo_url && strpos($logo_url,'http') !== 0 && $logo_url[0] !== '/') $logo_url = '/' . $logo_url;

$biz_name  = htmlspecialchars($tenant['business_name']);

// If no background image is set → default to light mode; user can still toggle
$has_bg = !empty($bg_url);

// Hero text — customizable by tenant in Theme & Branding settings
$hero_title    = $theme['hero_title']    ?? '';
$hero_subtitle = $theme['hero_subtitle'] ?? '';
// Defaults if not set
if (!$hero_title)    $hero_title    = 'Your Trusted';
if (!$hero_subtitle) $hero_subtitle = 'Pawnshop';
$biz_addr  = htmlspecialchars($tenant['address'] ?? '');
$biz_phone = htmlspecialchars($tenant['phone'] ?? '');
// Sign-in URL — /{slug}?login=1 routes to tenant_login.php via router.php
$login_url    = '/' . rawurlencode($slug) . '?login=1';
$register_url = '/' . rawurlencode($slug) . '?register=1';

// Live counts for hero stats
try {
    $cnt = $pdo->prepare("SELECT COUNT(*) FROM customers WHERE tenant_id=?");
    $cnt->execute([$tid]); $total_customers = (int)$cnt->fetchColumn();
    $cnt2 = $pdo->prepare("SELECT COUNT(*) FROM pawn_transactions WHERE tenant_id=? AND status='Stored'");
    $cnt2->execute([$tid]); $active_pawns = (int)$cnt2->fetchColumn();
} catch (Throwable $e) { $total_customers = 0; $active_pawns = 0; }

// Promos & Announcements (with linked item data)
$promos = [];
try {
    $promo_stmt = $pdo->prepare("
        SELECT p.*,
               i.item_name        AS linked_item_name,
               i.item_photo_path  AS linked_item_photo,
               i.display_price    AS linked_item_price,
               i.promo_original_price AS linked_item_orig_price
        FROM tenant_promos p
        LEFT JOIN item_inventory i ON i.id = p.linked_item_id AND i.tenant_id = p.tenant_id
        WHERE p.tenant_id = ?
          AND p.is_active = 1
          AND (p.start_date IS NULL OR p.start_date <= NOW())
          AND (p.end_date IS NULL OR p.end_date >= NOW())
        ORDER BY p.is_pinned DESC, p.created_at DESC
        LIMIT 12
    ");
    $promo_stmt->execute([$tid]);
    $promos = $promo_stmt->fetchAll();
} catch (Throwable $e) { $promos = []; }

// Build a map of item_id => promo for sale badge on item cards
$item_promo_map = [];
foreach ($promos as $p) {
    if (!empty($p['linked_item_id']) && !empty($p['discount_pct']) && (float)$p['discount_pct'] > 0) {
        $item_promo_map[(int)$p['linked_item_id']] = $p;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?= renderTenantFavicon($theme) ?>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover, maximum-scale=1.0"/>
<title><?= $biz_name ?> — Shop</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&family=DM+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet">
<style>
/* ── LIGHT MODE (default when no bg image) ── */
:root {
  --primary: <?= $primary ?>;
  --secondary: <?= $secondary ?>;
  --accent: <?= $accent ?>;
  --bg: #f0f2f7;
  /* Cards: solid white so text is always readable on light-gray page bg */
  --surface: #ffffff;
  --surface-2: rgba(0,0,0,0.05);
  --border: rgba(0,0,0,0.10);
  --text: #0f1117;
  --text-m: rgba(15,17,23,0.65);
  --text-dim: rgba(15,17,23,0.42);
  --radius: 16px;
  --nav-h: 68px;
  --nav-bg: rgba(240,242,247,0.90);
  --footer-bg: rgba(0,0,0,0.04);
  --modal-bg: #ffffff;
  --hero-title-color: #0f1117;
  --section-title-color: #0f1117;
  --card-bg: #ffffff;
  --placeholder-bg: linear-gradient(135deg,rgba(0,0,0,.03),rgba(0,0,0,.06));
  --item-name-color: #0f1117;
  --item-price-color: #0f1117;
  --featured-bg-grad: linear-gradient(to top,rgba(10,10,18,.92) 0%,rgba(10,10,18,.5) 55%,rgba(10,10,18,.15) 100%);
  --featured-name-color: #fff;
  --featured-price-color: #fff;
  --info-val-color: #0f1117;
  --empty-title-color: rgba(15,17,23,.3);
  --footer-name-color: rgba(15,17,23,.6);
  --item-cat-badge-bg: rgba(240,242,247,.95);
  color-scheme: light;
}

/* ── DARK MODE ── */
[data-theme="dark"] {
  --bg: #08090c;
  /* Cards: visible dark panel on near-black page */
  --surface: rgba(255,255,255,0.07);
  --surface-2: rgba(255,255,255,0.11);
  --border: rgba(255,255,255,0.10);
  --text: #f0f2f7;
  --text-m: rgba(240,242,247,0.70);
  --text-dim: rgba(240,242,247,0.38);
  --nav-bg: rgba(8,9,12,0.80);
  --footer-bg: rgba(255,255,255,0.02);
  --modal-bg: #0e1117;
  --hero-title-color: #fff;
  --section-title-color: #fff;
  --card-bg: rgba(255,255,255,0.07);
  --placeholder-bg: linear-gradient(135deg,rgba(255,255,255,.03),rgba(255,255,255,.06));
  --item-name-color: #fff;
  --item-price-color: #fff;
  --featured-bg-grad: linear-gradient(to top,rgba(8,9,12,.95) 0%,rgba(8,9,12,.3) 60%,transparent 100%);
  --featured-name-color: #fff;
  --featured-price-color: #fff;
  --info-val-color: #fff;
  --empty-title-color: rgba(255,255,255,.3);
  --footer-name-color: rgba(255,255,255,.6);
  --item-cat-badge-bg: rgba(8,9,12,.80);
  color-scheme: dark;
}

*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

html { scroll-behavior: smooth; }

body {
  font-family: 'DM Sans', sans-serif;
  background: var(--bg);
  color: var(--text);
  min-height: 100vh;
  overflow-x: hidden;
  transition: background .3s, color .3s;
  font-size: 18px; /* Larger base for readability — pawnshop users are often older */
  line-height: 1.75;
}

.material-symbols-outlined {
  font-variation-settings: 'FILL' 0, 'wght' 400, 'GRAD' 0, 'opsz' 24;
}

/* ── BACKGROUND ── */
.bg-scene {
  position: fixed; inset: 0; z-index: 0; pointer-events: none;
  background: #f0f2f7;
}
[data-theme="dark"] .bg-scene { background: #08090c; }
.bg-scene-img {
  width: 100%; height: 100%; object-fit: cover;
  transition: opacity .4s, filter .4s;
}
[data-theme="light"] .bg-scene-img {
  opacity: 1;
  filter: saturate(0.85) brightness(1.0);
  mix-blend-mode: multiply;
}
[data-theme="dark"] .bg-scene-img {
  opacity: 1;
  filter: saturate(1.05) brightness(1.05);
  mix-blend-mode: screen;
}
.bg-gradient { position: absolute; inset: 0; }

/* LIGHT MODE gradient — fades fast so section backgrounds are solid */
[data-theme="light"] .bg-gradient {
  background:
    radial-gradient(ellipse 80% 50% at 50% -5%, color-mix(in srgb, var(--primary) 8%, transparent), transparent 65%),
    linear-gradient(to bottom,
      rgba(240,242,247,0.0)  0%,
      rgba(240,242,247,0.6)  25%,
      rgba(240,242,247,0.96) 50%,
      #f0f2f7 65%);
}
/* DARK MODE gradient */
[data-theme="dark"] .bg-gradient {
  background:
    radial-gradient(ellipse 80% 60% at 50% -10%, color-mix(in srgb, var(--primary) 12%, transparent), transparent 70%),
    linear-gradient(to bottom,
      rgba(8,9,12,0.0)  0%,
      rgba(8,9,12,0.30) 30%,
      rgba(8,9,12,0.85) 58%,
      #08090c 75%);
}

/* ══════════════════════════════════════════════════════════════
   DEFINITIVE CARD & TEXT THEMING
   Strategy: light mode = everything white/dark-text
             dark mode  = dark panels/white-text
             has-bg-img = dark-glass panels/white-text
   Light mode rules use :not([data-theme="dark"]) so toggling
   back to light ALWAYS wins regardless of initial state.
   ══════════════════════════════════════════════════════════════ */

/* ── LIGHT MODE: solid white cards, dark readable text ── */
body:not([data-theme="dark"]) .item-card,
body:not([data-theme="dark"]) .section-card,
body:not([data-theme="dark"]) .info-card,
body:not([data-theme="dark"]) .promo-card {
  background: #ffffff !important;
  border-color: rgba(0,0,0,0.09) !important;
  box-shadow: 0 2px 16px rgba(0,0,0,0.08) !important;
  backdrop-filter: none !important;
  -webkit-backdrop-filter: none !important;
}
/* Section note (reminder box) in light mode */
body:not([data-theme="dark"]) .section-note {
  background: color-mix(in srgb, var(--primary) 7%, #eef0ff) !important;
  border-color: color-mix(in srgb, var(--primary) 30%, transparent) !important;
}
/* All inline surface-bg divs in content sections — light mode */
body:not([data-theme="dark"]) #why-us .section-card,
body:not([data-theme="dark"]) section#info .info-card {
  background: #ffffff !important;
  border-color: rgba(0,0,0,0.09) !important;
  box-shadow: 0 2px 16px rgba(0,0,0,0.08) !important;
  backdrop-filter: none !important;
  -webkit-backdrop-filter: none !important;
}

/* ── DARK MODE: visible dark panels, white text ── */
[data-theme="dark"] .item-card,
[data-theme="dark"] .section-card,
[data-theme="dark"] .info-card,
[data-theme="dark"] .promo-card {
  background: rgba(18,22,34,0.90) !important;
  border-color: rgba(255,255,255,0.10) !important;
  box-shadow: 0 2px 20px rgba(0,0,0,0.35) !important;
}
[data-theme="dark"] .section-note {
  background: rgba(18,22,34,0.90) !important;
  border-color: color-mix(in srgb, var(--primary) 40%, transparent) !important;
}
[data-theme="dark"] #why-us .section-card,
[data-theme="dark"] section#info .info-card {
  background: rgba(18,22,34,0.90) !important;
  border-color: rgba(255,255,255,0.10) !important;
  box-shadow: 0 2px 20px rgba(0,0,0,0.35) !important;
}

/* ── WITH BG IMAGE: glass-dark, always white text ── */
/* has-bg-img sets data-theme="dark" initially, but user can toggle to light.
   When has-bg-img + light, still use dark glass so text is readable over image */
.has-bg-img .item-card,
.has-bg-img .section-card,
.has-bg-img .info-card,
.has-bg-img .promo-card {
  background: rgba(10,13,24,0.87) !important;
  border-color: rgba(255,255,255,0.14) !important;
  box-shadow: 0 4px 24px rgba(0,0,0,0.45) !important;
  backdrop-filter: blur(10px) !important;
  -webkit-backdrop-filter: blur(10px) !important;
}
.has-bg-img .section-note {
  background: rgba(10,13,24,0.85) !important;
  border-color: color-mix(in srgb, var(--primary) 45%, transparent) !important;
}
.has-bg-img #why-us .section-card,
.has-bg-img section#info .info-card {
  background: rgba(10,13,24,0.87) !important;
  border-color: rgba(255,255,255,0.14) !important;
  box-shadow: 0 4px 24px rgba(0,0,0,0.45) !important;
  backdrop-filter: blur(10px) !important;
  -webkit-backdrop-filter: blur(10px) !important;
}

/* ── SERVICES section cards — fix gradient bg ── */
/* Light: override dark gradient → clean white card */
body:not([data-theme="dark"]) #services .section-card {
  background: #ffffff !important;
  border-top-width: 3px !important;
  border-color: rgba(0,0,0,0.09) !important;
  box-shadow: 0 2px 16px rgba(0,0,0,0.08) !important;
}
body:not([data-theme="dark"]) #services .section-card:nth-child(1) { border-top-color: var(--primary) !important; }
body:not([data-theme="dark"]) #services .section-card:nth-child(2) { border-top-color: #22c55e !important; }
body:not([data-theme="dark"]) #services .section-card:nth-child(3) { border-top-color: var(--accent) !important; }
/* Dark: dark panel with subtle gradient tint */
[data-theme="dark"] #services .section-card {
  background: rgba(18,22,34,0.90) !important;
  border-color: rgba(255,255,255,0.10) !important;
}
/* Has bg: glass dark */
.has-bg-img #services .section-card {
  background: rgba(10,13,24,0.87) !important;
  border-color: rgba(255,255,255,0.14) !important;
  backdrop-filter: blur(10px) !important;
  -webkit-backdrop-filter: blur(10px) !important;
}

/* ══ TEXT COLORS — light / dark / bg-img ══ */

/* INLINE style="color:var(--text/text-m/text-dim)" — resolved per theme */
body:not([data-theme="dark"]) [style*="color:var(--text)"]     { color: #0f1117 !important; }
body:not([data-theme="dark"]) [style*="color:var(--text-m)"]   { color: rgba(15,17,23,0.65) !important; }
body:not([data-theme="dark"]) [style*="color:var(--text-dim)"] { color: rgba(15,17,23,0.42) !important; }
[data-theme="dark"]  [style*="color:var(--text)"]              { color: #f0f2f7 !important; }
[data-theme="dark"]  [style*="color:var(--text-m)"]            { color: rgba(240,242,247,0.72) !important; }
[data-theme="dark"]  [style*="color:var(--text-dim)"]          { color: rgba(240,242,247,0.40) !important; }
/* has-bg-img forces white regardless of toggle */
.has-bg-img [style*="color:var(--text)"]                       { color: #ffffff !important; }
.has-bg-img [style*="color:var(--text-m)"]                     { color: rgba(240,242,247,0.80) !important; }
.has-bg-img [style*="color:var(--text-dim)"]                   { color: rgba(240,242,247,0.50) !important; }

/* NAMED classes */
/* Section titles */
body:not([data-theme="dark"]) .section-title { color: #0f1117 !important; }
[data-theme="dark"]  .section-title           { color: #ffffff !important; }
.has-bg-img          .section-title           { color: #ffffff !important; text-shadow: 0 1px 10px rgba(0,0,0,.6); }

/* Section labels */
body:not([data-theme="dark"]) .section-label  { color: color-mix(in srgb, var(--primary) 85%, #000) !important; }
[data-theme="dark"]  .section-label, .has-bg-img .section-label { color: color-mix(in srgb, var(--primary) 90%, #fff) !important; }

/* Section paragraph text */
body:not([data-theme="dark"]) section > p     { color: rgba(15,17,23,0.65) !important; }
[data-theme="dark"]  section > p              { color: rgba(240,242,247,0.78) !important; }
.has-bg-img section > p                       { color: rgba(240,242,247,0.85) !important; }

/* Hero */
body:not([data-theme="dark"]) .hero-title     { color: #0f1117 !important; }
[data-theme="dark"]  .hero-title              { color: #ffffff !important; text-shadow: 0 2px 20px rgba(0,0,0,.7); }
.has-bg-img          .hero-title              { color: #ffffff !important; text-shadow: 0 2px 24px rgba(0,0,0,.8); }
/* Hero subtitle — always readable over any bg */
body:not([data-theme="dark"]) .hero-sub       { color: rgba(15,17,23,0.72) !important; text-shadow: 0 1px 8px rgba(255,255,255,.8); }
[data-theme="dark"]  .hero-sub                { color: rgba(240,242,247,0.85) !important; text-shadow: 0 1px 8px rgba(0,0,0,.6); }
.has-bg-img          .hero-sub                { color: rgba(240,242,247,0.90) !important; text-shadow: 0 1px 12px rgba(0,0,0,.75); }
/* Hero stats */
body:not([data-theme="dark"]) .hero-stat-val  { color: #0f1117 !important; }
[data-theme="dark"]  .hero-stat-val, .has-bg-img .hero-stat-val { color: #ffffff !important; }
body:not([data-theme="dark"]) .hero-stat-label { color: rgba(15,17,23,0.45) !important; }
[data-theme="dark"]  .hero-stat-label, .has-bg-img .hero-stat-label { color: rgba(240,242,247,0.50) !important; }

/* Item cards */
body:not([data-theme="dark"]) .item-name     { color: #0f1117 !important; }
body:not([data-theme="dark"]) .item-price    { color: #0f1117 !important; }
body:not([data-theme="dark"]) .item-cond     { color: rgba(15,17,23,0.55) !important; }
body:not([data-theme="dark"]) .item-price-label { color: rgba(15,17,23,0.55) !important; }
body:not([data-theme="dark"]) .item-cat-badge { color: rgba(15,17,23,0.7) !important; background: rgba(255,255,255,.95) !important; }
[data-theme="dark"]  .item-name, .has-bg-img .item-name   { color: #ffffff !important; }
[data-theme="dark"]  .item-price, .has-bg-img .item-price  { color: #ffffff !important; }
[data-theme="dark"]  .item-cond                { color: rgba(240,242,247,0.55) !important; }
.has-bg-img          .item-cond                { color: rgba(240,242,247,0.60) !important; }
[data-theme="dark"]  .item-price-label         { color: rgba(240,242,247,0.45) !important; }
[data-theme="dark"]  .item-cat-badge           { color: rgba(240,242,247,0.8) !important; }
.has-bg-img          .item-stock               { color: color-mix(in srgb, var(--accent) 90%, #fff) !important; }

/* Info cards (Visit Us) */
body:not([data-theme="dark"]) .info-card-title { color: rgba(15,17,23,0.50) !important; }
body:not([data-theme="dark"]) .info-card-val   { color: #0f1117 !important; }
[data-theme="dark"]  .info-card-title, .has-bg-img .info-card-title { color: rgba(240,242,247,0.55) !important; }
[data-theme="dark"]  .info-card-val, .has-bg-img .info-card-val { color: #ffffff !important; }

/* Promo cards */
body:not([data-theme="dark"]) .promo-title     { color: #0f1117 !important; }
[data-theme="dark"]  .promo-title, .has-bg-img .promo-title { color: #ffffff !important; }
[data-theme="dark"]  .promo-sale-price         { color: #fcd34d !important; }
body:not([data-theme="dark"]) .promo-sale-price { color: #b45309 !important; }

/* Sale prices */
body:not([data-theme="dark"]) .sale-price, body:not([data-theme="dark"]) .sale-price-label { color: #b45309 !important; }
[data-theme="dark"]  .sale-price, [data-theme="dark"] .sale-price-label { color: #fcd34d !important; }

/* Featured cards — always white (dark scrim underneath) */
.featured-card-name  { color: #fff !important; text-shadow: 0 1px 8px rgba(0,0,0,.6); }
.featured-card-price { color: #fff !important; }
.featured-card-cat   { color: color-mix(in srgb, var(--accent) 90%, #fff) !important; }

/* Category pills */
body:not([data-theme="dark"]) .cat-pill { color: rgba(15,17,23,0.70) !important; background: rgba(0,0,0,0.06) !important; border-color: rgba(0,0,0,0.10) !important; }
[data-theme="dark"]  .cat-pill, .has-bg-img .cat-pill { color: rgba(240,242,247,0.75) !important; background: rgba(255,255,255,0.08) !important; border-color: rgba(255,255,255,0.14) !important; }

/* Nav — always dark background, always white text */
nav { background: rgba(8,9,12,0.82) !important; backdrop-filter: blur(20px) !important; -webkit-backdrop-filter: blur(20px) !important; }
.nav-name { color: #ffffff !important; }
.nav-link  { color: rgba(255,255,255,0.68) !important; }
.nav-link:hover { color: #fff !important; background: rgba(255,255,255,.09) !important; }
.dm-toggle { background: rgba(255,255,255,.10) !important; border-color: rgba(255,255,255,.15) !important; color: rgba(255,255,255,.75) !important; }
.dm-toggle:hover { background: rgba(255,255,255,.18) !important; color: #fff !important; }

/* Footer */
body:not([data-theme="dark"]) .footer-name    { color: rgba(15,17,23,0.75) !important; }
body:not([data-theme="dark"]) .footer-meta    { color: rgba(15,17,23,0.40) !important; }
[data-theme="dark"]  .footer-name, .has-bg-img .footer-name { color: rgba(240,242,247,0.75) !important; }
[data-theme="dark"]  .footer-meta, .has-bg-img .footer-meta { color: rgba(240,242,247,0.35) !important; }

/* Download App CTA — always dark regardless of theme */
#mobile-app > div {
  background: linear-gradient(135deg, #0d1120 0%, #0f172a 50%, #0d1120 100%) !important;
}






/* ── NAV ── */
nav {
  position: fixed; top: 0; left: 0; right: 0; z-index: 100;
  height: var(--nav-h);
  display: flex; align-items: center; justify-content: space-between;
  padding: 0 clamp(14px, 4vw, 48px);
  background: var(--nav-bg);
  backdrop-filter: blur(20px);
  -webkit-backdrop-filter: blur(20px);
  border-bottom: 1px solid var(--border);
  transition: background .3s;
  box-sizing: border-box;
}
/* When bg image present, nav text is always white for contrast */
.has-bg-img nav,
[data-theme="dark"] nav { background: rgba(8,9,12,0.7); border-color: rgba(255,255,255,.08); }
.has-bg-img .nav-name,
[data-theme="dark"] .nav-name { color: #fff; }
.has-bg-img .nav-link,
[data-theme="dark"] .nav-link { color: rgba(255,255,255,.65); }
.has-bg-img .nav-link:hover,
[data-theme="dark"] .nav-link:hover { color: #fff; background: rgba(255,255,255,.08); }
.has-bg-img .dm-toggle,
[data-theme="dark"] .dm-toggle { background: rgba(255,255,255,.1); border-color: rgba(255,255,255,.15); color: rgba(255,255,255,.7); }
.has-bg-img .dm-toggle:hover,
[data-theme="dark"] .dm-toggle:hover { background: rgba(255,255,255,.18); color: #fff; }
.nav-brand {
  display: flex; align-items: center; gap: 11px;
  text-decoration: none;
}
.nav-logo {
  width: 40px; height: 40px; border-radius: 12px;
  background: linear-gradient(135deg, var(--primary), var(--secondary));
  display: flex; align-items: center; justify-content: center;
  flex-shrink: 0; overflow: hidden;
  box-shadow: 0 4px 14px color-mix(in srgb, var(--primary) 40%, transparent);
}
.nav-logo img { width: 100%; height: 100%; object-fit: cover; }
.nav-logo svg { width: 20px; height: 20px; }
.nav-name {
  font-family: 'DM Serif Display', serif;
  font-size: 1.3rem;
  color: var(--text);
  letter-spacing: -.01em;
  font-weight: 700;
}
.nav-links {
  display: flex; align-items: center; gap: 6px;
}
.nav-link {
  font-size: .95rem; font-weight: 500;
  color: var(--text-m); text-decoration: none;
  padding: 8px 16px; border-radius: 10px;
  transition: all .18s;
}
.nav-link:hover { color: var(--text); background: var(--surface-2); }
.nav-signin {
  display: inline-flex; align-items: center; gap: 6px;
  font-size: .92rem; font-weight: 700;
  color: #fff; text-decoration: none;
  padding: 10px 20px; border-radius: 12px;
  line-height: 1; height: auto; min-height: unset;
  transition: all .2s;
  border: none; cursor: pointer; font-family: inherit;
  white-space: nowrap; flex-shrink: 0;
}
.nav-signin:hover { filter: brightness(1.1); transform: translateY(-1px); }
.nav-signin .material-symbols-outlined {
  font-size: 18px !important; line-height: 1 !important;
  width: 18px; height: 18px; vertical-align: middle; flex-shrink: 0;
}

/* ── HERO ── */
.hero {
  min-height: 80vh;
  display: flex; align-items: center; justify-content: center;
  padding: calc(var(--nav-h) + 40px) clamp(16px,5vw,64px) 60px;
  position: relative; z-index: 10; text-align: center;
}
.hero-inner { max-width: 680px; }
.hero-eyebrow {
  display: inline-flex; align-items: center; gap: 7px;
  font-size: .72rem; font-weight: 700; letter-spacing: .12em; text-transform: uppercase;
  color: color-mix(in srgb, var(--accent) 90%, #000);
  background: color-mix(in srgb, var(--accent) 15%, transparent);
  border: 1px solid color-mix(in srgb, var(--accent) 35%, transparent);
  padding: 5px 14px; border-radius: 100px;
  margin-bottom: 20px;
}
/* When bg image is present (dark theme), eyebrow text should be lighter */
[data-theme="dark"] .hero-eyebrow {
  color: color-mix(in srgb, var(--accent) 90%, #fff);
  background: color-mix(in srgb, var(--accent) 12%, transparent);
  border-color: color-mix(in srgb, var(--accent) 25%, transparent);
}
.hero-eyebrow .material-symbols-outlined {
  font-size: 13px;
  font-variation-settings: 'FILL' 1, 'wght' 400, 'GRAD' 0, 'opsz' 24;
}
.hero-title {
  font-family: 'DM Serif Display', serif;
  font-size: clamp(2.4rem, 6vw, 4.2rem);
  color: var(--hero-title-color);
  line-height: 1.1;
  letter-spacing: -.03em;
  margin-bottom: 16px;
}
.hero-title .accent { color: var(--primary); font-style: italic; }
.hero-sub {
  font-size: clamp(1.05rem, 2.2vw, 1.25rem);
  color: var(--text-m);
  line-height: 1.75;
  max-width: 520px;
  margin: 0 auto 36px;
}
/* Light mode hero text needs better contrast when bg image is present */
.has-bg-img .hero-title { color: #fff; text-shadow: 0 2px 16px rgba(0,0,0,.45); }
.has-bg-img .hero-sub   { color: rgba(255,255,255,.82); text-shadow: 0 1px 8px rgba(0,0,0,.4); }
.has-bg-img .hero-eyebrow {
  color: color-mix(in srgb, var(--accent) 90%, #fff);
  background: color-mix(in srgb, var(--accent) 12%, transparent);
  border-color: color-mix(in srgb, var(--accent) 25%, transparent);
}
.hero-actions {
  display: flex; align-items: center; justify-content: center; gap: 12px; flex-wrap: wrap;
}
.btn-hero-primary {
  display: inline-flex; align-items: center; gap: 10px;
  font-size: 1.05rem; font-weight: 700;
  color: #fff; text-decoration: none;
  padding: 16px 34px; border-radius: 16px;
  background: var(--primary);
  box-shadow: 0 6px 24px color-mix(in srgb, var(--primary) 40%, transparent);
  transition: all .22s;
  font-family: inherit;
}
.btn-hero-primary:hover { transform: translateY(-2px); filter: brightness(1.1); }

/* Accent-colored solid button — always readable regardless of bg */
.btn-hero-accent {
  display: inline-flex; align-items: center; gap: 10px;
  font-size: 1.05rem; font-weight: 700;
  color: #fff; text-decoration: none;
  padding: 16px 34px; border-radius: 16px;
  background: var(--accent);
  box-shadow: 0 6px 24px color-mix(in srgb, var(--accent) 40%, transparent);
  transition: all .22s;
  font-family: inherit;
}
.btn-hero-accent:hover { transform: translateY(-2px); filter: brightness(1.08); }

/* Secondary hero button — solid always visible in both modes */
.btn-hero-secondary {
  display: inline-flex; align-items: center; gap: 10px;
  font-size: 1.05rem; font-weight: 700;
  color: #fff; text-decoration: none;
  padding: 16px 28px; border-radius: 16px;
  background: rgba(0,0,0,0.45);
  border: 1.5px solid rgba(255,255,255,0.4);
  transition: all .22s;
  text-shadow: 0 1px 4px rgba(0,0,0,.5);
}
[data-theme="dark"] .btn-hero-secondary,
.has-bg-img .btn-hero-secondary {
  color: #fff;
  background: rgba(0,0,0,0.45);
  border-color: rgba(255,255,255,.4);
}
[data-theme="light"]:not(.has-bg-img) .btn-hero-secondary {
  color: #0f1117;
  background: rgba(0,0,0,0.10);
  border-color: rgba(0,0,0,0.30);
  text-shadow: none;
}
.btn-hero-secondary:hover {
  background: rgba(0,0,0,0.60);
  border-color: rgba(255,255,255,.6);
}
[data-theme="light"]:not(.has-bg-img) .btn-hero-secondary:hover {
  background: rgba(0,0,0,0.18);
  border-color: rgba(0,0,0,0.45);
}
.hero-stats {
  display: flex; align-items: center; justify-content: center; gap: 28px;
  margin-top: 36px; flex-wrap: wrap;
}
.hero-stat { text-align: center; }
.hero-stat-val {
  font-family: 'DM Serif Display', serif;
  font-size: 1.8rem; color: var(--hero-title-color); line-height: 1;
}
.hero-stat-label { font-size: .72rem; color: var(--text-dim); margin-top: 3px; font-weight: 500; text-transform: uppercase; letter-spacing: .08em; }
.hero-stat-divider { width: 1px; height: 36px; background: var(--border); }

/* ── SECTION ── */
section { position: relative; z-index: 10; padding: 60px clamp(16px,5vw,64px); }
.section-hdr {
  display: flex; align-items: flex-end; justify-content: space-between;
  margin-bottom: 28px; gap: 12px; flex-wrap: wrap;
}
.section-label {
  font-size: .68rem; font-weight: 700; letter-spacing: .12em; text-transform: uppercase;
  color: color-mix(in srgb, var(--primary) 90%, #fff);
  margin-bottom: 5px;
}
.section-title {
  font-family: 'DM Serif Display', serif;
  font-size: clamp(1.5rem, 3.5vw, 2.2rem);
  color: var(--section-title-color); line-height: 1.15;
}
.see-all {
  display: flex; align-items: center; gap: 5px;
  font-size: .82rem; font-weight: 600;
  color: var(--primary); text-decoration: none; white-space: nowrap;
  transition: gap .18s;
}
.see-all:hover { gap: 10px; }

/* ── CATEGORY PILLS ── */
.cat-pills {
  display: flex; gap: 8px; flex-wrap: wrap;
  margin-bottom: 28px;
}
.cat-pill {
  display: inline-flex; align-items: center; gap: 6px;
  font-size: .8rem; font-weight: 600;
  color: var(--text-m); background: var(--surface);
  border: 1px solid var(--border); border-radius: 100px;
  padding: 6px 14px; cursor: pointer;
  transition: all .18s;
  user-select: none;
}
.cat-pill:hover, .cat-pill.active {
  background: color-mix(in srgb, var(--primary) 18%, transparent);
  border-color: color-mix(in srgb, var(--primary) 45%, transparent);
  color: #fff;
}
.cat-pill .material-symbols-outlined {
  font-size: 15px;
  font-variation-settings: 'FILL' 1, 'wght' 400, 'GRAD' 0, 'opsz' 24;
}
.cat-pill-count {
  font-size: .65rem; font-weight: 700;
  background: rgba(255,255,255,.1);
  padding: 1px 6px; border-radius: 100px;
}

/* ── ITEMS GRID ── */
.items-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(min(100%, 230px), 1fr));
  gap: 18px;
}
.item-card {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: 18px;
  overflow: hidden;
  transition: all .25s;
  cursor: pointer;
  text-decoration: none;
  display: flex; flex-direction: column;
  position: relative;
}
.item-card:hover {
  transform: translateY(-4px);
  border-color: color-mix(in srgb, var(--primary) 40%, transparent);
  box-shadow: 0 16px 40px rgba(0,0,0,.4),
              0 0 0 1px color-mix(in srgb, var(--primary) 20%, transparent);
}
.item-img-wrap {
  aspect-ratio: 1 / 1; overflow: hidden;
  background: var(--surface);
  position: relative;
}
.item-img-wrap img {
  width: 100%; height: 100%; object-fit: cover;
  transition: transform .4s;
}
.item-card:hover .item-img-wrap img { transform: scale(1.06); }
.item-img-placeholder {
  width: 100%; height: 100%;
  display: flex; align-items: center; justify-content: center;
  background: var(--placeholder-bg);
}
.item-img-placeholder .material-symbols-outlined {
  font-size: 52px; color: rgba(128,128,128,.2);
}
.item-featured-badge {
  position: absolute; top: 10px; left: 10px;
  background: linear-gradient(135deg, #f59e0b, #d97706);
  color: #fff; font-size: .62rem; font-weight: 800;
  text-transform: uppercase; letter-spacing: .1em;
  padding: 3px 9px; border-radius: 100px;
  box-shadow: 0 2px 10px rgba(245,158,11,.4);
}
.item-sale-badge {
  position: absolute; top: 10px; left: 10px;
  background: linear-gradient(135deg, #ef4444, #dc2626);
  color: #fff; font-size: .62rem; font-weight: 800;
  text-transform: uppercase; letter-spacing: .1em;
  padding: 3px 9px; border-radius: 100px;
  box-shadow: 0 2px 10px rgba(239,68,68,.45);
  animation: salePulse 2s ease-in-out infinite;
}
@keyframes salePulse {
  0%,100% { box-shadow: 0 2px 10px rgba(239,68,68,.45); }
  50%      { box-shadow: 0 2px 18px rgba(239,68,68,.7); }
}
.item-cat-badge {
  position: absolute; top: 10px; right: 10px;
  background: var(--item-cat-badge-bg);
  backdrop-filter: blur(8px);
  color: var(--text-m); font-size: .63rem; font-weight: 600;
  padding: 3px 9px; border-radius: 100px;
  border: 1px solid var(--border);
}
.item-body { padding: 18px 20px; flex: 1; display: flex; flex-direction: column; }
.item-name {
  font-size: 1.12rem; font-weight: 700; color: var(--item-name-color);
  line-height: 1.4; margin-bottom: 6px;
  display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;
}
.item-cond {
  font-size: .9rem; color: var(--text-dim);
  margin-bottom: 10px; font-weight: 500;
}
.item-footer {
  display: flex; flex-direction: column;
  margin-top: auto; gap: 4px;
}
.item-price {
  font-family: 'DM Serif Display', serif;
  font-size: 1.45rem; color: var(--item-price-color);
}
.item-price-label { font-size: .78rem; color: var(--text-dim); font-family: 'DM Sans', sans-serif; font-weight: 600; text-transform: uppercase; letter-spacing: .04em; }
/* Sale price - readable in both modes */
.sale-price-label { color: #d97706 !important; }
[data-theme="dark"] .sale-price-label { color: #fcd34d !important; }
.sale-price { color: #d97706 !important; }
[data-theme="dark"] .sale-price { color: #fcd34d !important; }
.item-orig-price {
  font-size: .75rem; text-decoration: line-through; line-height: 1.3; margin-top: 1px;
  color: rgba(15,17,23,0.4);
}
[data-theme="dark"] .item-orig-price { color: rgba(240,242,247,0.35); }
.item-stock {
  font-size: .8rem; font-weight: 600;
  color: color-mix(in srgb, var(--accent) 75%, #000);
  white-space: nowrap;
}
[data-theme="dark"] .item-stock {
  color: color-mix(in srgb, var(--accent) 90%, #fff);
}

/* ── FEATURED STRIP ── */
.featured-strip {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(min(100%, 280px), 1fr));
  gap: 18px;
}
.featured-card {
  position: relative; overflow: hidden; border-radius: 20px;
  background: var(--surface); border: 1px solid color-mix(in srgb, var(--primary) 20%, transparent);
  min-height: 200px; display: flex; align-items: flex-end;
  text-decoration: none;
  transition: all .25s;
}
.featured-card:hover { transform: translateY(-3px); box-shadow: 0 16px 40px rgba(0,0,0,.5); }
.featured-card-bg {
  position: absolute; inset: 0;
}
.featured-card-bg img {
  width: 100%; height: 100%; object-fit: cover; opacity: .75;
  transition: transform .4s, opacity .3s;
}
.featured-card:hover .featured-card-bg img { transform: scale(1.05); opacity: .85; }
.featured-card-bg-grad {
  position: absolute; inset: 0;
  background: var(--featured-bg-grad);
}
.featured-card-body {
  position: relative; z-index: 2; padding: 18px 18px 18px;
  width: 100%;
}
.featured-card-cat {
  font-size: .65rem; font-weight: 700; letter-spacing: .1em; text-transform: uppercase;
  color: color-mix(in srgb, var(--accent) 90%, #fff);
  margin-bottom: 5px;
}
.featured-card-name {
  font-family: 'DM Serif Display', serif;
  font-size: 1.2rem; color: var(--featured-name-color); line-height: 1.25;
  margin-bottom: 8px;
}
.featured-card-price {
  font-size: 1rem; font-weight: 700; color: var(--featured-price-color);
}
.featured-star {
  position: absolute; top: 14px; right: 14px; z-index: 2;
  background: rgba(245,158,11,.9); color: #fff;
  font-size: .6rem; font-weight: 800; letter-spacing: .08em;
  text-transform: uppercase; padding: 3px 8px; border-radius: 100px;
}

/* ── INFO SECTION ── */
.info-grid {
  display: grid; grid-template-columns: repeat(auto-fill, minmax(min(100%, 220px), 1fr)); gap: 18px;
}
@media (max-width: 640px) { .info-grid { grid-template-columns: 1fr; } }
.info-card {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: 20px; padding: 24px;
}
.info-card-icon {
  width: 44px; height: 44px; border-radius: 12px;
  background: color-mix(in srgb, var(--primary) 15%, transparent);
  border: 1px solid color-mix(in srgb, var(--primary) 25%, transparent);
  display: flex; align-items: center; justify-content: center;
  margin-bottom: 14px;
}
.info-card-icon .material-symbols-outlined {
  font-size: 22px; color: var(--primary);
  font-variation-settings: 'FILL' 1, 'wght' 400, 'GRAD' 0, 'opsz' 24;
}
.info-card-title { font-size: .78rem; font-weight: 700; text-transform: uppercase; letter-spacing: .08em; color: var(--text-dim); margin-bottom: 8px; }
.info-card-val { font-size: 1rem; font-weight: 600; color: var(--info-val-color); line-height: 1.5; }

/* ── QR CODE CARD ── */
.qr-card { text-align: center; }
.qr-wrap {
  display: flex; flex-direction: column; align-items: center; gap: 10px;
  margin-top: 4px;
}
.qr-img {
  width: 140px; height: 140px; object-fit: contain;
  border-radius: 12px;
  background: #fff;
  padding: 8px;
  box-shadow: 0 4px 20px rgba(0,0,0,.3);
}
.qr-label {
  font-size: .75rem; color: var(--text-dim); font-weight: 500;
}
.qr-drive-link {
  display: inline-flex; align-items: center; gap: 6px;
  font-size: .8rem; font-weight: 600;
  color: var(--text); text-decoration: none;
  padding: 8px 16px; border-radius: 10px;
  background: color-mix(in srgb, var(--primary) 20%, transparent);
  border: 1px solid color-mix(in srgb, var(--primary) 35%, transparent);
  transition: all .2s;
  margin-top: 2px;
}
.qr-drive-link:hover {
  background: color-mix(in srgb, var(--primary) 35%, transparent);
  transform: translateY(-1px);
}
.qr-drive-link .material-symbols-outlined {
  font-size: 15px;
  font-variation-settings: 'FILL' 1, 'wght' 400, 'GRAD' 0, 'opsz' 24;
}

/* ── CTA BANNER ── */
.cta-banner {
  background: linear-gradient(135deg, var(--secondary), var(--primary));
  border-radius: 24px; padding: 40px 36px;
  text-align: center; position: relative; overflow: hidden;
}
.cta-banner::before {
  content: '';
  position: absolute; top: -60px; right: -60px;
  width: 200px; height: 200px; border-radius: 50%;
  background: rgba(255,255,255,.06);
}
.cta-banner::after {
  content: '';
  position: absolute; bottom: -80px; left: -40px;
  width: 240px; height: 240px; border-radius: 50%;
  background: rgba(255,255,255,.04);
}
.cta-banner-title {
  font-family: 'DM Serif Display', serif;
  font-size: clamp(1.4rem, 3vw, 2rem);
  color: #fff; margin-bottom: 10px; position: relative; z-index: 1;
}
.cta-banner-sub { font-size: .9rem; color: rgba(255,255,255,.7); margin-bottom: 24px; position: relative; z-index: 1; }

/* ── EMPTY STATE ── */
.empty-shop {
  text-align: center; padding: 80px 24px;
  color: var(--text-dim);
}
.empty-shop .material-symbols-outlined {
  font-size: 64px; display: block; margin-bottom: 16px; opacity: .25;
}
.empty-shop-title { font-family: 'DM Serif Display', serif; font-size: 1.5rem; color: var(--empty-title-color); margin-bottom: 8px; }
.empty-shop-sub { font-size: .85rem; }

/* ── FOOTER ── */
footer {
  position: relative; z-index: 10;
  background: var(--footer-bg);
  border-top: 1px solid var(--border);
  padding: 28px clamp(16px,5vw,64px);
  display: flex; align-items: center; justify-content: space-between;
  flex-wrap: wrap; gap: 12px;
}
.footer-brand { display: flex; align-items: center; gap: 10px; }
.footer-logo {
  width: 32px; height: 32px; border-radius: 9px;
  background: linear-gradient(135deg, var(--primary), var(--secondary));
  display: flex; align-items: center; justify-content: center;
  overflow: hidden;
}
.footer-logo img { width: 100%; height: 100%; object-fit: cover; }
.footer-logo svg { width: 16px; height: 16px; }
.footer-name { font-weight: 700; color: var(--footer-name-color); font-size: .88rem; }
.footer-meta { font-size: .74rem; color: var(--text-dim); }
.footer-signin {
  display: inline-flex; align-items: center; gap: 6px;
  font-size: .82rem; font-weight: 700;
  color: var(--primary); text-decoration: none;
  padding: 7px 16px; border-radius: 10px;
  border: 1px solid color-mix(in srgb, var(--primary) 35%, transparent);
  background: color-mix(in srgb, var(--primary) 10%, transparent);
  transition: all .18s;
}
.footer-signin:hover { background: color-mix(in srgb, var(--primary) 20%, transparent); }

/* ── MODAL ── */
.modal-overlay {
  display: none; position: fixed; inset: 0; z-index: 200;
  background: rgba(0,0,0,.8); backdrop-filter: blur(8px);
  align-items: center; justify-content: center; padding: 16px;
}
.modal-overlay.open { display: flex; }
.modal-box {
  background: var(--modal-bg);
  border: 1px solid var(--border);
  border-radius: 22px; width: 100%; max-width: 440px;
  box-shadow: 0 24px 80px rgba(0,0,0,.7);
  animation: mIn .25s ease both; overflow: hidden;
}
@keyframes mIn { from { opacity:0; transform: translateY(16px) } to { opacity:1; transform:none } }

/* ── DARK MODE TOGGLE BUTTON ── */
.dm-toggle {
  display: flex; align-items: center; justify-content: center;
  width: 38px; height: 38px; border-radius: 12px;
  background: var(--surface); border: 1px solid var(--border);
  cursor: pointer; transition: all .2s; color: var(--text-m);
  flex-shrink: 0;
}
.dm-toggle:hover { background: var(--surface-2); color: var(--text); }
.dm-toggle .material-symbols-outlined { font-size: 19px; }
.modal-head {
  background: linear-gradient(135deg, var(--secondary), var(--primary));
  padding: 28px 28px 24px;
  display: flex; align-items: center; gap: 14px;
}
.modal-logo {
  width: 48px; height: 48px; border-radius: 14px;
  background: rgba(255,255,255,.15);
  display: flex; align-items: center; justify-content: center;
  overflow: hidden; flex-shrink: 0;
}
.modal-logo img { width: 100%; height: 100%; object-fit: cover; }
.modal-logo svg { width: 24px; height: 24px; }
.modal-head-title { font-family: 'DM Serif Display', serif; font-size: 1.4rem; color: #fff; }
.modal-head-sub { font-size: .78rem; color: rgba(255,255,255,.65); margin-top: 2px; }
.modal-body { padding: 24px 28px 28px; }
.modal-desc { font-size: .88rem; color: var(--text-m); line-height: 1.7; margin-bottom: 20px; }
.modal-btn {
  display: flex; align-items: center; justify-content: center; gap: 8px;
  width: 100%; padding: 13px;
  font-family: inherit; font-size: .95rem; font-weight: 700;
  color: #fff; text-decoration: none;
  background: var(--primary);
  border: none; border-radius: 12px; cursor: pointer;
  box-shadow: 0 4px 18px color-mix(in srgb, var(--primary) 40%, transparent);
  transition: all .2s; margin-bottom: 10px;
}
.modal-btn:hover { filter: brightness(1.1); transform: translateY(-1px); }
.modal-btn.secondary {
  background: var(--surface); border: 1px solid var(--border);
  color: var(--text-m); box-shadow: none;
}
.modal-btn.secondary:hover { background: var(--surface-2); color: var(--text); transform: none; }
.modal-close-x {
  position: absolute; top: 16px; right: 16px;
  width: 32px; height: 32px; border-radius: 9px;
  background: var(--surface-2); border: 1px solid var(--border); cursor: pointer;
  display: flex; align-items: center; justify-content: center;
  color: var(--text-m);
}
.modal-box-wrap { position: relative; }

/* ── ITEM DETAIL MODAL ── */
.item-detail-modal { max-width: 540px; }
.item-detail-img {
  width: 100%; aspect-ratio: 16/9; object-fit: cover;
  border-radius: 14px; margin-bottom: 16px;
  border: 1px solid var(--border);
}
.item-detail-img-placeholder {
  width: 100%; aspect-ratio: 16/9;
  background: var(--surface-2); border-radius: 14px;
  display: flex; align-items: center; justify-content: center;
  margin-bottom: 16px; border: 1px solid var(--border);
}
.item-detail-img-placeholder .material-symbols-outlined { font-size: 48px; color: var(--text-dim); }
.item-detail-name { font-family: 'DM Serif Display', serif; font-size: 1.55rem; color: var(--text); margin-bottom: 6px; }
.item-detail-price { font-size: 1.7rem; font-weight: 800; color: var(--primary); margin-bottom: 12px; }
.item-detail-meta { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 14px; }
.item-detail-badge {
  display: inline-flex; align-items: center; gap: 5px;
  font-size: .78rem; font-weight: 600; padding: 5px 12px; border-radius: 100px;
  background: var(--surface-2); border: 1px solid var(--border); color: var(--text-m);
}
.item-detail-desc { font-size: .88rem; color: var(--text-m); line-height: 1.65; margin-bottom: 18px; }

/* ── HIDDEN FILTER ── */
.item-card[data-cat].hidden { display: none; }

/* ── RESPONSIVE ── */
/* ===== COMPREHENSIVE MOBILE RESPONSIVE ===== */
@media (max-width: 768px) {
  .nav-links { display: none; }
  .nav-signin {
    padding: 7px 12px !important;
    font-size: .78rem !important;
    gap: 4px !important;
    line-height: 1 !important;
    height: auto !important;
    min-height: unset !important;
    border-radius: 9px !important;
    flex-shrink: 0 !important;
  }
  .nav-signin .material-symbols-outlined {
    font-size: 14px !important; line-height: 1 !important;
    width: 14px !important; height: 14px !important;
  }
  nav > div:last-child { gap: 6px !important; }
  .nav-name { font-size: 1rem; }
  .nav-signin-login {
    background: var(--primary) !important;
    box-shadow: 0 4px 14px color-mix(in srgb, var(--primary) 35%, transparent) !important;
    color: #fff !important;
  }
}
@media (max-width: 500px) {
  :root { --nav-h: 56px; }
  nav { padding: 0 10px !important; }
  nav > div:last-child { gap: 4px !important; }
  .nav-signin {
    padding: 6px 9px !important;
    border-radius: 8px !important;
    font-size: .72rem !important;
    line-height: 1 !important;
    height: auto !important;
    min-height: unset !important;
  }
  .nav-signin .material-symbols-outlined {
    font-size: 13px !important; line-height: 1 !important;
    width: 13px !important; height: 13px !important;
  }
  .dm-toggle { width: 32px !important; height: 32px !important; border-radius: 9px !important; flex-shrink: 0; }
  .dm-toggle .material-symbols-outlined { font-size: 16px !important; }
  .nav-name { max-width: 110px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-size: .82rem; }
  .nav-logo { width: 32px !important; height: 32px !important; }
}
@media (max-width: 700px) {
  #app > div { grid-template-columns: 1fr !important; }
  #app > div > div:last-child { display: none; }
  .hero { min-height: 75vh; }
  nav { padding: 0 14px; }
  section { padding: 36px 14px; }
  footer { padding: 20px 14px; flex-direction: column; gap: 8px; text-align: center; }
  .cta-banner { padding: 24px 16px; border-radius: 16px; }
}
@media (max-width: 480px) {
  .hero-stat-divider { display: none; }
  .hero-stats { gap: 12px !important; flex-wrap: wrap; justify-content: center; }
  .hero-stat { flex: none; min-width: 70px; }
  .hero-actions { flex-direction: column; align-items: stretch; }
  .btn-hero-primary, .btn-hero-accent, .btn-hero-secondary { justify-content: center; }
  .hero-title { font-size: clamp(2rem, 10vw, 3.5rem) !important; }
  .hero-sub { font-size: .88rem; }
  .section-title { font-size: 1.5rem !important; }
}

/* ===== MOBILE / iOS COMPATIBILITY FIXES ===== */
* { -webkit-tap-highlight-color: transparent; }
html { -webkit-text-size-adjust: 100%; }
/* iOS safe area support */
.safe-top    { padding-top:    env(safe-area-inset-top,    0px); }
.safe-bottom { padding-bottom: env(safe-area-inset-bottom, 0px); }
/* iOS overflow scroll */
.overflow-y-auto, .overflow-auto { -webkit-overflow-scrolling: touch; }
/* Prevent iOS zoom on input focus */
input, select, textarea { font-size: max(16px, 1rem) !important; }
/* Mobile sidebar fix */
@media (max-width: 768px) {
  .sidebar-fixed { position: fixed !important; z-index: 50; height: 100dvh; }
  .main-content  { margin-left: 0 !important; width: 100% !important; }
}
/* Smooth scrolling on mobile */
html { scroll-behavior: smooth; }

/* Form mobile fixes */
@media (max-width: 480px) {
    .panel, .card { 
        width: 100% !important; 
        max-width: 100% !important; 
        margin: 0 !important;
        border-radius: 0 !important;
        min-height: 100dvh !important;
    }
    .page { padding: 0 !important; align-items: flex-start !important; }
}

/* ===== RESPONSIVE TABLES ===== */
.table-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; width: 100%; }
table { width: 100%; border-collapse: collapse; min-width: 480px; }
@media (max-width: 768px) {
    .table-wrap::before { content: '← Swipe →'; display: block; text-align: center; font-size: .65rem; color: rgba(255,255,255,.3); padding: 3px 0; }
    table { font-size: .74rem !important; }
    th, td { padding: 7px 9px !important; white-space: nowrap; }
}
@media (max-width: 480px) {
    .content, .page-content { padding: 10px 8px !important; }
}
</style>
</head>
<body data-theme="<?= $has_bg ? 'dark' : 'light' ?>" class="<?= $has_bg ? 'has-bg-img' : '' ?>">

<!-- Background -->
<div class="bg-scene">
  <?php if($bg_url): ?>
  <img class="bg-scene-img" src="<?= htmlspecialchars($bg_url) ?>" alt="">
  <?php endif; ?>
  <div class="bg-gradient"></div>
</div>

<!-- NAV -->
<nav>
  <a href="#" class="nav-brand">
    <div class="nav-logo">
      <?php if($logo_url): ?>
        <img src="<?= htmlspecialchars($logo_url) ?>" alt="logo">
      <?php else: ?>
        <svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2.5"><rect x="3" y="9" width="18" height="12"/><polyline points="3 9 12 3 21 9"/></svg>
      <?php endif; ?>
    </div>
    <span class="nav-name"><?= $biz_name ?></span>
  </a>

  <div class="nav-links">
    <?php if($total_items > 0): ?>
    <a href="#shop" class="nav-link">Shop</a>
    <?php endif; ?>
    <?php if(!empty($promos)): ?>
    <a href="#promos" class="nav-link">
      <span class="material-symbols-outlined" style="font-size:15px;vertical-align:-3px;">campaign</span>Promos
    </a>
    <?php endif; ?>
    <a href="#how-it-works" class="nav-link">How It Works</a>
    <a href="#services" class="nav-link">Services</a>
    <?php if($biz_addr || $biz_phone): ?>
    <a href="#info" class="nav-link">Visit Us</a>
    <?php endif; ?>
    <a href="#mobile-app" class="nav-link">
      <span class="material-symbols-outlined" style="font-size:15px;vertical-align:-3px;">download</span>Download App
    </a>
  </div>

  <div style="display:flex;align-items:center;gap:8px;">
    <button class="dm-toggle" onclick="toggleDarkMode()" id="dmBtn" title="Toggle dark mode">
      <span class="material-symbols-outlined" id="dmIcon">dark_mode</span>
    </button>
    <a href="<?= htmlspecialchars($register_url) ?>" class="nav-signin" style="background:color-mix(in srgb,var(--accent) 80%,#000);box-shadow:0 4px 18px color-mix(in srgb,var(--accent) 35%,transparent);">
      <span class="material-symbols-outlined">person_add</span>Register
    </a>
    <a href="<?= htmlspecialchars($login_url) ?>" class="nav-signin nav-signin-login" style="background:var(--primary);box-shadow:0 4px 18px color-mix(in srgb,var(--primary) 35%,transparent);">
      <span class="material-symbols-outlined">login</span>Sign In
    </a>
  </div>
</nav>

<!-- HERO -->
<section class="hero">
  <div class="hero-inner">
    <div class="hero-eyebrow">
      <span class="material-symbols-outlined">storefront</span>
      <?= $biz_name ?>
    </div>
    <h1 class="hero-title">
      <?= htmlspecialchars($hero_title) ?><br><span class="accent"><?= htmlspecialchars($hero_subtitle) ?></span>
    </h1>
    <p class="hero-sub">
      Get cash fast using your valuables as collateral — jewelry, watches, gadgets, and more. No credit check required.
      <?php if($biz_addr): ?>Visit us at <?= $biz_addr ?>.<?php endif; ?>
    </p>
    <div class="hero-actions">
      <?php if($total_items > 0): ?>
      <a href="#shop" class="btn-hero-primary">
        <span class="material-symbols-outlined">shopping_bag</span>Browse Items
      </a>
      <?php endif; ?>
      <a href="#mobile-app" class="btn-hero-accent">
        <span class="material-symbols-outlined">download</span>Download App
      </a>
      <a href="<?= htmlspecialchars($login_url) ?>" class="btn-hero-secondary">
        <span class="material-symbols-outlined">login</span>Sign In
      </a>
    </div>

    <div class="hero-stats">
      <?php if($total_items > 0): ?>
      <div class="hero-stat">
        <div class="hero-stat-val"><?= $total_items ?>+</div>
        <div class="hero-stat-label">Items Available</div>
      </div>
      <div class="hero-stat-divider"></div>
      <?php endif; ?>
      <?php if($total_customers > 0): ?>
      <div class="hero-stat">
        <div class="hero-stat-val"><?= number_format($total_customers) ?></div>
        <div class="hero-stat-label">Customers Served</div>
      </div>
      <div class="hero-stat-divider"></div>
      <?php endif; ?>
      <?php if($active_pawns > 0): ?>
      <div class="hero-stat">
        <div class="hero-stat-val"><?= number_format($active_pawns) ?></div>
        <div class="hero-stat-label">Active Pawns</div>
      </div>
      <?php if(count($categories) > 0): ?><div class="hero-stat-divider"></div><?php endif; ?>
      <?php endif; ?>
      <?php if(count($categories) > 0): ?>
      <div class="hero-stat">
        <div class="hero-stat-val"><?= count($categories) ?></div>
        <div class="hero-stat-label">Categories</div>
      </div>
      <?php endif; ?>
    </div>
  </div>
</section>

<?php if(!empty($promos)): ?>
<!-- PROMOS & ANNOUNCEMENTS -->
<section id="promos" style="padding-top:0;">
  <div class="section-hdr">
    <div>
      <div class="section-label">📢 News</div>
      <h2 class="section-title">Promos &amp; Announcements</h2>
    </div>
  </div>
  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(min(100%,300px),1fr));gap:16px;">
    <?php foreach($promos as $promo):
      $type_color = match($promo['type'] ?? 'announcement') {
        'promo'       => 'var(--primary)',
        'sale'        => '#f59e0b',
        'warning'     => '#ef4444',
        default       => 'var(--accent)',
      };
      $type_icon = match($promo['type'] ?? 'announcement') {
        'promo'       => 'local_offer',
        'sale'        => 'sell',
        'warning'     => 'warning',
        default       => 'campaign',
      };
      $type_label = match($promo['type'] ?? 'announcement') {
        'promo'   => 'Promo',
        'sale'    => 'Sale',
        'warning' => 'Notice',
        default   => 'Announcement',
      };
    ?>
    <?php
      $p_img      = $promo['image_url'] ?? '';
      $p_item_photo = $promo['linked_item_photo'] ?? '';
      $p_item_name  = $promo['linked_item_name']  ?? '';
      $p_item_price = $promo['linked_item_price']  ?? null;
      $p_item_orig  = $promo['linked_item_orig_price'] ?? null;
      $p_has_item   = !empty($promo['linked_item_id']);
      $p_disc       = (float)($promo['discount_pct'] ?? 0);
      $p_show_photo = $p_img ?: ($p_has_item ? $p_item_photo : '');
    ?>
    <div class="promo-card" style="
      background:var(--surface);border:1px solid var(--border);border-radius:18px;
      overflow:hidden;display:flex;flex-direction:column;
      <?= !empty($promo['is_pinned']) ? 'border-color:color-mix(in srgb,'.htmlspecialchars($type_color).' 40%,transparent);box-shadow:0 0 0 1px color-mix(in srgb,'.htmlspecialchars($type_color).' 15%,transparent);' : '' ?>
    ">
      <?php if($p_show_photo): ?>
      <div style="height:180px;overflow:hidden;position:relative;background:rgba(0,0,0,.3);">
        <img src="<?= htmlspecialchars($p_show_photo) ?>" alt="<?= htmlspecialchars($p_item_name) ?>" style="width:100%;height:100%;object-fit:cover;<?= !$p_img && $p_item_photo ? 'opacity:.85;' : '' ?>">
        <?php if(!$p_img && $p_item_photo): ?>
          <div style="position:absolute;inset:0;background:linear-gradient(to top,rgba(8,9,12,.75) 0%,transparent 55%);"></div>
          <?php if($p_disc > 0): ?>
          <div style="position:absolute;top:12px;right:12px;background:linear-gradient(135deg,#f59e0b,#d97706);color:#fff;font-size:.7rem;font-weight:800;padding:4px 11px;border-radius:100px;box-shadow:0 2px 12px rgba(245,158,11,.5);letter-spacing:.03em;">
            <?= $p_disc ?>% OFF
          </div>
          <?php endif; ?>
          <div style="position:absolute;bottom:10px;left:14px;right:14px;display:flex;align-items:flex-end;justify-content:space-between;gap:8px;">
            <div style="font-size:.79rem;font-weight:700;color:rgba(255,255,255,.85);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?= htmlspecialchars($p_item_name) ?></div>
            <?php if($p_disc > 0 && $p_item_price !== null): ?>
            <div style="flex-shrink:0;text-align:right;">
              <div style="font-size:.95rem;font-weight:800;color:#fcd34d;line-height:1;">₱<?= number_format((float)$p_item_price, 2) ?></div>
              <?php if($p_item_orig): ?>
              <div style="font-size:.68rem;color:rgba(255,255,255,.4);text-decoration:line-through;line-height:1.3;">₱<?= number_format((float)$p_item_orig, 2) ?></div>
              <?php endif; ?>
            </div>
            <?php endif; ?>
          </div>
        <?php endif; ?>
      </div>
      <?php endif; ?>
      <div style="padding:18px 20px;flex:1;display:flex;flex-direction:column;gap:10px;">
        <!-- Type badge + pinned -->
        <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
          <span style="display:inline-flex;align-items:center;gap:5px;font-size:.65rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;padding:3px 10px;border-radius:100px;background:color-mix(in srgb,<?= htmlspecialchars($type_color) ?> 15%,transparent);color:<?= htmlspecialchars($type_color) ?>;border:1px solid color-mix(in srgb,<?= htmlspecialchars($type_color) ?> 30%,transparent);">
            <span class="material-symbols-outlined" style="font-size:12px;font-variation-settings:'FILL' 1,'wght' 400,'GRAD' 0,'opsz' 24;"><?= $type_icon ?></span>
            <?= $type_label ?>
          </span>
          <?php if(!empty($promo['is_pinned'])): ?>
          <span style="font-size:.62rem;font-weight:700;color:var(--text-dim);display:flex;align-items:center;gap:4px;">
            <span class="material-symbols-outlined" style="font-size:12px;font-variation-settings:'FILL' 1,'wght' 400,'GRAD' 0,'opsz' 24;">push_pin</span>Pinned
          </span>
          <?php endif; ?>
        </div>
        <!-- Title -->
        <div class="promo-title" style="font-size:1.08rem;font-weight:700;line-height:1.35;"><?= htmlspecialchars($promo['title']) ?></div>
        <!-- Linked item price (if no photo overlay was shown) -->
        <?php if(!$p_show_photo && $p_has_item && $p_disc > 0 && $p_item_price !== null): ?>
        <div style="display:flex;align-items:center;gap:9px;flex-wrap:wrap;padding:10px 12px;background:rgba(245,158,11,.07);border:1px solid rgba(245,158,11,.18);border-radius:12px;">
          <div style="width:38px;height:38px;border-radius:9px;background:var(--surface-2);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
            <span class="material-symbols-outlined" style="font-size:19px;color:var(--text-dim);">diamond</span>
          </div>
          <div style="flex:1;min-width:0;">
            <div style="font-size:.82rem;color:var(--text-m);"><?= htmlspecialchars($p_item_name) ?></div>
            <div style="display:flex;align-items:center;gap:7px;margin-top:2px;">
              <span style="font-size:1.05rem;font-weight:800;color:#d97706;" class="promo-sale-price">₱<?= number_format((float)$p_item_price, 2) ?></span>
              <?php if($p_item_orig): ?>
              <span style="font-size:.82rem;text-decoration:line-through;color:var(--text-dim);">₱<?= number_format((float)$p_item_orig, 2) ?></span>
              <?php endif; ?>
              <span style="font-size:.62rem;font-weight:800;background:rgba(245,158,11,.2);color:#d97706;border:1px solid rgba(245,158,11,.3);padding:1px 7px;border-radius:100px;"><?= $p_disc ?>% OFF</span>
            </div>
          </div>
        </div>
        <?php endif; ?>
        <!-- Body -->
        <?php if(!empty($promo['body'])): ?>
        <div style="font-size:.92rem;color:var(--text-m);line-height:1.7;flex:1;"><?= nl2br(htmlspecialchars($promo['body'])) ?></div>
        <?php endif; ?>
        <!-- Date range -->
        <?php if(!empty($promo['start_date']) || !empty($promo['end_date'])): ?>
        <div style="font-size:.8rem;color:var(--text-dim);display:flex;align-items:center;gap:5px;margin-top:4px;">
          <span class="material-symbols-outlined" style="font-size:14px;font-variation-settings:'FILL' 1,'wght' 400,'GRAD' 0,'opsz' 24;">event</span>
          <?php
            $from = !empty($promo['start_date']) ? date('M d, Y', strtotime($promo['start_date'])) : null;
            $to   = !empty($promo['end_date'])   ? date('M d, Y', strtotime($promo['end_date'])) : null;
            if ($from && $to) echo "Valid: {$from} – {$to}";
            elseif ($from)    echo "Starts: {$from}";
            elseif ($to)      echo "Until: {$to}";
          ?>
        </div>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>

<?php if(!empty($featured)): ?>
<!-- FEATURED -->
<section id="featured" style="padding-top:0;">
  <div class="section-hdr">
    <div>
      <div class="section-label">⭐ Featured</div>
      <h2 class="section-title">Highlighted Items</h2>
    </div>
  </div>
  <div class="featured-strip">
    <?php foreach(array_slice($featured,0,4) as $item): ?>
    <a href="#" class="featured-card" onclick="openItem(<?= htmlspecialchars(json_encode($item), ENT_QUOTES) ?>); return false;">
      <div class="featured-card-bg">
        <?php if(!empty($item['item_photo_path'])): ?>
          <img src="<?= htmlspecialchars($item['item_photo_path']) ?>" alt="<?= htmlspecialchars($item['item_name']??'') ?>">
        <?php else: ?>
          <div style="width:100%;height:100%;background:linear-gradient(135deg,<?= $secondary ?>,<?= $primary ?>);"></div>
        <?php endif; ?>
        <div class="featured-card-bg-grad"></div>
      </div>
      <span class="featured-star">Featured</span>
      <div class="featured-card-body">
        <?php if($item['cat_name']): ?>
        <div class="featured-card-cat"><?= htmlspecialchars($item['cat_name']) ?></div>
        <?php endif; ?>
        <div class="featured-card-name"><?= htmlspecialchars($item['item_name'] ?? 'Item') ?></div>
        <div class="featured-card-price">₱<?= number_format($item['display_price'], 2) ?></div>
      </div>
    </a>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>

<!-- SHOP -->
<section id="shop">
  <div class="section-hdr">
    <div>
      <div class="section-label">🛒 Browse</div>
      <h2 class="section-title">All Items</h2>
    </div>
  </div>

  <?php if(!empty($categories)): ?>
  <div class="cat-pills">
    <div class="cat-pill active" data-filter="all" onclick="filterItems('all', this)">
      <span class="material-symbols-outlined">apps</span>
      All
      <span class="cat-pill-count"><?= $total_items ?></span>
    </div>
    <?php foreach($categories as $cat): ?>
    <div class="cat-pill" data-filter="<?= $cat['id'] ?>" onclick="filterItems('<?= $cat['id'] ?>', this)">
      <span class="material-symbols-outlined"><?= htmlspecialchars($cat['icon'] ?? 'category') ?></span>
      <?= htmlspecialchars($cat['name']) ?>
      <span class="cat-pill-count"><?= $cat['item_count'] ?></span>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <?php if(empty($items)): ?>
  <div class="empty-shop">
    <span class="material-symbols-outlined">storefront</span>
    <div class="empty-shop-title">No items yet</div>
    <p class="empty-shop-sub">This pawnshop hasn't listed any items yet. Check back soon!</p>
  </div>
  <?php else: ?>
  <div class="items-grid" id="itemsGrid">
    <?php foreach($items as $item):
      $item_promo_data = $item_promo_map[(int)$item['id']] ?? null;
      $on_sale = $item_promo_data !== null;
      $orig_price = $on_sale ? (float)($item['promo_original_price'] ?? $item_promo_data['original_price'] ?? 0) : 0;
      $sale_disc  = $on_sale ? (float)($item_promo_data['discount_pct'] ?? 0) : 0;
    ?>
    <a href="#"
       class="item-card"
       data-cat="<?= (int)($item['category_id'] ?? 0) ?>"
       onclick="openItem(<?= htmlspecialchars(json_encode($item + ['on_sale'=>$on_sale,'orig_price'=>$orig_price,'sale_disc'=>$sale_disc]), ENT_QUOTES) ?>); return false;">
      <div class="item-img-wrap">
        <?php if(!empty($item['item_photo_path'])): ?>
          <img src="<?= htmlspecialchars($item['item_photo_path']) ?>" alt="<?= htmlspecialchars($item['item_name']??'') ?>" loading="lazy">
        <?php else: ?>
          <div class="item-img-placeholder">
            <span class="material-symbols-outlined">diamond</span>
          </div>
        <?php endif; ?>
        <?php if($on_sale && $sale_disc > 0): ?>
          <div class="item-sale-badge"><?= $sale_disc ?>% OFF</div>
        <?php elseif((int)$item['is_featured']): ?>
          <div class="item-featured-badge">Featured</div>
        <?php endif; ?>
        <?php if($item['cat_name']): ?>
          <div class="item-cat-badge"><?= htmlspecialchars($item['cat_name']) ?></div>
        <?php endif; ?>
      </div>
      <div class="item-body">
        <div class="item-name"><?= htmlspecialchars($item['item_name'] ?? 'Item') ?></div>
        <?php if(!empty($item['condition_notes'])): ?>
        <div class="item-cond">Condition: <?= htmlspecialchars($item['condition_notes']) ?></div>        <?php endif; ?>
        <div class="item-footer">
          <div>
            <?php if($on_sale && $orig_price > 0): ?>
              <div class="item-price-label sale-price-label">Sale Price</div>
              <div class="item-price sale-price">₱<?= number_format($item['display_price'], 2) ?></div>
              <div class="item-orig-price">₱<?= number_format($orig_price, 2) ?></div>
            <?php else: ?>
              <div class="item-price-label">Price</div>
              <div class="item-price">₱<?= number_format($item['display_price'], 2) ?></div>
            <?php endif; ?>
          </div>
          <div class="item-stock">
            <?= $item['stock_qty'] ?> <?= $item['stock_qty'] == 1 ? 'piece' : 'pieces' ?> available
          </div>
        </div>
      </div>
    </a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</section>


<!-- ══════════════════════════════════════════════════════════ -->
<!-- CTA -->

<!-- DOWNLOAD APP SECTION -->
<section id="mobile-app" style="padding-top:0;">
  <div style="
    background: linear-gradient(135deg, #0d1120 0%, #0f172a 50%, #0d1120 100%);
    border: 1px solid rgba(255,255,255,.08);
    border-radius: 28px;
    padding: 48px clamp(24px,5vw,60px);
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 48px;
    align-items: center;
    position: relative;
    overflow: hidden;
  ">
    <!-- Glow blobs -->
    <div style="position:absolute;top:-80px;right:-80px;width:320px;height:320px;border-radius:50%;background:radial-gradient(circle,color-mix(in srgb,var(--primary) 18%,transparent),transparent 70%);pointer-events:none;"></div>
    <div style="position:absolute;bottom:-60px;left:-60px;width:260px;height:260px;border-radius:50%;background:radial-gradient(circle,color-mix(in srgb,var(--accent) 10%,transparent),transparent 70%);pointer-events:none;"></div>

    <!-- LEFT: Text content -->
    <div style="position:relative;z-index:1;">
      <div style="display:inline-flex;align-items:center;gap:7px;font-size:.7rem;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:color-mix(in srgb,var(--primary) 90%,#fff);background:color-mix(in srgb,var(--primary) 12%,transparent);border:1px solid color-mix(in srgb,var(--primary) 25%,transparent);padding:5px 14px;border-radius:100px;margin-bottom:20px;">
        <span class="material-symbols-outlined" style="font-size:13px;font-variation-settings:'FILL' 1,'wght' 400,'GRAD' 0,'opsz' 24;">smartphone</span>
        Mobile App
      </div>
      <h2 style="font-family:'DM Serif Display',serif;font-size:clamp(1.8rem,4vw,2.8rem);color:#fff;line-height:1.1;letter-spacing:-.03em;margin-bottom:16px;">
        Manage Your Pawn<br><span style="color:var(--primary);font-style:italic;">From Your Pocket</span>
      </h2>
      <p style="font-size:clamp(.88rem,1.5vw,1rem);color:rgba(240,242,247,.6);line-height:1.75;max-width:400px;margin-bottom:30px;">
        Download the <?= $biz_name ?> app to track your pawn transactions, receive real-time status alerts, and manage your items with a single tap.
      </p>
      <div style="display:flex;flex-direction:column;gap:12px;align-items:flex-start;">
        <a href="<?= htmlspecialchars($login_url) ?>" style="display:inline-flex;align-items:center;gap:12px;background:var(--primary);color:#000;text-decoration:none;padding:14px 24px;border-radius:14px;font-weight:700;font-size:.92rem;box-shadow:0 6px 24px color-mix(in srgb,var(--primary) 40%,transparent);transition:all .22s;border:none;" onmouseover="this.style.transform='translateY(-2px)';this.style.filter='brightness(1.1)'" onmouseout="this.style.transform='';this.style.filter=''">
          <div style="width:34px;height:34px;background:rgba(0,0,0,.15);border-radius:8px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
            <span class="material-symbols-outlined" style="font-size:18px;font-variation-settings:'FILL' 1,'wght' 400,'GRAD' 0,'opsz' 24;">download</span>
          </div>
          <div>
            <div style="font-size:.65rem;font-weight:600;opacity:.7;letter-spacing:.06em;text-transform:uppercase;line-height:1;">Access Our Mobile App</div>
            <div style="font-size:1rem;font-weight:800;line-height:1.3;">Download Here</div>
          </div>
        </a>
        <div style="display:flex;align-items:center;gap:8px;">
          <span class="material-symbols-outlined" style="font-size:15px;color:var(--accent);font-variation-settings:'FILL' 1,'wght' 400,'GRAD' 0,'opsz' 24;">check_circle</span>
          <span style="font-size:.78rem;color:rgba(240,242,247,.45);">Free · No credit card required</span>
        </div>
        <?php if(!empty($access_code)): ?>
        <!-- Access Code -->
        <div style="background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.12);border-radius:14px;padding:14px 18px;margin-top:4px;max-width:320px;">
          <div style="font-size:.7rem;color:rgba(240,242,247,.5);margin-bottom:8px;line-height:1.6;">
            📱 Use this <strong style="color:rgba(240,242,247,.8);">Branch Access Code</strong> when signing up on the mobile app to link your account to <strong style="color:rgba(240,242,247,.8);"><?= htmlspecialchars($biz_name) ?></strong>.
          </div>
          <div style="display:flex;align-items:center;gap:9px;">
            <div>
              <div style="font-size:.58rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:rgba(240,242,247,.35);margin-bottom:3px;">Branch Access Code</div>
              <div style="display:flex;align-items:center;gap:9px;">
                <span id="accessCodeText" style="font-size:1.15rem;font-weight:800;letter-spacing:.18em;color:#fff;font-family:monospace;"><?= htmlspecialchars($access_code) ?></span>
                <button onclick="copyAccessCode()" title="Copy code" style="width:28px;height:28px;border-radius:7px;background:rgba(255,255,255,.1);border:1px solid rgba(255,255,255,.15);cursor:pointer;display:flex;align-items:center;justify-content:center;color:rgba(255,255,255,.6);transition:all .18s;flex-shrink:0;" onmouseover="this.style.background='rgba(255,255,255,.18)';this.style.color='#fff'" onmouseout="this.style.background='rgba(255,255,255,.1)';this.style.color='rgba(255,255,255,.6)'">
                  <span class="material-symbols-outlined" id="copyIcon" style="font-size:14px;">content_copy</span>
                </button>
              </div>
            </div>
          </div>
        </div>
        <script>
        function copyAccessCode() {
          const code = document.getElementById('accessCodeText').textContent.trim();
          const icon = document.getElementById('copyIcon');
          navigator.clipboard.writeText(code).then(() => {
            icon.textContent = 'check';
            icon.style.color = '#6ee7b7';
            setTimeout(() => { icon.textContent = 'content_copy'; icon.style.color = ''; }, 2000);
          }).catch(() => {
            // fallback
            const ta = document.createElement('textarea');
            ta.value = code; document.body.appendChild(ta); ta.select(); document.execCommand('copy'); document.body.removeChild(ta);
            icon.textContent = 'check'; icon.style.color = '#6ee7b7';
            setTimeout(() => { icon.textContent = 'content_copy'; icon.style.color = ''; }, 2000);
          });
        }
        </script>
        <?php endif; ?>
      </div>
    </div>

    <!-- RIGHT: Phone mockup with service status -->
    <div style="position:relative;z-index:1;display:flex;justify-content:center;">
      <div style="width:100%;max-width:280px;background:linear-gradient(145deg,#1a1f2e,#141824);border:1px solid rgba(255,255,255,.12);border-radius:24px;overflow:hidden;box-shadow:0 24px 60px rgba(0,0,0,.6),0 0 0 1px rgba(255,255,255,.05);padding:0 0 4px;">
        <!-- Status bar -->
        <div style="background:linear-gradient(135deg,var(--secondary),var(--primary));padding:14px 18px 12px;display:flex;align-items:center;gap:10px;">
          <div style="width:32px;height:32px;border-radius:9px;background:rgba(255,255,255,.2);display:flex;align-items:center;justify-content:center;flex-shrink:0;overflow:hidden;">
            <?php if($logo_url): ?>
              <img src="<?= htmlspecialchars($logo_url) ?>" style="width:100%;height:100%;object-fit:cover;" alt="logo">
            <?php else: ?>
              <span class="material-symbols-outlined" style="color:#fff;font-size:16px;font-variation-settings:'FILL' 1,'wght' 400,'GRAD' 0,'opsz' 24;">storefront</span>
            <?php endif; ?>
          </div>
          <div>
            <div style="font-size:.75rem;font-weight:700;color:#fff;line-height:1.2;"><?= $biz_name ?></div>
            <div style="font-size:.62rem;color:rgba(255,255,255,.6);">My Account Overview</div>
          </div>
          <span class="material-symbols-outlined" style="color:rgba(255,255,255,.5);font-size:18px;margin-left:auto;">notifications</span>
        </div>
        <!-- Content area -->
        <div style="padding:16px;">
          <div style="font-size:.6rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:color-mix(in srgb,var(--primary) 90%,#fff);margin-bottom:12px;">Transaction Status</div>

          <!-- Status row 1 -->
          <div style="margin-bottom:14px;">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:5px;">
              <span style="font-size:.78rem;font-weight:600;color:#fff;">Active Pawns</span>
              <span style="font-size:.7rem;font-weight:700;color:var(--accent);">Stored</span>
            </div>
            <div style="height:6px;background:rgba(255,255,255,.08);border-radius:100px;overflow:hidden;">
              <div style="height:100%;width:85%;background:linear-gradient(90deg,var(--accent),color-mix(in srgb,var(--accent) 70%,#fff));border-radius:100px;"></div>
            </div>
          </div>

          <!-- Status row 2 -->
          <div style="margin-bottom:14px;">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:5px;">
              <span style="font-size:.78rem;font-weight:600;color:#fff;">Due for Renewal</span>
              <span style="font-size:.7rem;font-weight:700;color:#f59e0b;">Attention</span>
            </div>
            <div style="height:6px;background:rgba(255,255,255,.08);border-radius:100px;overflow:hidden;">
              <div style="height:100%;width:35%;background:linear-gradient(90deg,#f59e0b,#fbbf24);border-radius:100px;"></div>
            </div>
          </div>

          <!-- Status row 3 -->
          <div style="margin-bottom:18px;">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:5px;">
              <span style="font-size:.78rem;font-weight:600;color:#fff;">Redeemed Items</span>
              <span style="font-size:.7rem;font-weight:700;color:rgba(240,242,247,.4);">Complete</span>
            </div>
            <div style="height:6px;background:rgba(255,255,255,.08);border-radius:100px;overflow:hidden;">
              <div style="height:100%;width:100%;background:linear-gradient(90deg,rgba(255,255,255,.15),rgba(255,255,255,.25));border-radius:100px;"></div>
            </div>
          </div>

          <!-- CTA inside card -->
          <a href="<?= htmlspecialchars($login_url) ?>" style="display:flex;align-items:center;justify-content:center;gap:7px;width:100%;padding:11px;background:var(--primary);color:#fff;text-decoration:none;font-size:.8rem;font-weight:700;border-radius:10px;box-shadow:0 4px 14px color-mix(in srgb,var(--primary) 40%,transparent);transition:filter .2s;" onmouseover="this.style.filter='brightness(1.1)'" onmouseout="this.style.filter=''">
            <span class="material-symbols-outlined" style="font-size:15px;font-variation-settings:'FILL' 1,'wght' 400,'GRAD' 0,'opsz' 24;">receipt_long</span>
            View Full Transactions
          </a>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- ══════════════════════════════════════════════════════════ -->
<!-- HOW PAWNING WORKS                                          -->
<!-- ══════════════════════════════════════════════════════════ -->
<section id="how-it-works" style="padding-top:0;">
  <div class="section-hdr">
    <div>
      <div class="section-label">📖 Simple Steps</div>
      <h2 class="section-title">How Pawning Works</h2>
    </div>
  </div>
  <p style="font-size:1.05rem;color:var(--text-m);margin-bottom:36px;max-width:600px;line-height:1.75;">
    Get cash fast — just bring your item and a valid ID. No credit check. No lengthy application.
  </p>

  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(min(100%,220px),1fr));gap:20px;">

    <!-- Step 1 -->
    <div class="section-card" style="background:var(--surface);border:1px solid var(--border);border-radius:20px;padding:28px 24px;transition:transform .2s;" onmouseover="this.style.transform='translateY(-4px)'" onmouseout="this.style.transform=''">
      <div style="width:52px;height:52px;border-radius:16px;background:color-mix(in srgb,var(--primary) 18%,transparent);border:1.5px solid color-mix(in srgb,var(--primary) 35%,transparent);display:flex;align-items:center;justify-content:center;margin-bottom:18px;">
        <span class="material-symbols-outlined" style="font-size:26px;color:var(--primary);font-variation-settings:'FILL' 1,'wght' 400,'GRAD' 0,'opsz' 24;">diamond</span>
      </div>
      <div style="font-size:.7rem;font-weight:800;text-transform:uppercase;letter-spacing:.1em;color:var(--primary);margin-bottom:6px;">Step 1</div>
      <div style="font-size:1.1rem;font-weight:700;color:var(--text);margin-bottom:8px;line-height:1.3;">Bring Your Item</div>
      <div style="font-size:.97rem;color:var(--text-m);line-height:1.75;">Bring your jewelry, gadget, watch, or other valuables to our branch. Bring a valid government ID.</div>
    </div>

    <!-- Step 2 -->
    <div class="section-card" style="background:var(--surface);border:1px solid var(--border);border-radius:20px;padding:28px 24px;transition:transform .2s;" onmouseover="this.style.transform='translateY(-4px)'" onmouseout="this.style.transform=''">
      <div style="width:52px;height:52px;border-radius:16px;background:color-mix(in srgb,var(--accent) 18%,transparent);border:1.5px solid color-mix(in srgb,var(--accent) 35%,transparent);display:flex;align-items:center;justify-content:center;margin-bottom:18px;">
        <span class="material-symbols-outlined" style="font-size:26px;color:var(--accent);font-variation-settings:'FILL' 1,'wght' 400,'GRAD' 0,'opsz' 24;">fact_check</span>
      </div>
      <div style="font-size:.7rem;font-weight:800;text-transform:uppercase;letter-spacing:.1em;color:var(--accent);margin-bottom:6px;">Step 2</div>
      <div style="font-size:1.1rem;font-weight:700;color:var(--text);margin-bottom:8px;line-height:1.3;">Item Appraisal</div>
      <div style="font-size:.97rem;color:var(--text-m);line-height:1.75;">Our appraiser will assess your item and give you a fair loan offer — usually done within minutes.</div>
    </div>

    <!-- Step 3 -->
    <div class="section-card" style="background:var(--surface);border:1px solid var(--border);border-radius:20px;padding:28px 24px;transition:transform .2s;" onmouseover="this.style.transform='translateY(-4px)'" onmouseout="this.style.transform=''">
      <div style="width:52px;height:52px;border-radius:16px;background:rgba(245,158,11,.15);border:1.5px solid rgba(245,158,11,.35);display:flex;align-items:center;justify-content:center;margin-bottom:18px;">
        <span class="material-symbols-outlined" style="font-size:26px;color:#f59e0b;font-variation-settings:'FILL' 1,'wght' 400,'GRAD' 0,'opsz' 24;">payments</span>
      </div>
      <div style="font-size:.7rem;font-weight:800;text-transform:uppercase;letter-spacing:.1em;color:#f59e0b;margin-bottom:6px;">Step 3</div>
      <div style="font-size:1.1rem;font-weight:700;color:var(--text);margin-bottom:8px;line-height:1.3;">Receive Your Cash</div>
      <div style="font-size:.97rem;color:var(--text-m);line-height:1.75;">Once you agree to the loan amount, you receive cash on the spot. You will also get a pawn ticket.</div>
    </div>

    <!-- Step 4 -->
    <div class="section-card" style="background:var(--surface);border:1px solid var(--border);border-radius:20px;padding:28px 24px;transition:transform .2s;" onmouseover="this.style.transform='translateY(-4px)'" onmouseout="this.style.transform=''">
      <div style="width:52px;height:52px;border-radius:16px;background:rgba(34,197,94,.15);border:1.5px solid rgba(34,197,94,.35);display:flex;align-items:center;justify-content:center;margin-bottom:18px;">
        <span class="material-symbols-outlined" style="font-size:26px;color:#22c55e;font-variation-settings:'FILL' 1,'wght' 400,'GRAD' 0,'opsz' 24;">redeem</span>
      </div>
      <div style="font-size:.7rem;font-weight:800;text-transform:uppercase;letter-spacing:.1em;color:#22c55e;margin-bottom:6px;">Step 4</div>
      <div style="font-size:1.1rem;font-weight:700;color:var(--text);margin-bottom:8px;line-height:1.3;">Redeem Your Item</div>
      <div style="font-size:.97rem;color:var(--text-m);line-height:1.75;">Pay back the loan plus interest before your pawn ticket expires to get your item back. You may also renew.</div>
    </div>

  </div>

  <!-- Important note -->
  <div class="section-note" style="margin-top:24px;background:color-mix(in srgb,var(--primary) 8%,transparent);border:1.5px solid color-mix(in srgb,var(--primary) 25%,transparent);border-radius:16px;padding:20px 24px;display:flex;align-items:flex-start;gap:14px;">
    <span class="material-symbols-outlined" style="font-size:24px;color:var(--primary);flex-shrink:0;margin-top:2px;font-variation-settings:'FILL' 1,'wght' 400,'GRAD' 0,'opsz' 24;">info</span>
    <div style="font-size:.98rem;color:var(--text-m);line-height:1.75;">
      <strong style="color:var(--text);">Reminder:</strong> Please bring a valid government-issued ID — SSS, GSIS, Passport, Driver's License, or Voter's ID. Your pawned items are kept safely in our secured storage for the full duration of your loan.
    </div>
  </div>
</section>

<!-- ══════════════════════════════════════════════════════════ -->
<!-- OUR SERVICES                                               -->
<!-- ══════════════════════════════════════════════════════════ -->
<section id="services" style="padding-top:0;">
  <div class="section-hdr">
    <div>
      <div class="section-label">🏪 What We Offer</div>
      <h2 class="section-title">Our Services</h2>
    </div>
  </div>

  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(min(100%,300px),1fr));gap:20px;">

    <!-- Pawn a Loan -->
    <div class="section-card" style="background:linear-gradient(135deg,color-mix(in srgb,var(--primary) 15%,transparent),color-mix(in srgb,var(--primary) 5%,transparent));border:1.5px solid color-mix(in srgb,var(--primary) 30%,transparent);border-radius:22px;padding:32px 28px;transition:transform .2s;" onmouseover="this.style.transform='translateY(-4px)'" onmouseout="this.style.transform=''">
      <div style="font-size:2.8rem;margin-bottom:14px;">💍</div>
      <div style="font-size:1.25rem;font-weight:800;color:var(--text);margin-bottom:10px;">Pawn a Loan</div>
      <div style="font-size:1rem;color:var(--text-m);line-height:1.75;margin-bottom:18px;">Use your valuables as collateral and get instant cash. We accept a wide range of items.</div>
      <div style="display:flex;flex-direction:column;gap:9px;">
        <?php foreach(['Gold & Silver Jewelry','Mobile Phones & Tablets','Watches & Timepieces','Laptops & Electronics','Power Tools','Other Valuables'] as $svc): ?>
        <div style="display:flex;align-items:center;gap:10px;font-size:.97rem;color:var(--text-m);">
          <span class="material-symbols-outlined" style="font-size:18px;color:var(--primary);font-variation-settings:'FILL' 1,'wght' 400,'GRAD' 0,'opsz' 24;">check_circle</span>
          <?= $svc ?>
        </div>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- Redeem Your Item -->
    <div class="section-card" style="background:linear-gradient(135deg,rgba(34,197,94,.12),rgba(34,197,94,.04));border:1.5px solid rgba(34,197,94,.28);border-radius:22px;padding:32px 28px;transition:transform .2s;" onmouseover="this.style.transform='translateY(-4px)'" onmouseout="this.style.transform=''">
      <div style="font-size:2.8rem;margin-bottom:14px;">🔓</div>
      <div style="font-size:1.25rem;font-weight:800;color:var(--text);margin-bottom:10px;">Redeem Your Item</div>
      <div style="font-size:1rem;color:var(--text-m);line-height:1.75;margin-bottom:18px;">Reclaim your pawned item by settling your loan plus interest before the ticket expires.</div>
      <div style="display:flex;flex-direction:column;gap:9px;">
        <?php foreach(['Present your pawn ticket','Pay the loan + interest','Receive your item back','Renew your ticket if needed','No hidden charges'] as $svc): ?>
        <div style="display:flex;align-items:center;gap:10px;font-size:.97rem;color:var(--text-m);">
          <span class="material-symbols-outlined" style="font-size:18px;color:#22c55e;font-variation-settings:'FILL' 1,'wght' 400,'GRAD' 0,'opsz' 24;">check_circle</span>
          <?= $svc ?>
        </div>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- Buy Pre-Owned Items -->
    <?php if($total_items > 0): ?>
    <div class="section-card" style="background:linear-gradient(135deg,rgba(245,158,11,.12),rgba(245,158,11,.04));border:1.5px solid rgba(245,158,11,.28);border-radius:22px;padding:32px 28px;transition:transform .2s;" onmouseover="this.style.transform='translateY(-4px)'" onmouseout="this.style.transform=''">
      <div style="font-size:2.8rem;margin-bottom:14px;">🛍️</div>
      <div style="font-size:1.25rem;font-weight:800;color:var(--text);margin-bottom:10px;">Buy Pre-Owned Items</div>
      <div style="font-size:1rem;color:var(--text-m);line-height:1.75;margin-bottom:18px;">Browse our selection of quality second-hand items available for purchase at affordable prices.</div>
      <a href="#shop" style="display:inline-flex;align-items:center;gap:8px;font-size:1rem;font-weight:700;color:#f59e0b;text-decoration:none;background:rgba(245,158,11,.12);border:1.5px solid rgba(245,158,11,.28);padding:13px 22px;border-radius:12px;transition:all .2s;" onmouseover="this.style.background='rgba(245,158,11,.22)'" onmouseout="this.style.background='rgba(245,158,11,.12)'">
        <span class="material-symbols-outlined" style="font-size:20px;font-variation-settings:'FILL' 1,'wght' 400,'GRAD' 0,'opsz' 24;">shopping_bag</span>
        Browse Available Items
      </a>
    </div>
    <?php endif; ?>

  </div>
</section>

<!-- ══════════════════════════════════════════════════════════ -->
<!-- WHY CHOOSE US                                              -->
<!-- ══════════════════════════════════════════════════════════ -->
<section id="why-us" style="padding-top:0;">
  <div class="section-hdr">
    <div>
      <div class="section-label">⭐ Our Promise</div>
      <h2 class="section-title">Why Choose Us</h2>
    </div>
  </div>

  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(min(100%,250px),1fr));gap:18px;">

    <?php
    $why_items = [
      ['🏛️', 'Licensed & Registered', 'We are duly registered with the DTI and local government. All transactions are fully compliant with BSP pawnshop regulations.'],
      ['⚡', 'Fast Cash — No Waiting', 'Walk in, present your item and ID, and leave with cash in as little as 15 minutes.'],
      ['🔒', 'Your Items Are Safe', 'All pawned items are stored in our secured vault with 24/7 CCTV monitoring throughout the loan period.'],
      ['💰', 'Highest Loan Value', 'We offer the best appraised value for your items so you get the most out of every pawn transaction.'],
      ['📄', 'Clear Loan Terms', 'All loan terms, interest rates, and maturity dates are clearly stated on your pawn ticket. No surprises.'],
      ['🤝', 'Friendly Staff', 'Our staff are trained to assist you with patience and respect — whether you are pawning for the first time or the hundredth.'],
    ];
    foreach($why_items as [$icon, $title, $desc]):
    ?>
    <div class="section-card" style="background:var(--surface);border:1px solid var(--border);border-radius:18px;padding:24px 22px;display:flex;gap:16px;align-items:flex-start;transition:transform .2s;" onmouseover="this.style.transform='translateY(-3px)'" onmouseout="this.style.transform=''">
      <div style="font-size:2rem;flex-shrink:0;line-height:1;"><?= $icon ?></div>
      <div>
        <div style="font-size:1.05rem;font-weight:700;color:var(--text);margin-bottom:6px;line-height:1.3;"><?= $title ?></div>
        <div style="font-size:.94rem;color:var(--text-m);line-height:1.75;"><?= $desc ?></div>
      </div>
    </div>
    <?php endforeach; ?>

  </div>
</section>

<!-- ══════════════════════════════════════════════════════════ -->
<!-- VISIT US / CONTACT                                         -->
<!-- ══════════════════════════════════════════════════════════ -->
<?php if($biz_addr || $biz_phone): ?>
<section id="info" style="padding-top:0;">
  <div class="section-hdr">
    <div>
      <div class="section-label">📍 Find Us</div>
      <h2 class="section-title">Visit Our Branch</h2>
    </div>
  </div>

  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(min(100%,280px),1fr));gap:20px;">

    <?php if($biz_addr): ?>
    <div class="info-card" style="background:var(--surface);border:1px solid var(--border);border-radius:20px;padding:28px 26px;display:flex;flex-direction:column;gap:14px;">
      <div style="width:52px;height:52px;border-radius:16px;background:color-mix(in srgb,var(--primary) 15%,transparent);border:1.5px solid color-mix(in srgb,var(--primary) 30%,transparent);display:flex;align-items:center;justify-content:center;">
        <span class="material-symbols-outlined" style="font-size:26px;color:var(--primary);font-variation-settings:'FILL' 1,'wght' 400,'GRAD' 0,'opsz' 24;">location_on</span>
      </div>
      <div>
        <div style="font-size:.8rem;font-weight:700;text-transform:uppercase;letter-spacing:.09em;color:var(--text-dim);margin-bottom:6px;">Branch Address</div>
        <div style="font-size:1.08rem;font-weight:600;color:var(--text);line-height:1.6;"><?= $biz_addr ?></div>
      </div>
      <a href="https://maps.google.com?q=<?= urlencode($tenant['address'] ?? '') ?>" target="_blank" rel="noopener"
        style="display:inline-flex;align-items:center;gap:7px;font-size:.97rem;font-weight:700;color:var(--primary);text-decoration:none;background:color-mix(in srgb,var(--primary) 10%,transparent);border:1.5px solid color-mix(in srgb,var(--primary) 25%,transparent);padding:12px 18px;border-radius:12px;transition:all .2s;" onmouseover="this.style.background='color-mix(in srgb,var(--primary) 18%,transparent)'" onmouseout="this.style.background='color-mix(in srgb,var(--primary) 10%,transparent)'">
        <span class="material-symbols-outlined" style="font-size:19px;font-variation-settings:'FILL' 1,'wght' 400,'GRAD' 0,'opsz' 24;">near_me</span>
        Get Directions
      </a>
    </div>
    <?php endif; ?>

    <?php if($biz_phone): ?>
    <div class="info-card" style="background:var(--surface);border:1px solid var(--border);border-radius:20px;padding:28px 26px;display:flex;flex-direction:column;gap:14px;">
      <div style="width:52px;height:52px;border-radius:16px;background:rgba(34,197,94,.12);border:1.5px solid rgba(34,197,94,.28);display:flex;align-items:center;justify-content:center;">
        <span class="material-symbols-outlined" style="font-size:26px;color:#22c55e;font-variation-settings:'FILL' 1,'wght' 400,'GRAD' 0,'opsz' 24;">phone</span>
      </div>
      <div>
        <div style="font-size:.8rem;font-weight:700;text-transform:uppercase;letter-spacing:.09em;color:var(--text-dim);margin-bottom:6px;">Contact Number</div>
        <div style="font-size:1.4rem;font-weight:700;color:var(--text);letter-spacing:.02em;"><?= $biz_phone ?></div>
      </div>
      <a href="tel:<?= preg_replace('/[^0-9+]/','',$tenant['phone'] ?? '') ?>"
        style="display:inline-flex;align-items:center;gap:7px;font-size:.97rem;font-weight:700;color:#22c55e;text-decoration:none;background:rgba(34,197,94,.10);border:1.5px solid rgba(34,197,94,.25);padding:12px 18px;border-radius:12px;transition:all .2s;" onmouseover="this.style.background='rgba(34,197,94,.18)'" onmouseout="this.style.background='rgba(34,197,94,.10)'">
        <span class="material-symbols-outlined" style="font-size:19px;font-variation-settings:'FILL' 1,'wght' 400,'GRAD' 0,'opsz' 24;">call</span>
        Call Us Now
      </a>
    </div>
    <?php endif; ?>

    <!-- Branch Hours -->
    <div class="info-card" style="background:var(--surface);border:1px solid var(--border);border-radius:20px;padding:28px 26px;">
      <div style="width:52px;height:52px;border-radius:16px;background:rgba(245,158,11,.12);border:1.5px solid rgba(245,158,11,.28);display:flex;align-items:center;justify-content:center;margin-bottom:14px;">
        <span class="material-symbols-outlined" style="font-size:26px;color:#f59e0b;font-variation-settings:'FILL' 1,'wght' 400,'GRAD' 0,'opsz' 24;">schedule</span>
      </div>
      <div style="font-size:.8rem;font-weight:700;text-transform:uppercase;letter-spacing:.09em;color:var(--text-dim);margin-bottom:14px;">Branch Hours</div>
      <div style="display:flex;flex-direction:column;gap:10px;">
        <div style="display:flex;justify-content:space-between;align-items:center;font-size:.98rem;">
          <span style="color:var(--text-m);">Monday – Friday</span>
          <span style="color:var(--text);font-weight:600;">8:00 AM – 5:00 PM</span>
        </div>
        <div style="height:1px;background:var(--border);"></div>
        <div style="display:flex;justify-content:space-between;align-items:center;font-size:.98rem;">
          <span style="color:var(--text-m);">Saturday</span>
          <span style="color:var(--text);font-weight:600;">8:00 AM – 12:00 PM</span>
        </div>
        <div style="height:1px;background:var(--border);"></div>
        <div style="display:flex;justify-content:space-between;align-items:center;font-size:.98rem;">
          <span style="color:var(--text-m);">Sunday & Holidays</span>
          <span style="color:#ef4444;font-weight:700;">Closed</span>
        </div>
      </div>
    </div>

  </div>
</section>
<?php endif; ?>
<section style="padding-top: 0;">
  <div class="cta-banner">
    <!-- Decorative blobs -->
    <div style="position:absolute;top:-60px;left:-60px;width:260px;height:260px;border-radius:50%;background:radial-gradient(circle,rgba(255,255,255,.08),transparent 70%);pointer-events:none;"></div>
    <div style="position:absolute;bottom:-40px;right:-40px;width:200px;height:200px;border-radius:50%;background:radial-gradient(circle,rgba(255,255,255,.06),transparent 70%);pointer-events:none;"></div>

    <div style="position:relative;z-index:1;">
      <!-- Badge -->
      <div style="display:inline-flex;align-items:center;gap:6px;font-size:.68rem;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:rgba(255,255,255,.7);background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.2);padding:5px 14px;border-radius:100px;margin-bottom:18px;">
        <span class="material-symbols-outlined" style="font-size:12px;font-variation-settings:'FILL' 1,'wght' 400,'GRAD' 0,'opsz' 24;">storefront</span>
        <?= htmlspecialchars($biz_name) ?>
      </div>

      <h2 class="cta-banner-title">Ready to Pawn or Redeem?</h2>
      <p class="cta-banner-sub" style="max-width:520px;margin-left:auto;margin-right:auto;">
        Get instant cash for your valuables, redeem your pawned items, or browse our pre-owned shop — all in one place.
      </p>

      <!-- 3 CTA buttons -->
      <div style="display:flex;align-items:center;justify-content:center;gap:12px;flex-wrap:wrap;">
        <!-- Primary: Pawn / Browse Items -->
        <a href="#shop" style="display:inline-flex;align-items:center;gap:9px;background:rgba(255,255,255,1);color:var(--primary);text-decoration:none;padding:13px 24px;border-radius:14px;font-weight:800;font-size:.95rem;box-shadow:0 4px 20px rgba(0,0,0,.2);transition:all .2s;" onmouseover="this.style.transform='translateY(-2px)';this.style.boxShadow='0 8px 28px rgba(0,0,0,.3)'" onmouseout="this.style.transform='';this.style.boxShadow='0 4px 20px rgba(0,0,0,.2)'">
          <span class="material-symbols-outlined" style="font-size:18px;font-variation-settings:'FILL' 1,'wght' 400,'GRAD' 0,'opsz' 24;">shopping_bag</span>
          Browse Items
        </a>
        <!-- Secondary: Sign In to manage transactions -->
        <a href="<?= htmlspecialchars($login_url) ?>" style="display:inline-flex;align-items:center;gap:9px;background:rgba(255,255,255,.15);color:#fff;text-decoration:none;padding:13px 24px;border-radius:14px;font-weight:700;font-size:.95rem;border:1.5px solid rgba(255,255,255,.30);transition:all .2s;" onmouseover="this.style.background='rgba(255,255,255,.22)';this.style.transform='translateY(-2px)'" onmouseout="this.style.background='rgba(255,255,255,.15)';this.style.transform=''">
          <span class="material-symbols-outlined" style="font-size:18px;font-variation-settings:'FILL' 1,'wght' 400,'GRAD' 0,'opsz' 24;">login</span>
          Sign In
        </a>
        <!-- Tertiary: Visit Us -->
        <a href="#info" style="display:inline-flex;align-items:center;gap:9px;background:transparent;color:rgba(255,255,255,.75);text-decoration:none;padding:13px 22px;border-radius:14px;font-weight:700;font-size:.95rem;border:1.5px solid rgba(255,255,255,.18);transition:all .2s;" onmouseover="this.style.background='rgba(255,255,255,.08)';this.style.color='#fff';this.style.transform='translateY(-2px)'" onmouseout="this.style.background='transparent';this.style.color='rgba(255,255,255,.75)';this.style.transform=''">
          <span class="material-symbols-outlined" style="font-size:18px;font-variation-settings:'FILL' 1,'wght' 400,'GRAD' 0,'opsz' 24;">location_on</span>
          Visit Our Branch
        </a>
      </div>

      <!-- Branch info hint -->
      <?php if($biz_addr || $biz_phone): ?>
      <div style="margin-top:22px;display:flex;align-items:center;justify-content:center;gap:18px;flex-wrap:wrap;">
        <?php if($biz_addr): ?>
        <span style="display:inline-flex;align-items:center;gap:6px;font-size:.78rem;color:rgba(255,255,255,.55);">
          <span class="material-symbols-outlined" style="font-size:14px;font-variation-settings:'FILL' 1,'wght' 400,'GRAD' 0,'opsz' 24;">location_on</span>
          <?= htmlspecialchars($biz_addr) ?>
        </span>
        <?php endif; ?>
        <?php if($biz_phone): ?>
        <span style="display:inline-flex;align-items:center;gap:6px;font-size:.78rem;color:rgba(255,255,255,.55);">
          <span class="material-symbols-outlined" style="font-size:14px;font-variation-settings:'FILL' 1,'wght' 400,'GRAD' 0,'opsz' 24;">phone</span>
          <?= htmlspecialchars($biz_phone) ?>
        </span>
        <?php endif; ?>
      </div>
      <?php endif; ?>
    </div>
  </div>
</section>

<!-- FOOTER -->
<footer>
  <div class="footer-brand">
    <div class="footer-logo">
      <?php if($logo_url): ?>
        <img src="<?= htmlspecialchars($logo_url) ?>" alt="logo">
      <?php else: ?>
        <svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2.5"><rect x="3" y="9" width="18" height="12"/><polyline points="3 9 12 3 21 9"/></svg>
      <?php endif; ?>
    </div>
    <span class="footer-name"><?= $biz_name ?></span>
  </div>
  <div class="footer-meta">Powered by PawnHub · © <?= date('Y') ?></div>
  <a href="<?= htmlspecialchars($login_url) ?>" class="footer-signin">
    <span class="material-symbols-outlined" style="font-size:15px;">login</span>Sign In
  </a>
</footer>

<!-- ITEM DETAIL MODAL -->
<div class="modal-overlay" id="itemModal" onclick="if(event.target===this)closeItem()">
  <div class="modal-box item-detail-modal modal-box-wrap" id="itemModalBox">
    <button class="modal-close-x" onclick="closeItem()">
      <span class="material-symbols-outlined" style="font-size:17px;">close</span>
    </button>
    <div style="padding:24px;">
      <div id="modalContent"></div>
      <a href="<?= htmlspecialchars($login_url) ?>" class="modal-btn">
        <span class="material-symbols-outlined">login</span>Sign In
      </a>
      <button class="modal-btn secondary" onclick="closeItem()">Close</button>
    </div>
  </div>
</div>

<script>
// ── Category Filter ─────────────────────────────────────────
function filterItems(catId, el) {
  document.querySelectorAll('.cat-pill').forEach(p => p.classList.remove('active'));
  el.classList.add('active');
  document.querySelectorAll('#itemsGrid .item-card').forEach(card => {
    if (catId === 'all' || card.dataset.cat === catId) {
      card.classList.remove('hidden');
      card.style.display = '';
    } else {
      card.style.display = 'none';
    }
  });
}

// ── Item Detail Modal ───────────────────────────────────────
function openItem(item) {
  const photo = item.item_photo_path
    ? `<img class="item-detail-img" src="${item.item_photo_path}" alt="${escHtml(item.item_name||'')}">`
    : `<div class="item-detail-img-placeholder"><span class="material-symbols-outlined">diamond</span></div>`;

  const badges = [];
  if (item.cat_name) badges.push(`<span class="item-detail-badge"><span class="material-symbols-outlined" style="font-size:13px;">category</span>${escHtml(item.cat_name)}</span>`);
  if (item.condition_notes) badges.push(`<span class="item-detail-badge"><span class="material-symbols-outlined" style="font-size:13px;">info</span>${escHtml(item.condition_notes)}</span>`);
  if (item.stock_qty) badges.push(`<span class="item-detail-badge"><span class="material-symbols-outlined" style="font-size:13px;">inventory_2</span>${item.stock_qty} in stock</span>`);
  if (item.on_sale) badges.push(`<span class="item-detail-badge" style="background:rgba(239,68,68,.12);border-color:rgba(239,68,68,.3);color:#dc2626;">🔖 On Sale</span>`);
  else if (item.is_featured == 1) badges.push(`<span class="item-detail-badge" style="background:rgba(245,158,11,.15);border-color:rgba(245,158,11,.3);color:#fcd34d;">⭐ Featured</span>`);

  const priceHtml = item.on_sale && item.orig_price > 0
    ? `<div style="display:flex;align-items:baseline;gap:10px;flex-wrap:wrap;">
         <div class="item-detail-price sale-price">₱${parseFloat(item.display_price).toLocaleString('en-PH',{minimumFractionDigits:2})}</div>
         <div class="item-orig-price" style="font-size:1rem;">₱${parseFloat(item.orig_price).toLocaleString('en-PH',{minimumFractionDigits:2})}</div>
         <div style="font-size:.72rem;font-weight:800;background:rgba(239,68,68,.12);color:#dc2626;border:1px solid rgba(239,68,68,.25);padding:2px 9px;border-radius:100px;">${item.sale_disc}% OFF</div>
       </div>`
    : `<div class="item-detail-price">₱${parseFloat(item.display_price).toLocaleString('en-PH',{minimumFractionDigits:2})}</div>`;

  document.getElementById('modalContent').innerHTML = `
    ${photo}
    <div class="item-detail-name">${escHtml(item.item_name || 'Item')}</div>
    ${priceHtml}
    <div class="item-detail-meta">${badges.join('')}</div>
  `;
  document.getElementById('itemModal').classList.add('open');
  document.body.style.overflow = 'hidden';
}

function closeItem() {
  document.getElementById('itemModal').classList.remove('open');
  document.body.style.overflow = '';
}

function escHtml(str) {
  if (!str) return '';
  return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

document.addEventListener('keydown', e => { if (e.key === 'Escape') closeItem(); });

// ── Dark Mode Toggle ────────────────────────────────────────
(function() {
  const body  = document.body;
  const icon  = document.getElementById('dmIcon');
  const STORE = 'th_<?= addslashes($slug) ?>_dm';
  const hasBg = <?= $has_bg ? 'true' : 'false' ?>;

  function syncIcon() {
    if (!icon) return;
    icon.textContent = body.dataset.theme === 'dark' ? 'light_mode' : 'dark_mode';
  }

  function applyTheme(dark) {
    body.dataset.theme = dark ? 'dark' : 'light';
    if (hasBg) {
      body.classList.add('has-bg-img');
    }
    syncIcon();
  }

  // On load: check localStorage for saved preference
  const saved = localStorage.getItem(STORE);
  if (saved !== null) {
    applyTheme(saved === '1');
  } else {
    syncIcon();
  }

  window.toggleDarkMode = function() {
    const isDark = body.dataset.theme === 'dark';
    applyTheme(!isDark);
    localStorage.setItem(STORE, isDark ? '0' : '1');
  };
})();
</script>
</body>
</html>