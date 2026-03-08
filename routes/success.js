const router = require('express').Router();
const { getSubmissionBySessionId, getSubmission, updateSubmission } = require('../db');
const { stripe } = require('../stripe');
const { sendSubmissionNotification } = require('../email');

// GET /api/verify-session?session_id=xxx
router.get('/api/verify-session', async (req, res) => {
  const { session_id } = req.query;
  if (!session_id) return res.status(400).json({ error: 'Missing session_id' });

  // Dev bypass: session IDs starting with 'dev_test_' skip Stripe (DEV_BYPASS=true only)
  if (session_id.startsWith('dev_test_') && process.env.DEV_BYPASS === 'true') {
    const submission = getSubmissionBySessionId(session_id);
    if (!submission) return res.status(404).json({ error: 'Dev session not found' });
    return res.json({
      submissionId: submission.id, status: submission.status,
      name: submission.name, email: submission.email,
      age: submission.age, location: submission.location,
      ride_frequency: submission.ride_frequency, conditions: submission.conditions,
      equipment: submission.equipment, level: submission.level,
      stuck_on: submission.stuck_on, tried: submission.tried,
      success_looks_like: submission.success_looks_like, audio_file: submission.audio_file,
    });
  }

  let submission = getSubmissionBySessionId(session_id);

  if (!submission) {
    // Webhook may not have fired yet — verify directly with Stripe
    try {
      const session = await stripe.checkout.sessions.retrieve(session_id);
      if (session.payment_status !== 'paid') {
        return res.status(402).json({ error: 'Payment not completed' });
      }
      // Payment confirmed but webhook not yet processed
      return res.status(202).json({
        status: 'processing',
        message: 'Payment confirmed, please wait a moment and refresh.',
      });
    } catch {
      return res.status(400).json({ error: 'Invalid session ID' });
    }
  }

  res.json({
    submissionId: submission.id, status: submission.status,
    name: submission.name, email: submission.email,
    age: submission.age, location: submission.location,
    ride_frequency: submission.ride_frequency, conditions: submission.conditions,
    equipment: submission.equipment, level: submission.level,
    stuck_on: submission.stuck_on, tried: submission.tried,
    success_looks_like: submission.success_looks_like, audio_file: submission.audio_file,
  });
});

// POST /api/submit/:submissionId — save rider profile + mark submitted
router.post('/api/submit/:submissionId', async (req, res) => {
  const id = parseInt(req.params.submissionId);
  if (isNaN(id)) return res.status(400).json({ error: 'Invalid ID' });

  const sub = getSubmission(id);
  if (!sub) return res.status(404).json({ error: 'Submission not found' });

  const {
    name,
    email,
    age,
    location,
    ride_frequency,
    conditions,
    equipment,
    level,
    stuck_on,
    tried,
    success_looks_like,
    audio_file,
  } = req.body;

  updateSubmission(id, {
    name: name || sub.name,
    email: email || sub.email,
    age: age ? parseInt(age) : null,
    location: location || null,
    ride_frequency: ride_frequency || null,
    conditions: conditions || null,
    equipment: equipment || null,
    level: level || null,
    stuck_on: stuck_on || null,
    tried: tried || null,
    success_looks_like: success_looks_like || null,
    audio_file: audio_file || sub.audio_file,
    status: 'submitted',
    submitted_at: new Date().toISOString(),
  });

  const finalName = name || sub.name || 'Unknown';
  const finalEmail = email || sub.email || '';
  sendSubmissionNotification(finalName, finalEmail, id).catch(err =>
    console.error('Submission notification email error:', err)
  );

  res.json({ success: true });
});

// PATCH /api/submission/:id — auto-save profile fields (does NOT change status)
router.patch('/api/submission/:id', (req, res) => {
  const id = parseInt(req.params.id);
  if (isNaN(id)) return res.status(400).json({ error: 'Invalid ID' });

  const allowed = ['name', 'email', 'age', 'location', 'ride_frequency', 'conditions',
                   'equipment', 'level', 'stuck_on', 'tried', 'success_looks_like'];
  const fields = {};
  for (const key of allowed) {
    if (req.body[key] !== undefined) fields[key] = req.body[key];
  }
  if (Object.keys(fields).length === 0) return res.json({ ok: true });

  updateSubmission(id, fields);
  res.json({ ok: true });
});

module.exports = router;
