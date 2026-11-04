#!/usr/bin/env bash
# Aggiorna LogiDock in produzione. Da lanciare sul server, come l'utente proprietario del codice.
#
# Passi: scarica il codice -> installa le dipendenze (senza quelle di sviluppo) ->
#        applica le migrazioni -> aggiorna i permessi dell'utente dell'app -> ricarica PHP-FPM.
#
# Le migrazioni girano con un utente MySQL privilegiato (migrator), diverso da quello dell'app,
# le cui credenziali stanno in /etc/logidock/migrate.env (modo 600), con due righe:
#   MIGRATE_DB_USER=logidock_migrator
#   MIGRATE_DB_PASSWORD=...
#
# Variabili (con default): APP_DIR, MIGRATE_ENV_FILE, PHP_FPM_SERVICE, APP_DB_USER.

set -euo pipefail

APP_DIR="${APP_DIR:-/var/www/logidock}"
MIGRATE_ENV_FILE="${MIGRATE_ENV_FILE:-/etc/logidock/migrate.env}"
PHP_FPM_SERVICE="${PHP_FPM_SERVICE:-php8.3-fpm}"

[[ -r "$MIGRATE_ENV_FILE" ]] || { echo "ERRORE: non riesco a leggere $MIGRATE_ENV_FILE" >&2; exit 1; }

cd "$APP_DIR"

echo "==> Codice"
git pull --ff-only

echo "==> Dipendenze"
composer install --no-dev --optimize-autoloader --no-interaction

# Carica MIGRATE_DB_USER e MIGRATE_DB_PASSWORD (set -a li esporta ai comandi seguenti).
set -a
# shellcheck disable=SC1090
source "$MIGRATE_ENV_FILE"
set +a
: "${MIGRATE_DB_USER:?manca MIGRATE_DB_USER in $MIGRATE_ENV_FILE}"
: "${MIGRATE_DB_PASSWORD:?manca MIGRATE_DB_PASSWORD in $MIGRATE_ENV_FILE}"

echo "==> Migrazioni"
# Le variabili d'ambiente vere hanno la precedenza sul file .env.
DB_USER="$MIGRATE_DB_USER" DB_PASSWORD="$MIGRATE_DB_PASSWORD" php bin/migrate.php

echo "==> Permessi dell'utente applicativo"
MYSQL_ARGS="-u$MIGRATE_DB_USER" MYSQL_PWD="$MIGRATE_DB_PASSWORD" bash deploy/mysql/grant-app-user.sh

echo "==> Ricarico PHP-FPM"
sudo systemctl reload "$PHP_FPM_SERVICE"

echo "Deploy completato."
