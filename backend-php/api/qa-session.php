<?php
/**
 * Q&A Session API — dual-purpose endpoint
 * GET  /api/qa-sessions           → list upcoming sessions (public)
 * POST /api/qa-sessions/ID/signup → register for a session
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers/email.php';

setApiHeaders();

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['_action'] ?? '';
$sessionId = (int) ($_GET['session_id'] ?? 0);

// GET — list upcoming sessions
if ($method === 'GET' && !$action) {
    $db = getDb();
    $rows = $db->query("
        SELECT s.id, s.title, s.description, s.scheduled_at, s.duration_minutes,
               s.max_participants, s.status, s.type, s.external_url, s.created_at,
            (SELECT COUNT(*) FROM qa_signups WHERE session_id = s.id) AS signups
        FROM qa_sessions s
        WHERE s.status = 'upcoming' AND s.scheduled_at > NOW()
        ORDER BY s.scheduled_at ASC
    ")->fetchAll();

    // Add spots_remaining to each row
    foreach ($rows as &$row) {
        $row['spots_remaining'] = max(0, (int)$row['max_participants'] - (int)$row['signups']);
        // Don't expose meeting link publicly
        unset($row['meeting_link']);
    }

    jsonResponse($rows);
}

// POST — signup for a session
if ($method === 'POST' && $action === 'signup') {
    $data = getJsonBody();

    // Honeypot
    if (!empty($data['website'])) {
        jsonResponse(['ok' => true]);
    }

    $name    = trim($data['name'] ?? '');
    $email   = trim($data['email'] ?? '');
    $message = trim($data['message'] ?? '');

    if (!$name || !$email) {
        jsonResponse(['error' => 'Name and email are required.'], 400);
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        jsonResponse(['error' => 'Invalid email address.'], 400);
    }

    if (!$sessionId) {
        jsonResponse(['error' => 'Session ID is required.'], 400);
    }

    $db = getDb();

    // Check session exists and is upcoming
    $stmt = $db->prepare("SELECT * FROM qa_sessions WHERE id = ? AND status = 'upcoming' AND scheduled_at > NOW()");
    $stmt->execute([$sessionId]);
    $session = $stmt->fetch();

    if (!$session) {
        jsonResponse(['error' => 'Session not found or no longer available.'], 404);
    }

    // Check capacity
    $countStmt = $db->prepare('SELECT COUNT(*) FROM qa_signups WHERE session_id = ?');
    $countStmt->execute([$sessionId]);
    $currentSignups = (int) $countStmt->fetchColumn();

    if ($currentSignups >= (int) $session['max_participants']) {
        jsonResponse(['error' => 'This session is full.'], 400);
    }

    // Insert signup (UNIQUE constraint handles duplicate email per session)
    try {
        $ins = $db->prepare('INSERT INTO qa_signups (session_id, name, email, message) VALUES (?, ?, ?, ?)');
        $ins->execute([$sessionId, $name, $email, $message]);
    } catch (\PDOException $e) {
        if ($e->getCode() == 23000) {
            jsonResponse(['error' => 'You are already registered for this session.'], 409);
        }
        throw $e;
    }

    // Send confirmation to registrant
    try {
        sendQaSignupConfirmation($email, $name, $session);
    } catch (\Exception $e) {
        error_log('QA signup confirmation email failed: ' . $e->getMessage());
    }

    // Notify admin
    try {
        sendQaSignupNotification($name, $email, $session, $message);
    } catch (\Exception $e) {
        error_log('QA signup admin notification failed: ' . $e->getMessage());
    }

    jsonResponse(['ok' => true]);
}

jsonResponse(['error' => 'Invalid request'], 400);
