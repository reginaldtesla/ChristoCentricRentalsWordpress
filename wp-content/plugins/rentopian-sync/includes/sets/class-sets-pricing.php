<?php
/**
 * Rental_Sets_Pricing
 *
 * Implements the cart-side recalculation of a set parent line's
 * price when `_rental_item_based_total = 1`, mirroring the rule in the
 * admin's `changeSetPrice()` (sets_new2.js §18b lines 5295-5352).
 *
 * Pricing model (admin-side — sets_new2.js):
 *
 *   - When item_based_total = 0:
 *       totalPrice = `inventory_sets.rental_price` (set by admin)
 *
 *   - When item_based_total = 1:
 *       totalPrice = Σ over each top-level set-item ( (price + jobCost) * qty )
 *
 *     Where each top-level set-item contributes ONE term:
 *       - Simple item:    (item.price * item.quantity)
 *       - Selectable:     (chosen_variant_price * item.quantity)
 *       - Composite group: (group_price ?? default_inv_price) * group_quantity
 *                         contributes once, regardless of how many
 *                         children the customer selected from the group.
 *
 * The cart-side recalculation reads the actual cart lines (parent +
 * children) rather than the admin form. Note the cart side does NOT
 * re-apply `group_quantity` as a per-line billing multiplier: a group
 * child bills by its own picked units (per_set_quantity). On the cart
 * side `group_quantity` only seeds the product-page qty-stepper default
 * and travels as order/quote metadata (`rental_set_group_qty`).
 *
 * Hook: `woocommerce_before_calculate_totals` priority 20. We walk the
 * cart, identify each set parent line, and:
 *
 *   1. If item_based_total = 0 → re-read the set's `_regular_price`
 *      postmeta and set it as the parent line price. (Idempotent;
 *      protects against drift when WC recomputes.)
 *
 *   2. If item_based_total = 1 → walk the parent's children
 *      (`rental_add_on_of === parent_key`), compute the sum following
 *      the rule above, set the parent line price.
 *
 * Crucial: composite group children are de-duped by group_uid so a
 * group with N selected children still contributes ONE term to the
 * total, exactly as the admin JS expects.
 *
 * Children's own line prices are NOT modified — they keep their
 * per-line prices. The customer still sees individual costs in cart.
 * Only the parent's `set_subtotal` reflects the package total.
 *
 * @package RentopianSync\Sets
 * @since   2.14.7
 *
 * PHP requirement: 7.4+
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'Rental_Sets_Pricing', false ) ) :

class Rental_Sets_Pricing {

    /**
     * @var string
     */
    protected $log_source = 'rentopian-sets-sync';

    /**
     * @var self|null
     */
    protected static $instance = null;

    /**
     * Re-entry guard so our own price writes don't re-trigger the
     * `before_calculate_totals` hook recursively (WC won't fire again
     * within the same tick, but defensive nonetheless).
     *
     * @var bool
     */
    protected $recalculating = false;

    public static function register() {
        if ( null !== self::$instance ) {
            return;
        }
        self::$instance = new self();
        self::$instance->boot();
    }

    protected function boot() {
        // Priority 5 — BEFORE the legacy `calculate_cart_totals`
        // (registered at priority 10 in rentopian-sync.php). Order
        // matters: `calculate_cart_totals` reads the parent's CURRENT
        // `$cart_item['data']->get_price()`, ADDS each selected
        // product/set option's price to it (functions.php ~15086),
        // then writes the augmented value back via set_price(). If
        // this class ran AFTER it (the old priority 20), our
        // `parent_unit_price = 0` write would clobber the
        // option-augmented value.
        add_action( 'woocommerce_before_calculate_totals', array( $this, 'recalculate' ), 5, 1 );
    }

    /**
     * Walk the cart, recompute every set parent's line price.
     *
     * @param WC_Cart $cart
     * @return void
     */
    public function recalculate( $cart ) {
        if ( $this->recalculating ) {
            return;
        }
        if ( ! is_object( $cart ) || ! property_exists( $cart, 'cart_contents' ) ) {
            return;
        }

        $this->recalculating = true;

        try {
            // After the rewrite of compute_parent_price below, the
            // parent line's price only depends on the set's own
            // _regular_price × duration — we no longer need to index
            // children. Keeping the loop linear over cart_contents.
            foreach ( $cart->cart_contents as $key => $item ) {

                // Set PARENT line — fixed bundle bills `_regular_price ×
                // multiplier-adjusted duration`; aggregated bills 0.
                if ( $this->is_set_parent( $item ) ) {
                    $product_id = isset( $item['product_id'] ) ? (int) $item['product_id'] : 0;
                    if ( ! $product_id ) {
                        continue;
                    }
                    $price = $this->compute_parent_price( $product_id );
                    if ( null === $price ) {
                        continue;
                    }
                    if ( isset( $item['data'] ) && is_object( $item['data'] ) && method_exists( $item['data'], 'set_price' ) ) {
                        $item['data']->set_price( $price );
                        $cart->cart_contents[ $key ]['data'] = $item['data'];
                    }
                    continue;
                }

                // Set CHILD line — price to its effective per-unit rate ×
                // duration. For a fixed-bundle set this is $0 for plain
                // included items (CASE 3) and the real rate for variant
                // choices / addons (CASE 1/2). This MUST run here, ungated,
                // because the legacy `calculate_cart_totals` price writer is
                // gated behind the rental-date cookies — without dates it
                // never runs and WC falls back to the child product's own
                // WC price. Pricing the child here
                // keeps cart / mini-cart / checkout / order totals correct
                // in both modern and classic, with or without dates.
                if ( ! empty( $item['rental_add_on_of'] ) && function_exists( 'rental_set_child_effective_unit_price' ) ) {
                    $effective = rental_set_child_effective_unit_price( $item );
                    if ( null !== $effective ) {
                        $days  = function_exists( 'rental_get_days' ) ? (int) rental_get_days() : 1;
                        if ( $days < 1 ) {
                            $days = 1;
                        }
                        $child_price = (float) $effective * $days;
                        if ( isset( $item['data'] ) && is_object( $item['data'] ) && method_exists( $item['data'], 'set_price' ) ) {
                            $item['data']->set_price( $child_price );
                            $cart->cart_contents[ $key ]['data'] = $item['data'];
                        }
                    }
                }
            }
        } catch ( \Throwable $e ) {
            Project_WP_Logger::write(
                'Pricing: recalculate error: ' . $e->getMessage(),
                'error',
                $this->log_source
            );
        } finally {
            $this->recalculating = false;
        }
    }

    /**
     * Compute the parent line's per-unit price.
     *
     * Model (verified against Laravel's order calculator on
     * 2026-05-26): the parent set line ALWAYS contributes its own
     * intrinsic `_regular_price × rental_get_days()` to the cart
     * subtotal, regardless of `_rental_item_based_total`. Children
     * (set items + addons) contribute their own line subtotals
     * separately via the legacy `rental_calculate_cart_item_price`
     * path. Cart total = parent + Σ children.
     *
     * The previous implementation was wrong in two ways:
     *
     *   1. `item_based_total = 0` branch returned `_regular_price`
     *      with no duration multiplier — that's the $400 the user
     *      saw in the Subtotal column when the parent should show
     *      $400 × 2 days = $800.
     *
     *   2. `item_based_total = 1` branch summed children's prices
     *      and assigned the sum to the parent — which would have
     *      double-counted children in the cart total (parent line
     *      shows sum AND each child line shows its own price). The
     *      Laravel model has the parent contribute its own price
     *      independently; children are additive.
     *
     * Both bugs share the same fix: return `_regular_price × diff`
     * unconditionally, and let WC's standard subtotal mechanism
     * (line_subtotal = product_price × qty) carry the rest. The
     * cart total then naturally sums every line correctly.
     *
     * @param int $product_id  Set's WP product ID.
     * @return float|null  null when no price could be resolved.
     */
    protected function compute_parent_price( $product_id ) {
        // Single source of truth: the parent line per-unit price (incl.
        // duration) is computed by the price engine. This is the same
        // `_regular_price (or _price) × duration` rule that used to be
        // inlined here, now shared with the product-page display total so
        // the two can never drift.
        if ( class_exists( 'Rental_Sets_Price_Engine', false ) ) {
            return Rental_Sets_Price_Engine::parent_unit_price( (int) $product_id );
        }

        // Defensive fallback (engine not loaded — impossible in normal
        // flow since bootstrap requires it before this class).
        $regular = get_post_meta( $product_id, '_regular_price', true );
        if ( '' === $regular || null === $regular ) {
            $regular = get_post_meta( $product_id, '_price', true );
        }
        if ( '' === $regular || null === $regular ) {
            return null;
        }
        $diff = function_exists( 'rental_get_days' ) ? (int) rental_get_days() : 1;
        if ( $diff < 1 ) {
            $diff = 1;
        }
        return (float) $regular * $diff;
    }

    /**
     * Heuristic: is this cart line a set parent (not a child)?
     *
     * @param array $cart_item
     * @return bool
     */
    protected function is_set_parent( $cart_item ) {
        if ( ! empty( $cart_item['rental_add_on_of'] ) ) {
            return false;
        }
        $product_id = isset( $cart_item['product_id'] ) ? (int) $cart_item['product_id'] : 0;
        if ( ! $product_id ) {
            return false;
        }
        return (bool) get_post_meta( $product_id, '_rental_is_set', true );
    }
}

endif;
