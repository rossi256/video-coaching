<?php
/**
 * Stripe checkout for the coaching catalogue: video review, 1:1 call, 3-pack.
 *
 * Separate from checkout.php, which serves the founding WingCoach round and is
 * hardcoded to that one product and its spot counter. That flow has a live
 * customer in it and is left alone.
 *
 * POST { product: "call-45" | "video-review" | "pack-3", email?, name? }
 *   -> { url: "https://checkout.stripe.com/..." }
 *
 * The price is never taken from the request. It is read from the catalogue in
 * helpers/coaching-products.php, so a crafted POST cannot buy a call for a euro.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/helpers/coaching-products.php';
setApiHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['error' => 'Method not allowed'], 405);
}

$body    = getJsonBody();
$key     = trim($body['product'] ?? '');
$email   = trim($body['email'] ?? '') ?: null;
$name    = trim($body['name'] ?? '') ?: null;
$product = coachingProduct($key);

if (!$product) {
    jsonResponse(['error' => 'Unknown product'], 400);
}
if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    jsonResponse(['error' => 'That email does not look right'], 400);
}

$db = getDb();

// Dev bypass mirrors checkout.php: skip Stripe, create the credit directly so
// the whole redemption flow can be exercised on staging without live cards.
if (defined('DEV_BYPASS') && DEV_BYPASS) {
    $sessionId = 'dev_coaching_' . time() . '_' . mt_rand(1000, 9999);
    $token = grantCoachingCredits($db, $sessionId, $key, $product, $name, $email);
    jsonResponse(['url' => BASE_URL . '/credit?t=' . $token]);
}

if (!STRIPE_SECRET_KEY) {
    jsonResponse(['error' => 'Payment not configured. Please contact us.'], 500);
}

try {
    $stripe = new \Stripe\StripeClient(STRIPE_SECRET_KEY);
    $session = $stripe->checkout->sessions->create([
        'mode' => 'payment',
        'customer_email' => $email ?: null,
        'line_items' => [[
            'price_data' => [
                'currency' => 'eur',
                'product_data' => [
                    'name'        => $product['stripe_name'],
                    'description' => $product['description'],
                ],
                'unit_amount' => $product['amount_cents'],
            ],
            'quantity' => 1,
        ]],
        'success_url' => BASE_URL . '/credit?paid=1&session_id={CHECKOUT_SESSION_ID}',
        'cancel_url'  => BASE_URL . '/coaching',
        'metadata' => [
            // The webhook routes on this. 'coaching' keeps these clear of the
            // legacy 'wingcoach' branch and of every other Tricktionary charge
            // on the same Stripe account.
            'product'         => 'coaching',
            'coaching_sku'    => $key,
            'coaching_credits'=> (string) $product['credits'],
        ],
    ]);

    if ($email) {
        $db->prepare('INSERT IGNORE INTO checkout_attempts (email, stripe_session_id) VALUES (?, ?)')
           ->execute([$email, $session->id]);
    }

    jsonResponse(['url' => $session->url]);
} catch (\Exception $e) {
    error_log('Coaching checkout error: ' . $e->getMessage());
    jsonResponse(['error' => 'Failed to start checkout'], 500);
}
