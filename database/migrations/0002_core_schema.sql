-- ===== CORE ENTITIES =====

CREATE TABLE users (
  id CHAR(36) PRIMARY KEY,
  email VARCHAR(255) UNIQUE NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  full_name VARCHAR(255),
  role ENUM('traveler','business','admin') NOT NULL,
  country VARCHAR(100),
  preferred_currency VARCHAR(10),
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE businesses (
  id CHAR(36) PRIMARY KEY,
  owner_user_id CHAR(36) NOT NULL,
  business_name VARCHAR(255) NOT NULL,
  contact_email VARCHAR(255) NOT NULL,
  contact_phone VARCHAR(50),
  location ENUM('da_nang','hoi_an') NOT NULL,
  verification_status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  verification_docs JSON,
  payout_bank_details JSON,          -- encrypt at application layer before insert
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (owner_user_id) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE destinations (
  id CHAR(36) PRIMARY KEY,
  slug VARCHAR(100) UNIQUE NOT NULL,
  name VARCHAR(255) NOT NULL,
  description TEXT,
  hero_image_url VARCHAR(500)
) ENGINE=InnoDB;

CREATE TABLE categories (
  id CHAR(36) PRIMARY KEY,
  slug VARCHAR(100) UNIQUE NOT NULL,
  name VARCHAR(255) NOT NULL,
  icon VARCHAR(100)
) ENGINE=InnoDB;

CREATE TABLE experiences (
  id CHAR(36) PRIMARY KEY,
  business_id CHAR(36) NOT NULL,
  destination_id CHAR(36) NOT NULL,
  category_id CHAR(36) NOT NULL,
  title VARCHAR(255) NOT NULL,
  slug VARCHAR(255) UNIQUE NOT NULL,
  description TEXT NOT NULL,
  duration_minutes INT NOT NULL,
  max_group_size INT NOT NULL,
  price_amount DECIMAL(10,2) NOT NULL,
  price_currency VARCHAR(10) NOT NULL DEFAULT 'USD',
  languages JSON,
  included_items JSON,
  cancellation_policy TEXT,
  status ENUM('draft','pending_review','published','suspended') NOT NULL DEFAULT 'draft',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (business_id) REFERENCES businesses(id),
  FOREIGN KEY (destination_id) REFERENCES destinations(id),
  FOREIGN KEY (category_id) REFERENCES categories(id),
  INDEX idx_dest_status (destination_id, status),
  INDEX idx_cat_status (category_id, status)
) ENGINE=InnoDB;

CREATE TABLE experience_images (
  id CHAR(36) PRIMARY KEY,
  experience_id CHAR(36) NOT NULL,
  image_url VARCHAR(500) NOT NULL,
  display_order INT DEFAULT 0,
  FOREIGN KEY (experience_id) REFERENCES experiences(id)
) ENGINE=InnoDB;

CREATE TABLE experience_availability (
  id CHAR(36) PRIMARY KEY,
  experience_id CHAR(36) NOT NULL,
  date DATE NOT NULL,
  slots_total INT NOT NULL,
  slots_booked INT NOT NULL DEFAULT 0,
  UNIQUE KEY uq_exp_date (experience_id, date),
  FOREIGN KEY (experience_id) REFERENCES experiences(id)
) ENGINE=InnoDB;

-- ===== BOOKING & PAYMENT =====

CREATE TABLE bookings (
  id CHAR(36) PRIMARY KEY,
  experience_id CHAR(36) NOT NULL,
  traveler_id CHAR(36) NOT NULL,
  booking_date DATE NOT NULL,
  guest_count INT NOT NULL,
  total_amount DECIMAL(10,2) NOT NULL,
  currency VARCHAR(10) NOT NULL,
  commission_amount DECIMAL(10,2) NOT NULL,
  status ENUM('pending','confirmed','completed','cancelled','refunded') NOT NULL DEFAULT 'pending',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (experience_id) REFERENCES experiences(id),
  FOREIGN KEY (traveler_id) REFERENCES users(id),
  INDEX idx_traveler (traveler_id),
  INDEX idx_experience (experience_id)
) ENGINE=InnoDB;

CREATE TABLE payments (
  id CHAR(36) PRIMARY KEY,
  booking_id CHAR(36) NOT NULL,
  provider ENUM('stripe','razorpay','payu','vnpay') NOT NULL,
  provider_payment_id VARCHAR(255) NOT NULL,
  amount DECIMAL(10,2) NOT NULL,
  currency VARCHAR(10) NOT NULL,
  status ENUM('pending','succeeded','failed','refunded') NOT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (booking_id) REFERENCES bookings(id)
) ENGINE=InnoDB;

CREATE TABLE payouts (
  id CHAR(36) PRIMARY KEY,
  business_id CHAR(36) NOT NULL,
  booking_id CHAR(36) NOT NULL,
  amount_owed DECIMAL(10,2) NOT NULL,
  status ENUM('accrued','paid','disputed') NOT NULL DEFAULT 'accrued',
  paid_at DATETIME NULL,
  payout_batch_ref VARCHAR(255),
  FOREIGN KEY (business_id) REFERENCES businesses(id),
  FOREIGN KEY (booking_id) REFERENCES bookings(id),
  INDEX idx_business_status (business_id, status)
) ENGINE=InnoDB;

CREATE TABLE reviews (
  id CHAR(36) PRIMARY KEY,
  booking_id CHAR(36) NOT NULL,
  experience_id CHAR(36) NOT NULL,
  traveler_id CHAR(36) NOT NULL,
  rating TINYINT NOT NULL,
  comment TEXT,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (booking_id) REFERENCES bookings(id),
  FOREIGN KEY (experience_id) REFERENCES experiences(id),
  FOREIGN KEY (traveler_id) REFERENCES users(id),
  CHECK (rating BETWEEN 1 AND 5)
) ENGINE=InnoDB;
