<?php
/**
 * Rental_Sets_Cart_Display
 *
 * Adds a "Group" meta row beneath every group-child cart line on the
 * cart page, mini-cart, checkout review, and any other surface that
 * uses `wc_get_formatted_cart_item_data()`.
 *
 * Why a meta row rather than modifying the title:
 *   - Native WC styling: every theme already renders these key/value
 *     pairs consistently.
 *   - No double-display risk: the underlying group meta keys (set in
 *     the Cart_Handler) are not part of the cart-item-data
 *     auto-render (WC only auto-renders `cart_item_data` fields that
 *     pass through `get_item_data`'s filter). We're the only producer.
 *
 * Only group children get the row. Parent set lines and classic
 * children (entity 1 + 2) are untouched — they already render their
 * context via the existing `rental_add_on_of` mechanism.
 *
 * @package RentopianSync\Sets
 * @since   2.14.7
 *
 * PHP requirement: 7.4+
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'Rental_Sets_Cart_Display', false ) ) :

class Rental_Sets_Cart_Display {

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

    /**
     * Hook in. Priority 20 leaves room for any other plugins adding
     * their own meta at the default 10.
     */
    protected function boot() {
        add_filter( 'woocommerce_get_item_data', array( $this, 'add_group_meta' ), 20, 2 );
    }

    /**
     * Append a "Group" row to the rendered cart-item meta.
     *
     * @param array $item_data  The current meta rows: [ { key, value, display? }, ... ]
     * @param array $cart_item  Full cart item array.
     * @return array
     */
    public function add_group_meta( $item_data, $cart_item ) {
        if ( ! is_array( $item_data ) ) {
            $item_data = array();
        }

        if ( ! Rental_Sets_Cart_Meta::is_group_child( $cart_item ) ) {
            return $item_data;
        }

        $group_name = Rental_Sets_Cart_Meta::read( $cart_item, Rental_Sets_Cart_Meta::KEY_GROUP_NAME, '' );
        if ( ! is_string( $group_name ) || '' === trim( $group_name ) ) {
            // Fallback: if the renderer didn't ship a name (older add events),
            // try to fetch from the parent set's grouped_items postmeta
            // by group_uid match. Cheap defensive read.
            $group_name = $this->resolve_group_name_from_meta( $cart_item );
        }

        if ( '' === $group_name ) {
            // Still nothing — show a generic label rather than skip the
            // row entirely, so the customer at least knows this is a
            // package selection.
            $group_name = __( 'Selected option', 'rentopian-sync' );
        }

        $item_data[] = array(
            'key'     => __( 'Group', 'rentopian-sync' ),
            'value'   => $group_name,
            'display' => esc_html( $group_name ),
        );

        return $item_data;
    }

    /**
     * Defensive lookup for group_name when the cart line was created
     * before the modern renderer started shipping it. Walks the
     * parent set's `_rental_set_grouped_items` looking for a UID match.
     *
     * @param array $cart_item
     * @return string
     */
    protected function resolve_group_name_from_meta( $cart_item ) {
        $group_uid = Rental_Sets_Cart_Meta::read( $cart_item, Rental_Sets_Cart_Meta::KEY_GROUP_UID, '' );
        if ( '' === $group_uid ) {
            return '';
        }

        // The group lives inside the parent set's postmeta. Find the
        // parent line via `rental_add_on_of` → its product_id → its
        // grouped_items postmeta.
        if ( empty( $cart_item['rental_add_on_of'] ) ) {
            return '';
        }
        $parent_key = $cart_item['rental_add_on_of'];

        if ( ! function_exists( 'WC' ) || ! WC() || ! WC()->cart ) {
            return '';
        }
        $contents = WC()->cart->cart_contents;
        if ( ! isset( $contents[ $parent_key ] ) ) {
            return '';
        }

        $parent_product_id = isset( $contents[ $parent_key ]['product_id'] )
            ? (int) $contents[ $parent_key ]['product_id']
            : 0;
        if ( ! $parent_product_id ) {
            return '';
        }

        $groups = get_post_meta( $parent_product_id, '_rental_set_grouped_items', true );
        if ( ! is_array( $groups ) ) {
            return '';
        }

        foreach ( $groups as $g ) {
            if ( isset( $g['uid'] ) && (string) $g['uid'] === $group_uid ) {
                return isset( $g['group_name'] ) ? (string) $g['group_name'] : '';
            }
        }
        return '';
    }
}

endif;
