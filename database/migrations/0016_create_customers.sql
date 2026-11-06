-- Clienti (i compratori che fanno le richieste). round_id vuoto = giro non ancora assegnato.
CREATE TABLE IF NOT EXISTS customers (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(150) NOT NULL,
    tax_code VARCHAR(30) NULL,
    address VARCHAR(255) NULL,
    contact_email VARCHAR(255) NULL,
    phone VARCHAR(30) NULL,
    round_id BIGINT UNSIGNED NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_customers_name (name),
    KEY idx_customers_round (round_id),
    CONSTRAINT fk_customers_round FOREIGN KEY (round_id) REFERENCES rounds (id)
        ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
