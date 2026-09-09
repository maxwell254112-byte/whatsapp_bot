SET time_zone = '+00:00';

CREATE DATABASE IF NOT EXISTS whatsapp_bot
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE whatsapp_bot;

CREATE TABLE users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(64) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  display_name VARCHAR(120) NOT NULL DEFAULT '',
  role ENUM('admin','operator') NOT NULL DEFAULT 'operator',
  permissions JSON NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  failed_login_attempts INT UNSIGNED NOT NULL DEFAULT 0,
  locked_until DATETIME NULL,
  last_login_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_users_username (username)
) ENGINE=InnoDB;

CREATE TABLE workers (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  token_hash CHAR(64) NOT NULL,
  token_prefix CHAR(8) NOT NULL,
  status ENUM('offline','online','disabled') NOT NULL DEFAULT 'offline',
  whatsapp_status ENUM('unknown','qr_required','connected','disconnected','error') NOT NULL DEFAULT 'unknown',
  last_heartbeat_at DATETIME NULL,
  current_job_id BIGINT UNSIGNED NULL,
  hostname VARCHAR(120) NULL,
  os_info VARCHAR(255) NULL,
  browser VARCHAR(120) NULL,
  python_version VARCHAR(40) NULL,
  worker_version VARCHAR(40) NULL,
  last_error TEXT NULL,
  is_enabled TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_workers_name (name),
  KEY idx_workers_heartbeat (last_heartbeat_at),
  KEY idx_workers_status (status, is_enabled)
) ENGINE=InnoDB;

CREATE TABLE contacts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(160) NOT NULL DEFAULT '',
  phone VARCHAR(32) NOT NULL,
  phone_normalized VARCHAR(32) NOT NULL,
  company VARCHAR(160) NOT NULL DEFAULT '',
  notes TEXT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_contacts_phone_normalized (phone_normalized),
  KEY idx_contacts_active (is_active),
  KEY idx_contacts_name (name),
  CONSTRAINT fk_contacts_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE contact_groups (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  description VARCHAR(255) NOT NULL DEFAULT '',
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_contact_groups_name (name),
  CONSTRAINT fk_groups_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE contact_group_members (
  group_id INT UNSIGNED NOT NULL,
  contact_id INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (group_id, contact_id),
  CONSTRAINT fk_cgm_group FOREIGN KEY (group_id) REFERENCES contact_groups(id) ON DELETE CASCADE,
  CONSTRAINT fk_cgm_contact FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE message_templates (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  body TEXT NOT NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_templates_name (name),
  CONSTRAINT fk_templates_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE media_files (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  original_name VARCHAR(255) NOT NULL,
  stored_name VARCHAR(64) NOT NULL,
  mime_type VARCHAR(120) NOT NULL,
  extension VARCHAR(16) NOT NULL,
  size_bytes INT UNSIGNED NOT NULL,
  checksum_sha256 CHAR(64) NOT NULL,
  uploaded_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_media_stored_name (stored_name),
  CONSTRAINT fk_media_uploaded_by FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE campaigns (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(160) NOT NULL,
  status ENUM('draft','queued','running','paused','completed','cancelled') NOT NULL DEFAULT 'draft',
  message_body TEXT NOT NULL,
  template_id INT UNSIGNED NULL,
  media_id INT UNSIGNED NULL,
  scheduled_at DATETIME NULL COMMENT 'UTC',
  total_count INT UNSIGNED NOT NULL DEFAULT 0,
  sent_count INT UNSIGNED NOT NULL DEFAULT 0,
  failed_count INT UNSIGNED NOT NULL DEFAULT 0,
  pending_count INT UNSIGNED NOT NULL DEFAULT 0,
  processing_count INT UNSIGNED NOT NULL DEFAULT 0,
  cancelled_count INT UNSIGNED NOT NULL DEFAULT 0,
  created_by INT UNSIGNED NULL,
  started_at DATETIME NULL,
  completed_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_campaigns_status (status),
  KEY idx_campaigns_scheduled (scheduled_at),
  CONSTRAINT fk_campaigns_template FOREIGN KEY (template_id) REFERENCES message_templates(id) ON DELETE SET NULL,
  CONSTRAINT fk_campaigns_media FOREIGN KEY (media_id) REFERENCES media_files(id) ON DELETE SET NULL,
  CONSTRAINT fk_campaigns_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE message_jobs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  campaign_id INT UNSIGNED NULL,
  contact_id INT UNSIGNED NULL,
  phone VARCHAR(32) NOT NULL,
  phone_normalized VARCHAR(32) NOT NULL,
  recipient_name VARCHAR(160) NOT NULL DEFAULT '',
  message_body TEXT NOT NULL,
  media_id INT UNSIGNED NULL,
  status ENUM('pending','scheduled','processing','sent','failed','cancelled') NOT NULL DEFAULT 'pending',
  scheduled_at DATETIME NULL COMMENT 'UTC',
  worker_id INT UNSIGNED NULL,
  claimed_at DATETIME NULL,
  attempts INT UNSIGNED NOT NULL DEFAULT 0,
  max_attempts INT UNSIGNED NOT NULL DEFAULT 3,
  last_error TEXT NULL,
  sent_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_jobs_claim (status, scheduled_at, id),
  KEY idx_jobs_campaign (campaign_id, status),
  KEY idx_jobs_worker (worker_id, status),
  KEY idx_jobs_processing (status, claimed_at),
  CONSTRAINT fk_jobs_campaign FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE SET NULL,
  CONSTRAINT fk_jobs_contact FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE SET NULL,
  CONSTRAINT fk_jobs_media FOREIGN KEY (media_id) REFERENCES media_files(id) ON DELETE SET NULL,
  CONSTRAINT fk_jobs_worker FOREIGN KEY (worker_id) REFERENCES workers(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE message_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  job_id BIGINT UNSIGNED NULL,
  campaign_id INT UNSIGNED NULL,
  worker_id INT UNSIGNED NULL,
  phone_normalized VARCHAR(32) NOT NULL DEFAULT '',
  event_type VARCHAR(64) NOT NULL,
  status VARCHAR(32) NOT NULL DEFAULT '',
  message VARCHAR(500) NOT NULL DEFAULT '',
  detail_json JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_message_logs_job (job_id),
  KEY idx_message_logs_campaign (campaign_id),
  KEY idx_message_logs_created (created_at),
  CONSTRAINT fk_mlogs_job FOREIGN KEY (job_id) REFERENCES message_jobs(id) ON DELETE SET NULL,
  CONSTRAINT fk_mlogs_campaign FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE SET NULL,
  CONSTRAINT fk_mlogs_worker FOREIGN KEY (worker_id) REFERENCES workers(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE audit_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NULL,
  worker_id INT UNSIGNED NULL,
  action VARCHAR(80) NOT NULL,
  entity_type VARCHAR(60) NOT NULL DEFAULT '',
  entity_id VARCHAR(60) NOT NULL DEFAULT '',
  ip_address VARCHAR(45) NOT NULL DEFAULT '',
  user_agent VARCHAR(255) NOT NULL DEFAULT '',
  detail_json JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_audit_created (created_at),
  KEY idx_audit_action (action),
  KEY idx_audit_user (user_id),
  CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_audit_worker FOREIGN KEY (worker_id) REFERENCES workers(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE system_settings (
  setting_key VARCHAR(80) NOT NULL PRIMARY KEY,
  setting_value TEXT NOT NULL,
  updated_by INT UNSIGNED NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_settings_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE login_throttle (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(64) NOT NULL,
  ip_address VARCHAR(45) NOT NULL,
  attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_throttle_lookup (username, ip_address, attempted_at)
) ENGINE=InnoDB;

-- Default settings (values are strings; app casts as needed)
INSERT INTO system_settings (setting_key, setting_value) VALUES
('app_timezone', 'Asia/Kuala_Lumpur'),
('default_country_code', '60'),
('min_delay_seconds', '3'),
('max_delay_seconds', '8'),
('max_messages_per_batch', '20'),
('max_messages_per_hour', '60'),
('max_messages_per_day', '400'),
('pause_between_batches_seconds', '60'),
('max_retry_attempts', '3'),
('worker_heartbeat_timeout_seconds', '90'),
('stale_job_timeout_seconds', '600'),
('session_lifetime_seconds', '28800'),
('max_upload_bytes', '10485760'),
('disclaimer_ack', 'This system is for consent-based internal messaging only. Unofficial WhatsApp Web automation provides at-least-once job execution with delivery uncertainty. Do not use for spam or ban evasion.');

-- Default admin: username admin / password admin123
-- Change immediately after first login.
INSERT INTO users (username, password_hash, display_name, role, permissions, is_active) VALUES
('admin', '$2y$12$GXoofi/F4R5IxwaPSHYQbuIlygThRU9CVDAj9royAwLSmoQMNThQa', 'Administrator', 'admin', JSON_ARRAY(
  'manage_contacts','manage_campaigns','send_messages','manage_workers','manage_settings','view_logs','manage_templates','manage_media'
), 1);
