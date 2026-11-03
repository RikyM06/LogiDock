-- Contatori progressivi per anno: codici bancale (PAL-2026-000123), codici viaggio e numeri
-- di bolla/DDT. Un contatore per ogni coppia (tipo, anno), quindi a gennaio si riparte da 1.
--
-- Incremento atomico, senza buchi e senza race condition tra due utenti:
--   INSERT INTO number_sequences (type, year, last_number) VALUES (?, ?, LAST_INSERT_ID(1))
--   ON DUPLICATE KEY UPDATE last_number = LAST_INSERT_ID(last_number + 1);
-- e poi si legge il valore con LAST_INSERT_ID(). Va eseguito nella stessa transazione
-- che crea il record: se la transazione fallisce, il numero non viene bruciato.

CREATE TABLE IF NOT EXISTS number_sequences (
    type VARCHAR(30) NOT NULL,
    year SMALLINT UNSIGNED NOT NULL,
    last_number INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (type, year)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
