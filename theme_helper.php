<?php
/**
 * PawnHub Theme Helper
 * Load tenant custom theme settings (colors + bg image + logo)
 */
function getTenantTheme(PDO $pdo, int $tenant_id): array {
    try {
        // bg_image_url exists in BOTH tables:
        //   tenant_settings.bg_image_url — rarely set directly
        //   tenants.bg_image_url         — the actual uploaded bg file
        // We alias the tenants one to avoid the column clash with ts.*,
        // then prefer tenants.bg_image_url and fall back to tenant_settings.
        $stmt = $pdo->prepare("
            SELECT ts.*,
                   t.bg_image_url AS tenant_bg_image_url
            FROM   tenant_settings ts
            LEFT JOIN tenants t ON t.id = ts.tenant_id
            WHERE  ts.tenant_id = ?
            LIMIT  1
        ");
        $stmt->execute([$tenant_id]);
        $s = $stmt->fetch();
        if ($s) {
            // Merge: tenants.bg_image_url wins (it's where uploads go),
            // fall back to tenant_settings.bg_image_url if tenants has none.
            $s['bg_image_url'] = $s['tenant_bg_image_url'] ?: ($s['bg_image_url'] ?: null);
            return $s;
        }
    } catch (Exception $e) {}

    return [
        'primary_color'   => '#2563eb',
        'secondary_color' => '#1e3a8a',
        'accent_color'    => '#10b981',
        'sidebar_color'   => '#111827',
        'logo_text'       => null,
        'logo_url'        => null,
        'bg_image_url'    => null,
        'shop_bg_url'     => null,
        'hero_title'      => 'Your Trusted',
        'hero_subtitle'   => 'Pawnshop',
        'system_name'     => 'PawnHub',
        'font_style'      => 'Plus Jakarta Sans',
    ];
}
/**
 * Returns the background image URL for dashboards/login.
 * Falls back to a default if none is set.
 */
function getTenantBgImage(array $theme, string $default = ''): string {
    if (!empty($theme['bg_image_url'])) {
        return htmlspecialchars($theme['bg_image_url']);
    }
    return $default;
}

function renderThemeCSS(array $theme): string {
    // Strip any leading # then re-add to normalize; htmlspecialchars is safe for hex colors
    $p   = htmlspecialchars($theme['primary_color']   ?? '#2563eb');
    $s   = htmlspecialchars($theme['secondary_color'] ?? '#1e3a8a');
    $a   = htmlspecialchars($theme['accent_color']    ?? '#10b981');
    $sb  = htmlspecialchars($theme['sidebar_color']   ?? '#ffffff');

    $pDark = adjustColor($p, -20);
    $aDark = adjustColor($a, -20);

    // ── Page background ───────────────────────────────────────────
    // Use secondary if it's dark enough; otherwise fall back to near-black so
    // dashboard content is always readable regardless of tenant's chosen colors.
    $isDarkSecondary = colorIsDark($s);
    $pageBg = $isDarkSecondary
        ? "color-mix(in srgb, {$s} 95%, #0a0a0a)"
        : "#0f172a";

    // ── On-primary contrast colors ────────────────────────────────
    // Used for text/icons ON top of the primary-colored topbar and branch cards.
    // Light primary (e.g. yellow) → dark text; dark primary → white text.
    $isDarkPrimary = colorIsDark($p);
    $onPrimary     = $isDarkPrimary ? '#ffffff' : '#0f172a';
    $onPrimaryMid  = $isDarkPrimary ? 'rgba(255,255,255,.65)' : 'rgba(0,0,0,.55)';
    $onPrimaryDim  = $isDarkPrimary ? 'rgba(255,255,255,.4)'  : 'rgba(0,0,0,.38)';

    // ── Topbar contrast values ────────────────────────────────────
    $topbarText    = $isDarkPrimary ? '#ffffff' : '#0f172a';
    $topbarIcon    = $isDarkPrimary ? 'rgba(255,255,255,.15)' : 'rgba(0,0,0,.12)';
    $topbarIconHov = $isDarkPrimary ? 'rgba(255,255,255,.28)' : 'rgba(0,0,0,.2)';
    $topbarBorder  = $isDarkPrimary ? 'rgba(255,255,255,.2)'  : 'rgba(0,0,0,.15)';
    $chipBg        = $isDarkPrimary ? 'rgba(255,255,255,.18)' : 'rgba(0,0,0,.1)';
    $chipBorder    = $isDarkPrimary ? 'rgba(255,255,255,.35)' : 'rgba(0,0,0,.2)';

    // ── Button text on primary bg ─────────────────────────────────
    // .btn-primary text must contrast against the primary color, not assume white.
    $btnPrimaryText = $isDarkPrimary ? '#ffffff' : '#0f172a';

    // ── Sidebar overrides for dark sidebar colors ─────────────────
    // Force sidebar to always be dark — white sidebar looks broken on dark dashboards.
    // If tenant picks white/light sidebar color, override it to dark neutral.
    if (!colorIsDark($sb)) {
        $sb = '#111827';
    }
    $isDarkSidebar = true; // sidebar is always dark now

    // Blend sidebar color toward secondary for a nice gradient.
    // Since sidebar is always dark, sidebarGradEnd uses secondary color.
    $sidebarGradEnd = colorIsDark($s) ? $s : '#111827';

    $sidebarCSS = '';
    if ($isDarkSidebar) {
        // Active sidebar item should also be readable: use white text not primary
        // color, because primary may be yellow and unreadable on a dark sidebar.
        $sbActiveText = '#ffffff';
        $sbActiveBg   = 'rgba(255,255,255,.15)';

        $sidebarCSS = "
    /* ── Dark sidebar overrides (tenant chose a dark sidebar color) ── */
    .sidebar, aside.sidebar {
        background: linear-gradient(175deg, {$sb}, {$sidebarGradEnd}) !important;
        border-right-color: rgba(255,255,255,.06) !important;
    }
    .sb-brand, .sb-user, .sb-footer { border-color: rgba(255,255,255,.08) !important; }
    .sb-name, .sb-uname, .sb-role-name, .sb-tenant-name { color: #fff !important; }
    .sb-subtitle, .sb-urole, .sb-role-label, .sb-tenant-label,
    .sb-section { color: rgba(255,255,255,.38) !important; }
    .sb-item { color: rgba(255,255,255,.5) !important; }
    .sb-item:hover { background: rgba(255,255,255,.08) !important; color: #fff !important; }
    .sb-item.active {
        background: {$sbActiveBg} !important;
        color: {$sbActiveText} !important;
    }
    .sb-logout { color: rgba(255,255,255,.38) !important; }
    .sb-logout:hover { color: #f87171 !important; background: rgba(239,68,68,.12) !important; }
    .sb-role-card, .sb-tenant-card {
        background: rgba(255,255,255,.06) !important;
        border-color: rgba(255,255,255,.1) !important;
    }
    .sb-role-badge, .sb-tenant-badge {
        background: rgba(255,255,255,.12) !important;
        color: rgba(255,255,255,.8) !important;
    }
    .sb-status { background: rgba(16,185,129,.2) !important; color: #6ee7b7 !important; }";
    }

    return "
    <style id='tenant-theme'>
    :root {
        --t-primary:        {$p};
        --t-primary-d:      {$pDark};
        --t-secondary:      {$s};
        --t-accent:         {$a};
        --t-accent-d:       {$aDark};
        --t-sidebar:        {$sb};
        --t-page-bg:        {$pageBg};
        --t-on-primary:     {$onPrimary};
        --t-on-primary-mid: {$onPrimaryMid};
        --t-on-primary-dim: {$onPrimaryDim};
    }

    /* ── Branch hero / card — text always readable on primary bg ── */
    .branch-hero *, [class*='branch-card'] * { color: inherit !important; }
    .branch-hero [data-label], .branch-hero-label { color: {$onPrimaryDim} !important; }
    .branch-hero-title { color: {$onPrimary} !important; }
    .branch-hero-sub   { color: {$onPrimaryMid} !important; }

    /* Inline branch banners that use --t-secondary in their gradient */
    [style*='linear-gradient'][style*='--t-secondary'],
    [style*='linear-gradient'][style*='--t-primary'] {
        color: {$onPrimary} !important;
    }

    /* ── Logo & primary backgrounds ── */
    .sb-logo, .theme-primary-bg {
        background: linear-gradient(135deg, var(--t-primary), var(--t-secondary)) !important;
    }

    /* ── Buttons ── */
    .btn-primary, .btn-sm.btn-primary {
        background: var(--t-primary) !important;
        border-color: var(--t-primary) !important;
        color: {$btnPrimaryText} !important;
    }
    .btn-primary:hover {
        background: var(--t-primary-d) !important;
        border-color: var(--t-primary-d) !important;
    }

    /* ── Topbar — background, text, and all chips ── */
    .topbar {
        background: linear-gradient(135deg, var(--t-primary), var(--t-secondary)) !important;
        border-bottom-color: color-mix(in srgb, var(--t-secondary) 80%, #000) !important;
    }
    .topbar-title { color: {$topbarText} !important; }
    .topbar-icon {
        background: {$topbarIcon} !important;
        color: {$topbarText} !important;
    }
    .topbar-icon:hover {
        background: {$topbarIconHov} !important;
        color: {$topbarText} !important;
    }
    .topbar-user { border-left-color: {$topbarBorder} !important; }
    .topbar-user-name { color: {$topbarText} !important; }
    .topbar-user-role { color: {$onPrimaryMid} !important; }

    /* All chips/badges inside the topbar */
    .topbar .tenant-chip,
    .topbar .tenant-badge,
    .topbar .cashier-chip,
    .topbar .mgr-chip {
        background: {$chipBg} !important;
        color: {$topbarText} !important;
        border-color: {$chipBorder} !important;
    }

    /* Topbar avatar */
    .topbar-avatar {
        background: linear-gradient(135deg, var(--t-primary), var(--t-secondary)) !important;
    }

    /* ── Sidebar active item — only override when sidebar is light ── */
    /* (dark sidebar overrides this below via !important block) */
    .sb-item.active {
        color: var(--t-primary) !important;
        background: color-mix(in srgb, var(--t-primary) 12%, transparent) !important;
    }

    /* ── Form inputs ── */
    .finput:focus {
        border-color: var(--t-primary) !important;
        box-shadow: 0 0 0 3px color-mix(in srgb, var(--t-primary) 15%, transparent) !important;
    }

    /* ── Ticket tags ── */
    .ticket-tag { color: var(--t-primary) !important; }

    /* ── Stat card icons ── */
    .stat-card .stat-icon svg { stroke: var(--t-primary); }
    .stat-card .stat-icon-wrap svg { stroke: var(--t-primary); }

    /* ── Legacy blue-acc links → tenant primary ── */
    a[style*='background:var(--blue-acc)'] { background: var(--t-primary) !important; }

    /* ── Links inside page header ── */
    .page-hdr a { color: var(--t-primary) !important; }

    /* ── Modal / form submit buttons with hardcoded blue gradients ── */
    button[style*='background:linear-gradient'][style*='#2563eb'],
    button[style*='background:linear-gradient'][style*='#1d4ed8'],
    button[style*='background:linear-gradient'][style*='#1e3a8a'] {
        background: linear-gradient(135deg, var(--t-primary), var(--t-secondary)) !important;
        color: {$btnPrimaryText} !important;
    }

    /* ── Save Theme & Branding button ── */
    form[enctype] button[type=submit] {
        background: linear-gradient(135deg, var(--t-primary), var(--t-secondary)) !important;
        color: {$btnPrimaryText} !important;
    }

    /* ── Dashboard branch card gradient ── */
    .branch-hero, [class*='branch-card'] {
        background: linear-gradient(135deg, var(--t-secondary), var(--t-primary)) !important;
    }

    /* ── Cards & stat cards — dark glass tinted by primary ── */
    .card, .stat-card, .glass-card {
        background: color-mix(in srgb, var(--t-primary) 8%, rgba(255,255,255,.06)) !important;
        border-color: color-mix(in srgb, var(--t-primary) 25%, rgba(255,255,255,.1)) !important;
    }

    {$sidebarCSS}
    </style>";
}

/**
 * Returns true if a hex color is dark (luminance < 0.4).
 * Used to decide whether to inject white-text sidebar overrides.
 */
function colorIsDark(string $hex): bool {
    $hex = ltrim($hex, '#');
    if (strlen($hex) !== 6) return false;
    $r = hexdec(substr($hex, 0, 2)) / 255;
    $g = hexdec(substr($hex, 2, 2)) / 255;
    $b = hexdec(substr($hex, 4, 2)) / 255;
    // Perceived luminance (sRGB approximation)
    $luminance = 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
    return $luminance < 0.4;
}

function adjustColor(string $hex, int $amount): string {
    $hex = ltrim($hex, '#');
    if (strlen($hex) !== 6) return '#' . $hex;
    $r = max(0, min(255, hexdec(substr($hex,0,2)) + $amount));
    $g = max(0, min(255, hexdec(substr($hex,2,2)) + $amount));
    $b = max(0, min(255, hexdec(substr($hex,4,2)) + $amount));
    return sprintf('#%02x%02x%02x', $r, $g, $b);
}