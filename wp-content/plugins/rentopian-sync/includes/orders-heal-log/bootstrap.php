<?php
/**
 * Rentopian Sync — Heal & Log pipeline bootstrap.
 *
 * Single-line include for rentopian-sync.php:
 *
 *     require_once plugin_dir_path( __FILE__ ) . 'includes/orders-heal-log/bootstrap.php';
 *
 * This file is the only entry point. It:
 *   1. Guards against PHP < 7.4 (refuses to load; legacy behavior remains).
 *   2. Auto-discovers and loads Project_WP_Logger from the parent
 *      includes/ directory if not already loaded.
 *   3. Loads the helper classes in the correct order.
 *   4. Verifies all classes loaded successfully and logs any failure.
 *
 * Expected directory layout:
 *
 *   plugins/rentopian-sync/
 *   ├── rentopian-sync.php
 *   └── includes/
 *       ├── class-logger-with-timer.php         (existing — contains Project_WP_Logger)
 *       └── orders-heal-log/                    (this folder)
 *           ├── bootstrap.php                   (this file)
 *           ├── class-rental-date-validator.php
 *           ├── class-rental-data-healer.php
 *           ├── class-rentopian-incident-reporter.php
 *           ├── class-rentopian-order-logger.php
 *           ├── class-rentopian-set-cart-tracer.php
 *           ├── class-rentopian-set-integrity-audit.php
 *           ├── class-rt-delivery-patched.php
 *           ├── rental_create_order-patched.php (reference patch)
 *           ├── test-suite.php
 *           └── README.md
 *
 * Minimum PHP: 7.4. Compatible up to PHP 8.3.
 *
 * @package rentopian-sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'RENTOPIAN_HEAL_LOG_MIN_PHP' ) ) {
	define( 'RENTOPIAN_HEAL_LOG_MIN_PHP', '7.4.0' );
}
if ( ! defined( 'RENTOPIAN_HEAL_LOG_DIR' ) ) {
	define( 'RENTOPIAN_HEAL_LOG_DIR', __DIR__ );
}

// ---------------------------------------------------------------------------
// 1. PHP version guard. Do NOT kill the site; just refuse to load the new
//    code. The legacy rental_create_order() path will keep running.
// ---------------------------------------------------------------------------
if ( version_compare( PHP_VERSION, RENTOPIAN_HEAL_LOG_MIN_PHP, '<' ) ) {
	if ( class_exists( 'Project_WP_Logger', false ) ) {
		Project_WP_Logger::write(
			sprintf(
				'Heal & Log pipeline requires PHP %s; running on %s. New classes NOT loaded; legacy behavior remains.',
				RENTOPIAN_HEAL_LOG_MIN_PHP,
				PHP_VERSION
			),
			'warning',
			'rentopian-sync'
		);
	} elseif ( function_exists( 'error_log' ) ) {
		error_log( '[rentopian-sync] Heal & Log pipeline requires PHP ' . RENTOPIAN_HEAL_LOG_MIN_PHP . '; running on ' . PHP_VERSION . '.' );
	}
	return;
}

// ---------------------------------------------------------------------------
// 2. Auto-discover Project_WP_Logger. It lives one directory up:
//    plugins/rentopian-sync/includes/class-logger-with-timer.php
// ---------------------------------------------------------------------------
if ( ! class_exists( 'Project_WP_Logger', false ) ) {
	$rentopian_heal_log_parent = dirname( RENTOPIAN_HEAL_LOG_DIR ) . '/class-logger-with-timer.php';
	if ( file_exists( $rentopian_heal_log_parent ) ) {
		require_once $rentopian_heal_log_parent;
	}
	unset( $rentopian_heal_log_parent );
}

// If Project_WP_Logger STILL isn't available after auto-discovery, the new
// pipeline cannot function. Report the misconfiguration once and bail.
if ( ! class_exists( 'Project_WP_Logger', false ) ) {
	if ( function_exists( 'error_log' ) ) {
		error_log( '[rentopian-sync] Heal & Log bootstrap aborted: Project_WP_Logger not found. Expected in: ' . dirname( RENTOPIAN_HEAL_LOG_DIR ) . '/class-logger-with-timer.php' );
	}
	return;
}

// ---------------------------------------------------------------------------
// 3. Load the four helper classes. Order: validator → healer →
//    incident reporter → order logger. Each is idempotent (uses
//    class_exists guards), so reloads are safe.
// ---------------------------------------------------------------------------
require_once RENTOPIAN_HEAL_LOG_DIR . '/class-rental-date-validator.php';
require_once RENTOPIAN_HEAL_LOG_DIR . '/class-rental-data-healer.php';
require_once RENTOPIAN_HEAL_LOG_DIR . '/class-rentopian-incident-reporter.php';
require_once RENTOPIAN_HEAL_LOG_DIR . '/class-rentopian-order-logger.php';
require_once RENTOPIAN_HEAL_LOG_DIR . '/class-rentopian-set-cart-tracer.php';
require_once RENTOPIAN_HEAL_LOG_DIR . '/class-rentopian-set-integrity-audit.php';

// The tracer registers WooCommerce cart hooks, so it waits for `init` like the
// rest of the cart pipeline. Its own kill switch is the `rental_set_cart_trace`
// option. The audit is read-only and writes to the tracer's file.
add_action( 'init', array( 'Rentopian_Set_Cart_Tracer', 'register' ), 20 );
add_action( 'init', array( 'Rentopian_Set_Integrity_Audit', 'register' ), 21 );

// ---------------------------------------------------------------------------
// 4. Sanity check — every class must be available after the includes above.
//    If anything's missing, log it via Project_WP_Logger (now guaranteed
//    to exist).
// ---------------------------------------------------------------------------
$rentopian_heal_log_required = array(
	'Rental_Date_Validator',
	'Rental_Data_Healer',
	'Rentopian_Incident_Reporter',
	'Rentopian_Order_Logger',
	'Rentopian_Set_Cart_Tracer',
	'Rentopian_Set_Integrity_Audit',
);
$rentopian_heal_log_missing = array();
foreach ( $rentopian_heal_log_required as $cls ) {
	if ( ! class_exists( $cls, false ) ) {
		$rentopian_heal_log_missing[] = $cls;
	}
}
if ( ! empty( $rentopian_heal_log_missing ) ) {
	Project_WP_Logger::write(
		'Heal & Log bootstrap: class(es) failed to load: ' . implode( ', ', $rentopian_heal_log_missing ),
		'error',
		'rentopian-sync'
	);
}
unset( $rentopian_heal_log_required, $rentopian_heal_log_missing, $cls );
