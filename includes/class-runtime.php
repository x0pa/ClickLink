<?php

declare(strict_types=1);

namespace ClickLink;

use Throwable;

final class Runtime
{
    private const CLICKLINK_OPTIONS_OPTION_KEY = 'clicklink_options';

    /**
     * @param mixed $value
     */
    public static function scalar_string($value): string
    {
        if (! is_scalar($value)) {
            return '';
        }

        return trim((string) $value);
    }

    /**
     * @param mixed $value
     */
    public static function non_negative_int($value): int
    {
        if (! is_scalar($value) || $value === '') {
            return 0;
        }

        $validated = filter_var(
            (string) $value,
            FILTER_VALIDATE_INT,
            array(
                'options' => array(
                    'min_range' => 0,
                ),
            )
        );

        if ($validated === false) {
            return 0;
        }

        return (int) $validated;
    }

    /**
     * @param mixed $value
     */
    public static function positive_int($value): int
    {
        if (! is_scalar($value) || $value === '') {
            return 0;
        }

        $validated = filter_var(
            (string) $value,
            FILTER_VALIDATE_INT,
            array(
                'options' => array(
                    'min_range' => 1,
                ),
            )
        );

        if ($validated === false) {
            return 0;
        }

        return (int) $validated;
    }

    /**
     * @param object $wpdb
     */
    public static function posts_table_name(object $wpdb): string
    {
        if (isset($wpdb->posts) && is_string($wpdb->posts) && $wpdb->posts !== '') {
            return $wpdb->posts;
        }

        if (isset($wpdb->prefix) && is_string($wpdb->prefix) && $wpdb->prefix !== '') {
            return $wpdb->prefix . 'posts';
        }

        return 'posts';
    }

    public static function current_datetime_utc(): string
    {
        if (function_exists('current_time')) {
            $current_time = current_time('mysql', true);

            if (is_string($current_time) && $current_time !== '') {
                return $current_time;
            }
        }

        return gmdate('Y-m-d H:i:s');
    }

    public static function throwable_message(Throwable $throwable, string $fallback = 'Unexpected processing error.'): string
    {
        $message = trim($throwable->getMessage());

        if ($message === '') {
            return $fallback;
        }

        return $message;
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function is_debug_logging_enabled(array $context = array()): bool
    {
        $enabled = false;

        if (function_exists('get_option')) {
            $options = get_option(self::CLICKLINK_OPTIONS_OPTION_KEY, array());

            if (is_array($options)) {
                $enabled = self::truthy_flag($options['debug_logging_enabled'] ?? false);
            }
        }

        if (function_exists('apply_filters')) {
            $enabled = (bool) apply_filters('clicklink_debug_logging_enabled', $enabled, $context);
        }

        return $enabled;
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function debug_log(string $event, array $context = array(), bool $force = false): void
    {
        $event = trim($event);

        if ($event === '') {
            return;
        }

        $payload = array(
            'event' => $event,
            'timestamp' => self::current_datetime_utc(),
            'context' => self::normalize_log_context($context, 0),
        );

        if (! $force && ! self::is_debug_logging_enabled($payload)) {
            return;
        }

        if (function_exists('do_action')) {
            do_action('clicklink_debug_log', $payload);
        }

        $encoded_payload = self::encode_log_payload($payload);

        if ($encoded_payload === '') {
            return;
        }

        if (function_exists('error_log')) {
            error_log('[ClickLink] ' . $encoded_payload);
        }
    }

    /**
     * @param mixed $value
     */
    private static function truthy_flag($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (! is_scalar($value)) {
            return false;
        }

        $normalized = strtolower(trim((string) $value));

        return in_array($normalized, array('1', 'true', 'yes', 'on'), true);
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private static function normalize_log_context(array $context, int $depth): array
    {
        if ($context === array()) {
            return array();
        }

        $normalized = array();

        foreach ($context as $key => $value) {
            $normalized_key = trim((string) $key);

            if ($normalized_key === '') {
                continue;
            }

            $normalized[$normalized_key] = self::normalize_log_value($value, $depth + 1);
        }

        ksort($normalized, SORT_STRING);

        return $normalized;
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    private static function normalize_log_value($value, int $depth)
    {
        if ($depth > 3) {
            return '[depth-limited]';
        }

        if (is_array($value)) {
            return self::normalize_log_context($value, $depth);
        }

        if (is_null($value) || is_scalar($value)) {
            return $value;
        }

        if (is_object($value)) {
            return '[object:' . get_class($value) . ']';
        }

        if (is_resource($value)) {
            return '[resource:' . get_resource_type($value) . ']';
        }

        return '[unsupported]';
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function encode_log_payload(array $payload): string
    {
        $json_flags = JSON_UNESCAPED_SLASHES;

        if (defined('JSON_PARTIAL_OUTPUT_ON_ERROR')) {
            $json_flags |= JSON_PARTIAL_OUTPUT_ON_ERROR;
        }

        $encoded = json_encode($payload, $json_flags);

        if (is_string($encoded) && $encoded !== '') {
            return $encoded;
        }

        return '';
    }
}
