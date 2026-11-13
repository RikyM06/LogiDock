# Fase 15 - Storico, pagamenti e report: piano di implementazione

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** chiudere un viaggio, registrare il pagamento delle richieste e del compenso, e leggere lo storico in una pagina `/storico` con quattro schede.

**Architecture:** una migrazione aggiunge colonne a `orders` e `trips` (approccio 1 del design). Due service nuovi con le transazioni (`TripClosureService`, `PaymentService`), un service di sola lettura (`HistoryService`) sopra `HistoryRepository`, un controller nuovo per `/storico` e metodi nuovi in `TripController` / `OrderController`. L'importo di una richiesta usa la STESSA espressione SQL del DDT.

**Tech Stack:** PHP 8.3, MySQL 8 via PDO, PHPUnit su `logidock_test`, template PHP, nessuna libreria nuova.

**Spec:** `docs/superpowers/specs/2026-11-13-fase-15-storico-design.md` (approvato il 2026-11-13). Chi esegue legga prima la spec.

## Global Constraints

- PHP 8.3, `declare(strict_types=1);`, PSR-12, tipi su ogni parametro e ritorno. Nessuna libreria nuova.
- Codice, classi, metodi, tabelle in **inglese**; commenti, README, testi dell'interfaccia in **italiano**.
- Livelli: controller sottile, service senza SQL, repository senza regole; transazioni solo nel service (`Database::transaction()`); cablaggio esplicito in `Application::registerServices()` (e controller subito sotto, come gli altri).
- Importi `DECIMAL` in database, stringhe in PHP, somme con `Core\Thousandths`, mai `float`. Output sempre con `e()`.
- `pallet_movements` è append-only: solo lettura.
- Errori che l'utente deve leggere: eccezione con messaggio italiano pronto (come `TripChangeNotAllowedException`); stati impossibili: `LogicException` con rollback.
- Tutto ciò che riguarda il compenso: solo admin, **404 (non 403)** agli altri; all'operatore le colonne del compenso non esistono.
- Orari nel database in UTC (`Y-m-d H:i:s`), `?DateTimeImmutable $now = null` nei metodi che scrivono un orario (solo i test lo passano).
- Le prove HTTP che scrivono dati girano su `logidock_test` (`$env:DB_NAME = 'logidock_test'`), mai sul database di sviluppo.
- Un solo commit per la fase, messaggio `v0.0.15.0: ... (closes #29)`; prima `php bin/version.php phase 15`; nessun push se non richiesto.
- PowerShell 5.1: niente `&&`; i `.ps1` si leggono in ANSI (niente accenti nei pattern); file UTF-8 mai riscritti con `Get-Content` senza `-Encoding UTF8`.

## Review Focus

1. **Doppio click su "Segna pagata"** (o due schede aperte): un solo pagamento, il secondo riceve un messaggio italiano e la data del primo non cambia. Test in Task 3.
2. **Richiesta con importo 0,00** (prezzo 0): si può segnare pagata, nessun errore 500. Test in Task 3.
3. **POST costruito a mano**: pagare una richiesta non `shipped` o annullata, concludere un viaggio non partito, compenso su viaggio senza trasportatore/compenso, rotte admin da un operatore (404). Test in Task 3, 4.
4. **Filtri GET sporchi**: `dal=abc`, `al` prima di `dal`, id non numerici, `tipo=compensation` forzato da un operatore: ignorati senza errore e senza rivelare i compensi. Test in Task 5, 6.
5. **Fornitori dopo la partenza**: la quantità fornita non scende a 0 quando i bancali partono (legge i movimenti `inbound`, non `pallets.quantity`). Test in Task 5.

---

## Mappa dei file

| File | Responsabilità |
|---|---|
| `database/migrations/0028_add_payments_and_closure.sql` (nuovo) | colonne e CHECK su `orders` e `trips` |
| `src/Model/Order.php`, `src/Model/Trip.php` (modifica) | campi `paid*`, `concluded*`, `compensationPaid*` (argomenti finali opzionali) |
| `src/Repository/OrderRepository.php`, `TripRepository.php` (modifica) | SQL puntuale di pagamento e chiusura |
| `src/Repository/TransportDocumentRepository.php` (modifica) | costante con l'espressione dell'importo di riga, condivisa |
| `src/Service/TripClosureService.php` (nuovo) | concludere un viaggio |
| `src/Service/PaymentService.php`, `PaymentNotAllowedException.php` (nuovi) | pagamenti richiesta e compenso, annullamenti con log |
| `src/Repository/HistoryRepository.php`, `src/Service/HistoryService.php`, `src/Model/History/*.php` (nuovi) | le quattro schede, sola lettura |
| `src/Controller/HistoryController.php` (nuovo); `TripController`, `OrderController` (modifica) | pagina `/storico` e azioni |
| `templates/history/*.php` (nuovi), `templates/trips/show.php`, `templates/orders/show.php`, `templates/layout.php` (modifica) | interfaccia e voce di menu |
| `config/routes.php`, `src/Core/Application.php` (modifica) | rotte e cablaggio |
| `database/seeds/001_dev_data.php`, `docs/tools/capture-screenshots.ps1`, `docs/index.html`, `README.md` (modifica) | dati demo, screenshot, documentazione |
| `tests/Support/TripFlowFixture.php` (nuovo) | prepara un viaggio partito per i test di Task 2-5 |

---

### Task 1: Migrazione 0028, modelli e letture

**Files:**
- Create: `database/migrations/0028_add_payments_and_closure.sql`
- Modify: `src/Model/Order.php`, `src/Model/Trip.php`, `src/Repository/OrderRepository.php` (`baseSelect`), `src/Repository/TripRepository.php` (`baseSelect` + `groupBy`)
- Test: `tests/Service/PaymentSchemaTest.php`

**Interfaces:**
- Produces: `Order::$paidAt ?string`, `Order::$paidAmount ?string`, `Order::$paidByName ?string`, `Order::isPaid(): bool`; `Trip::$concludedAt ?string`, `Trip::$compensationPaidAt ?string`, `Trip::isConcluded(): bool`, `Trip::isCompensationPaid(): bool`. Tutti argomenti **finali opzionali** dei costruttori (come `carrierTaxCode`), così i test esistenti non cambiano.

- [ ] **Step 1: Write the failing test** `PaymentSchemaTest` (integrazione, `IntegrationTestCase`): su un ordine di prova, `UPDATE orders SET paid_at = NOW()` senza `paid_amount` deve lanciare `PDOException` (CHECK dei tre campi); `paid_at` valorizzato con `status = 'open'` deve lanciare; su un viaggio `UPDATE trips SET status='completed'` senza `concluded_at` deve lanciare; `compensation_paid_at` con `carrier_fee IS NULL` deve lanciare. Le righe di prova si creano con i repository/service come negli altri test (vedi `TripDepartureServiceTest::setUp`).
- [ ] **Step 2: Run** `composer test -- --filter PaymentSchemaTest` → FAIL (colonne inesistenti).
- [ ] **Step 3: Write migration 0028** (idempotente come le altre, un solo `ALTER` per tabella):
  - `orders`: `paid_at DATETIME NULL`, `paid_amount DECIMAL(12,2) NULL`, `paid_by BIGINT UNSIGNED NULL` + FK `users` (`RESTRICT`), `CHECK ((paid_at IS NULL) = (paid_amount IS NULL) AND (paid_at IS NULL) = (paid_by IS NULL))`, `CHECK (paid_at IS NULL OR status = 'shipped')`.
  - `trips`: `concluded_at DATETIME NULL`, `concluded_by` FK, `compensation_paid_at DATETIME NULL`, `compensation_paid_by` FK, `CHECK ((status = 'completed') = (concluded_at IS NOT NULL))`, `CHECK (compensation_paid_at IS NULL OR carrier_fee IS NOT NULL)`, `CHECK ((concluded_at IS NULL) = (concluded_by IS NULL))`, `CHECK ((compensation_paid_at IS NULL) = (compensation_paid_by IS NULL))`.
  Applicare con `php bin/migrate.php` su `logidock` e, con `$env:DB_NAME='logidock_test'`, su `logidock_test`.
- [ ] **Step 4: Implement** i campi nei due modelli (`fromRow` legge `paid_at`, `paid_amount`, `paid_by_name`, `concluded_at`, `compensation_paid_at` con `isset`), e aggiungere ai due `baseSelect` le colonne (join `users` per `paid_by_name` sugli ordini; per `trips` aggiungere le colonne a `GROUP BY`).
- [ ] **Step 5: Run** `composer test` → tutti verdi (344 + nuovi).
- [ ] **Step 6: Commit** non qui (un solo commit a fine fase). Solo `git add` locale non necessario.

### Task 2: Concludere un viaggio

**Files:**
- Create: `src/Service/TripClosureService.php`, `tests/Support/TripFlowFixture.php`
- Modify: `src/Repository/TripRepository.php`
- Test: `tests/Service/TripClosureServiceTest.php`

**Interfaces:**
- Consumes: `TripRepository::changeStatus(int, string, string): bool`, `TripDepartureService::depart(int $tripId, int $userId, ?DateTimeImmutable $now): array`, `TripChangeNotAllowedException`.
- Produces: `TripRepository::markConcluded(int $id, int $userId, string $nowUtc): bool` (`UPDATE trips SET status='completed', concluded_at=?, concluded_by=? WHERE id=? AND status='in_transit'`, true se 1 riga); `TripClosureService::__construct(Database $database, TripRepository $trips)` e `conclude(int $tripId, int $userId, ?DateTimeImmutable $now = null): void` (lancia `TripChangeNotAllowedException` con messaggio italiano se non `in_transit`).
- `TripFlowFixture` (test support): costruisce con servizi e repository veri, dentro la transazione del test, un cliente, un prodotto, un mezzo con trasportatore e compenso, una richiesta con prezzo, i bancali, il viaggio confermato; metodi `departedTrip(string $unitPrice = '2'): array{tripId:int, orderId:int}` e `plannedTrip(): int`, più accesso a `$trips`, `$departures`, `$orders`, `$userId`. Copia il cablaggio di `TripDepartureServiceTest::setUp` (non rifattorizzare quel test).

- [ ] **Step 1: Write failing tests** in `TripClosureServiceTest`: `testConcludeMarksTheTripCompleted` (stato `completed`, `concluded_at` = ora passata, `concluded_by` = utente); `testConcludeTwiceFails` (secondo `conclude` → `TripChangeNotAllowedException`, data invariata); `testPlannedTripCannotBeConcluded`; `testUnknownTripCannotBeConcluded`; `testConcludedTripCannotBeCancelledOrChanged` (`TripService::cancel` → `TripNotCancellableException`).
- [ ] **Step 2: Run** `composer test -- --filter TripClosureServiceTest` → FAIL.
- [ ] **Step 3: Implement** `TripClosureService::conclude` in una transazione: `markConcluded`; se false → eccezione «Si può concludere solo un viaggio già partito e non ancora concluso.».
- [ ] **Step 4: Run** → PASS.

### Task 3: Pagamenti (richiesta e compenso)

**Files:**
- Create: `src/Service/PaymentService.php`, `src/Service/PaymentNotAllowedException.php`
- Modify: `src/Repository/OrderRepository.php`, `src/Repository/TripRepository.php`, `src/Repository/TransportDocumentRepository.php`, `src/Core/Application.php`
- Test: `tests/Service/PaymentServiceTest.php`

**Interfaces:**
- Produces in `TransportDocumentRepository`: `public const LINE_TOTAL_SQL = 'ROUND(ti.quantity * ol.unit_price, 2)'`, usata anche da `linesOfTrip` (alias di riga: `ti` = trip_items, `ol` = order_lines).
- Produces in `OrderRepository`: `billedAmount(int $orderId): string` = `COALESCE(SUM(LINE_TOTAL_SQL), 0)` su `trip_items ti JOIN order_lines ol ... JOIN trips t` con `t.status IN ('in_transit','completed')`, stringa decimale `'0.00'` se vuota; `lockForPayment(int $orderId): ?array{status:string, paid_at:?string}` (`SELECT ... FOR UPDATE`); `markPaid(int $orderId, string $amount, int $userId, string $nowUtc): bool` (`... WHERE id=? AND paid_at IS NULL AND status='shipped'`); `markUnpaid(int $orderId): ?array{paid_at:string, paid_amount:string}` (legge poi azzera, `null` se non era pagata).
- Produces in `TripRepository`: `markCompensationPaid(int $tripId, int $userId, string $nowUtc): bool` (`WHERE id=? AND compensation_paid_at IS NULL AND carrier_fee IS NOT NULL AND status IN ('in_transit','completed')`), `markCompensationUnpaid(int $tripId): ?array{compensation_paid_at:string, carrier_fee:string}`.
- Produces: `PaymentService::__construct(Database $database, OrderRepository $orders, TripRepository $trips, Logger $logger)` con `markOrderPaid(int $orderId, int $userId, ?DateTimeImmutable $now = null): string` (restituisce l'importo salvato), `undoOrderPayment(int $orderId, int $userId): void`, `markCompensationPaid(int $tripId, int $userId, ?DateTimeImmutable $now = null): void`, `undoCompensationPayment(int $tripId, int $userId): void`; `PaymentNotAllowedException extends RuntimeException` (messaggio italiano).
- Gli annullamenti scrivono `Logger::info` con codice richiesta/viaggio, importo, data di pagamento precedente e id utente. Il controllo "solo admin" sta nel **controller** (Task 4), non nel service.

- [ ] **Step 1: Write failing tests** `PaymentServiceTest` (usa `TripFlowFixture`, `Logger` su file temporaneo):
  - `testPaidAmountEqualsTheSumOfTheDdtLines`: dopo `departedTrip('1.995')` con più bancali, `markOrderPaid` ritorna la somma delle righe di `linesOfTrip` (stessi arrotondamenti, calcolata nel test con `Thousandths`/stringhe) e la salva in `orders.paid_amount`.
  - `testPayingTwiceFailsAndKeepsTheFirstDate`: secondo `markOrderPaid` → `PaymentNotAllowedException`, `paid_at` uguale a quello del primo.
  - `testAZeroAmountOrderCanBePaid`: `departedTrip('0')` → importo `'0.00'`, salvato.
  - `testOrderNotShippedCannotBePaid` (richiesta `planned` e richiesta `cancelled`) e `testUnknownOrderCannotBePaid`.
  - `testPartialOrderCannotBePaid`: richiesta con una riga coperta solo in parte da un viaggio partito → eccezione.
  - `testUndoOrderPaymentClearsTheFieldsAndLogsTheOldOnes`; `testUndoOnAnUnpaidOrderFails`.
  - `testCompensationPaidNeedsCarrierFeeAndADepartedTrip` (viaggio senza trasportatore → eccezione; `planned` → eccezione), `testCompensationPaidTwiceFails`, `testUndoCompensationLogs`.
- [ ] **Step 2: Run** `composer test -- --filter PaymentServiceTest` → FAIL.
- [ ] **Step 3: Implement** repository (SQL sopra) e `PaymentService`. `markOrderPaid`: transazione; `lockForPayment` (se `null` o stato ≠ `shipped` → `PaymentNotAllowedException('Si può segnare pagata solo una richiesta spedita per intero.')`; se già pagata → «Questa richiesta risulta già pagata.»); importo da `billedAmount`; `markPaid` (se false → «già pagata», copre il doppio click); `LogicException` se `shipped` ma nessun `trip_items` su viaggio partito (billed vuoto con righe). Sostituire in `linesOfTrip` il frammento SQL con la costante.
- [ ] **Step 4: Wire** in `Application::registerServices()`: `PaymentService` (Database, OrderRepository, TripRepository, Logger) e `TripClosureService`.
- [ ] **Step 5: Run** `composer test` → verdi, compresi i test del DDT (la costante non deve cambiare nessun importo).

### Task 4: Azioni nell'interfaccia (viaggio e richiesta)

**Files:**
- Modify: `src/Controller/TripController.php`, `src/Controller/OrderController.php`, `config/routes.php`, `src/Core/Application.php`, `templates/trips/show.php`, `templates/orders/show.php`
- Test: `tests/Service/PaymentServiceTest.php` (già coperto) + prova HTTP in Task 7

**Interfaces:**
- Consumes: `TripClosureService::conclude`, `PaymentService::*`, `AuthService::user()`, `User::isAdmin()`.
- Produces: rotte POST (nel gruppo `AuthMiddleware`) `/trips/{id}/conclude`, `/trips/{id}/compensation/pay`, `/trips/{id}/compensation/unpay`, `/orders/{id}/pay`, `/orders/{id}/unpay`. Costruttori di `TripController` e `OrderController` ricevono i service nuovi in coda.
- Regole: `compensation/*` e `/orders/{id}/unpay` → se l'utente non è admin, `HttpException(404, ...)` (come `DocumentController`); gli errori dei service tornano alla pagina con `session->flash('error', ...)`, gli esiti con `flash('success', ...)`; ritorno a `/trips/{id}` o `/orders/{id}`; il campo `return` opzionale (solo percorsi che iniziano con `/storico?`) permette alla scheda Incassi di tornare ai suoi filtri.

- [ ] **Step 1:** Aggiungere metodi `conclude`, `payCompensation`, `unpayCompensation` a `TripController` e `pay`, `unpay` a `OrderController` (sottili, sul modello di `depart`).
- [ ] **Step 2:** `templates/trips/show.php`: pulsante «Segna concluso» (form POST con `_csrf`, `data-confirm`) quando `status = in_transit`; riga «Concluso il ...» quando concluso; solo per admin e con `carrierFee`: «Compenso: pagato il ... / da pagare» con pulsante «Segna pagato» o «Annulla pagamento».
- [ ] **Step 3:** `templates/orders/show.php`: per richiesta `shipped`: «Pagata il ... (importo)» o pulsante «Segna pagata»; admin: «Annulla pagamento». Mostrare l'importo da incassare (`billedAmount`) accanto al pulsante: il controller lo passa alla vista (aggiungere `PaymentService::amountDue(int $orderId): string` che chiama `OrderRepository::billedAmount`).
- [ ] **Step 4:** Rotte e cablaggio. Verifica manuale rapida con `php -S` su `logidock_test` è rimandata alla prova di Task 7.
- [ ] **Step 5: Run** `composer test` → verdi.

### Task 5: Report di storico (repository + service)

**Files:**
- Create: `src/Repository/HistoryRepository.php`, `src/Service/HistoryService.php`, `src/Model/History/TripHistoryRow.php`, `SupplierHistoryRow.php`, `PaymentHistoryRow.php`, `DocumentHistoryRow.php`
- Modify: `src/Core/Application.php`
- Test: `tests/Service/HistoryServiceTest.php`

**Interfaces:**
- Produces: `HistoryService::LIST_LIMIT = 200`; `HistoryFilters` come semplice array normalizzato da `HistoryService::normalizeFilters(array $query, bool $isAdmin): array{from:?string, to:?string, round:?int, status:?string, supplier:?int, customer:?int, payment:?string, type:?string}` (date solo `Y-m-d` valide con `checkdate`, altrimenti `null`; `from > to` → scambia o scarta `to`; `status` solo tra gli stati noti; `payment` solo `paid|unpaid|partial`; `type` solo `load_list|ddt` e, se `$isAdmin`, anche `compensation`); metodi `trips(array $f, bool $isAdmin): list<TripHistoryRow>`, `suppliers(array $f): list<SupplierHistoryRow>`, `payments(array $f): PaymentReport` (righe + `collectedTotal`, `dueTotal`, `partialTotal` come stringhe decimali via `Thousandths`), `documents(array $f, bool $isAdmin): list<DocumentHistoryRow>`.
- `TripHistoryRow` ha `fee` e `compensationPaidAt` **solo valorizzati se admin** (altrimenti `null` e la vista non stampa le colonne).
- SQL (in `HistoryRepository`, tutto parametrizzato, `LIMIT 200`): Viaggi su `trips.planned_date` con totale fatturato `SUM(TransportDocumentRepository::LINE_TOTAL_SQL)` solo per `in_transit`/`completed`; Fornitori su `supplier_documents.received_date`, quantità = `SUM(pallet_movements.quantity_delta)` dei movimenti `inbound` dei bancali di quei documenti, valore = quantità movimento × `pallets.unit_price`; Incassi su `orders.delivery_date` per richieste con almeno un viaggio partito: `shipped` (pagata o da pagare) e «parziale» (non `shipped` ma con righe su viaggi partiti), importo = stessa espressione; Documenti su `transport_documents.issued_at`, i `compensation` esclusi se non admin.

- [ ] **Step 1: Write failing tests** `HistoryServiceTest` (con `TripFlowFixture`; i filtri usano i codici/clienti creati nel test e un periodo lontano per non contare i residui):
  - `testSupplierQuantityDoesNotDropAfterDeparture` (Review Focus 5): quantità fornita = quantità dei movimenti `inbound` anche con bancali `shipped`.
  - `testPaymentsReportSplitsCollectedDueAndPartial` (una pagata, una da pagare, una parziale: totali esatti come stringhe).
  - `testTripsReportShowsBilledTotalOnlyForDepartedTrips`.
  - `testOperatorNeverGetsCompensationData` (righe Viaggi senza `fee`; Documenti senza `compensation` anche con `type=compensation` nel filtro; la lista di un admin sì).
  - `testDirtyFiltersAreIgnored` (`from='abc'`, `to` prima di `from`, `round='x'`, `status='boh'`, `payment='boh'`): nessuna eccezione, valori `null`/normalizzati.
  - Il limite di 200 righe non ha un test automatico (servirebbero 201 righe nel database di test): si verifica leggendo la query e a mano in Task 7.
- [ ] **Step 2: Run** `composer test -- --filter HistoryServiceTest` → FAIL.
- [ ] **Step 3: Implement** repository, modelli di riga (`final readonly`, `fromRow`) e service.
- [ ] **Step 4: Wire** `HistoryRepository`, `HistoryService` in `Application`.
- [ ] **Step 5: Run** `composer test` → verdi.

### Task 6: Pagina `/storico` e voce di menu

**Files:**
- Create: `src/Controller/HistoryController.php`, `templates/history/index.php`, `templates/history/_trips.php`, `_suppliers.php`, `_payments.php`, `_documents.php`
- Modify: `config/routes.php`, `src/Core/Application.php`, `templates/layout.php`, `public/assets/css/app.css` (solo se serve, con variabili CSS esistenti, mai colori fissi)

**Interfaces:**
- Consumes: `HistoryService` (Task 5), `AuthService::user()`.
- Produces: `HistoryController::index(Request $request, array $params): Response`, GET `/storico` (gruppo `AuthMiddleware`), parametri `scheda` (`viaggi|fornitori|incassi|documenti`, default `viaggi`, valore sconosciuto → `viaggi`), `dal`, `al`, `giro`, `stato`, `fornitore`, `cliente`, `pagamento`, `tipo`. Schede come **link** (`<nav aria-label="Schede dello storico">` con `aria-current="page"` sulla scheda attiva), niente JS; ogni scheda ha il suo form GET con i filtri e un link «Azzera filtri». Pagina `wide`. Sotto la lista, se ci sono 200 righe: «Mostrate le prime 200».
- Layout: aggiungere `'/storico' => 'Storico'` dopo `'/trips'` nell'array `$navigation` (per tutti).
- Importi nelle viste: stessa convenzione dei template esistenti (`str_replace('.', ',', ...)`), `e()` ovunque; le colonne del compenso si stampano solo se `$currentUser->isAdmin()`.

- [ ] **Step 1:** Controller (legge la query, chiama `normalizeFilters`, passa alla vista solo i dati della scheda scelta più le opzioni dei filtri: giri, fornitori, clienti dai service delle anagrafiche già esistenti).
- [ ] **Step 2:** Template (schede, tabelle, form filtri; in Incassi: pulsante «Segna pagata» con form POST verso `/orders/{id}/pay` e campo `return` con l'URL corrente; «Annulla pagamento» solo admin; riga di totali: incassato / da incassare / spedito non ancora incassabile; etichetta «parziale» senza pulsante).
- [ ] **Step 3:** Rotta, cablaggio, voce di menu.
- [ ] **Step 4: Verifica visiva:** avviare con `.\bin\start-test.ps1 -Dev` (o `php -S` a mano), aprire `/storico` a 1280 e 1440 px: la barra del menu deve restare su una riga con 10 voci per l'admin; se va a capo, ritoccare solo `.nav__link` / `.topbar__inner` (gap/padding), poi ricontrollare. La #51 resta aperta.
- [ ] **Step 5: Run** `composer test` → verdi.

### Task 7: Seed, prova HTTP, screenshot, documentazione, commit

**Files:**
- Modify: `database/seeds/001_dev_data.php`, `docs/tools/capture-screenshots.ps1`, `docs/index.html`, `README.md`, `CLAUDE.md` (inglese, non tracciato), `DOMANDE.md` (non tracciato)
- Create: scratchpad `smoke-storico.ps1` (fuori dal repository)

- [ ] **Step 1: Seed** (sempre tramite i service, con la guardia «solo se nessun viaggio è `completed`»): concludere il viaggio demo già partito e segnare pagata la richiesta di Alimentari Bianchi, lasciando non pagata quella di un secondo cliente (serve un secondo viaggio partito o una richiesta demo spedita; se il seed attuale ne ha una sola, aggiungerne una piccola e farla partire come già fatto per la prima). Attenzione: la partenza è irreversibile nel database di sviluppo; verificare prima con una transazione annullata su `logidock_test`. Eseguire `php bin/seed.php` e controllare lo stato (`bin` o query di sola lettura).
- [ ] **Step 2: Prova HTTP su `logidock_test`** (script nello scratchpad, `$env:DB_NAME='logidock_test'` prima di `php -S`): senza token CSRF → rifiutato; operatore: pagare una richiesta spedita ok, doppio click → messaggio, `unpay` → 404, `compensation/pay` → 404, `/storico?tipo=compensation` senza righe di compenso, colonne del compenso assenti; admin: tutto ok; anonimo → redirect al login; filtri sporchi → 200.
- [ ] **Step 3: Screenshot** nel `capture-screenshots.ps1`: `storico-viaggi`, `storico-fornitori`, `storico-incassi`, `storico-documenti` (+ rigenerare menu/viaggio-dettaglio/richiesta-dettaglio toccati); `php bin/version.php phase 15` PRIMA di rigenerare (la versione compare nel piè di pagina); verificare che non compaiano dati personali. Poi `docs/index.html` (card «Storico e pagamenti», 4 figure, «28 migrazioni», nuovo numero di test, roadmap Fase 15 «Fatta») e `README.md` (stato, sezione «Storico e pagamenti», test, roadmap).
- [ ] **Step 4: Documenti non tracciati:** `CLAUDE.md` (stato Fase 15, design in breve, nuovo numero di test), `DOMANDE.md` sezione «Fase 15» con le 8 assunzioni della spec (+ «Registro dei pagamenti annullati», «Esportazione CSV»); chiudere nelle domande della Fase 14 le voci 8 (compenso: ora risolta con le assunzioni 3-5) lasciando quelle ancora aperte.
- [ ] **Step 5: Verifica finale:** `composer test` (tutti verdi, riportare il numero), `git status` (nessun file spurio; `docs/images/` resta fuori).
- [ ] **Step 6: Commit** (solo dopo l'ok del proprietario, come per ogni fase): `git add -A -- . ':!docs/images'` e messaggio `v0.0.15.0: storico (viaggi, fornitori, incassi, documenti), chiusura dei viaggi e pagamento di richieste e compensi (closes #29)`. Nessun push senza richiesta. Poi aggiornare `CLAUDE.md` con l'hash.

---

## Autoverifica del piano

- **Copertura della spec:** obiettivi 1-4 → Task 2, 3, 4, 5-6; migrazione e vincoli → Task 1; importo unico → Task 3 (costante condivisa) e test sul DDT; partial → Task 5-6; permessi/404 → Task 4-6; seed/screenshot/doc/versione → Task 7; assunzioni → Task 7 Step 4; rischio menu → Task 6 Step 4.
- **Coerenza dei tipi:** `markOrderPaid` ritorna `string` (importo) e così lo usa Task 4; `normalizeFilters` produce le chiavi usate da `HistoryService` e dal controller; `TripFlowFixture::departedTrip` ritorna `array{tripId,orderId}` usato in Task 2, 3, 5.
- **Proporzioni:** i corpi non sono scritti: ogni passo dà firma, file e valori che la spec fissa.
