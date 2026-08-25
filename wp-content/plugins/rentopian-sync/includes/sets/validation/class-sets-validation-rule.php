<?php
/**
 * Rental_Sets_Validation_Rule
 *
 * Contract for every validator rule. Each rule is a self-contained class
 * that takes a Validator_Context, optionally appends errors to a
 * Validation_Result, and returns nothing (mutation via $result).
 *
 * The contract is intentionally tiny so adding a new rule is one file:
 * implement check(), wire it in Cart_Validator's rule list.
 *
 * Why interface + abstract: the interface keeps types clean for the
 * orchestrator; the abstract gives sub-rules a couple of shared helpers
 * (notably `text_for_group` for human-friendly error messages).
 *
 * @package RentopianSync\Sets\Validation
 * @since   2.14.7
 *
 * PHP requirement: 7.4+
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! interface_exists( 'Rental_Sets_Validation_Rule', false ) ) :

interface Rental_Sets_Validation_Rule {

    /**
     * Run the rule. Append any errors to $result.
     *
     * @param Rental_Sets_Validator_Context  $ctx
     * @param Rental_Sets_Validation_Result  $result
     * @return void
     */
    public function check( Rental_Sets_Validator_Context $ctx, Rental_Sets_Validation_Result $result );

    /**
     * Stable identifier for this rule. Used in logs.
     *
     * @return string
     */
    public function id();
}

endif;

if ( ! class_exists( 'Rental_Sets_Validation_Rule_Base', false ) ) :

abstract class Rental_Sets_Validation_Rule_Base implements Rental_Sets_Validation_Rule {

    /**
     * Resolve a human-friendly group label. Falls back to the UID when
     * `group_name` is empty.
     *
     * @param array $group  One entry from `_rental_set_grouped_items`.
     * @return string
     */
    protected function group_label( $group ) {
        if ( ! empty( $group['group_name'] ) ) {
            return (string) $group['group_name'];
        }
        if ( ! empty( $group['uid'] ) ) {
            return (string) $group['uid'];
        }
        return __( 'group', 'rentopian-sync' );
    }

    /**
     * Sum of selected children's submitted quantities for a single group:
     * `Σ child_qty`. This is the UNIT count the customer dialed across the
     * group's cards, and it is exactly what `quantity_min` /
     * `quantity_max` bound.
     *
     * It is NOT multiplied by `group_quantity` — that value is the
     * product-page qty-stepper default (and order/quote metadata), not a
     * selection bound.
     *
     * @param array $group        Group data (unused; kept for signature
     *                            stability and future per-group rules).
     * @param array $selections   { item_uid => { quantity, ... } }
     * @return int
     */
    protected function selection_unit_count( $group, array $selections ) {
        $sum = 0;
        foreach ( $selections as $sel ) {
            if ( ! is_array( $sel ) ) {
                continue;
            }
            $child_qty = isset( $sel['quantity'] ) ? (int) $sel['quantity'] : 0;
            if ( $child_qty <= 0 ) {
                continue;
            }
            $sum += $child_qty;
        }
        return $sum;
    }

    /**
     * Selections that actually have a positive quantity. Filters out
     * zero/negative submissions, which the renderer might still ship
     * for unselected items.
     *
     * @param array $selections
     * @return array
     */
    protected function effective_selections( array $selections ) {
        $out = array();
        foreach ( $selections as $uid => $sel ) {
            if ( ! is_array( $sel ) ) {
                continue;
            }
            $q = isset( $sel['quantity'] ) ? (int) $sel['quantity'] : 0;
            if ( $q > 0 ) {
                $out[ $uid ] = $sel;
            }
        }
        return $out;
    }
}

endif;
