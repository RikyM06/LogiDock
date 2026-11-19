# Fase 18, passo 3 - Controlli sui dati (design breve)

Issue [#44](https://github.com/RikyM06/LogiDock/issues/44) e [#45](https://github.com/RikyM06/LogiDock/issues/45).
Data: 2026-11-19. Stato: approvato il 2026-11-19 e realizzato (v0.0.18.3). Taglia: piccola. Versione: 0.0.18.3.

## Perché

- **#44:** la partita IVA e il telefono dei fornitori sono obbligatori ma si accetta qualunque testo («x», «boh»).
  La partita IVA finisce stampata sui DDT: un valore sbagliato si vede sul documento.
- **#45:** nel cliente è obbligatoria solo la ragione sociale. Ma un DDT per un cliente senza indirizzo o senza
  partita IVA esce con un destinatario incompleto.

## Decisione del proprietario (2026-11-19)

Per i clienti diventano obbligatori **ragione sociale, partita IVA / codice fiscale e indirizzo** (i campi che stanno
sul DDT). **E-mail e telefono restano facoltativi** (un negozio piccolo può non avere l'e-mail). Il giro resta
facoltativo finché l'azienda non risponde alla [#34](https://github.com/RikyM06/LogiDock/issues/34).

## Obiettivi

1. Un controllo **leggero** sul formato di partita IVA / codice fiscale e telefono, scritto una volta sola nel
   `Validator` e usato da fornitori, clienti e trasportatori.
2. Clienti: tax code e indirizzo obbligatori, con l'asterisco rosso nel form (le due cose devono coincidere, regola
   del progetto).
3. I record già esistenti non si toccano: la regola vale al salvataggio, come per i fornitori («alla prima modifica il
   form chiede di completare»).

## Non obiettivi

- Il calcolo della cifra di controllo della partita IVA italiana o la verifica contro registri (VIES): la partita IVA
  può essere estera, e un controllo completo è un'altra storia.
- Cambiare altre anagrafiche (giri, mezzi, posizioni...) o l'e-mail (già controllata).
- Il campo «giro» del cliente.

## Anti-obiettivi

- Rifiutare una partita IVA estera valida o un telefono scritto con spazi e trattini, perché il controllo è troppo
  severo.
- Impedire di salvare un record vecchio incompleto per un campo che l'utente non stava modificando (non succede: il
  form lo mostra e lo richiede, come per i fornitori).
- Una regola scritta in due posti (service e template) che prima o poi si separano.

## Approcci considerati

1. **Controllo leggero nel `Validator`** (scelto): solo caratteri ammessi e lunghezza. Semplice, si spiega in una riga,
   non rifiuta casi legittimi.
2. **Cifra di controllo della P.IVA italiana**: più rigoroso ma sbaglia per le partite IVA estere e per i codici
   fiscali; scartato.
3. **Un controllo diverso per ogni anagrafica**: ripete la stessa regola tre volte; scartato.

## Design

### Regole (mie scelte, da confermare)

- **Partita IVA / codice fiscale:** si tolgono gli spazi, si mette in maiuscolo, si accettano solo lettere e numeri,
  da **8 a 16** caratteri (la partita IVA italiana ha 11 cifre, 13 con «IT»; il codice fiscale 16; le europee sono
  più corte di 16). Si salva il valore normalizzato (`IT00000000101`).
- **Telefono:** si accettano cifre, spazi e i simboli `+ - . / ( )`, con il `+` solo all'inizio; devono esserci da
  **6 a 15 cifre** (15 è il massimo dei numeri internazionali). Si salva come scritto, senza spazi in fondo.
- Messaggi in italiano, sul campo giusto: «Partita IVA / codice fiscale: usa solo lettere e numeri (da 8 a 16
  caratteri).» e «Telefono: usa solo numeri, spazi e i simboli + - . / ( ) (da 6 a 15 cifre).».

### Codice

- `Core\Validator`: quattro metodi nuovi, `requiredTaxCode`, `optionalTaxCode`, `requiredPhone`, `optionalPhone`.
- `SupplierService`: tax code e telefono passano ai metodi nuovi (restano obbligatori).
- `CustomerService`: `tax_code` e `address` obbligatori (`requiredTaxCode`, `requiredString`), `phone` facoltativo
  con controllo (`optionalPhone`), e-mail invariata.
- `CarrierService`: se presenti, tax code e telefono sono controllati (restano facoltativi).
- Template: nel form del cliente asterisco rosso e `required` su partita IVA e indirizzo (la stessa lista del service).
- Seed: i clienti di esempio ricevono partita IVA e indirizzo (valori finti, come per i fornitori: `.test`/`IT000...`)
  e il seed continua a non sovrascrivere dati esistenti. Nessun dato del database di sviluppo viene cancellato.

### Test

- `ValidatorTest`: valori buoni e cattivi per partita IVA (spazi, minuscole, simboli, troppo corta/lunga, estera) e
  telefono (prefisso +, trattini, parentesi, troppe poche cifre, lettere), compresi input ostili (array, apici, tag).
- `MasterDataServicesTest`: fornitore con partita IVA «x» rifiutato; cliente senza partita IVA o senza indirizzo
  rifiutato, con e-mail e telefono vuoti accettato; trasportatore senza telefono accettato, con telefono «abc»
  rifiutato; valore normalizzato salvato.
- Il test delle regole di progetto (`CodingRulesTest`) e PHPStan restano verdi.

### Documentazione e versione

README e sito (card Anagrafiche: campi obbligatori e formato), screenshot del form cliente con gli errori
(`cliente-obbligatori.png`, come `fornitore-obbligatori.png`) e rigenerazione di quelli toccati, `CLAUDE.md`,
`DOMANDE.md` (assunzioni), post LinkedIn non pubblicati (post 19 e 20: «qualche controllo in più sui dati» ora c'è).
`php bin/version.php bump` (0.0.18.3), un commit con `(closes #44, closes #45)`; il push lo decide il proprietario.

## Assunzioni mie (da confermare, vanno in `DOMANDE.md`)

1. Partita IVA / codice fiscale da 8 a 16 caratteri, solo lettere e numeri, salvata in maiuscolo e senza spazi.
2. Telefono da 6 a 15 cifre, con spazi e `+ - . / ( )`, salvato come scritto.
3. Il controllo vale anche per clienti e trasportatori (stessi campi), non solo per i fornitori.
4. Clienti già presenti senza partita IVA o senza indirizzo: nessuna correzione automatica, si completano alla prima
   modifica.

## Lasciato al costruttore

Nomi esatti dei metodi, testi dei messaggi, dove mettere i dati finti dei clienti nel seed, ritocchi ai template.
