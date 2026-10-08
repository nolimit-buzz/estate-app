CREATE TABLE IF NOT EXISTS modules (
  module_key VARCHAR(60) PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  category VARCHAR(60) NOT NULL DEFAULT 'General',
  description VARCHAR(255) NULL,
  purpose VARCHAR(255) NULL,
  capabilities TEXT NULL,
  icon VARCHAR(60) NULL,
  default_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  global_enabled TINYINT(1) NOT NULL DEFAULT 1,
  is_core TINYINT(1) NOT NULL DEFAULT 0,
  show_public TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 0,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS plans (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(80) NOT NULL,
  slug VARCHAR(80) NOT NULL,
  description VARCHAR(255) NULL,
  monthly_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  annual_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  currency VARCHAR(8) NOT NULL DEFAULT 'NGN',
  resident_limit INT NOT NULL DEFAULT 0 COMMENT '0 = unlimited',
  user_limit INT NOT NULL DEFAULT 0 COMMENT '0 = unlimited',
  storage_limit_mb INT NOT NULL DEFAULT 0 COMMENT '0 = unlimited',
  support_level VARCHAR(40) NOT NULL DEFAULT 'email',
  features TEXT NULL,
  is_featured TINYINT(1) NOT NULL DEFAULT 0,
  show_public TINYINT(1) NOT NULL DEFAULT 1,
  status ENUM('active','inactive','archived') NOT NULL DEFAULT 'active',
  sort_order INT NOT NULL DEFAULT 0,
  version INT NOT NULL DEFAULT 1,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS plan_modules (
  plan_id INT NOT NULL,
  module_key VARCHAR(60) NOT NULL,
  inclusion ENUM('included','optional') NOT NULL DEFAULT 'included',
  module_price DECIMAL(12,2) NULL COMMENT 'NULL = use modules.default_price',
  PRIMARY KEY (plan_id, module_key),
  CONSTRAINT fk_pm_plan FOREIGN KEY (plan_id) REFERENCES plans(id) ON DELETE CASCADE,
  CONSTRAINT fk_pm_module FOREIGN KEY (module_key) REFERENCES modules(module_key) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS plan_versions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  plan_id INT NOT NULL,
  version INT NOT NULL,
  snapshot_json MEDIUMTEXT NOT NULL,
  changed_by INT NULL,
  change_note VARCHAR(255) NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  KEY idx_plan (plan_id, version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS estate_module_overrides (
  estate_id INT NOT NULL,
  module_key VARCHAR(60) NOT NULL,
  state ENUM('on','off') NOT NULL,
  reason VARCHAR(255) NULL,
  set_by INT NULL,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (estate_id, module_key),
  CONSTRAINT fk_emo_estate FOREIGN KEY (estate_id) REFERENCES estates(id) ON DELETE CASCADE,
  CONSTRAINT fk_emo_module FOREIGN KEY (module_key) REFERENCES modules(module_key) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS estate_addon_modules (
  estate_id INT NOT NULL,
  module_key VARCHAR(60) NOT NULL,
  price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  added_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (estate_id, module_key),
  CONSTRAINT fk_eam_estate FOREIGN KEY (estate_id) REFERENCES estates(id) ON DELETE CASCADE,
  CONSTRAINT fk_eam_module FOREIGN KEY (module_key) REFERENCES modules(module_key) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
