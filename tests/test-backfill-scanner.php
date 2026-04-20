<?php

declare(strict_types=1);

final class ClickLink_Test_Backfill_WPDB
{
    public string $prefix = 'wp_';
    public string $posts = 'wp_posts';

    /**
     * @var array<int, array{keyword: string, url: string}>
     */
    public array $mappings = array();

    /**
     * @return array<int, array{keyword: string, url: string}>
     */
    public function get_results(string $query, string $output = 'OBJECT'): array
    {
        if (! str_contains($query, 'SELECT keyword, url')) {
            return array();
        }

        return $this->mappings;
    }

    /**
     * @return array<int>
     */
    public function get_col(string $query): array
    {
        global $clicklink_test_posts;

        $cursor_post_id = 0;
        $batch_limit = 20;

        if (preg_match('/ID > (\d+)/', $query, $cursor_matches) === 1) {
            $cursor_post_id = max(0, (int) ($cursor_matches[1] ?? 0));
        }

        if (preg_match('/LIMIT (\d+)/', $query, $limit_matches) === 1) {
            $batch_limit = max(1, (int) ($limit_matches[1] ?? 1));
        }

        $eligible_ids = array();

        foreach ($clicklink_test_posts as $post_id => $post) {
            if (! is_int($post_id) || ! is_array($post)) {
                continue;
            }

            $post_type = (string) ($post['post_type'] ?? '');
            $post_status = (string) ($post['post_status'] ?? '');

            if ($post_type !== 'post' || $post_status !== 'publish') {
                continue;
            }

            if ($post_id <= $cursor_post_id) {
                continue;
            }

            $eligible_ids[] = $post_id;
        }

        sort($eligible_ids, SORT_NUMERIC);

        return array_slice($eligible_ids, 0, $batch_limit);
    }

    /**
     * @return int
     */
    public function get_var(string $query)
    {
        global $clicklink_test_posts;

        if (! str_contains($query, 'COUNT(*)')) {
            return 0;
        }

        $count = 0;

        foreach ($clicklink_test_posts as $post) {
            if (! is_array($post)) {
                continue;
            }

            $post_type = (string) ($post['post_type'] ?? '');
            $post_status = (string) ($post['post_status'] ?? '');

            if ($post_type !== 'post' || $post_status !== 'publish') {
                continue;
            }

            $count++;
        }

        return $count;
    }
}

$clicklink_test_options = array(
    'clicklink_options' => array(
        'max_links_per_post' => 5,
    ),
);
$clicklink_test_post_meta = array();
$clicklink_test_updates = array();
$clicklink_test_get_post_fail_ids = array();
$clicklink_test_posts = array(
    1 => array(
        'post_type' => 'post',
        'post_status' => 'publish',
        'post_content' => '<p>Apple apple.</p>',
    ),
    2 => array(
        'post_type' => 'post',
        'post_status' => 'publish',
        'post_content' => '<p>No keyword here.</p>',
    ),
    3 => array(
        'post_type' => 'post',
        'post_status' => 'draft',
        'post_content' => '<p>Apple draft should be ignored.</p>',
    ),
    4 => array(
        'post_type' => 'post',
        'post_status' => 'publish',
        'post_content' => '<p>Apple final.</p>',
    ),
);

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

        if ($post_id <= 0 || ! isset($clicklink_test_posts[$post_id])) {
            return false;
        }

        $clicklink_test_updates[] = $postarr;

        if (isset($postarr['post_content']) && is_string($postarr['post_content'])) {
            $clicklink_test_posts[$post_id]['post_content'] = $postarr['post_content'];
        }

        return $post_id;
    }
}

if (! function_exists('wp_rand')) {
    function wp_rand(int $min = 0, int $max = 0): int
    {
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

if (! function_exists('current_time')) {
    function current_time(string $type = 'mysql', bool $gmt = false): string
    {
        return '2026-04-20 15:00:00';
    }
}

if (! function_exists('get_post')) {
    /**
     * @return object|null
     */
    function get_post(int $post_id): ?object
    {
        global $clicklink_test_get_post_fail_ids;
        global $clicklink_test_posts;

        if (in_array($post_id, $clicklink_test_get_post_fail_ids, true)) {
            return null;
        }

        if (! isset($clicklink_test_posts[$post_id]) || ! is_array($clicklink_test_posts[$post_id])) {
            return null;
        }

        $post = $clicklink_test_posts[$post_id];

        return (object) array(
            'ID' => $post_id,
            'post_type' => (string) ($post['post_type'] ?? ''),
            'post_status' => (string) ($post['post_status'] ?? ''),
            'post_content' => (string) ($post['post_content'] ?? ''),
        );
    }
}

global $wpdb;
$wpdb = new ClickLink_Test_Backfill_WPDB();

require_once __DIR__ . '/../includes/class-installer.php';
require_once __DIR__ . '/../includes/class-keyword-mapping-repository.php';
require_once __DIR__ . '/../includes/class-linker-stats.php';
require_once __DIR__ . '/../includes/class-post-save-linker.php';
require_once __DIR__ . '/../includes/class-backfill-scanner.php';
require_once __DIR__ . '/fixtures/linker-content.php';

$failures = array();

$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (! $condition) {
        $failures[] = $message;
    }
};

$reset_environment = static function (): void {
    global $clicklink_test_options;
    global $clicklink_test_post_meta;
    global $clicklink_test_updates;
    global $clicklink_test_get_post_fail_ids;
    global $clicklink_test_posts;
    global $wpdb;

    $clicklink_test_options = array(
        'clicklink_options' => array(
            'max_links_per_post' => 5,
        ),
    );
    $clicklink_test_post_meta = array();
    $clicklink_test_updates = array();
    $clicklink_test_get_post_fail_ids = array();
    $clicklink_test_posts = array(
        1 => array(
            'post_type' => 'post',
            'post_status' => 'publish',
            'post_content' => '<p>Apple apple.</p>',
        ),
        2 => array(
            'post_type' => 'post',
            'post_status' => 'publish',
            'post_content' => '<p>No keyword here.</p>',
        ),
        3 => array(
            'post_type' => 'post',
            'post_status' => 'draft',
            'post_content' => '<p>Apple draft should be ignored.</p>',
        ),
        4 => array(
            'post_type' => 'post',
            'post_status' => 'publish',
            'post_content' => '<p>Apple final.</p>',
        ),
    );

    if (is_object($wpdb) && property_exists($wpdb, 'mappings')) {
        $wpdb->mappings = array(
            array(
                'keyword' => 'apple',
                'url' => 'https://example.com/apple',
            ),
        );
    }
};

$count_link_matches = static function (string $content): int {
    $matches = array();

    return max(0, (int) preg_match_all('/href="https:\/\/example\.com\/apple"/', $content, $matches));
};

$reset_environment();
$scanner = new \ClickLink\Backfill_Scanner();

$start_state = $scanner->start_run(2);

$assert(
    $start_state['status'] === 'running',
    'Expected start_run() to transition scanner state from pending to running.'
);
$assert(
    $start_state['started_at'] === '2026-04-20 15:00:00',
    'Expected start_run() to persist started_at timestamp for admin visibility.'
);
$assert(
    $start_state['completed_at'] === '',
    'Expected start_run() to clear completed_at at run initialization.'
);
$assert(
    $start_state['total_eligible_posts'] === 3,
    'Expected start_run() to snapshot total eligible published blog posts.'
);
$assert(
    $start_state['batch_size'] === 2,
    'Expected start_run() to persist the configured fixed batch size.'
);

$oversized_batch_state = $scanner->start_run(999);
$assert(
    $oversized_batch_state['batch_size'] >= 1 && $oversized_batch_state['batch_size'] <= 100,
    'Expected start_run() to cap oversized batch sizes to a timeout-safe upper bound.'
);

$scanner->start_run(2);

$first_batch_state = $scanner->process_next_batch();

$assert(
    $first_batch_state['status'] === 'running',
    'Expected scanner to remain running after processing a non-terminal batch.'
);
$assert(
    $first_batch_state['processed_posts'] === 2,
    'Expected first batch to process two published blog posts.'
);
$assert(
    $first_batch_state['changed_posts'] === 1,
    'Expected first batch to report one changed post when only one post gets new links.'
);
$assert(
    $first_batch_state['inserted_links'] === 2,
    'Expected first batch metadata to capture inserted link count from shared linker pipeline.'
);
$assert(
    $first_batch_state['cursor_post_id'] === 2,
    'Expected batch cursor to track the most recent processed post ID.'
);
$assert(
    $first_batch_state['failures'] === 0,
    'Did not expect failures when all posts are loadable and valid.'
);

$second_batch_state = $scanner->process_next_batch();

$assert(
    $second_batch_state['status'] === 'completed',
    'Expected scanner to transition to completed after the final batch.'
);
$assert(
    $second_batch_state['completed_at'] === '2026-04-20 15:00:00',
    'Expected completion metadata to persist completed_at timestamp.'
);
$assert(
    $second_batch_state['processed_posts'] === 3,
    'Expected scanner to process only published blog posts across all batches.'
);
$assert(
    $second_batch_state['changed_posts'] === 2,
    'Expected changed_posts counter to include each post that received new links.'
);
$assert(
    $second_batch_state['inserted_links'] === 3,
    'Expected inserted_links to accumulate across all processed batches.'
);
$assert(
    $second_batch_state['failures'] === 0,
    'Did not expect failure count to increase on a fully successful run.'
);

$post_one_content = (string) ($clicklink_test_posts[1]['post_content'] ?? '');
$post_two_content = (string) ($clicklink_test_posts[2]['post_content'] ?? '');
$post_four_content = (string) ($clicklink_test_posts[4]['post_content'] ?? '');

$assert(
    $count_link_matches($post_one_content) === 2,
    'Expected published post #1 to receive two links from shared paragraph linker rules.'
);
$assert(
    $count_link_matches($post_two_content) === 0,
    'Expected posts without matching keywords to remain unchanged.'
);
$assert(
    $count_link_matches($post_four_content) === 1,
    'Expected published post #4 to receive one link during backfill processing.'
);

$global_stats = get_option('clicklink_stats', array());
$assert(
    is_array($global_stats)
        && (int) ($global_stats['total_links_inserted'] ?? -1) === 3
        && (int) ($global_stats['posts_touched'] ?? -1) === 2,
    'Expected backfill run to update shared global linker stats via Linker_Stats::record_save_metrics().'
);

$scanner->start_run(2);
$scanner->process_next_batch();
$rerun_state = $scanner->process_next_batch();

$assert(
    $rerun_state['status'] === 'completed',
    'Expected rerun to still complete when all eligible posts are already hashed/linked.'
);
$assert(
    $rerun_state['processed_posts'] === 3,
    'Expected rerun to process the same eligible post count without cursor drift.'
);
$assert(
    $rerun_state['changed_posts'] === 0,
    'Expected idempotent rerun to avoid changing already-linked content.'
);
$assert(
    $rerun_state['inserted_links'] === 0,
    'Expected idempotent rerun to insert zero new links on already-linked posts.'
);
$assert(
    $count_link_matches((string) ($clicklink_test_posts[1]['post_content'] ?? '')) === 2,
    'Expected rerun to avoid duplicating links in previously-linked regions.'
);

$global_stats_after_rerun = get_option('clicklink_stats', array());
$assert(
    is_array($global_stats_after_rerun)
        && (int) ($global_stats_after_rerun['total_links_inserted'] ?? -1) === 3,
    'Expected idempotent rerun to leave global inserted-link totals unchanged.'
);

$reset_environment();
$scanner = new \ClickLink\Backfill_Scanner();

$post_one_original_content = (string) ($clicklink_test_posts[1]['post_content'] ?? '');
$post_four_original_content = (string) ($clicklink_test_posts[4]['post_content'] ?? '');
update_post_meta(1, '_clicklink_content_hash', hash('sha256', str_replace("\r\n", "\n", $post_one_original_content)));
update_post_meta(4, '_clicklink_content_hash', hash('sha256', str_replace("\r\n", "\n", $post_four_original_content)));

$scanner->start_run(3);
$hash_bypass_state = $scanner->process_next_batch();

$assert(
    $hash_bypass_state['status'] === 'completed',
    'Expected backfill batch to complete in one pass when batch size covers all eligible posts.'
);
$assert(
    $hash_bypass_state['changed_posts'] === 2 && $hash_bypass_state['inserted_links'] === 3,
    'Expected backfill processing to bypass save-event hash short-circuiting and still run shared linker rules.'
);
$assert(
    $count_link_matches((string) ($clicklink_test_posts[1]['post_content'] ?? '')) === 2
        && $count_link_matches((string) ($clicklink_test_posts[4]['post_content'] ?? '')) === 1,
    'Expected hashed published posts to still receive eligible paragraph links during manual backfill.'
);

$global_stats_after_hashed_backfill = get_option('clicklink_stats', array());
$assert(
    is_array($global_stats_after_hashed_backfill)
        && (int) ($global_stats_after_hashed_backfill['total_links_inserted'] ?? -1) === 3
        && (int) ($global_stats_after_hashed_backfill['posts_touched'] ?? -1) === 2,
    'Expected hashed-content backfill runs to keep updating shared global stats through Linker_Stats.'
);

$reset_environment();
$scanner = new \ClickLink\Backfill_Scanner();
$scanner->start_run(2);
$scanner->process_next_batch();
$clicklink_test_get_post_fail_ids = array(4);
$failure_state = $scanner->process_next_batch();

$assert(
    $failure_state['status'] === 'completed',
    'Expected scanner to complete after processing final batch even when a post load failure occurs.'
);
$assert(
    $failure_state['failures'] === 1,
    'Expected scanner to persist failure count when a post cannot be loaded for processing.'
);
$assert(
    str_contains($failure_state['last_error'], 'Unable to load post ID 4'),
    'Expected scanner to persist last_error details for failed post processing.'
);

$persisted_state = get_option('clicklink_backfill_run_state', array());
$assert(
    is_array($persisted_state) && (int) ($persisted_state['failures'] ?? 0) === 1,
    'Expected persisted scanner state option to expose run metadata for admin visibility.'
);

$reset_state = $scanner->reset_run();
$assert(
    $reset_state['status'] === 'pending',
    'Expected reset_run() to return the scanner to pending state for manual restart.'
);
$assert(
    $reset_state['processed_posts'] === 0
        && $reset_state['changed_posts'] === 0
        && $reset_state['inserted_links'] === 0
        && $reset_state['failures'] === 0,
    'Expected reset_run() to clear run counters to avoid stale progress metadata.'
);
$assert(
    $reset_state['started_at'] === '' && $reset_state['completed_at'] === '',
    'Expected reset_run() to clear run timestamps before the next manual run.'
);

$reset_environment();
$clicklink_test_options['clicklink_options']['max_links_per_post'] = 2;
$clicklink_test_posts = array(
    10 => array(
        'post_type' => 'post',
        'post_status' => 'publish',
        'post_content' => clicklink_fixture_exclusion_and_encoding_content(),
    ),
);
$wpdb->mappings = array(
    array(
        'keyword' => 'alpha',
        'url' => 'https://example.com/alpha',
    ),
);
$scanner = new \ClickLink\Backfill_Scanner();
$scanner->start_run(10);
$exclusion_cap_state = $scanner->process_next_batch();
$updated_exclusion_content = (string) ($clicklink_test_posts[10]['post_content'] ?? '');
$exclusion_cap_link_count = preg_match_all(
    '/href="https:\/\/example\.com\/alpha"/',
    $updated_exclusion_content,
    $exclusion_cap_matches
);

$assert(
    $exclusion_cap_state['status'] === 'completed'
        && $exclusion_cap_state['processed_posts'] === 1
        && $exclusion_cap_state['changed_posts'] === 1,
    'Expected scanner integration run to complete and record per-post progress metadata for exclusion/cap fixture content.'
);
$assert(
    $exclusion_cap_state['inserted_links'] === 2 && $exclusion_cap_link_count === 2,
    'Expected scanner integration run to respect max_links_per_post cap while using shared paragraph linker pipeline.'
);
$assert(
    str_contains($updated_exclusion_content, '<script>var sample = "<p>alpha</p>";</script>')
        && str_contains($updated_exclusion_content, '<style>.alpha{display:block;}</style>')
        && str_contains($updated_exclusion_content, '<textarea>alpha hidden</textarea>')
        && str_contains($updated_exclusion_content, '<h3>alpha heading</h3>'),
    'Expected scanner integration run to preserve excluded contexts (script/style/textarea/headings) while linking paragraph text.'
);

$reset_environment();
$clicklink_test_posts = array(
    31 => array(
        'post_type' => 'page',
        'post_status' => 'publish',
        'post_content' => '<p>apple page content should be ignored.</p>',
    ),
    32 => array(
        'post_type' => 'post',
        'post_status' => 'draft',
        'post_content' => '<p>apple draft content should be ignored.</p>',
    ),
);
$scanner = new \ClickLink\Backfill_Scanner();
$no_op_start_state = $scanner->start_run(4);
$no_op_state = $scanner->process_next_batch();

$assert(
    $no_op_start_state['total_eligible_posts'] === 0,
    'Expected no-op run initialization to snapshot zero eligible published blog posts.'
);
$assert(
    $no_op_state['status'] === 'completed'
        && $no_op_state['processed_posts'] === 0
        && $no_op_state['changed_posts'] === 0
        && $no_op_state['inserted_links'] === 0
        && $no_op_state['failures'] === 0,
    'Expected no-op run processing to complete without touching counters when no published blog posts are eligible.'
);
$assert(
    $no_op_state['completed_at'] === '2026-04-20 15:00:00',
    'Expected no-op runs to persist completed_at metadata for operator visibility.'
);

if ($failures !== array()) {
    fwrite(STDERR, "Backfill scanner tests failed:\n");

    foreach ($failures as $failure) {
        fwrite(STDERR, '- ' . $failure . "\n");
    }

    exit(1);
}

echo "Backfill scanner tests passed.\n";
