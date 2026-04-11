<?php
require_once __DIR__ . '/config.php';
setApiHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'PATCH') {
    jsonResponse(['error' => 'Method not allowed'], 405);
}

$id = (int) ($_GET['id'] ?? 0);
if (!$id) jsonResponse(['error' => 'Invalid ID'], 400);

$body = getJsonBody();

$allowed = ['name', 'email', 'age', 'location', 'ride_frequency', 'conditions',
            'equipment', 'level', 'stuck_on', 'tried', 'success_looks_like'];

$fields = [];
foreach ($allowed as $key) {
    if (array_key_exists($key, $body)) {
        $fields[$key] = $body[$key];
    }
}

if (empty($fields)) {
    jsonResponse(['ok' => true]);
}

$db = getDb();
$setParts = [];
$vals = [];
foreach ($fields as $k => $v) {
    $setParts[] = "`$k` = ?";
    $vals[] = $v;
}
$vals[] = $id;
$db->prepare('UPDATE submissions SET ' . implode(', ', $setParts) . ' WHERE id = ?')->execute($vals);

jsonResponse(['ok' => true]);
