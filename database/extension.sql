-- =====================================================================
-- R.P. Mulawin Fleet Management System V2  |  database/extension.sql
-- PART 2 OF 2: module tables. Import AFTER database/schema.sql.
-- Empty database only. No sample company data.
-- =====================================================================
SET NAMES utf8mb4;

-- =====================================================================
-- OPERATIONS MODULE  (FMS PDF II.D, II.E, II.F, II.G, pp.16-18)
-- =====================================================================

-- Trip status flow (bagong FMS PDF, Scenario A, B at C):
--   Pending Review     (Scenario B: dispatcher delivery request, waiting for Ops Head)
--   Awaiting Resources (confirmed but no free truck/driver yet)
--   Pre-Assigned       (system auto-assigned truck, driver, helper)
--   Ready for Dispatch (pre-dispatch checklist all OK)
--   Dispatched         (dispatcher confirmed dispatch; fund was Released)
--   On Hold - Incident Reported (truck breakdown, Maintenance assessing)
--   In Transit         (resumed after a minor repair)
--   For Reschedule     (major damage; truck under repair)
--   Returned           (arrival checklist done)
--   Completed          (dispatcher submitted the completed trip record)
--   Cancelled / Rejected
-- attempt_number: 1 = first dispatch; 2 = Second Attempt after major damage.
CREATE TABLE trips (
  id INT AUTO_INCREMENT PRIMARY KEY,
  trip_ref VARCHAR(32) NOT NULL UNIQUE,
  intake_source VARCHAR(60) NOT NULL COMMENT 'Scenario A (Client to Operations Head) or Scenario B (Client to Dispatcher)',
  created_by INT NOT NULL,
  client_id INT NOT NULL,
  truck_type VARCHAR(25) NULL,
  dispatcher_role VARCHAR(50) NULL COMMENT 'type of dispatcher chosen by Ops Head',
  dispatcher_id INT NULL,
  route_id INT NULL,
  origin VARCHAR(200) NOT NULL,
  destination VARCHAR(200) NOT NULL,
  distance_km DECIMAL(10,2) NULL,
  scheduled_at DATETIME NOT NULL,
  booking_reference VARCHAR(150) NULL COMMENT 'booking or nomination details',
  units_planned INT NULL COMMENT 'car carrier units',
  container_number VARCHAR(60) NULL,
  container_size VARCHAR(20) NULL COMMENT 'e.g. 1X20, X40',
  seal_number VARCHAR(60) NULL,
  shipment_number VARCHAR(60) NULL,
  client_rate DECIMAL(12,2) NULL COMMENT 'expected billing amount for this trip (from client rate)',
  vehicle_id INT NULL,
  driver_id INT NULL,
  driver2_id INT NULL COMMENT 'car carrier DRIVER 2',
  helper_id INT NULL,
  assignment_mode VARCHAR(20) NULL COMMENT 'Auto or Manual',
  status VARCHAR(35) NOT NULL DEFAULT 'Pending Review',
  attempt_number INT NOT NULL DEFAULT 1,
  approved_by INT NULL,
  approved_at DATETIME NULL,
  client_confirmed_at DATETIME NULL COMMENT 'container: booking/nomination confirmed; wing van: trip confirmed with client',
  pre_advice_sent_at DATETIME NULL COMMENT 'container: pre-advice sent to client',
  client_advised_at DATETIME NULL COMMENT 'wing van: truck details and plate given to client',
  empty_container_note VARCHAR(255) NULL,
  departure_at DATETIME NULL,
  arrival_at DATETIME NULL,
  pre_odometer INT NULL,
  arrival_odometer INT NULL,
  km_traveled INT NULL,
  units_delivered INT NULL,
  delivery_notes VARCHAR(500) NULL,
  remarks VARCHAR(500) NULL,
  cancel_reason VARCHAR(300) NULL,
  completed_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (created_by) REFERENCES users(id),
  FOREIGN KEY (client_id) REFERENCES clients(id),
  FOREIGN KEY (dispatcher_id) REFERENCES users(id),
  FOREIGN KEY (route_id) REFERENCES routes(id),
  FOREIGN KEY (vehicle_id) REFERENCES vehicles(id),
  FOREIGN KEY (driver_id) REFERENCES personnel(id),
  FOREIGN KEY (driver2_id) REFERENCES personnel(id),
  FOREIGN KEY (helper_id) REFERENCES personnel(id),
  INDEX idx_trip_schedule (scheduled_at),
  INDEX idx_trip_status (status),
  INDEX idx_trip_vehicle (vehicle_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- History of every approval, assignment, dispatch and completion.
CREATE TABLE trip_events (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  trip_id INT NOT NULL,
  actor_id INT NULL,
  event_type VARCHAR(80) NOT NULL,
  from_status VARCHAR(35) NOT NULL DEFAULT '',
  to_status VARCHAR(35) NOT NULL DEFAULT '',
  notes VARCHAR(500) NOT NULL DEFAULT '',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (trip_id) REFERENCES trips(id),
  FOREIGN KEY (actor_id) REFERENCES users(id),
  INDEX idx_trip_events (trip_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Progress, delivery, delay updates. delay_cause separates client-caused
-- delays (repo docs, client questionnaire Q5/Q8).
CREATE TABLE trip_updates (
  id INT AUTO_INCREMENT PRIMARY KEY,
  trip_id INT NOT NULL,
  actor_id INT NOT NULL,
  update_type VARCHAR(30) NOT NULL COMMENT 'Progress, Loading, Unloading, Delivery, Delay, Other',
  delay_cause VARCHAR(40) NULL COMMENT 'Client, Pending Documents, Operational, Traffic, Other',
  note VARCHAR(500) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (trip_id) REFERENCES trips(id),
  FOREIGN KEY (actor_id) REFERENCES users(id),
  INDEX idx_trip_updates (trip_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Pre-departure and arrival checklists (57 items from company scans).
-- status In Progress is created by "Start checklist" for the minimum
-- duration rule.
CREATE TABLE inspections (
  id INT AUTO_INCREMENT PRIMARY KEY,
  trip_id INT NOT NULL,
  vehicle_id INT NULL,
  attempt_number INT NOT NULL,
  inspection_type VARCHAR(15) NOT NULL COMMENT 'Departure or Arrival',
  status VARCHAR(20) NOT NULL DEFAULT 'In Progress',
  items_json MEDIUMTEXT NULL,
  odometer_km INT NULL,
  fuel_level VARCHAR(20) NULL,
  has_not_ok TINYINT NOT NULL DEFAULT 0,
  started_at DATETIME NOT NULL,
  completed_at DATETIME NULL,
  actor_id INT NOT NULL,
  UNIQUE KEY uq_inspection (trip_id, attempt_number, inspection_type),
  FOREIGN KEY (trip_id) REFERENCES trips(id),
  FOREIGN KEY (vehicle_id) REFERENCES vehicles(id),
  FOREIGN KEY (actor_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Incidents reported by dispatcher (driver calls dispatcher). Bagong PDF Scenario C.
-- Maintenance assesses severity:
--   Minor: Reported -> Under Repair -> Repaired - Cleared to Continue -> Resolved (trip resumed)
--   Major: Reported -> For Reschedule -> Resolved (truck repaired)
CREATE TABLE incidents (
  id INT AUTO_INCREMENT PRIMARY KEY,
  trip_id INT NOT NULL,
  vehicle_id INT NOT NULL,
  attempt_number INT NOT NULL DEFAULT 1,
  severity VARCHAR(10) NULL COMMENT 'Minor or Major, set by Maintenance assessment',
  incident_at DATETIME NULL COMMENT 'date and time of incident',
  details TEXT NOT NULL COMMENT 'incident description',
  problem VARCHAR(255) NULL COMMENT 'reported problem or damage',
  location VARCHAR(200) NULL,
  cause VARCHAR(255) NULL COMMENT 'TRIPS & DAMAGES: CAUSE (IF WITH DAMAGE)',
  damaged_unit VARCHAR(150) NULL COMMENT 'TRIPS & DAMAGES: DAMAGE UNIT / VIN',
  action_taken VARCHAR(255) NULL,
  status VARCHAR(40) NOT NULL DEFAULT 'Reported',
  reported_by INT NOT NULL,
  assessed_by INT NULL,
  assessed_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  resolved_at DATETIME NULL,
  FOREIGN KEY (trip_id) REFERENCES trips(id),
  FOREIGN KEY (vehicle_id) REFERENCES vehicles(id),
  FOREIGN KEY (reported_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Waybills (car carrier WAYBILL NO. / RVL DR NO.; container RPM WAYBILL
-- and client waybill). submitted_on starts billing terms.
CREATE TABLE waybills (
  id INT AUTO_INCREMENT PRIMARY KEY,
  trip_id INT NOT NULL,
  waybill_number VARCHAR(100) NOT NULL,
  client_waybill_number VARCHAR(100) NULL,
  dr_number VARCHAR(100) NULL,
  units INT NULL,
  submitted_on DATE NULL,
  note VARCHAR(255) NULL,
  recorded_by INT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_waybill_trip_num (trip_id, waybill_number),
  FOREIGN KEY (trip_id) REFERENCES trips(id),
  FOREIGN KEY (recorded_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =====================================================================
-- FINANCE MODULE  (FMS PDF II.I, pp.16-17)
-- =====================================================================

-- Fund request status flow (bagong FMS PDF, Scenario A steps 3-5, B steps 4-6):
--   Pending Approval  -> dispatcher submitted; Operations Head approves (no amount limit)
--   Ready for Release -> Ops Head approved; Finance can release (even before the checklist)
--   Released          -> Finance confirmed release
--   Completed         -> dispatcher confirmed dispatch
--   Rejected          -> back to the dispatcher for revision and resubmission
CREATE TABLE fund_requests (
  id INT AUTO_INCREMENT PRIMARY KEY,
  trip_id INT NOT NULL,
  attempt_number INT NOT NULL DEFAULT 1,
  amount DECIMAL(12,2) NOT NULL,
  breakdown_json TEXT NULL COMMENT 'selected categories, added lines and the preset amounts used',
  approver_role VARCHAR(30) NOT NULL DEFAULT 'operations_head',
  status VARCHAR(30) NOT NULL DEFAULT 'Pending Approval',
  requested_by INT NULL COMMENT 'dispatcher who submitted the fund request',
  approved_by INT NULL, approved_at DATETIME NULL,
  released_by INT NULL, released_at DATETIME NULL,
  release_method VARCHAR(40) NULL,
  release_reference VARCHAR(100) NULL,
  rejected_reason VARCHAR(300) NULL,
  liquidation_status VARCHAR(20) NOT NULL DEFAULT 'Not Liquidated',
  liquidated_amount DECIMAL(12,2) NULL,
  returned_amount DECIMAL(12,2) NULL,
  liquidation_notes VARCHAR(300) NULL,
  liquidated_by INT NULL, liquidated_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (trip_id) REFERENCES trips(id),
  INDEX idx_fund_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE suppliers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL UNIQUE,
  contact_person VARCHAR(120) NULL,
  contact_number VARCHAR(60) NULL,
  email VARCHAR(120) NULL,
  address VARCHAR(255) NULL,
  notes VARCHAR(255) NULL,
  active TINYINT NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Expenses. source tells where the row came from so totals are not
-- counted twice (Fund Release, Payroll, Maintenance, Parts Receipt, Manual).
CREATE TABLE expenses (
  id INT AUTO_INCREMENT PRIMARY KEY,
  category VARCHAR(60) NOT NULL COMMENT 'Fuel, RFID/Toll, Trip Allowance, Payroll, Maintenance, Parts, Office, Utilities, Other',
  trip_id INT NULL,
  vehicle_id INT NULL,
  amount DECIMAL(12,2) NOT NULL,
  incurred_on DATE NOT NULL,
  payee VARCHAR(150) NULL,
  reference_number VARCHAR(100) NULL,
  description VARCHAR(500) NULL,
  source VARCHAR(30) NOT NULL DEFAULT 'Manual',
  source_id INT NULL,
  recorded_by INT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (trip_id) REFERENCES trips(id),
  FOREIGN KEY (vehicle_id) REFERENCES vehicles(id),
  INDEX idx_expense_date (incurred_on),
  INDEX idx_expense_source (source, source_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE accounts_payable (
  id INT AUTO_INCREMENT PRIMARY KEY,
  supplier_id INT NULL,
  payee VARCHAR(150) NULL,
  reference_number VARCHAR(100) NULL,
  description VARCHAR(255) NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  paid_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  due_on DATE NULL,
  status VARCHAR(30) NOT NULL DEFAULT 'Unpaid' COMMENT 'Unpaid, Partially Paid, Paid',
  source VARCHAR(30) NOT NULL DEFAULT 'Manual',
  source_id INT NULL,
  recorded_by INT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (supplier_id) REFERENCES suppliers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE payment_vouchers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  voucher_number VARCHAR(40) NOT NULL UNIQUE,
  reference_type VARCHAR(30) NOT NULL COMMENT 'fund_request, payable, payroll, cash_advance, other',
  reference_id INT NULL,
  payee VARCHAR(180) NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  details VARCHAR(500) NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'Prepared' COMMENT 'Prepared, Paid, Cancelled',
  created_by INT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Every money release: fund releases, payable payments, payroll, cash
-- advances. Cheque details recorded here (PDF: cheque payments).
CREATE TABLE disbursements (
  id INT AUTO_INCREMENT PRIMARY KEY,
  source_type VARCHAR(30) NOT NULL,
  source_id INT NULL,
  voucher_id INT NULL,
  payee VARCHAR(180) NULL,
  payment_method VARCHAR(30) NOT NULL COMMENT 'Cash, Cheque, Bank Transfer, E-wallet, Other',
  cheque_number VARCHAR(60) NULL,
  bank_name VARCHAR(100) NULL,
  reference_number VARCHAR(100) NULL,
  amount DECIMAL(12,2) NOT NULL,
  released_on DATE NOT NULL,
  recorded_by INT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (voucher_id) REFERENCES payment_vouchers(id),
  FOREIGN KEY (recorded_by) REFERENCES users(id),
  INDEX idx_disb_date (released_on)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Budget set by Finance. Purchase approvals are checked against the
-- active "Purchasing" budget (PDF p.17).
CREATE TABLE budgets (
  id INT AUTO_INCREMENT PRIMARY KEY,
  category VARCHAR(60) NOT NULL,
  budget_amount DECIMAL(12,2) NOT NULL,
  used_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  period_start DATE NULL,
  period_end DATE NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'Active',
  set_by INT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (set_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =====================================================================
-- PURCHASING AND INVENTORY  (FMS PDF II.K, p.17)
-- =====================================================================
CREATE TABLE parts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  part_number VARCHAR(70) NOT NULL UNIQUE,
  description VARCHAR(200) NOT NULL,
  category VARCHAR(80) NULL,
  unit VARCHAR(20) NOT NULL DEFAULT 'pc',
  unit_cost DECIMAL(12,2) NOT NULL DEFAULT 0,
  reorder_level INT NOT NULL DEFAULT 0,
  quantity_on_hand INT NOT NULL DEFAULT 0,
  warranty_info VARCHAR(150) NULL,
  default_supplier_id INT NULL,
  active TINYINT NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (default_supplier_id) REFERENCES suppliers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE maintenance_jobs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  job_number VARCHAR(30) NULL UNIQUE,
  vehicle_id INT NOT NULL,
  source VARCHAR(20) NOT NULL DEFAULT 'Manual' COMMENT 'Incident, Manual, Preventive, Inspection',
  incident_id INT NULL,
  trip_id INT NULL,
  maintenance_type VARCHAR(40) NOT NULL DEFAULT 'Repair' COMMENT 'Repair, Roadside Repair, Preventive Maintenance',
  description TEXT NOT NULL,
  assigned_to VARCHAR(150) NULL COMMENT 'mechanic or service provider',
  status VARCHAR(30) NOT NULL DEFAULT 'Reported' COMMENT 'Reported, Scheduled, In Progress, Waiting for Parts, Completed, Cancelled',
  expected_completion DATE NULL COMMENT 'expected vehicle availability',
  odometer_at_service INT NULL,
  started_at DATETIME NULL,
  completed_at DATETIME NULL,
  created_by INT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (vehicle_id) REFERENCES vehicles(id),
  FOREIGN KEY (incident_id) REFERENCES incidents(id),
  FOREIGN KEY (trip_id) REFERENCES trips(id),
  INDEX idx_mjob_status (vehicle_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Stock-out request: Motorpool asks Purchasing for parts (PDF p.17 A).
CREATE TABLE part_requests (
  id INT AUTO_INCREMENT PRIMARY KEY,
  vehicle_id INT NOT NULL,
  maintenance_job_id INT NULL,
  part_id INT NOT NULL,
  requested_qty INT NOT NULL,
  status VARCHAR(30) NOT NULL DEFAULT 'Requested' COMMENT 'Requested, Issued, Not Available, Cancelled',
  notes VARCHAR(255) NULL,
  requested_by INT NOT NULL,
  issued_by INT NULL,
  issued_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (vehicle_id) REFERENCES vehicles(id),
  FOREIGN KEY (maintenance_job_id) REFERENCES maintenance_jobs(id),
  FOREIGN KEY (part_id) REFERENCES parts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Stock-in purchase request (PDF p.17 B). approver_role decided by the
-- purchase limit; then the system checks the Finance budget.
CREATE TABLE purchase_requests (
  id INT AUTO_INCREMENT PRIMARY KEY,
  pr_number VARCHAR(30) NULL UNIQUE,
  part_id INT NOT NULL,
  part_request_id INT NULL,
  supplier_id INT NULL,
  quantity INT NOT NULL,
  unit_cost DECIMAL(12,2) NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  reason VARCHAR(255) NULL,
  approver_role VARCHAR(30) NOT NULL,
  status VARCHAR(45) NOT NULL DEFAULT 'Pending Approval' COMMENT 'Pending Approval, Approved, Rejected, Rejected - Insufficient Budget, Ordered, Received',
  budget_id INT NULL,
  requested_by INT NOT NULL,
  decided_by INT NULL,
  decided_at DATETIME NULL,
  decision_notes VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (part_id) REFERENCES parts(id),
  FOREIGN KEY (part_request_id) REFERENCES part_requests(id),
  FOREIGN KEY (supplier_id) REFERENCES suppliers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE purchase_orders (
  id INT AUTO_INCREMENT PRIMARY KEY,
  po_number VARCHAR(40) NOT NULL UNIQUE,
  purchase_request_id INT NOT NULL UNIQUE,
  supplier_id INT NULL,
  ordered_on DATE NOT NULL,
  expected_on DATE NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'Ordered' COMMENT 'Ordered, Received',
  received_on DATE NULL,
  receipt_reference VARCHAR(100) NULL,
  warranty_until DATE NULL,
  entered_by INT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (purchase_request_id) REFERENCES purchase_requests(id),
  FOREIGN KEY (supplier_id) REFERENCES suppliers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE stock_movements (
  id INT AUTO_INCREMENT PRIMARY KEY,
  part_id INT NOT NULL,
  movement_type VARCHAR(20) NOT NULL COMMENT 'Stock In, Stock Out, Adjustment',
  quantity_delta INT NOT NULL,
  unit_cost DECIMAL(12,2) NULL,
  reason VARCHAR(150) NOT NULL,
  reference_type VARCHAR(30) NULL,
  reference_id INT NULL,
  vehicle_id INT NULL,
  actor_id INT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (part_id) REFERENCES parts(id),
  INDEX idx_stock_part (part_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =====================================================================
-- MAINTENANCE  (FMS PDF II.L, pp.17-18)
-- =====================================================================
CREATE TABLE maintenance_parts_used (
  id INT AUTO_INCREMENT PRIMARY KEY,
  maintenance_job_id INT NOT NULL,
  part_request_id INT NOT NULL,
  part_id INT NOT NULL,
  quantity INT NOT NULL,
  unit_cost DECIMAL(12,2) NOT NULL DEFAULT 0,
  recorded_by INT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (maintenance_job_id) REFERENCES maintenance_jobs(id),
  FOREIGN KEY (part_request_id) REFERENCES part_requests(id),
  FOREIGN KEY (part_id) REFERENCES parts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE maintenance_costs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  maintenance_job_id INT NOT NULL,
  cost_type VARCHAR(30) NOT NULL COMMENT 'Labor, Outside Service, Materials, Other',
  description VARCHAR(255) NOT NULL,
  provider VARCHAR(150) NULL,
  amount DECIMAL(12,2) NOT NULL,
  incurred_on DATE NOT NULL,
  recorded_by INT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (maintenance_job_id) REFERENCES maintenance_jobs(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE maintenance_schedules (
  id INT AUTO_INCREMENT PRIMARY KEY,
  vehicle_id INT NOT NULL,
  maintenance_type VARCHAR(100) NOT NULL,
  due_on DATE NULL,
  due_odometer_km INT NULL,
  notes VARCHAR(255) NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'Scheduled' COMMENT 'Scheduled, Completed, Cancelled',
  maintenance_job_id INT NULL,
  created_by INT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (vehicle_id) REFERENCES vehicles(id),
  FOREIGN KEY (maintenance_job_id) REFERENCES maintenance_jobs(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =====================================================================
-- BILLING AND ACCOUNTS RECEIVABLE  (FMS PDF II.H)
-- =====================================================================
-- Client-specific and route-based rates.
CREATE TABLE client_rates (
  id INT AUTO_INCREMENT PRIMARY KEY,
  client_id INT NOT NULL,
  route_id INT NULL,
  truck_type VARCHAR(25) NOT NULL,
  rate_basis VARCHAR(20) NOT NULL COMMENT 'Per Trip, Per Unit, Per KM',
  amount DECIMAL(12,2) NOT NULL,
  effective_on DATE NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'Active',
  created_by INT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (client_id) REFERENCES clients(id),
  FOREIGN KEY (route_id) REFERENCES routes(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE billings (
  id INT AUTO_INCREMENT PRIMARY KEY,
  invoice_number VARCHAR(70) NOT NULL UNIQUE,
  client_id INT NOT NULL,
  invoice_date DATE NOT NULL,
  due_date DATE NULL,
  gross_amount DECIMAL(12,2) NOT NULL,
  discount DECIMAL(12,2) NOT NULL DEFAULT 0,
  net_amount DECIMAL(12,2) NOT NULL,
  status VARCHAR(30) NOT NULL DEFAULT 'Issued' COMMENT 'Issued, Partially Paid, Paid, Cancelled',
  notes VARCHAR(255) NULL,
  created_by INT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (client_id) REFERENCES clients(id),
  INDEX idx_billing_date (invoice_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- One row per billed trip. A trip can only be billed once.
CREATE TABLE billing_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  billing_id INT NOT NULL,
  trip_id INT NOT NULL UNIQUE,
  description VARCHAR(255) NULL,
  rate_basis VARCHAR(20) NULL,
  quantity DECIMAL(10,2) NOT NULL DEFAULT 1,
  rate DECIMAL(12,2) NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  FOREIGN KEY (billing_id) REFERENCES billings(id),
  FOREIGN KEY (trip_id) REFERENCES trips(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE collections (
  id INT AUTO_INCREMENT PRIMARY KEY,
  billing_id INT NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  payment_date DATE NOT NULL,
  payment_method VARCHAR(30) NOT NULL,
  reference VARCHAR(100) NOT NULL,
  cheque_number VARCHAR(60) NULL,
  bank_name VARCHAR(100) NULL,
  recorded_by INT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (billing_id) REFERENCES billings(id),
  INDEX idx_collection_date (payment_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =====================================================================
-- PAYROLL AND TIMEKEEPING  (FMS PDF II.J; DRIVERS PAYROLL.xlsx)
-- Timekeeping (attendance) is kept separate from payroll disbursement.
-- =====================================================================
-- Trip pay rate per destination: driver and helper have different rates
-- (repo docs: client questionnaire Q23 "fixed trip pay rate per
-- destination x number of trips").
CREATE TABLE trip_pay_rates (
  id INT AUTO_INCREMENT PRIMARY KEY,
  route_id INT NULL,
  destination VARCHAR(150) NULL COMMENT 'used when no route is linked',
  truck_type VARCHAR(25) NOT NULL,
  driver_rate DECIMAL(10,2) NOT NULL DEFAULT 0,
  helper_rate DECIMAL(10,2) NOT NULL DEFAULT 0,
  active TINYINT NOT NULL DEFAULT 1,
  created_by INT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (route_id) REFERENCES routes(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE attendance (
  id INT AUTO_INCREMENT PRIMARY KEY,
  personnel_id INT NOT NULL,
  work_date DATE NOT NULL,
  status VARCHAR(20) NOT NULL COMMENT 'Present, Absent, VL, SIL, Rest Day',
  remarks VARCHAR(200) NULL,
  recorded_by INT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_attendance (personnel_id, work_date),
  FOREIGN KEY (personnel_id) REFERENCES personnel(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Balances: cash advance, uniform, damages (hulugan) and cash bond (ipon).
CREATE TABLE employee_ledgers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  personnel_id INT NOT NULL,
  ledger_type VARCHAR(30) NOT NULL COMMENT 'Cash Advance, Uniform, Damages, Cash Bond',
  description VARCHAR(255) NULL,
  total_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  installment DECIMAL(12,2) NOT NULL DEFAULT 0 COMMENT 'amount deducted per payroll',
  balance DECIMAL(12,2) NOT NULL DEFAULT 0,
  status VARCHAR(20) NOT NULL DEFAULT 'Open',
  source VARCHAR(30) NULL,
  source_id INT NULL,
  created_by INT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (personnel_id) REFERENCES personnel(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE ledger_entries (
  id INT AUTO_INCREMENT PRIMARY KEY,
  ledger_id INT NOT NULL,
  entry_type VARCHAR(20) NOT NULL COMMENT 'Charge, Payment, Contribution',
  amount DECIMAL(12,2) NOT NULL,
  payroll_line_id INT NULL,
  note VARCHAR(255) NULL,
  created_by INT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (ledger_id) REFERENCES employee_ledgers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Payroll period status: Draft, Finalized, Submitted to Finance, Disbursed
CREATE TABLE payroll_periods (
  id INT AUTO_INCREMENT PRIMARY KEY,
  period_name VARCHAR(120) NOT NULL,
  payroll_group VARCHAR(60) NULL,
  date_from DATE NOT NULL,
  date_to DATE NOT NULL,
  status VARCHAR(30) NOT NULL DEFAULT 'Draft',
  formula_snapshot TEXT NULL,
  created_by INT NOT NULL,
  finalized_by INT NULL, finalized_at DATETIME NULL,
  submitted_at DATETIME NULL,
  disbursed_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE payroll_lines (
  id INT AUTO_INCREMENT PRIMARY KEY,
  period_id INT NOT NULL,
  personnel_id INT NOT NULL,
  daily_rate DECIMAL(10,2) NOT NULL,
  days_worked DECIMAL(6,2) NOT NULL DEFAULT 0,
  trips_count INT NOT NULL DEFAULT 0,
  regular_wage DECIMAL(12,2) NOT NULL DEFAULT 0,
  trip_pay DECIMAL(12,2) NOT NULL DEFAULT 0,
  earnings_json TEXT NULL,
  gross_pay DECIMAL(12,2) NOT NULL DEFAULT 0,
  deductions_json TEXT NULL,
  total_deductions DECIMAL(12,2) NOT NULL DEFAULT 0,
  net_pay DECIMAL(12,2) NOT NULL DEFAULT 0,
  notes VARCHAR(255) NULL,
  UNIQUE KEY uq_payroll_line (period_id, personnel_id),
  FOREIGN KEY (period_id) REFERENCES payroll_periods(id),
  FOREIGN KEY (personnel_id) REFERENCES personnel(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
