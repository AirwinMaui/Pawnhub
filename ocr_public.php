<?php
/**
 * ocr_public.php
 * ─────────────────────────────────────────────────────────────
 * Public OCR endpoint for the signup page.
 * No session/auth required — called via AJAX when a user
 * uploads their business permit on signup.php.
 *
 * Returns JSON:
 *   { success, fields: { business_name, owner_name, address } }
 *
 * Rate-limited to 1 request per IP per 30 seconds to prevent abuse.
 * ─────────────────────────────────────────────────────────────
 */

require_once __DIR__ . '/paymongo_config.php'; // GOOGLE_VISION_API_KEY defined here

header('Content-Type: application/json');

// ── Session (only start if not already active) ────────────────
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ── Rate limiting: 1 OCR call per IP per 10 seconds ──────────
// Use IP-based file lock instead of session to avoid conflicts
$rate_file = sys_get_temp_dir() . '/ocr_rl_' . md5($_SERVER['REMOTE_ADDR'] ?? 'x');
$now = time();
$last = @file_get_contents($rate_file);
if ($last && ($now - (int)$last) < 10) {
    echo json_encode(['success' => false, 'error' => 'Please wait a moment before trying again.']);
    exit;
}
@file_put_contents($rate_file, $now);

// ── Only accept POST with a file ──────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_FILES['permit'])) {
    echo json_encode(['success' => false, 'error' => 'No file uploaded.']);
    exit;
}

$file    = $_FILES['permit'];
$mime    = mime_content_type($file['tmp_name']);
$allowed = ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'application/pdf'];

if (!in_array($mime, $allowed)) {
    echo json_encode(['success' => false, 'error' => 'Invalid file type.']);
    exit;
}

if ($file['size'] > 10 * 1024 * 1024) {
    echo json_encode(['success' => false, 'error' => 'File too large. Max 10MB.']);
    exit;
}

$api_key = defined('GOOGLE_VISION_API_KEY') ? GOOGLE_VISION_API_KEY : '';
if (!$api_key) {
    echo json_encode(['success' => false, 'error' => 'DEBUG: GOOGLE_VISION_API_KEY is not defined in paymongo_config.php']);
    exit;
}

// ── Call Google Cloud Vision API ─────────────────────────────
$image_data   = base64_encode(file_get_contents($file['tmp_name']));
$request_type = ($mime === 'application/pdf') ? 'DOCUMENT_TEXT_DETECTION' : 'TEXT_DETECTION';

$payload = json_encode([
    'requests' => [[
        'image'    => ['content' => $image_data],
        'features' => [['type' => $request_type, 'maxResults' => 1]],
    ]],
]);

$ch = curl_init('https://vision.googleapis.com/v1/images:annotate?key=' . urlencode($api_key));
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_TIMEOUT        => 30,
]);
$raw      = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr  = curl_error($ch);
curl_close($ch);

if ($httpCode !== 200 || !$raw) {
    echo json_encode(['success' => false, 'error' => 'DEBUG: Vision API HTTP ' . $httpCode . ($curlErr ? ' | cURL: ' . $curlErr : '') . ' — ' . substr($raw, 0, 300)]);
    exit;
}

$result   = json_decode($raw, true);
$raw_text = $result['responses'][0]['fullTextAnnotation']['text']
          ?? $result['responses'][0]['textAnnotations'][0]['description']
          ?? '';

if (!$raw_text) {
    echo json_encode(['success' => false, 'error' => 'Could not extract text. Please fill in manually.']);
    exit;
}

// ── Parse extracted text ──────────────────────────────────────
$fields = parsePermitText($raw_text);

echo json_encode([
    'success' => true,
    'fields'  => $fields,
]);
exit;


// ── Parser (same logic as ocr_permit.php) ────────────────────
function parsePermitText(string $text): array
{
    $lines = array_map('trim', explode("\n", $text));
    $lines = array_filter($lines, fn($l) => strlen($l) > 1);
    $lines = array_values($lines);

    $fields = ['business_name' => '', 'owner_name' => '', 'address' => ''];

    // Business Name
    $biz_patterns = [
        '/(?:BUSINESS\s+NAME|TRADE\s+NAME|NAME\s+OF\s+BUSINESS|ESTABLISHMENT\s+NAME)\s*[:\-]?\s*(.+)/i',
        '/(?:REGISTERED\s+NAME)\s*[:\-]?\s*(.+)/i',
    ];
    foreach ($biz_patterns as $pattern) {
        if (preg_match($pattern, $text, $m)) {
            $fields['business_name'] = trim($m[1]);
            break;
        }
    }

    // Owner Name
    $owner_patterns = [
        '/(?:OWNER|PROPRIETOR|REGISTERED\s+OWNER|APPLICANT|LICENSEE)\s*[:\-]?\s*(.+)/i',
        '/(?:NAME\s+OF\s+OWNER|NAME\s+OF\s+PROPRIETOR)\s*[:\-]?\s*(.+)/i',
    ];
    foreach ($owner_patterns as $pattern) {
        if (preg_match($pattern, $text, $m)) {
            $val = trim($m[1]);
            if (strlen($val) < 80 && !preg_match('/PERMIT|LICENSE|BUSINESS|TRADE/i', $val)) {
                $fields['owner_name'] = $val;
                break;
            }
        }
    }

    // Address
    $addr_patterns = [
        '/(?:ADDRESS|BUSINESS\s+ADDRESS|LOCATION|PLACE\s+OF\s+BUSINESS)\s*[:\-]?\s*(.+)/i',
        '/(?:BARANGAY|BRGY\.?|SITIO)\s+.{3,}/i',
    ];
    foreach ($addr_patterns as $pattern) {
        if (preg_match($pattern, $text, $m)) {
            $fields['address'] = trim($m[1]);
            break;
        }
    }

    // Fallback: prominent ALL-CAPS line for business name
    if (empty($fields['business_name'])) {
        foreach ($lines as $line) {
            if (strlen($line) < 5) continue;
            if (preg_match('/\d{4}|\bREPUBLIC\b|\bPROVINCE\b|\bCITY\b|\bMUNICIPALITY\b|\bBUSINESS PERMIT\b/i', $line)) continue;
            if ($line === strtoupper($line) && strlen($line) > 5 && strlen($line) < 60) {
                $fields['business_name'] = ucwords(strtolower($line));
                break;
            }
        }
    }

    // Clean up
    foreach ($fields as &$val) {
        $val = preg_replace('/\s+/', ' ', $val);
        $val = trim($val, " \t\n\r\0\x0B-:;,.");
        if (strlen($val) > 120) $val = substr($val, 0, 120);
    }

    return $fields;
}