<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Centralised logger for the file-sync-delete module.
 *
 * Writes directly to a predictable file under wp-content/uploads/wc-logs/.
 *
 * @package RentopianSync\FileSyncDelete
 */
class Rental_Delete_Logger {

    private static $source = 'rentopian-file-delete';

    /**
     * Write a log message.
     *
     * @param string $message
     * @param string $level  PSR-3 level (debug|info|notice|warning|error|critical)
     */
    public static function log( $message, $level = 'info' ) {
        self::write_to_file( $message, $level );
    }

    private static function write_to_file( $message, $level ) {
        $log_dir = self::get_log_dir();
        if ( ! $log_dir ) {
            error_log( sprintf( '[%s] [%s] %s', self::$source, $level, $message ) );
            return;
        }

        if ( ! is_dir( $log_dir ) ) {
            @mkdir( $log_dir, 0775, true );
        }

        $date     = gmdate( 'Y-m-d' );
        $filename = self::$source . '-' . $date . '.log';
        $filepath = rtrim( $log_dir, '/' ) . '/' . $filename;

        $timestamp = gmdate( 'Y-m-d\TH:i:s+00:00' );
        $line      = sprintf( "%s %s %s\n", $timestamp, strtoupper( $level ), trim( $message ) );

        $result = @file_put_contents( $filepath, $line, FILE_APPEND | LOCK_EX );

        if ( false === $result ) {
            error_log( sprintf( '[%s] [%s] %s (file write failed to: %s)', self::$source, $level, $message, $filepath ) );
        }
    }

    private static function get_log_dir() {
        if ( function_exists( 'wp_upload_dir' ) ) {
            $upload_dir = wp_upload_dir();
            if ( ! empty( $upload_dir['basedir'] ) ) {
                return rtrim( $upload_dir['basedir'], '/' ) . '/wc-logs';
            }
        }

        if ( defined( 'WP_CONTENT_DIR' ) ) {
            return rtrim( WP_CONTENT_DIR, '/' ) . '/uploads/wc-logs';
        }

        if ( defined( 'ABSPATH' ) ) {
            return rtrim( ABSPATH, '/' ) . '/wp-content/uploads/wc-logs';
        }

        return null;
    }
}
