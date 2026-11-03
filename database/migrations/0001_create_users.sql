-- Utenti dell'applicazione.
-- Il ruolo decide cosa si può fare (controllo nei middleware). Gli utenti non si cancellano
-- mai (hanno movimenti e viaggi collegati): si disattivano con is_active = 0.
-- Convenzione di tutte le migrazioni: ogni istruzione finisce con punto e virgola a fine riga.

CREATE TABLE IF NOT EXISTS users (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    email VARCHAR(255) NOT NULL,
    name VARCHAR(120) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('admin', 'operatore') NOT NULL DEFAULT 'operatore',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    last_login_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
