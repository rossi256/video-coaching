-- Between the Calls: a question can be marked answered on an EARLIER call than the one it is listed for.
-- answered_session_id = the qa_sessions row of that call; answer_t = seconds into that replay (nullable).
ALTER TABLE cm_questions ADD COLUMN IF NOT EXISTS answered_session_id INT NULL AFTER answer_t;
