<?php
/**
 * Retry Processor
 *
 * Re-processes an explicit list of failed rental image IDs.
 *
 * @package RentopianSync\FileSync
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Rental_Retry_Processor {

    /**
     * Retry an explicit list of failed image IDs.
     *
     * @param string $sync_id
     * @param int[]  $image_ids
     * @return array { succeeded_ids, failed_ids, processed_count }
     */
    public static function process( $sync_id, array $image_ids = [] ) {
        $image_ids = array_map( 'intval', array_values( $image_ids ) );

        if ( empty( $image_ids ) ) {
            // BUG FIX: Use get_retryable_ids instead of get_ids_by_sync
            // to skip images that have exceeded max retry attempts (fail_count > 3).
            // This prevents infinite retry of permanently-failing images
            // (e.g. "Forbidden" from bad signed URLs, missing files, etc.)
            $image_ids = Rental_Failed_Image_Repository::get_retryable_ids( $sync_id, 3 );
        } else {
            // Even when explicit IDs are provided, filter out over-retried ones
            $retryable = Rental_Failed_Image_Repository::get_retryable_ids( $sync_id, 3 );
            $image_ids = array_values( array_intersect( $image_ids, $retryable ) );
        }

        if ( empty( $image_ids ) ) {
            return [ 'succeeded_ids' => [], 'failed_ids' => [], 'processed_count' => 0 ];
        }

        set_time_limit( 800 );

        global $wpdb, $rental_tables;
        $rel_table = $wpdb->prefix . $rental_tables['image_relations'];

        // Acquire lock
        if ( ! Rental_Sync_Session_Manager::acquire_lock( $sync_id ) ) {
            return [ 'succeeded_ids' => [], 'failed_ids' => $image_ids, 'processed_count' => 0 ];
        }

        // Map of requested ids for filtering of the API response.
        $requested_ids_map = array_flip( array_map( 'intval', $image_ids ) );

        // Fetch metadata from API
        try {

            $images = rental_curl( 'files/images/stream', get_option( 'rental_api_key' ), true, [
                'images' => json_encode( array_values( array_map( 'intval', $image_ids ) ) ),
                'limit'  => max( 20, count( $image_ids ) ),
            ] );
            
        } catch ( Exception $e ) {
            Rental_Sync_Session_Manager::release_lock( $sync_id );
            self::log( 'Failed to fetch images for retry: ' . $e->getMessage(), 'error' );
            return [ 'succeeded_ids' => [], 'failed_ids' => $image_ids, 'processed_count' => 0 ];
        }

        if ( ! is_array( $images ) || empty( $images ) ) {
            Rental_Sync_Session_Manager::release_lock( $sync_id );
            return [ 'succeeded_ids' => [], 'failed_ids' => $image_ids, 'processed_count' => 0 ];
        }

        // Process each image
        $succeeded          = [];
        $failed             = [];
        $variant_gallery    = [];
        $set_gallery        = [];
        $processed_count    = (int) get_option( "rental_products_img_processed_{$sync_id}", 0 );

        foreach ( $images as $image ) {
            if ( ! isset( $image->id ) ) {
                continue;
            }

            $rental_id = (int) $image->id;
            if ( $rental_id <= 0 ) {
                continue;
            }

            // Only process images that were actually requested.
            if ( ! isset( $requested_ids_map[ $rental_id ] ) ) {
                self::log( "Skipping unrequested image id={$rental_id} in retry response" );
                continue;
            }

            $image_url = isset( $image->url ) ? (string) $image->url : '';

            // Try to reuse an existing WP attachment for this rental_id
            // before re-downloading.
            $attach_id = Rental_Image_Downloader::resolve_existing_attachment(
                $rental_id,
                $image_url
            );

            if ( $attach_id <= 0 ) {
                // No reusable attachment — download a fresh copy.
                $attach_id = Rental_Image_Downloader::download_and_attach(
                    $image_url,
                    $rental_id,
                    $sync_id,
                    $image
                );
            }

            if ( ! $attach_id ) {
                $failed[] = $rental_id;
                continue;
            }

            // Persist the relation row immediately
            $wpdb->replace(
                $rel_table,
                [
                    'rental_id' => $rental_id,
                    'id'        => (int) $attach_id,
                ],
                [ '%d', '%d' ]
            );

            update_option( "rental_products_img_last_id_{$sync_id}", $rental_id );

            // Attach to all relations
            Rental_Image_Relation_Attacher::attach_to_all(
                $image,
                $attach_id,
                $variant_gallery,
                $set_gallery
            );

            $succeeded[] = $rental_id;
            $processed_count++;
            update_option( "rental_products_img_processed_{$sync_id}", $processed_count );
        }

        // Commit galleries
        Rental_Image_Relation_Attacher::commit_variant_galleries( $variant_gallery );
        Rental_Sets_Gallery_Collector::commit( $set_gallery );

        // Mark succeeded as resolved
        if ( ! empty( $succeeded ) ) {
            Rental_Failed_Image_Repository::mark_resolved( $succeeded );
        }

        $final_failed = Rental_Failed_Image_Repository::get_ids_by_sync( $sync_id );

        // Logging
        Rental_Sync_Log_Repository::write(
            $sync_id,
            'notice',
            sprintf(
                'Retry processed chunk: requested=%d succeeded=%d failed=%d processed_total=%d',
                count( $image_ids ),
                count( $succeeded ),
                count( $final_failed ),
                $processed_count
            ),
            [
                'requested_ids'      => $image_ids,
                'succeeded_ids'      => $succeeded,
                'failed_ids_snapshot' => $final_failed,
            ],
            intval( get_option( "rental_products_img_last_id_{$sync_id}", 0 ) ),
            $processed_count,
            intval( get_option( 'rental_products_img_count', 0 ) ),
            0.0,
            Rental_Sync_Status::STATUS_PROCESSING
        );

        // Release lock
        Rental_Sync_Session_Manager::release_lock( $sync_id );

        return [
            'succeeded_ids'  => $succeeded,
            'failed_ids'     => $final_failed,
            'processed_count' => count( $succeeded ),
        ];
    }

    private static function log( $message, $level = 'info' ) {
        Rental_File_Sync_Logger::write( $message, $level, 'retry' );
    }
}
