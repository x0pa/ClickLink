<?php

declare(strict_types=1);

namespace ClickLink;

final class Linker_Stats
{
    private const STATS_OPTION_KEY = 'clicklink_stats';
    private const TOUCHED_POST_META_KEY = '_clicklink_touched_by_linker';
    private const LAST_SAVE_META_KEY = '_clicklink_links_inserted_last_save';
    private const POST_TOTAL_META_KEY = '_clicklink_links_inserted_total';

    public function record_save_metrics(int $post_id, int $links_inserted): void
    {
        if ($post_id <= 0) {
            return;
        }

        $normalized_links = max(0, $links_inserted);
        $this->update_post_meta_value($post_id, self::LAST_SAVE_META_KEY, (string) $normalized_links);

        if ($normalized_links <= 0) {
            return;
        }

        $stats = $this->get_totals();
        $stats['total_links_inserted'] += $normalized_links;

        if (! $this->post_has_been_touched($post_id)) {
            $stats['posts_touched'] += 1;
            $this->update_post_meta_value($post_id, self::TOUCHED_POST_META_KEY, '1');
        }

        $post_total = $this->read_post_meta_int($post_id, self::POST_TOTAL_META_KEY);
        $this->update_post_meta_value($post_id, self::POST_TOTAL_META_KEY, (string) ($post_total + $normalized_links));
        $this->persist_totals($stats);
    }

    /**
     * @return array{total_links_inserted: int, posts_touched: int}
     */
    public function get_totals(): array
    {
        $defaults = self::default_totals();

        if (! function_exists('get_option')) {
            return $defaults;
        }

        $saved_stats = get_option(self::STATS_OPTION_KEY, array());

        if (! is_array($saved_stats)) {
            return $defaults;
        }

        return array(
            'total_links_inserted' => self::non_negative_int($saved_stats['total_links_inserted'] ?? $defaults['total_links_inserted']),
            'posts_touched' => self::non_negative_int($saved_stats['posts_touched'] ?? $defaults['posts_touched']),
        );
    }

    public function total_blog_posts(): int
    {
        if (function_exists('wp_count_posts')) {
            $counts = wp_count_posts('post');

            if (is_object($counts)) {
                $total = 0;

                foreach (get_object_vars($counts) as $status => $count) {
                    if (! is_scalar($count) || $status === 'auto-draft') {
                        continue;
                    }

                    $total += max(0, (int) $count);
                }

                return $total;
            }
        }

        global $wpdb;

        if (! is_object($wpdb) || ! method_exists($wpdb, 'get_var')) {
            return 0;
        }

        $posts_table = self::posts_table_name($wpdb);
        $query = "SELECT COUNT(*) FROM {$posts_table} WHERE post_type = 'post' AND post_status <> 'auto-draft'";
        $count = $wpdb->get_var($query);

        return self::non_negative_int($count);
    }

    public function total_mappings(): int
    {
        global $wpdb;

        if (! is_object($wpdb) || ! method_exists($wpdb, 'get_var')) {
            return 0;
        }

        $table_name = Installer::table_name();
        $count = $wpdb->get_var("SELECT COUNT(*) FROM {$table_name}");

        return self::non_negative_int($count);
    }

    /**
     * @return array{total_links_inserted: int, posts_touched: int}
     */
    private static function default_totals(): array
    {
        return array(
            'total_links_inserted' => 0,
            'posts_touched' => 0,
        );
    }

    /**
     * @param array{total_links_inserted: int, posts_touched: int} $stats
     */
    private function persist_totals(array $stats): void
    {
        if (! function_exists('update_option')) {
            return;
        }

        update_option(
            self::STATS_OPTION_KEY,
            array(
                'total_links_inserted' => max(0, (int) $stats['total_links_inserted']),
                'posts_touched' => max(0, (int) $stats['posts_touched']),
            ),
            false
        );
    }

    private function post_has_been_touched(int $post_id): bool
    {
        if (! function_exists('get_post_meta')) {
            return false;
        }

        $value = get_post_meta($post_id, self::TOUCHED_POST_META_KEY, true);

        if (! is_scalar($value)) {
            return false;
        }

        $normalized = trim((string) $value);

        return $normalized !== '' && $normalized !== '0';
    }

    private function read_post_meta_int(int $post_id, string $meta_key): int
    {
        if (! function_exists('get_post_meta')) {
            return 0;
        }

        $value = get_post_meta($post_id, $meta_key, true);

        return self::non_negative_int($value);
    }

    private function update_post_meta_value(int $post_id, string $meta_key, string $value): void
    {
        if (! function_exists('update_post_meta')) {
            return;
        }

        update_post_meta($post_id, $meta_key, $value);
    }

    /**
     * @param mixed $value
     */
    private static function non_negative_int($value): int
    {
        if (! is_scalar($value) || $value === '') {
            return 0;
        }

        $validated = filter_var(
            (string) $value,
            FILTER_VALIDATE_INT,
            array(
                'options' => array(
                    'min_range' => 0,
                ),
            )
        );

        if ($validated === false) {
            return 0;
        }

        return (int) $validated;
    }

    /**
     * @param object $wpdb
     */
    private static function posts_table_name(object $wpdb): string
    {
        if (isset($wpdb->posts) && is_string($wpdb->posts) && $wpdb->posts !== '') {
            return $wpdb->posts;
        }

        if (isset($wpdb->prefix) && is_string($wpdb->prefix) && $wpdb->prefix !== '') {
            return $wpdb->prefix . 'posts';
        }

        return 'posts';
    }
}
