<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Admin UI: Background delete buttons.
 *
 * "Delete Products Images (BG)" → product/product_variation related images
 * "Delete Orphans Images (BG)"  → truly orphaned images (unused anywhere)
 *
 * Both queue work via the Main (Laravel) system:
 *   POST /api/v1/sync-delete/start
 *   POST /api/v1/sync-delete/cancel
 *   POST /api/v1/sync-delete/cancel-all
 *
 * @package RentopianSync\FileSyncDelete
 */
class Rental_Orphan_Delete_Admin_UI {

    private static $log_source = 'rentopian-file-delete';

    public static function init() {
        // AJAX actions (authenticated admin only — no nopriv!)
        add_action( 'wp_ajax_rental_orphan_delete_start',         [ __CLASS__, 'ajax_start' ] );
        add_action( 'wp_ajax_rental_orphan_delete_orphans_start', [ __CLASS__, 'ajax_start_orphans_only' ] );
        add_action( 'wp_ajax_rental_orphan_delete_cancel',        [ __CLASS__, 'ajax_cancel' ] );
        add_action( 'wp_ajax_rental_orphan_delete_cancel_all',    [ __CLASS__, 'ajax_cancel_all' ] );

        // Inline UI/JS in admin footer
        add_action( 'admin_footer', [ __CLASS__, 'print_inline_ui_js' ] );
    }

    /* ------------------------------------------------------------------ */
    /*  Shared helpers                                                     */
    /* ------------------------------------------------------------------ */

    /**
     * Verify nonce + capability. Dies on failure.
     */
    private static function verify_request() {
        check_ajax_referer( 'rental_orphan_delete_nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
        }
    }

    /**
     * Get or create the persistent shared secret for token-based auth.
     *
     * @return string
     */
    private static function get_wp_token() {
        $wp_token = get_option( 'rental_delete_token', '' );
        if ( empty( $wp_token ) ) {
            $wp_token = wp_generate_password( 32, false );
            update_option( 'rental_delete_token', $wp_token, false );
        }
        return $wp_token;
    }

    /**
     * Sanitise and clamp the limit parameter.
     *
     * @return int
     */
    private static function get_limit() {
        $limit = isset( $_POST['limit'] ) ? (int) $_POST['limit'] : 5;
        return max( 1, min( 50, $limit ) );
    }

    /**
     * Call rental_curl, normalise the response, and send JSON.
     *
     * @param string $endpoint   e.g. 'sync-delete/start'
     * @param array  $body
     * @param string $log_label  Human label for logging.
     */
    private static function call_main_api( $endpoint, array $body, $log_label ) {
        $api_key = get_option( 'rental_api_key' );

        self::log( sprintf( '%s → calling Main API %s', $log_label, $endpoint ) );

        try {
            $resp = rental_curl( $endpoint, $api_key, true, $body );
            $data = is_object( $resp ) ? json_decode( wp_json_encode( $resp ), true ) : (array) $resp;

            self::log( sprintf( '%s → Main API responded OK', $log_label ) );
            wp_send_json_success( $data );
        } catch ( \Throwable $e ) {
            self::log( sprintf( '%s → Main API error: %s', $log_label, $e->getMessage() ), 'error' );
            wp_send_json_error( [ 'message' => $e->getMessage() ], 500 );
        }
    }

    /**
     * Log via shared Rental_Delete_Logger (always writes to wc-logs).
     */
    private static function log( $message, $level = 'info' ) {
        Rental_Delete_Logger::log( $message, $level );
    }

    /* ------------------------------------------------------------------ */
    /*  AJAX handlers                                                      */
    /* ------------------------------------------------------------------ */

    /** Start: product/product_variation images cleaner (BG via Main API) */
    public static function ajax_start() {
        self::verify_request();

        $wp_endpoint = get_rest_url( null, '/rentopian/v1/orphans/delete-chunk' );

        self::call_main_api( 'sync-delete/start', [
            'wp_endpoint' => $wp_endpoint,
            'wp_token'    => self::get_wp_token(),
            'start_index' => 0,
            'chunk_size'  => self::get_limit(),
        ], 'BG Product Images Delete' );
    }

    /** Start: TRUE orphan images cleaner (BG via Main API) */
    public static function ajax_start_orphans_only() {
        self::verify_request();

        $wp_endpoint = get_rest_url( null, '/rentopian/v1/orphans/delete-orphans-chunk' );

        self::call_main_api( 'sync-delete/start', [
            'wp_endpoint' => $wp_endpoint,
            'wp_token'    => self::get_wp_token(),
            'start_index' => 0,
            'chunk_size'  => self::get_limit(),
        ], 'BG Orphan Images Delete' );
    }

    /** Cancel specific run via sync_id */
    public static function ajax_cancel() {
        self::verify_request();

        $sync_id = isset( $_POST['sync_id'] ) ? sanitize_text_field( $_POST['sync_id'] ) : '';
        if ( empty( $sync_id ) ) {
            wp_send_json_error( [ 'message' => 'sync_id is required' ], 422 );
        }

        self::call_main_api( 'sync-delete/cancel', [
            'sync_id' => $sync_id,
        ], 'BG Delete Cancel (sync=' . $sync_id . ')' );
    }

    /** Cancel all running deletes */
    public static function ajax_cancel_all() {
        self::verify_request();

        self::call_main_api( 'sync-delete/cancel-all', [
            '_noop' => 1,
        ], 'BG Delete Cancel All' );
    }

    /* ------------------------------------------------------------------ */
    /*  Inline UI / JS                                                     */
    /* ------------------------------------------------------------------ */

    /** Inject controls into the background-tools anchor and wire to admin-ajax. */
    public static function print_inline_ui_js() {
        $nonce = wp_create_nonce( 'rental_orphan_delete_nonce' );
        ?>
        <script>
        (function($){
            function addButton(){
                // #rental-bg-tools is a dedicated anchor. The legacy
                // .rental-resume-bg fallback keeps these controls reachable
                // on a settings page rendered by an older template.
                var $anchor = $('#rental-bg-tools');
                if (!$anchor.length) { $anchor = $('.rental-resume-bg'); }
                if (!$anchor.length || $('#rental-orphan-delete-wrap').length) return;

                var html = '' +
                '<span id="rental-orphan-delete-wrap" style="margin-left:8px; display:inline-flex; gap:6px; align-items:center;">' +
                    '<input type="number" id="rental-od-limit" min="1" max="50" value="5" title="Batch size" style="width:70px" />' +
                    '<button type="button" class="button button-secondary" id="rental-start-orphan-delete" title="Delete the image files attached to products and their variations. Runs in the background.">Delete Product Images (Background)</button>' +
                    '<button type="button" class="button button-secondary" id="rental-start-orphans-only-delete" title="Delete image files that are no longer attached to any product, set or category. Runs in the background.">Delete Orphaned Images (Background)</button>' +
                    '<button type="button" class="button" id="rental-cancel-orphan-delete" disabled>Cancel</button>' +
                    '<button type="button" class="button" id="rental-cancel-all-orphan-delete">Cancel All</button>' +
                    '<span id="rental-od-status" style="margin-left:6px;color:#555;"></span>' +
                '</span>';

                $anchor.after(html);

                var currentSyncId = null;
                var nonce = <?php echo wp_json_encode( $nonce ); ?>;

                function setBusy(b){
                    $('#rental-start-orphan-delete, #rental-start-orphans-only-delete, #rental-od-limit').prop('disabled', b);
                    $('#rental-cancel-orphan-delete').prop('disabled', !b || !currentSyncId);
                }
                function setStatus(msg){ $('#rental-od-status').text(msg || ''); }

                function startDelete(actionName, label) {
                    setBusy(true);
                    setStatus(label + ' – queueing…');

                    var limit = Math.max(1, Math.min(50, parseInt($('#rental-od-limit').val() || '5', 10) || 5));

                    $.ajax({
                        url: ajaxurl,
                        method: 'POST',
                        dataType: 'json',
                        data: {
                            action: actionName,
                            _ajax_nonce: nonce,
                            limit: limit
                        }
                    }).done(function(resp){
                        if (!resp || !resp.success) {
                            var errMsg = 'Failed';
                            if (resp && resp.data) {
                                errMsg = resp.data.message || resp.data.error || JSON.stringify(resp.data);
                            }
                            setStatus(errMsg);
                            setBusy(false);
                            return;
                        }
                        var d = resp.data || {};
                        currentSyncId = d.sync_id || null;
                        var statusMsg = d.message || 'queued';
                        setStatus(label + ' – ' + statusMsg + (currentSyncId ? (' (sync ' + currentSyncId + ')') : ''));
                        setBusy(true);
                        $('#rental-cancel-orphan-delete').prop('disabled', !currentSyncId);
                    }).fail(function(xhr){
                        var msg = 'Error';
                        try { var d = JSON.parse(xhr.responseText); if (d && d.data && d.data.message) msg = d.data.message; } catch(e){ msg = xhr.statusText || 'Network error'; }
                        setStatus(msg);
                        setBusy(false);
                    });
                }

                $('#rental-start-orphan-delete').on('click', function(e){
                    e.preventDefault();
                    startDelete('rental_orphan_delete_start', 'Product images cleanup');
                });

                $('#rental-start-orphans-only-delete').on('click', function(e){
                    e.preventDefault();
                    startDelete('rental_orphan_delete_orphans_start', 'Orphaned images cleanup');
                });

                $('#rental-cancel-orphan-delete').on('click', function(e){
                    e.preventDefault();
                    if (!currentSyncId) return;
                    setStatus('Canceling…');
                    $.ajax({
                        url: ajaxurl,
                        method: 'POST',
                        dataType: 'json',
                        data: {
                            action: 'rental_orphan_delete_cancel',
                            _ajax_nonce: nonce,
                            sync_id: currentSyncId
                        }
                    }).always(function(){
                        setStatus('Canceled' + (currentSyncId ? (' (sync ' + currentSyncId + ')') : ''));
                        currentSyncId = null;
                        setBusy(false);
                    });
                });

                $('#rental-cancel-all-orphan-delete').on('click', function(e){
                    e.preventDefault();
                    setStatus('Canceling all…');
                    $.ajax({
                        url: ajaxurl,
                        method: 'POST',
                        dataType: 'json',
                        data: {
                            action: 'rental_orphan_delete_cancel_all',
                            _ajax_nonce: nonce
                        }
                    }).always(function(){
                        setStatus('Canceled all');
                        currentSyncId = null;
                        setBusy(false);
                    });
                });
            }

            $(addButton);
            setTimeout(addButton, 800);
            document.addEventListener('woocommerce_init', addButton);
        })(jQuery);
        </script>
        <?php
    }
}
