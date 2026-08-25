<?php
/**
 * Rentopian availability / blocked-inventory filtering.
 *
 * Computes a per-request set of blocked product, variation and set IDs from the visitor's
 * selected rental dates and division, and applies it as a single source of truth across:
 *
 *   - Listings (shop / category / tag / search / custom product queries)  -> posts_where
 *   - Single product add-to-cart enforcement (server side)                -> is_purchasable
 *   - Cart / checkout validation against the current dates                -> woocommerce_check_cart_items
 *   - The rental date form when a listing returns no products             -> woocommerce_no_products_found
 *
 * The blocked set is built from the plugin's existing query helpers
 * (getInventoryBlockedRanges, getInventoryBlockedItems,
 *  get_unavailable_*_with_blocked_inventory_items_filter,
 *  get_unavailable_*_with_only_division_filter, rental_get_add_on_post_ids, decrypt_data),
 * so the underlying SQL has a single implementation.
 *
 * All hooks are registered only when the "Filter unavailable products/variants listing"
 * setting (rental_filter_unavailable_products) is enabled.
 *
 * PHP 7.4 compatible.
 *
 * @package Rentopian_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Rentopian_Availability_Filter' ) ) {

	class Rentopian_Availability_Filter {

		/** @var Rentopian_Availability_Filter|null */
		private static $instance = null;

		/** @var array|null Resolved request context (memoized per request). */
		private $context = null;

		/** @var array|null "You cannot rent this" ids: inventory blocks + division mismatch. */
		private $avail_blocked = null;

		/** @var array|null Subset of $avail_blocked caused by a type-2 inventory block (excludes division). */
		private $inventory_blocked = null;

		/** @var array|null "Do not show in listings" ids: availability + add-ons + duplicates. */
		private $listing_hidden = null;

		public static function instance() {
			if ( null === self::$instance ) {
				self::$instance = new self();
			}
			return self::$instance;
		}

		private function __construct() {
			$this->register_hooks();
		}

		/**
		 * Master switch for the availability filter.
		 *
		 * This filter enforces only STRUCTURAL availability: admin inventory blocks
		 * (blackouts) and division/location availability. Both are independent of the
		 * overbooking setting -- an item out for repair, or not offered in the selected
		 * division, is unavailable whether or not overbooking is allowed. Overbooking
		 * governs booking-QUANTITY availability, which is handled by the rental quantity
		 * logic, not here.
		 *
		 * Every entry point (hook registration, the single-product AJAX check and the
		 * script enqueue) reads this one method, so they turn on and off together.
		 *
		 * @return bool
		 */
		public function is_active() {
			return (bool) get_option( 'rental_filter_unavailable_products', 0 );
		}

		private function register_hooks() {
			if ( ! $this->is_active() ) {
				return;
			}

			// 1) Listings: exclude blocked ids from EVERY front-end product query.
			add_filter( 'posts_where', array( $this, 'filter_posts_where' ), 10, 2 );

			// 2) Search: keep ONLY the stemmed-search ordering (blocking handled by posts_where).
			add_action( 'pre_get_posts', array( $this, 'apply_stemmed_search' ) );
			add_action( 'pre_get_posts', array( $this, 'store_original_search_term' ), 0 );

			// 3) Single product: server-side enforcement (complements the JS UX check).
			add_filter( 'woocommerce_is_purchasable', array( $this, 'filter_is_purchasable' ), 10, 2 );
			add_filter( 'woocommerce_variation_is_purchasable', array( $this, 'filter_is_purchasable' ), 10, 2 );

			// 3b) Log every cart item WooCommerce drops from the session because it is no longer
			//     purchasable (the "...has been removed from your cart because it can no longer be
			//     purchased..." core notice).
			add_filter( 'woocommerce_cart_item_is_purchasable', array( $this, 'log_cart_item_removal' ), 999, 4 );

			// 3a) Single product: show a message in the add-to-cart slot when the product is
			//     blocked by an inventory block on the selected dates (the button is suppressed
			//     by the is_purchasable filter above). Priority 31 places it where the button
			//     would render (woocommerce_template_single_add_to_cart runs at 30).
			add_action( 'woocommerce_single_product_summary', array( $this, 'inventory_blocked_notice' ), 31 );

			// 4) Cart / checkout: validate items against the currently selected dates.
			add_action( 'woocommerce_check_cart_items', array( $this, 'validate_cart_items' ) );

			// 5) Render the date form when a listing returns no products -- but ONLY when the
			//    standalone horizontal form is the active layout on listing pages. This mirrors
			//    the exact conditions under which the form is hooked to woocommerce_before_shop_loop,
			//    so the form is never injected in fly-in-cart or checkout-only modes.
			if ( function_exists( 'rental_create_date_form' ) && $this->standalone_form_on_listings_enabled() ) {
				add_action( 'woocommerce_no_products_found', 'rental_create_date_form', 5 );
			}
		}

		/**
		 * Whether the standalone horizontal date form is shown on listing pages.
		 *
		 * Matches the existing gate around
		 *   add_action( 'woocommerce_before_shop_loop', 'rental_create_date_form', 10 );
		 * i.e. NOT hourly mode, NOT the fly-in-cart layout, and NOT checkout-only mode.
		 *
		 * @return bool
		 */
		private function standalone_form_on_listings_enabled() {
			if ( 'hourly' === get_option( 'rental_synchronized_product_type' ) ) {
				return false;
			}
			if ( 'in-cart' === get_option( 'rental_form_layout' ) ) {
				return false;
			}
			// Checkout-only mode (dates-on-checkout + overbook) hides the form from listings.
			if ( $this->dates_deferred_to_checkout() ) {
				return false;
			}
			return true;
		}

		/**
		 * "Dates on checkout" mode: the rental date is chosen at checkout, not on the
		 * product page or in listings. Active only when dates-on-checkout AND overbooking
		 * are both enabled. In this mode an item can be added to the cart before any date
		 * is selected; availability is validated later, once a date is chosen at checkout.
		 *
		 * @return bool
		 */
		private function dates_deferred_to_checkout() {
			return 1 == get_option( 'rental_dates_on_checkout', 0 )
				&& 1 == get_option( 'rental_allow_overbook', 1 );
		}

		/* ----------------------------------------------------------------- *
		 * Context resolution (single place – DRY)
		 * ----------------------------------------------------------------- */

		/**
		 * Resolve the current request context: division, selected dates, duplicate filter.
		 * Memoized for the whole request, so it is cheap to call from every hook.
		 *
		 * @return array
		 */
		public function get_context() {
			if ( null !== $this->context ) {
				return $this->context;
			}

			$key = get_option( 'rental_encryption_key' );

			// --- Division --------------------------------------------------
			$division_id = 0;
			if ( isset( $_POST['rental_division_id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
				$division_id = intval( $_POST['rental_division_id'] ); // phpcs:ignore WordPress.Security.NonceVerification
			} elseif ( isset( $_COOKIE['rental_division_id'] ) ) {
				$division_id = intval( $_COOKIE['rental_division_id'] );
			}

			// --- Selected rental dates (decrypted -> timestamps) -----------
			$start = 0;
			$end   = 0;
			if ( 'hourly' !== get_option( 'rental_synchronized_product_type' ) ) {
				if ( ! empty( $_COOKIE['rental_start_date'] ) ) {
					$dec   = decrypt_data( $_COOKIE['rental_start_date'], $key );
					$start = $dec ? strtotime( $dec ) : 0;
				}
				if ( ! empty( $_COOKIE['rental_end_date'] ) ) {
					$dec = decrypt_data( $_COOKIE['rental_end_date'], $key );
					$end = $dec ? strtotime( $dec ) : 0;
				}
				if ( $start && ! $end ) {
					$end = $start;
				}
			}

			// --- Zip presence (mirrors existing duplicate-filter gating) ---
			$decrypted_zip = 0;
			if ( ! empty( $_COOKIE['rental_zip'] ) ) {
				$decrypted_zip = decrypt_data( $_COOKIE['rental_zip'], $key );
			}

			// --- Duplicate (location-based) filter -------------------------
			$dup_on  = 0;
			$dup_div = 0;
			$zip_ok  = ( get_option( 'rental_hide_zip' )
				|| ( ! get_option( 'rental_hide_zip' ) && ( empty( $_COOKIE['rental_zip'] ) || 1 == $decrypted_zip ) ) );
			if ( empty( $division_id ) && $zip_ok ) {
				$dup_on  = intval( get_option( 'rental_location_based_duplicate_filter', 0 ) );
				$dup_div = intval( get_option( 'rental_location_based_duplicate_filter_division_id', 0 ) );
			}

			$this->context = array(
				'division_id' => $division_id,
				'start'       => $start,
				'end'         => $end,
				'has_dates'   => (bool) $start,
				'dup_on'      => $dup_on,
				'dup_div'     => $dup_div,
				'blackout'    => false, // set true during availability_blocked_ids() if a type-1 block overlaps.
			);

			return $this->context;
		}

		/* ----------------------------------------------------------------- *
		 * Blocked-id computation
		 * ----------------------------------------------------------------- */

		/**
		 * "You cannot rent this" ids for the current dates/division.
		 * Inventory blocks (type 2: products, variants AND sets) + division mismatch.
		 * NO add-ons, NO duplicates.
		 * Sets context['blackout'] = true if a type-1 (full) block overlaps the dates.
		 *
		 * @return int[]
		 */
		public function availability_blocked_ids() {
			if ( null !== $this->avail_blocked ) {
				return $this->avail_blocked;
			}

			global $wpdb, $rental_tables;
			$ctx = $this->get_context();

			$blocked                 = array();
			$this->inventory_blocked = array();

			$product_rel = $wpdb->prefix . $rental_tables['product_relations'];
			$variant_rel = $wpdb->prefix . $rental_tables['variant_relations'];
			$set_rel     = $wpdb->prefix . $rental_tables['set_relations'];
			$blocks_tbl  = $wpdb->prefix . $rental_tables['inventory_blocks'];

			$ljm_product = "LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = product_rel.id AND pm.meta_key = '_stock_status'";
			$ljm_variant = "LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = variant_rel.id AND pm.meta_key = '_stock_status'";
			$ljm_set     = "LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = set_rel.id AND pm.meta_key = '_stock_status'";

			$division_sql = $ctx['division_id'] ? ' AND rental_division_id = ' . absint( $ctx['division_id'] ) . ' ' : '';

			$inventory_active = false;

			$blocks_exist = ( $wpdb->get_var( "SHOW TABLES LIKE '" . esc_sql( $blocks_tbl ) . "'" ) === $blocks_tbl );

			// Inventory blocks are only meaningful once dates are chosen.
			if ( $blocks_exist && $ctx['has_dates'] ) {

				// type 1 = full blackout overlapping the selected range.
				$blackout = (int) getInventoryBlockedRanges( $blocks_tbl, $ctx['start'], $ctx['end'] );
				if ( $blackout > 0 ) {
					$this->context['blackout'] = true;
					$this->avail_blocked       = array();
					return $this->avail_blocked;
				}

				// type 2 = specific blocked items overlapping the selected range.
				$block_rel_tbl    = $wpdb->prefix . $rental_tables['inventory_block_relations'];
				$blocked_post_ids = getInventoryBlockedItems( $blocks_tbl, $block_rel_tbl, $ctx['start'], $ctx['end'] );

				if ( ! empty( $blocked_post_ids ) ) {
					$inventory_active = true;
					$placeholders     = implode( ', ', array_fill( 0, count( $blocked_post_ids ), '%d' ) );

					$p = get_unavailable_product_ids_with_blocked_inventory_items_filter(
						$product_rel, $ljm_product, $placeholders, '', $division_sql, $blocked_post_ids
					);
					$v = get_unavailable_variant_ids_with_blocked_inventory_items_filter(
						$variant_rel, $ljm_variant, $placeholders, '', $division_sql, $blocked_post_ids
					);
					// Sets directly targeted by an inventory block (set post id present in the
					// block relations). Mirrors the product/variant filter against set_relations.
					$s = $this->blocked_set_ids( $set_rel, $ljm_set, $placeholders, $division_sql, $blocked_post_ids );

					$blocked = array_merge( $blocked, (array) $p, (array) $v, (array) $s );

					$this->inventory_blocked = $this->normalize_ids( array_merge( (array) $p, (array) $v, (array) $s ) );
				}
			}

			// Division-only filtering: hide items that are not available in the selected division.
			if ( ! $inventory_active && $ctx['division_id'] ) {
				if ( function_exists( 'get_unavailable_product_ids_with_only_division_filter' ) ) {
					$blocked = array_merge(
						$blocked,
						(array) get_unavailable_product_ids_with_only_division_filter( $ctx['division_id'], $product_rel, $ljm_product )
					);
				}
				if ( function_exists( 'get_unavailable_variant_ids_with_only_division_filter' ) ) {
					$blocked = array_merge(
						$blocked,
						(array) get_unavailable_variant_ids_with_only_division_filter( $ctx['division_id'], $variant_rel, $ljm_variant )
					);
				}
			}

			$this->avail_blocked = $this->normalize_ids( $blocked );
			return $this->avail_blocked;
		}

		/**
		 * "Do not show in listings" ids = availability-blocked + add-ons + duplicate copies.
		 *
		 * @return int[]
		 */
		public function listing_hidden_ids() {
			if ( null !== $this->listing_hidden ) {
				return $this->listing_hidden;
			}

			$hidden = $this->availability_blocked_ids(); // also resolves the blackout flag.

			// Add-ons / hidden helper products are always removed from listings.
			if ( function_exists( 'rental_get_add_on_post_ids' ) ) {
				$hidden = array_merge( $hidden, (array) rental_get_add_on_post_ids() );
			}

			// Duplicate (location-based) copies to hide.
			$ctx = $this->get_context();
			if ( $ctx['dup_on'] && $ctx['dup_div'] ) {
				$hidden = array_merge( $hidden, $this->duplicate_ids_to_hide( $ctx['dup_div'] ) );
			}

			$this->listing_hidden = $this->normalize_ids( $hidden );
			return $this->listing_hidden;
		}

		/**
		 * Instock duplicate copies that are NOT in the primary division (these are the
		 * "second copies" the location-based de-dup feature hides from listings).
		 *
		 * @param int $primary_division_id
		 * @return int[]
		 */
		private function duplicate_ids_to_hide( $primary_division_id ) {
			global $wpdb, $rental_tables;

			$primary_division_id = absint( $primary_division_id );
			if ( ! $primary_division_id ) {
				return array();
			}

			$variant_rel = $wpdb->prefix . $rental_tables['variant_relations'];
			$product_rel = $wpdb->prefix . $rental_tables['product_relations'];

			$sql_v = "
				SELECT variant_rel.id
				FROM {$variant_rel} variant_rel
				LEFT JOIN {$wpdb->postmeta} pm  ON pm.post_id  = variant_rel.id AND pm.meta_key  = '_stock_status'
				LEFT JOIN {$wpdb->postmeta} pm2 ON pm2.post_id = variant_rel.id AND pm2.meta_key = '_rental_is_duplicate'
				WHERE pm.meta_value = 'instock'
				  AND variant_rel.rental_division_id <> %d
				  AND pm2.meta_value = '1'
			";
			$sql_p = "
				SELECT product_rel.id
				FROM {$product_rel} product_rel
				LEFT JOIN {$wpdb->postmeta} pm  ON pm.post_id  = product_rel.id AND pm.meta_key  = '_stock_status'
				LEFT JOIN {$wpdb->postmeta} pm2 ON pm2.post_id = product_rel.id AND pm2.meta_key = '_rental_is_duplicate'
				WHERE pm.meta_value = 'instock'
				  AND product_rel.rental_division_id <> %d
				  AND pm2.meta_value = '1'
			";

			$v = $wpdb->get_col( $wpdb->prepare( $sql_v, $primary_division_id ) );
			$p = $wpdb->get_col( $wpdb->prepare( $sql_p, $primary_division_id ) );

			return array_merge( (array) $v, (array) $p );
		}

		/**
		 * Sets directly targeted by a type-2 inventory block, scoped to instock + division.
		 * Mirrors get_unavailable_*_with_blocked_inventory_items_filter() against set_relations
		 * so that blocked sets are excluded from listings, purchase and the cart.
		 *
		 * @param string $set_rel        Prefixed set_relations table name.
		 * @param string $ljm_set        Stock-status LEFT JOIN for set_rel.
		 * @param string $placeholders   "%d, %d, ..." for the blocked post ids.
		 * @param string $division_sql   Optional " AND rental_division_id = N " clause.
		 * @param int[]  $blocked_post_ids
		 * @return int[]
		 */
		private function blocked_set_ids( $set_rel, $ljm_set, $placeholders, $division_sql, $blocked_post_ids ) {
			global $wpdb;

			if ( empty( $blocked_post_ids ) ) {
				return array();
			}

			$sql = "
				SELECT id
				FROM {$set_rel} set_rel
				{$ljm_set}
				WHERE id IN ({$placeholders})
				  AND pm.meta_value = 'instock'
				  {$division_sql}
			";

			return (array) $wpdb->get_col( $wpdb->prepare( $sql, $blocked_post_ids ) );
		}

		/** @param mixed $ids @return int[] */
		private function normalize_ids( $ids ) {
			$ids = array_map( 'absint', (array) $ids );
			return array_values( array_unique( array_filter( $ids ) ) );
		}

		/* ----------------------------------------------------------------- *
		 * Listings (shop / category / tag / search / custom product queries)
		 * ----------------------------------------------------------------- */

		/**
		 * Exclude blocked ids from any front-end product query via raw SQL, so it cannot be
		 * overwritten by another plugin's post__in and works for custom queries too.
		 *
		 * @param string   $where
		 * @param WP_Query $query
		 * @return string
		 */
		public function filter_posts_where( $where, $query ) {
			global $wpdb;

			if ( ! ( $query instanceof WP_Query ) ) {
				return $where;
			}
			// Front-end page loads only; wp-admin and admin-ajax requests are skipped.
			if ( is_admin() ) {
				return $where;
			}
			if ( ! $this->query_targets_products( $query ) ) {
				return $where;
			}

			$hidden = $this->listing_hidden_ids(); // resolves blackout flag first.
			$ctx    = $this->get_context();

			if ( ! empty( $ctx['blackout'] ) ) {
				// During a full blackout, empty ONLY a real listing page render. Incidental
				// product lookups (related products, cross-sells, the date form's own queries)
				// and non-rendering AJAX requests such as the wc-ajax mini-cart fragment refresh
				// are left untouched, so they are never broken by the blackout. The date form is
				// still shown via the woocommerce_no_products_found hook so dates can be changed.
				if ( ! wp_doing_ajax() && $this->is_listing_context( $query ) ) {
					return $where . " AND {$wpdb->posts}.ID = 0 ";
				}
				return $where;
			}

			if ( ! empty( $hidden ) ) {
				$ids = implode( ',', array_map( 'absint', $hidden ) );
				if ( '' !== $ids ) {
					$where .= " AND {$wpdb->posts}.ID NOT IN ($ids) ";
				}
			}

			return $where;
		}

		/**
		 * Is this the MAIN query for an actual product listing page (shop archive, product
		 * taxonomy, or product search)? Used to scope the blackout "show nothing" clause so it
		 * never empties incidental product queries.
		 *
		 * @param WP_Query $query
		 * @return bool
		 */
		private function is_listing_context( $query ) {
			if ( ! $query->is_main_query() ) {
				return false;
			}
			if ( $query->is_post_type_archive( 'product' ) || $query->is_search() ) {
				return true;
			}
			$taxes = function_exists( 'get_object_taxonomies' )
				? get_object_taxonomies( 'product' )
				: array( 'product_cat', 'product_tag' );
			return ( ! empty( $taxes ) && $query->is_tax( $taxes ) );
		}

		/**
		 * Does this query target WooCommerce products?
		 *
		 * @param WP_Query $query
		 * @return bool
		 */
		private function query_targets_products( $query ) {
			$post_type = $query->get( 'post_type' );
			if ( ! empty( $post_type ) ) {
				$post_type = (array) $post_type;
				return ( count( array_intersect( $post_type, array( 'product', 'product_variation' ) ) ) > 0 );
			}

			if ( $query->is_post_type_archive( 'product' ) ) {
				return true;
			}

			$taxes = function_exists( 'get_object_taxonomies' )
				? get_object_taxonomies( 'product' )
				: array( 'product_cat', 'product_tag' );
			if ( ! empty( $taxes ) && $query->is_tax( $taxes ) ) {
				return true;
			}

			if ( $query->is_search() && $query->is_main_query() ) {
				return true;
			}

			return false;
		}

		/* ----------------------------------------------------------------- *
		 * Search (stemming/ordering only – blocking handled by posts_where)
		 * ----------------------------------------------------------------- */

		public function apply_stemmed_search( $q ) {
			if ( is_admin() || ! $q->is_main_query() || ! $q->is_search() ) {
				return;
			}

			$q->set( 'post_type', array( 'product', 'product_variation' ) );

			$search_term = $q->get( 's' );
			if ( '' === $search_term || ! function_exists( 'get_stemmed_search_result_ids' ) ) {
				return; // Let native search run; posts_where still strips blocked items.
			}

			$stemmed = get_stemmed_search_result_ids( $search_term );
			if ( empty( $stemmed ) ) {
				// No stemmed matches: fall back to WooCommerce's native search for this term.
				return;
			}

			$q->set( 'original_search_term', $search_term );
			$q->set( 's', '' );
			$q->set( 'orderby', 'post__in' );
			$q->set( 'post__in', array_map( 'absint', $stemmed ) );
			// Blocked items are removed by filter_posts_where (NOT IN), which composes with post__in.
		}

		public function store_original_search_term( $q ) {
			if ( $q->is_main_query() && $q->is_search() && ! is_admin() ) {
				add_filter(
					'get_search_query',
					function () use ( $q ) {
						$orig = $q->get( 'original_search_term' );
						return ( null !== $orig && '' !== $orig ) ? $orig : $q->get( 's' );
					}
				);
			}
		}

		/* ----------------------------------------------------------------- *
		 * Single product (server-side enforcement)
		 * ----------------------------------------------------------------- */

		/**
		 * Block add-to-cart for items unavailable on the selected dates. Membership test
		 * against the once-computed blocked set, so it adds no per-product queries.
		 *
		 * @param bool       $purchasable
		 * @param WC_Product $product
		 * @return bool
		 */
		public function filter_is_purchasable( $purchasable, $product ) {
			if ( is_admin() && ! wp_doing_ajax() ) {
				return $purchasable;
			}
			if ( ! $purchasable || ! is_object( $product ) ) {
				return $purchasable;
			}

			$ctx = $this->get_context();
			if ( ! $ctx['has_dates'] ) {
				return $purchasable; // No dates selected -> don't block (matches "select a date" UX).
			}

			$blocked = $this->availability_blocked_ids();
			$ctx     = $this->get_context();

			if ( ! empty( $ctx['blackout'] ) ) {
				return false;
			}

			return in_array( (int) $product->get_id(), $blocked, true ) ? false : $purchasable;
		}

		/* ----------------------------------------------------------------- *
		 * Removal logging (why an item was dropped from the cart)
		 * ----------------------------------------------------------------- */

		/**
		 * Observe (never modify) WooCommerce's per-item purchasability decision while it loads the
		 * cart from session. When the result is false, WooCommerce removes the item and shows the
		 * "...can no longer be purchased..." notice; we record the item and the reason so the cause
		 * is traceable in wc-logs/from-cart-removal-logs-YYYY-MM-DD.log.
		 *
		 * @param bool       $purchasable   Resolved purchasability (already includes our is_purchasable filter).
		 * @param string     $cart_item_key Cart item key.
		 * @param array      $cart_item     Cart item values.
		 * @param WC_Product $product       The product being validated.
		 * @return bool Unchanged $purchasable.
		 */
		public function log_cart_item_removal( $purchasable, $cart_item_key, $cart_item, $product ) {
			// Only items WooCommerce is about to drop are of interest here.
			if ( $purchasable || ! is_object( $product ) ) {
				return $purchasable;
			}

			$product_id = (int) $product->get_id();
			$ctx        = $this->get_context();

			// Work out whether THIS plugin's availability filter is the cause, and which kind.
			$blocked_by_rentopian = 0;
			$reason               = 'other_not_purchasable'; // e.g. trashed / out of stock / another plugin.

			if ( ! $ctx['has_dates'] ) {
				$reason = 'no_dates_selected';
			} else {
				$blocked = $this->availability_blocked_ids(); // resolves blackout flag (memoized).
				$ctx     = $this->get_context();

				if ( ! empty( $ctx['blackout'] ) ) {
					$blocked_by_rentopian = 1;
					$reason               = 'inventory_blackout';
				} elseif ( in_array( $product_id, $blocked, true ) ) {
					$blocked_by_rentopian = 1;
					$reason               = $this->is_inventory_blocked( $product_id ) ? 'inventory_block' : 'division_mismatch';
				}
			}

			$this->log_from_cart_removal(
				array(
					'event'                => 'CART_ITEM_NOT_PURCHASABLE',
					'reason'               => $reason,
					'blocked_by_rentopian' => $blocked_by_rentopian,
					'product_id'           => $product_id,
					'product_name'         => $product->get_name(),
					'is_set'               => get_post_meta( $product_id, '_rental_is_set', true ) ? 1 : 0,
					'cart_key'             => (string) $cart_item_key,
					'division_id'          => $ctx['division_id'],
					'start_date'           => $ctx['start'] ? gmdate( 'Y-m-d H:i', $ctx['start'] ) : '',
					'end_date'             => $ctx['end'] ? gmdate( 'Y-m-d H:i', $ctx['end'] ) : '',
				)
			);

			return $purchasable;
		}

		/**
		 * Write one structured key=value line to wc-logs/from-cart-removal-logs-YYYY-MM-DD.log
		 * through Project_WP_Logger (which enforces the path stays inside ABSPATH).
		 *
		 * @param array $context
		 * @return void
		 */
		private function log_from_cart_removal( array $context ) {
			if ( ! class_exists( 'Project_WP_Logger', false ) ) {
				return;
			}

			$upload = wp_upload_dir();
			if ( ! empty( $upload['error'] ) ) {
				return;
			}

			$target = trailingslashit( $upload['basedir'] ) . 'wc-logs/from-cart-removal-logs-' . gmdate( 'Y-m-d' ) . '.log';

			$parts = array();
			foreach ( $context as $k => $v ) {
				if ( is_array( $v ) || is_object( $v ) ) {
					$v = wp_json_encode( $v );
				} elseif ( is_bool( $v ) ) {
					$v = $v ? 'true' : 'false';
				}
				$parts[] = $k . '=' . str_replace( array( "\n", "\r", '|' ), array( ' ', ' ', '/' ), (string) $v );
			}

			Project_WP_Logger::write( implode( ' | ', $parts ), 'warning', 'from-cart-removal', $target );
		}

		/* ----------------------------------------------------------------- *
		 * Cart / checkout validation
		 * ----------------------------------------------------------------- */

		/**
		 * Flag any cart item that is not available for the currently selected dates, blocking
		 * checkout with a notice. Runs on cart and checkout, so a date change after an item was
		 * added is caught.
		 */
		public function validate_cart_items() {
			if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
				return;
			}

			$ctx = $this->get_context();
			if ( ! $ctx['has_dates'] ) {
				return; // Can't judge availability without dates.
			}

			$blocked = $this->availability_blocked_ids();
			$ctx     = $this->get_context();

			foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) {
				$check_id = ! empty( $cart_item['variation_id'] )
					? (int) $cart_item['variation_id']
					: (int) $cart_item['product_id'];

				$is_blocked = ! empty( $ctx['blackout'] ) || in_array( $check_id, $blocked, true );
				if ( ! $is_blocked ) {
					continue;
				}

				$product = isset( $cart_item['data'] ) ? $cart_item['data'] : null;
				$name    = ( $product && is_object( $product ) )
					? $product->get_name()
					: __( 'An item in your cart', 'rentopian-sync' );

				wc_add_notice(
					sprintf(
						/* translators: %s: product name */
						__( '%s is not available for your selected rental dates. Please update your dates or remove it to continue.', 'rentopian-sync' ),
						esc_html( $name )
					),
					'error'
				);

				// To auto-remove instead of just blocking checkout, uncomment:
				// WC()->cart->remove_cart_item( $cart_item_key );
			}
		}

		/* ----------------------------------------------------------------- *
		 * Public helper for the single-product availability AJAX check
		 * ----------------------------------------------------------------- */

		/**
		 * Availability code for one product or variation, using the JS contract:
		 *   1 = available, 0 = unavailable, 2 = no date selected.
		 *
		 * Used by the rental_check_product_availability AJAX handler so the product page shares
		 * the same blocked set as the listings, purchase guard and cart.
		 *
		 * @param int $post_id
		 * @return int
		 */
		public function item_availability_code( $post_id ) {
			$ctx = $this->get_context();
			if ( ! $ctx['has_dates'] ) {
				// In "dates on checkout" mode the date is chosen later, at checkout, so a
				// product with no date selected yet is still purchasable now.
				return $this->dates_deferred_to_checkout() ? 1 : 2;
			}

			$blocked = $this->availability_blocked_ids();
			$ctx     = $this->get_context();

			if ( ! empty( $ctx['blackout'] ) ) {
				return 0;
			}

			return in_array( (int) $post_id, $blocked, true ) ? 0 : 1;
		}

		/**
		 * Whether a product/variation is unavailable specifically because of an inventory block
		 * on the selected dates (a full blackout, or a block targeting this item) -- as opposed
		 * to a division/location mismatch or any other reason.
		 *
		 * @param int $post_id
		 * @return bool
		 */
		public function is_inventory_blocked( $post_id ) {
			$ctx = $this->get_context();
			if ( ! $ctx['has_dates'] ) {
				return false;
			}

			// Resolve the blocked sets and the blackout flag.
			$this->availability_blocked_ids();
			$ctx = $this->get_context();

			if ( ! empty( $ctx['blackout'] ) ) {
				return true;
			}

			$inventory = is_array( $this->inventory_blocked ) ? $this->inventory_blocked : array();
			return in_array( (int) $post_id, $inventory, true );
		}

		/* ----------------------------------------------------------------- *
		 * Single-product notice
		 * ----------------------------------------------------------------- */

		/**
		 * Output a message in the add-to-cart area when the current product is blocked by an
		 * inventory block on the selected dates. The add-to-cart button itself is already
		 * suppressed by the is_purchasable filter, so this replaces it with an explanation.
		 * Only inventory blocks trigger this message; division/location mismatches do not.
		 */
		public function inventory_blocked_notice() {
			if ( ! function_exists( 'is_product' ) || ! is_product() ) {
				return;
			}

			global $product;
			if ( ! is_object( $product ) ) {
				return;
			}

			if ( ! $this->is_inventory_blocked( $product->get_id() ) ) {
				return;
			}

			$message = esc_html__( 'The product cannot be purchased on the selected date(s).', 'rentopian-sync' );

			echo '<div class="woocommerce-info rntp-inventory-blocked-notice">' . $message . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
	}
}

/**
 * Bootstrap after WooCommerce is loaded.
 */
add_action(
	'plugins_loaded',
	function () {
		if ( class_exists( 'WooCommerce' ) ) {
			Rentopian_Availability_Filter::instance();
		}
	},
	20
);
