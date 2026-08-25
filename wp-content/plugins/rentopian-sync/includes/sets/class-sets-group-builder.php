<?php
/**
 * Rental_Sets_Group_Builder
 *
 * Resolves the third item entity — composite (grouped) items — for a
 * single set. Mirrors the shape of Rental_Sets_Item_Builder: takes the
 * raw set object plus the sync context, returns a structured array
 * that the SQL builder can serialise into postmeta.
 *
 * The server emits `grouped_items` as an array of group objects shaped
 * like:
 *   {
 *     group_id, set_id, uid, group_name, group_description,
 *     group_price, group_quantity, group_quantity_min, group_quantity_max,
 *     multiple_selection, required, separate_price, hide_on_website,
 *     items_order: [uid, uid, ...],
 *     items: [
 *       { group_item_id, set_group_id, set_id, inv_id, uid, price,
 *         quantity, is_default, product_id, variant_id, division_id,
 *         rental_price, sale_price, product_name, variant_name,
 *         variant_img_id, product_img_id }
 *     ]
 *   }
 *
 * Each item inside a group is keyed off `inv_id` on the server, but on
 * the WP side we resolve it through the same `$variant_ids` /
 * `$products_data` maps the rest of the sync uses, division-aware.
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

if ( ! class_exists( 'Rental_Sets_Group_Builder', false ) ) :

class Rental_Sets_Group_Builder {

    /**
     * Build the grouped-items array for one set.
     *
     * Returns a structured payload + flags + marker_ids. The marker_ids
     * list contains every wp product/variant id that appears as a child
     * of any group, so the processor can record them in
     * $set_items_collection just like flat items do.
     *
     * @param object                   $set Set object from the API.
     * @param int                      $wp_set_id The wp post id assigned to this set.
     * @param Rental_Sets_Sync_Context $ctx
     * @return array {
     *     @type array $groups            Group payload ready to serialize.
     *     @type bool  $has_groups        True when at least one group was emitted.
     *     @type bool  $has_hidden_groups True when at least one group is hide_on_website=1.
     *     @type int[] $marker_ids        Wp product/variant ids belonging to groups.
     * }
     */
    public function build( $set, $wp_set_id, Rental_Sets_Sync_Context $ctx ) {
        $groups            = array();
        $has_groups        = false;
        $has_hidden_groups = false;
        $marker_ids        = array();

        $raw = isset( $set->grouped_items ) ? $set->grouped_items : null;
        if ( empty( $raw ) ) {
            return array(
                'groups'            => $groups,
                'has_groups'        => $has_groups,
                'has_hidden_groups' => $has_hidden_groups,
                'marker_ids'        => $marker_ids,
            );
        }

        // The payload may arrive as JSON-encoded string (legacy webhook
        // shape) or as a decoded array/object. Normalise once.
        if ( is_string( $raw ) ) {
            $decoded = json_decode( $raw );
            $raw     = is_array( $decoded ) || is_object( $decoded ) ? $decoded : null;
        }
        if ( empty( $raw ) ) {
            return array(
                'groups'            => $groups,
                'has_groups'        => $has_groups,
                'has_hidden_groups' => $has_hidden_groups,
                'marker_ids'        => $marker_ids,
            );
        }

        $products_data = $ctx->products_data();
        $variant_ids   = $ctx->variant_ids();

        foreach ( $raw as $group ) {
            // Server returns a list — but tolerate associative shape too.
            $g = (object) $group;

            $items_built = $this->build_group_items( $g, $products_data, $variant_ids, $marker_ids );

            $groups[] = array(
                'rental_group_id'    => isset( $g->group_id ) ? (int) $g->group_id : 0,
                'parent_set_id'      => $wp_set_id,
                'uid'                => isset( $g->uid ) ? (string) $g->uid : '',
                'group_name'         => isset( $g->group_name ) ? (string) $g->group_name : '',
                'group_description'  => isset( $g->group_description ) ? (string) $g->group_description : '',
                'group_price'        => isset( $g->group_price ) ? $g->group_price : '',
                'group_quantity'     => isset( $g->group_quantity ) ? (int) $g->group_quantity : 1,
                'group_quantity_min' => isset( $g->group_quantity_min ) ? (int) $g->group_quantity_min : 0,
                'group_quantity_max' => isset( $g->group_quantity_max ) ? (int) $g->group_quantity_max : 0,
                'multiple_selection' => isset( $g->multiple_selection ) && $g->multiple_selection ? 1 : 0,
                'required'           => isset( $g->required ) && $g->required ? 1 : 0,
                'separate_price'     => isset( $g->separate_price ) && $g->separate_price ? 1 : 0,
                'hide_on_website'    => isset( $g->hide_on_website ) && $g->hide_on_website ? 1 : 0,
                'items_order'        => $this->normalize_items_order( $g ),
                'items'              => $items_built,
            );

            $has_groups = true;
            if ( ! empty( $g->hide_on_website ) ) {
                $has_hidden_groups = true;
            }
        }

        return array(
            'groups'            => $groups,
            'has_groups'        => $has_groups,
            'has_hidden_groups' => $has_hidden_groups,
            'marker_ids'        => array_values( array_unique( $marker_ids ) ),
        );
    }

    /**
     * Build the resolved item list for one group. Mutates $marker_ids
     * in place — every wp id this group references gets appended.
     *
     * @param object $g            Single group object.
     * @param array  $products_data
     * @param array  $variant_ids
     * @param array  $marker_ids   Output accumulator.
     * @return array
     */
    protected function build_group_items( $g, array $products_data, array $variant_ids, array &$marker_ids ) {
        if ( empty( $g->items ) ) {
            return array();
        }

        // Explicit ordering authority — mirrors
        // Rental_Sets_Webhook_Group_Persister::build_group_items so the
        // bulk + webhook paths produce byte-equivalent, pre-ordered
        // group items. Each stored item carries an integer `ordering`
        // resolved from the group's `items_order` (drag-drop UID list);
        // unlisted items keep their payload position after the ordered
        // block. The output is finally usort()ed by `ordering` so the
        // renderer never depends on PHP array iteration order.
        $order_index = array();
        $items_order = $this->normalize_items_order( $g );
        foreach ( $items_order as $pos => $uid ) {
            if ( '' !== $uid && ! isset( $order_index[ $uid ] ) ) {
                $order_index[ $uid ] = $pos;
            }
        }
        $append_cursor = count( $items_order );

        $out         = array();
        $payload_pos = 0;

        foreach ( $g->items as $raw_item ) {
            // The server encodes group items as plain arrays or stdClass —
            // accept either by running everything through casts.
            $item = (array) $raw_item;

            $rental_product_id = isset( $item['product_id'] ) ? (int) $item['product_id'] : 0;
            $rental_variant_id = isset( $item['variant_id'] ) ? (int) $item['variant_id'] : 0;
            $division_id       = isset( $item['division_id'] ) ? (int) $item['division_id'] : 0;
            $rental_inv_id     = isset( $item['inv_id'] ) ? (int) $item['inv_id'] : 0;

            $wp_product_id = isset( $products_data[ $rental_product_id ]['ids'][ $division_id ] )
                ? (int) $products_data[ $rental_product_id ]['ids'][ $division_id ]
                : 0;

            $wp_variant_id = isset( $variant_ids[ $rental_variant_id ][ $division_id ] )
                ? (int) $variant_ids[ $rental_variant_id ][ $division_id ]
                : 0;

            // Skip items that don't resolve to anything we can render —
            // matches the conservative behaviour of Item_Builder.
            if ( ! $wp_product_id && ! $wp_variant_id ) {
                continue;
            }

            if ( $wp_product_id ) {
                $marker_ids[] = $wp_product_id;
            }
            if ( $wp_variant_id ) {
                $marker_ids[] = $wp_variant_id;
            }

            $uid = isset( $item['uid'] ) ? (string) $item['uid'] : '';

            if ( '' !== $uid && isset( $order_index[ $uid ] ) ) {
                $ordering = (int) $order_index[ $uid ];
            } else {
                $ordering = $append_cursor + $payload_pos;
            }
            $payload_pos++;

            $out[] = array(
                'rental_inv_id'     => $rental_inv_id,
                'rental_product_id' => $rental_product_id,
                'rental_variant_id' => $rental_variant_id,
                'product_id'        => $wp_product_id,
                'variant_id'        => $wp_variant_id,
                'uid'               => $uid,
                'ordering'          => $ordering,
                'quantity'          => isset( $item['quantity'] ) ? $item['quantity'] : 1,
                'price'             => isset( $item['price'] ) ? $item['price'] : '',
                'is_default'        => ! empty( $item['is_default'] ) ? 1 : 0,
                'rental_price'      => isset( $item['rental_price'] ) ? $item['rental_price'] : '',
                'sale_price'        => isset( $item['sale_price'] ) ? $item['sale_price'] : '',
                'product_name'      => isset( $item['product_name'] ) ? (string) $item['product_name'] : '',
                'variant_name'      => isset( $item['variant_name'] ) ? (string) $item['variant_name'] : '',
            );
        }

        usort( $out, function ( $a, $b ) {
            $oa = isset( $a['ordering'] ) ? (int) $a['ordering'] : 0;
            $ob = isset( $b['ordering'] ) ? (int) $b['ordering'] : 0;
            return $oa <=> $ob;
        } );

        return $out;
    }

    /**
     * The server's items_order is either an array (already decoded by
     * SetGroupedItemsService) or a JSON string. Normalise to a plain
     * array of strings so postmeta consumers don't have to care.
     *
     * @param object $g
     * @return string[]
     */
    protected function normalize_items_order( $g ) {
        if ( ! isset( $g->items_order ) ) {
            return array();
        }
        $val = $g->items_order;
        if ( is_string( $val ) ) {
            $decoded = json_decode( $val, true );
            $val     = is_array( $decoded ) ? $decoded : array();
        }
        if ( ! is_array( $val ) ) {
            return array();
        }
        $clean = array();
        foreach ( $val as $uid ) {
            $clean[] = (string) $uid;
        }
        return $clean;
    }
}

endif;
