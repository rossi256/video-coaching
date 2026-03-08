const router = require('express').Router();
const path = require('path');
const fs = require('fs');
const { getSubmissionByToken, getReplyItems } = require('../db');

const UPLOADS_DIR = path.join(__dirname, '..', 'uploads');

// GET /api/reply/:token — JSON data for reply page
router.get('/api/reply/:token', (req, res) => {
  const sub = getSubmissionByToken(req.params.token);
  if (!sub) return res.status(404).json({ error: 'Not found' });

  const replyDir = path.join(UPLOADS_DIR, sub.id.toString(), 'reply');
  let replyFiles = [];
  if (fs.existsSync(replyDir)) {
    replyFiles = fs.readdirSync(replyDir).filter(f => !f.startsWith('.'));
  }

  const replyItems = getReplyItems(sub.id);

  res.json({
    name: sub.name,
    status: sub.status,
    replyFiles,
    replyItems,
    token: sub.token,
    feedbackSentAt: sub.feedback_sent_at,
  });
});

// GET /api/reply/:token/video/:filename — serve reply video (token-gated)
router.get('/api/reply/:token/video/:filename', (req, res) => {
  const { token, filename } = req.params;

  if (filename.includes('/') || filename.includes('\\') || filename.startsWith('.')) {
    return res.status(400).send('Invalid filename');
  }

  const sub = getSubmissionByToken(token);
  if (!sub) return res.status(404).send('Not found');

  const filePath = path.join(UPLOADS_DIR, sub.id.toString(), 'reply', filename);
  const safeBase = path.resolve(UPLOADS_DIR, sub.id.toString(), 'reply');
  if (!path.resolve(filePath).startsWith(safeBase)) {
    return res.status(403).send('Forbidden');
  }
  if (!fs.existsSync(filePath)) return res.status(404).send('Not found');

  res.sendFile(path.resolve(filePath));
});

module.exports = router;
