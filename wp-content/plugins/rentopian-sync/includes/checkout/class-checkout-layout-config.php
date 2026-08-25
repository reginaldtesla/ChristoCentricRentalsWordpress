<?php
/**
 * Checkout Layout Configuration Handler
 *
 * Handles CRUD operations for checkout layout configuration.
 * Stores configuration in wp_options as JSON.
 * Provides validation and default layout generation.
 *
 * @package    Rentopian_Sync
 * @subpackage Rentopian_Sync/includes/checkout
 * @since      2.13.0
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Class Rental_Checkout_Layout_Config
 *
 * Manages checkout layout configuration storage and validation.
 * This class is responsible for:
 * - Storing/retrieving layout configurations from database
 * - Validating configuration structure and field references
 * - Providing default layout when none is configured
 * - Import/export functionality for configurations
 */
class Rental_Checkout_Layout_Config {

    /**
     * Option key for storing layout configuration in wp_options
     *
     * @var string
     */
    const OPTION_KEY = 'rental_checkout_layout_config';

    /**
     * Option key for storing whether custom layout is enabled
     *
     * @var string
     */
    // const ENABLED_KEY = 'rental_checkout_layout_enabled';

    /**
     * Current configuration version for migration purposes
     *
     * @var string
     */
    const CONFIG_VERSION = '1.0.0';

    /**
     * Singleton instance
     *
     * @var Rental_Checkout_Layout_Config|null
     */
    private static $instance = null;

    /**
     * Cached configuration to avoid repeated database calls
     *
     * @var array|null
     */
    private $config_cache = null;

    /**
     * Get singleton instance
     *
     * @return Rental_Checkout_Layout_Config
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Private constructor to enforce singleton pattern
     */
    private function __construct() {
        // Constructor is intentionally empty
        // Initialization happens on first use
    }

    /**
     * Get the current layout configuration
     *
     * Returns the stored configuration or default if none exists.
     * Configuration is cached for performance.
     *
     * @param bool $force_refresh Force refresh from database
     * @return array The layout configuration array
     */
    public function get_layout($force_refresh = false) {
        // Return cached config if available and not forcing refresh
        if (!$force_refresh && null !== $this->config_cache) {
            return $this->config_cache;
        }

        // Get from database
        $config = get_option(self::OPTION_KEY, null);

        // If no config stored, return default
        if (empty($config) || !is_array($config)) {
            $this->config_cache = $this->get_default_layout();
            return $this->config_cache;
        }

        // Cache and return
        $this->config_cache = $config;
        return $this->config_cache;
    }

    /**
     * Save layout configuration to database
     *
     * Validates the configuration before saving.
     * Clears the cache after successful save.
     *
     * @param array $config The configuration array to save
     * @return array|WP_Error Array with success message or WP_Error on failure
     */
    public function save_layout($config) {
        // Remove stale standalone date sub-fields before validating/saving.
        $removed_fields = array();
        $config = $this->sanitize_layout($config, $removed_fields);

        $removed_note = '';
        if (!empty($removed_fields)) {
            $removed_note = ' ' . sprintf(
                /* translators: %s: comma-separated list of removed field IDs */
                __('Removed unsupported field(s): %s — date selection is handled by the Date of Service field.', 'rentopian-sync'),
                implode(', ', $removed_fields)
            );
        }

        // Validate configuration structure
        $validation_result = $this->validate_config($config);

        if (is_wp_error($validation_result)) {
            return $validation_result;
        }

        // Add metadata
        $config['version'] = self::CONFIG_VERSION;
        $config['updated_at'] = current_time('mysql');
        $config['updated_by'] = get_current_user_id();

        // Save to database
        $saved = update_option(self::OPTION_KEY, $config);

        if (!$saved) {
            // Check if the value is the same (update_option returns false if value unchanged)
            $existing = get_option(self::OPTION_KEY);
            if ($existing === $config) {
                // Value unchanged, still considered success
                $this->config_cache = $config;
                return array(
                    'success' => true,
                    'message' => __('Layout configuration unchanged.', 'rentopian-sync') . $removed_note,
                );
            }
            return new WP_Error(
                'save_failed',
                __('Failed to save layout configuration to database.', 'rentopian-sync')
            );
        }

        // Clear cache
        $this->config_cache = $config;

        // Fire action for other components to react
        do_action('rental_checkout_layout_saved', $config);

        return array(
            'success' => true,
            'message' => __('Layout configuration saved successfully.', 'rentopian-sync') . $removed_note,
        );
    }

    /**
     * Validate configuration structure
     *
     * Checks that the configuration has valid structure:
     * - Has required keys (sections array)
     * - Each section has rows array
     * - Each row has columns array
     * - Column widths are 1-12 and don't exceed 12 per row
     * - Field IDs reference valid registered fields (when registry is available)
     *
     * @param array $config The configuration to validate
     * @return true|WP_Error True if valid, WP_Error with details if invalid
     */
    public function validate_config($config) {
        // Check basic structure
        if (!is_array($config)) {
            return new WP_Error(
                'invalid_config',
                __('Configuration must be an array.', 'rentopian-sync')
            );
        }

        // Check for sections array
        if (!isset($config['sections']) || !is_array($config['sections'])) {
            return new WP_Error(
                'missing_sections',
                __('Configuration must contain a "sections" array.', 'rentopian-sync')
            );
        }

        // Validate each section
        foreach ($config['sections'] as $section_index => $section) {
            $section_validation = $this->validate_section($section, $section_index);
            if (is_wp_error($section_validation)) {
                return $section_validation;
            }
        }

        // The rental date component must be present and active.
        $date_component_validation = $this->validate_date_component($config);
        if (is_wp_error($date_component_validation)) {
            return $date_component_validation;
        }

        // Validate that all required custom fields are included in layout
        $required_fields_validation = $this->validate_required_custom_fields($config);
        if (is_wp_error($required_fields_validation)) {
            return $required_fields_validation;
        }

        return true;
    }

    /**
     * Validate that all required custom fields are included in the layout
     *
     * @param array $config The layout configuration
     * @return true|WP_Error True if valid, WP_Error if required fields are missing
     */
    private function validate_required_custom_fields($config) {
        // Get all required dynamic custom fields from Rentopian
        $custom_fields = get_option('rental_custom_fields', array());
        
        if (empty($custom_fields) || !is_array($custom_fields)) {
            return true; // No custom fields to validate
        }

        // Build list of required field IDs
        $required_fields = array();
        foreach ($custom_fields as $group) {
            if (!is_array($group)) {
                continue;
            }
            
            foreach ($group as $field_data) {
                if (!is_array($field_data)) {
                    continue;
                }
                
                // Check if field is required
                $is_required = isset($field_data['required']) && $field_data['required'];
                
                if ($is_required) {
                    // Build field ID the same way registry does
                    $field_slug = isset($field_data['slug']) ? $field_data['slug'] : null;
                    $field_id_raw = isset($field_data['id']) ? $field_data['id'] : null;
                    
                    if ($field_slug || $field_id_raw) {
                        $field_id = 'rental_custom_' . ($field_slug ? sanitize_key($field_slug) : $field_id_raw);
                        $required_fields[$field_id] = isset($field_data['title']) ? $field_data['title'] : $field_id;
                    }
                }
            }
        }

        if (empty($required_fields)) {
            return true; // No required fields to check
        }

        // Get all fields in the layout
        $layout_fields = $this->get_all_layout_fields($config);

        // Check which required fields are missing
        $missing_fields = array();
        foreach ($required_fields as $field_id => $field_label) {
            // Check if field is in layout and is active
            if (!isset($layout_fields[$field_id]) || !$layout_fields[$field_id]['active']) {
                $missing_fields[] = $field_label . ' (' . $field_id . ')';
            }
        }

        if (!empty($missing_fields)) {
            return new WP_Error(
                'missing_required_fields',
                sprintf(
                    __('The following required custom fields are missing from the layout or set as inactive: %s. Please add these fields to the layout before saving.', 'rentopian-sync'),
                    implode(', ', $missing_fields)
                )
            );
        }

        return true;
    }

    /**
     * Get all fields defined in the layout configuration
     *
     * @param array $config The layout configuration
     * @return array Array of field IDs with their active status
     */
    private function get_all_layout_fields($config) {
        $fields = array();

        if (!isset($config['sections']) || !is_array($config['sections'])) {
            return $fields;
        }

        foreach ($config['sections'] as $section) {
            if (!isset($section['rows']) || !is_array($section['rows'])) {
                continue;
            }

            foreach ($section['rows'] as $row) {
                if (!isset($row['columns']) || !is_array($row['columns'])) {
                    continue;
                }

                foreach ($row['columns'] as $column) {
                    if (isset($column['field']) && !empty($column['field'])) {
                        $field_id = $column['field'];
                        $is_active = !isset($column['active']) || $column['active'];

                        $fields[$field_id] = array(
                            'active' => $is_active,
                            'hidden_visual' => isset($column['hidden_visual']) && $column['hidden_visual'],
                        );
                    }
                }
            }
        }

        return $fields;
    }

    /**
     * Field IDs that must never appear in a layout.
     *
     * Standalone start/end date fields are not real fields — date selection is the
     * rental_date_form_modern component. Any stored entries are stale and render nothing.
     *
     * @return string[]
     */
    private function get_unsupported_layout_fields() {
        return array(
            'rental_start_date',
            'rental_end_date',
        );
    }

    /**
     * Sanitize a layout configuration before validation/save.
     *
     * - Removes unsupported (stale standalone date) field columns.
     * - Keeps the mandatory rental date component active when present, so it can
     *   never be persisted as inactive (which would hide the picker and fail save).
     *
     * @param array $config  The layout configuration.
     * @param array $removed Out-param populated with the removed field IDs.
     * @return array The cleaned configuration.
     */
    private function sanitize_layout($config, &$removed = array()) {
        $removed = array();

        if (!is_array($config) || empty($config['sections']) || !is_array($config['sections'])) {
            return $config;
        }

        $denylist      = $this->get_unsupported_layout_fields();
        $mandatory     = $this->get_mandatory_fields();
        $force_visible = $this->has_google_maps_api_key()
            ? array()
            : array(
                'billing_city', 'billing_state', 'billing_postcode',
                'shipping_city', 'shipping_state', 'shipping_postcode',
            );

        foreach ($config['sections'] as $si => $section) {
            if (!isset($section['rows']) || !is_array($section['rows'])) {
                continue;
            }

            foreach ($section['rows'] as $ri => $row) {
                if (!isset($row['columns']) || !is_array($row['columns'])) {
                    continue;
                }

                $kept = array();
                foreach ($row['columns'] as $column) {
                    if (is_array($column) && isset($column['field'])) {
                        if (in_array($column['field'], $denylist, true)) {
                            $removed[] = $column['field'];
                            continue;
                        }
                        // Mandatory fields must never be persisted inactive or not-required.
                        if (in_array($column['field'], $mandatory, true)) {
                            $column['active']   = 1;
                            $column['required'] = true;
                        }
                        // Without a Google key, these address fields cannot be hidden.
                        if (in_array($column['field'], $force_visible, true)) {
                            $column['hidden_visual'] = 0;
                        }
                    }
                    $kept[] = $column;
                }

                $config['sections'][$si]['rows'][$ri]['columns'] = array_values($kept);
            }
        }

        $removed = array_values(array_unique($removed));

        return $config;
    }

    /**
     * Validate that the rental date component is present and active.
     *
     * The date component handles start/end selection and order sync; the layout
     * must always include it as an active field.
     *
     * @param array $config The layout configuration.
     * @return true|WP_Error
     */
    private function validate_date_component($config) {
        $fields = $this->get_all_layout_fields($config);

        if (!isset($fields['rental_date_form_modern'])) {
            return new WP_Error(
                'missing_date_component',
                __('The rental date field (Date of Service) is required and must be present in the layout.', 'rentopian-sync')
            );
        }

        if (empty($fields['rental_date_form_modern']['active'])) {
            return new WP_Error(
                'inactive_date_component',
                __('The rental date field (Date of Service) cannot be set to inactive.', 'rentopian-sync')
            );
        }

        return true;
    }

    /**
     * Validate a single section structure
     *
     * @param array $section The section to validate
     * @param int   $section_index The section index for error messages
     * @return true|WP_Error
     */
    private function validate_section($section, $section_index) {
        // Section must be an array
        if (!is_array($section)) {
            return new WP_Error(
                'invalid_section',
                sprintf(
                    __('Section at index %d must be an array.', 'rentopian-sync'),
                    $section_index
                )
            );
        }

        // Section must have rows array
        if (!isset($section['rows']) || !is_array($section['rows'])) {
            return new WP_Error(
                'missing_rows',
                sprintf(
                    __('Section at index %d must contain a "rows" array.', 'rentopian-sync'),
                    $section_index
                )
            );
        }

        // Validate each row
        foreach ($section['rows'] as $row_index => $row) {
            $row_validation = $this->validate_row($row, $section_index, $row_index);
            if (is_wp_error($row_validation)) {
                return $row_validation;
            }
        }

        return true;
    }

    /**
     * Validate a single row structure
     *
     * @param array $row          The row to validate
     * @param int   $section_index The parent section index
     * @param int   $row_index    The row index for error messages
     * @return true|WP_Error
     */
    private function validate_row($row, $section_index, $row_index) {
        // Row must be an array
        if (!is_array($row)) {
            return new WP_Error(
                'invalid_row',
                sprintf(
                    __('Row %d in section %d must be an array.', 'rentopian-sync'),
                    $row_index,
                    $section_index
                )
            );
        }

        // Row must have columns array
        if (!isset($row['columns']) || !is_array($row['columns'])) {
            return new WP_Error(
                'missing_columns',
                sprintf(
                    __('Row %d in section %d must contain a "columns" array.', 'rentopian-sync'),
                    $row_index,
                    $section_index
                )
            );
        }

        // Validate columns and calculate total width
        $total_width = 0;

        foreach ($row['columns'] as $col_index => $column) {
            $col_validation = $this->validate_column($column, $section_index, $row_index, $col_index);
            if (is_wp_error($col_validation)) {
                return $col_validation;
            }

            $width = isset($column['width']) ? intval($column['width']) : 12;
            $total_width += $width;
        }

        // Check that total width doesn't exceed 12
        if ($total_width > 12) {
            return new WP_Error(
                'row_overflow',
                sprintf(
                    __('Row %d in section %d exceeds 12 columns (total: %d).', 'rentopian-sync'),
                    $row_index,
                    $section_index,
                    $total_width
                )
            );
        }

        return true;
    }

    /**
     * Validate a single column structure
     *
     * @param array $column        The column to validate
     * @param int   $section_index The parent section index
     * @param int   $row_index     The parent row index
     * @param int   $col_index     The column index for error messages
     * @return true|WP_Error
     */
    private function validate_column($column, $section_index, $row_index, $col_index) {
        // Column must be an array
        if (!is_array($column)) {
            return new WP_Error(
                'invalid_column',
                sprintf(
                    __('Column %d in row %d, section %d must be an array.', 'rentopian-sync'),
                    $col_index,
                    $row_index,
                    $section_index
                )
            );
        }

        // Validate width if present
        if (isset($column['width'])) {
            $width = intval($column['width']);
            if ($width < 1 || $width > 12) {
                return new WP_Error(
                    'invalid_width',
                    sprintf(
                        __('Column width must be between 1 and 12. Found %d at column %d, row %d, section %d.', 'rentopian-sync'),
                        $width,
                        $col_index,
                        $row_index,
                        $section_index
                    )
                );
            }
        }

        // Field ID validation is optional - empty columns are allowed
        // Advanced field validation happens when field registry is available

        return true;
    }

    /**
     * Get the default layout configuration
     *
     * This default layout matches the checkout form structure based on all registered fields
     * from the Field Registry (class-checkout-field-registry.php):
     *
     * WOOCOMMERCE BILLING FIELDS (register_woocommerce_fields):
     * - billing_first_name, billing_last_name, billing_company
     * - billing_email, billing_phone
     * - billing_address_1, billing_address_2
     * - billing_city, billing_state, billing_postcode, billing_country
     *
     * WOOCOMMERCE SHIPPING FIELDS (register_woocommerce_fields):
     * - shipping_first_name, shipping_last_name
     * - shipping_address_1, shipping_city, shipping_state, shipping_postcode
     *
     * RENTAL STATIC FIELDS (register_rental_fields):
     * - rental_date_form_modern (Date of Service component)
     * - rental_multi_day_event (Multi Day Event checkbox)
     * - rental_event_type (Event Type dropdown)
     * - rental_referral_source (Referral Source dropdown)
     * - rental_payment_tips (Payment Tips component)
     *
     * DYNAMIC FIELDS FROM RENTOPIAN API (register_dynamic_rental_fields):
     * - These are prefixed with 'rental_custom_' followed by their slug
     * - Examples: rental_custom_outdoor, rental_custom_2-hour-delivery-window
     * - Use "Refresh Custom Fields from Rentopian" button to load them
     * - Add them to the layout JSON after refreshing
     *
     * Column properties:
     * - width: Grid column width (1-12, total per row should equal 12)
     * - field: Field ID from the registry
     * - class: Additional CSS classes for styling
     * - active: 1=visible, 0=hidden (mandatory fields always show)
     * - hidden_visual: 1=rendered but CSS hidden (for autocomplete fields)
     * - depends_on: Parent field ID for conditional display
     *
     * @return array Default layout configuration
     */
    public function get_default_layout() {
        return array(
            // Schema version for future migrations
            'version'         => self::CONFIG_VERSION,
            
            // Grid system identifier - 'rentopian' uses our custom 12-column grid
            'grid_system'     => 'rentopian',
            
            // Main container CSS class
            'container_class' => 'rentopian-checkout-form',
            
            // Timestamp for tracking when layout was created
            'created_at'      => current_time('mysql'),
            
            // Layout sections - each section can be a collapsible group
            'sections'        => array(
                
                // ============================================================
                // SECTION 1: BILLING INFORMATION
                // Contains all WooCommerce billing fields + rental fields
                // This is the primary checkout section visible to customers
                // ============================================================
                array(
                    'id'          => 'billing_section',
                    'title'       => __('Billing Information', 'rentopian-sync'),
                    'description' => __('Please enter your billing details and event information.', 'rentopian-sync'),
                    'class'       => 'rentopian-billing-section',
                    'rows'        => array(
                        
                        // ----------------------------------------------------
                        // ROW: Customer Name
                        // Fields: billing_first_name, billing_last_name
                        // Layout: 50% + 50% (two columns)
                        // ----------------------------------------------------
                        array(
                            'id'      => 'row_customer_name',
                            'class'   => '',
                            'columns' => array(
                                array(
                                    'width'         => 6,
                                    'field'         => 'billing_first_name',
                                    'class'         => '',
                                    'active'        => 1,
                                    'hidden_visual' => 0,
                                    'depends_on'    => '',
                                ),
                                array(
                                    'width'         => 6,
                                    'field'         => 'billing_last_name',
                                    'class'         => '',
                                    'active'        => 1,
                                    'hidden_visual' => 0,
                                    'depends_on'    => '',
                                ),
                            ),
                        ),
                        
                        // ----------------------------------------------------
                        // ROW: Contact Information
                        // Fields: billing_phone, billing_email
                        // Layout: 50% + 50% (two columns)
                        // ----------------------------------------------------
                        array(
                            'id'      => 'row_contact_info',
                            'class'   => '',
                            'columns' => array(
                                array(
                                    'width'         => 6,
                                    'field'         => 'billing_phone',
                                    'class'         => '',
                                    'active'        => 1,
                                    'hidden_visual' => 0,
                                    'depends_on'    => '',
                                ),
                                array(
                                    'width'         => 6,
                                    'field'         => 'billing_email',
                                    'class'         => '',
                                    'active'        => 1,
                                    'hidden_visual' => 0,
                                    'depends_on'    => '',
                                ),
                            ),
                        ),
                        
                        // ----------------------------------------------------
                        // ROW: Rental Date + Venue Address
                        // Fields: rental_date_form_modern, billing_address_1
                        // Layout: 50% + 50% (two columns)
                        // Note: billing_address_1 serves as "Venue / Address"
                        // ----------------------------------------------------
                        array(
                            'id'      => 'row_date_venue',
                            'class'   => '',
                            'columns' => array(
                                array(
                                    'width'         => 6,
                                    'field'         => 'rental_date_form_modern',
                                    'class'         => '',
                                    'active'        => 1,
                                    'hidden_visual' => 0,
                                    'depends_on'    => '',
                                ),
                                array(
                                    'width'         => 6,
                                    'field'         => 'billing_address_1',
                                    'class'         => '',
                                    'active'        => 1,
                                    'hidden_visual' => 0,
                                    'depends_on'    => '',
                                ),
                            ),
                        ),
                        
                        // ----------------------------------------------------
                        // ROW: Hidden Address Fields (for Google Places Autocomplete)
                        // Fields: billing_city, billing_state, billing_postcode
                        // Layout: 33% + 33% + 33% (three columns, all hidden)
                        // Note: These fields are rendered in DOM but visually hidden.
                        // Google Places API fills them automatically when user
                        // selects an address from autocomplete suggestions.
                        // ----------------------------------------------------
                        array(
                            'id'      => 'row_address_autocomplete',
                            'class'   => 'rentopian-hidden-row',
                            'columns' => array(
                                array(
                                    'width'         => 4,
                                    'field'         => 'billing_city',
                                    'class'         => '',
                                    'active'        => 1,
                                    'hidden_visual' => 1,
                                    'depends_on'    => 'billing_address_1',
                                ),
                                array(
                                    'width'         => 4,
                                    'field'         => 'billing_state',
                                    'class'         => '',
                                    'active'        => 1,
                                    'hidden_visual' => 1,
                                    'depends_on'    => 'billing_address_1',
                                ),
                                array(
                                    'width'         => 4,
                                    'field'         => 'billing_postcode',
                                    'class'         => '',
                                    'active'        => 1,
                                    'hidden_visual' => 1,
                                    'depends_on'    => 'billing_address_1',
                                ),
                            ),
                        ),
                        
                        // ----------------------------------------------------
                        // Note : this is a specific dynamic field related to a specific client
                        // ROW: Outdoor Checkbox (Rental custom/dynamic field)
                        // Fields: rental_custom_outdoor
                        // Layout: 50% (single column, leaves room for other fields)
                        // ----------------------------------------------------
                        // array(
                        //     'id'      => 'row_event_checkboxes',
                        //     'class'   => '',
                        //     'columns' => array(
                        //         array(
                        //             'width'         => 6,
                        //             'field'         => 'rental_custom_outdoor',
                        //             'class'         => '',
                        //             'active'        => 1,
                        //             'hidden_visual' => 0,
                        //             'depends_on'    => '',
                        //         ),
                        //     ),
                        // ),
                        
                        // ----------------------------------------------------
                        // ROW: Delivery/Pickup Time Selections
                        // Fields: delivery_time_selections_id, pickup_time_selections_id
                        // Layout: 50% + 50% (two columns)
                        // Note: These fields are conditional based on whether
                        // delivery_time_selections option has data. Pickup is
                        // only shown if charge_only_delivery_for_website is false.
                        // ----------------------------------------------------
                        array(
                            'id'      => 'row_time_selections',
                            'class'   => '',
                            'columns' => array(
                                array(
                                    'width'         => 6,
                                    'field'         => 'delivery_time_selections_id',
                                    'class'         => '',
                                    'active'        => 1,
                                    'hidden_visual' => 0,
                                    'depends_on'    => '',
                                ),
                                array(
                                    'width'         => 6,
                                    'field'         => 'pickup_time_selections_id',
                                    'class'         => '',
                                    'active'        => 1,
                                    'hidden_visual' => 0,
                                    'depends_on'    => '',
                                ),
                            ),
                        ),
                        
                        // ----------------------------------------------------
                        // ROW: Event Type Dropdown
                        // Fields: rental_event_type
                        // Layout: 100% (full width)
                        // Note: This field is conditional based on settings
                        // ----------------------------------------------------
                        array(
                            'id'      => 'row_event_type',
                            'class'   => '',
                            'columns' => array(
                                array(
                                    'width'         => 12,
                                    'field'         => 'rental_event_type',
                                    'class'         => '',
                                    'active'        => 1,
                                    'hidden_visual' => 0,
                                    'depends_on'    => '',
                                ),
                            ),
                        ),
                        
                        // ----------------------------------------------------
                        // ROW: Referral Source Dropdown
                        // Fields: rental_referral_source
                        // Layout: 100% (full width)
                        // Note: "Where Did You Find Us?" - conditional based on settings
                        // ----------------------------------------------------
                        array(
                            'id'      => 'row_referral_source',
                            'class'   => '',
                            'columns' => array(
                                array(
                                    'width'         => 12,
                                    'field'         => 'rental_referral_source',
                                    'class'         => '',
                                    'active'        => 1,
                                    'hidden_visual' => 0,
                                    'depends_on'    => '',
                                ),
                            ),
                        ),
                        
                        // ----------------------------------------------------
                        // ROW: Payment Tips Component
                        // Fields: rental_payment_tips
                        // Layout: 100% (full width)
                        // Note: Optional tip for delivery team - conditional
                        // ----------------------------------------------------
                        array(
                            'id'      => 'row_payment_tips',
                            'class'   => '',
                            'columns' => array(
                                array(
                                    'width'         => 12,
                                    'field'         => 'rental_payment_tips',
                                    'class'         => '',
                                    'active'        => 1,
                                    'hidden_visual' => 0,
                                    'depends_on'    => '',
                                ),
                            ),
                        ),
                    ),
                ),
                
                // ============================================================
                // SECTION 2: ADDITIONAL BILLING FIELDS
                // Contains optional WooCommerce billing fields that may be
                // needed for certain checkout scenarios (company, address line 2,
                // country selection). These are set to inactive by default.
                // Activate them by changing "active": 0 to "active": 1
                // ============================================================
                array(
                    'id'          => 'additional_billing_section',
                    'title'       => __('Additional Information', 'rentopian-sync'),
                    'description' => '',
                    'class'       => 'rentopian-additional-section',
                    'rows'        => array(
                        
                        // ----------------------------------------------------
                        // ROW: Company Name (Optional)
                        // Fields: billing_company
                        // Layout: 100% (full width)
                        // Status: INACTIVE by default - enable if needed
                        // ----------------------------------------------------
                        array(
                            'id'      => 'row_company',
                            'class'   => '',
                            'columns' => array(
                                array(
                                    'width'         => 12,
                                    'field'         => 'billing_company',
                                    'class'         => '',
                                    'active'        => 0,
                                    'hidden_visual' => 0,
                                    'depends_on'    => '',
                                ),
                            ),
                        ),
                        
                        // ----------------------------------------------------
                        // ROW: Address Line 2 (Optional)
                        // Fields: billing_address_2
                        // Layout: 100% (full width)
                        // Status: INACTIVE by default - enable if needed
                        // Note: Apartment, suite, unit, etc.
                        // ----------------------------------------------------
                        array(
                            'id'      => 'row_address_2',
                            'class'   => '',
                            'columns' => array(
                                array(
                                    'width'         => 12,
                                    'field'         => 'billing_address_2',
                                    'class'         => '',
                                    'active'        => 0,
                                    'hidden_visual' => 0,
                                    'depends_on'    => 'billing_address_1',
                                ),
                            ),
                        ),
                        
                        // ----------------------------------------------------
                        // ROW: Country Selection (Optional)
                        // Fields: billing_country
                        // Layout: 100% (full width)
                        // Status: INACTIVE by default - usually auto-detected
                        // Note: Enable for international checkouts
                        // ----------------------------------------------------
                        array(
                            'id'      => 'row_country',
                            'class'   => '',
                            'columns' => array(
                                array(
                                    'width'         => 12,
                                    'field'         => 'billing_country',
                                    'class'         => '',
                                    'active'        => 0,
                                    'hidden_visual' => 0,
                                    'depends_on'    => '',
                                ),
                            ),
                        ),
                    ),
                ),
                
                // ============================================================
                // SECTION 3: SHIPPING INFORMATION
                // Contains the "Ship to different address" checkbox and
                // shipping address fields. All shipping fields depend on
                // the checkbox being checked.
                // ============================================================
                array(
                    'id'          => 'shipping_section',
                    'title'       => __('Shipping Information', 'rentopian-sync'),
                    'description' => __('Enter shipping address if different from billing.', 'rentopian-sync'),
                    'class'       => 'rentopian-shipping-section',
                    'rows'        => array(
                        
                        // ----------------------------------------------------
                        // ROW: Ship to Different Address Checkbox
                        // Fields: ship_to_different_address
                        // Layout: 100% (full width)
                        // Note: This checkbox controls visibility of all
                        // shipping fields below via depends_on property
                        // ----------------------------------------------------
                        array(
                            'id'      => 'row_ship_to_different',
                            'class'   => '',
                            'columns' => array(
                                array(
                                    'width'         => 12,
                                    'field'         => 'ship_to_different_address',
                                    'class'         => '',
                                    'active'        => 1,
                                    'hidden_visual' => 0,
                                    'depends_on'    => '',
                                ),
                            ),
                        ),
                        
                        // ----------------------------------------------------
                        // ROW: Shipping Name
                        // Fields: shipping_first_name, shipping_last_name
                        // Layout: 50% + 50% (two columns)
                        // Depends on: ship_to_different_address checkbox
                        // ----------------------------------------------------
                        array(
                            'id'      => 'row_shipping_name',
                            'class'   => '',
                            'columns' => array(
                                array(
                                    'width'         => 6,
                                    'field'         => 'shipping_first_name',
                                    'class'         => '',
                                    'active'        => 1,
                                    'hidden_visual' => 0,
                                    'depends_on'    => 'ship_to_different_address',
                                ),
                                array(
                                    'width'         => 6,
                                    'field'         => 'shipping_last_name',
                                    'class'         => '',
                                    'active'        => 1,
                                    'hidden_visual' => 0,
                                    'depends_on'    => 'ship_to_different_address',
                                ),
                            ),
                        ),
                        
                        // ----------------------------------------------------
                        // ROW: Shipping Address
                        // Fields: shipping_address_1
                        // Layout: 100% (full width)
                        // Depends on: ship_to_different_address checkbox
                        // ----------------------------------------------------
                        array(
                            'id'      => 'row_shipping_address',
                            'class'   => '',
                            'columns' => array(
                                array(
                                    'width'         => 12,
                                    'field'         => 'shipping_address_1',
                                    'class'         => '',
                                    'active'        => 1,
                                    'hidden_visual' => 0,
                                    'depends_on'    => 'ship_to_different_address',
                                ),
                            ),
                        ),
                        
                        // ----------------------------------------------------
                        // ROW: Shipping City, State, ZIP
                        // Fields: shipping_city, shipping_state, shipping_postcode
                        // Layout: 40% + 30% + 30% (three columns)
                        // Depends on: ship_to_different_address checkbox
                        // ----------------------------------------------------
                        array(
                            'id'      => 'row_shipping_location',
                            'class'   => '',
                            'columns' => array(
                                array(
                                    'width'         => 5,
                                    'field'         => 'shipping_city',
                                    'class'         => '',
                                    'active'        => 1,
                                    'hidden_visual' => 0,
                                    'depends_on'    => 'ship_to_different_address',
                                ),
                                array(
                                    'width'         => 4,
                                    'field'         => 'shipping_state',
                                    'class'         => '',
                                    'active'        => 1,
                                    'hidden_visual' => 0,
                                    'depends_on'    => 'ship_to_different_address',
                                ),
                                array(
                                    'width'         => 3,
                                    'field'         => 'shipping_postcode',
                                    'class'         => '',
                                    'active'        => 1,
                                    'hidden_visual' => 0,
                                    'depends_on'    => 'ship_to_different_address',
                                ),
                            ),
                        ),
                    ),
                ),
                
                // ============================================================
                // SECTION 4: DYNAMIC FIELDS (RENTOPIAN API)
                // This section contains fields fetched from the Rentopian API.
                // Dynamic fields are automatically appended when they exist in
                // the rental_custom_fields option (fetched via API).
                //
                // Field ID format: rental_custom_{slug}
                // Example: rental_custom_outdoor, rental_custom_2-hour-delivery-window
                //
                // To refresh dynamic fields:
                // 1. Click "Refresh Custom Fields from Rentopian" button
                // 2. Reset to default or reload to see updated fields
                //
                // Admin can re-arrange these fields by editing the JSON layout
                // ============================================================
                array(
                    'id'          => 'dynamic_fields_section',
                    'title'       => __('Additional Event Details', 'rentopian-sync'),
                    'description' => __('Custom fields from your Rentopian account.', 'rentopian-sync'),
                    'class'       => 'rentopian-dynamic-section',
                    'rows'        => $this->get_dynamic_fields_rows(),
                ),
                // ============================================================
                // SECTION 5: Photo upload
                // ============================================================
                array(
                    'id'          => 'photo_section',
                    'title'       => __('Upload Inspo Photos', 'rentopian-sync'),
                    'description' => __('Please upload inspirational photos related to your event.', 'rentopian-sync'),
                    'class'       => 'rentopian-photo-upload',
                    'rows'        => array(
                        // ----------------------------------------------------
                        // ROW: Photo Upload
                        // Fields: rental_photo_upload
                        // Layout: 100% (full width)
                        // Note: photo upload - conditional on setting
                        // ----------------------------------------------------
                        array(
                            'id'      => 'row_photo_upload',
                            'class'   => '',
                            'columns' => array(
                                array(
                                    'width'         => 12,
                                    'field'         => 'rental_photo_upload',
                                    'class'         => '',
                                    'active'        => 1,
                                    'hidden_visual' => 0,
                                    'depends_on'    => '',
                                ),
                            ),
                        ),
                    ),
                ),
            ),
        );
    }

    /**
     * Get rows for dynamic fields from Rentopian API
     *
     * Fetches custom fields from the rental_custom_fields option and generates
     * layout rows for each field. Each field gets its own row with full width.
     * Admin can later rearrange them by editing the JSON layout.
     *
     * @return array Array of row configurations for dynamic fields
     */
    private function get_dynamic_fields_rows() {
        $rows = array();
        
        // Get custom fields from Rentopian API (stored in option)
        $custom_fields = get_option('rental_custom_fields', array());
        
        if (empty($custom_fields) || !is_array($custom_fields)) {
            return $rows;
        }
        
        $field_index = 0;
        
        // Handle the grouped structure from Rentopian API
        // The API returns groups, where each group contains an array of fields
        foreach ($custom_fields as $group) {
            if (!is_array($group)) {
                continue;
            }
            
            foreach ($group as $field_data) {
                if (!is_array($field_data)) {
                    continue;
                }
                
                // Get field identifiers - same logic as register_dynamic_rental_fields()
                $field_id_raw = isset($field_data['id']) ? $field_data['id'] : null;
                $field_slug = isset($field_data['slug']) ? $field_data['slug'] : null;
                
                if (!$field_id_raw && !$field_slug) {
                    continue;
                }
                
                // Build field ID matching the registry format
                $field_id = 'rental_custom_' . ($field_slug ? sanitize_key($field_slug) : $field_id_raw);
                $field_title = isset($field_data['title']) ? $field_data['title'] : $field_slug;
                $is_required = isset($field_data['required']) ? (bool) $field_data['required'] : false;
                
                // Create a row for this dynamic field
                // Using full width (12 cols) by default - admin can adjust later
                $rows[] = array(
                    'id'      => 'row_dynamic_' . $field_index,
                    'class'   => '',
                    'columns' => array(
                        array(
                            'width'         => 12,
                            'field'         => $field_id,
                            'class'         => '',
                            'active'        => 1,
                            'hidden_visual' => 0,
                            'depends_on'    => '',
                        ),
                    ),
                );
                
                $field_index++;
            }
        }
        
        return $rows;
    }

    /**
     * Reset configuration to default
     *
     * Deletes the stored configuration and clears cache.
     * Next call to get_layout() will return the default.
     *
     * @return array The default layout configuration
     */
    public function reset_to_default() {
        delete_option(self::OPTION_KEY);
        $this->config_cache = null;

        do_action('rental_checkout_layout_reset');

        return $this->get_default_layout();
    }

    /**
     * Check if custom checkout layout is enabled
     *
     * The layout is enabled when all three conditions are met:
     * - rental_dates_on_checkout is enabled (1)
     * - rental_allow_overbook is enabled (1)
     * - rental_checkout_layout_mode is set to 'modern'
     *
     * @return bool True if enabled, false otherwise
     */
    public function is_enabled() {
        $dates_on_checkout = get_option('rental_dates_on_checkout', 0) == 1;
        $allow_overbook = get_option('rental_allow_overbook', 1) == 1;
        $modern_mode = get_option('rental_checkout_layout_mode', 'classic') === 'modern';
        
        return $dates_on_checkout && $allow_overbook && $modern_mode;
    }

    /**
     * Get the requirements status for enabling checkout layout
     *
     * Returns an array with each requirement and its current status.
     * Useful for displaying which settings need to be enabled.
     *
     * @return array
     */
    public function get_requirements_status() {
        return array(
            'dates_on_checkout' => array(
                'label'    => __('Show Dates Form on Checkout', 'rentopian-sync'),
                'enabled'  => get_option('rental_dates_on_checkout', 0) == 1,
                'setting'  => 'rental_dates_on_checkout',
            ),
            'allow_overbook' => array(
                'label'    => __('Allow Overbooking', 'rentopian-sync'),
                'enabled'  => get_option('rental_allow_overbook', 1) == 1,
                'setting'  => 'rental_allow_overbook',
            ),
            'modern_mode' => array(
                'label'    => __('Modern Checkout Layout Mode', 'rentopian-sync'),
                'enabled'  => get_option('rental_checkout_layout_mode', 'classic') === 'modern',
                'setting'  => 'rental_checkout_layout_mode',
            ),
        );
    }

    /**
     * Get list of mandatory fields that must always be active
     *
     * These are WooCommerce required fields that cannot be disabled.
     * Even if set to inactive in config, they will always be rendered.
     *
     * @return array
     */
    /**
     * System-mandatory fields that can NEVER be set to not-required.
     * These are essential for order creation and API sync.
     *
     * Matches: start_date, firstname, lastname, email, address, country, city
     */
    public function get_mandatory_fields() {
        // Mirrors the Rentopian API's required order fields (firstname, lastname, email,
        // address, country, city, zip) plus state and the rental date component. These
        // can never be removed or set inactive; they are locked in the layout builder.
        return array(
            'billing_first_name',
            'billing_last_name',
            'billing_email',
            'billing_address_1',
            'billing_country',
            'billing_city',
            'billing_state',
            'billing_postcode',
            // Shipping country behaves like billing country: locked, read-only, store-filled.
            // Only enforced when "ship to a different address" is chosen.
            'shipping_country',
            'rental_date_form_modern',
        );
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
     * Check if a field is mandatory (cannot be disabled)
     *
     * @param string $field_id The field ID to check
     * @return bool
     */
    public function is_mandatory_field($field_id) {
        return in_array($field_id, $this->get_mandatory_fields(), true);
    }

    /**
     * Determine the effective "required" state for a field based on JSON layout.
     *
     * Priority:
     * 1. If field is in mandatory list → always true (layout cannot override)
     * 2. If layout column has explicit "required" key → use that value
     * 3. Fallback to registry default
     *
     * @param string     $field_id Field identifier
     * @param array|null $layout   Layout config (null = use current saved layout)
     * @return bool|null True/false if layout specifies, null if no override (use registry default)
     */
    public function get_field_required_from_layout($field_id, $layout = null) {
        // Mandatory fields are always required regardless of layout
        if ($this->is_mandatory_field($field_id)) {
            return true;
        }

        if (null === $layout) {
            $layout = $this->get_layout();
        }

        if (empty($layout['sections']) || !is_array($layout['sections'])) {
            return null; // no layout, use registry default
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
                    if (!isset($column['field']) || $column['field'] !== $field_id) {
                        continue;
                    }
                    // Found the field in layout. Check for explicit "required" key.
                    if (array_key_exists('required', $column)) {
                        return (bool) $column['required'];
                    }
                    // Field is in layout but no explicit "required" key → null (use registry default)
                    return null;
                }
            }
        }

        return null; // field not in layout
    }

    /**
     * Check if a column should be visually hidden
     *
     * hidden_visual columns are rendered in the DOM but not displayed.
     * They are used for fields that need to exist for form submission
     * but are filled programmatically (e.g., by Google Places autocomplete).
     *
     * @param array $column The column configuration
     * @return bool
     */
    public function is_hidden_visual($column) {
        return !empty($column['hidden_visual']) && $column['hidden_visual'] == 1;
    }

    /**
     * Get the parent field ID that a column depends on
     *
     * Used for establishing relationships between fields, such as:
     * - Multi-day event checkbox depending on date picker
     * - Outdoor checkbox depending on venue/address field
     * - City/state/zip depending on street address (for autocomplete)
     *
     * @param array $column The column configuration
     * @return string|null Parent field ID or null if no dependency
     */
    public function get_column_dependency($column) {
        if (empty($column['depends_on'])) {
            return null;
        }
        return $column['depends_on'];
    }

    /**
     * Find all columns that depend on a specific field
     *
     * Useful for determining which fields to show/hide when a parent
     * field is toggled or moved.
     *
     * @param string $field_id The parent field ID
     * @return array Array of dependent columns with their section/row context
     */
    public function get_dependent_columns($field_id) {
        $layout = $this->get_layout();
        $dependents = array();
        
        if (empty($layout['sections'])) {
            return $dependents;
        }
        
        foreach ($layout['sections'] as $section_idx => $section) {
            if (empty($section['rows'])) {
                continue;
            }
            
            foreach ($section['rows'] as $row_idx => $row) {
                if (empty($row['columns'])) {
                    continue;
                }
                
                foreach ($row['columns'] as $col_idx => $column) {
                    if (!empty($column['depends_on']) && $column['depends_on'] === $field_id) {
                        $dependents[] = array(
                            'section_index' => $section_idx,
                            'row_index'     => $row_idx,
                            'column_index'  => $col_idx,
                            'column'        => $column,
                            'field'         => isset($column['field']) ? $column['field'] : null,
                        );
                    }
                }
            }
        }
        
        return $dependents;
    }

    /**
     * Get all hidden visual fields from the layout
     *
     * Returns an array of field IDs that are marked as hidden_visual.
     * Useful for applying CSS hiding and for autocomplete setup.
     *
     * @return array Array of field IDs
     */
    public function get_hidden_visual_fields() {
        $layout = $this->get_layout();
        $hidden_fields = array();
        
        if (empty($layout['sections'])) {
            return $hidden_fields;
        }
        
        foreach ($layout['sections'] as $section) {
            if (empty($section['rows'])) {
                continue;
            }
            
            foreach ($section['rows'] as $row) {
                if (empty($row['columns'])) {
                    continue;
                }
                
                foreach ($row['columns'] as $column) {
                    if ($this->is_hidden_visual($column) && !empty($column['field'])) {
                        $hidden_fields[] = $column['field'];
                    }
                }
            }
        }
        
        return array_unique($hidden_fields);
    }

    /**
     * Get the dependency tree for all fields
     *
     * Returns a map of parent_field_id => array of child field IDs.
     * Useful for understanding the complete dependency structure.
     *
     * @return array Dependency tree
     */
    public function get_dependency_tree() {
        $layout = $this->get_layout();
        $tree = array();
        
        if (empty($layout['sections'])) {
            return $tree;
        }
        
        foreach ($layout['sections'] as $section) {
            if (empty($section['rows'])) {
                continue;
            }
            
            foreach ($section['rows'] as $row) {
                if (empty($row['columns'])) {
                    continue;
                }
                
                foreach ($row['columns'] as $column) {
                    if (!empty($column['depends_on']) && !empty($column['field'])) {
                        $parent = $column['depends_on'];
                        $child = $column['field'];
                        
                        if (!isset($tree[$parent])) {
                            $tree[$parent] = array();
                        }
                        
                        $tree[$parent][] = $child;
                    }
                }
            }
        }
        
        return $tree;
    }

    /**
     * Enable or disable custom checkout layout (deprecated)
     *
     * Note: This method is kept for backward compatibility but the
     * enabled state is now determined by the three settings:
     * dates_on_checkout, allow_overbook, and checkout_layout_mode.
     *
     * @deprecated 2.13.0 Use Rentopian Settings page instead
     * @param bool $enabled Whether to enable the custom layout
     * @return bool True on success
     */
    public function set_enabled($enabled) {
        // Deprecated - enabled state is now determined by settings
        _deprecated_function(__METHOD__, '2.13.0', 'Rentopian Settings page');
        return false;
    }

    /**
     * Export layout configuration as JSON string
     *
     * @return string JSON encoded configuration
     */
    public function export_layout() {
        $config = $this->get_layout();
        return wp_json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Import layout configuration from JSON string
     *
     * Parses JSON and validates before saving.
     *
     * @param string $json The JSON string to import
     * @return array|WP_Error Success array or WP_Error on failure
     */
    public function import_layout($json) {
        // Decode JSON
        $config = json_decode($json, true);

        // Check for JSON errors
        if (json_last_error() !== JSON_ERROR_NONE) {
            return new WP_Error(
                'invalid_json',
                sprintf(
                    __('Invalid JSON format: %s', 'rentopian-sync'),
                    json_last_error_msg()
                )
            );
        }

        // Validate and save
        return $this->save_layout($config);
    }

    /**
     * Get all field IDs used in current configuration
     *
     * Useful for determining which fields are in use.
     *
     * @return array List of field IDs
     */
    public function get_used_field_ids() {
        $config = $this->get_layout();
        $field_ids = array();

        if (!isset($config['sections'])) {
            return $field_ids;
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
                        $field_ids[] = $column['field'];
                    }
                }
            }
        }

        return array_unique($field_ids);
    }

    /**
     * Check if a specific field is used in the layout
     *
     * @param string $field_id The field ID to check
     * @return bool True if field is used
     */
    public function is_field_used($field_id) {
        return in_array($field_id, $this->get_used_field_ids(), true);
    }

    /**
     * Get configuration for a specific section
     *
     * @param string $section_id The section ID to retrieve
     * @return array|null Section configuration or null if not found
     */
    public function get_section($section_id) {
        $config = $this->get_layout();

        if (!isset($config['sections'])) {
            return null;
        }

        foreach ($config['sections'] as $section) {
            if (isset($section['id']) && $section['id'] === $section_id) {
                return $section;
            }
        }

        return null;
    }
}
