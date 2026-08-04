<?php
/**
 * WingCoach — Q&A Reminder Funnel (CLI cron)
 *
 * For every upcoming Q&A session that has a reminder schedule set, sends each
 * configured reminder (e.g. 7d / 24h / 1h before) to its registrants. Every
 * send is recorded in qa_reminder_log, so re-runs never double-send.
 *
 * Sessions with reminder_schedule = 'off' are ignored — nothing sends until
 * Michi picks a schedule on a session in the admin.
 *
 * Cron (every 15 min, written without a slash-star to keep this block comment valid):
 *   0,15,30,45 * * * * php /home/coaching/public_html/video-coaching/api/cron/qa-reminders.php >> /home/coaching/logs/wingcoach-cron.log 2>&1
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../helpers/email.php';
require_once __DIR__ . '/../helpers/qa_schedules.php';

$db = getDb();
$schedules = qaReminderSchedules();

// Housekeeping: a session whose time has passed is no longer "upcoming". Flip it
// to 'past' so the admin list and status stay honest. Safe — every reminder offset
// fires before scheduled_at, so a past session has nothing left to send.
// Grace period: keep just-started sessions 'upcoming' for 20 minutes so the
// 'live' (0h) reminder can fire; flip to 'past' only after the window closes.
$db->exec("UPDATE qa_sessions SET status = 'past' WHERE status = 'upcoming' AND scheduled_at < NOW() - INTERVAL 20 MINUTE");

// NOTE: scheduled_ts comes from MySQL UNIX_TIMESTAMP() so it is a correct epoch in
// the DB's timezone. Do NOT use PHP strtotime() on scheduled_at here — PHP runs in
// UTC while MySQL stores local (CEST), which would skew every reminder by hours.
$sessions = $db->query("
    SELECT *, UNIX_TIMESTAMP(scheduled_at) AS scheduled_ts FROM qa_sessions
    WHERE status = 'upcoming'
      AND reminder_schedule <> 'off'
      AND scheduled_at > NOW() - INTERVAL 20 MINUTE
")->fetchAll();

if (empty($sessions)) {
    exit; // nothing to do
}

// Recipients for one (session, offset): everyone registered for the session who
// hasn't already been sent this offset. The catch-up window below bounds how late
// an offset may fire, so late registrants only ever get near-term, correctly-worded
// reminders (e.g. a last-minute signup gets the "1h" nudge, never a stale "24h" one).
$signupStmt = $db->prepare("
    SELECT su.* FROM qa_signups su
    WHERE su.session_id = ?
      AND NOT EXISTS (
          SELECT 1 FROM qa_reminder_log l
          WHERE l.signup_id = su.id AND l.offset_key = ?
      )
");
$logStmt = $db->prepare(
    'INSERT IGNORE INTO qa_reminder_log (signup_id, session_id, offset_key) VALUES (?, ?, ?)'
);

$now = time();

foreach ($sessions as $session) {
    $offsets = $schedules[$session['reminder_schedule']] ?? [];
    $start = (int) $session['scheduled_ts'];

    foreach ($offsets as $off) {
        $dueAt   = $start - ((int) $off['hours'] * 3600);
        // Catch-up window: if the cron was down and we blew well past the due
        // moment, skip rather than send a reminder with stale "in 24 hours" wording.
        // The 'live' offset (0h) fires AT start and stays valid 15 minutes in.
        $isLive  = $off['key'] === 'live';
        $catchUp = $isLive ? 900 : min((int) $off['hours'], 6) * 3600;

        if ($now < $dueAt)                      continue; // not due yet
        if (!$isLive && $now >= $start)         continue; // session already started
        if ($now - $dueAt > $catchUp)           continue; // missed window, don't send stale

        $signupStmt->execute([$session['id'], $off['key']]);
        $recipients = $signupStmt->fetchAll();

        foreach ($recipients as $r) {
            try {
                sendQaReminder($r['email'], $r['name'], $session, $off['key']);
                $logStmt->execute([$r['id'], $session['id'], $off['key']]);
                echo "Sent {$off['key']} reminder to {$r['email']} (session {$session['id']})\n";
            } catch (\Exception $e) {
                error_log('QA reminder error session ' . $session['id'] . ' signup ' . $r['id'] . ': ' . $e->getMessage());
                echo "ERROR {$r['email']}: {$e->getMessage()}\n";
            }
        }
    }
}
