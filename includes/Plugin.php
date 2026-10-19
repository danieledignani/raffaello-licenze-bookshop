<?php

namespace RaffaelloLicenzeBookshop;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Avvio: gli invii girano con WooCommerce attivo e senza il vecchio snippet
 * "Raffaello Scuola: licenze RSCU". Anche con License Manager spento, così gli
 * invii già in coda non si perdono (le licenze nuove le porta solo il suo hook).
 * Impostazioni, checklist e Salute del sito ci sono sempre, per dire che cosa manca.
 */
class Plugin
{
    /** Versione di License Manager su cui il plugin è provato. */
    const LMFWC_PROVATA = '3.0.20';

    public static function boot(): void
    {
        load_plugin_textdomain('raffaello-licenze-bookshop', false, dirname(RLB_PLUGIN_BASENAME) . '/languages');

        // Anche a plugin fermo: la checklist cerca gli ordini, e sull'archivio classico serve il filtro.
        add_filter('woocommerce_order_data_store_cpt_get_orders_query', [Sender::class, 'cpt_query'], 10, 2);
        if (self::active()) {
            Sender::register();
            GreenBubble::register();
        }
        SiteHealth::register();
        if (is_admin()) {
            Admin::register();
        }
    }

    public static function active(): bool
    {
        return class_exists('WooCommerce') && !self::snippet_loaded();
    }

    public static function dependencies_ok(): bool
    {
        return class_exists('WooCommerce') && defined('LMFWC_VERSION');
    }

    /**
     * Lo snippet che il plugin sostituisce è caricato (Code Snippets o mu-plugin):
     * stesse meta e stessa azione di Action Scheduler, quindi il plugin si ferma
     * per non registrare due volte gli stessi hook.
     */
    public static function snippet_loaded(): bool
    {
        return function_exists('rscu_licenze_assegnate') || function_exists('rscu_invia');
    }
}
