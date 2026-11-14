# Fase 17 - Briciole di pane (design breve)

Issue [#31](https://github.com/RikyM06/LogiDock/issues/31). Data: 2026-11-14. Stato: da approvare.
Taglia: piccola (un partial, una riga per pagina, un po' di CSS, un test).

## Perché

Il proprietario voleva la «navigazione a lisca di pesce» e il 14 novembre 2026 ha chiarito che intende le **briciole
di pane**: un percorso in cima a ogni pagina (Home › Richieste › ORD-2026-000004 › Modifica) per sapere dove si è e
risalire di livello con un click. Oggi le pagine di dettaglio e di modifica si lasciano solo con il menu in alto o con
il tasto indietro del browser.

## Obiettivi

1. Ogni pagina interna mostra il suo percorso, sopra il titolo, con link a ogni livello tranne l'ultimo.
2. Accessibile: `<nav aria-label="Briciole di pane">` con lista ordinata, `aria-current="page"` sull'ultima voce.
3. Un solo punto per il markup e per lo stile; ogni pagina dichiara solo le sue voci.
4. Nessun cambio ai controller, ai service o ai repository.

## Non obiettivi

- Link tra record collegati (cliente → richieste → bancali): è stato escluso dal proprietario.
- Risolvere il problema della barra del menu ([#51](https://github.com/RikyM06/LogiDock/issues/51)).
- Briciole nella home, nel login e nelle pagine di errore.
- Ricerca globale (Fase 16, saltata).

## Anti-obiettivi

- Un'etichetta o un link sbagliato (un percorso che porta a una pagina che non esiste o che l'utente non può aprire).
- Un testo dinamico (codice del viaggio, numero del documento) stampato senza `e()`.
- Il menu in alto che va a capo o si sposta a causa delle briciole.

## Approcci

**A. I controller passano la lista.** Un solo punto per il markup (il layout), ma bisogna toccare una dozzina di
controller per un'etichetta, e il controller deve conoscere testi che il template ha già (`$trip->code`).

**B. Ogni template dichiara le sue voci con un partial `breadcrumb`** (scelta del proprietario, 2026-11-14). Una
riga per pagina, le etichette sono già disponibili nel template, markup in un solo file, nessun controller toccato.

**C. Ricavare il percorso dall'URL** (scartata): `/trips/6` darebbe «6» e non il codice del viaggio.

## Design

### Il partial

`templates/partials/breadcrumb.php`, usato così all'inizio di ogni pagina, prima del `page-header`:

```php
<?= $partial('breadcrumb', ['crumbs' => [['Richieste', '/orders'], [$order->code]]]) ?>
```

- `crumbs` è una lista di `[etichetta, url?]` (non `items`: molte pagine hanno già una variabile `$items`). La voce **Home** (link a `/`) è sempre la prima e non si scrive.
- L'ultima voce non ha il link e porta `aria-current="page"`; se una voce intermedia non ha l'URL resta testo.
- Etichetta e URL passano da `e()` dentro il partial: la pagina passa testo semplice.
- Il separatore «›» lo disegna il CSS (`::before`), così non finisce nel testo letto dallo screen reader.
- Lista `items` vuota: il partial non stampa niente.

### Stile

Classe `.breadcrumb` in `public/assets/css/app.css`: riga piccola e discreta sopra il titolo, solo variabili CSS
esistenti (mai colori fissi), contrasto WCAG AA verificato, può andare a capo sugli schermi stretti.

### Le pagine

| Pagina | Percorso (dopo «Home ›») |
|---|---|
| Anagrafiche (hub) | Anagrafiche |
| Lista di un'anagrafica (fornitori, clienti, giri, trasportatori, mezzi, tipi di rimorchio, posizioni, tipi di bancale) | Anagrafiche › Clienti |
| Nuovo / modifica di un'anagrafica | Anagrafiche › Clienti › Nuovo cliente / Modifica «nome» |
| Prodotti: lista, nuovo, modifica | Prodotti › (Nuovo prodotto / Modifica «codice») |
| Arrivi: lista, nuovo, dettaglio | Arrivi › (Nuovo arrivo / «numero documento») |
| Giacenze | Giacenze |
| Richieste: lista, nuova, dettaglio, modifica | Richieste › (Nuova richiesta / ORD-… / ORD-… › Modifica) |
| Viaggi: lista, piano del giorno, dettaglio | Viaggi › (Piano del gg/mm/aaaa / VIA-…) |
| Storico (le quattro schede) | Storico › nome della scheda attiva |
| Utenti (admin): lista, nuovo, modifica, password | Utenti › (Nuovo utente / Modifica «nome» / Password di «nome») |
| Impostazioni (admin) | Impostazioni |
| La mia password | La mia password |
| Home, login, errori | nessuna briciola |

Le liste delle anagrafiche passano da `partials/list_header.php`: la riga si può mettere lì, una volta per tutte,
invece che in ogni `index.php` (decisione del costruttore).

### Test

- Test unit del partial (renderizzato con `View`, senza layout): voci con e senza link, `aria-current` sull'ultima,
  escape (`<script>` nell'etichetta), lista vuota, Home sempre prima.
- Test di copertura: ogni template di pagina sotto `templates/` (esclusi `partials/`, `layout`, `home`, `auth/`,
  `errors/`, i partial `_*.php` delle schede) contiene la chiamata al partial. Così una pagina nuova senza briciole
  fa fallire i test.
- Controllo visivo: menu in alto ancora su una riga a 1280 e 1440 px (headless Edge, come in Fase 15) e briciole
  leggibili in 3-4 pagine.

### Documentazione e versione

`php bin/version.php phase 17` (0.0.17.0). Screenshot rigenerati tutti (le briciole compaiono in ogni pagina) con
`docs/tools/capture-screenshots.ps1`; sito (`docs/index.html`) e README aggiornati (card «Briciole di pane», roadmap
Fase 17 «Fatta», numero di test). Un commit, con `(closes #31)`. Nessun push senza richiesta.

## Assunzioni mie (da confermare, vanno in `DOMANDE.md`)

1. «Home» come testo, non come icona (più chiaro e senza immagini da gestire).
2. Il percorso segue la struttura del menu, non la cronologia di navigazione dell'utente.
3. Nelle pagine di modifica il livello «dettaglio» c'è solo se esiste (richieste sì, anagrafiche no).

## Lasciato al costruttore

Testi esatti delle etichette, dove mettere la chiamata per le liste (`list_header.php` o ogni `index.php`),
dimensioni e spaziatura nel CSS, nomi dei test.
