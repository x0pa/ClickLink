<?php

declare(strict_types=1);

namespace ClickLink\Admin;

use ClickLink\Backfill_Scanner;
use ClickLink\Installer;
use ClickLink\Keyword_Mapping_Repository;
use ClickLink\Linker_Stats;
use ClickLink\Runtime;
use RuntimeException;

final class Admin_Page
{
    private const CAPABILITY = 'manage_options';
    public const MENU_SLUG = 'clicklink';
    private const SAVE_ACTION = 'clicklink_save_mapping';
    private const DELETE_ACTION = 'clicklink_delete_mapping';
    private const BULK_DELETE_ACTION = 'clicklink_bulk_delete_mappings';
    private const START_SCAN_ACTION = 'clicklink_backfill_start';
    private const NEXT_BATCH_ACTION = 'clicklink_backfill_next_batch';
    private const RESET_SCAN_ACTION = 'clicklink_backfill_reset';
    private const RESET_OPERATIONAL_STATE_ACTION = 'clicklink_reset_operational_state';
    private const NOTICE_QUERY_KEY = 'clicklink_notice';
    private const BULK_DELETED_COUNT_QUERY_KEY = 'clicklink_deleted_count';
    private const RESET_DELETED_MAPPINGS_COUNT_QUERY_KEY = 'clicklink_reset_deleted_mappings';
    private const RESET_INCLUDE_MAPPINGS_KEY = 'clicklink_reset_include_mappings';
    private const MAPPINGS_SEARCH_QUERY_KEY = 'clicklink_mappings_search';
    private const MAPPINGS_KEYWORD_FILTER_QUERY_KEY = 'clicklink_mappings_keyword';
    private const MAPPINGS_SORT_QUERY_KEY = 'clicklink_mappings_sort';
    private const MAPPINGS_ORDER_QUERY_KEY = 'clicklink_mappings_order';
    private const MAPPINGS_PAGE_QUERY_KEY = 'clicklink_mappings_page';
    private const MAPPINGS_PER_PAGE_QUERY_KEY = 'clicklink_mappings_per_page';
    private const DEFAULT_MAPPINGS_PER_PAGE = 20;
    private const MAX_MAPPINGS_PER_PAGE = 200;
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
        $this->register_action_handlers(
            'admin_post_',
            array(
                self::SAVE_ACTION => 'handle_save_mapping',
                self::DELETE_ACTION => 'handle_delete_mapping',
                self::BULK_DELETE_ACTION => 'handle_bulk_delete_mappings',
                self::START_SCAN_ACTION => 'handle_start_scan',
                self::NEXT_BATCH_ACTION => 'handle_next_batch',
                self::RESET_SCAN_ACTION => 'handle_reset_scan',
                self::RESET_OPERATIONAL_STATE_ACTION => 'handle_reset_operational_state',
            )
        );
        $this->register_action_handlers(
            'wp_ajax_',
            array(
                self::START_SCAN_ACTION => 'handle_start_scan_ajax',
                self::NEXT_BATCH_ACTION => 'handle_next_batch_ajax',
                self::RESET_SCAN_ACTION => 'handle_reset_scan_ajax',
            )
        );
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
        $this->render_operational_reset_panel();
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

        if (! $this->verify_nonce(self::SAVE_ACTION)) {
            $this->redirect_with_notice('invalid_nonce');
            return;
        }

        $mapping_id = Runtime::positive_int(self::request_post('mapping_id'));
        $raw_keyword = self::request_post('keyword');
        $raw_url = self::request_post('url');
        $keyword = Keyword_Mapping_Repository::normalize_keyword_for_storage($raw_keyword);
        $url = Keyword_Mapping_Repository::sanitize_url($raw_url);

        if ($keyword === '') {
            $this->redirect_with_notice('keyword_required');
            return;
        }

        if (trim($raw_url) === '') {
            $this->redirect_with_notice('url_required');
            return;
        }

        if ($url === '') {
            $this->redirect_with_notice('invalid_url');
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

        if (! $this->verify_nonce(self::DELETE_ACTION)) {
            $this->redirect_with_notice('invalid_nonce');
            return;
        }

        $mapping_id = Runtime::positive_int(self::request_post('mapping_id'));

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

    public function handle_bulk_delete_mappings(): void
    {
        if (! self::can_manage()) {
            $this->deny_access();
            return;
        }

        if (! $this->verify_nonce(self::BULK_DELETE_ACTION)) {
            $this->redirect_with_notice('invalid_nonce', $this->mapping_table_query_args_from_post());
            return;
        }

        if (self::request_post('bulk_action') !== 'delete') {
            $this->redirect_with_notice('bulk_action_required', $this->mapping_table_query_args_from_post());
            return;
        }

        $mapping_ids = self::request_post_id_list('mapping_ids');

        if ($mapping_ids === array()) {
            $this->redirect_with_notice('bulk_selection_required', $this->mapping_table_query_args_from_post());
            return;
        }

        $deleted_count = 0;

        foreach ($mapping_ids as $mapping_id) {
            if (! $this->mapping_exists($mapping_id)) {
                continue;
            }

            if (! $this->delete_mapping($mapping_id)) {
                $this->redirect_with_notice('db_error', $this->mapping_table_query_args_from_post());
                return;
            }

            $deleted_count++;
        }

        if ($deleted_count <= 0) {
            $this->redirect_with_notice('not_found', $this->mapping_table_query_args_from_post());
            return;
        }

        $redirect_args = $this->mapping_table_query_args_from_post();
        $redirect_args[self::BULK_DELETED_COUNT_QUERY_KEY] = (string) $deleted_count;
        $this->redirect_with_notice('bulk_deleted', $redirect_args);
    }

    public function handle_start_scan(): void
    {
        if (! self::can_manage()) {
            $this->deny_access();
            return;
        }

        if (! $this->verify_nonce(self::START_SCAN_ACTION)) {
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

        if (! $this->verify_nonce(self::NEXT_BATCH_ACTION)) {
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

        if (! $this->verify_nonce(self::RESET_SCAN_ACTION)) {
            $this->redirect_with_notice('invalid_nonce');
            return;
        }

        $this->redirect_with_notice($this->reset_scan_notice_code());
    }

    public function handle_reset_operational_state(): void
    {
        if (! self::can_manage()) {
            $this->deny_access();
            return;
        }

        if (! $this->verify_nonce(self::RESET_OPERATIONAL_STATE_ACTION)) {
            $this->redirect_with_notice('invalid_nonce');
            return;
        }

        $include_mapping_reset = self::request_post(self::RESET_INCLUDE_MAPPINGS_KEY) === '1';
        $stats_reset = $this->reset_stats_state();
        $scan_reset = $this->reset_scan_notice_code() === 'scan_reset';
        $deleted_mapping_count = 0;
        $mapping_reset = true;

        if ($include_mapping_reset) {
            $deleted = $this->mapping_repository->delete_all_mappings();

            if ($deleted === false) {
                $mapping_reset = false;
            } else {
                $deleted_mapping_count = max(0, (int) $deleted);
            }
        }

        if (! $stats_reset || ! $scan_reset || ! $mapping_reset) {
            $this->redirect_with_notice('operational_reset_failed');
            return;
        }

        if (! $include_mapping_reset) {
            $this->redirect_with_notice('operational_reset');
            return;
        }

        $this->redirect_with_notice(
            'operational_reset_with_mappings',
            array(
                self::RESET_DELETED_MAPPINGS_COUNT_QUERY_KEY => (string) $deleted_mapping_count,
            )
        );
    }

    public function handle_start_scan_ajax(): void
    {
        $this->handle_scan_ajax_request(
            self::START_SCAN_ACTION,
            static fn (self $page): string => $page->start_scan_notice_code()
        );
    }

    public function handle_next_batch_ajax(): void
    {
        $this->handle_scan_ajax_request(
            self::NEXT_BATCH_ACTION,
            static fn (self $page): string => $page->next_batch_notice_code()
        );
    }

    public function handle_reset_scan_ajax(): void
    {
        $this->handle_scan_ajax_request(
            self::RESET_SCAN_ACTION,
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

        $requested_batch_size = Runtime::positive_int(self::request_post('batch_size'));
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
        $status = Runtime::scalar_string($state['status'] ?? '');

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
        $processed_status = Runtime::scalar_string($processed_state['status'] ?? '');

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
        $status = Runtime::scalar_string($state['status'] ?? 'pending');
        $processed_posts = Runtime::non_negative_int($state['processed_posts'] ?? 0);
        $changed_posts = Runtime::non_negative_int($state['changed_posts'] ?? 0);
        $inserted_links = Runtime::non_negative_int($state['inserted_links'] ?? 0);
        $batch_size = max(1, Runtime::non_negative_int($state['batch_size'] ?? 0));
        $state_total_eligible_posts = Runtime::non_negative_int($state['total_eligible_posts'] ?? 0);
        $current_eligible_posts = max(0, $scanner->current_eligible_posts());
        $started_at = Runtime::scalar_string($state['started_at'] ?? '');
        $completed_at = Runtime::scalar_string($state['completed_at'] ?? '');
        $last_error = Runtime::scalar_string($state['last_error'] ?? '');

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
        echo self::nonce_field(self::START_SCAN_ACTION);
        echo '<p class="submit"><button type="submit" class="button button-primary"' . $start_button_disabled_attr . '>';
        echo self::escape(self::translate('Run Now'));
        echo '</button></p>';
        echo '</form>';

        echo '<form method="post" action="' . self::escape_url($this->admin_post_url()) . '">';
        echo '<input type="hidden" name="action" value="' . self::escape_attr(self::NEXT_BATCH_ACTION) . '">';
        echo self::nonce_field(self::NEXT_BATCH_ACTION);
        echo '<p class="submit"><button type="submit" class="button button-secondary"' . $next_batch_button_disabled_attr . '>';
        echo self::escape(self::translate('Process Next Batch'));
        echo '</button></p>';
        echo '</form>';

        echo '<form method="post" action="' . self::escape_url($this->admin_post_url()) . '">';
        echo '<input type="hidden" name="action" value="' . self::escape_attr(self::RESET_SCAN_ACTION) . '">';
        echo self::nonce_field(self::RESET_SCAN_ACTION);
        echo '<p class="submit"><button type="submit" class="button button-secondary"' . $reset_button_disabled_attr . '>';
        echo self::escape(self::translate('Cancel / Reset Run'));
        echo '</button></p>';
        echo '</form>';
    }

    private function render_operational_reset_panel(): void
    {
        echo '<hr />';
        echo '<h2>' . self::escape(self::translate('Operational Reset')) . '</h2>';
        echo '<p>' . self::escape(self::translate('Reset accumulated stats and manual backfill state when diagnosing unexpected behavior. Mappings stay intact unless explicitly selected below.')) . '</p>';
        echo '<form method="post" action="' . self::escape_url($this->admin_post_url()) . '">';
        echo '<input type="hidden" name="action" value="' . self::escape_attr(self::RESET_OPERATIONAL_STATE_ACTION) . '">';
        echo self::nonce_field(self::RESET_OPERATIONAL_STATE_ACTION);
        echo '<p><label>';
        echo '<input type="checkbox" name="' . self::escape_attr(self::RESET_INCLUDE_MAPPINGS_KEY) . '" value="1"> ';
        echo self::escape(self::translate('Also delete all keyword mappings (cannot be undone).'));
        echo '</label></p>';
        echo '<p class="submit"><button type="submit" class="button button-secondary">';
        echo self::escape(self::translate('Reset Stats and Backfill State'));
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
        echo self::nonce_field(self::SAVE_ACTION);

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
        $query_args = $this->mapping_table_query_args_from_get();
        $paged_data = $this->fetch_mappings_page($query_args);
        $mappings = $paged_data['rows'];
        $total_rows = Runtime::non_negative_int($paged_data['total'] ?? 0);
        $current_page = max(1, Runtime::positive_int((string) ($paged_data['page'] ?? '1')));
        $per_page = max(1, Runtime::positive_int((string) ($paged_data['per_page'] ?? (string) self::DEFAULT_MAPPINGS_PER_PAGE)));
        $total_pages = max(1, Runtime::non_negative_int($paged_data['total_pages'] ?? 1));
        $filters_active = ($query_args['search'] !== '') || ($query_args['keyword_filter'] !== '');

        echo '<hr />';
        echo '<h2>' . self::escape(self::translate('Keyword Mappings')) . '</h2>';
        echo '<p>' . self::escape(self::translate('Search, filter, sort, and bulk-manage mappings while preserving duplicate keyword rows.')) . '</p>';
        $this->render_mappings_filter_form($query_args);

        if ($mappings === array() && $total_rows <= 0 && ! $filters_active) {
            echo '<p>' . self::escape(self::translate('No mappings yet. Add your first keyword and URL above.')) . '</p>';
            return;
        }

        echo '<form method="post" action="' . self::escape_url($this->admin_post_url()) . '">';
        echo '<input type="hidden" name="action" value="' . self::escape_attr(self::BULK_DELETE_ACTION) . '">';
        echo self::nonce_field(self::BULK_DELETE_ACTION);
        $this->render_mapping_query_hidden_inputs($query_args);
        $this->render_bulk_actions_controls();

        if ($mappings === array()) {
            echo '<p>' . self::escape(self::translate('No mappings matched your current search/filter settings.')) . '</p>';
            echo '</form>';
            return;
        }

        echo '<table class="widefat striped">';
        echo '<thead><tr>';
        echo '<th style="width: 32px;"><input type="checkbox" id="clicklink-select-all-mappings" aria-label="';
        echo self::escape_attr(self::translate('Select all mappings on this page'));
        echo '" onclick="var boxes=document.querySelectorAll(\'.clicklink-mapping-select\');for(var i=0;i<boxes.length;i++){boxes[i].checked=this.checked;}"></th>';
        echo '<th>' . $this->render_sort_link($query_args, self::translate('Keyword'), 'keyword') . '</th>';
        echo '<th>' . $this->render_sort_link($query_args, self::translate('URL'), 'url') . '</th>';
        echo '<th>' . $this->render_sort_link($query_args, self::translate('Updated'), 'updated_at') . '</th>';
        echo '<th>' . self::escape(self::translate('Actions')) . '</th>';
        echo '</tr></thead>';
        echo '<tbody>';

        foreach ($mappings as $mapping) {
            $id = (int) ($mapping['id'] ?? 0);
            $keyword = (string) ($mapping['keyword'] ?? '');
            $url = (string) ($mapping['url'] ?? '');
            $edit_url = self::add_query_args(
                array_merge(
                    $this->mapping_table_query_args_to_request_values($query_args),
                    array(
                        'action' => 'edit',
                        'mapping_id' => (string) $id,
                    )
                ),
                $this->admin_page_url()
            );

            echo '<tr>';
            echo '<td><input class="clicklink-mapping-select" type="checkbox" name="mapping_ids[]" value="' . self::escape_attr((string) $id) . '"></td>';
            echo '<td><code>' . self::escape($keyword) . '</code></td>';
            echo '<td><a href="' . self::escape_url($url) . '" target="_blank" rel="noopener noreferrer">' . self::escape($url) . '</a></td>';
            echo '<td>' . self::escape($this->display_timestamp((string) ($mapping['updated_at'] ?? ''))) . '</td>';
            echo '<td>';
            echo '<a class="button button-small" href="' . self::escape_url($edit_url) . '">' . self::escape(self::translate('Edit')) . '</a>';
            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody>';
        echo '</table>';
        echo '</form>';
        $this->render_mappings_pagination($query_args, $current_page, $per_page, $total_pages, $total_rows, count($mappings));
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
            'operational_reset' => array('class' => 'notice-success', 'message' => self::translate('Operational reset completed: stats and backfill state were reset.')),
            'operational_reset_with_mappings' => array('class' => 'notice-success', 'message' => $this->operational_reset_with_mappings_notice_message()),
            'scan_already_running' => array('class' => 'notice-warning', 'message' => self::translate('A manual backfill run is already in progress.')),
            'scan_not_running' => array('class' => 'notice-warning', 'message' => self::translate('Start a run before processing the next batch.')),
            'scan_no_posts' => array('class' => 'notice-info', 'message' => self::translate('No published blog posts are currently eligible for backfill.')),
            'scan_error' => array('class' => 'notice-error', 'message' => self::translate('Backfill run is in an error state. Reset the run before retrying.')),
            'scan_start_failed' => array('class' => 'notice-error', 'message' => self::translate('Unable to start manual backfill run. Please try again.')),
            'scan_next_failed' => array('class' => 'notice-error', 'message' => self::translate('Unable to process the next batch. Please review scanner status and retry.')),
            'scan_reset_failed' => array('class' => 'notice-error', 'message' => self::translate('Unable to reset manual backfill state. Please try again.')),
            'operational_reset_failed' => array('class' => 'notice-error', 'message' => self::translate('Unable to complete operational reset. Please try again.')),
            'bulk_deleted' => array('class' => 'notice-success', 'message' => $this->bulk_deleted_notice_message()),
            'bulk_action_required' => array('class' => 'notice-error', 'message' => self::translate('Choose a bulk action before applying changes.')),
            'bulk_selection_required' => array('class' => 'notice-error', 'message' => self::translate('Select at least one mapping row to delete.')),
            'keyword_required' => array('class' => 'notice-error', 'message' => self::translate('Keyword is required.')),
            'url_required' => array('class' => 'notice-error', 'message' => self::translate('URL is required.')),
            'invalid_url' => array('class' => 'notice-error', 'message' => self::translate('Please enter a valid URL, including protocol (for example, https://example.com).')),
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
     * @param array{
     *   search: string,
     *   keyword_filter: string,
     *   sort_by: string,
     *   sort_direction: string,
     *   page: int,
     *   per_page: int
     * } $query_args
     * @return array{
     *   rows: array<int, array{id: int, keyword: string, url: string, created_at: string, updated_at: string}>,
     *   total: int,
     *   page: int,
     *   per_page: int,
     *   total_pages: int
     * }
     */
    private function fetch_mappings_page(array $query_args): array
    {
        return $this->mapping_repository->fetch_mappings_page($query_args);
    }

    private function render_mappings_filter_form(array $query_args): void
    {
        $search = (string) ($query_args['search'] ?? '');
        $keyword_filter = (string) ($query_args['keyword_filter'] ?? '');
        $sort_by = (string) ($query_args['sort_by'] ?? 'keyword');
        $sort_direction = (string) ($query_args['sort_direction'] ?? 'asc');
        $per_page = Runtime::positive_int((string) ($query_args['per_page'] ?? self::DEFAULT_MAPPINGS_PER_PAGE));
        $per_page_options = array(20, 50, 100, 200);

        if (! in_array($per_page, $per_page_options, true)) {
            $per_page_options[] = max(1, min(self::MAX_MAPPINGS_PER_PAGE, $per_page));
        }

        sort($per_page_options, SORT_NUMERIC);

        echo '<form method="get" action="' . self::escape_url($this->admin_page_url()) . '">';
        echo '<input type="hidden" name="' . self::escape_attr(self::MAPPINGS_SORT_QUERY_KEY) . '" value="' . self::escape_attr($sort_by) . '">';
        echo '<input type="hidden" name="' . self::escape_attr(self::MAPPINGS_ORDER_QUERY_KEY) . '" value="' . self::escape_attr($sort_direction) . '">';
        echo '<input type="hidden" name="' . self::escape_attr(self::MAPPINGS_PAGE_QUERY_KEY) . '" value="1">';
        echo '<p>';
        echo '<label for="clicklink-mappings-search">' . self::escape(self::translate('Search')) . '</label> ';
        echo '<input id="clicklink-mappings-search" name="' . self::escape_attr(self::MAPPINGS_SEARCH_QUERY_KEY) . '" type="search" class="regular-text" value="' . self::escape_attr($search) . '"> ';
        echo '<label for="clicklink-mappings-keyword">' . self::escape(self::translate('Keyword filter')) . '</label> ';
        echo '<input id="clicklink-mappings-keyword" name="' . self::escape_attr(self::MAPPINGS_KEYWORD_FILTER_QUERY_KEY) . '" type="text" class="regular-text" value="' . self::escape_attr($keyword_filter) . '"> ';
        echo '<label for="clicklink-mappings-per-page">' . self::escape(self::translate('Rows per page')) . '</label> ';
        echo '<select id="clicklink-mappings-per-page" name="' . self::escape_attr(self::MAPPINGS_PER_PAGE_QUERY_KEY) . '">';

        foreach ($per_page_options as $option) {
            $selected = $option === $per_page ? ' selected' : '';
            echo '<option value="' . self::escape_attr((string) $option) . '"' . $selected . '>' . self::escape(self::format_number($option)) . '</option>';
        }

        echo '</select> ';
        echo '<button type="submit" class="button">' . self::escape(self::translate('Apply')) . '</button> ';
        echo '<a class="button button-secondary" href="' . self::escape_url($this->admin_page_url()) . '">' . self::escape(self::translate('Reset')) . '</a>';
        echo '</p>';
        echo '</form>';
    }

    private function render_bulk_actions_controls(): void
    {
        echo '<p>';
        echo '<label for="clicklink-bulk-action" class="screen-reader-text">' . self::escape(self::translate('Bulk actions')) . '</label>';
        echo '<select id="clicklink-bulk-action" name="bulk_action">';
        echo '<option value="">' . self::escape(self::translate('Bulk actions')) . '</option>';
        echo '<option value="delete">' . self::escape(self::translate('Delete selected')) . '</option>';
        echo '</select> ';
        echo '<button type="submit" class="button action">' . self::escape(self::translate('Apply')) . '</button>';
        echo '</p>';
    }

    private function render_mappings_pagination(
        array $query_args,
        int $current_page,
        int $per_page,
        int $total_pages,
        int $total_rows,
        int $rows_on_page
    ): void {
        $start_index = $total_rows > 0 ? (($current_page - 1) * $per_page) + 1 : 0;
        $end_index = $total_rows > 0 ? ($start_index + $rows_on_page - 1) : 0;

        echo '<p class="description">';
        echo self::escape(
            sprintf(
                (string) self::translate('Showing %1$s-%2$s of %3$s mappings.'),
                self::format_number($start_index),
                self::format_number($end_index),
                self::format_number($total_rows)
            )
        );
        echo '</p>';

        if ($total_pages <= 1) {
            return;
        }

        $previous_link = '';
        $next_link = '';

        if ($current_page > 1) {
            $previous_link = $this->mapping_table_url(
                array_merge(
                    $query_args,
                    array(
                        'page' => $current_page - 1,
                    )
                )
            );
        }

        if ($current_page < $total_pages) {
            $next_link = $this->mapping_table_url(
                array_merge(
                    $query_args,
                    array(
                        'page' => $current_page + 1,
                    )
                )
            );
        }

        echo '<p>';

        if ($previous_link !== '') {
            echo '<a class="button" href="' . self::escape_url($previous_link) . '">' . self::escape(self::translate('Previous page')) . '</a> ';
        } else {
            echo '<span class="button disabled" aria-disabled="true">' . self::escape(self::translate('Previous page')) . '</span> ';
        }

        echo '<span>' . self::escape(
            sprintf(
                (string) self::translate('Page %1$s of %2$s'),
                self::format_number($current_page),
                self::format_number($total_pages)
            )
        ) . '</span> ';

        if ($next_link !== '') {
            echo '<a class="button" href="' . self::escape_url($next_link) . '">' . self::escape(self::translate('Next page')) . '</a>';
        } else {
            echo '<span class="button disabled" aria-disabled="true">' . self::escape(self::translate('Next page')) . '</span>';
        }

        echo '</p>';
    }

    private function render_sort_link(array $query_args, string $label, string $sort_by): string
    {
        $active_sort_by = (string) ($query_args['sort_by'] ?? 'keyword');
        $active_sort_direction = (string) ($query_args['sort_direction'] ?? 'asc');
        $is_active = $active_sort_by === $sort_by;
        $next_direction = 'asc';
        $indicator = '';

        if ($is_active) {
            $next_direction = $active_sort_direction === 'asc' ? 'desc' : 'asc';
            $indicator = $active_sort_direction === 'asc' ? ' ↑' : ' ↓';
        }

        $url = $this->mapping_table_url(
            array_merge(
                $query_args,
                array(
                    'sort_by' => $sort_by,
                    'sort_direction' => $next_direction,
                    'page' => 1,
                )
            )
        );

        return '<a href="' . self::escape_url($url) . '">' . self::escape($label . $indicator) . '</a>';
    }

    private function render_mapping_query_hidden_inputs(array $query_args): void
    {
        $serialized_args = $this->mapping_table_query_args_to_request_values($query_args);

        foreach ($serialized_args as $key => $value) {
            echo '<input type="hidden" name="' . self::escape_attr($key) . '" value="' . self::escape_attr($value) . '">';
        }
    }

    private function bulk_deleted_notice_message(): string
    {
        $deleted_count = Runtime::non_negative_int(self::request_get(self::BULK_DELETED_COUNT_QUERY_KEY));

        if ($deleted_count <= 0) {
            return self::translate('Selected mappings deleted.');
        }

        return sprintf(
            (string) self::translate('Deleted %s mapping row(s).'),
            self::format_number($deleted_count)
        );
    }

    private function operational_reset_with_mappings_notice_message(): string
    {
        $deleted_count = Runtime::non_negative_int(self::request_get(self::RESET_DELETED_MAPPINGS_COUNT_QUERY_KEY));

        return sprintf(
            (string) self::translate('Operational reset completed: stats and backfill state were reset, and %s mapping row(s) were deleted.'),
            self::format_number($deleted_count)
        );
    }

    private function reset_stats_state(): bool
    {
        $stats = new Linker_Stats();
        $stats->reset_totals();

        return true;
    }

    private function mapping_table_url(array $query_args): string
    {
        return self::add_query_args(
            $this->mapping_table_query_args_to_request_values($query_args),
            $this->admin_page_url()
        );
    }

    /**
     * @return array{
     *   search: string,
     *   keyword_filter: string,
     *   sort_by: string,
     *   sort_direction: string,
     *   page: int,
     *   per_page: int
     * }
     */
    private function mapping_table_query_args_from_get(): array
    {
        return $this->normalize_mapping_table_query_args(
            array(
                'search' => self::request_get(self::MAPPINGS_SEARCH_QUERY_KEY),
                'keyword_filter' => self::request_get(self::MAPPINGS_KEYWORD_FILTER_QUERY_KEY),
                'sort_by' => self::request_get(self::MAPPINGS_SORT_QUERY_KEY),
                'sort_direction' => self::request_get(self::MAPPINGS_ORDER_QUERY_KEY),
                'page' => self::request_get(self::MAPPINGS_PAGE_QUERY_KEY),
                'per_page' => self::request_get(self::MAPPINGS_PER_PAGE_QUERY_KEY),
            )
        );
    }

    /**
     * @return array{
     *   search: string,
     *   keyword_filter: string,
     *   sort_by: string,
     *   sort_direction: string,
     *   page: int,
     *   per_page: int
     * }
     */
    private function mapping_table_query_args_from_post(): array
    {
        return $this->normalize_mapping_table_query_args(
            array(
                'search' => self::request_post(self::MAPPINGS_SEARCH_QUERY_KEY),
                'keyword_filter' => self::request_post(self::MAPPINGS_KEYWORD_FILTER_QUERY_KEY),
                'sort_by' => self::request_post(self::MAPPINGS_SORT_QUERY_KEY),
                'sort_direction' => self::request_post(self::MAPPINGS_ORDER_QUERY_KEY),
                'page' => self::request_post(self::MAPPINGS_PAGE_QUERY_KEY),
                'per_page' => self::request_post(self::MAPPINGS_PER_PAGE_QUERY_KEY),
            )
        );
    }

    /**
     * @param array<string, string> $query_args
     * @return array{
     *   search: string,
     *   keyword_filter: string,
     *   sort_by: string,
     *   sort_direction: string,
     *   page: int,
     *   per_page: int
     * }
     */
    private function normalize_mapping_table_query_args(array $query_args): array
    {
        $search = self::sanitize_search_input((string) ($query_args['search'] ?? ''));
        $keyword_filter = self::sanitize_search_input((string) ($query_args['keyword_filter'] ?? ''));
        $sort_by = self::normalize_sort_by((string) ($query_args['sort_by'] ?? 'keyword'));
        $sort_direction = self::normalize_sort_direction((string) ($query_args['sort_direction'] ?? 'asc'));
        $page = Runtime::positive_int((string) ($query_args['page'] ?? '1'));
        $per_page = Runtime::positive_int((string) ($query_args['per_page'] ?? (string) self::DEFAULT_MAPPINGS_PER_PAGE));

        if ($page <= 0) {
            $page = 1;
        }

        if ($per_page <= 0) {
            $per_page = self::DEFAULT_MAPPINGS_PER_PAGE;
        }

        $per_page = min(self::MAX_MAPPINGS_PER_PAGE, $per_page);

        return array(
            'search' => $search,
            'keyword_filter' => $keyword_filter,
            'sort_by' => $sort_by,
            'sort_direction' => $sort_direction,
            'page' => $page,
            'per_page' => $per_page,
        );
    }

    /**
     * @param array{
     *   search: string,
     *   keyword_filter: string,
     *   sort_by: string,
     *   sort_direction: string,
     *   page: int,
     *   per_page: int
     * } $query_args
     * @return array<string, string>
     */
    private function mapping_table_query_args_to_request_values(array $query_args): array
    {
        $page = Runtime::positive_int((string) ($query_args['page'] ?? '1'));
        $per_page = Runtime::positive_int((string) ($query_args['per_page'] ?? (string) self::DEFAULT_MAPPINGS_PER_PAGE));

        if ($page <= 0) {
            $page = 1;
        }

        if ($per_page <= 0) {
            $per_page = self::DEFAULT_MAPPINGS_PER_PAGE;
        }

        $per_page = min(self::MAX_MAPPINGS_PER_PAGE, $per_page);

        return array(
            self::MAPPINGS_SEARCH_QUERY_KEY => (string) ($query_args['search'] ?? ''),
            self::MAPPINGS_KEYWORD_FILTER_QUERY_KEY => (string) ($query_args['keyword_filter'] ?? ''),
            self::MAPPINGS_SORT_QUERY_KEY => (string) ($query_args['sort_by'] ?? 'keyword'),
            self::MAPPINGS_ORDER_QUERY_KEY => (string) ($query_args['sort_direction'] ?? 'asc'),
            self::MAPPINGS_PAGE_QUERY_KEY => (string) $page,
            self::MAPPINGS_PER_PAGE_QUERY_KEY => (string) $per_page,
        );
    }

    private static function sanitize_search_input(string $value): string
    {
        $value = trim($value);

        if (function_exists('sanitize_text_field')) {
            return sanitize_text_field($value);
        }

        return trim(strip_tags($value));
    }

    private static function normalize_sort_by(string $value): string
    {
        $normalized = strtolower(trim($value));
        $allowed = array('keyword', 'url', 'updated_at', 'created_at', 'id');

        if (! in_array($normalized, $allowed, true)) {
            return 'keyword';
        }

        return $normalized;
    }

    private static function normalize_sort_direction(string $value): string
    {
        return strtolower(trim($value)) === 'desc' ? 'desc' : 'asc';
    }

    /**
     * @return array{id: int, keyword: string, url: string}|null
     */
    private function get_edit_mapping(): ?array
    {
        if (self::request_get('action') !== 'edit') {
            return null;
        }

        $mapping_id = Runtime::positive_int(self::request_get('mapping_id'));

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

        $current_datetime = Runtime::current_datetime_utc();

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
                'updated_at' => Runtime::current_datetime_utc(),
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

    /**
     * @param array<string, string> $additional_query_args
     */
    private function redirect_with_notice(string $notice_code, array $additional_query_args = array()): void
    {
        $redirect_url = self::add_query_args(
            array_merge(
                $additional_query_args,
                array(self::NOTICE_QUERY_KEY => $notice_code)
            ),
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
            return;
        }

        throw new RuntimeException('You do not have permission to access this page.');
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
     * @param array<string, string> $handlers
     */
    private function register_action_handlers(string $hook_prefix, array $handlers): void
    {
        foreach ($handlers as $action => $method) {
            add_action($hook_prefix . $action, array($this, $method));
        }
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

    /**
     * @return array<int, int>
     */
    private static function request_post_id_list(string $key): array
    {
        if (! isset($_POST[$key])) {
            return array();
        }

        $raw_value = $_POST[$key];
        $raw_values = array();

        if (is_array($raw_value)) {
            foreach ($raw_value as $value) {
                if (! is_scalar($value)) {
                    continue;
                }

                $raw_values[] = self::unslash((string) $value);
            }
        } elseif (is_scalar($raw_value)) {
            $raw_values = explode(',', self::unslash((string) $raw_value));
        } else {
            return array();
        }

        $normalized_map = array();

        foreach ($raw_values as $value) {
            $mapping_id = Runtime::positive_int(trim($value));

            if ($mapping_id <= 0) {
                continue;
            }

            $normalized_map[$mapping_id] = $mapping_id;
        }

        if ($normalized_map === array()) {
            return array();
        }

        return array_values($normalized_map);
    }

    private static function unslash(string $value): string
    {
        if (function_exists('wp_unslash')) {
            return (string) wp_unslash($value);
        }

        return stripslashes($value);
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
