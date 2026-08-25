<?php
/**
 * Data Sync Log Repository
 *
 * Encapsulates all database operations against the `rental_data_sync_log`
 * table and mirrors every row into the unified wc-logs file.
 *
 * @package RentopianSync\DataSync
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Rental_Data_Sync_Log_Repository {

    const DB_VERSION = '1';

    /**
     * @return string Fully-prefixed table name.
     */
    private static function table() {
        global $wpdb;
        return $wpdb->prefix . 'rental_data_sync_log';
    }

    /**
     * Create the log table when missing (version-guarded).
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
            level VARCHAR(20) NOT NULL DEFAULT 'info',
            status TINYINT(3) NOT NULL DEFAULT 2,
            phase TINYINT(3) NOT NULL DEFAULT 0,
            message TEXT NULL,
            data LONGTEXT NULL,
            cursor_pos BIGINT UNSIGNED NOT NULL DEFAULT 0,
            processed_count INT UNSIGNED NOT NULL DEFAULT 0,
            elapsed FLOAT NOT NULL DEFAULT 0,
            register_time INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY  (id),
            KEY sync_id (sync_id)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );

        // Stamp the version only when the table truly exists, so a silent
        // dbDelta failure retries on the next request instead of hiding.
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table ) {
            update_option( 'rental_data_sync_db_version', self::DB_VERSION );
        } else {
            Rental_Data_Sync_Logger::write( 'Log table creation failed: ' . $wpdb->last_error, 'error', 'log' );
        }
    }

    /**
     * Write one log row and mirror it to wc-logs.
     *
     * @param string       $sync_id
     * @param string       $level
     * @param string       $message
     * @param array|string $data
     * @param int          $cursor
     * @param int          $processed_count
     * @param float        $elapsed
     * @param int          $status
     * @param int          $phase
     * @return int Inserted row id (0 on failure).
     */
    public static function write( $sync_id, $level, $message, $data = '', $cursor = 0, $processed_count = 0, $elapsed = 0.0, $status = 2, $phase = 0 ) {
        global $wpdb;

        if ( is_array( $data ) || is_object( $data ) ) {
            $data = wp_json_encode( $data );
        } elseif ( $data === null ) {
            $data = '';
        } else {
            $data = (string) $data;
        }

        $inserted = $wpdb->insert(
            self::table(),
            [
                'sync_id'         => substr( (string) $sync_id, 0, 64 ),
                'level'           => sanitize_key( $level ),
                'status'          => (int) $status,
                'phase'           => (int) $phase,
                'message'         => $message,
                'data'            => $data,
                'cursor_pos'      => (int) $cursor,
                'processed_count' => (int) $processed_count,
                'elapsed'         => (float) $elapsed,
                'register_time'   => time(),
            ],
            [ '%s', '%s', '%d', '%d', '%s', '%s', '%d', '%d', '%f', '%d' ]
        );

        $snippet = is_string( $data ) ? substr( $data, 0, 1000 ) : '';
        $label   = Rental_Data_Sync_Status::phase_label( $phase );
        Rental_Data_Sync_Logger::write( "[sync:{$sync_id}] [{$label}] {$message}" . ( $snippet !== '' ? " -- data: {$snippet}" : '' ), $level, 'log' );

        if ( $inserted === false ) {
            Rental_Data_Sync_Logger::write( "Failed to insert data-sync log row: {$wpdb->last_error}", 'error', 'log' );
            return 0;
        }

        return (int) $wpdb->insert_id;
    }

    /**
     * Grouped run list (one row per sync run), newest first.
     *
     * @param int $page
     * @param int $per_page
     * @return array
     */
    public static function get_grouped_page( $page = 1, $per_page = 10 ) {
        global $wpdb;
        $table = self::table();

        $page     = max( 1, (int) $page );
        $per_page = max( 1, (int) $per_page );
        $offset   = ( $page - 1 ) * $per_page;

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT sync_id,
                    MIN(register_time) AS started_at,
                    MAX(register_time) AS last_at,
                    COUNT(*)           AS entries_count,
                    MAX(phase)         AS max_phase,
                    MAX(processed_count) AS processed_count,
                    MAX(status)        AS max_status
             FROM {$table}
             GROUP BY sync_id
             ORDER BY MAX(id) DESC
             LIMIT %d OFFSET %d",
            $per_page,
            $offset
        ) );
    }

    /**
     * @return int Number of distinct runs.
     */
    public static function get_grouped_count() {
        global $wpdb;
        return (int) $wpdb->get_var( 'SELECT COUNT(DISTINCT sync_id) FROM ' . self::table() );
    }

    /**
     * Paginated entries for one run.
     *
     * @param string $sync_id
     * @param int    $page
     * @param int    $per_page
     * @return array { entries, total, page, per_page, total_pages }
     */
    public static function get_entries_paginated( $sync_id, $page = 1, $per_page = 20 ) {
        global $wpdb;
        $table = self::table();

        $page     = max( 1, (int) $page );
        $per_page = max( 1, (int) $per_page );
        $offset   = ( $page - 1 ) * $per_page;

        $total = (int) $wpdb->get_var(
            $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE sync_id = %s", $sync_id )
        );

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE sync_id = %s ORDER BY id ASC LIMIT %d OFFSET %d",
            $sync_id,
            $per_page,
            $offset
        ) );

        $entries = [];
        foreach ( $rows as $e ) {
            $decoded = null;
            if ( $e->data ) {
                $decoded = json_decode( $e->data, true );
                if ( $decoded === null ) {
                    $decoded = $e->data;
                }
            }
            $entries[] = [
                'id'              => (int) $e->id,
                'level'           => esc_html( $e->level ),
                'phase'           => Rental_Data_Sync_Status::phase_label( (int) $e->phase ),
                'message'         => esc_html( $e->message ),
                'data'            => $decoded,
                'cursor'          => (int) $e->cursor_pos,
                'processed_count' => (int) $e->processed_count,
                'register_time'   => (int) $e->register_time,
            ];
        }

        return [
            'entries'     => $entries,
            'total'       => $total,
            'page'        => $page,
            'per_page'    => $per_page,
            'total_pages' => $per_page ? (int) ceil( $total / $per_page ) : 1,
        ];
    }

    /**
     * Delete all rows for one run.
     *
     * @param string $sync_id
     * @return int|false
     */
    public static function delete_by_sync( $sync_id ) {
        global $wpdb;
        return $wpdb->delete( self::table(), [ 'sync_id' => $sync_id ], [ '%s' ] );
    }
}
