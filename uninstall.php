<?php

declare(strict_types=1);

if (! defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

if (! defined('CLICKLINK_PATH')) {
    define('CLICKLINK_PATH', __DIR__ . '/');
}

require_once CLICKLINK_PATH . 'includes/class-installer.php';
require_once CLICKLINK_PATH . 'includes/class-uninstaller.php';

\ClickLink\Uninstaller::uninstall();
