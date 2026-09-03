<?php
/**
 * Quote mode payment gate.
 *
 * With direct bookings off the site collects quotes, not money. That was
 * expressed by one filter on woocommerce_cart_needs_payment, which removes the
 * payment section from the classic checkout form and nothing else:
 *
 *  - WC_Cart::needs_payment() and the classic WC_Checkout::process_checkout()
 *    branch are the only two places core consults it.
 *  - The Store API, which the cart and checkout blocks and the wallet buttons
 *    built on them post to, asks WC_Order::needs_payment() instead, behind
 *    woocommerce_order_needs_payment.
 *  - Express checkout buttons (Apple Pay, Google Pay, Link, WooPay) are drawn
 *    on the product page, the cart and the mini cart by the gateway itself,
 *    outside the checkout form entirely.
 *
 * A quote only site could therefore still charge a card: the customer taps a
 * wallet button, is charged, and the order reaches Rentopian as a quote with a
 * payment attached that no processor there can refund.
 *
 * Four layers close it, none of which depend on which gateway is installed:
 *
 *  1. Nothing needs payment. Both needs_payment filters return false, so every
 *     flow that asks decides no money is due: classic checkout, the Store API,
 *     the pay for order endpoint, the My Account pay link.
 *  2. No gateway that can charge is available. Every charge resolves its
 *     gateway through get_available_payment_gateways(), so a gateway missing
 *     from that list cannot process_payment() whichever route reached it.
 *     Offline methods stay, as they only record an intention to pay.
 *  3. Wallet buttons are switched off in the gateway's own settings as those
 *     are read on customer facing requests, so they are never drawn. Settings
 *     screens read the stored values, so what the merchant sees is unchanged.
 *  4. The browser removes any wallet button that still appears, whether from a
 *     gateway not named here or injected after the page loaded.
 *
 * Layers 1 and 2 stop the charge. Layers 3 and 4 keep the customer from being
 * offered a payment that would then be refused.
 *
 * @package RentopianSync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Rental_Quote_Mode_Payment_Gate', false ) ) :

class Rental_Quote_Mode_Payment_Gate {

	/** Handle shared by the gate's stylesheet and script. */
	const ASSET_HANDLE = 'rental-quote-mode-payment-gate';

	/**
	 * Gateways that record an intention to pay rather than taking money, so
	 * they stay available. Mirrors the set rental_pay_order() already declines
	 * to report to Rentopian as a payment.
	 *
	 * @var string[]
	 */
	private static $offline_gateways = array( 'cod', 'cheque', 'bacs', 'ccr_pickup_cash' );

	/**
	 * Wallet button toggles, keyed by the option each gateway stores its
	 * settings in. Only keys the stored settings already contain are forced
	 * off, so a gateway is never handed a setting its version does not use.
	 *
	 * @var array
	 */
	private static $wallet_settings = array(
		'woocommerce_stripe_settings'               => array(
			'payment_request',
			'express_checkout_enabled',
		),
		'woocommerce_woocommerce_payments_settings' => array(
			'payment_request_enabled',
			'express_checkout_enabled',
			'platform_checkout_enabled',
		),
	);

	/**
	 * Containers wallet buttons are drawn into. Both generations of each
	 * gateway's markup, plus the express payment area of the cart and checkout
	 * blocks, which any gateway can fill.
	 *
	 * @var string[]
	 */
	private static $wallet_selectors = array(
		'#wc-stripe-payment-request-wrapper',
		'#wc-stripe-payment-request-button',
		'#wc-stripe-payment-request-button-separator',
		'#wc-stripe-express-checkout-element',
		'#wc-stripe-express-checkout-button-separator',
		'#wcpay-payment-request-wrapper',
		'#wcpay-payment-request-button',
		'#wcpay-payment-request-button-separator',
		'#wcpay-express-checkout-element',
		'#wcpay-express-checkout-button-separator',
		'.wc-block-components-express-payment',
		'.wc-block-components-express-payment-continue-rule',
		'.wp-block-woocommerce-cart-express-payment-block',
		'.wp-block-woocommerce-checkout-express-payment-block',
	);

	/**
	 * Attach the gate. Does nothing while direct bookings are on, so a site
	 * taking payments deliberately is untouched.
	 */
	public static function register() {
		if ( ! self::is_quote_mode() ) {
			return;
		}

		add_filter( 'woocommerce_cart_needs_payment', '__return_false', 9999 );
		add_filter( 'woocommerce_order_needs_payment', '__return_false', 9999 );

		add_filter( 'woocommerce_available_payment_gateways', array( __CLASS__, 'drop_charging_gateways' ), 9999 );

		foreach ( array_keys( self::$wallet_settings ) as $option ) {
			add_filter( 'option_' . $option, array( __CLASS__, 'disable_wallet_settings' ), 9999, 2 );
		}

		add_action( 'woocommerce_checkout_process', array( __CLASS__, 'reject_posted_payment_method' ) );

		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ), 20 );
	}

	/**
	 * Fail a checkout submission that carries a payment method.
	 *
	 * The checkout form is rendered without a payment section here, so a posted
	 * payment method means something put one back. Refusing the submission says
	 * so, rather than accepting it and recording a quote as if nothing had been
	 * chosen.
	 */
	public static function reject_posted_payment_method() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! isset( $_POST['payment_method'] ) ) {
			return;
		}

		wc_add_notice( __( 'No need to make payment', 'rentopian-sync' ), 'error' );
	}

	/**
	 * Keep only the gateways that cannot take money.
	 *
	 * An allow list rather than a deny list: recent gateways register one id
	 * per payment method, so naming the ones to remove would miss whatever is
	 * added next.
	 *
	 * @param array $gateways Available gateways, keyed by id.
	 * @return array
	 */
	public static function drop_charging_gateways( $gateways ) {
		if ( ! is_array( $gateways ) || self::is_settings_screen() ) {
			return $gateways;
		}

		foreach ( array_keys( $gateways ) as $id ) {
			if ( ! in_array( $id, self::$offline_gateways, true ) ) {
				unset( $gateways[ $id ] );
			}
		}

		return $gateways;
	}

	/**
	 * Report a gateway's wallet button toggles as off.
	 *
	 * @param mixed  $value  Stored settings.
	 * @param string $option Option name.
	 * @return mixed
	 */
	public static function disable_wallet_settings( $value, $option ) {
		if ( ! is_array( $value ) || self::is_settings_screen() ) {
			return $value;
		}

		if ( empty( self::$wallet_settings[ $option ] ) ) {
			return $value;
		}

		foreach ( self::$wallet_settings[ $option ] as $key ) {
			if ( isset( $value[ $key ] ) ) {
				$value[ $key ] = 'no';
			}
		}

		return $value;
	}

	/**
	 * Queue the browser side site wide: the mini cart carries wallet buttons on
	 * any page, not only the cart and checkout.
	 */
	public static function enqueue_assets() {
		if ( is_admin() ) {
			return;
		}

		$version = defined( 'RENTOPIAN_SYNC_VERSION' ) ? RENTOPIAN_SYNC_VERSION : null;

		// Generated from the same selector list the script works from, so the
		// containers are hidden before the script can run.
		wp_register_style( self::ASSET_HANDLE, false, array(), $version );
		wp_enqueue_style( self::ASSET_HANDLE );
		wp_add_inline_style(
			self::ASSET_HANDLE,
			implode( ',', self::$wallet_selectors ) . '{display:none !important;}'
		);

		wp_enqueue_script(
			self::ASSET_HANDLE,
			plugins_url( 'assets/rental-quote-mode-payment-gate.js', __FILE__ ),
			array(),
			$version,
			true
		);

		wp_localize_script(
			self::ASSET_HANDLE,
			'rentalQuoteModePaymentGate',
			array( 'selectors' => self::$wallet_selectors )
		);
	}

	/**
	 * Whether the site collects quotes rather than payments.
	 *
	 * @return bool
	 */
	public static function is_quote_mode() {
		return ! get_option( 'rental_direct_only_bookings' );
	}

	/**
	 * Whether this request is a WordPress admin screen, where the stored
	 * gateway configuration must be reported as it is.
	 *
	 * Express checkout posts to admin-ajax, where is_admin() is also true, so
	 * an AJAX request is deliberately not treated as a screen.
	 *
	 * @return bool
	 */
	private static function is_settings_screen() {
		return is_admin() && ! wp_doing_ajax();
	}
}

endif;
