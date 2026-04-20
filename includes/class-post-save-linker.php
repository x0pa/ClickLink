<?php

declare(strict_types=1);

namespace ClickLink;

require_once __DIR__ . '/class-runtime.php';

final class Post_Save_Linker
{
    private const CONTENT_HASH_META_KEY = '_clicklink_content_hash';
    private const OPTIONS_OPTION_KEY = 'clicklink_options';
    private const MAX_CONTENT_BYTES = 1500000;
    private const EXCLUDED_CONTEXT_TAGS = array(
        'a' => true,
        'code' => true,
        'pre' => true,
        'script' => true,
        'style' => true,
        'h1' => true,
        'h2' => true,
        'h3' => true,
        'h4' => true,
        'h5' => true,
        'h6' => true,
        'noscript' => true,
        'template' => true,
        'textarea' => true,
        'title' => true,
        'svg' => true,
        'math' => true,
    );
    private const RAW_TEXT_TAGS = array(
        'script' => true,
        'style' => true,
    );

    private Linker_Stats $stats;
    private Keyword_Mapping_Repository $mapping_repository;

    /**
     * @var array<int, bool>
     */
    private array $active_updates = array();

    public function __construct(
        ?Linker_Stats $stats = null,
        ?Keyword_Mapping_Repository $mapping_repository = null
    )
    {
        $this->stats = $stats ?? new Linker_Stats();
        $this->mapping_repository = $mapping_repository ?? new Keyword_Mapping_Repository();
    }

    public function register(): void
    {
        if (! function_exists('add_action')) {
            return;
        }

        add_action('save_post_post', array($this, 'handle_post_save'), 10, 3);
    }

    /**
     * @param mixed $post
     */
    public function handle_post_save(int $post_id, $post, bool $update): void
    {
        $this->process_post($post_id, $post, $update);
    }

    /**
     * @param mixed $post
     * @return array{processed: bool, changed: bool, inserted_links: int, keyword_hits: array<string, int>}
     */
    public function process_post(int $post_id, $post, bool $update = true): array
    {
        $result = array(
            'processed' => false,
            'changed' => false,
            'inserted_links' => 0,
            'keyword_hits' => array(),
        );

        if ($post_id <= 0) {
            return $result;
        }

        if (isset($this->active_updates[$post_id])) {
            return $result;
        }

        if (! is_object($post)) {
            return $result;
        }

        $post_type = isset($post->post_type) && is_string($post->post_type)
            ? $post->post_type
            : '';

        if ($post_type !== 'post') {
            return $result;
        }

        if ($this->is_autosave_or_revision($post_id)) {
            return $result;
        }

        $result['processed'] = true;

        $content = isset($post->post_content) && is_string($post->post_content)
            ? $post->post_content
            : '';
        $current_hash = $this->content_hash($content);

        if ($update && $this->is_unchanged_content_save($post_id, $current_hash)) {
            return $result;
        }

        $mappings = $this->mapping_repository->fetch_grouped_keyword_urls();

        if ($mappings === array()) {
            $this->persist_content_hash($post_id, $current_hash);
            $this->stats->record_save_metrics($post_id, 0);
            return $result;
        }

        $max_links_per_post = $this->max_links_per_post();

        if ($max_links_per_post <= 0) {
            $this->persist_content_hash($post_id, $current_hash);
            $this->stats->record_save_metrics($post_id, 0);
            return $result;
        }

        try {
            $link_result = $this->link_content($post_id, $content, $mappings, $max_links_per_post);
        } catch (\Throwable $throwable) {
            Runtime::debug_log(
                'linker.content_linking_exception',
                array(
                    'post_id' => $post_id,
                    'error' => Runtime::throwable_message($throwable),
                )
            );
            $this->persist_content_hash($post_id, $current_hash);
            $this->stats->record_save_metrics($post_id, 0);
            return $result;
        }
        $linked_content = (string) ($link_result['content'] ?? $content);
        $links_inserted = (int) ($link_result['inserted_links'] ?? 0);
        $keyword_hits = is_array($link_result['keyword_hits'] ?? null)
            ? $link_result['keyword_hits']
            : array();

        if ($links_inserted <= 0 || $linked_content === $content) {
            $this->persist_content_hash($post_id, $current_hash);
            $this->stats->record_save_metrics($post_id, 0);
            return array(
                'processed' => true,
                'changed' => false,
                'inserted_links' => 0,
                'keyword_hits' => array(),
            );
        }

        if ($this->update_post_content($post_id, $linked_content)) {
            $this->persist_content_hash($post_id, $this->content_hash($linked_content));
            $this->stats->record_save_metrics($post_id, $links_inserted, $keyword_hits);
            return array(
                'processed' => true,
                'changed' => true,
                'inserted_links' => max(0, $links_inserted),
                'keyword_hits' => $keyword_hits,
            );
        }

        Runtime::debug_log(
            'linker.post_update_failed',
            array(
                'post_id' => $post_id,
                'inserted_links' => max(0, $links_inserted),
            )
        );
        $this->persist_content_hash($post_id, $current_hash);
        $this->stats->record_save_metrics($post_id, 0);

        return array(
            'processed' => true,
            'changed' => false,
            'inserted_links' => 0,
            'keyword_hits' => array(),
        );
    }

    private function is_autosave_or_revision(int $post_id): bool
    {
        if (function_exists('wp_is_post_autosave') && wp_is_post_autosave($post_id) !== false) {
            return true;
        }

        if (function_exists('wp_is_post_revision') && wp_is_post_revision($post_id) !== false) {
            return true;
        }

        return false;
    }

    private function is_unchanged_content_save(int $post_id, string $current_hash): bool
    {
        if (! function_exists('get_post_meta')) {
            return false;
        }

        $saved_hash = get_post_meta($post_id, self::CONTENT_HASH_META_KEY, true);

        if (! is_scalar($saved_hash)) {
            return false;
        }

        return (string) $saved_hash === $current_hash;
    }

    private function persist_content_hash(int $post_id, string $content_hash): void
    {
        if (! function_exists('update_post_meta')) {
            return;
        }

        update_post_meta($post_id, self::CONTENT_HASH_META_KEY, $content_hash);
    }

    private function update_post_content(int $post_id, string $linked_content): bool
    {
        if (! function_exists('wp_update_post')) {
            return false;
        }

        $this->active_updates[$post_id] = true;

        try {
            $result = wp_update_post(
                array(
                    'ID' => $post_id,
                    'post_content' => $linked_content,
                ),
                true
            );
        } finally {
            unset($this->active_updates[$post_id]);
        }

        if (is_int($result)) {
            return $result > 0;
        }

        return $result !== false;
    }

    private function content_hash(string $content): string
    {
        return hash('sha256', str_replace("\r\n", "\n", $content));
    }

    private function max_links_per_post(): int
    {
        $defaults = Installer::default_options();
        $default_max_links = (int) ($defaults['max_links_per_post'] ?? 0);

        if (! function_exists('get_option')) {
            return max(0, $default_max_links);
        }

        $options = get_option(self::OPTIONS_OPTION_KEY, array());

        if (! is_array($options)) {
            return max(0, $default_max_links);
        }

        $raw_value = $options['max_links_per_post'] ?? $default_max_links;
        $validated = filter_var(
            (string) $raw_value,
            FILTER_VALIDATE_INT,
            array(
                'options' => array(
                    'min_range' => 0,
                ),
            )
        );

        if ($validated === false) {
            return max(0, $default_max_links);
        }

        return (int) $validated;
    }

    /**
     * @param array<string, array<int, string>> $mappings
     * @return array{content: string, inserted_links: int, keyword_hits: array<string, int>}
     */
    private function link_content(int $post_id, string $content, array $mappings, int $max_links_per_post): array
    {
        if ($content === '' || $max_links_per_post <= 0 || $mappings === array()) {
            return array(
                'content' => $content,
                'inserted_links' => 0,
                'keyword_hits' => array(),
            );
        }

        if (! $this->is_content_safe_for_linking($post_id, $content)) {
            return array(
                'content' => $content,
                'inserted_links' => 0,
                'keyword_hits' => array(),
            );
        }

        $keyword_entries = $this->build_keyword_entries($mappings);

        if ($keyword_entries === array()) {
            return array(
                'content' => $content,
                'inserted_links' => 0,
                'keyword_hits' => array(),
            );
        }

        $inserted_links = 0;
        $keyword_hits = array();
        $linked_content = '';
        $offset = 0;
        $content_length = strlen($content);
        $paragraph_depth = 0;
        $excluded_depth = 0;
        $raw_text_tag = '';

        while ($offset < $content_length) {
            if ($inserted_links >= $max_links_per_post) {
                $linked_content .= substr($content, $offset);
                break;
            }

            if ($raw_text_tag !== '') {
                $raw_close_offset = stripos($content, '</' . $raw_text_tag, $offset);

                if ($raw_close_offset === false) {
                    $linked_content .= substr($content, $offset);
                    break;
                }

                $linked_content .= substr($content, $offset, $raw_close_offset - $offset);
                $offset = $raw_close_offset;
                $raw_text_tag = '';
                continue;
            }

            $tag_offset = strpos($content, '<', $offset);

            if ($tag_offset === false) {
                $text_fragment = substr($content, $offset);
                $linked_content .= $this->link_text_fragment_for_context(
                    $text_fragment,
                    $keyword_entries,
                    $max_links_per_post,
                    $inserted_links,
                    $keyword_hits,
                    $paragraph_depth,
                    $excluded_depth
                );
                break;
            }

            if ($tag_offset > $offset) {
                $text_fragment = substr($content, $offset, $tag_offset - $offset);
                $linked_content .= $this->link_text_fragment_for_context(
                    $text_fragment,
                    $keyword_entries,
                    $max_links_per_post,
                    $inserted_links,
                    $keyword_hits,
                    $paragraph_depth,
                    $excluded_depth
                );
                $offset = $tag_offset;
                continue;
            }

            $tag_token = $this->parse_html_token($content, $offset);

            if ($tag_token === null) {
                $linked_content .= '<';
                $offset++;
                continue;
            }

            $linked_content .= (string) $tag_token['token'];
            $offset = (int) ($tag_token['next_offset'] ?? ($offset + 1));

            if (! ((bool) ($tag_token['is_tag'] ?? false))) {
                continue;
            }

            $tag_name = (string) ($tag_token['name'] ?? '');

            if ($tag_name === '') {
                continue;
            }

            $is_end_tag = (bool) ($tag_token['is_end_tag'] ?? false);
            $is_self_closing = (bool) ($tag_token['is_self_closing'] ?? false);

            if ($is_end_tag) {
                if ($tag_name === 'p' && $paragraph_depth > 0) {
                    $paragraph_depth--;
                }

                if (isset(self::EXCLUDED_CONTEXT_TAGS[$tag_name]) && $excluded_depth > 0) {
                    $excluded_depth--;
                }

                continue;
            }

            if ($tag_name === 'p' && ! $is_self_closing) {
                $paragraph_depth++;
            }

            if (isset(self::EXCLUDED_CONTEXT_TAGS[$tag_name]) && ! $is_self_closing) {
                $excluded_depth++;
            }

            if (isset(self::RAW_TEXT_TAGS[$tag_name]) && ! $is_self_closing) {
                $raw_text_tag = $tag_name;
            }
        }

        return array(
            'content' => $linked_content,
            'inserted_links' => $inserted_links,
            'keyword_hits' => $keyword_hits,
        );
    }

    private function is_content_safe_for_linking(int $post_id, string $content): bool
    {
        $content_bytes = strlen($content);

        if ($content_bytes > self::MAX_CONTENT_BYTES) {
            Runtime::debug_log(
                'linker.content_too_large',
                array(
                    'post_id' => $post_id,
                    'content_bytes' => $content_bytes,
                    'max_content_bytes' => self::MAX_CONTENT_BYTES,
                )
            );
            return false;
        }

        if (function_exists('mb_check_encoding') && ! mb_check_encoding($content, 'UTF-8')) {
            Runtime::debug_log(
                'linker.content_invalid_utf8',
                array(
                    'post_id' => $post_id,
                    'content_bytes' => $content_bytes,
                )
            );
            return false;
        }

        return true;
    }

    /**
     * @param array<int, array{keyword: string, pattern: string, urls: array<int, string>, length: int}> $keyword_entries
     */
    private function link_text_fragment_for_context(
        string $text_fragment,
        array $keyword_entries,
        int $max_links_per_post,
        int &$inserted_links,
        array &$keyword_hits,
        int $paragraph_depth,
        int $excluded_depth
    ): string {
        if ($text_fragment === '' || $paragraph_depth <= 0 || $excluded_depth > 0) {
            return $text_fragment;
        }

        return $this->link_plain_text(
            $text_fragment,
            $keyword_entries,
            $max_links_per_post,
            $inserted_links,
            $keyword_hits
        );
    }

    /**
     * @return array{
     *   token: string,
     *   next_offset: int,
     *   is_tag: bool,
     *   name: string,
     *   is_end_tag: bool,
     *   is_self_closing: bool
     * }|null
     */
    private function parse_html_token(string $content, int $offset): ?array
    {
        $content_length = strlen($content);

        if ($offset < 0 || $offset >= $content_length || $content[$offset] !== '<') {
            return null;
        }

        if (substr($content, $offset, 4) === '<!--') {
            $comment_end = strpos($content, '-->', $offset + 4);

            if ($comment_end === false) {
                return null;
            }

            $next_offset = $comment_end + 3;

            return array(
                'token' => substr($content, $offset, $next_offset - $offset),
                'next_offset' => $next_offset,
                'is_tag' => false,
                'name' => '',
                'is_end_tag' => false,
                'is_self_closing' => false,
            );
        }

        if (substr($content, $offset, 9) === '<![CDATA[') {
            $cdata_end = strpos($content, ']]>', $offset + 9);

            if ($cdata_end === false) {
                return null;
            }

            $next_offset = $cdata_end + 3;

            return array(
                'token' => substr($content, $offset, $next_offset - $offset),
                'next_offset' => $next_offset,
                'is_tag' => false,
                'name' => '',
                'is_end_tag' => false,
                'is_self_closing' => false,
            );
        }

        if (substr($content, $offset, 2) === '<?') {
            $pi_end = strpos($content, '?>', $offset + 2);

            if ($pi_end === false) {
                return null;
            }

            $next_offset = $pi_end + 2;

            return array(
                'token' => substr($content, $offset, $next_offset - $offset),
                'next_offset' => $next_offset,
                'is_tag' => false,
                'name' => '',
                'is_end_tag' => false,
                'is_self_closing' => false,
            );
        }

        $quote = '';
        $cursor = $offset + 1;

        while ($cursor < $content_length) {
            $char = $content[$cursor];

            if ($quote === '') {
                if ($char === '"' || $char === '\'') {
                    $quote = $char;
                } elseif ($char === '>') {
                    break;
                }
            } elseif ($char === $quote) {
                $quote = '';
            }

            $cursor++;
        }

        if ($cursor >= $content_length || $content[$cursor] !== '>') {
            return null;
        }

        $next_offset = $cursor + 1;
        $token = substr($content, $offset, $next_offset - $offset);
        $name = '';
        $is_tag = false;
        $is_end_tag = false;
        $is_self_closing = false;

        if (preg_match('/^<\s*\/\s*([a-zA-Z][a-zA-Z0-9:-]*)\b/s', $token, $matches) === 1) {
            $name = strtolower((string) ($matches[1] ?? ''));
            $is_tag = true;
            $is_end_tag = true;
        } elseif (preg_match('/^<\s*([a-zA-Z][a-zA-Z0-9:-]*)\b/s', $token, $matches) === 1) {
            $name = strtolower((string) ($matches[1] ?? ''));
            $is_tag = true;
            $is_self_closing = preg_match('/\/\s*>$/', $token) === 1;
        }

        return array(
            'token' => $token,
            'next_offset' => $next_offset,
            'is_tag' => $is_tag,
            'name' => $name,
            'is_end_tag' => $is_end_tag,
            'is_self_closing' => $is_self_closing,
        );
    }

    /**
     * @param array<int, array{keyword: string, pattern: string, urls: array<int, string>, length: int}> $keyword_entries
     */
    private function link_plain_text(
        string $text,
        array $keyword_entries,
        int $max_links_per_post,
        int &$inserted_links,
        array &$keyword_hits
    ): string {
        if ($text === '' || $inserted_links >= $max_links_per_post || $keyword_entries === array()) {
            return $text;
        }

        $output = '';
        $offset = 0;
        $text_length = strlen($text);

        while ($offset < $text_length && $inserted_links < $max_links_per_post) {
            $best_match = null;

            foreach ($keyword_entries as $keyword_entry) {
                if (! preg_match($keyword_entry['pattern'], $text, $matches, PREG_OFFSET_CAPTURE, $offset)) {
                    continue;
                }

                $full_match = $matches[0][0] ?? '';
                $full_offset = (int) ($matches[0][1] ?? -1);
                $capture_match = $matches[1][0] ?? $full_match;

                if (! is_string($full_match) || $full_match === '' || $full_offset < 0 || ! is_string($capture_match)) {
                    continue;
                }

                if (
                    $best_match === null
                    || $full_offset < (int) $best_match['offset']
                    || (
                        $full_offset === (int) $best_match['offset']
                        && (int) $keyword_entry['length'] > (int) $best_match['length']
                    )
                ) {
                    $best_match = array(
                        'entry' => $keyword_entry,
                        'offset' => $full_offset,
                        'full_match' => $full_match,
                        'capture_match' => $capture_match,
                        'length' => (int) $keyword_entry['length'],
                    );
                }
            }

            if (! is_array($best_match)) {
                break;
            }

            $match_offset = (int) ($best_match['offset'] ?? -1);
            $full_match = (string) ($best_match['full_match'] ?? '');
            $capture_match = (string) ($best_match['capture_match'] ?? '');
            $keyword_entry = (array) ($best_match['entry'] ?? array());

            if ($match_offset < $offset || $full_match === '' || ! isset($keyword_entry['urls']) || ! is_array($keyword_entry['urls'])) {
                break;
            }

            $output .= substr($text, $offset, $match_offset - $offset);

            $target_url = $this->pick_random_url($keyword_entry['urls']);
            $output .= '<a href="' . $this->escape_url_attr($target_url) . '">' . $capture_match . '</a>';

            $matched_keyword = isset($keyword_entry['keyword']) && is_string($keyword_entry['keyword'])
                ? $keyword_entry['keyword']
                : '';

            if ($matched_keyword !== '') {
                if (! isset($keyword_hits[$matched_keyword])) {
                    $keyword_hits[$matched_keyword] = 0;
                }

                $keyword_hits[$matched_keyword]++;
            }

            $offset = $match_offset + strlen($full_match);
            $inserted_links++;
        }

        $output .= substr($text, $offset);

        return $output;
    }

    /**
     * @param array<string, array<int, string>> $mappings
     * @return array<int, array{keyword: string, pattern: string, urls: array<int, string>, length: int}>
     */
    private function build_keyword_entries(array $mappings): array
    {
        $entries = array();

        foreach ($mappings as $keyword => $urls) {
            if (! is_string($keyword) || $keyword === '' || ! is_array($urls) || $urls === array()) {
                continue;
            }

            $entries[] = array(
                'keyword' => $keyword,
                'pattern' => '/(?<![\p{L}\p{N}_])(' . preg_quote($keyword, '/') . ')(?![\p{L}\p{N}_])/iu',
                'urls' => $urls,
                'length' => $this->string_length($keyword),
            );
        }

        usort(
            $entries,
            static function (array $left, array $right): int {
                $length_compare = ((int) ($right['length'] ?? 0)) <=> ((int) ($left['length'] ?? 0));

                if ($length_compare !== 0) {
                    return $length_compare;
                }

                return strcmp((string) ($left['keyword'] ?? ''), (string) ($right['keyword'] ?? ''));
            }
        );

        return $entries;
    }

    /**
     * @param array<int, string> $urls
     */
    private function pick_random_url(array $urls): string
    {
        if ($urls === array()) {
            return '';
        }

        if (count($urls) === 1) {
            return (string) $urls[0];
        }

        $max_index = count($urls) - 1;
        $selected_index = 0;

        if (function_exists('wp_rand')) {
            $selected_index = (int) wp_rand(0, $max_index);
        } else {
            try {
                $selected_index = random_int(0, $max_index);
            } catch (\Exception $exception) {
                $selected_index = 0;
            }
        }

        if ($selected_index < 0 || $selected_index > $max_index) {
            $selected_index = 0;
        }

        return (string) $urls[$selected_index];
    }

    private function escape_url_attr(string $url): string
    {
        if (function_exists('esc_url')) {
            return (string) esc_url($url);
        }

        return htmlspecialchars((string) filter_var($url, FILTER_SANITIZE_URL), ENT_QUOTES, 'UTF-8');
    }

    private function string_length(string $value): int
    {
        if (function_exists('mb_strlen')) {
            return mb_strlen($value);
        }

        return strlen($value);
    }
}
