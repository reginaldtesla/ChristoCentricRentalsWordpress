<?php
/**
 * Image Relation Attacher
 *
 * Handles attaching a WP attachment to all related entities:
 *  - Products (thumbnail + gallery)
 *  - Categories (thumbnail_id)
 *  - Category banners (banner_id)
 *  - Variants (thumbnail + zoo-cw-variation-gallery)
 *  - Sets (product gallery) — collected via the Sets module's
 *    {@see Rental_Sets_Gallery_Collector}; this class only forwards
 *    the per-image input.
 *  - Attribute values (slctd_img / sw_image)
 *  - Brands (thumbnail_id)
 *
 * @package RentopianSync\FileSync
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Rental_Image_Relation_Attacher {

    /**
     * Attach an image to ALL related entities.
     *
     * @param object $image      Image data from the API (->id, ->products, ->variants, ->sets).
     * @param int    $attach_id  WP attachment ID.
     * @param array  &$variant_gallery Accumulator for variant gallery IDs (gallery commit is caller's job).
     * @param array  &$set_gallery     Accumulator for set gallery IDs.
     */
    public static function attach_to_all( $image, $attach_id, &$variant_gallery, &$set_gallery ) {
        $attach_id = (int) $attach_id;
        if ( $attach_id <= 0 ) {
            self::log( 'Attach aborted: invalid attach_id (' . var_export( $attach_id, true ) . ')', 'error' );
            return;
        }

        $img = is_array( $image ) ? (object) $image : $image;
        if ( ! is_object( $img ) ) {
            self::log( "Attach aborted: image payload is not an object (attach_id={$attach_id}, got " . gettype( $img ) . ')', 'error' );
            return;
        }

        $image_id = isset( $img->id ) ? (int) $img->id : 0;
        if ( $image_id <= 0 ) {
            self::log( "Attach aborted: image payload has no usable id (attach_id={$attach_id})", 'error' );
            return;
        }

        // Products
        self::attach_to_products( $img, $attach_id );

        // Categories
        self::attach_to_term_meta( $image_id, $attach_id, 'rental_img_category_rel', 'thumbnail_id' );

        // Category banners
        self::attach_to_term_meta( $image_id, $attach_id, 'rental_banner_img_category_rel', 'banner_id' );

        // Variant main images (thumbnail)
        $variant_main_images = self::attach_variant_thumbnails( $image_id, $attach_id );

        // Attribute value swatches
        self::attach_attribute_swatches( $image_id, $attach_id );

        // Brands
        self::attach_to_term_meta( $image_id, $attach_id, 'rental_img_brand_rel', 'thumbnail_id' );

        // Variant galleries
        self::collect_variant_gallery( $img, $attach_id, $variant_main_images, $variant_gallery );

        // Set galleries — owned by the Sets module.
        Rental_Sets_Gallery_Collector::collect( $img, $attach_id, $variant_main_images, $set_gallery );
    }

    /**
     * Attach to products — thumbnail or gallery.
     *
     * @param object $img
     * @param int    $attach_id
     */
    public static function attach_to_products( $img, $attach_id ) {
        global $wpdb, $rental_tables;

        $image_id = (int) ( $img->id ?? 0 );

        if ( empty( $rental_tables['product_relations'] ) ) {
            self::log( "Product attach skipped for REN ID {$image_id}: product_relations table is not configured", 'error' );
            return;
        }

        if ( empty( $img->products ) ) {
            self::log( "Product attach skipped for REN ID {$image_id}: image carries no products in the API payload", 'warning' );
            return;
        }

        $rental_product_relations = $wpdb->prefix . $rental_tables['product_relations'];

        $product_ids = self::parse_ids( $img->products );
        if ( empty( $product_ids ) ) {
            self::log(
                "Product attach skipped for REN ID {$image_id}: products list could not be parsed into ids -- raw: "
                . substr( is_scalar( $img->products ) ? (string) $img->products : wp_json_encode( $img->products ), 0, 500 ),
                'warning'
            );
            return;
        }

        $in       = implode( ',', array_map( 'intval', $product_ids ) );
        $products = $wpdb->get_results( "SELECT `id` FROM {$rental_product_relations} WHERE `rental_id` IN ({$in})" );

        if ( $wpdb->last_error ) {
            self::log( "Product attach failed for REN ID {$image_id}: {$wpdb->last_error}", 'error' );
            return;
        }

        if ( empty( $products ) ) {
            self::log(
                "Product attach skipped for REN ID {$image_id}: no WP products are mapped to rental ids [{$in}] "
                . "-- the products are missing from {$rental_product_relations}; run a product sync before the file sync",
                'warning'
            );
            return;
        }

        $thumbnail_count = 0;
        $gallery_count   = 0;
        $repaired_count  = 0;

        foreach ( $products as $product ) {
            $pid = (int) ( $product->id ?? 0 );
            if ( $pid <= 0 ) {
                self::log( "Product attach skipped for REN ID {$image_id}: relation row has an empty product id", 'warning' );
                continue;
            }

            $current_thumb = (int) get_post_meta( $pid, '_thumbnail_id', true );

            // The thumbnail slot is free when it is empty, when it still
            // holds the rental placeholder id, or when it names an
            // attachment that no longer exists — the last case is a
            // product rendering an empty image slot, and claiming it here
            // is what repairs it.
            $placeholder = ( $current_thumb > 0 && $current_thumb === $image_id );
            $broken      = ( $current_thumb > 0 && ! $placeholder && ! Rental_Image_Downloader::attachment_is_usable( $current_thumb ) );

            if ( $current_thumb <= 0 || $placeholder || $broken ) {
                update_post_meta( $pid, '_thumbnail_id', $attach_id );
                $thumbnail_count++;

                if ( $broken ) {
                    $repaired_count++;
                    self::log(
                        "Repaired product {$pid}: _thumbnail_id pointed at missing attachment {$current_thumb}, replaced with {$attach_id} (REN ID {$image_id})",
                        'notice'
                    );
                }

                // The new featured image must not also sit in the gallery.
                self::merge_gallery_meta( $pid, '_product_image_gallery', [], [ $attach_id ] );
                continue;
            }

            // Occupied by a live image — this one belongs in the gallery.
            if ( self::merge_gallery_meta( $pid, '_product_image_gallery', [ $attach_id ], [ $current_thumb ] ) ) {
                $gallery_count++;
            }
        }

        self::log(
            sprintf(
                'Attached to products for REN ID: %d (attach_id=%d, matched=%d of rental ids [%s], thumbnail=%d, gallery=%d, repaired=%d)',
                $image_id,
                $attach_id,
                count( $products ),
                $in,
                $thumbnail_count,
                $gallery_count,
                $repaired_count
            )
        );
    }

    /**
     * Merge ids into a gallery meta key, dropping every entry that no
     * longer resolves to a real attachment.
     *
     * Galleries were previously append-only, so one deleted attachment
     * stayed in the list forever and every later run appended around it.
     * Rewriting the whole list on each touch keeps it self-healing.
     *
     * Shared with the Sets module's gallery collector so both galleries
     * obey exactly one set of rules.
     *
     * @param int   $post_id
     * @param string $meta_key
     * @param int[] $add     Ids to append.
     * @param int[] $exclude Ids to keep out (typically the featured image).
     * @return bool TRUE when something was appended.
     */
    public static function merge_gallery_meta( $post_id, $meta_key, array $add, array $exclude = [] ) {
        $existing = get_post_meta( $post_id, $meta_key, true );
        $existing = $existing ? explode( ',', (string) $existing ) : [];

        $clean   = Rental_Image_Downloader::filter_usable( $existing );
        $exclude = array_map( 'intval', $exclude );
        $clean   = array_values( array_diff( $clean, $exclude ) );

        $appended = false;
        foreach ( $add as $id ) {
            $id = (int) $id;
            if ( $id > 0 && ! in_array( $id, $clean, true ) && ! in_array( $id, $exclude, true ) ) {
                $clean[]  = $id;
                $appended = true;
            }
        }

        $value = implode( ',', $clean );

        if ( $value !== (string) get_post_meta( $post_id, $meta_key, true ) ) {
            if ( '' === $value ) {
                delete_post_meta( $post_id, $meta_key );
            } else {
                update_post_meta( $post_id, $meta_key, $value );
            }
        }

        return $appended;
    }

    /**
     * Commit accumulated variant galleries to postmeta.
     *
     * @param array $variant_gallery [ variant_id => [attach_id, ...], ... ]
     */
    public static function commit_variant_galleries( array $variant_gallery ) {
        foreach ( $variant_gallery as $vid => $ids ) {
            $vid = (int) $vid;
            if ( $vid <= 0 ) {
                continue;
            }

            $thumb = (int) get_post_meta( $vid, '_thumbnail_id', true );

            self::merge_gallery_meta(
                $vid,
                'zoo-cw-variation-gallery',
                array_map( 'intval', (array) $ids ),
                $thumb > 0 ? [ $thumb ] : []
            );
        }
    }

    /**
     * Commit accumulated set galleries to postmeta.
     *
     * @deprecated 2.14.7 Set-gallery commit lives in the Sets module now.
     *                    Use {@see Rental_Sets_Gallery_Collector::commit()} directly
     *                    — this method is kept only as a backward-compatible
     *                    pass-through for third-party callers.
     *
     * @param array $set_gallery [ set_id => [attach_id, ...], ... ]
     */
    public static function commit_set_galleries( array $set_gallery ) {
        Rental_Sets_Gallery_Collector::commit( $set_gallery );
    }

    /* ──────────────────────────────────────────────────────────
     * Private helpers
     * ────────────────────────────────────────────────────────── */

    /**
     * Attach to term meta using a relation option.
     */
    private static function attach_to_term_meta( $image_id, $attach_id, $option_name, $meta_key ) {
        $rel = get_option( $option_name );
        if ( $rel === false || ! isset( $rel[ $image_id ] ) ) {
            return;
        }
        foreach ( (array) $rel[ $image_id ] as $term_id ) {
            $term_id = (int) $term_id;
            if ( $term_id > 0 ) {
                update_term_meta( $term_id, $meta_key, $attach_id );
            }
        }
    }

    /**
     * Set variant thumbnails and return which variants were set as main image.
     *
     * @return array [ variant_id => true, ... ]
     */
    private static function attach_variant_thumbnails( $image_id, $attach_id ) {
        $variant_main = [];

        $rel = get_option( 'rental_img_variant_rel' );
        if ( $rel === false || ! isset( $rel[ $image_id ] ) ) {
            return $variant_main;
        }

        foreach ( (array) $rel[ $image_id ] as $variant_id ) {
            $variant_id = (int) $variant_id;
            if ( $variant_id <= 0 ) {
                continue;
            }
            update_post_meta( $variant_id, '_thumbnail_id', $attach_id );
            $variant_main[ $variant_id ] = true;
        }

        return $variant_main;
    }

    /**
     * Update attribute value swatches.
     */
    private static function attach_attribute_swatches( $image_id, $attach_id ) {
        $rel = get_option( 'rental_img_attribute_value_rel' );
        if ( $rel === false || ! isset( $rel[ $image_id ] ) ) {
            return;
        }

        foreach ( (array) $rel[ $image_id ] as $attr_val_id ) {
            $attr_val_id = (int) $attr_val_id;
            if ( $attr_val_id <= 0 ) {
                continue;
            }
            if ( defined( 'ZOO_CW_VERSION' ) ) {
                update_term_meta( $attr_val_id, 'slctd_img', wp_get_attachment_image_url( $attach_id ) );
            }
            if ( defined( 'RENTPRO_SWATCHES_PATH' ) ) {
                update_term_meta( $attr_val_id, 'sw_image', $attach_id );
            }
        }
    }

    /**
     * Collect variant gallery entries (not committed yet — caller commits in bulk).
     */
    private static function collect_variant_gallery( $img, $attach_id, $variant_main_images, &$variant_gallery ) {
        global $wpdb, $rental_tables;

        if ( empty( $img->variants ) || empty( $rental_tables['variant_relations'] ) ) {
            return;
        }

        $rel_table = $wpdb->prefix . $rental_tables['variant_relations'];
        $ids       = self::parse_ids( $img->variants );
        if ( empty( $ids ) ) {
            return;
        }

        $in       = implode( ',', array_map( 'intval', $ids ) );
        $variants = $wpdb->get_results( "SELECT `id` FROM {$rel_table} WHERE `rental_id` IN ({$in})" );

        foreach ( $variants as $variant ) {
            $vid = (int) ( $variant->id ?? 0 );
            if ( $vid <= 0 || isset( $variant_main_images[ $vid ] ) ) {
                continue;
            }
            if ( ! isset( $variant_gallery[ $vid ] ) ) {
                $variant_gallery[ $vid ] = [];
            }
            if ( ! in_array( $attach_id, $variant_gallery[ $vid ], true ) ) {
                $variant_gallery[ $vid ][] = $attach_id;
            }
        }
    }

    /**
     * Parse an ID list that may be JSON-encoded, an array, or a string.
     *
     * @param mixed $raw
     * @return int[]
     */
    private static function parse_ids( $raw ) {
        if ( is_array( $raw ) ) {
            return array_filter( array_map( 'intval', $raw ) );
        }
        if ( is_string( $raw ) ) {
            $decoded = json_decode( stripslashes( $raw ), true );
            if ( is_array( $decoded ) ) {
                return array_filter( array_map( 'intval', $decoded ) );
            }
        }
        return [];
    }

    private static function log( $message, $level = 'info' ) {
        Rental_File_Sync_Logger::write( $message, $level, 'attach' );
    }
}
