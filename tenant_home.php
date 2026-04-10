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
// Shop background: query shop_bg_url directly from DB, fallback to bg_image_url
try {
    $sbq = $pdo->prepare("SELECT shop_bg_url FROM tenant_settings WHERE tenant_id=? LIMIT 1");
    $sbq->execute([$tid]);
    $shop_bg_raw = $sbq->fetchColumn() ?: '';
} catch (Throwable $e) { $shop_bg_raw = ''; }
if (!$shop_bg_raw) $shop_bg_raw = $tenant['bg_image_url'] ?? '';
$bg_url = $shop_bg_raw;
// Normalize local upload paths (fix old records without leading slash)
if ($bg_url   && strpos($bg_url,  'http') !== 0 && $bg_url[0]   !== '/') $bg_url   = '/' . $bg_url;
if ($logo_url && strpos($logo_url,'http') !== 0 && $logo_url[0] !== '/') $logo_url = '/' . $logo_url;

$biz_name  = htmlspecialchars($tenant['business_name']);

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
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $biz_name ?> — Shop</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&family=DM+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet">
<style>
:root {
  --primary: <?= $primary ?>;
  --secondary: <?= $secondary ?>;
  --accent: <?= $accent ?>;
  --bg: #08090c;
  --surface: rgba(255,255,255,0.04);
  --surface-2: rgba(255,255,255,0.07);
  --border: rgba(255,255,255,0.08);
  --text: #f0f2f7;
  --text-m: rgba(240,242,247,0.6);
  --text-dim: rgba(240,242,247,0.3);
  --radius: 16px;
  --nav-h: 68px;
}

*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

html { scroll-behavior: smooth; }

body {
  font-family: 'DM Sans', sans-serif;
  background: var(--bg);
  color: var(--text);
  min-height: 100vh;
  overflow-x: hidden;
}

.material-symbols-outlined {
  font-variation-settings: 'FILL' 0, 'wght' 400, 'GRAD' 0, 'opsz' 24;
}

/* ── BACKGROUND ── */
.bg-scene {
  position: fixed; inset: 0; z-index: 0; pointer-events: none;
}
.bg-scene-img {
  width: 100%; height: 100%; object-fit: cover;
  opacity: .55; filter: saturate(0.75) brightness(0.65);
}
.bg-gradient {
  position: absolute; inset: 0;
  background: radial-gradient(ellipse 80% 60% at 50% -10%, color-mix(in srgb, var(--primary) 15%, transparent), transparent 70%),
              linear-gradient(to bottom, rgba(8,9,12,0.05) 0%, rgba(8,9,12,0.55) 45%, #08090c 82%);
}

/* ── NAV ── */
nav {
  position: fixed; top: 0; left: 0; right: 0; z-index: 100;
  height: var(--nav-h);
  display: flex; align-items: center; justify-content: space-between;
  padding: 0 clamp(16px, 4vw, 48px);
  background: rgba(8,9,12,0.7);
  backdrop-filter: blur(20px);
  border-bottom: 1px solid var(--border);
  transition: background .3s;
}
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
  font-size: 1.2rem;
  color: #fff;
  letter-spacing: -.01em;
}
.nav-links {
  display: flex; align-items: center; gap: 6px;
}
.nav-link {
  font-size: .85rem; font-weight: 500;
  color: var(--text-m); text-decoration: none;
  padding: 7px 14px; border-radius: 10px;
  transition: all .18s;
}
.nav-link:hover { color: #fff; background: var(--surface-2); }
.nav-signin {
  display: flex; align-items: center; gap: 7px;
  font-size: .88rem; font-weight: 700;
  color: #fff; text-decoration: none;
  padding: 9px 20px; border-radius: 12px;
  background: var(--primary);
  box-shadow: 0 4px 18px color-mix(in srgb, var(--primary) 40%, transparent);
  transition: all .2s;
  border: none; cursor: pointer; font-family: inherit;
}
.nav-signin:hover { filter: brightness(1.1); transform: translateY(-1px); }
.nav-signin .material-symbols-outlined { font-size: 17px; }

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
  color: color-mix(in srgb, var(--accent) 90%, #fff);
  background: color-mix(in srgb, var(--accent) 12%, transparent);
  border: 1px solid color-mix(in srgb, var(--accent) 25%, transparent);
  padding: 5px 14px; border-radius: 100px;
  margin-bottom: 20px;
}
.hero-eyebrow .material-symbols-outlined {
  font-size: 13px;
  font-variation-settings: 'FILL' 1, 'wght' 400, 'GRAD' 0, 'opsz' 24;
}
.hero-title {
  font-family: 'DM Serif Display', serif;
  font-size: clamp(2.4rem, 6vw, 4.2rem);
  color: #fff;
  line-height: 1.1;
  letter-spacing: -.03em;
  margin-bottom: 16px;
}
.hero-title .accent { color: var(--primary); font-style: italic; }
.hero-sub {
  font-size: clamp(.9rem, 2vw, 1.1rem);
  color: var(--text-m);
  line-height: 1.7;
  max-width: 480px;
  margin: 0 auto 32px;
}
.hero-actions {
  display: flex; align-items: center; justify-content: center; gap: 12px; flex-wrap: wrap;
}
.btn-hero-primary {
  display: inline-flex; align-items: center; gap: 8px;
  font-size: .95rem; font-weight: 700;
  color: #fff; text-decoration: none;
  padding: 13px 28px; border-radius: 14px;
  background: var(--primary);
  box-shadow: 0 6px 24px color-mix(in srgb, var(--primary) 40%, transparent);
  transition: all .22s;
  font-family: inherit;
}
.btn-hero-primary:hover { transform: translateY(-2px); filter: brightness(1.1); }
.btn-hero-secondary {
  display: inline-flex; align-items: center; gap: 8px;
  font-size: .95rem; font-weight: 600;
  color: var(--text-m); text-decoration: none;
  padding: 13px 24px; border-radius: 14px;
  background: var(--surface); border: 1px solid var(--border);
  transition: all .22s;
}
.btn-hero-secondary:hover { color: #fff; background: var(--surface-2); }
.hero-stats {
  display: flex; align-items: center; justify-content: center; gap: 28px;
  margin-top: 36px; flex-wrap: wrap;
}
.hero-stat { text-align: center; }
.hero-stat-val {
  font-family: 'DM Serif Display', serif;
  font-size: 1.8rem; color: #fff; line-height: 1;
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
  color: #fff; line-height: 1.15;
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
  background: rgba(255,255,255,.04);
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
  background: linear-gradient(135deg, rgba(255,255,255,.03), rgba(255,255,255,.06));
}
.item-img-placeholder .material-symbols-outlined {
  font-size: 52px; color: rgba(255,255,255,.1);
}
.item-featured-badge {
  position: absolute; top: 10px; left: 10px;
  background: linear-gradient(135deg, #f59e0b, #d97706);
  color: #fff; font-size: .62rem; font-weight: 800;
  text-transform: uppercase; letter-spacing: .1em;
  padding: 3px 9px; border-radius: 100px;
  box-shadow: 0 2px 10px rgba(245,158,11,.4);
}
.item-cat-badge {
  position: absolute; top: 10px; right: 10px;
  background: rgba(8,9,12,.75);
  backdrop-filter: blur(8px);
  color: var(--text-m); font-size: .63rem; font-weight: 600;
  padding: 3px 9px; border-radius: 100px;
  border: 1px solid var(--border);
}
.item-body { padding: 14px 16px; flex: 1; display: flex; flex-direction: column; }
.item-name {
  font-size: .9rem; font-weight: 700; color: #fff;
  line-height: 1.35; margin-bottom: 5px;
  display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;
}
.item-cond {
  font-size: .71rem; color: var(--text-dim);
  margin-bottom: 10px;
}
.item-footer {
  display: flex; align-items: center; justify-content: space-between;
  margin-top: auto;
}
.item-price {
  font-family: 'DM Serif Display', serif;
  font-size: 1.25rem; color: #fff;
}
.item-price-label { font-size: .62rem; color: var(--text-dim); font-family: 'DM Sans', sans-serif; font-weight: 500; }
.item-stock {
  font-size: .68rem; font-weight: 600;
  background: color-mix(in srgb, var(--accent) 15%, transparent);
  color: color-mix(in srgb, var(--accent) 90%, #fff);
  border: 1px solid color-mix(in srgb, var(--accent) 25%, transparent);
  padding: 3px 8px; border-radius: 100px;
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
  width: 100%; height: 100%; object-fit: cover; opacity: .55;
  transition: transform .4s, opacity .3s;
}
.featured-card:hover .featured-card-bg img { transform: scale(1.05); opacity: .65; }
.featured-card-bg-grad {
  position: absolute; inset: 0;
  background: linear-gradient(to top, rgba(8,9,12,.95) 0%, rgba(8,9,12,.3) 60%, transparent 100%);
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
  font-size: 1.2rem; color: #fff; line-height: 1.25;
  margin-bottom: 8px;
}
.featured-card-price {
  font-size: 1rem; font-weight: 700; color: #fff;
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
.info-card-val { font-size: 1rem; font-weight: 600; color: #fff; line-height: 1.5; }

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
.empty-shop-title { font-family: 'DM Serif Display', serif; font-size: 1.5rem; color: rgba(255,255,255,.3); margin-bottom: 8px; }
.empty-shop-sub { font-size: .85rem; }

/* ── FOOTER ── */
footer {
  position: relative; z-index: 10;
  background: rgba(255,255,255,.02);
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
.footer-name { font-weight: 700; color: rgba(255,255,255,.6); font-size: .88rem; }
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
  background: #0e1117;
  border: 1px solid var(--border);
  border-radius: 22px; width: 100%; max-width: 440px;
  box-shadow: 0 24px 80px rgba(0,0,0,.7);
  animation: mIn .25s ease both; overflow: hidden;
}
@keyframes mIn { from { opacity:0; transform: translateY(16px) } to { opacity:1; transform:none } }
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
.modal-btn.secondary:hover { background: var(--surface-2); color: #fff; transform: none; }
.modal-close-x {
  position: absolute; top: 16px; right: 16px;
  width: 32px; height: 32px; border-radius: 9px;
  background: rgba(255,255,255,.1); border: none; cursor: pointer;
  display: flex; align-items: center; justify-content: center;
  color: rgba(255,255,255,.6);
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
.item-detail-name { font-family: 'DM Serif Display', serif; font-size: 1.5rem; color: #fff; margin-bottom: 6px; }
.item-detail-price { font-size: 1.6rem; font-weight: 800; color: var(--primary); margin-bottom: 12px; }
.item-detail-meta { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 14px; }
.item-detail-badge {
  display: inline-flex; align-items: center; gap: 5px;
  font-size: .72rem; font-weight: 600; padding: 4px 10px; border-radius: 100px;
  background: var(--surface-2); border: 1px solid var(--border); color: var(--text-m);
}
.item-detail-desc { font-size: .88rem; color: var(--text-m); line-height: 1.65; margin-bottom: 18px; }

/* ── HIDDEN FILTER ── */
.item-card[data-cat].hidden { display: none; }

/* ── RESPONSIVE ── */
@media (max-width: 768px) {
  .nav-links { display: none; }
}
@media (max-width: 480px) {
  .hero-stat-divider { display: none; }
}
</style>
</head>
<body>

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
    <?php if($biz_addr || $biz_phone): ?>
    <a href="#info" class="nav-link">About</a>
    <?php endif; ?>
    <a href="<?= htmlspecialchars($register_url) ?>" class="nav-link" style="color:color-mix(in srgb,var(--accent) 90%,#fff);">Join Us</a>
  </div>

  <div style="display:flex;align-items:center;gap:8px;">
    <a href="<?= htmlspecialchars($register_url) ?>" class="nav-signin" style="background:color-mix(in srgb,var(--accent) 80%,#000);box-shadow:0 4px 18px color-mix(in srgb,var(--accent) 35%,transparent);">
      <span class="material-symbols-outlined">person_add</span>Apply
    </a>
    <a href="<?= htmlspecialchars($login_url) ?>" class="nav-signin">
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
      Browse our available items — jewelry, gadgets, watches, and more.
      <?php if($biz_addr): ?>Visit us at <?= $biz_addr ?>.<?php endif; ?>
    </p>
    <div class="hero-actions">
      <?php if($total_items > 0): ?>
      <a href="#shop" class="btn-hero-primary">
        <span class="material-symbols-outlined">shopping_bag</span>Browse Items
      </a>
      <?php endif; ?>
      <a href="<?= htmlspecialchars($register_url) ?>" class="btn-hero-secondary" style="color:color-mix(in srgb,var(--accent) 90%,#fff);border-color:color-mix(in srgb,var(--accent) 30%,transparent);background:color-mix(in srgb,var(--accent) 10%,transparent);">
        <span class="material-symbols-outlined">person_add</span>Join Our Team
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
    <?php foreach($items as $item): ?>
    <a href="#"
       class="item-card"
       data-cat="<?= (int)($item['category_id'] ?? 0) ?>"
       onclick="openItem(<?= htmlspecialchars(json_encode($item), ENT_QUOTES) ?>); return false;">
      <div class="item-img-wrap">
        <?php if(!empty($item['item_photo_path'])): ?>
          <img src="<?= htmlspecialchars($item['item_photo_path']) ?>" alt="<?= htmlspecialchars($item['item_name']??'') ?>" loading="lazy">
        <?php else: ?>
          <div class="item-img-placeholder">
            <span class="material-symbols-outlined">diamond</span>
          </div>
        <?php endif; ?>
        <?php if((int)$item['is_featured']): ?>
          <div class="item-featured-badge">Featured</div>
        <?php endif; ?>
        <?php if($item['cat_name']): ?>
          <div class="item-cat-badge"><?= htmlspecialchars($item['cat_name']) ?></div>
        <?php endif; ?>
      </div>
      <div class="item-body">
        <div class="item-name"><?= htmlspecialchars($item['item_name'] ?? 'Item') ?></div>
        <?php if(!empty($item['condition_notes'])): ?>
        <div class="item-cond">Condition: <?= htmlspecialchars($item['condition_notes']) ?></div>
        <?php endif; ?>
        <div class="item-footer">
          <div>
            <div class="item-price-label">Price</div>
            <div class="item-price">₱<?= number_format($item['display_price'], 2) ?></div>
          </div>
          <div class="item-stock">
            <?= $item['stock_qty'] ?> in stock
          </div>
        </div>
      </div>
    </a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</section>

<!-- INFO -->
<?php if($biz_addr || $biz_phone): ?>
<section id="info">
  <div class="section-hdr">
    <div>
      <div class="section-label">📍 Find Us</div>
      <h2 class="section-title">Visit Our Branch</h2>
    </div>
  </div>
  <div class="info-grid">
    <?php if($biz_addr): ?>
    <div class="info-card">
      <div class="info-card-icon"><span class="material-symbols-outlined">location_on</span></div>
      <div class="info-card-title">Address</div>
      <div class="info-card-val"><?= $biz_addr ?></div>
    </div>
    <?php endif; ?>
    <?php if($biz_phone): ?>
    <div class="info-card">
      <div class="info-card-icon"><span class="material-symbols-outlined">call</span></div>
      <div class="info-card-title">Contact Number</div>
      <div class="info-card-val"><?= $biz_phone ?></div>
    </div>
    <?php endif; ?>
    <div class="info-card qr-card">
      <div class="info-card-icon"><span class="material-symbols-outlined">qr_code_2</span></div>
      <div class="info-card-title">Scan to Visit</div>
      <div class="qr-wrap">
        <img src="YOUR_QR_CODE_HERE" alt="QR Code" class="qr-img">
        <div class="qr-label">Scan to open our shop</div>
      </div>
    </div>
  </div>
</section>
<?php endif; ?>

<!-- CTA -->
<section style="padding-top: 0;">
  <div class="cta-banner">
    <h2 class="cta-banner-title">Ready to Pawn or Redeem?</h2>
    <p class="cta-banner-sub">
      Visit us in-branch or sign in to manage your transactions.
    </p>
    <div style="display:flex;align-items:center;justify-content:center;gap:12px;flex-wrap:wrap;position:relative;z-index:1;">
      <a href="<?= htmlspecialchars($login_url) ?>" class="btn-hero-primary" style="display:inline-flex;">
        <span class="material-symbols-outlined">login</span>Sign In
      </a>
      <a href="<?= htmlspecialchars($register_url) ?>" class="btn-hero-secondary" style="display:inline-flex;color:rgba(255,255,255,.8);background:rgba(255,255,255,.12);border-color:rgba(255,255,255,.25);">
        <span class="material-symbols-outlined">person_add</span>Join Our Team
      </a>
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
  if (item.is_featured == 1) badges.push(`<span class="item-detail-badge" style="background:rgba(245,158,11,.15);border-color:rgba(245,158,11,.3);color:#fcd34d;">⭐ Featured</span>`);

  document.getElementById('modalContent').innerHTML = `
    ${photo}
    <div class="item-detail-name">${escHtml(item.item_name || 'Item')}</div>
    <div class="item-detail-price">₱${parseFloat(item.display_price).toLocaleString('en-PH', {minimumFractionDigits:2})}</div>
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
</script>
</body>
</html>