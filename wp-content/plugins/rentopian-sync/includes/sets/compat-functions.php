<?php
/**
 * Sets Module — Backward-compatible function aliases.
 *
 * These thin wrappers keep every existing call-site working unchanged.
 * They delegate to the new OOP classes. When the original functions
 * inside `functions.php` are collapsed to one-line wrappers, the
 * public API surface of the plugin remains exactly the same.
 *
 * @package RentopianSync\Sets
 * @since   2.14.7
 *
 * PHP requirement: 7.4+
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/*
|--------------------------------------------------------------------------
| rental_empty_set_options
|--------------------------------------------------------------------------
*/
if ( ! function_exists( 'rental_empty_set_options' ) ) {
    /**
     * Drop-in replacement for the legacy rental_empty_set_options().
     *
     * @return bool
     * @throws RentalException On SQL error.
     */
    function rental_empty_set_options() {
        return ( new Rental_Sets_Options_Repository() )->empty_options();
    }
}

/*
|--------------------------------------------------------------------------
| rental_add_set_options
|--------------------------------------------------------------------------
*/
if ( ! function_exists( 'rental_add_set_options' ) ) {
    /**
     * Drop-in replacement for the legacy rental_add_set_options().
     *
     * @return bool
     * @throws RentalException On SQL error.
     */
    function rental_add_set_options() {
        return ( new Rental_Sets_Options_Repository() )->sync_options();
    }
}

/*
|--------------------------------------------------------------------------
| get_set_options
|--------------------------------------------------------------------------
*/
if ( ! function_exists( 'get_set_options' ) ) {
    /**
     * Drop-in replacement for the legacy get_set_options().
     *
     * @param int $set_id WordPress post ID of the set.
     * @return array
     */
    function get_set_options( $set_id ) {
        return ( new Rental_Sets_Options_Query() )->get_options_for_set( $set_id );
    }
}

/*
|--------------------------------------------------------------------------
| get_once_per_order_set_options_by_option_ids
|--------------------------------------------------------------------------
*/
if ( ! function_exists( 'get_once_per_order_set_options_by_option_ids' ) ) {
    /**
     * Drop-in replacement for the legacy helper.
     *
     * @param int[] $set_option_ids
     * @return array
     */
    function get_once_per_order_set_options_by_option_ids( $set_option_ids ) {
        return ( new Rental_Sets_Options_Query() )->get_once_per_order_options( $set_option_ids );
    }
}

/*
|--------------------------------------------------------------------------
| rental_order_options_of_sets_check
|--------------------------------------------------------------------------
*/
if ( ! function_exists( 'rental_order_options_of_sets_check' ) ) {
    /**
     * Drop-in replacement for the legacy once-per-order cart check.
     *
     * @return bool
     */
    function rental_order_options_of_sets_check() {
        return ( new Rental_Sets_Options_Query() )->cart_has_once_per_order_set_option();
    }
}

/*
|--------------------------------------------------------------------------
| rental_get_sets_divisions
|--------------------------------------------------------------------------
*/
if ( ! function_exists( 'rental_get_sets_divisions' ) ) {
    /**
     * Drop-in replacement for the legacy rental_get_sets_divisions().
     *
     * @return array
     */
    function rental_get_sets_divisions() {
        return ( new Rental_Sets_Divisions_Query() )->get();
    }
}

/*
|--------------------------------------------------------------------------
| rental_set_sets_up_sells_cross_sells
|--------------------------------------------------------------------------
*/
if ( ! function_exists( 'rental_set_sets_up_sells_cross_sells' ) ) {
    /**
     * Write `_upsell_ids` / `_crosssell_ids` onto set posts.
     *
     * @param array $set_up_sells_from_sets
     * @param array $set_up_sells_from_products
     * @param array $set_cross_sells_from_sets
     * @param array $set_cross_sells_from_products
     * @param array $set_ids                         rental_set_id => wp_set_id
     * @param array $product_ids_for_up_cross_sells  rental_product_id => [wp_product_id, ...]
     * @return void
     */
    function rental_set_sets_up_sells_cross_sells(
        $set_up_sells_from_sets,
        $set_up_sells_from_products,
        $set_cross_sells_from_sets,
        $set_cross_sells_from_products,
        $set_ids,
        $product_ids_for_up_cross_sells
    ) {
        ( new Rental_Sets_Up_Cross_Sells_Processor() )->run(
            $set_up_sells_from_sets,
            $set_up_sells_from_products,
            $set_cross_sells_from_sets,
            $set_cross_sells_from_products,
            $set_ids,
            $product_ids_for_up_cross_sells
        );
    }
}

/*
|--------------------------------------------------------------------------
| rental_run_sets_sync_pass
|--------------------------------------------------------------------------
| One-call entry point for the big sets loop inside rental_synchronization.
| Takes the same inputs the inline loop used to read, and returns every
| piece the outer function then merges back into its running arrays.
*/
if ( ! function_exists( 'rental_run_sets_sync_pass' ) ) {
    /**
     * Run the sets sync pass.
     *
     * Expected $input keys:
     *   sets                          array    from inventories/sets
     *   starting_id                   int      last $variant_id the outer pass used
     *   products_data                 array    rental_product_id => ['ids' => [division => wp_id]]
     *   variant_ids                   array    rental_variant_id => [division => wp_id]
     *   simple_tt_id                  int      term_taxonomy_id of the "simple" product type
     *   date                          string
     *   gmdate                        string
     *   domain                        string
     *   products_slug                 array    running slug-counter
     *   rental_img_variant_rel        array    running image->ids map
     *   coupons_excluded_product_ids  int[]    running non-discountable list
     *   has_product_meta_lookup_sql   bool     whether the caller defined $product_meta_lookup_sql
     *
     * @param array $input
     * @return Rental_Sets_Sync_Result
     */
    function rental_run_sets_sync_pass( array $input ) {
        $ctx = new Rental_Sets_Sync_Context( $input );
        return ( new Rental_Sets_Processor( $ctx ) )->run();
    }
}

/*
|--------------------------------------------------------------------------
| rental_link_category_to_sets
|--------------------------------------------------------------------------
| Thin wrapper over the static method. One call replaces the sets block
| inside the categories loop.
*/
if ( ! function_exists( 'rental_link_category_to_sets' ) ) {
    /**
     * @param object $category
     * @param int    $cat_id
     * @param int    $parent_cat_id
     * @param array  $set_ids
     * @param array  &$term_relation_sql
     * @param int    &$cat_products_count
     * @param array  &$category_products
     * @return void
     */
    function rental_link_category_to_sets(
        $category,
        $cat_id,
        $parent_cat_id,
        array $set_ids,
        array &$term_relation_sql,
        &$cat_products_count,
        array &$category_products
    ) {
        Rental_Sets_Categories_Linker::link_category_to_sets(
            $category,
            $cat_id,
            $parent_cat_id,
            $set_ids,
            $term_relation_sql,
            $cat_products_count,
            $category_products
        );
    }
}

/*
|--------------------------------------------------------------------------
| rental_detect_similar_sets_tags_for_product_tag
| rental_build_sets_tags_sql
|--------------------------------------------------------------------------
| Paired helpers for the tags pass. The first runs inside the product-
| tags loop; the second runs after it to build rows for the sets tags.
*/
if ( ! function_exists( 'rental_detect_similar_sets_tags_for_product_tag' ) ) {
    /**
     * @param string $product_tag_title
     * @param array  $sets_tags
     * @param array  &$similar_tags
     * @return void
     */
    function rental_detect_similar_sets_tags_for_product_tag( $product_tag_title, $sets_tags, array &$similar_tags ) {
        Rental_Sets_Tags_Processor::detect_similar_for_product_tag( $product_tag_title, $sets_tags, $similar_tags );
    }
}

/*
|--------------------------------------------------------------------------
| rental_get_cart_item_hidden_style
|--------------------------------------------------------------------------
| Drop-in replacement for the inline visibility logic that was previously
| duplicated in every cart / mini-cart / checkout-review template.
*/
if ( ! function_exists( 'rental_get_cart_item_hidden_style' ) ) {
    /**
     * Return 'display:none' when the given cart item should be hidden based
     * on rental-set configuration, or '' when it should be visible.
     *
     * @param int $product_id   cart_item['product_id']  (always the parent)
     * @param int $variation_id cart_item['variation_id'] (0 when no variant)
     * @return string
     */
    function rental_get_cart_item_hidden_style( int $product_id, int $variation_id = 0 ): string {
        return Rental_Sets_Cart_Visibility::instance()->get_hidden_style( $product_id, $variation_id );
    }
}

/*
|--------------------------------------------------------------------------
| rental_should_hide_order_item
|--------------------------------------------------------------------------
| Drop-in replacement for the inline visibility logic that was previously
| duplicated in the order-details template.
*/
if ( ! function_exists( 'rental_should_hide_order_item' ) ) {
    /**
     * Return true when the given WooCommerce order line-item should be
     * skipped (hidden) based on rental-set configuration.
     *
     * @param WC_Order              $order
     * @param WC_Order_Item_Product $item
     * @return bool
     */
    function rental_should_hide_order_item( WC_Order $order, WC_Order_Item_Product $item ): bool {
        return Rental_Sets_Cart_Visibility::for_order( $order )->is_order_item_hidden( $item );
    }
}

if ( ! function_exists( 'rental_build_sets_tags_sql' ) ) {
    /**
     * @param array $sets_tags
     * @param int   $cat_id_base
     * @param array $similar_tags
     * @param array $set_ids
     * @param array &$terms_sql
     * @param array &$termmeta_sql
     * @param array &$term_taxonomy_sql
     * @param array &$term_relation_sql
     * @param array &$sets_tag_relations_sql
     * @return void
     */
    function rental_build_sets_tags_sql(
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
        Rental_Sets_Tags_Processor::build_sets_tags_sql(
            $sets_tags,
            $cat_id_base,
            $similar_tags,
            $set_ids,
            $terms_sql,
            $termmeta_sql,
            $term_taxonomy_sql,
            $term_relation_sql,
            $sets_tag_relations_sql
        );
    }
}

/*
|--------------------------------------------------------------------------
| Modern composite-group cart-item readers
|--------------------------------------------------------------------------
| Lightweight free-function wrappers around Rental_Sets_Cart_Meta so cart
| display code, theme templates and downstream handlers can branch on
| group provenance without naming the class explicitly.
*/
if ( ! function_exists( 'rental_sets_cart_item_is_group_child' ) ) {
    /**
     * True when this cart line was added from a composite-group selection.
     *
     * @param array $cart_item
     * @return bool
     */
    function rental_sets_cart_item_is_group_child( $cart_item ) {
        return Rental_Sets_Cart_Meta::is_group_child( $cart_item );
    }
}

if ( ! function_exists( 'rental_sets_cart_item_group_meta' ) ) {
    /**
     * Read all known group meta values off a cart item.
     *
     * @param array $cart_item
     * @return array<string,mixed>
     */
    function rental_sets_cart_item_group_meta( $cart_item ) {
        $out = array();
        foreach ( Rental_Sets_Cart_Meta::all_keys() as $key ) {
            if ( isset( $cart_item[ $key ] ) ) {
                $out[ $key ] = $cart_item[ $key ];
            }
        }
        return $out;
    }
}

if ( ! function_exists( 'rental_sets_order_item_group_meta' ) ) {
    /**
     * Read all known group meta values off an order line item.
     *
     * @param WC_Order_Item $item
     * @return array<string,mixed>
     */
    function rental_sets_order_item_group_meta( $item ) {
        $out = array();
        if ( ! is_object( $item ) || ! method_exists( $item, 'get_meta' ) ) {
            return $out;
        }
        foreach ( Rental_Sets_Cart_Meta::all_keys() as $key ) {
            $val = $item->get_meta( Rental_Sets_Cart_Meta::to_order_item_key( $key ), true );
            if ( '' !== $val && null !== $val ) {
                $out[ $key ] = $val;
            }
        }
        return $out;
    }
}

/*
|--------------------------------------------------------------------------
| Modern cart-edit / archive / prefill helpers
|--------------------------------------------------------------------------
| Tiny wrappers so theme code can branch on edit-mode / build the edit URL /
| read the prefill payload without naming class internals directly.
*/
if ( ! function_exists( 'rental_sets_get_cart_edit_url' ) ) {
    /**
     * Build the URL that opens the product page in edit mode for a given
     * cart line. Returns an empty string if the line isn't a set parent.
     *
     * @param string $cart_item_key
     * @return string
     */
    function rental_sets_get_cart_edit_url( $cart_item_key ) {
        if ( ! function_exists( 'WC' ) || ! WC() || ! WC()->cart ) {
            return '';
        }
        $contents = WC()->cart->cart_contents;
        if ( ! isset( $contents[ $cart_item_key ] ) ) {
            return '';
        }
        $item = $contents[ $cart_item_key ];
        if ( ! empty( $item['rental_add_on_of'] ) ) {
            return '';
        }
        $product_id = isset( $item['product_id'] ) ? (int) $item['product_id'] : 0;
        if ( ! $product_id ) {
            return '';
        }
        $permalink = get_permalink( $product_id );
        if ( ! $permalink ) {
            return '';
        }
        return add_query_arg(
            array( Rental_Sets_Cart_Edit_Mode::QUERY_VAR => $cart_item_key ),
            $permalink
        );
    }
}

if ( ! function_exists( 'rental_sets_is_edit_mode' ) ) {
    /**
     * True when the current request is a product-page edit-mode session
     * for a previously-added cart line.
     *
     * @return bool
     */
    function rental_sets_is_edit_mode() {
        $inst = Rental_Sets_Cart_Edit_Mode::instance();
        return null !== $inst && null !== $inst->get_editing_key();
    }
}

if ( ! function_exists( 'rental_sets_editing_cart_key' ) ) {
    /**
     * The cart item key currently being edited, or null.
     *
     * @return string|null
     */
    function rental_sets_editing_cart_key() {
        $inst = Rental_Sets_Cart_Edit_Mode::instance();
        return $inst ? $inst->get_editing_key() : null;
    }
}
