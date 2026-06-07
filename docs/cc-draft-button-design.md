# Design — "CC create draft email reply" button

**Date:** 2026-06-07
**Status:** Proposed (awaiting Michi's review before implementation)
**Author:** Claude Code session

## Goal

In the coaching admin (`coaching.tricktionary.com/video-coaching/admin`), each
event inquiry gets a **"CC create draft email reply"** button. Clicking it makes
a Claude Code session draft a personalised reply in Michi's voice and save it to
the **info@tricktionary.com → Drafts** folder, ready for Michi to review and send.
Nothing is ever auto-sent.

This generalises the one-off draft already produced manually for inquiry #11
(Erika). The button and the manual run share one drafting code path.

## Why pull-based

Two machines are involved:

- **coaching-server** (Virtualmin/Apache + PHP + MySQL) — serves the admin and
  holds `event_inquiries`.
- **OpenClaw machine** — where Claude Code (`scripts/claude-task.sh`), the
  voiceprint (`kb/VOICEPRINT.md`), and the info@ mailbox (himalaya) live. It
  already reaches the coaching DB over `ssh coaching-server`.

The OpenClaw machine cannot accept an inbound trigger cheaply/safely, but it
already *pulls* from coaching-server. So the button only raises a flag in the DB;
a cron poller on the OpenClaw machine does the work. No new inbound surface, no
secrets on the web server, reuses the existing SSH trust already used here.

## Components

### 1. Database (coaching-server, MySQL)

Add two columns to `event_inquiries`:

```sql
ALTER TABLE event_inquiries
  ADD COLUMN draft_status ENUM('requested','drafting','drafted','error') NULL DEFAULT NULL,
  ADD COLUMN draft_requested_at DATETIME NULL DEFAULT NULL;
```

State machine: `NULL` → `requested` (button) → `drafting` (poller claims it) →
`drafted` (CC saved the draft) | `error` (CC failed; safe to re-request).

### 2. Admin API (`backend-php/api/admin.php`)

New action, mirroring the existing `event-inquiry-respond` pattern and behind the
same `requireAdmin()` gate:

```
POST  ?_action=event-inquiry-draft-request&id=:id
  → UPDATE event_inquiries SET draft_status='requested', draft_requested_at=NOW()
    WHERE id=:id AND (draft_status IS NULL OR draft_status='error' OR draft_status='drafted')
  → { success: true, draft_status: 'requested' }
```

The existing `event-inquiries` list action already returns `SELECT *`, so the new
columns flow to the UI automatically — no change needed there.

### 3. Admin UI (`website/static/admin.html`)

On each event-inquiry row/detail, add a **"CC create draft email reply"** button.
State driven by `draft_status`:

| draft_status | Button label                     | Enabled |
|--------------|----------------------------------|---------|
| null / error | CC create draft email reply      | yes     |
| requested    | Draft requested…                 | no      |
| drafting     | Drafting…                        | no      |
| drafted      | ✓ Draft ready (re-request)       | yes     |

On click → POST the new action, then optimistically set `requested`. A light
poll/refresh of the list (or manual refresh) surfaces `drafted`. No live
websocket needed.

### 4. Poller (OpenClaw machine)

`skills/coaching-draft/poll.sh`, run by cron **every 5 minutes**:

1. `ssh coaching-server` → query the deployed config + MySQL for rows with
   `draft_status='requested'` (id, name, email, all inquiry fields).
2. For each row, atomically claim it:
   `UPDATE … SET draft_status='drafting' WHERE id=:id AND draft_status='requested'`
   (guards against the next tick double-processing).
3. Dispatch one drafting job via `scripts/claude-task.sh start coaching-draft-<id> <prompt>`.
4. The CC job (see §5) sets `drafted` or `error` itself at the end.

Cron line (registered via the workspace's normal cron management):
`*/5 * * * * … skills/coaching-draft/poll.sh >> .cache/coaching-draft/poll.log 2>&1`

### 5. The drafting job (CC task prompt)

A prompt template (`skills/coaching-draft/draft-prompt.tmpl`) instructs the CC
session to, for a given inquiry id:

1. Read the inquiry from the live DB (name, email, event, level, message, quiz
   answers, created_at).
2. Load `kb/VOICEPRINT.md` and current offerings (events-site `lake-garda/`,
   `tarifa/`, coaching pages) for accurate references.
3. Write a warm, helpful reply in Michi's voice, **auto-detecting language**
   (English vs German) from the inquiry's content/name/region cues. No markdown.
4. Apologise for a late reply **only if** the inquiry is genuinely old
   (e.g. created_at more than ~5 days ago).
5. Reference the offerings that actually fit the person; point to
   events.tricktionary.com; offer a call. Never invent dates not on the site.
6. Save as a plain-text draft to `info@tricktionary.com → Drafts` via
   `himalaya message save -a info@tricktionary.com -f Drafts`.
7. `ssh coaching-server` → set `draft_status='drafted'`.
8. On any failure, set `draft_status='error'` and log.
9. Notify Michi (Telegram) that a draft for `<name>` is ready in info@ Drafts.

The reply for Erika (#11) is the worked reference example of the desired output.

## Out of scope (YAGNI)

- Auto-sending. Always draft-only.
- Editing drafts from the admin. Michi edits/sends in his normal mail client.
- Applying this to `submissions` / `private_inquiries` (could reuse later; not now).
- Near-instant triggering. 5-min poll is enough.

## Open / confirm at build time

- Exact insertion point + render code in `admin.html` (SPA — locate the
  event-inquiry render block).
- Whether himalaya/claude auth on the OpenClaw machine is non-interactive under
  cron (claude-task.sh already documents the cron PATH fix; verify CC auth).

## Manual fallback

Independent of the button, any inquiry can still be drafted on demand by running
the same drafting job by id from a CC session — which is exactly how #11 was done.
