<?php
/**
 * Private Coaching Inquiry API
 * POST /video-coaching/api/private-inquiry.php
 * Body: { "name": "...", "email": "...", "location": "...", "riding_level": "...", "message": "..." }
 *
 * Database table (run once):
 * CREATE TABLE IF NOT EXISTS private_inquiries (
 *     id INT AUTO_INCREMENT PRIMARY KEY,
 *     name VARCHAR(255) NOT NULL,
 *     email VARCHAR(255) NOT NULL,
 *     location VARCHAR(100),
 *     riding_level VARCHAR(50),
 *     message TEXT,
 *     created_at DATETIME NOT NULL,
 *     INDEX idx_email (email),
 *     INDEX idx_created (created_at)
 * ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers/email.php';

setApiHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['error' => 'Method not allowed'], 405);
}

$data = getJsonBody();

// ── Honeypot ───────────────────────────────────────────────────────────────────
if (!empty($data['website'])) {
    // Bot detected — return fake success
    jsonResponse(['ok' => true]);
}

// ── Validate required fields ───────────────────────────────────────────────────
$name    = trim($data['name'] ?? '');
$email   = trim($data['email'] ?? '');

if (!$name || !$email) {
    jsonResponse(['error' => 'Name and email are required.'], 400);
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    jsonResponse(['error' => 'Invalid email address.'], 400);
}

// ── Optional fields ────────────────────────────────────────────────────────────
$location    = trim($data['location'] ?? '');
$ridingLevel = trim($data['riding_level'] ?? '');
$message     = trim($data['message'] ?? '');

// ── Store in database ──────────────────────────────────────────────────────────
$db = getDb();
$stmt = $db->prepare('INSERT INTO private_inquiries
    (name, email, location, riding_level, message, created_at)
    VALUES (?, ?, ?, ?, ?, NOW())');

$stmt->execute([$name, $email, $location, $ridingLevel, $message]);

$inquiryId = $db->lastInsertId();

// ── Email notification to Michi ────────────────────────────────────────────────
try {
    $mail = getMailer('Private Coaching');
    $mail->addAddress(NOTIFY_EMAIL);
    $mail->Subject = "Private Coaching Inquiry from $name";
    $mail->isHTML(true);

    $eName     = htmlspecialchars($name);
    $eEmail    = htmlspecialchars($email);
    $eLocation = htmlspecialchars($location ?: '-');
    $eLevel    = htmlspecialchars($ridingLevel ?: '-');
    $eMessage  = nl2br(htmlspecialchars($message ?: '-'));

    $mail->Body = <<<HTML
<div style="font-family:'Helvetica Neue',Helvetica,Arial,sans-serif;max-width:600px;margin:0 auto;background:#0a0e18;border-radius:12px;overflow:hidden;border:1px solid rgba(45,212,191,0.2);">
  <div style="background:linear-gradient(90deg,#2dd4bf 0%,#5eead4 100%);padding:16px 24px;">
    <h2 style="color:#060a14;margin:0;font-size:16px;font-weight:700;">Private Coaching Inquiry #{$inquiryId}</h2>
  </div>
  <div style="padding:24px;">
    <table style="width:100%;border-collapse:collapse;">
      <tr><td style="color:#94a3b8;padding:8px 14px 8px 0;font-size:13px;">Name</td><td style="padding:8px 0;font-size:14px;color:#ffffff;font-weight:600;">$eName</td></tr>
      <tr><td style="color:#94a3b8;padding:8px 14px 8px 0;font-size:13px;">Email</td><td style="padding:8px 0;font-size:13px;"><a href="mailto:$eEmail" style="color:#5eead4;">$eEmail</a></td></tr>
      <tr><td style="color:#94a3b8;padding:8px 14px 8px 0;font-size:13px;">Location</td><td style="padding:8px 0;font-size:13px;color:#e2e8f0;">$eLocation</td></tr>
      <tr><td style="color:#94a3b8;padding:8px 14px 8px 0;font-size:13px;">Riding Level</td><td style="padding:8px 0;font-size:13px;color:#e2e8f0;">$eLevel</td></tr>
    </table>
    <div style="margin-top:16px;">
      <p style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;color:#2dd4bf;margin:0 0 6px;">Message</p>
      <p style="font-size:13px;color:#e2e8f0;background:#111827;padding:12px 14px;border-radius:8px;margin:0;border-left:3px solid #2dd4bf;">$eMessage</p>
    </div>
  </div>
</div>
HTML;

    $mail->send();
} catch (\Exception $e) {
    // Email failure shouldn't block the response
    error_log('Private coaching inquiry email failed: ' . $e->getMessage());
}

jsonResponse(['ok' => true]);
