<?php

declare(strict_types=1);

final class ClickLink_Test_WPDB
{
    public string $prefix = 'wp_';

    public function get_charset_collate(): string
    {
        return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci';
    }
}

$clicklink_test_options = array();
$clicklink_test_dbdelta_calls = array();

if (! function_exists('get_option')) {
    /**
     * @return mixed
     */
    function get_option(string $name, $default = false)
    {
        global $clicklink_test_options;

        if (array_key_exists($name, $clicklink_test_options)) {
            return $clicklink_test_options[$name];
        }

        return $default;
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

global $wpdb;
$wpdb = new ClickLink_Test_WPDB();

require_once __DIR__ . '/../includes/class-installer.php';

$failures = array();

$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (! $condition) {
        $failures[] = $message;
    }
};

\ClickLink\Installer::maybe_upgrade();

$assert(
    count($clicklink_test_dbdelta_calls) === 1,
    'Expected a schema migration to run when no schema version is stored.'
);

$first_sql = $clicklink_test_dbdelta_calls[0] ?? '';
$assert(
    str_contains($first_sql, 'CREATE TABLE wp_clicklink_keyword_mappings'),
    'Expected the mapping table to use the WordPress-prefixed table name.'
);
$assert(
    str_contains($first_sql, 'KEY keyword (keyword)'),
    'Expected a keyword index to exist.'
);
$assert(
    str_contains($first_sql, 'KEY created_at (created_at)'),
    'Expected a created_at index to exist.'
);
$assert(
    str_contains($first_sql, 'KEY updated_at (updated_at)'),
    'Expected an updated_at index to exist.'
);
$assert(
    ! str_contains($first_sql, 'UNIQUE KEY keyword'),
    'Expected keyword mappings to allow duplicate keywords across rows.'
);

$assert(
    ($clicklink_test_options['clicklink_schema_version'] ?? null) === 1,
    'Expected schema version tracking option to be updated after migration.'
);
$assert(
    ($clicklink_test_options['clicklink_options']['max_links_per_post'] ?? null) === 5,
    'Expected plugin defaults to initialize max_links_per_post = 5.'
);

$clicklink_test_dbdelta_calls = array();
\ClickLink\Installer::maybe_upgrade();
$assert(
    $clicklink_test_dbdelta_calls === array(),
    'Did not expect dbDelta to run when schema version is already current.'
);

$clicklink_test_options['clicklink_options'] = array(
    'custom_flag' => true,
);
\ClickLink\Installer::maybe_upgrade();
$assert(
    ($clicklink_test_options['clicklink_options']['max_links_per_post'] ?? null) === 5,
    'Expected missing max_links_per_post default to be backfilled into existing options.'
);
$assert(
    ($clicklink_test_options['clicklink_options']['custom_flag'] ?? false) === true,
    'Expected existing custom options to be preserved when defaults are merged.'
);

$clicklink_test_options['clicklink_options'] = array(
    'max_links_per_post' => 12,
);
\ClickLink\Installer::maybe_upgrade();
$assert(
    ($clicklink_test_options['clicklink_options']['max_links_per_post'] ?? null) === 12,
    'Expected existing max_links_per_post values to remain unchanged.'
);

if ($failures !== array()) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}\n");
    }

    exit(1);
}

echo "PASS: installer\n";
