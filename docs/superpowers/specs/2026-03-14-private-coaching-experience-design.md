# Private Coaching Experience - Landing Page Design

## Overview

A standalone landing page for Michi Rossmeier's premium private coaching offering. Positioned as an exclusive, inquiry-only experience where Michi travels to the client's chosen location for a fully customized coaching immersion. The page serves as an application funnel - it signals premium through design and language, captures qualified leads via a structured application form with optional audio/video messages, and routes inquiries to Michi for personal follow-up.

**URL:** `coaching.tricktionary.com/private-coaching/`
**Name:** "Private Coaching Experience"
**Target:** High-net-worth individuals or small groups seeking exclusive, location-independent wingfoil coaching

## Visual Language

Derived from the Progression Summit page, elevated for premium positioning:

- **Fonts:** Playfair Display (headings, serif elegance), Inter (body, clean readability)
- **Palette:** Navy base (`#060a14`), gold accents (`#d4a843` / `#f0d078`), teal secondary (`#2dd4bf`)
- **Hero gradient:** White-to-gold on H1 text (no teal - warmer than summit, differentiates the product)
- **Ambient backgrounds:** Radial gradient glows, gold-dominant (warmer, more luxurious than summit's teal-forward glows)
- **Scroll animations:** Fade-in from below via IntersectionObserver (same pattern as coaching hub and Pro Gear Service)
- **No emoji icons anywhere.** Typography, color, and whitespace do the work.
- **Tone:** Understated confidence. Short declarative sentences. Reads like a private invitation, not a sales page.

## Page Structure

### 1. Hero (full viewport)

- Badge: "PRIVATE COACHING" (gold border pill, uppercase tracking)
- H1: Gradient text (Playfair Display, white-to-gold). Short, declarative. Example: "Your Coach. Your Location. Your Progression."
- Subtitle: One line, Inter light weight. Example: "A fully customized coaching immersion with Michi Rossmeier - anywhere in the world."
- Host line: "by Michi Rossmeier" in gold
- CTA: "Apply for a Private Experience" - gold gradient button
- Ambient gold/teal radial glow background (no hero image - let the typography breathe)
- No stats counters, no urgency signals

### 2. The Experience (prose section)

- Section label: "THE EXPERIENCE"
- No feature grid or bullet list. 3-4 short paragraphs of flowing prose.
- Content covers:
  - Michi comes to you - your location, your schedule, your conditions
  - Full immersion: on-water coaching, video analysis, technique refinement, equipment optimization
  - A hint at depth beyond technical: mindset, session planning, peak performance in the water
  - The experience begins before arrival and continues after - preparation, goal-setting, follow-up
  - Fully customized to your level, goals, and riding style
- Typography: larger body text (1.1rem+), generous line height, max-width ~700px for readability

### 3. What's Included (three pillars)

- Section label: "YOUR JOURNEY"
- Three cards/columns, not a long checklist:
  - **Before:** Pre-experience video review of your current riding, goal-setting conversation with Michi, custom preparation plan
  - **During:** Daily private on-water coaching, real-time video analysis, technique and progression sessions, equipment guidance and setup optimization
  - **After:** Follow-up coaching sessions, progression review, continued access to Michi for questions and check-ins
- Card styling: navy-card background (`#111827`), subtle gold border on hover, Playfair Display card titles
- 2-3 lines of description per card, no bullet points

### 4. Your Coach (Michi section)

- Section label: "YOUR COACH"
- Layout: portrait image left, text right (same as summit host section)
- Portrait: `coach-portrait.jpg` (existing asset)
- Name in Playfair Display, title/role in gold
- Bio: 2 paragraphs focused on experience, trust, and coaching depth. Decades of action sports, authored the Tricktionary book series, coached hundreds of riders.
- Credentials as subtle pills: "Tricktionary Author", "20+ Years Coaching", "Pro Gear Service Founder"

### 5. Application Form

- Section label: "APPLY"
- Title (Playfair): "Apply for Your Experience"
- Subtitle: "Tell Michi about yourself and what you're looking for."
- Ambient gold glow behind the form area

**Required fields:**
- Name (text input)
- Email (email input)

**Structured questions:**
- Preferred location or region (text input, placeholder: "e.g. Maldives, Caribbean, Mediterranean...")
- Time of year (text input, placeholder: "e.g. Spring 2026, flexible...")
- Number of riders (dropdown: 1, 2, 3-4, 5+)
- Riding level (dropdown: Beginner, Intermediate, Advanced, Mixed group)

**Optional message:**
- Textarea: "Anything else Michi should know?"

**Optional audio message:**
- Record button with waveform visualizer
- Playback before submit
- Uses MediaRecorder API (same pattern as video coaching success page)
- Stored as audio file on server

**Optional video message:**
- Camera button, preview window, re-record option
- Uses MediaRecorder API with video constraint
- Stored as video file on server

**Submit:**
- Gold gradient button: "Submit Application"
- Loading state during upload
- Success state replaces form: "Thank you. Michi will review your application and be in touch personally."

### 6. Footer

- Minimal single line
- Links: Tricktionary Coaching (hub), tricktionary.com, progearservice.com
- Copyright: 2026 Tricktionary GmbH

## Technical Implementation

### Files

| File | Purpose |
|------|---------|
| `private-coaching/index.html` | Landing page (single HTML file, all CSS inline) |
| `backend-php/api/private-application.php` | API endpoint for application submissions |
| `backend-php/api/schema.sql` | Updated with new table |
| `deploy-coaching.sh` | Updated with `deploy_private()` function |
| `coaching-hub/index.html` | VIP card updated to link to `/private-coaching/` |

### Database

New table `private_coaching_applications`:

```sql
CREATE TABLE IF NOT EXISTS private_coaching_applications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    email VARCHAR(255) NOT NULL,
    location VARCHAR(500),
    timeframe VARCHAR(255),
    group_size VARCHAR(32),
    riding_level VARCHAR(64),
    message TEXT,
    audio_file VARCHAR(255),
    video_file VARCHAR(255),
    status VARCHAR(32) DEFAULT 'new',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    reviewed_at DATETIME
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### API Endpoint

`POST /video-coaching/api/private-application.php`

- Accepts multipart/form-data (for audio/video file uploads) or JSON (text-only submissions)
- Validates required fields (name, email)
- Stores audio/video files in `uploads/private-applications/`
- Inserts row into `private_coaching_applications`
- Returns `{ "ok": true }` on success
- Sends email notification to Michi (rossi@tricktionary.com) on each new application using the existing SMTP config and email helper
- Honeypot field (`website`) for basic bot protection - reject if filled
- Frontend `fetch()` targets absolute path `/video-coaching/api/private-application.php` (API lives under the existing backend, not under `/private-coaching/`)

### File Upload Constraints

- Max audio size: 10MB
- Max video size: 50MB
- Accepted audio MIME types: `audio/webm`, `audio/ogg`, `audio/mp4`, `audio/mpeg`
- Accepted video MIME types: `video/webm`, `video/mp4`
- Server PHP config requires `upload_max_filesize=64M` and `post_max_size=70M` (document as init step)
- Filename sanitization via existing `safeName()` helper
- Frontend enforces size limits before upload (show error if recording exceeds limit)

### Audio/Video Recording

Reuses the MediaRecorder pattern from `success.html`:

- Audio: `navigator.mediaDevices.getUserMedia({ audio: true })`
- Video: `navigator.mediaDevices.getUserMedia({ audio: true, video: { facingMode: 'user' } })`
- Recording stored as Blob, uploaded as part of form submission
- Playback preview before submission
- Feature detection: check for `MediaRecorder` in window and `getUserMedia` support. If unavailable (older browsers, denied permissions), hide the record buttons entirely via `style.display = 'none'`. Text form remains fully functional without media.
- Mobile Safari note: video MediaRecorder has limited support. Audio generally works. If video capture fails, show a brief message and hide the video option gracefully.

### Deploy

Add to `deploy-coaching.sh`:

```bash
deploy_private() {
  echo "[private] Deploying private coaching landing page..."
  ssh "$SERVER" "mkdir -p $WEB_ROOT/private-coaching"
  scp "$PROJECT_DIR/private-coaching/index.html" "$SERVER:$WEB_ROOT/private-coaching/index.html"
  echo "  Private coaching page deployed."
}
```

Add `private` case to the switch and include in `all`.

**Init step** (first deploy only):
```bash
ssh "$SERVER" "mkdir -p $WEB_ROOT/video-coaching/uploads/private-applications && chmod 755 $WEB_ROOT/video-coaching/uploads/private-applications"
```

Upload directory lives under `/video-coaching/uploads/private-applications/` (co-located with existing upload infrastructure, not under `/private-coaching/`).

Note: `deploy_private` only deploys the landing page HTML. The API endpoint is deployed by `deploy_backend` (which rsync's the entire `api/` directory). Running `bash deploy-coaching.sh all` covers both.

### Hub Page Update

Replace the VIP card's inline inquiry form with a link to `/private-coaching/`:

- Keep the premium gold card styling (`.vip-card`)
- CTA becomes: `<a href="/private-coaching/" class="vip-cta">Learn More</a>` styled as gold gradient button
- Remove the inline waitlist form, "Send Inquiry" button, and success message

## What This Is Not

- Not a booking system (all conversion happens in personal conversation after application)
- Not a pricing page (no prices shown, inquiry-only)
- Not a multi-page site (single page is sufficient for an application funnel)
- Not a blog or content-heavy page (every word earns its place)
- Not an admin dashboard (Michi receives email notifications on new applications; a dedicated admin view can be added later if volume warrants it)

## Verification

1. Page renders correctly at all breakpoints (480px, 768px, 1200px)
2. Audio recording works (permissions prompt, record, playback, submit)
3. Video recording works (camera prompt, preview, re-record, submit)
4. Media recording gracefully degrades when unsupported or denied
5. Form submission stores data in database correctly
6. Email notification sent to Michi on submission
7. File uploads respect size limits (frontend + backend validation)
8. Honeypot field rejects bot submissions
9. Success state displays after submission
10. Hub VIP card links to /private-coaching/ correctly
11. Deploy script includes private coaching page
12. Scroll fade animations trigger on scroll
