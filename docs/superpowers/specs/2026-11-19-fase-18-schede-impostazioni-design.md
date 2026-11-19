# Fase 18, passo 5a - Schede nelle impostazioni (design breve)

Issue [#49](https://github.com/RikyM06/LogiDock/issues/49) (la issue contiene già i requisiti e i criteri «fatto
quando»: questo documento fissa le scelte tecniche). Data: 2026-11-19. Stato: approvato il 2026-11-19 e realizzato (v0.0.18.5). Taglia: piccola/media.
Versione: 0.0.18.5.

## Ordine del passo 5

Il passo 5 («comodità d'uso») ha tre lavori indipendenti, ognuno con il suo design e il suo commit:

1. **#49 schede nelle impostazioni** (questo documento): v0.0.18.5.
2. **#43 paginazione delle liste:** v0.0.18.6.
3. **#46 correzione di un arrivo già registrato:** v0.0.18.7. È il più delicato (i movimenti non si cancellano):
   avrà un design a parte, con domande al proprietario.

## Perché

La pagina `/settings` ha 11 impostazioni in tre riquadri uno sotto l'altro (con il gruppo «Accesso e sicurezza» sono
già lunghi: serve scorrere per arrivare ai dati dell'azienda). Crescerà ancora. Le schede mostrano un gruppo alla volta.

## Obiettivi (dalla #49)

1. Le impostazioni sono divise in **schede**, generate dai gruppi dell'elenco `SettingService::definitions()` (resta
   l'unica fonte: aggiungere un'impostazione o un gruppo non richiede di toccare il template).
2. Accessibili col pattern WAI-ARIA *Tabs*: `role="tablist"` / `tab` / `tabpanel`, `aria-selected`, `aria-controls`,
   frecce sinistra e destra (più Home e Fine) da tastiera, un solo `tab` raggiungibile con Tab.
3. **Senza JavaScript la pagina resta usabile:** tutti i riquadri visibili uno sotto l'altro, come oggi, e il form si salva.
4. Un solo form e un solo pulsante «Salva impostazioni» che salva tutte le schede (come oggi).
5. **Errori di validazione:** si apre la scheda con il primo errore, e le schede con errori sono segnalate in modo
   visibile e anche per gli screen reader.
6. **La scheda attiva resta nell'indirizzo** (`/settings#accesso`): ricaricando o dopo il salvataggio si torna sulla
   stessa scheda.
7. Solo variabili CSS già esistenti, contrasto WCAG AA della scheda attiva verificato.

## Non obiettivi

- Salvare ogni scheda a parte (un solo pulsante per tutto, scelta della #49).
- Librerie o framework JavaScript (vanilla, come il resto).
- Cambiare quali impostazioni esistono o i loro limiti.

## Anti-obiettivi

- Con JavaScript spento (o rotto) le impostazioni non si vedono o non si possono salvare.
- Un errore di validazione in una scheda nascosta che l'utente non vede: «non salva» senza dire perché.
- Gli identificativi delle schede che cambiano quando si ritocca un'etichetta (rompe i link salvati).

## Design

### Gruppi con identificativo stabile

Oggi `SettingDefinition::$group` è l'etichetta («Magazzino e bancali»). Passa a essere un **identificativo stabile**
(`magazzino`, `accesso`, `azienda`) e le etichette stanno in un solo elenco, `SettingService::GROUPS`
(`id => etichetta`), nello stesso ordine in cui le schede compaiono. `SettingService::groups()` restituisce, in
quell'ordine, `id`, `label` e le definizioni di ogni gruppo (solo i gruppi che hanno impostazioni). Il controller passa
questo elenco al template (oggi passa un array `etichetta => definizioni`).

### Markup

- Un contenitore `data-tabs` con: un `<div role="tablist" aria-label="Gruppi di impostazioni" hidden>` con un
  `<button role="tab" id="tab-ID" aria-controls="panel-ID" aria-selected=... tabindex=...>` per gruppo, poi un
  `<div role="tabpanel" id="panel-ID" aria-labelledby="tab-ID">` per gruppo, con il riquadro di oggi dentro.
- Il `tablist` è `hidden` nel markup: lo mostra il JavaScript. Senza JS si vedono solo i riquadri, impilati.
- Una scheda con errori ha la classe `has-error` e, dentro il pulsante, un testo solo per screen reader
  «(contiene errori)» più un puntino rosso visibile; il server lo calcola da `$errors` (nessuna logica nel JS).
- Ogni pannello conserva il suo titolo `<h2>` (con JS lo si può nascondere visivamente perché è già nella scheda).

### JavaScript (in `public/assets/js/app.js`, vanilla)

- All'avvio, se c'è `[data-tabs]`: mostra il `tablist`, nasconde i pannelli non attivi (`hidden`), imposta
  `aria-selected`/`tabindex`.
- Scheda iniziale: la prima con `has-error` (l'utente deve vedere l'errore, anche se l'indirizzo punta a un'altra
  scheda); altrimenti quella dell'indirizzo (`#accesso`) se esiste; altrimenti la prima.
- Clic o tasti freccia/Home/Fine cambiano scheda, spostano il fuoco e aggiornano `location.hash` (con
  `history.replaceState`, senza riempire la cronologia) **e l'attributo `action` del form** (`/settings#id`): dopo
  il salvataggio il browser eredita il frammento dal reindirizzamento e si torna sulla stessa scheda.

### Stile

Si riusano `.tabs` e `.tabs__link` già scritti per lo Storico (stesso aspetto, coerente), con la regola
`[aria-selected="true"]` accanto a `[aria-current="page"]`, un po' di spazio sotto e il puntino degli errori
(`--color-danger`). Contrasto della scheda attiva controllato.

### Test

- `SettingServiceTest`: ogni impostazione appartiene a un gruppo che esiste in `GROUPS`; gli identificativi dei gruppi
  sono unici e formati da lettere minuscole e trattini; `groups()` rispetta l'ordine e non ha gruppi vuoti.
- Prova HTTP sul database di test (scratchpad): la pagina contiene tablist, tab e tabpanel con id coerenti;
  con un valore sbagliato (422) la scheda giusta ha `has-error` e il testo «contiene errori»; un valore giusto salva e
  torna a `/settings`.
- Prova nel browser con il browser integrato: clic, frecce, Home/Fine, indirizzo con `#azienda`, errore che apre la
  scheda giusta, salvataggio che resta sulla scheda, JavaScript disattivato (tutti i riquadri visibili).
- `CodingRulesTest` e PHPStan restano verdi.

### Documentazione e versione

README (sezione Impostazioni), sito (didascalia della figura delle impostazioni e screenshot rigenerati: la pagina ora
mostra le schede), `CLAUDE.md`, post LinkedIn non pubblicati se citano le impostazioni. `php bin/version.php bump`
(0.0.18.5); un commit con `(closes #49)`; il push lo decide il proprietario.

## Assunzioni mie (da confermare, vanno in `DOMANDE.md`)

1. Le tre schede, nell'ordine: Magazzino e bancali, Accesso e sicurezza, Dati dell'azienda sui documenti.
2. L'identificativo del gruppo sta nel codice (`SettingService::GROUPS`) e non nel database.
3. Nessuna scheda con tutti i pannelli ricordata nel database: la scheda attiva sta solo nell'indirizzo.

## Lasciato al costruttore

Nomi esatti, testo dei messaggi per gli screen reader, dettagli del CSS, come gestire il fuoco dopo un errore.
