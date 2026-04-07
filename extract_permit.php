<?php
header('Content-Type: application/json');

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

// Save temp file
$tmpPath = tempnam(sys_get_temp_dir(), 'permit_') . '.png';
move_uploaded_file($file['tmp_name'], $tmpPath);

// Run Tesseract OCR
$outputBase = tempnam(sys_get_temp_dir(), 'ocr_out_');
exec("tesseract " . escapeshellarg($tmpPath) . " " . escapeshellarg($outputBase) . " -l eng 2>/dev/null", $out, $code);

$textFile = $outputBase . '.txt';
if (!file_exists($textFile)) {
    unlink($tmpPath);
    echo json_encode(['error' => 'OCR failed.']);
    exit;
}

$text = file_get_contents($textFile);
unlink($tmpPath);
unlink($textFile);

// ── Parse text ────────────────────────────────────────────────────────────────
$lines = array_filter(array_map('trim', explode("\n", $text)));

$business_name = '';
$owner_name    = '';
$address       = '';
$phone         = '';

foreach ($lines as $line) {
    if (!$business_name && preg_match('/business name[:\s]+(.+)/i', $line, $m))
        $business_name = trim($m[1]);

    if (!$owner_name && preg_match('/(?:owner|registrant|proprietor|issued to)[:\s]+(.+)/i', $line, $m))
        $owner_name = trim($m[1]);

    if (!$address && preg_match('/address[:\s]+(.+)/i', $line, $m))
        $address = trim($m[1]);

    if (!$phone && preg_match('/(\+?63[\s\-]?|0)(9\d{2})[\s\-]?(\d{3})[\s\-]?(\d{4})/', $line, $m))
        $phone = preg_replace('/[\s\-]/', '', $m[0]);
}

echo json_encode([
    'success' => true,
    'data'    => compact('business_name', 'owner_name', 'address', 'phone')
]);