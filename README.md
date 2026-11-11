# <img src="docs/assets/logo.svg" alt="" width="40" height="40" align="top"> LogiDock

![Dettaglio di un arrivo in LogiDock](docs/assets/screenshots/arrivo-dettaglio.png)

Gestionale web per una piccola azienda di logistica locale: anagrafica prodotti, bancali in ingresso,
tracciabilità dei movimenti, pianificazione dei viaggi, bolle e DDT, utenti e ruoli.

È anche un progetto personale per il portfolio: tutto è costruito da zero (niente framework full-stack)
per mostrare come funziona ogni pezzo.

Il codice è **source available** con doppia licenza: gratuito per gli usi non commerciali
([PolyForm Noncommercial License 1.0.0](LICENSE)), a pagamento con una
[licenza commerciale separata](COMMERCIAL-LICENSE.md) per gli usi commerciali o professionali.
Dettagli in [Licenza](#licenza).

> **Stato:** Fasi 1-6 e 8-13 completate (scheletro, database, autenticazione, prodotti, ambiente e deploy,
> test, anagrafiche e impostazioni, arrivi di merce, richieste dei clienti, utenti, giacenze, pianificazione dei
> viaggi con modifiche a mano e ricalcolo). Le altre funzionalità arrivano una alla volta, vedi [Roadmap](#roadmap).

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
src/Core/      router, request/response, container, view, logger, gestione errori, validatore
src/Controller controller (sottili)
src/Service    logica di business
src/Repository accesso al database
src/Model      entità e DTO
bin/           script da riga di comando (migrate.php, seed.php, create-user.php)
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

Prima del seed imposta `SEED_ADMIN_PASSWORD` e `SEED_OPERATOR_PASSWORD` in `.env` (almeno 12 caratteri,
come ogni password dell'applicazione: il seed controlla con `PasswordPolicy` e si ferma se non vanno bene):
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

Dalla Fase 10 `orders` e `order_lines` (richieste dei clienti e loro righe). Dalla Fase 9 `supplier_documents` (documenti dei fornitori) e, su `pallets`, tipo di bancale, documento,
prezzo d'acquisto e peso segnato. Dalla Fase 8 si aggiungono: `settings` (impostazioni), `pallet_types` e `trailer_types` (misure), `rounds` e
`customers` (giri e clienti), `carriers` (trasportatori). `vehicles` punta a un tipo di rimorchio e a un
trasportatore, `warehouse_locations` ha una capacità facoltativa.

### Autenticazione

Utenti di esempio (dal seed): `admin@logidock.test` e `operatore@logidock.test`; le password sono
quelle di `SEED_ADMIN_PASSWORD` e `SEED_OPERATOR_PASSWORD` in `.env`.

- **Password:** `password_hash` / `password_verify` (bcrypt oggi, con rehash automatico se l'algoritmo
  predefinito di PHP cambia). Se l'email non esiste si fa comunque un `password_verify` su un hash finto,
  così il tempo di risposta non rivela quali email sono registrate; il messaggio di errore è sempre lo stesso.
- **Sessione:** cookie `HttpOnly` e `SameSite=Lax` (`Secure` se `APP_URL` è https), `use_strict_mode`,
  nuovo ID di sessione a ogni login, scadenza per inattività (`SESSION_LIFETIME_MINUTES`).
  Nella sessione c'è solo l'id utente: ruolo e stato attivo si rileggono dal database a ogni richiesta,
  quindi disattivare un utente ha effetto subito.
- **CSRF:** ogni POST richiede il token (campo `_csrf` o header `X-CSRF-Token`), altrimenti 419.
- **Ruoli:** `AuthMiddleware` (qualunque utente collegato) e `AdminMiddleware` (solo admin, gli altri ricevono 403).
  I middleware si assegnano alle rotte in `config/routes.php`, anche a gruppi con `$router->group()`.
- **Non c'è** un limite ai tentativi di login: l'app è raggiungibile solo dalla rete locale.
  I tentativi falliti finiscono nel log (mai la password).

### Anagrafica prodotti (esempio da replicare)

La prima funzionalità completa, scritta per essere il modello delle prossime. Attraversa tutti i livelli:

| Livello | File | Cosa fa |
|---|---|---|
| Rotte | `config/routes.php` | chi può fare cosa (qualunque utente consulta, solo l'admin modifica) |
| Controller | `src/Controller/ProductController.php` | legge la richiesta, chiama il service, sceglie la risposta (render, redirect, 404) |
| Service | `src/Service/ProductService.php` | validazione, normalizzazione, unicità del codice |
| Repository | `src/Repository/ProductRepository.php` | solo SQL con prepared statement |
| Modello | `src/Model/Product.php`, `ProductData.php` | dato letto dal database / dato validato da scrivere |
| Template | `templates/products/` | lista con ricerca, form condiviso da creazione e modifica |

| Rotta | Chi | Azione |
|---|---|---|
| `GET /products` | admin, operatore | lista, con ricerca `?q=` per codice o descrizione |
| `GET /products/create`, `POST /products` | admin | nuovo prodotto |
| `GET /products/{id}/edit`, `POST /products/{id}` | admin | modifica |
| `POST /products/{id}/deactivate`, `.../activate` | admin | disattiva / riattiva |

Scelte da sapere:
- **Non si cancella mai**: un prodotto ha bancali collegati, quindi si disattiva (e si può riattivare).
- **Validazione nel service**, che lancia `ValidationException` con un messaggio per campo. Il controller
  ripresenta il form con gli errori e i valori digitati (risposta 422).
- **Il peso è sempre una stringa**: `12,5` o `12.5` diventano `12.500` senza passare da float.
- **Codice univoco anche in concorrenza**: il service controlla prima, ma l'ultima parola è del vincolo
  UNIQUE del database; il repository traduce l'errore in `DuplicateEntryException`.
- **Pattern post/redirect/get** con messaggi flash dopo ogni scrittura, così il refresh non ripete l'azione.
- Nella lista non c'è paginazione (pochi prodotti): si aggiungerà se serve.

Per aggiungere una nuova funzionalità si copiano questi sei file cambiando nome ed entità.

### Anagrafiche (fornitori, clienti, giri, veicoli, rimorchi, trasportatori, posizioni, tipi di bancale)

Otto anagrafiche quasi identiche (lista con ricerca, nuovo, modifica, disattiva/riattiva), quindi non sono
copiate otto volte: hanno una **base comune** e ognuna aggiunge solo le sue regole.

| Pezzo | Dove | Cosa fa |
|---|---|---|
| Repository base | `src/Repository/MasterDataRepository.php` | ricerca, lettura, insert, update, attiva/disattiva. Le sottoclassi dicono solo tabella, colonne e dove cercare |
| Service base | `src/Service/MasterDataService.php` | crea/modifica/cerca/disattiva, traduce i duplicati in errore sul campo. Ogni service scrive solo `validate()` |
| Controller base | `src/Controller/MasterDataController.php` | il flusso della lista e dei form. Ogni controller dice rotta, nomi e campi |
| Validatore | `src/Core/Validator.php` | testo, e-mail, interi, decimali (stringhe, mai float), codici, scelte, riferimenti ad altre anagrafiche |
| Pezzi di pagina | `templates/partials/` | campo, menu a tendina, intestazione di lista, pulsanti di riga |

Nell'SQL della base entrano solo nomi di tabelle e colonne scritti nel codice delle sottoclassi; i valori
passano sempre da prepared statement.

| Anagrafica | Rotta | Regole particolari |
|---|---|---|
| Fornitori | `/suppliers` | **tutti i campi obbligatori** (ragione sociale, partita IVA / codice fiscale, indirizzo, e-mail valida, telefono); nel database restano facoltativi per i fornitori registrati prima della regola |
| Clienti | `/customers` | ogni cliente può avere un giro (solo giri attivi) |
| Giri | `/rounds` | nome unico |
| Veicoli | `/vehicles` | targa unica (maiuscola), tipo di rimorchio e trasportatore facoltativi |
| Tipi di rimorchio | `/trailer-types` | lunghezza, larghezza, altezza interna (mm) e portata; il pianale è un rettangolo |
| Trasportatori | `/carriers` | compenso fisso per viaggio; **solo admin**, anche la lista |
| Posizioni di magazzino | `/locations` | codice unico, capacità facoltativa (vuota = capacità predefinita) |
| Tipi di bancale | `/pallet-types` | codice unico; EPAL 3 (1200 × 1000 mm) ed EPAL 1 (1200 × 800 mm) già presenti |

In tutte le schede i **campi obbligatori hanno un asterisco rosso** accanto all'etichetta (nelle tabelle a righe di
arrivi e richieste, accanto al titolo della colonna). L'asterisco è solo visivo: ai lettori di schermo l'obbligo
arriva dall'attributo `required` del campo, che i partial `field` e `select` mettono insieme all'asterisco.

Come per i prodotti, tutti consultano e solo gli admin modificano. Un riferimento già assegnato (il giro di
un cliente) che viene disattivato resta sul record e non sparisce dal form, ma non si può assegnare a record nuovi.

### Arrivi di merce

Il lavoro di ogni giorno del magazzino: arriva un documento del fornitore con uno o più bancali. Lo
registra **qualunque utente collegato** (non solo l'admin) da `/inbound`.

Un arrivo è una testata (fornitore, numero del documento, data del documento, data di arrivo, note) più
una riga per ogni bancale: prodotto, tipo di bancale, quantità, prezzo d'acquisto unitario, **peso lordo
segnato dal fornitore**, lotto, scadenza e posizione. Il form aggiunge e toglie righe con un po' di
JavaScript (`public/assets/js/app.js`); senza JavaScript funziona lo stesso con le righe già mostrate.

| Pezzo | Dove | Cosa fa |
|---|---|---|
| Service | `src/Service/InboundService.php` | valida testata e righe, controlla la capacità, scrive tutto in una transazione |
| Repository | `SupplierDocumentRepository`, `PalletRepository`, `PalletMovementRepository`, `NumberSequenceRepository` | solo SQL |
| Controller e template | `InboundController`, `templates/inbound/` | lista, nuovo arrivo, dettaglio |

Cosa succede quando si registra un arrivo, **tutto in una sola transazione** (se qualcosa non va non resta niente a metà):

1. si salva il documento del fornitore (lo stesso fornitore non può avere due volte lo stesso numero con la stessa data);
2. per ogni riga si assegna il codice `PAL-AAAA-NNNNNN` (numeratore per anno, senza buchi né doppioni anche con due utenti insieme);
3. si salva il bancale (stato corrente) e **un movimento di ingresso** nello storico, che è append-only.

Regole da sapere:
- **Capacità:** una posizione non può superare la sua capacità (quella propria o, se vuota, quella delle impostazioni). Il conteggio è fatto nella stessa transazione; resta una piccola finestra se due persone riempiono la stessa posizione nello stesso istante: è un avviso pratico, non un vincolo come il codice univoco.
- **Peso:** il peso segnato dal fornitore si confronta con quello calcolato (quantità × peso unitario del prodotto). Se differisce oltre la tolleranza delle impostazioni (5% di partenza) il bancale è evidenziato. Il confronto lo fa MySQL in DECIMAL, senza float in PHP.
- **Date:** la data di arrivo non può essere nel futuro, il documento non può essere dopo l'arrivo, una scadenza non può precedere l'arrivo.
- **Prezzo:** è il prezzo d'acquisto **per unità di misura** del prodotto (valore del bancale = quantità × prezzo).
- **Un arrivo registrato non si modifica:** i movimenti sono uno storico. Una correzione si farà con un movimento di rettifica.

### Giacenze e avviso dei bancali fermi

Cosa c'è adesso in magazzino, da `/stock`, per **qualunque utente collegato**. La pagina ha un riepilogo **per
prodotto** (bancali, quantità totale, arrivo più vecchio, quanti sono fermi) e la **lista dei bancali**, dal più
vecchio, filtrabile per prodotto, posizione e "solo fermi". La home mostra lo stesso avviso con i 5 bancali più vecchi.

| Pezzo | Dove | Cosa fa |
|---|---|---|
| Regola | `src/Service/AgingRule.php` | quando un bancale è fermo: giorni in magazzino, confronto con la soglia, data limite per l'SQL |
| Service | `src/Service/StockService.php` | crea la regola (soglia dalle impostazioni + data di oggi) e chiede i dati al repository |
| Repository | `src/Repository/StockRepository.php` | solo SQL di lettura: riepilogo per prodotto, lista filtrata, conteggio dei fermi |
| Controller e template | `StockController`, `templates/stock/`, `HomeController`, `templates/home.php` | pagina Giacenze e avviso nella home |

Regole da sapere:
- **In magazzino** vuol dire stato *in_stock* oppure *reserved* (prenotato per un viaggio ma non ancora partito):
  i bancali spediti o scartati non contano. I prenotati sono quelli di un viaggio confermato e hanno l'etichetta "Prenotato".
- **Fermo** vuol dire in magazzino da **più** giorni della soglia (`aging_threshold_days`, 2 di partenza, nelle
  impostazioni). I giorni sono giorni di calendario dalla data di arrivo, anche se il bancale è stato spostato:
  con soglia 2 un bancale arrivato lunedì è segnalato da giovedì.
- La regola sta **in un posto solo** (`AgingRule`): la usa PHP per i giorni e l'etichetta "Fermo", e la traduce
  per MySQL in una data limite (`received_at < data`), che usa l'indice su `received_at`. Un test controlla che
  le due versioni diano sempre la stessa risposta.
- Le quantità si sommano in MySQL in DECIMAL, mai con i float.
- Da quando contare i giorni, cosa fare con un bancale fermo e se serve un avviso sulle scadenze sono domande
  ancora aperte con l'azienda (issue #26): le scelte di oggi sono un punto di partenza.

### Richieste dei clienti

Cosa chiede ogni cliente, da `/orders`. Le registra **qualunque utente collegato**, come gli arrivi.

Una richiesta ha un cliente (con il suo giro), la data della richiesta, la **consegna desiderata**, delle note e
una riga per prodotto: quantità (nell'unità di misura del prodotto) e **prezzo di vendita** per unità. Il prezzo
è una "fotografia" del momento: se cambia il listino, le richieste già fatte non cambiano.

| Pezzo | Dove | Cosa fa |
|---|---|---|
| Service | `src/Service/OrderService.php` | valida testata e righe, assegna il codice, scrive in transazione, blocca le modifiche se non è più aperta |
| Repository | `src/Repository/OrderRepository.php` | solo SQL: lista con totali, righe con giacenza, inserimento, sostituzione delle righe |
| Controller e template | `OrderController`, `templates/orders/` | lista con filtri, nuova, dettaglio, modifica, annullamento |

Regole da sapere:
- **Codice** `ORD-AAAA-NNNNNN`, progressivo per anno, con lo stesso numeratore dei bancali (tipo `order`).
- **Stati:** *aperta* (si modifica e si annulla), *pianificata* (ha bancali su un viaggio confermato: per
  modificarla o annullarla bisogna prima annullare il viaggio), *spedita* (la useranno le consegne), *annullata*.
  Una richiesta **non si cancella mai**: fa parte dello storico.
- **Un prodotto una sola volta per richiesta:** se compare su due righe il form chiede di sommare le quantità.
- **Date:** la richiesta non può essere nel futuro, la consegna non può essere prima della richiesta.
- **Giacenza:** nel dettaglio, per ogni prodotto, si vede quanto ce n'è adesso in magazzino (bancali *in_stock*);
  se non basta la riga è evidenziata. È un'indicazione: cosa va davvero a ogni cliente lo decide la pianificazione.
- Un cliente o un prodotto disattivato dopo la richiesta resta su quella richiesta e non sparisce dal form di
  modifica, ma non si può usare in una richiesta nuova.

### Pianificazione dei viaggi

Da `/trips`, per **qualunque utente collegato**. Si sceglie un **giorno di consegna** e il programma propone i
viaggi di quel giorno: quali bancali vanno su quale mezzo, cosa resta in magazzino e cosa manca. Il piano è solo
una proposta finché non si preme **Conferma**: allora i viaggi diventano veri (codice `VIA-AAAA-NNNNNN`), i
bancali passano a *prenotato* e le richieste a *pianificata*. Un viaggio confermato e non ancora partito si può
**annullare**: bancali e richieste tornano liberi. Finché non è partito lo si può anche **modificare a mano**
(vedi [Scelte a mano](#scelte-a-mano-e-ricalcolo-del-giorno)).

| Pezzo | Dove | Cosa fa |
|---|---|---|
| Calcolo del carico | `src/Service/LoadCalculator.php` | dice se un gruppo di bancali sta su un mezzo e quanto lo riempie (solo calcoli) |
| Calcolo del piano | `src/Service/TripPlanner.php` | dalle richieste, dai bancali e dai mezzi costruisce il piano (solo calcoli, niente database) |
| Service | `src/Service/TripService.php` | legge i dati, fa calcolare il piano, lo conferma in una transazione, annulla un viaggio |
| Repository | `src/Repository/PlanningRepository.php`, `TripRepository.php` | solo SQL: righe da pianificare, bancali e mezzi liberi; viaggi e loro bancali |
| Modelli del piano | `src/Model/TripPlan.php`, `PlannedTrip.php`, `PlanAssignment.php`, ... | il piano in memoria: viaggi proposti, bancali rimasti a terra, riepilogo riga per riga |
| Millesimi | `src/Core/Thousandths.php` | quantità e pesi come interi (millesimi) per fare i conti senza float |
| Controller e template | `TripController`, `templates/trips/` | lista, piano del giorno, conferma, dettaglio, annullamento |

Come ragiona il programma (le tre regole di `TripPlanner`):
1. **Bancali alle richieste.** La richiesta registrata per prima passa per prima. Ogni riga prende i bancali
   **più vecchi** del suo prodotto finché è coperta. Un bancale parte **intero** e va a un solo cliente, quindi
   l'ultimo può portare più del richiesto ("In più"). Se i bancali finiscono, la parte scoperta "Manca".
2. **Richieste ai giri.** I bancali dei clienti dello stesso giro viaggiano insieme. Un cliente senza giro non
   entra nel piano (basta assegnargli un giro nell'anagrafica).
3. **Giri ai mezzi.** Per ogni giro si prende il mezzo **più piccolo che porta via tutto**; se nessuno basta si
   riempie il più grande e con il resto si ricomincia. Un mezzo fa un solo viaggio al giorno. Quello che non
   trova posto "Resta in magazzino".

Regole da sapere:
- **Il mezzo** deve avere un tipo di rimorchio (da lì arrivano le misure): senza, non viene usato e la pagina lo
  segnala. Il rimorchio è trattato come un rettangolo caricato a file. I limiti sono tre: **lunghezza del
  pianale**, **portata** (la più bassa tra veicolo e rimorchio) e **numero massimo di bancali** del veicolo.
- **Impilamento:** solo bancali dello stesso tipo, fino ai livelli delle impostazioni (2 di partenza) e mai oltre
  l'altezza interna del rimorchio. È una **stima prudente**: un carico incastrato a mano può starci anche meglio.
- **Peso di un bancale:** quello segnato dal fornitore; se manca, quantità × peso unitario del prodotto.
- **Niente piano salvato:** il piano si ricalcola a ogni apertura dai dati del momento. Chi conferma manda
  l'"impronta" del piano che ha visto: se nel frattempo è cambiato qualcosa (un bancale, una richiesta, un mezzo)
  l'impronta non torna più e l'app chiede di riguardare il piano, invece di confermare una cosa mai vista.
- **Più utenti insieme:** conferma e annullamento bloccano per prima cosa le righe delle richieste coinvolte, e
  lo stesso fa la modifica di una richiesta. Tutti prendono i blocchi nello stesso ordine, quindi si mettono in
  fila invece di bloccarsi a vicenda.
- **Compenso del trasportatore:** sul viaggio si salva una "fotografia" del compenso di quel momento; lo vedono
  solo gli admin, il nome del trasportatore lo vedono tutti.
- **La partenza non c'è ancora:** il movimento di uscita dei bancali e lo stato *spedita* arrivano con i documenti.
- L'ordine delle tappe di un giro, quanti livelli si impilano davvero e la forma dei rimorchi sono domande
  ancora aperte con l'azienda: le scelte di oggi sono un punto di partenza e si cambiano dalle impostazioni
  dove possibile.

#### Scelte a mano e ricalcolo del giorno

Il programma decide, ma una persona può correggerlo. Tutto questo vale solo per i viaggi **pianificati** (non
partiti) e lo possono fare tutti gli utenti collegati, dal dettaglio del viaggio (`/trips/{id}`):

- **Spostare un bancale** su un altro viaggio **dello stesso giro e dello stesso giorno**, oppure su un **nuovo
  viaggio** con un mezzo libero. Il programma controlla che il carico entri nel mezzo di arrivo (stessi limiti
  del piano) e non lascia un viaggio vuoto: l'ultimo bancale non si sposta, si annulla il viaggio.
- **Bloccare / sbloccare** un bancale. Uno spostato a mano si **blocca da solo** (si vede chi e quando, nel
  fumetto del badge *Bloccato a mano*).
- **Cambiare il mezzo** di un viaggio con un altro mezzo libero quel giorno, se il carico ci sta. Trasportatore e
  compenso diventano quelli del nuovo mezzo.

Cosa cambia nel piano:
- **Viaggi già confermati come punto di partenza.** Il piano di un giorno parte da loro: il mezzo di un viaggio
  confermato non cambia mai da solo e i bancali nuovi dello stesso giro salgono **prima** su quel mezzo, finché ci
  stanno; solo il resto prende un altro mezzo. Quello che è già sul viaggio resta dov'è.
- **Ricalcola il giorno** (`/trips/plan?date=...&ricalcola=1`): i bancali **non bloccati** dei viaggi confermati
  tornano nel mucchio e vengono riassegnati da capo (anche tra i viaggi dello stesso giro, ad esempio perché è
  arrivato un bancale più vecchio); quelli **bloccati a mano non si spostano mai**. Anche il ricalcolo è solo una
  proposta: la pagina dice cosa cambierebbe e si applica con **Conferma il ricalcolo**. Un viaggio che resterebbe
  senza bancali viene annullato (resta nello storico).

Dettagli tecnici:
- Migrazione `0026`: `trip_items.is_locked`, `locked_by`, `locked_at` (un `CHECK` li tiene coerenti tra loro).
- `TripPlanner` riceve i viaggi già confermati (`PlanSeedTrip`) e, nel ricalcolo, tiene fissi solo i bloccati.
  I viaggi che il piano non cambia non compaiono e non si riscrivono.
- L'impronta del piano comprende anche l'id dei viaggi già confermati e la modalità (normale o ricalcolo): un
  piano normale non si può confermare come ricalcolo.
- Più utenti insieme: spostamenti, blocchi e cambio mezzo prendono i blocchi nello stesso ordine di conferma e
  annullamento (prima le richieste, poi il mezzo); lo spostamento tra due viaggi blocca le richieste dei due con un
  solo comando ordinato per id, così due spostamenti in direzioni opposte non si bloccano a vicenda. Il mezzo
  scelto si blocca e si ricontrolla con una lettura aggiornata: due persone non possono prendere lo stesso mezzo.

### Utenti

Gli admin gestiscono gli utenti da `/users`; ogni utente cambia la propria password da «La mia password»,
in alto accanto al nome.

| Chi | Cosa può fare |
|---|---|
| Admin | elenco (nome, e-mail, ruolo, stato, ultimo accesso), nuovo utente con password iniziale, modifica di nome e ruolo, disattivazione e riattivazione, nuova password per un altro utente |
| Tutti | «La mia password»: serve quella attuale |

Regole per non restare chiusi fuori (in `src/Service/UserService.php`):
- un admin **non può disattivare sé stesso** né **togliersi il ruolo** di admin;
- deve restare **sempre almeno un admin attivo**: il controllo blocca le righe degli admin (`SELECT ... FOR UPDATE`)
  dentro una transazione, così due admin che si tolgono il ruolo a vicenda nello stesso istante non lasciano
  l'applicazione senza admin;
- un admin non reimposta la propria password dalla lista: usa «La mia password», che chiede quella attuale.

Sicurezza:
- **Password** (`src/Service/PasswordPolicy.php`, la usa anche `bin/create-user.php`): almeno 12 caratteri, al massimo
  72 byte (oltre, bcrypt ignorerebbe il resto senza dirlo), niente spazi all'inizio o alla fine. Le password non si
  ripresentano mai nei form dopo un errore.
- **Sessioni chiuse quando cambia la password:** nella sessione c'è un'impronta della password (un hash del suo
  hash). Se un admin reimposta la password di qualcuno, le sessioni già aperte di quella persona si chiudono alla
  richiesta successiva; chi cambia la propria resta collegato solo nella sessione da cui l'ha cambiata.
- Un utente **disattivato** esce subito, perché ruolo e stato si rileggono dal database a ogni richiesta.
- L'e-mail si salva in minuscolo ed è unica; non si cambia dopo la creazione, perché è il nome con cui si entra.
- Gli utenti **non si cancellano**: sono collegati ad arrivi, richieste e movimenti. Si disattivano.
### Impostazioni

Pagina `/settings`, solo admin. I valori di business non sono scritti nel codice: si chiedono a
`SettingService`.

| Impostazione | Valore di partenza |
|---|---|
| Altezza massima di carico del bancale | 750 mm (ogni tipo di bancale può avere la sua) |
| Giorni dopo i quali un bancale fermo viene segnalato | 2 |
| Capacità predefinita di una posizione | 300 bancali |
| Tolleranza sul peso dei bancali (segnato dal fornitore contro calcolato) | 5 % |
| Livelli massimi di bancali impilati sul mezzo | 2 (1 = nessun bancale sopra un altro) |
| Ragione sociale, indirizzo, partita IVA e piè di pagina dei documenti | vuoti |

Le definizioni (limiti e valore di partenza) stanno in un solo elenco in `SettingService::definitions()`: nel
database finisce solo ciò che l'admin salva. Per aggiungere un'impostazione basta una riga in quell'elenco.

### Avvio con Docker (sviluppo)

`docker-compose.yml` avvia Nginx 1.24, PHP-FPM 8.3 e MySQL 8.0, le stesse versioni di Ubuntu 24.04.
Nel `.env` imposta una `DB_PASSWORD` (e `DB_HOST=db`, anche se il compose lo forza comunque), poi:

```powershell
docker compose up -d --build
docker compose run --rm php composer install
docker compose run --rm php php bin/migrate.php
docker compose run --rm php php bin/seed.php
```

Apri <http://localhost:8080>. MySQL è raggiungibile dal PC su `127.0.0.1:3307`.

> **Non testato:** quando sono stati scritti non c'era Docker a disposizione, quindi `docker-compose.yml`
> e i file in `docker/` non sono mai stati eseguiti. Se qualcosa non parte, il primo posto dove guardare
> sono i log (`docker compose logs`).

### Produzione e backup

`deploy/` contiene la configurazione di Nginx (accesso limitato alla subnet locale, `.env` e `.git`
bloccati, intestazioni di sicurezza), il pool PHP-FPM, `backup.sh` per cron, `deploy.sh` per gli
aggiornamenti e la guida passo passo **[deploy/README.md](deploy/README.md)** per installare tutto
su Ubuntu Server 24.04 da zero.

Punti di sicurezza da conoscere:
- L'applicazione in produzione usa un utente MySQL con **permessi minimi** (`grant-app-user.sh`): su
  `pallet_movements` può solo leggere e inserire, quindi lo storico resta intatto anche in caso di bug.
  Un altro utente, separato, applica le migrazioni.
- Il seed è disabilitato in produzione: il primo admin si crea con `php bin/create-user.php`.

## Test

I test usano PHPUnit e stanno in `tests/`:

| File | Tipo | Cosa verifica |
|---|---|---|
| `tests/Core/RouterTest.php` | unitario | rotte, parametri `{id}`, 404 e 405, ordine dei middleware, gruppi, middleware che blocca |
| `tests/Core/RequestTest.php` | unitario | normalizzazione di percorso e metodo, lettura dell'input |
| `tests/Core/MigrationRunnerTest.php` | unitario | lo spezzatore delle istruzioni SQL delle migrazioni |
| `tests/Core/ValidatorTest.php` | unitario | ogni controllo del validatore, con valori limite e input ostili (apici, tag, array) |
| `tests/Service/ProductServiceTest.php` | integrazione | validazione, normalizzazione, codici duplicati, attiva/disattiva, ricerca (con il vero repository) |
| `tests/Service/MasterDataServicesTest.php` | integrazione | regole comuni delle anagrafiche e quelle di ognuna (giri dei clienti, targhe, capacità, EPAL) |
| `tests/Service/SettingServiceTest.php` | integrazione | valori di partenza, salvataggio, tutto-o-niente, limiti |
| `tests/Service/InboundServiceTest.php` | integrazione | arrivo completo (documento, bancali, movimenti), codici consecutivi, errori sulla riga giusta, capacità, peso, documento duplicato, tutto-o-niente |
| `tests/Service/UserServiceTest.php` | integrazione | creazione, e-mail unica in minuscolo, password, modifica, disattivazione, protezioni contro il blocco degli admin, reset e «la mia password» |
| `tests/Service/PasswordPolicyTest.php` | unitario | lunghezza minima, limite di 72 byte (anche con lettere accentate), spazi |
| `tests/Service/OrderServiceTest.php` | integrazione | richiesta completa, codici consecutivi, date, prodotto ripetuto, modifica che sostituisce le righe, annullamento, stati bloccati, giacenza |
| `tests/Service/AgingRuleTest.php` | unitario | la regola dei bancali fermi con date fisse: giorni di calendario, soglia, ora legale, stessa risposta in PHP e in SQL |
| `tests/Service/StockServiceTest.php` | integrazione | riepilogo per prodotto (solo bancali in magazzino, somme DECIMAL), filtri, ordine, bancali al limite della soglia, soglia dalle impostazioni |
| `tests/Core/ThousandthsTest.php` | unitario | decimali convertiti in millesimi interi e ritorno, valori non validi rifiutati |
| `tests/Service/LoadCalculatorTest.php` | unitario | file sul pianale, rotazione del bancale, impilamento per tipo, altezza del rimorchio, portata, numero di bancali |
| `tests/Service/TripPlannerTest.php` | unitario | bancali più vecchi per primi, precedenza delle richieste, giri, scelta del mezzo, cosa resta in magazzino, cosa manca, stesso piano con gli stessi dati; viaggi già confermati, bancali bloccati, ricalcolo |
| `tests/Service/TripServiceTest.php` | integrazione | piano dai dati veri, conferma tutto-o-niente (viaggio, bancali prenotati, richieste pianificate), piano cambiato nel frattempo, annullamento, richiesta pianificata non modificabile; spostamenti e blocchi a mano, cambio mezzo, ricalcolo |
| `tests/Core/DatabaseTransactionTest.php` | integrazione | commit, rollback e savepoint quando una transazione è già aperta |
| `tests/Core/ApplicationConfigTest.php` | unitario | le variabili d'ambiente vere vincono su `.env` |

I test del service parlano con MySQL, ma **solo con il database di test** (`logidock_test`):
`phpunit.xml` forza `DB_NAME=logidock_test` e `TestDatabase` si rifiuta di partire con un database
il cui nome non finisce in `_test`. Ogni test gira in una transazione che si annulla alla fine,
quindi il database resta pulito. Se MySQL non è raggiungibile quei test vengono saltati (e lo dicono).

Preparazione, una volta sola (serve un utente con permessi anche su `logidock_test`):

```powershell
# in MySQL: CREATE DATABASE logidock_test; GRANT ALL ON logidock_test.* TO 'logidock'@'localhost';
$env:DB_NAME = 'logidock_test'; php bin/migrate.php; Remove-Item Env:DB_NAME
composer test
```

Per lanciare un solo test: `php vendor/bin/phpunit --filter testDeactivateAndActivate`.

Il database di test non va riempito col seed. Quando il codice cambia lo schema, rilancia la migrazione
con la stessa riga di sopra.

## Roadmap

- [x] **Fase 0:** piano, schema del database e decisioni architetturali
- [x] **Fase 1:** scheletro (router, configurazione, gestione errori, layout)
- [x] **Fase 2:** migrazioni SQL, script di migrazione, seed
- [x] **Fase 3:** autenticazione, sessioni sicure, CSRF, ruoli
- [x] **Fase 4:** anagrafica prodotti (funzionalità verticale di esempio)
- [x] **Fase 5:** Docker Compose, configurazione di produzione, backup
- [x] **Fase 6:** test PHPUnit e documentazione finale

### Prossime fasi (dalle richieste dell'azienda)

L'azienda vuole registrare le richieste dei clienti e i bancali che arrivano ogni giorno, far decidere
al programma cosa va su quale rimorchio e cosa resta in magazzino, avvisare quando qualcosa è fermo da
troppo tempo, e produrre tutti i documenti (carico per camion, consegna per cliente, compenso per i
camionisti). Serve anche lo storico di viaggi, forniture e pagamenti.

- [ ] **Fase 7:** modello dati v2. *Fatto per le anagrafiche* (impostazioni, clienti, giri, trasportatori, tipi di
  bancale e di rimorchio, capacità delle posizioni); mancano documenti del fornitore, richieste e tappe dei viaggi,
  che si aggiungono insieme alle fasi che li usano
- [x] **Fase 8:** anagrafiche (fornitori, clienti, giri, veicoli, trasportatori, rimorchi, posizioni, tipi di bancale) e impostazioni
- [x] **Fase 9:** arrivi giornalieri: documento del fornitore con date, bancali con prezzo e peso, posizione
- [x] **Fase 10:** richieste dei clienti
- [x] **Fase 11:** gestione degli utenti dall'applicazione (solo admin): elenco, nuovo utente, ruolo, disattivazione,
  reimpostazione della password, e "la mia password" per tutti
- [x] **Fase 12:** giacenze per prodotto e per bancale, avviso per i bancali fermi da troppo tempo (nella home e nella lista)
- [x] **Fase 13:** pianificazione dei viaggi: piano proposto dal programma (bancali ai mezzi, giro per giro),
  conferma e annullamento, modifica manuale (sposta, blocca, cambia mezzo) e ricalcolo intorno alle scelte a mano
- [ ] **Fase 14:** documenti in PDF (carico per camion, consegna per cliente con prezzi, compenso camionisti)
- [ ] **Fase 15:** storico viaggi, forniture per fornitore, pagamenti dei clienti, report
- [ ] **Fase 16:** ricerca globale per descrizione: un testo cercato in tutti i campi di tutti i dati (clienti,
  fornitori, prodotti, arrivi, richieste...), con risultati di ogni tipo e il collegamento al dettaglio di ciascuno
  (i dettagli si definiscono quando si inizia la fase)
- [ ] **Fase 17:** navigazione "a lisca di pesce" (da definire)
- [ ] **Fase 18:** qualità finale e deploy

## Versione

La versione sta nel file [`VERSION`](VERSION) e si vede nel piè di pagina dell'applicazione e del sito.
Il formato è `MAGGIORE.MINORE.FASE.COMMIT`, per esempio `0.0.10.0`:

| Numero | Significato |
|---|---|
| FASE (terzo) | la fase di sviluppo in corso, per ora legata alla roadmap |
| COMMIT (ultimo) | cresce di uno a ogni commit e riparte da 0 quando inizia una nuova fase |

```powershell
php bin/version.php            # mostra la versione
php bin/version.php bump       # prima di ogni commit: +1 all'ultimo numero
php bin/version.php phase 11   # all'inizio di una nuova fase: 0.0.11.0
```

Il comando aggiorna `VERSION` e il numero scritto nel sito vetrina; l'applicazione legge `VERSION` da sola.
Non è *semantic versioning* (che ha tre numeri), per questo la versione non sta in `composer.json`.

## Licenza

LogiDock è **source available** e ha una **doppia licenza**:

| Uso | Licenza | Costo |
|---|---|---|
| Non commerciale: usi personali (studio, ricerca, hobby...) e uso da parte di enti benefici, istituti di istruzione, enti pubblici di ricerca, organizzazioni per la sicurezza o la salute pubblica, organizzazioni per la tutela dell'ambiente e istituzioni governative | [PolyForm Noncommercial License 1.0.0](LICENSE) | gratuito |
| Commerciale o professionale, non consentito dalla licenza gratuita (per esempio un'azienda che lo usa per la propria attività) | accordo di licenza commerciale separato con il titolare dei diritti | a pagamento |

- [LICENSE](LICENSE) contiene il testo ufficiale della PolyForm Noncommercial License 1.0.0, in inglese e
  senza modifiche. È quello il testo che vale: questa tabella è solo un riassunto.
- [NOTICE](NOTICE) contiene l'avviso di copyright (`Required Notice:`) che, secondo la licenza, deve
  accompagnare ogni copia del software.
- [COMMERCIAL-LICENSE.md](COMMERCIAL-LICENSE.md) spiega quando serve la licenza commerciale e come
  chiederla. È una pagina informativa: **non concede da sola nessuna licenza**.

**Come richiedere una licenza commerciale:** scrivi a
**<riccardo.melis06@gmail.com>** indicando chi sei, come vuoi usare
LogiDock, quante installazioni o utenti prevedi e per quanto tempo. Prezzo e condizioni si concordano
nell'accordo scritto.

"Source available" e non "open source": il codice è pubblico e si può studiare, ma la licenza gratuita
esclude l'uso commerciale, e la definizione di open source non ammette limiti di questo tipo.

Da sapere:
- Le versioni pubblicate prima dell'aggiunta del file `LICENSE` non avevano una licenza esplicita: per
  quelle restano solo i diritti che i Termini di servizio di GitHub danno agli utenti sui repository
  pubblici (per esempio il fork su GitHub).
- Le librerie installate con Composer (MIT, BSD-3-Clause, Apache-2.0) e le immagini Docker di sviluppo
  hanno le loro licenze, che non cambiano. Non sono incluse in questo repository: Composer e Docker le
  scaricano a parte.
- Il software non contiene sistemi di attivazione, pagamento o blocco.
