<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../vendor/autoload.php';
setApiHeaders();

$sessionId = $_GET['session_id'] ?? '';
if (!$sessionId) {
    jsonResponse(['error' => 'Missing session_id'], 400);
}

$db = getDb();

// Dev bypass
if ((str_starts_with($sessionId, 'dev_test_') || str_starts_with($sessionId, 'dev_checkout_')) && DEV_BYPASS) {
    $stmt = $db->prepare('SELECT * FROM submissions WHERE stripe_session_id = ?');
    $stmt->execute([$sessionId]);
    $sub = $stmt->fetch();
    if (!$sub) jsonResponse(['error' => 'Dev session not found'], 404);

    // Heal email from checkout_attempts
    if (!$sub['email']) {
        $aStmt = $db->prepare('SELECT email FROM checkout_attempts WHERE stripe_session_id = ?');
        $aStmt->execute([$sessionId]);
        $devEmail = $aStmt->fetchColumn();
        if ($devEmail) {
            $db->prepare('UPDATE submissions SET email = ? WHERE id = ?')->execute([$devEmail, $sub['id']]);
            $sub['email'] = $devEmail;
        }
    }

    jsonResponse([
        'submissionId' => (int) $sub['id'],
        'status' => $sub['status'],
        'name' => $sub['name'],
        'email' => $sub['email'],
        'age' => $sub['age'] ? (int) $sub['age'] : null,
        'location' => $sub['location'],
        'ride_frequency' => $sub['ride_frequency'],
        'conditions' => $sub['conditions'],
        'equipment' => $sub['equipment'],
        'level' => $sub['level'],
        'stuck_on' => $sub['stuck_on'],
        'tried' => $sub['tried'],
        'success_looks_like' => $sub['success_looks_like'],
        'audio_file' => $sub['audio_file'],
    ]);
}

$stmt = $db->prepare('SELECT * FROM submissions WHERE stripe_session_id = ?');
$stmt->execute([$sessionId]);
$sub = $stmt->fetch();

if (!$sub) {
    // Webhook may not have fired yet — verify directly with Stripe
    try {
        $stripe = new \Stripe\StripeClient(STRIPE_SECRET_KEY);
        $session = $stripe->checkout->sessions->retrieve($sessionId);
        if ($session->payment_status !== 'paid') {
            jsonResponse(['error' => 'Payment not completed'], 402);
        }
        jsonResponse([
            'status' => 'processing',
            'message' => 'Payment confirmed, please wait a moment and refresh.',
        ], 202);
    } catch (\Exception $e) {
        jsonResponse(['error' => 'Invalid session ID'], 400);
    }
}

// Heal email from checkout_attempts
$email = $sub['email'];
if (!$email) {
    $aStmt = $db->prepare('SELECT email FROM checkout_attempts WHERE stripe_session_id = ?');
    $aStmt->execute([$sessionId]);
    $email = $aStmt->fetchColumn() ?: null;
    if ($email) {
        $db->prepare('UPDATE submissions SET email = ? WHERE id = ?')->execute([$email, $sub['id']]);
    }
}

jsonResponse([
    'submissionId' => (int) $sub['id'],
    'status' => $sub['status'],
    'name' => $sub['name'],
    'email' => $email,
    'age' => $sub['age'] ? (int) $sub['age'] : null,
    'location' => $sub['location'],
    'ride_frequency' => $sub['ride_frequency'],
    'conditions' => $sub['conditions'],
    'equipment' => $sub['equipment'],
    'level' => $sub['level'],
    'stuck_on' => $sub['stuck_on'],
    'tried' => $sub['tried'],
    'success_looks_like' => $sub['success_looks_like'],
    'audio_file' => $sub['audio_file'],
]);
