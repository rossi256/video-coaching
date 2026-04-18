# Video Coaching / Coaching Hub

Full coaching platform: coaching hub landing page, video coaching app (Stripe payments, video uploads, admin), Q&A sessions system with typed sessions (meetup/info-session/kickoff/webinar/summit), private coaching page.

## Development & Deployment Setup

- **Local:** `~/.openclaw/workspace/projects/video-coaching/`
- **Live:** https://coaching.tricktionary.com/
- **Staging:** https://ari.tricktionary.com/projects/video-coaching/
- **Server:** `/home/coaching/public_html/` via SSH `coaching-server`
- **Staging server:** `/home/ari/public_html/projects/video-coaching/` via SSH `ari-server`
- **Deploy:** `bash deploy-coaching.sh [hub|backend|website|private|assets|all]`
- **GitHub:** rossi256/video-coaching (GitHub Actions enabled)

## Detailed Spec

See `CLAUDE_CODE_PROMPT.md` for the full project specification, feature requirements, and architecture details.

## Notes

- Production: coaching.tricktionary.com (SSH: coaching-server, /home/coaching/public_html/) — PHP backend only
- Staging: ari.tricktionary.com (SSH: ari-server) — Node.js backend at /home/ari/wingcoach-admin (PM2: wingcoach, port 3010)
- Deploy prod: bash deploy-coaching.sh [hub|backend|website|all] | Deploy staging Node.js: bash deploy-nodejs.sh | Deploy staging PHP: bash deploy-staging.sh all
- Production DB: coaching (MariaDB, user: coaching) | Staging DB: coaching.db (SQLite, local to Node.js)
- Stripe: live keys on both envs, separate webhook secrets. Staging has DEV_BYPASS=true (skips Stripe, simulates payment)

## Project Status Tracking

When you complete significant work (new feature, major fix, architecture change, deployment), update `PROJECT-STATUS.json` in this project root:

```json
{
  "lastDev": "One-line summary of what was done",
  "lastDevDate": "YYYY-MM-DD",
  "phase": "planning|building|active|maintaining|paused|complete",
  "milestone": "Current milestone or null",
  "blockedBy": "What's blocking or null"
}
```

- Create the file if it doesn't exist
- Keep `lastDev` under 120 characters
- Update on meaningful changes, not every small edit
- `phase` = development lifecycle (not deployment status)

## Completion Protocol

When you finish a significant piece of work on this project:

1. **Commit & push** your changes
2. **Update PROJECT-STATUS.json** in this project root (create if missing):
   ```json
   {"lastDev": "summary of work done", "lastDevDate": "YYYY-MM-DD", "phase": "active"}
   ```
3. **Update Forge** so the project dashboard stays current:
   ```bash
   # Post a status note (also updates lastMaintenance automatically)
   curl -s -X POST https://forge.tricktionary.com/api/projects/video-coaching/notes \
     -H "Content-Type: application/json" \
     -H "Authorization: Bearer $FORGE_API_KEY" \
     -d '{"text": "Completed: [brief summary of what was done]"}'
   ```
   ```bash
   # Mark todos as done (if applicable)
   curl -s -X PATCH https://forge.tricktionary.com/api/projects/video-coaching/todos \
     -H "Content-Type: application/json" \
     -H "Authorization: Bearer $FORGE_API_KEY" \
     -d '{"todoId": "TODO_ID", "status": "done"}'
   ```
   Or use the helper: `~/.openclaw/workspace/scripts/forge-sync.sh video-coaching "Completed: summary"`

This keeps the Forge dashboard, PROJECT-STATUS.json, and git history in sync.
