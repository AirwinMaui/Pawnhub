<?php
require_once __DIR__ . '/session_helper.php';

// Determine which role's session to destroy
$role_hint = $_GET['role'] ?? '';
pawnhub_session_start($role_hint);

// Default redirect based on role
if ($role_hint === 'super_admin') {
    $redirect = 'login.php';
} else {
    $redirect = 'home.php'; // will be overridden below if slug is found
}

if (!empty($_SESSION['user'])) {
    require 'db.php';
    $u = $_SESSION['user'];

    // Determine redirect before destroying session
    if (!empty($u['tenant_slug'])) {
        $redirect = '/' . rawurlencode($u['tenant_slug']);
    } elseif (!empty($u['tenant_id']) && $u['role'] !== 'super_admin') {
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
} elseif ($role_hint !== 'super_admin') {
    // Session expired but we know it's a tenant user — try to get slug from DB via referer
    require 'db.php';
    $ref = $_SERVER['HTTP_REFERER'] ?? '';
    if ($ref) {
        $path = trim(parse_url($ref, PHP_URL_PATH), '/');
        if ($path && !in_array($path, ['login.php','logout.php','superadmin.php','tenant.php','manager.php','staff.php','cashier.php'])) {
            try {
                $s = $pdo->prepare("SELECT slug FROM tenants WHERE slug = ? LIMIT 1");
                $s->execute([$path]);
                $row = $s->fetch();
                if (!empty($row['slug'])) {
                    $redirect = '/' . rawurlencode($row['slug']);
                }
            } catch (Throwable $e) {}
        }
    }
}

session_unset();
session_destroy();
header('Location: ' . $redirect);
exit;