<?php
/**
 * Rental_Sets_Cart_Handler
 *
 * Wires composite-group provenance onto cart lines without touching the
 * existing `rental_add_product_to_cart` flow. Two integration points:
 *
 *   1. Filter `woocommerce_add_cart_item_data` — when the modern renderer
 *      ships group-prefixed fields inside `$_POST['rental_add_ons'][i]`,
 *      they get folded into the matching cart-item via the same loop in
 *      `rental_add_product_to_cart`. Our filter sees the merged data and
 *      normalises the group fields through Cart_Meta::build_from_input().
 *
 *   2. Filter `woocommerce_get_cart_item_from_session` — re-applies the
 *      meta to a session-restored cart item so a page reload doesn't
 *      lose the group context.
 *
 * Classic add-to-cart paths are unaffected: when no group fields are
 * present, build_from_input() returns an empty array and the cart item
 * is left exactly as-is.
 *
 * @package RentopianSync\Sets
 * @since   2.14.7
 *
 * PHP requirement: 7.4+
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'Rental_Sets_Cart_Handler', false ) ) :

class Rental_Sets_Cart_Handler {

    /**
     * @var string
     */
    protected $log_source = 'rentopian-sets-sync';

    /**
     * Singleton — one handler per request, since hooks register exactly
     * once. Construct via register() rather than calling new directly.
     *
     * @var self|null
     */
    protected static $instance = null;

    /**
     * Register all hooks. Idempotent — calling twice is a no-op.
     *
     * @return void
     */
    public static function register() {
        if ( null !== self::$instance ) {
            return;
        }
        self::$instance = new self();
        self::$instance->boot();
    }

    /**
     * Hook in. Priority 20 keeps us behind the plugin's own legacy add-
     * to-cart filters (priority 10) so we see the merged shape they
     * produce.
     *
     * @return void
     */
    protected function boot() {
        add_filter( 'woocommerce_add_cart_item_data',          array( $this, 'on_add_cart_item_data' ), 20, 3 );
        add_filter( 'woocommerce_get_cart_item_from_session',  array( $this, 'on_get_cart_item_from_session' ), 20, 2 );
    }

    /**
     * Enrich cart-item data with group meta when the incoming POST
     * shipped group-prefixed fields. Two sources are checked:
     *
     *   - $cart_item_data itself (the existing add-to-cart loop merges
     *     `rental_add_ons[]` entries directly into this array).
     *   - $_POST['rental_add_ons'] when the cart-item was matched by
     *     `rental_set_group_item_uid` — defensive fallback.
     *
     * Anything that's not a group child returns the input untouched.
     *
     * @param array $cart_item_data
     * @param int   $product_id
     * @param int   $variation_id
     * @return array
     */
    public function on_add_cart_item_data( $cart_item_data, $product_id, $variation_id ) {
        // Fast exit: if the merged data has no group marker AND no
        // group-prefixed POST fields, this isn't a group-child line.
        if ( empty( $cart_item_data['rental_set_group_is_child'] )
            && empty( $cart_item_data['item_type'] )
            && empty( $cart_item_data['rental_set_group_id'] )
            && empty( $cart_item_data['rental_set_group_uid'] ) ) {
            return $cart_item_data;
        }

        $group_meta = Rental_Sets_Cart_Meta::build_from_input( $cart_item_data );

        if ( empty( $group_meta ) ) {
            return $cart_item_data;
        }

        // Merge — namespaced keys can't collide with existing ones.
        foreach ( $group_meta as $k => $v ) {
            $cart_item_data[ $k ] = $v;
        }

        if ( class_exists( 'Rental_Sets_Logger', false ) ) {
            Rental_Sets_Logger::add_to_cart( '-', array(
                'kind'      => 'child_tagging',
                'product'   => (int) $product_id,
                'variation' => (int) $variation_id,
                'group_id'  => isset( $group_meta[ Rental_Sets_Cart_Meta::KEY_GROUP_ID ] ) ? $group_meta[ Rental_Sets_Cart_Meta::KEY_GROUP_ID ] : '',
                'group_uid' => isset( $group_meta[ Rental_Sets_Cart_Meta::KEY_GROUP_UID ] ) ? $group_meta[ Rental_Sets_Cart_Meta::KEY_GROUP_UID ] : '',
                'item_uid'  => isset( $group_meta[ Rental_Sets_Cart_Meta::KEY_GROUP_ITEM_UID ] ) ? $group_meta[ Rental_Sets_Cart_Meta::KEY_GROUP_ITEM_UID ] : '',
            ) );
        }

        return $cart_item_data;
    }

    /**
     * Re-apply the meta when WC restores cart contents from session.
     * Cart-item-data set in `woocommerce_add_cart_item_data` survives
     * the session round-trip on its own — but hooking here too gives
     * us a place to repair anything an upstream plugin might strip.
     *
     * @param array $cart_item   The hydrated cart item.
     * @param array $values      The raw stored values.
     * @return array
     */
    public function on_get_cart_item_from_session( $cart_item, $values ) {
        // Add-on parentage rides along: it is what tells an add-on line
        // apart from an ordinary line of the same product, so losing it
        // would mis-scope visibility and the outbound payload's line
        // matching — not just a display cue.
        $keys = array_merge(
            Rental_Sets_Cart_Meta::all_keys(),
            Rental_Sets_Cart_Meta::addon_parent_keys()
        );
        foreach ( $keys as $key ) {
            if ( isset( $values[ $key ] ) && ! isset( $cart_item[ $key ] ) ) {
                $cart_item[ $key ] = $values[ $key ];
            }
        }
        return $cart_item;
    }
}

endif;
