CREATE TABLE IF NOT EXISTS subscriptions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  estate_id INT NOT NULL,
  plan_id INT NOT NULL,
  plan_version INT NOT NULL DEFAULT 1,
  plan_snapshot_json MEDIUMTEXT NULL,
  billing_cycle ENUM('monthly','annual') NOT NULL DEFAULT 'monthly',
  status ENUM('pending_payment','active','past_due','grace_period','suspended','cancelled','expired','renewed') NOT NULL DEFAULT 'pending_payment',
  resident_limit INT NOT NULL DEFAULT 0,
  period_start DATETIME NULL,
  period_end DATETIME NULL,
  next_billing_date DATETIME NULL,
  grace_ends_at DATETIME NULL,
  pending_plan_id INT NULL,
  pending_cycle ENUM('monthly','annual') NULL,
  pending_effective_at DATETIME NULL,
  cancelled_at DATETIME NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_estate (estate_id, status),
  KEY idx_next (next_billing_date),
  CONSTRAINT fk_sub_estate FOREIGN KEY (estate_id) REFERENCES estates(id) ON DELETE CASCADE,
  CONSTRAINT fk_sub_plan FOREIGN KEY (plan_id) REFERENCES plans(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS invoices (
  id INT AUTO_INCREMENT PRIMARY KEY,
  invoice_no VARCHAR(40) NOT NULL,
  estate_id INT NOT NULL,
  subscription_id INT NULL,
  kind ENUM('subscription','renewal','plan_change','addon','adjustment') NOT NULL DEFAULT 'subscription',
  status ENUM('draft','pending','paid','failed','expired','cancelled','refunded','void') NOT NULL DEFAULT 'pending',
  currency VARCHAR(8) NOT NULL DEFAULT 'NGN',
  subtotal DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  credit_applied DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  period_start DATETIME NULL,
  period_end DATETIME NULL,
  due_date DATETIME NULL,
  paid_at DATETIME NULL,
  payment_reference VARCHAR(80) NULL,
  meta_json TEXT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_invoice_no (invoice_no),
  UNIQUE KEY uk_reference (payment_reference),
  KEY idx_estate (estate_id, status),
  CONSTRAINT fk_inv_estate FOREIGN KEY (estate_id) REFERENCES estates(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS invoice_lines (
  id INT AUTO_INCREMENT PRIMARY KEY,
  invoice_id INT NOT NULL,
  description VARCHAR(255) NOT NULL,
  line_type ENUM('plan','module','proration','credit','other') NOT NULL DEFAULT 'plan',
  module_key VARCHAR(60) NULL,
  quantity INT NOT NULL DEFAULT 1,
  unit_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  CONSTRAINT fk_il_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS payments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  estate_id INT NOT NULL,
  invoice_id INT NULL,
  entry_type ENUM('payment','refund','adjustment') NOT NULL DEFAULT 'payment',
  status ENUM('pending','success','failed','expired','cancelled','refunded') NOT NULL DEFAULT 'pending',
  gateway VARCHAR(30) NOT NULL DEFAULT 'paystack',
  reference VARCHAR(80) NOT NULL,
  gateway_reference VARCHAR(120) NULL,
  amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  currency VARCHAR(8) NOT NULL DEFAULT 'NGN',
  failure_reason VARCHAR(255) NULL,
  raw_payload MEDIUMTEXT NULL,
  recorded_by VARCHAR(60) NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  KEY idx_estate (estate_id, created_at),
  KEY idx_ref (reference),
  KEY idx_invoice (invoice_id),
  CONSTRAINT fk_pay_estate FOREIGN KEY (estate_id) REFERENCES estates(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS credits (
  id INT AUTO_INCREMENT PRIMARY KEY,
  estate_id INT NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  remaining DECIMAL(12,2) NOT NULL,
  reason VARCHAR(255) NULL,
  created_by INT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  KEY idx_estate (estate_id),
  CONSTRAINT fk_cr_estate FOREIGN KEY (estate_id) REFERENCES estates(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS billing_events (
  id INT AUTO_INCREMENT PRIMARY KEY,
  estate_id INT NOT NULL,
  subscription_id INT NULL,
  event_type VARCHAR(40) NOT NULL,
  title VARCHAR(200) NOT NULL,
  amount DECIMAL(12,2) NULL,
  meta_json TEXT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  KEY idx_estate (estate_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
