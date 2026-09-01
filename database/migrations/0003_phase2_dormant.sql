-- Phase 2 tables: schema-ready but MUST NOT be used by application code
-- until the 30-operator / >=3-bookings-each threshold is met.
-- ===== PHASE 2 — SCHEMA-READY, DORMANT =====

CREATE TABLE trip_requests (
  id CHAR(36) PRIMARY KEY,
  traveler_id CHAR(36) NOT NULL,
  destination_id CHAR(36),
  budget_amount DECIMAL(10,2),
  budget_currency VARCHAR(10),
  duration_days INT,
  interests JSON,
  travel_start DATE,
  travel_end DATE,
  status ENUM('open','matched','expired','cancelled') NOT NULL DEFAULT 'open',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (traveler_id) REFERENCES users(id),
  FOREIGN KEY (destination_id) REFERENCES destinations(id)
) ENGINE=InnoDB;

CREATE TABLE bids (
  id CHAR(36) PRIMARY KEY,
  trip_request_id CHAR(36) NOT NULL,
  business_id CHAR(36) NOT NULL,
  proposed_price DECIMAL(10,2) NOT NULL,
  proposal_details TEXT,
  status ENUM('submitted','accepted','rejected','expired') NOT NULL DEFAULT 'submitted',
  expires_at DATETIME,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (trip_request_id) REFERENCES trip_requests(id),
  FOREIGN KEY (business_id) REFERENCES businesses(id)
) ENGINE=InnoDB;
