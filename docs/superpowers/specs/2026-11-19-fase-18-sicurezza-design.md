# Fase 18, passo 4 - Sicurezza (design breve)

Issue [#42](https://github.com/RikyM06/LogiDock/issues/42) e [#47](https://github.com/RikyM06/LogiDock/issues/47).
Data: 2026-11-19. Stato: approvato il 2026-11-19 e realizzato (v0.0.18.4). Taglia: media (una tabella, tre classi, due impostazioni, un lock).
Versione: 0.0.18.4.

## Perché

- **#42:** oggi chiunque sulla rete locale può provare password all'infinito: i tentativi falliti finiscono solo nel
  log. L'app gira in rete locale, ma una password debole o un PC infetto bastano per un attacco a dizionario.
- **#47:** la issue elenca due punti deboli quando più persone lavorano insieme. Guardando il codice:
  1. *Capacità delle posizioni:* il conteggio dei bancali è nella stessa transazione dell'inserimento, ma senza
     bloccare niente: due arrivi simultanei nella stessa posizione possono superarla di poco. **Resta da fare.**
  2. *Modifica di una richiesta senza lock sulla riga:* **è già risolto dalla Fase 13**: `OrderService::update()` e
     `cancel()` bloccano la riga (`lockOpenOrFail()` con `FOR UPDATE`) e ricontrollano lo stato prima di scrivere.
     Lo si annota nella issue, non serve altro codice.

## Obiettivi

1. **Blocco temporaneo del login.** Dopo troppi tentativi falliti il login viene rifiutato per un po', con un
   messaggio chiaro («Troppi tentativi. Riprova tra N minuti.»).
2. Soglia e durata sono **impostazioni modificabili da un admin** (regola del progetto: i valori di business non stanno
   nel codice).
3. **Capacità delle posizioni a prova di arrivi simultanei:** le posizioni coinvolte si bloccano (`FOR UPDATE`, in ordine
   di id) prima di contare, così due arrivi nella stessa posizione si mettono in fila.

## Non obiettivi

- Pulsante «sblocca» per l'admin: basta aspettare, e non c'è nessun account da recuperare (si può sempre aspettare).
- CAPTCHA, verifica in due passaggi, notifiche per e-mail.
- Bloccare per sempre un account o una persona.
- Modificare il comportamento di `OrderService` (già a posto).

## Anti-obiettivi

- Un blocco che rivela quali e-mail esistono: il conteggio e il messaggio devono valere **anche per e-mail
  sconosciute**, nello stesso modo.
- Un blocco che chiude fuori l'amministratore per sempre (chi sbaglia 5 volte aspetta 15 minuti, poi riprova).
- Rallentare il login normale con query pesanti, o riempire il database di tentativi vecchi.
- Un lock sulle posizioni che causa un deadlock con altre operazioni. Le posizioni si bloccano qui sempre in ordine di
  id; resta un caso raro scoperto in revisione: anche una partenza prende da sola un blocco leggero sulle posizioni
  (per la chiave esterna dei movimenti) e può incrociarsi con un arrivo su più posizioni. MySQL ferma uno dei due
  (errore 1213) e l'utente ripete; è annotato nel README.

## Approcci considerati

1. **Contatore nel database** (scelto): tabella `login_attempts`. Sopravvive ai riavvii e vale per tutte le sessioni
   e per tutti i processi PHP-FPM; un attaccante non lo aggira buttando il cookie.
2. **Contatore in sessione:** inutile, chi attacca non rimanda il cookie di sessione.
3. **Contatore in un file o in APCu:** non condiviso tra processi in modo affidabile, da ripulire a mano; scartato.
4. **Ritardo crescente invece del blocco:** più complicato da spiegare e da provare; scartato.

## Design

### Regola del blocco

- Si contano i tentativi **falliti** negli ultimi `login_lockout_minutes` minuti (default **15**).
- Login rifiutato se i fallimenti per **la stessa e-mail** (in minuscolo, anche inesistente) sono almeno
  `login_max_attempts` (default **5**), **oppure** quelli dallo **stesso indirizzo IP** sono almeno 4 volte tanto
  (default **20**: protegge dall'attacco che prova molte e-mail dallo stesso PC senza bloccare un utente onesto che
  condivide l'IP con qualcuno).
- Il blocco finisce da solo: quando il tentativo più vecchio della finestra esce dai 15 minuti. Il messaggio dice quanti
  minuti restano (arrotondati per eccesso).
- Un login riuscito cancella i fallimenti di quell'e-mail.
- I tentativi più vecchi della finestra si cancellano mentre se ne registra uno nuovo (la tabella non cresce).
- Durante il blocco non si verifica nemmeno la password (si risparmia la CPU e non si dà un'altra occasione).
- Ogni blocco scatta una riga di log (`Logger`): e-mail, IP, minuti.

### Dati (migrazione 0029)

`login_attempts`: `id`, `email VARCHAR(255)`, `ip VARCHAR(45)`, `attempted_at DATETIME` (UTC), indici su
`(email, attempted_at)` e `(ip, attempted_at)`. Il database dell'app (`logidock_app`) ha i permessi di
lettura/inserimento/cancellazione come sulle altre tabelle normali (lo script dei permessi lo fa già per ogni tabella
nuova: `deploy.sh` lo rilancia).

### Codice

- `Repository\LoginAttemptRepository` (solo SQL): `record(email, ip, nowUtc)`, `recentFailuresForEmail(email, sinceUtc):
  list<string>` (le date, per calcolare i minuti rimasti), `recentFailuresForIp(ip, sinceUtc)`, `clearEmail(email)`,
  `purgeBefore(sinceUtc)`.
- `Service\LoginThrottle`: `assertAllowed(email, ip, ?now)` lancia `TooManyLoginAttemptsException` (con i secondi
  rimasti); `recordFailure(...)`; `recordSuccess(email)`. Legge le due impostazioni da `SettingService`.
- `Service\AuthService::attempt()` usa `LoginThrottle` (prima `assertAllowed`, poi `recordFailure` o `recordSuccess`).
- `Controller\AuthController::login()` risponde 429 con il messaggio dell'eccezione.
- `SettingService::definitions()`: due impostazioni nuove nel gruppo «Accesso e sicurezza»:
  `login_max_attempts` (intero 3-20, default 5) e `login_lockout_minutes` (intero 1-1440, default 15).
- `WarehouseLocationRepository::lockForUpdate(array $ids): void` (`SELECT id ... WHERE id IN (...) ORDER BY id
  FOR UPDATE`), chiamato da `InboundService::assertLocationsHaveRoom()` prima di contare; il commento che oggi parla
  della «piccola finestra» si aggiorna.

### Test

- `LoginThrottleTest` (integrazione, con `$now` iniettato): 4 fallimenti non bloccano, il 5° sì; vale anche per
  un'e-mail inesistente; e-mail in maiuscolo e minuscolo contano insieme; il blocco finisce dopo 15 minuti; i minuti
  rimasti sono giusti; un login riuscito azzera il conteggio; il limite per IP (20) blocca anche con e-mail diverse e
  non blocca un altro IP; i tentativi vecchi vengono cancellati; le impostazioni cambiano la soglia.
- `AuthServiceTest` (o equivalente): `attempt()` lancia l'eccezione quando bloccato e non chiama `password_verify`
  (non si può misurare: si verifica che l'utente giusto con password giusta resti rifiutato durante il blocco).
- **Prova di concorrenza vera** (`InboundCapacityLockTest`): due connessioni MySQL separate; la prima blocca la posizione,
  la seconda con un tempo di attesa di 1 secondo prova a bloccarla e deve fallire con «Lock wait timeout»; dopo il
  commit della prima riesce. La posizione di prova si crea fuori dalla transazione del test e si cancella alla fine.
- Prova HTTP su `logidock_test` (scratchpad): 5 login sbagliati → 429 con il messaggio; il sesto con la password giusta è
  rifiutato; dopo aver «spostato indietro» i tentativi nel database, il login riesce.
- `CodingRulesTest` e PHPStan restano verdi.

### Documentazione e versione

README (sezione Sicurezza), sito (card «Sicurezza»), schermata impostazioni rigenerata (nuovo gruppo), `CLAUDE.md`,
`DOMANDE.md`, post LinkedIn non pubblicati (05 sicurezza, 20 «cosa manca ancora»). Commento sulla #47 che spiega che il
secondo punto era già risolto. `php bin/version.php bump` (0.0.18.4); un commit con `(closes #42, closes #47)`; il push
lo decide il proprietario.

## Assunzioni mie (da confermare, vanno in `DOMANDE.md`)

1. Soglia di 5 tentativi falliti e blocco di 15 minuti (modificabili dall'admin); il limite per IP è 4 volte la soglia.
2. Si conta per e-mail anche se non esiste, e il messaggio di blocco è lo stesso per tutti.
3. Nessun pulsante per sbloccare: si aspetta la fine del blocco.
4. Il secondo punto della #47 è già risolto dalla Fase 13.

## Lasciato al costruttore

Nomi esatti, testo preciso dei messaggi, dove mostrare le impostazioni nella pagina, eventuale pulizia dei tentativi
con una query sola.
