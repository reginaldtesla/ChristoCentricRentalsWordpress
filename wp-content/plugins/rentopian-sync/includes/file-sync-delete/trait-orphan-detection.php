<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Trait: Orphan Detection (v3)
 *
 * Centralised logic to determine whether a WordPress attachment is "used"
 * somewhere on the site. Three strictness levels are provided:
 *
 *  • is_product_related()         – image belongs to a WooCommerce product/variation
 *  • is_used_outside_products()   – image is referenced in non-product content
 *  • is_completely_unused()       – image is not referenced anywhere at all
 *
 * Plus a diagnostic helper:
 *
 *  • get_usage_info()             – returns the FIRST reason an image was flagged
 *                                   as "in use" (or null if unused). Used by the
 *                                   REST endpoints to log *why* an image was
 *                                   skipped, so you can trust the skip list.
 *
 * Every public method returns a boolean (or an array for the diagnostic) and
 * never mutates state.
 *
 * DESIGN NOTES
 * ============
 *
 *  - We do NOT use the bare attachment ID as a LIKE pattern. Numeric IDs
 *    collide with prices, stock, SKUs, phone numbers, Divi numeric shortcode
 *    attributes, z-indexes, etc.
 *
 *  - Exact-ID matches are performed only against a WHITELIST of meta_keys
 *    that are known to hold bare attachment IDs (_thumbnail_id, ACF image
 *    fields, term thumbnails, ...). Everything else uses URL / filename
 *    LIKE matching against the appropriate column.
 *
 *  - "Dead parent" handling: an attachment with post_parent pointing to a
 *    post that no longer exists is treated as effectively unattached. This
 *    is the common after-product-deletion scenario.
 *
 *  - Divi-specific option blobs (`et_divi`, `et_theme_builder`, Divi Library
 *    layouts stored in post_content) are covered explicitly.
 *
 * @package RentopianSync\FileSyncDelete
 */
trait Rental_Orphan_Detection {

    /* ------------------------------------------------------------------ */
    /*  Pattern + whitelist helpers                                        */
    /* ------------------------------------------------------------------ */

    /**
     * Build an array of textual search patterns for a given attachment.
     *
     * The bare attachment ID is intentionally excluded. Use the whitelisted
     * meta-key checks for exact-ID matches.
     *
     * @param int $attachment_id
     * @return array  [ full_url, relative_path, filename ]
     */
    private static function build_search_patterns( $attachment_id ) {
        $attachment_url = wp_get_attachment_url( $attachment_id );
        if ( ! $attachment_url ) {
            return [];
        }

        $upload_dir    = wp_upload_dir();
        $base_url      = isset( $upload_dir['baseurl'] ) ? $upload_dir['baseurl'] : '';
        $relative_path = $base_url ? str_replace( $base_url, '', $attachment_url ) : '';
        $filename      = basename( $attachment_url );

        $patterns = array_filter( [
            $attachment_url,
            $relative_path,
            $filename,
        ] );

        /**
         * Filter the LIKE patterns used for attachment usage checks.
         *
         * @param string[] $patterns
         * @param int      $attachment_id
         */
        return apply_filters( 'rental_orphan_search_patterns', $patterns, $attachment_id );
    }

    /**
     * Postmeta keys known to store a bare attachment ID as meta_value.
     *
     * @return string[]
     */
    private static function get_attachment_id_meta_keys() {
        $keys = [
            '_thumbnail_id',
            '_product_image_gallery',
            'zoo-cw-variation-gallery',
            // Common ACF image field names
            'image',
            'featured_image',
            'gallery',
            'hero_image',
            'background_image',
            'logo',
            'icon',
            // Rentopian
            '_rental_image_id',
            '_rental_featured_image',
        ];

        return (array) apply_filters( 'rental_orphan_attachment_id_meta_keys', $keys );
    }

    /**
     * Termmeta keys known to store a bare attachment ID.
     *
     * @return string[]
     */
    private static function get_attachment_id_term_meta_keys() {
        $keys = [
            'thumbnail_id',
            'banner_id',
            'sw_image',
            'slctd_img',
            'product_cat_thumbnail_id',
            'category_image',
        ];

        return (array) apply_filters( 'rental_orphan_attachment_id_term_meta_keys', $keys );
    }

    /**
     * Option names that can hold attachment references as serialized/JSON blobs.
     *
     * @return string[]
     */
    private static function get_option_names_with_attachments() {
        $keys = [
            // Divi
            'et_divi',
            'et_theme_builder',
            'et_theme_builder_settings',
            'et_extra',
            'et_bloom',
            'et_monarch',
            // Elementor
            'elementor_active_kit',
        ];

        // Active theme's theme_mods
        $sheet    = get_option( 'stylesheet' );
        $template = get_option( 'template' );
        if ( $sheet ) {
            $keys[] = 'theme_mods_' . $sheet;
        }
        if ( $template && $template !== $sheet ) {
            $keys[] = 'theme_mods_' . $template;
        }

        return (array) apply_filters( 'rental_orphan_option_names_with_attachments', $keys );
    }

    /**
     * Runs a LIKE check for every pattern against a SQL template with one %s.
     */
    private static function patterns_match_sql( array $patterns, $sql_template ) {
        global $wpdb;
        foreach ( $patterns as $pattern ) {
            if ( empty( $pattern ) ) {
                continue;
            }
            $result = $wpdb->get_var(
                $wpdb->prepare( $sql_template, '%' . $wpdb->esc_like( $pattern ) . '%' )
            );
            if ( $result > 0 ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Runs a LIKE check for every pattern against a SQL template with two %s.
     */
    private static function patterns_match_sql_pair( array $patterns, $sql_template ) {
        global $wpdb;
        foreach ( $patterns as $pattern ) {
            if ( empty( $pattern ) ) {
                continue;
            }
            $like   = '%' . $wpdb->esc_like( $pattern ) . '%';
            $result = $wpdb->get_var( $wpdb->prepare( $sql_template, $like, $like ) );
            if ( $result > 0 ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Precise gallery-shortcode / ids="..." check. Uses four anchor patterns
     * so arbitrary numeric substrings never match.
     *
     * @param int    $attachment_id
     * @param string $post_type_not_in_sql e.g. "('attachment','revision','nav_menu_item')"
     * @return bool
     */
    private static function gallery_shortcode_uses_id( $attachment_id, $post_type_not_in_sql ) {
        global $wpdb;

        $id = (int) $attachment_id;
        if ( $id <= 0 ) {
            return false;
        }

        $like_patterns = [
            'ids="' . $id . '"',
            'ids="' . $id . ',',
            ',' . $id . ',',
            ',' . $id . '"',
        ];

        $sql = "
            SELECT COUNT(*) FROM {$wpdb->posts}
            WHERE post_type NOT IN {$post_type_not_in_sql}
              AND post_status NOT IN ('trash','auto-draft')
              AND post_content LIKE %s
        ";

        foreach ( $like_patterns as $pat ) {
            $count = $wpdb->get_var(
                $wpdb->prepare( $sql, '%' . $wpdb->esc_like( $pat ) . '%' )
            );
            if ( $count > 0 ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Returns ['id' => <int>, 'status' => <string>] for the attachment's parent,
     * or null if no parent or parent is dead (row no longer in wp_posts).
     *
     * IMPORTANT: an auto-draft or trashed parent counts as "dead" for our
     * purposes — the attachment is effectively unused.
     *
     * @param int $attachment_id
     * @return array|null
     */
    private static function get_live_parent( $attachment_id ) {
        global $wpdb;

        $parent_id = (int) wp_get_post_parent_id( $attachment_id );
        if ( $parent_id <= 0 ) {
            return null;
        }

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT ID, post_type, post_status FROM {$wpdb->posts} WHERE ID = %d",
            $parent_id
        ), ARRAY_A );

        if ( ! $row ) {
            return null; // dead parent — row no longer exists
        }

        if ( in_array( $row['post_status'], [ 'trash', 'auto-draft' ], true ) ) {
            return null; // parent is trashed — treat as dead
        }

        return [
            'id'        => (int) $row['ID'],
            'post_type' => $row['post_type'],
            'status'    => $row['post_status'],
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  Product-relatedness check                                          */
    /* ------------------------------------------------------------------ */

    /**
     * Is this attachment directly related to a WooCommerce product?
     *
     * Handles dead parents: an attachment whose post_parent points to a
     * deleted/trashed post is NOT considered product-related just because
     * of the parent. Only real, live product/variation references count.
     *
     * @param int $attachment_id
     * @return bool
     */
    private static function is_product_related( $attachment_id ) {
        global $wpdb;
        $attachment_id = (int) $attachment_id;

        $pp = $wpdb->posts;
        $pm = $wpdb->postmeta;

        // 1. Live parent is product/variation
        $parent = self::get_live_parent( $attachment_id );
        if ( $parent && in_array( $parent['post_type'], [ 'product', 'product_variation' ], true ) ) {
            return true;
        }

        // 2. Referenced as _thumbnail_id on a live product
        $thumb = $wpdb->get_var( $wpdb->prepare( "
            SELECT COUNT(*) FROM {$pm} pm
            INNER JOIN {$pp} p ON p.ID = pm.post_id
            WHERE pm.meta_key = '_thumbnail_id'
              AND pm.meta_value = %d
              AND p.post_type IN ('product','product_variation')
              AND p.post_status NOT IN ('trash','auto-draft')
        ", $attachment_id ) );
        if ( $thumb > 0 ) {
            return true;
        }

        // 3. Referenced in product gallery CSV meta (live product)
        $gallery = $wpdb->get_var( $wpdb->prepare( "
            SELECT COUNT(*) FROM {$pm} pm
            INNER JOIN {$pp} p ON p.ID = pm.post_id
            WHERE pm.meta_key IN ('_product_image_gallery','zoo-cw-variation-gallery')
              AND FIND_IN_SET(%d, pm.meta_value)
              AND p.post_type IN ('product','product_variation')
              AND p.post_status NOT IN ('trash','auto-draft')
        ", $attachment_id ) );
        if ( $gallery > 0 ) {
            return true;
        }

        return false;
    }

    /* ------------------------------------------------------------------ */
    /*  Non-product usage check (for the "product images" cleaner)         */
    /* ------------------------------------------------------------------ */

    /**
     * Is this attachment used in non-product contexts?
     *
     * @param int $attachment_id
     * @return bool  TRUE = used outside products (do NOT delete).
     */
    private static function is_used_outside_products( $attachment_id ) {
        return null !== self::get_outside_product_reason( $attachment_id );
    }

    /**
     * Returns the first reason this attachment is considered "used outside
     * products", or null if no non-product usage is found.
     *
     * @param int $attachment_id
     * @return string|null
     */
    private static function get_outside_product_reason( $attachment_id ) {
        global $wpdb;
        $attachment_id = (int) $attachment_id;
        $patterns      = self::build_search_patterns( $attachment_id );

        if ( empty( $patterns ) ) {
            return null;
        }

        $pp = $wpdb->posts;
        $pm = $wpdb->postmeta;

        // Featured image on non-product posts
        $featured = $wpdb->get_var( $wpdb->prepare( "
            SELECT COUNT(*) FROM {$pm} pm
            INNER JOIN {$pp} p ON p.ID = pm.post_id
            WHERE pm.meta_key = '_thumbnail_id'
              AND pm.meta_value = %d
              AND p.post_type NOT IN ('product','product_variation')
              AND p.post_status NOT IN ('trash','auto-draft')
        ", $attachment_id ) );
        if ( $featured > 0 ) {
            return 'featured_on_non_product';
        }

        // Post content / excerpt (non-product)
        $content_sql = "
            SELECT COUNT(*) FROM {$pp}
            WHERE post_type NOT IN ('attachment','product','product_variation','revision','nav_menu_item')
              AND post_status NOT IN ('trash','auto-draft')
              AND ( post_content LIKE %s OR post_excerpt LIKE %s )
        ";
        if ( self::patterns_match_sql_pair( $patterns, $content_sql ) ) {
            return 'post_content_non_product';
        }

        // Elementor (non-product)
        $elementor_sql = "
            SELECT COUNT(*) FROM {$pm} pm
            INNER JOIN {$pp} p ON p.ID = pm.post_id
            WHERE pm.meta_key IN ('_elementor_data','_elementor_css')
              AND pm.meta_value LIKE %s
              AND p.post_type NOT IN ('product','product_variation')
              AND p.post_status NOT IN ('trash','auto-draft')
        ";
        if ( self::patterns_match_sql( $patterns, $elementor_sql ) ) {
            return 'elementor_non_product';
        }

        // WPBakery (non-product)
        $wpb_sql = "
            SELECT COUNT(*) FROM {$pm} pm
            INNER JOIN {$pp} p ON p.ID = pm.post_id
            WHERE pm.meta_key IN ('_wpb_shortcodes_custom_css','_wpb_post_custom_css','vcv-pageContent','_vcv-pageContent')
              AND pm.meta_value LIKE %s
              AND p.post_type NOT IN ('product','product_variation')
              AND p.post_status NOT IN ('trash','auto-draft')
        ";
        if ( self::patterns_match_sql( $patterns, $wpb_sql ) ) {
            return 'wpbakery_non_product';
        }

        // Live non-product parent
        $parent = self::get_live_parent( $attachment_id );
        if ( $parent && ! in_array( $parent['post_type'], [ 'product', 'product_variation' ], true ) ) {
            return 'parent_non_product';
        }

        // Precise gallery shortcode (non-product)
        if ( self::gallery_shortcode_uses_id(
            $attachment_id,
            "('attachment','product','product_variation','revision','nav_menu_item')"
        ) ) {
            return 'gallery_shortcode_non_product';
        }

        // Whitelisted postmeta exact-ID (non-product)
        $meta_keys = self::get_attachment_id_meta_keys();
        if ( ! empty( $meta_keys ) ) {
            $placeholders = implode( ',', array_fill( 0, count( $meta_keys ), '%s' ) );
            $args         = array_merge( $meta_keys, [ (string) $attachment_id ] );

            $sql = "
                SELECT COUNT(*) FROM {$pm} pm
                INNER JOIN {$pp} p ON p.ID = pm.post_id
                WHERE pm.meta_key IN ({$placeholders})
                  AND pm.meta_value = %s
                  AND p.post_type NOT IN ('product','product_variation')
                  AND p.post_status NOT IN ('trash','auto-draft')
            ";
            $hit = $wpdb->get_var( $wpdb->prepare( $sql, ...$args ) );
            if ( $hit > 0 ) {
                return 'whitelisted_meta_non_product';
            }
        }

        // Whitelisted FIND_IN_SET (CSV lists)
        $csv_keys = [ '_product_image_gallery', 'zoo-cw-variation-gallery' ];
        foreach ( $csv_keys as $key ) {
            $hit = $wpdb->get_var( $wpdb->prepare( "
                SELECT COUNT(*) FROM {$pm} pm
                INNER JOIN {$pp} p ON p.ID = pm.post_id
                WHERE pm.meta_key = %s
                  AND FIND_IN_SET(%d, pm.meta_value)
                  AND p.post_type NOT IN ('product','product_variation')
                  AND p.post_status NOT IN ('trash','auto-draft')
            ", $key, $attachment_id ) );
            if ( $hit > 0 ) {
                return 'csv_gallery_non_product:' . $key;
            }
        }

        // Shared globals
        $global_reason = self::get_site_globals_reason( $attachment_id, $patterns );
        if ( null !== $global_reason ) {
            return $global_reason;
        }

        return null;
    }

    /* ------------------------------------------------------------------ */
    /*  Complete-unused check (for the "orphans only" cleaner)             */
    /* ------------------------------------------------------------------ */

    /**
     * Is this attachment completely unused ANYWHERE on the site?
     *
     * @param int $attachment_id
     * @return bool  TRUE = completely unused (safe to delete).
     */
    private static function is_completely_unused( $attachment_id ) {
        return null === self::get_usage_info( $attachment_id );
    }

    /**
     * Returns the first reason this attachment is considered "in use" anywhere
     * on the site, or null if it is completely unused.
     *
     * Useful for building skip-lists so operators can trust that every skipped
     * image has a documented reason.
     *
     * @param int $attachment_id
     * @return string|null
     */
    private static function get_usage_info( $attachment_id ) {
        global $wpdb;
        $attachment_id = (int) $attachment_id;
        $patterns      = self::build_search_patterns( $attachment_id );

        if ( empty( $patterns ) ) {
            // No URL means no file — treat as orphan (safe to delete the row).
            return null;
        }

        $pp = $wpdb->posts;
        $pm = $wpdb->postmeta;
        $tm = isset( $wpdb->termmeta ) ? $wpdb->termmeta : $wpdb->prefix . 'termmeta';

        // Featured image on any live post
        $featured = $wpdb->get_var( $wpdb->prepare( "
            SELECT COUNT(*) FROM {$pm} pm
            INNER JOIN {$pp} p ON p.ID = pm.post_id
            WHERE pm.meta_key = '_thumbnail_id'
              AND pm.meta_value = %d
              AND p.post_status NOT IN ('trash','auto-draft')
        ", $attachment_id ) );
        if ( $featured > 0 ) {
            return 'featured_image';
        }

        // Post content / excerpt — URL/filename in any live post's body
        $content_sql = "
            SELECT COUNT(*) FROM {$pp}
            WHERE post_type NOT IN ('attachment','revision','nav_menu_item')
              AND post_status NOT IN ('trash','auto-draft')
              AND ( post_content LIKE %s OR post_excerpt LIKE %s )
        ";
        if ( self::patterns_match_sql_pair( $patterns, $content_sql ) ) {
            return 'post_content';
        }

        // Product gallery CSV (live product)
        $gallery_meta = $wpdb->get_var( $wpdb->prepare( "
            SELECT COUNT(*) FROM {$pm} pm
            INNER JOIN {$pp} p ON p.ID = pm.post_id
            WHERE pm.meta_key IN ('_product_image_gallery','zoo-cw-variation-gallery')
              AND FIND_IN_SET(%d, pm.meta_value)
              AND p.post_type IN ('product','product_variation')
              AND p.post_status NOT IN ('trash','auto-draft')
        ", $attachment_id ) );
        if ( $gallery_meta > 0 ) {
            return 'product_gallery';
        }

        // Elementor
        $elementor_sql = "
            SELECT COUNT(*) FROM {$pm} pm
            INNER JOIN {$pp} p ON p.ID = pm.post_id
            WHERE pm.meta_key IN ('_elementor_data','_elementor_css')
              AND pm.meta_value LIKE %s
              AND p.post_status NOT IN ('trash','auto-draft')
        ";
        if ( self::patterns_match_sql( $patterns, $elementor_sql ) ) {
            return 'elementor';
        }

        // WPBakery / Visual Composer
        $wpb_sql = "
            SELECT COUNT(*) FROM {$pm} pm
            INNER JOIN {$pp} p ON p.ID = pm.post_id
            WHERE pm.meta_key IN ('_wpb_shortcodes_custom_css','_wpb_post_custom_css','vcv-pageContent','_vcv-pageContent')
              AND pm.meta_value LIKE %s
              AND p.post_status NOT IN ('trash','auto-draft')
        ";
        if ( self::patterns_match_sql( $patterns, $wpb_sql ) ) {
            return 'wpbakery';
        }

        // Live parent post (any non-trash, non-auto-draft)
        $parent = self::get_live_parent( $attachment_id );
        if ( $parent ) {
            return 'live_parent:' . $parent['post_type'];
        }

        // Precise gallery shortcode
        if ( self::gallery_shortcode_uses_id(
            $attachment_id,
            "('attachment','revision','nav_menu_item')"
        ) ) {
            return 'gallery_shortcode';
        }

        // Whitelisted postmeta exact-ID
        $meta_keys = self::get_attachment_id_meta_keys();
        if ( ! empty( $meta_keys ) ) {
            $placeholders = implode( ',', array_fill( 0, count( $meta_keys ), '%s' ) );
            $args         = array_merge( $meta_keys, [ (string) $attachment_id ] );

            $sql = "
                SELECT pm.meta_key FROM {$pm} pm
                INNER JOIN {$pp} p ON p.ID = pm.post_id
                WHERE pm.meta_key IN ({$placeholders})
                  AND pm.meta_value = %s
                  AND p.post_status NOT IN ('trash','auto-draft')
                LIMIT 1
            ";
            $hit = $wpdb->get_var( $wpdb->prepare( $sql, ...$args ) );
            if ( $hit ) {
                return 'postmeta:' . $hit;
            }
        }

        // Whitelisted termmeta exact-ID
        $term_meta_keys = self::get_attachment_id_term_meta_keys();
        if ( ! empty( $term_meta_keys ) ) {
            $placeholders = implode( ',', array_fill( 0, count( $term_meta_keys ), '%s' ) );
            $args         = array_merge( $term_meta_keys, [ (string) $attachment_id ] );

            $sql = "
                SELECT meta_key FROM {$tm}
                WHERE meta_key IN ({$placeholders})
                  AND meta_value = %s
                LIMIT 1
            ";
            $hit = $wpdb->get_var( $wpdb->prepare( $sql, ...$args ) );
            if ( $hit ) {
                return 'termmeta:' . $hit;
            }
        }

        // Site globals
        $global_reason = self::get_site_globals_reason( $attachment_id, $patterns );
        if ( null !== $global_reason ) {
            return $global_reason;
        }

        return null;
    }

    /* ------------------------------------------------------------------ */
    /*  Shared global checks                                               */
    /* ------------------------------------------------------------------ */

    /**
     * Back-compat wrapper for the old boolean signature.
     */
    private static function is_used_in_site_globals( $attachment_id, array $patterns ) {
        return null !== self::get_site_globals_reason( $attachment_id, $patterns );
    }

    /**
     * Returns the first reason an attachment is referenced in widgets /
     * theme options / site identity / WooCommerce placeholder, or null.
     *
     * @param int   $attachment_id
     * @param array $patterns
     * @return string|null
     */
    private static function get_site_globals_reason( $attachment_id, array $patterns ) {
        global $wpdb;

        // Site logo / icon / custom logo / WC placeholder (exact-ID)
        $site_logo      = (int) get_option( 'site_logo' );
        $site_icon      = (int) get_option( 'site_icon' );
        $custom_logo    = (int) get_theme_mod( 'custom_logo' );
        $wc_placeholder = (int) get_option( 'woocommerce_placeholder_image', 0 );

        if ( $site_logo === $attachment_id )      { return 'site_logo'; }
        if ( $site_icon === $attachment_id )      { return 'site_icon'; }
        if ( $custom_logo === $attachment_id )    { return 'custom_logo'; }
        if ( $wc_placeholder === $attachment_id ) { return 'wc_placeholder'; }

        // Widgets
        $widget_sql = "
            SELECT COUNT(*) FROM {$wpdb->options}
            WHERE option_name LIKE '%widget%'
              AND option_value LIKE %s
        ";
        if ( self::patterns_match_sql( $patterns, $widget_sql ) ) {
            return 'widget';
        }

        // Known option blobs (Divi, Extra, theme builder, Elementor kit, ...)
        $option_names = self::get_option_names_with_attachments();
        foreach ( $option_names as $opt ) {
            $val = get_option( $opt );
            if ( empty( $val ) ) {
                continue;
            }
            $haystack = is_string( $val ) ? $val : wp_json_encode( $val );
            if ( ! is_string( $haystack ) || '' === $haystack ) {
                continue;
            }
            foreach ( $patterns as $pat ) {
                if ( ! empty( $pat ) && false !== strpos( $haystack, (string) $pat ) ) {
                    return 'option:' . $opt;
                }
            }
        }

        // Theme mods (JSON scan)
        $theme_mods = get_theme_mods();
        if ( is_array( $theme_mods ) ) {
            $json = wp_json_encode( $theme_mods );
            if ( is_string( $json ) ) {
                foreach ( $patterns as $pat ) {
                    if ( ! empty( $pat ) && false !== strpos( $json, (string) $pat ) ) {
                        return 'theme_mods';
                    }
                }
            }
        }

        return null;
    }
}
