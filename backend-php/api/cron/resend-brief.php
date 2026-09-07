<?php
/**
 * WingCoach — resend the coaching brief for one submission.
 *
 * The submission notification is the only thing that tells Michi a rider is
 * waiting. If it is missed, filtered, or (as on 2026-09-02) arrives without
 * any mention of the uploaded video, there is no way to ask for it again.
 *
 *   php cron/resend-brief.php 4 --dry-run   # show what would go out
 *   php cron/resend-brief.php 4             # send it to NOTIFY_EMAIL
 *
 * Goes to NOTIFY_EMAIL only. It never mails the rider.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../helpers/email.php';

$id     = (int) ($argv[1] ?? 0);
$dryRun = in_array('--dry-run', $argv ?? [], true);

if (!$id) {
    fwrite(STDERR, "Usage: php resend-brief.php <submissionId> [--dry-run]\n");
    exit(1);
}

$stmt = getDb()->prepare('SELECT * FROM submissions WHERE id = ?');
$stmt->execute([$id]);
$sub = $stmt->fetch();

if (!$sub) {
    fwrite(STDERR, "No submission #$id\n");
    exit(1);
}

$dir = UPLOADS_DIR . '/' . $id;
$files = is_dir($dir) ? array_values(array_filter(scandir($dir), fn($f) => $f[0] !== '.' && is_file("$dir/$f"))) : [];

echo "Submission #$id\n";
echo "  rider     : {$sub['name']} <{$sub['email']}>\n";
echo "  status    : {$sub['status']}  submitted {$sub['submitted_at']}\n";
echo "  files     : " . ($files ? implode(', ', $files) : 'NONE') . "\n";
echo "  recipient : " . NOTIFY_EMAIL . "\n";

if ($dryRun) {
    echo "\n[dry-run] nothing sent\n";
    exit(0);
}

sendSubmissionNotification((string) $sub['name'], (string) $sub['email'], $id, $sub);
echo "\nBrief sent to " . NOTIFY_EMAIL . "\n";
