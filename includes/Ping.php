<?php

namespace RaffaelloLicenzeBookshop;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * "Prova connessione": un evento ping firmato, con lo stesso client degli invii
 * e gli SKU dei prodotti RSCU pubblicati. Raffaello Scuola risponde con la sua
 * versione, l'ora e gli SKU che non conosce. L'esito si salva con l'impronta
 * della configurazione: se URL o segreto cambiano non vale più.
 */
class Ping
{
    const OPTION = 'rlb_ping';
    const MAX_SKU = 500;

    /** Esegue la prova (la configurazione deve essere valida), salva e restituisce l'esito. */
    public static function run(): array
    {
        $config = Config::current();
        $skus = array_slice(Products::skus(), 0, self::MAX_SKU);
        $risposta = Client::read(Client::post($config, wp_json_encode(['evento' => 'ping', 'skus' => $skus])));
        $json = $risposta['json'];
        $esito = [
            'quando'          => time(),
            'destinazione'    => $config['url'],
            'impronta'        => $config['impronta'],
            'sku_inviati'     => count($skus),
            'versione'        => '',
            'scarto'          => null,
            'sku_sconosciuti' => [],
        ];

        if ($risposta['codice'] === 'ok' && is_string($json['versione'] ?? null) && is_array($json['sku_sconosciuti'] ?? [])) {
            $esito['versione'] = $json['versione'];
            $esito['sku_sconosciuti'] = array_values(array_filter($json['sku_sconosciuti'] ?? [], 'is_string'));
            $ora = is_string($json['ora'] ?? null) ? strtotime($json['ora']) : false;
            $esito['scarto'] = $ora ? time() - $ora : null;
        } elseif ($risposta['codice'] === 'ok') {
            $risposta = ['codice' => 'risposta_inattesa', 'testo' => 'HTTP 200 senza i dati del ping', 'http' => 200];
        } elseif ($risposta['codice'] === 'formato' && ($json['code'] ?? '') === 'rcl_formato' && ($json['message'] ?? '') === 'evento sconosciuto') {
            // Una versione di Scuola senza ping (1.5.0) controlla la firma prima del formato.
            $risposta['codice'] = 'ping_sconosciuto';
            $risposta['testo'] = 'HTTP 400, firma accettata ma ping sconosciuto';
        }
        $esito += ['codice' => $risposta['codice'], 'testo' => $risposta['testo'], 'http' => $risposta['http']];

        update_option(self::OPTION, $esito, false);
        Log::write($esito['codice'] === 'ok' ? 'info' : 'warning', sprintf('Prova connessione verso %s: %s.', $config['url'], $esito['testo']));
        return $esito;
    }

    /** Ultima prova fatta con la configurazione attuale, o null. */
    public static function last(array $config): ?array
    {
        $ping = get_option(self::OPTION);
        return is_array($ping) && $config['ok'] && ($ping['impronta'] ?? '') === $config['impronta'] ? $ping : null;
    }
}
