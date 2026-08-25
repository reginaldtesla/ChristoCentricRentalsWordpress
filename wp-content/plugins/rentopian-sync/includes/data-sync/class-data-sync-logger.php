<?php
/**
 * Rental_Data_Sync_Logger
 *
 * Single logging entry point for the background data-sync module. Every
 * line goes to two destinations:
 *
 *   1. The unified wc-logs source `rentopian-data-sync`, so the module stays
 *      visible in WooCommerce → Status → Logs.
 *   2. A per-run file under `uploads/rentopian-data-sync/logs/`, which is
 *      what the admin panel tails, downloads and deletes. Keeping one file
 *      per run means the panel never scans a mixed daily file.
 *
 * Callers pass a short channel tag so a grep against the file follows a
 * single stage of the sync (rest, phase, write, sweep, report).
 *
 * The run file name carries a salt-derived hash, so it cannot be fetched
 * over HTTP by guessing the sync id.
 *
 * @package RentopianSync\DataSync
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Rental_Data_Sync_Logger {

    /**
     * Log source. One source for the whole module = one unified daily file.
     */
    const SOURCE = 'rentopian-data-sync';

    /**
     * Directory holding the per-run files, relative to the uploads base.
     */
    const LOG_SUBDIR = 'rentopian-data-sync/logs';

    /**
     * Prefix mixed into the run filename hash.
     */
    const LOG_HASH_PREFIX = 'rental-data-sync-log-';

    /**
     * Run whose file receives every write until it is rebound. Set by the
     * scheduler and the chunk worker so call sites deep in the phase engine
     * do not have to pass the sync id.
     *
     * @var string
     */
    private static $bound_run = '';

    /**
     * @var Rental_Run_Log_File|null
     */
    private static $files = null;

    /**
     * Per-run file store for this module.
     *
     * @return Rental_Run_Log_File
     */
    public static function files() {
        if ( null === self::$files ) {
            self::$files = new Rental_Run_Log_File( self::LOG_SUBDIR, self::LOG_HASH_PREFIX );
        }

        return self::$files;
    }

    /**
     * Bind subsequent writes to a run's file.
     *
     * @param string $sync_id Empty string unbinds.
     */
    public static function bind_run( $sync_id ) {
        self::$bound_run = (string) $sync_id;
    }

    /**
     * @return string Currently bound run id ('' when none).
     */
    public static function bound_run() {
        return self::$bound_run;
    }

    /**
     * Write one line to the unified data-sync log and, when a run is known,
     * to that run's own file.
     *
     * @param string $message
     * @param string $level   info | notice | warning | error | debug
     * @param string $channel Short stage tag (e.g. 'rest', 'phase', 'write').
     * @param string $sync_id Overrides the bound run for this line only.
     */
    public static function write( $message, $level = 'info', $channel = '', $sync_id = '' ) {
        $line = ( '' !== $channel ) ? '[' . $channel . '] ' . $message : $message;

        if ( class_exists( 'Project_WP_Logger', false ) ) {
            Project_WP_Logger::write( $line, $level, self::SOURCE );
        }

        $run = $sync_id ?: self::$bound_run;
        if ( '' !== $run ) {
            self::append_run_line( $run, $line, $level );
        }
    }

    /* ──────────────────────────────────────────────────────────
     * Per-run file
     * ────────────────────────────────────────────────────────── */

    /**
     * Absolute path of the directory holding per-run log files.
     *
     * @param bool $create
     * @return string '' when uploads are unavailable.
     */
    public static function logs_dir( $create = true ) {
        return self::files()->dir( $create );
    }

    /**
     * Absolute path of one run's log file.
     *
     * @param string $sync_id
     * @param bool   $create Create the directory when missing.
     * @return string '' when the directory is unavailable.
     */
    public static function run_log_path( $sync_id, $create = true ) {
        return self::files()->path( $sync_id, $create );
    }

    /**
     * @param string $sync_id
     * @return bool
     */
    public static function run_log_exists( $sync_id ) {
        return self::files()->exists( $sync_id );
    }

    /**
     * @param string $sync_id
     * @return int Size in bytes (0 when absent).
     */
    public static function run_log_size( $sync_id ) {
        return self::files()->size( $sync_id );
    }

    /**
     * Last N lines of a run's log, oldest first.
     *
     * @param string $sync_id
     * @param int    $lines Maximum lines to return.
     * @return array { lines: string[], total_bytes: int, truncated: bool }
     */
    public static function tail( $sync_id, $lines = 200 ) {
        return self::files()->tail( $sync_id, $lines );
    }

    /**
     * Delete one run's log file.
     *
     * @param string $sync_id
     * @return bool
     */
    public static function delete_run_log( $sync_id ) {
        return self::files()->delete( $sync_id );
    }

    /**
     * Append one already-formatted line to a run's file.
     *
     * @param string $sync_id
     * @param string $line
     * @param string $level
     */
    private static function append_run_line( $sync_id, $line, $level ) {
        self::files()->append( $sync_id, $line, $level );
    }
}
