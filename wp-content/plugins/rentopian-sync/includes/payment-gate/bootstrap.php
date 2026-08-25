<?php
/**
 * Quote mode payment gate loader.
 *
 * Stops any gateway from charging a card while the site collects quotes.
 *
 * Attached on plugins_loaded so WooCommerce and the plugin's own settings are
 * both in place; every hook the gate registers fires later than that.
 *
 * @package RentopianSync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-rental-quote-mode-payment-gate.php';

add_action( 'plugins_loaded', array( 'Rental_Quote_Mode_Payment_Gate', 'register' ), 20 );
