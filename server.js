require('dotenv').config();
const express = require('express');
const path = require('path');
const fs = require('fs');
const { initDb, getSpots, getUnremindedAttempts, markAttemptReminded } = require('./db');
const { sendAbandonedCheckoutReminder } = require('./email');

const app = express();
const PORT = process.env.PORT || 3010;
// ROUTE_PREFIX: what Express mounts on (empty when Apache strips the prefix via ProxyPass)
// PUBLIC_PATH:  what the browser sees — injected into HTML for JS fetch calls and asset URLs
const ROUTE_PREFIX = process.env.BASE_PATH || '';
const PUBLIC_PATH = process.env.PUBLIC_PATH || ROUTE_PREFIX;
const BASE_PATH = ROUTE_PREFIX; // alias kept for route mounting lines below

// ─── Basic Auth Middleware ────────────────────────────────────────────────────
function basicAuth(req, res, next) {
  const auth = req.headers.authorization;
  if (!auth || !auth.startsWith('Basic ')) {
    res.setHeader('WWW-Authenticate', 'Basic realm="WingCoach Admin"');
    return res.status(401).send('Authentication required');
  }
  const decoded = Buffer.from(auth.slice(6), 'base64').toString();
  const colonIdx = decoded.indexOf(':');
  const pass = decoded.slice(colonIdx + 1);
  if (pass !== process.env.ADMIN_PASSWORD) {
    res.setHeader('WWW-Authenticate', 'Basic realm="WingCoach Admin"');
    return res.status(401).send('Invalid credentials');
  }
  next();
}

// ─── HTML Helper (injects BASE_PATH) ─────────────────────────────────────────
function sendHtml(res, file) {
  try {
    const html = fs
      .readFileSync(path.join(__dirname, 'public', file), 'utf8')
      .replace(/\{\{BASE_PATH\}\}/g, PUBLIC_PATH);
    res.setHeader('Content-Type', 'text/html; charset=utf-8');
    res.send(html);
  } catch (err) {
    console.error('sendHtml error:', err);
    res.status(500).send('Page not found');
  }
}

// ─── Stripe Webhook (raw body — must be before json middleware) ───────────────
app.use(
  BASE_PATH + '/webhook/stripe',
  express.raw({ type: 'application/json' }),
  require('./routes/webhook')
);

// ─── Body Parsers ─────────────────────────────────────────────────────────────
app.use(express.json());
app.use(express.urlencoded({ extended: true }));

// ─── Static Files ─────────────────────────────────────────────────────────────
app.use(BASE_PATH + '/static', express.static(path.join(__dirname, 'public')));

// ─── Core API ─────────────────────────────────────────────────────────────────
app.get(BASE_PATH + '/api/config', (req, res) => {
  res.json({ basePath: BASE_PATH });
});

app.get(BASE_PATH + '/api/spots', (req, res) => {
  const { total, taken } = getSpots();
  res.json({ remaining: Math.max(0, total - taken), total, taken });
});

// ─── Pages ────────────────────────────────────────────────────────────────────
app.get(BASE_PATH + '/', (req, res) => sendHtml(res, 'index.html'));
app.get(BASE_PATH + '/success', (req, res) => sendHtml(res, 'success.html'));
app.get(BASE_PATH + '/admin', basicAuth, (req, res) => sendHtml(res, 'admin.html'));
app.get(BASE_PATH + '/reply/:token', (req, res) => sendHtml(res, 'reply.html'));

// ─── API Routes ───────────────────────────────────────────────────────────────
app.use(BASE_PATH, require('./routes/checkout'));
app.use(BASE_PATH, require('./routes/upload'));
app.use(BASE_PATH, require('./routes/success'));
app.use(BASE_PATH + '/api/admin', basicAuth, require('./routes/admin'));
app.use(BASE_PATH, require('./routes/reply'));

// ─── Dev Bypass (only when DEV_BYPASS=true) ──────────────────────────────────
if (process.env.DEV_BYPASS === 'true') {
  const {
    createSubmission, getSpots, getSubmission, updateSubmission,
    createCheckoutAttempt, getUnremindedAttempts, markAttemptReminded,
    getReplyItems,
  } = require('./db');

  // GET /dev/bypass — create a fake submission (step 1: just paid)
  app.get(BASE_PATH + '/dev/bypass', (req, res) => {
    const { total, taken } = getSpots();
    const fakeSessionId = 'dev_test_' + Date.now();
    const submissionId = createSubmission(fakeSessionId, total - taken);
    const redirectUrl = PUBLIC_PATH + '/success?session_id=' + fakeSessionId;
    res.send(`<p style="font:16px sans-serif;padding:20px">Dev bypass — submission #${submissionId} created.<br><a href="${redirectUrl}">→ Go to success page</a></p>`);
  });

  // GET /dev/test — full test flow dashboard with email tester
  app.get(BASE_PATH + '/dev/test', (req, res) => {
    const { total, taken } = getSpots();
    const { getAllSubmissions } = require('./db');
    const subs = getAllSubmissions();
    const base = PUBLIC_PATH;

    const subOptions = subs.map(s =>
      `<option value="${s.id}" data-email="${(s.email||'').replace(/"/g,'')}" data-name="${(s.name||'').replace(/"/g,'')}">
        #${s.id} — ${s.name || 'No name'} (${s.email || 'no email'}) [${s.status}]
      </option>`
    ).join('');

    const emails = [
      { type: 'upload-link',              label: '1. Upload Link',              desc: 'Sent after payment — links rider to success page' },
      { type: 'submission-confirmation',  label: '2. Submission Confirmation',  desc: 'Sent to rider after they click "Send to Michi"' },
      { type: 'receipt-confirmation',     label: '3. Receipt Confirmation',     desc: 'Sent to rider when admin clicks "Confirm Receipt"' },
      { type: 'feedback-ready',           label: '4. Feedback Ready',           desc: 'Sent to rider when admin clicks "Mark Feedback Sent"' },
      { type: 'abandoned-checkout',       label: '5. Abandoned Checkout',       desc: 'Sent 30min after entering email without paying' },
      { type: 'admin-payment',            label: '6. Admin: New Payment',       desc: 'Sent to admin (NOTIFY_EMAIL) on payment' },
      { type: 'admin-submission',         label: '7. Admin: New Submission',    desc: 'Sent to admin when rider submits their videos' },
    ];

    const emailRows = emails.map(e => `
      <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;padding:10px 0;border-bottom:1px solid rgba(255,255,255,0.06);">
        <div style="flex:1;">
          <div style="font-weight:600;font-size:13px;">${e.label}</div>
          <div style="color:#64748b;font-size:12px;margin-top:2px;">${e.desc}</div>
        </div>
        <button onclick="sendTestEmail('${e.type}')"
          style="background:rgba(14,165,233,0.15);border:1px solid rgba(14,165,233,0.4);color:#38bdf8;font-size:12px;font-weight:700;padding:7px 14px;border-radius:6px;cursor:pointer;white-space:nowrap;transition:background 0.15s;"
          onmouseover="this.style.background='rgba(14,165,233,0.3)'" onmouseout="this.style.background='rgba(14,165,233,0.15)'">
          Send →
        </button>
      </div>`).join('');

    res.send(`<!DOCTYPE html>
<html><head><title>WingCoach Dev — Test Flow</title>
<style>
  *{box-sizing:border-box;}
  body{font:14px/1.6 Inter,sans-serif;padding:28px;max-width:820px;background:#0d1b2e;color:#e2e8f0;margin:0 auto;}
  h1{color:#0ea5e9;margin:0 0 2px;font-size:22px;}
  .subtitle{color:#475569;font-size:13px;margin:0 0 24px;}
  .section{background:#1e293b;border-radius:10px;padding:20px 24px;margin:16px 0;}
  .section h3{margin:0 0 14px;color:#38bdf8;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;}
  a.link{color:#0ea5e9;text-decoration:none;display:block;margin:6px 0;padding:8px 12px;background:rgba(14,165,233,0.08);border-radius:6px;border:1px solid rgba(14,165,233,0.25);font-size:13px;}
  a.link:hover{background:rgba(14,165,233,0.18);}
  .note{color:#64748b;font-size:12px;margin:4px 0 10px;}
  input,select{width:100%;background:#0d1b2e;border:1px solid rgba(255,255,255,0.15);color:#e2e8f0;border-radius:6px;padding:8px 10px;font-size:13px;margin-bottom:10px;outline:none;}
  input:focus,select:focus{border-color:#0ea5e9;}
  #result{margin-top:12px;padding:10px 14px;border-radius:6px;font-size:13px;display:none;}
  .ok{background:rgba(34,197,94,0.15);border:1px solid rgba(34,197,94,0.3);color:#4ade80;}
  .err{background:rgba(239,68,68,0.15);border:1px solid rgba(239,68,68,0.3);color:#f87171;}
  .grid2{display:grid;grid-template-columns:1fr 1fr;gap:12px;}
  @media(max-width:600px){.grid2{grid-template-columns:1fr;}}
</style></head>
<body>
<h1>WingCoach Dev — Test Dashboard</h1>
<p class="subtitle">Spots: ${taken}/${total} taken &nbsp;·&nbsp; DEV_BYPASS=true</p>

<!-- ── Email Tester ── -->
<div class="section">
  <h3>Email Tester</h3>
  <p class="note">Pick a submission to auto-fill, or enter any email/name manually. Then fire any email.</p>

  <label style="font-size:12px;color:#64748b;font-weight:600;">Pick a submission</label>
  <select id="sub-select" onchange="fillFromSub(this)">
    <option value="">— custom email/name —</option>
    ${subOptions}
  </select>

  <div class="grid2">
    <div>
      <label style="font-size:12px;color:#64748b;font-weight:600;">Email</label>
      <input type="email" id="test-email" placeholder="rider@example.com">
    </div>
    <div>
      <label style="font-size:12px;color:#64748b;font-weight:600;">Name</label>
      <input type="text" id="test-name" placeholder="Rider Name">
    </div>
  </div>

  <div id="email-rows">${emailRows}</div>
  <div id="result"></div>
</div>

<!-- ── Flow Steps ── -->
<div class="section">
  <h3>Step 1 — Checkout (Rider)</h3>
  <p class="note">Real landing page. Or skip Stripe with the bypass.</p>
  <a class="link" href="${base}/" target="_blank">→ Landing page</a>
  <a class="link" href="${base}/dev/bypass" target="_blank">→ Dev bypass: create fake paid submission</a>
</div>

<div class="section">
  <h3>Step 2 — Abandoned Checkout</h3>
  <p class="note">Injects a 31-min-old attempt for the email above, fires reminder immediately.</p>
  <a class="link" href="#" onclick="triggerAbandoned(); return false;">→ Trigger abandoned checkout reminder</a>
</div>

<div class="section">
  <h3>Step 3 — Upload & Submit (Rider)</h3>
  <p class="note">Go to the success page link from the bypass, fill the form, upload a video, submit.</p>
</div>

<div class="section">
  <h3>Step 4 — Admin</h3>
  <a class="link" href="${base}/admin" target="_blank">→ Admin panel (pass: ${process.env.ADMIN_PASSWORD})</a>
  <p class="note">Confirm Receipt → add reply video + text → Mark Feedback Sent.</p>
</div>

<div class="section">
  <h3>Step 5 — Reply page (Rider)</h3>
  <a class="link" href="${base}/dev/latest-reply" target="_blank">→ Latest submission reply page</a>
</div>

<script>
const BP = '${base}';

function fillFromSub(sel) {
  const opt = sel.options[sel.selectedIndex];
  document.getElementById('test-email').value = opt.dataset.email || '';
  document.getElementById('test-name').value = opt.dataset.name || '';
}

async function sendTestEmail(type) {
  const email = document.getElementById('test-email').value.trim();
  const name  = document.getElementById('test-name').value.trim() || 'Test Rider';
  const subId = document.getElementById('sub-select').value;
  const result = document.getElementById('result');
  if (!email) { showResult('Enter an email address first.', false); return; }

  result.style.display = 'none';
  try {
    const r = await fetch(BP + '/dev/email-test', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ type, email, name, submissionId: subId || null }),
    });
    const d = await r.json();
    showResult(d.ok ? '✓ Email sent to ' + email : '✗ ' + (d.error || 'Unknown error'), d.ok);
  } catch (e) {
    showResult('✗ Network error: ' + e.message, false);
  }
}

async function triggerAbandoned() {
  const email = document.getElementById('test-email').value.trim();
  const url = BP + '/dev/abandoned-checkout-test' + (email ? '?email=' + encodeURIComponent(email) : '');
  const r = await fetch(url);
  const text = await r.text();
  showResult('✓ Abandoned checkout triggered' + (email ? ' for ' + email : ''), true);
}

function showResult(msg, ok) {
  const el = document.getElementById('result');
  el.textContent = msg;
  el.className = ok ? 'ok' : 'err';
  el.style.display = 'block';
}
</script>
</body></html>`);
  });

  // POST /dev/email-test — send any email type to a given address
  app.post(BASE_PATH + '/dev/email-test', async (req, res) => {
    const { type, email, name, submissionId } = req.body;
    if (!email) return res.json({ ok: false, error: 'email required' });

    const {
      sendUploadLink, sendSubmissionConfirmation, sendReceiptConfirmation,
      sendFeedbackReady, sendAbandonedCheckoutReminder,
      sendAdminNotification, sendSubmissionNotification,
    } = require('./email');
    const { getSubmission } = require('./db');

    const BASE_URL = process.env.BASE_URL || `http://localhost:${PORT}${BASE_PATH || ''}`;
    const sub = submissionId ? getSubmission(parseInt(submissionId)) : null;
    const riderName = name || sub?.name || 'Test Rider';

    try {
      switch (type) {
        case 'upload-link':
          await sendUploadLink(email, riderName, `${BASE_URL}/success?session_id=dev_test_preview`);
          break;
        case 'submission-confirmation':
          await sendSubmissionConfirmation(email, riderName, `${BASE_URL}/success?session_id=dev_test_preview`);
          break;
        case 'receipt-confirmation':
          await sendReceiptConfirmation(email, riderName);
          break;
        case 'feedback-ready':
          const token = sub?.token || 'preview-token';
          await sendFeedbackReady(email, riderName, `${BASE_URL}/reply/${token}`);
          break;
        case 'abandoned-checkout':
          await sendAbandonedCheckoutReminder(email, `${BASE_URL}/`);
          break;
        case 'admin-payment':
          await sendAdminNotification(riderName, email);
          break;
        case 'admin-submission':
          await sendSubmissionNotification(riderName, email, submissionId || 'test', sub || null);
          break;
        default:
          return res.json({ ok: false, error: 'Unknown type: ' + type });
      }
      res.json({ ok: true });
    } catch (err) {
      console.error('Dev email test error:', err);
      res.json({ ok: false, error: err.message });
    }
  });

  // GET /dev/abandoned-checkout-test — inject a fake old attempt and trigger check immediately
  app.get(BASE_PATH + '/dev/abandoned-checkout-test', (req, res) => {
    const testEmail = req.query.email || 'test-abandoned@example.com';
    // Insert attempt with created_at 31 min ago
    const db = require('better-sqlite3')(require('path').join(__dirname, 'coaching.db'));
    const oldTime = new Date(Date.now() - 31 * 60 * 1000).toISOString();
    db.prepare(
      'INSERT OR IGNORE INTO checkout_attempts (email, stripe_session_id, created_at) VALUES (?, ?, ?)'
    ).run(testEmail, 'dev_abandoned_' + Date.now(), oldTime);
    db.close();

    // Trigger the check now
    runAbandonedCheckoutCheck();

    res.send(`<p style="font:14px sans-serif;padding:20px;background:#0d1b2e;color:#e2e8f0;">Injected abandoned checkout for <strong>${testEmail}</strong> (31 min old) and triggered reminder check. Check server logs + email inbox.</p>`);
  });

  // GET /dev/latest-reply — redirect to the most recent submission's reply page
  app.get(BASE_PATH + '/dev/latest-reply', (req, res) => {
    const { getAllSubmissions } = require('./db');
    const subs = getAllSubmissions();
    const latest = subs.find(s => s.token && s.status !== 'paid');
    if (!latest) return res.send('<p style="font:14px sans-serif;padding:20px;">No submitted/active submissions found.</p>');
    res.redirect(PUBLIC_PATH + '/reply/' + latest.token);
  });

  console.log('[DEV] Bypass enabled at ' + PUBLIC_PATH + '/dev/bypass');
  console.log('[DEV] Test dashboard at ' + PUBLIC_PATH + '/dev/test');
}

// ─── Abandoned Checkout Scheduler ────────────────────────────────────────────
function runAbandonedCheckoutCheck() {
  try {
    const attempts = getUnremindedAttempts(30);
    if (attempts.length === 0) return;
    const checkoutUrl = (process.env.BASE_URL || `http://localhost:${PORT}${BASE_PATH || ''}`) + '/';
    for (const attempt of attempts) {
      sendAbandonedCheckoutReminder(attempt.email, checkoutUrl)
        .then(() => {
          markAttemptReminded(attempt.id);
          console.log(`Abandoned checkout reminder sent to ${attempt.email}`);
        })
        .catch(err => console.error('Abandoned checkout email error:', err));
    }
  } catch (err) {
    console.error('Abandoned checkout check error:', err);
  }
}

// ─── Init & Start ─────────────────────────────────────────────────────────────
initDb();
app.listen(PORT, () => {
  console.log(`WingCoach running on http://localhost:${PORT}${BASE_PATH || '/'}`);
  // Check for abandoned checkouts every 5 minutes
  setInterval(runAbandonedCheckoutCheck, 5 * 60 * 1000);
});
