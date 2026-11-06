-- Tipi di rimorchio: arrivano rimorchi di forme e dimensioni diverse, quindi le misure stanno
-- nel tipo e non nel singolo veicolo. Per ora il pianale è un rettangolo (lunghezza x larghezza).
CREATE TABLE IF NOT EXISTS trailer_types (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    length_mm SMALLINT UNSIGNED NOT NULL,
    width_mm SMALLINT UNSIGNED NOT NULL,
    inner_height_mm SMALLINT UNSIGNED NOT NULL,
    max_weight_kg DECIMAL(10,2) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_trailer_types_name (name),
    CONSTRAINT chk_trailer_types_size CHECK (length_mm > 0 AND width_mm > 0 AND inner_height_mm > 0),
    CONSTRAINT chk_trailer_types_weight CHECK (max_weight_kg > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
