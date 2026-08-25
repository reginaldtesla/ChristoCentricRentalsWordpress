<?php
/**
 * Rental Product Options Manager
 * 
 * A comprehensive, theme-independent system for managing product and set options
 * across WooCommerce cart, mini-cart, checkout, orders, and emails.
 * 
 * This class follows WooCommerce best practices by:
 * 1. Storing options data directly in cart item data (not just sessions)
 * 2. Using standard WooCommerce hooks for displaying options
 * 3. Persisting options to order item meta for order history
 * 4. Providing consistent formatting across all touchpoints
 * 
 * @package Rentopian_Sync
 * @since 2.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class Rental_Product_Options_Manager {

    /**
     * Instance of this class.
     * @var Rental_Product_Options_Manager
     */
    private static $instance = null;

    /**
     * Meta key for storing options in cart item data
     */
    const CART_ITEM_OPTIONS_KEY = 'rental_selected_options';
    
    /**
     * Meta key for storing set options in cart item data
     */
    const CART_ITEM_SET_OPTIONS_KEY = 'rental_selected_set_options';

    /**
     * Meta key for order item meta
     */
    const ORDER_ITEM_OPTIONS_KEY = '_rental_product_options';
    
    /**
     * Session key for temporary options storage (before add to cart)
     */
    const SESSION_OPTIONS_KEY = 'rental_product_options_valuables';

    /**
     * Flag: true when we are currently rendering items INSIDE the cart table.
     * Used to distinguish cart-table rendering from mini-cart widget rendering,
     * even when both happen on the same page (is_cart() is true for both).
     */
    private static $is_rendering_cart_table = false;

    /**
     * Get the singleton instance
     * @return Rental_Product_Options_Manager
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor - Register all hooks
     */
    private function __construct() {
        // PRE-add hook: Remove existing items BEFORE WooCommerce adds new one (like add-on pattern)
        add_filter('woocommerce_add_to_cart_validation', [$this, 'pre_add_remove_existing_item'], 99, 4);
        
        // POST-add hook: Update quantity after WooCommerce adds item
        add_action('woocommerce_add_to_cart', [$this, 'post_add_restore_quantity'], 99, 6);
        
        // Cart item data hooks
        add_filter('woocommerce_add_cart_item_data', [$this, 'add_options_to_cart_item_data'], 25, 4);
        add_filter('woocommerce_get_cart_item_from_session', [$this, 'restore_options_from_session'], 25, 3);
        
        // Display options in cart, mini-cart, and checkout
        add_filter('woocommerce_get_item_data', [$this, 'display_options_in_cart'], 25, 2);
        
        // Update cart item when options change via AJAX
        add_action('wp_ajax_rental_update_cart_item_options', [$this, 'ajax_update_cart_item_options']);
        add_action('wp_ajax_nopriv_rental_update_cart_item_options', [$this, 'ajax_update_cart_item_options']);
        
        // Save options to order item meta
        add_action('woocommerce_checkout_create_order_line_item', [$this, 'save_options_to_order_item'], 25, 4);
        
        // Display options in order details (admin, customer account, emails)
        add_filter('woocommerce_order_item_get_formatted_meta_data', [$this, 'format_order_item_options'], 25, 2);
        
        // DISABLED: display_options_in_order_item causes duplicate display
        // Options are displayed via woocommerce_get_item_data which generates <dl class="variation">
        // add_action('woocommerce_order_item_meta_start', [$this, 'display_options_in_order_item'], 25, 3);
        
        // DISABLED: apply_options_pricing causes DOUBLE option pricing.
        // calculate_cart_totals() (hooked at priority 10) already adds option prices to the base price.
        // apply_options_pricing then reads the ALREADY-INCREASED price and adds options AGAIN.
        // Example: base=$4000, options=$100 → calculate_cart_totals sets $4100 → apply_options_pricing adds $100 again → $4200 (WRONG)
        // add_action('woocommerce_before_calculate_totals', [$this, 'apply_options_pricing'], 25, 1);
        
        // Mini-cart AJAX fragment
        add_filter('woocommerce_add_to_cart_fragments', [$this, 'update_mini_cart_fragment'], 25);
        
        // Cart table rendering flag — distinguishes cart table items from mini-cart widget items
        // even when both render on the same page (where is_cart() is true for everything).
        add_action('woocommerce_before_cart_contents', function() {
            self::$is_rendering_cart_table = true;
        });
        add_action('woocommerce_after_cart_contents', function() {
            self::$is_rendering_cart_table = false;
        });
        
        // AJAX handlers for getting options
        add_action('wp_ajax_rental_get_cart_item_options_html', [$this, 'ajax_get_cart_item_options_html']);
        add_action('wp_ajax_nopriv_rental_get_cart_item_options_html', [$this, 'ajax_get_cart_item_options_html']);
        
        // Cart update handling
        add_action('woocommerce_cart_item_restored', [$this, 'handle_cart_item_restored'], 25, 2);
    }

    /**
     * Temporary storage for quantity to restore after removing existing items
     * @var array
     */
    private static $pending_qty_restore = [];

    /**
     * PRE-add hook: Remove existing items BEFORE WooCommerce adds new one.
     * This follows the same pattern as rental_add_add_on_to_cart.
     * 
     * @param bool $passed Whether validation passed
     * @param int $product_id Product ID
     * @param int $quantity Quantity being added
     * @param int $variation_id Variation ID (optional)
     * @return bool
     */
    public function pre_add_remove_existing_item($passed, $product_id, $quantity, $variation_id = 0) {
        if (!$passed) {
            return $passed;
        }
        
        $actual_product_id = $variation_id ? $variation_id : $product_id;
        
        // Skip if this is an add-on
        if (isset($_POST['rental_add_on_of']) || isset($_REQUEST['rental_add_on_of'])) {
            return $passed;
        }
        
        $cart = WC()->cart;
        if (!$cart) {
            return $passed;
        }
        
        // Find existing items for this product (excluding add-ons)
        $existing_qty = 0;
        $keys_to_remove = [];
        foreach ($cart->get_cart() as $cart_key => $cart_item) {
            // Skip add-ons
            if (isset($cart_item['rental_add_on_of']) && $cart_item['rental_add_on_of']) {
                continue;
            }
            
            $item_product_id = isset($cart_item['variation_id']) && $cart_item['variation_id'] 
                ? $cart_item['variation_id'] 
                : $cart_item['product_id'];
            
            if ((int) $item_product_id === (int) $actual_product_id) {
                $existing_qty += $cart_item['quantity'];
                $keys_to_remove[] = $cart_key;
            }
        }
        
        if (!empty($keys_to_remove)) {
            // Store the quantity to restore later
            self::$pending_qty_restore[$actual_product_id] = $existing_qty;
            
            // Remove existing items (WooCommerce will add the new one with updated options)
            foreach ($keys_to_remove as $key) {
                $cart->remove_cart_item($key);
            }
        }
        
        return $passed;
    }

    /**
     * POST-add hook: Restore quantity after WooCommerce adds item.
     * 
     * @param string $cart_item_key Cart item key
     * @param int $product_id Product ID
     * @param int $quantity Quantity added
     * @param int $variation_id Variation ID (optional)
     * @param array $variation Variation attributes (optional)
     * @param array $cart_item_data Cart item data (optional)
     */
    public function post_add_restore_quantity($cart_item_key, $product_id, $quantity, $variation_id = 0, $variation = [], $cart_item_data = []) {
        $actual_product_id = $variation_id ? $variation_id : $product_id;
        
        // Skip if this is an add-on
        if (isset($cart_item_data['rental_add_on_of']) && $cart_item_data['rental_add_on_of']) {
            return;
        }
        
        // Check if we have pending quantity to restore
        if (!isset(self::$pending_qty_restore[$actual_product_id])) {
            return;
        }
        
        $existing_qty = self::$pending_qty_restore[$actual_product_id];
        unset(self::$pending_qty_restore[$actual_product_id]);
        
        $cart = WC()->cart;
        if (!$cart || !isset($cart->cart_contents[$cart_item_key])) {
            return;
        }
        
        // Add the existing quantity to the new item
        $new_total_qty = $quantity + $existing_qty;
        $cart->cart_contents[$cart_item_key]['quantity'] = $new_total_qty;
        
        // CRITICAL: Save the cart to session to persist the quantity change
        // Without this, WooCommerce may reload from session and lose our change
        $cart->set_session();
        
    }

    /**
     * Handle cart item after add - update options and merge duplicates.
     * This handles TWO scenarios:
     * 1. WooCommerce creates a NEW item → merge with existing same-product items
     * 2. WooCommerce merges with existing item (same cart_id) → update options from session
     * 
     * @param string $cart_item_key The cart item key (could be new or existing)
     * @param int $product_id Product ID
     * @param int $quantity Quantity added
     * @param int $variation_id Variation ID (optional)
     * @param array $variation Variation attributes (optional)
     * @param array $cart_item_data Cart item data (optional)
     */
    public function merge_duplicate_cart_items($cart_item_key, $product_id, $quantity, $variation_id = 0, $variation = [], $cart_item_data = []) {
        $actual_product_id = $variation_id ? $variation_id : $product_id;
        
        $cart = WC()->cart;
        if (!$cart) {
            return;
        }
        
        // Skip if this is an add-on (add-ons have their own merge logic)
        if (isset($cart_item_data['rental_add_on_of']) && $cart_item_data['rental_add_on_of']) {
            return;
        }
        
        // Get the cart item
        if (!isset($cart->cart_contents[$cart_item_key])) {
            return;
        }
        
        $cart_item = &$cart->cart_contents[$cart_item_key];
        
        // Determine if this is a set
        $is_set = get_post_meta($actual_product_id, '_rental_is_set', true);
        $options_key = $is_set ? self::CART_ITEM_SET_OPTIONS_KEY : self::CART_ITEM_OPTIONS_KEY;
        
        // ALWAYS update options from current session (this handles WooCommerce native merge)
        $latest_options = $this->get_latest_options_from_session($actual_product_id, (bool) $is_set);
        
        if (!empty($latest_options)) {
            $cart_item[$options_key] = $latest_options;
            $cart_item['rental_is_set'] = $is_set ? 1 : 0;
        }
        
        // Now handle duplicate merging (find other items for same product)
        $cart_contents = $cart->get_cart();
        $duplicates = [];
        
        foreach ($cart_contents as $key => $item) {
            // Skip add-ons
            if (isset($item['rental_add_on_of']) && $item['rental_add_on_of']) {
                continue;
            }
            
            $item_product_id = isset($item['variation_id']) && $item['variation_id'] 
                ? $item['variation_id'] 
                : $item['product_id'];
            
            if ((int) $item_product_id === (int) $actual_product_id) {
                $duplicates[$key] = $item;
            }
        }
        
        // If only one item, no merge needed (but options are already updated above)
        if (count($duplicates) <= 1) {
            return;
        }
        
        // Merge all duplicates: sum quantities, remove old items, keep current
        $total_quantity = 0;
        $keys_to_remove = [];
        
        foreach ($duplicates as $key => $item) {
            $total_quantity += $item['quantity'];
            if ($key !== $cart_item_key) {
                $keys_to_remove[] = $key;
            }
        }
        
        // Remove old duplicate items
        foreach ($keys_to_remove as $key) {
            $cart->remove_cart_item($key);
        }
        
        // Update quantity on the kept item
        if (isset($cart->cart_contents[$cart_item_key])) {
            $cart->cart_contents[$cart_item_key]['quantity'] = $total_quantity;
        }
        
        $cart->calculate_totals();
        
    }
    
    /**
     * Get latest options from session for a product
     * 
     * @param int $product_id Product ID
     * @param bool $is_set Whether this is a set product
     * @return array Selected options with full data
     */
    private function get_latest_options_from_session($product_id, $is_set = false) {
        // Get options structure
        if ($is_set) {
            $options = $this->get_set_options_with_defaults($product_id);
            $session_name = $product_id . "_selected_options_of_set";
        } else {
            $options = $this->get_product_options_with_defaults($product_id);
            $session_name = $product_id . "_selected_options";
        }
        
        if (empty($options)) {
            return [];
        }
        
        $session_options = get_rental_session_data(self::SESSION_OPTIONS_KEY, []);
        $selected_session_options = get_rental_session_data($session_name, []);
        
        $selected_options = [];
        
        foreach ($options as $option) {
            $option_id = $option['id'];
            $selected_value = null;
            
            // Check session data (primary source - most recent user selection)
            if (isset($selected_session_options[$option_id])) {
                $selected_value = $selected_session_options[$option_id];
            } elseif (isset($session_options[$product_id][$option_id])) {
                $selected_value = $session_options[$product_id][$option_id];
            } else {
                // Use effective default value (explicit default OR sole option value)
                $default_value = $this->get_effective_default_value($option);
                if ($default_value) {
                    $selected_value = [
                        'value_id' => $default_value['id'],
                        'value_title' => $default_value['title'],
                        'price' => floatval($default_value['price']),
                        'option_title' => $option['title'],
                        'option_id' => $option_id
                    ];
                }
            }
            
            if ($selected_value) {
                // Normalize the data structure
                $value_id = isset($selected_value['value_id']) ? $selected_value['value_id'] : 
                           (isset($selected_value['selected_value_id']) ? $selected_value['selected_value_id'] : null);
                
                if ($value_id !== null) {
                    // Ensure we have complete data
                    if (!isset($selected_value['value_id'])) {
                        $selected_value['value_id'] = $value_id;
                    }
                    if (!isset($selected_value['option_title'])) {
                        $selected_value['option_title'] = $option['title'];
                    }
                    if (!isset($selected_value['option_id'])) {
                        $selected_value['option_id'] = $option_id;
                    }
                    // Get value title if not set
                    if (!isset($selected_value['value_title']) && !empty($option['option_values'])) {
                        foreach ($option['option_values'] as $value) {
                            if ((int) $value['id'] === (int) $value_id) {
                                $selected_value['value_title'] = $value['title'];
                                $selected_value['price'] = floatval($value['price']);
                                break;
                            }
                        }
                    }
                    $selected_options[$option_id] = $selected_value;
                }
            }
        }

        if ( function_exists( 'rental_options_trace' ) ) {
            $rntp_resolved = array();
            foreach ( $selected_options as $oid => $sv ) {
                $rntp_resolved[ $oid ] = isset( $sv['value_id'] ) ? (int) $sv['value_id'] : ( isset( $sv['selected_value_id'] ) ? (int) $sv['selected_value_id'] : 0 );
            }
            rental_options_trace( 'read_session', array(
                'pid'      => $product_id,
                'is_set'   => $is_set ? 1 : 0,
                'sess_key' => $session_name,
                'resolved' => $rntp_resolved,
                'has_per_opt_session'  => empty( $selected_session_options ) ? 0 : 1,
                'has_valuables'        => isset( $session_options[ $product_id ] ) ? 1 : 0,
            ) );
        }

        return $selected_options;
    }

    /**
     * Add selected options to cart item data when adding product to cart
     * 
     * @param array $cart_item_data Cart item data
     * @param int $product_id Product ID
     * @param int $variation_id Variation ID
     * @param int $quantity Quantity
     * @return array Modified cart item data
     */
    public function add_options_to_cart_item_data($cart_item_data, $product_id, $variation_id, $quantity) {
        $actual_product_id = $variation_id ? $variation_id : $product_id;
        if (function_exists('rental_options_trace')) {
            rental_options_trace('snapshot_entry', array('pid'=>$actual_product_id,'is_set'=>get_post_meta($actual_product_id,'_rental_is_set',true)?1:0));
        }
        
        // Check if this is a set
        $is_set = get_post_meta($actual_product_id, '_rental_is_set', true);
        
        // Get options from session (set before add to cart)
        $session_options = get_rental_session_data(self::SESSION_OPTIONS_KEY, []);
        
        if ($is_set) {
            $options = $this->get_set_options_with_defaults($actual_product_id);
            $session_name = $actual_product_id . "_selected_options_of_set";
        } else {
            $options = $this->get_product_options_with_defaults($actual_product_id);
            $session_name = $actual_product_id . "_selected_options";
        }
        
        if (!empty($options)) {
            $selected_options = [];
            $selected_session_options = get_rental_session_data($session_name, []);
            
            foreach ($options as $option) {
                $option_id = $option['id'];
                $selected_value = null;
                
                // Check if there's a selected value in session
                if (isset($selected_session_options[$option_id])) {
                    $selected_value = $selected_session_options[$option_id];
                } elseif (isset($session_options[$actual_product_id][$option_id])) {
                    $selected_value = $session_options[$actual_product_id][$option_id];
                } else {
                    // Use effective default value (explicit default OR sole option value)
                    $default_value = $this->get_effective_default_value($option);
                    if ($default_value) {
                        $selected_value = [
                            'value_id' => $default_value['id'],
                            'value_title' => $default_value['title'],
                            'price' => floatval($default_value['price']),
                            'option_title' => $option['title'],
                            'option_id' => $option_id
                        ];
                    }
                }
                
                if ($selected_value) {
                    // Ensure we have all required data (session may use selected_value_id, we normalize to value_id)

                    $value_id = isset($selected_value['value_id']) ? $selected_value['value_id'] : (isset($selected_value['selected_value_id']) ? $selected_value['selected_value_id'] : null);
                    if ($value_id !== null && !isset($selected_value['value_id'])) {
                        $selected_value['value_id'] = $value_id;
                    }
                    if (!isset($selected_value['option_title'])) {
                        $selected_value['option_title'] = $option['title'];
                    }
                    if (!isset($selected_value['option_id'])) {
                        $selected_value['option_id'] = $option_id;
                    }

                    // Get value title if not set
                    if (!isset($selected_value['value_title']) && $value_id !== null && !empty($option['option_values'])) {
                        foreach ($option['option_values'] as $value) {
                            if ((int) $value['id'] === (int) $value_id) {
                                $selected_value['value_title'] = $value['title'];
                                break;
                            }
                        }
                    }

                    $selected_options[$option_id] = $selected_value;
                }
            }
            
            if (!empty($selected_options)) {

                $options_key = $is_set ? self::CART_ITEM_SET_OPTIONS_KEY : self::CART_ITEM_OPTIONS_KEY;

                $cart_item_data[$options_key] = $selected_options;
                $cart_item_data['rental_is_set'] = $is_set ? 1 : 0;
            }

            if ( function_exists( 'rental_options_trace' ) ) {
                $rntp_sess = array();
                foreach ( (array) $selected_session_options as $oid => $sv ) {
                    $rntp_sess[ $oid ] = is_array( $sv ) ? ( isset( $sv['value_id'] ) ? (int) $sv['value_id'] : ( isset( $sv['selected_value_id'] ) ? (int) $sv['selected_value_id'] : 0 ) ) : (int) $sv;
                }
                $rntp_snap = array();
                foreach ( (array) $selected_options as $oid => $sv ) {
                    $rntp_snap[ $oid ] = is_array( $sv ) ? ( isset( $sv['value_id'] ) ? (int) $sv['value_id'] : ( isset( $sv['selected_value_id'] ) ? (int) $sv['selected_value_id'] : 0 ) ) : (int) $sv;
                }
                rental_options_trace( 'snapshot_at_add', array(
                    'pid'       => $actual_product_id,
                    'is_set'    => $is_set ? 1 : 0,
                    'sess_key'  => $session_name,
                    'from_session' => $rntp_sess,
                    'snapshot'  => $rntp_snap,
                ) );
            }

        }

        return $cart_item_data;
    }

    /**
     * Restore options from session after cart is loaded
     * 
     * @param array $cart_item Cart item data
     * @param array $values Session values
     * @param string $key Cart item key
     * @return array Modified cart item
     */
    public function restore_options_from_session($cart_item, $values, $key) {

        if (isset($values[self::CART_ITEM_OPTIONS_KEY])) {
            $cart_item[self::CART_ITEM_OPTIONS_KEY] = $values[self::CART_ITEM_OPTIONS_KEY];
        }

        if (isset($values[self::CART_ITEM_SET_OPTIONS_KEY])) {
            $cart_item[self::CART_ITEM_SET_OPTIONS_KEY] = $values[self::CART_ITEM_SET_OPTIONS_KEY];
        }

        if (isset($values['rental_is_set'])) {
            $cart_item['rental_is_set'] = $values['rental_is_set'];
        }
        return $cart_item;
    }

    /**
     * Display options in cart, mini-cart, and checkout
     * Uses WooCommerce's standard woocommerce_get_item_data filter for theme compatibility
     * 
     * @param array $item_data Existing item data
     * @param array $cart_item Cart item
     * @return array Modified item data
     */
    public function display_options_in_cart($item_data, $cart_item) {
        // Rentpro/Eventorian themes have a dedicated Options column on the cart TABLE.
        // That column is filled by JavaScript (AJAX) in rental-product-options-script.js.
        // We must NOT add options to item_data when rendering the cart TABLE, or they'll
        // appear TWICE: once under the product name (via <dl class="variation">) and once
        // in the Options column.
        //
        // IMPORTANT: We use `$is_rendering_cart_table` flag (set by woocommerce_before_cart_contents
        // / woocommerce_after_cart_contents hooks) instead of `is_cart()`, because is_cart()
        // returns true for the ENTIRE page — including the mini-cart widget in the header.
        // The mini-cart widget MUST still show read-only options.
        if (function_exists('rental_uses_rentpro_or_eventorian_options_column') && rental_uses_rentpro_or_eventorian_options_column()) {
            if (self::$is_rendering_cart_table) {
                return $item_data;
            }
        }
        $options_key = !empty($cart_item['rental_is_set']) ? self::CART_ITEM_SET_OPTIONS_KEY : self::CART_ITEM_OPTIONS_KEY;

        if ( function_exists( 'rental_options_trace' ) ) {
            $rntp_disp = array();
            foreach ( (array) ( $cart_item[ $options_key ] ?? array() ) as $oid => $sv ) {
                $rntp_disp[ $oid ] = is_array( $sv ) ? ( isset( $sv['value_id'] ) ? (int) $sv['value_id'] : ( isset( $sv['selected_value_id'] ) ? (int) $sv['selected_value_id'] : 0 ) ) : (int) $sv;
            }
            rental_options_trace( 'display_in_cart', array(
                'pid'           => ! empty( $cart_item['variation_id'] ) ? $cart_item['variation_id'] : ( $cart_item['product_id'] ?? 0 ),
                'options_key'   => $options_key,
                'cart_table'    => self::$is_rendering_cart_table ? 1 : 0,
                'from_cart_line'=> $rntp_disp,
            ) );
        }

        if (isset($cart_item[$options_key]) && !empty($cart_item[$options_key])) {
            $product_id = !empty($cart_item['variation_id']) ? $cart_item['variation_id'] : $cart_item['product_id'];
            $is_set = !empty($cart_item['rental_is_set']);
            $currency_symbol = get_woocommerce_currency_symbol();

            // Section title so output reads "Options: ..." then the option lines
            $item_data[] = [
                'key'   => __('Options', 'rentopian-sync'),
                'value' => '',
                'display' => '',
                'hidden' => false,
            ];

            foreach ($cart_item[$options_key] as $option_id => $option_data) {
                $option_data = $this->enrich_option_data_for_display($product_id, $is_set, $option_id, $option_data);
                $option_title = isset($option_data['option_title']) ? $option_data['option_title'] : __('Option', 'rentopian-sync');
                $display_value = $this->format_option_display($option_data, $currency_symbol);
                
                $item_data[] = [
                    'key' => esc_html($option_title),
                    'value' => $display_value,
                    'display' => '',
                    'hidden' => false,
                ];
            }
        }

        return $item_data;
    }

    /**
     * Enrich option data with option_title and value_title when missing (e.g. legacy session format).
     *
     * @param int   $product_id  Product or set ID
     * @param bool  $is_set      Whether product is a set
     * @param int   $option_id   Option ID
     * @param array $option_data Option data (may contain only selected_value_id/value_id and price)
     * @return array Enriched option_data with option_title and value_title when possible
     */
    public function enrich_option_data_for_display($product_id, $is_set, $option_id, $option_data) {
        $option_data = is_array($option_data) ? $option_data : [];
        $value_id = isset($option_data['selected_value_id']) ? $option_data['selected_value_id'] : (isset($option_data['value_id']) ? $option_data['value_id'] : null);
        $has_title = !empty($option_data['option_title']);
        $has_value_title = !empty($option_data['value_title']);
        if ($has_title && $has_value_title) {
            return $option_data;
        }
        $options = $is_set ? $this->get_set_options_with_defaults($product_id) : $this->get_product_options_with_defaults($product_id);
        
        foreach ($options as $option) {
            if ((int) $option['id'] !== (int) $option_id) {
                continue;
            }
            if (!$has_title) {
                $option_data['option_title'] = isset($option['title']) ? $option['title'] : '';
            }
            if (!$has_value_title && $value_id !== null && !empty($option['option_values'])) {
                foreach ($option['option_values'] as $value) {
                    if ((int) $value['id'] === (int) $value_id) {
                        $option_data['value_title'] = isset($value['title']) ? $value['title'] : '';
                        break;
                    }
                }
            }
            break;
        }
        
        if (!isset($option_data['option_title'])) {
            $option_data['option_title'] = '';
        }
        if (!isset($option_data['value_title'])) {
            $option_data['value_title'] = '';
        }
        return $option_data;
    }

    /**
     * Format option value for display
     *
     * The price suffix is dropped while prices are hidden; the raw option
     * data (including the price) is still stored on the cart line and the
     * order item, so only the visitor-facing label changes.
     *
     * @param array $option_data Option data
     * @param string $currency_symbol Currency symbol
     * @param string $context Display context, see rental_prices_are_hidden()
     * @return string Formatted display value
     */
    public function format_option_display($option_data, $currency_symbol = null, $context = 'cart') {
        if (!$currency_symbol) {
            $currency_symbol = get_woocommerce_currency_symbol();
        }

        $value_title = isset($option_data['value_title']) ? esc_html($option_data['value_title']) : '';
        $price = isset($option_data['price']) ? floatval($option_data['price']) : 0;

        if (function_exists('rental_prices_are_hidden') && rental_prices_are_hidden($context)) {
            return $value_title;
        }

        if ($price > 0) {
            return sprintf('%s (+%s%s)', $value_title, $currency_symbol, number_format($price, 2));
        } elseif ($price < 0) {
            return sprintf('%s (%s%s)', $value_title, $currency_symbol, number_format($price, 2));
        }
        
        return $value_title;
    }

    /**
     * Apply options pricing to cart items
     * 
     * @param WC_Cart $cart Cart object
     */
    public function apply_options_pricing($cart) {
        if (is_admin() && !defined('DOING_AJAX')) {
            return;
        }
        
        if (did_action('woocommerce_before_calculate_totals') >= 2) {
            return;
        }
        
        foreach ($cart->get_cart() as $cart_item_key => $cart_item) {
            $options_key = !empty($cart_item['rental_is_set']) ? self::CART_ITEM_SET_OPTIONS_KEY : self::CART_ITEM_OPTIONS_KEY;
            
            if (isset($cart_item[$options_key]) && !empty($cart_item[$options_key])) {
                $options_total = 0;
                
                foreach ($cart_item[$options_key] as $option_data) {
                    if (isset($option_data['price'])) {
                        $options_total += floatval($option_data['price']);
                    }
                }
                
                if ($options_total != 0) {
                    $product = $cart_item['data'];
                    $current_price = floatval($product->get_price());
                    $new_price = $current_price + $options_total;
                    $product->set_price($new_price);
                }
            }
        }
    }

    /**
     * Save options to order item meta during checkout
     * 
     * @param WC_Order_Item $item Order item
     * @param string $cart_item_key Cart item key
     * @param array $values Cart item values
     * @param WC_Order $order Order object
     */
    public function save_options_to_order_item($item, $cart_item_key, $values, $order) {
        $options_key = !empty($values['rental_is_set']) ? self::CART_ITEM_SET_OPTIONS_KEY : self::CART_ITEM_OPTIONS_KEY;
        
        if (isset($values[$options_key]) && !empty($values[$options_key])) {
            // Store the full options data for API processing
            $item->add_meta_data(self::ORDER_ITEM_OPTIONS_KEY, $values[$options_key], true);
            
            // Also add individual meta entries for display
            $currency_symbol = get_woocommerce_currency_symbol();
            foreach ($values[$options_key] as $option_id => $option_data) {
                $display_value = $this->format_option_display($option_data, $currency_symbol);
                $item->add_meta_data($option_data['option_title'], $display_value, false);
            }
        }
    }

    /**
     * Format order item options for display
     * Hides internal meta keys and formats options nicely
     *
     * Option rows are re-rendered from the raw option data stored on the item
     * whenever prices are hidden. The per-option display meta was written at
     * checkout, so orders placed while prices were still visible carry the
     * price inside the stored string — rebuilding here keeps historical
     * orders consistent with the current setting.
     *
     * @param array $formatted_meta Formatted meta
     * @param WC_Order_Item $item Order item
     * @return array Modified formatted meta
     */
    public function format_order_item_options($formatted_meta, $item) {
        foreach ($formatted_meta as $key => $meta) {
            // Hide internal meta key
            if ($meta->key === self::ORDER_ITEM_OPTIONS_KEY) {
                unset($formatted_meta[$key]);
            }
        }

        if (!function_exists('rental_prices_are_hidden') || !rental_prices_are_hidden('cart')) {
            return $formatted_meta;
        }

        $labels = $this->get_price_free_option_labels($item);
        if (empty($labels)) {
            return $formatted_meta;
        }

        foreach ($formatted_meta as $meta) {
            if (isset($labels[$meta->key])) {
                $meta->value = $labels[$meta->key];
                $meta->display_value = wpautop(make_clickable($labels[$meta->key]));
            }
        }

        return $formatted_meta;
    }

    /**
     * Option title => price-free value label, read from the raw option data
     * stored on an order item.
     *
     * Falls back to the JSON copy written by Rental_Options_WC_Integration
     * when the array copy is absent.
     *
     * @param WC_Order_Item $item Order item
     * @return array
     */
    private function get_price_free_option_labels($item) {
        $options = $item->get_meta(self::ORDER_ITEM_OPTIONS_KEY, true);

        if (empty($options)) {
            $options = $item->get_meta('_rental_selected_options', true);
            if (is_string($options)) {
                $options = json_decode($options, true);
            }
        }

        if (empty($options) || !is_array($options)) {
            return [];
        }

        $labels = [];
        foreach ($options as $option_data) {
            if (!is_array($option_data) || empty($option_data['option_title'])) {
                continue;
            }
            $labels[$option_data['option_title']] = $this->format_option_display($option_data);
        }

        return $labels;
    }

    /**
     * Display options in order item (order details, emails, thank you page)
     * 
     * @param int $item_id Order item ID
     * @param WC_Order_Item $item Order item
     * @param WC_Order $order Order
     */
    public function display_options_in_order_item($item_id, $item, $order) {
        $options = $item->get_meta(self::ORDER_ITEM_OPTIONS_KEY, true);
        
        if (!empty($options) && is_array($options)) {
            $currency_symbol = get_woocommerce_currency_symbol();
            echo '<div class="rental-product-options-display">';
            echo '<p class="rental-options-label"><strong>' . esc_html__('Options:', 'rentopian-sync') . '</strong></p>';
            echo '<ul class="rental-options-list">';
            
            foreach ($options as $option_data) {
                $display_value = $this->format_option_display($option_data, $currency_symbol);
                printf(
                    '<li>%s: %s</li>',
                    esc_html($option_data['option_title']),
                    esc_html($display_value)
                );
            }
            
            echo '</ul>';
            echo '</div>';
        }
    }

    /**
     * AJAX handler for updating cart item options
     */
    public function ajax_update_cart_item_options() {
        check_ajax_referer('rental_options_nonce', 'security');
        
        $cart_item_key = isset($_POST['cart_item_key']) ? sanitize_text_field($_POST['cart_item_key']) : '';
        $option_id = isset($_POST['option_id']) ? intval($_POST['option_id']) : 0;
        $value_id = isset($_POST['value_id']) ? intval($_POST['value_id']) : 0;
        
        if (empty($cart_item_key) || !$option_id) {
            wp_send_json_error(['message' => 'Invalid parameters']);
            return;
        }
        
        $cart = WC()->cart;
        $cart_contents = $cart->get_cart();
        
        if (!isset($cart_contents[$cart_item_key])) {
            wp_send_json_error(['message' => 'Cart item not found']);
            return;
        }
        
        $cart_item = $cart_contents[$cart_item_key];
        $product_id = $cart_item['variation_id'] ? $cart_item['variation_id'] : $cart_item['product_id'];
        $is_set = !empty($cart_item['rental_is_set']);
        
        // Get options
        if ($is_set) {
            $options = $this->get_set_options_with_defaults($product_id);
        } else {
            $options = $this->get_product_options_with_defaults($product_id);
        }
        
        // Find the selected option and value
        $selected_value = null;
        
        foreach ($options as $option) {
            if ($option['id'] == $option_id) {

                foreach ($option['option_values'] as $value) {

                    if ($value['id'] == $value_id) {
                        
                        $selected_value = [
                            'value_id' => $value['id'],
                            'value_title' => $value['title'],
                            'price' => floatval($value['price']),
                            'option_title' => $option['title'],
                            'option_id' => $option_id
                        ];
                        break;
                    }
                }
                break;
            }
        }
        
        if (!$selected_value) {
            wp_send_json_error(['message' => 'Option value not found']);
            return;
        }
        
        // Update cart item
        $options_key = $is_set ? self::CART_ITEM_SET_OPTIONS_KEY : self::CART_ITEM_OPTIONS_KEY;
        
        if (!isset($cart_contents[$cart_item_key][$options_key])) {
            $cart_contents[$cart_item_key][$options_key] = [];
        }
        
        $cart_contents[$cart_item_key][$options_key][$option_id] = $selected_value;
        
        // Update session
        WC()->cart->set_cart_contents($cart_contents);
        WC()->cart->set_session();
        
        // Recalculate totals
        WC()->cart->calculate_totals();
        
        // Also update the session-based storage for consistency
        $session_options = get_rental_session_data(self::SESSION_OPTIONS_KEY, []);
        $session_options[$product_id][$option_id] = $selected_value;
        set_rental_session_data(self::SESSION_OPTIONS_KEY, $session_options);
        
        // Also update legacy session key for compatibility
        $legacy_session_key = $product_id . ($is_set ? "_selected_options_of_set" : "_selected_options");
        $legacy_options = get_rental_session_data($legacy_session_key, []);
        $legacy_options[$option_id] = $selected_value;
        set_rental_session_data($legacy_session_key, $legacy_options);
        
        wp_send_json_success([
            'message' => 'Options updated',
            'cart_total' => WC()->cart->get_total(),
            'cart_subtotal' => WC()->cart->get_subtotal(),
            'fragments' => $this->get_cart_fragments()
        ]);
    }

    /**
     * Get cart fragments for AJAX updates
     * 
     * @return array Cart fragments
     */
    private function get_cart_fragments() {
        ob_start();
        woocommerce_mini_cart();
        $mini_cart = ob_get_clean();
        
        $fragments = [
            'div.widget_shopping_cart_content' => '<div class="widget_shopping_cart_content">' . $mini_cart . '</div>'
        ];
        
        return $fragments;
    }

    /**
     * AJAX handler for getting options HTML for a cart item
     */
    public function ajax_get_cart_item_options_html() {
        $cart_item_key = isset($_POST['cart_item_key']) ? sanitize_text_field($_POST['cart_item_key']) : '';
        $product_id = isset($_POST['product_id']) ? intval($_POST['product_id']) : 0;
        $is_set = isset($_POST['is_set']) ? intval($_POST['is_set']) : 0;
        
        if (!$product_id) {
            wp_send_json_error(['message' => 'Invalid product ID']);
            return;
        }
        
        $html = $this->render_options_selectors($product_id, $is_set, $cart_item_key);
        
        wp_send_json_success([
            'html' => $html
        ]);
    }

    /**
     * Render options selectors for a product/set
     * 
     * @param int $product_id Product ID
     * @param bool $is_set Whether this is a set
     * @param string $cart_item_key Cart item key (optional)
     * @return string HTML output
     */
    public function render_options_selectors($product_id, $is_set = false, $cart_item_key = '') {
        if ($is_set) {
            $options = $this->get_set_options_with_defaults($product_id);
        } else {
            $options = $this->get_product_options_with_defaults($product_id);
        }
        
        if (empty($options)) {
            return '';
        }
        
        // The cart line is the SINGLE SOURCE OF TRUTH once the item is in
        // the cart. If no explicit cart_item_key was passed (e.g. the
        // product page rendering an item that's already in the cart),
        // resolve it by product id so we still read the cart line instead
        // of the separate session store — keeping product page and cart in
        // lockstep.
        if (empty($cart_item_key) && function_exists('rental_find_cart_item_key_by_product_id')) {
            $resolved_key = rental_find_cart_item_key_by_product_id($product_id);
            if ($resolved_key) {
                $cart_item_key = $resolved_key;
            }
        }

        // Get selected options from cart if cart_item_key is provided
        $selected_options = [];
        if ($cart_item_key) {
            $cart = WC()->cart->get_cart();
            if (isset($cart[$cart_item_key])) {
                $options_key = $is_set ? self::CART_ITEM_SET_OPTIONS_KEY : self::CART_ITEM_OPTIONS_KEY;
                if (isset($cart[$cart_item_key][$options_key])) {
                    $selected_options = $cart[$cart_item_key][$options_key];
                }
            }
        }
        
        // Get from session if not in cart
        $rntp_render_source = $cart_item_key && ! empty( $selected_options ) ? 'cart_line' : '';
        if (empty($selected_options)) {
            $session_name = $product_id . ($is_set ? "_selected_options_of_set" : "_selected_options");
            $selected_options = get_rental_session_data($session_name, []);
            $rntp_render_source = 'session';
        }

        if ( function_exists( 'rental_options_trace' ) ) {
            $rntp_render_resolved = array();
            foreach ( (array) $selected_options as $oid => $ov ) {
                $rntp_render_resolved[ $oid ] = is_array( $ov )
                    ? ( isset( $ov['value_id'] ) ? (int) $ov['value_id'] : ( isset( $ov['selected_value_id'] ) ? (int) $ov['selected_value_id'] : 0 ) )
                    : (int) $ov;
            }
            rental_options_trace( 'render_selectors', array(
                'pid'      => $product_id,
                'is_set'   => $is_set ? 1 : 0,
                'cart_key' => $cart_item_key ? substr( (string) $cart_item_key, 0, 6 ) : '-',
                'source'   => $rntp_render_source ?: 'none',
                'resolved' => $rntp_render_resolved,
            ) );
        }

        $currency_symbol = get_woocommerce_currency_symbol();
        // Cart-side renderer (cart page column, cart options AJAX), so the
        // cart context decides whether the option prices may be shown.
        $hide_prices = function_exists('rental_prices_are_hidden') && rental_prices_are_hidden('cart');

        ob_start();
        ?>
        <div class="rental-product-options"
             data-product-id="<?php echo esc_attr($product_id); ?>"
             data-is-set="<?php echo esc_attr($is_set ? '1' : '0'); ?>"
             data-cart-item-key="<?php echo esc_attr($cart_item_key); ?>">
            <?php foreach ($options as $option): 
                $option_id = $option['id'];
                $once_per_order = !empty($option['once_per_order']);
                
                if ($once_per_order): ?>
                    <div class="rental-option-field rental-option-once-per-order">
                        <label><?php echo esc_html($option['title']); ?></label>
                        <small><?php esc_html_e('This option is a once per order type.', 'rentopian-sync'); ?></small>
                    </div>
                <?php else: 
                    $selected_value_id = isset($selected_options[$option_id]['value_id']) 
                        ? $selected_options[$option_id]['value_id'] 
                        : null;
                ?>
                    <div class="rental-option-field">
                        <label for="rental-option-<?php echo esc_attr($option_id); ?>">
                            <?php echo esc_html($option['title']); ?>
                        </label>
                        <select 
                            id="rental-option-<?php echo esc_attr($option_id); ?>"
                            class="rental-product-options-select"
                            data-option-id="<?php echo esc_attr($option_id); ?>"
                            data-product-id="<?php echo esc_attr($product_id); ?>"
                            data-is-set="<?php echo esc_attr($is_set ? '1' : '0'); ?>"
                            data-cart-item-key="<?php echo esc_attr($cart_item_key); ?>">
                            <?php foreach ($option['option_values'] as $value): 
                                $is_selected = false;
                                if ($selected_value_id !== null) {
                                    $is_selected = ($value['id'] == $selected_value_id);
                                } else {
                                    $is_selected = !empty($value['is_default']);
                                }
                                $price = floatval($value['price']);
                            ?>
                                <option 
                                    value="<?php echo esc_attr($value['id']); ?>"
                                    data-price="<?php echo esc_attr($price); ?>"
                                    <?php selected($is_selected); ?>>
                                    <?php
                                    echo esc_html($value['title']);
                                    if ($price != 0 && !$hide_prices) {
                                        if ($price > 0) {
                                            printf(' (+%s%s)', $currency_symbol, number_format($price, 2));
                                        } else {
                                            printf(' (%s%s)', $currency_symbol, number_format($price, 2));
                                        }
                                    }
                                    ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Update mini-cart fragment
     * 
     * @param array $fragments Cart fragments
     * @return array Modified fragments
     */
    public function update_mini_cart_fragment($fragments) {
        ob_start();
        woocommerce_mini_cart();
        $fragments['div.widget_shopping_cart_content'] = '<div class="widget_shopping_cart_content">' . ob_get_clean() . '</div>';
        return $fragments;
    }

    /**
     * Get product options with defaults
     * 
     * @param int $product_id Product ID
     * @return array Options
     */
    public function get_product_options_with_defaults($product_id) {
        if (function_exists('get_product_options')) {
            return get_product_options($product_id);
        }
        return [];
    }

    /**
     * Get set options with defaults
     * 
     * @param int $product_id Set product ID
     * @return array Options
     */
    public function get_set_options_with_defaults($product_id) {
        if (function_exists('get_set_options')) {
            return get_set_options($product_id);
        }
        return [];
    }

    /**
     * Get the effective default value for an option.
     *
     * Returns the explicitly marked default value if one exists.
     * If no explicit default is set AND the option has exactly one value,
     * that sole value is treated as the implicit default (the user has no
     * choice to make, so validation should not block add-to-cart).
     *
     * This method is the single source of truth for "which value should be
     * auto-selected when the user hasn't made an explicit choice" and is used
     * by validate_options_selection, add_options_to_cart_item_data, and
     * get_latest_options_from_session.
     *
     * @since 2.14.0
     *
     * @param array $option Single option definition with 'option_values' array.
     * @return array|null The default value definition, or null if none can be determined.
     */
    private function get_effective_default_value($option) {
        if (class_exists('Rental_Options_Defaults')) {
            return Rental_Options_Defaults::resolve($option);
        }

        if (empty($option['option_values']) || !is_array($option['option_values'])) {
            return null;
        }

        // First pass: look for an explicitly marked default
        foreach ($option['option_values'] as $value) {
            if (!empty($value['is_default'])) {
                return $value;
            }
        }

        // Second pass: if there is exactly one value, treat it as the implicit default.
        // When only one choice exists the user cannot meaningfully "select" anything,
        // so we should not require an explicit session entry or is_default flag.
        if (count($option['option_values']) === 1) {
            return reset($option['option_values']);
        }

        return null;
    }

    /**
     * Handle cart item restored
     * 
     * @param string $cart_item_key Cart item key
     * @param WC_Cart $cart Cart object
     */
    public function handle_cart_item_restored($cart_item_key, $cart) {
        // Ensure options are properly restored when item is restored from trash
        $cart_contents = $cart->get_cart();
        
        if (isset($cart_contents[$cart_item_key])) {
            $cart_item = $cart_contents[$cart_item_key];
            $options_key = !empty($cart_item['rental_is_set']) ? self::CART_ITEM_SET_OPTIONS_KEY : self::CART_ITEM_OPTIONS_KEY;
            
            if (isset($cart_item[$options_key])) {
                // Update session storage
                $product_id = $cart_item['variation_id'] ? $cart_item['variation_id'] : $cart_item['product_id'];
                $session_options = get_rental_session_data(self::SESSION_OPTIONS_KEY, []);
                $session_options[$product_id] = $cart_item[$options_key];
                set_rental_session_data(self::SESSION_OPTIONS_KEY, $session_options);
            }
        }
    }

    /**
     * Get selected options for a cart item
     * 
     * @param string $cart_item_key Cart item key
     * @return array Selected options
     */
    public function get_cart_item_options($cart_item_key) {
        $cart = WC()->cart->get_cart();
        
        if (!isset($cart[$cart_item_key])) {
            return [];
        }
        
        $cart_item = $cart[$cart_item_key];
        $options_key = !empty($cart_item['rental_is_set']) ? self::CART_ITEM_SET_OPTIONS_KEY : self::CART_ITEM_OPTIONS_KEY;
        
        return isset($cart_item[$options_key]) ? $cart_item[$options_key] : [];
    }

    /**
     * Get options for API submission
     * Formats options data for the Core API
     * 
     * @param int $product_id Product ID
     * @param bool $is_set Whether this is a set
     * @return array Options data for API
     */
    public function get_options_for_api($product_id, $is_set = false) {
        $session_options = get_rental_session_data(self::SESSION_OPTIONS_KEY, []);
        
        if (!isset($session_options[$product_id])) {
            return [];
        }
        
        $options_data = [];
        foreach ($session_options[$product_id] as $option_id => $option_data) {
            $options_data[] = [
                'option_id' => $option_id,
                'value_id' => $option_data['value_id'],
                'price' => $option_data['price'],
            ];
        }
        
        return $options_data;
    }

    /**
     * Get options total price for a product
     * 
     * @param int $product_id Product ID
     * @return float Total options price
     */
    public function get_options_total_price($product_id) {
        $session_options = get_rental_session_data(self::SESSION_OPTIONS_KEY, []);
        
        if (!isset($session_options[$product_id])) {
            return 0;
        }
        
        $total = 0;
        foreach ($session_options[$product_id] as $option_data) {
            if (isset($option_data['price'])) {
                $total += floatval($option_data['price']);
            }
        }
        
        return $total;
    }

    /**
     * Validate that all required options have been selected
     * 
     * @param int $product_id Product ID
     * @param bool $is_set Whether this is a set
     * @return bool|array True if valid, array of missing options if not
     */
    public function validate_options_selection($product_id, $is_set = false) {
        // Rental_Options_Selection resolves from the submission first, then the
        // cart line, then both session stores, then the option default. Reading
        // only the session here is what let this method report an option as
        // missing while the page showed it selected.
        if (class_exists('Rental_Options_Selection')) {
            $missing = Rental_Options_Selection::unanswered($product_id, (bool) $is_set);

            return empty($missing) ? true : wp_list_pluck($missing, 'title');
        }

        if ($is_set) {
            $options = $this->get_set_options_with_defaults($product_id);
        } else {
            $options = $this->get_product_options_with_defaults($product_id);
        }

        if (empty($options)) {
            return true;
        }

        $session_options = get_rental_session_data(self::SESSION_OPTIONS_KEY, []);
        $product_options = isset($session_options[$product_id]) ? $session_options[$product_id] : [];

        $missing = [];
        foreach ($options as $option) {
            if (!empty($option['once_per_order'])) {
                continue; // Skip once per order options
            }

            // Check if option has a valid selection (not the "Please select" placeholder)
            if (!isset($product_options[$option['id']]) ||
                $product_options[$option['id']]['value_id'] == -1) {

                // Check if there's an effective default (explicit default OR sole value)
                $effective_default = $this->get_effective_default_value($option);

                if (!$effective_default) {
                    $missing[] = $option['title'];
                }
            }
        }

        return empty($missing) ? true : $missing;
    }
}

// Initialize the manager
function rental_product_options_manager() {
    return Rental_Product_Options_Manager::get_instance();
}

// Initialize on plugins_loaded
add_action('plugins_loaded', 'rental_product_options_manager', 20);
