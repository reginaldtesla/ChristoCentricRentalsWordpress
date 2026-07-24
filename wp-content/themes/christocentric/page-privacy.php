<?php
defined('ABSPATH') || exit;

$page = ccr_page_json('privacy');
get_header();
get_template_part('template-parts/page-hero', null, [
    'title' => $page['hero_title'] ?? 'Privacy Policy',
    'subtitle' => $page['hero_subtitle'] ?? '',
]);
get_template_part('template-parts/doc-page', null, ['slug' => 'privacy']);
get_footer();
