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

  // GET /dev/test — full test flow dashboard with all URLs
  app.get(BASE_PATH + '/dev/test', (req, res) => {
    const { total, taken } = getSpots();
    const base = PUBLIC_PATH;
    const api = PUBLIC_PATH;
    res.send(`<!DOCTYPE html><html><head><title>WingCoach Test Flow</title>
    <style>body{font:14px/1.6 sans-serif;padding:32px;max-width:760px;background:#0d1b2e;color:#e2e8f0;}
    h1{color:#0ea5e9;margin-bottom:4px;}h2{color:#94a3b8;font-size:12px;font-weight:normal;margin-top:0 0 24px;}
    .section{background:#1e293b;border-radius:8px;padding:20px;margin:16px 0;}
    .section h3{margin:0 0 12px;color:#38bdf8;font-size:13px;text-transform:uppercase;letter-spacing:.06em;}
    a{color:#0ea5e9;text-decoration:none;display:block;margin:6px 0;padding:8px 12px;background:rgba(14,165,233,0.1);border-radius:6px;border:1px solid rgba(14,165,233,0.3);}
    a:hover{background:rgba(14,165,233,0.2);}
    .note{color:#64748b;font-size:12px;margin:4px 0 8px;}</style></head>
    <body>
    <h1>WingCoach Test Flow</h1>
    <h2>Spots: ${taken}/${total} taken</h2>

    <div class="section">
      <h3>Step 1 — Checkout (Rider side)</h3>
      <p class="note">Landing page — rider enters email and clicks "Claim my spot"</p>
      <a href="${base}/" target="_blank">→ Landing page (${base}/)</a>
      <p class="note">Or skip Stripe entirely (creates a fake paid submission):</p>
      <a href="${base}/dev/bypass" target="_blank">→ Dev bypass: create fake submission</a>
    </div>

    <div class="section">
      <h3>Step 2 — Abandoned Checkout (test immediately)</h3>
      <p class="note">Creates a checkout attempt 31 min old, then triggers the reminder check now</p>
      <a href="${api}/dev/abandoned-checkout-test" target="_blank">→ Trigger abandoned checkout test</a>
    </div>

    <div class="section">
      <h3>Step 3 — Upload & Submit (Rider side)</h3>
      <p class="note">After bypass, go to the success page. Fill in profile, upload videos, click Submit.</p>
      <p class="note">For a specific submission ID, append ?session_id=dev_test_... from the bypass URL.</p>
    </div>

    <div class="section">
      <h3>Step 4 — Admin Review</h3>
      <a href="${base}/admin" target="_blank">→ Admin panel (${base}/admin)</a>
      <p class="note">Login with ADMIN_PASSWORD. View submissions, click one to see details.</p>
      <p class="note">Test "Confirm Receipt" button (status: submitted → in_progress).</p>
      <p class="note">Add a video reply + text reply via Coaching Reply Builder.</p>
    </div>

    <div class="section">
      <h3>Step 5 — Reply Page (Rider side)</h3>
      <p class="note">After marking "Feedback Sent", a reply URL is shown. Open it.</p>
      <a href="${api}/dev/latest-reply" target="_blank">→ Open latest submission's reply page</a>
    </div>

    <div class="section">
      <h3>API Shortcuts</h3>
      <a href="${api}/api/spots" target="_blank">→ GET /api/spots</a>
      <a href="${api}/api/admin/submissions" target="_blank">→ GET /api/admin/submissions (needs auth)</a>
    </div>
    </body></html>`);
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
