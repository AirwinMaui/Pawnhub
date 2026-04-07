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
$cmd = escapeshellcmd("tesseract " . escapeshellarg($tmpPath) . " " . escapeshellarg($outputBase) . " -l eng 2>/dev/null");
exec($cmd, $out, $code);

$textFile = $outputBase . '.txt';
if (!file_exists($textFile)) {
    unlink($tmpPath);
    echo json_encode(['error' => 'OCR failed. Tesseract may not be installed.']);
    exit;
}

$text = file_get_contents($textFile);

// Cleanup temp files
unlink($tmpPath);
unlink($textFile);

// ── Parse extracted text ──────────────────────────────────────────────────────
$lines = array_filter(array_map('trim', explode("\n", $text)));

$business_name = '';
$owner_name    = '';
$address       = '';
$phone         = '';

foreach ($lines as $line) {
    $lower = strtolower($line);

    // Business name
    if (!$business_name && preg_match('/business name[:\s]+(.+)/i', $line, $m)) {
        $business_name = trim($m[1]);
    }

    // Owner / registrant name
    if (!$owner_name && preg_match('/(?:owner|registrant|proprietor|issued to)[:\s]+(.+)/i', $line, $m)) {
        $owner_name = trim($m[1]);
    }

    // Address
    if (!$address && preg_match('/address[:\s]+(.+)/i', $line, $m)) {
        $address = trim($m[1]);
    }

    // Phone — Philippine format
    if (!$phone && preg_match('/(\+?63[\s\-]?|0)(9\d{2})[\s\-]?(\d{3})[\s\-]?(\d{4})/', $line, $m)) {
        $phone = preg_replace('/[\s\-]/', '', $m[0]);
    }
}

echo json_encode([
    'success' => true,
    'data'    => [
        'business_name' => $business_name,
        'owner_name'    => $owner_name,
        'address'       => $address,
        'phone'         => $phone,
    ]
]);