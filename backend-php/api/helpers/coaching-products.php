<?php
/**
 * The coaching catalogue.
 *
 * One place that owns every price, every credit count and every label. The
 * checkout, the webhook, the customer's credit page, the emails and the admin
 * all read from here, so changing a price is one edit and cannot leave two
 * surfaces disagreeing.
 *
 * Everything is sold as CREDITS. A credit is one piece of Michi's attention,
 * redeemed as either a video review or a live call:
 *
 *   video-review   1 review credit    async, reply within 72h
 *   call-45        1 call credit      45 minutes live on Zoom
 *   pack-3         3 credits          any mix, valid 12 months
 *
 * WingCoach was already a credit in everything but name: one purchase created
 * exactly one submission row, used once, finished. Naming it makes the pack a
 * quantity rather than a new product.
 *
 * The legacy 'wingcoach' founding product is deliberately NOT in here. It has a
 * live customer and its own submissions flow, and it keeps running untouched
 * until the founding round closes.
 */

const COACHING_PRODUCTS = [
    'video-review' => [
        'label'        => 'Video review',
        'stripe_name'  => 'WingCoach video review',
        'description'  => 'Send your clips, get a personal video reply from Michi within 72 hours. One round, one or two moves.',
        'amount_cents' => 14900,
        'credits'      => 1,
        'kind'         => 'review',   // what the credit defaults to
        'valid_months' => 12,
    ],
    'call-45' => [
        'label'        => '1:1 call, 45 minutes',
        'stripe_name'  => '1:1 coaching call with Michi Rossmeier',
        'description'  => '45 minutes live on Zoom. Michi watches your footage with you and works through what is holding you back. Recording included.',
        'amount_cents' => 19900,
        'credits'      => 1,
        'kind'         => 'call',
        'valid_months' => 12,
    ],
    'pack-3' => [
        'label'        => 'Coaching pack, 3 sessions',
        'stripe_name'  => 'Coaching pack - 3 sessions with Michi Rossmeier',
        'description'  => 'Three sessions, used as calls or video reviews in any mix, valid for 12 months.',
        // Must stay below 3x the CHEAPEST credit, not 3x the call. At 49900 the
        // pack cost a review-only buyer 52 EUR more than buying three reviews
        // singly, so for part of the audience the "discount" was a penalty.
        'amount_cents' => 39900,
        'credits'      => 3,
        'kind'         => null,       // chosen per redemption
        'valid_months' => 12,
    ],
];

/** How a credit may be spent. Keep in step with credit_redemptions.kind. */
const CREDIT_KINDS = [
    'review' => 'Video review',
    'call'   => '1:1 call, 45 minutes',
];

function coachingProduct(string $key): ?array {
    return COACHING_PRODUCTS[$key] ?? null;
}

/** "199" / "499" - no decimals, because every price here is whole euros. */
/** Per-session price of a multi-credit product, for the sales copy. */
function coachingPerSessionEur(array $p): string {
    $each = (int) round($p['amount_cents'] / max(1, (int) $p['credits']));
    return number_format($each / 100, ($each % 100 === 0) ? 0 : 2, ',', '.');
}

function coachingPriceEur(array $p): string {
    return number_format($p['amount_cents'] / 100, ($p['amount_cents'] % 100 === 0) ? 0 : 2, ',', '.');
}

/**
 * Balance for one purchase. Redemptions are rows, never a counter, so a
 * double-submit cannot silently hand out a free session the way an
 * UPDATE ... SET used = used + 1 could.
 */
function creditBalance(PDO $db, int $creditId): array {
    $row = $db->prepare('SELECT * FROM coaching_credits WHERE id = ?');
    $row->execute([$creditId]);
    $c = $row->fetch(PDO::FETCH_ASSOC);
    if (!$c) return ['total' => 0, 'used' => 0, 'left' => 0, 'expired' => true];

    $used = (int) $db->query(
        'SELECT COUNT(*) FROM credit_redemptions WHERE credit_id = ' . (int) $creditId
    )->fetchColumn();

    $expired = $c['expires_at'] !== null && $c['expires_at'] < date('Y-m-d');

    return [
        'total'   => (int) $c['credits_total'],
        'used'    => $used,
        'left'    => max(0, (int) $c['credits_total'] - $used),
        'expired' => $expired,
    ];
}

/** Look a purchase up by the token in the customer's private link. */
function creditByToken(PDO $db, string $token): ?array {
    if ($token === '' || !preg_match('/^[a-f0-9]{32,64}$/', $token)) return null;
    $s = $db->prepare('SELECT * FROM coaching_credits WHERE token = ?');
    $s->execute([$token]);
    return $s->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * Turn a paid checkout into credits. Shared by the Stripe webhook and the dev
 * bypass so both produce identical rows.
 *
 * Idempotent on stripe_session_id: Stripe retries webhooks, and a retry must
 * not double-grant. Returns the token for the customer's private link.
 */
function grantCoachingCredits(
    PDO $db,
    string $stripeSessionId,
    string $sku,
    array $product,
    ?string $name,
    ?string $email
): string {
    $existing = $db->prepare('SELECT token FROM coaching_credits WHERE stripe_session_id = ?');
    $existing->execute([$stripeSessionId]);
    if ($token = $existing->fetchColumn()) {
        return $token;
    }

    $token = bin2hex(random_bytes(20));
    $expires = $product['valid_months']
        ? date('Y-m-d', strtotime('+' . (int) $product['valid_months'] . ' months'))
        : null;

    $db->prepare(
        'INSERT INTO coaching_credits
            (token, stripe_session_id, product, email, name, credits_total, amount_cents, expires_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        $token, $stripeSessionId, $sku, $email, $name,
        (int) $product['credits'], (int) $product['amount_cents'], $expires,
    ]);

    return $token;
}
