# Q&A Funnel Roadmap

The single durable plan for the live Q&A funnel: WingCoach PHP backend (sessions,
signups, confirmation + .ics, reminder cron) + Instagram comment-to-DM auto-register
+ qa-companion. Future sessions build on this file; update phases in place and date
what ships.

Strategy frame: the Q&A funnel is Tier 1 (free, visibility) of the Michi Rossmeier
ladder (`kb/strategy/brand-ecosystem-2026-04.md`). Its one job: capture wingfoilers
where they already are (Instagram, events page) and qualify them upward to Tier 2
(workshop, book) and Tier 3 (coaching, camps). Every phase below serves that.

Scope guard: the coaching PHP app is being integrated into the new **Coaching OS**
platform (NOT the wingfoil mobile app — that was a merge idea, not a decision; see
`video-coaching/CLAUDE.md`). The events/Q&A funnel layer stays PHP for now. Funnel
work here is in scope; keep the API clean so the Coaching OS can absorb it later
unchanged.

---

## Phase 0 — Shipped (log)

- 2026-06-18/21: sessions CRUD + typed sessions, public live-qa page on events site,
  signup API + confirmation email + .ics, reminder cron (7d/24h/1h schedules,
  qa_reminder_log idempotency), IG comment→DM→email→auto-register funnel
  (3 accounts: wing.tricktionary, michirossmeier, loveallsurfallstyle, keyword "qa"),
  admin Q&A tab, qa-companion recording co-pilot.
- 2026-07-04: IG DM auto-register into sessions; admin Upcoming/Past split.
- 2026-07-07 (this review, all deployed + verified live):
  - Reschedule now wipes qa_reminder_log so reminders re-fire for the new date
    (was: silently suppressed forever).
  - Cancelling a session emails every registrant (was: nobody was told).
  - Signup insert is atomic against capacity (no overbooking race).
  - Duplicate signup returns the same success shape as a fresh one (idempotent,
    closes the email-enumeration hole; IG side unaffected).
  - Admin JS renders all times as Berlin wall-clock (correct when travelling);
    create/edit forms labelled "Berlin time".
  - IG: honest truth-table on email capture. If the Q&A registration fails the
    lead gets a truthful "locking in your spot" DM, state stays armed for retry,
    and a `qa_register_failed` metric fires (was: false "you're on the list!").
  - IG: Q&A-only funnels no longer force-subscribe leads to a Sendy list.
  - IG: DM webhook redeliveries are dropped via the events_in claim (no double
    thank-you DMs / double analytics).
  - Sendy default list fixed: now `wingfoil-events-leads` (live-verified). The
    "The Lineup Insider" id is rejected by Sendy's /subscribe despite being the
    id the admin reports (Sendy-side id bug) — that list is unusable via API
    until recreated. NOTE: `waitlist.php` (the-lineup waitlist) still points at
    the broken id; its DB insert works, only the Sendy half fails silently.
  - Source attribution surfaced: admin signups panel shows a Source column +
    per-source counts; events page sends explicit `source: 'web'`; IG analytics
    now also count per-funnel under `rule:<id>` (legacy `wedge` totals kept).
  - Ops: instagram + instagram-engine were found orphaned/dead after a PM2
    daemon death; restored under pm2 and saved.

## Phase 1 — Trust & truth (NOW; code done, two human items open)

- [x] All fixes above.
- [ ] **Zoom links for sessions 11 (Jul 14) + 12 (Jul 28)** — waiting on Michi.
      Without a meeting_link the 1h reminder says "link follows" and nothing follows.
- [ ] **Promote the sessions.** Both are live with 0 signups. The funnel machinery
      is proven; it has never been fed traffic. One IG post/story with the "qa"
      keyword per account is the cheapest possible test.
- [ ] Decide fate of the "The Lineup Insider" Sendy list (recreate it, or repoint
      waitlist.php to a working list).

## Phase 2 — See the funnel (attribution + visibility)

Goal: Michi can answer "where do my leads come from and which funnel works" in
one glance.

- Per-funnel analytics view in the IG app leads page: triggered → dm_sent →
  opted_in → qa_register_failed per `rule:<id>` (data already collected as of
  Phase 1).
- Forge tile for the Q&A funnel: next session, days out, signup count, source
  split, reminder schedule state, Zoom link present yes/no.
- Weekly funnel digest as a Forge note (cron): signups this week by source,
  next session readiness.
- Source values richer than instagram/web: per-account (wing vs michi vs loveall)
  and per-campaign (e.g. `instagram:rule_qa_wing`), so ladder attribution works
  end to end.

## Phase 3 — The missing bottom half (post-event engine)

Nothing happens after a call ends today. This is where Tier-1 leads convert or die.

- Follow-up email offsets AFTER the session (reuse the qa_schedules/cron/log
  pattern with negative-direction offsets): thank-you + replay link + "next
  session" invite + one Tier-2 CTA (book/workshop).
- Reschedule notification: when scheduled_at changes, email registrants the new
  date with a fresh .ics (reminder wipe from Phase 1 makes reminders correct;
  this makes the change explicit).
- Post-session status: attended/no-show flag per signup (manual toggle in admin
  is enough at first) so follow-up copy can differ.
- Nurture opt-in: a line in confirmation + follow-up emails that adds Q&A
  registrants to the wingfoil nurture list (explicit, not silent).

## Phase 4 — Content flywheel (qa-companion loop-back)

Each call becomes raw material instead of a dead-end transcript file.

- Link qa-companion transcripts to their session row (session_id in the
  transcript metadata; transcripts currently land in /home/ari/qa-transcripts/).
- Auto-draft after each session: recap email (feeds Phase 3 follow-up), FAQ/KB
  entries (feed the future wingfoil support bot), 2-3 carousel/clip ideas from
  the best Q&A moments.
- Questions asked in signups + live become a topic bank that seeds the next
  session's title/description (titles with concrete topics convert better than
  "Q&A with Michi").

## Phase 5 — Multi-channel + auto-promotion

The schema is already channel-agnostic (accounts.channel: instagram | facebook |
telegram | whatsapp | tiktok).

- Same keyword funnel on Facebook comments; Telegram/WhatsApp entry points.
- "New session" auto-announcement: creating a session drafts the IG caption +
  Sendy blast + DM re-invite to past attendees (Michi approves, one tap).
- Recurring series: a session can auto-roll "next" (e.g. every 2nd Monday), so
  `qa_session_id='next'` rules never point at nothing and the funnel never goes
  dark between sessions (the resolveSession-returns-null gap closes for good).

## Phase 6 — Ladder integration (big vision)

- Lead scoring per email: registered (+1), attended (+2), asked a question (+2),
  clicked a Tier-2 CTA (+3). Hot leads surface in Forge / get a personal DM.
- Paid premium Q&A tier (small group, screen-share video review) — Stripe already
  lives on the coaching platform.
- Keep the Q&A API stable and documented so the mobile-app absorption consumes it
  unchanged; the funnel becomes the app's acquisition top-end.

---

## Working agreements

- Verify against the live server (`ssh coaching-server "php8.4 -l ..."`, real API
  calls); PHP is not installed locally.
- MariaDB session tz is Europe/Berlin while PHP runs UTC: SQL time math uses
  UNIX_TIMESTAMP()/NOW(), JS renders Berlin wall-clock, never browser-local.
- No em-dashes in any user-facing copy. Plain language for Michi.
- Deploy: `bash deploy-coaching.sh backend|website`, events via
  `bash deploy-events.sh site`, IG app via npm build + pm2 restart.
