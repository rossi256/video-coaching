<?php
/**
 * WingCoach — Admin API endpoint
 * All admin CRUD operations, routed via .htaccess _action parameter
 *
 * Actions: list, get, reply-item, reply-item-delete, reply-item-order,
 *          reply-file, confirm-receipt, feedback-sent, file
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers/email.php';
require_once __DIR__ . '/helpers/file-serve.php';
require_once __DIR__ . '/helpers/qa_schedules.php';

requireAdmin();
setApiHeaders();

$db = getDb();
$action = $_GET['_action'] ?? '';
$id = (int) ($_GET['id'] ?? 0);
$method = $_SERVER['REQUEST_METHOD'];

// --- GET /api/admin/submissions ---
if ($action === 'list') {
    $rows = $db->query('SELECT * FROM submissions ORDER BY id DESC')->fetchAll();
    echo json_encode($rows);
    exit;
}

// --- GET /api/admin/submission/:id ---
if ($action === 'get' && $id) {
    $stmt = $db->prepare('SELECT * FROM submissions WHERE id = ?');
    $stmt->execute([$id]);
    $sub = $stmt->fetch();
    if (!$sub) jsonResponse(['error' => 'Not found'], 404);

    // Uploaded files
    $dir = UPLOADS_DIR . '/' . $id;
    $files = [];
    if (is_dir($dir)) {
        foreach (scandir($dir) as $f) {
            $fp = $dir . '/' . $f;
            if ($f[0] !== '.' && is_file($fp)) {
                $files[] = ['name' => $f, 'size' => filesize($fp)];
            }
        }
    }

    // Reply files
    $replyDir = $dir . '/reply';
    $replyFiles = [];
    if (is_dir($replyDir)) {
        foreach (scandir($replyDir) as $f) {
            $fp = $replyDir . '/' . $f;
            if ($f[0] !== '.' && is_file($fp)) {
                $replyFiles[] = ['name' => $f, 'size' => filesize($fp)];
            }
        }
    }

    // Reply items
    $itemStmt = $db->prepare('SELECT * FROM reply_items WHERE submission_id = ? ORDER BY order_index ASC, id ASC');
    $itemStmt->execute([$id]);
    $replyItems = $itemStmt->fetchAll();

    $sub['uploaded_files'] = $files;
    $sub['reply_files'] = $replyFiles;
    $sub['reply_items'] = $replyItems;
    echo json_encode($sub);
    exit;
}

// --- POST /api/admin/submission/:id/reply-item ---
if ($action === 'reply-item' && $method === 'POST' && $id) {
    $stmt = $db->prepare('SELECT id FROM submissions WHERE id = ?');
    $stmt->execute([$id]);
    if (!$stmt->fetch()) jsonResponse(['error' => 'Not found'], 404);

    // Multipart: may have video file or text content
    $type = $_POST['type'] ?? (isset($_FILES['video']) ? 'video' : 'text');
    $description = trim($_POST['description'] ?? '');

    if ($type === 'video') {
        if (empty($_FILES['video'])) jsonResponse(['error' => 'No video file uploaded'], 400);

        $replyDir = UPLOADS_DIR . '/' . $id . '/reply';
        if (!is_dir($replyDir)) mkdir($replyDir, 0755, true);

        $filename = safeName($_FILES['video']['name']);
        $dest = $replyDir . '/' . $filename;
        if (!move_uploaded_file($_FILES['video']['tmp_name'], $dest)) {
            jsonResponse(['error' => 'Upload failed'], 500);
        }

        $ins = $db->prepare('INSERT INTO reply_items (submission_id, type, filename, description, order_index) VALUES (?, ?, ?, ?, ?)');
        $ins->execute([$id, 'video', $filename, $description, 0]);
        $itemId = $db->lastInsertId();

        jsonResponse(['id' => (int) $itemId, 'type' => 'video', 'filename' => $filename, 'description' => $description, 'size' => $_FILES['video']['size']]);
    }

    if ($type === 'text') {
        $content = trim($_POST['content'] ?? '');
        if (!$content) jsonResponse(['error' => 'Content required for text reply'], 400);

        $ins = $db->prepare('INSERT INTO reply_items (submission_id, type, description, content, order_index) VALUES (?, ?, ?, ?, ?)');
        $ins->execute([$id, 'text', $description, $content, 0]);
        $itemId = $db->lastInsertId();

        jsonResponse(['id' => (int) $itemId, 'type' => 'text', 'description' => $description, 'content' => $content]);
    }

    jsonResponse(['error' => 'Invalid type'], 400);
}

// --- DELETE /api/admin/submission/:id/reply-item/:itemId ---
if ($action === 'reply-item-delete' && $method === 'DELETE' && $id) {
    $itemId = (int) ($_GET['itemId'] ?? 0);
    if (!$itemId) jsonResponse(['error' => 'Invalid item ID'], 400);

    $stmt = $db->prepare('SELECT * FROM reply_items WHERE id = ? AND submission_id = ?');
    $stmt->execute([$itemId, $id]);
    $item = $stmt->fetch();
    if (!$item) jsonResponse(['error' => 'Item not found'], 404);

    // Delete file from disk if video
    if ($item['type'] === 'video' && $item['filename']) {
        $filePath = UPLOADS_DIR . '/' . $id . '/reply/' . $item['filename'];
        if (file_exists($filePath)) @unlink($filePath);
    }

    $db->prepare('DELETE FROM reply_items WHERE id = ?')->execute([$itemId]);
    jsonResponse(['success' => true]);
}

// --- PATCH /api/admin/submission/:id/reply-item/:itemId/order ---
if ($action === 'reply-item-order' && $method === 'PATCH' && $id) {
    $itemId = (int) ($_GET['itemId'] ?? 0);
    $body = getJsonBody();
    $direction = $body['direction'] ?? '';

    if ($direction !== 'up' && $direction !== 'down') {
        jsonResponse(['error' => 'direction must be "up" or "down"'], 400);
    }

    // Get all items for this submission
    $items = $db->prepare('SELECT id, order_index FROM reply_items WHERE submission_id = ? ORDER BY order_index ASC, id ASC');
    $items->execute([$id]);
    $allItems = $items->fetchAll();

    // Find current index
    $currentIdx = null;
    foreach ($allItems as $i => $item) {
        if ((int) $item['id'] === $itemId) {
            $currentIdx = $i;
            break;
        }
    }

    if ($currentIdx === null) jsonResponse(['error' => 'Item not found'], 404);

    $swapIdx = $direction === 'up' ? $currentIdx - 1 : $currentIdx + 1;
    if ($swapIdx < 0 || $swapIdx >= count($allItems)) {
        jsonResponse(['success' => true]); // already at boundary
    }

    // Swap order_index values
    $db->prepare('UPDATE reply_items SET order_index = ? WHERE id = ?')
       ->execute([$allItems[$swapIdx]['order_index'], $allItems[$currentIdx]['id']]);
    $db->prepare('UPDATE reply_items SET order_index = ? WHERE id = ?')
       ->execute([$allItems[$currentIdx]['order_index'], $allItems[$swapIdx]['id']]);

    jsonResponse(['success' => true]);
}

// --- DELETE /api/admin/submission/:id/reply/:filename (legacy) ---
if ($action === 'reply-file' && $method === 'DELETE' && $id) {
    $filename = $_GET['filename'] ?? '';
    if (!$filename || str_contains($filename, '/') || str_contains($filename, '\\') || str_starts_with($filename, '.')) {
        jsonResponse(['error' => 'Invalid filename'], 400);
    }

    $filePath = UPLOADS_DIR . '/' . $id . '/reply/' . $filename;
    if (!file_exists($filePath)) jsonResponse(['error' => 'Not found'], 404);
    unlink($filePath);

    // Update reply_video_path JSON
    $stmt = $db->prepare('SELECT reply_video_path FROM submissions WHERE id = ?');
    $stmt->execute([$id]);
    $sub = $stmt->fetch();
    if ($sub) {
        $paths = json_decode($sub['reply_video_path'] ?? '[]', true) ?: [];
        $paths = array_values(array_filter($paths, fn($p) => $p !== $filename));
        $db->prepare('UPDATE submissions SET reply_video_path = ? WHERE id = ?')
           ->execute([json_encode($paths), $id]);
    }

    jsonResponse(['success' => true]);
}

// --- POST /api/admin/submission/:id/confirm-receipt ---
if ($action === 'confirm-receipt' && $method === 'POST' && $id) {
    $stmt = $db->prepare('SELECT * FROM submissions WHERE id = ?');
    $stmt->execute([$id]);
    $sub = $stmt->fetch();
    if (!$sub) jsonResponse(['error' => 'Not found'], 404);

    $db->prepare('UPDATE submissions SET status = ?, confirmed_at = ? WHERE id = ?')
       ->execute(['in_progress', date('Y-m-d H:i:s'), $id]);

    if ($sub['email']) {
        try {
            sendReceiptConfirmation($sub['email'], $sub['name'] ?: 'Rider');
        } catch (\Exception $e) {
            error_log('Receipt confirmation email error: ' . $e->getMessage());
        }
    }

    jsonResponse(['success' => true]);
}

// --- POST /api/admin/submission/:id/feedback-sent ---
if ($action === 'feedback-sent' && $method === 'POST' && $id) {
    $stmt = $db->prepare('SELECT * FROM submissions WHERE id = ?');
    $stmt->execute([$id]);
    $sub = $stmt->fetch();
    if (!$sub) jsonResponse(['error' => 'Not found'], 404);

    $db->prepare('UPDATE submissions SET status = ?, feedback_sent_at = ? WHERE id = ?')
       ->execute(['feedback_sent', date('Y-m-d H:i:s'), $id]);

    $replyUrl = BASE_URL . '/reply/' . $sub['token'];

    if ($sub['email']) {
        try {
            sendFeedbackReady($sub['email'], $sub['name'] ?: 'Rider', $replyUrl);
        } catch (\Exception $e) {
            error_log('Feedback-ready email error: ' . $e->getMessage());
            jsonResponse(['success' => true, 'replyUrl' => $replyUrl, 'emailError' => $e->getMessage()]);
        }
    }

    jsonResponse(['success' => true, 'replyUrl' => $replyUrl]);
}

// --- GET /api/admin/file/:id/:path --- serve uploaded file
if ($action === 'file' && $id) {
    $filePath = $_GET['_path'] ?? '';
    if (!$filePath) {
        http_response_code(400);
        echo 'Missing path';
        exit;
    }

    $fullPath = UPLOADS_DIR . '/' . $id . '/' . $filePath;
    $safeBase = realpath(UPLOADS_DIR);
    if (!$safeBase || !file_exists($fullPath) || !str_starts_with(realpath($fullPath), $safeBase)) {
        http_response_code(404);
        echo 'Not found';
        exit;
    }

    serveFile($fullPath);
}

// --- GET /api/admin/event-inquiries ---
if ($action === 'event-inquiries') {
    $rows = $db->query('SELECT * FROM event_inquiries ORDER BY id DESC')->fetchAll();
    echo json_encode($rows);
    exit;
}

// --- POST /api/admin/event-inquiry/:id/respond ---
if ($action === 'event-inquiry-respond' && $method === 'POST' && $id) {
    $stmt = $db->prepare('UPDATE event_inquiries SET status = ?, responded_at = NOW() WHERE id = ?');
    $stmt->execute(['responded', $id]);
    jsonResponse(['success' => true]);
}

// --- POST /api/admin/event-inquiry/:id/archive ---
if ($action === 'event-inquiry-archive' && $method === 'POST' && $id) {
    $stmt = $db->prepare('UPDATE event_inquiries SET status = ? WHERE id = ?');
    $stmt->execute(['archived', $id]);
    jsonResponse(['success' => true]);
}

// --- DELETE /api/admin/event-inquiry/:id/delete ---
if ($action === 'event-inquiry-delete' && $method === 'DELETE' && $id) {
    $db->prepare('DELETE FROM event_inquiries WHERE id = ?')->execute([$id]);
    jsonResponse(['success' => true]);
}

// --- POST /api/admin/event-inquiry/:id/draft --- enqueue an AI reply draft request
if ($action === 'event-inquiry-draft' && $method === 'POST' && $id) {
    $stmt = $db->prepare('SELECT id FROM event_inquiries WHERE id = ?');
    $stmt->execute([$id]);
    if (!$stmt->fetch()) jsonResponse(['error' => 'Not found'], 404);

    $ins = $db->prepare('INSERT INTO email_draft_queue (inquiry_id) VALUES (?)');
    $ins->execute([$id]);
    jsonResponse(['queued' => true, 'queue_id' => (int) $db->lastInsertId()]);
}

// --- GET /api/admin/email-draft-queue --- pending draft requests (consumed by local poller)
if ($action === 'email-draft-queue') {
    $sql = 'SELECT q.id, q.inquiry_id, q.account,
                   e.name, e.email, e.event_slug, e.event_name, e.current_level, e.message,
                   e.created_at AS inquiry_created_at
            FROM email_draft_queue q
            JOIN event_inquiries e ON e.id = q.inquiry_id
            WHERE q.status = "pending"
            ORDER BY q.id ASC';
    echo json_encode($db->query($sql)->fetchAll());
    exit;
}

// --- POST /api/admin/email-draft-done --- poller marks a queue row processed
if ($action === 'email-draft-done' && $method === 'POST') {
    $body = getJsonBody();
    $queueId = (int) ($body['queue_id'] ?? 0);
    $status = ($body['status'] ?? '') === 'done' ? 'done' : 'failed';
    $error = $body['error'] ?? null;
    if (!$queueId) jsonResponse(['error' => 'queue_id required'], 400);

    $stmt = $db->prepare('UPDATE email_draft_queue SET status = ?, error = ?, processed_at = NOW() WHERE id = ?');
    $stmt->execute([$status, $error, $queueId]);
    jsonResponse(['success' => true]);
}

// --- GET /api/admin/qa-sessions ---
if ($action === 'qa-sessions-admin') {
    $rows = $db->query('SELECT * FROM qa_sessions ORDER BY scheduled_at DESC')->fetchAll();
    echo json_encode($rows);
    exit;
}

// --- POST /api/admin/qa-sessions (create new) ---
if ($action === 'qa-session-create' && $method === 'POST') {
    $data = getJsonBody();
    $title = trim($data['title'] ?? '');
    $description = trim($data['description'] ?? '');
    $scheduledAt = trim($data['scheduled_at'] ?? '');
    $duration = (int) ($data['duration_minutes'] ?? 60);
    $maxPart = (int) ($data['max_participants'] ?? 50);
    $meetingLink = trim($data['meeting_link'] ?? '');
    $reminderSchedule = qaNormalizeSchedule($data['reminder_schedule'] ?? 'off');

    if (!$title || !$scheduledAt) {
        jsonResponse(['error' => 'Title and scheduled_at are required.'], 400);
    }

    $stmt = $db->prepare('INSERT INTO qa_sessions (title, description, scheduled_at, duration_minutes, max_participants, meeting_link, reminder_schedule) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([$title, $description, $scheduledAt, $duration, $maxPart, $meetingLink, $reminderSchedule]);
    jsonResponse(['ok' => true, 'id' => (int) $db->lastInsertId()]);
}

// --- POST /api/admin/qa-session/update --- (edit any field on an existing session)
// Changing the date clears the session's reminder log so the full reminder cycle
// re-fires for the new date (the UNIQUE key still prevents double-sends within a
// cycle). Setting status to 'cancelled' emails every registrant.
if ($action === 'qa-session-update' && $method === 'POST') {
    $data = getJsonBody();
    $sid = (int) ($data['id'] ?? 0);
    if (!$sid) jsonResponse(['error' => 'id required'], 400);

    $prevStmt = $db->prepare('SELECT * FROM qa_sessions WHERE id = ?');
    $prevStmt->execute([$sid]);
    $prev = $prevStmt->fetch();
    if (!$prev) jsonResponse(['error' => 'session not found'], 404);

    $sets = [];
    $params = [];
    // Free-text / passthrough fields
    foreach (['title' => 'title', 'description' => 'description', 'meeting_link' => 'meeting_link'] as $key => $col) {
        if (array_key_exists($key, $data)) { $sets[] = "$col = ?"; $params[] = trim((string) $data[$key]); }
    }
    if (array_key_exists('scheduled_at', $data)) {
        $when = trim((string) $data['scheduled_at']);
        if ($when === '' || !strtotime($when)) jsonResponse(['error' => 'invalid scheduled_at'], 400);
        $sets[] = 'scheduled_at = ?'; $params[] = $when;
    }
    if (array_key_exists('duration_minutes', $data)) { $sets[] = 'duration_minutes = ?'; $params[] = max(1, (int) $data['duration_minutes']); }
    if (array_key_exists('max_participants', $data)) { $sets[] = 'max_participants = ?'; $params[] = max(1, (int) $data['max_participants']); }
    if (array_key_exists('reminder_schedule', $data)) { $sets[] = 'reminder_schedule = ?'; $params[] = qaNormalizeSchedule($data['reminder_schedule']); }
    if (array_key_exists('status', $data)) {
        $st = trim((string) $data['status']);
        if (in_array($st, ['upcoming', 'draft', 'past', 'cancelled'], true)) { $sets[] = 'status = ?'; $params[] = $st; }
    }
    if (!$sets) jsonResponse(['error' => 'nothing to update'], 400);

    $params[] = $sid;
    $stmt = $db->prepare('UPDATE qa_sessions SET ' . implode(', ', $sets) . ' WHERE id = ?');
    $stmt->execute($params);

    // Date changed → wipe this session's reminder log so every offset re-fires
    // against the new date. Without this, offsets already sent for the old date
    // stay marked "sent" forever and registrants get no reminders at all.
    $dateChanged = array_key_exists('scheduled_at', $data)
        && trim((string) $data['scheduled_at']) !== (string) $prev['scheduled_at'];
    if ($dateChanged) {
        $db->prepare('DELETE FROM qa_reminder_log WHERE session_id = ?')->execute([$sid]);
    }

    // Transition into 'cancelled' → tell every registrant. Best effort per
    // recipient; one bad address must not block the rest.
    $nowCancelled = array_key_exists('status', $data)
        && trim((string) $data['status']) === 'cancelled'
        && $prev['status'] !== 'cancelled';
    if ($nowCancelled) {
        $freshStmt = $db->prepare('SELECT * FROM qa_sessions WHERE id = ?');
        $freshStmt->execute([$sid]);
        $freshSession = $freshStmt->fetch();
        $suStmt = $db->prepare('SELECT name, email FROM qa_signups WHERE session_id = ?');
        $suStmt->execute([$sid]);
        foreach ($suStmt->fetchAll() as $su) {
            try {
                sendQaCancellation($su['email'], $su['name'], $freshSession);
            } catch (\Exception $e) {
                error_log('QA cancellation email failed for ' . $su['email'] . ': ' . $e->getMessage());
            }
        }
    }

    jsonResponse(['ok' => true]);
}

// --- POST /api/admin/qa-session/delete ---
if ($action === 'qa-session-delete' && $method === 'POST') {
    $data = getJsonBody();
    $sid = (int) ($data['id'] ?? 0);
    if (!$sid) jsonResponse(['error' => 'id required'], 400);
    $db->prepare('DELETE FROM qa_sessions WHERE id = ?')->execute([$sid]); // cascades signups + reminder log
    jsonResponse(['ok' => true]);
}

// --- GET /api/admin/qa-signups?session_id=ID ---
if ($action === 'qa-signups') {
    $sessId = (int) ($_GET['session_id'] ?? 0);
    if (!$sessId) jsonResponse(['error' => 'session_id required'], 400);
    $stmt = $db->prepare('SELECT * FROM qa_signups WHERE session_id = ? ORDER BY created_at DESC');
    $stmt->execute([$sessId]);
    echo json_encode($stmt->fetchAll());
    exit;
}

// --- GET /api/admin/qa-signups-all ---  (every signup across all sessions, session title joined)
if ($action === 'qa-signups-all') {
    $sql = 'SELECT s.id, s.session_id, s.name, s.email, s.message, s.source, s.created_at,
                   q.title AS session_title, q.scheduled_at
            FROM qa_signups s
            LEFT JOIN qa_sessions q ON q.id = s.session_id
            ORDER BY s.created_at DESC';
    echo json_encode($db->query($sql)->fetchAll());
    exit;
}

// --- GET /api/admin/waitlist ---
if ($action === 'waitlist') {
    $rows = $db->query('SELECT * FROM waitlist ORDER BY id DESC')->fetchAll();
    echo json_encode($rows);
    exit;
}

// --- GET /api/admin/private-inquiries ---
if ($action === 'private-inquiries') {
    $rows = $db->query('SELECT * FROM private_inquiries ORDER BY id DESC')->fetchAll();
    echo json_encode($rows);
    exit;
}

// --- GET /api/admin/private-applications ---
if ($action === 'private-applications') {
    $rows = $db->query('SELECT * FROM private_coaching_applications ORDER BY id DESC')->fetchAll();
    echo json_encode($rows);
    exit;
}

jsonResponse(['error' => 'Unknown action'], 400);
