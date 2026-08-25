<?php
/**
 * Unified Sync Log AJAX Controller
 *
 * Admin AJAX endpoints behind the unified synchronization log: the merged
 * run list, one run's detail, its download, and its deletion.
 *
 * Every response names its own failure. A panel that can only say "loading"
 * forever is worse than one that says what went wrong, so the endpoints
 * always answer with a message the UI can display.
 *
 * @package RentopianSync\SyncLog
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Rental_Unified_Sync_Log_Ajax_Controller {

    /**
     * Nonce action shared by every endpoint here.
     */
    const NONCE_ACTION = 'rental_sync_log_admin';

    const PER_PAGE     = 10;
    const MAX_PER_PAGE = 50;

    public function register_hooks() {
        add_action( 'wp_ajax_rental_sync_log_runs', [ $this, 'runs' ] );
        add_action( 'wp_ajax_rental_sync_log_detail', [ $this, 'detail' ] );
        add_action( 'wp_ajax_rental_sync_log_download', [ $this, 'download' ] );
        add_action( 'wp_ajax_rental_sync_log_delete', [ $this, 'delete' ] );
    }

    /**
     * Reject non-admin callers, and cross-site requests on anything that
     * changes state.
     *
     * @param bool $check_nonce
     */
    private function guard( $check_nonce = true ) {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json( [ 'success' => false, 'message' => __( 'Insufficient permissions.', 'rentopian-sync' ) ], 403 );
        }

        if ( $check_nonce && ! check_ajax_referer( self::NONCE_ACTION, 'nonce', false ) ) {
            wp_send_json( [
                'success' => false,
                'message' => __( 'The page session expired. Reload the log page and try again.', 'rentopian-sync' ),
            ], 403 );
        }
    }

    /**
     * One page of the merged run list.
     */
    public function runs() {
        $this->guard( false );

        $page     = isset( $_GET['page_no'] ) ? max( 1, (int) $_GET['page_no'] ) : 1;
        $per_page = isset( $_GET['per_page'] )
            ? max( 1, min( self::MAX_PER_PAGE, (int) $_GET['per_page'] ) )
            : self::PER_PAGE;

        $total = Rental_Unified_Sync_Log::count();

        wp_send_json( [
            'success'     => true,
            'runs'        => Rental_Unified_Sync_Log::get_page( $page, $per_page ),
            'total'       => $total,
            'page'        => $page,
            'per_page'    => $per_page,
            'total_pages' => (int) ceil( $total / $per_page ),
        ], 200 );
    }

    /**
     * One run's metadata plus the tail of every log it produced.
     */
    public function detail() {
        $this->guard( false );

        $key = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';
        if ( '' === $key ) {
            wp_send_json( [ 'success' => false, 'message' => __( 'Missing run id.', 'rentopian-sync' ) ], 400 );
        }

        $lines = isset( $_GET['lines'] )
            ? max( 20, min( 1000, (int) $_GET['lines'] ) )
            : Rental_Unified_Sync_Log::TAIL_LINES;

        $detail = Rental_Unified_Sync_Log::get_detail( $key, $lines );

        if ( ! $detail ) {
            wp_send_json( [
                'success' => false,
                'message' => __( 'That run is no longer in the log. Refresh the list.', 'rentopian-sync' ),
            ], 404 );
        }

        wp_send_json( array_merge( [ 'success' => true ], $detail ), 200 );
    }

    /**
     * Stream one run's whole log as a plain-text download.
     */
    public function download() {
        $this->guard();

        $key = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';

        if ( '' === $key || ! Rental_Unified_Sync_Log::stream_download( $key ) ) {
            wp_die( esc_html__( 'No log exists for that run.', 'rentopian-sync' ), '', [ 'response' => 404 ] );
        }

        exit;
    }

    /**
     * Delete one run and whatever log it wrote.
     */
    public function delete() {
        $this->guard();

        $key = isset( $_POST['key'] ) ? sanitize_text_field( wp_unslash( $_POST['key'] ) ) : '';
        if ( '' === $key ) {
            wp_send_json( [ 'success' => false, 'message' => __( 'Missing run id.', 'rentopian-sync' ) ], 400 );
        }

        $result = Rental_Unified_Sync_Log::delete( $key );

        wp_send_json( $result, empty( $result['success'] ) ? 409 : 200 );
    }
}
