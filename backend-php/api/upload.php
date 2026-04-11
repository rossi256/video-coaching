<?php
/**
 * WingCoach — Upload endpoint
 * POST /api/upload/:submissionId       — upload video
 * DELETE /api/upload/:submissionId/:fn  — delete video
 * POST /api/upload/:submissionId/audio  — upload audio recording
 *
 * Routed via .htaccess rewrite rules
 */
require_once __DIR__ . '/config.php';
setApiHeaders();

$submissionId = (int) ($_GET['id'] ?? 0);
if (!$submissionId) jsonResponse(['error' => 'Invalid submission ID'], 400);

$db = getDb();
$stmt = $db->prepare('SELECT id FROM submissions WHERE id = ?');
$stmt->execute([$submissionId]);
if (!$stmt->fetch()) jsonResponse(['error' => 'Submission not found'], 404);

$uploadDir = UPLOADS_DIR . '/' . $submissionId;
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['_action'] ?? 'video';

// POST /api/upload/:id/audio
if ($method === 'POST' && $action === 'audio') {
    if (empty($_FILES['audio'])) {
        jsonResponse(['error' => 'No audio file uploaded'], 400);
    }
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

    $ext = pathinfo($_FILES['audio']['name'], PATHINFO_EXTENSION) ?: 'webm';
    $filename = 'audio.' . $ext;
    $dest = $uploadDir . '/' . $filename;

    if (!move_uploaded_file($_FILES['audio']['tmp_name'], $dest)) {
        jsonResponse(['error' => 'Upload failed'], 500);
    }
    jsonResponse(['filename' => $filename, 'size' => $_FILES['audio']['size']]);
}

// POST /api/upload/:id — upload video
if ($method === 'POST' && $action === 'video') {
    if (empty($_FILES['video'])) {
        jsonResponse(['error' => 'No file uploaded'], 400);
    }

    $file = $_FILES['video'];
    $mime = $file['type'];
    if (!str_starts_with($mime, 'video/') && $mime !== 'application/octet-stream') {
        jsonResponse(['error' => 'Only video files are allowed'], 400);
    }

    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

    $filename = safeName($file['name']);
    $dest = $uploadDir . '/' . $filename;

    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        jsonResponse(['error' => 'Upload failed'], 500);
    }
    jsonResponse([
        'filename' => $filename,
        'originalName' => $file['name'],
        'size' => $file['size'],
    ]);
}

// DELETE /api/upload/:id/:filename
if ($method === 'DELETE') {
    $filename = $_GET['filename'] ?? '';

    // Security: no path traversal
    if (!$filename || str_contains($filename, '/') || str_contains($filename, '\\') || str_starts_with($filename, '.')) {
        jsonResponse(['error' => 'Invalid filename'], 400);
    }

    $filePath = $uploadDir . '/' . $filename;
    $safeBase = realpath($uploadDir);
    if (!$safeBase || !str_starts_with(realpath(dirname($filePath)) . '/', $safeBase . '/')) {
        jsonResponse(['error' => 'Forbidden'], 403);
    }

    if (!file_exists($filePath)) jsonResponse(['error' => 'File not found'], 404);
    unlink($filePath);
    jsonResponse(['success' => true]);
}

jsonResponse(['error' => 'Method not allowed'], 405);
