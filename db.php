<?php
define('DB_HOST', 'pawnhub.mysql.database.azure.com');
define('DB_USER', 'PawnhubAdmin');
define('DB_PASS', 'Admin123');
define('DB_NAME', 'pawnhub');
define('DB_PORT', '3306');

// Look for SSL cert in multiple possible locations (Azure Web App paths)
$ssl_cert_paths = [
    __DIR__ . '/certs/DigiCertGlobalRootG2.crt.pem',
    '/home/site/wwwroot/certs/DigiCertGlobalRootG2.crt.pem',
    '/home/DigiCertGlobalRootG2.crt.pem',
    __DIR__ . '/DigiCertGlobalRootG2.crt.pem',
];

$ssl_cert = null;
foreach ($ssl_cert_paths as $path) {
    if (file_exists($path)) {
        $ssl_cert = $path;
        break;
    }
}

try {
    $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4';

    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    // Use SSL cert if found, otherwise connect without (Azure sometimes handles SSL at network level)
    if ($ssl_cert) {
        $options[PDO::MYSQL_ATTR_SSL_CA]     = $ssl_cert;
        $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = false;
    } else {
        // No cert file found — try connecting without strict SSL
        // Azure Database for MySQL Flexible Server supports this in VNet-integrated setups
        $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = false;
    }

    $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);

} catch (Throwable $e) {
    die('<div style="font-family:sans-serif;padding:30px;background:#fef2f2;color:#dc2626;border:1px solid #fca5a5;border-radius:8px;margin:20px;">
        <strong>Database Error:</strong> ' . htmlspecialchars($e->getMessage()) . '
    </div>');
}