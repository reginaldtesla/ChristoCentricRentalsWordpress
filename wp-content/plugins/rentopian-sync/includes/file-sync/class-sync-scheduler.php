<?php
/**
 * Sync Scheduler
 *
 * Handles starting, canceling, and resuming sync sessions.
 * This is the "orchestrator" that talks to the remote Laravel server.
 *
 * @package RentopianSync\FileSync
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Rental_Sync_Scheduler {

    /**
     * Schedule a new file sync (initial or resync).
     *
     * Creates a new session, cancels previous sessions, notifies the
     * Laravel server to start dispatching chunks.
     *
     * @param int $mode Rental_Sync_Status::MODE_SYNC or MODE_RESYNC
     * @return array { success, message, sync_id, ... }
     */
    public static function schedule( $mode = 1 ) {

        // If globally disabled, clear the flag so the new sync can proceed
        if ( Rental_Sync_Session_Manager::is_globally_disabled() ) {
            update_option( 'rental_sync_all_canceled', 0 );
            update_option( 'rental_sync_canceled_reason', '' );
            Rental_Sync_Session_Manager::generation_increment();
        }

        $mode = ( $mode === Rental_Sync_Status::MODE_RESYNC )
            ? Rental_Sync_Status::MODE_RESYNC
            : Rental_Sync_Status::MODE_SYNC;

        $api_key  = get_option( 'rental_api_key' );
        $sync_id  = wp_generate_uuid4();
        $wp_token = wp_generate_password( 32, false );

        update_option( 'rental_current_sync_id', $sync_id );
        update_option( 'rental_current_wp_token', $wp_token );

        // Everything logged from here down belongs to this run's file.
        Rental_File_Sync_Logger::bind_run( $sync_id );

        // The total belongs to this run. Keeping whatever an earlier run left
        // behind makes the progress bar a percentage of a catalog that no
        // longer exists; a count that cannot be fetched is worth less than
        // the previous one, but not worth failing the run over.
        $img_count = intval( get_option( 'rental_products_img_count', 0 ) );
        try {
            $fresh = rental_curl( 'files/images/count', $api_key );
            if ( is_numeric( $fresh ) && (int) $fresh > 0 ) {
                $img_count = (int) $fresh;
            } else {
                Rental_File_Sync_Logger::write(
                    'Image count endpoint did not answer with a number, keeping the previous total',
                    'warning',
                    'sync'
                );
            }
        } catch ( Throwable $e ) {
            Rental_File_Sync_Logger::write(
                'Image count unavailable, keeping the previous total: ' . $e->getMessage(),
                'warning',
                'sync'
            );
        }
        update_option( 'rental_products_img_count', $img_count );

        // Cancel previous sessions
        Rental_Sync_Session_Manager::cancel_previous_sessions( $sync_id );

        // Create new session
        Rental_Sync_Session_Manager::update( $sync_id, [
            'wp_token'        => $wp_token,
            'last_index'      => 0,
            'failed_ids'      => [],
            'failed_count'    => 0,
            'status'          => Rental_Sync_Status::STATUS_CREATED,
            'started_at'      => current_time( 'mysql' ),
            // Real Unix time as well, so the run's duration is measured
            // rather than derived from a local-time string.
            'started_epoch'   => time(),
            'processed_count' => 0,
            'total_count'     => $img_count,
            'generation'      => Rental_Sync_Session_Manager::generation_current(),
            'mode'            => $mode,
        ] );

        // Log start
        Rental_Sync_Log_Repository::write(
            $sync_id, 'info', 'Sync started',
            [ 'started_at' => current_time( 'mysql' ), 'total_count' => $img_count ],
            0, 0, $img_count, 0.0,
            Rental_Sync_Status::STATUS_CREATED,
            $mode,
            Rental_Sync_Log_Repository::STAGE_START
        );

        // Init per-sync options
        update_option( "rental_products_img_last_id_{$sync_id}", 0 );
        update_option( "rental_products_img_processed_{$sync_id}", 0 );
        update_option( "rental_image_upload_completed_{$sync_id}", false );

        // Clear failed images for this new sync
        Rental_Failed_Image_Repository::clear_for_sync( $sync_id );

        $chunk_size = (int) get_option( 'rental_default_chunk_size', 8 );

        // ── Notify Laravel server ────────────────────────────
        try {
            $response = rental_curl( 'sync/start', $api_key, true, [
                'wp_endpoint' => get_rest_url( null, '/rentopian-sync/v1/process-chunk' ),
                'wp_token'    => $wp_token,
                'sync_id'     => $sync_id,
                'start_index' => 0,
                'chunk_size'  => $chunk_size,
                'mode'        => $mode,
            ] );

            return [
                'success'              => true,
                'message'              => 'Background file sync scheduled.',
                'sync_id'              => $sync_id,
                'response_from_rental' => $response,
            ];

        } catch ( Exception $e ) {
            Rental_Sync_Session_Manager::update( $sync_id, [
                'status'     => Rental_Sync_Status::STATUS_FAILED,
                'last_error' => $e->getMessage(),
            ] );

            return [
                'success' => false,
                'message' => 'Failed to schedule file sync: ' . $e->getMessage(),
            ];
        }
    }

    /* ──────────────────────────────────────────────────────────
     * Stopping
     * ────────────────────────────────────────────────────────── */

    /**
     * Stop one file sync and tell the Rentopian server to stop dispatching
     * for it.
     *
     * @param string $sync_id Defaults to the current run.
     * @return array { success, message, sync_id, remote_ok }
     */
    public static function cancel( $sync_id = '' ) {
        $sync_id  = $sync_id ?: get_option( 'rental_current_sync_id', '' );
        $wp_token = get_option( 'rental_current_wp_token', '' );

        if ( ! $sync_id ) {
            return [ 'success' => false, 'message' => 'No active file sync found.', 'sync_id' => '' ];
        }

        Rental_File_Sync_Logger::bind_run( $sync_id );
        self::log( "Cancel requested for sync_id={$sync_id}" );

        $session = Rental_Sync_Session_Manager::get( $sync_id );

        $fields = [
            'status'      => Rental_Sync_Status::STATUS_CANCELED,
            'canceled_at' => current_time( 'mysql' ),
        ];

        // A cancel that arrives with no session on record still has to leave
        // one behind, or nothing downstream can tell the run was stopped.
        if ( ! $session ) {
            $fields += [
                'wp_token'      => $wp_token,
                'last_index'    => intval( get_option( "rental_products_img_last_id_{$sync_id}", 0 ) ),
                'failed_ids'    => Rental_Failed_Image_Repository::get_ids_by_sync( $sync_id ),
                'started_at'    => current_time( 'mysql' ),
                'started_epoch' => time(),
            ];
        }

        Rental_Sync_Session_Manager::update( $sync_id, $fields );
        Rental_Sync_Session_Manager::release_lock( $sync_id );

        Rental_Sync_Log_Repository::write(
            $sync_id, 'warning', 'File sync canceled by admin',
            [ 'canceled_at' => current_time( 'mysql' ) ],
            intval( get_option( "rental_products_img_last_id_{$sync_id}", 0 ) ),
            intval( get_option( "rental_products_img_processed_{$sync_id}", 0 ) ),
            intval( get_option( 'rental_products_img_count', 0 ) ),
            0.0,
            Rental_Sync_Status::STATUS_CANCELED,
            (int) ( $session['mode'] ?? Rental_Sync_Status::MODE_SYNC ),
            Rental_Sync_Log_Repository::STAGE_END
        );

        return [
            'success'   => true,
            'message'   => 'File sync canceled.',
            'sync_id'   => $sync_id,
            'remote_ok' => self::notify_remote_cancel( $sync_id ),
        ];
    }

    /**
     * Stop every file sync and refuse new ones until a start clears the
     * switch.
     *
     * Three things stop work, and all three are needed: the generation bump
     * makes every chunk already in flight arrive stale, the kill switch
     * refuses anything the driver sends afterwards, and the remote call stops
     * it sending at all. Cancelling the sessions alone leaves whatever the
     * queue has already dispatched still running.
     *
     * @param string $reason Recorded for the admin.
     * @return array
     */
    public static function cancel_all( $reason = '' ) {
        $reason = $reason ?: 'Manually canceled all file syncs at ' . current_time( 'mysql' );

        $generation = Rental_Sync_Session_Manager::generation_increment();

        update_option( 'rental_sync_all_canceled', 1 );
        update_option( 'rental_sync_canceled_reason', $reason );

        $sessions = Rental_Sync_Session_Manager::get_all();
        $canceled = Rental_Sync_Session_Manager::cancel_all_sessions();

        foreach ( array_keys( $sessions ) as $sid ) {
            Rental_Sync_Log_Repository::write(
                $sid, 'warning', 'File sync canceled (stop all)',
                [ 'canceled_at' => current_time( 'mysql' ), 'reason' => $reason ],
                intval( get_option( "rental_products_img_last_id_{$sid}", 0 ) ),
                intval( get_option( "rental_products_img_processed_{$sid}", 0 ) ),
                intval( get_option( 'rental_products_img_count', 0 ) ),
                0.0,
                Rental_Sync_Status::STATUS_CANCELED,
                Rental_Sync_Status::MODE_SYNC,
                Rental_Sync_Log_Repository::STAGE_END
            );
        }

        $locks_cleared  = self::delete_transients_by_prefix( 'rental_image_download_lock_' );
        $locks_cleared += self::delete_transients_by_prefix( 'rental_sync_lock_' );

        update_option( 'rental_current_sync_id', '' );
        update_option( 'rental_current_wp_token', '' );

        return [
            'success'          => true,
            'message'          => 'All file syncs canceled and further ones disabled.',
            'generation'       => $generation,
            'sessions_updated' => $canceled,
            'locks_cleared'    => $locks_cleared,
            'remote'           => self::notify_remote_cancel_all( array_keys( $sessions ) ),
        ];
    }

    /**
     * @param string $sync_id
     * @return bool Whether the Rentopian server acknowledged the cancel.
     */
    private static function notify_remote_cancel( $sync_id ) {
        try {
            rental_curl( 'sync/cancel', get_option( 'rental_api_key', '' ), true, [ 'sync_id' => $sync_id ] );
            return true;
        } catch ( Throwable $e ) {
            self::log( 'Remote cancel failed for ' . $sync_id . ': ' . $e->getMessage(), 'error' );
            return false;
        }
    }

    /**
     * Ask the server to drop everything, falling back to one call per run
     * when it has no bulk endpoint.
     *
     * @param array $sync_ids
     * @return array { bulk_ok, ok, failed }
     */
    private static function notify_remote_cancel_all( array $sync_ids ) {
        try {
            rental_curl( 'sync/cancel-all', get_option( 'rental_api_key', '' ), true, [] );
            return [ 'bulk_ok' => true, 'ok' => count( $sync_ids ), 'failed' => 0 ];
        } catch ( Throwable $e ) {
            self::log( 'Remote cancel-all failed, falling back per run: ' . $e->getMessage(), 'warning' );
        }

        $ok = $failed = 0;
        foreach ( $sync_ids as $sid ) {
            if ( self::notify_remote_cancel( $sid ) ) {
                $ok++;
            } else {
                $failed++;
            }
        }

        return [ 'bulk_ok' => false, 'ok' => $ok, 'failed' => $failed ];
    }

    /**
     * @param string $prefix
     * @return int Rows removed.
     */
    private static function delete_transients_by_prefix( $prefix ) {
        global $wpdb;

        $like    = esc_sql( '_transient_' . $prefix . '%' );
        $like_to = esc_sql( '_transient_timeout_' . $prefix . '%' );

        return (int) $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '{$like}'" )
            + (int) $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '{$like_to}'" );
    }

    private static function log( $message, $level = 'info' ) {
        Rental_File_Sync_Logger::write( $message, $level, 'schedule' );
    }
}
