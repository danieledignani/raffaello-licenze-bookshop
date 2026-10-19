<?php

namespace RaffaelloLicenzeBookshop;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Chiamate firmate a Raffaello Scuola, le stesse per gli invii e per la prova
 * di connessione: POST /wp-json/raffaello-licenze/v1/eventi con le intestazioni
 * X-Raffaello-Timestamp e X-Raffaello-Signature (HMAC-SHA256 di timestamp +
 * "." + corpo).
 */
class Client
{
    const PATH = '/wp-json/raffaello-licenze/v1/eventi';
    const TIMEOUT = 15;

    /**
     * Codici che, negli invii, si ritentano con il solito intervallo: rete, configurazione e
     * risposte inattese (una pagina di manutenzione o di cache). Definitivo resta il 400.
     */
    const DA_RITENTARE = ['dns', 'tls', 'timeout', 'bloccata', 'rete', 'cloudflare', 'firma', 'vietato', 'non_trovato', 'reindirizzamento', 'scuola_non_configurata', 'occupato', 'errore_server', 'risposta_inattesa'];

    /** @return array|\WP_Error */
    public static function post(array $config, string $body)
    {
        $timestamp = (string) time();
        return wp_remote_post($config['url'] . self::PATH, [
            'timeout'     => self::TIMEOUT,
            // Seguendo un reindirizzamento la POST diventerebbe una GET: meglio vederlo.
            'redirection' => 0,
            'user-agent'  => 'RaffaelloBookshop-Licenze/' . RLB_VERSION . ' (+' . home_url('/') . ')',
            'headers'     => [
                'Content-Type'          => 'application/json',
                'X-Raffaello-Timestamp' => $timestamp,
                'X-Raffaello-Signature' => 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $body, $config['secret']),
            ],
            'body'        => $body,
        ]);
    }

    /**
     * Lettura di una risposta: codice, testo breve per note e log, stato HTTP,
     * JSON e se un invio va ritentato. "ok" vuol dire 200 con JSON e ok: true;
     * il resto del contenuto lo controlla il chiamante.
     */
    public static function read($response): array
    {
        if (is_wp_error($response)) {
            $msg = $response->get_error_message();
            if ($response->get_error_code() === 'http_request_not_executed') {
                $codice = 'bloccata';
            } elseif (preg_match('/cURL error 6:|resolve host/i', $msg)) {
                $codice = 'dns';
            } elseif (preg_match('/cURL error 28:|timed out/i', $msg)) {
                $codice = 'timeout';
            } elseif (preg_match('/SSL|TLS|certificate|cURL error (35|51|58|60):/i', $msg)) {
                $codice = 'tls';
            } else {
                $codice = 'rete';
            }
            return self::result($codice, $codice . ': ' . $msg, 0);
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $body = (string) wp_remote_retrieve_body($response);
        $json = json_decode($body, true);
        $json = is_array($json) ? $json : null;

        if (self::cloudflare($response, $status, $body)) {
            return self::result('cloudflare', "HTTP $status, challenge di Cloudflare", $status, $json);
        }
        if ($status === 200) {
            return ($json['ok'] ?? null) === true
                ? self::result('ok', 'HTTP 200', 200, $json)
                : self::result('risposta_inattesa', 'HTTP 200 senza JSON valido', 200, $json);
        }
        $errore_wp = (string) ($json['code'] ?? '');
        if ($status === 401) {
            $testo = 'HTTP 401, firma o ora non accettate';
            // Lo scarto d'orologio si vede dall'intestazione Date di Scuola.
            $ora = strtotime(self::header($response, 'date'));
            if ($ora && abs(time() - $ora) > 300) {
                $testo .= sprintf(', orologi sfasati di %d s', time() - $ora);
            }
            return self::result('firma', $testo, 401, $json);
        }
        if ($status === 403) {
            return self::result('vietato', 'HTTP 403, accesso negato', 403, $json);
        }
        if ($status === 404) {
            return self::result('non_trovato', 'HTTP 404, rotta non trovata', 404, $json);
        }
        if ($status >= 300 && $status < 400) {
            return self::result('reindirizzamento', "HTTP $status verso " . self::header($response, 'location'), $status, $json);
        }
        if ($status === 503 && $errore_wp === 'rcl_non_configurato') {
            return self::result('scuola_non_configurata', 'HTTP 503, Raffaello Scuola non configurata', 503, $json);
        }
        if ($status === 503 && $errore_wp === 'rcl_occupato') {
            return self::result('occupato', 'HTTP 503, ordine già in elaborazione su Raffaello Scuola', 503, $json);
        }
        if ($status === 429 || $status >= 500) {
            return self::result('errore_server', "HTTP $status", $status, $json);
        }
        if ($status === 400) {
            return self::result('formato', 'HTTP 400, ' . (is_string($json['message'] ?? null) ? $json['message'] : 'richiesta rifiutata'), 400, $json);
        }
        return self::result('risposta_inattesa', "HTTP $status", $status, $json);
    }

    /** Spiegazione e rimedio per chi legge la checklist o l'esito della prova. */
    public static function help(string $codice): string
    {
        switch ($codice) {
            case 'bloccata':
                return __('WordPress blocca le chiamate esterne (WP_HTTP_BLOCK_EXTERNAL): aggiungi l\'host di Raffaello Scuola a WP_ACCESSIBLE_HOSTS in wp-config.php.', 'raffaello-licenze-bookshop');
            case 'dns':
                return __('DNS: dal server del Bookshop l\'host di Raffaello Scuola non si risolve. Controlla l\'URL.', 'raffaello-licenze-bookshop');
            case 'timeout':
                return __('Timeout: Raffaello Scuola non ha risposto entro 15 secondi (sito lento o fermo, oppure un firewall che scarta la chiamata).', 'raffaello-licenze-bookshop');
            case 'tls':
                return __('TLS: il certificato HTTPS di Raffaello Scuola non è valido per il server del Bookshop, o la connessione sicura non si stabilisce.', 'raffaello-licenze-bookshop');
            case 'rete':
                return __('Errore di rete fra il Bookshop e Raffaello Scuola.', 'raffaello-licenze-bookshop');
            case 'cloudflare':
                return __('Cloudflare blocca la chiamata con una challenge, prima di WordPress. Sul Cloudflare di Raffaello Scuola serve una IP Access rule che consenta l\'IP del server del Bookshop: Bot Fight Mode non si salta con una regola WAF "Skip".', 'raffaello-licenze-bookshop');
            case 'firma':
                return __('Raffaello Scuola rifiuta la firma: il segreto non è lo stesso sui due siti, oppure gli orologi dei due server differiscono di più di 5 minuti.', 'raffaello-licenze-bookshop');
            case 'vietato':
                return __('Accesso negato prima di Raffaello Licenze: un firewall (Wordfence, regole di Cloudflare o dell\'hosting) blocca la chiamata. Su Wordfence guarda Strumenti > Traffico dal vivo e consenti l\'IP del Bookshop.', 'raffaello-licenze-bookshop');
            case 'non_trovato':
                return __('La rotta non esiste: URL sbagliato, plugin Raffaello Licenze non attivo su Scuola oppure API REST disattivata.', 'raffaello-licenze-bookshop');
            case 'reindirizzamento':
                return __('Raffaello Scuola risponde con un reindirizzamento: usa l\'indirizzo finale (per esempio con o senza www).', 'raffaello-licenze-bookshop');
            case 'scuola_non_configurata':
                return __('Raffaello Scuola non ha il segreto: impostalo in Licenze > Impostazioni sul sito di Scuola.', 'raffaello-licenze-bookshop');
            case 'occupato':
                return __('Raffaello Scuola stava elaborando lo stesso ordine: si riprova da solo.', 'raffaello-licenze-bookshop');
            case 'errore_server':
                return __('Errore di Raffaello Scuola o troppe richieste: si riprova da solo. Se continua, guarda il log raffaello-licenze su Scuola.', 'raffaello-licenze-bookshop');
            case 'formato':
                return __('Raffaello Scuola ha accettato la firma ma ha rifiutato il contenuto.', 'raffaello-licenze-bookshop');
            case 'ping_sconosciuto':
                return __('URL e segreto sono giusti (firma accettata), ma Raffaello Scuola non conosce il ping: aggiorna Raffaello Licenze sul sito di Scuola alla 1.5.1 o successiva.', 'raffaello-licenze-bookshop');
            case 'risposta_inattesa':
                return __('Risposta non riconosciuta, per esempio una pagina HTML al posto del JSON: controlla l\'URL e le pagine intermedie (manutenzione, cache, firewall).', 'raffaello-licenze-bookshop');
            default:
                return '';
        }
    }

    private static function result(string $codice, string $testo, int $http, ?array $json = null): array
    {
        return ['codice' => $codice, 'testo' => $testo, 'http' => $http, 'json' => $json, 'ritenta' => in_array($codice, self::DA_RITENTARE, true)];
    }

    /**
     * Blocco di Cloudflare: 403 o 503 con cf-mitigated: challenge, oppure una sua pagina
     * HTML al posto del JSON con 403, 429 o 503. Le pagine 52x (origine irraggiungibile)
     * restano errori del server.
     */
    private static function cloudflare($response, int $status, string $body): bool
    {
        if (in_array($status, [403, 503], true) && strtolower(self::header($response, 'cf-mitigated')) === 'challenge') {
            return true;
        }
        return in_array($status, [403, 429, 503], true) && strpos(ltrim($body), '<') === 0
            && preg_match('/cdn-cgi\/|cf-chl|cf-error-details|Cloudflare Ray ID/i', $body) === 1;
    }

    private static function header($response, string $name): string
    {
        $value = wp_remote_retrieve_header($response, $name);
        return is_array($value) ? (string) reset($value) : (string) $value;
    }
}
