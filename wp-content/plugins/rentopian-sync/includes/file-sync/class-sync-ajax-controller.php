<?php
/**
 * Sync AJAX Controller
 *
 * Handles all wp_ajax_* actions triggered from the admin JS
 * (rental-admin-script.js). Each method maps 1:1 to the old procedural
 * wp_ajax_* callback so that the JS action names don't change.
 *
 *
 * @package RentopianSync\FileSync
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Rental_Sync_Ajax_Controller {

    /**
     * Nonce action shared by every endpoint here.
     */
    const NONCE_ACTION = 'rental_file_sync_admin';

    /**
     * Register all AJAX hooks.
     *
     * Action names are kept identical to the original procedural callbacks
     * so existing JS code requires ZERO changes.
     */
    public function register_hooks() {
        add_action( 'wp_ajax_rental_sync_files_bg',          [ $this, 'sync_files_bg' ] );
        add_action( 'wp_ajax_rental_resync_files_bg',        [ $this, 'resync_files_bg' ] );
        add_action( 'wp_ajax_rental_cancel_sync_bg',         [ $this, 'cancel_sync_bg' ] );
        add_action( 'wp_ajax_rental_cancel_all_sync_bg',     [ $this, 'cancel_all_sync_bg' ] );
        add_action( 'wp_ajax_rental_retry_failed_images',    [ $this, 'retry_failed_images' ] );
        add_action( 'wp_ajax_rental_get_file_sync_details',  [ $this, 'get_file_sync_details' ] );
        add_action( 'wp_ajax_rental_paginate_file_sync_log', [ $this, 'paginate_file_sync_log' ] );
        add_action( 'wp_ajax_rental_delete_file_sync',       [ $this, 'delete_file_sync' ] );
    }

    /**
     * Reject callers who cannot administer the site, and cross-site requests
     * on anything that changes state. Starting, stopping and globally
     * disabling synchronization are administrator actions, and being logged
     * in is not the same as being one.
     *
     * @param bool $check_nonce Reads pass FALSE; anything that writes does not.
     */
    private static function guard( $check_nonce = true ) {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json( [ 'success' => false, 'message' => 'Insufficient permissions.' ], 403 );
        }

        if ( $check_nonce && ! check_ajax_referer( self::NONCE_ACTION, 'nonce', false ) ) {
            wp_send_json( [
                'success' => false,
                'message' => 'The page session expired. Reload the settings page and try again.',
            ], 403 );
        }
    }

    /* ──────────────────────────────────────────────────────────
     * Start initial sync
     * ────────────────────────────────────────────────────────── */

    public function sync_files_bg() {
        self::guard();

        Rental_Timer::start_persistent();

        $result = Rental_Sync_Scheduler::schedule( Rental_Sync_Status::MODE_SYNC );

        wp_send_json( $result, $result['success'] ? 200 : 500 );
        wp_die();
    }

    /* ──────────────────────────────────────────────────────────
     * Start resync
     * ────────────────────────────────────────────────────────── */

    public function resync_files_bg() {
        self::guard();

        Rental_Timer::start_persistent();

        $result = Rental_Sync_Scheduler::schedule( Rental_Sync_Status::MODE_RESYNC );

        if ( $result['success'] ) {
            $result['message'] = 'File re-sync scheduled';
        }

        wp_send_json( $result, $result['success'] ? 200 : 500 );
        wp_die();
    }

    /* ──────────────────────────────────────────────────────────
     * Cancel current sync
     * ────────────────────────────────────────────────────────── */

    public function cancel_sync_bg() {
        self::guard();

        $result = Rental_Sync_Scheduler::cancel();

        wp_send_json( $result, empty( $result['success'] ) ? 400 : 200 );
    }

    /* ──────────────────────────────────────────────────────────
     * Cancel ALL syncs
     * ────────────────────────────────────────────────────────── */

    public function cancel_all_sync_bg() {
        self::guard();

        wp_send_json( Rental_Sync_Scheduler::cancel_all(), 200 );
    }

    /* ──────────────────────────────────────────────────────────
     * Retry failed images
     * ────────────────────────────────────────────────────────── */

    public function retry_failed_images() {
        self::guard();

        $sync_id = sanitize_text_field( $_POST['sync_id'] ?? get_option( 'rental_current_sync_id', '' ) );
        $limit   = intval( $_POST['limit'] ?? 20 );

        if ( empty( $sync_id ) ) {
            wp_send_json_error( [ 'message' => 'No sync_id found' ], 400 );
        }

        $failed_ids = Rental_Failed_Image_Repository::get_ids_by_sync( $sync_id );

        if ( empty( $failed_ids ) ) {
            wp_send_json_success( [ 'message' => 'No failed images to retry', 'succeeded_ids' => [], 'failed_ids' => [] ] );
        }

        $to_process = array_slice( $failed_ids, 0, $limit );
        $result     = Rental_Retry_Processor::process( $sync_id, $to_process );

        wp_send_json_success( $result );
    }

    /* ──────────────────────────────────────────────────────────
     * File sync log details
     * ────────────────────────────────────────────────────────── */

    public function get_file_sync_details() {
        self::guard( false );

        $sync_id  = isset( $_GET['sync_id'] ) ? sanitize_text_field( $_GET['sync_id'] ) : '';
        if ( ! $sync_id ) {
            wp_send_json( [ 'success' => false, 'message' => 'Missing sync_id' ], 400 );
        }

        $page     = isset( $_GET['page'] ) ? max( 1, intval( $_GET['page'] ) ) : 1;
        $per_page = isset( $_GET['per_page'] ) ? max( 1, intval( $_GET['per_page'] ) ) : 10;

        $result = Rental_Sync_Log_Repository::get_entries_paginated( $sync_id, $page, $per_page );

        wp_send_json( [
            'success'     => true,
            'entries'     => $result['entries'],
            'page'        => $result['page'],
            'per_page'    => $result['per_page'],
            'total'       => $result['total'],
            'total_pages' => $result['total_pages'],
            'headers'     => [
                __( 'Level', 'rentopian-sync' ),
                __( 'Message', 'rentopian-sync' ),
                __( 'Data', 'rentopian-sync' ),
                __( 'Processed', 'rentopian-sync' ),
                __( 'Time', 'rentopian-sync' ),
            ],
        ], 200 );
    }

    /* ──────────────────────────────────────────────────────────
     * Paginate file sync log (grouped view)
     * ────────────────────────────────────────────────────────── */

    public function paginate_file_sync_log() {
        self::guard( false );

        $page     = isset( $_GET['page'] ) ? max( 1, intval( $_GET['page'] ) ) : 1;
        $per_page = isset( $_GET['per_page'] ) ? max( 1, intval( $_GET['per_page'] ) ) : 10;

        $total_groups = Rental_Sync_Log_Repository::get_grouped_count();
        $groups       = Rental_Sync_Log_Repository::get_grouped_page( $page, $per_page );
        $total_pages  = $per_page ? (int) ceil( $total_groups / $per_page ) : 1;

        wp_send_json( [
            'success'     => true,
            'groups'      => $groups,
            'page'        => $page,
            'per_page'    => $per_page,
            'total'       => $total_groups,
            'total_pages' => $total_pages,
        ], 200 );
    }

    /* ──────────────────────────────────────────────────────────
     * Delete file sync log entry
     * ────────────────────────────────────────────────────────── */

    public function delete_file_sync() {
        self::guard();

        $sync_id = isset( $_POST['sync_id'] ) ? sanitize_text_field( $_POST['sync_id'] ) : '';
        if ( ! $sync_id ) {
            wp_send_json( [ 'success' => false, 'message' => 'Missing sync_id' ], 400 );
        }

        $deleted = Rental_Sync_Log_Repository::delete_by_sync( $sync_id );

        // Clean up sessions
        Rental_Sync_Session_Manager::remove( $sync_id );

        wp_send_json( [ 'success' => true, 'deleted' => $deleted ], 200 );
    }

    /* ──────────────────────────────────────────────────────────
     * Private helpers
     * ────────────────────────────────────────────────────────── */

    private static function log( $message, $level = 'info' ) {
        Rental_File_Sync_Logger::write( $message, $level, 'ajax' );
    }
}
