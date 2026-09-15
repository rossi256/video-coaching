<?php
/**
 * The rider loop on the coaching reply page: a rating, a capped set of
 * follow-up questions, and the "what next" card.
 *
 * Everything the reply page and the admin need for these lives here so the
 * two never disagree: the cap, the balance card, the tables. Tables are
 * created on first use (CREATE TABLE IF NOT EXISTS, the pattern email.php
 * already uses for qa_email_samples), so staging and production pick them up
 * on the next request after deploy with no manual migration. The same DDL is
 * in migrations/2026-09-15-rider-loop.sql for the record.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/coaching-products.php';

/** Follow-up questions a rider may ask per coaching video. */
const QUESTION_CAP = 2;

/** Public POSTs (rating + questions) accepted per reply token per hour. */
const RIDER_POST_LIMIT = 10;

/** Character limits on the public POST bodies. */
const RIDER_QUESTION_MAX = 1000;
const RIDER_COMMENT_MAX  = 300;
const RIDER_BODY_MAX     = 4096;   // raw JSON bytes

function ensureRiderLoopTables(PDO $db): void {
    static $done = false;
    if ($done) return;
    $done = true;

    $db->exec("CREATE TABLE IF NOT EXISTS reply_ratings (
        submission_id INT PRIMARY KEY,
        rating        TINYINT      NOT NULL,
        comment       VARCHAR(500) NULL,
        created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at    DATETIME     NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $db->exec("CREATE TABLE IF NOT EXISTS reply_questions (
        id            INT AUTO_INCREMENT PRIMARY KEY,
        submission_id INT      NOT NULL,
        question      TEXT     NOT NULL,
        asked_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        answer        TEXT     NULL,
        answered_at   DATETIME NULL,
        INDEX idx_question_submission (submission_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // One row per accepted-or-rejected public POST, for the per-token hourly cap.
    $db->exec("CREATE TABLE IF NOT EXISTS reply_post_log (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        token      VARCHAR(64) NOT NULL,
        created_at DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_post_token (token, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/**
 * Count this POST against the token's hourly allowance. Returns false when the
 * allowance is used up. The row is written before the body is validated, so a
 * script hammering the endpoint with junk runs out just the same.
 */
function riderPostAllowed(PDO $db, string $token): bool {
    ensureRiderLoopTables($db);
    $db->prepare('DELETE FROM reply_post_log WHERE token = ? AND created_at < NOW() - INTERVAL 1 DAY')
       ->execute([$token]);
    $count = $db->prepare('SELECT COUNT(*) FROM reply_post_log WHERE token = ? AND created_at > NOW() - INTERVAL 1 HOUR');
    $count->execute([$token]);
    if ((int) $count->fetchColumn() >= RIDER_POST_LIMIT) return false;
    $db->prepare('INSERT INTO reply_post_log (token) VALUES (?)')->execute([$token]);
    return true;
}

function riderLoopRating(PDO $db, int $submissionId): ?array {
    ensureRiderLoopTables($db);
    $s = $db->prepare('SELECT rating, comment, created_at, updated_at FROM reply_ratings WHERE submission_id = ?');
    $s->execute([$submissionId]);
    $r = $s->fetch(PDO::FETCH_ASSOC);
    if (!$r) return null;
    return [
        'rating'    => (int) $r['rating'],
        'comment'   => $r['comment'],
        'createdAt' => $r['created_at'],
        'updatedAt' => $r['updated_at'],
    ];
}

function riderLoopQuestions(PDO $db, int $submissionId): array {
    ensureRiderLoopTables($db);
    $s = $db->prepare('SELECT id, question, asked_at, answer, answered_at FROM reply_questions WHERE submission_id = ? ORDER BY id ASC');
    $s->execute([$submissionId]);
    $out = [];
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $q) {
        $out[] = [
            'id'         => (int) $q['id'],
            'question'   => $q['question'],
            'askedAt'    => $q['asked_at'],
            'answer'     => $q['answer'],
            'answeredAt' => $q['answered_at'],
        ];
    }
    return $out;
}

/**
 * What the footer card says. The platform sells coaching credits per purchase
 * (coaching_credits, keyed by the buyer's email), not per rider account, so the
 * closest thing to "your balance" is the sum of unexpired credits bought with
 * the same email as this submission. When there are none - or the table is
 * not there, as on staging - the card falls back to the sales page.
 */
function riderLoopNextStep(PDO $db, array $sub): array {
    $out = [
        'credits'   => null,                    // null = no purchase found under this email
        'creditUrl' => null,                    // the buyer's own credit page, where a credit is spent
        'buyUrl'    => BASE_URL . '/coaching',  // the sales page
    ];
    $email = trim((string) ($sub['email'] ?? ''));
    if ($email === '') return $out;

    try {
        $s = $db->prepare('SELECT id, token, expires_at FROM coaching_credits WHERE LOWER(email) = LOWER(?) ORDER BY id DESC');
        $s->execute([$email]);
        $rows = $s->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        return $out;   // coaching_credits not present on this environment
    }
    if (!$rows) return $out;

    $left = 0;
    foreach ($rows as $c) {
        $b = creditBalance($db, (int) $c['id']);
        if ($b['expired']) continue;
        $left += $b['left'];
        if ($out['creditUrl'] === null && $b['left'] > 0) {
            $out['creditUrl'] = BASE_URL . '/credit?t=' . $c['token'];
        }
    }
    $out['credits'] = $left;
    return $out;
}

/** The block the reply JSON carries, and what every public POST answers with. */
function riderLoopPayload(PDO $db, array $sub): array {
    $questions = riderLoopQuestions($db, (int) $sub['id']);
    return [
        'rating'        => riderLoopRating($db, (int) $sub['id']),
        'questions'     => $questions,
        'questionsLeft' => max(0, QUESTION_CAP - count($questions)),
        'questionCap'   => QUESTION_CAP,
        'nextStep'      => riderLoopNextStep($db, $sub),
    ];
}
