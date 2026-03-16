<?php
define('DB_HOST', 'pawnhub.mysql.database.azure.com');
define('DB_USER', 'PawnhubAdmin@pawnhub');
define('DB_PASS', 'YOUR_NEW_PASSWORD_HERE');
define('DB_NAME', 'pawnhub');
define('DB_PORT', '3306');

$ssl_cert = __DIR__ . '/certs/DigiCertGlobalRootG2.crt.pem';

try {
    if (!file_exists($ssl_cert)) {
        die('SSL certificate file not found: ' . $ssl_cert);
    }

    $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4';

    $pdo = new PDO(
        $dsn,
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::MYSQL_ATTR_SSL_CA => $ssl_cert
        ]
    );

    echo "Database connected successfully";

} catch (PDOException $e) {
    die('<div style="font-family:sans-serif;padding:30px;background:#fef2f2;color:#dc2626;border:1px solid #fca5a5;border-radius:8px;margin:20px;">
        <strong>Database Error:</strong> ' . htmlspecialchars($e->getMessage()) . '
    </div>');
}