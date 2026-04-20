<?php

declare(strict_types=1);

namespace ClickLink;

final class Backfill_Scanner
{
    private const STATE_OPTION_KEY = 'clicklink_backfill_run_state';
    private const STATUS_PENDING = 'pending';
    private const STATUS_RUNNING = 'running';
    private const STATUS_COMPLETED = 'completed';
    private const STATUS_ERROR = 'error';
    private const DEFAULT_BATCH_SIZE = 20;
    private const MAX_BATCH_SIZE = 100;
    private const TIMEOUT_BATCH_BUFFER_SECONDS = 5;
    private const ESTIMATED_SECONDS_PER_POST = 2;

    private Post_Save_Linker $post_save_linker;

    public function __construct(?Post_Save_Linker $post_save_linker = null)
    {
        $this->post_save_linker = $post_save_linker ?? new Post_Save_Linker();
    }

    /**
     * @return array{
     *   status: string,
     *   started_at: string,
     *   completed_at: string,
     *   cursor_post_id: int,
     *   processed_posts: int,
     *   changed_posts: int,
     *   inserted_links: int,
     *   failures: int,
     *   last_error: string,
     *   batch_size: int,
     *   total_eligible_posts: int
     * }
     */
    public function get_state(): array
    {
        $defaults = self::default_state();

        if (! function_exists('get_option')) {
            return $defaults;
        }

        $raw_state = get_option(self::STATE_OPTION_KEY, array());

        if (! is_array($raw_state)) {
            return $defaults;
        }

        $normalized_state = $defaults;
        $normalized_state['status'] = self::normalize_status($raw_state['status'] ?? $defaults['status']);
        $normalized_state['started_at'] = self::scalar_string($raw_state['started_at'] ?? $defaults['started_at']);
        $normalized_state['completed_at'] = self::scalar_string($raw_state['completed_at'] ?? $defaults['completed_at']);
        $normalized_state['cursor_post_id'] = self::non_negative_int($raw_state['cursor_post_id'] ?? $defaults['cursor_post_id']);
        $normalized_state['processed_posts'] = self::non_negative_int($raw_state['processed_posts'] ?? $defaults['processed_posts']);
        $normalized_state['changed_posts'] = self::non_negative_int($raw_state['changed_posts'] ?? $defaults['changed_posts']);
        $normalized_state['inserted_links'] = self::non_negative_int($raw_state['inserted_links'] ?? $defaults['inserted_links']);
        $normalized_state['failures'] = self::non_negative_int($raw_state['failures'] ?? $defaults['failures']);
        $normalized_state['last_error'] = self::scalar_string($raw_state['last_error'] ?? $defaults['last_error']);
        $normalized_state['batch_size'] = self::clamp_batch_size(
            self::non_negative_int($raw_state['batch_size'] ?? $defaults['batch_size'])
        );
        $normalized_state['total_eligible_posts'] = self::non_negative_int(
            $raw_state['total_eligible_posts'] ?? $defaults['total_eligible_posts']
        );

        return $normalized_state;
    }

    public function current_eligible_posts(): int
    {
        return $this->count_eligible_posts();
    }

    /**
     * @return array{
     *   status: string,
     *   started_at: string,
     *   completed_at: string,
     *   cursor_post_id: int,
     *   processed_posts: int,
     *   changed_posts: int,
     *   inserted_links: int,
     *   failures: int,
     *   last_error: string,
     *   batch_size: int,
     *   total_eligible_posts: int
     * }
     */
    public function reset_run(): array
    {
        $state = self::default_state();
        $state['total_eligible_posts'] = $this->count_eligible_posts();
        $this->persist_state($state);

        return $state;
    }

    /**
     * @return array{
     *   status: string,
     *   started_at: string,
     *   completed_at: string,
     *   cursor_post_id: int,
     *   processed_posts: int,
     *   changed_posts: int,
     *   inserted_links: int,
     *   failures: int,
     *   last_error: string,
     *   batch_size: int,
     *   total_eligible_posts: int
     * }
     */
    public function start_run(?int $batch_size = null): array
    {
        $resolved_batch_size = $this->resolve_batch_size($batch_size);
        $state = self::default_state();
        $state['batch_size'] = $resolved_batch_size;
        $state['total_eligible_posts'] = $this->count_eligible_posts();
        $this->persist_state($state);

        $state['status'] = self::STATUS_RUNNING;
        $state['started_at'] = $this->current_timestamp();
        $state['completed_at'] = '';
        $state['last_error'] = '';
        $this->persist_state($state);

        return $state;
    }

    /**
     * @return array{
     *   status: string,
     *   started_at: string,
     *   completed_at: string,
     *   cursor_post_id: int,
     *   processed_posts: int,
     *   changed_posts: int,
     *   inserted_links: int,
     *   failures: int,
     *   last_error: string,
     *   batch_size: int,
     *   total_eligible_posts: int
     * }
     */
    public function process_next_batch(): array
    {
        $state = $this->get_state();

        if ($state['status'] === self::STATUS_PENDING) {
            $state['status'] = self::STATUS_RUNNING;

            if ($state['started_at'] === '') {
                $state['started_at'] = $this->current_timestamp();
            }

            $this->persist_state($state);
        }

        if ($state['status'] !== self::STATUS_RUNNING) {
            return $state;
        }

        $batch_ids = $this->fetch_batch_post_ids((int) $state['cursor_post_id'], (int) $state['batch_size']);

        if ($batch_ids === null) {
            return $this->transition_to_error($state, 'Unable to query eligible blog posts for backfill.');
        }

        if ($batch_ids === array()) {
            return $this->transition_to_completed($state);
        }

        foreach ($batch_ids as $post_id) {
            $state['processed_posts']++;
            $state['cursor_post_id'] = $post_id;

            $post = $this->load_post($post_id);

            if (! is_object($post)) {
                $state['failures']++;
                $state['last_error'] = 'Unable to load post ID ' . $post_id . ' for backfill processing.';
                $this->persist_state($state);
                continue;
            }

            try {
                $link_result = $this->post_save_linker->process_post($post_id, $post, false);
            } catch (\Throwable $throwable) {
                $state['failures']++;
                $state['last_error'] = $throwable->getMessage();
                $this->persist_state($state);
                continue;
            }

            $state = $this->record_link_result($state, $link_result);
            $this->persist_state($state);
        }

        $has_remaining_posts = $this->has_remaining_posts((int) $state['cursor_post_id']);

        if ($has_remaining_posts === null) {
            return $this->transition_to_error($state, 'Unable to determine remaining blog posts for backfill.');
        }

        if (! $has_remaining_posts) {
            $state['status'] = self::STATUS_COMPLETED;
            $state['completed_at'] = $this->current_timestamp();
        }

        $this->persist_state($state);

        return $state;
    }

    /**
     * @return array{
     *   status: string,
     *   started_at: string,
     *   completed_at: string,
     *   cursor_post_id: int,
     *   processed_posts: int,
     *   changed_posts: int,
     *   inserted_links: int,
     *   failures: int,
     *   last_error: string,
     *   batch_size: int,
     *   total_eligible_posts: int
     * }
     */
    private static function default_state(): array
    {
        return array(
            'status' => self::STATUS_PENDING,
            'started_at' => '',
            'completed_at' => '',
            'cursor_post_id' => 0,
            'processed_posts' => 0,
            'changed_posts' => 0,
            'inserted_links' => 0,
            'failures' => 0,
            'last_error' => '',
            'batch_size' => self::DEFAULT_BATCH_SIZE,
            'total_eligible_posts' => 0,
        );
    }

    /**
     * @param array{
     *   status: string,
     *   started_at: string,
     *   completed_at: string,
     *   cursor_post_id: int,
     *   processed_posts: int,
     *   changed_posts: int,
     *   inserted_links: int,
     *   failures: int,
     *   last_error: string,
     *   batch_size: int,
     *   total_eligible_posts: int
     * } $state
     * @return array{
     *   status: string,
     *   started_at: string,
     *   completed_at: string,
     *   cursor_post_id: int,
     *   processed_posts: int,
     *   changed_posts: int,
     *   inserted_links: int,
     *   failures: int,
     *   last_error: string,
     *   batch_size: int,
     *   total_eligible_posts: int
     * }
     */
    private function transition_to_completed(array $state): array
    {
        $state['status'] = self::STATUS_COMPLETED;

        if ($state['completed_at'] === '') {
            $state['completed_at'] = $this->current_timestamp();
        }

        $this->persist_state($state);

        return $state;
    }

    /**
     * @param array{
     *   status: string,
     *   started_at: string,
     *   completed_at: string,
     *   cursor_post_id: int,
     *   processed_posts: int,
     *   changed_posts: int,
     *   inserted_links: int,
     *   failures: int,
     *   last_error: string,
     *   batch_size: int,
     *   total_eligible_posts: int
     * } $state
     * @return array{
     *   status: string,
     *   started_at: string,
     *   completed_at: string,
     *   cursor_post_id: int,
     *   processed_posts: int,
     *   changed_posts: int,
     *   inserted_links: int,
     *   failures: int,
     *   last_error: string,
     *   batch_size: int,
     *   total_eligible_posts: int
     * }
     */
    private function transition_to_error(array $state, string $message): array
    {
        $state['status'] = self::STATUS_ERROR;
        $state['last_error'] = trim($message);
        $state['completed_at'] = $this->current_timestamp();
        $this->persist_state($state);

        return $state;
    }

    /**
     * @param array{
     *   status: string,
     *   started_at: string,
     *   completed_at: string,
     *   cursor_post_id: int,
     *   processed_posts: int,
     *   changed_posts: int,
     *   inserted_links: int,
     *   failures: int,
     *   last_error: string,
     *   batch_size: int,
     *   total_eligible_posts: int
     * } $state
     * @param array{processed?: bool, changed?: bool, inserted_links?: int} $link_result
     * @return array{
     *   status: string,
     *   started_at: string,
     *   completed_at: string,
     *   cursor_post_id: int,
     *   processed_posts: int,
     *   changed_posts: int,
     *   inserted_links: int,
     *   failures: int,
     *   last_error: string,
     *   batch_size: int,
     *   total_eligible_posts: int
     * }
     */
    private function record_link_result(array $state, array $link_result): array
    {
        if ((bool) ($link_result['changed'] ?? false)) {
            $state['changed_posts']++;
        }

        $state['inserted_links'] += max(0, (int) ($link_result['inserted_links'] ?? 0));

        return $state;
    }

    /**
     * @param array{
     *   status: string,
     *   started_at: string,
     *   completed_at: string,
     *   cursor_post_id: int,
     *   processed_posts: int,
     *   changed_posts: int,
     *   inserted_links: int,
     *   failures: int,
     *   last_error: string,
     *   batch_size: int,
     *   total_eligible_posts: int
     * } $state
     */
    private function persist_state(array $state): void
    {
        if (! function_exists('update_option')) {
            return;
        }

        update_option(self::STATE_OPTION_KEY, $state, false);
    }

    private function resolve_batch_size(?int $batch_size): int
    {
        $requested_batch_size = self::non_negative_int($batch_size ?? self::DEFAULT_BATCH_SIZE);
        $sanitized_batch_size = self::clamp_batch_size($requested_batch_size);
        $timeout_batch_limit = $this->timeout_batch_limit();

        return min($sanitized_batch_size, $timeout_batch_limit);
    }

    private function timeout_batch_limit(): int
    {
        $default_limit = self::MAX_BATCH_SIZE;

        if (! function_exists('ini_get')) {
            return $default_limit;
        }

        $max_execution_time = self::non_negative_ini_int(ini_get('max_execution_time'));

        if ($max_execution_time <= 0) {
            return $default_limit;
        }

        $budget_seconds = max(1, $max_execution_time - self::TIMEOUT_BATCH_BUFFER_SECONDS);
        $calculated_limit = intdiv($budget_seconds, self::ESTIMATED_SECONDS_PER_POST);

        if ($calculated_limit <= 0) {
            return 1;
        }

        return self::clamp_batch_size($calculated_limit);
    }

    private function count_eligible_posts(): int
    {
        global $wpdb;

        if (! is_object($wpdb) || ! method_exists($wpdb, 'get_var')) {
            return 0;
        }

        $posts_table = self::posts_table_name($wpdb);
        $count = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$posts_table} WHERE post_type = 'post' AND post_status = 'publish'"
        );

        return self::non_negative_int($count);
    }

    /**
     * @return array<int>|null
     */
    private function fetch_batch_post_ids(int $cursor_post_id, int $batch_size): ?array
    {
        global $wpdb;

        if (! is_object($wpdb) || ! method_exists($wpdb, 'get_col')) {
            return null;
        }

        $posts_table = self::posts_table_name($wpdb);
        $cursor = max(0, $cursor_post_id);
        $limit = max(1, $batch_size);
        $query = "SELECT ID FROM {$posts_table} "
            . "WHERE post_type = 'post' AND post_status = 'publish' AND ID > {$cursor} "
            . "ORDER BY ID ASC LIMIT {$limit}";
        $results = $wpdb->get_col($query);

        if (! is_array($results)) {
            return null;
        }

        $post_ids = array();

        foreach ($results as $result) {
            $post_id = self::non_negative_int($result);

            if ($post_id <= 0) {
                continue;
            }

            $post_ids[] = $post_id;
        }

        return $post_ids;
    }

    /**
     * @return bool|null
     */
    private function has_remaining_posts(int $cursor_post_id): ?bool
    {
        $next_post_ids = $this->fetch_batch_post_ids($cursor_post_id, 1);

        if ($next_post_ids === null) {
            return null;
        }

        return $next_post_ids !== array();
    }

    /**
     * @return object|null
     */
    private function load_post(int $post_id): ?object
    {
        if (! function_exists('get_post')) {
            return null;
        }

        $post = get_post($post_id);

        if (! is_object($post)) {
            return null;
        }

        return $post;
    }

    /**
     * @param mixed $value
     */
    private static function scalar_string($value): string
    {
        if (! is_scalar($value)) {
            return '';
        }

        return trim((string) $value);
    }

    /**
     * @param mixed $value
     */
    private static function non_negative_int($value): int
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
    private static function non_negative_ini_int($value): int
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

    private static function clamp_batch_size(int $batch_size): int
    {
        return max(1, min(self::MAX_BATCH_SIZE, $batch_size));
    }

    private static function normalize_status(string $status): string
    {
        $allowed = array(
            self::STATUS_PENDING => true,
            self::STATUS_RUNNING => true,
            self::STATUS_COMPLETED => true,
            self::STATUS_ERROR => true,
        );

        if (! isset($allowed[$status])) {
            return self::STATUS_PENDING;
        }

        return $status;
    }

    /**
     * @param object $wpdb
     */
    private static function posts_table_name(object $wpdb): string
    {
        if (isset($wpdb->posts) && is_string($wpdb->posts) && $wpdb->posts !== '') {
            return $wpdb->posts;
        }

        if (isset($wpdb->prefix) && is_string($wpdb->prefix) && $wpdb->prefix !== '') {
            return $wpdb->prefix . 'posts';
        }

        return 'posts';
    }

    private function current_timestamp(): string
    {
        if (function_exists('current_time')) {
            return (string) current_time('mysql', true);
        }

        return gmdate('Y-m-d H:i:s');
    }
}
