<?php
header('Content-Type: application/json');

// ── 1. Robust API key retrieval (Azure App Service fix) ───────────────────────
$apiKey = getenv('ANTHROPIC_API_KEY')
       ?: ($_ENV['ANTHROPIC_API_KEY']   ?? '')
       ?: ($_SERVER['ANTHROPIC_API_KEY'] ?? '');

if (!$apiKey) {
    echo json_encode(['error' => 'No API key configured']);
    exit;
}

// ── 2. File validation ────────────────────────────────────────────────────────
if (empty($_FILES['permit']['tmp_name'])) {
    echo json_encode(['error' => 'No file uploaded']);
    exit;
}

$file    = $_FILES['permit'];
$mime    = mime_content_type($file['tmp_name']);
$allowed = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

if (!in_array($mime, $allowed)) {
    echo json_encode(['error' => 'Invalid file type. Use JPG, PNG, or WEBP.']);
    exit;
}

$imageData = base64_encode(file_get_contents($file['tmp_name']));

// ── 3. Build payload ──────────────────────────────────────────────────────────
$payload = [
    'model'      => 'claude-haiku-4-5-20251001',
    'max_tokens' => 512,
    'messages'   => [[
        'role'    => 'user',
        'content' => [
            [
                'type'   => 'image',
                'source' => [
                    'type'       => 'base64',
                    'media_type' => $mime,
                    'data'       => $imageData,
                ],
            ],
            [
                'type' => 'text',
                'text' => 'Extract the following from this Philippine business permit or DTI/SEC registration document. Reply ONLY with valid JSON, no markdown, no explanation:
{
  "business_name": "",
  "owner_name": "",
  "address": "",
  "phone": ""
}
If a field is not found, leave it as empty string.',
            ],
        ],
    ]],
];

// ── 4. cURL with explicit headers (fixes 400 on some PHP/Azure configs) ───────
$ch = curl_init('https://api.anthropic.com/v1/messages');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'x-api-key: ' . $apiKey,           // must be lowercase
        'anthropic-version: 2023-06-01',
        'Accept: application/json',         // added — some proxies need this
    ],
    CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_SSL_VERIFYPEER => true,        // keep SSL verification ON in prod
]);

$response  = curl_exec($ch);
$httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

// ── 5. Handle non-200 responses with full debug info ─────────────────────────
if (!$response || $httpCode !== 200) {
    // Attempt to decode Anthropic's error body for a cleaner message
    $errBody = json_decode($response, true);
    $errMsg  = $errBody['error']['message'] ?? ('HTTP ' . $httpCode);

    echo json_encode([
        'error'      => 'Could not connect to AI: ' . $errMsg,
        'http_code'  => $httpCode,
        'curl_error' => $curlError,
        'response'   => $response,   // remove in production
    ]);
    exit;
}

// ── 6. Parse AI response ──────────────────────────────────────────────────────
$data = json_decode($response, true);
$text = $data['content'][0]['text'] ?? '';

// Strip accidental markdown fences
$text      = preg_replace('/```json|```/', '', $text);
$extracted = json_decode(trim($text), true);

if (!$extracted) {
    echo json_encode(['error' => 'AI could not read the document']);
    exit;
}

echo json_encode(['success' => true, 'data' => $extracted]);