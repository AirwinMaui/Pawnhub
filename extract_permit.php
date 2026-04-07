<?php
header('Content-Type: application/json');

$apiKey = getenv('GEMINI_API_KEY')
       ?: ($_ENV['GEMINI_API_KEY']    ?? '')
       ?: ($_SERVER['GEMINI_API_KEY'] ?? '');

if (!$apiKey) {
    echo json_encode(['error' => 'No API key configured']);
    exit;
}

if (empty($_FILES['permit']['tmp_name'])) {
    echo json_encode(['error' => 'No file uploaded']);
    exit;
}

$file    = $_FILES['permit'];
$mime    = mime_content_type($file['tmp_name']);
$allowed = ['image/jpeg', 'image/png', 'image/webp'];

if (!in_array($mime, $allowed)) {
    echo json_encode(['error' => 'Invalid file type.']);
    exit;
}

$imageData = base64_encode(file_get_contents($file['tmp_name']));

$payload = [
    'contents' => [[
        'parts' => [
            [
                'inline_data' => [
                    'mime_type' => $mime,
                    'data'      => $imageData,
                ]
            ],
            [
                'text' => 'Extract the following from this Philippine business permit or DTI/SEC registration. Reply ONLY with valid JSON, no markdown:
{
  "business_name": "",
  "owner_name": "",
  "address": "",
  "phone": ""
}
If a field is not found, leave it as empty string.'
            ]
        ]
    ]]
];

$url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key=' . $apiKey;

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    CURLOPT_POSTFIELDS     => json_encode($payload),
    CURLOPT_TIMEOUT        => 30,
]);

$response  = curl_exec($ch);
$httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if (!$response || $httpCode !== 200) {
    $errBody = json_decode($response, true);
    $errMsg  = $errBody['error']['message'] ?? ('HTTP ' . $httpCode);
    echo json_encode(['error' => 'AI error: ' . $errMsg]);
    exit;
}

$data = json_decode($response, true);
$text = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';

// Strip markdown fences
$text      = preg_replace('/```json|```/', '', $text);
$extracted = json_decode(trim($text), true);

if (!$extracted) {
    echo json_encode(['error' => 'AI could not read the document']);
    exit;
}

echo json_encode(['success' => true, 'data' => $extracted]);