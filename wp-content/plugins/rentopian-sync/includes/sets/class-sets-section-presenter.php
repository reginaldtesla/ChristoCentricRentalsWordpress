<?php
/**
 * Rental_Sets_Section_Presenter
 *
 * Unifies the three entity types into one ordered list of "sections"
 * that the renderer's partials consume.
 *
 * The set's `_rental_set_order` postmeta is a JSON array of UIDs in the
 * admin-defined display order. We walk it once, looking up each UID in:
 *
 *   1. Composite groups (`_rental_set_grouped_items`) → group section
 *   2. Selectable items (entries in `_rental_set_items` with optional_items) → synthetic group section
 *   3. Simple items (entries in `_rental_set_items` with concrete inv id) → fixed-item section
 *
 * Anything not found via UID falls through into a tail pass that yields
 * remaining items in source order — defensive, in case an admin re-saves
 * a set without re-uploading set_order.
 *
 * Section types emitted (consumed by Renderer):
 *
 *   - fixed_item   — simple required item, no choice
 *   - dropdown     — single-select group/selectable, qty fixed at 1
 *   - dropdown_qty — single-select group with allowed qty > 1
 *   - multi_select — multi_selection group (cards with qty steppers)
 *
 * Hidden sections (`hide_on_website`) are filtered out before yielding.
 *
 * @package RentopianSync\Sets
 * @since   2.14.7
 *
 * PHP requirement: 7.4+
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'Rental_Sets_Section_Presenter', false ) ) :

class Rental_Sets_Section_Presenter {

    const TYPE_FIXED_ITEM   = 'fixed_item';
    const TYPE_DROPDOWN     = 'dropdown';
    const TYPE_DROPDOWN_QTY = 'dropdown_qty';
    const TYPE_MULTI_SELECT = 'multi_select';

    /** @var int */
    protected $set_id;

    /** @var array */
    protected $set_items;

    /** @var array */
    protected $grouped_items;

    /** @var string[] */
    protected $set_order;

    /**
     * Whether the set parent product has `_rental_item_based_total = 1`.
     * When true, each customer-chosen child contributes its own price
     * to the parent total, so per-option prices on dropdowns become
     * meaningful and we surface them in the option labels.
     *
     * @var bool
     */
    protected $item_based_total = false;

    /**
     * Whether the whole set hides its items on the website (the per-set
     * `_rental_hide_items_on_website` flag, or the global
     * `rental_hide_set_items` option). When true the configurator emits no
     * sections — the set's contents are added silently as a bundle and the
     * customer only sees the set + Add to Cart.
     *
     * @var bool
     */
    protected $hide_all_items = false;

    /**
     * Whether variant choices drop the repeated product-name prefix
     * ({@see Rental_Sets_Admin_Settings::hides_variant_product_name}).
     * Only affects labels inside a list of choices — never a section
     * title, and never a label whose prefix is the sole differentiator.
     *
     * @var bool
     */
    protected $hide_variant_product_name = false;

    public function __construct( $set_id, array $set_items, array $grouped_items, array $set_order, $item_based_total = false, $hide_all_items = false, $hide_variant_product_name = false ) {
        $this->set_id           = (int) $set_id;
        $this->set_items        = $set_items;
        $this->grouped_items    = $grouped_items;
        $this->set_order        = $set_order;
        $this->item_based_total = (bool) $item_based_total;
        $this->hide_all_items   = (bool) $hide_all_items;
        $this->hide_variant_product_name = (bool) $hide_variant_product_name;
    }

    /**
     * Build from postmeta of a given set product.
     *
     * @param int $set_id
     * @return self
     */
    public static function from_meta( $set_id ) {
        $set_id = (int) $set_id;
        // Selection-aware read: the customer's in-progress option picks
        // live in their session overlay, not the global postmeta. The
        // product page must render THEIR selection, so resolve through the
        // session store (falls back to the pristine postmeta definition).
        $items  = function_exists( 'rental_set_items_resolved' )
            ? rental_set_items_resolved( $set_id )
            : get_post_meta( $set_id, '_rental_set_items', true );
        $groups = get_post_meta( $set_id, '_rental_set_grouped_items', true );
        $order_raw = get_post_meta( $set_id, '_rental_set_order', true );
        $item_based_total = (bool) get_post_meta( $set_id, '_rental_item_based_total', true );

        // Whole-set hide: per-set flag OR the global option. When on, the
        // configurator renders nothing (items are added as a hidden bundle).
        $hide_all_items = (bool) get_post_meta( $set_id, '_rental_hide_items_on_website', true )
            || (bool) get_option( 'rental_hide_set_items', 0 );

        $order = array();
        if ( is_string( $order_raw ) && '' !== $order_raw ) {
            $decoded = json_decode( $order_raw, true );
            if ( is_array( $decoded ) ) {
                $order = $decoded;
            }
        } elseif ( is_array( $order_raw ) ) {
            $order = $order_raw;
        }

        return new self(
            $set_id,
            is_array( $items ) ? $items : array(),
            is_array( $groups ) ? $groups : array(),
            array_map( 'strval', $order ),
            $item_based_total,
            $hide_all_items,
            class_exists( 'Rental_Sets_Admin_Settings', false )
                ? Rental_Sets_Admin_Settings::hides_variant_product_name()
                : false
        );
    }

    /**
     * Yield sections in display order.
     *
     * @return array<int,array>  Each section has at minimum: type, uid, title, items[].
     */
    public function sections() {
        // Whole-set hide: the customer configures nothing — the set's items
        // are added silently as a bundle by the cart synthesizer (which
        // resolves their hidden defaults server-side). Mirrors classic,
        // which renders no item table when the set hides its items.
        if ( $this->hide_all_items ) {
            return array();
        }

        $by_uid_groups = array();
        foreach ( $this->grouped_items as $g ) {
            $u = isset( $g['uid'] ) ? (string) $g['uid'] : '';
            if ( '' !== $u ) {
                $by_uid_groups[ $u ] = $g;
            }
        }

        // Source order = the order Laravel's bulk-feed `GROUP_CONCAT`
        // returned rows in, which without an explicit ORDER BY tends
        // to be inventory_sets_relation.id ASC (insertion order). That
        // approximates admin order well enough for sets where the
        // admin hasn't drag-reordered after creation. A previous
        // attempt sorted by `rental_product_id` here to stabilise
        // output, but that produced an order that didn't match what
        // the admin sees — IDs increment with creation, not with the
        // admin's drag-and-drop position.
        //
        // The PROPER fix is server-side: have InventorySets::getForApiRaw
        // ship a `set_order` list (an array of UIDs in admin display
        // order) OR add `ORDER BY inventory_sets_relation.position` to
        // the items SQL. Once set_order is present, Pass 1 below
        // honours it. Until then we preserve source order so the
        // shuffling stays predictable.
        $sortable_set_items = $this->set_items;

        // Selectable items use a synthetic UID convention shared with
        // Rule_Selectable_Items: "sel-{set_id}-{product_id}".
        $by_uid_selectable = array();
        foreach ( $sortable_set_items as $item ) {
            if ( empty( $item['optional_items'] ) ) {
                continue;
            }
            $synth = $this->synthetic_uid_for_selectable( $item );
            $by_uid_selectable[ $synth ] = $item;
        }

        // Simple items keyed by their own uid when present, or
        // "{division}-{inv_id}" derived as fallback.
        $by_uid_simple = array();
        foreach ( $sortable_set_items as $item ) {
            if ( ! empty( $item['optional_items'] ) ) {
                continue;
            }
            $u = $this->resolve_simple_item_uid( $item );
            if ( '' !== $u ) {
                $by_uid_simple[ $u ] = $item;
            }
        }

        $emitted_uids = array();
        $sections     = array();

        // Build a Laravel→WP UID translation map. Laravel's set_order ships
        // its OWN identifier format —
        //
        //   simple items:     {division_id}-{rental_product_id}-{inv_id}-rt-1-0
        //   selectable items: {division_id}-{rental_product_id}-0-rt-0-0
        //   groups:           {division_id}-grp-{group_uid}
        //
        // — and groups already match because WP keys them the same way.
        // The item form does NOT match anything WP synthesizes locally
        // (selectables use `sel-{inventory_sets_id}-{product_id}`, simples
        // use `{division}-{inv_id}`). Without a translator, Pass 1 matches
        // only the groups and Pass 2 silently falls back to items-SQL
        // source order — the symptom: "set_order_len > 0 but render order
        // never updates."
        //
        // Per-kind key shape (this matters — they differ in the inv slot):
        //
        //   - Simple items. WP stores the concrete `inv_id` (= Laravel's
        //     inventory.id for the picked variant). Laravel ships the
        //     same number in set_order. Strict triplet: {div}-{rpid}-{inv}.
        //
        //   - Selectables. The wrapper has NO inventory of its own —
        //     each `optional_items[]` row carries its own inv_id. Laravel
        //     normalises that to **inv_id = 0** in set_order (verified
        //     2026-06-04: `61-6748-0-rt-0-0` for sel-0-6137 even though
        //     WP persists `inv_id = 64122` from the bulk feed, that being
        //     the default option's inventory_id). We register the wrapper
        //     form `{div}-{rpid}-0` to mirror Laravel's convention,
        //     ignoring the (option-level) inv_id WP happens to hold —
        //     trying to match against the stored inv would never line up.
        //
        // Then `resolve_set_order_uid()` strips the `-rt-…` tail from each
        // set_order entry (those flags aren't part of the identity — keeps
        // the matcher resilient to any future flag changes Laravel makes)
        // and looks the core up.
        
        // resolve_set_order_uid() normalises each set_order entry to the
        // same `{rpid}-{inv}` shape before lookup.
        $laravel_to_wp = array();
        foreach ( $by_uid_selectable as $wp_uid => $item ) {
            $rpid = isset( $item['rental_product_id'] ) ? (int) $item['rental_product_id'] : 0;
            if ( $rpid > 0 ) {
                // Wrapper form: Laravel normalises selectable inv to 0.
                $laravel_to_wp[ $rpid . '-0' ] = (string) $wp_uid;
            }
        }
        foreach ( $by_uid_simple as $wp_uid => $item ) {
            $rpid = isset( $item['rental_product_id'] ) ? (int) $item['rental_product_id'] : 0;
            $inv  = isset( $item['inv_id'] )            ? (int) $item['inv_id']            : 0;
            if ( $rpid > 0 ) {
                // Strict pair: Laravel ships the same concrete inv.
                $laravel_to_wp[ $rpid . '-' . $inv ] = (string) $wp_uid;
            }
        }

        // Pass 1: walk admin-defined order. Each set_order entry gets
        // normalized + bridged through the translation map; if no bridge
        // applies we fall back to the entry itself (covers groups, which
        // are already in WP-compatible form, and any future entry types
        // that ship pre-translated). We log a single summary line so the
        // operator can confirm matched=N missed=0 after this change.
        $matched_uids = array();
        $missed_uids  = array();
        foreach ( $this->set_order as $uid ) {
            $resolved = $this->resolve_set_order_uid( (string) $uid, $laravel_to_wp );
            $section  = $this->section_for_uid( $resolved, $by_uid_groups, $by_uid_selectable, $by_uid_simple );
            if ( null !== $section ) {
                $sections[]                  = $section;
                $emitted_uids[ $resolved ]   = true;
                $matched_uids[]              = (string) $uid;
            } else {
                $missed_uids[] = (string) $uid;
            }
        }

        // Safety-net log: fires only when Pass 1 misses a set_order
        // entry. Silent on the happy path. If Laravel introduces a new
        // entry shape in the future (e.g. an addon variant), this is
        // what surfaces it — paste the missed entries + laravel_keys
        // into a follow-up and the translator can be extended in one
        // round-trip without needing a fresh full-diagnostic pass.
        if ( ! empty( $missed_uids ) && class_exists( 'Rental_Sets_Logger', false ) ) {
            Rental_Sets_Logger::warn(
                'set_order_unmatched',
                sprintf( 'pass1 missed=%d / %d', count( $missed_uids ), count( $this->set_order ) ),
                array(
                    'set'          => $this->set_id,
                    'missed'       => implode( ',', array_slice( $missed_uids, 0, 8 ) ),
                    'laravel_keys' => implode( ',', array_slice( array_keys( $laravel_to_wp ), 0, 8 ) ),
                )
            );
        }

        // Pass 2: anything not in set_order, in source order.
        foreach ( $by_uid_groups as $uid => $g ) {
            if ( isset( $emitted_uids[ $uid ] ) ) {
                continue;
            }
            $sec = $this->build_group_section( $g );
            if ( null !== $sec ) {
                $sections[] = $sec;
            }
        }
        foreach ( $by_uid_selectable as $uid => $item ) {
            if ( isset( $emitted_uids[ $uid ] ) ) {
                continue;
            }
            $sec = $this->build_selectable_section( $item );
            if ( null !== $sec ) {
                $sections[] = $sec;
            }
        }
        foreach ( $by_uid_simple as $uid => $item ) {
            if ( isset( $emitted_uids[ $uid ] ) ) {
                continue;
            }
            $sec = $this->build_simple_section( $item );
            if ( null !== $sec ) {
                $sections[] = $sec;
            }
        }

        if ( class_exists( 'Rental_Sets_Logger', false ) ) {
            // Snapshot the section makeup so the operator can confirm
            // counts match expectations after a re-sync. Section types
            // are joined into a short comma list to keep the line
            // grep-friendly.
            $types = array();
            foreach ( $sections as $s ) {
                $types[] = isset( $s['type'] ) ? (string) $s['type'] : '?';
            }
            Rental_Sets_Logger::render( $this->set_id, array(
                'sections'         => count( $sections ),
                'types'            => implode( ',', $types ),
                'simple'           => count( $by_uid_simple ),
                'selectable'       => count( $by_uid_selectable ),
                'groups'           => count( $by_uid_groups ),
                'set_order_len'    => count( $this->set_order ),
                'item_based_total' => $this->item_based_total ? 1 : 0,
            ) );
        }

        return $sections;
    }

    /**
     * Look up a UID in the three maps and produce its section.
     *
     * @return array|null
     */
    protected function section_for_uid( $uid, $by_uid_groups, $by_uid_selectable, $by_uid_simple ) {
        if ( isset( $by_uid_groups[ $uid ] ) ) {
            return $this->build_group_section( $by_uid_groups[ $uid ] );
        }
        if ( isset( $by_uid_selectable[ $uid ] ) ) {
            return $this->build_selectable_section( $by_uid_selectable[ $uid ] );
        }
        if ( isset( $by_uid_simple[ $uid ] ) ) {
            return $this->build_simple_section( $by_uid_simple[ $uid ] );
        }
        return null;
    }

    /**
     * Translate one `_rental_set_order` entry into the WP-side UID that
     * `section_for_uid()` looks up against the three section maps.
     *
     *   - Groups ship in WP-compatible form already
     *     (`{division}-grp-{group_uid}`) → returned untouched.
     *   - Item entries from Laravel's `getForApiRaw`
     *     (`{division}-{rental_product_id}-{inv_id}-rt-{flag}-{flag}`)
     *     are stripped of the `-rt-…` tail and bridged through the
     *     translation map built in `sections()` from each WP item's
     *     stored ids. The trailing flags aren't part of the identity, so
     *     we ignore them — keeps the matcher resilient to any future
     *     flag changes Laravel makes to that tail.
     *   - Anything that's already a WP-native UID (e.g.
     *     `sel-0-6146`, `61-64081`) passes through and is matched
     *     directly by `section_for_uid()`.
     *
     * @param string              $uid
     * @param array<string,string> $laravel_to_wp  Laravel item key → WP UID.
     * @return string
     */
    protected function resolve_set_order_uid( $uid, array $laravel_to_wp ) {
        // Groups: keep as-is.
        if ( false !== strpos( $uid, '-grp-' ) ) {
            return $uid;
        }
        // Items: strip the `-rt-…` tail before the lookup so we don't
        // depend on what those trailing flags encode.
        $core = $uid;
        $pos  = strpos( $uid, '-rt-' );
        if ( false !== $pos ) {
            $core = substr( $uid, 0, $pos );
        }

        // Normalise to the DIVISION-AGNOSTIC `{rpid}-{inv}` key shape the
        // map uses. Laravel's set_order ships the core inconsistently as
        // either `{div}-{rpid}-{inv}` (3 numeric segments) or
        // `{rpid}-{inv}` (2 segments). Collapse the 3-segment form by
        // dropping the leading division segment so both match the same
        // key. (Group UIDs already returned above; selectable inv is 0.)
        $segments = explode( '-', $core );
        if ( 3 === count( $segments )
            && ctype_digit( $segments[0] )
            && ctype_digit( $segments[1] )
            && ctype_digit( $segments[2] ) ) {
            // {div}-{rpid}-{inv} → {rpid}-{inv}
            $normalized = $segments[1] . '-' . $segments[2];
        } else {
            // Already {rpid}-{inv}, or some other shape — use verbatim.
            $normalized = $core;
        }

        if ( isset( $laravel_to_wp[ $normalized ] ) ) {
            return $laravel_to_wp[ $normalized ];
        }
        // Fall back to the raw core too (covers any pre-normalised or
        // already-WP-native entry), then pass through unchanged so
        // section_for_uid() can still try a direct match.
        if ( isset( $laravel_to_wp[ $core ] ) ) {
            return $laravel_to_wp[ $core ];
        }
        return $uid;
    }

    /**
     * Build a section from a composite group. Picks the appropriate
     * type based on multiple_selection / group_quantity.
     *
     * @param array $g
     * @return array|null
     */
    protected function build_group_section( $g ) {
        if ( ! empty( $g['hide_on_website'] ) ) {
            return null;
        }

        $multi    = ! empty( $g['multiple_selection'] );
        $g_qty    = isset( $g['group_quantity'] ) ? (int) $g['group_quantity'] : 1;
        $qmax     = isset( $g['group_quantity_max'] ) ? (int) $g['group_quantity_max'] : 0;
        $qmin     = isset( $g['group_quantity_min'] ) ? (int) $g['group_quantity_min'] : 0;

        if ( $multi ) {
            $type = self::TYPE_MULTI_SELECT;
        } else {
            // Single-pick groups (entity-3, multiple_selection=0) ALWAYS
            // render with the quantity stepper so the customer can set
            // "how many of this pick they want".
            // Selectables (entity-2) are NOT affected — they dispatch
            // through `build_selectable_section`, not here.
            $type = self::TYPE_DROPDOWN_QTY;
        }

        // Composite-group price override (added 2026-06-05). A non-
        // empty `group_price` (including the literal 0) on the group
        // means: every option in this group bills at that rate
        // regardless of the option's own variant price. Pass it down
        // to normalise_group_item so the dropdown label echoes the
        // override consistently with what the cart will charge.
        // Empty / null = no override (each item shows its own price).
        $group_price_override = ( isset( $g['group_price'] ) && '' !== $g['group_price'] && is_numeric( $g['group_price'] ) )
            ? $g['group_price']
            : null;

        // Order authority, strongest first:
        //
        //   1. Explicit per-item `ordering` integer — stamped at sync
        //      time by Group_Builder / Webhook_Group_Persister from the
        //      System's drag-drop `items_order`. This is the canonical
        //      "System is the single source of truth" path: the order is
        //      frozen as a stored integer, immune to any array-iteration
        //      or DB-insertion order. Items are pre-sorted by it at sync
        //      time; we re-sort here too so a render never depends on the
        //      stored array order.
        //
        //   2. Legacy `items_order` UID-list reorder — for postmeta
        //      written BEFORE the `ordering` field existed (i.e. synced
        //      on an older plugin build, not yet re-synced). Applied only
        //      when no item carries an `ordering` value.
        //
        //   3. Payload/source order — last resort.
        $raw_items = ( ! empty( $g['items'] ) && is_array( $g['items'] ) ) ? $g['items'] : array();

        $has_explicit_ordering = false;
        foreach ( $raw_items as $it ) {
            if ( is_array( $it ) && isset( $it['ordering'] ) ) {
                $has_explicit_ordering = true;
                break;
            }
        }

        if ( $has_explicit_ordering ) {
            usort( $raw_items, function ( $a, $b ) {
                $oa = ( is_array( $a ) && isset( $a['ordering'] ) ) ? (int) $a['ordering'] : PHP_INT_MAX;
                $ob = ( is_array( $b ) && isset( $b['ordering'] ) ) ? (int) $b['ordering'] : PHP_INT_MAX;
                return $oa <=> $ob;
            } );
        }

        $items = array();
        foreach ( $raw_items as $it ) {
            $items[] = $this->normalise_group_item( $it, $group_price_override );
        }

        // Legacy fallback: only when the items predate the explicit
        // `ordering` field. Newer postmeta is already correctly sorted
        // above, so skipping this avoids a redundant (and potentially
        // UID-format-sensitive) second reorder.
        if ( ! $has_explicit_ordering ) {
            $order_uids = array();
            if ( ! empty( $g['items_order'] ) && is_array( $g['items_order'] ) ) {
                $order_uids = array_map( 'strval', $g['items_order'] );
            }
            if ( ! empty( $order_uids ) ) {
                $items = $this->reorder_items_by_uid( $items, $order_uids );
            }
        }

        $title = isset( $g['group_name'] ) ? (string) $g['group_name'] : '';
        $items = $this->apply_variant_label_display( $items, $title );

        return array(
            'type'               => $type,
            'origin'             => 'group',
            'uid'                => isset( $g['uid'] ) ? (string) $g['uid'] : '',
            'title'              => $title,
            'description'        => isset( $g['group_description'] ) ? (string) $g['group_description'] : '',
            'required'           => ! empty( $g['required'] ),
            'multiple_selection' => $multi,
            'group_id'           => isset( $g['rental_group_id'] ) ? (int) $g['rental_group_id'] : 0,
            'group_quantity'     => $g_qty,
            'group_price'        => isset( $g['group_price'] ) ? $g['group_price'] : null,
            'quantity_min'       => isset( $g['group_quantity_min'] ) ? (int) $g['group_quantity_min'] : 0,
            'quantity_max'       => $qmax,
            'items'              => $items,
        );
    }

    /**
     * Build a section from a selectable item — rendered as single-select
     * synthetic group, with a `sel-{set_id}-{product_id}` UID convention
     * so it slots into the same `rental_set_selections` payload shape
     * that real composite groups use.
     *
     * @param array $item
     * @return array|null
     */
    protected function build_selectable_section( $item ) {
        if ( ! empty( $item['hidden'] ) ) {
            return null;
        }

        $product = isset( $item['product_id'] ) ? wc_get_product( (int) $item['product_id'] ) : null;
        $title   = $product ? $product->get_name() : __( 'Choose an option', 'rentopian-sync' );

        $opts = array();
        foreach ( (array) $item['optional_items'] as $opt ) {
            $opts[] = $this->normalise_selectable_option( $opt );
        }

        // Stable, admin-driven ordering. The bulk feed builds the
        // optional_items array via GROUP_CONCAT without an ORDER BY,
        // so the customer-facing dropdown ends up in whatever order
        // MySQL happened to return rows — for the same product across
        // page loads that order can flip. Sort by the variation's
        // menu_order (which is what WC's variations admin lets you
        // drag-reorder) with a stable variant_id tie-break.
        usort( $opts, function ( $a, $b ) {
            $va = isset( $a['variant_id'] ) ? (int) $a['variant_id'] : 0;
            $vb = isset( $b['variant_id'] ) ? (int) $b['variant_id'] : 0;
            $ma = $va ? (int) get_post_field( 'menu_order', $va ) : 0;
            $mb = $vb ? (int) get_post_field( 'menu_order', $vb ) : 0;
            if ( $ma !== $mb ) {
                return $ma <=> $mb;
            }
            return $va <=> $vb;
        } );

        // The set item's product name heads this dropdown, so its options
        // can drop the product name they repeat.
        $opts = $this->apply_variant_label_display( $opts, $title );

        $synth_uid = $this->synthetic_uid_for_selectable( $item );

        return array(
            'type'               => self::TYPE_DROPDOWN,
            'origin'             => 'selectable',
            'uid'                => $synth_uid,
            'title'              => $title,
            'description'        => '',
            'required'           => ! empty( $item['required'] ),
            'multiple_selection' => false,
            'group_id'           => 0,
            'group_quantity'     => isset( $item['quantity'] ) ? (int) $item['quantity'] : 1,
            'group_price'        => null,
            'quantity_min'       => 0,
            'quantity_max'       => 1,
            'items'              => $opts,
            // Carry the parent product id so the cart-handler can map
            // back to the existing rental_add_ons[].parent_set_item_*
            // shape when the form submits.
            'parent_set_item_product_id' => isset( $item['product_id'] ) ? (int) $item['product_id'] : 0,
            // Addons attached to this set item. They apply to the
            // selectable regardless of which variant the customer
            // picks (matching classic mode's behaviour — addons are
            // per-set-item, not per-variant).
            'addons'             => $this->normalise_section_addons( $item ),
        );
    }

    /**
     * Build a section from a simple item — rendered as a fixed card.
     *
     * @param array $item
     * @return array|null
     */
    protected function build_simple_section( $item ) {
        if ( ! empty( $item['hidden'] ) ) {
            return null;
        }

        $variant_id = isset( $item['variant_id'] ) ? (int) $item['variant_id'] : 0;
        $product_id = isset( $item['product_id'] ) ? (int) $item['product_id'] : 0;

        $wp_id   = $variant_id ?: $product_id;
        $product = $wp_id ? wc_get_product( $wp_id ) : null;
        $title   = $product
            ? $this->format_product_display_name( $product )
            : __( 'Item', 'rentopian-sync' );
        $img     = $product ? $product->get_image( 'medium' ) : '';
        $perma   = $this->public_permalink( $product );
        // Stamp-first. The
        // `$item['price']` field carries the in-set override price
        // (Laravel's `inventory_sets_relation.price`) — the rate the
        // admin configured for THIS product as part of THIS set,
        // distinct from its standalone product price. When positive
        // it wins; only when empty/null do we fall back to the live
        // variant chain.
        //
        // CASE 1/2/3 entry gate (mirrors Rental_Sets_Price_Engine::
        // child_unit_price exactly, so the product page total === cart):
        //   - item_based_total = 1     → contributes (every item bills)
        //   - item_based_total = 0     → $0 (CASE 3) unless separate_price
        //
        // A simple SET ITEM (entity-1, handled by this method) is an
        // INCLUDED / optional bundle component, NEVER a customer variant
        // choice — even when it points to a specific variation. So in a
        // fixed bundle it lists at $0 regardless of its variant/in-set
        // price; the fixed bundle price IS the total. (Genuine variant
        // CHOICES are selectables/entity-2, handled by
        // build_selectable_section, and keep showing their prices.)
        //
        // EXCEPTION — separate_price. A simple item flagged to be priced
        // separately bills its own rate on top of the bundle, so it MUST
        // show a real price here too (mirrors the engine's separate_price
        // gate so the product page total === the cart).
        $rntp_separate_price = ! empty( $item['separate_price'] );
        $rntp_has_stamp      = isset( $item['price'] ) && (float) $item['price'] > 0;
        $rntp_contributes    = $this->item_based_total || $rntp_separate_price;

        if ( ! $rntp_contributes ) {
            $price = '';
        } elseif ( $rntp_has_stamp ) {
            $price = $item['price'];
        } else {
            $price = $this->variant_price_for_display( $product );
        }
        $qty     = isset( $item['quantity'] ) ? (int) $item['quantity'] : 1;

        return array(
            'type'           => self::TYPE_FIXED_ITEM,
            'origin'         => 'simple',
            'addons'         => $this->normalise_section_addons( $item ),
            'uid'            => $this->resolve_simple_item_uid( $item ),
            'title'          => $title,
            'description'    => isset( $item['note'] ) ? (string) $item['note'] : '',
            'required'       => ! empty( $item['required'] ),
            'multiple_selection' => false,
            'group_id'       => 0,
            'group_quantity' => $qty,
            'group_price'    => $price,
            'quantity_min'   => 0,
            'quantity_max'   => 0,
            'items'          => array(
                array(
                    'uid'        => $this->resolve_simple_item_uid( $item ),
                    'product_id' => $product_id,
                    'variant_id' => $variant_id,
                    'quantity'   => $qty,
                    'price'      => $price,
                    'name'       => $title,
                    // A simple item is never a variant choice, so its
                    // label is never shortened — but the key is present
                    // so every partial can read one name shape.
                    'name_full'  => $title,
                    'image'      => $img,
                    'permalink'  => $perma,
                    'is_default' => true,
                    'inv_id'     => isset( $item['inv_id'] ) ? (int) $item['inv_id'] : 0,
                ),
            ),
        );
    }

    /**
     * @param array      $it                   Group item from postmeta.
     * @param mixed|null $group_price_override Group-level `group_price`
     *                                         when set; takes precedence
     *                                         over per-item prices. Pass
     *                                         null when the group has no
     *                                         override.
     * @return array Display-ready item.
     */
    protected function normalise_group_item( $it, $group_price_override = null ) {
        $variant_id = isset( $it['variant_id'] ) ? (int) $it['variant_id'] : 0;
        $product_id = isset( $it['product_id'] ) ? (int) $it['product_id'] : 0;
        $wp_id      = $variant_id ?: $product_id;
        $product    = $wp_id ? wc_get_product( $wp_id ) : null;

        if ( $product ) {
            $name_parts = $this->split_product_display_name( $product );
        } else {
            // No WC product behind the row — the server-sent label is all
            // we have, and it can't be split into product + variant.
            $name_parts = array(
                'base'    => ! empty( $it['variant_name'] )
                    ? (string) $it['variant_name']
                    : (string) ( $it['product_name'] ?? __( 'Option', 'rentopian-sync' ) ),
                'variant' => '',
            );
        }
        $name = '' !== $name_parts['variant']
            ? $name_parts['base'] . ' - ' . $name_parts['variant']
            : $name_parts['base'];

        // Price resolution priority:
        //
        //   1. Group-level `group_price` override (when set, including
        //      the literal 0). The admin's "this whole group bills at
        //      one rate" instruction wins over per-item rates.
        //   2. Per-item `price` from the server, when positive.
        //   3. Variant's own `_regular_price`-first display price (fall-
        //      through when the server stored 0 / empty for non-
        //      selected siblings).
        //
        // Matches the engine's child_unit_price resolution order so the
        // dropdown label and the cart line subtotal can never disagree.
        if ( null !== $group_price_override ) {
            $price = $group_price_override;
        } else {
            $price = isset( $it['price'] ) && (float) $it['price'] > 0
                ? $it['price']
                : $this->variant_price_for_display( $product );
        }

        return array(
            'uid'         => isset( $it['uid'] ) ? (string) $it['uid'] : '',
            'product_id'  => $product_id,
            'variant_id'  => $variant_id,
            'inv_id'      => isset( $it['rental_inv_id'] ) ? (int) $it['rental_inv_id'] : 0,
            'quantity'    => isset( $it['quantity'] ) ? (int) $it['quantity'] : 1,
            'price'       => $price,
            'is_default'  => ! empty( $it['is_default'] ),
            'name'        => $name,
            // Complete label, kept whole for tooltips / screen readers
            // even when `name` is shortened.
            'name_full'    => $name,
            // Halves of `name`, for apply_variant_label_display().
            'name_base'    => $name_parts['base'],
            'name_variant' => $name_parts['variant'],
            'image'       => $product ? $product->get_image( 'medium' ) : '',
            'permalink'   => $this->public_permalink( $product ),
        );
    }

    /**
     * @param array $opt One element of $item['optional_items'].
     * @return array
     */
    protected function normalise_selectable_option( $opt ) {
        $variant_id = isset( $opt['variant_id'] ) ? (int) $opt['variant_id'] : 0;
        $product_id = isset( $opt['product_id'] ) ? (int) $opt['product_id'] : 0;
        $wp_id      = $variant_id ?: $product_id;
        $product    = $wp_id ? wc_get_product( $wp_id ) : null;

        // Stamp-first. The
        // `$opt['price']` field carries the in-set override price for
        // this variant option (Laravel's
        // `inventory_sets_relation.price` for the row). When positive,
        // it wins over the live variant chain — the override IS the
        // intended per-set price for this option.
        //
        // Empty / null / 0 means "no in-set override; use the live
        // variant chain".
        //
        // Same rule the engine's child_unit_price() applies to the
        // cart subtotal, so the dropdown label and the cart line can
        // never disagree.
        $price = isset( $opt['price'] ) && (float) $opt['price'] > 0
            ? $opt['price']
            : $this->variant_price_for_display( $product );

        $name_parts = $this->split_product_display_name( $product );
        $name       = $this->format_product_display_name( $product );

        return array(
            'uid'        => '',
            'product_id' => $product_id,
            'variant_id' => $variant_id,
            'inv_id'     => isset( $opt['inventory_id'] ) ? (int) $opt['inventory_id'] : 0,
            'quantity'   => isset( $opt['quantity'] ) ? (int) $opt['quantity'] : 1,
            'price'      => $price,
            'is_default' => ! empty( $opt['is_selected'] ),
            'name'       => $name,
            // Complete label, kept whole for tooltips / screen readers
            // even when `name` is shortened.
            'name_full'    => $name,
            // Halves of `name`, for apply_variant_label_display().
            'name_base'    => $name_parts['base'],
            'name_variant' => $name_parts['variant'],
            'image'      => $product ? $product->get_image( 'medium' ) : '',
            'permalink'  => $this->public_permalink( $product ),
        );
    }

    /**
     * Project the API's addons array (as stored on a set item) into a
     * partial-friendly shape. Each entry includes:
     *
     *   - product_id          WP product id of the addon
     *   - rental_inv_id       Laravel inventory id (= what the cart
     *                         expects on `rental_add_ons[i][inv_id]`)
     *   - variant_id          WP variant id of the *currently selected*
     *                         option (default-variant if variants_optional
     *                         is present, otherwise the addon's own
     *                         variant_id)
     *   - quantity            Per-addon quantity
     *   - required            Required flag (passed through)
     *   - price               Per-unit price (for display + cart total)
     *   - name                Customer-facing parent name
     *   - image               WC product image markup
     *   - has_variants        bool — true when the addon's customer
     *                         must pick a variant (variants_optional
     *                         has 2+ entries)
     *   - options             Array of variant options shaped like the
     *                         selectable options elsewhere
     *                         (uid, product_id, variant_id, name,
     *                         name_full, price, image, permalink,
     *                         is_default)
     *
     * Addons with `hide_on_website` truthy are dropped — same policy as
     * hidden items.
     *
     * Returns [] when the source item has no addons; partials use that
     * to decide whether to render the addon block at all.
     *
     * @param array $item One element of `_rental_set_items`.
     * @return array
     */
    protected function normalise_section_addons( $item ) {
        if ( empty( $item['addons'] ) || ! is_array( $item['addons'] ) ) {
            return array();
        }

        $out = array();
        foreach ( $item['addons'] as $addon ) {
            if ( ! is_array( $addon ) ) {
                continue;
            }
            if ( ! empty( $addon['hidden'] ) ) {
                continue;
            }

            $addon_product_id  = isset( $addon['product_id'] )  ? (int) $addon['product_id']  : 0;
            $addon_variant_id  = isset( $addon['variant_id'] )  ? (int) $addon['variant_id']  : 0;
            $addon_rental_inv  = isset( $addon['rental_inv_id'] ) ? (int) $addon['rental_inv_id'] : 0;
            $addon_qty         = isset( $addon['quantity'] )    ? (int) $addon['quantity']    : 1;
            $addon_required    = ! empty( $addon['required'] );
            $addon_price       = isset( $addon['price'] )       ? $addon['price'] : '';

            // Variants_optional → options array. Each option carries
            // its own WP variant id; the customer picks one. Sort by
            // menu_order for stable, admin-controlled ordering (same
            // policy as the selectable dropdown above).
            $options = array();
            $default_variant_id = $addon_variant_id; // fallback
            if ( ! empty( $addon['variants_optional'] ) && is_array( $addon['variants_optional'] ) ) {
                foreach ( $addon['variants_optional'] as $vo ) {
                    if ( ! is_array( $vo ) ) {
                        continue;
                    }
                    $vid = isset( $vo['variant_id'] ) ? (int) $vo['variant_id'] : 0;
                    $pid = isset( $vo['product_id'] ) ? (int) $vo['product_id'] : 0;
                    $product = ( $vid ?: $pid ) ? wc_get_product( $vid ?: $pid ) : null;
                    $is_default = ! empty( $vo['default'] );
                    $vo_name_parts = $this->split_product_display_name( $product );
                    $vo_name       = $this->format_product_display_name( $product );
                    $options[] = array(
                        'uid'        => '',
                        'product_id' => $pid,
                        'variant_id' => $vid,
                        'inv_id'     => 0,
                        'quantity'   => isset( $vo['quantity'] ) ? (int) $vo['quantity'] : $addon_qty,
                        // Variable addons always show prices regardless
                        // of item_based_total — customers can't make
                        // an informed pick without the per-variant
                        // price visible. Whether the price *contributes
                        // to the parent set total* is still gated by
                        // item_based_total downstream.
                        'price'      => $product ? $this->variant_price_for_display( $product ) : '',
                        'is_default' => $is_default,
                        'name'       => $vo_name,
                        // Complete label, kept whole for tooltips / screen
                        // readers even when `name` is shortened.
                        'name_full'    => $vo_name,
                        // Halves of `name`, for apply_variant_label_display().
                        'name_base'    => $vo_name_parts['base'],
                        'name_variant' => $vo_name_parts['variant'],
                        'image'      => $product ? $product->get_image( 'medium' ) : '',
                        'permalink'  => $this->public_permalink( $product ),
                    );
                    if ( $is_default && $vid ) {
                        $default_variant_id = $vid;
                    }
                }

                // Stable sort: menu_order, then variant_id.
                usort( $options, function ( $a, $b ) {
                    $va = isset( $a['variant_id'] ) ? (int) $a['variant_id'] : 0;
                    $vb = isset( $b['variant_id'] ) ? (int) $b['variant_id'] : 0;
                    $ma = $va ? (int) get_post_field( 'menu_order', $va ) : 0;
                    $mb = $vb ? (int) get_post_field( 'menu_order', $vb ) : 0;
                    if ( $ma !== $mb ) {
                        return $ma <=> $mb;
                    }
                    return $va <=> $vb;
                } );

                // If no explicit default flag came through, fall back
                // to the first option as the default selection.
                if ( ! $default_variant_id && ! empty( $options ) ) {
                    $default_variant_id = (int) $options[0]['variant_id'];
                }
            }

            // Parent (the addon's own product post) for the name and
            // image rendered above the variant dropdown.
            $parent_product = $addon_product_id ? wc_get_product( $addon_product_id ) : null;
            $parent_name    = $parent_product ? $this->format_product_display_name( $parent_product ) : __( 'Add-on', 'rentopian-sync' );
            $parent_image   = $parent_product ? $parent_product->get_image( 'medium' ) : '';

            // The addon name heads the variant dropdown, so its options
            // can drop the product name they repeat.
            $options = $this->apply_variant_label_display( $options, $parent_name );

            $out[] = array(
                'product_id'    => $addon_product_id,
                'rental_inv_id' => $addon_rental_inv,
                'variant_id'    => $default_variant_id,
                'quantity'      => $addon_qty,
                'required'      => $addon_required,
                'price'         => $addon_price,
                'name'          => $parent_name,
                'image'         => $parent_image,
                'has_variants'  => count( $options ) > 1,
                'options'       => $options,
            );
        }
        return $out;
    }

    /**
     * Build a customer-facing display name for a product or variation:
     * "{product} - {attributes}" for a variation, the product name alone
     * for anything else. {@see split_product_display_name} resolves the
     * two halves and documents how.
     *
     * @param WC_Product|null $product
     * @return string
     */
    protected function format_product_display_name( $product ) {
        $parts = $this->split_product_display_name( $product );

        return '' !== $parts['variant']
            ? $parts['base'] . ' - ' . $parts['variant']
            : $parts['base'];
    }

    /**
     * The two halves of a display name — the product name and the
     * variant-specific part — kept apart so a list of choices can drop
     * the `base` they all repeat ({@see apply_variant_label_display}).
     *
     * For variations WC core's `WC_Product_Variation::get_title()` (and
     * therefore `get_name()`) returns the PARENT product's name — not the
     * variation's own post_title — because get_title() routes through
     * the `woocommerce_product_variation_title` filter with parent name
     * as the default. That's why every variant of "Baby bike" reads
     * "Baby bike" with no differentiator. We have to recover the
     * variant-specific label ourselves, trying four strategies in order
     * because no single one works on every sync configuration:
     *
     *   1. `get_attribute_summary()` — "Color: Red, Size: M". Works
     *      when the variation has `attribute_pa_*` rows (this sync
     *      writes them; see functions.php:7425).
     *   2. variation's actual post_title — the sync writes
     *      "{variant->name} - {attr_title}" into it (functions.php:7437).
     *      For variations with empty attribute slugs (e.g. global
     *      variants without taxonomy attributes) this is the only
     *      source.
     *   3. `get_variation_attributes()` — fallback when the summary
     *      came back empty but the attribute_* postmeta exists.
     *   4. parent name alone — last-resort to keep the dropdown
     *      functional even if nothing distinguishing was found.
     *
     * @param WC_Product|null $product
     * @return array{base:string,variant:string} `variant` is '' for a
     *                                           plain product, and for a
     *                                           variation nothing could
     *                                           distinguish (strategy 4).
     */
    protected function split_product_display_name( $product ) {
        if ( ! $product ) {
            return array( 'base' => __( 'Option', 'rentopian-sync' ), 'variant' => '' );
        }

        // Non-variations: WC's name is correct.
        if ( ! method_exists( $product, 'is_type' ) || ! $product->is_type( 'variation' ) ) {
            return array( 'base' => (string) $product->get_name(), 'variant' => '' );
        }

        // Resolve the parent's name once — it's the prefix for every
        // strategy below. `get_name()` on a variation already returns
        // the parent's name (per the WC filter chain), so reading from
        // the variation is enough; no need to instantiate the parent.
        $parent_name = (string) $product->get_name();

        // Strategy 1 — WC's attribute summary.
        if ( method_exists( $product, 'get_attribute_summary' ) ) {
            $summary = trim( (string) $product->get_attribute_summary() );
            if ( '' !== $summary ) {
                return array( 'base' => $parent_name, 'variant' => $summary );
            }
        }

        // Strategy 2 — variation's own post_title. Reading via get_post
        // bypasses get_title()'s parent-name override.
        $post = get_post( $product->get_id() );
        if ( $post ) {
            $variation_title = trim( (string) $post->post_title );
            // Drop a redundant "{parent} - " prefix if the sync already
            // wrote one. The output here is "{parent} - {variant}", so
            // doubling up reads "Baby bike - Baby bike - Red".
            if ( '' !== $variation_title ) {
                $needle = $parent_name . ' - ';
                if ( 0 === strpos( $variation_title, $needle ) ) {
                    $variation_title = (string) substr( $variation_title, strlen( $needle ) );
                }
                if ( '' !== $variation_title && $variation_title !== $parent_name ) {
                    return array( 'base' => $parent_name, 'variant' => $variation_title );
                }
            }
        }

        // Strategy 3 — iterate variation_attributes manually.
        if ( method_exists( $product, 'get_variation_attributes' ) ) {
            $attrs = $product->get_variation_attributes();
            $parts = array();
            foreach ( $attrs as $value ) {
                $value = trim( (string) $value );
                if ( '' !== $value ) {
                    $parts[] = $value;
                }
            }
            if ( ! empty( $parts ) ) {
                return array( 'base' => $parent_name, 'variant' => implode( ' / ', $parts ) );
            }
        }

        // Strategy 4 — give up and return the parent alone.
        return array( 'base' => $parent_name, 'variant' => '' );
    }

    /**
     * Shorten the labels of a list of choices by dropping the product
     * name they all repeat.
     *
     * Only applied when the admin enabled it, and only where the prefix
     * is provably redundant:
     *
     *   1. every choice in the list shares the same product name, so the
     *      prefix distinguishes nothing, or
     *   2. the choice repeats the heading rendered directly above the
     *      list (a selectable item's product name, an add-on's name, or
     *      a group named after its product).
     *
     * A list mixing several products therefore keeps its full labels —
     * dropping the name there would leave choices no customer could tell
     * apart. Choices without a variant part are never rewritten, so a
     * label can never come out empty.
     *
     * @param array  $items   Normalised items carrying `name_base` / `name_variant`.
     * @param string $heading Title rendered above the list.
     * @return array
     */
    protected function apply_variant_label_display( array $items, $heading ) {
        if ( ! $this->hide_variant_product_name || empty( $items ) ) {
            return $items;
        }

        $bases = array();
        foreach ( $items as $item ) {
            $bases[ isset( $item['name_base'] ) ? (string) $item['name_base'] : '' ] = true;
        }
        $one_product = ( 1 === count( $bases ) );

        $heading = trim( (string) $heading );

        foreach ( $items as $index => $item ) {
            $base    = isset( $item['name_base'] ) ? (string) $item['name_base'] : '';
            $variant = isset( $item['name_variant'] ) ? (string) $item['name_variant'] : '';

            if ( '' === $variant ) {
                continue;
            }
            if ( ! $one_product && 0 !== strcasecmp( trim( $base ), $heading ) ) {
                continue;
            }

            $items[ $index ]['name'] = $variant;
        }

        return $items;
    }

    /**
     * A product's permalink for the external-link icon, or '' when the
     * product has no browsable single page. Every partial renders the
     * icon only for a non-empty permalink, so returning '' here is what
     * suppresses the icon for a hidden-on-website set line / option /
     * card — no per-partial change needed.
     *
     * @param WC_Product|null $product
     * @return string
     */
    protected function public_permalink( $product ) {
        if ( ! $product || ! method_exists( $product, 'get_permalink' ) ) {
            return '';
        }
        if ( $this->product_is_hidden_on_website( $product ) ) {
            return '';
        }
        return (string) $product->get_permalink();
    }

    /**
     * Whether a product has no public single page, so linking to it from
     * the set configurator would only dead-end (the plugin redirects such
     * pages to the home page).
     *
     * Hidden-on-website means either:
     *   - `_rental_is_add_on` — add-ons AND hidden-from-API products (both
     *     stored under this meta at import) are redirected to home by
     *     `rental_redirect_products_and_variants_to_home_page`, or
     *   - WC catalog visibility `hidden` — excluded from both catalog and
     *     search, i.e. not browsable.
     *
     * The status lives on the parent product, so a variation resolves to
     * its parent before the check.
     *
     * @param WC_Product|null $product
     * @return bool
     */
    protected function product_is_hidden_on_website( $product ) {
        if ( ! $product || ! method_exists( $product, 'get_id' ) ) {
            return true;
        }

        $owner_id = ( method_exists( $product, 'is_type' ) && $product->is_type( 'variation' )
            && method_exists( $product, 'get_parent_id' ) )
            ? (int) $product->get_parent_id()
            : (int) $product->get_id();
        if ( $owner_id <= 0 ) {
            return true;
        }

        if ( get_post_meta( $owner_id, '_rental_is_add_on', true ) ) {
            return true;
        }

        $owner = ( $owner_id === (int) $product->get_id() ) ? $product : wc_get_product( $owner_id );
        if ( $owner && method_exists( $owner, 'get_catalog_visibility' )
            && 'hidden' === $owner->get_catalog_visibility() ) {
            return true;
        }

        return false;
    }

    /**
     * Read a variant's display price for use in the dropdown label.
     * Returns '' when no source resolves a non-zero price.
     *
     * Classic-parity rationale. The legacy
     * `rental_calculate_rental_item_price($variant_id, null, true, true)`
     * call (functions.php:2178) is invoked with `$get_regular_price = true`
     * and reads `_regular_price` postmeta — which is reliable across
     * every variant because WC's variation data store sync doesn't
     * touch it. Meanwhile `WC_Product::get_price()` reads `_price`,
     * which WC's sync zeroes out on sibling variants of the default-
     * flagged one (verified against the Baby bike set on 2026-05-26:
     * five of six variants had `_price = 0` even though every
     * inventory row had `rental_price = 99`).
     *
     * So this method mirrors classic's read order:
     *
     *   1. variant's `_regular_price`              (classic's source)
     *   2. parent variable product's `_regular_price`
     *   3. variant's `_price`                       (defensive)
     *   4. parent product's `_price`
     *
     * @param WC_Product|null $product
     * @return string
     */
    protected function variant_price_for_display( $product ) {
        if ( ! $product || ! method_exists( $product, 'get_id' ) ) {
            return '';
        }
        $wp_id = (int) $product->get_id();
        if ( $wp_id <= 0 ) {
            return '';
        }

        // Step 1 — variant's own _regular_price (classic's source).
        $regular = get_post_meta( $wp_id, '_regular_price', true );
        if ( '' !== $regular && is_numeric( $regular ) && (float) $regular > 0 ) {
            return (string) $regular;
        }

        // Step 2 — parent product's _regular_price (sibling fallback).
        if ( method_exists( $product, 'is_type' ) && $product->is_type( 'variation' )
            && method_exists( $product, 'get_parent_id' ) ) {
            $parent_id = (int) $product->get_parent_id();
            if ( $parent_id ) {
                $parent_regular = get_post_meta( $parent_id, '_regular_price', true );
                if ( '' !== $parent_regular && is_numeric( $parent_regular )
                    && (float) $parent_regular > 0 ) {
                    return (string) $parent_regular;
                }
            }
        }

        // Step 3 — variant's _price (defensive fallback for older sync builds).
        $price = get_post_meta( $wp_id, '_price', true );
        if ( '' !== $price && is_numeric( $price ) && (float) $price > 0 ) {
            return (string) $price;
        }

        // Step 4 — parent product's _price (last-resort).
        if ( method_exists( $product, 'is_type' ) && $product->is_type( 'variation' )
            && method_exists( $product, 'get_parent_id' ) ) {
            $parent_id = (int) $product->get_parent_id();
            if ( $parent_id ) {
                $parent_price = get_post_meta( $parent_id, '_price', true );
                if ( '' !== $parent_price && is_numeric( $parent_price )
                    && (float) $parent_price > 0 ) {
                    return (string) $parent_price;
                }
            }
        }

        return '';
    }

    /**
     * Reorder a normalised item list to follow a list of UIDs. Items
     * whose UID is present in $order_uids appear in the listed order;
     * items not listed are appended afterwards in their original
     * relative order. Defensive against stale order lists left over
     * from prior syncs.
     *
     * @param array    $items
     * @param string[] $order_uids
     * @return array
     */
    protected function reorder_items_by_uid( array $items, array $order_uids ) {
        $by_uid = array();
        foreach ( $items as $idx => $item ) {
            $u = isset( $item['uid'] ) ? (string) $item['uid'] : '';
            if ( '' !== $u && ! isset( $by_uid[ $u ] ) ) {
                $by_uid[ $u ] = $idx;
            }
        }

        $sorted = array();
        $emitted = array();
        foreach ( $order_uids as $uid ) {
            if ( isset( $by_uid[ $uid ] ) ) {
                $idx = $by_uid[ $uid ];
                $sorted[] = $items[ $idx ];
                $emitted[ $idx ] = true;
            }
        }
        foreach ( $items as $idx => $item ) {
            if ( isset( $emitted[ $idx ] ) ) {
                continue;
            }
            $sorted[] = $item;
        }
        return $sorted;
    }

    /**
     * @param array $item
     * @return string
     */
    protected function synthetic_uid_for_selectable( $item ) {
        $sid = isset( $item['inventory_sets_id'] ) ? (int) $item['inventory_sets_id'] : 0;
        $pid = isset( $item['product_id'] ) ? (int) $item['product_id'] : 0;
        return 'sel-' . $sid . '-' . $pid;
    }

    /**
     * @param array $item
     * @return string
     */
    protected function resolve_simple_item_uid( $item ) {
        if ( ! empty( $item['uid'] ) ) {
            return (string) $item['uid'];
        }
        $div = isset( $item['division_id'] ) ? (int) $item['division_id'] : 0;
        $inv = isset( $item['inv_id'] ) ? (int) $item['inv_id'] : 0;
        // `inv_id` alone is enough — Laravel's inventory.id is unique
        // across divisions, so the "{div}-{inv}" key stays unique even
        // when div is 0 (e.g. global / all-division sets where the
        // bulk feed reports the inventory's own division and that
        // happens to be 0). Requiring both was too strict and was the
        // reason simple-items sets rendered nothing under the modern
        // renderer.
        if ( $inv ) {
            return $div . '-' . $inv;
        }
        // Last-resort fallback for legacy data that has neither uid
        // nor inv_id: key by product/variant. Not unique if the same
        // product appears twice in one set, but better than silently
        // dropping the item from the render output.
        $pid = isset( $item['product_id'] ) ? (int) $item['product_id'] : 0;
        $vid = isset( $item['variant_id'] ) ? (int) $item['variant_id'] : 0;
        if ( $pid ) {
            return 'pv-' . $pid . '-' . $vid;
        }
        return '';
    }
}

endif;
