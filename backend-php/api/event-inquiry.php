<?php
/**
 * Event Inquiry API — handles booking interest forms from events site.
 * POST /api/event-inquiry
 * Body: { "name": "...", "email": "...", "event_slug": "...", "event_name": "...", "current_level": "...", "message": "..." }
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers/email.php';

setApiHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['error' => 'Method not allowed'], 405);
}

$data = getJsonBody();

// Honeypot
if (!empty($data['website'])) {
    jsonResponse(['ok' => true]);
}

$name       = trim($data['name'] ?? '');
$email      = trim($data['email'] ?? '');
$whatsapp   = trim($data['whatsapp'] ?? '');
$eventSlug  = trim($data['event_slug'] ?? '');
$eventName  = trim($data['event_name'] ?? '');
$level      = trim($data['current_level'] ?? '');
$message    = trim($data['message'] ?? '');
$qaSignup   = !empty($data['qa_signup']);

if (!$name || !$email) {
    jsonResponse(['error' => 'Name and email are required.'], 400);
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    jsonResponse(['error' => 'Invalid email address.'], 400);
}

if (!$eventSlug) {
    jsonResponse(['error' => 'Event slug is required.'], 400);
}

$db = getDb();
$stmt = $db->prepare('INSERT INTO event_inquiries (name, email, event_slug, event_name, current_level, message) VALUES (?, ?, ?, ?, ?, ?)');
// Store whatsapp and qa_signup in message field for now (no schema migration needed)
$fullMessage = $message;
if ($whatsapp) $fullMessage .= "\n\nWhatsApp: " . $whatsapp;
if ($qaSignup) $fullMessage .= "\n\n[Opted in for next Q&A MEETUP]";
$stmt->execute([$name, $email, $eventSlug, $eventName, $level, $fullMessage]);
$inquiryId = $db->lastInsertId();

// Organizer email map — CC organizer when inquiry matches
$organizerEmails = [
    'garda-july'      => 'info@dpctorbole.com',
    'garda-august'    => 'info@dpctorbole.com',
    'garda-freestyle' => 'info@dpctorbole.com',
    'garda-overview'  => 'info@dpctorbole.com',
    'garda-general'   => 'info@dpctorbole.com',
    'tarifa-may'      => null, // TBD - surfcenter email pending
];

// Admin notification
try {
    // Lifecycle: ticking "also send me invites to the free Q&A calls" is what
    // the Q&A audience IS - before 2026-08-24 the flag only appended a line to
    // the inquiry message, so every opt-in from an event form was dropped and
    // those people never received a single invite.
    if ($qaSignup) {
        try {
            $db->prepare("INSERT INTO qa_audience (email, name, first_source) VALUES (?, ?, 'inquiry')
                          ON DUPLICATE KEY UPDATE last_activity = NOW(), name = IF(name = '', VALUES(name), name)")
               ->execute([strtolower($email), $name]);
        } catch (\Throwable $t) { error_log('qa_audience opt-in upsert: ' . $t->getMessage()); }
    }

    // Lifecycle: replay unlocks join the Q&A audience too.
    if (strpos($eventSlug, 'qa-replay') === 0) {
        try {
            $db->prepare("INSERT INTO qa_audience (email, name, first_source) VALUES (?, ?, 'replay')
                          ON DUPLICATE KEY UPDATE last_activity = NOW(), name = IF(name = '', VALUES(name), name)")
               ->execute([strtolower($email), $name]);
        } catch (\Throwable $t) { error_log('qa_audience upsert: ' . $t->getMessage()); }
    }
    sendEventInquiryNotification($inquiryId, $name, $email, $eventSlug, $eventName, $level, $fullMessage, $whatsapp, $qaSignup);
} catch (\Exception $e) {
    error_log('Event inquiry email failed: ' . $e->getMessage());
}

// Organizer forwarding
$organizerEmail = $organizerEmails[$eventSlug] ?? null;
if ($organizerEmail) {
    try {
        sendEventInquiryToOrganizer($organizerEmail, $name, $email, $eventSlug, $eventName, $message, $whatsapp);
    } catch (\Exception $e) {
        error_log('Organizer email failed: ' . $e->getMessage());
    }
}

jsonResponse(['ok' => true]);
