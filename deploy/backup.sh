#!/usr/bin/env bash
# Backup del database LogiDock con mysqldump, pensato per cron.
#
# - Scrive prima su un file ".partial" e lo rinomina solo a backup verificato: in cartella non
#   restano mai backup a metà.
# - Verifica che il file sia un gzip valido e che il dump sia arrivato in fondo.
# - Cancella i backup più vecchi di RETENTION_DAYS giorni.
# - Esce con codice diverso da 0 se qualcosa va storto (cron lo segnala per mail/log).
# - Le credenziali stanno in un file a parte (DEFAULTS_FILE), mai nella riga di comando
#   dove comparirebbero nell'elenco dei processi.
#
# Variabili (tutte con un default):
#   DB_NAME         database da salvare                 (logidock)
#   BACKUP_DIR      dove salvare i backup               (/var/backups/logidock)
#   RETENTION_DAYS  giorni di conservazione             (14)
#   DEFAULTS_FILE   file con [client] user/password     (/etc/logidock/backup.cnf)
#   MYSQLDUMP       eseguibile mysqldump                (mysqldump)

set -euo pipefail
umask 077

DB_NAME="${DB_NAME:-logidock}"
BACKUP_DIR="${BACKUP_DIR:-/var/backups/logidock}"
RETENTION_DAYS="${RETENTION_DAYS:-14}"
DEFAULTS_FILE="${DEFAULTS_FILE:-/etc/logidock/backup.cnf}"
MYSQLDUMP="${MYSQLDUMP:-mysqldump}"

log() {
    echo "$(date '+%Y-%m-%d %H:%M:%S') backup: $*"
    if command -v logger >/dev/null 2>&1; then
        logger -t logidock-backup -- "$*" || true
    fi
}

fail() {
    log "ERRORE: $*" >&2
    exit 1
}

[[ "$RETENTION_DAYS" =~ ^[0-9]+$ ]] || fail "RETENTION_DAYS deve essere un numero intero (valore: $RETENTION_DAYS)"
[[ -r "$DEFAULTS_FILE" ]] || fail "file delle credenziali non leggibile: $DEFAULTS_FILE"

mkdir -p "$BACKUP_DIR"

timestamp="$(date '+%Y%m%d-%H%M%S')"
final="$BACKUP_DIR/${DB_NAME}-${timestamp}.sql.gz"
partial="${final}.partial"

# Se qualcosa fallisce a metà, il file parziale sparisce.
trap 'rm -f "$partial"' EXIT

log "inizio backup di $DB_NAME"

# --single-transaction: copia coerente senza bloccare le scritture (le tabelle sono InnoDB).
# --triggers: salva anche i trigger che rendono pallet_movements append-only.
# --no-tablespaces: evita di richiedere il permesso PROCESS.
# --defaults-extra-file deve essere la PRIMA opzione.
"$MYSQLDUMP" --defaults-extra-file="$DEFAULTS_FILE" \
    --single-transaction --triggers --no-tablespaces \
    --default-character-set=utf8mb4 --hex-blob \
    "$DB_NAME" | gzip -9 > "$partial"

# Verifiche prima di dichiarare buono il backup.
gzip -t "$partial" || fail "il file compresso è danneggiato"
[[ -s "$partial" ]] || fail "il backup è vuoto"
gzip -dc "$partial" | tail -n 1 | grep -q 'Dump completed' || fail "il dump non è arrivato in fondo (manca la riga finale)"

mv "$partial" "$final"
log "backup completato: $final ($(du -h "$final" | cut -f1))"

# Rotazione: via i backup più vecchi di RETENTION_DAYS giorni.
removed="$(find "$BACKUP_DIR" -maxdepth 1 -type f -name "${DB_NAME}-*.sql.gz" -mtime +"$RETENTION_DAYS" -print -delete | wc -l)"
log "rotazione: $removed backup più vecchi di $RETENTION_DAYS giorni rimossi"
