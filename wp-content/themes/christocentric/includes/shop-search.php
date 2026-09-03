<?php

defined('ABSPATH') || exit;

/**
 * Rank shop search so closer titles (e.g. “Mark II”) beat substring hits (“Mark III”).
 */
add_filter('posts_orderby', 'ccr_product_search_orderby', 30, 2);
add_filter('the_posts', 'ccr_product_search_reorder_page', 20, 2);

function ccr_product_search_query_term(WP_Query $query): string
{
    return trim((string) $query->get('s'));
}

function ccr_is_product_search_query(WP_Query $query): bool
{
    if (is_admin() || ! $query instanceof WP_Query) {
        return false;
    }

    $term = ccr_product_search_query_term($query);
    if ($term === '') {
        return false;
    }

    $postType = $query->get('post_type');
    if ($postType === 'product') {
        return true;
    }
    if (is_array($postType) && in_array('product', $postType, true)) {
        return true;
    }

    return $query->is_post_type_archive('product');
}

function ccr_product_search_user_orderby(): string
{
    return isset($_GET['orderby']) ? sanitize_key(wp_unslash((string) $_GET['orderby'])) : ''; // phpcs:ignore
}

function ccr_product_search_should_rank(WP_Query $query): bool
{
    if (! ccr_is_product_search_query($query)) {
        return false;
    }

    $orderby = ccr_product_search_user_orderby();

    return $orderby === '' || in_array($orderby, ['menu_order', 'relevance'], true);
}

function ccr_search_normalize(string $value): string
{
    $value = strtolower($value);
    $value = str_replace(['–', '—', '-', '_', '/', '\\'], ' ', $value);
    $value = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $value) ?? $value;
    $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

    return trim($value);
}

/**
 * Whole-phrase regex so “mark ii” does not match “mark iii”.
 */
function ccr_search_phrase_regex(string $query): string
{
    $norm = ccr_search_normalize($query);
    if ($norm === '') {
        return '';
    }

    $parts = preg_split('/\s+/', $norm) ?: [];
    $escaped = array_map(static function (string $part): string {
        return preg_quote($part, '/');
    }, $parts);

    return '(^|[^[:alnum:]])' . implode('[^[:alnum:]]+', $escaped) . '($|[^[:alnum:]])';
}

function ccr_product_search_title_score(string $title, string $query): int
{
    $titleN = ccr_search_normalize($title);
    $queryN = ccr_search_normalize($query);
    if ($queryN === '' || $titleN === '') {
        return 0;
    }

    if ($titleN === $queryN) {
        return 1000;
    }

    $qTokens = preg_split('/\s+/', $queryN) ?: [];
    $tTokens = preg_split('/\s+/', $titleN) ?: [];
    $qTokens = array_values(array_filter($qTokens, static fn ($t) => $t !== ''));
    $tTokens = array_values(array_filter($tTokens, static fn ($t) => $t !== ''));

    $matched = 0;
    foreach ($qTokens as $tok) {
        if (in_array($tok, $tTokens, true)) {
            $matched++;
        }
    }

    $allWhole = $qTokens !== [] && $matched === count($qTokens);
    if ($allWhole) {
        if (str_starts_with($titleN, $queryN)) {
            return 900 - min(80, max(0, count($tTokens) - count($qTokens)) * 8);
        }

        return 750 - min(80, max(0, count($tTokens) - count($qTokens)) * 8);
    }

    if (str_contains($titleN, $queryN)) {
        return 200;
    }

    return $matched * 40;
}

function ccr_product_search_orderby(string $orderby, WP_Query $query): string
{
    if (! ccr_product_search_should_rank($query)) {
        return $orderby;
    }

    global $wpdb;
    $term = ccr_product_search_query_term($query);
    $norm = ccr_search_normalize($term);
    $regex = ccr_search_phrase_regex($term);
    if ($norm === '' || $regex === '') {
        return $orderby;
    }

    $title = "LOWER({$wpdb->posts}.post_title)";
    $sql = $wpdb->prepare(
        "CASE
            WHEN {$title} = %s THEN 0
            WHEN {$wpdb->posts}.post_title REGEXP %s THEN 1
            WHEN {$title} LIKE %s THEN 2
            ELSE 3
        END ASC, {$wpdb->posts}.post_title ASC",
        $norm,
        $regex,
        $wpdb->esc_like($norm) . ' %'
    );

    return is_string($sql) ? $sql : $orderby;
}

/**
 * Re-rank the current page so whole-word title matches win over substring hits.
 *
 * @param WP_Post[] $posts
 * @return WP_Post[]
 */
function ccr_product_search_reorder_page($posts, WP_Query $query)
{
    if (! is_array($posts) || $posts === [] || ! ccr_product_search_should_rank($query)) {
        return $posts;
    }

    $term = ccr_product_search_query_term($query);
    $scored = [];
    foreach ($posts as $index => $post) {
        if (! $post instanceof WP_Post) {
            continue;
        }
        $scored[] = [
            'post' => $post,
            'score' => ccr_product_search_title_score($post->post_title, $term),
            'index' => $index,
            'title' => ccr_search_normalize($post->post_title),
        ];
    }

    usort($scored, static function (array $a, array $b): int {
        if ($a['score'] !== $b['score']) {
            return $b['score'] <=> $a['score'];
        }
        $cmp = strcasecmp($a['title'], $b['title']);
        if ($cmp !== 0) {
            return $cmp;
        }

        return $a['index'] <=> $b['index'];
    });

    return array_map(static fn (array $row) => $row['post'], $scored);
}
