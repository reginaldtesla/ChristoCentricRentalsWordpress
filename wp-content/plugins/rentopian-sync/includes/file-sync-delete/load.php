<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * File Sync Delete — Module Bootstrap
 *
 * Loads all classes in the correct order and initialises them.
 * Include this file once from the main plugin file (rentopian-sync.php).
 *
 * Provides:
 *  - REST endpoints for chunk-based deletion (called by Main/Laravel system)
 *  - Admin UI buttons for background deletion via Main API
 *  - Direct AJAX handlers for immediate deletion (admin only, secured)
 *
 * Requires:
 *  - class-logger-with-timer.php (Project_WP_Logger) loaded before this file
 *
 * @package RentopianSync\FileSyncDelete
 */

$file_sync_delete_dir = __DIR__;

// 1. Shared logger (must load before any class that calls self::log())
require_once $file_sync_delete_dir . '/class-rental-delete-logger.php';

// 2. Shared orphan detection trait (must load before any class that uses it)
require_once $file_sync_delete_dir . '/trait-orphan-detection.php';

// 2. REST endpoints (token-authenticated, called by Main/Laravel system)
require_once $file_sync_delete_dir . '/class-rental-orphan-delete-endpoint.php';
Rental_Orphan_Delete_Endpoint::init();

require_once $file_sync_delete_dir . '/class-rental-orphan-only-delete-endpoint.php';
Rental_Orphan_Only_Delete_Endpoint::init();

// 3. Admin UI for background delete buttons
require_once $file_sync_delete_dir . '/class-rental-orphan-delete-admin-ui.php';
Rental_Orphan_Delete_Admin_UI::init();

// 4. Direct (non-BG) AJAX handlers — secured, replaces old insecure functions
require_once $file_sync_delete_dir . '/class-rental-direct-delete-ajax.php';
Rental_Direct_Delete_Ajax::init();

/* ------------------------------------------------------------------ */
/*  Backward-compatible global wrapper functions                       */
/*                                                                     */
/*  These preserve the old function signatures so that existing code    */
/*  (e.g. wp_ajax_rental_change_file_sync_type) keeps working without  */
/*  any changes. The real logic lives in Rental_Direct_Delete_Ajax.     */
/* ------------------------------------------------------------------ */

if ( ! function_exists( 'rental_delete_orphaned_files' ) ) {
    /**
     * @see Rental_Direct_Delete_Ajax::delete_orphaned_files()
     */
    function rental_delete_orphaned_files( $batch_size = 50, $exclude_ids = [], $dry_run = true ) {
        return Rental_Direct_Delete_Ajax::delete_orphaned_files( $batch_size, $exclude_ids, $dry_run );
    }
}

if ( ! function_exists( 'rental_delete_product_images' ) ) {
    /**
     * @see Rental_Direct_Delete_Ajax::delete_product_images()
     */
    function rental_delete_product_images( $batch_size = 5, $exclude_ids = [] ) {
        return Rental_Direct_Delete_Ajax::delete_product_images( $batch_size, $exclude_ids );
    }
}
