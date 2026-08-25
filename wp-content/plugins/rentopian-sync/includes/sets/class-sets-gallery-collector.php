<?php
/**
 * Rental_Sets_Gallery_Collector
 *
 * Owns the "set product gallery" assembly for the file-sync subsystem.
 * Two responsibilities, both bounded to the Sets module:
 *
 *   1. {@see collect()}  — given a remote image payload and a freshly
 *      attached WP attachment ID, append the attachment to the in-memory
 *      `wp_set_id => [attach_id, …]` accumulator that the calling
 *      uploader maintains across a chunk.
 *
 *   2. {@see commit()}   — write the accumulated buckets to each set
 *      post's `_product_image_gallery` postmeta in one bulk pass at the
 *      end of the chunk, merging with any pre-existing IDs and avoiding
 *      duplicates.
 *
 * Historically these helpers lived inside
 * `Rental_Image_Relation_Attacher` (file-sync module). They have been
 * relocated here so that everything related to the Set entity is
 * authored, evolved and reasoned about in one place. The original class
 * now delegates to this collector, preserving backward compatibility
 * for any third-party code that called the legacy public method.
 *
 * Note on shape vs. {@see Rental_Sets_Image_Linker}:
 * `Rental_Sets_Image_Linker::attach()` writes a CSV string accumulator
 * (`wp_set_id => "id1,id2,…"`) and is consumed by the streaming
 * `rental_upload_images*` legacy paths. This collector keeps the
 * accumulator as an array of integers, which is what the OOP file-sync
 * uploaders expect. We intentionally keep both flavours rather than
 * forcing one caller to translate.
 *
 * @package RentopianSync\Sets
 * @since   2.14.7
 *
 * PHP requirement: 7.4+
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'Rental_Sets_Gallery_Collector', false ) ) :

class Rental_Sets_Gallery_Collector {

    /**
     * Append `$attach_id` to the per-set bucket inside `$set_gallery`
     * for every WP set whose `rental_id` is referenced by `$image->sets`.
     *
     * Sets that already received this attachment as their featured image
     * (recorded in `$variant_main_images` keyed by WP post ID) are
     * skipped — we do not want a duplicate gallery entry for the
     * featured image.
     *
     * The accumulator is mutated in place; the caller commits it later
     * via {@see commit()}.
     *
     * @param object|array         $image                Image payload (must expose `->sets`).
     * @param int                  $attach_id            WP attachment ID.
     * @param array<int,bool>      $variant_main_images  WP set IDs that already have this attachment as featured.
     * @param array<int,int[]>     $set_gallery          Accumulator (mutated): wp_set_id => [attach_id, …].
     * @param Rental_Sets_Tables|null $tables            Optional injected tables helper (for tests).
     * @return void
     */
    public static function collect( $image, $attach_id, $variant_main_images, &$set_gallery, $tables = null ) {
        $img       = is_array( $image ) ? (object) $image : $image;
        $attach_id = (int) $attach_id;

        if ( ! is_object( $img ) || $attach_id <= 0 || empty( $img->sets ) ) {
            return;
        }

        $tables = ( $tables instanceof Rental_Sets_Tables )
            ? $tables
            : Rental_Sets_Tables::instance();

        $rel_table = $tables->set_relations();
        if ( '' === $rel_table ) {
            return;
        }

        $rental_ids = self::normalize_rental_ids( $img->sets );
        if ( empty( $rental_ids ) ) {
            return;
        }

        $wpdb = $tables->db();
        // The IN list is built from positive integers only — safe to interpolate.
        $in   = implode( ',', $rental_ids );
        $rows = $wpdb->get_results( "SELECT `id` FROM {$rel_table} WHERE `rental_id` IN ({$in})" );

        if ( empty( $rows ) ) {
            return;
        }

        foreach ( $rows as $row ) {
            $sid = isset( $row->id ) ? (int) $row->id : 0;
            if ( $sid <= 0 ) {
                continue;
            }

            // Already used as featured image — don't also push to gallery.
            if ( isset( $variant_main_images[ $sid ] ) ) {
                continue;
            }

            if ( ! isset( $set_gallery[ $sid ] ) ) {
                $set_gallery[ $sid ] = array();
            }

            if ( ! in_array( $attach_id, $set_gallery[ $sid ], true ) ) {
                $set_gallery[ $sid ][] = $attach_id;
            }
        }
    }

    /**
     * Persist the accumulated set-gallery buckets to postmeta.
     *
     * Each bucket is merged with any pre-existing
     * `_product_image_gallery` IDs, deduplicated, and written back as a
     * CSV string — matching the format WooCommerce/the rest of the
     * plugin reads.
     *
     * Entries naming an attachment that no longer exists are dropped on
     * the way through, so a set gallery cannot accumulate dead ids across
     * runs. The rule lives in the file-sync attacher so sets, products and
     * variants all behave identically.
     *
     * @param array<int,int[]> $set_gallery wp_set_id => [attach_id, …]
     * @return void
     */
    public static function commit( array $set_gallery ) {
        if ( empty( $set_gallery ) ) {
            return;
        }

        foreach ( $set_gallery as $sid => $ids ) {
            $sid = (int) $sid;
            if ( $sid <= 0 ) {
                continue;
            }

            $ids = array_unique( array_filter( array_map( 'intval', (array) $ids ) ) );
            if ( empty( $ids ) ) {
                continue;
            }

            $thumb = (int) get_post_meta( $sid, '_thumbnail_id', true );

            Rental_Image_Relation_Attacher::merge_gallery_meta(
                $sid,
                '_product_image_gallery',
                $ids,
                $thumb > 0 ? array( $thumb ) : array()
            );
        }
    }

    /**
     * Normalise the `$image->sets` payload into an int[] of unique,
     * positive rental set IDs. Accepts either an already-decoded array
     * or a JSON-encoded string (possibly slashed).
     *
     * Prefers the project-wide `_rental_parse_ids()` helper when it has
     * been loaded so the rules stay in one place. Falls back to
     * stripslashes + json_decode for edge invocations that occur before
     * `functions.php` is loaded (e.g. during plugin activation).
     *
     * @param mixed $raw Raw `$image->sets`.
     * @return int[]
     */
    protected static function normalize_rental_ids( $raw ) {
        if ( is_array( $raw ) ) {
            $ids = function_exists( '_rental_parse_ids' ) ? _rental_parse_ids( $raw ) : $raw;
        } elseif ( is_string( $raw ) ) {
            if ( function_exists( '_rental_parse_ids' ) ) {
                $ids = _rental_parse_ids( $raw );
            } else {
                $decoded = json_decode( stripslashes( $raw ), true );
                $ids     = is_array( $decoded ) ? $decoded : array();
            }
        } else {
            return array();
        }

        $clean = array();
        foreach ( $ids as $id ) {
            $id = (int) $id;
            if ( $id > 0 && ! in_array( $id, $clean, true ) ) {
                $clean[] = $id;
            }
        }
        return $clean;
    }
}

endif;
