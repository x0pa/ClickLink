<?php

declare(strict_types=1);

final class ClickLink_Test_Focused_WPDB
{
    public string $prefix = 'wp_';
    public int $get_results_calls = 0;

    /**
     * @var array<int, array{keyword: string, url: string}>
     */
    public array $mappings = array();

    /**
     * @return array<int, array{keyword: string, url: string}>
     */
    public function get_results(string $query, string $output = 'OBJECT'): array
    {
        $this->get_results_calls++;

        return $this->mappings;
    }
}

$clicklink_test_actions = array();
$clicklink_test_options = array();
$clicklink_test_post_meta = array();
$clicklink_test_autosave_ids = array();
$clicklink_test_revision_ids = array();
$clicklink_test_updates = array();
$clicklink_test_rand_sequence = array();

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

if (! function_exists('wp_is_post_autosave')) {
    /**
     * @return int|false
     */
    function wp_is_post_autosave(int $post_id)
    {
        global $clicklink_test_autosave_ids;

        return in_array($post_id, $clicklink_test_autosave_ids, true) ? $post_id : false;
    }
}

if (! function_exists('wp_is_post_revision')) {
    /**
     * @return int|false
     */
    function wp_is_post_revision(int $post_id)
    {
        global $clicklink_test_revision_ids;

        return in_array($post_id, $clicklink_test_revision_ids, true) ? $post_id : false;
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

        $clicklink_test_updates[] = $postarr;

        return isset($postarr['ID']) ? (int) $postarr['ID'] : false;
    }
}

if (! function_exists('wp_rand')) {
    function wp_rand(int $min = 0, int $max = 0): int
    {
        global $clicklink_test_rand_sequence;

        if ($clicklink_test_rand_sequence === array()) {
            return $min;
        }

        return (int) array_shift($clicklink_test_rand_sequence);
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

global $wpdb;
$wpdb = new ClickLink_Test_Focused_WPDB();

require_once __DIR__ . '/fixtures/linker-content.php';
require_once __DIR__ . '/../includes/class-installer.php';
require_once __DIR__ . '/../includes/class-keyword-mapping-repository.php';
require_once __DIR__ . '/../includes/class-linker-stats.php';
require_once __DIR__ . '/../includes/class-post-save-linker.php';

$failures = array();

$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (! $condition) {
        $failures[] = $message;
    }
};

$reset_environment = static function (): void {
    global $clicklink_test_options;
    global $clicklink_test_post_meta;
    global $clicklink_test_autosave_ids;
    global $clicklink_test_revision_ids;
    global $clicklink_test_updates;
    global $clicklink_test_rand_sequence;
    global $wpdb;

    $clicklink_test_options = array(
        'clicklink_options' => array(
            'max_links_per_post' => 5,
        ),
    );
    $clicklink_test_post_meta = array();
    $clicklink_test_autosave_ids = array();
    $clicklink_test_revision_ids = array();
    $clicklink_test_updates = array();
    $clicklink_test_rand_sequence = array();

    if (is_object($wpdb) && property_exists($wpdb, 'get_results_calls')) {
        $wpdb->get_results_calls = 0;
    }
};

$run_save = static function (\ClickLink\Post_Save_Linker $linker, int $post_id, string $content, bool $update): string {
    global $clicklink_test_updates;

    $clicklink_test_updates = array();
    $post = (object) array(
        'ID' => $post_id,
        'post_type' => 'post',
        'post_content' => $content,
    );

    $linker->handle_post_save($post_id, $post, $update);

    $updated_post = $clicklink_test_updates[0] ?? array();

    if (! is_array($updated_post) || ! isset($updated_post['post_content']) || ! is_string($updated_post['post_content'])) {
        return $content;
    }

    return $updated_post['post_content'];
};

$linker = new \ClickLink\Post_Save_Linker();
$linker->register();

$save_post_hooks = $clicklink_test_actions['save_post_post'] ?? array();
$hook_registration = $save_post_hooks[0] ?? null;

$assert(
    is_array($hook_registration),
    'Expected Post_Save_Linker::register() to hook save_post_post for focused behavior tests.'
);

$reset_environment();
$linker = new \ClickLink\Post_Save_Linker();
$wpdb->mappings = array(
    array('keyword' => 'art', 'url' => 'https://example.com/art'),
);

$boundary_content = clicklink_fixture_keyword_boundary_content();
$updated_boundary_content = $run_save($linker, 1101, $boundary_content, false);
$boundary_link_count = preg_match_all('/<a href="https:\/\/example\.com\/art">/', $updated_boundary_content, $boundary_matches);

$assert(
    $boundary_link_count === 2,
    'Expected keyword matching to respect word boundaries and skip partial-word matches.'
);
$assert(
    str_contains($updated_boundary_content, '<a href="https://example.com/art">Art</a>'),
    'Expected linked keyword output to preserve original matched casing.'
);
$assert(
    ! str_contains($updated_boundary_content, '<a href="https://example.com/art">art</a>ful'),
    'Did not expect keyword matching inside larger words such as artful.'
);
$assert(
    ! str_contains($updated_boundary_content, 'st<a href="https://example.com/art">art</a>'),
    'Did not expect keyword matching inside larger words such as start.'
);

$reset_environment();
$linker = new \ClickLink\Post_Save_Linker();
$wpdb->mappings = array(
    array('keyword' => 'apple', 'url' => 'https://example.com/apple-a'),
    array('keyword' => 'apple', 'url' => 'https://example.com/apple-b'),
    array('keyword' => 'apple', 'url' => 'https://example.com/apple-c'),
);
$clicklink_test_rand_sequence = array(2, 9, -5);

$random_content = '<p>apple apple apple</p>';
$updated_random_content = $run_save($linker, 2202, $random_content, false);
$random_a_count = preg_match_all('/href="https:\/\/example\.com\/apple-a"/', $updated_random_content, $random_a_matches);
$random_b_count = preg_match_all('/href="https:\/\/example\.com\/apple-b"/', $updated_random_content, $random_b_matches);
$random_c_count = preg_match_all('/href="https:\/\/example\.com\/apple-c"/', $updated_random_content, $random_c_matches);

$assert(
    $random_c_count === 1,
    'Expected in-range random selections to map to the indexed duplicate-keyword URL.'
);
$assert(
    $random_a_count === 2 && $random_b_count === 0,
    'Expected out-of-range random selections to fall back safely to the first duplicate-keyword URL.'
);

$reset_environment();
$linker = new \ClickLink\Post_Save_Linker();
$wpdb->mappings = array(
    array('keyword' => '  APPLE  ', 'url' => 'https://example.com/apple-a'),
    array('keyword' => 'apple', 'url' => 'https://example.com/apple-a'),
    array('keyword' => 'Apple', 'url' => 'https://example.com/apple-b'),
    array('keyword' => '', 'url' => 'https://example.com/skip-empty-keyword'),
    array('keyword' => 'apple', 'url' => 'not-a-valid-url'),
    array('keyword' => 'banana', 'url' => ''),
);
$clicklink_test_rand_sequence = array(1, 0);

$hardened_mapping_content = '<p>apple apple</p>';
$updated_hardened_mapping_content = $run_save($linker, 2602, $hardened_mapping_content, false);
$hardened_a_count = preg_match_all('/href="https:\/\/example\.com\/apple-a"/', $updated_hardened_mapping_content, $hardened_a_matches);
$hardened_b_count = preg_match_all('/href="https:\/\/example\.com\/apple-b"/', $updated_hardened_mapping_content, $hardened_b_matches);

$assert(
    $hardened_b_count === 1,
    'Expected keyword matching to normalize case/spacing while still honoring unique URL pools per keyword.'
);
$assert(
    $hardened_a_count === 1,
    'Expected duplicate rows and invalid mapping rows to be ignored safely without breaking link insertion.'
);

$reset_environment();
$linker = new \ClickLink\Post_Save_Linker();
$wpdb->mappings = array(
    array('keyword' => 'cache', 'url' => 'https://example.com/cache'),
);

$cache_content = '<p>cache</p>';
$run_save($linker, 2701, $cache_content, false);
$run_save($linker, 2702, $cache_content, false);

$assert(
    $wpdb->get_results_calls === 1,
    'Expected grouped keyword mappings to be cached per linker instance to avoid repeated mapping-table reads.'
);

$reset_environment();
$linker = new \ClickLink\Post_Save_Linker();
$clicklink_test_options['clicklink_options']['max_links_per_post'] = 10;
$wpdb->mappings = array(
    array('keyword' => 'apple', 'url' => 'https://example.com/apple'),
    array('keyword' => 'banana', 'url' => 'https://example.com/banana'),
);

$paragraph_scope_content = clicklink_fixture_paragraph_scope_content();
$updated_paragraph_scope_content = $run_save($linker, 3303, $paragraph_scope_content, false);

$assert(
    str_contains($updated_paragraph_scope_content, '<h2>Apple heading</h2>'),
    'Expected heading content to remain untouched when linking paragraphs.'
);
$assert(
    str_contains($updated_paragraph_scope_content, '<div>apple in div block.</div>'),
    'Expected non-paragraph block content to remain untouched when linking paragraphs.'
);
$assert(
    str_contains($updated_paragraph_scope_content, '<pre>apple in pre block</pre>'),
    'Expected pre blocks to remain untouched when linking paragraphs.'
);
$assert(
    str_contains($updated_paragraph_scope_content, '<a href="https://external.example.com">apple</a>'),
    'Expected existing anchors to remain untouched when linking paragraphs.'
);
$assert(
    str_contains($updated_paragraph_scope_content, '<code>banana</code>'),
    'Expected code blocks to remain untouched when linking paragraphs.'
);
$assert(
    str_contains($updated_paragraph_scope_content, '<a href="https://example.com/apple">Apple</a>'),
    'Expected paragraph-only linking to insert links inside eligible paragraph text.'
);

$reset_environment();
$linker = new \ClickLink\Post_Save_Linker();
$clicklink_test_options['clicklink_options']['max_links_per_post'] = 3;
$wpdb->mappings = array(
    array('keyword' => 'apple', 'url' => 'https://example.com/apple'),
);

$cap_content = clicklink_fixture_dense_keyword_content();
$updated_cap_content = $run_save($linker, 4404, $cap_content, false);
$cap_link_count = preg_match_all('/<a href="https:\/\/example\.com\/apple">/', $updated_cap_content, $cap_matches);

$assert(
    $cap_link_count === 3,
    'Expected max_links_per_post to enforce a hard post-level link insertion cap.'
);
$assert(
    (string) ($clicklink_test_post_meta[4404]['_clicklink_links_inserted_last_save'] ?? '') === '3',
    'Expected per-save metrics to record capped link insert totals.'
);
$assert(
    (int) (($clicklink_test_options['clicklink_stats']['total_links_inserted'] ?? 0)) === 3,
    'Expected cumulative stats to reflect the capped insert count from a qualifying save.'
);

if ($failures !== array()) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}\n");
    }

    exit(1);
}

echo "PASS: linker focused behaviors\n";
