-- Tipi di bancale: le misure sono dati modificabili dall'admin, non costanti nel codice.
-- max_height_mm vuoto = vale l'altezza massima globale (impostazione pallet_max_height_mm).
CREATE TABLE IF NOT EXISTS pallet_types (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    code VARCHAR(30) NOT NULL,
    name VARCHAR(100) NOT NULL,
    length_mm SMALLINT UNSIGNED NOT NULL,
    width_mm SMALLINT UNSIGNED NOT NULL,
    max_height_mm SMALLINT UNSIGNED NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_pallet_types_code (code),
    CONSTRAINT chk_pallet_types_size CHECK (length_mm > 0 AND width_mm > 0),
    CONSTRAINT chk_pallet_types_height CHECK (max_height_mm IS NULL OR max_height_mm > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- I due formati in uso (EPAL 3 e EPAL 1). INSERT IGNORE: se il codice esiste già non succede nulla.
INSERT IGNORE INTO pallet_types (code, name, length_mm, width_mm) VALUES
    ('EPAL3', 'EPAL 3', 1200, 1000),
    ('EPAL1', 'EPAL 1', 1200, 800);
