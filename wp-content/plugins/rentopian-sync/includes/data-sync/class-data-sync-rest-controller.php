<?php
/**
 * Data Sync REST Controller
 *
 * WP REST endpoints driven by the Laravel chunk job. The endpoint path
 * ends in /process-chunk so the driver's sibling derivation (/status,
 * /retry-images) resolves predictably.
 *
 * Routes:
 *   POST /rentopian-sync/v1/data-sync/process-chunk
 *   GET  /rentopian-sync/v1/data-sync/status
 *   POST /rentopian-sync/v1/data-sync/retry-images   (compatibility no-op)
 *
 * @package RentopianSync\DataSync
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Rental_Data_Sync_REST_Controller {

    public static function register_routes() {
        $namespace = 'rentopian-sync/v1';

        register_rest_route( $namespace, '/data-sync/process-chunk', [
            'methods'             => 'POST',
            'callback'            => [ __CLASS__, 'process_chunk_handler' ],
            'permission_callback' => '__return_true', // token validation inside
        ] );

        register_rest_route( $namespace, '/data-sync/status', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'status_handler' ],
            'permission_callback' => '__return_true',
        ] );

        register_rest_route( $namespace, '/data-sync/retry-images', [
            'methods'             => 'POST',
            'callback'            => [ __CLASS__, 'retry_noop_handler' ],
            'permission_callback' => '__return_true',
        ] );
    }

    /* ──────────────────────────────────────────────────────────
     * Process Chunk
     * ────────────────────────────────────────────────────────── */

    public static function process_chunk_handler( WP_REST_Request $request ) {
        $params = $request->get_params();

        foreach ( [ 'sync_id', 'start_index', 'wp_token' ] as $key ) {
            if ( ! isset( $params[ $key ] ) ) {
                return new WP_REST_Response( [ 'success' => false, 'message' => "Missing {$key}" ], 400 );
            }
        }

        $sync_id  = sanitize_text_field( $params['sync_id'] );
        $wp_token = sanitize_text_field( $params['wp_token'] );

        $session = Rental_Data_Sync_Session::get( $sync_id );
        if ( ! $session || empty( $session['wp_token'] ) || ! hash_equals( (string) $session['wp_token'], $wp_token ) ) {
            Rental_Data_Sync_Logger::write( "Token validation failed for sync_id={$sync_id}", 'warning', 'rest' );
            return new WP_REST_Response( [ 'success' => false, 'message' => 'Invalid token' ], 403 );
        }

        if ( (int) ( $session['status'] ?? 0 ) === Rental_Data_Sync_Status::STATUS_CANCELED ) {
            return new WP_REST_Response( [
                'success' => false,
                'message' => 'Canceled',
                'status'  => Rental_Data_Sync_Status::STATUS_CANCELED,
            ], 200 );
        }

        $session_gen = isset( $session['generation'] ) ? (int) $session['generation'] : -1;
        if ( $session_gen !== Rental_Data_Sync_Session::generation_current() ) {
            return new WP_REST_Response( [
                'success' => false,
                'message' => 'This sync session is stale (generation mismatch).',
                'status'  => Rental_Data_Sync_Status::STATUS_CANCELED,
            ], 409 );
        }

        // Proof the driver is alive; the watchdog reads it to tell "the
        // queue never delivered" apart from "the run stalled part-way".
        Rental_Data_Sync_Session::heartbeat( $sync_id );

        try {
            $result = Rental_Data_Sync_Chunk_Worker::process( intval( $params['start_index'] ), $sync_id );

            if ( ! is_array( $result ) ) {
                return new WP_REST_Response( [ 'success' => false, 'message' => 'Bad result from worker' ], 500 );
            }

            return new WP_REST_Response( array_merge( [ 'success' => true ], $result ), 200 );

        } catch ( Throwable $e ) {
            Rental_Data_Sync_Session::update( $sync_id, [ 'last_error' => $e->getMessage() ] );
            Rental_Data_Sync_Logger::write( 'process-chunk exception: ' . $e->getMessage(), 'error', 'rest' );
            return new WP_REST_Response( [ 'success' => false, 'message' => $e->getMessage() ], 500 );
        }
    }

    /* ──────────────────────────────────────────────────────────
     * Status
     * ────────────────────────────────────────────────────────── */

    public static function status_handler( WP_REST_Request $request ) {
        $sync_id  = sanitize_text_field( $request->get_param( 'sync_id' ) );
        $wp_token = sanitize_text_field( $request->get_param( 'wp_token' ) );

        $session = Rental_Data_Sync_Session::get( $sync_id );
        if ( ! $session || ! hash_equals( (string) ( $session['wp_token'] ?? '' ), $wp_token ) ) {
            return new WP_REST_Response( [ 'success' => false, 'message' => 'Not found or invalid token' ], 404 );
        }

        $status = (int) ( $session['status'] ?? Rental_Data_Sync_Status::STATUS_PROCESSING );

        // The driver's fallback loop only stops on CANCELED or completed;
        // report a terminally failed run as canceled so it stops pacing.
        if ( $status === Rental_Data_Sync_Status::STATUS_FAILED ) {
            $status = Rental_Data_Sync_Status::STATUS_CANCELED;
        }

        return new WP_REST_Response( [
            'success'    => true,
            'last_index' => Rental_Data_Sync_Session::get_stored_cursor( $sync_id ),
            'completed'  => ( (int) ( $session['status'] ?? 0 ) === Rental_Data_Sync_Status::STATUS_COMPLETED ),
            'status'     => $status,
        ], 200 );
    }

    /* ──────────────────────────────────────────────────────────
     * Retry (derived sibling — data sync retries internally)
     * ────────────────────────────────────────────────────────── */

    public static function retry_noop_handler( WP_REST_Request $request ) {
        unset( $request );
        return new WP_REST_Response( [ 'success' => true, 'succeeded_ids' => [], 'failed_ids' => [] ], 200 );
    }
}
