-- Bancali. Questa tabella è lo STATO CORRENTE di ogni bancale: la STORIA sta in pallet_movements.
-- I due si aggiornano sempre nella stessa transazione (lo garantisce il service).
-- Un bancale ha un solo prodotto e un solo lotto. Il codice è interno: PAL-AAAA-NNNNNN.
-- Gli indici seguono le ricerche previste: per prodotto e stato, per posizione, per scadenza, per lotto.

CREATE TABLE IF NOT EXISTS pallets (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    code VARCHAR(30) NOT NULL,
    product_id BIGINT UNSIGNED NOT NULL,
    supplier_id BIGINT UNSIGNED NULL,
    location_id BIGINT UNSIGNED NULL,
    quantity DECIMAL(12,3) NOT NULL,
    initial_quantity DECIMAL(12,3) NOT NULL,
    lot_number VARCHAR(50) NULL,
    expiry_date DATE NULL,
    received_at DATETIME NOT NULL,
    status ENUM('in_stock', 'reserved', 'shipped', 'discarded') NOT NULL DEFAULT 'in_stock',
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_pallets_code (code),
    KEY idx_pallets_product_status (product_id, status),
    KEY idx_pallets_supplier (supplier_id),
    KEY idx_pallets_location (location_id),
    KEY idx_pallets_expiry_date (expiry_date),
    KEY idx_pallets_lot_number (lot_number),
    KEY idx_pallets_received_at (received_at),
    KEY idx_pallets_created_by (created_by),
    CONSTRAINT fk_pallets_product FOREIGN KEY (product_id) REFERENCES products (id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_pallets_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers (id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_pallets_location FOREIGN KEY (location_id) REFERENCES warehouse_locations (id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_pallets_created_by FOREIGN KEY (created_by) REFERENCES users (id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT chk_pallets_quantity CHECK (quantity >= 0),
    CONSTRAINT chk_pallets_initial_quantity CHECK (initial_quantity > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
