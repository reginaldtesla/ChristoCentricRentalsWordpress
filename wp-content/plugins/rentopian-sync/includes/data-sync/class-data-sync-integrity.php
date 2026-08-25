<?php
/**
 * Data Sync Integrity
 *
 * Makes the sync survive WordPress-side tampering.
 *
 * Every upsert in this module decides "create or update" by looking up a
 * relation row (rental id → WP id). When the WP object behind that row is
 * deleted or trashed by hand, the row survives, the sync takes the update
 * path, and the update silently does nothing — so the record is never
 * restored. The legacy one-shot sync never hit this because it truncated
 * and rebuilt everything.
 *
 * Two passes restore that guarantee without truncating:
 *
 *   - {@see purge_stale_relations()} runs before the write phases and drops
 *     relation rows whose WP target is gone, so the next upsert takes the
 *     create path for every entity — products, variants, sets, categories,
 *     brands, tags, attributes, options and set options alike.
 *   - {@see reconcile_product()} rebuilds a variable product's attribute
 *     metadata from its own live variations, which is what makes deleted
 *     `_product_attributes` come back.
 *
 * @package RentopianSync\DataSync
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Rental_Data_Sync_Integrity {

    /**
     * Bumped whenever {@see relation_indexes()} gains an entry, so the
     * migration re-runs once and then stays out of the way.
     */
    const INDEX_VERSION = '1';

    const OPTION_INDEX_VERSION = 'rental_relations_index_version';

    /* ──────────────────────────────────────────────────────────
     * Schema
     * ────────────────────────────────────────────────────────── */

    /**
     * Lookup indexes the sync depends on, keyed by `$rental_tables` key.
     *
     * The relation tables were designed for the legacy sync, which
     * truncated and bulk-inserted and therefore never looked a row up by
     * `rental_id` — so the only index is `UNIQUE KEY id (id)`. This module
     * inverts that access pattern: resolving "does this Rentopian record
     * already exist here?" is the hottest query in the pipeline, and
     * without these indexes every one of those lookups is a full table
     * scan. On a large catalog that alone stops the run from finishing.
     *
     * @return array table key => [ index name => columns ]
     */
    private static function relation_indexes() {
        return [
            'product_relations'        => [ 'rental_lookup' => [ 'rental_id', 'rental_division_id' ] ],
            'variant_relations'        => [ 'rental_lookup' => [ 'rental_id', 'rental_division_id' ] ],
            'set_relations'            => [ 'rental_lookup' => [ 'rental_id', 'rental_division_id' ] ],
            'category_relations'       => [ 'rental_lookup' => [ 'rental_id' ] ],
            'brand_relations'          => [ 'rental_lookup' => [ 'rental_id' ] ],
            'tag_relations'            => [ 'rental_lookup' => [ 'rental_id' ] ],
            'sets_tag_relations'       => [ 'rental_lookup' => [ 'rental_id' ] ],
            'attribute_relations'      => [ 'rental_lookup' => [ 'rental_id' ] ],
            'coupon_relations'         => [ 'rental_lookup' => [ 'rental_id' ] ],
            'image_relations'          => [ 'rental_lookup' => [ 'rental_id' ] ],
            'product_option_relations' => [ 'wp_lookup' => [ 'wp_id', 'type' ], 'po_lookup' => [ 'po_id' ] ],
            'set_option_relations'     => [ 'wp_lookup' => [ 'wp_id' ] ],
        ];
    }

    /**
     * Create the lookup indexes when missing. Version-guarded, so it costs
     * one option read once they exist.
     *
     * @param bool $force Ignore the version guard.
     * @return array index name => table it was added to.
     */
    public static function ensure_relation_indexes( $force = false ) {
        global $wpdb, $rental_tables;

        if ( ! $force && get_option( self::OPTION_INDEX_VERSION ) === self::INDEX_VERSION ) {
            return [];
        }

        $added = [];

        foreach ( self::relation_indexes() as $table_key => $indexes ) {
            if ( empty( $rental_tables[ $table_key ] ) ) {
                continue;
            }

            $table = $wpdb->prefix . $rental_tables[ $table_key ];
            if ( ! self::table_exists( $table ) ) {
                continue;
            }

            $existing = [];
            foreach ( (array) $wpdb->get_results( "SHOW INDEX FROM `{$table}`", ARRAY_A ) as $row ) {
                $existing[ $row['Key_name'] ] = true;
            }

            $columns = (array) $wpdb->get_col( "SHOW COLUMNS FROM `{$table}`" );

            foreach ( $indexes as $name => $cols ) {
                if ( isset( $existing[ $name ] ) ) {
                    continue;
                }

                // A table predating a column (rental_division_id was added
                // by a later migration) must not fail the whole pass.
                if ( array_diff( $cols, $columns ) ) {
                    continue;
                }

                $list = '`' . implode( '`,`', $cols ) . '`';

                if ( false !== $wpdb->query( "ALTER TABLE `{$table}` ADD KEY `{$name}` ({$list})" ) ) {
                    $added[ "{$table}.{$name}" ] = implode( ',', $cols );
                } else {
                    self::log( "Could not add index {$name} to {$table}: {$wpdb->last_error}", 'error' );
                }
            }
        }

        update_option( self::OPTION_INDEX_VERSION, self::INDEX_VERSION, true );

        if ( $added ) {
            self::log( 'Added relation lookup indexes: ' . wp_json_encode( $added ), 'notice' );
        } else {
            self::log( 'Relation lookup indexes already present', 'info' );
        }

        return $added;
    }

    /* ──────────────────────────────────────────────────────────
     * Relation purging
     * ────────────────────────────────────────────────────────── */

    /**
     * Relation tables keyed by their `$rental_tables` key, mapped to the
     * kind of WordPress object their `id` column points at.
     *
     * `attribute_relations` is deliberately `wc_attribute`: its ids are
     * WooCommerce attribute ids from `woocommerce_attribute_taxonomies`,
     * NOT term ids. Checking them as terms silently passes, because low
     * term ids almost always exist.
     *
     * @return array
     */
    private static function relation_map() {
        return [
            'product_relations'  => 'post',
            'variant_relations'  => 'post',
            'set_relations'      => 'post',
            'coupon_relations'   => 'post',
            'category_relations' => 'term',
            'brand_relations'    => 'term',
            'tag_relations'      => 'term',
            'sets_tag_relations' => 'term',
            'attribute_relations' => 'wc_attribute',
        ];
    }

    /**
     * Drop relation rows whose WordPress target no longer exists, so the
     * entity is recreated on this run instead of being "updated" into a
     * void.
     *
     * @return array Counters per relation table plus a total.
     */
    public static function purge_stale_relations() {
        global $wpdb, $rental_tables;

        $report = [];
        $total  = 0;

        foreach ( self::relation_map() as $key => $kind ) {
            if ( empty( $rental_tables[ $key ] ) ) {
                continue;
            }

            $table = $wpdb->prefix . $rental_tables[ $key ];
            if ( ! self::table_exists( $table ) ) {
                continue;
            }

            $removed = self::delete_dead_targets( $table, 'id', $kind );

            if ( $removed > 0 ) {
                $report[ $key ] = $removed;
                $total         += $removed;
            }
        }

        $total += self::purge_option_relations( $report );
        $total += self::purge_set_option_relations( $report );

        $report['total'] = $total;

        Rental_Data_Sync_Logger::write(
            $total > 0
                ? 'Dropped ' . $total . ' relation row(s) pointing at deleted WordPress records: ' . wp_json_encode( $report )
                : 'Relation check passed: every relation row points at a live WordPress record',
            $total > 0 ? 'notice' : 'info',
            'integrity'
        );

        return $report;
    }

    /**
     * Delete rows of a relation table whose WordPress target is gone.
     *
     * Set-based on purpose. The obvious loop — read every id, ask
     * `get_post_status()` per row — costs one query and one primed post
     * cache entry per relation, so memory and time both scale with the
     * catalog inside a single bounded step. These joins do the same work
     * in one indexed statement per table, and match the per-row checks in
     * {@see target_is_live()} exactly:
     *
     *   post → missing row, or `post_status = 'trash'`
     *   term → no `term_taxonomy` row (which is what `get_term()` requires)
     *
     * @param string $table
     * @param string $column Column holding the WP id.
     * @param string $kind   post | term | wc_attribute
     * @param string $extra  Optional additional WHERE predicate.
     * @return int Rows deleted.
     */
    private static function delete_dead_targets( $table, $column, $kind, $extra = '' ) {
        global $wpdb;

        $extra = $extra ? " AND {$extra}" : '';

        switch ( $kind ) {
            case 'post':
                $sql = "DELETE r FROM `{$table}` r
                        LEFT JOIN {$wpdb->posts} p ON p.ID = r.`{$column}`
                        WHERE (p.ID IS NULL OR p.post_status = 'trash'){$extra}";
                break;

            case 'term':
                $sql = "DELETE r FROM `{$table}` r
                        LEFT JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = r.`{$column}`
                        WHERE tt.term_id IS NULL{$extra}";
                break;

            case 'wc_attribute':
                $sql = "DELETE r FROM `{$table}` r
                        LEFT JOIN {$wpdb->prefix}woocommerce_attribute_taxonomies a
                               ON a.attribute_id = r.`{$column}`
                        WHERE a.attribute_id IS NULL{$extra}";
                break;

            default:
                return 0;
        }

        $deleted = $wpdb->query( $sql );

        if ( false === $deleted ) {
            self::log( "Relation purge failed on {$table}: {$wpdb->last_error}", 'error' );
            return 0;
        }

        return (int) $deleted;
    }

    /**
     * Product-option relations carry their own shape: `wp_id` with a
     * `type` discriminator (1 = product post, 2 = category term).
     *
     * @param array $report
     * @return int
     */
    private static function purge_option_relations( array &$report ) {
        global $wpdb, $rental_tables;

        if ( empty( $rental_tables['product_option_relations'] ) ) {
            return 0;
        }

        $table = $wpdb->prefix . $rental_tables['product_option_relations'];
        if ( ! self::table_exists( $table ) ) {
            return 0;
        }

        // type 1 rows point at product posts, type 2 rows at category terms.
        $removed  = self::delete_dead_targets( $table, 'wp_id', 'post', 'r.`type` = 1' );
        $removed += self::delete_dead_targets( $table, 'wp_id', 'term', 'r.`type` <> 1' );

        if ( $removed > 0 ) {
            $report['product_option_relations'] = $removed;
        }

        return $removed;
    }

    /**
     * Set-option relations key their WordPress target on `wp_id`.
     *
     * @param array $report
     * @return int
     */
    private static function purge_set_option_relations( array &$report ) {
        global $wpdb, $rental_tables;

        if ( empty( $rental_tables['set_option_relations'] ) ) {
            return 0;
        }

        $table = $wpdb->prefix . $rental_tables['set_option_relations'];
        if ( ! self::table_exists( $table ) ) {
            return 0;
        }

        $removed = self::delete_dead_targets( $table, 'wp_id', 'post' );

        if ( $removed > 0 ) {
            $report['set_option_relations'] = $removed;
        }

        return $removed;
    }

    /**
     * @param int    $wp_id
     * @param string $kind post | term | wc_attribute
     * @return bool
     */
    public static function target_is_live( $wp_id, $kind ) {
        global $wpdb;

        if ( $wp_id <= 0 ) {
            return false;
        }

        switch ( $kind ) {
            case 'post':
                $status = get_post_status( $wp_id );
                return $status !== false && 'trash' !== $status;

            case 'term':
                $term = get_term( $wp_id );
                return $term && ! is_wp_error( $term );

            case 'wc_attribute':
                return (bool) $wpdb->get_var( $wpdb->prepare(
                    "SELECT attribute_id FROM {$wpdb->prefix}woocommerce_attribute_taxonomies WHERE attribute_id = %d",
                    $wp_id
                ) );
        }

        return false;
    }

    /* ──────────────────────────────────────────────────────────
     * Product reconciliation
     * ────────────────────────────────────────────────────────── */

    /**
     * Rebuild a variable product's attribute metadata from its own live
     * variations.
     *
     * `product/update` refreshes fields and stock only, so a parent whose
     * `_product_attributes` or attribute terms were removed by hand stays
     * broken through every later sync: the variations exist and are
     * purchasable, but WooCommerce renders no dropdowns because it builds
     * them from the parent. Deriving the parent from its children heals
     * that no matter how the metadata was lost.
     *
     * @param int $product_id Parent product post id.
     * @return bool TRUE when something was rebuilt.
     */
    public static function reconcile_product( $product_id ) {
        global $wpdb;

        $product_id = (int) $product_id;
        if ( $product_id <= 0 || 'product' !== get_post_type( $product_id ) ) {
            return false;
        }

        $children = $wpdb->get_col( $wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts}
             WHERE post_parent = %d AND post_type = 'product_variation' AND post_status = 'publish'
             ORDER BY menu_order ASC, ID ASC",
            $product_id
        ) );

        if ( empty( $children ) ) {
            return false;
        }

        $changed_by_dedupe = self::dedupe_variations( $product_id, $children );
        if ( $changed_by_dedupe ) {
            $children = $wpdb->get_col( $wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts}
                 WHERE post_parent = %d AND post_type = 'product_variation' AND post_status = 'publish'
                 ORDER BY menu_order ASC, ID ASC",
                $product_id
            ) );
        }

        // taxonomy/attribute name => ordered unique option slugs
        $found = [];

        foreach ( $children as $child ) {
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT meta_key, meta_value FROM {$wpdb->postmeta}
                 WHERE post_id = %d AND meta_key LIKE 'attribute\\_%%' AND meta_value <> ''",
                (int) $child
            ), ARRAY_A );

            foreach ( $rows as $row ) {
                $name  = substr( $row['meta_key'], strlen( 'attribute_' ) );
                $value = (string) $row['meta_value'];

                if ( '' === $name ) {
                    continue;
                }
                if ( ! isset( $found[ $name ] ) ) {
                    $found[ $name ] = [];
                }
                if ( ! in_array( $value, $found[ $name ], true ) ) {
                    $found[ $name ][] = $value;
                }
            }
        }

        if ( empty( $found ) ) {
            return false;
        }

        $attributes = [];
        $position   = 0;
        $changed    = false;

        foreach ( $found as $name => $values ) {
            $is_taxonomy = taxonomy_exists( $name );

            if ( $is_taxonomy ) {
                // The parent must carry every term its variations use, or
                // the dropdown for that attribute comes back empty.
                $existing = wp_get_object_terms( $product_id, $name, [ 'fields' => 'slugs' ] );
                $existing = is_wp_error( $existing ) ? [] : $existing;

                $missing = array_diff( $values, $existing );
                if ( $missing ) {
                    wp_set_object_terms( $product_id, array_values( array_unique( array_merge( $existing, $values ) ) ), $name, false );
                    $changed = true;
                }
            }

            $attributes[ $name ] = [
                'name'         => $name,
                'value'        => $is_taxonomy ? '' : implode( ' | ', $values ),
                'position'     => $position,
                'is_visible'   => 1,
                'is_variation' => 1,
                'is_taxonomy'  => $is_taxonomy ? 1 : 0,
            ];

            $position++;
        }

        $current = get_post_meta( $product_id, '_product_attributes', true );
        $current = is_array( $current ) ? $current : [];

        if ( self::attributes_differ( $current, $attributes ) ) {
            update_post_meta( $product_id, '_product_attributes', $attributes );
            $changed = true;
        }

        $changed = $changed || $changed_by_dedupe;

        if ( $changed ) {
            self::flush_product_caches( $product_id );

            Rental_Data_Sync_Logger::write(
                sprintf(
                    'Rebuilt attributes for product %d from %d live variation(s): %s',
                    $product_id,
                    count( $children ),
                    implode( ', ', array_keys( $attributes ) )
                ),
                'notice',
                'integrity'
            );
        }

        return $changed;
    }

    /**
     * Remove duplicate variations — live children of the same parent that
     * cover an identical attribute combination.
     *
     * A variation whose relation row was purged (because the post had been
     * trashed) is recreated by the writer. If that original post is later
     * restored, the parent ends up offering the same combination twice and
     * WooCommerce picks arbitrarily. The copy the relation table points at
     * is authoritative; the others are dropped.
     *
     * @param int   $product_id
     * @param int[] $children Live variation ids.
     * @return bool TRUE when anything was removed.
     */
    private static function dedupe_variations( $product_id, array $children ) {
        global $wpdb, $rental_tables;

        if ( count( $children ) < 2 ) {
            return false;
        }

        $tracked = [];
        if ( ! empty( $rental_tables['variant_relations'] ) ) {
            $table   = $wpdb->prefix . $rental_tables['variant_relations'];
            $tracked = array_flip( array_map( 'intval', (array) $wpdb->get_col( "SELECT `id` FROM {$table}" ) ) );
        }

        $groups = [];

        foreach ( $children as $child ) {
            $child = (int) $child;

            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT meta_key, meta_value FROM {$wpdb->postmeta}
                 WHERE post_id = %d AND meta_key LIKE 'attribute\\_%%'
                 ORDER BY meta_key ASC",
                $child
            ), ARRAY_A );

            $signature = [];
            foreach ( $rows as $row ) {
                $signature[] = $row['meta_key'] . '=' . $row['meta_value'];
            }

            $groups[ implode( '|', $signature ) ][] = $child;
        }

        $removed = 0;

        foreach ( $groups as $signature => $ids ) {
            if ( count( $ids ) < 2 || '' === $signature ) {
                continue;
            }

            // Prefer the copy the sync tracks; otherwise keep the newest.
            $keep = null;
            foreach ( $ids as $id ) {
                if ( isset( $tracked[ $id ] ) ) {
                    $keep = $id;
                    break;
                }
            }
            if ( null === $keep ) {
                $keep = max( $ids );
            }

            foreach ( $ids as $id ) {
                if ( $id === $keep ) {
                    continue;
                }
                wp_delete_post( $id, true );
                $removed++;
            }

            Rental_Data_Sync_Logger::write(
                sprintf(
                    'Product %d: removed %d duplicate variation(s) for [%s], kept %d',
                    $product_id,
                    count( $ids ) - 1,
                    $signature,
                    $keep
                ),
                'notice',
                'integrity'
            );
        }

        return $removed > 0;
    }

    /**
     * Compare only the fields the sync owns, so an unrelated edit (a
     * position tweak in the admin) does not trigger a rewrite every run.
     *
     * @param array $current
     * @param array $rebuilt
     * @return bool
     */
    private static function attributes_differ( array $current, array $rebuilt ) {
        if ( array_keys( $current ) !== array_keys( $rebuilt ) ) {
            return true;
        }

        foreach ( $rebuilt as $name => $spec ) {
            foreach ( [ 'value', 'is_variation', 'is_taxonomy' ] as $field ) {
                if ( (string) ( $current[ $name ][ $field ] ?? '' ) !== (string) $spec[ $field ] ) {
                    return true;
                }
            }
        }

        return false;
    }

    /* ──────────────────────────────────────────────────────────
     * Leftovers
     * ────────────────────────────────────────────────────────── */

    /**
     * Delete trashed variations the sync owns, and the product-lookup rows
     * left behind by any deleted post.
     *
     * A trashed variation is invisible on the storefront but still sits in
     * `wc_product_meta_lookup`, where it skews price ranges and layered-nav
     * filters. Superseded variations accumulate one set per re-sync.
     *
     * @return array { variations_deleted, lookup_rows_deleted }
     */
    public static function purge_orphan_posts() {
        global $wpdb;

        $trashed = $wpdb->get_col(
            "SELECT p.ID FROM {$wpdb->posts} p
             WHERE p.post_type = 'product_variation'
               AND p.post_status = 'trash'"
        );

        $deleted = 0;
        foreach ( $trashed as $id ) {
            if ( wp_delete_post( (int) $id, true ) ) {
                $deleted++;
            }
        }

        $lookup = (int) $wpdb->query(
            "DELETE l FROM {$wpdb->prefix}wc_product_meta_lookup l
             LEFT JOIN {$wpdb->posts} p ON p.ID = l.product_id
             WHERE p.ID IS NULL OR p.post_status = 'trash'"
        );

        if ( $deleted || $lookup ) {
            Rental_Data_Sync_Logger::write(
                "Removed {$deleted} superseded variation(s) and {$lookup} stale product-lookup row(s)",
                'notice',
                'integrity'
            );
        }

        return [ 'variations_deleted' => $deleted, 'lookup_rows_deleted' => $lookup ];
    }

    /**
     * Drop the caches WooCommerce builds from a product's children, so the
     * storefront reflects the run instead of the previous shape.
     *
     * @param int $product_id
     */
    public static function flush_product_caches( $product_id ) {
        $product_id = (int) $product_id;

        delete_transient( 'wc_var_prices_' . $product_id );
        delete_transient( 'wc_product_children_' . $product_id );

        if ( function_exists( 'wc_delete_product_transients' ) ) {
            wc_delete_product_transients( $product_id );
        }

        clean_post_cache( $product_id );
    }

    /**
     * @param string $table
     * @return bool
     */
    private static function table_exists( $table ) {
        global $wpdb;
        return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
    }

    /**
     * @param string $message
     * @param string $level
     */
    private static function log( $message, $level = 'info' ) {
        Rental_Data_Sync_Logger::write( $message, $level, 'integrity' );
    }
}
