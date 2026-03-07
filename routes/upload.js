const router = require('express').Router();
const multer = require('multer');
const path = require('path');
const fs = require('fs');
const { getSubmission } = require('../db');

const UPLOADS_DIR = path.join(__dirname, '..', 'uploads');

// Sanitize filename: keep alphanumeric, dots, dashes, underscores
function safeName(original) {
  const ext = path.extname(original);
  const base = path.basename(original, ext).replace(/[^a-zA-Z0-9._-]/g, '_');
  return `${Date.now()}-${base}${ext}`;
}

// Storage: rider videos
const videoStorage = multer.diskStorage({
  destination: (req, file, cb) => {
    const dir = path.join(UPLOADS_DIR, req.params.submissionId);
    fs.mkdirSync(dir, { recursive: true });
    cb(null, dir);
  },
  filename: (req, file, cb) => cb(null, safeName(file.originalname)),
});

// Storage: audio recordings
const audioStorage = multer.diskStorage({
  destination: (req, file, cb) => {
    const dir = path.join(UPLOADS_DIR, req.params.submissionId);
    fs.mkdirSync(dir, { recursive: true });
    cb(null, dir);
  },
  filename: (req, file, cb) => {
    const ext = path.extname(file.originalname) || '.webm';
    cb(null, `audio${ext}`);
  },
});

const videoUpload = multer({
  storage: videoStorage,
  limits: { fileSize: 5 * 1024 * 1024 * 1024 }, // 5 GB
  fileFilter: (req, file, cb) => {
    if (file.mimetype.startsWith('video/') || file.mimetype === 'application/octet-stream') {
      cb(null, true);
    } else {
      cb(new Error('Only video files are allowed'));
    }
  },
});

const audioUpload = multer({
  storage: audioStorage,
  limits: { fileSize: 500 * 1024 * 1024 }, // 500 MB
});

// Middleware: validate submission ID
function validateSubmission(req, res, next) {
  const id = parseInt(req.params.submissionId);
  if (isNaN(id)) return res.status(400).json({ error: 'Invalid submission ID' });
  const sub = getSubmission(id);
  if (!sub) return res.status(404).json({ error: 'Submission not found' });
  req.submission = sub;
  next();
}

// POST /upload/:submissionId — upload one video (called multiple times for multiple files)
router.post('/upload/:submissionId', validateSubmission, (req, res) => {
  videoUpload.single('video')(req, res, err => {
    if (err) return res.status(400).json({ error: err.message });
    if (!req.file) return res.status(400).json({ error: 'No file uploaded' });
    res.json({
      filename: req.file.filename,
      originalName: req.file.originalname,
      size: req.file.size,
    });
  });
});

// DELETE /upload/:submissionId/:filename — delete a rider video
router.delete('/upload/:submissionId/:filename', validateSubmission, (req, res) => {
  const { submissionId, filename } = req.params;

  // Security: no path traversal
  if (filename.includes('/') || filename.includes('\\') || filename.startsWith('.')) {
    return res.status(400).json({ error: 'Invalid filename' });
  }

  const filePath = path.join(UPLOADS_DIR, submissionId, filename);
  const safeBase = path.resolve(UPLOADS_DIR, submissionId);
  if (!path.resolve(filePath).startsWith(safeBase)) {
    return res.status(403).json({ error: 'Forbidden' });
  }

  if (!fs.existsSync(filePath)) return res.status(404).json({ error: 'File not found' });
  fs.unlinkSync(filePath);
  res.json({ success: true });
});

// POST /upload/:submissionId/audio — upload audio recording
router.post('/upload/:submissionId/audio', validateSubmission, (req, res) => {
  audioUpload.single('audio')(req, res, err => {
    if (err) return res.status(400).json({ error: err.message });
    if (!req.file) return res.status(400).json({ error: 'No audio file uploaded' });
    res.json({ filename: req.file.filename, size: req.file.size });
  });
});

module.exports = router;
