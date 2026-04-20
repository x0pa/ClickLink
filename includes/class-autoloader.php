<?php

declare(strict_types=1);

namespace ClickLink;

final class Autoloader
{
    private const PREFIX = 'ClickLink\\';

    public static function register(): void
    {
        spl_autoload_register(array(self::class, 'autoload'));
    }

    public static function autoload(string $class): void
    {
        if (strncmp($class, self::PREFIX, strlen(self::PREFIX)) !== 0) {
            return;
        }

        $relative_class = substr($class, strlen(self::PREFIX));
        $class_map = array(
            'Plugin' => CLICKLINK_PATH . 'includes/class-plugin.php',
            'Compatibility' => CLICKLINK_PATH . 'includes/class-compatibility.php',
            'Lifecycle' => CLICKLINK_PATH . 'includes/class-lifecycle.php',
            'Installer' => CLICKLINK_PATH . 'includes/class-installer.php',
            'Linker_Stats' => CLICKLINK_PATH . 'includes/class-linker-stats.php',
            'Post_Save_Linker' => CLICKLINK_PATH . 'includes/class-post-save-linker.php',
            'Admin\\Admin_Page' => CLICKLINK_PATH . 'admin/class-admin-page.php',
            'Admin\\Dashboard_Widget' => CLICKLINK_PATH . 'admin/class-dashboard-widget.php',
        );

        if (! isset($class_map[$relative_class])) {
            return;
        }

        $file_path = $class_map[$relative_class];

        if (is_readable($file_path)) {
            require_once $file_path;
        }
    }
}
