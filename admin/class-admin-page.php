<?php

declare(strict_types=1);

namespace ClickLink\Admin;

final class Admin_Page
{
    private const CAPABILITY = 'manage_options';
    public const MENU_SLUG = 'clicklink';

    public function register(): void
    {
        if (! function_exists('add_action')) {
            return;
        }

        add_action('admin_menu', array($this, 'register_menu'));
    }

    public function register_menu(): void
    {
        if (! function_exists('current_user_can') || ! current_user_can(self::CAPABILITY)) {
            return;
        }

        if (! function_exists('add_menu_page')) {
            return;
        }

        add_menu_page(
            self::translate('ClickLink'),
            self::translate('ClickLink'),
            self::CAPABILITY,
            self::MENU_SLUG,
            array($this, 'render'),
            'dashicons-admin-links',
            65
        );
    }

    public function render(): void
    {
        if (! function_exists('current_user_can') || ! current_user_can(self::CAPABILITY)) {
            if (function_exists('wp_die')) {
                wp_die(self::escape('You do not have permission to access this page.'));
            }

            return;
        }

        echo '<div class="wrap">';
        echo '<h1>' . self::escape(self::translate('ClickLink')) . '</h1>';
        echo '<p>' . self::escape(self::translate('Foundation scaffold is active. Mapping CRUD is added in the next task.')) . '</p>';
        echo '</div>';
    }

    private static function translate(string $value): string
    {
        if (function_exists('__')) {
            return __($value, 'clicklink');
        }

        return $value;
    }

    private static function escape(string $value): string
    {
        if (function_exists('esc_html')) {
            return esc_html($value);
        }

        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
