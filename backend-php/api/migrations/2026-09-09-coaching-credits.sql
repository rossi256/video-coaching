-- Coaching credits: the 1:1 call and the 3-session pack.
--
-- Deliberately separate from `submissions`, which serves the founding WingCoach
-- round and has a live customer in it. That flow keeps working untouched.
--
-- A purchase grants N credits. A credit is spent by inserting a redemption row,
-- never by incrementing a counter, so the balance is always derivable and a
-- double-submit cannot quietly hand out a free session.

CREATE TABLE IF NOT EXISTS coaching_credits (
  id                INT AUTO_INCREMENT PRIMARY KEY,
  token             VARCHAR(64)  NOT NULL,
  stripe_session_id VARCHAR(255) NULL,
  product           VARCHAR(32)  NOT NULL,
  email             VARCHAR(255) NULL,
  name              VARCHAR(255) NULL,
  credits_total     INT          NOT NULL DEFAULT 1,
  amount_cents      INT          NULL,
  expires_at        DATE         NULL,
  created_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  notes             TEXT         NULL,
  UNIQUE KEY uq_credit_token (token),
  UNIQUE KEY uq_credit_stripe (stripe_session_id),
  KEY idx_credit_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS credit_redemptions (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  credit_id      INT          NOT NULL,
  kind           VARCHAR(16)  NOT NULL,          -- review | call
  message        TEXT         NULL,              -- what they are working on
  clip_url       TEXT         NULL,
  status         VARCHAR(24)  NOT NULL DEFAULT 'requested',
  scheduled_at   DATETIME     NULL,
  zoom_join_url  TEXT         NULL,
  reply_url      TEXT         NULL,              -- the video reply, for reviews
  delivered_at   DATETIME     NULL,
  created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_redemption_credit (credit_id),
  KEY idx_redemption_status (status),
  CONSTRAINT fk_redemption_credit FOREIGN KEY (credit_id)
    REFERENCES coaching_credits (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
