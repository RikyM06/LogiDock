# Fase 15 - Storico, pagamenti e report (design)

Issue [#29](https://github.com/RikyM06/LogiDock/issues/29). Data: 2026-11-13. Stato: da approvare.

## Perché

L'azienda ha chiesto che il database "tenga la storia" di tre cose: i viaggi, i pezzi forniti da ogni fornitore e
i soldi pagati dai clienti (requisito 9 delle note). Oggi:

- i viaggi si pianificano e partono, ma nessuno li chiude: `trips.status` ha già il valore `completed`
  (migrazione 0007) che il codice non imposta mai;
- i documenti si vedono solo dentro il singolo viaggio, non esiste un elenco;
- non esiste nessun pagamento: né del cliente, né del compenso del trasportatore (la Fase 14 ha rimandato qui
  anche questo);
- la merce fornita da ogni fornitore si può ricostruire dai bancali, ma nessuna pagina lo mostra.

Obiettivo: chiudere il giro "merce entra, parte, viene pagata" e poterlo rileggere dopo mesi.

## Obiettivi

1. **Chiudere un viaggio**: da *in viaggio* a *concluso*, con data e chi l'ha fatto.
2. **Pagamento del cliente**: pagata / non pagata **per richiesta**, con data, importo totale e chi l'ha
   registrato. Nessun pagamento parziale (decisione del 6 novembre).
3. **Pagamento del compenso** del trasportatore: pagato / da pagare per viaggio, solo admin.
4. **Una pagina `/storico`** (una sola voce di menu, scelta del proprietario) con quattro schede:
   Viaggi, Fornitori, Incassi, Documenti. Ogni scheda ha i suoi filtri.

## Non obiettivi

- Fatturazione, contabilità, IVA, solleciti, scadenze di pagamento (fuori scopo del progetto).
- Pagamenti parziali, più pagamenti per richiesta, metodi di pagamento.
- Esportazione CSV/Excel (si può aggiungere dopo; non richiesta).
- Correggere un viaggio partito per errore (domanda 6 della Fase 14: resta aperta).
- Un registro delle scelte a mano sui viaggi (domanda 19 della Fase 13: resta aperta).
- Risolvere il problema della barra del menu ([#51](https://github.com/RikyM06/LogiDock/issues/51)).

## Anti-obiettivi (la fase fallisce anche se "funziona" se...)

- un report mostra un numero diverso da quello scritto sul DDT per la stessa merce;
- l'operatore vede il compenso dei trasportatori (per lui deve essere come se non esistesse);
- un importo passa da un `float` PHP;
- un click doppio registra due pagamenti o sovrascrive la data del primo.

## Vincoli

Quelli del progetto (`CLAUDE.md`): nessuna libreria nuova, MySQL con PDO e query preparate, controller sottile,
niente SQL nei service, transazioni solo nel service, importi `DECIMAL` in database e stringhe in PHP (somme con
`Core\Thousandths`), UI in italiano, ogni output con `e()`. Due vincoli di questa fase:

- **Barra del menu**: un admin ha già 9 voci e sta su una riga a 1280 px solo con padding ridotti. "Storico" è la
  10a. Va verificato con lo screenshot a 1280 e 1440 px; se va a capo basta un ritocco minimo di CSS (non un
  ridisegno, quello è la #51).
- **`pallet_movements` è append-only**: la fase la legge soltanto.

## Approcci considerati

**1. Colonne sulle tabelle esistenti (scelto dal proprietario il 2026-11-13).**
`orders.paid_at / paid_amount / paid_by`, `trips.concluded_at / concluded_by / compensation_paid_at /
compensation_paid_by`. Una migrazione, nessun join in più, regge perché non ci sono pagamenti parziali.
Contro: annullare un pagamento fatto per errore cancella la traccia nel database. Mitigazione: l'annullamento è
solo admin e scrive una riga nel log (`Core\Logger`) con importo e data che aveva.

**2. Tabelle nuove (`order_payments`, `trip_events`).** Tengono ogni evento, errori corretti compresi. Più codice
e più join per un caso che oggi non esiste. Se arrivano i pagamenti parziali si passa a questa con una
migrazione che copia i dati. Scartato per ora.

**Scartato**: tabelle di riepilogo precalcolate. I dati sono pochi e un report ricalcolato a ogni richiesta non
può uscire di sincrono con i dati veri.

## Design

### Dati (migrazione 0028)

`orders`:
- `paid_at DATETIME NULL` (UTC, come il resto), `paid_amount DECIMAL(12,2) NULL`, `paid_by BIGINT UNSIGNED NULL`
  (FK `users`).
- CHECK: i tre campi sono tutti NULL o tutti valorizzati; `paid_at` valorizzato solo se `status = 'shipped'`.

`trips`:
- `concluded_at DATETIME NULL`, `concluded_by BIGINT UNSIGNED NULL` (FK `users`).
- `compensation_paid_at DATETIME NULL`, `compensation_paid_by BIGINT UNSIGNED NULL` (FK `users`).
- CHECK: `status = 'completed'` se e solo se `concluded_at` non è NULL; compenso pagato solo se `carrier_fee`
  non è NULL.

Nessuna riga esistente viola i vincoli (nessun viaggio è `completed`, nessun pagamento esiste).

### Regole

- **Concludere** (`TripClosureService::conclude`): solo da `in_transit`, con `changeStatus(in_transit ->
  completed)` condizionato (come la partenza: due click insieme non fanno danni). Non tocca le richieste, non
  tocca i bancali. Dopo, il viaggio è solo in lettura (come già dopo la partenza).
- **Importo di una richiesta**: la somma delle righe di DDT di quella richiesta, cioè
  `SUM(ROUND(trip_items.quantity * order_lines.unit_price, 2))` sui `trip_items` della richiesta su viaggi
  `in_transit` o `completed`. Lo calcola MySQL in DECIMAL, con la stessa espressione di
  `TransportDocumentRepository::linesOfTrip`: **una sola definizione**, che si riusa (non si riscrive), così
  report e DDT non possono divergere. Fino alla risposta alla domanda [#50](https://github.com/RikyM06/LogiDock/issues/50)
  l'importo è quello del bancale intero che parte.
- **Pagare una richiesta** (`PaymentService::markOrderPaid`): solo se la richiesta è `shipped` (tutte le righe
  coperte da viaggi partiti) e non già pagata. In transazione: blocca la richiesta (`FOR UPDATE`, stesso ordine di
  lock delle altre operazioni sulle richieste), ricalcola l'importo, scrive `paid_at/paid_amount/paid_by` con
  un `UPDATE ... WHERE paid_at IS NULL` (il secondo click trova 0 righe e riceve un errore con messaggio
  italiano pronto). L'importo non si digita: è quello calcolato e resta fotografato (come il prezzo sulla riga).
- **Annullare un pagamento** (solo admin): azzera i tre campi, scrive il log con importo e data precedenti.
- **Pagare il compenso** (solo admin): solo se il viaggio ha `carrier_fee` e non è `planned`/`cancelled`
  (il prospetto esce alla partenza), con lo stesso `UPDATE ... WHERE ... IS NULL`. Annullabile solo da admin,
  con log.
- **Eccezioni**: una `PaymentNotAllowedException` come `TripChangeNotAllowedException` (messaggio italiano pronto
  per la pagina). Stati inattesi (richiesta `shipped` senza righe su viaggi partiti) sono `LogicException` e
  fanno rollback. Un importo di 0,00 è **valido** (il prezzo 0 è ammesso da validazione e CHECK: campioni,
  omaggi): la richiesta si può segnare pagata con importo 0,00.

### Chiarimenti dopo la revisione (mie scelte, segnate come tali)

- **Arrotondamento**: `linesOfTrip` arrotonda per singolo bancale (`ROUND(ti.quantity * ol.unit_price, 2)`), e il
  DDT somma quelle righe. L'importo della richiesta somma le stesse righe arrotondate, quindi coincide con la
  somma dei suoi DDT anche se la richiesta viaggia su più viaggi (nessun doppio arrotondamento).
- **Date dei filtri**: Viaggi = `trips.planned_date`, e mostra tutti gli stati (anche pianificati e annullati; il
  "totale fatturato" c'è solo per `in_transit`/`completed`, negli altri è vuoto). Incassi = `orders.delivery_date`.
  Fornitori = `supplier_documents.received_date`, con la quantità letta dai movimenti `inbound` dei bancali di
  quei documenti: stessi numeri della pagina dell'arrivo.
- **Annullare** un pagamento o un compenso pagato è ammesso anche se il viaggio è `completed` (solo admin); i
  vincoli CHECK restano veri perché la richiesta resta `shipped`.
- **Operatore e compenso**: nella scheda Viaggi le colonne del compenso non esistono per lui (non celle
  vuote); nella scheda Documenti il tipo "compenso" non è tra i filtri e un `tipo=compensation` passato a mano
  viene ignorato (nessun errore che riveli che esiste).

### Schede di `/storico` (sola lettura, `HistoryRepository`)

Tutti i filtri sono parametri GET (la pagina funziona senza JS, come le schede delle impostazioni, WAI-ARIA).
Le liste sono limitate a 200 righe come `/stock`, con la scritta "mostrate le prime 200".

| Scheda | Righe | Filtri | Note |
|---|---|---|---|
| **Viaggi** | codice, giorno, giro, mezzo, trasportatore, stato, n. bancali, n. clienti, totale fatturato (somma DDT), compenso + pagato (solo admin) | periodo (giorno pianificato), giro, stato | link al viaggio |
| **Fornitori** | per fornitore: n. documenti, n. bancali, quantità fornita per prodotto, valore d'acquisto | periodo (data di ricevimento), fornitore | **la quantità fornita si legge dai movimenti `inbound`**, non da `pallets.quantity`: dopo la partenza `ship()` azzera la quantità del bancale, il movimento invece resta (è lo storico vero) |
| **Incassi** | per richiesta spedita: codice, cliente, giorno di consegna, importo, pagata sì/no, data; in testa il totale incassato e il totale ancora da incassare | periodo (consegna), cliente, stato pagamento (pagata / da pagare / parziale) | pulsante "Segna pagata"; admin: "Annulla pagamento". **Richieste parziali** (scelta del proprietario, 2026-11-13): le richieste con almeno un viaggio partito ma non ancora `shipped` compaiono con l'etichetta "parziale" e l'importo già spedito, senza pulsante di pagamento; il loro importo è in un totale a parte ("spedito, non ancora incassabile"), così i totali non nascondono merce già partita. Nessuna regola di pagamento nuova. |
| **Documenti** | codice, tipo, viaggio, cliente, data di emissione, link al PDF | periodo, tipo, cliente | i compensi compaiono solo agli admin (404 sul PDF agli altri, come già oggi) |

Dove si agisce: "Segna pagata" anche nel dettaglio della richiesta (`/orders/{id}`); "Segna concluso" e
"Compenso pagato" nel dettaglio del viaggio (`/trips/{id}`). La scheda Incassi permette di segnare pagata dalla
riga stessa (POST, con ritorno alla scheda con i filtri).

### Struttura (rispetta i livelli del progetto)

- `Repository\HistoryRepository` (solo SQL, sola lettura) + modelli piccoli per le righe dei report.
- `Service\HistoryService`: costruisce i filtri validati (date `AAAA-MM-GG`, id esistenti) e i totali con
  `Thousandths`.
- `Service\PaymentService` (pagamenti richiesta e compenso) e `Service\TripClosureService` (concludere), con la
  transazione; i metodi SQL puntuali stanno in `OrderRepository` / `TripRepository`.
- `Controller\HistoryController` (`index`) e metodi nuovi in `TripController` / `OrderController`.
- Rotte (`config/routes.php`): GET `/storico?scheda=...`, POST `/trips/{id}/conclude`, POST
  `/trips/{id}/compensation/pay` e `/unpay` (admin), POST `/orders/{id}/pay` e `/unpay` (admin).
- Menu: voce "Storico" dopo "Viaggi" (per tutti; i dati riservati agli admin li filtra il service, non il menu).
- Template: `templates/history/index.php` + un partial per scheda.
- Cablaggio esplicito in `Application::registerServices()`.

### Permessi

Storico e pagamento delle richieste: ogni utente collegato (come pianificare e far partire). Annullare un
pagamento, tutto ciò che riguarda il compenso e le colonne del compenso: solo admin, con 404 (non 403) per gli
altri, come per il PDF del compenso.

### Test

- Unit/integrazione sul database di test (transazioni annullate a fine test, come gli altri): concludere
  (stato, doppio click, viaggio non partito), pagare (importo uguale alla somma del DDT, richiesta non spedita,
  doppio click, annullamento solo admin), compenso (senza trasportatore/compenso, non admin), report (filtri,
  limite, quantità fornita da `inbound` anche dopo la partenza, compenso nascosto all'operatore).
- Un test che confronta l'importo della richiesta con la somma delle righe del DDT generato dalla Fase 14 (i due
  numeri non devono mai divergere).
- Prova HTTP su `logidock_test` (mai sul database di sviluppo: i movimenti non si cancellano): CSRF, operatore
  contro admin, 404 sul compenso, doppio click.
- Il numero dei test sale oltre 344; `CLAUDE.md`, README e sito si aggiornano.

### Seed, screenshot, documentazione

- Il seed conclude il viaggio demo già partito e segna pagata una richiesta demo, lasciandone una non pagata
  (così la scheda Incassi ha entrambi i casi). Passa dai service, come il resto del seed.
- Screenshot nuovi: `storico-viaggi`, `storico-fornitori`, `storico-incassi`, `storico-documenti`; rigenerati
  quelli toccati (menu, dettaglio viaggio, dettaglio richiesta). Sito e README aggiornati.
- Versione: `php bin/version.php phase 15` (0.0.15.0). Un commit, con `(closes #29)` alla fine.

## Assunzioni mie (da confermare, vanno in `DOMANDE.md`, sezione "Fase 15")

1. L'importo di una richiesta non è modificabile (se il cliente paga una cifra diversa non c'è un campo per dirlo).
2. Si può segnare pagata solo una richiesta `shipped` (non una parzialmente partita).
3. Il viaggio si conclude a mano, non da solo.
4. Il compenso si può segnare pagato anche prima che il viaggio sia concluso.
5. Annullare un pagamento: solo admin, senza storia nel database (solo nel log).
6. Liste a 200 righe, senza paginazione (la paginazione è la #43).
7. Nessuna esportazione CSV.
8. I totali di "Viaggi" e "Incassi" seguono la domanda #50 (bancale intero o quantità).

## Lasciato al costruttore

Nomi esatti di classi e metodi, forma dei modelli di riga, testi italiani dei messaggi, markup e CSS delle
schede (riusare quelle delle impostazioni), indici da aggiungere se una query dei report risulta lenta, aspetto
degli screenshot.
