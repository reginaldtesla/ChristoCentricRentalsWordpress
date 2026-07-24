<?php
defined('ABSPATH') || exit;

$page = ccr_page_json('terms');
get_header();
get_template_part('template-parts/page-hero', null, [
    'title' => $page['hero_title'] ?? 'Terms and Conditions',
    'subtitle' => $page['hero_subtitle'] ?? '',
]);
get_template_part('template-parts/doc-page', null, ['slug' => 'terms']);
get_footer();
