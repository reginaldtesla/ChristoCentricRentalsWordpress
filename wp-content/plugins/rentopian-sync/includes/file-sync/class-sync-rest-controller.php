<?php
/**
 * Sync REST Controller
 *
 * Exposes WP REST endpoints consumed by the Laravel core server.
 *
 * Routes:
 *   POST /rentopian-sync/v1/process-chunk
 *   GET  /rentopian-sync/v1/status
 *   POST /rentopian-sync/v1/cancel
 *   POST /rentopian-sync/v1/retry-images
 *
 * @package RentopianSync\FileSync
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Rental_Sync_REST_Controller {

    /**
     * Register REST API routes.
     */
    public static function register_routes() {
        $namespace = 'rentopian-sync/v1';

        register_rest_route( $namespace, '/process-chunk', [
            'methods'             => 'POST',
            'callback'            => [ __CLASS__, 'process_chunk_handler' ],
            'permission_callback' => '__return_true', // token validation inside
        ] );

        register_rest_route( $namespace, '/status', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'status_handler' ],
            'permission_callback' => '__return_true',
        ] );

        register_rest_route( $namespace, '/cancel', [
            'methods'             => 'POST',
            'callback'            => [ __CLASS__, 'cancel_handler' ],
            'permission_callback' => '__return_true',
        ] );

        register_rest_route( $namespace, '/retry-images', [
            'methods'             => 'POST',
            'callback'            => [ __CLASS__, 'retry_images_handler' ],
            'permission_callback' => '__return_true',
        ] );
    }

    /* ──────────────────────────────────────────────────────────
     * Process Chunk
     * ────────────────────────────────────────────────────────── */

    public static function process_chunk_handler( WP_REST_Request $request ) {
        $params = $request->get_params();

        // Validate required
        foreach ( [ 'sync_id', 'start_index', 'chunk_size', 'wp_token' ] as $key ) {
            if ( ! isset( $params[ $key ] ) ) {
                return new WP_REST_Response( [ 'success' => false, 'message' => "Missing {$key}" ], 400 );
            }
        }

        $sync_id  = sanitize_text_field( $params['sync_id'] );
        $wp_token = sanitize_text_field( $params['wp_token'] );

        if ( !$sync_id || !$wp_token ) {
            return new WP_REST_Response( [ 'success' => false, 'message' => 'Missing sync_id or wp_token' ], 400 );
        }

        // ── Token validation ─────────────────────────────────
        $session = Rental_Sync_Session_Manager::get( $sync_id );
        if ( !$session || empty( $session['wp_token'] ) || $session['wp_token'] !== $wp_token ) {
            self::log_rest( "Token validation failed for sync_id={$sync_id}", 'warning' );
            return new WP_REST_Response( [ 'success' => false, 'message' => 'Invalid token' ], 403 );
        }

        // ── Resolve mode ──────────────────────────────────────
        $mode = isset( $params['mode'] ) ? (int) $params['mode'] : null;
        if ( $mode === null && isset( $session['mode'] ) ) {
            $mode = (int) $session['mode'];
        }
        if ( $mode === null ) {
            $mode = Rental_Sync_Status::MODE_SYNC;
        }

        // ── Canceled locally? ────────────────────────────────
        if ( isset( $session['status'] ) && (int) $session['status'] === Rental_Sync_Status::STATUS_CANCELED ) {
            self::log_rest( "Chunk rejected: session {$sync_id} is canceled" );
            return new WP_REST_Response( [
                'success' => false,
                'message' => 'Canceled',
                'status'  => Rental_Sync_Status::STATUS_CANCELED,
            ], 200 );
        }

        // ── Generation check ─────────────────────────────────
        $session_gen = isset( $session['generation'] ) ? (int) $session['generation'] : -1;
        if ( $session_gen !== Rental_Sync_Session_Manager::generation_current() ) {
            self::log_rest( "Chunk rejected: generation mismatch for {$sync_id} (session={$session_gen}, current=" . Rental_Sync_Session_Manager::generation_current() . ")", 'warning' );
            return new WP_REST_Response( [
                'success' => false,
                'message' => 'This sync session is stale (generation mismatch).',
                'status'  => Rental_Sync_Status::STATUS_CANCELED,
            ], 409 );
        }

        // ── Process ──────────────────────────────────────────
        try {
            $is_resync = ( $mode === Rental_Sync_Status::MODE_RESYNC );
            $result    = Rental_Chunk_Worker::process(
                intval( $params['start_index'] ),
                intval( $params['chunk_size'] ),
                $sync_id,
                $is_resync
            );

            if ( ! is_array( $result ) ) {
                return new WP_REST_Response( [ 'success' => false, 'message' => 'Bad result from worker' ], 500 );
            }

            return new WP_REST_Response( array_merge( [ 'success' => true ], $result ), 200 );

        } catch ( Exception $e ) {
            Rental_Sync_Session_Manager::update( $sync_id, [ 'last_error' => $e->getMessage() ] );
            return new WP_REST_Response( [ 'success' => false, 'message' => $e->getMessage() ], 500 );
        }
    }

    /* ──────────────────────────────────────────────────────────
     * Status
     * ────────────────────────────────────────────────────────── */

    public static function status_handler( WP_REST_Request $request ) {
        $sync_id  = sanitize_text_field( $request->get_param( 'sync_id' ) );
        $wp_token = sanitize_text_field( $request->get_param( 'wp_token' ) );

        $session = Rental_Sync_Session_Manager::get( $sync_id );
        if ( ! $session || ( $session['wp_token'] ?? '' ) !== $wp_token ) {
            return new WP_REST_Response( [ 'success' => false, 'message' => 'Not found or invalid token' ], 404 );
        }

        return new WP_REST_Response( [
            'success'    => true,
            'last_index' => intval( $session['last_index'] ?? 0 ),
            'failed_ids' => $session['failed_ids'] ?? [],
            'status'     => $session['status'] ?? Rental_Sync_Status::STATUS_PROCESSING,
        ], 200 );
    }

    /* ──────────────────────────────────────────────────────────
     * Cancel
     * ────────────────────────────────────────────────────────── */

    public static function cancel_handler( WP_REST_Request $request ) {
        $params   = $request->get_params();
        $sync_id  = sanitize_text_field( $params['sync_id'] ?? '' );
        $wp_token = sanitize_text_field( $params['wp_token'] ?? '' );

        $session = Rental_Sync_Session_Manager::get( $sync_id );
        if ( ! $session || ( $session['wp_token'] ?? '' ) !== $wp_token ) {
            return new WP_REST_Response( [ 'success' => false, 'message' => 'Not found or invalid token' ], 404 );
        }

        Rental_Sync_Session_Manager::update( $sync_id, [
            'status' => Rental_Sync_Status::STATUS_CANCELED,
        ] );

        return new WP_REST_Response( [ 'success' => true, 'message' => 'Canceled' ], 200 );
    }

    /* ──────────────────────────────────────────────────────────
     * Retry Images
     * ────────────────────────────────────────────────────────── */

    public static function retry_images_handler( WP_REST_Request $request ) {
        $params   = $request->get_json_params() ?: $request->get_params();
        $sync_id  = sanitize_text_field( $params['sync_id'] ?? '' );
        $wp_token = sanitize_text_field( $params['wp_token'] ?? '' );
        $image_ids = $params['image_ids'] ?? [];

        if ( empty( $sync_id ) || empty( $wp_token ) ) {
            return new WP_REST_Response( [ 'success' => false, 'message' => 'Missing sync_id/wp_token' ], 400 );
        }

        $session = Rental_Sync_Session_Manager::get( $sync_id );
        if ( ! $session || ( $session['wp_token'] ?? '' ) !== $wp_token ) {
            return new WP_REST_Response( [ 'success' => false, 'message' => 'Invalid token or sync not found' ], 403 );
        }

        // Accept as JSON string or array
        if ( is_string( $image_ids ) ) {
            $image_ids = json_decode( $image_ids, true ) ?: [];
        }
        if ( ! is_array( $image_ids ) || empty( $image_ids ) ) {
            // BUG FIX: Use get_retryable_ids to skip images exceeding max retry attempts
            $image_ids = Rental_Failed_Image_Repository::get_retryable_ids( $sync_id, 3 );
        }

        try {
            $result = Rental_Retry_Processor::process( $sync_id, $image_ids );

            // Update session
            Rental_Sync_Session_Manager::update( $sync_id, [
                'last_index' => intval( get_option( "rental_products_img_last_id_{$sync_id}", 0 ) ),
                'failed_ids' => Rental_Failed_Image_Repository::get_ids_by_sync( $sync_id ),
            ] );

            return new WP_REST_Response( [
                'success'         => true,
                'succeeded_ids'   => $result['succeeded_ids'],
                'failed_ids'      => $result['failed_ids'],
                'processed_count' => $result['processed_count'],
                'last_index'      => intval( get_option( "rental_products_img_last_id_{$sync_id}", 0 ) ),
            ], 200 );

        } catch ( Exception $e ) {
            Rental_Sync_Log_Repository::write(
                $sync_id, 'error',
                'retry_images_handler exception: ' . $e->getMessage(),
                [ 'trace' => $e->getTraceAsString() ],
                intval( get_option( "rental_products_img_last_id_{$sync_id}", 0 ) ),
                intval( get_option( "rental_products_img_processed_{$sync_id}", 0 ) ),
                intval( get_option( 'rental_products_img_count', 0 ) ),
                0.0,
                Rental_Sync_Status::STATUS_COMPLETED
            );

            return new WP_REST_Response( [ 'success' => false, 'message' => 'Exception: ' . $e->getMessage() ], 500 );
        }
    }

    /* ──────────────────────────────────────────────────────────
     * Private helpers
     * ────────────────────────────────────────────────────────── */

    private static function log_rest( $message, $level = 'info' ) {
        Rental_File_Sync_Logger::write( $message, $level, 'rest' );
    }
}
