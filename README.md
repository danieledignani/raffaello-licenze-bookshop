# Raffaello Licenze — Bookshop

Plugin WordPress per **raffaellobookshop.it**. Chi compra sul Bookshop un prodotto di accesso a
Raffaello Scuola (chiave con prefisso `RSCU`) se lo trova attivo su raffaelloscuola.it, sul proprio
account Raffaello Identity. Il Bookshop resta l'autorità sulle licenze (License Manager for
WooCommerce 3.0.20, versione gratuita): il plugin le manda a Raffaello Scuola, che le riceve con il
plugin **Raffaello Licenze** (1.5.0 per gli invii, 1.5.1 o successiva per la prova di connessione).
Raffaello Scuola non chiama mai l'API di License Manager.

Prende il posto dello snippet `tools/bookshop/raffaello-scuola-licenze.php` del repo
raffaello-codici-libro: stessa logica e stesse meta d'ordine, più impostazioni, prova di
connessione, checklist di configurazione, tentativi anche sugli errori di configurazione e guardia
sullo snippet GreenBubble.

- [Come funziona](#come-funziona)
- [Requisiti](#requisiti)
- [Installazione](#installazione)
- [Configurazione del collegamento](#configurazione-del-collegamento)
- [Prova connessione](#prova-connessione)
- [Checklist, avviso e Salute del sito](#checklist-avviso-e-salute-del-sito)
- [License Manager: generatore, prodotti, consegna](#license-manager-generatore-prodotti-consegna)
- [Snippet GreenBubble: escludere le RSCU](#snippet-greenbubble-escludere-le-rscu)
- [Cloudflare e Wordfence su Raffaello Scuola](#cloudflare-e-wordfence-su-raffaello-scuola)
- [Go-live in produzione](#go-live-in-produzione)
- [Assistenza](#assistenza)
- [Limiti noti](#limiti-noti)
- [Rilascio](#rilascio)

## Come funziona

1. License Manager assegna le licenze quando l'ordine entra in uno degli stati di consegna e lancia
   `lmfwc_event_post_order_license_keys` una volta per ogni riga con licenza, ogni volta con tutte le
   licenze assegnate fin lì.
2. Il plugin tiene solo le chiavi che iniziano con `RSCU` e **fotografa** nella meta d'ordine
   `_rscu_licenze` quelle nuove: id della licenza, chiave, riga d'ordine, SKU e nome del prodotto,
   scadenza. Nella meta `_rscu_cliente` fotografa una volta sola il `sub` di Identity del cliente
   (user meta `ri_oidc_sub`, `null` se manca) e l'email di fatturazione. La scadenza è quella di
   License Manager; se manca, la data di pagamento più i giorni di validità; altrimenti `null`
   (illimitata). La fotografia non cambia più, anche se poi cambiano prodotto o cliente.
3. Accoda con Action Scheduler (gruppo `raffaello-scuola`, azione `rscu_invia_licenze`) un invio per
   ordine ed evento, un minuto dopo. Il job manda il JSON a
   `URL di Scuola/wp-json/raffaello-licenze/v1/eventi`, a lotti di al massimo 50 licenze e 64 KB,
   firmando ogni lotto con HMAC-SHA256 (`X-Raffaello-Timestamp`, `X-Raffaello-Signature`).
4. È riuscito solo se ogni lotto riceve un 200 con JSON `ok: true` e un esito per ogni sua licenza.
   **Si ritenta** su errori di rete (DNS, TLS, timeout), 401 (segreto o orologio), 403 (anche la
   challenge di Cloudflare), 404, reindirizzamenti, 429, 5xx e risposte inattese (una pagina HTML
   di manutenzione o di cache con 200, un JSON senza tutti gli esiti): 5 tentativi in tutto, a
   1 min, 5 min, 30 min, 2 h e 6 h l'uno dall'altro (circa 8 h 36 min). Al go-live questi sono
   errori di configurazione: corretti, gli invii ripartono da soli. Definitivo resta il 400 (Scuola
   rifiuta il contenuto).
5. **Senza configurazione** (URL o segreto mancanti o non validi) l'invio aspetta e si riprova ogni
   ora, senza consumare tentativi; la nota d'ordine lo dice una volta sola.
6. Rimborso totale (stato "Rimborsato") o annullamento: accoda l'evento `licenze_revocate` con tutte
   le licenze RSCU dell'ordine. La revoca si registra anche se License Manager non ha ancora
   assegnato le licenze: quelle assegnate dopo si revocano subito dopo l'assegnazione. Su Raffaello
   Scuola la revoca è definitiva. Rimborso parziale: nessuna revoca automatica, solo una nota.
7. Ogni ordine ha un **esito leggibile** e una nota d'ordine privata (vedi [Assistenza](#assistenza)).

Esiti per licenza restituiti da Raffaello Scuola: `attivata`, `codice_libero` (quantità maggiore di
1: il primo codice di ogni licenza va all'acquirente, gli altri restano codici liberi da regalare),
`senza_destinatario`, `in_attesa_configurazione`, `revocata`, `conflitto`.

## Requisiti

- WordPress 5.8 o successivo, PHP 7.4 o successivo.
- WooCommerce (con Action Scheduler, incluso) e License Manager for WooCommerce. Il plugin è provato
  con License Manager **3.0.20**: con un'altra versione funziona, ma la checklist chiede di provare
  un ordine. Senza WooCommerce il plugin non fa niente; senza License Manager non arrivano licenze
  nuove (gli invii già in coda partono lo stesso). La checklist lo dice.
- Raffaello Identity attivo sul Bookshop: senza il `sub` del cliente le licenze arrivano "senza
  destinatario".
- Su Raffaello Scuola: Raffaello Licenze 1.5.1 o successiva (1.5.0 riceve gli invii ma non conosce
  la prova di connessione).
- Compatibile con l'archivio ordini HPOS di WooCommerce (usa solo le API CRUD degli ordini).

## Installazione

### Dallo zip della release

1. Da GitHub, release più recente di `danieledignani/raffaello-licenze-bookshop`, scarica
   `raffaello-licenze-bookshop.zip`. Non rinominarlo: WordPress usa il nome dello zip come nome della
   cartella del plugin.
2. Bacheca > Plugin > Aggiungi nuovo > Carica plugin, scegli lo zip, Installa, Attiva.
3. Gli aggiornamenti successivi arrivano da soli in Bacheca > Aggiornamenti, come per gli altri
   plugin Raffaello (il plugin legge
   `.github/update-metadata/raffaello-licenze-bookshop.json` del repo).

La pagina è **WooCommerce > Raffaello Scuola** (serve `manage_options`).

### Se c'è ancora lo snippet

Lo snippet "Raffaello Scuola: licenze RSCU" (Code Snippets) o il file
`raffaello-scuola-licenze.php` in `wp-content/mu-plugins/` va **disattivato**. Finché è caricato il
plugin resta fermo, per non registrare due volte gli stessi ordini, e mostra un avviso bloccante
"disattivalo"; la checklist lo segnala anche quando lo snippet è attivo in Code Snippets.

L'ordine giusto: installa e configura il plugin, poi disattiva lo snippet. Meta d'ordine
(`_rscu_licenze`, `_rscu_cliente`, `_rscu_tentativi`, `_rscu_revocato`), azione e gruppo di Action
Scheduler sono gli stessi: gli ordini già fotografati restano validi e gli invii già in coda li
esegue il plugin. Gli ordini falliti al tempo dello snippet non hanno l'esito nuovo e non compaiono
fra gli invii falliti: per quelli si usa "Reinvia licenze a Raffaello Scuola" sull'ordine.

## Configurazione del collegamento

In **WooCommerce > Raffaello Scuola > Collegamento**:

- **URL di Raffaello Scuola**: solo schema e host, per esempio `https://www.raffaelloscuola.it`
  (sul dev `https://dev.raffaelloscuola.it`). Deve essere https, senza percorso, parametri o
  credenziali, e diverso dall'indirizzo del Bookshop. Usa l'indirizzo finale: il plugin non segue i
  reindirizzamenti (una POST reindirizzata diventerebbe una GET).
- **Segreto condiviso**: si genera su Raffaello Scuola (Licenze > Impostazioni > Genera segreto,
  mostrato una volta sola) e si incolla qui. Almeno 32 caratteri, senza spazi. Il campo non
  ristampa mai il segreto: lasciato vuoto tiene quello salvato; per toglierlo c'è la casella
  "Cancella il segreto salvato". Dev e produzione hanno segreti diversi.

I valori stanno nell'opzione `rlb_impostazioni` (autoload no), legata all'host del sito che l'ha
salvata: se il database viene copiato su un altro ambiente (per esempio la produzione sul dev) il
collegamento vale come **non configurato** e la checklist lo dice, così un Bookshop di prova non
scrive su Scuola di produzione. Salvando lì, il segreto dell'altro sito non si conserva.

### In alternativa, wp-config.php

```php
define('RAFFAELLO_SCUOLA_URL', 'https://www.raffaelloscuola.it');
define('RAFFAELLO_SCUOLA_SECRET', '...il segreto generato su Scuola...');
```

Le costanti vincono sulle impostazioni e rendono il campo di sola lettura ("Gestito da
wp-config.php"). Una costante definita ma vuota rende la configurazione non valida, senza ripiego
sulle impostazioni.

Se in `wp-config.php` c'è `WP_HTTP_BLOCK_EXTERNAL`, l'host di Raffaello Scuola va aggiunto a
`WP_ACCESSIBLE_HOSTS`, altrimenti WordPress blocca gli invii (la checklist lo segnala, B4).

### Cambiare il segreto

Non c'è un doppio segreto: si genera il nuovo su Scuola, si incolla sul Bookshop, si preme "Prova
connessione". Gli invii che nel frattempo ricevono 401 si ritentano con il segreto attuale e
ripartono da soli.

## Prova connessione

Il pulsante manda a Raffaello Scuola un evento `ping` firmato, dallo stesso server, con lo stesso
client e sulla stessa rotta degli invii veri, con gli SKU dei prodotti RSCU pubblicati (al massimo
500). Non tocca licenze né attivazioni su Scuola: lì si registra solo l'ultima richiesta ricevuta.
Data, destinazione ed esito restano salvati e valgono solo per
URL e segreto attuali: se cambiano, la prova va ripetuta.

| Esito | Che cosa vuol dire | Che cosa fare |
|---|---|---|
| Riuscita | Scuola risponde con la sua versione e gli SKU che non conosce | Se ci sono SKU sconosciuti, creare su Scuola le licenze con lo stesso SKU |
| DNS, TLS, timeout, rete | Il server del Bookshop non raggiunge Scuola | Controllare URL, certificato, firewall dell'hosting |
| Blocco di WordPress | `WP_HTTP_BLOCK_EXTERNAL` senza l'host di Scuola | `WP_ACCESSIBLE_HOSTS` |
| 401 | Segreto diverso sui due siti, o orologi sfasati di più di 5 minuti | Incollare di nuovo il segreto; controllare l'ora dei server |
| Challenge di Cloudflare | 403 o 503 con `cf-mitigated: challenge`, o una pagina HTML di Cloudflare | Vedi [Cloudflare](#cloudflare-e-wordfence-su-raffaello-scuola) |
| 403 | Un altro filtro prima di WordPress (Wordfence, hosting) | Vedi [Wordfence](#wordfence) |
| 404 | URL sbagliato, plugin non attivo su Scuola o REST chiusa | Controllare l'URL e il plugin su Scuola |
| Reindirizzamento | Scuola risponde 301/302 | Usare l'indirizzo finale |
| 503 Scuola non configurata | Scuola non ha il segreto | Licenze > Impostazioni su Scuola |
| Firma accettata ma ping sconosciuto | URL e segreto giusti, Scuola è la 1.5.0 | Aggiornare Raffaello Licenze su Scuola |

## Checklist, avviso e Salute del sito

La pagina del plugin mostra la checklist completa. Gli stessi controlli alimentano:

- un **avviso** in Bacheca e nelle pagine di WooCommerce (non nella pagina del plugin, che ha già
  la checklist), solo per i problemi **bloccanti**, senza pulsante di chiusura: sparisce da solo
  quando il problema è risolto;
- un test in **Strumenti > Salute del sito** ("Licenze RSCU verso Raffaello Scuola"), che legge lo
  stato senza chiamate HTTP.

Gravità: **bloccante** (rosso), **attenzione** (giallo), **informativo**. "Non verificabile" non è
mai verde.

| | Controllo | Bloccante se |
|---|---|---|
| D1 | WooCommerce e License Manager | uno dei due manca |
| D2 | Vecchio snippet | è caricato o attivo in Code Snippets |
| B1 | URL di Scuola | manca, non è valido, la costante è vuota, o le impostazioni sono di un altro host |
| B2 | Segreto | manca, è corto, la costante è vuota, o è di un altro host |
| B3 | Collegamento | l'ultima prova o l'ultimo invio con la configurazione attuale è fallito (attenzione se mai provato, o se Scuola ha accettato la firma: ordine occupato, 400, Scuola senza ping) |
| B4 | Chiamate HTTP in uscita | WordPress blocca l'host di Scuola |
| B5 | Action Scheduler | ci sono invii scaduti da oltre 30 minuti e non eseguiti (WP-Cron fermo); attenzione se gira più di un batch insieme |
| B6 | Invii falliti | c'è almeno un ordine fallito (con i link, ultimi 50, e "Rimetti in coda") |
| B7 | Versione di License Manager | (attenzione se diversa dalla 3.0.20) |
| B8 | Consegna delle licenze | né "In lavorazione" né "Completato" hanno "Send" (attenzione se ne manca uno o la consegna automatica è spenta) |
| B9 | Generatore RSCU | non c'è un generatore con prefisso `RSCU` (attenzione se il prefisso non è `RSCU-` o le attivazioni non sono 1) |
| B10 | Prodotti RSCU | (attenzione: nessun prodotto, o non virtuale, senza SKU, venduto singolarmente, "Sell license keys" o "Generate license keys" spenti, quantità consegnata maggiore di 1) |
| B11 | SKU su Scuola | l'ultima prova riuscita ha SKU sconosciuti a Scuola |
| B12 | Identity e acquisti da ospite | Identity non attivo o acquisto da ospite permesso |
| B13 | GreenBubble esclude le RSCU | una licenza RSCU è finita nelle meta di GreenBubble (attenzione: snippet che manda licenze altrove, non ancora confermato) |
| B14 | Ordini da sistemare | (attenzione, con i link e i motivi) |

## License Manager: generatore, prodotti, consegna

### Generatore

License Manager > Generatori > Aggiungi:

- Character map: `ABCDEFGHLMNPQRSTUVZ123456789`
- Number of chunks: `2`, Chunk length: `4`, Separator: `-`
- Prefix: `RSCU-` (License Manager non mette il separatore dopo il prefisso: il trattino va nel
  prefisso). Le chiavi risultano come `RSCU-AB12-CD34`.
- Maximum activation count: `1`
- Expires in: i giorni di accesso (per esempio `365`)

### Prodotto

- Prodotto **virtuale** con uno **SKU suo** (l'ISBN appartiene già al libro fisico), lo stesso della
  licenza su Raffaello Scuola.
- Scheda "License Manager": "Sell license keys", "Generate license keys" con il generatore RSCU,
  "Delivered quantity" `1`.
- Non "venduto singolarmente": la quantità può essere maggiore di 1 (i codici oltre il primo restano
  liberi da regalare).

### Consegna

WooCommerce > Impostazioni > License Manager > WooCommerce, "License key delivery": consegna
automatica attiva e "Send" almeno negli stati **In lavorazione** e **Completato** (i prodotti solo
virtuali restano in lavorazione dopo il pagamento). Sul Bookshop sono attivi anche `test` (ordini con
SCONTO100) e `imported`. Il plugin legge queste impostazioni come le legge License Manager
(opzione `lmfwc_settings_woocommerce`); attenzione: riattivare License Manager le riporta ai valori
predefiniti (solo Completato).

## Snippet GreenBubble: escludere le RSCU

Lo snippet GreenBubble ("Personalizzazione License Manager", Code Snippets) manda a Raffaello
Digitale tutte le chiavi che non iniziano con `RLAB`, quindi anche le RSCU. Nel suo ciclo sulle
licenze, subito dopo aver letto `$license_key` e prima di
`$is_rlab = str_starts_with($license_key, 'RLAB');`, va aggiunta questa riga (compatibile con
PHP 7.4):

```php
if (strpos($license_key, 'RSCU') === 0) { continue; } // Raffaello Scuola: le manda il plugin Raffaello Licenze — Bookshop
```

La riga va prima dell'invio e prima che l'id finisca in `_gb_sent_license_ids`: così le RSCU non
vanno a Raffaello Digitale né risultano inviate da GreenBubble.

Il plugin controlla in tre modi (B13):

- **a runtime**, dopo GreenBubble sullo stesso hook: se una licenza RSCU compare nelle meta
  d'ordine `_gb_sent_license_ids` o `_gb_license_status_map`, scrive una nota d'ordine e segnala un
  problema bloccante. Il danno di quell'ordine è già fatto: va avvisata Raffaello Digitale;
- **sul codice** degli snippet attivi (con `\Code_Snippets\get_snippets()`, oppure dalla tabella
  `{prefix}snippets`): uno snippet attivo che usa `lmfwc_event_post_order_license_keys` e manda dati
  altrove è "da verificare" (giallo). Se nel codice compare `RSCU` la checklist lo dice, ma non
  basta: una riga commentata o messa dopo l'invio passerebbe lo stesso. Se Code Snippets non si
  legge, il controllo è "non verificabile";
- con la **conferma manuale** "Ho verificato l'esclusione…", legata all'hash del codice di quegli
  snippet: è l'unico modo per avere il verde quando c'è uno snippet del genere. Se l'agenzia modifica
  lo snippet (anche solo commentando la riga), la conferma decade e il controllo torna giallo. Dopo
  una segnalazione a runtime, la conferma la chiude.

## Cloudflare e Wordfence su Raffaello Scuola

### Cloudflare

Se Raffaello Scuola è dietro Cloudflare, la chiamata del Bookshop (una POST da un server, senza
browser) può ricevere una challenge. La prova di connessione la riconosce. Rimedi:

- **Bot Fight Mode** non si salta con una regola WAF "Skip" (lo dice la documentazione di
  Cloudflare). Serve una **IP Access rule** (Security > WAF > Tools, oppure Security > Security
  rules secondo la versione del pannello) che consenta ("Allow") l'IP pubblico del server del
  Bookshop: le IP Access rule vengono valutate prima. L'alternativa è Super Bot Fight Mode (piano a
  pagamento), che accetta le regole Skip.
- Con le regole WAF personalizzate o i Managed Rules: una regola "Skip" limitata al percorso
  `/wp-json/raffaello-licenze/v1/eventi` e all'IP del Bookshop.

L'IP da consentire è quello da cui il server del Bookshop esce verso Internet (chiedere all'hosting).

### Wordfence

Se su Scuola c'è Wordfence (in produzione c'è) e la prova risponde 403 senza challenge di
Cloudflare:

- Wordfence > Strumenti > Traffico dal vivo: cercare la richiesta bloccata e il motivo;
- Wordfence > Firewall > Tutte le opzioni del firewall, "Allowlisted IP addresses that bypass all
  rules": aggiungere l'IP del server del Bookshop.

## Go-live in produzione

1. **Raffaello Identity sul Bookshop di produzione** (oggi non installato): senza il `sub` del
   cliente nessuna attivazione automatica.
2. **Raffaello Identity, Raffaello Licenze 1.5.1 e LearnDash su Raffaello Scuola di produzione**;
   poi su Scuola le licenze con gli SKU dei prodotti e i loro contenuti; i corsi venduti in modalità "Chiusa" con il pulsante
   verso il prodotto del Bookshop; il segreto (Licenze > Impostazioni > Genera segreto).
   Cloudflare e Wordfence come sopra.
3. **Sul Bookshop**: generatore e prodotti RSCU, consegna di License Manager, riga di esclusione
   nello snippet GreenBubble.
4. **Plugin Bookshop**: installazione, URL e segreto, poi disattivazione del vecchio snippet se c'è.
5. **Prova connessione**: deve riuscire, senza SKU sconosciuti. La checklist non deve avere
   problemi bloccanti.
6. **Ordine di prova** con il coupon `SCONTO100` (stato test), un account con Identity e due righe
   RSCU, una con quantità 2: nota d'ordine "Raffaello Scuola, assegnazione riuscita …" con una
   licenza `attivata` per riga e un `codice_libero` per la seconda copia; su Scuola le attivazioni
   in Licenze > Attivazioni (ricerca per ordine). Poi il **rimborso totale** dello stesso ordine:
   nota "revoca riuscita" e attivazioni revocate su Scuola. In quell'ordine B13 non deve diventare
   rosso.

## Assistenza

Dove guardare:

- la **nota d'ordine** "Raffaello Scuola, …": esito di ogni licenza (fino a 10, oltre i conteggi) e,
  se c'è da intervenire, il motivo e cosa fare;
- la **checklist** (WooCommerce > Raffaello Scuola): B6 invii falliti e B14 ordini da sistemare, con
  i link agli ultimi 50 ordini;
- WooCommerce > Stato > Azioni programmate, gruppo `raffaello-scuola`;
- WooCommerce > Stato > Log, sorgente `raffaello-scuola-licenze` sul Bookshop e `raffaello-licenze`
  su Raffaello Scuola. Nei log le chiavi sono sempre mascherate (`RSCU-****-AB12`).

Esito dell'ordine (meta `_rscu_esito` per evento e `_rscu_stato` per le ricerche; per un ordine
revocato conta la revoca):

| Stato | Che cosa vuol dire | Che cosa fare |
|---|---|---|
| `in_coda` | Invio in attesa, oppure un tentativo non riuscito e il prossimo programmato, oppure collegamento non configurato | Niente, se la checklist è a posto |
| `attivato` | Scuola ha attivato l'accesso per l'acquirente | Niente |
| `inviato` | Ricevuto da Scuola senza attivazioni da fare (revoca riuscita, o licenze già revocate) | Niente |
| `da_sistemare` | Ricevuto, ma serve un intervento: il motivo è nell'esito e nella nota | Vedi sotto |
| `fallito` | Tentativi esauriti o errore definitivo | Correggere la causa (nota d'ordine), poi "Rimetti in coda gli invii falliti" o "Reinvia" sull'ordine |

Motivi da sistemare:

- `senza_destinatario`: il cliente non aveva un account Raffaello Identity collegato al momento
  dell'assegnazione. Il codice è salvato su Scuola come libero: il cliente lo inserisce su
  Raffaello Scuola (pagina del codice) dopo aver fatto l'accesso. "Reinvia" non aiuta: manda la
  stessa fotografia.
- `in_attesa_configurazione`: su Scuola non c'è una licenza con quello SKU. Creata la licenza,
  l'attivazione parte da sola; "Reinvia" aggiorna l'esito sul Bookshop.
- `conflitto`: Scuola ha già dati diversi per quella licenza (chiave, ordine o destinatario) e non
  ha cambiato niente. Controllare in Licenze > Attivazioni su Scuola.
- `codice_libero`: per quel prodotto nessuna licenza dell'ordine è stata attivata per l'acquirente,
  per esempio perché il codice era già stato riscattato da un altro. Controllare su Scuola. (Con
  quantità maggiore di 1 i codici liberi accanto a uno attivato sono normali e non contano.)
- un esito che questa versione del plugin non conosce (da una versione futura di Scuola): da
  controllare su Scuola, e magari è il momento di aggiornare il plugin.

Azioni:

- **Reinvia licenze a Raffaello Scuola** (menu "Azioni ordine" degli ordini con licenze RSCU):
  rimette in coda gli stessi dati fotografati, e la revoca se l'ordine è stato revocato, con i
  tentativi azzerati. Su Scuola l'invio è idempotente.
- **Rimetti in coda gli invii falliti** (pagina del plugin, B6): lo stesso per gli ultimi 50 ordini
  falliti.
- **Rimborso parziale**: nessuna revoca automatica; si revoca a mano su Scuola (Licenze >
  Attivazioni, ricerca per ordine).

## Limiti noti

- Le modifiche fatte direttamente in License Manager (licenza disattivata o cancellata, chiave
  rigenerata) non arrivano a Raffaello Scuola: si revoca a mano.
- Il `sub` è quello del cliente al momento dell'assegnazione: un cambio di account dopo l'acquisto
  non sposta niente.
- La guardia GreenBubble legge `_gb_sent_license_ids` e `_gb_license_status_map` senza conoscerne
  il formato esatto: cerca gli id e le chiavi RSCU fra le chiavi e i valori (anche elenchi come
  `"501,502"`, non i numeri dentro un testo). Funziona se GreenBubble scrive quelle meta durante
  l'hook di License Manager, come risulta dalla descrizione dello snippet attuale; se le scrivesse
  dopo (per esempio da una coda), resterebbero il controllo sul codice e la conferma manuale. Il
  controllo sul codice degli snippet è testuale.
- Action Scheduler esegue per default un batch alla volta. Se un filtro
  (`action_scheduler_queue_runner_concurrent_batches`) ne fa girare più d'uno insieme, assegnazione
  e revoca dello stesso ordine possono sovrascrivere l'una l'esito dell'altra, e una revoca fallita
  può sparire dagli invii falliti: la checklist lo segnala in B5 (attenzione).
- Il plugin, se lo si disattiva, lascia le sue opzioni (`rlb_impostazioni`, `rlb_ping`,
  `rlb_ultimo_invio`, `rlb_greenbubble`) e le meta d'ordine.

## Rilascio

Un tag sul repo (per esempio `1.0.1`) avvia `.github/workflows/release.yml`: scrive la versione nel
file principale, crea `raffaello-licenze-bookshop.zip` e la release, poi aggiorna
`.github/update-metadata/raffaello-licenze-bookshop.json` su `master`, da cui i siti leggono
l'aggiornamento.
