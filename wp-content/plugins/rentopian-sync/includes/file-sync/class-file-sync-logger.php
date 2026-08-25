<?php
/**
 * Rental_File_Sync_Logger
 *
 * Single logging entry point for the background file-sync module. Every
 * line goes to two destinations:
 *
 *   1. The unified wc-logs source `rentopian-file-sync`, so the whole module
 *      lands in one daily file, listed in WooCommerce → Status → Logs.
 *   2. A per-run file under `uploads/rentopian-file-sync/logs/`, which is
 *      what the log panel tails and downloads. Keeping one file per run
 *      means the panel never scans a mixed daily file.
 *
 * Callers pass a short channel tag so a grep against the file follows a
 * single stage of the sync (chunk worker, uploader, downloader, attacher).
 *
 * The run file name carries a salt-derived hash, so it cannot be fetched
 * over HTTP by guessing the sync id.
 *
 * @package RentopianSync\FileSync
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Rental_File_Sync_Logger {

    /**
     * Log source. One source for the whole module = one unified daily file.
     */
    const SOURCE = 'rentopian-file-sync';

    /**
     * Directory holding the per-run files, relative to the uploads base.
     */
    const LOG_SUBDIR = 'rentopian-file-sync/logs';

    /**
     * Prefix mixed into the run filename hash.
     */
    const LOG_HASH_PREFIX = 'rental-file-sync-log-';

    /**
     * Run whose file receives every write until it is rebound. Set at the
     * entry points that know the sync id, so call sites deep in the
     * uploaders and downloaders do not have to pass it.
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
     * Write one line to the unified file-sync log and, when a run is known,
     * to that run's own file.
     *
     * @param string $message
     * @param string $level   info | notice | warning | error | debug
     * @param string $channel Short stage tag (e.g. 'chunk', 'download', 'attach').
     * @param string $sync_id Overrides the bound run for this line only.
     */
    public static function write( $message, $level = 'info', $channel = '', $sync_id = '' ) {
        $line = ( '' !== $channel ) ? '[' . $channel . '] ' . $message : $message;

        if ( class_exists( 'Project_WP_Logger', false ) ) {
            Project_WP_Logger::write( $line, $level, self::SOURCE );
        }

        $run = $sync_id ?: self::$bound_run;
        if ( '' !== $run ) {
            self::files()->append( $run, $line, $level );
        }
    }

    /**
     * Last N lines of a run's log, oldest first.
     *
     * @param string $sync_id
     * @param int    $lines
     * @return array { lines: string[], total_bytes: int, truncated: bool }
     */
    public static function tail( $sync_id, $lines = 200 ) {
        return self::files()->tail( $sync_id, $lines );
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
     * Delete one run's log file.
     *
     * @param string $sync_id
     * @return bool
     */
    public static function delete_run_log( $sync_id ) {
        return self::files()->delete( $sync_id );
    }
}
