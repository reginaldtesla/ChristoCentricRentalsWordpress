<?php
/**
 * Rental_Sets_Payload_Parser
 *
 * server-side OBSERVER for the normalized JSON payload emitted
 * by the modern configurator under `$_POST['rental_set_payload']`. The
 * payload mirrors the canonical wire shape produced by the JS Wrapper's
 * `serializeStatePayload()` (see assets/js/rental-sets-modern.js):
 *
 *   {
 *     "schema_version": 1,
 *     "set_id":         <int>,
 *     "rental_set_id":  <int>,
 *     "parent_qty":     <int>,
 *     "groups":         [ { uid, origin, group_id, required, group_quantity, selections: [...] } ],
 *     "selectables":    [ { uid, origin, ... } ],
 *     "addons":         [ { product_id, variant_id, inv_id, quantity, required, parent_set_item_product_id } ]
 *   }
 *
 * Crucial guarantees this class makes (the user's "nothing breaks"
 * contract):
 *
 *   - Never mutates $_POST, the cart, the session, postmeta, or any
 *     order data. Read-only inspection.
 *   - Never returns false from the validation hook (i.e. never blocks
 *     an add-to-cart).
 *   - Never throws. Any error inside parsing/logging is caught and
 *     swallowed so an upstream bug here cannot regress the cart flow.
 *   - The legacy server-side flow (rental_validate_cart_item +
 *     Rental_Sets_Cart_Validator + rental_add_product_to_cart) remains
 *     the single source of truth.
 *
 *
 * @package RentopianSync\Sets
 * @since   2.15.0
 *
 * PHP requirement: 7.4+
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'Rental_Sets_Payload_Parser', false ) ) :

class Rental_Sets_Payload_Parser {

    /**
     * Wire-protocol version the JS Wrapper emits. Bump in lockstep with
     * the JS when the payload shape changes.
     */
    const SCHEMA_VERSION = 2;

    /**
     * $_POST key carrying the JSON-serialized state snapshot.
     */
    const POST_KEY = 'rental_set_payload';

    /**
     * Convert a normalized v2 payload into the legacy nested
     * `rental_add_ons[]` shape that `rental_validate_cart_item` consumes.
     *
     * What the legacy synthesizer in rentopian-sync.php READS from
     * `$_POST['rental_add_ons']`:
     *
     *   (a) For each selectable set item: a top-level entry with
     *       `product_id` + `variant_id` (matched by product_id) — used
     *       as the form-first variant override.
     *
     *   (b) For each addon: an entry with `parent_set_item_product_id`
     *       + `product_id` + `variant_id` — used as the addon's form-
     *       first variant override.
     *
     *   (c) For each composite-group child: the FULL entry with all 9
     *       `rental_set_group_*` namespaced fields plus `item_type =
     *       'grouped_child'`. These aren't in postmeta, so the
     *       synthesizer captures them up-front and re-appends them at
     *       the end of its build pass.
     *
     * What the synthesizer DOES NOT need from this array:
     *
     *   - Simple set items. They're read entirely from postmeta.
     *   - Selectable items whose variant matches the default. (Sending
     *     the default still works; the form-first override is a no-op.)
     *
     * So this conversion only emits (a), (b), and (c). Anything beyond
     * those would be ignored anyway. The group-children entries are
     * additionally enriched from `_rental_set_grouped_items` postmeta
     * for the two display fields the wire shape doesn't carry
     * (`rental_set_group_multiple_selection`, `rental_set_group_name`)
     * so the cart-display class shows the customer-facing group label.
     *
     * @param array $payload Decoded v2 payload (output of parse_from_post).
     * @return array  Legacy `rental_add_ons[]` shape. Empty array on any
     *                input that doesn't validate (caller must fall back to
     *                whatever the legacy synthesizer would have built).
     */
    public static function build_legacy_add_ons_from_payload( $payload ) {
        if ( ! is_array( $payload ) ) {
            return array();
        }

        $entries        = array();
        $rental_set_id  = isset( $payload['rental_set_id'] ) ? (int) $payload['rental_set_id'] : 0;
        $wp_set_id      = isset( $payload['set_id'] ) ? (int) $payload['set_id'] : 0;

        // (a) Selectable variant picks. Single-select sections produce
        //     one pick; that pick's productId is the wrapper (variable)
        //     product and variantId is the chosen variation — exactly
        //     what the legacy form-first lookup matches on.
        if ( ! empty( $payload['selectables'] ) && is_array( $payload['selectables'] ) ) {
            foreach ( $payload['selectables'] as $sel ) {
                if ( ! is_array( $sel ) || empty( $sel['selections'] ) || ! is_array( $sel['selections'] ) ) {
                    continue;
                }
                foreach ( $sel['selections'] as $pick ) {
                    if ( ! is_array( $pick ) ) {
                        continue;
                    }
                    $entries[] = array(
                        'product_id' => isset( $pick['productId'] ) ? (int) $pick['productId'] : 0,
                        'variant_id' => isset( $pick['variantId'] ) ? (int) $pick['variantId'] : 0,
                        'quantity'   => isset( $pick['quantity'] )  ? (int) $pick['quantity']  : 1,
                    );
                }
            }
        }

        // (a2) Simple item picks. A simple set item is single-select like
        //      a selectable, but its origin is 'simple' (no variant
        //      dropdown — it's one fixed product). Its pick still carries
        //      the customer's chosen QUANTITY from the optional stepper.
        //      Without emitting these, the consumer's rewrite drops simple
        //      items from `rental_add_ons`, the synthesizer's form-first
        //      quantity lookup finds nothing, and the cart falls back to
        //      the postmeta package quantity — ignoring the stepper.
        if ( ! empty( $payload['simples'] ) && is_array( $payload['simples'] ) ) {
            foreach ( $payload['simples'] as $simple ) {
                if ( ! is_array( $simple ) || empty( $simple['selections'] ) || ! is_array( $simple['selections'] ) ) {
                    continue;
                }
                foreach ( $simple['selections'] as $pick ) {
                    if ( ! is_array( $pick ) ) {
                        continue;
                    }
                    $entries[] = array(
                        'product_id' => isset( $pick['productId'] ) ? (int) $pick['productId'] : 0,
                        'variant_id' => isset( $pick['variantId'] ) ? (int) $pick['variantId'] : 0,
                        'quantity'   => isset( $pick['quantity'] )  ? (int) $pick['quantity']  : 1,
                    );
                }
            }
        }

        // (b) Addon variant picks. `parent_set_item_product_id` is the
        //     WP id of the set item the addon attaches to; the legacy
        //     synthesizer matches by that PAIR (parent + addon).
        if ( ! empty( $payload['addons'] ) && is_array( $payload['addons'] ) ) {
            foreach ( $payload['addons'] as $addon ) {
                if ( ! is_array( $addon ) ) {
                    continue;
                }
                $entries[] = array(
                    'product_id'                 => isset( $addon['product_id'] ) ? (int) $addon['product_id'] : 0,
                    'variant_id'                 => isset( $addon['variant_id'] ) ? (int) $addon['variant_id'] : 0,
                    'inv_id'                     => isset( $addon['inv_id'] )     ? (int) $addon['inv_id']     : 0,
                    'quantity'                   => isset( $addon['quantity'] )   ? (int) $addon['quantity']   : 1,
                    'required'                   => ! empty( $addon['required'] ) ? 1 : 0,
                    'parent_set_item_product_id' => isset( $addon['parent_set_item_product_id'] ) ? (int) $addon['parent_set_item_product_id'] : 0,
                );
            }
        }

        // (c) Group children. The wire shape carries enough to identify
        //     each pick (product/variant/inv/qty) and the group context
        //     (uid / group_id / required / quantity). The two display-
        //     only fields the wire shape doesn't ship are looked up
        //     from `_rental_set_grouped_items` keyed by uid — one
        //     postmeta read per group, cached locally.
        if ( ! empty( $payload['groups'] ) && is_array( $payload['groups'] ) ) {
            $group_display_cache = $wp_set_id > 0 ? self::index_grouped_items_postmeta( $wp_set_id ) : array();

            foreach ( $payload['groups'] as $g ) {
                if ( ! is_array( $g ) || empty( $g['selections'] ) || ! is_array( $g['selections'] ) ) {
                    continue;
                }

                $group_uid = isset( $g['uid'] ) ? (string) $g['uid'] : '';
                $display   = isset( $group_display_cache[ $group_uid ] ) ? $group_display_cache[ $group_uid ] : array();

                foreach ( $g['selections'] as $pick ) {
                    if ( ! is_array( $pick ) ) {
                        continue;
                    }
                    // Field set mirrors exactly what the JS `writeHiddenInputs`
                    // emits per pick for an `entry.origin === 'group'`
                    // section (see rental-sets-modern.js:1428-1453).
                    //
                    // Why this needs to be EXACT, not a subset: grouped
                    // children are NOT walked back through the legacy
                    // synthesizer's postmeta loop — `_rental_set_items`
                    // doesn't contain them. The synthesizer captures
                    // them as-is (rentopian-sync.php:897-909) and re-
                    // appends them straight into `$_POST['rental_add_ons']`
                    // (line 1518-1521). So every field that
                    // `rental_add_product_to_cart` reads later
                    // (line 4537+ — price branch, line 4591 — required,
                    // line 4594-4595 — rental ids, line 4549 — parent_set_id
                    // for item_based_total lookup) must already be on the
                    // entry. Selectables and simples don't have this
                    // requirement because the synthesizer rebuilds their
                    // entries from postmeta.
                    $entries[] = array(
                        // Basics (lines 1430-1433 in JS).
                        'product_id'                          => isset( $pick['productId'] ) ? (int) $pick['productId'] : 0,
                        'variant_id'                          => isset( $pick['variantId'] ) ? (int) $pick['variantId'] : 0,
                        'inv_id'                              => isset( $pick['invId'] )     ? (int) $pick['invId']     : 0,
                        'quantity'                            => isset( $pick['quantity'] )  ? (int) $pick['quantity']  : 1,

                        // Required + price-resolution hints (lines 1434-1441).
                        // The JS makes `required` per-pick from
                        // `entry.required` (the group's required flag),
                        // not per-item — mirrored here. `inherit_price=1`
                        // forces the addon branch in
                        // rental_add_product_to_cart to resolve the
                        // variant's `_price` postmeta instead of
                        // falling back to the raw `price` field, which
                        // matches the JS's price-resolution policy.
                        'required'                            => ! empty( $g['required'] ) ? 1 : 0,
                        'set_id'                              => $rental_set_id,
                        'parent_set_id'                       => $wp_set_id,
                        'inherit_price'                       => 1,
                        'price'                               => isset( $pick['price'] ) ? $pick['price'] : 0,
                        // Core (Rentopian-side) ids. The wire shape
                        // doesn't carry these per pick (the JS uses
                        // `pick.rentalProductId || 0` and they're rarely
                        // populated on group children). Shipping 0 keeps
                        // the downstream code's `isset()` reads happy.
                        'rental_product_id'                   => 0,
                        'rental_variant_id'                   => 0,

                        // Composite-group context (lines 1444-1453 in JS).
                        'item_type'                           => 'grouped_child',
                        'rental_set_group_is_child'           => 1,
                        'rental_set_group_uid'                => $group_uid,
                        'rental_set_group_item_uid'           => isset( $pick['itemUid'] ) ? (string) $pick['itemUid'] : '',
                        'rental_set_group_id'                 => isset( $g['group_id'] ) ? (int) $g['group_id'] : 0,
                        'rental_set_group_required'           => ! empty( $g['required'] ) ? 1 : 0,
                        'rental_set_group_qty'                => isset( $g['group_quantity'] ) ? (int) $g['group_quantity'] : 1,
                        'rental_set_group_multiple_selection' => isset( $display['multiple_selection'] ) ? (int) $display['multiple_selection'] : 0,
                        'rental_set_group_name'               => isset( $display['name'] ) ? (string) $display['name'] : '',
                        'rental_set_group_price'              => isset( $display['price'] ) ? $display['price'] : '',
                    );
                }
            }
        }

        return $entries;
    }

    /**
     * Index `_rental_set_grouped_items` postmeta by group UID so the
     * v2-to-legacy converter can stamp the two display fields the wire
     * shape doesn't carry (`rental_set_group_multiple_selection`,
     * `rental_set_group_name`, `rental_set_group_price`).
     *
     * @param int $wp_set_id
     * @return array<string,array>  uid → { name, multiple_selection, price }
     */
    protected static function index_grouped_items_postmeta( $wp_set_id ) {
        $groups = get_post_meta( (int) $wp_set_id, '_rental_set_grouped_items', true );
        if ( ! is_array( $groups ) ) {
            return array();
        }
        $out = array();
        foreach ( $groups as $g ) {
            if ( ! is_array( $g ) ) {
                continue;
            }
            $uid = isset( $g['uid'] ) ? (string) $g['uid'] : '';
            if ( '' === $uid ) {
                continue;
            }
            $out[ $uid ] = array(
                'name'               => isset( $g['group_name'] ) ? (string) $g['group_name'] : '',
                'multiple_selection' => ! empty( $g['multiple_selection'] ) ? 1 : 0,
                'price'              => isset( $g['group_price'] ) ? $g['group_price'] : '',
            );
        }
        return $out;
    }

    /**
     * Hook in. Priority 5 on `woocommerce_add_to_cart_validation` puts
     * us BEFORE the legacy validator (priority 10), so the payload we
     * observe is the raw browser submission — not the synthesized
     * `$_POST['rental_add_ons']` the legacy code builds from postmeta.
     *
     * @return void
     */
    public static function register() {
        // Priority 5 — BEFORE the legacy validator rewrites $_POST. Captures
        // the raw browser submission as a sanity baseline + schema check.
        add_filter( 'woocommerce_add_to_cart_validation', array( __CLASS__, 'observe_add_to_cart' ), 5, 3 );

        // Priority 11 — AFTER the legacy validator has synthesized
        // $_POST['rental_add_ons'] from postmeta. Diffs our normalized
        // payload against the children the legacy code is about to insert,
        // so we can verify the new shape would have produced the same
        // cart contents before any cutover.
        add_filter( 'woocommerce_add_to_cart_validation', array( __CLASS__, 'observe_post_legacy_diff' ), 11, 3 );
    }

    /**
     * Parse the normalized payload from $_POST if present. Returns the
     * decoded array, or null when the payload isn't present / can't be
     * parsed / decodes to a non-array shape. Never throws.
     *
     * @return array|null
     */
    public static function parse_from_post() {
        if ( ! isset( $_POST[ self::POST_KEY ] ) ) {
            return null;
        }
        $raw = wp_unslash( $_POST[ self::POST_KEY ] );
        if ( ! is_string( $raw ) || '' === $raw ) {
            return null;
        }
        $decoded = json_decode( $raw, true );
        return is_array( $decoded ) ? $decoded : null;
    }

    /**
     * Schema check — returns a list of issue codes, empty array when OK.
     * Codes are stable strings suitable for grepping the log later when
     * we ratchet the wire format.
     *
     * @param mixed $payload
     * @return string[]
     */
    public static function validate_schema( $payload ) {
        $issues = array();

        if ( ! is_array( $payload ) ) {
            $issues[] = 'not_array';
            return $issues;
        }
        if ( ! isset( $payload['schema_version'] ) || (int) $payload['schema_version'] !== self::SCHEMA_VERSION ) {
            $issues[] = 'schema_version_mismatch';
        }
        foreach ( array( 'set_id', 'parent_qty' ) as $key ) {
            if ( ! isset( $payload[ $key ] ) || ! is_numeric( $payload[ $key ] ) ) {
                $issues[] = $key . '_missing_or_non_numeric';
            }
        }
        foreach ( array( 'groups', 'selectables', 'simples', 'addons' ) as $key ) {
            if ( isset( $payload[ $key ] ) && ! is_array( $payload[ $key ] ) ) {
                $issues[] = $key . '_not_array';
            }
        }
        return $issues;
    }

    /**
     * `woocommerce_add_to_cart_validation` callback. Pure observer:
     * parses the payload, validates the schema, writes a structured log
     * line, and returns `$passed` unchanged. Cannot block an add.
     *
     * @param bool $passed
     * @param int  $product_id
     * @param int  $quantity
     * @return bool
     */
    public static function observe_add_to_cart( $passed, $product_id, $quantity ) {
        try {
            $payload = self::parse_from_post();
            if ( null === $payload ) {
                return $passed; // nothing to observe
            }
            // Defensively gate on set products. A non-set submission
            // shouldn't carry the payload but check anyway so logs stay
            // scoped to the modern set flow.
            if ( ! get_post_meta( (int) $product_id, '_rental_is_set', true ) ) {
                return $passed;
            }

            if ( ! class_exists( 'Rental_Sets_Logger', false ) ) {
                return $passed; // logger not loaded; nothing useful to do
            }

            $issues = self::validate_schema( $payload );
            Rental_Sets_Logger::warn(
                'payload_observe',
                'normalized payload received',
                array(
                    'set'         => (int) $product_id,
                    'qty'         => (int) $quantity,
                    'parent_qty'  => isset( $payload['parent_qty'] ) ? (int) $payload['parent_qty'] : 0,
                    'groups'      => isset( $payload['groups'] )      && is_array( $payload['groups'] )      ? count( $payload['groups'] )      : 0,
                    'selectables' => isset( $payload['selectables'] ) && is_array( $payload['selectables'] ) ? count( $payload['selectables'] ) : 0,
                    'simples'     => isset( $payload['simples'] )     && is_array( $payload['simples'] )     ? count( $payload['simples'] )     : 0,
                    'addons'      => isset( $payload['addons'] )      && is_array( $payload['addons'] )      ? count( $payload['addons'] )      : 0,
                    'schema_ok'   => empty( $issues ) ? 1 : 0,
                    'issues'      => $issues ? implode( ',', $issues ) : '-',
                )
            );
        } catch ( \Throwable $e ) {
            // Observation MUST NOT regress the cart. Swallow and log.
            if ( class_exists( 'Rental_Sets_Logger', false ) ) {
                Rental_Sets_Logger::warn(
                    'payload_observe_error',
                    $e->getMessage(),
                    array( 'set' => (int) $product_id )
                );
            }
        }
        return $passed;
    }

    /**
     * `woocommerce_add_to_cart_validation` callback at priority 11 — runs
     * AFTER the legacy `rental_validate_cart_item` (priority 10) has
     * rewritten `$_POST['rental_add_ons']` from postmeta. Diffs what the
     * normalized payload says SHOULD be added against the children the
     * legacy code is about to insert, so we can verify the new shape is
     * a faithful representation of the legacy outcome before any cutover.
     *
     * Expected mapping:
     *
     *   payload.groups.Σ(selections)  ===  count of grouped_child entries
     *                                       in legacy rental_add_ons
     *
     *   payload.selectables + simples ===  count of non-group entries
     *                                       in legacy rental_add_ons
     *
     *   payload.addons                ===  Σ(count of nested $entry['addons']
     *                                          across legacy entries)
     *
     * Any drift in the log between these pairs is the signal to fix the
     * client shape (or the legacy synthesis) before the server cutover.
     * NEVER blocks the add — pure logging.
     *
     * @param bool $passed
     * @param int  $product_id
     * @param int  $quantity
     * @return bool
     */
    public static function observe_post_legacy_diff( $passed, $product_id, $quantity ) {
        try {
            $payload = self::parse_from_post();
            if ( null === $payload ) {
                return $passed;
            }
            if ( ! get_post_meta( (int) $product_id, '_rental_is_set', true ) ) {
                return $passed;
            }
            if ( ! class_exists( 'Rental_Sets_Logger', false ) ) {
                return $passed;
            }

            // ── Payload-side counts ──────────────────────────────────
            $payload_group_selections = 0;
            if ( isset( $payload['groups'] ) && is_array( $payload['groups'] ) ) {
                foreach ( $payload['groups'] as $g ) {
                    if ( is_array( $g ) && isset( $g['selections'] ) && is_array( $g['selections'] ) ) {
                        $payload_group_selections += count( $g['selections'] );
                    }
                }
            }
            $payload_selectables = isset( $payload['selectables'] ) && is_array( $payload['selectables'] ) ? count( $payload['selectables'] ) : 0;
            $payload_simples     = isset( $payload['simples'] )     && is_array( $payload['simples'] )     ? count( $payload['simples'] )     : 0;
            $payload_addons      = isset( $payload['addons'] )      && is_array( $payload['addons'] )      ? count( $payload['addons'] )      : 0;

            // ── Legacy-side counts (post-priority-10 synthesis) ──────
            $legacy_rental_add_ons   = isset( $_POST['rental_add_ons'] ) && is_array( $_POST['rental_add_ons'] ) ? $_POST['rental_add_ons'] : array();
            $legacy_group_children   = 0;
            $legacy_non_group        = 0;
            $legacy_nested_addons    = 0;
            foreach ( $legacy_rental_add_ons as $entry ) {
                if ( ! is_array( $entry ) ) {
                    continue;
                }
                $is_group_child =
                    ( isset( $entry['item_type'] ) && 'grouped_child' === $entry['item_type'] )
                    || ! empty( $entry['rental_set_group_is_child'] )
                    || ! empty( $entry['rental_set_group_uid'] );
                if ( $is_group_child ) {
                    $legacy_group_children++;
                } else {
                    $legacy_non_group++;
                }
                if ( ! empty( $entry['addons'] ) && is_array( $entry['addons'] ) ) {
                    $legacy_nested_addons += count( $entry['addons'] );
                }
            }

            // ── Diff verdict ────────────────────────────────────────
            $non_group_match = ( ( $payload_selectables + $payload_simples ) === $legacy_non_group ) ? 1 : 0;
            $groups_match    = ( $payload_group_selections === $legacy_group_children ) ? 1 : 0;
            $addons_match    = ( $payload_addons === $legacy_nested_addons ) ? 1 : 0;
            $all_match       = ( $non_group_match && $groups_match && $addons_match ) ? 1 : 0;

            Rental_Sets_Logger::warn(
                'payload_diff',
                $all_match ? 'payload === legacy (counts)' : 'payload != legacy (counts)',
                array(
                    'set'             => (int) $product_id,
                    'p_groups_sel'    => $payload_group_selections,
                    'p_selectables'   => $payload_selectables,
                    'p_simples'       => $payload_simples,
                    'p_addons'        => $payload_addons,
                    'l_group_kids'    => $legacy_group_children,
                    'l_non_group'     => $legacy_non_group,
                    'l_nested_addons' => $legacy_nested_addons,
                    'groups_match'    => $groups_match,
                    'non_group_match' => $non_group_match,
                    'addons_match'    => $addons_match,
                    'all_match'       => $all_match,
                )
            );
        } catch ( \Throwable $e ) {
            // Diff observation must never regress the cart.
            if ( class_exists( 'Rental_Sets_Logger', false ) ) {
                Rental_Sets_Logger::warn(
                    'payload_diff_error',
                    $e->getMessage(),
                    array( 'set' => (int) $product_id )
                );
            }
        }
        return $passed;
    }
}

endif;
