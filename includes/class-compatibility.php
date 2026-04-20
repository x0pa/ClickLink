<?php

declare(strict_types=1);

namespace ClickLink;

final class Compatibility
{
    public static function is_supported_environment(): bool
    {
        return self::get_environment_errors() === array();
    }

    /**
     * @return array<int, string>
     */
    public static function get_environment_errors(): array
    {
        global $wp_version;

        $errors = array();

        if (version_compare(PHP_VERSION, CLICKLINK_MIN_PHP_VERSION, '<')) {
            $errors[] = sprintf(
                'PHP %s or higher is required (current: %s).',
                CLICKLINK_MIN_PHP_VERSION,
                PHP_VERSION
            );
        }

        if (isset($wp_version) && is_string($wp_version) && version_compare($wp_version, CLICKLINK_MIN_WP_VERSION, '<')) {
            $errors[] = sprintf(
                'WordPress %s or higher is required (current: %s).',
                CLICKLINK_MIN_WP_VERSION,
                $wp_version
            );
        }

        if (self::is_multisite()) {
            $errors[] = 'Multisite is not supported in this phase.';
        }

        return $errors;
    }

    public static function is_multisite(): bool
    {
        return function_exists('is_multisite') && is_multisite();
    }

    public static function render_unsupported_notice(): void
    {
        if (! function_exists('current_user_can') || ! current_user_can('activate_plugins')) {
            return;
        }

        $errors = self::get_environment_errors();

        if ($errors === array()) {
            return;
        }

        $notice = 'ClickLink is disabled: ' . implode(' ', $errors);
        echo '<div class="notice notice-error"><p>' . self::escape($notice) . '</p></div>';
    }

    private static function escape(string $value): string
    {
        if (function_exists('esc_html')) {
            return esc_html($value);
        }

        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
