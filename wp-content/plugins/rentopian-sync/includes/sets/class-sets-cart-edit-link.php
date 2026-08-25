<?php
/**
 * Rental_Sets_Cart_Edit_Link
 *
 * Adds an "Edit" link next to set parent lines on the cart and mini-cart.
 * Clicking the link takes the user to the product page with the
 * `?rental_edit_cart=KEY` query var so the renderer can pre-fill the
 * configurator with the line's existing selections.
 *
 * The link is rendered via filter on `woocommerce_cart_item_name`,
 * appending an HTML anchor after the product name. Mini-cart picks
 * up the same filter when WC's mini-cart fragment is rebuilt.
 *
 * Only set parent lines (not children) get the link. Children inherit
 * the parent's identity through `rental_add_on_of`.
 *
 * @package RentopianSync\Sets
 * @since   2.14.7
 *
 * PHP requirement: 7.4+
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'Rental_Sets_Cart_Edit_Link', false ) ) :

class Rental_Sets_Cart_Edit_Link {

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
        add_filter( 'woocommerce_cart_item_name', array( $this, 'append_edit_link' ), 30, 3 );
    }

    /**
     * @param string $name          Cart item name HTML.
     * @param array  $cart_item
     * @param string $cart_item_key
     * @return string
     */
    public function append_edit_link( $name, $cart_item, $cart_item_key ) {
        // Skip child lines.
        if ( ! empty( $cart_item['rental_add_on_of'] ) ) {
            return $name;
        }

        $product_id = isset( $cart_item['product_id'] ) ? (int) $cart_item['product_id'] : 0;
        if ( ! $product_id ) {
            return $name;
        }

        if ( ! get_post_meta( $product_id, '_rental_is_set', true ) ) {
            return $name;
        }

        $permalink = get_permalink( $product_id );
        if ( ! $permalink ) {
            return $name;
        }

        $url = add_query_arg(
            array( Rental_Sets_Cart_Edit_Mode::QUERY_VAR => $cart_item_key ),
            $permalink
        );

        $link = sprintf(
            '<a href="%s" class="rental-set-edit-link" data-cart-item-key="%s">%s</a>',
            esc_url( $url ),
            esc_attr( $cart_item_key ),
            esc_html__( 'Edit', 'rentopian-sync' )
        );

        return $name . '<div class="rental-set-edit-link-wrapper">' . $link . '</div>';
    }
}

endif;
