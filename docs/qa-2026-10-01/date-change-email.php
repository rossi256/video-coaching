<?php
require_once '/home/coaching/public_html/video-coaching/api/config.php';
require_once '/home/coaching/public_html/video-coaching/api/helpers/email.php';

$onlyTo = $argv[1] ?? '';          // pass an address to send a single sample
$db = getDb();
$session = $db->query("SELECT * FROM qa_sessions WHERE id = 15")->fetch(PDO::FETCH_ASSOC);
$when = date('l, F j', strtotime($session['scheduled_at']));
$tz   = qaTz($session['scheduled_at']);
$join = $session['meeting_link'];

$rows = $onlyTo
    ? [['name' => 'Michi', 'email' => $onlyTo]]
    : $db->query("SELECT name, email FROM qa_signups WHERE session_id = 15 ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);

foreach ($rows as $r) {
    $first = htmlspecialchars(trim(explode(' ', (string) $r['name'])[0]) ?: 'there');
    $body = <<<HTML
    <h2 style="color:#0c1929;margin:0 0 12px;font-size:22px;">Small change, $first</h2>
    <p style="color:#334155;">
      I have moved the next live Q&amp;A forward by a few days. It is now
      <strong>Thursday, 1 October at 19:00 ($tz)</strong>, instead of Tuesday the 6th.
      Same Zoom room, same hour, same everything else.
    </p>
    <p style="color:#334155;">
      The reason is boring: I will be on a catamaran on the 6th and I would rather
      not find out mid-call how good the signal is out there &#x1F609;
    </p>
    <p style="color:#334155;">Looking forward to seeing you there!</p>
    <p style="text-align:center;margin:28px 0;">
      <a href="$join" style="display:inline-block;background:#1063a0;background-image:linear-gradient(135deg,#1580c4,#0b4f80);color:#ffffff;padding:14px 32px;border-radius:8px;text-decoration:none;font-weight:700;font-size:15px;">
        Join on 1 October &rarr;
      </a>
    </p>
    <p style="color:#64748b;font-size:13px;">
      There is an updated calendar invite attached. Depending on your calendar it
      may not clear the old entry by itself, so if you still see Tuesday the 6th
      in there, delete that one.
    </p>
    <p style="color:#334155;">Michi</p>
HTML;

    $mail = getMailer('Michi Rossmeier');
    $mail->addAddress($r['email']);
    if (!$onlyTo) $mail->addBCC('rossi@tricktionary.com');
    $mail->Subject = ($onlyTo ? '[copy] ' : '') . 'The Q&A moved to Thursday 1 October';
    $mail->isHTML(true);
    $mail->Body = riderEmailWrap($body);
    $mail->addStringAttachment(buildQaIcs($session), 'wingcoach-qa.ics', 'base64',
                               'text/calendar; charset=UTF-8; method=PUBLISH');
    $mail->send();
    echo "  sent to {$r['email']}\n";
}
