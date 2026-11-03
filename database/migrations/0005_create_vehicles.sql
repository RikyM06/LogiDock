-- Mezzi (camion). La capacità è un blocco rigido: peso massimo e numero massimo di bancali.

CREATE TABLE IF NOT EXISTS vehicles (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    plate VARCHAR(15) NOT NULL,
    description VARCHAR(150) NOT NULL,
    max_weight_kg DECIMAL(10,2) NOT NULL,
    max_pallets SMALLINT UNSIGNED NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_vehicles_plate (plate),
    CONSTRAINT chk_vehicles_max_weight CHECK (max_weight_kg > 0),
    CONSTRAINT chk_vehicles_max_pallets CHECK (max_pallets > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
