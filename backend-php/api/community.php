<?php
/**
 * Between the Calls - community API, v1 = the Question Wall.
 *
 * GET  community.php?action=wall&session=next|<id>&k=<device key>[&key=<admin key>]
 * POST community.php?action=ask      {session_id, name, email, body, k, website, src}
 * POST community.php?action=metoo    {question_id, k, on}
 * POST community.php?action=reply    {question_id, body, k, name?, email?, website, key?}
 * POST community.php?action=status   {key, question_id, status}          (admin)
 * POST community.php?action=hide_reply {key, reply_id, hidden}           (admin)
 * POST community.php?action=track    {kind, k, src, ref_id?}             (analytics)
 * GET  community.php?action=admin_check&key=<admin key>
 *
 * Tables: cm_members, cm_devices, cm_questions, cm_metoo, cm_replies, cm_events
 * (migrations/2026-11-02-community-wall.sql). The admin key is COMMUNITY_ADMIN_KEY
 * in config.local.php, which survives deploys; without it admin actions answer 403.
 *
 * Times: the DB stores local (CEST/CET) wall-clock time and PHP runs in UTC, so
 * every timestamp is produced by MariaDB (NOW()) and never by PHP. The client
 * gets server_time from the same clock for relative "2 min ago" labels.
 */
require_once __DIR__ . '/config.php';
if (!defined('COMMUNITY_ADMIN_KEY') && is_file(__DIR__ . '/config.local.php')) {
    require_once __DIR__ . '/config.local.php';
}

setApiHeaders();
header('Cache-Control: no-store');

const CM_QUESTION_MAX = 600;
const CM_REPLY_MAX    = 500;
const CM_NAME_MAX     = 80;
const CM_ASKS_PER_HOUR    = 6;
const CM_REPLIES_PER_HOUR = 20;
const CM_MICHI_EMAILS = ['rossi@tricktionary.com', 'info@tricktionary.com', 'michi@tricktionary.com'];

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
$db = getDb();

// ---------- helpers ----------

function cmDeviceKey(?string $k): ?string {
    $k = strtolower(trim((string) $k));
    return preg_match('/^[a-f0-9]{16,64}$/', $k) ? $k : null;
}

function cmIsAdmin(?string $key): bool {
    if (!defined('COMMUNITY_ADMIN_KEY') || COMMUNITY_ADMIN_KEY === '') return false;
    $key = (string) $key;
    return $key !== '' && hash_equals(COMMUNITY_ADMIN_KEY, $key);
}

/** "Carl Spiess" -> "Carl S.", "JC" -> "JC", "Techno Mike" -> "Techno M." */
function cmDisplayName(string $name): string {
    $name = trim(preg_replace('/\s+/u', ' ', $name));
    $parts = explode(' ', $name);
    if (count($parts) < 2) return mb_substr($name, 0, 24);
    $first = $parts[0];
    $last = $parts[count($parts) - 1];
    $initial = mb_strtoupper(mb_substr($last, 0, 1));
    return mb_substr($first, 0, 20) . ' ' . $initial . '.';
}

function cmClean(string $s, int $max): string {
    $s = str_replace(["\r\n", "\r"], "\n", $s);
    $s = preg_replace('/[^\P{C}\n]+/u', '', $s);      // control chars out, newlines stay
    $s = preg_replace("/\n{3,}/", "\n\n", $s);
    return mb_substr(trim($s), 0, $max);
}

/** Resolve the session the wall is about. next = soonest upcoming, else most recent past. */
function cmResolveSession(PDO $db, string $want): ?array {
    $cols = 'id, title, scheduled_at, duration_minutes, status, replay_url, meeting_link,
             (scheduled_at <= NOW() AND DATE_ADD(scheduled_at, INTERVAL duration_minutes + 15 MINUTE) > NOW()) AS is_live,
             (DATE_ADD(scheduled_at, INTERVAL duration_minutes + 15 MINUTE) <= NOW()) AS is_over,
             TIMESTAMPDIFF(SECOND, NOW(), scheduled_at) AS starts_in';
    if ($want !== 'next' && ctype_digit($want)) {
        $st = $db->prepare("SELECT $cols FROM qa_sessions WHERE id = ?");
        $st->execute([(int) $want]);
        $row = $st->fetch();
    } else {
        $row = $db->query("SELECT $cols FROM qa_sessions
            WHERE status = 'upcoming' AND DATE_ADD(scheduled_at, INTERVAL duration_minutes + 15 MINUTE) > NOW()
              AND type <> 'kickoff'
            ORDER BY scheduled_at ASC LIMIT 1")->fetch();
        if (!$row) {
            $row = $db->query("SELECT $cols FROM qa_sessions
                WHERE type <> 'kickoff' AND scheduled_at <= NOW()
                ORDER BY scheduled_at DESC LIMIT 1")->fetch();
        }
    }
    if (!$row) return null;
    $row['is_live'] = (bool) $row['is_live'];
    $row['is_over'] = (bool) $row['is_over'];
    $row['starts_in'] = (int) $row['starts_in'];
    $row['phase'] = $row['is_live'] ? 'live' : ($row['is_over'] ? 'after' : 'before');
    // The Zoom link is public only while live, same rule as qa-session.php.
    if (!$row['is_live']) $row['meeting_link'] = null;
    // Call number = position among real sessions since the first one in August 2026.
    $n = $db->prepare("SELECT COUNT(*) FROM qa_sessions WHERE type <> 'kickoff' AND scheduled_at >= '2026-08-01' AND scheduled_at <= ?");
    $n->execute([$row['scheduled_at']]);
    $row['call_no'] = (int) $n->fetchColumn();
    return $row;
}

function cmMember(PDO $db, string $name, string $email, string $source): array {
    $email = mb_strtolower(trim($email));
    $name = cmClean($name, CM_NAME_MAX);
    $display = cmDisplayName($name);
    $st = $db->prepare('SELECT * FROM cm_members WHERE email = ?');
    $st->execute([$email]);
    $m = $st->fetch();
    if ($m) {
        $db->prepare('UPDATE cm_members SET last_seen = NOW() WHERE id = ?')->execute([$m['id']]);
        return $m;
    }
    $token = bin2hex(random_bytes(16));
    $db->prepare('INSERT INTO cm_members (email, name, display_name, token, source) VALUES (?, ?, ?, ?, ?)')
       ->execute([$email, $name, $display, $token, $source]);
    $id = (int) $db->lastInsertId();
    // Everyone who asks joins the Q&A list (the asset): same table the invites read.
    try {
        $db->prepare("INSERT INTO qa_audience (email, name, first_source) VALUES (?, ?, 'wall')
                      ON DUPLICATE KEY UPDATE last_activity = NOW()")->execute([$email, $name]);
    } catch (\Throwable $e) {
        error_log('community: qa_audience upsert failed: ' . $e->getMessage());
    }
    return ['id' => $id, 'email' => $email, 'name' => $name, 'display_name' => $display, 'token' => $token];
}

function cmLinkDevice(PDO $db, string $k, int $memberId): void {
    $db->prepare('INSERT INTO cm_devices (voter_key, member_id) VALUES (?, ?)
                  ON DUPLICATE KEY UPDATE member_id = VALUES(member_id), last_seen = NOW()')->execute([$k, $memberId]);
}

function cmMemberForDevice(PDO $db, ?string $k): ?array {
    if (!$k) return null;
    $st = $db->prepare('SELECT m.* FROM cm_devices d JOIN cm_members m ON m.id = d.member_id WHERE d.voter_key = ?');
    $st->execute([$k]);
    return $st->fetch() ?: null;
}

function cmTrack(PDO $db, string $kind, ?int $refId, ?string $k, ?string $src): void {
    try {
        $db->prepare('INSERT INTO cm_events (kind, ref_id, voter_key, src) VALUES (?, ?, ?, ?)')
           ->execute([$kind, $refId, $k, $src ? mb_substr($src, 0, 32) : null]);
    } catch (\Throwable $e) { /* analytics never breaks the wall */ }
}

function cmQuestions(PDO $db, ?int $sessionId, ?string $k, bool $admin): array {
    $where = $sessionId ? '(q.session_id = ? OR q.session_id IS NULL)' : 'q.session_id IS NULL';
    $params = $sessionId ? [$sessionId] : [];
    if (!$admin) $where .= " AND q.status <> 'hidden'";
    $sql = "SELECT q.id, q.session_id, q.body, q.status, q.source, q.pinned, q.answered_at, q.answer_t,
                   q.created_at, q.voter_key, m.display_name,
                   (SELECT COUNT(*) FROM cm_metoo v WHERE v.question_id = q.id) AS metoo,
                   TIMESTAMPDIFF(SECOND, q.created_at, NOW()) AS age_s
            FROM cm_questions q JOIN cm_members m ON m.id = q.member_id
            WHERE $where";
    $st = $db->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll();
    if (!$rows) return [];

    $ids = array_column($rows, 'id');
    $in = implode(',', array_fill(0, count($ids), '?'));

    $mine = [];
    if ($k) {
        $st = $db->prepare("SELECT question_id FROM cm_metoo WHERE voter_key = ? AND question_id IN ($in)");
        $st->execute(array_merge([$k], $ids));
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $qid) $mine[(int) $qid] = true;
    }

    $replies = [];
    $rw = $admin ? '' : ' AND r.hidden = 0';
    $st = $db->prepare("SELECT r.id, r.question_id, r.body, r.is_michi, r.hidden, r.created_at, r.voter_key, m.display_name,
                               TIMESTAMPDIFF(SECOND, r.created_at, NOW()) AS age_s
                        FROM cm_replies r JOIN cm_members m ON m.id = r.member_id
                        WHERE r.question_id IN ($in)$rw ORDER BY r.created_at ASC");
    $st->execute($ids);
    foreach ($st->fetchAll() as $r) {
        $replies[(int) $r['question_id']][] = [
            'id' => (int) $r['id'], 'body' => $r['body'], 'display_name' => $r['display_name'],
            'is_michi' => (bool) $r['is_michi'], 'hidden' => (bool) $r['hidden'],
            'age_s' => (int) $r['age_s'], 'mine' => $k !== null && $r['voter_key'] === $k,
        ];
    }

    $out = [];
    foreach ($rows as $r) {
        $id = (int) $r['id'];
        $out[] = [
            'id' => $id, 'body' => $r['body'], 'status' => $r['status'], 'source' => $r['source'],
            'pinned' => (bool) $r['pinned'], 'display_name' => $r['display_name'],
            'metoo' => (int) $r['metoo'], 'metoo_mine' => isset($mine[$id]),
            'mine' => $k !== null && $r['voter_key'] === $k,
            'age_s' => (int) $r['age_s'], 'answered_at' => $r['answered_at'], 'answer_t' => $r['answer_t'] !== null ? (int) $r['answer_t'] : null,
            'replies' => $replies[$id] ?? [],
        ];
    }
    // Order: answering first, then open by me-too (desc) then oldest first, answered last (most recent first), hidden at the very end.
    $rank = ['answering' => 0, 'open' => 1, 'answered' => 2, 'hidden' => 3];
    usort($out, function ($a, $b) use ($rank) {
        $ra = $rank[$a['status']] ?? 1; $rb = $rank[$b['status']] ?? 1;
        if ($ra !== $rb) return $ra <=> $rb;
        if ($a['status'] === 'open') {
            if ($a['pinned'] !== $b['pinned']) return $b['pinned'] <=> $a['pinned'];
            if ($a['metoo'] !== $b['metoo']) return $b['metoo'] <=> $a['metoo'];
            return $b['age_s'] <=> $a['age_s'];
        }
        if ($a['status'] === 'answered') return strcmp((string) $b['answered_at'], (string) $a['answered_at']);
        return $b['age_s'] <=> $a['age_s'];
    });
    return $out;
}

function cmRateLimited(PDO $db, string $table, string $k, int $perHour): bool {
    $st = $db->prepare("SELECT COUNT(*) FROM $table WHERE voter_key = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)");
    $st->execute([$k]);
    return (int) $st->fetchColumn() >= $perHour;
}

// ---------- routes ----------

if ($method === 'GET' && $action === 'wall') {
    $k = cmDeviceKey($_GET['k'] ?? null);
    $admin = cmIsAdmin($_GET['key'] ?? null);
    $session = cmResolveSession($db, (string) ($_GET['session'] ?? 'next'));
    $sessionId = $session ? (int) $session['id'] : null;
    $me = cmMemberForDevice($db, $k);
    $questions = cmQuestions($db, $sessionId, $k, $admin);
    $counts = ['open' => 0, 'answering' => 0, 'answered' => 0, 'hidden' => 0];
    foreach ($questions as $q) $counts[$q['status']] = ($counts[$q['status']] ?? 0) + 1;
    jsonResponse([
        'session' => $session,
        'phase' => $session['phase'] ?? 'before',
        'server_time' => $db->query('SELECT NOW()')->fetchColumn(),
        'me' => $me ? ['display_name' => $me['display_name'], 'name' => $me['name']] : null,
        'admin' => $admin,
        'admin_enabled' => defined('COMMUNITY_ADMIN_KEY') && COMMUNITY_ADMIN_KEY !== '',
        'questions' => $questions,
        'counts' => $counts,
    ]);
}

if ($method === 'GET' && $action === 'admin_check') {
    jsonResponse(['ok' => cmIsAdmin($_GET['key'] ?? null)]);
}

if ($method === 'POST' && $action === 'track') {
    $d = getJsonBody();
    $kind = (string) ($d['kind'] ?? '');
    if (!in_array($kind, ['open', 'share', 'preview', 'zoom'], true)) jsonResponse(['ok' => false], 400);
    cmTrack($db, $kind, isset($d['ref_id']) ? (int) $d['ref_id'] : null, cmDeviceKey($d['k'] ?? null), $d['src'] ?? null);
    jsonResponse(['ok' => true]);
}

if ($method === 'POST' && $action === 'ask') {
    $d = getJsonBody();
    if (!empty($d['website'])) jsonResponse(['ok' => true]);           // honeypot
    $k = cmDeviceKey($d['k'] ?? null);
    if (!$k) jsonResponse(['error' => 'Please reload the page and try again.'], 400);
    $body = cmClean((string) ($d['body'] ?? ''), CM_QUESTION_MAX);
    if (mb_strlen($body) < 8) jsonResponse(['error' => 'Write a little more, so Michi knows what you mean.'], 400);
    $name = cmClean((string) ($d['name'] ?? ''), CM_NAME_MAX);
    $email = mb_strtolower(trim((string) ($d['email'] ?? '')));
    $known = cmMemberForDevice($db, $k);
    if ($known && ($name === '' || $email === '')) { $name = $known['name']; $email = $known['email']; }
    if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) jsonResponse(['error' => 'Your name and a valid email, please. The email is only so Michi can reach you if needed.'], 400);
    if (cmRateLimited($db, 'cm_questions', $k, CM_ASKS_PER_HOUR)) jsonResponse(['error' => 'That is plenty for one hour. Tap "me too" on others for now.'], 429);

    $sessionId = isset($d['session_id']) && $d['session_id'] !== null && $d['session_id'] !== '' ? (int) $d['session_id'] : null;
    if ($sessionId) {
        $st = $db->prepare("SELECT id FROM qa_sessions WHERE id = ? AND DATE_ADD(scheduled_at, INTERVAL duration_minutes + 15 MINUTE) > NOW()");
        $st->execute([$sessionId]);
        if (!$st->fetchColumn()) $sessionId = null;                     // the call is over: general queue
    }
    $src = preg_replace('/[^a-z0-9_-]/', '', mb_strtolower((string) ($d['src'] ?? 'wall')));
    if ($src === '') $src = 'wall';

    $m = cmMember($db, $name, $email, 'wall');
    cmLinkDevice($db, $k, (int) $m['id']);
    $db->prepare('INSERT INTO cm_questions (session_id, member_id, voter_key, body, status, source) VALUES (?, ?, ?, ?, ?, ?)')
       ->execute([$sessionId, $m['id'], $k, $body, 'open', 'wall']);
    $qid = (int) $db->lastInsertId();
    // The asker counts as the first "me too", so a fresh question never shows 0.
    $db->prepare('INSERT IGNORE INTO cm_metoo (question_id, voter_key) VALUES (?, ?)')->execute([$qid, $k]);
    cmTrack($db, 'ask', $qid, $k, $src);
    jsonResponse(['ok' => true, 'id' => $qid, 'me' => ['display_name' => $m['display_name'], 'name' => $m['name']]]);
}

if ($method === 'POST' && $action === 'metoo') {
    $d = getJsonBody();
    $k = cmDeviceKey($d['k'] ?? null);
    $qid = (int) ($d['question_id'] ?? 0);
    if (!$k || !$qid) jsonResponse(['error' => 'Reload the page and try again.'], 400);
    $st = $db->prepare("SELECT id, voter_key FROM cm_questions WHERE id = ? AND status <> 'hidden'");
    $st->execute([$qid]);
    $q = $st->fetch();
    if (!$q) jsonResponse(['error' => 'That question is gone.'], 404);
    $on = !empty($d['on']);
    if ($q['voter_key'] === $k && !$on) jsonResponse(['ok' => true, 'metoo' => null]);   // the asker stays counted
    if ($on) $db->prepare('INSERT IGNORE INTO cm_metoo (question_id, voter_key) VALUES (?, ?)')->execute([$qid, $k]);
    else     $db->prepare('DELETE FROM cm_metoo WHERE question_id = ? AND voter_key = ?')->execute([$qid, $k]);
    $n = $db->prepare('SELECT COUNT(*) FROM cm_metoo WHERE question_id = ?'); $n->execute([$qid]);
    cmTrack($db, $on ? 'metoo' : 'unmetoo', $qid, $k, null);
    jsonResponse(['ok' => true, 'metoo' => (int) $n->fetchColumn(), 'metoo_mine' => $on]);
}

if ($method === 'POST' && $action === 'reply') {
    $d = getJsonBody();
    if (!empty($d['website'])) jsonResponse(['ok' => true]);
    $k = cmDeviceKey($d['k'] ?? null);
    $qid = (int) ($d['question_id'] ?? 0);
    if (!$k || !$qid) jsonResponse(['error' => 'Reload the page and try again.'], 400);
    $body = cmClean((string) ($d['body'] ?? ''), CM_REPLY_MAX);
    if (mb_strlen($body) < 2) jsonResponse(['error' => 'Write something first.'], 400);
    $st = $db->prepare("SELECT id FROM cm_questions WHERE id = ? AND status <> 'hidden'");
    $st->execute([$qid]);
    if (!$st->fetchColumn()) jsonResponse(['error' => 'That question is gone.'], 404);
    $admin = cmIsAdmin($d['key'] ?? null);
    $m = cmMemberForDevice($db, $k);
    if (!$m) {
        $name = cmClean((string) ($d['name'] ?? ''), CM_NAME_MAX);
        $email = mb_strtolower(trim((string) ($d['email'] ?? '')));
        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) jsonResponse(['error' => 'Your name and email, please, so people know who is answering.', 'need_identity' => true], 400);
        $m = cmMember($db, $name, $email, 'reply');
        cmLinkDevice($db, $k, (int) $m['id']);
    }
    if (cmRateLimited($db, 'cm_replies', $k, CM_REPLIES_PER_HOUR)) jsonResponse(['error' => 'Slow down a little.'], 429);
    $isMichi = $admin || in_array(mb_strtolower($m['email']), CM_MICHI_EMAILS, true);
    $db->prepare('INSERT INTO cm_replies (question_id, member_id, voter_key, body, is_michi) VALUES (?, ?, ?, ?, ?)')
       ->execute([$qid, $m['id'], $k, $body, $isMichi ? 1 : 0]);
    $rid = (int) $db->lastInsertId();
    cmTrack($db, 'reply', $qid, $k, null);
    jsonResponse(['ok' => true, 'id' => $rid, 'me' => ['display_name' => $m['display_name'], 'name' => $m['name']]]);
}

if ($method === 'POST' && $action === 'status') {
    $d = getJsonBody();
    if (!cmIsAdmin($d['key'] ?? null)) jsonResponse(['error' => 'Not allowed.'], 403);
    $qid = (int) ($d['question_id'] ?? 0);
    $status = (string) ($d['status'] ?? '');
    if (!$qid || !in_array($status, ['open', 'answering', 'answered', 'hidden'], true)) jsonResponse(['error' => 'Bad request.'], 400);
    $st = $db->prepare('SELECT id, session_id, status FROM cm_questions WHERE id = ?');
    $st->execute([$qid]);
    $q = $st->fetch();
    if (!$q) jsonResponse(['error' => 'Not found.'], 404);

    if ($status === 'answering') {
        // One question at a time: whatever was being answered is now answered.
        $db->prepare("UPDATE cm_questions SET status = 'answered', answered_at = COALESCE(answered_at, NOW())
                      WHERE status = 'answering' AND id <> ?")->execute([$qid]);
        // Seconds into the call = where this answer starts in the replay.
        $t = null;
        if ($q['session_id']) {
            $ts = $db->prepare('SELECT GREATEST(0, TIMESTAMPDIFF(SECOND, scheduled_at, NOW())) FROM qa_sessions WHERE id = ?');
            $ts->execute([$q['session_id']]);
            $t = (int) $ts->fetchColumn();
        }
        $db->prepare("UPDATE cm_questions SET status = 'answering', answer_t = COALESCE(answer_t, ?), answered_at = NULL WHERE id = ?")->execute([$t, $qid]);
    } elseif ($status === 'answered') {
        $db->prepare("UPDATE cm_questions SET status = 'answered', answered_at = COALESCE(answered_at, NOW()) WHERE id = ?")->execute([$qid]);
    } elseif ($status === 'open') {
        $db->prepare("UPDATE cm_questions SET status = 'open', answered_at = NULL, answer_t = NULL WHERE id = ?")->execute([$qid]);
    } else {
        $db->prepare("UPDATE cm_questions SET status = 'hidden' WHERE id = ?")->execute([$qid]);
    }
    cmTrack($db, 'admin_' . $status, $qid, null, null);
    jsonResponse(['ok' => true]);
}

if ($method === 'POST' && $action === 'hide_reply') {
    $d = getJsonBody();
    if (!cmIsAdmin($d['key'] ?? null)) jsonResponse(['error' => 'Not allowed.'], 403);
    $rid = (int) ($d['reply_id'] ?? 0);
    if (!$rid) jsonResponse(['error' => 'Bad request.'], 400);
    $db->prepare('UPDATE cm_replies SET hidden = ? WHERE id = ?')->execute([!empty($d['hidden']) ? 1 : 0, $rid]);
    jsonResponse(['ok' => true]);
}

jsonResponse(['error' => 'Unknown action.'], 404);
