<?php
if (! defined('ABSPATH')) exit;

/**
 * Helpers for managing failed rental images in DB table rental_failed_images
 * (uses $rental_tables['failed_images']).
 */

if (! function_exists('rental_failed_table_name')) {
    function rental_failed_table_name() {
        global $wpdb, $rental_tables;
        return $wpdb->prefix . $rental_tables['failed_images'];
    }
}

/**
 * Insert or increment a failed image record.
 *
 * @param int $rental_id  remote rental image id
 * @param string|null $sync_id optional sync id for attribution
 * @param string $error optional error message
 * @return bool true on success
 */
if (! function_exists('rental_failed_mark')) {
    function rental_failed_mark($rental_id, $sync_id = null, $error = '') {
        global $wpdb;

        $table = rental_failed_table_name();
        $now = time();

        // Try to update existing row (increment fail_count)
        $updated = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$table} SET fail_count = fail_count + 1, last_error = %s, last_failed_at = %d, sync_id = COALESCE(%s, sync_id) WHERE rental_id = %d",
                $error,
                $now,
                $sync_id !== null ? $wpdb->prepare('%s', $sync_id) : 'NULL',
                intval($rental_id)
            )
        );

        if ($updated === false) {
            // fallback: try insert (in case row missing)
            $inserted = $wpdb->insert($table, [
                'sync_id' => $sync_id,
                'rental_id' => intval($rental_id),
                'fail_count' => 1,
                'last_error' => $error,
                'first_failed_at' => $now,
                'last_failed_at' => $now,
                'resolved' => 0,
            ], ['%s','%d','%d','%s','%d','%d','%d']);

            return $inserted !== false;
        }

        // If update affected 0 rows, try insert (race condition)
        if ($updated === 0) {
            $inserted = $wpdb->insert($table, [
                'sync_id' => $sync_id,
                'rental_id' => intval($rental_id),
                'fail_count' => 1,
                'last_error' => $error,
                'first_failed_at' => $now,
                'last_failed_at' => $now,
                'resolved' => 0,
            ], ['%s','%d','%d','%s','%d','%d','%d']);

            return $inserted !== false;
        }

        return true;
    }
}

/**
 * Get failed rental ids for a sync (or all unresolved if $sync_id is null).
 * @param string|null $sync_id
 * @return array list of rental_id ints
 */
if (! function_exists('rental_failed_get_ids_by_sync')) {
    function rental_failed_get_ids_by_sync($sync_id = null) {
        global $wpdb;
        $table = rental_failed_table_name();

        $rows = [];
        if ($sync_id) {
            $rows = $wpdb->get_col($wpdb->prepare("SELECT rental_id FROM {$table} WHERE sync_id = %s AND resolved = 0", $sync_id));
        }
        return array_map('intval', $rows);
    }
}

/**
 * Remove succeeded IDs (mark resolved = 1). Accepts array of rental ids.
 */
if (! function_exists('rental_failed_mark_resolved')) {
    function rental_failed_mark_resolved(array $rental_ids = [], $resolved_by = 'system') {
        if (empty($rental_ids)) return true;

        global $wpdb;
        $table = rental_failed_table_name();

        // Build placeholders
        $placeholders = implode(',', array_fill(0, count($rental_ids), '%d'));
        $query = "UPDATE {$table} SET resolved = 1, resolved_at = %d WHERE rental_id IN ($placeholders)";
        $args = array_merge([time()], array_map('intval', $rental_ids));
        
        return $wpdb->query($wpdb->prepare($query, $args)) !== false;
    }
}

/**
 * Delete failed rows for a given sync (useful at sync start / cleanup).
 */
if (! function_exists('rental_failed_clear_for_sync')) {
    function rental_failed_clear_for_sync($sync_id) {
        global $wpdb;
        $table = rental_failed_table_name();
        return $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE sync_id = %s", $sync_id)) !== false;
    }
}

/**
 * Clear all unresolved failed ids (global reset)
 */
if (! function_exists('rental_failed_clear_all')) {
    function rental_failed_clear_all() {
        global $wpdb;
        $table = rental_failed_table_name();
        return $wpdb->query("DELETE FROM {$table} WHERE resolved = 0") !== false;
    }
}

/**
 * Fetch full rows for debugging if needed
 */
if (! function_exists('rental_failed_get_rows')) {
    function rental_failed_get_rows($limit = 100) {
        global $wpdb;
        $table = rental_failed_table_name();
        return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} ORDER BY last_failed_at DESC LIMIT %d", intval($limit)));
    }
}

/**
 * Return paginated rental_ids for a given sync_id (unresolved only).
 *
 * @param string $sync_id
 * @param int $page 1-based
 * @param int $per_page
 * @return array ['ids' => [..], 'total' => N, 'page' => p, 'per_page' => k, 'total_pages' => m]
 */
if (! function_exists('rental_failed_get_ids_for_sync_paginated')) {
    function rental_failed_get_ids_for_sync_paginated($sync_id, $page = 1, $per_page = 50) {
        global $wpdb;
        $table = rental_failed_table_name();

        $page = max(1, intval($page));
        $per_page = max(1, intval($per_page));
        $offset = ($page - 1) * $per_page;

        // total count for this sync
        $sql_count = $wpdb->prepare("SELECT COUNT(*) AS c FROM {$table} WHERE sync_id = %s AND resolved = 0", $sync_id);
        $row = $wpdb->get_row($sql_count);
        $total = intval($row->c ?? 0);

        // get the ids
        $sql = $wpdb->prepare("SELECT rental_id FROM {$table} WHERE sync_id = %s AND resolved = 0 ORDER BY last_failed_at DESC LIMIT %d OFFSET %d", $sync_id, $per_page, $offset);
        $rows = $wpdb->get_col($sql);

        $total_pages = $per_page ? (int) ceil($total / $per_page) : 1;

        return [
            'ids' => array_map('intval', $rows ?: []),
            'total' => $total,
            'page' => $page,
            'per_page' => $per_page,
            'total_pages' => $total_pages
        ];
    }
}
