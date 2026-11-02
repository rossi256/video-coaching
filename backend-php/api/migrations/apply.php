<?php
/**
 * Apply one SQL migration file from this folder, statement by statement.
 *   php migrations/apply.php 2026-11-02-community-wall.sql
 * Statements are split on ";\n". Comments (-- ...) are dropped. Each statement
 * runs on its own, so an idempotent file (IF NOT EXISTS) can be re-run safely.
 */
require_once __DIR__ . '/../config.php';
$file = $argv[1] ?? '';
if ($file === '' || !preg_match('/^[A-Za-z0-9._-]+\.sql$/', $file) || !is_file(__DIR__ . '/' . $file)) {
    fwrite(STDERR, "usage: php apply.php <file.sql> (a file in this folder)\n");
    exit(1);
}
$sql = file_get_contents(__DIR__ . '/' . $file);
$sql = preg_replace('/^\s*--.*$/m', '', $sql);
$db = getDb();
$n = 0;
foreach (preg_split('/;\s*\n/', $sql) as $stmt) {
    $stmt = trim($stmt);
    if ($stmt === '') continue;
    $db->exec($stmt);
    $n++;
    echo 'ok: ' . preg_replace('/\s+/', ' ', mb_substr($stmt, 0, 70)) . "\n";
}
echo "$n statements applied from $file\n";
