<?php
/**
 * Rental_Sets_Webhook_Item_Persister
 *
 * Entity-1+2 (set items + selectable items + addons) builder for the
 * single-set webhook flow. Mirrors the bulk-feed `Rental_Sets_Item_Builder`
 * shape so both code paths produce byte-equivalent `_rental_set_items`
 * postmeta — every downstream consumer (renderer, presenter, cart
 * validator, addon pipeline) handles one shape, not two.
 *
 * Why this class exists. The webhook receiver in api.php
 * (`rentopian_set_create` and `rentopian_set_update`) contained ~80
 * lines of duplicated inline logic that walked `$set->items`, resolved
 * Laravel ids to WP ids via N+1 single-row SELECTs, and produced a
 * reduced item shape missing six fields the bulk-feed builder writes:
 *
 *   - `rental_product_id`  (Laravel product id — needed for round-trip)
 *   - `rental_variant_id`  (Laravel variant id)
 *   - `parent_set_id`      (WP set id)
 *   - `price`              (item price — webhook doesn't ship; restored
 *                            by `Rental_Sets_Webhook_Defense` from prior)
 *   - `separate_price`     (same)
 *   - `required`           (same)
 *
 * Addons in the receiver lacked the three `parent_set_*` fields the
 * bulk-feed builder writes. Optional items resolved the wrong product
 * id in the rare case that an optional points at a different product
 * than its parent (the receiver used the parent's WP product id for
 * every optional; the bulk feed correctly resolves each optional's
 * own product).
 *
 * This class:
 *
 *   1. Collects every distinct (rental_id, division_id) pair across
 *      items, optional_items, addons, and addon variants_optional in
 *      ONE pass.
 *   2. Runs TWO batched SELECTs (product_relations + variant_relations)
 *      to resolve every Laravel id to its WP id. No N+1.
 *   3. Builds the canonical entity-1+2 shape — matching
 *      `Rental_Sets_Item_Builder`'s output field-for-field — plus
 *      backfills the fields the webhook payload itself omits where
 *      it can.
 *
 * Invocation from api.php:
 *
 *     if ( class_exists( 'Rental_Sets_Webhook_Item_Persister', false ) ) {
 *         $built = Rental_Sets_Webhook_Item_Persister::build( (int) $set_id, $set );
 *         $set_items                          = $built['items'];
 *         $set_items_have_optional_items      = $built['has_optional_items'];
 *         $set_items_have_some_hidden_items   = $built['has_hidden_items'];
 *     } else {
 *         // … original inline loops as fallback …
 *     }
 *
 * The `class_exists` gate keeps the call backward-compatible: if the
 * sets module fails to load the legacy inline path still runs.
 *
 * Interaction with the rest of the pipeline:
 *
 *   - `Rental_Sets_Webhook_Defense` still fires on the resulting
 *     `update_post_meta('_rental_set_items', …)` call. The defense's
 *     entity-1/2 merge restores fields this builder couldn't supply
 *     because the webhook payload doesn't ship them
 *     (`price`/`separate_price`/`required`/`note`). The two layers
 *     compose.
 *
 *   - `Rental_Sets_Webhook_Meta_Guard` (Step 2) preserves any modern
 *     postmeta around the `set/create` wipe. Combined with this
 *     builder, the webhook flow now produces the same postmeta state
 *     as a bulk-sync of the same set.
 *
 * Postmeta NOT written by this class. The class only RETURNS the
 * shape; the caller (api.php) writes the postmeta. This matches how
 * api.php already structures the flow — items are built first, then
 * either bulk-INSERTed (create) or update_post_meta'd (update). Step
 * 4 will collapse that further; this step is the surgical extraction.
 *
 * @package RentopianSync\Sets
 * @since   2.14.8
 *
 * PHP requirement: 7.4+
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'Rental_Sets_Webhook_Item_Persister', false ) ) :

class Rental_Sets_Webhook_Item_Persister {

    /**
     * Log source tag.
     */
    const LOG_SOURCE = 'rentopian-sets-sync';

    /**
     * Static entry-point used by api.php.
     *
     * @param int          $wp_set_id    The WP post id of the set being persisted.
     * @param object|array $set_payload  The webhook's $set object (stdClass after json_decode).
     * @return array {
     *     @type array $items              Resolved set_items ready to serialise.
     *     @type bool  $has_optional_items True if any item has optional_items.
     *     @type bool  $has_hidden_items   True if any item is hidden.
     *     @type int   $skipped_count      Items dropped because their product didn't resolve.
     * }
     */
    public static function build( $wp_set_id, $set_payload ) {
        $instance = new self();
        return $instance->run( (int) $wp_set_id, $set_payload );
    }

    /**
     * @param int          $wp_set_id
     * @param object|array $set_payload
     * @return array
     */
    protected function run( $wp_set_id, $set_payload ) {
        $empty = array(
            'items'              => array(),
            'has_optional_items' => false,
            'has_hidden_items'   => false,
            'skipped_count'      => 0,
        );

        if ( $wp_set_id <= 0 ) {
            return $empty;
        }

        $raw_items = $this->extract_items( $set_payload );
        if ( empty( $raw_items ) ) {
            return $empty;
        }

        // Diagnostic: surface whether the webhook payload actually carried
        // addons per item (rental_product_id:addon_count). When the System's
        // single-set webhook ships a leaner item shape than the bulk feed,
        // addons arrive empty here and a "set-item addons don't load" report
        // can be pinpointed to the payload rather than the WP build.
        if ( class_exists( 'Project_WP_Logger', false ) ) {
            $addon_summary = array();
            foreach ( $raw_items as $ri ) {
                $o = $this->cast_object( $ri );
                if ( null === $o ) {
                    continue;
                }
                $pid = $this->prop_int( $o, 'product_id' );
                $ac  = ( isset( $o->addons ) && is_array( $o->addons ) ) ? count( $o->addons ) : 0;
                $addon_summary[] = $pid . ':' . $ac;
            }
            Project_WP_Logger::write(
                sprintf(
                    'Webhook_Item_Persister: payload addon counts on set %d (rental_product_id:count) = %s',
                    (int) $wp_set_id,
                    implode( ' ', $addon_summary )
                ),
                'info',
                self::LOG_SOURCE
            );
        }

        // Single pass to collect every (rental_id, division_id) pair.
        $pairs = $this->collect_lookup_pairs( $raw_items );

        // Two batched SELECTs to resolve everything in one round-trip
        // each.
        $maps = $this->resolve_id_maps( $pairs );

        // Build the canonical shape.
        $built = $this->build_items( $raw_items, $maps, $wp_set_id );

        $this->log( sprintf(
            'Webhook_Item_Persister: built %d item(s) on set %d (skipped %d unresolved); has_optional=%s, has_hidden=%s.',
            count( $built['items'] ),
            $wp_set_id,
            $built['skipped_count'],
            $built['has_optional_items'] ? 'yes' : 'no',
            $built['has_hidden_items']   ? 'yes' : 'no'
        ) );

        return $built;
    }

    /*
    |--------------------------------------------------------------------------
    | Payload extraction
    |--------------------------------------------------------------------------
    */

    /**
     * Pull `items` off the payload. Tolerant: $set->items arrives as a
     * JSON-encoded string from the webhook (`getSetsOptionalItems` calls
     * json_encode on its result), but defensive code accepts an already-
     * decoded array/object too.
     *
     * @param mixed $set_payload
     * @return array
     */
    protected function extract_items( $set_payload ) {
        if ( is_object( $set_payload ) ) {
            $raw = property_exists( $set_payload, 'items' ) ? $set_payload->items : null;
        } elseif ( is_array( $set_payload ) ) {
            $raw = array_key_exists( 'items', $set_payload ) ? $set_payload['items'] : null;
        } else {
            return array();
        }

        if ( null === $raw || '' === $raw ) {
            return array();
        }

        if ( is_string( $raw ) ) {
            $decoded = json_decode( $raw, false );
            $raw     = ( is_array( $decoded ) || is_object( $decoded ) ) ? $decoded : null;
        }

        if ( null === $raw ) {
            return array();
        }
        if ( ! is_array( $raw ) ) {
            $raw = array( $raw );
        }
        return $raw;
    }

    /*
    |--------------------------------------------------------------------------
    | ID mapping
    |--------------------------------------------------------------------------
    */

    /**
     * Walk every item, optional_item, addon and addon variant. Collect
     * unique (rental_id, division_id) pairs into two buckets.
     *
     * @param array $items
     * @return array { products: [ [rid, did], ... ], variants: [ [rid, did], ... ] }
     */
    protected function collect_lookup_pairs( array $items ) {
        $products = array();
        $variants = array();

        foreach ( $items as $raw_item ) {
            $item = $this->cast_object( $raw_item );
            if ( null === $item ) {
                continue;
            }

            $item_division = $this->prop_int( $item, 'division_id' );

            // Top-level item.
            $rp = $this->prop_int( $item, 'product_id' );
            $rv = $this->prop_int( $item, 'variant_id' );
            if ( $rp > 0 ) {
                $products[ $rp . ':' . $item_division ] = array( $rp, $item_division );
            }
            if ( $rv > 0 ) {
                $variants[ $rv . ':' . $item_division ] = array( $rv, $item_division );
            }

            // Optional items.
            if ( isset( $item->optional_items ) && is_array( $item->optional_items ) ) {
                foreach ( $item->optional_items as $raw_opt ) {
                    $opt = $this->cast_object( $raw_opt );
                    if ( null === $opt ) {
                        continue;
                    }
                    $od = $this->prop_int( $opt, 'division_id' );
                    $op = $this->prop_int( $opt, 'product_id' );
                    $ov = $this->prop_int( $opt, 'variant_id' );
                    if ( $op > 0 ) {
                        $products[ $op . ':' . $od ] = array( $op, $od );
                    }
                    if ( $ov > 0 ) {
                        $variants[ $ov . ':' . $od ] = array( $ov, $od );
                    }
                }
            }

            // Addons.
            if ( isset( $item->addons ) && is_array( $item->addons ) ) {
                foreach ( $item->addons as $raw_addon ) {
                    $addon = $this->cast_object( $raw_addon );
                    if ( null === $addon ) {
                        continue;
                    }
                    $ad = $this->prop_int( $addon, 'division_id' );
                    $ap = $this->prop_int( $addon, 'product_id' );
                    $av = $this->prop_int( $addon, 'add_on_variant_id' );
                    if ( $ap > 0 ) {
                        $products[ $ap . ':' . $ad ] = array( $ap, $ad );
                    }
                    if ( $av > 0 ) {
                        $variants[ $av . ':' . $ad ] = array( $av, $ad );
                    }

                    // Addon's own variants_optional.
                    if ( isset( $addon->variants_optional ) && is_array( $addon->variants_optional ) ) {
                        foreach ( $addon->variants_optional as $raw_var ) {
                            $var = $this->cast_object( $raw_var );
                            if ( null === $var ) {
                                continue;
                            }
                            $vd = $this->prop_int( $var, 'division_id' );
                            $vp = $this->prop_int( $var, 'product_id' );
                            $vv = $this->prop_int( $var, 'variant_id' );
                            if ( $vp > 0 ) {
                                $products[ $vp . ':' . $vd ] = array( $vp, $vd );
                            }
                            if ( $vv > 0 ) {
                                $variants[ $vv . ':' . $vd ] = array( $vv, $vd );
                            }
                        }
                    }
                }
            }
        }

        return array(
            'products' => array_values( $products ),
            'variants' => array_values( $variants ),
        );
    }

    /**
     * Run two batched SELECTs to resolve Laravel ids to WP ids.
     *
     * @param array $pairs
     * @return array { products: [ "<rid>:<did>" => <wp_id> ], variants: [ ... ] }
     */
    protected function resolve_id_maps( array $pairs ) {
        global $wpdb, $rental_tables;

        $maps = array(
            'products' => array(),
            'variants' => array(),
        );

        $product_table = isset( $rental_tables['product_relations'] )
            ? $wpdb->prefix . $rental_tables['product_relations']
            : '';
        $variant_table = isset( $rental_tables['variant_relations'] )
            ? $wpdb->prefix . $rental_tables['variant_relations']
            : '';

        if ( ! empty( $pairs['products'] ) && '' !== $product_table ) {
            $maps['products'] = $this->batch_resolve( $product_table, $pairs['products'] );
        }
        if ( ! empty( $pairs['variants'] ) && '' !== $variant_table ) {
            $maps['variants'] = $this->batch_resolve( $variant_table, $pairs['variants'] );
        }
        return $maps;
    }

    /**
     * One SELECT against a relations table; result keyed
     * `"<rental_id>:<division_id>"` for O(1) lookup.
     *
     * @param string $table
     * @param array  $pairs  [ [rental_id, division_id], ... ]
     * @return array
     */
    protected function batch_resolve( $table, array $pairs ) {
        global $wpdb;
        $out = array();

        $rental_ids = array();
        foreach ( $pairs as $p ) {
            $rental_ids[ (int) $p[0] ] = true;
        }
        $rental_ids = array_keys( $rental_ids );
        if ( empty( $rental_ids ) ) {
            return $out;
        }

        $placeholders = implode( ',', array_fill( 0, count( $rental_ids ), '%d' ) );
        // The table name is built from $rental_tables, not user input;
        // the IN list IS parameter-bound through wpdb->prepare().
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $sql  = "SELECT id, rental_id, rental_division_id FROM {$table} WHERE rental_id IN ($placeholders)";
        $rows = $wpdb->get_results( $wpdb->prepare( $sql, $rental_ids ), ARRAY_A );

        if ( empty( $rows ) ) {
            return $out;
        }

        foreach ( $rows as $r ) {
            $key = (int) $r['rental_id'] . ':' . (int) $r['rental_division_id'];
            $out[ $key ] = (int) $r['id'];
        }
        return $out;
    }

    /*
    |--------------------------------------------------------------------------
    | Build canonical shape
    |--------------------------------------------------------------------------
    */

    /**
     * Build the resolved items array. Same conservative skip rule as
     * api.php's current behaviour: items whose product doesn't resolve
     * to a WP product are dropped. This matches the receiver's
     * pre-step-3 semantics.
     *
     * @param array $raw_items
     * @param array $maps
     * @param int   $wp_set_id
     * @return array
     */
    protected function build_items( array $raw_items, array $maps, $wp_set_id ) {
        $items              = array();
        $has_optional_items = false;
        $has_hidden_items   = false;
        $skipped_count      = 0;

        foreach ( $raw_items as $raw_item ) {
            $item = $this->cast_object( $raw_item );
            if ( null === $item ) {
                continue;
            }

            $division_id       = $this->prop_int( $item, 'division_id' );
            $rental_product_id = $this->prop_int( $item, 'product_id' );
            $rental_variant_id = $this->prop_int( $item, 'variant_id' );

            $product_key   = $rental_product_id . ':' . $division_id;
            $wp_product_id = isset( $maps['products'][ $product_key ] ) ? (int) $maps['products'][ $product_key ] : 0;

            // Conservative skip — matches the api.php current behaviour
            // exactly. The bulk-feed builder is more permissive (keeps
            // items with optional_items even when the product doesn't
            // resolve); deliberately not adopted here to avoid changing
            // observable behaviour in this step.
            if ( ! $wp_product_id ) {
                $skipped_count++;
                continue;
            }

            $has_selected_flag = isset( $item->has_selected ) && $item->has_selected ? 1 : 0;
            $hidden_flag       = isset( $item->hidden )       && $item->hidden       ? 1 : 0;
            if ( $hidden_flag ) {
                $has_hidden_items = true;
            }

            // Optional items.
            $optional_items = $this->build_optional_items( $item, $maps );
            if ( ! empty( $optional_items ) ) {
                $has_optional_items = true;
            }

            // Item's own variant id. The api.php rule is: if the item
            // carries optional_items the variant id is cleared, UNLESS
            // has_selected is set (in which case the chosen variant
            // wins). Mirrored verbatim.
            $set_item_variant_id = null;
            if ( ! isset( $item->optional_items ) ) {
                $vkey = $rental_variant_id . ':' . $division_id;
                $set_item_variant_id = isset( $maps['variants'][ $vkey ] ) ? (int) $maps['variants'][ $vkey ] : null;
            }
            if ( $has_selected_flag && $rental_variant_id > 0 ) {
                $vkey = $rental_variant_id . ':' . $division_id;
                $set_item_variant_id = isset( $maps['variants'][ $vkey ] ) ? (int) $maps['variants'][ $vkey ] : null;
            }

            // Addons.
            $addons = $this->build_addons( $item, $wp_set_id, $wp_product_id, $set_item_variant_id, $maps );

            // optional_item_price_update_needed mirrors api.php's
            // current calculation: ties to has_selected only. The bulk
            // feed adds a second clause (count(optional_items) < 2)
            // that we deliberately don't replicate here — keeping
            // observable behaviour the same.
            $price_update_needed = $has_selected_flag ? 1 : 0;

            // UID + division + inventory identifiers — required by
            // Rental_Sets_Section_Presenter for simple-item lookup in
            // the modern renderer (`resolve_simple_item_uid` falls
            // through to "{division_id}-{inv_id}" when uid is absent,
            // which is the typical case from the bulk feed).
            //
            // The keys are conditionally inserted: when the webhook
            // payload omits one, we leave it OUT of $items_row so
            // `Rental_Sets_Webhook_Defense` can restore the prior
            // bulk-feed value. See `item_preserve_fields`.
            //
            // The webhook may ship the inventory id under either
            // `inventory_id` (matching the bulk feed) or `inv_id`
            // (the WP-side normalised name) depending on how
            // `SetWebhook::getSet()` serialises it. We accept either
            // and store under `inv_id` for downstream consumption.
            $items_row = array();
            if ( isset( $item->uid ) ) {
                $items_row['uid'] = (string) $item->uid;
            }
            if ( isset( $item->division_id ) ) {
                $items_row['division_id'] = $division_id;
            }
            if ( isset( $item->inventory_id ) ) {
                $items_row['inv_id'] = (int) $item->inventory_id;
            } elseif ( isset( $item->inv_id ) ) {
                $items_row['inv_id'] = (int) $item->inv_id;
            }

            $items[] = array_merge( $items_row, array(
                'rental_variant_id'                 => $rental_variant_id,
                'rental_product_id'                 => $rental_product_id,
                'parent_set_id'                     => (int) $wp_set_id,
                'product_id'                        => $wp_product_id,
                'variant_id'                        => $set_item_variant_id,
                'quantity'                          => isset( $item->quantity ) ? $item->quantity : 0,
                'optional_items'                    => $optional_items,
                'has_selected'                      => $has_selected_flag,
                'optional_item_price_update_needed' => $price_update_needed,
                'hidden'                            => $hidden_flag,
                // The next three are absent from the webhook payload by
                // design (getSet()'s GROUP_CONCAT SQL doesn't ship
                // them). `Rental_Sets_Webhook_Defense` restores them
                // from prior postmeta when both sides match by
                // (product_id, variant_id). On a brand-new set we
                // default to zero — matches the bulk-feed builder.
                'price'                             => isset( $item->price ) ? $item->price : 0,
                'separate_price'                    => isset( $item->separate_price ) ? $item->separate_price : 0,
                'required'                          => isset( $item->required ) ? $item->required : 0,
                'addons'                            => $addons,
            ) );
        }

        return array(
            'items'              => $items,
            'has_optional_items' => $has_optional_items,
            'has_hidden_items'   => $has_hidden_items,
            'skipped_count'      => $skipped_count,
        );
    }

    /**
     * Resolve each optional item by its OWN product id + division —
     * matches the bulk-feed builder. (api.php's current behaviour used
     * the parent's WP product id for every optional, which produces a
     * wrong product reference in the rare case that an optional
     * resolves to a different product than its parent.)
     *
     * @param object $item
     * @param array  $maps
     * @return array
     */
    protected function build_optional_items( $item, array $maps ) {
        if ( ! isset( $item->optional_items ) || ! is_array( $item->optional_items ) ) {
            return array();
        }

        $out = array();
        foreach ( $item->optional_items as $raw_opt ) {
            $opt = $this->cast_object( $raw_opt );
            if ( null === $opt ) {
                continue;
            }

            $division_id       = $this->prop_int( $opt, 'division_id' );
            $rental_product_id = $this->prop_int( $opt, 'product_id' );
            $rental_variant_id = $this->prop_int( $opt, 'variant_id' );

            $pkey = $rental_product_id . ':' . $division_id;
            $vkey = $rental_variant_id . ':' . $division_id;

            $wp_product_id = isset( $maps['products'][ $pkey ] ) ? (int) $maps['products'][ $pkey ] : 0;
            $wp_variant_id = isset( $maps['variants'][ $vkey ] ) ? (int) $maps['variants'][ $vkey ] : null;

            $out[] = array(
                'rental_product_id' => $rental_product_id,
                'rental_variant_id' => $rental_variant_id,
                'product_id'        => $wp_product_id,
                'variant_id'        => $wp_variant_id,
                'quantity'          => isset( $opt->quantity ) ? $opt->quantity : 0,
                'is_selected'       => isset( $opt->is_selected ) && $opt->is_selected ? 1 : 0,
            );
        }
        return $out;
    }

    /**
     * Mirror `Rental_Sets_Item_Builder::build_addons` field-for-field.
     * Backfills `parent_set_id`, `parent_set_product_id`, and
     * `parent_set_variant_id` — three fields the receiver's current
     * inline path doesn't write.
     *
     * @param object $item
     * @param int    $wp_set_id
     * @param int    $parent_product_id
     * @param mixed  $parent_variant_id
     * @param array  $maps
     * @return array
     */
    protected function build_addons( $item, $wp_set_id, $parent_product_id, $parent_variant_id, array $maps ) {
        if ( ! isset( $item->addons ) || ! is_array( $item->addons ) || empty( $item->addons ) ) {
            return array();
        }

        $out = array();
        foreach ( $item->addons as $raw_addon ) {
            $addon = $this->cast_object( $raw_addon );
            if ( null === $addon ) {
                continue;
            }

            $division_id       = $this->prop_int( $addon, 'division_id' );
            $rental_product_id = $this->prop_int( $addon, 'product_id' );
            $rental_variant_id = $this->prop_int( $addon, 'add_on_variant_id' );

            $pkey = $rental_product_id . ':' . $division_id;
            $vkey = $rental_variant_id . ':' . $division_id;

            $wp_addon_product_id = isset( $maps['products'][ $pkey ] ) ? (int) $maps['products'][ $pkey ] : 0;
            $wp_addon_variant_id = isset( $maps['variants'][ $vkey ] ) ? (int) $maps['variants'][ $vkey ] : 0;

            $variants_optional = $this->build_variants_optional( $addon, $maps );

            $out[] = array(
                'parent_set_id'         => (int) $wp_set_id,
                'parent_set_product_id' => (int) $parent_product_id,
                'parent_set_variant_id' => $parent_variant_id, // may be null — match bulk feed
                'rental_inv_id'         => isset( $addon->id ) ? $addon->id : 0,
                'product_id'            => $wp_addon_product_id,
                'rental_product_id'     => $rental_product_id,
                'variant_id'            => $wp_addon_variant_id,
                'rental_variant_id'     => $rental_variant_id,
                'quantity'              => isset( $addon->add_on_quantity ) ? $addon->add_on_quantity : 0,
                'price'                 => isset( $addon->price ) ? $addon->price : 0,
                'required'              => isset( $addon->required ) ? $addon->required : 0,
                'hidden'                => isset( $addon->hidden ) ? $addon->hidden : 0,
                'product_price'         => isset( $addon->product_price ) ? $addon->product_price : 0,
                'inherit_price'         => isset( $addon->inherit_price ) ? $addon->inherit_price : 0,
                'variants_optional'     => $variants_optional,
            );
        }
        return $out;
    }

    /**
     * Mirror `Rental_Sets_Item_Builder::build_addons` — its inner
     * variants_optional loop, extracted for clarity.
     *
     * @param object $addon
     * @param array  $maps
     * @return array
     */
    protected function build_variants_optional( $addon, array $maps ) {
        if ( ! isset( $addon->variants_optional ) || ! is_array( $addon->variants_optional ) || empty( $addon->variants_optional ) ) {
            return array();
        }
        $out = array();
        foreach ( $addon->variants_optional as $raw_var ) {
            $var = $this->cast_object( $raw_var );
            if ( null === $var ) {
                continue;
            }

            $division_id       = $this->prop_int( $var, 'division_id' );
            $rental_product_id = $this->prop_int( $var, 'product_id' );
            $rental_variant_id = $this->prop_int( $var, 'variant_id' );

            $pkey = $rental_product_id . ':' . $division_id;
            $vkey = $rental_variant_id . ':' . $division_id;

            $out[] = array(
                'product_id'        => isset( $maps['products'][ $pkey ] ) ? (int) $maps['products'][ $pkey ] : 0,
                'rental_product_id' => $rental_product_id,
                'variant_id'        => isset( $maps['variants'][ $vkey ] ) ? (int) $maps['variants'][ $vkey ] : 0,
                'rental_variant_id' => $rental_variant_id,
                'quantity'          => isset( $var->quantity ) ? $var->quantity : 0,
                'default'           => isset( $var->default ) ? $var->default : 0,
            );
        }
        return $out;
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Tolerate array | object | null. Returns an object or null.
     *
     * @param mixed $v
     * @return object|null
     */
    protected function cast_object( $v ) {
        if ( is_object( $v ) ) {
            return $v;
        }
        if ( is_array( $v ) ) {
            return (object) $v;
        }
        return null;
    }

    /**
     * Read an integer-coerced property off an object, tolerating both
     * present and absent / null values.
     *
     * @param object $o
     * @param string $prop
     * @return int
     */
    protected function prop_int( $o, $prop ) {
        if ( ! isset( $o->$prop ) ) {
            return 0;
        }
        return (int) $o->$prop;
    }

    /**
     * Logging helper that degrades gracefully when Project_WP_Logger
     * isn't available.
     *
     * @param string $message
     * @param string $level
     * @return void
     */
    protected function log( $message, $level = 'info' ) {
        if ( class_exists( 'Project_WP_Logger', false )
            && method_exists( 'Project_WP_Logger', 'write' ) ) {
            Project_WP_Logger::write( $message, $level, self::LOG_SOURCE );
        }
    }
}

endif;
