<?php
/**
 * Checkout Layout System Loader
 *
 * Loads all checkout layout classes and initializes the system.
 * This file should be required from the main plugin file.
 *
 * Phase 1: Admin configuration (JSON editor, field registry)
 * Phase 2: Frontend rendering (layout renderer, field renderer)
 *
 * @package    Rentopian_Sync
 * @subpackage Rentopian_Sync/includes/checkout
 * @since      2.13.0
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Load checkout layout classes
 *
 * Order matters: Config first, then Registry, then Manager, then Renderers
 */

// Configuration handler - stores/retrieves layout settings
require_once RENTOPIAN_SYNC_PATH . '/includes/checkout/class-checkout-layout-config.php';

// Field registry - defines available fields
require_once RENTOPIAN_SYNC_PATH . '/includes/checkout/class-checkout-field-registry.php';

// Template manager - handles saving/loading layout templates
require_once RENTOPIAN_SYNC_PATH . '/includes/checkout/class-checkout-template-manager.php';

// Main manager - coordinates everything, handles admin UI
require_once RENTOPIAN_SYNC_PATH . '/includes/checkout/class-checkout-layout-manager.php';

// Frontend renderers (Phase 2)
require_once RENTOPIAN_SYNC_PATH . '/includes/checkout/class-checkout-layout-renderer.php';
// Note: class-checkout-field-renderer.php is loaded by layout-renderer when needed

// Validator - handles required field validation during checkout process
require_once RENTOPIAN_SYNC_PATH . '/includes/checkout/class-checkout-validator.php';

// Order Display - handles order-received and my-account order views
require_once RENTOPIAN_SYNC_PATH . '/includes/checkout/class-checkout-order-display.php';

// Photo Upload - handles AJAX upload, order attachment, and API forwarding
require_once RENTOPIAN_SYNC_PATH . '/includes/class-rental-checkout-photo-upload.php';

/**
 * Initialize the checkout layout manager
 *
 * We use a function hooked to 'init' to ensure WordPress is fully loaded.
 * The manager will only register admin hooks when in admin context.
 */
function rental_init_checkout_layout_manager() {
    // Only initialize if WooCommerce is active
    if (!class_exists('WooCommerce')) {
        return;
    }

    // Get the manager instance (singleton)
    // This will register all hooks automatically
    Rental_Checkout_Layout_Manager::get_instance();

    // Initialize the template manager (needed for admin AJAX)
    if (is_admin()) {
        Rental_Checkout_Template_Manager::get_instance();
    }

    // Initialize photo upload handler (registers AJAX hooks)
    if (class_exists('Rental_Checkout_Photo_Upload')) {
        Rental_Checkout_Photo_Upload::get_instance();
    }
}
add_action('init', 'rental_init_checkout_layout_manager', 5);

/**
 * Initialize the frontend checkout renderer
 *
 * Initializes on 'wp' hook to ensure conditional tags like is_checkout() work.
 * Only activates when modern checkout layout is enabled.
 */
function rental_init_checkout_layout_renderer() {
    // Only initialize if WooCommerce is active
    if (!class_exists('WooCommerce')) {
        return;
    }

    // Only on frontend (not admin)
    if (is_admin()) {
        return;
    }

    // Get the renderer instance and initialize it
    $renderer = rental_checkout_layout_renderer();
    if ($renderer) {
        $renderer->init();
    }
    
    // Initialize order display for order-received and my-account pages
    // CRITICAL: Only initialize if modern checkout is enabled to avoid affecting classic mode
    if (class_exists('Rental_Checkout_Order_Display')) {
        $config = Rental_Checkout_Layout_Config::get_instance();
        if ($config->is_enabled()) {
            Rental_Checkout_Order_Display::get_instance();
        }
    }
}
add_action('wp', 'rental_init_checkout_layout_renderer', 10);

/**
 * Register the base-country address-locale override early.
 *
 * WooCommerce caches the country locale on first read, so the
 * woocommerce_get_country_locale filter must be in place before anything reads
 * it (the renderer's own init runs later, on 'wp'). Without this, the required
 * asterisk / visibility for the base country's state/postcode/city can be lost.
 */
function rental_register_base_country_locale_filter() {
    if (is_admin() || !class_exists('WooCommerce')) {
        return;
    }
    if (!class_exists('Rental_Checkout_Layout_Config')
        || !Rental_Checkout_Layout_Config::get_instance()->is_enabled()) {
        return;
    }
    $renderer = rental_checkout_layout_renderer();
    if ($renderer) {
        add_filter('woocommerce_get_country_locale', array($renderer, 'force_required_address_locale'), 100);
    }
}
add_action('init', 'rental_register_base_country_locale_filter', 6);

/**
 * Initialize the checkout validator
 *
 * Initializes early to hook into woocommerce_checkout_process.
 * The validator handles required field validation for modern checkout layout.
 * 
 * CRITICAL: This must run before checkout AJAX is processed to validate fields.
 */
function rental_init_checkout_validator() {
    // Only initialize if WooCommerce is active
    if (!class_exists('WooCommerce')) {
        return;
    }

    // The validator class auto-initializes via plugins_loaded hook
    // This is a backup initialization
    if (class_exists('Rental_Checkout_Validator')) {
        Rental_Checkout_Validator::get_instance();
    }
}
add_action('init', 'rental_init_checkout_validator', 5); // Priority 5 = early

// Also ensure it loads on wp_loaded as another backup
add_action('wp_loaded', 'rental_init_checkout_validator', 5);

/**
 * Diagnostic endpoint for the modern checkout submit-stuck recovery.
 *
 * The frontend posts here when it detects and clears a stale checkout lock
 * (or forces a watchdog recovery), so the deadlock condition is captured in
 * the order logs for analysis. Logging only; never alters checkout behavior.
 */
function rental_checkout_diag_log() {
    // Logging-only endpoint; verify the nonce so the nopriv hook cannot be
    // used to flood the log. Silently succeed on failure (no behavior change).
    $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
    if (!wp_verify_nonce($nonce, 'rentopian_checkout_layout')) {
        wp_send_json_success();
    }

    if (!class_exists('Project_WP_Logger')) {
        wp_send_json_success();
    }

    $state_raw = isset($_POST['state']) ? wp_unslash($_POST['state']) : '';
    $state = json_decode($state_raw, true);
    if (!is_array($state)) {
        $state = array('raw' => substr((string) $state_raw, 0, 300));
    }

    $reason = isset($state['reason']) ? sanitize_text_field($state['reason']) : 'unknown';
    $parts = array();
    foreach ($state as $key => $value) {
        if ($key === 'reason') {
            continue;
        }
        $parts[] = sanitize_key($key) . '=' . sanitize_text_field(is_scalar($value) ? (string) $value : wp_json_encode($value));
    }

    Project_WP_Logger::write(
        'CHECKOUT_STUCK | reason=' . $reason . ' | ' . implode(' | ', $parts),
        'warning',
        'rentopian-checkout'
    );

    wp_send_json_success();
}
add_action('wp_ajax_rental_checkout_diag', 'rental_checkout_diag_log');
add_action('wp_ajax_nopriv_rental_checkout_diag', 'rental_checkout_diag_log');

/**
 * Guard the checkout request against a fatal PHP error during order processing.
 *
 * WooCommerce's process_checkout() only catches Exception, so a fatal Error /
 * TypeError thrown by any hook between validation and order save escapes
 * uncaught: the request dies with no JSON body and the browser's checkout AJAX
 * never resolves, leaving the customer stuck under the loading overlay with no
 * way forward except a page reload.
 *
 * Armed on woocommerce_checkout_process (only fires during a checkout submit),
 * this registers a shutdown handler that detects such a fatal, logs its exact
 * location, and emits the JSON failure response WooCommerce's frontend expects
 * so the form unlocks and the customer can retry. Only acts on a genuine fatal;
 * normal success/failure responses are untouched.
 */
function rental_arm_checkout_fatal_guard() {
    static $armed = false;
    if ($armed) {
        return;
    }

    // Modern checkout only — classic checkout keeps default WooCommerce behavior.
    $is_modern_checkout = get_option('rental_dates_on_checkout', 0) == 1
        && get_option('rental_allow_overbook', 1) == 1
        && get_option('rental_checkout_layout_mode', 'classic') === 'modern';
    if (!$is_modern_checkout) {
        return;
    }

    $armed = true;
    register_shutdown_function('rental_checkout_fatal_shutdown');
}
add_action('woocommerce_checkout_process', 'rental_arm_checkout_fatal_guard', 1);

/**
 * Shutdown handler: if the checkout request ended on a fatal error, log it and
 * return a JSON failure so the frontend unblocks. No-op on normal completion.
 */
function rental_checkout_fatal_shutdown() {
    $error = error_get_last();
    $fatal_mask = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR | E_RECOVERABLE_ERROR;

    // Nothing fatal happened (normal success/failure already responded, or only
    // notices/deprecations remain) — leave the response untouched.
    if (!$error || !($error['type'] & $fatal_mask)) {
        return;
    }

    if (class_exists('Project_WP_Logger')) {
        Project_WP_Logger::write(
            'CHECKOUT_FATAL | ' . $error['message'] . ' | ' . $error['file'] . ':' . $error['line'],
            'critical',
            'rentopian-checkout'
        );
    }

    // If a response was already sent we cannot safely replace it; the client
    // watchdog covers that rare case.
    if (headers_sent()) {
        return;
    }

    // Discard any partial output (e.g. a server error fragment) before the JSON.
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }

    if (function_exists('status_header')) {
        status_header(200);
    }
    header('Content-Type: application/json; charset=utf-8');

    $message = function_exists('__')
        ? __('Something went wrong while placing your order. Please review your details and try again.', 'rentopian-sync')
        : 'Something went wrong while placing your order. Please review your details and try again.';

    echo wp_json_encode(array(
        'result'   => 'failure',
        'messages' => '<div class="woocommerce-error">' . esc_html($message) . '</div>',
        'refresh'  => false,
        'reload'   => false,
    ));
}

/**
 * Suppress generic "Please fill in all required fields" and deduplicate checkout error notices
 * so the same logical field never shows more than one error (WC + rental validator must not double up).
 * 
 * CRITICAL: Only applies to modern checkout mode to maintain backward compatibility with classic mode.
 */
function rental_checkout_filter_error_notices($message) {
    // Only filter errors for modern checkout - classic mode uses default WooCommerce behavior
    $is_modern_checkout = get_option('rental_dates_on_checkout', 0) == 1
        && get_option('rental_allow_overbook', 1) == 1
        && get_option('rental_checkout_layout_mode', 'classic') === 'modern';
    
    if (!$is_modern_checkout) {
        return $message;
    }
    
    if (!is_string($message) || $message === '') {
        return $message;
    }
    $plain = wp_strip_all_tags($message);
    $lower = strtolower($plain);

    // Suppress generic catch-all message
    if (stripos($plain, 'Please fill in all required fields') !== false) {
        return '';
    }

    static $seen = array();

    // 1) Dedupe by "X is a required field" / "X is required" – extract field name and normalize to key
    if (preg_match('/^(.+?)\s+is\s+(?:a\s+)?required\s+field\.?\s*$/i', $plain, $m) ||
        preg_match('/^(.+?)\s+is\s+required\.?\s*$/i', $plain, $m) ||
        preg_match('/required\s+field[:\s]+(.+)$/i', $plain, $m)) {
        $field_label = trim($m[1]);
        $key = 'field_' . preg_replace('/[^a-z0-9]+/i', '_', strtolower($field_label));
        $key = trim($key, '_');
        if ($key !== '' && isset($seen[$key])) {
            return '';
        }
        if ($key !== '') {
            $seen[$key] = true;
        }
        return $message;
    }

    // 2) Dedupe by known field keywords (same logical field = one error)
    $keyword_to_key = array(
        'email'                => 'email',
        'e-mail'               => 'email',
        'first name'           => 'first_name',
        'last name'             => 'last_name',
        'phone'                => 'phone',
        'telephone'             => 'phone',
        'how did you find us'   => 'referral',
        'referral'              => 'referral',
        'event type'            => 'event_type',
        'delivery time'         => 'delivery_time',
        'pickup time'           => 'pickup_time',
        'address'               => 'address',
        'street'                => 'address',
        'city'                  => 'city',
        'town'                  => 'city',
        'state'                 => 'state',
        'province'              => 'state',
        'region'                => 'state',
        'postcode'              => 'postcode',
        'zip'                   => 'postcode',
        'postal'                => 'postcode',
        'country'               => 'country',
    );

    foreach ($keyword_to_key as $keyword => $key) {
        if (strpos($lower, $keyword) !== false) {
            if (!empty($seen[$key])) {
                return '';
            }
            $seen[$key] = true;
            return $message;
        }
    }

    return $message;
}
add_filter('woocommerce_add_error', 'rental_checkout_filter_error_notices', 20);

/**
 * Helper function to get the checkout layout manager instance
 *
 * @return Rental_Checkout_Layout_Manager|null
 */
function rental_checkout_layout_manager() {
    if (!class_exists('Rental_Checkout_Layout_Manager')) {
        return null;
    }
    return Rental_Checkout_Layout_Manager::get_instance();
}

/**
 * Helper function to get the checkout layout configuration
 *
 * @return array The current layout configuration
 */
function rental_get_checkout_layout() {
    $manager = rental_checkout_layout_manager();
    if ($manager) {
        return $manager->get_config()->get_layout();
    }
    return array();
}

/**
 * Helper function to check if custom checkout layout is enabled
 *
 * @return bool
 */
function rental_is_custom_checkout_enabled() {
    $manager = rental_checkout_layout_manager();
    if ($manager) {
        return $manager->get_config()->is_enabled();
    }
    return false;
}

/**
 * Helper function to get the checkout layout renderer instance
 *
 * @return Rental_Checkout_Layout_Renderer|null
 */
function rental_get_checkout_renderer() {
    if (!class_exists('Rental_Checkout_Layout_Renderer')) {
        return null;
    }
    return Rental_Checkout_Layout_Renderer::get_instance();
}

/**
 * Helper function to check if we're using modern checkout on current page
 *
 * @return bool
 */
function rental_is_modern_checkout_active() {
    if (!is_checkout() || is_wc_endpoint_url()) {
        return false;
    }
    
    return rental_is_custom_checkout_enabled();
}

/**
 * Helper function to get field registry instance
 *
 * @return Rental_Checkout_Field_Registry|null
 */
function rental_get_field_registry() {
    if (!class_exists('Rental_Checkout_Field_Registry')) {
        return null;
    }
    return Rental_Checkout_Field_Registry::get_instance();
}

/**
 * Helper function to get a specific field from registry
 *
 * @param string $field_id The field ID
 * @return array|null Field configuration or null if not found
 */
function rental_get_checkout_field($field_id) {
    $registry = rental_get_field_registry();
    if ($registry) {
        return $registry->get_field($field_id);
    }
    return null;
}

/**
 * Helper function to get the template manager instance
 *
 * @return Rental_Checkout_Template_Manager|null
 */
function rental_get_template_manager() {
    if (!class_exists('Rental_Checkout_Template_Manager')) {
        return null;
    }
    return Rental_Checkout_Template_Manager::get_instance();
}

/**
 * Helper function to get all saved templates
 *
 * @param bool $include_layout Whether to include full layout data
 * @return array Array of templates
 */
function rental_get_checkout_templates($include_layout = false) {
    $manager = rental_get_template_manager();
    if ($manager) {
        return $manager->get_templates($include_layout);
    }
    return array();
}

/**
 * Check if different pickup address feature is enabled
 *
 * @return bool
 */
function rental_is_different_pickup_address_enabled() {
    $use_rentopian_shipping = get_option('rental_do_not_use_rentopian_shipping');
    
    if ($use_rentopian_shipping) {
        return false;
    }
    
    $delivery_settings = function_exists('rental_get_delivery_settings') ? rental_get_delivery_settings() : array();
    
    return isset($delivery_settings['enable_different_pickup_delivery_address_for_website']) 
        && $delivery_settings['enable_different_pickup_delivery_address_for_website'];
}

/**
 * Check if damage waiver feature is enabled
 *
 * Conditions:
 * - rental_get_damage_waiver() must return truthy
 * - rental_hide_damage_waiver must be false
 * - rental_hide_and_buy_damage_waiver_by_default must be false
 * - the cart must contain items the waiver can be charged on
 *
 * @return bool
 */
function rental_is_damage_waiver_enabled() {
    // Check if damage waiver data exists
    if (!function_exists('rental_get_damage_waiver') || !rental_get_damage_waiver()) {
        return false;
    }

    // Check if it should be hidden
    if (get_option('rental_hide_damage_waiver')) {
        return false;
    }

    // Check if auto-buy is enabled (which hides the UI)
    if (get_option('rental_hide_and_buy_damage_waiver_by_default')) {
        return false;
    }

    // Offering the choice is pointless when the fee would be zero
    if (function_exists('rental_cart_has_damage_waiver_fee') && !rental_cart_has_damage_waiver_fee()) {
        return false;
    }

    return true;
}

/**
 * Check if event start time feature is enabled
 *
 * @return bool
 */
function rental_is_event_start_time_enabled() {
    return (bool) get_option('rental_event_start_time', false);
}
