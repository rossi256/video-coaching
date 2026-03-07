require('dotenv').config();
const express = require('express');
const path = require('path');
const fs = require('fs');
const { initDb, getSpots } = require('./db');

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
  const { createSubmission, getSpots } = require('./db');
  app.get(BASE_PATH + '/dev/bypass', (req, res) => {
    const { total, taken } = getSpots();
    const fakeSessionId = 'dev_test_' + Date.now();
    const submissionId = createSubmission(fakeSessionId, total - taken);
    const redirectUrl = PUBLIC_PATH + '/success?session_id=' + fakeSessionId;
    res.send(`<p style="font:16px sans-serif;padding:20px">Dev bypass — submission #${submissionId} created.<br><a href="${redirectUrl}">→ Go to success page</a></p>`);
  });
  console.log('[DEV] Bypass enabled at ' + PUBLIC_PATH + '/dev/bypass');
}

// ─── Init & Start ─────────────────────────────────────────────────────────────
initDb();
app.listen(PORT, () => {
  console.log(`WingCoach running on http://localhost:${PORT}${BASE_PATH || '/'}`);
});
