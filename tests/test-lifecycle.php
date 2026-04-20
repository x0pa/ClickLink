<?php

declare(strict_types=1);

if (! defined('CLICKLINK_MIN_WP_VERSION')) {
    define('CLICKLINK_MIN_WP_VERSION', '6.5');
}

if (! defined('CLICKLINK_MIN_PHP_VERSION')) {
    define('CLICKLINK_MIN_PHP_VERSION', '8.1');
}

if (! defined('CLICKLINK_FILE')) {
    define('CLICKLINK_FILE', __DIR__ . '/../clicklink.php');
}

$clicklink_test_multisite = false;
$clicklink_test_deactivated = array();

if (! function_exists('is_multisite')) {
    function is_multisite(): bool
    {
        global $clicklink_test_multisite;

        return (bool) $clicklink_test_multisite;
    }
}

if (! function_exists('deactivate_plugins')) {
    function deactivate_plugins(string $plugin): void
    {
        global $clicklink_test_deactivated;

        $clicklink_test_deactivated[] = $plugin;
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

require_once __DIR__ . '/../includes/class-compatibility.php';
require_once __DIR__ . '/../includes/class-lifecycle.php';

$failures = array();

$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (! $condition) {
        $failures[] = $message;
    }
};

global $wp_version;

$wp_version = '6.5';
$clicklink_test_multisite = false;
$clicklink_test_deactivated = array();

\ClickLink\Lifecycle::activate();
$assert(
    $clicklink_test_deactivated === array(),
    'Did not expect plugin deactivation in a supported environment.'
);

$wp_version = '6.5';
$clicklink_test_multisite = true;

try {
    \ClickLink\Lifecycle::activate();
    $assert(false, 'Expected multisite activation to fail.');
} catch (RuntimeException $exception) {
    $assert(
        str_contains($exception->getMessage(), 'Multisite'),
        'Expected multisite activation error message to include multisite guidance.'
    );
}

$assert(
    in_array('clicklink.php', $clicklink_test_deactivated, true),
    'Expected plugin to be deactivated on activation failure.'
);

if ($failures !== array()) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}\n");
    }

    exit(1);
}

echo "PASS: lifecycle\n";
