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
  `);

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
};
