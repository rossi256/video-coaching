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
    $source  = trim($data['source'] ?? 'web');
    if (!in_array($source, ['web', 'instagram', 'facebook', 'telegram', 'whatsapp'], true)) {
        $source = 'web';
    }

    // Optional interest topics (multi-choice on the signup form). Whitelisted
    // keys only, stored as CSV — informational, helps Michi prep the session.
    $allowedInterests = ['gear', 'technique', 'events', 'coaching', 'books', 'footstraps', 'other'];
    $interests = $data['interests'] ?? [];
    if (!is_array($interests)) $interests = [];
    $interests = array_values(array_intersect($allowedInterests, array_map('strval', $interests)));
    $interestsCsv = implode(',', $interests);

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

    // Atomic capacity-checked insert: the row only lands while the session still
    // has room, so two racing signups can never overbook. The UNIQUE constraint
    // still catches duplicate emails per session.
    try {
        $ins = $db->prepare('
            INSERT INTO qa_signups (session_id, name, email, message, source, interests)
            SELECT ?, ?, ?, ?, ?, ? FROM DUAL
            WHERE (SELECT COUNT(*) FROM qa_signups WHERE session_id = ?) < ?
        ');
        $ins->execute([$sessionId, $name, $email, $message, $source, $interestsCsv, $sessionId, (int) $session['max_participants']]);
    } catch (\PDOException $e) {
        if ($e->getCode() == 23000) {
            // Already registered: answer exactly like a fresh signup (a distinct
            // "already registered" reply would let anyone probe which emails are
            // on the list) — but RE-SEND the confirmation. The mail only ever
            // goes to the address owner, and "signed up again because I lost the
            // link" is the common real-world case.
            try {
                sendQaSignupConfirmation($email, $name, $session);
            } catch (\Exception $mailErr) {
                error_log('QA duplicate-signup resend failed: ' . $mailErr->getMessage());
            }
            jsonResponse(['ok' => true]);
        }
        throw $e;
    }

    if ($ins->rowCount() === 0) {
        // Insert was blocked by the capacity guard. If this email is already on
        // the list, keep the idempotent success answer instead of "full".
        $dup = $db->prepare('SELECT 1 FROM qa_signups WHERE session_id = ? AND email = ?');
        $dup->execute([$sessionId, $email]);
        if ($dup->fetchColumn()) {
            // Same re-send courtesy as the UNIQUE-violation path above.
            try {
                sendQaSignupConfirmation($email, $name, $session);
            } catch (\Exception $mailErr) {
                error_log('QA duplicate-signup resend failed: ' . $mailErr->getMessage());
            }
            jsonResponse(['ok' => true]);
        }
        jsonResponse(['error' => 'This session is full.'], 400);
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
