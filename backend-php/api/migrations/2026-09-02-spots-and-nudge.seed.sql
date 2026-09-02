-- WingCoach — 2026-09-02 — PRODUCTION data seed (run once, after the schema migration)
--
-- Rows are matched on stripe_session_id, never on id, so this cannot touch the
-- wrong record. Every row is listed explicitly with the reason for its flags.
--
-- counts_toward_spots = 0  -> row does not consume one of the 10 founding spots
-- nudge_opt_out       = 1  -> cron/submission-nudge.php will never email this person

-- #1 + #2 — Michi's own end-to-end test purchases (real 49 EUR charges, not customers).
UPDATE submissions SET counts_toward_spots = 0, nudge_opt_out = 1
 WHERE stripe_session_id = 'cs_live_a151DZJBDNvxfF46RA3yEaUjadrz1CXqQgq4fKdYHF9ddUQakNFXQa9Kc3';
UPDATE submissions SET counts_toward_spots = 0, nudge_opt_out = 1
 WHERE stripe_session_id = 'cs_live_a1loyx7Vuj8h9tQqAPNjACwfrFDXpLTh0sEfYVq2FXKyvKtYfT3ttDWlL7';

-- #3 — Peter Najim: a 157 EUR "Tricktionary Invoice" Stripe payment with empty
-- metadata that predates the metadata.product guard in webhook.php and was
-- wrongly recorded as a WingCoach submission. He never bought video coaching.
-- Do not count it as a spot and never contact him about it.
UPDATE submissions SET counts_toward_spots = 0, nudge_opt_out = 1
 WHERE stripe_session_id = 'cs_live_a17S2HiyIphfFDCzCrpOghSZHxSVVR4fWgHtZvomS5Wyi2dVBm9yYpH1kW';

-- #4 — Gino Borland: the first real founding customer. Counts as a spot.
-- Opted out of the automated nudge on purpose: Michi follows up personally.
-- Set nudge_opt_out = 0 to hand him over to the cron.
UPDATE submissions SET counts_toward_spots = 1, nudge_opt_out = 1
 WHERE stripe_session_id = 'cs_live_a1qTIPKaGb3SvrLkqDQFPhNwBtmMRfcOceI99QnU4aNxqAK1pL3kAtFUef';
