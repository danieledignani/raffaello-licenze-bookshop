=== Raffaello Licenze — Bookshop ===
Contributors: grupporaffaello
Tags: licenze, license manager, woocommerce, raffaello scuola, identity
Requires at least: 5.8
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 1.0.3
License: GPLv2 or later

Manda a Raffaello Scuola le licenze RSCU vendute sul Bookshop con License Manager for WooCommerce, per attivarle sul profilo Raffaello Identity di chi compra.

== Descrizione ==

Quando License Manager for WooCommerce assegna le licenze di un ordine, il plugin fotografa quelle con prefisso `RSCU` (chiave, SKU, scadenza, `sub` di Raffaello Identity del cliente) e le manda a Raffaello Scuola (plugin Raffaello Licenze) con una coda di Action Scheduler: chiamate firmate HMAC-SHA256, lotti di al massimo 50 licenze e 64 KB, 5 tentativi. Rimborso totale o annullamento revocano le licenze su Scuola.

Funzionalità:

* Impostazioni in WooCommerce > Raffaello Scuola: URL di Scuola e segreto condiviso, salvati senza autoload e legati all'host del sito (una copia del database su un altro ambiente risulta non configurata). Le costanti `RAFFAELLO_SCUOLA_URL` e `RAFFAELLO_SCUOLA_SECRET` in wp-config.php vincono. Il segreto non si ristampa mai.
* **Prova connessione**: un ping firmato con lo stesso client degli invii, che riconosce DNS, TLS, timeout, 401, challenge di Cloudflare, 403, 404, Scuola non configurata, e confronta gli SKU dei prodotti RSCU.
* **Checklist** dei controlli di go-live (URL, segreto, collegamento, HTTP in uscita, Action Scheduler, invii falliti, versione e consegna di License Manager, generatore e prodotti RSCU, SKU su Scuola, Identity e acquisti da ospite, snippet GreenBubble, ordini da sistemare, riconciliazione), con l'avviso in admin per i soli problemi bloccanti e un test in Salute del sito.
* Esito leggibile per ordine (in coda, inviato, attivato, da sistemare con il motivo, fallito), note d'ordine, azione "Reinvia licenze a Raffaello Scuola" e "Rimetti in coda gli invii falliti".
* Riconciliazione oraria: gli invii rimasti in coda senza job ripartono da soli.
* Guardia sullo snippet GreenBubble: a runtime, sul codice degli snippet e con una conferma manuale che decade se il codice cambia.
* Prende il posto dello snippet "Raffaello Scuola: licenze RSCU" con le stesse meta d'ordine; finché lo snippet è caricato il plugin resta fermo e chiede di disattivarlo.
* Aggiornamento automatico da GitHub.

Istruzioni complete per installazione, configurazione, go-live e assistenza nel README.md del repo.

== Requisiti ==

* WooCommerce con Action Scheduler e License Manager for WooCommerce (provato con la 3.0.20 gratuita).
* Raffaello Identity sul Bookshop.
* Raffaello Licenze su Raffaello Scuola: 1.5.0 per gli invii, 1.5.1 o successiva per la prova di connessione.

== Changelog ==

= 1.0.3 =
* Corretto: la riconciliazione oraria non finiva mai su un archivio ordini grande. La sua ricerca metteva in OR condizioni su meta diverse, e WooCommerce (HPOS e archivio classico) unisce le meta di "=" ed EXISTS solo per ordine: l'OR moltiplicava fra loro tutte le meta di ogni ordine. Sul Bookshop dev, con 71.000 ordini, ogni giro restava in esecuzione e Action Scheduler lo segnava fallito dopo 5 minuti; il giro dopo partiva alla prima pagina di amministrazione. Ora sono quattro ricerche in AND, una per caso, che usano gli indici delle meta; gli id si uniscono e si scorrono 250 per giro con un cursore sull'ultimo ordine visto (opzione rlb_riconciliazione_cursore al posto di rlb_riconciliazione_pagina). Su MariaDB 11.8 con 71.000 ordini e 817.000 meta di prova la ricerca vecchia non finisce in 60 secondi; le quattro nuove, insieme, in meno di un secondo.
* Corretto: lo stesso per gli invii falliti (B6) e gli ordini da sistemare (B14), che la pagina del plugin calcola a ogni apertura e l'avviso in admin al più ogni 5 minuti: due ricerche in AND, una per evento, con i totali sommati. Sugli stessi dati da 16 secondi a meno di uno.

= 1.0.2 =
* Fotografia delle licenze sotto un lock breve per ordine (GET_LOCK <prefisso>rlb_foto_<id>, attesa massima 10 secondi; senza lock si fotografa lo stesso con un avviso nel log): rilettura delle meta, unione, salvataggio. Lo stesso lock in ordine_revocato attorno a marcatore, rilettura e decisione.
* Chiusura dell'invio sotto il lock della fotografia (attesa massima 10 secondi): a invio riuscito il job rilegge la fotografia e, se ci sono licenze che non ha mandato, lascia l'esito "in coda" e rimette in coda tutto (l'endpoint è idempotente), invece di segnare "attivato" sopra un "in coda" che nessuno avrebbe ripreso. Senza lock non chiude e rimette in coda.
* Ordine delle scritture: un salvataggio di WooCommerce scrive una meta per query, senza transazione (save_meta_data di WooCommerce 11.2.1: le chiavi nuove nell'ordine delle update_meta_data, quelle esistenti nell'ordine di lettura, con HPOS e con l'archivio classico). Prima cliente e fotografia, poi tentativi, esito e stato: un'interruzione a metà lascia una fotografia senza stato.
* La riconciliazione riprende anche le fotografie senza stato dell'assegnazione e le revoche senza stato, negli ordini creati da più di 30 minuti, con la stessa ricerca a pagine, il lock dell'invio e le riprese; tra questi gli ordini fotografati dal vecchio snippet, rimandati una volta.
* Azione d'ordine "Recupera le licenze RSCU da License Manager", con License Manager attivo: rilegge le licenze dell'ordine (repository License di License Manager 3.0.20, findAllBy per order_id), fotografa le RSCU che mancano e rimette in coda l'invio di tutte, senza rilanciare l'hook di License Manager. Per il processo fermato prima della fotografia e per le vendite fatte prima del plugin.
* Fotografia e stato "in coda" nello stesso salvataggio, e così il marcatore di revoca quando la fotografia c'è: un'interruzione prima di programmare l'invio non lascia più l'ordine senza stato.
* Riconciliazione oraria (azione ricorrente rlb_riconcilia, gruppo raffaello-scuola, programmata se manca e tolta alla disattivazione): gli eventi in coda da più di 30 minuti senza un'azione in attesa o in esecuzione si rimettono in coda da soli; riprese contate in _rscu_riprese_<evento> e azzerate da un invio riuscito o da "Reinvia"; oltre 3 l'evento è fallito con motivo "interrotto" e compare in B6. A pagine di 250 ordini (al massimo 500 coppie ordine/evento per giro) sui due archivi ordini, con il segno della pagina in un'opzione.
* B15 diventa "Riconciliazione": verde con la data dell'ultimo giro, gli invii ripresi e quelli segnati falliti; attenzione se l'ultimo giro è di più di 2 ore fa o non c'è ancora stato. Tolta la ricerca delle azioni fallite a ogni pagina; "Rimetti in coda gli invii falliti" torna solo su B6.
* Avviso in admin dal risultato della checklist tenuto 5 minuti (transient rlb_bloccanti), ricalcolato dopo salvataggio delle impostazioni, prova di connessione, "Rimetti in coda", conferma o segnalazione di GreenBubble e apertura della pagina del plugin, che calcola sempre da zero. Gli snippet di Code Snippets si leggono una volta sola per D2 e B13.
* B10: ora va segnalato il prodotto RSCU acquistabile in più copie (per ora i codici regalo non servono: «Venduto singolarmente»), non più quello venduto singolarmente.
* Nomi dei lock con il prefisso delle tabelle (<prefisso>rlb_ordine_<id>), come su Raffaello Scuola.
* Salvataggio dell'ordine con la fotografia senza messaggi d'errore del database, che riporterebbero la query con le chiavi complete.
* README: prodotti RSCU venduti singolarmente e generatore senza "Expires in" (per ora i corsi non scadono), ordine di prova con una copia, riconciliazione, monitoraggio e nomi dei lock.

= 1.0.1 =
* Esiti, stati e tentativi in chiavi separate per evento (_rscu_esito_<evento>, _rscu_stato_<evento>, _rscu_tentativi_<evento>, con evento licenze_assegnate o licenze_revocate): ogni invio scrive solo le sue, così assegnazione e revoca dello stesso ordine non si sovrascrivono anche con più batch di Action Scheduler insieme. Le chiavi della 1.0.0 (_rscu_esito, _rscu_stato, _rscu_tentativi) non si leggono più: nessuna migrazione, perché la 1.0.0 ha girato solo sul Bookshop dev con un ordine di prova.
* Invii falliti e ordini da sistemare cercati sulle chiavi nuove (per un ordine revocato conta la revoca), con HPOS e con l'archivio ordini classico su post: lì WooCommerce ignora la meta_query, e la aggiunge il plugin con il filtro woocommerce_order_data_store_cpt_get_orders_query.
* Un invio per ordine alla volta, per tutti e due gli eventi: lock del database (GET_LOCK rlb_ordine_<id>, rilasciato anche su errore); con il lock occupato l'invio si riprova fra un minuto senza consumare tentativi; se il lock non si può prendere (errore del database) o il nuovo tentativo non si programma, l'azione fallisce con un errore nel log, senza toccare l'ordine. Dopo il lock le meta dell'ordine si rileggono dal database. Requisito: un solo server MySQL/MariaDB.
* Nuovo controllo B15 "Invii interrotti": azioni di Action Scheduler fallite (errore fatale, timeout, lock del database) con l'ordine ancora in coda e nessuna altra azione in attesa o in esecuzione per lo stesso ordine ed evento; bloccante, li conta tutti e ne elenca 50 con i link; "Rimetti in coda gli invii falliti e interrotti" li riprende tutti insieme ai falliti.
* B5 torna a controllare solo gli invii in ritardo.
* Nota del rimborso parziale: revocare l'attivazione dei codici già riscattati e disattivare quelli ancora liberi.
* README: go-live con i prodotti RSCU non acquistabili fino alla prova finale, fatta con un prodotto di prova dedicato e un utente non amministratore e seguita dalla prova di un pagamento vero; monitoraggio di B6, B14 e B15 (azioni fallite conservate 3 mesi); quali errori consumano tentativi; cache su Raffaello Scuola (prima e dopo una revoca); cliente senza sub; rimborso parziale.
* Testi: singolare nelle note ("1 licenza in 1 invio") e nei conteggi; B11 senza prodotti RSCU pubblicati dice che non c'è niente da confrontare; nel README la riga di esclusione per GreenBubble va prima della riga che assegna $is_rlab.

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
