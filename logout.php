<?php
ini_set('session.gc_maxlifetime', 28800);
ini_set('session.cookie_lifetime', 28800);
session_set_cookie_params(['lifetime'=>28800,'path'=>'/','secure'=>true,'httponly'=>true,'samesite'=>'Lax']);

// Determine which session to destroy based on role hint in URL
$role_hint = $_GET['role'] ?? '';
if ($role_hint === 'super_admin') {
    session_name('PAWNHUB_SUPERADMIN');
} else {
    session_name('PAWNHUB_TENANT');
}
session_start();

$redirect = 'login.php'; // default: super admin login

if (!empty($_SESSION['user'])) {
    require 'db.php';
    $u = $_SESSION['user'];

    // Determine redirect before destroying session
    if (!empty($u['tenant_slug'])) {
        $redirect = '/' . rawurlencode($u['tenant_slug']);
    } elseif (!empty($u['tenant_id']) && $u['role'] !== 'super_admin') {
        // Fallback: look up slug from DB in case it wasn't in session
        try {
            $s = $pdo->prepare("SELECT slug FROM tenants WHERE id = ? LIMIT 1");
            $s->execute([$u['tenant_id']]);
            $row = $s->fetch();
            if (!empty($row['slug'])) {
                $redirect = '/' . rawurlencode($row['slug']);
            }
        } catch (Throwable $e) {}
    }

    // Audit log
    try {
        $pdo->prepare("INSERT INTO audit_logs (tenant_id,actor_user_id,actor_username,actor_role,action,entity_type,entity_id,message,ip_address,created_at) VALUES (?,?,?,?,'USER_LOGOUT','user',?,?,?,NOW())")
            ->execute([$u['tenant_id']??null, $u['id'], $u['username'], $u['role'], (string)$u['id'], $u['name'].' logged out.', $_SERVER['REMOTE_ADDR']??'::1']);
    } catch (PDOException $e) {}
}

session_unset();
session_destroy();
header('Location: ' . $redirect);
exit;