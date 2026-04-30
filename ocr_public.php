<?php
/**
 * ocr_public.php
 * Uses Tesseract OCR — free, no API key, runs on server.
 */

header('Content-Type: application/json');

// ── Rate limiting ─────────────────────────────────────────────
$rate_file = sys_get_temp_dir() . '/ocr_rl_' . md5($_SERVER['REMOTE_ADDR'] ?? 'x');
$now = time();
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

if ($file['size'] > 10 * 1024 * 1024) {
    echo json_encode(['success' => false, 'error' => 'File too large. Max 10MB.']);
    exit;
}

// ── Prepare temp paths ────────────────────────────────────────
$tmp_dir    = sys_get_temp_dir();
$unique     = 'ocr_' . uniqid('', true);
$input_file = $tmp_dir . '/' . $unique . '_input';
$out_base   = $tmp_dir . '/' . $unique . '_out';
$out_txt    = $out_base . '.txt';

move_uploaded_file($file['tmp_name'], $input_file);

// ── If PDF, convert first page to image ──────────────────────
$ocr_input = $input_file;
$pdf_page  = $tmp_dir . '/' . $unique . '_page';

if ($mime === 'application/pdf') {
    exec('pdftoppm -r 200 -png -l 1 ' . escapeshellarg($input_file) . ' ' . escapeshellarg($pdf_page) . ' 2>&1');
    $candidates = glob($pdf_page . '*.png');
    if (!empty($candidates)) {
        $ocr_input = $candidates[0];
    } else {
        $fallback = $tmp_dir . '/' . $unique . '_fb.png';
        exec('convert -density 200 ' . escapeshellarg($input_file . '[0]') . ' ' . escapeshellarg($fallback) . ' 2>&1');
        if (file_exists($fallback)) $ocr_input = $fallback;
    }
}

// ── Detect language packs ─────────────────────────────────────
exec('tesseract --list-langs 2>&1', $lang_list);
$lang = 'eng';
if (in_array('fil', $lang_list)) $lang = 'eng+fil';
elseif (in_array('tgl', $lang_list)) $lang = 'eng+tgl';

// ── Run Tesseract ─────────────────────────────────────────────
$cmd = 'tesseract ' . escapeshellarg($ocr_input) . ' ' . escapeshellarg($out_base)
     . ' -l ' . escapeshellarg($lang)
     . ' --psm 3 --oem 3 2>&1';
exec($cmd, $tess_out, $tess_ret);

// ── Cleanup ───────────────────────────────────────────────────
@unlink($input_file);
foreach (glob($pdf_page . '*.png') as $f) @unlink($f);
@unlink($tmp_dir . '/' . $unique . '_fb.png');

// ── Read result ───────────────────────────────────────────────
if ($tess_ret !== 0 || !file_exists($out_txt)) {
    @unlink($out_txt);
    echo json_encode(['success' => false, 'error' => 'Could not extract text. Try a clearer photo of your permit.']);
    exit;
}

$raw_text = file_get_contents($out_txt);
@unlink($out_txt);

if (!trim($raw_text)) {
    echo json_encode(['success' => false, 'error' => 'No text found. Please upload a clearer photo of your business permit.']);
    exit;
}

// ── Parse fields ──────────────────────────────────────────────
$fields = parsePermitText($raw_text);

echo json_encode(['success' => true, 'fields' => $fields]);
exit;


function parsePermitText(string $text): array
{
    $lines  = array_values(array_filter(array_map('trim', explode("\n", $text)), fn($l) => strlen($l) > 1));
    $fields = ['business_name' => '', 'owner_name' => '', 'address' => ''];

    // ── Business Name ─────────────────────────────────────────
    // Matches PH Mayor's Permit format: "Business Name: XXXXX"
    // Also matches: TRADE NAME, NAME OF BUSINESS, ESTABLISHMENT NAME
    $biz_patterns = [
        '/Business\s+Name\s*[:\-]\s*(.+)/i',
        '/(?:TRADE\s+NAME|NAME\s+OF\s+BUSINESS|ESTABLISHMENT\s+NAME|REGISTERED\s+NAME)\s*[:\-]\s*(.+)/i',
    ];
    foreach ($biz_patterns as $p) {
        if (preg_match($p, $text, $m)) {
            $val = trim($m[1]);
            if (strlen($val) > 1 && strlen($val) < 120) {
                $fields['business_name'] = $val;
                break;
            }
        }
    }

    // ── Owner / Proprietor ────────────────────────────────────
    // Matches: "Owner / Proprietor:", "Owner:", "Proprietor:", "Applicant:", etc.
    $owner_patterns = [
        '/Owner\s*[\/\\\\]?\s*Proprietor\s*[:\-]\s*(.+)/i',
        '/(?:OWNER|PROPRIETOR|REGISTERED\s+OWNER|APPLICANT|LICENSEE|ISSUED\s+TO|GRANTED\s+TO)\s*[:\-]\s*(.+)/i',
        '/(?:NAME\s+OF\s+OWNER|NAME\s+OF\s+PROPRIETOR)\s*[:\-]\s*(.+)/i',
    ];
    foreach ($owner_patterns as $p) {
        if (preg_match($p, $text, $m)) {
            $val = trim($m[1]);
            if (strlen($val) < 80 && !preg_match('/PERMIT|LICENSE|BUSINESS|TRADE|NATURE/i', $val)) {
                $fields['owner_name'] = $val;
                break;
            }
        }
    }

    // ── Address ───────────────────────────────────────────────
    // Matches: "Business Address:", "Address:", "Location:", etc.
    $addr_patterns = [
        '/Business\s+Address\s*[:\-]\s*(.+)/i',
        '/(?:ADDRESS|LOCATION|PLACE\s+OF\s+BUSINESS)\s*[:\-]\s*(.+)/i',
        '/(?:BARANGAY|BRGY\.?)\s+[\w\s,]+(?:,\s*[\w\s]+){1,2}/i',
    ];
    foreach ($addr_patterns as $p) {
        if (preg_match($p, $text, $m)) {
            $val = trim($m[1]);
            if (strlen($val) > 3 && strlen($val) < 200) {
                $fields['address'] = $val;
                break;
            }
        }
    }

    // ── Fallback: ALL-CAPS line as business name ──────────────
    if (empty($fields['business_name'])) {
        foreach ($lines as $line) {
            if (strlen($line) < 5 || strlen($line) > 60) continue;
            if (preg_match('/\d{4}|\bREPUBLIC\b|\bPROVINCE\b|\bCITY\b|\bMUNICIPALITY\b|\bPERMIT\b|\bOFFICE\b/i', $line)) continue;
            if ($line === strtoupper($line) && preg_match('/[A-Z]{3}/', $line)) {
                $fields['business_name'] = ucwords(strtolower($line));
                break;
            }
        }
    }

    // ── Clean all fields ──────────────────────────────────────
    foreach ($fields as &$val) {
        $val = trim(preg_replace('/\s+/', ' ', $val), " \t\n\r\0\x0B-:;,.");
        if (strlen($val) > 120) $val = substr($val, 0, 120);
    }

    return $fields;
}