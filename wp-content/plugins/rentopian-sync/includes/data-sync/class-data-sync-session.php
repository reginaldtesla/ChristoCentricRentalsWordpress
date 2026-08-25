<?php
/**
 * Data Sync Session Manager
 *
 * Option-backed session store for background data-sync runs, mirroring the
 * file-sync session manager: generation counter, kill switch, per-session
 * lock, plus run-scoped extras — per-phase completion flags (the sweep
 * guard), per-entity counters (the email report), seen-id sets (orphan
 * sweep) and a per-run staging directory for variant buckets and the sets
 * dump.
 *
 * @package RentopianSync\DataSync
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Rental_Data_Sync_Session {

    /* ──────────────────────────────────────────────────────────
     * Generation counter
     * ────────────────────────────────────────────────────────── */

    public static function generation_current() {
        return (int) get_option( 'rental_data_sync_generation', 0 );
    }

    public static function generation_increment() {
        $gen = self::generation_current() + 1;
        update_option( 'rental_data_sync_generation', $gen );
        return $gen;
    }

    /* ──────────────────────────────────────────────────────────
     * Global kill-switch
     * ────────────────────────────────────────────────────────── */

    public static function is_globally_disabled() {
        return (int) get_option( 'rental_data_sync_all_canceled', 0 ) === 1;
    }

    public static function clear_global_disable() {
        update_option( 'rental_data_sync_all_canceled', 0 );
        update_option( 'rental_data_sync_canceled_reason', '' );
    }

    /* ──────────────────────────────────────────────────────────
     * Session CRUD
     * ────────────────────────────────────────────────────────── */

    /**
     * @return array All sessions keyed by sync_id.
     */
    public static function get_all() {
        $sessions = get_option( 'rental_data_sync_sessions', [] );
        return is_array( $sessions ) ? $sessions : [];
    }

    public static function save_all( array $sessions ) {
        update_option( 'rental_data_sync_sessions', $sessions, false );
    }

    /**
     * @param string $sync_id
     * @return array|null
     */
    public static function get( $sync_id ) {
        $sessions = self::get_all();
        return isset( $sessions[ $sync_id ] ) ? $sessions[ $sync_id ] : null;
    }

    /**
     * Update (merge) a single session.
     *
     * @param string $sync_id
     * @param array  $data
     */
    public static function update( $sync_id, array $data ) {
        $sessions = self::get_all();
        if ( ! isset( $sessions[ $sync_id ] ) ) {
            $sessions[ $sync_id ] = [];
        }
        $sessions[ $sync_id ]               = array_merge( $sessions[ $sync_id ], $data );
        $sessions[ $sync_id ]['updated_at'] = current_time( 'mysql' );
        self::save_all( $sessions );
    }

    /**
     * Remove a session and every per-run artifact (options, staging files).
     *
     * @param string $sync_id
     */
    public static function remove( $sync_id ) {
        $sessions = self::get_all();
        unset( $sessions[ $sync_id ] );
        self::save_all( $sessions );

        self::cleanup_run_artifacts( $sync_id );
    }

    /**
     * Cancel all sessions EXCEPT the given sync_id.
     *
     * @param string $except_sync_id
     */
    public static function cancel_previous_sessions( $except_sync_id ) {
        $sessions = self::get_all();

        foreach ( $sessions as $sid => &$s ) {
            if ( $sid === $except_sync_id ) {
                continue;
            }
            if ( (int) ( $s['status'] ?? 0 ) !== Rental_Data_Sync_Status::STATUS_CANCELED ) {
                $s['status']      = Rental_Data_Sync_Status::STATUS_CANCELED;
                $s['canceled_at'] = current_time( 'mysql' );
            }
            delete_transient( "rental_data_sync_lock_{$sid}" );
        }
        unset( $s );

        self::save_all( $sessions );
    }

    /**
     * Record that the Rentopian driver just called back. `first_chunk_at`
     * separates "the queue never delivered anything" from "the run stalled
     * part-way", which are different problems with different fixes.
     *
     * @param string $sync_id
     */
    public static function heartbeat( $sync_id ) {
        $session = self::get( $sync_id );
        if ( ! $session ) {
            return;
        }

        $data = [ 'last_chunk_at' => time() ];
        if ( empty( $session['first_chunk_at'] ) ) {
            $data['first_chunk_at'] = time();
        }

        self::update( $sync_id, $data );
    }

    /**
     * Put a run the watchdog gave up on back to processing, because
     * something has since proved it is alive.
     *
     * The run record is corrected too, so the history and the panel stop
     * showing a failure that did not happen.
     *
     * @param string $sync_id
     * @return array|null The revived session.
     */
    public static function revive( $sync_id ) {
        $session = self::get( $sync_id );
        if ( ! $session ) {
            return null;
        }

        $reason = (string) ( $session['last_error'] ?? '' );

        self::update( $sync_id, [
            'status'     => Rental_Data_Sync_Status::STATUS_PROCESSING,
            'last_error' => '',
            'revived_at' => time(),
        ] );

        Rental_Data_Sync_Run_Repository::touch( $sync_id, [
            'status'  => Rental_Data_Sync_Status::STATUS_PROCESSING,
            'message' => __( 'Resumed: the synchronization reported in again after being given up on.', 'rentopian-sync' ),
        ] );

        Rental_Data_Sync_Run_Repository::reopen( $sync_id );

        Rental_Data_Sync_Logger::write(
            'Run resumed: a chunk arrived after the watchdog had failed it' . ( $reason ? " ({$reason})" : '' ),
            'warning',
            'watchdog',
            $sync_id
        );

        return self::get( $sync_id );
    }

    /* ──────────────────────────────────────────────────────────
     * Lock helpers
     * ────────────────────────────────────────────────────────── */

    /**
     * @param string $sync_id
     * @param int    $ttl Seconds.
     * @return bool TRUE if lock acquired.
     */
    public static function acquire_lock( $sync_id, $ttl = 300 ) {
        $key = "rental_data_sync_lock_{$sync_id}";
        if ( get_transient( $key ) ) {
            return false;
        }
        set_transient( $key, time(), $ttl );
        return true;
    }

    public static function release_lock( $sync_id ) {
        delete_transient( "rental_data_sync_lock_{$sync_id}" );
    }

    /* ──────────────────────────────────────────────────────────
     * Per-run state: cursor, phase flags, counters, seen ids
     * ────────────────────────────────────────────────────────── */

    /**
     * Stored cursor for retry fast-forward (mirror of the file-sync
     * stored-progress pattern).
     */
    public static function get_stored_cursor( $sync_id ) {
        return (int) get_option( "rental_data_sync_cursor_{$sync_id}", 0 );
    }

    public static function store_cursor( $sync_id, $cursor ) {
        update_option( "rental_data_sync_cursor_{$sync_id}", (int) $cursor, false );
    }

    /**
     * Mark a phase fully completed for this run.
     */
    public static function mark_phase_done( $sync_id, $phase ) {
        $done = get_option( "rental_data_sync_phases_done_{$sync_id}", [] );
        if ( ! is_array( $done ) ) {
            $done = [];
        }
        $done[ (int) $phase ] = time();
        update_option( "rental_data_sync_phases_done_{$sync_id}", $done, false );
    }

    /**
     * @return bool TRUE when every phase in $phases completed this run.
     */
    public static function phases_done( $sync_id, array $phases ) {
        $done = get_option( "rental_data_sync_phases_done_{$sync_id}", [] );
        if ( ! is_array( $done ) ) {
            return false;
        }
        foreach ( $phases as $p ) {
            if ( ! isset( $done[ (int) $p ] ) ) {
                return false;
            }
        }
        return true;
    }

    /**
     * Merge per-entity counters into the run stats option.
     * Shape: [ entity => [ created => n, updated => n, deleted => n, failed => n ] ]
     *
     * @param string $sync_id
     * @param array  $delta Same shape; values are added.
     */
    public static function add_stats( $sync_id, array $delta ) {
        $stats = get_option( "rental_data_sync_stats_{$sync_id}", [] );
        if ( ! is_array( $stats ) ) {
            $stats = [];
        }
        foreach ( $delta as $entity => $counts ) {
            foreach ( (array) $counts as $k => $v ) {
                if ( ! isset( $stats[ $entity ][ $k ] ) ) {
                    $stats[ $entity ][ $k ] = 0;
                }
                $stats[ $entity ][ $k ] += (int) $v;
            }
        }
        update_option( "rental_data_sync_stats_{$sync_id}", $stats, false );
        return $stats;
    }

    public static function get_stats( $sync_id ) {
        $stats = get_option( "rental_data_sync_stats_{$sync_id}", [] );
        return is_array( $stats ) ? $stats : [];
    }

    /**
     * How many failing records a run keeps for its report. The `failed`
     * counters carry the true total; this is the sample a person reads, so
     * a run that fails everything cannot grow the option without bound.
     */
    const MAX_RECORDED_FAILURES = 50;

    /**
     * Append failing records for the report.
     *
     * Shape per entry: [ entity, context, reason ].
     *
     * @param string  $sync_id
     * @param array[] $failures
     */
    public static function add_failures( $sync_id, array $failures ) {
        if ( empty( $failures ) ) {
            return;
        }

        $key      = "rental_data_sync_failures_{$sync_id}";
        $recorded = get_option( $key, [] );
        if ( ! is_array( $recorded ) ) {
            $recorded = [];
        }

        if ( count( $recorded ) >= self::MAX_RECORDED_FAILURES ) {
            return;
        }

        $recorded = array_slice(
            array_merge( $recorded, array_values( $failures ) ),
            0,
            self::MAX_RECORDED_FAILURES
        );

        update_option( $key, $recorded, false );
    }

    /**
     * @param string $sync_id
     * @return array[] Failing records, up to MAX_RECORDED_FAILURES.
     */
    public static function get_failures( $sync_id ) {
        $recorded = get_option( "rental_data_sync_failures_{$sync_id}", [] );
        return is_array( $recorded ) ? $recorded : [];
    }

    /**
     * Append rental ids seen this run for one entity (sweep + report).
     * Ids are plain ints or "id:division" composites — composites MUST
     * keep their string form, an int cast would collapse "55:1" to 55.
     *
     * @param string         $sync_id
     * @param string         $entity  products | variants | sets | categories | brands
     * @param int[]|string[] $ids
     */
    public static function add_seen_ids( $sync_id, $entity, array $ids ) {
        if ( empty( $ids ) ) {
            return;
        }
        $key  = "rental_data_sync_seen_{$entity}_{$sync_id}";
        $seen = get_option( $key, [] );
        if ( ! is_array( $seen ) ) {
            $seen = [];
        }
        foreach ( $ids as $id ) {
            $id = preg_replace( '/[^0-9:]/', '', (string) $id );
            if ( $id !== '' ) {
                $seen[ $id ] = 1;
            }
        }
        update_option( $key, $seen, false );
    }

    /**
     * @return array Map rental_id => 1.
     */
    public static function get_seen_ids( $sync_id, $entity ) {
        $seen = get_option( "rental_data_sync_seen_{$entity}_{$sync_id}", [] );
        return is_array( $seen ) ? $seen : [];
    }

    /**
     * Record rental ids the writer deliberately did not write this run.
     *
     * These are present in the feed but produced nothing writable — a
     * product whose inventory rows are all inactive, for instance. The
     * sweep decides what to delete from what it did *not* see, so without
     * this set a skipped record looks identical to a deleted one.
     *
     * @param string $sync_id
     * @param string $entity
     * @param int[]  $ids
     */
    public static function add_skipped_ids( $sync_id, $entity, array $ids ) {
        if ( empty( $ids ) ) {
            return;
        }

        $key     = "rental_data_sync_skipped_{$entity}_{$sync_id}";
        $skipped = get_option( $key, [] );
        if ( ! is_array( $skipped ) ) {
            $skipped = [];
        }

        foreach ( $ids as $id ) {
            $id = (int) $id;
            if ( $id > 0 ) {
                $skipped[ $id ] = 1;
            }
        }

        update_option( $key, $skipped, false );
    }

    /**
     * @return array Map rental_id => 1.
     */
    public static function get_skipped_ids( $sync_id, $entity ) {
        $skipped = get_option( "rental_data_sync_skipped_{$entity}_{$sync_id}", [] );
        return is_array( $skipped ) ? $skipped : [];
    }

    /* ──────────────────────────────────────────────────────────
     * Staging directory (variant buckets, sets dump)
     * ────────────────────────────────────────────────────────── */

    /**
     * Per-run staging dir under uploads. Protected by an index.html and a
     * hash-suffixed, non-guessable directory name.
     *
     * @param string $sync_id
     * @param bool   $create
     * @return string Absolute path without trailing slash, '' on failure.
     */
    public static function staging_dir( $sync_id, $create = true ) {
        $upload = wp_upload_dir();
        if ( empty( $upload['basedir'] ) ) {
            return '';
        }

        $hash = substr( wp_hash( 'rental-data-sync-' . $sync_id ), 0, 12 );
        $dir  = trailingslashit( $upload['basedir'] ) . 'rentopian-data-sync/' . sanitize_key( $sync_id ) . '-' . $hash;

        if ( $create && ! is_dir( $dir ) ) {
            wp_mkdir_p( $dir );
            @file_put_contents( dirname( $dir ) . '/index.html', '' );
            @file_put_contents( $dir . '/index.html', '' );
        }

        return is_dir( $dir ) ? $dir : '';
    }

    /**
     * Delete a run's staging directory. Staging only ever serves an
     * in-flight run, so it is dropped as soon as the run stops for any
     * reason — completed, canceled or failed.
     *
     * @param string $sync_id
     */
    public static function cleanup_staging( $sync_id ) {
        $dir = self::staging_dir( $sync_id, false );
        if ( ! $dir || ! is_dir( $dir ) ) {
            return;
        }

        $files = glob( $dir . '/*' );
        if ( is_array( $files ) ) {
            foreach ( $files as $f ) {
                if ( is_file( $f ) ) {
                    @unlink( $f );
                }
            }
        }

        @rmdir( $dir );
    }

    /**
     * Age after which a staging directory cannot belong to a live run: the
     * watchdog fails an abandoned run long before this.
     */
    const STAGING_MAX_AGE = DAY_IN_SECONDS;

    /**
     * Remove staging directories left behind by runs that died without
     * cleaning up (a fatal, a killed worker, a site moved between hosts).
     * Without this the uploads folder would only ever grow.
     */
    public static function purge_orphan_staging() {
        $upload = wp_upload_dir();
        if ( empty( $upload['basedir'] ) ) {
            return;
        }

        $root = trailingslashit( $upload['basedir'] ) . 'rentopian-data-sync';
        if ( ! is_dir( $root ) ) {
            return;
        }

        $cutoff = time() - self::STAGING_MAX_AGE;

        foreach ( (array) glob( $root . '/*', GLOB_ONLYDIR ) as $dir ) {
            // The per-run log files live here too and are kept until the
            // admin deletes the run.
            if ( basename( $dir ) === 'logs' || filemtime( $dir ) > $cutoff ) {
                continue;
            }

            foreach ( (array) glob( $dir . '/*' ) as $file ) {
                if ( is_file( $file ) ) {
                    @unlink( $file );
                }
            }
            @rmdir( $dir );
        }
    }

    /**
     * Delete every per-run option and the staging directory. The run's log
     * file is deliberately kept — the admin panel serves it until the run
     * is deleted explicitly.
     *
     * @param string $sync_id
     */
    public static function cleanup_run_artifacts( $sync_id ) {
        delete_option( "rental_data_sync_cursor_{$sync_id}" );
        delete_option( "rental_data_sync_phases_done_{$sync_id}" );
        delete_option( "rental_data_sync_stats_{$sync_id}" );
        delete_option( "rental_data_sync_failures_{$sync_id}" );

        foreach ( [ 'products', 'variants', 'sets', 'categories', 'brands' ] as $entity ) {
            delete_option( "rental_data_sync_seen_{$entity}_{$sync_id}" );
            delete_option( "rental_data_sync_skipped_{$entity}_{$sync_id}" );
        }

        self::cleanup_staging( $sync_id );
        self::release_lock( $sync_id );
    }
}
