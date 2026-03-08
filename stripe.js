const Stripe = require('stripe');

const stripe = new Stripe(process.env.STRIPE_SECRET_KEY);

async function createCheckoutSession(successUrl, cancelUrl, spotsAtPurchase, customerEmail) {
  return stripe.checkout.sessions.create({
    mode: 'payment',
    customer_email: customerEmail || undefined,
    line_items: [
      {
        price_data: {
          currency: 'eur',
          product_data: {
            name: 'WingCoach — Founding 10 Spot',
            description:
              'Personal video coaching from Michi Rossmeier (Tricktionary). Limited to 10 founding clients at €39.',
          },
          unit_amount: 4900,
        },
        quantity: 1,
      },
    ],
    success_url: successUrl,
    cancel_url: cancelUrl,
    metadata: {
      spots_at_purchase: String(spotsAtPurchase),
    },
  });
}

function verifyWebhookSignature(payload, signature, secret) {
  return stripe.webhooks.constructEvent(payload, signature, secret);
}

module.exports = { stripe, createCheckoutSession, verifyWebhookSignature };
