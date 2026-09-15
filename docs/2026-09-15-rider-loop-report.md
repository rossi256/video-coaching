# Rider loop on the coaching reply page - report (2026-09-15)

Branch `rider-loop`, merged into `main`. Michi's ask (see `2026-09-11-rider-loop-ideas.md`):
a rating, a limited way to ask back, and the credits in view - so the rider stays in the loop
without the coaching session growing into a chat.

## What changed

**Backend (`backend-php/api/`)**

- `helpers/rider-loop.php` (new) - the one place for the cap (`QUESTION_CAP = 2`), the hourly POST
  limit (`RIDER_POST_LIMIT = 10`), the body limits, the three tables (created on first use with
  `CREATE TABLE IF NOT EXISTS`, same pattern as `qa_email_samples` in `email.php` - no manual
  migration on staging or production), the rating / questions readers, the next-step card data
  and `riderLoopPayload()` that the reply JSON and every POST answer with.
- `migrations/2026-09-15-rider-loop.sql` - the same DDL, for the record.
- `reply.php` - `POST /api/reply/:token/rating` `{rating 1-5, comment?}` (a second post replaces
  the first, `ON DUPLICATE KEY UPDATE`) and `POST /api/reply/:token/question` `{text}` (max 1000
  chars, 400 with the message when the cap is reached; the count is done on the server inside the
  request, so two fast taps cannot make three). Both reject bodies over 4 KB (413) and more than
  10 POSTs per token per hour (429, `reply_post_log`). The GET now carries `rating`, `questions[]`,
  `questionsLeft`, `questionCap`, `nextStep`.
- `admin.php` - `list` joins the rating and counts open questions per row; `get` adds `rating`,
  `questions`, `question_cap`; new `answer-question` (`_action=answer-question&id=<submission>
  &question_id=<n>`, body `{answer}`) writes the answer and emails the rider once. Answering again
  replaces the text and emails again.
- `.htaccess` - the three routes above (`/api/reply/T/rating`, `/api/reply/T/question`,
  `/api/admin/submission/ID/question/QID/answer`).
- `helpers/email.php` - `sendQuestionToCoach()` (to `NOTIFY_EMAIL`, which is what the platform
  already uses for every coach notification - rossi@tricktionary.com on both environments) and
  `sendQuestionAnswered()` (to the rider, with the reply link). Both in the existing
  `riderEmailWrap` look, short, plain hyphen.

**Reply page (`website/static/reply.html`)**

- Under the video: "How was this coaching video?" - five star buttons (real `<button>`s with
  `role=radio` and aria labels, so they tab and take Space/Enter; aqua once set), a one-line
  "What helped most? (optional)" field and Send. After sending: "Thanks, Michi sees this." A reload
  shows the stored rating and comment.
- "Ask Michi" with the "2 of 2 questions left" pill, a text box and Send. Each question renders
  with Michi's answer or "Michi will answer here". At 0 left the box becomes one line - "You have
  used your two questions for this video - book the next one to keep going" - with the button.
- Footer: the next-step card (one function, `nextStepCardHtml()`, renders both the footer card and
  the compact line under the spent question box). It replaces the old "Want to continue
  coaching?" bar; WhatsApp and Email stay on it as the secondary links.

**Admin (`website/static/admin.html`)**

- List row: `★ 4` next to the status, and `1 open Q` when a question waits.
- Submission detail: a "Rating / Questions" card with the stars and comment, each question with
  an answer box and "Send answer" (or the answer and a "Change answer" link).

**Deploy (`.github/workflows/deploy.yml`)**

- Staging copied the API with `cp -r backend-php/api/ api/`, which - because `api/` already
  exists on the box - copies the folder *into* `api/api/` and never updates `api/`. Staging's
  `api/` had been frozen for weeks (no `credit-redeem.php`, no `helpers/coaching-products.php`;
  they sat in `api/api/`). Changed to `cp -r backend-php/api/. api/`. Production uses rsync and
  was never affected.

## What the credits card shows, and why

The platform sells credits per *purchase* (`coaching_credits`, keyed by the buyer's token and
email), not per rider account. A WingCoach submission has no link to a credit row. So the closest
honest thing to "your balance" is: unexpired credits bought with the same email as the
submission (case-insensitive match, `riderLoopNextStep()`).

- Credits found and > 0: "You have N coaching credits left" - button goes to their own credit
  page (`/credit?t=...`), where a credit is actually spent.
- Credits found, all used: "All your coaching credits are used" - button to `/coaching`.
- No purchase under that email (every WingCoach founding rider today, and every test): "Next:
  book your next coaching video" - button to `/coaching`, the sales page.

On staging the `coaching_credits` table does not exist at all (its migration was only applied on
production), so the lookup is wrapped in a try/catch and the card falls back to the sales page.
That is what the test page below shows.

## Staging test page

https://ari.tricktionary.com/projects/video-coaching/reply/1889833ee92d76522cd9a42244ae4f45

Submission #5 on staging ("Test Rider", no email, `counts_toward_spots=0`), two text reply
items, rated 5, both questions used, one answered. Production submission 4 was not touched.

Note: the coach-notification emails for the two test questions went to rossi@tricktionary.com
from staging (subject "Question from Test Rider on their coaching video"). No rider email was
sent - the test submission has no email address, and `answer-question` returns `emailed: false`.

## Curl tests (staging, `curl-tests.sh`)

```

## 1 GET reply JSON (fresh)
{"name": "Test Rider", "status": "feedback_sent", "replyFiles": [], "replyItems": [{"id": 7, "submission_id": 5, "type": "text", "filename": null, "description": "Your gybe - what I saw", "content": "Front foot pressure comes late in the entry - shift it before the turn, not during. Two sessions on flat water, then send the next clip.", "order_index": 0, "created_at": "2026-09-15 07:12:03"}, {"id": 8, "submission_id": 5, "type": "text", "filename": null, "description": "Chapters", "content": "<p><a href=\"#t=12\" data-t=\"12\">00:12</a> Entry</p><p><a href=\"#t=40\" data-t=\"40\">00:40</a> Foot change</p>", "order_index": 1, "created_at": "2026-09-15 07:12:03"}], "token": "1889833ee92d76522cd9a42244ae4f45", "feedbackSentAt": "2026-09-15 07:12:03", "rating": null, "questions": [], "questionsLeft": 2, "questionCap": 2, "nextStep": {"credits": null, "creditUrl": null, "buyUrl": "https://ari.tricktionary.com/projects/video-coaching/coaching"}}

## 2 POST rating 4 + comment
{"ok": true, "rating": {"rating": 4, "comment": "The foot change at 00:40", "createdAt": "2026-09-15 07:13:19", "updatedAt": null}, "questions": [], "questionsLeft": 2, "questionCap": 2, "nextStep": {"credits": null, "creditUrl": null, "buyUrl": "https://ari.tricktionary.com/projects/video-coaching/coaching"}}

## 3 POST rating 5 again (replaces)
{"ok": true, "rating": {"rating": 5, "comment": null, "createdAt": "2026-09-15 07:13:19", "updatedAt": "2026-09-15 07:13:19"}, "questions": [], "questionsLeft": 2, "questionCap": 2, "nextStep": {"credits": null, "creditUrl": null, "buyUrl": "https://ari.tricktionary.com/projects/video-coaching/coaching"}}

## 4 POST rating 9 (invalid)
{"error":"Pick one to five stars"} [400]

## 5 POST question 1
{"ok": true, "questionId": 1, "rating": {"rating": 5, "comment": null, "createdAt": "2026-09-15 07:13:19", "updatedAt": "2026-09-15 07:13:19"}, "questions": [{"id": 1, "question": "At 00:40 - do I move the back foot first or the front foot?", "askedAt": "2026-09-15 07:13:19", "answer": null, "answeredAt": null}], "questionsLeft": 1, "questionCap": 2, "nextStep": {"credits": null, "creditUrl": null, "buyUrl": "https://ari.tricktionary.com/projects/video-coaching/coaching"}}

## 6 POST question 2
{"ok": true, "questionId": 2, "rating": {"rating": 5, "comment": null, "createdAt": "2026-09-15 07:13:19", "updatedAt": "2026-09-15 07:13:19"}, "questions": [{"id": 1, "question": "At 00:40 - do I move the back foot first or the front foot?", "askedAt": "2026-09-15 07:13:19", "answer": null, "answeredAt": null}, {"id": 2, "question": "How many sessions before I try it in chop?", "askedAt": "2026-09-15 07:13:20", "answer": null, "answeredAt": null}], "questionsLeft": 0, "questionCap": 2, "nextStep": {"credits": null, "creditUrl": null, "buyUrl": "https://ari.tricktionary.com/projects/video-coaching/coaching"}}

## 7 POST question 3 (cap)
{"error":"You have used your 2 questions for this video - book the next one to keep going","questionsLeft":0} [400]

## 8 POST empty question
{"error":"Write your question first"} [400]

## 9 POST oversize body (5000 bytes)
{"error":"That is too long to send"} [413]

## 10 admin GET submission
{"id": 5, "name": "Test Rider", "rating": {"rating": 5, "comment": null, "createdAt": "2026-09-15 07:13:19", "updatedAt": "2026-09-15 07:13:19"}, "questions": [{"id": 1, "question": "At 00:40 - do I move the back foot first or the front foot?", "askedAt": "2026-09-15 07:13:19", "answer": null, "answeredAt": null}, {"id": 2, "question": "How many sessions before I try it in chop?", "askedAt": "2026-09-15 07:13:20", "answer": null, "answeredAt": null}], "question_cap": 2}

## 11 admin answer question 1
{"success": true, "questions": [{"id": 1, "question": "At 00:40 - do I move the back foot first or the front foot?", "askedAt": "2026-09-15 07:13:19", "answer": "Back foot first - it frees the hips. Front foot follows once the board is through the wind.", "answeredAt": "2026-09-15 07:13:21"}, {"id": 2, "question": "How many sessions before I try it in chop?", "askedAt": "2026-09-15 07:13:20", "answer": null, "answeredAt": null}], "emailed": false, "emailError": null}

## 12 admin answer, wrong submission (404)
{"error":"Question not found"} [404]

## 13 GET reply JSON (after answer)
{"rating": {"rating": 5, "comment": null, "createdAt": "2026-09-15 07:13:19", "updatedAt": "2026-09-15 07:13:19"}, "questions": [{"id": 1, "question": "At 00:40 - do I move the back foot first or the front foot?", "askedAt": "2026-09-15 07:13:19", "answer": "Back foot first - it frees the hips. Front foot follows once the board is through the wind.", "answeredAt": "2026-09-15 07:13:21"}, {"id": 2, "question": "How many sessions before I try it in chop?", "askedAt": "2026-09-15 07:13:20", "answer": null, "answeredAt": null}], "questionsLeft": 0, "nextStep": {"credits": null, "creditUrl": null, "buyUrl": "https://ari.tricktionary.com/projects/video-coaching/coaching"}}

## 14 admin list row
[{'id': 5, 'rating': 5, 'open_questions': 1}]

## 15 rate limit: hammer the rating endpoint
200 200 200 429 429 429 

## 16 reply page HTML has the new sections
id="next-step"
id="questions-card"
id="rating-card"
```

Test 15: seven POSTs had already been logged for the token by then, so the 4th of the six hits
the tenth-per-hour and the rest are 429. The log rows for the test token were cleared afterwards
so the page can be tried by hand.

`php -l` on coaching-server: reply.php, admin.php, helpers/email.php, helpers/rider-loop.php -
no syntax errors. `node --check` on the inline scripts of reply.html and admin.html - clean.

## Open questions for Michi

1. Two questions per video, and the questions are text only. The ideas note mentions answering
   with a short clip through the Vimeo path - not built; an answer is text. Add a Vimeo id to an
   answer later if you want it.
2. The coach email goes to `NOTIFY_EMAIL` (rossi@). Fine? The admin link in it opens the list,
   not the submission - the admin page has no deep link to a submission yet.
3. The credits card matches on email. A rider who bought credits with a different email than
   their WingCoach submission sees the sales page, not their balance.
4. Staging's `coaching_credits` table is missing - run `migrations/2026-09-09-coaching-credits.sql`
   there if you want the credits path testable on staging.
5. The founding riders' reply pages (submission 4 on production) now show the rating and the
   question box the next time they open the link. The rate limit is per token, 10 an hour.
