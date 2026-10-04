-- Migration v6: Fun Run Organizer Assistant Phase 1
-- Race Categories, Bib Numbers, Race Kit Inventory, Route Checkpoint Types

CREATE TABLE race_categories (
  id INT AUTO_INCREMENT PRIMARY KEY,
  event_id INT NOT NULL,
  name VARCHAR(50) NOT NULL,
  fee DECIMAL(10,2) NOT NULL DEFAULT 0,
  max_participants INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_event_category (event_id, name),
  FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE registrations
  ADD COLUMN race_category_id INT NULL AFTER distance_category,
  ADD COLUMN bib_number VARCHAR(20) NULL AFTER race_category_id,
  ADD UNIQUE KEY uq_event_bib (event_id, bib_number),
  ADD FOREIGN KEY (race_category_id) REFERENCES race_categories(id);

ALTER TABLE event_items
  ADD COLUMN item_type ENUM('kit','shirt','bib','medal','other') NOT NULL DEFAULT 'other' AFTER item_name,
  ADD COLUMN variant_label VARCHAR(50) NULL AFTER item_type,
  ADD COLUMN low_stock_threshold INT NOT NULL DEFAULT 0 AFTER claimed_quantity;

ALTER TABLE event_checkpoints
  ADD COLUMN checkpoint_type ENUM('start','finish','checkpoint','water_station','medical_station') NOT NULL DEFAULT 'checkpoint' AFTER label;
