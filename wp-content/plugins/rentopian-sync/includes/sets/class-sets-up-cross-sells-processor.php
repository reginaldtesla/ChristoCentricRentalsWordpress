<?php
/**
 * Rental_Sets_Up_Cross_Sells_Processor
 *
 * Writes `_upsell_ids` and `_crosssell_ids` onto set posts based on the
 * four accumulator maps we collect while iterating the API `sets` payload:
 *
 *   - $set_up_sells_from_sets        wp_set_id => [rental_set_id, ...]
 *   - $set_up_sells_from_products    wp_set_id => [rental_product_id, ...]
 *   - $set_cross_sells_from_sets     wp_set_id => [rental_set_id, ...]
 *   - $set_cross_sells_from_products wp_set_id => [rental_product_id, ...]
 *
 * Rental IDs are translated to WP IDs via:
 *   - $set_ids                         rental_set_id => wp_set_id (single entry)
 *   - $product_ids_for_up_cross_sells  rental_product_id => [wp_product_id, ...]
 *     (multi-division case yields multiple wp ids; single-division uses [0])
 *
 * Everything is cleared first (`wp_set_object_terms($id, [], ...)` plus an
 * empty `_upsell_ids`/`_crosssell_ids` postmeta write) so partial data from
 * a previous sync can't leak into the new state.
 *
 * @package RentopianSync\Sets
 * @since   2.14.7
 *
 * PHP requirement: 7.4+
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'Rental_Sets_Up_Cross_Sells_Processor', false ) ) :

class Rental_Sets_Up_Cross_Sells_Processor {

    /**
     * @var string
     */
    protected $log_source = 'rentopian-sets-sync';

    /**
     * Entry point. Signature mirrors the legacy function so the compat
     * shim can forward positional arguments verbatim.
     *
     * @param array $set_up_sells_from_sets
     * @param array $set_up_sells_from_products
     * @param array $set_cross_sells_from_sets
     * @param array $set_cross_sells_from_products
     * @param array $set_ids                         rental_set_id => wp_set_id
     * @param array $product_ids_for_up_cross_sells  rental_product_id => [wp_product_id, ...]
     * @return void
     */
    public function run(
        $set_up_sells_from_sets,
        $set_up_sells_from_products,
        $set_cross_sells_from_sets,
        $set_cross_sells_from_products,
        $set_ids,
        $product_ids_for_up_cross_sells
    ) {
        $token = Project_WP_Logger::start( 'sets_up_cross_sells' );

        $this->apply_upsells(
            $set_up_sells_from_sets,
            $set_up_sells_from_products,
            $set_ids,
            $product_ids_for_up_cross_sells
        );

        $this->apply_crosssells(
            $set_cross_sells_from_sets,
            $set_cross_sells_from_products,
            $set_ids,
            $product_ids_for_up_cross_sells
        );

        Project_WP_Logger::stop(
            $token,
            'Rental_Sets_Up_Cross_Sells_Processor::run',
            'info',
            $this->log_source,
            0,
            sprintf(
                'Applied upsells (sets: %d, products: %d) and cross-sells (sets: %d, products: %d).',
                count( $set_up_sells_from_sets ),
                count( $set_up_sells_from_products ),
                count( $set_cross_sells_from_sets ),
                count( $set_cross_sells_from_products )
            )
        );
    }

    /**
     * Build the wp-id upsell pack per set, then write it to postmeta and the
     * `_upsell_ids` taxonomy. Set-sourced upsells are collected first, then
     * product-sourced upsells are merged in.
     *
     * @param array $from_sets     wp_set_id => [rental_set_id, ...]
     * @param array $from_products wp_set_id => [rental_product_id, ...]
     * @param array $set_ids       rental_set_id => wp_set_id
     * @param array $product_map   rental_product_id => [wp_product_id, ...]
     * @return void
     */
    protected function apply_upsells( $from_sets, $from_products, $set_ids, $product_map ) {

        // First pass: set -> set upsells. Clear existing state on each
        // target, then rebuild from the rental-id list.
        $wp_upsells_pack = [];
        foreach ( $from_sets as $wp_set_id => $rental_up_sell_pack ) {
            wp_set_object_terms( $wp_set_id, [], '_upsell_ids' );
            update_post_meta( $wp_set_id, '_upsell_ids', [] );

            if ( empty( $rental_up_sell_pack ) ) {
                continue;
            }

            $bucket = [];
            foreach ( $rental_up_sell_pack as $rental_set_id ) {
                if ( ! isset( $set_ids[ $rental_set_id ] ) ) {
                    continue;
                }
                $wp_id = $set_ids[ $rental_set_id ];
                if ( ! in_array( $wp_id, $bucket ) ) {
                    $bucket[] = $wp_id;
                }
            }
            $wp_upsells_pack[ $wp_set_id ] = $bucket;
        }

        // Second pass: set -> product upsells. Clear again. Then merge with
        // anything the first pass built.
        foreach ( $from_products as $wp_set_id => $rental_up_sell_pack ) {
            wp_set_object_terms( $wp_set_id, [], '_upsell_ids' );
            update_post_meta( $wp_set_id, '_upsell_ids', [] );

            $bucket = $this->translate_product_pack( $rental_up_sell_pack, $product_map );

            if ( ! empty( $bucket ) ) {
                if ( ! empty( $wp_upsells_pack[ $wp_set_id ] ) ) {
                    $wp_upsells_pack[ $wp_set_id ] = array_merge( $wp_upsells_pack[ $wp_set_id ], $bucket );
                } else {
                    $wp_upsells_pack[ $wp_set_id ] = $bucket;
                }
            }

            if ( ! empty( $wp_upsells_pack[ $wp_set_id ] ) ) {
                wp_set_object_terms( $wp_set_id, $wp_upsells_pack[ $wp_set_id ], '_upsell_ids' );
                update_post_meta( $wp_set_id, '_upsell_ids', $wp_upsells_pack[ $wp_set_id ] );
            }
        }
    }

    /**
     * Mirror of apply_upsells() for cross-sells.
     *
     * @param array $from_sets     wp_set_id => [rental_set_id, ...]
     * @param array $from_products wp_set_id => [rental_product_id, ...]
     * @param array $set_ids       rental_set_id => wp_set_id
     * @param array $product_map   rental_product_id => [wp_product_id, ...]
     * @return void
     */
    protected function apply_crosssells( $from_sets, $from_products, $set_ids, $product_map ) {

        // First pass
        $wp_cross_sells_pack = [];
        foreach ( $from_sets as $wp_set_id => $rental_cross_sell_pack ) {
            wp_set_object_terms( $wp_set_id, [], '_crosssell_ids' );
            update_post_meta( $wp_set_id, '_crosssell_ids', [] );

            if ( empty( $rental_cross_sell_pack ) ) {
                continue;
            }

            $bucket = [];
            foreach ( $rental_cross_sell_pack as $rental_set_id ) {
                if ( ! isset( $set_ids[ $rental_set_id ] ) ) {
                    continue;
                }
                $wp_id = $set_ids[ $rental_set_id ];
                if ( ! in_array( $wp_id, $bucket ) ) {
                    $bucket[] = $wp_id;
                }
            }
            $wp_cross_sells_pack[ $wp_set_id ] = $bucket;
        }

        // Second pass: set -> product cross-sells. Same clear-then-merge
        // flow as the upsell side.
        foreach ( $from_products as $wp_set_id => $rental_cross_sell_pack ) {
            wp_set_object_terms( $wp_set_id, [], '_crosssell_ids' );
            update_post_meta( $wp_set_id, '_crosssell_ids', [] );

            $bucket = $this->translate_product_pack( $rental_cross_sell_pack, $product_map );

            if ( !empty( $bucket ) ) {
                if ( !empty( $wp_cross_sells_pack[ $wp_set_id ] ) ) {
                    $wp_cross_sells_pack[ $wp_set_id ] = array_merge( $wp_cross_sells_pack[ $wp_set_id ], $bucket );
                } else {
                    $wp_cross_sells_pack[ $wp_set_id ] = $bucket;
                }
            }

            if ( !empty( $wp_cross_sells_pack[ $wp_set_id ] ) ) {
                wp_set_object_terms( $wp_set_id, $wp_cross_sells_pack[ $wp_set_id ], '_crosssell_ids' );
                update_post_meta( $wp_set_id, '_crosssell_ids', $wp_cross_sells_pack[ $wp_set_id ] );
            }
        }
    }

    /**
     * Resolve a list of rental product IDs into a deduped list of WP
     * product IDs. If a rental ID maps to more than one WP ID (multi-
     * division case) every WP ID is included; otherwise the first entry
     * of the single-item list is used.
     *
     * @param array|null $rental_pack List of rental product IDs (may be empty/null).
     * @param array      $product_map rental_product_id => [wp_product_id, ...]
     * @return int[]
     */
    protected function translate_product_pack( $rental_pack, $product_map ) {
        if ( empty( $rental_pack ) ) {
            return [];
        }

        $wp_pack = [];
        foreach ( $rental_pack as $rental_product_id ) {
            if ( ! isset( $product_map[ $rental_product_id ] ) ) {
                continue;
            }

            $wp_ids = $product_map[ $rental_product_id ];

            if ( count( $wp_ids ) > 1 ) {
                // Multi-division — every WP id for this rental product is in scope.
                foreach ( $wp_ids as $wp_id ) {
                    if ( ! in_array( $wp_id, $wp_pack ) ) {
                        $wp_pack[] = $wp_id;
                    }
                }
            } else {
                // Single-division — take index [0] as the canonical WP id.
                $wp_id = isset( $wp_ids[0] ) ? $wp_ids[0] : 0;
                if ( $wp_id && ! in_array( $wp_id, $wp_pack ) ) {
                    $wp_pack[] = $wp_id;
                }
            }
        }
        return $wp_pack;
    }
}

endif;
