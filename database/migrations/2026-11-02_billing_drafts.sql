-- =====================================================================
--  Migration 2026-11-02  —  Billing draft (FMS PDF v7, Billing and Collection step)
--  Para sa EXISTING na database lang (halimbawa ang live sa Hostinger).
--  Ang bagong install ay gumagamit na ng schema.sql + extension.sql (kasama na ito).
--
--  Paano: phpMyAdmin > piliin ang database > Import > piliin ang file na ito.
--  Gumawa muna ng backup (Admin > Backup) bago i-import.
--  Ligtas itong i-import nang isang beses lang.
-- =====================================================================

CREATE TABLE IF NOT EXISTS billing_drafts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  trip_id INT NOT NULL UNIQUE,
  client_id INT NOT NULL,
  rate_basis VARCHAR(20) NULL COMMENT 'Per Trip, Per Unit, Per KM (galing sa client rate ng route)',
  quantity DECIMAL(10,2) NOT NULL DEFAULT 1,
  rate DECIMAL(12,2) NULL COMMENT 'NULL = walang client rate para sa route; ilalagay ng Billing',
  amount DECIMAL(12,2) NULL,
  description VARCHAR(255) NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'Draft' COMMENT 'Draft, Finalized, Invoiced',
  docs_verified_by INT NULL,
  docs_verified_at DATETIME NULL,
  docs_note VARCHAR(255) NULL,
  finalized_by INT NULL,
  finalized_at DATETIME NULL,
  billing_id INT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (trip_id) REFERENCES trips(id),
  FOREIGN KEY (client_id) REFERENCES clients(id),
  FOREIGN KEY (docs_verified_by) REFERENCES users(id),
  FOREIGN KEY (finalized_by) REFERENCES users(id),
  FOREIGN KEY (billing_id) REFERENCES billings(id),
  INDEX idx_billing_draft_status (status, client_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Mga trip na nakaalis na o tapos na pero wala pang invoice: gagawan ng draft
-- (ang halaga ay ang naka-save na expected rate ng trip; pwedeng i-edit ng Billing).
INSERT IGNORE INTO billing_drafts (trip_id, client_id, rate_basis, quantity, rate, amount, description)
SELECT t.id, t.client_id, 'Per Trip', 1, t.client_rate, t.client_rate,
       CONCAT(t.trip_ref, ' ', COALESCE(t.origin, ''), ' - ', COALESCE(t.destination, ''))
FROM trips t
WHERE t.status IN ('Dispatched', 'On Hold - Incident Reported', 'In Transit', 'Returned', 'Completed')
  AND NOT EXISTS (SELECT 1 FROM billing_items bi JOIN billings b ON b.id = bi.billing_id
                  WHERE bi.trip_id = t.id AND b.status <> 'Cancelled');

-- Mga trip na naka-invoice na: itatala bilang Invoiced para buo ang kasaysayan.
INSERT IGNORE INTO billing_drafts (trip_id, client_id, rate_basis, quantity, rate, amount, description, status, billing_id)
SELECT bi.trip_id, b.client_id, bi.rate_basis, bi.quantity, bi.rate, bi.amount, bi.description, 'Invoiced', b.id
FROM billing_items bi JOIN billings b ON b.id = bi.billing_id
WHERE b.status <> 'Cancelled';
