-- Catalogue: what the store holds. These rows describe things; they never
-- carry stock levels or status. Stock and status live in `movements`.

CREATE TABLE gear_types (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(32) NOT NULL UNIQUE,
    name VARCHAR(100) NOT NULL,
    category VARCHAR(40) NOT NULL
) ENGINE=InnoDB;

-- One row per serialised item. Never updated after insert, which is what
-- makes these rows safe to lock as a mutex when packing.
CREATE TABLE gear_items (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    gear_type_id INT UNSIGNED NOT NULL,
    serial VARCHAR(40) NOT NULL UNIQUE,
    acquired_on DATE NOT NULL,
    CONSTRAINT fk_gear_items_type FOREIGN KEY (gear_type_id) REFERENCES gear_types (id)
) ENGINE=InnoDB;

CREATE TABLE consumables (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(32) NOT NULL UNIQUE,
    name VARCHAR(100) NOT NULL,
    unit VARCHAR(20) NOT NULL,
    reorder_point INT UNSIGNED NOT NULL
) ENGINE=InnoDB;

-- A supplier lot, created by receiving. Also never updated.
CREATE TABLE lots (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    consumable_id INT UNSIGNED NOT NULL,
    lot_code VARCHAR(40) NOT NULL,
    received_on DATE NOT NULL,
    CONSTRAINT fk_lots_consumable FOREIGN KEY (consumable_id) REFERENCES consumables (id),
    UNIQUE KEY uq_lots_code (consumable_id, lot_code),
    KEY idx_lots_fifo (consumable_id, received_on, id)
) ENGINE=InnoDB;

CREATE TABLE kit_templates (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(32) NOT NULL UNIQUE,
    name VARCHAR(100) NOT NULL,
    description VARCHAR(255) NOT NULL DEFAULT '',
    is_subkit BOOLEAN NOT NULL DEFAULT FALSE
) ENGINE=InnoDB;

-- A recipe line points at exactly one of: a gear type, a consumable, or a
-- child template (a sub-kit). per_position lines scale with the planner count.
CREATE TABLE template_lines (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    template_id INT UNSIGNED NOT NULL,
    qty INT UNSIGNED NOT NULL,
    per_position BOOLEAN NOT NULL DEFAULT FALSE,
    gear_type_id INT UNSIGNED NULL,
    consumable_id INT UNSIGNED NULL,
    child_template_id INT UNSIGNED NULL,
    CONSTRAINT fk_lines_template FOREIGN KEY (template_id) REFERENCES kit_templates (id),
    CONSTRAINT fk_lines_gear_type FOREIGN KEY (gear_type_id) REFERENCES gear_types (id),
    CONSTRAINT fk_lines_consumable FOREIGN KEY (consumable_id) REFERENCES consumables (id),
    CONSTRAINT fk_lines_child FOREIGN KEY (child_template_id) REFERENCES kit_templates (id),
    CONSTRAINT chk_lines_qty CHECK (qty > 0),
    CONSTRAINT chk_lines_one_target CHECK (
        (gear_type_id IS NOT NULL) + (consumable_id IS NOT NULL) + (child_template_id IS NOT NULL) = 1
    )
) ENGINE=InnoDB;

CREATE TABLE jobs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    job_date DATE NOT NULL,
    template_id INT UNSIGNED NOT NULL,
    positions INT UNSIGNED NOT NULL,
    packed_at DATETIME(6) NOT NULL,
    returned_at DATETIME(6) NULL,
    CONSTRAINT fk_jobs_template FOREIGN KEY (template_id) REFERENCES kit_templates (id),
    KEY idx_jobs_date (job_date)
) ENGINE=InnoDB;

-- The ledger. Append-only: every change to stock or gear status is a new row
-- here, tied to exactly one gear item or one lot.
CREATE TABLE movements (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    type ENUM('receipt', 'consume', 'checkout', 'return', 'faulty') NOT NULL,
    qty INT NOT NULL,
    gear_item_id INT UNSIGNED NULL,
    lot_id INT UNSIGNED NULL,
    job_id INT UNSIGNED NULL,
    note VARCHAR(255) NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    CONSTRAINT fk_movements_gear_item FOREIGN KEY (gear_item_id) REFERENCES gear_items (id),
    CONSTRAINT fk_movements_lot FOREIGN KEY (lot_id) REFERENCES lots (id),
    CONSTRAINT fk_movements_job FOREIGN KEY (job_id) REFERENCES jobs (id),
    CONSTRAINT chk_movements_one_subject CHECK ((gear_item_id IS NULL) <> (lot_id IS NULL)),
    KEY idx_movements_gear (gear_item_id, id),
    KEY idx_movements_lot (lot_id, id),
    KEY idx_movements_job (job_id)
) ENGINE=InnoDB;

-- Make "append-only" a database rule, not just a convention.
CREATE TRIGGER movements_no_update BEFORE UPDATE ON movements FOR EACH ROW
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'movements is append-only';

CREATE TRIGGER movements_no_delete BEFORE DELETE ON movements FOR EACH ROW
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'movements is append-only';
