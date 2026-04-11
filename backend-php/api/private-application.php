<?php
/**
 * Private Coaching Experience — Application endpoint
 * POST /video-coaching/api/private-application.php
 * Accepts multipart/form-data (with optional audio/video) or JSON
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers/email.php';

setApiHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['error' => 'Method not allowed'], 405);
}

// ── Parse input ────────────────────────────────────────────────────────────────
$isMultipart = !empty($_FILES);

if ($isMultipart) {
    $data = $_POST;
} else {
    $data = getJsonBody();
}

// ── Honeypot ───────────────────────────────────────────────────────────────────
if (!empty($data['website'])) {
    // Bot detected — return fake success
    jsonResponse(['ok' => true]);
}

// ── Validate required fields ───────────────────────────────────────────────────
$name = trim($data['name'] ?? '');
$email = trim($data['email'] ?? '');

if (!$name || !$email) {
    jsonResponse(['error' => 'Name and email are required.'], 400);
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    jsonResponse(['error' => 'Invalid email address.'], 400);
}

// ── Optional fields ────────────────────────────────────────────────────────────
$location     = trim($data['location'] ?? '');
$timeframe    = trim($data['timeframe'] ?? '');
$groupSize    = trim($data['group_size'] ?? '');
$ridingLevel  = trim($data['riding_level'] ?? '');
$message      = trim($data['message'] ?? '');

// ── File uploads ───────────────────────────────────────────────────────────────
$uploadDir = __DIR__ . '/../uploads/private-applications/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

$audioFile = null;
$videoFile = null;

$allowedAudio = ['audio/webm', 'audio/ogg', 'audio/mp4', 'audio/mpeg'];
$allowedVideo = ['video/webm', 'video/mp4'];

// Audio upload
if (!empty($_FILES['audio']) && $_FILES['audio']['error'] === UPLOAD_ERR_OK) {
    $mime = $_FILES['audio']['type'];
    if (!in_array($mime, $allowedAudio)) {
        jsonResponse(['error' => 'Invalid audio format.'], 400);
    }
    if ($_FILES['audio']['size'] > 10 * 1024 * 1024) {
        jsonResponse(['error' => 'Audio file too large (max 10MB).'], 400);
    }
    $audioFile = safeName($_FILES['audio']['name']);
    move_uploaded_file($_FILES['audio']['tmp_name'], $uploadDir . $audioFile);
}

// Video upload
if (!empty($_FILES['video']) && $_FILES['video']['error'] === UPLOAD_ERR_OK) {
    $mime = $_FILES['video']['type'];
    if (!in_array($mime, $allowedVideo)) {
        jsonResponse(['error' => 'Invalid video format.'], 400);
    }
    if ($_FILES['video']['size'] > 50 * 1024 * 1024) {
        jsonResponse(['error' => 'Video file too large (max 50MB).'], 400);
    }
    $videoFile = safeName($_FILES['video']['name']);
    move_uploaded_file($_FILES['video']['tmp_name'], $uploadDir . $videoFile);
}

// ── Store in database ──────────────────────────────────────────────────────────
$db = getDb();
$stmt = $db->prepare('INSERT INTO private_coaching_applications
    (name, email, location, timeframe, group_size, riding_level, message, audio_file, video_file)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');

$stmt->execute([
    $name, $email, $location, $timeframe, $groupSize, $ridingLevel, $message, $audioFile, $videoFile
]);

$applicationId = $db->lastInsertId();

// ── Email notification to Michi ────────────────────────────────────────────────
try {
    $mail = getMailer('Private Coaching');
    $mail->addAddress(NOTIFY_EMAIL);
    $mail->Subject = "Private Coaching Application from $name";
    $mail->isHTML(true);

    $eName     = htmlspecialchars($name);
    $eEmail    = htmlspecialchars($email);
    $eLocation = htmlspecialchars($location ?: '-');
    $eTime     = htmlspecialchars($timeframe ?: '-');
    $eGroup    = htmlspecialchars($groupSize ?: '-');
    $eLevel    = htmlspecialchars($ridingLevel ?: '-');
    $eMessage  = nl2br(htmlspecialchars($message ?: '-'));

    $mediaNote = '';
    if ($audioFile) $mediaNote .= '<p style="font-size:13px;color:#f0d078;">Audio message attached</p>';
    if ($videoFile) $mediaNote .= '<p style="font-size:13px;color:#f0d078;">Video message attached</p>';

    $mail->Body = <<<HTML
<div style="font-family:'Helvetica Neue',Helvetica,Arial,sans-serif;max-width:600px;margin:0 auto;background:#0a0e18;border-radius:12px;overflow:hidden;border:1px solid rgba(212,168,67,0.2);">
  <div style="background:linear-gradient(90deg,#d4a843 0%,#f0d078 100%);padding:16px 24px;">
    <h2 style="color:#060a14;margin:0;font-size:16px;font-weight:700;">Private Coaching Application #{$applicationId}</h2>
  </div>
  <div style="padding:24px;">
    <table style="width:100%;border-collapse:collapse;">
      <tr><td style="color:#94a3b8;padding:8px 14px 8px 0;font-size:13px;">Name</td><td style="padding:8px 0;font-size:14px;color:#ffffff;font-weight:600;">$eName</td></tr>
      <tr><td style="color:#94a3b8;padding:8px 14px 8px 0;font-size:13px;">Email</td><td style="padding:8px 0;font-size:13px;"><a href="mailto:$eEmail" style="color:#f0d078;">$eEmail</a></td></tr>
      <tr><td style="color:#94a3b8;padding:8px 14px 8px 0;font-size:13px;">Location</td><td style="padding:8px 0;font-size:13px;color:#e2e8f0;">$eLocation</td></tr>
      <tr><td style="color:#94a3b8;padding:8px 14px 8px 0;font-size:13px;">Timeframe</td><td style="padding:8px 0;font-size:13px;color:#e2e8f0;">$eTime</td></tr>
      <tr><td style="color:#94a3b8;padding:8px 14px 8px 0;font-size:13px;">Group Size</td><td style="padding:8px 0;font-size:13px;color:#e2e8f0;">$eGroup</td></tr>
      <tr><td style="color:#94a3b8;padding:8px 14px 8px 0;font-size:13px;">Riding Level</td><td style="padding:8px 0;font-size:13px;color:#e2e8f0;">$eLevel</td></tr>
    </table>
    <div style="margin-top:16px;">
      <p style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;color:#d4a843;margin:0 0 6px;">Message</p>
      <p style="font-size:13px;color:#e2e8f0;background:#111827;padding:12px 14px;border-radius:8px;margin:0;border-left:3px solid #d4a843;">$eMessage</p>
    </div>
    $mediaNote
  </div>
</div>
HTML;

    $mail->send();
} catch (\Exception $e) {
    // Email failure shouldn't block the response
    error_log('Private coaching email failed: ' . $e->getMessage());
}

jsonResponse(['ok' => true]);
