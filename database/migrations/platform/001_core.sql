-- Platform (control plane) core schema
CREATE TABLE IF NOT EXISTS estates (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL,
  domain_prefix VARCHAR(63) NOT NULL,
  custom_domain VARCHAR(150) NULL,
  organisation_type VARCHAR(60) NULL,
  address VARCHAR(255) NULL,
  city VARCHAR(100) NULL,
  state VARCHAR(100) NULL,
  country VARCHAR(100) NULL DEFAULT 'Nigeria',
  contact_email VARCHAR(150) NULL,
  contact_phone VARCHAR(40) NULL,
  status ENUM('pending','onboarding','trial','active','grace_period','suspended','retention','deletion_pending','deleted','archived') NOT NULL DEFAULT 'pending',
  onboarding_status ENUM('registration_started','email_verification_pending','central_admin_verification_pending','onboarding_pending','support_review','plan_selected','payment_pending','payment_confirmed','active','suspended','grace_period','deletion_pending') NOT NULL DEFAULT 'registration_started',
  db_name VARCHAR(80) NULL,
  db_user VARCHAR(80) NULL,
  db_pass_enc TEXT NULL,
  provisioned_at DATETIME NULL,
  trial_ends_at DATETIME NULL,
  grace_ends_at DATETIME NULL,
  suspended_at DATETIME NULL,
  retention_ends_at DATETIME NULL,
  deletion_scheduled_at DATETIME NULL,
  deleted_at DATETIME NULL,
  deletion_approved_by INT NULL,
  current_subscription_id INT NULL,
  resident_count INT NOT NULL DEFAULT 0,
  resident_count_at DATETIME NULL,
  notes TEXT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_domain_prefix (domain_prefix),
  UNIQUE KEY uk_custom_domain (custom_domain),
  KEY idx_status (status),
  KEY idx_onboarding (onboarding_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS platform_users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL,
  email VARCHAR(191) NOT NULL,
  password VARCHAR(255) NOT NULL,
  role ENUM('superadmin','support','finance') NOT NULL DEFAULT 'superadmin',
  status ENUM('active','disabled') NOT NULL DEFAULT 'active',
  force_password_change TINYINT(1) NOT NULL DEFAULT 0,
  last_login_at DATETIME NULL,
  last_login_ip VARCHAR(45) NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS platform_settings (
  setting_key VARCHAR(80) PRIMARY KEY,
  setting_value TEXT NULL,
  value_type ENUM('int','string','bool','decimal','text','csv') NOT NULL DEFAULT 'string',
  group_name VARCHAR(40) NOT NULL DEFAULT 'general',
  label VARCHAR(150) NULL,
  description VARCHAR(255) NULL,
  sort_order INT NOT NULL DEFAULT 0,
  updated_by INT NULL,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS platform_audit_logs (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actor_type ENUM('platform','tenant','system') NOT NULL DEFAULT 'system',
  actor_id INT NULL,
  actor_name VARCHAR(150) NULL,
  actor_role VARCHAR(40) NULL,
  estate_id INT NULL,
  action VARCHAR(120) NOT NULL,
  module VARCHAR(60) NULL,
  entity_type VARCHAR(60) NULL,
  entity_id VARCHAR(64) NULL,
  details TEXT NULL,
  before_json MEDIUMTEXT NULL,
  after_json MEDIUMTEXT NULL,
  ip_address VARCHAR(45) NULL,
  user_agent VARCHAR(255) NULL,
  KEY idx_created (created_at),
  KEY idx_estate (estate_id, created_at),
  KEY idx_action (action),
  KEY idx_actor (actor_type, actor_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS platform_login_logs (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  event_type VARCHAR(40) NOT NULL,
  user_type ENUM('platform','tenant') NOT NULL DEFAULT 'tenant',
  user_id INT NULL,
  email VARCHAR(191) NULL,
  user_name VARCHAR(150) NULL,
  role VARCHAR(40) NULL,
  estate_id INT NULL,
  success TINYINT(1) NOT NULL DEFAULT 0,
  failure_reason VARCHAR(150) NULL,
  ip_address VARCHAR(45) NULL,
  user_agent VARCHAR(255) NULL,
  device VARCHAR(120) NULL,
  session_ref VARCHAR(64) NULL,
  logged_in_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  logged_out_at DATETIME NULL,
  KEY idx_time (logged_in_at),
  KEY idx_estate (estate_id, logged_in_at),
  KEY idx_email (email),
  KEY idx_session (session_ref),
  KEY idx_event (event_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS security_alerts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  alert_type VARCHAR(60) NOT NULL,
  severity ENUM('low','medium','high','critical') NOT NULL DEFAULT 'medium',
  estate_id INT NULL,
  user_ref VARCHAR(191) NULL,
  ip_address VARCHAR(45) NULL,
  message VARCHAR(255) NOT NULL,
  context_json TEXT NULL,
  status ENUM('open','acknowledged','resolved') NOT NULL DEFAULT 'open',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  resolved_by INT NULL,
  resolved_at DATETIME NULL,
  KEY idx_status (status, created_at),
  KEY idx_estate (estate_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS rate_limits (
  id INT AUTO_INCREMENT PRIMARY KEY,
  bucket VARCHAR(40) NOT NULL,
  identifier VARCHAR(191) NOT NULL,
  attempts INT NOT NULL DEFAULT 0,
  window_start DATETIME NOT NULL,
  locked_until DATETIME NULL,
  UNIQUE KEY uk_bucket_ident (bucket, identifier)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS job_runs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  job_name VARCHAR(80) NOT NULL,
  status ENUM('running','success','failed') NOT NULL DEFAULT 'running',
  started_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  finished_at DATETIME NULL,
  message TEXT NULL,
  KEY idx_job (job_name, started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS system_errors (
  id INT AUTO_INCREMENT PRIMARY KEY,
  category ENUM('payment','email','invitation','webhook','job','backup','tenant','security','other') NOT NULL DEFAULT 'other',
  severity ENUM('info','warning','error','critical') NOT NULL DEFAULT 'error',
  estate_id INT NULL,
  message VARCHAR(500) NOT NULL,
  context_json TEXT NULL,
  resolved TINYINT(1) NOT NULL DEFAULT 0,
  resolved_by INT NULL,
  resolved_at DATETIME NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  KEY idx_cat (category, resolved, created_at),
  KEY idx_estate (estate_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
