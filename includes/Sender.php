<?php

namespace RaffaelloLicenzeBookshop;

use Automattic\WooCommerce\Utilities\OrderUtil;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Licenze RSCU vendute sul Bookshop, mandate a Raffaello Scuola.
 *
 * - Quando License Manager assegna le licenze di un ordine, fotografa nella meta
 *   d'ordine quelle con prefisso RSCU (id, chiave, riga, SKU, scadenza) e il sub
 *   di Identity del cliente, poi accoda l'invio con Action Scheduler.
 * - L'invio va a lotti di al massimo 50 licenze e 64 KB, firmati a ogni
 *   tentativo; 5 tentativi a 1 min, 5 min, 30 min, 2 h e 6 h, ripetuti sugli
 *   errori di rete e di configurazione (401, 403, 404, challenge di Cloudflare),
 *   su 429 e 5xx. Senza configurazione l'invio aspetta, senza consumare tentativi.
 * - Rimborso totale o annullamento: accoda la revoca di tutte le licenze RSCU,
 *   anche di quelle assegnate dopo.
 * - Esito, stato e tentativi in chiavi separate per evento (_rscu_esito_<evento>,
 *   _rscu_stato_<evento>, _rscu_tentativi_<evento>): ogni job scrive solo le sue,
 *   così assegnazione e revoca dello stesso ordine non si sovrascrivono anche con
 *   più batch di Action Scheduler insieme. Stati: in_coda, inviato, attivato,
 *   da_sistemare, fallito.
 * - Fotografia e marcatore di revoca si scrivono sotto un lock breve per ordine, nello
 *   stesso salvataggio dello stato "in coda" (prima la fotografia, poi lo stato); sotto lo
 *   stesso lock il job chiude l'invio. Un'interruzione prima di programmare l'invio, o a metà
 *   del salvataggio, la ripara la riconciliazione oraria.
 *
 * Fotografia (_rscu_licenze, _rscu_cliente), _rscu_revocato, azione e gruppo di
 * Action Scheduler sono quelli dello snippet che il plugin sostituisce.
 */
class Sender
{
    const PREFISSO = 'RSCU';
    const META_LICENZE = '_rscu_licenze';
    const META_CLIENTE = '_rscu_cliente';
    const META_TENTATIVI = '_rscu_tentativi_';
    const META_REVOCATO = '_rscu_revocato';
    // Prefissi: la chiave è il prefisso seguito dall'evento (licenze_assegnate, licenze_revocate).
    const META_ESITO = '_rscu_esito_';
    const META_STATO = '_rscu_stato_';
    const META_RIPRESE = '_rscu_riprese_';
    const EVENTI = ['licenze_assegnate', 'licenze_revocate'];
    const AZIONE = 'rscu_invia_licenze';
    const GRUPPO = 'raffaello-scuola';

    // Riconciliazione: ogni ora riprende gli invii rimasti in coda senza job (al massimo 3 volte).
    const RICONCILIA = 'rlb_riconcilia';
    const OPTION_RICONCILIA = 'rlb_riconciliazione';
    // Ultimo ordine visto dal giro precedente.
    const OPTION_CURSORE = 'rlb_riconciliazione_cursore';
    const MAX_RIPRESE = 3;

    /** Repository delle licenze di License Manager 3.0.20 (findAllBy, come prima del suo hook). */
    const LM_LICENZE = \LicenseManagerForWooCommerce\Repositories\Resources\License::class;

    /** Ultimo invio vero: codice, testo, ora, ordine e impronta della configurazione. */
    const OPTION_ULTIMO = 'rlb_ultimo_invio';

    // Attesa prima di ciascuno dei 5 tentativi: 1 min, 5 min, 30 min, 2 h, 6 h.
    const ATTESE = [60, 300, 1800, 7200, 21600];
    // Senza configurazione si riprova ogni ora, senza contare il tentativo.
    const ATTESA_CONFIGURAZIONE = 3600;

    // Limiti dell'endpoint di Raffaello Scuola per un invio.
    const LOTTO = 50;
    const MAX_BYTE = 65536;

    // Esiti di Raffaello Scuola che non chiedono interventi (codice_libero con la regola di motivi()).
    const RIUSCITI = ['attivata', 'revocata', 'codice_libero'];

    public static function register(): void
    {
        add_action('lmfwc_event_post_order_license_keys', [self::class, 'licenze_assegnate'], 20);
        add_action(self::AZIONE, [self::class, 'invia'], 10, 2);
        add_filter('woocommerce_order_actions', [self::class, 'azioni_ordine'], 10, 2);
        add_action('woocommerce_order_action_rscu_reinvia', [self::class, 'reinvia']);
        add_action('woocommerce_order_action_rscu_recupera', [self::class, 'recupera']);
        add_action('woocommerce_order_status_refunded', [self::class, 'ordine_revocato'], 10, 2);
        add_action('woocommerce_order_status_cancelled', [self::class, 'ordine_revocato'], 10, 2);
        add_action('woocommerce_order_partially_refunded', [self::class, 'rimborso_parziale'], 10, 2);
        add_action(self::RICONCILIA, [self::class, 'riconcilia']);
        add_action('admin_init', [self::class, 'programma_riconciliazione']);
    }

    /**
     * License Manager lancia l'evento una volta per ogni riga d'ordine con licenza,
     * ogni volta con tutte le licenze assegnate fin lì: si fotografano solo le RSCU
     * non ancora salvate.
     */
    public static function licenze_assegnate($args): void
    {
        $order = wc_get_order($args['orderId'] ?? 0);
        $rscu = array_filter((array) ($args['licenses'] ?? []), function ($license) {
            return strpos((string) $license->getDecryptedLicenseKey(), self::PREFISSO) === 0;
        });
        if (!$order || !$rscu) {
            return;
        }

        // Lock breve, non quello dell'invio, che resta preso durante le chiamate a Scuola.
        $lock = 'rlb_foto_' . $order->get_id();
        $preso = self::lock($lock, 10) === '1';
        if (!$preso) {
            // Meglio una fotografia senza lock che nessuna fotografia.
            Log::write('warning', sprintf('Ordine %d: lock della fotografia non ottenuto in 10 secondi, si fotografa lo stesso.', $order->get_id()));
        }
        try {
            $order->read_meta_data(true);
            $salvate = $order->get_meta(self::META_LICENZE);
            $salvate = is_array($salvate) ? $salvate : [];
            $nuove = 0;
            foreach ($rscu as $license) {
                $id = (int) $license->getId();
                if (!isset($salvate[$id])) {
                    $salvate[$id] = self::fotografa($order, $license, (string) $license->getDecryptedLicenseKey());
                    $nuove++;
                }
            }
            if (!$nuove) {
                return;
            }

            // WooCommerce scrive una meta per query, senza transazione: le chiavi nuove nell'ordine delle
            // update_meta_data, quelle esistenti nell'ordine di lettura. Prima cliente e fotografia,
            // poi tentativi, esito e stato: un'interruzione a metà lascia una fotografia senza stato,
            // che la riconciliazione riprende, mai uno stato senza fotografia.
            // Il destinatario si fotografa una volta sola, al primo invio dell'ordine.
            if (!$order->get_meta(self::META_CLIENTE)) {
                $sub = $order->get_customer_id() ? (string) get_user_meta($order->get_customer_id(), 'ri_oidc_sub', true) : '';
                $order->update_meta_data(self::META_CLIENTE, ['sub' => $sub !== '' ? $sub : null, 'email' => $order->get_billing_email()]);
            }
            ksort($salvate);
            $order->update_meta_data(self::META_LICENZE, $salvate);
            // Nello stesso salvataggio lo stato "da inviare": se il processo si ferma prima di
            // programmare l'invio, la riconciliazione lo trova in coda senza job.
            $revocato = (bool) $order->get_meta(self::META_REVOCATO);
            self::da_inviare($order, 'licenze_assegnate');
            if ($revocato) {
                // Ordine revocato prima che License Manager assegnasse queste licenze: si revocano anche loro.
                self::da_inviare($order, 'licenze_revocate');
            }
            self::salva_fotografia($order);
        } finally {
            if ($preso) {
                self::unlock($lock);
            }
        }

        self::accoda($order, 'licenze_assegnate', false);
        if ($revocato) {
            self::accoda($order, 'licenze_revocate', false);
        }
    }

    /** Stato "in coda" con i tentativi azzerati, da salvare insieme a fotografia o revoca. */
    private static function da_inviare(\WC_Order $order, string $evento): void
    {
        $order->update_meta_data(self::META_TENTATIVI . $evento, 0);
        self::segna($order, $evento, 'in_coda', [], 'in coda per l\'invio');
    }

    /** Il salvataggio con la fotografia: un errore SQL scriverebbe nel log la query con le chiavi complete. */
    private static function salva_fotografia(\WC_Order $order): void
    {
        global $wpdb;
        $prev = $wpdb->suppress_errors(true);
        try {
            $order->save();
        } finally {
            $wpdb->suppress_errors($prev);
        }
    }

    /** GET_LOCK con il prefisso delle tabelle nel nome (più siti sullo stesso server): '1', '0' o null. */
    private static function lock(string $nome, int $attesa)
    {
        global $wpdb;
        $preso = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', $wpdb->prefix . $nome, $attesa));
        return $preso === null ? null : (string) $preso;
    }

    private static function unlock(string $nome): void
    {
        global $wpdb;
        $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $wpdb->prefix . $nome));
    }

    /** Dati di una licenza come li riceve Raffaello Scuola, calcolati una volta sola. */
    private static function fotografa(\WC_Order $order, $license, string $chiave): array
    {
        $product_id = (int) $license->getProductId();
        $riga = 0;
        foreach ($order->get_items() as $item_id => $item) {
            if ((int) ($item->get_variation_id() ?: $item->get_product_id()) === $product_id) {
                $riga = (int) $item_id;
                break;
            }
        }
        $product = wc_get_product($product_id);

        // Scadenza: quella di License Manager (GMT); altrimenti pagamento + giorni di validità; altrimenti illimitata.
        $scade_il = null;
        if ($license->getExpiresAt()) {
            $scade_il = gmdate('Y-m-d\TH:i:s\Z', strtotime($license->getExpiresAt() . ' UTC'));
        } elseif ((int) $license->getValidFor() > 0) {
            $pagato = $order->get_date_paid() ?: $order->get_date_created();
            $scade_il = gmdate('Y-m-d\TH:i:s\Z', ($pagato ? $pagato->getTimestamp() : time()) + (int) $license->getValidFor() * DAY_IN_SECONDS);
        }

        return [
            'id'          => (int) $license->getId(),
            'chiave'      => $chiave,
            'riga_ordine' => $riga,
            'prodotto'    => [
                'id'   => $product_id,
                'sku'  => $product ? (string) $product->get_sku() : '',
                // Troncato: una licenza da sola non deve mai superare il limite di 64 KB del corpo.
                'nome' => $product ? mb_substr($product->get_name(), 0, 200) : '',
            ],
            'scade_il'    => $scade_il,
        ];
    }

    /**
     * Accoda l'invio di un evento per l'ordine, con i tentativi azzerati. Un invio
     * in attesa basta (manda tutte le licenze fotografate); uno in esecuzione no,
     * e se ne accoda un altro. Il reinvio manuale sostituisce quello in attesa.
     */
    public static function accoda(\WC_Order $order, string $evento, bool $sostituisci): void
    {
        $args = [$order->get_id(), $evento];
        if ($sostituisci) {
            as_unschedule_all_actions(self::AZIONE, $args, self::GRUPPO);
        } elseif (as_get_scheduled_actions(['hook' => self::AZIONE, 'args' => $args, 'group' => self::GRUPPO, 'status' => 'pending', 'per_page' => 1], 'ids')) {
            return;
        }
        self::da_inviare($order, $evento);
        $order->save();
        self::programma($order, $evento, self::ATTESE[0]);
    }

    /** Programma un tentativo; se Action Scheduler non lo accetta l'ordine risulta fallito. */
    private static function programma(\WC_Order $order, string $evento, int $attesa): void
    {
        if (as_schedule_single_action(time() + $attesa, self::AZIONE, [$order->get_id(), $evento], self::GRUPPO)) {
            return;
        }
        self::segna($order, $evento, 'fallito', ['action_scheduler'], 'Action Scheduler non ha programmato l\'invio');
        $order->save();
        $order->add_order_note(sprintf('Raffaello Scuola: Action Scheduler non ha programmato l\'invio (%s). Usa l\'azione "Reinvia licenze a Raffaello Scuola".', $evento));
        Log::write('error', sprintf('Ordine %d: Action Scheduler non ha programmato l\'invio (%s).', $order->get_id(), $evento));
    }

    /**
     * Job di Action Scheduler. Un invio per ordine alla volta, per tutti e due gli eventi:
     * con il lock di MySQL (un solo server di database), perché la deduplica di accoda()
     * guarda solo gli invii in attesa e non quello in esecuzione.
     */
    public static function invia($order_id, $evento): void
    {
        $order_id = (int) $order_id;
        $lock = 'rlb_ordine_' . $order_id;
        $preso = self::lock($lock, 0);
        // Senza lock non si scrive niente sull'ordine: un errore fa fallire l'azione di Action
        // Scheduler, l'ordine resta in coda senza job e lo riprende la riconciliazione.
        if ($preso === null) {
            Log::write('error', sprintf('Ordine %d, %s: lock del database non disponibile, invio non eseguito.', $order_id, $evento));
            throw new \RuntimeException(sprintf('Invio dell\'ordine %d non eseguito: lock del database non disponibile.', $order_id));
        }
        if ($preso !== '1') {
            // Ordine già in invio: si riprova fra un minuto, senza consumare tentativi e senza nota.
            if (!as_schedule_single_action(time() + 60, self::AZIONE, [$order_id, $evento], self::GRUPPO)) {
                Log::write('error', sprintf('Ordine %d, %s: ordine già in invio e nuovo tentativo non programmato.', $order_id, $evento));
                throw new \RuntimeException(sprintf('Invio dell\'ordine %d non riprogrammato.', $order_id));
            }
            return;
        }
        try {
            self::invia_ordine($order_id, $evento);
        } finally {
            self::unlock($lock);
        }
    }

    /**
     * Un tentativo di invio dei dati fotografati, a lotti uno dopo l'altro. Riesce solo
     * se riescono tutti; al tentativo successivo si rimanda tutto, tanto l'endpoint è idempotente.
     */
    private static function invia_ordine(int $order_id, string $evento): void
    {
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }
        // Dopo il lock si legge dal DB: questo processo può avere le meta in cache da un job precedente.
        $order->read_meta_data(true);
        $licenze = $order->get_meta(self::META_LICENZE);
        if (!is_array($licenze) || !$licenze) {
            return;
        }
        // Rimborso arrivato in una richiesta concorrente alla fotografia: ha visto l'ordine senza
        // licenze e non ha accodato la revoca. Il job gira in una richiesta successiva e la recupera.
        if ($evento === 'licenze_assegnate' && $order->get_meta(self::META_REVOCATO) && !self::esito($order, 'licenze_revocate')) {
            self::accoda($order, 'licenze_revocate', false);
        }

        $config = Config::current();
        if (!$config['ok']) {
            // Si aspetta la configurazione senza consumare tentativi; la nota una volta sola.
            if (!in_array('non_configurato', self::esito($order, $evento)['motivi'] ?? [], true)) {
                $order->add_order_note('Raffaello Scuola: invio rimandato, il collegamento non è configurato (WooCommerce > Raffaello Scuola). Riparte da solo dopo la configurazione.');
            }
            self::segna($order, $evento, 'in_coda', ['non_configurato'], 'collegamento non configurato, si riprova ogni ora');
            $order->save();
            self::programma($order, $evento, self::ATTESA_CONFIGURAZIONE);
            return;
        }

        $tentativo = (int) $order->get_meta(self::META_TENTATIVI . $evento) + 1;
        $order->update_meta_data(self::META_TENTATIVI . $evento, $tentativo);
        $order->save();

        $base = [
            'evento'  => $evento,
            'ordine'  => ['id' => $order->get_id(), 'numero' => (string) $order->get_order_number(), 'stato' => $order->get_status()],
            'cliente' => $order->get_meta(self::META_CLIENTE),
        ];
        $azione = $evento === 'licenze_revocate' ? 'revoca' : 'assegnazione';
        $lotti = self::lotti($base, array_values($licenze));
        $esiti = [];

        foreach ($lotti as $n => $lotto) {
            $risposta = Client::read(Client::post($config, wp_json_encode($base + ['licenze' => $lotto])));
            $parziali = $risposta['codice'] === 'ok' ? self::esiti($risposta['json'], array_column($lotto, 'id')) : null;
            if ($parziali !== null) {
                $esiti += $parziali;
                continue;
            }

            // Un 200 senza un esito per ogni licenza inviata non è un successo.
            if ($risposta['codice'] === 'ok') {
                $risposta = ['codice' => 'risposta_inattesa', 'testo' => 'HTTP 200 senza esiti validi', 'ritenta' => true];
            }
            self::ricorda($config, $risposta['codice'], $risposta['testo'], $order->get_id());
            $motivo = $risposta['testo'] . (count($lotti) > 1 ? sprintf(', invio %d di %d', $n + 1, count($lotti)) : '');
            Log::write('warning', sprintf('Ordine %d, %s: tentativo %d non riuscito (%s).', $order->get_id(), $azione, $tentativo, $motivo));
            if ($risposta['ritenta'] && $tentativo < count(self::ATTESE)) {
                self::segna($order, $evento, 'in_coda', [$risposta['codice']], sprintf('tentativo %d non riuscito (%s), si riprova', $tentativo, $motivo));
                $order->save();
                self::programma($order, $evento, self::ATTESE[$tentativo]);
                return;
            }
            self::segna($order, $evento, 'fallito', [$risposta['codice']], sprintf('tentativo %d: %s', $tentativo, $motivo));
            $order->save();
            $order->add_order_note(sprintf('Raffaello Scuola, %s fallita definitivamente al tentativo %d (%s). Usa l\'azione "Reinvia licenze a Raffaello Scuola" o "Rimetti in coda gli invii falliti" in WooCommerce > Raffaello Scuola.', $azione, $tentativo, $motivo));
            return;
        }
        self::ricorda($config, 'ok', 'HTTP 200', $order->get_id());

        // Riassunto: l'esito di ogni licenza fino a 10, altrimenti i conteggi.
        $righe = [];
        if (count($licenze) <= 10) {
            foreach ($licenze as $id => $l) {
                $righe[] = Log::mask($l['chiave']) . ' ' . $esiti[$id];
            }
        } else {
            foreach (array_count_values($esiti) as $esito => $quante) {
                $righe[] = $quante . ' ' . $esito;
            }
        }
        $motivi = self::motivi($licenze, $esiti);
        if ($motivi) {
            $stato = 'da_sistemare';
        } elseif ($evento === 'licenze_assegnate' && in_array('attivata', $esiti, true)) {
            $stato = 'attivato';
        } else {
            $stato = 'inviato';
        }
        $nota = sprintf(
            'Raffaello Scuola, %s %s (%d %s in %d %s): %s.',
            $azione,
            $motivi ? 'da sistemare' : 'riuscita',
            count($licenze),
            count($licenze) === 1 ? 'licenza' : 'licenze',
            count($lotti),
            count($lotti) === 1 ? 'invio' : 'invii',
            implode('; ', $righe)
        );
        foreach ($motivi as $motivo) {
            $nota .= ' ' . self::come_sistemare($motivo);
        }

        // Chiusura sotto il lock della fotografia: rilettura, confronto con le licenze mandate ed
        // esito finale non si incrociano con una fotografia nuova. Senza lock non si chiude: lo
        // stato resta "in coda" e si rimanda tutto, l'endpoint è idempotente.
        $lock = 'rlb_foto_' . $order->get_id();
        if (self::lock($lock, 10) !== '1') {
            Log::write('warning', sprintf('Ordine %d, %s: lock della fotografia non ottenuto in 10 secondi, invio riuscito ma da ripetere.', $order->get_id(), $azione));
            self::accoda($order, $evento, false);
            return;
        }
        try {
            $order->read_meta_data(true);
            $fotografia = $order->get_meta(self::META_LICENZE);
            // Licenze fotografate durante l'invio: lo stato resta "in coda" e si rimanda tutto.
            if (array_diff_key(is_array($fotografia) ? $fotografia : [], $licenze)) {
                Log::write('info', sprintf('Ordine %d, %s: licenze nuove arrivate durante l\'invio, si rimanda tutto.', $order->get_id(), $azione));
                self::accoda($order, $evento, false);
                return;
            }
            self::segna($order, $evento, $stato, $motivi, implode('; ', $righe));
            // Le riprese contano le interruzioni di fila: un invio arrivato a Scuola le azzera.
            $order->delete_meta_data(self::META_RIPRESE . $evento);
            $order->save();
        } finally {
            self::unlock($lock);
        }
        $order->add_order_note($nota);
        Log::write($motivi ? 'warning' : 'info', sprintf('Ordine %d, tentativo %d. %s', $order->get_id(), $tentativo, $nota));
    }

    /** Lotti entro i limiti dell'endpoint: al massimo 50 licenze e 64 KB di corpo. */
    private static function lotti(array $base, array $licenze): array
    {
        $lotti = [];
        $lotto = [];
        foreach ($licenze as $licenza) {
            $prova = array_merge($lotto, [$licenza]);
            if ($lotto && (count($prova) > self::LOTTO || strlen(wp_json_encode($base + ['licenze' => $prova])) > self::MAX_BYTE)) {
                $lotti[] = $lotto;
                $prova = [$licenza];
            }
            $lotto = $prova;
        }
        $lotti[] = $lotto;
        return $lotti;
    }

    /** Esiti per id licenza se la risposta ha un esito per ogni licenza inviata, altrimenti null. */
    private static function esiti(array $json, array $ids): ?array
    {
        if (!is_array($json['esiti'] ?? null)) {
            return null;
        }
        $esiti = [];
        foreach ($json['esiti'] as $e) {
            if (is_array($e) && isset($e['id'], $e['esito']) && is_string($e['esito'])) {
                $esiti[(int) $e['id']] = $e['esito'];
            }
        }
        foreach ($ids as $id) {
            if (!isset($esiti[$id])) {
                return null;
            }
        }
        return $esiti;
    }

    /**
     * Motivi per cui l'ordine va sistemato a mano. codice_libero conta solo se per
     * quello SKU nessuna licenza dell'ordine è attivata: l'acquirente è rimasto
     * senza accesso. Con quantità maggiore di 1 i codici liberi oltre il primo sono normali.
     */
    private static function motivi(array $licenze, array $esiti): array
    {
        // Ogni altro esito, anche uno che questa versione non conosce, va guardato.
        $motivi = array_values(array_unique(array_diff($esiti, self::RIUSCITI)));
        $per_sku = [];
        foreach ($licenze as $id => $l) {
            $per_sku[$l['prodotto']['sku']][] = $esiti[$id];
        }
        foreach ($per_sku as $e) {
            if (in_array('codice_libero', $e, true) && !in_array('attivata', $e, true)) {
                $motivi[] = 'codice_libero';
                break;
            }
        }
        return $motivi;
    }

    private static function come_sistemare(string $motivo): string
    {
        switch ($motivo) {
            case 'senza_destinatario':
                return 'Senza destinatario: il cliente non aveva un account Raffaello Identity collegato, il codice va riscattato a mano su Raffaello Scuola.';
            case 'in_attesa_configurazione':
                return 'In attesa di configurazione: su Raffaello Scuola manca la licenza con questo SKU; creata quella, l\'attivazione parte da sola e "Reinvia" aggiorna l\'esito qui.';
            case 'conflitto':
                return 'Conflitto: Raffaello Scuola ha già dati diversi per questa licenza e non ha cambiato niente; controlla in Licenze > Attivazioni.';
            case 'codice_libero':
                return 'Codice libero: l\'acquirente non ha ricevuto l\'attivazione perché il codice risulta già usato; controlla in Licenze > Attivazioni.';
            default:
                return sprintf('Esito "%s" sconosciuto a questa versione del plugin: controlla in Licenze > Attivazioni.', $motivo);
        }
    }

    /** Esito di un evento dell'ordine: stato, motivi, dettaglio, quando; [] se non c'è. */
    public static function esito(\WC_Order $order, string $evento): array
    {
        $esito = $order->get_meta(self::META_ESITO . $evento);
        return is_array($esito) ? $esito : [];
    }

    /** Solo le chiavi dell'evento: WooCommerce salva solo le meta cambiate, quelle dell'altro job restano sue. */
    private static function segna(\WC_Order $order, string $evento, string $stato, array $motivi, string $dettaglio): void
    {
        $order->update_meta_data(self::META_ESITO . $evento, ['stato' => $stato, 'motivi' => $motivi, 'dettaglio' => $dettaglio, 'quando' => time()]);
        $order->update_meta_data(self::META_STATO . $evento, $stato);
    }

    /** Esito dell'ultimo invio vero, per la checklist. */
    private static function ricorda(array $config, string $codice, string $testo, int $order_id): void
    {
        update_option(self::OPTION_ULTIMO, ['quando' => time(), 'codice' => $codice, 'testo' => $testo, 'ordine' => $order_id, 'impronta' => $config['impronta']], false);
    }

    /**
     * Ultimi 50 ordini nello stato dato, dal più recente, con il totale (->orders, ->total).
     * Per un ordine revocato conta la revoca: revocate le licenze, l'assegnazione non serve più.
     * Una ricerca per evento, perché search() non accetta OR.
     */
    public static function orders(string $stato)
    {
        $revoca = self::search([['key' => self::META_STATO . 'licenze_revocate', 'value' => $stato]], 50, 'DESC');
        $assegnazione = self::search([
            ['key' => self::META_STATO . 'licenze_assegnate', 'value' => $stato],
            ['key' => self::META_STATO . 'licenze_revocate', 'compare' => 'NOT EXISTS'],
        ], 50, 'DESC');
        // Le due ricerche non hanno ordini in comune (con e senza lo stato della revoca): i totali si sommano.
        $ids = array_merge($revoca->orders, $assegnazione->orders);
        rsort($ids);
        return (object) ['orders' => array_slice($ids, 0, 50), 'total' => $revoca->total + $assegnazione->total];
    }

    /** Programma la riconciliazione oraria, se manca (anche dopo che qualcuno l'ha cancellata). */
    public static function programma_riconciliazione(): void
    {
        if (as_next_scheduled_action(self::RICONCILIA, [], self::GRUPPO) === false) {
            as_schedule_recurring_action(time(), HOUR_IN_SECONDS, self::RICONCILIA, [], self::GRUPPO);
        }
    }

    /**
     * Job orario di Action Scheduler: riprende gli eventi in coda da più di 30 minuti senza
     * un'azione in attesa o in esecuzione (processo fermato prima di programmare l'invio, job
     * interrotto da un errore fatale o un timeout, lock del database), e le fotografie o le
     * revoche rimaste senza stato in un ordine di più di 30 minuti (salvataggio interrotto a
     * metà, ordini dello snippet). Dopo 3 riprese l'evento risulta fallito (motivo "interrotto").
     * 250 ordini per giro, cioè al massimo 500 coppie, in ordine di id: il giro dopo riparte
     * dall'ordine successivo all'ultimo visto e, finito l'elenco, dal primo.
     */
    public static function riconcilia(): void
    {
        // Una ricerca per caso, perché search() non accetta OR.
        $casi = [
            [['key' => self::META_STATO . 'licenze_assegnate', 'value' => 'in_coda']],
            [['key' => self::META_STATO . 'licenze_revocate', 'value' => 'in_coda']],
            // Fotografia senza stato.
            [
                ['key' => self::META_LICENZE, 'compare' => 'EXISTS'],
                ['key' => self::META_STATO . 'licenze_assegnate', 'compare' => 'NOT EXISTS'],
            ],
            // Revoca senza stato.
            [
                ['key' => self::META_REVOCATO, 'compare' => 'EXISTS'],
                ['key' => self::META_LICENZE, 'compare' => 'EXISTS'],
                ['key' => self::META_STATO . 'licenze_revocate', 'compare' => 'NOT EXISTS'],
            ],
        ];
        $ids = [];
        foreach ($casi as $condizioni) {
            $ids = array_merge($ids, self::search($condizioni, -1, 'ASC')->orders);
        }
        $ids = array_unique($ids);
        sort($ids);
        $cursore = (int) get_option(self::OPTION_CURSORE, 0);
        $restanti = array_values(array_filter($ids, function ($id) use ($cursore) {
            return $id > $cursore;
        }));
        // Dopo l'ultimo, anche perché nel frattempo l'elenco si è accorciato: subito dal primo.
        if (!$restanti) {
            $restanti = $ids;
        }
        $giro = array_slice($restanti, 0, 250);
        $riprese = 0;
        $fallite = 0;
        foreach ($giro as $id) {
            // Il lock dell'invio, senza attesa: se un job sta lavorando sull'ordine, al giro dopo.
            $lock = 'rlb_ordine_' . $id;
            if (self::lock($lock, 0) !== '1') {
                continue;
            }
            try {
                $order = wc_get_order($id);
                // Eliminato fra la ricerca e qui.
                if (!$order) {
                    continue;
                }
                $order->read_meta_data(true);
                foreach (self::EVENTI as $evento) {
                    $da = self::in_attesa_da($order, $evento);
                    if ($da === null || $da > time() - 30 * MINUTE_IN_SECONDS
                        || as_get_scheduled_actions(['hook' => self::AZIONE, 'args' => [$id, $evento], 'group' => self::GRUPPO, 'status' => ['pending', 'in-progress'], 'per_page' => 1], 'ids')) {
                        continue;
                    }
                    $volte = (int) $order->get_meta(self::META_RIPRESE . $evento);
                    if ($volte >= self::MAX_RIPRESE) {
                        self::segna($order, $evento, 'fallito', ['interrotto'], sprintf('invio interrotto %d volte', $volte + 1));
                        $order->save();
                        $order->add_order_note(sprintf('Raffaello Scuola: invio (%s) interrotto più di %d volte, per esempio da un errore fatale, un timeout o il lock del database. Corretta la causa, usa "Reinvia licenze a Raffaello Scuola" o "Rimetti in coda gli invii falliti" in WooCommerce > Raffaello Scuola.', $evento, self::MAX_RIPRESE));
                        Log::write('error', sprintf('Ordine %d, %s: invio interrotto più di %d volte, segnato fallito.', $id, $evento, self::MAX_RIPRESE));
                        $fallite++;
                        continue;
                    }
                    $order->update_meta_data(self::META_RIPRESE . $evento, $volte + 1);
                    $order->save();
                    self::accoda($order, $evento, false);
                    Log::write('warning', sprintf('Ordine %d, %s: invio rimasto senza job, ripreso (%d di %d).', $id, $evento, $volte + 1, self::MAX_RIPRESE));
                    $riprese++;
                }
            } finally {
                self::unlock($lock);
            }
        }
        update_option(self::OPTION_CURSORE, count($restanti) > count($giro) ? end($giro) : 0, false);
        update_option(self::OPTION_RICONCILIA, ['quando' => time(), 'riprese' => $riprese, 'fallite' => $fallite], false);
    }

    /** Da quando l'evento aspetta un invio: in coda dall'esito, senza stato dalla creazione dell'ordine; null se non aspetta. */
    private static function in_attesa_da(\WC_Order $order, string $evento): ?int
    {
        $stato = (string) $order->get_meta(self::META_STATO . $evento);
        if ($stato === 'in_coda') {
            return (int) (self::esito($order, $evento)['quando'] ?? 0);
        }
        if ($stato === '' && $order->get_meta(self::META_LICENZE) && ($evento === 'licenze_assegnate' || $order->get_meta(self::META_REVOCATO))) {
            // Senza data di creazione vale come vecchio.
            $creato = $order->get_date_created();
            return $creato ? $creato->getTimestamp() : 0;
        }
        return null;
    }

    /**
     * Ordini con tutte le condizioni sulle meta, per id nel verso dato, con il totale (->orders,
     * ->total), su HPOS e sull'archivio classico; $limit -1 per tutti. Solo AND: WooCommerce e
     * WordPress uniscono le meta di "=" ed EXISTS solo per ordine, e un OR fra due di queste unioni
     * moltiplica le meta di ogni ordine fra loro. Su un archivio grande la query non finisce.
     */
    private static function search(array $condizioni, int $limit, string $verso)
    {
        $args = [
            'type'     => 'shop_order',
            'limit'    => $limit,
            'orderby'  => 'ID',
            'order'    => $verso,
            'return'   => 'ids',
            'paginate' => true,
        ];
        // HPOS accetta meta_query; l'archivio classico (post) la ignora e restituirebbe tutti gli
        // ordini: lì la aggiunge cpt_query() da una variabile nostra.
        $hpos = class_exists(OrderUtil::class) && OrderUtil::custom_orders_table_usage_is_enabled();
        $args[$hpos ? 'meta_query' : 'rlb_stato'] = ['relation' => 'AND'] + $condizioni;
        return wc_get_orders($args);
    }

    /** Archivio ordini classico (post): la meta_query di search() passa da qui. */
    public static function cpt_query($wp_query_args, $query_vars)
    {
        if (isset($query_vars['rlb_stato'])) {
            $wp_query_args['meta_query'][] = $query_vars['rlb_stato'];
        }
        return $wp_query_args;
    }

    /** Rimette in coda gli ultimi 50 ordini falliti; restituisce quanti. */
    public static function requeue(): int
    {
        $ids = self::orders('fallito')->orders;
        foreach ($ids as $id) {
            $order = wc_get_order($id);
            if ($order) {
                self::reinvia($order);
            }
        }
        return count($ids);
    }

    /**
     * Azioni d'ordine nel menu "Azioni ordine": "Reinvia" per gli ordini con licenze RSCU,
     * "Recupera" anche per quelli con prodotti che vendono licenze, se License Manager c'è.
     */
    public static function azioni_ordine($actions, $order = null)
    {
        if (!$order instanceof \WC_Order) {
            return $actions;
        }
        $fotografia = (bool) $order->get_meta(self::META_LICENZE);
        if ($fotografia) {
            $actions['rscu_reinvia'] = 'Reinvia licenze a Raffaello Scuola';
        }
        if (class_exists(self::LM_LICENZE) && ($fotografia || self::ha_licenze($order))) {
            $actions['rscu_recupera'] = 'Recupera le licenze RSCU da License Manager';
        }
        return $actions;
    }

    /**
     * Azione dell'assistenza: rilegge da License Manager le licenze dell'ordine (la stessa ricerca
     * che fa prima del suo hook), fotografa le RSCU che mancano e rimette in coda l'invio di tutte.
     * Per il processo fermato fra la consegna di License Manager e la fotografia (il suo hook non
     * ripassa) e per le vendite fatte prima del plugin. Non lancia l'hook di License Manager:
     * GreenBubble manderebbe le chiavi a Raffaello Digitale.
     */
    public static function recupera($order): void
    {
        $fotografia = $order->get_meta(self::META_LICENZE);
        $prima = is_array($fotografia) ? count($fotografia) : 0;
        $repository = self::LM_LICENZE;
        $licenze = $repository::instance()->findAllBy(['order_id' => $order->get_id()]);
        self::licenze_assegnate(['orderId' => $order->get_id(), 'licenses' => $licenze ?: []]);
        $order->read_meta_data(true);
        if (!$order->get_meta(self::META_LICENZE)) {
            $order->add_order_note('Raffaello Scuola: License Manager non ha licenze RSCU per questo ordine, niente da mandare.');
            return;
        }
        $nuove = count($order->get_meta(self::META_LICENZE)) - $prima;
        $order->add_order_note($nuove
            ? sprintf('Raffaello Scuola: %d %s da License Manager.', $nuove, $nuove === 1 ? 'licenza RSCU recuperata' : 'licenze RSCU recuperate')
            : 'Raffaello Scuola: nessuna licenza RSCU nuova in License Manager, tutte già fotografate.');
        self::reinvia($order);
    }

    /** Rimette in coda gli stessi dati fotografati, e la revoca se l'ordine è stato revocato; azzera le riprese. */
    public static function reinvia($order): void
    {
        foreach (self::EVENTI as $evento) {
            $order->update_meta_data(self::META_RIPRESE . $evento, 0);
        }
        self::accoda($order, 'licenze_assegnate', true);
        if ($order->get_meta(self::META_REVOCATO)) {
            self::accoda($order, 'licenze_revocate', true);
        }
        $order->add_order_note('Raffaello Scuola: licenze rimesse in coda per l\'invio.');
        Checks::invalidate();
    }

    /**
     * Rimborso totale o annullamento: le licenze già fotografate si revocano ora; la
     * revoca si registra anche prima che License Manager le assegni, se l'ordine ha
     * prodotti che vendono licenze.
     */
    public static function ordine_revocato($order_id, $order = null): void
    {
        $order = $order instanceof \WC_Order ? $order : wc_get_order($order_id);
        if (!$order) {
            return;
        }
        // Lo stesso lock della fotografia: marcatore, rilettura e decisione non si incrociano con lei.
        $lock = 'rlb_foto_' . $order->get_id();
        $preso = self::lock($lock, 10) === '1';
        if (!$preso) {
            Log::write('warning', sprintf('Ordine %d: lock della fotografia non ottenuto in 10 secondi, si registra la revoca lo stesso.', $order->get_id()));
        }
        try {
            $order->read_meta_data(true);
            $con_foto = (bool) $order->get_meta(self::META_LICENZE);
            // Con la fotografia basta quella: il prodotto può essere stato eliminato o cambiato dopo la vendita.
            if (!$con_foto && !self::ha_licenze($order)) {
                return;
            }
            $order->update_meta_data(self::META_REVOCATO, time());
            if ($con_foto) {
                self::da_inviare($order, 'licenze_revocate');
            }
            $order->save();
        } finally {
            if ($preso) {
                self::unlock($lock);
            }
        }
        if ($con_foto) {
            self::accoda($order, 'licenze_revocate', false);
        }
    }

    /** Almeno un prodotto (o variazione) che vende licenze, con lo stesso controllo di License Manager. */
    private static function ha_licenze(\WC_Order $order): bool
    {
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if ($product && $product->get_meta('lmfwc_licensed_product', true)) {
                return true;
            }
        }
        return false;
    }

    /** Rimborso parziale: non si sa quali licenze togliere, si lascia la decisione all'assistenza. */
    public static function rimborso_parziale($order_id, $refund_id): void
    {
        $order = wc_get_order($order_id);
        if ($order && $order->get_meta(self::META_LICENZE)) {
            $order->add_order_note('Rimborso parziale: su Raffaello Scuola, per le licenze rimborsate, revocare l\'attivazione dei codici già riscattati (Licenze > Attivazioni, ricerca per ordine) e disattivare quelli ancora liberi (Licenze > Codici), altrimenti restano riscattabili.');
        }
    }
}
