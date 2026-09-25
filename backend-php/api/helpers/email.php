<?php
/**
 * WingCoach — Email helpers (PHPMailer)
 * 7 email templates — dark header + light body for riders, high-contrast dark for admin
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../config.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;

function getMailer(string $fromName = 'WingCoach'): PHPMailer {
    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host       = SMTP_HOST;
    $mail->Port       = SMTP_PORT;
    $mail->SMTPSecure = SMTP_PORT == 465 ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
    $mail->SMTPAuth   = true;
    $mail->Username   = SMTP_USER;
    $mail->Password   = SMTP_PASS;
    $mail->CharSet    = 'UTF-8';
    $mail->setFrom(SMTP_USER, $fromName);
    return $mail;
}

/**
 * Shared rider email wrapper — dark branded header, light body, clean footer
 */
function riderEmailWrap(string $bodyHtml): string {
    return <<<HTML
<div style="font-family:'Helvetica Neue',Helvetica,Arial,sans-serif;max-width:580px;margin:0 auto;background:#ffffff;border-radius:12px;overflow:hidden;border:1px solid #e2e8f0;">
  <!-- Header -->
  <div style="background:linear-gradient(135deg,#0c1929 0%,#142740 100%);padding:28px 32px;text-align:center;">
    <span style="font-weight:900;color:#0ea5e9;font-size:22px;letter-spacing:0.5px;">WING</span><span style="font-weight:300;color:#ffffff;font-size:22px;">COACH</span>
    <span style="color:#94a3b8;font-size:13px;margin-left:8px;">by Tricktionary</span>
  </div>
  <!-- Body -->
  <div style="padding:32px 32px 24px;color:#1e293b;line-height:1.65;font-size:15px;">
    $bodyHtml
  </div>
  <!-- Footer -->
  <div style="padding:20px 32px;background:#f8fafc;border-top:1px solid #e2e8f0;text-align:center;">
    <p style="margin:0 0 8px;font-size:13px;color:#475569;">
      Got questions? <a href="https://wa.me/4369913909040" style="color:#0b6e93;text-decoration:none;">WhatsApp Michi</a> &middot; <a href="mailto:info@tricktionary.com" style="color:#0b6e93;text-decoration:none;">info@tricktionary.com</a>
    </p>
    <p style="margin:0;font-size:12px;color:#94a3b8;">
      WingCoach by Michael Rossmeier &middot; <a href="https://tricktionary.com" style="color:#94a3b8;text-decoration:none;">Tricktionary</a> &middot; &copy; 2026
    </p>
  </div>
</div>
HTML;
}

/**
 * One-click "Save my spot" tokens.
 *
 * People on the invite list already gave us their email once; making them retype
 * it in a modal is the reason invites convert badly. The invite CTA therefore
 * carries a signed token that identifies the recipient, so the landing page can
 * register them on the click alone.
 *
 * The secret is derived from credentials that already exist in config.php, so
 * there is no new constant to keep in sync across the local/staging/prod copies.
 * Tokens are scoped to one email and do not expire: the worst case for a leaked
 * link is that someone books a free seat for an address they already control.
 */
/**
 * Europe/Vienna decides CEST or CET for a given session, so a winter Q&A is
 * not labelled with a summer timezone. Two emails had ' CEST' concatenated,
 * which would have been wrong for every session after DST ends on 25 October.
 */
function qaTz(string $scheduledAt): string {
    $d = new DateTime($scheduledAt, new DateTimeZone('Europe/Vienna'));
    return $d->format('T');
}

function qaLinkSecret(): string {
    return hash('sha256', ADMIN_PASSWORD . '|' . DB_PASS . '|qa-oneclick-v1');
}

function qaAudienceToken(string $email): string {
    $email = strtolower(trim($email));
    $payload = rtrim(strtr(base64_encode($email), '+/', '-_'), '=');
    $sig = substr(hash_hmac('sha256', $email, qaLinkSecret()), 0, 16);
    return $payload . '.' . $sig;
}

/** Verify a one-click token and return the email it was issued for, or null. */
function qaVerifyAudienceToken(string $token): ?string {
    $parts = explode('.', trim($token));
    if (count($parts) !== 2) return null;
    [$payload, $sig] = $parts;
    $decoded = base64_decode(strtr($payload, '-_', '+/'), true);
    if ($decoded === false || $decoded === '') return null;
    $email = strtolower(trim($decoded));
    $expected = substr(hash_hmac('sha256', $email, qaLinkSecret()), 0, 16);
    if (!hash_equals($expected, $sig)) return null;
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return null;
    return $email;
}

/**
 * Send Michi exactly one copy of each lifecycle email per session.
 *
 * The house rule is that automated customer email copies him, so he can see what
 * people actually received. Taken literally on a list send that would mean one
 * copy per recipient: 45 invites, then 45 more per reminder offset. So this
 * claims the FIRST message of each (session, type) batch and re-sends it to him
 * afterwards, labelled. It is a second message rather than a BCC because a BCC
 * shares one envelope, so relabelling the subject would relabel the customer's
 * copy too (found 2026-08-25, the first recipient got "[copy]" in their inbox).
 *
 * Call qaClaimSample() before send() and qaSendSampleCopy() after it.
 */
function qaClaimSample(int $sessionId, string $type): bool {
    if ($sessionId <= 0) return false;
    try {
        $db = getDb();
        $db->exec("CREATE TABLE IF NOT EXISTS qa_email_samples (
            session_id INT NOT NULL,
            email_type VARCHAR(32) NOT NULL,
            sent_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (session_id, email_type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $ins = $db->prepare('INSERT IGNORE INTO qa_email_samples (session_id, email_type) VALUES (?, ?)');
        $ins->execute([$sessionId, $type]);
        return $ins->rowCount() > 0;
    } catch (\Throwable $t) {
        error_log('qa sample claim failed: ' . $t->getMessage());
        return false;
    }
}

/** Re-send the message just sent to Michi, labelled, without touching the original. */
function qaSendSampleCopy(PHPMailer $mail, bool $claimed): void {
    if (!$claimed) return;
    try {
        $mail->clearAllRecipients();
        $mail->addAddress(NOTIFY_EMAIL);
        $mail->Subject = '[copy] ' . $mail->Subject;
        $mail->send();
    } catch (\Throwable $t) {
        error_log('qa sample copy failed: ' . $t->getMessage());
    }
}

/** One-click unsubscribe footer for the list emails (invite, replay). */
function qaUnsubscribeLine(string $email): string {
    $url = 'https://coaching.tricktionary.com/video-coaching/api/qa-unsubscribe?t='
         . rawurlencode(qaAudienceToken($email));
    return '<p style="color:#94a3b8;font-size:12px;margin-top:22px;">'
         . 'You get this because you joined a Q&amp;A or watched a replay. '
         . '<a href="' . $url . '" style="color:#94a3b8;">Unsubscribe from Q&amp;A invites</a>.'
         . '</p>';
}

/**
 * Build a calendar invite (.ics) for a Q&A session so registrants can one-tap
 * "save to calendar" with the Zoom link embedded. Times are interpreted in
 * Europe/Berlin (how scheduled_at is stored) and emitted in UTC, so the skew
 * that bites PHP strtotime() elsewhere cannot happen here.
 */
function buildQaIcs(array $session): string {
    $tz    = new DateTimeZone('Europe/Berlin');
    $start = new DateTime($session['scheduled_at'], $tz);
    $end   = clone $start;
    $end->modify('+' . max(1, (int) $session['duration_minutes']) . ' minutes');
    $utc = new DateTimeZone('UTC');
    $start->setTimezone($utc); $end->setTimezone($utc);

    $stamp = gmdate('Ymd\THis\Z');
    $link  = trim((string) ($session['meeting_link'] ?? ''));
    $esc = function (string $s): string {
        return str_replace(["\\", ",", ";", "\r\n", "\n"], ["\\\\", "\\,", "\\;", "\\n", "\\n"], $s);
    };
    $title = $esc('Live Q&A with Michi — ' . $session['title']);
    $descParts = [trim((string) ($session['description'] ?? ''))];
    if ($link !== '') $descParts[] = 'Join: ' . $link;
    $desc = $esc(trim(implode("\n\n", array_filter($descParts))));
    $loc  = $esc($link !== '' ? $link : 'Online');
    // The UID must NOT contain the date. It used to, which meant a rescheduled
    // session produced a second calendar entry beside the old one instead of
    // replacing it. SEQUENCE is what tells a calendar this is a newer version
    // of the same event, so it has to climb every time the date moves.
    $uid  = 'qa-' . ((int) ($session['id'] ?? 0)) . '@coaching.tricktionary.com';
    $seq  = (int) ($session['ics_sequence'] ?? 0);

    $lines = [
        'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Tricktionary//WingCoach Q&A//EN',
        'CALSCALE:GREGORIAN', 'METHOD:PUBLISH', 'BEGIN:VEVENT',
        'UID:' . $uid, 'SEQUENCE:' . $seq, 'DTSTAMP:' . $stamp,
        'DTSTART:' . $start->format('Ymd\THis\Z'), 'DTEND:' . $end->format('Ymd\THis\Z'),
        'SUMMARY:' . $title, 'DESCRIPTION:' . $desc, 'LOCATION:' . $loc,
    ];
    if ($link !== '') $lines[] = 'URL:' . $link;
    $lines[] = 'END:VEVENT'; $lines[] = 'END:VCALENDAR';
    return implode("\r\n", $lines) . "\r\n";
}

/** Attach the Q&A calendar invite to an email, swallowing any build error. */
function attachQaIcs(PHPMailer $mail, array $session): void {
    try {
        $mail->addStringAttachment(buildQaIcs($session), 'wingcoach-qa.ics', 'base64', 'text/calendar; charset=UTF-8; method=PUBLISH');
    } catch (\Throwable $e) {
        error_log('QA ics build failed: ' . $e->getMessage());
    }
}

// 1. Admin notification — new payment received
function sendAdminNotification(string $name, string $email): void {
    $mail = getMailer();
    $mail->addAddress(NOTIFY_EMAIL);
    $mail->Subject = "New WingCoach payment: $name";
    $mail->Body = "New payment received!\n\nName: $name\nEmail: $email\n\nLogin to admin to view the submission:\n" . BASE_URL . '/admin';
    $mail->send();
}

// 2. Admin notification — full submission received
function sendSubmissionNotification(string $name, string $email, $submissionId, ?array $sub): void {
    $mail = getMailer();
    $mail->addAddress(NOTIFY_EMAIL);
    $mail->Subject = "New coaching submission from $name";
    $mail->isHTML(true);

    $adminUrl = BASE_URL . "/admin#submission-$submissionId";
    $eName = htmlspecialchars($name);
    $eEmail = htmlspecialchars($email);

    // What the rider actually uploaded. This block was missing entirely, so the
    // notification never told Michi there was a video waiting, let alone where.
    $filesBlock = '';
    $dir = UPLOADS_DIR . '/' . $submissionId;
    $files = [];
    if (is_dir($dir)) {
        foreach (scandir($dir) as $f) {
            $fp = $dir . '/' . $f;
            if ($f[0] !== '.' && is_file($fp)) {
                $files[] = ['name' => $f, 'size' => filesize($fp)];
            }
        }
    }
    if ($files) {
        $rows = '';
        foreach ($files as $f) {
            $mb   = number_format($f['size'] / 1048576, 1);
            $url  = BASE_URL . '/api/admin/file/' . $submissionId . '/' . rawurlencode($f['name']);
            $safe = htmlspecialchars($f['name']);
            $rows .= '<tr>'
                . '<td style="padding:10px 12px;font-size:13px;color:#e2e8f0;border-bottom:1px solid #1e3a5f;">' . $safe . '</td>'
                . '<td style="padding:10px 12px;font-size:13px;color:#94a3b8;white-space:nowrap;border-bottom:1px solid #1e3a5f;">' . $mb . ' MB</td>'
                . '<td style="padding:10px 12px;text-align:right;border-bottom:1px solid #1e3a5f;">'
                . '<a href="' . htmlspecialchars($url) . '" style="color:#38bdf8;font-size:13px;font-weight:600;text-decoration:none;">Download &darr;</a>'
                . '</td></tr>';
        }
        $count = count($files);
        $label = $count === 1 ? '1 file uploaded' : "$count files uploaded";
        $filesBlock = <<<HTML
<div style="margin-top:22px;">
  <p style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;color:#7dd3fc;margin:0 0 8px;">$label</p>
  <table style="width:100%;border-collapse:collapse;background:#132a45;border-radius:8px;overflow:hidden;">$rows</table>
  <p style="font-size:12px;color:#94a3b8;margin:8px 0 0;">Sign in with the admin password when the download prompts you.</p>
</div>
HTML;
    } else {
        $filesBlock = '<div style="margin-top:22px;"><p style="font-size:13px;color:#fca5a5;background:#3f1d1d;padding:12px 14px;border-radius:8px;margin:0;border-left:3px solid #ef4444;">No files uploaded yet.</p></div>';
    }

    $riderRows = '';
    $coachingRows = '';
    if ($sub) {
        $level = htmlspecialchars($sub['level'] ?? '—');
        $location = htmlspecialchars($sub['location'] ?? '—');
        $equipment = htmlspecialchars($sub['equipment'] ?? '—');
        $rideFreq = htmlspecialchars($sub['ride_frequency'] ?? '—');
        $stuckOn = htmlspecialchars($sub['stuck_on'] ?? '—');
        $tried = htmlspecialchars($sub['tried'] ?? '—');
        $successLooksLike = htmlspecialchars($sub['success_looks_like'] ?? '—');

        $riderRows = <<<HTML
<tr><td style="color:#94a3b8;padding:8px 14px 8px 0;white-space:nowrap;font-size:13px;vertical-align:top;">Level</td><td style="padding:8px 0;font-size:13px;color:#f1f5f9;">$level</td></tr>
<tr><td style="color:#94a3b8;padding:8px 14px 8px 0;white-space:nowrap;font-size:13px;vertical-align:top;">Location</td><td style="padding:8px 0;font-size:13px;color:#f1f5f9;">$location</td></tr>
<tr><td style="color:#94a3b8;padding:8px 14px 8px 0;white-space:nowrap;font-size:13px;vertical-align:top;">Equipment</td><td style="padding:8px 0;font-size:13px;color:#f1f5f9;">$equipment</td></tr>
<tr><td style="color:#94a3b8;padding:8px 14px 8px 0;white-space:nowrap;font-size:13px;vertical-align:top;">Ride Freq</td><td style="padding:8px 0;font-size:13px;color:#f1f5f9;">$rideFreq</td></tr>
HTML;

        $coachingRows = <<<HTML
<div style="margin-top:20px;">
  <p style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;color:#7dd3fc;margin:0 0 6px;">Stuck on</p>
  <p style="font-size:13px;color:#e2e8f0;background:#1e3a5f;padding:12px 14px;border-radius:8px;margin:0 0 14px;border-left:3px solid #0ea5e9;">$stuckOn</p>
</div>
<div>
  <p style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;color:#7dd3fc;margin:0 0 6px;">Tried</p>
  <p style="font-size:13px;color:#e2e8f0;background:#1e3a5f;padding:12px 14px;border-radius:8px;margin:0 0 14px;border-left:3px solid #0ea5e9;">$tried</p>
</div>
<div>
  <p style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;color:#7dd3fc;margin:0 0 6px;">Success looks like</p>
  <p style="font-size:13px;color:#e2e8f0;background:#1e3a5f;padding:12px 14px;border-radius:8px;margin:0;border-left:3px solid #0ea5e9;">$successLooksLike</p>
</div>
HTML;
    }

    $mail->Body = <<<HTML
<div style="font-family:'Helvetica Neue',Helvetica,Arial,sans-serif;max-width:600px;margin:0 auto;background:#0f2035;border-radius:12px;overflow:hidden;border:1px solid #1e3a5f;">
  <!-- Header bar -->
  <div style="background:linear-gradient(90deg,#0ea5e9 0%,#0284c7 100%);padding:16px 24px;">
    <h2 style="color:#ffffff;margin:0;font-size:16px;font-weight:600;">New coaching submission &mdash; #$submissionId</h2>
  </div>
  <!-- Content -->
  <div style="padding:24px;">
    <table style="width:100%;border-collapse:collapse;margin-bottom:8px;">
      <tr><td style="color:#94a3b8;padding:8px 14px 8px 0;white-space:nowrap;font-size:13px;vertical-align:top;">Name</td><td style="padding:8px 0;font-size:14px;color:#ffffff;font-weight:600;">$eName</td></tr>
      <tr><td style="color:#94a3b8;padding:8px 14px 8px 0;white-space:nowrap;font-size:13px;vertical-align:top;">Email</td><td style="padding:8px 0;font-size:13px;"><a href="mailto:$eEmail" style="color:#38bdf8;">$eEmail</a></td></tr>
      $riderRows
    </table>
    $coachingRows
    $filesBlock
    <div style="margin-top:28px;text-align:center;">
      <a href="$adminUrl" style="display:inline-block;background:#1063a0;background-image:linear-gradient(135deg,#1580c4,#0b4f80);color:#ffffff;font-weight:700;padding:12px 32px;border-radius:8px;text-decoration:none;font-size:14px;">
        View in Admin &rarr;
      </a>
    </div>
  </div>
</div>
HTML;
    $mail->send();
}

// 3. Feedback ready — sent to rider when coaching video is ready
function sendFeedbackReady(string $email, string $name, string $replyUrl): void {
    $mail = getMailer('Michi @ WingCoach');
    $mail->addAddress($email);
    $mail->Subject = 'Your coaching feedback from Michi is ready';
    $mail->isHTML(true);

    $eName = htmlspecialchars($name);
    $eUrl = htmlspecialchars($replyUrl);

    $body = <<<HTML
    <h2 style="color:#0c1929;margin:0 0 12px;font-size:22px;">Hey $eName &mdash; your feedback is ready.</h2>
    <p style="color:#334155;">Michi has reviewed your videos and recorded a personal coaching response just for you.</p>
    <p style="text-align:center;margin:28px 0;">
      <a href="$eUrl" style="display:inline-block;background:#1063a0;background-image:linear-gradient(135deg,#1580c4,#0b4f80);color:#ffffff;padding:14px 32px;border-radius:8px;text-decoration:none;font-weight:700;font-size:16px;">
        Watch Your Coaching Feedback
      </a>
    </p>
    <p style="color:#64748b;font-size:13px;">Or copy this link: <a href="$eUrl" style="color:#0b6e93;">$eUrl</a></p>
    <p style="color:#334155;">This link is yours &mdash; you can come back to it anytime.</p>
HTML;

    $mail->Body = riderEmailWrap($body);
    $mail->send();
}

// 4. Upload link — sent after payment
function sendUploadLink(string $email, string $name, string $uploadUrl): void {
    $mail = getMailer();
    $mail->addAddress($email);
    $mail->Subject = 'Your WingCoach upload link — come back anytime';
    $mail->isHTML(true);

    $eName = htmlspecialchars($name ?: 'there');
    $eUrl = htmlspecialchars($uploadUrl);

    $body = <<<HTML
    <h2 style="color:#0c1929;margin:0 0 12px;font-size:22px;">Hey $eName &#x1F44B;</h2>
    <p style="color:#334155;">
      Your coaching spot is secured! Use the link below to upload your riding videos and fill out your rider profile &mdash; you can come back any time, your progress is saved automatically.
    </p>
    <p style="text-align:center;margin:28px 0;">
      <a href="$eUrl" style="display:inline-block;background:#1063a0;background-image:linear-gradient(135deg,#1580c4,#0b4f80);color:#ffffff;padding:14px 32px;border-radius:8px;text-decoration:none;font-weight:700;font-size:15px;">
        Go to my upload page &rarr;
      </a>
    </p>
    <p style="color:#64748b;font-size:13px;">
      Bookmark this email or save the link &mdash; it's your personal access to this coaching session.
    </p>
HTML;

    $mail->Body = riderEmailWrap($body);
    $mail->send();
}

// 5. Abandoned checkout reminder
function sendAbandonedCheckoutReminder(string $email, string $checkoutUrl): void {
    $mail = getMailer();
    $mail->addAddress($email);
    $mail->Subject = 'Your coaching spot is still waiting';
    $mail->isHTML(true);

    $eUrl = htmlspecialchars($checkoutUrl);

    $body = <<<HTML
    <h2 style="color:#0c1929;margin:0 0 12px;font-size:22px;">Hey &mdash; you were this close.</h2>
    <p style="color:#334155;">
      Your founding coaching spot with Michi is still available. Once all 10 spots fill up, the price goes to &euro;149.
    </p>
    <p style="color:#1e293b;font-weight:500;">
      Click below to come back and lock it in.
    </p>
    <p style="text-align:center;margin:28px 0;">
      <a href="$eUrl" style="display:inline-block;background:#1063a0;background-image:linear-gradient(135deg,#1580c4,#0b4f80);color:#ffffff;padding:14px 32px;border-radius:8px;text-decoration:none;font-weight:700;font-size:15px;">
        Claim my founding spot &rarr;
      </a>
    </p>
HTML;

    $mail->Body = riderEmailWrap($body);
    $mail->send();
}

// 6. Submission confirmation — sent to rider after they submit
function sendSubmissionConfirmation(string $email, string $name, string $uploadUrl): void {
    $mail = getMailer('Michi @ WingCoach');
    $mail->addAddress($email);
    $mail->Subject = "Got it — Michi is on it \xF0\x9F\x8E\xAF";
    $mail->isHTML(true);

    $eName = htmlspecialchars($name ?: 'there');
    $eUrl = htmlspecialchars($uploadUrl);

    $body = <<<HTML
    <h2 style="color:#0c1929;margin:0 0 12px;font-size:22px;">Hey $eName &mdash; got it! &#x1F3AF;</h2>
    <p style="color:#334155;">
      Your videos and profile are in. I'll review everything and send your personalized coaching video within <strong>72 hours</strong>.
    </p>
    <p style="color:#1e293b;font-weight:600;font-size:16px;">
      Watch your inbox.
    </p>
    <p style="text-align:center;margin:24px 0;">
      <a href="$eUrl" style="display:inline-block;background:#f0f9ff;border:1px solid #bae6fd;color:#0369a1;font-weight:600;padding:12px 24px;border-radius:8px;text-decoration:none;font-size:14px;">
        Come back to your submission &rarr;
      </a>
    </p>
HTML;

    $mail->Body = riderEmailWrap($body);
    $mail->send();
}

// 7. Receipt confirmation — sent to rider when admin confirms receipt
// (Phase 3 email functions below: 8, 9, 10)
function sendReceiptConfirmation(string $email, string $name): void {
    $mail = getMailer('Michi @ WingCoach');
    $mail->addAddress($email);
    $mail->Subject = "Michi just confirmed your submission is in \xF0\x9F\x91\x8B";
    $mail->isHTML(true);

    $eName = htmlspecialchars($name ?: 'there');

    $body = <<<HTML
    <h2 style="color:#0c1929;margin:0 0 12px;font-size:22px;">Hey $eName &#x1F44B;</h2>
    <p style="color:#334155;">
      Just to let you know &mdash; Michi confirmed the receipt of your submission and started working on it.
    </p>
    <p style="color:#1e293b;">
      Stay tuned for your coaching video &mdash; you'll get an email as soon as it's ready.
    </p>
HTML;

    $mail->Body = riderEmailWrap($body);
    $mail->send();
}

// 8. Event inquiry notification — sent to admin when someone submits interest in an event
/**
 * What kind of thing landed in event_inquiries.
 *
 * Everything the site captures goes through the same endpoint, so a replay
 * unlock and a quiz result used to arrive titled "Event Inquiry" exactly like a
 * real camp lead. On 2 September the queue was 15 real enquiries against 16
 * automated captures, which buries the leads that actually need answering.
 *
 * Returns [label, subjectPrefix, accentColour, isLead].
 */
function eventInquiryKind(string $slug): array {
    if (str_starts_with($slug, 'qa-replay'))    return ['Replay unlock', 'Replay unlock',  '#7c5cbf', false];
    if (str_starts_with($slug, 'wing-genius'))  return ['Quiz result',   'Quiz result',    '#0f766e', false];
    if (str_starts_with($slug, 'waitlist'))     return ['Waitlist signup','Waitlist',      '#b45309', false];
    // Asked for a personal look right after typing a question at Q&A signup.
    // The warmest lead the funnel produces, so it gets its own colour.
    if (str_starts_with($slug, 'coaching-call')) return ['1:1 call request', '1:1 call request', '#b91c1c', true];
    return ['Camp enquiry', 'Camp enquiry', '#1580c4', true];
}

function sendEventInquiryNotification(int $inquiryId, string $name, string $email, string $slug, string $eventName, string $level, string $message, string $whatsapp = '', bool $qaSignup = false): void {
    $mail = getMailer('Tricktionary Events');
    $mail->addAddress(NOTIFY_EMAIL);
    [$kindLabel, $kindPrefix, $kindColour, $isLead] = eventInquiryKind($slug);
    // A lead needs a reply; a capture is an FYI. Say so in the subject so the
    // inbox can be scanned without opening anything.
    $mail->Subject = $isLead
        ? "$kindPrefix: $name — $eventName"
        : "$kindPrefix: $name";
    $mail->isHTML(true);

    $eName    = htmlspecialchars($name);
    $eEmail   = htmlspecialchars($email);
    $eEvent   = htmlspecialchars($eventName ?: $slug);
    $eLevel   = htmlspecialchars($level ?: '—');
    $eWhatsapp = htmlspecialchars($whatsapp ?: '—');
    $eMessage = nl2br(htmlspecialchars($message ?: '—'));
    $qaLabel  = $qaSignup ? '<span style="color:#22c55e;font-weight:600;">Yes, wants to join</span>' : '<span style="color:#94a3b8;">No</span>';
    // That tickbox only exists on the camp forms. On a replay unlock or a quiz
    // result it always said "No", which read as a refusal rather than
    // not-applicable, so the row is dropped there. A replay unlock adds the
    // person to qa_audience regardless.
    $qaRow = $isLead
        ? '<tr><td style="color:#94a3b8;padding:8px 14px 8px 0;font-size:13px;">Q&amp;A MEETUP</td><td style="padding:8px 0;font-size:13px;">' . $qaLabel . '</td></tr>'
        : '<tr><td style="color:#94a3b8;padding:8px 14px 8px 0;font-size:13px;">On the Q&amp;A list</td><td style="padding:8px 0;font-size:13px;"><span style="color:#22c55e;font-weight:600;">Added automatically</span></td></tr>';
    $manageUrl = "https://coaching.tricktionary.com/video-coaching/admin#events/{$inquiryId}";
    $actionNote = $isLead
        ? 'This one is a lead and is waiting for a reply.'
        : 'Captured automatically. No reply needed.';

    $mail->Body = <<<HTML
<div style="font-family:'Helvetica Neue',Helvetica,Arial,sans-serif;max-width:600px;margin:0 auto;background:#0f2035;border-radius:12px;overflow:hidden;border:1px solid #1e3a5f;">
  <div style="background:{$kindColour};padding:16px 24px;">
    <h2 style="color:#ffffff;margin:0;font-size:16px;font-weight:600;">{$kindLabel} #{$inquiryId}</h2>
    <p style="color:rgba(255,255,255,0.82);margin:4px 0 0;font-size:12.5px;">{$eEvent}</p>
  </div>
  <div style="padding:24px;">
    <table style="width:100%;border-collapse:collapse;">
      <tr><td style="color:#94a3b8;padding:8px 14px 8px 0;font-size:13px;">Name</td><td style="padding:8px 0;font-size:14px;color:#ffffff;font-weight:600;">$eName</td></tr>
      <tr><td style="color:#94a3b8;padding:8px 14px 8px 0;font-size:13px;">Email</td><td style="padding:8px 0;font-size:13px;"><a href="mailto:$eEmail" style="color:#38bdf8;">$eEmail</a></td></tr>
      <tr><td style="color:#94a3b8;padding:8px 14px 8px 0;font-size:13px;">WhatsApp</td><td style="padding:8px 0;font-size:13px;color:#e2e8f0;">$eWhatsapp</td></tr>
      <tr><td style="color:#94a3b8;padding:8px 14px 8px 0;font-size:13px;">Event</td><td style="padding:8px 0;font-size:13px;color:#e2e8f0;">$eEvent</td></tr>
      $qaRow
    </table>
    <div style="margin-top:16px;">
      <p style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;color:#7dd3fc;margin:0 0 6px;">Message</p>
      <p style="font-size:13px;color:#e2e8f0;background:#1e3a5f;padding:12px 14px;border-radius:8px;margin:0;border-left:3px solid #0ea5e9;">$eMessage</p>
    </div>
    <div style="margin-top:24px;text-align:center;">
      <a href="$manageUrl" style="display:inline-block;padding:11px 22px;background:#1063a0;background-image:linear-gradient(135deg,#1580c4,#0b4f80);color:#ffffff;text-decoration:none;font-size:14px;font-weight:600;border-radius:8px;letter-spacing:0.02em;">Manage in admin &rarr;</a>
      <p style="margin:10px 0 0;font-size:11px;color:#64748b;">$actionNote</p>
    </div>
  </div>
</div>
HTML;

    $mail->send();
}

// 8b. Event inquiry forwarding — clean branded email sent to event organizer
function sendEventInquiryToOrganizer(string $organizerEmail, string $name, string $email, string $slug, string $eventName, string $message, string $whatsapp = ''): void {
    $mail = getMailer('Tricktionary Events');
    $mail->addAddress($organizerEmail);
    $mail->addReplyTo($email, $name);
    $mail->Subject = "New Inquiry: $name — " . ($eventName ?: $slug);
    $mail->isHTML(true);

    $eName    = htmlspecialchars($name);
    $eEmail   = htmlspecialchars($email);
    $eEvent   = htmlspecialchars($eventName ?: $slug);
    $eWhatsapp = $whatsapp ? htmlspecialchars($whatsapp) : '';
    $eMessage = nl2br(htmlspecialchars($message ?: 'No message provided.'));
    $waRow = $eWhatsapp ? '<tr><td style="color:#64748b;padding:8px 14px 8px 0;font-size:13px;">WhatsApp</td><td style="padding:8px 0;font-size:13px;color:#334155;">' . $eWhatsapp . '</td></tr>' : '';

    $mail->Body = <<<HTML
<div style="font-family:'Helvetica Neue',Helvetica,Arial,sans-serif;max-width:600px;margin:0 auto;background:#ffffff;border-radius:12px;overflow:hidden;border:1px solid #e2e8f0;">
  <div style="background:linear-gradient(90deg,#0ea5e9 0%,#0284c7 100%);padding:20px 24px;">
    <h2 style="color:#ffffff;margin:0;font-size:18px;font-weight:600;">Tricktionary Events — New Inquiry</h2>
  </div>
  <div style="padding:24px;">
    <p style="color:#334155;font-size:14px;margin:0 0 16px;">A new inquiry has been submitted for <strong>{$eEvent}</strong>.</p>
    <table style="width:100%;border-collapse:collapse;">
      <tr><td style="color:#64748b;padding:8px 14px 8px 0;font-size:13px;border-bottom:1px solid #f1f5f9;">Name</td><td style="padding:8px 0;font-size:14px;color:#1e293b;font-weight:600;border-bottom:1px solid #f1f5f9;">$eName</td></tr>
      <tr><td style="color:#64748b;padding:8px 14px 8px 0;font-size:13px;border-bottom:1px solid #f1f5f9;">Email</td><td style="padding:8px 0;font-size:13px;border-bottom:1px solid #f1f5f9;"><a href="mailto:$eEmail" style="color:#0b6e93;">$eEmail</a></td></tr>
      <tr><td style="color:#64748b;padding:8px 14px 8px 0;font-size:13px;">Event</td><td style="padding:8px 0;font-size:13px;color:#334155;">$eEvent</td></tr>
      $waRow
    </table>
    <div style="margin-top:16px;">
      <p style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;color:#0b6e93;margin:0 0 6px;">Message</p>
      <p style="font-size:13px;color:#334155;background:#f8fafc;padding:12px 14px;border-radius:8px;margin:0;border-left:3px solid #0ea5e9;">$eMessage</p>
    </div>
    <p style="color:#94a3b8;font-size:11px;margin:20px 0 0;">This inquiry was submitted via <a href="https://events.tricktionary.com" style="color:#0b6e93;">events.tricktionary.com</a>. You can reply directly to the person by responding to this email.</p>
  </div>
</div>
HTML;

    $mail->send();
}

// 9. Q&A signup confirmation — sent to registrant with session details
function sendQaSignupConfirmation(string $email, string $name, array $session): void {
    $mail = getMailer('Michi @ WingCoach');
    $mail->addAddress($email);
    $mail->Subject = "You're in! Q&A with Michi — " . $session['title'];
    $mail->isHTML(true);

    $eName = htmlspecialchars($name);
    $eTitle = htmlspecialchars($session['title']);
    $date = date('l, F j, Y \a\t g:i A', strtotime($session['scheduled_at'])) . ' ' . qaTz($session['scheduled_at']);
    $duration = (int) $session['duration_minutes'];

    // The join link belongs in the confirmation, not only in the .ics: people
    // who do not import the calendar file had no clickable link at all until
    // the first reminder fired (found 2026-08-24).
    $link = trim((string) ($session['meeting_link'] ?? ''));
    $linkBlock = $link !== ''
        ? '<div style="text-align:center;margin:24px 0;">'
          . '<a href="' . htmlspecialchars($link) . '" style="display:inline-block;padding:13px 26px;background:#1063a0;background-image:linear-gradient(135deg,#1580c4,#0b4f80);color:#ffffff;text-decoration:none;font-size:15px;font-weight:700;border-radius:8px;">Join the session</a>'
          . '<p style="margin:10px 0 0;font-size:12px;color:#64748b;">Same link every time. Or paste this into your browser:<br>' . htmlspecialchars($link) . '</p></div>'
        : '';

    $body = <<<HTML
    <h2 style="color:#0c1929;margin:0 0 12px;font-size:22px;">Hey $eName, you're registered!</h2>
    <p style="color:#334155;">You've signed up for the upcoming live Q&amp;A session:</p>
    <div style="background:#f0f9ff;border:1px solid #bae6fd;border-radius:8px;padding:16px 20px;margin:20px 0;">
      <p style="margin:0 0 4px;font-weight:700;color:#0c1929;font-size:16px;">$eTitle</p>
      <p style="margin:0 0 4px;color:#334155;font-size:14px;">$date</p>
      <p style="margin:0;color:#64748b;font-size:13px;">Duration: {$duration} minutes</p>
    </div>
    $linkBlock
    <p style="color:#334155;">I've attached a calendar invite so you can save the time and the link in one tap, and you'll get reminders before we start. If you have a question in the meantime, just reply to this email.</p>
HTML;

    $mail->Body = riderEmailWrap($body);
    attachQaIcs($mail, $session);
    $sampled = qaClaimSample((int)($session['id'] ?? 0), 'confirmation');
    $mail->send();
    qaSendSampleCopy($mail, $sampled);
}

// 10. Q&A signup notification — sent to admin when someone registers
function sendQaSignupNotification(string $name, string $email, array $session, string $message): void {
    $mail = getMailer('Tricktionary Events');
    $mail->addAddress(NOTIFY_EMAIL);
    $mail->Subject = "Q&A Signup: $name — " . $session['title'];
    $mail->isHTML(true);

    $eName    = htmlspecialchars($name);
    $eEmail   = htmlspecialchars($email);
    $eTitle   = htmlspecialchars($session['title']);
    $eMessage = nl2br(htmlspecialchars($message ?: '—'));
    $date     = date('M j, Y H:i', strtotime($session['scheduled_at']));
    $sessionId = (int) ($session['id'] ?? 0);
    $manageUrl = "https://coaching.tricktionary.com/video-coaching/admin#qa/{$sessionId}";

    $mail->Body = <<<HTML
<div style="font-family:'Helvetica Neue',Helvetica,Arial,sans-serif;max-width:600px;margin:0 auto;background:#0f2035;border-radius:12px;overflow:hidden;border:1px solid #1e3a5f;">
  <div style="background:linear-gradient(90deg,#0ea5e9 0%,#0284c7 100%);padding:16px 24px;">
    <h2 style="color:#ffffff;margin:0;font-size:16px;font-weight:600;">Q&amp;A Signup &mdash; $eTitle ($date)</h2>
  </div>
  <div style="padding:24px;">
    <table style="width:100%;border-collapse:collapse;">
      <tr><td style="color:#94a3b8;padding:8px 14px 8px 0;font-size:13px;">Name</td><td style="padding:8px 0;font-size:14px;color:#ffffff;font-weight:600;">$eName</td></tr>
      <tr><td style="color:#94a3b8;padding:8px 14px 8px 0;font-size:13px;">Email</td><td style="padding:8px 0;font-size:13px;"><a href="mailto:$eEmail" style="color:#38bdf8;">$eEmail</a></td></tr>
    </table>
    <div style="margin-top:16px;">
      <p style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;color:#7dd3fc;margin:0 0 6px;">Question / Message</p>
      <p style="font-size:13px;color:#e2e8f0;background:#1e3a5f;padding:12px 14px;border-radius:8px;margin:0;border-left:3px solid #0ea5e9;">$eMessage</p>
    </div>
    <div style="margin-top:24px;text-align:center;">
      <a href="$manageUrl" style="display:inline-block;padding:11px 22px;background:#1063a0;background-image:linear-gradient(135deg,#1580c4,#0b4f80);color:#ffffff;text-decoration:none;font-size:14px;font-weight:600;border-radius:8px;letter-spacing:0.02em;">View Q&amp;A signups in admin &rarr;</a>
    </div>
  </div>
</div>
HTML;

    $mail->send();
}

// 11. Q&A reminder — sent to a registrant ahead of the session per the
//     session's reminder schedule. $offsetKey is '7d' | '24h' | '1h'.
//     Edit the copy here; voice = Michi (warm, practical, no long dashes).
function sendQaReminder(string $email, string $name, array $session, string $offsetKey): void {
    require_once __DIR__ . '/qa_schedules.php';

    $mail = getMailer('Michi @ WingCoach');
    $mail->addAddress($email);

    $eName     = htmlspecialchars($name);
    $eTitle    = htmlspecialchars($session['title']);
    $date      = date('l, F j, Y \a\t g:i A', strtotime($session['scheduled_at'])) . ' ' . qaTz($session['scheduled_at']);
    $duration  = (int) $session['duration_minutes'];
    $link      = trim((string) ($session['meeting_link'] ?? ''));
    $when      = qaOffsetPhrase($offsetKey); // "in 24 hours" / "in about an hour" / "in 7 days"

    $soon = $offsetKey === '1h';
    $live = $offsetKey === 'live';
    $mail->Subject = $live
        ? "🔴 We're LIVE now — {$session['title']}"
        : ($soon
            ? "Starting soon: Q&A with Michi — {$session['title']}"
            : "Reminder: your Q&A with Michi is {$when} — {$session['title']}");
    $mail->isHTML(true);

    $linkBlock = $link !== ''
        ? '<div style="text-align:center;margin:24px 0;">'
          . '<a href="' . htmlspecialchars($link) . '" style="display:inline-block;padding:13px 26px;background:#1063a0;background-image:linear-gradient(135deg,#1580c4,#0b4f80);color:#ffffff;text-decoration:none;font-size:15px;font-weight:700;border-radius:8px;">Join the session</a>'
          . '<p style="margin:10px 0 0;font-size:12px;color:#64748b;">Or paste this into your browser:<br>' . htmlspecialchars($link) . '</p></div>'
        : '<p style="color:#334155;">I will send the meeting link in a follow-up email before we start. Keep an eye on your inbox.</p>';

    $closing = $live
        ? 'Bring your questions - or just jump in and listen 🤙🏼'
        : 'Bring a question - or just come and listen. See you there 🤙🏼';

    $intro = $live
        ? "We just went live! Come on in, we are getting started:"
        : ($soon
            ? "We go live $when. Here is everything you need to jump in:"
            : "Quick reminder that your live Q&A with me is coming up $when. Save the time and the link so you are ready:");

    $heading = $live ? "Hey $eName, we're live!" : "Hey $eName, see you $when";
    $body = <<<HTML
    <h2 style="color:#0c1929;margin:0 0 12px;font-size:22px;">$heading</h2>
    <p style="color:#334155;">$intro</p>
    <div style="background:#f0f9ff;border:1px solid #bae6fd;border-radius:8px;padding:16px 20px;margin:20px 0;">
      <p style="margin:0 0 4px;font-weight:700;color:#0c1929;font-size:16px;">$eTitle</p>
      <p style="margin:0 0 4px;color:#334155;font-size:14px;">$date</p>
      <p style="margin:0;color:#64748b;font-size:13px;">Duration: {$duration} minutes</p>
    </div>
    $linkBlock
    <p style="color:#334155;">$closing</p>
HTML;

    $mail->Body = riderEmailWrap($body);
    attachQaIcs($mail, $session);
    $sampled = qaClaimSample((int)($session['id'] ?? 0), 'reminder-' . $offsetKey);
    $mail->send();
    qaSendSampleCopy($mail, $sampled);
}

// 12. Q&A cancellation — sent to every registrant when a session is cancelled
//     in the admin. Voice = Michi (warm, practical, no long dashes).
function sendQaCancellation(string $email, string $name, array $session): void {
    $mail = getMailer('Michi @ WingCoach');
    $mail->addAddress($email);
    $mail->Subject = "Session cancelled: " . $session['title'];
    $mail->isHTML(true);

    $eName  = htmlspecialchars($name);
    $eTitle = htmlspecialchars($session['title']);
    $date   = date('l, F j, Y \a\t g:i A', strtotime($session['scheduled_at']));

    $body = <<<HTML
    <h2 style="color:#0c1929;margin:0 0 12px;font-size:22px;">Hey $eName, a quick heads-up</h2>
    <p style="color:#334155;">I have to cancel this live Q&amp;A session:</p>
    <div style="background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:16px 20px;margin:20px 0;">
      <p style="margin:0 0 4px;font-weight:700;color:#0c1929;font-size:16px;">$eTitle</p>
      <p style="margin:0;color:#334155;font-size:14px;text-decoration:line-through;">$date</p>
    </div>
    <p style="color:#334155;">Sorry for the change of plans. As soon as a new date is set you'll hear from me, and any question you sent in stays on my list. If there is anything urgent in the meantime, just reply to this email.</p>
HTML;

    $mail->Body = riderEmailWrap($body);
    $mail->send();
}


/**
 * Cross-sell block appended to lifecycle emails. Offers live in
 * api/qa-offers.json so Michi can change them without touching code:
 * [{"title": "...", "text": "...", "url": "...", "cta": "..."}]
 */
function qaOffersBlock(): string {
    $path = __DIR__ . '/../qa-offers.json';
    if (!file_exists($path)) return '';
    $offers = json_decode((string) file_get_contents($path), true);
    if (!is_array($offers) || !$offers) return '';
    $html = '<div style="margin-top:22px;padding-top:16px;border-top:1px solid #e2e8f0;">'
          . '<p style="color:#64748b;font-size:13px;letter-spacing:1px;text-transform:uppercase;margin:0 0 10px;">Also happening</p>';
    foreach (array_slice($offers, 0, 3) as $o) {
        $t = htmlspecialchars($o['title'] ?? ''); $x = htmlspecialchars($o['text'] ?? '');
        $u = htmlspecialchars($o['url'] ?? '#'); $c = htmlspecialchars($o['cta'] ?? 'More');
        $html .= "<p style=\"color:#334155;margin:0 0 10px;\"><strong>$t</strong> - $x <a href=\"$u\" style=\"color:#0b6e93;\">$c &rarr;</a></p>";
    }
    return $html . '</div>';
}

// Lifecycle: replay email, sent automatically once replay_url is set on a past session.
function sendQaReplayEmail(string $email, string $name, array $session, ?array $next): void {
    $mail = getMailer('Michi @ Tricktionary');
    $mail->addAddress($email, $name);
    $date = date('F j', strtotime($session['scheduled_at']));
    $mail->Subject = "The replay is here - thank you! ({$session['title']}, $date)";
    $mail->isHTML(true);
    $eName = htmlspecialchars($name ?: 'there');
    $url = htmlspecialchars($session['replay_url']);
    $nextBlock = '';
    if ($next) {
        $nd = date('l, F j \a\t g:i A', strtotime($next['scheduled_at']));
        // Same signed token the invite uses. We already know who this is going
        // to, so there is no reason to make them retype an address we just
        // mailed. Until 2026-09-02 this was a bare #upcoming anchor and the
        // reader had to fill in the whole signup form again.
        $nextUrl = 'https://events.tricktionary.com/live-qa/?signup=next&amp;t='
                 . rawurlencode(qaAudienceToken($email));
        $nextBlock = '<p style="color:#334155;">The next live Q&A is already set: <strong>' . $nd . ' (' . qaTz($next['scheduled_at']) . ')</strong>, once a month.</p>'
          . '<p style="margin:14px 0;text-align:center;"><a href="' . $nextUrl . '" style="display:inline-block;padding:13px 28px;background:#1063a0;background-image:linear-gradient(135deg,#1580c4,#0b4f80);color:#ffffff;text-decoration:none;font-weight:700;border-radius:8px;font-size:15px;">Save my spot in 1 click</a></p>'
          . '<p style="color:#94a3b8;font-size:13px;text-align:center;margin:0 0 6px;">One tap and you are in. No form, we already have your details.</p>';
    }
    // A clickable thumbnail with the play button burned into the pixels. Mail
    // clients cannot play video and strip the CSS you would use to overlay a
    // button, so the image has to look like a player by itself.
    // Named by convention from the session date, the same way the recording is,
    // and checked on disk rather than over HTTP because this runs on the box
    // that serves it. Missing file simply means no thumbnail, never a broken one.
    $thumbFile = '/home/coaching/public_html/video-coaching/static/replay/qa-'
               . date('Y-m-d', strtotime($session['scheduled_at'])) . '-thumb.jpg';
    $thumbBlock = '';
    if (is_readable($thumbFile)) {
        $thumbUrl = 'https://coaching.tricktionary.com/video-coaching/static/replay/qa-'
                  . date('Y-m-d', strtotime($session['scheduled_at'])) . '-thumb.jpg';
        $thumbBlock = '<a href="' . $url . '" style="display:block;margin:18px 0 6px;">'
          . '<img src="' . $thumbUrl . '" width="520" alt="Watch the replay"'
          . ' style="width:100%;max-width:520px;height:auto;display:block;border-radius:10px;border:0;"></a>';
    }

    $body = '<h2 style="color:#0c1929;margin:0 0 12px;font-size:22px;">Hey ' . $eName . ', the replay is up</h2>'
      . '<p style="color:#334155;">Thanks for being part of the live Q&A. Whether you were on the call or missed it - here is the full recording with clickable chapters.</p>'
      . $thumbBlock
      . '<p style="margin:14px 0 18px;text-align:center;"><a href="' . $url . '" style="display:inline-block;padding:14px 30px;background:#1063a0;background-image:linear-gradient(135deg,#1580c4,#0b4f80);color:#ffffff;text-decoration:none;font-weight:700;border-radius:8px;font-size:16px;">Watch the replay</a></p>'
      . $nextBlock
      . '<p style="color:#334155;">Got a question I did not get to? Just reply to this email.</p>'
      . qaOffersBlock()
      . '<p style="color:#334155;margin-top:20px;">See you on the water,<br><strong>Michi</strong></p>'
      . qaUnsubscribeLine($email);
    $mail->Body = riderEmailWrap($body);
    $sampled = qaClaimSample((int)($session['id'] ?? 0), 'replay');
    $mail->send();
    qaSendSampleCopy($mail, $sampled);
}

// Lifecycle: invite to the whole Q&A audience ~7 days before each session.
function sendQaInviteEmail(string $email, string $name, array $session): void {
    $mail = getMailer('Michi @ Tricktionary');
    $mail->addAddress($email, $name);
    $date = date('l, F j \a\t g:i A', strtotime($session['scheduled_at']));
    $mail->Subject = 'Next live Q&A: ' . date('l, F j', strtotime($session['scheduled_at'])) . ' - you in?';
    $mail->isHTML(true);
    $eName = htmlspecialchars($name ?: 'there');
    $body = '<h2 style="color:#0c1929;margin:0 0 12px;font-size:22px;">Hey ' . $eName . ',</h2>'
      . '<p style="color:#334155;">the next live Q&A is coming up: <strong>' . $date . ' (CEST)</strong>, free on Zoom, English &amp; German. Ask me anything - books, camps, gear, technique - or just listen in.</p>'
      . '<p style="margin:18px 0;text-align:center;"><a href="https://events.tricktionary.com/live-qa/?signup=next&amp;t=' . rawurlencode(qaAudienceToken($email)) . '" style="display:inline-block;padding:14px 30px;background:#1063a0;background-image:linear-gradient(135deg,#1580c4,#0b4f80);color:#ffffff;text-decoration:none;font-weight:700;border-radius:8px;font-size:16px;">Save my spot in 1 click</a></p>'
      . qaOffersBlock()
      . '<p style="color:#334155;margin-top:20px;">See you there,<br><strong>Michi</strong></p>'
      . qaUnsubscribeLine($email);
    $mail->Body = riderEmailWrap($body);
    $sampled = qaClaimSample((int)($session['id'] ?? 0), 'invite');
    $mail->send();
    qaSendSampleCopy($mail, $sampled);
}

/**
 * Stalled submission nudge — rider paid but never sent their videos.
 * Sent by cron/submission-nudge.php. Short on purpose: one reason to come back,
 * one link, one way to reach a human.
 */
function sendSubmissionNudge(string $email, string $name, string $uploadUrl, int $nudgeNumber): void {
    $mail = getMailer();
    $mail->addAddress($email);
    // Standing rule: automated client-facing mail is copied to Michi.
    $mail->addBCC(NOTIFY_EMAIL);

    $eName = htmlspecialchars($name ?: 'there');
    $eUrl  = htmlspecialchars($uploadUrl);

    if ($nudgeNumber >= 3) {
        $mail->Subject = 'Still holding your coaching spot';
        $opening = "Your spot is still open and your answers are still saved. Whenever you get a session on video, send it over and I will work through it.";
    } elseif ($nudgeNumber === 2) {
        $mail->Subject = 'Your coaching spot is waiting for clips';
        $opening = "No rush, but your spot is sitting here waiting for footage. Even one average session filmed from the beach is plenty to work with.";
    } else {
        $mail->Subject = 'Ready when your clips are';
        $opening = "You paid for your coaching spot and filled in your rider profile, so the only thing missing is footage. Phone on the beach is fine, no need for anything fancy.";
    }

    $mail->isHTML(true);
    $body = <<<HTML
    <h2 style="color:#0c1929;margin:0 0 12px;font-size:22px;">Hey $eName</h2>
    <p style="color:#334155;">$opening</p>
    <p style="color:#334155;">
      Two or three minutes of riding, filmed from the side, is the most useful thing you can send. Your answers are already saved, so you only need to add the clips.
    </p>
    <p style="text-align:center;margin:28px 0;">
      <a href="$eUrl" style="display:inline-block;background:#1063a0;background-image:linear-gradient(135deg,#1580c4,#0b4f80);color:#ffffff;padding:14px 32px;border-radius:8px;text-decoration:none;font-weight:700;font-size:15px;">
        Upload my clips &rarr;
      </a>
    </p>
    <p style="color:#64748b;font-size:13px;">
      Stuck on filming or anything else? Just hit reply.
    </p>
HTML;

    $mail->Body = riderEmailWrap($body);
    $mail->send();
}

// ---------------------------------------------------------------------------
// Coaching credits: 1:1 call, video review, 3-session pack
// ---------------------------------------------------------------------------

/**
 * After a coaching purchase: the buyer gets their private link, Michi gets a
 * heads-up. Two separate messages, never a BCC, because a BCC puts Michi's
 * subject line in the customer's copy.
 */
function sendCoachingCreditEmails(
    string $token, string $sku, array $product, string $name, string $email
): void {
    $link  = BASE_URL . '/credit?t=' . $token;
    $n     = (int) $product['credits'];
    $eName = htmlspecialchars(trim(explode(' ', $name)[0] ?? '') ?: 'there');
    $eLink = htmlspecialchars($link);
    $eWhat = htmlspecialchars($product['label']);

    if ($email) {
        $mail = getMailer('Michi Rossmeier');
        $mail->addAddress($email);
        // Michi keeps a copy of every client-facing send.
        $mail->addBCC('rossi@tricktionary.com');
        $mail->Subject = $n > 1
            ? "Your $n coaching sessions are ready"
            : 'Your coaching session is ready';
        $mail->isHTML(true);

        $sessions = $n > 1
            ? "You have <b>$n sessions</b> to use whenever you like, as calls or video reviews, in any mix."
            : "You have <b>one session</b> ready to use.";
        $expiry = $product['valid_months']
            ? '<p style="color:#64748b;font-size:13px;">Valid until '
              . htmlspecialchars(date('j F Y', strtotime('+' . (int) $product['valid_months'] . ' months')))
              . '. Keep this email, the link is your access.</p>'
            : '';

        $body = <<<HTML
        <h2 style="color:#0c1929;margin:0 0 12px;font-size:22px;">Thanks $eName</h2>
        <p style="color:#334155;">$sessions Tell me what you are working on and send a clip, and I will take it from there.</p>
        <p style="text-align:center;margin:28px 0;">
          <a href="$eLink" style="display:inline-block;background:#1063a0;background-image:linear-gradient(135deg,#1580c4,#0b4f80);color:#ffffff;padding:14px 32px;border-radius:8px;text-decoration:none;font-weight:700;font-size:15px;">
            Book my session &rarr;
          </a>
        </p>
        $expiry
HTML;
        $mail->Body = riderEmailWrap($body);
        $mail->send();
    }

    $admin = getMailer('Tricktionary Coaching');
    $admin->addAddress(NOTIFY_EMAIL);
    $admin->Subject = 'Coaching sale: ' . $product['label'] . ' - ' . ($name ?: $email ?: 'unknown');
    $admin->isHTML(true);
    $eEmail = htmlspecialchars($email ?: '-');
    $ePrice = htmlspecialchars(coachingPriceEur($product));
    $admin->Body = riderEmailWrap(
        '<h2 style="color:#0c1929;margin:0 0 12px;font-size:20px;">' . $eWhat . ' sold</h2>'
        . '<p style="color:#334155;">' . htmlspecialchars($name ?: 'No name') . ' &middot; ' . $eEmail
        . '<br>&euro;' . $ePrice . ' &middot; ' . $n . ' credit' . ($n > 1 ? 's' : '') . '</p>'
        . '<p style="color:#64748b;font-size:13px;">Nothing to do yet. You will get a second email when they '
        . 'book the session and tell you what they want to work on.</p>'
    );
    $admin->send();
}

/** A credit was spent. This is the one that needs Michi to act. */
function sendCreditRedemptionNotification(
    int $redemptionId, array $credit, string $kind,
    string $message, string $clipUrl, string $prefer, int $left
): void {
    $isCall = $kind === 'call';
    $mail = getMailer('Tricktionary Coaching');
    $mail->addAddress(NOTIFY_EMAIL);
    $mail->Subject = ($isCall ? '1:1 call booked: ' : 'Video review in: ')
        . ($credit['name'] ?: $credit['email'] ?: 'unknown');
    $mail->isHTML(true);

    $colour  = $isCall ? '#b91c1c' : '#0f766e';
    $eName   = htmlspecialchars($credit['name'] ?: 'No name');
    $eEmail  = htmlspecialchars($credit['email'] ?: '-');
    $eMsg    = nl2br(htmlspecialchars($message));
    $ePrefer = $prefer !== '' ? '<tr><td style="color:#94a3b8;padding:8px 14px 8px 0;font-size:13px;">When they can</td><td style="padding:8px 0;font-size:13px;">' . htmlspecialchars($prefer) . '</td></tr>' : '';
    $eClip   = $clipUrl !== ''
        ? '<tr><td style="color:#94a3b8;padding:8px 14px 8px 0;font-size:13px;">Clips</td><td style="padding:8px 0;font-size:13px;"><a href="' . htmlspecialchars($clipUrl) . '">' . htmlspecialchars($clipUrl) . '</a></td></tr>'
        : '';
    $action = $isCall
        ? 'Reply with two or three times that suit you. The Zoom room is the usual one.'
        : 'Record the reply and send it. The promise on the page is 72 hours.';

    $mail->Body = riderEmailWrap(
        '<h2 style="color:' . $colour . ';margin:0 0 4px;font-size:20px;">'
        . ($isCall ? '1:1 call, 45 minutes' : 'Video review') . '</h2>'
        . '<p style="color:#64748b;font-size:13px;margin:0 0 16px;">Redemption #' . $redemptionId
        . ' &middot; ' . $left . ' session' . ($left === 1 ? '' : 's') . ' left on their account</p>'
        . '<table style="width:100%;border-collapse:collapse;">'
        . '<tr><td style="color:#94a3b8;padding:8px 14px 8px 0;font-size:13px;width:120px;">Who</td><td style="padding:8px 0;font-size:13px;">' . $eName . ' &middot; ' . $eEmail . '</td></tr>'
        . $ePrefer . $eClip
        . '</table>'
        . '<p style="color:#0c1929;font-weight:600;margin:18px 0 6px;font-size:14px;">What they are working on</p>'
        . '<p style="color:#334155;font-size:14px;line-height:1.6;">' . $eMsg . '</p>'
        . '<p style="color:#b45309;font-size:13px;margin-top:18px;"><b>' . htmlspecialchars($action) . '</b></p>'
    );
    $mail->send();
}

// ---------------------------------------------------------------------------
// Rider loop: follow-up questions on the reply page
// ---------------------------------------------------------------------------

/** A rider asked a question under their coaching video. Michi answers it in the admin. */
function sendQuestionToCoach(array $sub, int $questionId, string $question, int $left): void {
    $mail = getMailer('WingCoach');
    $mail->addAddress(NOTIFY_EMAIL);
    $who = $sub['name'] ?: $sub['email'] ?: ('submission #' . $sub['id']);
    $mail->Subject = 'Question from ' . $who . ' on their coaching video';
    $mail->isHTML(true);

    $eWho   = htmlspecialchars($who);
    $eEmail = htmlspecialchars($sub['email'] ?: '-');
    $eQ     = nl2br(htmlspecialchars($question));
    $eAdmin = htmlspecialchars(BASE_URL . '/admin');
    $eReply = htmlspecialchars(BASE_URL . '/reply/' . $sub['token']);
    $leftTxt = $left === 1 ? '1 question' : $left . ' questions';

    $body = <<<HTML
    <h2 style="color:#0c1929;margin:0 0 4px;font-size:20px;">$eWho asked</h2>
    <p style="color:#64748b;font-size:13px;margin:0 0 16px;">Submission #{$sub['id']} &middot; $eEmail &middot; question #$questionId &middot; $leftTxt left on this video</p>
    <blockquote style="margin:0 0 20px;padding:12px 16px;border-left:3px solid #0ea5e9;background:#f1f5f9;color:#1e293b;font-size:15px;line-height:1.6;">$eQ</blockquote>
    <p style="color:#334155;">Answer it in the admin, submission #{$sub['id']} - they get one email with your answer and the link back to the video.</p>
    <p style="text-align:center;margin:24px 0;">
      <a href="$eAdmin" style="display:inline-block;background:#1063a0;background-image:linear-gradient(135deg,#1580c4,#0b4f80);color:#ffffff;padding:12px 28px;border-radius:8px;text-decoration:none;font-weight:700;font-size:15px;">Open the admin</a>
    </p>
    <p style="color:#64748b;font-size:13px;">Their page: <a href="$eReply" style="color:#0b6e93;">$eReply</a></p>
HTML;

    $mail->Body = riderEmailWrap($body);
    $mail->send();
}

/** Michi answered - one email per answer, with the link back to the video. */
function sendQuestionAnswered(string $email, string $name, string $question, string $answer, string $replyUrl): void {
    $mail = getMailer('Michi @ WingCoach');
    $mail->addAddress($email);
    $mail->Subject = 'Michi answered your question';
    $mail->isHTML(true);

    $eName = htmlspecialchars(trim(explode(' ', $name)[0] ?? '') ?: 'there');
    $eQ    = nl2br(htmlspecialchars($question));
    $eA    = nl2br(htmlspecialchars($answer));
    $eUrl  = htmlspecialchars($replyUrl);

    $body = <<<HTML
    <h2 style="color:#0c1929;margin:0 0 12px;font-size:22px;">Hey $eName - here is your answer.</h2>
    <p style="color:#64748b;font-size:13px;margin:0 0 6px;">You asked</p>
    <blockquote style="margin:0 0 18px;padding:12px 16px;border-left:3px solid #cbd5e1;background:#f8fafc;color:#475569;font-size:14px;line-height:1.6;">$eQ</blockquote>
    <p style="color:#64748b;font-size:13px;margin:0 0 6px;">Michi</p>
    <div style="margin:0 0 20px;padding:12px 16px;border-left:3px solid #0ea5e9;background:#f1f5f9;color:#1e293b;font-size:15px;line-height:1.65;">$eA</div>
    <p style="text-align:center;margin:26px 0;">
      <a href="$eUrl" style="display:inline-block;background:#1063a0;background-image:linear-gradient(135deg,#1580c4,#0b4f80);color:#ffffff;padding:14px 32px;border-radius:8px;text-decoration:none;font-weight:700;font-size:16px;">Open your coaching video</a>
    </p>
    <p style="color:#334155;">The answer sits under the video on your page, so you can watch the part it is about again.</p>
HTML;

    $mail->Body = riderEmailWrap($body);
    $mail->send();
}
