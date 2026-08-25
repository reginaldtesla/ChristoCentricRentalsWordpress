<?php
/**
 * Checkout Layout Manager
 *
 * Main orchestrator class for the checkout layout system.
 * Handles admin interface, AJAX actions, and coordinates components.
 *
 * @package    Rentopian_Sync
 * @subpackage Rentopian_Sync/includes/checkout
 * @since      2.13.0
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Class Rental_Checkout_Layout_Manager
 *
 * Central controller for the checkout layout customization feature.
 * Responsibilities:
 * - Register admin menu pages
 * - Handle AJAX requests for save/load/reset
 * - Enqueue admin assets
 * - Coordinate between Config and Field Registry
 */
class Rental_Checkout_Layout_Manager {

    /**
     * Singleton instance
     *
     * @var Rental_Checkout_Layout_Manager|null
     */
    private static $instance = null;

    /**
     * Configuration handler instance
     *
     * @var Rental_Checkout_Layout_Config
     */
    private $config;

    /**
     * Field registry instance
     *
     * @var Rental_Checkout_Field_Registry
     */
    private $registry;

    /**
     * Admin page hook suffix
     *
     * @var string
     */
    private $admin_page_hook;

    /**
     * Get singleton instance
     *
     * @return Rental_Checkout_Layout_Manager
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Private constructor for singleton
     */
    private function __construct() {
        // Get dependencies
        $this->config = Rental_Checkout_Layout_Config::get_instance();
        $this->registry = Rental_Checkout_Field_Registry::get_instance();

        // Initialize hooks
        $this->init_hooks();
    }

    /**
     * Initialize WordPress hooks
     */
    private function init_hooks() {
        // Admin-only hooks
        if (is_admin()) {
            // Admin menu
            add_action('admin_menu', array($this, 'add_admin_menu'), 20);

            // Admin assets
            add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));

            // AJAX handlers (these need to be registered for admin AJAX to work)
            add_action('wp_ajax_rental_checkout_layout_save', array($this, 'ajax_save_layout'));
            add_action('wp_ajax_rental_checkout_layout_get', array($this, 'ajax_get_layout'));
            add_action('wp_ajax_rental_checkout_layout_reset', array($this, 'ajax_reset_layout'));
            add_action('wp_ajax_rental_checkout_layout_import', array($this, 'ajax_import_layout'));
            add_action('wp_ajax_rental_checkout_layout_export', array($this, 'ajax_export_layout'));
            add_action('wp_ajax_rental_checkout_layout_toggle', array($this, 'ajax_toggle_enabled'));
            add_action('wp_ajax_rental_checkout_layout_get_fields', array($this, 'ajax_get_fields'));
            add_action('wp_ajax_rental_checkout_layout_validate', array($this, 'ajax_validate_config'));
            add_action('wp_ajax_rental_checkout_layout_refresh_dynamic_fields', array($this, 'ajax_refresh_dynamic_fields'));
            add_action('wp_ajax_rental_checkout_save_thank_you', array($this, 'ajax_save_thank_you_message'));
            add_action('wp_ajax_rental_checkout_save_review_display', array($this, 'ajax_save_review_display_settings'));
            add_action('wp_ajax_rental_checkout_save_marketing', array($this, 'ajax_save_marketing_settings'));
            add_action('wp_ajax_rental_checkout_toggle_debug', array($this, 'ajax_toggle_debug'));
        }

    }

    /**
     * Add admin submenu page
     *
     * Adds "Checkout Layout" under the Rentopian Sync menu.
     */
    public function add_admin_menu() {
        // Use the same menu slug as the parent menu
        // The rental_get_admin_menu_slug() function is defined in functions.php
        if (!function_exists('rental_get_admin_menu_slug')) {
            return;
        }
        
        $parent_slug = rental_get_admin_menu_slug();

        $this->admin_page_hook = add_submenu_page(
            $parent_slug,
            __('Checkout Layout', 'rentopian-sync'),
            __('Checkout Layout', 'rentopian-sync'),
            'manage_woocommerce',
            'rentopian-checkout-layout',
            array($this, 'render_admin_page')
        );
    }

    /**
     * Enqueue admin assets
     *
     * @param string $hook The current admin page hook
     */
    public function enqueue_admin_assets($hook) {
        // Only load on our admin page
        if ($hook !== $this->admin_page_hook) {
            return;
        }

        // Enqueue CSS
        wp_enqueue_style(
            'rental-checkout-layout-admin',
            plugins_url('assets/css/checkout-layout-admin.css', dirname(dirname(__FILE__))),
            array(),
            RENTOPIAN_SYNC_VERSION
        );

        // Visual Builder CSS
        wp_enqueue_style(
            'rental-checkout-visual-builder',
            plugins_url('assets/css/checkout-visual-builder.css', dirname(dirname(__FILE__))),
            array(),
            RENTOPIAN_SYNC_VERSION
        );

        // jQuery UI Sortable for visual builder drag-and-drop
        wp_enqueue_script('jquery-ui-sortable');

        // Enqueue JS
        wp_enqueue_script(
            'rental-checkout-layout-admin',
            plugins_url('assets/js/checkout-layout-admin.js', dirname(dirname(__FILE__))),
            array('jquery', 'wp-util'),
            RENTOPIAN_SYNC_VERSION,
            true
        );

        // Visual Builder JS (depends on layout-admin for shared config)
        wp_enqueue_script(
            'rental-checkout-visual-builder',
            plugins_url('assets/js/checkout-visual-builder.js', dirname(dirname(__FILE__))),
            array('jquery', 'jquery-ui-sortable', 'rental-checkout-layout-admin'),
            RENTOPIAN_SYNC_VERSION,
            true
        );

        // Localize script with data
        wp_localize_script('rental-checkout-layout-admin', 'rentalCheckoutLayout', array(
            'ajaxUrl'         => admin_url('admin-ajax.php'),
            'nonce'           => wp_create_nonce('rental_checkout_layout_nonce'),
            'isEnabled'       => $this->config->is_enabled(),
            'currentLayout'   => $this->config->get_layout(),
            'fields'          => $this->registry->get_fields_for_admin(),
            'fieldGroups'     => $this->registry->get_groups_for_admin(),
            'mandatoryFields' => $this->config->get_mandatory_fields(),
            'hasGoogleApiKey' => $this->has_google_maps_api_key(),
            'strings'         => array(
                'saveSuccess'      => __('Layout saved successfully!', 'rentopian-sync'),
                'saveError'        => __('Error saving layout.', 'rentopian-sync'),
                'resetConfirm'     => __('Are you sure you want to reset to the default layout? This cannot be undone.', 'rentopian-sync'),
                'resetSuccess'     => __('Layout reset to default.', 'rentopian-sync'),
                'importSuccess'    => __('Layout imported successfully!', 'rentopian-sync'),
                'importError'      => __('Error importing layout.', 'rentopian-sync'),
                'invalidJson'      => __('Invalid JSON format. Please check your input.', 'rentopian-sync'),
                'validationError'  => __('Validation failed.', 'rentopian-sync'),
                'validationSuccess' => __('JSON is valid.', 'rentopian-sync'),
                'processing'       => __('Processing...', 'rentopian-sync'),
                'copiedToClipboard' => __('Copied to clipboard!', 'rentopian-sync'),
                'exportFilename'   => 'rentopian-checkout-layout.json',
                'refreshing'       => __('Fetching from Rentopian...', 'rentopian-sync'),
                'refreshSuccess'   => __('Custom fields refreshed successfully!', 'rentopian-sync'),
                'refreshError'     => __('Error refreshing custom fields.', 'rentopian-sync'),
                'reloadSuccess'    => __('Layout reloaded from server.', 'rentopian-sync'),
                'unsavedChanges'   => __('You have unsaved changes. Are you sure you want to proceed?', 'rentopian-sync'),
            ),
        ));
    }

    /**
     * Render the admin page
     */
    public function render_admin_page() {
        // Security check
        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'rentopian-sync'));
        }

        // Get current state
        $is_enabled = $this->config->is_enabled();
        $current_layout = $this->config->get_layout();
        $fields = $this->registry->get_fields_for_admin();
        $field_groups = $this->registry->get_groups_for_admin();
        
        // New variables for the updated template
        $requirements_status = $this->config->get_requirements_status();
        $mandatory_fields = $this->config->get_mandatory_fields();

        // Review order display settings
        $review_display_settings = self::get_review_display_settings();

        // Include the admin view template
        include RENTOPIAN_SYNC_PATH . '/templates/checkout/admin-checkout-layout.php';
    }

    /**
     * AJAX: Save layout configuration
     */
    public function ajax_save_layout() {
        // Verify nonce
        if (!$this->verify_ajax_nonce()) {
            return;
        }

        // Check permissions
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array(
                'message' => __('Permission denied.', 'rentopian-sync'),
            ), 403);
        }

        // Get JSON data
        $json = isset($_POST['layout']) ? wp_unslash($_POST['layout']) : '';

        if (empty($json)) {
            wp_send_json_error(array(
                'message' => __('No layout data provided.', 'rentopian-sync'),
            ), 400);
        }

        // Decode JSON
        $config = json_decode($json, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            wp_send_json_error(array(
                'message' => sprintf(
                    __('Invalid JSON: %s', 'rentopian-sync'),
                    json_last_error_msg()
                ),
            ), 400);
        }

        // Save configuration
        $result = $this->config->save_layout($config);

        if (is_wp_error($result)) {
            wp_send_json_error(array(
                'message' => $result->get_error_message(),
                'code'    => $result->get_error_code(),
            ), 400);
        }

        wp_send_json_success(array(
            'message' => $result['message'],
            'layout'  => $this->config->get_layout(),
        ));
    }

    /**
     * AJAX: Get current layout configuration
     */
    public function ajax_get_layout() {
        // Verify nonce
        if (!$this->verify_ajax_nonce()) {
            return;
        }

        // Check permissions
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array(
                'message' => __('Permission denied.', 'rentopian-sync'),
            ), 403);
        }

        // Check if we should bypass cache
        $force_refresh = !empty($_POST['nocache']);

        wp_send_json_success(array(
            'layout'      => $this->config->get_layout($force_refresh),
            'isEnabled'   => $this->config->is_enabled(),
            'fields'      => $this->registry->get_fields_for_admin(),
            'fieldGroups' => $this->registry->get_groups_for_admin(),
        ));
    }

    /**
     * AJAX: Reset layout to default
     */
    public function ajax_reset_layout() {
        // Verify nonce
        if (!$this->verify_ajax_nonce()) {
            return;
        }

        // Check permissions
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array(
                'message' => __('Permission denied.', 'rentopian-sync'),
            ), 403);
        }

        $default_layout = $this->config->reset_to_default();

        wp_send_json_success(array(
            'message' => __('Layout reset to default successfully.', 'rentopian-sync'),
            'layout'  => $default_layout,
        ));
    }

    /**
     * AJAX: Import layout from JSON
     */
    public function ajax_import_layout() {
        // Verify nonce
        if (!$this->verify_ajax_nonce()) {
            return;
        }

        // Check permissions
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array(
                'message' => __('Permission denied.', 'rentopian-sync'),
            ), 403);
        }

        // Get JSON data
        $json = isset($_POST['json']) ? wp_unslash($_POST['json']) : '';

        if (empty($json)) {
            wp_send_json_error(array(
                'message' => __('No JSON data provided.', 'rentopian-sync'),
            ), 400);
        }

        // Import the layout
        $result = $this->config->import_layout($json);

        if (is_wp_error($result)) {
            wp_send_json_error(array(
                'message' => $result->get_error_message(),
                'code'    => $result->get_error_code(),
            ), 400);
        }

        wp_send_json_success(array(
            'message' => $result['message'],
            'layout'  => $this->config->get_layout(),
        ));
    }

    /**
     * AJAX: Export layout as JSON
     */
    public function ajax_export_layout() {
        // Verify nonce
        if (!$this->verify_ajax_nonce()) {
            return;
        }

        // Check permissions
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array(
                'message' => __('Permission denied.', 'rentopian-sync'),
            ), 403);
        }

        $json = $this->config->export_layout();

        wp_send_json_success(array(
            'json' => $json,
        ));
    }

    /**
     * AJAX: Toggle custom layout enabled/disabled
     */
    public function ajax_toggle_enabled() {
        // Verify nonce
        if (!$this->verify_ajax_nonce()) {
            return;
        }

        // Check permissions
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array(
                'message' => __('Permission denied.', 'rentopian-sync'),
            ), 403);
        }

        // Get enabled state
        $enabled = isset($_POST['enabled']) ? filter_var($_POST['enabled'], FILTER_VALIDATE_BOOLEAN) : false;

        // Update state
        $this->config->set_enabled($enabled);

        wp_send_json_success(array(
            'message'   => $enabled
                ? __('Custom checkout layout enabled.', 'rentopian-sync')
                : __('Custom checkout layout disabled.', 'rentopian-sync'),
            'isEnabled' => $enabled,
        ));
    }

    /**
     * AJAX: Get available fields list
     */
    public function ajax_get_fields() {
        // Verify nonce
        if (!$this->verify_ajax_nonce()) {
            return;
        }

        // Check permissions
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array(
                'message' => __('Permission denied.', 'rentopian-sync'),
            ), 403);
        }

        wp_send_json_success(array(
            'fields'      => $this->registry->get_fields_for_admin(),
            'fieldGroups' => $this->registry->get_groups_for_admin(),
        ));
    }

    /**
     * AJAX: Validate configuration without saving
     */
    public function ajax_validate_config() {
        // Verify nonce
        if (!$this->verify_ajax_nonce()) {
            return;
        }

        // Check permissions
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array(
                'message' => __('Permission denied.', 'rentopian-sync'),
            ), 403);
        }

        // Get JSON data
        $json = isset($_POST['json']) ? wp_unslash($_POST['json']) : '';

        if (empty($json)) {
            wp_send_json_error(array(
                'message' => __('No JSON data provided.', 'rentopian-sync'),
            ), 400);
        }

        // Decode JSON
        $config = json_decode($json, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            wp_send_json_error(array(
                'message' => sprintf(
                    __('Invalid JSON: %s', 'rentopian-sync'),
                    json_last_error_msg()
                ),
                'valid' => false,
            ), 400);
        }

        // Validate configuration
        $validation = $this->config->validate_config($config);

        if (is_wp_error($validation)) {
            wp_send_json_error(array(
                'message' => $validation->get_error_message(),
                'code'    => $validation->get_error_code(),
                'valid'   => false,
            ), 400);
        }

        // Additional validation: Check field references
        $field_errors = $this->validate_field_references($config);

        if (!empty($field_errors)) {
            wp_send_json_success(array(
                'message'  => __('Configuration structure is valid, but some fields may not exist.', 'rentopian-sync'),
                'valid'    => true,
                'warnings' => $field_errors,
            ));
        }

        wp_send_json_success(array(
            'message' => __('Configuration is valid.', 'rentopian-sync'),
            'valid'   => true,
        ));
    }

    /**
     * Validate that all field references in config exist in registry
     *
     * @param array $config The configuration to check
     * @return array List of warning messages for unknown fields
     */
    private function validate_field_references($config) {
        $warnings = array();

        if (!isset($config['sections'])) {
            return $warnings;
        }

        foreach ($config['sections'] as $section) {
            if (!isset($section['rows'])) {
                continue;
            }
            foreach ($section['rows'] as $row) {
                if (!isset($row['columns'])) {
                    continue;
                }
                foreach ($row['columns'] as $column) {
                    if (!empty($column['field'])) {
                        $field_id = $column['field'];
                        if (!$this->registry->field_exists($field_id)) {
                            $warnings[] = sprintf(
                                __('Field "%s" is not recognized and may not render correctly.', 'rentopian-sync'),
                                $field_id
                            );
                        }
                    }
                }
            }
        }

        return array_unique($warnings);
    }

    /**
     * Whether a Google Address Autocomplete key is configured.
     *
     * @return bool
     */
    private function has_google_maps_api_key() {
        $api_key = get_option('rental_google_map_key', '');
        return !empty($api_key) && strlen($api_key) > 10;
    }

    /**
     * Verify AJAX nonce
     *
     * @return bool True if valid, sends error response and returns false if invalid
     */
    private function verify_ajax_nonce() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'rental_checkout_layout_nonce')) {
            wp_send_json_error(array(
                'message' => __('Security check failed. Please refresh the page and try again.', 'rentopian-sync'),
            ), 403);
            return false;
        }
        return true;
    }

    /**
     * Get the configuration handler
     *
     * @return Rental_Checkout_Layout_Config
     */
    public function get_config() {
        return $this->config;
    }

    /**
     * Get the field registry
     *
     * @return Rental_Checkout_Field_Registry
     */
    public function get_registry() {
        return $this->registry;
    }

    /**
     * AJAX handler: Refresh dynamic fields from Rentopian API
     *
     * Calls the Rentopian API to get the latest custom fields
     * and updates the local option. Then reinitializes the registry.
     */
    public function ajax_refresh_dynamic_fields() {
        // Verify nonce
        if (!$this->verify_ajax_nonce()) {
            return;
        }

        // Check permissions
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array(
                'message' => __('You do not have permission to perform this action.', 'rentopian-sync'),
            ), 403);
            return;
        }

        // Check if rental_get_custom_fields function exists
        if (!function_exists('rental_get_custom_fields')) {
            wp_send_json_error(array(
                'message' => __('The rental_get_custom_fields function is not available.', 'rentopian-sync'),
            ));
            return;
        }

        try {
            // Call the Rentopian API to get fresh custom fields
            // This function also updates the rental_custom_fields option
            $custom_fields = rental_get_custom_fields();
            
            if ($custom_fields === false || is_null($custom_fields)) {
                wp_send_json_error(array(
                    'message' => __('Failed to fetch custom fields from Rentopian API. Please check your API key.', 'rentopian-sync'),
                ));
                return;
            }

            // Count the fields for the response
            $field_count = 0;
            if (is_array($custom_fields)) {
                foreach ($custom_fields as $group) {
                    if (is_array($group)) {
                        $field_count += count($group);
                    }
                }
            }

            // Reinitialize the registry to pick up the new fields
            // Since it's a singleton, we need to reset it
            $this->registry->refresh_dynamic_fields();

            // Get the updated fields for the admin UI
            $fields = $this->registry->get_fields_for_admin();
            $field_groups = $this->registry->get_groups_for_admin();

            wp_send_json_success(array(
                'message'     => sprintf(
                    __('Successfully refreshed %d custom fields from Rentopian.', 'rentopian-sync'),
                    $field_count
                ),
                'field_count' => $field_count,
                'fields'      => $fields,
                'fieldGroups' => $field_groups,
            ));

        } catch (Exception $e) {
            wp_send_json_error(array(
                'message' => sprintf(
                    __('Error refreshing custom fields: %s', 'rentopian-sync'),
                    $e->getMessage()
                ),
            ));
        }
    }

    /**
     * AJAX: Save thank you message and display options
     */
    public function ajax_save_thank_you_message() {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'rental_checkout_thank_you_save')) {
            wp_send_json_error(array(
                'message' => __('Security check failed.', 'rentopian-sync'),
            ), 403);
        }

        // Check permissions
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array(
                'message' => __('Permission denied.', 'rentopian-sync'),
            ), 403);
        }

        // Get message content
        $message = isset($_POST['message']) ? wp_kses_post(wp_unslash($_POST['message'])) : '';

        // Get show_only option
        $show_only = isset($_POST['show_only']) && $_POST['show_only'] === '1' ? '1' : '';

        // Save both settings
        update_option('rental_checkout_thank_you_message', $message);
        update_option('rental_checkout_thank_you_only', $show_only);

        wp_send_json_success(array(
            'message' => __('Settings saved successfully.', 'rentopian-sync'),
        ));
    }

    // =========================================================================
    // Review Order Display Settings
    // =========================================================================

    /**
     * Option key for review order display settings.
     */
    const REVIEW_DISPLAY_OPTION = 'rental_checkout_review_display_settings';

    /**
     * All toggleable review-order items with their default visibility.
     *
     * @return array Keyed by item slug, value = array with label + default bool.
     */
    public static function get_review_display_defaults() {
        return array(
            'rental_dates'   => array('label' => __('Rental Date(s) Summary', 'rentopian-sync'),    'default' => true),
            'cart_items'     => array('label' => __('Cart Items (Products)', 'rentopian-sync'),      'default' => true),
            'subtotal'       => array('label' => __('Subtotal', 'rentopian-sync'),                   'default' => true),
            'shipping'       => array('label' => __('Shipping / Delivery', 'rentopian-sync'),        'default' => true),
            'fees'           => array('label' => __('Fees', 'rentopian-sync'),                       'default' => true),
            'tax'            => array('label' => __('Tax', 'rentopian-sync'),                        'default' => true),
            'coupon'         => array('label' => __('Coupon', 'rentopian-sync'),                     'default' => true),
            'order_total'    => array('label' => __('Order Total', 'rentopian-sync'),                'default' => true),
            'cart_actions'   => array('label' => __('Note & Coupon Actions', 'rentopian-sync'),      'default' => true),
            'payment_info'   => array('label' => __('Payment Information', 'rentopian-sync'),        'default' => true),
        );
    }

    /**
     * Retrieve saved review display settings, merged with defaults.
     *
     * @return array Keyed by item slug → bool (true = visible).
     */
    public static function get_review_display_settings() {
        $saved    = get_option(self::REVIEW_DISPLAY_OPTION, array());
        $defaults = self::get_review_display_defaults();
        $merged   = array();
        foreach ($defaults as $key => $def) {
            $merged[$key] = isset($saved[$key]) ? (bool) $saved[$key] : $def['default'];
        }
        return $merged;
    }

    /**
     * Check if a specific review-order item is visible.
     *
     * @param string $item_key One of the keys in get_review_display_defaults().
     * @return bool
     */
    public static function is_review_item_visible($item_key) {
        $settings = self::get_review_display_settings();
        return isset($settings[$item_key]) ? $settings[$item_key] : true;
    }

    /**
     * AJAX: Save review order display settings.
     */
    public function ajax_save_review_display_settings() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'rental_checkout_layout_nonce')) {
            wp_send_json_error(array('message' => __('Security check failed.', 'rentopian-sync')), 403);
        }
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('Permission denied.', 'rentopian-sync')), 403);
        }

        $raw      = isset($_POST['settings']) ? $_POST['settings'] : array();
        $defaults = self::get_review_display_defaults();
        $clean    = array();

        foreach ($defaults as $key => $def) {
            // Checkbox: present in POST → true, absent → false
            $clean[$key] = !empty($raw[$key]);
        }

        update_option(self::REVIEW_DISPLAY_OPTION, $clean);

        wp_send_json_success(array(
            'message'  => __('Review order display settings saved.', 'rentopian-sync'),
            'settings' => $clean,
        ));
    }

    // =========================================================================
    // MARKETING SECTION
    // =========================================================================

    /**
     * AJAX: Save marketing section settings.
     */
    public function ajax_save_marketing_settings() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'rental_checkout_marketing_section_save')) {
            wp_send_json_error(array('message' => __('Security check failed.', 'rentopian-sync')), 403);
        }
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('Permission denied.', 'rentopian-sync')), 403);
        }

        $enabled   = isset($_POST['enabled']) ? sanitize_text_field($_POST['enabled']) : '0';
        $banner_id = isset($_POST['banner_id']) ? absint($_POST['banner_id']) : '';
        $content   = isset($_POST['content']) ? wp_kses_post(wp_unslash($_POST['content'])) : '';

        update_option('rental_checkout_marketing_enabled', $enabled);
        update_option('rental_checkout_marketing_banner_id', $banner_id);
        update_option('rental_checkout_marketing_content', $content);

        wp_send_json_success(array(
            'message' => __('Marketing settings saved successfully.', 'rentopian-sync'),
        ));
    }


    /**
     * AJAX: Toggle checkout JS debug mode
     *
     * @since 2.15.0
     * @return void
     */
    public function ajax_toggle_debug() {
        check_ajax_referer('rental_checkout_layout_nonce', 'nonce');

        if ( ! current_user_can('manage_woocommerce') ) {
            wp_send_json_error(array('message' => __('Permission denied.', 'rentopian-sync')), 403);
        }

        $enabled = isset($_POST['enabled']) ? sanitize_text_field($_POST['enabled']) : '0';
        update_option('rental_checkout_debug_mode', $enabled === '1' ? '1' : '0');

        wp_send_json_success(array(
            'message' => $enabled === '1'
                ? __('Debug mode enabled. Console logging is now active on checkout.', 'rentopian-sync')
                : __('Debug mode disabled.', 'rentopian-sync'),
            'enabled' => $enabled === '1',
        ));
    }
}
