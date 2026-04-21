<?php
/**
 * Plugin Name: ClickLink
 * Description: Auto-link keyword mappings in posts and surface baseline metrics.
 * Version: 0.1.0
 * Author: Dade Williams
 * Author URI: https://www.dadewilliams.com
 * Text Domain: clicklink
 * Domain Path: /languages
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/old-licenses/gpl-2.0.html
 * Requires at least: 6.5
 * Requires PHP: 8.1
 * Network: false
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

// Keep this synchronized with the plugin header Version and release changelog entries.
define('CLICKLINK_VERSION', '0.1.0');
define('CLICKLINK_FILE', __FILE__);
define('CLICKLINK_PATH', plugin_dir_path(CLICKLINK_FILE));
define('CLICKLINK_URL', plugin_dir_url(CLICKLINK_FILE));
define('CLICKLINK_MIN_WP_VERSION', '6.5');
define('CLICKLINK_MIN_PHP_VERSION', '8.1');

require_once CLICKLINK_PATH . 'includes/class-autoloader.php';

\ClickLink\Autoloader::register();

register_activation_hook(CLICKLINK_FILE, array('ClickLink\\Lifecycle', 'activate'));
register_deactivation_hook(CLICKLINK_FILE, array('ClickLink\\Lifecycle', 'deactivate'));

add_action(
    'plugins_loaded',
    static function (): void {
        \ClickLink\Plugin::instance()->run();
    }
);
