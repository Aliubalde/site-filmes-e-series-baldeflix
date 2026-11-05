CREATE DATABASE IF NOT EXISTS balde_flix
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE balde_flix;

CREATE TABLE IF NOT EXISTS users (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(190) NOT NULL UNIQUE,
  username VARCHAR(40) NULL UNIQUE,
  display_name VARCHAR(120) NOT NULL,
  bio VARCHAR(500) NULL,
  avatar_path VARCHAR(255) NULL,
  cover_path VARCHAR(255) NULL,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('super_admin', 'admin', 'moderator', 'viewer') NOT NULL DEFAULT 'viewer',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  email_verified_at TIMESTAMP NULL DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS categories (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(80) NOT NULL UNIQUE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS titles (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(180) NOT NULL,
  kind ENUM('movie', 'series') NOT NULL,
  synopsis TEXT NOT NULL,
  category_id BIGINT UNSIGNED NULL,
  release_year SMALLINT UNSIGNED NULL,
  is_paid TINYINT(1) NOT NULL DEFAULT 0,
  poster_path VARCHAR(255) NULL,
  video_url VARCHAR(2048) NULL,
  video_360p_url VARCHAR(2048) NULL,
  video_480p_url VARCHAR(2048) NULL,
  video_720p_url VARCHAR(2048) NULL,
  video_1080p_url VARCHAR(2048) NULL,
  featured TINYINT(1) NOT NULL DEFAULT 0,
  published TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_titles_category FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL,
  INDEX idx_titles_public (published, featured, created_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS seasons (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  title_id BIGINT UNSIGNED NOT NULL,
  season_number SMALLINT UNSIGNED NOT NULL,
  name VARCHAR(160) NULL,
  UNIQUE KEY uq_season (title_id, season_number),
  CONSTRAINT fk_seasons_title FOREIGN KEY (title_id) REFERENCES titles(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS episodes (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  season_id BIGINT UNSIGNED NOT NULL,
  episode_number SMALLINT UNSIGNED NOT NULL,
  name VARCHAR(180) NOT NULL,
  synopsis TEXT NULL,
  video_url VARCHAR(2048) NULL,
  video_360p_url VARCHAR(2048) NULL,
  video_480p_url VARCHAR(2048) NULL,
  video_720p_url VARCHAR(2048) NULL,
  video_1080p_url VARCHAR(2048) NULL,
  UNIQUE KEY uq_episode (season_id, episode_number),
  CONSTRAINT fk_episodes_season FOREIGN KEY (season_id) REFERENCES seasons(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS content_requests (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NULL,
  requester_name VARCHAR(120) NULL,
  requester_email VARCHAR(190) NULL,
  request_type ENUM('movie', 'series') NOT NULL,
  title VARCHAR(180) NOT NULL,
  release_year SMALLINT UNSIGNED NULL,
  genre VARCHAR(80) NULL,
  details TEXT NULL,
  status ENUM('pending', 'reviewing', 'approved', 'available', 'rejected') NOT NULL DEFAULT 'pending',
  admin_response TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_requests_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_requests_status (status, created_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS favorites (
  user_id BIGINT UNSIGNED NOT NULL,
  title_id BIGINT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, title_id),
  CONSTRAINT fk_favorites_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_favorites_title FOREIGN KEY (title_id) REFERENCES titles(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS user_title_access (
  user_id BIGINT UNSIGNED NOT NULL,
  title_id BIGINT UNSIGNED NOT NULL,
  granted_by BIGINT UNSIGNED NULL,
  granted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, title_id),
  CONSTRAINT fk_title_access_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_title_access_title FOREIGN KEY (title_id) REFERENCES titles(id) ON DELETE CASCADE,
  CONSTRAINT fk_title_access_granted_by FOREIGN KEY (granted_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS premium_subscriptions (
  user_id BIGINT UNSIGNED PRIMARY KEY,
  started_at DATETIME NOT NULL,
  expires_at DATETIME NOT NULL,
  granted_by BIGINT UNSIGNED NULL,
  CONSTRAINT fk_premium_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_premium_granted_by FOREIGN KEY (granted_by) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_premium_expiration (expires_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS watch_history (
  user_id BIGINT UNSIGNED NOT NULL,
  title_id BIGINT UNSIGNED NOT NULL,
  episode_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  progress_seconds INT UNSIGNED NOT NULL DEFAULT 0,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, title_id, episode_id),
  CONSTRAINT fk_history_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_history_title FOREIGN KEY (title_id) REFERENCES titles(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS audit_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NULL,
  action VARCHAR(120) NOT NULL,
  details VARCHAR(500) NULL,
  ip_address VARCHAR(45) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_audit_created (created_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS login_attempts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  login_key CHAR(64) NOT NULL,
  attempted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_login_attempts_key_time (login_key, attempted_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS account_tokens (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  token_hash CHAR(64) NOT NULL UNIQUE,
  purpose ENUM('password_reset') NOT NULL,
  target_email VARCHAR(190) NULL,
  expires_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  used_at TIMESTAMP NULL DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_account_tokens_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_account_token_lookup (user_id, purpose, used_at, expires_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS notifications (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  title VARCHAR(180) NOT NULL,
  message TEXT NOT NULL,
  read_at TIMESTAMP NULL DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_notifications_user (user_id, read_at, created_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS social_links (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  platform ENUM('facebook', 'instagram', 'discord', 'whatsapp') NOT NULL UNIQUE,
  url VARCHAR(2048) NULL,
  is_enabled TINYINT(1) NOT NULL DEFAULT 0,
  placement ENUM('header', 'footer', 'both') NOT NULL DEFAULT 'footer',
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS comments (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NULL,
  title_id BIGINT UNSIGNED NOT NULL,
  body VARCHAR(2000) NOT NULL,
  is_approved TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_comments_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_comments_title FOREIGN KEY (title_id) REFERENCES titles(id) ON DELETE CASCADE,
  INDEX idx_comments_title (title_id, is_approved, created_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS ratings (
  user_id BIGINT UNSIGNED NOT NULL,
  title_id BIGINT UNSIGNED NOT NULL,
  rating TINYINT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, title_id),
  CONSTRAINT fk_ratings_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_ratings_title FOREIGN KEY (title_id) REFERENCES titles(id) ON DELETE CASCADE,
  CONSTRAINT chk_rating_range CHECK (rating BETWEEN 1 AND 5)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS site_settings (
  setting_key VARCHAR(100) PRIMARY KEY,
  setting_value TEXT NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

INSERT IGNORE INTO social_links (platform, url, is_enabled, placement) VALUES
  ('facebook', NULL, 0, 'footer'),
  ('instagram', NULL, 0, 'footer'),
  ('discord', NULL, 0, 'footer'),
  ('whatsapp', NULL, 0, 'footer');

INSERT IGNORE INTO site_settings (setting_key, setting_value) VALUES
  ('payment_whatsapp_url', NULL);

INSERT IGNORE INTO categories (name) VALUES
  ('Ação'), ('Comédia'), ('Terror'), ('Romance'), ('Drama'),
  ('Ficção científica'), ('Animação'), ('Aventura'), ('Documentário'), ('Suspense');
