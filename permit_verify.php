<?php
/**
 * permit_verify.php
 * ─────────────────────────────────────────────────────────────
 * Called automatically after signup OR after paymongo_success.php
 * Uses Google Gemini Vision API (FREE) to verify business permit.
 *
 * Checks:
 *   1. Is it a real Philippine Business Permit / Mayor's Permit?
 *   2. Is it NOT expired? (Valid Until date must be in the future)
 *   3. Does the Business Name match what the tenant registered?
 *   4. Is Nature of Business related to pawnshop/lending/jewelry?
 *
 * Updates tenants table:
 *   business_permit_status = 'ai_approved' | 'ai_rejected' | 'manual_review'
 *   business_permit_data   = JSON with extracted fields
 *   rejection_reason       = string if rejected
 *   ocr_verified           = 1 if approved
 *
 * Can be called:
 *   A) Internally from paymongo_success.php / signup flow
 *   B) Via CLI: php permit_verify.php <tenant_id>
 *   C) Via POST: { tenant_id: X } — used by superadmin re-verify
 * ─────────────────────────────────────────────────────────────
 */

// ── Allow CLI invocation ──────────────────────────────────────
$is_cli = (PHP_SAPI === 'cli');

if (!$is_cli) {
    require_once __DIR__ . '/db.php';
    require_once __DIR__ . '/paymongo_config.php';
} else {
    require_once __DIR__ . '/db.php';
    require_once __DIR__ . '/paymongo_config.php';
}

/**
 * Main verification function.
 * Returns array: ['status' => 'ai_approved'|'ai_rejected'|'manual_review', 'data' => [...], 'reason' => '...']
 */
function verifyBusinessPermit(int $tenant_id, PDO $pdo): array {

    // ── Fetch tenant ──────────────────────────────────────────
    $stmt = $pdo->prepare("SELECT * FROM tenants WHERE id = ? LIMIT 1");
    $stmt->execute([$tenant_id]);
    $tenant = $stmt->fetch();

    if (!$tenant) {
        return ['status' => 'manual_review', 'data' => [], 'reason' => 'Tenant not found.'];
    }

    $permit_url = $tenant['business_permit_url'] ?? '';
    $biz_name   = strtolower(trim($tenant['business_name'] ?? ''));

    if (empty($permit_url)) {
        return ['status' => 'manual_review', 'data' => [], 'reason' => 'No business permit uploaded.'];
    }

    // ── Load image ────────────────────────────────────────────
    $permit_path = __DIR__ . '/' . ltrim($permit_url, '/');

    if (!file_exists($permit_path)) {
        return ['status' => 'manual_review', 'data' => [], 'reason' => 'Permit file not found on server.'];
    }

    $image_data  = file_get_contents($permit_path);
    $image_b64   = base64_encode($image_data);
    $ext         = strtolower(pathinfo($permit_path, PATHINFO_EXTENSION));
    $mime_map    = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif', 'pdf' => 'application/pdf'];
    $mime_type   = $mime_map[$ext] ?? 'image/jpeg';

    // ── Build Gemini prompt ───────────────────────────────────
    $today  = date('Y-m-d');
    $prompt = <<<PROMPT
You are a Philippine business document verification system.

Analyze this uploaded document image carefully and extract information from it.

Please respond ONLY with a valid JSON object (no markdown, no explanation, just pure JSON) with this exact structure:

{
  "is_business_permit": true or false,
  "business_name": "extracted business name or empty string",
  "owner_name": "extracted owner/proprietor name or empty string",
  "nature_of_business": "extracted nature of business or empty string",
  "valid_until": "extracted date in YYYY-MM-DD format or empty string",
  "permit_number": "extracted permit number or empty string",
  "is_expired": true or false,
  "is_pawnshop_related": true or false,
  "confidence": "high" or "medium" or "low",
  "notes": "any important observations about the document"
}

Rules for is_expired: Set to true if Valid Until date is before {$today}.
Rules for is_pawnshop_related: Set to true if Nature of Business mentions pawnshop, lending, jewelry, money changer, or similar financial services.
Rules for is_business_permit: Set to true only if this is clearly a Philippine Business Permit or Mayor's Permit from a City/Municipal government.

The registered business name to cross-check is: "{$biz_name}"
PROMPT;

    // ── Call Gemini Vision API ────────────────────────────────
    $gemini_key = defined('GEMINI_API_KEY') ? GEMINI_API_KEY : '';
    if (empty($gemini_key)) {
        return ['status' => 'manual_review', 'data' => [], 'reason' => 'Gemini API key not configured.'];
    }

    $gemini_url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key={$gemini_key}";

    $payload = [
        'contents' => [[
            'parts' => [
                [
                    'inline_data' => [
                        'mime_type' => $mime_type,
                        'data'      => $image_b64,
                    ]
                ],
                [
                    'text' => $prompt
                ]
            ]
        ]],
        'generationConfig' => [
            'temperature'     => 0.1,
            'maxOutputTokens' => 1024,
        ]
    ];

    $ch = curl_init($gemini_url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_TIMEOUT        => 30,
    ]);
    $raw      = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        error_log("[PermitVerify] Gemini API error {$httpCode}: {$raw}");
        return ['status' => 'manual_review', 'data' => [], 'reason' => "AI service error (HTTP {$httpCode}). Please review manually."];
    }

    // ── Parse Gemini response ─────────────────────────────────
    $gemini_response = json_decode($raw, true);
    $text_response   = $gemini_response['candidates'][0]['content']['parts'][0]['text'] ?? '';

    // Strip markdown code fences if any
    $text_response = preg_replace('/```json\s*/i', '', $text_response);
    $text_response = preg_replace('/```\s*/i', '', $text_response);
    $text_response = trim($text_response);

    $extracted = json_decode($text_response, true);

    if (!$extracted || !is_array($extracted)) {
        error_log("[PermitVerify] Failed to parse Gemini response: {$text_response}");
        return ['status' => 'manual_review', 'data' => ['raw' => $text_response], 'reason' => 'AI could not parse the document. Please review manually.'];
    }

    // ── Decision logic ────────────────────────────────────────
    $is_permit      = (bool)($extracted['is_business_permit'] ?? false);
    $is_expired     = (bool)($extracted['is_expired'] ?? false);
    $is_pawnshop    = (bool)($extracted['is_pawnshop_related'] ?? false);
    $confidence     = $extracted['confidence'] ?? 'low';
    $permit_biz     = strtolower(trim($extracted['business_name'] ?? ''));
    $valid_until    = $extracted['valid_until'] ?? '';

    // Check business name similarity (fuzzy match)
    $name_match = false;
    if (!empty($permit_biz) && !empty($biz_name)) {
        // Exact match or one contains the other
        if (str_contains($permit_biz, $biz_name) || str_contains($biz_name, $permit_biz)) {
            $name_match = true;
        }
        // Similar enough (80%+ similarity)
        similar_text($permit_biz, $biz_name, $similarity_pct);
        if ($similarity_pct >= 70) {
            $name_match = true;
        }
    }

    // ── Make decision ─────────────────────────────────────────
    $status = 'manual_review';
    $reason = '';

    if (!$is_permit) {
        $status = 'ai_rejected';
        $reason = 'The uploaded document does not appear to be a valid Philippine Business Permit or Mayor\'s Permit.';

    } elseif ($is_expired) {
        $status = 'ai_rejected';
        $reason = "The business permit appears to be expired (Valid Until: {$valid_until}). Please upload a current, valid permit.";

    } elseif (!$is_pawnshop) {
        $status = 'ai_rejected';
        $reason = 'The Nature of Business on the permit does not indicate pawnshop, lending, or jewelry operations. PawnHub is designed for pawnshop businesses only.';

    } elseif (!$name_match && !empty($permit_biz)) {
        // Name mismatch → flag for manual review instead of hard reject
        $status = 'manual_review';
        $reason = "Business name mismatch: Registered as \"{$tenant['business_name']}\" but permit shows \"{$extracted['business_name']}\". Flagged for manual review.";

    } elseif ($confidence === 'low') {
        $status = 'manual_review';
        $reason = 'AI confidence is low on this document. Please review manually.';

    } else {
        $status = 'ai_approved';
        $reason = '';
    }

    return [
        'status' => $status,
        'data'   => $extracted,
        'reason' => $reason,
    ];
}

/**
 * Save verification result to DB
 */
function saveVerificationResult(int $tenant_id, array $result, PDO $pdo): void {
    $status      = $result['status'];
    $data_json   = json_encode($result['data']);
    $reason      = $result['reason'] ?? '';
    $ocr_verified = ($status === 'ai_approved') ? 1 : 0;

    $pdo->prepare("
        UPDATE tenants SET
            business_permit_status = ?,
            business_permit_data   = ?,
            rejection_reason       = ?,
            ocr_verified           = ?
        WHERE id = ?
    ")->execute([$status, $data_json, $reason, $ocr_verified, $tenant_id]);
}

// ── CLI mode ──────────────────────────────────────────────────
if ($is_cli) {
    $tid = intval($argv[1] ?? 0);
    if (!$tid) {
        echo "Usage: php permit_verify.php <tenant_id>\n";
        exit(1);
    }
    echo "Verifying permit for tenant #{$tid}...\n";
    $result = verifyBusinessPermit($tid, $pdo);
    saveVerificationResult($tid, $result, $pdo);
    echo "Status: {$result['status']}\n";
    echo "Reason: {$result['reason']}\n";
    echo "Data: " . json_encode($result['data'], JSON_PRETTY_PRINT) . "\n";
    exit(0);
}

// ── HTTP POST mode (Super Admin re-verify trigger) ────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Auth check — only super_admin
    require_once __DIR__ . '/session_helper.php';
    pawnhub_session_start('super_admin');
    if (empty($_SESSION['user']) || $_SESSION['user']['role'] !== 'super_admin') {
        http_response_code(403);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }

    header('Content-Type: application/json');
    $tid = intval($_POST['tenant_id'] ?? 0);
    if (!$tid) {
        echo json_encode(['error' => 'Missing tenant_id']);
        exit;
    }

    $result = verifyBusinessPermit($tid, $pdo);
    saveVerificationResult($tid, $result, $pdo);
    echo json_encode(['success' => true, 'result' => $result]);
    exit;
}