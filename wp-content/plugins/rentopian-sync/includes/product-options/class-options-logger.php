<?php
/**
 * Rental_Options_Logger
 *
 * One line per option store read/write, so the divergence between what the
 * product page shows and what the cart holds can be traced across every
 * handler instead of guessed at.
 *
 * Routed through the shared logger, which prefers the WooCommerce logger and
 * falls back to a file in uploads. Writing the log file directly fails when
 * wc-logs is not writable by the web user, and that warning leaks into AJAX
 * responses.
 *
 * Stages worth knowing:
 *   get_all_options_resolved  what the page was rendered with, and from where
 *   validate_passed/_blocked  what was submitted, resolved, and left unanswered
 *   post_capture_on_add       what was written to the cart line
 *   add_verdict               the final pass/fail plus the notice text shown
 *
 * @package RentopianSync\ProductOptions
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'Rental_Options_Logger', false ) ) :

class Rental_Options_Logger {

    /** Log source; becomes wc-logs/rentopian-options-trace-<date>.log */
    const SOURCE = 'rentopian-options-trace';

    /**
     * Record one stage.
     *
     * @param string $where   Short stage label.
     * @param array  $context key => value pairs (arrays are JSON-encoded).
     * @return void
     */
    public static function trace( $where, array $context = array() ) {
        if ( ! class_exists( 'Project_WP_Logger' ) ) {
            return;
        }

        $parts = array();
        foreach ( $context as $key => $value ) {
            if ( is_array( $value ) ) {
                $value = wp_json_encode( $value );
            } elseif ( is_bool( $value ) ) {
                $value = $value ? '1' : '0';
            }
            $parts[] = $key . '=' . $value;
        }

        Project_WP_Logger::write( $where . ' ' . implode( ' ', $parts ), 'debug', self::SOURCE );
    }
}

endif;
