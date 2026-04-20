<?php

declare(strict_types=1);

namespace ClickLink;

require_once __DIR__ . '/class-runtime.php';

final class Linker_Stats
{
    private const STATS_OPTION_KEY = 'clicklink_stats';
    private const BACKFILL_STATE_OPTION_KEY = 'clicklink_backfill_run_state';
    private const TOUCHED_POST_META_KEY = '_clicklink_touched_by_linker';
    private const LAST_SAVE_META_KEY = '_clicklink_links_inserted_last_save';
    private const POST_TOTAL_META_KEY = '_clicklink_links_inserted_total';

    /**
     * @param array<string, int> $keyword_hits
     */
    public function record_save_metrics(int $post_id, int $links_inserted, array $keyword_hits = array()): void
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

        $stats['keyword_match_counts'] = $this->merge_keyword_hits(
            $stats['keyword_match_counts'] ?? array(),
            $keyword_hits
        );

        $post_total = $this->read_post_meta_int($post_id, self::POST_TOTAL_META_KEY);
        $this->update_post_meta_value($post_id, self::POST_TOTAL_META_KEY, (string) ($post_total + $normalized_links));
        $this->persist_totals($stats);
    }

    /**
     * @return array{total_links_inserted: int, posts_touched: int, keyword_match_counts: array<string, int>}
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

        $keyword_match_counts = $this->normalize_keyword_count_map($saved_stats['keyword_match_counts'] ?? array());

        return array(
            'total_links_inserted' => Runtime::non_negative_int(
                $saved_stats['total_links_inserted'] ?? $defaults['total_links_inserted']
            ),
            'posts_touched' => Runtime::non_negative_int($saved_stats['posts_touched'] ?? $defaults['posts_touched']),
            'keyword_match_counts' => $keyword_match_counts,
        );
    }

    public function posts_with_links(): int
    {
        $totals = $this->get_totals();

        return max(0, (int) ($totals['posts_touched'] ?? 0));
    }

    public function average_links_per_changed_post(): float
    {
        $totals = $this->get_totals();
        $posts_touched = max(0, (int) ($totals['posts_touched'] ?? 0));

        if ($posts_touched <= 0) {
            return 0.0;
        }

        $total_links_inserted = max(0, (int) ($totals['total_links_inserted'] ?? 0));

        return $total_links_inserted / $posts_touched;
    }

    /**
     * @return array<int, array{keyword: string, matches: int}>
     */
    public function top_matched_keywords(int $limit = 5): array
    {
        $resolved_limit = max(1, $limit);
        $totals = $this->get_totals();
        $keyword_counts = $this->normalize_keyword_count_map($totals['keyword_match_counts'] ?? array());

        if ($keyword_counts === array()) {
            return array();
        }

        $entries = array();

        foreach ($keyword_counts as $keyword => $matches) {
            $entries[] = array(
                'keyword' => $keyword,
                'matches' => max(0, (int) $matches),
            );
        }

        usort(
            $entries,
            static function (array $left, array $right): int {
                $matches_compare = ((int) ($right['matches'] ?? 0)) <=> ((int) ($left['matches'] ?? 0));

                if ($matches_compare !== 0) {
                    return $matches_compare;
                }

                return strcmp((string) ($left['keyword'] ?? ''), (string) ($right['keyword'] ?? ''));
            }
        );

        return array_slice($entries, 0, $resolved_limit);
    }

    public function latest_backfill_links_added(): int
    {
        if (! function_exists('get_option')) {
            return 0;
        }

        $state = get_option(self::BACKFILL_STATE_OPTION_KEY, array());

        if (! is_array($state)) {
            return 0;
        }

        return Runtime::non_negative_int($state['inserted_links'] ?? 0);
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

        $posts_table = Runtime::posts_table_name($wpdb);
        $query = "SELECT COUNT(*) FROM {$posts_table} WHERE post_type = 'post' AND post_status <> 'auto-draft'";
        $count = $wpdb->get_var($query);

        return Runtime::non_negative_int($count);
    }

    public function total_mappings(): int
    {
        global $wpdb;

        if (! is_object($wpdb) || ! method_exists($wpdb, 'get_var')) {
            return 0;
        }

        $table_name = Installer::table_name();
        $count = $wpdb->get_var("SELECT COUNT(*) FROM {$table_name}");

        return Runtime::non_negative_int($count);
    }

    /**
     * @return array{total_links_inserted: int, posts_touched: int, keyword_match_counts: array<string, int>}
     */
    private static function default_totals(): array
    {
        return array(
            'total_links_inserted' => 0,
            'posts_touched' => 0,
            'keyword_match_counts' => array(),
        );
    }

    /**
     * @param array{total_links_inserted: int, posts_touched: int, keyword_match_counts: array<string, int>} $stats
     */
    private function persist_totals(array $stats): void
    {
        if (! function_exists('update_option')) {
            return;
        }

        $keyword_match_counts = $this->normalize_keyword_count_map($stats['keyword_match_counts'] ?? array());

        update_option(
            self::STATS_OPTION_KEY,
            array(
                'total_links_inserted' => max(0, (int) $stats['total_links_inserted']),
                'posts_touched' => max(0, (int) $stats['posts_touched']),
                'keyword_match_counts' => $keyword_match_counts,
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

        return Runtime::non_negative_int($value);
    }

    private function update_post_meta_value(int $post_id, string $meta_key, string $value): void
    {
        if (! function_exists('update_post_meta')) {
            return;
        }

        update_post_meta($post_id, $meta_key, $value);
    }

    /**
     * @param array<string, int> $existing_counts
     * @param array<string, int> $keyword_hits
     * @return array<string, int>
     */
    private function merge_keyword_hits(array $existing_counts, array $keyword_hits): array
    {
        $merged_counts = $this->normalize_keyword_count_map($existing_counts);
        $normalized_hits = $this->normalize_keyword_count_map($keyword_hits);

        foreach ($normalized_hits as $keyword => $count) {
            if (! isset($merged_counts[$keyword])) {
                $merged_counts[$keyword] = 0;
            }

            $merged_counts[$keyword] += $count;
        }

        ksort($merged_counts, SORT_STRING);

        return $merged_counts;
    }

    /**
     * @param mixed $value
     * @return array<string, int>
     */
    private function normalize_keyword_count_map($value): array
    {
        if (! is_array($value)) {
            return array();
        }

        $normalized = array();

        foreach ($value as $keyword => $count) {
            $normalized_keyword = self::normalize_keyword_key((string) $keyword);

            if ($normalized_keyword === '') {
                continue;
            }

            $normalized_count = Runtime::non_negative_int($count);

            if ($normalized_count <= 0) {
                continue;
            }

            if (! isset($normalized[$normalized_keyword])) {
                $normalized[$normalized_keyword] = 0;
            }

            $normalized[$normalized_keyword] += $normalized_count;
        }

        ksort($normalized, SORT_STRING);

        return $normalized;
    }

    private static function normalize_keyword_key(string $keyword): string
    {
        $keyword = trim($keyword);
        $keyword = preg_replace('/\s+/', ' ', $keyword) ?? $keyword;

        if (function_exists('sanitize_text_field')) {
            $keyword = sanitize_text_field($keyword);
        } else {
            $keyword = trim(strip_tags($keyword));
        }

        if ($keyword === '') {
            return '';
        }

        if (function_exists('mb_strtolower')) {
            return mb_strtolower($keyword);
        }

        return strtolower($keyword);
    }

}
