<?php
/**
 * ocr_public.php
 * Uses OCR.space free API — no billing, no server install needed.
 * Free tier: 25,000 requests/month, max 1MB per image.
 * API key: get free key at https://ocr.space/ocrapi (takes 1 min)
 *
 * Set OCR_SPACE_API_KEY in paymongo_config.php:
 *   define('OCR_SPACE_API_KEY', 'helloworld'); // 'helloworld' = free demo key (limited)
 */

require_once __DIR__ . '/paymongo_config.php';

header('Content-Type: application/json');

// ── Rate limiting (file-based, no session conflict) ───────────
$rate_file = sys_get_temp_dir() . '/ocr_rl_' . md5($_SERVER['REMOTE_ADDR'] ?? 'x');
$now  = time();
$last = @file_get_contents($rate_file);
if ($last && ($now - (int)$last) < 10) {
    echo json_encode(['success' => false, 'error' => 'Please wait a moment before trying again.']);
    exit;
}
@file_put_contents($rate_file, $now);

// ── Validate upload ───────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_FILES['permit'])) {
    echo json_encode(['success' => false, 'error' => 'No file uploaded.']);
    exit;
}

$file    = $_FILES['permit'];
$mime    = mime_content_type($file['tmp_name']);
$allowed = ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'application/pdf'];

if (!in_array($mime, $allowed)) {
    echo json_encode(['success' => false, 'error' => 'Invalid file type. Upload JPG, PNG, WEBP, or PDF.']);
    exit;
}
if ($file['size'] > 5 * 1024 * 1024) {
    echo json_encode(['success' => false, 'error' => 'File too large. Max 5MB.']);
    exit;
}

// ── Get API key ───────────────────────────────────────────────
// 'helloworld' is OCR.space's free demo key — works but rate-limited
// Get your own free key at: https://ocr.space/ocrapi
$api_key = defined('OCR_SPACE_API_KEY') ? OCR_SPACE_API_KEY : 'helloworld';

// ── Call OCR.space API ────────────────────────────────────────
$post_fields = [
    'apikey'          => $api_key,
    'language'        => 'eng',
    'isOverlayRequired' => 'false',
    'detectOrientation' => 'true',
    'scale'           => 'true',
    'OCREngine'       => '2',   // Engine 2 = better for structured documents
    'file'            => new CURLFile($file['tmp_name'], $mime, $file['name']),
];

$ch = curl_init('https://api.ocr.space/parse/image');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $post_fields,
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_SSL_VERIFYPEER => true,
]);
$raw      = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr  = curl_error($ch);
curl_close($ch);

if ($curlErr || $httpCode !== 200 || !$raw) {
    echo json_encode(['success' => false, 'error' => 'Could not connect to OCR service. Please fill in the fields manually.']);
    exit;
}

$result = json_decode($raw, true);

// Check for OCR.space errors
if (!empty($result['IsErroredOnProcessing']) || empty($result['ParsedResults'])) {
    $msg = $result['ErrorMessage'][0] ?? 'OCR processing failed.';
    echo json_encode(['success' => false, 'error' => 'Could not read permit. Please fill in manually. (' . $msg . ')']);
    exit;
}

$raw_text = $result['ParsedResults'][0]['ParsedText'] ?? '';

if (!trim($raw_text)) {
    echo json_encode(['success' => false, 'error' => 'No text found. Please upload a clearer photo of your business permit.']);
    exit;
}

// ── Parse fields from extracted text ─────────────────────────
$fields = parsePermitText($raw_text);

echo json_encode(['success' => true, 'fields' => $fields]);
exit;


function parsePermitText(string $text): array
{
    $lines  = array_values(array_filter(array_map('trim', explode("\n", $text)), fn($l) => strlen($l) > 1));
    $fields = ['business_name' => '', 'owner_name' => '', 'address' => ''];

    // Business Name
    foreach ([
        '/Business\s+Name\s*[:\-]\s*(.+)/i',
        '/(?:TRADE\s+NAME|NAME\s+OF\s+BUSINESS|ESTABLISHMENT\s+NAME|REGISTERED\s+NAME)\s*[:\-]\s*(.+)/i',
    ] as $p) {
        if (preg_match($p, $text, $m)) {
            $val = trim($m[1]);
            if (strlen($val) > 1 && strlen($val) < 120) { $fields['business_name'] = $val; break; }
        }
    }

    // Owner
    foreach ([
        '/Owner\s*[\/\\\\]?\s*Proprietor\s*[:\-]\s*(.+)/i',
        '/(?:OWNER|PROPRIETOR|REGISTERED\s+OWNER|APPLICANT|LICENSEE|ISSUED\s+TO|GRANTED\s+TO)\s*[:\-]\s*(.+)/i',
        '/(?:NAME\s+OF\s+OWNER|NAME\s+OF\s+PROPRIETOR)\s*[:\-]\s*(.+)/i',
    ] as $p) {
        if (preg_match($p, $text, $m)) {
            $val = trim($m[1]);
            if (strlen($val) < 80 && !preg_match('/PERMIT|LICENSE|BUSINESS|TRADE|NATURE/i', $val)) {
                $fields['owner_name'] = $val; break;
            }
        }
    }

    // Address
    foreach ([
        '/Business\s+Address\s*[:\-]\s*(.+)/i',
        '/(?:ADDRESS|LOCATION|PLACE\s+OF\s+BUSINESS)\s*[:\-]\s*(.+)/i',
        '/(?:BARANGAY|BRGY\.?)\s+[\w\s,]+(?:,\s*[\w\s]+){1,2}/i',
    ] as $p) {
        if (preg_match($p, $text, $m)) {
            $val = trim($m[1]);
            if (strlen($val) > 3 && strlen($val) < 200) { $fields['address'] = $val; break; }
        }
    }

    // Fallback: ALL-CAPS line = business name
    if (empty($fields['business_name'])) {
        foreach ($lines as $line) {
            if (strlen($line) < 5 || strlen($line) > 60) continue;
            if (preg_match('/\d{4}|\bREPUBLIC\b|\bPROVINCE\b|\bCITY\b|\bMUNICIPALITY\b|\bPERMIT\b|\bOFFICE\b/i', $line)) continue;
            if ($line === strtoupper($line) && preg_match('/[A-Z]{3}/', $line)) {
                $fields['business_name'] = ucwords(strtolower($line)); break;
            }
        }
    }

    // Clean all fields
    foreach ($fields as &$val) {
        $val = trim(preg_replace('/\s+/', ' ', $val), " \t\n\r\0\x0B-:;,.");
        if (strlen($val) > 120) $val = substr($val, 0, 120);
    }

    return $fields;
}