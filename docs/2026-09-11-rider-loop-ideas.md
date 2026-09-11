# Rider loop after a coaching video - ideas for later (2026-09-11)

Michi, after the first real delivery: "don't we have some sort of chat/messaging and rating
thing here - some limited talk and feedback system will be nice - so customers can give feedback
or ask back - limit it to a certain amount - but something so they stay caught in the loop - and
at some point show that there is a credits system".

Not built. When it is:

- **Rating** on the reply page: one tap (1-5 or thumbs) plus an optional line, stored on the
  submission, shown in the admin list. Cheap, and the number Michi wants to see.
- **Limited follow-up**: a thread under the video, capped - e.g. two rider questions per
  coaching video, each answered once by the coach (text, or a short clip through the same
  Vimeo path). The cap is shown ("2 of 2 questions left"), so it reads as part of the product,
  not a limit. Coach answers from the admin; rider gets one email per answer.
- **Credits in view**: the reply page footer shows the rider's remaining credits and the next
  step ("book your next video" / buy credits), so the loop closes on the page they are already on.
- Later: this moves into the wing app's Coaching tab (Rails `Comment` model exists, threaded
  and moderated) rather than growing here.
