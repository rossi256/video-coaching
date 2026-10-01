-- Veto window: the replay is built automatically and publishes itself unless
-- Michi stops it. publish_after is the moment it goes; publish_held records a
-- veto so nothing retries it.
ALTER TABLE qa_sessions ADD COLUMN publish_after DATETIME NULL;
ALTER TABLE qa_sessions ADD COLUMN publish_held  TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE qa_sessions ADD COLUMN autobuilt_at  DATETIME NULL;
