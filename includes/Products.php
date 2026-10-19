<?php

namespace RaffaelloLicenzeBookshop;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Generatori e prodotti RSCU di License Manager, letti come li legge License
 * Manager 3.0.20: tabella {prefix}lmfwc_generators e meta di prodotto
 * lmfwc_licensed_product*.
 */
class Products
{
    /** Generatori con prefisso RSCU (righe della tabella), o null se la tabella non c'è. */
    public static function generators(): ?array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'lmfwc_generators';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) !== $table) {
            return null;
        }
        $rows = (array) $wpdb->get_results("SELECT * FROM {$table} ORDER BY id", ARRAY_A);
        return array_values(array_filter($rows, function ($g) {
            return strpos((string) $g['prefix'], Sender::PREFISSO) === 0;
        }));
    }

    /** Prodotti e variazioni (non nel cestino) collegati a uno dei generatori dati. */
    public static function rscu(array $generators): array
    {
        global $wpdb;
        if (!$generators) {
            return [];
        }
        $in = implode(',', array_map(function ($g) {
            return "'" . (int) $g['id'] . "'";
        }, $generators));
        $ids = $wpdb->get_col("SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = 'lmfwc_licensed_product_assigned_generator' AND meta_value IN ({$in})");
        if (!$ids) {
            return [];
        }
        // Una query per i post e una per le meta, invece di due per prodotto.
        $posts = get_posts(['post_type' => ['product', 'product_variation'], 'post__in' => array_map('intval', $ids), 'post_status' => 'any', 'numberposts' => -1, 'orderby' => 'ID', 'order' => 'ASC']);
        return array_values(array_filter(array_map('wc_get_product', $posts)));
    }

    /** SKU dei prodotti RSCU pubblicati, senza doppioni. */
    public static function skus(): array
    {
        $skus = [];
        foreach (self::rscu(self::generators() ?? []) as $product) {
            if ($product->get_status() === 'publish' && $product->get_sku() !== '') {
                $skus[] = (string) $product->get_sku();
            }
        }
        // Stringhe anche per gli SKU numerici: come chiavi di array diventerebbero interi.
        return array_values(array_unique($skus));
    }
}
