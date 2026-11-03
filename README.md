# LogiDock

Gestionale web per una piccola azienda di logistica locale: anagrafica prodotti, bancali in ingresso,
tracciabilità dei movimenti, pianificazione dei viaggi, bolle e DDT, utenti e ruoli.

È anche un progetto personale per il portfolio: tutto è costruito da zero (niente framework full-stack)
per mostrare come funziona ogni pezzo.

> **Stato:** Fase 1 completata (scheletro dell'applicazione). Database, login e prime funzionalità
> arrivano nelle fasi successive, vedi [Roadmap](#roadmap).

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

Migrazioni, seed e test arrivano con le fasi 2 e 6: i comandi verranno aggiunti qui.

## Roadmap

- [x] **Fase 0:** piano, schema del database e decisioni architetturali
- [x] **Fase 1:** scheletro (router, configurazione, gestione errori, layout)
- [ ] **Fase 2:** migrazioni SQL, script di migrazione, seed
- [ ] **Fase 3:** autenticazione, sessioni sicure, CSRF, ruoli
- [ ] **Fase 4:** anagrafica prodotti (funzionalità verticale di esempio)
- [ ] **Fase 5:** Docker Compose, configurazione di produzione, backup
- [ ] **Fase 6:** test PHPUnit e documentazione finale
