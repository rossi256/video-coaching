# Claude Code Prompt: Tricktionary Video Coaching Platform

## Project Name
**WingCoach** — Remote Video Coaching by Tricktionary

Tagline: *"The fastest way to break through your wingfoil plateau."*

---

## What We're Building

A standalone web application for Michi Rossmeier (Tricktionary) to offer remote video coaching.

Riders pay €39 (early adopter price), upload their riding videos, fill out a rider profile, and receive a personalized video feedback reply from Michi. Limited to 10 early adopters.

This is the **entry product** in a scaling coaching ecosystem. Keep the architecture clean and evolvable — it will grow into ongoing monthly coaching memberships.

---

## Tech Stack

- **Frontend:** Vanilla HTML/CSS/JS with Tailwind CSS (CDN), Lucide icons (CDN)
- **Backend:** Node.js + Express
- **Database:** SQLite via `better-sqlite3`
- **Payments:** Stripe Checkout (hosted page, redirect flow)
- **File uploads:** `multer` for local disk storage
- **Email notifications:** `nodemailer` (SMTP — config via .env)

No React, no Next.js, no complex frameworks. Keep it simple and deployable on a basic VPS.

---

## Full Feature Spec

### 1. Landing Page (`/`)

**Layout (mobile-first, clean, sports-oriented):**

Hero section:
- Bold headline: "Break Through Your Wingfoil Plateau — Get Expert Video Feedback"
- Subheadline: "Send Michi your riding video. Get a personalized video response within 48 hours."
- Early adopter badge: "🔥 Founding 10 — €39 (price goes up to €149)"
- Scarcity counter: "X of 10 spots remaining" (live from DB)
- Single CTA button: "Get My Coaching Spot" → scrolls to or links to payment
- Short credibility block: "Author of the Wingfoil Tricktionary • 20+ years coaching • Trusted by thousands of riders worldwide"

What you get section (3 cards):
- 📹 Send your riding clips (unlimited, any format)
- 🎯 Get a personal video response within 48h
- 📈 Clear, specific feedback on your biggest plateau

How it works section (numbered steps):
1. Pay — secure your spot (€39 founding price)
2. Upload — send your riding videos + fill out your rider profile
3. Receive — Michi reviews and sends you a personal video response

Testimonials/Social proof placeholder (3 empty cards, easy to fill in later)

Early adopter framing section:
- "This is not a course. This is a direct conversation with Michi."
- "We're launching with 10 founding clients at €39. Once all 10 spots are taken, the next round starts at €149."
- "This is your chance to get in early and help shape what remote coaching from Tricktionary looks like."

Footer:
- WhatsApp support link: `https://wa.me/4369913909040` — "Questions? Chat with Michi on WhatsApp"
- Email: `info@tricktionary.com`
- "Secure payment via Stripe"

**Design notes:**
- Color palette: deep navy + ocean teal + white. Action sports feel, not generic SaaS.
- Font: Inter (Google Fonts)
- No heavy shadows. Clean, modern, mobile-first.
- Use Unsplash wingfoil imagery for hero background (search: "wingfoil ocean")

---

### 2. Payment Flow

- "Get My Coaching Spot" button → POST `/create-checkout-session`
- Backend creates a Stripe Checkout Session:
  - Product: "WingCoach — Founding 10 Spot"
  - Amount: €3900 (i.e. €39.00)
  - Currency: EUR
  - Success URL: `/success?session_id={CHECKOUT_SESSION_ID}`
  - Cancel URL: `/` 
- On success: Stripe webhook (`/webhook/stripe`) verifies payment, creates a submission record in DB with `status: 'paid'`, sends email to `info@tricktionary.com` with rider name + email
- Redirect to `/success?session_id=...`

**Important:** Also decrement the spots counter in DB when payment completes (via webhook, not redirect — redirects can be unreliable).

---

### 3. Success Page + Rider Profile Form (`/success`)

Shown after Stripe redirects back. Verifies the session_id is valid and paid.

**Top section:**
- "🎉 You're in! Spot secured."
- "Now let's get you set up. Fill out your rider profile and upload your videos below."
- "Michi will review everything and send your feedback video within 48 hours."

**Rider Profile Form (text fields — required):**
- Full name
- Email (pre-filled from Stripe if available)
- Age
- Where do you ride? (location/country)
- How often do you ride? (options: "< 10 days/year", "10–30 days/year", "30–60 days/year", "60+ days/year")
- Usual wind conditions? (e.g. "15–20 knots, choppy", free text)
- Equipment you own: wing size(s), board, foil (free text)
- Your current level: (dropdown — Beginner / Intermediate / Advanced / Expert)
- What specific move or skill are you stuck on? (textarea)
- What have you already tried to fix it? (textarea)
- What would success look like for you after this coaching? (textarea)

**Audio recording option (IMPORTANT):**
Below the form, add a clearly labeled alternative:
"Prefer to record your answers? Hit record and answer each question out loud — the questions stay visible while you record."
- Show all questions in a numbered visible list on screen (always visible, even during recording)
- Add a browser-based audio recorder using the Web Audio API (`MediaRecorder`)
- Record button → recording indicator → stop → playback preview → submit
- Save audio file alongside the text form (if audio recorded, text fields become optional except name + email)
- Questions must remain visible and readable during recording (split-screen or sticky sidebar on desktop, questions above recorder on mobile)

**Video Upload Section:**
- Drag and drop + file picker for video uploads
- Accept: mp4, mov, avi, mkv, hevc, webm — any common format from phones
- Multiple files allowed (no hard limit — riders can upload as many clips as they want)
- Show upload progress bar per file
- Show thumbnail preview after upload (use video element for preview)
- Show file name + size + status (uploading / uploaded / error)
- **Delete button per uploaded file** — rider can remove files they uploaded by mistake
- Files saved to `uploads/<submission_id>/` on server

**Submit button:** "Send to Michi" — submits the form + confirms all uploads done
- On submit: mark submission as `status: 'submitted'` in DB
- Send email notification to `info@tricktionary.com`: "New coaching submission ready for review from [name]"
- Show confirmation: "Done! Michi will review your videos and send your response within 48 hours. Watch your email."

**Support block (always visible on this page):**
"Something not working? Chat with Michi on WhatsApp: [link] or email info@tricktionary.com"

---

### 4. Admin Panel (`/admin`)

Simple password-protected page (basic HTTP auth via env var `ADMIN_PASSWORD`).

**Submission list view:**
- Table: Name | Email | Submission date | Status | Actions
- Status values: `paid` / `submitted` / `feedback_sent`
- Click a row → detail view

**Submission detail view:**
- All rider profile answers displayed
- Audio recording playback (if submitted)
- Video list with download links and inline video player
- Upload reply video button: file picker → upload to `uploads/<submission_id>/reply/`
- "Mark as feedback sent" button → updates status in DB + sends email to rider with download link to their reply video
- Reply video download link for rider (once uploaded)

---

### 5. Reply Delivery Page (`/reply/:token`)

Each submission gets a unique token (UUID). When Michi marks "feedback sent", rider gets an email with a link to `/reply/<token>`.

Page shows:
- "Your coaching feedback from Michi is ready"
- Video player with the reply video (or download link if browser can't play the format)
- Download button
- "Want to continue coaching? Reply to this email or WhatsApp Michi."
- Link back to tricktionary.com

---

## Database Schema (SQLite)

```sql
CREATE TABLE submissions (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  stripe_session_id TEXT UNIQUE,
  stripe_payment_intent TEXT,
  token TEXT UNIQUE NOT NULL,
  status TEXT DEFAULT 'paid', -- paid | submitted | feedback_sent
  
  -- Rider profile
  name TEXT,
  email TEXT,
  age INTEGER,
  location TEXT,
  ride_frequency TEXT,
  conditions TEXT,
  equipment TEXT,
  level TEXT,
  stuck_on TEXT,
  tried TEXT,
  success_looks_like TEXT,
  audio_file TEXT, -- path to audio recording if submitted
  
  -- Meta
  created_at TEXT DEFAULT (datetime('now')),
  submitted_at TEXT,
  feedback_sent_at TEXT,
  reply_video_path TEXT,
  spots_at_purchase INTEGER -- record how many spots were left when they bought
);

CREATE TABLE config (
  key TEXT PRIMARY KEY,
  value TEXT
);
-- Insert: INSERT INTO config VALUES ('total_spots', '10'), ('spots_taken', '0');
```

---

## Environment Variables (`.env`)

```
PORT=3010
STRIPE_SECRET_KEY=sk_live_...
STRIPE_WEBHOOK_SECRET=whsec_...
STRIPE_PRICE_ID=price_...   (or use inline price)
ADMIN_PASSWORD=your_admin_password
SMTP_HOST=mail.tricktionary.com
SMTP_PORT=587
SMTP_USER=ari@tricktionary.com
SMTP_PASS=...
NOTIFY_EMAIL=info@tricktionary.com
BASE_URL=https://ari.tricktionary.com/projects/video-coaching
BASE_PATH=/projects/video-coaching
```

**Important for subpath routing:**
The app is served under `/projects/video-coaching/` via Apache reverse proxy.
In `server.js`, mount all routes under the base path:
```js
const BASE_PATH = process.env.BASE_PATH || '';
app.use(BASE_PATH, router);
```
All internal links, form actions, and redirects must include `BASE_PATH` as prefix.
Static files: `app.use(BASE_PATH + '/static', express.static('public'))`.
Stripe success/cancel URLs must use the full `BASE_URL`.

---

## File Structure

```
tricktionary-coaching/
├── server.js           (main Express app)
├── db.js               (SQLite setup + helpers)
├── stripe.js           (Stripe helpers)
├── email.js            (nodemailer helpers)
├── routes/
│   ├── checkout.js
│   ├── webhook.js
│   ├── upload.js
│   ├── admin.js
│   └── reply.js
├── public/
│   ├── index.html      (landing page)
│   ├── success.html    (post-payment form + upload)
│   ├── reply.html      (feedback delivery)
│   └── css/
│       └── style.css
├── uploads/            (gitignored)
├── coaching.db         (gitignored)
├── .env                (gitignored)
├── package.json
└── README.md
```

---

## Stripe Setup Notes

1. Create a product in Stripe Dashboard: "WingCoach Founding 10 Spot" — €39
2. Use Stripe Checkout (hosted) — no need to embed card fields
3. Register webhook in Stripe Dashboard pointing to `/webhook/stripe`
4. Listen for: `checkout.session.completed`
5. Always verify webhook signature using `STRIPE_WEBHOOK_SECRET`

---

## Design Notes

**Branding:**
- Product name: WingCoach by Tricktionary
- Tagline: "The fastest way to break through your wingfoil plateau"
- This is part of the **Tricktionary ecosystem** — link back to tricktionary.com in footer
- Frame as "next-gen support" — the book teaches, the coaching accelerates

**Tone (landing page copy):**
- Direct, no fluff
- Sport credibility first
- Early adopter framing without fake urgency (real scarcity: 10 spots)
- Price anchor clearly communicated: "€39 now → €149 after founding round"

**Mobile first:** Most riders will land on this from Instagram on their phone.

---

## What NOT to Build (Keep It Simple)

- No user accounts / login system
- No email verification flow
- No automated video processing
- No S3 / cloud storage (local disk is fine for 10 riders)
- No React / Vue / complex frontend
- No payment retry logic (Stripe handles it)

---

## Start Order for Claude Code

1. `package.json` + install dependencies
2. `db.js` — SQLite schema + helpers
3. `server.js` — Express setup, static files, basic routing
4. `routes/webhook.js` — Stripe webhook first (most critical)
5. `routes/checkout.js` — Checkout session creation
6. `public/index.html` — Landing page with live spots counter
7. `public/success.html` — Profile form + audio recorder + video upload
8. `routes/upload.js` — File upload + delete endpoints
9. `routes/admin.js` — Admin panel
10. `routes/reply.js` — Reply delivery page
11. `email.js` — Notifications
12. `public/reply.html` — Reply download page
13. `.env.example` — Template
14. `README.md` — Setup + deployment instructions

---

## Notes for Later (Not Now)

- Ongoing monthly coaching membership (upsell after founding 10 complete)
- Multi-sport expansion (windsurf, kite, SUP)
- Group coaching webinars
- App chatbot integration (Tricktionary Knowledge Engine)
- These are the next tiers in the Tricktionary product ecosystem. Keep architecture clean so they can be added.
