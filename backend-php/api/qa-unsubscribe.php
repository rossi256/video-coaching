<?php
/**
 * Q&A unsubscribe: GET /api/qa-unsubscribe?t=TOKEN
 *
 * Until 2026-08-25 the only way off the Q&A list was to reply "no more" to an
 * invite and have a human set the flag by hand. That is fine at 45 people and
 * not fine at a few hundred, which is where the list is heading.
 *
 * Reuses the same signed token as the one-click signup link, so the address is
 * never taken from the query string directly and nobody can unsubscribe anyone
 * else. Renders a plain confirmation page rather than JSON, because this is
 * opened by a person clicking a link in an email client.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers/email.php';

$token = trim($_GET['t'] ?? '');
$email = $token !== '' ? qaVerifyAudienceToken($token) : null;

$ok = false;
if ($email !== null) {
    try {
        $db = getDb();
        $stmt = $db->prepare('UPDATE qa_audience SET unsubscribed = 1 WHERE LOWER(email) = ?');
        $stmt->execute([$email]);
        // Idempotent: a second click on the same link is still a success for the
        // person clicking, whether or not a row changed.
        $ok = true;
    } catch (\Throwable $t) {
        error_log('qa unsubscribe failed: ' . $t->getMessage());
    }
}

$title = $ok ? 'You are off the list' : 'That link did not work';
$body  = $ok
    ? '<p>No more Q&amp;A invites will come your way. Nothing else changes: orders, camps and any other email from us are separate.</p>'
      . '<p>Changed your mind later? Just sign up for a session again and you are back on.</p>'
    : '<p>This unsubscribe link is not valid. It may have been cut in half by your email client.</p>'
      . '<p>Reply to any of my emails with "no more" and I will take you off by hand.</p>';

header('Content-Type: text/html; charset=utf-8');
header('X-Robots-Tag: noindex');
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($title) ?></title>
<style>
  body{margin:0;background:#eef2f6;font-family:Helvetica,Arial,sans-serif;color:#33414f;
       display:flex;align-items:center;justify-content:center;min-height:100vh;padding:24px;}
  .card{background:#fff;border-radius:14px;max-width:520px;width:100%;overflow:hidden;
        border:1px solid #dde5ec;}
  .head{background:#0d1b2e;padding:22px 28px;}
  .head span.a{font-weight:900;color:#0ea5e9;font-size:22px;}
  .head span.b{font-weight:300;color:#fff;font-size:22px;}
  .body{padding:28px 32px 32px;line-height:1.62;font-size:16px;}
  h1{margin:0 0 12px;font-size:24px;color:#0d1b2e;}
  p{margin:0 0 12px;}
  a{color:#0ea5e9;}
  .foot{padding:18px 32px;background:#f8fafc;border-top:1px solid #e2e8f0;
        font-size:12px;color:#8a99a8;text-align:center;}
</style>
</head>
<body>
  <div class="card">
    <div class="head"><span class="a">WING</span><span class="b">COACH</span></div>
    <div class="body">
      <h1><?= htmlspecialchars($title) ?></h1>
      <?= $body ?>
    </div>
    <div class="foot">Tricktionary GmbH &middot; Riedgasse 10, 6142 Mieders, Austria</div>
  </div>
</body>
</html>
