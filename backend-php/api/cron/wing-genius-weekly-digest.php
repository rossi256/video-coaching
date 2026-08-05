<?php
/**
 * Wing Genius - Weekly Digest (CLI cron)
 *
 * Queries event_inquiries for the last 7 days where event_slug starts with
 * "wing-genius-" or "waitlist-", aggregates the data, sends Michi a markdown
 * digest email.
 *
 * Cron suggestion: 0 9 * * 1 (Mondays at 09:00 server time)
 *   php /home/coaching/public_html/video-coaching/api/cron/wing-genius-weekly-digest.php
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../helpers/email.php';

$db = getDb();

// Pull the last 7 days of Wing Genius + waitlist inquiries
$stmt = $db->prepare("
    SELECT id, name, email, event_slug, event_name, current_level, message, created_at
    FROM event_inquiries
    WHERE (event_slug LIKE 'wing-genius-%' OR event_slug LIKE 'waitlist-%')
      AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
    ORDER BY created_at DESC
");
$stmt->execute();
$rows = $stmt->fetchAll();

if (empty($rows)) {
    echo "No Wing Genius / waitlist inquiries in the last 7 days. Nothing to send.\n";
    exit;
}

// Parse answers out of the message body. Wing Genius reports include
// "  audience: me", "  level: intermediate", etc.
function parseAnswers(string $msg): array {
    $out = [];
    if (preg_match('/QUIZ ANSWERS.*?(?=^\s*--|\Z)/sm', $msg, $m)) {
        if (preg_match_all('/\s+([a-zA-Z]+):\s+([^\n]+)/', $m[0], $kv, PREG_SET_ORDER)) {
            foreach ($kv as $pair) {
                $out[trim($pair[1])] = trim($pair[2]);
            }
        }
    }
    return $out;
}

// Buckets
$genius        = [];      // wing-genius rows
$waitlistApp   = [];
$waitlistSub   = [];
$profileCount  = [];      // first-flights => 4
$audienceCount = [];
$levelCount    = [];
$goalCount     = [];
$blockCount    = [];
$ageCount      = [];
$regionCount   = [];

foreach ($rows as $r) {
    $slug = $r['event_slug'];

    if (strpos($slug, 'wing-genius-') === 0) {
        $profile = substr($slug, strlen('wing-genius-'));
        $r['profile']  = $profile;
        $r['answers']  = parseAnswers($r['message'] ?? '');
        $genius[]      = $r;

        $profileCount[$profile] = ($profileCount[$profile] ?? 0) + 1;
        $a = $r['answers'];
        if (!empty($a['audience'])) $audienceCount[$a['audience']] = ($audienceCount[$a['audience']] ?? 0) + 1;
        if (!empty($a['level']))    $levelCount[$a['level']]       = ($levelCount[$a['level']]   ?? 0) + 1;
        if (!empty($a['goal']))     $goalCount[$a['goal']]         = ($goalCount[$a['goal']]    ?? 0) + 1;
        if (!empty($a['block']))    $blockCount[$a['block']]       = ($blockCount[$a['block']]  ?? 0) + 1;
        if (!empty($a['age']))      $ageCount[$a['age']]           = ($ageCount[$a['age']]      ?? 0) + 1;
        if (!empty($a['region']))   $regionCount[$a['region']]     = ($regionCount[$a['region']]?? 0) + 1;
    } elseif ($slug === 'waitlist-app') {
        $waitlistApp[] = $r;
    } elseif ($slug === 'waitlist-coaching-subscription') {
        $waitlistSub[] = $r;
    }
}

// Helper: render a top-N counter block as ASCII
function topCounter(array $counts, int $top = 5): string {
    if (empty($counts)) return "  (none)\n";
    arsort($counts);
    $total = array_sum($counts);
    $out = '';
    $i = 0;
    foreach ($counts as $k => $v) {
        if (++$i > $top) break;
        $pct = $total > 0 ? round($v / $total * 100) : 0;
        $out .= sprintf("  %-22s %3d  (%d%%)\n", $k, $v, $pct);
    }
    return $out;
}

// Build the digest body (plain text wrapped in <pre> for the email)
$weekStart = (new DateTime('-7 days'))->format('D j M');
$weekEnd   = (new DateTime('now'))->format('D j M Y');
$total     = count($rows);

$body  = "WING GENIUS - WEEKLY DIGEST\n";
$body .= "===========================\n";
$body .= "Week: $weekStart  to  $weekEnd\n";
$body .= "Total signups (quiz + waitlists): $total\n\n";

$body .= "----------------------------------------\n";
$body .= "QUIZ TAKES (" . count($genius) . ")\n";
$body .= "----------------------------------------\n\n";
$body .= "Profile distribution:\n" . topCounter($profileCount) . "\n";
$body .= "Audience:\n"             . topCounter($audienceCount) . "\n";
$body .= "Level:\n"                . topCounter($levelCount, 6) . "\n";
$body .= "Top goals:\n"            . topCounter($goalCount, 6)  . "\n";
$body .= "Top blockers:\n"         . topCounter($blockCount)    . "\n";
if (!empty($ageCount)) {
    $body .= "Age (only 'me' audience):\n" . topCounter($ageCount) . "\n";
}
$body .= "Region:\n" . topCounter($regionCount) . "\n";

$body .= "----------------------------------------\n";
$body .= "WAIT LISTS\n";
$body .= "----------------------------------------\n\n";
$body .= "Wing Tricktionary app:        " . count($waitlistApp) . "\n";
$body .= "Video coaching subscription:  " . count($waitlistSub) . "\n\n";

if (!empty($waitlistApp)) {
    $body .= "App wait list signups this week:\n";
    foreach ($waitlistApp as $w) {
        $body .= sprintf("  %s  %s <%s>\n", substr($w['created_at'], 0, 10), $w['name'], $w['email']);
    }
    $body .= "\n";
}
if (!empty($waitlistSub)) {
    $body .= "Coaching subscription signups this week:\n";
    foreach ($waitlistSub as $w) {
        $body .= sprintf("  %s  %s <%s>\n", substr($w['created_at'], 0, 10), $w['name'], $w['email']);
    }
    $body .= "\n";
}

$body .= "----------------------------------------\n";
$body .= "RECENT QUIZ TAKES (latest 5)\n";
$body .= "----------------------------------------\n\n";
$recent = array_slice($genius, 0, 5);
foreach ($recent as $r) {
    $body .= sprintf("- %s  %s <%s>\n", substr($r['created_at'], 0, 16), $r['name'], $r['email']);
    $body .= sprintf("  Profile: %s   Level: %s   Goal: %s   Block: %s\n\n",
        $r['profile'],
        $r['answers']['level']  ?? '-',
        $r['answers']['goal']   ?? '-',
        $r['answers']['block']  ?? '-');
}

$body .= "----------------------------------------\n";
$body .= "Cumulative all-time:\n";
$body .= "----------------------------------------\n\n";
$totalAllTime = $db->query("SELECT COUNT(*) FROM event_inquiries WHERE event_slug LIKE 'wing-genius-%'")->fetchColumn();
$totalAppList = $db->query("SELECT COUNT(*) FROM event_inquiries WHERE event_slug = 'waitlist-app'")->fetchColumn();
$totalSubList = $db->query("SELECT COUNT(*) FROM event_inquiries WHERE event_slug = 'waitlist-coaching-subscription'")->fetchColumn();
$body .= sprintf("  Wing Genius total:         %d\n", $totalAllTime);
$body .= sprintf("  App wait list total:       %d\n", $totalAppList);
$body .= sprintf("  Coaching sub list total:   %d\n\n", $totalSubList);

$body .= "-- Wing Genius weekly digest\n";
$body .= "   Sent every Monday morning. Edit cron in crontab on coaching server.\n";

// Email it
try {
    $mail = getMailer('Wing Genius Digest');
    $mail->addAddress(NOTIFY_EMAIL);
    $mail->Subject = "Wing Genius - Weekly Digest ($total this week)";
    $mail->isHTML(true);
    $mail->Body = '<pre style="font-family:Menlo,Consolas,monospace;font-size:13px;line-height:1.55;color:#1e293b;white-space:pre-wrap;">'
                . htmlspecialchars($body)
                . '</pre>';
    $mail->AltBody = $body;
    $mail->send();
    echo "Digest sent to " . NOTIFY_EMAIL . " ($total signups this week)\n";
} catch (\Exception $e) {
    error_log('Wing Genius digest email failed: ' . $e->getMessage());
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
