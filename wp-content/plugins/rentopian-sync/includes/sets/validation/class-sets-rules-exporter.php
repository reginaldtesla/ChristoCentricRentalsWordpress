<?php
/**
 * Rental_Sets_Rules_Exporter
 *
 * Client-side mirror of the validator. Rather than re-implement the
 * rules in JS, this class serialises the static rule data per set into a
 * structure the renderer's JS can read and use to drive inline feedback
 * ("Please pick at least 6 chairs (you've picked 4)") + submit-button
 * disabled state.
 *
 * The server validator remains the source of truth — the client mirror
 * is UX-only. A malicious or buggy client can't bypass server checks.
 *
 * Output shape (consumed by the modern renderer's JS):
 *
 *   {
 *     set_id: <int>,
 *     rental_set_id: <int>,
 *     max_quantity: <int>,
 *     hide_items_on_website: <bool>,
 *     groups: [
 *       {
 *         uid: "...",
 *         group_id: <int>,
 *         name: "Chairs",
 *         required: <bool>,
 *         multiple_selection: <bool>,
 *         hide_on_website: <bool>,
 *         group_quantity: <int>,
 *         quantity_min: <int>,
 *         quantity_max: <int>,
 *         items: [{ uid, inv_id, product_id, variant_id, quantity, price, is_default, name }]
 *       },
 *       ...
 *     ],
 *     selectable_items: [
 *       { synth_uid: "sel-{set}-{prod}", product_id, options: [...] },
 *       ...
 *     ],
 *     addons_with_optional_variants: [
 *       { synth_uid: "addon-{parent}-{prod}", product_id, parent_product_id, options: [...] },
 *       ...
 *     ],
 *     error_codes: { ... }   // human messages keyed by code, for JS localisation
 *   }
 *
 * Filter `rental_sets_rules_exported` exposed for last-mile mutation.
 *
 * @package RentopianSync\Sets\Validation
 * @since   2.14.7
 *
 * PHP requirement: 7.4+
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'Rental_Sets_Rules_Exporter', false ) ) :

class Rental_Sets_Rules_Exporter {

    /**
     * Build the export for one set.
     *
     * @param int $set_id WP product id.
     * @return array
     */
    public static function build_for_set( $set_id ) {
        $set_id = (int) $set_id;

        $set_items     = get_post_meta( $set_id, '_rental_set_items', true );
        $grouped_items = get_post_meta( $set_id, '_rental_set_grouped_items', true );
        $set_items     = is_array( $set_items ) ? $set_items : array();
        $grouped_items = is_array( $grouped_items ) ? $grouped_items : array();

        $payload = array(
            'set_id'                       => $set_id,
            'rental_set_id'                => self::resolve_rental_set_id( $set_id ),
            'max_quantity'                 => (int) get_post_meta( $set_id, '_rental_max_quantity', true ),
            'hide_items_on_website'        => (bool) get_post_meta( $set_id, '_rental_hide_items_on_website', true ),
            'item_based_total'             => (bool) get_post_meta( $set_id, '_rental_item_based_total', true ),
            'groups'                       => self::groups_payload( $grouped_items ),
            'selectable_items'             => self::selectable_items_payload( $set_items ),
            'addons_with_optional_variants'=> self::addons_payload( $set_items ),
            'error_codes'                  => self::error_codes_dictionary(),
        );

        return apply_filters( 'rental_sets_rules_exported', $payload, $set_id );
    }

    /**
     * Resolve rental_set_id (core id) for the given WP product id.
     *
     * @param int $set_id
     * @return int
     */
    protected static function resolve_rental_set_id( $set_id ) {
        global $wpdb, $rental_tables;
        if ( empty( $rental_tables['set_relations'] ) ) {
            return 0;
        }
        $tbl = $wpdb->prefix . $rental_tables['set_relations'];
        return (int) $wpdb->get_var( $wpdb->prepare( "SELECT `rental_id` FROM {$tbl} WHERE `id` = %d", (int) $set_id ) );
    }

    /**
     * @param array $grouped_items
     * @return array
     */
    protected static function groups_payload( array $grouped_items ) {
        $out = array();
        foreach ( $grouped_items as $g ) {
            $items = array();
            if ( ! empty( $g['items'] ) && is_array( $g['items'] ) ) {
                foreach ( $g['items'] as $it ) {
                    $items[] = array(
                        'uid'         => isset( $it['uid'] ) ? (string) $it['uid'] : '',
                        'inv_id'      => isset( $it['rental_inv_id'] ) ? (int) $it['rental_inv_id'] : 0,
                        'product_id'  => isset( $it['product_id'] ) ? (int) $it['product_id'] : 0,
                        'variant_id'  => isset( $it['variant_id'] ) ? (int) $it['variant_id'] : 0,
                        'quantity'    => isset( $it['quantity'] ) ? (int) $it['quantity'] : 1,
                        'price'       => isset( $it['price'] ) ? $it['price'] : '',
                        'is_default'  => ! empty( $it['is_default'] ),
                        'name'        => isset( $it['variant_name'] ) ? (string) $it['variant_name'] : ( isset( $it['product_name'] ) ? (string) $it['product_name'] : '' ),
                    );
                }
            }

            $out[] = array(
                'uid'                => isset( $g['uid'] ) ? (string) $g['uid'] : '',
                'group_id'           => isset( $g['rental_group_id'] ) ? (int) $g['rental_group_id'] : 0,
                'name'               => isset( $g['group_name'] ) ? (string) $g['group_name'] : '',
                'description'        => isset( $g['group_description'] ) ? (string) $g['group_description'] : '',
                'required'           => ! empty( $g['required'] ),
                'multiple_selection' => ! empty( $g['multiple_selection'] ),
                'hide_on_website'    => ! empty( $g['hide_on_website'] ),
                'group_quantity'     => isset( $g['group_quantity'] ) ? (int) $g['group_quantity'] : 1,
                'group_price'        => isset( $g['group_price'] ) ? $g['group_price'] : null,
                'quantity_min'       => isset( $g['group_quantity_min'] ) ? (int) $g['group_quantity_min'] : 0,
                'quantity_max'       => isset( $g['group_quantity_max'] ) ? (int) $g['group_quantity_max'] : 0,
                'items'              => $items,
                'items_order'        => isset( $g['items_order'] ) && is_array( $g['items_order'] ) ? $g['items_order'] : array(),
            );
        }
        return $out;
    }

    /**
     * Selectable items rendered as single-select synthetic groups in
     * the modern UI. The synthetic UID convention is shared with
     * Rule_Selectable_Items so server + client agree.
     *
     * @param array $set_items
     * @return array
     */
    protected static function selectable_items_payload( array $set_items ) {
        $out = array();
        foreach ( $set_items as $item ) {
            if ( empty( $item['optional_items'] ) ) {
                continue;
            }
            if ( ! empty( $item['hidden'] ) ) {
                continue;
            }

            $sid = isset( $item['inventory_sets_id'] ) ? (int) $item['inventory_sets_id'] : 0;
            $pid = isset( $item['product_id'] ) ? (int) $item['product_id'] : 0;

            $options = array();
            foreach ( $item['optional_items'] as $opt ) {
                $options[] = array(
                    'product_id' => isset( $opt['product_id'] ) ? (int) $opt['product_id'] : 0,
                    'variant_id' => isset( $opt['variant_id'] ) ? (int) $opt['variant_id'] : 0,
                    'is_selected'=> ! empty( $opt['is_selected'] ),
                    'inv_id'     => isset( $opt['inventory_id'] ) ? (int) $opt['inventory_id'] : 0,
                );
            }

            // requires_choice drives the client mirror's "must pick"
            // gate. Only REQUIRED selectable items force a choice — an
            // OPTIONAL item can be declined ("No Thanks"), so it must
            // never be treated as required by the JS validator.
            $requires_choice = ! empty( $item['required'] )
                && ( empty( $item['has_selected'] ) || count( $options ) > 1 );

            $out[] = array(
                'synth_uid'        => 'sel-' . $sid . '-' . $pid,
                'product_id'       => $pid,
                'has_selected'     => ! empty( $item['has_selected'] ),
                'requires_choice'  => $requires_choice,
                'options'          => $options,
            );
        }
        return $out;
    }

    /**
     * Addons whose `variants_optional` requires a customer pick.
     *
     * @param array $set_items
     * @return array
     */
    protected static function addons_payload( array $set_items ) {
        $out = array();
        foreach ( $set_items as $item ) {
            if ( empty( $item['addons'] ) || ! is_array( $item['addons'] ) ) {
                continue;
            }
            foreach ( $item['addons'] as $addon ) {
                if ( ! empty( $addon['variant_id'] ) ) {
                    continue;
                }
                if ( empty( $addon['variants_optional'] ) ) {
                    continue;
                }

                $pid    = isset( $addon['product_id'] ) ? (int) $addon['product_id'] : 0;
                $parent = isset( $addon['parent_product_id'] ) ? (int) $addon['parent_product_id'] : 0;

                $options = array();
                foreach ( $addon['variants_optional'] as $variant ) {
                    $variant = (array) $variant;
                    $options[] = array(
                        'inv_id'     => isset( $variant['inventory_id'] ) ? (int) $variant['inventory_id'] : 0,
                        'variant_id' => isset( $variant['variant_id'] ) ? (int) $variant['variant_id'] : 0,
                        'product_id' => isset( $variant['product_id'] ) ? (int) $variant['product_id'] : 0,
                    );
                }

                $out[] = array(
                    'synth_uid'         => 'addon-' . $parent . '-' . $pid,
                    'product_id'        => $pid,
                    'parent_product_id' => $parent,
                    'options'           => $options,
                );
            }
        }
        return $out;
    }

    /**
     * Stable error-code → message map for client-side localisation.
     * The %s/%d placeholders are filled by JS using sprintf-style
     * substitutions.
     *
     * @return array
     */
    protected static function error_codes_dictionary() {
        return array(
            Rental_Sets_Validation_Result::ERR_GROUP_REQUIRED           => __( 'Please choose an option for "%s" (required).', 'rentopian-sync' ),
            Rental_Sets_Validation_Result::ERR_GROUP_MIN_QTY            => __( 'Please pick at least %2$d for "%1$s" (you\'ve picked %3$d).', 'rentopian-sync' ),
            Rental_Sets_Validation_Result::ERR_GROUP_MAX_QTY            => __( 'Please pick at most %2$d for "%1$s" (you\'ve picked %3$d).', 'rentopian-sync' ),
            Rental_Sets_Validation_Result::ERR_GROUP_MULTIPLE_SELECTION => __( 'Please choose only one option for "%s".', 'rentopian-sync' ),
            Rental_Sets_Validation_Result::ERR_ITEM_OPTIONAL_NOT_PICKED => __( 'Please choose a variation for "%s".', 'rentopian-sync' ),
            Rental_Sets_Validation_Result::ERR_ADDON_VARIANT_NOT_PICKED => __( 'Please choose a variation for the "%s" addon.', 'rentopian-sync' ),
            Rental_Sets_Validation_Result::ERR_MAX_QUANTITY             => __( 'Maximum available quantity for this item is: %d', 'rentopian-sync' ),
        );
    }
}

endif;
