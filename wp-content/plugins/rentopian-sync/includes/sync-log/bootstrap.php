<?php
/**
 * Unified Sync Log Module — Bootstrap
 *
 * Loads the shared per-run log file store and the unified log panel that
 * reads every synchronization generation from the stores that already hold
 * them. Nothing here writes to those stores: this module only reads.
 *
 * Loaded before the file-sync and data-sync modules, which both build their
 * per-run files on `Rental_Run_Log_File`.
 *
 * @package RentopianSync\SyncLog
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$sync_log_dir = __DIR__;

// Foundation: local-time ↔ Unix time (used by both sync modules)
require_once $sync_log_dir . '/class-sync-time.php';

// Foundation: per-run log files on disk (used by both sync modules)
require_once $sync_log_dir . '/class-run-log-file.php';

// Fallback reader for runs that predate per-run files
require_once $sync_log_dir . '/class-daily-log-reader.php';

// Read-time union of every generation's log store
require_once $sync_log_dir . '/class-unified-sync-log-repository.php';

// Admin AJAX endpoints behind the panel
require_once $sync_log_dir . '/class-unified-sync-log-ajax-controller.php';

$sync_log_ajax_controller = new Rental_Unified_Sync_Log_Ajax_Controller();
$sync_log_ajax_controller->register_hooks();
