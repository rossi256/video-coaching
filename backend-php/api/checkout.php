<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../vendor/autoload.php';
setApiHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['error' => 'Method not allowed'], 405);
}

// Dev bypass: skip Stripe, simulate payment outcome
if (defined('DEV_BYPASS') && DEV_BYPASS) {
    $db = getDb();
    $body = getJsonBody();
    $email = trim($body['email'] ?? '') ?: null;
    $outcome = $body['outcome'] ?? 'success';

    if ($outcome === 'cancel') {
        jsonResponse(['url' => BASE_URL . '/']);
    }
    if ($outcome === 'declined') {
        jsonResponse(['error' => 'Payment declined (dev simulation)'], 402);
    }

    $total = (int) $db->query("SELECT value FROM config WHERE `key` = 'total_spots'")->fetchColumn();
    $taken = (int) $db->query("SELECT value FROM config WHERE `key` = 'spots_taken'")->fetchColumn();
    $remaining = $total - $taken;
    if ($remaining <= 0) {
        jsonResponse(['error' => 'No spots remaining'], 400);
    }

    $fakeSessionId = 'dev_checkout_' . time() . '_' . mt_rand(1000, 9999);
    if ($email) {
        $db->prepare('INSERT IGNORE INTO checkout_attempts (email, stripe_session_id) VALUES (?, ?)')->execute([$email, $fakeSessionId]);
        $db->prepare('UPDATE checkout_attempts SET converted = 1 WHERE stripe_session_id = ?')->execute([$fakeSessionId]);
    }
    $token = bin2hex(random_bytes(16));
    $db->prepare('INSERT INTO submissions (stripe_session_id, token, status, email, spots_at_purchase) VALUES (?, ?, ?, ?, ?)')->execute([
        $fakeSessionId, $token, 'paid', $email, $remaining
    ]);

    jsonResponse(['url' => BASE_URL . '/success?session_id=' . urlencode($fakeSessionId)]);
}

if (!STRIPE_SECRET_KEY) {
    jsonResponse(['error' => 'Payment not configured. Please contact us.'], 500);
}

$db = getDb();
$total = (int) $db->query("SELECT value FROM config WHERE `key` = 'total_spots'")->fetchColumn();
$taken = (int) $db->query("SELECT value FROM config WHERE `key` = 'spots_taken'")->fetchColumn();
$remaining = $total - $taken;

if ($remaining <= 0) {
    jsonResponse(['error' => 'No spots remaining'], 400);
}

$body = getJsonBody();
$email = trim($body['email'] ?? '') ?: null;

try {
    $stripe = new \Stripe\StripeClient(STRIPE_SECRET_KEY);
    $session = $stripe->checkout->sessions->create([
        'mode' => 'payment',
        'customer_email' => $email ?: null,
        'line_items' => [[
            'price_data' => [
                'currency' => 'eur',
                'product_data' => [
                    'name' => 'WingCoach — Founding 10 Spot',
                    'description' => 'Personal video coaching from Michi Rossmeier (Tricktionary). Limited to 10 founding clients at €39.',
                ],
                'unit_amount' => 4900,
            ],
            'quantity' => 1,
        ]],
        'success_url' => BASE_URL . '/success?session_id={CHECKOUT_SESSION_ID}',
        'cancel_url' => BASE_URL . '/',
        'metadata' => [
            'spots_at_purchase' => (string) $remaining,
        ],
    ]);

    if ($email) {
        $stmt = $db->prepare('INSERT IGNORE INTO checkout_attempts (email, stripe_session_id) VALUES (?, ?)');
        $stmt->execute([$email, $session->id]);
    }

    jsonResponse(['url' => $session->url]);
} catch (\Exception $e) {
    error_log('Stripe error: ' . $e->getMessage());
    jsonResponse(['error' => 'Failed to create checkout session'], 500);
}
