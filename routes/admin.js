const router = require('express').Router();
const multer = require('multer');
const path = require('path');
const fs = require('fs');
const {
  getAllSubmissions,
  getSubmission,
  updateSubmission,
  addReplyItem,
  getReplyItems,
  deleteReplyItem,
  moveReplyItem,
} = require('../db');
const { sendFeedbackReady, sendReceiptConfirmation } = require('../email');

const UPLOADS_DIR = path.join(__dirname, '..', 'uploads');

// Storage: reply videos
const replyStorage = multer.diskStorage({
  destination: (req, file, cb) => {
    const dir = path.join(UPLOADS_DIR, req.params.id, 'reply');
    fs.mkdirSync(dir, { recursive: true });
    cb(null, dir);
  },
  filename: (req, file, cb) => {
    const ext = path.extname(file.originalname);
    const base = path.basename(file.originalname, ext).replace(/[^a-zA-Z0-9._-]/g, '_');
    cb(null, `${Date.now()}-${base}${ext}`);
  },
});

const replyUpload = multer({
  storage: replyStorage,
  limits: { fileSize: 5 * 1024 * 1024 * 1024 },
});

// GET /api/admin/submissions
router.get('/submissions', (req, res) => {
  res.json(getAllSubmissions());
});

// GET /api/admin/submission/:id
router.get('/submission/:id', (req, res) => {
  const sub = getSubmission(parseInt(req.params.id));
  if (!sub) return res.status(404).json({ error: 'Not found' });

  const dir = path.join(UPLOADS_DIR, req.params.id);
  let files = [];
  if (fs.existsSync(dir)) {
    files = fs
      .readdirSync(dir)
      .filter(f => {
        const fp = path.join(dir, f);
        return !fs.statSync(fp).isDirectory() && !f.startsWith('.');
      })
      .map(f => ({ name: f, size: fs.statSync(path.join(dir, f)).size }));
  }

  const replyDir = path.join(dir, 'reply');
  let replyFiles = [];
  if (fs.existsSync(replyDir)) {
    replyFiles = fs
      .readdirSync(replyDir)
      .filter(f => !f.startsWith('.'))
      .map(f => ({ name: f, size: fs.statSync(path.join(replyDir, f)).size }));
  }

  const replyItems = getReplyItems(parseInt(req.params.id));

  res.json({ ...sub, uploaded_files: files, reply_files: replyFiles, reply_items: replyItems });
});

// POST /api/admin/submission/:id/reply-upload (legacy — kept for backward compat)
router.post('/submission/:id/reply-upload', (req, res) => {
  const sub = getSubmission(parseInt(req.params.id));
  if (!sub) return res.status(404).json({ error: 'Not found' });

  replyUpload.single('video')(req, res, err => {
    if (err) return res.status(400).json({ error: err.message });
    if (!req.file) return res.status(400).json({ error: 'No file uploaded' });

    let paths = [];
    try {
      paths = JSON.parse(sub.reply_video_path || '[]');
    } catch {
      paths = [];
    }
    paths.push(req.file.filename);
    updateSubmission(parseInt(req.params.id), { reply_video_path: JSON.stringify(paths) });

    res.json({ filename: req.file.filename, size: req.file.size });
  });
});

// POST /api/admin/submission/:id/reply-item — add video (multer) OR text, with description
router.post('/submission/:id/reply-item', (req, res) => {
  const sub = getSubmission(parseInt(req.params.id));
  if (!sub) return res.status(404).json({ error: 'Not found' });

  replyUpload.single('video')(req, res, err => {
    if (err) return res.status(400).json({ error: err.message });

    const type = req.body.type || (req.file ? 'video' : 'text');
    const description = (req.body.description || '').trim();

    if (type === 'video') {
      if (!req.file) return res.status(400).json({ error: 'No video file uploaded' });
      const itemId = addReplyItem(parseInt(req.params.id), 'video', req.file.filename, description, null);
      return res.json({ id: itemId, type: 'video', filename: req.file.filename, description, size: req.file.size });
    }

    if (type === 'text') {
      const content = (req.body.content || '').trim();
      if (!content) return res.status(400).json({ error: 'Content required for text reply' });
      const itemId = addReplyItem(parseInt(req.params.id), 'text', null, description, content);
      return res.json({ id: itemId, type: 'text', description, content });
    }

    return res.status(400).json({ error: 'Invalid type' });
  });
});

// DELETE /api/admin/submission/:id/reply-item/:itemId
router.delete('/submission/:id/reply-item/:itemId', (req, res) => {
  const { id, itemId } = req.params;
  const items = getReplyItems(parseInt(id));
  const item = items.find(i => i.id === parseInt(itemId));
  if (!item) return res.status(404).json({ error: 'Item not found' });

  // Delete file from disk if video
  if (item.type === 'video' && item.filename) {
    const filePath = path.join(UPLOADS_DIR, id, 'reply', item.filename);
    if (fs.existsSync(filePath)) {
      try { fs.unlinkSync(filePath); } catch {}
    }
  }

  deleteReplyItem(parseInt(itemId));
  res.json({ success: true });
});

// PATCH /api/admin/submission/:id/reply-item/:itemId/order — move up/down
router.patch('/submission/:id/reply-item/:itemId/order', (req, res) => {
  const { direction } = req.body;
  if (direction !== 'up' && direction !== 'down') {
    return res.status(400).json({ error: 'direction must be "up" or "down"' });
  }
  moveReplyItem(parseInt(req.params.itemId), direction);
  res.json({ success: true });
});

// DELETE /api/admin/submission/:id/reply/:filename (legacy)
router.delete('/submission/:id/reply/:filename', (req, res) => {
  const { id, filename } = req.params;
  if (filename.includes('/') || filename.includes('\\') || filename.startsWith('.')) {
    return res.status(400).json({ error: 'Invalid filename' });
  }

  const filePath = path.join(UPLOADS_DIR, id, 'reply', filename);
  if (!fs.existsSync(filePath)) return res.status(404).json({ error: 'Not found' });
  fs.unlinkSync(filePath);

  const sub = getSubmission(parseInt(id));
  if (sub) {
    let paths = [];
    try {
      paths = JSON.parse(sub.reply_video_path || '[]');
    } catch {
      paths = [];
    }
    paths = paths.filter(p => p !== filename);
    updateSubmission(parseInt(id), { reply_video_path: JSON.stringify(paths) });
  }

  res.json({ success: true });
});

// POST /api/admin/submission/:id/confirm-receipt
router.post('/submission/:id/confirm-receipt', async (req, res) => {
  const id = parseInt(req.params.id);
  const sub = getSubmission(id);
  if (!sub) return res.status(404).json({ error: 'Not found' });

  updateSubmission(id, {
    status: 'in_progress',
    confirmed_at: new Date().toISOString(),
  });

  if (sub.email) {
    sendReceiptConfirmation(sub.email, sub.name || 'Rider').catch(err =>
      console.error('Receipt confirmation email error:', err)
    );
  }

  res.json({ success: true });
});

// POST /api/admin/submission/:id/feedback-sent
router.post('/submission/:id/feedback-sent', async (req, res) => {
  const id = parseInt(req.params.id);
  const sub = getSubmission(id);
  if (!sub) return res.status(404).json({ error: 'Not found' });

  updateSubmission(id, {
    status: 'feedback_sent',
    feedback_sent_at: new Date().toISOString(),
  });

  const BASE_URL =
    process.env.BASE_URL ||
    `http://localhost:${process.env.PORT || 3010}${process.env.BASE_PATH || ''}`;
  const replyUrl = `${BASE_URL}/reply/${sub.token}`;

  if (sub.email) {
    try {
      await sendFeedbackReady(sub.email, sub.name || 'Rider', replyUrl);
    } catch (err) {
      console.error('Feedback-ready email error:', err);
      return res.json({ success: true, replyUrl, emailError: err.message });
    }
  }

  res.json({ success: true, replyUrl });
});

// GET /api/admin/file/:submissionId/* — serve any uploaded file (admin only)
router.get('/file/:submissionId/*', (req, res) => {
  const { submissionId } = req.params;
  if (!/^\d+$/.test(submissionId)) return res.status(400).send('Invalid ID');

  const filepath = req.params[0];
  const fullPath = path.join(UPLOADS_DIR, submissionId, filepath);
  const safeBase = path.resolve(UPLOADS_DIR);
  if (!path.resolve(fullPath).startsWith(safeBase)) {
    return res.status(403).send('Forbidden');
  }
  if (!fs.existsSync(fullPath)) return res.status(404).send('Not found');
  res.sendFile(path.resolve(fullPath));
});

module.exports = router;
