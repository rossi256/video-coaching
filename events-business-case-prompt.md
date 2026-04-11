## Task: Add Events Business Case Module to Video Coaching Backend

**Context:**
The video-coaching backend lives at: /home/openclaw/.openclaw/workspace/projects/video-coaching/
It's a Node.js/Express backend already deployed at https://ari.tricktionary.com (or similar).

The existing backend has a coaching hub. We need to ADD a new section: an Events Business Case Calculator + Event Planning To-Do.

**Reference Business Case (from CSV):**
Structure: Revenue (attendees × price/person) minus itemized costs:
- Accommodation (EUR/person/night × nights × attendees)
- Rental (EUR/person × attendees)
- Coaching (EUR/day × days)
- Commission income (% offset)
- Extras (EUR/person)
- Marketing %
- Organizational hours × hourly rate
- Administration %

Result: Profit/Loss + break-even participants + margin %.

**What to build:**

### 1. Events Business Case Calculator (HTML page + optional DB storage)
- Input form: event name, location, dates, attendees (min/max), price per person
- Cost inputs: accommodation (per person per night, nights), rental, coaching rate + days, extras (itemized), org hours, marketing %, admin %
- Live calculation: revenue, total costs, profit, margin, break-even participants
- Scenario table: show results at min/base/max attendees
- Save/load multiple business cases (SQLite or JSON file storage)

### 2. Event Planning To-Do (per event)
A structured checklist tied to each business case/event with phases:
- Phase 1: Concept & Decision (go/no-go, set price, set min pax, find venue)
- Phase 2: Promotion (create content, email list, social posts, landing page)
- Phase 3: Logistics (accommodation booking, equipment rental, transfers)
- Phase 4: During Event (welcome, daily schedule, photos/video)
- Phase 5: Post Event (follow-up emails, testimonials, next event announcement)

Each to-do item: checkbox + responsible (me / AI / collaborator) + due date + status.

### 3. Event Types Reference
Add a static reference page showing Michi's 3 event models with rate/fee templates:
- Tricktionary Own Event (full income, own promo, own org)
- Partner Collab Event (commission model if partner brings client, full income if Michi promotes)
- Guest Coach / Surf Center Event (fixed daily coaching fee — set standard rates here)

### Technical Requirements
- Use the existing Express backend structure (add new routes in routes/)
- New frontend pages in public/ or coaching-hub/ folder following existing style
- SQLite for persistence (coaching.db already exists — add new tables)
- Protected by existing auth (same session)
- Clean, minimal UI — consistent with rest of backend

### Files to read first
- server.js (understand existing routes + auth)
- routes/ (existing route structure)  
- public/ or coaching-hub/ (existing frontend style)
- coaching.db schema (what tables exist)

### Deliverable
Working events section accessible at /events-planner or /events in the backend.
Include a link from the existing nav/sidebar.
