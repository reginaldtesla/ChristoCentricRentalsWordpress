<?php
/**
 * Rental_Sets_Webhook_Meta_Guard
 *
 * Scoped postmeta wipe for the `set/create` webhook flow.
 *
 * Why this exists. The webhook receiver `rentopian_set_create` in
 * api.php has, since launch, handled the "set already exists in WP"
 * branch by issuing an UNSCOPED postmeta wipe:
 *
 *     DELETE FROM wp_postmeta WHERE post_id = <set_id>
 *
 * …followed by a single bulk INSERT that recreates ~47 legacy keys
 * (sku, regular_price, _rental_set_items, _rental_max_quantity, etc.).
 *
 * That worked when the sets module only owned the legacy keys. With
 * the modern feature shipping new postmeta — entity-3 group data,
 * set_order, layout_mode, item_based_total, optional admin flags, and
 * any user/theme custom postmeta — the unscoped wipe silently destroys
 * data on every `set/create` webhook for an existing set.
 *
 * This class owns the canonical list of "legacy keys that the bulk
 * INSERT in api.php recreates" and exposes a one-line entry-point that
 * api.php can call in place of the unscoped DELETE:
 *
 *     if ( class_exists( 'Rental_Sets_Webhook_Meta_Guard', false ) ) {
 *         Rental_Sets_Webhook_Meta_Guard::scoped_wipe_for_set_create( (int) $set_id );
 *     } else {
 *         $wpdb->query( "DELETE FROM $wpdb->postmeta WHERE `post_id` = $set_id" );
 *     }
 *
 * The `class_exists` gate keeps the call backward-compatible: if for
 * any reason the sets module isn't loaded, api.php falls back to its
 * original unscoped behaviour.
 *
 * What survives the scoped wipe:
 *
 *   - `_rental_set_grouped_items`, `_rental_set_has_grouped_items`,
 *     `_rental_set_some_hidden_groups` — entity 3. The Step 1
 *     `Rental_Sets_Webhook_Group_Persister` then refreshes these from
 *     the webhook payload.
 *   - `_rental_set_order` — section ordering. The webhook doesn't ship
 *     it, so preserving the prior value is the correct behaviour.
 *   - `_rental_sets_layout_mode` — modern/classic toggle.
 *   - `_rental_item_based_total` — admin-side per-set pricing flag.
 *   - Any other postmeta (Polylang language meta, third-party plugin
 *     keys, theme custom fields, etc.) the unscoped wipe was silently
 *     destroying.
 *
 * What gets wiped: only the 47 keys api.php's bulk INSERT is about to
 * recreate. Net effect on those keys is identical to today — UPDATE in
 * place rather than wipe-and-reinsert.
 *
 * Filter `rental_sets_legacy_meta_keys_for_set_create` lets stores
 * extend the list (e.g. for additional theme-side legacy keys that
 * should also be wiped).
 *
 * @package RentopianSync\Sets
 * @since   2.14.8
 *
 * PHP requirement: 7.4+
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'Rental_Sets_Webhook_Meta_Guard', false ) ) :

class Rental_Sets_Webhook_Meta_Guard {

    /**
     * Log source tag.
     */
    const LOG_SOURCE = 'rentopian-sets-sync';

    /**
     * Run the scoped wipe and log the result. Intended to be called
     * from api.php's `rentopian_set_create` in place of the original
     * unscoped DELETE.
     *
     * Idempotent. Safe to call on a set that has no postmeta yet (the
     * IN-list match simply finds nothing to delete).
     *
     * @param int $wp_set_id  The WP post id of the set being recreated.
     * @return int  Number of postmeta rows actually deleted, or 0 on
     *              invalid input. (Useful for logging / tests.)
     */
    public static function scoped_wipe_for_set_create( $wp_set_id ) {
        global $wpdb;

        $wp_set_id = (int) $wp_set_id;
        if ( $wp_set_id <= 0 ) {
            return 0;
        }

        // Allow a third-party to bail out — e.g. for a debugging build
        // that wants to inspect raw postmeta before the wipe runs.
        if ( ! apply_filters( 'rental_sets_webhook_meta_guard_enabled', true, $wp_set_id ) ) {
            return 0;
        }

        $keys = self::legacy_set_create_keys();
        if ( empty( $keys ) ) {
            // Defensive: if a filter nukes the list, fall back to the
            // historical unscoped behaviour rather than silently
            // skipping the wipe — preserves api.php's existing flow.
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            return (int) $wpdb->query( $wpdb->prepare(
                "DELETE FROM {$wpdb->postmeta} WHERE post_id = %d",
                $wp_set_id
            ) );
        }

        $placeholders = implode( ',', array_fill( 0, count( $keys ), '%s' ) );

        // The args list for prepare(): set_id first, then each key.
        $args = $keys;
        array_unshift( $args, $wp_set_id );

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $sql = "DELETE FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key IN ($placeholders)";

        $rows = (int) $wpdb->query( $wpdb->prepare( $sql, $args ) );

        self::log( sprintf(
            'Webhook_Meta_Guard: scoped wipe on set %d deleted %d legacy postmeta row(s); modern + custom keys preserved.',
            $wp_set_id,
            $rows
        ) );

        return $rows;
    }

    /**
     * The canonical list of legacy postmeta keys that api.php's
     * `rentopian_set_create` bulk INSERT will recreate. Order is not
     * significant for the SQL IN-list, but kept stable for readability
     * and for the filter's downstream consumers.
     *
     * Keep this list in sync with the bulk INSERT in
     * `rentopian_set_create`. If a key is added to or removed from
     * that INSERT, mirror the change here.
     *
     * @return array<int,string>
     */
    public static function legacy_set_create_keys() {
        $keys = array(
            // WooCommerce review / rating defaults (recreated as 0/empty)
            '_wc_review_count',
            '_wc_rating_count',
            '_wc_average_rating',

            // WP editor flags
            '_edit_last',
            '_edit_lock',

            // Identity + pricing
            '_sku',
            '_regular_price',
            '_job_cost',
            '_price_multiplier_id',
            '_sale_price',
            '_sale_price_dates_from',
            '_sale_price_dates_to',
            'total_sales',
            '_price',

            // Tax
            '_tax_status',
            '_tax_class',

            // Stock / inventory model
            '_manage_stock',
            '_backorders',
            '_sold_individually',
            '_stock',
            '_stock_status',

            // Dimensions
            '_weight',
            '_length',
            '_width',
            '_height',
            '_depth',

            // Related products
            '_upsell_ids',
            '_crosssell_ids',

            // Misc product fields
            '_purchase_note',
            '_default_attributes',
            '_virtual',
            '_downloadable',
            '_product_image_gallery',
            '_download_limit',
            '_download_expiry',
            '_product_version',
            'zoo_cw_product_swatch_data',
            '_thumbnail_id',

            // Rentopian classic per-set meta
            '_rental_exempt_waiver',
            '_rental_is_sale',
            '_rental_is_add_on',
            '_rental_set_items_have_optional_items',
            '_rental_set_items',
            '_rental_set_items_default',
            '_rental_hide_items_on_website',
            '_rental_some_hidden_items',
            '_rental_max_quantity',
            '_rental_is_set',
        );

        $keys = apply_filters( 'rental_sets_legacy_meta_keys_for_set_create', $keys );

        // Defensive: only return strings, drop empties.
        $clean = array();
        foreach ( (array) $keys as $k ) {
            $k = (string) $k;
            if ( '' !== $k ) {
                $clean[] = $k;
            }
        }
        return $clean;
    }

    /**
     * Logging helper that degrades gracefully when Project_WP_Logger
     * isn't available.
     *
     * @param string $message
     * @param string $level  info|warning|error
     * @return void
     */
    protected static function log( $message, $level = 'info' ) {
        if ( class_exists( 'Project_WP_Logger', false )
            && method_exists( 'Project_WP_Logger', 'write' ) ) {
            Project_WP_Logger::write( $message, $level, self::LOG_SOURCE );
        }
    }
}

endif;
