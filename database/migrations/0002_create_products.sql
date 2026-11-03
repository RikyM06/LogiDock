-- Anagrafica prodotti.
-- unit_weight_kg è il peso di una unità di misura: serve a calcolare il peso di un bancale
-- (quantità x peso unitario) e quindi a rispettare la portata del mezzo.

CREATE TABLE IF NOT EXISTS products (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    code VARCHAR(50) NOT NULL,
    description VARCHAR(255) NOT NULL,
    unit_of_measure VARCHAR(10) NOT NULL,
    unit_weight_kg DECIMAL(10,3) NOT NULL DEFAULT 0.000,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_products_code (code),
    CONSTRAINT chk_products_unit_weight CHECK (unit_weight_kg >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
