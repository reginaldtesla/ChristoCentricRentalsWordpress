<?php
/**
 * Rental_Sets_Cart_Reconciler
 *
 * Fixes the stale-child problem documented in WORKFLOWS.md §10.1 and
 * the same-pair-duplicate problem implicit in §10.
 *
 * Background. When a customer re-adds the same set with a different
 * child selection, three things can happen depending on whether the
 * parent's hash matches the existing parent line:
 *
 *   A. Hash matches (same options stash) → WC natively merges the
 *      parent; children added in this submission attach to the same
 *      parent key. WC's own child cart-id hashing merges same-pair
 *      children automatically. Old children whose pairs are absent
 *      from the new submission still persist — that's the stale-child
 *      bug.
 *
 *   B. Hash differs (different options stash) → WC creates a new parent
 *      line, the cart-merger collapses it into the existing parent and
 *      reparents the new children. The new children retain their
 *      original cart_item_key (which was computed against the dropped
 *      parent's key), so they DO NOT collide with the existing same-
 *      pair children. Two lines for one logical (group_uid, item_uid)
 *      pair appear under one parent — that's the same-pair-duplicate
 *      bug. Stale children also persist.
 *
 *   C. Edit-mode submit → Cart_Edit_Mode removes the entire old parent
 *      and its children, leaving only the new parent + new children.
 *      Clean by construction; reconciler is a defensive no-op.
 *
 * Strategy. After every set-parent add-to-cart event has finished, we
 * treat the current submission's `rental_add_ons[]` POST array as the
 * authoritative target for what children should exist under that parent.
 * Every child — selectable, simple, addon, or composite-group — is
 * reduced to a stable IDENTITY (group → "grp:{group_uid}:{item_uid}",
 * addon → "ao:{parent_product_id}:{product_id}:{variant_id}", else
 * "pv:{product_id}:{variant_id}") so the new submission can be diffed
 * against the existing cart. The reconciler then performs two passes
 * against the parent's current children:
 *
 *   1. **Drop identities not in the submission.** Any child whose
 *      identity isn't present in the POST submission is treated as stale
 *      and removed. This is what makes a CHANGED selectable variant (Baby
 *      bike Orange → Yellow) drop its old line instead of leaving a
 *      duplicate. The customer's last submission is the truth.
 *
 *   2. **Dedup duplicate identities.** Multiple lines sharing one
 *      identity collapse to the first line; the rest are removed. No
 *      quantity summing happens here — the canonical quantity is
 *      re-applied immediately afterward by normalize_children_qty()
 *      (child = parent_qty × per_set_qty). Splitting "WHICH children
 *      exist" (this class) from "HOW MANY of each" (the normalizer) is
 *      what keeps reconfigure/re-add correct.
 *
 * Hook order:
 *
 *   - priority  10 → legacy `rental_add_product_to_cart` (adds children)
 *   - priority  20 → option-transfer
 *   - priority  99 → Cart_Merger::collapse_parent_duplicates
 *   - priority 100 → Cart_Edit_Mode::finalize_replacement
 *   - priority 105 → THIS class                       ← final cleanup
 *
 * Running last means whichever parent identity wins (natural-merge,
 * merger-collapse, edit-replace, or fresh add), we always reconcile
 * against the same authoritative POST snapshot.
 *
 * Safety. The stale-removal pass stands down entirely (no child is ever
 * removed) when:
 *
 *   - The submission lacks `_rental_set_from_product_page` — the marker
 *     emitted by single-product-modern.php. This scopes removal to
 *     modern set product-page submits; classic / REST / AJAX /
 *     programmatic adds never trigger it.
 *   - The submission carries no `rental_add_ons` (e.g., a JS error that
 *     shipped an empty payload). Without an authoritative target,
 *     removing children would be destructive.
 *
 * Quantity normalization (normalize_children_qty) is NOT gated by the
 * above — it runs unconditionally for every set parent, and also on
 * `woocommerce_cart_updated` as a settled-state safety net (priority 30)
 * to catch any timing race where the add-time pass ran before the parent
 * reached its final merged quantity.
 *
 * This keeps the reconciler invisible to every classic flow.
 *
 * @package RentopianSync\Sets
 * @since   2.14.7
 *
 * PHP requirement: 7.4+
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'Rental_Sets_Cart_Reconciler', false ) ) :

class Rental_Sets_Cart_Reconciler {

    /**
     * @var string
     */
    protected static $log_source = 'rentopian-sets-sync';

    /**
     * Per-request guard. Prevents the reconciler from running twice for
     * the same parent line when more than one parent event fires in a
     * single request (rare, but possible with bundled / composited adds).
     *
     * @var array<string,bool>
     */
    protected static $handled = array();

    /**
     * Re-entry guard for the settled-state normalize pass that runs on
     * `woocommerce_cart_updated`. set_quantity() can re-trigger cart
     * recalculation, so without this the pass could recurse.
     *
     * @var bool
     */
    protected static $normalizing = false;

    /**
     * Hook registration. Called from bootstrap.
     *
     * @return void
     */
    public static function register() {
        add_action( 'woocommerce_add_to_cart', array( __CLASS__, 'on_add_to_cart' ), 105, 6 );
        // Settled-state safety net: after every cart mutation has fully
        // applied (merges, qty changes, removals), re-assert the
        // child=parent×per_set invariant for every set in the cart. This
        // catches any timing race where the add-time normalize ran before
        // the parent quantity reached its final value. Priority 30 keeps
        // us after the legacy orphan-cleanup
        // (rental_validate_and_update_add_on_quantity_in_cart @ 10).
        add_action( 'woocommerce_cart_updated', array( __CLASS__, 'on_cart_updated' ), 30 );

        // PRE-TOTALS normalize. Three downstream consumers all read
        // `$cart_item['quantity']` directly during this hook to compute
        // subtotals/fragments, and they all fire BEFORE the
        // `woocommerce_cart_updated` normalize above had a chance to run:
        //
        //   priority 10 — `rental_change_cart_item_price` →
        //                 `calculate_cart_totals` (functions.php:14860)
        //                 caches `rental_product_subtotal` via
        //                 `$subtotal += $cart_item['quantity'] * $price`
        //                 at line 15094.
        //
        //   priority 20 — `Rental_Sets_Pricing::recalculate` sets the
        //                 parent's runtime product price via
        //                 `$item['data']->set_price()`.
        //
        //   priority — WC core then computes each line's `line_subtotal`
        //              as `quantity × price` and writes it into
        //              `cart_contents[$key]['line_subtotal']`.
        //
        // After an edit-mode add, the new children are stamped at qty 1
        // (the per-set quantity) and the parent at qty N (the customer's
        // original choice). Until priority 30 normalize runs, every
        // consumer above sees the child at qty 1 — producing:
        //
        //   $subtotal = parent.line_subtotal + child.line_subtotal_at_qty_1
        //
        // which is the "$520 cart subtotal" + "$1 child in mini-cart"
        // symptom: the line_subtotal stamp + the cached subtotal carry
        // qty 1 onwards even after the cart page later renders qty 4
        // (which it reads live from cart_contents['quantity']).
        //
        // Running normalize at priority 1 means every consumer at priority
        // 10+ sees the post-normalize state. The hook is idempotent
        // (re-entry-guarded by `self::$normalizing`) so re-firing it at
        // each totals-recompute is cheap.
        add_action( 'woocommerce_before_calculate_totals', array( __CLASS__, 'on_cart_updated' ), 1 );
    }

    /**
     * `woocommerce_add_to_cart` callback. Runs once per cart-item-add,
     * including the (recursive) child adds the legacy
     * `rental_add_product_to_cart` triggers — we short-circuit those by
     * skipping any line that carries `rental_add_on_of`.
     *
     * @param string $cart_item_key
     * @param int    $product_id
     * @param int    $quantity
     * @param int    $variation_id
     * @param array  $variation
     * @param array  $cart_item_data
     * @return void
     */
    public static function on_add_to_cart( $cart_item_key, $product_id, $quantity, $variation_id, $variation, $cart_item_data ) {

        if ( ! function_exists( 'WC' ) || ! WC() || ! WC()->cart ) {
            return;
        }

        // Skip child events — only the parent add triggers reconciliation.
        if ( ! empty( $cart_item_data['rental_add_on_of'] ) ) {
            return;
        }

        // Skip non-set events. We only own set parents.
        if ( ! get_post_meta( (int) $product_id, '_rental_is_set', true ) ) {
            return;
        }

        // Resolve the parent that survived this submission. After the
        // merger collapse, $cart_item_key may have been dropped — in
        // which case we look up the surviving line by product/variation.
        $parent_key = self::resolve_surviving_parent_key(
            $cart_item_key,
            (int) $product_id,
            (int) $variation_id
        );

        if ( null === $parent_key ) {
            return;
        }

        if ( isset( self::$handled[ $parent_key ] ) ) {
            return;
        }
        self::$handled[ $parent_key ] = true;

        // 1. Stale-child reconciliation. GATED on the submission being a
        //    modern set product-page add (`_rental_set_from_product_page`)
        //    so classic / non-modern adds never lose state. When active it
        //    reconciles ALL child types — selectable, simple, addon AND
        //    composite-group — against the submitted set of children, so a
        //    changed selectable variant (e.g. Baby bike Orange → Yellow)
        //    drops the old line instead of leaving a duplicate.
        if ( self::should_reconcile() ) {
            $intended = self::collect_submission_identities();
            $result   = self::reconcile_children_of( $parent_key, $intended );

            if ( class_exists( 'Rental_Sets_Logger', false ) ) {
                Rental_Sets_Logger::reconciler( $parent_key, array(
                    'removed'      => count( $result['removed'] ),
                    'merged'       => count( $result['merged'] ),
                    'intended'     => count( $intended ),
                    'intended_ids' => implode( ',', array_keys( $intended ) ),
                    'removed_ids'  => implode( ',', isset( $result['removed_ids'] ) ? $result['removed_ids'] : array() ),
                    'skipped_ids'  => implode( ',', isset( $result['skipped_ids'] ) ? $result['skipped_ids'] : array() ),
                ) );
            }
        }

        // 2. Quantity normalization — runs for EVERY set parent add,
        //    regardless of entity type. Enforces the cart-wide invariant:
        //
        //        child_qty = parent_qty × per_set_qty
        //
        //    This is what makes reconfiguration / re-add correct. When a
        //    customer changes a selectable variant and re-adds, the new
        //    child line lands at its add-time qty (per_set_qty, usually
        //    1) while the parent has already been collapsed by the merger
        //    to the existing quantity (e.g. 3). Without this pass the new
        //    child would sit at 1 next to a parent of 3. Snapping every
        //    child to parent_qty × per_set_qty restores the invariant the
        //    increase/decrease handlers already maintain.
        self::normalize_children_qty( $parent_key );
    }

    /**
     * Force every child line of a set parent to the canonical quantity
     * `parent_qty × per_set_qty`. This is the single invariant that keeps
     * children consistent with the parent across ALL cart mutations —
     * add, increase, decrease, reconfigure, edit-replace.
     *
     * `per_set_qty` is read from the child's `rental_per_set_quantity`
     * cart-meta (stamped at add-to-cart time). Missing / zero falls back
     * to 1 so a child never drops out.
     *
     * Uses set_quantity with refresh_totals=false to batch; the final
     * cart recalculation runs once after the add-to-cart action chain.
     * Setting a CHILD's quantity fires woocommerce_after_cart_item_quantity_update
     * for that child, which the classic handler treats as a leaf (no
     * grandchildren) — so there's no cascade and no recursion back into
     * this method (the per-request `$handled` guard also protects it).
     *
     * @param string $parent_key
     * @return int Number of child lines whose quantity was changed.
     */
    public static function normalize_children_qty( $parent_key ) {
        if ( ! function_exists( 'WC' ) || ! WC() || ! WC()->cart ) {
            return 0;
        }
        $contents = WC()->cart->cart_contents;
        if ( ! isset( $contents[ $parent_key ] ) ) {
            return 0;
        }

        $parent_qty = (int) $contents[ $parent_key ]['quantity'];
        if ( $parent_qty < 1 ) {
            return 0;
        }

        $changed = 0;
        $details = array();
        foreach ( $contents as $key => $item ) {
            if ( ! isset( $item['rental_add_on_of'] ) || $item['rental_add_on_of'] !== $parent_key ) {
                continue;
            }
            $per_set = isset( $item['rental_per_set_quantity'] ) && (int) $item['rental_per_set_quantity'] > 0
                ? (int) $item['rental_per_set_quantity']
                : 1;
            // Static quantity (Laravel `static_quantity`):
            //   1 → "Singular regardless of parent quantity": the child's
            //       qty is the configured per_set value, fixed — it does
            //       NOT scale with the parent quantity.
            //   0 → "Per 1 parent product": child qty = parent_qty * per_set.
            $is_static = ! empty( $item['rental_static_quantity'] );
            $from      = (int) $item['quantity'];
            $target    = $is_static ? $per_set : ( $parent_qty * $per_set );
            if ( $from !== $target ) {
                WC()->cart->set_quantity( $key, $target, false );
                $changed++;
                $details[] = sprintf(
                    '%s %s%d %d->%d',
                    substr( (string) $key, 0, 6 ),
                    $is_static ? 'static x' : ( $parent_qty . 'x' ),
                    $per_set,
                    $from,
                    $target
                );
            }
        }

        if ( $changed > 0 && class_exists( 'Rental_Sets_Logger', false ) ) {
            Rental_Sets_Logger::warn(
                'cart_qty_normalize',
                sprintf(
                    'parent %s qty=%d synced %d child line(s): %s',
                    substr( (string) $parent_key, 0, 6 ),
                    $parent_qty,
                    $changed,
                    implode( ', ', $details )
                ),
                array()
            );
        }

        return $changed;
    }

    /**
     * Detect whether the current request should trigger reconciliation.
     *
     * Gated on the modern set product-page submission marker
     * (`_rental_set_from_product_page`, emitted by single-product-modern.php)
     * plus a non-empty `rental_add_ons`. This scopes reconciliation to
     * modern set adds and never touches classic / non-modern carts (which
     * lack the marker). Requiring a non-empty submission avoids wiping a
     * set's children if a JS error shipped an empty payload.
     *
     * @return bool
     */
    public static function should_reconcile() {
        if ( empty( $_POST['_rental_set_from_product_page'] ) ) {
            return false;
        }
        if ( empty( $_POST['rental_add_ons'] ) || ! is_array( $_POST['rental_add_ons'] ) ) {
            return false;
        }
        return true;
    }

    /**
     * Build the set of intended child IDENTITIES from the current
     * submission. An identity uniquely names "the same child" across the
     * old cart state and the new submission so we can tell which existing
     * children are stale:
     *
     *   - composite-group child → "grp:{group_uid}:{item_uid}"
     *   - set-item addon        → "ao:{parent_product_id}:{product_id}:{variant_id}"
     *   - selectable / simple   → "pv:{product_id}:{variant_id}"
     *
     * Group children keep their uid pair because the same product+variant
     * can legitimately appear in two different groups. Addons keep their
     * host item's product id for the same reason: one product can be an
     * addon on one line and an ordinary set line on another, and the two
     * must stay independent lines rather than collapse into one.
     *
     * @return array<string,true>
     */
    public static function collect_submission_identities() {
        $intended = array();
        if ( empty( $_POST['rental_add_ons'] ) || ! is_array( $_POST['rental_add_ons'] ) ) {
            return $intended;
        }
        foreach ( $_POST['rental_add_ons'] as $add_on ) {
            $id = self::submission_identity( $add_on );
            if ( '' !== $id ) {
                $intended[ $id ] = true;
            }
        }
        return $intended;
    }

    /**
     * Identity for a submission `rental_add_ons[i]` entry.
     *
     * @param mixed $add_on
     * @return string '' when the entry can't be identified.
     */
    protected static function submission_identity( $add_on ) {
        if ( ! is_array( $add_on ) ) {
            return '';
        }
        $is_group = ! empty( $add_on['rental_set_group_is_child'] )
            || ( isset( $add_on['item_type'] ) && 'grouped_child' === $add_on['item_type'] );
        if ( $is_group ) {
            $gid = isset( $add_on['rental_set_group_uid'] ) ? (string) $add_on['rental_set_group_uid'] : '';
            $iid = isset( $add_on['rental_set_group_item_uid'] ) ? (string) $add_on['rental_set_group_item_uid'] : '';
            if ( '' === $gid || '' === $iid ) {
                return '';
            }
            return 'grp:' . $gid . ':' . $iid;
        }
        $pid = isset( $add_on['product_id'] ) ? (int) $add_on['product_id'] : 0;
        $vid = isset( $add_on['variant_id'] ) ? (int) $add_on['variant_id'] : 0;
        if ( ! $pid ) {
            return '';
        }
        $parent = isset( $add_on['parent_set_item_product_id'] ) ? (int) $add_on['parent_set_item_product_id'] : 0;
        if ( $parent > 0 ) {
            return 'ao:' . $parent . ':' . $pid . ':' . $vid;
        }
        return 'pv:' . $pid . ':' . $vid;
    }

    /**
     * Identity for an existing cart child line. Mirrors
     * submission_identity() so the two can be compared.
     *
     * @param array $item
     * @return string '' when the line can't be identified.
     */
    protected static function cart_item_identity( $item ) {
        if ( self::is_group_child_safe( $item ) ) {
            $gid = self::read_meta( $item, Rental_Sets_Cart_Meta::KEY_GROUP_UID );
            $iid = self::read_meta( $item, Rental_Sets_Cart_Meta::KEY_GROUP_ITEM_UID );
            if ( '' === $gid || '' === $iid ) {
                return '';
            }
            return 'grp:' . $gid . ':' . $iid;
        }
        $pid = isset( $item['product_id'] ) ? (int) $item['product_id'] : 0;
        $vid = isset( $item['variation_id'] ) ? (int) $item['variation_id'] : 0;
        if ( ! $pid ) {
            return '';
        }
        $parent = isset( $item['parent_set_item_product_id'] ) ? (int) $item['parent_set_item_product_id'] : 0;
        if ( $parent > 0 ) {
            return 'ao:' . $parent . ':' . $pid . ':' . $vid;
        }
        return 'pv:' . $pid . ':' . $vid;
    }

    /**
     * Reconcile ALL child lines of a parent against the intended set of
     * child identities from the submission. Returns a summary.
     *
     *   Pass 1 — index this parent's children by identity.
     *   Pass 2 — for each identity:
     *              - absent from $intended, BUT its KIND is represented in
     *                the submission → remove every line (STALE). This is
     *                how a changed selectable variant drops its old line.
     *              - present, but ≥2 lines  → keep the first, remove the
     *                rest (DEDUP). No quantity summing — the canonical
     *                quantity is re-applied by normalize_children_qty()
     *                (child = parent_qty × per_set_qty) right after.
     *
     * Per-KIND authority. The submission (`$_POST['rental_add_ons']`) is
     * authoritative only for the identity KINDS it actually contains. The
     * legacy add-to-cart pipeline synthesises that array from
     * `_rental_set_items` (simple / selectable / addon = `pv:` identities)
     * and does NOT include composite-group children (`grp:` identities).
     * So when the submission carries no `grp:` identity, the reconciler
     * must NOT treat existing group children as stale — it has no
     * authoritative picture of them and removing them would silently drop
     * configured grouped items from the cart. Group children are only
     * reconciled once the submission genuinely carries `grp:` identities.
     *
     * Children whose identity can't be resolved are left untouched
     * (defensive: never delete what we can't positively match).
     *
     * @param string             $parent_key
     * @param array<string,true> $intended   collect_submission_identities()
     * @return array{ removed: string[], merged: string[], removed_ids: string[], skipped_ids: string[] }
     */
    public static function reconcile_children_of( $parent_key, array $intended ) {
        $removed     = array();
        $merged      = array();
        $removed_ids = array();
        $skipped_ids = array();

        if ( ! function_exists( 'WC' ) || ! WC() || ! WC()->cart ) {
            return array(
                'removed'     => $removed,
                'merged'      => $merged,
                'removed_ids' => $removed_ids,
                'skipped_ids' => $skipped_ids,
            );
        }

        // Which identity KINDS does the submission actually speak for?
        // We only remove stale children of a kind the submission contains.
        $intended_kinds = array();
        foreach ( array_keys( $intended ) as $intended_id ) {
            $intended_kinds[ self::identity_kind( $intended_id ) ] = true;
        }

        // Pass 1 — index children of this parent by identity.
        $by_identity = array();
        foreach ( WC()->cart->cart_contents as $key => $item ) {
            if ( ! isset( $item['rental_add_on_of'] ) || $item['rental_add_on_of'] !== $parent_key ) {
                continue;
            }
            $id = self::cart_item_identity( $item );
            if ( '' === $id ) {
                continue; // unidentifiable — leave alone
            }
            $by_identity[ $id ]   = isset( $by_identity[ $id ] ) ? $by_identity[ $id ] : array();
            $by_identity[ $id ][] = $key;
        }

        // Pass 2 — reconcile.
        foreach ( $by_identity as $id => $keys ) {
            if ( ! isset( $intended[ $id ] ) ) {
                // Not in the submission. Only remove it if the submission
                // is authoritative for this identity's KIND; otherwise the
                // submission simply doesn't carry this kind (e.g. group
                // children, which the legacy pipeline never serialises) and
                // we must leave it alone.
                if ( empty( $intended_kinds[ self::identity_kind( $id ) ] ) ) {
                    $skipped_ids[] = $id;
                    continue;
                }
                // Stale — drop every line for this identity.
                foreach ( $keys as $stale_key ) {
                    WC()->cart->remove_cart_item( $stale_key );
                    $removed[] = $stale_key;
                }
                $removed_ids[] = $id;
                continue;
            }
            // In submission — dedup duplicate lines (keep first).
            if ( count( $keys ) > 1 ) {
                array_shift( $keys ); // keep the first
                foreach ( $keys as $dup_key ) {
                    if ( isset( WC()->cart->cart_contents[ $dup_key ] ) ) {
                        WC()->cart->remove_cart_item( $dup_key );
                    }
                }
                $merged[] = $id;
            }
        }

        return array(
            'removed'     => $removed,
            'merged'      => $merged,
            'removed_ids' => $removed_ids,
            'skipped_ids' => $skipped_ids,
        );
    }

    /**
     * The KIND prefix of a child identity — "grp", "ao" or "pv"
     * (everything before the first ":"). Used to scope stale-removal to
     * the kinds the submission is authoritative for.
     *
     * @param string $id
     * @return string
     */
    protected static function identity_kind( $id ) {
        $pos = strpos( (string) $id, ':' );
        return false === $pos ? (string) $id : substr( (string) $id, 0, $pos );
    }

    /**
     * Settled-state normalize pass. Runs on `woocommerce_cart_updated`
     * (priority 30) — i.e. after every cart mutation has fully applied,
     * including merges and quantity changes that the add-to-cart-time
     * normalize may have raced. Re-asserts child = parent_qty × per_set_qty
     * for every set parent in the cart. Idempotent: no-ops when already
     * consistent.
     *
     * @return void
     */
    public static function on_cart_updated() {
        if ( self::$normalizing ) {
            return;
        }
        if ( ! function_exists( 'WC' ) || ! WC() || ! WC()->cart ) {
            return;
        }

        self::$normalizing = true;
        try {
            $parents = array();
            foreach ( WC()->cart->cart_contents as $key => $item ) {
                if ( ! empty( $item['rental_add_on_of'] ) ) {
                    continue; // child
                }
                $pid = isset( $item['product_id'] ) ? (int) $item['product_id'] : 0;
                if ( $pid && get_post_meta( $pid, '_rental_is_set', true ) ) {
                    $parents[] = $key;
                }
            }
            foreach ( $parents as $pkey ) {
                self::normalize_children_qty( $pkey );
            }
        } finally {
            self::$normalizing = false;
        }
    }

    /**
     * Locate the parent cart line that survived the current submission.
     *
     * Three cases:
     *
     *   1. $hint_key is still in the cart — that's the parent. (Fresh
     *      add or natural-merge.)
     *
     *   2. $hint_key was dropped by Cart_Merger or Cart_Edit_Mode —
     *      find the surviving line by product/variation match. There
     *      should be exactly one parent line per (product, variation)
     *      after a successful add.
     *
     *   3. No parent found — caller should bail.
     *
     * @param string $hint_key
     * @param int    $product_id
     * @param int    $variation_id
     * @return string|null
     */
    protected static function resolve_surviving_parent_key( $hint_key, $product_id, $variation_id ) {
        $contents = WC()->cart->cart_contents;

        if ( isset( $contents[ $hint_key ] ) && empty( $contents[ $hint_key ]['rental_add_on_of'] ) ) {
            return $hint_key;
        }

        foreach ( $contents as $key => $item ) {
            if ( ! empty( $item['rental_add_on_of'] ) ) {
                continue;
            }
            if ( (int) $item['product_id'] !== (int) $product_id ) {
                continue;
            }
            if ( (int) ( $item['variation_id'] ?? 0 ) !== (int) $variation_id ) {
                continue;
            }
            if ( ! get_post_meta( (int) $item['product_id'], '_rental_is_set', true ) ) {
                continue;
            }
            return $key;
        }

        return null;
    }

    /**
     * Defensive wrapper around Rental_Sets_Cart_Meta::is_group_child.
     * Survives the class not yet being loaded (early hook firing).
     *
     * @param array $item
     * @return bool
     */
    protected static function is_group_child_safe( $item ) {
        if ( class_exists( 'Rental_Sets_Cart_Meta', false ) ) {
            return Rental_Sets_Cart_Meta::is_group_child( $item );
        }
        return ! empty( $item['rental_set_group_is_child'] );
    }

    /**
     * Defensive cart-item meta read. Returns the string value or '' on
     * missing key, regardless of whether Cart_Meta is loaded yet.
     *
     * @param array  $item
     * @param string $key
     * @return string
     */
    protected static function read_meta( $item, $key ) {
        return isset( $item[ $key ] ) ? (string) $item[ $key ] : '';
    }
}

endif;
