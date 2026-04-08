<?php
/**
 * router.php — PawnHub Path-Based Tenant Router
 * /{slug}        → tenant_home.php  (public shop/home page)
 * /{slug}/login  → tenant_login.php (staff login) — via ?login=1 or separate rewrite
 */

require_once __DIR__ . '/session_helper.php';

$slug = trim($_GET['slug'] ?? '');

// Reserved paths — not tenant slugs
$reserved = ['login', 'logout', 'signup', 'home', 'superadmin', 'tenant',
             'staff', 'cashier', 'router', 'db', 'api', 'index'];

if ($slug && !in_array(strtolower($slug), $reserved)) {

    // ?login=1 or ?page=login → go to tenant login
    $want_login = isset($_GET['login']) || (($_GET['page'] ?? '') === 'login');

    // ?register=1 or ?token=xxx → tenant owner registration
    $want_tenant_register = isset($_GET['register']) || (isset($_GET['token']) && !isset($_GET['role']));

    // ?token=xxx&role=manager → manager registration
    $want_manager_register = isset($_GET['token']) && (($_GET['role'] ?? '') === 'manager');

    // ?token=xxx&role=staff or role=cashier → staff registration
    $want_staff_register = isset($_GET['token']) && in_array($_GET['role'] ?? '', ['staff', 'cashier']);

    if ($want_login) {
        session_start();
        require __DIR__ . '/tenant_login.php';
    } elseif ($want_manager_register) {
        session_start();
        require __DIR__ . '/manager_register.php';
    } elseif ($want_staff_register) {
        session_start();
        require __DIR__ . '/staff_register.php';
    } elseif ($want_tenant_register) {
        session_start();
        require __DIR__ . '/tenant_register.php';
    } else {
        // Default: show the public tenant home/shop page
        require __DIR__ . '/tenant_home.php';
    }
    exit;
}

// No valid slug → main PawnHub home
require __DIR__ . '/home.php';