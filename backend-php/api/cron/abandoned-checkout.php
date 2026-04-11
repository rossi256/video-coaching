<?php
/**
 * WingCoach — Abandoned Checkout Reminder (CLI cron)
 *
 * Finds checkout_attempts older than 30 min that haven't been reminded
 * and haven't converted, then sends reminder emails.
 *
 * Cron: */5 * * * * php /path/to/cron/abandoned-checkout.php
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../helpers/email.php';

$db = getDb();

// Find unreminded, unconverted attempts older than 30 minutes
$stmt = $db->prepare("
    SELECT id, email
    FROM checkout_attempts
    WHERE converted = 0
      AND reminded_at IS NULL
      AND created_at < DATE_SUB(NOW(), INTERVAL 30 MINUTE)
");
$stmt->execute();
$attempts = $stmt->fetchAll();

if (empty($attempts)) {
    exit; // nothing to do
}

$checkoutUrl = BASE_URL . '/';

foreach ($attempts as $attempt) {
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
