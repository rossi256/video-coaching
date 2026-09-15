<?php
/**
 * WingCoach — Reply endpoint (token-gated)
 * GET  /api/reply/:token              — JSON data for reply page
 * GET  /api/reply/:token/video/:fn    — serve reply video with Range support
 * POST /api/reply/:token/rating       — {rating 1-5, comment?}; a second post replaces the first
 * POST /api/reply/:token/question     — {text}; QUESTION_CAP per submission
 *
 * Routed via .htaccess
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers/file-serve.php';
require_once __DIR__ . '/helpers/rider-loop.php';
setApiHeaders();

$token = $_GET['token'] ?? '';
if (!$token) jsonResponse(['error' => 'Missing token'], 400);

$db = getDb();
$stmt = $db->prepare('SELECT * FROM submissions WHERE token = ?');
$stmt->execute([$token]);
$sub = $stmt->fetch();
if (!$sub) jsonResponse(['error' => 'Not found'], 404);

$action = $_GET['_action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

// --- POST /api/reply/:token/rating and /question ---
if ($action === 'rating' || $action === 'question') {
    if ($method !== 'POST') jsonResponse(['error' => 'Method not allowed'], 405);

    $raw = getRawBody();
    if (strlen($raw) > RIDER_BODY_MAX) jsonResponse(['error' => 'That is too long to send'], 413);
    if (!riderPostAllowed($db, $token)) {
        jsonResponse(['error' => 'Too many sends in a row - give it an hour and try again'], 429);
    }
    $body = json_decode($raw, true) ?: [];

    if ($action === 'rating') {
        $rating = (int) ($body['rating'] ?? 0);
        if ($rating < 1 || $rating > 5) jsonResponse(['error' => 'Pick one to five stars'], 400);
        $comment = trim((string) ($body['comment'] ?? ''));
        if (mb_strlen($comment) > RIDER_COMMENT_MAX) {
            jsonResponse(['error' => 'Keep the line under ' . RIDER_COMMENT_MAX . ' characters'], 400);
        }
        $db->prepare(
            'INSERT INTO reply_ratings (submission_id, rating, comment)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE rating = VALUES(rating), comment = VALUES(comment), updated_at = NOW()'
        )->execute([$sub['id'], $rating, $comment !== '' ? $comment : null]);

        jsonResponse(['ok' => true] + riderLoopPayload($db, $sub));
    }

    // question
    $text = trim((string) ($body['text'] ?? ''));
    if ($text === '') jsonResponse(['error' => 'Write your question first'], 400);
    if (mb_strlen($text) > RIDER_QUESTION_MAX) {
        jsonResponse(['error' => 'Keep it under ' . RIDER_QUESTION_MAX . ' characters'], 400);
    }

    // Count on the server rather than trusting the page: two fast taps must not make three.
    $count = $db->prepare('SELECT COUNT(*) FROM reply_questions WHERE submission_id = ?');
    $count->execute([$sub['id']]);
    $asked = (int) $count->fetchColumn();
    if ($asked >= QUESTION_CAP) {
        jsonResponse([
            'error' => 'You have used your ' . QUESTION_CAP . ' questions for this video - book the next one to keep going',
            'questionsLeft' => 0,
        ], 400);
    }

    $db->prepare('INSERT INTO reply_questions (submission_id, question) VALUES (?, ?)')
       ->execute([$sub['id'], $text]);
    $questionId = (int) $db->lastInsertId();

    try {
        require_once __DIR__ . '/helpers/email.php';
        sendQuestionToCoach($sub, $questionId, $text, QUESTION_CAP - $asked - 1);
    } catch (\Exception $e) {
        error_log('Question-to-coach email error: ' . $e->getMessage());
    }

    jsonResponse(['ok' => true, 'questionId' => $questionId] + riderLoopPayload($db, $sub));
}

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
] + riderLoopPayload($db, $sub));
