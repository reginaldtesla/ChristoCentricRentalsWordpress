<?php
/**
 * Checkout Field Registry
 *
 * Registers and manages all available checkout fields.
 * Fields include WooCommerce standard fields and custom rental fields.
 *
 * @package    Rentopian_Sync
 * @subpackage Rentopian_Sync/includes/checkout
 * @since      2.13.0
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Class Rental_Checkout_Field_Registry
 *
 * Central registry for all checkout fields.
 * This class:
 * - Registers WooCommerce billing/shipping fields
 * - Registers custom rental fields (referral sources, event types, etc.)
 * - Provides field metadata (label, type, required, etc.)
 * - Allows external registration of additional fields via hooks
 */
class Rental_Checkout_Field_Registry {

    /**
     * Singleton instance
     *
     * @var Rental_Checkout_Field_Registry|null
     */
    private static $instance = null;

    /**
     * Registered fields storage
     * Format: field_id => field_config
     *
     * @var array
     */
    private $fields = array();

    /**
     * Field groups for organization
     * Format: group_id => array('label' => 'Group Label', 'fields' => array(...field_ids))
     *
     * @var array
     */
    private $field_groups = array();

    /**
     * Flag to track if fields have been registered
     *
     * @var bool
     */
    private $initialized = false;

    /**
     * Get singleton instance
     *
     * @return Rental_Checkout_Field_Registry
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Private constructor for singleton pattern
     */
    private function __construct() {
        // Initialization happens lazily on first access
    }

    /**
     * Initialize and register all fields
     *
     * Called lazily when fields are first accessed.
     * Registers WooCommerce fields and custom rental fields.
     */
    private function init() {
        if ($this->initialized) {
            return;
        }

        // Register WooCommerce billing fields
        $this->register_woocommerce_fields();

        // Register custom rental fields
        $this->register_rental_fields();

        // Allow external registration
        do_action('rental_checkout_register_fields', $this);

        $this->initialized = true;
    }

    /**
     * Register standard WooCommerce checkout fields
     *
     * These are the billing fields that come from WooCommerce.
     * We use woocommerce_form_field() to render them.
     * Labels can be customized via textual labels settings.
     */
    private function register_woocommerce_fields() {
        // Get textual labels from settings
        $billing_details_label = get_option('rental_billing_details_text', '');
        $street_address_label = get_option('rental_street_address_label_text', '');
        $ship_to_diff_label = get_option('rental_ship_to_dif_adrs_text', '');
        $shipping_label = get_option('rental_shipping_text', '');

        // Define billing fields group
        // REQUIRED FIELD ARCHITECTURE:
        // - System-mandatory fields: required:true ALWAYS, cannot be overridden by JSON layout
        // - All other fields: required:false by default, can be made required via JSON "required":true
        // Mandatory fields: billing_first_name, billing_last_name, billing_email,
        //                   billing_address_1, billing_country, billing_city
        $billing_fields = array(
            'billing_first_name' => array(
                'label'    => __('First Name', 'rentopian-sync'),
                'type'     => 'text',
                'required' => true,  // MANDATORY - cannot be overridden
                'priority' => 10,
            ),
            'billing_last_name' => array(
                'label'    => __('Last Name', 'rentopian-sync'),
                'type'     => 'text',
                'required' => true,  // MANDATORY - cannot be overridden
                'priority' => 20,
            ),
            'billing_company' => array(
                'label'    => __('Company Name', 'rentopian-sync'),
                'type'     => 'text',
                'required' => false, // Optional - configurable via JSON layout
                'priority' => 25,
            ),
            'billing_email' => array(
                'label'    => __('Email Address', 'rentopian-sync'),
                'type'     => 'email',
                'required' => true,  // MANDATORY - cannot be overridden
                'priority' => 30,
            ),
            'billing_phone' => array(
                'label'    => __('Phone', 'rentopian-sync'),
                'type'     => 'tel',
                'required' => false, // Optional - configurable via JSON layout
                'priority' => 40,
            ),
            'billing_address_1' => array(
                'label'       => !empty($street_address_label) ? $street_address_label : __('Street Address', 'rentopian-sync'),
                'type'        => 'text',
                'required'    => true,  // MANDATORY - cannot be overridden
                'priority'    => 50,
                'placeholder' => __('House number and street name', 'rentopian-sync'),
            ),
            'billing_address_2' => array(
                'label'       => __('Address Line 2', 'rentopian-sync'),
                'type'        => 'text',
                'required'    => false, // Optional - configurable via JSON layout
                'priority'    => 55,
                'placeholder' => __('Apartment, suite, unit, etc.', 'rentopian-sync'),
            ),
            'billing_city' => array(
                'label'    => __('City', 'rentopian-sync'),
                'type'     => 'text',
                'required' => true,  // MANDATORY - cannot be overridden
                'priority' => 60,
            ),
            'billing_state' => array(
                'label'    => __('State / Province', 'rentopian-sync'),
                'type'     => 'state',
                'required' => false, // Optional - configurable via JSON layout
                'priority' => 70,
            ),
            'billing_postcode' => array(
                'label'    => __('ZIP / Postal Code', 'rentopian-sync'),
                'type'     => 'text',
                'required' => false, // Optional - configurable via JSON layout
                'priority' => 80,
            ),
            'billing_country' => array(
                'label'    => __('Country', 'rentopian-sync'),
                'type'     => 'country',
                'required' => true,  // MANDATORY - cannot be overridden
                'priority' => 90,
            ),
        );

        // Register billing fields group with custom label if set
        $billing_group_label = !empty($billing_details_label) ? $billing_details_label : __('Billing Details', 'rentopian-sync');
        $this->register_field_group(
            'billing',
            $billing_group_label,
            'woocommerce'
        );

        // Register each billing field
        foreach ($billing_fields as $field_id => $field_config) {
            $this->register_field(
                $field_id,
                array_merge($field_config, array(
                    'source' => 'woocommerce',
                    'group'  => 'billing',
                ))
            );
        }

        // Ship to different address checkbox - standard WooCommerce field
        // This controls visibility of shipping fields section
        $this->register_field('ship_to_different_address', array(
            'label'       => !empty($ship_to_diff_label) ? $ship_to_diff_label : __('Ship to a different address?', 'rentopian-sync'),
            'type'        => 'checkbox',
            'source'      => 'woocommerce',
            'group'       => 'billing',
            'required'    => false,
            'priority'    => 100,
            'description' => __('Check this box to enter a separate shipping address.', 'rentopian-sync'),
        ));


        // TODO : check to see if this field's slug (order_comments) should be changed to this : customer_note so we can call it like : $wc_order->get_customer_note()
        // Order comments/notes - standard WooCommerce field
        // Maps to $order->get_customer_note()
        // $this->register_field('order_comments', array(
        $this->register_field('customer_note', array(
            'label'       => __('Order Notes', 'rentopian-sync'),
            'type'        => 'textarea',
            'source'      => 'woocommerce',
            'group'       => 'order',
            'required'    => false,
            'priority'    => 95,
            'placeholder' => __('Notes about your order, e.g. special notes for delivery.', 'rentopian-sync'),
            'class'       => array('notes'),
        ));

        // Shipping fields - only shown when ship_to_different_address is checked
        // All shipping fields depend on ship_to_different_address checkbox
        $shipping_fields = array(
            'shipping_first_name' => array(
                'label'    => __('First Name', 'rentopian-sync'),
                'type'     => 'text',
                'required' => true,
                'priority' => 110,
            ),
            'shipping_last_name' => array(
                'label'    => __('Last Name', 'rentopian-sync'),
                'type'     => 'text',
                'required' => true,
                'priority' => 120,
            ),
            'shipping_address_1' => array(
                'label'    => __('Street Address', 'rentopian-sync'),
                'type'     => 'text',
                'required' => true,
                'priority' => 130,
            ),
            'shipping_address_2' => array(
                'label'       => __('Address Line 2', 'rentopian-sync'),
                'type'        => 'text',
                'required'    => false,
                'priority'    => 135,
                'placeholder' => __('Apartment, suite, unit, etc.', 'rentopian-sync'),
            ),
            'shipping_city' => array(
                'label'    => __('City', 'rentopian-sync'),
                'type'     => 'text',
                'required' => true,
                'priority' => 140,
            ),
            'shipping_state' => array(
                'label'    => __('State', 'rentopian-sync'),
                'type'     => 'state',
                'required' => true,
                'priority' => 150,
            ),
            'shipping_postcode' => array(
                'label'    => __('ZIP Code', 'rentopian-sync'),
                'type'     => 'text',
                'required' => true,
                'priority' => 160,
            ),
            'shipping_country' => array(
                'label'    => __('Country', 'rentopian-sync'),
                'type'     => 'country',
                'required' => true,
                'priority' => 165,
            ),
        );

        // Register shipping fields group
        $this->register_field_group(
            'shipping',
            !empty($shipping_label) ? $shipping_label : __('Shipping Details', 'rentopian-sync'),
            'woocommerce'
        );

        // Register each shipping field with dependency on ship_to_different_address
        foreach ($shipping_fields as $field_id => $field_config) {
            $this->register_field(
                $field_id,
                array_merge($field_config, [
                    'source'     => 'woocommerce',
                    'group'      => 'shipping',
                    'depends_on' => 'ship_to_different_address', // Only show when checkbox is checked
                ])
            );
        }
    }

    /**
     * Register custom rental-specific fields
     *
     * These are fields specific to the rental checkout:
     * - Date/time selection
     * - Delivery/pickup time selections
     * - Event types, referral sources
     * - Custom fields from Rentopian API
     */
    private function register_rental_fields() {
        // Register rental fields group
        $this->register_field_group(
            'rental',
            __('Rental Information', 'rentopian-sync'),
            'rental_custom'
        );

        // Get textual labels from settings
        $start_date_label = get_option('rental_start_date_text', __('Start Date', 'rentopian-sync'));
        $event_type_label = get_option('rental_event_types_text', '');
        $referral_label = get_option('rental_referral_sources_text', '');
        $payment_tips_label = get_option('rental_payment_tips_text', '');
        
        // Get delivery/pickup time labels from settings (allow customization)
        $delivery_time_label = get_option('rental_delivery_time_label', '');
        $pickup_time_label = get_option('rental_pickup_time_label', '');

        // Date Form (Modern) - This is a component, not a simple field
        $this->register_field('rental_date_form_modern', array(
            'label'       => !empty($start_date_label) ? $start_date_label : __('Date of Service', 'rentopian-sync'),
            'type'        => 'component',
            'source'      => 'rental_custom',
            'group'       => 'rental',
            'required'    => true,
            'priority'    => 200,
            'description' => __('The rental date selection form with start/end dates.', 'rentopian-sync'),
            'component'   => 'rental_date_form_modern',
        ));

        // Delivery Time Selections - dropdown for selecting delivery time window
        // Only shown if delivery_time_selections option has data
        // Label can be customized via rental_delivery_time_label option
        $this->register_field('delivery_time_selections_id', array(
            'label'       => !empty($delivery_time_label) ? $delivery_time_label : __('Delivery Time Window', 'rentopian-sync'),
            'type'        => 'select',
            'source'      => 'rental_custom',
            'group'       => 'rental',
            'required'    => false,
            'priority'    => 215,
            'description' => __('Select a delivery time window.', 'rentopian-sync'),
            'options_callback' => 'rental_get_delivery_time_options',
            'conditional' => array(
                'option'  => 'delivery_time_selections',
                'compare' => 'not_empty',
            ),
        ));

        // Pickup Time Selections - dropdown for selecting pickup/strike time window
        // Only shown if delivery_time_selections has data AND charge_only_delivery_for_website is false
        // Label can be customized via rental_pickup_time_label option
        $this->register_field('pickup_time_selections_id', array(
            'label'       => !empty($pickup_time_label) ? $pickup_time_label : __('Pickup Time Window', 'rentopian-sync'),
            'type'        => 'select',
            'source'      => 'rental_custom',
            'group'       => 'rental',
            'required'    => false,
            'priority'    => 216,
            'description' => __('Select a pickup time window.', 'rentopian-sync'),
            'options_callback' => 'rental_get_pickup_time_options',
            'conditional' => array(
                'option'  => 'delivery_time_selections',
                'compare' => 'not_empty',
                'also'    => array(
                    'setting' => 'charge_only_delivery_for_website',
                    'value'   => false,
                ),
            ),
        ));

        // Event Type
        // Event Type - uses rental_event_types_id to match original form field name
        $this->register_field('rental_event_type', array(
            'label'       => !empty($event_type_label) ? $event_type_label : __('Event Type', 'rentopian-sync'),
            'type'        => 'select',
            'source'      => 'rental_custom',
            'group'       => 'rental',
            'required'    => false,
            'priority'    => 240,
            'description' => __('Select the type of event.', 'rentopian-sync'),
            'options_callback' => 'rental_get_event_type_options',
            'form_field_name'  => 'rental_event_types_id', // Actual HTML form field name for rental_checkout_process()
            'conditional' => array(
                'setting' => 'rental_event_types_setting',
            ),
        ));

        // Referral Source - uses rental_referral_source_id to match original form field name
        $this->register_field('rental_referral_source', array(
            'label'       => !empty($referral_label) ? $referral_label : __('Where Did You Find Us?', 'rentopian-sync'),
            'type'        => 'select',
            'source'      => 'rental_custom',
            'group'       => 'rental',
            'required'    => false, // Optional - configurable via JSON layout "required": true
            'priority'    => 250,
            'description' => __('How did you hear about us?', 'rentopian-sync'),
            'options_callback' => 'rental_get_referral_source_options',
            'form_field_name'  => 'rental_referral_source_id', // Actual HTML form field name for rental_checkout_process()
            'conditional' => array(
                'setting' => 'rental_referral_sources_setting',
            ),
        ));

        // Special Terms - displays special terms content if set
        $this->register_field('rental_special_terms', array(
            'label'       => __('Special Terms', 'rentopian-sync'),
            'type'        => 'component',
            'source'      => 'rental_custom',
            'group'       => 'rental',
            'required'    => false,
            'priority'    => 255,
            'description' => __('Special terms and conditions.', 'rentopian-sync'),
            'component'   => 'rental_special_terms',
            'conditional' => array(
                'option'  => 'rental_special_terms',
                'compare' => 'not_empty',
            ),
        ));

        // Payment Tips (if enabled)
        $this->register_field('rental_payment_tips', array(
            'label'       => !empty($payment_tips_label) ? $payment_tips_label : __('Add a Tip', 'rentopian-sync'),
            'type'        => 'component',
            'source'      => 'rental_custom',
            'group'       => 'rental',
            'required'    => false,
            'priority'    => 270,
            'description' => __('Optional tip for the delivery team.', 'rentopian-sync'),
            'component'   => 'rental_payment_tips',
            'conditional' => array(
                'setting' => 'rental_payment_tips_enabled',
                'value'   => true,
            ),
        ));

        // Different Delivery/Pickup Address - Component field
        // Only shown if rental_do_not_use_rentopian_shipping is disabled
        // and enable_different_pickup_delivery_address_for_website is enabled
        $this->register_field('rental_different_pickup_address', array(
            'label'       => __('Different delivery and pick up locations', 'rentopian-sync'),
            'type'        => 'component',
            'source'      => 'rental_custom',
            'group'       => 'rental',
            'required'    => false,
            'priority'    => 275,
            'description' => __('Enable separate pickup address fields.', 'rentopian-sync'),
            'component'   => 'rental_different_pickup_address',
            'conditional' => array(
                'callback' => 'rental_is_different_pickup_address_enabled',
            ),
        ));

        // Damage Waiver field - Component
        $this->register_field('rental_damage_waiver', array(
            'label'       => __('Damage Waiver', 'rentopian-sync'),
            'type'        => 'component',
            'source'      => 'rental_custom',
            'group'       => 'rental',
            'required'    => false,
            'priority'    => 280,
            'description' => __('Damage waiver options.', 'rentopian-sync'),
            'component'   => 'rental_damage_waiver',
            'conditional' => array(
                'callback' => 'rental_is_damage_waiver_enabled',
            ),
        ));

        // Event Start Time field - Component
        // Only shown if rental_event_start_time option is enabled
        $this->register_field('rental_event_time', array(
            'label'       => __('Event Start Time', 'rentopian-sync'),
            'type'        => 'component',
            'source'      => 'rental_custom',
            'group'       => 'rental',
            'required'    => false,
            'priority'    => 285,
            'description' => __('Event start time selection.', 'rentopian-sync'),
            'component'   => 'rental_event_time',
            'conditional' => array(
                'callback' => 'rental_is_event_start_time_enabled',
            ),
        ));

        // Photo Upload (if enabled)
        $this->register_field('rental_photo_upload', array(
            'label'       => get_option('rental_checkout_photo_upload_label', '')
                             ? get_option('rental_checkout_photo_upload_label')
                             : __('Upload Inspiration Photos', 'rentopian-sync'),
            'type'        => 'component',
            'source'      => 'rental_custom',
            'group'       => 'rental',
            'required'    => false, // Optional - configurable via JSON layout "required": true
            'priority'    => 288,
            'description' => __('Allow customers to upload inspiration photos with their order.', 'rentopian-sync'),
            'component'   => 'rental_photo_upload',
            'conditional' => array(
                'setting' => 'rental_checkout_photo_upload_enabled',
                'value'   => true,
            ),
        ));

        // Register dynamic custom fields from Rentopian API
        $this->register_dynamic_rental_fields();
    }

    /**
     * Register dynamic custom fields from Rentopian API
     *
     * These fields come from get_option('rental_custom_fields')
     * which is synced from the Rentopian system via rental_get_custom_fields().
     * 
     * The API returns groups of fields, where each group contains:
     * - id: unique field identifier
     * - group: group name for the field
     * - slug: field slug for form naming
     * - title: human-readable label
     * - required: boolean for required validation
     * - type: numeric type code (see map_rentopian_type_code())
     * - options: JSON string of options for select/multi-select fields
     */
    private function register_dynamic_rental_fields() {
        // Get custom fields from Rentopian
        $custom_fields = get_option('rental_custom_fields', array());

        if (empty($custom_fields) || !is_array($custom_fields)) {
            return;
        }

        // Register dynamic fields group
        $this->register_field_group(
            'rental_dynamic',
            __('Custom Fields (Rentopian)', 'rentopian-sync'),
            'rental_dynamic'
        );

        $priority = 300;
        
        // Handle the grouped structure from Rentopian API
        foreach ($custom_fields as $group) {
            // Each group is an array of fields
            if (!is_array($group)) {
                continue;
            }
            
            foreach ($group as $field_data) {
                if (!is_array($field_data)) {
                    continue;
                }
                
                // Get field identifiers
                $field_id_raw = isset($field_data['id']) ? $field_data['id'] : null;
                $field_slug = isset($field_data['slug']) ? $field_data['slug'] : null;
                
                if (!$field_id_raw && !$field_slug) {
                    continue;
                }
                
                // Build a unique field ID
                $field_id = 'rental_custom_' . ($field_slug ? sanitize_key($field_slug) : $field_id_raw);
                
                // Get field type from numeric code
                $type = 'text';
                if (isset($field_data['type'])) {
                    $type = $this->map_rentopian_type_code($field_data['type']);
                }
                
                // Parse options for select/multi-select fields
                $options = array();
                if (isset($field_data['options']) && !empty($field_data['options'])) {
                    $parsed_options = is_string($field_data['options']) 
                        ? json_decode($field_data['options'], true) 
                        : $field_data['options'];
                    
                    if (is_array($parsed_options)) {
                        // Add placeholder option for selects
                        if ($type === 'select') {
                            $options[''] = __('Choose', 'rentopian-sync');
                        }
                        foreach ($parsed_options as $opt) {
                            $options[$opt] = $opt;
                        }
                    }
                }
                
                // Register the field
                $this->register_field($field_id, array(
                    'label'         => isset($field_data['title']) ? $field_data['title'] : $field_slug,
                    'type'          => $type,
                    'source'        => 'rental_dynamic',
                    'group'         => 'rental_dynamic',
                    'required'      => isset($field_data['required']) ? (bool) $field_data['required'] : false,
                    'priority'      => $priority,
                    'description'   => '',
                    'options'       => $options,
                    'rentopian_id'  => $field_id_raw,
                    'rentopian_slug' => $field_slug,
                    'rentopian_group' => isset($field_data['group']) ? $field_data['group'] : '',
                    'rentopian_type_code' => isset($field_data['type']) ? $field_data['type'] : 1,
                ));

                $priority += 10;
            }
        }
    }

    /**
     * Map Rentopian numeric type code to standard field type
     *
     * Type codes from Rentopian API:
     * 1 = text (default)
     * 2 = number
     * 3 = checkbox
     * 4 = datetime-local
     * 5 = date
     * 6 = time
     * 7 = email
     * 8 = url (website)
     * 9 = select
     * 10 = multi-select
     * 11 = currency (number with decimals)
     * 12 = phone/tel
     * 13 = textarea
     *
     * @param int|string $type_code The numeric type code from Rentopian
     * @return string Standardized field type
     */
    private function map_rentopian_type_code($type_code) {
        $type_map = array(
            1  => 'text',
            2  => 'number',
            3  => 'checkbox',
            4  => 'datetime-local',
            5  => 'date',
            6  => 'time',
            7  => 'email',
            8  => 'url',
            9  => 'select',
            10 => 'select', // multi-select rendered as select with multiple attribute
            11 => 'number', // currency
            12 => 'tel',
            13 => 'textarea',
        );
        
        $code = intval($type_code);
        return isset($type_map[$code]) ? $type_map[$code] : 'text';
    }

    /**
     * Map Rentopian field type string to standard field type (legacy support)
     *
     * @param string $rentopian_type The field type from Rentopian
     * @return string Standardized field type
     */
    private function map_rentopian_field_type($rentopian_type) {
        $type_map = array(
            'string'   => 'text',
            'text'     => 'text',
            'number'   => 'number',
            'integer'  => 'number',
            'email'    => 'email',
            'phone'    => 'tel',
            'tel'      => 'tel',
            'select'   => 'select',
            'dropdown' => 'select',
            'checkbox' => 'checkbox',
            'boolean'  => 'checkbox',
            'textarea' => 'textarea',
            'date'     => 'date',
            'time'     => 'time',
            'datetime' => 'datetime',
        );

        $rentopian_type = strtolower($rentopian_type);

        return isset($type_map[$rentopian_type]) ? $type_map[$rentopian_type] : 'text';
    }

    /**
     * Register a field group
     *
     * @param string $group_id    Unique group identifier
     * @param string $group_label Human-readable group label
     * @param string $source      Source type (woocommerce, rental_custom, rental_dynamic)
     */
    public function register_field_group($group_id, $group_label, $source = 'custom') {
        $this->field_groups[$group_id] = array(
            'id'     => $group_id,
            'label'  => $group_label,
            'source' => $source,
            'fields' => array(),
        );
    }

    /**
     * Register a single field
     *
     * @param string $field_id Unique field identifier
     * @param array  $config   Field configuration
     */
    public function register_field($field_id, $config) {
        // Ensure we're initialized
        // Note: This is a no-op if called during init

        // Default configuration
        $defaults = array(
            'id'          => $field_id,
            'label'       => '',
            'type'        => 'text',
            'source'      => 'custom',
            'group'       => 'custom',
            'required'    => false,
            'priority'    => 50,
            'description' => '',
            'placeholder' => '',
            'class'       => array(),
            'default'     => '',
            'options'     => array(),
            'conditional' => array(),
            'validation'  => array(),
        );

        // Merge with defaults
        $config = wp_parse_args($config, $defaults);
        $config['id'] = $field_id;

        // Store field
        $this->fields[$field_id] = $config;

        // Add to group
        $group = $config['group'];
        if (isset($this->field_groups[$group])) {
            $this->field_groups[$group]['fields'][] = $field_id;
        }
    }

    /**
     * Get all registered fields
     *
     * @return array All registered fields
     */
    public function get_all_fields() {
        $this->init();
        return $this->fields;
    }

    /**
     * Get a single field by ID
     *
     * @param string $field_id The field ID to retrieve
     * @return array|null Field configuration or null if not found
     */
    public function get_field($field_id) {
        $this->init();
        return isset($this->fields[$field_id]) ? $this->fields[$field_id] : null;
    }

    /**
     * Check if a field exists
     *
     * @param string $field_id The field ID to check
     * @return bool True if field exists
     */
    public function field_exists($field_id) {
        $this->init();
        return isset($this->fields[$field_id]);
    }

    /**
     * Get all field groups
     *
     * @return array All field groups
     */
    public function get_field_groups() {
        $this->init();
        return $this->field_groups;
    }

    /**
     * Get fields by group
     *
     * @param string $group_id The group ID
     * @return array Fields in the specified group
     */
    public function get_fields_by_group($group_id) {
        $this->init();

        if (!isset($this->field_groups[$group_id])) {
            return array();
        }

        $fields = array();
        foreach ($this->field_groups[$group_id]['fields'] as $field_id) {
            if (isset($this->fields[$field_id])) {
                $fields[$field_id] = $this->fields[$field_id];
            }
        }

        return $fields;
    }

    /**
     * Get fields by source type
     *
     * @param string $source Source type (woocommerce, rental_custom, rental_dynamic)
     * @return array Fields from the specified source
     */
    public function get_fields_by_source($source) {
        $this->init();

        $fields = array();
        foreach ($this->fields as $field_id => $field) {
            if ($field['source'] === $source) {
                $fields[$field_id] = $field;
            }
        }

        return $fields;
    }

    /**
     * Get fields formatted for admin interface
     *
     * Returns a simplified version suitable for JavaScript/JSON.
     *
     * @return array Formatted field list for admin UI
     */
    public function get_fields_for_admin() {
        $this->init();

        $formatted = array();

        foreach ($this->fields as $field_id => $field) {
            $formatted[$field_id] = array(
                'id'          => $field_id,
                'label'       => $field['label'],
                'type'        => $field['type'],
                'group'       => $field['group'],
                'source'      => $field['source'],
                'required'    => $field['required'],
                'description' => $field['description'],
            );

            // Include conditional visibility info
            if (!empty($field['conditional'])) {
                $formatted[$field_id]['conditional'] = $field['conditional'];
            }
        }

        return $formatted;
    }

    /**
     * Get field groups formatted for admin interface
     *
     * @return array Formatted groups for admin UI
     */
    public function get_groups_for_admin() {
        $this->init();

        $formatted = array();

        foreach ($this->field_groups as $group_id => $group) {
            $formatted[$group_id] = array(
                'id'     => $group_id,
                'label'  => $group['label'],
                'source' => $group['source'],
                'count'  => count($group['fields']),
            );
        }

        return $formatted;
    }

    /**
     * Check if a field should be visible based on settings
     *
     * @param string $field_id The field ID to check
     * @return bool True if field should be displayed
     */
    public function is_field_visible($field_id) {
        $this->init();

        $field = $this->get_field($field_id);
        if (!$field) {
            return false;
        }

        // Check conditional visibility
        if (!empty($field['conditional'])) {
            $conditional = $field['conditional'];
            if (isset($conditional['setting'])) {
                $setting_value = get_option($conditional['setting'], false);
                $expected_value = isset($conditional['value']) ? $conditional['value'] : true;

                if ($setting_value != $expected_value) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Get all visible fields (respecting conditional logic)
     *
     * @return array Fields that should be displayed
     */
    public function get_visible_fields() {
        $this->init();

        $visible = array();

        foreach ($this->fields as $field_id => $field) {
            if ($this->is_field_visible($field_id)) {
                $visible[$field_id] = $field;
            }
        }

        return $visible;
    }

    /**
     * Refresh dynamic fields from Rentopian API
     *
     * Clears existing dynamic fields and re-registers them from
     * the updated rental_custom_fields option.
     *
     * @return int Number of dynamic fields registered
     */
    public function refresh_dynamic_fields() {
        // Ensure we're initialized first
        $this->init();

        // Remove existing dynamic fields from registry
        $fields_to_remove = array();
        foreach ($this->fields as $field_id => $field) {
            if (isset($field['source']) && $field['source'] === 'rental_dynamic') {
                $fields_to_remove[] = $field_id;
            }
        }
        
        foreach ($fields_to_remove as $field_id) {
            unset($this->fields[$field_id]);
        }

        // Clear the rental_dynamic group fields array
        if (isset($this->field_groups['rental_dynamic'])) {
            $this->field_groups['rental_dynamic']['fields'] = array();
        }

        // Re-register dynamic fields with fresh data from option
        $this->register_dynamic_rental_fields();

        // Return count of new dynamic fields
        $count = 0;
        foreach ($this->fields as $field) {
            if (isset($field['source']) && $field['source'] === 'rental_dynamic') {
                $count++;
            }
        }

        // Update the group count
        if (isset($this->field_groups['rental_dynamic'])) {
            $this->field_groups['rental_dynamic']['count'] = $count;
        }

        return $count;
    }

    /**
     * Get all dynamic field IDs
     *
     * @return array Array of dynamic field IDs
     */
    public function get_dynamic_field_ids() {
        $this->init();

        $dynamic_fields = array();
        foreach ($this->fields as $field_id => $field) {
            if (isset($field['source']) && $field['source'] === 'rental_dynamic') {
                $dynamic_fields[] = $field_id;
            }
        }

        return $dynamic_fields;
    }
}
