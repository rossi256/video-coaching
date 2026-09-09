<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/helpers/email.php';
require_once __DIR__ . '/helpers/coaching-products.php';

// Webhook needs raw body for signature verification
$payload = getRawBody();
$sig = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

try {
    $event = \Stripe\Webhook::constructEvent($payload, $sig, STRIPE_WEBHOOK_SECRET);
} catch (\Exception $e) {
    error_log('Webhook signature verification failed: ' . $e->getMessage());
    http_response_code(400);
    echo 'Webhook Error: ' . $e->getMessage();
    exit;
}

if ($event->type === 'checkout.session.completed') {
    $session = $event->data->object;

    // The coaching catalogue (call, video review, 3-pack) grants credits and
    // returns here. Handled before the wingcoach guard below, which would
    // otherwise discard it as a foreign charge.
    if (($session->metadata->product ?? '') === 'coaching') {
        $db  = getDb();
        $sku = $session->metadata->coaching_sku ?? '';
        $product = coachingProduct($sku);
        if (!$product) {
            error_log('Coaching webhook: unknown sku ' . $sku);
            header('Content-Type: application/json');
            echo json_encode(['received' => true]);
            exit;
        }

        $db->prepare('UPDATE checkout_attempts SET converted = 1 WHERE stripe_session_id = ?')
           ->execute([$session->id]);

        $name  = $session->customer_details->name ?? '';
        $email = $session->customer_details->email ?? '';
        if (!$email) {
            $a = $db->prepare('SELECT email FROM checkout_attempts WHERE stripe_session_id = ?');
            $a->execute([$session->id]);
            $email = $a->fetchColumn() ?: '';
        }

        // Idempotent on the session id, so a Stripe retry cannot double-grant.
        $token = grantCoachingCredits($db, $session->id, $sku, $product, $name ?: null, $email ?: null);

        try {
            sendCoachingCreditEmails($token, $sku, $product, $name, $email);
        } catch (\Exception $e) {
            error_log('Coaching credit email error: ' . $e->getMessage());
        }

        error_log("Coaching credits granted: $sku ({$product['credits']}) for " . ($email ?: 'unknown'));
        header('Content-Type: application/json');
        echo json_encode(['received' => true]);
        exit;
    }

    // Only process WingCoach checkout sessions (shared Stripe account)
    if (($session->metadata->product ?? '') !== 'wingcoach') {
        error_log('Skipping non-WingCoach checkout session: ' . $session->id);
        header('Content-Type: application/json');
        echo json_encode(['received' => true]);
        exit;
    }

    $db = getDb();

    // Mark checkout attempt as converted
    $db->prepare('UPDATE checkout_attempts SET converted = 1 WHERE stripe_session_id = ?')
       ->execute([$session->id]);

    // Idempotency: skip if already processed
    $existing = $db->prepare('SELECT id FROM submissions WHERE stripe_session_id = ?');
    $existing->execute([$session->id]);

    if (!$existing->fetch()) {
        $spotsAtPurchase = (int) ($session->metadata->spots_at_purchase ?? 10);
        $token = bin2hex(random_bytes(16));

        // Create submission
        $stmt = $db->prepare('INSERT INTO submissions (stripe_session_id, token, spots_at_purchase) VALUES (?, ?, ?)');
        $stmt->execute([$session->id, $token, $spotsAtPurchase]);
        $submissionId = $db->lastInsertId();

        // Spots are counted from the submissions table itself (helpers/spots.php);
        // the row above already consumes one via counts_toward_spots DEFAULT 1.
        // The old config.spots_taken counter is no longer maintained here — it had
        // drifted by counting test purchases and a mis-filed non-coaching invoice.

        $name = $session->customer_details->name ?? '';
        // Check checkout_attempts for email
        $attemptStmt = $db->prepare('SELECT email FROM checkout_attempts WHERE stripe_session_id = ?');
        $attemptStmt->execute([$session->id]);
        $attemptEmail = $attemptStmt->fetchColumn() ?: '';
        $email = $session->customer_details->email ?? $attemptEmail;

        $updates = ['stripe_payment_intent' => $session->payment_intent];
        if ($name || $email) {
            $updates['name'] = $name;
            $updates['email'] = $email;
        }

        $setParts = [];
        $vals = [];
        foreach ($updates as $k => $v) {
            $setParts[] = "`$k` = ?";
            $vals[] = $v;
        }
        $vals[] = $submissionId;
        $db->prepare('UPDATE submissions SET ' . implode(', ', $setParts) . ' WHERE id = ?')
           ->execute($vals);

        // Send admin notification
        try {
            sendAdminNotification($name ?: 'Unknown', $email);
        } catch (\Exception $e) {
            error_log('Admin notification email error: ' . $e->getMessage());
        }

        // Send upload link to customer
        if ($email) {
            $uploadUrl = BASE_URL . '/success?session_id=' . $session->id;
            try {
                sendUploadLink($email, $name, $uploadUrl);
            } catch (\Exception $e) {
                error_log('Upload link email error: ' . $e->getMessage());
            }
        }

        error_log("New submission created: #$submissionId for $email");
    }
}

header('Content-Type: application/json');
echo json_encode(['received' => true]);
