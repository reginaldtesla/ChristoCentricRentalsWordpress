<?php
/**
 * Rentopian Set Integrity Audit
 *
 * Read-only. Reports the true WordPress state of every member of a set so a
 * member that cannot reach the cart can be attributed to a specific cause
 * rather than guessed at. Nothing here changes cart, order or sync behaviour.
 *
 * A set member is stored in `_rental_set_items` as a pair of WordPress post
 * ids (`product_id` plus an optional `variant_id`). Those posts can be in one
 * of several states, and each state fails differently and at a different
 * moment:
 *
 *   publish            — usable.
 *   private / draft /  — wc_get_product() still returns an object, so the child
 *   pending / future     line is created; is_purchasable() is false, so
 *                        WooCommerce discards it when the cart is next rebuilt
 *                        from the session, showing "can no longer be
 *                        purchased". The child leaves the cart between page
 *                        loads.
 *   trash              — same as above: loadable, not purchasable, dropped on
 *                        the next session read.
 *   missing            — the post row is gone. wc_get_product() returns false
 *                        and the child is never created at all.
 *
 * Two views are produced:
 *
 *   SET_MEMBER_AUDIT   — one line per member: post status of the product and
 *                        the variation, whether a relations row still maps
 *                        that variation, the variations the parent product
 *                        actually has today, and the resulting verdict.
 *   SET_AUDIT_SUMMARY  — one line per set: how many members are usable and how
 *                        many fall into each failing state.
 *
 * Plus SITE_POST_STATE, a single census of product / variation / set posts by
 * post_status, so the size of the bin and the private catalogue is on record
 * next to the per-member findings.
 *
 * Triggers:
 *   - Automatically for a set being added to the cart, once per set per
 *     request, alongside the existing add-to-cart trace.
 *   - On demand for the whole catalogue by loading any wp-admin screen with
 *     `?rentopian_set_audit=1` as a user who can manage WooCommerce.
 *     `&set=<id>` limits the scan to one set.
 *
 * Output goes to the set-cart trace file so a single upload carries both the
 * live trace and the audit.
 *
 * @package rentopian-sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Rentopian_Set_Integrity_Audit', false ) ) :

class Rentopian_Set_Integrity_Audit {

	const QUERY_VAR = 'rentopian_set_audit';

	/**
	 * Sets already audited in this request.
	 *
	 * @var array<int,bool>
	 */
	private static $audited = array();

	/**
	 * Whether the site-wide post census has been emitted this request.
	 *
	 * @var bool
	 */
	private static $census_done = false;

	public static function register() {
		if ( ! class_exists( 'Rentopian_Set_Cart_Tracer', false ) || ! Rentopian_Set_Cart_Tracer::enabled() ) {
			return;
		}

		// Audit the set being added, immediately after the tracer's
		// SET_VALIDATE_IN line so both describe the same submission.
		add_filter( 'woocommerce_add_to_cart_validation', array( __CLASS__, 'on_add_to_cart' ), 2, 3 );

		// On-demand catalogue scan.
		add_action( 'admin_init', array( __CLASS__, 'maybe_run_full_scan' ) );
	}

	/**
	 * @param bool $passed
	 * @param int  $product_id
	 * @param int  $quantity
	 * @return bool Unmodified.
	 */
	public static function on_add_to_cart( $passed, $product_id, $quantity ) {
		if ( get_post_meta( (int) $product_id, '_rental_is_set', true ) ) {
			self::census();
			self::audit_set( (int) $product_id, 'add_to_cart' );
		}
		return $passed;
	}

	/**
	 * `?rentopian_set_audit=1` on any admin screen. Optional `&set=<id>`.
	 *
	 * @return void
	 */
	public static function maybe_run_full_scan() {
		if ( empty( $_GET[ self::QUERY_VAR ] ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		self::census();

		$single = isset( $_GET['set'] ) ? (int) $_GET['set'] : 0;
		if ( $single > 0 ) {
			self::audit_set( $single, 'manual' );
			return;
		}

		$set_ids = self::all_set_ids();
		Rentopian_Set_Cart_Tracer::log( 'SET_AUDIT_SCAN_BEGIN', array(
			'sets' => count( $set_ids ),
		) );

		$broken = 0;
		foreach ( $set_ids as $set_id ) {
			$result = self::audit_set( $set_id, 'scan' );
			if ( $result && $result['unusable'] > 0 ) {
				$broken++;
			}
		}

		Rentopian_Set_Cart_Tracer::log( 'SET_AUDIT_SCAN_END', array(
			'sets'        => count( $set_ids ),
			'sets_broken' => $broken,
		), $broken > 0 ? 'critical' : 'info' );
	}

	/**
	 * Every product post carrying `_rental_is_set`, regardless of status, so a
	 * set that is itself in the bin or private is audited too.
	 *
	 * @return int[]
	 */
	private static function all_set_ids() {
		global $wpdb;

		$ids = $wpdb->get_col(
			"SELECT DISTINCT pm.post_id
			   FROM {$wpdb->postmeta} pm
			   INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			  WHERE pm.meta_key = '_rental_is_set'
			    AND pm.meta_value = '1'
			    AND p.post_type = 'product'
			  ORDER BY pm.post_id ASC"
		);

		return array_map( 'intval', (array) $ids );
	}

	/**
	 * One census line per request: how many product / variation / set posts sit
	 * in each post_status. Establishes whether the bin and the private
	 * catalogue are large enough to matter.
	 *
	 * @return void
	 */
	public static function census() {
		if ( self::$census_done ) {
			return;
		}
		self::$census_done = true;

		global $wpdb;

		$rows = $wpdb->get_results(
			"SELECT post_type, post_status, COUNT(*) AS total
			   FROM {$wpdb->posts}
			  WHERE post_type IN ('product','product_variation')
			  GROUP BY post_type, post_status",
			ARRAY_A
		);

		$counts = array();
		foreach ( (array) $rows as $row ) {
			$counts[] = $row['post_type'] . ':' . $row['post_status'] . '=' . (int) $row['total'];
		}

		$sets = $wpdb->get_results(
			"SELECT p.post_status, COUNT(*) AS total
			   FROM {$wpdb->posts} p
			   INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_rental_is_set' AND pm.meta_value = '1'
			  WHERE p.post_type = 'product'
			  GROUP BY p.post_status",
			ARRAY_A
		);

		$set_counts = array();
		foreach ( (array) $sets as $row ) {
			$set_counts[] = $row['post_status'] . '=' . (int) $row['total'];
		}

		Rentopian_Set_Cart_Tracer::log( 'SITE_POST_STATE', array(
			'posts' => $counts ? implode( ',', $counts ) : '-',
			'sets'  => $set_counts ? implode( ',', $set_counts ) : '-',
		) );
	}

	/**
	 * Audit one set's members.
	 *
	 * @param int    $set_id
	 * @param string $stage
	 * @return array|null Counters, or null when the set has no items.
	 */
	public static function audit_set( $set_id, $stage = 'manual' ) {
		$set_id = (int) $set_id;
		if ( isset( self::$audited[ $set_id ] ) ) {
			return null;
		}
		self::$audited[ $set_id ] = true;

		$items = get_post_meta( $set_id, '_rental_set_items', true );
		if ( ! is_array( $items ) || empty( $items ) ) {
			Rentopian_Set_Cart_Tracer::log( 'SET_AUDIT_SUMMARY', array(
				'stage' => $stage,
				'set'   => $set_id,
				'items' => 0,
				'note'  => 'Set has no items in _rental_set_items.',
			), 'warning' );
			return null;
		}

		$counters = array(
			'usable'     => 0,
			'unusable'   => 0,
			'missing'    => 0,
			'trash'      => 0,
			'private'    => 0,
			'other'      => 0,
			'no_inv'     => 0,
			'remappable' => 0,
		);

		foreach ( $items as $index => $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			self::audit_member( $set_id, (int) $index, $item, $stage, $counters );
		}

		Rentopian_Set_Cart_Tracer::log( 'SET_AUDIT_SUMMARY', array(
			'stage'      => $stage,
			'set'        => $set_id,
			'set_status' => get_post_status( $set_id ) ?: 'MISSING',
			'items'      => count( $items ),
			'usable'     => $counters['usable'],
			'unusable'   => $counters['unusable'],
			'missing'    => $counters['missing'],
			'trash'      => $counters['trash'],
			'private'    => $counters['private'],
			'other'      => $counters['other'],
			'no_inv'     => $counters['no_inv'],
			'remappable' => $counters['remappable'],
		), $counters['unusable'] > 0 ? 'critical' : 'info' );

		return $counters;
	}

	/**
	 * One member: the state of both posts, the relations mapping, the parent
	 * product's current variations, and a verdict.
	 *
	 * @param int    $set_id
	 * @param int    $index
	 * @param array  $item
	 * @param string $stage
	 * @param array  $counters Passed by reference.
	 * @return void
	 */
	private static function audit_member( $set_id, $index, array $item, $stage, array &$counters ) {
		$product_id = isset( $item['product_id'] ) ? (int) $item['product_id'] : 0;
		$variant_id = isset( $item['variant_id'] ) ? (int) $item['variant_id'] : 0;

		$product_state = self::post_state( $product_id );
		$variant_state = $variant_id ? self::post_state( $variant_id ) : null;

		// The post the cart would actually load for this member.
		$target_id    = $variant_id ?: $product_id;
		$target_state = $variant_id ? $variant_state : $product_state;

		$verdict = self::verdict( $target_state );

		$inv_id = $target_id ? (string) get_post_meta( $target_id, '_rental_inventory_id', true ) : '';

		// Relations: does a row still map this variation, and what Rentopian
		// variant does it correspond to? A surviving row is what makes a
		// stale id repairable without a full re-sync.
		$rel = self::variant_relation( $variant_id );

		// What the parent product actually has today. When the stored variant
		// is gone, this is the candidate set a repair would choose from.
		$live = self::live_variations( $product_id );

		$context = array(
			'stage'          => $stage,
			'set'            => $set_id,
			'idx'            => $index,
			'product'        => $product_id,
			'product_status' => $product_state['status'],
			'variant'        => $variant_id ?: '-',
			'variant_status' => $variant_id ? $variant_state['status'] : '-',
			'variant_parent' => $variant_id ? $variant_state['parent'] : '-',
			'purchasable'    => null === $target_state['purchasable'] ? '-' : ( $target_state['purchasable'] ? 1 : 0 ),
			'inv_id'         => '' === $inv_id ? '-' : $inv_id,
			'rel_row'        => $rel['found'] ? 1 : 0,
			'rel_rental_id'  => $rel['rental_id'],
			'rel_division'   => $rel['division_id'],
			'rel_current_wp' => $rel['current_wp_id'],
			'live_vars'      => $live['summary'],
			'live_count'     => $live['count'],
			'has_optional'   => empty( $item['optional_items'] ) ? 0 : count( (array) $item['optional_items'] ),
			'addons'         => empty( $item['addons'] ) ? 0 : count( (array) $item['addons'] ),
			'rental_var_id'  => isset( $item['rental_variant_id'] ) ? (int) $item['rental_variant_id'] : '-',
			'hidden'         => empty( $item['hidden'] ) ? 0 : 1,
			'verdict'        => $verdict,
		);

		$level = ( 'usable' === $verdict ) ? 'debug' : 'critical';
		Rentopian_Set_Cart_Tracer::log( 'SET_MEMBER_AUDIT', $context, $level );

		if ( 'usable' === $verdict ) {
			$counters['usable']++;
		} else {
			$counters['unusable']++;
			if ( isset( $counters[ $verdict ] ) ) {
				$counters[ $verdict ]++;
			} else {
				$counters['other']++;
			}
		}

		if ( '' === $inv_id ) {
			$counters['no_inv']++;
		}

		// Repairable without a re-sync when a live replacement can be named:
		// either the relations row still resolves, or the parent product has
		// exactly one usable variation to fall back to.
		if ( 'usable' !== $verdict ) {
			if ( $rel['current_wp_id'] > 0 || 1 === $live['usable_count'] ) {
				$counters['remappable']++;
			}
		}
	}

	/**
	 * State of a post id: whether it exists, its status and type, its parent,
	 * and whether WooCommerce considers it purchasable.
	 *
	 * @param int $post_id
	 * @return array{exists:bool,status:string,type:string,parent:mixed,purchasable:?bool}
	 */
	private static function post_state( $post_id ) {
		$post_id = (int) $post_id;
		$out     = array(
			'exists'      => false,
			'status'      => 'MISSING',
			'type'        => '-',
			'parent'      => '-',
			'purchasable' => null,
		);

		if ( $post_id <= 0 ) {
			$out['status'] = 'NONE';
			return $out;
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return $out;
		}

		$out['exists'] = true;
		$out['status'] = $post->post_status;
		$out['type']   = $post->post_type;
		$out['parent'] = (int) $post->post_parent;

		$product = wc_get_product( $post_id );
		if ( $product instanceof WC_Product ) {
			$out['purchasable'] = $product->is_purchasable();
		} else {
			// Loadable as a post but not as a product — e.g. the post type is
			// no longer a product. The cart cannot use it either way.
			$out['status'] = $out['status'] . '/NOT_A_PRODUCT';
		}

		return $out;
	}

	/**
	 * Reduce a post state to the reason the member cannot be used.
	 *
	 * @param array $state
	 * @return string usable|missing|trash|private|other
	 */
	private static function verdict( array $state ) {
		if ( ! $state['exists'] ) {
			return 'missing';
		}
		if ( null === $state['purchasable'] ) {
			return 'other';
		}
		if ( 'trash' === $state['status'] ) {
			return 'trash';
		}
		if ( 'private' === $state['status'] ) {
			return 'private';
		}
		if ( 'publish' !== $state['status'] ) {
			return 'other';
		}
		if ( ! $state['purchasable'] ) {
			return 'other';
		}
		return 'usable';
	}

	/**
	 * The relations row for a stored variation id, plus the WordPress id that
	 * the same Rentopian variant maps to today. When `current_wp_id` differs
	 * from the stored id, the set is holding a superseded post id.
	 *
	 * @param int $variant_id
	 * @return array{found:bool,rental_id:mixed,division_id:mixed,current_wp_id:int}
	 */
	private static function variant_relation( $variant_id ) {
		global $wpdb, $rental_tables;

		$out = array(
			'found'         => false,
			'rental_id'     => '-',
			'division_id'   => '-',
			'current_wp_id' => 0,
		);

		$variant_id = (int) $variant_id;
		if ( $variant_id <= 0 || empty( $rental_tables['variant_relations'] ) ) {
			return $out;
		}

		$table = $wpdb->prefix . $rental_tables['variant_relations'];

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT `rental_id`, `rental_division_id` FROM `{$table}` WHERE `id` = %d LIMIT 1", $variant_id ),
			ARRAY_A
		);

		if ( ! $row ) {
			return $out;
		}

		$out['found']       = true;
		$out['rental_id']   = (int) $row['rental_id'];
		$out['division_id'] = (int) $row['rental_division_id'];

		// The id this Rentopian variant maps to now. Equal to the stored id
		// when the mapping is current.
		$current = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT `id` FROM `{$table}` WHERE `rental_id` = %d AND `rental_division_id` = %d ORDER BY `id` DESC LIMIT 1",
				(int) $row['rental_id'],
				(int) $row['rental_division_id']
			)
		);
		$out['current_wp_id'] = (int) $current;

		return $out;
	}

	/**
	 * The variations the parent product has today, with their statuses, and
	 * how many of them are usable. Names the candidates any repair would pick
	 * from when the stored variation is gone.
	 *
	 * @param int $product_id
	 * @return array{summary:string,count:int,usable_count:int}
	 */
	private static function live_variations( $product_id ) {
		global $wpdb;

		$out = array( 'summary' => '-', 'count' => 0, 'usable_count' => 0 );

		$product_id = (int) $product_id;
		if ( $product_id <= 0 ) {
			return $out;
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, post_status FROM {$wpdb->posts} WHERE post_parent = %d AND post_type = 'product_variation' ORDER BY ID ASC",
				$product_id
			),
			ARRAY_A
		);

		if ( empty( $rows ) ) {
			return $out;
		}

		$parts = array();
		foreach ( $rows as $row ) {
			$parts[] = (int) $row['ID'] . ':' . $row['post_status'];
			if ( 'publish' === $row['post_status'] ) {
				$out['usable_count']++;
			}
		}

		$out['count']   = count( $rows );
		$out['summary'] = implode( ',', $parts );

		return $out;
	}
}

endif;
