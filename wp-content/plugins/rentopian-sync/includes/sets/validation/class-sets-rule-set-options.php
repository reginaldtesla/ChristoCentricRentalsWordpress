<?php
/**
 * Rental_Sets_Rule_Set_Options
 *
 * If the set has set-level options (e.g. "Clean up before event needed?")
 * the customer must have made a selection for each option before
 * submitting. This is what `rental_validate_cart_item` already checks
 * for classic submissions; we mirror it here for completeness so the
 * unified validator can give one consistent answer.
 *
 * The legacy code checks session via `get_rental_session_data()`; we
 * keep that check too because the modern UI also writes to the session
 * via the existing `update_option_data_of_single_product` JS handler.
 *
 * @package RentopianSync\Sets\Validation
 * @since   2.14.7
 *
 * PHP requirement: 7.4+
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'Rental_Sets_Rule_Set_Options', false ) ) :

class Rental_Sets_Rule_Set_Options extends Rental_Sets_Validation_Rule_Base {

    public function id() { return 'set_options'; }

    public function check( Rental_Sets_Validator_Context $ctx, Rental_Sets_Validation_Result $result ) {

        // Cart updates don't need to re-validate options — they were
        // already validated on the original add. Matches legacy behavior.
        if ( $ctx->is_update() ) {
            return;
        }

        if ( ! function_exists( 'get_set_options' ) ) {
            return;
        }

        $options = get_set_options( $ctx->set_id() );
        if ( empty( $options ) ) {
            return;
        }

        // Legacy session key.
        $session_name = $ctx->set_id() . '_selected_options_of_set';
        $picked       = function_exists( 'get_rental_session_data' )
            ? get_rental_session_data( $session_name, array() )
            : array();

        // Legacy code's check is intentionally permissive: it only
        // raises if `$item_selected_option` is unset. We follow suit so
        // a partial selection (some options picked, others defaulting)
        // doesn't get flagged here.
        if ( empty( $picked ) ) {

            $custom_cart_label = get_option( 'rental_cart_button_text' )
                ? lcfirst( get_option( 'rental_cart_button_text' ) )
                : __( 'cart', 'rentopian-sync' );

            $result->add_error(
                Rental_Sets_Validation_Result::ERR_SET_OPTIONS_NOT_PICKED,
                sprintf(
                    /* translators: %s = cart button label */
                    __( 'Please review the options before adding to %s.', 'rentopian-sync' ),
                    esc_html( $custom_cart_label )
                ),
                array()
            );
        }
    }
}

endif;
