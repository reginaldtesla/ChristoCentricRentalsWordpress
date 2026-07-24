<?php
defined('ABSPATH') || exit;

$page = ccr_page_json('help');
get_header();
get_template_part('template-parts/page-hero', null, [
    'title' => $page['hero_title'] ?? 'Help & Support',
    'subtitle' => $page['hero_subtitle'] ?? '',
]);
get_template_part('template-parts/doc-page', null, ['slug' => 'help']);
get_footer();
