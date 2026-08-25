<?php
/**
 * Procedural entry points for the product-options module.
 *
 * Existing call sites across functions.php, rentopian-sync.php and the theme
 * keep calling these names; each one now delegates to the module so there is a
 * single answer to "is this option chosen" no matter who asks.
 *
 * @package RentopianSync\ProductOptions
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! function_exists( 'rental_options_resolve_selection' ) ) {
    /**
     * The chosen value of every option of a product.
     *
     * @param int         $product_id
     * @param bool|null   $is_set        Null auto-detects from post meta.
     * @param string|null $cart_item_key Restrict the cart read to one line.
     * @return array option_id => entry
     */
    function rental_options_resolve_selection( $product_id, $is_set = null, $cart_item_key = null ) {
        $product_id = (int) $product_id;
        if ( null === $is_set ) {
            $is_set = Rental_Options_Repository::is_set( $product_id );
        }

        return Rental_Options_Selection::resolve( $product_id, (bool) $is_set, $cart_item_key );
    }
}

if ( ! function_exists( 'rental_options_are_satisfied' ) ) {
    /**
     * Whether every option of a product has an answer.
     *
     * @param int       $product_id
     * @param bool|null $is_set
     * @return bool
     */
    function rental_options_are_satisfied( $product_id, $is_set = null ) {
        $product_id = (int) $product_id;
        if ( null === $is_set ) {
            $is_set = Rental_Options_Repository::is_set( $product_id );
        }

        return array() === Rental_Options_Selection::unanswered( $product_id, (bool) $is_set );
    }
}

if ( ! function_exists( 'rental_options_default_value_id' ) ) {
    /**
     * The default value id of an option, or 0 when the customer must choose.
     *
     * @param array $option
     * @return int
     */
    function rental_options_default_value_id( $option ) {
        $default = Rental_Options_Defaults::resolve( $option );

        return $default ? (int) $default['id'] : 0;
    }
}

if ( ! function_exists( 'rental_options_persist_selection' ) ) {
    /**
     * Write a selection to both session stores at once.
     *
     * @param int       $product_id
     * @param array     $selection
     * @param bool|null $is_set
     * @return void
     */
    function rental_options_persist_selection( $product_id, $selection, $is_set = null ) {
        $product_id = (int) $product_id;
        if ( null === $is_set ) {
            $is_set = Rental_Options_Repository::is_set( $product_id );
        }

        Rental_Options_Selection::persist( $product_id, (bool) $is_set, $selection );
    }
}
