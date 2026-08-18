<?php

defined('ABSPATH') || exit;

/**
 * Keep client verification ID uploads out of the shared Media Library pickers
 * (featured image, product gallery, studio hero, etc.).
 */
final class CCR_Private_Media
{
    public const META = '_ccr_private_verification';
    public const SUBDIR = 'ccr-verification';

    public static function init(): void
    {
        add_filter('ajax_query_attachments_args', [self::class, 'exclude_from_media_modal']);
        add_action('pre_get_posts', [self::class, 'exclude_from_media_library']);
        add_action('admin_init', [self::class, 'maybe_tag_existing'], 40);
    }

    /** @param array<string,mixed> $query */
    public static function exclude_from_media_modal(array $query): array
    {
        $meta = isset($query['meta_query']) && is_array($query['meta_query'])
            ? $query['meta_query']
            : [];
        $meta[] = [
            'relation' => 'OR',
            [
                'key' => self::META,
                'compare' => 'NOT EXISTS',
            ],
            [
                'key' => self::META,
                'value' => '1',
                'compare' => '!=',
            ],
        ];
        $query['meta_query'] = $meta;

        return $query;
    }

    public static function exclude_from_media_library(WP_Query $q): void
    {
        if (! is_admin() || ! $q->is_main_query()) {
            return;
        }
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (! $screen || $screen->base !== 'upload') {
            return;
        }
        // Allow a dedicated filter view later; by default hide private verification docs.
        if (isset($_GET['ccr_show_verification']) && (string) $_GET['ccr_show_verification'] === '1') { // phpcs:ignore
            return;
        }

        $meta = (array) $q->get('meta_query');
        $meta[] = [
            'relation' => 'OR',
            [
                'key' => self::META,
                'compare' => 'NOT EXISTS',
            ],
            [
                'key' => self::META,
                'value' => '1',
                'compare' => '!=',
            ],
        ];
        $q->set('meta_query', $meta);
    }

    /**
     * Route verification uploads into uploads/ccr-verification/YYYY/MM/.
     *
     * @param array<string,string> $dirs
     * @return array<string,string>
     */
    public static function upload_dir(array $dirs): array
    {
        $subdir = '/' . self::SUBDIR . ($dirs['subdir'] ?? '');
        $dirs['subdir'] = $subdir;
        $dirs['path'] = ($dirs['basedir'] ?? '') . $subdir;
        $dirs['url'] = ($dirs['baseurl'] ?? '') . $subdir;

        return $dirs;
    }

    public static function mark_attachment(int $attachmentId): void
    {
        if ($attachmentId <= 0) {
            return;
        }
        update_post_meta($attachmentId, self::META, '1');
        wp_update_post([
            'ID' => $attachmentId,
            'post_status' => 'private',
        ]);
    }

    /** Tag existing client-verification attachments once. */
    public static function maybe_tag_existing(): void
    {
        if (get_option('ccr_private_media_tagged') === '2') {
            return;
        }
        if (! current_user_can('manage_options')) {
            return;
        }

        $keys = [
            '_ccr_agreement_id_file',
            '_ccr_agreement_gps_photo',
            '_ccr_agreement_g1_id_file',
            '_ccr_agreement_g2_id_file',
        ];

        global $wpdb;
        $ids = [];
        foreach ($keys as $key) {
            $rows = $wpdb->get_col($wpdb->prepare(
                "SELECT meta_value FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value REGEXP '^[0-9]+$'",
                $key
            ));
            foreach ($rows as $raw) {
                $id = absint($raw);
                if ($id > 0) {
                    $ids[$id] = true;
                }
            }
        }

        // Also catch files already under ccr-verification/.
        $pathRows = $wpdb->get_col($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value LIKE %s",
            '%' . $wpdb->esc_like(self::SUBDIR . '/') . '%'
        ));
        foreach ($pathRows as $raw) {
            $id = absint($raw);
            if ($id > 0) {
                $ids[$id] = true;
            }
        }

        foreach (array_keys($ids) as $attachmentId) {
            self::mark_attachment((int) $attachmentId);
        }

        update_option('ccr_private_media_tagged', '2', false);
    }
}
