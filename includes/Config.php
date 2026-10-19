<?php

namespace RaffaelloLicenzeBookshop;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Collegamento con Raffaello Scuola: URL e segreto condiviso.
 *
 * Le costanti RAFFAELLO_SCUOLA_URL e RAFFAELLO_SCUOLA_SECRET di wp-config.php
 * vincono; una costante definita ma vuota rende la configurazione non valida,
 * senza ripiego sul DB. Altrimenti vale l'opzione rlb_impostazioni (autoload
 * no), solo sul sito che l'ha salvata: una copia del DB su un altro host
 * risulta non configurata.
 */
class Config
{
    const OPTION = 'rlb_impostazioni';
    const MIN_SEGRETO = 32;

    /**
     * Configurazione in uso: url e secret (vuoti se non validi), fonte di ciascuno
     * ('costante', 'impostazioni' o ''), codice d'errore di ciascuno o null,
     * host di un'opzione salvata su un altro sito, ok e impronta (cambia quando
     * cambiano URL o segreto).
     */
    public static function current(): array
    {
        $saved = get_option(self::OPTION, []);
        $altro_host = is_array($saved) && isset($saved['host']) && $saved['host'] !== self::site_origin() ? (string) $saved['host'] : null;
        $db = self::saved();

        $c = ['altro_host' => $altro_host];
        if (defined('RAFFAELLO_SCUOLA_URL')) {
            [$url, $errore] = self::normalize_url((string) RAFFAELLO_SCUOLA_URL);
            $c += ['url' => (string) $url, 'fonte_url' => 'costante', 'errore_url' => (string) RAFFAELLO_SCUOLA_URL === '' ? 'vuota' : $errore];
        } elseif ((string) ($db['url'] ?? '') !== '') {
            [$url, $errore] = self::normalize_url((string) $db['url']);
            $c += ['url' => (string) $url, 'fonte_url' => 'impostazioni', 'errore_url' => $errore];
        } else {
            $c += ['url' => '', 'fonte_url' => '', 'errore_url' => 'mancante'];
        }

        if (defined('RAFFAELLO_SCUOLA_SECRET')) {
            $secret = (string) RAFFAELLO_SCUOLA_SECRET;
            $c['fonte_secret'] = 'costante';
            $c['errore_secret'] = $secret === '' ? 'vuota' : null;
        } else {
            $secret = (string) ($db['secret'] ?? '');
            $c['fonte_secret'] = $secret !== '' ? 'impostazioni' : '';
            $c['errore_secret'] = $secret === '' ? 'mancante' : null;
        }
        if ($c['errore_secret'] === null && strlen($secret) < self::MIN_SEGRETO) {
            $c['errore_secret'] = 'corto';
        }
        $c['secret'] = $c['errore_secret'] === null ? $secret : '';

        $c['ok'] = $c['errore_url'] === null && $c['errore_secret'] === null;
        // Impronta senza il segreto in chiaro: invalida gli esiti salvati quando cambia la configurazione.
        $c['impronta'] = $c['ok'] ? hash_hmac('sha256', $c['url'], $c['secret']) : '';
        return $c;
    }

    /**
     * Salva dal modulo delle impostazioni i campi non gestiti da una costante.
     * Segreto vuoto = si tiene quello salvato; per toglierlo serve $cancella_segreto.
     * Restituisce il codice del messaggio da mostrare.
     */
    public static function save(string $url, string $secret, bool $cancella_segreto): string
    {
        // I valori salvati su un altro sito (DB copiato) non si conservano.
        $saved = self::saved();
        $nuovo = ['url' => (string) ($saved['url'] ?? ''), 'secret' => (string) ($saved['secret'] ?? ''), 'host' => self::site_origin()];

        if (!defined('RAFFAELLO_SCUOLA_URL')) {
            $url = trim($url);
            [$norm, $errore] = $url === '' ? ['', null] : self::normalize_url($url);
            if ($errore !== null) {
                return $errore;
            }
            $nuovo['url'] = $norm;
        }
        if (!defined('RAFFAELLO_SCUOLA_SECRET')) {
            $secret = trim($secret);
            if ($cancella_segreto) {
                $nuovo['secret'] = '';
            } elseif ($secret !== '') {
                if (strlen($secret) < self::MIN_SEGRETO || preg_match('/\s/', $secret)) {
                    return 'segreto_non_valido';
                }
                $nuovo['secret'] = $secret;
            }
        }
        // Un errore del DB finirebbe nel log con la query intera, segreto compreso.
        global $wpdb;
        $prev = $wpdb->suppress_errors(true);
        update_option(self::OPTION, $nuovo, false);
        $wpdb->suppress_errors($prev);
        // update_option è falso anche a valore invariato: si controlla quello che c'è davvero.
        $letto = self::saved();
        return ($letto['url'] ?? null) === $nuovo['url'] && ($letto['secret'] ?? null) === $nuovo['secret'] ? 'salvato' : 'non_salvato';
    }

    /** Opzione salvata da questo sito (url, secret, host), o [] se manca o è di un altro host. */
    public static function saved(): array
    {
        $saved = get_option(self::OPTION, []);
        return is_array($saved) && ($saved['host'] ?? null) === self::site_origin() ? $saved : [];
    }

    /** [schema://host[:porta], null] oppure [null, codice d'errore]. */
    public static function normalize_url(string $url): array
    {
        $p = wp_parse_url(trim($url));
        if (!is_array($p) || empty($p['scheme']) || empty($p['host'])) {
            return [null, 'url_formato'];
        }
        $schema = strtolower($p['scheme']);
        // http solo in un ambiente "local" (prove in locale), come le Application Passwords del core.
        if ($schema !== 'https' && !($schema === 'http' && wp_get_environment_type() === 'local')) {
            return [null, 'url_https'];
        }
        if (isset($p['user']) || isset($p['pass']) || isset($p['query']) || isset($p['fragment']) || trim($p['path'] ?? '', '/') !== '') {
            return [null, 'url_percorso'];
        }
        $origine = strtolower($p['host']) . (isset($p['port']) ? ':' . $p['port'] : '');
        if ($origine === self::site_origin()) {
            return [null, 'url_stesso_sito'];
        }
        return [$schema . '://' . $origine, null];
    }

    /** Host di home_url(), con la porta se c'è. */
    public static function site_origin(): string
    {
        $p = wp_parse_url(home_url());
        return strtolower($p['host'] ?? '') . (isset($p['port']) ? ':' . $p['port'] : '');
    }

    public static function error_text(string $codice): string
    {
        switch ($codice) {
            case 'url_formato':
                return __('URL non valido: serve l\'indirizzo completo, per esempio https://www.raffaelloscuola.it.', 'raffaello-licenze-bookshop');
            case 'url_https':
                return __('L\'URL deve iniziare con https://.', 'raffaello-licenze-bookshop');
            case 'url_percorso':
                return __('Solo schema e host, senza percorso, parametri o credenziali: per esempio https://www.raffaelloscuola.it.', 'raffaello-licenze-bookshop');
            case 'url_stesso_sito':
                return __('L\'URL è quello di questo sito: serve quello di Raffaello Scuola.', 'raffaello-licenze-bookshop');
            case 'vuota':
                return __('La costante in wp-config.php è definita ma vuota: la configurazione non è valida e non si usa quella delle impostazioni.', 'raffaello-licenze-bookshop');
            case 'non_salvato':
                return __('riprova.', 'raffaello-licenze-bookshop');
            case 'corto':
            case 'segreto_non_valido':
                /* translators: %d: lunghezza minima del segreto */
                return sprintf(__('Il segreto deve avere almeno %d caratteri, senza spazi.', 'raffaello-licenze-bookshop'), self::MIN_SEGRETO);
            default:
                return __('Non configurato.', 'raffaello-licenze-bookshop');
        }
    }
}
