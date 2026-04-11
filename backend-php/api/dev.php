<?php
/**
 * WingCoach — Dev Test Dashboard (PHP)
 * Fire all 7 email types, create test submissions, trigger abandoned checkout, etc.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers/email.php';

if (!defined('DEV_BYPASS') || !DEV_BYPASS) {
    http_response_code(403);
    echo 'Dev mode is disabled.';
    exit;
}

$action = $_GET['_action'] ?? 'dashboard';
$method = $_SERVER['REQUEST_METHOD'];
$db = getDb();

// ─── API Actions ───────────────────────────────────────────────────────────────

// POST /api/dev/email-test — send any email type
if ($action === 'email-test' && $method === 'POST') {
    setApiHeaders();
    $body = getJsonBody();
    $type = $body['type'] ?? '';
    $email = trim($body['email'] ?? '');
    $name = $body['name'] ?? 'Test Rider';
    $submissionId = $body['submissionId'] ?? null;

    if (!$email) {
        jsonResponse(['ok' => false, 'error' => 'email required']);
    }

    $sub = null;
    if ($submissionId) {
        $sub = $db->prepare('SELECT * FROM submissions WHERE id = ?')->execute([$submissionId]);
        $sub = $db->prepare('SELECT * FROM submissions WHERE id = ?');
        $sub->execute([$submissionId]);
        $sub = $sub->fetch();
    }
    $riderName = $name ?: ($sub['name'] ?? 'Test Rider');
    $token = $sub['token'] ?? 'preview-token';

    try {
        switch ($type) {
            case 'upload-link':
                sendUploadLink($email, $riderName, BASE_URL . '/success?session_id=dev_test_preview');
                break;
            case 'submission-confirmation':
                sendSubmissionConfirmation($email, $riderName, BASE_URL . '/success?session_id=dev_test_preview');
                break;
            case 'receipt-confirmation':
                sendReceiptConfirmation($email, $riderName);
                break;
            case 'feedback-ready':
                sendFeedbackReady($email, $riderName, BASE_URL . '/reply/' . $token);
                break;
            case 'abandoned-checkout':
                sendAbandonedCheckoutReminder($email, BASE_URL . '/');
                break;
            case 'admin-payment':
                sendAdminNotification($riderName, $email);
                break;
            case 'admin-submission':
                sendSubmissionNotification($riderName, $email, $submissionId ?: 'test', $sub);
                break;
            default:
                jsonResponse(['ok' => false, 'error' => 'Unknown type: ' . $type]);
        }
        jsonResponse(['ok' => true]);
    } catch (\Exception $e) {
        jsonResponse(['ok' => false, 'error' => $e->getMessage()]);
    }
}

// GET /api/dev/bypass — create a fake submission (step 1: just paid)
if ($action === 'bypass') {
    $email = trim($_GET['email'] ?? 'test@example.com');
    $fakeSessionId = 'dev_test_' . time() . '_' . mt_rand(1000, 9999);

    // Get spots
    $spots = $db->query("SELECT * FROM config WHERE `key` IN ('total_spots','spots_taken')")->fetchAll();
    $total = 10;
    $taken = 0;
    foreach ($spots as $s) {
        if ($s['key'] === 'total_spots') $total = (int)$s['value'];
        if ($s['key'] === 'spots_taken') $taken = (int)$s['value'];
    }

    // Create checkout attempt
    $db->prepare('INSERT INTO checkout_attempts (email, stripe_session_id) VALUES (?, ?)')->execute([$email, $fakeSessionId]);

    // Create submission
    $token = bin2hex(random_bytes(16));
    $db->prepare('INSERT INTO submissions (stripe_session_id, token, status, email, spots_at_purchase) VALUES (?, ?, ?, ?, ?)')->execute([
        $fakeSessionId, $token, 'paid', $email, $total - $taken
    ]);
    $submissionId = $db->lastInsertId();

    // Mark checkout attempt as converted
    $db->prepare('UPDATE checkout_attempts SET converted = 1 WHERE stripe_session_id = ?')->execute([$fakeSessionId]);

    $redirectUrl = BASE_URL . '/success?session_id=' . urlencode($fakeSessionId);
    header('Content-Type: text/html; charset=utf-8');
    echo <<<HTML
    <div style="font:16px/1.6 sans-serif;padding:28px;max-width:600px;margin:0 auto;background:#0d1b2e;color:#e2e8f0;border-radius:12px;">
      <p>Dev bypass — submission <strong>#$submissionId</strong> created for <strong>{$email}</strong>.</p>
      <p><a href="$redirectUrl" style="color:#38bdf8;">→ Go to success page</a></p>
      <p><a href="javascript:history.back()" style="color:#64748b;font-size:13px;">← Back to dashboard</a></p>
    </div>
HTML;
    exit;
}

// GET /api/dev/abandoned-test — inject old attempt + trigger check
if ($action === 'abandoned-test') {
    setApiHeaders();
    $email = trim($_GET['email'] ?? 'test-abandoned@example.com');
    $oldTime = date('Y-m-d H:i:s', time() - 31 * 60);
    $fakeSession = 'dev_abandoned_' . time() . '_' . mt_rand(1000, 9999);

    $db->prepare('INSERT INTO checkout_attempts (email, stripe_session_id, created_at) VALUES (?, ?, ?)')->execute([$email, $fakeSession, $oldTime]);

    // Run abandoned checkout check inline
    $stmt = $db->prepare("SELECT id, email FROM checkout_attempts WHERE converted = 0 AND reminded_at IS NULL AND created_at < DATE_SUB(NOW(), INTERVAL 30 MINUTE)");
    $stmt->execute();
    $attempts = $stmt->fetchAll();
    $sent = 0;

    foreach ($attempts as $attempt) {
        try {
            sendAbandonedCheckoutReminder($attempt['email'], BASE_URL . '/');
            $db->prepare('UPDATE checkout_attempts SET reminded_at = NOW() WHERE id = ?')->execute([$attempt['id']]);
            $sent++;
        } catch (\Exception $e) {
            error_log('Abandoned checkout email error: ' . $e->getMessage());
        }
    }

    jsonResponse(['ok' => true, 'injected' => $email, 'reminders_sent' => $sent]);
}

// GET /api/dev/latest-reply — redirect to most recent submission's reply page
if ($action === 'latest-reply') {
    $sub = $db->query("SELECT token FROM submissions WHERE token IS NOT NULL AND status != 'paid' ORDER BY id DESC LIMIT 1")->fetch();
    if (!$sub) {
        header('Content-Type: text/html');
        echo '<p style="font:14px sans-serif;padding:20px;background:#0d1b2e;color:#e2e8f0;">No submitted/active submissions found.</p>';
        exit;
    }
    header('Location: ' . BASE_URL . '/reply/' . $sub['token']);
    exit;
}

// ─── Dashboard Page ────────────────────────────────────────────────────────────

// Fetch spots
$spots = $db->query("SELECT * FROM config WHERE `key` IN ('total_spots','spots_taken')")->fetchAll();
$total = 10;
$taken = 0;
foreach ($spots as $s) {
    if ($s['key'] === 'total_spots') $total = (int)$s['value'];
    if ($s['key'] === 'spots_taken') $taken = (int)$s['value'];
}

// Fetch all submissions
$subs = $db->query('SELECT id, name, email, status, token FROM submissions ORDER BY id DESC')->fetchAll();
$subOptions = '';
foreach ($subs as $s) {
    $eName = htmlspecialchars($s['name'] ?? 'No name');
    $eEmail = htmlspecialchars($s['email'] ?? 'no email');
    $eDataEmail = htmlspecialchars($s['email'] ?? '');
    $eDataName = htmlspecialchars($s['name'] ?? '');
    $subOptions .= "<option value=\"{$s['id']}\" data-email=\"{$eDataEmail}\" data-name=\"{$eDataName}\">#{$s['id']} — {$eName} ({$eEmail}) [{$s['status']}]</option>\n";
}

// Fetch checkout attempts (recent)
$attempts = $db->query('SELECT id, email, stripe_session_id, created_at, reminded_at, converted FROM checkout_attempts ORDER BY id DESC LIMIT 20')->fetchAll();

// Fetch submission status counts
$statusCounts = $db->query("SELECT status, COUNT(*) as cnt FROM submissions GROUP BY status")->fetchAll();
$statusMap = [];
foreach ($statusCounts as $sc) $statusMap[$sc['status']] = $sc['cnt'];

$base = BASE_URL;
$adminPass = ADMIN_PASSWORD;

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="en"><head>
<title>WingCoach Dev — Test Dashboard</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
  *{box-sizing:border-box;margin:0;padding:0;}
  body{font:14px/1.6 'Helvetica Neue',Helvetica,Arial,sans-serif;padding:24px;max-width:900px;background:#080f1c;color:#e2e8f0;margin:0 auto;}
  h1{color:#0ea5e9;margin:0 0 4px;font-size:24px;font-weight:800;}
  .subtitle{color:#475569;font-size:13px;margin:0 0 20px;}
  .stats{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:20px;}
  .stat{background:#1e293b;border-radius:8px;padding:8px 14px;font-size:12px;font-weight:600;}
  .stat .num{color:#0ea5e9;font-size:18px;display:block;}
  .section{background:#111827;border:1px solid #1e293b;border-radius:12px;padding:20px 24px;margin:16px 0;}
  .section h3{margin:0 0 14px;color:#38bdf8;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;}
  a.link{color:#0ea5e9;text-decoration:none;display:block;margin:6px 0;padding:10px 14px;background:rgba(14,165,233,0.06);border-radius:8px;border:1px solid rgba(14,165,233,0.2);font-size:13px;transition:all .15s;}
  a.link:hover{background:rgba(14,165,233,0.15);border-color:rgba(14,165,233,0.4);}
  .note{color:#64748b;font-size:12px;margin:4px 0 10px;}
  label.lbl{font-size:11px;color:#64748b;font-weight:700;text-transform:uppercase;letter-spacing:.05em;display:block;margin-bottom:4px;}
  input,select{width:100%;background:#0d1b2e;border:1px solid rgba(255,255,255,0.12);color:#e2e8f0;border-radius:8px;padding:9px 12px;font-size:13px;margin-bottom:10px;outline:none;transition:border-color .15s;}
  input:focus,select:focus{border-color:#0ea5e9;}
  .grid2{display:grid;grid-template-columns:1fr 1fr;gap:12px;}
  .email-row{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:12px 0;border-bottom:1px solid rgba(255,255,255,0.04);}
  .email-row:last-child{border-bottom:none;}
  .email-label{font-weight:600;font-size:13px;}
  .email-desc{color:#64748b;font-size:12px;margin-top:2px;}
  .btn-send{background:rgba(14,165,233,0.12);border:1px solid rgba(14,165,233,0.35);color:#38bdf8;font-size:12px;font-weight:700;padding:8px 16px;border-radius:8px;cursor:pointer;white-space:nowrap;transition:all .15s;}
  .btn-send:hover{background:rgba(14,165,233,0.25);}
  .btn-send:active{transform:scale(0.97);}
  .btn-danger{background:rgba(239,68,68,0.1);border:1px solid rgba(239,68,68,0.3);color:#f87171;}
  .btn-danger:hover{background:rgba(239,68,68,0.2);}
  #result{margin-top:14px;padding:12px 16px;border-radius:8px;font-size:13px;display:none;font-weight:500;}
  .ok{background:rgba(34,197,94,0.1);border:1px solid rgba(34,197,94,0.25);color:#4ade80;}
  .err{background:rgba(239,68,68,0.1);border:1px solid rgba(239,68,68,0.25);color:#f87171;}
  table.data{width:100%;border-collapse:collapse;font-size:12px;}
  table.data th{text-align:left;color:#64748b;font-weight:600;padding:6px 10px;border-bottom:1px solid #1e293b;font-size:11px;text-transform:uppercase;letter-spacing:.05em;}
  table.data td{padding:6px 10px;border-bottom:1px solid rgba(255,255,255,0.04);color:#cbd5e1;}
  table.data tr:hover td{background:rgba(14,165,233,0.04);}
  .badge{display:inline-block;padding:2px 8px;border-radius:20px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;}
  .badge-paid{background:rgba(250,204,21,0.15);color:#fbbf24;}
  .badge-submitted{background:rgba(59,130,246,0.15);color:#60a5fa;}
  .badge-in_progress{background:rgba(168,85,247,0.15);color:#c084fc;}
  .badge-feedback_sent{background:rgba(34,197,94,0.15);color:#4ade80;}
  .badge-yes{background:rgba(34,197,94,0.15);color:#4ade80;}
  .badge-no{background:rgba(239,68,68,0.1);color:#f87171;}
  .tabs{display:flex;gap:0;margin-bottom:0;}
  .tab{padding:10px 18px;font-size:12px;font-weight:600;cursor:pointer;color:#64748b;border-bottom:2px solid transparent;transition:all .15s;}
  .tab:hover{color:#e2e8f0;}
  .tab.active{color:#0ea5e9;border-bottom-color:#0ea5e9;}
  .tab-content{display:none;}
  .tab-content.active{display:block;}
  @media(max-width:600px){.grid2{grid-template-columns:1fr;} .stats{flex-direction:column;}}
</style>
</head>
<body>

<h1>WingCoach Dev Dashboard</h1>
<p class="subtitle">Spots: <?= $taken ?>/<?= $total ?> taken &nbsp;·&nbsp; DEV_BYPASS=true &nbsp;·&nbsp; PHP backend</p>

<div class="stats">
  <div class="stat"><span class="num"><?= count($subs) ?></span>Total</div>
  <div class="stat"><span class="num"><?= $statusMap['paid'] ?? 0 ?></span>Paid</div>
  <div class="stat"><span class="num"><?= $statusMap['submitted'] ?? 0 ?></span>Submitted</div>
  <div class="stat"><span class="num"><?= $statusMap['in_progress'] ?? 0 ?></span>In Progress</div>
  <div class="stat"><span class="num"><?= $statusMap['feedback_sent'] ?? 0 ?></span>Feedback Sent</div>
</div>

<!-- ── Tabs ── -->
<div class="tabs">
  <div class="tab active" onclick="switchTab('emails')">Email Tester</div>
  <div class="tab" onclick="switchTab('flow')">Flow Steps</div>
  <div class="tab" onclick="switchTab('submissions')">Submissions</div>
  <div class="tab" onclick="switchTab('checkouts')">Checkout Attempts</div>
</div>

<!-- ── Email Tester ── -->
<div class="section tab-content active" id="tab-emails">
  <h3>Send Test Emails</h3>
  <p class="note">Pick a submission to auto-fill, or enter any email/name manually. Then fire any email template.</p>

  <label class="lbl">Pick a submission</label>
  <select id="sub-select" onchange="fillFromSub(this)">
    <option value="">— custom email/name —</option>
    <?= $subOptions ?>
  </select>

  <div class="grid2">
    <div>
      <label class="lbl">Email</label>
      <input type="email" id="test-email" placeholder="rider@example.com">
    </div>
    <div>
      <label class="lbl">Name</label>
      <input type="text" id="test-name" placeholder="Rider Name">
    </div>
  </div>

  <div id="email-rows">
    <?php
    $emails = [
        ['type' => 'upload-link',             'label' => '1. Upload Link',             'desc' => 'Sent after payment — links rider to success page', 'icon' => '📧'],
        ['type' => 'submission-confirmation',  'label' => '2. Submission Confirmation', 'desc' => 'Sent to rider after they click "Send to Michi"', 'icon' => '📨'],
        ['type' => 'receipt-confirmation',     'label' => '3. Receipt Confirmation',    'desc' => 'Sent to rider when admin clicks "Confirm Receipt"', 'icon' => '📬'],
        ['type' => 'feedback-ready',           'label' => '4. Feedback Ready',          'desc' => 'Sent to rider when admin clicks "Mark Feedback Sent"', 'icon' => '🎬'],
        ['type' => 'abandoned-checkout',       'label' => '5. Abandoned Checkout',      'desc' => 'Sent 30min after entering email without paying', 'icon' => '⏰'],
        ['type' => 'admin-payment',            'label' => '6. Admin: New Payment',      'desc' => 'Sent to admin (NOTIFY_EMAIL) on payment', 'icon' => '💰'],
        ['type' => 'admin-submission',         'label' => '7. Admin: New Submission',   'desc' => 'Sent to admin when rider submits their videos', 'icon' => '📋'],
    ];
    foreach ($emails as $e): ?>
    <div class="email-row">
      <div style="flex:1;">
        <div class="email-label"><?= $e['icon'] ?> <?= $e['label'] ?></div>
        <div class="email-desc"><?= $e['desc'] ?></div>
      </div>
      <button class="btn-send" onclick="sendTestEmail('<?= $e['type'] ?>')">Send &rarr;</button>
    </div>
    <?php endforeach; ?>
  </div>

  <div id="result"></div>
</div>

<!-- ── Flow Steps ── -->
<div class="section tab-content" id="tab-flow">
  <h3>Step 1 — Checkout (Rider)</h3>
  <p class="note">Real landing page. Or skip Stripe with the dev bypass to create a fake paid submission.</p>
  <a class="link" href="<?= $base ?>/" target="_blank">→ Landing page</a>
  <a class="link" href="<?= $base ?>/api/dev/bypass?email=test@tricktionary.com" target="_blank">→ Dev bypass: create fake paid submission (test@tricktionary.com)</a>

  <h3 style="margin-top:20px;">Step 2 — Abandoned Checkout</h3>
  <p class="note">Injects a 31-min-old checkout attempt for the email above, fires reminder immediately.</p>
  <a class="link" href="#" onclick="triggerAbandoned(); return false;">→ Trigger abandoned checkout reminder</a>
  <div id="abandoned-result" style="margin-top:8px;font-size:13px;"></div>

  <h3 style="margin-top:20px;">Step 3 — Upload & Submit (Rider)</h3>
  <p class="note">Go to the success page link from the bypass, fill the form, upload a video, submit.</p>

  <h3 style="margin-top:20px;">Step 4 — Admin</h3>
  <a class="link" href="<?= $base ?>/admin" target="_blank">→ Admin panel (pass: <?= htmlspecialchars($adminPass) ?>)</a>
  <p class="note">Confirm Receipt → add reply video + text → Mark Feedback Sent.</p>

  <h3 style="margin-top:20px;">Step 5 — Reply Page (Rider)</h3>
  <a class="link" href="<?= $base ?>/api/dev/latest-reply" target="_blank">→ Latest submission reply page</a>

  <h3 style="margin-top:20px;">Step 6 — Run Abandoned Checkout Cron</h3>
  <p class="note">Manually trigger the cron that checks for un-reminded checkout attempts older than 30 min.</p>
  <a class="link" href="#" onclick="runAbandonedCron(); return false;">→ Run abandoned checkout cron now</a>
  <div id="cron-result" style="margin-top:8px;font-size:13px;"></div>
</div>

<!-- ── Submissions Table ── -->
<div class="section tab-content" id="tab-submissions">
  <h3>All Submissions (<?= count($subs) ?>)</h3>
  <?php if (empty($subs)): ?>
    <p class="note">No submissions yet. Use the bypass to create one.</p>
  <?php else: ?>
    <div style="overflow-x:auto;">
    <table class="data">
      <thead><tr><th>ID</th><th>Name</th><th>Email</th><th>Status</th><th>Token</th></tr></thead>
      <tbody>
      <?php foreach ($subs as $s): ?>
        <tr>
          <td>#<?= $s['id'] ?></td>
          <td><?= htmlspecialchars($s['name'] ?? '—') ?></td>
          <td><?= htmlspecialchars($s['email'] ?? '—') ?></td>
          <td><span class="badge badge-<?= $s['status'] ?>"><?= $s['status'] ?></span></td>
          <td style="font-family:monospace;font-size:11px;color:#64748b;"><?= substr($s['token'] ?? '', 0, 12) ?>…</td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  <?php endif; ?>
</div>

<!-- ── Checkout Attempts ── -->
<div class="section tab-content" id="tab-checkouts">
  <h3>Recent Checkout Attempts (last 20)</h3>
  <?php if (empty($attempts)): ?>
    <p class="note">No checkout attempts recorded.</p>
  <?php else: ?>
    <div style="overflow-x:auto;">
    <table class="data">
      <thead><tr><th>ID</th><th>Email</th><th>Created</th><th>Reminded</th><th>Converted</th></tr></thead>
      <tbody>
      <?php foreach ($attempts as $a): ?>
        <tr>
          <td>#<?= $a['id'] ?></td>
          <td><?= htmlspecialchars($a['email']) ?></td>
          <td><?= $a['created_at'] ?></td>
          <td><?= $a['reminded_at'] ?: '—' ?></td>
          <td><span class="badge badge-<?= $a['converted'] ? 'yes' : 'no' ?>"><?= $a['converted'] ? 'Yes' : 'No' ?></span></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  <?php endif; ?>
</div>

<script>
const BP = '<?= $base ?>';

function switchTab(name) {
  document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
  document.querySelectorAll('.tab-content').forEach(t => t.classList.remove('active'));
  event.target.classList.add('active');
  document.getElementById('tab-' + name).classList.add('active');
}

function fillFromSub(sel) {
  const opt = sel.options[sel.selectedIndex];
  document.getElementById('test-email').value = opt.dataset.email || '';
  document.getElementById('test-name').value = opt.dataset.name || '';
}

async function sendTestEmail(type) {
  const email = document.getElementById('test-email').value.trim();
  const name  = document.getElementById('test-name').value.trim() || 'Test Rider';
  const subId = document.getElementById('sub-select').value;
  if (!email) { showResult('Enter an email address first.', false); return; }

  showResult('Sending...', true);
  try {
    const r = await fetch(BP + '/api/dev/email-test', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ type, email, name, submissionId: subId || null }),
    });
    const d = await r.json();
    showResult(d.ok ? '\u2713 Email sent to ' + email : '\u2717 ' + (d.error || 'Unknown error'), d.ok);
  } catch (e) {
    showResult('\u2717 Network error: ' + e.message, false);
  }
}

async function triggerAbandoned() {
  const email = document.getElementById('test-email').value.trim() || 'test-abandoned@example.com';
  try {
    const r = await fetch(BP + '/api/dev/abandoned-test?email=' + encodeURIComponent(email));
    const d = await r.json();
    const el = document.getElementById('abandoned-result');
    el.innerHTML = '<span style="color:#4ade80;">\u2713 Injected abandoned checkout for ' + email + '. Reminders sent: ' + d.reminders_sent + '</span>';
  } catch (e) {
    document.getElementById('abandoned-result').innerHTML = '<span style="color:#f87171;">\u2717 ' + e.message + '</span>';
  }
}

async function runAbandonedCron() {
  try {
    const r = await fetch(BP + '/api/dev/abandoned-test?email=');
    const d = await r.json();
    const el = document.getElementById('cron-result');
    el.innerHTML = '<span style="color:#4ade80;">\u2713 Cron executed. Reminders sent: ' + d.reminders_sent + '</span>';
  } catch (e) {
    document.getElementById('cron-result').innerHTML = '<span style="color:#f87171;">\u2717 ' + e.message + '</span>';
  }
}

function showResult(msg, ok) {
  const el = document.getElementById('result');
  el.textContent = msg;
  el.className = ok ? 'ok' : 'err';
  el.style.display = 'block';
}
</script>
</body></html>
