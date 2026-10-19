<?php

namespace RaffaelloLicenzeBookshop;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Pagina WooCommerce > Raffaello Scuola: checklist, collegamento (URL e
 * segreto), prova di connessione, invii falliti e verifica di GreenBubble.
 * Avviso in admin per i soli problemi bloccanti, senza chiusura: sparisce da
 * solo quando il problema è risolto. Tutte le azioni richiedono manage_options
 * e un nonce.
 */
class Admin
{
    const CAP = 'manage_options';
    const PAGE = 'rlb-scuola';

    /** Codici dei messaggi dopo un'azione (?rlb_msg=). */
    const MESSAGGI = ['salvato', 'url_formato', 'url_https', 'url_percorso', 'url_stesso_sito', 'segreto_non_valido', 'non_salvato', 'ping', 'rimessi', 'confermato', 'conferma_cambiata'];

    public static function register(): void
    {
        add_action('admin_menu', [self::class, 'menu'], 60);
        add_action('admin_notices', [self::class, 'notices']);
        add_action('admin_post_rlb_salva', [self::class, 'action_save']);
        add_action('admin_post_rlb_ping', [self::class, 'action_ping']);
        add_action('admin_post_rlb_rimetti', [self::class, 'action_requeue']);
        add_action('admin_post_rlb_conferma', [self::class, 'action_confirm']);
    }

    public static function url(): string
    {
        return admin_url('admin.php?page=' . self::PAGE);
    }

    public static function menu(): void
    {
        // Senza WooCommerce la pagina va sotto Impostazioni, per dire che cosa manca.
        add_submenu_page(
            class_exists('WooCommerce') ? 'woocommerce' : 'options-general.php',
            __('Raffaello Licenze — Bookshop', 'raffaello-licenze-bookshop'),
            __('Raffaello Scuola', 'raffaello-licenze-bookshop'),
            self::CAP,
            self::PAGE,
            [self::class, 'page']
        );
    }

    /** Problemi bloccanti in Bacheca e nelle pagine WooCommerce; non in quella del plugin, che ha la checklist. */
    public static function notices(): void
    {
        $screen = get_current_screen();
        if (!$screen || !current_user_can(self::CAP)) {
            return;
        }
        $woocommerce = function_exists('wc_get_screen_ids') ? wc_get_screen_ids() : [];
        // La pagina del plugin non è fra le schermate di WooCommerce: lì basta la checklist.
        if ($screen->id !== 'dashboard' && !in_array($screen->id, $woocommerce, true)) {
            return;
        }
        $bloccanti = Checks::blocking();
        if (!$bloccanti) {
            return;
        }
        echo '<div class="notice notice-error"><p><strong>' . esc_html__('Raffaello Licenze — Bookshop: le licenze RSCU non arrivano a Raffaello Scuola come dovrebbero.', 'raffaello-licenze-bookshop') . '</strong></p><ul>';
        foreach ($bloccanti as $v) {
            printf('<li><strong>%s</strong>: %s</li>', esc_html($v['titolo']), esc_html($v['testo']));
        }
        printf('</ul><p><a href="%s">%s</a></p></div>', esc_url(self::url()), esc_html__('Apri la checklist', 'raffaello-licenze-bookshop'));
    }

    /* ----------------------------------------------------------------------
     * Azioni
     * -------------------------------------------------------------------- */

    public static function action_save(): void
    {
        self::guard('rlb_salva');
        self::back(Config::save(
            wp_unslash((string) ($_POST['url'] ?? '')),
            wp_unslash((string) ($_POST['secret'] ?? '')),
            !empty($_POST['cancella_segreto'])
        ));
    }

    public static function action_ping(): void
    {
        self::guard('rlb_ping');
        if (Config::current()['ok']) {
            Ping::run();
        }
        self::back('ping');
    }

    public static function action_requeue(): void
    {
        self::guard('rlb_rimetti');
        self::back('rimessi', ['rlb_n' => Plugin::active() ? Sender::requeue() : 0]);
    }

    public static function action_confirm(): void
    {
        self::guard('rlb_conferma');
        self::back(GreenBubble::confirm(sanitize_text_field(wp_unslash((string) ($_POST['hash'] ?? '')))) ? 'confermato' : 'conferma_cambiata');
    }

    private static function guard(string $azione): void
    {
        if (!current_user_can(self::CAP)) {
            wp_die(esc_html__('Non hai i permessi per questa azione.', 'raffaello-licenze-bookshop'), 403);
        }
        check_admin_referer($azione);
    }

    private static function back(string $messaggio, array $extra = []): void
    {
        wp_safe_redirect(add_query_arg(['rlb_msg' => $messaggio] + $extra, self::url()));
        exit;
    }

    /* ----------------------------------------------------------------------
     * Pagina
     * -------------------------------------------------------------------- */

    public static function page(): void
    {
        if (!current_user_can(self::CAP)) {
            return;
        }
        $config = Config::current();
        echo '<div class="wrap"><h1>' . esc_html__('Raffaello Licenze — Bookshop', 'raffaello-licenze-bookshop') . '</h1>';
        echo '<p>' . esc_html__('Manda a Raffaello Scuola le licenze RSCU vendute qui, per attivarle sul profilo Raffaello Identity di chi compra. Istruzioni complete nel README del plugin.', 'raffaello-licenze-bookshop') . '</p>';
        self::message();
        self::checklist(Checks::run());
        self::connection_form($config);
        self::ping_box($config);
        echo '</div>';
    }

    private static function message(): void
    {
        $codice = isset($_GET['rlb_msg']) ? sanitize_key(wp_unslash($_GET['rlb_msg'])) : '';
        if (!in_array($codice, self::MESSAGGI, true)) {
            return;
        }
        switch ($codice) {
            case 'salvato':
                $testo = __('Impostazioni salvate.', 'raffaello-licenze-bookshop');
                break;
            case 'ping':
                $testo = __('Prova eseguita: l\'esito è qui sotto.', 'raffaello-licenze-bookshop');
                break;
            case 'rimessi':
                $n = isset($_GET['rlb_n']) ? (int) $_GET['rlb_n'] : 0;
                /* translators: %d: numero di ordini */
                $testo = sprintf(_n('%d ordine rimesso in coda.', '%d ordini rimessi in coda.', $n, 'raffaello-licenze-bookshop'), $n);
                break;
            case 'confermato':
                $testo = __('Verifica registrata.', 'raffaello-licenze-bookshop');
                break;
            case 'conferma_cambiata':
                $testo = __('Gli snippet sono cambiati mentre la pagina era aperta: ricontrolla e conferma di nuovo.', 'raffaello-licenze-bookshop');
                break;
            default:
                /* translators: %s: motivo */
                $testo = sprintf(__('Impostazioni non salvate: %s', 'raffaello-licenze-bookshop'), Config::error_text($codice));
        }
        $errore = in_array($codice, ['conferma_cambiata', 'url_formato', 'url_https', 'url_percorso', 'url_stesso_sito', 'segreto_non_valido', 'non_salvato'], true);
        printf('<div class="notice notice-%s"><p>%s</p></div>', $errore ? 'error' : 'success', esc_html($testo));
    }

    private static function checklist(array $voci): void
    {
        $icone = [
            'ok'          => ['yes-alt', '#00a32a', __('OK', 'raffaello-licenze-bookshop')],
            'bloccante'   => ['dismiss', '#d63638', __('Bloccante', 'raffaello-licenze-bookshop')],
            'attenzione'  => ['warning', '#dba617', __('Attenzione', 'raffaello-licenze-bookshop')],
            'informativo' => ['info', '#72aee6', __('Informativo', 'raffaello-licenze-bookshop')],
        ];
        echo '<h2>' . esc_html__('Controlli', 'raffaello-licenze-bookshop') . '</h2>';
        echo '<table class="widefat striped"><tbody>';
        foreach ($voci as $v) {
            [$icona, $colore, $etichetta] = $icone[$v['esito'] === 'ok' ? 'ok' : $v['gravita']];
            if ($v['esito'] === 'non_verificabile') {
                $icona = 'editor-help';
                $etichetta = __('Non verificabile', 'raffaello-licenze-bookshop');
            }
            printf(
                '<tr><td style="width:150px;white-space:nowrap"><span class="dashicons dashicons-%s" style="color:%s"></span> %s</td><td style="width:280px"><strong>%s</strong> <code>%s</code></td><td>%s',
                esc_attr($icona),
                esc_attr($colore),
                esc_html($etichetta),
                esc_html($v['titolo']),
                esc_html($v['id']),
                esc_html($v['testo'])
            );
            self::order_links($v);
            if (in_array($v['id'], ['B6', 'B15'], true) && $v['ordini']) {
                self::button('rlb_rimetti', __('Rimetti in coda gli invii falliti e interrotti', 'raffaello-licenze-bookshop'));
            }
            if (isset($v['conferma'])) {
                self::button('rlb_conferma', __('Ho verificato l\'esclusione delle RSCU nello snippet GreenBubble', 'raffaello-licenze-bookshop'), ['hash' => $v['conferma']]);
            }
            echo '</td></tr>';
        }
        echo '</tbody></table>';
    }

    /** Link agli ordini da guardare (al massimo 50), con l'esito per gli ordini da sistemare. */
    private static function order_links(array $voce): void
    {
        if (!$voce['ordini']) {
            return;
        }
        $link = [];
        foreach ($voce['ordini'] as $id) {
            $order = wc_get_order($id);
            if (!$order) {
                continue;
            }
            $motivi = [];
            foreach (['licenze_assegnate', 'licenze_revocate'] as $evento) {
                $motivi = array_merge($motivi, Sender::esito($order, $evento)['motivi'] ?? []);
            }
            $link[] = sprintf('<a href="%s">#%s</a>%s', esc_url($order->get_edit_order_url()), esc_html($order->get_order_number()), $motivi && $voce['id'] !== 'B13' ? ' (' . esc_html(implode(', ', array_unique($motivi))) . ')' : '');
        }
        echo '<br>' . implode(', ', $link);
    }

    private static function button(string $azione, string $testo, array $campi = []): void
    {
        printf('<form method="post" action="%s" style="margin-top:8px">', esc_url(admin_url('admin-post.php')));
        printf('<input type="hidden" name="action" value="%s">', esc_attr($azione));
        foreach ($campi as $nome => $valore) {
            printf('<input type="hidden" name="%s" value="%s">', esc_attr($nome), esc_attr($valore));
        }
        wp_nonce_field($azione);
        submit_button($testo, 'secondary', 'submit', false);
        echo '</form>';
    }

    private static function connection_form(array $config): void
    {
        $saved = Config::saved();
        $url_costante = defined('RAFFAELLO_SCUOLA_URL');
        $secret_costante = defined('RAFFAELLO_SCUOLA_SECRET');
        $secret_salvato = (string) ($saved['secret'] ?? '');

        echo '<h2>' . esc_html__('Collegamento', 'raffaello-licenze-bookshop') . '</h2>';
        printf('<form method="post" action="%s"><input type="hidden" name="action" value="rlb_salva">', esc_url(admin_url('admin-post.php')));
        wp_nonce_field('rlb_salva');
        echo '<table class="form-table"><tr><th><label for="rlb-url">' . esc_html__('URL di Raffaello Scuola', 'raffaello-licenze-bookshop') . '</label></th><td>';
        printf(
            '<input type="url" id="rlb-url" name="url" class="regular-text" value="%s" placeholder="https://www.raffaelloscuola.it" %s>',
            esc_attr($url_costante ? (string) RAFFAELLO_SCUOLA_URL : (string) ($saved['url'] ?? '')),
            $url_costante ? 'readonly' : ''
        );
        echo '<p class="description">' . ($url_costante
            ? esc_html__('Gestito da wp-config.php (RAFFAELLO_SCUOLA_URL).', 'raffaello-licenze-bookshop')
            : esc_html__('Solo schema e host, senza percorso. Dev e produzione hanno ciascuno il proprio.', 'raffaello-licenze-bookshop')) . '</p>';
        echo '</td></tr><tr><th><label for="rlb-secret">' . esc_html__('Segreto condiviso', 'raffaello-licenze-bookshop') . '</label></th><td>';
        if ($secret_costante) {
            $placeholder = __('Gestito da wp-config.php', 'raffaello-licenze-bookshop');
        } elseif ($secret_salvato !== '') {
            /* translators: %d: lunghezza del segreto */
            $placeholder = sprintf(__('Impostato (%d caratteri): lascia vuoto per non cambiarlo', 'raffaello-licenze-bookshop'), strlen($secret_salvato));
        } else {
            $placeholder = __('Incolla il segreto generato su Raffaello Scuola', 'raffaello-licenze-bookshop');
        }
        // Il segreto non si ristampa mai: il campo parte vuoto.
        printf('<input type="password" id="rlb-secret" name="secret" class="regular-text" value="" autocomplete="new-password" placeholder="%s" %s>', esc_attr($placeholder), $secret_costante ? 'readonly' : '');
        if ($secret_costante) {
            echo '<p class="description">' . esc_html__('Gestito da wp-config.php (RAFFAELLO_SCUOLA_SECRET).', 'raffaello-licenze-bookshop') . '</p>';
        } else {
            if ($secret_salvato !== '') {
                echo '<p><label><input type="checkbox" name="cancella_segreto" value="1"> ' . esc_html__('Cancella il segreto salvato', 'raffaello-licenze-bookshop') . '</label></p>';
            }
            echo '<p class="description">' . esc_html__('Lo stesso di Raffaello Scuola: si genera lì (Licenze > Impostazioni > Genera segreto) e si incolla qui. Almeno 32 caratteri.', 'raffaello-licenze-bookshop') . '</p>';
        }
        echo '</td></tr></table>';
        submit_button(__('Salva', 'raffaello-licenze-bookshop'));
        echo '</form>';
    }

    private static function ping_box(array $config): void
    {
        echo '<h2>' . esc_html__('Prova connessione', 'raffaello-licenze-bookshop') . '</h2>';
        echo '<p>' . esc_html__('Una chiamata firmata a Raffaello Scuola, dallo stesso server e con lo stesso client degli invii veri: prova URL, segreto, orologio e filtri come Cloudflare, e confronta gli SKU dei prodotti RSCU pubblicati. Non tocca licenze né attivazioni su Scuola: lì si registra solo l\'ultima richiesta ricevuta.', 'raffaello-licenze-bookshop') . '</p>';

        $ping = get_option(Ping::OPTION);
        if (is_array($ping)) {
            $riuscita = $ping['codice'] === 'ok';
            /* translators: 1: data, 2: URL */
            echo '<p><strong>' . esc_html(sprintf(__('Ultima prova: %1$s verso %2$s.', 'raffaello-licenze-bookshop'), Checks::date($ping['quando']), $ping['destinazione'])) . '</strong> ';
            if ($riuscita) {
                /* translators: 1: versione, 2: SKU inviati */
                echo esc_html(sprintf(_n('Riuscita: Raffaello Scuola %1$s, %2$d SKU confrontato.', 'Riuscita: Raffaello Scuola %1$s, %2$d SKU confrontati.', $ping['sku_inviati'], 'raffaello-licenze-bookshop'), $ping['versione'], $ping['sku_inviati']));
                if ($ping['sku_sconosciuti']) {
                    /* translators: %s: elenco di SKU */
                    echo ' ' . esc_html(sprintf(__('SKU sconosciuti a Scuola: %s.', 'raffaello-licenze-bookshop'), implode(', ', $ping['sku_sconosciuti'])));
                }
                if ($ping['scarto'] !== null && abs($ping['scarto']) > 60) {
                    /* translators: %d: secondi */
                    echo ' ' . esc_html(sprintf(__('Gli orologi dei due server differiscono di %d secondi: oltre 300 le firme vengono rifiutate.', 'raffaello-licenze-bookshop'), $ping['scarto']));
                }
            } else {
                /* translators: %s: esito */
                echo esc_html(sprintf(__('Non riuscita (%s).', 'raffaello-licenze-bookshop'), $ping['testo'])) . ' ' . esc_html(Client::help($ping['codice']));
            }
            echo '</p>';
            if (!Ping::last($config)) {
                echo '<p>' . esc_html__('URL o segreto sono cambiati dopo questa prova: ripetila.', 'raffaello-licenze-bookshop') . '</p>';
            }
        }
        if (!$config['ok']) {
            echo '<p>' . esc_html__('Imposta URL e segreto per provare il collegamento.', 'raffaello-licenze-bookshop') . '</p>';
            return;
        }
        self::button('rlb_ping', __('Prova connessione', 'raffaello-licenze-bookshop'));
    }
}
