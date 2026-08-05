# Tricktionary Community - Concept Paper (v1, 2026-08-05)

Origin: Frank's pitch on Live Q&A #1 ("a place where your readers connect, exchange about
moves, and ask you - readers free, others a small fee, like Alan Cadiz's Patreon") and
Michi's on-air commitment ("100%, I will look into that"). Development target: the wing
app being built this month + a web interface. This paper is the decision basis; build
happens in a separate session in the app project.

## Why now
- Live proof of demand: Q&A #1 produced the idea organically; attendees asked for MORE
  connection (Carl: instructor calls; Frank: move exchange; Joachim: gear guidance culture).
- The assets already exist: `qa_audience` (growing people table), monthly live calls +
  replay library, the books' move taxonomy, WingCoach, 212+ IG funnel contacts, 23k newsletter.
- Strategic fit: the app needs a retention layer beyond content; community is the moat
  competitors (Duotone Academy app) don't have - THE differentiator is Michi himself + the
  book-structured move taxonomy.

## What it is (product definition)
A member area around the Tricktionary universe with four pillars:
1. **Move Exchange** - discussion structured by the book's move categories (basics ->
   jibes -> tacks -> jumps -> rotations...). Every thread anchored to a move. This IS
   Frank's ask and maps 1:1 to the books and future app content.
2. **Live Calls layer** - monthly Q&A (existing machine) + special formats (instructor
   roundtable, camp pre-briefs). Members see calendar, replays with chapters, and can
   submit questions upfront (feeds the host prep automatically).
3. **Ask Michi** - a visible queue where members drop questions; answered in threads or
   picked up in the next live call. Bounded effort: Michi answers in batches / on calls.
4. **Progress + clips** - members post a clip per question (reuses WingCoach upload
   mechanics); later: Beat-Steffen-style progress tracking (he tracks 1000+ variations -
   podcast tie-in, possible collab feature).

## Access model (Frank's framing, refined)
- **Free tier:** everyone in `qa_audience` (Q&A participants, replay watchers) - read +
  live-call access. Keeps the funnel wide; Q&A stays the free front door.
- **Reader tier (free, verified):** book buyers - full posting rights. Verification:
  order-email match (Magento/webshop data exists) or a code printed in future editions.
  "Buy the book, join the community" becomes a sales argument for the book itself.
- **Supporter tier (paid, later, ~5-9 EUR/m):** priority Ask-Michi, masterclass content
  from the app, early access (footstraps votes, camp pre-booking). Do NOT launch paid on
  day one - earn density first.

## Platform decision (the real question)
| Option | Pros | Cons |
|---|---|---|
| A. In-app native (Rails backend + app UI + web view) | One backend with the app; owns data; taxonomy shared with book content; qa_audience imports cleanly | Slowest to first post; app timeline coupling |
| B. Web-first module on the app's Rails backend (RECOMMENDED) | Live before the app ships; every email/funnel can link it NOW; same backend so the app inherits it natively later; web interface requirement satisfied | Some UI built twice (web + app views) |
| C. Hosted (Circle/Discord/Patreon) | Fastest | Data not owned, no move-taxonomy structure, off-brand, third fee, migration debt - conflicts with app strategy |

Recommendation: **B** - a community module in the app's backend (Rails 8) with a clean
web UI at community.tricktionary.com (or /community on the app domain), consumed later by
the Vue/Capacitor app views. C only as a stopgap if B slips past September.

## MVP (phase 1 - buildable alongside the app this month)
- Auth: magic-link email (qa_audience import = instant member base, no passwords)
- Move-category board (taxonomy from the books, seeded with ~15 categories)
- Replay library integration (existing pages/videos embedded)
- Ask-Michi queue (simple thread type, "answered in call #N" state)
- Member profile: name, level, home spot (Wing Genius data enriches later)
- Lifecycle email hooks: invite + replay emails link the community; new-thread digests weekly
Explicitly OUT of MVP: paid tier, clip uploads, DMs, gamification, app-native UI.

## Effort + moderation reality
- Michi's sustainable budget: ~2h/week (one Ask-Michi batch + skim). Community managers
  later: power users (Frank is the obvious founding member/mod candidate - invite him
  personally, it was his idea and he asked for exactly this role in spirit).
- Cold-start plan: seed with the Q&A #1 threads (the questions already asked), personally
  invite the ~40 warmest (audience + camp alumni), THEN announce at Q&A #2 (Sep 1) as the
  next reveal - keeps the announcement cadence pattern.

## Success metrics (90 days)
- 100+ members activated (magic link used), 30% monthly active
- 10+ move threads with real exchange, Ask-Michi queue feeding every live call
- Q&A attendance growth via community reminders (baseline: 9 live / 30 signups)

## Dependencies / next actions
1. Decide platform option (Michi) -> then build session in the app project
2. App backend scaffold must exist first (wing app dev this month)
3. qa_audience export/import contract (trivial - table exists)
4. Frank: personal invite as founding member once MVP is clickable
