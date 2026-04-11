-- WingCoach MySQL Schema
-- Apply: mysql -u ari -p video-coaching < schema.sql

CREATE TABLE IF NOT EXISTS submissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    stripe_session_id VARCHAR(255) UNIQUE,
    stripe_payment_intent VARCHAR(255),
    token VARCHAR(36) UNIQUE NOT NULL,
    status VARCHAR(32) DEFAULT 'paid',

    name VARCHAR(255),
    email VARCHAR(255),
    age INT,
    location VARCHAR(255),
    ride_frequency VARCHAR(255),
    `conditions` TEXT,
    equipment TEXT,
    level TEXT,
    stuck_on TEXT,
    tried TEXT,
    success_looks_like TEXT,
    audio_file VARCHAR(255),

    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    submitted_at DATETIME,
    confirmed_at DATETIME,
    feedback_sent_at DATETIME,
    reply_video_path TEXT,
    spots_at_purchase INT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS config (
    `key` VARCHAR(64) PRIMARY KEY,
    value TEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO config (`key`, value) VALUES ('total_spots', '10');
INSERT IGNORE INTO config (`key`, value) VALUES ('spots_taken', '0');

CREATE TABLE IF NOT EXISTS checkout_attempts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) NOT NULL,
    stripe_session_id VARCHAR(255) UNIQUE,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    reminded_at DATETIME,
    converted TINYINT DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS waitlist (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255),
    email VARCHAR(255) NOT NULL,
    type VARCHAR(32) NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_email_type (email, type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

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

CREATE TABLE IF NOT EXISTS private_inquiries (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    email VARCHAR(255) NOT NULL,
    location VARCHAR(100),
    riding_level VARCHAR(50),
    message TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_email (email),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS reply_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    submission_id INT NOT NULL,
    type VARCHAR(32) NOT NULL DEFAULT 'video',
    filename VARCHAR(255),
    description TEXT,
    content TEXT,
    order_index INT DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_submission (submission_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Phase 3: Event inquiries, Q&A sessions, Q&A signups

CREATE TABLE IF NOT EXISTS event_inquiries (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    email VARCHAR(255) NOT NULL,
    event_slug VARCHAR(64) NOT NULL,
    event_name VARCHAR(255),
    current_level VARCHAR(64),
    message TEXT,
    status VARCHAR(32) DEFAULT 'new',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    responded_at DATETIME,
    INDEX idx_event (event_slug),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS qa_sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    description TEXT,
    scheduled_at DATETIME NOT NULL,
    duration_minutes INT DEFAULT 60,
    max_participants INT DEFAULT 50,
    status VARCHAR(32) DEFAULT 'upcoming',
    meeting_link VARCHAR(500),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_scheduled (scheduled_at),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS qa_signups (
    id INT AUTO_INCREMENT PRIMARY KEY,
    session_id INT NOT NULL,
    name VARCHAR(255) NOT NULL,
    email VARCHAR(255) NOT NULL,
    message TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_session_email (session_id, email),
    FOREIGN KEY (session_id) REFERENCES qa_sessions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
