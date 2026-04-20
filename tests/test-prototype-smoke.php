<?php

declare(strict_types=1);

final class ClickLink_Test_Smoke_WPDB
{
    public string $prefix = 'wp_';
    public string $posts = 'wp_posts';

    /**
     * @var array<int, array{id: int, keyword: string, url: string, created_at: string, updated_at: string}>
     */
    public array $mappings = array();

    private int $next_mapping_id = 1;

    public function get_charset_collate(): string
    {
        return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci';
    }

    /**
     * @return array<int, array<string, string|int>>
     */
    public function get_results(string $query, string $output = 'OBJECT'): array
    {
        $rows = $this->sorted_mappings();

        if (str_contains($query, 'SELECT keyword, url')) {
            return array_map(
                static fn (array $row): array => array(
                    'keyword' => (string) $row['keyword'],
                    'url' => (string) $row['url'],
                ),
                $rows
            );
        }

        if (str_contains($query, 'SELECT id, keyword, url, created_at, updated_at')) {
            return $rows;
        }

        return array();
    }

    /**
     * @return array<string, string|int>|null
     */
    public function get_row(string $query, string $output = 'OBJECT'): ?array
    {
        if (! preg_match('/WHERE id = (\d+)/i', $query, $matches)) {
            return null;
        }

        $mapping_id = (int) ($matches[1] ?? 0);

        foreach ($this->mappings as $row) {
            if ((int) $row['id'] !== $mapping_id) {
                continue;
            }

            if (str_contains($query, 'SELECT id FROM')) {
                return array('id' => $mapping_id);
            }

            return array(
                'id' => $mapping_id,
                'keyword' => (string) $row['keyword'],
                'url' => (string) $row['url'],
            );
        }

        return null;
    }

    /**
     * @return int
     */
    public function get_var(string $query)
    {
        if (str_contains($query, 'clicklink_keyword_mappings')) {
            return count($this->mappings);
        }

        return 0;
    }

    /**
     * @param array<string, string> $data
     * @param array<int, string> $format
     * @return int|false
     */
    public function insert(string $table, array $data, array $format = array())
    {
        $mapping_id = $this->next_mapping_id;
        $this->next_mapping_id++;

        $this->mappings[$mapping_id] = array(
            'id' => $mapping_id,
            'keyword' => (string) ($data['keyword'] ?? ''),
            'url' => (string) ($data['url'] ?? ''),
            'created_at' => (string) ($data['created_at'] ?? ''),
            'updated_at' => (string) ($data['updated_at'] ?? ''),
        );

        return 1;
    }

    /**
     * @param array<string, string> $data
     * @param array<string, int> $where
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
        $mapping_id = (int) ($where['id'] ?? 0);

        if ($mapping_id <= 0 || ! isset($this->mappings[$mapping_id])) {
            return false;
        }

        $existing = $this->mappings[$mapping_id];
        $existing['keyword'] = (string) ($data['keyword'] ?? $existing['keyword']);
        $existing['url'] = (string) ($data['url'] ?? $existing['url']);
        $existing['updated_at'] = (string) ($data['updated_at'] ?? $existing['updated_at']);
        $this->mappings[$mapping_id] = $existing;

        return 1;
    }

    /**
     * @param array<string, int> $where
     * @param array<int, string> $where_format
     * @return int|false
     */
    public function delete(string $table, array $where, array $where_format = array())
    {
        $mapping_id = (int) ($where['id'] ?? 0);

        if ($mapping_id <= 0 || ! isset($this->mappings[$mapping_id])) {
            return false;
        }

        unset($this->mappings[$mapping_id]);

        return 1;
    }

    /**
     * @param mixed ...$args
     */
    public function prepare(string $query, ...$args): string
    {
        foreach ($args as $arg) {
            $query = preg_replace('/%d/', (string) ((int) $arg), $query, 1) ?? $query;
        }

        return $query;
    }

    /**
     * @return array<int, array{id: int, keyword: string, url: string, created_at: string, updated_at: string}>
     */
    private function sorted_mappings(): array
    {
        $rows = array_values($this->mappings);

        usort(
            $rows,
            static function (array $left, array $right): int {
                $keyword_compare = strcmp((string) $left['keyword'], (string) $right['keyword']);

                if ($keyword_compare !== 0) {
                    return $keyword_compare;
                }

                return ((int) $left['id']) <=> ((int) $right['id']);
            }
        );

        return $rows;
    }
}

$clicklink_test_actions = array();
$clicklink_test_activation_hooks = array();
$clicklink_test_deactivation_hooks = array();
$clicklink_test_dashboard_widgets = array();
$clicklink_test_redirects = array();
$clicklink_test_updates = array();
$clicklink_test_dbdelta_calls = array();
$clicklink_test_options = array();
$clicklink_test_post_meta = array();
$clicklink_test_posts = array(
    100 => array(
        'post_type' => 'post',
        'post_status' => 'publish',
        'post_content' => '<p>Existing post.</p>',
    ),
    101 => array(
        'post_type' => 'post',
        'post_status' => 'draft',
        'post_content' => '<p>Draft post.</p>',
    ),
    102 => array(
        'post_type' => 'post',
        'post_status' => 'auto-draft',
        'post_content' => '<p>Auto draft post.</p>',
    ),
);
$wp_version = '6.5';

if (! defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}

if (! function_exists('plugin_dir_path')) {
    function plugin_dir_path(string $file): string
    {
        return rtrim(dirname($file), '/\\') . '/';
    }
}

if (! function_exists('plugin_dir_url')) {
    function plugin_dir_url(string $file): string
    {
        return 'https://example.test/wp-content/plugins/clicklink/';
    }
}

if (! function_exists('register_activation_hook')) {
    function register_activation_hook(string $file, callable $callback): void
    {
        global $clicklink_test_activation_hooks;

        $clicklink_test_activation_hooks[$file] = $callback;
    }
}

if (! function_exists('register_deactivation_hook')) {
    function register_deactivation_hook(string $file, callable $callback): void
    {
        global $clicklink_test_deactivation_hooks;

        $clicklink_test_deactivation_hooks[$file] = $callback;
    }
}

if (! function_exists('add_action')) {
    function add_action(string $hook, callable $callback, int $priority = 10, int $accepted_args = 1): void
    {
        global $clicklink_test_actions;

        $clicklink_test_actions[$hook][] = array(
            'callback' => $callback,
            'priority' => $priority,
            'accepted_args' => $accepted_args,
        );
    }
}

if (! function_exists('current_user_can')) {
    function current_user_can(string $capability): bool
    {
        return true;
    }
}

if (! function_exists('is_admin')) {
    function is_admin(): bool
    {
        return true;
    }
}

if (! function_exists('is_multisite')) {
    function is_multisite(): bool
    {
        return false;
    }
}

if (! function_exists('deactivate_plugins')) {
    function deactivate_plugins(string $plugin): void
    {
        global $clicklink_test_deactivation_hooks;

        $clicklink_test_deactivation_hooks[] = $plugin;
    }
}

if (! function_exists('plugin_basename')) {
    function plugin_basename(string $file): string
    {
        return basename($file);
    }
}

if (! function_exists('wp_die')) {
    function wp_die(string $message): void
    {
        throw new RuntimeException($message);
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

if (! function_exists('dbDelta')) {
    function dbDelta(string $sql): void
    {
        global $clicklink_test_dbdelta_calls;

        $clicklink_test_dbdelta_calls[] = $sql;
    }
}

if (! function_exists('sanitize_text_field')) {
    function sanitize_text_field(string $text): string
    {
        return trim(strip_tags($text));
    }
}

if (! function_exists('esc_url_raw')) {
    function esc_url_raw(string $text): string
    {
        return (string) filter_var($text, FILTER_SANITIZE_URL);
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

if (! function_exists('esc_url')) {
    function esc_url(string $text): string
    {
        return (string) filter_var($text, FILTER_SANITIZE_URL);
    }
}

if (! function_exists('wp_is_post_autosave')) {
    /**
     * @return int|false
     */
    function wp_is_post_autosave(int $post_id)
    {
        return false;
    }
}

if (! function_exists('wp_is_post_revision')) {
    /**
     * @return int|false
     */
    function wp_is_post_revision(int $post_id)
    {
        return false;
    }
}

if (! function_exists('wp_update_post')) {
    /**
     * @param array<string, mixed> $postarr
     * @return int|false
     */
    function wp_update_post(array $postarr, bool $wp_error = false)
    {
        global $clicklink_test_updates;
        global $clicklink_test_posts;

        $post_id = (int) ($postarr['ID'] ?? 0);

        if ($post_id <= 0) {
            return false;
        }

        $clicklink_test_updates[] = $postarr;

        if (! isset($clicklink_test_posts[$post_id]) || ! is_array($clicklink_test_posts[$post_id])) {
            $clicklink_test_posts[$post_id] = array(
                'post_type' => 'post',
                'post_status' => 'publish',
                'post_content' => '',
            );
        }

        if (isset($postarr['post_content']) && is_string($postarr['post_content'])) {
            $clicklink_test_posts[$post_id]['post_content'] = $postarr['post_content'];
        }

        return $post_id;
    }
}

if (! function_exists('get_post_meta')) {
    /**
     * @return mixed
     */
    function get_post_meta(int $post_id, string $key, bool $single = false)
    {
        global $clicklink_test_post_meta;

        if (! isset($clicklink_test_post_meta[$post_id]) || ! array_key_exists($key, $clicklink_test_post_meta[$post_id])) {
            return $single ? '' : array();
        }

        $value = $clicklink_test_post_meta[$post_id][$key];

        if ($single) {
            return $value;
        }

        return array($value);
    }
}

if (! function_exists('update_post_meta')) {
    /**
     * @param mixed $value
     */
    function update_post_meta(int $post_id, string $key, $value): void
    {
        global $clicklink_test_post_meta;

        if (! isset($clicklink_test_post_meta[$post_id]) || ! is_array($clicklink_test_post_meta[$post_id])) {
            $clicklink_test_post_meta[$post_id] = array();
        }

        $clicklink_test_post_meta[$post_id][$key] = $value;
    }
}

if (! function_exists('wp_rand')) {
    function wp_rand(int $min = 0, int $max = 0): int
    {
        return $min;
    }
}

if (! function_exists('wp_safe_redirect')) {
    function wp_safe_redirect(string $location): void
    {
        global $clicklink_test_redirects;

        $clicklink_test_redirects[] = $location;
    }
}

if (! function_exists('admin_url')) {
    function admin_url(string $path = ''): string
    {
        return 'https://example.test/wp-admin/' . ltrim($path, '/');
    }
}

if (! function_exists('add_query_arg')) {
    /**
     * @param array<string, string> $args
     */
    function add_query_arg(array $args, string $url): string
    {
        $query = http_build_query($args);
        $separator = str_contains($url, '?') ? '&' : '?';

        return $query === '' ? $url : $url . $separator . $query;
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

if (! function_exists('wp_create_nonce')) {
    function wp_create_nonce(string $action): string
    {
        return 'nonce-' . $action;
    }
}

if (! function_exists('wp_nonce_field')) {
    function wp_nonce_field(
        string $action,
        string $name = '_wpnonce',
        bool $referer = true,
        bool $echo = true
    ): string {
        return '<input type="hidden" name="' . $name . '" value="' . wp_create_nonce($action) . '">';
    }
}

if (! function_exists('wp_unslash')) {
    function wp_unslash(string $value): string
    {
        return stripslashes($value);
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

if (! function_exists('number_format_i18n')) {
    function number_format_i18n(int $number): string
    {
        return number_format($number);
    }
}

if (! function_exists('wp_add_dashboard_widget')) {
    function wp_add_dashboard_widget(string $widget_id, string $title, callable $callback): void
    {
        global $clicklink_test_dashboard_widgets;

        $clicklink_test_dashboard_widgets[] = array(
            'widget_id' => $widget_id,
            'title' => $title,
            'callback' => $callback,
        );
    }
}

if (! function_exists('wp_count_posts')) {
    /**
     * @return object
     */
    function wp_count_posts(string $type = 'post')
    {
        global $clicklink_test_posts;

        $counts = array(
            'publish' => 0,
            'draft' => 0,
            'auto-draft' => 0,
        );

        foreach ($clicklink_test_posts as $post) {
            if (! is_array($post)) {
                continue;
            }

            $post_type = (string) ($post['post_type'] ?? '');

            if ($post_type !== $type) {
                continue;
            }

            $status = (string) ($post['post_status'] ?? 'draft');

            if (! isset($counts[$status])) {
                $counts[$status] = 0;
            }

            $counts[$status]++;
        }

        return (object) $counts;
    }
}

if (! function_exists('current_time')) {
    function current_time(string $type = 'mysql', bool $gmt = false): string
    {
        return '2026-04-20 00:00:00';
    }
}

global $wpdb;
$wpdb = new ClickLink_Test_Smoke_WPDB();

require_once __DIR__ . '/../clicklink.php';

$failures = array();

$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (! $condition) {
        $failures[] = $message;
    }
};

$activation_callback = reset($clicklink_test_activation_hooks);
if (is_callable($activation_callback)) {
    call_user_func($activation_callback);
}

$assert(
    count($clicklink_test_dbdelta_calls) === 1,
    'Expected activation flow to run installer schema migration once.'
);
$assert(
    ($clicklink_test_options['clicklink_options']['max_links_per_post'] ?? null) === 5,
    'Expected activation flow to initialize max_links_per_post default.'
);
$assert(
    ! in_array('clicklink.php', $clicklink_test_deactivation_hooks, true),
    'Did not expect plugin deactivation for a supported smoke environment.'
);

$plugins_loaded_hooks = $clicklink_test_actions['plugins_loaded'] ?? array();
foreach ($plugins_loaded_hooks as $hook) {
    if (! is_array($hook) || ! isset($hook['callback']) || ! is_callable($hook['callback'])) {
        continue;
    }

    call_user_func($hook['callback']);
}

$assert(
    isset($clicklink_test_actions['admin_post_clicklink_save_mapping']),
    'Expected Plugin::run() to register admin save-mapping handler in admin context.'
);
$assert(
    isset($clicklink_test_actions['admin_post_clicklink_backfill_start']),
    'Expected Plugin::run() to register manual backfill start handler in admin context.'
);
$assert(
    isset($clicklink_test_actions['save_post_post']),
    'Expected Plugin::run() to register post-save linker handler.'
);
$assert(
    isset($clicklink_test_actions['wp_dashboard_setup']),
    'Expected Plugin::run() to register dashboard widget setup hook.'
);

$save_mapping_hooks = $clicklink_test_actions['admin_post_clicklink_save_mapping'] ?? array();
$save_mapping_hook = $save_mapping_hooks[0] ?? null;

if (is_array($save_mapping_hook) && isset($save_mapping_hook['callback']) && is_callable($save_mapping_hook['callback'])) {
    $_POST = array(
        '_wpnonce' => 'nonce-clicklink_save_mapping',
        'keyword' => 'Apple',
        'url' => 'https://example.com/apple-a',
    );
    call_user_func($save_mapping_hook['callback']);

    $_POST = array(
        '_wpnonce' => 'nonce-clicklink_save_mapping',
        'keyword' => 'Apple',
        'url' => 'https://example.com/apple-b',
    );
    call_user_func($save_mapping_hook['callback']);
}

$_POST = array();

$assert(
    count($wpdb->mappings) === 2,
    'Expected smoke path to persist two sample keyword mappings.'
);
$assert(
    array_column(array_values($wpdb->mappings), 'keyword') === array('Apple', 'Apple'),
    'Expected admin save flow to preserve display casing in stored keywords.'
);
$assert(
    count($clicklink_test_redirects) === 2
        && str_contains((string) $clicklink_test_redirects[0], 'clicklink_notice=created')
        && str_contains((string) $clicklink_test_redirects[1], 'clicklink_notice=created'),
    'Expected mapping creation actions to redirect with success notices.'
);

$sample_content = '<h2>Apple heading</h2>'
    . '<p>Apple appears in this paragraph and apple appears again.</p>'
    . '<p><code>apple</code> should stay plain and <a href="https://external.example.com/apple">apple</a> stays linked; Apple in text should link.</p>'
    . '<pre>apple in pre block</pre>'
    . '<div>apple in div block</div>';
$clicklink_test_posts[9001] = array(
    'post_type' => 'post',
    'post_status' => 'publish',
    'post_content' => $sample_content,
);

$save_post_hooks = $clicklink_test_actions['save_post_post'] ?? array();
$save_post_hook = $save_post_hooks[0] ?? null;

if (is_array($save_post_hook) && isset($save_post_hook['callback']) && is_callable($save_post_hook['callback'])) {
    $post = (object) array(
        'ID' => 9001,
        'post_type' => 'post',
        'post_content' => $sample_content,
    );

    call_user_func($save_post_hook['callback'], 9001, $post, false);
}

$updated_post = $clicklink_test_updates[0] ?? array();
$updated_content = (string) ($updated_post['post_content'] ?? '');
$linked_keyword_count = preg_match_all('/href="https:\/\/example\.com\/apple-(?:a|b)"/', $updated_content, $linked_keyword_matches);
$inserted_link_count = max(0, (int) $linked_keyword_count);

$assert(
    count($clicklink_test_updates) === 1,
    'Expected smoke post save to update content exactly once.'
);
$assert(
    $inserted_link_count === 3,
    'Expected smoke post save to link all eligible paragraph keyword matches when duplicate keyword mappings exist.'
);
$assert(
    str_contains($updated_content, '<h2>Apple heading</h2>'),
    'Expected smoke post save to skip linking heading content.'
);
$assert(
    str_contains($updated_content, '<code>apple</code>'),
    'Expected smoke post save to keep code-tag keyword content untouched.'
);
$assert(
    str_contains($updated_content, '<a href="https://external.example.com/apple">apple</a>'),
    'Expected smoke post save to keep existing anchor-tag keyword content untouched.'
);
$assert(
    str_contains($updated_content, '<pre>apple in pre block</pre>'),
    'Expected smoke post save to keep pre-tag keyword content untouched.'
);
$assert(
    str_contains($updated_content, '<div>apple in div block</div>'),
    'Expected smoke post save to keep non-paragraph block keyword content untouched.'
);
$assert(
    (int) ($clicklink_test_options['clicklink_stats']['total_links_inserted'] ?? 0) === $inserted_link_count,
    'Expected smoke post save to align total_links_inserted with actual inserted link count.'
);
$assert(
    (int) ($clicklink_test_options['clicklink_stats']['posts_touched'] ?? 0) === 1,
    'Expected smoke post save to increment posts_touched.'
);
$assert(
    (int) ($clicklink_test_post_meta[9001]['_clicklink_links_inserted_last_save'] ?? 0) === $inserted_link_count,
    'Expected smoke post save to align per-post last-save link counter with actual inserted link count.'
);
$assert(
    (int) ($clicklink_test_post_meta[9001]['_clicklink_links_inserted_total'] ?? 0) === $inserted_link_count,
    'Expected smoke post save to align per-post cumulative link counter with actual inserted link count.'
);

$dashboard_hooks = $clicklink_test_actions['wp_dashboard_setup'] ?? array();
foreach ($dashboard_hooks as $hook) {
    if (! is_array($hook) || ! isset($hook['callback']) || ! is_callable($hook['callback'])) {
        continue;
    }

    call_user_func($hook['callback']);
}

$dashboard_widget = $clicklink_test_dashboard_widgets[0] ?? null;
$widget_output = '';

if (is_array($dashboard_widget) && isset($dashboard_widget['callback']) && is_callable($dashboard_widget['callback'])) {
    ob_start();
    call_user_func($dashboard_widget['callback']);
    $widget_output = (string) ob_get_clean();
}

$assert(
    is_array($dashboard_widget) && ($dashboard_widget['widget_id'] ?? '') === 'clicklink_dashboard_widget',
    'Expected smoke path to register the ClickLink dashboard widget.'
);
$assert(
    str_contains($widget_output, '<th scope="row">Total blog posts</th><td>3</td>'),
    'Expected dashboard widget smoke output to show total blog post count.'
);
$assert(
    str_contains($widget_output, '<th scope="row">Total keyword/url rows</th><td>2</td>'),
    'Expected dashboard widget smoke output to show saved mapping count.'
);
$assert(
    str_contains($widget_output, '<th scope="row">Total links inserted</th><td>' . (string) $inserted_link_count . '</td>'),
    'Expected dashboard widget smoke output to align cumulative inserted links with actual inserted link count.'
);
$assert(
    str_contains($widget_output, '<th scope="row">Posts touched by linker</th><td>1</td>'),
    'Expected dashboard widget smoke output to show posts touched by linker.'
);

if ($failures !== array()) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}\n");
    }

    exit(1);
}

echo "PASS: prototype smoke\n";
