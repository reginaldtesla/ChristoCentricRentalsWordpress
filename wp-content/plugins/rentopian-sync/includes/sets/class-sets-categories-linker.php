<?php
/**
 * Rental_Sets_Categories_Linker
 *
 * One small block inside the categories loop relates each category to the
 * sets it contains. This class extracts that block into a single call so
 * the categories loop in `functions.php` doesn't carry sets-specific logic
 * inline.
 *
 * @package RentopianSync\Sets
 * @since   2.14.7
 *
 * PHP requirement: 7.4+
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'Rental_Sets_Categories_Linker', false ) ) :

class Rental_Sets_Categories_Linker {

    /**
     * Append term-relation rows + update category-products index for every
     * set attached to the given category.
     *
     * Reads `$category->sets` (comma-separated rental set ids), resolves
     * each to a wp set id via `$set_ids`, and for every match:
     *  - Pushes a `($wp_set_id, $cat_id, 0)` row onto $term_relation_sql.
     *  - Bumps $cat_products_count.
     *  - Adds $wp_set_id to $category_products[$cat_id] (and to the
     *    parent category bucket if one was supplied).
     *
     * @param object $category            Category object with `->sets`.
     * @param int    $cat_id              term_taxonomy_id of the category.
     * @param int    $parent_cat_id       term_taxonomy_id of the parent, or 0.
     * @param array  $set_ids             rental_set_id => wp_set_id map.
     * @param array  &$term_relation_sql  Accumulator; extended in place.
     * @param int    &$cat_products_count Running count; extended in place.
     * @param array  &$category_products  cat_id => [wp_ids]; extended in place.
     * @return void
     */
    public static function link_category_to_sets(
        $category,
        $cat_id,
        $parent_cat_id,
        array $set_ids,
        array &$term_relation_sql,
        &$cat_products_count,
        array &$category_products
    ) {
        if ( empty( $category->sets ) ) {
            return;
        }

        $cat_sets = explode( ',', $category->sets );
        if ( empty( $cat_sets ) ) {
            return;
        }

        foreach ( $cat_sets as $cat_set_id ) {
            if ( ! isset( $set_ids[ $cat_set_id ] ) ) {
                continue;
            }

            $wp_set_id = $set_ids[ $cat_set_id ];

            $term_relation_sql[] = "($wp_set_id, $cat_id, 0)";
            $cat_products_count++;

            if ( ! in_array( $wp_set_id, $category_products[ $cat_id ] ) ) {
                $category_products[ $cat_id ][] = $wp_set_id;
            }
            if ( $parent_cat_id && ! in_array( $wp_set_id, $category_products[ $parent_cat_id ] ) ) {
                $category_products[ $parent_cat_id ][] = $wp_set_id;
            }
        }
    }
}

endif;
