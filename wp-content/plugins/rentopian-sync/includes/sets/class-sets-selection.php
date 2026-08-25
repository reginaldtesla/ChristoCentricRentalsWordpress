<?php
/**
 * Rental_Sets_Selection
 *
 * Owns the set's per-customer SELECTION state and the read path that
 * combines it with the authoritative set DEFINITION.
 *
 * Two stores are involved:
 *
 *   - Definition (authoritative): `_rental_set_items` postmeta. Written by
 *     the sync / webhook pipeline. Carries the set's STRUCTURE — which
 *     items exist, their `hidden` flags, which selectable options / addons
 *     exist, required / separate_price, etc.
 *
 *   - Selection overlay (transient): a per-customer copy of the items
 *     array stored in their WC session. Carries the customer's in-progress
 *     PICKS — which option is selected, the locked option price. Cleared
 *     once the set is committed to the cart.
 *
 * The subtlety this class fixes: the overlay used to be returned verbatim
 * whenever present, which made it SHADOW later definition changes. If an
 * admin hid an item (or changed the set) after the customer's overlay was
 * taken, the stale overlay kept showing the old structure. `resolve()` now
 * compares a STRUCTURAL fingerprint of the overlay against the current
 * definition: when they diverge the overlay is stale and is discarded so
 * the fresh definition wins. Selection-only differences (which option is
 * picked, the locked price) do not change the fingerprint, so a live
 * configuration is preserved.
 *
 * It also owns the server-side resolution of HIDDEN items' defaults
 * (`apply_hidden_defaults`) — locking selectable / addon defaults at
 * add-to-cart and skipping options that have no configured default — the
 * layout-agnostic replacement for the old page-load default AJAX.
 *
 * The procedural `rental_set_*` functions in functions.php delegate here so
 * the whole subsystem lives in one place for debugging / testing / logging.
 *
 * @package RentopianSync\Sets
 *
 * PHP requirement: 7.4+
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'Rental_Sets_Selection', false ) ) :

class Rental_Sets_Selection {

    /**
     * Session key holding the per-customer selection overlay for a set.
     *
     * @param int $set_id
     * @return string
     */
    public static function session_key( $set_id ) {
        return 'rental_set_items_ovr_' . (int) $set_id;
    }

    /**
     * Companion session key holding the fingerprint of the DEFINITION the
     * overlay was built against. Compared on read to detect a stale overlay.
     *
     * @param int $set_id
     * @return string
     */
    public static function def_key( $set_id ) {
        return self::session_key( $set_id ) . '_def';
    }

    /**
     * The set's items array resolved against the customer's session overlay.
     *
     * Definition (postmeta) is authoritative for STRUCTURE; the overlay is
     * applied only while it still matches that structure. A stale overlay
     * (taken before a definition change such as hiding an item) is dropped
     * so the change reflects immediately.
     *
     * @param int $set_id
     * @return array
     */
    public static function resolve( $set_id ) {
        $set_id = (int) $set_id;

        $base = get_post_meta( $set_id, '_rental_set_items', true );
        $base = is_array( $base ) ? $base : array();

        if ( ! function_exists( 'get_rental_session_data' ) || ! function_exists( 'WC' ) || ! WC() || ! WC()->session ) {
            return $base;
        }

        $overlay = get_rental_session_data( self::session_key( $set_id ), null );
        if ( ! is_array( $overlay ) || empty( $overlay ) ) {
            return $base;
        }

        // Stale-overlay guard. store_override() snapshots a fingerprint of
        // the DEFINITION the overlay was built against. If the live
        // definition no longer matches — ANY Sets detail changed: hidden,
        // price, required, separate_price, quantity, note, or the
        // item/option/addon structure — the overlay is stale and is dropped
        // so the change reflects immediately. The fingerprint is computed
        // from postmeta both times (never the overlay), so a customer's
        // selection-induced price/variant mutations never false-trigger it;
        // pure selection/default fields are excluded too, so an in-progress
        // configuration survives a default-only change.
        $stored_fp  = get_rental_session_data( self::def_key( $set_id ), null );
        $current_fp = self::definition_fingerprint( $base );
        if ( ! is_string( $stored_fp ) || $stored_fp !== $current_fp ) {
            self::clear_override( $set_id );
            if ( class_exists( 'Rental_Sets_Logger', false ) ) {
                Rental_Sets_Logger::hide( $set_id, array( 'action' => 'overlay_dropped', 'reason' => 'definition_changed' ) );
            }
            return $base;
        }

        return $overlay;
    }

    /**
     * Fingerprint of a set's DEFINITION — every Sets detail that, when an
     * admin/webhook changes it, should invalidate a customer's stale
     * selection overlay: hidden, required, separate_price, in-set price,
     * quantity, note, and the full item / option / addon structure.
     *
     * Always computed from the postmeta definition (never the overlay), so
     * selection-induced mutations (the picked option's recomputed price /
     * variant) never affect it. Pure selection / default fields
     * (`has_selected`, `is_selected`, `optional_item_price_update_needed`)
     * are EXCLUDED, so a default-only change preserves an in-progress pick.
     * Order-independent.
     *
     * @param mixed $items
     * @return string
     */
    public static function definition_fingerprint( $items ) {
        if ( ! is_array( $items ) ) {
            return '';
        }

        $sig = array();
        foreach ( $items as $item ) {
            if ( ! is_array( $item ) ) {
                continue;
            }
            $sig[] = self::project_item_definition( $item );
        }

        usort( $sig, static function ( $x, $y ) {
            return strcmp( (string) $x['k'], (string) $y['k'] );
        } );

        return md5( wp_json_encode( $sig ) );
    }

    /**
     * Project one set item down to its definition fields (see
     * definition_fingerprint). Selection / default fields are omitted.
     *
     * @param array $item
     * @return array
     */
    protected static function project_item_definition( array $item ) {
        $p = (int) ( $item['rental_product_id'] ?? $item['product_id'] ?? 0 );
        $v = (int) ( $item['rental_variant_id'] ?? $item['variant_id'] ?? 0 );

        $row = array(
            'k'  => $p . ':' . $v,
            'h'  => empty( $item['hidden'] )         ? 0 : 1,
            'r'  => empty( $item['required'] )       ? 0 : 1,
            's'  => empty( $item['separate_price'] ) ? 0 : 1,
            'pr' => isset( $item['price'] )    ? (string) $item['price'] : '',
            'q'  => isset( $item['quantity'] ) ? (int) $item['quantity'] : 0,
            'n'  => isset( $item['note'] )     ? (string) $item['note'] : '',
            'o'  => array(),
            'a'  => array(),
        );

        // Selectable options — identity + in-set price + quantity.
        if ( ! empty( $item['optional_items'] ) && is_array( $item['optional_items'] ) ) {
            foreach ( $item['optional_items'] as $o ) {
                if ( ! is_array( $o ) ) {
                    continue;
                }
                $row['o'][] = (int) ( $o['rental_product_id'] ?? $o['product_id'] ?? 0 )
                    . '-' . (int) ( $o['rental_variant_id'] ?? $o['variant_id'] ?? 0 )
                    . '-' . ( isset( $o['price'] )    ? (string) $o['price'] : '' )
                    . '-' . ( isset( $o['quantity'] ) ? (int) $o['quantity'] : 0 );
            }
            sort( $row['o'] );
        }

        // Addons — identity + hidden/required + price + qty + variant options.
        if ( ! empty( $item['addons'] ) && is_array( $item['addons'] ) ) {
            foreach ( $item['addons'] as $a ) {
                if ( ! is_array( $a ) ) {
                    continue;
                }
                $av = array();
                if ( ! empty( $a['variants_optional'] ) && is_array( $a['variants_optional'] ) ) {
                    foreach ( $a['variants_optional'] as $vo ) {
                        if ( ! is_array( $vo ) ) {
                            continue;
                        }
                        $av[] = (int) ( $vo['rental_product_id'] ?? $vo['product_id'] ?? 0 )
                            . '-' . (int) ( $vo['rental_variant_id'] ?? $vo['variant_id'] ?? 0 )
                            . '-' . ( empty( $vo['default'] ) ? 0 : 1 )
                            . '-' . ( isset( $vo['quantity'] ) ? (int) $vo['quantity'] : 0 );
                    }
                    sort( $av );
                }
                $row['a'][] = array(
                    'i'  => (int) ( $a['rental_inv_id'] ?? $a['rental_product_id'] ?? $a['product_id'] ?? 0 ),
                    'h'  => empty( $a['hidden'] )        ? 0 : 1,
                    'r'  => empty( $a['required'] )      ? 0 : 1,
                    'pr' => isset( $a['price'] )         ? (string) $a['price'] : '',
                    'q'  => isset( $a['quantity'] )      ? (int) $a['quantity'] : 0,
                    'pp' => isset( $a['product_price'] ) ? (string) $a['product_price'] : '',
                    'ip' => empty( $a['inherit_price'] ) ? 0 : 1,
                    'vo' => $av,
                );
            }
            usort( $row['a'], static function ( $x, $y ) {
                return $x['i'] <=> $y['i'];
            } );
        }

        return $row;
    }

    /**
     * Persist the customer's working selection overlay (full items array)
     * to their own session.
     *
     * @param int   $set_id
     * @param array $items
     * @return void
     */
    public static function store_override( $set_id, $items ) {
        if ( ! is_array( $items ) ) {
            return;
        }
        if ( function_exists( 'set_rental_session_data' ) ) {
            $set_id = (int) $set_id;
            set_rental_session_data( self::session_key( $set_id ), $items );
            // Snapshot the DEFINITION the overlay was built against (read
            // from postmeta, not the overlay) so a later definition change
            // can be detected on read and the stale overlay dropped.
            $definition = get_post_meta( $set_id, '_rental_set_items', true );
            set_rental_session_data(
                self::def_key( $set_id ),
                self::definition_fingerprint( is_array( $definition ) ? $definition : array() )
            );
        }
    }

    /**
     * Clear the customer's working selection overlay (and its definition
     * fingerprint companion).
     *
     * @param int $set_id
     * @return void
     */
    public static function clear_override( $set_id ) {
        if ( function_exists( 'set_rental_session_data' ) ) {
            $set_id = (int) $set_id;
            set_rental_session_data( self::session_key( $set_id ), null );
            set_rental_session_data( self::def_key( $set_id ), null );
        }
    }

    /**
     * Resolve default selections for HIDDEN set items, server-side.
     *
     * Hidden items can't be configured by the customer (the whole set is
     * hidden, or the item carries `hidden=1`), so any selectable variant or
     * addon variant must be resolved to a default at add-to-cart time.
     *
     * Rules, applied only to hidden items:
     *   - Selectable item with a configured default (an `is_selected`
     *     option, or a single option) → lock that option in.
     *   - Selectable item with NO configured default → SKIP it.
     *   - Addon with a default variant (a `default` option, or a single
     *     one) → lock it in; otherwise the addon is dropped.
     *
     * Pure: returns a new items array; performs no postmeta/session writes.
     *
     * @param int   $set_id
     * @param array $items
     * @return array
     */
    public static function apply_hidden_defaults( $set_id, array $items ) {
        $set_id      = (int) $set_id;
        $hide_all    = (bool) get_option( 'rental_hide_set_items', 0 )
            || (bool) get_post_meta( $set_id, '_rental_hide_items_on_website', true );
        $some_hidden = (bool) get_post_meta( $set_id, '_rental_some_hidden_items', true );

        // Nothing hidden — return untouched so visible-set carts are identical.
        if ( ! $hide_all && ! $some_hidden ) {
            return $items;
        }

        // Decision trail for the debug log (only built when something is hidden).
        $decisions = array();

        foreach ( $items as $key => $item ) {
            if ( ! is_array( $item ) ) {
                continue;
            }

            $item_hidden = $hide_all || ( $some_hidden && ! empty( $item['hidden'] ) );
            if ( ! $item_hidden ) {
                continue;
            }

            $pid = isset( $item['product_id'] ) ? (int) $item['product_id'] : 0;

            // Selectable item — ensure it carries a chosen variant. A hidden
            // selectable can't be configured, so resolve it to its default
            // option whenever it doesn't already have a variant locked in.
            // This covers BOTH "no selection yet" AND the case where the API
            // marked has_selected but kept the chosen variant on the option
            // (item variant_id empty) — without it the item would be added as
            // a non-variant line and bill $0. No resolvable default → skip
            // (nothing accountable to add).
            $has_optional = ! empty( $item['optional_items'] ) && is_array( $item['optional_items'] );
            if ( $has_optional && empty( $item['variant_id'] ) ) {
                $resolved = self::resolve_selectable_default( $item['optional_items'] );
                if ( null === $resolved ) {
                    $decisions[] = 'selectable-skip(p=' . $pid . ',no-default)';
                    unset( $items[ $key ] );
                    continue;
                }
                $item['optional_items']    = $resolved['optional_items'];
                $item['has_selected']      = 1;
                $item['variant_id']        = $resolved['variant_id'];
                $item['price']             = $resolved['price'];
                $item['rental_variant_id'] = $resolved['rental_variant_id'];
                $item['rental_product_id'] = $resolved['rental_product_id'];
                $decisions[] = 'selectable-default(p=' . $pid . ',v=' . (int) $resolved['variant_id'] . ',price=' . $resolved['price'] . ')';
            } elseif ( $has_optional ) {
                $decisions[] = 'selectable-kept(p=' . $pid . ',v=' . (int) $item['variant_id'] . ')';
            } else {
                $decisions[] = 'simple-keep(p=' . $pid . ',price=' . ( isset( $item['price'] ) ? $item['price'] : 0 ) . ')';
            }

            // Addons of a hidden item — resolve each addon's default
            // variant, or drop the addon when it has variant choices but
            // no default.
            if ( ! empty( $item['addons'] ) && is_array( $item['addons'] ) ) {
                foreach ( $item['addons'] as $ak => $addon ) {
                    if ( ! is_array( $addon ) ) {
                        continue;
                    }
                    $has_addon_opts = ! empty( $addon['variants_optional'] ) && is_array( $addon['variants_optional'] );
                    $addon_selected = ! empty( $addon['has_selected'] ) || ! empty( $addon['already_selected'] );
                    if ( ! $has_addon_opts || $addon_selected ) {
                        continue;
                    }
                    $resolved = self::resolve_addon_default( $addon['variants_optional'] );
                    if ( null === $resolved ) {
                        $decisions[] = 'addon-skip(p=' . (int) ( $addon['product_id'] ?? 0 ) . ',no-default)';
                        unset( $item['addons'][ $ak ] );
                        continue;
                    }
                    $item['addons'][ $ak ]['variants_optional'] = $resolved['variants_optional'];
                    $item['addons'][ $ak ]['has_selected']      = 1;
                    $item['addons'][ $ak ]['already_selected']  = 1;
                    $item['addons'][ $ak ]['variant_id']        = $resolved['variant_id'];
                    $item['addons'][ $ak ]['product_id']        = $resolved['product_id'];
                    $item['addons'][ $ak ]['rental_variant_id'] = $resolved['rental_variant_id'];
                    $item['addons'][ $ak ]['rental_product_id'] = $resolved['rental_product_id'];
                    $decisions[] = 'addon-default(p=' . (int) $resolved['product_id'] . ',v=' . (int) $resolved['variant_id'] . ')';
                }
                // Re-index so the synthesizer's addon loop sees a clean list.
                $item['addons'] = array_values( $item['addons'] );
            }

            $items[ $key ] = $item;
        }

        if ( class_exists( 'Rental_Sets_Logger', false ) ) {
            Rental_Sets_Logger::hide( $set_id, array(
                'action'      => 'defaults',
                'hide_all'    => $hide_all ? 1 : 0,
                'some_hidden' => $some_hidden ? 1 : 0,
                'decisions'   => implode( ' ', $decisions ),
            ) );
        }

        return array_values( $items );
    }

    /**
     * Resolve default item(s) for HIDDEN composite groups (entity-3) into
     * legacy `rental_add_ons[]` grouped-child entries, so a hidden group's
     * default contributes its price to the total — the customer can't pick
     * it, so the default stands in. Returns an empty array when no group is
     * hidden.
     *
     * Rule (matches the optional-item-with-default rule): add the group's
     * `is_default` item(s) — single-select takes one default (or the sole
     * item), multi-select takes all defaults — and SKIP a group that has no
     * default. Price follows the normal group rules downstream (group_price
     * override, else the item's in-set price, else the live variant chain).
     *
     * @param int $wp_set_id     WP set post id.
     * @param int $rental_set_id Core (Rentopian) set id.
     * @return array<int,array>
     */
    public static function resolve_hidden_group_children( $wp_set_id, $rental_set_id ) {
        $wp_set_id     = (int) $wp_set_id;
        $rental_set_id = (int) $rental_set_id;

        $hide_all = (bool) get_option( 'rental_hide_set_items', 0 )
            || (bool) get_post_meta( $wp_set_id, '_rental_hide_items_on_website', true );

        $groups = get_post_meta( $wp_set_id, '_rental_set_grouped_items', true );
        if ( ! is_array( $groups ) || empty( $groups ) ) {
            return array();
        }

        $entries   = array();
        $decisions = array();

        foreach ( $groups as $group ) {
            if ( ! is_array( $group ) ) {
                continue;
            }

            // Only HIDDEN groups need server-side resolution; a visible
            // group is configured through the form like normal.
            $group_hidden = $hide_all || ! empty( $group['hide_on_website'] );
            if ( ! $group_hidden ) {
                continue;
            }

            $group_uid = isset( $group['uid'] ) ? (string) $group['uid'] : '';
            $items     = ( ! empty( $group['items'] ) && is_array( $group['items'] ) ) ? array_values( $group['items'] ) : array();
            if ( empty( $items ) ) {
                $decisions[] = 'group-skip(' . $group_uid . ',no-items)';
                continue;
            }

            $multiple = ! empty( $group['multiple_selection'] );

            // Default item(s): is_default; else the sole item; else skip.
            $defaults = array();
            foreach ( $items as $it ) {
                if ( is_array( $it ) && ! empty( $it['is_default'] ) ) {
                    $defaults[] = $it;
                }
            }
            if ( empty( $defaults ) ) {
                if ( 1 === count( $items ) && is_array( $items[0] ) ) {
                    $defaults = array( $items[0] );
                } else {
                    $decisions[] = 'group-skip(' . $group_uid . ',no-default)';
                    continue;
                }
            }
            if ( ! $multiple ) {
                $defaults = array( reset( $defaults ) ); // single-select → one
            }

            $group_price = isset( $group['group_price'] ) ? $group['group_price'] : '';

            foreach ( $defaults as $it ) {
                if ( ! is_array( $it ) ) {
                    continue;
                }
                $wp_product = isset( $it['product_id'] ) ? (int) $it['product_id'] : 0;
                $wp_variant = isset( $it['variant_id'] ) ? (int) $it['variant_id'] : 0;
                if ( $wp_product <= 0 && $wp_variant <= 0 ) {
                    continue;
                }
                $qty = isset( $it['quantity'] ) ? (int) $it['quantity'] : 1;
                if ( $qty < 1 ) {
                    $qty = 1;
                }

                $entries[] = array(
                    'product_id'                          => $wp_product,
                    'variant_id'                          => $wp_variant,
                    'inv_id'                              => isset( $it['rental_inv_id'] ) ? (int) $it['rental_inv_id'] : 0,
                    'quantity'                            => $qty,
                    'required'                            => ! empty( $group['required'] ) ? 1 : 0,
                    'set_id'                              => $rental_set_id,
                    'parent_set_id'                       => $wp_set_id,
                    'inherit_price'                       => 1,
                    'price'                               => isset( $it['price'] ) ? $it['price'] : 0,
                    'rental_product_id'                   => isset( $it['rental_product_id'] ) ? (int) $it['rental_product_id'] : 0,
                    'rental_variant_id'                   => isset( $it['rental_variant_id'] ) ? (int) $it['rental_variant_id'] : 0,
                    'item_type'                           => 'grouped_child',
                    'rental_set_group_is_child'           => 1,
                    'rental_set_group_uid'                => $group_uid,
                    'rental_set_group_item_uid'           => isset( $it['uid'] ) ? (string) $it['uid'] : '',
                    'rental_set_group_id'                 => isset( $group['rental_group_id'] ) ? (int) $group['rental_group_id'] : 0,
                    'rental_set_group_required'           => ! empty( $group['required'] ) ? 1 : 0,
                    'rental_set_group_qty'                => isset( $group['group_quantity'] ) ? (int) $group['group_quantity'] : 1,
                    'rental_set_group_multiple_selection' => $multiple ? 1 : 0,
                    'rental_set_group_name'               => isset( $group['group_name'] ) ? (string) $group['group_name'] : '',
                    'rental_set_group_price'              => $group_price,
                );
                $decisions[] = 'group-default(' . $group_uid . ',p=' . $wp_product . ',v=' . $wp_variant
                    . ',price=' . ( isset( $it['price'] ) ? $it['price'] : 0 ) . ')';
            }
        }

        if ( ! empty( $decisions ) && class_exists( 'Rental_Sets_Logger', false ) ) {
            Rental_Sets_Logger::hide( $wp_set_id, array(
                'action'    => 'group_defaults',
                'hide_all'  => $hide_all ? 1 : 0,
                'children'  => count( $entries ),
                'decisions' => implode( ' ', $decisions ),
            ) );
        }

        return $entries;
    }

    /**
     * Pick a selectable item's default option. Prefers an option flagged
     * `is_selected`; falls back to the sole option when there is only one.
     * Returns null when neither applies (no default → caller skips).
     *
     * @param array $optional_items
     * @return array|null { optional_items, variant_id, price, rental_variant_id, rental_product_id }
     */
    public static function resolve_selectable_default( array $optional_items ) {
        $chosen_key = null;
        foreach ( $optional_items as $k => $opt ) {
            if ( ! empty( $opt['is_selected'] ) ) {
                $chosen_key = $k;
                break;
            }
        }
        if ( null === $chosen_key && count( $optional_items ) === 1 ) {
            $chosen_key = array_key_first( $optional_items );
        }
        if ( null === $chosen_key || ! isset( $optional_items[ $chosen_key ] ) ) {
            return null;
        }

        $opt    = $optional_items[ $chosen_key ];
        $lookup = ! empty( $opt['variant_id'] ) ? (int) $opt['variant_id'] : (int) ( $opt['product_id'] ?? 0 );
        if ( $lookup <= 0 ) {
            return null;
        }

        $price = function_exists( 'rental_calculate_rental_item_price' ) ? rental_calculate_rental_item_price( $lookup ) : 0;

        foreach ( $optional_items as $k => $o ) {
            $optional_items[ $k ]['is_selected'] = ( $k === $chosen_key ) ? 1 : 0;
            $optional_items[ $k ]['price']       = ( $k === $chosen_key ) ? $price : 0;
        }

        return array(
            'optional_items'    => $optional_items,
            'variant_id'        => $lookup,
            'price'             => $price,
            'rental_variant_id' => $opt['rental_variant_id'] ?? 0,
            'rental_product_id' => $opt['rental_product_id'] ?? 0,
        );
    }

    /**
     * The variant a submission picks for a selectable set item, or 0.
     *
     * The pick reaches the server in the form before the async persist
     * AJAX writes it to the selection overlay, so the form is the fresher
     * source. Two shapes carry it: the modern
     * `rental_set_selections[<uid>][selections][]` bucket, and the legacy
     * `rental_add_ons[]` rows — where a SET ITEM row is the one carrying
     * `set_id` (addon rows never do), matched on either its own product id
     * or the parent set item it was submitted for.
     *
     * @param string $uid        Synthetic selectable uid ("sel-{set}-{product}").
     * @param int    $product_id Set item product id.
     * @return int
     */
    public static function form_variant_for_selectable( $uid, $product_id ) {
        $product_id = (int) $product_id;
        $uid        = (string) $uid;

        if ( '' !== $uid
            && ! empty( $_POST['rental_set_selections'][ $uid ]['selections'] )
            && is_array( $_POST['rental_set_selections'][ $uid ]['selections'] )
        ) {
            foreach ( $_POST['rental_set_selections'][ $uid ]['selections'] as $pick ) {
                if ( is_array( $pick ) && ! empty( $pick['variant_id'] ) ) {
                    return (int) $pick['variant_id'];
                }
            }
        }

        if ( $product_id > 0 && ! empty( $_POST['rental_add_ons'] ) && is_array( $_POST['rental_add_ons'] ) ) {
            foreach ( $_POST['rental_add_ons'] as $entry ) {
                if ( ! is_array( $entry ) || empty( $entry['set_id'] ) || empty( $entry['variant_id'] ) ) {
                    continue;
                }
                $entry_product = isset( $entry['product_id'] ) ? (int) $entry['product_id'] : 0;
                $entry_parent  = isset( $entry['parent_set_item_product_id'] ) ? (int) $entry['parent_set_item_product_id'] : 0;
                if ( $entry_product === $product_id || $entry_parent === $product_id ) {
                    return (int) $entry['variant_id'];
                }
            }
        }

        return 0;
    }

    /**
     * Pick an addon's default variant from its `variants_optional` list.
     * Prefers a variant flagged `default`; falls back to the sole variant.
     * Returns null when neither applies (no default → caller drops the addon).
     *
     * @param array $variants_optional
     * @return array|null { variants_optional, variant_id, product_id, rental_variant_id, rental_product_id }
     */
    public static function resolve_addon_default( array $variants_optional ) {
        $chosen_key = null;
        foreach ( $variants_optional as $k => $v ) {
            if ( ! empty( $v['default'] ) ) {
                $chosen_key = $k;
                break;
            }
        }
        if ( null === $chosen_key && count( $variants_optional ) === 1 ) {
            $chosen_key = array_key_first( $variants_optional );
        }
        if ( null === $chosen_key || ! isset( $variants_optional[ $chosen_key ] ) ) {
            return null;
        }

        $v      = $variants_optional[ $chosen_key ];
        $lookup = ! empty( $v['variant_id'] ) ? (int) $v['variant_id'] : (int) ( $v['product_id'] ?? 0 );
        if ( $lookup <= 0 ) {
            return null;
        }

        $price = function_exists( 'rental_calculate_rental_item_price' ) ? rental_calculate_rental_item_price( $lookup ) : 0;

        foreach ( $variants_optional as $k => $o ) {
            $variants_optional[ $k ]['is_selected'] = ( $k === $chosen_key ) ? 1 : 0;
            $variants_optional[ $k ]['price']       = ( $k === $chosen_key ) ? $price : 0;
        }

        return array(
            'variants_optional' => $variants_optional,
            'variant_id'        => $lookup,
            'product_id'        => (int) ( $v['product_id'] ?? 0 ),
            'rental_variant_id' => $v['rental_variant_id'] ?? 0,
            'rental_product_id' => $v['rental_product_id'] ?? 0,
        );
    }

    /**
     * Apply a customer's selectable-item pick to their overlay.
     *
     * Locks the chosen option on the matching set item (variant + price +
     * has_selected), marks the chosen option `is_selected`, clears the rest,
     * and persists the overlay. Returns the AJAX response payload.
     *
     * @param int $set_id
     * @param int $item_product_id
     * @param int $item_variant_id
     * @return array
     */
    public static function select_optional_item( $set_id, $item_product_id, $item_variant_id ) {
        $set_id          = (int) $set_id;
        $item_product_id = (int) $item_product_id;
        $item_variant_id = (int) $item_variant_id;

        store_set_ids_with_optional_items( $set_id, $item_product_id, $item_variant_id ?: 0 );
        $optional_item_price = rental_calculate_rental_item_price( $item_variant_id ?: $item_product_id, null, true );

        $current_set_items = self::resolve( $set_id );

        foreach ( $current_set_items as $key => $current_set_item ) {
            if (
                $current_set_item['product_id'] == $item_product_id
                && isset( $current_set_item['optional_items'] )
                && $current_set_item['optional_items']
            ) {
                $current_set_items[ $key ]['variant_id']   = $item_variant_id ?: $item_product_id;
                $current_set_items[ $key ]['has_selected'] = 1;
                $current_set_items[ $key ]['price']        = $optional_item_price;
                $current_set_items[ $key ]['attribute']    = $optional_item_price;

                $opt_items = $current_set_items[ $key ]['optional_items'];
                foreach ( $opt_items as $opt_item_key => $opt_item ) {
                    if ( $opt_item['variant_id'] ) {
                        if ( $opt_item['variant_id'] == $item_variant_id ) {
                            $opt_items[ $opt_item_key ]['is_selected'] = 1;
                            $opt_items[ $opt_item_key ]['price']       = $optional_item_price;

                            $current_set_items[ $key ]['rental_variant_id'] = $opt_item['rental_variant_id'] ?? 0;
                            $current_set_items[ $key ]['rental_product_id'] = $opt_item['rental_product_id'] ?? 0;
                        }
                        if ( $opt_item['variant_id'] != $item_variant_id ) {
                            $opt_items[ $opt_item_key ]['is_selected'] = 0;
                            $opt_items[ $opt_item_key ]['price']       = 0;
                        }
                    } else {
                        if ( $opt_item['product_id'] ) {
                            if ( $opt_item['product_id'] == $item_product_id ) {
                                $opt_items[ $opt_item_key ]['is_selected'] = 1;
                                $opt_items[ $opt_item_key ]['price']       = $optional_item_price;

                                $current_set_items[ $key ]['rental_variant_id'] = $opt_item['rental_variant_id'] ?? 0;
                                $current_set_items[ $key ]['rental_product_id'] = $opt_item['rental_product_id'] ?? 0;
                            }
                            if ( $opt_item['product_id'] != $item_product_id ) {
                                $opt_items[ $opt_item_key ]['is_selected'] = 0;
                                $opt_items[ $opt_item_key ]['price']       = 0;
                            }
                        }
                    }
                }

                $current_set_items[ $key ]['optional_items'] = $opt_items;
            }
        }

        self::store_override( $set_id, $current_set_items );

        return array( 'success' => true, 'item_remove_notif' => '' );
    }

    /**
     * Apply a customer's addon-variant pick to their overlay.
     *
     * Marks the matching addon (by rental_inv_id) selected, locks the chosen
     * variant + price, clears the other addon options, deselects sibling
     * addons that aren't already locked, flags the set as having
     * in-process variant addons, and persists the overlay. Returns the AJAX
     * response payload.
     *
     * @param int $set_id
     * @param int $selected_item_product_id   Parent set item product id.
     * @param int $selected_addon_product_id
     * @param int $selected_addon_variant_id
     * @param int $selected_rental_inv_id
     * @return array
     */
    public static function select_addon_variant( $set_id, $selected_item_product_id, $selected_addon_product_id, $selected_addon_variant_id, $selected_rental_inv_id ) {
        $set_id                    = (int) $set_id;
        $selected_item_product_id  = (int) $selected_item_product_id;
        $selected_addon_product_id = (int) $selected_addon_product_id;
        $selected_addon_variant_id = (int) $selected_addon_variant_id;
        $selected_rental_inv_id    = (int) $selected_rental_inv_id;

        $optional_item_price = rental_calculate_rental_item_price( $selected_addon_variant_id ?: $selected_addon_product_id, null, true );

        $current_set_items = self::resolve( $set_id );

        foreach ( $current_set_items as $key => $current_set_item ) {
            if (
                $current_set_item['product_id'] == $selected_item_product_id
                && isset( $current_set_item['addons'] )
                && $current_set_item['addons']
            ) {
                foreach ( $current_set_item['addons'] as $item_addon_key => $item_addon ) {
                    if (
                        isset( $item_addon['variants_optional'] )
                        && $item_addon['variants_optional']
                        && $item_addon['rental_inv_id'] == $selected_rental_inv_id
                    ) {
                        $current_set_item['addons'][ $item_addon_key ]['has_selected']     = 1;
                        $current_set_item['addons'][ $item_addon_key ]['already_selected'] = 1;

                        $item_addon_optional_items = $item_addon['variants_optional'];
                        foreach ( $item_addon_optional_items as $item_addon_optional_item_key => $item_addon_optional_item ) {
                            if ( $item_addon_optional_item['variant_id'] && $selected_addon_variant_id ) {
                                if ( $item_addon_optional_item['variant_id'] == $selected_addon_variant_id ) {
                                    $item_addon_optional_items[ $item_addon_optional_item_key ]['is_selected'] = 1;
                                    $item_addon_optional_items[ $item_addon_optional_item_key ]['price']       = $optional_item_price;

                                    $current_set_item['addons'][ $item_addon_key ]['variant_id'] = $selected_addon_variant_id;
                                    $current_set_item['addons'][ $item_addon_key ]['product_id'] = $selected_addon_product_id;

                                    $current_set_item['addons'][ $item_addon_key ]['rental_variant_id'] = $item_addon_optional_item['rental_variant_id'] ?? 0;
                                    $current_set_item['addons'][ $item_addon_key ]['rental_product_id'] = $item_addon_optional_item['rental_product_id'] ?? 0;
                                }
                                if ( $item_addon_optional_item['variant_id'] != $selected_addon_variant_id ) {
                                    $item_addon_optional_items[ $item_addon_optional_item_key ]['is_selected'] = 0;
                                    $item_addon_optional_items[ $item_addon_optional_item_key ]['price']       = 0;
                                }
                            } else {
                                if ( $item_addon_optional_item['product_id'] ) {
                                    if ( $item_addon_optional_item['product_id'] == $selected_addon_product_id ) {
                                        $item_addon_optional_items[ $item_addon_optional_item_key ]['is_selected'] = 1;
                                        $item_addon_optional_items[ $item_addon_optional_item_key ]['price']       = $optional_item_price;

                                        $current_set_item['addons'][ $item_addon_key ]['variant_id'] = $selected_addon_product_id;
                                        $current_set_item['addons'][ $item_addon_key ]['product_id'] = $selected_addon_product_id;

                                        $current_set_item['addons'][ $item_addon_key ]['rental_variant_id'] = $item_addon_optional_item['rental_variant_id'] ?? 0;
                                        $current_set_item['addons'][ $item_addon_key ]['rental_product_id'] = $item_addon_optional_item['rental_product_id'] ?? 0;
                                    }
                                    if ( $item_addon_optional_item['product_id'] != $selected_addon_product_id ) {
                                        $item_addon_optional_items[ $item_addon_optional_item_key ]['is_selected'] = 0;
                                        $item_addon_optional_items[ $item_addon_optional_item_key ]['price']       = 0;
                                    }
                                }
                            }
                        }

                        $current_set_item['addons'][ $item_addon_key ]['variants_optional'] = $item_addon_optional_items;
                    } else {
                        if ( ! isset( $current_set_item['addons'][ $item_addon_key ]['already_selected'] ) || ( isset( $current_set_item['addons'][ $item_addon_key ]['already_selected'] ) && $current_set_item['addons'][ $item_addon_key ]['already_selected'] != 1 ) ) {
                            $current_set_item['addons'][ $item_addon_key ]['has_selected'] = 0;
                        }
                    }
                }

                $current_set_items[ $key ]['addons'] = $current_set_item['addons'];
            }
        }

        $sets_with_variant_addons = get_option( '_rental_sets_with_variant_addons_exist_in_process', array() );
        if ( ! in_array( $set_id, $sets_with_variant_addons ) ) {
            $sets_with_variant_addons[] = $set_id;
            update_option( '_rental_sets_with_variant_addons_exist_in_process', $sets_with_variant_addons );
        }

        self::store_override( $set_id, $current_set_items );

        return array( 'success' => true, 'item_remove_notif' => '' );
    }

    /**
     * Lock default variants for hidden / single-option addons in the overlay.
     *
     * For each addon that is hidden or has fewer than two variant options and
     * is not already selected, selects its first option, prices it (honoring
     * a non-inherited custom price), clears the rest, and persists. Returns
     * the AJAX response payload.
     *
     * @param int $set_id
     * @return array
     */
    public static function apply_addon_defaults( $set_id ) {
        $set_id = (int) $set_id;

        if ( ! get_option( 'rental_set_items_have_addons', false ) ) {
            return array( 'success' => 'Sets do not have addons!' );
        }

        $current_set_items = self::resolve( $set_id );

        foreach ( $current_set_items as $key => $current_set_item ) {
            if (
                isset( $current_set_item['addons'] )
                && $current_set_item['addons']
            ) {
                foreach ( $current_set_item['addons'] as $item_addon_key => $item_addon ) {
                    $count_item_addon_variants_optional = 0;
                    if ( ! empty( $item_addon['variants_optional'] ) ) {
                        $count_item_addon_variants_optional = count( $item_addon['variants_optional'] );
                    }

                    if (
                        ! empty( $item_addon['variants_optional'] )
                        && (
                            ( isset( $item_addon['hidden'] ) && $item_addon['hidden'] )
                            || $count_item_addon_variants_optional < 2
                        )
                    ) {
                        if (
                            ( isset( $item_addon['has_selected'] ) && $item_addon['has_selected'] == 1 )
                            || ( isset( $item_addon['already_selected'] ) && $item_addon['already_selected'] == 1 )
                        ) {
                            continue;
                        }

                        $custom_price = $item_addon['inherit_price'] ? null : $item_addon['price'];

                        $current_set_item['addons'][ $item_addon_key ]['has_selected']     = 1;
                        $current_set_item['addons'][ $item_addon_key ]['already_selected'] = 1;

                        $item_addon_optional_items = $item_addon['variants_optional'];
                        foreach ( $item_addon_optional_items as $item_addon_optional_item_key => $item_addon_optional_item ) {
                            if ( $item_addon_optional_item['variant_id'] ) {
                                if ( $item_addon_optional_item_key == 0 ) {
                                    $optional_item_price = rental_calculate_rental_item_price( $item_addon_optional_item['variant_id'], $custom_price );

                                    $item_addon_optional_items[ $item_addon_optional_item_key ]['is_selected'] = 1;
                                    $item_addon_optional_items[ $item_addon_optional_item_key ]['price']       = $optional_item_price;

                                    $current_set_item['addons'][ $item_addon_key ]['variant_id'] = $item_addon_optional_item['variant_id'];
                                    $current_set_item['addons'][ $item_addon_key ]['product_id'] = $item_addon_optional_item['product_id'];

                                    $current_set_item['addons'][ $item_addon_key ]['rental_variant_id'] = $item_addon_optional_item['rental_variant_id'] ?? 0;
                                    $current_set_item['addons'][ $item_addon_key ]['rental_product_id'] = $item_addon_optional_item['rental_product_id'] ?? 0;
                                } else {
                                    $item_addon_optional_items[ $item_addon_optional_item_key ]['is_selected'] = 0;
                                    $item_addon_optional_items[ $item_addon_optional_item_key ]['price']       = 0;
                                }
                            } else {
                                if ( $item_addon_optional_item['product_id'] ) {
                                    if ( $item_addon_optional_item_key == 0 ) {
                                        $optional_item_price = rental_calculate_rental_item_price( $item_addon_optional_item['product_id'], $custom_price );

                                        $item_addon_optional_items[ $item_addon_optional_item_key ]['is_selected'] = 1;
                                        $item_addon_optional_items[ $item_addon_optional_item_key ]['price']       = $optional_item_price;

                                        $current_set_item['addons'][ $item_addon_key ]['variant_id'] = $item_addon_optional_item['product_id'];
                                        $current_set_item['addons'][ $item_addon_key ]['product_id'] = $item_addon_optional_item['product_id'];

                                        $current_set_item['addons'][ $item_addon_key ]['rental_variant_id'] = $item_addon_optional_item['rental_variant_id'] ?? 0;
                                        $current_set_item['addons'][ $item_addon_key ]['rental_product_id'] = $item_addon_optional_item['rental_product_id'] ?? 0;
                                    } else {
                                        $item_addon_optional_items[ $item_addon_optional_item_key ]['is_selected'] = 0;
                                        $item_addon_optional_items[ $item_addon_optional_item_key ]['price']       = 0;
                                    }
                                }
                            }
                        }

                        $current_set_item['addons'][ $item_addon_key ]['variants_optional'] = $item_addon_optional_items;
                    } else {
                        if ( ! isset( $current_set_item['addons'][ $item_addon_key ]['already_selected'] ) || ( isset( $current_set_item['addons'][ $item_addon_key ]['already_selected'] ) && $current_set_item['addons'][ $item_addon_key ]['already_selected'] != 1 ) ) {
                            $current_set_item['addons'][ $item_addon_key ]['has_selected'] = 0;
                        }
                    }
                }

                $current_set_items[ $key ]['addons'] = $current_set_item['addons'];
            }
        }

        $sets_with_variant_addons = get_option( '_rental_sets_with_variant_addons_exist_in_process', array() );
        if ( ! in_array( $set_id, $sets_with_variant_addons ) ) {
            $sets_with_variant_addons[] = $set_id;
            update_option( '_rental_sets_with_variant_addons_exist_in_process', $sets_with_variant_addons );
        }

        self::store_override( $set_id, $current_set_items );

        return array( 'success' => true, 'item_remove_notif' => '' );
    }

    /**
     * Refresh prices for already-selected options and sole-option items.
     *
     * For each item flagged `optional_item_price_update_needed == 1` that has
     * a selection (or a single option), recomputes the chosen option's price
     * into the overlay and advances the flag to `2` so it runs once. Returns
     * the AJAX response payload.
     *
     * @param int $set_id
     * @return array
     */
    public static function refresh_already_selected( $set_id ) {
        $set_id = (int) $set_id;

        if ( ! get_post_meta( $set_id, '_rental_set_items_have_optional_items', true ) ) {
            return array( 'success' => 'There are no optional items!' );
        }

        $current_set_items = self::resolve( $set_id );

        foreach ( $current_set_items as $key => $current_set_item ) {
            if ( $current_set_item['optional_item_price_update_needed'] != 1 ) {
                continue;
            }

            $optional_items_count       = isset( $current_set_item['optional_items'] ) && $current_set_item['optional_items'] ? count( $current_set_item['optional_items'] ) : 0;
            $select_the_only_one_option = $optional_items_count == 1 ? true : false;

            if (
                isset( $current_set_item['optional_items'] )
                && $current_set_item['optional_items']
                && $current_set_item['optional_item_price_update_needed'] == 1
                && (
                    $current_set_item['has_selected'] == 1
                    || $select_the_only_one_option
                )
            ) {
                $current_set_items[ $key ]['optional_item_price_update_needed'] = 2;

                $already_selected_variant_id = 0;
                $already_selected_product_id = 0;

                $opt_items = $current_set_items[ $key ]['optional_items'];
                foreach ( $opt_items as $opt_item_key => $opt_item ) {
                    if ( $select_the_only_one_option ) {
                        if ( $opt_item_key == 0 ) {
                            if ( $opt_item['variant_id'] ) {
                                $already_selected_variant_id = $opt_item['variant_id'];
                                $already_selected_product_id = $current_set_item['product_id'];

                                $optional_item_price = rental_calculate_rental_item_price( $already_selected_variant_id );

                                $opt_items[ $opt_item_key ]['price'] = $optional_item_price;

                                $current_set_items[ $key ]['price']     = $optional_item_price;
                                $current_set_items[ $key ]['attribute'] = '';

                                $opt_items[ $opt_item_key ]['is_selected'] = 1;
                                $current_set_items[ $key ]['has_selected'] = 1;

                                $current_set_items[ $key ]['rental_variant_id'] = $opt_item['rental_variant_id'] ?? 0;
                                $current_set_items[ $key ]['rental_product_id'] = $opt_item['rental_product_id'] ?? 0;
                            } else {
                                if ( $opt_item['product_id'] ) {
                                    $already_selected_variant_id = $opt_item['product_id'];
                                    $already_selected_product_id = $current_set_item['product_id'];

                                    $optional_item_price = rental_calculate_rental_item_price( $already_selected_variant_id );

                                    $opt_items[ $opt_item_key ]['price'] = $optional_item_price;

                                    $current_set_items[ $key ]['price']     = $optional_item_price;
                                    $current_set_items[ $key ]['attribute'] = '';

                                    $opt_items[ $opt_item_key ]['is_selected'] = 1;
                                    $current_set_items[ $key ]['has_selected'] = 1;

                                    $current_set_items[ $key ]['rental_variant_id'] = $opt_item['rental_variant_id'] ?? 0;
                                    $current_set_items[ $key ]['rental_product_id'] = $opt_item['rental_product_id'] ?? 0;
                                }
                            }
                        }
                    } else {
                        if ( $opt_item['is_selected'] == 1 ) {
                            if ( $opt_item['variant_id'] ) {
                                if ( $opt_item['variant_id'] == $current_set_item['variant_id'] ) {
                                    $already_selected_variant_id = $current_set_item['variant_id'];
                                    $already_selected_product_id = $current_set_item['product_id'];

                                    $optional_item_price = rental_calculate_rental_item_price( $already_selected_variant_id );

                                    $opt_items[ $opt_item_key ]['price'] = $optional_item_price;

                                    $current_set_items[ $key ]['price']     = $optional_item_price;
                                    $current_set_items[ $key ]['attribute'] = '';

                                    $current_set_items[ $key ]['rental_variant_id'] = $opt_item['rental_variant_id'] ?? 0;
                                    $current_set_items[ $key ]['rental_product_id'] = $opt_item['rental_product_id'] ?? 0;
                                }
                            } else {
                                if ( $opt_item['product_id'] ) {
                                    if ( $opt_item['product_id'] == $current_set_item['product_id'] ) {
                                        $already_selected_variant_id = $current_set_item['product_id'];
                                        $already_selected_product_id = $current_set_item['product_id'];

                                        $optional_item_price = rental_calculate_rental_item_price( $already_selected_variant_id );

                                        $opt_items[ $opt_item_key ]['price'] = $optional_item_price;

                                        $current_set_items[ $key ]['price']     = $optional_item_price;
                                        $current_set_items[ $key ]['attribute'] = '';

                                        $current_set_items[ $key ]['rental_variant_id'] = $opt_item['rental_variant_id'] ?? 0;
                                        $current_set_items[ $key ]['rental_product_id'] = $opt_item['rental_product_id'] ?? 0;
                                    }
                                }
                            }
                        }
                    }
                }

                $current_set_items[ $key ]['optional_items'] = $opt_items;

                store_set_ids_with_optional_items( $set_id, $already_selected_product_id, $already_selected_variant_id );
            }
        }

        self::store_override( $set_id, $current_set_items );

        return array( "set's already selected optional items (and optional items with only one item) prices updated" => true );
    }
}

endif;
