const router = require('express').Router();
const { createCheckoutSession } = require('../stripe');
const { getSpots } = require('../db');

router.post('/create-checkout-session', async (req, res) => {
  if (!process.env.STRIPE_SECRET_KEY) {
    console.error('STRIPE_SECRET_KEY is not set');
    return res.status(500).json({ error: 'Payment not configured. Please contact us.' });
  }

  const BASE_URL =
    process.env.BASE_URL ||
    `http://localhost:${process.env.PORT || 3010}${process.env.BASE_PATH || ''}`;

  const { total, taken } = getSpots();
  const remaining = total - taken;

  if (remaining <= 0) {
    return res.status(400).json({ error: 'No spots remaining' });
  }

  const email = (req.body?.email || '').trim() || null;

  try {
    const session = await createCheckoutSession(
      `${BASE_URL}/success?session_id={CHECKOUT_SESSION_ID}`,
      `${BASE_URL}/`,
      remaining,
      email,
    );
    res.json({ url: session.url });
  } catch (err) {
    console.error('Stripe error:', err.message);
    res.status(500).json({ error: 'Failed to create checkout session' });
  }
});

module.exports = router;
