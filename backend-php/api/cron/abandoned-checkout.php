<?php
/**
 * WingCoach — Abandoned Checkout Reminder (CLI cron)
 *
 * Finds checkout_attempts older than 30 min that haven't been reminded
 * and haven't converted, then sends reminder emails.
 *
 * Cron (every 5 min): 0,5,10,15,20,25,30,35,40,45,50,55 * * * * php /path/to/cron/abandoned-checkout.php
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../helpers/email.php';

// Upper bound on how far back this will ever reach. Without it, a cron that
// has been off (this one was never scheduled at all until 2026-09-02) wakes up
// and mails every unconverted attempt in history at once.
const MAX_AGE_HOURS = 24;

$dryRun = in_array('--dry-run', $argv ?? [], true);
$prefix = $dryRun ? '[dry-run] ' : '';

$db = getDb();

// Find unreminded, unconverted attempts between 30 minutes and MAX_AGE_HOURS old
$stmt = $db->prepare("
    SELECT id, email
    FROM checkout_attempts
    WHERE converted = 0
      AND reminded_at IS NULL
      AND created_at < DATE_SUB(NOW(), INTERVAL 30 MINUTE)
      AND created_at > DATE_SUB(NOW(), INTERVAL :max_age HOUR)
");
$stmt->bindValue(':max_age', MAX_AGE_HOURS, PDO::PARAM_INT);
$stmt->execute();
$attempts = $stmt->fetchAll();

if (empty($attempts)) {
    exit; // nothing to do
}

$checkoutUrl = BASE_URL . '/';

foreach ($attempts as $attempt) {
    if ($dryRun) {
        echo "[dry-run] WOULD REMIND {$attempt['email']}\n";
        continue;
    }
    try {
        sendAbandonedCheckoutReminder($attempt['email'], $checkoutUrl);
        $db->prepare('UPDATE checkout_attempts SET reminded_at = NOW() WHERE id = ?')
           ->execute([$attempt['id']]);
        echo "Reminder sent to {$attempt['email']}\n";
    } catch (\Exception $e) {
        error_log('Abandoned checkout email error for ' . $attempt['email'] . ': ' . $e->getMessage());
        echo "ERROR: {$attempt['email']}: {$e->getMessage()}\n";
    }
}
