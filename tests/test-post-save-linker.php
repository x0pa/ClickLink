<?php

declare(strict_types=1);

final class ClickLink_Test_Linker_WPDB
{
    public string $prefix = 'wp_';

    /**
     * @var array<int, array{keyword: string, url: string}>
     */
    public array $mappings = array();

    /**
     * @return array<int, array{keyword: string, url: string}>
     */
    public function get_results(string $query, string $output = 'OBJECT'): array
    {
        return $this->mappings;
    }
}

$clicklink_test_actions = array();
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

        if ($clicklink_test_rand_sequence !== array()) {
            $value = (int) array_shift($clicklink_test_rand_sequence);

            if ($value < $min || $value > $max) {
                return $min;
            }

            return $value;
        }

        return $min;
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
$wpdb = new ClickLink_Test_Linker_WPDB();

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

$linker = new \ClickLink\Post_Save_Linker();
$linker->register();

$save_post_hooks = $clicklink_test_actions['save_post_post'] ?? array();
$hook_registration = $save_post_hooks[0] ?? null;

$assert(
    is_array($hook_registration),
    'Expected Post_Save_Linker::register() to hook save_post_post.'
);
$assert(
    is_array($hook_registration) && (int) ($hook_registration['accepted_args'] ?? 0) === 3,
    'Expected save_post_post hook to accept post_id, post, and update arguments.'
);

$wpdb->mappings = array(
    array('keyword' => 'apple', 'url' => 'https://example.com/apple-a'),
    array('keyword' => 'apple', 'url' => 'https://example.com/apple-b'),
    array('keyword' => 'banana', 'url' => 'https://example.com/banana'),
);

$clicklink_test_rand_sequence = array(1, 0, 0, 0, 0);
$source_content = clicklink_fixture_paragraph_scope_content();

$post = (object) array(
    'ID' => 101,
    'post_type' => 'post',
    'post_content' => $source_content,
);

$linker->handle_post_save(101, $post, true);

$assert(
    count($clicklink_test_updates) === 1,
    'Expected changed blog-post saves to update post content once when links are inserted.'
);

$updated_post = $clicklink_test_updates[0] ?? array();
$updated_content = (string) ($updated_post['post_content'] ?? '');
$saved_hash = (string) ($clicklink_test_post_meta[101]['_clicklink_content_hash'] ?? '');

$assert(
    str_contains($updated_content, '<a href="https://example.com/apple-b">Apple</a>'),
    'Expected keyword rows with duplicate keywords to allow random URL selection.'
);
$assert(
    str_contains($updated_content, '<h2>Apple heading</h2>'),
    'Expected headings outside paragraphs to be skipped.'
);
$assert(
    str_contains($updated_content, '<div>apple in div block.</div>'),
    'Expected non-paragraph block content to remain untouched.'
);
$assert(
    str_contains($updated_content, '<pre>apple in pre block</pre>'),
    'Expected pre blocks to remain untouched.'
);
$assert(
    str_contains($updated_content, '<a href="https://external.example.com">apple</a>'),
    'Expected existing anchors to remain untouched.'
);
$assert(
    str_contains($updated_content, '<code>banana</code>'),
    'Expected code blocks to remain untouched.'
);

$inserted_links = preg_match_all('/<a href="https:\/\/example\.com\//', $updated_content, $matches);

$assert(
    $inserted_links === 5,
    'Expected the linker to enforce the max_links_per_post cap of 5 links.'
);
$assert(
    $saved_hash === hash('sha256', str_replace("\r\n", "\n", $updated_content)),
    'Expected content hash metadata to be stored from the linked post body.'
);
$assert(
    (int) (($clicklink_test_options['clicklink_stats']['total_links_inserted'] ?? 0)) === 5,
    'Expected per-save metrics to increment cumulative total links inserted immediately.'
);
$assert(
    (int) (($clicklink_test_options['clicklink_stats']['posts_touched'] ?? 0)) === 1,
    'Expected posts_touched metrics to track unique posts updated by the linker.'
);
$keyword_counts_after_first_save = $clicklink_test_options['clicklink_stats']['keyword_match_counts'] ?? array();
$assert(
    is_array($keyword_counts_after_first_save) && array_sum($keyword_counts_after_first_save) === 5,
    'Expected per-keyword match counters to sum to inserted-link totals for each qualifying save.'
);
$assert(
    is_array($keyword_counts_after_first_save)
        && (int) ($keyword_counts_after_first_save['apple'] ?? 0) > 0
        && (int) ($keyword_counts_after_first_save['banana'] ?? 0) > 0,
    'Expected per-keyword match counters to track each matched keyword in linked paragraph content.'
);
$assert(
    (string) ($clicklink_test_post_meta[101]['_clicklink_links_inserted_last_save'] ?? '') === '5',
    'Expected per-save metadata to capture the number of links inserted on the latest qualifying save.'
);
$assert(
    (string) ($clicklink_test_post_meta[101]['_clicklink_touched_by_linker'] ?? '') === '1',
    'Expected touched-post metadata to be marked after successful link insertion.'
);
$assert(
    (string) ($clicklink_test_post_meta[101]['_clicklink_links_inserted_total'] ?? '') === '5',
    'Expected post-level inserted link totals to accumulate for touched posts.'
);

$clicklink_test_updates = array();
$unchanged_post = (object) array(
    'ID' => 101,
    'post_type' => 'post',
    'post_content' => $updated_content,
);
$linker->handle_post_save(101, $unchanged_post, true);

$assert(
    $clicklink_test_updates === array(),
    'Expected unchanged content saves to skip linker updates.'
);

$clicklink_test_autosave_ids = array(202);
$autosave_post = (object) array(
    'ID' => 202,
    'post_type' => 'post',
    'post_content' => '<p>apple</p>',
);
$linker->handle_post_save(202, $autosave_post, true);

$assert(
    $clicklink_test_updates === array(),
    'Expected autosaves to be skipped.'
);

$clicklink_test_autosave_ids = array();
$clicklink_test_revision_ids = array(303);
$revision_post = (object) array(
    'ID' => 303,
    'post_type' => 'post',
    'post_content' => '<p>apple</p>',
);
$linker->handle_post_save(303, $revision_post, true);

$assert(
    $clicklink_test_updates === array(),
    'Expected revisions to be skipped.'
);

$clicklink_test_revision_ids = array();
$page_post = (object) array(
    'ID' => 404,
    'post_type' => 'page',
    'post_content' => '<p>apple</p>',
);
$linker->handle_post_save(404, $page_post, true);

$assert(
    $clicklink_test_updates === array(),
    'Expected non-blog post types to be skipped.'
);

$new_post = (object) array(
    'ID' => 505,
    'post_type' => 'post',
    'post_content' => '<p>apple</p>',
);
$linker->handle_post_save(505, $new_post, false);

$assert(
    count($clicklink_test_updates) === 1 && (int) ($clicklink_test_updates[0]['ID'] ?? 0) === 505,
    'Expected new post saves to run linker even without prior content hash metadata.'
);
$assert(
    (int) (($clicklink_test_options['clicklink_stats']['total_links_inserted'] ?? 0)) === 6,
    'Expected cumulative link totals to include additional inserts from later qualifying saves.'
);
$assert(
    (int) (($clicklink_test_options['clicklink_stats']['posts_touched'] ?? 0)) === 2,
    'Expected posts_touched to increment when a second post receives inserted links.'
);
$keyword_counts_after_second_save = $clicklink_test_options['clicklink_stats']['keyword_match_counts'] ?? array();
$assert(
    is_array($keyword_counts_after_second_save) && array_sum($keyword_counts_after_second_save) === 6,
    'Expected per-keyword match counters to remain aligned with cumulative inserted-link totals after later saves.'
);
$assert(
    is_array($keyword_counts_after_second_save)
        && (int) ($keyword_counts_after_second_save['apple'] ?? 0)
            === (int) ($keyword_counts_after_first_save['apple'] ?? 0) + 1,
    'Expected later qualifying saves to increment the matching keyword counter without resetting prior counts.'
);
$assert(
    (string) ($clicklink_test_post_meta[505]['_clicklink_links_inserted_last_save'] ?? '') === '1',
    'Expected per-save metadata to track inserts for newly-linked posts.'
);

if ($failures !== array()) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}\n");
    }

    exit(1);
}

echo "PASS: post save linker\n";
