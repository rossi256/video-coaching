<?php
/**
 * WingCoach — Stalled Submission Nudge (CLI cron)
 *
 * The gap this closes: a rider pays, lands on the success page, maybe fills in
 * the questionnaire, then never uploads video. Nothing in the system noticed.
 * They are out 49 EUR and Michi never hears about it.
 *
 * Finds submissions that are paid but never submitted and emails a short
 * reminder with their resume link, at most MAX_NUDGES times.
 *
 * Usage:
 *   php submission-nudge.php --dry-run   # print what would be sent, touch nothing
 *   php submission-nudge.php             # send
 *
 * Cron (daily 10:00):
 *   0 10 * * * /usr/bin/php8.4 /home/coaching/public_html/video-coaching/api/cron/submission-nudge.php >> /home/coaching/logs/wingcoach-nudge.log 2>&1
 *
 * SAFETY RAILS, all deliberate:
 *   - nudge_opt_out = 1 on a row means this script never emails that person.
 *     Every row that existed when this shipped was seeded opt-out, so switching
 *     the cron on cannot mail anyone retroactively. New customers default in.
 *   - MAX_AGE_DAYS caps how far back it will ever reach, so a paused cron
 *     coming back to life cannot blast a backlog.
 *   - MIN_GAP_HOURS means editing the schedule below cannot double-send.
 *   - --dry-run performs no sends and no writes.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../helpers/email.php';

const MAX_NUDGES    = 3;
const MAX_AGE_DAYS  = 30;   // never touch anything older than this
const MIN_GAP_HOURS = 72;   // never send two nudges closer together than this

// Hours after payment at which nudge 1, 2 and 3 become due.
const NUDGE_SCHEDULE_HOURS = [48, 168, 336]; // 2 days, 7 days, 14 days

$dryRun = in_array('--dry-run', $argv ?? [], true);
$prefix = $dryRun ? '[dry-run] ' : '';

$db = getDb();

$stmt = $db->prepare("
    SELECT id, name, email, stripe_session_id, created_at, nudge_count, nudge_last_at
    FROM submissions
    WHERE status = 'paid'
      AND submitted_at IS NULL
      AND nudge_opt_out = 0
      AND nudge_count < :max_nudges
      AND email IS NOT NULL AND email <> ''
      AND created_at > DATE_SUB(NOW(), INTERVAL :max_age DAY)
    ORDER BY id
");
$stmt->bindValue(':max_nudges', MAX_NUDGES, PDO::PARAM_INT);
$stmt->bindValue(':max_age', MAX_AGE_DAYS, PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll();

if (!$rows) {
    echo $prefix . "No stalled submissions due a nudge.\n";
    exit(0);
}

$now = new DateTimeImmutable('now');
$sent = 0;
$skipped = 0;

foreach ($rows as $row) {
    $paidAt    = new DateTimeImmutable($row['created_at']);
    $ageHours  = ($now->getTimestamp() - $paidAt->getTimestamp()) / 3600;
    $nextNudge = (int) $row['nudge_count'] + 1;
    $dueAfter  = NUDGE_SCHEDULE_HOURS[$nextNudge - 1] ?? null;

    if ($dueAfter === null) { $skipped++; continue; }

    if ($ageHours < $dueAfter) {
        echo $prefix . "#{$row['id']} {$row['email']}: nudge $nextNudge not due yet ("
           . round($ageHours) . "h of {$dueAfter}h)\n";
        $skipped++;
        continue;
    }

    if ($row['nudge_last_at']) {
        $sinceLast = ($now->getTimestamp() - (new DateTimeImmutable($row['nudge_last_at']))->getTimestamp()) / 3600;
        if ($sinceLast < MIN_GAP_HOURS) {
            echo $prefix . "#{$row['id']} {$row['email']}: last nudge only "
               . round($sinceLast) . "h ago, holding\n";
            $skipped++;
            continue;
        }
    }

    $uploadUrl = BASE_URL . '/success?session_id=' . $row['stripe_session_id'];

    if ($dryRun) {
        echo "[dry-run] WOULD SEND nudge $nextNudge to #{$row['id']} {$row['email']} "
           . "(paid " . round($ageHours) . "h ago) -> $uploadUrl\n";
        $sent++;
        continue;
    }

    try {
        sendSubmissionNudge($row['email'], (string) $row['name'], $uploadUrl, $nextNudge);
        $db->prepare('UPDATE submissions SET nudge_count = nudge_count + 1, nudge_last_at = NOW() WHERE id = ?')
           ->execute([$row['id']]);
        echo "Nudge $nextNudge sent to #{$row['id']} {$row['email']}\n";
        $sent++;
    } catch (\Exception $e) {
        error_log('Submission nudge error for ' . $row['email'] . ': ' . $e->getMessage());
        echo "ERROR #{$row['id']} {$row['email']}: {$e->getMessage()}\n";
    }
}

echo $prefix . "Done. sent=$sent skipped=$skipped\n";
