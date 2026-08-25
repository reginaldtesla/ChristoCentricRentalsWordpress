<?php
/**
 * Rental_Sets_Archive_Guard — DEPRECATED in 2.14.7
 *
 * The archive-page configuration check has moved into
 * Rental_Sets_Rule_Archive_Context, executed by Rental_Sets_Cart_Validator.
 *
 * This class is now a no-op shim that registers no hooks. Kept so any
 * downstream code that calls `Rental_Sets_Archive_Guard::register()`
 * or holds a reference to the class name doesn't error. Will be
 * removed in a future version.
 *
 * @package RentopianSync\Sets
 * @deprecated 2.14.7 Use Rental_Sets_Cart_Validator instead.
 *
 * PHP requirement: 7.4+
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'Rental_Sets_Archive_Guard', false ) ) :

class Rental_Sets_Archive_Guard {

    /**
     * @var self|null
     */
    protected static $instance = null;

    /**
     * Idempotent no-op. Logs a one-time deprecation notice.
     *
     * @return void
     */
    public static function register() {
        if ( null !== self::$instance ) {
            return;
        }
        self::$instance = new self();

        if ( class_exists( 'Project_WP_Logger', false ) ) {
            Project_WP_Logger::write(
                'Archive_Guard: deprecated — archive-context validation now lives in Rental_Sets_Cart_Validator.',
                'info',
                'rentopian-sets-sync'
            );
        }
    }
}

endif;
