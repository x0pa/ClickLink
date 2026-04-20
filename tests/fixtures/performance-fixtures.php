<?php

declare(strict_types=1);

if (! function_exists('clicklink_fixture_large_publish_posts')) {
    /**
     * @return array<int, array{post_type: string, post_status: string, post_content: string}>
     */
    function clicklink_fixture_large_publish_posts(int $count, string $keyword, int $start_id = 1): array
    {
        $resolved_count = max(0, $count);
        $resolved_start_id = max(1, $start_id);
        $normalized_keyword = trim($keyword);

        if ($normalized_keyword === '') {
            $normalized_keyword = 'keyword';
        }

        $posts = array();

        for ($offset = 0; $offset < $resolved_count; $offset++) {
            $post_id = $resolved_start_id + $offset;
            $posts[$post_id] = array(
                'post_type' => 'post',
                'post_status' => 'publish',
                'post_content' => '<p>' . $normalized_keyword . ' performance post ' . $post_id . '.</p>',
            );
        }

        return $posts;
    }
}

if (! function_exists('clicklink_fixture_large_mapping_pool')) {
    /**
     * @return array<int, array{keyword: string, url: string}>
     */
    function clicklink_fixture_large_mapping_pool(int $noise_count, string $matching_keyword, string $matching_url): array
    {
        $resolved_noise_count = max(0, $noise_count);
        $keyword = trim($matching_keyword);

        if ($keyword === '') {
            $keyword = 'keyword';
        }

        $url = trim($matching_url);

        if ($url === '') {
            $url = 'https://example.com/' . rawurlencode(strtolower(str_replace(' ', '-', $keyword)));
        }

        $mappings = array();

        for ($index = 1; $index <= $resolved_noise_count; $index++) {
            $mappings[] = array(
                'keyword' => sprintf('noise-keyword-%03d', $index),
                'url' => sprintf('https://example.com/noise-%03d', $index),
            );
        }

        $mappings[] = array(
            'keyword' => $keyword,
            'url' => $url,
        );

        return $mappings;
    }
}
