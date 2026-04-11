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

    if (!$title || !$scheduledAt) {
        jsonResponse(['error' => 'Title and scheduled_at are required.'], 400);
    }

    $stmt = $db->prepare('INSERT INTO qa_sessions (title, description, scheduled_at, duration_minutes, max_participants, meeting_link) VALUES (?, ?, ?, ?, ?, ?)');
    $stmt->execute([$title, $description, $scheduledAt, $duration, $maxPart, $meetingLink]);
    jsonResponse(['ok' => true, 'id' => (int) $db->lastInsertId()]);
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

jsonResponse(['error' => 'Unknown action'], 400);
