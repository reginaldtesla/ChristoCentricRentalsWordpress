<?php
/**
 * Rental_Sets_Tags_Processor
 *
 * Handles the sets-tags side of the product-tags pass:
 *
 *  - `detect_similar_for_product_tag()` — called from inside the product-
 *    tags loop. If a product tag's slug collides with any set tag's slug,
 *    the set-tag slug gets a `-sets` suffix recorded in the shared
 *    $similar_tags map so the later sets-tags loop uses the suffixed form.
 *
 *  - `build_sets_tags_sql()` — runs after the product-tags loop has
 *    populated $similar_tags. Generates the terms / term_taxonomy /
 *    term_relationships / termmeta / sets_tag_relations rows for every
 *    set tag, and links each to the wp set ids its rental ids resolve to.
 *
 * The tag id scheme is inherited from the outer pass: $tag_id = $cat_id + $tag->id.
 * $cat_id here means the largest category term_taxonomy_id we allocated —
 * it's used as an id-space base so tag ids can't collide with category ids.
 *
 * @package RentopianSync\Sets
 * @since   2.14.7
 *
 * PHP requirement: 7.4+
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'Rental_Sets_Tags_Processor', false ) ) :

class Rental_Sets_Tags_Processor {

    /**
     * For a single product-tag title, mark any set-tag sharing the same
     * slug with a `-sets` suffix. Called from inside the product-tags
     * loop so the sets-tags loop sees the renames when it runs next.
     *
     * @param string $product_tag_title Title of the current product tag.
     * @param array  $sets_tags         Full set-tags payload from the API.
     * @param array  &$similar_tags     set_slug => suffixed_slug; extended in place.
     * @return void
     */
    public static function detect_similar_for_product_tag( $product_tag_title, $sets_tags, array &$similar_tags ) {
        if ( empty( $sets_tags ) ) {
            return;
        }

        $product_slug = sanitize_title( $product_tag_title );

        foreach ( $sets_tags as $set_tag ) {
            $set_slug = sanitize_title( $set_tag->title );
            if ( $product_slug == $set_slug ) {
                $similar_tags[ $set_slug ] = $set_slug . '-sets';
            }
        }
    }

    /**
     * Build the rows for every set tag. Signature mirrors the variables
     * the caller already holds so the inline block can be replaced with
     * one call.
     *
     * Adds rows to:
     *   - $terms_sql                — WP term row.
     *   - $termmeta_sql             — `product_count_product_tag` meta.
     *   - $term_taxonomy_sql        — `product_tag` taxonomy row.
     *   - $term_relation_sql        — ($wp_set_id, $tag_id, 0) for each link.
     *   - $sets_tag_relations_sql   — plugin relation table row.
     *
     * @param array $sets_tags               Set tags from the API.
     * @param int   $cat_id_base             Id-space base (largest category tt id).
     * @param array $similar_tags            Output of detect_similar_for_product_tag().
     * @param array $set_ids                 rental_set_id => wp_set_id.
     * @param array &$terms_sql              Accumulator.
     * @param array &$termmeta_sql           Accumulator.
     * @param array &$term_taxonomy_sql      Accumulator.
     * @param array &$term_relation_sql      Accumulator.
     * @param array &$sets_tag_relations_sql Accumulator.
     * @return void
     */
    public static function build_sets_tags_sql(
        $sets_tags,
        $cat_id_base,
        array $similar_tags,
        array $set_ids,
        array &$terms_sql,
        array &$termmeta_sql,
        array &$term_taxonomy_sql,
        array &$term_relation_sql,
        array &$sets_tag_relations_sql
    ) {
        if ( empty( $sets_tags ) ) {
            return;
        }

        $set_tags_slug = array();

        foreach ( $sets_tags as $set_tag ) {
            $tag_id = $cat_id_base + $set_tag->id;

            $slug = sanitize_title( $set_tag->title );
            if ( isset( $similar_tags[ $slug ] ) ) {
                $slug = $similar_tags[ $slug ];
            }

            // Slug dedup within the sets-tags namespace itself — in case
            // two different set tags sanitize to the same slug.
            if ( isset( $set_tags_slug[ $slug ] ) ) {
                $set_tags_slug[ $slug ]++;
                $slug .= '-' . $set_tags_slug[ $slug ];
            } else {
                $set_tags_slug[ $slug ] = 1;
            }

            $terms_sql[] = "($tag_id, '" . addslashes( $set_tag->title ) . "', '$slug', 0)";

            // Resolve each comma-separated rental set id to its wp id,
            // emit a term-relation row, and count as we go.
            $tag_sets_count = 0;
            if ( ! empty( $set_tag->sets ) ) {
                $rental_ids = explode( ',', $set_tag->sets );
                foreach ( $rental_ids as $rental_id ) {
                    if ( isset( $set_ids[ $rental_id ] ) ) {
                        $term_relation_sql[] = "(" . $set_ids[ $rental_id ] . ", $tag_id, 0)";
                        $tag_sets_count++;
                    }
                }
            }

            $termmeta_sql[]           = "($tag_id, 'product_count_product_tag', $tag_sets_count)";
            $term_taxonomy_sql[]      = "($tag_id, $tag_id, 'product_tag', '', 0, $tag_sets_count)";
            $sets_tag_relations_sql[] = "($tag_id, {$set_tag->id})";
        }
    }
}

endif;
