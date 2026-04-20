<?php

declare(strict_types=1);

namespace ClickLink;

use ClickLink\Admin\Admin_Page;

final class Plugin
{
    private static ?self $instance = null;
    private ?Admin_Page $admin_page = null;
    private ?Post_Save_Linker $post_save_linker = null;

    private function __construct()
    {
    }

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function run(): void
    {
        if (! Compatibility::is_supported_environment()) {
            if (function_exists('add_action')) {
                add_action('admin_notices', array(Compatibility::class, 'render_unsupported_notice'));
            }

            return;
        }

        Installer::maybe_upgrade();

        $this->post_save_linker = new Post_Save_Linker();
        $this->post_save_linker->register();

        if (function_exists('is_admin') && is_admin()) {
            $this->admin_page = new Admin_Page();
            $this->admin_page->register();
        }
    }
}
