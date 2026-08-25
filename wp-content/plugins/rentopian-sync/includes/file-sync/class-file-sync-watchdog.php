<?php
/**
 * File Sync Watchdog
 *
 * The file phase is driven from the Rentopian server: WordPress asks it to
 * start and it calls back once per chunk. When those callbacks stop — a queue
 * worker that died, a retry ceiling reached, a response that never arrived —
 * nothing on this side notices. The session stays "processing", the panel
 * keeps showing a percentage that will never move, and no report is ever
 * sent, because the report is only sent on completion.
 *
 * This turns that silence into a recorded outcome and a report.
 *
 * @package RentopianSync\FileSync
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Rental_File_Sync_Watchdog {

    /**
     * Seconds without a callback before the phase is shown as stalled.
     */
    const STALL_AFTER = 600;

    /**
     * Seconds without a callback before the run is failed outright, so the
     * outcome is recorded and reported instead of hanging forever. Longer
     * than any retry backoff the driver applies.
     */
    const FAIL_AFTER = 1800;

    /**
     * Minimum seconds between two ticks, so the check costs nothing on a
     * busy site.
     */
    const TICK_INTERVAL = 60;

    /**
     * Watchdog tick. Runs without an admin watching the panel.
     */
    public static function tick() {
        if ( get_transient( 'rental_file_sync_watchdog_tick' ) ) {
            return;
        }
        set_transient( 'rental_file_sync_watchdog_tick', 1, self::TICK_INTERVAL );

        self::enforce( (string) get_option( 'rental_current_sync_id', '' ) );
    }

    /**
     * Fail a run whose driver has gone quiet for longer than any retry would
     * take.
     *
     * @param string $sync_id
     * @return bool TRUE when the run was failed by this call.
     */
    public static function enforce( $sync_id ) {
        if ( ! $sync_id ) {
            return false;
        }

        $session = Rental_Sync_Session_Manager::get( $sync_id );
        if ( ! $session ) {
            return false;
        }

        if ( ! self::is_active( $session ) ) {
            return false;
        }

        $idle = self::idle_seconds( $sync_id, $session );
        if ( $idle < self::FAIL_AFTER ) {
            return false;
        }

        $processed = (int) ( $session['processed_count'] ?? 0 );
        $total     = (int) ( $session['total_count'] ?? 0 );
        $reason    = self::reason( $session, $idle, $processed, $total );

        Rental_Sync_Session_Manager::update( $sync_id, [
            'status'     => Rental_Sync_Status::STATUS_FAILED,
            'last_error' => $reason,
        ] );
        Rental_Sync_Session_Manager::release_lock( $sync_id );

        Rental_File_Sync_Logger::bind_run( $sync_id );

        Rental_Sync_Log_Repository::write(
            $sync_id,
            'error',
            'File sync failed by watchdog: ' . $reason,
            [ 'idle_seconds' => $idle ],
            (int) ( $session['last_index'] ?? 0 ),
            $processed,
            $total,
            0.0,
            Rental_Sync_Status::STATUS_FAILED,
            (int) ( $session['mode'] ?? Rental_Sync_Status::MODE_SYNC ),
            Rental_Sync_Log_Repository::STAGE_END
        );

        do_action( 'rental_file_sync_failed', $sync_id, $reason );

        return true;
    }

    /**
     * Whether a run has gone quiet long enough to be worth reporting, while
     * still short of the point where it is failed.
     *
     * @param string $sync_id
     * @param array  $session
     * @return bool
     */
    public static function is_stalled( $sync_id, array $session ) {
        return self::is_active( $session )
            && self::idle_seconds( $sync_id, $session ) > self::STALL_AFTER;
    }

    /**
     * Seconds since the run last showed a sign of life.
     *
     * The session heartbeat is preferred because it is stamped on every
     * chunk. The log table only holds a row per minute at most, and a run
     * that never got its first callback has neither, so the start time is
     * the last resort.
     *
     * @param string $sync_id
     * @param array  $session
     * @return int
     */
    public static function idle_seconds( $sync_id, array $session ) {
        $last = (int) ( $session['last_chunk_at'] ?? 0 );

        if ( ! $last ) {
            $last = self::last_logged_at( $sync_id );
        }

        if ( ! $last ) {
            $last = Rental_Sync_Time::started( $session );
        }

        return $last ? max( 0, time() - $last ) : 0;
    }

    /* ──────────────────────────────────────────────────────────
     * Internals
     * ────────────────────────────────────────────────────────── */

    /**
     * @param array $session
     * @return bool Whether the run is still expected to progress.
     */
    private static function is_active( array $session ) {
        return in_array(
            (int) ( $session['status'] ?? 0 ),
            [ Rental_Sync_Status::STATUS_CREATED, Rental_Sync_Status::STATUS_PROCESSING ],
            true
        );
    }

    /**
     * Newest log row for a run, as a Unix time.
     *
     * @param string $sync_id
     * @return int 0 when the table or the run has no rows.
     */
    private static function last_logged_at( $sync_id ) {
        global $wpdb;

        $table = $wpdb->prefix . 'rental_file_sync_log';

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return 0;
        }

        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT MAX(register_time) FROM {$table} WHERE sync_id = %s",
            $sync_id
        ) );
    }

    /**
     * Say what stopped, in the terms the admin can act on: a run that never
     * started needs a different fix from one that stopped halfway.
     *
     * @param array $session
     * @param int   $idle
     * @param int   $processed
     * @param int   $total
     * @return string
     */
    private static function reason( array $session, $idle, $processed, $total ) {
        $minutes = max( 1, (int) round( $idle / 60 ) );

        if ( empty( $session['last_chunk_at'] ) && $processed <= 0 ) {
            return sprintf(
                /* translators: %d: number of minutes */
                _n(
                    'The Rentopian server never asked WordPress for an image chunk (%d minute after the file phase started). No images were synchronized.',
                    'The Rentopian server never asked WordPress for an image chunk (%d minutes after the file phase started). No images were synchronized.',
                    $minutes,
                    'rentopian-sync'
                ),
                $minutes
            );
        }

        return sprintf(
            /* translators: 1: number of minutes, 2: images processed, 3: images in the catalog */
            __( 'The image synchronization stopped: no chunk request for %1$d minutes, at %2$d of %3$d images.', 'rentopian-sync' ),
            $minutes,
            $processed,
            $total
        );
    }
}
