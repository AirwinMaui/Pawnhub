<?php
/**
 * router.php — PawnHub Path-Based Tenant Router
 *
 * /{slug}              → tenant_home.php  (public shop/home page)
 * /{slug}?login=1      → tenant_login.php (sign-in page for all roles)
 * /{slug}?token=...    → tenant_login.php (invitation registration)
 * /{slug}?registered=1 → tenant_home.php  (redirected here after registration — but we
 *                         actually send ?login=1&registered=1 now, so this is a fallback)
 */

require_once __DIR__ . '/session_helper.php';

$slug = trim($_GET['slug'] ?? '');

// Reserved paths — not tenant slugs
$reserved = ['login', 'logout', 'signup', 'home', 'superadmin', 'tenant',
             'staff', 'cashier', 'router', 'db', 'api', 'index'];

if ($slug && !in_array(strtolower($slug), $reserved)) {

    // Route to tenant login when:
    //   ?login=1   — explicit sign-in button
    //   ?page=login — alternate query-string style
    //   ?token=...  — invitation/registration link
    $want_login = isset($_GET['login'])
               || (($_GET['page'] ?? '') === 'login')
               || isset($_GET['token']);

    // Route to walk-in applicant registration
    $want_register = isset($_GET['register']);

    if ($want_register) {
        require __DIR__ . '/tenant_home_register.php';
        exit;
    }

    if ($want_login) {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        // If a token is present, check the role so we route to the correct
        // registration page instead of always landing on tenant_login.php.
        if (isset($_GET['token'])) {
            $role_param = strtolower(trim($_GET['role'] ?? ''));

            if ($role_param === 'manager') {
                // Manager invitation → manager_register.php
                require __DIR__ . '/manager_register.php';
                exit;
            }

            if (in_array($role_param, ['staff', 'cashier'])) {
                // Staff / Cashier invitation → staff_register.php
                require __DIR__ . '/staff_register.php';
                exit;
            }

            // role='' or role=admin → tenant owner registration (tenant_login.php handles it)
        }

        require __DIR__ . '/tenant_login.php';
    } else {
        // Default: public tenant shop/home page
        require __DIR__ . '/tenant_home.php';
    }
    exit;
}

// No valid slug → main PawnHub home
require __DIR__ . '/home.php';