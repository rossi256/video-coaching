-- One nudge per session per day. Without a ledger the hourly lifecycle cron
-- would mail every hour until the replay is published.
CREATE TABLE IF NOT EXISTS qa_aftercare_log (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  session_id INT      NOT NULL,
  sent_on    DATE     NOT NULL,
  sent_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_aftercare (session_id, sent_on)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
