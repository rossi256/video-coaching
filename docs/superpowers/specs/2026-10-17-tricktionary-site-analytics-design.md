# Tricktionary sites: first-party analytics + form funnels - design

Date: 2026-10-17. Status: DRAFT for Michi's approval. Nothing built yet.

## Intent

Port the analytics system built for jimmyvanderham.com (`ho_events` + `hit.php`
beacon + `TRACKER_JS` + the `?p=analytics` admin page) onto the Tricktionary
public sites, and extend it with what Michi asked for that Jimmy's version does
not do yet: **form-level funnels** - who starts a form, how far they get, which
field they stop at, who submits, and which journey a real sign-up came from.

Cookie-free, consent-banner-free, first-party, no Google. Same correctness rules
Jimmy's version learned the hard way (see "Rules carried over").

## The sites (what exists today)

All four hosts are on the same server as Jimmy's API (vp4, 91.134.225.245),
Apache + PHP-FPM 8.x, Cloudflare in front (so `CF-Connecting-IP` and
`CF-IPCountry` exist). None has any analytics today. Nav on all of them is one
shared script, `https://thelineup.tricktionary.com/lineup-menu.js`.

| Site | Source on `ari` (`~/.openclaw/workspace/projects/`) | Deploy | Pages | Forms |
|---|---|---|---|---|
| events.tricktionary.com | `events-site/` | `bash deploy-events.sh site` -> events-server `/home/events/public_html/` | 37 static HTML, hand-copied head/foot, `js/main.js` on 25 of them, `lineup-menu.js` on 32 | camp enquiry (8 pages), lead magnet (email only, 8 pages), waitlists (`app/`, `coaching-subscription/`), **quote (6 steps)**, Live Q&A modal, **quizzes (multi-step)**, `/book/` (separate Next.js app, out of scope here) |
| coaching.tricktionary.com | `video-coaching/` | `bash deploy-coaching.sh [hub\|backend\|website\|private\|assets\|all]` -> coaching-server `/home/coaching/public_html/` | hub `/`, `/private-coaching/`, `/vip-coaching/`, WingCoach `/video-coaching/*` (PHP wrappers around static HTML + Tailwind) | waitlist, Q&A signup modal, private inquiry, VIP application, Stripe checkouts, post-checkout `/success` multi-step |
| thelineup.tricktionary.com | `the-lineup/hub/` | no deploy script found; `thelineup-server` ssh alias exists on ari | 11 static HTML | waitlist forms on summit / book / workshop / elite (2) |
| podcast.tricktionary.com | `tricktionary-podcast/site/` | `scripts/deploy-podcast-subdomain.sh` -> podcast-server `/home/podcast/public_html/` | home + 1 episode (+ for-guests) | none (outbound subscribe links, per-episode feedback widget) |

**Every form on every site already posts JSON to one backend:**
`https://coaching.tricktionary.com/video-coaching/api/` (`event-inquiry`,
`qa-sessions/{id}/signup`, `waitlist.php`, `private-inquiry.php`,
`private-application.php`), stored in the MariaDB
database `coaching` (tables `event_inquiries`, `qa_signups`, `waitlist`,
`private_inquiries`, `private_coaching_applications`, ...). An admin already
exists at `coaching.tricktionary.com/video-coaching/admin` (Basic auth,
Tailwind, vanilla JS, tabs: Video / Event Inquiries / Q&A / Waitlist /
Private Coaching / Coaching Credits) reading `api/admin/*` JSON endpoints.

Verified 2026-10-17: the live `/video-coaching/api/*` and `/admin` are served by
this PHP backend (`admin.php?_action=...` behind `WWW-Authenticate: Basic realm=
"WingCoach Admin"`); the deployed `api/` file list matches the checkout. The
Node app beside it (`server.js`, `db.js`, `coaching.db`) is the old staging
backend and is not in play.

`lineup.tricktionary.com` does not exist (no DNS). The hub is `thelineup.`.

**Bug found while mapping the forms (fix is a prerequisite, see below):** the
6-step quote flow at `events.tricktionary.com/quote/` (linked from the home
page and 8 camp pages) posts to `api/event-quote-request.php`, which exists in
no checkout and returns 404 live. Every visitor who finished the quote has seen
"Hmm, that didn't send" and nothing was stored. This is exactly the failure the
form funnel is meant to surface (100% `submit`, 0% `ok`).

## Goals

1. Michi can open one page and see, per site and across all sites: visits,
   visitors, sources (first touch), entry and exit pages, clicks, devices,
   countries, trend over time. Same shape as Jimmy's overview.
2. For every form: how many saw the page, started the form, reached each
   field / step, submitted, succeeded. Where people stop. Per form, per
   period.
3. Every real sign-up row (inquiry, Q&A signup, waitlist, application, quote)
   is linked to the anonymous journey that produced it: source, landing page,
   pages seen, device, country, time from first visit to submit.
4. Ships to all four sites with one deploy, and every future
   `*.tricktionary.com` page that carries the shared nav gets it for free.

## Non-goals

- Identifying people who have not submitted anything (see open question 1).
- `/book/` (Next.js booking app on events.tricktionary.com) - it already
  stores `source`/`utm_json` on bookings; joining it is a follow-on.
- www.tricktionary.com (Magento, has GTM), blog, riders, progearservice.com.
  The beacon will accept any `*.tricktionary.com` host so adding them later is
  one script tag; PGS is a different company and gets its own instance if
  wanted.
- Replacing Jimmy's stack. His stays on Supabase; nothing here touches
  project `bjcsuk...` (see [[jimmy-vs-tricktionary-separation]]).
- Email open tracking (`px.php`) - Tricktionary mail goes through Sendy, which
  has its own.

## Anti-goals (would make it a failure even if it "works")

- A consent banner, a cookie, a durable visitor id, or any third-party script
  on public pages.
- Conversion numbers that can exceed 100% or that count pasted URLs / reloads.
- A tracker that blocks rendering or hurts Core Web Vitals.
- Two sources of truth for sign-ups. The headline sign-up count is always the
  real table row, never the beacon.

## Architecture (three movable parts, as in Jimmy's)

### 1. Beacon endpoint - `video-coaching/backend-php/api/hit.php`

Port of Jimmy's `hit.php` with these changes:

- Writes to MariaDB (same `$db` PDO as the other handlers), not Supabase.
  Table `site_events`:
  `id, site (host), session (varchar 64), visitor (char 16, day-scoped hash),
  kind (page|click|form|convert|scroll), path, ref_host, utm_source,
  utm_medium, utm_campaign, label, value (decimal null), device, country
  (char 2), day (date), created_at`. Indexes on `(site, day)`,
  `(session)`, `(kind, label)`.
- Accepts beacons cross-origin from any `*.tricktionary.com` origin (body is
  `application/x-www-form-urlencoded` via `sendBeacon`, so no preflight; the
  handler just checks `Origin`/`Referer` host suffix and drops others).
- `site` = host of the page (`p` carries the path; new param `h` carries the
  host, validated against the suffix).
- Own-domain referrers (`*.tricktionary.com`) are dropped the way Jimmy's
  drops `jimmyvanderham.com`, so cross-site hops between our own properties do
  not show up as "outside sources". The hop itself is still visible as a
  `page` event on a different `site` within the same session (see "one
  session across our hosts" below).
- Visitor hash salt = a new `ANALYTICS_SALT` constant in `config.php` (rotate
  freely; it only needs to be stable within a day).
- Same bot filter, same device/country derivation, 204, nothing returned.

### 2. Client tracker - `tk.js`

One file, hosted at `https://thelineup.tricktionary.com/tk.js` next to
`lineup-menu.js` (same deploy, same cache rules). ~3 KB, vanilla, inline-safe.

**How it gets onto pages.** `lineup-menu.js` appends
`<script src="https://thelineup.tricktionary.com/tk.js?v=..." defer>` when
it mounts. That covers the coaching hub, thelineup, podcast and 32 of 37 events
pages with zero per-page edits, and every future page that carries the nav.
The 5 events pages without the nav (ybmpt/2026/*, quiz index + preview, replay
pages, casa-punta-paloma) and the WingCoach PHP wrappers get the one script
tag added by hand. Verification step: a crawl of every page on the four hosts
asserts `tk.js` is present.

**One session across our hosts.** `sessionStorage` is per origin, so a sid
set on podcast. would not exist on events. - a podcast listener who signs up
for a camp would read as "direct" on events. Fix (my call, no schema change):
`tk.js` decorates every link whose host ends in `.tricktionary.com` and
differs from the current host with `?tks=<sid>` (on click, capture phase, same
pattern as the partner-ref decorator in `events-site/js/main.js`). On load the
tracker adopts a `tks` from the URL into its own `sessionStorage` and removes
it with `history.replaceState`. No cookie, nothing durable, and a pasted link
carrying an old `tks` just continues a session that the dashboard treats like
any other. First touch stays "the earliest event of the session", so the
podcast page becomes the attributed landing. Links that leave our domains are
never decorated.

Pages without the shared nav (WingCoach `/video-coaching/*`,
`/private-coaching/`, `/vip-coaching/` - verified none load `lineup-menu.js`)
get the tag by hand as well as the five events pages listed above.

**What it sends** (Jimmy's set, plus the form funnel):

- `page` on load, with first-touch `utm_*` / `?ref=` / `?src=` (events-site
  and Q&A already use `?src=` and `?ref=`; both are read as `utm_source` when
  `utm_source` is absent, with `utm_medium` = `ref` / `src`).
- `click` on `a.btn, button, .cta a, nav a, [data-ev]` and on outbound links
  (podcast's Apple/Spotify/YouTube buttons, wa.me, mailto) - label = `data-ev`
  or visible text, `value` unused.
- `scroll` depth + dwell on pagehide (as Jimmy's).
- **`form` events, the new part:**
  - `start` on first focus inside any `<form>` that has an `id` or
    `data-form` (label = `<formkey>`; the form key is `data-form` or the id,
    with per-page slug suffixes like `event-form-tarifa` kept as-is).
  - `field` the first time each field is touched (focus or change), label =
    `<formkey>:<field name>` and `value` = ordinal position of that field in
    the form. Only the field *name*, never its content.
  - `step` for multi-step flows, label = `<formkey>:<n>` - emitted by a tiny
    hook `window.tk && tk.step(formkey, n)` that `quote.js` / `quiz.js` /
    WingCoach `/success` call where they already call `goTo(n)` /
    `showQuestion(n)`.
  - `submit` on the submit event, `ok` / `error` from the fetch result (the
    submit functions in `main.js`, `funnel-footer.js`, `quote.js`, `quiz.js`,
    the hub pages and the coaching pages call `tk.result(formkey, ok)`).
  - `abandon` on pagehide when `start` fired and neither `submit` nor `ok`
    did: label = `<formkey>:<last field name>`, value = number of fields
    touched. This is the "where do they leave" signal.
- **`convert`** fires client-side only after a real `{ok:true}` from the
  backend (label = formkey), once per session. There are no redirect/thank-you
  pages on these sites, so Jimmy's `cv=` marker is replaced by the fetch result
  as the gate - a pasted URL can never produce one.

**Session stitching to real rows.** The tracker exposes `tk.sid`. Every
existing submit function adds `session: tk.sid` (and `landing`, `utm_source`
if present) to its JSON body. Each receiving handler stores it in a new
nullable `session_id varchar(64)` column on `event_inquiries`, `qa_signups`,
`waitlist`, `private_inquiries`, `private_coaching_applications`. Nothing else
about the handlers changes; a missing session (tracker blocked) is simply NULL
and the row still counts as a sign-up. All handlers are plain PDO `json_decode`
readers that ignore unknown fields, so adding `session` to the body is safe
before the column exists.

**Prerequisite: the quote endpoint.** Build `event-quote-request.php` (new
table `event_quote_requests`: contact fields, camp/tier/people/accommodation/
gear picks, `approx_total`, `quote_details` JSON, honeypot check, the admin
notification mail the other handlers send, `session_id`) and an
`admin/quote-requests` list + tab. Without it the quote funnel can only ever
show errors, and the stored quotes are the sign-ups Michi most wants to see.

Rule from Jimmy's ([[analytics-on-every-feature]]): when a side-effect is added
to one submit path, grep the siblings. There are ~10 submit paths; the plan
lists each.

### 3. Store + read side - MariaDB + `api/admin/analytics*`

- `site_events` as above, plus a nightly cron purge after 400 days.
- Read endpoints (Basic auth, same as the other `admin/*`):
  - `GET api/admin/analytics?days=30&site=all|events|coaching|thelineup|podcast`
    -> KPIs (views, visitor-days, sessions, sign-ups real, sign-ups traced,
    conversion = traced/sessions), daily trend, attribution (first touch),
    entry pages, exit pages, top pages, outside sources, clicks, devices,
    countries, per-site split.
  - `GET api/admin/analytics/forms?days=30&site=...` -> one block per form
    key: page views of the pages that host it, started, per-field reach
    (ordered by the field ordinal), submitted, ok, abandoned, top abandon
    field. Multi-step forms show steps instead of fields.
  - `GET api/admin/analytics/journey?session=<sid>` -> the ordered event list
    for one session (used from a sign-up row: "how did this person get here").
  - Each sign-up list endpoint that exists (`event-inquiries`, `qa-signups-all`,
    `waitlist`, ...) gains `source`, `landing`, `device`, `country`,
    `first_seen` joined through `session_id` when present.
- Admin UI: a new **Visitors** tab in `website/static/admin.html` with the
  same cards as Jimmy's overview (ported layout, Tailwind instead of Tabler),
  a site switcher, a range switcher (14 / 30 / 90 days), a **Forms** section
  (one funnel card per form, horizontal bars like Jimmy's "path to sign-up",
  abandon field highlighted), and a "journey" drawer reachable from any
  sign-up row in the existing Event Inquiries / Q&A / Waitlist tabs.
  Charts: ApexCharts from CDN on the admin only (gated page, as on Jimmy's).
  Audited at four widths with the house `designing-interfaces` auditor
  before it is shown.

## Rules carried over from Jimmy's (keep verbatim)

- Conversion is gated (real backend ok), never inferred from a page view.
- Dedupe conversions by `distinct session`, not rows.
- Conversion rate = traced sign-ups / sessions. Headline sign-ups = real rows.
  The UI bridges them: "N of M sign-ups could be traced to a visit".
- Visitors are visitor-days, labelled "counted once a day". Do not add a
  durable id to "fix" it.
- Attribution is first touch per session.
- Bots are filtered from the data, never served different HTML.
- No external script on public pages; chart lib only behind the admin login.

## Privacy position (so there is no banner)

Stored per event: day-scoped hash (unlinkable tomorrow), path, referrer host,
utm, a form key and a field *name*, device class, 2-letter country. No IP, no
cookie, no field contents, no email before submit. The `session_id` on a
sign-up row links a submitted form (which already holds name + email with the
person's knowledge) to its own anonymous journey - the same data the person
produced, kept together. Add two lines to each site's privacy text.

## Constraints

- Deploys: four separate rsync targets plus the shared nav file. The
  `lineup-menu.js` cache is `max-age=14400` per `?v=` URL, so the tracker
  reaches cached pages within 4 hours of the nav deploy without touching them.
- `events-site/js/main.js` is cache-busted by hand (`?v=YYYYMMDDx`) on each
  page; two pages lag (`20260930b/c`). The build bumps all 25 in one sed.
- `the-lineup/hub` has no deploy script in the repo. The builder finds the
  target via the `thelineup-server` alias on ari and writes one, mirroring
  `deploy-events.sh`, before shipping.
- Handlers are plain PDO; a schema change is a new file in
  `backend-php/api/migrations/` and is applied with the existing pattern.
- Smoke tests must assert content, not status + size
  ([[smoke-test-passes-on-empty-lists]], [[php-lint-is-not-a-smoke-test]]).

## Verification (definition of done)

1. QA scenario, as on Jimmy's 2026-10-01: same browser two sessions, a
   double-fired convert, a reload of a page after submit, a blocked tracker
   submit. Assert: one visitor-day, one traced conversion, real sign-ups
   count the blocked one too, rate <= 100%.
2. Form funnel scenario: open the quote flow, stop at step 3, close the tab.
   Assert one `abandon` with `quote:3`. Open a camp enquiry, fill name and
   email, leave. Assert `field` events for name and email in order and
   `abandon` labelled `...:email`.
3. Crawl all pages on the four hosts: every HTML page loads `tk.js`; no
   console errors; Lighthouse performance unchanged within noise on two pages.
4. Admin: Visitors tab renders with data on phone width; a journey drawer
   opens from a real inquiry row.

## Calls I am making (override any of them)

- Store in the existing `coaching` MariaDB, not Supabase. Reason: every form
  already lands there, the admin already reads it, same server as the beacon,
  and it keeps Tricktionary data out of Jimmy's project.
- Tracker delivered through `lineup-menu.js` + hand-add on the few pages
  without the nav.
- Field *names* and ordinals only; never field contents; no email before
  submit (pending open question 1).
- 400-day raw retention.
- Dashboard lives in the existing coaching admin (pending open question 2).
- One session across our hosts via the `tks` link parameter (no cookie).
- Fixing the missing quote endpoint is in scope as a prerequisite.

## Open questions for Michi

1. **Identify abandoners?** As designed, someone who types an email and leaves
   is counted ("started, stopped at email") but not named. If you want to see
   *who* half-filled a form, the tracker would send the email on blur before
   submit. That is personal data the person did not agree to send and it
   changes the privacy position above. Default: no.
2. **Where should you look at it?** Default: the existing
   `coaching.tricktionary.com/video-coaching/admin` (Basic auth, where the
   inquiries already are). Alternative: coaching-os on forge.tricktionary.com,
   which already reads the inquiries over HTTP. The API is the same either
   way; only the first UI differs.
3. **Scope of sites now.** The four named (with thelineup for lineup) plus
   the WingCoach PHP pages. Add progearservice.com in this round or later?

## Left to the builder

Exact form keys per page, migration file naming, the admin tab's markup,
cron wiring, the hub deploy script, the order of the four deploys (backend
first, then nav, then per-site script tags, then verification crawl).
