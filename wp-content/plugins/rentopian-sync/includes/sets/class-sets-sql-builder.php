<?php
/**
 * Rental_Sets_Sql_Builder
 *
 * Produces the SQL value rows for one set. The strings this class returns
 * are concatenated into the outer `INSERT ... VALUES` statements the
 * synchronization function runs at the end of the product/variant/set pass.
 *
 *   - `posts_row()`                     — one posts table row
 *   - `postmeta_rows()`                 — the 40+ postmeta rows for a set
 *   - `product_meta_lookup_row()`       — the wc_product_meta_lookup row
 *   - `set_relations_row()`             — ($wp_set_id, $rental_id, $division_id)
 *   - `categories_term_relation_row()`  — ($wp_set_id, $simple_tt_id, 0)
 *
 * Formatting (escaping, column order, quote style) matches the legacy inline
 * statements — changing the shape would desync reviews against deployed
 * databases and break any tooling that greps for the exact literals.
 *
 * @package RentopianSync\Sets
 * @since   2.14.7
 *
 * PHP requirement: 7.4+
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'Rental_Sets_Sql_Builder', false ) ) :

class Rental_Sets_Sql_Builder {

    /**
     * Build the posts-table INSERT row for one set.
     *
     * @param object $set
     * @param int    $wp_set_id
     * @param string $slug    Resolved, possibly numbered, slug.
     * @param Rental_Sets_Sync_Context $ctx
     * @return string
     */
    public function posts_row( $set, $wp_set_id, $slug, Rental_Sets_Sync_Context $ctx ) {
        $full_description  = isset( $set->full_description ) ? addslashes( $set->full_description ) : '';
        $short_description = isset( $set->description ) ? addslashes( $set->description ) : '';

        $title  = addslashes( $set->title );
        $date   = $ctx->date();
        $gmdate = $ctx->gmdate();
        $domain = $ctx->domain();

        return "($wp_set_id, 1, '$date', '$gmdate', '$title',
            '$full_description', '$short_description', 'publish', 'open', 'closed',
            '$slug', '', '', '$date', '$gmdate', '', 0, '$domain/?post_type=product&p=$wp_set_id', 'product')";
    }

    /**
     * Build the set-relations INSERT row: wp id -> rental id -> division id.
     *
     * @param object $set
     * @param int    $wp_set_id
     * @return string
     */
    public function set_relations_row( $set, $wp_set_id ) {
        return "($wp_set_id, {$set->id}, {$set->division_id})";
    }

    /**
     * Build the term-relationships row that marks a set as a "simple" product.
     *
     * @param int $wp_set_id
     * @param int $simple_tt_id
     * @return string
     */
    public function simple_term_relation_row( $wp_set_id, $simple_tt_id ) {
        return "($wp_set_id, $simple_tt_id, 0)";
    }

    /**
     * Build the 40+ postmeta rows for a set as one concatenated string.
     *
     * @param object $set
     * @param int    $wp_set_id
     * @param array  $items Built set-items array (pre-serialize).
     * @param array  $flags { has_optional_items, has_hidden_items, has_groups, has_hidden_groups } — booleans.
     * @param int    $price_multiplier_id
     * @param array  $modern { groups, set_order, layout_mode } — modern-layout payload.
     * @return string
     */
    public function postmeta_rows( $set, $wp_set_id, array $items, array $flags, $price_multiplier_id, array $modern = array() ) {
        $sku                   = addslashes( $set->number );
        $tax_status            = $set->taxable ? 'taxable' : 'none';
        $hide_items_on_website = isset( $set->hide_items_on_website ) ? $set->hide_items_on_website : 0;
        $item_based_total      = isset( $set->item_based_total ) ? $set->item_based_total : 0;
        $set_max_qty           = $set->max_quantity ?? '';

        $has_optional_items = ! empty( $flags['has_optional_items'] );
        $has_hidden_items   = ! empty( $flags['has_hidden_items'] );
        $has_groups         = ! empty( $flags['has_groups'] );
        $has_hidden_groups  = ! empty( $flags['has_hidden_groups'] );

        $serialized_items = addslashes( serialize( $items ) );

        // Modern-layout postmeta values. Always emitted so readers can
        // rely on the keys being present, with safe defaults when the
        // server didn't ship grouped data or the active mode is classic.
        $groups        = isset( $modern['groups'] ) && is_array( $modern['groups'] ) ? $modern['groups'] : array();
        $set_order_raw = isset( $modern['set_order'] ) ? $modern['set_order'] : array();
        if ( is_string( $set_order_raw ) ) {
            $decoded       = json_decode( $set_order_raw, true );
            $set_order_raw = is_array( $decoded ) ? $decoded : array();
        }
        $layout_mode = isset( $modern['layout_mode'] ) ? (string) $modern['layout_mode'] : 'classic';

        $serialized_groups = addslashes( serialize( $groups ) );
        $set_order_json    = addslashes( wp_json_encode( array_values( (array) $set_order_raw ) ) );
        $layout_mode       = addslashes( $layout_mode );

        return "($wp_set_id, '_wc_review_count', '0' ),
            ($wp_set_id, '_wc_rating_count', 'a:0:{}'),
            ($wp_set_id, '_wc_average_rating', '0'),
            ($wp_set_id, '_edit_last', ''),
            ($wp_set_id, '_edit_lock', ''),
            ($wp_set_id, '_sku', '$sku'),
            ($wp_set_id, '_regular_price', '$set->rental_price'),
            ($wp_set_id, '_job_cost', '$set->job_cost'),
            ($wp_set_id, '_price_multiplier_id', '$price_multiplier_id'),
            ($wp_set_id, '_sale_price', '' ),
            ($wp_set_id, '_sale_price_dates_from', '' ),
            ($wp_set_id, '_sale_price_dates_to', '' ),
            ($wp_set_id, 'total_sales', '0' ),
            ($wp_set_id, '_tax_status', '$tax_status'),
            ($wp_set_id, '_tax_class', '' ),
            ($wp_set_id, '_manage_stock', 'no' ),
            ($wp_set_id, '_backorders', 'yes' ),
            ($wp_set_id, '_sold_individually', 'no' ),
            ($wp_set_id, '_weight', '' ),
            ($wp_set_id, '_length', '' ),
            ($wp_set_id, '_width', '' ),
            ($wp_set_id, '_height', '' ),
            ($wp_set_id, '_depth', '' ),
            ($wp_set_id, '_upsell_ids', 'a:0:{}' ),
            ($wp_set_id, '_crosssell_ids', 'a:0:{}' ),
            ($wp_set_id, '_purchase_note', '' ),
            ($wp_set_id, '_default_attributes', 'a:0:{}' ),
            ($wp_set_id, '_virtual', 'no' ),
            ($wp_set_id, '_downloadable', 'no' ),
            ($wp_set_id, '_product_image_gallery', '' ),
            ($wp_set_id, '_download_limit', '-1' ),
            ($wp_set_id, '_download_expiry', '-1' ),
            ($wp_set_id, '_stock', '1' ),
            ($wp_set_id, '_stock_status', 'instock'),
            ($wp_set_id, '_product_version', '3.2.3'),
            ($wp_set_id, '_price', '$set->rental_price'),
            ($wp_set_id, '_price_default', '$set->rental_price'),
            ($wp_set_id, 'zoo_cw_product_swatch_data', 'a:0:{}'),
            ($wp_set_id, '_thumbnail_id', ''),
            ($wp_set_id, '_rental_exempt_waiver', '$set->exempt_waiver'),
            ($wp_set_id, '_rental_is_sale', '$set->is_sale'),
            ($wp_set_id, '_rental_is_add_on', '0'),
            ($wp_set_id, '_rental_set_items_have_optional_items', '" . $has_optional_items . "'),
            ($wp_set_id, '_rental_set_items', '" . $serialized_items . "'),
            ($wp_set_id, '_rental_set_items_default', '" . $serialized_items . "'),
            ($wp_set_id, '_rental_item_based_total', '$item_based_total'),
            ($wp_set_id, '_rental_hide_items_on_website', '$hide_items_on_website'),
            ($wp_set_id, '_rental_some_hidden_items', '$has_hidden_items'),
            ($wp_set_id, '_rental_max_quantity', '$set_max_qty'),
            ($wp_set_id, '_rental_is_set', '1'),
            ($wp_set_id, '_rental_set_grouped_items', '" . $serialized_groups . "'),
            ($wp_set_id, '_rental_set_has_grouped_items', '" . ( $has_groups ? 1 : 0 ) . "'),
            ($wp_set_id, '_rental_set_some_hidden_groups', '" . ( $has_hidden_groups ? 1 : 0 ) . "'),
            ($wp_set_id, '_rental_set_order', '" . $set_order_json . "'),
            ($wp_set_id, '_rental_sets_layout_mode', '$layout_mode')";
    }

    /**
     * Build the wc_product_meta_lookup row. Only included when the outer
     * sync defined `$product_meta_lookup_sql`.
     *
     * @param object $set
     * @param int    $wp_set_id
     * @return string
     */
    public function product_meta_lookup_row( $set, $wp_set_id ) {
        $sku        = addslashes( $set->number );
        $tax_status = $set->taxable ? 'taxable' : 'none';

        return "($wp_set_id, '$sku', 0, 0, $set->rental_price, $set->rental_price, 0, 1, 'instock', '$tax_status')";
    }
}

endif;
