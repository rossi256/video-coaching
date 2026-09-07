# Live Q&A - System Playbook

The monthly live Q&A (first Tuesday, 19:00 CEST) runs on a built-out system.
This is the single source of truth for what runs itself, what needs a human,
and the exact command for each step.

**Read this before touching anything Q&A related.** If something here is wrong,
fix this file in the same change.

---

## The five phases at a glance

| Phase | When | Runs itself | Needs a human |
|---|---|---|---|
| 1. Prepare | any time | session rows exist through Dec 2026 | check `qa-offers.json` |
| 2. Promote | T-7 to T-0 | invite, all reminders, live email | Instagram post, WhatsApp, newsletter send |
| 3. Run | on the night | recording, page flips to live | host it, drive the companion |
| 4. Post-process | T+1 | nothing until `replay_url` is set | pull, build, publish, then it emails itself |
| 5. Repurpose | T+1 onward | nothing yet | Opus Clip, blog, newsletter |

---

## Phase 1: Prepare

Sessions are created through December 2026. Create next year's in the admin:
`coaching.tricktionary.com/video-coaching/admin` → Q&A Sessions.

**The one thing that rots:** `backend-php/api/qa-offers.json`. That file is the
cross-sell block appended to every invite and every replay email. On 24 August
it was still promoting a Lake Garda camp that had finished ten days earlier, and
it went to 33 people. **Check it before every session.**

Also rebuild the campaign pack (see Phase 2) so the promo images carry the
right date. Nothing else in the system reads it, so a stale pack fails quietly.

Seat cap is 100 (`qa_sessions.max_participants`), matching what the Zoom room
holds. It was 50 until 30 August, which was a silent throttle: `spots_remaining`
is never displayed, so it gave no scarcity effect and only blocked signups.

---

## Phase 2: Promote

### Runs itself

| When | What |
|---|---|
| T-7d | invite email to the whole `qa_audience`, minus already-registered, minus unsubscribed |
| T-7d / 24h / 1h | reminder chain to registrants, join link and .ics |
| signup | confirmation with the Zoom link and calendar invite; joins `qa_audience` |
| 19:00 | "we're LIVE" email; the site flips to the live state |
| ~20:20 | session flips to `past`, the next one becomes the signup target |

Late registrants only get near-term reminders. The catch-up window in
`cron/qa-reminders.php` refuses to send a stale "in 7 days" mail, so someone who
signs up on the day gets the 1h and live mails only.

### Needs a human

The campaign pack has the images, the caption and the copy buttons:

**https://coaching.tricktionary.com/video-coaching/static/campaign/**

Build it for the next session before you promote anything:

```bash
python3 backend-php/api/cron/qa-campaign-build.py            # preview locally
python3 backend-php/api/cron/qa-campaign-build.py --deploy   # publish it
```

It reads the date off the sessions API and renders all nine Instagram images
with that date burned in, plus the caption, the German and English WhatsApp
copy and the run sheet. Timezone comes from the date, so the November session
correctly says CET while October says CEST.

**This used to be a lie.** The page claimed it "always shows the current
session" while being a hand-copied duplicate of the previous month. On 7
September, six days after the September call, it still served
`qa-sept1-*.jpg` and read "Tuesday, September 1". Templates now live in
`website/static/campaign/_templates/`; the pack itself is generated output, so
do not hand-edit `campaign/index.html` or the jpgs. The builder also deletes
the previous session's images, which is what made the staleness invisible
before: old jpgs sat beside the page long after nothing referenced them.

The dated copies at `/campaign-aug4/` and `/campaign-sept1/` are the archive.

1. **Instagram feed post.** The single biggest lever. In August roughly 30 of 34
   signups landed in the 48 hours after the feed post. Post it on
   **@wing.tricktionary** (11.5k) not just @michirossmeier (3.6k); in September
   it only went to the smaller account.
2. **WhatsApp** to the camp groups, one post per group, not individual DMs.
3. **Newsletter.** Audience definition and the list ids:
   `node backend-php/api/cron/qa-promo-audience.mjs`
4. **Stories** on the dated frames from the pack.

### Attribution

Every promo link carries `?src=`, written to `qa_signups.source`. Check what
worked:

```sql
SELECT source, COUNT(*) FROM qa_signups WHERE session_id = ? GROUP BY source;
```

Values: `email`, `whatsapp`, `ig-post`, `ig-story`, `ig-dm`, `ig-bio`,
`newsletter`, `web`, and `invite` which is set server-side by the one-click token
only. **A link without `?src=` records as `web` and teaches you nothing.**

The Instagram comment funnel answers both `qa` and `q a`, so "Q&A" as people
actually type it works. Rules live in `instagram.db.keyword_rules`.

---

## Phase 3: Run the call

The companion is the dashboard:
**https://ari.tricktionary.com/projects/qa-companion/qa-companion.html**

It lands on the soonest session by itself and loads the submitted questions.
Hit **Record**: it transcribes, works out which submitted questions you have
covered, ticks them off and suggests the next one with an answer angle. Click
any question to tick it by hand.

Suggestions run through the **Forge Claude gateway**, not a direct Anthropic
key. The direct key had run out of credit on session day; the gateway is backed
by the Max subscription. Config: `gatewayKey` in `/home/ari/podcast-copilot.json`.

Zoom: `join_before_host` is **off** and there are no alternative hosts, so start
the room a few minutes early or people wait outside. Recording is automatic
(`auto_recording: cloud`).

---

## Phase 4: Post-process

**Nothing happens on its own here.** The replay email fires only when
`replay_url` is set, and setting it is the trigger, so do it last.

```bash
# 1. everything off Zoom (needs the cloud_recording + report scopes, added 2 Sep)
node projects/video-coaching/backend-php/api/cron/qa-postprocess.mjs <session_id>

# 2. write the session data file, this is the judgement step, CC does it
#    projects/events-site/live-qa/replay/data/YYYY-MM.json
#    summary, chapters, faq pairs, product keys per chapter

# 3. build and deploy
cd projects/events-site/live-qa/replay && python3 build-replays.py
cd projects/events-site && bash deploy-events.sh site

# 4. review the page, THEN set replay_url. This sends the replay email
#    to every registrant within the hour.
```

The replay pages are generated, never hand-edited. Editing
`2026-09/index.html` directly is pointless: the next build overwrites it.
Change `data/YYYY-MM.json` or `data/products.json` instead.

Product links resolve from one shared catalogue, so changing a product URL is a
single edit that reaches every past and future replay page.

---

## Phase 5: Repurpose

**Not done for the first two sessions.** 22,368 words of Michi answering real
questions are sitting in two transcripts.

### Opus Clip

The replay MP4 is publicly reachable, so it goes straight in:

```bash
bash skills/opus-pro/scripts/opus-pro.sh submit \
  "https://coaching.tricktionary.com/video-coaching/static/replay/qa-2026-09-01.mp4" \
  --confirm --max-credits 90
bash skills/opus-pro/scripts/opus-pro.sh clips <projectId> --out ./clips
```

Credits are about 1 per minute, so a 76 minute session costs roughly 76 of the
900 monthly cap. One session a month is comfortable.

**Do not try to schedule straight to Instagram.** As of 2 September the
Instagram Business, YouTube, Facebook and X tokens in Opus are all invalid. Only
TikTok Business and the three LinkedIn accounts have valid tokens. Download the
clips and post them by hand, or re-authorise in Opus first.

### Everything else the transcript feeds

- Blog posts, via the content machine
- Newsletter sections
- The per-question pages under a future `/wingfoil-answers/`

---

## Data model

- `qa_sessions` - lifecycle state machine plus the email stamps
- `qa_signups` - registrations, interests, source, the submitted question
- `qa_audience` - **the asset.** Everyone who ever signed up or unlocked a
  replay. Drives the invites. Unsubscribes are one-click and set `unsubscribed`.
- `qa_email_samples` - which batches Michi has been sent a `[copy]` of
- `event_inquiries` - camp enquiries; ticking the Q&A box adds them to
  `qa_audience`

---

## Ops notes

- Every lifecycle send is stamped, so re-runs are safe. `--dry` previews.
- Michi gets one `[copy]` of each email type per session, not one per recipient.
- Logs: `/home/coaching/qa-lifecycle.log`, `/home/coaching/qa-reminders.log`
- Duplicate people on the list: `php backend-php/api/cron/qa-duplicates.php`
- Questions asked in Instagram comments or DMs:
  `python3 backend-php/api/cron/qa-questions.py`
- Audit that no comment went unanswered, against the real API, because the local
  event store is incomplete: `node backend-php/api/cron/ig-comment-audit.mjs`

---

## Known gaps

- **Live question submission.** People can only attach a question at signup. A
  repeat signup resends the confirmation but never updates the message, so there
  is no way to add one later. A `/live-qa/ask` page is the fix.
- **Question upvoting.** Wait until there is a public ask surface and enough
  volume; at 7 questions from 24 people a vote count is noise.
- **Companion transcript.** `qa-save.php` writes to `/home/ari/qa-transcripts/`
  but it is empty, so Save transcript has never been used. Zoom's own transcript
  is better anyway.
