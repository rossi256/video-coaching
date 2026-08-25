<?php
/**
 * Report people who are on the Q&A list under more than one address, so they do
 * not get two invites. Read only: it never merges anything, because which
 * address a person actually reads is not something the data can tell you.
 *
 * Usage: php qa-duplicates.php
 */
require_once '/home/coaching/public_html/video-coaching/api/config.php';
$pdo = getDb();

$rows = $pdo->query("
    SELECT LOWER(TRIM(name)) AS k, GROUP_CONCAT(CONCAT(email, ' (', first_source, ', seen ', DATE(first_seen), ')') SEPARATOR '  |  ') AS entries, COUNT(*) n
    FROM qa_audience
    WHERE name <> '' AND unsubscribed = 0
    GROUP BY LOWER(TRIM(name))
    HAVING n > 1
")->fetchAll(PDO::FETCH_ASSOC);

if (!$rows) { echo "No duplicate names on the list.\n"; exit; }
echo count($rows) . " person(s) on the list more than once:\n\n";
foreach ($rows as $r) {
    echo "  {$r['k']}  ({$r['n']} addresses)\n    {$r['entries']}\n\n";
}
echo "Nothing was changed. Decide which address to keep, then set unsubscribed=1\n";
echo "on the other one so the lifecycle cron skips it.\n";
