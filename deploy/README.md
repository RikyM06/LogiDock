# Installazione di LogiDock su Ubuntu Server 24.04 (da zero)

Guida per installare tutto su un server nuovo: Nginx + PHP-FPM 8.3 + MySQL 8, raggiungibile solo dalla
rete locale. I comandi si lanciano come un utente normale con `sudo`.

> **Stato di verifica:** gli script (`backup.sh`, `mysql/grant-app-user.sh`) e il comando
> `bin/create-user.php` sono stati provati su MySQL reale (vedi in fondo, "Cosa è stato provato").
> Le configurazioni di Nginx e PHP-FPM e `deploy.sh` sono scritte con cura ma **non sono mai state
> eseguite su Ubuntu**: alla prima installazione controlla ogni passo, in particolare i test
> del punto 11.

Sostituisci ovunque `192.168.1.0/24` con la subnet reale dell'azienda e `192.168.1.50` con l'IP del server.

## Mappa dei file

| File | Dove va sul server |
|---|---|
| `nginx/logidock.conf` | `/etc/nginx/sites-available/logidock.conf` |
| `php-fpm/logidock.conf` | `/etc/php/8.3/fpm/pool.d/logidock.conf` |
| `mysql/logidock.cnf` | `/etc/mysql/mysql.conf.d/logidock.cnf` |
| `logrotate/logidock` | `/etc/logrotate.d/logidock` |
| `cron/logidock-backup` | `/etc/cron.d/logidock-backup` |
| `backup.sh`, `deploy.sh`, `mysql/grant-app-user.sh` | restano nel repository, in `/var/www/logidock/deploy/` |

## 1. Sistema e pacchetti

```bash
sudo apt update && sudo apt upgrade -y
sudo apt install -y nginx mysql-server git unzip composer ufw \
    php8.3-fpm php8.3-cli php8.3-mysql php8.3-mbstring php8.3-intl
```

## 2. Firewall: solo la rete locale

Imposta **prima** SSH, altrimenti `ufw enable` ti chiude fuori dal server.

```bash
sudo ufw default deny incoming
sudo ufw default allow outgoing
sudo ufw allow from 192.168.1.0/24 to any port 22 proto tcp
sudo ufw allow from 192.168.1.0/24 to any port 80 proto tcp
sudo ufw enable
sudo ufw status
```

Il limite alla subnet c'è due volte: nel firewall e in Nginx (`allow`/`deny`).

## 3. Codice dell'applicazione

```bash
sudo mkdir -p /var/www/logidock
sudo chown $USER:www-data /var/www/logidock
git clone https://github.com/RikyM06/LogiDock.git /var/www/logidock
cd /var/www/logidock
composer install --no-dev --optimize-autoloader --no-interaction
```

Se il repository fosse privato, serve una *deploy key* di sola lettura (`ssh-keygen`, poi si aggiunge la
chiave pubblica nelle impostazioni del repository su GitHub).

## 4. MySQL

Copia la configurazione (nel file c'è il perché di ogni riga) e riavvia:

```bash
sudo cp /var/www/logidock/deploy/mysql/logidock.cnf /etc/mysql/mysql.conf.d/logidock.cnf
sudo systemctl restart mysql
```

Crea database e tre utenti con compiti diversi. Genera tre password diverse
(`openssl rand -base64 24`) e sostituiscile ai segnaposto:

```bash
sudo mysql
```

```sql
CREATE DATABASE logidock CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;

-- Migrazioni: cambia lo schema, mai usato dall'applicazione.
CREATE USER 'logidock_migrator'@'localhost' IDENTIFIED BY 'PASSWORD_MIGRATOR';
GRANT ALL PRIVILEGES ON logidock.* TO 'logidock_migrator'@'localhost' WITH GRANT OPTION;

-- Applicazione: nessun permesso ora. Li assegna deploy/mysql/grant-app-user.sh tabella per tabella,
-- così può solo leggere e inserire in pallet_movements (storico append-only).
CREATE USER 'logidock_app'@'localhost' IDENTIFIED BY 'PASSWORD_APP';

-- Backup: sola lettura.
CREATE USER 'logidock_backup'@'localhost' IDENTIFIED BY 'PASSWORD_BACKUP';
GRANT SELECT, SHOW VIEW, TRIGGER ON logidock.* TO 'logidock_backup'@'localhost';
```

## 5. Configurazione dell'applicazione (`.env`)

```bash
cp .env.example .env
nano .env
```

Valori da impostare per la produzione:

```ini
APP_ENV=production
APP_DEBUG=false          # con true l'utente vedrebbe gli errori interni
APP_URL=http://192.168.1.50
DB_USER=logidock_app
DB_PASSWORD=PASSWORD_APP
# NON mettere SEED_ADMIN_PASSWORD / SEED_OPERATOR_PASSWORD: il seed in produzione è disabilitato.
```

Permessi: il file con le password lo legge solo il proprietario e PHP-FPM; la cartella dei log è scrivibile da PHP.

```bash
chmod 640 .env && sudo chown $USER:www-data .env
sudo chown -R www-data:www-data storage
sudo chmod -R 750 storage
sudo chmod -R g+rX /var/www/logidock
```

## 6. Credenziali per migrazioni e deploy

`deploy.sh` usa l'utente delle migrazioni, le cui credenziali stanno fuori dal repository:

```bash
sudo install -d -m 755 /etc/logidock
sudo install -m 600 -o $USER /dev/null /etc/logidock/migrate.env
nano /etc/logidock/migrate.env
```

Contenuto del file:

```ini
MIGRATE_DB_USER=logidock_migrator
MIGRATE_DB_PASSWORD=PASSWORD_MIGRATOR
```

## 7. PHP-FPM

Pool dedicato, con socket Unix e gestione sicura degli errori e delle sessioni:

```bash
sudo cp deploy/php-fpm/logidock.conf /etc/php/8.3/fpm/pool.d/logidock.conf
sudo mv /etc/php/8.3/fpm/pool.d/www.conf /etc/php/8.3/fpm/pool.d/www.conf.disabled   # pool predefinito non usato
sudo install -d -o www-data -g www-data -m 700 /var/lib/php/logidock-sessions
sudo php-fpm8.3 -t                    # controllo della configurazione
sudo systemctl restart php8.3-fpm
ls -l /run/php/php8.3-fpm-logidock.sock
```

## 8. Nginx

```bash
sudo cp deploy/nginx/logidock.conf /etc/nginx/sites-available/logidock.conf
sudo nano /etc/nginx/sites-available/logidock.conf     # controlla le righe "allow" con la subnet giusta
sudo ln -s /etc/nginx/sites-available/logidock.conf /etc/nginx/sites-enabled/logidock.conf
sudo rm -f /etc/nginx/sites-enabled/default
sudo nginx -t
sudo systemctl reload nginx
```

## 9. Primo deploy: migrazioni e permessi

```bash
cd /var/www/logidock
bash deploy/deploy.sh
```

Lo script scarica il codice, installa le dipendenze, applica le migrazioni (con l'utente migrator),
assegna i permessi a `logidock_app` e ricarica PHP-FPM. **Lo stesso comando serve per ogni aggiornamento
futuro.** Controllo: `php bin/migrate.php --status` deve mostrare tutte le migrazioni `applied`.

## 10. Primo utente amministratore

In produzione il seed non gira, quindi il primo admin si crea da riga di comando (la password si digita
nascosta, minimo 12 caratteri):

```bash
cd /var/www/logidock
php bin/create-user.php admin@azienda.lan "Mario Rossi" admin
```

Altri utenti: stesso comando con ruolo `operatore`.

## 11. Verifica

Da un PC della rete locale (con l'IP del server):

```bash
curl -I http://192.168.1.50/login        # 200 OK
curl -I http://192.168.1.50/.env         # 403: i file nascosti sono bloccati
curl -I http://192.168.1.50/composer.json   # 404: non è nel document root
```

- Apri `http://192.168.1.50` nel browser e accedi con l'admin creato.
- Da un dispositivo **fuori** dalla subnet (o con `curl --interface`) ogni richiesta deve dare **403**.
- Un URL che non esiste deve mostrare la pagina d'errore generica, senza dettagli.
- Controlla che nel `.env` ci sia `APP_DEBUG=false` e che i log si scrivano:
  `tail /var/www/logidock/storage/logs/app.log` e `sudo tail /var/log/nginx/logidock.error.log`.
- Lasciati loggato per più di 24 minuti senza usare l'app e poi ricarica: devi essere ancora dentro
  (la sessione dura `SESSION_LIFETIME_MINUTES`, 120 di default). Se ti butta fuori prima, vedi il
  commento sulle sessioni in `php-fpm/logidock.conf`.

## 12. Backup con cron

```bash
sudo install -d -m 700 /var/backups/logidock
sudo install -m 600 /dev/null /etc/logidock/backup.cnf
sudo nano /etc/logidock/backup.cnf
```

Contenuto di `backup.cnf`:

```ini
[client]
user=logidock_backup
password=PASSWORD_BACKUP
```

```bash
sudo cp /var/www/logidock/deploy/cron/logidock-backup /etc/cron.d/logidock-backup
sudo bash /var/www/logidock/deploy/backup.sh      # prova subito a mano
ls -lh /var/backups/logidock
```

Ogni notte alle 02:30 viene creato `logidock-AAAAMMGG-HHMMSS.sql.gz`; si conservano 14 giorni
(`RETENTION_DAYS`). Lo script verifica il file e fallisce con un errore chiaro se qualcosa non va.

**Importante:** un backup sullo stesso disco non protegge da un guasto del disco. Copia periodicamente
`/var/backups/logidock` su un'altra macchina o su un disco esterno (ad esempio con `rsync`).

### Ripristino

Prova **sempre** prima su un database di prova, non su quello in uso:

```bash
sudo mysql -e "CREATE DATABASE logidock_restore CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci"
gunzip -c /var/backups/logidock/logidock-AAAAMMGG-HHMMSS.sql.gz | sudo mysql logidock_restore
sudo mysql logidock_restore -e "SELECT COUNT(*) FROM pallets"
```

Per ripristinare quello vero: ferma l'applicazione (`sudo systemctl stop nginx`), svuota il database
(`DROP DATABASE logidock; CREATE DATABASE ...` come al punto 4), carica il dump, poi riassegna i permessi con
`bash deploy/deploy.sh` e riavvia Nginx.

## 13. Rotazione dei log

```bash
sudo cp deploy/logrotate/logidock /etc/logrotate.d/logidock
sudo logrotate --debug /etc/logrotate.d/logidock
```

## Aggiornare l'applicazione

```bash
cd /var/www/logidock && bash deploy/deploy.sh
```

Se una migrazione fallisce a metà, correggi la causa e rilancia: le migrazioni sono ripetibili.

## Note

- **HTTPS:** l'app gira in HTTP sulla rete locale. Se vorrai HTTPS (certificato interno o autofirmato),
  aggiungi un blocco `listen 443 ssl` in Nginx e imposta `APP_URL=https://...`: il cookie di sessione
  diventa automaticamente `Secure`.
- **Utente dell'app e nuove tabelle:** ogni migrazione che crea una tabella richiede di rilanciare
  `grant-app-user.sh`, e `deploy.sh` lo fa da solo. Senza, l'app non ha accesso alla nuova tabella.

## Cosa è stato provato (e cosa no)

Provato su MySQL 8.4 locale con Git Bash:
- `backup.sh`: dump valido, ripristino identico (righe, trigger, chiavi esterne), nessun file a metà in
  caso di errore, rotazione, rifiuto di parametri sbagliati.
- `mysql/grant-app-user.sh`: l'utente dell'app legge e inserisce in `pallet_movements` ma `UPDATE`,
  `DELETE`, `TRUNCATE`, `DROP` e `CREATE` sono negati; l'app web funziona con quei soli permessi.
- `bin/create-user.php`: creazione da stdin e controlli sugli input.
- Le variabili d'ambiente reali prevalgono su `.env` (è ciò che usa `deploy.sh` per le migrazioni).

**Non provato** (nessun Ubuntu né Docker a disposizione quando sono stati scritti): `nginx/logidock.conf`,
`php-fpm/logidock.conf`, `deploy.sh`, il prompt nascosto della password in `create-user.php` (richiede
Linux), `docker-compose.yml` e i file in `docker/`. Segui i controlli del punto 11.
