<?php

declare(strict_types=1);

namespace ClickLink;

use ClickLink\Admin\Admin_Page;
use ClickLink\Admin\Dashboard_Widget;

final class Plugin
{
    private static ?self $instance = null;
    private ?Admin_Page $admin_page = null;
    private ?Dashboard_Widget $dashboard_widget = null;
    private ?Keyword_Mapping_Repository $mapping_repository = null;
    private ?Linker_Stats $linker_stats = null;
    private ?Post_Save_Linker $post_save_linker = null;
    private ?Backfill_Scanner $backfill_scanner = null;

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

        $this->mapping_repository = new Keyword_Mapping_Repository();
        $this->linker_stats = new Linker_Stats();
        $this->post_save_linker = new Post_Save_Linker($this->linker_stats, $this->mapping_repository);
        $this->post_save_linker->register();
        $this->backfill_scanner = new Backfill_Scanner($this->post_save_linker);

        if (function_exists('is_admin') && is_admin()) {
            $this->admin_page = new Admin_Page($this->mapping_repository, $this->backfill_scanner);
            $this->admin_page->register();

            $this->dashboard_widget = new Dashboard_Widget($this->linker_stats);
            $this->dashboard_widget->register();
        }
    }
}
