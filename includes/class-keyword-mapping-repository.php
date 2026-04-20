<?php

declare(strict_types=1);

namespace ClickLink;

final class Keyword_Mapping_Repository
{
    /**
     * @var array<string, array<int, string>>|null
     */
    private ?array $grouped_keyword_url_cache = null;

    /**
     * @return array<string, array<int, string>>
     */
    public function fetch_grouped_keyword_urls(): array
    {
        if ($this->grouped_keyword_url_cache !== null) {
            return $this->grouped_keyword_url_cache;
        }

        global $wpdb;

        if (! is_object($wpdb) || ! method_exists($wpdb, 'get_results')) {
            $this->grouped_keyword_url_cache = array();
            return $this->grouped_keyword_url_cache;
        }

        $table_name = Installer::table_name();
        $results = $wpdb->get_results(
            "SELECT keyword, url FROM {$table_name} WHERE keyword <> '' AND url <> '' ORDER BY keyword ASC, id ASC",
            'ARRAY_A'
        );

        if (! is_array($results)) {
            $this->grouped_keyword_url_cache = array();
            return $this->grouped_keyword_url_cache;
        }

        $grouped_mappings = array();
        $dedupe_map = array();

        foreach ($results as $result) {
            if (! is_array($result)) {
                continue;
            }

            $keyword = self::normalize_keyword_for_matching((string) ($result['keyword'] ?? ''));
            $url = self::sanitize_url((string) ($result['url'] ?? ''));

            if ($keyword === '' || $url === '') {
                continue;
            }

            if (! isset($dedupe_map[$keyword])) {
                $dedupe_map[$keyword] = array();
            }

            if (isset($dedupe_map[$keyword][$url])) {
                continue;
            }

            if (! isset($grouped_mappings[$keyword])) {
                $grouped_mappings[$keyword] = array();
            }

            $grouped_mappings[$keyword][] = $url;
            $dedupe_map[$keyword][$url] = true;
        }

        $this->grouped_keyword_url_cache = $grouped_mappings;

        return $this->grouped_keyword_url_cache;
    }

    /**
     * @return array<int, array{id: int, keyword: string, url: string, created_at: string, updated_at: string}>
     */
    public function fetch_mappings(): array
    {
        global $wpdb;

        if (! is_object($wpdb) || ! method_exists($wpdb, 'get_results')) {
            return array();
        }

        $table_name = Installer::table_name();
        $results = $wpdb->get_results(
            "SELECT id, keyword, url, created_at, updated_at FROM {$table_name} ORDER BY keyword ASC, id ASC",
            'ARRAY_A'
        );

        if (! is_array($results)) {
            return array();
        }

        $mappings = array();

        foreach ($results as $result) {
            if (! is_array($result)) {
                continue;
            }

            $mappings[] = array(
                'id' => self::positive_int((string) ($result['id'] ?? '0')),
                'keyword' => self::normalize_keyword_for_storage((string) ($result['keyword'] ?? '')),
                'url' => trim((string) ($result['url'] ?? '')),
                'created_at' => (string) ($result['created_at'] ?? ''),
                'updated_at' => (string) ($result['updated_at'] ?? ''),
            );
        }

        return $mappings;
    }

    /**
     * @return array{id: int, keyword: string, url: string}|null
     */
    public function fetch_mapping_by_id(int $mapping_id): ?array
    {
        if ($mapping_id <= 0) {
            return null;
        }

        global $wpdb;

        if (! is_object($wpdb) || ! method_exists($wpdb, 'get_row')) {
            return null;
        }

        $table_name = Installer::table_name();
        $query = "SELECT id, keyword, url FROM {$table_name} WHERE id = {$mapping_id}";

        if (method_exists($wpdb, 'prepare')) {
            $query = (string) $wpdb->prepare(
                "SELECT id, keyword, url FROM {$table_name} WHERE id = %d",
                $mapping_id
            );
        }

        $result = $wpdb->get_row($query, 'ARRAY_A');

        if (! is_array($result)) {
            return null;
        }

        return array(
            'id' => self::positive_int((string) ($result['id'] ?? '0')),
            'keyword' => self::normalize_keyword_for_storage((string) ($result['keyword'] ?? '')),
            'url' => trim((string) ($result['url'] ?? '')),
        );
    }

    public function mapping_exists(int $mapping_id): bool
    {
        if ($mapping_id <= 0) {
            return false;
        }

        global $wpdb;

        if (! is_object($wpdb) || ! method_exists($wpdb, 'get_row')) {
            return false;
        }

        $table_name = Installer::table_name();
        $query = "SELECT id FROM {$table_name} WHERE id = {$mapping_id}";

        if (method_exists($wpdb, 'prepare')) {
            $query = (string) $wpdb->prepare(
                "SELECT id FROM {$table_name} WHERE id = %d",
                $mapping_id
            );
        }

        $result = $wpdb->get_row($query, 'ARRAY_A');

        return is_array($result);
    }

    public function invalidate_grouped_cache(): void
    {
        $this->grouped_keyword_url_cache = null;
    }

    public static function normalize_keyword_for_storage(string $keyword): string
    {
        $keyword = trim($keyword);
        $keyword = preg_replace('/\s+/', ' ', $keyword) ?? $keyword;

        if (function_exists('sanitize_text_field')) {
            $keyword = sanitize_text_field($keyword);
        } else {
            $keyword = trim(strip_tags($keyword));
        }

        return $keyword;
    }

    public static function normalize_keyword_for_matching(string $keyword): string
    {
        $normalized_keyword = self::normalize_keyword_for_storage($keyword);

        if ($normalized_keyword === '') {
            return '';
        }

        if (function_exists('mb_strtolower')) {
            return mb_strtolower($normalized_keyword);
        }

        return strtolower($normalized_keyword);
    }

    public static function sanitize_url(string $url): string
    {
        $url = trim($url);

        if (function_exists('esc_url_raw')) {
            $url = esc_url_raw($url);
        } else {
            $url = (string) filter_var($url, FILTER_SANITIZE_URL);
        }

        if ($url === '') {
            return '';
        }

        if (function_exists('wp_http_validate_url')) {
            return wp_http_validate_url($url) ? $url : '';
        }

        return filter_var($url, FILTER_VALIDATE_URL) !== false ? $url : '';
    }

    private static function positive_int(string $value): int
    {
        $validated = filter_var($value, FILTER_VALIDATE_INT, array('options' => array('min_range' => 1)));

        if ($validated === false) {
            return 0;
        }

        return (int) $validated;
    }
}
