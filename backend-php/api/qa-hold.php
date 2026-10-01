<?php
/**
 * One-click veto on an automatic replay publish.
 *
 * The replay builds itself after a session and publishes on a timer. This is
 * the stop button in that email: one tap, no login, no form. It only ever
 * stops something, so the worst a leaked link can do is delay a replay that
 * Michi then publishes by hand.
 *
 *   /video-coaching/api/qa-hold?s=15&t=<token>
 *
 * Signed with the same secret as the Q&A one-click signup links.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers/email.php';

function holdToken(int $sessionId): string {
    return substr(hash_hmac('sha256', 'hold-' . $sessionId, qaLinkSecret()), 0, 20);
}

$sessionId = (int) ($_GET['s'] ?? 0);
$token     = (string) ($_GET['t'] ?? '');

$ok = $sessionId > 0 && hash_equals(holdToken($sessionId), $token);

$title = 'Not held';
$msg   = 'That link is not valid. Nothing was changed.';

if ($ok) {
    $db = getDb();
    $s = $db->prepare('SELECT id, scheduled_at, replay_url, publish_held FROM qa_sessions WHERE id = ?');
    $s->execute([$sessionId]);
    $row = $s->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        $msg = 'That session no longer exists.';
    } elseif ($row['replay_url']) {
        // Too late is a real outcome and must be stated plainly rather than
        // showing a reassuring "held" page for something already sent.
        $title = 'Already published';
        $msg   = 'This replay went out before the hold arrived. The page is live and '
               . 'the email has been sent. Nothing can unsend it, but you can still '
               . 'edit the page and rebuild.';
    } else {
        $db->prepare('UPDATE qa_sessions SET publish_held = 1, publish_after = NULL WHERE id = ?')
           ->execute([$sessionId]);
        $title = 'Held';
        $msg   = 'This replay will not publish by itself. Nothing has gone to anyone. '
               . 'You will get the usual daily reminder until you publish it from the admin.';
    }
}

header('Content-Type: text/html; charset=utf-8');
$eTitle = htmlspecialchars($title);
$eMsg   = htmlspecialchars($msg);
echo <<<HTML
<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex">
<title>$eTitle</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;800&display=swap" rel="stylesheet">
<style>
  html{background:#0d1b2e;color-scheme:dark}
  body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;
       background:#0d1b2e;color:#f1f5f9;font:16px/1.6 Inter,system-ui,sans-serif;padding:24px}
  .card{max-width:30rem;background:#16283f;border:1px solid rgba(255,255,255,.09);
        border-radius:16px;padding:32px 28px;text-align:center}
  h1{font-size:1.5rem;margin:0 0 10px}
  p{color:#c3cfdb;margin:0 0 18px}
  a{color:#0ea5e9;text-decoration:none;font-weight:600}
</style></head><body>
<div class="card">
  <h1>$eTitle</h1>
  <p>$eMsg</p>
  <a href="https://coaching.tricktionary.com/video-coaching/admin">Open the admin &rarr;</a>
</div></body></html>
HTML;
