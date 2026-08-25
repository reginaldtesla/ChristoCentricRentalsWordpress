<?php
/**
 * Rental Product Options Integration Loader
 * 
 * This file integrates the new Product Options Manager with the existing
 * Rentopian Sync plugin, ensuring backward compatibility while enabling
 * theme-independent functionality.
 * 
 * @package Rentopian_Sync
 * @since 2.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Load and initialize the Product Options Manager class
 */
function rental_load_product_options_manager() {
    require_once plugin_dir_path(__FILE__) . 'class-rental-product-options-manager.php';
    // Actually instantiate the singleton to register all hooks
    Rental_Product_Options_Manager::get_instance();
}
add_action('plugins_loaded', 'rental_load_product_options_manager', 5);

/**
 * Helper function to get the Product Options Manager instance
 * 
 * @return Rental_Product_Options_Manager|null
 */
function rental_get_options_manager() {
    if (class_exists('Rental_Product_Options_Manager')) {
        return Rental_Product_Options_Manager::get_instance();
    }
    return null;
}

/**
 * Whether the active theme uses a dedicated Options column (Rentpro / Eventorian).
 * When true, the plugin skips adding options to item_data
 * so the theme's own column and markup are used.
 *
 * @return bool
 */
function rental_uses_rentpro_or_eventorian_options_column() {
    static $result = null;
    if ($result !== null) {
        return $result;
    }

    $template = get_template();
    $theme    = wp_get_theme();
    $name     = strtolower((string) $theme->get('Name'));
    $parent   = $theme->parent();
    $parent_name = $parent ? strtolower((string) $parent->get('Name')) : '';
    $result = in_array($template, ['rentpro', 'eventorian'], true)
        || strpos($name, 'rentpro') !== false
        || strpos($name, 'eventorian') !== false
        || strpos($parent_name, 'rentpro') !== false
        || strpos($parent_name, 'eventorian') !== false;
    return $result;
}

/**
 * Add options column to cart table for themes that don't have it
 * This ensures options display works on ALL themes
 */
// function rental_add_cart_options_column_header($columns) {
//     // Check if options column already exists (some themes may have it)
//     if (isset($columns['product-options'])) {
//         return $columns;
//     }
    
//     // Insert options column after product name
//     $new_columns = [];
//     foreach ($columns as $key => $value) {
//         $new_columns[$key] = $value;
//         if ($key === 'product-name') {
//             // Don't add a separate column - options display via woocommerce_get_item_data
//             // This is the WooCommerce standard way and works with all themes
//         }
//     }
    
//     return $columns;
// }
// Note: We're NOT adding a column, instead using woocommerce_get_item_data which is theme-independent


/**
 * Render editable options for cart page
 */
function rental_render_editable_cart_options($cart_item, $cart_item_key) {
    $manager = rental_get_options_manager();
    if (!$manager) {
        return '';
    }
    
    $product_id = $cart_item['variation_id'] ? $cart_item['variation_id'] : $cart_item['product_id'];
    $is_set = !empty($cart_item['rental_is_set']);
    
    // Check if product has options
    if ($is_set) {
        $options = $manager->get_set_options_with_defaults($product_id);
    } else {
        $options = $manager->get_product_options_with_defaults($product_id);
    }
    
    if (empty($options)) {
        return '';
    }
    
    return $manager->render_options_selectors($product_id, $is_set, $cart_item_key);
}

/**
 * Add hidden fields to add-to-cart form for selected options
 * This allows options to be submitted with the form
 */
function rental_add_options_hidden_fields() {
    global $product;
    
    if (!$product) {
        return;
    }
    
    $product_id = $product->get_id();
    $is_set = get_post_meta($product_id, '_rental_is_set', true);
    
    // Get options
    if ($is_set) {
        $options = function_exists('get_set_options') ? get_set_options($product_id) : [];
    } else {
        $options = function_exists('get_product_options') ? get_product_options($product_id) : [];
    }
    
    if (empty($options)) {
        return;
    }
    
    // Add nonce for option updates
    wp_nonce_field('rental_options_nonce', 'rental_options_nonce_field');
    
    // Add hidden field for tracking
    echo '<input type="hidden" name="rental_has_options" value="1" />';
}
add_action('woocommerce_before_add_to_cart_button', 'rental_add_options_hidden_fields', 25);

/**
 * Validate options before adding to cart
 *
 * DEPRECATED. The add-to-cart gate is now Rental_Options_Cart_Validator
 * (includes/product-options/), registered on the same hook at the same
 * priority. It reads the customer's submission from $_POST before falling back
 * to the cart line and the session, so it can no longer reject a value the
 * page is showing as selected. This wrapper is kept because stores and themes
 * remove or re-add the filter by function name.
 *
 * @deprecated Use Rental_Options_Cart_Validator.
 */
function rental_validate_options_before_add_to_cart($passed, $product_id, $quantity, $variation_id = 0) {
    if (!class_exists('Rental_Options_Cart_Validator')) {
        return $passed;
    }

    $validator = Rental_Options_Cart_Validator::instance();

    return $validator
        ? $validator->on_validate($passed, $product_id, $quantity, $variation_id)
        : $passed;
}

/**
 * Sync options from session to cart item on add to cart
 * This ensures selected options are properly transferred
 */
function rental_sync_options_on_add_to_cart($cart_item_key, $product_id, $quantity, $variation_id, $variation, $cart_item_data) {
    $actual_product_id = $variation_id ? $variation_id : $product_id;
    
    // Update the session storage with the cart item key
    $session_options = get_rental_session_data('rental_product_options_valuables', []);
    
    if (isset($session_options[$actual_product_id])) {
        // Copy to cart-item-specific key for easy retrieval
        $cart_session_key = 'rental_cart_item_options_' . $cart_item_key;
        set_rental_session_data($cart_session_key, $session_options[$actual_product_id]);
    }
}
add_action('woocommerce_add_to_cart', 'rental_sync_options_on_add_to_cart', 25, 6);

/**
 * Clean up session data when cart item is removed
 */
function rental_cleanup_options_on_cart_item_removed($cart_item_key, $cart) {
    // Remove cart-item-specific session data
    delete_rental_session_data('rental_cart_item_options_' . $cart_item_key);
}
add_action('woocommerce_cart_item_removed', 'rental_cleanup_options_on_cart_item_removed', 25, 2);

/**
 * Add options data to order for API submission
 * This ensures options are available when creating orders for the Core API
 */
function rental_add_options_to_order_for_api($item, $cart_item_key, $values, $order) {
    $manager = rental_get_options_manager();
    if (!$manager) {
        return;
    }
    
    $product_id = $values['variation_id'] ? $values['variation_id'] : $values['product_id'];
    $is_set = !empty($values['rental_is_set']);
    
    // Get formatted options for API
    $api_options = $manager->get_options_for_api($product_id, $is_set);
    
    if (!empty($api_options)) {
        $item->add_meta_data('_rental_api_options', $api_options, true);
    }
}
add_action('woocommerce_checkout_create_order_line_item', 'rental_add_options_to_order_for_api', 30, 4);

/**
 * Display options in admin order details
 */
// function rental_display_options_in_admin_order($item_id, $item, $product) {
//     $options = $item->get_meta('_rental_product_options', true);
    
//     if (empty($options) || !is_array($options)) {
//         return;
//     }
    
//     $currency_symbol = get_woocommerce_currency_symbol();
    
//     echo '<div class="rental-admin-options" style="margin-top: 10px; padding: 10px; background: #f9f9f9; border-radius: 4px;">';
//     echo '<strong>' . esc_html__('Rental Options:', 'rentopian-sync') . '</strong><br>';
    
//     foreach ($options as $option_data) {
//         $price_display = '';
//         if (isset($option_data['price']) && floatval($option_data['price']) != 0) {
//             $price = floatval($option_data['price']);
//             $price_display = $price > 0 
//                 ? ' (+' . $currency_symbol . number_format($price, 2) . ')'
//                 : ' (' . $currency_symbol . number_format($price, 2) . ')';
//         }
        
//         printf(
//             '&nbsp;&nbsp;• %s: %s%s<br>',
//             esc_html($option_data['option_title']),
//             esc_html($option_data['value_title'] ?? ''),
//             $price_display
//         );
//     }
    
//     echo '</div>';
// }
// add_action('woocommerce_before_order_itemmeta', 'rental_display_options_in_admin_order', 10, 3);

/**
 * Add options to email order item meta
 */
function rental_add_options_to_email_item($formatted_meta, $item) {
    // Already handled by the main class
    return $formatted_meta;
}

/**
 * Get selected options for a product (helper function for backward compatibility)
 */
function rental_get_selected_options($product_id) {
    $session_options = get_rental_session_data('rental_product_options_valuables', []);
    return isset($session_options[$product_id]) ? $session_options[$product_id] : [];
}

/**
 * Set selected option for a product (helper function for backward compatibility)
 */
function rental_set_selected_option($product_id, $option_id, $value_id, $value_title, $price, $option_title) {
    $session_options = get_rental_session_data('rental_product_options_valuables', []);
    
    $session_options[$product_id][$option_id] = [
        'value_id' => $value_id,
        'value_title' => $value_title,
        'price' => floatval($price),
        'option_title' => $option_title,
        'option_id' => $option_id
    ];
    
    set_rental_session_data('rental_product_options_valuables', $session_options);
}

/**
 * Log options changes for debugging (only in development mode)
 */
function rental_log_options_change($product_id, $option_id, $value_id) {
    if (defined('WP_DEBUG') && WP_DEBUG && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
        error_log(sprintf(
            '[Rentopian Options] Product: %d, Option: %d, Value: %d',
            $product_id,
            $option_id,
            $value_id
        ));
    }
}

/**
 * Initialize theme compatibility checks
 */
function rental_options_theme_compatibility() {
    // List of known theme-specific adjustments
    $theme = wp_get_theme();
    $theme_name = strtolower($theme->get('Name'));
    $parent_theme = $theme->parent();
    $parent_theme_name = $parent_theme ? strtolower($parent_theme->get('Name')) : '';
    
    // Storefront theme compatibility
    if (strpos($theme_name, 'storefront') !== false || strpos($parent_theme_name, 'storefront') !== false) {
        add_action('wp_head', function() {
            echo '<style>.storefront-sorting { margin-bottom: 2em; }</style>';
        });
    }
    
    // Astra theme compatibility
    if (strpos($theme_name, 'astra') !== false || strpos($parent_theme_name, 'astra') !== false) {
        // Astra generally works well with standard WooCommerce hooks
    }
    
    // OceanWP theme compatibility
    if (strpos($theme_name, 'oceanwp') !== false || strpos($parent_theme_name, 'oceanwp') !== false) {
        // OceanWP generally works well with standard WooCommerce hooks
    }
    
    // GeneratePress theme compatibility
    if (strpos($theme_name, 'generatepress') !== false || strpos($parent_theme_name, 'generatepress') !== false) {
        // GeneratePress generally works well with standard WooCommerce hooks
    }
}
add_action('after_setup_theme', 'rental_options_theme_compatibility');

