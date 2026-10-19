=== Raffaello Licenze — Bookshop ===
Contributors: grupporaffaello
Tags: licenze, license manager, woocommerce, raffaello scuola, identity
Requires at least: 5.8
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later

Manda a Raffaello Scuola le licenze RSCU vendute sul Bookshop con License Manager for WooCommerce, per attivarle sul profilo Raffaello Identity di chi compra.

== Descrizione ==

Quando License Manager for WooCommerce assegna le licenze di un ordine, il plugin fotografa quelle con prefisso `RSCU` (chiave, SKU, scadenza, `sub` di Raffaello Identity del cliente) e le manda a Raffaello Scuola (plugin Raffaello Licenze) con una coda di Action Scheduler: chiamate firmate HMAC-SHA256, lotti di al massimo 50 licenze e 64 KB, 5 tentativi. Rimborso totale o annullamento revocano le licenze su Scuola.

Funzionalità:

* Impostazioni in WooCommerce > Raffaello Scuola: URL di Scuola e segreto condiviso, salvati senza autoload e legati all'host del sito (una copia del database su un altro ambiente risulta non configurata). Le costanti `RAFFAELLO_SCUOLA_URL` e `RAFFAELLO_SCUOLA_SECRET` in wp-config.php vincono. Il segreto non si ristampa mai.
* **Prova connessione**: un ping firmato con lo stesso client degli invii, che riconosce DNS, TLS, timeout, 401, challenge di Cloudflare, 403, 404, Scuola non configurata, e confronta gli SKU dei prodotti RSCU.
* **Checklist** dei controlli di go-live (URL, segreto, collegamento, HTTP in uscita, Action Scheduler, invii falliti, versione e consegna di License Manager, generatore e prodotti RSCU, SKU su Scuola, Identity e acquisti da ospite, snippet GreenBubble, ordini da sistemare), con l'avviso in admin per i soli problemi bloccanti e un test in Salute del sito.
* Esito leggibile per ordine (in coda, inviato, attivato, da sistemare con il motivo, fallito), note d'ordine, azione "Reinvia licenze a Raffaello Scuola" e "Rimetti in coda gli invii falliti".
* Guardia sullo snippet GreenBubble: a runtime, sul codice degli snippet e con una conferma manuale che decade se il codice cambia.
* Prende il posto dello snippet "Raffaello Scuola: licenze RSCU" con le stesse meta d'ordine; finché lo snippet è caricato il plugin resta fermo e chiede di disattivarlo.
* Aggiornamento automatico da GitHub.

Istruzioni complete per installazione, configurazione, go-live e assistenza nel README.md del repo.

== Requisiti ==

* WooCommerce con Action Scheduler e License Manager for WooCommerce (provato con la 3.0.20 gratuita).
* Raffaello Identity sul Bookshop.
* Raffaello Licenze su Raffaello Scuola: 1.5.0 per gli invii, 1.5.1 o successiva per la prova di connessione.

== Changelog ==

= 1.0.0 =
* Prima versione come plugin, dallo snippet "Raffaello Scuola: licenze RSCU": stessa fotografia delle licenze, stesso hook cumulativo, lotti da 50 licenze e 64 KB, coda Action Scheduler, revoca registrata anche prima della fotografia, solo ordini con prodotti licenziati, azione "Reinvia"; stesse meta d'ordine e stessa azione in coda.
* Impostazioni in WooCommerce > Raffaello Scuola: URL e segreto nel database (autoload no, legati all'host), con le costanti di wp-config.php che vincono.
* Prova connessione con un ping firmato: esiti DNS, TLS, timeout, 401, challenge di Cloudflare, 403, 404, reindirizzamento, Scuola non configurata, Scuola senza ping; versione di Scuola e SKU sconosciuti.
* Checklist D1-D2 e B1-B14, avviso in admin per i bloccanti senza chiusura, test in Salute del sito.
* Si ritentano anche 401, 403, 404, challenge di Cloudflare, reindirizzamenti e risposte inattese (definitivo resta il 400); senza configurazione l'invio aspetta senza consumare tentativi.
* Revoca recuperata se il rimborso arriva mentre si fotografano le licenze; un solo invio in attesa per ordine ed evento anche con un invio in esecuzione.
* Esito per ordine (meta _rscu_esito e _rscu_stato), note che non scrivono "riuscita" quando c'è da intervenire (anche per esiti sconosciuti), "Rimetti in coda gli invii falliti" per gli ultimi 50 ordini.
* Guardia GreenBubble: a runtime sulle meta _gb_sent_license_ids e _gb_license_status_map, statica sugli snippet di Code Snippets, verde solo con la conferma manuale legata all'hash del codice.
* Convivenza con lo snippet: se è ancora caricato il plugin resta fermo e lo segnala come bloccante.
* Compatibile con HPOS; chiamate senza seguire i reindirizzamenti.
