# Fase 17 - Briciole di pane: piano di implementazione

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** mostrare in cima a ogni pagina interna il percorso (Home › Richieste › ORD-…), con un partial `breadcrumb` e una riga in ogni template.

**Architecture:** un partial `templates/partials/breadcrumb.php` stampa il `<nav>`; ogni template di pagina lo richiama con la sua lista `crumbs`. Nessun controller, service o repository viene toccato (unica eccezione ammessa: vedi Task 3). Un test unit controlla il partial, un test di copertura controlla che ogni pagina lo usi.

**Tech Stack:** PHP 8.3, template PHP, CSS con variabili, PHPUnit, nessuna libreria nuova.

**Spec:** `docs/superpowers/specs/2026-11-14-fase-17-briciole-di-pane-design.md` (approvato il 2026-11-14). Chi esegue la legga prima.

## Global Constraints

- PHP 8.3, `declare(strict_types=1);` nei file PHP con classi/test, PSR-12, UTF-8 senza BOM nei template. File che finiscono con un solo LF.
- Etichette e commenti in **italiano**, codice (nomi di classi, test, variabili) in inglese.
- Ogni testo stampato passa da `e()`; il partial riceve testo semplice, non HTML.
- Colori solo con variabili CSS esistenti (mai colori fissi); contrasto WCAG AA (>= 4.5:1) verificato.
- La voce «Home» (link `/`) è sempre la prima e **non** si scrive nelle pagine. L'ultima voce non ha link e porta `aria-current="page"`. Il separatore «›» lo disegna il CSS (`::before`), non il markup.
- Il parametro del partial si chiama **`crumbs`** (non `items`: molte pagine hanno già `$items`). Formato: lista di `[etichetta]` o `[etichetta, url]`.
- Nessuna briciola in: `home.php`, `auth/*`, `errors/*`.
- Il menu in alto deve restare su una riga a 1280 e 1440 px (verifica con gli screenshot di headless Edge).
- Nessun commit finché il proprietario non lo chiede (regola del progetto: un commit per fase). Prima di rigenerare gli screenshot: `php bin/version.php phase 17`. Commit finale: `v0.0.17.0: ... (closes #31)`.
- PowerShell 5.1: niente `&&`; i `.ps1` si leggono in ANSI; file UTF-8 mai riscritti con `Get-Content` senza `-Encoding UTF8` (nelle console i caratteri accentati possono apparire rovinati anche se il file è corretto).

## Review Focus

1. **Etichetta con HTML** (`<script>` nel nome di un cliente o nel codice): escapata, mai interpretata. Test in Task 1.
2. **Pagina di modifica ri-mostrata dopo un errore di validazione**: le briciole restano giuste (il titolo viene dal controller, non dai valori del form). Controllo manuale in Task 3.
3. **Giorno non valido nel piano dei viaggi / scheda dello storico sconosciuta**: la pagina non va in errore per colpa delle briciole (si usa il giorno/la scheda già validati dal controller). Controllo in Task 2.
4. **Pagina nuova senza briciole**: il test di copertura fallisce. Test in Task 4.
5. **Menu in alto che va a capo** per colpa del nuovo markup sopra il titolo: controllo visivo in Task 4.

---

## Mappa dei file

| File | Responsabilità |
|---|---|
| `templates/partials/breadcrumb.php` (nuovo) | markup del percorso |
| `public/assets/css/app.css` (modifica) | stile `.breadcrumb` |
| `templates/**/*.php` (modifica, ~25 pagine) | una riga `$partial('breadcrumb', ...)` all'inizio |
| `templates/partials/list_header.php` (modifica) | briciole delle liste (anagrafiche e arrivi) |
| `tests/View/BreadcrumbPartialTest.php`, `tests/View/BreadcrumbCoverageTest.php` (nuovi) | test del partial e di copertura |
| `docs/tools/capture-screenshots.ps1`, `docs/index.html`, `README.md` (modifica) | screenshot e documentazione |

## Percorsi per pagina (dopo «Home ›»)

| Template | `crumbs` |
|---|---|
| `master-data` | `[['Anagrafiche']]` |
| liste anagrafiche (via `list_header`, `$path` in suppliers/customers/rounds/carriers/vehicles/trailer-types/locations/pallet-types) | `[['Anagrafiche','/master-data'], [plurale]]` |
| `*/form` delle 8 anagrafiche | `[['Anagrafiche','/master-data'], [plurale, $path], [$title]]` |
| `products/index` | `[['Prodotti']]` |
| `products/form` | `[['Prodotti','/products'], [$title]]` |
| `inbound/index` (via `list_header`, `$path` = `/inbound`) | `[['Arrivi']]` |
| `inbound/form` | `[['Arrivi','/inbound'], [$title]]` |
| `inbound/show` | `[['Arrivi','/inbound'], [$document->number]]` |
| `stock/index` | `[['Giacenze']]` |
| `orders/index` | `[['Richieste']]` |
| `orders/form` (nuova) | `[['Richieste','/orders'], [$title]]` |
| `orders/form` (modifica) | `[['Richieste','/orders'], [codice,'/orders/{id}'], ['Modifica']]` (il codice e l'id vengono da ciò che il controller già passa; se il form non li ha, il costruttore decide la via più piccola e la annota) |
| `orders/show` | `[['Richieste','/orders'], [$order->code]]` |
| `trips/index` | `[['Viaggi']]` |
| `trips/plan` | `[['Viaggi','/trips'], ['Piano del ' . $day]]` |
| `trips/show` | `[['Viaggi','/trips'], [$trip->code]]` |
| `history/index` | `[['Storico','/storico'], [nome della scheda attiva]]` |
| `users/index` | `[['Utenti']]` |
| `users/form` | `[['Utenti','/users'], [$title]]` |
| `users/password` | `[['Utenti','/users'], [$title]]` |
| `settings/form` | `[['Impostazioni']]` |
| `account/password` | `[['La mia password']]` |

Il plurale dell'anagrafica ('Clienti', 'Fornitori', …) esiste già: `master-data.php` ha la tabella `$sections` e i controller passano `labels` alle liste. Per i form, il costruttore verifica se `labels` arriva già; se non arriva, l'unica modifica ammessa a un controller è passarlo dalla classe base `MasterDataController` (un solo punto), mai da ogni controller.

---

### Task 1: Il partial, lo stile e il suo test

**Files:**
- Create: `templates/partials/breadcrumb.php`, `tests/View/BreadcrumbPartialTest.php`
- Modify: `public/assets/css/app.css`

**Interfaces:**
- Produces: `$partial('breadcrumb', ['crumbs' => list<array{0: string, 1?: string}>]): string`. Output: `<nav class="breadcrumb" aria-label="Briciole di pane"><ol>` con `<li>` per Home (link `/`, testo «Home») e per ogni voce; le voci intermedie con URL sono `<a href>`, l'ultima è `<span aria-current="page">`. Se `crumbs` è vuoto (o assente) non stampa nulla (stringa vuota, nemmeno il `<nav>`).

- [ ] **Step 1: Write the failing test** `BreadcrumbPartialTest` (PHPUnit, senza database; usa `LogiDock\Core\View` con la cartella `templates` reale e `render('partials/breadcrumb', ['crumbs' => ...], null)`):
  - `testHomeIsAlwaysTheFirstItemAndLinksToTheRoot` (`href="/"`, testo «Home»);
  - `testIntermediateItemsWithAnUrlAreLinks` e `testTheLastItemIsNotALinkAndIsTheCurrentPage` (`aria-current="page"`, nessun `<a>` per l'ultima voce);
  - `testAnItemWithoutAnUrlIsPlainText` (voce intermedia senza URL);
  - `testLabelsAndUrlsAreEscaped` (`<script>alert(1)</script>` e `"` nelle etichette e negli URL non compaiono grezzi; Review Focus 1);
  - `testAnEmptyListPrintsNothing`.
- [ ] **Step 2: Run** `composer test -- --filter BreadcrumbPartialTest` → FAIL (partial inesistente).
- [ ] **Step 3: Implement** il partial (scritto con i soli template-tag e `e()`, nessuna logica oltre al ciclo) e lo stile `.breadcrumb` in `app.css`: lista orizzontale senza puntini, testo piccolo (`font-size` come le altre note, variabile colore «muted» già esistente), link con colore e sottolineatura al passaggio come gli altri link, separatore `li + li::before { content: "›" }` con `aria-hidden` non necessario perché è CSS, `flex-wrap: wrap`, spazio sotto prima del titolo. Verifica del contrasto del colore scelto sul fondo crema (>= 4.5:1) e annotazione del rapporto nel report.
- [ ] **Step 4: Run** il test del partial → PASS; poi `composer test` completo (390 test erano verdi).

### Task 2: Pagine «singole» (prodotti, arrivi, richieste, viaggi, giacenze, storico, utenti, impostazioni, account, hub anagrafiche)

**Files:**
- Modify: `templates/products/{index,form}.php`, `templates/inbound/{form,show}.php` (la lista `inbound/index` passa da `list_header`, Task 3), `templates/stock/index.php`, `templates/orders/{index,form,show}.php`, `templates/trips/{index,plan,show}.php`, `templates/history/index.php`, `templates/users/{index,form,password}.php`, `templates/settings/form.php`, `templates/account/password.php`, `templates/master-data.php`

**Interfaces:**
- Consumes: il partial `breadcrumb` di Task 1 (`crumbs`).
- Produces: la riga `<?= $partial('breadcrumb', ['crumbs' => [...]]) ?>` come **prima cosa** dentro `<main>` di ciascuna pagina, prima di `page-header` / `card`, con i valori della tabella «Percorsi per pagina».

- [ ] **Step 1:** Aggiungere la riga a ogni template elencato, con le etichette della tabella. Dove il template non ha già la variabile che serve (per esempio il codice della richiesta nel form di modifica) usare solo ciò che il controller già passa (vedi nota nella tabella); non toccare i controller.
- [ ] **Step 2: Verifica**: per ogni pagina elencata caricarla (avviare `php -S localhost:8011 -t public` con `$env:DB_NAME='logidock_test'`, con un utente di prova creato con `bin/create-user.php` e una password usa-e-getta, solo nel database di test) oppure, per le pagine con dati, renderizzare il template in un piccolo script PHP; controllare che la riga compaia, che i link vadano a pagine esistenti e che il piano con giorno non valido e la scheda dello storico sconosciuta (Review Focus 3) si aprano ancora senza errore. Fermare il server alla fine.
- [ ] **Step 3: Run** `composer test` → verdi.

### Task 3: Anagrafiche e liste con `list_header`

**Files:**
- Modify: `templates/partials/list_header.php`, `templates/{suppliers,customers,rounds,carriers,vehicles,trailer-types,locations,pallet-types}/form.php`; eventualmente `src/Controller/MasterDataController.php` (solo se `labels` non arriva ai form, vedi nota sopra)

**Interfaces:**
- Consumes: partial `breadcrumb`; variabili già presenti in `list_header` (`$title`, `$path`, `$labels`, `$canManage`, `$search`).
- Produces: `list_header.php` stampa la riga prima del `page-header`: se `$path === '/inbound'` → `[['Arrivi']]`; altrimenti (anagrafiche) → `[['Anagrafiche','/master-data'], [$labels['plural']]]`. Le 8 `form.php` stampano `[['Anagrafiche','/master-data'], [plurale, $path], [$title]]`.

- [ ] **Step 1:** Implementare `list_header` e i form come sopra. Il plurale per i form viene da `labels['plural']` se disponibile; altrimenti applicare l'unica eccezione ammessa (passare `labels` dalla classe base `MasterDataController`) e annotarla nel report.
- [ ] **Step 2: Verifica** come in Task 2 sulle 8 anagrafiche (lista, nuovo, modifica) e su Arrivi; in particolare un form di modifica con errore di validazione (campo obbligatorio vuoto) deve mostrare le briciole giuste (Review Focus 2).
- [ ] **Step 3: Run** `composer test` → verdi.

### Task 4: Test di copertura, screenshot, documentazione

**Files:**
- Create: `tests/View/BreadcrumbCoverageTest.php`
- Modify: `docs/tools/capture-screenshots.ps1` (solo se serve), `docs/index.html`, `README.md`, `VERSION` (via script), `CLAUDE.md` e `DOMANDE.md` (non tracciati)

- [ ] **Step 1: Write the failing test** `BreadcrumbCoverageTest::testEveryPageTemplateShowsTheBreadcrumb`: percorre `templates/**/*.php`, esclude `layout.php`, `home.php`, `auth/*`, `errors/*`, `partials/*` (tranne la verifica che `partials/list_header.php` contenga la chiamata) e i partial delle schede dello storico (`history/_*.php`); ogni altro file deve contenere `partial('breadcrumb'` oppure `partial('list_header'`. Il messaggio di errore elenca i template senza briciole. Eseguirlo **prima** di finire Task 2-3 deve fallire con l'elenco giusto (verifica che il test non sia tautologico: togliere a mano una riga da una pagina e vedere il test rosso, poi rimetterla).
- [ ] **Step 2:** `php bin/version.php phase 17` (-> 0.0.17.0). Rigenerare tutti gli screenshot con `docs/tools/capture-screenshots.ps1` contro il server di sviluppo (porta 8000, in sola lettura sul database di sviluppo; la password admin la legge lo script da `.env`, mai stamparla). Guardare con Read almeno `home.png`, `richiesta-dettaglio.png`, `viaggio-dettaglio.png`, `clienti.png`, `storico-incassi.png`: briciole visibili e leggibili, menu su UNA riga, nessun dato personale. Se il menu va a capo, riferirlo (non ridisegnarlo: è la #51).
- [ ] **Step 3: Documentazione.** `docs/index.html`: card «Briciole di pane», numero di test aggiornato, roadmap Fase 17 «Fatta» (e Fase 16 «Saltata per ora»); `README.md`: stato, sezione breve, test, roadmap. `CLAUDE.md` (inglese, non tracciato): stato Fase 17, decisioni (parametro `crumbs`, il test di copertura), commit «v0.0.17.0: ... (closes #31)» NON ancora fatto. `DOMANDE.md`: le 3 assunzioni del design (Home come testo, percorso strutturale, livello dettaglio solo se esiste).
- [ ] **Step 4: Verifica finale:** `composer test` (riportare il numero), `git status` (nessun file spurio; `docs/images/` resta fuori e non si tocca).
- [ ] **Step 5:** NON committare: il commit lo fa il proprietario quando lo chiede.

---

## Autoverifica del piano

- **Copertura della spec:** partial + accessibilità + CSS → Task 1; ~25 pagine → Task 2-3 (tabella); test unit e di copertura → Task 1 e 4; menu su una riga, screenshot, sito, README, versione, `(closes #31)` → Task 4; assunzioni → Task 4 Step 3.
- **Coerenza:** il parametro è `crumbs` ovunque (spec aggiornata); `list_header` serve sia le anagrafiche sia gli arrivi, quindi decide per `$path`.
- **Proporzioni:** nessun corpo di codice scritto; firme, valori e test sono fissati.
