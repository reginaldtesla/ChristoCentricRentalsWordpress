<?php
/**
 * Rental_Options_Defaults
 *
 * The single source of truth for "which value is selected when the customer
 * has not picked one".
 *
 * Before this class the answer was spelled four different ways across the
 * codebase: a strict `is_default === 1` in the browser, a loose `== 1` in the
 * session seeder, `!empty(is_default)` plus a sole-value rule in the
 * add-to-cart gate, and `is_default || default` with no sole-value rule in the
 * AJAX checker. A value the renderer showed as selected could therefore be a
 * value the validator considered unchosen. Every reader now calls this class,
 * so the page and the server cannot disagree.
 *
 * The rule:
 *   1. A value flagged default (`is_default` or `default`, in any scalar
 *      spelling the API may send) wins. The first such value is used.
 *   2. Otherwise, if the option offers exactly one real value, that value is
 *      the implicit default — there is nothing for the customer to decide.
 *   3. Otherwise there is no default and the customer must pick explicitly.
 *
 * @package RentopianSync\ProductOptions
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'Rental_Options_Defaults', false ) ) :

class Rental_Options_Defaults {

    /**
     * Value id of the "Please select an option" placeholder the renderer
     * prepends. It is never a real choice.
     */
    const PLACEHOLDER_VALUE_ID = -1;

    /**
     * Whether a scalar coming from the API means "true".
     *
     * The options payload is stored verbatim as JSON, so a flag can arrive as
     * int 1, string "1", "true", or bool true depending on the core version.
     *
     * @param mixed $flag
     * @return bool
     */
    public static function is_truthy( $flag ) {
        if ( is_bool( $flag ) ) {
            return $flag;
        }
        if ( is_numeric( $flag ) ) {
            return (float) $flag > 0;
        }
        if ( is_string( $flag ) ) {
            $flag = strtolower( trim( $flag ) );
            return in_array( $flag, array( '1', 'true', 'yes', 'on' ), true );
        }
        return false;
    }

    /**
     * Whether a value definition is the placeholder rather than a real choice.
     *
     * @param array $value
     * @return bool
     */
    public static function is_placeholder( $value ) {
        return ! is_array( $value )
            || ! isset( $value['id'] )
            || (int) $value['id'] === self::PLACEHOLDER_VALUE_ID;
    }

    /**
     * Real (non-placeholder) values of an option.
     *
     * @param array $option
     * @return array
     */
    public static function real_values( $option ) {
        if ( empty( $option['option_values'] ) || ! is_array( $option['option_values'] ) ) {
            return array();
        }
        $out = array();
        foreach ( $option['option_values'] as $value ) {
            if ( ! self::is_placeholder( $value ) ) {
                $out[] = $value;
            }
        }
        return $out;
    }

    /**
     * The default value definition for an option, or null when the customer
     * has to choose.
     *
     * @param array $option
     * @return array|null
     */
    public static function resolve( $option ) {
        $values = self::real_values( $option );
        if ( empty( $values ) ) {
            return null;
        }

        foreach ( $values as $value ) {
            $flagged = ( isset( $value['is_default'] ) && self::is_truthy( $value['is_default'] ) )
                || ( isset( $value['default'] ) && self::is_truthy( $value['default'] ) );
            if ( $flagged ) {
                return $value;
            }
        }

        if ( count( $values ) === 1 ) {
            return $values[0];
        }

        return null;
    }

    /**
     * Whether an option can be satisfied without the customer touching it.
     *
     * Once-per-order options are asked at order level, never per product line,
     * so they never require a per-product pick.
     *
     * @param array $option
     * @return bool
     */
    public static function is_satisfiable_without_input( $option ) {
        if ( ! empty( $option['once_per_order'] ) ) {
            return true;
        }
        return self::resolve( $option ) !== null;
    }
}

endif;
