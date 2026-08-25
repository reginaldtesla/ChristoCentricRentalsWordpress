<?php
/**
 * Price visibility helpers.
 *
 * Single source of truth for the "Prices Visibility" settings, so every
 * amount a visitor can see obeys the same rule: product prices, product and
 * set option prices, add-on prices, set item prices and the set
 * configurator totals.
 *
 * @package Rentopian_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! function_exists( 'rental_prices_are_hidden' ) ) {
    /**
     * Whether prices must be hidden from the visitor in a display context.
     *
     * Contexts:
     *   'catalog' — shop, product page, set configurator, add-on pickers.
     *   'cart'    — cart, mini-cart, checkout, order details and emails.
     *
     * "Hide Product Price" hides prices in every context. "Show Prices only
     * when items are in cart" hides them outside the cart contexts only.
     *
     * @param string $context Display context. Default 'catalog'.
     * @return bool
     */
    function rental_prices_are_hidden( $context = 'catalog' ) {

        if ( get_option( 'rental_hide_product_price' ) ) {
            return true;
        }

        if ( 'cart' === $context ) {
            return false;
        }

        return (bool) get_option( 'rental_show_product_price_only_in_cart' );
    }
}

if ( ! function_exists( 'rental_price_label' ) ) {
    /**
     * Plain-text price label for option, add-on and set item labels.
     *
     * Returns an empty string when prices are hidden or no price is set, so
     * callers can drop the whole label element.
     *
     * @param mixed  $price   Raw price value.
     * @param string $context Display context, see rental_prices_are_hidden().
     * @return string
     */
    function rental_price_label( $price, $context = 'catalog' ) {

        if ( null === $price || '' === (string) $price || rental_prices_are_hidden( $context ) ) {
            return '';
        }

        if ( function_exists( 'wc_price' ) ) {
            return trim( wp_strip_all_tags( wc_price( $price ) ) );
        }

        return (string) $price;
    }
}

if ( ! function_exists( 'rental_price_visibility_script_data' ) ) {
    /**
     * Price visibility flags for scripts that build price labels in the browser.
     *
     * Localized values arrive in JS as strings, so consumers must compare with
     * parseInt rather than truthiness.
     *
     * @return array
     */
    function rental_price_visibility_script_data() {

        return array(
            'hideCatalogPrices' => rental_prices_are_hidden( 'catalog' ) ? 1 : 0,
            'hideCartPrices'    => rental_prices_are_hidden( 'cart' ) ? 1 : 0,
        );
    }
}
