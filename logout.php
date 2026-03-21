<?php
session_start();
if (!empty($_SESSION['user'])) {
    require 'db.php';
    $u = $_SESSION['user'];
    try {
        $pdo->prepare("INSERT INTO audit_logs (tenant_id,actor_user_id,actor_username,actor_role,action,entity_type,entity_id,message,ip_address,created_at) VALUES (?,?,?,?,'USER_LOGOUT','user',?,?,?,NOW())")
            ->execute([$u['tenant_id']??null, $u['id'], $u['username'], $u['role'], (string)$u['id'], $u['name'].' logged out.', $_SERVER['REMOTE_ADDR']??'::1']);
    } catch (PDOException $e) {}
}
session_unset();
session_destroy();
header('Location: login.php');
exit;