# Fase 18, passo 5b - Paginazione delle liste (design breve)

Issue [#43](https://github.com/RikyM06/LogiDock/issues/43). Data: 2026-11-19. Stato: approvato il 2026-11-19 e realizzato (v0.0.18.6).
Versione: 0.0.18.6. Taglia: media (tocca molte liste, ma con una sola regola comune).

## Perché

Arrivi, richieste, viaggi, giacenze e storico tagliano la lista a 200 righe (l'utente non sa che ce ne sono altre);
prodotti e anagrafiche non hanno nessun limite. Quando i dati crescono le pagine diventano lunghe o incomplete.

## Decisioni del proprietario (2026-11-19)

1. **Quali liste:** tutte quelle che possono crescere: arrivi, richieste, viaggi, giacenze (elenco bancali),
   storico (le 4 schede), prodotti, fornitori, clienti. Le anagrafiche piccole (giri, tipi di bancale, tipi di
   rimorchio, posizioni, veicoli, trasportatori, utenti) restano intere.
2. **Come si sfoglia:** «Precedente / Successivo» con «Pagina X di Y» (link semplici, `?pagina=2`).

## Obiettivi

1. Una sola regola per tutte le liste: righe per pagina, calcolo delle pagine, controlli «Precedente/Successivo».
2. Funziona **senza JavaScript** (sono link) e **con i filtri**: cambiare pagina conserva ricerca e filtri; cambiare
   un filtro o cercare torna a pagina 1.
3. Righe per pagina = **impostazione modificabile dall'admin** (`list_page_size`, default 25, da 10 a 100), come ogni
   valore di business del progetto. Sostituisce i vecchi `LIST_LIMIT = 200` (spariscono).
4. Il totale è sempre visibile («37 risultati»): niente più liste tagliate in silenzio.
5. Accessibile: `<nav aria-label="Paginazione">`, pagina corrente con `aria-current="page"`, link disattivati resi come
   testo (non link morti).
6. Un valore sporco in `?pagina=` (testo, 0, negativo, oltre l'ultima pagina) non è mai un errore: si ripiega sulla
   pagina valida più vicina.

## Non obiettivi

- Ordinamento per colonna, scelta delle righe per pagina da parte dell'utente, scorrimento infinito, AJAX.
- Paginare le anagrafiche piccole.

## Anti-obiettivi

- Contare le righe con un'altra regola rispetto alla lista (totale diverso dalle righe mostrate).
- `OFFSET` con ordinamento non stabile (righe duplicate o saltate tra una pagina e l'altra): ogni lista ha già un
  ordine con `id` come ultimo criterio, va mantenuto.
- Concatenare il numero di pagina nell'SQL senza forzarlo a intero.

## Design

- `Core\Pagination` (puro, niente database): da `page` richiesta, `perPage` e `total` calcola `page` effettiva
  (limitata a 1..pagine), `pages`, `offset`, `limit`, `hasPrevious/hasNext`. Testi unitari con valori sporchi.
- Il controller legge `pagina` dalla richiesta; il **servizio** chiede il totale al repository, costruisce la
  `Pagination` e chiede le righe di quella pagina (nessuna regola nel controller, nessun SQL nel servizio). Le liste
  restituiscono un piccolo oggetto `Page` (righe + `Pagination`).
- Repository: ogni lista ha un metodo che conta con **gli stessi filtri** della lista (stessa condizione `WHERE`,
  scritta una volta sola) e la lista con `LIMIT ? OFFSET ?` (interi legati con `PDO::PARAM_INT`).
- Template: partial `templates/partials/pagination.php` (parametri: `pagination`, `baseUrl` con i filtri già
  presenti), richiamato sotto ogni lista paginata; `Core\HistoryUrl` è il modello per costruire gli indirizzi
  conservando i filtri.
- Storico: ogni scheda ha la sua paginazione; il totale dei soldi («incassato», «da incassare»…) resta calcolato su
  **tutte** le righe del filtro, non solo su quelle della pagina (da controllare caso per caso e coprire con un test).
- Impostazione: `list_page_size` in un nuovo gruppo `liste` («Liste») in `SettingService::GROUPS`, dopo `accesso`
  (la pagina impostazioni genera la scheda da sola).

## Test

- `PaginationTest` (unitario): pagina 0, negativa, testo, oltre l'ultima, zero risultati, totale esatto multiplo.
- Test di servizio/repository sul database di prova: totale uguale alle righe della lista senza limite; pagine
  consecutive non si sovrappongono e insieme danno tutte le righe (ordine stabile); filtro + pagina.
- Test del partial (come `BreadcrumbPartialTest`): link, pagina corrente, filtri conservati, stato disattivato.
- Prova HTTP nel database di prova (scratchpad) e nel browser; `CodingRulesTest` e PHPStan verdi.

## Documentazione e versione

README, sito (didascalia + screenshot: nuova impostazione e una lista con la barra di paginazione, con i dati demo
bastano poche righe: per vederla nello screenshot si usa un valore piccolo solo nello script), `CLAUDE.md`, post
LinkedIn non pubblicati (post 20 «cosa manca ancora»). `php bin/version.php bump` (0.0.18.6); commit
`(closes #43)`; il push lo decide il proprietario.

## Assunzioni mie (da confermare, vanno in `DOMANDE.md`)

1. Default 25 righe per pagina, limiti 10-100.
2. Parametro `?pagina=` (italiano come il resto dell'interfaccia).
3. L'impostazione sta in una scheda propria «Liste».
4. Le giacenze (elenco bancali) hanno il loro filtro e la loro paginazione; il riepilogo per prodotto resta intero.

## Lasciato al costruttore

Nomi esatti di classi e metodi, testo dei link, dettagli del CSS, ordine in cui convertire le liste (una per volta,
partendo da una semplice come i fornitori per fissare il modello).
