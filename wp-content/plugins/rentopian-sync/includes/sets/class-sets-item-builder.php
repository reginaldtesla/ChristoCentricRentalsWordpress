<?php
/**
 * Rental_Sets_Item_Builder
 *
 * Given a single API set object and the context's product/variant maps,
 * returns the `$set_items` array that gets serialized into the
 * `_rental_set_items` postmeta. Also surfaces three flags the outer
 * loop needs: whether the set has any optional-item entries, whether
 * any item is marked hidden, and whether any addons are present.
 *
 * Item resolution handles three concerns:
 *
 *  1. Core item — one entry per element of `$set->items`. Each carries
 *     its own rental_product_id / rental_variant_id and resolves them
 *     to wp ids via $products_data and $variant_ids (division-aware).
 *  2. Optional items — when an item has `optional_items`, those are
 *     unpacked into a nested list. An item with optional_items gets
 *     its variant id cleared unless `has_selected` is set.
 *  3. Addons — when an item has `addons`, each addon carries its own
 *     optional variants. Addon ids/variants are resolved the same way.
 *
 * The builder is pure: it reads the context and the set, writes nothing.
 *
 * @package RentopianSync\Sets
 * @since   2.14.7
 *
 * PHP requirement: 7.4+
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'Rental_Sets_Item_Builder', false ) ) :

class Rental_Sets_Item_Builder {

    /**
     * Build the items array for one set.
     *
     * @param object                     $set Set object from the API.
     * @param int                        $wp_set_id The wp post id assigned to this set.
     * @param Rental_Sets_Sync_Context   $ctx
     * @return array {
     *     @type array $items              The set_items payload (ready to serialize).
     *     @type bool  $has_optional_items
     *     @type bool  $has_hidden_items
     *     @type bool  $has_addons
     *     @type int[] $marker_ids         wp product/variant ids that belong to this set.
     * }
     */
    public function build( $set, $wp_set_id, Rental_Sets_Sync_Context $ctx ) {
        $products_data = $ctx->products_data();
        $variant_ids   = $ctx->variant_ids();

        $items_raw = isset( $set->items ) ? json_decode( $set->items ) : null;

        $items              = array();
        $has_optional_items = false;
        $has_hidden_items   = false;
        $has_addons         = false;
        $marker_ids         = array();

        if ( empty( $items_raw ) ) {
            return array(
                'items'              => $items,
                'has_optional_items' => $has_optional_items,
                'has_hidden_items'   => $has_hidden_items,
                'has_addons'         => $has_addons,
                'marker_ids'         => $marker_ids,
            );
        }

        foreach ( $items_raw as $item ) {
            // The legacy guard: either the item resolves to a real wp product,
            // or it carries optional_items and a non-zero product_id. Anything
            // else is skipped.
            $resolves_to_product = isset( $products_data[ $item->product_id ] )
                && isset( $products_data[ $item->product_id ]['ids'][ $item->division_id ] );

            $has_optional_sub_items = isset( $item->optional_items ) && $item->product_id;

            if ( ! $resolves_to_product && ! $has_optional_sub_items ) {
                continue;
            }

            // Item's own wp product id. If it carries optional_items we don't
            // have a single product to point at — fall back to the rental id.
            $set_item_product_id = $resolves_to_product
                ? $products_data[ $item->product_id ]['ids'][ $item->division_id ]
                : ( $item->product_id ? $item->product_id : 0 );

            // Variant id: cleared when the item carries optional_items, unless
            // has_selected is true (then we respect the selected variant).
            $set_item_variant_id = isset( $item->optional_items )
                ? 0
                : ( isset( $variant_ids[ $item->variant_id ][ $item->division_id ] )
                    ? $variant_ids[ $item->variant_id ][ $item->division_id ]
                    : null );

            if ( isset( $item->has_selected ) && $item->has_selected && $item->variant_id ) {
                $set_item_variant_id = isset( $variant_ids[ $item->variant_id ][ $item->division_id ] )
                    ? $variant_ids[ $item->variant_id ][ $item->division_id ]
                    : null;
            }

            $optional_items = $this->build_optional_items( $item, $products_data, $variant_ids );
            if ( ! empty( $optional_items ) ) {
                $has_optional_items = true;
            }

            $addons = $this->build_addons( $item, $wp_set_id, $set_item_product_id, $set_item_variant_id, $products_data, $variant_ids );
            if ( ! empty( $addons ) ) {
                $has_addons = true;
            }

            if ( isset( $item->hidden ) && $item->hidden ) {
                $has_hidden_items = true;
            }

            // The optional-price flag fires when has_selected is set OR when
            // there's fewer than two optional items (so there's nothing to
            // pick between and the price can be locked in immediately).
            $optional_item_price_update_needed =
                ( ( isset( $item->has_selected ) && $item->has_selected ) || count( $optional_items ) < 2 ) ? 1 : 0;

            // UID + division + inventory identifiers are required by
            // the modern renderer (Rental_Sets_Section_Presenter) to:
            //   - match items against `_rental_set_order` (Pass 1)
            //   - key items into the simple-item lookup map (Pass 2)
            //
            // The bulk feed (`InventorySets::getForApiRaw`) does NOT
            // ship a per-item `uid` — only `inventory_id`, `division_id`,
            // and the rental product/variant IDs. So `uid` ends up empty
            // here and the presenter falls through to its
            // "{division_id}-{inv_id}" fallback. We MUST persist `inv_id`
            // (renamed from API's `inventory_id` to match the WP-side
            // convention used everywhere else in this plugin) for that
            // fallback to produce a non-empty key. Without it the
            // presenter's `$by_uid_simple` map ends up empty and the
            // modern renderer paints nothing for simple-items sets.
            //
            // For classic-mode reads these keys are ignored, so adding
            // them is a no-op for any existing code path.
            $rental_uid  = isset( $item->uid ) ? (string) $item->uid : '';
            $division_id = isset( $item->division_id ) ? (int) $item->division_id : 0;
            $inv_id      = isset( $item->inventory_id ) ? (int) $item->inventory_id : 0;

            $items[] = array(
                'uid'                               => $rental_uid,
                'division_id'                       => $division_id,
                'inv_id'                            => $inv_id,
                'rental_variant_id'                 => $item->variant_id ?? 0,
                'rental_product_id'                 => $item->product_id ?? 0,
                'parent_set_id'                     => $wp_set_id,
                'product_id'                        => $set_item_product_id,
                'variant_id'                        => $set_item_variant_id,
                'quantity'                          => $item->quantity,
                'optional_items'                    => $optional_items,
                'has_selected'                      => isset( $item->has_selected ) && $item->has_selected ? 1 : 0,
                'optional_item_price_update_needed' => $optional_item_price_update_needed,
                'hidden'                            => isset( $item->hidden ) && $item->hidden ? 1 : 0,
                'price'                             => $item->price ?? 0,
                'separate_price'                    => $item->separate_price ?? 0,
                'required'                          => $item->required ?? 0,
                'addons'                            => $addons,
            );

            // Items without optional_items represent a concrete component
            // of this set — their wp product id (and variant id, when
            // present) go into $set_items_collection so downstream code
            // can tell "this wp post belongs to a set".
            if ( ! isset( $item->optional_items ) && $resolves_to_product ) {
                $marker_ids[] = $products_data[ $item->product_id ]['ids'][ $item->division_id ];
                if ( isset( $variant_ids[ $item->variant_id ][ $item->division_id ] ) ) {
                    $marker_ids[] = $variant_ids[ $item->variant_id ][ $item->division_id ];
                }
            }
        }

        return array(
            'items'              => $items,
            'has_optional_items' => $has_optional_items,
            'has_hidden_items'   => $has_hidden_items,
            'has_addons'         => $has_addons,
            'marker_ids'         => $marker_ids,
        );
    }

    /**
     * Items with optional_items — resolve each option to its wp ids.
     * Note: this indexes `$products_data[...]['ids'][...]` directly. The outer guard in build() ensures we only get here
     * when the product_id is at least present in the feed.
     *
     * @param object $item
     * @param array  $products_data
     * @param array  $variant_ids
     * @return array
     */
    protected function build_optional_items( $item, array $products_data, array $variant_ids ) {
        if ( ! isset( $item->optional_items ) ) {
            return array();
        }

        $out = array();
        foreach ( $item->optional_items as $opt ) {
            // The bulk feed ships each optional row with `inventory_id`
            // (= inventory table primary key) and `division_id`. We
            // persist both as `inv_id` / `division_id` on the WP side
            // for parity with top-level set items — these are the keys
            // Rental_Sets_Section_Presenter::resolve_simple_item_uid and
            // the cart-side rules consume. Missing either of these was
            // the reason every option in the modern renderer rendered
            // with `data-inv-id="0"`.
            $opt_div     = isset( $opt->division_id ) ? (int) $opt->division_id : 0;
            $opt_inv_id  = isset( $opt->inventory_id ) ? (int) $opt->inventory_id : 0;

            $out[] = array(
                'rental_product_id' => $opt->product_id,
                'rental_variant_id' => $opt->variant_id,
                'product_id'        => $products_data[ $opt->product_id ]['ids'][ $opt->division_id ],
                'variant_id'        => isset( $variant_ids[ $opt->variant_id ][ $opt->division_id ] )
                    ? $variant_ids[ $opt->variant_id ][ $opt->division_id ]
                    : null,
                'division_id'       => $opt_div,
                'inv_id'            => $opt_inv_id,
                'quantity'          => $opt->quantity,
                'is_selected'       => isset( $opt->is_selected ) && $opt->is_selected ? 1 : 0,
            );
        }
        return $out;
    }

    /**
     * Addons attached to an item. Each addon carries its own
     * `variants_optional` list which is expanded here too.
     *
     * @param object $item
     * @param int    $wp_set_id
     * @param int    $parent_product_id
     * @param int|null $parent_variant_id
     * @param array  $products_data
     * @param array  $variant_ids
     * @return array
     */
    protected function build_addons( $item, $wp_set_id, $parent_product_id, $parent_variant_id, array $products_data, array $variant_ids ) {
        if ( ! isset( $item->addons ) || ! $item->addons ) {
            return array();
        }

        $out = array();
        foreach ( $item->addons as $addon ) {

            $variants_optional = array();
            if ( isset( $addon->variants_optional ) && $addon->variants_optional ) {
                foreach ( $addon->variants_optional as $variant ) {
                    $variants_optional[] = array(
                        'product_id'        => isset( $products_data[ $variant->product_id ] ) && isset( $products_data[ $variant->product_id ]['ids'][ $variant->division_id ] )
                            ? $products_data[ $variant->product_id ]['ids'][ $variant->division_id ]
                            : 0,
                        'rental_product_id' => $variant->product_id,
                        'variant_id'        => $variant->variant_id
                            && isset( $variant_ids[ $variant->variant_id ] )
                            && isset( $variant_ids[ $variant->variant_id ][ $variant->division_id ] )
                            ? $variant_ids[ $variant->variant_id ][ $variant->division_id ]
                            : 0,
                        'rental_variant_id' => $variant->variant_id,
                        'quantity'          => $variant->quantity,
                        'default'           => $variant->default,
                    );
                }
            }

            $add_on_id = isset( $products_data[ $addon->product_id ] )
                && isset( $products_data[ $addon->product_id ]['ids'][ $addon->division_id ] )
                ? $products_data[ $addon->product_id ]['ids'][ $addon->division_id ]
                : 0;

            $add_on_variant_id = $addon->add_on_variant_id
                && isset( $variant_ids[ $addon->add_on_variant_id ] )
                && isset( $variant_ids[ $addon->add_on_variant_id ][ $addon->division_id ] )
                ? $variant_ids[ $addon->add_on_variant_id ][ $addon->division_id ]
                : 0;

            $out[] = array(
                'parent_set_id'         => $wp_set_id,
                'parent_set_product_id' => $parent_product_id,
                'parent_set_variant_id' => $parent_variant_id,
                'rental_inv_id'         => $addon->id,
                'product_id'            => $add_on_id,
                'rental_product_id'     => $addon->product_id,
                'variant_id'            => $add_on_variant_id,
                'rental_variant_id'     => $addon->add_on_variant_id,
                'quantity'              => $addon->add_on_quantity,
                'price'                 => $addon->price,
                'required'              => $addon->required,
                'hidden'                => isset( $addon->hidden ) ? $addon->hidden : 0,
                'product_price'         => isset( $addon->product_price ) ? $addon->product_price : 0,
                'inherit_price'         => isset( $addon->inherit_price ) ? $addon->inherit_price : 0,
                'variants_optional'     => $variants_optional,
            );
        }
        return $out;
    }
}

endif;
