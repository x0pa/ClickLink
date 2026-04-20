<?php

declare(strict_types=1);

namespace ClickLink\Admin;

use ClickLink\Linker_Stats;

final class Dashboard_Widget
{
    private const CAPABILITY = 'manage_options';
    private const WIDGET_ID = 'clicklink_dashboard_widget';

    private Linker_Stats $stats;

    public function __construct(?Linker_Stats $stats = null)
    {
        $this->stats = $stats ?? new Linker_Stats();
    }

    public function register(): void
    {
        if (! function_exists('add_action')) {
            return;
        }

        add_action('wp_dashboard_setup', array($this, 'register_widget'));
    }

    public function register_widget(): void
    {
        if (! self::can_manage()) {
            return;
        }

        if (! function_exists('wp_add_dashboard_widget')) {
            return;
        }

        wp_add_dashboard_widget(
            self::WIDGET_ID,
            self::translate('ClickLink Stats'),
            array($this, 'render')
        );
    }

    public function render(): void
    {
        $totals = $this->stats->get_totals();
        $rows = array(
            self::translate('Total blog posts') => $this->stats->total_blog_posts(),
            self::translate('Total keyword/url rows') => $this->stats->total_mappings(),
            self::translate('Total links inserted') => (int) ($totals['total_links_inserted'] ?? 0),
            self::translate('Posts touched by linker') => (int) ($totals['posts_touched'] ?? 0),
        );

        echo '<div class="clicklink-dashboard-widget">';
        echo '<p>' . self::escape(self::translate('Baseline ClickLink activity metrics update after each qualifying post save.')) . '</p>';
        echo '<table class="widefat striped" role="presentation">';
        echo '<tbody>';

        foreach ($rows as $label => $value) {
            echo '<tr>';
            echo '<th scope="row">' . self::escape($label) . '</th>';
            echo '<td>' . self::escape(self::format_number((int) $value)) . '</td>';
            echo '</tr>';
        }

        echo '</tbody>';
        echo '</table>';
        echo '</div>';
    }

    private static function can_manage(): bool
    {
        return function_exists('current_user_can') && current_user_can(self::CAPABILITY);
    }

    private static function format_number(int $value): string
    {
        if (function_exists('number_format_i18n')) {
            return number_format_i18n($value);
        }

        return number_format($value);
    }

    private static function translate(string $value): string
    {
        if (function_exists('__')) {
            return __($value, 'clicklink');
        }

        return $value;
    }

    private static function escape(string $value): string
    {
        if (function_exists('esc_html')) {
            return esc_html($value);
        }

        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
