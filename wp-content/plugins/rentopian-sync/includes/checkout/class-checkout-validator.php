<?php
/**
 * Checkout Validator for Modern Checkout Layout
 *
 * CRITICAL: This validator ensures required fields are validated during checkout.
 *
 * @package Rentopian_Sync
 * @subpackage Checkout_Layout
 * @since 1.3.7
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class Rental_Checkout_Validator
 */
class Rental_Checkout_Validator {

    /**
     * Singleton instance
     *
     * @var Rental_Checkout_Validator|null
     */
    private static $instance = null;

    /**
     * Debug mode
     *
     * @var bool
     */
    private $debug = false;

    /**
     * Get singleton instance
     *
     * @return Rental_Checkout_Validator
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        $this->debug = defined('WP_DEBUG') && WP_DEBUG;
        
        // Hook into checkout process with EARLY priority (before rental_checkout_process at 10)
        // Using priority 5 to run before other validations
        add_action('woocommerce_checkout_process', array($this, 'validate_checkout_fields'), 5);
        
        $this->debug_log('Rental_Checkout_Validator initialized');
    }

    /**
     * Check if modern checkout is enabled
     *
     * @return bool
     */
    private function is_modern_checkout_enabled() {
        $dates_on_checkout = get_option('rental_dates_on_checkout', 0);
        $allow_overbook = get_option('rental_allow_overbook', 1);
        $layout_mode = get_option('rental_checkout_layout_mode', 'classic');
        
        $is_enabled = (
            $dates_on_checkout == 1 &&
            $allow_overbook == 1 &&
            $layout_mode === 'modern'
        );
        
        $this->debug_log("Modern checkout check: dates_on_checkout=$dates_on_checkout, allow_overbook=$allow_overbook, layout_mode=$layout_mode, enabled=" . ($is_enabled ? 'yes' : 'no'));
        
        return $is_enabled;
    }

    /**
     * Main validation method - hooked to woocommerce_checkout_process
     */
    public function validate_checkout_fields() {
        $this->debug_log('=== CHECKOUT VALIDATION STARTED ===');
        $this->debug_log('POST data: ' . print_r(array_keys($_POST), true));

        // Only validate layout-specific fields if modern checkout is enabled
        if (!$this->is_modern_checkout_enabled()) {
            $this->debug_log('Modern checkout not enabled, skipping layout validation');
            return;
        }

        // Validate required photo upload
        $this->validate_photo_upload();

        // WC validates billing/shipping (field defs kept during AJAX). We validate rental components
        // and layout custom fields only; duplicate errors are suppressed by rental_checkout_filter_error_notices.

        // Block checkout when miles-based shipping is chosen but address is incomplete
        $this->validate_miles_based_address();

        // Validate referral source if enabled and required
        $this->validate_referral_source();

        // Validate event type if enabled and required
        $this->validate_event_type();

        // Validate delivery time selections
        $this->validate_delivery_time();

        // Validate pickup time selections
        $this->validate_pickup_time();

        // Validate dynamic custom fields from layout
        $this->validate_layout_fields();

        $this->debug_log('=== CHECKOUT VALIDATION COMPLETED ===');
    }

    /**
     * Block checkout when miles-based shipping is selected but billing address is incomplete
     * (avoids order creation while "please enter your address to calculate price" is shown)
     */
    private function validate_miles_based_address() {
        $shipping_method = isset($_POST['shipping_method']) ? $_POST['shipping_method'] : null;
        if ($shipping_method === null) {
            return;
        }
        $chosen = is_array($shipping_method) ? $shipping_method : array($shipping_method);
        $uses_miles_based = false;
        foreach ($chosen as $method) {
            if (is_string($method) && strpos($method, 'miles_based') !== false) {
                $uses_miles_based = true;
                break;
            }
        }
        if (!$uses_miles_based) {
            return;
        }

        $address_1 = $this->get_posted_field_value('billing_address_1');
        $city = $this->get_posted_field_value('billing_city');
        $state = $this->get_posted_field_value('billing_state');
        $postcode = $this->get_posted_field_value('billing_postcode');
        $country = $this->get_posted_field_value('billing_country');
        if (empty($address_1) || empty($city) || empty($state) || empty($postcode) || empty($country)) {
            wc_add_notice(
                __('Please enter your full billing address so we can calculate delivery.', 'rentopian-sync'),
                'error'
            );
        }
    }

    /**
     * Get hidden visual fields from layout
     *
     * @param array $layout Layout configuration
     * @return array Field IDs that are hidden_visual
     */
    private function get_hidden_visual_fields($layout) {
        $hidden_fields = array();

        if (!isset($layout['sections']) || !is_array($layout['sections'])) {
            return $hidden_fields;
        }

        foreach ($layout['sections'] as $section) {
            if (!isset($section['rows']) || !is_array($section['rows'])) {
                continue;
            }

            foreach ($section['rows'] as $row) {
                if (!isset($row['columns']) || !is_array($row['columns'])) {
                    continue;
                }

                foreach ($row['columns'] as $column) {
                    if (isset($column['hidden_visual']) && $column['hidden_visual']) {
                        if (isset($column['field']) && !empty($column['field'])) {
                            $hidden_fields[] = $column['field'];
                        }
                    }
                }
            }
        }

        return $hidden_fields;
    }

    /**
     * Check if a field is active in the layout
     *
     * @param string $field_id Field ID to check
     * @param array  $layout   Layout configuration
     * @return bool
     */
    private function is_field_active_in_layout($field_id, $layout) {
        if (!isset($layout['sections']) || !is_array($layout['sections'])) {
            return true; // Default to active if no layout
        }

        foreach ($layout['sections'] as $section) {
            if (!isset($section['rows']) || !is_array($section['rows'])) {
                continue;
            }

            foreach ($section['rows'] as $row) {
                if (!isset($row['columns']) || !is_array($row['columns'])) {
                    continue;
                }

                foreach ($row['columns'] as $column) {
                    if (isset($column['field']) && $column['field'] === $field_id) {
                        // Found the field - check if it's active
                        return !isset($column['active']) || $column['active'];
                    }
                }
            }
        }

        // Field not found in layout - default to requiring validation
        return true;
    }

    /**
     * Determine whether a field is currently visible, mirroring the frontend
     * depends_on logic, so validation only applies to fields the customer sees.
     *
     * Rules (match checkout-layout-frontend.js):
     *  - No dependency        → visible.
     *  - Parent not on form   → hidden (the control needed to enable it is absent).
     *  - Parent on form       → visible only when the parent is "active"
     *                           (checkbox checked, or value field non-empty).
     *
     * @param string $field_id     Field identifier.
     * @param array  $layout_config The field's column config from the layout.
     * @param array  $layout       Full layout configuration.
     * @return bool
     */
    private function is_field_visible($field_id, $layout_config, $layout) {
        $depends_on = '';
        if (is_array($layout_config) && !empty($layout_config['depends_on'])) {
            $depends_on = $layout_config['depends_on'];
        } elseif (class_exists('Rental_Checkout_Field_Registry')) {
            $field = Rental_Checkout_Field_Registry::get_instance()->get_field($field_id);
            if ($field && !empty($field['depends_on'])) {
                $depends_on = $field['depends_on'];
            }
        }

        if ('' === $depends_on) {
            return true;
        }

        // Parent control not on the form → the dependent cannot be enabled, so it
        // stays hidden (standard WooCommerce behavior) and is not validated.
        if (!$this->is_field_present_in_layout($depends_on, $layout)) {
            return false;
        }

        // Parent present → visible only when active (matches the frontend).
        return $this->is_parent_active($depends_on);
    }

    /**
     * Whether a field exists as an active column in the layout.
     *
     * @param string $field_id Field identifier.
     * @param array  $layout   Full layout configuration.
     * @return bool
     */
    private function is_field_present_in_layout($field_id, $layout) {
        if (!isset($layout['sections']) || !is_array($layout['sections'])) {
            return false;
        }
        foreach ($layout['sections'] as $section) {
            if (empty($section['rows']) || !is_array($section['rows'])) {
                continue;
            }
            foreach ($section['rows'] as $row) {
                if (empty($row['columns']) || !is_array($row['columns'])) {
                    continue;
                }
                foreach ($row['columns'] as $column) {
                    if (isset($column['field']) && $column['field'] === $field_id) {
                        return !isset($column['active']) || $column['active'];
                    }
                }
            }
        }
        return false;
    }

    /**
     * Get the title of the layout section that contains a field.
     *
     * @param string $field_id Field identifier.
     * @param array  $layout   Full layout configuration.
     * @return string Section title, or '' if not found.
     */
    private function get_section_title_for_field($field_id, $layout) {
        if (!isset($layout['sections']) || !is_array($layout['sections'])) {
            return '';
        }
        foreach ($layout['sections'] as $section) {
            if (empty($section['rows']) || !is_array($section['rows'])) {
                continue;
            }
            foreach ($section['rows'] as $row) {
                if (empty($row['columns']) || !is_array($row['columns'])) {
                    continue;
                }
                foreach ($row['columns'] as $column) {
                    if (isset($column['field']) && $column['field'] === $field_id) {
                        return isset($section['title']) ? (string) $section['title'] : '';
                    }
                }
            }
        }
        return '';
    }

    /**
     * Build a hidden marker carrying the exact target field DOM id.
     *
     * Billing and shipping share labels ("First Name", "State"), so the frontend
     * cannot reliably resolve which input an error belongs to from the text alone.
     * This empty, visually-hidden span encodes the field id in a class the frontend
     * reads to anchor/scroll/highlight the correct field. The class survives
     * wc_kses_notice (data-* attributes do not), and the empty span adds no visible
     * text to the notice.
     *
     * @param string $field_id Target field DOM id (e.g. shipping_first_name).
     * @return string
     */
    private function error_target_marker($field_id) {
        return '<span class="rentopian-error-target rentopian-target-' . esc_attr($field_id) . '" aria-hidden="true" style="display:none"></span>';
    }

    /**
     * Whether a dependency parent's posted value makes dependents visible.
     *
     * @param string $parent_id Parent field identifier.
     * @return bool
     */
    private function is_parent_active($parent_id) {
        if (!isset($_POST[$parent_id])) {
            return false;
        }
        $value = $_POST[$parent_id];
        if (is_array($value)) {
            foreach ($value as $v) {
                if (is_string($v) && trim($v) !== '') {
                    return true;
                }
            }
            return false;
        }
        $value = trim((string) $value);
        return $value !== '' && $value !== '0';
    }

    /**
     * Validate referral source field
     */
    private function validate_referral_source() {
        // Check if referral sources feature is enabled
        $setting = get_option('rental_referral_sources_setting', 0);
        
        $this->debug_log("Referral source setting: " . var_export($setting, true));
        
        if (!$this->is_setting_enabled($setting)) {
            $this->debug_log('Referral sources not enabled, skipping');
            return;
        }

        // Check if there are any sources configured
        $sources = get_option('rental_referral_sources', array());
        if (empty($sources)) {
            $this->debug_log('No referral sources configured, skipping');
            return;
        }

        // Get the submitted value
        $value = isset($_POST['rental_referral_source_id']) ? $_POST['rental_referral_source_id'] : null;
        
        $this->debug_log("Referral source value: " . var_export($value, true));

        // Check if field is required (from registry or default to true when enabled)
        $is_required = $this->is_field_required('rental_referral_source', true);
        
        $this->debug_log("Referral source required: " . ($is_required ? 'yes' : 'no'));

        if ($is_required && $this->is_empty_value($value)) {
            $label = $this->get_field_label('rental_referral_source', __('How did you find us?', 'rentopian-sync'));
            wc_add_notice(
                sprintf(__('%s is a required field.', 'rentopian-sync'), '<strong>' . esc_html($label) . '</strong>'),
                'error'
            );
            $this->debug_log("ERROR: Referral source is required but empty!");
        }
    }

    /**
     * Validate event type field
     */
    private function validate_event_type() {
        // Check if event types feature is enabled
        $setting = get_option('rental_event_types_setting', 0);
        
        $this->debug_log("Event type setting: " . var_export($setting, true));
        
        if (!$this->is_setting_enabled($setting)) {
            $this->debug_log('Event types not enabled, skipping');
            return;
        }

        // Check if there are any types configured
        $types = get_option('rental_event_types', array());
        if (empty($types)) {
            $this->debug_log('No event types configured, skipping');
            return;
        }

        // Get the submitted value
        $value = isset($_POST['rental_event_types_id']) ? $_POST['rental_event_types_id'] : null;
        
        $this->debug_log("Event type value: " . var_export($value, true));

        // Check if field is required (from registry, default to false)
        $is_required = $this->is_field_required('rental_event_type', false);
        
        $this->debug_log("Event type required: " . ($is_required ? 'yes' : 'no'));

        if ($is_required && $this->is_empty_value($value)) {
            $label = $this->get_field_label('rental_event_type', __('Event Type', 'rentopian-sync'));
            wc_add_notice(
                sprintf(__('%s is a required field.', 'rentopian-sync'), '<strong>' . esc_html($label) . '</strong>'),
                'error'
            );
            $this->debug_log("ERROR: Event type is required but empty!");
        }
    }

    /**
     * Validate delivery time selections
     */
    private function validate_delivery_time() {
        $selections = get_option('delivery_time_selections', array());
        
        if (empty($selections)) {
            return;
        }

        // Skip for customer pickup mode
        $pickup_delivery = get_option('rental_pickup_delivery');
        if ($pickup_delivery === 'customer_pickup') {
            return;
        }

        $value = isset($_POST['delivery_time_selections_id']) ? $_POST['delivery_time_selections_id'] : null;
        
        $this->debug_log("Delivery time value: " . var_export($value, true));

        // Check if required (from registry, default false)
        $is_required = $this->is_field_required('delivery_time_selections_id', false);

        if ($is_required && $this->is_empty_value($value)) {
            $label = $this->get_field_label('delivery_time_selections_id', __('Delivery Time Window', 'rentopian-sync'));
            wc_add_notice(
                sprintf(__('%s is a required field.', 'rentopian-sync'), '<strong>' . esc_html($label) . '</strong>'),
                'error'
            );
        }
    }

    /**
     * Validate pickup time selections
     */
    private function validate_pickup_time() {
        $selections = get_option('delivery_time_selections', array());
        
        if (empty($selections)) {
            return;
        }

        // Get delivery settings
        $delivery_settings = function_exists('rental_get_delivery_settings') ? rental_get_delivery_settings() : array();
        
        // Skip if charge_only_delivery is enabled
        $charge_only = isset($delivery_settings['charge_only_delivery_for_website']) 
            ? $delivery_settings['charge_only_delivery_for_website'] 
            : false;
        
        if ($charge_only) {
            return;
        }

        $value = isset($_POST['pickup_time_selections_id']) ? $_POST['pickup_time_selections_id'] : null;
        
        $this->debug_log("Pickup time value: " . var_export($value, true));

        // Check if required (from registry, default false)
        $is_required = $this->is_field_required('pickup_time_selections_id', false);

        if ($is_required && $this->is_empty_value($value)) {
            $label = $this->get_field_label('pickup_time_selections_id', __('Pickup Time Window', 'rentopian-sync'));
            wc_add_notice(
                sprintf(__('%s is a required field.', 'rentopian-sync'), '<strong>' . esc_html($label) . '</strong>'),
                'error'
            );
        }
    }

    /**
     * Validate required inspiration photo upload.
     * Photos are stored in session (rental_checkout_photos), not in POST.
     * Runs in modern layout and required photos are always enforced.
     */
    private function validate_photo_upload() {
        if (!get_option('rental_checkout_photo_upload_enabled', 0)) {
            return;
        }

        if (!class_exists('Rental_Checkout_Field_Registry')) {
            return;
        }

        $registry = Rental_Checkout_Field_Registry::get_instance();
        $field   = $registry->get_field('rental_photo_upload');

        // When modern checkout is enabled, only validate if the field is in the active layout.
        if ($this->is_modern_checkout_enabled() && class_exists('Rental_Checkout_Layout_Config')) {
            $config = Rental_Checkout_Layout_Config::get_instance();
            $layout = $config->get_layout();
            if (empty($layout) || !isset($layout['sections'])) {
                return;
            }
            $active_fields = $this->get_active_fields_from_layout($layout);
            if (!isset($active_fields['rental_photo_upload'])) {
                return;
            }
        }

        // Determine effective required: layout override → registry default
        $is_required = $this->is_field_required('rental_photo_upload', false);

        if (!$field || !$is_required) {
            return;
        }

        if (!$this->check_field_conditions($field)) {
            $this->debug_log('Photo upload field conditions not met, skipping validation');
            return;
        }

        $photos = array();
        if (function_exists('WC') && WC()->session) {
            $photos = WC()->session->get('rental_checkout_photos', array());
        }
        if (!is_array($photos)) {
            $photos = array();
        }
        // Also check transient fallback (logged in users may not have WC session yet)
        if (count($photos) === 0 && class_exists('Rental_Checkout_Photo_Upload')) {
            $photo_instance = Rental_Checkout_Photo_Upload::get_instance();
            // Use reflection to call private get_session_photos is not practical,
            // instead check transient directly via raw session ID
            $sid = '';
            if (function_exists('WC') && WC()->session) {
                $sid = WC()->session->get('rental_photo_upload_session_id', '');
            }
            if (!$sid && isset($_COOKIE['rental_photo_session'])) {
                $sid = sanitize_text_field($_COOKIE['rental_photo_session']);
            }
            if ($sid) {
                $transient_photos = get_transient('rental_photos_' . $sid);
                if (!empty($transient_photos) && is_array($transient_photos)) {
                    $photos = $transient_photos;
                }
            }
        }

        if (count($photos) === 0) {
            $label = isset($field['label']) ? $field['label'] : __('Upload Inspiration Photos', 'rentopian-sync');
            wc_add_notice(
                sprintf(__('%s is a required field.', 'rentopian-sync'), '<strong>' . esc_html($label) . '</strong>'),
                'error'
            );
            $this->debug_log('Photo upload is required but no photos in session.');
        }
    }

    /**
     * Validate fields from the layout configuration
     */
    private function validate_layout_fields() {
        if (!class_exists('Rental_Checkout_Layout_Config') || !class_exists('Rental_Checkout_Field_Registry')) {
            $this->debug_log('Layout classes not available, skipping layout field validation');
            return;
        }

        $config = Rental_Checkout_Layout_Config::get_instance();
        $registry = Rental_Checkout_Field_Registry::get_instance();
        
        $layout = $config->get_layout();
        
        if (empty($layout) || !isset($layout['sections'])) {
            $this->debug_log('No layout or sections found');
            return;
        }

        // Get all active fields from layout
        $active_fields = $this->get_active_fields_from_layout($layout);
        
        $this->debug_log('Active fields in layout: ' . implode(', ', array_keys($active_fields)));

        // Determine whether the user opted for a different shipping/pickup address.
        // When not shipping to a different address, skip all shipping_* field validation
        // because those fields are hidden and intentionally empty.
        $ship_to_different = !empty($_POST['ship_to_different_address']) || !empty($_POST['rental_different_pickup_address']);

        // Diagnostic logging (to uploads/wc-logs) so billing/shipping validation can be traced live.
        $this->wc_log(sprintf(
            'Validation start. ship_to_different=%s | active fields (layout order): %s',
            $ship_to_different ? '1' : '0',
            implode(', ', array_keys($active_fields))
        ));
        $this->wc_log('Posted address values: ' . wp_json_encode($this->collect_posted_address_values()));

        foreach ($active_fields as $field_id => $layout_config) {
            // Skip fields hidden by an unmet dependency, mirroring the frontend
            // depends_on visibility. A shown field is always validated; a hidden one
            // never is — regardless of whether it is a billing or shipping field.
            if (!$this->is_field_visible($field_id, $layout_config, $layout)) {
                $this->debug_log("Skipping $field_id — hidden by dependency");
                $this->wc_log('Skipping "' . $field_id . '" — hidden by dependency.');
                continue;
            }

            // Skip fields we validate in dedicated methods (avoid duplicate notices)
            if (in_array($field_id, array(
                'rental_referral_source', 'rental_referral_source_id',
                'rental_event_type', 'rental_event_types_id',
                'delivery_time_selections_id', 'pickup_time_selections_id'
            ), true)) {
                continue;
            }

            // Get full field config from registry
            $field = $registry->get_field($field_id);
            
            if (!$field) {
                continue;
            }

            // Skip components
            if (isset($field['type']) && $field['type'] === 'component') {
                continue;
            }

            // Determine effective required state:
            // 1. Mandatory fields → always required
            // 2. Layout column "required" override → use that
            // 3. Registry default
            $is_required = false;
            if ($config->is_mandatory_field($field_id)) {
                $is_required = true;
            } elseif (is_array($layout_config) && array_key_exists('required', $layout_config)) {
                $is_required = (bool) $layout_config['required'];
            } elseif (isset($field['required']) && $field['required']) {
                $is_required = true;
            }

            if (!$is_required) {
                continue;
            }

            // Check conditions
            if (!$this->check_field_conditions($field)) {
                $this->debug_log("Field $field_id conditions not met, skipping");
                continue;
            }

            // Get POST field name
            $post_name = $this->get_post_field_name($field_id, $field);
            $value = $this->get_posted_value($post_name);

            $this->debug_log("Layout field: $field_id, POST name: $post_name, required: " . ($is_required ? 'yes' : 'no') . ", value: " . var_export($value, true));

            if (strpos($field_id, 'billing_') === 0 || strpos($field_id, 'shipping_') === 0) {
                $this->wc_log(sprintf(
                    'Address field "%s" (post "%s"): required=%s, value=%s',
                    $field_id,
                    $post_name,
                    $is_required ? '1' : '0',
                    $this->is_empty_value($value) ? '[EMPTY]' : '"' . substr((string) (is_array($value) ? wp_json_encode($value) : $value), 0, 60) . '"'
                ));
            }

            if ($this->is_empty_value($value)) {
                $label = $this->get_field_display_label($field_id, $field, $layout_config);
                // Qualify with the section title so billing vs shipping ("Venue Details")
                // errors stay distinct (the notice de-duper keys by label) and the
                // customer knows which field to fix.
                $section_title = $this->get_section_title_for_field($field_id, $layout);
                $display_label = ($section_title !== '') ? ($section_title . ' — ' . $label) : $label;
                wc_add_notice(
                    sprintf(__('%s is a required field.', 'rentopian-sync'), '<strong>' . esc_html($display_label) . '</strong>')
                        . $this->error_target_marker($field_id),
                    'error'
                );
                $this->debug_log("ERROR: $field_id is required but empty!");
                $this->wc_log('VALIDATION ERROR: "' . $field_id . '" is required but empty.', 'warning');
            }
        }
    }

    /**
     * Get active fields from layout
     *
     * @param array $layout Layout configuration
     * @return array Active field IDs with layout config
     */
    private function get_active_fields_from_layout($layout) {
        $active_fields = array();

        foreach ($layout['sections'] as $section) {
            if (!isset($section['rows'])) {
                continue;
            }

            foreach ($section['rows'] as $row) {
                if (!isset($row['columns'])) {
                    continue;
                }

                foreach ($row['columns'] as $column) {
                    // Skip inactive
                    if (isset($column['active']) && !$column['active']) {
                        continue;
                    }

                    // Skip hidden visual fields only if Google API key is present
                    // (because then autocomplete fills them automatically)
                    $is_hidden_visual = isset($column['hidden_visual']) && $column['hidden_visual'];
                    $has_google_api = $this->has_google_maps_api_key();
                    if ($is_hidden_visual && $has_google_api) {
                        continue;
                    }

                    $field_id = isset($column['field']) ? $column['field'] : '';
                    
                    if (!empty($field_id) && $field_id !== 'empty_spacer') {
                        $active_fields[$field_id] = $column;
                    }
                }
            }
        }

        return $active_fields;
    }

    /**
     * Check if a setting is enabled (handles various truthy values)
     *
     * @param mixed $value Setting value
     * @return bool
     */
    private function is_setting_enabled($value) {
        if ($value === true || $value === 1 || $value === '1' || $value === 'yes' || $value === 'on') {
            return true;
        }
        return false;
    }

    /**
     * Check if a field is required
     *
     * @param string $field_id Field ID
     * @param bool   $default  Default value
     * @return bool
     */
    /**
     * Determine effective required state for a field.
     *
     * Priority: mandatory → layout override → registry → default.
     *
     * @param string $field_id Field identifier.
     * @param bool   $default  Fallback if not found anywhere.
     * @return bool
     */
    private function is_field_required($field_id, $default = false) {
        // 1. Layout config override (also checks mandatory list internally)
        if (class_exists('Rental_Checkout_Layout_Config')) {
            $config = Rental_Checkout_Layout_Config::get_instance();
            // Mandatory fields are always required
            if ($config->is_mandatory_field($field_id)) {
                return true;
            }
            $layout_override = $config->get_field_required_from_layout($field_id);
            if (null !== $layout_override) {
                return $layout_override;
            }
        }

        // 2. Registry default
        if (class_exists('Rental_Checkout_Field_Registry')) {
            $registry = Rental_Checkout_Field_Registry::get_instance();
            $field = $registry->get_field($field_id);
            if ($field && isset($field['required'])) {
                return (bool) $field['required'];
            }
        }

        return $default;
    }

    /**
     * Get field label
     *
     * @param string $field_id Field ID
     * @param string $default  Default label
     * @return string
     */
    private function get_field_label($field_id, $default = '') {
        if (!class_exists('Rental_Checkout_Field_Registry')) {
            return $default;
        }

        $registry = Rental_Checkout_Field_Registry::get_instance();
        $field = $registry->get_field($field_id);

        if ($field && isset($field['label'])) {
            return $field['label'];
        }

        return $default;
    }

    /**
     * Resolve the label to show in an error message.
     *
     * Address breakdown fields (state/postcode/city) are renamed per country by
     * WooCommerce's locale (e.g. "County"/"Postcode" for GB). The country is locked
     * to the store base country, so errors must use that locale's label to match
     * what the customer sees on the form, instead of the generic registry label.
     *
     * @param string $field_id      Field identifier.
     * @param array  $field         Registry field configuration.
     * @param array  $layout_config The field's layout column (may carry a label override).
     * @return string
     */
    private function get_field_display_label($field_id, $field, $layout_config = array()) {
        // Explicit layout label override wins — it is exactly what the customer sees.
        if (is_array($layout_config) && !empty($layout_config['label_override'])) {
            $override = trim((string) $layout_config['label_override']);
            if ($override !== '') {
                return $override;
            }
        }

        $default = isset($field['label']) ? $field['label'] : $field_id;

        $key_map = array(
            'billing_state'     => 'state',
            'shipping_state'    => 'state',
            'billing_postcode'  => 'postcode',
            'shipping_postcode' => 'postcode',
            'billing_city'      => 'city',
            'shipping_city'     => 'city',
        );

        if (!isset($key_map[$field_id]) || !function_exists('WC') || !WC()->countries) {
            return $default;
        }

        $key    = $key_map[$field_id];
        $base   = WC()->countries->get_base_country();
        $locale = WC()->countries->get_country_locale();

        if ($base && !empty($locale[$base][$key]['label'])) {
            return $locale[$base][$key]['label'];
        }

        $defaults = WC()->countries->get_default_address_fields();
        if (!empty($defaults[$key]['label'])) {
            return $defaults[$key]['label'];
        }

        return $default;
    }

    /**
     * Check if a value is considered empty
     *
     * @param mixed $value Value to check
     * @return bool True if empty
     */
    private function is_empty_value($value) {
        // Null is empty
        if ($value === null) {
            return true;
        }
        
        // Empty string is empty
        if (is_string($value) && trim($value) === '') {
            return true;
        }

        // Empty array is empty
        if (is_array($value) && count($value) === 0) {
            return true;
        }

        // "0" or 0 is empty (placeholder option)
        if ($value === '0' || $value === 0) {
            return true;
        }

        return false;
    }

    /**
     * Check field conditions
     *
     * @param array $field Field configuration
     * @return bool True if conditions are met
     */
    private function check_field_conditions($field) {
        if (!isset($field['conditional'])) {
            return true;
        }

        $conditional = $field['conditional'];

        // Check setting
        if (isset($conditional['setting'])) {
            $setting_value = get_option($conditional['setting'], false);
            $expected = isset($conditional['value']) ? $conditional['value'] : true;
            
            if (!$this->is_setting_enabled($setting_value) && $this->is_setting_enabled($expected)) {
                return false;
            }
        }

        // Check option (not_empty comparison)
        if (isset($conditional['option'])) {
            $option_value = get_option($conditional['option'], '');
            $compare = isset($conditional['compare']) ? $conditional['compare'] : 'equals';

            if ($compare === 'not_empty' && empty($option_value)) {
                return false;
            }
            if ($compare === 'empty' && !empty($option_value)) {
                return false;
            }
        }

        // Check callback
        if (isset($conditional['callback']) && is_callable($conditional['callback'])) {
            if (!call_user_func($conditional['callback'])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get POST field name
     *
     * @param string $field_id Field ID
     * @param array  $field    Field configuration
     * @return string POST field name
     */
    private function get_post_field_name($field_id, $field) {
        // Explicit form_field_name takes priority
        if (isset($field['form_field_name'])) {
            return $field['form_field_name'];
        }

        // Dynamic fields use array notation
        if (isset($field['source']) && $field['source'] === 'rental_dynamic') {
            $rentopian_id = isset($field['rentopian_id']) ? $field['rentopian_id'] : null;
            $rentopian_slug = isset($field['rentopian_slug']) ? $field['rentopian_slug'] : null;
            
            $raw_id = $rentopian_id ?: ($rentopian_slug ?: str_replace('rental_custom_', '', $field_id));
            return 'rental_custom_fields[' . $raw_id . ']';
        }

        return $field_id;
    }

    /**
     * Get posted value
     *
     * @param string $field_name POST field name
     * @return mixed Value or null
     */
    private function get_posted_value($field_name) {
        // Handle array notation (e.g. rental_custom_fields[123])
        if (preg_match('/^([^\[]+)\[([^\]]+)\]$/', $field_name, $matches)) {
            $array_name = $matches[1];
            $array_key = $matches[2];
            
            if (isset($_POST[$array_name]) && is_array($_POST[$array_name]) && isset($_POST[$array_name][$array_key])) {
                return $_POST[$array_name][$array_key];
            }
            return null;
        }

        if (!isset($_POST[$field_name])) {
            return null;
        }

        $raw = $_POST[$field_name];

        // Defence-in-depth: if duplicate inputs produce an array,
        // use the first non-empty value (our visible field's value).
        if (is_array($raw)) {
            foreach ($raw as $v) {
                if (is_string($v) && trim($v) !== '') {
                    return trim($v);
                }
            }
            return '';
        }

        return $raw;
    }

    /**
     * Check if Google Maps API key is configured
     * 
     * @return bool
     */
    private function has_google_maps_api_key() {
        $api_key = get_option('rental_google_map_key', '');
        return !empty($api_key) && strlen($api_key) > 10;
    }

    /**
     * Debug log helper - uses error_log only (never file_put_contents) to avoid
     * breaking checkout when custom log paths don't exist (e.g. Docker/.cursor).
     *
     * @param string $message Message to log
     */
    private function debug_log($message) {
        if (!$this->debug) {
            return;
        }
        error_log('[Rentopian Checkout Validator] ' . $message);
    }

    /**
     * Write a checkout diagnostic line to the WooCommerce logs (uploads/wc-logs).
     *
     * Used to trace billing/shipping validation on live sites. Can be disabled via
     * the 'rental_checkout_logging_enabled' filter.
     *
     * @param string $message
     * @param string $level
     */
    private function wc_log($message, $level = 'info') {
        if (!apply_filters('rental_checkout_logging_enabled', true)) {
            return;
        }
        if (!class_exists('Project_WP_Logger')) {
            return;
        }
        // Dedicated dated file under uploads/wc-logs, separate from the heal/log pipeline.
        $upload = wp_upload_dir();
        $path   = trailingslashit($upload['basedir']) . 'wc-logs/rentopian-modern-checkout-' . date('Y-m-d') . '.log';
        Project_WP_Logger::write($message, $level, 'rentopian-modern-checkout', $path);
    }

    /**
     * Collect posted billing/shipping address values for diagnostics.
     *
     * Arrays (duplicate name inputs) are surfaced explicitly so field collisions
     * are visible in the log.
     *
     * @return array
     */
    private function collect_posted_address_values() {
        $keys = array(
            'billing_first_name', 'billing_last_name', 'billing_email', 'billing_phone',
            'billing_address_1', 'billing_address_2', 'billing_city', 'billing_state',
            'billing_postcode', 'billing_country',
            'shipping_first_name', 'shipping_last_name', 'shipping_address_1', 'shipping_address_2',
            'shipping_city', 'shipping_state', 'shipping_postcode', 'shipping_country',
            'ship_to_different_address',
        );
        $dump = array();
        foreach ($keys as $k) {
            if (!isset($_POST[$k])) {
                $dump[$k] = '[not set]';
                continue;
            }
            $v = $_POST[$k];
            $dump[$k] = is_array($v) ? ('ARRAY(duplicate):' . wp_json_encode($v)) : substr((string) $v, 0, 60);
        }
        return $dump;
    }

    /**
     * Get required billing field value from POST only (no session/cache).
     * When duplicate keys exist (hidden WC fields + our visible fields), prefer strict:
     * if any value is empty, treat as empty so validation catches cleared required fields.
     *
     * @param string $field_id Field key (e.g. billing_address_1, billing_phone)
     * @return string Trimmed value or empty string
     */
    /**
     * Get billing/shipping field value from POST.
     * Defence-in-depth: if duplicate inputs somehow still produce an array value,
     * use the FIRST NON-EMPTY entry (our visible field) rather than returning empty.
     *
     * @param string $field_id Field key (e.g. billing_first_name)
     * @return string Trimmed value or empty string
     */
    private function get_posted_field_value($field_id) {
        if (!isset($_POST[$field_id])) {
            return '';
        }
        $raw = $_POST[$field_id];
        // If PHP received an array (duplicate name[] inputs), use first non-empty
        if (is_array($raw)) {
            foreach ($raw as $v) {
                $v = is_string($v) ? trim(wc_clean(wp_unslash($v))) : '';
                if ($v !== '') {
                    return $v;
                }
            }
            return '';
        }
        $raw = is_string($raw) ? wp_unslash($raw) : '';
        return is_string($raw) ? trim(wc_clean($raw)) : '';
    }
}

// Initialize immediately when file is loaded
add_action('plugins_loaded', function() {
    if (class_exists('WooCommerce') || defined('WC_VERSION')) {
        Rental_Checkout_Validator::get_instance();
    }
}, 20);

// Also try on init as backup
add_action('init', function() {
    if (class_exists('Rental_Checkout_Validator')) {
        Rental_Checkout_Validator::get_instance();
    }
}, 15);

/**
 * Helper function to get validator instance
 *
 * @return Rental_Checkout_Validator
 */
function rental_checkout_validator() {
    return Rental_Checkout_Validator::get_instance();
}
