<?php
/**
 * WingCoach — Reply endpoint (token-gated)
 * GET /api/reply/:token              — JSON data for reply page
 * GET /api/reply/:token/video/:fn    — serve reply video with Range support
 *
 * Routed via .htaccess
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers/file-serve.php';
setApiHeaders();

$token = $_GET['token'] ?? '';
if (!$token) jsonResponse(['error' => 'Missing token'], 400);

$db = getDb();
$stmt = $db->prepare('SELECT * FROM submissions WHERE token = ?');
$stmt->execute([$token]);
$sub = $stmt->fetch();
if (!$sub) jsonResponse(['error' => 'Not found'], 404);

// Serve video file
$videoFilename = $_GET['_video'] ?? '';
if ($videoFilename) {
    // Security: no path traversal
    if (str_contains($videoFilename, '/') || str_contains($videoFilename, '\\') || str_starts_with($videoFilename, '.')) {
        http_response_code(400);
        echo 'Invalid filename';
        exit;
    }

    $filePath = UPLOADS_DIR . '/' . $sub['id'] . '/reply/' . $videoFilename;
    $safeBase = realpath(UPLOADS_DIR . '/' . $sub['id'] . '/reply');
    if (!$safeBase || !file_exists($filePath)) {
        http_response_code(404);
        echo 'Not found';
        exit;
    }
    if (!str_starts_with(realpath($filePath), $safeBase)) {
        http_response_code(403);
        echo 'Forbidden';
        exit;
    }

    serveFile($filePath);
}

// GET reply data
$replyDir = UPLOADS_DIR . '/' . $sub['id'] . '/reply';
$replyFiles = [];
if (is_dir($replyDir)) {
    foreach (scandir($replyDir) as $f) {
        if ($f[0] !== '.') $replyFiles[] = $f;
    }
}

$itemStmt = $db->prepare('SELECT * FROM reply_items WHERE submission_id = ? ORDER BY order_index ASC, id ASC');
$itemStmt->execute([$sub['id']]);
$replyItems = $itemStmt->fetchAll();

jsonResponse([
    'name' => $sub['name'],
    'status' => $sub['status'],
    'replyFiles' => $replyFiles,
    'replyItems' => $replyItems,
    'token' => $sub['token'],
    'feedbackSentAt' => $sub['feedback_sent_at'],
]);
