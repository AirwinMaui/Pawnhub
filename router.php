<?php
/**
 * router.php — PawnHub Path-Based Tenant Router
 * Reads the slug from ?slug= (passed by .htaccess / nginx)
 * and loads the branded tenant login page.
 */

// ── Session config — must be set before tenant_login.php is required ──
require_once __DIR__ . '/session_helper.php';

$slug = trim($_GET['slug'] ?? '');

// Reserved paths — these should NOT be treated as tenant slugs
$reserved = ['login', 'logout', 'signup', 'home', 'superadmin', 'tenant',
             'staff', 'cashier', 'router', 'db', 'api', 'index'];

if ($slug && !in_array(strtolower($slug), $reserved)) {
    // Forward to tenant branded login
    session_start();
    require __DIR__ . '/tenant_login.php';
    exit;
}

// No valid slug — go to main home
require __DIR__ . '/home.php';