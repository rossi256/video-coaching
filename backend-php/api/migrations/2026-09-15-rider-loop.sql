-- Rider loop on the reply page: rating, capped follow-up questions, POST log.
--
-- For the record only: helpers/rider-loop.php runs the same CREATE TABLE IF
-- NOT EXISTS statements on first use, so nothing here needs applying by hand.

CREATE TABLE IF NOT EXISTS reply_ratings (
    submission_id INT PRIMARY KEY,
    rating        TINYINT      NOT NULL,          -- 1..5
    comment       VARCHAR(500) NULL,              -- "What helped most?"
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME     NULL               -- set when a second rating replaces the first
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS reply_questions (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    submission_id INT      NOT NULL,
    question      TEXT     NOT NULL,
    asked_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    answer        TEXT     NULL,
    answered_at   DATETIME NULL,
    INDEX idx_question_submission (submission_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- One row per public POST on a reply token; at most RIDER_POST_LIMIT per hour.
CREATE TABLE IF NOT EXISTS reply_post_log (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    token      VARCHAR(64) NOT NULL,
    created_at DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_post_token (token, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
