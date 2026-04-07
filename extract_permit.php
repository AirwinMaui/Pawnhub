<?php
header('Content-Type: application/json');

$apiKey = getenv('ANTHROPIC_API_KEY');
if (!$apiKey) {
    echo json_encode(['error' => 'No API key configured']);
    exit;
}

if (empty($_FILES['permit']['tmp_name'])) {
    echo json_encode(['error' => 'No file uploaded']);
    exit;
}

$file     = $_FILES['permit'];
$mime     = mime_content_type($file['tmp_name']);
$allowed  = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

if (!in_array($mime, $allowed)) {
    echo json_encode(['error' => 'Invalid file type. Use JPG, PNG, or WEBP.']);
    exit;
}

$imageData = base64_encode(file_get_contents($file['tmp_name']));

$payload = [
    'model'      => 'claude-opus-4-5',
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

$ch = curl_init('https://api.anthropic.com/v1/messages');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'x-api-key: ' . $apiKey,
        'anthropic-version: 2023-06-01',
    ],
    CURLOPT_POSTFIELDS     => json_encode($payload),
    CURLOPT_TIMEOUT        => 30,
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if (!$response || $httpCode !== 200) {
    echo json_encode(['error' => 'Could not connect to AI']);
    exit;
}

$data = json_decode($response, true);
$text = $data['content'][0]['text'] ?? '';

// Strip any accidental markdown
$text = preg_replace('/```json|```/', '', $text);
$extracted = json_decode(trim($text), true);

if (!$extracted) {
    echo json_encode(['error' => 'AI could not read the document']);
    exit;
}

echo json_encode(['success' => true, 'data' => $extracted]);