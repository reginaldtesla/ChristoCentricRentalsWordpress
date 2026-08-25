<?php
/**
 * Rental_Options_WC_Integration - WooCommerce hooks integration for rental options.
 *
 * This class provides theme-independent rendering of product and set options
 * through standard WooCommerce hooks. It works alongside the existing AJAX-based
 * implementation for backward compatibility.
 *
 * Features:
 * - Server-side HTML rendering (reduces AJAX dependency)
 * - Standard WooCommerce hooks for cart/checkout/order display
 * - Theme-independent (works with any WooCommerce-compatible theme)
 * - Full backward compatibility with existing rentpro and eventorian themes integration
 *
 * @package    Rentopian_Sync
 * @subpackage Rentopian_Sync/includes
 * @since      2.13.0
 * @author     Rentopian
 * @see        docs/options/README.md for full documentation
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * WooCommerce Integration Class for Rental Options.
 *
 * Provides hooks and methods for displaying rental options in cart,
 * checkout, and order pages using standard WooCommerce filters and actions.
 *
 * @since 2.13.0
 */
class Rental_Options_WC_Integration
{
    /**
     * Singleton instance.
     *
     * @var Rental_Options_WC_Integration|null
     */
    private static $instance = null;

    /**
     * Whether the integration is enabled.
     *
     * @var bool
     */
    private $enabled = true;

    /**
     * Currency symbol for price display.
     *
     * @var string
     */
    private $currency_symbol;

    /**
     * Get singleton instance.
     *
     * @return Rental_Options_WC_Integration
     */
    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor - Initialize hooks.
     *
     * Private to enforce singleton pattern.
     */
    private function __construct()
    {
        // Check if WooCommerce is active
        if (!function_exists('WC')) {
            $this->enabled = false;
            return;
        }

        $this->currency_symbol = get_woocommerce_currency_symbol();

        // Initialize hooks
        $this->init_hooks();
    }

    /**
     * Initialize WooCommerce hooks for options display.
     *
     * These hooks provide theme-independent rendering of options data
     * in cart, checkout, and order pages.
     *
     *
     * @return void
     */
    private function init_hooks()
    {
        // Save options to order item meta when order is created (works with all themes)
        add_action('woocommerce_checkout_create_order_line_item', [$this, 'save_options_to_order_item'], 20, 4);

        // Email display of options (works with all themes)
        add_filter('woocommerce_order_item_get_formatted_meta_data', [$this, 'format_options_meta_for_display'], 20, 2);

        // Allow themes/plugins to control integration via filter
        add_action('after_setup_theme', [$this, 'maybe_adjust_for_theme'], 20);
    }

    /**
     * Whether option prices must be hidden from the visitor.
     *
     * @param string $context Display context, see rental_prices_are_hidden().
     * @return bool
     */
    private function prices_are_hidden($context = 'cart')
    {
        return function_exists('rental_prices_are_hidden') && rental_prices_are_hidden($context);
    }

    /**
     * Adjust integration based on theme support.
     *
     * Allows themes to disable specific hooks via theme support or filter.
     *
     * @return void
     */
    public function maybe_adjust_for_theme()
    {
        // Check if theme explicitly disables rental options integration
        if (current_theme_supports('rentopian-custom-options-display')) {
            // Theme handles its own options display
            remove_filter('woocommerce_get_item_data', [$this, 'display_cart_item_options'], 20);
        }

        // Allow filter control
        $disable_cart_display = apply_filters('rentopian_disable_cart_options_display', false);
        if ($disable_cart_display) {
            remove_filter('woocommerce_get_item_data', [$this, 'display_cart_item_options'], 20);
        }
    }

    /**
     * Display selected options in cart item data.
     *
     * This filter adds rental option selections to the cart item display,
     * appearing below the product name in cart and checkout pages.
     * Format matches the fly-in cart: "Option Title: Selected Value ($X.XX)"
     *
     * @param array $item_data Array of item data (name => value pairs).
     * @param array $cart_item Cart item data.
     * @return array Modified item data with options.
     */
    public function display_cart_item_options($item_data, $cart_item)
    {
        if (!$this->enabled) {
            return $item_data;
        }

        $product_id = isset($cart_item['variation_id']) && $cart_item['variation_id']
            ? $cart_item['variation_id']
            : $cart_item['product_id'];

        // Check if this is a set or regular product
        $is_set = get_post_meta($product_id, '_rental_is_set', true);

        // Skip if this is a set's child item
        if (isset($cart_item['rental_set_id'])) {
            return $item_data;
        }

        // Get selected options - pass cart_item for direct data lookup
        $selected_options = $this->get_selected_options_from_session($product_id, $is_set, $cart_item);

        if (empty($selected_options)) {
            return $item_data;
        }

        // Get option definitions
        $options = $is_set ? $this->get_set_options($product_id) : $this->get_product_options($product_id);

        if (empty($options)) {
            return $item_data;
        }

        // Build formatted options list
        $options_list = [];
        foreach ($options as $option) {
            $option_id = $option['id'];

            // Skip once-per-order options (displayed separately)
            if (!empty($option['once_per_order'])) {
                continue;
            }

            // Check if this option has a selection
            if (isset($selected_options[$option_id])) {
                $selection = $selected_options[$option_id];
                $selected_value = $this->get_option_value_by_id($option, $selection['selected_value_id']);

                if ($selected_value && $selected_value['id'] != -1) {
                    $display_text = esc_html($option['title']) . ': ' . esc_html($selected_value['title']);

                    // Add price if applicable
                    $price = floatval($selected_value['price']);
                    if ($price > 0 && !$this->prices_are_hidden()) {
                        $display_text .= ' (' . $this->currency_symbol . number_format($price, 2) . ')';
                    }

                    $options_list[] = $display_text;
                }
            }
        }

        // Add options with "Options:" header if there are any
        if (!empty($options_list)) {
            $item_data[] = [
                'key'   => __('Options', 'rentopian-sync'),
                'value' => implode('<br>', $options_list),
                'display' => '',
            ];
        }

        return $item_data;
    }

    /**
     * Display selected options in mini-cart (fly-in cart) item.
     *
     * This action adds rental option selections to the mini-cart item display,
     * appearing below the product name. Options are shown as read-only text.
     * This is theme-independent and works with WooCommerce's standard mini-cart.
     * Format: "Options:" header followed by "Option Title: Selected Value ($X.XX)"
     *
     * @since 2.13.0
     * @param string $product_name   The product name HTML.
     * @param array  $cart_item      Cart item data.
     * @param string $cart_item_key  Cart item key.
     * @return void Echoes HTML output.
     */
    public function display_mini_cart_item_options($product_name, $cart_item, $cart_item_key)
    {
        if (!$this->enabled) {
            return;
        }

        $product_id = isset($cart_item['variation_id']) && $cart_item['variation_id']
            ? $cart_item['variation_id']
            : $cart_item['product_id'];

        // Check if this is a set or regular product
        $is_set = get_post_meta($product_id, '_rental_is_set', true);

        // Skip if this is a set's child item
        if (isset($cart_item['rental_set_id'])) {
            return;
        }

        // Get selected options - pass cart_item for direct data lookup
        $selected_options = $this->get_selected_options_from_session($product_id, $is_set, $cart_item);

        if (empty($selected_options)) {
            return;
        }

        // Get option definitions
        $options = $is_set ? $this->get_set_options($product_id) : $this->get_product_options($product_id);

        if (empty($options)) {
            return;
        }

        // Build options HTML for mini-cart 
        $options_html = '<div class="rental-mini-cart-options" style="margin: 5px 0; padding: 5px 0; font-size: 0.85em; color: #666; border-top: 1px dashed #eee;">';
        $options_html .= '<div class="rental-mini-cart-options-header" style="font-weight: 600; color: #333; margin-bottom: 3px;">' . esc_html__('Options', 'rentopian-sync') . '</div>';
        $has_options = false;

        foreach ($options as $option) {
            $option_id = $option['id'];
            $option_id_str = strval($option_id);

            // Skip once-per-order options (displayed separately)
            if (!empty($option['once_per_order'])) {
                continue;
            }

            // Check if this option has a selection (check both int and string keys)
            $selection = null;
            if (isset($selected_options[$option_id])) {
                $selection = $selected_options[$option_id];
            } elseif (isset($selected_options[$option_id_str])) {
                $selection = $selected_options[$option_id_str];
            }
            
            if ($selection !== null) {
                $selected_value_id = isset($selection['selected_value_id']) 
                    ? $selection['selected_value_id'] 
                    : (isset($selection['value_id']) ? $selection['value_id'] : null);
                    
                $selected_value = $this->get_option_value_by_id($option, $selected_value_id);

                if ($selected_value && $selected_value['id'] != -1) {
                    $has_options = true;
                    $display_value = esc_html($selected_value['title']);

                    // Add price if applicable
                    $price = floatval($selected_value['price']);
                    if ($price > 0 && !$this->prices_are_hidden()) {
                        $display_value .= ' <small style="color: #888;">(' . $this->currency_symbol . number_format($price, 2) . ')</small>';
                    }

                    $options_html .= '<div class="rental-mini-cart-option-item" style="margin: 2px 0;">';
                    $options_html .= '<span style="font-weight: 500;">' . esc_html($option['title']) . ':</span> ';
                    $options_html .= '<span>' . $display_value . '</span>';
                    $options_html .= '</div>';
                }
            }
        }

        $options_html .= '</div>';

        // Output only if there are options to display
        if ($has_options) {
            echo $options_html;
        }
    }

    /**
     * Display options in order item meta (order details page, thank you page, and emails).
     *
     * Format matches: "Options:" header followed by
     * "Option Title: Selected Value ($X.XX)" for each option.
     * 
     * Uses enriched title data when available (from save_options_to_order_item),
     * falls back to looking up titles from option definitions.
     *
     * @param int           $item_id    Order item ID.
     * @param WC_Order_Item $item       Order item object.
     * @param WC_Order      $order      Order object.
     * @param bool          $plain_text Whether this is plain text (for emails).
     * @return void
     */
    public function display_order_item_options($item_id, $item, $order, $plain_text = false)
    {
        if (!$this->enabled) {
            return;
        }

        // Get saved options from order item meta
        $saved_options = $item->get_meta('_rental_selected_options');

        if (empty($saved_options)) {
            return;
        }

        // Ensure it's an array
        if (is_string($saved_options)) {
            $saved_options = json_decode($saved_options, true);
        }

        if (empty($saved_options) || !is_array($saved_options)) {
            return;
        }

        // Get product ID for option definitions (needed when titles not stored)
        $product_id = $item->get_variation_id() ?: $item->get_product_id();
        $is_set = get_post_meta($product_id, '_rental_is_set', true);

        // Get option definitions (for looking up titles if not stored)
        $options = $is_set ? $this->get_set_options($product_id) : $this->get_product_options($product_id);

        // Build options display format
        $options_list = [];
        foreach ($saved_options as $option_id => $selection) {
            // Skip once-per-order options (they may have been stored but shouldn't display per-item)
            $option = $this->find_option_by_id($options, $option_id);
            if ($option && !empty($option['once_per_order'])) {
                continue;
            }

            // Get option title - prefer stored title, fallback to definition lookup
            $option_title = '';
            if (isset($selection['option_title']) && !empty($selection['option_title'])) {
                $option_title = $selection['option_title'];
            } elseif ($option) {
                $option_title = isset($option['title']) ? $option['title'] : '';
            }
            
            if (empty($option_title)) {
                continue; // Skip if we can't determine the option title
            }

            // Get value ID
            $selected_value_id = isset($selection['selected_value_id']) 
                ? $selection['selected_value_id'] 
                : (isset($selection['value_id']) ? $selection['value_id'] : null);

            // Skip if no value selected or "Please select" (-1)
            if ($selected_value_id === null || intval($selected_value_id) === -1) {
                continue;
            }

            // Get value title
            $value_title = '';
            $price = 0;
            
            if (isset($selection['value_title']) && !empty($selection['value_title'])) {

                $value_title = $selection['value_title'];
                $price = isset($selection['price']) ? floatval($selection['price']) : 0;
            } else {
                // Look up from option definitions

                $selected_value = $option ? $this->get_option_value_by_id($option, $selected_value_id) : null;
                if ($selected_value) {
                    $value_title = isset($selected_value['title']) ? $selected_value['title'] : '';
                    $price = isset($selected_value['price']) ? floatval($selected_value['price']) : 0;
                }
            }
            
            if (empty($value_title)) {
                continue; // Skip if we can't determine the value title
            }

            // Build display string: "Option Title: Value Title ($X.XX)"
            $value_display = esc_html($value_title);
            if ($price > 0 && !$this->prices_are_hidden()) {
                $value_display .= ' (' . $this->currency_symbol . number_format($price, 2) . ')';
            }

            $options_list[] = [
                'title' => esc_html($option_title),
                'value' => $value_display,
            ];
        }

        if (!empty($options_list)) {
            if ($plain_text) {
                // Plain text format for emails
                echo "\n" . esc_html__('Options:', 'rentopian-sync');
                foreach ($options_list as $opt) {
                    echo "\n" . $opt['title'] . ': ' . $opt['value'];
                }
            } else {
                // HTML format for order details, thank you page, customer account
                echo '<div class="rental-options-summary" style="margin: 8px 0; padding: 8px 0; font-size: 0.9em; border-top: 1px dashed #ddd;">';
                echo '<div class="rental-options-header" style="font-weight: 600; color: #333; margin-bottom: 5px;">' . esc_html__('Options:', 'rentopian-sync') . '</div>';
                foreach ($options_list as $opt) {
                    echo '<div class="rental-option-display" style="margin: 3px 0;">';
                    echo '<span style="font-weight: 500;">' . $opt['title'] . ':</span> ';
                    echo '<span>' . $opt['value'] . '</span>';
                    echo '</div>';
                }
                echo '</div>';
            }
        }
    }

    /**
     * Save selected options to order item meta when order is created.
     *
     * This preserves the option selections permanently with the order,
     * allowing them to be displayed even after session data is cleared.
     * 
     * IMPORTANT: Stores full option data (including titles) for proper display
     * on checkout, thank you page, emails, and customer order pages.
     * 
     * PRIORITY: Reads from cart item data first (most reliable), then session.
     *
     * @param WC_Order_Item_Product $item          Order item object.
     * @param string                $cart_item_key Cart item key.
     * @param array                 $values        Cart item values.
     * @param WC_Order              $order         Order object.
     * @return void
     */
    public function save_options_to_order_item($item, $cart_item_key, $values, $order)
    {
        if (!$this->enabled) {
            return;
        }

        $product_id = isset($values['variation_id']) && $values['variation_id']
            ? $values['variation_id']
            : $values['product_id'];

        // Skip set child items
        if (isset($values['rental_set_id'])) {
            return;
        }

        $is_set = get_post_meta($product_id, '_rental_is_set', true);
        
        // Get options - pass $values (cart item data) for direct lookup
        $selected_options = $this->get_selected_options_from_session($product_id, $is_set, $values);

        if (!empty($selected_options)) {
            // Get option definitions to enrich with titles
            $option_definitions = $is_set ? $this->get_set_options($product_id) : $this->get_product_options($product_id);
            
            // Enrich selected options with full title information for display
            $enriched_options = $this->enrich_options_with_titles($selected_options, $option_definitions);
            
            // Save as JSON to preserve structure (used by display_order_item_options)
            $item->add_meta_data('_rental_selected_options', wp_json_encode($enriched_options), true);
        }
    }
    
    /**
     * Enrich selected options with title information from option definitions.
     * This ensures proper "Option Title: Value Title ($X.XX)" format on all pages.
     *
     * @param array $selected_options Selected options from cart/session.
     * @param array $option_definitions Option definitions from product/set.
     * @return array Enriched options with titles.
     */
    private function enrich_options_with_titles($selected_options, $option_definitions)
    {
        $enriched = [];
        
        foreach ($selected_options as $option_id => $selection) {
            $option = $this->find_option_by_id($option_definitions, $option_id);
            
            if (!$option) {
                // Keep original data if option definition not found
                $enriched[$option_id] = $selection;
                continue;
            }
            
            $value_id = isset($selection['selected_value_id']) 
                ? $selection['selected_value_id'] 
                : (isset($selection['value_id']) ? $selection['value_id'] : null);
            
            $selected_value = $this->get_option_value_by_id($option, $value_id);
            
            $enriched[$option_id] = [
                'selected_value_id' => intval($value_id),
                'value_id' => intval($value_id),
                'price' => isset($selection['price']) ? $selection['price'] : (isset($selected_value['price']) ? $selected_value['price'] : 0),
                'option_title' => isset($option['title']) ? $option['title'] : '',
                'value_title' => $selected_value ? (isset($selected_value['title']) ? $selected_value['title'] : '') : ''
            ];
        }
        
        return $enriched;
    }

    /**
     * Format options meta data for display in order details.
     *
     * Filters out internal meta keys and formats option display.
     *
     * @param array         $formatted_meta Formatted meta data.
     * @param WC_Order_Item $item           Order item object.
     * @return array Modified formatted meta data.
     */
    public function format_options_meta_for_display($formatted_meta, $item)
    {
        if (!$this->enabled) {
            return $formatted_meta;
        }

        // Filter out internal meta keys
        $hidden_keys = ['_rental_selected_options'];

        foreach ($formatted_meta as $key => $meta) {
            if (in_array($meta->key, $hidden_keys, true)) {
                unset($formatted_meta[$key]);
            }
        }

        return $formatted_meta;
    }

    /**
     * Get selected options for a product.
     * 
     * ROBUST PRIORITY ORDER:
     * 1. Cart item data (most reliable - persists in WC session, directly from cart_item)
     * 2. Cart lookup by product ID (if cart_item not provided)
     * 3. Product-specific session data (for single product page pre-add selections)
     * 4. Global valuables session data (fallback for mini-cart display)
     *
     * @param int        $product_id    Product ID.
     * @param bool       $is_set        Whether product is a set.
     * @param array|null $cart_item     Cart item data (optional, to avoid re-lookup).
     * @return array Selected options array.
     */
    /**
     * Compact {option_id => value_id} map for trace logging.
     *
     * @param array $normalized
     * @return array
     */
    private function trace_resolved($normalized)
    {
        $out = array();
        foreach ((array) $normalized as $oid => $sel) {
            if (is_array($sel)) {
                $out[$oid] = isset($sel['value_id']) ? (int) $sel['value_id'] : (isset($sel['selected_value_id']) ? (int) $sel['selected_value_id'] : 0);
            } else {
                $out[$oid] = (int) $sel;
            }
        }
        return $out;
    }

    private function get_selected_options_from_session($product_id, $is_set = false, $cart_item = null)
    {
        $product_id = intval($product_id);

        // The cart line is the SINGLE SOURCE OF TRUTH once the item is in
        // the cart. A SET stores its options under 'rental_selected_set_options';
        // a plain product under 'rental_selected_options'. 
        $cart_options_key = $is_set ? 'rental_selected_set_options' : 'rental_selected_options';

        // =====================================================================
        // A named cart line answers for itself and stops here.
        //
        // Every fallback below finds its answer by product id — the first cart
        // line matching it, then session stores keyed by it. With two lines of
        // the same product that resolves both to the first line, which reached
        // the mini-cart, the checkout and the saved order item. A caller that
        // knows which line it is asking about never needs any of that.
        // =====================================================================
        if (is_array($cart_item) && !empty($cart_item['key']) && class_exists('Rental_Options_Selection')) {
            $resolved = Rental_Options_Selection::for_cart_line($cart_item['key'], $cart_item);

            if (!empty($resolved)) {
                $normalized = $this->normalize_options_format($resolved);
                if (!empty($normalized)) {
                    if (function_exists('rental_options_trace')) {
                        rental_options_trace('wc_int_read', array('pid' => $product_id, 'is_set' => $is_set ? 1 : 0, 'source' => 'cart_line', 'key' => substr((string) $cart_item['key'], 0, 6), 'resolved' => $this->trace_resolved($normalized)));
                    }
                    return $normalized;
                }
            }

            return [];
        }

        // =====================================================================
        // PRIORITY 0: Check WC session cart data directly (most authoritative)
        // This data is updated immediately by rental_update_cart_item_options
        // The WC session is the source of truth - cart_contents might be stale
        // =====================================================================
        if (function_exists('WC') && WC()->session) {
            $cart_session_data = WC()->session->get('cart', []);
            // Find the cart item by product ID
            foreach ($cart_session_data as $key => $session_item) {
                if (!is_array($session_item)) {
                    continue;
                }
                $session_item_product_id = !empty($session_item['variation_id'])
                    ? intval($session_item['variation_id'])
                    : (isset($session_item['product_id']) ? intval($session_item['product_id']) : 0);

                if ($session_item_product_id === $product_id) {
                    if (isset($session_item[$cart_options_key]) && !empty($session_item[$cart_options_key])) {
                        $normalized = $this->normalize_options_format($session_item[$cart_options_key]);
                        if (!empty($normalized)) {
                            if (function_exists('rental_options_trace')) {
                                rental_options_trace('wc_int_read', array('pid'=>$product_id,'is_set'=>$is_set?1:0,'source'=>'P0_wc_session_cart','key'=>$cart_options_key,'resolved'=>$this->trace_resolved($normalized)));
                            }
                            return $normalized;
                        }
                    }
                    break;
                }
            }
        }

        // =====================================================================
        // PRIORITY 1: Try to get from cart item data directly passed
        // (This may be stale if the cart was loaded before recent updates)
        // =====================================================================
        if ($cart_item !== null && isset($cart_item[$cart_options_key])) {
            $options = $cart_item[$cart_options_key];
            if (!empty($options) && is_array($options)) {
                $normalized = $this->normalize_options_format($options);
                if (!empty($normalized)) {
                    return $normalized;
                }
            }
        }

        // =====================================================================
        // PRIORITY 2: Look up cart item by product ID in cart_contents
        // =====================================================================
        if (function_exists('WC') && WC()->cart) {
            foreach (WC()->cart->get_cart() as $item) {
                $item_product_id = !empty($item['variation_id'])
                    ? intval($item['variation_id'])
                    : intval($item['product_id']);

                if ($item_product_id === $product_id) {
                    if (isset($item[$cart_options_key]) && !empty($item[$cart_options_key])) {
                        $normalized = $this->normalize_options_format($item[$cart_options_key]);
                        if (!empty($normalized)) {
                            if (function_exists('rental_options_trace')) {
                                rental_options_trace('wc_int_read', array('pid'=>$product_id,'is_set'=>$is_set?1:0,'source'=>'P2_cart_contents','key'=>$cart_options_key,'resolved'=>$this->trace_resolved($normalized)));
                            }
                            return $normalized;
                        }
                    }
                    break;
                }
            }
        }

        // =====================================================================
        // PRIORITY 3: Product-specific session data
        // =====================================================================
        if (function_exists('WC') && WC()->session) {
            $session_key = $is_set
                ? $product_id . '_selected_options_of_set'
                : $product_id . '_selected_options';

            $selected = WC()->session->get($session_key, []);

            if (!empty($selected) && is_array($selected)) {
                $normalized = $this->normalize_options_format($selected);
                if (!empty($normalized)) {
                    if (function_exists('rental_options_trace')) {
                        rental_options_trace('wc_int_read', array('pid'=>$product_id,'is_set'=>$is_set?1:0,'source'=>'P3_session_fallback','key'=>$session_key,'resolved'=>$this->trace_resolved($normalized)));
                    }
                    return $normalized;
                }
            }
        }

        // =====================================================================
        // PRIORITY 4: Rental session data (product-specific)
        // =====================================================================
        if (function_exists('get_rental_session_data')) {
            $session_key = $is_set
                ? $product_id . '_selected_options_of_set'
                : $product_id . '_selected_options';
                
            $selected = get_rental_session_data($session_key, []);
            
            if (!empty($selected) && is_array($selected)) {
                $normalized = $this->normalize_options_format($selected);
                if (!empty($normalized)) {
                    return $normalized;
                }
            }
        }
        
        // =====================================================================
        // PRIORITY 5: Global valuables session (mini-cart/fly-in cart fallback)
        // =====================================================================
        if (function_exists('get_rental_session_data')) {
            $rental_product_options_valuables = get_rental_session_data('rental_product_options_valuables', []);
            if (isset($rental_product_options_valuables[$product_id]) && !empty($rental_product_options_valuables[$product_id])) {
                $normalized = $this->normalize_options_format($rental_product_options_valuables[$product_id]);
                if (!empty($normalized)) {
                    return $normalized;
                }
            }
        }

        return [];
    }
    
    /**
     * Normalize options format to ensure consistent structure.
     * Handles both old format (selected_value_id) and new format (value_id).
     * Preserves option_title and value_title if available for consistent display.
     * Always returns INTEGER keys for option_ids.
     *
     * @param array $options Options array.
     * @return array Normalized options array with integer keys.
     */
    private function normalize_options_format($options)
    {
        $normalized = [];
        foreach ($options as $option_id => $selection) {
            if (!is_array($selection)) {
                continue;
            }
            
            // Ensure both value_id and selected_value_id are present
            $value_id = isset($selection['selected_value_id']) 
                ? $selection['selected_value_id'] 
                : (isset($selection['value_id']) ? $selection['value_id'] : null);
            
            if ($value_id !== null) {
                $normalized_entry = [
                    'selected_value_id' => intval($value_id),
                    'value_id' => intval($value_id),
                    'price' => isset($selection['price']) ? $selection['price'] : 0
                ];
                
                // Preserve title information if available (for display on checkout/thank you/email)
                if (isset($selection['option_title'])) {
                    $normalized_entry['option_title'] = $selection['option_title'];
                }
                if (isset($selection['value_title'])) {
                    $normalized_entry['value_title'] = $selection['value_title'];
                }
                
                // Store with both integer key (for PHP array access) 
                // The key type doesn't matter much since PHP arrays handle both
                $normalized[intval($option_id)] = $normalized_entry;
            }
        }
        return $normalized;
    }

    /**
     * Get product options by product ID.
     *
     * Wrapper for global get_product_options function.
     *
     * @param int $product_id Product ID.
     * @return array Options array.
     */
    private function get_product_options($product_id)
    {
        if (function_exists('get_product_options')) {
            return get_product_options($product_id);
        }
        return [];
    }

    /**
     * Get set options by set ID.
     *
     * Wrapper for global get_set_options function.
     *
     * @param int $set_id Set product ID.
     * @return array Options array.
     */
    private function get_set_options($set_id)
    {
        if (function_exists('get_set_options')) {
            return get_set_options($set_id);
        }
        return [];
    }

    /**
     * Find an option by its ID in an options array.
     *
     * @param array $options   Array of option definitions.
     * @param int   $option_id Option ID to find.
     * @return array|null Option definition or null if not found.
     */
    private function find_option_by_id($options, $option_id)
    {
        foreach ($options as $option) {
            if (intval($option['id']) === intval($option_id)) {
                return $option;
            }
        }
        return null;
    }

    /**
     * Get an option value by its ID from an option definition.
     *
     * @param array $option   Option definition with 'option_values' array.
     * @param int   $value_id Value ID to find.
     * @return array|null Value definition or null if not found.
     */
    private function get_option_value_by_id($option, $value_id)
    {
        if (empty($option['option_values']) || !is_array($option['option_values'])) {
            return null;
        }

        foreach ($option['option_values'] as $value) {
            if (intval($value['id']) === intval($value_id)) {
                return $value;
            }
        }
        return null;
    }

    /**
     * Render options HTML for product page.
     *
     * This method provides server-side rendering of options as an alternative
     * to the AJAX-based approach. Can be used with any theme.
     *
     * @param int  $product_id Product ID.
     * @param bool $is_set     Whether product is a set.
     * @return string HTML output.
     */
    public function render_product_options_html($product_id, $is_set = false)
    {
        $options = $is_set ? $this->get_set_options($product_id) : $this->get_product_options($product_id);

        if (empty($options)) {
            return '';
        }

        $selected_options = $this->get_selected_options_from_session($product_id, $is_set);
        $output = '<div class="rental-product-options rental-product-options-server-rendered">';
        // $output .= '<div class="rental-options-header" style="font-weight: 600; color: #333; margin-bottom: 3px;">' . esc_html__('Options:', 'rentopian-sync') . '</div>';

        foreach ($options as $option) {
            // Handle once-per-order options differently
            if (!empty($option['once_per_order'])) {
                
                $output .= '<div class="rental-option-once-per-order">';
                $output .= '<label>' . esc_html($option['title']) . '</label>';
                $output .= '<small>' . esc_html__('This option applies once per order.', 'rentopian-sync') . '</small>';
                $output .= '</div>';

                continue;
            }

            $option_id = $option['id'];
            $selected_value_id = isset($selected_options[$option_id]['selected_value_id'])
                ? $selected_options[$option_id]['selected_value_id']
                : null;

            $output .= '<div class="rental-option-wrapper">';
            $output .= '<label for="rental_option_' . esc_attr($option_id) . '">';
            $output .= esc_html($option['title']);
            $output .= '</label>';

            $output .= '<select ';
            $output .= 'id="' . esc_attr($option_id) . '" ';
            $output .= 'class="rental-product-options-select" ';
            $output .= 'data-product-id="' . esc_attr($product_id) . '" ';
            $output .= 'data-is-set="' . ($is_set ? '1' : '0') . '" ';
            $output .= 'onchange="update_option_data_of_single_product(this)">';

            if (!empty($option['option_values']) && is_array($option['option_values'])) {
                $has_default = false;

                foreach ($option['option_values'] as $value) {
                    $value_id = $value['id'];
                    $price = floatval($value['price']);
                    $is_default = !empty($value['is_default']);

                    // Determine if this value should be selected
                    $is_selected = false;
                    if ($selected_value_id !== null && intval($selected_value_id) === intval($value_id)) {
                        $is_selected = true;
                    } elseif ($selected_value_id === null && $is_default && !$has_default) {
                        $is_selected = true;
                        $has_default = true;
                    }

                    $output .= '<option ';
                    $output .= 'value="' . esc_attr($value_id) . '" ';
                    $output .= 'id="' . esc_attr($value_id) . '" ';
                    $output .= 'data-value="' . esc_attr($price) . '" ';
                    if ($is_selected) {
                        $output .= 'selected ';
                    }
                    $output .= '>';

                    $output .= esc_html($value['title']);
                    if ($price > 0 && !$this->prices_are_hidden('catalog')) {
                        $output .= ' (' . $this->currency_symbol . number_format($price, 2) . ')';
                    }

                    $output .= '</option>';
                }
            }

            $output .= '</select>';
            $output .= '</div>';
        }

        $output .= '</div>';

        return $output;
    }

    /**
     * Check if integration is enabled.
     *
     * @return bool True if enabled.
     */
    public function is_enabled()
    {
        return $this->enabled;
    }

    /**
     * Disable the integration.
     *
     * Useful for themes that provide their own options rendering.
     *
     * @return void
     */
    public function disable()
    {
        $this->enabled = false;

        // Remove hooks
        remove_filter('woocommerce_get_item_data', [$this, 'display_cart_item_options'], 20);
        remove_action('woocommerce_order_item_meta_start', [$this, 'display_order_item_options'], 20);
        remove_action('woocommerce_checkout_create_order_line_item', [$this, 'save_options_to_order_item'], 20);
        remove_filter('woocommerce_order_item_get_formatted_meta_data', [$this, 'format_options_meta_for_display'], 20);
    }

    /**
     * Enable the integration.
     *
     * @return void
     */
    public function enable()
    {
        $this->enabled = true;

        // Re-add hooks
        add_filter('woocommerce_get_item_data', [$this, 'display_cart_item_options'], 20, 2);
        add_action('woocommerce_order_item_meta_start', [$this, 'display_order_item_options'], 20, 4);
        add_action('woocommerce_checkout_create_order_line_item', [$this, 'save_options_to_order_item'], 20, 4);
        add_filter('woocommerce_order_item_get_formatted_meta_data', [$this, 'format_options_meta_for_display'], 20, 2);
    }
}

/**
 * Initialize the WooCommerce integration.
 *
 * This function should be called after plugins_loaded to ensure WooCommerce is available.
 *
 * @since 2.13.0
 * @return Rental_Options_WC_Integration The integration instance.
 */
function rental_options_wc_integration_init()
{
    return Rental_Options_WC_Integration::get_instance();
}

// Initialize on plugins_loaded with priority 20 (after WooCommerce)
add_action('plugins_loaded', 'rental_options_wc_integration_init', 20);

/**
 * Helper function to render product options HTML.
 *
 * Can be used in any theme template to display product options.
 *
 * @since 2.13.0
 *
 * @param int       $product_id Product ID.
 * @param bool|null $is_set     Whether product is a set (auto-detected if null).
 * @return string HTML output.
 */
function rental_render_product_options($product_id, $is_set = null)
{
    $integration = Rental_Options_WC_Integration::get_instance();

    if (!$integration->is_enabled()) {
        return '';
    }

    // Auto-detect if is_set
    if ($is_set === null) {
        $is_set = (bool) get_post_meta($product_id, '_rental_is_set', true);
    }

    return $integration->render_product_options_html($product_id, $is_set);
}

/**
 * Shortcode for rendering product options.
 *
 * Usage: [rental_product_options product_id="123"]
 *
 * @since 2.13.0
 *
 * @param array $atts Shortcode attributes.
 * @return string HTML output.
 */
function rental_product_options_shortcode($atts)
{
    $atts = shortcode_atts([
        'product_id' => 0,
        'is_set'     => null,
    ], $atts, 'rental_product_options');

    $product_id = intval($atts['product_id']);

    if (!$product_id) {
        global $product;
        if ($product && is_a($product, 'WC_Product')) {
            $product_id = $product->get_id();
        }
    }

    if (!$product_id) {
        return '';
    }

    $is_set = $atts['is_set'] !== null ? (bool) $atts['is_set'] : null;

    return rental_render_product_options($product_id, $is_set);
}
add_shortcode('rental_product_options', 'rental_product_options_shortcode');
