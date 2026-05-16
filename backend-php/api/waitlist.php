<?php
/**
 * Waitlist API - handles interest form submissions for upcoming coaching programs.
 * POST /api/waitlist.php
 * Body: { "name": "...", "email": "...", "type": "1on1"|"monthly"|"vip" }
 */
require_once __DIR__ . '/config.php';

setApiHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['error' => 'Method not allowed'], 405);
}

$data = getJsonBody();
$name  = trim($data['name'] ?? '');
$email = trim($data['email'] ?? '');
$type  = trim($data['type'] ?? '');

if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    jsonResponse(['error' => 'Valid email required'], 400);
}

if (!in_array($type, ['1on1', 'monthly', 'vip', 'masterclass', 'peak', 'summit', 'elite', 'book', 'workshop', 'insider'], true)) {
    jsonResponse(['error' => 'Invalid type'], 400);
}

$db = getDb();

// Upsert - if same email+type exists, update name and bump timestamp
$stmt = $db->prepare('
    INSERT INTO waitlist (name, email, type, created_at)
    VALUES (:name, :email, :type, NOW())
    ON DUPLICATE KEY UPDATE name = :name2, created_at = NOW()
');
$stmt->execute([
    'name'  => $name,
    'email' => $email,
    'type'  => $type,
    'name2' => $name,
]);

// --- Notify Michi + add to the Sendy "The Lineup Insider" list ---
// Both are best-effort: a failure here must NEVER break the user's signup.
$goals = trim($data['goals'] ?? '');

try {
    $subject = "New Lineup signup: {$type}";
    $body  = "New signup on a The Lineup / Tricktionary page.\n\n";
    $body .= "Type:  {$type}\n";
    $body .= "Name:  " . ($name !== '' ? $name : '(not given)') . "\n";
    $body .= "Email: {$email}\n";
    if ($goals !== '') { $body .= "Goals: {$goals}\n"; }
    $body .= "Time:  " . date('Y-m-d H:i') . " (server)\n";
    @mail(
        'rossi@tricktionary.com',
        $subject,
        $body,
        "From: notify@tricktionary.com\r\nReply-To: {$email}\r\nContent-Type: text/plain; charset=UTF-8"
    );
} catch (\Throwable $e) { /* swallow */ }

try {
    $sendyFields = http_build_query([
        'list'    => 'Ev9D4gFqG892CrSMfbGOKA', // The Lineup Insider (brand 1, l=85)
        'email'   => $email,
        'name'    => $name,
        'boolean' => 'true',
    ]);
    $ch = curl_init('https://sendy.tricktionary.com/subscribe');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $sendyFields,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 5,
        CURLOPT_CONNECTTIMEOUT => 4,
    ]);
    curl_exec($ch);
    curl_close($ch);
} catch (\Throwable $e) { /* swallow */ }

jsonResponse(['ok' => true]);
