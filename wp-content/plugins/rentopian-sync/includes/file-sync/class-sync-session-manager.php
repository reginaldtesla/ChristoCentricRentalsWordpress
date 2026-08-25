<?php
/**
 * Sync Session Manager
 *
 * Manages the `rental_sync_sessions` WP option (the in-memory "session table")
 * as well as generation counters, guard checks, resume logic, and lock handling.
 *
 * @package RentopianSync\FileSync
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Rental_Sync_Session_Manager {

    /* ──────────────────────────────────────────────────────────
     * Generation counter
     * ────────────────────────────────────────────────────────── */

    /**
     * @return int Current sync generation.
     */
    public static function generation_current() {
        return (int) get_option( 'rental_sync_generation', 0 );
    }

    /**
     * Increment and return the new generation.
     *
     * @return int
     */
    public static function generation_increment() {
        $gen = self::generation_current() + 1;
        update_option( 'rental_sync_generation', $gen );
        return $gen;
    }

    /* ──────────────────────────────────────────────────────────
     * Global kill-switch
     * ────────────────────────────────────────────────────────── */

    /**
     * @return bool
     */
    public static function is_globally_disabled() {
        return (int) get_option( 'rental_sync_all_canceled', 0 ) === 1;
    }

    /**
     * Guard: returns an error array if sync should NOT proceed, or null if OK.
     *
     * @param string|null $sync_id
     * @return array|null
     */
    public static function guard_or_null( $sync_id = null ) {
        // Global kill switch
        if ( self::is_globally_disabled() ) {
            $msg = get_option( 'rental_sync_canceled_reason', 'All file syncs canceled.' );

            if ( $sync_id ) {
                $sessions = self::get_all();
                if ( isset( $sessions[ $sync_id ] ) ) {
                    $sessions[ $sync_id ]['status']      = Rental_Sync_Status::STATUS_CANCELED;
                    $sessions[ $sync_id ]['canceled_at']  = current_time( 'mysql' );
                    self::save_all( $sessions );
                }
            }

            return [
                'success' => false,
                'reason'  => 'all_canceled',
                'message' => $msg,
            ];
        }

        // Per-session cancel
        if ( $sync_id ) {
            $sessions = self::get_all();
            if (
                isset( $sessions[ $sync_id ] ) &&
                (int) ( $sessions[ $sync_id ]['status'] ?? 0 ) === Rental_Sync_Status::STATUS_CANCELED
            ) {
                return [
                    'success' => false,
                    'reason'  => 'session_canceled',
                    'message' => 'This sync session is canceled.',
                ];
            }
        }

        return null;
    }

    /* ──────────────────────────────────────────────────────────
     * Session CRUD
     * ────────────────────────────────────────────────────────── */

    /**
     * @return array All sessions keyed by sync_id.
     */
    public static function get_all() {
        $sessions = get_option( 'rental_sync_sessions', [] );
        return is_array( $sessions ) ? $sessions : [];
    }

    /**
     * @param array $sessions
     */
    public static function save_all( array $sessions ) {
        update_option( 'rental_sync_sessions', $sessions, false );
    }

    /**
     * Get a single session.
     *
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
     * @param array  $data Key-value pairs to merge.
     */
    public static function update( $sync_id, array $data ) {
        $sessions = self::get_all();
        if ( ! isset( $sessions[ $sync_id ] ) ) {
            $sessions[ $sync_id ] = [];
        }
        $sessions[ $sync_id ] = array_merge( $sessions[ $sync_id ], $data );
        self::save_all( $sessions );
    }

    /**
     * Remove a session entirely.
     *
     * @param string $sync_id
     */
    public static function remove( $sync_id ) {
        $sessions = self::get_all();
        unset( $sessions[ $sync_id ] );
        self::save_all( $sessions );
    }

    /**
     * Cancel ALL sessions + purge locks.
     *
     * @return int Number of sessions updated.
     */
    public static function cancel_all_sessions() {
        $sessions = self::get_all();
        $count    = 0;

        foreach ( $sessions as $sid => &$s ) {
            $s['status']      = Rental_Sync_Status::STATUS_CANCELED;
            $s['canceled_at'] = current_time( 'mysql' );
            $count++;
            delete_transient( "rental_sync_lock_{$sid}" );
        }
        unset( $s );

        self::save_all( $sessions );
        return $count;
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
            if (
                ! isset( $s['status'] ) ||
                (int) $s['status'] !== Rental_Sync_Status::STATUS_CANCELED
            ) {
                $s['status']      = Rental_Sync_Status::STATUS_CANCELED;
                $s['canceled_at'] = current_time( 'mysql' );
            }
            delete_transient( "rental_sync_lock_{$sid}" );
        }
        unset( $s );

        self::save_all( $sessions );
    }

    /* ──────────────────────────────────────────────────────────
     * Lock helpers
     * ────────────────────────────────────────────────────────── */

    /**
     * Attempt to acquire a per-session transient lock.
     *
     * @param string $sync_id
     * @param int    $ttl Seconds (default 60).
     * @return bool TRUE if lock acquired, FALSE if already held.
     */
    public static function acquire_lock( $sync_id, $ttl = 60 ) {
        $key = "rental_sync_lock_{$sync_id}";
        if ( get_transient( $key ) ) {
            return false;
        }
        set_transient( $key, time(), $ttl );
        return true;
    }

    /**
     * Release a per-session lock.
     *
     * @param string $sync_id
     */
    public static function release_lock( $sync_id ) {
        delete_transient( "rental_sync_lock_{$sync_id}" );
    }

    /* ──────────────────────────────────────────────────────────
     * Resume helpers
     * ────────────────────────────────────────────────────────── */

    /**
     * Compute a safe resume cursor.
     * Priority: per-sync option → logs → relations table → 0
     *
     * @param string $sync_id
     * @return int
     */
    public static function compute_resume_index( $sync_id ) {
        global $wpdb, $rental_tables;

        // Per-sync option
        $opt = intval( get_option( "rental_products_img_last_id_{$sync_id}", 0 ) );
        if ( $opt > 0 ) {
            return $opt;
        }

        // From logs
        $table      = $wpdb->prefix . 'rental_file_sync_log';
        $last_index = intval( $wpdb->get_var( $wpdb->prepare(
            "SELECT last_index FROM {$table}
             WHERE sync_id = %s AND processed_count < total_count AND status <> %d
             ORDER BY id DESC LIMIT 1",
            $sync_id,
            Rental_Sync_Status::STATUS_COMPLETED
        ) ) );

        if ( $last_index > 0 ) {
            return $last_index;
        }

        // Relations table
        $rel = $wpdb->prefix . ( $rental_tables['image_relations'] ?? 'rental_image_relations' );
        if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $rel ) ) === $rel ) {
            $max_id = intval( $wpdb->get_var( "SELECT MAX(rental_id) FROM {$rel}" ) );
            if ( $max_id > 0 ) {
                return $max_id;
            }
        }

        return 0;
    }

    /**
     * Prepare session for resume: refresh generation, mint/reuse token, clear locks.
     *
     * @param string $sync_id
     * @return array [ $sessions_array, $wp_token ]
     */
    public static function prepare_for_resume( $sync_id ) {
        $sessions = self::get_all();
        if ( ! isset( $sessions[ $sync_id ] ) ) {
            $sessions[ $sync_id ] = [];
        }

        $cur_gen = self::generation_current();
        $sessions[ $sync_id ]['generation'] = $cur_gen;

        $status = intval( $sessions[ $sync_id ]['status'] ?? 0 );
        $resumable_statuses = [
            Rental_Sync_Status::STATUS_CANCELED,
            Rental_Sync_Status::STATUS_FAILED,
            0,
        ];

        if ( in_array( $status, $resumable_statuses, true ) ) {
            $sessions[ $sync_id ]['status'] = Rental_Sync_Status::STATUS_PROCESSING;
        }

        unset( $sessions[ $sync_id ]['last_error'] );

        // Mint or reuse token
        $wp_token = ! empty( $sessions[ $sync_id ]['wp_token'] )
            ? $sessions[ $sync_id ]['wp_token']
            : wp_generate_password( 32, false );

        $sessions[ $sync_id ]['wp_token'] = $wp_token;

        self::save_all( $sessions );

        // Clear locks
        self::release_lock( $sync_id );
        update_option( 'rental_current_sync_id', $sync_id );
        update_option( 'rental_current_wp_token', $wp_token );

        return [ $sessions, $wp_token ];
    }

    /**
     * Find the most recent eligible (broken/stuck) sync to resume.
     *
     * @return string|null sync_id or null
     */
    public static function pick_latest_broken_sync_id() {
        global $wpdb;

        // Check logs first
        $table = $wpdb->prefix . 'rental_file_sync_log';
        $row   = $wpdb->get_row(
            "SELECT sync_id FROM {$table}
             WHERE processed_count <> 0
               AND processed_count < total_count
               AND last_index <> 0
             ORDER BY id DESC LIMIT 1"
        );

        if ( $row && ! empty( $row->sync_id ) ) {
            return $row->sync_id;
        }

        // Fallback: sessions
        $sessions  = self::get_all();
        $candidate = null;
        $best_ts   = 0;
        $now       = time();

        foreach ( $sessions as $sid => $s ) {
            $status  = intval( $s['status'] ?? 0 );
            $started = isset( $s['started_at'] ) ? strtotime( $s['started_at'] ) : 0;
            $updated = isset( $s['updated_at'] ) ? strtotime( $s['updated_at'] ) : 0;
            $last_t  = max( $started, $updated );

            $is_stuck = ( $status === Rental_Sync_Status::STATUS_PROCESSING )
                && ( ( $now - $last_t ) > 20 * 60 );

            $eligible = in_array( $status, [
                Rental_Sync_Status::STATUS_FAILED,
                Rental_Sync_Status::STATUS_CANCELED,
            ], true ) || $is_stuck;

            if ( ! $eligible ) {
                continue;
            }

            if ( $last_t > $best_ts ) {
                $candidate = $sid;
                $best_ts   = $last_t;
            }
        }

        return $candidate;
    }
}
