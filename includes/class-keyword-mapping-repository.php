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
     * @param array{
     *   search?: string,
     *   keyword_filter?: string,
     *   sort_by?: string,
     *   sort_direction?: string,
     *   page?: int,
     *   per_page?: int
     * } $args
     * @return array{
     *   rows: array<int, array{id: int, keyword: string, url: string, created_at: string, updated_at: string}>,
     *   total: int,
     *   page: int,
     *   per_page: int,
     *   total_pages: int
     * }
     */
    public function fetch_mappings_page(array $args = array()): array
    {
        global $wpdb;

        $page = max(1, self::positive_int((string) ($args['page'] ?? '1')));
        $per_page = self::bounded_per_page($args['per_page'] ?? 20);
        $search = self::normalize_search_term((string) ($args['search'] ?? ''));
        $keyword_filter = self::normalize_search_term((string) ($args['keyword_filter'] ?? ''));
        $sort_by = self::normalize_sort_by((string) ($args['sort_by'] ?? 'keyword'));
        $sort_direction = self::normalize_sort_direction((string) ($args['sort_direction'] ?? 'asc'));
        $empty_result = array(
            'rows' => array(),
            'total' => 0,
            'page' => $page,
            'per_page' => $per_page,
            'total_pages' => 1,
        );

        if (! is_object($wpdb) || ! method_exists($wpdb, 'get_results') || ! method_exists($wpdb, 'get_var')) {
            return $empty_result;
        }

        $table_name = Installer::table_name();
        $where_clauses = array('1=1');
        $where_params = array();

        if ($search !== '') {
            $like_pattern = '%' . self::escape_like($search, $wpdb) . '%';
            $where_clauses[] = '(keyword LIKE %s OR url LIKE %s)';
            $where_params[] = $like_pattern;
            $where_params[] = $like_pattern;
        }

        if ($keyword_filter !== '') {
            $like_pattern = '%' . self::escape_like($keyword_filter, $wpdb) . '%';
            $where_clauses[] = 'keyword LIKE %s';
            $where_params[] = $like_pattern;
        }

        $where_sql = implode(' AND ', $where_clauses);
        $count_query = $this->prepare_query(
            "SELECT COUNT(*) FROM {$table_name} WHERE {$where_sql}",
            $where_params
        );
        $total_count = Runtime::non_negative_int($wpdb->get_var($count_query));
        $total_pages = max(1, (int) ceil($total_count / $per_page));

        if ($page > $total_pages) {
            $page = $total_pages;
        }

        $offset = max(0, ($page - 1) * $per_page);
        $order_column = self::sort_column_sql($sort_by);
        $order_direction = strtoupper($sort_direction) === 'DESC' ? 'DESC' : 'ASC';
        $order_by_sql = "{$order_column} {$order_direction}";

        if ($order_column !== 'id') {
            $order_by_sql .= ', id ASC';
        }

        $results_query = $this->prepare_query(
            "SELECT id, keyword, url, created_at, updated_at
            FROM {$table_name}
            WHERE {$where_sql}
            ORDER BY {$order_by_sql}
            LIMIT %d OFFSET %d",
            array_merge($where_params, array($per_page, $offset))
        );
        $results = $wpdb->get_results($results_query, 'ARRAY_A');

        if (! is_array($results)) {
            $results = array();
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

        return array(
            'rows' => $mappings,
            'total' => $total_count,
            'page' => $page,
            'per_page' => $per_page,
            'total_pages' => $total_pages,
        );
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

    /**
     * @param mixed $value
     */
    private static function bounded_per_page($value): int
    {
        if (! is_scalar($value) || $value === '') {
            return 20;
        }

        $per_page = self::positive_int((string) $value);

        if ($per_page <= 0) {
            return 20;
        }

        return min(200, $per_page);
    }

    private static function normalize_search_term(string $value): string
    {
        return trim($value);
    }

    private static function normalize_sort_by(string $value): string
    {
        $allowed = array('keyword', 'url', 'created_at', 'updated_at', 'id');
        $normalized = trim(strtolower($value));

        if (! in_array($normalized, $allowed, true)) {
            return 'keyword';
        }

        return $normalized;
    }

    private static function normalize_sort_direction(string $value): string
    {
        return trim(strtolower($value)) === 'desc' ? 'desc' : 'asc';
    }

    private static function sort_column_sql(string $sort_by): string
    {
        $map = array(
            'keyword' => 'keyword',
            'url' => 'url',
            'created_at' => 'created_at',
            'updated_at' => 'updated_at',
            'id' => 'id',
        );

        if (! isset($map[$sort_by])) {
            return 'keyword';
        }

        return $map[$sort_by];
    }

    /**
     * @param array<int, mixed> $params
     */
    private function prepare_query(string $query, array $params): string
    {
        if ($params === array()) {
            return $query;
        }

        global $wpdb;

        if (! is_object($wpdb) || ! method_exists($wpdb, 'prepare')) {
            return $query;
        }

        $prepared = $wpdb->prepare($query, ...$params);

        if (! is_string($prepared) || $prepared === '') {
            return $query;
        }

        return $prepared;
    }

    /**
     * @param object $wpdb
     */
    private static function escape_like(string $value, object $wpdb): string
    {
        if (method_exists($wpdb, 'esc_like')) {
            $escaped = $wpdb->esc_like($value);

            if (is_string($escaped)) {
                return $escaped;
            }
        }

        return addcslashes($value, "\\%_");
    }
}
