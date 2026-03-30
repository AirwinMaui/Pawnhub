<?php
/**
 * session_helper.php — PawnHub Session Manager
 * Gives each role its own session cookie so multiple
 * users can be logged in simultaneously on the same browser.
 */

function pawnhub_session_name(string $role = ''): string {
    return match($role) {
        'super_admin' => 'PH_SUPERADMIN',
        'admin'       => 'PH_ADMIN',
        'manager'     => 'PH_MANAGER',
        'staff'       => 'PH_STAFF',
        'cashier'     => 'PH_CASHIER',
        default       => 'PH_TENANT', // fallback for router/login page (role unknown yet)
    };
}

function pawnhub_session_start(string $role = ''): void {
    if (session_status() !== PHP_SESSION_NONE) return;
    ini_set('session.gc_maxlifetime', 28800);
    ini_set('session.cookie_lifetime', 28800);
    session_set_cookie_params([
        'lifetime' => 28800,
        'path'     => '/',
        'secure'   => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_name(pawnhub_session_name($role));
    session_start();
}