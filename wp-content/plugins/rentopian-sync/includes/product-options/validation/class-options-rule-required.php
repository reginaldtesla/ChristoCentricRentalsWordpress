<?php
/**
 * Rental_Options_Rule_Required
 *
 * Blocks an add only when an option is genuinely unanswered — no submitted
 * value, no cart value, no session value, and no default.
 *
 * Because the context resolves `$_POST` first, an option the customer just
 * picked always counts as answered, whether or not the debounced option AJAX
 * has landed. And because defaults come from Rental_Options_Defaults, an
 * option the page shows pre-selected is never reported as missing.
 *
 * When the request carries no options form at all (an archive or quick-view
 * add), an unanswered option is not the customer ignoring a control — there
 * was no control. That case gets its own code and a message that sends them to
 * the product page.
 *
 * @package RentopianSync\ProductOptions\Validation
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'Rental_Options_Rule_Required', false ) ) :

class Rental_Options_Rule_Required extends Rental_Options_Validation_Rule_Base {

    public function id() {
        return 'options_required';
    }

    /**
     * @param Rental_Options_Validator_Context $ctx
     * @param Rental_Options_Validation_Result $result
     * @return void
     */
    public function check( Rental_Options_Validator_Context $ctx, Rental_Options_Validation_Result $result ) {

        // A cart update re-submits an already validated line.
        if ( $ctx->is_update() ) {
            return;
        }

        if ( ! $ctx->has_options() ) {
            return;
        }

        $missing = $ctx->unanswered();
        if ( empty( $missing ) ) {
            return;
        }

        if ( ! $ctx->has_submission() ) {
            $result->add_error(
                Rental_Options_Validation_Result::ERR_NEEDS_PRODUCT_PAGE,
                sprintf(
                    /* translators: %s = comma-separated option names */
                    __( 'This product needs you to choose: %s. Please open the product page to make your selection.', 'rentopian-sync' ),
                    $this->titles( $missing )
                ),
                array( 'options' => wp_list_pluck( $missing, 'id' ) )
            );

            return;
        }

        $result->add_error(
            Rental_Options_Validation_Result::ERR_OPTION_NOT_CHOSEN,
            sprintf(
                /* translators: 1: comma-separated option names, 2: cart button label */
                __( 'Please select a value for: %1$s before adding to %2$s.', 'rentopian-sync' ),
                $this->titles( $missing ),
                $this->cart_label()
            ),
            array( 'options' => wp_list_pluck( $missing, 'id' ) )
        );
    }
}

endif;
