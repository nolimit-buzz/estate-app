CREATE TABLE IF NOT EXISTS support_tickets (
  id INT AUTO_INCREMENT PRIMARY KEY,
  ticket_no VARCHAR(30) NOT NULL,
  estate_id INT NOT NULL,
  category ENUM('onboarding','billing','technical','module_request','plan_change','other') NOT NULL DEFAULT 'other',
  subject VARCHAR(200) NOT NULL,
  priority ENUM('low','normal','high','urgent') NOT NULL DEFAULT 'normal',
  status ENUM('open','in_progress','awaiting_customer','resolved','closed') NOT NULL DEFAULT 'open',
  created_by_name VARCHAR(150) NULL,
  created_by_email VARCHAR(191) NULL,
  assigned_to INT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  closed_at DATETIME NULL,
  UNIQUE KEY uk_no (ticket_no),
  KEY idx_estate (estate_id, status),
  CONSTRAINT fk_tk_estate FOREIGN KEY (estate_id) REFERENCES estates(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ticket_messages (
  id INT AUTO_INCREMENT PRIMARY KEY,
  ticket_id INT NOT NULL,
  author_type ENUM('estate','platform','system') NOT NULL,
  author_name VARCHAR(150) NULL,
  message TEXT NOT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_tm_ticket FOREIGN KEY (ticket_id) REFERENCES support_tickets(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS email_verifications (
  id INT AUTO_INCREMENT PRIMARY KEY,
  purpose ENUM('registration','password_reset','login_otp') NOT NULL DEFAULT 'registration',
  estate_id INT NULL,
  user_id INT NULL,
  email VARCHAR(191) NOT NULL,
  otp_hash VARCHAR(64) NOT NULL,
  token_hash VARCHAR(64) NOT NULL,
  attempts INT NOT NULL DEFAULT 0,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  ip_address VARCHAR(45) NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  KEY idx_email (email, purpose),
  KEY idx_token (token_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS invitations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  estate_id INT NOT NULL,
  email VARCHAR(191) NOT NULL,
  name VARCHAR(150) NULL,
  role VARCHAR(40) NOT NULL DEFAULT 'resident',
  payload_json TEXT NULL,
  token_hash VARCHAR(64) NOT NULL,
  status ENUM('pending','accepted','expired','declined','cancelled') NOT NULL DEFAULT 'pending',
  invited_by INT NULL,
  invited_by_name VARCHAR(150) NULL,
  expires_at DATETIME NOT NULL,
  accepted_at DATETIME NULL,
  last_sent_at DATETIME NULL,
  send_count INT NOT NULL DEFAULT 1,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_token (token_hash),
  KEY idx_estate (estate_id, status),
  KEY idx_email (email),
  CONSTRAINT fk_inv2_estate FOREIGN KEY (estate_id) REFERENCES estates(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS email_templates (
  template_key VARCHAR(60) PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  subject VARCHAR(255) NOT NULL,
  body_html MEDIUMTEXT NOT NULL,
  placeholders VARCHAR(500) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  version INT NOT NULL DEFAULT 1,
  updated_by INT NULL,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS email_queue (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  estate_id INT NULL,
  template_key VARCHAR(60) NULL,
  to_email VARCHAR(191) NOT NULL,
  to_name VARCHAR(150) NULL,
  subject VARCHAR(255) NOT NULL,
  body_html MEDIUMTEXT NOT NULL,
  status ENUM('queued','sent','failed') NOT NULL DEFAULT 'queued',
  attempts INT NOT NULL DEFAULT 0,
  last_error VARCHAR(500) NULL,
  dedupe_key VARCHAR(120) NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  sent_at DATETIME NULL,
  next_attempt_at DATETIME NULL,
  KEY idx_status (status, next_attempt_at),
  KEY idx_dedupe (dedupe_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS backups (
  id INT AUTO_INCREMENT PRIMARY KEY,
  estate_id INT NOT NULL,
  db_name VARCHAR(80) NOT NULL,
  file_path VARCHAR(255) NOT NULL,
  size_bytes BIGINT NOT NULL DEFAULT 0,
  checksum VARCHAR(64) NULL,
  kind ENUM('scheduled','manual','pre_restore','final') NOT NULL DEFAULT 'scheduled',
  status ENUM('ok','failed','deleted') NOT NULL DEFAULT 'ok',
  created_by INT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  KEY idx_estate (estate_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS data_exports (
  id INT AUTO_INCREMENT PRIMARY KEY,
  estate_id INT NOT NULL,
  requested_by INT NULL,
  requested_by_name VARCHAR(150) NULL,
  file_path VARCHAR(255) NULL,
  status ENUM('ready','failed','expired') NOT NULL DEFAULT 'ready',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  KEY idx_estate (estate_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS lifecycle_notices (
  id INT AUTO_INCREMENT PRIMARY KEY,
  estate_id INT NOT NULL,
  notice_key VARCHAR(80) NOT NULL,
  sent_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_notice (estate_id, notice_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS deletion_requests (
  id INT AUTO_INCREMENT PRIMARY KEY,
  estate_id INT NOT NULL,
  requested_by INT NULL,
  status ENUM('awaiting_approval','approved','rejected','executed') NOT NULL DEFAULT 'awaiting_approval',
  approved_by INT NULL,
  final_backup_id INT NULL,
  note VARCHAR(255) NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  executed_at DATETIME NULL,
  KEY idx_estate (estate_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
