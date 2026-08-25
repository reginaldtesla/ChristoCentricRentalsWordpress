<?php
/**
 * Sync Uploader (Initial Sync)
 *
 * Processes a chunk of images during the initial background sync.
 *
 * @package RentopianSync\FileSync
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Rental_Sync_Uploader {

    /**
     * Process a chunk of images for initial sync.
     *
     * @param int    $start   Cursor (last processed rental_id).
     * @param int    $limit   Chunk size.
     * @param string $sync_id Session id.
     * @return array|void
     */
    public static function process( $start, $limit, $sync_id ) {
        set_time_limit(800);

        $total_count = (int) get_option( 'rental_products_img_count', 0 );

        global $wpdb, $rental_tables;
        $rel_table = $wpdb->prefix . $rental_tables['image_relations'];

        // Already uploaded mapping (avoids re-uploading)
        $uploaded = $wpdb->get_results(
            "SELECT `rental_id`, `id` FROM {$rel_table} WHERE `rental_id` > {$start} ORDER BY `rental_id` ASC LIMIT {$limit}",
            'OBJECT_K'
        );

        if ( '' !== $wpdb->last_error ) {
            self::log( "Could not read the uploaded-image map for start={$start}: {$wpdb->last_error}", 'error' );
            $uploaded = [];
        }

        $images = self::fetch( $start, $limit, (array) $uploaded );

        // An exhausted stream and a request the API could not answer both
        // arrive as "no images". Only the first one means the run is over;
        // completing on the second ends it early and silently, so the chunk
        // is abandoned instead and the driver retries it.
        if ( ! is_array( $images ) ) {
            return;
        }

        $images_count = count( $images );

        self::log( "Fetched {$images_count} images for start={$start}" );

        if ( 0 === $images_count ) {
            update_option( "rental_image_upload_completed_{$sync_id}", true);

            self::log("No images returned for start={$start}, marking completed");
            return;
        }

        $variant_gallery     = [];
        $set_gallery         = [];
        $image_relations_sql = [];
        $succeeded           = [];

        // Progress is accumulated here and written once at the end of the
        // chunk. Incrementing the option per image is a read-modify-write on
        // an autoloaded row: update_option() returns early without touching
        // the object cache whenever the row already holds the new value, so a
        // single lost write freezes the counter for the rest of the run.
        $processed = (int) get_option( "rental_products_img_processed_{$sync_id}", 0 );

        foreach ($images as $image) {

            // ── Cancel check (inside loop for responsive cancellation) ──
            $session = Rental_Sync_Session_Manager::get( $sync_id );
            if (
                $session &&
                isset( $session['status'] ) &&
                (int) $session['status'] === Rental_Sync_Status::STATUS_CANCELED
            ) {

                self::log( "Sync {$sync_id} canceled during processing; stopping." );

                update_option( "rental_image_upload_completed_{$sync_id}", false );
                update_option( "rental_products_img_processed_{$sync_id}", $processed, false );

                return [
                    'succeeded_ids'         => $succeeded,
                    'last_index'            => (int) $start,
                    'processed_count'       => $processed,
                    'total_count'           => $total_count,
                    'stopped_due_to_cancel' => true,
                ];
            }

            // Generation check
            if (
                $session &&
                isset( $session['generation'] ) &&
                (int) $session['generation'] !== Rental_Sync_Session_Manager::generation_current()
            ) {
                break;
            }

            $start = $image->id;

            // Per-image lock 
            $img_lock_key = "rental_image_download_lock_" . (int) $image->id;
            // if there was already a transient then the system is already processing the image so continue to next image
            if ( get_transient($img_lock_key) ) {
                continue;
            }
            set_transient( $img_lock_key, time(), 300 );

            // Past the in-flight lock the image belongs to this chunk and is
            // dealt with either way — downloaded, reused, or unrecoverable.
            // Counting only the ones that end in an attachment stalls the
            // progress figure on exactly the images that went worst.
            $processed++;

            $image_url = isset( $image->url ) ? (string) $image->url : '';
            $attach_id = 0;

            // A relation row is a claim, not proof: the attachment it names
            // may have been deleted since. Trusting it blindly is what
            // leaves a product pointing at a missing image forever, so the
            // claim is verified and pruned when it no longer holds.
            if ( isset( $uploaded[ $image->id ] ) ) {
                $claimed = (int) $uploaded[ $image->id ]->id;

                if ( Rental_Image_Downloader::attachment_is_usable( $claimed ) ) {
                    $attach_id = $claimed;
                    self::log( "Reusing existing attach_id={$attach_id} for REN_ID={$image->id}" );
                } else {
                    Rental_Image_Downloader::forget_relation( (int) $image->id, $claimed );
                    self::log( "Stale relation for REN_ID={$image->id}: attachment {$claimed} is gone, re-resolving", 'warning' );
                }
            }

            // Look for the file elsewhere before spending a download on it.
            if ( $attach_id <= 0 ) {
                $attach_id = Rental_Image_Downloader::resolve_existing_attachment( (int) $image->id, $image_url );
                if ( $attach_id > 0 ) {
                    self::log( "Resolved existing attach_id={$attach_id} for REN_ID={$image->id}" );
                }
            }

            // Nothing local — fetch it.
            if ( $attach_id <= 0 ) {
                if ( '' === $image_url ) {
                    // Marked as already uploaded upstream but absent here
                    // and no URL to recover it from: clear the dangling
                    // references so nothing renders a broken image, and
                    // leave it for the next pass.
                    self::handle_already_uploaded( $image );
                    delete_transient( $img_lock_key );
                    self::log( "Skipped REN_ID={$image->id}: no local attachment and no URL to download from", 'warning' );
                    continue;
                }

                $attach_id = Rental_Image_Downloader::download_and_attach(
                    $image_url,
                    $image->id,
                    $sync_id,
                    $image
                );

                if ( ! $attach_id ) {
                    delete_transient( $img_lock_key );
                    continue;
                }

                $image_relations_sql[] = "({$attach_id}, {$image->id})";
            }

            // Update cursor
            update_option( "rental_products_img_last_id_{$sync_id}", $start );
            delete_transient( $img_lock_key );

            // Attach to all relations
            self::section( "Attach REN ID {$image->id} to its products", static function () use ( $image, $attach_id, &$variant_gallery, &$set_gallery ) {
                Rental_Image_Relation_Attacher::attach_to_all(
                    $image,
                    $attach_id,
                    $variant_gallery,
                    $set_gallery
                );
            } );

            $succeeded[] = (int) $image->id;
        }

        update_option( "rental_products_img_processed_{$sync_id}", $processed, false );

        // Bulk insert image relations
        if ( ! empty( $image_relations_sql ) ) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            $vals = implode( ', ', $image_relations_sql );
            $wpdb->query( "INSERT INTO `{$rel_table}` (`id`, `rental_id`) VALUES {$vals}" );

            if ( '' !== $wpdb->last_error ) {
                self::log( sprintf(
                    'Could not record %d image relation(s) for start=%d: %s',
                    count( $image_relations_sql ),
                    $start,
                    $wpdb->last_error
                ), 'error' );
            }
        }

        // Commit galleries
        self::section( 'Variant gallery commit', static function () use ( $variant_gallery ) {
            Rental_Image_Relation_Attacher::commit_variant_galleries( $variant_gallery );
        } );
        self::section( 'Set gallery commit', static function () use ( $set_gallery ) {
            Rental_Sets_Gallery_Collector::commit( $set_gallery );
        } );

        if ( $wpdb->last_error !== '' ) {
            throw new RentalException(
                "SQL_ERROR: {$wpdb->last_error} (SQL: {$wpdb->last_query})",
                RentalException::TYPE_SYNC_RUNTIME
            );
        }

        // Completion detection (last chunk)
        if ( $images_count < $limit ) {
            update_option( "rental_image_upload_completed_{$sync_id}", true );
            self::log( "Last chunk detected (received {$images_count} < limit {$limit}), marking completed" );
        }

        return [
            'succeeded_ids'     => $succeeded,
            'last_index'        => (int) $start,
            'processed_count'   => $processed,
            'total_count'       => $total_count,
            'likely_last_chunk' => ( $images_count < $limit ),
        ];
    }

    /**
     * One page of the image stream.
     *
     * @param int   $start
     * @param int   $limit
     * @param array $uploaded Relation rows the API uses to skip work.
     * @return array|null NULL when the API did not answer with a list.
     */
    private static function fetch( $start, $limit, array $uploaded ) {
        try {
            $images = rental_curl( 'files/images/stream', get_option( 'rental_api_key' ), true, [
                'start'           => $start,
                'limit'           => $limit,
                'uploaded_images' => json_encode( $uploaded ),
            ] );
        } catch ( Throwable $e ) {
            self::log( "Image stream request failed for start={$start} limit={$limit}: " . $e->getMessage(), 'error' );
            throw $e;
        }

        if ( is_array( $images ) ) {
            return $images;
        }

        self::log( sprintf(
            'Image stream answered with %s instead of a list for start=%d limit=%d — chunk abandoned so it can be retried',
            is_object( $images ) ? 'an object' : gettype( $images ),
            $start,
            $limit
        ), 'error' );

        return null;
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

    /**
     * Clear references to an image that cannot be materialised locally, so
     * nothing renders an empty image slot while waiting for the next pass.
     *
     * The meta row is deleted rather than blanked: an empty `_thumbnail_id`
     * still satisfies `has_post_thumbnail()`'s meta lookup in some themes
     * and leaves junk rows behind.
     */
    private static function handle_already_uploaded( $image ) {
        global $wpdb, $rental_tables;

        if ( empty( $image->products ) ) {
            return;
        }

        $rel_table = $wpdb->prefix . $rental_tables['product_relations'];

        $raw_products = $image->products;
        $product_ids  = null;

        if ( is_array( $raw_products ) ) {
            $product_ids = $raw_products;
        } elseif ( is_string( $raw_products ) ) {
            $product_ids = json_decode( stripslashes( $raw_products ) );
        }

        if ( ! is_array( $product_ids ) || empty( $product_ids ) ) {
            return;
        }

        foreach ( $product_ids as $id ) {
            $id = intval( $id );
            if ( $id <= 0 ) {
                continue;
            }
            $products = $wpdb->get_results( $wpdb->prepare(
                "SELECT `id` FROM {$rel_table} WHERE `rental_id` = %d",
                $id
            ) );
            foreach ( $products as $product ) {
                $pid   = (int) $product->id;
                $thumb = (int) get_post_meta( $pid, '_thumbnail_id', true );

                // The placeholder rental id, or a pointer to an attachment
                // that is gone — either way it cannot render.
                if ( $thumb > 0 && ( $thumb === (int) $image->id || ! Rental_Image_Downloader::attachment_is_usable( $thumb ) ) ) {
                    delete_post_meta( $pid, '_thumbnail_id' );
                }
            }
        }

        self::log( "Already uploaded REN_ID={$image->id}, cleared unrenderable thumbnails" );
    }

    private static function log( $message, $level = 'info' ) {
        Rental_File_Sync_Logger::write( $message, $level, 'sync' );
    }
}
