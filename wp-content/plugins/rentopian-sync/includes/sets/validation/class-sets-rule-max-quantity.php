<?php
/**
 * Rental_Sets_Rule_Max_Quantity
 *
 * Matches the existing `_rental_max_quantity` postmeta check. Same
 * postmeta covers both classic and modern flows. The check is
 * straight-forward: if max_quantity > 0 and submitted qty > max,
 * reject with a clear message.
 *
 * Note: when the cart already contains some of this set and we're
 * adding more, we only validate the *submitted* quantity here — not
 * the projected post-add total. This matches the legacy behavior;
 * over-cart-quantity is then caught by Rule_Availability.
 *
 * @package RentopianSync\Sets\Validation
 * @since   2.14.7
 *
 * PHP requirement: 7.4+
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'Rental_Sets_Rule_Max_Quantity', false ) ) :

class Rental_Sets_Rule_Max_Quantity extends Rental_Sets_Validation_Rule_Base {

    public function id() { return 'max_quantity'; }

    public function check( Rental_Sets_Validator_Context $ctx, Rental_Sets_Validation_Result $result ) {
        $max = $ctx->max_quantity();
        if ( $max <= 0 ) {
            return;
        }

        if ( $ctx->quantity() <= $max ) {
            return;
        }

        $result->add_error(
            Rental_Sets_Validation_Result::ERR_MAX_QUANTITY,
            sprintf(
                /* translators: %d = max quantity */
                __( 'Maximum available quantity for this item is: %d', 'rentopian-sync' ),
                $max
            ),
            array(
                'max'      => $max,
                'attempted'=> $ctx->quantity(),
            )
        );
    }
}

endif;
