<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * REST Endpoint: POST /wp-json/rentopian/v1/orphans/delete-orphans-chunk
 *
 * Deletes truly orphaned image attachments.
 *
 * "Orphaned" here means either of:
 *   a) post_parent = 0 (canonical unattached), OR
 *   b) post_parent points to a post that no longer exists, or is in the
 *      'trash' / 'auto-draft' state ("zombie orphan" — the common
 *      after-product-deletion case).
 *
 * Both cases are treated identically: the attachment is considered a
 * deletion candidate and then run through is_completely_unused() to confirm
 * it is not referenced anywhere else on the site.
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
class Rental_Orphan_Only_Delete_Endpoint {

    use Rental_Orphan_Detection;

    private static $log_source = 'rentopian-file-delete';

    public static function init() {
        add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
    }

    public static function register_routes() {
        register_rest_route( 'rentopian/v1', '/orphans/delete-orphans-chunk', [
            'methods'             => 'POST',
            'callback'            => [ __CLASS__, 'handle_delete_orphans_only' ],
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

    public static function handle_delete_orphans_only( WP_REST_Request $req ) {
        global $wpdb;

        // Swallow stray PHP output that would corrupt the JSON response
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

        self::log( sprintf(
            'delete-orphans-chunk START | after_id=%d limit=%d dry_run=%s excludes=%d',
            $after_id, $limit, $dry_run ? 'yes' : 'no', count( $exclude_ids )
        ) );

        /*
         * Selector: unattached (post_parent=0) OR zombie orphans (parent dead).
         *
         * The LEFT JOIN with the post_status filter on the joined row means
         * parent.ID is NULL for:
         *   - deleted parents (row missing)
         *   - trashed parents
         *   - auto-draft parents
         * …all of which are treated as "no live parent".
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
              AND ( p.post_parent = 0 OR parent.ID IS NULL )
            ORDER BY p.ID ASC
            LIMIT %d
        ";
        $ids = $wpdb->get_col( $wpdb->prepare( $sql, 'image/%', $after_id, $limit ) );

        $deleted      = [];
        $failed       = [];
        $skipped_used = [];
        $skip_reasons = []; // id => reason
        $inspected    = 0;
        $last_seen_id = $after_id;

        foreach ( $ids as $aid ) {
            $aid = (int) $aid;
            $last_seen_id = max( $last_seen_id, $aid );
            $inspected++;

            if ( in_array( $aid, $exclude_ids, true ) ) {
                $skipped_used[]      = $aid;
                $skip_reasons[ $aid ] = 'excluded';
                continue;
            }

            if ( ! wp_attachment_is_image( $aid ) ) {
                $skipped_used[]      = $aid;
                $skip_reasons[ $aid ] = 'not_image';
                continue;
            }

            $reason = self::get_usage_info( $aid );

            if ( null === $reason ) {
                // Unused — delete (or record for dry-run)
                if ( $dry_run ) {
                    $deleted[] = $aid;
                    continue;
                }
                try {
                    if ( wp_delete_attachment( $aid, true ) ) {
                        $deleted[] = $aid;
                    } else {
                        $failed[] = $aid;
                    }
                } catch ( \Throwable $e ) {
                    $failed[] = $aid;
                    self::log( sprintf( 'delete-orphans-chunk EXCEPTION id=%d: %s', $aid, $e->getMessage() ), 'error' );
                }
            } else {
                $skipped_used[]      = $aid;
                $skip_reasons[ $aid ] = $reason;
            }
        }

        // Check if more candidates exist after the cursor
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
              AND ( p.post_parent = 0 OR parent.ID IS NULL )
            LIMIT 1
        ", 'image/%', $last_seen_id ) );

        self::log( sprintf(
            'delete-orphans-chunk DONE | inspected=%d deleted=%d failed=%d skipped=%d last_id=%d has_more=%s',
            $inspected, count( $deleted ), count( $failed ), count( $skipped_used ),
            $last_seen_id, $has_more ? 'yes' : 'no'
        ) );

        if ( ! empty( $deleted ) ) {
            self::log( 'delete-orphans-chunk DELETED_IDS: ' . implode( ',', $deleted ) );
        }
        if ( ! empty( $failed ) ) {
            self::log( 'delete-orphans-chunk FAILED_IDS: ' . implode( ',', $failed ), 'warning' );
        }
        if ( ! empty( $skip_reasons ) ) {
            foreach ( self::summarize_reasons( $skip_reasons ) as $reason => $ids_for_reason ) {
                self::log( sprintf(
                    'delete-orphans-chunk SKIPPED [%s]: %s',
                    $reason,
                    implode( ',', $ids_for_reason )
                ) );
            }
        }

        $stray = ob_get_clean();
        if ( $stray ) {
            self::log( 'delete-orphans-chunk WARNING stray output: ' . substr( $stray, 0, 500 ), 'warning' );
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

    /**
     * Group skip reasons for more compact logging: reason => [id1,id2,...]
     */
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
