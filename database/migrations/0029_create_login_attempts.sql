-- Tentativi di accesso falliti, per il blocco temporaneo del login (dopo troppi errori si aspetta qualche minuto).
-- L'e-mail è quella digitata (anche se non esiste: il blocco non deve rivelare chi è registrato), in minuscolo.
-- Si tengono solo i tentativi della finestra di blocco: quelli più vecchi si cancellano a ogni nuovo errore.
-- Gli orari sono in UTC, come il resto del database.
CREATE TABLE IF NOT EXISTS login_attempts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    email VARCHAR(255) NOT NULL,
    ip VARCHAR(45) NOT NULL,
    attempted_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_login_attempts_email_time (email, attempted_at),
    KEY idx_login_attempts_ip_time (ip, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
