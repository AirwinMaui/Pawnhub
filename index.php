<?php
session_start();

// Kung naka-login na, i-redirect sa tamang dashboard
if (!empty($_SESSION['user'])) {
    $role = $_SESSION['user']['role'] ?? '';
    if ($role === 'super_admin') { header('Location: superadmin.php'); exit; }
    if ($role === 'admin')       { header('Location: tenant.php');     exit; }
    if ($role === 'staff')       { header('Location: staff.php');      exit; }
    if ($role === 'cashier')     { header('Location: cashier.php');    exit; }
}

// Hindi pa naka-login — ipakita ang home page
header('Location: home.php');
exit;