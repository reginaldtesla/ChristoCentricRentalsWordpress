<?php
/**
 * Rental_Sets_Variant_Remapper
 *
 * Keeps a set's stored member references valid when the product sync
 * replaces the WordPress posts those references point at.
 *
 * The problem. `_rental_set_items[i]['variant_id']` holds a WordPress
 * *variation post id*, snapshotted when the set was last synced. The product
 * sync trashes and re-creates a product's variation posts on every update, so
 * the ids a set stored can stop existing without the set being touched. The
 * set then silently loses that member: `wc_get_product()` returns false, the
 * child line is never created, and the order reaches Rentopian short of items.
 *
 * The fix. The sync knows both sides of every replacement — the outgoing WP id
 * and the incoming one, keyed by the Rentopian variant id that survives both.
 * This class takes that pair and rewrites every set that references an
 * outgoing id.
 *
 * Two resolutions, in order:
 *
 *   1. EXACT REMAP. The sync created a replacement post for the variant, so
 *      the member is repointed to the new id. Always safe: same Rentopian
 *      variant, same inventory, different post.
 *
 *   2. PRODUCT-LEVEL FALLBACK (`variant_id = 0`). The sync created no post for
 *      that variant — WordPress carries the item as a simple product. Applied
 *      ONLY when the product's `_rental_inventory_id` is identical to the
 *      variant's `inventory_id` from the sync payload, which proves the two
 *      resolve to the same Rentopian inventory and therefore book the same
 *      thing. A member with `variant_id = 0` is the shape every working
 *      product-level member already uses: pricing, availability and the order
 *      payload all read the product's own inventory id.
 *
 * When neither applies the member is LEFT UNCHANGED and logged. Guessing a
 * replacement that books a different inventory would be worse than the
 * existing failure.
 *
 * Scope guards, so nothing that works today can change:
 *   - Only members whose stored `variant_id` is one of the ids the sync is
 *     replacing are considered. A member pointing at a live post is never
 *     touched.
 *   - The product-level fallback is applied only to top-level items and
 *     addons, which carry a `product_id` to fall back to. Selectable options
 *     (`optional_items`) and addon variant choices (`variants_optional`) are
 *     exact-remap only — collapsing a *choice* to product level would merge
 *     two distinct options into one.
 *   - A set is written only when a value actually changed.
 *   - Every failure is caught; a sync can never fail because of this class.
 *
 * `Rental_Sets_Webhook_Defense` sees these writes. It only restores fields
 * ABSENT from the incoming value and never overwrites a present one, so it is
 * a no-op here — the array written is the stored array with one field changed.
 *
 * @package RentopianSync\Sets
 *
 * PHP requirement: 7.4+
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'Rental_Sets_Variant_Remapper', false ) ) :

class Rental_Sets_Variant_Remapper {

    /**
     * Meta keys holding a set's member definition. Both are written together
     * by the sync, and the "restore defaults" path copies `_default` over the
     * live key, so a repair applied to only one would be undone.
     */
    const META_KEYS = array( '_rental_set_items', '_rental_set_items_default' );

    /**
     * Per-request cache of the set post ids.
     *
     * @var int[]|null
     */
    protected static $set_ids = null;

    // -----------------------------------------------------------------
    // Sync entry points
    // -----------------------------------------------------------------

    /**
     * The WordPress ids a payload's variants map to RIGHT NOW. Call before the
     * sync deletes the relation rows; the result is the "outgoing" side of the
     * remap.
     *
     * @param mixed $variants    Payload variants (objects with an `id`).
     * @param int   $division_id
     * @return array<int,int> rental_variant_id => current WP post id
     */
    public static function snapshot( $variants, $division_id ) {
        global $wpdb, $rental_tables;

        $out = array();
        if ( ! is_array( $variants ) && ! is_object( $variants ) ) {
            return $out;
        }
        if ( empty( $rental_tables['variant_relations'] ) ) {
            return $out;
        }

        $rental_ids = array();
        foreach ( (array) $variants as $variant ) {
            $rental_id = is_object( $variant ) ? ( $variant->id ?? 0 ) : ( is_array( $variant ) ? ( $variant['id'] ?? 0 ) : 0 );
            if ( (int) $rental_id > 0 ) {
                $rental_ids[] = (int) $rental_id;
            }
        }
        if ( empty( $rental_ids ) ) {
            return $out;
        }

        $table       = $wpdb->prefix . $rental_tables['variant_relations'];
        $placeholders = implode( ',', array_fill( 0, count( $rental_ids ), '%d' ) );
        $params      = $rental_ids;
        $params[]    = (int) $division_id;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT `id`, `rental_id` FROM `{$table}` WHERE `rental_id` IN ({$placeholders}) AND `rental_division_id` = %d",
                $params
            ),
            ARRAY_A
        );

        foreach ( (array) $rows as $row ) {
            $out[ (int) $row['rental_id'] ] = (int) $row['id'];
        }

        return $out;
    }

    /**
     * rental_variant_id => inventory_id, read from the sync payload. Used to
     * prove the product-level fallback books the same inventory.
     *
     * @param mixed $variants
     * @return array<int,string>
     */
    public static function inventory_map( $variants ) {
        $out = array();
        if ( ! is_array( $variants ) && ! is_object( $variants ) ) {
            return $out;
        }
        foreach ( (array) $variants as $variant ) {
            if ( is_object( $variant ) ) {
                $rental_id = (int) ( $variant->id ?? 0 );
                $inventory = $variant->inventory_id ?? null;
            } elseif ( is_array( $variant ) ) {
                $rental_id = (int) ( $variant['id'] ?? 0 );
                $inventory = $variant['inventory_id'] ?? null;
            } else {
                continue;
            }
            if ( $rental_id > 0 && null !== $inventory ) {
                $out[ $rental_id ] = (string) $inventory;
            }
        }
        return $out;
    }

    /**
     * Rewrite every set that references one of the outgoing ids.
     *
     * @param array<int,int>    $before        rental_variant_id => outgoing WP id.
     * @param array<int,int>    $after         rental_variant_id => incoming WP id (absent/0 when no post was created).
     * @param array<int,string> $inventories   rental_variant_id => inventory_id from the payload.
     * @param int               $wp_product_id The product whose variants were replaced.
     * @param int               $division_id
     * @return array{sets:int,changed:int,remapped:int,product_level:int,unresolved:int}
     */
    public static function apply( array $before, array $after, array $inventories, $wp_product_id, $division_id ) {
        $summary = array(
            'sets'          => 0,
            'changed'       => 0,
            'remapped'      => 0,
            'product_level' => 0,
            'unresolved'    => 0,
        );

        try {
            if ( empty( $before ) ) {
                return $summary;
            }

            // Outgoing WP id => rental variant id. Set members store the WP id,
            // so this is the direction the walk needs.
            $by_old_wp = array();
            foreach ( $before as $rental_id => $old_wp_id ) {
                if ( (int) $old_wp_id > 0 ) {
                    $by_old_wp[ (int) $old_wp_id ] = (int) $rental_id;
                }
            }
            if ( empty( $by_old_wp ) ) {
                return $summary;
            }

            // The sync writes product meta with raw SQL, so the cached copy
            // for this post predates the values just committed.
            $product_inventory = (string) self::fresh_meta( (int) $wp_product_id, '_rental_inventory_id' );

            foreach ( self::set_ids() as $set_id ) {
                $set_changed = false;

                foreach ( self::META_KEYS as $meta_key ) {
                    $items = self::fresh_meta( $set_id, $meta_key );
                    if ( ! is_array( $items ) || empty( $items ) ) {
                        continue;
                    }

                    $result = self::rewrite_items(
                        $items,
                        $by_old_wp,
                        $after,
                        $inventories,
                        $product_inventory,
                        (int) $wp_product_id,
                        $set_id,
                        $meta_key
                    );

                    if ( ! $result['changed'] ) {
                        continue;
                    }

                    update_post_meta( $set_id, $meta_key, $result['items'] );

                    $set_changed = true;
                    $summary['remapped']      += $result['remapped'];
                    $summary['product_level'] += $result['product_level'];
                    $summary['unresolved']    += $result['unresolved'];
                }

                $summary['sets']++;
                if ( $set_changed ) {
                    $summary['changed']++;
                    wc_delete_product_transients( $set_id );
                }
            }

            if ( $summary['changed'] > 0 ) {
                self::log( 'SET_VARIANT_REMAP', array(
                    'product'       => (int) $wp_product_id,
                    'division'      => (int) $division_id,
                    'sets_changed'  => $summary['changed'],
                    'remapped'      => $summary['remapped'],
                    'product_level' => $summary['product_level'],
                    'unresolved'    => $summary['unresolved'],
                ), $summary['unresolved'] > 0 ? 'warning' : 'info' );
            }
        } catch ( \Throwable $e ) {
            self::log( 'SET_VARIANT_REMAP_ERROR', array(
                'product' => (int) $wp_product_id,
                'message' => $e->getMessage(),
                'file'    => basename( $e->getFile() ),
                'line'    => $e->getLine(),
            ), 'error' );
        }

        return $summary;
    }

    // -----------------------------------------------------------------
    // Rewriting
    // -----------------------------------------------------------------

    /**
     * Walk one set's items and rewrite the member references the sync
     * replaced. Returns the (possibly unchanged) array plus counters.
     *
     * @param array             $items
     * @param array<int,int>    $by_old_wp         outgoing WP id => rental variant id
     * @param array<int,int>    $after             rental variant id => incoming WP id
     * @param array<int,string> $inventories       rental variant id => inventory id
     * @param string            $product_inventory `_rental_inventory_id` of the synced product
     * @param int               $wp_product_id
     * @param int               $set_id
     * @param string            $meta_key
     * @return array{items:array,changed:bool,remapped:int,product_level:int,unresolved:int}
     */
    protected static function rewrite_items( array $items, array $by_old_wp, array $after, array $inventories, $product_inventory, $wp_product_id, $set_id, $meta_key ) {
        $changed       = false;
        $remapped      = 0;
        $product_level = 0;
        $unresolved    = 0;

        foreach ( $items as $i => $item ) {
            if ( ! is_array( $item ) ) {
                continue;
            }

            // Top-level member. Falls back to product level when proven safe.
            $decision = self::decide(
                $item,
                $by_old_wp,
                $after,
                $inventories,
                $product_inventory,
                $wp_product_id,
                true
            );
            if ( $decision['action'] ) {
                self::count( $decision['action'], $remapped, $product_level, $unresolved );
                if ( 'unresolved' !== $decision['action'] ) {
                    $item['variant_id'] = $decision['variant_id'];
                    if ( $decision['rental_variant_id'] && empty( $item['rental_variant_id'] ) ) {
                        $item['rental_variant_id'] = $decision['rental_variant_id'];
                    }
                    $changed = true;
                }
                self::log_member( $set_id, $meta_key, 'item', $item, $decision );
            }

            // Selectable options — exact remap only.
            if ( ! empty( $item['optional_items'] ) && is_array( $item['optional_items'] ) ) {
                foreach ( $item['optional_items'] as $oi => $option ) {
                    if ( ! is_array( $option ) ) {
                        continue;
                    }
                    $decision = self::decide( $option, $by_old_wp, $after, $inventories, $product_inventory, $wp_product_id, false );
                    if ( ! $decision['action'] ) {
                        continue;
                    }
                    self::count( $decision['action'], $remapped, $product_level, $unresolved );
                    if ( 'unresolved' !== $decision['action'] ) {
                        $item['optional_items'][ $oi ]['variant_id'] = $decision['variant_id'];
                        $changed = true;
                    }
                    self::log_member( $set_id, $meta_key, 'optional_item', $option, $decision );
                }
            }

            // Addons — may fall back to product level, they carry a product_id.
            if ( ! empty( $item['addons'] ) && is_array( $item['addons'] ) ) {
                foreach ( $item['addons'] as $ai => $addon ) {
                    if ( ! is_array( $addon ) ) {
                        continue;
                    }

                    $decision = self::decide( $addon, $by_old_wp, $after, $inventories, $product_inventory, $wp_product_id, true );
                    if ( $decision['action'] ) {
                        self::count( $decision['action'], $remapped, $product_level, $unresolved );
                        if ( 'unresolved' !== $decision['action'] ) {
                            $item['addons'][ $ai ]['variant_id'] = $decision['variant_id'];
                            if ( $decision['rental_variant_id'] && empty( $addon['rental_variant_id'] ) ) {
                                $item['addons'][ $ai ]['rental_variant_id'] = $decision['rental_variant_id'];
                            }
                            $changed = true;
                        }
                        self::log_member( $set_id, $meta_key, 'addon', $addon, $decision );
                    }

                    // Addon variant choices — exact remap only.
                    if ( empty( $addon['variants_optional'] ) || ! is_array( $addon['variants_optional'] ) ) {
                        continue;
                    }
                    foreach ( $addon['variants_optional'] as $vi => $choice ) {
                        if ( ! is_array( $choice ) ) {
                            continue;
                        }
                        $decision = self::decide( $choice, $by_old_wp, $after, $inventories, $product_inventory, $wp_product_id, false );
                        if ( ! $decision['action'] ) {
                            continue;
                        }
                        self::count( $decision['action'], $remapped, $product_level, $unresolved );
                        if ( 'unresolved' !== $decision['action'] ) {
                            $item['addons'][ $ai ]['variants_optional'][ $vi ]['variant_id'] = $decision['variant_id'];
                            $changed = true;
                        }
                        self::log_member( $set_id, $meta_key, 'addon_choice', $choice, $decision );
                    }
                }
            }

            $items[ $i ] = $item;
        }

        return array(
            'items'         => $items,
            'changed'       => $changed,
            'remapped'      => $remapped,
            'product_level' => $product_level,
            'unresolved'    => $unresolved,
        );
    }

    /**
     * Decide what happens to one member reference.
     *
     * Returns `action = ''` when the member is not part of this replacement
     * and must be left completely alone.
     *
     * @param array             $member
     * @param array<int,int>    $by_old_wp
     * @param array<int,int>    $after
     * @param array<int,string> $inventories
     * @param string            $product_inventory
     * @param int               $wp_product_id
     * @param bool              $allow_product_level
     * @return array{action:string,variant_id:int,rental_variant_id:int,reason:string}
     */
    protected static function decide( array $member, array $by_old_wp, array $after, array $inventories, $product_inventory, $wp_product_id, $allow_product_level ) {
        $none = array( 'action' => '', 'variant_id' => 0, 'rental_variant_id' => 0, 'reason' => '' );

        $stored = isset( $member['variant_id'] ) ? (int) $member['variant_id'] : 0;
        if ( $stored <= 0 || ! isset( $by_old_wp[ $stored ] ) ) {
            return $none;
        }

        $rental_variant_id = (int) $by_old_wp[ $stored ];
        $new_wp_id         = isset( $after[ $rental_variant_id ] ) ? (int) $after[ $rental_variant_id ] : 0;

        // 1. The sync created a replacement post.
        if ( $new_wp_id > 0 ) {
            if ( $new_wp_id === $stored ) {
                return $none; // unchanged id, nothing to write
            }
            return array(
                'action'            => 'remapped',
                'variant_id'        => $new_wp_id,
                'rental_variant_id' => $rental_variant_id,
                'reason'            => 'replacement_post',
            );
        }

        // 2. No post exists for this variant. Fall back to the product only
        //    when both resolve to the same Rentopian inventory.
        if ( ! $allow_product_level ) {
            return array(
                'action'            => 'unresolved',
                'variant_id'        => $stored,
                'rental_variant_id' => $rental_variant_id,
                'reason'            => 'choice_not_collapsible',
            );
        }

        $member_product_id = isset( $member['product_id'] ) ? (int) $member['product_id'] : 0;
        if ( $member_product_id !== (int) $wp_product_id ) {
            // The member's product is not the one being synced, so this
            // product's inventory proves nothing about it.
            return array(
                'action'            => 'unresolved',
                'variant_id'        => $stored,
                'rental_variant_id' => $rental_variant_id,
                'reason'            => 'foreign_product',
            );
        }

        $variant_inventory = isset( $inventories[ $rental_variant_id ] ) ? (string) $inventories[ $rental_variant_id ] : '';
        if ( '' === $product_inventory || '' === $variant_inventory || $product_inventory !== $variant_inventory ) {
            return array(
                'action'            => 'unresolved',
                'variant_id'        => $stored,
                'rental_variant_id' => $rental_variant_id,
                'reason'            => 'inventory_mismatch',
            );
        }

        return array(
            'action'            => 'product_level',
            'variant_id'        => 0,
            'rental_variant_id' => $rental_variant_id,
            'reason'            => 'same_inventory',
        );
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /**
     * Post meta read straight from the database. Large parts of the sync write
     * postmeta with raw SQL, which leaves WordPress's cached copy of that
     * post's meta behind; a cached read here would decide against values that
     * no longer exist.
     *
     * @param int    $post_id
     * @param string $meta_key
     * @return mixed
     */
    protected static function fresh_meta( $post_id, $meta_key ) {
        $post_id = (int) $post_id;
        if ( $post_id <= 0 ) {
            return '';
        }
        if ( function_exists( 'wp_cache_delete' ) ) {
            wp_cache_delete( $post_id, 'post_meta' );
        }
        return get_post_meta( $post_id, $meta_key, true );
    }

    /**
     * @param string $action
     * @param int    $remapped
     * @param int    $product_level
     * @param int    $unresolved
     * @return void
     */
    protected static function count( $action, &$remapped, &$product_level, &$unresolved ) {
        if ( 'remapped' === $action ) {
            $remapped++;
        } elseif ( 'product_level' === $action ) {
            $product_level++;
        } else {
            $unresolved++;
        }
    }

    /**
     * Every product post carrying `_rental_is_set`, whatever its status, so a
     * private or binned set is repaired too rather than being resurrected
     * broken later.
     *
     * @return int[]
     */
    public static function set_ids() {
        if ( null !== self::$set_ids ) {
            return self::$set_ids;
        }

        global $wpdb;

        $ids = $wpdb->get_col(
            "SELECT DISTINCT pm.post_id
               FROM {$wpdb->postmeta} pm
               INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
              WHERE pm.meta_key = '_rental_is_set'
                AND pm.meta_value = '1'
                AND p.post_type = 'product'"
        );

        self::$set_ids = array_map( 'intval', (array) $ids );
        return self::$set_ids;
    }

    /**
     * Invalidate the per-request set list. Called after a set is created so a
     * later remap in the same request sees it.
     *
     * @return void
     */
    public static function flush_set_ids() {
        self::$set_ids = null;
    }

    /**
     * @param int    $set_id
     * @param string $meta_key
     * @param string $level
     * @param array  $member
     * @param array  $decision
     * @return void
     */
    protected static function log_member( $set_id, $meta_key, $level, array $member, array $decision ) {
        self::log( 'SET_MEMBER_REMAP', array(
            'set'        => (int) $set_id,
            'meta'       => str_replace( '_rental_set_items', 'items', $meta_key ),
            'level'      => $level,
            'product'    => isset( $member['product_id'] ) ? (int) $member['product_id'] : 0,
            'from'       => isset( $member['variant_id'] ) ? (int) $member['variant_id'] : 0,
            'to'         => (int) $decision['variant_id'],
            'rental_var' => (int) $decision['rental_variant_id'],
            'action'     => $decision['action'],
            'reason'     => $decision['reason'],
        ), 'unresolved' === $decision['action'] ? 'critical' : 'info' );
    }

    /**
     * Routes to the set-cart trace file when available so remaps sit next to
     * the add-to-cart evidence, otherwise to the shared logger.
     *
     * @param string $event
     * @param array  $context
     * @param string $level
     * @return void
     */
    protected static function log( $event, array $context, $level = 'info' ) {
        if ( class_exists( 'Rentopian_Set_Cart_Tracer', false ) ) {
            Rentopian_Set_Cart_Tracer::log( $event, $context, $level );
            return;
        }
        if ( class_exists( 'Rental_Sets_Logger', false ) ) {
            Rental_Sets_Logger::warn( strtolower( $event ), '', $context );
        }
    }
}

endif;
