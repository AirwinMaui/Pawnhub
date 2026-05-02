<?php
/**
 * PawnHub Theme Helper
 * Load tenant custom theme settings (colors + bg image + logo)
 */
function getTenantTheme(PDO $pdo, int $tenant_id): array {
    try {
        $stmt = $pdo->prepare("SELECT * FROM tenant_settings WHERE tenant_id=? LIMIT 1");
        $stmt->execute([$tenant_id]);
        $s = $stmt->fetch();
        if ($s) return $s;
    } catch (Exception $e) {}

    return [
        'primary_color'   => '#2563eb',
        'secondary_color' => '#1e3a8a',
        'accent_color'    => '#10b981',
        'sidebar_color'   => '#ffffff',
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
    $p   = htmlspecialchars($theme['primary_color']   ?? '#2563eb');
    $s   = htmlspecialchars($theme['secondary_color'] ?? '#1e3a8a');
    $a   = htmlspecialchars($theme['accent_color']    ?? '#10b981');
    $sb  = htmlspecialchars($theme['sidebar_color']   ?? '#ffffff');

    $pDark = adjustColor($p, -20);
    $aDark = adjustColor($a, -20);

    // When tenant picks a dark sidebar color, inject dark-mode sidebar overrides
    $isDarkSidebar = colorIsDark($sb);

    $sidebarCSS = '';
    if ($isDarkSidebar) {
        $sidebarCSS = "
    /* Tenant dark sidebar overrides */
    .sidebar, aside.sidebar {
        background: linear-gradient(175deg, {$sb}, {$s}) !important;
        border-right-color: rgba(255,255,255,.06) !important;
    }
    .sb-brand, .sb-user, .sb-footer { border-color: rgba(255,255,255,.07) !important; }
    .sb-name, .sb-uname, .sb-role-name, .sb-tenant-name { color: #fff !important; }
    .sb-subtitle, .sb-urole, .sb-role-label, .sb-tenant-label,
    .sb-section { color: rgba(255,255,255,.35) !important; }
    .sb-item { color: rgba(255,255,255,.45) !important; }
    .sb-item:hover { background: rgba(255,255,255,.08) !important; color: #fff !important; }
    .sb-item.active { background: rgba(255,255,255,.15) !important; color: #fff !important; }
    .sb-logout { color: rgba(255,255,255,.35) !important; }
    .sb-logout:hover { color: #f87171 !important; background: rgba(239,68,68,.12) !important; }
    .sb-role-card, .sb-tenant-card {
        background: rgba(255,255,255,.06) !important;
        border-color: rgba(255,255,255,.1) !important;
    }
    .sb-role-badge, .sb-tenant-badge {
        background: rgba(255,255,255,.12) !important;
        color: rgba(255,255,255,.75) !important;
    }
    .sb-status { background: rgba(16,185,129,.2) !important; color: #6ee7b7 !important; }";
    }

    return "
    <style id='tenant-theme'>
    :root {
        --t-primary:   {$p};
        --t-primary-d: {$pDark};
        --t-secondary: {$s};
        --t-accent:    {$a};
        --t-accent-d:  {$aDark};
        --t-sidebar:   {$sb};
    }
    .sb-logo, .theme-primary-bg { background: linear-gradient(135deg, var(--t-primary), var(--t-secondary)) !important; }
    .btn-primary, .btn-sm.btn-primary { background: var(--t-primary) !important; border-color: var(--t-primary) !important; }
    .btn-primary:hover { background: var(--t-primary-d) !important; }
    .topbar .tenant-chip, .topbar .cashier-chip, .topbar .mgr-chip {
        background: color-mix(in srgb, var(--t-primary) 10%, transparent) !important;
        color: var(--t-primary) !important;
        border-color: color-mix(in srgb, var(--t-primary) 25%, transparent) !important;
    }
    .sb-item.active { color: var(--t-primary) !important; background: color-mix(in srgb, var(--t-primary) 12%, transparent) !important; }
    .finput:focus { border-color: var(--t-primary) !important; box-shadow: 0 0 0 3px color-mix(in srgb, var(--t-primary) 15%, transparent) !important; }
    .ticket-tag { color: var(--t-primary) !important; }
    .stat-card .stat-icon svg { stroke: var(--t-primary); }
    a[style*='background:var(--blue-acc)'] { background: var(--t-primary) !important; }
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