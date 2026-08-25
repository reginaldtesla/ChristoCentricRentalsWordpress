<?php
/**
 * Background File Sync Module — Bootstrap
 *
 * Loads all classes for the background file-sync subsystem and wires them
 * into WordPress via action hooks.
 *
 * @package RentopianSync\FileSync
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/*
|--------------------------------------------------------------------------
| Load Classes (order matters: dependencies first)
|--------------------------------------------------------------------------
*/

$file_sync_dir = __DIR__;

// Foundation: Statuses/Modes enum-like constants
require_once $file_sync_dir . '/class-sync-status.php';

// Foundation: unified module logger (used by every class below)
require_once $file_sync_dir . '/class-file-sync-logger.php';

// Data-layer classes (no dependencies on other file-sync classes)
require_once $file_sync_dir . '/class-sync-log-repository.php';
require_once $file_sync_dir . '/class-failed-image-repository.php';

// Session management
require_once $file_sync_dir . '/class-sync-session-manager.php';

// Abandoned-run detection
require_once $file_sync_dir . '/class-file-sync-watchdog.php';

// Image processing helpers
require_once $file_sync_dir . '/class-image-performance-filter.php';
require_once $file_sync_dir . '/class-image-subsizer.php';
require_once $file_sync_dir . '/class-image-downloader.php';
require_once $file_sync_dir . '/class-image-relation-attacher.php';
require_once $file_sync_dir . '/class-image-integrity.php';

// Upload workers (initial sync + resync)
require_once $file_sync_dir . '/class-sync-uploader.php';
require_once $file_sync_dir . '/class-resync-uploader.php';

// Chunk worker (orchestrates uploaders)
require_once $file_sync_dir . '/class-chunk-worker.php';

// Retry logic
require_once $file_sync_dir . '/class-retry-processor.php';

// Scheduler (starts / resumes / cancels syncs)
require_once $file_sync_dir . '/class-sync-scheduler.php';

// REST API controller (called by Laravel server)
require_once $file_sync_dir . '/class-sync-rest-controller.php';

// WP-Admin AJAX controller (called by JS admin UI)
require_once $file_sync_dir . '/class-sync-ajax-controller.php';

/*
|--------------------------------------------------------------------------
| Backward-Compatible Function Aliases
|--------------------------------------------------------------------------
| These thin wrappers keep existing call-sites working so nothing breaks.
| They simply delegate to the new OOP classes.
|--------------------------------------------------------------------------
*/

require_once $file_sync_dir . '/compat-functions.php';

/*
|--------------------------------------------------------------------------
| Register WordPress Hooks
|--------------------------------------------------------------------------
*/

// REST API routes (called by the Laravel core server)
add_action( 'rest_api_init', [ 'Rental_Sync_REST_Controller', 'register_routes' ] );

// Close out runs the Rentopian queue abandoned
add_action( 'admin_init', [ 'Rental_File_Sync_Watchdog', 'tick' ] );

// Admin AJAX endpoints (called by rental-admin-script.js)
$ajax_controller = new Rental_Sync_Ajax_Controller();
$ajax_controller->register_hooks();
