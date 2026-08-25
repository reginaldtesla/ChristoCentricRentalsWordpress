<?php
/**
 * Rental_Sets_Image_Linker
 *
 * One entry point for "given an API image and a WP attachment ID,
 * accumulate the attachment ID into the set-gallery map for every set
 * the image is attached to". Used during image upload, where the caller
 * holds the outer `$set_image_gallery` accumulator and commits it to
 * `_product_image_gallery` postmeta later.
 *
 * Two live callers in `rental_upload_images()` and
 * `rental_upload_images_stream()` inlined this same loop; they now both
 * call `attach()`. The third copy (inside the dead `rental_upload_images_ORIGIN`)
 * is not wired up — that function has no callers.
 *
 * `$image->sets` can arrive as either:
 *   - an array of rental set IDs, or
 *   - a JSON-encoded string of rental set IDs (possibly slashed)
 * Both shapes are normalized through `_rental_parse_ids()` when available,
 * falling back to stripslashes+json_decode.
 *
 * @package RentopianSync\Sets
 * @since   2.14.7
 *
 * PHP requirement: 7.4+
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'Rental_Sets_Image_Linker', false ) ) :

class Rental_Sets_Image_Linker {

    /**
     * Append $attach_id to every set bucket in $set_image_gallery that
     * this image applies to. Sets that already have a main image (i.e.
     * are keys in $variant_main_images) are skipped — they get the
     * single featured image, not an extra gallery entry.
     *
     * @param object|array $image                Image payload (needs ->sets).
     * @param int          $attach_id            WP attachment ID.
     * @param array        $variant_main_images  wp_set_id => true for sets that already took this attach_id as their featured image.
     * @param array        &$set_image_gallery   Accumulator: wp_set_id => "id1,id2,..." (mutated in place).
     * @param Rental_Sets_Tables|null $tables    Optional injected tables helper.
     * @return void
     */
    public static function attach( $image, $attach_id, $variant_main_images, &$set_image_gallery, $tables = null ) {
        $img = is_array( $image ) ? (object) $image : $image;
        if ( ! is_object( $img ) || empty( $img->sets ) ) {
            return;
        }

        $attach_id = (int) $attach_id;
        if ( $attach_id <= 0 ) {
            return;
        }

        $tables = ( $tables instanceof Rental_Sets_Tables ) ? $tables : Rental_Sets_Tables::instance();
        $wpdb   = $tables->db();
        $table  = $tables->set_relations();

        $rental_ids = self::normalize_rental_ids( $img->sets );
        if ( empty( $rental_ids ) ) {
            return;
        }

        // The IN list is built from integers only (see normalize_rental_ids),
        // so direct interpolation is safe here
        $id_list = implode( ',', $rental_ids );
        $rows = $wpdb->get_results( "SELECT `id` FROM {$table} WHERE `rental_id` IN ({$id_list})" );
        if ( empty( $rows ) ) {
            return;
        }

        foreach ( $rows as $row ) {
            $wp_set_id = isset( $row->id ) ? (int) $row->id : 0;
            if ( $wp_set_id <= 0 ) {
                continue;
            }

            // Already the featured image for this set — don't also put it in the gallery.
            if ( isset( $variant_main_images[ $wp_set_id ] ) ) {
                continue;
            }

            if ( isset( $set_image_gallery[ $wp_set_id ] ) ) {
                $set_image_gallery[ $wp_set_id ] .= ',' . $attach_id;
            } else {
                $set_image_gallery[ $wp_set_id ] = (string) $attach_id;
            }
        }
    }

    /**
     * Convert $image->sets (array or JSON-string) into an int[] of rental IDs.
     *
     * @param mixed $raw $image->sets value as it arrived from the API layer.
     * @return int[] Non-empty, positive, de-duplicated rental set IDs.
     */
    protected static function normalize_rental_ids( $raw ) {
        if ( is_array( $raw ) ) {
            // Prefer the project's parser if it's loaded — it handles the
            if ( function_exists( '_rental_parse_ids' ) ) {
                $ids = _rental_parse_ids( $raw );
            } else {
                $ids = $raw;
            }
        } else {
            $decoded = json_decode( stripslashes( (string) $raw ) );
            $ids = is_array( $decoded ) ? $decoded : [];
        }

        $clean = [];
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
