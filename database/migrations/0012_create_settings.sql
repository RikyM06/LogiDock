-- Impostazioni globali modificabili dall'admin (altezza massima dei bancali, giorni dell'avviso, dati azienda...).
-- Qui non ci sono valori di partenza: li definisce il codice (SettingsService) e valgono finché
-- l'admin non salva un valore diverso.
CREATE TABLE IF NOT EXISTS settings (
    name VARCHAR(60) NOT NULL,
    value VARCHAR(500) NOT NULL,
    updated_by BIGINT UNSIGNED NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (name),
    CONSTRAINT fk_settings_updated_by FOREIGN KEY (updated_by) REFERENCES users (id)
        ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
