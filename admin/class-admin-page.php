<?php

declare(strict_types=1);

namespace ClickLink\Admin;

use ClickLink\Backfill_Scanner;
use ClickLink\Installer;
use ClickLink\Keyword_Mapping_Repository;

final class Admin_Page
{
    private const CAPABILITY = 'manage_options';
    public const MENU_SLUG = 'clicklink';
    private const SAVE_ACTION = 'clicklink_save_mapping';
    private const DELETE_ACTION = 'clicklink_delete_mapping';
    private const START_SCAN_ACTION = 'clicklink_backfill_start';
    private const NEXT_BATCH_ACTION = 'clicklink_backfill_next_batch';
    private const RESET_SCAN_ACTION = 'clicklink_backfill_reset';
    private const SAVE_NONCE_ACTION = 'clicklink_save_mapping';
    private const DELETE_NONCE_ACTION = 'clicklink_delete_mapping';
    private const START_SCAN_NONCE_ACTION = 'clicklink_backfill_start';
    private const NEXT_BATCH_NONCE_ACTION = 'clicklink_backfill_next_batch';
    private const RESET_SCAN_NONCE_ACTION = 'clicklink_backfill_reset';
    private const NOTICE_QUERY_KEY = 'clicklink_notice';
    private Keyword_Mapping_Repository $mapping_repository;
    private ?Backfill_Scanner $backfill_scanner;

    public function __construct(
        ?Keyword_Mapping_Repository $mapping_repository = null,
        ?Backfill_Scanner $backfill_scanner = null
    ) {
        $this->mapping_repository = $mapping_repository ?? new Keyword_Mapping_Repository();
        $this->backfill_scanner = $backfill_scanner;
    }

    public function register(): void
    {
        if (! function_exists('add_action')) {
            return;
        }

        add_action('admin_menu', array($this, 'register_menu'));
        add_action('admin_post_' . self::SAVE_ACTION, array($this, 'handle_save_mapping'));
        add_action('admin_post_' . self::DELETE_ACTION, array($this, 'handle_delete_mapping'));
        add_action('admin_post_' . self::START_SCAN_ACTION, array($this, 'handle_start_scan'));
        add_action('admin_post_' . self::NEXT_BATCH_ACTION, array($this, 'handle_next_batch'));
        add_action('admin_post_' . self::RESET_SCAN_ACTION, array($this, 'handle_reset_scan'));
        add_action('wp_ajax_' . self::START_SCAN_ACTION, array($this, 'handle_start_scan_ajax'));
        add_action('wp_ajax_' . self::NEXT_BATCH_ACTION, array($this, 'handle_next_batch_ajax'));
        add_action('wp_ajax_' . self::RESET_SCAN_ACTION, array($this, 'handle_reset_scan_ajax'));
    }

    public function register_menu(): void
    {
        if (! self::can_manage()) {
            return;
        }

        if (! function_exists('add_submenu_page')) {
            return;
        }

        add_submenu_page(
            'index.php',
            self::translate('ClickLink'),
            self::translate('ClickLink'),
            self::CAPABILITY,
            self::MENU_SLUG,
            array($this, 'render')
        );
    }

    public function render(): void
    {
        if (! self::can_manage()) {
            $this->deny_access();
            return;
        }

        $edit_mapping = $this->get_edit_mapping();

        echo '<div class="wrap">';
        echo '<h1>' . self::escape(self::translate('ClickLink')) . '</h1>';
        echo '<p>' . self::escape(self::translate('Manage keyword-to-URL mappings used by ClickLink auto-linking. Duplicate keywords are allowed.')) . '</p>';
        $this->render_notice();
        $this->render_backfill_scanner_panel();
        $this->render_form($edit_mapping);
        $this->render_mappings_table();
        echo '</div>';
    }

    public function handle_save_mapping(): void
    {
        if (! self::can_manage()) {
            $this->deny_access();
            return;
        }

        if (! $this->verify_nonce(self::SAVE_NONCE_ACTION)) {
            $this->redirect_with_notice('invalid_nonce');
            return;
        }

        $mapping_id = self::positive_int(self::request_post('mapping_id'));
        $keyword = Keyword_Mapping_Repository::normalize_keyword_for_storage(self::request_post('keyword'));
        $url = Keyword_Mapping_Repository::sanitize_url(self::request_post('url'));

        if ($keyword === '' || $url === '') {
            $this->redirect_with_notice('invalid_input');
            return;
        }

        if ($mapping_id > 0) {
            if (! $this->mapping_exists($mapping_id)) {
                $this->redirect_with_notice('not_found');
                return;
            }

            if ($this->update_mapping($mapping_id, $keyword, $url)) {
                $this->redirect_with_notice('updated');
                return;
            }

            $this->redirect_with_notice('db_error');
            return;
        }

        if ($this->insert_mapping($keyword, $url)) {
            $this->redirect_with_notice('created');
            return;
        }

        $this->redirect_with_notice('db_error');
    }

    public function handle_delete_mapping(): void
    {
        if (! self::can_manage()) {
            $this->deny_access();
            return;
        }

        if (! $this->verify_nonce(self::DELETE_NONCE_ACTION)) {
            $this->redirect_with_notice('invalid_nonce');
            return;
        }

        $mapping_id = self::positive_int(self::request_post('mapping_id'));

        if ($mapping_id <= 0) {
            $this->redirect_with_notice('invalid_input');
            return;
        }

        if (! $this->mapping_exists($mapping_id)) {
            $this->redirect_with_notice('not_found');
            return;
        }

        if ($this->delete_mapping($mapping_id)) {
            $this->redirect_with_notice('deleted');
            return;
        }

        $this->redirect_with_notice('db_error');
    }

    public function handle_start_scan(): void
    {
        if (! self::can_manage()) {
            $this->deny_access();
            return;
        }

        if (! $this->verify_nonce(self::START_SCAN_NONCE_ACTION)) {
            $this->redirect_with_notice('invalid_nonce');
            return;
        }

        $this->redirect_with_notice($this->start_scan_notice_code());
    }

    public function handle_next_batch(): void
    {
        if (! self::can_manage()) {
            $this->deny_access();
            return;
        }

        if (! $this->verify_nonce(self::NEXT_BATCH_NONCE_ACTION)) {
            $this->redirect_with_notice('invalid_nonce');
            return;
        }

        $this->redirect_with_notice($this->next_batch_notice_code());
    }

    public function handle_reset_scan(): void
    {
        if (! self::can_manage()) {
            $this->deny_access();
            return;
        }

        if (! $this->verify_nonce(self::RESET_SCAN_NONCE_ACTION)) {
            $this->redirect_with_notice('invalid_nonce');
            return;
        }

        $this->redirect_with_notice($this->reset_scan_notice_code());
    }

    public function handle_start_scan_ajax(): void
    {
        $this->handle_scan_ajax_request(
            self::START_SCAN_NONCE_ACTION,
            static fn (self $page): string => $page->start_scan_notice_code()
        );
    }

    public function handle_next_batch_ajax(): void
    {
        $this->handle_scan_ajax_request(
            self::NEXT_BATCH_NONCE_ACTION,
            static fn (self $page): string => $page->next_batch_notice_code()
        );
    }

    public function handle_reset_scan_ajax(): void
    {
        $this->handle_scan_ajax_request(
            self::RESET_SCAN_NONCE_ACTION,
            static fn (self $page): string => $page->reset_scan_notice_code()
        );
    }

    private function start_scan_notice_code(): string
    {
        $scanner = $this->backfill_scanner();
        $state = $scanner->get_state();

        if (($state['status'] ?? '') === 'running') {
            return 'scan_already_running';
        }

        if ($scanner->current_eligible_posts() <= 0) {
            return 'scan_no_posts';
        }

        $requested_batch_size = self::positive_int(self::request_post('batch_size'));
        $started_state = $scanner->start_run($requested_batch_size > 0 ? $requested_batch_size : null);

        if (($started_state['status'] ?? '') === 'running') {
            return 'scan_started';
        }

        return 'scan_start_failed';
    }

    private function next_batch_notice_code(): string
    {
        $scanner = $this->backfill_scanner();
        $state = $scanner->get_state();
        $status = self::scalar_string($state['status'] ?? '');

        if ($status === 'completed') {
            return 'scan_completed';
        }

        if ($status === 'error') {
            return 'scan_error';
        }

        if ($status !== 'running') {
            return 'scan_not_running';
        }

        $processed_state = $scanner->process_next_batch();
        $processed_status = self::scalar_string($processed_state['status'] ?? '');

        if ($processed_status === 'running') {
            return 'scan_batch_processed';
        }

        if ($processed_status === 'completed') {
            return 'scan_completed';
        }

        if ($processed_status === 'error') {
            return 'scan_error';
        }

        return 'scan_next_failed';
    }

    private function reset_scan_notice_code(): string
    {
        $scanner = $this->backfill_scanner();
        $state = $scanner->reset_run();

        if (($state['status'] ?? '') === 'pending') {
            return 'scan_reset';
        }

        return 'scan_reset_failed';
    }

    /**
     * @param callable(self): string $operation
     */
    private function handle_scan_ajax_request(string $nonce_action, callable $operation): void
    {
        if (! self::can_manage()) {
            $this->send_ajax_notice_response('forbidden', false, 403);
            return;
        }

        if (! $this->verify_nonce($nonce_action)) {
            $this->send_ajax_notice_response('invalid_nonce', false, 403);
            return;
        }

        $notice_code = $operation($this);
        $success = in_array(
            $notice_code,
            array('scan_started', 'scan_batch_processed', 'scan_completed', 'scan_reset', 'scan_already_running', 'scan_no_posts'),
            true
        );

        $status_code = $success ? 200 : 400;
        $this->send_ajax_notice_response($notice_code, $success, $status_code);
    }

    private function send_ajax_notice_response(string $notice_code, bool $success, int $status_code): void
    {
        $scanner_state = $this->backfill_scanner()->get_state();
        $response = array(
            'success' => $success,
            'notice' => $notice_code,
            'state' => $scanner_state,
        );

        if (function_exists('wp_send_json')) {
            wp_send_json($response, $status_code);
            return;
        }

        if (! headers_sent()) {
            header('Content-Type: application/json; charset=utf-8', true, $status_code);
        }

        echo (string) json_encode($response);
    }

    private function render_backfill_scanner_panel(): void
    {
        $scanner = $this->backfill_scanner();
        $state = $scanner->get_state();
        $status = self::scalar_string($state['status'] ?? 'pending');
        $processed_posts = self::non_negative_int($state['processed_posts'] ?? 0);
        $changed_posts = self::non_negative_int($state['changed_posts'] ?? 0);
        $inserted_links = self::non_negative_int($state['inserted_links'] ?? 0);
        $batch_size = max(1, self::non_negative_int($state['batch_size'] ?? 0));
        $state_total_eligible_posts = self::non_negative_int($state['total_eligible_posts'] ?? 0);
        $current_eligible_posts = max(0, $scanner->current_eligible_posts());
        $started_at = self::scalar_string($state['started_at'] ?? '');
        $completed_at = self::scalar_string($state['completed_at'] ?? '');
        $last_error = self::scalar_string($state['last_error'] ?? '');

        $display_total_eligible_posts = $state_total_eligible_posts;

        if ($display_total_eligible_posts <= 0 || $status === 'pending' || $status === 'error') {
            $display_total_eligible_posts = $current_eligible_posts;
        }

        $remaining_posts = max(0, $display_total_eligible_posts - $processed_posts);
        $scan_in_progress = $status === 'running';
        $scan_ready_for_batches = $status === 'running';
        $can_start_scan = ! $scan_in_progress && $current_eligible_posts > 0;
        $can_reset_scan = $status !== 'pending';
        $start_button_disabled_attr = $can_start_scan ? '' : ' disabled';
        $next_batch_button_disabled_attr = $scan_ready_for_batches ? '' : ' disabled';
        $reset_button_disabled_attr = $can_reset_scan ? '' : ' disabled';

        echo '<hr />';
        echo '<h2>' . self::escape(self::translate('Manual Backfill Scanner')) . '</h2>';
        echo '<p>' . self::escape(self::translate('Run a manual scan of published blog posts using the same linking rules used on save.')) . '</p>';
        echo '<table class="widefat striped" role="presentation">';
        echo '<tbody>';
        echo '<tr><th scope="row">' . self::escape(self::translate('Current status')) . '</th><td>' . self::escape($this->status_label($status)) . '</td></tr>';
        echo '<tr><th scope="row">' . self::escape(self::translate('Run started')) . '</th><td>' . self::escape($this->display_timestamp($started_at)) . '</td></tr>';
        echo '<tr><th scope="row">' . self::escape(self::translate('Run completed')) . '</th><td>' . self::escape($this->display_timestamp($completed_at)) . '</td></tr>';
        echo '<tr><th scope="row">' . self::escape(self::translate('Batch size')) . '</th><td>' . self::escape(self::format_number($batch_size)) . '</td></tr>';
        echo '<tr><th scope="row">' . self::escape(self::translate('Scanned posts')) . '</th><td>' . self::escape(self::format_number($processed_posts)) . '</td></tr>';
        echo '<tr><th scope="row">' . self::escape(self::translate('Changed posts')) . '</th><td>' . self::escape(self::format_number($changed_posts)) . '</td></tr>';
        echo '<tr><th scope="row">' . self::escape(self::translate('Inserted links')) . '</th><td>' . self::escape(self::format_number($inserted_links)) . '</td></tr>';
        echo '<tr><th scope="row">' . self::escape(self::translate('Remaining posts')) . '</th><td>' . self::escape(self::format_number($remaining_posts)) . '</td></tr>';
        echo '<tr><th scope="row">' . self::escape(self::translate('Last error')) . '</th><td>' . self::escape($last_error === '' ? self::translate('None') : $last_error) . '</td></tr>';
        echo '</tbody>';
        echo '</table>';

        if ($current_eligible_posts <= 0 && ! $scan_in_progress) {
            echo '<p>' . self::escape(self::translate('No published blog posts are currently eligible for backfill.')) . '</p>';
        }

        echo '<form method="post" action="' . self::escape_url($this->admin_post_url()) . '">';
        echo '<input type="hidden" name="action" value="' . self::escape_attr(self::START_SCAN_ACTION) . '">';
        echo self::nonce_field(self::START_SCAN_NONCE_ACTION);
        echo '<p class="submit"><button type="submit" class="button button-primary"' . $start_button_disabled_attr . '>';
        echo self::escape(self::translate('Run Now'));
        echo '</button></p>';
        echo '</form>';

        echo '<form method="post" action="' . self::escape_url($this->admin_post_url()) . '">';
        echo '<input type="hidden" name="action" value="' . self::escape_attr(self::NEXT_BATCH_ACTION) . '">';
        echo self::nonce_field(self::NEXT_BATCH_NONCE_ACTION);
        echo '<p class="submit"><button type="submit" class="button button-secondary"' . $next_batch_button_disabled_attr . '>';
        echo self::escape(self::translate('Process Next Batch'));
        echo '</button></p>';
        echo '</form>';

        echo '<form method="post" action="' . self::escape_url($this->admin_post_url()) . '">';
        echo '<input type="hidden" name="action" value="' . self::escape_attr(self::RESET_SCAN_ACTION) . '">';
        echo self::nonce_field(self::RESET_SCAN_NONCE_ACTION);
        echo '<p class="submit"><button type="submit" class="button button-secondary"' . $reset_button_disabled_attr . '>';
        echo self::escape(self::translate('Cancel / Reset Run'));
        echo '</button></p>';
        echo '</form>';
    }

    /**
     * @param array{id: int, keyword: string, url: string}|null $edit_mapping
     */
    private function render_form(?array $edit_mapping): void
    {
        $is_edit = is_array($edit_mapping);
        $mapping_id = $is_edit ? (int) ($edit_mapping['id'] ?? 0) : 0;
        $keyword = $is_edit ? (string) ($edit_mapping['keyword'] ?? '') : '';
        $url = $is_edit ? (string) ($edit_mapping['url'] ?? '') : '';
        $form_heading = $is_edit ? self::translate('Edit Mapping') : self::translate('Add Mapping');
        $submit_label = $is_edit ? self::translate('Update Mapping') : self::translate('Add Mapping');

        echo '<h2>' . self::escape($form_heading) . '</h2>';
        echo '<form method="post" action="' . self::escape_url($this->admin_post_url()) . '">';
        echo '<input type="hidden" name="action" value="' . self::escape_attr(self::SAVE_ACTION) . '">';
        echo self::nonce_field(self::SAVE_NONCE_ACTION);

        if ($is_edit) {
            echo '<input type="hidden" name="mapping_id" value="' . self::escape_attr((string) $mapping_id) . '">';
        }

        echo '<table class="form-table" role="presentation">';
        echo '<tr>';
        echo '<th scope="row"><label for="clicklink-keyword">' . self::escape(self::translate('Keyword')) . '</label></th>';
        echo '<td><input id="clicklink-keyword" name="keyword" type="text" class="regular-text" required value="' . self::escape_attr($keyword) . '"></td>';
        echo '</tr>';
        echo '<tr>';
        echo '<th scope="row"><label for="clicklink-url">' . self::escape(self::translate('URL')) . '</label></th>';
        echo '<td><input id="clicklink-url" name="url" type="url" class="regular-text code" required value="' . self::escape_attr($url) . '"></td>';
        echo '</tr>';
        echo '</table>';

        echo '<p class="submit">';
        echo '<button type="submit" class="button button-primary">' . self::escape($submit_label) . '</button>';

        if ($is_edit) {
            echo ' <a class="button button-secondary" href="' . self::escape_url($this->admin_page_url()) . '">' . self::escape(self::translate('Cancel')) . '</a>';
        }

        echo '</p>';
        echo '</form>';
    }

    private function render_mappings_table(): void
    {
        $mappings = $this->fetch_mappings();

        echo '<hr />';
        echo '<h2>' . self::escape(self::translate('Keyword Mappings')) . '</h2>';

        if ($mappings === array()) {
            echo '<p>' . self::escape(self::translate('No mappings yet. Add your first keyword and URL above.')) . '</p>';
            return;
        }

        echo '<table class="widefat striped">';
        echo '<thead><tr>';
        echo '<th>' . self::escape(self::translate('Keyword')) . '</th>';
        echo '<th>' . self::escape(self::translate('URL')) . '</th>';
        echo '<th>' . self::escape(self::translate('Actions')) . '</th>';
        echo '</tr></thead>';
        echo '<tbody>';

        foreach ($mappings as $mapping) {
            $id = (int) ($mapping['id'] ?? 0);
            $keyword = (string) ($mapping['keyword'] ?? '');
            $url = (string) ($mapping['url'] ?? '');
            $edit_url = self::add_query_args(
                array(
                    'action' => 'edit',
                    'mapping_id' => (string) $id,
                ),
                $this->admin_page_url()
            );

            echo '<tr>';
            echo '<td><code>' . self::escape($keyword) . '</code></td>';
            echo '<td><a href="' . self::escape_url($url) . '" target="_blank" rel="noopener noreferrer">' . self::escape($url) . '</a></td>';
            echo '<td>';
            echo '<a class="button button-small" href="' . self::escape_url($edit_url) . '">' . self::escape(self::translate('Edit')) . '</a> ';
            echo '<form method="post" action="' . self::escape_url($this->admin_post_url()) . '" style="display:inline-block; margin:0;">';
            echo '<input type="hidden" name="action" value="' . self::escape_attr(self::DELETE_ACTION) . '">';
            echo '<input type="hidden" name="mapping_id" value="' . self::escape_attr((string) $id) . '">';
            echo self::nonce_field(self::DELETE_NONCE_ACTION);
            echo '<button type="submit" class="button button-small button-link-delete">' . self::escape(self::translate('Delete')) . '</button>';
            echo '</form>';
            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody>';
        echo '</table>';
    }

    private function render_notice(): void
    {
        $notice_code = self::request_get(self::NOTICE_QUERY_KEY);

        if ($notice_code === '') {
            return;
        }

        $notice_map = array(
            'created' => array('class' => 'notice-success', 'message' => self::translate('Mapping added.')),
            'updated' => array('class' => 'notice-success', 'message' => self::translate('Mapping updated.')),
            'deleted' => array('class' => 'notice-success', 'message' => self::translate('Mapping deleted.')),
            'scan_started' => array('class' => 'notice-success', 'message' => self::translate('Manual backfill run initialized.')),
            'scan_batch_processed' => array('class' => 'notice-success', 'message' => self::translate('Processed one backfill batch. Continue until completion.')),
            'scan_completed' => array('class' => 'notice-success', 'message' => self::translate('Manual backfill run completed.')),
            'scan_reset' => array('class' => 'notice-success', 'message' => self::translate('Manual backfill run has been reset to pending state.')),
            'scan_already_running' => array('class' => 'notice-warning', 'message' => self::translate('A manual backfill run is already in progress.')),
            'scan_not_running' => array('class' => 'notice-warning', 'message' => self::translate('Start a run before processing the next batch.')),
            'scan_no_posts' => array('class' => 'notice-info', 'message' => self::translate('No published blog posts are currently eligible for backfill.')),
            'scan_error' => array('class' => 'notice-error', 'message' => self::translate('Backfill run is in an error state. Reset the run before retrying.')),
            'scan_start_failed' => array('class' => 'notice-error', 'message' => self::translate('Unable to start manual backfill run. Please try again.')),
            'scan_next_failed' => array('class' => 'notice-error', 'message' => self::translate('Unable to process the next batch. Please review scanner status and retry.')),
            'scan_reset_failed' => array('class' => 'notice-error', 'message' => self::translate('Unable to reset manual backfill state. Please try again.')),
            'invalid_input' => array('class' => 'notice-error', 'message' => self::translate('Keyword and a valid URL are required.')),
            'invalid_nonce' => array('class' => 'notice-error', 'message' => self::translate('Security check failed. Please try again.')),
            'not_found' => array('class' => 'notice-error', 'message' => self::translate('The selected mapping no longer exists.')),
            'db_error' => array('class' => 'notice-error', 'message' => self::translate('Unable to persist mapping changes. Please try again.')),
        );

        if (! isset($notice_map[$notice_code])) {
            return;
        }

        $notice = $notice_map[$notice_code];
        $notice_class = (string) ($notice['class'] ?? 'notice-info');
        $notice_message = (string) ($notice['message'] ?? '');

        echo '<div class="notice ' . self::escape_attr($notice_class) . '"><p>' . self::escape($notice_message) . '</p></div>';
    }

    /**
     * @return array<int, array{id: int, keyword: string, url: string, created_at: string, updated_at: string}>
     */
    private function fetch_mappings(): array
    {
        return $this->mapping_repository->fetch_mappings();
    }

    /**
     * @return array{id: int, keyword: string, url: string}|null
     */
    private function get_edit_mapping(): ?array
    {
        if (self::request_get('action') !== 'edit') {
            return null;
        }

        $mapping_id = self::positive_int(self::request_get('mapping_id'));

        if ($mapping_id <= 0) {
            return null;
        }

        return $this->mapping_repository->fetch_mapping_by_id($mapping_id);
    }

    private function mapping_exists(int $mapping_id): bool
    {
        return $this->mapping_repository->mapping_exists($mapping_id);
    }

    private function insert_mapping(string $keyword, string $url): bool
    {
        global $wpdb;

        if (! is_object($wpdb) || ! method_exists($wpdb, 'insert')) {
            return false;
        }

        $current_datetime = self::current_datetime();

        $result = $wpdb->insert(
            Installer::table_name(),
            array(
                'keyword' => $keyword,
                'url' => $url,
                'created_at' => $current_datetime,
                'updated_at' => $current_datetime,
            ),
            array('%s', '%s', '%s', '%s')
        );

        if ($result === false) {
            return false;
        }

        $this->mapping_repository->invalidate_grouped_cache();

        return true;
    }

    private function update_mapping(int $mapping_id, string $keyword, string $url): bool
    {
        global $wpdb;

        if (! is_object($wpdb) || ! method_exists($wpdb, 'update')) {
            return false;
        }

        $result = $wpdb->update(
            Installer::table_name(),
            array(
                'keyword' => $keyword,
                'url' => $url,
                'updated_at' => self::current_datetime(),
            ),
            array(
                'id' => $mapping_id,
            ),
            array('%s', '%s', '%s'),
            array('%d')
        );

        if ($result === false) {
            return false;
        }

        $this->mapping_repository->invalidate_grouped_cache();

        return true;
    }

    private function delete_mapping(int $mapping_id): bool
    {
        global $wpdb;

        if (! is_object($wpdb) || ! method_exists($wpdb, 'delete')) {
            return false;
        }

        $result = $wpdb->delete(
            Installer::table_name(),
            array(
                'id' => $mapping_id,
            ),
            array('%d')
        );

        if ($result === false) {
            return false;
        }

        $this->mapping_repository->invalidate_grouped_cache();

        return true;
    }

    private static function current_datetime(): string
    {
        if (function_exists('current_time')) {
            $current_time = current_time('mysql', true);

            if (is_string($current_time) && $current_time !== '') {
                return $current_time;
            }
        }

        return gmdate('Y-m-d H:i:s');
    }

    private function verify_nonce(string $action): bool
    {
        if (! function_exists('wp_verify_nonce')) {
            return true;
        }

        $nonce = self::request_post('_wpnonce');

        if ($nonce === '') {
            return false;
        }

        return wp_verify_nonce($nonce, $action) !== false;
    }

    private function redirect_with_notice(string $notice_code): void
    {
        $redirect_url = self::add_query_args(
            array(self::NOTICE_QUERY_KEY => $notice_code),
            $this->admin_page_url()
        );

        if (function_exists('wp_safe_redirect')) {
            wp_safe_redirect($redirect_url);
            return;
        }

        if (! headers_sent()) {
            header('Location: ' . $redirect_url, true, 302);
        }
    }

    private function admin_page_url(): string
    {
        $relative_path = 'admin.php?page=' . self::MENU_SLUG;

        if (function_exists('admin_url')) {
            return (string) admin_url($relative_path);
        }

        return $relative_path;
    }

    private function admin_post_url(): string
    {
        if (function_exists('admin_url')) {
            return (string) admin_url('admin-post.php');
        }

        return 'admin-post.php';
    }

    private function deny_access(): void
    {
        if (function_exists('wp_die')) {
            wp_die(self::escape('You do not have permission to access this page.'));
        }
    }

    private function backfill_scanner(): Backfill_Scanner
    {
        if ($this->backfill_scanner === null) {
            $this->backfill_scanner = new Backfill_Scanner();
        }

        return $this->backfill_scanner;
    }

    private static function can_manage(): bool
    {
        return function_exists('current_user_can') && current_user_can(self::CAPABILITY);
    }

    /**
     * @param array<string, string> $args
     */
    private static function add_query_args(array $args, string $url): string
    {
        if (function_exists('add_query_arg')) {
            $query_url = add_query_arg($args, $url);

            if (is_string($query_url)) {
                return $query_url;
            }
        }

        $query = http_build_query($args);

        if ($query === '') {
            return $url;
        }

        $separator = str_contains($url, '?') ? '&' : '?';

        return $url . $separator . $query;
    }

    private static function nonce_field(string $action): string
    {
        if (function_exists('wp_nonce_field')) {
            $field = wp_nonce_field($action, '_wpnonce', false, false);

            if (is_string($field)) {
                return $field;
            }
        }

        $nonce = '';

        if (function_exists('wp_create_nonce')) {
            $nonce = (string) wp_create_nonce($action);
        }

        return '<input type="hidden" name="_wpnonce" value="' . self::escape_attr($nonce) . '">';
    }

    private static function request_post(string $key): string
    {
        if (! isset($_POST[$key]) || ! is_scalar($_POST[$key])) {
            return '';
        }

        return self::unslash((string) $_POST[$key]);
    }

    private static function request_get(string $key): string
    {
        if (! isset($_GET[$key]) || ! is_scalar($_GET[$key])) {
            return '';
        }

        return self::unslash((string) $_GET[$key]);
    }

    private static function unslash(string $value): string
    {
        if (function_exists('wp_unslash')) {
            return (string) wp_unslash($value);
        }

        return stripslashes($value);
    }

    private static function positive_int(string $value): int
    {
        $validated = filter_var($value, FILTER_VALIDATE_INT, array('options' => array('min_range' => 1)));

        if ($validated === false) {
            return 0;
        }

        return (int) $validated;
    }

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

    private static function scalar_string($value): string
    {
        if (! is_scalar($value)) {
            return '';
        }

        return trim((string) $value);
    }

    private static function format_number(int $value): string
    {
        if (function_exists('number_format_i18n')) {
            return number_format_i18n($value);
        }

        return number_format($value);
    }

    private function status_label(string $status): string
    {
        $labels = array(
            'pending' => self::translate('Pending'),
            'running' => self::translate('Running'),
            'completed' => self::translate('Completed'),
            'error' => self::translate('Error'),
        );

        if (! isset($labels[$status])) {
            return self::translate('Pending');
        }

        return $labels[$status];
    }

    private function display_timestamp(string $timestamp): string
    {
        if ($timestamp === '') {
            return self::translate('Not set');
        }

        return $timestamp;
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

    private static function escape_attr(string $value): string
    {
        if (function_exists('esc_attr')) {
            return esc_attr($value);
        }

        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    private static function escape_url(string $value): string
    {
        if (function_exists('esc_url')) {
            return esc_url($value);
        }

        return filter_var($value, FILTER_SANITIZE_URL) ?: '';
    }
}
