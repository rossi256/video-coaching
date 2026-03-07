const router = require('express').Router();
const { createCheckoutSession } = require('../stripe');
const { getSpots } = require('../db');

router.post('/create-checkout-session', async (req, res) => {
  const BASE_URL =
    process.env.BASE_URL ||
    `http://localhost:${process.env.PORT || 3010}${process.env.BASE_PATH || ''}`;

  const { total, taken } = getSpots();
  const remaining = total - taken;

  if (remaining <= 0) {
    return res.status(400).json({ error: 'No spots remaining' });
  }

  try {
    const session = await createCheckoutSession(
      `${BASE_URL}/success?session_id={CHECKOUT_SESSION_ID}`,
      `${BASE_URL}/`,
      remaining
    );
    res.json({ url: session.url });
  } catch (err) {
    console.error('Stripe error:', err);
    res.status(500).json({ error: 'Failed to create checkout session' });
  }
});

module.exports = router;
