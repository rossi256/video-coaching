-- Between the Calls, v1: the Question Wall.
-- Idempotent. Apply with: php migrations/apply.php 2026-11-02-community-wall.sql

CREATE TABLE IF NOT EXISTS cm_members (
  id INT AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(255) NOT NULL UNIQUE,
  name VARCHAR(255) NOT NULL,
  display_name VARCHAR(64) NOT NULL,
  token CHAR(32) NOT NULL UNIQUE,
  source VARCHAR(64) NOT NULL DEFAULT 'wall',
  created_at DATETIME NOT NULL DEFAULT current_timestamp(),
  last_seen DATETIME NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cm_devices (
  voter_key VARCHAR(64) NOT NULL PRIMARY KEY,
  member_id INT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT current_timestamp(),
  last_seen DATETIME NOT NULL DEFAULT current_timestamp(),
  INDEX idx_member (member_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cm_questions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  session_id INT NULL,
  member_id INT NOT NULL,
  voter_key VARCHAR(64) NULL,
  body TEXT NOT NULL,
  status VARCHAR(16) NOT NULL DEFAULT 'open',
  source VARCHAR(32) NOT NULL DEFAULT 'wall',
  pinned TINYINT(1) NOT NULL DEFAULT 0,
  answered_at DATETIME NULL,
  answer_t INT NULL,
  answer_text TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT current_timestamp(),
  INDEX idx_session_status (session_id, status),
  INDEX idx_voter_created (voter_key, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cm_metoo (
  question_id INT NOT NULL,
  voter_key VARCHAR(64) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (question_id, voter_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cm_replies (
  id INT AUTO_INCREMENT PRIMARY KEY,
  question_id INT NOT NULL,
  member_id INT NOT NULL,
  voter_key VARCHAR(64) NULL,
  body TEXT NOT NULL,
  is_michi TINYINT(1) NOT NULL DEFAULT 0,
  hidden TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT current_timestamp(),
  INDEX idx_question (question_id),
  INDEX idx_voter_created (voter_key, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cm_events (
  id INT AUTO_INCREMENT PRIMARY KEY,
  kind VARCHAR(32) NOT NULL,
  ref_id INT NULL,
  voter_key VARCHAR(64) NULL,
  src VARCHAR(32) NULL,
  created_at DATETIME NOT NULL DEFAULT current_timestamp(),
  INDEX idx_kind_created (kind, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
