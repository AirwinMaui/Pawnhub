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

/**
 * Convert hex color to RGB array [r, g, b]
 */
function hexToRgb(string $hex): array {
    $hex = ltrim($hex, '#');
    if (strlen($hex) === 3) {
        $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
    }
    if (strlen($hex) !== 6) return [37, 99, 235]; // fallback blue
    return [
        hexdec(substr($hex, 0, 2)),
        hexdec(substr($hex, 2, 2)),
        hexdec(substr($hex, 4, 2)),
    ];
}

/**
 * Calculate relative luminance of a color (WCAG formula)
 */
function getLuminance(string $hex): float {
    [$r, $g, $b] = hexToRgb($hex);
    $toLinear = fn($c) => ($c / 255 <= 0.04045)
        ? $c / 255 / 12.92
        : (($c / 255 + 0.055) / 1.055) ** 2.4;
    return 0.2126 * $toLinear($r) + 0.7152 * $toLinear($g) + 0.0722 * $toLinear($b);
}

/**
 * Get contrast ratio between two hex colors (WCAG)
 */
function getContrast(string $hex1, string $hex2): float {
    $l1 = getLuminance($hex1);
    $l2 = getLuminance($hex2);
    $lighter = max($l1, $l2);
    $darker  = min($l1, $l2);
    return ($lighter + 0.05) / ($darker + 0.05);
}

/**
 * Given a primary color, return the best text color to use ON TOP of it
 * Returns #ffffff for dark backgrounds, #0f172a for light backgrounds
 */
function getTextOnColor(string $hex): string {
    $lum = getLuminance($hex);
    return $lum > 0.35 ? '#0f172a' : '#ffffff';
}

/**
 * Darken or lighten a hex color by amount
 */
function adjustColor(string $hex, int $amount): string {
    $hex = ltrim($hex, '#');
    if (strlen($hex) !== 6) return '#' . $hex;
    $r = max(0, min(255, hexdec(substr($hex,0,2)) + $amount));
    $g = max(0, min(255, hexdec(substr($hex,2,2)) + $amount));
    $b = max(0, min(255, hexdec(substr($hex,4,2)) + $amount));
    return sprintf('#%02x%02x%02x', $r, $g, $b);
}

/**
 * Mix a hex color with black or white to ensure sidebar always reads clearly.
 * Returns a darkened sidebar base color guaranteed to have contrast >= 4.5 with white text.
 */
function ensureDarkSidebar(string $hex): string {
    [$r, $g, $b] = hexToRgb($hex);
    // Blend with very dark slate — use primary as a tint, keep sidebar very dark
    $rOut = (int)round($r * 0.15 + 10);
    $gOut = (int)round($g * 0.15 + 12);
    $bOut = (int)round($b * 0.15 + 18);
    return sprintf('#%02x%02x%02x',
        max(0, min(30, $rOut)),
        max(0, min(35, $gOut)),
        max(0, min(45, $bOut))
    );
}

/**
 * Generate a safe, always-readable nav active color from the primary color.
 * If primary is too light, we darken it and ensure white text.
 * Returns ['bg' => ..., 'text' => ..., 'border' => ...]
 */
function getSafeNavActive(string $primaryHex): array {
    $lum = getLuminance($primaryHex);

    if ($lum > 0.5) {
        // Very light color — darken significantly for nav active
        $darkened = adjustColor($primaryHex, -80);
        return [
            'bg'     => $darkened,
            'text'   => '#ffffff',
            'border' => adjustColor($primaryHex, -60),
        ];
    } elseif ($lum > 0.2) {
        // Medium — use as-is with white text
        return [
            'bg'     => $primaryHex,
            'text'   => '#ffffff',
            'border' => adjustColor($primaryHex, -20),
        ];
    } else {
        // Already dark — lighten it slightly for the active pill
        return [
            'bg'     => adjustColor($primaryHex, +30),
            'text'   => '#ffffff',
            'border' => $primaryHex,
        ];
    }
}

function renderThemeCSS(array $theme): string {
    $p  = $theme['primary_color']   ?? '#2563eb';
    $s  = $theme['secondary_color'] ?? '#1e3a8a';
    $a  = $theme['accent_color']    ?? '#10b981';
    $sb = $theme['sidebar_color']   ?? '#0f172a';

    // Sanitize hex values
    $sanitize = fn($h) => preg_match('/^#[0-9a-fA-F]{3,8}$/', $h) ? $h : '#2563eb';
    $p  = $sanitize($p);
    $s  = $sanitize($s);
    $a  = $sanitize($a);
    $sb = $sanitize($sb);

    $pSafe = htmlspecialchars($p);
    $sSafe = htmlspecialchars($s);
    $aSafe = htmlspecialchars($a);
    $sbSafe = htmlspecialchars($sb);

    $pDark = adjustColor($p, -20);
    $aDark = adjustColor($a, -20);

    // Always-dark sidebar base derived from primary color tint
    $sidebarBase = ensureDarkSidebar($p);

    // Safe nav-active styling
    $navActive = getSafeNavActive($p);
    $navActiveBg     = htmlspecialchars($navActive['bg']);
    $navActiveText   = htmlspecialchars($navActive['text']);
    $navActiveBorder = htmlspecialchars($navActive['border']);

    // Button text: white on dark buttons, dark on light buttons
    $btnText = getTextOnColor($p);
    $btnTextSafe = htmlspecialchars($btnText);

    // Logo gradient: always readable
    $logoGrad1 = $p;
    $logoGrad2 = $s;

    // RGB values for rgba() usage
    [$pr, $pg, $pb] = hexToRgb($p);
    [$ar, $ag, $ab] = hexToRgb($a);

    return "
    <style id='tenant-theme'>
    :root {
        --t-primary:    {$pSafe};
        --t-primary-d:  {$pDark};
        --t-secondary:  {$sSafe};
        --t-accent:     {$aSafe};
        --t-accent-d:   {$aDark};
        --t-sidebar:    {$sbSafe};
        --t-sidebar-base: {$sidebarBase};
        --t-p-r: {$pr};
        --t-p-g: {$pg};
        --t-p-b: {$pb};
        --t-a-r: {$ar};
        --t-a-g: {$ag};
        --t-a-b: {$ab};
        --t-btn-text:   {$btnTextSafe};
        --t-nav-active-bg:     {$navActiveBg};
        --t-nav-active-text:   {$navActiveText};
        --t-nav-active-border: {$navActiveBorder};
    }

    /* ── Logo / Brand ── */
    .sb-logo,
    .theme-primary-bg {
        background: linear-gradient(135deg, var(--t-primary), var(--t-secondary)) !important;
    }

    /* ── Sidebar: always dark regardless of tenant color ── */
    .sidebar,
    aside.sidebar {
        background: linear-gradient(175deg, var(--t-sidebar-base), color-mix(in srgb, var(--t-secondary) 30%, #060a0e)) !important;
    }

    /* ── Nav items: readable on dark sidebar ── */
    .sb-item {
        color: rgba(255, 255, 255, 0.55) !important;
    }
    .sb-item:hover {
        background: rgba(255, 255, 255, 0.09) !important;
        color: rgba(255, 255, 255, 0.95) !important;
    }

    /* ── Active nav: uses a safe color computed from primary ── */
    .sb-item.active {
        background: var(--t-nav-active-bg) !important;
        color: var(--t-nav-active-text) !important;
        font-weight: 700 !important;
        border-left: 3px solid var(--t-nav-active-border) !important;
        padding-left: 11px !important;
    }

    /* ── Sign Out: ALWAYS red, never themed ── */
    .sb-logout {
        color: rgba(248, 113, 113, 0.6) !important;
        background: none !important;
        border: none !important;
        width: 100% !important;
        text-align: left !important;
        font-family: inherit !important;
        cursor: pointer !important;
        display: flex !important;
        align-items: center !important;
        gap: 9px !important;
        font-size: .8rem !important;
        padding: 9px 10px !important;
        border-radius: 10px !important;
        transition: all .18s !important;
        text-decoration: none !important;
    }
    .sb-logout:hover {
        color: #fff !important;
        background: rgba(239, 68, 68, 0.85) !important;
        box-shadow: 0 2px 12px rgba(239, 68, 68, 0.35) !important;
    }
    .sb-logout .material-symbols-outlined {
        font-size: 18px !important;
        color: inherit !important;
    }

    /* ── Buttons ── */
    .btn-primary,
    .btn-sm.btn-primary {
        background: var(--t-primary) !important;
        border-color: var(--t-primary) !important;
        color: var(--t-btn-text) !important;
    }
    .btn-primary:hover {
        background: var(--t-primary-d) !important;
        border-color: var(--t-primary-d) !important;
    }
    .btn-success {
        background: rgba(var(--t-p-r), var(--t-p-g), var(--t-p-b), 0.85) !important;
        border-color: transparent !important;
        color: var(--t-btn-text) !important;
    }
    .btn-success:hover {
        background: var(--t-primary) !important;
    }

    /* ── Chips / badges in topbar ── */
    .topbar .tenant-chip,
    .topbar .cashier-chip {
        background: rgba(var(--t-p-r), var(--t-p-g), var(--t-p-b), 0.12);
        color: var(--t-primary);
        border-color: rgba(var(--t-p-r), var(--t-p-g), var(--t-p-b), 0.3);
    }

    /* ── Form inputs ── */
    .finput:focus {
        border-color: var(--t-primary) !important;
        box-shadow: 0 0 0 3px rgba(var(--t-p-r), var(--t-p-g), var(--t-p-b), 0.15) !important;
    }

    /* ── Ticket tag / monospace refs ── */
    .ticket-tag { color: var(--t-primary) !important; }

    /* ── Stat card icons ── */
    .stat-card .stat-icon svg { stroke: var(--t-primary); }

    /* ── Legacy inline styles using blue-acc ── */
    a[style*='background:var(--blue-acc)'] { background: var(--t-primary) !important; }

    /* ── Pay button ── */
    .btn-pay {
        background: linear-gradient(135deg, var(--t-primary), var(--t-primary-d)) !important;
        color: var(--t-btn-text) !important;
        box-shadow: 0 4px 18px rgba(var(--t-p-r), var(--t-p-g), var(--t-p-b), 0.3) !important;
    }
    </style>";
}