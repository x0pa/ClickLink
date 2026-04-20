<?php

declare(strict_types=1);

namespace ClickLink;

final class Installer
{
    private const TABLE_SUFFIX = 'clicklink_keyword_mappings';
    private const SCHEMA_OPTION_KEY = 'clicklink_schema_version';
    private const OPTIONS_OPTION_KEY = 'clicklink_options';
    private const SCHEMA_VERSION = 1;

    public static function activate(): void
    {
        self::run_schema_migrations();
        self::ensure_default_options();
    }

    public static function maybe_upgrade(): void
    {
        if (self::needs_schema_upgrade()) {
            self::run_schema_migrations();
        }

        self::ensure_default_options();
    }

    public static function table_name(): string
    {
        global $wpdb;

        if (is_object($wpdb) && isset($wpdb->prefix) && is_string($wpdb->prefix)) {
            return $wpdb->prefix . self::TABLE_SUFFIX;
        }

        return self::TABLE_SUFFIX;
    }

    /**
     * @return array<string, int>
     */
    public static function default_options(): array
    {
        return array(
            'max_links_per_post' => 5,
        );
    }

    private static function needs_schema_upgrade(): bool
    {
        if (! function_exists('get_option')) {
            return true;
        }

        $installed_schema_version = get_option(self::SCHEMA_OPTION_KEY, 0);

        return (int) $installed_schema_version < self::SCHEMA_VERSION;
    }

    private static function run_schema_migrations(): void
    {
        self::load_dbdelta();

        if (! function_exists('dbDelta')) {
            return;
        }

        $table_name = self::table_name();
        $charset_collate = self::get_charset_collate();
        $sql = "CREATE TABLE {$table_name} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
keyword varchar(191) NOT NULL DEFAULT '',
url text NOT NULL,
created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
PRIMARY KEY  (id),
KEY keyword (keyword),
KEY created_at (created_at),
KEY updated_at (updated_at)
) {$charset_collate};";

        dbDelta($sql);

        if (function_exists('update_option')) {
            update_option(self::SCHEMA_OPTION_KEY, self::SCHEMA_VERSION, false);
        }
    }

    private static function ensure_default_options(): void
    {
        if (! function_exists('get_option') || ! function_exists('update_option')) {
            return;
        }

        $defaults = self::default_options();
        $saved_options = get_option(self::OPTIONS_OPTION_KEY, array());

        if (! is_array($saved_options)) {
            $saved_options = array();
        }

        $normalized_options = array_replace($defaults, $saved_options);

        if ($normalized_options === $saved_options) {
            return;
        }

        update_option(self::OPTIONS_OPTION_KEY, $normalized_options, false);
    }

    private static function load_dbdelta(): void
    {
        if (function_exists('dbDelta')) {
            return;
        }

        if (! defined('ABSPATH')) {
            return;
        }

        $upgrade_file = ABSPATH . 'wp-admin/includes/upgrade.php';

        if (! is_readable($upgrade_file)) {
            return;
        }

        require_once $upgrade_file;
    }

    private static function get_charset_collate(): string
    {
        global $wpdb;

        if (! is_object($wpdb) || ! method_exists($wpdb, 'get_charset_collate')) {
            return '';
        }

        $charset_collate = $wpdb->get_charset_collate();

        if (! is_string($charset_collate)) {
            return '';
        }

        return $charset_collate;
    }
}
