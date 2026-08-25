<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Direct (non-background) AJAX delete handlers.
 *
 * Uses the same shared orphan detection trait as the REST endpoints.
 * Handles "zombie orphan" attachments — those with post_parent pointing to
 * a deleted / trashed / auto-draft post.
 *
 * @package RentopianSync\FileSyncDelete
 */
class Rental_Direct_Delete_Ajax {

    use Rental_Orphan_Detection;

    private static $log_source = 'rentopian-file-delete';

    public static function init() {
        add_action( 'wp_ajax_rental_delete_orphaned_files',    [ __CLASS__, 'handle_delete_orphaned_files' ] );
        add_action( 'wp_ajax_rental_delete_all_product_files', [ __CLASS__, 'handle_delete_all_product_files' ] );
    }

    /* ------------------------------------------------------------------ */
    /*  Security                                                           */
    /* ------------------------------------------------------------------ */

    private static function verify_request() {
        if ( ! check_ajax_referer( 'rental_file_delete_nonce', '_ajax_nonce', false )
          && ! check_ajax_referer( 'rental_file_delete_nonce', 'nonce', false ) ) {
            wp_send_json_error( [ 'message' => 'Invalid or missing nonce' ], 403 );
        }

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
        }
    }

    /* ------------------------------------------------------------------ */
    /*  Handler: Delete orphaned files (direct)                            */
    /* ------------------------------------------------------------------ */

    public static function handle_delete_orphaned_files() {
        self::verify_request();

        $batch_size  = isset( $_POST['batch_size'] ) ? max( 1, min( 500, (int) $_POST['batch_size'] ) ) : 50;
        $dry_run     = ! empty( $_POST['dry_run'] );
        $exclude_ids = isset( $_POST['exclude_ids'] ) && is_array( $_POST['exclude_ids'] )
            ? array_map( 'intval', $_POST['exclude_ids'] )
            : [];

        self::log( sprintf(
            'Direct orphan delete START | batch_size=%d dry_run=%s',
            $batch_size, $dry_run ? 'yes' : 'no'
        ) );

        $result = self::delete_orphaned_files( $batch_size, $exclude_ids, $dry_run );

        self::log( sprintf(
            'Direct orphan delete DONE | inspected=%d deleted=%d failed=%d skipped=%d',
            $result['inspected'],
            count( $result['deleted'] ),
            count( $result['failed'] ),
            count( $result['skipped_used'] )
        ) );

        wp_send_json( [
            'data'  => $result,
            'count' => count( $result['deleted'] ),
        ], 200 );
    }

    /* ------------------------------------------------------------------ */
    /*  Handler: Delete all product files (direct)                         */
    /* ------------------------------------------------------------------ */

    public static function handle_delete_all_product_files() {
        self::verify_request();

        $batch_size  = isset( $_POST['batch_size'] ) ? max( 1, min( 500, (int) $_POST['batch_size'] ) ) : 50;
        $dry_run     = ! empty( $_POST['dry_run'] );
        $exclude_ids = isset( $_POST['exclude_ids'] ) && is_array( $_POST['exclude_ids'] )
            ? array_map( 'intval', $_POST['exclude_ids'] )
            : [];

        self::log( sprintf(
            'Direct product images delete START | batch_size=%d dry_run=%s',
            $batch_size, $dry_run ? 'yes' : 'no'
        ) );

        $result = self::delete_product_images( $batch_size, $exclude_ids, $dry_run );

        self::log( sprintf(
            'Direct product images delete DONE | deleted=%d failed=%d skipped=%d',
            count( $result['deleted'] ),
            count( $result['failed'] ),
            count( $result['skipped_protected'] )
        ) );

        wp_send_json( [
            'data'  => $result,
            'count' => count( $result['deleted'] ),
        ], 200 );
    }

    /* ------------------------------------------------------------------ */
    /*  Core: Delete orphaned files                                        */
    /* ------------------------------------------------------------------ */

    /**
     * Delete truly orphaned attachments:
     *   - post_parent = 0, OR
     *   - post_parent points to a deleted / trashed / auto-draft post
     *
     * Only deletes when is_completely_unused() confirms no references remain.
     */
    public static function delete_orphaned_files( $batch_size = 50, $exclude_ids = [], $dry_run = true ) {
        global $wpdb;

        $deleted      = [];
        $failed       = [];
        $skipped_used = [];
        $skip_reasons = [];
        $inspected    = 0;
        $errors       = [];

        $exclude_ids = array_map( 'intval', (array) $exclude_ids );

        $pp = $wpdb->posts;

        $last_id = 0;
        while ( true ) {
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
            $ids = $wpdb->get_col( $wpdb->prepare( $sql, 'image/%', $last_id, $batch_size ) );

            if ( empty( $ids ) ) {
                break;
            }

            foreach ( $ids as $aid ) {
                $aid     = (int) $aid;
                $last_id = max( $last_id, $aid );
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

                $reason = self::get_usage_info( $aid );
                if ( null !== $reason ) {
                    $skipped_used[]       = $aid;
                    $skip_reasons[ $aid ] = $reason;
                    continue;
                }

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
                } catch ( \Exception $e ) {
                    $errors[ $aid ] = $e->getMessage();
                    $failed[]       = $aid;
                }
            }
        }

        return [
            'deleted'      => $deleted,
            'failed'       => $failed,
            'skipped_used' => $skipped_used,
            'skip_reasons' => $skip_reasons,
            'inspected'    => $inspected,
            'errors'       => $errors,
            'dry_run'      => $dry_run,
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  Core: Delete product images                                        */
    /* ------------------------------------------------------------------ */

    /**
     * Delete all image attachments that are product-related (live products,
     * variations, product galleries, term thumbnails) AND every image
     * attachment with post_parent=0 or a dead parent that is not used outside
     * products.
     *
     */
    public static function delete_product_images( $batch_size = 50, $exclude_ids = [], $dry_run = false ) {
        global $wpdb;

        $deleted           = [];
        $failed            = [];
        $skipped_protected = [];
        $skip_reasons      = [];

        $exclude_ids = array_map( 'intval', (array) $exclude_ids );

        $pp = $wpdb->posts;
        $pm = $wpdb->postmeta;
        $tm = isset( $wpdb->termmeta ) ? $wpdb->termmeta : $wpdb->prefix . 'termmeta';

        // Placeholder name patterns — never delete these regardless of refs
        $placeholder_patterns = [
            'placeholder', 'no-image', 'no_image', 'default', 'dummy', 'woocommerce-placeholder',
        ];

        $last_id = 0;
        while ( true ) {
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
            $ids = $wpdb->get_col( $wpdb->prepare( $sql, 'image/%', $last_id, $batch_size ) );

            if ( empty( $ids ) ) {
                break;
            }

            foreach ( $ids as $aid ) {
                $aid     = (int) $aid;
                $last_id = max( $last_id, $aid );

                if ( in_array( $aid, $exclude_ids, true ) ) {
                    $skipped_protected[]  = $aid;
                    $skip_reasons[ $aid ] = 'excluded';
                    continue;
                }

                if ( ! wp_attachment_is_image( $aid ) ) {
                    $skipped_protected[]  = $aid;
                    $skip_reasons[ $aid ] = 'not_image';
                    continue;
                }

                // Respect placeholder filenames
                $file = get_attached_file( $aid );
                if ( $file ) {
                    $basename = strtolower( basename( $file ) );
                    foreach ( $placeholder_patterns as $pat ) {
                        if ( false !== strpos( $basename, $pat ) ) {
                            $skipped_protected[]  = $aid;
                            $skip_reasons[ $aid ] = 'placeholder';
                            continue 2;
                        }
                    }
                }

                // Decide: product-related vs. generic orphan
                if ( self::is_product_related( $aid ) ) {
                    $outside = self::get_outside_product_reason( $aid );
                    if ( null !== $outside ) {
                        $skipped_protected[]  = $aid;
                        $skip_reasons[ $aid ] = 'product+' . $outside;
                        continue;
                    }
                } else {
                    $reason = self::get_usage_info( $aid );
                    if ( null !== $reason ) {
                        $skipped_protected[]  = $aid;
                        $skip_reasons[ $aid ] = $reason;
                        continue;
                    }
                }

                // Safe to delete
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
                    self::log( sprintf( 'delete_product_images EXCEPTION id=%d: %s', $aid, $e->getMessage() ), 'error' );
                }
            }
        }

        return [
            'deleted'           => array_values( array_unique( $deleted ) ),
            'failed'            => array_values( array_unique( $failed ) ),
            'skipped_protected' => array_values( array_unique( $skipped_protected ) ),
            'skip_reasons'      => $skip_reasons,
            'dry_run'           => $dry_run,
        ];
    }

    /* ------------------------------------------------------------------ */

    private static function log( $message, $level = 'info' ) {
        Rental_Delete_Logger::log( $message, $level );
    }
}
