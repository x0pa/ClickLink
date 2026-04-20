<?php

declare(strict_types=1);

final class ClickLink_Test_Dashboard_WPDB
{
    public string $prefix = 'wp_';
    public string $posts = 'wp_posts';
    public int $mapping_count = 0;

    /**
     * @return int
     */
    public function get_var(string $query)
    {
        if (str_contains($query, 'clicklink_keyword_mappings')) {
            return $this->mapping_count;
        }

        return 0;
    }
}

$clicklink_test_actions = array();
$clicklink_test_dashboard_widgets = array();
$clicklink_test_can_manage = true;
$clicklink_test_options = array();
$clicklink_test_post_meta = array();
$clicklink_test_count_posts = (object) array(
    'publish' => 0,
    'draft' => 0,
    'auto-draft' => 0,
);

if (! function_exists('add_action')) {
    function add_action(string $hook, callable $callback): void
    {
        global $clicklink_test_actions;

        $clicklink_test_actions[$hook][] = $callback;
    }
}

if (! function_exists('wp_add_dashboard_widget')) {
    function wp_add_dashboard_widget(string $widget_id, string $title, callable $callback): void
    {
        global $clicklink_test_dashboard_widgets;

        $clicklink_test_dashboard_widgets[] = array(
            'widget_id' => $widget_id,
            'title' => $title,
            'callback' => $callback,
        );
    }
}

if (! function_exists('current_user_can')) {
    function current_user_can(string $capability): bool
    {
        global $clicklink_test_can_manage;

        return (bool) $clicklink_test_can_manage;
    }
}

if (! function_exists('__')) {
    function __(string $text, string $domain = 'default'): string
    {
        return $text;
    }
}

if (! function_exists('esc_html')) {
    function esc_html(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (! function_exists('number_format_i18n')) {
    function number_format_i18n(int $number): string
    {
        return number_format($number);
    }
}

if (! function_exists('get_option')) {
    /**
     * @return mixed
     */
    function get_option(string $name, $default = false)
    {
        global $clicklink_test_options;

        if (! array_key_exists($name, $clicklink_test_options)) {
            return $default;
        }

        return $clicklink_test_options[$name];
    }
}

if (! function_exists('update_option')) {
    /**
     * @param mixed $value
     */
    function update_option(string $name, $value, bool $autoload = false): void
    {
        global $clicklink_test_options;

        $clicklink_test_options[$name] = $value;
    }
}

if (! function_exists('get_post_meta')) {
    /**
     * @return mixed
     */
    function get_post_meta(int $post_id, string $key, bool $single = false)
    {
        global $clicklink_test_post_meta;

        if (! isset($clicklink_test_post_meta[$post_id]) || ! array_key_exists($key, $clicklink_test_post_meta[$post_id])) {
            return $single ? '' : array();
        }

        $value = $clicklink_test_post_meta[$post_id][$key];

        if ($single) {
            return $value;
        }

        return array($value);
    }
}

if (! function_exists('update_post_meta')) {
    /**
     * @param mixed $value
     */
    function update_post_meta(int $post_id, string $key, $value): void
    {
        global $clicklink_test_post_meta;

        if (! isset($clicklink_test_post_meta[$post_id]) || ! is_array($clicklink_test_post_meta[$post_id])) {
            $clicklink_test_post_meta[$post_id] = array();
        }

        $clicklink_test_post_meta[$post_id][$key] = $value;
    }
}

if (! function_exists('wp_count_posts')) {
    /**
     * @return object
     */
    function wp_count_posts(string $type = 'post')
    {
        global $clicklink_test_count_posts;

        return $clicklink_test_count_posts;
    }
}

global $wpdb;
$wpdb = new ClickLink_Test_Dashboard_WPDB();

require_once __DIR__ . '/../includes/class-installer.php';
require_once __DIR__ . '/../includes/class-linker-stats.php';
require_once __DIR__ . '/../admin/class-dashboard-widget.php';

$failures = array();

$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (! $condition) {
        $failures[] = $message;
    }
};

$stats = new \ClickLink\Linker_Stats();
$widget = new \ClickLink\Admin\Dashboard_Widget($stats);
$widget->register();

$assert(
    isset($clicklink_test_actions['wp_dashboard_setup']),
    'Expected Dashboard_Widget::register() to hook wp_dashboard_setup.'
);

$clicklink_test_can_manage = true;
$widget->register_widget();
$registered_widget = $clicklink_test_dashboard_widgets[0] ?? null;

$assert(
    is_array($registered_widget) && ($registered_widget['widget_id'] ?? '') === 'clicklink_dashboard_widget',
    'Expected dashboard widget registration with the ClickLink widget ID.'
);
$assert(
    is_array($registered_widget) && ($registered_widget['title'] ?? '') === 'ClickLink Stats',
    'Expected dashboard widget title to match ClickLink Stats.'
);

$clicklink_test_count_posts = (object) array(
    'publish' => 0,
    'draft' => 0,
    'auto-draft' => 0,
);
$wpdb->mapping_count = 0;

ob_start();
$widget->render();
$empty_output = (string) ob_get_clean();

$assert(
    str_contains($empty_output, 'Total keyword/url rows')
        && str_contains($empty_output, 'Total links created')
        && str_contains($empty_output, '>0<'),
    'Expected dashboard render to safely show zero values for required headline metrics when no mappings or stats exist.'
);
$assert(
    str_contains($empty_output, 'No matched keywords yet.'),
    'Expected dashboard render to show an empty-state message for top keywords when no matches have been recorded.'
);

$clicklink_test_count_posts = (object) array(
    'publish' => 8,
    'draft' => 1,
    'auto-draft' => 4,
);
$wpdb->mapping_count = 3;
$stats->record_save_metrics(77, 4, array('apple' => 3, 'banana' => 1));
$stats->record_save_metrics(77, 2, array('apple' => 2));
update_option(
    'clicklink_backfill_run_state',
    array(
        'status' => 'completed',
        'inserted_links' => 5,
    ),
    false
);

$totals = $stats->get_totals();

$assert(
    (int) ($totals['total_links_inserted'] ?? 0) === 6,
    'Expected cumulative links inserted to increment with each qualifying save.'
);
$assert(
    (int) ($totals['posts_touched'] ?? 0) === 1,
    'Expected posts_touched to remain unique per post ID.'
);
$assert(
    is_array($totals['keyword_match_counts'] ?? null)
        && (int) (($totals['keyword_match_counts']['apple'] ?? 0)) === 5
        && (int) (($totals['keyword_match_counts']['banana'] ?? 0)) === 1,
    'Expected keyword match counts to accumulate for each inserted keyword across repeated saves.'
);

ob_start();
$widget->render();
$stats_output = (string) ob_get_clean();

$assert(
    str_contains($stats_output, '<td>9</td>'),
    'Expected total blog posts to exclude auto-draft counts in widget output.'
);
$assert(
    str_contains($stats_output, '<td>3</td>'),
    'Expected total keyword/url row count to render from mapping table totals.'
);
$assert(
    str_contains($stats_output, '<td>6</td>'),
    'Expected widget output to render updated cumulative links-created totals immediately.'
);
$assert(
    str_contains($stats_output, '<td>1</td>'),
    'Expected widget output to render posts-with-links totals from linker metrics.'
);
$assert(
    str_contains($stats_output, '<td>5</td>'),
    'Expected widget output to render latest backfill run link totals from scanner state metadata.'
);
$assert(
    str_contains($stats_output, '<td>6.00</td>'),
    'Expected widget output to render average links per changed post using decimal formatting.'
);
$assert(
    str_contains($stats_output, '<code>apple</code>: 5') && str_contains($stats_output, '<code>banana</code>: 1'),
    'Expected widget output to render top matched keyword leaderboard entries.'
);

$widget_count_before_denied = count($clicklink_test_dashboard_widgets);
$clicklink_test_can_manage = false;
$widget->register_widget();

$assert(
    count($clicklink_test_dashboard_widgets) === $widget_count_before_denied,
    'Did not expect dashboard widget registration when capability checks fail.'
);

if ($failures !== array()) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}\n");
    }

    exit(1);
}

echo "PASS: dashboard widget\n";
