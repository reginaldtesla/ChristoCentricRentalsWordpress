<?php
if (! defined('ABSPATH')) exit;

class Rentopian_Sync_REST {

    const STATUS_CREATED = 1;
    const STATUS_PROCESSING = 2;
    const STATUS_COMPLETED = 3;
    const STATUS_FAILED = 4;
    const STATUS_CANCELED = 5;

    const MODE_SYNC = 1;
    const MODE_RESYNC = 2;

    public static function register_routes() {
        register_rest_route('rentopian-sync/v1', '/process-chunk', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'process_chunk_handler'],
            'permission_callback' => '__return_true' // we do token validation inside
        ]);

        register_rest_route('rentopian-sync/v1', '/status', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'status_handler'],
            'permission_callback' => '__return_true'
        ]);

        register_rest_route('rentopian-sync/v1', '/cancel', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'cancel_handler'],
            'permission_callback' => '__return_true'
        ]);

        register_rest_route('rentopian-sync/v1', '/retry-images', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'retry_images_handler'],
            'permission_callback' => '__return_true'
        ]);
    }

    /**
     * WP receives this call from Main Server job to process a chunk.
     * WP handler
     *  - validate wp_token,
     *  - call existing rental_upload_images flow (or use rental_upload_images_stream),
     *  - persist last_index and failed_ids for this sync_id,
     *  - return JSON: { success: true, last_index: X, failed_ids: [], completed: bool }
     */
    public static function process_chunk_handler(\WP_REST_Request $request) {
        $params = $request->get_params();

        $required = ['sync_id', 'start_index', 'chunk_size', 'wp_token'];
        foreach ($required as $r) {
            if (!isset($params[$r])) {
                return new WP_REST_Response(['success' => false, 'message' => "Missing $r"], 400);
            }
        }

        $sync_id = sanitize_text_field($params['sync_id']);
        $wp_token = sanitize_text_field($params['wp_token']);

        $mode      = isset($params['mode']) ? (int) $params['mode'] : self::MODE_SYNC;
        $is_resync = ($mode === self::MODE_RESYNC);

        if (!$sync_id || !$wp_token) {
            return new WP_REST_Response(['success' => false, 'message' => 'Missing sync_id or wp_token'], 400);
        }

        // Validate token: token stored by WP when Start sync initiated
        $sessions = get_option('rental_sync_sessions', []);
        if (!isset($sessions[$sync_id]) || empty($sessions[$sync_id]['wp_token']) || $sessions[$sync_id]['wp_token'] !== $wp_token) {
            return new WP_REST_Response(['success' => false, 'message' => 'Invalid token'], 403);
        }
    

        // If canceled locally, respond accordingly
        if (isset($sessions[$sync_id]['status']) && $sessions[$sync_id]['status'] === self::STATUS_CANCELED) {
            return new WP_REST_Response(['success' => false, 'message' => 'Canceled', 'status' => self::STATUS_CANCELED], 200);
        }

        $current_gen = rental_sync_generation_current();
        $session_gen = isset($sessions[$sync_id]['generation']) ? (int)$sessions[$sync_id]['generation'] : -1;

        if ($session_gen != $current_gen) {

            // Tell caller this session is stale and should not continue
            return new WP_REST_Response([
                'success' => false,
                'message' => 'This sync session is stale (generation mismatch).',
                'status'  => self::STATUS_CANCELED,
            ], 409);
        }


        // initial state
        // reusing existing flow: rental_upload_images / upload_images (calling via a wrapper function : rental_process_images_chunk($start, $chunk_size, $sync_id))
        try {

            $start_index = intval($params['start_index']);
            $chunk_size = intval($params['chunk_size']);
        
            $result = rental_process_images_chunk($start_index, $chunk_size, $sync_id, $is_resync);

            if (!is_array($result)) {
                return new WP_REST_Response(['success' => false, 'message' => 'Bad result from worker'], 500);
            }

            return new WP_REST_Response(array_merge(['success' => true], $result), 200);

        } catch (\Exception $e) {
            // record failure

            $sessions[$sync_id]['last_error'] = $e->getMessage();
            update_option('rental_sync_sessions', $sessions);

            return new WP_REST_Response(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Return current status for the sync_id
     */
    public static function status_handler(\WP_REST_Request $request) {
        $sync_id = sanitize_text_field($request->get_param('sync_id'));
        $wp_token = sanitize_text_field($request->get_param('wp_token'));

        $sessions = get_option('rental_sync_sessions', []);
        if (!isset($sessions[$sync_id]) || $sessions[$sync_id]['wp_token'] !== $wp_token) {
            return new WP_REST_Response(['success' => false, 'message' => 'Not found or invalid token'], 404);
        }

        $s = $sessions[$sync_id];

        return new WP_REST_Response([
            'success' => true,
            'last_index' => intval($s['last_index'] ?? 0),
            'failed_ids' => $s['failed_ids'] ?? [],
            'status' => $s['status'] ?? self::STATUS_PROCESSING
        ], 200);
    }

    /**
     * Mark a session canceled
     */
    public static function cancel_handler(\WP_REST_Request $request) {
        $params = $request->get_params();

        $sync_id = sanitize_text_field($params['sync_id']);
        $wp_token = sanitize_text_field($params['wp_token']);
        $sessions = get_option('rental_sync_sessions', []);

        if (!isset($sessions[$sync_id]) || $sessions[$sync_id]['wp_token'] !== $wp_token) {
            return new WP_REST_Response(['success' => false, 'message' => 'Not found or invalid token'], 404);
        }

        $sessions[$sync_id]['status'] = self::STATUS_CANCELED;
        update_option('rental_sync_sessions', $sessions);
        
        return new WP_REST_Response(['success' => true, 'message' => 'Canceled'], 200);
    }


    public static function retry_images_handler(\WP_REST_Request $request) {
        $params = $request->get_json_params() ?: $request->get_params();
    
        $sync_id = isset($params['sync_id']) ? sanitize_text_field($params['sync_id']) : '';
        $wp_token = isset($params['wp_token']) ? sanitize_text_field($params['wp_token']) : '';
        $image_ids = isset($params['image_ids']) ? $params['image_ids'] : [];
    
        if (empty($sync_id) || empty($wp_token)) {
            return new WP_REST_Response(['success' => false, 'message' => 'Missing sync_id/wp_token'], 400);
        }
    
        $sessions = get_option('rental_sync_sessions', []);
        if (!isset($sessions[$sync_id]) || $sessions[$sync_id]['wp_token'] !== $wp_token) {
            return new WP_REST_Response(['success' => false, 'message' => 'Invalid token or sync not found'], 403);
        }
    
        // Accept image_ids as JSON string or array
        if (is_string($image_ids)) {
            $image_ids = json_decode($image_ids, true) ?: [];
        }

        if (!is_array($image_ids) || empty($image_ids)) {
            // Fall back: use DB failed ids for this sync
            $image_ids = rental_failed_get_ids_by_sync($sync_id);
        }
        
    
        try {

            $result = rental_process_images_array_for_retry($sync_id, $image_ids);

            // update sessions state
            $sessions = get_option('rental_sync_sessions', []);
            if (!isset($sessions[$sync_id])) {

                $sessions[$sync_id] = [
                    'wp_token' => $wp_token,
                    'last_index' => intval(get_option("rental_products_img_last_id_{$sync_id}", 0)),
                    'failed_ids' => $result['failed_ids'],
                    'status' => Rentopian_Sync_REST::STATUS_PROCESSING
                ];

            } else {
                
                $sessions[$sync_id]['last_index'] = intval(get_option("rental_products_img_last_id_{$sync_id}", 0));
                $sessions[$sync_id]['failed_ids'] = rental_failed_get_ids_by_sync($sync_id);
                // we don't mark completed here; main flow will set completed when global flag set

            }
            update_option('rental_sync_sessions', $sessions);
    
            return new WP_REST_Response([
                'success' => true,
                'succeeded_ids' => $result['succeeded_ids'],
                'failed_ids' => $result['failed_ids'],
                'processed_count' => $result['processed_count'],
                'last_index' => intval(get_option("rental_products_img_last_id_{$sync_id}", 0))
            ], 200);
    
        } catch (\Exception $e) {

            rental_file_sync_log_write($sync_id, 'error', 'retry_images_handler exception: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()], intval(get_option("rental_products_img_last_id_{$sync_id}", 0)), intval(get_option('rental_products_img_processed', 0)), intval(get_option('rental_products_img_count', 0)), 0.0, Rentopian_Sync_REST::STATUS_COMPLETED);
            
            return new WP_REST_Response(['success' => false, 'message' => 'Exception: '.$e->getMessage() ], 500);
        }
    }
    
}

// Hook registration
add_action('rest_api_init', ['Rentopian_Sync_REST', 'register_routes']);
