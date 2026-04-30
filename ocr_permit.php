<?php
/**
 * ocr_permit.php
 * ─────────────────────────────────────────────────────────────
 * SA uploads a business permit image/PDF.
 * Google Cloud Vision OCR extracts text, then we parse
 * business name, owner name, address from the raw text.
 *
 * Returns JSON: { success, fields: { business_name, owner_name, address }, raw_text }
 *
 * Setup:
 *   1. Enable "Cloud Vision API" in Google Cloud Console
 *   2. Create an API key (restrict to Cloud Vision API)
 *   3. Set GOOGLE_VISION_API_KEY in paymongo_config.php
 *      e.g.  define('GOOGLE_VISION_API_KEY', 'AIza...');
 * ─────────────────────────────────────────────────────────────
 */

require_once __DIR__ . '/session_helper.php';
pawnhub_session_start('admin');
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/paymongo_config.php';   // GOOGLE_VISION_API_KEY defined here

header('Content-Type: application/json');

// ── Auth: Super Admin only ────────────────────────────────────
if (empty($_SESSION['user']) || $_SESSION['user']['role'] !== 'super_admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

// ── Validate upload ───────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_FILES['permit'])) {
    echo json_encode(['success' => false, 'error' => 'No file uploaded.']);
    exit;
}

$file      = $_FILES['permit'];
$mime      = mime_content_type($file['tmp_name']);
$allowed   = ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'application/pdf'];

if (!in_array($mime, $allowed)) {
    echo json_encode(['success' => false, 'error' => 'Invalid file type. Upload JPG, PNG, WEBP, or PDF.']);
    exit;
}

if ($file['size'] > 10 * 1024 * 1024) {
    echo json_encode(['success' => false, 'error' => 'File too large. Max 10MB.']);
    exit;
}

// ── Read file and base64-encode ───────────────────────────────
$image_data = base64_encode(file_get_contents($file['tmp_name']));

// ── Determine Vision API type ─────────────────────────────────
// For PDFs: use DOCUMENT_TEXT_DETECTION via files/annotate (different endpoint)
// For images: use TEXT_DETECTION
$is_pdf = ($mime === 'application/pdf');

if ($is_pdf) {
    // Google Vision does not support inline PDF — use DOCUMENT_TEXT_DETECTION
    // with the inputConfig source. Requires GCS for large PDFs, but for small
    // permits we can use the inline base64 approach via the v1/images endpoint
    // with type DOCUMENT_TEXT_DETECTION (works for single-page PDFs up to 10MB).
    $request_type = 'DOCUMENT_TEXT_DETECTION';
} else {
    $request_type = 'TEXT_DETECTION';
}

$api_key = defined('GOOGLE_VISION_API_KEY') ? GOOGLE_VISION_API_KEY : '';
if (!$api_key) {
    echo json_encode(['success' => false, 'error' => 'Google Vision API key not configured. Set GOOGLE_VISION_API_KEY in paymongo_config.php.']);
    exit;
}

// ── Call Google Cloud Vision API ─────────────────────────────
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
curl_close($ch);

if ($httpCode !== 200 || !$raw) {
    error_log('[OCR] Vision API error: ' . $raw);
    echo json_encode(['success' => false, 'error' => 'Google Vision API error. Check your API key and try again.']);
    exit;
}

$result   = json_decode($raw, true);
$raw_text = $result['responses'][0]['fullTextAnnotation']['text']
          ?? $result['responses'][0]['textAnnotations'][0]['description']
          ?? '';

if (!$raw_text) {
    echo json_encode(['success' => false, 'error' => 'Could not extract text from the image. Try a clearer photo.']);
    exit;
}

// ── Parse extracted text ──────────────────────────────────────
$fields = parsePermitText($raw_text);

echo json_encode([
    'success'  => true,
    'fields'   => $fields,
    'raw_text' => $raw_text,   // SA can see full extracted text for debugging
]);
exit;


// ────────────────────────────────────────────────────────────────────────────
// Helper: Parse business permit text into structured fields
// Philippine business permits typically contain:
//   BUSINESS NAME / TRADE NAME, OWNER / PROPRIETOR, ADDRESS
// ────────────────────────────────────────────────────────────────────────────
function parsePermitText(string $text): array
{
    $lines = array_map('trim', explode("\n", $text));
    $lines = array_filter($lines, fn($l) => strlen($l) > 1);
    $lines = array_values($lines);

    $fields = [
        'business_name' => '',
        'owner_name'    => '',
        'address'       => '',
    ];

    $text_upper = strtoupper($text);

    // ── Business Name ─────────────────────────────────────────
    // Look for labels: BUSINESS NAME, TRADE NAME, NAME OF BUSINESS, ESTABLISHMENT
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

    // ── Owner / Proprietor Name ───────────────────────────────
    $owner_patterns = [
        '/(?:OWNER|PROPRIETOR|REGISTERED\s+OWNER|APPLICANT|LICENSEE)\s*[:\-]?\s*(.+)/i',
        '/(?:NAME\s+OF\s+OWNER|NAME\s+OF\s+PROPRIETOR)\s*[:\-]?\s*(.+)/i',
    ];
    foreach ($owner_patterns as $pattern) {
        if (preg_match($pattern, $text, $m)) {
            $val = trim($m[1]);
            // Ignore if it looks like a label line or is too long
            if (strlen($val) < 80 && !preg_match('/PERMIT|LICENSE|BUSINESS|TRADE/i', $val)) {
                $fields['owner_name'] = $val;
                break;
            }
        }
    }

    // ── Address ───────────────────────────────────────────────
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

    // ── Fallback: if business_name empty, try to find a prominent ALL-CAPS line ──
    if (empty($fields['business_name'])) {
        foreach ($lines as $i => $line) {
            // Skip short lines, header words, and lines with digits (dates/amounts)
            if (strlen($line) < 5) continue;
            if (preg_match('/\d{4}|\bREPUBLIC\b|\bPROVINCE\b|\bCITY\b|\bMUNICIPALITY\b|\bBUSINESS PERMIT\b/i', $line)) continue;
            if ($line === strtoupper($line) && strlen($line) > 5 && strlen($line) < 60) {
                // Looks like a business name in all-caps
                if (empty($fields['business_name'])) {
                    $fields['business_name'] = ucwords(strtolower($line));
                }
            }
        }
    }

    // ── Clean up each field ───────────────────────────────────
    foreach ($fields as &$val) {
        // Remove stray OCR artifacts: multiple spaces, newlines
        $val = preg_replace('/\s+/', ' ', $val);
        $val = trim($val, " \t\n\r\0\x0B-:;,.");
        // Truncate if suspiciously long (OCR grabbed multiple lines)
        if (strlen($val) > 120) {
            $val = substr($val, 0, 120);
        }
    }

    return $fields;
}