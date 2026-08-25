<?php
/**
 * Rental Polylang API Helpers
 *
 * Lightweight wrapper functions for use inside api.php webhook handlers.
 * Each function obtains the singleton Polylang integration instance and
 * delegates language assignment. All functions are safe to call even when
 * Polylang is not active — they silently no-op.
 *
 * @package RentopianSync
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Assign the default Polylang language to a product and its variations.
 *
 * Call this at the end of rentopian_product_create() and rentopian_set_create()
 * after all DB inserts are complete.
 *
 * @param int   $product_id  The WP product (or set) post ID.
 * @param int[] $variant_ids Optional array of child variation post IDs.
 */
function rental_pll_assign_product( int $product_id, array $variant_ids = [] ): void {
    if ( ! class_exists( 'Rental_Polylang_Integration' ) ) {
        return;
    }

    Rental_Polylang_Integration::get_instance()
        ->assign_language_to_product_with_variants( $product_id, $variant_ids );
}

/**
 * Assign the default Polylang language to a single post (product, variation, set).
 *
 * Call this at the end of rentopian_variant_create(), rentopian_variant_update(),
 * rentopian_product_update(), rentopian_set_update(), etc.
 *
 * @param int $post_id The WP post ID.
 */
function rental_pll_assign_post( int $post_id ): void {
    if ( ! class_exists( 'Rental_Polylang_Integration' ) ) {
        return;
    }

    Rental_Polylang_Integration::get_instance()
        ->assign_language_to_post( $post_id );
}

/**
 * Assign the default Polylang language to a single term.
 *
 * Call this at the end of rentopian_category_create(), rentopian_save_brand(),
 * rentopian_save_product_attribute(), rentopian_product_attribute_value_handler(), etc.
 *
 * @param int $term_id The WP term ID.
 */
function rental_pll_assign_term( int $term_id ): void {
    if ( ! class_exists( 'Rental_Polylang_Integration' ) ) {
        return;
    }

    Rental_Polylang_Integration::get_instance()
        ->assign_language_to_term( $term_id );
}

/**
 * Assign the default Polylang language to multiple terms at once.
 *
 * Useful when a single webhook creates several attribute value terms.
 *
 * @param int[] $term_ids Array of WP term IDs.
 */
function rental_pll_assign_terms( array $term_ids ): void {
    if ( ! class_exists( 'Rental_Polylang_Integration' ) ) {
        return;
    }

    Rental_Polylang_Integration::get_instance()
        ->assign_language_to_term_ids( $term_ids );
}
