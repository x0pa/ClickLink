<?php

declare(strict_types=1);

final class ClickLink_Test_Uninstall_WPDB
{
    public string $prefix = 'wp_';

    /**
     * @var array<int, string>
     */
    public array $queries = array();

    /**
     * @return int|false
     */
    public function query(string $query)
    {
        $this->queries[] = $query;

        return 1;
    }
}

$clicklink_test_deleted_options = array();
$clicklink_test_deleted_site_options = array();
$clicklink_test_deleted_meta_keys = array();

if (! function_exists('delete_option')) {
    function delete_option(string $name): bool
    {
        global $clicklink_test_deleted_options;

        $clicklink_test_deleted_options[] = $name;

        return true;
    }
}

if (! function_exists('delete_site_option')) {
    function delete_site_option(string $name): bool
    {
        global $clicklink_test_deleted_site_options;

        $clicklink_test_deleted_site_options[] = $name;

        return true;
    }
}

if (! function_exists('delete_post_meta_by_key')) {
    function delete_post_meta_by_key(string $meta_key): bool
    {
        global $clicklink_test_deleted_meta_keys;

        $clicklink_test_deleted_meta_keys[] = $meta_key;

        return true;
    }
}

global $wpdb;
$wpdb = new ClickLink_Test_Uninstall_WPDB();

require_once __DIR__ . '/../includes/class-installer.php';
require_once __DIR__ . '/../includes/class-uninstaller.php';

$failures = array();

$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (! $condition) {
        $failures[] = $message;
    }
};

$expected_option_keys = array(
    'clicklink_schema_version',
    'clicklink_options',
    'clicklink_stats',
    'clicklink_backfill_run_state',
);
$expected_meta_keys = array(
    '_clicklink_content_hash',
    '_clicklink_touched_by_linker',
    '_clicklink_links_inserted_last_save',
    '_clicklink_links_inserted_total',
);

\ClickLink\Uninstaller::uninstall();

$drop_table_query = $wpdb->queries[0] ?? '';
$assert(
    is_string($drop_table_query) && str_contains($drop_table_query, 'DROP TABLE IF EXISTS'),
    'Expected direct uninstaller run to issue a drop-table query.'
);
$assert(
    is_string($drop_table_query) && str_contains($drop_table_query, 'wp_clicklink_keyword_mappings'),
    'Expected direct uninstaller run to target the prefixed mappings table.'
);

foreach ($expected_option_keys as $option_key) {
    $assert(
        in_array($option_key, $clicklink_test_deleted_options, true),
        'Expected direct uninstaller run to delete option: ' . $option_key
    );
    $assert(
        in_array($option_key, $clicklink_test_deleted_site_options, true),
        'Expected direct uninstaller run to delete site option: ' . $option_key
    );
}

foreach ($expected_meta_keys as $meta_key) {
    $assert(
        in_array($meta_key, $clicklink_test_deleted_meta_keys, true),
        'Expected direct uninstaller run to delete post meta key: ' . $meta_key
    );
}

$wpdb->queries = array();
$clicklink_test_deleted_options = array();
$clicklink_test_deleted_site_options = array();
$clicklink_test_deleted_meta_keys = array();

if (! defined('WP_UNINSTALL_PLUGIN')) {
    define('WP_UNINSTALL_PLUGIN', 'clicklink/clicklink.php');
}

require __DIR__ . '/../uninstall.php';

$drop_table_query_from_bootstrap = $wpdb->queries[0] ?? '';
$assert(
    is_string($drop_table_query_from_bootstrap) && str_contains($drop_table_query_from_bootstrap, 'DROP TABLE IF EXISTS'),
    'Expected uninstall.php bootstrap to run the drop-table query.'
);

foreach ($expected_option_keys as $option_key) {
    $assert(
        in_array($option_key, $clicklink_test_deleted_options, true),
        'Expected uninstall.php bootstrap to delete option: ' . $option_key
    );
}

foreach ($expected_meta_keys as $meta_key) {
    $assert(
        in_array($meta_key, $clicklink_test_deleted_meta_keys, true),
        'Expected uninstall.php bootstrap to delete post meta key: ' . $meta_key
    );
}

if ($failures !== array()) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}\n");
    }

    exit(1);
}

echo "PASS: uninstaller\n";
