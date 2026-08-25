<?php
/**
 * Rental_Sets_Rule_Group_Quantity
 *
 * Enforces the quantity-related rules per composite group, separating
 * the option-COUNT dimension from the unit-QUANTITY dimension:
 *
 *   - `multiple_selection = 0` (single-select) → at most one child may
 *     have qty > 0. The chosen option's configured quantity is the
 *     bundle size and is NOT bounded; min/max do not apply.
 *
 *   - `multiple_selection = 1` (multi-select) → the customer chooses how
 *     many units, so the unit-count (Σ child_qty) must be
 *     ≥ `quantity_min` (when set) and ≤ `quantity_max` (when set). A
 *     multi-select group can fail both bounds at once — one error per
 *     failing dimension.
 *
 * Why the split: applying a unit min/max to a single-select dropdown
 * conflates "how many options" with "how many units" and produces bogus
 * errors like "Please pick at most 1 (you've picked 6)" on a dropdown
 * whose single option carries a quantity of 6.
 *
 * Hidden groups (`hide_on_website=1`) are exempt for the same reason
 * as Rule_Group_Required.
 *
 * @package RentopianSync\Sets\Validation
 * @since   2.14.7
 *
 * PHP requirement: 7.4+
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'Rental_Sets_Rule_Group_Quantity', false ) ) :

class Rental_Sets_Rule_Group_Quantity extends Rental_Sets_Validation_Rule_Base {

    public function id() { return 'group_quantity'; }

    public function check( Rental_Sets_Validator_Context $ctx, Rental_Sets_Validation_Result $result ) {

        foreach ( $ctx->grouped_items() as $group ) {

            if ( ! empty( $group['hide_on_website'] ) ) {
                continue;
            }

            $group_uid  = isset( $group['uid'] ) ? (string) $group['uid'] : '';
            $selections = $this->effective_selections( $ctx->selections_for_group( $group_uid ) );

            // No selections at all — Rule_Group_Required already raised
            // an error if this is required. Min/max only apply when
            // there is at least one selection.
            if ( empty( $selections ) ) {
                continue;
            }

            $label     = $this->group_label( $group );
            $picked_n  = count( $selections );

            // Selection-COUNT and unit-QUANTITY are different concepts.
            //
            //   - single-select (multiple_selection = 0): the customer
            //     picks at most ONE option; that option's configured
            //     quantity is the bundle size and is NOT bounded here.
            //     Only the option COUNT is checked.
            //   - multi-select (multiple_selection = 1): the customer
            //     decides HOW MANY units, so the unit total is what
            //     quantity_min / quantity_max bound.
            //
            // Applying a unit min/max to a single-select group is the bug
            // class behind "Please pick at most 1 (you've picked 6)" on a
            // dropdown whose single option carries a quantity of 6.
            if ( empty( $group['multiple_selection'] ) ) {

                // single-select → at most one distinct child.
                if ( $picked_n > 1 ) {
                    $msg = sprintf(
                        /* translators: %s = group name */
                        __( 'Please choose only one option for "%s".', 'rentopian-sync' ),
                        esc_html( $label )
                    );
                    $result->add_error(
                        Rental_Sets_Validation_Result::ERR_GROUP_MULTIPLE_SELECTION,
                        $msg,
                        array(
                            'group_uid'  => $group_uid,
                            'group_name' => $label,
                            'picked'     => $picked_n,
                        )
                    );
                }

            } else {

                // multi-select → unit total bounded by min / max.
                $unit_sum = $this->selection_unit_count( $group, $selections );

                $min = isset( $group['group_quantity_min'] ) ? (int) $group['group_quantity_min'] : 0;
                if ( $min > 0 && $unit_sum < $min ) {
                    $msg = sprintf(
                        /* translators: 1=group name, 2=min, 3=current */
                        __( 'Please pick at least %2$d for "%1$s" (you\'ve picked %3$d).', 'rentopian-sync' ),
                        esc_html( $label ),
                        $min,
                        $unit_sum
                    );
                    $result->add_error(
                        Rental_Sets_Validation_Result::ERR_GROUP_MIN_QTY,
                        $msg,
                        array(
                            'group_uid'  => $group_uid,
                            'group_name' => $label,
                            'min'        => $min,
                            'current'    => $unit_sum,
                        )
                    );
                }

                $max = isset( $group['group_quantity_max'] ) ? (int) $group['group_quantity_max'] : 0;
                if ( $max > 0 && $unit_sum > $max ) {
                    $msg = sprintf(
                        /* translators: 1=group name, 2=max, 3=current */
                        __( 'Please pick at most %2$d for "%1$s" (you\'ve picked %3$d).', 'rentopian-sync' ),
                        esc_html( $label ),
                        $max,
                        $unit_sum
                    );
                    $result->add_error(
                        Rental_Sets_Validation_Result::ERR_GROUP_MAX_QTY,
                        $msg,
                        array(
                            'group_uid'  => $group_uid,
                            'group_name' => $label,
                            'max'        => $max,
                            'current'    => $unit_sum,
                        )
                    );
                }
            }
        }
    }
}

endif;
