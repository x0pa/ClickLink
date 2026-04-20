<?php

declare(strict_types=1);

final class ClickLink_Test_Admin_WPDB
{
    public string $prefix = 'wp_';
    public string $posts = 'wp_posts';
    public int $eligible_posts_count = 0;

    /**
     * @var array<int, array{id: int, keyword: string, url: string, created_at: string, updated_at: string}>
     */
    public array $rows = array();
    private int $next_id = 1;

    /**
     * @param array<string, mixed> $data
     * @param array<int, string> $format
     * @return int|false
     */
    public function insert(string $table, array $data, array $format = array())
    {
        $id = $this->next_id;
        $this->next_id++;

        $this->rows[$id] = array(
            'id' => $id,
            'keyword' => (string) ($data['keyword'] ?? ''),
            'url' => (string) ($data['url'] ?? ''),
            'created_at' => (string) ($data['created_at'] ?? ''),
            'updated_at' => (string) ($data['updated_at'] ?? ''),
        );

        return 1;
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $where
     * @param array<int, string> $format
     * @param array<int, string> $where_format
     * @return int|false
     */
    public function update(
        string $table,
        array $data,
        array $where,
        array $format = array(),
        array $where_format = array()
    ) {
        $id = (int) ($where['id'] ?? 0);

        if ($id <= 0 || ! isset($this->rows[$id])) {
            return 0;
        }

        $before = $this->rows[$id];

        if (array_key_exists('keyword', $data)) {
            $this->rows[$id]['keyword'] = (string) $data['keyword'];
        }

        if (array_key_exists('url', $data)) {
            $this->rows[$id]['url'] = (string) $data['url'];
        }

        if (array_key_exists('updated_at', $data)) {
            $this->rows[$id]['updated_at'] = (string) $data['updated_at'];
        }

        if ($this->rows[$id] === $before) {
            return 0;
        }

        return 1;
    }

    /**
     * @param array<string, mixed> $where
     * @param array<int, string> $where_format
     * @return int|false
     */
    public function delete(string $table, array $where, array $where_format = array())
    {
        $id = (int) ($where['id'] ?? 0);

        if (! isset($this->rows[$id])) {
            return 0;
        }

        unset($this->rows[$id]);

        return 1;
    }

    /**
     * @param mixed ...$args
     */
    public function prepare(string $query, ...$args): string
    {
        if ($args === array()) {
            return $query;
        }

        $arg_index = 0;

        return (string) preg_replace_callback(
            '/%[ds]/',
            static function (array $matches) use (&$arg_index, $args): string {
                $placeholder = $matches[0] ?? '';
                $value = $args[$arg_index] ?? '';
                $arg_index++;

                if ($placeholder === '%d') {
                    return (string) ((int) $value);
                }

                $string_value = str_replace("'", "''", (string) $value);

                return "'" . $string_value . "'";
            },
            $query
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get_row(string $query, string $output = 'OBJECT'): ?array
    {
        if (! preg_match('/WHERE id = (\d+)/', $query, $matches)) {
            return null;
        }

        $id = (int) ($matches[1] ?? 0);

        if (! isset($this->rows[$id])) {
            return null;
        }

        if (str_contains($query, 'SELECT id FROM')) {
            return array('id' => $id);
        }

        return array(
            'id' => $id,
            'keyword' => $this->rows[$id]['keyword'],
            'url' => $this->rows[$id]['url'],
        );
    }

    /**
     * @return array<int, array{id: int, keyword: string, url: string, created_at: string, updated_at: string}>
     */
    public function get_results(string $query, string $output = 'OBJECT'): array
    {
        if (str_contains($query, 'SELECT id, keyword, url, created_at, updated_at FROM')) {
            return $this->query_mapping_rows($query, true);
        }

        if (str_contains($query, 'SELECT keyword, url FROM')) {
            $rows = $this->query_mapping_rows($query, false);
            $results = array();

            foreach ($rows as $row) {
                $results[] = array(
                    'keyword' => (string) ($row['keyword'] ?? ''),
                    'url' => (string) ($row['url'] ?? ''),
                );
            }

            return $results;
        }

        $rows = array_values($this->rows);

        usort(
            $rows,
            static function (array $left, array $right): int {
                $left_keyword = $left['keyword'] ?? '';
                $right_keyword = $right['keyword'] ?? '';
                $keyword_compare = strcmp((string) $left_keyword, (string) $right_keyword);

                if ($keyword_compare !== 0) {
                    return $keyword_compare;
                }

                return ((int) ($left['id'] ?? 0)) <=> ((int) ($right['id'] ?? 0));
            }
        );

        return $rows;
    }

    /**
     * @return int
     */
    public function get_var(string $query)
    {
        if (! str_contains($query, 'COUNT(*)')) {
            return 0;
        }

        if (str_contains($query, 'clicklink_keyword_mappings')) {
            return count($this->query_mapping_rows($query, false));
        }

        return max(0, $this->eligible_posts_count);
    }

    /**
     * @return array<int>
     */
    public function get_col(string $query): array
    {
        return array();
    }

    public function esc_like(string $value): string
    {
        return addcslashes($value, "\\%_");
    }

    /**
     * @return array<int, array{id: int, keyword: string, url: string, created_at: string, updated_at: string}>
     */
    private function query_mapping_rows(string $query, bool $respect_pagination): array
    {
        $rows = array_values($this->rows);
        $search_like = '';
        $keyword_like = '';
        $order_column = 'keyword';
        $order_direction = 'ASC';
        $limit = null;
        $offset = 0;

        if (preg_match("/\\(keyword LIKE '([^']*)' OR url LIKE '([^']*)'\\)/", $query, $search_matches) === 1) {
            $search_like = (string) ($search_matches[1] ?? '');
        }

        if (preg_match("/AND keyword LIKE '([^']*)'/", $query, $keyword_matches) === 1) {
            $keyword_like = (string) ($keyword_matches[1] ?? '');
        }

        if (preg_match('/ORDER BY\\s+([a-z_]+)\\s+(ASC|DESC)/i', $query, $order_matches) === 1) {
            $order_column = strtolower((string) ($order_matches[1] ?? 'keyword'));
            $order_direction = strtoupper((string) ($order_matches[2] ?? 'ASC'));
        }

        if ($respect_pagination && preg_match('/LIMIT\\s+(\\d+)\\s+OFFSET\\s+(\\d+)/i', $query, $limit_matches) === 1) {
            $limit = max(0, (int) ($limit_matches[1] ?? 0));
            $offset = max(0, (int) ($limit_matches[2] ?? 0));
        }

        if (str_contains($query, "keyword <> ''")) {
            $rows = array_values(
                array_filter(
                    $rows,
                    static function (array $row): bool {
                        return ((string) ($row['keyword'] ?? '')) !== '';
                    }
                )
            );
        }

        if (str_contains($query, "url <> ''")) {
            $rows = array_values(
                array_filter(
                    $rows,
                    static function (array $row): bool {
                        return ((string) ($row['url'] ?? '')) !== '';
                    }
                )
            );
        }

        if ($search_like !== '') {
            $rows = array_values(
                array_filter(
                    $rows,
                    function (array $row) use ($search_like): bool {
                        $keyword = (string) ($row['keyword'] ?? '');
                        $url = (string) ($row['url'] ?? '');
                        return $this->matches_like_pattern($keyword, $search_like)
                            || $this->matches_like_pattern($url, $search_like);
                    }
                )
            );
        }

        if ($keyword_like !== '') {
            $rows = array_values(
                array_filter(
                    $rows,
                    function (array $row) use ($keyword_like): bool {
                        $keyword = (string) ($row['keyword'] ?? '');
                        return $this->matches_like_pattern($keyword, $keyword_like);
                    }
                )
            );
        }

        usort(
            $rows,
            static function (array $left, array $right) use ($order_column, $order_direction): int {
                $left_value = (string) ($left[$order_column] ?? '');
                $right_value = (string) ($right[$order_column] ?? '');
                $comparison = strcmp($left_value, $right_value);

                if ($comparison === 0) {
                    $comparison = ((int) ($left['id'] ?? 0)) <=> ((int) ($right['id'] ?? 0));
                }

                if ($order_direction === 'DESC') {
                    return -1 * $comparison;
                }

                return $comparison;
            }
        );

        if ($respect_pagination && $limit !== null) {
            $rows = array_slice($rows, $offset, $limit);
        }

        return $rows;
    }

    private function matches_like_pattern(string $value, string $like_pattern): bool
    {
        $pattern = str_replace("''", "'", $like_pattern);
        $regex = '';
        $length = strlen($pattern);

        for ($index = 0; $index < $length; $index++) {
            $character = $pattern[$index];

            if ($character === '\\' && ($index + 1) < $length) {
                $index++;
                $regex .= preg_quote($pattern[$index], '/');
                continue;
            }

            if ($character === '%') {
                $regex .= '.*';
                continue;
            }

            if ($character === '_') {
                $regex .= '.';
                continue;
            }

            $regex .= preg_quote($character, '/');
        }

        return preg_match('/^' . $regex . '$/i', $value) === 1;
    }
}

$clicklink_test_actions = array();
$clicklink_test_submenus = array();
$clicklink_test_redirects = array();
$clicklink_test_json_responses = array();
$clicklink_test_can_manage = true;
$clicklink_test_options = array();

if (! function_exists('add_action')) {
    function add_action(string $hook, callable $callback): void
    {
        global $clicklink_test_actions;

        $clicklink_test_actions[$hook][] = $callback;
    }
}

if (! function_exists('add_submenu_page')) {
    function add_submenu_page(
        string $parent_slug,
        string $page_title,
        string $menu_title,
        string $capability,
        string $menu_slug,
        callable $callback
    ): void {
        global $clicklink_test_submenus;

        $clicklink_test_submenus[] = array(
            'parent_slug' => $parent_slug,
            'page_title' => $page_title,
            'menu_title' => $menu_title,
            'capability' => $capability,
            'menu_slug' => $menu_slug,
        );
    }
}

if (! function_exists('current_user_can')) {
    function current_user_can(string $capability): bool
    {
        global $clicklink_test_can_manage;

        return (bool) $clicklink_test_can_manage;
    }
}

if (! function_exists('__')) {
    function __(string $text, string $domain = 'default'): string
    {
        return $text;
    }
}

if (! function_exists('esc_html')) {
    function esc_html(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (! function_exists('esc_attr')) {
    function esc_attr(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (! function_exists('esc_url')) {
    function esc_url(string $text): string
    {
        return (string) filter_var($text, FILTER_SANITIZE_URL);
    }
}

if (! function_exists('esc_url_raw')) {
    function esc_url_raw(string $text): string
    {
        return (string) filter_var($text, FILTER_SANITIZE_URL);
    }
}

if (! function_exists('sanitize_text_field')) {
    function sanitize_text_field(string $text): string
    {
        return trim(strip_tags($text));
    }
}

if (! function_exists('wp_http_validate_url')) {
    /**
     * @return string|false
     */
    function wp_http_validate_url(string $url)
    {
        return filter_var($url, FILTER_VALIDATE_URL) !== false ? $url : false;
    }
}

if (! function_exists('wp_unslash')) {
    function wp_unslash(string $value): string
    {
        return stripslashes($value);
    }
}

if (! function_exists('admin_url')) {
    function admin_url(string $path = ''): string
    {
        return 'https://example.com/wp-admin/' . ltrim($path, '/');
    }
}

if (! function_exists('add_query_arg')) {
    /**
     * @param array<string, string> $args
     */
    function add_query_arg(array $args, string $url = ''): string
    {
        $parts = parse_url($url);
        $base = '';
        $existing_query = array();

        if (is_array($parts) && isset($parts['query'])) {
            parse_str((string) $parts['query'], $existing_query);
        }

        $merged_query = array_merge($existing_query, $args);
        $query = http_build_query($merged_query);

        if (is_array($parts)) {
            if (isset($parts['scheme'])) {
                $base .= $parts['scheme'] . '://';
            }

            if (isset($parts['host'])) {
                $base .= $parts['host'];
            }

            if (isset($parts['path'])) {
                $base .= $parts['path'];
            }
        } else {
            $base = $url;
        }

        if ($query !== '') {
            $base .= '?' . $query;
        }

        return $base;
    }
}

if (! function_exists('wp_nonce_field')) {
    /**
     * @return string|void
     */
    function wp_nonce_field(
        string $action = '-1',
        string $name = '_wpnonce',
        bool $referer = true,
        bool $display = true
    ) {
        $field = '<input type="hidden" name="' . esc_attr($name) . '" value="' . esc_attr('nonce-' . $action) . '">';

        if ($display) {
            echo $field;
            return;
        }

        return $field;
    }
}

if (! function_exists('wp_create_nonce')) {
    function wp_create_nonce(string $action): string
    {
        return 'nonce-' . $action;
    }
}

if (! function_exists('wp_verify_nonce')) {
    /**
     * @return int|false
     */
    function wp_verify_nonce(string $nonce, string $action)
    {
        return $nonce === 'nonce-' . $action ? 1 : false;
    }
}

if (! function_exists('wp_safe_redirect')) {
    function wp_safe_redirect(string $location): void
    {
        global $clicklink_test_redirects;

        $clicklink_test_redirects[] = $location;
    }
}

if (! function_exists('wp_send_json')) {
    /**
     * @param mixed $response
     */
    function wp_send_json($response, int $status_code = 200): void
    {
        global $clicklink_test_json_responses;

        $clicklink_test_json_responses[] = array(
            'status_code' => $status_code,
            'payload' => $response,
        );
    }
}

if (! function_exists('get_option')) {
    /**
     * @return mixed
     */
    function get_option(string $name, $default = false)
    {
        global $clicklink_test_options;

        if (! array_key_exists($name, $clicklink_test_options)) {
            return $default;
        }

        return $clicklink_test_options[$name];
    }
}

if (! function_exists('update_option')) {
    /**
     * @param mixed $value
     */
    function update_option(string $name, $value, bool $autoload = false): void
    {
        global $clicklink_test_options;

        $clicklink_test_options[$name] = $value;
    }
}

if (! function_exists('wp_die')) {
    function wp_die(string $message): void
    {
        throw new RuntimeException($message);
    }
}

if (! function_exists('current_time')) {
    function current_time(string $type, bool $gmt = false): string
    {
        return '2026-04-20 00:00:00';
    }
}

global $wpdb;
$wpdb = new ClickLink_Test_Admin_WPDB();

require_once __DIR__ . '/../includes/class-installer.php';
require_once __DIR__ . '/../includes/class-keyword-mapping-repository.php';
require_once __DIR__ . '/../includes/class-linker-stats.php';
require_once __DIR__ . '/../includes/class-post-save-linker.php';
require_once __DIR__ . '/../includes/class-backfill-scanner.php';
require_once __DIR__ . '/../admin/class-admin-page.php';

$failures = array();

$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (! $condition) {
        $failures[] = $message;
    }
};

$page = new \ClickLink\Admin\Admin_Page();

$page->register();

$assert(
    isset($clicklink_test_actions['admin_menu']),
    'Expected register() to hook admin_menu.'
);
$assert(
    isset($clicklink_test_actions['admin_post_clicklink_save_mapping']),
    'Expected register() to hook save mapping admin_post action.'
);
$assert(
    isset($clicklink_test_actions['admin_post_clicklink_delete_mapping']),
    'Expected register() to hook delete mapping admin_post action.'
);
$assert(
    isset($clicklink_test_actions['admin_post_clicklink_bulk_delete_mappings']),
    'Expected register() to hook bulk delete mappings admin_post action.'
);
$assert(
    isset($clicklink_test_actions['admin_post_clicklink_backfill_start']),
    'Expected register() to hook manual backfill start admin_post action.'
);
$assert(
    isset($clicklink_test_actions['admin_post_clicklink_backfill_next_batch']),
    'Expected register() to hook manual backfill next-batch admin_post action.'
);
$assert(
    isset($clicklink_test_actions['admin_post_clicklink_backfill_reset']),
    'Expected register() to hook manual backfill reset admin_post action.'
);
$assert(
    isset($clicklink_test_actions['wp_ajax_clicklink_backfill_start']),
    'Expected register() to hook manual backfill start AJAX action.'
);
$assert(
    isset($clicklink_test_actions['wp_ajax_clicklink_backfill_next_batch']),
    'Expected register() to hook manual backfill next-batch AJAX action.'
);
$assert(
    isset($clicklink_test_actions['wp_ajax_clicklink_backfill_reset']),
    'Expected register() to hook manual backfill reset AJAX action.'
);

$clicklink_test_can_manage = true;
$page->register_menu();
$submenu = $clicklink_test_submenus[0] ?? null;

$assert(
    is_array($submenu) && ($submenu['parent_slug'] ?? '') === 'index.php',
    'Expected menu registration under the dashboard parent.'
);
$assert(
    is_array($submenu) && ($submenu['menu_slug'] ?? '') === \ClickLink\Admin\Admin_Page::MENU_SLUG,
    'Expected menu slug to match Admin_Page::MENU_SLUG.'
);

$submenu_count = count($clicklink_test_submenus);
$clicklink_test_can_manage = false;
$page->register_menu();
$assert(
    count($clicklink_test_submenus) === $submenu_count,
    'Did not expect submenu registration when capability checks fail.'
);

$clicklink_test_can_manage = true;
$wpdb->eligible_posts_count = 0;

$_GET = array();
$_POST = array();
ob_start();
$page->render();
$rendered_output = (string) ob_get_clean();

$assert(
    str_contains($rendered_output, 'Manual Backfill Scanner'),
    'Expected admin render output to include the manual backfill scanner section.'
);
$assert(
    str_contains($rendered_output, '<button type="submit" class="button button-primary" disabled>Run Now</button>'),
    'Expected Run Now scanner button to be disabled when no published blog posts are eligible.'
);
$assert(
    str_contains($rendered_output, '<button type="submit" class="button button-secondary" disabled>Process Next Batch</button>'),
    'Expected Process Next Batch button to be disabled when no run is active.'
);
$assert(
    str_contains($rendered_output, '<button type="submit" class="button button-secondary" disabled>Cancel / Reset Run</button>'),
    'Expected Cancel / Reset button to be disabled while scanner state is still pending.'
);
$assert(
    str_contains($rendered_output, 'Scanned posts'),
    'Expected scanner panel to render scanned-post counters for run summary visibility.'
);
$assert(
    str_contains($rendered_output, 'Remaining posts'),
    'Expected scanner panel to render remaining-post counters for run summary visibility.'
);
$assert(
    str_contains($rendered_output, 'No published blog posts are currently eligible for backfill.'),
    'Expected safe fallback messaging when no published blog posts are eligible for manual backfill.'
);

$wpdb->eligible_posts_count = 4;
ob_start();
$page->render();
$rendered_output_with_posts = (string) ob_get_clean();

$assert(
    str_contains($rendered_output_with_posts, '<button type="submit" class="button button-primary">Run Now</button>'),
    'Expected Run Now scanner button to be enabled when eligible published posts exist.'
);
$assert(
    str_contains($rendered_output_with_posts, 'Rows per page'),
    'Expected mappings toolbar to render rows-per-page controls for larger datasets.'
);

$_POST = array(
    '_wpnonce' => 'nonce-clicklink_save_mapping',
    'keyword' => '  Summer   Sale  ',
    'url' => 'https://example.com/deals',
);
$page->handle_save_mapping();

$inserted_row = array_values($wpdb->rows)[0] ?? null;
$latest_redirect = end($clicklink_test_redirects);

$assert(
    is_array($inserted_row) && ($inserted_row['keyword'] ?? '') === 'Summer Sale',
    'Expected keyword normalization to trim/collapse spacing while preserving display casing on insert.'
);
$assert(
    is_array($inserted_row) && ($inserted_row['url'] ?? '') === 'https://example.com/deals',
    'Expected URL sanitization to preserve valid URLs on insert.'
);
$assert(
    is_string($latest_redirect) && str_contains($latest_redirect, 'clicklink_notice=created'),
    'Expected successful insert redirect to include created notice.'
);

$mapping_id = (int) (($inserted_row['id'] ?? 0));
$_POST = array(
    '_wpnonce' => 'nonce-clicklink_save_mapping',
    'mapping_id' => (string) $mapping_id,
    'keyword' => 'SUMMER   SALE',
    'url' => 'https://example.com/new',
);
$page->handle_save_mapping();
$latest_redirect = end($clicklink_test_redirects);

$assert(
    ($wpdb->rows[$mapping_id]['keyword'] ?? '') === 'SUMMER SALE',
    'Expected update flow to preserve submitted keyword casing in stored admin values.'
);
$assert(
    ($wpdb->rows[$mapping_id]['url'] ?? '') === 'https://example.com/new',
    'Expected update flow to persist new URL values.'
);
$assert(
    is_string($latest_redirect) && str_contains($latest_redirect, 'clicklink_notice=updated'),
    'Expected successful update redirect to include updated notice.'
);

$rows_before_invalid = count($wpdb->rows);
$_POST = array(
    '_wpnonce' => 'nonce-clicklink_save_mapping',
    'keyword' => 'Keyword',
    'url' => 'not-a-valid-url',
);
$page->handle_save_mapping();
$latest_redirect = end($clicklink_test_redirects);

$assert(
    count($wpdb->rows) === $rows_before_invalid,
    'Did not expect invalid URLs to create mapping rows.'
);
$assert(
    is_string($latest_redirect) && str_contains($latest_redirect, 'clicklink_notice=invalid_url'),
    'Expected malformed URL submissions to surface invalid_url notices.'
);

$_POST = array(
    '_wpnonce' => 'nonce-clicklink_save_mapping',
    'keyword' => '    ',
    'url' => 'https://example.com/keyword-required',
);
$page->handle_save_mapping();
$latest_redirect = end($clicklink_test_redirects);

$assert(
    is_string($latest_redirect) && str_contains($latest_redirect, 'clicklink_notice=keyword_required'),
    'Expected blank keywords to return keyword_required validation feedback.'
);

$_POST = array(
    '_wpnonce' => 'nonce-clicklink_save_mapping',
    'keyword' => 'Keyword',
    'url' => '   ',
);
$page->handle_save_mapping();
$latest_redirect = end($clicklink_test_redirects);

$assert(
    is_string($latest_redirect) && str_contains($latest_redirect, 'clicklink_notice=url_required'),
    'Expected blank URL submissions to return url_required validation feedback.'
);

$_POST = array(
    '_wpnonce' => 'bad-nonce',
    'keyword' => 'Keyword',
    'url' => 'https://example.com',
);
$page->handle_save_mapping();
$latest_redirect = end($clicklink_test_redirects);

$assert(
    is_string($latest_redirect) && str_contains($latest_redirect, 'clicklink_notice=invalid_nonce'),
    'Expected nonce failures to redirect with invalid_nonce notice.'
);

for ($index = 0; $index < 24; $index++) {
    $wpdb->insert(
        'wp_clicklink_keyword_mappings',
        array(
            'keyword' => sprintf('Batch Keyword %02d', $index + 1),
            'url' => sprintf('https://example.com/batch-%02d', $index + 1),
            'created_at' => '2026-04-20 00:00:00',
            'updated_at' => sprintf('2026-04-20 00:%02d:00', $index % 60),
        ),
        array('%s', '%s', '%s', '%s')
    );
}

$wpdb->insert(
    'wp_clicklink_keyword_mappings',
    array(
        'keyword' => 'Promo',
        'url' => 'https://example.com/promo-a',
        'created_at' => '2026-04-20 00:00:00',
        'updated_at' => '2026-04-20 01:00:00',
    ),
    array('%s', '%s', '%s', '%s')
);
$wpdb->insert(
    'wp_clicklink_keyword_mappings',
    array(
        'keyword' => 'Promo',
        'url' => 'https://example.com/promo-b',
        'created_at' => '2026-04-20 00:00:00',
        'updated_at' => '2026-04-20 01:01:00',
    ),
    array('%s', '%s', '%s', '%s')
);

$_GET = array(
    'clicklink_mappings_sort' => 'updated_at',
    'clicklink_mappings_order' => 'desc',
    'clicklink_mappings_page' => '2',
    'clicklink_mappings_per_page' => '10',
);
$_POST = array();
ob_start();
$page->render();
$paged_render = (string) ob_get_clean();

$assert(
    str_contains($paged_render, 'Page 2 of'),
    'Expected mappings view pagination labels when dataset exceeds one page.'
);
$assert(
    str_contains($paged_render, 'Previous page'),
    'Expected mappings view to render previous-page controls on later pages.'
);
$assert(
    str_contains($paged_render, 'Next page'),
    'Expected mappings view to render next-page controls on non-terminal pages.'
);
$assert(
    str_contains($paged_render, 'clicklink_mappings_sort=updated_at'),
    'Expected mappings sort links to preserve/emit sort query arguments.'
);
$assert(
    str_contains($paged_render, 'Delete selected'),
    'Expected mappings toolbar to include a bulk delete action.'
);

$_GET = array(
    'clicklink_mappings_keyword' => 'Promo',
    'clicklink_mappings_per_page' => '50',
);
$_POST = array();
ob_start();
$page->render();
$promo_filtered_render = (string) ob_get_clean();
$promo_row_count = preg_match_all('/<code>Promo<\\/code>/', $promo_filtered_render, $promo_matches);

$assert(
    $promo_row_count === 2,
    'Expected keyword filtering to preserve duplicate keyword rows in admin listings.'
);
$assert(
    str_contains($promo_filtered_render, 'https://example.com/promo-a')
        && str_contains($promo_filtered_render, 'https://example.com/promo-b'),
    'Expected duplicate keyword rows with distinct URLs to remain independently visible after filtering.'
);

$promo_ids = array();
foreach ($wpdb->rows as $row) {
    if (($row['keyword'] ?? '') !== 'Promo') {
        continue;
    }

    $promo_ids[] = (int) ($row['id'] ?? 0);
}

sort($promo_ids, SORT_NUMERIC);

$_POST = array(
    '_wpnonce' => 'nonce-clicklink_bulk_delete_mappings',
    'bulk_action' => 'delete',
    'mapping_ids' => array_map(static fn (int $id): string => (string) $id, $promo_ids),
    'clicklink_mappings_search' => '',
    'clicklink_mappings_keyword' => 'Promo',
    'clicklink_mappings_sort' => 'keyword',
    'clicklink_mappings_order' => 'asc',
    'clicklink_mappings_page' => '1',
    'clicklink_mappings_per_page' => '50',
);
$page->handle_bulk_delete_mappings();
$latest_redirect = end($clicklink_test_redirects);

$assert(
    count($promo_ids) === 2
        && ! isset($wpdb->rows[$promo_ids[0]])
        && ! isset($wpdb->rows[$promo_ids[1]]),
    'Expected bulk delete actions to remove all selected mapping rows.'
);
$assert(
    is_string($latest_redirect)
        && str_contains($latest_redirect, 'clicklink_notice=bulk_deleted')
        && str_contains($latest_redirect, 'clicklink_deleted_count=2'),
    'Expected bulk delete redirects to include both success notice and deleted-row count.'
);

$_POST = array(
    '_wpnonce' => 'nonce-clicklink_bulk_delete_mappings',
    'bulk_action' => '',
    'mapping_ids' => array((string) $mapping_id),
);
$page->handle_bulk_delete_mappings();
$latest_redirect = end($clicklink_test_redirects);

$assert(
    is_string($latest_redirect) && str_contains($latest_redirect, 'clicklink_notice=bulk_action_required'),
    'Expected bulk delete requests without an action to return bulk_action_required notices.'
);

$_POST = array(
    '_wpnonce' => 'nonce-clicklink_bulk_delete_mappings',
    'bulk_action' => 'delete',
);
$page->handle_bulk_delete_mappings();
$latest_redirect = end($clicklink_test_redirects);

$assert(
    is_string($latest_redirect) && str_contains($latest_redirect, 'clicklink_notice=bulk_selection_required'),
    'Expected bulk delete requests without selected rows to return bulk_selection_required notices.'
);

$_POST = array(
    '_wpnonce' => 'bad-nonce',
    'bulk_action' => 'delete',
    'mapping_ids' => array((string) $mapping_id),
);
$page->handle_bulk_delete_mappings();
$latest_redirect = end($clicklink_test_redirects);

$assert(
    is_string($latest_redirect) && str_contains($latest_redirect, 'clicklink_notice=invalid_nonce'),
    'Expected bulk delete requests to enforce nonce validation.'
);

$wpdb->eligible_posts_count = 0;
$_POST = array(
    '_wpnonce' => 'nonce-clicklink_backfill_start',
);
$page->handle_start_scan();
$latest_redirect = end($clicklink_test_redirects);

$assert(
    is_string($latest_redirect) && str_contains($latest_redirect, 'clicklink_notice=scan_no_posts'),
    'Expected manual scan start action to safely fallback when no posts are eligible.'
);

$wpdb->eligible_posts_count = 3;
$_POST = array(
    '_wpnonce' => 'bad-nonce',
);
$page->handle_start_scan();
$latest_redirect = end($clicklink_test_redirects);

$assert(
    is_string($latest_redirect) && str_contains($latest_redirect, 'clicklink_notice=invalid_nonce'),
    'Expected manual scan start action to enforce nonce validation.'
);

$wpdb->eligible_posts_count = 3;
$_POST = array(
    '_wpnonce' => 'nonce-clicklink_backfill_start',
);
$page->handle_start_scan();
$latest_redirect = end($clicklink_test_redirects);

$assert(
    is_string($latest_redirect) && str_contains($latest_redirect, 'clicklink_notice=scan_started'),
    'Expected manual scan start action to initialize run state when eligible posts exist.'
);

$_POST = array(
    '_wpnonce' => 'nonce-clicklink_backfill_start',
    'batch_size' => 'not-a-number',
);
$page->handle_start_scan();
$latest_redirect = end($clicklink_test_redirects);
$malformed_batch_state = get_option('clicklink_backfill_run_state', array());

$assert(
    is_string($latest_redirect) && str_contains($latest_redirect, 'clicklink_notice=scan_already_running'),
    'Expected malformed start request payloads during active runs to fail safely without fatal errors.'
);
$assert(
    is_array($malformed_batch_state) && (int) ($malformed_batch_state['batch_size'] ?? 0) >= 1,
    'Expected malformed batch_size payloads to preserve an existing valid scanner batch size.'
);

$_POST = array(
    '_wpnonce' => 'bad-nonce',
);
$page->handle_next_batch();
$latest_redirect = end($clicklink_test_redirects);

$assert(
    is_string($latest_redirect) && str_contains($latest_redirect, 'clicklink_notice=invalid_nonce'),
    'Expected next-batch admin_post action to enforce nonce validation.'
);

$_POST = array(
    '_wpnonce' => 'nonce-clicklink_backfill_next_batch',
);
$page->handle_next_batch();
$latest_redirect = end($clicklink_test_redirects);

$assert(
    is_string($latest_redirect) && str_contains($latest_redirect, 'clicklink_notice=scan_completed'),
    'Expected next-batch admin_post action to drive scanner runs toward completion.'
);

$_POST = array(
    '_wpnonce' => 'bad-nonce',
);
$page->handle_reset_scan();
$latest_redirect = end($clicklink_test_redirects);

$assert(
    is_string($latest_redirect) && str_contains($latest_redirect, 'clicklink_notice=invalid_nonce'),
    'Expected reset admin_post action to enforce nonce validation.'
);

$_POST = array(
    '_wpnonce' => 'nonce-clicklink_backfill_reset',
);
$page->handle_reset_scan();
$latest_redirect = end($clicklink_test_redirects);

$assert(
    is_string($latest_redirect) && str_contains($latest_redirect, 'clicklink_notice=scan_reset'),
    'Expected reset admin_post action to return scanner state to pending.'
);

$_POST = array(
    '_wpnonce' => 'nonce-clicklink_backfill_next_batch',
);
$page->handle_next_batch();
$latest_redirect = end($clicklink_test_redirects);

$assert(
    is_string($latest_redirect) && str_contains($latest_redirect, 'clicklink_notice=scan_not_running'),
    'Expected next-batch action to require an active running scan.'
);

$clicklink_test_can_manage = true;
$clicklink_test_json_responses = array();
$wpdb->eligible_posts_count = 2;
$_POST = array(
    '_wpnonce' => 'nonce-clicklink_backfill_start',
);
$page->handle_start_scan_ajax();
$ajax_start = end($clicklink_test_json_responses);

$assert(
    is_array($ajax_start)
        && (int) ($ajax_start['status_code'] ?? 0) === 200
        && (($ajax_start['payload']['success'] ?? false) === true)
        && (($ajax_start['payload']['notice'] ?? '') === 'scan_started'),
    'Expected start AJAX endpoint to return a successful JSON payload for valid requests.'
);

$clicklink_test_json_responses = array();
$_POST = array(
    '_wpnonce' => 'nonce-clicklink_backfill_next_batch',
);
$page->handle_next_batch_ajax();
$ajax_next = end($clicklink_test_json_responses);

$assert(
    is_array($ajax_next)
        && (int) ($ajax_next['status_code'] ?? 0) === 200
        && (($ajax_next['payload']['success'] ?? false) === true)
        && (($ajax_next['payload']['notice'] ?? '') === 'scan_completed'),
    'Expected next-batch AJAX endpoint to return completion payloads when scanner batches finish.'
);

$clicklink_test_json_responses = array();
$_POST = array(
    '_wpnonce' => 'nonce-clicklink_backfill_reset',
);
$page->handle_reset_scan_ajax();
$ajax_reset = end($clicklink_test_json_responses);

$assert(
    is_array($ajax_reset)
        && (int) ($ajax_reset['status_code'] ?? 0) === 200
        && (($ajax_reset['payload']['success'] ?? false) === true)
        && (($ajax_reset['payload']['notice'] ?? '') === 'scan_reset'),
    'Expected reset AJAX endpoint to return successful JSON payloads when scanner reset succeeds.'
);

$clicklink_test_json_responses = array();
$_POST = array();
$page->handle_start_scan_ajax();
$ajax_invalid_nonce = end($clicklink_test_json_responses);

$assert(
    is_array($ajax_invalid_nonce)
        && (int) ($ajax_invalid_nonce['status_code'] ?? 0) === 403
        && (($ajax_invalid_nonce['payload']['success'] ?? true) === false)
        && (($ajax_invalid_nonce['payload']['notice'] ?? '') === 'invalid_nonce'),
    'Expected scanner AJAX endpoints to reject malformed requests that omit nonce payloads.'
);

$clicklink_test_can_manage = false;
$clicklink_test_json_responses = array();
$_POST = array(
    '_wpnonce' => 'nonce-clicklink_backfill_start',
);
$page->handle_start_scan_ajax();
$ajax_forbidden = end($clicklink_test_json_responses);

$assert(
    is_array($ajax_forbidden)
        && (int) ($ajax_forbidden['status_code'] ?? 0) === 403
        && (($ajax_forbidden['payload']['success'] ?? true) === false)
        && (($ajax_forbidden['payload']['notice'] ?? '') === 'forbidden'),
    'Expected scanner AJAX endpoints to reject invalid-permission requests with explicit forbidden responses.'
);

$clicklink_test_can_manage = true;
$_POST = array(
    '_wpnonce' => 'nonce-clicklink_delete_mapping',
    'mapping_id' => (string) $mapping_id,
);
$page->handle_delete_mapping();
$latest_redirect = end($clicklink_test_redirects);

$assert(
    ! isset($wpdb->rows[$mapping_id]),
    'Expected delete flow to remove existing mapping rows.'
);
$assert(
    is_string($latest_redirect) && str_contains($latest_redirect, 'clicklink_notice=deleted'),
    'Expected successful delete redirect to include deleted notice.'
);

$_POST = array(
    '_wpnonce' => 'nonce-clicklink_delete_mapping',
    'mapping_id' => (string) $mapping_id,
);
$page->handle_delete_mapping();
$latest_redirect = end($clicklink_test_redirects);

$assert(
    is_string($latest_redirect) && str_contains($latest_redirect, 'clicklink_notice=not_found'),
    'Expected deleting a missing row to redirect with not_found notice.'
);

$clicklink_test_can_manage = false;
$threw_for_capability = false;

try {
    $_POST = array(
        '_wpnonce' => 'nonce-clicklink_save_mapping',
        'keyword' => 'Keyword',
        'url' => 'https://example.com',
    );
    $page->handle_save_mapping();
} catch (RuntimeException $exception) {
    $threw_for_capability = true;
    $assert(
        str_contains($exception->getMessage(), 'permission'),
        'Expected denied access messages when capability checks fail.'
    );
}

$assert(
    $threw_for_capability === true,
    'Expected save handler to deny access when capability checks fail.'
);

$threw_for_start_scan_capability = false;

try {
    $_POST = array(
        '_wpnonce' => 'nonce-clicklink_backfill_start',
    );
    $page->handle_start_scan();
} catch (RuntimeException $exception) {
    $threw_for_start_scan_capability = true;
}

$assert(
    $threw_for_start_scan_capability === true,
    'Expected start scan handler to deny access when capability checks fail.'
);

$threw_for_bulk_delete_capability = false;

try {
    $_POST = array(
        '_wpnonce' => 'nonce-clicklink_bulk_delete_mappings',
        'bulk_action' => 'delete',
        'mapping_ids' => array('1'),
    );
    $page->handle_bulk_delete_mappings();
} catch (RuntimeException $exception) {
    $threw_for_bulk_delete_capability = true;
}

$assert(
    $threw_for_bulk_delete_capability === true,
    'Expected bulk delete handler to deny access when capability checks fail.'
);

$_POST = array();
$_GET = array();

if ($failures !== array()) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}\n");
    }

    exit(1);
}

echo "PASS: admin page\n";
