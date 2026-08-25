<?php
/**
 * Data Sync Record Writer
 *
 * Feeds composed payloads to the existing `api.php` webhook handlers so the
 * background sync produces byte-identical results to live webhooks.
 *
 * api.php is a standalone endpoint whose dispatcher echoes/exits; the
 * RENTAL_DATA_SYNC_HANDLER_LOAD constant makes it return before dispatching
 * while PHP's early binding still registers every handler function. Every
 * handler call is wrapped in output buffering because handlers echo their
 * webhook responses instead of returning them.
 *
 * Upsert rule per record (mirrors webhook semantics):
 *  - product missing locally → product/create (creates variants inline)
 *  - product exists → product/update + variant/create|update per inventory
 *    row (product/update only refreshes product fields and stock).
 *
 * @package RentopianSync\DataSync
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Rental_Data_Sync_Record_Writer {

    /**
     * How many failing records one callback keeps. The counters carry the
     * true total; this is the sample a report names.
     */
    const MAX_FAILURES = 50;

    /** @var array Per-callback counters: entity => [created,updated,failed,skipped] */
    private $stats = [];

    /**
     * Records this callback could not write, as
     * [ entity, context, reason ]. A counter says how many failed; this
     * says which ones and why, so a report can name them instead of
     * sending the reader to a log.
     *
     * @var array[]
     */
    private $failures = [];

    /**
     * Why the most recent handler call failed. Handlers answer with an
     * echoed error envelope rather than a return value, so the reason is
     * kept here for the caller that records the failure.
     *
     * @var string
     */
    private $last_error = '';

    /**
     * Rental ids this callback declined to write, per entity. The sweep
     * must not treat a record the writer chose to skip as an orphan — the
     * feed did contain it, so deleting it would be wrong.
     *
     * @var array entity => int[]
     */
    private $skipped_ids = [];

    /**
     * @param string $entity
     * @return int[] Rental ids skipped by this writer instance.
     */
    public function skipped_ids( $entity ) {
        return isset( $this->skipped_ids[ $entity ] ) ? $this->skipped_ids[ $entity ] : [];
    }

    /**
     * Record a record the writer deliberately did not write.
     *
     * @param string $entity
     * @param int    $rental_id
     */
    private function mark_skipped( $entity, $rental_id ) {
        $this->skipped_ids[ $entity ][] = (int) $rental_id;
    }

    /**
     * Attributes already resolved this callback, keyed by rental id.
     * Only hits are kept: an attribute can appear part-way through a run,
     * so a miss must stay repeatable.
     *
     * @var array
     */
    private $attribute_cache = [];

    /**
     * Load a model the api.php handlers require on demand.
     *
     * The handler library is loaded without dispatching, so the model
     * files a handler pulls in are absent until one runs — and the probe
     * that tells `created` from `updated` needs them before that.
     *
     * @param string $class
     * @return bool TRUE when the class is available.
     */
    private static function load_model( $class ) {
        if ( class_exists( $class, false ) ) {
            return true;
        }

        $base = defined( 'RENTOPIAN_SYNC_PATH' ) ? RENTOPIAN_SYNC_PATH : dirname( __DIR__, 2 );
        $path = $base . "/includes/models/{$class}.php";

        if ( ! file_exists( $path ) ) {
            return false;
        }

        require_once $path;

        return class_exists( $class, false );
    }

    /**
     * Counter key for a successful write.
     *
     * An inconclusive probe reports `updated`: understating what is new
     * beats inventing it, and it is what this counter said before the
     * probe existed.
     *
     * @param bool|null $existed
     * @return string
     */
    private static function write_key( $existed ) {
        return ( false === $existed ) ? 'created' : 'updated';
    }

    /**
     * Load the api.php handler library exactly once.
     *
     * @return bool TRUE when handlers are callable.
     */
    public static function load_handlers() {
        if ( function_exists( 'rentopian_product_create' ) ) {
            return true;
        }

        if ( ! defined( 'RENTAL_DATA_SYNC_HANDLER_LOAD' ) ) {
            define( 'RENTAL_DATA_SYNC_HANDLER_LOAD', true );
        }

        $api = defined( 'RENTOPIAN_SYNC_PATH' )
            ? RENTOPIAN_SYNC_PATH . '/api.php'
            : dirname( __DIR__, 2 ) . '/api.php';

        if ( ! file_exists( $api ) ) {
            return false;
        }

        require_once $api;

        return function_exists( 'rentopian_product_create' );
    }

    /* ──────────────────────────────────────────────────────────
     * Stats
     * ────────────────────────────────────────────────────────── */

    private function bump( $entity, $key ) {
        if ( ! isset( $this->stats[ $entity ][ $key ] ) ) {
            $this->stats[ $entity ][ $key ] = 0;
        }
        $this->stats[ $entity ][ $key ]++;
    }

    /**
     * Count a record as failed and keep what went wrong with it.
     *
     * The reason comes from the handler call that just returned false, so
     * this must be called while `$last_error` still belongs to it.
     *
     * @param string $entity  Counter key.
     * @param string $context Human label for the record ("product 55 div 2").
     */
    private function fail( $entity, $context ) {
        $this->bump( $entity, 'failed' );

        if ( count( $this->failures ) >= self::MAX_FAILURES ) {
            return;
        }

        $this->failures[] = [
            'entity'  => $entity,
            'context' => (string) $context,
            'reason'  => '' !== $this->last_error
                ? $this->last_error
                : __( 'the handler rejected the record without giving a reason', 'rentopian-sync' ),
        ];
    }

    /**
     * @return array Accumulated counters since construction.
     */
    public function stats() {
        return $this->stats;
    }

    /**
     * @return array[] Failing records recorded since construction.
     */
    public function failures() {
        return $this->failures;
    }

    /* ──────────────────────────────────────────────────────────
     * Products (+ their variants)
     * ────────────────────────────────────────────────────────── */

    /**
     * Upsert one product with all its inventory rows.
     *
     * @param object $product     Row from `/products`.
     * @param array  $bucket_rows Rows from `/products/variants` for this product.
     * @param array  $maps
     */
    public function upsert_product( $product, array $bucket_rows, array $maps ) {
        global $wpdb, $rental_tables;

        $product_id = (int) $product->id;

        $payloads = Rental_Data_Sync_Payload_Composer::compose_product_payloads( $product, $bucket_rows, $maps );
        if ( empty( $payloads ) ) {
            $this->bump( 'products', 'skipped' );

            // The feed DID carry this product — it simply produced no
            // writable payload. Recording the id keeps the sweep from
            // reading "absent from the feed" and deleting it.
            $this->mark_skipped( 'products', $product_id );

            Rental_Data_Sync_Logger::write(
                "Product {$product_id} skipped: no active inventory rows — excluded from this run's sweep",
                'warning',
                'write'
            );
            return;
        }

        $rel_table = $wpdb->prefix . $rental_tables['product_relations'];

        foreach ( $payloads as $division_id => $payload ) {
            $exists = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT `id` FROM {$rel_table} WHERE `rental_id` = %d AND `rental_division_id` = %d",
                $product_id,
                $division_id
            ) );

            // A relation pointing at a missing or trashed post is stale —
            // only the create path (create-or-replace) revives it.
            if ( $exists && ! $this->post_is_live( $exists ) ) {
                Rental_Data_Sync_Logger::write( "Product {$product_id} div {$division_id}: relation points at missing/trashed post {$exists}, recreating", 'notice', 'write' );
                $exists = 0;
            }

            if ( $exists ) {
                $ok = $this->call_handler( 'rentopian_product_update', $payload, "product {$product_id} div {$division_id}" );

                if ( ! $ok ) {
                    // Stale relation (post gone): create is create-or-replace.
                    Rental_Data_Sync_Logger::write( "Healing product {$product_id} div {$division_id}: update failed, recreating", 'notice', 'write' );
                    $ok = $this->call_handler( 'rentopian_product_create', $payload, "product {$product_id} div {$division_id} (heal)" );

                    if ( $ok ) {
                        $this->bump( 'products', 'created' );
                    } else {
                        $this->fail( 'products', "product {$product_id} div {$division_id}" );
                    }
                } else {
                    $this->bump( 'products', 'updated' );
                }

                if ( $ok ) {
                    $this->upsert_division_variants( $product, $bucket_rows, $maps, (int) $division_id );
                }
            } else {
                $ok = $this->call_handler( 'rentopian_product_create', $payload, "product {$product_id} div {$division_id}" );

                if ( $ok ) {
                    $this->bump( 'products', 'created' );

                    // Variant posts are created inline by the product handler.
                    foreach ( $bucket_rows as $row ) {
                        if ( (int) $row->division_id === (int) $division_id ) {
                            $this->bump( 'variants', 'created' );
                        }
                    }
                } else {
                    $this->fail( 'products', "product {$product_id} div {$division_id}" );
                }
            }

            if ( $ok ) {
                $this->reconcile_parent( $product_id, (int) $division_id );
            }
        }
    }

    /**
     * Bring the parent product back in line with the variations that now
     * exist beneath it, and drop the caches WooCommerce built from the
     * previous shape.
     *
     * `product/update` refreshes fields and stock only, so a parent whose
     * attribute metadata was removed keeps rendering no variation
     * dropdowns however many times the sync runs. Deriving the parent from
     * its own live variations is what closes that gap.
     *
     * @param int $rental_product_id
     * @param int $division_id
     */
    private function reconcile_parent( $rental_product_id, $division_id ) {
        global $wpdb, $rental_tables;

        $post_id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT `id` FROM {$wpdb->prefix}{$rental_tables['product_relations']}
             WHERE `rental_id` = %d AND `rental_division_id` = %d",
            $rental_product_id,
            $division_id
        ) );

        if ( $post_id <= 0 ) {
            return;
        }

        if ( Rental_Data_Sync_Integrity::reconcile_product( $post_id ) ) {
            $this->bump( 'products', 'repaired' );
        }

        Rental_Data_Sync_Integrity::flush_product_caches( $post_id );
    }

    /**
     * Upsert the variant posts of an EXISTING product for one division.
     * (product/update refreshes stock only; prices/attributes arrive via
     * the variant handlers, matching the live webhook flow.)
     */
    private function upsert_division_variants( $product, array $bucket_rows, array $maps, $division_id ) {
        global $wpdb, $rental_tables;

        $rel_table = $wpdb->prefix . $rental_tables['variant_relations'];

        foreach ( $bucket_rows as $row ) {
            if ( (int) $row->division_id !== (int) $division_id ) {
                continue;
            }

            $variant_id = (int) $row->id;
            $payload    = Rental_Data_Sync_Payload_Composer::compose_variant_payload( $row, $product, $bucket_rows, $maps );

            $exists = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT `id` FROM {$rel_table} WHERE `rental_id` = %d AND `rental_division_id` = %d",
                $variant_id,
                $division_id
            ) );

            if ( $exists && ! $this->post_is_live( $exists ) ) {
                Rental_Data_Sync_Logger::write( "Variant {$variant_id} div {$division_id}: relation points at missing/trashed post {$exists}, recreating", 'notice', 'write' );
                $exists = 0;
            }

            if ( $exists ) {
                $ok = $this->call_handler( 'rentopian_variant_update', $payload, "variant {$variant_id} div {$division_id}" );

                if ( ! $ok ) {
                    // Stale relation (post gone): recreate the variant.
                    Rental_Data_Sync_Logger::write( "Healing variant {$variant_id} div {$division_id}: update failed, recreating", 'notice', 'write' );
                    $ok = $this->call_handler( 'rentopian_variant_create', $payload, "variant {$variant_id} div {$division_id} (heal)" );

                    if ( $ok ) {
                        $this->bump( 'variants', 'created' );
                    } else {
                        $this->fail( 'variants', "variant {$variant_id} div {$division_id}" );
                    }
                } else {
                    $this->bump( 'variants', 'updated' );
                }
            } else {
                $ok = $this->call_handler( 'rentopian_variant_create', $payload, "variant {$variant_id} div {$division_id}" );

                if ( $ok ) {
                    $this->bump( 'variants', 'created' );
                } else {
                    $this->fail( 'variants', "variant {$variant_id} div {$division_id}" );
                }
            }
        }
    }

    /* ──────────────────────────────────────────────────────────
     * Taxonomies
     * ────────────────────────────────────────────────────────── */

    /**
     * Upsert one category through the webhook handlers.
     *
     * @param object $category Row from `/products/categories`.
     */
    public function upsert_category( $category ) {
        $exists = $this->live_relation( 'category_relations', (int) $category->id, 'term', 'category ' . (int) $category->id );

        $payload = Rental_Data_Sync_Payload_Composer::encode( $category );
        $fn      = $exists ? 'rentopian_category_update' : 'rentopian_category_create';
        $ok      = $this->call_handler( $fn, $payload, 'category ' . (int) $category->id );

        if ( $ok ) {
            $this->bump( 'categories', $exists ? 'updated' : 'created' );
        } else {
            $this->fail( 'categories', 'category ' . (int) $category->id );
        }
    }

    /**
     * Resolve a relation row, treating one whose WordPress record is gone
     * as absent — and deleting it, so the caller takes the create path and
     * the record is restored rather than "updated" into a void.
     *
     * @param string $table_key `$rental_tables` key.
     * @param int    $rental_id
     * @param string $kind      post | term | wc_attribute
     * @param string $context   For logging.
     * @return int WP id, or 0 when there is nothing usable.
     */
    private function live_relation( $table_key, $rental_id, $kind, $context ) {
        global $wpdb, $rental_tables;

        if ( empty( $rental_tables[ $table_key ] ) ) {
            return 0;
        }

        $table = $wpdb->prefix . $rental_tables[ $table_key ];

        $wp_id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT `id` FROM {$table} WHERE `rental_id` = %d",
            (int) $rental_id
        ) );

        if ( $wp_id <= 0 ) {
            return 0;
        }

        if ( Rental_Data_Sync_Integrity::target_is_live( $wp_id, $kind ) ) {
            return $wp_id;
        }

        $wpdb->delete( $table, [ 'rental_id' => (int) $rental_id ], [ '%d' ] );

        Rental_Data_Sync_Logger::write(
            "Recreating {$context}: relation pointed at deleted record {$wp_id}",
            'notice',
            'write'
        );

        return 0;
    }

    /**
     * Upsert one brand ($_POST-based handler).
     *
     * @param object $brand Row from `/products/brands`.
     */
    public function upsert_brand( $brand ) {
        // A brand with no title can't become a WP term (wp_insert_term
        // requires a name); the shared handler would fatal on it. Skip
        // cleanly rather than record a permanent failure.
        if ( trim( (string) ( $brand->title ?? '' ) ) === '' ) {
            $this->bump( 'brands', 'skipped' );
            Rental_Data_Sync_Logger::write( 'Brand ' . (int) ( $brand->id ?? 0 ) . ' skipped: empty title', 'notice', 'write' );
            return;
        }

        $existed = $this->brand_exists( (int) $brand->id );

        $ok = $this->call_post_handler( 'rentopian_save_brand', 'brand', $brand, 'brand ' . (int) $brand->id );

        if ( $ok ) {
            $this->bump( 'brands', self::write_key( $existed ) );
        } else {
            $this->fail( 'brands', 'brand ' . (int) $brand->id );
        }
    }

    /**
     * Whether this brand already has a term locally.
     *
     * Asked through the model the handler itself uses, so the counter
     * names the branch the write actually took — a relation row pointing
     * at a deleted term reads as absent here exactly as it does there.
     *
     * @param int $rental_id
     * @return bool|null NULL when the model is unavailable.
     */
    private function brand_exists( $rental_id ) {
        if ( ! self::load_model( 'RTBrand' ) ) {
            return null;
        }

        return (bool) ( new RTBrand( (int) $rental_id ) )->getBrand();
    }

    /**
     * Upsert one attribute ($_POST-based handler).
     *
     * @param object $attribute Row from `/products/attributes`.
     */
    public function upsert_attribute( $attribute ) {
        $existed = $this->attribute_exists( (int) $attribute->id );

        $ok = $this->call_post_handler( 'rentopian_save_product_attribute', 'attribute', $attribute, 'attribute ' . (int) $attribute->id );

        if ( $ok ) {
            $this->bump( 'attributes', self::write_key( $existed ) );
        } else {
            $this->fail( 'attributes', 'attribute ' . (int) $attribute->id );
        }
    }

    /**
     * Whether this attribute already has a WooCommerce taxonomy.
     *
     * @param int $rental_id
     * @return bool|null NULL when the model is unavailable.
     */
    private function attribute_exists( $rental_id ) {
        if ( ! self::load_model( 'RTAttribute' ) ) {
            return null;
        }

        return null !== $this->resolve_attribute( $rental_id );
    }

    /**
     * The WooCommerce attribute taxonomy behind a Rentopian attribute id.
     *
     * Every value of an attribute needs it, so a hit is remembered. A miss
     * is not: the attribute may be created later in the same run.
     *
     * @param int $rental_id
     * @return object|null
     */
    private function resolve_attribute( $rental_id ) {
        $rental_id = (int) $rental_id;

        if ( isset( $this->attribute_cache[ $rental_id ] ) ) {
            return $this->attribute_cache[ $rental_id ];
        }

        if ( ! self::load_model( 'RTAttribute' ) ) {
            return null;
        }

        $attribute = ( new RTAttribute( $rental_id ) )->getAttribute();

        if ( $attribute ) {
            $this->attribute_cache[ $rental_id ] = $attribute;
        }

        return $attribute ?: null;
    }

    /**
     * Upsert one attribute value ($_POST-based handler).
     *
     * @param object $value Row from `/products/attributes/values`.
     */
    public function upsert_attribute_value( $value ) {
        $context = 'attribute value ' . (int) $value->id;
        $existed = $this->attribute_value_exists( $value );

        $ok = $this->call_post_handler(
            'rentopian_product_attribute_value_handler',
            'attribute_value',
            $value,
            $context,
            [ 'save' ]
        );

        if ( $ok ) {
            $this->bump( 'attribute_values', self::write_key( $existed ) );
        } else {
            $this->fail( 'attribute_values', $context );
        }
    }

    /**
     * Whether this attribute value already has a term.
     *
     * Mirrors the lookup the model performs, down to preferring
     * `old_slug` on a rename — that is the term the write will find.
     *
     * @param object $value Row from `/products/attributes/values`.
     * @return bool|null NULL when the model is unavailable.
     */
    private function attribute_value_exists( $value ) {
        if ( ! self::load_model( 'RTAttributeValue' ) ) {
            return null;
        }

        $attribute = $this->resolve_attribute( (int) ( $value->attribute_id ?? 0 ) );
        if ( ! $attribute ) {
            // The model gives up here too, so no term can already exist.
            return false;
        }

        $slug     = (string) ( $value->slug ?? '' );
        $old_slug = isset( $value->old_slug ) ? (string) $value->old_slug : '';
        $lookup   = ( '' !== $old_slug && $old_slug !== $slug ) ? $old_slug : $slug;

        $probe = new RTAttributeValue(
            (int) ( $value->attribute_id ?? 0 ),
            $slug,
            (string) ( $value->title ?? '' ),
            $value->color ?? null,
            $value->img_id ?? null,
            $old_slug ?: null
        );

        return (bool) $probe->getAttributeValue( $lookup, $attribute->attribute_name );
    }

    /* ──────────────────────────────────────────────────────────
     * Sets
     * ────────────────────────────────────────────────────────── */

    /**
     * Upsert one set (delegates to the modern sets module handler).
     *
     * @param object $set Row from `/inventories/sets`.
     */
    public function upsert_set( $set ) {
        $set_id = (int) $set->id;

        // A set is a post: trashing it by hand must not make the sync
        // "update" a record the storefront can no longer see.
        $exists = $this->live_relation( 'set_relations', $set_id, 'post', "set {$set_id}" );

        $payload = Rental_Data_Sync_Payload_Composer::encode( $set );
        $fn      = $exists ? 'rentopian_set_update' : 'rentopian_set_create';
        $ok      = $this->call_handler( $fn, $payload, 'set ' . $set_id );

        if ( $ok ) {
            $this->bump( 'sets', $exists ? 'updated' : 'created' );
        } else {
            $this->fail( 'sets', 'set ' . $set_id );
        }

        // Grouped children, their add-ons and option items all hang off the
        // set post, so the set's own children decide what renders.
        if ( $ok && $exists ) {
            Rental_Data_Sync_Integrity::flush_product_caches( $exists );
        }
    }

    /* ──────────────────────────────────────────────────────────
     * Deletes (sweep)
     * ────────────────────────────────────────────────────────── */

    /**
     * Delete one orphaned record via its webhook delete handler.
     *
     * @param string $entity      products | variants | sets | categories | brands
     * @param int    $rental_id
     * @param int    $division_id
     * @return bool
     */
    public function delete_record( $entity, $rental_id, $division_id = 0 ) {
        $ok = false;

        // An unrecognised entity reaches the counter without calling a
        // handler, so the previous call's reason must not be read as its.
        $this->last_error = '';
        $context          = "sweep {$entity} {$rental_id}";

        switch ( $entity ) {
            case 'products':
                $context = "sweep product {$rental_id} div {$division_id}";
                $payload = Rental_Data_Sync_Payload_Composer::encode( [ 'id' => (int) $rental_id, 'division_id' => (int) $division_id ] );
                $ok      = $this->call_handler( 'rentopian_product_delete', $payload, $context );
                break;

            case 'variants':
                $context = "sweep variant {$rental_id} div {$division_id}";
                $payload = Rental_Data_Sync_Payload_Composer::encode( [ 'id' => (int) $rental_id, 'division_id' => (int) $division_id ] );
                $ok      = $this->call_handler( 'rentopian_variant_delete', $payload, $context );
                break;

            case 'sets':
                $context = "sweep set {$rental_id}";
                $payload = Rental_Data_Sync_Payload_Composer::encode( [ 'id' => (int) $rental_id ] );
                $ok      = $this->call_handler( 'rentopian_set_delete', $payload, $context );
                break;

            case 'categories':
                $context = "sweep category {$rental_id}";
                $payload = Rental_Data_Sync_Payload_Composer::encode( [ 'id' => (int) $rental_id ] );
                $ok      = $this->call_handler( 'rentopian_category_delete', $payload, $context );
                break;

            case 'brands':
                $context = "sweep brand {$rental_id}";
                $ok      = $this->call_post_handler( 'rentopian_delete_brand', 'brand', [ 'id' => (int) $rental_id ], $context );
                break;
        }

        if ( $ok ) {
            $this->bump( $entity, 'deleted' );
        } else {
            $this->fail( $entity, $context );
        }

        return $ok;
    }

    /**
     * @param int $post_id
     * @return bool TRUE when the post exists and is not trashed.
     */
    private function post_is_live( $post_id ) {
        $status = get_post_status( $post_id );
        return $status !== false && $status !== 'trash';
    }

    /* ──────────────────────────────────────────────────────────
     * Handler invocation
     * ────────────────────────────────────────────────────────── */

    /**
     * Call a payload-argument handler with output captured.
     *
     * @param string $fn
     * @param string $payload Slashed JSON.
     * @param string $context For logging.
     * @return bool FALSE when the handler threw.
     */
    private function call_handler( $fn, $payload, $context ) {
        $this->last_error = '';

        if ( ! function_exists( $fn ) ) {
            $this->last_error = "the {$fn} handler is not available";
            Rental_Data_Sync_Logger::write( "Handler {$fn} missing for {$context}", 'error', 'write' );
            return false;
        }

        ob_start();
        try {
            $fn( $payload );
            $ok = true;
        } catch ( Throwable $t ) {
            $this->last_error = $t->getMessage();
            Rental_Data_Sync_Logger::write( "{$fn} threw for {$context}: " . $t->getMessage(), 'error', 'write' );
            $ok = false;
        } finally {
            $echoed = trim( (string) ob_get_clean() );
        }

        // Handlers set 4xx codes + echo errors instead of throwing.
        if ( http_response_code() >= 400 ) {
            http_response_code( 200 );
            $ok = false;
        }
        if ( ! $ok ) {
            if ( $echoed !== '' ) {
                Rental_Data_Sync_Logger::write( "{$fn} output for {$context}: " . substr( $echoed, 0, 300 ), 'warning', 'write' );
            }
            if ( '' === $this->last_error ) {
                $this->last_error = self::failure_reason( $echoed );
            }
        }

        return $ok;
    }

    /**
     * Call a $_POST-based handler with a scoped superglobal shim.
     *
     * @param string       $fn
     * @param string       $key     $_POST key the handler reads.
     * @param object|array $record  Encoded into that key.
     * @param string       $context
     * @param array        $flags   Extra truthy $_POST keys (e.g. ['save']).
     * @return bool
     */
    private function call_post_handler( $fn, $key, $record, $context, array $flags = [] ) {
        $this->last_error = '';

        if ( ! function_exists( $fn ) ) {
            $this->last_error = "the {$fn} handler is not available";
            Rental_Data_Sync_Logger::write( "Handler {$fn} missing for {$context}", 'error', 'write' );
            return false;
        }

        $backup = $_POST;

        $_POST[ $key ] = Rental_Data_Sync_Payload_Composer::encode( $record );
        foreach ( $flags as $flag ) {
            $_POST[ $flag ] = 1;
        }

        ob_start();
        try {
            // The value handler takes an action argument; others take none.
            if ( $fn === 'rentopian_product_attribute_value_handler' ) {
                $fn( 'save' );
            } else {
                $fn();
            }
            $ok = true;
        } catch ( Throwable $t ) {
            $this->last_error = $t->getMessage();
            Rental_Data_Sync_Logger::write( "{$fn} threw for {$context}: " . $t->getMessage(), 'error', 'write' );
            $ok = false;
        } finally {
            $echoed = trim( (string) ob_get_clean() );
            $_POST  = $backup;
        }

        if ( http_response_code() >= 400 ) {
            http_response_code( 200 );
            $ok = false;
        }
        if ( ! $ok ) {
            if ( $echoed !== '' ) {
                Rental_Data_Sync_Logger::write( "{$fn} output for {$context}: " . substr( $echoed, 0, 300 ), 'warning', 'write' );
            }
            if ( '' === $this->last_error ) {
                $this->last_error = self::failure_reason( $echoed );
            }
        }

        return $ok;
    }

    /**
     * Turn a handler's echoed response into a sentence a report can print.
     *
     * Handlers answer with a JSON error envelope; anything else is passed
     * through truncated, so an unexpected shape still says something
     * rather than nothing.
     *
     * @param string $echoed
     * @return string '' when the handler said nothing.
     */
    private static function failure_reason( $echoed ) {
        $echoed = trim( (string) $echoed );
        if ( '' === $echoed ) {
            return '';
        }

        $decoded = json_decode( $echoed, true );
        if ( is_array( $decoded ) && ! empty( $decoded['error'] ) && is_scalar( $decoded['error'] ) ) {
            return trim( (string) $decoded['error'] );
        }

        return substr( $echoed, 0, 200 );
    }
}
