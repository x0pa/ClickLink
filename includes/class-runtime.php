<?php

declare(strict_types=1);

namespace ClickLink;

use Throwable;

final class Runtime
{
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
}
