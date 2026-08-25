<?php
/**
 * Image Integrity
 *
 * Repairs image references that point at attachments which no longer
 * exist.
 *
 * Fixing the code that creates dangling references does not heal the ones
 * already stored, and a dangling `_thumbnail_id` is invisible in the admin
 * — the product simply renders an empty image slot while `has_post_thumbnail()`
 * still reports true. This pass finds and clears them, so the next file
 * sync sees an empty slot and fills it.
 *
 * Order matters: run {@see repair()} BEFORE the file phase (it clears the
 * wreckage and prunes stale relations so images are re-downloaded), and
 * {@see promote_gallery_images()} AFTER it (anything still missing a
 * featured image can borrow one from its own gallery).
 *
 * @package RentopianSync\FileSync
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Rental_Image_Integrity {

    /**
     * Post types carrying image references this pass owns.
     */
    const POST_TYPES = [ 'product', 'product_variation' ];

    /**
     * Gallery meta keys, in the order they are repaired.
     */
    const GALLERY_KEYS = [ '_product_image_gallery', 'zoo-cw-variation-gallery' ];

    /**
     * Clear every image reference that cannot render.
     *
     * @return array Counters describing what was repaired.
     */
    public static function repair() {
        $report = [
            'thumbnails_cleared'  => self::clear_broken_thumbnails(),
            'galleries_pruned'    => self::prune_broken_galleries(),
            'orphan_meta_deleted' => self::prune_orphan_meta(),
            'relations_pruned'    => self::prune_orphan_relations(),
            'terms_cleared'       => self::clear_broken_term_images(),
        ];

        $report['total'] = array_sum( $report );

        if ( $report['total'] > 0 ) {
            self::log( 'Image integrity repair: ' . wp_json_encode( $report ), 'notice' );
        }

        return $report;
    }

    /**
     * Give a featured image to anything that has none but does have a
     * usable gallery image. Runs after the file phase, when every image
     * that could be downloaded has been.
     *
     * @return int Number of posts given a featured image.
     */
    public static function promote_gallery_images() {
        global $wpdb;

        $types     = self::type_in();
        $promoted  = 0;

        $candidates = $wpdb->get_results(
            "SELECT p.ID, pm.meta_key, pm.meta_value
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm
                     ON pm.post_id = p.ID
                    AND pm.meta_key IN ('_product_image_gallery', 'zoo-cw-variation-gallery')
                    AND pm.meta_value <> ''
             WHERE p.post_type IN ({$types})
               AND p.post_status IN ('publish', 'private', 'draft')
               AND NOT EXISTS (
                   SELECT 1 FROM {$wpdb->postmeta} t
                   WHERE t.post_id = p.ID AND t.meta_key = '_thumbnail_id' AND t.meta_value <> ''
               )",
            ARRAY_A
        );

        foreach ( $candidates as $row ) {
            $usable = Rental_Image_Downloader::filter_usable( explode( ',', (string) $row['meta_value'] ) );
            if ( empty( $usable ) ) {
                continue;
            }

            update_post_meta( (int) $row['ID'], '_thumbnail_id', $usable[0] );
            $promoted++;
        }

        if ( $promoted > 0 ) {
            self::log( "Promoted a gallery image to featured image on {$promoted} post(s)", 'notice' );
        }

        return $promoted;
    }

    /**
     * Products left with nothing to display. These need attention
     * upstream — the image is missing in Rentopian, or its download failed
     * every time.
     *
     * Variations are only reported when their parent has no image either:
     * a variation without its own image falls back to the parent's, which
     * is normal and would otherwise flood the report.
     *
     * @param int $limit
     * @return array[] { id, title, type }
     */
    public static function posts_without_images( $limit = 50 ) {
        global $wpdb;

        $types = self::type_in();

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT p.ID, p.post_title, p.post_type
             FROM {$wpdb->posts} p
             WHERE p.post_type IN ({$types})
               AND p.post_status = 'publish'
               AND NOT EXISTS (
                   SELECT 1 FROM {$wpdb->postmeta} t
                   WHERE t.post_id = p.ID AND t.meta_key = '_thumbnail_id' AND t.meta_value <> ''
               )
               AND NOT EXISTS (
                   SELECT 1 FROM {$wpdb->postmeta} pt
                   WHERE pt.post_id = p.post_parent AND pt.meta_key = '_thumbnail_id' AND pt.meta_value <> ''
               )
             ORDER BY p.ID ASC
             LIMIT %d",
            $limit
        ), ARRAY_A );

        return array_map( static function ( $r ) {
            return [ 'id' => (int) $r['ID'], 'title' => $r['post_title'], 'type' => $r['post_type'] ];
        }, (array) $rows );
    }

    /* ──────────────────────────────────────────────────────────
     * Repair steps
     * ────────────────────────────────────────────────────────── */

    /**
     * Delete `_thumbnail_id` rows that are empty or name a missing
     * attachment. An empty row is as harmful as a dangling one: it makes
     * the "does this product have an image?" query answer yes.
     *
     * @return int
     */
    private static function clear_broken_thumbnails() {
        global $wpdb;

        $types = self::type_in();

        $rows = $wpdb->get_results(
            "SELECT pm.post_id, pm.meta_value
             FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE pm.meta_key = '_thumbnail_id'
               AND p.post_type IN ({$types})",
            ARRAY_A
        );

        $cleared = 0;

        foreach ( $rows as $row ) {
            $attach_id = (int) $row['meta_value'];

            if ( $attach_id > 0 && Rental_Image_Downloader::attachment_is_usable( $attach_id ) ) {
                continue;
            }

            delete_post_meta( (int) $row['post_id'], '_thumbnail_id' );
            $cleared++;
        }

        return $cleared;
    }

    /**
     * Rewrite gallery meta without the ids that no longer resolve.
     *
     * @return int Number of gallery values changed.
     */
    private static function prune_broken_galleries() {
        global $wpdb;

        $types   = self::type_in();
        $keys    = "'" . implode( "','", array_map( 'esc_sql', self::GALLERY_KEYS ) ) . "'";
        $changed = 0;

        $rows = $wpdb->get_results(
            "SELECT pm.post_id, pm.meta_key, pm.meta_value
             FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE pm.meta_key IN ({$keys})
               AND pm.meta_value <> ''
               AND p.post_type IN ({$types})",
            ARRAY_A
        );

        foreach ( $rows as $row ) {
            $original = (string) $row['meta_value'];
            $usable   = Rental_Image_Downloader::filter_usable( explode( ',', $original ) );
            $value    = implode( ',', $usable );

            if ( $value === $original ) {
                continue;
            }

            if ( '' === $value ) {
                delete_post_meta( (int) $row['post_id'], $row['meta_key'] );
            } else {
                update_post_meta( (int) $row['post_id'], $row['meta_key'], $value );
            }

            $changed++;
        }

        return $changed;
    }

    /**
     * Delete our image meta rows whose post no longer exists.
     *
     * The other repair steps join against `posts` to scope themselves to
     * products, which by construction cannot see meta left behind by a
     * deleted post. Those rows are invisible everywhere but keep a
     * reference to a dead attachment alive, so they are swept separately —
     * bounded to the meta keys this module owns.
     *
     * @return int
     */
    private static function prune_orphan_meta() {
        global $wpdb;

        $keys = "'" . implode( "','", array_map( 'esc_sql', array_merge( self::GALLERY_KEYS, [ '_thumbnail_id' ] ) ) ) . "'";

        return (int) $wpdb->query(
            "DELETE pm FROM {$wpdb->postmeta} pm
             LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE pm.meta_key IN ({$keys})
               AND p.ID IS NULL"
        );
    }

    /**
     * Delete relation rows whose attachment is gone, so the next sync
     * re-downloads instead of trusting the mapping again.
     *
     * @return int
     */
    private static function prune_orphan_relations() {
        global $wpdb, $rental_tables;

        if ( empty( $rental_tables['image_relations'] ) ) {
            return 0;
        }

        $table = $wpdb->prefix . $rental_tables['image_relations'];
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return 0;
        }

        // Rows whose target is not an attachment post at all can go in one
        // statement; the rest are checked individually for a missing file.
        $deleted = (int) $wpdb->query(
            "DELETE r FROM {$table} r
             LEFT JOIN {$wpdb->posts} p ON p.ID = r.id AND p.post_type = 'attachment'
             WHERE p.ID IS NULL"
        );

        foreach ( $wpdb->get_results( "SELECT id, rental_id FROM {$table}", ARRAY_A ) as $row ) {
            if ( ! Rental_Image_Downloader::attachment_is_usable( (int) $row['id'] ) ) {
                $wpdb->delete( $table, [ 'id' => (int) $row['id'], 'rental_id' => (int) $row['rental_id'] ], [ '%d', '%d' ] );
                $deleted++;
            }
        }

        return $deleted;
    }

    /**
     * Category, banner and brand images live in term meta and rot the same
     * way.
     *
     * @return int
     */
    private static function clear_broken_term_images() {
        global $wpdb;

        $cleared = 0;

        $rows = $wpdb->get_results(
            "SELECT term_id, meta_key, meta_value
             FROM {$wpdb->termmeta}
             WHERE meta_key IN ('thumbnail_id', 'banner_id', 'sw_image')
               AND meta_value <> ''",
            ARRAY_A
        );

        foreach ( $rows as $row ) {
            if ( Rental_Image_Downloader::attachment_is_usable( (int) $row['meta_value'] ) ) {
                continue;
            }

            delete_term_meta( (int) $row['term_id'], $row['meta_key'] );
            $cleared++;
        }

        return $cleared;
    }

    /* ──────────────────────────────────────────────────────────
     * Helpers
     * ────────────────────────────────────────────────────────── */

    /**
     * @return string Quoted post-type list for an IN clause.
     */
    private static function type_in() {
        return "'" . implode( "','", array_map( 'esc_sql', self::POST_TYPES ) ) . "'";
    }

    private static function log( $message, $level = 'info' ) {
        if ( class_exists( 'Rental_File_Sync_Logger' ) ) {
            Rental_File_Sync_Logger::write( $message, $level, 'integrity' );
        }
        if ( class_exists( 'Rental_Data_Sync_Logger' ) ) {
            Rental_Data_Sync_Logger::write( $message, $level, 'integrity' );
        }
    }
}
