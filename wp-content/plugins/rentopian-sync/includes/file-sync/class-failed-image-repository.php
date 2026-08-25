<?php
/**
 * Failed Image Repository
 *
 * Manages the `rental_failed_images` custom table that tracks which rental
 * images failed to download/upload during a background sync.
 *
 *
 * @package RentopianSync\FileSync
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Rental_Failed_Image_Repository {

    /**
     * @return string Fully-prefixed table name.
     */
    private static function table() {
        global $wpdb, $rental_tables;
        return $wpdb->prefix . $rental_tables['failed_images'];
    }

    /* ──────────────────────────────────────────────────────────
     * Mark / Resolve
     * ────────────────────────────────────────────────────────── */

    /**
     * Insert or increment a failed image record.
     *
     * @param int         $rental_id Remote rental image id.
     * @param string|null $sync_id   Optional sync id for attribution.
     * @param string      $error     Optional error message.
     * @return bool
     */
    public static function mark( $rental_id, $sync_id = null, $error = '' ) {
        global $wpdb;
        $table      = self::table();
        $now        = time();
        $rental_id  = intval( $rental_id );

        // Try UPDATE first (increment fail_count)
        if ( $sync_id !== null ) {
            $updated = $wpdb->query( $wpdb->prepare(
                "UPDATE {$table}
                 SET fail_count = fail_count + 1,
                     last_error = %s,
                     last_failed_at = %d,
                     sync_id = %s
                 WHERE rental_id = %d",
                $error,
                $now,
                $sync_id,
                $rental_id
            ) );
        } else {
            $updated = $wpdb->query( $wpdb->prepare(
                "UPDATE {$table}
                 SET fail_count = fail_count + 1,
                     last_error = %s,
                     last_failed_at = %d
                 WHERE rental_id = %d",
                $error,
                $now,
                $rental_id
            ) );
        }

        // INSERT if row doesn't exist yet
        if ( $updated === false || $updated === 0 ) {
            $inserted = $wpdb->insert( $table, [
                'sync_id'         => $sync_id,
                'rental_id'       => $rental_id,
                'fail_count'      => 1,
                'last_error'      => $error,
                'first_failed_at' => $now,
                'last_failed_at'  => $now,
                'resolved'        => 0,
            ], [ '%s', '%d', '%d', '%s', '%d', '%d', '%d' ] );

            return $inserted !== false;
        }

        return true;
    }

    /**
     * Get unresolved failed rental ids for a sync.
     *
     * @param string|null $sync_id
     * @return int[]
     */
    public static function get_ids_by_sync( $sync_id = null ) {
        global $wpdb;
        $table = self::table();

        if ( ! $sync_id ) {
            return [];
        }

        $rows = $wpdb->get_col( $wpdb->prepare(
            "SELECT rental_id FROM {$table} WHERE sync_id = %s AND resolved = 0",
            $sync_id
        ) );

        return array_map( 'intval', $rows );
    }

    /**
     * Get unresolved failed rental ids that haven't exceeded max retry attempts.
     *
     * BUG FIX: Without this cap, images that fail with permanent errors
     * (e.g. "Forbidden" from bad signed URLs) get retried infinitely.
     * Each retry increments fail_count; once it exceeds $max_attempts,
     * the image is excluded from automatic retries.
     *
     * @param string   $sync_id
     * @param int      $max_attempts  Maximum fail_count before giving up. Default 3.
     * @return int[]
     */
    public static function get_retryable_ids( $sync_id, $max_attempts = 3 ) {
        global $wpdb;
        $table = self::table();

        if ( ! $sync_id ) {
            return [];
        }

        $rows = $wpdb->get_col( $wpdb->prepare(
            "SELECT rental_id FROM {$table} WHERE sync_id = %s AND resolved = 0 AND fail_count <= %d",
            $sync_id,
            intval( $max_attempts )
        ) );

        return array_map( 'intval', $rows );
    }

    /**
     * Mark given rental ids as resolved.
     *
     * @param int[]  $rental_ids
     * @param string $resolved_by
     * @return bool
     */
    public static function mark_resolved( array $rental_ids = [], $resolved_by = 'system' ) {
        if ( empty( $rental_ids ) ) {
            return true;
        }

        global $wpdb;
        $table = self::table();

        $placeholders = implode( ',', array_fill( 0, count( $rental_ids ), '%d' ) );
        $args         = array_merge( [ time() ], array_map( 'intval', $rental_ids ) );

        return $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$table} SET resolved = 1, resolved_at = %d WHERE rental_id IN ({$placeholders})",
                $args
            )
        ) !== false;
    }

    /**
     * Delete all failed rows for a given sync (used at sync start).
     *
     * @param string $sync_id
     * @return bool
     */
    public static function clear_for_sync( $sync_id ) {
        global $wpdb;
        $table = self::table();
        return $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE sync_id = %s", $sync_id ) ) !== false;
    }

    /**
     * Delete all unresolved failed images (global reset).
     *
     * @return bool
     */
    public static function clear_all() {
        global $wpdb;
        $table = self::table();
        return $wpdb->query( "DELETE FROM {$table} WHERE resolved = 0" ) !== false;
    }

    /**
     * Paginated rental ids for a sync (for admin UI / REST retry).
     *
     * @param string $sync_id
     * @param int    $page     1-based
     * @param int    $per_page
     * @return array { ids, total, page, per_page, total_pages }
     */
    public static function get_ids_paginated( $sync_id, $page = 1, $per_page = 50 ) {
        global $wpdb;
        $table = self::table();

        $page     = max( 1, intval( $page ) );
        $per_page = max( 1, intval( $per_page ) );
        $offset   = ( $page - 1 ) * $per_page;

        $total = intval( $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE sync_id = %s AND resolved = 0",
            $sync_id
        ) ) );

        $rows = $wpdb->get_col( $wpdb->prepare(
            "SELECT rental_id FROM {$table} WHERE sync_id = %s AND resolved = 0 ORDER BY last_failed_at DESC LIMIT %d OFFSET %d",
            $sync_id,
            $per_page,
            $offset
        ) );

        return [
            'ids'         => array_map( 'intval', $rows ?: [] ),
            'total'       => $total,
            'page'        => $page,
            'per_page'    => $per_page,
            'total_pages' => $per_page ? (int) ceil( $total / $per_page ) : 1,
        ];
    }

    /**
     * Fetch full rows for debugging.
     *
     * @param int $limit
     * @return array
     */
    public static function get_rows( $limit = 100 ) {
        global $wpdb;
        $table = self::table();
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$table} ORDER BY last_failed_at DESC LIMIT %d",
            intval( $limit )
        ) );
    }
}
