<?php
/**
 * Data Sync Run Repository
 *
 * One database row per background sync run — status and timings only.
 * The line-by-line trace of every phase lives in the per-run file log
 * (`Rental_Data_Sync_Logger`), so the table stays small and the admin panel
 * can list hundreds of runs without loading their contents.
 *
 * @package RentopianSync\DataSync
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Rental_Data_Sync_Run_Repository {

    const DB_VERSION = '2';

    /**
     * @return string Fully-prefixed run table name.
     */
    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'rental_data_sync_run';
    }

    /**
     * Create the run table when missing (version-guarded).
     */
    public static function install() {
        if ( get_option( 'rental_data_sync_db_version' ) === self::DB_VERSION ) {
            return;
        }

        global $wpdb;
        $table   = self::table();
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            sync_id VARCHAR(64) NOT NULL,
            status TINYINT(3) NOT NULL DEFAULT 1,
            phase TINYINT(3) NOT NULL DEFAULT 0,
            processed_count INT UNSIGNED NOT NULL DEFAULT 0,
            failed_count INT UNSIGNED NOT NULL DEFAULT 0,
            message TEXT NULL,
            file_sync_id VARCHAR(64) NOT NULL DEFAULT '',
            started_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
            started_at DATETIME NULL DEFAULT NULL,
            updated_at DATETIME NULL DEFAULT NULL,
            finished_at DATETIME NULL DEFAULT NULL,
            duration INT UNSIGNED NOT NULL DEFAULT 0,
            register_time INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY  (id),
            UNIQUE KEY sync_id (sync_id),
            KEY register_time (register_time)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );

        // Stamp the version only when the table truly exists, so a silent
        // dbDelta failure retries on the next request instead of hiding.
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            Rental_Data_Sync_Logger::write( 'Run table creation failed: ' . $wpdb->last_error, 'error', 'log' );
            return;
        }

        self::drop_legacy_line_table();

        update_option( 'rental_data_sync_db_version', self::DB_VERSION );
    }

    /**
     * The previous schema stored one row per phase step. That trace now
     * lives in the per-run file log, so the table is removed rather than
     * left to grow unused.
     */
    private static function drop_legacy_line_table() {
        global $wpdb;

        $legacy = $wpdb->prefix . 'rental_data_sync_log';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $legacy ) ) !== $legacy ) {
            return;
        }

        $wpdb->query( "DROP TABLE {$legacy}" );
        Rental_Data_Sync_Logger::write( 'Removed the per-line log table; run detail now lives in the per-run log file', 'info', 'log' );
    }

    /**
     * @return bool Whether the run table is available.
     */
    public static function is_installed() {
        global $wpdb;
        $table = self::table();
        return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
    }

    /* ──────────────────────────────────────────────────────────
     * Writes
     * ────────────────────────────────────────────────────────── */

    /**
     * Record the start of a run.
     *
     * @param string $sync_id
     * @param string $message Opening note.
     * @return int Row id (0 on failure).
     */
    public static function start( $sync_id, $message = '' ) {
        global $wpdb;

        self::install();

        $now = current_time( 'mysql' );

        $wpdb->replace(
            self::table(),
            [
                'sync_id'       => substr( (string) $sync_id, 0, 64 ),
                'status'        => Rental_Data_Sync_Status::STATUS_CREATED,
                'phase'         => Rental_Data_Sync_Status::PHASE_CONFIG,
                'message'       => (string) $message,
                'started_by'    => get_current_user_id(),
                'started_at'    => $now,
                'updated_at'    => $now,
                'register_time' => time(),
            ],
            [ '%s', '%d', '%d', '%s', '%d', '%s', '%s', '%d' ]
        );

        return (int) $wpdb->insert_id;
    }

    /**
     * Refresh the live counters of a running row. Cheap enough to call once
     * per chunk.
     *
     * @param string $sync_id
     * @param array  $fields status | phase | processed_count | failed_count | message
     */
    public static function touch( $sync_id, array $fields ) {
        global $wpdb;

        $allowed = [
            'status'          => '%d',
            'phase'           => '%d',
            'processed_count' => '%d',
            'failed_count'    => '%d',
            'file_sync_id'    => '%s',
            'message'         => '%s',
        ];

        $data   = [];
        $format = [];
        foreach ( $allowed as $key => $fmt ) {
            if ( array_key_exists( $key, $fields ) ) {
                $data[ $key ] = ( '%d' === $fmt ) ? (int) $fields[ $key ] : (string) $fields[ $key ];
                $format[]     = $fmt;
            }
        }

        if ( ! $data ) {
            return;
        }

        $data['updated_at'] = current_time( 'mysql' );
        $format[]           = '%s';

        $wpdb->update( self::table(), $data, [ 'sync_id' => $sync_id ], $format, [ '%s' ] );
    }

    /**
     * Close a run: stamp the finish time, duration and final status.
     *
     * @param string $sync_id
     * @param int    $status  STATUS_COMPLETED | STATUS_FAILED | STATUS_CANCELED
     * @param string $message Outcome summary or failure reason.
     * @param array  $fields  Optional extra columns (processed_count, …).
     */
    public static function finish( $sync_id, $status, $message = '', array $fields = [] ) {
        global $wpdb;

        $row = self::get( $sync_id );
        $now = current_time( 'mysql' );

        $started  = ( $row && ! empty( $row['started_at'] ) ) ? strtotime( $row['started_at'] ) : 0;
        $duration = $started ? max( 0, strtotime( $now ) - $started ) : 0;

        $data = array_merge( $fields, [
            'status'      => (int) $status,
            'message'     => (string) $message,
            'updated_at'  => $now,
            'finished_at' => $now,
            'duration'    => $duration,
        ] );

        $format = [];
        foreach ( $data as $key => $value ) {
            $format[] = in_array( $key, [ 'status', 'phase', 'processed_count', 'failed_count', 'duration' ], true ) ? '%d' : '%s';
        }

        $wpdb->update( self::table(), $data, [ 'sync_id' => $sync_id ], $format, [ '%s' ] );
    }

    /**
     * Attach the chained file sync to a run record.
     *
     * @param string $sync_id
     * @param string $file_sync_id
     */
    public static function set_file_sync( $sync_id, $file_sync_id ) {
        self::touch( $sync_id, [ 'file_sync_id' => (string) $file_sync_id ] );
    }

    /**
     * Clear the finish stamp of a run that turned out not to be over, so it
     * stops counting as the last completed run while it is still going.
     *
     * @param string $sync_id
     */
    public static function reopen( $sync_id ) {
        global $wpdb;

        if ( ! self::is_installed() ) {
            return;
        }

        $wpdb->query( $wpdb->prepare(
            'UPDATE ' . self::table() . ' SET finished_at = NULL, duration = 0 WHERE sync_id = %s',
            $sync_id
        ) );
    }

    /* ──────────────────────────────────────────────────────────
     * Reads
     * ────────────────────────────────────────────────────────── */

    /**
     * @param string $sync_id
     * @return array|null
     */
    public static function get( $sync_id ) {
        global $wpdb;

        if ( ! $sync_id || ! self::is_installed() ) {
            return null;
        }

        $row = $wpdb->get_row(
            $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE sync_id = %s', $sync_id ),
            ARRAY_A
        );

        return $row ? self::decorate( $row ) : null;
    }

    /**
     * Newest runs first.
     *
     * @param int $page
     * @param int $per_page
     * @return array[]
     */
    public static function get_page( $page = 1, $per_page = 10 ) {
        global $wpdb;

        if ( ! self::is_installed() ) {
            return [];
        }

        $page     = max( 1, (int) $page );
        $per_page = max( 1, (int) $per_page );

        $rows = $wpdb->get_results( $wpdb->prepare(
            'SELECT * FROM ' . self::table() . ' ORDER BY id DESC LIMIT %d OFFSET %d',
            $per_page,
            ( $page - 1 ) * $per_page
        ), ARRAY_A );

        return array_map( [ __CLASS__, 'decorate' ], (array) $rows );
    }

    /**
     * @return int Total number of recorded runs.
     */
    public static function count() {
        global $wpdb;
        return self::is_installed() ? (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::table() ) : 0;
    }

    /**
     * The most recent run that reached a terminal state, whatever it was.
     * Drives the "last synchronization" line in the admin panel, so a
     * failure is reported as plainly as a success.
     *
     * @return array|null
     */
    public static function last_finished() {
        global $wpdb;

        if ( ! self::is_installed() ) {
            return null;
        }

        $row = $wpdb->get_row(
            'SELECT * FROM ' . self::table() . ' WHERE finished_at IS NOT NULL ORDER BY id DESC LIMIT 1',
            ARRAY_A
        );

        return $row ? self::decorate( $row ) : null;
    }

    /**
     * Delete one run record together with its log file.
     *
     * @param string $sync_id
     * @return bool
     */
    public static function delete( $sync_id ) {
        global $wpdb;

        if ( ! $sync_id ) {
            return false;
        }

        Rental_Data_Sync_Logger::delete_run_log( $sync_id );

        if ( ! self::is_installed() ) {
            return false;
        }

        return false !== $wpdb->delete( self::table(), [ 'sync_id' => $sync_id ], [ '%s' ] );
    }

    /* ──────────────────────────────────────────────────────────
     * Internals
     * ────────────────────────────────────────────────────────── */

    /**
     * Add the derived fields the admin panel renders.
     *
     * @param array $row
     * @return array
     */
    private static function decorate( array $row ) {
        $status = (int) $row['status'];

        $row['id']              = (int) $row['id'];
        $row['status']          = $status;
        $row['phase']           = (int) $row['phase'];
        $row['processed_count'] = (int) $row['processed_count'];
        $row['failed_count']    = (int) $row['failed_count'];
        $row['duration']        = (int) $row['duration'];
        $row['register_time']   = (int) $row['register_time'];
        $row['status_label']    = self::status_label( $status );
        $row['phase_label']     = Rental_Data_Sync_Status::phase_label( $row['phase'] );
        $row['is_running']      = in_array( $status, [ Rental_Data_Sync_Status::STATUS_CREATED, Rental_Data_Sync_Status::STATUS_PROCESSING ], true );
        $row['has_log']         = Rental_Data_Sync_Logger::run_log_exists( $row['sync_id'] );
        $row['log_size']        = Rental_Data_Sync_Logger::run_log_size( $row['sync_id'] );

        return $row;
    }

    /**
     * @param int $status
     * @return string
     */
    public static function status_label( $status ) {
        $map = [
            Rental_Data_Sync_Status::STATUS_CREATED    => 'created',
            Rental_Data_Sync_Status::STATUS_PROCESSING => 'processing',
            Rental_Data_Sync_Status::STATUS_COMPLETED  => 'completed',
            Rental_Data_Sync_Status::STATUS_FAILED     => 'failed',
            Rental_Data_Sync_Status::STATUS_CANCELED   => 'canceled',
        ];

        return isset( $map[ (int) $status ] ) ? $map[ (int) $status ] : 'unknown';
    }
}
