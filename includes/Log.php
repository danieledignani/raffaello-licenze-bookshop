<?php

namespace RaffaelloLicenzeBookshop;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Log in WooCommerce > Stato > Log, sorgente raffaello-scuola-licenze (la
 * stessa dello snippet). Mai chiavi complete né segreti.
 */
class Log
{
    const SOURCE = 'raffaello-scuola-licenze';

    public static function write(string $level, string $message): void
    {
        if (function_exists('wc_get_logger')) {
            wc_get_logger()->log($level, $message, ['source' => self::SOURCE]);
        }
    }

    /** Chiave per note e log: RSCU-****-AB12. */
    public static function mask(string $chiave): string
    {
        return substr($chiave, 0, 4) . '-****-' . substr($chiave, -4);
    }
}
