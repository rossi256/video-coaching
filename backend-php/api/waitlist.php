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

if (!in_array($type, ['1on1', 'monthly', 'vip', 'masterclass'], true)) {
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

jsonResponse(['ok' => true]);
