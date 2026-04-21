<?php

declare(strict_types=1);

namespace ClickLink;

final class Uninstaller
{
    /**
     * @var array<int, string>
     */
    private const OPTION_KEYS = array(
        'clicklink_schema_version',
        'clicklink_options',
        'clicklink_stats',
        'clicklink_backfill_run_state',
    );

    /**
     * @var array<int, string>
     */
    private const POST_META_KEYS = array(
        '_clicklink_content_hash',
        '_clicklink_touched_by_linker',
        '_clicklink_links_inserted_last_save',
        '_clicklink_links_inserted_total',
    );

    public static function uninstall(): void
    {
        self::drop_mappings_table();
        self::delete_options();
        self::delete_post_meta();
    }

    private static function drop_mappings_table(): void
    {
        global $wpdb;

        if (! is_object($wpdb) || ! method_exists($wpdb, 'query')) {
            return;
        }

        $table_name = Installer::table_name();

        if ($table_name === '') {
            return;
        }

        $escaped_table = str_replace('`', '``', $table_name);
        $wpdb->query("DROP TABLE IF EXISTS `{$escaped_table}`");
    }

    private static function delete_options(): void
    {
        if (function_exists('delete_option')) {
            foreach (self::OPTION_KEYS as $option_key) {
                delete_option($option_key);
            }
        }

        if (function_exists('delete_site_option')) {
            foreach (self::OPTION_KEYS as $option_key) {
                delete_site_option($option_key);
            }
        }
    }

    private static function delete_post_meta(): void
    {
        if (! function_exists('delete_post_meta_by_key')) {
            return;
        }

        foreach (self::POST_META_KEYS as $meta_key) {
            delete_post_meta_by_key($meta_key);
        }
    }
}
