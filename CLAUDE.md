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

## Ecosystem context

**Tier 3 Core** of the KPoI ladder, status: **migrating (dual-surface)**. The **video coaching** feature (paid video review + uploads) is being absorbed into the wingfoil Tricktionary mobile app (Rails 8 backend + Vue/Capacitor frontend in `tricktionary-apps/`); Mac Claude session owns that execution per `wingcoach_absorption_decision.md` memory.

**Decision 2026-06-18 (Michi):** the PHP web backend is NOT being fully retired — it **stays as a real management surface** for **events & Q&A** (create/schedule Q&A calls, meeting links, registrant lists, reminder funnels, event inquiries & quotes). Goal is to manage in **both** places: video coaching mostly in the mobile app, events/Q&A management on the computer/web backend. So new PHP work on the **events/Q&A funnel surface is in scope**; avoid new PHP only on the **video-coaching/payment core** that is moving to mobile. Coordinate with the Mac session on shared schema. Funnel pages stay (events, Q&A, private-inquiry, **and the `/api/waitlist.php` endpoint that the the-lineup hub forms post to**).

Master plan: `~/.openclaw/workspace/kb/strategy/brand-ecosystem-2026-04.md` — single source of truth for the Michi Rossmeier KPoI ladder, brand architecture, and the 6-tier ATM ladder.

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
