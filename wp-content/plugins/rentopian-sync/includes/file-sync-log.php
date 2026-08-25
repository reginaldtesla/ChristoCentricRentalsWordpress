<?php

if (! function_exists('rental_file_sync_log_write')) {

    /**
     * Write a file-sync log row.
     *
     * @param string $sync_id UUID/string for this sync run.
     * @param string $level 'info'|'warning'|'error'
     * @param string $message informative message (not system message)
     * @param array|string $data array or string (will be json_encoded if array)
     * @param int $last_index
     * @param int $processed_count
     * @param int $total_count
     * @param float $elapsed seconds
     * @return int inserted row id
     */
    function rental_file_sync_log_write($sync_id, $level, $message, $data = '', $last_index = 0, $processed_count = 0, $total_count = 0, $elapsed = 0.0, $status = 2, $mode = 1) { // status:2 = PROCESSING, mode:1 = SYNC
        global $wpdb;

        $table = $wpdb->prefix . 'rental_file_sync_log';

        if (is_array($data) || is_object($data)) {
            $data = wp_json_encode($data);
        } else if ($data === null) {
            $data = '';
        } else {
            // keep scalar as-is
            $data = (string)$data;
        }

        $row = [
            'sync_id' => substr($sync_id, 0, 64),
            'level' => sanitize_key($level),
            'status' => intval($status),
            'mode' => intval($mode),
            'message' => $message,
            'data' => $data,
            'last_index' => intval($last_index),
            'processed_count' => intval($processed_count),
            'total_count' => intval($total_count),
            'elapsed' => floatval($elapsed),
            'register_time' => time()
        ];

        $format = ['%s', '%s', '%d', '%d', '%s', '%s', '%d', '%d', '%d', '%f', '%d'];

        $inserted = $wpdb->insert($table, $row, $format);
        if ($inserted === false) {
            // fallback to WP logger if DB insert failed

            Project_WP_Logger::write("Failed to insert file-sync log: " . $wpdb->last_error . " -- msg: $message", 'error', 'rentopian-sync', 'logs/file_bg_sync.log');
            Project_WP_Logger::write("Failed to insert file-sync log: " . $wpdb->last_error . " -- msg: $message", 'error', 'rentopian-sync'); // write to WC logger as well
           
           return 0;
        }

        $id = (int)$wpdb->insert_id;

        // Also write to Project_WP_Logger for easier immediate debugging in files/WC logs:
        Project_WP_Logger::write("[sync:$sync_id] $level: $message -- data: " . substr($data,0,1000), $level, 'rentopian-sync', 'logs/file_bg_sync.log');
        Project_WP_Logger::write("[sync:$sync_id] $level: $message -- data: " . substr($data,0,1000), $level, 'rentopian-sync'); // write to WC logger as well

        return $id;
    }
}

if (! function_exists('rental_file_sync_log_get_grouped')) {
    /**
     * Get a grouped list of sync runs (one row per sync_id) with summary data.
     * Returns array of objects: sync_id, started_at (min), last_at (max), entries_count, processed_count (max), total_count (max)
     *
     * @param int $limit
     * @param int $offset
     * @return array
     */
    function rental_file_sync_log_get_grouped($limit = 20, $offset = 0) {
        global $wpdb;
        $table = $wpdb->prefix . 'rental_file_sync_log';

        // Group by sync_id, get earliest & latest register_time and counts
        $sql = $wpdb->prepare("
            SELECT
                sync_id,
                mode,
                MIN(register_time) AS started_at,
                MAX(register_time) AS last_at,
                COUNT(*) AS entries_count,
                MAX(processed_count) AS processed_count,
                MAX(total_count) AS total_count
            FROM $table
            GROUP BY sync_id
            ORDER BY id DESC
            LIMIT %d OFFSET %d
        ", $limit, $offset);
        
        return $wpdb->get_results($sql);

    }
}

if (! function_exists('rental_file_sync_log_get_entries')) {
    /**
     * Return entries for a given sync_id
     *
     * @param string $sync_id
     * @return array rows
     */
    function rental_file_sync_log_get_entries($sync_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'rental_file_sync_log';

        $sql = $wpdb->prepare("SELECT * FROM $table WHERE sync_id = %s ORDER BY register_time ASC", $sync_id);
        return $wpdb->get_results($sql);
    }
}


/**
 * Return number of distinct sync runs (grouped by sync_id).
 *
 * @return int
 */
function rental_file_sync_log_get_grouped_count() {
    global $wpdb;
    $table = $wpdb->prefix . 'rental_file_sync_log';

    // Count distinct sync_id
    $sql = "SELECT COUNT(DISTINCT sync_id) as c FROM $table";
    $row = $wpdb->get_row($sql);
    return intval($row->c ?? 0);
}

/**
 * Return a page of grouped sync runs (same object format as rental_file_sync_log_get_grouped).
 *
 * @param int $page 1-based page number
 * @param int $per_page
 * @return array
 */
function rental_file_sync_log_get_grouped_page($page = 1, $per_page = 20) {
    global $wpdb;
    $table = $wpdb->prefix . 'rental_file_sync_log';

    $page = max(1, intval($page));
    $per_page = max(1, intval($per_page));
    $offset = ($page - 1) * $per_page;

    // We use the same grouping query, but with LIMIT/OFFSET
    $sql = $wpdb->prepare("
        SELECT
            sync_id,
            MAX(mode) AS mode,
            MIN(register_time) AS started_at,
            MAX(register_time) AS last_at,
            COUNT(*) AS entries_count,
            MAX(processed_count) AS processed_count,
            MAX(total_count) AS total_count
        FROM $table
        GROUP BY sync_id
        ORDER BY id DESC
        LIMIT %d OFFSET %d
    ", $per_page, $offset);

    return $wpdb->get_results($sql);
}

/**
 * Return paginated entries for a given sync_id.
 *
 * @param string $sync_id
 * @param int $page (1-based)
 * @param int $per_page
 * @return array ['entries' => [...], 'total' => N, 'page' => p, 'per_page' => k, 'total_pages' => m]
 */
function rental_file_sync_log_get_entries_paginated($sync_id, $page = 1, $per_page = 20) {
    global $wpdb;
    $table = $wpdb->prefix . 'rental_file_sync_log';

    $page = max(1, intval($page));
    $per_page = max(1, intval($per_page));
    $offset = ($page - 1) * $per_page;

    // total count
    $sql_count = $wpdb->prepare("SELECT COUNT(*) AS c FROM $table WHERE sync_id = %s", $sync_id);
    $row = $wpdb->get_row($sql_count);
    $total = intval($row->c ?? 0);

    $sql = $wpdb->prepare("SELECT * FROM $table WHERE sync_id = %s ORDER BY register_time ASC LIMIT %d OFFSET %d", $sync_id, $per_page, $offset);
    $rows = $wpdb->get_results($sql);

    // map to same arrays as existing rental_get_file_sync_details expects
    $out = [];
    foreach ($rows as $e) {
        $data = $e->data;
        $decoded = null;
        if ($data) {
            $decoded = json_decode($data, true);
            if ($decoded === null) $decoded = $data;
        }
        $out[] = [
            'id' => intval($e->id),
            'level' => esc_html($e->level),
            'message' => esc_html($e->message),
            'data' => $decoded,
            'processed_count' => intval($e->processed_count),
            'total_count' => intval($e->total_count),
            'last_index' => intval($e->last_index),
            'register_time' => intval($e->register_time)
        ];
    }

    $total_pages = $per_page ? (int) ceil($total / $per_page) : 1;

    return [
        'entries' => $out,
        'total' => $total,
        'page' => $page,
        'per_page' => $per_page,
        'total_pages' => $total_pages
    ];
}


if (! function_exists('rental_file_sync_log_get_last_elapsed')) {
    function rental_file_sync_log_get_last_elapsed($sync_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'rental_file_sync_log';
        $val = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT elapsed FROM `$table` WHERE sync_id = %s AND elapsed > 0 ORDER BY id DESC LIMIT 1",
                $sync_id
            )
        );
        return is_null($val) ? null : (float)$val;
    }
}

if (! function_exists('rental_file_sync_log_had_recent_error')) {
    function rental_file_sync_log_had_recent_error($sync_id, $seconds = 900) {
        global $wpdb;
        $table = $wpdb->prefix . 'rental_file_sync_log';
        $since = time() - (int)$seconds;
        $has = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT 1 FROM `$table`
                 WHERE sync_id = %s AND level = 'error' AND register_time >= %d
                 ORDER BY id DESC LIMIT 1",
                $sync_id, $since
            )
        );
        return (bool)$has;
    }
}


if ( !function_exists('rental_get_last_completed_sync_session')) {
    function rental_get_last_completed_sync_session() {
        global $wpdb;

        $table = $wpdb->prefix . 'rental_file_sync_log';

        $status_completed = (int) Rentopian_Sync_REST::STATUS_COMPLETED;   // Status:3 = Completed
        $mode_sync = (int) Rentopian_Sync_REST::MODE_SYNC;   // mode:1 = SYNC
      
        $row = $wpdb->get_row("
            SELECT sync_id, processed_count, total_count, status, mode
            FROM {$table}
            WHERE status = {$status_completed}
                AND mode = {$mode_sync}
                AND processed_count <> 0
                AND total_count <> 0
                AND processed_count >= total_count
            ORDER BY id DESC
            LIMIT 1
        ");

        if ($row && !empty($row->sync_id)) {

            return [
                'sync_id'         => $row->sync_id,
                'mode'         => $row->mode,
                'processed_count' => (int) $row->processed_count,
                'total_count'     => (int) $row->total_count,
                'status'          => (int) $row->status,
            ];
        }

        return null;
    }
}


/**
 * Compute a safe resume cursor. Resume can be triggered on both Sync and Re-Sync processes
 * Priority: per-sync option -> logs -> relations table -> 0
 */
function rental_compute_resume_index(string $sync_id): int {
    global $wpdb, $rental_tables;

    // Per-sync option
    $opt = get_option("rental_products_img_last_id_{$sync_id}", 0);
    $opt = intval($opt);
    if ($opt > 0) return $opt;

    $status_completed = Rentopian_Sync_REST::STATUS_COMPLETED;

    // From logs snapshot
    $table = $wpdb->prefix . 'rental_file_sync_log';
    $last_index = intval($wpdb->get_var($wpdb->prepare("
        SELECT last_index
        FROM {$table}
        WHERE sync_id = %s
           AND processed_count < total_count
           AND status <> %d
        ORDER BY id DESC 
        LIMIT 1
    ", $sync_id, Rentopian_Sync_REST::STATUS_COMPLETED)));

    if ($last_index > 0) return $last_index;

    // Relations table (already mapped attachments)
    $rel = $wpdb->prefix . ($rental_tables['image_relations'] ?? 'rental_image_relations');
    if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $rel)) === $rel) {
        $max_id = intval($wpdb->get_var("SELECT MAX(rental_id) FROM {$rel}"));
        if ($max_id > 0) return $max_id;
    }

    return 0;
}


/** Ensure session object is aligned and marked PROCESSING, mint token, clear locks. */
function rental_prepare_session_for_resume(string $sync_id): array {
    $sessions = get_option('rental_sync_sessions', []);
    if (!isset($sessions[$sync_id])) $sessions[$sync_id] = [];

    $cur_gen = rental_sync_generation_current();
    $sessions[$sync_id]['generation'] = $cur_gen;
    $status = intval($sessions[$sync_id]['status'] ?? 0);

    if (in_array($status, [
        Rentopian_Sync_REST::STATUS_CANCELED,
        Rentopian_Sync_REST::STATUS_FAILED,
        0
    ], true)) {
        $sessions[$sync_id]['status'] = Rentopian_Sync_REST::STATUS_PROCESSING;
    }

    unset($sessions[$sync_id]['last_error']);

    // Mint/reuse token
    $wp_token = !empty($sessions[$sync_id]['wp_token'])
        ? $sessions[$sync_id]['wp_token']
        : wp_generate_password(32, false);

    $sessions[$sync_id]['wp_token'] = $wp_token;

    update_option('rental_sync_sessions', $sessions);

    // Clear locks and make it “current”
    delete_transient("rental_sync_lock_{$sync_id}");
    update_option('rental_current_sync_id', $sync_id);
    update_option('rental_current_wp_token', $wp_token);

    return [$sessions, $wp_token];
}

/**
 * Find the most recent “eligible” sync_id to resume when none is provided.
 * Rules:
 *  - Prefer STATUS_FAILED or STATUS_CANCELED with the newest started_at/updated_at
 *  - If STATUS_PROCESSING but stale (last update > 20min) treat as stuck and resume it
 *  - Ignore STATUS_COMPLETED
 */
function rental_pick_latest_broken_sync_id(): ?string {
    global $wpdb;

    // look into the logs table if sessions are empty/outdated
    $table = $wpdb->prefix . 'rental_file_sync_log';
    $row = $wpdb->get_row("
        SELECT sync_id
        FROM {$table}
        WHERE 
            processed_count <> 0
            AND processed_count < total_count
            AND last_index <> 0
        ORDER BY id DESC
        LIMIT 1
    ");

    if ($row && !empty($row->sync_id)) {
        return $row->sync_id;
    }

    // fallback to look into sessions 
    $sessions = get_option('rental_sync_sessions', []);
    $candidate = null;
    $candidate_ts = 0;
    $now = time();
    foreach ($sessions as $sid => $s) {
        $status = intval($s['status'] ?? 0);
        $started = isset($s['started_at']) ? strtotime($s['started_at']) : 0;
        $updated = isset($s['updated_at']) ? strtotime($s['updated_at']) : 0; // if you store it
        $last_t = max($started, $updated);

        // Stuck definition for PROCESSING: last update older than 20 minutes
        $is_processing_stuck = ($status === Rentopian_Sync_REST::STATUS_PROCESSING) && (($now - $last_t) > 20*60);
        $eligible = in_array($status, [
            Rentopian_Sync_REST::STATUS_FAILED,
            Rentopian_Sync_REST::STATUS_CANCELED,
        ], true) || $is_processing_stuck;

        if (!$eligible) continue;

        if ($last_t > $candidate_ts) {
            $candidate = $sid;
            $candidate_ts = $last_t;
        }
    }
    if ($candidate) return $candidate;

    return null;
}