<?php
/**
 * Background Data Sync Module — Bootstrap
 *
 * Loads the data-sync subsystem and wires it into WordPress. The module is
 * fully isolated: the legacy `rental_sync` pipeline and the file-sync
 * module are only re-used, never modified by anything in here.
 *
 * @package RentopianSync\DataSync
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/*
|--------------------------------------------------------------------------
| Load Classes (order matters: dependencies first)
|--------------------------------------------------------------------------
*/

$data_sync_dir = __DIR__;

// Foundation: unified module logger + constants
require_once $data_sync_dir . '/class-data-sync-logger.php';
require_once $data_sync_dir . '/class-data-sync-status.php';

// State: sessions, per-run artifacts, run history
require_once $data_sync_dir . '/class-data-sync-session.php';
require_once $data_sync_dir . '/class-data-sync-run-repository.php';
require_once $data_sync_dir . '/class-data-sync-watchdog.php';
require_once $data_sync_dir . '/class-data-sync-integrity.php';

// Write pipeline: composer + webhook-handler writer
require_once $data_sync_dir . '/class-payload-composer.php';
require_once $data_sync_dir . '/class-record-writer.php';

// Phase engine + chunk worker
require_once $data_sync_dir . '/class-data-sync-phases.php';
require_once $data_sync_dir . '/class-data-sync-chunk-worker.php';

// Orchestration: scheduler, REST endpoints, email reports, admin AJAX
require_once $data_sync_dir . '/class-data-sync-scheduler.php';
require_once $data_sync_dir . '/class-data-sync-rest-controller.php';
require_once $data_sync_dir . '/class-data-sync-reporter.php';
require_once $data_sync_dir . '/class-data-sync-ajax-controller.php';

/*
|--------------------------------------------------------------------------
| Register WordPress Hooks
|--------------------------------------------------------------------------
*/

// REST routes driven by the Laravel chunk job
add_action( 'rest_api_init', [ 'Rental_Data_Sync_REST_Controller', 'register_routes' ] );

// Admin AJAX endpoints
$data_sync_ajax_controller = new Rental_Data_Sync_Ajax_Controller();
$data_sync_ajax_controller->register_hooks();

// Run history table (version-guarded, admin only)
add_action( 'admin_init', [ 'Rental_Data_Sync_Run_Repository', 'install' ] );

// Lookup indexes on the legacy relation tables (version-guarded). Also
// ensured at the start of a run; here so webhooks benefit without one.
add_action( 'admin_init', [ 'Rental_Data_Sync_Integrity', 'ensure_relation_indexes' ] );

// Watchdog: fail an abandoned run and sweep orphaned staging files. Admin
// side only — it costs a transient read, and the settings page runs the
// same check inline while it is open.
add_action( 'admin_init', [ 'Rental_Data_Sync_Watchdog', 'tick' ] );

// Deferred file-sync chain: cron is the primary trigger; the init fallback
// covers a site whose cron cannot run, so the gap between the two phases
// stays the deferral rather than however long cron stays broken.
add_action( 'rental_data_sync_chain_files', [ 'Rental_Data_Sync_Scheduler', 'advance_chain' ] );
add_action( 'init', static function () {
    $pending = get_option( Rental_Data_Sync_Scheduler::OPTION_PENDING_CHAIN, [] );
    $due     = is_array( $pending )
        && ! empty( $pending['sync_id'] )
        && time() > (int) ( $pending['not_before'] ?? 0 ) + Rental_Data_Sync_Scheduler::CHAIN_CRON_GRACE;

    if ( $due || get_option( Rental_Data_Sync_Scheduler::OPTION_CHAIN_WATCH, [] ) ) {
        Rental_Data_Sync_Scheduler::advance_chain();
    }
}, 99 );

// Combined report email once the chained file sync finishes
add_action( 'rental_file_sync_completed', [ 'Rental_Data_Sync_Reporter', 'on_file_sync_completed' ], 10, 2 );

// A file sync the driver abandoned ends the pipeline, and is reported too
add_action( 'rental_file_sync_failed', [ 'Rental_Data_Sync_Reporter', 'on_file_sync_failed' ], 10, 2 );
