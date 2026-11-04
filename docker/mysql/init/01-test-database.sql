-- Eseguito da MySQL solo al PRIMO avvio del volume dati (docker-entrypoint-initdb.d).
-- Crea il database dei test PHPUnit (fase 6) e dà all'utente dell'app i permessi su di esso.
-- Il nome utente è quello di default: se cambi DB_USER nel .env, adatta questa riga.
CREATE DATABASE IF NOT EXISTS logidock_test CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
GRANT ALL PRIVILEGES ON logidock_test.* TO 'logidock'@'%';
