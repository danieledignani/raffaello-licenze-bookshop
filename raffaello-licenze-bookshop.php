<?php
/**
 * Plugin Name: Raffaello Licenze — Bookshop
 * Plugin URI: https://raffaellolibri.it
 * Description: Manda a Raffaello Scuola le licenze RSCU vendute sul Bookshop con License Manager for WooCommerce: attivazione sul profilo di Raffaello Identity, revoca a rimborso o annullamento, impostazioni del collegamento, prova di connessione e controlli di configurazione.
 * Version: 1.0.0
 * Author: Gruppo Raffaello
 * Text Domain: raffaello-licenze-bookshop
 * Domain Path: /languages
 * Requires PHP: 7.4
 * Requires at least: 5.8
 */

if (!defined('ABSPATH')) {
    exit;
}

define('RLB_VERSION', '1.0.0');
define('RLB_PLUGIN_FILE', __FILE__);
define('RLB_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('RLB_PLUGIN_BASENAME', plugin_basename(__FILE__));

// Aggiornamento automatico da GitHub: legge la versione corrente da un JSON
// pubblicato nel repo e, se più alta di quella installata, scarica lo zip
// dalla release corrispondente.
require_once __DIR__ . '/plugin-update-checker/plugin-update-checker.php';
\YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
    'https://raw.githubusercontent.com/danieledignani/raffaello-licenze-bookshop/master/.github/update-metadata/raffaello-licenze-bookshop.json',
    __FILE__,
    'raffaello-licenze-bookshop'
);

// Autoload classi del plugin (namespace RaffaelloLicenzeBookshop\ → includes/).
spl_autoload_register(function ($class) {
    $prefix = 'RaffaelloLicenzeBookshop\\';
    if (strncmp($prefix, $class, strlen($prefix)) !== 0) {
        return;
    }
    $file = RLB_PLUGIN_DIR . 'includes/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_readable($file)) {
        require_once $file;
    }
});

// Usa solo le API CRUD degli ordini: compatibile con l'archivio ordini HPOS.
add_action('before_woocommerce_init', function () {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});

// Dopo Code Snippets, che esegue gli snippet a plugins_loaded priorità 1.
add_action('plugins_loaded', [\RaffaelloLicenzeBookshop\Plugin::class, 'boot']);
