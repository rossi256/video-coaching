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
      Got questions? <a href="https://wa.me/4369913909040" style="color:#0ea5e9;text-decoration:none;">WhatsApp Michi</a> &middot; <a href="mailto:info@tricktionary.com" style="color:#0ea5e9;text-decoration:none;">info@tricktionary.com</a>
    </p>
    <p style="margin:0;font-size:12px;color:#94a3b8;">
      WingCoach by Michael Rossmeier &middot; <a href="https://tricktionary.com" style="color:#94a3b8;text-decoration:none;">Tricktionary</a> &middot; &copy; 2026
    </p>
  </div>
</div>
HTML;
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
    <div style="margin-top:28px;text-align:center;">
      <a href="$adminUrl" style="display:inline-block;background:#0ea5e9;color:#ffffff;font-weight:700;padding:12px 32px;border-radius:8px;text-decoration:none;font-size:14px;">
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
      <a href="$eUrl" style="display:inline-block;background:#0ea5e9;color:#ffffff;padding:14px 32px;border-radius:8px;text-decoration:none;font-weight:700;font-size:16px;">
        Watch Your Coaching Feedback
      </a>
    </p>
    <p style="color:#64748b;font-size:13px;">Or copy this link: <a href="$eUrl" style="color:#0ea5e9;">$eUrl</a></p>
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
      <a href="$eUrl" style="display:inline-block;background:#0ea5e9;color:#ffffff;padding:14px 32px;border-radius:8px;text-decoration:none;font-weight:700;font-size:15px;">
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
      <a href="$eUrl" style="display:inline-block;background:#0ea5e9;color:#ffffff;padding:14px 32px;border-radius:8px;text-decoration:none;font-weight:700;font-size:15px;">
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
function sendEventInquiryNotification(int $inquiryId, string $name, string $email, string $slug, string $eventName, string $level, string $message, string $whatsapp = '', bool $qaSignup = false): void {
    $mail = getMailer('Tricktionary Events');
    $mail->addAddress(NOTIFY_EMAIL);
    $mail->Subject = "Event Inquiry: $name — $eventName";
    $mail->isHTML(true);

    $eName    = htmlspecialchars($name);
    $eEmail   = htmlspecialchars($email);
    $eEvent   = htmlspecialchars($eventName ?: $slug);
    $eLevel   = htmlspecialchars($level ?: '—');
    $eWhatsapp = htmlspecialchars($whatsapp ?: '—');
    $eMessage = nl2br(htmlspecialchars($message ?: '—'));
    $qaLabel  = $qaSignup ? '<span style="color:#22c55e;font-weight:600;">Yes — wants to join</span>' : '<span style="color:#94a3b8;">No</span>';

    $mail->Body = <<<HTML
<div style="font-family:'Helvetica Neue',Helvetica,Arial,sans-serif;max-width:600px;margin:0 auto;background:#0f2035;border-radius:12px;overflow:hidden;border:1px solid #1e3a5f;">
  <div style="background:linear-gradient(90deg,#0ea5e9 0%,#0284c7 100%);padding:16px 24px;">
    <h2 style="color:#ffffff;margin:0;font-size:16px;font-weight:600;">Event Inquiry #{$inquiryId} &mdash; {$eEvent}</h2>
  </div>
  <div style="padding:24px;">
    <table style="width:100%;border-collapse:collapse;">
      <tr><td style="color:#94a3b8;padding:8px 14px 8px 0;font-size:13px;">Name</td><td style="padding:8px 0;font-size:14px;color:#ffffff;font-weight:600;">$eName</td></tr>
      <tr><td style="color:#94a3b8;padding:8px 14px 8px 0;font-size:13px;">Email</td><td style="padding:8px 0;font-size:13px;"><a href="mailto:$eEmail" style="color:#38bdf8;">$eEmail</a></td></tr>
      <tr><td style="color:#94a3b8;padding:8px 14px 8px 0;font-size:13px;">WhatsApp</td><td style="padding:8px 0;font-size:13px;color:#e2e8f0;">$eWhatsapp</td></tr>
      <tr><td style="color:#94a3b8;padding:8px 14px 8px 0;font-size:13px;">Event</td><td style="padding:8px 0;font-size:13px;color:#e2e8f0;">$eEvent</td></tr>
      <tr><td style="color:#94a3b8;padding:8px 14px 8px 0;font-size:13px;">Q&amp;A MEETUP</td><td style="padding:8px 0;font-size:13px;">$qaLabel</td></tr>
    </table>
    <div style="margin-top:16px;">
      <p style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;color:#7dd3fc;margin:0 0 6px;">Message</p>
      <p style="font-size:13px;color:#e2e8f0;background:#1e3a5f;padding:12px 14px;border-radius:8px;margin:0;border-left:3px solid #0ea5e9;">$eMessage</p>
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
      <tr><td style="color:#64748b;padding:8px 14px 8px 0;font-size:13px;border-bottom:1px solid #f1f5f9;">Email</td><td style="padding:8px 0;font-size:13px;border-bottom:1px solid #f1f5f9;"><a href="mailto:$eEmail" style="color:#0ea5e9;">$eEmail</a></td></tr>
      <tr><td style="color:#64748b;padding:8px 14px 8px 0;font-size:13px;">Event</td><td style="padding:8px 0;font-size:13px;color:#334155;">$eEvent</td></tr>
      $waRow
    </table>
    <div style="margin-top:16px;">
      <p style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;color:#0ea5e9;margin:0 0 6px;">Message</p>
      <p style="font-size:13px;color:#334155;background:#f8fafc;padding:12px 14px;border-radius:8px;margin:0;border-left:3px solid #0ea5e9;">$eMessage</p>
    </div>
    <p style="color:#94a3b8;font-size:11px;margin:20px 0 0;">This inquiry was submitted via <a href="https://events.tricktionary.com" style="color:#0ea5e9;">events.tricktionary.com</a>. You can reply directly to the person by responding to this email.</p>
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
    $date = date('l, F j, Y \a\t g:i A', strtotime($session['scheduled_at']));
    $duration = (int) $session['duration_minutes'];

    $body = <<<HTML
    <h2 style="color:#0c1929;margin:0 0 12px;font-size:22px;">Hey $eName &mdash; you're registered!</h2>
    <p style="color:#334155;">You've signed up for the upcoming live Q&amp;A session:</p>
    <div style="background:#f0f9ff;border:1px solid #bae6fd;border-radius:8px;padding:16px 20px;margin:20px 0;">
      <p style="margin:0 0 4px;font-weight:700;color:#0c1929;font-size:16px;">$eTitle</p>
      <p style="margin:0 0 4px;color:#334155;font-size:14px;">$date</p>
      <p style="margin:0;color:#64748b;font-size:13px;">Duration: {$duration} minutes</p>
    </div>
    <p style="color:#334155;">You'll receive the meeting link by email before the session starts. If you have any questions in the meantime, just reply to this email.</p>
HTML;

    $mail->Body = riderEmailWrap($body);
    $mail->send();
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
  </div>
</div>
HTML;

    $mail->send();
}
