<?php
define('DB_HOST', 'pawnhub.mysql.database.azure.com');
define('DB_USER', 'PawnhubAdmin');
define('DB_PASS', 'Admin123');
define('DB_NAME', 'pawnhub');

$ssl_cert = __DIR__ . '/certs/DigiCertGlobalRootG2 (1).crt.pem';

try {
    $pdo = new PDO(
        'mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,

            // Azure SSL requirement
            PDO::MYSQL_ATTR_SSL_CA => $ssl_cert
        ]
    );

} catch (PDOException $e) {
    die('<div style="font-family:sans-serif;padding:30px;background:#fef2f2;color:#dc2626;border:1px solid #fca5a5;border-radius:8px;margin:20px;">
        <strong>Database Error:</strong> ' . htmlspecialchars($e->getMessage()) . '
    </div>');
}