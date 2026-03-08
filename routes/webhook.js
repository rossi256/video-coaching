const router = require('express').Router();
const { verifyWebhookSignature } = require('../stripe');
const {
  createSubmission,
  decrementSpots,
  getSubmissionBySessionId,
  updateSubmission,
} = require('../db');
const { sendAdminNotification, sendUploadLink } = require('../email');

// Route is mounted at BASE_PATH/webhook/stripe with express.raw middleware
router.post('/', (req, res) => {
  const sig = req.headers['stripe-signature'];

  let event;
  try {
    event = verifyWebhookSignature(req.body, sig, process.env.STRIPE_WEBHOOK_SECRET);
  } catch (err) {
    console.error('Webhook signature verification failed:', err.message);
    return res.status(400).send(`Webhook Error: ${err.message}`);
  }

  if (event.type === 'checkout.session.completed') {
    const session = event.data.object;

    // Idempotency: skip if already processed
    const existing = getSubmissionBySessionId(session.id);
    if (!existing) {
      const spotsAtPurchase = parseInt(session.metadata?.spots_at_purchase || '10');
      const submissionId = createSubmission(session.id, spotsAtPurchase);
      decrementSpots();

      const name = session.customer_details?.name || '';
      const email = session.customer_details?.email || '';

      if (name || email) {
        updateSubmission(submissionId, {
          stripe_payment_intent: session.payment_intent,
          name,
          email,
        });
      } else {
        updateSubmission(submissionId, {
          stripe_payment_intent: session.payment_intent,
        });
      }

      sendAdminNotification(name || 'Unknown', email).catch(err =>
        console.error('Admin notification email error:', err)
      );

      if (email) {
        const uploadUrl = `${process.env.BASE_URL}/success?session_id=${session.id}`;
        sendUploadLink(email, name, uploadUrl).catch(err =>
          console.error('Upload link email error:', err)
        );
      }

      console.log(`New submission created: #${submissionId} for ${email}`);
    }
  }

  res.json({ received: true });
});

module.exports = router;
