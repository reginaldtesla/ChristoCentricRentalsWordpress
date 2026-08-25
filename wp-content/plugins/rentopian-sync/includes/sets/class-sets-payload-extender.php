<?php
/**
 * Rental_Sets_Payload_Extender
 *
 * Hooks the existing `rental_order_data_before_send` filter (already
 * fired at the end of `rental_create_order` in `functions.php`) and
 * enriches the outbound payload with modern-sets context the legacy
 * builder doesn't ship.
 *
 * Four layers of enrichment, all on top of the existing legacy fields
 * (which already carry the core rental_id under `product_id`, `parent_id`,
 * and `sel_variant_id` — see functions.php:12355, 12453, 12436):
 *
 *   1. Per-line `division_id`. Read once from `rental_product_relations`
 *      (variant first, product fallback). Laravel CAN derive this from
 *      product_id alone via its own DB, but shipping it explicit gives
 *      multi-division installs a defensive cross-check.
 *
 *   2. Per-line `is_set_group_item` flag — 1 for grouped-set children,
 *      0 for any other set-tagged line. Mirrors the core database
 *      column so the intake can branch on a single field.
 *
 *   3. Per-line composite-group context for grouped children only:
 *      the cart-item's `rental_set_group_*` meta shipped under
 *      un-prefixed `set_group_*` keys (id, uid, item_uid, price, qty,
 *      required, multiple_selection, name).
 *
 *   4. Top-level `set_meta` block keyed by core set rental_id. Carries
 *      `set_order` (admin display order JSON) + `item_based_total`
 *      (pricing-mode flag) + `wp_set_id` (for trace-back to WP postmeta).
 *      Lets Laravel validate the structure it receives against the
 *      definition it shipped out.
 *
 * Why a filter rather than direct surgery on `rental_create_order`:
 *  - Zero edits to the existing add-on/order builder. Lines that don't
 *    belong to a set produce byte-identical payloads to today.
 *  - The new fields appear ONLY on lines we can prove belong to a set,
 *    so a site without modern layout (or a set without modern entities)
 *    ships exactly the legacy shape.
 *  - When the core API contract finalises and field names need to
 *    change, the surgery is one class, one file.
 *
 * Two extension points are exposed:
 *
 *   - `rental_sets_modern_payload_inventory_line` — filter run on every
 *     inventory line *that we tagged*, after our enrichment. Useful for
 *     last-minute renames once the core team locks the contract.
 *
 *   - `rental_sets_modern_payload_set_total` — filter run on the full
 *     payload, useful for adding set-level metadata.
 *
 * @package RentopianSync\Sets
 * @since   2.14.7
 *
 * PHP requirement: 7.4+
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'Rental_Sets_Payload_Extender', false ) ) :

class Rental_Sets_Payload_Extender {

    /**
     * @var string
     */
    protected $log_source = 'rentopian-sets-sync';

    /**
     * @var self|null
     */
    protected static $instance = null;

    public static function register() {
        if ( null !== self::$instance ) {
            return;
        }
        self::$instance = new self();
        self::$instance->boot();
    }

    protected function boot() {
        // Priority 50 — late, so anything plugins added at default
        // priority 10 has already landed in $data.
        add_filter( 'rental_order_data_before_send', array( $this, 'on_data_before_send' ), 50, 2 );
    }

    /**
     * Walk the order-bound payload, decode `inventories`, walk the cart
     * to find the matching cart line per inventory entry, and enrich
     * lines that belong to a modern set. See class header for the four
     * enrichment layers.
     *
     * Lines without a matching set-tagged cart entry are passed through
     * untouched — classic (non-set) products and addons never enter the
     * enrichment loop, so the legacy payload shape for those is
     * preserved byte-for-byte.
     *
     * @param array $data     The full payload about to be sent to core.
     * @param int   $order_id WP order id.
     * @return array Modified payload.
     */
    public function on_data_before_send( $data, $order_id ) {
        if ( empty( $data['inventories'] ) ) {
            return $data;
        }

        // The legacy builder emits `inventories` as a JSON string.
        $inventories = json_decode( $data['inventories'], true );
        if ( ! is_array( $inventories ) || empty( $inventories ) ) {
            return $data;
        }

        // Index every set-tagged cart item by (rental_set_id, inv_id) so
        // each inventory line resolves to its cart entry in O(1). One key
        // can hold several lines — the same inventory legitimately appears
        // twice in a set when a product is both a set line (item / variant
        // choice / group option) and an add-on attached to another line.
        $cart_index = $this->index_cart_set_children();
        if ( empty( $cart_index ) ) {
            // No modern-set lines in cart — short-circuit.
            return $data;
        }

        // Track the unique WP set ids we touch, so the set-level
        // enrichment pass can attach metadata for each one.
        $wp_set_ids = array();

        $tagged_count = 0;

        foreach ( $inventories as $idx => $line ) {

            $line_set_id = isset( $line['set_id'] ) ? (int) $line['set_id'] : 0;
            $line_inv_id = isset( $line['inventory_id'] ) ? (int) $line['inventory_id'] : 0;

            if ( ! $line_set_id || ! $line_inv_id ) {
                continue;
            }

            $key = $line_set_id . ':' . $line_inv_id;
            if ( empty( $cart_index[ $key ] ) ) {
                continue;
            }

            $cart_item = $this->take_matching_cart_item( $line, $cart_index[ $key ] );
            if ( null === $cart_item ) {
                continue;
            }

            $wp_set_id = isset( $cart_item['set_id'] ) ? (int) $cart_item['set_id'] : 0;
            if ( $wp_set_id > 0 ) {
                $wp_set_ids[ $wp_set_id ] = true;
            }

            $is_group_child = Rental_Sets_Cart_Meta::is_group_child( $cart_item );

            // Layer 1+2 — division_id + is_set_group_item. Always
            // populated for any set-tagged line.
            $addition = array(
                'division_id'       => $this->resolve_division_id( $cart_item ),
                'is_set_group_item' => $is_group_child ? 1 : 0,
            );

            // Layer 3 — composite-group context for grouped children
            // ONLY. Selectables / simples / addons leave these alone.
            // Cart meta keeps the `rental_` prefix; the wire contract
            // drops it.
            if ( $is_group_child ) {
                $addition['set_group_id']                 = (int) Rental_Sets_Cart_Meta::read( $cart_item, Rental_Sets_Cart_Meta::KEY_GROUP_ID, 0 );
                $addition['set_group_uid']                = (string) Rental_Sets_Cart_Meta::read( $cart_item, Rental_Sets_Cart_Meta::KEY_GROUP_UID, '' );
                $addition['set_group_item_uid']           = (string) Rental_Sets_Cart_Meta::read( $cart_item, Rental_Sets_Cart_Meta::KEY_GROUP_ITEM_UID, '' );
                $addition['set_group_price']              = Rental_Sets_Cart_Meta::read( $cart_item, Rental_Sets_Cart_Meta::KEY_GROUP_PRICE, null );
                $addition['set_group_qty']                = (int) Rental_Sets_Cart_Meta::read( $cart_item, Rental_Sets_Cart_Meta::KEY_GROUP_QTY, 0 );
                $addition['set_group_required']           = (int) Rental_Sets_Cart_Meta::read( $cart_item, Rental_Sets_Cart_Meta::KEY_GROUP_REQUIRED, 0 );
                $addition['set_group_multiple_selection'] = (int) Rental_Sets_Cart_Meta::read( $cart_item, Rental_Sets_Cart_Meta::KEY_GROUP_MULTIPLE_SELECTION, 0 );
                $addition['set_group_name']               = (string) Rental_Sets_Cart_Meta::read( $cart_item, Rental_Sets_Cart_Meta::KEY_GROUP_NAME, '' );
            }

            // Per-line filter — last-mile rename / restructure point.
            $addition = apply_filters(
                'rental_sets_modern_payload_inventory_line',
                $addition,
                $line,
                $cart_item,
                $order_id
            );

            $line                = array_merge( $line, $addition );
            $inventories[ $idx ] = $line;
            $tagged_count++;
        }

        // Write enriched lines back into the payload.
        $data['inventories'] = json_encode( $inventories );

        // Layer 4 — top-level set_meta block.
        $data = $this->attach_set_meta( $data, array_keys( $wp_set_ids ) );

        // Whole-payload filter — useful for set-level rollups.
        $data = apply_filters( 'rental_sets_modern_payload_set_total', $data, $inventories, $order_id );

        if ( $tagged_count > 0 ) {
            Project_WP_Logger::write(
                sprintf(
                    'Payload_Extender: enriched %d inventory lines (sets=%d) for order %d.',
                    (int) $tagged_count,
                    count( $wp_set_ids ),
                    (int) $order_id
                ),
                'info',
                $this->log_source
            );
        }

        return $data;
    }

    /**
     * Index every set-tagged cart line by (rental_set_id, inv_id).
     * Covers grouped children, selectables, simples, and addons —
     * anything that carries a non-zero `rental_set_id` and resolves to
     * an inventory id.
     *
     * Each key holds a LIST, not a single line. One inventory can back
     * two independent cart lines in the same set: the product sold as a
     * set line (item / variant choice / group option) AND the same
     * product attached as an add-on to another line. Collapsing them
     * would hand one line's context to the other — an add-on line could
     * inherit `is_set_group_item` + `set_group_*` and be filed by the
     * core as a group item.
     *
     * @return array<string,array<int,array>> Keyed by "{rental_set_id}:{inv_id}".
     */
    protected function index_cart_set_children() {
        if ( ! function_exists( 'WC' ) || ! WC() || ! WC()->cart ) {
            return array();
        }

        $index = array();
        foreach ( WC()->cart->cart_contents as $cart_item ) {

            // `rental_set_id` is the set's CORE rental_id (set on every
            // set-child line by the cart handler — see
            // rentopian-sync.php:4464). Lines without it aren't set
            // children; skip cleanly.
            $rental_set_id = isset( $cart_item['rental_set_id'] ) ? (int) $cart_item['rental_set_id'] : 0;
            if ( ! $rental_set_id ) {
                continue;
            }

            $product_variant_id = ! empty( $cart_item['variation_id'] )
                ? (int) $cart_item['variation_id']
                : (int) $cart_item['product_id'];
            $inv_id = (int) get_post_meta( $product_variant_id, '_rental_inventory_id', true );
            if ( ! $inv_id ) {
                continue;
            }

            $key             = $rental_set_id . ':' . $inv_id;
            $index[ $key ][] = $cart_item;
        }

        return $index;
    }

    /**
     * Pick the cart line that produced a given inventory line and remove
     * it from the candidate list, so a second payload line sharing the
     * same (set, inv) key resolves to the next candidate instead of
     * re-reading the first.
     *
     * The discriminator is how the line was ADDED, never what the product
     * is flagged as: an add-on line carries `parent_id` /
     * `parent_inventory_id` in the payload and `parent_set_item_product_id`
     * on the cart item; a set line carries neither. An add-on-flagged
     * product sold as its own set line is therefore matched as a set line.
     *
     * @param array $line       One decoded `inventories[]` entry.
     * @param array $candidates Cart lines for this (set, inv) key, by reference.
     * @return array|null  The matched cart line, or null when the list is empty.
     */
    protected function take_matching_cart_item( array $line, array &$candidates ) {
        if ( empty( $candidates ) ) {
            return null;
        }

        $line_is_addon = ! empty( $line['parent_id'] ) || ! empty( $line['parent_inventory_id'] );

        foreach ( $candidates as $idx => $candidate ) {
            $candidate_is_addon = ! empty( $candidate['parent_set_item_product_id'] )
                || ! empty( $candidate['parent_set_item_variant_id'] );
            if ( $candidate_is_addon === $line_is_addon ) {
                unset( $candidates[ $idx ] );
                return $candidate;
            }
        }

        // No kind match — fall back to the first remaining candidate so a
        // shape we didn't anticipate still gets its legacy enrichment.
        $idx = array_key_first( $candidates );
        $fallback = $candidates[ $idx ];
        unset( $candidates[ $idx ] );

        return $fallback;
    }

    /**
     * Resolve a cart line's core `division_id` via the relations tables.
     * Tries the variant table first (since `variation_id` typically
     * carries the most specific record for variable products), then
     * falls back to the parent product's relation row.
     *
     * Returns 0 when neither lookup yields a hit — that's the same null-
     * island value the legacy code uses elsewhere, and Laravel can
     * still derive division from product_id in that case.
     *
     * @param array $cart_item
     * @return int
     */
    protected function resolve_division_id( $cart_item ) {
        global $wpdb, $rental_tables;
        if ( ! isset( $wpdb ) || empty( $rental_tables['product_relations'] ) || empty( $rental_tables['variant_relations'] ) ) {
            return 0;
        }

        $product_relations = $wpdb->prefix . $rental_tables['product_relations'];
        $variant_relations = $wpdb->prefix . $rental_tables['variant_relations'];

        $variation_id = isset( $cart_item['variation_id'] ) ? (int) $cart_item['variation_id'] : 0;
        $product_id   = isset( $cart_item['product_id'] )   ? (int) $cart_item['product_id']   : 0;

        if ( $variation_id > 0 ) {
            $div = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT `rental_division_id` FROM {$variant_relations} WHERE `id` = %d LIMIT 1",
                $variation_id
            ) );
            if ( $div > 0 ) {
                return $div;
            }
        }

        if ( $product_id > 0 ) {
            $div = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT `rental_division_id` FROM {$product_relations} WHERE `id` = %d LIMIT 1",
                $product_id
            ) );
            if ( $div > 0 ) {
                return $div;
            }
        }

        return 0;
    }

    /**
     * Attach a top-level `set_meta` block keyed by core set rental_id.
     * Carries the admin display order (`set_order`) and the pricing-
     * mode flag (`item_based_total`) for every set referenced in this
     * order. Laravel's intake can use this to validate the structure
     * it receives against the definition it shipped out — and to
     * verify the pricing model the WP side used when computing
     * subtotals.
     *
     * The block is keyed by CORE rental_id (not WP id), since the
     * intake side speaks in core ids. `wp_set_id` is included as an
     * audit / trace-back field for human debugging only.
     *
     * @param array $data       Outbound payload.
     * @param int[] $wp_set_ids Unique WP set product ids referenced.
     * @return array
     */
    protected function attach_set_meta( array $data, array $wp_set_ids ) {
        if ( empty( $wp_set_ids ) ) {
            return $data;
        }

        global $wpdb, $rental_tables;
        if ( ! isset( $wpdb ) || empty( $rental_tables['set_relations'] ) ) {
            return $data;
        }

        $set_meta      = array();
        $set_relations = $wpdb->prefix . $rental_tables['set_relations'];

        foreach ( $wp_set_ids as $wp_set_id ) {
            $wp_set_id = (int) $wp_set_id;
            if ( $wp_set_id <= 0 ) {
                continue;
            }

            $core_id = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT `rental_id` FROM {$set_relations} WHERE `id` = %d LIMIT 1",
                $wp_set_id
            ) );
            if ( $core_id <= 0 ) {
                // Set isn't synced to a core id (yet). Skip — there's no
                // useful Laravel-facing identity to key under.
                continue;
            }

            // set_order arrives as JSON in postmeta; decode so Laravel
            // doesn't have to. The legacy fallback is an empty array
            // when the meta is absent or malformed.
            $order_raw = get_post_meta( $wp_set_id, '_rental_set_order', true );
            $order     = array();
            if ( is_string( $order_raw ) && '' !== $order_raw ) {
                $decoded = json_decode( $order_raw, true );
                if ( is_array( $decoded ) ) {
                    $order = $decoded;
                }
            } elseif ( is_array( $order_raw ) ) {
                $order = $order_raw;
            }

            $set_meta[ $core_id ] = array(
                'wp_set_id'        => $wp_set_id,
                'set_order'        => array_map( 'strval', $order ),
                'item_based_total' => (int) get_post_meta( $wp_set_id, '_rental_item_based_total', true ),
            );
        }

        if ( ! empty( $set_meta ) ) {
            // Stringify so the payload stays JSON-friendly even if
            // upstream serialisation flattens nested arrays.
            $data['set_meta'] = json_encode( $set_meta );
        }

        return $data;
    }
}

endif;
