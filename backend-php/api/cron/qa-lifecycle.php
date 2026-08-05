<?php
/**
 * WingCoach - Q&A lifecycle cron (runs hourly)
 *
 * Automates the between-sessions flow so no human (or AI) has to remember it:
 *
 *  1. REPLAY EMAIL - when a past session gets a `replay_url`, every signup of
 *     that session receives the replay email exactly once
 *     (stamped in qa_sessions.replay_email_sent_at).
 *
 *  2. AUDIENCE INVITE - 6-8 days before each upcoming session, the whole
 *     qa_audience (minus people already signed up for it, minus unsubscribed)
 *     gets one invite email (stamped in qa_sessions.invite_email_sent_at).
 *
 * Dry run: php qa-lifecycle.php --dry   (prints the plan, sends nothing)
 *
 * Cron: 5 * * * * php qa-lifecycle.php >> /home/coaching/qa-lifecycle.log 2>&1
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../helpers/email.php';

$dry = in_array('--dry', $argv ?? [], true);
$db = getDb();

/* ---------- 1. Replay emails ---------- */
$sessions = $db->query("
    SELECT * FROM qa_sessions
    WHERE status = 'past'
      AND replay_url IS NOT NULL AND replay_url <> ''
      AND replay_email_sent_at IS NULL
      AND scheduled_at > NOW() - INTERVAL 21 DAY
")->fetchAll();

foreach ($sessions as $session) {
    $signups = $db->prepare('SELECT name, email FROM qa_signups WHERE session_id = ?');
    $signups->execute([$session['id']]);
    $rows = $signups->fetchAll();
    $next = $db->query("SELECT * FROM qa_sessions WHERE status = 'upcoming' AND scheduled_at > NOW() ORDER BY scheduled_at ASC LIMIT 1")->fetch() ?: null;

    if ($dry) {
        echo "[dry] would send REPLAY email for session {$session['id']} to " . count($rows) . " signups\n";
        continue;
    }
    $sent = 0;
    foreach ($rows as $r) {
        try { sendQaReplayEmail($r['email'], $r['name'], $session, $next); $sent++; usleep(400000); }
        catch (\Throwable $t) { error_log("replay mail fail {$r['email']}: " . $t->getMessage()); }
    }
    $db->prepare('UPDATE qa_sessions SET replay_email_sent_at = NOW() WHERE id = ?')->execute([$session['id']]);
    echo "Replay email sent for session {$session['id']} to $sent signups\n";
}

/* ---------- 2. Audience invites (T-7) ---------- */
$sessions = $db->query("
    SELECT * FROM qa_sessions
    WHERE status = 'upcoming'
      AND invite_email_sent_at IS NULL
      AND scheduled_at BETWEEN NOW() + INTERVAL 6 DAY AND NOW() + INTERVAL 8 DAY
")->fetchAll();

foreach ($sessions as $session) {
    $aud = $db->prepare("
        SELECT a.name, a.email FROM qa_audience a
        WHERE a.unsubscribed = 0
          AND a.email NOT IN (SELECT LOWER(email) FROM qa_signups WHERE session_id = ?)
    ");
    $aud->execute([$session['id']]);
    $rows = $aud->fetchAll();

    if ($dry) {
        echo "[dry] would send INVITE for session {$session['id']} ({$session['scheduled_at']}) to " . count($rows) . " audience members\n";
        continue;
    }
    $sent = 0;
    foreach ($rows as $r) {
        try { sendQaInviteEmail($r['email'], $r['name'], $session); $sent++; usleep(400000); }
        catch (\Throwable $t) { error_log("invite mail fail {$r['email']}: " . $t->getMessage()); }
    }
    $db->prepare('UPDATE qa_sessions SET invite_email_sent_at = NOW() WHERE id = ?')->execute([$session['id']]);
    echo "Invite sent for session {$session['id']} to $sent audience members\n";
}

if ($dry) echo "[dry] done\n";
