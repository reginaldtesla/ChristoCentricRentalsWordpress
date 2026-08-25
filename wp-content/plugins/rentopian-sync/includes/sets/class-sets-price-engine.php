<?php
/**
 * Rental_Sets_Price_Engine
 *
 * The single, authoritative calculator for set pricing. It centralises
 * the three numbers that previously lived in scattered, drifting code
 * paths:
 *
 *   - the rental DURATION multiplier (days),
 *   - the PARENT line's per-unit price,
 *   - a CHILD line's effective per-unit price (the CASE 1/2/3 rules).
 *
 * It mirrors the Laravel order calculator and the spec in
 * docs/sets/PRICE-RULES.md. The cart, the checkout, and the product-page
 * display total all resolve their numbers HERE so they can never
 * disagree.
 *
 * The methods are deliberately pure and side-effect free (no cart
 * mutation, no postmeta writes) — callers decide what to do with the
 * numbers. This keeps the engine trivially testable and safe to call
 * from any context (cart hook, AJAX endpoint, REST).
 *
 * Cart-total composition (both pricing models, identical formula):
 *
 *   set_total = duration × parent_qty × parent_base
 *             + duration × Σ ( child_effective_unit × child_qty )
 *
 * For fixed_bundle_price sets the child term collapses to zero on its
 * own — child_unit_price() returns 0 for CASE-3 (informational) children
 * — so one formula serves both models without branching at the call site.
 * The exception is a child flagged `separate_price`: it keeps its own
 * rate in the child term and is billed on top of the fixed bundle fee.
 *
 * @package RentopianSync\Sets
 * @since   2.15.0
 *
 * PHP requirement: 7.4+
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'Rental_Sets_Price_Engine', false ) ) :

class Rental_Sets_Price_Engine {

    /**
     * Rental-duration multiplier in days. Single source of truth shared
     * by parent and child pricing so they always use the same period.
     * Clamps to a minimum of 1 (the no-period default shown before the
     * customer has chosen rental dates).
     *
     * @return int
     */
    public static function duration() {
        $days = function_exists( 'rental_get_days' ) ? (int) rental_get_days() : 1;
        return $days < 1 ? 1 : $days;
    }

    /**
     * PARENT line per-unit price, INCLUDING the duration multiplier.
     *
     * Pricing model (the cart-total contribution depends on
     * `_rental_item_based_total`):
     *
     *   aggregated_children (`_rental_item_based_total = 1`)
     *     → returns 0. The parent line itself bills nothing; the cart
     *       total is the sum of children's effective prices. The "Price"
     *       column on the parent row therefore reads $0.00 and the line
     *       subtotal is $0.
     *
     *   fixed_bundle_price (`_rental_item_based_total = 0`)
     *     → returns `_regular_price × duration`. The parent bills its
     *       own fixed bundle price; children are informational
     *       (child_unit_price returns 0 for CASE-3 children).
     *
     * The change from previous behavior (parent always contributed its
     * `_regular_price × duration` regardless of model) was made on
     * 2026-06-05 at customer request — see PRICE-RULES.md §1 and §4.
     * For aggregated sets, the parent's own `_regular_price` is now
     * treated as advisory metadata only; the cart total is driven
     * exclusively by the children's effective prices.
     *
     * `_price` is a fallback for older sync builds that wrote only one
     * of the two meta keys (fixed_bundle branch only — irrelevant for
     * aggregated since we always return 0 there).
     *
     * @param int $set_wp_id  Set's WP product id.
     * @return float|null  null when no price meta could be resolved
     *                    (fixed_bundle only). Aggregated always returns
     *                    0.0 (not null).
     */
    public static function parent_unit_price( $set_wp_id ) {
        $set_wp_id = (int) $set_wp_id;
        if ( $set_wp_id <= 0 ) {
            return null;
        }

        // Aggregated → parent contributes nothing; children carry the
        // cart total via child_unit_price.
        $item_based_total = (bool) get_post_meta( $set_wp_id, '_rental_item_based_total', true );
        if ( $item_based_total ) {
            return 0.0;
        }

        $regular = get_post_meta( $set_wp_id, '_regular_price', true );
        if ( '' === $regular || null === $regular ) {
            $regular = get_post_meta( $set_wp_id, '_price', true );
        }
        if ( '' === $regular || null === $regular ) {
            return null;
        }

        // Apply the set's price_multiplier (tiered duration coefficient,
        // matching classic). Classic non-set products get this via the
        // cart-item-price path; set parents route through this engine and
        // would otherwise skip it. Falls back to the plain duration when
        // the set has no multiplier configured (the helper no-ops).
        $days = ( function_exists( 'rental_get_multiplier_adjusted_days' ) )
            ? (float) rental_get_multiplier_adjusted_days( $set_wp_id, self::duration() )
            : self::duration();

        return (float) $regular * $days;
    }

    /**
     * CHILD line effective per-unit price, BEFORE the duration multiplier.
     *
     * This is the authoritative encoding of the set price-DISPLAY rules
     * (PRICE-RULES.md §2). It is the extracted core of
     * rental_set_child_effective_unit_price() — that function now resolves
     * the four scalars below from a cart item and delegates here so the
     * cart Price column, the line Subtotal, and the product-page display
     * total all share one implementation.
     *
     *   CASE 1 — item_based_total = TRUE  → real price (every child bills).
     *   CASE 2 — item_based_total = FALSE, child is variant-based
     *            (has a variation_id) OR carries a non-zero stored price
     *            → real price (the customer's choice affects cost).
     *   CASE 3 — item_based_total = FALSE, fixed/non-variable child
     *            → 0.0 (informational component; parent bundle bills).
     *
     * Resolution order is `_regular_price`-FIRST (via
     * rental_resolve_variant_price). `$stored_price` — the value the
     * legacy AJAX bridge stamps onto `_rental_set_items[i]['price']` and
     * which then rides on the cart line as `rental_add_on_price` — is
     * used ONLY as a last-resort fallback when the variant has no
     * resolvable rate at all. Why: the set product page (section
     * presenter) also reads the `_regular_price`-first chain to label
     * each option, and the user spec requires "the same pricing engine
     * and pricing source are used for display and calculation". Letting
     * a sale-price-leaked `stored_price` win here re-opens the page-vs-
     * cart divergence (e.g. variant `_regular_price=320` but
     * `stored_price=250` → page shows $320, cart $250). With this order
     * page === cart by construction.
     *
     * Composite-group override (added 2026-06-05). When the child belongs
     * to a composite group that carries a non-empty `group_price`, that
     * value replaces the variant-chain lookup for every option in the
     * group — including a literal `0`, which means "free as a member of
     * this group". An EMPTY group_price ('' / null) means "no override —
     * fall back to per-item variant prices", matching the admin contract
     * the user requested: "if rental_price_final is empty we consider
     * each item price; if it had value or 0 we will consider the new
     * price for each item in one group".
     *
     * Resolution priority (revised 2026-06-06 per customer spec):
     *
     *   1. Composite-group `group_price` override — when set on the
     *      group (any numeric value, including 0), every option in
     *      that group bills at that rate.
     *   2. **Set-level override (`stored_price`)** — when positive.
     *      This is `inventory_sets_relation.price` on the Laravel
     *      side, stamped onto the cart line at add-time as
     *      `rental_add_on_price`. The admin uses it to say "this
     *      product, IN THIS SET, bills at this rate" — distinct from
     *      its standalone product price. Wins over the live variant
     *      chain by design: the override IS the in-set price.
     *   3. Live variant chain (`_regular_price`-first via
     *      `rental_resolve_variant_price`) — the fallback when the
     *      admin did NOT configure a set-level override. Reads from
     *      the WP product's current postmeta.
     *   4. `0` — last resort when nothing resolves.
     *
     * Applies uniformly to every child type — addons, selectables,
     * simples, and group children. The previous variant-chain-first
     * order was reverted because it ignored the in-set override that
     * customers configure intentionally per (product, set) pair.
     *
     * CASE 3 gate is preserved as the entry condition: a non-variant
     * child in a fixed_bundle set with no stored_price stays
     * informational (returns 0).
     *
     * @param int        $set_wp_id     Set's WP product id (>0 required).
     * @param int        $product_id    Child product id.
     * @param int        $variation_id  Child variation id (0 when none).
     * @param float      $stored_price  In-set override price stamped on the
     *                                  line at add-time
     *                                  (rental_add_on_price). 0 / negative
     *                                  is treated as "no override".
     * @param mixed|null $group_price   Composite-group's `group_price` field
     *                                  when this child belongs to a group.
     *                                  Pass `null` (or omit) for non-group
     *                                  children. EMPTY string means "no
     *                                  override"; numeric (including 0)
     *                                  means "use this rate for all group
     *                                  options".
     * @param bool       $is_addon      Reserved for backward-compatible
     *                                  callers. With the unified
     *                                  stored_price-first rule this flag
     *                                  is no longer needed — addons get
     *                                  the same priority chain as every
     *                                  other child. Kept in the signature
     *                                  so existing call sites compile;
     *                                  the value is ignored.
     * @param bool       $separate_price When true, the child bills its own
     *                                  price even in a fixed_bundle set —
     *                                  it is priced separately and ADDED on
     *                                  top of the bundle fee instead of
     *                                  being folded into it. This overrides
     *                                  the CASE-3 gate for an included item
     *                                  (simple / selectable / group) that
     *                                  would otherwise read $0.00. The rate
     *                                  still resolves through the normal
     *                                  priority chain (in-set stored price
     *                                  first, then the live variant chain).
     * @param bool       $is_group_child True when the line is a composite-group
     *                                  option. Keeps the simple-item lookup
     *                                  (which matches on product id alone)
     *                                  from claiming a group option whose
     *                                  product also appears as a simple item
     *                                  in the same set.
     * @return float  Effective per-unit price (0.0 for CASE 3 or unresolved).
     */
    public static function child_unit_price( $set_wp_id, $product_id, $variation_id, $stored_price, $group_price = null, $is_addon = false, $separate_price = false, $is_group_child = false ) {
        $set_wp_id      = (int) $set_wp_id;
        $product_id     = (int) $product_id;
        $variation_id   = (int) $variation_id;
        $stored_price   = (float) $stored_price;
        $is_addon       = (bool) $is_addon;
        $separate_price = (bool) $separate_price;
        $is_group_child = (bool) $is_group_child;

        $item_based_total = (bool) get_post_meta( $set_wp_id, '_rental_item_based_total', true );

        // A SIMPLE set item (entity-1) carries no customer variant CHOICE
        // even when it points to a specific variation, so it must not count
        // as "variant-based": in a fixed bundle it stays informational
        // (CASE 3, $0) unless flagged separate_price. Only genuine
        // selectables (entity-2) and addons are variant-based.
        // is_simple_set_item() matches on product id alone, so addons and
        // group children — which can point at a product that ALSO appears
        // as a simple item in the same set — are excluded by their own
        // flag rather than by the lookup.
        $is_simple_item   = ! $is_addon && ! $is_group_child && self::is_simple_set_item( $set_wp_id, $product_id );
        $is_variant_based = ( $variation_id > 0 ) && ! $is_simple_item;

        // 1. Composite-group override.
        if ( null !== $group_price && '' !== $group_price && is_numeric( $group_price ) ) {
            return (float) $group_price;
        }

        // CASE 3 gate — what CONTRIBUTES to a fixed_bundle set's total:
        //
        //   - item_based_total = 1 → every child bills (CASE 1).
        //   - variant-based child — a genuine customer CHOICE among
        //     options (selectable / entity-2) → bills; the choice affects
        //     cost (CASE 2). A simple item that merely points to a
        //     variation is NOT a choice and does NOT qualify (see
        //     $is_simple_item above).
        //   - ADDON with a positive in-set price → bills; addons are the
        //     "explicitly configured chargeable extras" (upgrades /
        //     surcharges) that ride on top of the bundle.
        //
        // A plain INCLUDED / optional simple set item in a fixed_bundle set
        // is informational and bills $0 — EVEN when it carries an in-set
        // price or points to a variation. The fixed bundle price IS the
        // total; the included contents are listed at $0.
        //
        // EXCEPTION — separate_price. When the item is flagged to be priced
        // separately, it bills its own rate ON TOP of the fixed bundle fee
        // regardless of type or variant state. This is the admin's explicit
        // "charge this item in addition to the bundle" instruction.
        $contributes = $item_based_total
            || $is_variant_based
            || ( $stored_price > 0 && $is_addon )
            || $separate_price;
        if ( ! $contributes ) {
            return 0.0;
        }

        // 2. Set-level override — the in-set price stamped from
        //    inventory_sets_relation.price.
        if ( $stored_price > 0 ) {
            return $stored_price;
        }

        // 3. Live variant chain fallback.
        $lookup_id = $variation_id ?: $product_id;
        $resolved  = (float) rental_resolve_variant_price( $lookup_id, $product_id );
        if ( $resolved > 0 ) {
            return $resolved;
        }

        // 4. Unresolved.
        return 0.0;
    }

    /**
     * Whether a product is a SIMPLE (entity-1) item in the set definition —
     * a top-level `_rental_set_items` entry with no `optional_items`.
     *
     * A simple item is an included/optional bundle component the customer
     * cannot make a variant CHOICE on (even when it points to a specific
     * variation), so it is informational in a fixed bundle (CASE 3) and
     * must not be treated as "variant-based".
     *
     * Returns false when the product is not a top-level item (group child,
     * addon) or is a selectable (has `optional_items`), leaving those
     * types' pricing unchanged.
     *
     * @param int $set_wp_id   Set's WP product id.
     * @param int $product_id  Child product id (the set-item product, which
     *                         for a selectable is the wrapper product).
     * @return bool
     */
    public static function is_simple_set_item( $set_wp_id, $product_id ) {
        $set_wp_id  = (int) $set_wp_id;
        $product_id = (int) $product_id;
        if ( $set_wp_id <= 0 || $product_id <= 0 ) {
            return false;
        }

        $items = get_post_meta( $set_wp_id, '_rental_set_items', true );
        if ( ! is_array( $items ) ) {
            return false;
        }

        foreach ( $items as $item ) {
            if ( ! is_array( $item ) ) {
                continue;
            }
            if ( (int) ( $item['product_id'] ?? 0 ) === $product_id ) {
                return empty( $item['optional_items'] );
            }
        }

        return false;
    }

    /**
     * Single source of truth for a SET CHILD cart line's effective per-unit
     * price (BEFORE the rental-duration multiplier). Resolves the cart-item
     * scalars and delegates the CASE 1/2/3/4 math to child_unit_price(), so
     * the cart Price column, the line Subtotal, and the product-page total
     * all share one implementation.
     *
     * Returns:
     *   - float  the effective per-unit price (0.0 for CASE 3)
     *   - null   when this is NOT a set child (caller uses its own default —
     *            a plain product addon outside a set, or the parent line)
     *
     * @param array $cart_item
     * @return float|null
     */
    public static function effective_child_unit_price( $cart_item ) {
        if ( empty( $cart_item['rental_add_on_of'] ) ) {
            return null; // not a child line
        }

        // `set_id` on a child cart line is the WP set POST id. A child with
        // no set context is a plain product addon — leave it to the default.
        $set_wp_id = isset( $cart_item['set_id'] ) ? (int) $cart_item['set_id'] : 0;
        if ( $set_wp_id <= 0 ) {
            return null;
        }

        // Composite-group override: a child carrying a namespaced
        // `rental_set_group_price` bills at that group rate. Empty / null =
        // no override; a literal 0 = free as a group member.
        $group_price    = null;
        $is_group_child = false;
        if ( class_exists( 'Rental_Sets_Cart_Meta', false ) ) {
            $group_price    = Rental_Sets_Cart_Meta::read( $cart_item, Rental_Sets_Cart_Meta::KEY_GROUP_PRICE, null );
            $is_group_child = Rental_Sets_Cart_Meta::is_group_child( $cart_item );
        }

        // Addon discriminator — only addons carry parent_set_item_product_id.
        $is_addon = ! empty( $cart_item['parent_set_item_product_id'] );

        // Separately-priced child — bills on top of a fixed bundle even when
        // it's a non-variant included item (stamped at add-time).
        $separate_price = ! empty( $cart_item['separate_price'] );

        return self::child_unit_price(
            $set_wp_id,
            isset( $cart_item['product_id'] )   ? (int) $cart_item['product_id']   : 0,
            isset( $cart_item['variation_id'] ) ? (int) $cart_item['variation_id'] : 0,
            isset( $cart_item['rental_add_on_price'] ) ? (float) $cart_item['rental_add_on_price'] : 0.0,
            $group_price,
            $is_addon,
            $separate_price,
            $is_group_child
        );
    }
}

endif;
