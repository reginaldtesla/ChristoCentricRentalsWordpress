<?php
/**
 * Rental_Options_Validation_Rule / Rental_Options_Validation_Rule_Base
 *
 * The contract every product-options rule implements, plus a base class with
 * the shared helpers.
 *
 * A rule inspects the context and appends to the result. It returns nothing
 * and decides nothing on its own — the orchestrator owns the verdict.
 *
 * @package RentopianSync\ProductOptions\Validation
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! interface_exists( 'Rental_Options_Validation_Rule', false ) ) :

interface Rental_Options_Validation_Rule {

    /**
     * Stable identifier, used by the rules filter and the logs.
     *
     * @return string
     */
    public function id();

    /**
     * Inspect the context and record any errors.
     *
     * @param Rental_Options_Validator_Context $ctx
     * @param Rental_Options_Validation_Result $result
     * @return void
     */
    public function check( Rental_Options_Validator_Context $ctx, Rental_Options_Validation_Result $result );
}

endif;

if ( ! class_exists( 'Rental_Options_Validation_Rule_Base', false ) ) :

abstract class Rental_Options_Validation_Rule_Base implements Rental_Options_Validation_Rule {

    /**
     * The store's label for the cart, used in customer-facing messages.
     *
     * @return string
     */
    protected function cart_label() {
        $label = get_option( 'rental_cart_button_text' );

        return $label ? lcfirst( $label ) : __( 'cart', 'rentopian-sync' );
    }

    /**
     * Comma-separated option titles.
     *
     * @param array $options
     * @return string
     */
    protected function titles( array $options ) {
        $titles = array();
        foreach ( $options as $option ) {
            if ( ! empty( $option['title'] ) ) {
                $titles[] = $option['title'];
            }
        }

        return implode( ', ', $titles );
    }
}

endif;
