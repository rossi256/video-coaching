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
 * Cron (every 15 min):
 *   */15 * * * * php /home/coaching/public_html/video-coaching/api/cron/qa-reminders.php >> /home/coaching/logs/wingcoach-cron.log 2>&1
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../helpers/email.php';
require_once __DIR__ . '/../helpers/qa_schedules.php';

$db = getDb();
$schedules = qaReminderSchedules();

$sessions = $db->query("
    SELECT * FROM qa_sessions
    WHERE status = 'upcoming'
      AND reminder_schedule <> 'off'
      AND scheduled_at > NOW()
")->fetchAll();

if (empty($sessions)) {
    exit; // nothing to do
}

// Recipients for one (session, offset): registered by the due moment and not yet reminded for this offset.
$signupStmt = $db->prepare("
    SELECT su.* FROM qa_signups su
    WHERE su.session_id = ?
      AND su.created_at <= ?
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
    $start = strtotime($session['scheduled_at']);

    foreach ($offsets as $off) {
        $dueAt   = $start - ((int) $off['hours'] * 3600);
        // Catch-up window: if the cron was down and we blew well past the due
        // moment, skip rather than send a reminder with stale "in 24 hours" wording.
        $catchUp = min((int) $off['hours'], 6) * 3600;

        if ($now < $dueAt)            continue; // not due yet
        if ($now >= $start)           continue; // session already started
        if ($now - $dueAt > $catchUp) continue; // missed window, don't send stale

        $signupStmt->execute([$session['id'], date('Y-m-d H:i:s', $dueAt), $off['key']]);
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
