-- The registrant reminder log keys on signup_id. A last-call goes to people who
-- have no signup row at all, so it needs its own ledger or a cron re-run would
-- mail the whole list again.
CREATE TABLE IF NOT EXISTS qa_lastcall_log (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  session_id INT          NOT NULL,
  email      VARCHAR(255) NOT NULL,
  sent_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_lastcall (session_id, email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
