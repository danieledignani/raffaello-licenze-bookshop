<?php

namespace RaffaelloLicenzeBookshop;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Checklist di configurazione (controlli B1-B14 del go-live, più dipendenze e
 * vecchio snippet). La stessa lista alimenta la pagina Impostazioni, l'avviso
 * in admin (solo i bloccanti) e Salute del sito. Nessuna chiamata HTTP: il
 * collegamento si legge dall'ultima prova o dall'ultimo invio.
 *
 * Ogni voce: id, titolo, esito (ok, ko, non_verificabile), gravita (bloccante,
 * attenzione, informativo), testo, ordini (id degli ordini da guardare).
 * "Non verificabile" non è mai verde.
 */
class Checks
{
    /** Tutte le voci, nell'ordine della checklist. */
    public static function run(): array
    {
        $config = Config::current();
        $voci = [self::dipendenze(), self::snippet(), self::url($config), self::segreto($config)];
        if (!Plugin::dependencies_ok()) {
            return $voci;
        }
        $ping = Ping::last($config);
        $generatori = Products::generators();
        array_push(
            $voci,
            self::collegamento($config, $ping),
            self::http_esterno($config),
            self::coda(),
            self::falliti(),
            self::license_manager(),
            self::consegna(),
            self::generatore($generatori),
            self::prodotti($generatori),
            self::sku($ping),
            self::identity(),
            GreenBubble::check(),
            self::da_sistemare()
        );
        return $voci;
    }

    /** Voci da guardare: bloccanti e di attenzione, comprese quelle non verificabili. */
    public static function problems(): array
    {
        return array_values(array_filter(self::run(), function ($v) {
            return $v['esito'] !== 'ok' && $v['gravita'] !== 'informativo';
        }));
    }

    public static function blocking(): array
    {
        return array_values(array_filter(self::problems(), function ($v) {
            return $v['gravita'] === 'bloccante';
        }));
    }

    public static function ok(string $id, string $titolo, string $testo): array
    {
        return ['id' => $id, 'titolo' => $titolo, 'esito' => 'ok', 'gravita' => 'informativo', 'testo' => $testo, 'ordini' => []];
    }

    public static function ko(string $id, string $titolo, string $gravita, string $testo, array $ordini = []): array
    {
        return ['id' => $id, 'titolo' => $titolo, 'esito' => 'ko', 'gravita' => $gravita, 'testo' => $testo, 'ordini' => $ordini];
    }

    public static function nv(string $id, string $titolo, string $gravita, string $testo): array
    {
        return ['id' => $id, 'titolo' => $titolo, 'esito' => 'non_verificabile', 'gravita' => $gravita, 'testo' => $testo, 'ordini' => []];
    }

    public static function date(int $ts): string
    {
        return wp_date(get_option('date_format') . ' ' . get_option('time_format'), $ts);
    }

    private static function dipendenze(): array
    {
        $titolo = __('WooCommerce e License Manager', 'raffaello-licenze-bookshop');
        $mancano = [];
        if (!class_exists('WooCommerce')) {
            $mancano[] = 'WooCommerce';
        }
        if (!defined('LMFWC_VERSION')) {
            $mancano[] = 'License Manager for WooCommerce';
        }
        return $mancano
            /* translators: %s: plugin mancanti */
            ? self::ko('D1', $titolo, 'bloccante', sprintf(__('Non attivo: %s. Senza WooCommerce il plugin non fa niente; senza License Manager non arrivano licenze nuove da mandare.', 'raffaello-licenze-bookshop'), implode(', ', $mancano)))
            : self::ok('D1', $titolo, __('Attivi.', 'raffaello-licenze-bookshop'));
    }

    /** Il vecchio snippet, caricato in questa richiesta o attivo in Code Snippets. */
    private static function snippet(): array
    {
        $titolo = __('Vecchio snippet "Raffaello Scuola: licenze RSCU"', 'raffaello-licenze-bookshop');
        $attivi = array_filter(GreenBubble::snippets() ?? [], function ($s) {
            return $s['active'] && strpos($s['code'], 'rscu_licenze_assegnate') !== false;
        });
        if (!Plugin::snippet_loaded() && !$attivi) {
            return self::ok('D2', $titolo, __('Non attivo: gli invii li fa il plugin.', 'raffaello-licenze-bookshop'));
        }
        $dove = $attivi
            ? implode(', ', array_map(function ($s) {
                return sprintf('"%s" (#%d)', $s['name'], $s['id']);
            }, $attivi))
            : __('caricato da Code Snippets o da mu-plugins', 'raffaello-licenze-bookshop');
        return self::ko('D2', $titolo, 'bloccante', sprintf(
            /* translators: %s: dove è attivo lo snippet */
            __('Ancora attivo (%s): disattivalo. Mentre lo snippet è caricato il plugin resta fermo, per non registrare due volte gli stessi ordini; ordini e invii in coda passano poi al plugin così come sono.', 'raffaello-licenze-bookshop'),
            $dove
        ));
    }

    private static function url(array $config): array
    {
        $titolo = __('URL di Raffaello Scuola', 'raffaello-licenze-bookshop');
        if ($config['errore_url'] === null) {
            /* translators: 1: URL, 2: fonte */
            return self::ok('B1', $titolo, sprintf(__('%1$s (%2$s).', 'raffaello-licenze-bookshop'), $config['url'], self::fonte($config['fonte_url'])));
        }
        return self::ko('B1', $titolo, 'bloccante', self::errore($config, $config['errore_url'], $config['fonte_url']));
    }

    private static function segreto(array $config): array
    {
        $titolo = __('Segreto condiviso', 'raffaello-licenze-bookshop');
        if ($config['errore_secret'] === null) {
            /* translators: 1: lunghezza, 2: fonte */
            return self::ok('B2', $titolo, sprintf(__('Impostato, %1$d caratteri (%2$s).', 'raffaello-licenze-bookshop'), strlen($config['secret']), self::fonte($config['fonte_secret'])));
        }
        return self::ko('B2', $titolo, 'bloccante', self::errore($config, $config['errore_secret'], $config['fonte_secret']));
    }

    private static function errore(array $config, string $errore, string $fonte): string
    {
        if ($errore === 'mancante' && $config['altro_host'] !== null) {
            /* translators: %s: host del sito che ha salvato le impostazioni */
            return sprintf(__('Le impostazioni salvate sono di un altro sito (%s): il database è stato copiato? Qui il collegamento vale come non configurato: inserisci URL e segreto di questo ambiente.', 'raffaello-licenze-bookshop'), $config['altro_host']);
        }
        return ($fonte !== '' ? self::fonte($fonte) . ': ' : '') . Config::error_text($errore);
    }

    private static function fonte(string $fonte): string
    {
        return $fonte === 'costante' ? __('da wp-config.php', 'raffaello-licenze-bookshop') : __('dalle impostazioni', 'raffaello-licenze-bookshop');
    }

    /** B3: l'ultima chiamata vera o di prova fatta con la configurazione attuale. */
    private static function collegamento(array $config, ?array $ping): array
    {
        $titolo = __('Collegamento', 'raffaello-licenze-bookshop');
        if (!$config['ok']) {
            return self::nv('B3', $titolo, 'informativo', __('Da provare dopo aver impostato URL e segreto.', 'raffaello-licenze-bookshop'));
        }
        $invio = get_option(Sender::OPTION_ULTIMO);
        $invio = is_array($invio) && ($invio['impronta'] ?? '') === $config['impronta'] ? $invio : null;
        $da_ping = $ping && (!$invio || $ping['quando'] >= $invio['quando']);
        $ultimo = $da_ping ? $ping : $invio;
        if (!$ultimo) {
            return self::nv('B3', $titolo, 'attenzione', __('Mai provato con la configurazione attuale: premi "Prova connessione".', 'raffaello-licenze-bookshop'));
        }
        $fonte = $da_ping
            /* translators: %s: data */
            ? sprintf(__('prova di connessione del %s', 'raffaello-licenze-bookshop'), self::date($ultimo['quando']))
            /* translators: 1: numero d'ordine, 2: data */
            : sprintf(__('invio dell\'ordine %1$d del %2$s', 'raffaello-licenze-bookshop'), $ultimo['ordine'], self::date($ultimo['quando']));
        if ($ultimo['codice'] === 'ok') {
            if ($da_ping) {
                /* translators: %s: versione */
                $fonte .= sprintf(__(', Raffaello Scuola %s', 'raffaello-licenze-bookshop'), $ultimo['versione']);
            }
            /* translators: %s: prova o invio */
            return self::ok('B3', $titolo, sprintf(__('Riuscito: %s.', 'raffaello-licenze-bookshop'), $fonte));
        }
        // Scuola ha accettato la firma: il collegamento c'è, il problema è altrove.
        $gravita = in_array($ultimo['codice'], ['occupato', 'formato', 'ping_sconosciuto'], true) ? 'attenzione' : 'bloccante';
        /* translators: 1: prova o invio, 2: esito, 3: spiegazione */
        return self::ko('B3', $titolo, $gravita, sprintf(__('Non riuscito: %1$s (%2$s). %3$s', 'raffaello-licenze-bookshop'), $fonte, $ultimo['testo'], Client::help($ultimo['codice'])));
    }

    private static function http_esterno(array $config): array
    {
        $titolo = __('Chiamate HTTP in uscita', 'raffaello-licenze-bookshop');
        if ($config['url'] === '') {
            return self::nv('B4', $titolo, 'informativo', __('Da controllare dopo aver impostato l\'URL.', 'raffaello-licenze-bookshop'));
        }
        if ((new \WP_Http())->block_request($config['url'] . Client::PATH)) {
            return self::ko('B4', $titolo, 'bloccante', Client::help('bloccata'));
        }
        return self::ok('B4', $titolo, __('WordPress non blocca le chiamate verso Raffaello Scuola.', 'raffaello-licenze-bookshop'));
    }

    private static function coda(): array
    {
        $titolo = __('Action Scheduler', 'raffaello-licenze-bookshop');
        $in_ritardo = as_get_scheduled_actions([
            'hook'         => Sender::AZIONE,
            'group'        => Sender::GRUPPO,
            'status'       => 'pending',
            'date'         => time() - 30 * MINUTE_IN_SECONDS,
            'date_compare' => '<=',
            'per_page'     => 1,
        ], 'ids');
        if ($in_ritardo) {
            return self::ko('B5', $titolo, 'bloccante', __('Ci sono invii in attesa da più di 30 minuti: Action Scheduler non sta girando (WP-Cron fermo?). Guarda WooCommerce > Stato > Azioni programmate, gruppo raffaello-scuola.', 'raffaello-licenze-bookshop'));
        }
        // Il default è 1: un batch alla volta, quindi gli invii dello stesso ordine non si sovrappongono.
        if (apply_filters('action_scheduler_queue_runner_concurrent_batches', 1) > 1) {
            return self::ko('B5', $titolo, 'attenzione', __('Action Scheduler esegue più batch insieme (filtro action_scheduler_queue_runner_concurrent_batches): assegnazione e revoca dello stesso ordine possono sovrascrivere l\'una l\'esito dell\'altra, e una revoca fallita può sparire dagli invii falliti. Nessun invio in ritardo.', 'raffaello-licenze-bookshop'));
        }
        return self::ok('B5', $titolo, __('Nessun invio in ritardo.', 'raffaello-licenze-bookshop'));
    }

    private static function falliti(): array
    {
        $titolo = __('Invii falliti', 'raffaello-licenze-bookshop');
        $falliti = Sender::orders('fallito');
        if (!$falliti->total) {
            return self::ok('B6', $titolo, __('Nessuno.', 'raffaello-licenze-bookshop'));
        }
        return self::ko('B6', $titolo, 'bloccante', sprintf(
            /* translators: %d: numero di ordini */
            _n('%d ordine con invio fallito definitivamente: correggi la causa (nota d\'ordine) e usa "Rimetti in coda gli invii falliti".', '%d ordini con invio fallito definitivamente: correggi la causa (note d\'ordine) e usa "Rimetti in coda gli invii falliti".', $falliti->total, 'raffaello-licenze-bookshop'),
            $falliti->total
        ), $falliti->orders);
    }

    private static function license_manager(): array
    {
        $titolo = __('Versione di License Manager', 'raffaello-licenze-bookshop');
        $versione = (string) LMFWC_VERSION;
        if ($versione === Plugin::LMFWC_PROVATA) {
            /* translators: %s: versione */
            return self::ok('B7', $titolo, sprintf(__('%s, quella provata.', 'raffaello-licenze-bookshop'), $versione));
        }
        /* translators: 1: versione installata, 2: versione provata */
        return self::ko('B7', $titolo, 'attenzione', sprintf(__('%1$s: il plugin è provato con la %2$s; hook, opzioni e tabelle potrebbero essere cambiati. Prova un ordine dopo ogni aggiornamento di License Manager.', 'raffaello-licenze-bookshop'), $versione, Plugin::LMFWC_PROVATA));
    }

    /** B8: le stesse opzioni e la stessa lettura di License Manager (Settings::get, Order::addOrderStatusHooks). */
    private static function consegna(): array
    {
        $titolo = __('Consegna delle licenze', 'raffaello-licenze-bookshop');
        $non_riconosciuta = self::nv('B8', $titolo, 'attenzione', __('Impostazioni di License Manager non riconosciute (opzione lmfwc_settings_woocommerce): controlla a mano WooCommerce > Impostazioni > License Manager > WooCommerce.', 'raffaello-licenze-bookshop'));
        // Opzione o chiave assenti valgono come License Manager le legge: nessuno stato, nessuna consegna.
        $impostazioni = get_option('lmfwc_settings_woocommerce', []);
        $stati = is_array($impostazioni) ? ($impostazioni['lmfwc_license_key_delivery_options'] ?? []) : null;
        if (!is_array($stati)) {
            return $non_riconosciuta;
        }
        $attivi = [];
        foreach ($stati as $stato => $conf) {
            if (!is_array($conf)) {
                return $non_riconosciuta;
            }
            if (array_key_exists('send', $conf) && filter_var($conf['send'], FILTER_VALIDATE_BOOLEAN)) {
                $attivi[] = str_replace('wc-', '', (string) $stato);
            }
        }
        $mancano = array_diff(['processing', 'completed'], $attivi);
        if (count($mancano) === 2) {
            return self::ko('B8', $titolo, 'bloccante', __('License Manager non assegna le licenze né agli ordini "In lavorazione" né a quelli "Completati": spunta "Send" per questi stati in WooCommerce > Impostazioni > License Manager > WooCommerce.', 'raffaello-licenze-bookshop'));
        }
        /* translators: %s: stati d'ordine */
        $testo = sprintf(__('Licenze assegnate negli stati: %s.', 'raffaello-licenze-bookshop'), implode(', ', $attivi));
        $problemi = [];
        if (in_array('processing', $mancano, true)) {
            $problemi[] = __('senza "In lavorazione" le licenze arrivano solo quando l\'ordine viene completato', 'raffaello-licenze-bookshop');
        }
        if (in_array('completed', $mancano, true)) {
            $problemi[] = __('manca "Completato"', 'raffaello-licenze-bookshop');
        }
        // Come License Manager: chiave assente o vuota = consegna automatica spenta.
        if (empty($impostazioni['lmfwc_auto_delivery'])) {
            $problemi[] = __('consegna automatica spenta: le chiavi restano "vendute" e non arrivano nelle email al cliente', 'raffaello-licenze-bookshop');
        }
        return $problemi
            ? self::ko('B8', $titolo, 'attenzione', $testo . ' ' . ucfirst(implode('; ', $problemi)) . '.')
            : self::ok('B8', $titolo, $testo . ' ' . __('Consegna automatica attiva.', 'raffaello-licenze-bookshop'));
    }

    private static function generatore(?array $generatori): array
    {
        $titolo = __('Generatore RSCU', 'raffaello-licenze-bookshop');
        if ($generatori === null) {
            return self::nv('B9', $titolo, 'attenzione', __('Tabella dei generatori di License Manager non trovata: controlla a mano.', 'raffaello-licenze-bookshop'));
        }
        if (!$generatori) {
            return self::ko('B9', $titolo, 'bloccante', __('Nessun generatore con prefisso RSCU-: crealo in License Manager > Generatori (vedi README). Senza, i prodotti non producono chiavi da mandare a Raffaello Scuola.', 'raffaello-licenze-bookshop'));
        }
        $descrizioni = [];
        $problemi = [];
        foreach ($generatori as $g) {
            $giorni = (int) $g['expires_in'];
            /* translators: 1: nome, 2: durata */
            $descrizioni[] = sprintf(__('"%1$s" (%2$s)', 'raffaello-licenze-bookshop'), $g['name'], $giorni > 0 ? sprintf(_n('%d giorno di accesso', '%d giorni di accesso', $giorni, 'raffaello-licenze-bookshop'), $giorni) : __('senza scadenza', 'raffaello-licenze-bookshop'));
            if ($g['prefix'] !== 'RSCU-') {
                /* translators: 1: nome, 2: prefisso */
                $problemi[] = sprintf(__('"%1$s" ha il prefisso "%2$s" invece di "RSCU-"', 'raffaello-licenze-bookshop'), $g['name'], $g['prefix']);
            }
            if ((int) $g['times_activated_max'] !== 1) {
                /* translators: %s: nome */
                $problemi[] = sprintf(__('"%s" non ha 1 come numero massimo di attivazioni', 'raffaello-licenze-bookshop'), $g['name']);
            }
        }
        $testo = implode(', ', $descrizioni) . '.';
        return $problemi
            ? self::ko('B9', $titolo, 'attenzione', $testo . ' ' . ucfirst(implode('; ', $problemi)) . '.')
            : self::ok('B9', $titolo, $testo);
    }

    private static function prodotti(?array $generatori): array
    {
        $titolo = __('Prodotti RSCU', 'raffaello-licenze-bookshop');
        if (!$generatori) {
            return self::nv('B10', $titolo, 'informativo', __('Da controllare dopo aver creato il generatore.', 'raffaello-licenze-bookshop'));
        }
        $prodotti = Products::rscu($generatori);
        if (!$prodotti) {
            return self::ko('B10', $titolo, 'attenzione', __('Nessun prodotto usa il generatore RSCU.', 'raffaello-licenze-bookshop'));
        }
        $problemi = [];
        foreach ($prodotti as $p) {
            $difetti = [];
            if (!$p->get_meta('lmfwc_licensed_product')) {
                $difetti[] = __('"Sell license keys" spento', 'raffaello-licenze-bookshop');
            }
            // Senza generatore (e senza magazzino) License Manager salta la riga.
            if (!$p->get_meta('lmfwc_licensed_product_use_generator')) {
                $difetti[] = __('"Generate license keys" spento', 'raffaello-licenze-bookshop');
            }
            if (!$p->is_virtual()) {
                $difetti[] = __('non virtuale', 'raffaello-licenze-bookshop');
            }
            if ($p->get_sku() === '') {
                $difetti[] = __('senza SKU', 'raffaello-licenze-bookshop');
            }
            if ($p->get_sold_individually()) {
                $difetti[] = __('venduto singolarmente', 'raffaello-licenze-bookshop');
            }
            if (absint($p->get_meta('lmfwc_licensed_product_delivered_quantity')) > 1) {
                $difetti[] = __('quantità consegnata maggiore di 1', 'raffaello-licenze-bookshop');
            }
            if ($difetti) {
                $problemi[] = sprintf('"%s" (#%d): %s', $p->get_name(), $p->get_id(), implode(', ', $difetti));
            }
        }
        /* translators: %d: numero di prodotti */
        $testo = sprintf(_n('%d prodotto collegato al generatore.', '%d prodotti collegati al generatore.', count($prodotti), 'raffaello-licenze-bookshop'), count($prodotti));
        return $problemi
            ? self::ko('B10', $titolo, 'attenzione', $testo . ' ' . implode('; ', $problemi) . '.')
            : self::ok('B10', $titolo, $testo . ' ' . __('Virtuali, con SKU, vendibili in più copie, una chiave per copia.', 'raffaello-licenze-bookshop'));
    }

    private static function sku(?array $ping): array
    {
        $titolo = __('SKU configurati su Raffaello Scuola', 'raffaello-licenze-bookshop');
        if (!$ping || $ping['codice'] !== 'ok') {
            return self::nv('B11', $titolo, 'informativo', __('Si verifica con una "Prova connessione" riuscita.', 'raffaello-licenze-bookshop'));
        }
        if ($ping['sku_sconosciuti']) {
            /* translators: %s: elenco di SKU */
            return self::ko('B11', $titolo, 'bloccante', sprintf(__('Raffaello Scuola non ha una licenza attiva per: %s. Chi compra questi prodotti resta in attesa di configurazione: crea su Scuola le licenze con lo stesso SKU, poi ripeti la prova.', 'raffaello-licenze-bookshop'), implode(', ', $ping['sku_sconosciuti'])));
        }
        /* translators: 1: numero di SKU, 2: data */
        return self::ok('B11', $titolo, sprintf(__('Tutti i %1$d SKU dei prodotti pubblicati (prova del %2$s).', 'raffaello-licenze-bookshop'), $ping['sku_inviati'], self::date($ping['quando'])));
    }

    private static function identity(): array
    {
        $titolo = __('Raffaello Identity e acquisti da ospite', 'raffaello-licenze-bookshop');
        $problemi = [];
        if (!defined('RI_VERSION')) {
            $problemi[] = __('Raffaello Identity non è attivo: senza il sub del cliente le licenze arrivano "senza destinatario"', 'raffaello-licenze-bookshop');
        }
        if (get_option('woocommerce_enable_guest_checkout') !== 'no') {
            $problemi[] = __('l\'acquisto da ospite è permesso: quegli ordini non hanno un cliente a cui attivare la licenza', 'raffaello-licenze-bookshop');
        }
        return $problemi
            ? self::ko('B12', $titolo, 'bloccante', ucfirst(implode('; ', $problemi)) . '.')
            : self::ok('B12', $titolo, __('Identity attivo, acquisto da ospite disattivato.', 'raffaello-licenze-bookshop'));
    }

    private static function da_sistemare(): array
    {
        $titolo = __('Ordini da sistemare', 'raffaello-licenze-bookshop');
        $ordini = Sender::orders('da_sistemare');
        if (!$ordini->total) {
            return self::ok('B14', $titolo, __('Nessuno.', 'raffaello-licenze-bookshop'));
        }
        return self::ko('B14', $titolo, 'attenzione', sprintf(
            /* translators: %d: numero di ordini */
            _n('%d ordine ricevuto da Raffaello Scuola ma da sistemare a mano: il motivo e cosa fare sono nella nota d\'ordine.', '%d ordini ricevuti da Raffaello Scuola ma da sistemare a mano: il motivo e cosa fare sono nelle note d\'ordine.', $ordini->total, 'raffaello-licenze-bookshop'),
            $ordini->total
        ), $ordini->orders);
    }
}
