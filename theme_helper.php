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
        'sidebar_color'   => '#0f172a',
        'logo_text'       => null,
        'logo_url'        => null,
        'bg_image_url'    => null,
        'shop_bg_url'     => null,
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
    $p  = htmlspecialchars($theme['primary_color']   ?? '#2563eb');
    $s  = htmlspecialchars($theme['secondary_color'] ?? '#1e3a8a');
    $a  = htmlspecialchars($theme['accent_color']    ?? '#10b981');
    $sb = htmlspecialchars($theme['sidebar_color']   ?? '#0f172a');

    $pDark = adjustColor($p, -20);
    $aDark = adjustColor($a, -20);

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
    .sb-item.active             { background: rgba(255,255,255,.15) !important; color: #fff !important; }
    .sidebar, aside.sidebar     { background: linear-gradient(175deg, var(--t-sidebar), var(--t-secondary)) !important; }
    .btn-primary, .btn-sm.btn-primary { background: var(--t-primary) !important; border-color: var(--t-primary) !important; }
    .btn-primary:hover          { background: var(--t-primary-d) !important; }
    .topbar .tenant-chip, .topbar .cashier-chip { background: rgba(0,0,0,.07); color: var(--t-primary); border-color: var(--t-primary); }
    .finput:focus               { border-color: var(--t-primary) !important; box-shadow: 0 0 0 3px color-mix(in srgb, var(--t-primary) 15%, transparent) !important; }
    .ticket-tag                 { color: var(--t-primary) !important; }
    .stat-card .stat-icon svg   { stroke: var(--t-primary); }
    a[style*='background:var(--blue-acc)'] { background: var(--t-primary) !important; }
    </style>";
}

function adjustColor(string $hex, int $amount): string {
    $hex = ltrim($hex, '#');
    if (strlen($hex) !== 6) return '#' . $hex;
    $r = max(0, min(255, hexdec(substr($hex,0,2)) + $amount));
    $g = max(0, min(255, hexdec(substr($hex,2,2)) + $amount));
    $b = max(0, min(255, hexdec(substr($hex,4,2)) + $amount));
    return sprintf('#%02x%02x%02x', $r, $g, $b);
}