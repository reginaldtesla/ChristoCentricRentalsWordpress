<?php
/**
 * Image Downloader
 *
 * Encapsulates the shared logic of downloading a remote image, uploading
 * it to WordPress via wp_upload_bits(), creating an attachment post, and
 * generating sub-sizes.
 *
 * @package RentopianSync\FileSync
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Rental_Image_Downloader {

    /**
     * Download a remote image and create a WP attachment.
     *
     * @param string $url        Signed/direct URL to download.
     * @param int    $rental_id  Remote rental image id.
     * @param string $sync_id    Current sync id (for error attribution).
     * @param object $image_meta Optional image metadata (->mime, ->label, ->description).
     * @return int Attachment ID on success, 0 on failure.
     */
    public static function download_and_attach( $url, $rental_id, $sync_id, $image_meta = null ) {

        if ( !function_exists( 'download_url' ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $rental_id = (int) $rental_id;

        // ── Download ─────────────────────────────────────────
        $tmp_file = download_url($url);
        if ( is_wp_error($tmp_file) ) {
            self::mark_failed( $rental_id, $sync_id, 'download_url failed', $tmp_file->get_error_message() );
            return 0;
        }

        self::log( "Image downloaded for REN ID: {$rental_id}" );

        // ── Upload to WP ─────────────────────────────────────
        try {
            add_filter( 'wp_check_filetype_and_ext', 'rental_bypass_mime_check', 10, 4 );

            $upload = wp_upload_bits( basename( $url ), null, file_get_contents( $tmp_file ) );

            remove_filter( 'wp_check_filetype_and_ext', 'rental_bypass_mime_check', 10 );

            if ( !empty( $upload['error'] ) ) {
                self::mark_failed( $rental_id, $sync_id, 'wp_upload_bits error', $upload['error'] );
                self::cleanup_tmp( $tmp_file );
                return 0;
            }

            if ( !isset( $upload['file'] ) ) {
                self::mark_failed( $rental_id, $sync_id, 'upload[file] missing after wp_upload_bits', '' );
                self::cleanup_tmp( $tmp_file );
                return 0;
            }

        } catch ( Exception $e ) {
            remove_filter( 'wp_check_filetype_and_ext', 'rental_bypass_mime_check', 10 );
            self::mark_failed( $rental_id, $sync_id, 'exception during upload', $e->getMessage() );
            self::cleanup_tmp( $tmp_file );
            return 0;
        }

        // ── Create Attachment Post ───────────────────────────
        $wp_upload_dir = wp_upload_dir();
        $mime          = isset( $image_meta->mime ) ? $image_meta->mime : wp_check_filetype( $upload['file'] )['type'];

        $attachment = [
            'guid'           => $wp_upload_dir['baseurl'] . '/' . _wp_relative_upload_path( $upload['file'] ),
            'post_mime_type' => $mime,
            'post_title'     => sanitize_text_field( isset( $image_meta->label ) ? $image_meta->label : '' ),
            'post_content'   => sanitize_text_field( isset( $image_meta->description ) ? $image_meta->description : '' ),
            'post_status'    => 'inherit',
        ];

        $attach_id = wp_insert_attachment( $attachment, $upload['file'], 0 );

        if ( is_wp_error($attach_id) || !$attach_id ) {
            self::mark_failed( $rental_id, $sync_id, 'wp_insert_attachment failed', is_wp_error( $attach_id ) ? $attach_id->get_error_message() : 'unknown' );
            self::cleanup_tmp( $tmp_file );
            return 0;
        }

        self::log( "wp_insert_attachment done: attach_id={$attach_id} REN_ID={$rental_id}" );

        // ── Generate Sub-sizes ───────────────────────────────
        Rental_Image_Performance_Filter::enable( $upload['file'] );
        try {
            Rental_Image_Subsizer::update_metadata_with_fallback( $attach_id, $upload['file'] );
        } finally {
            Rental_Image_Performance_Filter::disable();
        }

        self::log( "metadata generated: attach_id={$attach_id} REN_ID={$rental_id}" );

        // ── Cleanup ──────────────────────────────────────────
        self::cleanup_tmp($tmp_file);

        return (int) $attach_id;
    }

    /**
     * Whether an attachment id still points at a real, readable image.
     *
     * This is the single authority for that question. A relation row, a
     * `_thumbnail_id` or a gallery entry naming an attachment that was
     * deleted (a legacy truncating sync, a media purge, a half-finished
     * migration) is indistinguishable from a good one until it is checked,
     * and treating it as good is what leaves a product rendering an empty
     * image slot forever.
     *
     * Results are memoised per request because one chunk asks about the
     * same handful of ids many times.
     *
     * @param int $attach_id
     * @return bool
     */
    public static function attachment_is_usable( $attach_id ) {
        static $cache = [];

        $attach_id = (int) $attach_id;
        if ( $attach_id <= 0 ) {
            return false;
        }

        if ( isset( $cache[ $attach_id ] ) ) {
            return $cache[ $attach_id ];
        }

        $usable = false;
        $post   = get_post( $attach_id );

        if ( $post && 'attachment' === $post->post_type ) {
            $file   = get_attached_file( $attach_id );
            $usable = ( $file && file_exists( $file ) );
        }

        $cache[ $attach_id ] = $usable;

        return $usable;
    }

    /**
     * Keep only the ids that still resolve to a usable attachment.
     *
     * @param array $attach_ids
     * @return int[] Re-indexed, unique, order preserved.
     */
    public static function filter_usable( array $attach_ids ) {
        $clean = [];

        foreach ( $attach_ids as $id ) {
            $id = (int) $id;
            if ( $id > 0 && ! in_array( $id, $clean, true ) && self::attachment_is_usable( $id ) ) {
                $clean[] = $id;
            }
        }

        return $clean;
    }

    /**
     * Drop a relation row that points at an attachment which no longer
     * exists, so the next pass re-downloads instead of trusting it again.
     *
     * @param int $rental_id
     * @param int $attach_id
     */
    public static function forget_relation( $rental_id, $attach_id ) {
        global $wpdb, $rental_tables;

        if ( empty( $rental_tables['image_relations'] ) ) {
            return;
        }

        $wpdb->delete(
            $wpdb->prefix . $rental_tables['image_relations'],
            [ 'rental_id' => (int) $rental_id, 'id' => (int) $attach_id ],
            [ '%d', '%d' ]
        );

        self::log( "Pruned stale image relation: REN ID {$rental_id} -> attachment {$attach_id} (attachment is gone)", 'warning' );
    }

    /**
     * Resolve an existing WP attachment for a rental image (used during resync).
     *
     * Tries:
     *  1. rental_image_relations table by rental_id.
     *  2. WP postmeta (_wp_attached_file) by filename.
     *  3. WP posts (guid) by filename.
     *
     * Relation rows that fail the usability check are pruned as they are
     * encountered, so a stale mapping cannot keep winning on every run.
     *
     * @param int    $rental_id
     * @param string $image_url
     * @return int Attachment ID or 0.
     */
    public static function resolve_existing_attachment( $rental_id, $image_url ) {
        global $wpdb, $rental_tables;

        if ( empty( $rental_tables['image_relations'] ) ) {
            return 0;
        }

        $rel_table = $wpdb->prefix . $rental_tables['image_relations'];

        // ── Try via relations table ──────────────────────────
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id FROM {$rel_table} WHERE rental_id = %d ORDER BY id DESC",
            $rental_id
        ) );

        if ( $rows ) {
            foreach ( $rows as $row ) {
                $aid = (int) ( $row->id ?? 0 );
                if ( $aid <= 0 ) {
                    continue;
                }
                if ( self::attachment_is_usable( $aid ) ) {
                    return $aid;
                }
                self::forget_relation( $rental_id, $aid );
            }
        }

        // ── Try by filename ──────────────────────────────────
        $path     = parse_url( $image_url, PHP_URL_PATH );
        $filename = $path ? wp_basename( $path ) : '';

        if ( $filename ) {
            $aid = self::find_attachment_by_filename( $filename );
            if ( $aid ) {
                // Ensure relation row exists
                $wpdb->replace( $rel_table, [
                    'rental_id' => (int) $rental_id,
                    'id'        => (int) $aid,
                ], [ '%d', '%d' ] );
                return $aid;
            }
        }

        return 0;
    }

    /**
     * Find attachment by filename (postmeta or guid).
     *
     * @param string $filename
     * @return int
     */
    public static function find_attachment_by_filename( $filename ) {
        global $wpdb;

        $filename = wp_basename( $filename );
        if ( ! $filename ) {
            return 0;
        }

        $like = '%' . $wpdb->esc_like( $filename ) . '%';

        // Try _wp_attached_file
        $id = $wpdb->get_var( $wpdb->prepare(
            "SELECT p.ID
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_wp_attached_file'
             WHERE p.post_type = 'attachment' AND p.post_status = 'inherit' AND m.meta_value LIKE %s
             ORDER BY p.ID DESC LIMIT 1",
            $like
        ) );

        if ( $id ) {
            return (int) $id;
        }

        // Fallback: guid
        $id2 = $wpdb->get_var( $wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts}
             WHERE post_type = 'attachment' AND guid LIKE %s
             ORDER BY ID DESC LIMIT 1",
            $like
        ) );

        return $id2 ? (int) $id2 : 0;
    }

    /* ──────────────────────────────────────────────────────────
     * Private helpers
     * ────────────────────────────────────────────────────────── */

    private static function mark_failed( $rental_id, $sync_id, $reason, $error_msg ) {
        Rental_Failed_Image_Repository::mark( $rental_id, $sync_id, $error_msg );

        Rental_Sync_Log_Repository::write(
            $sync_id ?: get_option( 'rental_current_sync_id', '' ),
            'error',
            "Image failed id={$rental_id} reason={$reason}",
            [ 'error_msg' => $error_msg ],
            intval( $rental_id ),
            intval( get_option( "rental_products_img_processed_{$sync_id}", 0 ) ),
            intval( get_option( 'rental_products_img_count', 0 ) ),
            0.0,
            Rental_Sync_Status::STATUS_PROCESSING
        );

        if ( class_exists( 'ErrorHandler', false ) && class_exists( 'RentalException', false ) ) {
            ErrorHandler::registerErrorInLog(
                "Following image {$rental_id} has errors : {$error_msg}",
                __FILE__, __LINE__,
                RentalException::TYPE_SYNC_RUNTIME
            );
        }
    }

    private static function cleanup_tmp( $tmp_file ) {
        if ( is_string( $tmp_file ) && file_exists( $tmp_file ) ) {
            @unlink( $tmp_file );
        }
    }

    private static function log( $message, $level = 'info' ) {
        Rental_File_Sync_Logger::write( $message, $level, 'download' );
    }
}
