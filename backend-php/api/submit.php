<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers/email.php';
setApiHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['error' => 'Method not allowed'], 405);
}

$id = (int) ($_GET['id'] ?? 0);
if (!$id) jsonResponse(['error' => 'Invalid ID'], 400);

$db = getDb();
$stmt = $db->prepare('SELECT * FROM submissions WHERE id = ?');
$stmt->execute([$id]);
$sub = $stmt->fetch();
if (!$sub) jsonResponse(['error' => 'Submission not found'], 404);

$body = getJsonBody();

$fields = [
    'name' => $body['name'] ?? $sub['name'],
    'email' => $body['email'] ?? $sub['email'],
    'age' => isset($body['age']) ? (int) $body['age'] : null,
    'location' => $body['location'] ?? null,
    'ride_frequency' => $body['ride_frequency'] ?? null,
    'conditions' => $body['conditions'] ?? null,
    'equipment' => $body['equipment'] ?? null,
    'level' => $body['level'] ?? null,
    'stuck_on' => $body['stuck_on'] ?? null,
    'tried' => $body['tried'] ?? null,
    'success_looks_like' => $body['success_looks_like'] ?? null,
    'audio_file' => $body['audio_file'] ?? $sub['audio_file'],
    'status' => 'submitted',
    'submitted_at' => date('Y-m-d H:i:s'),
];

$setParts = [];
$vals = [];
foreach ($fields as $k => $v) {
    $setParts[] = "`$k` = ?";
    $vals[] = $v;
}
$vals[] = $id;
$db->prepare('UPDATE submissions SET ' . implode(', ', $setParts) . ' WHERE id = ?')->execute($vals);

// Re-fetch for email
$stmt = $db->prepare('SELECT * FROM submissions WHERE id = ?');
$stmt->execute([$id]);
$finalSub = $stmt->fetch();
$finalName = $finalSub['name'] ?: 'Unknown';
$finalEmail = $finalSub['email'] ?: '';

// Send admin notification
try {
    sendSubmissionNotification($finalName, $finalEmail, $id, $finalSub);
} catch (\Exception $e) {
    error_log('Submission notification email error: ' . $e->getMessage());
}

// Send confirmation to rider
if ($finalEmail) {
    $uploadUrl = BASE_URL . '/success?session_id=' . ($finalSub['stripe_session_id'] ?? '');
    try {
        sendSubmissionConfirmation($finalEmail, $finalName, $uploadUrl);
    } catch (\Exception $e) {
        error_log('Submission confirmation email error: ' . $e->getMessage());
    }
}

jsonResponse(['success' => true]);
