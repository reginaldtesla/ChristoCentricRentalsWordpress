<?php

defined('ABSPATH') || exit;

/**
 * Admin CSS fixes for the WP media modal (featured image picker, etc.).
 */
final class CCR_Admin_Media_Ui
{
    public static function init(): void
    {
        add_action('admin_enqueue_scripts', [self::class, 'enqueue'], 100);
        add_action('wp_enqueue_media', [self::class, 'enqueue']);
    }

    public static function enqueue(): void
    {
        if (! is_admin()) {
            return;
        }

        $css = <<<'CSS'
/* Prevent media library list-view "staircase" when floats leak in */
.media-modal .attachments-browser.list .attachments,
.media-frame .attachments-browser.list .attachments {
	list-style: none !important;
	margin: 0 !important;
	padding: 0 !important;
}
.media-modal .attachments-browser.list .attachment,
.media-frame .attachments-browser.list .attachment {
	float: none !important;
	clear: both !important;
	width: 100% !important;
	margin: 0 0 8px !important;
	display: block !important;
	box-sizing: border-box !important;
}
.media-modal .attachments-browser.list .attachment .attachment-preview,
.media-frame .attachments-browser.list .attachment .attachment-preview {
	float: left !important;
	margin-right: 10px !important;
}
CSS;

        wp_register_style('ccr-admin-media-ui', false, ['media-views'], CCR_VERSION);
        wp_enqueue_style('ccr-admin-media-ui');
        wp_add_inline_style('ccr-admin-media-ui', $css);
    }
}
