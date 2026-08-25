<?php
/**
 * Rental_Sets_Webhook_Group_Persister
 *
 * Entity-3 (composite groups) persistence for the single-set webhook
 * flow. Mirrors what `Rental_Sets_Group_Builder` does on the bulk-sync
 * path so both flows produce byte-equivalent `_rental_set_grouped_items`
 * postmeta — no consumer downstream has to handle two shapes.
 *
 * Why this class exists. The api.php webhook receivers
 * (`rentopian_set_create` / `rentopian_set_update`) historically only
 * walked `$set->items` (entities 1+2) and never touched
 * `$set->grouped_items`. The Laravel side has shipped `grouped_items`
 * via `SetWebhook::enrichSet()` since the introduction of
 * `SetGroupedItemsService` — but the data was discarded on arrival.
 * The result was that a set created or edited in the Laravel UI had
 * stale or missing entity-3 data on the WP side until the next full
 * bulk sync.
 *
 * This class fixes that gap. Invocation is from api.php:
 *
 *   if ( class_exists( 'Rental_Sets_Webhook_Group_Persister', false ) ) {
 *       Rental_Sets_Webhook_Group_Persister::persist( $set_id, $set );
 *   }
 *
 * The `class_exists` gate keeps the call backward-compatible: if the
 * sets module isn't loaded for some reason, the legacy entity-1/2 code
 * still runs and the webhook responds with HTTP 200 — exactly what it
 * does today.
 *
 * Persistence contract:
 *
 *   - `grouped_items` absent on payload → no-op. The previously-stored
 *     postmeta is preserved (a deliberate "old Laravel compatibility"
 *     guard so a downgrade never silently wipes synced groups).
 *
 *   - `grouped_items === []` → wipe. Three postmeta keys are written
 *     to their empty values so a Laravel-side delete of all groups
 *     propagates correctly.
 *
 *   - `grouped_items` is a non-empty array → resolve each Laravel
 *     `(product_id, division_id)` / `(variant_id, division_id)` pair
 *     to its WP id via a SINGLE batch query per relations table
 *     (no N+1), then write the resolved array.
 *
 * Postmeta written:
 *
 *   - `_rental_set_grouped_items`      (array, Group_Builder shape)
 *   - `_rental_set_has_grouped_items`  (int 0|1)
 *   - `_rental_set_some_hidden_groups` (int 0|1)
 *
 * Postmeta intentionally NOT touched:
 *
 *   - `_rental_set_order` — the bulk feed ships `$set->set_order` and
 *     the SQL builder writes it; the webhook payload doesn't include
 *     it, so we leave the prior value alone.
 *   - `_rental_sets_layout_mode` — per-installation, not per-set.
 *
 * Interaction with Rental_Sets_Webhook_Defense. The defense class
 * hooks `update_post_metadata` on `_rental_set_grouped_items` and
 * runs its type normalisation (coerce booleans, null bounds, null
 * `items_order` to safe defaults). This persister produces values
 * that are ALREADY well-typed, so the defense filter will short-
 * circuit on `shapes_differ()` returning false in the happy path.
 * The defense is still useful as a second line — e.g. when a future
 * payload variation slips a null past the persister's coercions.
 *
 * @package RentopianSync\Sets
 * @since   2.14.8
 *
 * PHP requirement: 7.4+
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'Rental_Sets_Webhook_Group_Persister', false ) ) :

class Rental_Sets_Webhook_Group_Persister {

    /**
     * Log source tag.
     */
    const LOG_SOURCE = 'rentopian-sets-sync';

    /**
     * Static entry-point used by api.php.
     *
     * @param int          $wp_set_id    The WP post id of the set being updated.
     * @param object|array $set_payload  The webhook's $set object — typically the
     *                                   stdClass result of json_decode().
     * @return array Diagnostic info: { written: bool, group_count: int, item_count: int, missing_ids: array }
     */
    public static function persist( $wp_set_id, $set_payload ) {
        $instance = new self();
        return $instance->run( (int) $wp_set_id, $set_payload );
    }

    /**
     * @param int          $wp_set_id
     * @param object|array $set_payload
     * @return array
     */
    protected function run( $wp_set_id, $set_payload ) {
        $diag = array(
            'written'     => false,
            'group_count' => 0,
            'item_count'  => 0,
            'missing_ids' => array(),
        );

        if ( $wp_set_id <= 0 ) {
            return $diag;
        }

        // Allow third-party code to disable persistence on a per-set basis.
        if ( ! apply_filters( 'rental_sets_webhook_group_persister_enabled', true, $wp_set_id, $set_payload ) ) {
            return $diag;
        }

        $raw_groups = $this->extract_groups( $set_payload );
        if ( null === $raw_groups ) {
            // `grouped_items` key absent from the payload (old Laravel).
            // Preserve existing postmeta — do nothing.
            return $diag;
        }

        if ( empty( $raw_groups ) ) {
            // `grouped_items` present but empty — admin removed all
            // groups on the Laravel side. Propagate the deletion.
            update_post_meta( $wp_set_id, '_rental_set_grouped_items',      array() );
            update_post_meta( $wp_set_id, '_rental_set_has_grouped_items',  0 );
            update_post_meta( $wp_set_id, '_rental_set_some_hidden_groups', 0 );

            $this->log( sprintf(
                'Webhook_Group_Persister: cleared grouped_items on set %d (Laravel shipped empty array).',
                $wp_set_id
            ) );

            $diag['written'] = true;
            return $diag;
        }

        // Build the (rental_id, division_id) lookup pairs we need from
        // every group's items in one pass, then run two batched queries
        // and assemble the resolved groups.
        $pairs = $this->collect_lookup_pairs( $raw_groups );
        $maps  = $this->resolve_id_maps( $pairs );

        $built = $this->build_groups( $raw_groups, $maps, $wp_set_id );
        $groups            = $built['groups'];
        $has_groups        = ! empty( $groups );
        $has_hidden_groups = ! empty( $built['has_hidden_groups'] );

        update_post_meta( $wp_set_id, '_rental_set_grouped_items',      $groups );
        update_post_meta( $wp_set_id, '_rental_set_has_grouped_items',  $has_groups ? 1 : 0 );
        update_post_meta( $wp_set_id, '_rental_set_some_hidden_groups', $has_hidden_groups ? 1 : 0 );

        $diag['written']     = true;
        $diag['group_count'] = count( $groups );
        $diag['item_count']  = $built['item_count'];
        $diag['missing_ids'] = $built['missing_ids'];

        $this->log( sprintf(
            'Webhook_Group_Persister: wrote %d group(s), %d item(s) on set %d. Missing-id skips: %d.',
            $diag['group_count'],
            $diag['item_count'],
            $wp_set_id,
            count( $diag['missing_ids'] )
        ) );

        return $diag;
    }

    /*
    |--------------------------------------------------------------------------
    | Payload extraction
    |--------------------------------------------------------------------------
    */

    /**
     * Pull `grouped_items` off the webhook payload tolerantly. Returns:
     *
     *   - null  → key absent (don't touch postmeta)
     *   - []    → key present, empty
     *   - array → key present, non-empty (each entry is array|object)
     *
     * @param mixed $set_payload
     * @return array|null
     */
    protected function extract_groups( $set_payload ) {
        if ( is_object( $set_payload ) ) {
            // json_decode default → stdClass. `property_exists` is the
            // precise "did this key ship?" check; isset() would treat a
            // shipped null the same as an absent key, and we want to
            // know the difference.
            if ( ! property_exists( $set_payload, 'grouped_items' ) ) {
                return null;
            }
            $raw = $set_payload->grouped_items;
        } elseif ( is_array( $set_payload ) ) {
            if ( ! array_key_exists( 'grouped_items', $set_payload ) ) {
                return null;
            }
            $raw = $set_payload['grouped_items'];
        } else {
            return null;
        }

        // Tolerate a JSON-encoded string (some webhook callers
        // double-encode). The Group_Builder does the same.
        if ( is_string( $raw ) ) {
            $decoded = json_decode( $raw, false );
            $raw     = ( is_array( $decoded ) || is_object( $decoded ) ) ? $decoded : null;
        }

        if ( null === $raw ) {
            return null;
        }
        if ( ! is_array( $raw ) ) {
            // Single-object groups payload — wrap.
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
     * Walk every group's items, collect distinct
     * (rental_product_id, division_id) and (rental_variant_id, division_id)
     * pairs.
     *
     * @param array $raw_groups
     * @return array { products: [ [rid, did], ... ], variants: [ [rid, did], ... ] }
     */
    protected function collect_lookup_pairs( array $raw_groups ) {
        $products = array();
        $variants = array();

        foreach ( $raw_groups as $g ) {
            $g = is_object( $g ) ? (array) $g : (array) $g;
            if ( empty( $g['items'] ) || ! is_array( $g['items'] ) ) {
                continue;
            }
            foreach ( $g['items'] as $item ) {
                $item = is_object( $item ) ? (array) $item : (array) $item;

                $rp = isset( $item['product_id'] ) ? (int) $item['product_id'] : 0;
                $rv = isset( $item['variant_id'] ) ? (int) $item['variant_id'] : 0;
                $d  = isset( $item['division_id'] ) ? (int) $item['division_id'] : 0;

                if ( $rp > 0 ) {
                    $products[ $rp . ':' . $d ] = array( $rp, $d );
                }
                if ( $rv > 0 ) {
                    $variants[ $rv . ':' . $d ] = array( $rv, $d );
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
     * The relations tables use composite keys
     * `(rental_id, rental_division_id) → id`. We over-select on
     * `rental_id IN (...)` (the indexed column) and filter the
     * division match in PHP — single round-trip per table.
     *
     * @param array $pairs From collect_lookup_pairs().
     * @return array {
     *     products: [ "<rental_id>:<division_id>" => <wp_id> ],
     *     variants: [ "<rental_id>:<division_id>" => <wp_id> ],
     * }
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
        // The relations table name is built from $rental_tables, not
        // user input — no escaping concern here. The IN list IS
        // parameter-bound through wpdb->prepare().
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
    | Build canonical postmeta shape
    |--------------------------------------------------------------------------
    */

    /**
     * Mirror Group_Builder's per-group output shape exactly. Items that
     * don't resolve to either a WP product or a WP variant are dropped
     * (same conservative rule as Group_Builder).
     *
     * @param array $raw_groups
     * @param array $maps  From resolve_id_maps().
     * @param int   $wp_set_id
     * @return array { groups, has_hidden_groups, item_count, missing_ids }
     */
    protected function build_groups( array $raw_groups, array $maps, $wp_set_id ) {
        $groups            = array();
        $has_hidden_groups = false;
        $item_count        = 0;
        $missing_ids       = array();

        foreach ( $raw_groups as $g ) {
            $g = is_object( $g ) ? $g : (object) $g;

            $built_items = $this->build_group_items( $g, $maps, $missing_ids );
            $item_count += count( $built_items );

            $hide = isset( $g->hide_on_website ) && $g->hide_on_website ? 1 : 0;
            if ( $hide ) {
                $has_hidden_groups = true;
            }

            $items_order = $this->normalise_items_order( $g );

            // Per-group ordering diagnostic 
            $this->log( sprintf(
                'Webhook_Group_Persister: group "%s" (uid=%s) items=%d items_order=%d on set %d.',
                isset( $g->group_name ) ? (string) $g->group_name : '',
                isset( $g->uid ) ? (string) $g->uid : '',
                count( $built_items ),
                count( $items_order ),
                (int) $wp_set_id
            ) );

            $groups[] = array(
                'rental_group_id'    => isset( $g->group_id ) ? (int) $g->group_id : 0,
                'parent_set_id'      => (int) $wp_set_id,
                'uid'                => isset( $g->uid ) ? (string) $g->uid : '',
                'group_name'         => isset( $g->group_name ) ? (string) $g->group_name : '',
                'group_description'  => isset( $g->group_description ) ? (string) $g->group_description : '',
                'group_price'        => isset( $g->group_price ) ? $g->group_price : '',
                'group_quantity'     => isset( $g->group_quantity ) ? (int) $g->group_quantity : 1,
                'group_quantity_min' => isset( $g->group_quantity_min ) ? (int) $g->group_quantity_min : 0,
                'group_quantity_max' => isset( $g->group_quantity_max ) ? (int) $g->group_quantity_max : 0,
                'multiple_selection' => isset( $g->multiple_selection ) && $g->multiple_selection ? 1 : 0,
                'required'           => isset( $g->required ) && $g->required ? 1 : 0,
                'separate_price'     => isset( $g->separate_price ) && $g->separate_price ? 1 : 0,
                'hide_on_website'    => $hide,
                'items_order'        => $items_order,
                'items'              => $built_items,
            );
        }

        return array(
            'groups'            => $groups,
            'has_hidden_groups' => $has_hidden_groups,
            'item_count'        => $item_count,
            'missing_ids'       => $missing_ids,
        );
    }

    /**
     * Per-group item resolution. Returns the exact item shape
     * `Rental_Sets_Group_Builder::build_group_items` emits.
     *
     * @param object $g
     * @param array  $maps
     * @param array  $missing_ids  Output accumulator for unresolved ids.
     * @return array
     */
    protected function build_group_items( $g, array $maps, array &$missing_ids ) {
        if ( empty( $g->items ) || ! is_array( $g->items ) ) {
            return array();
        }

        // Explicit ordering authority. Laravel ships each group's child
        // order in `items_order` (a UID list in drag-drop order). We
        // resolve every item's position from it up-front so each STORED
        // item carries an explicit integer `ordering`. Items whose uid
        // isn't in items_order (or when items_order is absent) keep
        // their payload-array position, appended after the ordered ones.
        // The renderer then sorts strictly by this integer and never
        // relies on PHP array iteration order. This is what makes the
        // System the single source of truth for nested ordering.
        $order_index = array();
        $items_order = $this->normalise_items_order( $g );
        foreach ( $items_order as $pos => $uid ) {
            if ( '' !== $uid && ! isset( $order_index[ $uid ] ) ) {
                $order_index[ $uid ] = $pos;
            }
        }
        // Positions for unlisted items start after the explicit block so
        // they never interleave with the drag-drop-ordered ones.
        $append_cursor = count( $items_order );

        $out         = array();
        $payload_pos = 0;

        foreach ( $g->items as $raw_item ) {
            $item = is_object( $raw_item ) ? (array) $raw_item : (array) $raw_item;

            $rental_product_id = isset( $item['product_id'] ) ? (int) $item['product_id'] : 0;
            $rental_variant_id = isset( $item['variant_id'] ) ? (int) $item['variant_id'] : 0;
            $division_id       = isset( $item['division_id'] ) ? (int) $item['division_id'] : 0;
            $rental_inv_id     = isset( $item['inv_id'] ) ? (int) $item['inv_id'] : 0;

            $pkey = $rental_product_id . ':' . $division_id;
            $vkey = $rental_variant_id . ':' . $division_id;

            $wp_product_id = isset( $maps['products'][ $pkey ] ) ? (int) $maps['products'][ $pkey ] : 0;
            $wp_variant_id = isset( $maps['variants'][ $vkey ] ) ? (int) $maps['variants'][ $vkey ] : 0;

            // Same conservative rule the bulk-feed builder uses: drop
            // items that resolve to neither a WP product nor a WP
            // variant. They'd have nothing to render against.
            if ( ! $wp_product_id && ! $wp_variant_id ) {
                if ( $rental_product_id || $rental_variant_id ) {
                    $missing_ids[] = array(
                        'rental_product_id' => $rental_product_id,
                        'rental_variant_id' => $rental_variant_id,
                        'division_id'       => $division_id,
                        'uid'               => isset( $item['uid'] ) ? (string) $item['uid'] : '',
                    );
                }
                continue;
            }

            $uid = isset( $item['uid'] ) ? (string) $item['uid'] : '';

            // Resolve the explicit position: items_order first, then a
            // stable payload-order fallback that sorts AFTER the
            // explicitly-ordered block.
            if ( '' !== $uid && isset( $order_index[ $uid ] ) ) {
                $ordering = (int) $order_index[ $uid ];
            } else {
                $ordering = $append_cursor + $payload_pos;
            }
            $payload_pos++;

            $out[] = array(
                'rental_inv_id'     => $rental_inv_id,
                'rental_product_id' => $rental_product_id,
                'rental_variant_id' => $rental_variant_id,
                'product_id'        => $wp_product_id,
                'variant_id'        => $wp_variant_id,
                'uid'               => $uid,
                'ordering'          => $ordering,
                'quantity'          => isset( $item['quantity'] ) ? $item['quantity'] : 1,
                'price'             => isset( $item['price'] ) ? $item['price'] : '',
                'is_default'        => ! empty( $item['is_default'] ) ? 1 : 0,
                'rental_price'      => isset( $item['rental_price'] ) ? $item['rental_price'] : '',
                'sale_price'        => isset( $item['sale_price'] ) ? $item['sale_price'] : '',
                'product_name'      => isset( $item['product_name'] ) ? (string) $item['product_name'] : '',
                'variant_name'      => isset( $item['variant_name'] ) ? (string) $item['variant_name'] : '',
            );
        }

        // Freeze the explicit order into the stored array itself so the
        // renderer can rely on array order too, even if some consumer
        // ignores the `ordering` field.
        usort( $out, function ( $a, $b ) {
            $oa = isset( $a['ordering'] ) ? (int) $a['ordering'] : 0;
            $ob = isset( $b['ordering'] ) ? (int) $b['ordering'] : 0;
            return $oa <=> $ob;
        } );

        return $out;
    }

    /**
     * Same normalisation as Group_Builder: accept array | JSON string |
     * null, return clean array of string UIDs.
     *
     * @param object $g
     * @return string[]
     */
    protected function normalise_items_order( $g ) {
        if ( ! isset( $g->items_order ) ) {
            return array();
        }
        $val = $g->items_order;
        if ( is_string( $val ) ) {
            $decoded = json_decode( $val, true );
            $val     = is_array( $decoded ) ? $decoded : array();
        }
        if ( ! is_array( $val ) ) {
            return array();
        }
        $clean = array();
        foreach ( $val as $uid ) {
            $clean[] = (string) $uid;
        }
        return $clean;
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Logging helper that degrades gracefully when Project_WP_Logger
     * isn't available (e.g. plugin uninstalled, autoload race).
     *
     * @param string $message
     * @param string $level  info|warning|error
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
