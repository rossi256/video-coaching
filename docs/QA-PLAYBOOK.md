# Live Q&A - System Playbook

The monthly live Q&A (first Tuesday, 19:00 CEST) runs on a built-out system, not on ad-hoc AI work.
This is the single source of truth for what runs itself, what needs a human, and where it extends.

## The automatic lifecycle (zero-touch)

| When | What happens | Machinery |
|---|---|---|
| T-7d | **Invite email to the whole Q&A audience** (everyone who ever signed up or unlocked a replay, minus already-registered, minus unsubscribed) | `cron/qa-lifecycle.php` hourly, stamped in `invite_email_sent_at` |
| T-7d/24h/1h | Reminder chain to registrants (join link + .ics) | `cron/qa-reminders.php`, `qa_reminder_log` |
| Signup anytime | Confirmation + calendar invite; duplicates get a re-send; signup joins `qa_audience` | `qa-session.php` |
| 19:00 | **"We're LIVE" email** to registrants; **site flips to LIVE state** (red banner, Join-live-now fast form, Zoom link on success screen + in API) | reminder cron `live` offset; `is_live` window in API + `main.js` |
| ~20:20 | Session auto-flips to `past`; next session becomes the signup target | reminders cron housekeeping |
| Replay ready | **Replay email to that session's signups**, exactly once | lifecycle cron, triggers on `replay_url` being set, stamped `replay_email_sent_at` |
| Always | Replay pages gate new visitors -> email -> `qa_audience` | replay page + `event-inquiry.php` hook |

Cross-sell: invite + replay emails append the **offers block** from `api/qa-offers.json`
(camps/coaching/peak-performance links). Edit that file to change what every lifecycle email promotes.

## Per-session human steps (the whole checklist)

1. **Before (any time):** check the session row exists (created through Dec already; create next year's in the admin).
2. **Promo:** IG post/story from the campaign-cockpit pattern; comment-keyword funnels are standing. WhatsApp = Status only.
3. **During:** start Zoom (recording is automatic), host with the cockpit prep list.
4. **After (one manual block, ~20 min or delegate to ARI):**
   a. Download recording from Zoom (share link; or API once `cloud_recording:read` scope is added - then this automates too)
   b. Upload as `static/replay/qa-YYYY-MM-DD.mp4`
   c. Create replay page: copy `events-site/live-qa/replay/2026-08/` to `/YYYY-MM/`, adjust title/chapters/video URL; add card to `replay/index.html`
   d. Set `replay_url` on the session (admin or SQL) -> **the replay email then sends itself within the hour**
5. Refresh `qa-offers.json` when camps/offers change.

## Data model (community seed)

- `qa_sessions` - lifecycle state machine (upcoming -> live window -> past) + email stamps
- `qa_signups` - per-session registrations incl. interests + source
- `qa_audience` - **the asset**: unified people table (email, name, first_source signup/replay, activity, unsubscribed). The wing app / community feature imports THIS. Unsubscribes: reply-based for now -> set `unsubscribed=1`.

## Extension points (planned, not built)

- **Community feature (wing app, this month):** import `qa_audience`; sessions + replays as content objects; Frank's forum-by-move-categories idea (see Q&A #1 after-analysis).
- **Zoom scope** `cloud_recording:read` -> full auto replay pipeline.
- **More call formats** (instructor calls a la Carl, camp pre-briefings): create a session row with another `type` - whole machinery applies unchanged.
- **Forge**: current touchpoints are notes/todos only; deeper backbone optional later.

## Ops notes

- All lifecycle sends are stamped -> re-runs are safe; `--dry` flag previews.
- Test emails are excluded from audience backfill; new tests should use +tags and be cleaned.
- Logs: `/home/coaching/qa-lifecycle.log`, `/home/coaching/qa-reminders.log`.
