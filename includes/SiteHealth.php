<?php

namespace RaffaelloLicenzeBookshop;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Test in Strumenti > Salute del sito: legge la stessa checklist della pagina
 * Impostazioni, senza chiamate HTTP (gira anche nel cron settimanale di WordPress).
 */
class SiteHealth
{
    const TEST = 'raffaello_licenze_bookshop';

    public static function register(): void
    {
        add_filter('site_status_tests', [self::class, 'tests']);
    }

    public static function tests($tests)
    {
        $tests['direct'][self::TEST] = [
            'label' => __('Licenze RSCU verso Raffaello Scuola', 'raffaello-licenze-bookshop'),
            'test'  => [self::class, 'test'],
        ];
        return $tests;
    }

    public static function test(): array
    {
        $problemi = Checks::problems();
        $bloccanti = array_filter($problemi, function ($v) {
            return $v['gravita'] === 'bloccante';
        });
        if ($bloccanti) {
            $status = 'critical';
            $label = __('Licenze RSCU verso Raffaello Scuola: ci sono problemi bloccanti', 'raffaello-licenze-bookshop');
        } elseif ($problemi) {
            $status = 'recommended';
            $label = __('Licenze RSCU verso Raffaello Scuola: ci sono punti da controllare', 'raffaello-licenze-bookshop');
        } else {
            $status = 'good';
            $label = __('Licenze RSCU verso Raffaello Scuola: configurazione a posto', 'raffaello-licenze-bookshop');
        }

        $description = '<p>' . esc_html__('Il plugin Raffaello Licenze — Bookshop manda a Raffaello Scuola le licenze RSCU vendute, per attivarle sul profilo Raffaello Identity di chi compra.', 'raffaello-licenze-bookshop') . '</p>';
        if ($problemi) {
            $description .= '<ul>';
            foreach ($problemi as $v) {
                $description .= sprintf('<li><strong>%s</strong>: %s</li>', esc_html($v['titolo']), esc_html($v['testo']));
            }
            $description .= '</ul>';
        }

        return [
            'label'       => $label,
            'status'      => $status,
            'badge'       => ['label' => __('Raffaello Scuola', 'raffaello-licenze-bookshop'), 'color' => $status === 'good' ? 'blue' : ($bloccanti ? 'red' : 'orange')],
            'description' => $description,
            'actions'     => sprintf('<p><a href="%s">%s</a></p>', esc_url(Admin::url()), esc_html__('Apri la checklist', 'raffaello-licenze-bookshop')),
            'test'        => self::TEST,
        ];
    }
}
