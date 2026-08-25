<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * REST Endpoint: POST /wp-json/rentopian/v1/orphans/delete-chunk
 *
 * Deletes image attachments that are related to WooCommerce products,
 * variations, term thumbnails, or which are effectively unattached
 * (post_parent = 0 OR pointing to a deleted / trashed post — i.e. "zombie
 * orphans" left behind by a prior full-sync teardown).
 *
 * For each candidate:
 *   - if it's product-related, delete unless also used outside products
 *   - otherwise, delete only if it's truly unused anywhere on the site
 *
 * Body parameters:
 *   - after_id    (int)    cursor; default 0
 *   - limit       (int)    batch size; default 25; max 500
 *   - dry_run     (bool)   default false
 *   - exclude_ids (int[])  optional
 *   - token|wp_token (string) shared secret
 *
 * Returns:
 *   { deleted_ids, failed_ids, skipped_used_ids, skip_reasons, inspected,
 *     last_id, has_more }
 *
 * @package RentopianSync\FileSyncDelete
 */
class Rental_Orphan_Delete_Endpoint {

    use Rental_Orphan_Detection;

    private static $log_source = 'rentopian-file-delete';

    public static function init() {
        add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
    }

    public static function register_routes() {
        register_rest_route( 'rentopian/v1', '/orphans/delete-chunk', [
            'methods'             => 'POST',
            'callback'            => [ __CLASS__, 'handle_delete_chunk' ],
            'permission_callback' => [ __CLASS__, 'check_token' ],
            'args'                => [
                'after_id'    => [ 'type' => 'integer' ],
                'limit'       => [ 'type' => 'integer' ],
                'dry_run'     => [ 'type' => 'boolean' ],
                'exclude_ids' => [ 'type' => 'array' ],
                'token'       => [ 'type' => 'string' ],
                'wp_token'    => [ 'type' => 'string' ],
            ],
        ] );
    }

    public static function check_token( WP_REST_Request $req ) {
        $provided = $req->get_header( 'x-rentopian-delete-token' );
        if ( ! $provided ) { $provided = (string) $req->get_param( 'wp_token' ); }
        if ( ! $provided ) { $provided = (string) $req->get_param( 'token' ); }
        $expected = get_option( 'rental_delete_token', '_secret_xO9B81G' ) ?: '_secret_xO9B81G';

        return ( is_string( $expected ) && '' !== $expected && hash_equals( $expected, $provided ) );
    }

    public static function handle_delete_chunk( WP_REST_Request $req ) {
        global $wpdb;

        while ( ob_get_level() > 0 ) { ob_end_clean(); }
        ob_start();

        $after_id    = max( 0, (int) $req->get_param( 'after_id' ) );
        $limit       = null !== $req->get_param( 'limit' )
            ? max( 1, min( 500, (int) $req->get_param( 'limit' ) ) )
            : 25;
        $dry_run     = (bool) $req->get_param( 'dry_run' );
        $exclude_ids = $req->get_param( 'exclude_ids' );
        $exclude_ids = is_array( $exclude_ids ) ? array_map( 'intval', $exclude_ids ) : [];

        $pp = $wpdb->posts;
        $pm = $wpdb->postmeta;
        $tm = isset( $wpdb->termmeta ) ? $wpdb->termmeta : $wpdb->prefix . 'termmeta';

        self::log( sprintf(
            'delete-chunk START | after_id=%d limit=%d dry_run=%s excludes=%d',
            $after_id, $limit, $dry_run ? 'yes' : 'no', count( $exclude_ids )
        ) );

        /*
         * Selector:
         *   - live product / variation parent, OR
         *   - thumbnail ref on any live post, OR
         *   - product / variation gallery ref, OR
         *   - relevant term-meta ref, OR
         *   - unattached (post_parent = 0), OR
         *   - zombie orphan (parent row missing, trashed, or auto-draft)
         *
         * The LEFT JOIN with the post_status filter means parent.* is NULL
         * for deleted / trashed / auto-draft parents — so they all get
         * picked up by the `parent.ID IS NULL` branch.
         */
        $sql = "
            SELECT p.ID
            FROM {$pp} p
            LEFT JOIN {$pp} parent
                   ON parent.ID = p.post_parent
                  AND parent.post_status NOT IN ('trash','auto-draft')
            WHERE p.post_type      = 'attachment'
              AND p.post_status    IN ('inherit','private')
              AND p.post_mime_type LIKE %s
              AND p.ID             > %d
              AND (
                    ( parent.post_type IN ('product','product_variation') )
                 OR EXISTS ( SELECT 1 FROM {$pm} pm1
                              WHERE pm1.meta_key = '_thumbnail_id'
                                AND pm1.meta_value = p.ID )
                 OR EXISTS ( SELECT 1 FROM {$pm} pm2
                              WHERE pm2.meta_key = '_product_image_gallery'
                                AND FIND_IN_SET(p.ID, pm2.meta_value) )
                 OR EXISTS ( SELECT 1 FROM {$pm} pm3
                              WHERE pm3.meta_key = 'zoo-cw-variation-gallery'
                                AND FIND_IN_SET(p.ID, pm3.meta_value) )
                 OR EXISTS ( SELECT 1 FROM {$tm} tm1
                              WHERE tm1.meta_key IN ('thumbnail_id','banner_id','sw_image','slctd_img')
                                AND tm1.meta_value = p.ID )
                 OR ( p.post_parent = 0 OR parent.ID IS NULL )
              )
            ORDER BY p.ID ASC
            LIMIT %d
        ";
        $ids = $wpdb->get_col( $wpdb->prepare( $sql, 'image/%', $after_id, $limit ) );

        $deleted      = [];
        $failed       = [];
        $skipped_used = [];
        $skip_reasons = [];
        $inspected    = 0;
        $last_seen_id = $after_id;

        foreach ( $ids as $aid ) {
            $aid = (int) $aid;
            $last_seen_id = max( $last_seen_id, $aid );
            $inspected++;

            if ( in_array( $aid, $exclude_ids, true ) ) {
                $skipped_used[]       = $aid;
                $skip_reasons[ $aid ] = 'excluded';
                continue;
            }

            if ( ! wp_attachment_is_image( $aid ) ) {
                $skipped_used[]       = $aid;
                $skip_reasons[ $aid ] = 'not_image';
                continue;
            }

            // Product-related path
            if ( self::is_product_related( $aid ) ) {
                $outside = self::get_outside_product_reason( $aid );
                if ( null !== $outside ) {
                    $skipped_used[]       = $aid;
                    $skip_reasons[ $aid ] = 'product+' . $outside;
                    continue;
                }

                if ( $dry_run ) { $deleted[] = $aid; continue; }
                try {
                    if ( wp_delete_attachment( $aid, true ) ) { $deleted[] = $aid; }
                    else                                      { $failed[]  = $aid; }
                } catch ( \Throwable $e ) {
                    $failed[] = $aid;
                    self::log( sprintf( 'delete-chunk EXCEPTION id=%d: %s', $aid, $e->getMessage() ), 'error' );
                }
                continue;
            }

            // Not product-related: only delete if completely unused
            $reason = self::get_usage_info( $aid );
            if ( null === $reason ) {
                if ( $dry_run ) { $deleted[] = $aid; continue; }
                try {
                    if ( wp_delete_attachment( $aid, true ) ) { $deleted[] = $aid; }
                    else                                      { $failed[]  = $aid; }
                } catch ( \Throwable $e ) {
                    $failed[] = $aid;
                    self::log( sprintf( 'delete-chunk EXCEPTION id=%d: %s', $aid, $e->getMessage() ), 'error' );
                }
            } else {
                $skipped_used[]       = $aid;
                $skip_reasons[ $aid ] = $reason;
            }
        }

        // has_more
        $has_more = (bool) $wpdb->get_var( $wpdb->prepare( "
            SELECT 1
            FROM {$pp} p
            LEFT JOIN {$pp} parent
                   ON parent.ID = p.post_parent
                  AND parent.post_status NOT IN ('trash','auto-draft')
            WHERE p.post_type      = 'attachment'
              AND p.post_status    IN ('inherit','private')
              AND p.post_mime_type LIKE %s
              AND p.ID             > %d
              AND (
                    ( parent.post_type IN ('product','product_variation') )
                 OR EXISTS ( SELECT 1 FROM {$pm} pm1
                              WHERE pm1.meta_key = '_thumbnail_id'
                                AND pm1.meta_value = p.ID )
                 OR EXISTS ( SELECT 1 FROM {$pm} pm2
                              WHERE pm2.meta_key = '_product_image_gallery'
                                AND FIND_IN_SET(p.ID, pm2.meta_value) )
                 OR EXISTS ( SELECT 1 FROM {$pm} pm3
                              WHERE pm3.meta_key = 'zoo-cw-variation-gallery'
                                AND FIND_IN_SET(p.ID, pm3.meta_value) )
                 OR EXISTS ( SELECT 1 FROM {$tm} tm1
                              WHERE tm1.meta_key IN ('thumbnail_id','banner_id','sw_image','slctd_img')
                                AND tm1.meta_value = p.ID )
                 OR ( p.post_parent = 0 OR parent.ID IS NULL )
              )
            LIMIT 1
        ", 'image/%', $last_seen_id ) );

        self::log( sprintf(
            'delete-chunk DONE | inspected=%d deleted=%d failed=%d skipped=%d last_id=%d has_more=%s',
            $inspected, count( $deleted ), count( $failed ), count( $skipped_used ),
            $last_seen_id, $has_more ? 'yes' : 'no'
        ) );

        if ( ! empty( $deleted ) ) {
            self::log( 'delete-chunk DELETED_IDS: ' . implode( ',', $deleted ) );
        }
        if ( ! empty( $failed ) ) {
            self::log( 'delete-chunk FAILED_IDS: ' . implode( ',', $failed ), 'warning' );
        }
        if ( ! empty( $skip_reasons ) ) {
            foreach ( self::summarize_reasons( $skip_reasons ) as $reason => $ids_for_reason ) {
                self::log( sprintf(
                    'delete-chunk SKIPPED [%s]: %s',
                    $reason,
                    implode( ',', $ids_for_reason )
                ) );
            }
        }

        $stray = ob_get_clean();
        if ( $stray ) {
            self::log( 'delete-chunk WARNING stray output: ' . substr( $stray, 0, 500 ), 'warning' );
        }

        return new WP_REST_Response( [
            'deleted_ids'      => $deleted,
            'failed_ids'       => $failed,
            'skipped_used_ids' => $skipped_used,
            'skip_reasons'     => $skip_reasons,
            'inspected'        => $inspected,
            'last_id'          => $last_seen_id,
            'has_more'         => $has_more,
        ], 200 );
    }

    private static function summarize_reasons( array $skip_reasons ) {
        $out = [];
        foreach ( $skip_reasons as $id => $reason ) {
            $out[ $reason ][] = $id;
        }
        return $out;
    }

    private static function log( $message, $level = 'info' ) {
        Rental_Delete_Logger::log( $message, $level );
    }
}
