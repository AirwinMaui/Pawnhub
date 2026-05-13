<?php
/**
 * smtp_test.php
 * I-upload sa root ng iyong site, then buksan sa browser:
 * https://yourdomain.com/smtp_test.php
 * TANGGALIN agad pagkatapos!
 */

echo "<pre style='font-family:monospace;padding:20px;'>";

$host = 'smtp-relay.brevo.com';
$port = 587;

echo "Testing connection to {$host}:{$port}...\n\n";

$conn = @fsockopen($host, $port, $errno, $errstr, 10);

if ($conn) {
    echo "✅ Port 587 is OPEN — Azure is NOT blocking it.\n";
    echo "Response: " . fgets($conn, 512) . "\n";
    fclose($conn);
} else {
    echo "❌ Port 587 is BLOCKED — Error {$errno}: {$errstr}\n";
    echo "Azure is blocking outbound SMTP on port 587.\n";
}

echo "\n--- Testing port 465 ---\n";
$conn2 = @fsockopen("ssl://{$host}", 465, $errno2, $errstr2, 10);
if ($conn2) {
    echo "✅ Port 465 is OPEN\n";
    echo "Response: " . fgets($conn2, 512) . "\n";
    fclose($conn2);
} else {
    echo "❌ Port 465 is BLOCKED — Error {$errno2}: {$errstr2}\n";
}

echo "\n--- PHP Info ---\n";
echo "PHP Version: " . PHP_VERSION . "\n";
echo "OpenSSL: " . (extension_loaded('openssl') ? '✅ loaded' : '❌ missing') . "\n";
echo "cURL: "    . (extension_loaded('curl')    ? '✅ loaded' : '❌ missing') . "\n";

echo "</pre>";
echo "<p style='color:red;font-family:sans-serif'><strong>⚠️ DELETE THIS FILE after testing!</strong></p>";