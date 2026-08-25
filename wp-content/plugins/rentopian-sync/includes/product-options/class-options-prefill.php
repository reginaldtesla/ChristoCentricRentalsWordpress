<?php
/**
 * Rental_Options_Prefill
 *
 * Puts the customer's picks back on the product page after a rejected add.
 *
 * Without this, a blocked add-to-cart returned a page whose selects had reset,
 * so the customer had to re-make choices that were never the problem. The
 * validator caches the rejected submission; this class hands it to the render
 * endpoint, which outranks the cart and session copies, and clears it once
 * consumed so it cannot shadow a later change.
 *
 * @package RentopianSync\ProductOptions
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'Rental_Options_Prefill', false ) ) :

class Rental_Options_Prefill {

    /**
     * The rejected submission for a product, if one is waiting.
     *
     * @param int  $product_id
     * @param bool $consume Clear it after reading.
     * @return array option_id => entry
     */
    public static function pending( $product_id, $consume = false ) {
        $product_id = (int) $product_id;
        $pending    = Rental_Options_Cart_Validator::failed_selection( $product_id );

        if ( $pending && $consume ) {
            self::clear( $product_id );
        }

        return $pending;
    }

    /**
     * Drop a stored submission for a product and its variation family.
     *
     * A rejected add is remembered against both the variation and the parent,
     * because the product page renders a variable product from the parent id.
     * Consuming only the id that was asked for would leave the other copy to
     * resurface later as a stale prefill.
     *
     * @param int $product_id
     * @return void
     */
    public static function clear( $product_id ) {
        foreach ( self::family( (int) $product_id ) as $id ) {
            delete_rental_session_data( Rental_Options_Cart_Validator::SESSION_FAILED_PREFIX . $id );
        }
    }

    /**
     * A product id together with its parent and sibling variations.
     *
     * @param int $product_id
     * @return array<int>
     */
    protected static function family( $product_id ) {
        global $wpdb;

        $product_id = (int) $product_id;
        if ( ! $product_id ) {
            return array();
        }

        $parent = (int) wp_get_post_parent_id( $product_id );
        $root   = $parent ? $parent : $product_id;

        $ids = array( $product_id, $root );

        $variations = $wpdb->get_col( $wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_parent = %d AND post_type = 'product_variation'",
            $root
        ) );

        foreach ( (array) $variations as $variation_id ) {
            $ids[] = (int) $variation_id;
        }

        return array_unique( array_filter( $ids ) );
    }

    /**
     * Whether a rejected submission is waiting for a product.
     *
     * @param int $product_id
     * @return bool
     */
    public static function has_pending( $product_id ) {
        return ! empty( self::pending( $product_id ) );
    }
}

endif;
