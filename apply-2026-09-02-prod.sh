#!/bin/bash
# One-time production rollout for the 2026-09-02 fixes.
# Safe to re-run: every step checks its own state first.
#
#   bash apply-2026-09-02-prod.sh check    # read-only: show what would happen
#   bash apply-2026-09-02-prod.sh apply    # do it
set -euo pipefail

SERVER="coaching-server"
ROOT="/home/coaching/public_html/video-coaching"
PHP="/usr/bin/php8.4"
MODE="${1:-check}"
PROJECT_DIR="$(cd "$(dirname "$0")" && pwd)"

step() { echo; echo "=== $* ==="; }

step "0. Current production state"
ssh "$SERVER" "$PHP -r '
require \"$ROOT/api/config.php\"; \$db=getDb();
\$cols=array_column(\$db->query(\"SHOW COLUMNS FROM submissions\")->fetchAll(),\"Field\");
echo \"migration applied: \".(in_array(\"counts_toward_spots\",\$cols)?\"yes\":\"no\").PHP_EOL;
echo \"rows: \".\$db->query(\"SELECT COUNT(*) FROM submissions\")->fetchColumn().PHP_EOL;
'"
echo "spots API: $(curl -s https://coaching.tricktionary.com/video-coaching/api/spots)"
echo "nudge cron installed: $(ssh "$SERVER" "crontab -l 2>/dev/null | grep -c submission-nudge || true")"

if [ "$MODE" != "apply" ]; then
  echo; echo "check-only. Re-run with: bash $(basename "$0") apply"; exit 0
fi

step "1. Back up the submissions table"
ssh "$SERVER" "$PHP -r '
require \"$ROOT/api/config.php\";
file_put_contents(\"/home/coaching/wc-submissions-backup-2026-09-02.json\",
  json_encode(getDb()->query(\"SELECT * FROM submissions ORDER BY id\")->fetchAll(), JSON_PRETTY_PRINT));
echo \"backed up to /home/coaching/wc-submissions-backup-2026-09-02.json\".PHP_EOL;'"

step "2. Deploy the PHP backend"
bash "$PROJECT_DIR/deploy-coaching.sh" backend

step "3. Apply the schema migration (idempotent)"
ssh "$SERVER" "$PHP -r '
require \"$ROOT/api/config.php\"; \$db=getDb();
\$cols=array_column(\$db->query(\"SHOW COLUMNS FROM submissions\")->fetchAll(),\"Field\");
if (in_array(\"counts_toward_spots\",\$cols)) { echo \"already applied, skipping\".PHP_EOL; exit; }
\$db->exec(file_get_contents(\"$ROOT/api/migrations/2026-09-02-spots-and-nudge.sql\"));
echo \"migration applied\".PHP_EOL;'"

step "4. Seed the flags (matched on stripe_session_id, never on id)"
ssh "$SERVER" "$PHP -r '
require \"$ROOT/api/config.php\";
getDb()->exec(file_get_contents(\"$ROOT/api/migrations/2026-09-02-spots-and-nudge.seed.sql\"));
echo \"seed applied\".PHP_EOL;'"

step "5. Verify: flags, spot count, and that nothing else moved"
ssh "$SERVER" "$PHP -r '
require \"$ROOT/api/config.php\";
foreach (getDb()->query(\"SELECT id,name,counts_toward_spots,nudge_opt_out FROM submissions ORDER BY id\")->fetchAll() as \$r)
  echo sprintf(\"  #%d %-20s counts=%d nudge_opt_out=%d\", \$r[\"id\"], \$r[\"name\"], \$r[\"counts_toward_spots\"], \$r[\"nudge_opt_out\"]).PHP_EOL;'"
echo "spots API now: $(curl -s https://coaching.tricktionary.com/video-coaching/api/spots)"

step "6. Nudge cron: dry-run FIRST (must send nothing)"
ssh "$SERVER" "$PHP $ROOT/api/cron/submission-nudge.php --dry-run"

step "7. Install the nudge cron (daily 10:00), if absent"
ssh "$SERVER" "
  if crontab -l 2>/dev/null | grep -q submission-nudge; then
    echo 'already installed'
  else
    (crontab -l 2>/dev/null; echo '0 10 * * * $PHP $ROOT/api/cron/submission-nudge.php >> /home/coaching/logs/wingcoach-nudge.log 2>&1') | crontab -
    echo 'installed'
  fi
  crontab -l | grep submission-nudge"

step "Done"
echo "Everything existing is seeded nudge_opt_out=1, so the cron mails nobody until you"
echo "clear the flag on someone. To hand Gino to the cron later:"
echo "  ssh $SERVER \"$PHP -r 'require \\\"$ROOT/api/config.php\\\"; getDb()->exec(\\\"UPDATE submissions SET nudge_opt_out=0 WHERE id=4\\\");'\""
