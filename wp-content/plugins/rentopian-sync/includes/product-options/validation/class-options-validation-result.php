<?php
/**
 * Rental_Options_Validation_Result
 *
 * Accumulates the errors raised by the rule pipeline so the orchestrator can
 * decide once, and so a caller that only wants an answer (the inline AJAX
 * check) can read the same result the add-to-cart gate acted on.
 *
 * Errors carry a stable code alongside the message. Codes are what the client
 * and the logs match on; the message is only for the customer.
 *
 * @package RentopianSync\ProductOptions\Validation
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'Rental_Options_Validation_Result', false ) ) :

class Rental_Options_Validation_Result {

    /** One or more options have no answer from any source. */
    const ERR_OPTION_NOT_CHOSEN = 'option_not_chosen';

    /** The request cannot carry an options form, and an option needs a choice. */
    const ERR_NEEDS_PRODUCT_PAGE = 'needs_product_page';

    /**
     * @var array<int,array{code:string,message:string,data:array}>
     */
    protected $errors = array();

    /**
     * Record an error.
     *
     * @param string $code
     * @param string $message
     * @param array  $data
     * @return void
     */
    public function add_error( $code, $message, array $data = array() ) {
        $this->errors[] = array(
            'code'    => (string) $code,
            'message' => (string) $message,
            'data'    => $data,
        );
    }

    /**
     * @return bool
     */
    public function failed() {
        return ! empty( $this->errors );
    }

    /**
     * @return bool
     */
    public function passed() {
        return empty( $this->errors );
    }

    /**
     * @return array
     */
    public function errors() {
        return $this->errors;
    }

    /**
     * @return string First message, or an empty string.
     */
    public function first_message() {
        return isset( $this->errors[0]['message'] ) ? $this->errors[0]['message'] : '';
    }

    /**
     * @return array List of error codes.
     */
    public function codes() {
        return wp_list_pluck( $this->errors, 'code' );
    }

    /**
     * Push every message into the WooCommerce notice queue.
     *
     * @return void
     */
    public function notice_all() {
        if ( ! function_exists( 'wc_add_notice' ) ) {
            return;
        }
        foreach ( $this->errors as $error ) {
            wc_add_notice( $error['message'], 'error' );
        }
    }
}

endif;
