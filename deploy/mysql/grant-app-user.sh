#!/usr/bin/env bash
# Dà all'utente MySQL dell'applicazione i SOLI permessi che servono, tabella per tabella:
#   - pallet_movements (storico append-only): solo SELECT e INSERT
#   - schema_migrations: nessun permesso (le migrazioni le fa un altro utente)
#   - tutte le altre tabelle: SELECT, INSERT, UPDATE, DELETE
# Così, anche se qualcuno sbagliasse il codice (o ci fosse una SQL injection), l'app non può
# modificare lo storico né cambiare lo schema. Si aggiunge alla protezione dei trigger.
#
# Va rilanciato dopo ogni migrazione che crea nuove tabelle (deploy.sh lo fa da solo).
# I permessi sono SOLO additivi: lo script non toglie nulla.
#
# Variabili:
#   DB_NAME        database                                    (logidock)
#   APP_DB_USER    utente dell'applicazione                    (logidock_app)
#   APP_DB_HOST    host dell'utente                            (localhost)
#   MYSQL_BIN      client mysql                                (mysql)
#   MYSQL_ARGS     opzioni per collegarsi come utente che può dare permessi
#                  (default: vuote = collegamento locale come l'utente di sistema, es. "sudo")
#                  La password, se serve, si passa con la variabile MYSQL_PWD.
# L'utente dell'applicazione deve già esistere (CREATE USER, vedi deploy/README.md).

set -euo pipefail

DB_NAME="${DB_NAME:-logidock}"
APP_DB_USER="${APP_DB_USER:-logidock_app}"
APP_DB_HOST="${APP_DB_HOST:-localhost}"
MYSQL_BIN="${MYSQL_BIN:-mysql}"
MYSQL_ARGS="${MYSQL_ARGS:-}"

# Solo caratteri semplici: questi valori finiscono dentro istruzioni SQL.
for value in "$DB_NAME" "$APP_DB_USER" "$APP_DB_HOST"; do
    [[ "$value" =~ ^[A-Za-z0-9_.%-]+$ ]] || { echo "ERRORE: valore non ammesso: $value" >&2; exit 1; }
done

# MYSQL_ARGS è volutamente senza virgolette: contiene più opzioni separate da spazi.
# shellcheck disable=SC2086
run_mysql() { "$MYSQL_BIN" $MYSQL_ARGS "$@"; }

# Genera un GRANT per ogni tabella leggendo l'elenco da information_schema.
statements="$(run_mysql -N -B <<SQL
SELECT CONCAT(
    'GRANT ',
    IF(table_name = 'pallet_movements', 'SELECT, INSERT', 'SELECT, INSERT, UPDATE, DELETE'),
    ' ON ${DB_NAME}.', table_name,
    ' TO ''${APP_DB_USER}''@''${APP_DB_HOST}'';')
FROM information_schema.tables
WHERE table_schema = '${DB_NAME}'
  AND table_type = 'BASE TABLE'
  AND table_name <> 'schema_migrations'
ORDER BY table_name;
SQL
)"

[[ -n "$statements" ]] || { echo "ERRORE: nessuna tabella trovata in $DB_NAME (le migrazioni sono state eseguite?)" >&2; exit 1; }

echo "$statements" | run_mysql
echo "Permessi di ${APP_DB_USER}@${APP_DB_HOST} su ${DB_NAME} aggiornati ($(echo "$statements" | wc -l) tabelle)."
