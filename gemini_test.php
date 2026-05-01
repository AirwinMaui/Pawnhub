<?php
/**
 * gemini_test.php
 * Quick debug page — DELETE after testing!
 * Access: https://yourdomain.com/gemini_test.php
 */
require_once __DIR__ . '/paymongo_config.php';

header('Content-Type: text/plain');

echo "=== GEMINI API DEBUG TEST ===\n\n";

// 1. Check key
$key = defined('GEMINI_API_KEY') ? GEMINI_API_KEY : '';
echo "1. GEMINI_API_KEY defined: " . (empty($key) ? "NO ❌" : "YES ✅") . "\n";
echo "   Key preview: " . substr($key, 0, 10) . "...\n\n";

// 2. Check curl
echo "2. cURL available: " . (function_exists('curl_init') ? "YES ✅" : "NO ❌") . "\n\n";

// 3. Test Gemini with simple text prompt (no image)
echo "3. Testing Gemini API (text only)...\n";

$url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key={$key}";

$payload = [
    'contents' => [[
        'parts' => [[
            'text' => 'Say hello in one word.'
        ]]
    ]],
    'generationConfig' => [
        'maxOutputTokens' => 50,
    ]
];

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    CURLOPT_POSTFIELDS     => json_encode($payload),
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_SSL_VERIFYPEER => false,
]);
$raw      = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curl_err = curl_error($ch);
curl_close($ch);

echo "   HTTP Status: {$httpCode}\n";

if ($curl_err) {
    echo "   cURL Error: {$curl_err} ❌\n";
} elseif ($httpCode === 200) {
    $resp = json_decode($raw, true);
    $text = $resp['candidates'][0]['content']['parts'][0]['text'] ?? '(no text)';
    echo "   Gemini Response: {$text} ✅\n";
} else {
    echo "   Error Response: {$raw} ❌\n";
}

echo "\n4. PHP version: " . PHP_VERSION . "\n";
echo "5. Server: " . ($_SERVER['SERVER_SOFTWARE'] ?? 'unknown') . "\n";

// 6. Check if permit folder exists
$permit_dir = __DIR__ . '/uploads/permits/';
echo "6. Permits folder exists: " . (is_dir($permit_dir) ? "YES ✅" : "NO ❌") . "\n";

// 7. List recent permit files
if (is_dir($permit_dir)) {
    $files = glob($permit_dir . '*');
    echo "   Recent permit files (" . count($files) . " total):\n";
    $recent = array_slice(array_reverse($files), 0, 3);
    foreach ($recent as $f) {
        echo "   - " . basename($f) . " (" . round(filesize($f)/1024, 1) . " KB)\n";
    }
}

echo "\n=== END ===\n";