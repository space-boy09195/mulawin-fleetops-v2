-- =====================================================================
-- R.P. Mulawin Fleet Management System V2  |  database/schema.sql
-- PART 1 OF 2: core and master tables.
--
-- HOW TO USE
--   1. Create a NEW, EMPTY database (example name: mulawin_v2, utf8mb4).
--   2. Import this file first, then database/extension.sql.
--   3. Never import over an existing company database.
--
-- No sample company data is inserted. Real users, clients, trucks and
-- crew are entered inside the system by the Admin.
-- =====================================================================
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 1;

-- ---------------------------------------------------------------------
-- USERS AND ACCESS  (FMS PDF II.A: individual accounts, roles, activate /
-- deactivate, activity logs with date and time stamps)
-- role values: admin, operations_head, car_carrier_dispatcher,
-- container_wingvan_dispatcher, motorpool, purchasing, finance, billing,
-- payroll, audit
-- ---------------------------------------------------------------------
CREATE TABLE users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(70) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  full_name VARCHAR(100) NOT NULL,
  role VARCHAR(50) NOT NULL,
  shift VARCHAR(10) NULL COMMENT 'Day or Night (dispatchers only)',
  active TINYINT NOT NULL DEFAULT 1,
  must_change_password TINYINT NOT NULL DEFAULT 0,
  last_login_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_users_role (role, active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Activity / audit log: who created, updated, approved or processed a record.
CREATE TABLE audit_events (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  actor_id INT NULL COMMENT 'NULL = action done automatically by the system',
  area VARCHAR(60) NOT NULL,
  record_ref VARCHAR(70) NOT NULL,
  action VARCHAR(120) NOT NULL,
  details VARCHAR(1000) NOT NULL DEFAULT '',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (actor_id) REFERENCES users(id),
  INDEX idx_audit_created (created_at),
  INDEX idx_audit_area (area, record_ref)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- In-app notifications (FMS PDF V.63: pending approvals, trip updates,
-- expiring documents). dedupe_key stops the same reminder twice a day.
CREATE TABLE notifications (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  category VARCHAR(60) NOT NULL,
  message VARCHAR(300) NOT NULL,
  page VARCHAR(60) NULL COMMENT 'page to open when clicked',
  dedupe_key VARCHAR(120) NULL,
  is_read TINYINT NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id),
  UNIQUE KEY uq_notification_dedupe (user_id, dedupe_key),
  INDEX idx_notifications_user (user_id, is_read, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Formula settings saved from the Formula Settings page. Defaults live in
-- config/formulas.php; a row here overrides the default.
CREATE TABLE app_settings (
  setting_key VARCHAR(80) PRIMARY KEY,
  setting_value VARCHAR(255) NOT NULL,
  updated_by INT NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (updated_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Editable computation lists (trip allowance, P&L expenses, payroll
-- earnings and deductions). Empty scope = use config/formulas.php.
CREATE TABLE formula_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  scope VARCHAR(40) NOT NULL,
  name VARCHAR(100) NOT NULL,
  calc VARCHAR(30) NOT NULL,
  amount DECIMAL(14,6) NOT NULL DEFAULT 0,
  divisor DECIMAL(14,6) NOT NULL DEFAULT 1,
  only_if_attended TINYINT NOT NULL DEFAULT 0,
  ledger VARCHAR(40) NULL,
  source VARCHAR(255) NULL,
  active TINYINT NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 0,
  updated_by INT NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (updated_by) REFERENCES users(id),
  INDEX idx_formula_scope (scope, active, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- CLIENTS  (FMS PDF II.H; monitoring workbooks use two levels:
-- COMPANY NAME (broker / forwarder) and CLIENT (brand / consignee))
-- ---------------------------------------------------------------------
CREATE TABLE clients (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL COMMENT 'CLIENT column in monitoring sheets',
  company_name VARCHAR(150) NULL COMMENT 'COMPANY NAME column (broker / forwarder)',
  contact_person VARCHAR(120) NULL,
  contact_number VARCHAR(60) NULL,
  email VARCHAR(120) NULL,
  address VARCHAR(255) NULL,
  payment_terms_days INT NULL COMMENT 'blank = use default from Formula Settings',
  active TINYINT NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_client_name (name, company_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Routes: origin / destination with total trip kilometers and RFID/toll.
-- Used by the automatic allowance and the projected P&L.
CREATE TABLE routes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  origin VARCHAR(150) NOT NULL,
  destination VARCHAR(150) NOT NULL,
  distance_km DECIMAL(10,2) NOT NULL DEFAULT 0 COMMENT 'total km of the whole trip (P&L G52)',
  toll_fee DECIMAL(12,2) NOT NULL DEFAULT 0 COMMENT 'RFID / toll for the whole trip (P&L G27)',
  notes VARCHAR(255) NULL,
  active TINYINT NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_route (origin, destination)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- PERSONNEL  (FMS PDF II.C: drivers, helpers, admin employees,
-- contractors, PDI personnel)
-- ---------------------------------------------------------------------
CREATE TABLE personnel (
  id INT AUTO_INCREMENT PRIMARY KEY,
  employee_no VARCHAR(40) NULL UNIQUE,
  full_name VARCHAR(120) NOT NULL,
  person_type VARCHAR(30) NOT NULL COMMENT 'Driver, Helper, Admin Staff, PDI Personnel',
  employment_type VARCHAR(20) NOT NULL DEFAULT 'Employee' COMMENT 'Employee or Contractor',
  employment_status VARCHAR(20) NOT NULL DEFAULT 'Active' COMMENT 'Active, Resigned, Inactive',
  contact_number VARCHAR(60) NULL,
  address VARCHAR(255) NULL,
  license_number VARCHAR(60) NULL,
  license_expiry DATE NULL,
  date_hired DATE NULL,
  availability VARCHAR(20) NOT NULL DEFAULT 'Available' COMMENT 'Available, On Trip, Unavailable',
  payroll_group VARCHAR(60) NULL COMMENT 'e.g. South Carrier, South Container, North Container, Wing Van',
  pay_frequency VARCHAR(20) NOT NULL DEFAULT 'Weekly',
  daily_rate DECIMAL(10,2) NOT NULL DEFAULT 0,
  notes VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_personnel_type (person_type, employment_status, availability)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- FLEET  (FMS PDF II.B; OFFICIAL RECEIPT REGISTRATION sheet columns)
-- status: Available, Assigned, On Trip, Under Maintenance, Unavailable
-- ---------------------------------------------------------------------
CREATE TABLE vehicles (
  id INT AUTO_INCREMENT PRIMARY KEY,
  plate_number VARCHAR(40) NOT NULL UNIQUE,
  truck_number VARCHAR(20) NULL COMMENT 'unit number used in monitoring sheets (NO column)',
  truck_type VARCHAR(25) NOT NULL COMMENT 'Car Carrier, Container, Wing Van',
  make_brand VARCHAR(80) NULL,
  model VARCHAR(80) NULL,
  chassis_number VARCHAR(80) NULL,
  engine_number VARCHAR(80) NULL,
  mv_file_number VARCHAR(80) NULL,
  or_number VARCHAR(80) NULL,
  cr_number VARCHAR(80) NULL,
  registration_expiry DATE NULL,
  insurance_provider VARCHAR(120) NULL,
  insurance_policy_number VARCHAR(80) NULL,
  insurance_expiry DATE NULL,
  assigned_driver_id INT NULL,
  assigned_helper_id INT NULL,
  status VARCHAR(30) NOT NULL DEFAULT 'Available',
  odometer_km INT NOT NULL DEFAULT 0,
  km_since_pm INT NOT NULL DEFAULT 0 COMMENT 'km added since last preventive maintenance',
  notes VARCHAR(255) NULL,
  active TINYINT NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (assigned_driver_id) REFERENCES personnel(id),
  FOREIGN KEY (assigned_helper_id) REFERENCES personnel(id),
  INDEX idx_vehicle_status (truck_type, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Every status change of a truck. Used for down-truck days
-- (CONTAINER MONITORING "TRUCK STATUS": COUNTIF(...,"DT")).
CREATE TABLE vehicle_status_log (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  vehicle_id INT NOT NULL,
  from_status VARCHAR(30) NULL,
  to_status VARCHAR(30) NOT NULL,
  reason VARCHAR(255) NULL,
  actor_id INT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (vehicle_id) REFERENCES vehicles(id),
  FOREIGN KEY (actor_id) REFERENCES users(id),
  INDEX idx_vsl (vehicle_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- DOCUMENTS  (FMS PDF II.M and II.O). Files are stored in
-- storage/private with random names; never served directly.
-- ---------------------------------------------------------------------
CREATE TABLE documents (
  id INT AUTO_INCREMENT PRIMARY KEY,
  category VARCHAR(80) NOT NULL,
  entity_type VARCHAR(40) NOT NULL,
  entity_id INT NULL,
  original_filename VARCHAR(220) NOT NULL,
  storage_name VARCHAR(100) NOT NULL UNIQUE,
  mime_type VARCHAR(100) NOT NULL,
  byte_size INT NOT NULL,
  expires_on DATE NULL,
  notes VARCHAR(255) NULL,
  uploaded_by INT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (uploaded_by) REFERENCES users(id),
  INDEX idx_doc_expiry (expires_on),
  INDEX idx_doc_entity (entity_type, entity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
