<?php
/**
 * Rental_Sets_Webhook_Defense
 *
 * Defensive normalisation + merge for single-set webhook writes.
 *
 * Background. The Laravel server has two paths that ship sets to WP:
 *
 *   - **Bulk feed** (`InventorySets::getForApiRaw`) — used during the
 *     initial sync and resync. Ships a complete shape per item:
 *     `price`, `separate_price`, `required`, `hide_on_website`, `note`,
 *     and every addon field the bulk feed builder needs.
 *
 *   - **Single-set webhook** (`SetWebhook::createSet/updateSet`) —
 *     fired when an admin edits one set in the Laravel UI. The webhook
 *     calls `getSet()` and `enrichSet()`, which assembles the payload
 *     from two independent code paths:
 *
 *       a. **Entity 1+2 (items + selectable + addons)** — built by the
 *          GROUP_CONCAT SQL inside `getSet()` plus `getSetsOptionalItems()`
 *          and `Inventory::getProductAddOns()`. The fields it ships do
 *          NOT cover the full bulk-feed set:
 *
 *            Top-level set items ship:
 *                product_id, variant_id, division_id, inventory_id,
 *                quantity, hidden, inventory_sets_id
 *                + has_selected / optional_items (when applicable)
 *            … and are MISSING `price`, `separate_price`, `required`,
 *            `note`. The WP-side bulk-feed builder fills these in.
 *
 *            Addons ship:
 *                id, add_on_quantity, add_on_id, add_on_variant_id,
 *                required, parent_product_id, inherit_price, price,
 *                product_price, hidden, product_variant_id, product_id,
 *                division_id, quantity
 *            … note that addons DO ship `price`, `required`, `hidden` —
 *            so there's no missing-field gap on addons. But the
 *            webhook's key for an addon is `id`, whereas the bulk-feed
 *            builder writes it as `rental_inv_id`. The defense must
 *            match across both names AND backfill the `rental_inv_id`
 *            alias on the webhook side so downstream code reading
 *            `rental_inv_id` keeps working after every webhook.
 *
 *       b. **Entity 3 (grouped_items)** — built by the SHARED service
 *          `SetGroupedItemsService::getForSet()`. The same service is
 *          used by the bulk feed, so the shapes are byte-equivalent.
 *          No field gap exists. Defense still runs as forward-compat
 *          (no-op when nothing is missing) and to perform type
 *          normalisation: Laravel ships tinyints, the WP plugin code
 *          mostly handles them, but a few helpers expect concrete
 *          ints/bools/arrays — null/missing values would otherwise
 *          surface as warnings later.
 *
 * What this class does on every `update_post_metadata` for the two
 * affected keys:
 *
 *   1. If the prior value isn't an array (first write) — skip; the
 *      raw webhook payload is what we keep.
 *   2. Defensively MERGE: any field in the bulk-feed prior that the
 *      webhook didn't ship gets restored. Matching uses stable keys —
 *      `(product_id, variant_id)` for entity-1/2 items;
 *      `rental_inv_id || id` cross-matching for addons; `uid` for
 *      entity-3 groups and their items.
 *   3. NORMALISE entity-3 values: coerce booleans/tinyints to int(0|1),
 *      coerce nullable min/max bounds to int(0), coerce null
 *      `items_order` to `[]`. Idempotent — no-op when already typed.
 *   4. Validate group UID shape (informational log only, never blocks).
 *   5. If anything changed, write the corrected value ourselves and
 *      short-circuit the original update.
 *
 * Idempotent. Zero round-trips. Bulk feed and unaffected meta writes
 * see no work — short-circuit triggers only when the merged shape
 * differs from the incoming shape.
 *
 * Filters:
 *   - `rental_sets_webhook_defense_enabled`           (bool, $post_id, $meta_key)
 *   - `rental_sets_webhook_defense_item_fields`       (string[])  fields on entity-1/2 items
 *   - `rental_sets_webhook_defense_addon_fields`      (string[])  fields on addons
 *   - `rental_sets_webhook_defense_group_fields`      (string[])  fields on entity-3 groups
 *   - `rental_sets_webhook_defense_group_item_fields` (string[])  fields on entity-3 group items
 *
 * @package RentopianSync\Sets
 * @since   2.14.7
 *
 * PHP requirement: 7.4+
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'Rental_Sets_Webhook_Defense', false ) ) :

class Rental_Sets_Webhook_Defense {

    /**
     * @var string
     */
    protected $log_source = 'rentopian-sets-sync';

    /**
     * Fields the webhook is known to drop on entity-1/2 top-level set
     * items. The webhook's GROUP_CONCAT SQL in `SetWebhook::getSet()`
     * doesn't include any of these, so the defense restores them from
     * the prior bulk-feed value whenever both sides match by
     * `(product_id, variant_id)`.
     *
     * @var string[]
     */
    protected $item_preserve_fields = array(
        'price',
        'separate_price',
        'required',
        'hide_on_website',
        'note',
        // Defended for stability across webhook runs (not strictly
        // missing from webhook but the receiver may strip them):
        'optional_item_price_update_needed',
        'parent_set_id',
        // Modern-renderer keys. The webhook payload may or may not
        // ship them depending on `SetWebhook::getSet()` evolution;
        // restoring from prior keeps the modern renderer functional
        // across webhook updates that omit them. The persister also
        // skips writing these keys when the API doesn't supply them,
        // so the array_key_exists check in merge_set_items() will
        // trigger restoration.
        //
        // `inv_id` is the critical one: section presenter's simple-
        // item UID fallback is "{division_id}-{inv_id}", and without
        // it the modern renderer drops every simple item.
        'uid',
        'division_id',
        'inv_id',
    );

    /**
     * Fields on addons. The webhook DOES ship `price`, `required`,
     * `hidden`, `quantity`, `product_price`, `inherit_price` directly,
     * so they're rarely missing in practice. The list is mostly here
     * to defend against future Laravel reductions and to backfill the
     * WP-only mapping fields (`parent_set_*`) that the webhook handler
     * may not always compute.
     *
     * @var string[]
     */
    protected $addon_preserve_fields = array(
        'parent_set_id',
        'parent_set_product_id',
        'parent_set_variant_id',
        'rental_product_id',
        'rental_variant_id',
        'variants_optional',
    );

    /**
     * Fields the webhook is theoretically complete on (because
     * `SetGroupedItemsService` is shared with the bulk feed), but
     * defended as forward-compat. Listed in the order
     * `SetGroupedItemsService::getForSets()` emits them.
     *
     * @var string[]
     */
    protected $group_preserve_fields = array(
        'group_id',
        'set_id',
        'group_name',
        'group_description',
        'group_price',
        'group_quantity',
        'group_quantity_min',
        'group_quantity_max',
        'multiple_selection',
        'required',
        'separate_price',
        'hide_on_website',
        'items_order',
    );

    /**
     * Per-item fields inside a group (`grouped_items[*].items[*]`).
     *
     * @var string[]
     */
    protected $group_item_preserve_fields = array(
        'group_item_id',
        'set_group_id',
        'set_id',
        'inv_id',
        'price',
        'quantity',
        'is_default',
        'product_id',
        'variant_id',
        'division_id',
        'rental_price',
        'sale_price',
        'product_name',
        'variant_name',
        'variant_img_id',
        'product_img_id',
    );

    /**
     * @var self|null
     */
    protected static $instance = null;

    public static function register() {
        if ( null !== self::$instance ) {
            return;
        }
        self::$instance = new self();
        self::$instance->boot();
    }

    /**
     * Hook in. The `update_post_metadata` filter fires before the meta
     * is written; we read the prior value, decide if defense is needed,
     * mutate the incoming `$meta_value`, and either let WP proceed
     * (return $check) or short-circuit by writing the corrected value
     * ourselves.
     */
    protected function boot() {
        // Resolve filterable field lists once on boot. Filter callers
        // can still alter on a per-call basis through the
        // `maybe_defend` flow because the lists are re-read there too.
        $this->item_preserve_fields       = (array) apply_filters( 'rental_sets_webhook_defense_item_fields',       $this->item_preserve_fields );
        $this->addon_preserve_fields      = (array) apply_filters( 'rental_sets_webhook_defense_addon_fields',      $this->addon_preserve_fields );
        $this->group_preserve_fields      = (array) apply_filters( 'rental_sets_webhook_defense_group_fields',      $this->group_preserve_fields );
        $this->group_item_preserve_fields = (array) apply_filters( 'rental_sets_webhook_defense_group_item_fields', $this->group_item_preserve_fields );

        add_filter( 'update_post_metadata', array( $this, 'maybe_defend' ), 20, 5 );
    }

    /**
     * @var bool  Re-entry guard so our own update_metadata call doesn't
     *            recurse through this filter.
     */
    protected $defending = false;

    /**
     * Filter callback.
     *
     * @param mixed   $check       null = let WP proceed; non-null = override.
     * @param int     $object_id   Post ID.
     * @param string  $meta_key
     * @param mixed   $meta_value
     * @param mixed   $prev_value  Filter's $prev_value parameter (for matching).
     * @return mixed  Returns null in normal cases; returns the result of
     *                update_metadata() when we performed defensive merge.
     */
    public function maybe_defend( $check, $object_id, $meta_key, $meta_value, $prev_value ) {

        if ( $this->defending ) {
            // Re-entry from our own update_metadata call — let it through.
            return $check;
        }

        // Filter only acts on the two affected keys.
        if ( '_rental_set_items' !== $meta_key && '_rental_set_grouped_items' !== $meta_key ) {
            return $check;
        }

        if ( ! apply_filters( 'rental_sets_webhook_defense_enabled', true, $object_id, $meta_key ) ) {
            return $check;
        }

        // Skip if the new value isn't an array — webhook always sends arrays.
        if ( ! is_array( $meta_value ) ) {
            return $check;
        }

        $prior = get_post_meta( $object_id, $meta_key, true );
        $has_prior = is_array( $prior ) && ! empty( $prior );

        if ( '_rental_set_items' === $meta_key ) {
            $merged = $has_prior
                ? $this->merge_set_items( $meta_value, $prior )
                : $meta_value;
        } else {
            // _rental_set_grouped_items: always normalise types, then
            // merge from prior (when present) for forward-compat.
            $merged = $this->normalise_grouped_items( $meta_value );
            if ( $has_prior ) {
                $merged = $this->merge_grouped_items( $merged, $prior );
            }
            // Informational UID-shape warning. Never blocks.
            $this->validate_group_uids( $object_id, $merged );
        }

        // Quick exit if no changes — let WP proceed normally.
        if ( ! $this->shapes_differ( $merged, $meta_value ) ) {
            return $check;
        }

        Project_WP_Logger::write(
            sprintf(
                'Webhook_Defense: normalised/restored fields on %s for set %d.',
                $meta_key,
                (int) $object_id
            ),
            'info',
            $this->log_source
        );

        // Short-circuit by writing the merged value ourselves and
        // returning a non-null. Set the guard so our own write doesn't
        // re-trigger this filter.
        $this->defending = true;
        $result = update_metadata( 'post', $object_id, $meta_key, $merged, $prev_value );
        $this->defending = false;

        return $result;
    }

    /*
    |--------------------------------------------------------------------------
    | Entity 1+2 (set items + addons)
    |--------------------------------------------------------------------------
    */

    /**
     * Merge entity-1/2 items. Top-level items match by
     * `(product_id, variant_id)`. Each item's `addons` array (if any)
     * merges through `merge_addons()`.
     *
     * Items that exist only in prior (not in new) are NOT restored —
     * those reflect Laravel-side deletions and must propagate.
     *
     * @param array $new
     * @param array $prior
     * @return array
     */
    protected function merge_set_items( array $new, array $prior ) {
        $idx = array();
        foreach ( $prior as $p ) {
            if ( ! is_array( $p ) ) {
                continue;
            }
            $key = (int) ( $p['product_id'] ?? 0 ) . ':' . (int) ( $p['variant_id'] ?? 0 );
            $idx[ $key ] = $p;
        }

        foreach ( $new as $i => $item ) {
            if ( ! is_array( $item ) ) {
                continue;
            }
            $key = (int) ( $item['product_id'] ?? 0 ) . ':' . (int) ( $item['variant_id'] ?? 0 );
            if ( ! isset( $idx[ $key ] ) ) {
                continue;
            }
            $prior_item = $idx[ $key ];

            foreach ( $this->item_preserve_fields as $f ) {
                if ( ! array_key_exists( $f, $item ) && array_key_exists( $f, $prior_item ) ) {
                    $item[ $f ] = $prior_item[ $f ];
                }
            }

            // Recurse on addons.
            if ( ! empty( $item['addons'] ) && is_array( $item['addons'] )
                && ! empty( $prior_item['addons'] ) && is_array( $prior_item['addons'] ) ) {
                $item['addons'] = $this->merge_addons( $item['addons'], $prior_item['addons'] );
            }

            $new[ $i ] = $item;
        }
        return $new;
    }

    /**
     * Cross-key addon matching.
     *
     * The bulk feed indexes addons by `rental_inv_id` (= Laravel
     * `inventory.id`). The webhook ships them with `id` (= same
     * Laravel `inventory.id`). The defense matches across both, and
     * backfills `rental_inv_id` from `id` when missing so downstream
     * WP code that reads `rental_inv_id` keeps working after every
     * webhook write.
     *
     * @param array $new
     * @param array $prior
     * @return array
     */
    protected function merge_addons( array $new, array $prior ) {
        $idx = array();
        foreach ( $prior as $p ) {
            if ( ! is_array( $p ) ) {
                continue;
            }
            $key = $this->addon_match_key( $p );
            if ( $key > 0 ) {
                $idx[ $key ] = $p;
            }
        }
        foreach ( $new as $i => $a ) {
            if ( ! is_array( $a ) ) {
                continue;
            }
            $key = $this->addon_match_key( $a );
            if ( $key <= 0 || ! isset( $idx[ $key ] ) ) {
                continue;
            }
            $p = $idx[ $key ];

            // Backfill the canonical alias the bulk feed stores under.
            if ( ! array_key_exists( 'rental_inv_id', $a ) ) {
                $a['rental_inv_id'] = $key;
            }

            foreach ( $this->addon_preserve_fields as $f ) {
                if ( ! array_key_exists( $f, $a ) && array_key_exists( $f, $p ) ) {
                    $a[ $f ] = $p[ $f ];
                }
            }

            $new[ $i ] = $a;
        }
        return $new;
    }

    /**
     * Stable matching key for an addon. Prefers `rental_inv_id`
     * (bulk-feed key), falls back to `id` (webhook key).
     *
     * @param array $addon
     * @return int  Returns 0 when no usable key is present.
     */
    protected function addon_match_key( array $addon ) {
        if ( isset( $addon['rental_inv_id'] ) && (int) $addon['rental_inv_id'] > 0 ) {
            return (int) $addon['rental_inv_id'];
        }
        if ( isset( $addon['id'] ) && (int) $addon['id'] > 0 ) {
            return (int) $addon['id'];
        }
        return 0;
    }

    /*
    |--------------------------------------------------------------------------
    | Entity 3 (grouped_items)
    |--------------------------------------------------------------------------
    */

    /**
     * Type-normalise the new payload's entity-3 value.
     *
     * Laravel ships:
     *   - tinyints for booleans (multiple_selection, required, separate_price, hide_on_website, is_default)
     *   - nullable INT columns for group_quantity_min / group_quantity_max
     *   - json-encoded items_order column → array | null
     *
     * Plugin code expects ints (cast through `(int)`) and arrays. The
     * normalisation coerces nulls / strings to safe defaults without
     * losing data.
     *
     * @param array $groups
     * @return array
     */
    protected function normalise_grouped_items( array $groups ) {
        foreach ( $groups as $gi => $group ) {
            if ( ! is_array( $group ) ) {
                continue;
            }

            // Boolean-ish to 0|1.
            foreach ( array( 'multiple_selection', 'required', 'separate_price', 'hide_on_website' ) as $bf ) {
                if ( array_key_exists( $bf, $group ) ) {
                    $group[ $bf ] = $this->to_bool_int( $group[ $bf ] );
                }
            }

            // Nullable bounds → 0.
            foreach ( array( 'group_quantity_min', 'group_quantity_max', 'group_quantity' ) as $nf ) {
                if ( array_key_exists( $nf, $group ) && null === $group[ $nf ] ) {
                    $group[ $nf ] = 0;
                }
            }

            // items_order: null/string → array.
            if ( array_key_exists( 'items_order', $group ) ) {
                if ( null === $group['items_order'] ) {
                    $group['items_order'] = array();
                } elseif ( is_string( $group['items_order'] ) ) {
                    $decoded = json_decode( $group['items_order'], true );
                    $group['items_order'] = is_array( $decoded ) ? $decoded : array();
                } elseif ( ! is_array( $group['items_order'] ) ) {
                    $group['items_order'] = array();
                }
            }

            // group_price: null → empty string (preserves the
            // "no override" semantics expected by the section
            // presenter).
            if ( array_key_exists( 'group_price', $group ) && null === $group['group_price'] ) {
                $group['group_price'] = '';
            }

            // Items inside group: normalise is_default.
            if ( ! empty( $group['items'] ) && is_array( $group['items'] ) ) {
                foreach ( $group['items'] as $ii => $item ) {
                    if ( ! is_array( $item ) ) {
                        continue;
                    }
                    if ( array_key_exists( 'is_default', $item ) ) {
                        $item['is_default'] = $this->to_bool_int( $item['is_default'] );
                    }
                    if ( array_key_exists( 'quantity', $item ) && null === $item['quantity'] ) {
                        $item['quantity'] = 1;
                    }
                    if ( array_key_exists( 'price', $item ) && null === $item['price'] ) {
                        $item['price'] = '';
                    }
                    $group['items'][ $ii ] = $item;
                }
            }

            $groups[ $gi ] = $group;
        }
        return $groups;
    }

    /**
     * Coerce a tinyint / bool / string to int(0|1).
     *
     * @param mixed $v
     * @return int
     */
    protected function to_bool_int( $v ) {
        if ( is_bool( $v ) ) {
            return $v ? 1 : 0;
        }
        if ( null === $v ) {
            return 0;
        }
        if ( is_numeric( $v ) ) {
            return ( (int) $v ) ? 1 : 0;
        }
        return $v ? 1 : 0;
    }

    /**
     * Forward-compat merge of entity-3. Matches groups by `uid`; only
     * restores fields that are absent from the new value. Groups that
     * exist only in prior are NOT restored (they represent Laravel
     * deletions).
     *
     * @param array $new
     * @param array $prior
     * @return array
     */
    protected function merge_grouped_items( array $new, array $prior ) {
        $idx = array();
        foreach ( $prior as $g ) {
            if ( ! is_array( $g ) ) {
                continue;
            }
            $u = isset( $g['uid'] ) ? (string) $g['uid'] : '';
            if ( '' !== $u ) {
                $idx[ $u ] = $g;
            }
        }
        foreach ( $new as $i => $g ) {
            if ( ! is_array( $g ) ) {
                continue;
            }
            $u = isset( $g['uid'] ) ? (string) $g['uid'] : '';
            if ( '' === $u || ! isset( $idx[ $u ] ) ) {
                continue;
            }
            $prior_g = $idx[ $u ];

            foreach ( $this->group_preserve_fields as $f ) {
                if ( ! array_key_exists( $f, $g ) && array_key_exists( $f, $prior_g ) ) {
                    $g[ $f ] = $prior_g[ $f ];
                }
            }

            // Group items: merge by item-level uid.
            if ( ! empty( $g['items'] ) && is_array( $g['items'] )
                && ! empty( $prior_g['items'] ) && is_array( $prior_g['items'] ) ) {
                $g['items'] = $this->merge_group_items( $g['items'], $prior_g['items'] );
            }

            $new[ $i ] = $g;
        }
        return $new;
    }

    /**
     * Match group items by `uid`. Items only in prior aren't restored
     * — they represent soft-deleted items.
     *
     * @param array $new
     * @param array $prior
     * @return array
     */
    protected function merge_group_items( array $new, array $prior ) {
        $idx = array();
        foreach ( $prior as $p ) {
            if ( ! is_array( $p ) ) {
                continue;
            }
            $u = isset( $p['uid'] ) ? (string) $p['uid'] : '';
            if ( '' !== $u ) {
                $idx[ $u ] = $p;
            }
        }
        foreach ( $new as $i => $it ) {
            if ( ! is_array( $it ) ) {
                continue;
            }
            $u = isset( $it['uid'] ) ? (string) $it['uid'] : '';
            if ( '' === $u || ! isset( $idx[ $u ] ) ) {
                continue;
            }
            $prior_it = $idx[ $u ];
            foreach ( $this->group_item_preserve_fields as $f ) {
                if ( ! array_key_exists( $f, $it ) && array_key_exists( $f, $prior_it ) ) {
                    $it[ $f ] = $prior_it[ $f ];
                }
            }
            $new[ $i ] = $it;
        }
        return $new;
    }

    /**
     * Warn on group UIDs that don't match the expected
     * `{division_id}-grp-{22ch base64url}` shape. Logs only — never
     * blocks the write, since a malformed UID still works as long as
     * it's stable.
     *
     * @param int   $set_id
     * @param array $groups
     * @return void
     */
    protected function validate_group_uids( $set_id, array $groups ) {
        $pattern = '/^\d+\-grp\-[A-Za-z0-9_\-]{22}$/';
        foreach ( $groups as $g ) {
            if ( ! is_array( $g ) ) {
                continue;
            }
            $uid = isset( $g['uid'] ) ? (string) $g['uid'] : '';
            if ( '' === $uid ) {
                continue;
            }
            if ( ! preg_match( $pattern, $uid ) ) {
                Project_WP_Logger::write(
                    sprintf(
                        'Webhook_Defense: group UID "%s" on set %d does not match expected {division}-grp-{22ch} shape. Continuing.',
                        $uid,
                        (int) $set_id
                    ),
                    'warning',
                    $this->log_source
                );
            }
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Cheap structural diff used to decide whether to short-circuit.
     *
     * @param mixed $a
     * @param mixed $b
     * @return bool
     */
    protected function shapes_differ( $a, $b ) {
        return wp_json_encode( $a ) !== wp_json_encode( $b );
    }
}

endif;
