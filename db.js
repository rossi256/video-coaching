const Database = require('better-sqlite3');
const { v4: uuidv4 } = require('uuid');
const path = require('path');

let db;

function initDb() {
  db = new Database(path.join(__dirname, 'coaching.db'));

  db.exec(`
    CREATE TABLE IF NOT EXISTS submissions (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      stripe_session_id TEXT UNIQUE,
      stripe_payment_intent TEXT,
      token TEXT UNIQUE NOT NULL,
      status TEXT DEFAULT 'paid',

      name TEXT,
      email TEXT,
      age INTEGER,
      location TEXT,
      ride_frequency TEXT,
      conditions TEXT,
      equipment TEXT,
      level TEXT,
      stuck_on TEXT,
      tried TEXT,
      success_looks_like TEXT,
      audio_file TEXT,

      created_at TEXT DEFAULT (datetime('now')),
      submitted_at TEXT,
      feedback_sent_at TEXT,
      reply_video_path TEXT,
      spots_at_purchase INTEGER
    );

    CREATE TABLE IF NOT EXISTS config (
      key TEXT PRIMARY KEY,
      value TEXT
    );

    INSERT OR IGNORE INTO config VALUES ('total_spots', '10');
    INSERT OR IGNORE INTO config VALUES ('spots_taken', '0');

    CREATE TABLE IF NOT EXISTS checkout_attempts (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      email TEXT NOT NULL,
      stripe_session_id TEXT UNIQUE,
      created_at TEXT DEFAULT (datetime('now')),
      reminded_at TEXT,
      converted INTEGER DEFAULT 0
    );

    CREATE TABLE IF NOT EXISTS reply_items (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      submission_id INTEGER NOT NULL,
      type TEXT NOT NULL DEFAULT 'video',
      filename TEXT,
      description TEXT,
      content TEXT,
      order_index INTEGER DEFAULT 0,
      created_at TEXT DEFAULT (datetime('now'))
    );
  `);

  // Migration: add confirmed_at column if it doesn't exist
  try { db.exec(`ALTER TABLE submissions ADD COLUMN confirmed_at TEXT`); } catch {}

  console.log('Database initialized');
}

function getSpots() {
  const total = parseInt(db.prepare('SELECT value FROM config WHERE key = ?').get('total_spots').value);
  const taken = parseInt(db.prepare('SELECT value FROM config WHERE key = ?').get('spots_taken').value);
  return { total, taken };
}

function decrementSpots() {
  db.prepare(
    "UPDATE config SET value = CAST(CAST(value AS INTEGER) + 1 AS TEXT) WHERE key = 'spots_taken'"
  ).run();
}

function createSubmission(stripeSessionId, spotsAtPurchase) {
  const token = uuidv4();
  const result = db
    .prepare('INSERT INTO submissions (stripe_session_id, token, spots_at_purchase) VALUES (?, ?, ?)')
    .run(stripeSessionId, token, spotsAtPurchase);
  return result.lastInsertRowid;
}

// Pre-create submission at checkout initiation (before payment confirms)
function createPendingSubmission(stripeSessionId, email, spotsAtPurchase) {
  const token = uuidv4();
  const result = db
    .prepare('INSERT INTO submissions (stripe_session_id, token, status, email, spots_at_purchase) VALUES (?, ?, ?, ?, ?)')
    .run(stripeSessionId, token, 'pending_payment', email || null, spotsAtPurchase);
  return result.lastInsertRowid;
}

function updateSubmission(id, fields) {
  const keys = Object.keys(fields);
  if (keys.length === 0) return;
  const setClause = keys.map(k => `${k} = ?`).join(', ');
  const values = keys.map(k => fields[k]);
  db.prepare(`UPDATE submissions SET ${setClause} WHERE id = ?`).run(...values, id);
}

function getSubmission(id) {
  return db.prepare('SELECT * FROM submissions WHERE id = ?').get(id);
}

function getAllSubmissions() {
  return db.prepare('SELECT * FROM submissions ORDER BY created_at DESC').all();
}

function getSubmissionByToken(token) {
  return db.prepare('SELECT * FROM submissions WHERE token = ?').get(token);
}

function getSubmissionBySessionId(sessionId) {
  return db.prepare('SELECT * FROM submissions WHERE stripe_session_id = ?').get(sessionId);
}

// ── Checkout Attempts ─────────────────────────────────────────────────────────

function createCheckoutAttempt(email, stripeSessionId) {
  db.prepare(
    'INSERT OR IGNORE INTO checkout_attempts (email, stripe_session_id) VALUES (?, ?)'
  ).run(email, stripeSessionId);
}

function markAttemptConverted(stripeSessionId) {
  db.prepare(
    'UPDATE checkout_attempts SET converted = 1 WHERE stripe_session_id = ?'
  ).run(stripeSessionId);
}

function getUnremindedAttempts(olderThanMinutes) {
  const cutoff = new Date(Date.now() - olderThanMinutes * 60 * 1000).toISOString();
  return db.prepare(
    `SELECT * FROM checkout_attempts
     WHERE converted = 0 AND reminded_at IS NULL AND created_at < ?`
  ).all(cutoff);
}

function markAttemptReminded(id) {
  db.prepare(
    'UPDATE checkout_attempts SET reminded_at = ? WHERE id = ?'
  ).run(new Date().toISOString(), id);
}

function getCheckoutAttemptBySessionId(sessionId) {
  return db.prepare('SELECT * FROM checkout_attempts WHERE stripe_session_id = ?').get(sessionId);
}

// ── Reply Items ───────────────────────────────────────────────────────────────

function addReplyItem(submissionId, type, filename, description, content) {
  const maxOrder = db.prepare(
    'SELECT MAX(order_index) as max FROM reply_items WHERE submission_id = ?'
  ).get(submissionId);
  const nextOrder = (maxOrder?.max ?? -1) + 1;
  const result = db.prepare(
    'INSERT INTO reply_items (submission_id, type, filename, description, content, order_index) VALUES (?, ?, ?, ?, ?, ?)'
  ).run(submissionId, type, filename || null, description || null, content || null, nextOrder);
  return result.lastInsertRowid;
}

function getReplyItems(submissionId) {
  return db.prepare(
    'SELECT * FROM reply_items WHERE submission_id = ? ORDER BY order_index ASC'
  ).all(submissionId);
}

function deleteReplyItem(itemId) {
  db.prepare('DELETE FROM reply_items WHERE id = ?').run(itemId);
}

function moveReplyItem(itemId, direction) {
  const item = db.prepare('SELECT * FROM reply_items WHERE id = ?').get(itemId);
  if (!item) return;
  const targetOrder = direction === 'up' ? item.order_index - 1 : item.order_index + 1;
  const sibling = db.prepare(
    'SELECT * FROM reply_items WHERE submission_id = ? AND order_index = ?'
  ).get(item.submission_id, targetOrder);
  if (sibling) {
    db.prepare('UPDATE reply_items SET order_index = ? WHERE id = ?').run(targetOrder, item.id);
    db.prepare('UPDATE reply_items SET order_index = ? WHERE id = ?').run(item.order_index, sibling.id);
  }
}

module.exports = {
  initDb,
  getSpots,
  decrementSpots,
  createSubmission,
  updateSubmission,
  getSubmission,
  getAllSubmissions,
  getSubmissionByToken,
  getSubmissionBySessionId,
  createCheckoutAttempt,
  markAttemptConverted,
  getUnremindedAttempts,
  markAttemptReminded,
  getCheckoutAttemptBySessionId,
  addReplyItem,
  getReplyItems,
  deleteReplyItem,
  moveReplyItem,
};
