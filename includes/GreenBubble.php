<?php

namespace RaffaelloLicenzeBookshop;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Lo snippet GreenBubble (Code Snippets, sullo stesso hook di License Manager)
 * manda a Raffaello Digitale tutte le chiavi che non sono RLAB: le RSCU vanno
 * escluse con una riga nel suo ciclo. Tre controlli:
 * - a runtime, dopo GreenBubble: una licenza RSCU che compare nelle sue meta
 *   d'ordine è stata trattata anche da lui (nota d'ordine, problema bloccante);
 * - statico, sul codice degli snippet attivi: chi usa l'hook e manda dati altrove
 *   è da verificare (se menziona RSCU lo si dice, ma non basta);
 * - conferma manuale dell'amministratore, legata all'hash del codice di quegli
 *   snippet: è l'unico verde, e se il codice cambia decade.
 */
class GreenBubble
{
    const OPTION = 'rlb_greenbubble';
    const META = '_rscu_greenbubble';
    const META_GB = ['_gb_sent_license_ids', '_gb_license_status_map'];
    const HOOK = 'lmfwc_event_post_order_license_keys';

    public static function register(): void
    {
        // Dopo ogni altro callback dell'hook, compreso quello di GreenBubble.
        add_action(self::HOOK, [self::class, 'controlla_ordine'], PHP_INT_MAX);
    }

    public static function controlla_ordine($args): void
    {
        $rscu = [];
        foreach ((array) ($args['licenses'] ?? []) as $license) {
            $chiave = (string) $license->getDecryptedLicenseKey();
            if (strpos($chiave, Sender::PREFISSO) === 0) {
                $rscu[(int) $license->getId()] = $chiave;
            }
        }
        $order = $rscu ? wc_get_order($args['orderId'] ?? 0) : null;
        if (!$order) {
            return;
        }

        $tracce = [];
        foreach (self::META_GB as $meta) {
            self::tracce($order->get_meta($meta), $tracce);
        }
        $segnalate = $order->get_meta(self::META);
        $segnalate = is_array($segnalate) ? $segnalate : [];
        $nuove = [];
        foreach ($rscu as $id => $chiave) {
            if ((isset($tracce[(string) $id]) || isset($tracce[$chiave])) && !in_array($id, $segnalate, true)) {
                $nuove[$id] = Log::mask($chiave);
            }
        }
        if (!$nuove) {
            return;
        }

        $order->update_meta_data(self::META, array_merge($segnalate, array_keys($nuove)));
        $order->save();
        $order->add_order_note(sprintf(
            'Raffaello Scuola: le licenze %s risultano registrate anche dallo snippet GreenBubble, che quindi non esclude le RSCU e potrebbe averle mandate a Raffaello Digitale. Aggiungi allo snippet GreenBubble la riga di esclusione (README di Raffaello Licenze — Bookshop) e avvisa Raffaello Digitale.',
            implode(', ', $nuove)
        ));
        Log::write('error', sprintf('Ordine %d: licenze RSCU registrate anche da GreenBubble (%s).', $order->get_id(), implode(', ', $nuove)));

        $stato = self::stato();
        $ordini = array_unique(array_merge([$order->get_id()], $stato['rilevato']['ordini'] ?? []));
        $stato['rilevato'] = ['quando' => time(), 'ordini' => array_slice($ordini, 0, 50)];
        update_option(self::OPTION, $stato, false);
    }

    /**
     * Raccoglie quello che compare nelle meta di GreenBubble, di cui non si conosce
     * il formato: chiavi degli array associativi e valori. Si spezzano solo le stringhe
     * fatte di numeri e separatori ("501,502", "[501,502]"); un testo resta intero,
     * così un numero dentro un messaggio non passa per l'id di una licenza.
     */
    private static function tracce($valore, array &$tracce): void
    {
        if (is_array($valore)) {
            $lista = array_keys($valore) === range(0, count($valore) - 1);
            foreach ($valore as $k => $v) {
                if (!$lista) {
                    $tracce[(string) $k] = true;
                }
                self::tracce($v, $tracce);
            }
        } elseif (is_scalar($valore)) {
            $valore = trim((string) $valore);
            $parti = preg_match('/^[\d\s,;\[\]"]+$/', $valore) ? preg_split('/\D+/', $valore, -1, PREG_SPLIT_NO_EMPTY) : [$valore];
            foreach ($parti as $t) {
                $tracce[$t] = true;
            }
        }
    }

    /** Snippet di Code Snippets (id, name, code, active), o null se Code Snippets non si legge. */
    public static function snippets(): ?array
    {
        if (function_exists('Code_Snippets\get_snippets')) {
            return array_map(function ($s) {
                return ['id' => (int) $s->id, 'name' => (string) $s->name, 'code' => (string) $s->code, 'active' => (bool) $s->active];
            }, \Code_Snippets\get_snippets());
        }
        global $wpdb;
        $table = $wpdb->prefix . 'snippets';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) !== $table) {
            return null;
        }
        $rows = $wpdb->get_results("SELECT id, name, code, active FROM {$table}", ARRAY_A);
        // Una lettura fallita non vuol dire "nessuno snippet": non verificabile.
        if ($wpdb->last_error !== '') {
            return null;
        }
        return array_map(function ($r) {
            return ['id' => (int) $r['id'], 'name' => (string) $r['name'], 'code' => (string) $r['code'], 'active' => (int) $r['active'] === 1];
        }, (array) $rows);
    }

    /** Snippet attivi che usano l'hook di License Manager e mandano dati altrove (il vecchio snippet di Scuola escluso). */
    public static function sospetti(array $snippets): array
    {
        return array_values(array_filter($snippets, function ($s) {
            return $s['active']
                && strpos($s['code'], self::HOOK) !== false
                && strpos($s['code'], 'rscu_licenze_assegnate') === false
                && preg_match('/\b(wp_remote_(post|get|request)|wp_safe_remote_(post|get|request)|curl_exec)\s*\(/', $s['code']);
        }));
    }

    public static function hash(array $snippets): string
    {
        return hash('sha256', implode("\n", array_map(function ($s) {
            return $s['id'] . ':' . $s['code'];
        }, $snippets)));
    }

    /** Voce B13 della checklist; 'conferma' è l'hash da confermare, se serve una conferma. */
    public static function check(): array
    {
        $titolo = __('GreenBubble esclude le RSCU', 'raffaello-licenze-bookshop');
        $stato = self::stato();
        $conferma = $stato['conferma'] ?? null;
        $rilevato = $stato['rilevato'] ?? null;
        $snippets = self::snippets();
        $sospetti = self::sospetti($snippets ?? []);
        $hash = self::hash($sospetti);

        // Nello stesso secondo vince la segnalazione: meglio una conferma in più che una persa.
        if ($rilevato && (!$conferma || $conferma['quando'] <= $rilevato['quando'])) {
            $voce = Checks::ko('B13', $titolo, 'bloccante', sprintf(
                /* translators: 1: numero di ordini, 2: data */
                __('Licenze RSCU registrate anche dallo snippet GreenBubble in %1$d ordini (l\'ultimo il %2$s): GreenBubble non le esclude e potrebbe averle mandate a Raffaello Digitale. Aggiungi la riga di esclusione (README), poi conferma la verifica.', 'raffaello-licenze-bookshop'),
                count($rilevato['ordini']),
                Checks::date($rilevato['quando'])
            ), $rilevato['ordini']);
            return $voce + ['conferma' => $hash];
        }
        if ($snippets === null) {
            return Checks::nv('B13', $titolo, 'attenzione', __('Code Snippets non trovato: lo snippet GreenBubble non si controlla da qui. La guardia sugli ordini resta attiva.', 'raffaello-licenze-bookshop'));
        }
        if (!$sospetti) {
            return Checks::ok('B13', $titolo, __('Nessuno snippet attivo manda altrove le licenze di License Manager. La guardia sugli ordini resta attiva.', 'raffaello-licenze-bookshop'));
        }
        // Verde solo con la conferma del codice attuale: "RSCU" nel testo può essere un commento.
        if ($conferma && $conferma['hash'] === $hash) {
            /* translators: 1: data, 2: nomi degli snippet */
            return Checks::ok('B13', $titolo, sprintf(__('Esclusione verificata a mano il %1$s per %2$s; la verifica decade se il codice cambia.', 'raffaello-licenze-bookshop'), Checks::date($conferma['quando']), self::nomi($sospetti)));
        }
        $descrizioni = array_map(function ($s) {
            return self::nomi([$s]) . (strpos($s['code'], Sender::PREFISSO) !== false
                ? __(' menziona RSCU: conferma dopo aver controllato che la riga di esclusione venga prima dell\'invio', 'raffaello-licenze-bookshop')
                : __(' non menziona RSCU: aggiungi la riga di esclusione (README)', 'raffaello-licenze-bookshop'));
        }, $sospetti);
        return Checks::ko('B13', $titolo, 'attenzione', sprintf(
            /* translators: %s: snippet e cosa fare */
            __('Da verificare, perché usano l\'hook di License Manager e mandano dati altrove: %s.', 'raffaello-licenze-bookshop'),
            implode('; ', $descrizioni)
        )) + ['conferma' => $hash];
    }

    /** Registra la verifica manuale, se il codice è ancora quello che l'amministratore ha visto. */
    public static function confirm(string $hash): bool
    {
        if (!hash_equals(self::hash(self::sospetti(self::snippets() ?? [])), $hash)) {
            return false;
        }
        $stato = self::stato();
        $stato['conferma'] = ['hash' => $hash, 'quando' => time(), 'utente' => get_current_user_id()];
        update_option(self::OPTION, $stato, false);
        return true;
    }

    public static function stato(): array
    {
        $stato = get_option(self::OPTION, []);
        return is_array($stato) ? $stato : [];
    }

    private static function nomi(array $snippets): string
    {
        return implode(', ', array_map(function ($s) {
            return sprintf('"%s" (#%d)', $s['name'], $s['id']);
        }, $snippets));
    }
}
