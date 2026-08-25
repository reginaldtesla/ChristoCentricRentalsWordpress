<?php
/**
 * Sync Log Repository
 *
 * Encapsulates all database operations against the `rental_file_sync_log` table.
 *
 * @package RentopianSync\FileSync
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Rental_Sync_Log_Repository {

    /*
     * What a row in this table is for.
     *
     * The table is the RUN RECORD — one row for the start, a bounded number
     * of progress snapshots, and one for the outcome. It is what the admin
     * runs list is built from, so it has to stay small enough to paginate.
     *
     * The line-by-line trace (every image, every retry, every exception)
     * belongs in the file log, which has no size pressure and is what you
     * actually read when debugging a run. Every call still writes there —
     * only the DB row is gated.
     */
    const STAGE_START    = 'start';
    const STAGE_PROGRESS = 'progress';
    const STAGE_END      = 'end';
    const STAGE_DETAIL   = 'detail';

    /**
     * Minimum seconds between two persisted progress snapshots of one run.
     * Without this, a long sync writes a row per chunk and the table grows
     * by thousands of rows per run.
     */
    const PROGRESS_INTERVAL = 60;

    /* ──────────────────────────────────────────────────────────
     * Table helpers
     * ────────────────────────────────────────────────────────── */

    /**
     * @return string Fully-prefixed table name.
     */
    private static function table() {
        global $wpdb;
        return $wpdb->prefix . 'rental_file_sync_log';
    }

    /* ──────────────────────────────────────────────────────────
     * Write
     * ────────────────────────────────────────────────────────── */

    /**
     * Write a file-sync log row.
     *
     * @param string       $sync_id
     * @param string       $level   info | warning | error | notice
     * @param string       $message
     * @param array|string $data
     * @param int          $last_index
     * @param int          $processed_count
     * @param int          $total_count
     * @param float        $elapsed
     * @param int          $status  Rental_Sync_Status constant
     * @param int          $mode    Rental_Sync_Status mode constant
     * @param string       $stage   STAGE_START | STAGE_PROGRESS | STAGE_END |
     *                              STAGE_DETAIL. Only the first three are kept
     *                              in the table; everything else is written to
     *                              the file log only. Defaults to STAGE_DETAIL
     *                              so existing callers keep working and simply
     *                              stop adding rows.
     * @return int Inserted row id (0 when not persisted or on failure).
     */
    public static function write(
        $sync_id,
        $level,
        $message,
        $data = '',
        $last_index = 0,
        $processed_count = 0,
        $total_count = 0,
        $elapsed = 0.0,
        $status = 2,
        $mode = 1,
        $stage = self::STAGE_DETAIL
    ) {
        global $wpdb;
        $table = self::table();

        // Normalise $data to string
        if ( is_array( $data ) || is_object( $data ) ) {
            $data = wp_json_encode( $data );
        } elseif ( $data === null ) {
            $data = '';
        } else {
            $data = (string) $data;
        }

        // The file log is the complete record and is written first, so a
        // line survives even when the row is skipped or the insert fails.
        self::mirror_log( $sync_id, $level, $message, $data );

        if ( ! self::should_persist( $sync_id, $stage ) ) {
            return 0;
        }

        $row = [
            'sync_id'         => substr( $sync_id, 0, 64 ),
            'level'           => sanitize_key( $level ),
            'status'          => intval( $status ),
            'mode'            => intval( $mode ),
            'message'         => $message,
            'data'            => $data,
            'last_index'      => intval( $last_index ),
            'processed_count' => intval( $processed_count ),
            'total_count'     => intval( $total_count ),
            'elapsed'         => floatval( $elapsed ),
            'register_time'   => time(),
        ];

        $format = [ '%s', '%s', '%d', '%d', '%s', '%s', '%d', '%d', '%d', '%f', '%d' ];

        $inserted = $wpdb->insert( $table, $row, $format );

        if ( $inserted === false ) {
            self::fallback_log( "Failed to insert file-sync log: {$wpdb->last_error} -- msg: {$message}" );
            return 0;
        }

        return (int) $wpdb->insert_id;
    }

    /**
     * Whether this line earns a row in the run record.
     *
     * Progress is rate-limited per run: the driver calls back once per
     * chunk, and persisting each one turns the run record into a trace.
     *
     * @param string $sync_id
     * @param string $stage
     * @return bool
     */
    private static function should_persist( $sync_id, $stage ) {
        if ( self::STAGE_START === $stage || self::STAGE_END === $stage ) {
            return true;
        }

        if ( self::STAGE_PROGRESS !== $stage ) {
            return false;
        }

        $key = 'rental_fs_log_progress_' . md5( (string) $sync_id );
        if ( get_transient( $key ) ) {
            return false;
        }

        set_transient( $key, time(), self::PROGRESS_INTERVAL );
        return true;
    }

    /* ──────────────────────────────────────────────────────────
     * Read — grouped sync runs
     * ────────────────────────────────────────────────────────── */

    /**
     * @param int $limit
     * @param int $offset
     * @return array
     */
    public static function get_grouped( $limit = 20, $offset = 0 ) {
        global $wpdb;
        $table = self::table();

        $sql = $wpdb->prepare(
            "SELECT sync_id, mode,
                    MIN(register_time) AS started_at,
                    MAX(register_time) AS last_at,
                    COUNT(*)           AS entries_count,
                    MAX(processed_count) AS processed_count,
                    MAX(total_count)   AS total_count
             FROM {$table}
             GROUP BY sync_id
             ORDER BY id DESC
             LIMIT %d OFFSET %d",
            $limit,
            $offset
        );

        return $wpdb->get_results( $sql );
    }

    /**
     * @return int Number of distinct sync runs.
     */
    public static function get_grouped_count() {
        global $wpdb;
        $table = self::table();

        $row = $wpdb->get_row( "SELECT COUNT(DISTINCT sync_id) as c FROM {$table}" );
        return intval( $row->c ?? 0 );
    }

    /**
     * @param int $page     1-based
     * @param int $per_page
     * @return array
     */
    public static function get_grouped_page( $page = 1, $per_page = 20 ) {
        global $wpdb;
        $table = self::table();

        $page     = max( 1, intval( $page ) );
        $per_page = max( 1, intval( $per_page ) );
        $offset   = ( $page - 1 ) * $per_page;

        $sql = $wpdb->prepare(
            "SELECT sync_id,
                    MAX(mode) AS mode,
                    MIN(register_time) AS started_at,
                    MAX(register_time) AS last_at,
                    COUNT(*)           AS entries_count,
                    MAX(processed_count) AS processed_count,
                    MAX(total_count)   AS total_count
             FROM {$table}
             GROUP BY sync_id
             ORDER BY id DESC
             LIMIT %d OFFSET %d",
            $per_page,
            $offset
        );

        return $wpdb->get_results( $sql );
    }

    /* ──────────────────────────────────────────────────────────
     * Read — entries for a single sync run
     * ────────────────────────────────────────────────────────── */

    /**
     * @param string $sync_id
     * @return array
     */
    public static function get_entries( $sync_id ) {
        global $wpdb;
        $table = self::table();

        return $wpdb->get_results(
            $wpdb->prepare( "SELECT * FROM {$table} WHERE sync_id = %s ORDER BY register_time ASC", $sync_id )
        );
    }

    /**
     * Paginated entries.
     *
     * @param string $sync_id
     * @param int    $page
     * @param int    $per_page
     * @return array { entries, total, page, per_page, total_pages }
     */
    public static function get_entries_paginated( $sync_id, $page = 1, $per_page = 20 ) {
        global $wpdb;
        $table = self::table();

        $page     = max( 1, intval( $page ) );
        $per_page = max( 1, intval( $per_page ) );
        $offset   = ( $page - 1 ) * $per_page;

        // Total
        $total = intval( $wpdb->get_var(
            $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE sync_id = %s", $sync_id )
        ) );

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE sync_id = %s ORDER BY register_time ASC LIMIT %d OFFSET %d",
                $sync_id,
                $per_page,
                $offset
            )
        );

        $out = [];
        foreach ( $rows as $e ) {
            $decoded = null;
            if ( $e->data ) {
                $decoded = json_decode( $e->data, true );
                if ( $decoded === null ) {
                    $decoded = $e->data;
                }
            }
            $out[] = [
                'id'              => intval( $e->id ),
                'level'           => esc_html( $e->level ),
                'message'         => esc_html( $e->message ),
                'data'            => $decoded,
                'processed_count' => intval( $e->processed_count ),
                'total_count'     => intval( $e->total_count ),
                'last_index'      => intval( $e->last_index ),
                'register_time'   => intval( $e->register_time ),
            ];
        }

        return [
            'entries'     => $out,
            'total'       => $total,
            'page'        => $page,
            'per_page'    => $per_page,
            'total_pages' => $per_page ? (int) ceil( $total / $per_page ) : 1,
        ];
    }

    /* ──────────────────────────────────────────────────────────
     * Read — single-value helpers
     * ────────────────────────────────────────────────────────── */

    /**
     * Last elapsed seconds for a sync (for adaptive chunk sizing).
     *
     * @param string $sync_id
     * @return float|null
     */
    public static function get_last_elapsed( $sync_id ) {
        global $wpdb;
        $table = self::table();

        $val = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT elapsed FROM {$table} WHERE sync_id = %s AND elapsed > 0 ORDER BY id DESC LIMIT 1",
                $sync_id
            )
        );

        return is_null( $val ) ? null : (float) $val;
    }

    /**
     * Check if there was a recent error for this sync.
     *
     * @param string $sync_id
     * @param int    $seconds Look-back window (default 15 min).
     * @return bool
     */
    public static function had_recent_error( $sync_id, $seconds = 900 ) {
        global $wpdb;
        $table = self::table();
        $since = time() - (int) $seconds;

        $has = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT 1 FROM {$table} WHERE sync_id = %s AND level = 'error' AND register_time >= %d ORDER BY id DESC LIMIT 1",
                $sync_id,
                $since
            )
        );

        return (bool) $has;
    }

    /**
     * Find the last completed sync session (for resync prerequisites).
     *
     * @return array|null
     */
    public static function get_last_completed_sync_session() {
        global $wpdb;
        $table = self::table();

        $status_completed = Rental_Sync_Status::STATUS_COMPLETED;
        $mode_sync        = Rental_Sync_Status::MODE_SYNC;

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT sync_id, processed_count, total_count, status, mode
             FROM {$table}
             WHERE status = %d
               AND mode = %d
               AND processed_count <> 0
               AND total_count <> 0
               AND processed_count >= total_count
             ORDER BY id DESC
             LIMIT 1",
            $status_completed,
            $mode_sync
        ) );

        if ( $row && ! empty( $row->sync_id ) ) {
            return [
                'sync_id'         => $row->sync_id,
                'mode'            => $row->mode,
                'processed_count' => (int) $row->processed_count,
                'total_count'     => (int) $row->total_count,
                'status'          => (int) $row->status,
            ];
        }

        return null;
    }

    /* ──────────────────────────────────────────────────────────
     * Delete
     * ────────────────────────────────────────────────────────── */

    /**
     * Delete all log entries for a sync.
     *
     * @param string $sync_id
     * @return int|false
     */
    public static function delete_by_sync( $sync_id ) {
        global $wpdb;
        return $wpdb->delete( self::table(), [ 'sync_id' => $sync_id ], [ '%s' ] );
    }

    /* ──────────────────────────────────────────────────────────
     * Internal helpers
     * ────────────────────────────────────────────────────────── */

    /**
     * Mirror log line to the unified file-sync log for immediate debugging.
     */
    private static function mirror_log( $sync_id, $level, $message, $data ) {
        $snippet = is_string( $data ) ? substr( $data, 0, 1000 ) : '';
        $line    = "[sync:{$sync_id}] {$message} -- data: {$snippet}";

        // The sync id is passed explicitly: this is the one call site that
        // always knows it, whether or not an entry point bound the run.
        Rental_File_Sync_Logger::write( $line, $level, 'log', $sync_id );
    }

    /**
     * Fallback when DB insert itself fails.
     */
    private static function fallback_log( $message ) {
        Rental_File_Sync_Logger::write( $message, 'error', 'log' );
    }
}
