<?php

declare(strict_types=1);

namespace ClickLink;

final class Post_Save_Linker
{
    private const CONTENT_HASH_META_KEY = '_clicklink_content_hash';
    private const OPTIONS_OPTION_KEY = 'clicklink_options';

    private Linker_Stats $stats;

    /**
     * @var array<int, bool>
     */
    private array $active_updates = array();

    public function __construct(?Linker_Stats $stats = null)
    {
        $this->stats = $stats ?? new Linker_Stats();
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
        if ($post_id <= 0) {
            return;
        }

        if (isset($this->active_updates[$post_id])) {
            return;
        }

        if (! is_object($post)) {
            return;
        }

        $post_type = isset($post->post_type) && is_string($post->post_type)
            ? $post->post_type
            : '';

        if ($post_type !== 'post') {
            return;
        }

        if ($this->is_autosave_or_revision($post_id)) {
            return;
        }

        $content = isset($post->post_content) && is_string($post->post_content)
            ? $post->post_content
            : '';
        $current_hash = $this->content_hash($content);

        if ($update && $this->is_unchanged_content_save($post_id, $current_hash)) {
            return;
        }

        $mappings = $this->fetch_keyword_mappings();

        if ($mappings === array()) {
            $this->persist_content_hash($post_id, $current_hash);
            $this->stats->record_save_metrics($post_id, 0);
            return;
        }

        $max_links_per_post = $this->max_links_per_post();

        if ($max_links_per_post <= 0) {
            $this->persist_content_hash($post_id, $current_hash);
            $this->stats->record_save_metrics($post_id, 0);
            return;
        }

        $result = $this->link_content($content, $mappings, $max_links_per_post);
        $linked_content = (string) ($result['content'] ?? $content);
        $links_inserted = (int) ($result['inserted_links'] ?? 0);

        if ($links_inserted <= 0 || $linked_content === $content) {
            $this->persist_content_hash($post_id, $current_hash);
            $this->stats->record_save_metrics($post_id, 0);
            return;
        }

        if ($this->update_post_content($post_id, $linked_content)) {
            $this->persist_content_hash($post_id, $this->content_hash($linked_content));
            $this->stats->record_save_metrics($post_id, $links_inserted);
            return;
        }

        $this->persist_content_hash($post_id, $current_hash);
        $this->stats->record_save_metrics($post_id, 0);
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

    /**
     * @return array<string, array<int, string>>
     */
    private function fetch_keyword_mappings(): array
    {
        global $wpdb;

        if (! is_object($wpdb) || ! method_exists($wpdb, 'get_results')) {
            return array();
        }

        $table_name = Installer::table_name();
        $results = $wpdb->get_results(
            "SELECT keyword, url FROM {$table_name} WHERE keyword <> '' AND url <> '' ORDER BY keyword ASC, id ASC",
            'ARRAY_A'
        );

        if (! is_array($results)) {
            return array();
        }

        $mappings = array();

        foreach ($results as $result) {
            if (! is_array($result)) {
                continue;
            }

            $keyword = $this->normalize_keyword((string) ($result['keyword'] ?? ''));
            $url = $this->sanitize_url((string) ($result['url'] ?? ''));

            if ($keyword === '' || $url === '') {
                continue;
            }

            if (! isset($mappings[$keyword])) {
                $mappings[$keyword] = array();
            }

            $mappings[$keyword][] = $url;
        }

        return $mappings;
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
     * @return array{content: string, inserted_links: int}
     */
    private function link_content(string $content, array $mappings, int $max_links_per_post): array
    {
        if ($content === '' || $max_links_per_post <= 0 || $mappings === array()) {
            return array(
                'content' => $content,
                'inserted_links' => 0,
            );
        }

        $keyword_entries = $this->build_keyword_entries($mappings);

        if ($keyword_entries === array()) {
            return array(
                'content' => $content,
                'inserted_links' => 0,
            );
        }

        $inserted_links = 0;
        $linked_content = preg_replace_callback(
            '/<p\b[^>]*>.*?<\/p>/is',
            function (array $matches) use ($keyword_entries, $max_links_per_post, &$inserted_links): string {
                $paragraph = (string) ($matches[0] ?? '');

                if ($paragraph === '' || $inserted_links >= $max_links_per_post) {
                    return $paragraph;
                }

                return $this->link_paragraph($paragraph, $keyword_entries, $max_links_per_post, $inserted_links);
            },
            $content
        );

        if (! is_string($linked_content)) {
            return array(
                'content' => $content,
                'inserted_links' => 0,
            );
        }

        return array(
            'content' => $linked_content,
            'inserted_links' => $inserted_links,
        );
    }

    /**
     * @param array<int, array{keyword: string, pattern: string, urls: array<int, string>, length: int}> $keyword_entries
     */
    private function link_paragraph(
        string $paragraph,
        array $keyword_entries,
        int $max_links_per_post,
        int &$inserted_links
    ): string {
        $segments = preg_split(
            '/(<a\b[^>]*>.*?<\/a>|<code\b[^>]*>.*?<\/code>|<pre\b[^>]*>.*?<\/pre>)/is',
            $paragraph,
            -1,
            PREG_SPLIT_DELIM_CAPTURE
        );

        if (! is_array($segments)) {
            return $paragraph;
        }

        $linked_paragraph = '';

        foreach ($segments as $segment) {
            if ($segment === '') {
                continue;
            }

            if (preg_match('/^<(a|code|pre)\b/i', $segment) === 1) {
                $linked_paragraph .= $segment;
                continue;
            }

            $linked_paragraph .= $this->link_text_outside_tags(
                $segment,
                $keyword_entries,
                $max_links_per_post,
                $inserted_links
            );
        }

        return $linked_paragraph;
    }

    /**
     * @param array<int, array{keyword: string, pattern: string, urls: array<int, string>, length: int}> $keyword_entries
     */
    private function link_text_outside_tags(
        string $html_fragment,
        array $keyword_entries,
        int $max_links_per_post,
        int &$inserted_links
    ): string {
        $segments = preg_split('/(<[^>]+>)/', $html_fragment, -1, PREG_SPLIT_DELIM_CAPTURE);

        if (! is_array($segments)) {
            return $html_fragment;
        }

        $linked_fragment = '';

        foreach ($segments as $segment) {
            if ($segment === '') {
                continue;
            }

            if ($segment[0] === '<') {
                $linked_fragment .= $segment;
                continue;
            }

            $linked_fragment .= $this->link_plain_text(
                $segment,
                $keyword_entries,
                $max_links_per_post,
                $inserted_links
            );
        }

        return $linked_fragment;
    }

    /**
     * @param array<int, array{keyword: string, pattern: string, urls: array<int, string>, length: int}> $keyword_entries
     */
    private function link_plain_text(
        string $text,
        array $keyword_entries,
        int $max_links_per_post,
        int &$inserted_links
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

    private function normalize_keyword(string $keyword): string
    {
        $keyword = trim($keyword);
        $keyword = preg_replace('/\s+/', ' ', $keyword) ?? $keyword;

        if (function_exists('sanitize_text_field')) {
            $keyword = sanitize_text_field($keyword);
        } else {
            $keyword = trim(strip_tags($keyword));
        }

        if (function_exists('mb_strtolower')) {
            return mb_strtolower($keyword);
        }

        return strtolower($keyword);
    }

    private function sanitize_url(string $url): string
    {
        $url = trim($url);

        if (function_exists('esc_url_raw')) {
            $url = esc_url_raw($url);
        } else {
            $url = (string) filter_var($url, FILTER_SANITIZE_URL);
        }

        if ($url === '') {
            return '';
        }

        if (function_exists('wp_http_validate_url')) {
            return wp_http_validate_url($url) ? $url : '';
        }

        return filter_var($url, FILTER_VALIDATE_URL) !== false ? $url : '';
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
