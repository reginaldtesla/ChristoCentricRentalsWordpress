<?php
/**
 * Checkout Field Renderer
 *
 * Renders individual checkout fields based on their type and configuration.
 * Uses WooCommerce's woocommerce_form_field() for compatibility with
 * payment gateways, validation, and order processing.
 *
 * @package    Rentopian_Sync
 * @subpackage Checkout_Layout
 * @since      1.2.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class Rental_Checkout_Field_Renderer
 *
 * Handles the rendering of individual checkout fields based on their
 * configuration in the field registry.
 */
class Rental_Checkout_Field_Renderer {

    /**
     * Field registry instance
     *
     * @var Rental_Checkout_Field_Registry
     */
    private $registry;

    /**
     * WooCommerce checkout instance
     *
     * @var WC_Checkout|null
     */
    private $checkout = null;

    /**
     * Constructor
     *
     * @param Rental_Checkout_Field_Registry $registry Field registry instance
     */
    public function __construct(Rental_Checkout_Field_Registry $registry) {
        $this->registry = $registry;
    }

    /**
     * Get WooCommerce checkout instance (lazy loaded)
     *
     * @return WC_Checkout|null
     */
    private function get_checkout() {
        if (null === $this->checkout && function_exists('WC') && WC()->checkout) {
            $this->checkout = WC()->checkout();
        }
        return $this->checkout;
    }

    /**
     * Render a field by its ID
     *
     * @param string $field_id The field ID to render
     * @return void
     */
    /**
     * Render a checkout field by ID.
     *
     * @param string $field_id   The field identifier.
     * @param array  $overrides  Optional layout-level overrides (e.g. ['required' => false]).
     *                           These take priority over registry defaults but NOT over
     *                           system mandatory fields.
     */
    public function render($field_id, $overrides = array()) {
        // Handle special pseudo-fields
        if ($field_id === 'empty_spacer') {
            // Output an empty spacer div for layout purposes
            echo '<div class="rentopian-empty-spacer" aria-hidden="true"></div>';
            return;
        }

        $field = $this->registry->get_field($field_id);

        if (!$field) {
            $this->render_unknown_field($field_id);
            return;
        }

        // Apply layout-level overrides (e.g. required from JSON layout column config)
        if (!empty($overrides) && is_array($overrides)) {
            if (array_key_exists('required', $overrides)) {
                // Mandatory fields cannot be overridden to not-required
                $is_mandatory = class_exists('Rental_Checkout_Layout_Config')
                    && Rental_Checkout_Layout_Config::get_instance()->is_mandatory_field($field_id);
                if ($is_mandatory) {
                    $field['required'] = true;
                } else {
                    $field['required'] = (bool) $overrides['required'];
                }
            }
            // Default-checked state for checkbox fields (from the layout column).
            if (array_key_exists('checked', $overrides)) {
                $field['default_checked'] = (bool) $overrides['checked'];
            }
            // Custom label from the layout column (applies to all field sources).
            if (array_key_exists('label_override', $overrides)) {
                $label_override = trim((string) $overrides['label_override']);
                if ($label_override !== '') {
                    $field['label'] = $label_override;
                }
            }
        }

        // Check conditional visibility (settings-based)
        if (!$this->check_field_conditions($field)) {
            // Debug output when conditions fail
            if (defined('WP_DEBUG') && WP_DEBUG && current_user_can('manage_options')) {
                $conditional_info = isset($field['conditional']) ? json_encode($field['conditional']) : 'none';
                echo '<!-- DEBUG field "' . esc_html($field_id) . '" hidden due to conditional: ' . esc_html($conditional_info) . ' -->';
            }
            return;
        }

        // Route to appropriate renderer based on field source/type
        switch ($field['source']) {
            case 'woocommerce':
                $this->render_woocommerce_field($field_id, $field);
                break;

            case 'rental_custom':
                $this->render_rental_field($field_id, $field);
                break;

            case 'rental_dynamic':
                $this->render_dynamic_field($field_id, $field);
                break;

            default:
                $this->render_generic_field($field_id, $field);
                break;
        }
    }

    /**
     * Render a WooCommerce billing/shipping field
     *
     * @param string $field_id The field ID
     * @param array  $field    Field configuration
     */
    private function render_woocommerce_field($field_id, $field) {
        $args = $this->build_wc_field_args($field_id, $field);
        $value = $this->get_field_value($field_id, $field);
        $value = $this->maybe_default_checked($field, $args, $value);

        woocommerce_form_field($field_id, $args, $value);
    }

    /**
     * Default a checkbox field to checked when the layout enables it and there is
     * no existing value. Applies to any field rendered as a checkbox.
     *
     * @param array $field Field configuration (with default_checked applied).
     * @param array $args  WooCommerce field args (resolved type).
     * @param mixed $value Current value.
     * @return mixed
     */
    private function maybe_default_checked($field, $args, $value) {
        $type = isset($args['type']) ? $args['type'] : (isset($field['type']) ? $field['type'] : 'text');
        if ('checkbox' !== $type) {
            return $value;
        }
        $empty = ($value === '' || $value === null || $value === false || $value === '0' || $value === 0 || (is_array($value) && empty($value)));
        if ($empty && !empty($field['default_checked'])) {
            return 1;
        }
        return $value;
    }

    /**
     * Render a rental-specific field
     *
     * @param string $field_id The field ID
     * @param array  $field    Field configuration
     */
    private function render_rental_field($field_id, $field) {
        // Handle component fields (date picker, payment tips, etc.)
        if (isset($field['type']) && $field['type'] === 'component') {
            $this->render_component($field_id, $field);
            return;
        }

        // Handle standard rental fields (selects, checkboxes, etc.)
        $args = $this->build_rental_field_args($field_id, $field);
        $value = $this->get_field_value($field_id, $field);
        $value = $this->maybe_default_checked($field, $args, $value);

        // Use form_field_name if specified (e.g., rental_referral_source_id)
        $form_name = isset($field['form_field_name']) ? $field['form_field_name'] : $field_id;

        // Debug output for development
        if (defined('WP_DEBUG') && WP_DEBUG && current_user_can('manage_options')) {
            // Check if options are empty for select fields
            if (isset($args['type']) && $args['type'] === 'select') {
                $options_count = isset($args['options']) ? count($args['options']) : 0;
                if ($options_count <= 1) {
                    echo '<!-- DEBUG rental_field ' . esc_html($field_id) . ': select has only ' . $options_count . ' options (may be empty) -->';
                }
            }
        }

        woocommerce_form_field($form_name, $args, $value);
    }

    /**
     * Render a dynamic field from Rentopian API
     *
     * @param string $field_id The field ID
     * @param array  $field    Field configuration
     */
    private function render_dynamic_field($field_id, $field) {
        // Get the rentopian ID (numeric) from the field registry
        $rentopian_id = isset($field['rentopian_id']) ? $field['rentopian_id'] : null;
        $rentopian_slug = isset($field['rentopian_slug']) ? $field['rentopian_slug'] : null;
        $type_code = isset($field['rentopian_type_code']) ? (int) $field['rentopian_type_code'] : 1;
        
        // Build field arguments using the type code from registry
        $args = $this->build_dynamic_field_args_from_registry($field_id, $field, $type_code);
        $value = $this->get_field_value($field_id, $field);
        $value = $this->maybe_default_checked($field, $args, $value);

        // Build the proper name for POST handling
        // Dynamic fields use rental_custom_fields[{id}] format - use numeric ID if available
        $raw_id = $rentopian_id ? $rentopian_id : ($rentopian_slug ? $rentopian_slug : str_replace('rental_custom_', '', $field_id));
        $name = 'rental_custom_fields[' . $raw_id . ']';

        // For multi-select, add [] to name
        if ($type_code == 10) {
            $name .= '[]';
        }

        // Override the field ID to use proper name
        $args['id'] = $field_id;
        
        echo '<div class="rentopian-dynamic-field-wrapper">';
        woocommerce_form_field($name, $args, $value);
        echo '</div>';
    }

    /**
     * Render a component field (special fields that include PHP files)
     *
     * @param string $field_id The field ID
     * @param array  $field    Field configuration
     */
    private function render_component($field_id, $field) {
        $component = isset($field['component']) ? $field['component'] : '';

        switch ($component) {
            case 'rental_date_form_modern':
                $this->render_date_form_modern();
                break;

            case 'rental_payment_tips':
                $this->render_payment_tips();
                break;

            case 'rental_different_pickup_address':
                $this->render_different_pickup_address();
                break;

            case 'rental_damage_waiver':
                $this->render_damage_waiver();
                break;

            case 'rental_event_time':
                $this->render_event_start_time();
                break;

            case 'rental_special_terms':
                $this->render_special_terms();
                break;
            
            case 'rental_photo_upload':
                $this->render_photo_upload($field);
                break;
            default:
                // Try to render as a custom component
                do_action('rentopian_render_checkout_component', $component, $field_id, $field);
                break;
        }
    }

    /**
     * Render the modern date form component
     */
    private function render_date_form_modern() {
        $file = RENTOPIAN_SYNC_PATH . '/components/rental_date_from_modern.php';
        
        if (file_exists($file)) {
            include $file;
        }
    }

    /**
     * Render the payment tips component
     */
    private function render_payment_tips() {
        if (function_exists('rental_payment_tips')) {
            rental_payment_tips();
        }
    }

    /**
     * Render different pickup address component
     */
    private function render_different_pickup_address() {
        // Check conditions first
        $delivery_settings = function_exists('rental_get_delivery_settings') ? rental_get_delivery_settings() : array();
        $use_rentopian_shipping = get_option('rental_do_not_use_rentopian_shipping');
        $enable_different_address = isset($delivery_settings['enable_different_pickup_delivery_address_for_website']) 
            ? $delivery_settings['enable_different_pickup_delivery_address_for_website'] 
            : false;

        if ($use_rentopian_shipping || !$enable_different_address) {
            return;
        }

        // Render the pickup address container
        $pickup_cookie = isset($_COOKIE['rental_pick_up']) && $_COOKIE['rental_pick_up'];
        ?>
        <div id="rental_address_container" <?php if ($pickup_cookie): ?>style="display: none"<?php endif; ?>>
            <label class="woocommerce-form__label woocommerce-form__label-for-checkbox checkbox">
                <input id="rental_different_pick_up_address"
                       class="woocommerce-form__input woocommerce-form__input-checkbox input-checkbox"
                       type="checkbox" name="rental_different_pick_up_address" value="1">
                <span><?php esc_html_e('Different delivery and pick up locations', 'rentopian-sync'); ?></span>
            </label>
            <div id="rental_pick_up_address_fields" class="woocommerce-address-fields" style="display: none">
                <?php
                if (function_exists('WC') && WC()->countries) {
                    $address_fields = WC()->countries->get_address_fields('', 'rental_pick_up_');
                    unset($address_fields['rental_pick_up_first_name']);
                    unset($address_fields['rental_pick_up_last_name']);
                    unset($address_fields['rental_pick_up_company']);
                    
                    foreach ($address_fields as $key => $args) {
                        woocommerce_form_field($key, $args);
                    }
                }
                ?>
            </div>
        </div>
        <?php
    }

    /**
     * Render damage waiver component
     * 
     * Matches the original checkout_rental_field() damage waiver logic exactly.
     */
    private function render_damage_waiver() {
        // Check conditions - same as original checkout_rental_field()
        if (!function_exists('rental_is_damage_waiver_enabled') || !rental_is_damage_waiver_enabled()) {
            return;
        }

        // Build options
        $options = array();
        $options['buy'] = __('Buy Damage Waiver', 'rentopian-sync');
        $options['exempt'] = __('Opt out of Damage Waiver', 'rentopian-sync');
        
        // Determine default value based on cookie or option
        if (isset($_COOKIE['rental_exempt_waiver'])) {
            $default = $_COOKIE['rental_exempt_waiver'] ? 'exempt' : 'buy';
        } else {
            $default = get_option('rental_buy_damage_waiver_by_default') ? 'buy' : 'exempt';
        }
        
        // Render the damage waiver block
        echo '<div id="damage_waiver" class="rntp-form-block">';
        echo '<h3>' . esc_html__('Damage Waiver', 'rentopian-sync') . '</h3>';
        
        woocommerce_form_field('damage_waiver', array(
            'type'        => 'radio',
            'class'       => array('radio-toolbar'),
            'required'    => true,
            'label_class' => array('rntp-label'),
            'input_class' => array('rntp-radio'),
            'options'     => $options,
        ), $default);
        
        echo '</div>';
    }

    /**
     * Render event start time component
     * 
     * Matches the original checkout_rental_field() event time logic.
     */
    private function render_event_start_time() {
        // Check if event start time is enabled
        if (!get_option('rental_event_start_time')) {
            // Debug: Output HTML comment when option is disabled
            if (defined('WP_DEBUG') && WP_DEBUG) {
                echo '<!-- rental_event_time: rental_event_start_time option is disabled -->';
            }
            return;
        }
        
        $opt_default_start_time = get_option('rental_default_start_time', '09:00 AM');
        $decrypted_rental_start_date = '';
        
        // Get start date from cookie
        if (isset($_COOKIE['rental_start_date']) && $_COOKIE['rental_start_date']) {
            if (function_exists('decrypt_data')) {
                $decrypted_rental_start_date = decrypt_data($_COOKIE['rental_start_date'], get_option('rental_encryption_key'));
            } else {
                $decrypted_rental_start_date = $_COOKIE['rental_start_date'];
            }
        }
        
        // Determine start time from decrypted date or use default
        $start_time = $decrypted_rental_start_date 
            ? date('g:i A', strtotime($decrypted_rental_start_date)) 
            : $opt_default_start_time;
        
        // Get saved value from cookie
        $saved_value = isset($_COOKIE['rental_event_time']) ? $_COOKIE['rental_event_time'] : $start_time;
        
        // Render the field
        woocommerce_form_field('rental_event_time', array(
            'type'              => 'text',
            'class'             => array('rental-start-time', 'form-row-wide'),
            'label'             => __('Event start time', 'rentopian-sync'),
            'required'          => false,
            'custom_attributes' => array('min' => $start_time),
        ), $saved_value);
    }

    /**
     * Render special terms component
     * 
     * Displays special terms content if configured in settings.
     * Matches the original rental_add_special_terms_on_checkout_page() function.
     */
    private function render_special_terms() {
        $special_terms = rental_get_special_terms_html();

        if (!$special_terms) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                echo '<!-- rental_special_terms: No special terms content set -->';
            }
            return;
        }

        echo '<div class="rental-special-terms">';
        echo '<h3>' . esc_html__('Special Terms', 'rentopian-sync') . '</h3>';
        echo $special_terms;
        echo '</div>';
    }

    /**
     * Render photo upload component
     *
     * Includes the component template file and enqueues required assets.
     * Only renders if the feature is enabled via admin settings.
     * Passes field config so the template can show/hide the required asterisk per registry.
     *
     * @since 2.14.0
     * @param array $field Field configuration from registry (optional when called outside layout).
     */
    private function render_photo_upload($field = array()) {
        // Double-check setting (also checked in component file)
        if (!get_option('rental_checkout_photo_upload_enabled', 0)) {
            return;
        }

        $plugin_file = RENTOPIAN_SYNC_PATH . '/rentopian-sync.php';
        $version = defined('RENTOPIAN_SYNC_VERSION') ? RENTOPIAN_SYNC_VERSION : '2.14.0';

        // Enqueue CSS
        wp_enqueue_style(
            'rental-checkout-photo-upload',
            plugins_url('assets/css/rental-checkout-photo-upload.css', $plugin_file),
            array(),
            $version
        );

        // Enqueue JS
        wp_enqueue_script(
            'rental-checkout-photo-upload',
            plugins_url('assets/js/rental-checkout-photo-upload.js', $plugin_file),
            array('jquery'),
            $version,
            true
        );

        // Pass config to JS
        $max_files   = apply_filters('rental_photo_upload_max_files', 5);
        $max_size_mb = apply_filters('rental_photo_upload_max_size_mb', 2);

        wp_localize_script('rental-checkout-photo-upload', 'rentalPhotoUploadConfig', array(
            'ajaxUrl'           => admin_url('admin-ajax.php'),
            'nonce'             => wp_create_nonce('rental_photo_upload_nonce'),
            'maxFiles'          => $max_files,
            'maxSizeMB'         => $max_size_mb,
            'allowedTypes'      => array('image/jpeg', 'image/jpg', 'image/png'),
            'allowedExtensions' => array('jpg', 'jpeg', 'png'),
            'i18n'              => array(
                'dropzoneText'   => __('Drag & drop images here or click to browse', 'rentopian-sync'),
                'uploading'      => __('Uploading photos, please wait...', 'rentopian-sync'),
                'uploadSuccess'  => __('Photo uploaded successfully.', 'rentopian-sync'),
                'uploadFailed'   => __('Upload failed. Please try again.', 'rentopian-sync'),
                'maxFilesReached'=> sprintf(__('Maximum of %d photos allowed.', 'rentopian-sync'), $max_files),
                'fileTooLarge'   => sprintf(__('File is too large. Maximum size is %d MB.', 'rentopian-sync'), $max_size_mb),
                'invalidType'    => __('Invalid file type. Accepted: JPG, JPEG, PNG.', 'rentopian-sync'),
                'photoCount'     => __( '{count} of {max} photos', 'rentopian-sync'),
                'clearAll'       => __('Clear all', 'rentopian-sync'),
                'removePhoto'    => __('Remove photo', 'rentopian-sync'),
                'networkError'   => __('Network error. Please check your connection.', 'rentopian-sync'),
            ),
        ));

        // Required flag from field registry (default false — admin enables via JSON layout "required": true)
        $rental_photo_upload_required = isset($field['required']) ? (bool) $field['required'] : false;

        // Include the component template (uses $rental_photo_upload_required for required asterisk)
        $file = RENTOPIAN_SYNC_PATH . '/components/rental_photo_upload.php';
        if (file_exists($file)) {
            include $file;
        }
    }

    /**
     * Render a generic/unknown field
     *
     * @param string $field_id The field ID
     * @param array  $field    Field configuration (optional)
     */
    private function render_generic_field($field_id, $field = array()) {
        $args = array(
            'type'     => isset($field['type']) ? $field['type'] : 'text',
            'label'    => isset($field['label']) ? $field['label'] : $field_id,
            'required' => isset($field['required']) ? $field['required'] : false,
            'class'    => array('form-row-wide'),
        );

        woocommerce_form_field($field_id, $args, '');
    }

    /**
     * Render placeholder for unknown field
     *
     * @param string $field_id The unknown field ID
     */
    private function render_unknown_field($field_id) {
        if (current_user_can('manage_options') && WP_DEBUG) {
            echo '<div class="rentopian-unknown-field" style="padding:10px;background:#fff3cd;border:1px solid #ffc107;margin:5px 0;">';
            echo '<small>Unknown field: <code>' . esc_html($field_id) . '</code></small>';
            echo '</div>';
        }
    }

    /**
     * Build WooCommerce field arguments
     *
     * @param string $field_id The field ID
     * @param array  $field    Field configuration
     * @return array Field arguments for woocommerce_form_field()
     */
    private function build_wc_field_args($field_id, $field) {
        $type = isset($field['type']) ? $field['type'] : 'text';
        
        // Map our types to WC types
        $type_map = array(
            'state' => 'state',
            'country' => 'country',
        );

        if (isset($type_map[$type])) {
            $type = $type_map[$type];
        }

        $args = array(
            'type'        => $type,
            'label'       => isset($field['label']) ? $field['label'] : '',
            'required'    => isset($field['required']) ? (bool) $field['required'] : false,
            'class'       => array('form-row-wide'),
            'placeholder' => isset($field['placeholder']) ? $field['placeholder'] : '',
        );

        // Add autocomplete attribute for address fields
        $autocomplete_map = array(
            'billing_first_name'  => 'given-name',
            'billing_last_name'   => 'family-name',
            'billing_company'     => 'organization',
            'billing_email'       => 'email',
            'billing_phone'       => 'tel',
            'billing_address_1'   => 'address-line1',
            'billing_address_2'   => 'address-line2',
            'billing_city'        => 'address-level2',
            'billing_state'       => 'address-level1',
            'billing_postcode'    => 'postal-code',
            'billing_country'     => 'country',
            'shipping_first_name' => 'shipping given-name',
            'shipping_last_name'  => 'shipping family-name',
            'shipping_address_1'  => 'shipping address-line1',
            'shipping_city'       => 'shipping address-level2',
            'shipping_state'      => 'shipping address-level1',
            'shipping_postcode'   => 'shipping postal-code',
        );

        if (isset($autocomplete_map[$field_id])) {
            $args['autocomplete'] = $autocomplete_map[$field_id];
        }

        // Handle state field specially
        if ($type === 'state') {
            $country_key = strpos($field_id, 'shipping') !== false ? 'shipping_country' : 'billing_country';
            $args['country_field'] = $country_key;
            $checkout = $this->get_checkout();
            $args['country'] = $checkout ? $checkout->get_value($country_key) : '';
        }

        return $args;
    }

    /**
     * Build rental field arguments
     *
     * @param string $field_id The field ID
     * @param array  $field    Field configuration
     * @return array Field arguments for woocommerce_form_field()
     */
    private function build_rental_field_args($field_id, $field) {
        $type = isset($field['type']) ? $field['type'] : 'text';

        $args = array(
            'type'     => $type,
            'label'    => isset($field['label']) ? $field['label'] : '',
            'required' => isset($field['required']) ? (bool) $field['required'] : false,
            'class'    => array('form-row-wide'),
        );

        // Handle select fields with options callback
        if ($type === 'select' && isset($field['options_callback'])) {
            $options = $this->get_options_from_callback($field['options_callback']);
            $args['options'] = $options;
        }

        return $args;
    }

    /**
     * Build dynamic field arguments based on Rentopian API field type
     *
     * @param string $field_id   The field ID
     * @param array  $field      Field configuration from registry
     * @param array  $field_data Raw field data from API
     * @return array Field arguments
     */
    private function build_dynamic_field_args($field_id, $field, $field_data) {
        $type_code = isset($field_data['type']) ? (int) $field_data['type'] : 1;
        return $this->build_dynamic_field_args_from_registry($field_id, $field, $type_code);
    }

    /**
     * Build dynamic field arguments using type code from registry
     *
     * @param string $field_id  The field ID
     * @param array  $field     Field configuration from registry
     * @param int    $type_code Rentopian numeric type code
     * @return array Field arguments for woocommerce_form_field()
     */
    private function build_dynamic_field_args_from_registry($field_id, $field, $type_code) {
        $args = array(
            'label'      => isset($field['label']) ? $field['label'] : '',
            'required'   => isset($field['required']) ? (bool) $field['required'] : false,
            'class'      => array('form-row-wide', 'custom-field-' . sanitize_key($field_id)),
            'maxlength'  => 255,
        );

        // Map Rentopian type codes to WC field types
        switch ($type_code) {
            case 1: // Text
                $args['type'] = 'text';
                break;

            case 2: // Number
                $args['type'] = 'number';
                $args['maxlength'] = 11;
                $args['custom_attributes'] = array('min' => 0, 'step' => 1);
                break;

            case 3: // Checkbox
                $args['type'] = 'checkbox';
                unset($args['maxlength']); // Checkboxes don't need maxlength
                break;

            case 4: // DateTime
                $args['type'] = 'datetime-local';
                break;

            case 5: // Date
                $args['type'] = 'date';
                break;

            case 6: // Time
                $args['type'] = 'time';
                break;

            case 7: // Email
                $args['type'] = 'email';
                break;

            case 8: // Website/URL
                $args['type'] = 'url';
                break;

            case 9: // Select
                $args['type'] = 'select';
                // Get options from registered field
                $args['options'] = isset($field['options']) ? $field['options'] : array('' => __('Choose', 'rentopian-sync'));
                break;

            case 10: // Multi-select
                $args['type'] = 'select';
                // Get options from registered field (no empty first option for multi-select)
                $options = isset($field['options']) ? $field['options'] : array();
                // Remove empty first option if it exists
                if (isset($options[''])) {
                    unset($options['']);
                }
                $args['options'] = $options;
                $args['custom_attributes'] = array('multiple' => 'multiple');
                break;

            case 11: // Currency
                $args['type'] = 'number';
                $args['maxlength'] = 11;
                $args['custom_attributes'] = array('min' => 0, 'step' => 0.01);
                break;

            case 12: // Phone
                $args['type'] = 'tel';
                break;

            case 13: // Textarea
                $args['type'] = 'textarea';
                break;

            default:
                $args['type'] = 'text';
                break;
        }

        return $args;
    }

    /**
     * Build select options from field data
     *
     * @param array $field_data     Raw field data
     * @param bool  $include_empty  Whether to include empty first option
     * @return array Options array
     */
    private function build_select_options($field_data, $include_empty = true) {
        $options = array();

        if ($include_empty) {
            $options[0] = __('Choose', 'rentopian-sync');
        }

        if (isset($field_data['options'])) {
            $raw_options = is_string($field_data['options']) 
                ? json_decode($field_data['options'], true) 
                : $field_data['options'];

            if (is_array($raw_options)) {
                foreach ($raw_options as $option) {
                    $options[$option] = $option;
                }
            }
        }

        return $options;
    }

    /**
     * Get options from a callback function
     *
     * @param string $callback The callback function name
     * @return array Options array
     */
    private function get_options_from_callback($callback) {
        $options = array();

        switch ($callback) {
            case 'rental_get_event_type_options':
                $options = $this->get_event_type_options();
                break;

            case 'rental_get_referral_source_options':
                $options = $this->get_referral_source_options();
                break;

            case 'rental_get_delivery_time_options':
                $options = $this->get_delivery_time_options();
                break;

            case 'rental_get_pickup_time_options':
                $options = $this->get_pickup_time_options();
                break;

            default:
                if (function_exists($callback)) {
                    $options = call_user_func($callback);
                }
                break;
        }

        return $options;
    }

    /**
     * Get event type options
     *
     * @return array Options array
     */
    private function get_event_type_options() {
        $options = array(0 => __('Please, select an Event Type.', 'rentopian-sync'));
        
        $event_types = get_option('rental_event_types', array());
        
        if (!empty($event_types)) {
            foreach ($event_types as $type) {
                if (isset($type->id) && isset($type->title)) {
                    $options[$type->id] = $type->title;
                }
            }
        }

        return $options;
    }

    /**
     * Get referral source options
     *
     * @return array Options array
     */
    private function get_referral_source_options() {
        $options = array(0 => __('Please, select a referral source.', 'rentopian-sync'));
        
        $sources = get_option('rental_referral_sources', array());
        
        // Debug output
        if (defined('WP_DEBUG') && WP_DEBUG && current_user_can('manage_options')) {
            $sources_count = is_array($sources) ? count($sources) : 0;
            echo '<!-- DEBUG referral_sources: found ' . $sources_count . ' sources, type: ' . gettype($sources) . ' -->';
        }
        
        if (!empty($sources) && is_array($sources)) {
            foreach ($sources as $source) {
                // Handle both object and array formats
                if (is_object($source)) {
                    if (isset($source->id) && isset($source->title)) {
                        $options[$source->id] = $source->title;
                    }
                } elseif (is_array($source)) {
                    if (isset($source['id']) && isset($source['title'])) {
                        $options[$source['id']] = $source['title'];
                    }
                }
            }
        }

        return $options;
    }

    /**
     * Get delivery time selection options
     *
     * @return array Options array
     */
    private function get_delivery_time_options() {
        $options = array(0 => __('Please, select a delivery time range.', 'rentopian-sync'));
        
        $selections = get_option('delivery_time_selections', array());
        
        if (!empty($selections)) {
            $opt_default_start_time = get_option('rental_default_start_time', '09:00 AM');
            $rental_start_date = isset($_COOKIE['rental_start_date']) && $_COOKIE['rental_start_date'] 
                ? $_COOKIE['rental_start_date'] 
                : '';

            $start_date_day_name = '';
            if ($rental_start_date && function_exists('parseWithDefaultTime')) {
                $start_date = parseWithDefaultTime($rental_start_date, $opt_default_start_time);
                $start_date_day_name = $start_date->format('l');
            }

            foreach ($selections as $option) {
                // Filter by week day if specified
                if (isset($option->week_day) && $option->week_day != 0) {
                    if (function_exists('getDayNameByNumber')) {
                        $week_day_name = getDayNameByNumber($option->week_day);
                        if ($week_day_name != $start_date_day_name) {
                            continue;
                        }
                    }
                }

                $label = isset($option->title) && $option->title 
                    ? $option->title . '  ' . $option->start_time . '-' . $option->end_time
                    : $option->start_time . '-' . $option->end_time;

                $options[$option->id] = $label;
            }
        }

        return $options;
    }

    /**
     * Get pickup time selection options
     *
     * @return array Options array
     */
    private function get_pickup_time_options() {
        $options = array(0 => __('Please, select a pickup time range.', 'rentopian-sync'));
        
        $selections = get_option('delivery_time_selections', array());
        
        if (!empty($selections)) {
            $opt_default_end_time = get_option('rental_default_end_time', '05:00 PM');
            $rental_end_date = isset($_COOKIE['rental_end_date']) && $_COOKIE['rental_end_date'] 
                ? $_COOKIE['rental_end_date'] 
                : '';

            $end_date_day_name = '';
            if ($rental_end_date && function_exists('parseWithDefaultTime')) {
                $end_date = parseWithDefaultTime($rental_end_date, $opt_default_end_time);
                $end_date_day_name = $end_date->format('l');
            }

            foreach ($selections as $option) {
                // Filter by week day if specified
                if (isset($option->week_day) && $option->week_day != 0) {
                    if (function_exists('getDayNameByNumber')) {
                        $week_day_name = getDayNameByNumber($option->week_day);
                        if ($week_day_name != $end_date_day_name) {
                            continue;
                        }
                    }
                }

                $label = isset($option->title) && $option->title 
                    ? $option->title . '  ' . $option->start_time . '-' . $option->end_time
                    : $option->start_time . '-' . $option->end_time;

                $options[$option->id] = $label;
            }
        }

        return $options;
    }

    /**
     * Get the current value for a field
     *
     * @param string $field_id The field ID
     * @param array  $field    Field configuration
     * @return mixed The field value
     */
    private function get_field_value($field_id, $field) {
        // Check for posted value first
        if (isset($_POST[$field_id])) {
            return wc_clean($_POST[$field_id]);
        }

        // Check WooCommerce checkout value
        $checkout = $this->get_checkout();
        if ($checkout) {
            $value = $checkout->get_value($field_id);
            if ($value !== null && $value !== '') {
                return $value;
            }
        }

        // Check for cookie value (some rental fields use cookies)
        $cookie_map = array(
            'delivery_time_selections_id' => 'delivery_time_selections_id',
            'pickup_time_selections_id' => 'pickup_time_selections_id',
        );

        if (isset($cookie_map[$field_id]) && isset($_COOKIE[$cookie_map[$field_id]])) {
            return sanitize_text_field($_COOKIE[$cookie_map[$field_id]]);
        }

        // Return default value if set
        if (isset($field['default'])) {
            return $field['default'];
        }

        return '';
    }

    /**
     * Check if field should be displayed based on conditional settings
     *
     * @param array $field Field configuration
     * @return bool Whether field should be displayed
     */
    private function check_field_conditions($field) {
        if (!isset($field['conditional'])) {
            return true;
        }

        $conditional = $field['conditional'];
        $debug = defined('WP_DEBUG') && WP_DEBUG && current_user_can('manage_options');

        // Check WordPress option
        if (isset($conditional['option'])) {
            $option_value = get_option($conditional['option'], '');
            $compare = isset($conditional['compare']) ? $conditional['compare'] : 'equals';

            switch ($compare) {
                case 'not_empty':
                    if (empty($option_value)) {
                        return false;
                    }
                    break;

                case 'empty':
                    if (!empty($option_value)) {
                        return false;
                    }
                    break;

                case 'equals':
                    $expected = isset($conditional['value']) ? $conditional['value'] : true;
                    if ($option_value != $expected) {
                        return false;
                    }
                    break;
            }
        }

        // Check setting - more flexible boolean check
        if (isset($conditional['setting'])) {
            $setting_name = $conditional['setting'];
            $setting_value = get_option($setting_name, false);
            $expected = isset($conditional['value']) ? $conditional['value'] : true;

            if ($debug) {
                echo '<!-- DEBUG check_field_conditions: setting "' . esc_html($setting_name) . '" = "' . esc_html(var_export($setting_value, true)) . '", expected "' . esc_html(var_export($expected, true)) . '" -->';
            }

            // Normalize both values for comparison
            // Handle string "1", "0", true, false, 1, 0
            $setting_bool = filter_var($setting_value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            $expected_bool = filter_var($expected, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            
            // If we're comparing booleans
            if ($setting_bool !== null && $expected_bool !== null) {
                if ($setting_bool !== $expected_bool) {
                    return false;
                }
            } else {
                // Fallback to loose comparison
                if ($setting_value != $expected) {
                    return false;
                }
            }
        }

        // Check custom callback
        if (isset($conditional['callback']) && is_callable($conditional['callback'])) {
            if (!call_user_func($conditional['callback'])) {
                return false;
            }
        }

        // Check additional conditions
        if (isset($conditional['also']) && is_array($conditional['also'])) {
            return $this->check_field_conditions(array('conditional' => $conditional['also']));
        }

        return true;
    }
}
