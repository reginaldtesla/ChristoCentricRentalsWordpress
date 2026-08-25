<?php
/**
 * Rental_Sets_Validation_Result
 *
 * Immutable result bag returned by the Cart_Validator orchestrator. Holds
 * a list of structured errors (each with a code + message + optional
 * group/item UID) that the caller can either:
 *
 *   - Surface to WooCommerce via `wc_add_notice()` (the typical case
 *     during add-to-cart validation).
 *   - Hand to the prefill class so the renderer can re-display the
 *     customer's selections with the right error highlighted.
 *
 * Errors carry a stable `code` so client-side JS can localise them and
 * decide which form field to flag.
 *
 * @package RentopianSync\Sets\Validation
 * @since   2.14.7
 *
 * PHP requirement: 7.4+
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'Rental_Sets_Validation_Result', false ) ) :

class Rental_Sets_Validation_Result {

    // Error codes — kept stable so client-side JS can localise them.
    const ERR_ARCHIVE_NEEDS_CONFIG       = 'archive_needs_config';
    const ERR_GROUP_REQUIRED             = 'group_required';
    const ERR_GROUP_MIN_QTY              = 'group_min_qty';
    const ERR_GROUP_MAX_QTY              = 'group_max_qty';
    const ERR_GROUP_MULTIPLE_SELECTION   = 'group_multiple_selection';
    const ERR_ITEM_OPTIONAL_NOT_PICKED   = 'item_optional_not_picked';
    const ERR_ADDON_VARIANT_NOT_PICKED   = 'addon_variant_not_picked';
    const ERR_SET_OPTIONS_NOT_PICKED     = 'set_options_not_picked';
    const ERR_AVAILABILITY               = 'availability';
    const ERR_MAX_QUANTITY               = 'max_quantity';

    /**
     * @var array<int,array> { code, message, group_uid?, item_uid?, addon_id?, option_id? }
     */
    protected $errors = array();

    /**
     * Add an error.
     *
     * @param string $code     One of the ERR_* constants.
     * @param string $message  Human-readable message.
     * @param array  $context  Optional context: group_uid, item_uid, etc.
     * @return self  For fluent chaining.
     */
    public function add_error( $code, $message, array $context = array() ) {
        $this->errors[] = array_merge(
            array(
                'code'    => (string) $code,
                'message' => (string) $message,
            ),
            $context
        );
        return $this;
    }

    /**
     * @return bool  True when no errors have been added.
     */
    public function passed() {
        return empty( $this->errors );
    }

    /**
     * @return bool  Inverse of passed().
     */
    public function failed() {
        return ! $this->passed();
    }

    /**
     * @return array<int,array>
     */
    public function errors() {
        return $this->errors;
    }

    /**
     * @return string[]  Just the messages.
     */
    public function messages() {
        $out = array();
        foreach ( $this->errors as $e ) {
            $out[] = $e['message'];
        }
        return $out;
    }

    /**
     * Surface every error as a WC notice. Idempotent if called twice
     * in the same request — WC dedupes notices with the same message.
     *
     * @return void
     */
    public function notice_all() {
        if ( ! function_exists( 'wc_add_notice' ) ) {
            return;
        }
        foreach ( $this->errors as $e ) {
            wc_add_notice( $e['message'], 'error' );
        }
    }

    /**
     * Merge another result's errors into this one.
     *
     * @param self $other
     * @return self
     */
    public function merge( Rental_Sets_Validation_Result $other ) {
        foreach ( $other->errors() as $err ) {
            $this->errors[] = $err;
        }
        return $this;
    }
}

endif;
