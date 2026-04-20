<?php

declare(strict_types=1);

if (! defined('CLICKLINK_MIN_WP_VERSION')) {
    define('CLICKLINK_MIN_WP_VERSION', '6.5');
}

if (! defined('CLICKLINK_MIN_PHP_VERSION')) {
    define('CLICKLINK_MIN_PHP_VERSION', '8.1');
}

$clicklink_test_multisite = false;

if (! function_exists('is_multisite')) {
    function is_multisite(): bool
    {
        global $clicklink_test_multisite;

        return (bool) $clicklink_test_multisite;
    }
}

require_once __DIR__ . '/../includes/class-compatibility.php';

$failures = array();

$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (! $condition) {
        $failures[] = $message;
    }
};

global $wp_version;

$wp_version = '6.5';
$clicklink_test_multisite = false;
$assert(
    \ClickLink\Compatibility::is_supported_environment() === true,
    'Expected a supported environment when versions are valid and multisite is off.'
);

$wp_version = '6.4.9';
$errors = \ClickLink\Compatibility::get_environment_errors();
$assert(
    (bool) array_filter($errors, static fn (string $error): bool => str_contains($error, 'WordPress')),
    'Expected a WordPress compatibility error for versions below the minimum.'
);

$wp_version = '6.5';
$clicklink_test_multisite = true;
$errors = \ClickLink\Compatibility::get_environment_errors();
$assert(
    (bool) array_filter($errors, static fn (string $error): bool => str_contains($error, 'Multisite')),
    'Expected multisite to be flagged as unsupported.'
);

if ($failures !== array()) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}\n");
    }

    exit(1);
}

echo "PASS: compatibility\n";
