<?php

declare(strict_types=1);

namespace ClickLink;

use RuntimeException;

final class Lifecycle
{
    public static function activate(): void
    {
        if (Compatibility::is_supported_environment()) {
            return;
        }

        self::deactivate_self();
        $message = self::activation_error_message();

        if (function_exists('wp_die')) {
            wp_die(self::escape($message));
            return;
        }

        throw new RuntimeException($message);
    }

    public static function deactivate(): void
    {
        // Kept intentionally empty for Phase 01 foundation.
    }

    private static function deactivate_self(): void
    {
        if (! defined('CLICKLINK_FILE')) {
            return;
        }

        if (! function_exists('deactivate_plugins') || ! function_exists('plugin_basename')) {
            return;
        }

        deactivate_plugins(plugin_basename(CLICKLINK_FILE));
    }

    private static function activation_error_message(): string
    {
        return 'ClickLink cannot be activated: ' . implode(' ', Compatibility::get_environment_errors());
    }

    private static function escape(string $value): string
    {
        if (function_exists('esc_html')) {
            return esc_html($value);
        }

        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
