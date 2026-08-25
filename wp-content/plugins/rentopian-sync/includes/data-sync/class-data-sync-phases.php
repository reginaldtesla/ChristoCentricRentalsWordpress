<?php
/**
 * Data Sync Phases
 *
 * One method per phase, invoked by the chunk worker with a within-phase
 * offset. Every step is bounded: one page of API rows, one entity slice,
 * one bounded batch of deletes — memory never scales with catalog size.
 *
 * Step contract: run_step() returns
 *   [ 'offset' => int, 'done' => bool, 'processed' => int ]
 * where `offset` is the next within-phase offset (strictly increasing).
 *
 * Reuse map (all existing code):
 *  - config       → rental_sync_company_settings / rental_sync_divisions /
 *                   rental_set_filter_duplicate_products_options / ...
 *  - purge        → the classic sync's rental_empty_* wipe helpers
 *  - taxonomies   → api.php attribute/brand/category handlers
 *  - variants     → staged to per-product JSONL buckets (no writes)
 *  - products     → api.php product/variant handlers via the composer
 *  - sets         → rental_run_sets_sync_pass (sets module sync pass)
 *  - extras       → rental_empty_* + rental_add_* pairs
 *  - sweep        → api.php delete handlers
 *
 * @package RentopianSync\DataSync
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Rental_Data_Sync_Phases {

    /** @var string */
    private $sync_id;

    /** @var string */
    private $api_key;

    /** @var Rental_Data_Sync_Record_Writer */
    private $writer;

    /** @var array|null Cached composer maps for the products phase. */
    private $maps = null;

    const SWEEP_ENTITY_STRIDE = 100000;
    const SWEEP_BATCH         = 200;

    public function __construct( $sync_id, $api_key ) {
        $this->sync_id = $sync_id;
        $this->api_key = $api_key;
        $this->writer  = new Rental_Data_Sync_Record_Writer();
    }

    /**
     * Run one bounded step of the given phase.
     *
     * @param int $phase
     * @param int $offset
     * @return array { offset, done, processed }
     */
    public function run_step( $phase, $offset ) {
        Rental_Data_Sync_Record_Writer::load_handlers();

        switch ( (int) $phase ) {
            case Rental_Data_Sync_Status::PHASE_CONFIG:
                return $this->phase_config();
            case Rental_Data_Sync_Status::PHASE_PURGE:
                return $this->phase_purge();
            case Rental_Data_Sync_Status::PHASE_TAXONOMIES:
                return $this->phase_taxonomies( $offset );
            case Rental_Data_Sync_Status::PHASE_VARIANTS:
                return $this->phase_variants( $offset );
            case Rental_Data_Sync_Status::PHASE_PRODUCTS:
                return $this->phase_products( $offset );
            case Rental_Data_Sync_Status::PHASE_SETS:
                return $this->phase_sets( $offset );
            case Rental_Data_Sync_Status::PHASE_EXTRAS:
                return $this->phase_extras();
            case Rental_Data_Sync_Status::PHASE_SWEEP:
                return $this->phase_sweep( $offset );
            case Rental_Data_Sync_Status::PHASE_FINALIZE:
                return $this->phase_finalize();
        }

        return [ 'offset' => $offset + 1, 'done' => true, 'processed' => 0 ];
    }

    /**
     * @return array Writer counters accumulated during this step.
     */
    public function step_stats() {
        return $this->writer->stats();
    }

    /**
     * @return array[] Records the writer could not write during this step.
     */
    public function step_failures() {
        return $this->writer->failures();
    }

    /* ══════════════════════════════════════════════════════════
     * Phase 0 — config
     * ══════════════════════════════════════════════════════════ */

    private function phase_config() {
        rental_clear_cache();

        $settings = rental_sync_company_settings( $this->api_key );
        set_default_settings();
        update_option( 'rental_filter_unavailable_products', 0 );

        rental_sync_divisions( $this->api_key );
        $divisions_synced = get_option( 'rental_divisions' );

        $location_based_filter = isset( $settings->location_based_filter ) ? $settings->location_based_filter : 0;
        rental_set_filter_duplicate_products_options( $divisions_synced, $location_based_filter );

        if ( isset( $settings->api_seo_no_index_filter ) ) {
            update_option( 'rental_do_not_index_hidden_duplicate_products_for_seo', $settings->api_seo_no_index_filter );
        }

        rental_sync_main_division_address( $divisions_synced );
        update_option( 'rental_pickup_delivery', 'company_delivery_return' );

        update_option( 'rental_referral_sources', rental_curl( 'referral_sources', $this->api_key ) );
        update_option( 'rental_event_types', rental_curl( 'event_types', $this->api_key ) );

        $payment_tips = rental_curl( 'payment_tips', $this->api_key, true, null, null, true );
        if ( $payment_tips ) {
            update_option( 'rental_payment_tips', $payment_tips );
        } else {
            Rental_Data_Sync_Logger::write( 'Failed to fetch payment tips: URL not reachable', 'warning', 'phase' );
        }

        if ( ! get_option( 'rental_do_not_use_rentopian_shipping' ) ) {
            update_option( 'rental_shipping_settings', rental_curl( 'shipping/settings', $this->api_key ) );
        }

        rental_curl( 'settings/plugin_path/update', $this->api_key, false, [
            'plugin_path' => substr( RENTOPIAN_SYNC_PATH, strlen( ABSPATH ) ),
        ] );

        // Before anything is written: drop relation rows whose WordPress
        // record was deleted or trashed by hand. Every upsert decides
        // create-vs-update from those rows, so a stale one would make this
        // run "update" a record that no longer exists and silently skip it.
        Rental_Data_Sync_Integrity::purge_stale_relations();

        return [ 'offset' => 1, 'done' => true, 'processed' => 1 ];
    }

    /* ══════════════════════════════════════════════════════════
     * Phase 1 — purge (full catalog wipe before the rebuild)
     * ══════════════════════════════════════════════════════════ */

    /**
     * Wipe the catalog so the rebuild starts from nothing.
     *
     * The rebuild writes through the api.php handlers, and those populate
     * several fields only on the CREATE path — product type, the sale and
     * interval/slot flags, and the parent price range are never brought
     * back in line by an update. Rebuilding from an empty catalog is what
     * keeps every field in step with the feed.
     *
     * Runs the same wipe helpers as the classic sync, in the same order.
     * They are shared with that path, so they are called here, never
     * modified.
     *
     * @return array
     * @throws RuntimeException When the feed is unreadable — nothing is deleted.
     */
    private function phase_purge() {
        $session = Rental_Data_Sync_Session::get( $this->sync_id );

        // Wiping is destructive and belongs to the run exactly once. A
        // replay must not empty a catalog this run has already started
        // rebuilding.
        if ( ! empty( $session['purged'] ) ) {
            Rental_Data_Sync_Logger::write( 'Purge skipped: this run already wiped the catalog', 'info', 'purge' );
            return [ 'offset' => 1, 'done' => true, 'processed' => 0 ];
        }

        // Prove the feed is readable BEFORE deleting anything: wiping and
        // then failing to read the catalog would leave the storefront
        // empty with nothing to refill it. fetch_page() throws when the
        // body is not a list, which the driver retries with backoff and
        // turns terminal after MAX_CONSECUTIVE_ERRORS — with the catalog
        // still intact.
        $this->fetch_page( 'products', 0, 1 );
        $this->fetch_page( 'products/variants', 0, 1 );

        rental_empty_inventory_blocks();
        rental_empty_product_options();

        if ( function_exists( 'rental_empty_set_options' ) ) {
            rental_empty_set_options();
        }

        rental_empty_coupons();
        rental_empty_price_multipliers();

        // Products, variants, their meta, the product taxonomies and the
        // relation tables. Image relations pointing at attachments that
        // still exist survive, so the chained file sync reuses the media
        // already downloaded instead of fetching the whole library again.
        rental_empty_products();

        if ( ! get_option( 'rental_do_not_use_rentopian_shipping' ) ) {
            rental_empty_shipping_zones();
        }

        if ( defined( 'WPSEO_VERSION' ) ) {
            rental_empty_yoast();
        }

        rental_clear_cache();

        Rental_Data_Sync_Session::update( $this->sync_id, [ 'purged' => 1 ] );

        Rental_Data_Sync_Logger::write(
            'Catalog wiped: products, variants, product taxonomies, relations, product options, set options, '
            . 'coupons, price multipliers and inventory blocks removed — the rebuild starts from an empty catalog',
            'notice',
            'purge'
        );

        return [ 'offset' => 1, 'done' => true, 'processed' => 1 ];
    }

    /* ══════════════════════════════════════════════════════════
     * Phase 2 — taxonomies
     * ══════════════════════════════════════════════════════════ */

    private function phase_taxonomies( $offset ) {
        $maps = $this->load_maps();

        switch ( (int) $offset ) {
            case 0:
                // Attributes + values.
                $attributes = (array) rental_curl( 'products/attributes', $this->api_key );
                $values     = (array) rental_curl( 'products/attributes/values', $this->api_key );

                foreach ( $attributes as $attribute ) {
                    $this->writer->upsert_attribute( $attribute );
                    $maps['attributes'][ (int) $attribute->id ] = $attribute;
                }
                foreach ( $values as $value ) {
                    $this->writer->upsert_attribute_value( $value );
                    $maps['attribute_values'][ (int) $value->id ] = $value;
                }

                $this->sync_attribute_value_groups();

                $this->save_maps( $maps );
                return [ 'offset' => 1, 'done' => false, 'processed' => count( $attributes ) + count( $values ) ];

            case 1:
                // Categories, parents before children.
                $categories = (array) rental_curl( 'products/categories', $this->api_key );
                $sorted     = $this->sort_parents_first( $categories );

                $seen = [];
                foreach ( $sorted as $category ) {
                    $this->writer->upsert_category( $category );
                    $seen[] = (int) $category->id;

                    if ( ! empty( $category->products ) ) {
                        foreach ( explode( ',', (string) $category->products ) as $pid ) {
                            $pid = (int) $pid;
                            if ( $pid > 0 ) {
                                $maps['categories_by_product'][ $pid ][ (int) $category->id ] = (string) $category->title;
                            }
                        }
                    }
                }

                Rental_Data_Sync_Session::add_seen_ids( $this->sync_id, 'categories', $seen );
                $this->save_maps( $maps );
                return [ 'offset' => 2, 'done' => false, 'processed' => count( $sorted ) ];

            default:
                // Tags map, brands, sets tags.
                $tags = (array) rental_curl( 'products/tags', $this->api_key );
                foreach ( $tags as $tag ) {
                    if ( empty( $tag->products ) ) {
                        continue;
                    }
                    foreach ( explode( ',', (string) $tag->products ) as $pid ) {
                        $pid = (int) $pid;
                        if ( $pid > 0 ) {
                            $maps['tags_by_product'][ $pid ][ (int) $tag->id ] = (string) $tag->title;
                        }
                    }
                }

                $maps['tags_raw'] = $tags;

                $brands = (array) rental_curl( 'products/brands', $this->api_key );
                $seen   = [];
                foreach ( $brands as $brand ) {
                    $this->writer->upsert_brand( $brand );
                    $seen[] = (int) $brand->id;
                }
                Rental_Data_Sync_Session::add_seen_ids( $this->sync_id, 'brands', $seen );

                $maps['sets_tags'] = (array) rental_curl( 'inventories/sets/tags', $this->api_key );

                $this->save_maps( $maps );
                return [ 'offset' => 3, 'done' => true, 'processed' => count( $tags ) + count( $brands ) ];
        }
    }

    /**
     * Rebuild attribute value group membership from the full pull.
     *
     * Membership is replaced wholesale, so it is only touched when the
     * endpoint actually answered: a core that does not serve it yet makes
     * `rental_curl()` throw, and taking that as "no groups" would erase every
     * group on the site. The run itself must not fail over it either — the
     * groups only drive the filter facet, and the previous membership stays
     * usable until the next pull.
     *
     * @return void
     */
    private function sync_attribute_value_groups() {
        if ( ! class_exists( 'Rental_Attribute_Groups' ) ) {
            return;
        }

        Rental_Attribute_Groups::ensure_tables();

        try {
            $pairs = rental_curl( 'products/attributes/value-groups', $this->api_key );
        } catch ( Exception $e ) {
            Rental_Data_Sync_Logger::write(
                'Attribute value groups not rebuilt: ' . $e->getMessage() . ' — the previous membership is kept',
                'warning',
                'phase'
            );

            return;
        }

        if ( ! is_array( $pairs ) ) {
            Rental_Data_Sync_Logger::write(
                'Attribute value groups not rebuilt: the response was not a list — the previous membership is kept',
                'warning',
                'phase'
            );

            return;
        }

        $stored = Rental_Attribute_Groups::replace_all_membership( $pairs );

        Rental_Data_Sync_Logger::write(
            sprintf( 'Attribute value groups rebuilt: %d membership row(s)', $stored ),
            'info',
            'phase'
        );
    }

    /**
     * Fetch one page of a paged endpoint, insisting on a list.
     *
     * A paging phase decides it is finished when a page comes back shorter
     * than the page size. `rental_curl()` throws on a non-200, but a 200
     * whose body will not decode — a connection reset mid-body, an HTML
     * error page from a proxy, a PHP notice printed before the JSON —
     * returns null, which would read as "zero rows" and end the phase
     * early. The run would then complete, having imported a fraction of
     * the catalog, and the sweep would delete everything it never saw.
     *
     * Throwing instead hands the step to the driver's retry-with-backoff,
     * and turns terminal after MAX_CONSECUTIVE_ERRORS.
     *
     * @param string $endpoint
     * @param int    $offset
     * @param int    $limit
     * @return array
     * @throws RuntimeException When the body is not a list.
     */
    private function fetch_page( $endpoint, $offset, $limit ) {
        $rows = rental_curl( $endpoint, $this->api_key, true, [
            'start' => (int) $offset,
            'limit' => (int) $limit,
        ] );

        if ( ! is_array( $rows ) ) {
            throw new RuntimeException( sprintf(
                '%s page at offset %d returned a %s instead of a list — the response was not usable JSON',
                $endpoint,
                (int) $offset,
                null === $rows ? 'null body' : gettype( $rows )
            ) );
        }

        Rental_Data_Sync_Logger::write(
            sprintf( 'Fetched %d row(s) from %s at offset %d', count( $rows ), $endpoint, (int) $offset ),
            'info',
            'phase'
        );

        return $rows;
    }

    /* ══════════════════════════════════════════════════════════
     * Phase 3 — variants staging (no DB writes)
     * ══════════════════════════════════════════════════════════ */

    private function phase_variants( $offset ) {
        $limit = $this->page_size();

        $rows  = $this->fetch_page( 'products/variants', $offset, $limit );
        $count = count( $rows );

        $by_product = [];
        $seen       = [];
        foreach ( $rows as $row ) {
            $pid = (int) ( $row->product_id ?? 0 );
            if ( $pid <= 0 ) {
                continue;
            }
            $by_product[ $pid ][] = $row;
            $seen[]               = ( (int) $row->id ) . ':' . ( (int) $row->division_id );
        }

        foreach ( $by_product as $pid => $bucket ) {
            $this->append_jsonl( "variants-{$pid}.jsonl", $bucket );
        }

        Rental_Data_Sync_Session::add_seen_ids( $this->sync_id, 'variants', $seen );

        return [
            'offset'    => $offset + max( 1, $count ),
            'done'      => ( $count < $limit ),
            'processed' => $count,
        ];
    }

    /* ══════════════════════════════════════════════════════════
     * Phase 4 — products (rebuilt via webhook handlers)
     * ══════════════════════════════════════════════════════════ */

    private function phase_products( $offset ) {
        $limit = $this->page_size();
        $maps  = $this->load_maps();

        $rows  = $this->fetch_page( 'products', $offset, $limit );
        $count = count( $rows );

        $seen          = [];
        $products_data = [];

        foreach ( $rows as $product ) {
            $pid    = (int) $product->id;
            $bucket = $this->read_jsonl( "variants-{$pid}.jsonl" );

            $this->writer->upsert_product( $product, $bucket, $maps );

            foreach ( $bucket as $row ) {
                $seen[ $pid . ':' . (int) $row->division_id ] = 1;
            }

            // Entry for the sets pass (products_data shape of the legacy loop).
            $hidden          = ! empty( $product->hidden_from_api ) && filter_var( $product->hidden_from_api, FILTER_VALIDATE_BOOLEAN );
            $addon           = ! empty( $product->is_add_on ) && filter_var( $product->is_add_on, FILTER_VALIDATE_BOOLEAN );
            $products_data[] = [
                'id'            => $pid,
                'img_id'        => ! empty( $product->img_id ) ? $product->img_id : ( ! empty( $product->variant_img_id ) ? $product->variant_img_id : '' ),
                'exempt_waiver' => $product->exempt_waiver ?? 0,
                'is_sale'       => $product->is_sale ?? 0,
                'is_add_on'     => ( $addon || $hidden ) ? 1 : 0,
                'add_ons'       => $product->add_ons ?? '',
            ];
        }

        if ( ! empty( $products_data ) ) {
            $this->append_jsonl( 'products-data.jsonl', $products_data );
        }
        Rental_Data_Sync_Session::add_seen_ids( $this->sync_id, 'products', array_keys( $seen ) );

        // Products the writer declined to write are present in the feed,
        // so they are not orphans however the sweep reads the seen-set.
        Rental_Data_Sync_Session::add_skipped_ids(
            $this->sync_id,
            'products',
            $this->writer->skipped_ids( 'products' )
        );

        // Only now, with the whole page written, are the buckets safe to
        // drop. Deleting each one inside the loop made the step
        // non-repeatable: the cursor advances once per page, so a throw
        // half-way through sends the driver back to the same offset — and
        // the products it had already handled would find their variants
        // gone, import as empty, and be swept as orphans.
        foreach ( $rows as $product ) {
            $this->delete_staging_file( 'variants-' . (int) $product->id . '.jsonl' );
        }

        Rental_Data_Sync_Logger::write(
            sprintf(
                'Products page at offset %d: %d row(s) imported, %d product/division pairing(s) recorded as present',
                (int) $offset,
                $count,
                count( $seen )
            ),
            'info',
            'phase'
        );

        $done = ( $count < $limit );
        if ( $done ) {
            $leftovers = glob( $this->staging_path( 'variants-*.jsonl' ) );
            if ( is_array( $leftovers ) && count( $leftovers ) > 0 ) {
                Rental_Data_Sync_Logger::write(
                    count( $leftovers ) . ' variant bucket(s) had no matching product row (product deleted or filtered by the API key scope)',
                    'warning',
                    'phase'
                );
            }
        }

        return [
            'offset'    => $offset + max( 1, $count ),
            'done'      => $done,
            'processed' => $count,
        ];
    }

    /* ══════════════════════════════════════════════════════════
     * Phase 5 — sets (sets-module sync pass, delete + recreate)
     * ══════════════════════════════════════════════════════════ */

    private function phase_sets( $offset ) {
        if ( (int) $offset === 0 ) {
            $sets = rental_curl( 'inventories/sets', $this->api_key );
            $sets = is_array( $sets ) ? $sets : [];
            $this->write_staging_json( 'sets.json', $sets );

            return [ 'offset' => 1, 'done' => false, 'processed' => count( $sets ) ];
        }

        $sets      = $this->read_staging_json( 'sets.json' );
        $processed = 0;

        if ( ! empty( $sets ) && function_exists( 'rental_run_sets_sync_pass' ) ) {
            $processed = $this->import_sets( $sets );
        } else {
            update_option( "rental_data_sync_coupon_excludes_{$this->sync_id}", [], false );
        }

        // Set options are a small full rebuild, exactly like the legacy run.
        if ( function_exists( 'rental_empty_set_options' ) ) {
            rental_empty_set_options();
        }
        if ( function_exists( 'rental_add_set_options' ) ) {
            rental_add_set_options();
        }

        return [ 'offset' => 2, 'done' => true, 'processed' => $processed ];
    }

    /**
     * Delete existing set posts and recreate them all through the sets
     * module sync pass (the same delete-and-rebuild semantics every legacy
     * run applies to sets; set counts are small, so this fits one step).
     *
     * @param array $sets Decoded `/inventories/sets` rows.
     * @return int Number of sets imported.
     */
    private function import_sets( array $sets ) {
        global $wpdb, $rental_tables;

        $set_relations = $wpdb->prefix . $rental_tables['set_relations'];

        $existing_rentals = $wpdb->get_col( "SELECT DISTINCT rental_id FROM {$set_relations}" );

        // Remove previous set posts (scoped copy of the legacy empty pass).
        $old_ids = array_filter( array_map( 'intval', $wpdb->get_col( "SELECT id FROM {$set_relations}" ) ) );
        if ( ! empty( $old_ids ) ) {
            $in = implode( ',', $old_ids );
            $wpdb->query( "DELETE FROM {$wpdb->posts} WHERE ID IN ({$in})" );
            $wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE post_id IN ({$in})" );
            $wpdb->query( "DELETE FROM {$wpdb->term_relationships} WHERE object_id IN ({$in})" );
            $wpdb->query( "DELETE FROM {$wpdb->prefix}wc_product_meta_lookup WHERE product_id IN ({$in})" );
        }
        $wpdb->query( "DELETE FROM {$set_relations}" );

        // Rebuild the id maps the sets pass expects from this run's data.
        list( $products_data, $variant_ids ) = $this->build_sets_pass_maps();

        $simple = (int) $wpdb->get_var(
            "SELECT `term_id` FROM {$wpdb->terms} WHERE `name` = 'simple' AND `slug` = 'simple'"
        );

        $existing_slugs = $wpdb->get_col( "SELECT slug FROM {$wpdb->posts} WHERE post_type IN ('product','product_variation')" );

        // Explicit-id allocation with a safety gap; a duplicate-key race is
        // caught below and the whole step retries on the next callback.
        $starting_id = (int) $wpdb->get_var( "SELECT MAX(ID) FROM {$wpdb->posts}" ) + 50;

        $result = rental_run_sets_sync_pass( [
            'sets'                         => $sets,
            'starting_id'                  => $starting_id,
            'products_data'                => $products_data,
            'variant_ids'                  => $variant_ids,
            'simple_tt_id'                 => $simple,
            'date'                         => date( 'Y-m-d H:i:s' ),
            'gmdate'                       => gmdate( 'Y-m-d H:i:s' ),
            'domain'                       => get_option( 'siteurl' ),
            'products_slug'                => is_array( $existing_slugs ) ? $existing_slugs : [],
            'rental_img_variant_rel'       => [],
            'coupons_excluded_product_ids' => [],
            'has_product_meta_lookup_sql'  => true,
        ] );

        rental_insert(
            "INSERT INTO `{$wpdb->posts}` (`id`, `post_author`, `post_date`, " .
            "`post_date_gmt`, `post_title`, `post_content`, `post_excerpt`, `post_status`, " .
            "`comment_status`, `ping_status`, `post_name`, `to_ping`, `pinged`, `post_modified`, " .
            "`post_modified_gmt`, `post_content_filtered`, `post_parent`, `guid`,`post_type`) VALUES",
            $result->product_sql(),
            500
        );
        rental_insert( "INSERT INTO `{$wpdb->postmeta}` (`post_id`, `meta_key`, `meta_value`) VALUES", $result->productmeta_sql(), 300 );
        rental_insert(
            "INSERT INTO `{$wpdb->prefix}wc_product_meta_lookup` (`product_id`, `sku`, `virtual`, `downloadable`, " .
            "`min_price`, `max_price`, `onsale`, `stock_quantity`, `stock_status`, `tax_status`) VALUES",
            $result->product_meta_lookup_sql(),
            500
        );
        rental_insert( "INSERT INTO `{$set_relations}` (`id`, `rental_id`, `rental_division_id`) VALUES", $result->set_relations_sql(), 800 );
        rental_insert( "INSERT INTO `{$wpdb->term_relationships}` (`object_id`, `term_taxonomy_id`, `term_order`) VALUES", $result->term_relation_sql(), 800 );

        if ( $wpdb->last_error !== '' ) {
            throw new RuntimeException( 'Sets import SQL error: ' . $wpdb->last_error );
        }

        // Up/cross sells between sets and products.
        if ( function_exists( 'rental_set_sets_up_sells_cross_sells' ) ) {
            $product_ids_for_up_cross_sells = [];
            foreach ( $products_data as $rid => $pdata ) {
                foreach ( (array) $pdata['ids'] as $wp_id ) {
                    $product_ids_for_up_cross_sells[ $rid ][] = $wp_id;
                }
            }

            rental_set_sets_up_sells_cross_sells(
                $result->up_sells_from_sets(),
                $result->up_sells_from_products(),
                $result->cross_sells_from_sets(),
                $result->cross_sells_from_products(),
                $result->set_ids(),
                $product_ids_for_up_cross_sells
            );
        }

        $this->rebuild_sets_tags( $result->set_ids() );

        // Coupon exclusions produced by the sets pass, consumed by extras.
        update_option(
            "rental_data_sync_coupon_excludes_{$this->sync_id}",
            (array) $result->coupons_excluded_product_ids(),
            false
        );

        // Stats: recreated sets count as updated when previously present.
        $set_ids   = (array) $result->set_ids();
        $existing  = array_flip( array_map( 'intval', (array) $existing_rentals ) );
        $created   = 0;
        $updated   = 0;
        foreach ( array_keys( $set_ids ) as $rental_set_id ) {
            if ( isset( $existing[ (int) $rental_set_id ] ) ) {
                $updated++;
            } else {
                $created++;
            }
        }
        Rental_Data_Sync_Session::add_stats( $this->sync_id, [
            'sets' => [ 'created' => $created, 'updated' => $updated ],
        ] );
        Rental_Data_Sync_Session::add_seen_ids( $this->sync_id, 'sets', array_map( 'intval', array_keys( $set_ids ) ) );

        return count( $set_ids );
    }

    /**
     * Delete-and-recreate the set tag terms through the sets module's SQL
     * builder (explicit term ids, same as the legacy run). Set-tag term
     * ownership is tracked in the sets_tag_relations table, so product-tag
     * terms reused via the similar-tags dedupe are never touched.
     *
     * @param array $set_ids rental set id => wp post id.
     */
    private function rebuild_sets_tags( array $set_ids ) {
        global $wpdb, $rental_tables;

        if ( ! function_exists( 'rental_build_sets_tags_sql' ) || empty( $rental_tables['sets_tag_relations'] ) ) {
            return;
        }

        $maps      = $this->load_maps();
        $sets_tags = $maps['sets_tags'];
        if ( empty( $sets_tags ) ) {
            return;
        }
        // Rows round-trip through maps.json as assoc arrays; the builder
        // and the similar-tags helper read object properties.
        $sets_tags = json_decode( wp_json_encode( $sets_tags ) );

        $rel_table = $wpdb->prefix . $rental_tables['sets_tag_relations'];

        // Drop set-tag terms created by previous runs.
        $old_term_ids = array_filter( array_map( 'intval', $wpdb->get_col( "SELECT id FROM {$rel_table}" ) ) );
        if ( ! empty( $old_term_ids ) ) {
            $in = implode( ',', $old_term_ids );
            $wpdb->query( "DELETE FROM {$wpdb->terms} WHERE term_id IN ({$in})" );
            $wpdb->query( "DELETE FROM {$wpdb->termmeta} WHERE term_id IN ({$in})" );
            $wpdb->query( "DELETE FROM {$wpdb->term_taxonomy} WHERE term_taxonomy_id IN ({$in})" );
            $wpdb->query( "DELETE FROM {$wpdb->term_relationships} WHERE term_taxonomy_id IN ({$in})" );
        }
        $wpdb->query( "DELETE FROM {$rel_table}" );

        // Dedupe set tags against existing product tags.
        $similar_tags = [];
        if ( function_exists( 'rental_detect_similar_sets_tags_for_product_tag' ) ) {
            foreach ( (array) ( $maps['tags_raw'] ?? [] ) as $tag ) {
                $tag = is_array( $tag ) ? (object) $tag : $tag;
                if ( isset( $tag->title ) ) {
                    rental_detect_similar_sets_tags_for_product_tag( $tag->title, $sets_tags, $similar_tags );
                }
            }
        }

        $term_base = (int) $wpdb->get_var(
            "SELECT GREATEST( IFNULL(MAX(term_id),0), (SELECT IFNULL(MAX(term_taxonomy_id),0) FROM {$wpdb->term_taxonomy}) ) FROM {$wpdb->terms}"
        ) + 50;

        $terms_sql = $termmeta_sql = $term_taxonomy_sql = $term_relation_sql = $sets_tag_relations_sql = [];

        rental_build_sets_tags_sql(
            $sets_tags,
            $term_base,
            $similar_tags,
            $set_ids,
            $terms_sql,
            $termmeta_sql,
            $term_taxonomy_sql,
            $term_relation_sql,
            $sets_tag_relations_sql
        );

        rental_insert( "INSERT INTO `{$wpdb->terms}` (`term_id`, `name`, `slug`, `term_group`) VALUES", $terms_sql );
        rental_insert( "INSERT INTO `{$wpdb->termmeta}` (`term_id`, `meta_key`, `meta_value`) VALUES", $termmeta_sql );
        rental_insert( "INSERT INTO `{$wpdb->term_taxonomy}` (`term_taxonomy_id`, `term_id`, `taxonomy`, `description`, `parent`, `count`) VALUES", $term_taxonomy_sql );
        rental_insert( "INSERT INTO `{$wpdb->term_relationships}` (`object_id`, `term_taxonomy_id`, `term_order`) VALUES", $term_relation_sql, 800 );
        rental_insert( "INSERT INTO `{$rel_table}` (`id`, `rental_id`) VALUES", $sets_tag_relations_sql );

        if ( $wpdb->last_error !== '' ) {
            throw new RuntimeException( 'Sets tags SQL error: ' . $wpdb->last_error );
        }
    }

    /**
     * Rebuild products_data / variant_ids for the sets pass from this
     * run's staged product entries + the relation tables.
     *
     * @return array [ products_data, variant_ids ]
     */
    private function build_sets_pass_maps() {
        global $wpdb, $rental_tables;

        $products_data = [];
        foreach ( $this->read_jsonl( 'products-data.jsonl' ) as $entry ) {
            $products_data[ (int) $entry->id ] = [
                'ids'           => [],
                'img_id'        => $entry->img_id,
                'exempt_waiver' => $entry->exempt_waiver,
                'is_sale'       => $entry->is_sale,
                'is_add_on'     => $entry->is_add_on,
                'add_ons'       => $entry->add_ons,
            ];
        }

        $product_relations = $wpdb->prefix . $rental_tables['product_relations'];
        foreach ( $wpdb->get_results( "SELECT id, rental_id, rental_division_id FROM {$product_relations}" ) as $rel ) {
            $rid = (int) $rel->rental_id;
            if ( isset( $products_data[ $rid ] ) ) {
                $products_data[ $rid ]['ids'][ (int) $rel->rental_division_id ] = (int) $rel->id;
            }
        }

        $variant_ids       = [];
        $variant_relations = $wpdb->prefix . $rental_tables['variant_relations'];
        foreach ( $wpdb->get_results( "SELECT id, rental_id, rental_division_id FROM {$variant_relations}" ) as $rel ) {
            $variant_ids[ (int) $rel->rental_id ][ (int) $rel->rental_division_id ] = (int) $rel->id;
        }

        return [ $products_data, $variant_ids ];
    }

    /* ══════════════════════════════════════════════════════════
     * Phase 6 — extras (small full rebuilds, legacy pairs)
     * ══════════════════════════════════════════════════════════ */

    private function phase_extras() {
        global $wpdb;

        $excludes = get_option( "rental_data_sync_coupon_excludes_{$this->sync_id}", [] );
        $excludes = is_array( $excludes ) ? implode( ',', array_unique( array_map( 'intval', $excludes ) ) ) : '';

        rental_empty_coupons();
        $coupon_base = (int) $wpdb->get_var( "SELECT MAX(ID) FROM {$wpdb->posts}" ) + 50;
        rental_add_coupons( $coupon_base, $excludes );

        rental_empty_price_multipliers();
        rental_add_price_multipliers();

        rental_empty_inventory_blocks();
        rental_add_inventory_blocks();

        rental_empty_product_options();
        rental_add_product_options();

        delete_option( "rental_data_sync_coupon_excludes_{$this->sync_id}" );

        return [ 'offset' => 1, 'done' => true, 'processed' => 4 ];
    }

    /* ══════════════════════════════════════════════════════════
     * Phase 7 — sweep (guarded orphan deletion; skipped after a wipe)
     * ══════════════════════════════════════════════════════════ */

    private function phase_sweep( $offset ) {
        $session = Rental_Data_Sync_Session::get( $this->sync_id );

        // After a wipe there are no orphans by construction: every record
        // that exists was written by this run. Running the sweep anyway
        // would only expose live rows to a deletion pass whose input (the
        // seen-id set) is the very thing that rebuilt them.
        if ( ! empty( $session['purged'] ) ) {
            Rental_Data_Sync_Logger::write(
                'Sweep not needed: the catalog was wiped this run, so nothing can be orphaned',
                'info',
                'sweep'
            );
            return [ 'offset' => $offset + 1, 'done' => true, 'processed' => 0 ];
        }

        $required = [
            Rental_Data_Sync_Status::PHASE_CONFIG,
            Rental_Data_Sync_Status::PHASE_TAXONOMIES,
            Rental_Data_Sync_Status::PHASE_VARIANTS,
            Rental_Data_Sync_Status::PHASE_PRODUCTS,
            Rental_Data_Sync_Status::PHASE_SETS,
            Rental_Data_Sync_Status::PHASE_EXTRAS,
        ];

        if ( ! Rental_Data_Sync_Session::phases_done( $this->sync_id, $required ) ) {
            Rental_Data_Sync_Logger::write( 'Sweep skipped: not every fetch phase completed this run', 'error', 'sweep' );
            Rental_Data_Sync_Session::update( $this->sync_id, [ 'sweep_skipped' => 1 ] );
            return [ 'offset' => $offset + 1, 'done' => true, 'processed' => 0 ];
        }

        $entities   = [ 'products', 'variants', 'sets', 'categories', 'brands' ];
        $entity_idx = (int) floor( $offset / self::SWEEP_ENTITY_STRIDE );

        if ( $entity_idx >= count( $entities ) ) {
            return [ 'offset' => $offset + 1, 'done' => true, 'processed' => 0 ];
        }

        $entity  = $entities[ $entity_idx ];
        $orphans = $this->find_orphans( $entity );

        if ( ! $this->sweep_is_safe( $entity, count( $orphans ) ) ) {
            Rental_Data_Sync_Session::update( $this->sync_id, [ 'sweep_skipped' => 1 ] );
            return [ 'offset' => ( $entity_idx + 1 ) * self::SWEEP_ENTITY_STRIDE, 'done' => false, 'processed' => 0 ];
        }

        $batch     = array_slice( $orphans, 0, self::SWEEP_BATCH );
        $processed = 0;
        foreach ( $batch as $orphan ) {
            $this->writer->delete_record( $entity, $orphan['rental_id'], $orphan['division_id'] );
            $processed++;
        }

        if ( count( $orphans ) > $processed ) {
            // More of this entity next callback.
            return [ 'offset' => $offset + $processed, 'done' => false, 'processed' => $processed ];
        }

        // Entity finished — jump to the next entity boundary.
        $next     = ( $entity_idx + 1 ) * self::SWEEP_ENTITY_STRIDE;
        $has_next = ( $entity_idx + 1 ) < count( $entities );

        return [ 'offset' => $next, 'done' => ! $has_next, 'processed' => $processed ];
    }

    /**
     * Relation rows whose rental id was never seen this run.
     *
     * @param string $entity
     * @return array [ [ rental_id, division_id ], ... ]
     */
    private function find_orphans( $entity ) {
        global $wpdb, $rental_tables;

        $seen    = Rental_Data_Sync_Session::get_seen_ids( $this->sync_id, $entity );
        $skipped = Rental_Data_Sync_Session::get_skipped_ids( $this->sync_id, $entity );
        $orphans = [];

        $table_key = [
            'products'   => 'product_relations',
            'variants'   => 'variant_relations',
            'sets'       => 'set_relations',
            'categories' => 'category_relations',
            'brands'     => 'brand_relations',
        ];
        if ( ! isset( $table_key[ $entity ] ) || empty( $rental_tables[ $table_key[ $entity ] ] ) ) {
            return [];
        }
        $table = $wpdb->prefix . $rental_tables[ $table_key[ $entity ] ];

        if ( $entity === 'products' || $entity === 'variants' ) {
            $rows = $wpdb->get_results( "SELECT DISTINCT rental_id, rental_division_id FROM {$table}" );
            foreach ( $rows as $row ) {
                $rental_id = (int) $row->rental_id;

                // Present in the feed but not written this run: not an
                // orphan. Checked on the id alone, because a skipped
                // product produced no payload for ANY division — while
                // products that did import stay keyed on id:division, so
                // withdrawing one from a single division still deletes it.
                if ( isset( $skipped[ $rental_id ] ) ) {
                    continue;
                }

                $key = $rental_id . ':' . ( (int) $row->rental_division_id );
                if ( ! isset( $seen[ $key ] ) ) {
                    $orphans[] = [ 'rental_id' => $rental_id, 'division_id' => (int) $row->rental_division_id ];
                }
            }
        } else {
            $ids = $wpdb->get_col( "SELECT DISTINCT rental_id FROM {$table}" );
            foreach ( $ids as $rid ) {
                $rid = (int) $rid;
                if ( ! isset( $seen[ $rid ] ) && ! isset( $skipped[ $rid ] ) ) {
                    $orphans[] = [ 'rental_id' => $rid, 'division_id' => 0 ];
                }
            }
        }

        if ( $skipped ) {
            Rental_Data_Sync_Logger::write(
                sprintf(
                    'Sweep of %s: %d orphan(s) to delete, %d record(s) held back because this run skipped them',
                    $entity,
                    count( $orphans ),
                    count( $skipped )
                ),
                'info',
                'sweep'
            );
        }

        return $orphans;
    }

    /**
     * Refuse a sweep that would delete a large share of an entity — a
     * wrong or partial feed must never wipe the catalog. Overridable per
     * run via the rental_data_sync_sweep_force option.
     */
    private function sweep_is_safe( $entity, $orphan_count ) {
        if ( $orphan_count <= 20 ) {
            return true;
        }
        if ( (int) get_option( 'rental_data_sync_sweep_force', 0 ) === 1 ) {
            return true;
        }

        $seen_count = count( Rental_Data_Sync_Session::get_seen_ids( $this->sync_id, $entity ) );
        $total      = $seen_count + $orphan_count;

        // Refuse when orphans are 40%+ of the entity.
        if ( $total > 0 && ( $orphan_count * 100 ) >= ( $total * 40 ) ) {
            Rental_Data_Sync_Logger::write(
                "Sweep aborted for {$entity}: {$orphan_count} orphans vs {$seen_count} seen — refusing mass deletion "
                . '(set rental_data_sync_sweep_force=1 to override on a verified feed change)',
                'error',
                'sweep'
            );
            return false;
        }
        return true;
    }

    /* ══════════════════════════════════════════════════════════
     * Phase 8 — finalize + chain the file sync
     * ══════════════════════════════════════════════════════════ */

    private function phase_finalize() {
        // Category menu-order pass, exactly like the legacy tail.
        $categories = rental_curl( 'products/categories', $this->api_key );
        if ( is_array( $categories ) && function_exists( 'sort_categories' ) ) {
            sort_categories( json_decode( stripslashes( json_encode( $categories ) ) ), false );
        }

        rental_clear_cache();

        // Superseded variations and the lookup rows of deleted posts skew
        // price ranges and layered-nav filters while being invisible on
        // the storefront.
        Rental_Data_Sync_Integrity::purge_orphan_posts();

        do_action( 'rental_after_synchronization' );

        $session = Rental_Data_Sync_Session::get( $this->sync_id );

        // Run record for the combined email report. The report is composed
        // when the chained file sync ends, by which time the per-run
        // options are gone — so everything it needs is copied here.
        update_option( 'rental_data_sync_last_run', [
            'sync_id'           => $this->sync_id,
            'started_at'        => $session['started_at'] ?? '',
            'data_completed_at' => current_time( 'mysql' ),
            'stats'             => Rental_Data_Sync_Session::get_stats( $this->sync_id ),
            'failures'          => Rental_Data_Sync_Session::get_failures( $this->sync_id ),
            'sweep_skipped'     => (int) ( $session['sweep_skipped'] ?? 0 ),
            'purged'            => (int) ( $session['purged'] ?? 0 ),
        ], false );

        // A completed data run is what flips the admin button to "Resync".
        Rental_Data_Sync_Scheduler::record_success( $this->sync_id );

        // Deferred file-sync chain: never call sync/start from inside this
        // callback — the Laravel chunk job still holds the per-company
        // overlap lock while it waits for this response, and the file job
        // it dispatches would be discarded by WithoutOverlapping.
        Rental_Data_Sync_Scheduler::queue_file_chain( $this->sync_id );

        return [ 'offset' => 1, 'done' => true, 'processed' => 1 ];
    }

    /* ══════════════════════════════════════════════════════════
     * Helpers
     * ══════════════════════════════════════════════════════════ */

    private function page_size() {
        $size = (int) get_option( 'rental_data_sync_page_size', 100 );
        return max( 10, min( 300, $size ?: 100 ) );
    }

    private function staging_path( $file ) {
        $dir = Rental_Data_Sync_Session::staging_dir( $this->sync_id );
        return $dir ? $dir . '/' . $file : '';
    }

    private function append_jsonl( $file, array $rows ) {
        $path = $this->staging_path( $file );
        if ( ! $path ) {
            throw new RuntimeException( 'Data sync staging directory is not writable' );
        }
        $lines = '';
        foreach ( $rows as $row ) {
            $lines .= wp_json_encode( $row ) . "\n";
        }
        if ( false === file_put_contents( $path, $lines, FILE_APPEND | LOCK_EX ) ) {
            throw new RuntimeException( "Failed writing staging file {$file}" );
        }
    }

    /**
     * @return array Decoded objects, one per line.
     */
    private function read_jsonl( $file ) {
        $path = $this->staging_path( $file );
        if ( ! $path || ! file_exists( $path ) ) {
            return [];
        }
        $rows = [];
        $fh   = fopen( $path, 'rb' );
        if ( ! $fh ) {
            return [];
        }
        while ( ( $line = fgets( $fh ) ) !== false ) {
            $line = trim( $line );
            if ( $line === '' ) {
                continue;
            }
            $decoded = json_decode( $line );
            if ( $decoded !== null ) {
                $rows[] = $decoded;
            }
        }
        fclose( $fh );
        return $rows;
    }

    private function write_staging_json( $file, $data ) {
        $path = $this->staging_path( $file );
        if ( ! $path || false === file_put_contents( $path, wp_json_encode( $data ), LOCK_EX ) ) {
            throw new RuntimeException( "Failed writing staging file {$file}" );
        }
    }

    private function read_staging_json( $file ) {
        $path = $this->staging_path( $file );
        if ( ! $path || ! file_exists( $path ) ) {
            return [];
        }
        $decoded = json_decode( (string) file_get_contents( $path ) );
        return is_array( $decoded ) ? $decoded : [];
    }

    private function delete_staging_file( $file ) {
        $path = $this->staging_path( $file );
        if ( $path && file_exists( $path ) ) {
            @unlink( $path );
        }
    }

    /**
     * Order categories so parents precede children.
     *
     * @param array $categories
     * @return array
     */
    private function sort_parents_first( array $categories ) {
        $sorted  = [];
        $emitted = [];
        $pending = $categories;

        // Bounded passes; anything cyclic or orphaned appends at the end.
        for ( $pass = 0; $pass < 10 && ! empty( $pending ); $pass++ ) {
            $next = [];
            foreach ( $pending as $category ) {
                $parent = (int) ( $category->parent_id ?? 0 );
                if ( $parent === 0 || isset( $emitted[ $parent ] ) ) {
                    $sorted[]                        = $category;
                    $emitted[ (int) $category->id ] = 1;
                } else {
                    $next[] = $category;
                }
            }
            $pending = $next;
        }

        return array_merge( $sorted, $pending );
    }

    /**
     * Composer maps, cached per request, persisted in staging.
     *
     * @return array
     */
    private function load_maps() {
        if ( $this->maps !== null ) {
            return $this->maps;
        }

        $path = $this->staging_path( 'maps.json' );
        $maps = [];
        if ( $path && file_exists( $path ) ) {
            $decoded = json_decode( (string) file_get_contents( $path ), true );
            if ( is_array( $decoded ) ) {
                $maps = $decoded;
            }
        }

        $maps += [
            'attributes'           => [],
            'attribute_values'     => [],
            'categories_by_product' => [],
            'tags_by_product'      => [],
            'sets_tags'            => [],
        ];

        // Attribute rows round-trip through JSON as arrays; the composer
        // passes them into payloads where handlers read object properties,
        // which json_encode preserves either way.
        $this->maps = $maps;
        return $maps;
    }

    private function save_maps( array $maps ) {
        $this->maps = $maps;
        $path       = $this->staging_path( 'maps.json' );
        if ( ! $path || false === file_put_contents( $path, wp_json_encode( $maps ), LOCK_EX ) ) {
            throw new RuntimeException( 'Failed writing maps.json to the staging directory' );
        }
    }
}
