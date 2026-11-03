# LogiDock

Gestionale web per una piccola azienda di logistica locale: anagrafica prodotti, bancali in ingresso,
tracciabilità dei movimenti, pianificazione dei viaggi, bolle e DDT, utenti e ruoli.

È anche un progetto personale per il portfolio: tutto è costruito da zero (niente framework full-stack)
per mostrare come funziona ogni pezzo.

> **Stato:** Fasi 1 e 2 completate (scheletro dell'applicazione e database). Login e prime
> funzionalità arrivano nelle fasi successive, vedi [Roadmap](#roadmap).

## Stack

| Componente | Scelta |
|---|---|
| Linguaggio | PHP 8.3, senza framework |
| Database | MySQL 8 con PDO e prepared statement (niente ORM) |
| Frontend | HTML, CSS e JavaScript vanilla, template PHP lato server |
| Web server | Nginx + PHP-FPM su Ubuntu Server 24.04 LTS |
| Sviluppo | Docker Compose (solo dev) oppure PHP e MySQL installati in locale |

Pacchetti Composer: `vlucas/phpdotenv` (lettura di `.env`) e `phpunit/phpunit` (test, solo sviluppo).

## Architettura

```
Browser
   │  HTTP
   ▼
Nginx ──► public/index.php (front controller)
              │
              ▼
          Application ──► Router ──► [Middleware] ──► Controller
                                                          │
                                                          ▼
                                                       Service     (logica di business, validazione, transazioni)
                                                          │
                                                          ▼
                                                     Repository    (solo SQL, via PDO)
                                                          │
                                                          ▼
                                                        MySQL
              Controller ──► View (template PHP + layout) ──► Response ──► Browser
```

Regole dei livelli: il controller non contiene regole di business, il service non contiene SQL,
il repository non contiene regole. Gli errori non gestiti arrivano a un gestore centrale
(`ErrorHandler`) che scrive sul log e mostra una pagina generica.

### Struttura delle cartelle

```
public/        unico document root esposto da Nginx (index.php, asset)
src/Core/      router, request/response, container, view, logger, gestione errori
src/Controller controller (sottili)
src/Service    logica di business
src/Repository accesso al database
src/Model      entità e DTO
bin/           script da riga di comando (migrate.php, seed.php)
config/        app.php (da .env) e routes.php (tutte le rotte)
templates/     template PHP e layout
database/      migrazioni SQL e seed
deploy/        configurazione di produzione (Nginx, PHP-FPM, backup)
docker/        file per l'ambiente di sviluppo con Docker
tests/         test PHPUnit
storage/logs/  log applicativo (non versionato)
```

## Avvio in locale

Requisiti: PHP 8.3 (con `pdo_mysql`, `mbstring`, `intl`), Composer, MySQL 8.

```powershell
composer install
Copy-Item .env.example .env      # poi modifica i valori in .env
php -S localhost:8000 -t public
```

Apri <http://localhost:8000>. Con `APP_DEBUG=true` le pagine di errore mostrano i dettagli;
in produzione deve essere `false`.

### Database

Serve un MySQL 8 con un database `logidock` e un utente con tutti i permessi su di esso
(credenziali in `.env`). Poi:

```powershell
php bin/migrate.php            # applica le migrazioni mancanti
php bin/migrate.php --status   # stato di ogni migrazione (applied / pending / changed)
php bin/seed.php               # dati di esempio, solo sviluppo (rifiutato con APP_ENV=production)
```

Prima del seed imposta `SEED_ADMIN_PASSWORD` e `SEED_OPERATOR_PASSWORD` in `.env`:
gli utenti di esempio sono `admin@logidock.test` e `operatore@logidock.test`.

Le migrazioni sono file SQL numerati in `database/migrations`, ripetibili (`IF NOT EXISTS`).
Una migrazione già applicata **non si modifica**: `migrate.php` se ne accorge dal checksum e si
ferma. Una correzione si fa con una nuova migrazione.

> **MySQL e i trigger:** MySQL 8 ha il binary log attivo di default e in quel caso un utente senza
> `SUPER` non può creare trigger (errore 1419). La migrazione `0009` crea i trigger che rendono
> `pallet_movements` append-only, quindi serve `log_bin_trust_function_creators=1` nella
> configurazione di MySQL (oppure eseguire le migrazioni con un utente privilegiato).

### Schema

```
users ─┬──< pallets >── products          pallets: stato corrente di ogni bancale
       │      │  └───── suppliers         pallet_movements: storico append-only (solo INSERT)
       │      └──────── warehouse_locations
       ├──< pallet_movements >── pallets, warehouse_locations (da/a), trips
       ├──< trips >── vehicles
       │      ├──< trip_items >── pallets
       │      └──< transport_documents    (bolla / DDT, numerati con number_sequences)
```

Test PHPUnit: arrivano con la Fase 6.

## Roadmap

- [x] **Fase 0:** piano, schema del database e decisioni architetturali
- [x] **Fase 1:** scheletro (router, configurazione, gestione errori, layout)
- [x] **Fase 2:** migrazioni SQL, script di migrazione, seed
- [ ] **Fase 3:** autenticazione, sessioni sicure, CSRF, ruoli
- [ ] **Fase 4:** anagrafica prodotti (funzionalità verticale di esempio)
- [ ] **Fase 5:** Docker Compose, configurazione di produzione, backup
- [ ] **Fase 6:** test PHPUnit e documentazione finale
