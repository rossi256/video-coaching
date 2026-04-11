<?php
require_once __DIR__ . '/config.php';
setApiHeaders();

$db = getDb();
$total = (int) $db->query("SELECT value FROM config WHERE `key` = 'total_spots'")->fetchColumn();
$taken = (int) $db->query("SELECT value FROM config WHERE `key` = 'spots_taken'")->fetchColumn();

jsonResponse([
    'remaining' => max(0, $total - $taken),
    'total' => $total,
    'taken' => $taken,
]);
