<?php
/**
 * Checkout Layout Renderer
 *
 * Main orchestrator for rendering the custom checkout layout.
 * Reads the JSON configuration and outputs the checkout form
 * with proper grid structure, field visibility, and dependencies.
 *
 * IMPORTANT: This renderer maintains full compatibility with:
 * - WooCommerce checkout validation and order processing
 * - Miles-based shipping calculations
 * - Order creation and payment processing
 * - Existing rental-checkout-script.js functionality
 *
 * @package    Rentopian_Sync
 * @subpackage Checkout_Layout
 * @since      1.2.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class Rental_Checkout_Layout_Renderer
 *
 * Renders the checkout form based on the JSON layout configuration.
 */
class Rental_Checkout_Layout_Renderer {

    /**
     * Layout configuration instance
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
     * Field renderer instance
     *
     * @var Rental_Checkout_Field_Renderer
     */
    private $field_renderer;

    /**
     * Whether hooks have been removed
     *
     * @var bool
     */
    private $hooks_removed = false;

    /**
     * Singleton instance
     *
     * @var Rental_Checkout_Layout_Renderer
     */
    private static $instance = null;

    /**
     * Get singleton instance
     *
     * @return Rental_Checkout_Layout_Renderer
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
        $this->config = Rental_Checkout_Layout_Config::get_instance();
        $this->registry = Rental_Checkout_Field_Registry::get_instance();
        
        // Load field renderer
        require_once RENTOPIAN_SYNC_PATH . '/includes/checkout/class-checkout-field-renderer.php';
        $this->field_renderer = new Rental_Checkout_Field_Renderer($this->registry);
    }

    /**
     * Initialize the renderer
     *
     * Sets up hooks to intercept WooCommerce checkout rendering
     * when the modern checkout layout is enabled.
     *
     * @return void
     */
    public function init() {
        // Only initialize if modern checkout is enabled
        if (!$this->config->is_enabled()) {
            return;
        }

        // Add body class for CSS targeting
        add_filter('body_class', array($this, 'add_body_class'));

        // Enqueue frontend assets
        add_action('wp_enqueue_scripts', array($this, 'enqueue_assets'));

        // Hook into checkout to render our layout
        // Priority 5 to run before other plugins
        add_action('woocommerce_checkout_before_customer_details', array($this, 'render_checkout_layout'), 5);

        // Modify WC checkout fields to prevent duplicate rendering
        add_filter('woocommerce_checkout_fields', array($this, 'modify_checkout_fields'), 100);

        // Note: the woocommerce_get_country_locale override (force_required_address_locale)
        // is registered earlier, on 'init', in the loader — WooCommerce caches the locale
        // on first read, so it must be hooked before anything reads it.

        // Inject layout-managed billing fields into WC's posted data so its
        // shipping validation has the address/country it needs (we removed
        // those fields from WC's field definitions above, which means
        // get_posted_data() never reads them from $_POST).
        add_filter('woocommerce_checkout_posted_data', array($this, 'inject_handled_posted_data'), 10);

        // Remove default rental hooks that we'll handle in our layout
        add_action('wp', array($this, 'manage_default_hooks'), 20);

        // Render marketing section in the checkout right column (before order review)
        add_action('woocommerce_checkout_before_order_review', array($this, 'render_marketing_section'), 5);

        // Remove the "(optional)" label suffix from WC form fields on modern checkout.
        // Required fields show an asterisk; non-required fields need no "(optional)" tag.
        // Admins can still explicitly include "optional" in their label text if desired.
        add_filter('woocommerce_form_field', array($this, 'remove_optional_label_suffix'), 10, 4);
    }

    /**
     * Add body class for modern checkout
     *
     * @param array $classes Body classes
     * @return array Modified classes
     */
    public function add_body_class($classes) {
        if (is_checkout() && !is_wc_endpoint_url()) {
            $classes[] = 'rentopian-modern-checkout';
        }
        return $classes;
    }

    /**
     * Enqueue frontend assets
     *
     * @return void
     */
    public function enqueue_assets() {
        if (!is_checkout() || is_wc_endpoint_url()) {
            return;
        }

        // Get plugin URL - RENTOPIAN_SYNC_PATH is directory, need to add file for plugins_url
        $plugin_file = RENTOPIAN_SYNC_PATH . '/rentopian-sync.php';
        
        // CSS
        wp_enqueue_style(
            'rentopian-checkout-layout-frontend',
            plugins_url('assets/css/checkout-layout-frontend.css', $plugin_file),
            array(),
            defined('RENTOPIAN_SYNC_VERSION') ? RENTOPIAN_SYNC_VERSION : '1.3.11'
        );

        // JavaScript
        wp_enqueue_script(
            'rentopian-checkout-layout-frontend',
            plugins_url('assets/js/checkout-layout-frontend.js', $plugin_file),
            array('jquery'),
            defined('RENTOPIAN_SYNC_VERSION') ? RENTOPIAN_SYNC_VERSION : '1.3.11',
            true
        );

        // Get custom loading text if set
        $loading_text = get_option('rental_checkout_loading_text', '');
        if (empty($loading_text)) {
            $loading_text = __('Processing...', 'rentopian-sync');
        }

        // Pass configuration to JavaScript
        $is_debug = get_option('rental_checkout_debug_mode', '0') === '1' || (defined('WP_DEBUG') && WP_DEBUG);
        wp_localize_script('rentopian-checkout-layout-frontend', 'rentopianCheckoutLayoutConfig', array(
            'debug' => $is_debug,
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('rentopian_checkout_layout'),
            'hasGoogleApiKey' => $this->has_google_maps_api_key(),
        ));

        $rentopian_checkout_config = array(
            'debug' => $is_debug,
            'loadingText' => $loading_text,
        );

        // Dynamic field order and hidden-visual keywords from layout (when modern checkout is enabled)
        if ($this->config->is_enabled()) {
            $used_ids = $this->config->get_used_field_ids();
            $priority = array();
            foreach (array_values($used_ids) as $index => $field_id) {
                $priority[ $field_id ] = $index + 1;
            }
            $rentopian_checkout_config['fieldOrderPriority'] = $priority;

            $hidden_ids = $this->config->get_hidden_visual_fields();
            $rentopian_checkout_config['hiddenVisualKeywords'] = $this->get_keywords_for_hidden_visual_fields($hidden_ids);

            // Custom labels from the layout, re-applied client-side after WC's address
            // i18n rewrites state/postcode/country labels.
            $rentopian_checkout_config['labelOverrides'] = $this->get_label_overrides_from_layout($this->config->get_layout());
        }

        wp_localize_script('rentopian-checkout-layout-frontend', 'rentopianCheckoutConfig', $rentopian_checkout_config);
    }

    /**
     * Get error-message keywords for hidden visual field IDs (for filtering displayed errors).
     * Matches field IDs to keywords that typically appear in their validation messages.
     *
     * @param array $field_ids List of field IDs that are hidden_visual in the layout.
     * @return array List of lowercase keywords to filter from the error display.
     */
    private function get_keywords_for_hidden_visual_fields($field_ids) {
        $field_keywords = array(
            'billing_first_name'   => array( 'first name' ),
            'billing_last_name'   => array( 'last name' ),
            'billing_phone'       => array( 'phone', 'telephone' ),
            'billing_email'       => array( 'email', 'e-mail', 'email address' ),
            'billing_address_1'   => array( 'address', 'street', 'delivery address', 'billing address' ),
            'billing_address_2'   => array( 'apartment', 'suite', 'unit', 'address line 2' ),
            'billing_city'        => array( 'city', 'town', 'billing city' ),
            'billing_state'       => array( 'state', 'county', 'province', 'region', 'billing state' ),
            'billing_postcode'    => array( 'postcode', 'zip', 'postal code', 'zip code', 'billing postcode' ),
            'billing_country'     => array( 'country', 'billing country' ),
            // Explicit keywords so the humanizer fallback does not register a bare
            // "address" keyword (which would hide unrelated address-field errors).
            'ship_to_different_address' => array( 'ship to a different address', 'different address' ),
            'shipping_first_name' => array( 'first name' ),
            'shipping_last_name'  => array( 'last name' ),
            'shipping_address_1'  => array( 'address', 'street' ),
            'shipping_address_2'  => array( 'apartment', 'suite', 'unit' ),
            'shipping_city'       => array( 'city', 'town' ),
            'shipping_state'      => array( 'state', 'county', 'province', 'region' ),
            'shipping_postcode'   => array( 'postcode', 'zip', 'postal code', 'zip code' ),
            'shipping_country'    => array( 'country' ),
            'rental_referral_source_id' => array( 'referral', 'find us', 'how did you find us' ),
            'rental_event_types_id'     => array( 'event type' ),
            'delivery_time_selections_id' => array( 'delivery time', 'delivery window' ),
            'pickup_time_selections_id'  => array( 'pickup time', 'pickup window', 'strike time' ),
            'order_comments'     => array( 'order notes', 'notes', 'comments' ),
        );
        $field_keywords = apply_filters('rentopian_checkout_hidden_visual_keywords_map', $field_keywords);

        $keywords = array();
        foreach ($field_ids as $field_id) {
            if (isset($field_keywords[ $field_id ])) {
                $keywords = array_merge($keywords, $field_keywords[ $field_id ]);
            } else {
                // Fallback: humanize field ID (e.g. billing_city -> city, billing_state -> state)
                $parts = preg_split('/[_-]/', $field_id, -1, PREG_SPLIT_NO_EMPTY);
                foreach ($parts as $part) {
                    if (! in_array($part, array( 'billing', 'shipping', 'rental', 'id' ), true)) {
                        $keywords[] = strtolower($part);
                    }
                }
            }
        }
        return array_unique(array_map('strtolower', $keywords));
    }

    /**
     * Collect custom field labels from the layout (field_id => label).
     *
     * @param array $layout Layout configuration.
     * @return array
     */
    private function get_label_overrides_from_layout($layout) {
        $overrides = array();

        if (!isset($layout['sections']) || !is_array($layout['sections'])) {
            return $overrides;
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
                    if (empty($column['field']) || !isset($column['label_override'])) {
                        continue;
                    }
                    $label = trim((string) $column['label_override']);
                    if ($label !== '') {
                        $overrides[ $column['field'] ] = $label;
                    }
                }
            }
        }

        return $overrides;
    }

    /**
     * Manage default rental hooks
     *
     * Removes default rental checkout hooks when our layout is active
     * to prevent duplicate fields.
     *
     * @return void
     */
    public function manage_default_hooks() {
        if (!is_checkout() || is_wc_endpoint_url() || $this->hooks_removed) {
            return;
        }

        // Remove the modern date form hook - we render it in our layout
        remove_action('woocommerce_after_checkout_billing_form', 'rental_render_modern_date_form', 5);

        // Remove rental field hooks - we render these in our layout
        remove_action('woocommerce_after_checkout_billing_form', 'rental_referral_sources_field');
        remove_action('woocommerce_after_checkout_billing_form', 'rental_event_types_field');
        remove_action('woocommerce_after_checkout_billing_form', 'rental_delivery_time_selections_field');
        remove_action('woocommerce_after_checkout_billing_form', 'rental_payment_tips');
        remove_action('woocommerce_after_checkout_billing_form', 'rental_checkout_photo_upload_field');
        
        // Note: We do NOT remove rental_checkout_delivery_options as it may contain
        // pickup address fields that are separate from our layout
        // remove_action('woocommerce_after_checkout_billing_form', 'rental_checkout_delivery_options');

        // Note: We do NOT remove checkout_rental_field as it handles:
        // - Event start time
        // - Damage waiver
        // - Custom fields (rental_init_custom_fields)
        // These will be rendered via woocommerce_after_order_notes as usual
        // unless specifically included in our layout

        $this->hooks_removed = true;
    }

    /**
     * Modify WooCommerce checkout fields
     *
     * Removes billing/shipping fields from WC's default rendering
     * since we handle them in our custom layout.
     *
     * CRITICAL: We only unset during page rendering (not AJAX) to prevent
     * duplicate inputs while still allowing WC to validate during checkout.
     *
     * @param array $fields Checkout fields
     * @return array Modified fields
     */
    public function modify_checkout_fields($fields) {
        $layout = $this->config->get_layout();
        $handled_fields = $this->get_handled_fields($layout);

        // ALWAYS unset fields we handle — on page load (prevents duplicate rendering)
        // and during AJAX (prevents WC validation of its hidden empty duplicates).
        //
        // ROOT CAUSE: WC's updated_checkout replaces DOM fragments creating fresh <input>
        // elements with name attributes. On form submission, PHP receives BOTH our visible
        // field value AND the empty hidden WC field value. PHP takes the LAST duplicate →
        // validation sees empty string → "First Name is required" for filled fields.
        //
        // Our Rental_Checkout_Validator handles ALL layout field validation correctly
        // by reading $_POST directly, so WC does NOT need these field definitions.
        foreach ($handled_fields as $field_id) {
            if (isset($fields['billing'][$field_id])) {
                unset($fields['billing'][$field_id]);
            }
            $billing_key = 'billing_' . $field_id;
            if (isset($fields['billing'][$billing_key])) {
                unset($fields['billing'][$billing_key]);
            }
            if (isset($fields['shipping'][$field_id])) {
                unset($fields['shipping'][$field_id]);
            }
            $shipping_key = 'shipping_' . $field_id;
            if (isset($fields['shipping'][$shipping_key])) {
                unset($fields['shipping'][$shipping_key]);
            }
        }

        // Apply layout-level required overrides to any REMAINING WC fields
        // (ones NOT in our layout that WC still validates)
        $fields = $this->apply_layout_required_overrides($fields, $layout);

        return $fields;
    }

    /**
     * Inject layout-managed billing fields into WC's posted checkout data.
     *
     * Because modify_checkout_fields() removes billing fields from WC's field
     * definitions, WC's get_posted_data() never reads them from $_POST.
     * This causes WC's shipping validation to fail ("Please enter an address
     * to continue.") because billing_country (and other address fields) are
     * absent from $data — so the billing→shipping copy at WC_Checkout line 818
     * sets shipping_country to '' instead of the actual posted value.
     *
     * This filter re-injects the handled billing fields from $_POST into $data
     * so WC's downstream validation and order creation work correctly.
     *
     * @param array $data The posted checkout data assembled by WC.
     * @return array Modified data with our layout's billing fields included.
     */
    public function inject_handled_posted_data($data) {
        $layout = $this->config->get_layout();
        $handled_fields = $this->get_handled_fields($layout);

        foreach ($handled_fields as $field_id) {
            // Only inject fields that are in $_POST but missing from $data
            if (!isset($data[$field_id]) && isset($_POST[$field_id])) {
                $data[$field_id] = wc_clean(wp_unslash($_POST[$field_id]));
            }
        }

        // When NOT shipping to a different address, WC copies billing→shipping
        // at line 818 of class-wc-checkout.php. But that copy runs BEFORE this
        // filter, and it only iterates over shipping fields still in WC's
        // definitions. Since we removed most shipping fields (address_1, city,
        // state, postcode) and billing fields weren't in $data yet, the copy
        // either skipped them or set them to ''. Now that billing fields are
        // injected, re-do the copy so WC's validation has correct shipping data.
        if (empty($data['ship_to_different_address'])) {
            $address_suffixes = array('first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country');
            foreach ($address_suffixes as $suffix) {
                $billing_key  = 'billing_' . $suffix;
                $shipping_key = 'shipping_' . $suffix;
                // Ship-to-different is off → shipping mirrors billing (standard WooCommerce).
                if (isset($data[$billing_key]) && $data[$billing_key] !== '') {
                    $data[$shipping_key] = $data[$billing_key];
                }
            }
        }

        // Diagnostic: trace billing→shipping injection/copy for live debugging.
        $trace = array(
            'ship_to_different' => !empty($data['ship_to_different_address']) ? '1' : '0',
            'handled_fields'    => $handled_fields,
        );
        foreach (array('first_name', 'last_name', 'address_1', 'city', 'state', 'postcode', 'country') as $suffix) {
            $trace['billing_' . $suffix]  = isset($data['billing_' . $suffix]) ? substr((string) $data['billing_' . $suffix], 0, 40) : '[unset]';
            $trace['shipping_' . $suffix] = isset($data['shipping_' . $suffix]) ? substr((string) $data['shipping_' . $suffix], 0, 40) : '[unset]';
        }
        $this->wc_log('inject_handled_posted_data: ' . wp_json_encode($trace));

        return $data;
    }

    /**
     * Force the store base country's address breakdown fields to stay required.
     *
     * WooCommerce's per-country locale can mark state/postcode optional (e.g. GB
     * county), and its client-side i18n then removes the required asterisk. Since
     * the country is locked to the store base country and these fields are required
     * for valid orders, keep them required so the asterisk shows and validation
     * (server + client) stays consistent.
     *
     * @param array $locale WooCommerce country locale array.
     * @return array
     */
    public function force_required_address_locale($locale) {
        // Scope to modern checkout mode. We intentionally do NOT gate on is_checkout()
        // here: WooCommerce caches the locale on first access, so an earlier call
        // (when is_checkout() is still false) would poison the cache and the required
        // asterisk/visibility would be lost on the checkout page.
        if (!$this->config->is_enabled()) {
            return $locale;
        }
        if (!function_exists('WC') || !WC()->countries) {
            return $locale;
        }
        $base = WC()->countries->get_base_country();
        if (!$base) {
            return $locale;
        }
        if (!isset($locale[$base]) || !is_array($locale[$base])) {
            $locale[$base] = array();
        }
        foreach (array('state', 'postcode', 'city') as $field) {
            if (!isset($locale[$base][$field]) || !is_array($locale[$base][$field])) {
                $locale[$base][$field] = array();
            }
            // Keep the field visible and required: some country locales mark these
            // hidden/optional (e.g. GB county), which removes the asterisk and can
            // hide the field via the address i18n script. The country is locked to
            // the store base country, so these must always show and validate.
            $locale[$base][$field]['required'] = true;
            $locale[$base][$field]['hidden']   = false;
        }
        return $locale;
    }

    /**
     * Apply layout-level "required" overrides to WooCommerce checkout fields.
     *
     * This ensures WC's own validation respects the admin's required/not-required
     * choices from the JSON layout builder. Mandatory fields cannot be overridden.
     *
     * @param array $fields WC checkout fields.
     * @param array $layout Layout configuration.
     * @return array Modified fields.
     */
    private function apply_layout_required_overrides($fields, $layout) {
        if (empty($layout['sections']) || !is_array($layout['sections'])) {
            return $fields;
        }

        // Build a map of field_id → required override from layout columns
        $overrides = array();
        foreach ($layout['sections'] as $section) {
            if (empty($section['rows'])) continue;
            foreach ($section['rows'] as $row) {
                if (empty($row['columns'])) continue;
                foreach ($row['columns'] as $column) {
                    if (empty($column['field']) || !array_key_exists('required', $column)) continue;
                    $overrides[$column['field']] = (bool) $column['required'];
                }
            }
        }

        if (empty($overrides)) {
            return $fields;
        }

        // Apply overrides to WC field definitions
        foreach (array('billing', 'shipping', 'order') as $group) {
            if (empty($fields[$group])) continue;
            foreach ($fields[$group] as $key => &$def) {
                if (!isset($overrides[$key])) continue;
                // Mandatory fields cannot be set to not-required
                if ($this->config->is_mandatory_field($key)) continue;
                $def['required'] = $overrides[$key];
            }
            unset($def);
        }

        return $fields;
    }

    /**
     * Get list of fields handled by our layout
     *
     * @param array $layout The layout configuration
     * @return array Field IDs that we handle
     */
    private function get_handled_fields($layout) {
        $fields = array();

        if (!isset($layout['sections']) || !is_array($layout['sections'])) {
            return $fields;
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
                    if (isset($column['field']) && !empty($column['field'])) {
                        // Check if field is active
                        $is_active = !isset($column['active']) || $column['active'];
                        
                        if ($is_active) {
                            $fields[] = $column['field'];
                        }
                    }
                }
            }
        }

        return $fields;
    }

    /**
     * Render required hidden address fields for Google Autocomplete
     *
     * These fields (billing_city, billing_state, billing_postcode, billing_country)
     * are essential infrastructure for:
     * - Google Places Autocomplete to populate address components
     * - WooCommerce shipping calculations (miles-based shipping)
     * - Order address data completeness
     *
     * If these fields are not in the user's layout configuration, we render them
     * as hidden inputs so they can receive values from Google Autocomplete.
     *
     * @param array $layout The layout configuration
     * @return void
     */
    private function render_required_hidden_address_fields($layout) {
        // Fields that MUST exist for Google Autocomplete and WooCommerce to work.
        // These are essential infrastructure - we ALWAYS render them as hidden inputs
        // because even if they're in the layout config, they might not be rendered
        // due to hidden_visual or other conditions.
        // Default country comes from WooCommerce store settings, not a hardcoded value.
        $base_country = (function_exists('WC') && WC()->countries) ? WC()->countries->get_base_country() : '';

        $required_hidden_fields = array(
            'billing_city'     => array('label' => __('City', 'rentopian-sync'), 'autocomplete' => 'address-level2'),
            'billing_state'    => array('label' => __('State', 'rentopian-sync'), 'autocomplete' => 'address-level1'),
            'billing_postcode' => array('label' => __('ZIP Code', 'rentopian-sync'), 'autocomplete' => 'postal-code'),
            'billing_country'  => array('label' => __('Country', 'rentopian-sync'), 'autocomplete' => 'country', 'default' => $base_country),
        );

        // Fields already active in the layout are rendered by it. Adding a second
        // input with the same name here would create a duplicate that wins on submit
        // (empty when not autocompleted) and breaks required-field validation.
        $active_field_ids = $this->get_handled_fields($layout);

        // Get checkout instance for default values
        $checkout = function_exists('WC') && WC()->checkout ? WC()->checkout() : null;

        echo '<div class="rentopian-hidden-address-fields" style="display:none !important; visibility:hidden; position:absolute; left:-9999px;" aria-hidden="true">';

        foreach ($required_hidden_fields as $field_id => $field_config) {
            // Only render the hidden infrastructure input for fields NOT placed in the
            // layout (so Google autocomplete still has a target and address data stays
            // complete). Placed fields already exist in the DOM under the same name.
            if (in_array($field_id, $active_field_ids, true)) {
                continue;
            }

            // Get default value from checkout or session
            $value = '';
            if ($checkout) {
                $value = $checkout->get_value($field_id);
            }
            if (empty($value) && isset($field_config['default'])) {
                $value = $field_config['default'];
            }

            // Render as text input (not type="hidden") so change events work properly
            // and WooCommerce can process them. CSS hides them visually.
            // Use unique ID suffix to avoid conflicts with any visible fields
            printf(
                '<input type="text" name="%s" id="%s_hidden" value="%s" autocomplete="%s" data-label="%s" class="rentopian-autocomplete-field" />',
                esc_attr($field_id),
                esc_attr($field_id),
                esc_attr($value),
                esc_attr($field_config['autocomplete']),
                esc_attr($field_config['label'])
            );
        }

        echo '</div><!-- .rentopian-hidden-address-fields -->';
    }

    /**
     * Write a checkout diagnostic line to the WooCommerce logs (uploads/wc-logs).
     *
     * Can be disabled via the 'rental_checkout_logging_enabled' filter.
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
     * Render the checkout layout
     *
     * Main method that outputs the custom checkout form structure.
     *
     * @return void
     */
    public function render_checkout_layout() {
        $layout = $this->config->get_layout();

        // Diagnostic: confirm which base country is applied and that it is enforced read-only.
        $base_country_log = (function_exists('WC') && WC()->countries) ? WC()->countries->get_base_country() : '';
        $this->wc_log('Checkout render: WC base country = "' . $base_country_log . '"; billing country is filled from store settings and locked read-only on the form.');

        // Validate layout
        if (!$this->is_valid_layout($layout)) {
            $this->render_fallback_notice();
            return;
        }

        // Start output
        $container_class = isset($layout['container_class']) ? $layout['container_class'] : 'rentopian-checkout-form';
        
        echo '<div class="' . esc_attr($container_class) . '" id="rentopian-checkout-form">';

        // Render each section
        if (isset($layout['sections']) && is_array($layout['sections'])) {
            foreach ($layout['sections'] as $section) {
                $this->render_section($section);
            }
        }

        // CRITICAL: Render required hidden address fields that Google Autocomplete needs
        // These fields may not be in the user's layout but are essential for:
        // - Google Places Autocomplete to populate city/state/zip/country
        // - WooCommerce shipping calculations (miles-based)
        // - Order address data completeness
        $this->render_required_hidden_address_fields($layout);

        echo '</div><!-- .rentopian-checkout-form -->';

        /**
         * Fires after the checkout form closes, before the hide-fields CSS.
         *
         * Use this hook to render modals or supplementary markup that
         * depends on checkout fields being in the DOM.
         *
         * @since 2.16.0
         * @param array $layout The full layout configuration array.
         */
        do_action( 'rentopian_after_checkout_form', $layout );

        // Hide default WC billing/shipping field wrappers
        $this->output_hide_default_fields_css();

        // Hide/show review order items based on admin settings
        $this->output_review_display_css();
    }

    /**
     * Render a section
     *
     * @param array $section Section configuration
     * @return void
     */
    private function render_section($section) {
        // First, check if section has any visible fields
        $visible_field_count = $this->count_visible_fields_in_section($section);
        
        // Skip completely empty sections (no active fields at all)
        if ($visible_field_count === 0) {
            return;
        }

        $section_id = isset($section['id']) ? $section['id'] : 'section-' . uniqid();
        $section_class = isset($section['class']) ? $section['class'] : '';
        $section_title = isset($section['title']) ? $section['title'] : '';
        $section_desc = isset($section['description']) ? $section['description'] : '';
        
        // Check if title/description should be shown (default: true if not empty)
        $show_title = isset($section['show_title']) ? (bool) $section['show_title'] : true;
        $show_description = isset($section['show_description']) ? (bool) $section['show_description'] : true;

        // Build section classes
        $classes = array('rentopian-section');
        if (!empty($section_class)) {
            $classes[] = $section_class;
        }

        echo '<div class="' . esc_attr(implode(' ', $classes)) . '" id="' . esc_attr($section_id) . '">';

        // Section title - only show if not empty, show_title is true, and section has visible fields
        if (!empty($section_title) && $show_title) {
            echo '<h3 class="rentopian-section-title">' . esc_html($section_title) . '</h3>';
        }

        // Section description - only show if not empty and show_description is true
        if (!empty($section_desc) && $show_description) {
            echo '<p class="rentopian-section-description">' . esc_html($section_desc) . '</p>';
        }

        // Render rows
        if (isset($section['rows']) && is_array($section['rows'])) {
            foreach ($section['rows'] as $row) {
                $this->render_row($row);
            }
        }

        echo '</div><!-- .rentopian-section -->';
    }

    /**
     * Count visible fields in a section
     *
     * Counts all active, non-hidden fields in the section.
     * Used to determine if section should be rendered at all.
     *
     * @param array $section Section configuration
     * @return int Number of visible fields
     */
    private function count_visible_fields_in_section($section) {
        $count = 0;

        if (!isset($section['rows']) || !is_array($section['rows'])) {
            return 0;
        }

        foreach ($section['rows'] as $row) {
            if (!isset($row['columns']) || !is_array($row['columns'])) {
                continue;
            }

            foreach ($row['columns'] as $column) {
                // Check if column should be rendered (active, has field, not hidden_visual)
                if ($this->should_render_column($column)) {
                    $is_hidden_visual_configured = isset($column['hidden_visual']) && $column['hidden_visual'];
                    if (!$is_hidden_visual_configured) {
                        $count++;
                    }
                }
            }
        }

        return $count;
    }

    /**
     * Render a row
     *
     * @param array $row Row configuration
     * @return void
     */
    private function render_row($row) {
        $row_id = isset($row['id']) ? $row['id'] : 'row-' . uniqid();
        $row_class = isset($row['class']) ? $row['class'] : '';

        // Check if row has any active columns
        $has_active_columns = false;
        if (isset($row['columns']) && is_array($row['columns'])) {
            foreach ($row['columns'] as $column) {
                if ($this->should_render_column($column)) {
                    $has_active_columns = true;
                    break;
                }
            }
        }

        // Skip empty rows
        if (!$has_active_columns) {
            return;
        }

        // Build row classes
        $classes = array('rentopian-row');
        if (!empty($row_class)) {
            $classes[] = $row_class;
        }

        echo '<div class="' . esc_attr(implode(' ', $classes)) . '" id="' . esc_attr($row_id) . '">';

        // Render columns
        if (isset($row['columns']) && is_array($row['columns'])) {
            foreach ($row['columns'] as $column) {
                $this->render_column($column);
            }
        }

        echo '</div><!-- .rentopian-row -->';
    }

    /**
     * Render a column
     *
     * @param array $column Column configuration
     * @return void
     */
    private function render_column($column) {
        // Check if column should be rendered
        if (!$this->should_render_column($column)) {
            return;
        }

        $field_id = isset($column['field']) ? $column['field'] : '';
        $width = isset($column['width']) ? (int) $column['width'] : 12;
        $column_class = isset($column['class']) ? $column['class'] : '';
        $depends_on = isset($column['depends_on']) ? $column['depends_on'] : '';
        
        // hidden_visual: field is rendered in DOM but visually hidden
        // Always applied when configured — the field stays in the form for submission
        $is_hidden_visual_configured = isset($column['hidden_visual']) && $column['hidden_visual'];
        $hidden_visual = $is_hidden_visual_configured;

        // Build column classes
        $classes = array('rentopian-col', 'rentopian-col-' . $width);
        
        if (!empty($column_class)) {
            $classes[] = $column_class;
        }

        if ($hidden_visual) {
            $classes[] = 'rentopian-hidden-visual-wrapper';
        }

        // Calculate inline width as fallback for themes that override our CSS
        $width_percent = ($width / 12) * 100;
        $inline_style = '';
        
        if (!$hidden_visual) {
            $inline_style = sprintf(
                'flex: 0 0 %1$.4f%%; max-width: %1$.4f%%; width: %1$.4f%%;',
                $width_percent
            );
        }

        // Build data attributes
        $data_attrs = array();
        
        if (!empty($field_id)) {
            $data_attrs[] = 'data-field-id="' . esc_attr($field_id) . '"';
        }

        if (!empty($depends_on)) {
            $data_attrs[] = 'data-depends-on="' . esc_attr($depends_on) . '"';
        }

        $data_attr_string = !empty($data_attrs) ? ' ' . implode(' ', $data_attrs) : '';
        $style_attr = !empty($inline_style) ? ' style="' . esc_attr($inline_style) . '"' : '';

        echo '<div class="' . esc_attr(implode(' ', $classes)) . '"' . $data_attr_string . $style_attr . '>';

        // Render the field
        if (!empty($field_id)) {
            // Build layout overrides from column config
            $overrides = array();
            if (array_key_exists('required', $column)) {
                $overrides['required'] = $column['required'];
            }
            if (array_key_exists('checked', $column)) {
                $overrides['checked'] = $column['checked'];
            }
            if (array_key_exists('label_override', $column) && trim((string) $column['label_override']) !== '') {
                $overrides['label_override'] = $column['label_override'];
            }
            $this->field_renderer->render($field_id, $overrides);
        }

        echo '</div><!-- .rentopian-col -->';
    }

    /**
     * Check if a column should be rendered
     *
     * @param array $column Column configuration
     * @return bool Whether to render the column
     */
    private function should_render_column($column) {
        // Check active flag
        if (isset($column['active']) && !$column['active']) {
            return false;
        }

        // Must have a field ID
        if (!isset($column['field']) || empty($column['field'])) {
            return false;
        }

        // Check if field exists in registry (for validation)
        $field = $this->registry->get_field($column['field']);
        
        // Allow rendering even if not in registry (for flexibility)
        // The field renderer will handle unknown fields gracefully

        return true;
    }

    /**
     * Render marketing section on checkout page
     *
     * Outputs the marketing banner and content in the checkout right column
     * (before the order review table). Only shows when marketing is enabled
    /**
     * Remove the "(optional)" label suffix from WooCommerce form fields.
     *
     * WooCommerce appends '&nbsp;<span class="optional">...' to non-required fields.
     * On the modern checkout, required fields have an asterisk and non-required fields
     * are implicitly optional — the label is noise.
     *
     * Hooked to: woocommerce_form_field (priority 10)
     *
     * @param string $field The HTML output.
     * @param string $key   The field key.
     * @param array  $args  Field arguments.
     * @param mixed  $value Field value.
     * @return string Modified HTML.
     */
    public function remove_optional_label_suffix( $field, $key, $args, $value ) {
        // Only strip on the checkout page with our modern layout
        if ( ! is_checkout() ) {
            return $field;
        }
        // Remove <span class="optional">...</span> and the preceding &nbsp;
        $field = preg_replace( '/&nbsp;<span class="optional">\(.*?\)<\/span>/', '', $field );
        return $field;
    }

    /**
     * Render marketing section on checkout page
     *
     * Outputs the marketing banner and content in the checkout right column
     * (before the order review table). Only shows when marketing is enabled
     * via admin settings.
     *
     * Hooked to: woocommerce_checkout_before_order_review (priority 5)
     *
     * @return void
     */
    public function render_marketing_section() {
        $enabled = get_option('rental_checkout_marketing_enabled', '0');
        if ($enabled !== '1') {
            return;
        }

        $banner_id = get_option('rental_checkout_marketing_banner_id', '');
        $content   = get_option('rental_checkout_marketing_content', '');

        // Nothing to show
        if (empty($banner_id) && empty($content)) {
            return;
        }

        echo '<div class="rentopian-marketing-section" id="rentopian-marketing-section">';

        // Banner image
        if (!empty($banner_id)) {
            $banner_url = wp_get_attachment_url($banner_id);
            if ($banner_url) {
                $alt = get_post_meta($banner_id, '_wp_attachment_image_alt', true);
                echo '<div class="rentopian-marketing-banner">';
                echo '<img src="' . esc_url($banner_url) . '" alt="' . esc_attr($alt ?: '') . '" class="rentopian-marketing-banner-img" />';
                echo '</div>';
            }
        }

        // Content
        if (!empty($content)) {
            echo '<div class="rentopian-marketing-content">';
            echo wp_kses_post(wpautop($content));
            echo '</div>';
        }

        echo '</div><!-- .rentopian-marketing-section -->';
    }

    /**
     * Output CSS to hide default WooCommerce fields
     *
     * We render fields in our layout, so we need to hide the default
     * WC field wrappers while keeping the fields in DOM for validation.
     *
     * @return void
     */
    private function output_hide_default_fields_css() {
        ?>
        <style type="text/css">
            /* Hide default WC billing fields completely - we render them in our layout */
            body.rentopian-modern-checkout .woocommerce-billing-fields {
                display: none !important;
            }
            
            /* Hide default WC shipping fields section completely */
            body.rentopian-modern-checkout .woocommerce-shipping-fields {
                display: none !important;
            }
            
            /* Hide the woocommerce-additional-fields that contains order notes and other default fields */
            /* We render these in our layout */
            body.rentopian-modern-checkout .woocommerce-additional-fields {
                display: none !important;
            }
            
            /* Keep our custom checkout form visible */
            body.rentopian-modern-checkout #rentopian-checkout-form,
            body.rentopian-modern-checkout .rentopian-checkout-form {
                display: block !important;
            }
            
            /* Hide order attribution inputs container if rendered by WC outside our layout */
            /* body.rentopian-modern-checkout #customer_details > wc-order-attribution-inputs { */
                /* Keep these - they're needed for order tracking */
            /* } */
            
            /* Hide default rental hooks output that we handle */
            body.rentopian-modern-checkout #customer_details > .rntp-form-block,
            body.rentopian-modern-checkout #customer_details > #rental_date_form_modern,
            body.rentopian-modern-checkout #customer_details > #rental_address_container {
                display: none !important;
            }
            
            /* Ensure error notifications show above our form */
            body.rentopian-modern-checkout .rntp-notification {
                margin-bottom: 20px;
            }
        </style>
        <?php
    }

    /**
     * Output CSS to hide review order items based on admin display settings.
     *
     * Uses class selectors from WooCommerce's standard checkout review order
     * template so it works regardless of theme. Applied on checkout page AND
     * thank-you / my-account order-received pages.
     */
    private function output_review_display_css() {
        if (!class_exists('Rental_Checkout_Layout_Manager')) {
            return;
        }
        $settings = Rental_Checkout_Layout_Manager::get_review_display_settings();

        // Map setting keys → CSS selectors (checkout + thank-you + order-view)
        $map = array(
            'rental_dates' => array(
                '.rental-dates-summary-wrapper',
                '#rntp-rental-dates-summary-wrapper',
            ),
            'cart_items' => array(
                '.wc-checkout-review-order-table tbody',
                '.woocommerce-checkout-review-order-table tbody',
                '.checkout-order-review-heading',
                '#order_review_heading',
            ),
            'subtotal' => array(
                '.cart-subtotal',
            ),
            'shipping' => array(
                '.woocommerce-shipping-totals',
                '.cart-shipping',
            ),
            'fees' => array(
                '.cart-totals-fee',
            ),
            'tax' => array(
                '.auto-tax-key',
                '.tax-rate',
                '.cart-totals-tax',
            ),
            'coupon' => array(
                '.cart-discount',
            ),
            'order_total' => array(
                '.order-total',
            ),
            'cart_actions' => array(
                '.cart-footer-actions-row',
            ),
            'payment_info' => array(
                '.checkout-payment-info-heading',
                '#payment .wc_payment_methods',
                '#payment .woocommerce-SavedPaymentMethods',
                '#payment .payment_methods',
                '#payment .wc-payment-form',
                '#payment .about_paypal',
                '#payment .woocommerce-terms-and-conditions-wrapper',
                '.woocommerce-terms-and-conditions-wrapper',
            ),
        );

        $rules = array();
        foreach ($map as $key => $selectors) {
            if (!empty($settings[$key])) {
                continue; // visible — skip
            }
            foreach ($selectors as $sel) {
                $rules[] = 'body.rentopian-modern-checkout ' . $sel;
                $rules[] = '.woocommerce-order-received ' . $sel;
                $rules[] = '.woocommerce-view-order ' . $sel;
            }
        }

        if (empty($rules)) {
            return;
        }
        ?>
        <style type="text/css">
            /* Review Order Display Settings (admin-controlled) */
            <?php echo implode(",\n            ", array_map('esc_html', $rules)); ?> {
                display: none !important;
            }
            /* CRITICAL: Always keep the place order / submit button visible regardless of payment_info setting */
            body.rentopian-modern-checkout #payment .place-order,
            body.rentopian-modern-checkout #payment #place_order,
            body.rentopian-modern-checkout .place-order,
            body.rentopian-modern-checkout #place_order {
                display: block !important;
                visibility: visible !important;
            }
        </style>
        <?php
    }

    /**
     * Validate layout structure
     *
     * @param array $layout The layout configuration
     * @return bool Whether layout is valid
     */
    private function is_valid_layout($layout) {
        if (!is_array($layout)) {
            return false;
        }

        if (!isset($layout['sections']) || !is_array($layout['sections'])) {
            return false;
        }

        // Must have at least one section
        if (empty($layout['sections'])) {
            return false;
        }

        return true;
    }

    /**
     * Render fallback notice for invalid layout
     *
     * @return void
     */
    private function render_fallback_notice() {
        if (current_user_can('manage_options')) {
            echo '<div class="woocommerce-info">';
            echo esc_html__('Checkout layout configuration is invalid. Please check your settings.', 'rentopian-sync');
            echo '</div>';
        }

        // Allow default checkout to render
        $this->restore_default_hooks();
    }

    /**
     * Restore default rental hooks
     *
     * Called when our layout fails and we need to fall back to default checkout.
     *
     * @return void
     */
    private function restore_default_hooks() {
        // Re-add the hooks we removed
        add_action('woocommerce_after_checkout_billing_form', 'rental_render_modern_date_form', 5);
        add_action('woocommerce_after_checkout_billing_form', 'rental_referral_sources_field');
        add_action('woocommerce_after_checkout_billing_form', 'rental_event_types_field');
        add_action('woocommerce_after_checkout_billing_form', 'rental_delivery_time_selections_field');
        add_action('woocommerce_after_checkout_billing_form', 'rental_payment_tips');

        $this->hooks_removed = false;
    }

    /**
     * Get the layout configuration
     *
     * @return array The layout configuration
     */
    public function get_layout() {
        return $this->config->get_layout();
    }

    /**
     * Check if modern checkout is enabled
     *
     * @return bool
     */
    public function is_enabled() {
        return $this->config->is_enabled();
    }

    /**
     * Check if Google Maps API key is configured
     * 
     * This is required for address autocomplete to work.
     * If no key is present, hidden_visual fields should be shown normally.
     *
     * @return bool
     */
    public function has_google_maps_api_key() {
        $api_key = get_option('rental_google_map_key', '');
        return !empty($api_key) && strlen($api_key) > 10;
    }
}

/**
 * Initialize the checkout layout renderer
 *
 * @return Rental_Checkout_Layout_Renderer
 */
function rental_checkout_layout_renderer() {
    return Rental_Checkout_Layout_Renderer::get_instance();
}
