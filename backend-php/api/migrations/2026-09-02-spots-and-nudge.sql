-- WingCoach — 2026-09-02
-- Adds: real spot accounting (counts_toward_spots) + stalled-submission nudge tracking.
--
-- Apply:  /usr/bin/php8.4 -r 'require "api/config.php"; getDb()->exec(file_get_contents("api/migrations/2026-09-02-spots-and-nudge.sql"));'
-- or:     mysql -u coaching -p coaching < 2026-09-02-spots-and-nudge.sql
--
-- Schema only. The data seeding (which rows count, who is opted out of nudges)
-- lives in the companion file 2026-09-02-spots-and-nudge.seed.sql so it is
-- reviewed and applied deliberately, once, per environment.

ALTER TABLE submissions
  ADD COLUMN counts_toward_spots TINYINT(1) NOT NULL DEFAULT 1,
  ADD COLUMN nudge_opt_out       TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN nudge_count         INT        NOT NULL DEFAULT 0,
  ADD COLUMN nudge_last_at       DATETIME   NULL;
