<?php
/**
 * Page cache compatibility for the rental date gate.
 *
 * The add to cart gate is decided in PHP from the customer's date cookies, and
 * the resulting page carries no cache headers of its own. Nothing states how
 * long that HTML stays valid, so any layer holding a copy may reuse it — the
 * visitor's own browser first of all, then any proxy or caching plugin. A reused
 * copy never runs PHP, so it keeps showing the decision made for whoever fetched
 * it first. Which way it looks broken depends on which state was stored first.
 *
 * Three measures, none of which depend on a particular host:
 *
 *  1. A response rendered for a customer who has dates is marked uncacheable,
 *     so a personal copy never becomes the copy everyone else is served. This is
 *     the one that does the work; the other two cover layers it cannot reach.
 *  2. That customer is given a WooCommerce session cookie. Page caches already
 *     treat it as a reason to skip the cache, so their later requests reach PHP.
 *  3. The browser compares the decision baked into the HTML against the cookie
 *     it can see, and reloads once past the cache when the two disagree. This
 *     is what recovers copies cached before any of the above existed.
 *
 * Theme independent: it hooks WordPress and WooCommerce only, and the browser
 * side reads the plugin's own form holder and WooCommerce's standard cart
 * markup, so it behaves the same whichever theme renders the product page.
 *
 * Nothing here applies while dates are collected on the checkout page: that
 * mode seeds date cookies for every visitor and does not gate add to cart on
 * them, so treating those visitors as personalized would only cost the site its
 * cache.
 *
 * @package RentopianSync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Rental_Page_Cache_Guard', false ) ) :

class Rental_Page_Cache_Guard {

	/** Handle of the browser reconciliation script. */
	const SCRIPT_HANDLE = 'rental-page-cache-sync';

	/** Query argument that takes a reload past the cache. */
	const BUSTER_PARAM = 'rntp_nc';

	/**
	 * The cookie the browser is allowed to read, and the one the gate reads.
	 * They are written and cleared together by the date form, so the first can
	 * stand in for the second in JavaScript.
	 */
	const MARKER_COOKIE = 'rental_form_filled';

	/** Cookies that make a rendered page specific to one customer. */
	private static $personal_cookies = array(
		'rental_start_date',
		'rental_end_date',
		'rental_zip',
		'rental_form_filled',
	);

	/**
	 * Attach the guard. Every handler returns early when it is not needed, so
	 * this is safe on any request.
	 */
	public static function register() {
		add_action( 'template_redirect', array( __CLASS__, 'protect_response' ), 0 );
		add_action( 'template_redirect', array( __CLASS__, 'ensure_bypass_cookie' ), 0 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_sync' ), 20 );
	}

	/* ------------------------------------------------------------------ *
	 * 1. Keep a personalized render out of the cache
	 * ------------------------------------------------------------------ */

	/**
	 * Tell every layer that might store this response not to.
	 *
	 * This stops one customer's copy from being served to the next visitor. It
	 * cannot help a request that is already being answered from a stored copy —
	 * that is what the session cookie and the browser check are for.
	 */
	public static function protect_response() {
		if ( ! self::is_personalized() && ! self::has_buster() ) {
			return;
		}

		// Read by WP Rocket, WP Super Cache, W3 Total Cache, SG Optimizer,
		// LiteSpeed Cache and others when they decide whether to store a page.
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}

		if ( headers_sent() ) {
			return;
		}

		nocache_headers();

		// nocache_headers() allows a revalidated copy; a gate decision must not
		// be stored at all. no-store also keeps the page out of the browser's
		// back/forward cache, which is intended: a restored page shows the gate
		// decision it was rendered with, and that is the fault being fixed.
		header( 'Cache-Control: no-cache, no-store, must-revalidate, private, max-age=0' );

		// Reverse proxy directives, for the layers that never see the constant.
		header( 'X-Accel-Expires: 0' );              // nginx proxy / fastcgi cache
		header( 'X-Cache-Enabled: False' );          // SiteGround
		header( 'X-LiteSpeed-Cache-Control: no-cache' );
	}

	/* ------------------------------------------------------------------ *
	 * 2. Get the customer past the cache on later requests
	 * ------------------------------------------------------------------ */

	/**
	 * Give a customer who has chosen dates a WooCommerce session cookie.
	 *
	 * Page caches bypass a request carrying one, so this is what makes the
	 * customer's next product page reach PHP and render the real gate decision.
	 * Visitors who have not chosen dates keep getting cached pages, which is
	 * both correct for them and what keeps the cache worth having.
	 */
	public static function ensure_bypass_cookie() {
		if ( is_user_logged_in() || ! self::has_personal_cookies() ) {
			return;
		}

		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return;
		}

		if ( WC()->session->has_session() ) {
			return;
		}

		if ( function_exists( 'ensure_wc_session' ) ) {
			ensure_wc_session();
			return;
		}

		WC()->session->set_customer_session_cookie( true );
	}

	/* ------------------------------------------------------------------ *
	 * 3. Let the browser correct a stale copy
	 * ------------------------------------------------------------------ */

	/**
	 * Queue the reconciliation script on single product pages, where the gate
	 * decides which button the customer gets.
	 */
	public static function enqueue_sync() {
		if ( ! function_exists( 'is_product' ) || ! is_product() ) {
			return;
		}

		if ( self::dates_on_checkout() ) {
			return;
		}

		wp_enqueue_script(
			self::SCRIPT_HANDLE,
			plugins_url( 'assets/rental-page-cache-sync.js', __FILE__ ),
			array(),
			defined( 'RENTOPIAN_SYNC_VERSION' ) ? RENTOPIAN_SYNC_VERSION : null,
			true
		);

		wp_localize_script(
			self::SCRIPT_HANDLE,
			'rentalPageCacheSync',
			array(
				'marker' => self::MARKER_COOKIE,
				'param'  => self::BUSTER_PARAM,
			)
		);
	}

	/* ------------------------------------------------------------------ *
	 * Shared conditions
	 * ------------------------------------------------------------------ */

	/**
	 * Whether this request is a page render whose HTML is specific to one
	 * customer.
	 *
	 * @return bool
	 */
	public static function is_personalized() {
		if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return false;
		}

		return self::has_personal_cookies();
	}

	/**
	 * Whether the customer has chosen dates, regardless of what kind of request
	 * this is. The date form endpoints ask during AJAX, where there is no page
	 * to protect but a session cookie still has to be handed out.
	 *
	 * @return bool
	 */
	public static function has_personal_cookies() {
		if ( self::dates_on_checkout() ) {
			return false;
		}

		foreach ( self::$personal_cookies as $cookie ) {
			if ( ! empty( $_COOKIE[ $cookie ] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether the customer collects dates on the checkout page, where date
	 * cookies are seeded for everyone and add to cart is not gated on them.
	 *
	 * @return bool
	 */
	private static function dates_on_checkout() {
		return 1 === (int) get_option( 'rental_dates_on_checkout', 0 )
			&& 1 === (int) get_option( 'rental_allow_overbook', 1 );
	}

	/**
	 * Whether this request is a reload aimed past the cache. Those must not be
	 * stored either, or the cache fills with one entry per visitor.
	 *
	 * @return bool
	 */
	private static function has_buster() {
		return isset( $_GET[ self::BUSTER_PARAM ] );
	}
}

endif;
