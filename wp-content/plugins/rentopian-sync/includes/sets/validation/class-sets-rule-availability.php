<?php
/**
 * Rental_Sets_Rule_Availability
 *
 * Inventory + date-range availability check for the customer's chosen
 * composite-group children. Classic items + addons + selectable items
 * are still covered by the legacy `rental_validate_cart_item` running
 * at priority 10 — this rule only adds the entity-3 piece.
 *
 * The check delegates to the existing `rental_check_availability()`
 * helper. It builds an inventories list from the modern selections using
 * the same quantity math as the add-to-cart / order flow
 * (`set_qty * child_qty`), then asks the helper whether each inv id has
 * enough headroom over the rental date window. `group_quantity` is NOT a
 * factor here — it is the product-page qty-stepper default only; the
 * per-item picked units (child_qty) are the real quantity, mirroring the
 * legacy `rental_validate_cart_item` set-item check (`$quantity *
 * $set_item['quantity']`).
 *
 * Overbooking: this mirrors the legacy gating. `rental_check_availability()`
 * computes real available quantities only when the `rental_allow_overbook`
 * setting is OFF (it passes `calculate_available_quantity => !allow_overbook`
 * to the API); and the headroom comparison below runs only when the
 * response's `allow_overbook` is falsy. When overbooking is allowed the
 * headroom check is skipped and the set is allowed through.
 *
 * Inventories without enough headroom raise an error per affected
 * group. We don't try to short-circuit one-error-per-set-line because
 * the customer needs to know which item specifically was unavailable.
 *
 * @package RentopianSync\Sets\Validation
 * @since   2.14.7
 *
 * PHP requirement: 7.4+
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'Rental_Sets_Rule_Availability', false ) ) :

class Rental_Sets_Rule_Availability extends Rental_Sets_Validation_Rule_Base {

    public function id() { return 'availability'; }

    public function check( Rental_Sets_Validator_Context $ctx, Rental_Sets_Validation_Result $result ) {

        // Modern selections only. Classic flow's availability is
        // covered by the legacy `rental_validate_cart_item`.
        if ( empty( $ctx->modern_selections() ) ) {
            return;
        }

        if ( ! function_exists( 'rental_check_availability' ) ) {
            return;
        }

        // Collect inv_ids for the modern selections, with their submitted
        // quantities multiplied by the parent set qty — the same math the
        // add-to-cart / order flow uses (`set_qty * child_qty`).
        // group_quantity is deliberately NOT a factor here.
        $needed = array(); // inv_id => required quantity
        $labels = array(); // inv_id => human label for the error msg

        $set_qty = $ctx->quantity();

        foreach ( $ctx->grouped_items() as $group ) {
            if ( ! empty( $group['hide_on_website'] ) ) {
                continue;
            }
            $group_uid = isset( $group['uid'] ) ? (string) $group['uid'] : '';

            $selections = $this->effective_selections( $ctx->selections_for_group( $group_uid ) );
            if ( empty( $selections ) ) {
                continue;
            }

            foreach ( $selections as $item_uid => $sel ) {
                $inv_id    = isset( $sel['inv_id'] ) ? (int) $sel['inv_id'] : 0;
                $child_qty = isset( $sel['quantity'] ) ? (int) $sel['quantity'] : 0;
                if ( ! $inv_id || $child_qty <= 0 ) {
                    continue;
                }

                $required = $set_qty * $child_qty;
                if ( ! isset( $needed[ $inv_id ] ) ) {
                    $needed[ $inv_id ] = 0;
                    $labels[ $inv_id ] = $this->resolve_item_label( $sel, $group );
                }
                $needed[ $inv_id ] += $required;
            }
        }

        if ( empty( $needed ) ) {
            return;
        }

        $availability = call_user_func( 'rental_check_availability', array_keys( $needed ) );

        // Helper returns various states:
        //   '' / null → unset → unavailable
        //   'not_set' → dates/zip missing
        //   'not_valid' → invalid date
        //   array { allow_overbook, inventories } → real result.
        if ( 'not_set' === $availability ) {
            $result->add_error(
                Rental_Sets_Validation_Result::ERR_AVAILABILITY,
                __( 'Please select the rental dates so that you can add this set to the cart.', 'rentopian-sync' ),
                array()
            );
            return;
        }

        if ( 'not_valid' === $availability ) {
            $result->add_error(
                Rental_Sets_Validation_Result::ERR_AVAILABILITY,
                __( 'Please select a valid rental start date.', 'rentopian-sync' ),
                array()
            );
            return;
        }

        if ( empty( $availability ) || ! isset( $availability['inventories'] ) ) {
            $result->add_error(
                Rental_Sets_Validation_Result::ERR_AVAILABILITY,
                __( 'Sorry, this set is not available.', 'rentopian-sync' ),
                array()
            );
            return;
        }

        if ( ! empty( $availability['allow_overbook'] ) ) {
            // Overbooking allowed — skip the headroom check.
            return;
        }

        foreach ( $needed as $inv_id => $required_qty ) {
            $available = isset( $availability['inventories'][ $inv_id ]['quantity'] )
                ? (int) $availability['inventories'][ $inv_id ]['quantity']
                : 0;

            if ( $available >= $required_qty ) {
                continue;
            }

            $label = isset( $labels[ $inv_id ] ) ? $labels[ $inv_id ] : __( 'item', 'rentopian-sync' );

            $result->add_error(
                Rental_Sets_Validation_Result::ERR_AVAILABILITY,
                sprintf(
                    /* translators: 1=quantity required, 2=item label, 3=quantity available */
                    __( 'This selection requires %1$d × %2$s, but only %3$d available for the chosen dates.', 'rentopian-sync' ),
                    $required_qty,
                    esc_html( $label ),
                    $available
                ),
                array(
                    'inv_id'    => $inv_id,
                    'required'  => $required_qty,
                    'available' => $available,
                )
            );
        }
    }

    /**
     * Best-effort label for an inventory item — falls back to the
     * underlying WP product name if the selection itself doesn't
     * carry one.
     *
     * @param array $selection
     * @param array $group
     * @return string
     */
    protected function resolve_item_label( $selection, $group ) {
        if ( ! empty( $selection['display_name'] ) ) {
            return (string) $selection['display_name'];
        }
        $vid = isset( $selection['variant_id'] ) ? (int) $selection['variant_id'] : 0;
        $pid = isset( $selection['product_id'] ) ? (int) $selection['product_id'] : 0;
        $wp_id = $vid ?: $pid;
        if ( $wp_id ) {
            $product = wc_get_product( $wp_id );
            if ( $product ) {
                return $product->get_name();
            }
        }
        return ! empty( $group['group_name'] ) ? (string) $group['group_name'] : __( 'item', 'rentopian-sync' );
    }
}

endif;
