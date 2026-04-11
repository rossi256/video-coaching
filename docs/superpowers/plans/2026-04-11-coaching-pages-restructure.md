# Coaching Pages Restructure Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Restructure coaching pages - update VIP coaching with background/contact changes, create Private Coaching page, add hub cards for Private Coaching and Peak Performance Coaching.

**Architecture:** Static HTML pages following existing patterns from coaching-hub. VIP page gets hero background changes, gear links, and three contact paths. Private Coaching is a new simpler page at `/private-coaching/`. Hub gets two new cards. Waitlist API extended for new type.

**Tech Stack:** HTML, CSS, vanilla JS. PHP backend (waitlist API). Unsplash for yacht image.

---

### Task 1: Download New Yacht Aerial Image

**Files:**
- Create: `assets/experience-yacht-aerial.jpg`

- [ ] **Step 1: Download yacht aerial image from Unsplash**

Browse these Unsplash candidates and download the best aerial yacht shot with lots of ocean and yacht on one side:
- https://unsplash.com/photos/aerial-photography-of-yacht-in-body-of-water-re2Meno1UIU
- https://unsplash.com/photos/nxRwke3Mr7Q
- https://unsplash.com/photos/vnfpcINVllI

Download the chosen image at high resolution. Save to `assets/experience-yacht-aerial.jpg`. Optimize for web (compress to ~200-400KB, keep at least 1920px wide).

```bash
# Download from Unsplash (use the direct download URL from the chosen photo)
# Example with the Cris Tagupa aerial yacht photo:
curl -L "https://unsplash.com/photos/re2Meno1UIU/download?force=true" -o assets/experience-yacht-aerial.jpg

# Optimize if imagemagick available:
convert assets/experience-yacht-aerial.jpg -resize 2400x -quality 82 assets/experience-yacht-aerial.jpg
```

- [ ] **Step 2: Commit**

```bash
git add assets/experience-yacht-aerial.jpg
git commit -m "assets: add aerial yacht image for VIP coaching page"
```

---

### Task 2: Update VIP Coaching Page - Hero Background

**Files:**
- Modify: `vip-coaching/index.html`

Move the Maldives background image to be behind the hero section headline, so visitors see the scenic background immediately on page load.

- [ ] **Step 1: Add hero background image styling**

In the `<style>` section, modify the `.hero` class to include the Maldives background image. Replace the existing `.hero` block (lines 47-56) and its `::before`/`::after` pseudo-elements (lines 58-80):

```css
/* ─── HERO ─── */
.hero {
  min-height: 100vh;
  display: flex;
  align-items: center;
  justify-content: center;
  text-align: center;
  position: relative;
  overflow: hidden;
  padding: 80px 24px;
}

.hero-bg {
  position: absolute;
  inset: -20% 0;
  background-image: url('/assets/experience-maldives.jpg');
  background-size: cover;
  background-position: center 40%;
  background-repeat: no-repeat;
  will-change: transform;
  transition: transform 0.1s linear;
}

.hero::after {
  content: '';
  position: absolute;
  inset: 0;
  background: linear-gradient(135deg, rgba(6, 10, 20, 0.78) 0%, rgba(6, 10, 20, 0.50) 50%, rgba(6, 10, 20, 0.68) 100%);
  pointer-events: none;
}
```

- [ ] **Step 2: Update hero HTML to include background div**

Replace the hero section HTML (line 732-741):

```html
<!-- ═══ HERO ═══ -->
<section class="hero">
  <div class="hero-bg" data-parallax></div>
  <div class="hero-content fade-in">
    <span class="hero-badge">VIP Private Coaching</span>
    <h1>Your Coach. Your Location. Your Progression.</h1>
    <p class="hero-sub">The world's best wingfoil coach comes to you - with the best gear, a custom plan, and full immersion coaching for you, your family, or your crew. Anywhere in the world.</p>
    <p class="hero-host">by Michi Rossmeier</p>
    <a href="#apply" class="cta-btn">Apply for Your VIP Experience</a>
  </div>
</section>
```

- [ ] **Step 3: Remove the standalone Maldives experience-hero section**

The Maldives section (lines 743-754) is no longer needed as a separate section since the image is now in the hero. Replace it with a text-only experience block that flows naturally after the hero:

```html
<!-- ═══ YOUR WORLD, YOUR SCHEDULE ═══ -->
<section class="experience-block">
  <div class="container fade-in">
    <p class="section-label">The Experience</p>
    <h2 class="section-title">Your World. Your Schedule.</h2>
    <div class="experience-prose">
      <p>Michi comes to you. Your location, your schedule, your conditions. Whether it's a week in the Maldives, a long weekend in the Mediterranean, or a focused stretch at your home spot - this is coaching built entirely around you.</p>
      <p>Solo, with your partner, your kids, or a group of friends. Adults and children of all ages and levels - everyone gets a tailored experience. No group schedules. No compromises. Just you, your coach, and the water.</p>
    </div>
  </div>
</section>
```

- [ ] **Step 4: Commit**

```bash
git add vip-coaching/index.html
git commit -m "feat(vip): move Maldives background behind hero, update messaging for families"
```

---

### Task 3: Update VIP Coaching Page - Yacht Image & Gear Section

**Files:**
- Modify: `vip-coaching/index.html`

- [ ] **Step 1: Update yacht background image reference**

In the CSS, change `.experience-hero--yacht .experience-hero-bg` (line 211-213) to use the new aerial image:

```css
.experience-hero--yacht .experience-hero-bg {
  background-image: url('/assets/experience-yacht-aerial.jpg');
  background-position: center;
}
```

- [ ] **Step 2: Update the gear section text and add links**

Replace the World-Class Equipment section content (lines 786-796) with updated text, Duotone/Pro Gear Service links, and gear delivery option:

```html
<!-- ═══ WORLD-CLASS EQUIPMENT ═══ -->
<section class="experience-hero experience-hero--yacht">
  <div class="experience-hero-bg" data-parallax></div>
  <div class="container fade-in">
    <p class="section-label">World-Class Equipment</p>
    <h2 class="section-title">Ride the Best Gear in the World</h2>
    <div class="experience-prose">
      <p>Michi brings the latest top-level <a href="https://www.duotone.com" target="_blank" rel="noopener" class="experience-highlight" style="text-decoration: underline; text-underline-offset: 3px;">Duotone</a> equipment - wings, boards, and foils at the highest performance level. Supported by <a href="https://progearservice.com" target="_blank" rel="noopener" class="experience-highlight" style="text-decoration: underline; text-underline-offset: 3px;">Pro Gear Service Tarifa</a>, every piece of gear is race-tuned, freshly serviced, and matched to your riding and the conditions.</p>
      <p>No need to travel with your own equipment. No compromises on quality. You show up, and everything is ready - dialed in by someone who understands gear as deeply as technique.</p>
      <p style="color: var(--gold-light); font-weight: 500;">Michi can bring the full equipment setup to your location if desired - just let him know when you apply.</p>
    </div>
  </div>
</section>
```

- [ ] **Step 3: Commit**

```bash
git add vip-coaching/index.html
git commit -m "feat(vip): new yacht image, link Duotone and Pro Gear Service, gear delivery option"
```

---

### Task 4: Update VIP Coaching Page - Contact Options

**Files:**
- Modify: `vip-coaching/index.html`

Add three contact paths at the bottom: Apply form (existing), Schedule a Call (TidyCal), Message Michi (WhatsApp).

- [ ] **Step 1: Add CSS for contact options section**

Add these styles before the `/* ─── FOOTER ─── */` comment in the `<style>` block:

```css
/* ─── CONTACT OPTIONS ─── */
.contact-options {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 1.5rem;
  margin-bottom: 3rem;
}

.contact-option {
  text-align: center;
  padding: 2rem 1.5rem;
  background: rgba(255, 255, 255, 0.03);
  border: 1px solid rgba(255, 255, 255, 0.06);
  border-radius: 16px;
  text-decoration: none;
  color: inherit;
  transition: all 0.3s ease;
}

.contact-option:hover {
  border-color: rgba(212, 168, 67, 0.25);
  transform: translateY(-3px);
  background: rgba(212, 168, 67, 0.04);
}

.contact-option-icon {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 48px;
  height: 48px;
  margin: 0 auto 1rem;
  border-radius: 12px;
  background: rgba(212, 168, 67, 0.1);
}

.contact-option h3 {
  font-family: 'Playfair Display', serif;
  font-size: 1.15rem;
  font-weight: 600;
  color: var(--gold-light);
  margin-bottom: 0.5rem;
}

.contact-option p {
  font-size: 0.85rem;
  color: var(--white-dim);
  line-height: 1.6;
}

.contact-option--active {
  border-color: rgba(212, 168, 67, 0.2);
  background: rgba(212, 168, 67, 0.03);
}

@media (max-width: 768px) {
  .contact-options {
    grid-template-columns: 1fr;
  }
}
```

- [ ] **Step 2: Restructure the apply section with three contact paths**

Replace the entire apply section (lines 856-978) with a new structure that offers three ways to connect, with the form shown when "Apply" is selected:

```html
<!-- ═══ GET IN TOUCH ═══ -->
<section class="apply-section" id="apply">
  <div class="container fade-in">
    <div class="apply-wrap" style="max-width: 720px;">
      <p class="section-label">Get Started</p>
      <h2 class="section-title">Ready for Your VIP Experience?</h2>
      <p class="apply-subtitle" style="max-width: 640px;">Choose how you'd like to connect with Michi. Whether you want to apply directly, talk it through on a call, or send a quick message - there's no wrong way to start.</p>

      <div class="contact-options">
        <!-- Apply -->
        <div class="contact-option contact-option--active" style="cursor: pointer;" onclick="showApplyForm()">
          <div class="contact-option-icon">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#d4a843" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
          </div>
          <h3>Apply</h3>
          <p>Tell Michi about yourself and what you're looking for</p>
        </div>

        <!-- Schedule a Call -->
        <a href="https://tidycal.com/michirossmeier/free-consultation-call-1" target="_blank" rel="noopener" class="contact-option">
          <div class="contact-option-icon">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#d4a843" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
          </div>
          <h3>Schedule a Call</h3>
          <p>Book a free consultation to talk through your ideas</p>
        </a>

        <!-- WhatsApp -->
        <a href="https://wa.me/4369913909040?text=Hi%20Michi%2C%20I%27m%20interested%20in%20your%20VIP%20coaching%20experience." target="_blank" rel="noopener" class="contact-option">
          <div class="contact-option-icon">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="#d4a843"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>
          </div>
          <h3>Message Michi Directly</h3>
          <p>Send a direct message and start the conversation</p>
        </a>
      </div>

      <!-- Application Form (shown by default, togglable) -->
      <div id="apply-form-wrap">
        <form id="application-form" onsubmit="submitApplication(event)">
          <!-- Honeypot -->
          <div class="ohnohoney">
            <input type="text" name="website" id="hp-website" tabindex="-1" autocomplete="off">
          </div>

          <div class="form-row">
            <div class="form-group">
              <label>Name *</label>
              <input type="text" id="app-name" required placeholder="Your name">
            </div>
            <div class="form-group">
              <label>Email *</label>
              <input type="email" id="app-email" required placeholder="Your email">
            </div>
          </div>

          <div class="form-row">
            <div class="form-group">
              <label>Preferred Location</label>
              <input type="text" id="app-location" placeholder="e.g. Maldives, Caribbean, Mediterranean...">
            </div>
            <div class="form-group">
              <label>Time of Year</label>
              <input type="text" id="app-timeframe" placeholder="e.g. Spring 2026, flexible...">
            </div>
          </div>

          <div class="form-row">
            <div class="form-group">
              <label>Number of Riders</label>
              <select id="app-group-size">
                <option value="">Select...</option>
                <option value="1">Just me</option>
                <option value="2">2 (couple / partner)</option>
                <option value="3-4">3-4 (family / small group)</option>
                <option value="5+">5+ (larger group)</option>
              </select>
            </div>
            <div class="form-group">
              <label>Riding Level</label>
              <select id="app-level">
                <option value="">Select...</option>
                <option value="Beginner">Beginner</option>
                <option value="Intermediate">Intermediate</option>
                <option value="Advanced">Advanced</option>
                <option value="Mixed group">Mixed group (different levels)</option>
              </select>
            </div>
          </div>

          <div class="form-group">
            <label>Tell Michi about yourself</label>
            <textarea id="app-message" placeholder="Who's joining, what you're looking for, any special requests..."></textarea>
          </div>

          <div class="form-divider"></div>

          <p class="media-label">Optional: record a voice or video message for Michi</p>

          <div id="audio-section">
            <div class="media-row">
              <button type="button" id="audio-btn" class="media-btn" onclick="toggleAudioRecording()">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z"/><path d="M19 10v2a7 7 0 0 1-14 0v-2"/><line x1="12" y1="19" x2="12" y2="23"/><line x1="8" y1="23" x2="16" y2="23"/></svg>
                Record Audio Message
              </button>
              <span id="audio-timer" class="rec-timer"></span>
            </div>
            <div id="audio-preview" class="media-preview" style="display:none;">
              <audio id="audio-player" controls></audio>
              <div class="media-actions">
                <button type="button" onclick="reRecordAudio()">Re-record</button>
                <span id="audio-status" class="media-status"></span>
              </div>
              <p id="audio-size-warning" class="size-warning">Audio file exceeds 10MB limit. Please record a shorter message.</p>
            </div>
          </div>

          <div id="video-section">
            <div class="media-row">
              <button type="button" id="video-btn" class="media-btn" onclick="toggleVideoRecording()">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="23 7 16 12 23 17 23 7"/><rect x="1" y="5" width="15" height="14" rx="2" ry="2"/></svg>
                Record Video Message
              </button>
              <span id="video-timer" class="rec-timer"></span>
            </div>
            <div id="video-preview" class="media-preview" style="display:none;">
              <video id="video-player" controls playsinline></video>
              <div class="media-actions">
                <button type="button" onclick="reRecordVideo()">Re-record</button>
                <span id="video-status" class="media-status"></span>
              </div>
              <p id="video-size-warning" class="size-warning">Video file exceeds 50MB limit. Please record a shorter message.</p>
            </div>
          </div>

          <div id="camera-preview-wrap" style="display:none; margin-bottom: 1rem;">
            <video id="camera-preview" autoplay muted playsinline style="width:100%; max-height:280px; border-radius:8px; background:#000;"></video>
          </div>

          <button type="submit" id="submit-btn" class="cta-btn submit-btn">Submit Application</button>
        </form>

        <div id="success-state" class="success-message" style="display:none;">
          <h3>Thank you.</h3>
          <p>Michi will review your application and be in touch personally.</p>
        </div>
      </div>
    </div>
  </div>
</section>
```

- [ ] **Step 3: Add showApplyForm function**

Add this function to the `<script>` block, before the existing `submitApplication` function:

```javascript
function showApplyForm() {
  var wrap = document.getElementById('apply-form-wrap');
  if (wrap) {
    wrap.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }
}
```

- [ ] **Step 4: Commit**

```bash
git add vip-coaching/index.html
git commit -m "feat(vip): add three contact paths - apply form, TidyCal call, WhatsApp"
```

---

### Task 5: Create Private Coaching Page

**Files:**
- Create: `private-coaching/index.html`

Build a simpler, warmer page for private coaching at Michi's locations (Tarifa / Lake Garda). Less luxury, more personal and accessible. EUR 250 per session, framed around progression not hours.

- [ ] **Step 1: Create the private coaching page**

Create `private-coaching/index.html` with:
- Same design system (Playfair Display + Inter, navy/gold/teal palette) but lighter feel
- No parallax hero images - cleaner, simpler layout
- Hero: "Private Coaching with Michi" - come to Tarifa or Lake Garda
- Section: What to expect (1-on-1 on-water coaching, video analysis, gear advice)
- Section: Locations (Tarifa / Lake Garda cards with brief descriptions)
- Section: Pricing - EUR 250 per session, framed as "invest in your progression"
- Section: Your Coach (reuse Michi bio pattern from VIP)
- Contact: TidyCal booking button + simple form (name, email, location preference Tarifa/Garda, message)
- WhatsApp link
- Footer matching VIP page

The page should use the VIP page's color scheme (`--navy: #060a14`, `--gold: #d4a843`, etc.) and font imports, but with a simpler structure - no parallax backgrounds, no experience-hero sections. More like a clean landing page.

Key content differences from VIP:
- Client travels to Michi (not the other way around)
- Client brings their own gear
- EUR 250 per session
- Tarifa and Lake Garda as locations
- Booking (not application) - lower barrier to entry
- TidyCal embed or link for direct booking

The form should POST to `/video-coaching/api/private-inquiry.php` (Task 6 creates this endpoint).

Full HTML file content:

```html
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Private Coaching - Michi Rossmeier</title>
<meta name="description" content="Private wingfoil coaching sessions with Michi Rossmeier in Tarifa or Lake Garda. One-on-one coaching focused on your progression.">
<meta property="og:type" content="website">
<meta property="og:title" content="Private Coaching - Michi Rossmeier">
<meta property="og:description" content="Private wingfoil coaching sessions with Michi Rossmeier in Tarifa or Lake Garda.">
<meta property="og:url" content="https://coaching.tricktionary.com/private-coaching/">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;500;600;700;800&family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<style>
  :root {
    --navy: #060a14;
    --navy-card: #111827;
    --gold: #d4a843;
    --gold-light: #f0d078;
    --teal: #2dd4bf;
    --white: #f1f5f9;
    --white-dim: #cbd5e1;
    --gray: #94a3b8;
  }

  * { margin: 0; padding: 0; box-sizing: border-box; }
  html { scroll-behavior: smooth; }

  body {
    font-family: 'Inter', sans-serif;
    background: var(--navy);
    color: var(--white);
    line-height: 1.6;
    overflow-x: hidden;
    -webkit-font-smoothing: antialiased;
  }

  .container {
    max-width: 900px;
    margin: 0 auto;
    padding: 0 24px;
  }

  /* ─── HERO ─── */
  .hero {
    min-height: 70vh;
    display: flex;
    align-items: center;
    justify-content: center;
    text-align: center;
    position: relative;
    padding: 100px 24px 80px;
  }

  .hero::before {
    content: '';
    position: absolute;
    top: -20%;
    left: 50%;
    transform: translateX(-50%);
    width: 120%;
    height: 80%;
    background: radial-gradient(ellipse at center, rgba(45, 212, 191, 0.06) 0%, rgba(45, 212, 191, 0.02) 35%, transparent 65%);
    pointer-events: none;
  }

  .hero-content {
    position: relative;
    z-index: 1;
    max-width: 720px;
  }

  .hero-badge {
    display: inline-block;
    font-size: 0.7rem;
    font-weight: 600;
    letter-spacing: 0.18em;
    text-transform: uppercase;
    color: var(--teal);
    border: 1px solid rgba(45, 212, 191, 0.35);
    padding: 6px 18px;
    border-radius: 100px;
    margin-bottom: 2rem;
  }

  .hero h1 {
    font-family: 'Playfair Display', serif;
    font-size: clamp(2rem, 4.5vw, 3.2rem);
    font-weight: 700;
    line-height: 1.15;
    margin-bottom: 1.2rem;
    color: var(--white);
  }

  .hero-sub {
    font-size: 1.05rem;
    font-weight: 300;
    color: var(--white-dim);
    margin-bottom: 1.5rem;
    line-height: 1.7;
  }

  .hero-price {
    font-size: 0.9rem;
    color: var(--teal);
    font-weight: 500;
    margin-bottom: 2.5rem;
  }

  .cta-btn {
    display: inline-block;
    font-family: 'Inter', sans-serif;
    font-size: 0.9rem;
    font-weight: 600;
    color: var(--navy);
    background: linear-gradient(135deg, var(--teal) 0%, #5eead4 100%);
    padding: 14px 36px;
    border-radius: 8px;
    text-decoration: none;
    transition: all 0.3s ease;
    border: none;
    cursor: pointer;
  }

  .cta-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 12px 40px rgba(45, 212, 191, 0.25);
  }

  .cta-btn--gold {
    background: linear-gradient(135deg, var(--gold) 0%, var(--gold-light) 100%);
    box-shadow: none;
  }

  .cta-btn--gold:hover {
    box-shadow: 0 12px 40px rgba(212, 168, 67, 0.25);
  }

  /* ─── SECTIONS ─── */
  section {
    padding: 80px 24px;
    position: relative;
  }

  .section-label {
    font-size: 0.65rem;
    font-weight: 600;
    letter-spacing: 0.2em;
    text-transform: uppercase;
    color: var(--teal);
    margin-bottom: 1rem;
  }

  .section-title {
    font-family: 'Playfair Display', serif;
    font-size: clamp(1.6rem, 3vw, 2.2rem);
    font-weight: 700;
    color: var(--white);
    margin-bottom: 1.5rem;
    line-height: 1.2;
  }

  .section-prose p {
    font-size: 1rem;
    color: var(--white-dim);
    line-height: 1.8;
    margin-bottom: 1.5rem;
    max-width: 680px;
  }

  /* ─── FEATURE GRID ─── */
  .feature-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1.5rem;
    margin-top: 2rem;
  }

  .feature-card {
    background: rgba(255, 255, 255, 0.03);
    border: 1px solid rgba(255, 255, 255, 0.06);
    border-radius: 12px;
    padding: 1.5rem;
  }

  .feature-card h4 {
    font-family: 'Playfair Display', serif;
    font-size: 1.05rem;
    font-weight: 600;
    color: var(--teal);
    margin-bottom: 0.5rem;
  }

  .feature-card p {
    font-size: 0.85rem;
    color: var(--white-dim);
    line-height: 1.7;
  }

  /* ─── LOCATION CARDS ─── */
  .location-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1.5rem;
    margin-top: 2rem;
  }

  .location-card {
    background: var(--navy-card);
    border: 1px solid rgba(255, 255, 255, 0.05);
    border-radius: 16px;
    padding: 2rem;
    transition: all 0.3s ease;
  }

  .location-card:hover {
    border-color: rgba(45, 212, 191, 0.2);
    transform: translateY(-3px);
  }

  .location-card h3 {
    font-family: 'Playfair Display', serif;
    font-size: 1.4rem;
    font-weight: 700;
    color: var(--white);
    margin-bottom: 0.3rem;
  }

  .location-card .location-country {
    font-size: 0.8rem;
    color: var(--teal);
    font-weight: 500;
    margin-bottom: 1rem;
  }

  .location-card p {
    font-size: 0.88rem;
    color: var(--white-dim);
    line-height: 1.7;
  }

  /* ─── PRICING ─── */
  .pricing-block {
    text-align: center;
    max-width: 560px;
    margin: 0 auto;
  }

  .pricing-amount {
    font-family: 'Playfair Display', serif;
    font-size: 3rem;
    font-weight: 700;
    color: var(--white);
    margin-bottom: 0.3rem;
  }

  .pricing-unit {
    font-size: 0.9rem;
    color: var(--teal);
    font-weight: 500;
    margin-bottom: 1.5rem;
  }

  .pricing-note {
    font-size: 0.9rem;
    color: var(--white-dim);
    line-height: 1.7;
  }

  /* ─── COACH ─── */
  .coach-grid {
    display: grid;
    grid-template-columns: 240px 1fr;
    gap: 2.5rem;
    align-items: start;
  }

  .coach-portrait {
    width: 100%;
    aspect-ratio: 3/4;
    object-fit: cover;
    border-radius: 16px;
    border: 1px solid rgba(255, 255, 255, 0.08);
  }

  .coach-name {
    font-family: 'Playfair Display', serif;
    font-size: 1.6rem;
    font-weight: 700;
    color: var(--white);
    margin-bottom: 0.3rem;
  }

  .coach-role {
    font-size: 0.85rem;
    color: var(--teal);
    font-weight: 500;
    margin-bottom: 1.2rem;
  }

  .coach-bio p {
    font-size: 0.9rem;
    color: var(--white-dim);
    line-height: 1.75;
    margin-bottom: 1rem;
  }

  /* ─── CONTACT ─── */
  .contact-section {
    position: relative;
  }

  .contact-wrap {
    max-width: 640px;
    margin: 0 auto;
  }

  .contact-actions {
    display: flex;
    gap: 1rem;
    margin-bottom: 2.5rem;
    flex-wrap: wrap;
  }

  .contact-subtitle {
    font-size: 0.95rem;
    color: var(--white-dim);
    margin-bottom: 2rem;
  }

  .form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1rem;
  }

  .form-group {
    margin-bottom: 1.2rem;
  }

  .form-group label {
    display: block;
    font-size: 0.72rem;
    font-weight: 600;
    color: var(--teal);
    text-transform: uppercase;
    letter-spacing: 0.06em;
    margin-bottom: 0.4rem;
  }

  .form-group input,
  .form-group select,
  .form-group textarea {
    width: 100%;
    background: rgba(255, 255, 255, 0.04);
    border: 1px solid rgba(255, 255, 255, 0.1);
    border-radius: 8px;
    color: var(--white);
    padding: 12px 16px;
    font-family: 'Inter', sans-serif;
    font-size: 0.88rem;
    outline: none;
    transition: border-color 0.2s, background 0.2s;
  }

  .form-group input:focus,
  .form-group select:focus,
  .form-group textarea:focus {
    border-color: var(--teal);
    background: rgba(45, 212, 191, 0.04);
  }

  .form-group input::placeholder,
  .form-group textarea::placeholder {
    color: #475569;
  }

  .form-group select option {
    background: var(--navy-card);
    color: var(--white);
  }

  .form-group textarea {
    resize: vertical;
    min-height: 100px;
  }

  .ohnohoney {
    position: absolute;
    left: -9999px;
    opacity: 0;
    height: 0;
    width: 0;
    overflow: hidden;
  }

  .submit-btn {
    display: block;
    width: 100%;
    margin-top: 1.5rem;
  }

  .submit-btn:disabled {
    opacity: 0.6;
    cursor: not-allowed;
  }

  .success-message {
    text-align: center;
    padding: 3rem 1rem;
  }

  .success-message h3 {
    font-family: 'Playfair Display', serif;
    font-size: 1.4rem;
    font-weight: 600;
    color: var(--teal);
    margin-bottom: 1rem;
  }

  .success-message p {
    font-size: 0.95rem;
    color: var(--white-dim);
    line-height: 1.7;
  }

  /* WhatsApp link */
  .wa-link {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    font-size: 0.85rem;
    color: var(--white-dim);
    text-decoration: none;
    padding: 10px 20px;
    border: 1px solid rgba(255, 255, 255, 0.1);
    border-radius: 8px;
    transition: all 0.2s;
  }

  .wa-link:hover {
    border-color: rgba(37, 211, 102, 0.4);
    color: #25d366;
  }

  /* ─── FOOTER ─── */
  .footer {
    padding: 2rem 24px;
    text-align: center;
    border-top: 1px solid rgba(255, 255, 255, 0.05);
  }

  .footer-links {
    display: flex;
    justify-content: center;
    gap: 1.5rem;
    flex-wrap: wrap;
    margin-bottom: 0.8rem;
  }

  .footer-links a {
    font-size: 0.78rem;
    color: var(--gray);
    text-decoration: none;
    transition: color 0.2s;
  }

  .footer-links a:hover { color: var(--white); }

  .footer-copy {
    font-size: 0.72rem;
    color: #475569;
  }

  /* ─── SCROLL FADE ─── */
  .fade-in {
    opacity: 0;
    transform: translateY(20px);
    transition: opacity 0.6s ease, transform 0.6s ease;
  }

  .fade-in.visible {
    opacity: 1;
    transform: translateY(0);
  }

  /* ─── RESPONSIVE ─── */
  @media (max-width: 768px) {
    section { padding: 60px 20px; }
    .feature-grid, .location-grid { grid-template-columns: 1fr; }
    .coach-grid { grid-template-columns: 1fr; gap: 2rem; }
    .coach-portrait { max-width: 200px; margin: 0 auto; }
    .form-row { grid-template-columns: 1fr; }
    .contact-actions { flex-direction: column; }
  }

  @media (max-width: 480px) {
    section { padding: 50px 16px; }
    .hero { padding: 80px 16px 60px; }
  }
</style>
</head>
<body>

<!-- ═══ HERO ═══ -->
<section class="hero">
  <div class="hero-content fade-in">
    <span class="hero-badge">Private Coaching</span>
    <h1>Come Train With Michi</h1>
    <p class="hero-sub">Private one-on-one wingfoil coaching in Tarifa or at Lake Garda. Bring your gear, bring your goals - leave riding at a different level.</p>
    <p class="hero-price">&euro;250 per session</p>
    <a href="#book" class="cta-btn">Book a Session</a>
  </div>
</section>

<!-- ═══ WHAT TO EXPECT ═══ -->
<section>
  <div class="container fade-in">
    <p class="section-label">What to Expect</p>
    <h2 class="section-title">Focused Coaching, Real Progress</h2>
    <div class="section-prose">
      <p>Every session is built around your riding. Michi works with you on the water in real time, then reviews video together after. You'll leave each session with a clear picture of what changed and what to work on next.</p>
    </div>
    <div class="feature-grid">
      <div class="feature-card">
        <h4>On-Water Coaching</h4>
        <p>Michi is on the water with you - real-time feedback on technique, positioning, and timing as it happens.</p>
      </div>
      <div class="feature-card">
        <h4>Video Analysis</h4>
        <p>Frame-by-frame review of your sessions. See exactly what's happening and what to change on your next run.</p>
      </div>
      <div class="feature-card">
        <h4>Gear Advice</h4>
        <p>Equipment setup optimized for your weight, style, and conditions. Bring your gear - Michi will help you get the most from it.</p>
      </div>
      <div class="feature-card">
        <h4>Progression Focus</h4>
        <p>You invest in your progress, not in hours. Each session is designed to unlock specific breakthroughs in your riding.</p>
      </div>
    </div>
  </div>
</section>

<!-- ═══ LOCATIONS ═══ -->
<section>
  <div class="container fade-in">
    <p class="section-label">Locations</p>
    <h2 class="section-title">Where to Find Michi</h2>
    <div class="location-grid">
      <div class="location-card">
        <h3>Tarifa</h3>
        <p class="location-country">Spain</p>
        <p>Europe's wind capital. Consistent conditions, warm water, and the perfect training ground for progression at any level. Michi's home base and where Pro Gear Service is headquartered.</p>
      </div>
      <div class="location-card">
        <h3>Lake Garda</h3>
        <p class="location-country">Italy</p>
        <p>Reliable thermal winds, flat water, and stunning alpine scenery. Ideal for focused technique work and perfect for riders who prefer lake conditions.</p>
      </div>
    </div>
  </div>
</section>

<!-- ═══ PRICING ═══ -->
<section>
  <div class="container fade-in">
    <div class="pricing-block">
      <p class="section-label">Investment</p>
      <h2 class="section-title">Your Progression, Your Pace</h2>
      <p class="pricing-amount">&euro;250</p>
      <p class="pricing-unit">per session</p>
      <p class="pricing-note">You're investing in breakthroughs, not in clock time. Each session is as long as it needs to be for you to progress. Multi-session packages available - ask Michi directly.</p>
    </div>
  </div>
</section>

<!-- ═══ YOUR COACH ═══ -->
<section>
  <div class="container fade-in">
    <p class="section-label">Your Coach</p>
    <div class="coach-grid">
      <img src="/assets/coach-portrait.jpg" alt="Michi Rossmeier" class="coach-portrait" loading="lazy">
      <div>
        <h2 class="coach-name">Michi Rossmeier</h2>
        <p class="coach-role">Founder, Tricktionary &middot; Pro Gear Service Tarifa</p>
        <div class="coach-bio">
          <p>Two decades of action sports coaching distilled into one person. Michi authored the Tricktionary book series - the definitive reference for kiteboarding and wingfoil technique - and has coached hundreds of riders from first flights to competition podiums.</p>
          <p>His coaching is precise, intuitive, and deeply personal. He sees what others miss, explains it in a way that clicks, and gives you the tools to change it on your next session.</p>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- ═══ BOOK / CONTACT ═══ -->
<section class="contact-section" id="book">
  <div class="container fade-in">
    <div class="contact-wrap">
      <p class="section-label">Book a Session</p>
      <h2 class="section-title">Ready to Train?</h2>

      <div class="contact-actions">
        <a href="https://tidycal.com/michirossmeier/free-consultation-call-1" target="_blank" rel="noopener" class="cta-btn">Schedule a Call</a>
        <a href="https://wa.me/4369913909040?text=Hi%20Michi%2C%20I%27m%20interested%20in%20private%20coaching." target="_blank" rel="noopener" class="wa-link">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>
          Message on WhatsApp
        </a>
      </div>

      <p class="contact-subtitle">Or send Michi a message and he'll get back to you:</p>

      <form id="inquiry-form" onsubmit="submitInquiry(event)">
        <div class="ohnohoney">
          <input type="text" name="website" id="hp-website" tabindex="-1" autocomplete="off">
        </div>

        <div class="form-row">
          <div class="form-group">
            <label>Name *</label>
            <input type="text" id="inq-name" required placeholder="Your name">
          </div>
          <div class="form-group">
            <label>Email *</label>
            <input type="email" id="inq-email" required placeholder="Your email">
          </div>
        </div>

        <div class="form-row">
          <div class="form-group">
            <label>Preferred Location</label>
            <select id="inq-location">
              <option value="">Select...</option>
              <option value="Tarifa">Tarifa, Spain</option>
              <option value="Lake Garda">Lake Garda, Italy</option>
              <option value="Either">Either / flexible</option>
            </select>
          </div>
          <div class="form-group">
            <label>Riding Level</label>
            <select id="inq-level">
              <option value="">Select...</option>
              <option value="Beginner">Beginner</option>
              <option value="Intermediate">Intermediate</option>
              <option value="Advanced">Advanced</option>
            </select>
          </div>
        </div>

        <div class="form-group">
          <label>Message</label>
          <textarea id="inq-message" placeholder="When are you planning to come? What are your goals?"></textarea>
        </div>

        <button type="submit" id="submit-btn" class="cta-btn cta-btn--gold submit-btn">Send Message</button>
      </form>

      <div id="success-state" class="success-message" style="display:none;">
        <h3>Message sent!</h3>
        <p>Michi will get back to you soon to arrange your session.</p>
      </div>
    </div>
  </div>
</section>

<!-- ═══ FOOTER ═══ -->
<footer class="footer">
  <div class="footer-links">
    <a href="/">Tricktionary Coaching</a>
    <a href="/vip-coaching/">VIP Experience</a>
    <a href="https://tricktionary.com" target="_blank" rel="noopener">tricktionary.com</a>
    <a href="https://progearservice.com" target="_blank" rel="noopener">progearservice.com</a>
  </div>
  <p class="footer-copy">&copy; 2026 Tricktionary GmbH</p>
</footer>

<script>
async function submitInquiry(e) {
  e.preventDefault();
  if (document.getElementById('hp-website').value) return;

  var name = document.getElementById('inq-name').value.trim();
  var email = document.getElementById('inq-email').value.trim();
  if (!name || !email) return;

  var btn = document.getElementById('submit-btn');
  btn.textContent = 'Sending...';
  btn.disabled = true;

  try {
    var r = await fetch('/video-coaching/api/private-inquiry.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        name: name,
        email: email,
        location: document.getElementById('inq-location').value,
        riding_level: document.getElementById('inq-level').value,
        message: document.getElementById('inq-message').value.trim()
      })
    });

    var d = await r.json();
    if (d.ok) {
      document.getElementById('inquiry-form').style.display = 'none';
      document.getElementById('success-state').style.display = 'block';
    } else {
      alert('Something went wrong. Please try again.');
      btn.textContent = 'Send Message';
      btn.disabled = false;
    }
  } catch (err) {
    alert('Network error. Please try again.');
    btn.textContent = 'Send Message';
    btn.disabled = false;
  }
}

// Scroll fade
var observer = new IntersectionObserver(function(entries) {
  entries.forEach(function(entry) {
    if (entry.isIntersecting) entry.target.classList.add('visible');
  });
}, { threshold: 0.1 });

document.querySelectorAll('.fade-in').forEach(function(el) { observer.observe(el); });
</script>
</body>
</html>
```

- [ ] **Step 2: Commit**

```bash
git add private-coaching/index.html
git commit -m "feat: create private coaching page - Tarifa/Garda, EUR 250/session"
```

---

### Task 6: Create Private Inquiry API Endpoint

**Files:**
- Create: `backend-php/api/private-inquiry.php`

- [ ] **Step 1: Check existing API patterns**

Read `backend-php/api/private-application.php` to understand the existing pattern for form submissions, then create a similar endpoint for private coaching inquiries.

- [ ] **Step 2: Create the private inquiry endpoint**

Create `backend-php/api/private-inquiry.php` following the same pattern as `private-application.php` but simpler (no file uploads):

```php
<?php
/**
 * Private Coaching Inquiry API
 * POST /api/private-inquiry.php
 * Body: { "name": "...", "email": "...", "location": "...", "riding_level": "...", "message": "..." }
 */
require_once __DIR__ . '/config.php';

setApiHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['error' => 'Method not allowed'], 405);
}

$data = getJsonBody();
$name    = trim($data['name'] ?? '');
$email   = trim($data['email'] ?? '');
$location = trim($data['location'] ?? '');
$level   = trim($data['riding_level'] ?? '');
$message = trim($data['message'] ?? '');

if (!$name || !$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    jsonResponse(['error' => 'Name and valid email required'], 400);
}

$db = getDb();

$stmt = $db->prepare('
    INSERT INTO private_inquiries (name, email, location, riding_level, message, created_at)
    VALUES (:name, :email, :location, :level, :message, NOW())
');
$stmt->execute([
    'name'     => $name,
    'email'    => $email,
    'location' => $location,
    'level'    => $level,
    'message'  => $message,
]);

jsonResponse(['ok' => true]);
```

- [ ] **Step 3: Create the database table**

Create a migration or add to the existing setup. Check how other tables are created in the project, then create the `private_inquiries` table:

```sql
CREATE TABLE IF NOT EXISTS private_inquiries (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    email VARCHAR(255) NOT NULL,
    location VARCHAR(100),
    riding_level VARCHAR(50),
    message TEXT,
    created_at DATETIME NOT NULL,
    INDEX idx_email (email),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

- [ ] **Step 4: Commit**

```bash
git add backend-php/api/private-inquiry.php
git commit -m "feat: add private coaching inquiry API endpoint"
```

---

### Task 7: Update Coaching Hub - Add Private Coaching Card

**Files:**
- Modify: `coaching-hub/index.html`

- [ ] **Step 1: Add Private Coaching program card to the "On the Water" section**

In `coaching-hub/index.html`, find the `.card-grid` in the "On the Water" section (around line 1524). The current grid has: Camps & Events card + VIP card. Add a Private Coaching card between them.

After the Camps & Events card closing `</a>` tag (around line 1545) and before the VIP card `<a href="/vip-coaching/"` (around line 1547), insert:

```html
<a href="/private-coaching/" class="program-card">
  <span class="program-icon">&#127947;</span>
  <h4>Private Coaching</h4>
  <p>One-on-one sessions with Michi at his home spots. Bring your gear, bring your goals.</p>
  <ul class="detail-list">
    <li>Private 1:1 on-water coaching</li>
    <li>Video analysis after every session</li>
    <li>Tarifa (Spain) or Lake Garda (Italy)</li>
    <li>&euro;250 per session</li>
  </ul>
  <span class="program-tag program-tag--live">Book Now</span>
</a>
```

- [ ] **Step 2: Update card grid to 3 columns**

The `.card-grid` currently uses `grid-template-columns: repeat(2, 1fr)`. With 3 cards in the on-the-water tier, we need to check if the grid handles 3 items well. If the VIP card spans full width below, keep the grid at 2 columns (the 3rd card wraps). If all 3 should be equal, update to `repeat(3, 1fr)`.

Given VIP is the premium standout, keep the grid at 2 columns. The VIP card already has special styling (`.vip-card`) that makes it stand out. Place Private Coaching and Camps side by side, VIP below spanning full width. To do this, add a CSS rule:

```css
.vip-card {
  grid-column: 1 / -1;
}
```

If this rule doesn't already exist, add it near the existing `.vip-card` styles.

- [ ] **Step 3: Commit**

```bash
git add coaching-hub/index.html
git commit -m "feat(hub): add Private Coaching card to on-the-water section"
```

---

### Task 8: Update Coaching Hub - Add Peak Performance Coaching Card

**Files:**
- Modify: `coaching-hub/index.html`

- [ ] **Step 1: Add Peak Performance Coaching card with waitlist**

After the on-the-water section (after the VIP card's closing tags), or in a new section below it, add a Peak Performance Coaching card. This uses the existing waitlist pattern.

Find a good placement - either as a new section or within the existing coaching tiers. Add:

```html
<!-- Peak Performance card - add within or after the on-the-water section -->
<a class="program-card" style="cursor: default; text-decoration: none; color: inherit;">
  <span class="coaching-badge coaching-badge--soon" style="position: relative; top: 0; left: 0; display: inline-block; margin-bottom: 1rem;">Coming Soon</span>
  <span class="program-icon">&#129504;</span>
  <h4>Peak Performance Coaching</h4>
  <p>Go beyond technique. Michi helps you unlock your mental game - flow states, focus, confidence, and breaking through the barriers that hold your riding back.</p>
  <ul class="detail-list">
    <li>Mental performance and flow state training</li>
    <li>Overcoming fears and mental blocks</li>
    <li>Breaking through plateaus and limitations</li>
    <li>NLP techniques for peak performance</li>
    <li>For athletes, professionals, and anyone ready to level up</li>
  </ul>
  <div id="waitlist-peak-container" onclick="event.preventDefault(); event.stopPropagation();">
    <button class="btn-waitlist" onclick="showWaitlistForm('peak')">
      Join the Waitlist
    </button>
    <div class="waitlist-form" id="waitlist-form-peak">
      <div class="waitlist-form-inner">
        <input type="text" id="waitlist-name-peak" placeholder="Your name">
        <input type="email" id="waitlist-email-peak" placeholder="Your email">
        <button onclick="submitWaitlist('peak')">Notify me</button>
      </div>
    </div>
    <div class="waitlist-success" id="waitlist-success-peak">
      Thanks! Michi will be in touch when Peak Performance Coaching launches.
    </div>
  </div>
</a>
```

- [ ] **Step 2: Update waitlist API to accept "peak" type**

In `backend-php/api/waitlist.php`, find the allowed types array and add `'peak'`:

```php
if (!in_array($type, ['1on1', 'monthly', 'vip', 'masterclass', 'peak'], true)) {
    jsonResponse(['error' => 'Invalid type'], 400);
}
```

- [ ] **Step 3: Commit**

```bash
git add coaching-hub/index.html backend-php/api/waitlist.php
git commit -m "feat(hub): add Peak Performance Coaching card with waitlist"
```

---

### Task 9: Deploy and Verify

**Files:** None (deployment)

- [ ] **Step 1: Deploy all changes**

```bash
bash deploy-coaching.sh all
```

- [ ] **Step 2: Verify live pages**

Check these URLs:
- https://coaching.tricktionary.com/vip-coaching/ - hero has Maldives background, new yacht image, Duotone/PGS links, three contact paths
- https://coaching.tricktionary.com/private-coaching/ - new private coaching page (not a redirect anymore)
- https://coaching.tricktionary.com/ - hub has Private Coaching card and Peak Performance card

- [ ] **Step 3: Test forms**

- Test the VIP application form still submits correctly
- Test the private coaching inquiry form submits correctly
- Test the peak performance waitlist form works
- Test TidyCal links open correctly
- Test WhatsApp links open correctly

- [ ] **Step 4: Update PROJECT-STATUS.json**

```json
{
  "lastDev": "Restructured coaching pages: VIP updates, new Private Coaching page, Peak Performance waitlist",
  "lastDevDate": "2026-04-11",
  "phase": "active",
  "milestone": "Coaching product lineup expansion",
  "blockedBy": null
}
```

- [ ] **Step 5: Commit status and push everything**

```bash
git add PROJECT-STATUS.json
git commit -m "chore: update project status after coaching pages restructure"
git push
```

- [ ] **Step 6: Update Forge**

```bash
~/.openclaw/workspace/scripts/forge-sync.sh video-coaching "Completed: VIP coaching page updates (hero bg, yacht image, contact options), new Private Coaching page (Tarifa/Garda), Peak Performance Coaching waitlist on hub"
```

---

### Future TODO (Not in this plan)

- **Custom TidyCal integration** - Build a higher-end booking experience instead of linking to TidyCal directly
- **Peak Performance Coaching page** - Full dedicated page when ready to launch
- **Private Coaching page** - Add location images (Tarifa, Lake Garda) once sourced
- **Review coaching hub navigation** - Ensure all products are discoverable and the hierarchy is clear
- **Review tricktionary product ecosystem** - Audit how all products connect and ensure consistent cross-linking
