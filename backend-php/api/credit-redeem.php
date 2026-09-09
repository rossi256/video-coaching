<?php
/**
 * Spend one coaching credit.
 *
 * POST { token, kind: "review" | "call", message, clip_url?, prefer? }
 *   -> { ok: true, left: 2 }
 *
 * A credit is spent by inserting a redemption row, so the balance is always
 * COUNT(redemptions) against credits_total and can be audited. The insert runs
 * inside a transaction with the parent row locked, because two taps on a phone
 * a moment apart would otherwise both read "1 left" and both succeed.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers/coaching-products.php';
require_once __DIR__ . '/helpers/email.php';
setApiHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['error' => 'Method not allowed'], 405);
}

$body    = getJsonBody();
$token   = trim($body['token'] ?? '');
$kind    = trim($body['kind'] ?? '');
$message = trim($body['message'] ?? '');
$clipUrl = trim($body['clip_url'] ?? '');
$prefer  = trim($body['prefer'] ?? '');   // when they can make a call

if (!isset(CREDIT_KINDS[$kind])) {
    jsonResponse(['error' => 'Pick a video review or a call'], 400);
}
if ($message === '') {
    jsonResponse(['error' => 'Tell Michi what you are working on'], 400);
}

$db = getDb();
$credit = creditByToken($db, $token);
if (!$credit) {
    jsonResponse(['error' => 'That link is not valid'], 404);
}

try {
    $db->beginTransaction();

    // Lock the purchase row so two concurrent redemptions cannot both pass the
    // balance check below.
    $lock = $db->prepare('SELECT credits_total, expires_at FROM coaching_credits WHERE id = ? FOR UPDATE');
    $lock->execute([$credit['id']]);
    $locked = $lock->fetch(PDO::FETCH_ASSOC);

    $used = (int) $db->query(
        'SELECT COUNT(*) FROM credit_redemptions WHERE credit_id = ' . (int) $credit['id']
    )->fetchColumn();
    $left = (int) $locked['credits_total'] - $used;

    if ($locked['expires_at'] !== null && $locked['expires_at'] < date('Y-m-d')) {
        $db->rollBack();
        jsonResponse(['error' => 'These sessions expired on ' . $locked['expires_at'] . '. Email Michi and he will sort it out.'], 400);
    }
    if ($left <= 0) {
        $db->rollBack();
        jsonResponse(['error' => 'All of your sessions are used. Thanks, and grab another whenever you like.'], 400);
    }

    $db->prepare(
        'INSERT INTO credit_redemptions (credit_id, kind, message, clip_url, status)
         VALUES (?, ?, ?, ?, ?)'
    )->execute([
        $credit['id'], $kind,
        $prefer !== '' ? $message . "\n\nWhen they can make it: " . $prefer : $message,
        $clipUrl ?: null,
        'requested',
    ]);
    $redemptionId = (int) $db->lastInsertId();

    $db->commit();
} catch (\Exception $e) {
    if ($db->inTransaction()) $db->rollBack();
    error_log('Credit redeem error: ' . $e->getMessage());
    jsonResponse(['error' => 'Something went wrong. Try again, or email rossi@tricktionary.com.'], 500);
}

try {
    sendCreditRedemptionNotification($redemptionId, $credit, $kind, $message, $clipUrl, $prefer, $left - 1);
} catch (\Exception $e) {
    error_log('Credit redemption notification error: ' . $e->getMessage());
}

jsonResponse(['ok' => true, 'left' => $left - 1, 'kind' => $kind]);
