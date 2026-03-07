const router = require('express').Router();
const multer = require('multer');
const path = require('path');
const fs = require('fs');
const { getAllSubmissions, getSubmission, updateSubmission } = require('../db');
const { sendFeedbackReady } = require('../email');

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

  res.json({ ...sub, uploaded_files: files, reply_files: replyFiles });
});

// POST /api/admin/submission/:id/reply-upload
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

// DELETE /api/admin/submission/:id/reply/:filename
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
