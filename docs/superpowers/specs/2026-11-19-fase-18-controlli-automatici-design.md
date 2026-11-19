# Fase 18, passo 2 - Controlli automatici (design breve)

Issue [#32](https://github.com/RikyM06/LogiDock/issues/32). Data: 2026-11-19. Stato: approvato il 2026-11-19 e realizzato (v0.0.18.1).
Taglia: piccola/media (un workflow, un file di configurazione, un test di regole, ritocchi al codice che l'analisi
farà emergere). Versione: 0.0.18.1.

## Perché

Oggi i 399 test girano solo se qualcuno si ricorda di lanciarli sul proprio PC. Il proprietario ha chiesto (2026-11-16)
«una verifica automatica dei test a ogni push e un controllo di errori e stile sul codice». In questa fase sono già
passati inosservati a una revisione umana difetti che una macchina trova da sola: file senza riga finale a capo,
un test che dipendeva da un'estensione PHP non dichiarata.

## Obiettivi

1. **GitHub Actions**: a ogni push e a ogni pull request su `main` parte un workflow che installa le dipendenze, crea
   un MySQL di prova, applica le migrazioni e lancia `composer test`. Se qualcosa si rompe, si vede in rosso.
2. **PHPStan** (analisi statica: trova chiamate a metodi che non esistono, tipi sbagliati, variabili non definite,
   senza eseguire il codice) su `src/` e `tests/`, lanciabile in locale con `composer analyse` e dal workflow.
3. **Regole di progetto come test** (`tests/Quality/CodingRulesTest.php`, senza dipendenze), solo quelle che le
   convenzioni del progetto già impongono e che ci sono sfuggite davvero:
   - ogni file PHP di `src/`, `tests/`, `config/`, `bin/` ha `declare(strict_types=1);`;
   - ogni file di testo del progetto (PHP, SQL, MD, CSS, JS, template) finisce con una sola riga a capo;
   - niente `var_dump(`, `print_r(`, `dd(` dimenticati in `src/`, `config/`, `templates/`;
   - le migrazioni sono numerate in modo consecutivo, senza buchi né numeri doppi (`0001_...` fino all'ultima);
   - ogni variabile letta con `env('...')` in `config/app.php` compare in `.env.example` (regola già scritta in
     `CLAUDE.md`: «tieni `.env.example` allineato a `config/app.php`»).
4. **Un comando solo**: `composer check` esegue analisi e test, nello stesso ordine del workflow.

## Non obiettivi

- Deploy automatico (CD): il server si aggiorna con `deploy.sh`, a mano.
- Copertura dei test (serve Xdebug o PCOV, e un numero in sé non dice se i test sono buoni).
- PHP_CodeSniffer / PHP-CS-Fixer: il proprietario ha scelto «PHPStan + regole mie» (2026-11-19).
- Controlli su JavaScript e CSS.

## Anti-obiettivi

- Un workflow sempre rosso o inaffidabile (test che passano solo sul PC del proprietario): peggio di nessun workflow.
- Centinaia di segnalazioni di PHPStan silenziate in un «baseline» senza leggerle.
- Un'altra dipendenza aggiunta senza permesso.

## Vincoli

- **Dipendenza nuova approvata dal proprietario il 2026-11-19: `phpstan/phpstan`**, solo sviluppo (`require-dev`),
  licenza MIT (compatibile con la doppia licenza del progetto). Nessun'altra. Va aggiornato l'elenco dei pacchetti
  ammessi in `CLAUDE.md`.
- PHP 8.3, MySQL 8; il workflow deve rispecchiare la versione di produzione (PHP 8.3).
- Il test di regole non deve toccare il database e deve restare veloce.
- Codice, nomi e test in inglese; commenti, messaggi di errore dei test e documentazione in italiano.

## Approcci considerati

1. **PHPStan + regole mie** (scelto dal proprietario): una dipendenza di sviluppo; trova gli errori veri; lo stile
   lo controllano pochi test su misura. Rischio: PHPStan può segnalare molte cose la prima volta.
2. **PHPStan + PHP_CodeSniffer**: due dipendenze; lo stile PSR-12 completo, ma rumoroso (molte correzioni iniziali).
3. **Solo Actions, nessuna dipendenza**: niente pacchetti nuovi, ma non trova errori di tipo.

## Design

### Workflow (`.github/workflows/tests.yml`)

- Si avvia su `push` e `pull_request` per `main`.
- Un solo job su `ubuntu-24.04` (come il server), con un servizio MySQL 8 (`mysql:8.0`, con controllo di salute).
- Passi: checkout, PHP 8.3 con le estensioni usate dal progetto (`pdo_mysql`, `intl`, `mbstring`, `zip`; se il PHP
  già presente sul runner non basta si usa l'azione `shivammathur/setup-php`), `composer install`, abilitazione dei
  database `logidock_test` creato dal servizio MySQL (utente root, che ha il permesso SUPER: i trigger della migrazione
  0009 si creano senza `log_bin_trust_function_creators`, scoperta durante la realizzazione),
  `php bin/migrate.php` con le variabili d'ambiente del database di prova, `composer analyse`, `composer test`.
- Nessun segreto: la password del MySQL di prova è un valore usa-e-getta scritto nel workflow (il servizio vive solo
  per la durata del job).
- **Non si può eseguire in locale** (niente Docker sul PC del proprietario): il primo vero collaudo è il primo push.
  Prima si controlla la sintassi YAML; dopo il push si guarda l'esecuzione (`gh run watch`) e si corregge subito.

### PHPStan

- `phpstan.neon` con i percorsi `src` e `tests`; livello iniziale scelto dal costruttore (si parte da 6 e si sale se
  costa poco), senza baseline salvo motivi scritti nel file. Le segnalazioni vere si correggono nel codice.
- Script Composer: `analyse` (`phpstan analyse`) e `check` (analisi + test).

### Test delle regole

- Una classe `CodingRulesTest` con un metodo per regola; ogni errore elenca i file colpevoli e come sistemarli
  (messaggi in italiano). Si prova che il test non è tautologico rompendo a mano una regola per volta (poi si ripristina).

### Documentazione, versione, LinkedIn

- README (sezione Test: i due comandi nuovi e cosa fa il workflow), sito (`docs/index.html`, sezione Test e numero dei
  test), `CLAUDE.md` (pacchetti ammessi, comandi). Un badge «tests» nel README solo dopo il primo workflow verde.
- `php bin/version.php bump` (0.0.18.1), un commit; il push lo decide il proprietario.
- Aggiornare i post LinkedIn non ancora pubblicati (07 test, 19 qualità, 20 «cosa manca») e rigenerare l'immagine del
  post 07 se la sezione Test del sito cambia.

## Assunzioni mie (da confermare, vanno in `DOMANDE.md`)

1. Il workflow gira solo su `main` e sulle pull request, non su ogni ramo.
2. PHPStan parte dal livello 6 e non usa baseline.
3. Il test di regole copre solo le cinque regole elencate; altre si aggiungono quando una sfugge davvero.

## Lasciato al costruttore

Livello esatto di PHPStan, nomi dei passi del workflow, formato dei messaggi dei test, come si legge l'elenco di
variabili `env()` da `config/app.php`, eventuale uso di `setup-php`.
