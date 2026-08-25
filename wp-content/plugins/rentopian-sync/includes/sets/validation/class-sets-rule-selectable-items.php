<?php
/**
 * Rental_Sets_Rule_Selectable_Items
 *
 * Mirrors the classic checks the legacy `rental_validate_cart_item`
 * already does for selectable items (entity 2) and addon variants:
 *
 *   1. Every item with `optional_items` and no auto-`has_selected`
 *      flag must have a chosen variant. The legacy code reads this
 *      from the postmeta directly, but in the modern flow the
 *      customer's pick lives in `rental_set_selections[<group_uid>]`
 *      where group_uid is the synthetic "selectable as single-select
 *      group" UID established by the modern renderer.
 *
 *   2. Every addon with `variants_optional` and no chosen variant
 *      must have a pick. Same indirection.
 *
 * In the hybrid approach, the legacy function still runs first for the
 * classic UI — it raises notices and aborts. This rule only handles
 * the modern UI's submissions, which the modern renderer funnels
 * through the unified selection POST shape. When that shape isn't
 * present (classic submit), this rule is a no-op.
 *
 * @package RentopianSync\Sets\Validation
 * @since   2.14.7
 *
 * PHP requirement: 7.4+
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'Rental_Sets_Rule_Selectable_Items', false ) ) :

class Rental_Sets_Rule_Selectable_Items extends Rental_Sets_Validation_Rule_Base {

    public function id() { return 'selectable_items'; }

    public function check( Rental_Sets_Validator_Context $ctx, Rental_Sets_Validation_Result $result ) {

        // Hidden items skip validation entirely (matches legacy behavior).
        if ( $ctx->hide_items_on_website() ) {
            return;
        }

        // No modern selections submitted → either classic UI (legacy
        // function handles it) or the renderer hasn't loaded yet.
        if ( empty( $ctx->modern_selections() ) ) {
            return;
        }

        foreach ( $ctx->set_items() as $item ) {

            if ( ! empty( $item['hidden'] ) ) {
                continue;
            }

            // 1. Item with optional_items requires a pick when more
            //    than one option exists and `has_selected` is not 1.
            //
            //    OPTIONAL items (required != 1) are exempt: choosing
            //    "No Thanks" (no selection) is a valid answer, so an empty
            //    selection must NOT raise an error. Only REQUIRED selectable
            //    items must have a variant chosen. Without this guard an
            //    optional item with variants wrongly reported
            //    "Please choose an option … (required)" when declined.
            if ( ! empty( $item['required'] )
                && ! empty( $item['optional_items'] )
                && ( empty( $item['has_selected'] ) || count( $item['optional_items'] ) > 1 ) ) {

                $synth_uid = $this->synthetic_group_uid_for_item( $item );

                if ( empty( $this->effective_selections( $ctx->selections_for_group( $synth_uid ) ) ) ) {

                    $product_id = isset( $item['product_id'] ) ? (int) $item['product_id'] : 0;
                    $product    = $product_id ? wc_get_product( $product_id ) : null;
                    $name       = $product ? $product->get_name() : __( 'item', 'rentopian-sync' );

                    $result->add_error(
                        Rental_Sets_Validation_Result::ERR_ITEM_OPTIONAL_NOT_PICKED,
                        sprintf(
                            /* translators: %s = product name */
                            __( 'Please choose a variation for "%s".', 'rentopian-sync' ),
                            esc_html( $name )
                        ),
                        array(
                            'group_uid'  => $synth_uid,
                            'product_id' => $product_id,
                        )
                    );
                }
            }

            // 2. Addons with variants_optional requiring a pick. The pick
            //    can arrive from three sources, in order of authority:
            //
            //      (a) postmeta `_rental_set_items[i].addons[j].variant_id`
            //          when the admin shipped a default variant — covers
            //          re-syncs that pre-resolve.
            //      (b) `rental_set_selections['addon-{parent}-{addon}']`
            //          when the modern configurator wrote a modern-shape
            //          selection. (Today the JS does NOT write this for
            //          addons — see writeHiddenInputs in
            //          rental-sets-modern.js — but the check stays as
            //          future-proofing for when it does.)
            //      (c) `$_POST['rental_add_ons']` — the customer's actual
            //          pick rides here both from the rentpro theme's
            //          legacy form serialization and from the Phase 2e
            //          payload consumer. The modern UI is the dominant
            //          source today, so this branch is the one that
            //          actually catches the customer's pick in the
            //          common case where the admin didn't ship a default
            //          variant.
            //
            //    Without source (c) this rule emits "Please choose a
            //    variation for the X addon" even when the customer DID
            //    pick a variation — symptom previously reported on
            //    Bikes Set 2 / Road Cycling Shoe addon.
            if ( ! empty( $item['addons'] ) && is_array( $item['addons'] ) ) {
                foreach ( $item['addons'] as $addon ) {
                    if ( ! empty( $addon['variant_id'] ) ) {
                        continue;
                    }
                    if ( empty( $addon['variants_optional'] ) ) {
                        continue;
                    }

                    // Only a REQUIRED addon that offers a GENUINE multi-variant
                    // choice must be picked. OPTIONAL addons are skippable
                    // ("No Thanks"), and a single option or a simple-product
                    // option (variant_id 0) is not a variation choice — a
                    // simple addon can never satisfy variant_id > 0. Mirrors
                    // the legacy synthesizer gate (rentopian-sync.php) so both
                    // validators agree and this class of error can't recur.
                    if ( empty( $addon['required'] ) ) {
                        continue;
                    }
                    $addon_real_variant_opts = 0;
                    foreach ( $addon['variants_optional'] as $addon_vo ) {
                        if ( is_array( $addon_vo ) && ! empty( $addon_vo['variant_id'] ) ) {
                            $addon_real_variant_opts++;
                        }
                    }
                    if ( $addon_real_variant_opts <= 1 ) {
                        continue;
                    }

                    $synth_uid = $this->synthetic_group_uid_for_addon( $addon );

                    if ( ! empty( $this->effective_selections( $ctx->selections_for_group( $synth_uid ) ) ) ) {
                        continue;
                    }

                    // Source (c) — form-first lookup. Same pattern the
                    // legacy synthesizer uses at rentopian-sync.php:1058.
                    if ( $this->addon_picked_in_form( $item, $addon ) ) {
                        continue;
                    }

                    $product_id = isset( $addon['product_id'] ) ? (int) $addon['product_id'] : 0;
                    $product    = $product_id ? wc_get_product( $product_id ) : null;
                    $name       = $product ? $product->get_name() : __( 'addon', 'rentopian-sync' );

                    $result->add_error(
                        Rental_Sets_Validation_Result::ERR_ADDON_VARIANT_NOT_PICKED,
                        sprintf(
                            /* translators: %s = addon product name */
                            __( 'Please choose a variation for the "%s" addon.', 'rentopian-sync' ),
                            esc_html( $name )
                        ),
                        array(
                            'group_uid'  => $synth_uid,
                            'product_id' => $product_id,
                            'addon_id'   => isset( $addon['id'] ) ? (int) $addon['id'] : 0,
                        )
                    );
                }
            }
        }
    }

    /**
     * The modern renderer presents selectable items as single-select
     * synthetic groups. The synthetic UID convention is
     * "sel-{set_id}-{product_id}" so the validator can find them in
     * `rental_set_selections` without colliding with real composite
     * group UIDs (which are "{division}-grp-{hash}").
     *
     * @param array $item
     * @return string
     */
    protected function synthetic_group_uid_for_item( $item ) {
        $sid = isset( $item['inventory_sets_id'] ) ? (int) $item['inventory_sets_id'] : 0;
        $pid = isset( $item['product_id'] ) ? (int) $item['product_id'] : 0;
        return 'sel-' . $sid . '-' . $pid;
    }

    /**
     * @param array $addon
     * @return string
     */
    protected function synthetic_group_uid_for_addon( $addon ) {
        $pid    = isset( $addon['product_id'] ) ? (int) $addon['product_id'] : 0;
        $parent = isset( $addon['parent_product_id'] ) ? (int) $addon['parent_product_id'] : 0;
        return 'addon-' . $parent . '-' . $pid;
    }

    /**
     * Form-first lookup: did the customer pick a variant for this addon
     * in the submitted form? Mirrors `rental_validate_cart_item`'s
     * lookup at rentopian-sync.php:1058 so the modern rule and the
     * legacy synthesizer agree on what counts as "the customer picked
     * something".
     *
     * Two shapes are honoured because this rule runs at WC priority 15,
     * which means BOTH:
     *
     *   - Nested (post-legacy-synthesizer): the legacy synthesizer at
     *     priority 10 rewrote `$_POST['rental_add_ons']` so each set
     *     item is a top-level entry with its addons nested under
     *     `['addons'][...]`. The variant_id inside those nested rows
     *     already reflects any form-first override the synthesizer
     *     applied.
     *
     *   - Flat (Phase 2e payload-consumer shape): the consumer at
     *     priority 8 writes each addon as a top-level entry with a
     *     `parent_set_item_product_id` pointing back at its host set
     *     item. The legacy synthesizer normally rewrites these into
     *     nested form before priority 15, but checking the flat shape
     *     too keeps the rule robust if some future plugin disables
     *     the legacy rewrite (e.g. via `__rental_set_payload_consumed`
     *     short-circuits down the line).
     *
     * @param array $set_item Postmeta row for the parent set item.
     * @param array $addon    Postmeta row for the addon being checked.
     * @return bool True when a non-zero variant was found in the form.
     */
    protected function addon_picked_in_form( $set_item, $addon ) {
        if ( empty( $_POST['rental_add_ons'] ) || ! is_array( $_POST['rental_add_ons'] ) ) {
            return false;
        }

        $parent_pid = isset( $set_item['product_id'] ) ? (int) $set_item['product_id'] : 0;
        $addon_pid  = isset( $addon['product_id'] )    ? (int) $addon['product_id']    : 0;
        if ( $parent_pid <= 0 || $addon_pid <= 0 ) {
            return false;
        }

        foreach ( $_POST['rental_add_ons'] as $entry ) {
            if ( ! is_array( $entry ) ) {
                continue;
            }

            // Nested shape: top-level set-item entry with addons[] inside.
            $entry_pid = isset( $entry['product_id'] ) ? (int) $entry['product_id'] : 0;
            if ( $entry_pid === $parent_pid && ! empty( $entry['addons'] ) && is_array( $entry['addons'] ) ) {
                foreach ( $entry['addons'] as $nested ) {
                    if ( ! is_array( $nested ) ) {
                        continue;
                    }
                    $nested_pid = isset( $nested['product_id'] ) ? (int) $nested['product_id'] : 0;
                    if ( $nested_pid !== $addon_pid ) {
                        continue;
                    }
                    $nested_vid = isset( $nested['variant_id'] ) ? (int) $nested['variant_id'] : 0;
                    if ( $nested_vid > 0 ) {
                        return true;
                    }
                }
            }

            // Flat shape: addon entry sitting at top level with
            // parent_set_item_product_id pointing at its host.
            $flat_parent = isset( $entry['parent_set_item_product_id'] ) ? (int) $entry['parent_set_item_product_id'] : 0;
            $flat_prod   = isset( $entry['product_id'] )                 ? (int) $entry['product_id']                 : 0;
            $flat_var    = isset( $entry['variant_id'] )                 ? (int) $entry['variant_id']                 : 0;
            if ( $flat_parent === $parent_pid && $flat_prod === $addon_pid && $flat_var > 0 ) {
                return true;
            }
        }

        return false;
    }
}

endif;
