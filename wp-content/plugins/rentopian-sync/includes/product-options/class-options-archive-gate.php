<?php
/**
 * Rental_Options_Archive_Gate
 *
 * Keeps the shop loop honest about products whose options need a decision.
 *
 * The loop button posts only a product id and a quantity — the option selects
 * exist nowhere in that DOM, so nothing can be submitted for them. Products
 * whose options all have defaults are unaffected and still add in one click.
 * A product with an option that has no default cannot be configured from the
 * loop at all, so its button becomes a link to the product page rather than an
 * add that the validator would reject.
 *
 * @package RentopianSync\ProductOptions
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'Rental_Options_Archive_Gate', false ) ) :

class Rental_Options_Archive_Gate {

    /** @var self|null */
    protected static $instance = null;

    /** @var array<int,bool> Memoized per product. */
    protected static $needs_page = array();

    /**
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
     * @return void
     */
    protected function boot() {
        add_filter( 'woocommerce_loop_add_to_cart_link', array( $this, 'filter_loop_button' ), 25, 2 );
        add_filter( 'woocommerce_product_supports', array( $this, 'filter_ajax_add_to_cart_support' ), 25, 3 );
    }

    /**
     * Whether a product cannot be added without visiting its page.
     *
     * @param int $product_id
     * @return bool
     */
    public static function needs_product_page( $product_id ) {
        $product_id = (int) $product_id;
        if ( isset( self::$needs_page[ $product_id ] ) ) {
            return self::$needs_page[ $product_id ];
        }

        // Read the option-ids postmeta first. It is served from the meta cache
        // WordPress primes for the loop, so a product with no options costs no
        // queries at all — resolving definitions for every tile in an archive
        // would otherwise add two queries per product. Products keep their ids
        // under `_product_options`, sets under `_set_options`.
        if ( ! self::has_option_ids( $product_id ) ) {
            self::$needs_page[ $product_id ] = false;

            return false;
        }

        $needs = false;
        if ( Rental_Options_Cart_Validator::owns( $product_id ) ) {
            $is_set = Rental_Options_Repository::is_set( $product_id );
            foreach ( Rental_Options_Repository::get( $product_id, $is_set ) as $option ) {
                if ( ! Rental_Options_Defaults::is_satisfiable_without_input( $option ) ) {
                    $needs = true;
                    break;
                }
            }
        }

        self::$needs_page[ $product_id ] = $needs;

        return $needs;
    }

    /**
     * Whether a product records any option ids at all.
     *
     * Answered from the post-meta cache, so it costs no query in a loop.
     *
     * @param int $product_id
     * @return bool
     */
    protected static function has_option_ids( $product_id ) {
        foreach ( array( '_product_options', '_set_options' ) as $meta_key ) {
            $ids = get_post_meta( (int) $product_id, $meta_key, true );
            if ( ! empty( $ids ) && '[]' !== $ids ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Swap the loop add-to-cart button for a link to the product page.
     *
     * @param string     $html
     * @param WC_Product $product
     * @return string
     */
    public function filter_loop_button( $html, $product ) {
        if ( ! $product instanceof WC_Product ) {
            return $html;
        }
        if ( ! self::needs_product_page( $product->get_id() ) ) {
            return $html;
        }

        return sprintf(
            '<a href="%s" class="button product_type_variable add_to_cart_button rental-options-select-link">%s</a>',
            esc_url( $product->get_permalink() ),
            esc_html__( 'Select options', 'rentopian-sync' )
        );
    }

    /**
     * Withdraw AJAX add-to-cart support so no other entry point can bypass the
     * link above.
     *
     * @param bool       $supports
     * @param string     $feature
     * @param WC_Product $product
     * @return bool
     */
    public function filter_ajax_add_to_cart_support( $supports, $feature, $product ) {
        if ( 'ajax_add_to_cart' !== $feature || ! $supports ) {
            return $supports;
        }
        if ( ! $product instanceof WC_Product ) {
            return $supports;
        }

        return self::needs_product_page( $product->get_id() ) ? false : $supports;
    }
}

endif;
