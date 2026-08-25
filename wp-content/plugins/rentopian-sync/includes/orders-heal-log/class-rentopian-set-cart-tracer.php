<?php
/**
 * Rentopian Set Cart Tracer
 *
 * Follows a SET (package) from add-to-cart to order creation and records
 * every point where its child lines can appear or disappear. Built for the
 * failure mode where a set parent reaches checkout ALONE: the order payload
 * skips the parent (`ITEM_SKIP reason=is_set`) because children carry the
 * inventories, so an order with no children produces zero inventories and is
 * never sent to Rentopian.
 *
 * The existing order log records the outcome; this records the cause. Each
 * stage answers one question:
 *
 *   SET_VALIDATE_IN   — what did the browser submit, and what does the set
 *                       definition say it contains?
 *   SET_VALIDATE_OUT  — did validation pass, and how many children did it
 *                       hand to the cart adder?
 *   SET_ADD_PLAN      — how many children were planned, and are any of them
 *                       planned at quantity 0 (they never materialise)?
 *   SET_ADD_RESULT    — how many children actually landed under the parent?
 *   CART_LOAD         — after session hydration, does each set parent still
 *                       have its children?
 *   SESSION_DROP      — WooCommerce dropped a stored line while rebuilding
 *                       the cart (missing product, not purchasable, qty 0).
 *   CART_ITEM_REMOVED — a line was removed at runtime, with the calling code.
 *   CHECKOUT_CART     — full cart composition when checkout starts.
 *   ORDER_CART        — full cart composition at order creation, bound to the
 *                       WP order id so it lines up with the order log.
 *
 * Any stage that finds a set parent with zero children writes a `critical`
 * line, so one grep for SET_WITHOUT_CHILDREN finds every affected request.
 *
 * Output: wp-content/uploads/wc-logs/rentopian-set-cart-trace-YYYY-MM-DD.log
 * Kill switch: option `rental_set_cart_trace` = 0.
 *
 * @package rentopian-sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Rentopian_Set_Cart_Tracer', false ) ) :

class Rentopian_Set_Cart_Tracer {

	const SOURCE      = 'rentopian-set-cart-trace';
	const FILE_PREFIX = 'rentopian-set-cart-trace-';
	const OPTION      = 'rental_set_cart_trace';

	/**
	 * Per-request memo of the enable check.
	 *
	 * @var bool|null
	 */
	private static $enabled = null;

	/**
	 * Child plan captured from the submission before the cart adder consumes
	 * (and unsets) `$_POST['rental_add_ons']`. Keyed by set product id.
	 *
	 * @var array<int,array>
	 */
	private static $plan = array();

	/**
	 * Set product ids whose add-to-cart already produced a result line, so a
	 * second parent event in the same request doesn't duplicate it.
	 *
	 * @var array<int,bool>
	 */
	private static $reported = array();

	// ---------------------------------------------------------------------
	// Registration
	// ---------------------------------------------------------------------

	public static function register() {
		if ( ! self::enabled() ) {
			return;
		}

		// Add-to-cart: before the legacy validator, and after every validator.
		add_filter( 'woocommerce_add_to_cart_validation', array( __CLASS__, 'on_validate_start' ), 1, 3 );
		add_filter( 'woocommerce_add_to_cart_validation', array( __CLASS__, 'on_validate_end' ), 998, 3 );

		// Add-to-cart commit: priority 1 runs before rental_add_product_to_cart
		// (10) which consumes and unsets the submitted children; priority 106
		// runs after the merger (99), edit-mode (100) and reconciler (105).
		add_action( 'woocommerce_add_to_cart', array( __CLASS__, 'on_add_begin' ), 1, 6 );
		add_action( 'woocommerce_add_to_cart', array( __CLASS__, 'on_add_end' ), 106, 6 );

		// Session hydration — the only place WooCommerce silently discards a
		// stored cart line.
		add_action( 'woocommerce_cart_loaded_from_session', array( __CLASS__, 'on_cart_loaded' ), 99 );
		add_action( 'woocommerce_remove_cart_item_from_session', array( __CLASS__, 'on_session_drop' ), 10, 2 );

		// Runtime removals and quantity changes on set lines.
		add_action( 'woocommerce_cart_item_removed', array( __CLASS__, 'on_cart_item_removed' ), 1, 2 );
		add_action( 'woocommerce_after_cart_item_quantity_update', array( __CLASS__, 'on_qty_update' ), 999, 3 );

		// Checkout and order creation snapshots.
		add_action( 'woocommerce_checkout_process', array( __CLASS__, 'on_checkout_process' ), 1 );
		add_action( 'woocommerce_new_order', array( __CLASS__, 'on_new_order' ), 1, 1 );
	}

	/**
	 * Tracing is on unless explicitly disabled, so a production incident is
	 * captured the first time it happens rather than on the next reproduction.
	 *
	 * @return bool
	 */
	public static function enabled() {
		if ( null !== self::$enabled ) {
			return self::$enabled;
		}
		$on = (bool) get_option( self::OPTION, 1 );
		self::$enabled = (bool) apply_filters( 'rental_set_cart_trace_enabled', $on );
		return self::$enabled;
	}

	// ---------------------------------------------------------------------
	// Add-to-cart
	// ---------------------------------------------------------------------

	/**
	 * Records the submission shape and the set definition before any
	 * validator has run.
	 *
	 * @param bool $passed
	 * @param int  $product_id
	 * @param int  $quantity
	 * @return bool Unmodified.
	 */
	public static function on_validate_start( $passed, $product_id, $quantity ) {
		if ( ! self::is_set( $product_id ) ) {
			return $passed;
		}

		$submitted = self::read_submitted_children();
		$def       = self::read_definition( $product_id );

		self::log( 'SET_VALIDATE_IN', array_merge(
			array(
				'set'             => (int) $product_id,
				'qty'             => (int) $quantity,
				'post_children'   => $submitted['count'],
				'post_groups'     => $submitted['groups'],
				'post_qty0'       => $submitted['qty0'],
				'from_page'       => empty( $_POST['_rental_set_from_product_page'] ) ? 0 : 1,
				// Selection payload. The marker travels separately from
				// these, so a submission with the marker but no selections
				// means the configurator wrote its fields into a form that
				// was never submitted.
				'post_sels'       => isset( $_POST['rental_set_selections'] ) ? count( (array) $_POST['rental_set_selections'] ) : 0,
				'post_payload'    => empty( $_POST['rental_set_payload'] ) ? 0 : 1,
				'opt_outs'        => isset( $_POST['rental_set_opt_outs'] ) ? count( (array) $_POST['rental_set_opt_outs'] ) : 0,
				'addon_opt_outs'  => isset( $_POST['rental_set_addon_opt_outs'] ) ? count( (array) $_POST['rental_set_addon_opt_outs'] ) : 0,
				'edit_key'        => isset( $_POST['_rental_edit_cart_key'] ) ? self::short( $_POST['_rental_edit_cart_key'] ) : '-',
			),
			$def
		) );

		// A member with no inventory id can never be priced or booked; it is
		// the difference between "the set adds" and "the set adds empty".
		if ( ! empty( $def['def_no_inv_ids'] ) ) {
			self::log( 'SET_DEF_MISSING_INV', array(
				'set'      => (int) $product_id,
				'products' => $def['def_no_inv_ids'],
				'note'     => 'Set members without _rental_inventory_id. These are invisible to availability and to the order payload.',
			), 'warning' );
		}

		return $passed;
	}

	/**
	 * Records the validation verdict plus the child list the validator handed
	 * to the cart adder. A pass with zero children is the exact condition
	 * that produces a childless set line.
	 *
	 * @param bool $passed
	 * @param int  $product_id
	 * @param int  $quantity
	 * @return bool Unmodified.
	 */
	public static function on_validate_end( $passed, $product_id, $quantity ) {
		if ( ! self::is_set( $product_id ) ) {
			return $passed;
		}

		$handed = self::read_submitted_children();

		self::log( 'SET_VALIDATE_OUT', array(
			'set'      => (int) $product_id,
			'qty'      => (int) $quantity,
			'passed'   => $passed ? 1 : 0,
			'children' => $handed['count'],
			'qty0'     => $handed['qty0'],
			'errors'   => self::error_notices(),
		), $passed ? 'info' : 'warning' );

		if ( $passed && 0 === $handed['count'] ) {
			self::log( 'SET_ADD_NO_CHILDREN_PLANNED', array(
				'set'  => (int) $product_id,
				'qty'  => (int) $quantity,
				'note' => 'Validation passed but no child items were produced. The parent will enter the cart alone and the order payload will hold zero inventories.',
			), 'critical' );
		}

		return $passed;
	}

	/**
	 * Snapshots the planned children before `rental_add_product_to_cart`
	 * consumes and unsets the submission.
	 *
	 * @param string $cart_item_key
	 * @param int    $product_id
	 * @param int    $quantity
	 * @param int    $variation_id
	 * @param array  $variation
	 * @param array  $cart_item_data
	 * @return void
	 */
	public static function on_add_begin( $cart_item_key, $product_id, $quantity, $variation_id, $variation, $cart_item_data ) {
		if ( ! empty( $cart_item_data['rental_add_on_of'] ) || ! self::is_set( $product_id ) ) {
			return;
		}

		$plan = self::read_submitted_children();
		self::$plan[ (int) $product_id ] = $plan;

		self::log( 'SET_ADD_PLAN', array(
			'set'      => (int) $product_id,
			'key'      => self::short( $cart_item_key ),
			'qty'      => (int) $quantity,
			'children' => $plan['count'],
			'qty0'     => $plan['qty0'],
			'items'    => $plan['items'],
		) );

		if ( $plan['qty0'] > 0 ) {
			self::log( 'SET_CHILD_PLANNED_QTY0', array(
				'set'   => (int) $product_id,
				'count' => $plan['qty0'],
				'note'  => 'Child planned at quantity 0. It is written into the cart but WooCommerce discards a zero-quantity line on the next session read.',
			), 'critical' );
		}
	}

	/**
	 * Compares planned children against the lines that actually landed under
	 * the surviving parent, after merge / edit-replace / reconcile.
	 *
	 * @param string $cart_item_key
	 * @param int    $product_id
	 * @param int    $quantity
	 * @param int    $variation_id
	 * @param array  $variation
	 * @param array  $cart_item_data
	 * @return void
	 */
	public static function on_add_end( $cart_item_key, $product_id, $quantity, $variation_id, $variation, $cart_item_data ) {
		if ( ! empty( $cart_item_data['rental_add_on_of'] ) || ! self::is_set( $product_id ) ) {
			return;
		}
		if ( isset( self::$reported[ (int) $product_id ] ) ) {
			return;
		}
		self::$reported[ (int) $product_id ] = true;

		$parent_key = self::find_parent_key( $cart_item_key, $product_id, $variation_id );
		if ( null === $parent_key ) {
			self::log( 'SET_ADD_RESULT', array(
				'set'     => (int) $product_id,
				'key'     => self::short( $cart_item_key ),
				'outcome' => 'parent_line_gone',
			), 'critical' );
			return;
		}

		$children = self::children_of( $parent_key );
		$planned  = isset( self::$plan[ (int) $product_id ] ) ? self::$plan[ (int) $product_id ] : array( 'count' => 0 );
		$contents = self::cart_contents();

		self::log( 'SET_ADD_RESULT', array(
			'set'       => (int) $product_id,
			'key'       => self::short( $parent_key ),
			'reparented'=> ( (string) $parent_key !== (string) $cart_item_key ) ? 1 : 0,
			'parent_qty'=> isset( $contents[ $parent_key ]['quantity'] ) ? (int) $contents[ $parent_key ]['quantity'] : 0,
			'planned'   => (int) $planned['count'],
			'landed'    => count( $children ),
			'lines'     => self::describe_children( $children ),
		) );

		if ( empty( $children ) ) {
			self::log( 'SET_WITHOUT_CHILDREN', array(
				'stage' => 'add_to_cart',
				'set'   => (int) $product_id,
				'key'   => self::short( $parent_key ),
				'note'  => 'Set parent is in the cart with no child lines.',
			), 'critical' );
		}
	}

	// ---------------------------------------------------------------------
	// Session hydration
	// ---------------------------------------------------------------------

	/**
	 * Reports each set parent's child count once the cart has been rebuilt
	 * from the session — the first place a drop that happened between two
	 * page loads becomes visible.
	 *
	 * @return void
	 */
	public static function on_cart_loaded() {
		self::report_silent_session_drops();

		$contents = self::cart_contents();
		if ( empty( $contents ) ) {
			return;
		}

		foreach ( $contents as $key => $item ) {
			if ( ! empty( $item['rental_add_on_of'] ) ) {
				continue;
			}
			if ( ! self::is_set( isset( $item['product_id'] ) ? $item['product_id'] : 0 ) ) {
				continue;
			}

			$children = self::children_of( $key );

			self::log( 'CART_LOAD', array(
				'set'      => (int) $item['product_id'],
				'key'      => self::short( $key ),
				'qty'      => isset( $item['quantity'] ) ? (int) $item['quantity'] : 0,
				'children' => count( $children ),
			) );

			if ( empty( $children ) ) {
				self::log( 'SET_WITHOUT_CHILDREN', array(
					'stage' => 'cart_load',
					'set'   => (int) $item['product_id'],
					'key'   => self::short( $key ),
					'note'  => 'Set parent restored from session with no child lines.',
				), 'critical' );
			}
		}
	}

	/**
	 * Diffs the stored session cart against the cart WooCommerce just built
	 * from it. `WC_Cart_Session::get_cart_from_session()` discards a stored
	 * line whose product is missing or whose quantity is 0 with a bare
	 * `continue` — no action, no notice — so a set child lost this way is
	 * otherwise untraceable. The stored cart is still the pre-hydration copy
	 * at this point; WooCommerce rewrites it after this hook.
	 *
	 * @return void
	 */
	private static function report_silent_session_drops() {
		if ( ! function_exists( 'WC' ) || ! WC() || ! WC()->session ) {
			return;
		}

		$stored = WC()->session->get( 'cart', null );
		if ( ! is_array( $stored ) || empty( $stored ) ) {
			return;
		}

		$contents = self::cart_contents();
		foreach ( $stored as $key => $values ) {
			if ( isset( $contents[ $key ] ) || ! is_array( $values ) ) {
				continue;
			}

			$product_id   = isset( $values['product_id'] ) ? (int) $values['product_id'] : 0;
			$variation_id = isset( $values['variation_id'] ) ? (int) $values['variation_id'] : 0;
			$is_child     = ! empty( $values['rental_add_on_of'] );
			$quantity     = isset( $values['quantity'] ) ? (int) $values['quantity'] : 0;

			if ( ! $is_child && empty( $values['rental_set_id'] ) && ! self::is_set( $product_id ) ) {
				continue;
			}

			$product = wc_get_product( $variation_id ? $variation_id : $product_id );
			if ( $quantity < 1 ) {
				$reason = 'quantity_zero';
			} elseif ( ! $product || ! $product->exists() ) {
				$reason = 'product_missing';
			} elseif ( ! $product->is_purchasable() ) {
				$reason = 'not_purchasable';
			} else {
				$reason = 'removed_during_hydration';
			}

			self::log( 'SESSION_DROP_SILENT', array(
				'key'       => self::short( $key ),
				'kind'      => $is_child ? 'set_child' : 'set_parent',
				'product'   => $product_id,
				'variation' => $variation_id,
				'qty'       => $quantity,
				'parent'    => $is_child ? self::short( $values['rental_add_on_of'] ) : '-',
				'status'    => get_post_status( $variation_id ? $variation_id : $product_id ) ?: 'MISSING',
				'price'     => $product ? (string) $product->get_price() : '-',
				'reason'    => $reason,
				'note'      => 'Stored cart line was discarded while rebuilding the cart from the session. WooCommerce reports nothing to the customer for this path.',
			), 'critical' );
		}
	}

	/**
	 * A stored line WooCommerce refused to restore: the product is gone, is
	 * no longer purchasable (empty price), or the stored quantity was 0.
	 *
	 * @param string $key
	 * @param array  $values
	 * @return void
	 */
	public static function on_session_drop( $key, $values ) {
		$product_id   = isset( $values['product_id'] ) ? (int) $values['product_id'] : 0;
		$variation_id = isset( $values['variation_id'] ) ? (int) $values['variation_id'] : 0;
		$is_child     = ! empty( $values['rental_add_on_of'] );

		if ( ! $is_child && ! self::is_set( $product_id ) ) {
			return;
		}

		$product = wc_get_product( $variation_id ? $variation_id : $product_id );
		$reason  = 'unknown';
		if ( ! $product || ! $product->exists() ) {
			$reason = 'product_missing';
		} elseif ( isset( $values['quantity'] ) && (int) $values['quantity'] < 1 ) {
			$reason = 'quantity_zero';
		} elseif ( ! $product->is_purchasable() ) {
			$reason = 'not_purchasable';
		}

		self::log( 'SESSION_DROP', array(
			'key'       => self::short( $key ),
			'kind'      => $is_child ? 'set_child' : 'set_parent',
			'product'   => $product_id,
			'variation' => $variation_id,
			'qty'       => isset( $values['quantity'] ) ? (int) $values['quantity'] : 0,
			'parent'    => $is_child ? self::short( $values['rental_add_on_of'] ) : '-',
			'status'    => get_post_status( $variation_id ? $variation_id : $product_id ) ?: 'MISSING',
			'price'     => $product ? (string) $product->get_price() : '-',
			'reason'    => $reason,
		), 'critical' );
	}

	// ---------------------------------------------------------------------
	// Runtime cart mutations
	// ---------------------------------------------------------------------

	/**
	 * A set line was removed during the request. The caller trail names the
	 * code that did it — customer action, orphan cleanup, reconciler, merger
	 * or the checkout availability re-check.
	 *
	 * @param string  $key
	 * @param WC_Cart $cart
	 * @return void
	 */
	public static function on_cart_item_removed( $key, $cart ) {
		$removed = isset( $cart->removed_cart_contents[ $key ] ) ? $cart->removed_cart_contents[ $key ] : array();
		if ( empty( $removed ) ) {
			return;
		}

		$product_id = isset( $removed['product_id'] ) ? (int) $removed['product_id'] : 0;
		$is_child   = ! empty( $removed['rental_add_on_of'] );
		if ( ! $is_child && ! self::is_set( $product_id ) ) {
			return;
		}

		self::log( 'CART_ITEM_REMOVED', array(
			'key'       => self::short( $key ),
			'kind'      => $is_child ? 'set_child' : 'set_parent',
			'product'   => $product_id,
			'variation' => isset( $removed['variation_id'] ) ? (int) $removed['variation_id'] : 0,
			'qty'       => isset( $removed['quantity'] ) ? (int) $removed['quantity'] : 0,
			'parent'    => $is_child ? self::short( $removed['rental_add_on_of'] ) : '-',
			'caller'    => self::caller_trail(),
		), 'warning' );
	}

	/**
	 * Quantity changes on a set line, including the propagation to children.
	 * A child driven to 0 here is removed by WooCommerce.
	 *
	 * @param string $key
	 * @param int    $quantity
	 * @param int    $old_quantity
	 * @return void
	 */
	public static function on_qty_update( $key, $quantity, $old_quantity ) {
		$contents = self::cart_contents();
		if ( ! isset( $contents[ $key ] ) ) {
			return;
		}

		$item     = $contents[ $key ];
		$is_child = ! empty( $item['rental_add_on_of'] );
		if ( ! $is_child && ! self::is_set( isset( $item['product_id'] ) ? $item['product_id'] : 0 ) ) {
			return;
		}

		self::log( 'SET_QTY_UPDATE', array(
			'key'     => self::short( $key ),
			'kind'    => $is_child ? 'set_child' : 'set_parent',
			'product' => isset( $item['product_id'] ) ? (int) $item['product_id'] : 0,
			'from'    => (int) $old_quantity,
			'to'      => (int) $quantity,
			'per_set' => isset( $item['rental_per_set_quantity'] ) ? (int) $item['rental_per_set_quantity'] : 0,
			'static'  => empty( $item['rental_static_quantity'] ) ? 0 : 1,
		), (int) $quantity < 1 ? 'critical' : 'debug' );
	}

	// ---------------------------------------------------------------------
	// Checkout / order
	// ---------------------------------------------------------------------

	public static function on_checkout_process() {
		self::snapshot( 'checkout_process', 0 );
	}

	/**
	 * Runs at priority 1 on `woocommerce_new_order`, before
	 * `rental_create_order` builds the payload at priority 10, so the trace
	 * shows the exact cart that produced the order.
	 *
	 * @param int $order_id
	 * @return void
	 */
	public static function on_new_order( $order_id ) {
		self::snapshot( 'new_order', $order_id );
	}

	/**
	 * One summary line, one line per cart line, and a critical line for every
	 * childless set parent.
	 *
	 * @param string $stage
	 * @param int    $order_id
	 * @return void
	 */
	public static function snapshot( $stage, $order_id = 0 ) {
		$contents = self::cart_contents();

		$parents  = 0;
		$children = 0;
		foreach ( $contents as $item ) {
			if ( ! empty( $item['rental_add_on_of'] ) ) {
				$children++;
			} elseif ( self::is_set( isset( $item['product_id'] ) ? $item['product_id'] : 0 ) ) {
				$parents++;
			}
		}

		self::log( 'CART_SNAPSHOT', array(
			'stage'        => $stage,
			'wp_id'        => $order_id ? (int) $order_id : '-',
			'lines'        => count( $contents ),
			'set_parents'  => $parents,
			'set_children' => $children,
		), 'info' );

		if ( 0 === $parents ) {
			return;
		}

		foreach ( $contents as $key => $item ) {
			$product_id   = isset( $item['product_id'] ) ? (int) $item['product_id'] : 0;
			$variation_id = isset( $item['variation_id'] ) ? (int) $item['variation_id'] : 0;

			self::log( 'CART_LINE', array(
				'stage'     => $stage,
				'wp_id'     => $order_id ? (int) $order_id : '-',
				'key'       => self::short( $key ),
				'product'   => $product_id,
				'variation' => $variation_id,
				'qty'       => isset( $item['quantity'] ) ? (int) $item['quantity'] : 0,
				'is_set'    => self::is_set( $product_id ) ? 1 : 0,
				'parent'    => ! empty( $item['rental_add_on_of'] ) ? self::short( $item['rental_add_on_of'] ) : '-',
				'set_id'    => isset( $item['rental_set_id'] ) ? (int) $item['rental_set_id'] : 0,
				'inv_id'    => (int) get_post_meta( $variation_id ? $variation_id : $product_id, '_rental_inventory_id', true ),
				'subtotal'  => isset( $item['line_subtotal'] ) ? $item['line_subtotal'] : '-',
			), 'debug' );
		}

		foreach ( $contents as $key => $item ) {
			if ( ! empty( $item['rental_add_on_of'] ) ) {
				continue;
			}
			$product_id = isset( $item['product_id'] ) ? (int) $item['product_id'] : 0;
			if ( ! self::is_set( $product_id ) ) {
				continue;
			}
			if ( self::children_of( $key ) ) {
				continue;
			}

			$def = self::read_definition( $product_id );
			self::log( 'SET_WITHOUT_CHILDREN', array(
				'stage'        => $stage,
				'wp_id'        => $order_id ? (int) $order_id : '-',
				'set'          => $product_id,
				'key'          => self::short( $key ),
				'qty'          => isset( $item['quantity'] ) ? (int) $item['quantity'] : 0,
				'def_items'    => $def['def_items'],
				'def_groups'   => $def['def_groups'],
				'note'         => 'Set parent has no child lines. Its inventories cannot reach Rentopian.',
			), 'critical' );
		}
	}

	// ---------------------------------------------------------------------
	// Readers
	// ---------------------------------------------------------------------

	/**
	 * Compact view of `$_POST['rental_add_ons']` — the array both the modern
	 * configurator and the legacy synthesizer use to describe child lines.
	 *
	 * @return array{count:int,groups:int,qty0:int,items:string}
	 */
	private static function read_submitted_children() {
		$out = array( 'count' => 0, 'groups' => 0, 'qty0' => 0, 'items' => '-' );

		if ( empty( $_POST['rental_add_ons'] ) || ! is_array( $_POST['rental_add_ons'] ) ) {
			return $out;
		}

		$items = array();
		foreach ( $_POST['rental_add_ons'] as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			$pid = isset( $entry['product_id'] ) ? (int) $entry['product_id'] : 0;
			$vid = isset( $entry['variant_id'] ) ? (int) $entry['variant_id'] : 0;
			$qty = isset( $entry['quantity'] ) ? (int) $entry['quantity'] : 1;
			$grp = ! empty( $entry['rental_set_group_uid'] )
				|| ( isset( $entry['item_type'] ) && 'grouped_child' === $entry['item_type'] );

			$out['count']++;
			if ( $grp ) {
				$out['groups']++;
			}
			if ( $qty < 1 ) {
				$out['qty0']++;
			}
			$items[] = $pid . ':' . $vid . 'x' . $qty . ( $grp ? 'g' : '' );

			if ( empty( $entry['addons'] ) || ! is_array( $entry['addons'] ) ) {
				continue;
			}
			foreach ( $entry['addons'] as $addon ) {
				if ( ! is_array( $addon ) ) {
					continue;
				}
				$a_pid = isset( $addon['product_id'] ) ? (int) $addon['product_id'] : 0;
				$a_vid = isset( $addon['variant_id'] ) ? (int) $addon['variant_id'] : 0;
				$a_qty = isset( $addon['quantity'] ) ? (int) $addon['quantity'] : 1;

				$out['count']++;
				if ( $a_qty < 1 ) {
					$out['qty0']++;
				}
				$items[] = $a_pid . ':' . $a_vid . 'x' . $a_qty . 'a';
			}
		}

		if ( $items ) {
			$out['items'] = implode( ',', $items );
		}

		return $out;
	}

	/**
	 * What the set definition says it contains, straight from postmeta.
	 * Compared against the submission this shows whether children were lost
	 * before the request or during it.
	 *
	 * @param int $set_id
	 * @return array{def_items:int,def_groups:int,def_addons:int,def_no_inv:int,def_no_inv_ids:string,hide_all:int,some_hidden:int,item_based:int}
	 */
	private static function read_definition( $set_id ) {
		$items  = get_post_meta( (int) $set_id, '_rental_set_items', true );
		$groups = get_post_meta( (int) $set_id, '_rental_set_grouped_items', true );

		$items  = is_array( $items ) ? $items : array();
		$groups = is_array( $groups ) ? $groups : array();

		$addons   = 0;
		$optional = 0;
		$no_inv   = array();
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			if ( ! empty( $item['addons'] ) && is_array( $item['addons'] ) ) {
				$addons += count( $item['addons'] );
			}
			// Optional, visible members are the ones the customer chooses;
			// they are the difference between "the package" and "the package
			// plus extras nobody asked for".
			if ( empty( $item['required'] ) && empty( $item['hidden'] ) ) {
				$optional++;
			}
			$lookup = ! empty( $item['variant_id'] ) ? (int) $item['variant_id'] : (int) ( $item['product_id'] ?? 0 );
			if ( $lookup && ! get_post_meta( $lookup, '_rental_inventory_id', true ) ) {
				$no_inv[] = $lookup;
			}
		}

		// Which configurator the customer saw. Only the modern one can carry
		// a selection back, so a submission with no selection fields means
		// something different under each layout.
		$modern = class_exists( 'Rental_Sets_Renderer', false )
			&& Rental_Sets_Renderer::will_render( (int) $set_id );

		return array(
			'def_items'      => count( $items ),
			'def_groups'     => count( $groups ),
			'def_addons'     => $addons,
			'def_optional'   => $optional,
			'def_no_inv'     => count( $no_inv ),
			'def_no_inv_ids' => $no_inv ? implode( ',', $no_inv ) : '',
			'modern'         => $modern ? 1 : 0,
			'hide_all'       => get_post_meta( (int) $set_id, '_rental_hide_items_on_website', true ) ? 1 : 0,
			'some_hidden'    => get_post_meta( (int) $set_id, '_rental_some_hidden_items', true ) ? 1 : 0,
			'item_based'     => get_post_meta( (int) $set_id, '_rental_item_based_total', true ) ? 1 : 0,
		);
	}

	/**
	 * Current WooCommerce error notices, flattened onto one line.
	 *
	 * @return string
	 */
	private static function error_notices() {
		if ( ! function_exists( 'wc_get_notices' ) ) {
			return '-';
		}
		$out = array();
		foreach ( (array) wc_get_notices( 'error' ) as $notice ) {
			$text = is_array( $notice ) ? ( $notice['notice'] ?? '' ) : (string) $notice;
			$text = trim( wp_strip_all_tags( (string) $text ) );
			if ( '' !== $text ) {
				$out[] = $text;
			}
		}
		return $out ? implode( ' || ', $out ) : '-';
	}

	// ---------------------------------------------------------------------
	// Cart helpers
	// ---------------------------------------------------------------------

	private static function cart_contents() {
		if ( ! function_exists( 'WC' ) || ! WC() || ! WC()->cart ) {
			return array();
		}
		return (array) WC()->cart->cart_contents;
	}

	/**
	 * @param string $parent_key
	 * @return array<string,array>
	 */
	private static function children_of( $parent_key ) {
		$children = array();
		foreach ( self::cart_contents() as $key => $item ) {
			if ( isset( $item['rental_add_on_of'] ) && (string) $item['rental_add_on_of'] === (string) $parent_key ) {
				$children[ $key ] = $item;
			}
		}
		return $children;
	}

	/**
	 * The parent line that survived the submission — the added key when it is
	 * still present, otherwise the line the merger or edit-mode kept.
	 *
	 * @param string $hint_key
	 * @param int    $product_id
	 * @param int    $variation_id
	 * @return string|null
	 */
	private static function find_parent_key( $hint_key, $product_id, $variation_id ) {
		$contents = self::cart_contents();
		if ( isset( $contents[ $hint_key ] ) && empty( $contents[ $hint_key ]['rental_add_on_of'] ) ) {
			return $hint_key;
		}
		foreach ( $contents as $key => $item ) {
			if ( ! empty( $item['rental_add_on_of'] ) ) {
				continue;
			}
			if ( (int) ( $item['product_id'] ?? 0 ) !== (int) $product_id ) {
				continue;
			}
			if ( (int) ( $item['variation_id'] ?? 0 ) !== (int) $variation_id ) {
				continue;
			}
			return $key;
		}
		return null;
	}

	/**
	 * @param array $children
	 * @return string
	 */
	private static function describe_children( array $children ) {
		if ( empty( $children ) ) {
			return '-';
		}
		$out = array();
		foreach ( $children as $item ) {
			$out[] = (int) ( $item['product_id'] ?? 0 )
				. ':' . (int) ( $item['variation_id'] ?? 0 )
				. 'x' . (int) ( $item['quantity'] ?? 0 );
		}
		return implode( ',', $out );
	}

	/**
	 * Plugin/theme functions on the current call stack, so a removal can be
	 * attributed to the code that triggered it.
	 *
	 * @return string
	 */
	private static function caller_trail() {
		$frames = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 20 ); // phpcs:ignore
		$out    = array();
		foreach ( $frames as $frame ) {
			$name = isset( $frame['function'] ) ? $frame['function'] : '';
			if ( '' === $name || 'caller_trail' === $name || 'on_cart_item_removed' === $name ) {
				continue;
			}
			if ( isset( $frame['class'] ) ) {
				if ( __CLASS__ === $frame['class'] ) {
					continue;
				}
				$name = $frame['class'] . '::' . $name;
			}
			if ( in_array( $name, array( 'do_action', 'apply_filters', 'call_user_func_array', 'WP_Hook::do_action', 'WP_Hook::apply_filters' ), true ) ) {
				continue;
			}
			$out[] = $name;
			if ( count( $out ) >= 6 ) {
				break;
			}
		}
		return $out ? implode( '<', $out ) : '-';
	}

	private static function is_set( $product_id ) {
		$product_id = (int) $product_id;
		return $product_id > 0 && (bool) get_post_meta( $product_id, '_rental_is_set', true );
	}

	private static function short( $key ) {
		$key = (string) $key;
		return strlen( $key ) > 8 ? substr( $key, 0, 8 ) : ( '' === $key ? '-' : $key );
	}

	// ---------------------------------------------------------------------
	// Writer
	// ---------------------------------------------------------------------

	/**
	 * Writes one structured line. Same key=value shape as the order log so
	 * the two files read alike.
	 *
	 * @param string $event
	 * @param array  $context
	 * @param string $level
	 * @return void
	 */
	public static function log( $event, array $context = array(), $level = 'info' ) {
		if ( ! self::enabled() ) {
			return;
		}

		$parts = array( $event );
		foreach ( $context as $key => $value ) {
			if ( is_array( $value ) || is_object( $value ) ) {
				$value = wp_json_encode( $value );
			} elseif ( is_bool( $value ) ) {
				$value = $value ? 'true' : 'false';
			} elseif ( null === $value ) {
				$value = 'null';
			}
			$value = (string) $value;
			if ( strlen( $value ) > 500 ) {
				$value = substr( $value, 0, 497 ) . '...';
			}
			$value   = str_replace( array( "\n", "\r", '|' ), array( ' ', ' ', '/' ), $value );
			$parts[] = $key . '=' . $value;
		}

		Project_WP_Logger::write( implode( ' | ', $parts ), $level, self::SOURCE, self::log_file_rel() );
	}

	/**
	 * Daily file next to the order log, so both are visible to the same
	 * file-manager tooling.
	 *
	 * @return string
	 */
	private static function log_file_rel() {
		return 'wp-content/uploads/wc-logs/' . self::FILE_PREFIX . gmdate( 'Y-m-d' ) . '.log';
	}
}

endif;
