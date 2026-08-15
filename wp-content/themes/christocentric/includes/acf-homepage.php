<?php

defined('ABSPATH') || exit;

/**
 * ACF Free homepage CMS (dedicated private page — free ACF has no Options Pages).
 */

function ccr_homepage_cms_page_id(): int
{
    static $id = null;
    if ($id !== null) {
        return $id;
    }

    $stored = (int) get_option('ccr_homepage_cms_page_id', 0);
    if ($stored > 0 && get_post($stored)) {
        $id = $stored;

        return $id;
    }

    $existing = get_page_by_path('homepage-cms', OBJECT, 'page');
    if ($existing instanceof WP_Post) {
        update_option('ccr_homepage_cms_page_id', (int) $existing->ID, false);
        $id = (int) $existing->ID;

        return $id;
    }

    $created = wp_insert_post([
        'post_title' => 'Homepage CMS',
        'post_name' => 'homepage-cms',
        'post_status' => 'private',
        'post_type' => 'page',
        'post_content' => '<!-- Managed by ACF fields. Do not delete. -->',
    ], true);

    if (is_wp_error($created) || ! $created) {
        $id = 0;

        return 0;
    }

    update_option('ccr_homepage_cms_page_id', (int) $created, false);
    $id = (int) $created;

    return $id;
}

function ccr_acf_get(string $selector, mixed $default = null): mixed
{
    if (! function_exists('get_field')) {
        return $default;
    }

    $pageId = ccr_homepage_cms_page_id();
    if ($pageId <= 0) {
        return $default;
    }

    $value = get_field($selector, $pageId);
    if ($value === null || $value === false || $value === '' || $value === []) {
        return $default;
    }

    return $value;
}

add_action('acf/init', static function (): void {
    if (! function_exists('acf_add_local_field_group')) {
        return;
    }

    $pageId = ccr_homepage_cms_page_id();
    if ($pageId <= 0) {
        return;
    }

    acf_add_local_field_group([
        'key' => 'group_ccr_homepage',
        'title' => 'Homepage content',
        'fields' => [
            [
                'key' => 'field_ccr_brand_banners',
                'label' => 'Browse by category banners',
                'name' => 'ccr_brand_banners',
                'type' => 'repeater',
                'layout' => 'block',
                'button_label' => 'Add banner',
                'sub_fields' => [
                    [
                        'key' => 'field_ccr_bb_title',
                        'label' => 'Title',
                        'name' => 'title',
                        'type' => 'text',
                    ],
                    [
                        'key' => 'field_ccr_bb_description',
                        'label' => 'Description',
                        'name' => 'description',
                        'type' => 'text',
                    ],
                    [
                        'key' => 'field_ccr_bb_category',
                        'label' => 'Product category',
                        'name' => 'category',
                        'type' => 'taxonomy',
                        'taxonomy' => 'product_cat',
                        'field_type' => 'select',
                        'return_format' => 'object',
                        'add_term' => 0,
                    ],
                    [
                        'key' => 'field_ccr_bb_image',
                        'label' => 'Image override (optional)',
                        'name' => 'image',
                        'type' => 'image',
                        'return_format' => 'array',
                        'preview_size' => 'medium',
                    ],
                ],
            ],
            [
                'key' => 'field_ccr_newsletter',
                'label' => 'Newsletter band',
                'name' => 'ccr_newsletter',
                'type' => 'group',
                'layout' => 'block',
                'sub_fields' => [
                    [
                        'key' => 'field_ccr_nl_heading',
                        'label' => 'Heading',
                        'name' => 'heading',
                        'type' => 'text',
                    ],
                    [
                        'key' => 'field_ccr_nl_subtext',
                        'label' => 'Subtext',
                        'name' => 'subtext',
                        'type' => 'textarea',
                        'rows' => 3,
                    ],
                    [
                        'key' => 'field_ccr_nl_button',
                        'label' => 'Button label',
                        'name' => 'button',
                        'type' => 'text',
                    ],
                ],
            ],
            [
                'key' => 'field_ccr_trust',
                'label' => 'Trust bar features',
                'name' => 'ccr_trust_features',
                'type' => 'repeater',
                'layout' => 'table',
                'button_label' => 'Add feature',
                'sub_fields' => [
                    [
                        'key' => 'field_ccr_tf_title',
                        'label' => 'Title',
                        'name' => 'title',
                        'type' => 'text',
                    ],
                    [
                        'key' => 'field_ccr_tf_subtitle',
                        'label' => 'Subtitle',
                        'name' => 'subtitle',
                        'type' => 'text',
                    ],
                ],
            ],
            [
                'key' => 'field_ccr_brands',
                'label' => 'Brand strip logos',
                'name' => 'ccr_brand_logos',
                'type' => 'repeater',
                'layout' => 'table',
                'button_label' => 'Add brand',
                'sub_fields' => [
                    [
                        'key' => 'field_ccr_bl_name',
                        'label' => 'Name',
                        'name' => 'name',
                        'type' => 'text',
                    ],
                    [
                        'key' => 'field_ccr_bl_image',
                        'label' => 'Logo image',
                        'name' => 'image',
                        'type' => 'image',
                        'return_format' => 'array',
                        'preview_size' => 'thumbnail',
                    ],
                ],
            ],
        ],
        'location' => [[
            [
                'param' => 'page',
                'operator' => '==',
                'value' => (string) $pageId,
            ],
        ]],
        'menu_order' => 0,
        'position' => 'normal',
        'style' => 'default',
        'active' => true,
    ]);
});

add_action('admin_menu', static function (): void {
    $pageId = ccr_homepage_cms_page_id();
    if ($pageId <= 0) {
        return;
    }

    add_theme_page(
        __('Homepage CMS', 'christocentric'),
        __('Homepage CMS', 'christocentric'),
        'edit_theme_options',
        'ccr-homepage-cms',
        static function () use ($pageId): void {
            $url = get_edit_post_link($pageId, 'raw');
            if (! $url) {
                echo '<div class="wrap"><p>' . esc_html__('Homepage CMS page missing.', 'christocentric') . '</p></div>';

                return;
            }
            wp_safe_redirect($url);
            exit;
        }
    );
});
