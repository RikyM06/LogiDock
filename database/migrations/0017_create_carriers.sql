-- Trasportatori: i camionisti proprietari del camion. trip_fee è il compenso fisso per viaggio.
CREATE TABLE IF NOT EXISTS carriers (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(150) NOT NULL,
    tax_code VARCHAR(30) NULL,
    phone VARCHAR(30) NULL,
    trip_fee DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_carriers_name (name),
    CONSTRAINT chk_carriers_trip_fee CHECK (trip_fee >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
