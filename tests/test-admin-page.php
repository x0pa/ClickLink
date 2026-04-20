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

        $value = (int) ($args[0] ?? 0);

        return str_replace('%d', (string) $value, $query);
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

        return max(0, $this->eligible_posts_count);
    }
}

$clicklink_test_actions = array();
$clicklink_test_submenus = array();
$clicklink_test_redirects = array();
$clicklink_test_can_manage = true;

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
    isset($clicklink_test_actions['admin_post_clicklink_backfill_start']),
    'Expected register() to hook manual backfill start admin_post action.'
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
    is_string($latest_redirect) && str_contains($latest_redirect, 'clicklink_notice=invalid_input'),
    'Expected invalid input redirect notice for malformed URLs.'
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

$_POST = array();
$_GET = array();

if ($failures !== array()) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}\n");
    }

    exit(1);
}

echo "PASS: admin page\n";
