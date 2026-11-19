# Fase 18, passo 2 - Controlli automatici: piano di implementazione

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** far girare in automatico test, analisi statica e regole di progetto a ogni push, e poterli lanciare in locale con un comando.

**Architecture:** un test di regole senza dipendenze (`tests/Quality/CodingRulesTest.php`), PHPStan come unica dipendenza di sviluppo nuova, un workflow GitHub Actions con un servizio MySQL. Nessun codice dell'applicazione cambia, salvo le correzioni che l'analisi farà emergere.

**Tech Stack:** PHP 8.3, PHPUnit 11, PHPStan (MIT), GitHub Actions, MySQL 8.

**Spec:** `docs/superpowers/specs/2026-11-19-fase-18-controlli-automatici-design.md` (approvato il 2026-11-19).

## Global Constraints

- Unica dipendenza nuova ammessa: `phpstan/phpstan` in `require-dev` (approvata dal proprietario il 2026-11-19, licenza MIT da verificare nel `composer.lock` dopo l'installazione). Nessun altro pacchetto.
- PHP 8.3 e MySQL 8, come in produzione. `declare(strict_types=1);` e tipi ovunque, PSR-12.
- Codice, nomi e test in **inglese**; commenti, messaggi dei test e documentazione in **italiano**.
- Il test di regole non tocca il database ed è veloce.
- Un solo commit finale (`php bin/version.php bump` prima: 0.0.18.1); nessun push senza richiesta del proprietario.
- Il workflow non si può eseguire in locale: si controlla la sintassi e si guarda la prima esecuzione dopo il push.
- PowerShell 5.1: niente `&&`; file UTF-8 senza BOM; non riscrivere file con `Get-Content` senza `-Encoding UTF8`.

## Review Focus

1. **Test di regole tautologico** (passa anche se la regola è violata): si rompe a mano una regola alla volta e si vede il test rosso (Task 1).
2. **File escluso per sbaglio dalla scansione** (nuova cartella non vista): un test controlla che la scansione veda un campione di file noti (Task 1).
3. **Workflow rosso solo su GitHub** (estensione PHP mancante, trigger MySQL): si legge la prima esecuzione e si corregge (Task 3).
4. **PHPStan che «passa» perché non guarda niente** (percorsi sbagliati nel `phpstan.neon`): si controlla che analizzi i file attesi e che un errore piantato apposta venga trovato (Task 2).
5. **Messaggi di errore inutili** (elenco senza i file colpevoli): ogni regola elenca i file e dice come sistemare (Task 1).

---

### Task 1: Regole di progetto come test

**Files:**
- Create: `tests/Quality/CodingRulesTest.php`
- Modify: i file che violano le regole (si scoprono col test)

**Interfaces:**
- Produces: `LogiDock\Tests\Quality\CodingRulesTest` con un metodo per regola: `testEveryPhpFileDeclaresStrictTypes`, `testEveryTextFileEndsWithASingleNewline`, `testNoDebugCallsAreLeftBehind`, `testMigrationsAreNumberedWithoutGaps`, `testEveryEnvVariableOfTheConfigIsInEnvExample`, `testTheScanSeesTheRealFiles`.

- [ ] **Step 1: Write the failing tests.** Un metodo per regola, ognuno elenca i file colpevoli nel messaggio:
  - `strict_types`: ogni `.php` di `src/`, `tests/`, `config/`, `bin/`, `database/` (esclusi `templates/` e `vendor/`) contiene `declare(strict_types=1);`.
  - riga finale: ogni file con estensione `php`, `sql`, `md`, `css`, `js`, `html`, `ps1`, `sh`, `yml`, `neon` (fuori da `vendor/`, `.git/`, `.phpunit.cache/`, `LinkedIn/`, `docs/images/`) finisce con **un solo** `\n`, né zero né due (nessun `\n\n` finale).
  - debug: in `src/`, `config/`, `templates/` non compaiono `var_dump(`, `print_r(`, `dd(`.
  - migrazioni: i nomi in `database/migrations/` sono `NNNN_snake_case.sql`, i numeri partono da `0001` e sono consecutivi e senza doppioni.
  - `.env.example`: ogni chiave letta con `env('NOME'` in `config/app.php` compare come `NOME=` in `.env.example`.
  - campione: la scansione trova `src/Core/Router.php`, `config/app.php`, `database/migrations/0001_create_users.sql`, `templates/layout.php`, `README.md` e più di 100 file in totale.
- [ ] **Step 2: Run** `composer test -- --filter CodingRulesTest`. Expected: il campione passa; le altre regole possono fallire elencando i file da sistemare (es. file senza riga finale): è l'esito atteso.
- [ ] **Step 3: Fix** tutte le violazioni che il test elenca (mai indebolire la regola per far passare il test; se una regola è sbagliata si ragiona e si annota).
- [ ] **Step 4: Prova di non-tautologia.** Per ogni regola: rompila a mano in un file (togli `declare`, togli la riga finale, aggiungi `var_dump(1);`, rinomina una migrazione con un buco, togli una chiave da `.env.example`), vedi il test **rosso con il file giusto nel messaggio**, ripristina a mano (i file nuovi non si ripristinano con `git checkout`). Annota l'esito nel registro.
- [ ] **Step 5: Run** `composer test`. Expected: tutti verdi (399 + i nuovi), output pulito.

### Task 2: PHPStan

**Files:**
- Create: `phpstan.neon`
- Modify: `composer.json`, `composer.lock`, il codice che PHPStan segnala

**Interfaces:**
- Produces: script Composer `analyse` (= `phpstan analyse --memory-limit=512M`) e `check` (= `@analyse` poi `@test`).

- [ ] **Step 1:** `composer require --dev phpstan/phpstan` (nessun'altra dipendenza). Verifica la licenza nel `composer.lock` (deve essere `MIT`): se non lo è, ferma tutto e chiedi.
- [ ] **Step 2:** `phpstan.neon` con `paths: [src, tests]`, livello **6**, senza baseline. Aggiungi gli script `analyse` e `check` a `composer.json`.
- [ ] **Step 3: Run** `composer analyse`. Leggi **ogni** segnalazione: se è un errore vero, correggi il codice (con un test se cambia il comportamento); se è un falso positivo, scegli la correzione più piccola (un `@var` o un tipo più preciso, mai `ignoreErrors` generico). Obiettivo: zero errori, senza baseline.
- [ ] **Step 4: Prova che guarda davvero.** Controlla che `composer analyse` indichi di aver analizzato i file attesi (numero di file > 100); pianta un errore (es. chiama un metodo inesistente in un file di `src/`), vedi PHPStan rosso, ripristina.
- [ ] **Step 5: Run** `composer check`. Expected: analisi e test verdi.

### Task 3: Workflow GitHub Actions, documentazione, versione

**Files:**
- Create: `.github/workflows/tests.yml`
- Modify: `README.md`, `docs/index.html`, `CLAUDE.md` (non tracciato), `DOMANDE.md` (non tracciato), post LinkedIn non pubblicati, `VERSION` (script)

- [ ] **Step 1: Workflow** `tests.yml`: su `push` e `pull_request` per `main`; un job `tests` su `ubuntu-24.04`; servizio `mysql:8.0` (utente root con password usa-e-getta, controllo di salute con `mysqladmin ping`); passi: `actions/checkout`, PHP 8.3 con `pdo_mysql`, `intl`, `mbstring`, `zip` (usa `shivammathur/setup-php` solo se il PHP del runner non ha le estensioni), `composer install`, `SET GLOBAL log_bin_trust_function_creators=1` e creazione del database `logidock_test` con il client `mysql`, `php bin/migrate.php` con le variabili `DB_HOST/DB_PORT/DB_NAME/DB_USER/DB_PASSWORD`, `composer analyse`, `composer test`. Nessun segreto reale.
- [ ] **Step 2: Verifica locale.** Controlla la sintassi YAML (ad esempio con PowerShell e un parser disponibile, oppure leggendola riga per riga e con `php -r` + `symfony/yaml` se presente in vendor, altrimenti verifica a occhio l'indentazione) e confronta i nomi delle variabili con `config/app.php`.
- [ ] **Step 3: Documentazione.** README: sezione Test con i nuovi comandi (`composer analyse`, `composer check`) e cosa fa il workflow; `docs/index.html`: sezione Test (numero dei test, riga sulla verifica automatica e sull'analisi); `CLAUDE.md`: pacchetti ammessi (+ `phpstan/phpstan`, dev, approvato 2026-11-19), comandi e stato; `DOMANDE.md`: le 3 assunzioni del design. Aggiorna i post LinkedIn non pubblicati (07 numero di test e analisi statica, 19, 20) e rigenera `LinkedIn/immagini/07-sito-test.png` se la sezione Test del sito è cambiata.
- [ ] **Step 4: Verifica finale.** `composer check` verde; `git status` senza file spurii (`docs/images/` resta fuori).
- [ ] **Step 5: Commit.** `php bin/version.php bump` (0.0.18.1), poi `git add -A -- . ':!docs/images'` e il messaggio `v0.0.18.1: controlli automatici (GitHub Actions, PHPStan, regole di progetto come test)`. Il push lo decide il proprietario: dopo il push si guarda la prima esecuzione con `gh run watch` e si corregge quello che serve.

---

## Autoverifica del piano

- **Copertura della spec:** workflow → Task 3; PHPStan e `composer analyse`/`check` → Task 2; le cinque regole → Task 1; documentazione, versione e LinkedIn → Task 3; vincoli (dipendenza, licenza MIT) → Task 2 Step 1.
- **Review Focus:** punti 1, 2 e 5 → Task 1 (Step 1 e 4); punto 4 → Task 2 Step 4; punto 3 → dopo il push (Task 3 Step 5).
- **Proporzioni:** nessun corpo scritto; firme, regole e comandi sono fissati.
