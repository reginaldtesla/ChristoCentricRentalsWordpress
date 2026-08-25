<?php
/**
 * Chunk Worker
 *
 * Orchestrates a single chunk of image processing. Called by the REST
 * controller when the Laravel server dispatches a "process-chunk" request.
 *
 * @package RentopianSync\FileSync
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Rental_Chunk_Worker {

    /**
     * Adaptive chunk size based on recent performance.
     *
     * @param string $sync_id
     * @param int    $incoming_limit Caller's requested limit.
     * @return int
     */
    public static function adapt_chunk_size( $sync_id, $incoming_limit ) {
        $default   = (int) get_option( 'rental_default_chunk_size', 8 );
        $prev      = Rental_Sync_Log_Repository::get_last_elapsed( $sync_id );
        $had_error = Rental_Sync_Log_Repository::had_recent_error( $sync_id, 900 );

        $effective = $incoming_limit ?: $default;

        if ( $had_error ) {
            $effective = 1;
        } elseif ( $prev !== null ) {
            if ( $prev > 35 ) {
                $effective = 1;
            } elseif ( $prev > 25 ) {
                $effective = 4;
            } elseif ( $prev < 15 ) {
                $effective = $default;
            }
        }

        // Hard clamp
        $effective = max(1, min(25, $effective));

        // TBD: How to use this data effectively or is it useless
        // update_option( "rental_effective_chunk_size_{$sync_id}", $effective, false );

        return $effective;
    }

    /**
     * Process a chunk of images (initial sync or resync).
     *
     * @param int    $start     Cursor position.
     * @param int    $limit     Chunk size.
     * @param string $sync_id   Session id.
     * @param bool   $is_resync Whether this is a resync.
     * @return array { last_index, failed_ids, failed_count, completed }
     */
    public static function process( $start, $limit, $sync_id, $is_resync = false ) {
        // Ensure sufficient execution time for the entire chunk
        // processing + response pipeline.
        if ( function_exists( 'set_time_limit' ) ) {
            @set_time_limit( 800 );
        }

        $t0 = microtime( true );

        // Everything logged from here down belongs to this run's file.
        Rental_File_Sync_Logger::bind_run( $sync_id );

        // ── Session exists? ──────────────────────────────────
        $session = Rental_Sync_Session_Manager::get( $sync_id );
        if ( !$session ) {
            self::log( "Chunk rejected: no session found for sync_id={$sync_id}" );
            return self::empty_result( $start );
        }

        // ── Canceled? ────────────────────────────────────────
        if ( isset($session['status']) && (int) $session['status'] === Rental_Sync_Status::STATUS_CANCELED ) {
            self::log( "Chunk rejected: session {$sync_id} is canceled" );
            return self::empty_result( $start, true );
        }

        // ── Globally stopped? ────────────────────────────────
        // "Stop all" raises this switch. Without honouring it here the
        // switch only refuses NEW runs, and everything the queue already
        // holds keeps arriving and keeps being processed.
        if ( Rental_Sync_Session_Manager::is_globally_disabled() ) {
            self::log( "Chunk rejected: file synchronization is stopped site-wide ({$sync_id})", 'warning' );
            return self::empty_result( $start, true );
        }

        // Already completed? ───────────────────────────────
        // Once a sync is genuinely complete, subsequent chunk
        // requests from Laravel (due to lost responses / stale jobs)
        // should return immediately so Laravel stops dispatching.
        if ( isset($session['status']) && (int) $session['status'] === Rental_Sync_Status::STATUS_COMPLETED ) {
            $stored_last = intval( get_option( "rental_products_img_last_id_{$sync_id}", $start ) );
            self::log( "Chunk short-circuit: session {$sync_id} already completed (last_index={$stored_last})" );
            return [
                'last_index'         => $stored_last,
                'failed_ids'         => [],
                'failed_count'       => 0,
                'total_failed_count' => count( $session['failed_ids'] ?? [] ),
                'completed'          => true,
            ];
        }

        // ── Generation match? ────────────────────────────────
        $cur_gen  = Rental_Sync_Session_Manager::generation_current();
        $sess_gen = isset($session['generation']) ? (int) $session['generation'] : -1;
        if ( $sess_gen !== $cur_gen ) {
            self::log( "Chunk rejected: generation mismatch for {$sync_id} (session={$sess_gen}, current={$cur_gen})" );
            return self::empty_result( $start );
        }

        // ── Advance cursor if stored progress is ahead ───────
        // When the WP response is lost (PHP timeout, proxy
        // timeout, network issue), Laravel retries with the SAME
        // start_index. Without cursor advancement, the resync uploader
        // sees all images in seen_ids, triggers premature completion,
        // deletes seen_ids, and creates an infinite 1-image loop.
        // By advancing to stored progress, the uploader fetches NEW
        // images instead of re-processing old ones.
        $stored_last = intval( get_option( "rental_products_img_last_id_{$sync_id}", 0 ) );
        if ( $stored_last > 0 && $start < $stored_last ) {
            self::log( "Cursor advanced from {$start} to {$stored_last} for {$sync_id}" );
            $start = $stored_last;
        }

        // ── Acquire lock ─────────────────────────────────────
        if ( !Rental_Sync_Session_Manager::acquire_lock($sync_id) ) {
            self::log( "Chunk deferred: lock not acquired for {$sync_id}" );
            return [
                'last_index'   => $start,
                'failed_ids'   => $session['failed_ids'] ?? [],
                'failed_count' => count($session['failed_ids'] ?? []),
                'completed'    => false,
            ];
        }

        try {
            
            // calculate effective limit based on previous processes elapsed time and errors 
            $limit = intval($limit);
            $effective_limit = self::adapt_chunk_size( $sync_id, $limit); 
            // $effective_limit = 5;

            // ── Snapshot failed IDs BEFORE processing ────────
            // BUG FIX: We need to know which IDs were already failed before this chunk,
            // so we can compute the DIFF after processing. Without this, the response
            // includes ALL accumulated failures across all chunks, causing Laravel to
            // dispatch exponentially growing retry jobs on every chunk.
            $failed_ids_before = Rental_Failed_Image_Repository::get_ids_by_sync( $sync_id );

            // ── Resolve mode from session (belt-and-suspenders) ──
            // The REST controller already resolves mode from the session,
            // but as a safety net the chunk worker also checks. This
            // ensures re-sync always uses the correct uploader even if
            // $is_resync was incorrectly passed as false.
            if ( ! $is_resync && isset( $session['mode'] ) && (int) $session['mode'] === Rental_Sync_Status::MODE_RESYNC ) {
                $is_resync = true;
            }

            // ── Dispatch to uploader ─────────────────────────
            // Cleared first so the flag read back below can only have been
            // set by this chunk. That — not a counter comparison — is what
            // tells a genuine end-of-stream apart from a leftover flag.
            update_option( "rental_image_upload_completed_{$sync_id}", false, false );

            if ($is_resync) {
                Rental_Resync_Uploader::process( intval($start), $effective_limit, $sync_id );
            } else {
                Rental_Sync_Uploader::process( intval($start), $effective_limit, $sync_id );
            }

            // ── Read results from options ────────────────────
            $new_last_index   = intval( get_option( "rental_products_img_last_id_{$sync_id}", intval( $start ) ) );
            $all_failed_ids   = Rental_Failed_Image_Repository::get_ids_by_sync( $sync_id );
            $failed_count     = count( $all_failed_ids );

            // BUG FIX: Only report NEW failures from THIS chunk to Laravel.
            // Laravel uses failed_ids to dispatch RetryFailedFilesJob. If we return
            // ALL accumulated failures, Laravel dispatches retries for images that
            // already have retry jobs queued — causing an exponential retry storm.
            $chunk_failed_ids = array_values( array_diff( $all_failed_ids, $failed_ids_before ) );

            // Keep $failed_ids as the full list for session/log, but use $chunk_failed_ids in the response
            $failed_ids       = $all_failed_ids;
            $img_count       = intval( get_option( 'rental_products_img_count', 0 ) );
            $completed_flag  = (bool) get_option( "rental_image_upload_completed_{$sync_id}", false );
            $processed_count = intval( get_option( "rental_products_img_processed_{$sync_id}", 0 ) );

            // force completion if processed far exceeds total
            // This prevents infinite loops in resync mode where the same images
            // are re-processed without the uploader ever setting completed.
            if (
                ! $completed_flag &&
                $img_count > 0 &&
                $processed_count >= $img_count
            ) {
                $completed_flag = true;
                update_option( "rental_image_upload_completed_{$sync_id}", true, false );

                Rental_Sync_Log_Repository::write(
                    $sync_id,
                    'info',
                    sprintf(
                        'Chunk worker forced completion: processed=%d >= total=%d (mode=%s)',
                        $processed_count,
                        $img_count,
                        $is_resync ? 'resync' : 'sync'
                    ),
                    [ 'forced_completion' => true ],
                    $new_last_index,
                    $processed_count,
                    $img_count,
                    microtime( true ) - $t0,
                    Rental_Sync_Status::STATUS_COMPLETED,
                    $is_resync ? Rental_Sync_Status::MODE_RESYNC : Rental_Sync_Status::MODE_SYNC,
                    Rental_Sync_Log_Repository::STAGE_END
                );
            }

            // ── Update session ───────────────────────────────
            Rental_Sync_Session_Manager::update( $sync_id, [
                'last_index'      => $new_last_index,
                'failed_ids'      => $failed_ids,
                'failed_count'    => $failed_count,
                'status'          => $completed_flag ? Rental_Sync_Status::STATUS_COMPLETED : Rental_Sync_Status::STATUS_PROCESSING,
                'processed_count' => $processed_count,
                'total_count'     => $img_count,
                // Heartbeat: the one thing that proves the driver is still
                // calling, and all the watchdog has to go on.
                'last_chunk_at'   => time(),
            ] );

            // ── Logging ──────────────────────────────────────
            $elapsed    = microtime( true ) - $t0;
            $mode_label = $is_resync ? 'resync' : 'sync';
            $mode_int   = $is_resync ? Rental_Sync_Status::MODE_RESYNC : Rental_Sync_Status::MODE_SYNC;

            Rental_Sync_Log_Repository::write(
                $sync_id,
                'info',
                sprintf(
                    'Processed chunk start=%d limit=%d last_index=%d processed=%d total=%d failed=%d',
                    intval( $start ),
                    intval( $effective_limit ),
                    intval( $new_last_index ),
                    intval( $processed_count ),
                    intval( $img_count ),
                    intval( $failed_count )
                ),
                [
                    'failed_count'       => $failed_count,
                    'failed_ids'         => $failed_ids,
                    'chunk_failed_ids'   => $chunk_failed_ids,
                    'chunk_failed_count' => count( $chunk_failed_ids ),
                    'elapsed'            => $elapsed,
                    'effective_limit'    => $effective_limit,
                    'incoming_limit'     => $limit,
                    'is_resync'          => $is_resync ? 1 : 0,
                ],
                $new_last_index,
                $processed_count,
                $img_count,
                $elapsed,
                Rental_Sync_Status::STATUS_PROCESSING,
                $mode_int,
                Rental_Sync_Log_Repository::STAGE_PROGRESS
            );

            // ── Release lock ─────────────────────────────────
            Rental_Sync_Session_Manager::release_lock( $sync_id );

            // ── Completion ───────────────────────────────────
            if ( $completed_flag ) {
                update_option( 'rental_synchronize_status', 1 );
                update_option( 'rental_api_key_is_valid', 1 );

                // Legacy display value, still shown by the old sync screen.
                $duration = Rental_Timer::stop_persistent();
                update_option( 'rental_show_sync_duration', $duration !== false ? $duration : '' );

                // How long THIS run took, measured from its own start. The
                // persistent timer above is a site-wide option that only the
                // manual buttons ever start, so on a chained run it reports
                // the age of whatever value was left in it — and it returns
                // a formatted string, which floatval() renders as 0 anyway.
                $elapsed = Rental_Sync_Time::age( (array) $session );

                Rental_Sync_Log_Repository::write(
                    $sync_id,
                    'info',
                    sprintf( 'File sync completed (mode=%s) in %ds', $mode_label, $elapsed ),
                    [
                        'failed_count' => $failed_count,
                        'failed_ids'   => $failed_ids,
                    ],
                    $new_last_index,
                    $processed_count,
                    $img_count,
                    (float) $elapsed,
                    Rental_Sync_Status::STATUS_COMPLETED,
                    $mode_int,
                    Rental_Sync_Log_Repository::STAGE_END
                );

                do_action( 'rental_file_sync_completed', $sync_id, (float) $elapsed );
            }

            return [
                'last_index'         => $new_last_index,
                'failed_ids'         => $chunk_failed_ids, // BUG FIX: only NEW failures from this chunk
                'failed_count'       => count( $chunk_failed_ids ),
                'total_failed_count' => $failed_count,     // total accumulated (for UI/logging)
                'completed'          => $completed_flag,
            ];

        } catch ( Exception $e ) {
            // ── Error handling ────────────────────────────────
            Rental_Sync_Session_Manager::update( $sync_id, [
                'last_error' => $e->getMessage(),
            ] );
            Rental_Sync_Session_Manager::release_lock( $sync_id );

            $elapsed = microtime( true ) - $t0;

            Rental_Sync_Log_Repository::write(
                $sync_id,
                'error',
                'Exception in chunk worker: ' . $e->getMessage(),
                [ 'trace' => $e->getTraceAsString() ],
                intval( $start ),
                intval( get_option( "rental_products_img_processed_{$sync_id}", 0 ) ),
                intval( get_option( 'rental_products_img_count', 0 ) ),
                $elapsed,
                Rental_Sync_Status::STATUS_PROCESSING
            );

            return self::empty_result( $start );
        }
    }

    /**
     * Return an empty/default result array.
     *
     * @param int  $start
     * @param bool $completed
     * @return array
     */
    private static function empty_result( $start, $completed = false ) {
        return [
            'last_index'   => $start,
            'failed_ids'   => [],
            'failed_count' => 0,
            'completed'    => $completed,
        ];
    }

    private static function log( $message, $level = 'info' ) {
        Rental_File_Sync_Logger::write( $message, $level, 'chunk' );
    }
}
