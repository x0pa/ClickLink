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
        $total_links_created = max(0, (int) ($totals['total_links_inserted'] ?? 0));
        $posts_with_links = max(0, (int) ($totals['posts_touched'] ?? 0));
        $average_links_per_changed_post = $posts_with_links > 0
            ? $total_links_created / $posts_with_links
            : 0.0;

        $rows = array(
            array(
                'label' => self::translate('Total blog posts'),
                'value' => self::format_number($this->stats->total_blog_posts()),
            ),
            array(
                'label' => self::translate('Total links created'),
                'value' => self::format_number($total_links_created),
            ),
            array(
                'label' => self::translate('Total keyword/url rows'),
                'value' => self::format_number($this->stats->total_mappings()),
            ),
            array(
                'label' => self::translate('Posts with links'),
                'value' => self::format_number($posts_with_links),
            ),
            array(
                'label' => self::translate('Links added by latest backfill run'),
                'value' => self::format_number($this->stats->latest_backfill_links_added()),
            ),
            array(
                'label' => self::translate('Average links per changed post'),
                'value' => self::format_decimal($average_links_per_changed_post),
            ),
        );
        $top_keywords = $this->stats->top_matched_keywords(5);

        echo '<div class="clicklink-dashboard-widget">';
        echo '<p>' . self::escape(self::translate('Operator metrics update after each qualifying post save and each manual backfill batch.')) . '</p>';
        echo '<table class="widefat striped" role="presentation">';
        echo '<tbody>';

        foreach ($rows as $row) {
            $label = isset($row['label']) && is_string($row['label']) ? $row['label'] : '';
            $value = isset($row['value']) && is_string($row['value']) ? $row['value'] : '0';
            echo '<tr>';
            echo '<th scope="row">' . self::escape($label) . '</th>';
            echo '<td>' . self::escape($value) . '</td>';
            echo '</tr>';
        }

        echo '</tbody>';
        echo '</table>';

        echo '<h4>' . self::escape(self::translate('Top matched keywords')) . '</h4>';
        $rendered_keyword_count = 0;

        if ($top_keywords !== array()) {
            echo '<ol style="margin-left:1.25em;">';

            foreach ($top_keywords as $entry) {
                if (! is_array($entry)) {
                    continue;
                }

                $keyword = isset($entry['keyword']) && is_string($entry['keyword'])
                    ? trim($entry['keyword'])
                    : '';
                $matches = max(0, (int) ($entry['matches'] ?? 0));

                if ($keyword === '' || $matches <= 0) {
                    continue;
                }

                echo '<li><code>' . self::escape($keyword) . '</code>: ' . self::escape(self::format_number($matches)) . '</li>';
                $rendered_keyword_count++;
            }

            echo '</ol>';
        }

        if ($rendered_keyword_count <= 0) {
            echo '<p>' . self::escape(self::translate('No matched keywords yet.')) . '</p>';
        }

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

    private static function format_decimal(float $value): string
    {
        return number_format($value, 2, '.', ',');
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
