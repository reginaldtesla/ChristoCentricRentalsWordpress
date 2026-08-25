<?php
/**
 * Resync Uploader
 *
 * Processes a chunk of images during a re-sync (tries to re-use existing
 * attachments before downloading).
 *
 * @package RentopianSync\FileSync
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Rental_Resync_Uploader {

    /**
     * Process a chunk of images for resync.
     *
     * @param int    $start
     * @param int    $limit
     * @param string $sync_id
     */
    public static function process( $start, $limit, $sync_id ) {
        global $wpdb, $rental_tables;

        $api_key = get_option( 'rental_api_key' );
        if ( ! $api_key || empty( $rental_tables['image_relations'] ) ) {
            return;
        }

        $rel_table   = $wpdb->prefix . $rental_tables['image_relations'];
        $start       = (int) $start;
        $limit       = (int) $limit;
        $total_count = (int) get_option( 'rental_products_img_count', 0 );

        // Track which rental_ids this sync has already seen
        // Prevents the processed counter from inflating when the same
        // images are returned by the API on consecutive chunks.
        $seen_key  = "rental_resync_seen_ids_{$sync_id}";
        $seen_ids  = get_option( $seen_key, [] );
        if ( ! is_array( $seen_ids ) ) {
            $seen_ids = [];
        }

        // Fetch from remote
        try {
            $images = rental_curl( 'files/images/stream', $api_key, true, [
                'start' => $start,
                'limit' => $limit,
            ] );
        } catch ( Throwable $e ) {
            self::log( "Image stream request failed for start={$start} limit={$limit}: " . $e->getMessage(), 'error' );
            throw $e;
        }

        // An exhausted stream and a request the API could not answer both
        // arrive as "no images". Completing on the second ends the run early
        // and silently, so the chunk is abandoned and the driver retries it.
        if ( ! is_array( $images ) ) {
            self::log( sprintf(
                'Image stream answered with %s instead of a list for start=%d limit=%d — chunk abandoned so it can be retried',
                is_object( $images ) ? 'an object' : gettype( $images ),
                $start,
                $limit
            ), 'error' );
            return;
        }

        self::log( 'Fetched ' . count( $images ) . " images for start={$start}" );

        if ( empty( $images ) ) {
            update_option( "rental_image_upload_completed_{$sync_id}", true, false );
            self::log( "No images returned for start={$start}, marking completed" );
            return;
        }

        $processed_count   = (int) get_option( "rental_products_img_processed_{$sync_id}", 0 );
        $last_id           = (int) get_option( "rental_products_img_last_id_{$sync_id}", 0 );
        $variant_gallery   = [];
        $set_gallery       = [];
        $chunk_new_count   = 0; // track genuinely new *successful* items
        $chunk_seen_new    = 0; // track new (unseen) items encountered, regardless of success

        foreach ( $images as $image ) {
            // Normalise
            $rental_id = 0;
            $image_url = '';

            if ( is_object( $image ) ) {
                $rental_id = isset( $image->id )  ? (int) $image->id       : 0;
                $image_url = isset( $image->url ) ? (string) $image->url   : '';
            } elseif ( is_array( $image ) ) {
                $rental_id = isset( $image['id'] )  ? (int) $image['id']   : 0;
                $image_url = isset( $image['url'] ) ? (string) $image['url'] : '';
            }

            if ( $rental_id <= 0 || ! $image_url ) {
                continue;
            }

            // Skip if already processed in this sync session
            if ( isset( $seen_ids[ $rental_id ] ) ) {
                continue;
            }

            // Per-image lock
            $lock_key = "rental_image_download_lock_{$rental_id}";
            if ( get_transient( $lock_key ) ) {
                continue;
            }
            set_transient( $lock_key, time(), 300 );

            // This is a genuinely new (unseen) image in this sync session
            $chunk_seen_new++;

            // Try to re-use existing attachment
            $attachment_id = Rental_Image_Downloader::resolve_existing_attachment( $rental_id, $image_url );

            // Download if not found
            if ( $attachment_id <= 0 ) {
                $attachment_id = Rental_Image_Downloader::download_and_attach(
                    $image_url,
                    $rental_id,
                    $sync_id,
                    is_object( $image ) ? $image : (object) $image
                );
            }

            if ( $attachment_id > 0 ) {
                // Ensure relation row
                $wpdb->replace( $rel_table, [
                    'rental_id' => (int) $rental_id,
                    'id'        => (int) $attachment_id,
                ], [ '%d', '%d' ] );

                if ( '' !== $wpdb->last_error ) {
                    self::log( "Could not record the image relation for REN ID {$rental_id}: {$wpdb->last_error}", 'error' );
                }

                // Attach to all relations
                self::section( "Attach REN ID {$rental_id} to its products", static function () use ( $image, $attachment_id, &$variant_gallery, &$set_gallery ) {
                    Rental_Image_Relation_Attacher::attach_to_all(
                        $image,
                        $attachment_id,
                        $variant_gallery,
                        $set_gallery
                    );
                } );

                // Only count successfully processed items.
                $processed_count++;
                $chunk_new_count++;
            }

            // Release lock
            delete_transient( $lock_key );

            // Always advance cursor and mark as seen to prevent
            // infinite re-fetch loops on the same images.
            $last_id = max( $last_id, $rental_id );
            $seen_ids[ $rental_id ] = true;
        }

        // Commit galleries
        self::section( 'Variant gallery commit', static function () use ( $variant_gallery ) {
            Rental_Image_Relation_Attacher::commit_variant_galleries( $variant_gallery );
        } );
        self::section( 'Set gallery commit', static function () use ( $set_gallery ) {
            Rental_Sets_Gallery_Collector::commit( $set_gallery );
        } );

        update_option( "rental_products_img_last_id_{$sync_id}", $last_id, false );
        update_option( "rental_products_img_processed_{$sync_id}", $processed_count, false );
        update_option( $seen_key, $seen_ids, false );

        // ── Completion detection ────────────────────────────────────
        // Mark completed if:
        //  1) All items have been processed (processed_count >= total_count), OR
        //  2) The API returned fewer items than requested (end of cursor)
        $is_complete = false;

        if ( $total_count > 0 && $processed_count >= $total_count ) {
            $is_complete = true;
        }

        if ( count( $images ) < $limit ) {
            // API returned fewer items than requested — end of dataset
            $is_complete = true;
        }

        if ( $is_complete ) {
            update_option( "rental_image_upload_completed_{$sync_id}", true, false );
            // Clean up the seen-ids tracking option
            delete_option( $seen_key );
        }
    }

    /**
     * Run one step of the chunk, naming it when it fails. A chunk that dies
     * without saying which step died is the hardest kind of run to diagnose.
     *
     * @param string   $name
     * @param callable $step
     */
    private static function section( $name, callable $step ) {
        try {
            $step();
        } catch ( Throwable $e ) {
            self::log( "{$name} failed: " . $e->getMessage(), 'error' );
            throw $e;
        }
    }

    private static function log( $message, $level = 'info' ) {
        Rental_File_Sync_Logger::write( $message, $level, 'resync' );
    }
}
