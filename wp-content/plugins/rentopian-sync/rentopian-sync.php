<?php

/**
 * @link              https://rentopian.com
 * @since             1.0.0
 * @package           rentopian-sync
 *
 * @wordpress-plugin
 * Plugin Name:       Rentopian Sync
 * Plugin URI:        https://rentopian.com
 * Description:       The plugin enables synchronization of Rentopian system users inventory with their company's website.
 * Version:           2.15.1
 * Author:            Rentopian, Inc.
 * Author URI:        https://rentopian.com/
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       rentopian-sync
 * Domain Path:       /languages
 */


if ( !defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

define('RENTOPIAN_SYNC_VERSION', '2.15.1');
define( 'RENTOPIAN_SYNC_DOCUMENTATION_URL', 'https://rentopian.com/system-assets/rentopian-sync-documentation' );

// Define RENTOPIAN_SYNC_PATH.
if ( !defined('RENTOPIAN_SYNC_PATH')) {
    define('RENTOPIAN_SYNC_PATH', __DIR__);
}

if ( !defined('RENTOPIAN_DATE_EXPIRE_TIME')) {
    define('RENTOPIAN_DATE_EXPIRE_TIME', 604800); // 7 days
}


if ( class_exists( 'WooCommerce_Single_Variations' )) {
    define('WC_SINGLE_VAR_VERSION', '1.4.2');
}

if (!defined('RENTAL_IMAGE_MAX_RETRIES')) {
    define('RENTAL_IMAGE_MAX_RETRIES', 3);
}

// Include plugin updater
require_once RENTOPIAN_SYNC_PATH . '/plugin_updater.php';

require_once RENTOPIAN_SYNC_PATH . '/includes/class-process-timer.php';
require_once RENTOPIAN_SYNC_PATH . '/includes/class-logger-with-timer.php';

// Price visibility gate. Loaded first so every module, template and partial
// below can ask it whether an amount may be shown.
require_once RENTOPIAN_SYNC_PATH . '/includes/rental-price-visibility.php';

require_once RENTOPIAN_SYNC_PATH . '/includes/sets/bootstrap.php';

// Shared run-log files + the unified log panel (both sync modules build on it)
require_once RENTOPIAN_SYNC_PATH . '/includes/sync-log/bootstrap.php';

// Unified file-sync module
require_once RENTOPIAN_SYNC_PATH . '/includes/file-sync/bootstrap.php';

// Background data-sync module (chunked catalog sync)
require_once RENTOPIAN_SYNC_PATH . '/includes/data-sync/bootstrap.php';

require_once RENTOPIAN_SYNC_PATH . '/includes/file-sync-delete/load.php';

// Attribute value groups (facet grouping data)
require_once RENTOPIAN_SYNC_PATH . '/includes/attribute-groups/bootstrap.php';

// plugin settings
require_once RENTOPIAN_SYNC_PATH . '/includes/admin-settings-ajax.php';

require_once RENTOPIAN_SYNC_PATH . '/includes/orders-heal-log/bootstrap.php';

// Keeps the date gate correct behind a full page cache. Loaded before
// functions.php, which calls into it from the date form endpoints.
require_once RENTOPIAN_SYNC_PATH . '/includes/page-cache/bootstrap.php';

// Stops any gateway from charging while the site collects quotes.
require_once RENTOPIAN_SYNC_PATH . '/includes/payment-gate/bootstrap.php';

// Include functions.php
require_once RENTOPIAN_SYNC_PATH . '/functions.php';

// Include error handler
require_once RENTOPIAN_SYNC_PATH . '/error_handler/ErrorHandler.php';
new ErrorHandler();

require_once RENTOPIAN_SYNC_PATH . '/components/rental-related-products.php';
require_once RENTOPIAN_SYNC_PATH . '/components/rental-upsell-products.php';
require_once RENTOPIAN_SYNC_PATH . '/components/rentpro-single-product-carousel.php';
require_once RENTOPIAN_SYNC_PATH . '/includes/models/RTDelivery.php';

// WooCommerce Integration for Rental Options (theme-independent rendering)
// This class provides standard WooCommerce hooks for options display in cart/checkout/order pages
require_once RENTOPIAN_SYNC_PATH . '/includes/class-rental-options-wc-integration.php';

// Product Options Manager - handles cart item merging and options management
require_once RENTOPIAN_SYNC_PATH . '/includes/rental-product-options-integration.php';

// Product Options module - selection resolution and the add-to-cart gate
require_once RENTOPIAN_SYNC_PATH . '/includes/product-options/bootstrap.php';

// Checkout Layout System (custom checkout layout builder)
require_once RENTOPIAN_SYNC_PATH . '/includes/checkout/checkout-layout-loader.php';

require_once RENTOPIAN_SYNC_PATH . '/includes/class-rental-polylang-integration.php';
require_once RENTOPIAN_SYNC_PATH . '/includes/translation/rental-translation-bootstrap.php';

require_once RENTOPIAN_SYNC_PATH . '/includes/class-rentopian-availability-filter.php';

// Hook for creating the tables
function rental_plugin_activate() {
    if (is_multisite()) {
        global $wpdb;

        foreach ($wpdb->get_col("SELECT blog_id FROM $wpdb->blogs") as $blog_id) {
            switch_to_blog($blog_id);

            rental_create_tables();

            register_sale_products_template();

            Rental_Sets_Admin_Settings::apply_release_defaults();

            restore_current_blog();
        }

    } else {
        rental_create_tables();

        register_sale_products_template();

        Rental_Sets_Admin_Settings::apply_release_defaults();
    }

    set_default_settings();
}
register_activation_hook(__FILE__, 'rental_plugin_activate');

// Hook for removing the tables
function rental_plugin_uninstall() {
    if (is_multisite()) {
        global $wpdb;

        foreach ($wpdb->get_col("SELECT blog_id FROM $wpdb->blogs") as $blog_id) {
            switch_to_blog($blog_id);

            rental_remove_tables();

            restore_current_blog();
        }

    } else {
        rental_remove_tables();
    }
}
register_uninstall_hook(__FILE__, 'rental_plugin_uninstall');

include_once( ABSPATH . 'wp-admin/includes/plugin.php' );

// WP Rocket compatibility
if ( ! function_exists( 'WP_Rocket\\Helpers\\cache\\dynamic_cookie\\cache_dynamic_cookie' ) ) {
    require_once RENTOPIAN_SYNC_PATH . '/includes/wp-rocket-helper.php';
}

// Hook for creating the tables in new blog
function rental_plugin_new_blog($blog_id) {
    if (is_plugin_active_for_network(plugin_basename(RENTOPIAN_SYNC_PATH . '/rentopian-sync.php'))) {
        switch_to_blog($blog_id);

        rental_create_tables();

        restore_current_blog();
    }
}
add_action('wpmu_new_blog', 'rental_plugin_new_blog', 10, 1);

//if (basename(RENTOPIAN_SYNC_PATH) == "rentopian-sync") {

if (is_plugin_active('woocommerce/woocommerce.php')) {

    // Start session
    // function rental_start_session() {
    //     // if ( !session_id() && !headers_sent()) {
    //     //     session_start();
    //     // }

    //     if (false === get_option('rental_encryption_key')) {
    //         update_option('rental_encryption_key', bin2hex(random_bytes(32)));
    //     }
    // }
    // add_action('init', 'rental_start_session', 1);

    function rental_init() {

        if (false === get_option('rental_encryption_key')) {
            update_option('rental_encryption_key', bin2hex(random_bytes(32)));
        }
    }
    add_action('init', 'rental_init', 1);

    // Hook for adding plugin section in WordPress admin menu
    add_action('admin_menu', 'rental_add_pages');

    // Hook for enqueuing scripts to admin page
    add_action('admin_enqueue_scripts', 'rental_enqueue_scripts_to_admin');

    // Hook for creating ajax functions
    add_action('wp_ajax_rental_sync', 'wp_ajax_rental_sync');
    add_action('wp_ajax_rental_upload_images', 'wp_ajax_rental_upload_images');
    add_action('wp_ajax_rental_upload_images_manual', 'wp_ajax_rental_upload_images_manual');
    add_action('wp_ajax_rental_change_file_sync_type', 'wp_ajax_rental_change_file_sync_type');

    // add_action('wp_ajax_rental_delete_orphaned_files', 'wp_ajax_rental_delete_orphaned_files');
    // add_action( 'wp_ajax_nopriv_rental_delete_orphaned_files', 'wp_ajax_rental_delete_orphaned_files' );

    // add_action('wp_ajax_rental_delete_all_product_files', 'wp_ajax_rental_delete_all_product_files');
    // add_action( 'wp_ajax_nopriv_rental_delete_all_product_files', 'wp_ajax_rental_delete_all_product_files' );

    add_action('wp_ajax_rental_sync_yoast_seo_plugin', 'wp_ajax_rental_sync_yoast_seo_plugin');
    add_action('wp_ajax_rental_sync_yoast_seo_plugin_in_chunks', 'wp_ajax_rental_sync_yoast_seo_plugin_in_chunks');
    add_action('wp_ajax_nopriv_rental_sync_yoast_seo_plugin_in_chunks', 'wp_ajax_rental_sync_yoast_seo_plugin_in_chunks');
    add_action('wp_ajax_rental_delete_log_item', 'wp_ajax_rental_delete_log_item');
    add_action('wp_ajax_rental_delete_runtime_log', 'wp_ajax_rental_delete_runtime_log');
    add_action('wp_ajax_rental_paginate_runtime_log', 'wp_ajax_rental_paginate_runtime_log');
    add_action('wp_ajax_rental_upload_images_try_again', 'wp_ajax_rental_upload_images_try_again');
    add_action('wp_ajax_rental_paginate_orders_log', 'wp_ajax_rental_paginate_orders_log');
    add_action('wp_ajax_rental_resend_order', 'wp_ajax_rental_resend_order');
    add_action('wp_ajax_rental_save_log_settings', 'wp_ajax_rental_save_log_settings');

    add_action( 'wp_ajax_update_rental_shipping_cost', 'wp_ajax_update_rental_shipping_cost' );
    add_action( 'wp_ajax_nopriv_update_rental_shipping_cost', 'wp_ajax_update_rental_shipping_cost' );

    // add_action( 'wp_ajax_rental_trigger_calculate_shipping', 'wp_ajax_rental_trigger_calculate_shipping' );
    // add_action( 'wp_ajax_nopriv_rental_trigger_calculate_shipping', 'wp_ajax_rental_trigger_calculate_shipping' );

    // add_action('wp_ajax_rental_delivery', 'wp_ajax_rental_delivery');
    // add_action('wp_ajax_nopriv_rental_delivery', 'wp_ajax_rental_delivery');
    add_action('wp_ajax_rental_damage_waiver', 'wp_ajax_rental_damage_waiver');
    add_action('wp_ajax_nopriv_rental_damage_waiver', 'wp_ajax_rental_damage_waiver');

    add_action('wp_ajax_rental_apply_coupon', 'wp_ajax_rental_apply_coupon');
    add_action('wp_ajax_nopriv_rental_apply_coupon', 'wp_ajax_rental_apply_coupon');
    add_action('wp_ajax_rental_remove_coupon', 'wp_ajax_rental_remove_coupon');
    add_action('wp_ajax_nopriv_rental_remove_coupon', 'wp_ajax_rental_remove_coupon');

    add_action('wp_ajax_rental_get_multiplier', 'wp_ajax_rental_get_multiplier');
    add_action('wp_ajax_nopriv_rental_get_multiplier', 'wp_ajax_rental_get_multiplier');
    add_action('wp_ajax_rental_dates', 'wp_ajax_rental_dates');
    add_action('wp_ajax_nopriv_rental_dates', 'wp_ajax_rental_dates');

    // send post request with ajax from date form (rental_date_start, rental_date_end, rental_zip)
    add_action('wp_ajax_rental_date_form_api', 'wp_ajax_rental_date_form_api');
    add_action('wp_ajax_nopriv_rental_date_form_api', 'wp_ajax_rental_date_form_api');

    add_action('wp_ajax_rental_replace_full_address_in_checkout', 'wp_ajax_rental_replace_full_address_in_checkout');
    add_action('wp_ajax_nopriv_rental_replace_full_address_in_checkout', 'wp_ajax_rental_replace_full_address_in_checkout');

    add_action('wp_ajax_rental_update_checkout_address_fields', 'rental_update_checkout_address_fields');
    add_action('wp_ajax_nopriv_rental_update_checkout_address_fields', 'rental_update_checkout_address_fields');


    // set optional items related
    add_action('wp_ajax_rental_update_set_items', 'wp_ajax_rental_update_set_items');
    add_action('wp_ajax_nopriv_rental_update_set_items', 'wp_ajax_rental_update_set_items');

    add_action('wp_ajax_rental_set_items_default_update', 'wp_ajax_rental_set_items_default_update');
    add_action('wp_ajax_nopriv_rental_set_items_default_update', 'wp_ajax_rental_set_items_default_update');

    add_action('wp_ajax_rental_update_set_already_selected_items', 'wp_ajax_rental_update_set_already_selected_items');
    add_action('wp_ajax_nopriv_rental_update_set_already_selected_items', 'wp_ajax_rental_update_set_already_selected_items');

    // sets with addons related
    add_action('wp_ajax_rental_update_set_items_addon_with_variants_optional', 'wp_ajax_rental_update_set_items_addon_with_variants_optional');
    add_action('wp_ajax_nopriv_rental_update_set_items_addon_with_variants_optional', 'wp_ajax_rental_update_set_items_addon_with_variants_optional');

    add_action('wp_ajax_rental_set_items_addons_with_variants_optional_default_update', 'wp_ajax_rental_set_items_addons_with_variants_optional_default_update');
    add_action('wp_ajax_nopriv_rental_set_items_addons_with_variants_optional_default_update', 'wp_ajax_rental_set_items_addons_with_variants_optional_default_update');

    // [DEPRECATED] Legacy set-price calculator retired.
    // Total Price for both the top (`.entry-price-wrap`) and bottom
    // (`.rntp-summary`) panels is now computed exclusively by the JS
    // `updateSummary()` + `writeTopTotal()` pipeline in
    // rental-sets-modern.js. The AJAX endpoint stays defined in
    // functions.php (also commented out) for grep-able provenance but
    // is not registered, so wp_ajax / wp_ajax_nopriv requests for it
    // 404 cleanly. Re-enable both lines + the function + the JS
    // callers if you ever need to fall back to the server-side path.
    // add_action('wp_ajax_rental_calculate_set_price_based_on_items_price', 'wp_ajax_rental_calculate_set_price_based_on_items_price');
    // add_action('wp_ajax_nopriv_rental_calculate_set_price_based_on_items_price', 'wp_ajax_rental_calculate_set_price_based_on_items_price');
    

    // product addons related
    add_action('wp_ajax_rental_product_with_addons_fix_variants', 'wp_ajax_rental_product_with_addons_fix_variants');
    add_action('wp_ajax_nopriv_rental_product_with_addons_fix_variants', 'wp_ajax_rental_product_with_addons_fix_variants');

    // order total update on payment tip amount change
    add_action('wp_ajax_rental_update_total_on_tip_amount_change', 'wp_ajax_rental_update_total_on_tip_amount_change');
    add_action('wp_ajax_nopriv_rental_update_total_on_tip_amount_change', 'wp_ajax_rental_update_total_on_tip_amount_change');


    add_action('wp_ajax_rental_get_min_order_notice', 'rental_get_min_order_notice');
    add_action('wp_ajax_nopriv_rental_get_min_order_notice', 'rental_get_min_order_notice');


    add_action('wp_ajax_rental_update_selected_start_end_times', 'rental_update_selected_start_end_times');
    add_action('wp_ajax_nopriv_rental_update_selected_start_end_times', 'rental_update_selected_start_end_times');

    add_action('wp_ajax_rental_override_checkout_fields_labels', 'rental_override_checkout_fields_labels');
    add_action('wp_ajax_nopriv_rental_override_checkout_fields_labels', 'rental_override_checkout_fields_labels');

    add_action( 'wp_ajax_rental_update_shipping_method', 'rental_update_shipping_method');
    add_action( 'wp_ajax_nopriv_rental_update_shipping_method', 'rental_update_shipping_method');

    /**
     * Register the AJAX action for updating rental dates summary
     * 
     * Handles both logged-in and guest users
     */
    add_action('wp_ajax_rental_update_dates_summary', 'rental_update_dates_summary_handler');
    add_action('wp_ajax_nopriv_rental_update_dates_summary', 'rental_update_dates_summary_handler');

    // if hourly mode is false, then render start/end dates
    if (
        get_option('rental_synchronized_product_type') != "hourly"
    ) {
        // get date form request with ajax
        add_action('wp_ajax_rental_get_date_form', 'wp_ajax_rental_get_date_form');
        add_action('wp_ajax_nopriv_rental_get_date_form', 'wp_ajax_rental_get_date_form');
    }


    if ( get_option('rental_synchronized_product_type') == "hourly" ) {

        $start_end_date_expire_time = time() + RENTOPIAN_DATE_EXPIRE_TIME;

        if (!isset($_COOKIE['rental_zip'])) {

            $encrypted_rental_zip = encrypt_data(true, get_option('rental_encryption_key'));
            $_COOKIE['rental_zip'] = $encrypted_rental_zip;
            setcookie('rental_zip', $encrypted_rental_zip, $start_end_date_expire_time, "/", "", false, false);
            // $_COOKIE['rental_zip'] = true;
            // setcookie('rental_zip', true, time() + (10800), "/", "", false, false);
        }

        // a cookie to check in order to check in js
        if (!isset($_COOKIE['rental_hourly_mode'])) {
            $_COOKIE['rental_hourly_mode'] = true;
            setcookie('rental_hourly_mode', true, time() + (10800), "/", "", false, false);
        }

        // collect hourly product data
        add_action('wp_ajax_rental_collect_hourly_product_data', 'wp_ajax_rental_collect_hourly_product_data');
        add_action('wp_ajax_nopriv_rental_collect_hourly_product_data', 'wp_ajax_rental_collect_hourly_product_data');

        // collect hourly product variation data
        add_action('wp_ajax_rental_get_hourly_product_variation_id', 'wp_ajax_rental_get_hourly_product_variation_id');
        add_action('wp_ajax_nopriv_rental_get_hourly_product_variation_id', 'wp_ajax_rental_get_hourly_product_variation_id');

    } else {
        // if not in hourly mode disable js cart cookie
        if (isset($_COOKIE['rental_hourly_mode'])) {
            unset($_COOKIE['rental_hourly_mode']);
            setcookie('rental_hourly_mode', false, time() - (31556952), "/", "", false, false);
        }

        if (isset($_COOKIE["rental_hourly_start_date"])) {
            unset($_COOKIE["rental_hourly_start_date"]);
            setcookie('rental_hourly_start_date', false, time() - (31556952), "/", "", false, false);
        }

        if (isset($_COOKIE["rental_hourly_end_date"])) {
            unset($_COOKIE["rental_hourly_end_date"]);
            setcookie('rental_hourly_end_date', false, time() - (31556952), "/", "", false, false);
        }

        if (get_option('rental_hide_zip')) {
            if (isset($_COOKIE['rental_zip'])) {

                $encrypted_rental_zip = encrypt_data(true, get_option('rental_encryption_key'));
                $_COOKIE['rental_zip'] = $encrypted_rental_zip;
            }
        }
    }

    
    // update product option
    add_action('wp_ajax_rental_update_product_option', 'wp_ajax_rental_update_product_option');
    add_action('wp_ajax_nopriv_rental_update_product_option', 'wp_ajax_rental_update_product_option');

    // BATCH update multiple product options at once (for rapid changes on single product page)
    add_action('wp_ajax_rental_update_product_options_batch', 'wp_ajax_rental_update_product_options_batch');
    add_action('wp_ajax_nopriv_rental_update_product_options_batch', 'wp_ajax_rental_update_product_options_batch');

    // Sync confirmation: re-read session options and force-write into cart item data
    add_action('wp_ajax_rental_sync_cart_item_options', 'wp_ajax_rental_sync_cart_item_options');
    add_action('wp_ajax_nopriv_rental_sync_cart_item_options', 'wp_ajax_rental_sync_cart_item_options');

    // update order option of a product
    add_action('wp_ajax_rental_update_order_option', 'wp_ajax_rental_update_order_option');
    add_action('wp_ajax_nopriv_rental_update_order_option', 'wp_ajax_rental_update_order_option');

    // update order option of a set
    add_action('wp_ajax_rental_update_order_option_of_set', 'wp_ajax_rental_update_order_option_of_set');
    add_action('wp_ajax_nopriv_rental_update_order_option_of_set', 'wp_ajax_rental_update_order_option_of_set');

    // check selected products' options / check selected order options cart page
    add_action('wp_ajax_rental_check_selected_options', 'wp_ajax_rental_check_selected_options');
    add_action('wp_ajax_nopriv_rental_check_selected_options', 'wp_ajax_rental_check_selected_options');

    // check selected product's options
    add_action('wp_ajax_rental_check_selected_options_of_product', 'wp_ajax_rental_check_selected_options_of_product');
    add_action('wp_ajax_nopriv_rental_check_selected_options_of_product', 'wp_ajax_rental_check_selected_options_of_product');

    // get selected product's options
    add_action('wp_ajax_rental_get_all_options_of_product', 'wp_ajax_rental_get_all_options_of_product');
    add_action('wp_ajax_nopriv_rental_get_all_options_of_product', 'wp_ajax_rental_get_all_options_of_product');

    // get cart products' selected options
    add_action('wp_ajax_rental_get_all_options_of_cart_products', 'wp_ajax_rental_get_all_options_of_cart_products');
    add_action('wp_ajax_nopriv_rental_get_all_options_of_cart_products', 'wp_ajax_rental_get_all_options_of_cart_products');

    // get order specific options of products
    add_action('wp_ajax_rental_get_order_options', 'wp_ajax_rental_get_order_options');
    add_action('wp_ajax_nopriv_rental_get_order_options', 'wp_ajax_rental_get_order_options');

    // get order specific options of sets
    add_action('wp_ajax_rental_get_order_options_of_sets', 'wp_ajax_rental_get_order_options_of_sets');
    add_action('wp_ajax_nopriv_rental_get_order_options_of_sets', 'wp_ajax_rental_get_order_options_of_sets');

    // get the subtotal effected with order specific options
    add_action('wp_ajax_rental_get_order_options_effected_subtotal', 'wp_ajax_rental_get_order_options_effected_subtotal');
    add_action('wp_ajax_nopriv_rental_get_order_options_effected_subtotal', 'wp_ajax_rental_get_order_options_effected_subtotal');

    // add product's options to cart from single product page
    add_action('wp_ajax_rental_add_options_to_cart', 'wp_ajax_rental_add_options_to_cart');
    add_action('wp_ajax_nopriv_rental_add_options_to_cart', 'wp_ajax_rental_add_options_to_cart');

    // check a product/variant's availability

    add_action('wp_ajax_rental_check_product_availability', 'wp_ajax_rental_check_product_availability');
    add_action('wp_ajax_nopriv_rental_check_product_availability', 'wp_ajax_rental_check_product_availability');

    // rental send wishlist form with ajax
    add_action('wp_ajax_rental_send_wishlist_form', 'wp_ajax_rental_send_wishlist_form');
    add_action('wp_ajax_nopriv_rental_send_wishlist_form', 'wp_ajax_rental_send_wishlist_form');

    // rental replace textual labels
    add_action('wp_ajax_rental_replace_cart_page_textual_labels', 'wp_ajax_rental_replace_cart_page_textual_labels');
    add_action('wp_ajax_nopriv_rental_replace_cart_page_textual_labels', 'wp_ajax_rental_replace_cart_page_textual_labels');
    add_action('wp_ajax_rental_replace_mini_cart_textual_labels_values', 'wp_ajax_rental_replace_mini_cart_textual_labels_values');
    add_action('wp_ajax_nopriv_rental_replace_mini_cart_textual_labels_values', 'wp_ajax_rental_replace_mini_cart_textual_labels_values');

    add_action('wp_ajax_rental_replace_coupon_text_label', 'rental_replace_coupon_text_label');
    add_action('wp_ajax_nopriv_rental_replace_coupon_text_label', 'rental_replace_coupon_text_label');

    add_action('wp_ajax_rental_get_invalid_coupon_response', 'rental_get_invalid_coupon_response');
    add_action('wp_ajax_nopriv_rental_get_invalid_coupon_response', 'rental_get_invalid_coupon_response');


    // rental replace textual labels
    add_action('wp_ajax_rental_update_file_sync_settings', 'wp_ajax_rental_update_file_sync_settings');
    add_action('wp_ajax_nopriv_rental_update_file_sync_settings', 'wp_ajax_rental_update_file_sync_settings');

    add_filter( 'deprecated_constructor_trigger_error', 'rental_suppress_deprecated_errors' );
    add_filter( 'deprecated_function_trigger_error', 'rental_suppress_deprecated_errors' );
    add_filter( 'deprecated_file_trigger_error', 'rental_suppress_deprecated_errors' );
    add_filter( 'deprecated_argument_trigger_error', 'rental_suppress_deprecated_errors' );
    add_filter( 'deprecated_hook_trigger_error', 'rental_suppress_deprecated_errors' );

    function rental_suppress_deprecated_errors(){

        return get_option('rental_runtime_log_setting') == 1;
    }

    if (($rental_sync_status = get_option('rental_synchronize_status')) && !rental_check_api_key(get_option('rental_api_key'))) {
        // adding notices
        function rental_wrong_api_key() {

            if (isset($_COOKIE['rental_start_date'])) {
                unset($_COOKIE['rental_start_date']);
                setcookie('rental_start_date', '', time() - (31556952), "/", "", false, true);
            }

            if (isset($_COOKIE['rental_zip'])) {
                unset($_COOKIE['rental_zip']);
                setcookie('rental_zip',false, time() - (31556952), "/", "", false, false);
            }

            ?>
            <div class="error">
                <p><?php _e('Wrong API key provided', 'rentopian-sync'); ?></p>
            </div>
            <?php
        }
        add_action('admin_notices', 'rental_wrong_api_key');

        if (!is_admin() && !str_contains($_SERVER["REQUEST_URI"], 'wp-login.php')) {
            ?>
            <ul class="woocommerce_error woocommerce-error wc-stripe-error" style="width:80%; margin:20px auto">
                <li>
                    <h4><?php _e('Wrong API key provided.', 'rentopian-sync'); ?></h4>
                    <?php _e("The \"Rentopian Sync\" plugin requires an API key to operate correctly. Please refer the plugin documentation for key retrieval guide.", "rentopian-sync"); ?>
                </li>
            </ul>
            <?php
        }

    } elseif ($rental_sync_status) {
        ob_start();
        // Add rentopian_sync_location_select shortcode
        // the short code : [rentopian_sync_location_select]
        function rental_location_select_shortcode() {

            if ( !get_option('rental_select_division')) {
                return '';
            }
            $divisions = get_option('rental_divisions');
            if ( !is_array($divisions) || count($divisions) < 2) {
                return '';
            }

            if (isset($_POST['rental_division_id']) && $_POST['rental_division_id']) {
                setcookie('rental_division_id', $_POST['rental_division_id'], time() + (10800), "/", "", false, true);
                $_COOKIE['rental_division_id'] = $_POST['rental_division_id'];

                if (get_option('rental_synchronized_product_type') != "hourly") {
                    // not hourly mode
                    submit_date_range_after_location_select();
                }
                rental_get_product_settings();

                global $woocommerce;
                $woocommerce->cart->empty_cart();
            }
            wp_enqueue_style('rental-divisions-modal-style');
            wp_enqueue_script('rental-divisions-modal-script');
            ob_start();
            include('components/rental_divisions_modal.php');
            return ob_get_clean();
        }
        add_shortcode('rentopian_sync_location_select', 'rental_location_select_shortcode');

        // re-submit date range selection after location select
        function submit_date_range_after_location_select() {
            $opt_hide_zip = get_option('rental_hide_zip');
            $opt_show_location = get_option('rental_show_location');

            $decrypted_rental_zip = false;
            if (isset($_COOKIE['rental_zip']) && $_COOKIE['rental_zip']) {
                $decrypted_rental_zip = decrypt_data($_COOKIE['rental_zip'], get_option('rental_encryption_key'));
            }
            $decrypted_rental_address = "";
            if (isset($_COOKIE['rental_address']) && $_COOKIE['rental_address']) {
                $decrypted_rental_address = decrypt_data($_COOKIE['rental_address'], get_option('rental_encryption_key'));
            }

            // handling the zip code
            $zip = $decrypted_rental_zip;

            if ($opt_hide_zip) {
                $zip = true;
            }

            // handling the delivery input address
            $address = '';
            if ($opt_show_location) {
                if (isset($_COOKIE['rental_address']) && $_COOKIE['rental_address']) {
                    $address = trim($decrypted_rental_address);
                }
            }

            $decrypted_rental_start_date = "";
            if (isset($_COOKIE['rental_start_date']) && $_COOKIE['rental_start_date']) {
                $decrypted_rental_start_date = decrypt_data($_COOKIE['rental_start_date'], get_option('rental_encryption_key'));
            }

            $decrypted_rental_end_date = "";
            if (isset($_COOKIE['rental_end_date']) && $_COOKIE['rental_end_date']) {
                $decrypted_rental_end_date = decrypt_data($_COOKIE['rental_end_date'], get_option('rental_encryption_key'));
            }

            // handling the start/end date
            $end_date = $decrypted_rental_end_date;
            $start_date = $decrypted_rental_start_date;

            rental_validate_dates_form_and_get_products_data_init(get_option('rental_hide_time_pickers'), $opt_hide_zip, $opt_show_location, $start_date, $end_date, $address, $zip);
        }

        // Add rentopian_sync_date_form shortcode
        function rental_date_form_shortcode() {
            rental_create_date_form();
        }
        add_shortcode('rentopian_sync_date_form_shortcode', 'rental_date_form_shortcode');

        // Add rental_wishlist_form shortcode
        function rental_wishlist_shortcode() {
            wp_enqueue_script('rental-script');
            wp_enqueue_script('rental-wishlist-form-script');
            wp_enqueue_style('rental-wishlist-style');
            ob_start();
            echo '<div id="rntp-wishlist-form-placeholder">';
            include('components/rental_wishlist_form.php');
            echo '</div>';
            return ob_get_clean();
        }
        add_shortcode('rentopian_sync_wishlist_shortcode', 'rental_wishlist_shortcode');

        // if hourly mode is false, then render start/end dates
        if (get_option('rental_synchronized_product_type') != "hourly" && get_option('rental_form_layout') !== "in-cart") {
            if (get_option('rental_dates_on_checkout', 0) != 1 || get_option('rental_allow_overbook', 1) != 1) {
                // Hook for add date form to shop page
                add_action('woocommerce_before_shop_loop', 'rental_create_date_form', 10);
                // Hook for add date form to single product page
                add_action('woocommerce_before_single_product', 'rental_create_date_form', 10);
                // Hook for add date form to cart page
                add_action('woocommerce_before_cart', 'rental_create_date_form', 10);
            }
        }

        if (get_option('rental_dates_on_checkout', 0) == 1 && get_option('rental_allow_overbook', 1) == 1 && get_option('rental_checkout_layout_mode', 'classic') !== 'modern') {
            add_action('woocommerce_before_checkout_form', 'rental_create_date_form', 10);
        }

        // Hook for enqueue scripts
        add_action('wp_enqueue_scripts', 'rental_enqueue_scripts', 900);
        // Hook for validate checkout process
        add_action('woocommerce_checkout_process', 'rental_checkout_process');

        add_action('woocommerce_checkout_process', 'rental_validate_payjunction_gateway_plugin');

        // Clear the cart after checkout. Runs when the thank-you (order-received) page
        // is rendered. WooCommerce also clears when the customer lands on order-received
        // with a valid ?key= (wc_clear_cart_after_payment on template_redirect). If
        // items sometimes stay in the cart, the customer likely never reached the
        // order-received URL (e.g. payment gateway redirects elsewhere or they closed
        // the browser). Ensure gateways use the WooCommerce return URL including ?key=.
        function rental_clear_cart() {
            if ( isset( WC()->cart ) && WC()->cart ) {
                WC()->cart->empty_cart();
            }
        }
        add_action( 'woocommerce_thankyou', 'rental_clear_cart' );

        // Hook for sending a new order after it is created in WP
        add_action('woocommerce_new_order', 'rental_create_order');
        // Hook for send order payment
        add_action('woocommerce_payment_complete', 'rental_pay_order', 10, 1);
        // Hook for send delete order request
        add_action('woocommerce_order_status_failed', 'rental_delete_order', 10, 1);
        add_action('woocommerce_order_status_cancelled', 'rental_delete_order', 10, 1);

        // Hook for overriding the labels and placeholders of existing fields
        add_filter('woocommerce_checkout_fields', 'rental_override_checkout_fields');
        // add_filter('woocommerce_review_order_after_cart_contents', 'rental_woocommerce_review_order_after_cart_contents');


        // Runs before output so every date form and the add-to-cart gate agree
        // on whether the stored dates still clear the orders offset.
        add_action( 'template_redirect', 'rental_clear_blocked_start_date_cookie', 1 );

        add_action( 'template_redirect', 'rental_clear_start_end_date_after_order_submit', 1 );
        function rental_clear_start_end_date_after_order_submit() {
            if ( empty( $_COOKIE['rental_order_submitted'] ) ) {
                return;
            }

            if ( is_checkout() && is_wc_endpoint_url( 'order-received' ) ) {
                return;
            }

            foreach ( [ 'rental_start_date', 'rental_end_date', 'rental_order_submitted' ] as $cookie_name ) {
                unset( $_COOKIE[ $cookie_name ] );
                setcookie( $cookie_name, false, time() - YEAR_IN_SECONDS, COOKIEPATH, COOKIE_DOMAIN, false, false );
            }
        }

        
        // Category slug update needs category filter URLs redirect to be updated as well
        // add_action('template_redirect', function() {
            
        //     $redirects = get_option('rental_category_slug_redirects', []);
        //     if (!is_array($redirects) || empty($redirects)) {
        //         return;
        //     }
        
        //     // request path info
        //     $uri = isset($_SERVER['REQUEST_URI']) ? wp_unslash($_SERVER['REQUEST_URI']) : '';
        //     $path = parse_url($uri, PHP_URL_PATH) ?: '';
        //     $path = untrailingslashit($path);
        //     $last_segment = $path ? basename($path) : '';
        
        //     // prefer WP's queried slug when available
        //     $queried_slug = '';
        //     if (is_tax('product_cat')) {
        //         $queried = get_queried_object();
        //         if ($queried && !is_wp_error($queried) && !empty($queried->slug)) {
        //             $queried_slug = $queried->slug;
        //         }
        //     }
        
        //     // candidate keys to try (order matters)
        //     $candidate_keys = [];
        //     if ($queried_slug) $candidate_keys[] = $queried_slug;
        //     if ($last_segment && $last_segment !== $queried_slug) $candidate_keys[] = $last_segment;
        //     if ($path) $candidate_keys[] = ltrim($path, '/');
        
        //     // find mapping
        //     $new_slug = '';
        //     foreach ($candidate_keys as $k) {
        //         if ($k !== '' && isset($redirects[$k]) && $redirects[$k]) {
        //             $new_slug = $redirects[$k];
        //             break;
        //         }
        //     }
        //     if (empty($new_slug)) {
        //         return;
        //     }
        
        //     // avoid loop
        //     if ($new_slug === $queried_slug || $new_slug === $last_segment) {
        //         return;
        //     }
        
        //     // resolve and redirect
        //     $term = get_term_by('slug', sanitize_title($new_slug), 'product_cat');
        //     if (!$term || is_wp_error($term)) {
        //         return;
        //     }
        
        //     $dest = get_term_link($term);
        //     if (is_wp_error($dest) || !$dest) {
        //         return;
        //     }
        
        //     // perform 301 redirect to category URL
        //     wp_safe_redirect($dest, 301);
        //     exit;
        // }, 1);
        


        // Hook for add Special Terms options on checkout page
        function rental_add_special_terms_on_checkout_page() {
            $special_terms = rental_get_special_terms_html();
            if ($special_terms) {
                echo '<div class="rental-special-terms"><h3>' . esc_html__('Special Terms', 'rentopian-sync') . '</h3>' . $special_terms . '</div>';
            }
        }

        if ($rental_special_terms_placement = get_option('rental_special_terms_placement', 'special_terms_on_top')) {

            if (
                get_option('rental_dates_on_checkout', 0) != 1 
                || get_option('rental_allow_overbook', 1) != 1 
                || get_option('rental_checkout_layout_mode', 'classic') !== 'modern'
            ) {

                if ($rental_special_terms_placement === 'special_terms_on_top') {
                    add_action('woocommerce_checkout_before_customer_details', 'rental_add_special_terms_on_checkout_page');
                } else {
                    add_action('woocommerce_checkout_after_customer_details', 'rental_add_special_terms_on_checkout_page');
                }
            }
        }
        

        // Change product stock status
        function rental_product_is_in_stock($stock, $product) {
            return $stock;
            /*

            if (is_admin() || !isset($_COOKIE['rental_start_date'])) {
                return $stock;
            }

            $supported_divisions = rental_get_supported_divisions();
            if (!$supported_divisions) {
                return $stock;
            }


            if ( !($product instanceof WC_Product_Variation)) {
                if (get_post_meta($product->get_id(), '_rental_is_set', true)) {

                    // check set's division to be in supported divisions
                    if ($sets_divs = rental_get_sets_divisions()) {
                        foreach($sets_divs as $set_div) {

                            if ($set_div['id'] == $product->get_id() && in_array($set_div['rental_division_id'] ,$supported_divisions)) {

                                return true;
                            }
                        }
                    }
                }

                if ($product->is_type('variable')) {
                    return $stock;
                }
            }

            if ($prods_divs = rental_get_products_variants_divisions()) {
                foreach($prods_divs as $prod_div) {

                    if ($prod_div['id'] == $product->get_id() && in_array($prod_div['rental_division_id'] ,$supported_divisions)) {

                        return true;
                    }
                }
            }

            return false;
            */
        }
        add_filter('woocommerce_product_is_in_stock', 'rental_product_is_in_stock', 10, 2);

        // Add Overbooking message on product page
        function rental_overbook_text_on_product_page() {
            $overbook_text = get_option('rental_overbook_text', '');
            $allow_overbook =  get_option('rental_allow_overbook', 0);

            if ( !$overbook_text || $allow_overbook) {
                return;
            }

            global $wpdb, $product;

            $product_id = $product->get_id();
            if ($product->is_type('variable')) {
                $variants = $wpdb->get_results("SELECT `post_id`, `meta_value` AS `inv_id` FROM $wpdb->postmeta WHERE `meta_key` = '_rental_inventory_id' AND `post_id` IN (" . implode(",", $product->get_visible_children()) . ")");
                $inv_ids = [];
                foreach ($variants as $variant) {
                    $inv_ids[] = $variant->inv_id;
                }
                if (is_null($availability = rental_check_availability($inv_ids))
                    || empty($availability['inventories']) || !$availability['allow_overbook']) {
                    return;
                }
                $quantity = [];
                foreach ($variants as $variant) {
                    if (isset($availability['inventories'][$variant->inv_id])) {
                        $quantity[$variant->post_id] = $availability['inventories'][$variant->inv_id]['quantity'];
                    }
                }
                foreach (WC()->cart->cart_contents as $cart_item) {
                    if ($cart_item['product_id'] == $product_id && isset($quantity[$cart_item['variation_id']])) {
                        $quantity[$cart_item['variation_id']] -= $cart_item['quantity'];
                    }
                }
            } else {
                $allow_overbook =  get_option('rental_allow_overbook', 0);
                if (get_post_meta($product_id, '_rental_is_set', true)
                    || !($inv_id = get_post_meta($product_id, '_rental_inventory_id', true))
                    || !$allow_overbook
                    || is_null($availability = rental_check_availability([$inv_id]))
                    || empty($availability['inventories'][$inv_id])) {
                    return;
                }
                $quantity = $availability['inventories'][$inv_id]['quantity'];
                foreach (WC()->cart->cart_contents as $cart_item) {
                    if ($cart_item['product_id'] == $product_id) {
                        $quantity -= $cart_item['quantity'];
                    }
                }
            }
            ?>
            <script>
                jQuery(document).ready(function ($) {
                    var overbookingMessage = '<?php echo $overbook_text; ?>';
                    var availableQuantity = JSON.parse('<?php echo json_encode($quantity); ?>');

                    var $variant = $('input[name=variation_id]');
                    var $quantity = $('input[name=quantity]');
                    function checkQuantity() {
                        var $message = $('#rental-overbooking-message');
                        var quantity = parseInt($quantity.val());
                        var variantId, available;
                        if ($variant.length) {
                            variantId = $variant.val();
                            available = parseInt(availableQuantity[variantId]);
                        } else {
                            variantId = 1;
                            available = parseInt(availableQuantity);
                        }
                        if ( !quantity || !variantId || available !== available || quantity <= available) {
                            $message.hide();
                        } else {
                            if ($message.length) {
                                $message.show();
                            } else {
                                $('form.cart div.quantity').before('<p id="rental-overbooking-message" class="alert-color">' + overbookingMessage + '</p>');
                            }
                        }
                    }
                    if ($variant.length) {
                        $variant.on('change', checkQuantity);
                    }
                    $quantity.on('change', checkQuantity);
                });
            </script>
            <?php
        }
        add_filter('woocommerce_after_add_to_cart_form', 'rental_overbook_text_on_product_page', 10);

        // Add Overbooking message in cart
        function rental_overbook_text_in_cart_items($item_name, $cart_item) {
            $overbook_text = get_option('rental_overbook_text', '');
            if (isset($cart_item['rental_overbook']) && !empty($overbook_text) && !get_option('rental_allow_overbook', 0)) {
                $item_name .= '<p class="alert-color">' . $overbook_text . '</p>';
            }
            return $item_name;
        }
        add_filter('woocommerce_cart_item_name', 'rental_overbook_text_in_cart_items', 99, 2);

        // Validate cart item
        function rental_validate_cart_item($product_id, $variation_id, $quantity, $cart_item_key = null, $is_update = false, $return_response = false) {
            global $wpdb, $rental_tables;
            $rental_set_relations = $wpdb->prefix . $rental_tables["set_relations"];

            $set_items_as_add_ons = [];
            $add_ons_required = false;
            $inv_id = 0;
            $inventories = [];
            $add_ons = [];
            if (get_post_meta($product_id, '_rental_is_set', true)) {

                // Preserve composite-GROUP children the modern configurator
                // submitted in $_POST['rental_add_ons']. The synthesis below
                // rebuilds rental_add_ons from `_rental_set_items` postmeta,
                // which only holds simple + selectable items + their addons —
                // NEVER group children (those live in
                // `_rental_set_grouped_items` and are only known from the
                // form payload).
                if ($cart_item_key && function_exists('WC') && WC()->cart) {
                    foreach (WC()->cart->cart_contents as $rental_cart_child) {
                        if (!is_array($rental_cart_child)) { continue; }
                        $rental_cart_child_parent = isset($rental_cart_child['rental_add_on_of'])
                            ? (string) $rental_cart_child['rental_add_on_of']
                            : '';
                        if ($rental_cart_child_parent !== (string) $cart_item_key) { continue; }
                        $rental_cart_child_lookup = !empty($rental_cart_child['variation_id'])
                            ? (int) $rental_cart_child['variation_id']
                            : (int) ($rental_cart_child['product_id'] ?? 0);
                        if ($rental_cart_child_lookup <= 0) { continue; }
                        $rental_cart_child_inv = (int) get_post_meta($rental_cart_child_lookup, '_rental_inventory_id', true);
                        if ($rental_cart_child_inv > 0) {
                            $inventories[] = $rental_cart_child_inv;
                        }
                    }
                }

                // Group-level separate_price lives in postmeta, keyed by
                // group uid — the form payload doesn't ship it. Build a
                // uid → separate_price map so each captured group child can
                // be stamped with its group's flag (the price engine reads
                // it back from the cart line).
                $rental_group_separate_price_map = [];
                $rental_grouped_items_meta = get_post_meta($product_id, '_rental_set_grouped_items', true);
                if (is_array($rental_grouped_items_meta)) {
                    foreach ($rental_grouped_items_meta as $rental_grp_meta) {
                        if (!is_array($rental_grp_meta)) { continue; }
                        $rental_grp_meta_uid = isset($rental_grp_meta['uid']) ? (string) $rental_grp_meta['uid'] : '';
                        if ('' !== $rental_grp_meta_uid) {
                            $rental_group_separate_price_map[$rental_grp_meta_uid] = !empty($rental_grp_meta['separate_price']) ? 1 : 0;
                        }
                    }
                }

                $rental_browser_group_children = [];
                if (!empty($_POST['rental_add_ons']) && is_array($_POST['rental_add_ons'])) {
                    foreach ($_POST['rental_add_ons'] as $rental_posted_add_on) {
                        if (!is_array($rental_posted_add_on)) { continue; }
                        $rental_is_group_child =
                            (isset($rental_posted_add_on['item_type']) && $rental_posted_add_on['item_type'] === 'grouped_child')
                            || !empty($rental_posted_add_on['rental_set_group_is_child'])
                            || !empty($rental_posted_add_on['rental_set_group_uid']);
                        if ($rental_is_group_child) {
                            // Stamp the group's separate_price from postmeta.
                            $rental_posted_group_uid = isset($rental_posted_add_on['rental_set_group_uid'])
                                ? (string) $rental_posted_add_on['rental_set_group_uid']
                                : '';
                            if ('' !== $rental_posted_group_uid && isset($rental_group_separate_price_map[$rental_posted_group_uid])) {
                                $rental_posted_add_on['separate_price'] = $rental_group_separate_price_map[$rental_posted_group_uid];
                            }
                            $rental_browser_group_children[] = $rental_posted_add_on;

                            // Group-child inv_id contribution to
                            // $inventories. Composite group children
                            // live in _rental_set_grouped_items
                            // postmeta — NOT _rental_set_items — so
                            // the main set-items loop below
                            // doesn't iterate over them. 
                            $rental_group_child_inv_id = isset($rental_posted_add_on['inv_id'])
                                ? (int) $rental_posted_add_on['inv_id']
                                : 0;
                            if ($rental_group_child_inv_id <= 0) {
                                $rental_group_child_lookup_id = isset($rental_posted_add_on['variant_id']) && (int) $rental_posted_add_on['variant_id'] > 0
                                    ? (int) $rental_posted_add_on['variant_id']
                                    : (int) ($rental_posted_add_on['product_id'] ?? 0);
                                if ($rental_group_child_lookup_id > 0) {
                                    $rental_group_child_inv_id = (int) get_post_meta($rental_group_child_lookup_id, '_rental_inventory_id', true);
                                }
                            }
                            if ($rental_group_child_inv_id > 0) {
                                $inventories[] = $rental_group_child_inv_id;
                            }
                        }
                    }
                }

                $rental_set_max_qty = get_post_meta( $product_id, '_rental_max_quantity', true);

                if (!empty($rental_set_max_qty)) {
                    if ($quantity > intval($rental_set_max_qty)) {

                        $max_qty_validation_msg = __('Maximum available quantity for this item is : ', 'rentopian-sync') . $rental_set_max_qty;
                        wc_add_notice($max_qty_validation_msg, 'error');
                        if ($return_response) {
                            return $max_qty_validation_msg;
                        }
                        return false;
                    }
                }

                // Selection-aware read: at add-to-cart the customer's set
                // option picks come from THEIR session overlay (classic
                // flow) / are overridden by the submitted payload form-first
                // lookups (modern flow). Resolving through the session store
                // keeps the cart snapshot matching what the customer saw —
                // the global postmeta is never mutated at request time.
                $set_items_as_add_ons = function_exists('rental_set_items_resolved')
                    ? rental_set_items_resolved($product_id)
                    : get_post_meta($product_id, '_rental_set_items', true);

                // Resolve default selections for hidden items server-side
                // (lock in selectable / addon defaults, drop options with no
                // configured default). This replaces the page-load default
                // AJAX so hidden sets build correctly even when the modern
                // configurator is suppressed. No-op for fully visible sets.
                if (is_array($set_items_as_add_ons) && function_exists('rental_set_apply_hidden_defaults')) {
                    $set_items_as_add_ons = rental_set_apply_hidden_defaults($product_id, $set_items_as_add_ons);
                }

                // rental set id
                $set_id = $wpdb->get_var("SELECT `rental_id` FROM $rental_set_relations WHERE `id` = " . $product_id);

                // Hidden composite GROUPS (entity-3) can't be configured by
                // the customer, so resolve their default item(s) server-side
                // and add them as group children — their price contributes to
                // the total just like the visible flow. Appended to the
                // browser-captured group children so the existing re-append +
                // add-to-cart path inserts them. No-op when no group is hidden.
                //
                // Deliberately NOT added to $inventories: a hidden default is
                // an extra resolved server-side and must never gate the SET's
                // availability check — a stale/mismatched group inv_id would
                // otherwise fail the whole availability call and block the
                // add. Wrapped so a resolution error can never break the cart.
                if (class_exists('Rental_Sets_Selection', false)) {
                    try {
                        $rental_hidden_group_children = Rental_Sets_Selection::resolve_hidden_group_children($product_id, (int) $set_id);
                        foreach ($rental_hidden_group_children as $rental_hg_child) {
                            $rental_browser_group_children[] = $rental_hg_child;
                        }
                    } catch (\Throwable $rental_hg_e) {
                        if (class_exists('Rental_Sets_Logger', false)) {
                            Rental_Sets_Logger::warn('hidden_group_resolve_error', $rental_hg_e->getMessage(), array('set' => (int) $product_id));
                        }
                    }
                }

                $item_based_total = get_post_meta($product_id, '_rental_item_based_total', true);

                // NO-THANKS OPT-OUT CASCADE (server-side)
                // The modern configurator ships
                // `$_POST['rental_set_opt_outs']` — an array of section
                // UIDs the customer declined via "No Thanks, I don't
                // need this".
                $rental_set_opt_outs = array();
                if ( ! empty( $_POST['rental_set_opt_outs'] ) && is_array( $_POST['rental_set_opt_outs'] ) ) {
                    foreach ( $_POST['rental_set_opt_outs'] as $rental_opt_out_uid ) {
                        if ( ! is_string( $rental_opt_out_uid ) || '' === $rental_opt_out_uid ) { continue; }
                        $rental_set_opt_outs[ $rental_opt_out_uid ] = true;
                    }
                }

                // ADDON-level opt-outs. The configurator ships
                // `$_POST['rental_set_addon_opt_outs']` — one key per
                // OPTIONAL addon the customer declined, shaped
                // "{parent_item_product_id}:{addon_product_id}:{addon_rental_inv_id}".
                // The synthesizer rebuilds addons from postmeta, so without
                // this the declined addon would be re-added to the cart even
                // though the form omitted it.
                $rental_set_addon_opt_outs = array();
                if ( ! empty( $_POST['rental_set_addon_opt_outs'] ) && is_array( $_POST['rental_set_addon_opt_outs'] ) ) {
                    foreach ( $_POST['rental_set_addon_opt_outs'] as $rental_addon_opt_out_key ) {
                        if ( ! is_string( $rental_addon_opt_out_key ) || '' === $rental_addon_opt_out_key ) { continue; }
                        $rental_set_addon_opt_outs[ $rental_addon_opt_out_key ] = true;
                    }
                }

                // OPTIONAL SET ITEMS ARE OPT-IN
                // The synthesizer below rebuilds every child from postmeta,
                // so an item is included unless something removes it. That
                // is right for the package contents, but wrong for an
                // OPTIONAL item: whenever the configurator's fields fail to
                // reach the server — a JS error, a theme that submits the
                // cart form on its own, a bare add-to-cart from outside the
                // product page — the customer gets billed for extras they
                // never chose, and the "No Thanks" opt-out never arrives to
                // say otherwise. So inclusion, not exclusion, is what the
                // submission has to state.
                //
                // Scoped to sets the modern configurator renders: it is the
                // only UI that can express the choice. Classic sets list
                // their items read-only and keep including all of them.
                // Cart updates are excluded too — they re-validate lines
                // that already exist rather than deciding what to create.
                $rental_set_optional_opt_in = ! $is_update
                    && class_exists( 'Rental_Sets_Renderer', false )
                    && Rental_Sets_Renderer::will_render( $product_id );

                // What the submission selected, in the three shapes the
                // configurator can speak: section uids (modern selections),
                // section uids (v2 payload), and product ids (legacy
                // `rental_add_ons` rows). Collected unconditionally so the
                // selectable gate below can read them on a cart update too.
                $rental_set_selected_uids = array();
                $rental_set_selected_pids = array();
                if ( ! empty( $_POST['rental_set_selections'] ) && is_array( $_POST['rental_set_selections'] ) ) {
                    foreach ( array_keys( $_POST['rental_set_selections'] ) as $rental_sel_uid ) {
                        $rental_set_selected_uids[ (string) $rental_sel_uid ] = true;
                    }
                }

                if ( class_exists( 'Rental_Sets_Payload_Parser', false ) ) {
                    $rental_v2_payload = Rental_Sets_Payload_Parser::parse_from_post();
                    if ( is_array( $rental_v2_payload ) ) {
                        foreach ( array( 'groups', 'selectables', 'simples' ) as $rental_v2_key ) {
                            if ( empty( $rental_v2_payload[ $rental_v2_key ] ) || ! is_array( $rental_v2_payload[ $rental_v2_key ] ) ) {
                                continue;
                            }
                            foreach ( $rental_v2_payload[ $rental_v2_key ] as $rental_v2_bucket ) {
                                // An empty `selections` list is a declined
                                // section, not a selected one.
                                if ( ! is_array( $rental_v2_bucket ) || empty( $rental_v2_bucket['uid'] ) || empty( $rental_v2_bucket['selections'] ) ) {
                                    continue;
                                }
                                $rental_set_selected_uids[ (string) $rental_v2_bucket['uid'] ] = true;
                            }
                        }
                    }
                }

                if ( ! empty( $_POST['rental_add_ons'] ) && is_array( $_POST['rental_add_ons'] ) ) {
                    foreach ( $_POST['rental_add_ons'] as $rental_sel_entry ) {
                        if ( ! is_array( $rental_sel_entry ) ) { continue; }
                        // Addon rows belong to their parent item, not to
                        // a set item of their own.
                        if ( ! empty( $rental_sel_entry['parent_set_item_product_id'] ) ) { continue; }
                        $rental_sel_pid = isset( $rental_sel_entry['product_id'] ) ? (int) $rental_sel_entry['product_id'] : 0;
                        if ( $rental_sel_pid > 0 ) {
                            $rental_set_selected_pids[ $rental_sel_pid ] = true;
                        }
                    }
                }

                // SELECTABLE ITEMS — "choose a variation" gate.
                // A selectable set item offers the customer a list of
                // variants to pick from. Forcing that pick is only
                // legitimate when the item actually enters the cart AND a
                // real choice exists, so the gate clears the item when:
                //
                //   - a stored or submitted variant already answers it;
                //   - the section was declined ("No Thanks") — there is
                //     nothing left to choose;
                //   - the item is OPTIONAL and the submission didn't select
                //     it, so the opt-in filter below drops it from the cart;
                //   - the list holds a single option, or options that are
                //     the product itself (variant_id 0) — neither is a
                //     variation choice.
                //
                // Mirrors the addon gate below and
                // Rental_Sets_Rule_Selectable_Items so all three agree.
                if (!get_post_meta($product_id, '_rental_hide_items_on_website', true)) {

                    // Only the modern configurator can express "No Thanks",
                    // so only its sets treat an unselected optional item as
                    // a deliberate skip. Classic sets list every item and
                    // keep requiring the choice.
                    $rental_sel_declinable = class_exists('Rental_Sets_Renderer', false)
                        && Rental_Sets_Renderer::will_render($product_id);

                    $set_items_with_errors_product_names = '';
                    foreach($set_items_as_add_ons as $set_item) {

                        if (empty($set_item['optional_items']) || !is_array($set_item['optional_items'])) {
                            continue;
                        }

                        // Hidden items are never offered to the customer —
                        // rental_set_apply_hidden_defaults resolves them.
                        if (!empty($set_item['hidden'])) {
                            continue;
                        }

                        $rental_sel_product_id = isset($set_item['product_id']) ? (int) $set_item['product_id'] : 0;
                        if (!$rental_sel_product_id) {
                            continue;
                        }

                        // Answered by the stored selection.
                        if (!empty($set_item['variant_id'])
                            || (isset($set_item['has_selected']) && 1 == $set_item['has_selected'])
                        ) {
                            continue;
                        }

                        $rental_sel_uid = 'sel-'
                            . (isset($set_item['inventory_sets_id']) ? (int) $set_item['inventory_sets_id'] : 0)
                            . '-' . $rental_sel_product_id;

                        // Declined via "No Thanks".
                        if (isset($rental_set_opt_outs[$rental_sel_uid])) {
                            continue;
                        }

                        // Answered by the submission. The pick rides in the
                        // form and can reach the server before the async
                        // persist AJAX writes it to the stored selection.
                        if (class_exists('Rental_Sets_Selection', false)
                            && Rental_Sets_Selection::form_variant_for_selectable($rental_sel_uid, $rental_sel_product_id) > 0
                        ) {
                            continue;
                        }

                        // Optional and unselected: skipping is a valid
                        // answer, and the opt-in filter below removes the
                        // item rather than adding it unresolved.
                        $rental_sel_chosen = isset($rental_set_selected_uids[$rental_sel_uid])
                            || isset($rental_set_selected_pids[$rental_sel_product_id]);
                        if ($rental_sel_declinable && empty($set_item['required']) && !$rental_sel_chosen) {
                            continue;
                        }

                        // Only a genuine multi-variant list is a choice.
                        $rental_sel_variant_options = 0;
                        foreach ($set_item['optional_items'] as $rental_sel_option) {
                            if (is_array($rental_sel_option) && !empty($rental_sel_option['variant_id'])) {
                                $rental_sel_variant_options++;
                            }
                        }
                        if ($rental_sel_variant_options <= 1) {
                            continue;
                        }

                        $item_with_error_product = wc_get_product($rental_sel_product_id);
                        if ($item_with_error_product) {
                            $set_items_with_errors_product_names .= ' ' . $item_with_error_product->get_name() . ' ,';
                        }
                    }

                    if ($set_items_with_errors_product_names) {

                        $set_items_with_errors_product_names = rtrim($set_items_with_errors_product_names, ' ,');

                        $error_msg = 'Please, choose a variation from the following items: <br/>' . $set_items_with_errors_product_names;
                        wc_add_notice(__($error_msg, 'rentopian-sync'), 'error');
                        if ($return_response) {
                            return $error_msg;
                        }
                        return false;
                    }

                }

                foreach ($set_items_as_add_ons as $i => $set_item) {

                    // Item identity, shared by the opt-out and opt-in checks.
                    // Selectables key on their synthetic wrapper uid; every
                    // other item keys on its own uid, falling back to
                    // division+inventory for rows that predate uids. Mirrors
                    // Rental_Sets_Section_Presenter so the uid the browser
                    // sends is the uid resolved here.
                    $rental_item_uid = '';
                    if ( ! empty( $set_item['optional_items'] ) ) {
                        $rental_item_sid = isset( $set_item['inventory_sets_id'] ) ? (int) $set_item['inventory_sets_id'] : 0;
                        $rental_item_pid = isset( $set_item['product_id'] ) ? (int) $set_item['product_id'] : 0;
                        $rental_item_uid = 'sel-' . $rental_item_sid . '-' . $rental_item_pid;
                    } elseif ( ! empty( $set_item['uid'] ) ) {
                        $rental_item_uid = (string) $set_item['uid'];
                    } else {
                        $rental_item_div = isset( $set_item['division_id'] ) ? (int) $set_item['division_id'] : 0;
                        $rental_item_inv = isset( $set_item['inv_id'] ) ? (int) $set_item['inv_id'] : 0;
                        if ( $rental_item_inv > 0 ) {
                            $rental_item_uid = $rental_item_div . '-' . $rental_item_inv;
                        }
                    }

                    // Opt-out check FIRST so an opted-out item never
                    // pays the cost of variant lookup, addon iteration,
                    // or availability resolution.
                    if ( ! empty( $rental_set_opt_outs )
                        && '' !== $rental_item_uid
                        && isset( $rental_set_opt_outs[ $rental_item_uid ] )
                    ) {
                        if ( class_exists( 'Rental_Sets_Logger', false ) ) {
                            Rental_Sets_Logger::warn(
                                'opt_out_skipped',
                                'No-Thanks: skipping set item per rental_set_opt_outs',
                                array(
                                    'set'      => (int) $product_id,
                                    'item_uid' => $rental_item_uid,
                                    'item_idx' => (int) $i,
                                )
                            );
                        }
                        // REMOVE the declined item from the array, not just
                        // skip its processing. `$set_items_as_add_ons`
                        // becomes `$all_addon_items` (the cart children)
                        // verbatim below — a bare `continue` would leave
                        // the opted-out item in the array and it would
                        // still be added to the cart at its postmeta
                        // quantity. Unsetting is what makes "No Thanks"
                        // actually exclude the line.
                        unset( $set_items_as_add_ons[ $i ] );
                        continue;
                    }

                    // Optional items enter the cart only when the submission
                    // names them. Hidden items are exempt: they are never
                    // shown, so the customer has no way to select them and
                    // rental_set_apply_hidden_defaults resolves them instead.
                    if ( $rental_set_optional_opt_in
                        && empty( $set_item['required'] )
                        && empty( $set_item['hidden'] )
                    ) {
                        $rental_item_product = isset( $set_item['product_id'] ) ? (int) $set_item['product_id'] : 0;
                        $rental_item_chosen  = ( '' !== $rental_item_uid && isset( $rental_set_selected_uids[ $rental_item_uid ] ) )
                            || ( $rental_item_product > 0 && isset( $rental_set_selected_pids[ $rental_item_product ] ) );

                        if ( ! $rental_item_chosen ) {
                            if ( class_exists( 'Rental_Sets_Logger', false ) ) {
                                Rental_Sets_Logger::warn(
                                    'optional_not_selected',
                                    'Optional set item skipped: the submission did not select it',
                                    array(
                                        'set'      => (int) $product_id,
                                        'item_uid' => $rental_item_uid,
                                        'product'  => $rental_item_product,
                                        'item_idx' => (int) $i,
                                    )
                                );
                            }
                            unset( $set_items_as_add_ons[ $i ] );
                            continue;
                        }
                    }

                    // FORM-FIRST VARIANT LOOKUP (selectable set items)
                    // The modern configurator persists the customer's pick
                    // to postmeta via an async AJAX (rental_update_set_items)
                    // AND submits it directly in rental_add_ons[i][variant_id].
                    // If the customer changes a variant and submits before
                    // the persist AJAX has reached the server, postmeta is
                    // STALE — and the line below would silently restore the
                    // previous variant.
                    if ( ! empty( $_POST['rental_add_ons'] ) && is_array( $_POST['rental_add_ons'] ) ) {
                        foreach ( $_POST['rental_add_ons'] as $rental_post_entry ) {
                            if ( ! is_array( $rental_post_entry ) ) { continue; }
                            if ( ! empty( $rental_post_entry['parent_set_item_product_id'] ) ) { continue; }
                            if ( ! empty( $rental_post_entry['rental_set_group_uid'] ) ) { continue; }
                            if ( isset( $rental_post_entry['item_type'] ) && 'grouped_child' === $rental_post_entry['item_type'] ) { continue; }
                            $rental_post_product = isset( $rental_post_entry['product_id'] ) ? (int) $rental_post_entry['product_id'] : 0;
                            if ( $rental_post_product !== (int) $set_item['product_id'] ) { continue; }
                            $rental_post_variant = isset( $rental_post_entry['variant_id'] ) ? (int) $rental_post_entry['variant_id'] : 0;
                            if ( $rental_post_variant > 0 ) {
                                $set_item['variant_id'] = $rental_post_variant;
                            }
                            // FORM-FIRST QUANTITY LOOKUP. An OPTIONAL simple
                            // item exposes a +/- stepper so the customer can
                            // take fewer than the package max. That choice
                            // rides in rental_add_ons[i][quantity]; without
                            // honoring it here the synthesizer would restore
                            // the postmeta (package-max) quantity and the
                            // cart line would ignore the customer's stepper.
                            $rental_post_qty = isset( $rental_post_entry['quantity'] ) ? (int) $rental_post_entry['quantity'] : 0;
                            if ( $rental_post_qty > 0 ) {
                                $set_item['quantity'] = $rental_post_qty;
                            }
                            break;
                        }
                    }

                    // The modern configurator submits a selectable pick with
                    // `parent_set_item_product_id` set, which the loop above
                    // skips as an addon row, and it can arrive before the
                    // persist AJAX writes the pick to the stored selection.
                    // Fill the variant from the submission so the cart line
                    // carries the variant the customer actually chose. Only
                    // fills an otherwise-unresolved item.
                    if ( empty( $set_item['variant_id'] )
                        && ! empty( $set_item['optional_items'] )
                        && class_exists( 'Rental_Sets_Selection', false )
                    ) {
                        $rental_form_variant = Rental_Sets_Selection::form_variant_for_selectable(
                            $rental_item_uid,
                            (int) $set_item['product_id']
                        );
                        if ( $rental_form_variant > 0 ) {
                            $set_item['variant_id'] = $rental_form_variant;
                        }
                    }

                    if ($set_item['variant_id']) {
                        $set_item['inv_id'] = get_post_meta($set_item['variant_id'], '_rental_inventory_id', true);
                    } else {
                        $set_item['variant_id'] = 0;
                        $set_item['inv_id'] = get_post_meta($set_item['product_id'], '_rental_inventory_id', true);
                    }

                    // Fixed-bundle sets fold included items into the bundle
                    // fee, so their in-set price is dropped here. A
                    // separate_price item is the exception: it is billed on
                    // top of the bundle, so keep its in-set rate intact.
                    if (!$item_based_total && empty($set_item['separate_price'])) {
                        $set_item['price'] = 0;
                    }

                    // if (
                    //     isset($set_item['optional_items'])
                    //     && $set_item['optional_items']
                    //     && isset($set_item['price'])
                    // ) {
                    //     $set_item['price'] = $set_item['price'];
                    // }

                    $set_item['required'] = $set_item['required'] ?? false;
                    $set_item['set_id'] = $set_id;
                    $set_item['parent_set_id'] = $product_id;

                    // NO-THANKS OPT-OUT (addon-level). Remove each declined
                    // OPTIONAL addon before validation/pricing so it is
                    // neither validated nor added to the cart. A REQUIRED
                    // addon ignores the opt-out (defense in depth — the UI
                    // never offers "No Thanks" for required addons).
                    if ( ! empty( $rental_set_addon_opt_outs ) && ! empty( $set_item['addons'] ) && is_array( $set_item['addons'] ) ) {
                        foreach ( $set_item['addons'] as $rental_ao_idx => $rental_ao ) {
                            if ( ! is_array( $rental_ao ) || ! empty( $rental_ao['required'] ) ) { continue; }
                            $rental_ao_key = (int) $set_item['product_id']
                                . ':' . (int) ( $rental_ao['product_id'] ?? 0 )
                                . ':' . (int) ( $rental_ao['rental_inv_id'] ?? 0 );
                            if ( isset( $rental_set_addon_opt_outs[ $rental_ao_key ] ) ) {
                                unset( $set_item['addons'][ $rental_ao_idx ] );
                                if ( class_exists( 'Rental_Sets_Logger', false ) ) {
                                    Rental_Sets_Logger::warn(
                                        'addon_opt_out_skipped',
                                        'No-Thanks: skipping optional addon per rental_set_addon_opt_outs',
                                        array(
                                            'set'   => (int) $product_id,
                                            'key'   => $rental_ao_key,
                                        )
                                    );
                                }
                            }
                        }
                        $set_item['addons'] = array_values( $set_item['addons'] );
                    }

                    if (isset($set_item['addons']) && $set_item['addons']) {

                        $set_item_addons_with_errors_product_names = '';
                        foreach ($set_item['addons'] as $set_item_addon_index => $set_item_addon) {

                            $set_item_addon_product = wc_get_product($set_item_addon['product_id']);

                            // ── FORM-FIRST VARIANT LOOKUP ────────────────────
                            // The modern set configurator submits the
                            // customer's variant picks via rental_add_ons[i]
                            // instead of mutating _rental_set_items postmeta.
                            // The classic flow still mutates postmeta via
                            // its AJAX bridge, so its $set_item_addon already
                            // carries variant_id by the time we get here.
                            //
                            // To support BOTH paths without breaking either,
                            // we look in $_POST['rental_add_ons'] first for
                            // an entry matching this (parent_set_item_product_id,
                            // addon_product_id) pair. If found with a non-zero
                            // variant_id we treat the addon as having that
                            // variant chosen; otherwise we fall back to whatever
                            // postmeta says — which is what the validator did
                            // before, so classic carts are byte-identical.
                            $form_addon_variant_id = 0;
                            if ( ! empty( $_POST['rental_add_ons'] ) && is_array( $_POST['rental_add_ons'] ) ) {
                                foreach ( $_POST['rental_add_ons'] as $post_addon ) {
                                    if ( ! is_array( $post_addon ) ) { continue; }
                                    $post_parent = isset( $post_addon['parent_set_item_product_id'] )
                                        ? (int) $post_addon['parent_set_item_product_id'] : 0;
                                    $post_product = isset( $post_addon['product_id'] )
                                        ? (int) $post_addon['product_id'] : 0;
                                    if ( $post_parent !== (int) $set_item['product_id'] ) { continue; }
                                    if ( $post_product !== (int) $set_item_addon['product_id'] ) { continue; }
                                    $post_variant = isset( $post_addon['variant_id'] )
                                        ? (int) $post_addon['variant_id'] : 0;
                                    if ( $post_variant > 0 ) {
                                        $form_addon_variant_id = $post_variant;
                                        break;
                                    }
                                }
                            }
                            if ( $form_addon_variant_id > 0 ) {
                                // Promote the form's variant_id into the
                                // local copy so all the downstream checks
                                // in this block — `is_type('variable')`,
                                // the `parent_set_item_*` enrichment below,
                                // and the addons-with-errors check above —
                                // see the customer's intent.
                                $set_item_addon['variant_id'] = $form_addon_variant_id;

                                // PRICING for a REAL multi-variant choice.
                                // Per the add-on rules: when the addon offers
                                // a genuine variant selection (2+ variants)
                                // and the customer picks one, price comes
                                // from THAT variant — not the addon's flat
                                // custom price. We force inherit_price for
                                // this row only and clear the stale base
                                // price so the resolver reads the chosen
                                // variant's `_price`.
                                //
                                // A single-/no-variant addon (e.g. a tool set
                                // with one base option) is NOT a variant
                                // choice: its configured pricing mode stands
                                // (inherit_price=0 → the custom price like
                                // $2). Gating on count(>1) is what keeps the
                                // $2 custom-price addon from being rebilled
                                // at its variant's product price.
                                $rental_addon_variant_count = ( isset( $set_item_addon['variants_optional'] ) && is_array( $set_item_addon['variants_optional'] ) )
                                    ? count( $set_item_addon['variants_optional'] )
                                    : 0;
                                if ( $rental_addon_variant_count > 1 ) {
                                    $set_item_addon['inherit_price'] = 1;
                                    $set_item_addon['price']         = 0;
                                }
                            }

                            // Resolve the addon's variant from its optional variants when none is set.
                            // Mirrors the frontend, which auto-selects the sole option and records the
                            // user's pick for multi-option addons. A simple-product option
                            // (variant_id = 0) is a valid resolution and keeps the addon as the product.
                            if (
                                empty($set_item_addon['variant_id'])
                                && isset($set_item_addon['variants_optional'])
                                && is_array($set_item_addon['variants_optional'])
                                && $set_item_addon['variants_optional']
                            ) {
                                $resolved_addon_option = null;
                                foreach ($set_item_addon['variants_optional'] as $addon_variant_option) {
                                    if (is_array($addon_variant_option) && !empty($addon_variant_option['is_selected'])) {
                                        $resolved_addon_option = $addon_variant_option;
                                        break;
                                    }
                                }
                                if (!$resolved_addon_option && count($set_item_addon['variants_optional']) === 1) {
                                    $resolved_addon_option = reset($set_item_addon['variants_optional']);
                                }

                                if (is_array($resolved_addon_option)) {
                                    $set_item_addon['variant_id'] = !empty($resolved_addon_option['variant_id']) ? $resolved_addon_option['variant_id'] : 0;
                                    if (!empty($resolved_addon_option['product_id'])) {
                                        $set_item_addon['product_id'] = $resolved_addon_option['product_id'];
                                    }
                                    $set_item_addon['rental_variant_id'] = $resolved_addon_option['rental_variant_id'] ?? ($set_item_addon['rental_variant_id'] ?? 0);
                                    $set_item_addon['rental_product_id'] = $resolved_addon_option['rental_product_id'] ?? ($set_item_addon['rental_product_id'] ?? 0);
                                }
                            }

                            $set_item_addon_product = wc_get_product($set_item_addon['product_id']);

                            // Require a choice only for a genuine multi-variant addon left unresolved;
                            // a single option or a simple-product option is not a "choose a variation".
                            $addon_real_variant_opts = 0;
                            if (isset($set_item_addon['variants_optional']) && is_array($set_item_addon['variants_optional'])) {
                                foreach ($set_item_addon['variants_optional'] as $addon_variant_option) {
                                    if (is_array($addon_variant_option) && !empty($addon_variant_option['variant_id'])) {
                                        $addon_real_variant_opts++;
                                    }
                                }
                            }

                            // Only a REQUIRED addon must force a variation. An
                            // OPTIONAL addon is skippable ("No Thanks") so it
                            // must never block add-to-cart, even when left
                            // unresolved.
                            $addon_is_required = !empty($set_item_addon['required']);
                            if (
                                $addon_is_required
                                && empty($set_item_addon['variant_id'])
                                && $addon_real_variant_opts > 1
                            ) {
                                $addon_pname = $set_item_addon_product ? $set_item_addon_product->get_name() : '';
                                $set_item_addons_with_errors_product_names .= ' ' . $addon_pname . ' ,';
                            }


                            if (empty($set_item_addons_with_errors_product_names)) {

                                if ($set_item_addon_product && $set_item_addon_product->is_type('variable')) {

                                    if ( !$set_item_addon['variant_id'] ) {
                                        $set_item_addon_product_variants = $set_item_addon_product->get_visible_children();

                                        if ( !isset($set_item_addon_product_variants[0])) {

                                            $set_item_addon_name = $set_item_addon_product->get_name();
                                            $error_msg = 'Sorry, this product : '.$set_item_addon_name.' is not available.';
                                            wc_add_notice(__($error_msg, 'rentopian-sync'), 'error');
                                            if ($return_response) {
                                                return $error_msg;
                                            }
                                            return false;
                                        }
                                    }
                                }


                                $set_item_addon['parent_set_item_variant_id'] = $set_item['variant_id'];
                                $set_item_addon['parent_set_item_product_id'] = $set_item['product_id'];
                                $set_item_addon['required'] = 1;

                                $set_item['addons'][$set_item_addon_index] = $set_item_addon;
                                $inventories[] = $set_item_addon['rental_inv_id'];

                            }
                        }


                        if ($set_item_addons_with_errors_product_names) {

                            $set_item_addons_with_errors_product_names = rtrim($set_item_addons_with_errors_product_names, ' ,');

                            $error_msg = 'Please, choose a variation from the following addons: <br/>' . $set_item_addons_with_errors_product_names;
                            wc_add_notice(__($error_msg, 'rentopian-sync'), 'error');
                            if ($return_response) {
                                return $error_msg;
                            }
                            return false;
                        }

                    }


                    $set_items_as_add_ons[$i] = $set_item;
                    $inventories[] = $set_item['inv_id'];
                }



            } else {

                $inventories[] = $inv_id = get_post_meta($variation_id?: $product_id, '_rental_inventory_id', true);

                $add_ons = get_post_meta($product_id, '_rental_add_ons', true);
                if ($add_ons) {

                    foreach ($add_ons as $i => $add_on) {

                        $product = wc_get_product($add_on['product_id']);
                        if ($product->is_type('variable')) {

                            if ( !$add_on['variant_id'] ) {
                                $variants = $product->get_visible_children();

                                if ($is_update != true) {
                                    if (
                                        isset($variants) && $variants
                                        && !isset($_POST["rental_add_on_variants"])
                                    ) {
                                        $add_ons_required = true;
                                    }
                                }

                                if ( !isset($variants[0])) {
                                    $error_msg = 'Sorry, this product is not available.';
                                    wc_add_notice(__($error_msg, 'rentopian-sync'), 'error');
                                    if ($return_response) {
                                        return $error_msg;
                                    }
                                    return false;
                                }

                                // ErrorHandler::registerErrorInLog(
                                //     " _POST['rental_add_on_variants'] : " . json_encode( $_POST['rental_add_on_variants']),
                                //     __FILE__, __LINE__,
                                //     RentalException::TYPE_SYNC_RUNTIME
                                // );


                                if (isset($_POST['rental_add_on_variants']) && isset($_POST['rental_add_on_variants'][$add_on['product_id']]) &&
                                    in_array($_POST['rental_add_on_variants'][$add_on['product_id']], $variants)) {

                                        // ErrorHandler::registerErrorInLog(
                                        //     " here1 : " . $_POST['rental_add_on_variants'][$add_on['product_id']],
                                        //     __FILE__, __LINE__,
                                        //     RentalException::TYPE_SYNC_RUNTIME
                                        // );

                                    $add_on['variant_id'] = $_POST['rental_add_on_variants'][$add_on['product_id']];
                                } else {

                                    // ErrorHandler::registerErrorInLog(
                                    //     " here2 : " . $variants[0],
                                    //     __FILE__, __LINE__,
                                    //     RentalException::TYPE_SYNC_RUNTIME
                                    // );

                                    $add_on['variant_id'] = $variants[0];
                                }

                            }

                            $add_on['inv_id'] = get_post_meta($add_on['variant_id'], '_rental_inventory_id', true);

                        } else {

                            $add_on['inv_id'] = get_post_meta($add_on['product_id'], '_rental_inventory_id', true);
                        }

                        // $price = get_post_meta($add_on['variant_id'] ? $add_on['variant_id'] : $add_on['product_id'], '_price', true);
                        // $add_on['price'] = $price;

                        $add_on['set_id'] = 0;
                        $add_ons[$i] = $add_on;
                        $inventories[] = $add_on['inv_id'];
                    }
                }
            }

            if (empty($inventories)) {
                $error_msg = 'Sorry, this product is not available.';
                wc_add_notice(__($error_msg, 'rentopian-sync'), 'error');
                if ($return_response) {
                    return $error_msg;
                }
                return false;
            }

            $availability = rental_check_availability($inventories);
            $custom_cart_label = get_option('rental_cart_button_text') ? lcfirst(get_option('rental_cart_button_text')) : 'cart';

            // product/variant inventory not available condition
            if (empty($availability)) {

                $error_msg = 'Sorry, this product is not available.';
                // $opt_hide_zip = get_option('rental_hide_zip');
                // $error_msg = __('Please select the dates' . ($opt_hide_zip? '': ' and zip code') . ' so that you can add a product to the '.$custom_cart_label.'!', 'rentopian-sync');

                wc_add_notice('<span id="rental_error_select_dates">' . __($error_msg, 'rentopian-sync') . '</span>', 'error');
                if ($return_response) {
                    return '<span id="rental_error_select_dates">' . $error_msg . '</span>';
                }
                return false;
            }

            if ($availability == 'not_set') {
                $opt_hide_zip = get_option('rental_hide_zip');
                $error_msg = __('Please select the dates' . ($opt_hide_zip? '': ' and zip code') . ' to be able to add a product to the '.$custom_cart_label.'!', 'rentopian-sync');

                wc_add_notice('<span id="rental_error_select_dates">' . __($error_msg, 'rentopian-sync') . '</span>', 'error');
                if ($return_response) {
                    return '<span id="rental_error_select_dates">' . $error_msg . '</span>';
                }
                return false;
            }

            if ($availability == 'not_valid') {
                $error_msg = 'Please select a valid rental start date.';
                wc_add_notice('<span id="rental_error_select_dates">' . __($error_msg, 'rentopian-sync') . '</span>', 'error');
                if ($return_response) {
                    return '<span id="rental_error_select_dates">' . $error_msg . '</span>';
                }
                return false;
            }

            if ($is_update != true) {
                // check for product/set options

                $pid = $variation_id ? $variation_id : $product_id;
                $options_session_name = "";

                if (get_post_meta($pid, '_rental_is_set', true)) {

                    $options = get_set_options($pid);
                    $options_session_name = $pid."_selected_options_of_set";
                } else {
                    // if (!isset($cart_item['rental_set_id'])) {
                        // not a set's item
                        $options = get_product_options($pid);
                        $options_session_name = $pid."_selected_options";
                    // }
                }

                // $item_selected_option = get_option($options_session_name, []);
                $item_selected_option = get_rental_session_data($options_session_name, []);
                
                if (
                    $options
                    && !isset($item_selected_option)
                ) {

                    $error_msg = 'Please review the options before adding to '.$custom_cart_label.'.';
                    wc_add_notice(__($error_msg, 'rentopian-sync'), 'error');
                    if ($return_response) {
                        return $error_msg;
                    }

                    return false;
                }
            }

            if ($add_ons_required) {
                $error_msg = 'Please, select from the provided addon variations.';
                wc_add_notice(__($error_msg, 'rentopian-sync'), 'error');
                if ($return_response) {
                    return $error_msg;
                }
                return false;
            }

            $is_overbook_allowed = get_option('rental_allow_overbook', 0);
            $cart = [];
            $cart_contents = WC()->cart->cart_contents;
            if (!$is_overbook_allowed || !$availability["allow_overbook"]) {
                foreach ($cart_contents as $cart_item) {
                    if ($cart_item["key"] != $cart_item_key) {
                        $unique_key = $cart_item["product_id"] . "_" . $cart_item["variation_id"];
                        if (isset($cart[$unique_key])) {
                            $cart[$unique_key] += $cart_item["quantity"];
                        } else {
                            $cart[$unique_key] = $cart_item["quantity"];
                        }
                    }
                }
            }

            if ($inv_id) {

                $sum_quantity_in_cart = $quantity;
                if (isset($cart[$product_id . "_" . $variation_id])) {
                    $sum_quantity_in_cart += $cart[$product_id . "_" . $variation_id];
                }

                if ( !isset($availability["inventories"][$inv_id]) ) {
                    $error_msg = 'Sorry, this product is not available.';
                    wc_add_notice(__($error_msg, 'rentopian-sync'), 'error');
                    if ($return_response) {
                        return $error_msg;
                    }
                    return false;
                }

                $available_quantity = $availability["inventories"][$inv_id]["quantity"];
                if (!$availability["allow_overbook"]) {
                    $name = $cart_item_key && isset($cart_contents[$cart_item_key])? '"' . $cart_contents[$cart_item_key]['data']->get_name() . '" ': '';
                    if (empty($available_quantity)) {
                        if ($name) {
                            $zero_qty_msg = __('Sorry, This product : ', 'rentopian-sync') . "$name" . __(' is not available.', 'rentopian-sync');
                        } else {
                            $zero_qty_msg = __('Sorry, this product is not available.', 'rentopian-sync');
                        }
                        wc_add_notice($zero_qty_msg, 'error');
                        if ($return_response) {
                            return $zero_qty_msg;
                        }
                        return false;
                    }
                    if ($sum_quantity_in_cart > $available_quantity) {
                        $partial_qty_msg = __('Only', 'rentopian-sync') . " $available_quantity $name " . __('available', 'rentopian-sync');
                        wc_add_notice($partial_qty_msg, 'error');
                        if ($return_response) {
                            return $partial_qty_msg;
                        }
                        return false;
                    }
                }


                if (!$is_overbook_allowed) {
                    if ($sum_quantity_in_cart > $available_quantity) {
                        if ($cart_item_key) {
                            WC()->cart->cart_contents[$cart_item_key]['rental_overbook'] = true;
                        } else {
                            $_POST['rental_overbook_' . $product_id . '_' . $variation_id] = true;
                        }
                    } elseif ($cart_item_key) {
                        unset(WC()->cart->cart_contents[$cart_item_key]['rental_overbook']);
                    }
                }
            }

            $all_addon_items = [];
            if ($set_items_as_add_ons) {

                foreach ($set_items_as_add_ons as $i => $set_item) {

                    $product = wc_get_product($set_item['product_id']);
                    $addon_title = '';
                    if ($product) {
                        $addon_title = $product->get_title();
                    }

                    $sum_quantity_in_cart = $quantity * $set_item['quantity'];
                    if (isset($cart[$set_item["product_id"] . "_" . $set_item["variant_id"]])) {
                        $sum_quantity_in_cart += $cart[$set_item["product_id"] . "_" . $set_item["variant_id"]];
                    }

                    if ( !isset($availability["inventories"][$set_item['inv_id']])
                        || ( !$availability["allow_overbook"]
                        && $sum_quantity_in_cart > $availability["inventories"][$set_item['inv_id']]['quantity'])
                    ) {

                        $set_item_available_qty = isset($availability["inventories"][$set_item['inv_id']]['quantity'])
                            ? $availability["inventories"][$set_item['inv_id']]['quantity']
                            : 0;

                        if (!$is_overbook_allowed) {

                            // Overbooking disabled: block the WHOLE set so a package never reaches
                            // the order (and Rentopian) without all of its contents — even members
                            // the customer kept that are not flagged "required".
                            Rentopian_Order_Logger::set_add_blocked('n/a', $product_id, array(
                                'set_item_product_id' => $set_item['product_id'],
                                'set_item_variant_id' => $set_item['variant_id'],
                                'inv_id'              => $set_item['inv_id'],
                                'required'            => !empty($set_item['required']),
                                'requested_qty'       => $sum_quantity_in_cart,
                                'available_qty'       => $set_item_available_qty,
                            ));

                            $error_msg = 'This item requires <b>'. $set_item['quantity'] .' '. $addon_title . '</b> which is not available for the selected dates.';
                            wc_add_notice(__($error_msg, 'rentopian-sync'), 'error');

                            if ($return_response) {
                                return $error_msg;
                            }

                            return false;
                        }

                        // Overbooking allowed: never drop a set member. Keep it so every set child
                        // reaches the order;
                        Rentopian_Order_Logger::set_item_kept_overbook('n/a', $product_id, array(
                            'set_item_product_id' => $set_item['product_id'],
                            'set_item_variant_id' => $set_item['variant_id'],
                            'inv_id'              => $set_item['inv_id'],
                            'required'            => !empty($set_item['required']),
                            'requested_qty'       => $sum_quantity_in_cart,
                            'available_qty'       => $set_item_available_qty,
                        ));

                    } elseif (!$is_overbook_allowed) {

                        if ($sum_quantity_in_cart > $availability["inventories"][$set_item['inv_id']]['quantity']) {

                            if ($cart_item_key) {

                                WC()->cart->cart_contents[$cart_item_key]['rental_overbook'] = true;
                            } else {

                                $_POST['rental_overbook_' . $product_id . '_' . $variation_id] = true;
                            }

                        } elseif ($cart_item_key) {

                            unset(WC()->cart->cart_contents[$cart_item_key]['rental_overbook']);
                        }
                    }
                }

                $all_addon_items = $set_items_as_add_ons;
            }

            if ($add_ons) {

                foreach ($add_ons as $i => $add_on) {

                    $product = wc_get_product($add_on['product_id']);
                    $addon_title = '';
                    if ($product) {
                        $addon_title = $product->get_title();
                    }

                    $sum_quantity_in_cart = $quantity * $add_on['quantity'];
                    if (isset($cart[$add_on["product_id"] . "_" . $add_on["variant_id"]])) {
                        $sum_quantity_in_cart += $cart[$add_on["product_id"] . "_" . $add_on["variant_id"]];
                    }

                    if ( !isset($availability["inventories"][$add_on['inv_id']])
                        || ( !$availability["allow_overbook"]
                        && $sum_quantity_in_cart > $availability["inventories"][$add_on['inv_id']]['quantity'])
                    ) {

                        if ($add_on['required']
                            && !$availability["allow_overbook"]
                        ) {

                            $error_msg = 'This item requires <b>'. $add_on['quantity'] .' '. $addon_title . '</b> which is not available for the selected dates.';
                            wc_add_notice(__($error_msg, 'rentopian-sync'), 'error');

                            if ($return_response) {
                                return $error_msg;
                            }

                            return false;
                        } else {

                            unset($add_ons[$i]);
                        }

                    } elseif (!$is_overbook_allowed) {

                        if ($sum_quantity_in_cart > $availability["inventories"][$add_on['inv_id']]['quantity']) {

                            if ($cart_item_key) {
                                WC()->cart->cart_contents[$cart_item_key]['rental_overbook'] = true;
                            } else {
                                $_POST['rental_overbook_' . $product_id . '_' . $variation_id] = true;
                            }

                        } elseif ($cart_item_key) {

                            unset(WC()->cart->cart_contents[$cart_item_key]['rental_overbook']);
                        }
                    }
                }

                if ($all_addon_items) {

                    $all_addon_items = array_merge($all_addon_items, $add_ons);
                } else {
                    $all_addon_items = $add_ons;
                }
            }

            // Re-append the composite-group children captured from the form
            // payload (see note where $rental_browser_group_children is
            // built). They carry set_id + parent_set_id + group meta, so
            // rental_add_product_to_cart inserts them as set children and
            // Rental_Sets_Cart_Handler tags their group provenance.
            if (!empty($rental_browser_group_children)) {
                $all_addon_items = $all_addon_items
                    ? array_merge($all_addon_items, $rental_browser_group_children)
                    : $rental_browser_group_children;
            }

            if ($all_addon_items) {
                $_POST['rental_add_ons'] = $all_addon_items;
            }

            if ($return_response) {
                return 'success';
            }
            return true;
        }


        // Validate cart item hourly
        /*
        function rental_validate_cart_item_hourly($product_id, $variation_id, $quantity, $cart_item_key = null, $is_update = false) {
            $add_ons_required = false;
            $inv_id = 0;
            $inventories = [];
            if (
                (get_post_meta( $product_id, '_rental_by_interval', true)
                || get_post_meta( $product_id, '_rental_by_slot', true))
                && get_option('rental_synchronized_product_type') == "hourly"
            ) {

                $inventories[] = $inv_id = get_post_meta($variation_id?: $product_id, '_rental_inventory_id', true);
                $add_ons = get_post_meta($product_id, '_rental_add_ons', true);
                if ( !empty($add_ons)) {
                    foreach ($add_ons as $i => $add_on) {
                        $product = wc_get_product($add_on['product_id']);
                        if ($product->is_type('variable')) {
                            if ( !$add_on['variant_id']) {
                                $variants = $product->get_visible_children();

                                if ($is_update != true) {
                                    if (
                                        isset($variants) && !empty($variants)
                                        && !isset($_POST["rental_add_on_variants"])
                                    ) {
                                        $add_ons_required = true;
                                    }
                                }

                                if ( !isset($variants[0])) {
                                    wc_add_notice(__('Sorry, this product is not available.', 'rentopian-sync'), 'error');
                                    return false;
                                }
                                if (isset($_POST["rental_add_on_variants"]) && isset($_POST["rental_add_on_variants"][$add_on['product_id']]) &&
                                    in_array($_POST["rental_add_on_variants"][$add_on['product_id']], $variants)) {
                                    $add_on['variant_id'] = $_POST["rental_add_on_variants"][$add_on['product_id']];
                                } else {
                                    $add_on['variant_id'] = $variants[0];
                                }
                            }
                            $add_on['inv_id'] = get_post_meta($add_on['variant_id'], '_rental_inventory_id', true);
                        } else {
                            $add_on['inv_id'] = get_post_meta($add_on['product_id'], '_rental_inventory_id', true);
                        }
                        $add_on['set_id'] = 0;
                        $add_ons[$i] = $add_on;
                        $inventories[] = $add_on['inv_id'];
                    }
                }
            }

            if (empty($inventories)) {
                wc_add_notice(__('Sorry, this product is not available.', 'rentopian-sync'), 'error');
                return false;
            }

            $product_prefix = $variation_id ? $variation_id : $product_id;
            $session_name_interval = $product_prefix."_rental_by_interval";
            $session_name_slot = $product_prefix."_rental_by_slot";

            $rental_by_interval_opt = get_option($session_name_interval);
            $rental_by_slot_opt = get_option($session_name_slot);

            if ($rental_by_interval_opt !== false && $rental_by_interval_opt) {

                // replacing the updated selected item
                $session_name_interval_updated = $session_name_interval."_updated";
                $rental_by_interval_updated_opt = get_option($session_name_interval_updated);

                if ( $rental_by_interval_updated_opt !== false ) {
                    $rental_by_interval_opt = $rental_by_interval_updated_opt;
                    update_option($session_name_interval, $rental_by_interval_updated_opt);
                }

                $start_date = $rental_by_interval_opt["start_date"];
                $rental_by_interval_opt['active'] = 1;

            } elseif ($rental_by_slot_opt !== false && $rental_by_slot_opt) {

                // replacing the updated selected item
                $session_name_slot_updated = $session_name_slot."_updated";

                $rental_by_slot_updated_opt = get_option($session_name_slot_updated);

                if ( $rental_by_slot_updated_opt !== false ) {
                    $rental_by_slot_opt = $rental_by_slot_updated_opt;
                    update_option($session_name_slot, $rental_by_slot_opt);
                }

                $start_date = $rental_by_slot_opt["start_date"];
                $rental_by_slot_opt['active'] = 1;
                update_option($session_name_slot, $rental_by_slot_opt);

            } else {
                wc_add_notice(__('Please, select from the provided intervals.', 'rentopian-sync'), 'error');
                return false;
            }

            $availability = rental_check_availability_hourly($inventories, $start_date);
            if (is_null($availability)) {
                return false;
            }

            if ($add_ons_required) {
                wc_add_notice(__('Please, select from the provided addon variations.', 'rentopian-sync'), 'error');
                return false;
            }

            $is_overbook_allowed = get_option('rental_allow_overbook', 0);
            $cart = [];
            $cart_contents = WC()->cart->cart_contents;
            if (!$is_overbook_allowed || !$availability["allow_overbook"]) {
                foreach ($cart_contents as $cart_item) {
                    if ($cart_item["key"] != $cart_item_key) {
                        $unique_key = $cart_item["product_id"] . "_" . $cart_item["variation_id"];
                        if (isset($cart[$unique_key])) {
                            $cart[$unique_key] += $cart_item["quantity"];
                        } else {
                            $cart[$unique_key] = $cart_item["quantity"];
                        }
                    }
                }
            }

            if ($inv_id) {
                $sum_quantity_in_cart = $quantity;
                if (isset($cart[$product_id . "_" . $variation_id])) {
                    $sum_quantity_in_cart += $cart[$product_id . "_" . $variation_id];
                }
                if ( !isset($availability["inventories"][$inv_id])) {
                    wc_add_notice(__('Sorry, this product is not available.', 'rentopian-sync'), 'error');
                    return false;
                }
                $available_quantity = $availability["inventories"][$inv_id]["quantity"];
                if ( !$availability["allow_overbook"] && $sum_quantity_in_cart > $available_quantity) {
                    $name = $cart_item_key && isset($cart_contents[$cart_item_key])? '"' . $cart_contents[$cart_item_key]['data']->get_name() . '" ': '';
                    wc_add_notice(__('Only', 'rentopian-sync') . " $available_quantity $name" . __('available', 'rentopian-sync'), 'error');
                    return false;
                }
                if (!$is_overbook_allowed) {
                    if ($sum_quantity_in_cart > $available_quantity) {
                        if ($cart_item_key) {
                            WC()->cart->cart_contents[$cart_item_key]['rental_overbook'] = true;
                        } else {
                            $_POST['rental_overbook_' . $product_id . '_' . $variation_id] = true;
                        }
                    } elseif ($cart_item_key) {
                        unset(WC()->cart->cart_contents[$cart_item_key]['rental_overbook']);
                    }
                }
            }

            if ($add_ons) {

                foreach ($add_ons as $i => $add_on) {

                    $sum_quantity_in_cart = $quantity * $add_on['quantity'];

                    if (isset($cart[$add_on["product_id"] . "_" . $add_on["variant_id"]])) {
                        $sum_quantity_in_cart += $cart[$add_on["product_id"] . "_" . $add_on["variant_id"]];
                    }

                    if ( !isset($availability["inventories"][$add_on['inv_id']])
                        || ( !$availability["allow_overbook"] && $sum_quantity_in_cart > $availability["inventories"][$add_on['inv_id']]['quantity'])) {
                        if ($add_on['required']) {
                            wc_add_notice(__('Sorry, this product is not available.', 'rentopian-sync'), 'error');
                            return false;
                        } else {
                            unset($add_ons[$i]);
                        }
                    } elseif (!$is_overbook_allowed) {
                    // } elseif ($overbook_text) {
                        if ($sum_quantity_in_cart > $availability["inventories"][$add_on['inv_id']]['quantity']) {
                            if ($cart_item_key) {
                                WC()->cart->cart_contents[$cart_item_key]['rental_overbook'] = true;
                            } else {
                                $_POST['rental_overbook_' . $product_id . '_' . $variation_id] = true;
                            }
                        } elseif ($cart_item_key) {
                            unset(WC()->cart->cart_contents[$cart_item_key]['rental_overbook']);
                        }
                    }
                }

                $_POST['rental_add_ons'] = $add_ons;
            }

            return true;
        }
        */


        // Validate product before add to cart
        function rental_validate_add_cart_item($passed, $product_id, $quantity, $variation_id = 0, $variations = null) {
            if (get_post_meta($product_id, '_rental_is_add_on', true)) {

                $cart_custom_label = get_option('rental_cart_button_text', 'Cart');

                // wc_add_notice(__('This is only Add-On', 'rentopian-sync'), 'error');
                wc_add_notice(__('Sorry, can not add this item to your ' . $cart_custom_label, 'rentopian-sync'), 'error');
                return false;
            }

            if (empty($variation_id)) {
                $variation_id = !empty($_POST['variation_id']) ? absint($_POST['variation_id']) : 0;
            }

            // if (get_option('rental_synchronized_product_type') == "hourly") {

                // validate hourly products
                // if (rental_validate_cart_item_hourly($product_id, $variation_id, $quantity)) {
                //     return true;
                // }

            // } else {

                // validate daily products
                $valid = rental_validate_cart_item($product_id, $variation_id, $quantity);
                if ($valid) {
                    return true;
                }

                return false;
            // }

        }
        add_filter('woocommerce_add_to_cart_validation', 'rental_validate_add_cart_item', 10, 5);

        // Validate product before cart update
        function rental_update_cart_validation($true, $cart_item_key, $values, $quantity) {
            if (isset($values['rental_add_on_of'])) {
                return false;
            }

            if (get_option('rental_synchronized_product_type') == "hourly") {
                // validate hourly products
                // return rental_validate_cart_item_hourly($values['product_id'], $values['variation_id'], $quantity, $cart_item_key, true);
            } else {
                // validate daily products
                return rental_validate_cart_item($values['product_id'], $values['variation_id'], $quantity, $cart_item_key, true);
            }

        }
        add_filter('woocommerce_update_cart_validation', 'rental_update_cart_validation', 10, 4);


        /*
            if (get_option('rental_filter_unavailable_products', 0)) {
                
                // display products, variants and sets based on various filters
                function rental_loop_shop_post_in($array) {

                    $division_id = null;
                    if (isset($_POST['rental_division_id'])) {
                        $division_id = intval($_POST['rental_division_id']);
                    } elseif (isset($_COOKIE['rental_division_id'])) {
                        $division_id = intval($_COOKIE['rental_division_id']);
                    }

                    $location_based_duplicate_filter = 0;
                    $location_based_duplicate_filter_division_id = 0;

                    $decrypted_rental_zip = 0;
                    if (isset($_COOKIE['rental_zip']) && $_COOKIE['rental_zip']) {
                        $decrypted_rental_zip = decrypt_data($_COOKIE['rental_zip'], get_option('rental_encryption_key'));
                    }

                    if (
                        empty($division_id)
                        && ( get_option('rental_hide_zip')
                        || ( !get_option('rental_hide_zip') && (!isset($_COOKIE['rental_zip']) || empty($_COOKIE['rental_zip']) || $decrypted_rental_zip == 1)))
                        // && (get_option('rental_dates_on_checkout', 0) != 1 || get_option('rental_allow_overbook', 1) != 1)
                    ) {
                        $location_based_duplicate_filter = get_option('rental_location_based_duplicate_filter', 0);
                        $location_based_duplicate_filter_division_id = get_option('rental_location_based_duplicate_filter_division_id', 0);
                    }

                    $duplicate_filter = ["location_based_duplicate_filter" => $location_based_duplicate_filter,
                    "location_based_duplicate_filter_division_id" => $location_based_duplicate_filter_division_id];

                    $rental_unavailable_items = [];
                    // $rental_unavailable_items_opt = get_option('rental_unavailable_items');
                    // if ($rental_unavailable_items_opt && is_array($rental_unavailable_items_opt)) {
                    //     $rental_unavailable_items = $rental_unavailable_items_opt;
                    // }


                    if ($data = get_rental_cache('rental_product_variant_list_filter', 'rental_product_variant_list_filter_expiration_date')) {
                        
                        return $data;
                    }

                    $rental_product_variant_list_filter = product_variant_list_filter($rental_unavailable_items, $division_id, $array, 0, $duplicate_filter);

                    set_rental_cache($rental_product_variant_list_filter, 'rental_product_variant_list_filter', 'rental_product_variant_list_filter_expiration_date');

                    return $rental_product_variant_list_filter;
                }
                add_action('loop_shop_post_in', 'rental_loop_shop_post_in');


                function rental_custom_pre_get_posts_query($q) {
                    if ( !$q->is_main_query() || is_admin() || !$q->is_search()) return;

                    $q->set('post_type', array('product', 'product_variation'));

                    $search_term = $q->get('s');
                    $stemmed_search_result_ids = get_stemmed_search_result_ids($search_term);
                    
                    $division_id = null;
                    if (isset($_POST['rental_division_id'])) {
                        $division_id = intval($_POST['rental_division_id']);
                    } elseif (isset($_COOKIE['rental_division_id'])) {
                        $division_id = intval($_COOKIE['rental_division_id']);
                    }

                    $decrypted_rental_zip = 0;
                    if (isset($_COOKIE['rental_zip']) && $_COOKIE['rental_zip']) {
                        $decrypted_rental_zip = decrypt_data($_COOKIE['rental_zip'], get_option('rental_encryption_key'));
                    }

                    $location_based_duplicate_filter = 0;
                    $location_based_duplicate_filter_division_id = 0;
                    if (
                        empty($division_id)
                        && ( get_option('rental_hide_zip')
                        || ( !get_option('rental_hide_zip') && (!isset($_COOKIE['rental_zip']) || empty($_COOKIE['rental_zip']) || $decrypted_rental_zip == 1)))
                        // && (get_option('rental_dates_on_checkout', 0) != 1 || get_option('rental_allow_overbook', 1) != 1)
                    ) {
                        $location_based_duplicate_filter = get_option('rental_location_based_duplicate_filter', 0);
                        $location_based_duplicate_filter_division_id = get_option('rental_location_based_duplicate_filter_division_id', 0);
                    }

                    $duplicate_filter = ["location_based_duplicate_filter" => $location_based_duplicate_filter,
                    "location_based_duplicate_filter_division_id" => $location_based_duplicate_filter_division_id];

                    $rental_unavailable_items = [];
                    // $rental_unavailable_items_opt = get_option('rental_unavailable_items');
                    // if ($rental_unavailable_items_opt && is_array($rental_unavailable_items_opt)) {
                    //     $rental_unavailable_items = $rental_unavailable_items_opt;
                    // }

                    if (empty($stemmed_search_result_ids)) {

                        if ($data = get_rental_cache('rental_product_variant_list_filter', 'rental_product_variant_list_filter_expiration_date')) {

                            $q->set('post__in', $data);
                        } else {
        
                            $filtered_products = product_variant_list_filter($rental_unavailable_items, $division_id, [], 0, $duplicate_filter);
        
                            set_rental_cache($filtered_products, 'rental_product_variant_list_filter', 'rental_product_variant_list_filter_expiration_date');
        
                            $q->set('post__in', $filtered_products);
                        }
                        
                    } else {
                        // stemmed search effect
                    
                        $q->set('original_search_term', $search_term );

                        // Clear the native search filter so WP doesn't re‐apply its LIKE()
                        $q->set('s', '');

                        // Respect the order of IDs you passed in
                        $q->set('orderby', 'post__in');
                        

                        $final_ids = [];
                        if ($data = get_rental_cache('rental_product_variant_list_filter', 'rental_product_variant_list_filter_expiration_date')) {
                            
                            $final_ids = array_intersect($stemmed_search_result_ids, $data);
                            
                            $q->set('post__in', $final_ids);
                            
                        } else {

                            $filtered_products = product_variant_list_filter($rental_unavailable_items, $division_id, [], 0, $duplicate_filter);
        
                            set_rental_cache($filtered_products, 'rental_product_variant_list_filter', 'rental_product_variant_list_filter_expiration_date');
        
                            $final_ids = array_intersect($stemmed_search_result_ids, $filtered_products);

                            $q->set('post__in', $final_ids);
                        }
                    }
                

                }
                add_action('pre_get_posts', 'rental_custom_pre_get_posts_query');

                
                // stash the original term in original_search_term
                function store_original_search_term( $q ) {
                    if ( $q->is_main_query() && $q->is_search() && !is_admin() ) {

                        // stash it for later
                        add_filter('get_search_query', function() use ( $q ) {
                            return $q->get('original_search_term');
                        });
                    }
                }
                add_action( 'pre_get_posts', 'store_original_search_term', 0 ); 

            }
        */




        // Add product type label after product price shop and single product pages
        function rental_show_product_type() {
            if ( !get_option('rental_hide_product_type_label')) {
                if (get_post_meta(get_the_ID(), '_rental_is_sale', true)) {
                    echo '<span class="product-rental-type label label-danger">' . __('Sale', 'rentopian-sync') . '</span>';
                } else {
                    echo '<span class="product-rental-type label label-primary">' . __('Rental', 'rentopian-sync') . '</span>';
                }
            }
        }
        add_action('woocommerce_after_shop_loop_item', 'rental_show_product_type', 10);
        add_action('woocommerce_before_add_to_cart_form', 'rental_show_product_type', 10);

        // returns the avilability of the product
        function rental_get_availability($availability, $product) {
            // if not in hourly mode
            if (get_option('rental_synchronized_product_type') != "hourly") {
                // if there is no rental start/end dates
                if ( !(isset($_COOKIE['rental_start_date']) && $_COOKIE['rental_start_date'] && isset($_COOKIE['rental_zip']) && $_COOKIE['rental_zip'])) {
                    // if not in dates on checkout mode
                    if (get_option('rental_dates_on_checkout', 0) != 1 || get_option('rental_allow_overbook', 1) != 1) {
                        $availability['availability'] = 'Please fill out the rental dates form.';
                        $availability['class'] = 'rntp-out-of-stock';
                    }
                }
            }
            return $availability;
        }
        add_filter('woocommerce_get_availability', 'rental_get_availability', 1, 2);


        // Removing add to cart button
        function rental_hide_add_to_cart_button() {

            // hide add to cart button if the location(division) does not have working days(hours)
            if ( get_option('rental_synchronized_product_type') == "hourly" ) {
                $divisions = get_option("rental_divisions");
                foreach ($divisions as $division) {
                    if (get_option('rental_select_division') && isset($_COOKIE['rental_division_id']) && !empty($_COOKIE['rental_division_id'])) {
                        if ( is_array($divisions) && count($divisions) > 1 && ($_COOKIE['rental_division_id'] == $division->id)) {
                            // getting selected division work hours
                            if (empty($division->working_hours)) {
                                remove_action('woocommerce_single_variation', 'woocommerce_single_variation_add_to_cart_button', 20);
                                remove_action('woocommerce_single_product_summary', 'woocommerce_template_single_add_to_cart', 30);
                            }
                        }
                    } else {
                        // default : getting main division work hours
                        if ($division->main_division) {
                            if (empty($division->working_hours)) {
                                remove_action('woocommerce_single_variation', 'woocommerce_single_variation_add_to_cart_button', 20);
                                remove_action('woocommerce_single_product_summary', 'woocommerce_template_single_add_to_cart', 30);
                            }
                        }
                    }
                }


            } else {
                // if not in hourly mode
                if (get_option('rental_dates_on_checkout', 0) == 1 && get_option('rental_allow_overbook', 1) == 1) {
                    // if we have allow overbook and rental dates on checkout page
                    rental_dates_on_checkout_page_option_effect();

                } else {
                    // usual journey
                    if ( !isset($_COOKIE['rental_start_date']) || !$_COOKIE['rental_start_date']) {
                        remove_action('woocommerce_single_variation', 'woocommerce_single_variation_add_to_cart_button', 20);
                        remove_action('woocommerce_single_product_summary', 'woocommerce_template_single_add_to_cart', 30);
                        // global $product;
                        // if (!$product->is_type('variable')) {
                            add_action('woocommerce_single_variation', 'rental_add_select_rental_dates_button', 90);
                        // }
                        // add_action('woocommerce_before_add_to_cart_form', 'rental_add_select_rental_dates_button', 90);
                        add_action('woocommerce_single_product_summary', 'rental_add_select_rental_dates_button', 90);
                    }
                }

            }

        }
        add_action('woocommerce_single_product_summary', 'rental_hide_add_to_cart_button', 1, 0);
        // add_action( 'woocommerce_before_add_to_cart_form', 'rental_hide_add_to_cart_button' );

        function rental_add_select_rental_dates_button() {
            // if Eventorian theme is active
            //if (defined('EVENTORIAN_THEME_DIR')) {

            // if not in hourly mode
            if (get_option('rental_synchronized_product_type') != "hourly") {
                $auto_openable = get_option('rental_not_open_dates_form') ? 0 : 1;
                $custom_cart_label = get_option('rental_cart_button_text') == '' ?  __(' Cart', 'rentopian-sync') : get_option('rental_cart_button_text');
                $select_dates_text = 'Select Rental Dates To Add To ' . $custom_cart_label;
                $form_layout =  get_option('rental_form_layout') ? get_option('rental_form_layout') : 'horizontal';
                ?>

                <button type="submit" class="rental-select-dates rntp-button-wrap rntp-button rntp-button-primary" data-openable="<?php echo $auto_openable; ?>" data-type="<?php echo $form_layout?>">
                    <?php _e($select_dates_text, 'rentopian-sync'); ?>
                </button>

                <?php
            }
        }

        // Change price html on shop and single product pages
        function rental_change_product_price_html($price, $product) {
            if (rental_prices_are_hidden('catalog')) {
                return '';
            }

            if ($price && !get_post_meta(($product instanceof WC_Product_Variation)? $product->get_parent_id(): $product->get_id(), '_rental_is_sale', true)) {
                $daily_fee_custom_label = get_option('rental_daily_fee_text');
                $price .= isset($daily_fee_custom_label) ? ' '.$daily_fee_custom_label : __(' Daily fee', 'rentopian-sync');
            }
            return $price;
        }
        add_filter('woocommerce_get_price_html', 'rental_change_product_price_html', 10, 2);

        // Change cart item price before calculate totals
        function rental_change_cart_item_price($cart) {
            calculate_cart_totals($cart);
        }
        add_action('woocommerce_before_calculate_totals', 'rental_change_cart_item_price', 10, 1);

        // apply coupon code in rentpro theme
        function rental_apply_product_coupon($cart) {
            if (is_admin() && !defined('DOING_AJAX'))
                return;

            if ( did_action( 'woocommerce_before_calculate_totals' ) >= 2 )
                return;


            $coupon_code = '';
            if (isset($_COOKIE['rental_coupon_code']) && $_COOKIE['rental_coupon_code']) {
                $decrypted_rental_coupon_code = decrypt_data($_COOKIE['rental_coupon_code'], get_option('rental_encryption_key'));
                $coupon_code = $decrypted_rental_coupon_code;
            }

            if ($coupon_code) {
                // Check if coupon is applied
                if ($cart->has_discount($coupon_code)) return;

                // Check if coupon is not valid then remove it and show error notif
                if (!$cart->add_discount(sanitize_text_field($coupon_code))) {
                    
                    $cart->remove_coupon($coupon_code);
        
                    $_COOKIE['rental_coupon_code'] = "";
                    if (isset($_COOKIE['rental_coupon_code'])) {
                        unset($_COOKIE['rental_coupon_code']);
                        setcookie('rental_coupon_code', false, time() - (31556952), "/", "", false, false);
                    }

                    // Coupon is not valid
                    wc_add_notice(__('The coupon code is not valid!', 'rentopian-sync'), 'notice');
                }
            }
        }
        add_action('woocommerce_before_calculate_totals', 'rental_apply_product_coupon');


        // Apply a post's price_multiplier (the tiered duration coefficient,
        // including weekend-rate handling) to a base day count. This is the
        // exact math the classic cart-item-price path uses for non-set
        // products, factored out so set PARENT lines — which are priced
        // through Rental_Sets_Price_Engine::parent_unit_price() and would
        // otherwise skip the multiplier — apply the SAME coefficient.
        //
        // Returns the adjusted day-coefficient. No-ops (returns the input
        // days) when the post has no price_multiplier configured.
        function rental_get_multiplier_adjusted_days($post_id, $base_days = null) {
            $diff = ($base_days === null) ? rental_get_days() : (float) $base_days;

            $price_multipliers = get_price_multiplier_items_by_post_id($post_id);
            if (!$price_multipliers) {
                return $diff;
            }
            $price_multiplier_items = isset($price_multipliers['price_multiplier_items'])
                ? $price_multipliers['price_multiplier_items'] : [];
            if (empty($price_multiplier_items)) {
                return $diff;
            }
            $price_multiplier_options = [
                'is_monthly' => $price_multipliers['is_monthly'],
                'is_repeat'  => $price_multipliers['is_repeat'],
            ];

            // Weekend-rate handling (mirrors the classic cart-item path).
            $we_days = [];
            $we_max = 0;
            $weekend_rate = false;
            foreach ($price_multiplier_items as $item) {
                if (isset($item['we_rate'])) {
                    $weekend_rate = true;
                    $we_days = $item['we_days'];
                    $we_max = $item['we_max'];
                    break;
                }
            }
            if ($weekend_rate) {
                $decrypted_rental_start_date = "";
                if (isset($_COOKIE['rental_start_date']) && $_COOKIE['rental_start_date']) {
                    $decrypted_rental_start_date = decrypt_data($_COOKIE['rental_start_date'], get_option('rental_encryption_key'));
                }
                $start_date = new DateTime($decrypted_rental_start_date);
                $wd = (int) $start_date->format("w");
                $mp = $wd; // start day in matrix
                $ws = false;
                $wc = 0;
                $newdiff = 0;
                for ($i = 0; $i < $diff; $i++) {
                    if ($mp > 6) $mp = 0;
                    $day = $we_days[$mp];
                    if ($day == 1) {
                        $ws = true;
                        if ($wc < $we_max || $i == 0 || ($i == ($diff - 1) && $wc == 0)) {
                            $wc++;
                        }
                    }
                    if ($day == 0 || $i == ($diff - 1)) {
                        if ($day == 0) {
                            $newdiff++;
                        }
                        if ($ws) {
                            $newdiff += $wc;
                            $ws = false;
                            $wc = 0;
                        }
                    }
                    $mp++;
                }
                $diff = $newdiff;
            }

            return rental_calculate_price_multiplier($price_multiplier_items, $diff, $price_multiplier_options);
        }

        function rental_calculate_cart_item_price($cart_item) {
            if (get_option('rental_synchronized_product_type') == "hourly") {

                $product_prefix = $cart_item['variation_id'] ? $cart_item['variation_id'] : $cart_item['product_id'];
                $session_name_interval = $product_prefix."_rental_by_interval";
                $session_name_slot = $product_prefix."_rental_by_slot";

                $rental_by_interval_opt = get_option($session_name_interval);
                $rental_by_slot_opt = get_option($session_name_slot);

                if ($rental_by_interval_opt !== false && $rental_by_interval_opt) {
                    // intervals
                    return $rental_by_interval_opt['total'];
                }

                if ($rental_by_slot_opt !== false && $rental_by_slot_opt) {
                    // time slots
                    return $rental_by_slot_opt['total'];
                }
            }

            if (get_post_meta($cart_item['product_id'], '_rental_is_set', true)) {
                $post_id = $cart_item['product_id'];
            } else {
                $post_id = $cart_item['variation_id']?: $cart_item['product_id'];
            }

            // Set PARENT line — route through the price engine so the
            // "Price" column matches what the engine bills:
            //   item_based_total = 1 → returns 0.0 (children carry total)
            //   item_based_total = 0 → returns _regular_price × duration
            // Skipping this branch for child lines: they hit the
            // `rental_add_on_of` block further down which already
            // delegates to rental_set_child_effective_unit_price.
            if (
                empty($cart_item['rental_add_on_of'])
                && get_post_meta($cart_item['product_id'], '_rental_is_set', true)
                && class_exists('Rental_Sets_Price_Engine', false)
            ) {
                $rental_parent_unit = Rental_Sets_Price_Engine::parent_unit_price((int) $cart_item['product_id']);
                if (null !== $rental_parent_unit) {
                    return (float) $rental_parent_unit;
                }
            }

            $price = (float) get_post_meta($post_id, '_price', true);

            if (get_post_meta($cart_item['product_id'], '_rental_is_sale', true)) {
                // return $price;
                return isset($cart_item['rental_add_on_of']) && isset($cart_item['rental_add_on_price'])?
                    $cart_item['rental_add_on_price']:
                    $price;
            }

            // $addons_of_products = get_rental_session_data('rental_uncertain_add_ons', []);
            // $is_product_addon = false;
            // if (!empty($addons_of_products)) {
            //     foreach($addons_of_products as $addon_ids) {
            //         if (in_array($post_id, $addon_ids)) {
            //             $is_product_addon = true;
            //             break;
            //         }
            //     }
            // }

            $diff = rental_get_days();
            if (isset($cart_item['rental_add_on_of'])
                && isset($cart_item['rental_add_on_price'])
            ) {

                // Set price-DISPLAY rules. For a set child the effective
                // per-unit price is resolved by a single source of truth
                // (rental_set_child_effective_unit_price) so the cart
                // Price column never disagrees with the Subtotal column.
                // It returns null for plain (non-set) product addons —
                // those keep the legacy rental_add_on_price behaviour.
                $effective_unit = rental_set_child_effective_unit_price($cart_item);
                if ($effective_unit !== null) {
                    $price = $effective_unit * $diff;
                } else {
                    $price = $cart_item['rental_add_on_price'] * $diff;
                }

            } else {

                // Apply the post's price_multiplier (tiered duration
                // coefficient + weekend rate) to the day count, then bill
                // _price × adjusted days. Shared with the set price engine
                // so set parents apply the same coefficient.
                $diff = rental_get_multiplier_adjusted_days($post_id, $diff);
                $price = (float) get_post_meta($post_id, '_price', true);
                $price *= $diff;
            }

            return $price;
        }

        function rental_calculate_rental_item_price($post_id, $custom_price = null, $calculate_one_day_price = false, $get_regular_price = false) {

            $price = $custom_price ? (float) $custom_price : (float) get_post_meta($post_id, '_price', true);
            if ($get_regular_price) {
                $price = (float) get_post_meta($post_id, '_regular_price', true);
            }

            if (get_post_meta($post_id, '_rental_is_sale', true)) {

                $is_add_on = get_post_meta($post_id, '_rental_is_add_on', true);
                $add_on_item = wc_get_product($post_id);
                if ($is_add_on && $add_on_item) {
                    // $rental_add_on_price = isset($add_on_item->inherit_price) && $add_on_item->inherit_price == 1 ? $add_on_item->product_price : $add_on_item->price;
                    $rental_add_on_price = (float) $custom_price !== null ? $custom_price : (isset($add_on_item->inherit_price) && $add_on_item->inherit_price == 1 ? $price : $add_on_item->price);

                    return $rental_add_on_price;
                }
            }

            // $addons_of_products = get_rental_session_data('rental_uncertain_add_ons', []);
            // $is_product_addon = false;
            // if ($addons_of_products) {
            //     foreach($addons_of_products as $addon_ids) {
            //         if (in_array($post_id, $addon_ids)) {
            //             $is_product_addon = true;
            //             break;
            //         }
            //     }
            // }

            $has_price_multiplier = get_post_meta($post_id, '_price_multiplier_id', true);

            // get price multiplier items of the current variant or set
            $price_multipliers = $has_price_multiplier ? get_price_multiplier_items_by_post_id($post_id) : [];
            $price_multiplier_items = $price_multiplier_options = [];

            if($price_multipliers) {
                $price_multiplier_options = [
                    'is_monthly' => $price_multipliers['is_monthly'],
                    'is_repeat' => $price_multipliers['is_repeat'],
                ];
                $price_multiplier_items = $price_multipliers['price_multiplier_items'];
            }

            $diff = rental_get_days();
            if ($calculate_one_day_price) {
                $diff = 1;
            }
           
            $is_add_on = get_post_meta($post_id, '_rental_is_add_on', true);
            $add_on_item = wc_get_product($post_id);
            if ($is_add_on && $add_on_item) {

                // if ($is_product_addon) {
                //     $price = $price * $diff;
                // } else {

                    // $rental_add_on_price = isset($add_on_item->inherit_price) && $add_on_item->inherit_price == 1 ? $add_on_item->product_price : $add_on_item->price;
                    $rental_add_on_price = (float) $custom_price !== null ? $custom_price : (isset($add_on_item->inherit_price) && $add_on_item->inherit_price == 1 ? $price : $add_on_item->price);
                    $price = $rental_add_on_price * $diff;
                // }

            } else {

                if ($price_multiplier_items) {
                    $we_days = [];
                    $we_max = 0;
                    $weekend_rate = false;
                    foreach($price_multiplier_items as $item) {
                        if(isset($item['we_rate'])) {
                            $weekend_rate = true;
                            $we_days = $item['we_days'];
                            $we_max = $item['we_max'];
                            break;
                        }
                    }
                    if($weekend_rate) {
                        $start_date = new DateTime($_COOKIE['rental_start_date']);
                        $wd = (int) $start_date->format("w");
                        $mp = $wd; // start day in matrix
                        $ws = false;
                        $wc = 0;
                        // Calculating weekend rate days
                        $newdiff = 0;
                        for($i=0;$i<$diff;$i++) {
                            // we will take day type from matrix $we_days
                            if($mp > 6) $mp = 0;
                            $day = $we_days[$mp];
                            if($day == 1) {
                                // If weekend day - mark that we have it
                                $ws = true;
                                if($wc < $we_max || $i == 0 || ($i == ($diff-1) && $wc == 0)) {
                                    // add days up to max_days. If first day or last day with none counted - count it
                                    $wc++;
                                }
                            }
                            if($day == 0 || $i == ($diff-1)) {
                                // if normal day or last day
                                if($day == 0) {
                                    // count normal day
                                    $newdiff++;
                                }
                                if($ws) {
                                    // add counted previosly counted weekend days
                                    $newdiff+=$wc;
                                    $ws = false;
                                    $wc = 0;
                                }
                            }
                            $mp++;
                        }
                        $diff = $newdiff;
                    }
                    $diff = rental_calculate_price_multiplier($price_multiplier_items, $diff, $price_multiplier_options);
                }
                $price = $custom_price ? (float) $custom_price : (float) get_post_meta($post_id, '_price', true);
                
                if ($get_regular_price) {
                    $price = (float) get_post_meta($post_id, '_regular_price', true);
                }
                
                $price *= $diff;
            }

            return $price;
        }

        // Change cart item price text
        function rental_change_cart_item_price_text($price, $cart_item) {
            if (rental_prices_are_hidden('cart')) {
                return '';
            }

            $start_date = "";
            // hourly products mode
            if (get_option('rental_synchronized_product_type') == "hourly") {
                $start_date = isset($_COOKIE["rental_hourly_start_date"]) && $_COOKIE["rental_hourly_start_date"] ? $_COOKIE["rental_hourly_start_date"] : "";
            } else {

                $decrypted_rental_start_date = "";
                if (isset($_COOKIE['rental_start_date']) && $_COOKIE['rental_start_date']) {
                    $decrypted_rental_start_date = decrypt_data($_COOKIE['rental_start_date'], get_option('rental_encryption_key'));
                }
                $start_date = $decrypted_rental_start_date ? $decrypted_rental_start_date : "";
            }

            if (!empty($start_date) && isset($_COOKIE['rental_zip']) && $_COOKIE['rental_zip']) {
                $new_price = rental_calculate_cart_item_price($cart_item);
                if ($new_price !== false) {
                    $price = wc_price($new_price);
                }
            }

            return $price;
        }
        add_filter('woocommerce_cart_item_price', 'rental_change_cart_item_price_text', 10, 2);

        function rental_set_products_list() {

            if ( class_exists( 'Rental_Sets_Renderer', false )
                && Rental_Sets_Renderer::will_render() ) {

                // Modern renderer is taking over — classic stands down.
                return;
            }

            global $product;

            $set_id = $product->get_id();
            if (get_post_meta($set_id, '_rental_is_set', true)) {

                ?>
                    <div id="_rental_is_set_identifier"></div>
                <?php

                $items = get_post_meta($set_id, '_rental_set_items', true);

                if (empty($items)) {
                    return;
                }


                $rental_hide_items_on_website = get_post_meta($set_id, '_rental_hide_items_on_website', true);
                if (!get_option('rental_hide_set_items', 0) && !$rental_hide_items_on_website) {

                    $set_components_header = get_option('rental_set_components_text', '');

                    $rental_set_listing_style =  get_option('rental_set_listing_style');
                    $min_class = $rental_set_listing_style == 'standard' ? '' : 'table-style-minimal';

                    if($rental_set_listing_style == 'standard') {
                        $header_html = '<h4 class="text-center set-table-title">' . $set_components_header . '</h4>';
                        echo $header_html;
                    }

                    $item_based_total = get_post_meta($set_id, '_rental_item_based_total', true);
                ?>

                    <table id="rental_set_items_table" class="rntp-table <?php echo $min_class?>">

                        <thead>
                            <tr class="rate-header">
                                <?php if($rental_set_listing_style == 'standard'):?>
                                    <th class="period-text"><?php _e('Type', 'rentopian-sync') ?></th>
                                    <th class="period-text"><?php _e('Image', 'rentopian-sync') ?></th>
                                    <th class="period-text"><?php _e('Product', 'rentopian-sync') ?></th>
                                    <th class="rate-text"><?php _e('Quantity', 'rentopian-sync') ?></th>
                                <?php else:?>
                                    <th><?php echo $set_components_header ?></th>
                                <?php endif ?>
                            </tr>
                        </thead>

                        <tbody>
                            <?php
                            // Tracks whether any item or add-on actually exposes a variant/optional-item picker.
                            $set_has_selectable_variants = false;

                            foreach ($items as $item) {

                                if (isset($item['hidden']) && $item['hidden']) {
                                    continue;
                                }

                                $optional_items = [];
                                $selected_variant_icon_class = '';
                                $selected_variant_attributes = [];
                                $selected_variant_price = 0;
                                if ( isset($item['optional_items']) && $item['optional_items'] ) {
                                    $optional_items = $item['optional_items'];

                                    $optional_items_count = count($item['optional_items']);

                                    $selected_variant_icon_class = (isset($item['has_selected']) && $item['has_selected'] == 1) || ($optional_items_count < 2) ? 'selected-variant-icon' : '';
                                }

                                $prod = wc_get_product($item['product_id']);

                                if ($prod) {
                                    ?>
                                        <tr>
                                            <?php if(!isset($rental_set_listing_style) || $rental_set_listing_style == 'standard'):?>
                                                <td class="td-item-type">SET ITEM</td>
                                                
                                                <td class="td-item-img">
                                                <?php
                                                    $item_image = $prod->get_image('medium'); // default to product image
    
                                                    // Check if item has a variant_id and get its image
                                                    if (!empty($item['variant_id'])) {
                                                        $variant = wc_get_product($item['variant_id']);
                                                        if ($variant && $variant->get_image_id()) {
                                                            $item_image = $variant->get_image('medium');
                                                        }
                                                    }
                                                    
                                                    echo $item_image;
                                                ?>
                                                </td>
                                                <td class="td-item-name">
                                                    <?php
                                                    // Default: use parent product.
                                                    $display_name      = $prod->get_name();
                                                    $display_permalink = $prod->get_permalink();

                                                    // If this set item is a regular (non-optional) variant (variant_id > 0), use its own label.
                                                    if ( !empty($item['variant_id']) && intval($item['variant_id']) > 0 ) {
                                                        $variation = wc_get_product( $item['variant_id'] );

                                                        if ( $variation && $variation->get_id() ) {

                                                            // Build a readable variation label from its attributes, e.g. “90\" Round White”.
                                                            $attrs_text = wc_get_formatted_variation( $variation, true, false, false ); // no price, no HTML.

                                                            if ( $attrs_text ) {
                                                                $display_name = $display_name . ' – ' . $attrs_text;
                                                            } else {
                                                                // If no separate attributes text, at least use the variation’s own name if different.
                                                                $variation_name = $variation->get_name();
                                                                if ( $variation_name && $variation_name !== $display_name ) {
                                                                    $display_name = $variation_name;
                                                                }
                                                            }

                                                            $display_permalink = $variation->get_permalink();
                                                        }
                                                    }
                                                    ?>
                                                    <a href="<?php echo esc_url( $display_permalink ); ?>">
                                                        <?php echo esc_html( $display_name ); ?>
                                                    </a>


                                                    
                                                    <?php
                                                        $set_item_price = 0;
                                                        // if (!empty($item['separate_price']))  {
                                                        if ($item_based_total && !$optional_items)  {
                                                            $set_item_price = $item['price'] ?? 0;
                                                        }

                                                        if ($set_item_price && !rental_prices_are_hidden('catalog')) {
                                                            echo "<p><span class='woocommerce-Price-amount amount'><bdi><span class='woocommerce-Price-currencySymbol'>".get_woocommerce_currency_symbol()."</span>".format_value_to_fixed_precision($set_item_price,2)."</bdi></span></p>";
                                                        }
                                                    ?>
                                                    

                                                    <?php if ($optional_items):

                                                        $optional_items_data = [];
                                                        foreach($optional_items as $optional_item) {

                                                            $optional_item_data = get_variant_data($optional_item['product_id'], $optional_item['variant_id']);

                                                            if (
                                                                (isset($optional_item['is_selected'])
                                                                && $optional_item['is_selected'])
                                                                || $optional_items_count < 2
                                                            ) {
                                                                $optional_item_data['is_selected'] = 1;

                                                                $selected_variant_attributes = isset($optional_item_data['attrs']) && $optional_item_data['attrs'] ? $optional_item_data['attrs'] : [];
                                                                $selected_variant_price = $optional_item_data['price'];
                                                            }

                                                            $optional_items_data[] = $optional_item_data;
                                                        }

                                                        $json_flags = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE;

                                                        $optional_items_data_encoded = json_encode($optional_items_data, $json_flags);

                                                        $set_has_selectable_variants = true;
                                                    ?>

                                                        <div class="choose-item-variant"
                                                            data-has-selected="<?= isset($item['has_selected']) && $item['has_selected'] == 1 ? 1 : 0; ?>"
                                                            data-items="<?php echo esc_attr($optional_items_data_encoded); ?>">

                                                            <?php
                                                                $attrs_joined = '';

                                                                if ($selected_variant_attributes) {
                                                                    foreach($selected_variant_attributes as $key => $selected_variant_attribute) {
                                                                        if ($key == 0) {
                                                                            $attrs_joined .= $selected_variant_attribute;
                                                                        } else {
                                                                            $attrs_joined .= ', ' . $selected_variant_attribute;
                                                                        }

                                                                    }

                                                                    echo '<p>' . $attrs_joined . '</p>';
                                                                }

                                                                if ($selected_variant_price && !rental_prices_are_hidden('catalog')) {
                                                                    echo '<p>' . wc_price($selected_variant_price) . '</p>';
                                                                }
                                                            ?>
                                                            <span title="<?php echo $selected_variant_icon_class ? 'Has selected item' : ''; ?>" class="dashicons dashicons-color-picker <?= $selected_variant_icon_class; ?> "></span>
                                                        </div>

                                                    <?php endif; ?>
                                                </td>
                                                <td><?php echo $item['quantity']; ?></td>
                                            <?php else: ?>
                                                <td>
                                                    <?php echo $prod->get_name(); ?> (<?php echo $item['quantity']; ?>)
                                                </td>
                                            <?php endif; ?>
                                        </tr>
                                    <?php


                                    if (isset($item['addons']) && $item['addons']) {

                                        foreach ($item['addons'] as $item_addon) {

                                            if (isset($item_addon['hidden']) && $item_addon['hidden']) {
                                                continue;
                                            }

                                            // $current_set_item_addons_count = count($item['addons']);

                                            $addon_selected_variant_icon_class = '';
                                            $addon_selected_variant_attributes = [];
                                            $addon_selected_variant_price = 0;

                                            $addon_rental_inv_id = $item_addon['rental_inv_id'];
                                            $addon_product = wc_get_product($item_addon['product_id']);
                                            $item_addon_optional_items = $item_addon['variants_optional'];

                                            if ($addon_product) {
                                        ?>

                                            <tr>
                                                <?php if(!isset($rental_set_listing_style) || $rental_set_listing_style == 'standard'):?>


                                                    <td class="td-item-type is-add-on">ADD-ON</td>
                                                    <td class="td-item-img"><?php echo $addon_product->get_image('medium'); ?></td>

                                                    <td class="td-item-name">
                                                        <a href="<?php echo $addon_product->get_permalink(); ?>"><?php echo $addon_product->get_name(); ?></a>

                                                        <?php if ($item_addon_optional_items):

                                                            $current_item_addon_optional_items_count = count($item_addon_optional_items);
                                                            $addon_selected_variant_icon_class = (isset($item_addon['has_selected']) && $item_addon['has_selected'] == 1) || $current_item_addon_optional_items_count < 2 ? 'selected-variant-icon' : '';

                                                            $item_addon_optional_items_data = [];

                                                            foreach($item_addon_optional_items as $item_addon_optional_item) {

                                                                $optional_item_data = get_variant_data($item_addon_optional_item['product_id'], $item_addon_optional_item['variant_id']);

                                                                if (
                                                                    (isset($item_addon_optional_item['is_selected']) && $item_addon_optional_item['is_selected'])
                                                                    || $current_item_addon_optional_items_count < 2
                                                                ) {
                                                                // if (isset($item_addon_optional_item['default']) && $item_addon_optional_item['default']) {

                                                                    $optional_item_data['is_selected'] = 1;
                                                                    // $optional_item_data['default'] = 1;

                                                                    $addon_selected_variant_attributes = isset($optional_item_data['attrs']) && $optional_item_data['attrs'] ? $optional_item_data['attrs'] : [];
                                                                    $addon_selected_variant_price = $optional_item_data['price'];
                                                                }

                                                                $item_addon_optional_items_data[] = $optional_item_data;
                                                            }

                                                            $json_flags = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE;

                                                            $item_addon_optional_items_data_encoded = json_encode($item_addon_optional_items_data, $json_flags);

                                                            $set_has_selectable_variants = true;
                                                        ?>

                                                            <div class="choose-item-variant-addon"
                                                                data-has-selected="<?= isset($item_addon['has_selected']) && $item_addon['has_selected'] == 1 ? 1 : 0; ?>"
                                                                data-addon-rental-inv-id="<?= $addon_rental_inv_id; ?>"
                                                                data-parent-set-item-product-id="<?= esc_attr($item['product_id']); ?>"
                                                                data-items="<?php echo esc_attr($item_addon_optional_items_data_encoded); ?>">

                                                                <?php
                                                                    $addon_attrs_joined = '';

                                                                    if ($addon_selected_variant_attributes) {
                                                                        foreach($addon_selected_variant_attributes as $key => $selected_variant_attribute) {
                                                                            if ($key == 0) {
                                                                                $addon_attrs_joined .= $selected_variant_attribute;
                                                                            } else {
                                                                                $addon_attrs_joined .= ', ' . $selected_variant_attribute;
                                                                            }

                                                                        }

                                                                        echo '<p>' . $addon_attrs_joined . '</p>';
                                                                    }

                                                                    if ($addon_selected_variant_price && !rental_prices_are_hidden('catalog')) {
                                                                        echo '<p>' . wc_price($addon_selected_variant_price) . '</p>';
                                                                    }
                                                                ?>
                                                                <span title="<?php echo $addon_selected_variant_icon_class ? 'Has selected item' : ''; ?>" class="dashicons dashicons-color-picker <?= $addon_selected_variant_icon_class; ?> "></span>
                                                            </div>

                                                        <?php endif; ?>
                                                    </td>
                                                    <td><?php echo $item_addon['quantity']; ?></td>
                                                <?php else: ?>
                                                    <td>
                                                        <?php echo $addon_product->get_name(); ?> (<?php echo $item_addon['quantity']; ?>)
                                                    </td>
                                                <?php endif; ?>
                                            </tr>

                                        <?php
                                            } // if ($addon_product)

                                        }
                                    }

                                }
                            } ?>
                        </tbody>
                    </table>

                    <?php if ($set_has_selectable_variants): ?>
                    <!-- The Modal-Rental -->
                    <div id="myModalRental" class="modal-rental">
                        <!-- Modal-Rental content -->
                        <div class="modal-rental-content">
                            <span class="modal-rental-close">&times;</span>

                            <h4> Variations availability </h4>
                            <hr>

                            <div class="variants-list">

                            </div>

                            <input type="hidden" id="set-id" value="<?php echo get_the_ID(); ?>">

                            <div class="action-btn-wrapper">
                                <button type="button" class="rental-btn btn-success choose-variant">Choose Selected</button>
                                <button type="button" class="rental-btn btn-danger remove-variant">Cancel</button>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <div id="modalMessageContainer"></div>

                <?php

                }
            }
        }
        add_action('woocommerce_after_single_product_summary', 'rental_set_products_list', 10);

        function rental_price_multipliers_table() {
            global $product;

            if (get_option('rental_dates_on_checkout', 0) == 1 && get_option('rental_allow_overbook', 1) == 1) {
                // if we have allow overbook and rental dates on checkout page
                rental_dates_on_checkout_page_option_effect();
            }

            if (isset($_COOKIE['rental_start_date']) && $_COOKIE['rental_start_date'] && isset($_COOKIE['rental_zip']) && $_COOKIE['rental_zip'] &&
                !rental_prices_are_hidden('catalog')) {
                if ($product->is_type('simple')) {

                    // get price multiplier items of the current variant or set
                    $price_multipliers = get_price_multiplier_items_by_post_id($product->get_id());
                    $price_multiplier_items = isset($price_multipliers['price_multiplier_items']) ? $price_multipliers['price_multiplier_items'] : [];
                    if (!empty($price_multiplier_items)) {
                        $days = rental_get_daily_prices($price_multiplier_items);
                        ?>
                        <table id="rental_multipliers_table" class="rntp-table">
                            <thead>
                            <tr class="rate-header">
                                <th class="period-text"><?php _e('Period', 'rentopian-sync') ?></th>
                                <th class="rate-text"><?php _e('Rate', 'rentopian-sync') ?></th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($days as $day => $coefficient) { ?>
                                <tr>
                                    <td class=""><?php echo sprintf(_n('%s Day', '%s Days', $day, 'rentopian-sync'), $day); ?></td>
                                    <td class=""><?php echo wc_price($coefficient * $product->get_price()); ?></td>
                                </tr>
                            <?php } ?>
                            </tbody>
                        </table>
                        <?php
                    }
                }
            }
        }
        add_action('woocommerce_after_single_product_summary', 'rental_price_multipliers_table', 10);

        if (rental_prices_are_hidden('cart')) {
            function rental_return_empty_string() {
                return '';
            }
            function rental_return_empty_array() {
                return [];
            }
            // Change cart item subtotal
            add_filter('woocommerce_cart_item_subtotal', 'rental_return_empty_string');

            // Change cart subtotal in cart totals
            add_filter('woocommerce_cart_subtotal', 'rental_return_empty_string');

            // Change cart total in cart totals
            add_filter('woocommerce_cart_total', 'rental_return_empty_string');

            // Per-line amount on the order details table and in order emails
            add_filter('woocommerce_order_formatted_line_subtotal', 'rental_return_empty_string');

            // Order totals rows (subtotal, shipping, fees, tax, total). The
            // order details template skips these rows itself, but emails render
            // WooCommerce's own template, so the rows are dropped at source.
            // Late priority so it wins over the rows other hooks add.
            add_filter('woocommerce_get_order_item_totals', 'rental_return_empty_array', 999);
        } else {
            // Add fees to cart and checkout
            function rental_calculate_cart_fees() {
                global $woocommerce;

                $fees = rental_calculate_order_total();
                if ( !$fees) {
                    return;
                }

                // Add labor cost to cart and checkout
                if ($fees['labor_cost']) {
                    $woocommerce->cart->add_fee(__('Labor Cost', 'rentopian-sync'), $fees['labor_cost']);
                }

                // Add damage waiver fee to cart and checkout
                if ($fees['damage_waiver']) {
                    $woocommerce->cart->add_fee(__('Damage Waiver', 'rentopian-sync'), $fees['damage_waiver']);
                    if ($fees['damage_waiver_tax']) {
                        $custom_tax_label_opt = get_option('rental_tax_text') ;
                        $custom_tax_label = ($custom_tax_label_opt == '') ? __('Tax', 'rentopian-sync') : $custom_tax_label_opt;
                        $woocommerce->cart->add_fee(__('Damage Waiver '.$custom_tax_label, 'rentopian-sync'), $fees['damage_waiver_tax']);
                    }
                }

                // Add rush fee to cart and checkout
                if ($fees['rush_fee']) {
                    $woocommerce->cart->add_fee(__('Rush Fee', 'rentopian-sync'), $fees['rush_fee']);
                }

                // Add auto applied order fees
                if ( $fees['order_fees'] && !get_option('rental_exclude_order_fees', 0) ) {
                    foreach ($fees['order_fees'] as $order_fee) {
                        $woocommerce->cart->add_fee($order_fee['title'], $order_fee['amount']);
                    }
                }

                // Add security deposit fee to cart and checkout
                if (get_option('rental_allow_to_pay_security_deposit') && !empty($security_deposit = rental_get_security_deposit())) {
                    if ($fees['security_deposit_fee']) {
                        $woocommerce->cart->add_fee(__($security_deposit['title'], 'rentopian-sync'), $fees['security_deposit_fee']);
                    }
                }


                $tax = 0;
                // Add shipping fee to cart and checkout
                if ($fees['shipping_tax']) {
                    // if (get_option('rental_combine_shipping_tax')) {
                    $delivery_settings = rental_get_delivery_settings();
                    if ($delivery_settings && $delivery_settings['enable_combined_shipping_tax_for_website']) {
                        $tax = $fees['shipping_tax'];
                    } else {

                        $custom_tax_label_opt = get_option('rental_tax_text') ;
                        $custom_tax_label = ($custom_tax_label_opt == '') ? __('Tax', 'rentopian-sync') : $custom_tax_label_opt;

                        $custom_shipping_label_opt = get_option('rental_shipping_text') ;
                        $custom_shipping_label = ($custom_shipping_label_opt == '') ? __('Shipping', 'rentopian-sync') : $custom_shipping_label_opt;

                        $woocommerce->cart->add_fee(__($custom_shipping_label.' '.$custom_tax_label, 'rentopian-sync'), $fees['shipping_tax']);
                    }
                }

                // Add tax fee to cart and checkout
                $tax += $fees['rental_tax'] + $fees['sale_tax'] + $fees['service_tax'];

                if ($tax && !isset($fees['is_auto_tax'])) {
                    $woocommerce->cart->add_fee(get_option('rental_tax_text')?: __('Tax', 'rentopian-sync'), $tax);
                }

                // Add payment tip fee to direct payment checkout
                $rental_payment_tips = get_option('rental_payment_tips', []);
                if (
                    $rental_payment_tips
                    && get_option("rental_direct_only_bookings", 0)
                    && get_option("rental_payment_tips_enabled", 0)
                    && is_checkout()
                ) {

                    add_filter('woocommerce_cart_totals_fee_html', function ( $fee_html, $fee ) {
                        if ( $fee->name === __('Tip', 'rentopian-sync') ) {
                            $fee_html = '<span id="rental-tip-amount" class="rental-tip-amount">' . $fee_html . '</span>';
                        }
                        return $fee_html;
                    }, 10, 2);

                    $woocommerce->cart->add_fee(__('Tip', 'rentopian-sync'), $fees['tip']);
                }

                if (isset($fees['is_auto_tax']) && $fees['is_auto_tax'] == 1 && isset($fees['auto_tax'])) {

                    // Add auto_tax as a WC cart fee so it is saved as a visible order line
                    // item and the saved order total matches the displayed breakdown.
                    if (!empty($fees['auto_tax'])) {
                        $auto_tax_label = get_option('rental_tax_text') ?: __('Tax', 'rentopian-sync');
                        $woocommerce->cart->add_fee($auto_tax_label, $fees['auto_tax']);
                        // Keep the option in sync for backward-compat with orders saved
                        // before this fix (rental_add_items_to_order_totals reads it).
                        update_option('rental_auto_tax', $fees['auto_tax']);
                    }

                    add_action( 'woocommerce_cart_totals_before_order_total', 'rental_add_auto_tax_rows' , 99);
                    add_action( 'woocommerce_review_order_before_order_total', 'rental_add_auto_tax_rows' , 99);
                    if (!function_exists('rental_add_auto_tax_rows')) {
                        function rental_add_auto_tax_rows() {
                            $fees = rental_calculate_order_total();
                            $tax = 0;
                            if ($fees['shipping_tax']) {
                                // if (get_option('rental_combine_shipping_tax')) {
                                $delivery_settings = rental_get_delivery_settings();
                                if ($delivery_settings && $delivery_settings['enable_combined_shipping_tax_for_website']) {
                                    $tax = $fees['shipping_tax'];
                                }
                            }
                            if (isset($fees['auto_tax'])) {
                                $tax += $fees['auto_tax'];
                                if ($tax) {
                                    update_option('rental_auto_tax', $tax);
                                }
                                if (isset($fees['auto_tax_rate']) && !empty($fees['auto_tax_rate'])) {
                                    $custom_tax_label = get_option('rental_tax_text') ?: __('Tax', 'rentopian-sync');
                                    // Skip HTML when auto_tax is already a WC cart fee - WooCommerce renders it automatically.
                                    $wc_fees = WC()->cart ? WC()->cart->get_fees() : [];
                                    $auto_tax_is_wc_fee = false;
                                    foreach ($wc_fees as $_wc_fee) {
                                        if ($_wc_fee->name === $custom_tax_label) { $auto_tax_is_wc_fee = true; break; }
                                    }
                                    if (!$auto_tax_is_wc_fee) { ?>
                                    <tr class="auto-tax-key">
                                        <th>
                                            <?php
                                            if($custom_tax_label){
                                                echo $custom_tax_label ."</th>";
                                            }
                                            else{
                                                _e( 'Auto Tax', 'rentopian-sync' );
                                            }
                                            ?>
                                        </th>
                                        <td class="auto-tax-value">
                                            <strong>
                                                <?php echo get_woocommerce_currency_symbol().format_value_to_fixed_precision($tax,2)?>
                                            </strong>
                                        </td>
                                    </tr>
                                    <?php
                                    // echo "<tr>";
                                    // // echo "<th>Auto Tax Total Rate</th>";
                                    // // echo "<td>" .get_woocommerce_currency_symbol().format_value_to_fixed_precision($fees['auto_tax_rate'],3). "</td>";
                                    // foreach($fees['rental_auto_tax_rates'] as $value) {
                                    //     echo "<tr>";
                                    //         echo "<th> " .$value['name']. " </th>";
                                    //         echo "<td> " .get_woocommerce_currency_symbol().format_value_to_fixed_precision($value['value'],2). " </td>";
                                    //     echo "</tr>";
                                    // }
                                    // echo "</tr>";
                                    } // end if (!$auto_tax_is_wc_fee)
                                } // end if (auto_tax_rate)



                            }
                        }
                    }
                }

            }
            add_action('woocommerce_cart_calculate_fees', 'rental_calculate_cart_fees', 20);

            function rental_add_items_to_order_totals( $total_rows, $order, $tax_display ) {
                // Return early if order_total is not set
                if (!isset($total_rows['order_total'])) {
                    return $total_rows;
                }
            
                // Save the last total row in a variable and remove it
                $grand_total = $total_rows['order_total'];
                unset($total_rows['order_total']);
            
                $rental_auto_tax = get_option('rental_auto_tax', 0);
                $rental_tax_type = get_option('rental_tax_type', 1);
            
                if (!empty($rental_auto_tax) && $rental_tax_type != 1) {
                    $custom_tax_label = get_option('rental_tax_text') ?: __('Tax', 'rentopian-sync');

                    // For orders placed after the fix, auto_tax is saved as a WC order fee
                    // and WooCommerce already renders it — skip to prevent a duplicate row.
                    $auto_tax_already_fee = false;
                    if ($order) {
                        foreach ($order->get_fees() as $_order_fee) {
                            if ($_order_fee->get_name() === $custom_tax_label) {
                                $auto_tax_already_fee = true;
                                break;
                            }
                        }
                    }

                    if (!$auto_tax_already_fee) {
                        $total_rows['auto_tax'] = [
                            'label' => esc_html($custom_tax_label),
                            'value' => wc_price($rental_auto_tax),
                        ];
                    }
                }
            
                // Restore the total cost as the last row
                $total_rows['order_total'] = $grand_total;
            
                return $total_rows;
            }
            add_filter( 'woocommerce_get_order_item_totals', 'rental_add_items_to_order_totals', 30, 3 );


            function rental_before_cart_totals() {
                global $woocommerce;

                $fees = rental_calculate_order_total();
                if ( !$fees) {
                    return;
                }

                if (!empty($fees['coupons'])) {
                    // single/multiple coupons
                    foreach($fees['coupons'] as $coupon) {
                        if (isset($woocommerce->cart->coupon_discount_totals[$coupon['code']])) {
                            $woocommerce->cart->coupon_discount_totals[$coupon['code']] = $coupon['coupon_discount'];
                        }
                    }
                }

                WC()->cart->total = $fees['total'];
            }
            add_action('woocommerce_before_cart_totals', 'rental_before_cart_totals', 21);
            add_action('woocommerce_after_calculate_totals', 'rental_before_cart_totals', 21);

            function rental_package_rates( $rates, $package ) {

                $pickup_delivery = get_option( 'rental_pickup_delivery', 'company_delivery_return' );

                // ─────────────────────────────────────────────────────────────────────
                // company_delivery_return: NO local pickup should ever appear
                // ─────────────────────────────────────────────────────────────────────
                if ( $pickup_delivery === 'company_delivery_return' ) {
                    foreach ( $rates as $rate_id => $rate ) {
                        if ( 'local_pickup' === $rate->method_id ) {
                            unset( $rates[ $rate_id ] );
                        }
                    }

                    // If this cleanup fired, self-heal the DB so it doesn't keep happening
                    // (checks internally if repair is needed before doing DB work)
                    rental_enforce_no_local_pickup_in_db();

                    return $rates;
                }
                
                // ─── Client pickup/return only: remove miles_based and reduced_rate ───
                if ( $pickup_delivery === 'client_pickup_return' ) {
                    unset( $rates['reduced_rate'], $rates['miles_based'] );
                }
                
                // ─── Min order amount filter: remove delivery options, keep local pickup ───
                $delivery_settings   = rental_get_delivery_settings();
                $min_order_amount    = $delivery_settings['restriction_fixed_min_for_website'] ?? 0;
                $allow_pickup_on_min = $delivery_settings['restriction_fixed_min_allow_pickup_for_website'] ?? false;

                if (
                    $min_order_amount
                    && WC()->cart->get_subtotal() < $min_order_amount
                    && $allow_pickup_on_min
                    && $pickup_delivery === 'company_client_delivery_return'
                ) {
                    unset( $rates['reduced_rate'], $rates['miles_based'] );
                }


                // ─── No-customer-pickup products filter ───
                if (
                    in_array( $pickup_delivery, [ 'company_client_delivery_return', 'client_pickup_return' ], true )
                    && ( $no_pickup_list = get_option( 'no_customer_pickup_products_list', [] ) )
                ) {
                    foreach ( WC()->cart->get_cart() as $cart_item ) {
                        $product_id   = $cart_item['product_id'];
                        $variation_id = ! empty( $cart_item['variation_id'] ) ? $cart_item['variation_id'] : 0;

                        if (
                            in_array( $product_id, $no_pickup_list )
                            || in_array( $variation_id, $no_pickup_list )
                        ) {
                            foreach ( $rates as $rate_id => $rate ) {
                                if ( 'local_pickup' === $rate->method_id ) {
                                    unset( $rates[ $rate_id ] );
                                }
                            }
                            break;
                        }
                    }
                }

                // ─── FALLBACK: Ensure local_pickup exists when it SHOULD be available ───
                // Covers both company_client_delivery_return AND client_pickup_return
                $modes_requiring_local_pickup = [
                    'company_client_delivery_return',
                    'client_pickup_return',
                ];

                if ( in_array( $pickup_delivery, $modes_requiring_local_pickup, true ) ) {
                    $has_local_pickup = false;

                    foreach ( $rates as $rate ) {
                        if ( 'local_pickup' === $rate->method_id ) {
                            $has_local_pickup = true;
                            break;
                        }
                    }

                    if ( ! $has_local_pickup ) {
                        // Self-heal the DB
                        rental_repair_local_pickup_zones();

                        // Inject a local_pickup rate so the customer isn't stuck
                        $custom_shipping_label = get_option( 'rental_shipping_text', '' );
                        $label = ! empty( $custom_shipping_label ) ? $custom_shipping_label : __( 'Local Pickup', 'rentopian-sync' );

                        $fallback_rate = new WC_Shipping_Rate(
                            'local_pickup:fallback',
                            $label,
                            0,
                            [],
                            'local_pickup'
                        );

                        $rates['local_pickup:fallback'] = $fallback_rate;
                    }
                }

                return $rates;
            }
            add_filter( 'woocommerce_package_rates', 'rental_package_rates', 10, 2 );
        }

        // Custom Fields
        function checkout_rental_dates() {
            $rntp_checkout_offset_mode = function_exists('rental_get_event_date_offsets') && rental_get_event_date_offsets();
            ?>
             <div id="rntp-rental-dates-summary-wrapper" class="rental-dates-summary-wrapper">
                <strong><?php echo $rntp_checkout_offset_mode ? esc_html__('Event date', 'rentopian-sync') : esc_html__('Rental Date(s)', 'rentopian-sync'); ?></strong>
            
                <?php 
                    if (!get_option('rental_dates_on_checkout') && get_option('rental_form_layout') === "in-cart") :
                ?>
                
                    <span title="Click here to change the dates!" class="dates-edit dashicons dashicons-edit"></span>
                
                <?php endif ?>
                
                <ul class="rental-dates-summary">
                        <?php if (get_option('rental_synchronized_product_type') != "sale"): ?>
                        <li>
                            <?php
                                $date_format = 'M j';
                                if ( !get_option('rental_hide_time_pickers')) {
                                    $date_format .= ', g:i A';
                                }

                                if ( get_option('rental_synchronized_product_type') == "hourly") {

                                    echo isset($_COOKIE["rental_hourly_start_date"])? date('M j', strtotime($_COOKIE["rental_hourly_start_date"])).' &rarr; ' : '';
                                    echo isset($_COOKIE["rental_hourly_end_date"])? date('M j', strtotime($_COOKIE["rental_hourly_end_date"])) : '';
                                } else {

                                    $decrypted_rental_start_date = "";
                                    if (isset($_COOKIE['rental_start_date']) && $_COOKIE['rental_start_date']) {
                                        $decrypted_rental_start_date = decrypt_data($_COOKIE['rental_start_date'], get_option('rental_encryption_key'));
                                    }

                                    if ($decrypted_rental_start_date) {

                                        $start_date_exploded = explode(' ', $decrypted_rental_start_date);

                                        $decrypted_rental_end_date = "";
                                        if (isset($_COOKIE['rental_end_date']) && $_COOKIE['rental_end_date']) {
                                            $decrypted_rental_end_date = decrypt_data($_COOKIE['rental_end_date'], get_option('rental_encryption_key'));
                                        }

                                        $end_date_exploded = $decrypted_rental_end_date ? explode(' ', $decrypted_rental_end_date) : [];

                                        // EVENT DATE OFFSET: primary line = event; rental period block is below <ul>.
                                        if ($rntp_checkout_offset_mode) {
                                            // Offset mode: primary line = event date (like mini-cart); rental period block is below the <ul>.
                                            $ev_raw = '';
                                            if (isset($_COOKIE['rental_event_date']) && $_COOKIE['rental_event_date']) {
                                                $ev_raw = decrypt_data($_COOKIE['rental_event_date'], get_option('rental_encryption_key'));
                                            }
                                            $hide_time_chk = (bool) get_option('rental_hide_time_pickers');
                                            $primary_line  = function_exists('rental_format_checkout_event_date_display_line')
                                                ? rental_format_checkout_event_date_display_line($ev_raw, $hide_time_chk)
                                                : '';
                                            if ($primary_line === '' && $decrypted_rental_start_date) {
                                                $primary_line = date($date_format, strtotime($decrypted_rental_start_date));
                                            }
                                            echo esc_html($primary_line);

                                        } else {
                                            // Standard mode
                                            
                                            $start_date_only_date = 0;
                                            $end_date_only_date = -1;

                                            if ($start_date_exploded) {
                                                $start_date_only_date = strtotime($start_date_exploded[0]);
                                            }

                                            if ($end_date_exploded) {
                                                $end_date_only_date = strtotime($end_date_exploded[0]);
                                            }

                                            if ($start_date_only_date == $end_date_only_date) {

                                                echo $decrypted_rental_start_date ? date('M j', strtotime($decrypted_rental_start_date)).' ' : '';
                                                if ( !get_option('rental_hide_time_pickers')) {
                                                    echo $decrypted_rental_start_date? date('g:i A', strtotime($decrypted_rental_start_date)).' &rarr; ' : '';
                                                    echo $decrypted_rental_end_date? date('g:i A', strtotime($decrypted_rental_end_date)) : '';
                                                }

                                            } else {

                                                echo $decrypted_rental_start_date? date($date_format, strtotime($decrypted_rental_start_date)) : '';
                                                if (!get_option('rental_hide_end_date')) {
                                                    echo $decrypted_rental_end_date? ' &rarr; '.date($date_format, strtotime($decrypted_rental_end_date)) : '';
                                                }
                                            }
                                        }
                                    }
                                }
                            ?>
                        </li>
                    <?php endif; ?>
                    <?php if (
                                !get_option('rental_hide_zip')
                            ) :?>
                    <li>
                        <strong><?php _e('ZIP Code', 'rentopian-sync') ?></strong>
                        <?php
                            $dates_on_checkout = get_option('rental_dates_on_checkout', 0) == 1 && get_option('rental_allow_overbook', 1) == 1 ? 1 : 0;
                            if ( !rental_is_date_form_filled() && $dates_on_checkout == 1) {
                                $zip = '';
                            } else {

                                $decrypted_rental_zip = 0;
                                if (isset($_COOKIE['rental_zip']) && $_COOKIE['rental_zip']) {
                                    $decrypted_rental_zip = decrypt_data($_COOKIE['rental_zip'], get_option('rental_encryption_key'));
                                }

                                $zip = $decrypted_rental_zip ? $decrypted_rental_zip : '';
                            }
                            echo $zip;
                        ?>
                    </li>
                    <?php endif ?>
                </ul>

                <?php
                // EVENT DATE OFFSET: Full rental period under the list (event date is in the first <li> above).
                if ($rntp_checkout_offset_mode && get_option('rental_synchronized_product_type') != 'sale') {
                    $pd_start = '';
                    $pd_end   = '';
                    if (isset($_COOKIE['rental_start_date']) && $_COOKIE['rental_start_date']) {
                        $pd_start = decrypt_data($_COOKIE['rental_start_date'], get_option('rental_encryption_key'));
                    }
                    if (isset($_COOKIE['rental_end_date']) && $_COOKIE['rental_end_date']) {
                        $pd_end = decrypt_data($_COOKIE['rental_end_date'], get_option('rental_encryption_key'));
                    }
                    if ($pd_start && $pd_end && function_exists('rental_format_rental_period_markup')) {
                        echo rental_format_rental_period_markup(
                            $pd_start,
                            $pd_end,
                            (bool) get_option('rental_hide_time_pickers')
                        );
                    }
                }
                ?>

            </div>
            <?php
        }
        add_action('woocommerce_checkout_before_order_review', 'checkout_rental_dates');

        /**
         * Add the field to the checkout
         */
        function checkout_rental_field($checkout) {

            if (
                get_option('rental_dates_on_checkout', 0) == 1 
                && get_option('rental_allow_overbook', 1) == 1 
                && get_option('rental_checkout_layout_mode', 'classic') === 'modern'
            ) {
                return;
            }
            
            if (get_option('rental_event_start_time')) {
                $opt_default_start_time = get_option('rental_default_start_time', '09:00 AM');

                $decrypted_rental_start_date = "";
                if (isset($_COOKIE['rental_start_date']) && $_COOKIE['rental_start_date']) {
                    $decrypted_rental_start_date = decrypt_data($_COOKIE['rental_start_date'], get_option('rental_encryption_key'));
                }
                $start_time = $decrypted_rental_start_date ? date('g:i A', strtotime($decrypted_rental_start_date)): $opt_default_start_time;

                woocommerce_form_field('rental_event_time', ['type' => 'text',
                    'class' => ['rental-start-time form-row-wide'],
                    'label' => __('Event start time', 'rentopian-sync'),
                    'required' => false,
                    'custom_attributes' => ['min' => $start_time],
                ], isset($_COOKIE["rental_event_time"]) ? $_COOKIE["rental_event_time"] : $start_time);
            }

            // damage waiver
            if (rental_is_damage_waiver_enabled()) {
                echo '<div id="damage_waiver" class="rntp-form-block"><h3>' . __('Damage Waiver', 'rentopian-sync') . '</h3>';
                $options = [];
                $options['buy'] = __('Buy Damage Waiver', 'rentopian-sync');
                $options['exempt'] = __('Opt out of Damage Waiver', 'rentopian-sync');
                if (isset($_COOKIE['rental_exempt_waiver'])) {
                    $default = $_COOKIE['rental_exempt_waiver']? 'exempt': 'buy';
                } else {
                    $default = get_option('rental_buy_damage_waiver_by_default')? 'buy': 'exempt';
                }

                woocommerce_form_field("damage_waiver", [
                    'type' => 'radio',
                    'class' => ['radio-toolbar'],
                    // 'label' => '__('Delivery', 'rentopian-sync')',
                    'required' => true,
                    'label_class' => ['rntp-label'],
                    'input_class' => ['rntp-radio'],
                    'options' => $options,
                ], $default);
                echo '</div>';
            }

            rental_init_custom_fields();
        }
        add_action('woocommerce_after_order_notes', 'checkout_rental_field');

        /**
         * Add the damage waiver selector to the cart totals
         */
        function rental_cart_damage_waiver_field() {

            if ( !rental_is_damage_waiver_shown_on_cart()) {
                return;
            }

            rental_render_damage_waiver_field('rntp-cart-damage-waiver');
        }
        add_action('woocommerce_before_cart_totals', 'rental_cart_damage_waiver_field', 10);


        function rental_referral_sources_field() {

            if (
                get_option('rental_dates_on_checkout', 0) == 1 
                && get_option('rental_allow_overbook', 1) == 1 
                && get_option('rental_checkout_layout_mode', 'classic') === 'modern'
            ) {
                return;
            }
            

            if (get_option('rental_referral_sources_setting', 0) && $rental_referral_sources = get_option('rental_referral_sources', '')) {

                $options_list[0] = 'Please, select a referral source.';
                foreach ($rental_referral_sources as $option) {
                    $options_list[$option->id] = $option->title;
                }

                $opt_rental_referral_sources_text = get_option('rental_referral_sources_text');
                $rental_referral_sources_text = $opt_rental_referral_sources_text ? $opt_rental_referral_sources_text : 'Referral source (optional)';

                echo '<div><label for="rental_referral_source_id">' . __($rental_referral_sources_text) . '</label>';
                woocommerce_form_field("rental_referral_source_id", [
                    'type' => 'select',
                    'class' => 'rental_referral_source_id',
                    // 'label'   => __('Select a referral source'),
                    'options' => $options_list,
                ]);
                echo '</div>';
            }
        }
        add_action('woocommerce_after_checkout_billing_form', 'rental_referral_sources_field');


        function rental_event_types_field() {

            if (get_option('rental_event_types_setting', 0) && $rental_event_types = get_option('rental_event_types', [])) {
                
                $options_list[0] = 'Please, select an Event Type.';
                foreach ($rental_event_types as $option) {
                    $options_list[$option->id] = $option->title;
                }

                $opt_rental_event_types_text = get_option('rental_event_types_text');
                $rental_event_types_text = $opt_rental_event_types_text ? $opt_rental_event_types_text : 'Event Type (optional)';

                echo '<div><label for="rental_event_types_id">' . __($rental_event_types_text) . '</label>';
                woocommerce_form_field("rental_event_types_id", [
                    'type' => 'select',
                    'class' => 'rental_event_types_id',
                    // 'label'   => __('Select an Event Type'),
                    'options' => $options_list,
                ]);
                echo '</div>';
            }
        }
        add_action('woocommerce_after_checkout_billing_form', 'rental_event_types_field');

        // delivery time selections 
        function rental_delivery_time_selections_field() {

            if ($delivery_time_selections = get_option('delivery_time_selections', '')) {

                $start_date_day_name = $end_date_day_name = '';

                $opt_default_start_time = get_option('rental_default_start_time', '09:00 AM');
                $opt_default_end_time = get_option('rental_default_end_time', '05:00 PM');
              
                if ($rental_start_date = isset($_COOKIE['rental_start_date']) && $_COOKIE['rental_start_date'] ? $_COOKIE['rental_start_date'] : '') {
                    
                    $start_date = parseWithDefaultTime($rental_start_date, $opt_default_start_time);
                    $start_date_day_name = $start_date->format('l'); // Full name of the day
                }

                if ($rental_end_date = isset($_COOKIE['rental_end_date']) && $_COOKIE['rental_end_date'] ? $_COOKIE['rental_end_date'] : '') {
                    
                    $end_date = parseWithDefaultTime($rental_end_date, $opt_default_end_time);
                    $end_date_day_name = $end_date->format('l');
                }

                $options_list[0] = 'Please, select a delivery time range.';
                foreach ($delivery_time_selections as $option) {

                    if ($option->week_day != 0) {

                        $week_day_name = getDayNameByNumber($option->week_day);

                        if ($week_day_name != $start_date_day_name) {
                            continue;
                        }
                    }

                    $options_list[$option->id] = $option->title ? $option->title . '  ' . $option->start_time . '-' . $option->end_time : $option->start_time . '-' . $option->end_time;
                }

                $delivery_time_selections_text = "Delivery time selections";

                echo '<div><label for="delivery_time_selections_id">' . __($delivery_time_selections_text) . '</label>';
                woocommerce_form_field("delivery_time_selections_id", [
                    'type' => 'select',
                    'class' => 'delivery_time_selections_id',
                    // 'label'   => __('Select a time selection'),
                    'options' => $options_list,
                    'required' => false
                ]);
                echo '</div>';


                // Generating pickup fields if allowed
                $delivery_settings = rental_get_delivery_settings();
                if (!$delivery_settings['charge_only_delivery_for_website']) {

                    $options_list_pickup[0] = 'Please, select a pickup time range.';
                    foreach ($delivery_time_selections as $option) {

                        if ($option->week_day != 0) {

                            $week_day_name = getDayNameByNumber($option->week_day);
                            if ($week_day_name != $end_date_day_name) {
                                continue;
                            }
                        }

                        $options_list_pickup[$option->id] = $option->title ? $option->title . '  ' . $option->start_time . '-' . $option->end_time : $option->start_time . '-' . $option->end_time;
                    }

                    $pickup_time_selections_text = "Pickup time selections";

                    echo '<div><label for="pickup_time_selections_id">' . __($pickup_time_selections_text) . '</label>';
                    woocommerce_form_field("pickup_time_selections_id", [
                        'type' => 'select',
                        'class' => 'pickup_time_selections_id',
                        // 'label'   => __('Select a time selection'),
                        'options' => $options_list_pickup,
                        'required' => false
                    ]);
                    echo '</div>';

                }

            }
        }
        add_action('woocommerce_after_checkout_billing_form', 'rental_delivery_time_selections_field');
        


        function rental_payment_tips() {

            $rental_payment_tips = get_option('rental_payment_tips', []);
            if (
                $rental_payment_tips
                && get_option("rental_direct_only_bookings", 0)
                && get_option("rental_payment_tips_enabled", 0)
            ) {

                $options_list = [];
                $default_option_id = null;

                $options_list[0] = 'No Tip (Thank You!)';
                foreach ($rental_payment_tips as $option) {
                    $options_list[$option->id] = $option->title;

                    if ($option->default) {
                        $default_option_id = $option->id;
                    }
                }

                $default_option_id = $default_option_id ?? array_key_first($options_list); // Use the first option if no default is specified
                // use the user selected tip id if exists
                if (isset($_COOKIE['TIP_ID_SELECTED_BY_CUSTOMER' . get_new_unique_id()])) {
                    $default_option_id = $_COOKIE['TIP_ID_SELECTED_BY_CUSTOMER' . get_new_unique_id()];
                }
                

                $opt_rental_payment_tips_text = get_option('rental_payment_tips_text');
                $rental_payment_tips_text = $opt_rental_payment_tips_text ? $opt_rental_payment_tips_text : 'Add a Tip (Optional)';

                echo '<div><label for="rental_payment_tip_id">' . __($rental_payment_tips_text) . '</label>';
                woocommerce_form_field("rental_payment_tip_id", [
                    'type' => 'select',
                    'class' => 'rental_payment_tip_id',
                    // 'label'   => __('Select a Tip (optional)'),
                    'default' => $default_option_id, // Set the default option
                    'options' => $options_list,
                ]);
                echo '</div>';
            }
        }
        add_action('woocommerce_after_checkout_billing_form', 'rental_payment_tips');

        function rental_init_custom_fields() {
            if (
                get_option('rental_dates_on_checkout', 0) == 1 
                && get_option('rental_allow_overbook', 1) == 1 
                && get_option('rental_checkout_layout_mode', 'classic') === 'modern'
            ) {
                return;
            }

            $groups = rental_get_custom_fields();
            if ( !empty($groups)) {
                foreach ($groups as $group) {
                    echo '<div class="rntp-form-block"><h3>' . $group[0]['group'] . '</h3>';
                    foreach ($group as $field) {
                        $name = 'rental_custom_fields[' . $field['id'] . ']';
                        $def_class = 'custom-field-'.$field['slug'];
                        $args = [
                            'type' => 'text',
                            'maxlength' => 255,
                            'class' => ['form-row-wide', $def_class],
                            'label' => $field['title'],
                            'required' => $field['required'],
                        ];
                        switch ($field['type']) {
                            case 2: // number
                                $args['type'] = 'number';
                                $args['maxlength'] = 11;
                                $args['custom_attributes'] = ['min' => 0, 'step' => 1];
                                break;
                            case 3: // checkbox
                                $args['type'] = 'checkbox';
                                break;
                            case 4: // datetime
                                $args['type'] = 'datetime-local';
                                break;
                            case 5: // date
                                $args['type'] = 'date';
                                break;
                            case 6: // time
                                $args['type'] = 'time';
                                break;
                            case 7: // email
                                $args['type'] = 'email';
                                break;
                            case 8: // website
                                $args['type'] = 'url';
                                break;
                            case 9: // select
                                $args['type'] = 'select';
                                $opt = [];
                                $options = json_decode($field['options']);
                                $opt[0] = 'Choose';
                                foreach ($options as $option) {
                                    $opt[$option] = $option;
                                }
                                $args['options'] = $opt;
                                break;
                            case 10: // multi select
                                $args['type'] = 'select';
                                $opt = [];
                                $options = json_decode($field['options']);
                                foreach ($options as $option) {
                                    $opt[$option] = $option;
                                }
                                $args['options'] = $opt;
                                $args['custom_attributes'] = ['multiple' => 'multiple'];
                                $name .= '[]';
                                break;
                            case 11: // currency
                                $args['type'] = 'number';
                                $args['maxlength'] = 11;
                                $args['custom_attributes'] = ['min' => 0, 'step' => 0.01];
                                break;
                            case 12: // phone
                                $args['type'] = 'tel';
                                break;
                            case 13: // textarea
                                $args['type'] = 'textarea';
                                break;
                        }
                        woocommerce_form_field($name, $args);
                    }
                    echo '</div>';
                }
            }
        }


        function rental_custom_checkout_field_update_order_meta($order_id) {

            $rental_custom_fields = get_option('rental_custom_fields');
            if ($rental_custom_fields !== false && $rental_custom_fields && isset($_POST['rental_custom_fields'])) {

                $groups = $rental_custom_fields;

                foreach ($groups as $group) {
                    foreach ($group as $field) {
                        $val = isset($_POST['rental_custom_fields'][$field['id']]) ? $_POST['rental_custom_fields'][$field['id']] : 0;

                        if ($val) {
                            update_post_meta($order_id, $field['slug'], $val);
                        }
                    }
                }
            }
        }
        add_action('woocommerce_checkout_update_order_meta','rental_custom_checkout_field_update_order_meta');


        /**
         * Display field value on the order edit page (human-readable: option labels, Yes/No for checkboxes).
         */
        function checkout_rental_field_display_admin_order_meta($order) {
            $order_id = $order->get_id();
            echo '<p><strong>' . __('Rental start date', 'rentopian-sync') . ':</strong> ' . esc_html(get_post_meta($order_id, '_rental_start_date', true)) . '</p>';
            echo '<p><strong>' . __('Rental end date', 'rentopian-sync') . ':</strong> ' . esc_html(get_post_meta($order_id, '_rental_end_date', true)) . '</p>';

            $use_display_helper = (get_option('rental_checkout_layout_mode', 'classic') === 'modern' && class_exists('Rental_Checkout_Order_Display'));
            $order_display = $use_display_helper ? Rental_Checkout_Order_Display::get_instance() : null;

            // Event type, referral source, delivery/pickup times, flexible delivery/pickup (human-readable; fallback ID meta to title)
            $rental_meta_keys = array(
                '_rental_event_type'       => __('Event Type', 'rentopian-sync'),
                '_rental_referral_source'  => __('How Did You Find Us', 'rentopian-sync'),
                '_rental_delivery_time'    => __('Delivery Window', 'rentopian-sync'),
                '_rental_pickup_time'      => __('Pickup Window', 'rentopian-sync'),
                '_rental_flexible_delivery' => __('Flexible with time (Delivery)', 'rentopian-sync'),
                '_rental_flexible_pickup'  => __('Flexible with time (Pickup)', 'rentopian-sync'),
            );
            foreach ($rental_meta_keys as $meta_key => $label) {
                $val = $order->get_meta($meta_key);
                if (($val === '' || $val === null) && $meta_key === '_rental_event_type') {
                    $val = $order->get_meta('rental_event_types_id');
                    if ($val === '' || $val === null) {
                        $val = $order->get_meta('_rental_rental_event_types_id');
                    }
                    if ($val !== '' && $val !== null && $order_display) {
                        $val = $order_display->get_display_value_for_order_meta('_rental_event_type', $val);
                    }
                }
                if (($val === '' || $val === null) && $meta_key === '_rental_referral_source') {
                    $val = $order->get_meta('rental_referral_source_id');
                    if ($val === '' || $val === null) {
                        $val = $order->get_meta('_rental_rental_referral_source_id');
                    }
                    if ($val !== '' && $val !== null && $order_display) {
                        $val = $order_display->get_display_value_for_order_meta('_rental_referral_source', $val);
                    }
                }
                if ($val !== '' && $val !== null) {
                    $display_val = $order_display ? $order_display->get_display_value_for_order_meta($meta_key, $val) : (is_array($val) ? implode(', ', $val) : $val);
                    echo '<p><strong>' . esc_html($label) . ':</strong> ' . esc_html($display_val) . '</p>';
                }
            }

            // Billing email when useful for context
            $billing_email = $order->get_billing_email();
            if ($billing_email) {
                echo '<p><strong>' . esc_html__('Email', 'rentopian-sync') . ':</strong> ' . esc_html($billing_email) . '</p>';
            }

            $rental_custom_fields = get_option('rental_custom_fields');
            if ($rental_custom_fields !== false && $rental_custom_fields) {

                $groups = $rental_custom_fields;

                foreach ($groups as $group) {
                    foreach ($group as $field) {

                        $post_meta = get_post_meta($order_id, $field['slug'], true);
                        if (isset($post_meta) && $post_meta !== '') {
                            if (is_array($post_meta)) {
                                $post_meta = implode(', ', $post_meta);
                            }
                            // Checkbox (type 3): show Yes/No
                            if (isset($field['type']) && ((int) $field['type'] === 3 || $field['type'] === 'checkbox')) {
                                $post_meta = ($post_meta === '1' || $post_meta === 1 || $post_meta === 'yes' || $post_meta === 'on') ? __('Yes', 'rentopian-sync') : __('No', 'rentopian-sync');
                            }
                            echo '<p><strong>' . esc_html(__($field['title'], 'rentopian-sync')) . ':</strong> ' . esc_html($post_meta) . '</p>';
                        }
                    }
                }
            }
        }
        add_action('woocommerce_admin_order_data_after_billing_address', 'checkout_rental_field_display_admin_order_meta', 10, 1);


        function rental_display_custom_fields_on_thankyou( $order_id ) {

            if (get_option('rental_checkout_layout_mode', 'classic') !== 'modern') {

                $rental_custom_fields = get_option('rental_custom_fields');
                if ($rental_custom_fields !== false && $rental_custom_fields) {
    
                    $groups = $rental_custom_fields;
    
                    foreach ($groups as $group) {
                        foreach ($group as $field) {
    
                            $post_meta = get_post_meta($order_id, $field['slug'], true);
    
                            if (isset($post_meta) && $post_meta) {
                                // Check if $post_meta is an array
                                if (is_array($post_meta)) {
                                    $post_meta = implode(', ', $post_meta); // Convert array to a comma-separated string
                                }
                                echo '<p><strong>' . esc_html(__($field['title'], 'rentopian-sync')) . ':</strong> ' . esc_html($post_meta) . '</p>';
                            }
                        }
                    }
                }

            }
           
        }
        add_action('woocommerce_thankyou', 'rental_display_custom_fields_on_thankyou', 10, 1 );

        function rental_email_after_order_table( $order, $sent_to_admin, $plain_text, $email ) {

            if (get_option('rental_checkout_layout_mode', 'classic') !== 'modern') {

                $rental_custom_fields = get_option('rental_custom_fields');
                if ($rental_custom_fields !== false && $rental_custom_fields) {
    
                    $groups = $rental_custom_fields;
    
                    echo '<h2>Additional information</h2>';
    
                    foreach ($groups as $group) {
                        foreach ($group as $field) {
    
                            $post_meta = get_post_meta($order->get_id(), $field['slug'], true);
    
                            if (isset($post_meta) && $post_meta) {
                                // Check if $post_meta is an array
                                if (is_array($post_meta)) {
                                    $post_meta = implode(', ', $post_meta); // Convert array to a comma-separated string
                                }
                                echo '<p><strong>' . esc_html(__($field['title'], 'rentopian-sync')) . ':</strong> ' . esc_html($post_meta) . '</p>';
                            }
                        }
                    }
                }
            }
           
        }
        add_action('woocommerce_email_after_order_table', 'rental_email_after_order_table', 10, 4 );


        function rental_checkout_delivery_options() {
            if (get_option('rental_do_not_use_rentopian_shipping')) {
                return;
            }
        }
        add_action('woocommerce_after_checkout_billing_form', 'rental_checkout_delivery_options');


        function rental_save_pickup_fields($order_id) {
            foreach ($_POST as $key => $value) {
                if (strpos($key, 'rental_pick_up_') === 0) {
                    update_post_meta($order_id, $key, sanitize_text_field($value));
                }
            }
        }
        add_action('woocommerce_checkout_update_order_meta', 'rental_save_pickup_fields');


        function show_rental_pickup_address_on_thankyou_page($order_id) {
            
            $has_pickup_data = false;
            $fields = WC()->countries->get_address_fields('', 'rental_pick_up_');
            foreach ($fields as $key => $_) {
                if (get_post_meta($order_id, $key, true)) {
                    $has_pickup_data = true;
                    break;
                }
            }
            if (!$has_pickup_data) {
                return;
            }

            echo '<div class="woocommerce-column woocommerce-column--2 woocommerce-column--shipping-address col-2 wc-pickup-address">';
            echo '<h2 class="woocommerce-column__title">Pickup address</h2>';
            echo '<address>';

            $line = [];
            $city_state_zip = [];
            
            $address_1 = get_post_meta($order_id, 'rental_pick_up_address_1', true);
            $address_2 = get_post_meta($order_id, 'rental_pick_up_address_2', true);
            $city      = get_post_meta($order_id, 'rental_pick_up_city', true);
            $state     = get_post_meta($order_id, 'rental_pick_up_state', true);
            $postcode  = get_post_meta($order_id, 'rental_pick_up_postcode', true);
            $country   = get_post_meta($order_id, 'rental_pick_up_country', true);

            if ($address_1) $line[] = $address_1;
            if ($address_2) $line[] = $address_2;

            if ($city) $city_state_zip[] = $city;
            if ($state) $city_state_zip[] = $state;
            if ($postcode) $city_state_zip[] = $postcode;

            echo implode('<br>', $line);
            if (!empty($line)) echo '<br>';

            echo implode(', ', $city_state_zip);
            if (!empty($city_state_zip)) echo '<br>';

            if ($country) {
                $country_name = WC()->countries->countries[$country] ?? $country;
                echo esc_html($country_name);
            }

            echo '</address>';
            echo '</div>';
        }
        add_action('woocommerce_thankyou', 'show_rental_pickup_address_on_thankyou_page', 20);
        add_action('woocommerce_order_details_after_order_table', 'show_rental_pickup_address_on_thankyou_page', 20);

       

        // Force "Ship to a different address?" checkbox based on an option
        function rental_force_ship_to_different_address_checked($checked) {
            $delivery_settings = rental_get_delivery_settings();
            $delivery_to_different_address = (bool) $delivery_settings['delivery_to_different_address_option_for_website'] ?? false;
            return $delivery_to_different_address;
        }
        add_filter('woocommerce_ship_to_different_address_checked', 'rental_force_ship_to_different_address_checked');

        

        function rental_checkout_pickup_address_fields(){
            
            $delivery_settings = rental_get_delivery_settings();
            if (get_option('rental_do_not_use_rentopian_shipping') 
                // || !get_option('rental_different_pick_up_addresses')
                || !$delivery_settings['enable_different_pickup_delivery_address_for_website']
            ) {
                return;
            }

            if (
                get_option('rental_dates_on_checkout', 0) == 1 
                && get_option('rental_allow_overbook', 1) == 1 
                && get_option('rental_checkout_layout_mode', 'classic') === 'modern'
            ) {
                return;
            }

            ?>
            <div id="rental_address_container"
                <?php if (isset($_COOKIE['rental_pick_up']) && $_COOKIE['rental_pick_up']): ?> style="display: none" <?php endif; ?>>
                <label class="woocommerce-form__label woocommerce-form__label-for-checkbox checkbox">
                    <input id="rental_different_pick_up_address"
                           class="woocommerce-form__input woocommerce-form__input-checkbox input-checkbox"
                           type="checkbox" name="rental_different_pick_up_address" value="1">
                    <span><?php _e('Different delivery and pick up locations', 'rentopian-sync'); ?></span>
                </label>
                <div id="rental_pick_up_address_fields" class="woocommerce-address-fields" style="display: none">
                    <?php
                    $address_fields = WC()->countries->get_address_fields('', 'rental_pick_up_');
                    unset($address_fields['rental_pick_up_first_name']);
                    unset($address_fields['rental_pick_up_last_name']);
                    unset($address_fields['rental_pick_up_company']);
                    // if (isset($address_fields['rental_pick_up_state'])) {
                    //     $address_fields['rental_pick_up_state']['id'] = 'calc_shipping_state';
                    // }
                    foreach ($address_fields as $key => $args) {
                        woocommerce_form_field($key, $args);
                    }
                    ?>
                </div>
            </div>
            <?php
        }
        add_action('woocommerce_before_order_notes', 'rental_checkout_pickup_address_fields');

        // Customize cart button text
        function rental_custom_cart_button_text($text, $product_variable = null) {
            if (($product_variable == null || $product_variable->is_purchasable()) && $custom_text = get_option('rental_cart_button_text')) {
                return 'Add to '.$custom_text;
            }
            return $text;
        }
        add_filter('woocommerce_product_add_to_cart_text', 'rental_custom_cart_button_text', 10, 2);
        add_filter('woocommerce_product_single_add_to_cart_text', 'rental_custom_cart_button_text', 10, 1);

        function rental_customize_different_address_shipping_text( $translated_text, $text, $domain ) {

            if (!empty($ship_to_dif_adrs_text = get_option('rental_ship_to_dif_adrs_text')) && 'woocommerce' === $domain && 'Ship to a different address?' === $text ) {
                $translated_text = $ship_to_dif_adrs_text;
            }
            return $translated_text;
        }
        add_filter('gettext', 'rental_customize_different_address_shipping_text', 20, 3);

        function rental_customize_billing_details_text( $translated_text, $text, $domain ) {
            
            if (!empty($billing_details_text = get_option('rental_billing_details_text')) && 'woocommerce' === $domain && 'Billing details' === $text ) {
                $translated_text = $billing_details_text;
            }
            return $translated_text;
        }
        add_filter( 'gettext', 'rental_customize_billing_details_text', 20, 3 );

        add_filter( 'woocommerce_cart_totals_coupon_label', function( $label, $coupon ) {
            return get_option('rental_coupon_label_text', 'Coupon');
        }, 10, 2 );

        add_filter( 'woocommerce_checkout_coupon_message', function( $msg ) {
            $old   = _x( 'Have a coupon?', 'checkout coupon message', 'woocommerce' );
            $label = get_option('rental_coupon_label_text');
            $new   = sprintf( __( 'Have a %s?', 'dynamic-promo-label' ), $label ? strtolower( $label ) : 'coupon' );
            return str_replace( $old, $new, $msg );
        }, 10 );

        add_filter( 'gettext', function( $translated, $text, $domain ) {
            if ( 'woocommerce' === $domain ) {
                $label   = get_option('rental_coupon_label_text', 'Coupon');
                $lower   = strtolower( $label );
                // map other Woo strings to custom
                $replacements = [
                    'Coupon code' => $label . ' code',
                    'Apply coupon' => sprintf( __( 'Apply %s', 'dynamic-promo-label' ), $lower ),
                ];
                if ( isset( $replacements[ $text ] ) ) {
                    return $replacements[ $text ];
                }
            }
            return $translated;
        }, 20, 3 );


        // Update cart and totals text
        function change_update_cart_text( $translated_text, $text, $domain ) {
            $custom_text = get_option('rental_cart_button_text');
            if($custom_text && $domain == 'woocommerce'){
                switch ( $translated_text ) {
                    case 'Update cart' :
                        $translated_text = __('Update '.$custom_text, 'woocommerce' );
                        break;
                    case 'Cart totals' :
                        $translated_text = __( $custom_text. ' totals', 'woocommerce' );
                        break;
                }
            }
            return $translated_text;
        }
        add_filter( 'gettext', 'change_update_cart_text', 20, 3 );


        // Change the word "Shipping"
        add_filter( 'woocommerce_shipping_package_name', 'rental_customize_shipping_package_name' );
        function rental_customize_shipping_package_name( $name ) {
            $custom_text = get_option('rental_shipping_text');

            return !empty($custom_text) ? $custom_text : 'Shipping';
        }

        function rental_customize_shipping_label( $translated_text, $text, $domain) {
            $custom_text = get_option('rental_shipping_text');
            if($custom_text && $domain == 'woocommerce'){
                $translated_text = str_ireplace( 'Shipping', $custom_text, $translated_text );
            }

            return $translated_text;
        }
        add_filter( 'gettext', 'rental_customize_shipping_label', 20, 3 );


        // Change the word "Order"
        function change_woocommerce_order_label( $translated_text, $text, $domain ) {
            $rntp_order_label = get_option('rental_order_text');

            if($rntp_order_label && $domain == 'woocommerce'){
                $translated_text = str_ireplace( 'order', $rntp_order_label, $translated_text );
            }

            return $translated_text;
        }

        add_filter( 'gettext', 'change_woocommerce_order_label', 20, 3 );

        // Change Read more text on cart to "Select Options"
        add_filter( 'woocommerce_product_add_to_cart_text', function( $text ) {
            if ( 'Read more' == $text ) {
                $text = get_option('rental_read_more_text');
                if(!$text){
                    $text = __( 'Select Options', 'woocommerce' );
                }
            }

            return $text;
        } );

        function ace_add_to_cart_message_html( $message, $products ) {
            $cart_custom_label = get_option('rental_cart_button_text');
            if($cart_custom_label) {
                $count = 0;
                $titles = array();
                foreach ($products as $product_id => $qty) {
                    $titles[] = ($qty > 1 ? absint($qty) . ' &times; ' : '') . sprintf(_x('&ldquo;%s&rdquo;', 'Item name in '.$cart_custom_label, 'woocommerce'), strip_tags(get_the_title($product_id)));
                    $count += $qty;
                }

                $titles = array_filter($titles);
                $added_text = sprintf(_n(
                    '%s is added to your '.$cart_custom_label, // Singular
                    '%s are added to your wishlist'.$cart_custom_label, // Plural
                    $count, // Number of products added
                    'woocommerce' // Textdomain
                ), wc_format_list_of_items($titles));
                $message = sprintf('<a href="%s" class="button wc-forward">%s</a> %s', esc_url(wc_get_page_permalink('cart')), esc_html__('View '.$cart_custom_label, 'woocommerce'), esc_html($added_text));
            }
            return $message;
        }
        add_filter( 'wc_add_to_cart_message_html', 'ace_add_to_cart_message_html', 10, 2 );


        // Customize checkout button text
        if ( !function_exists('woocommerce_button_proceed_to_checkout')) {
            function woocommerce_button_proceed_to_checkout() {
                ?>
                <a href="<?php echo wc_get_checkout_url(); ?>" class="checkout-button  button rntp-button-wrap button-primary alt wc-forward">
                    <?php echo get_option('rental_checkout_button_text') ?: __('Proceed to checkout', 'woocommerce'); ?>
                </a>
                <?php
            }
        }


        // Register product type variable
        function rntp_add_product_type_query_var( $vars ){
            $vars[] = "product_type";
            return $vars;
        }
        add_filter( 'query_vars', 'rntp_add_product_type_query_var' );

        // Check for product type filter (query string)
        if ( !function_exists('rntp_product_type_query')) {
            function rntp_product_type_query($q)
            {
                $product_type_query = get_query_var('product_type');


                if (isset($product_type_query) && !empty($product_type_query) && $product_type_query != 'all') {

                    $productType = ($product_type_query == 'sale') ? '1' : '0';
                    $q->set('meta_query', array(
                        array(
                            'key' => '_rental_is_sale',
                            'value' => $productType,
                            'compare' => '=',
                        ),
                    ));
                }
            }

            add_action('woocommerce_product_query', 'rntp_product_type_query');
        }

        // Customize order button text
        function rental_custom_order_button_text($text) {
            return get_option('rental_order_button_text') ?: $text;
        }
        add_filter('woocommerce_order_button_text', 'rental_custom_order_button_text', 10, 1);

        // DEPRECATED: kept for callers outside the plugin.
        function return_false() {
            return false;
        }

        // Add variation threshold to fix variations filter
        function custom_wc_ajax_variation_threshold( $qty, $product ) {
            return 300;
        }
        add_filter( 'woocommerce_ajax_variation_threshold', 'custom_wc_ajax_variation_threshold', 10, 2 );
        /*
                    function bbloomer_unset_gateway_by_category( $available_gateways ) {
                        if ( !rental_get_shipping() || 1) {
                            foreach ($available_gateways as $key => $value) {
                                if ($key != 'cheque')
                                    unset($available_gateways[$key]);
                            }
                        }
                        return $available_gateways;
                    }
                    add_filter( 'woocommerce_available_payment_gateways', 'bbloomer_unset_gateway_by_category' );
        */

        // Action to add product add ons to cart.
        function rental_add_add_on_to_cart($parent_product_id, $product_id, $quantity, $variation_id, $variation, $cart_item_data, $variants_to_delete = []) {

            // Generate a ID based on product ID, variation ID, variation data, and other cart item data.
            $cart_id = WC()->cart->generate_cart_id($product_id, $variation_id, $variation, $cart_item_data);

            // See if this product and its options are already in the cart.
            $cart_item_key = WC()->cart->find_product_in_cart($cart_id);
            $qty_to_replace = 0;
            if ($cart_item_key) {
               $qty_to_replace = WC()->cart->cart_contents[$cart_item_key]['quantity'];
            }


            $quantity_modified = false;

            // Check if this is a set item - set items should allow multiple variants of the same product
            $is_set_item = isset($cart_item_data['rental_set_id']) && $cart_item_data['rental_set_id'] > 0;

            // Get the WooCommerce cart
            $cart = WC()->cart->get_cart();

            // Find and remove any existing add-on with the same parent product
            // BUT only for non-set items (regular add-ons where user changes variant selection)
            // Set items can legitimately have multiple different variants of the same product
            if (!$is_set_item) {

                // Find and remove any existing add-on with the same parent product
                foreach ($cart as $cart_key => $cart_item) {

                    $cart_item_parent_id = 0;
                    if (isset($cart_item['variation_id']) && $cart_item['variation_id']) {
                        $cart_item_parent_id = wc_get_product($cart_item['variation_id'])->get_parent_id() ?: $cart_item['product_id'];
                    }
                    

                    if (
                        isset($cart_item['rental_add_on_of']) 
                        && $cart_item_data['rental_add_on_of'] == $cart_item['rental_add_on_of'] 
                        && isset($cart_item['variation_id'])
                        && $cart_item['variation_id']
                        && (
                            $cart_item_parent_id == $product_id || 
                            $cart_item_parent_id == $variation_id 
                        ) 
                        && $cart_item['variation_id'] !== $variation_id
                    ) {

                        $quantity = $cart_item['quantity'] + $qty_to_replace;
                        $quantity_modified = true;
                        WC()->cart->remove_cart_item($cart_key);
                    }
                }
            }
          

            

            if ($variants_to_delete && !$quantity_modified) {
                foreach($variants_to_delete as $var_to_del) {

                    $cart_id_to_delete = WC()->cart->generate_cart_id($product_id, $var_to_del['variation_id'], $var_to_del['variation_data'], $cart_item_data);
                    $cart_item_key_to_delete = WC()->cart->find_product_in_cart($cart_id_to_delete);

                    if ($cart_item_key_to_delete) {
                        $item = WC()->cart->cart_contents[$cart_item_key_to_delete];
                        $result = WC()->cart->remove_cart_item($cart_item_key_to_delete);
                        if ($result) {

                            $quantity = $item['quantity'] + $qty_to_replace;
                        }
                    }
                }
            }


            // Get the product.
            $product_data = wc_get_product($variation_id ?: $product_id);

            // This writes the line straight into cart_contents, bypassing the
            // checks WC()->cart->add_to_cart() performs. A line whose product
            // cannot be loaded carries `data => false` and fatals the first
            // time the cart is priced, so refuse it here and record which
            // member of which set is broken.
            if ( !$product_data instanceof WC_Product ) {
                if (class_exists('Rentopian_Set_Cart_Tracer', false)) {
                    Rentopian_Set_Cart_Tracer::log('SET_CHILD_ADD_REFUSED', [
                        'parent_product' => (int) $parent_product_id,
                        'product'        => (int) $product_id,
                        'variation'      => (int) $variation_id,
                        'qty'            => (int) $quantity,
                        'parent_key'     => isset($cart_item_data['rental_add_on_of'])
                            ? substr((string) $cart_item_data['rental_add_on_of'], 0, 8)
                            : '-',
                        'post_status'    => get_post_status($variation_id ?: $product_id) ?: 'MISSING',
                        'reason'         => 'product_not_found',
                        'note'           => 'Set child not added: WooCommerce cannot load its product/variation. The set will be short this item.',
                    ], 'critical');
                }
                return '';
            }

            // A zero quantity produces a line WooCommerce discards on the next
            // session read, with no notice to the customer. Keep the existing
            // behaviour but make the loss visible.
            if ( $quantity < 1 && class_exists('Rentopian_Set_Cart_Tracer', false) ) {
                Rentopian_Set_Cart_Tracer::log('SET_CHILD_ADDED_QTY0', [
                    'parent_product' => (int) $parent_product_id,
                    'product'        => (int) $product_id,
                    'variation'      => (int) $variation_id,
                    'note'           => 'Set child written at quantity 0. WooCommerce drops a zero-quantity line when the cart is rebuilt from the session.',
                ], 'critical');
            }

            // If cart_item_key is set, the item is already in the cart and its quantity will be handled by update_quantity_in_cart().
            if ( !$cart_item_key) {
                $cart_item_key = $cart_id;
                // Add item after merging with $cart_item_data - allow plugins and wc_cp_add_cart_item_filter to modify cart item.
                WC()->cart->cart_contents[$cart_item_key] = apply_filters(
                        'woocommerce_add_cart_item', array_merge($cart_item_data, [
                        'key' => $cart_item_key,
                        'product_id' => $product_id,
                        'variation_id' => $variation_id,
                        'variation' => $variation,
                        'quantity' => $quantity,
                        'data' => $product_data,
                    ]), $cart_item_key
                );
            }

            return $cart_item_key;
        }

        /**
         * Robust price resolver for a variant/product post id.
         *
         * Why `_regular_price` is checked FIRST. Classic mode calls
         * `rental_calculate_rental_item_price($variant_id, null, true, true)`
         * (functions.php → get_variant_data) with `$get_regular_price = true`,
         * which reads `_regular_price` postmeta — and that returns the
         * correct $99 for every Baby bike sibling variant.
         *
         * Meanwhile `_price` postmeta is unreliable per-variant. The
         * sync writes both keys to `$variant->rental_price` at
         * functions.php:7464 (_regular_price) and :7493 (_price), but
         * WooCommerce's variation data store
         * (`WC_Product_Variable_Data_Store_CPT::sync_price()`) runs
         * its own price-sync after our sync ends and is observed to
         * zero out `_price` on sibling variants of the default-flagged
         * one. `_regular_price` survives untouched. Empirical proof:
         * classic shows $99 for every Baby bike variant while modern
         * (which used to read `_price` first) showed $0 for siblings.
         *
         * To stay correctness-equivalent with classic, this resolver
         * walks the four post-meta candidates in classic's preferred
         * order:
         *
         *   1. variant's own `_regular_price`        (classic's source,
         *                                             survives WC sync)
         *   2. parent variable product's `_regular_price`
         *                                            (sibling-of-default
         *                                             fallback)
         *   3. variant's `_price`                    (newer sync builds
         *                                             that happen to
         *                                             have it intact)
         *   4. parent product's `_price`
         *
         * Returns 0.0 only when none of the four sources is non-zero.
         *
         * @param int $wp_id     The lookup target (variant_id when set, else product_id).
         * @param int $product_id The parent product id, used for the
         *                        sibling fallback when $wp_id is a variation.
         * @return float
         */
        function rental_resolve_variant_price($wp_id, $product_id = 0) {
            $wp_id      = (int) $wp_id;
            $product_id = (int) $product_id;
            if ( $wp_id <= 0 ) {
                return 0.0;
            }

            // Step 1 — variant's own _regular_price. This is the source
            // classic uses and it's reliable across the WC sync cycle.
            $regular = get_post_meta( $wp_id, '_regular_price', true );
            if ( '' !== $regular && (float) $regular > 0 ) {
                return (float) $regular;
            }

            // Step 2 — parent product's _regular_price. Sibling
            // variants share the same rental rate in our data model;
            // when a variation's own _regular_price is also empty,
            // the parent's is a safe stand-in.
            if ( $product_id > 0 && $product_id !== $wp_id ) {
                $parent_regular = get_post_meta( $product_id, '_regular_price', true );
                if ( '' !== $parent_regular && (float) $parent_regular > 0 ) {
                    return (float) $parent_regular;
                }
            }

            // Step 3 — variant's _price. Modern syncs write this too,
            // but WC's variation data store sometimes zeroes it for
            // sibling variants. Used here only if _regular_price was
            // empty (shouldn't happen, but defensive).
            $price = get_post_meta( $wp_id, '_price', true );
            if ( '' !== $price && (float) $price > 0 ) {
                return (float) $price;
            }

            // Step 4 — parent product's _price. Last resort.
            if ( $product_id > 0 && $product_id !== $wp_id ) {
                $parent_price = get_post_meta( $product_id, '_price', true );
                if ( '' !== $parent_price && (float) $parent_price > 0 ) {
                    return (float) $parent_price;
                }
            }

            return 0.0;
        }

        /**
         * Single source of truth for a SET CHILD line's effective per-unit
         * price (BEFORE the rental-duration multiplier). 100% Sets logic —
         * the implementation lives in the Sets price engine; this is a thin
         * backward-compatible wrapper. See docs/sets/PRICE-RULES.md.
         *
         * @param array $cart_item
         * @return float|null  Effective per-unit price (0.0 for CASE 3), or
         *                     null when this is not a set child.
         */
        function rental_set_child_effective_unit_price( $cart_item ) {
            if ( ! class_exists( 'Rental_Sets_Price_Engine', false ) ) {
                return null;
            }
            return Rental_Sets_Price_Engine::effective_child_unit_price( $cart_item );
        }

        function rental_add_product_to_cart($cart_item_key, $product_id, $quantity, $variation_id, $variation, $cart_item_data, $parent_cart_key = null) {
            if (isset($_POST['rental_overbook_' . $product_id . '_' . $variation_id])) {
                WC()->cart->cart_contents[$cart_item_key]['rental_overbook'] = true;
                unset($_POST['rental_overbook_' . $product_id . '_' . $variation_id]);
            }

            if (isset($_POST['rental_add_ons']) && $add_ons = $_POST['rental_add_ons']) {
                
                // add_ons can be set items and can also be product add_ons
                foreach ($add_ons as $add_on) {

                    // Reset for each add_on iteration to prevent duplicate additions
                    $rental_add_set_item_add_on_to_cart_data = [];

                    // set items' addons
                    if (isset($add_on['addons']) && $add_on['addons']) {

                        foreach ($add_on['addons'] as $set_item_add_on) {

                            // Resolve the addon's per-unit price via the
                            // robust chain (see rental_resolve_variant_price).
                            // The chain protects against the empty-_price
                            // bug where the sync only wrote rental_price
                            // to the default variant.
                            $sia_variant_id = isset($set_item_add_on['variant_id']) ? (int) $set_item_add_on['variant_id'] : 0;
                            $sia_product_id = isset($set_item_add_on['product_id']) ? (int) $set_item_add_on['product_id'] : 0;
                            $sia_lookup_id  = $sia_variant_id ?: $sia_product_id;
                            $set_item_add_on_price = rental_resolve_variant_price( $sia_lookup_id, $sia_product_id );

                            // inherit_price=1 → use the resolved variant
                            // price (now non-zero thanks to the chain).
                            // inherit_price=0/missing → use the addon's
                            // own `price` field. Modern JS ships `price`
                            // explicitly so the row never lands at 0.
                            if ( isset( $set_item_add_on['inherit_price'] ) && $set_item_add_on['inherit_price'] == 1 ) {
                                $rental_set_item_add_on_price = $set_item_add_on_price;
                            } else {
                                $rental_set_item_add_on_price = isset( $set_item_add_on['price'] ) ? $set_item_add_on['price'] : 0;
                            }
                            // Last-resort: if both above paths returned
                            // 0, fall back to whichever side has a
                            // non-zero number. This catches a malformed
                            // row that has neither inherit_price nor a
                            // non-empty `price`.
                            if ( (float) $rental_set_item_add_on_price <= 0 ) {
                                if ( (float) $set_item_add_on_price > 0 ) {
                                    $rental_set_item_add_on_price = $set_item_add_on_price;
                                } elseif ( isset( $set_item_add_on['price'] ) && (float) $set_item_add_on['price'] > 0 ) {
                                    $rental_set_item_add_on_price = $set_item_add_on['price'];
                                }
                            }

                            // $rental_set_item_add_on_price = isset($set_item_add_on['inherit_price']) && $set_item_add_on['inherit_price'] == 1 ? $set_item_add_on['product_price'] : $set_item_add_on['price'];

                            $set_item_add_on_cart_item_data = [
                                'rental_add_on_of' => $cart_item_key,
                                'rental_add_on_price' => $rental_set_item_add_on_price,
                                'rental_add_on_required' => $set_item_add_on['required'],
                                'rental_set_id' => isset($add_on['set_id']) ? $add_on['set_id'] : 0,
                                'set_id' => isset($add_on['parent_set_id']) ? $add_on['parent_set_id'] : 0,
                                'parent_set_item_product_id' => isset($set_item_add_on['parent_set_item_product_id']) ? $set_item_add_on['parent_set_item_product_id'] : 0,
                                'parent_set_item_variant_id' => isset($set_item_add_on['parent_set_item_variant_id']) ? $set_item_add_on['parent_set_item_variant_id'] : 0,
                                // Per-set-unit quantity. The child's
                                // initial cart qty is parent_qty *
                                // per_set_qty; storing the per_set part
                                // lets the qty-sync handler scale the
                                // child as parent_qty * per_set_qty
                                // (absolute math) instead of the fragile
                                // ratio math that rounds to 0 on decrease.
                                'rental_per_set_quantity' => isset($set_item_add_on['quantity']) ? (int) $set_item_add_on['quantity'] : 1,
                                // Static-quantity flag (Laravel `static_quantity`):
                                //   1 → "Singular regardless of parent quantity":
                                //       final qty = configured qty (does NOT
                                //       scale with parent_qty).
                                //   0/empty → "Per 1 parent product": final qty
                                //       = parent_qty * configured qty.
                                // The normalizer reads this to decide whether
                                // to multiply by parent_qty.
                                'rental_static_quantity' => ( isset($set_item_add_on['static_quantity']) && (int) $set_item_add_on['static_quantity'] === 1 ) ? 1 : 0,
                            ];

                            $set_item_add_on_wc_product = $set_item_add_on['variant_id'] ? wc_get_product($set_item_add_on['variant_id']) : null;
                            $set_item_add_on_variation_data = ($set_item_add_on_wc_product && $set_item_add_on_wc_product->is_type( 'variation' ))
                                ? $set_item_add_on_wc_product->get_variation_attributes()
                                : [];

                            $rental_add_set_item_add_on_to_cart_data[] = [
                                'product_id' => $product_id,
                                'add_on_product_id' => $set_item_add_on['product_id'],
                                'quantity' => $quantity * $set_item_add_on['quantity'],
                                'variant_id' => $set_item_add_on['variant_id'],
                                'variation_data' => $set_item_add_on_variation_data,
                                'cart_item_data' => $set_item_add_on_cart_item_data
                            ];
                        }
                    }


                    // Resolve the row's per-unit price via the robust
                    // chain in rental_resolve_variant_price (handles the
                    // sync's empty-_price gap on sibling variants).
                    $row_variant_id = isset($add_on['variant_id']) ? (int) $add_on['variant_id'] : 0;
                    $row_product_id = isset($add_on['product_id']) ? (int) $add_on['product_id'] : 0;
                    $row_lookup_id  = $row_variant_id ?: $row_product_id;
                    $resolved_variant_price = rental_resolve_variant_price( $row_lookup_id, $row_product_id );

                    if (isset($add_on['set_id']) && $add_on['set_id']) {
                        // set item — Rentopian set children.
                        //
                        // Pricing model selection (see PRICE-RULES.md):
                        //   CASE 1  item_based_total = true → child contributes
                        //           its real price.
                        //   CASE 2  item_based_total = false BUT the child is
                        //           variant-based (selectable / variable) →
                        //           the variant choice affects pricing, so the
                        //           child STILL contributes its real price.
                        //   CASE 3  item_based_total = false AND non-variable →
                        //           informational bundle component, price 0.
                        if (isset($add_on['parent_set_id']) && $add_on['parent_set_id']) {
                            $item_based_total = get_post_meta($add_on['parent_set_id'], '_rental_item_based_total', true);
                            $row_is_variable  = !empty($add_on['variant_id']);
                            // A simple set item (entity-1) is not a customer
                            // variant CHOICE even when it points to a
                            // variation, so it must not bill in a fixed
                            // bundle. Only a genuine selectable (entity-2)
                            // counts as variant-based here. Mirrors
                            // Rental_Sets_Price_Engine::child_unit_price.
                            // A composite-group option is excluded up front:
                            // is_simple_set_item() matches on product id
                            // alone and would claim a group option whose
                            // product also appears as a simple item.
                            $row_is_group_child = (isset($add_on['item_type']) && 'grouped_child' === $add_on['item_type'])
                                || !empty($add_on['rental_set_group_is_child'])
                                || !empty($add_on['rental_set_group_uid']);
                            $row_is_simple_item = !$row_is_group_child
                                && class_exists('Rental_Sets_Price_Engine', false)
                                && Rental_Sets_Price_Engine::is_simple_set_item($add_on['parent_set_id'], $add_on['product_id']);
                            $row_is_variant_based = $row_is_variable && !$row_is_simple_item;
                            // separate_price → the item is billed on top of
                            // a fixed bundle even when it's a non-variant
                            // included component (overrides the CASE-3 gate).
                            $row_separate_price = !empty($add_on['separate_price']);

                            if ( $item_based_total || $row_is_variant_based || $row_separate_price ) {
                                // CASE 1 / CASE 2 / separate_price → real
                                // price. Explicit `price` first (admin-set
                                // in-set rate), then the resolved variant
                                // price (_regular_price chain) as a robust
                                // fallback.
                                $row_price = isset($add_on['price']) ? (float) $add_on['price'] : 0;
                                $rental_add_on_price = $row_price > 0 ? $row_price : $resolved_variant_price;
                            } else {
                                // CASE 3 → informational only.
                                $rental_add_on_price = 0;
                            }

                            // Trace separate_price children so the "billed
                            // on top of a fixed bundle" behavior can be
                            // verified from the unified Sets log.
                            if ( $row_separate_price && class_exists('Rental_Sets_Logger', false) ) {
                                Rental_Sets_Logger::price( array(
                                    'reason'     => 'separate_price',
                                    'set'        => (int) $add_on['parent_set_id'],
                                    'product'    => isset($add_on['product_id']) ? (int) $add_on['product_id'] : 0,
                                    'variant'    => isset($add_on['variant_id']) ? (int) $add_on['variant_id'] : 0,
                                    'item_based' => $item_based_total ? 1 : 0,
                                    'variable'   => $row_is_variable ? 1 : 0,
                                    'price'      => $rental_add_on_price,
                                ) );
                            }
                        }
                    } else {
                        // product addon
                        // inherit_price=1 → use the resolved variant price
                        // (which now falls back to parent when the
                        // variant's _price is empty).
                        // inherit_price=0/missing → use $add_on['price'],
                        // falling back to the resolved variant price if
                        // that's empty too.
                        if ( isset( $add_on['inherit_price'] ) && $add_on['inherit_price'] == 1 ) {
                            $rental_add_on_price = $resolved_variant_price;
                        } else {
                            $rental_add_on_price = isset($add_on['price']) ? (float) $add_on['price'] : 0;
                        }
                        if ( (float) $rental_add_on_price <= 0 ) {
                            if ( $resolved_variant_price > 0 ) {
                                $rental_add_on_price = $resolved_variant_price;
                            } elseif ( isset( $add_on['price'] ) && (float) $add_on['price'] > 0 ) {
                                $rental_add_on_price = (float) $add_on['price'];
                            }
                        }
                    }
                    
                    
                    $add_on_cart_item_data = [
                        'rental_add_on_of' => $cart_item_key,
                        'rental_add_on_price' => $rental_add_on_price,
                        'rental_add_on_required' => $add_on['required'],
                        'rental_set_id' => isset($add_on['set_id']) ? $add_on['set_id'] : 0,
                        'set_id' => isset($add_on['parent_set_id']) ? $add_on['parent_set_id'] : 0,
                        // Separately-priced flag — read back by the price
                        // engine so the child bills on top of a fixed bundle.
                        'separate_price' => !empty($add_on['separate_price']) ? 1 : 0,
                        'rental_variant_id' => isset($add_on['rental_variant_id']) ? $add_on['rental_variant_id'] : 0,
                        'rental_product_id' => isset($add_on['rental_product_id']) ? $add_on['rental_product_id'] : 0,
                        // Per-set-unit quantity — see note in the nested
                        // set_item_add_on branch above. Enables absolute
                        // qty scaling (parent_qty * per_set_qty) in
                        // rental_after_cart_item_quantity_update.
                        'rental_per_set_quantity' => isset($add_on['quantity']) ? (int) $add_on['quantity'] : 1,
                        // Static-quantity flag — see note in the nested
                        // set_item_add_on branch. 1 = fixed qty (no parent
                        // scaling); 0/empty = scales as parent_qty * qty.
                        'rental_static_quantity' => ( isset($add_on['static_quantity']) && (int) $add_on['static_quantity'] === 1 ) ? 1 : 0,
                    ];

                    // Composite-GROUP provenance. Children added via
                    // rental_add_add_on_to_cart() are written straight into
                    // cart_contents and only fire `woocommerce_add_cart_item`
                    // (NOT `woocommerce_add_cart_item_data`), so the
                    // Cart_Handler's group-tagging filter never sees them.
                    // We therefore normalise the group fields onto the child
                    // line here, from the same `rental_add_ons` entry the
                    // modern configurator emitted. Non-group children get an
                    // empty map and are unchanged (classic-safe).
                    if ( class_exists( 'Rental_Sets_Cart_Meta', false ) ) {
                        $rental_group_meta = Rental_Sets_Cart_Meta::build_from_input( $add_on );
                        foreach ( $rental_group_meta as $rental_gmk => $rental_gmv ) {
                            $add_on_cart_item_data[ $rental_gmk ] = $rental_gmv;
                        }
                    }

                    $wc_product = wc_get_product($add_on['variant_id']);
                    $variation_data = ($add_on['variant_id'] && $wc_product && $wc_product->is_type( 'variation' )) ? $wc_product->get_variation_attributes() : [];
                    $variants_to_delete = [];
                    if ($add_on['variant_id']) {

                        $rental_uncertain_add_ons = get_rental_session_data('rental_uncertain_add_ons', []);
                        $variants = isset($rental_uncertain_add_ons[$product_id]) ? $rental_uncertain_add_ons[$product_id] : [];

                        if ($variants) {
                            foreach($variants as $variant) {
                                if ($variant != $add_on['variant_id']) {
                                    $variation_data_to_delete = $variant? wc_get_product($variant)->get_variation_attributes(): [];
                                    $variants_to_delete[] = [
                                        "variation_id" => $variant,
                                        "variation_data" => $variation_data_to_delete
                                    ];
                                }
                            }
                        }
                    }
                    rental_add_add_on_to_cart($product_id, $add_on['product_id'], $quantity * $add_on['quantity'], $add_on['variant_id'], $variation_data, $add_on_cart_item_data, $variants_to_delete);


                    if ($rental_add_set_item_add_on_to_cart_data) {
                        foreach ($rental_add_set_item_add_on_to_cart_data as $data) {

                            rental_add_add_on_to_cart($data['product_id'], $data['add_on_product_id'], $data['quantity'], $data['variant_id'], $data['variation_data'], $data['cart_item_data']);
                        }
                    }

                }
                unset($_POST['rental_add_ons']);
            }
        }
        add_action('woocommerce_add_to_cart', 'rental_add_product_to_cart', 10, 6);
        add_action('woocommerce_mnm_add_to_cart', 'rental_add_product_to_cart', 10, 7);
        add_action('woocommerce_bundled_add_to_cart', 'rental_add_product_to_cart', 99, 7);
        add_action('woocommerce_composited_add_to_cart', 'rental_add_product_to_cart', 10, 7);

        /**
         * Add rental selected options to cart item data BEFORE the item is added.
         * This is the most reliable method as the options are stored in WooCommerce's
         * cart session from the very beginning, not added after.
         * 
         * Runs on woocommerce_add_cart_item_data filter with priority 10.
         */
        function rental_add_options_to_cart_item_data($cart_item_data, $product_id, $variation_id) {
            // Determine the actual product ID (variation or parent)
            $actual_product_id = $variation_id ? $variation_id : $product_id;

            // Check if this is a set
            $is_set = get_post_meta($actual_product_id, '_rental_is_set', true);

            // Get the session key based on product type
            $session_key = $is_set
                ? $actual_product_id . '_selected_options_of_set'
                : $actual_product_id . '_selected_options';

            // Get selected options from session
            $selected_options = [];

            if (function_exists('get_rental_session_data')) {
                $selected_options = get_rental_session_data($session_key, []);
            }

            // If no product-specific session data, try global valuables
            if (empty($selected_options) && function_exists('get_rental_session_data')) {
                $rental_product_options_valuables = get_rental_session_data('rental_product_options_valuables', []);
                if (isset($rental_product_options_valuables[$actual_product_id])) {
                    $selected_options = $rental_product_options_valuables[$actual_product_id];
                }
            }

            // Normalize and add to cart item data
            if (!empty($selected_options) && is_array($selected_options)) {
                $normalized = [];
                foreach ($selected_options as $option_id => $selection) {
                    if (!is_array($selection)) {
                        continue;
                    }
                    
                    $value_id = isset($selection['selected_value_id']) 
                        ? $selection['selected_value_id'] 
                        : (isset($selection['value_id']) ? $selection['value_id'] : null);
                    
                    if ($value_id !== null && intval($value_id) !== -1) {
                        $normalized[intval($option_id)] = [
                            'selected_value_id' => intval($value_id),
                            'value_id' => intval($value_id),
                            'price' => isset($selection['price']) ? $selection['price'] : 0
                        ];
                    }
                }
                
                if (!empty($normalized)) {
                    $cart_item_data['rental_selected_options'] = $normalized;
                }
            }

            return $cart_item_data;
        }
        add_filter('woocommerce_add_cart_item_data', 'rental_add_options_to_cart_item_data', 10, 3);

        /**
         * Transfer pre-selected options from session to cart item data.
         * Runs after the main add_to_cart handler with priority 20.
         * NOTE: This is now a backup/fallback since rental_add_options_to_cart_item_data
         * handles the main transfer via the woocommerce_add_cart_item_data filter.
         */
        function rental_transfer_options_on_add_to_cart($cart_item_key, $product_id, $quantity, $variation_id, $variation, $cart_item_data) {
            // Determine the actual product ID (variation or parent)
            $actual_product_id = $variation_id ? $variation_id : $product_id;
            
            // Check if options already exist in cart item (added by rental_add_options_to_cart_item_data)
            if (function_exists('WC') && WC()->cart) {
                $cart_contents = WC()->cart->get_cart();
                if (isset($cart_contents[$cart_item_key]['rental_selected_options']) 
                    && !empty($cart_contents[$cart_item_key]['rental_selected_options'])) {
                    // Options already in cart item data - DO NOT clear session data.
                    // The session data is needed by the product page to restore the selected
                    // options in the dropdowns after a full page reload (add-to-cart POST).
                    // Clearing it here causes the product page selects to reset to defaults.
                    return;
                }
            }
            
            // Check if this is a set
            $is_set = get_post_meta($actual_product_id, '_rental_is_set', true);
            
            // Transfer session options to cart item data (fallback)
            if (function_exists('rental_transfer_session_options_to_cart_item')) {
                rental_transfer_session_options_to_cart_item($actual_product_id, $cart_item_key, $is_set);
            }
        }
        add_action('woocommerce_add_to_cart', 'rental_transfer_options_on_add_to_cart', 20, 6);

        function rntp_add_to_cart_redirect( $url ) {
            if (
                (isset($_COOKIE['rental_hourly_mode']) && $_COOKIE['rental_hourly_mode'] === true)
                || isset($_COOKIE['rental_addon_product'])
            ) {
                // if not in eventorian theme, delete the rental_addon_product cookie to make sure other products' page
                // will not be reloaded after add to cart
                if (!defined('EVENTORIAN_THEME_DIR')) {
                    if (isset($_COOKIE['rental_addon_product'])) {
                        unset($_COOKIE['rental_addon_product']);
                        setcookie('rental_addon_product', '', time() - (31556952), "/", "", false, false);
                    }
                }

                $url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://".$_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
                if (get_option('woocommerce_cart_redirect_after_add') == 'yes') {
                    $url = wc_get_cart_url();
                }
            }
            return $url;
        }
        add_filter( 'woocommerce_add_to_cart_redirect', 'rntp_add_to_cart_redirect' );


        // Action for updating product add ons quantity in cart.
        function rental_after_cart_item_quantity_update($cart_item_key, $quantity = 0, $old_quantity = 1) {
            $cart_contents = WC()->cart->cart_contents;
            if (isset($cart_contents[$cart_item_key]) && !empty($cart_contents[$cart_item_key])) {
                $quantity = ($quantity > 0) ? $quantity : 0;

                $log_children = [];

                foreach ($cart_contents as $key => $cart_item) {
                    if (isset($cart_item['rental_add_on_of']) && $cart_item['rental_add_on_of'] === $cart_item_key) {

                        // Per-set-unit quantity, stamped onto the child
                        // cart line at add-to-cart time. When present we
                        // use ABSOLUTE math: child_qty = parent_qty x
                        // per_set_qty. This is the Laravel-side rule —
                        // every child inherits the set's quantity — and
                        // it's robust against prior desyncs.
                        //
                        // The legacy RATIO math (child / old_parent x
                        // new_parent) is only used as a fallback for old
                        // carts / non-set product addons that never got
                        // a stored per-set quantity. Ratio math is the
                        // source of the "decrease removes children" bug:
                        // when a child was left unsynced at qty 1 and the
                        // parent went 2 -> 1, floor(1/2*1) = 0 silently
                        // zeroed (removed) the child.
                        $per_set_qty = isset($cart_item['rental_per_set_quantity'])
                            ? (int) $cart_item['rental_per_set_quantity']
                            : 0;

                        // Static quantity (Laravel `static_quantity` = 1):
                        // the child is "singular regardless of parent
                        // quantity", so its qty stays at per_set_qty and
                        // does NOT scale when the parent qty changes.
                        $is_static_qty = ! empty($cart_item['rental_static_quantity']);

                        if ($per_set_qty > 0 && $is_static_qty) {
                            $add_on_quantity = $quantity > 0 ? $per_set_qty : 0;
                        } elseif ($per_set_qty > 0) {
                            $add_on_quantity = $quantity > 0 ? ($quantity * $per_set_qty) : 0;
                        } else {
                            // Legacy ratio fallback (guarded against /0).
                            $add_on_quantity = $old_quantity > 0
                                ? floor($cart_item['quantity'] / $old_quantity * $quantity)
                                : 0;
                        }

                        WC()->cart->set_quantity($key, $add_on_quantity);

                        $log_children[] = sprintf(
                            '%s:%s->%d',
                            substr((string) $key, 0, 6),
                            $per_set_qty > 0 ? 'abs' : 'ratio',
                            (int) $add_on_quantity
                        );
                    }
                }

                // Trace the propagation so cart qty issues are debuggable
                // from the dedicated sets log.
                if (!empty($log_children) && class_exists('Rental_Sets_Logger', false)) {
                    $parent_pid = isset($cart_contents[$cart_item_key]['product_id'])
                        ? (int) $cart_contents[$cart_item_key]['product_id'] : 0;
                    if ($parent_pid && get_post_meta($parent_pid, '_rental_is_set', true)) {
                        Rental_Sets_Logger::warn(
                            'cart_qty_sync',
                            sprintf('parent %s qty %d->%d', substr((string) $cart_item_key, 0, 6), (int) $old_quantity, (int) $quantity),
                            ['children' => implode(',', $log_children)]
                        );
                    }
                }
            }
        }
        add_action('woocommerce_after_cart_item_quantity_update', 'rental_after_cart_item_quantity_update', 1, 3);
        add_action('woocommerce_before_cart_item_quantity_zero', 'rental_after_cart_item_quantity_update', 1, 1);

        function rental_validate_and_update_add_on_quantity_in_cart() {
            $cart_contents_modified = WC()->cart->cart_contents;
            foreach ($cart_contents_modified as $key => $cart_item) {
                if (isset($cart_item['rental_add_on_of']) && !isset($cart_contents_modified[$cart_item['rental_add_on_of']])) {
                    WC()->cart->set_quantity($key, 0);
                }
            }
        }
        add_action('woocommerce_cart_updated', 'rental_validate_and_update_add_on_quantity_in_cart');

        // Don't allow product add ons to be removed or changed in quantity.
        function rental_cart_item_remove_link($link, $cart_item_key) {
            $cart_item = WC()->cart->cart_contents[$cart_item_key];
            if (isset($cart_item['rental_add_on_of']) && $cart_item['rental_add_on_required']) {
                return '';
            }

            return $link;
        }
        add_filter('woocommerce_cart_item_remove_link', 'rental_cart_item_remove_link', 10, 2);

        function rental_cart_item_quantity($quantity, $cart_item_key) {
            if (isset(WC()->cart->cart_contents[$cart_item_key]['rental_add_on_of'])) {
                return '<div class="quantity buttons_added">' . WC()->cart->cart_contents[$cart_item_key]['quantity'] . '</div>';
            }

            return $quantity;
        }
        add_filter('woocommerce_cart_item_quantity', 'rental_cart_item_quantity', 10, 2);


        function rental_cart_hourly_item_removed($cart_item_key, $cart) {
            if (get_option('rental_synchronized_product_type') == "hourly") {
                $var_id = isset($cart->removed_cart_contents[$cart_item_key]["variation_id"]) && $cart->removed_cart_contents[$cart_item_key]["variation_id"] ? $cart->removed_cart_contents[$cart_item_key]["variation_id"] : 0;
                $prod_id = isset($cart->removed_cart_contents[$cart_item_key]["product_id"]) && $cart->removed_cart_contents[$cart_item_key]["product_id"] ? $cart->removed_cart_contents[$cart_item_key]["product_id"] : 0;
                $product_prefix = !empty($var_id) ? $var_id : $prod_id;

                if ( !empty($product_prefix) ) {
                // if ( isset($cart->removed_cart_contents[$cart_item_key]["product_id"]) && !empty($cart->removed_cart_contents[$cart_item_key]["product_id"]) ) {
                    $session_name_interval = $product_prefix."_rental_by_interval";
                    $session_name_slot = $product_prefix."_rental_by_slot";

                    $rental_by_interval_opt = get_option($session_name_interval);
                    $rental_by_slot_opt = get_option($session_name_slot);

                    if ($rental_by_interval_opt !== false) {

                        // keep removed data to restore hourly product item
                        $session_name_interval_removed = $session_name_interval."_removed";
                        update_option($session_name_interval_removed, $rental_by_interval_opt);

                        delete_option($session_name_interval);

                    } else if ($rental_by_slot_opt !== false) {

                        // keep removed data to restore hourly product item
                        $session_name_slot_removed = $session_name_slot."_removed";
                        update_option($session_name_slot_removed, $rental_by_slot_opt);

                        delete_option($rental_by_slot_opt);
                    }

                }
            }

        }

        // Remove/restore add ons when parent is removed/restored.
        function rental_cart_item_removed($cart_item_key, $cart) {
            if ( !empty($cart->removed_cart_contents[$cart_item_key])) {
                foreach ($cart->cart_contents as $item_key => $item) {
                    if (isset($item['rental_add_on_of']) && $item['rental_add_on_of'] === $cart_item_key) {
                        $cart->removed_cart_contents[$item_key] = $item;
                        unset($cart->cart_contents[$item_key]);
                    }
                }
            }

            // Get removed items map from the cart instance
            $removed = method_exists( $cart, 'get_removed_cart_contents' )
                ? $cart->get_removed_cart_contents()
                : [];

            if ( empty( $removed ) || ! isset( $removed[ $cart_item_key ] ) ) {
                return;
            }

            $item = $removed[ $cart_item_key ];

            // Product and variation ids that were removed
            $product_id   = isset( $item['product_id'] ) ? (int) $item['product_id'] : 0;
            $variation_id = isset( $item['variation_id'] ) ? (int) $item['variation_id'] : 0;

            if ( ! function_exists( 'get_set_ids_with_optional_items' ) ) {
                return;
            }

            $sets_with_optional = get_set_ids_with_optional_items();
            if ( empty( $sets_with_optional ) || ! is_array( $sets_with_optional ) ) {
                return;
            }

            // helper to flatten nested arrays (iterative)
            $flatten = function( $arr ) {
                $out = [];
                $stack = is_array( $arr ) ? $arr : [ $arr ];
                while ( !empty( $stack ) ) {
                    $v = array_shift( $stack );
                    if ( is_array( $v ) ) {
                        foreach ( $v as $vv ) {
                            $stack[] = $vv;
                        }
                    } else {
                        $out[] = $v;
                    }
                }
                return $out;
            };

            foreach ( $sets_with_optional as $set_id => $selected_values ) {
                // normalize/flatten selected values into a simple array
                $flat = $flatten( $selected_values );
                if ( empty( $flat ) ) {
                    continue;
                }

                $matched = false;

                foreach ( $flat as $val ) {
                    if ( $val === null || $val === '' ) {
                        continue;
                    }

                    // normalize both to strings for comparison
                    $val_str = (string) $val;

                    if ( (string) $product_id !== '0' && $val_str === (string) $product_id ) {
                        $matched = true;
                        break;
                    }
                    if ( (string) $variation_id !== '0' && $val_str === (string) $variation_id ) {
                        $matched = true;
                        break;
                    }

                }

                if ( $matched ) {
                    // Replace current rental_set_items with the default one
                    $rental_set_items_default = get_post_meta( $set_id, '_rental_set_items_default', true );
                    update_post_meta( $set_id, '_rental_set_items', $rental_set_items_default );
                }
            }

        }
        add_action('woocommerce_cart_item_removed', 'rental_cart_item_removed', 10, 2);
        // add_action('woocommerce_cart_item_removed', 'rental_cart_hourly_item_removed', 10, 2);


        function rental_cart_item_restored($cart_item_key, $cart) {
            if ( !empty($cart->cart_contents[$cart_item_key]) && !empty($cart->removed_cart_contents)) {
                foreach ($cart->removed_cart_contents as $item_key => $item) {
                    if (isset($item['rental_add_on_of']) && $item['rental_add_on_of'] === $cart_item_key) {
                        $cart->cart_contents[$item_key] = $item;
                        unset($cart->removed_cart_contents[$item_key]);
                    }
                }
            }
        }
        add_action('woocommerce_cart_item_restored', 'rental_cart_item_restored', 10, 2);

        // Actions for adding products from order.
        function rental_add_products_order_item_meta($item_id, $item, $order_id) {
            if ( !isset($item->legacy_values['rental_add_on_of']) || empty($cart = WC()->cart) || !$cart instanceof WC_Cart) {
                return;
            }
            $cart_contents = $cart->get_cart();
            if (isset($cart_contents[$item->legacy_values['rental_add_on_of']])) {
                wc_add_order_item_meta($item_id, '_rental_add_on_parent_id', $cart_contents[$item->legacy_values['rental_add_on_of']]['product_id']);
                wc_add_order_item_meta($item_id, '_rental_add_on_parent_variant_id', $cart_contents[$item->legacy_values['rental_add_on_of']]['variation_id']);
                wc_add_order_item_meta($item_id, '_rental_add_on_required', $item->legacy_values['rental_add_on_required']);
                wc_add_order_item_meta($item_id, '_rental_set_id', $item->legacy_values['rental_set_id']);
            }
        }
        add_action('woocommerce_new_order_item', 'rental_add_products_order_item_meta', 10, 3);

        // Add_ons modal
        function rental_render_add_ons_modal() {
            global $product;

            $product_id = $product->get_id();

            $add_ons = get_post_meta($product_id, '_rental_add_ons', true);
            if ( !$add_ons = get_post_meta($product_id, '_rental_add_ons', true)) {
                return;
            }

            ?>

                <div id="product_has_rental_add_ons"></div>

            <?php

            $chosen_hidden_addon_items_per_product = [];
            $not_hidden_addons_count = 0;
            $selected_variants = [];
            $uncertain_add_ons = [];
            foreach ($add_ons as $add_on) {

                if ( !$add_on['variant_id']) {

                    $rental_uncertain_add_ons = get_rental_session_data('rental_uncertain_add_ons', []);
                    $rental_uncertain_add_ons[$product_id] = [];

                    $add_on_product = wc_get_product($add_on['product_id']);
                    if ($add_on_product->is_type('variable')) {
                        $variants = $add_on_product->get_visible_children();

                        $addons_variants_count = count($variants);

                        $available_variants = [];
                        foreach ($variants as $key => $variant_id) {

                            $variant = wc_get_product($variant_id);

                            if ($variant instanceof WC_Product && rental_product_is_in_stock(true, $variant)) {
                                $available_variants[] = $variant;

                                if (
                                    (isset($add_on['hidden']) && $add_on['hidden'] == 1) 
                                    || $addons_variants_count < 2
                                ) {

                                    if ($key == 0) {
                                        $chosen_hidden_addon_items_per_product[$add_on['product_id']] = $variant_id;
                                    }

                                } else {
                                    ++$not_hidden_addons_count;
                                }

                                $rental_uncertain_add_ons[$product_id][] = $variant_id;
                                set_rental_session_data('rental_uncertain_add_ons', $rental_uncertain_add_ons);

                            }
                        }
                        if ( !empty($available_variants)) {
                            $uncertain_add_ons[] = [
                                'product'  => $add_on_product,
                                'variants' => $available_variants,
                                'hidden'   => isset($add_on['hidden']) && $add_on['hidden'] ? 1 : 0,
                                'required' => isset($add_on['required']) ? (int) $add_on['required'] : 1,
                            ];
                        }
                    }
                }
            }
            if (empty($uncertain_add_ons)) {
                return;
            }


            // hidden uncertain addons input generation
            if ($chosen_hidden_addon_items_per_product) {
                foreach($chosen_hidden_addon_items_per_product as $addon_product_id => $chosen_hidden_addon_variant_id) {
                    echo ' <input type="hidden" name="rental_add_on_variants['.$addon_product_id.']" id="variant'.$chosen_hidden_addon_variant_id.'" value="'.$chosen_hidden_addon_variant_id.'" /> ';
                }

                if (empty($not_hidden_addons_count)) {
                    return;
                }
            }

            ?>
            <div id="rental_add_ons" class="rental-modal" <?php echo isset($selected_variants) ? "data-selected_variants='" . json_encode($selected_variants) . "'" : ""; ?>>
                <div class="rental-modal-content">
                    <div class="rental-modal-header">
                        <h3 class="rental-modal-title">
                            <?php _e("Please select an add-on variation", 'rentopian-sync'); ?>
                        </h3>
                    </div>
                    <div class="rental-modal-body">
                        <?php foreach ($uncertain_add_ons as $item): ?>

                            <?php

                                if ($item['hidden']) continue;

                                $add_on_price_type = 'custom_price';
                                // $add_on_product_price = 0;
                                if (
                                    isset($item['product'])
                                ) {

                                    $add_on_item = $add_ons[$item['product']->get_id()];

                                    if (isset($add_on_item) && $add_on_item) {

                                        if ( isset($add_on_item['inherit_price']) && $add_on_item['inherit_price'] == 1 ) {

                                            $add_on_price_type = 'inherit_price_from_product';
                                            // $add_on_product_price = $add_on_item['product_price']; // use this in case if variant has no price 
                                        }
                                    }
                                }
                            ?>

                            <div class="rental-addons-list">
                                <?php if (empty($item['variants'])): ?>
                                    <div class="rental-product-image">
                                        <?php
                                        $thumbnail = wp_get_attachment_image_src($item['product']->get_image_id());
                                        if (isset($thumbnail[0]) && $thumbnail[0]):?>
                                            <img src="<?php echo $thumbnail[0] ?>" width="70" height="70" alt=""/>
                                        <?php endif; ?>
                                    </div>
                                <?php endif ?>
                                <div class="rental-product-title">
                                    <a href="<?php echo $item['product']->get_permalink() ?>" target="_blank">
                                        <?php echo $item['product']->get_title() ?>
                                    </a>
                                    <a href="<?php echo esc_url( $item['product']->get_permalink() ) ?>" target="_blank" class="rental-addon-view-details">
                                        <?php _e('View details', 'rentopian-sync') ?>
                                    </a>
                                </div>

                                <ul class="rental-product-variants" data-required="<?php echo isset($item['required']) ? (int)$item['required'] : 1; ?>">
                                    <?php foreach ($item['variants'] as $variant): $thumbnail = wp_get_attachment_image_src($variant->get_image_id()); ?>
                                        <li>
                                            <div class="rental-variant-image">
                                                <?php if (isset($thumbnail[0]) && $thumbnail[0]): ?>
                                                    <img src="<?php echo $thumbnail[0] ?>" width="50" height="50" alt=""/>
                                                <?php endif; ?>
                                            </div>
                                            <div class="rental-variant-title">
                                                
                                                <label for="variant<?php echo esc_attr( $variant->get_id() ); ?>">
                                                    <?php
                                                    $display_name = rental_get_variant_display_name( $variant );

                                                    if ( rental_prices_are_hidden( 'catalog' ) ) {

                                                        echo esc_html( $display_name );

                                                    } elseif ( $add_on_price_type === 'custom_price' ) {
                                                        $add_on_custom_price = isset( $add_ons[ $item['product']->get_id() ]['price'] )
                                                            && $add_ons[ $item['product']->get_id() ]['price']
                                                                ? $add_ons[ $item['product']->get_id() ]['price']
                                                                : 0;

                                                        echo esc_html( $display_name . ' (' . get_woocommerce_currency_symbol() . $add_on_custom_price . ')' );

                                                    } else {
                                                        // inherit_price_from_product
                                                        $price = $variant->get_price();
                                                        if ( $price ) {
                                                            echo esc_html( $display_name . ' (' . get_woocommerce_currency_symbol() . $price . ')' );
                                                        } else {
                                                            echo esc_html( $display_name );
                                                        }
                                                    }
                                                    ?>
                                                </label>

                                            </div>

                                            <div class="rental-variant-select">
                                                <input type="radio" name="rental_add_on_variants[<?php echo $item['product']->get_id(); ?>]" id="variant<?php echo $variant->get_id(); ?>" value="<?php echo $variant->get_id() ?>"/>
                                                <div class="check"></div>
                                            </div>

                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="rental-modal-footer text-center">
                        <button type="button" class="rental-modal-cancel">
                            <?php _e('Cancel', 'rentopian-sync') ?>
                        </button>
                        <button type="submit" name="add-to-cart" value="<?php echo $product->get_id(); ?>" class="single_add_to_cart_button rental-modal-submit">
                            <?php _e('Confirm', 'rentopian-sync') ?>
                        </button>
                    </div>
                </div>
            </div>
            <?php
        }
        add_action('woocommerce_before_add_to_cart_button', 'rental_render_add_ons_modal', 5);


        function rental_render_hourly_modal() {
            global $product;

            $rental_by_interval_product = (bool) get_post_meta($product->get_id(), '_rental_by_interval', true);
            $rental_by_slot_product = (bool) get_post_meta($product->get_id(), '_rental_by_slot', true);
            // check if product is rental by interval/slot type
            if (
                get_option('rental_synchronized_product_type') == "hourly"
                && ($rental_by_interval_product || $rental_by_slot_product)
            ) {

                // getting main division working hours
                $divisions = get_option("rental_divisions");
                $main_division_working_days = [];
                $week_days = [1,2,3,4,5,6,7];
                // can change it to work with product division work day-hours
                foreach ($divisions as $division) {
                    if (get_option('rental_select_division') && isset($_COOKIE['rental_division_id']) && !empty($_COOKIE['rental_division_id'])) {
                        if ( is_array($divisions) && count($divisions) > 1 && ($_COOKIE['rental_division_id'] == $division->id)) {
                            // getting selected division work hours
                            $main_division_working_days = $division->working_hours;
                        }
                    } else {
                        // default : getting main division work hours
                        if ($division->main_division) {
                            $main_division_working_days = $division->working_hours;
                        }
                    }
                }



                $main_division_working_days_diff=[];
                if (!empty($main_division_working_days)) {
                    foreach($main_division_working_days as $work_day) {
                        if (in_array($work_day->week_day, $week_days)) {
                            $main_division_working_days_diff[] = $work_day->week_day;
                        }
                    }
                }
                $disabled_working_days = !empty($main_division_working_days_diff) ? json_encode(array_values(array_diff($week_days,$main_division_working_days_diff))) : json_encode([]);

                // getting the hourly data
                $hourly_data = [];
                $select_from_title = "";
                if ($rental_by_interval_product) {
                    $hourly_data['rental_interval'] = get_post_meta($product->get_id(), '_rental_interval', true);
                    $hourly_data['rental_interval_steps'] = get_post_meta($product->get_id(), '_rental_interval_steps', true);
                    $hourly_data['rental_interval_price'] = get_post_meta($product->get_id(), '_rental_interval_price', true);
                    $hourly_data['rental_interval_sale_price'] = get_post_meta($product->get_id(), '_rental_interval_sale_price', true);
                    $hourly_data['rental_by_interval'] = true;
                    $hourly_data['rental_by_slot'] = false;

                    $select_from_title = "Select From Intervals";
                } else if ($rental_by_slot_product) {
                    $hourly_data['rental_additional_hourly_price'] = get_post_meta($product->get_id(), '_rental_additional_hourly_price', true);
                    $hourly_data['rental_time_slots'] = json_decode(get_post_meta($product->get_id(), '_rental_time_slots', true));
                    $hourly_data['rental_by_interval'] = false;
                    $hourly_data['rental_by_slot'] = true;

                    $select_from_title = "Select From Time Slots";
                }

            ?>

                <div id="rental_hourly_modal_holder" class="rental-modal">
                    <div class="rental-modal-content rental-w-600">
                        <div class="rental-modal-header">
                            <h3 class="rental-modal-title">
                                <?php _e("Please select one of the following options:", 'rentopian-sync'); ?>
                            </h3>
                        </div>

                        <div class="rental-modal-body">

                            <div class="rental-col-bottom rental-w-100">
                                <!-- <input id="daily-rental" name="rental_type_radio" type="radio" class="input-radio" value="hourly" /> -->
                                <h4> <?php _e('Hourly Rental', 'rentopian-sync'); ?> </h4>

                                <div id="rental-hourly-calendar-wrapper">

                                    <div class="rntp-form-wrapper-hourly">
                                        <input type="text"
                                            class="date-form rntp-date"
                                            name="rental_date_hourly"
                                            id="rental_date_hourly"
                                            data-disabled-week-days="<?php echo esc_attr($disabled_working_days); ?>"
                                            data-work-days="<?php echo esc_attr(json_encode($main_division_working_days)); ?>"
                                            data-hourly="<?php echo esc_attr(json_encode($hourly_data)); ?>"
                                            data-product-id="<?php echo $product->get_id(); ?>"
                                            required="required"
                                            />
                                    </div>

                                </div>

                            </div>

                            <div id="rental_intervals_wrapper">
                                <h4> <?php _e($select_from_title, 'rentopian-sync'); ?> </h4>
                                <div id="rental_intervals"></div>
                            </div>

                        </div>

                        <div class="rental-modal-footer text-center">
                            <button type="button" class="rental-modal-cancel">
                                <?php _e('Cancel', 'rentopian-sync') ?>
                            </button>
                            <button type="submit" name="add-to-cart" value="<?php echo $product->get_id(); ?>" class="single_add_to_cart_button rental-modal-submit rental-hourly-modal-submit">
                                <?php _e('Confirm', 'rentopian-sync') ?>
                            </button>
                        </div>
                    </div>
                </div>

            <?php

            }
        }
        add_action('woocommerce_before_add_to_cart_button', 'rental_render_hourly_modal', 5);


        function rental_min_order_amount_message() {

            // $min_order_amount = get_option('rental_min_order_amount');
            $delivery_settings = rental_get_delivery_settings();
            $min_order_amount = $delivery_settings['restriction_fixed_min_for_website'] ?? 0;
            if (
                $min_order_amount
                && WC()->cart->get_subtotal() < $min_order_amount
            ) {

                $message = 'You must have an order with a minimum of ' . wc_price($min_order_amount) . ' to place your order';
                // $custom_message = get_option('rental_min_order_text');
                $custom_message = $delivery_settings['restriction_fixed_min_message_for_website'];
                if ($custom_message) {
                    $message = $custom_message;
                }

                $additional_msg = '';
                if (get_option('rental_allow_to_pay_security_deposit')) {
                    $required_amount_to_submit_order = $min_order_amount - WC()->cart->get_subtotal();
                    $additional_msg = ' The security deposit is not calculated towards the order total. This means you still need '.wc_price($required_amount_to_submit_order).' added to the order to checkout.';
                }

                $message = $message.$additional_msg;

                // Store the message in the session for AJAX retrieval
                WC()->session->set('rental_min_order_message', $message);

                // wc_print_notice($message, 'error');

            } else {
                WC()->session->set('rental_min_order_message', '');
                WC()->session->__unset('rental_min_order_message');
            }
        }
        add_action('woocommerce_proceed_to_checkout', 'rental_min_order_amount_message', 10);
        add_action('woocommerce_review_order_before_submit', 'rental_min_order_amount_message', 10);

        function rental_custom_order_button_html($button_html) {
            // $min_order_amount = get_option('rental_min_order_amount');
            $delivery_settings = rental_get_delivery_settings();
            $min_order_amount = $delivery_settings['restriction_fixed_min_for_website'] ?? 0;
            if ( !$min_order_amount || WC()->cart->get_subtotal() >= $min_order_amount) {
                return $button_html;
            }

            // company_client_delivery_return = allow both
            // if (!(get_option('rental_min_order_pickup') && get_option('rental_pickup_delivery') === 'company_client_delivery_return')) {
            if (!($delivery_settings['restriction_fixed_min_allow_pickup_for_website'] && get_option('rental_pickup_delivery') === 'company_client_delivery_return')) {
                $button_html = '';
            }

            return '<a class="rntp-button-wrap rntp-button large rntp-button-green button button-2 mr-1 w-100" href="' .
                wc_get_page_permalink( 'shop' ) .'">' . __('Continue Shopping', 'rentopian-sync') . '</a>'.
                $button_html;
        }
        add_filter('woocommerce_order_button_html', 'rental_custom_order_button_html');

        if (get_option('rental_allow_to_pay_deposit')) {

            function rental_update_order_review($data_string) {
                if (empty(rental_get_deposit())) {
                    return;
                }

                parse_str($data_string, $data);
                if ( !isset(WC()->cart->rental_deposit)) {

                    WC()->cart->rental_deposit = ['deposit_enabled' => false];
                }

                // The session still holds the previous rate at this point,
                // so read the rate posted with this refresh.
                $posted_shipping_methods = isset($data['shipping_method'])? $data['shipping_method']: null;
                if (rental_is_full_payment_required_for_delivery($posted_shipping_methods)) {
                    WC()->cart->rental_deposit['deposit_enabled'] = false;

                    return;
                }

                if (isset($data['rental_deposit_radio'])) {

                    if ($data['rental_deposit_radio'] === 'full') {
                        WC()->cart->rental_deposit['deposit_enabled'] = false;
                    } else {
                        WC()->cart->rental_deposit['deposit_enabled'] = true;
                    }
                }
            }
            add_action('woocommerce_checkout_update_order_review', 'rental_update_order_review', 10, 1);

            function rental_calculated_total($cart_total) {
                $deposit = rental_get_deposit();
                if (empty($deposit)) {
                    return $cart_total;
                }

                
                if ($total_excluded_extra_fees = get_rental_session_data('total_excluded_extra_fees', 0)) {
                    $cart_total = $total_excluded_extra_fees;
                }

                $rental_deposit = [
                    'deposit_enabled' => false,
                    'deposit_amount' => 0,
                    'deposit_id' => $deposit['id'],
                ];

                // Delivery orders must be paid in full
                if (rental_is_full_payment_required_for_delivery()) {
                    WC()->cart->rental_deposit = $rental_deposit;

                    return $cart_total;
                }

                if (is_ajax()) {

                    $deposit_radio = '';

                    if (isset($_POST['rental_deposit_radio'])) {

                        $deposit_radio = $_POST['rental_deposit_radio'];
                    } elseif (isset($_POST['post_data'])) {

                        parse_str($_POST['post_data'], $post_data);
                        if (isset($post_data['rental_deposit_radio'])) {
                            $deposit_radio = $post_data['rental_deposit_radio'];
                        }
                    }

                    if ($deposit_radio && $deposit_radio !== 'full') {
                        $deposit_amount = $deposit['is_percent']? ($deposit['amount'] * $cart_total / 100): (float) $deposit['amount'];

                        $rental_deposit['deposit_enabled'] = $cart_total > $deposit_amount;
                        $rental_deposit['deposit_amount'] = round($deposit_amount, wc_get_price_decimals());

                        if ($rental_deposit['deposit_enabled'] && get_option('rental_allow_to_pay_another_amount') && $deposit_radio === 'another') {
                            $amount = isset($_POST['rental_amount_to_pay'])? $_POST['rental_amount_to_pay']:
                                (isset($post_data, $post_data['rental_amount_to_pay'])? $post_data['rental_amount_to_pay']: $cart_total);
                            if ($amount < $deposit_amount || $amount > $cart_total) {
                                wc_add_notice(__('Invalid amount to pay.', 'rentopian-sync'), 'error');
                            }
                            $rental_deposit['another_amount'] = round($amount, wc_get_price_decimals());
                        }
                    }
                }
                WC()->cart->rental_deposit = $rental_deposit;

                return $cart_total;
            }
            add_filter('woocommerce_calculated_total', 'rental_calculated_total', 1010);

            function rental_checkout_deposit_button() {

                if (empty(rental_get_deposit())) {
                    return;
                }

                $cart_total = WC()->cart->get_total('edit');
                $default_checked = 'full';
                if (is_ajax() && isset($_POST['post_data'])) {
                    parse_str($_POST['post_data'], $post_data);
                    if (isset($post_data['rental_deposit_radio'])) {
                        $default_checked = $post_data['rental_deposit_radio'];
                    }
                }
                $allow_another_amount = get_option('rental_allow_to_pay_another_amount');

                // Delivery orders are limited to the full payment option
                $full_payment_only = rental_is_full_payment_required_for_delivery();
                if ($full_payment_only) {
                    $default_checked = 'full';
                    $allow_another_amount = false;
                }
                ?>
                <div>
                    <label for="rental-amount-to-pay"><b><?php _e('To Pay', 'rentopian-sync'); ?></b></label>
                    <span>
                        <b><?php echo get_woocommerce_currency_symbol(); ?></b>
                        <?php
                        $min = isset(WC()->cart->rental_deposit['deposit_amount'])? WC()->cart->rental_deposit['deposit_amount']: 0;
                        $max = $cart_total;
                        if ($allow_another_amount && $default_checked === 'another') {
                            // $val = isset(WC()->cart->rental_deposit['another_amount'])? WC()->cart->rental_deposit['another_amount']: $max;
                            $val = $max;
                            ?>
                            <input id='rental-amount-to-pay' name='rental_amount_to_pay' type='number' step="0.01"
                                   min="<?php echo $min; ?>" max="<?php echo $max; ?>" value="<?php echo $val; ?>">
                            <small><?php _e('Input any sum in the range of $', 'rentopian-sync'); echo $min.' - $'.$max ?> </small>
                        <?php } else { ?>
                            <b><?php echo $default_checked === 'deposit'? $min: $max; ?></b>
                        <?php } ?>
                    </span>
                </div>
                <div id='rental-deposits-options-form' class="radio-toolbar">
                    <input id='pay-full-amount' name='rental_deposit_radio' type='radio' <?php echo checked($default_checked, 'full'); ?> class='input-radio' value='full' />
                    <label id="pay-full-amount-label" for='pay-full-amount' onclick=''>
                        <?php _e('Full Amount', 'rentopian-sync'); ?>
                    </label>

                    <?php if ( !$full_payment_only): ?>
                        <input id='pay-deposit' name='rental_deposit_radio' type='radio' <?php echo checked($default_checked, 'deposit'); ?> class='input-radio' value='deposit' />
                        <label id="pay-deposit-label" for='pay-deposit'>
                            <?php _e('Deposit', 'rentopian-sync'); ?>
                        </label>
                    <?php endif; ?>

                    <?php if ($allow_another_amount): ?>
                        <input id='pay-another-amount' name='rental_deposit_radio' type='radio' <?php echo checked($default_checked, 'another'); ?> class='input-radio' value='another' />
                        <label id="pay-another-amount-label" for='pay-another-amount' onclick=''>
                            <?php _e('Other Amount', 'rentopian-sync'); ?>
                        </label>
                    <?php endif; ?>
                </div>
                <script>
                  jQuery(document).ready(function($) {
                    $('#rental-deposits-options-form').on('change', 'input[name="rental_deposit_radio"]', function() {
                      $(document.body).trigger('update_checkout');
                    });
                    $('#rental-amount-to-pay').on('change', function() {
                      var max = parseFloat(this.max);
                      var min = parseFloat(this.min);
                      var val = parseFloat(this.value);
                      if (!val || val > max) {
                        val = max;
                      } else if (val < min) {
                        val = min;
                      }
                      this.value = val.toFixed(2);
                    });
                  });
                </script>
                <?php
            }
            add_action('woocommerce_review_order_before_submit', 'rental_checkout_deposit_button', 50);

            function rental_change_total_on_checking($order){
                if (isset(WC()->cart->rental_deposit['deposit_enabled']) && WC()->cart->rental_deposit['deposit_enabled']) {
                    $deposit = WC()->cart->rental_deposit['deposit_amount'];
                    $order->add_meta_data('_rental_deposit_amount', $deposit, true);
                    $order->add_meta_data('_rental_deposit_id', WC()->cart->rental_deposit['deposit_id'], true);
                    $amount = isset(WC()->cart->rental_deposit['another_amount']) && get_option('rental_allow_to_pay_another_amount')?
                        WC()->cart->rental_deposit['another_amount']: $deposit;

                    $order_total = $order->get_total();
                    $order->set_total($amount);
                    $order->add_meta_data('_rental_balance_due', $order_total - $amount, true);
                }
            }
            add_action('woocommerce_checkout_create_order', 'rental_change_total_on_checking', 10, 1);

            function rental_get_order_item_totals($total_rows, $order) {
                $balance_due = (float) $order->get_meta('_rental_balance_due', true);
                if ($balance_due && is_checkout() && get_query_var(get_option('woocommerce_checkout_order_received_endpoint', 'order-received'))) {
                    $currency = $order->get_currency();
                    $amount_paid = $order->get_total();
                    $total_rows['amount_paid'] = [
                        'label' => __('Amount Paid:', 'rentopian-sync'),
                        'value' => wc_price($amount_paid, ['currency' => $currency])
                    ];
                    $total_rows['balance_due'] = [
                        'label' => __('Balance Due:', 'rentopian-sync'),
                        'value' => wc_price($balance_due, ['currency' => $currency])
                    ];
                    if (isset($total_rows['order_total'])) {
                        $total_rows['order_total']['value'] = wc_price($amount_paid + $balance_due, ['currency' => $currency]);
                    }
                }

                return $total_rows;
            }
            add_filter('woocommerce_get_order_item_totals', 'rental_get_order_item_totals', 10, 2);
        }

        if ( !get_option('rental_do_not_use_rentopian_shipping')) {

            /** Disable Rentopian shipping if selected in the settings **/
            add_filter('wc_shipping_enabled', 'rental_shipping_enabled', 100);
            function rental_shipping_enabled($enable) {

                return $enable || is_admin();
            }

             /** Rentopian External API Shipping Method **/
            /** Mile/Kilometre Based Shipping Method **/
            require_once RENTOPIAN_SYNC_PATH . '/includes/class-miles-based-shipping.php';

            /** Reduced Shipping Method **/
            // require_once RENTOPIAN_SYNC_PATH . '/includes/class-reduced-rate-shipping.php';

            // Allow Both specific shipping flow 
            if ( (get_option('rental_pickup_delivery') == 'company_client_delivery_return') ) {

                /**
                 * Check whether we have a usable address for shipping calculation.
                 * - Prefer shipping fields if present/complete.
                 * - Fallback to billing fields (important when "Ship to a different address" is unchecked).
                 * - Also considers posted checkout data during AJAX refreshes.
                 */
                function rentopian_has_address_context() {

                    // Try POSTed fields first
                    $post = isset($_POST) ? wp_unslash($_POST) : [];

                    $p_ship_country  = $post['shipping_country']  ?? '';
                    $p_ship_city     = $post['shipping_city']     ?? '';
                    $p_ship_addr1    = $post['shipping_address_1'] ?? '';
                    $p_ship_postcode = $post['shipping_postcode'] ?? '';

                    $p_bill_country  = $post['billing_country']  ?? '';
                    $p_bill_city     = $post['billing_city']     ?? '';
                    $p_bill_addr1    = $post['billing_address_1'] ?? '';
                    $p_bill_postcode = $post['billing_postcode'] ?? '';

                    $posted_has_shipping = $p_ship_country && $p_ship_city && $p_ship_addr1 && $p_ship_postcode;
                    $posted_has_billing  = $p_bill_country && $p_bill_city && $p_bill_addr1 && $p_bill_postcode;

                    // Determine if checkout indicates "ship to different address"
                    $ship_to_diff = null;
                    if ( array_key_exists('ship_to_different_address', $post) ) {
                        
                        $ship_to_diff = ! empty($post['ship_to_different_address']);
                    }

                    if ( $ship_to_diff === false ) {

                        // Billing is the shipping basis
                        if ( $posted_has_billing ) return true;

                        // If theme still posts shipping in background, accept it too
                        if ( $posted_has_shipping ) return true;

                    } elseif ( $ship_to_diff === true ) {

                        if ( $posted_has_shipping ) return true;
                    } else {

                        // Unknown intent: accept either if complete
                        if ( $posted_has_shipping || $posted_has_billing ) return true;
                    }

                    // Fallback to WC customer data
                    if ( function_exists('WC') && WC()->customer ) {
                        $c = WC()->customer;

                        $ship_country  = $c->get_shipping_country();
                        $ship_city     = $c->get_shipping_city();
                        $ship_addr1    = $c->get_shipping_address_1();
                        $ship_postcode = $c->get_shipping_postcode();

                        $has_shipping = $ship_country && $ship_city && $ship_addr1 && $ship_postcode;
                        if ( $has_shipping ) return true;

                        $bill_country  = $c->get_billing_country();
                        $bill_city     = $c->get_billing_city();
                        $bill_addr1    = $c->get_billing_address_1();
                        $bill_postcode = $c->get_billing_postcode();

                        $has_billing = $bill_country && $bill_city && $bill_addr1 && $bill_postcode;
                        if ( $has_billing ) return true;
                    }

                    return false;
                }

                /**
                 * Default shipping chosen method logic
                 * - Only "defaults" to miles_based when appropriate.
                 * - Avoids overriding an already valid selection.
                 *
                 * WooCommerce passes: ( $default, $package_rates, $chosen_method_from_session ).
                 */
                function rental_shipping_default_method( $default, $available_methods, $chosen_method = '' ) {

                    $miles_method = 'miles_based';

                    // If miles_based isn't offered in this package, keep WooCommerce's choice.
                    if ( ! isset( $available_methods[ $miles_method ] ) ) {
                        return $default;
                    }

                    if ( $chosen_method !== '' && isset( $available_methods[ $chosen_method ] ) ) {
                        return $chosen_method;
                    }

                    // No valid prior choice for this package — default to miles_based.
                    return $miles_method;
                }
                add_filter('woocommerce_shipping_chosen_method', 'rental_shipping_default_method', 10, 3);


                add_action('wp_footer', function () {

                    if ( get_option('rental_pickup_delivery') !== 'company_client_delivery_return' ) {
                        return;
                    }
                
                    if ( !( is_cart() || is_checkout()) ) {
                        return;
                    }
                    ?>

                    <script>
                        jQuery(function($){
                    
                            function getVal(sel){
                                var $el = $(sel);
                                if(!$el.length) return '';
                                return ($el.val() || '').toString().trim();
                            }
                    
                            function hasAddress() {
                    
                                // Cart shipping calculator (if present)
                                var c_country  = getVal('select[name="calc_shipping_country"]');
                                var c_city     = getVal('input[name="calc_shipping_city"]');
                                var c_addr1    = getVal('input[name="calc_shipping_address"]');
                                var c_postcode = getVal('input[name="calc_shipping_postcode"]');
                    
                                var hasCalc = !!(c_country && c_city && c_addr1 && c_postcode);
                                if (hasCalc) return true;
                    
                                // Checkout shipping fields
                                var s_country  = getVal('#shipping_country');
                                var s_city     = getVal('#shipping_city');
                                var s_addr1    = getVal('#shipping_address_1');
                                var s_postcode = getVal('#shipping_postcode');
                    
                                var hasShipping = !!(s_country && s_city && s_addr1 && s_postcode);
                    
                                // Checkout billing fields
                                var b_country  = getVal('#billing_country');
                                var b_city     = getVal('#billing_city');
                                var b_addr1    = getVal('#billing_address_1');
                                var b_postcode = getVal('#billing_postcode');
                    
                                var hasBilling = !!(b_country && b_city && b_addr1 && b_postcode);
                    
                                var $shipDiff = $('input[name="ship_to_different_address"]');
                    
                                // If the toggle exists and is unchecked, Woo uses billing as shipping
                                if ($shipDiff.length && !$shipDiff.is(':checked')) {
                                    return hasBilling;
                                }
                    
                                // Otherwise accept either complete set
                                return hasShipping || hasBilling;
                            }
                    
                            function toggleMilesBased() {
                                var $miles = $('input.shipping_method[value="miles_based"]');
                                if (!$miles.length) return;

                                var $li = $miles.closest('li');

                                if (!hasAddress()) {

                                    // Show an informational note so the customer knows they need to
                                    // complete their address before delivery pricing is available.
                                    if (!$li.find('.shipping-disabled-note').length) {
                                        $li.append(
                                            '<div class="shipping-disabled-note"><?php echo esc_js( __( 'Enter your address to enable this option.', 'rentopian-sync' ) ); ?></div>'
                                        );
                                    }

                                } else {
                                    // Remove note when address is valid
                                    $li.find('.shipping-disabled-note').remove();
                                }
                            }
                    
                            // Initial run
                            toggleMilesBased();
                    
                            // Watch BOTH shipping + billing + cart page shipping calculator fields
                            $(document).on(
                                'change',
                                [
                                    '#shipping_country','#shipping_city','#shipping_address_1','#shipping_postcode',
                                    '#billing_country','#billing_city','#billing_address_1','#billing_postcode',
                                    'select[name="calc_shipping_country"]',
                                    'input[name="calc_shipping_city"]',
                                    'input[name="calc_shipping_address"]',
                                    'input[name="calc_shipping_postcode"]',
                                    'input[name="ship_to_different_address"]'
                                ].join(','),
                                toggleMilesBased
                            );
                    
                            // Re-run when Woo updates totals / shipping methods via AJAX
                            $(document.body).on(
                                'updated_checkout updated_cart_totals updated_shipping_method wc_fragments_refreshed',
                                toggleMilesBased
                            );
                        });
                    </script>
                
                    <style>
                        .woocommerce-shipping-methods li .shipping-disabled-note {
                            font-size: 11px;
                            opacity: 0.8;
                            margin-top: 2px;
                        }
                    </style>
                    <?php
                });

            } else {
            // Default shipping methods flow

                /** Set default method to miles based when it is enabled **/
                add_filter('woocommerce_shipping_chosen_method', 'rental_shipping_default_method', 10, 3);
                function rental_shipping_default_method( $default, $available_methods, $chosen_method = '' ) {

                    $miles_method = 'miles_based';

                    // If miles_based isn't offered in this package, keep WooCommerce's choice.
                    if ( ! isset( $available_methods[ $miles_method ] ) ) {
                        return $default;
                    }

                    if ( $chosen_method !== '' && isset( $available_methods[ $chosen_method ] ) ) {
                        return $chosen_method;
                    }

                    // No valid prior choice for this package — default to miles_based.
                    return $miles_method;
                }
            }

        }

        // generate product options placeholder
        function rental_generate_product_options() {
            global $product;
            $product_id = $product->get_id();
            $options = [];
            $is_set_class = '';
            if (get_post_meta($product_id, '_rental_is_set', true)) {
                $options = get_set_options($product_id);
                $is_set_class = 'rental-is-set';
            } else {
                $options = get_product_options($product_id);
            }

            if (!empty($options)) {
                $opt_rental_select_option_text = get_option('rental_select_option_text');
                $opt_rental_select_option_text = $opt_rental_select_option_text ? $opt_rental_select_option_text : 'Please select an option:';
                echo "<h5 class='product-options-label'>".$opt_rental_select_option_text."</h5>";
                echo "<div class='rental-product-options $is_set_class'></div><br/>";
            }
        }
        add_action( 'woocommerce_before_add_to_cart_button', 'rental_generate_product_options' );


        function rental_woocommerce_dropdown_variation_attribute_options_html( $html, $args )
        {
            global $wpdb, $rental_tables;
            $rental_variant_relations = $wpdb->prefix . $rental_tables["variant_relations"];

            $product = $args[ 'product' ];
            $attribute = $args[ 'attribute' ];
            $terms = wc_get_product_terms( $product->get_id(), $attribute, array( 'fields' => 'all' ) );
            $options = $args[ 'options' ];
            $options_final = [];

            $sql = "
                SELECT
                    posts.ID,
                    variant_rel.rental_id,
                    variant_rel.rental_division_id
                FROM
                    {$wpdb->posts} posts
                LEFT JOIN {$rental_variant_relations} variant_rel ON variant_rel.id = posts.ID
                WHERE
                    post_parent = %d
            ";
            $wp_variant_rental_and_wp_ids = $wpdb->get_results($wpdb->prepare($sql, $product->get_id()), ARRAY_A);

            $options_hiddable = true;
            if (count($wp_variant_rental_and_wp_ids) > count($options) ) {
                $options_hiddable = false;
            }

            // start/end date + zip code filter
            $hidden_variant_ids = [];

            $rental_unavailable_items_opt = get_option('rental_unavailable_items');
            if ($rental_unavailable_items_opt && is_array($rental_unavailable_items_opt)) {
                if (isset($rental_unavailable_items_opt['unavailable_variant_ids_with_divs'])) {
                    $unavailable_variant_ids_with_divs = !empty($rental_unavailable_items_opt['unavailable_variant_ids_with_divs']) ? $rental_unavailable_items_opt['unavailable_variant_ids_with_divs'] : [];
                    if ($unavailable_variant_ids_with_divs) {
                        foreach($wp_variant_rental_and_wp_ids as $id) {
                            foreach($unavailable_variant_ids_with_divs as $variant_id_with_div){
                                // with division working
                                if ($id['rental_division_id'] == $variant_id_with_div['division_id']) {
                                    if ($id['rental_id'] == $variant_id_with_div['variant_id']) {
                                        $hidden_variant_ids[] = $id['ID'];
                                    }
                                }
                            }
                        }
                    }
                }

            }

            $variant_attrs_vals = [];
            $attributes_filtered = [];
            if ($hidden_variant_ids) {
                foreach($hidden_variant_ids as $var_id) {
                    $variation = wc_get_product($var_id);
                    $attrs_of_vars = $variation->get_attributes();
                    if ($attrs_of_vars) {
                        foreach($attrs_of_vars as $key=>$value) {
                            $variant_attrs_vals[] = $value;
                        }
                    }
                }

                if ($options) {
                    foreach($options as $option) {
                        if (!in_array($option, $variant_attrs_vals)) {
                            $options_final[] = $option;
                        }
                    }

                    if (empty($options_final)) {
                        ?>
                        <script>
                            jQuery(document).ready(function(){
                                setTimeout(() => {
                                    jQuery('a.reset_variations').trigger('click');
                                }, 1000);
                            });
                        </script>
                        <?php
                    }
                }

                $attributes_filtered = $product->get_variation_attributes();
                foreach($attributes_filtered as $key => $var_attr) {
                    if ($var_attr) {
                        $var_attr_new = [];
                        foreach($var_attr as $var_attr_single) {
                            if (!in_array($var_attr_single, $variant_attrs_vals)) {
                                $var_attr_new[] = $var_attr_single;
                            }
                        }
                    }
                    $attributes_filtered[$key] = $var_attr_new;
                }
            }


            if ( empty( $options ) && !empty( $product ) && !empty( $attribute ) ) {
                $attributes = $attributes_filtered;
                if(isset($attributes[ $attribute ])) {
                    $options = $attributes[$attribute];
                }
            }

            foreach ( $terms as $key => $term ) {
                if (isset($options_final) && $hidden_variant_ids) {
                    $options = $options_final;
                }

                $hidden = ' ';
                if ($options_hiddable) {
                    $hidden = 'hidden';
                }

                if ( !in_array( $term->slug, $options )) {
                    $html = str_replace( '<option value="' . esc_attr( $term->slug ) . '" ', '<option '.$hidden.' value="' . esc_attr( $term->slug ) . '" ', $html );
                } else {

                    $selected = '';
                    if (count($terms) == 1) {
                        $selected = ' selected ';
                    }
                    $html = str_replace( '<option value="' . esc_attr( $term->slug ) . '" ', '<option '.$selected.' value="' . esc_attr( $term->slug ) . '" ', $html );

                }

            }
            return $html;
        }
        add_filter( 'woocommerce_dropdown_variation_attribute_options_html', 'rental_woocommerce_dropdown_variation_attribute_options_html', 10, 2 );

    }


    function rental_enqueue_google_maps_scripts() {
        wp_enqueue_script(
            'rental-google-maps-api',
            plugins_url('/assets/js/rental-google-maps-api.js', __FILE__),
            ['jquery'],
            RENTOPIAN_SYNC_VERSION,
            true
        );

        // Fetch Google Map keys
        $google_map_key = get_option('rental_google_map_key') ?: '';
        $google_distance_key = get_option('rental_google_distance_key') ?: '';
        $use_distance_key = get_option('rental_use_google_distance_key') && !empty($google_distance_key);

        // Determine the effective key
        $final_key = $use_distance_key ? $google_distance_key : $google_map_key;

        wp_localize_script('rental-google-maps-api', 'rentalGoogleMapConfig', [
            'google_map_key' => $final_key,
            'form_layout' => get_option('rental_form_layout') ?: 'horizontal',
            'show_location' => (bool) get_option('rental_show_location') ? true : false,
        ]);

    }
    add_action('wp_enqueue_scripts', 'rental_enqueue_google_maps_scripts');

    /*
        if (get_option('rental_filter_unavailable_products', 0)) {
            // Custom function to modify the WooCommerce products shortcode output
            function rental_products_shortcode($attrs) {

                $division_id = null;
                if (isset($_POST['rental_division_id'])) {
                    $division_id = intval($_POST['rental_division_id']);
                } elseif (isset($_COOKIE['rental_division_id'])) {
                    $division_id = intval($_COOKIE['rental_division_id']);
                }

                $decrypted_rental_zip = 0;
                if (isset($_COOKIE['rental_zip']) && $_COOKIE['rental_zip']) {
                    $decrypted_rental_zip = decrypt_data($_COOKIE['rental_zip'], get_option('rental_encryption_key'));
                }

                $location_based_duplicate_filter = 0;
                $location_based_duplicate_filter_division_id = 0;
                if (
                    empty($division_id)
                    && ( get_option('rental_hide_zip')
                    || ( !get_option('rental_hide_zip') && (!isset($_COOKIE['rental_zip']) || empty($_COOKIE['rental_zip']) || $decrypted_rental_zip == 1)))
                ) {
                    $location_based_duplicate_filter = get_option('rental_location_based_duplicate_filter', 0);
                    $location_based_duplicate_filter_division_id = get_option('rental_location_based_duplicate_filter_division_id', 0);
                }

                $duplicate_filter = ["location_based_duplicate_filter" => $location_based_duplicate_filter,
                "location_based_duplicate_filter_division_id" => $location_based_duplicate_filter_division_id];

                $rental_unavailable_items = [];
                // $rental_unavailable_items_opt = get_option('rental_unavailable_items');
                // if ($rental_unavailable_items_opt && is_array($rental_unavailable_items_opt)) {
                //     $rental_unavailable_items = $rental_unavailable_items_opt;
                // }

                $item_ids_list = '';
                if ($data = get_rental_cache('rental_product_variant_list_filter', 'rental_product_variant_list_filter_expiration_date')) {
                    $item_ids_list = $data;
                }

                if (!$item_ids_list) {
                    // handeling rentopian filters
                    $item_ids_list = product_variant_list_filter($rental_unavailable_items, $division_id, [], 0, $duplicate_filter);

                    set_rental_cache($item_ids_list, 'rental_product_variant_list_filter', 'rental_product_variant_list_filter_expiration_date');
                }

                $item_ids_list = $item_ids_list ? implode(',', $item_ids_list) : '';

                $attrs = parse_legacy_attributes($attrs);

                $attrs = shortcode_atts(
                    [
                        'limit'          => '-1',      // Results limit.
                        'columns'        => '',        // Number of columns.
                        'rows'           => '',        // Number of rows. If defined, limit will be ignored.
                        'orderby'        => '',        // menu_order, title, date, rand, price, popularity, rating, or id.
                        'order'          => '',        // ASC or DESC.
                        'ids'            => $item_ids_list,        // Comma separated IDs.
                        'skus'           => '',        // Comma separated SKUs.
                        'category'       => '',        // Comma separated category slugs or ids.
                        'cat_operator'   => 'IN',      // Operator to compare categories. Possible values are 'IN', 'NOT IN', 'AND'.
                        'attribute'      => '',        // Single attribute slug.
                        'terms'          => '',        // Comma separated term slugs or ids.
                        'terms_operator' => 'IN',      // Operator to compare terms. Possible values are 'IN', 'NOT IN', 'AND'.
                        'tag'            => '',        // Comma separated tag slugs.
                        'tag_operator'   => 'IN',      // Operator to compare tags. Possible values are 'IN', 'NOT IN', 'AND'.
                        'visibility'     => 'visible', // Product visibility setting. Possible values are 'visible', 'catalog', 'search', 'hidden'.
                        'class'          => '',        // HTML class.
                        'page'           => 1,         // Page for pagination.
                        'paginate'       => false,     // Should results be paginated.
                        'cache'          => true,      // Should shortcode output be cached.
                    ],
                    $attrs,
                    'products'
                );

                if ( ! absint( $attrs['columns'] ) ) {
                    $attrs['columns'] = wc_get_default_products_per_row();
                }

                $output = do_shortcode("[products " . implode(' ', array_map(function ($key, $value) {
                    return "$key='$value'";
                }, array_keys($attrs), $attrs)) . "]");

                return $output;
            }
            // Hook to override the default products shortcode
            add_shortcode('rs_products', 'rental_products_shortcode');


            function parse_legacy_attributes( $attributes ) {
                $mapping = array(
                    'per_page' => 'limit',
                    'operator' => 'cat_operator',
                    'filter'   => 'terms',
                );

                foreach ( $mapping as $old => $new ) {
                    if ( isset( $attributes[ $old ] ) ) {
                        $attributes[ $new ] = $attributes[ $old ];
                        unset( $attributes[ $old ] );
                    }
                }

                return $attributes;
            }

            // Elementor products related shortcodes override
            if (in_array('elementor/elementor.php', apply_filters('active_plugins', get_option('active_plugins')))) {

                // Custom function to modify the recent products shortcode output for Elementor
                function rs_recent_products_shortcode($attrs) {

                    $division_id = null;
                    if (isset($_POST['rental_division_id'])) {
                        $division_id = intval($_POST['rental_division_id']);
                    } elseif (isset($_COOKIE['rental_division_id'])) {
                        $division_id = intval($_COOKIE['rental_division_id']);
                    }

                    $decrypted_rental_zip = 0;
                    if (isset($_COOKIE['rental_zip']) && $_COOKIE['rental_zip']) {
                        $decrypted_rental_zip = decrypt_data($_COOKIE['rental_zip'], get_option('rental_encryption_key'));
                    }

                    $location_based_duplicate_filter = 0;
                    $location_based_duplicate_filter_division_id = 0;
                    if (
                        empty($division_id)
                        && ( get_option('rental_hide_zip')
                        || ( !get_option('rental_hide_zip') && (!isset($_COOKIE['rental_zip']) || empty($_COOKIE['rental_zip']) || $decrypted_rental_zip == 1)))
                    ) {
                        $location_based_duplicate_filter = get_option('rental_location_based_duplicate_filter', 0);
                        $location_based_duplicate_filter_division_id = get_option('rental_location_based_duplicate_filter_division_id', 0);
                    }

                    $duplicate_filter = ["location_based_duplicate_filter" => $location_based_duplicate_filter,
                    "location_based_duplicate_filter_division_id" => $location_based_duplicate_filter_division_id];

                    $rental_unavailable_items = [];
                    // $rental_unavailable_items_opt = get_option('rental_unavailable_items');
                    // if ($rental_unavailable_items_opt && is_array($rental_unavailable_items_opt)) {
                    //     $rental_unavailable_items = $rental_unavailable_items_opt;
                    // }

                    // handeling rentopian filters
                    $item_ids_list = '';
                    if ($data = get_rental_cache('rental_product_variant_list_filter', 'rental_product_variant_list_filter_expiration_date')) {
                        $item_ids_list = $data;
                    }


                    if (!$item_ids_list) {
                        // handeling rentopian filters
                        $item_ids_list = product_variant_list_filter($rental_unavailable_items, $division_id, [], 0, $duplicate_filter);

                        set_rental_cache($item_ids_list, 'rental_product_variant_list_filter', 'rental_product_variant_list_filter_expiration_date');
                    }

                    $item_ids_list = $item_ids_list ? implode(',', $item_ids_list) : '';

                    // Merge with default attributes
                    $attrs = shortcode_atts(
                        [
                            'limit' => '-1', // Results limit.
                            'orderby' => 'date', // Order by date by default.
                            'order' => 'desc', // Order by descending by default.
                            'ids'            => $item_ids_list,        // Comma separated IDs.
                        ],
                        $attrs,
                        'recent_products'
                    );

                    // Generate the modified shortcode with updated attributes
                    $shortcode = '[recent_products ' . implode(' ', array_map(function ($key, $value) {
                        return "$key='$value'";
                    }, array_keys($attrs), $attrs)) . ']';

                    // Execute the modified shortcode and return the output
                    $output = do_shortcode($shortcode);

                    return $output;
                }
                add_shortcode('rs_recent_products', 'rs_recent_products_shortcode');


                // Custom function to modify the featured products shortcode output for Elementor
                function rs_featured_products_shortcode($attrs) {

                    $division_id = null;
                    if (isset($_POST['rental_division_id'])) {
                        $division_id = intval($_POST['rental_division_id']);
                    } elseif (isset($_COOKIE['rental_division_id'])) {
                        $division_id = intval($_COOKIE['rental_division_id']);
                    }

                    $decrypted_rental_zip = 0;
                    if (isset($_COOKIE['rental_zip']) && $_COOKIE['rental_zip']) {
                        $decrypted_rental_zip = decrypt_data($_COOKIE['rental_zip'], get_option('rental_encryption_key'));
                    }

                    $location_based_duplicate_filter = 0;
                    $location_based_duplicate_filter_division_id = 0;
                    if (
                        empty($division_id)
                        && ( get_option('rental_hide_zip')
                        || ( !get_option('rental_hide_zip') && (!isset($_COOKIE['rental_zip']) || empty($_COOKIE['rental_zip']) || $decrypted_rental_zip == 1)))
                    ) {
                        $location_based_duplicate_filter = get_option('rental_location_based_duplicate_filter', 0);
                        $location_based_duplicate_filter_division_id = get_option('rental_location_based_duplicate_filter_division_id', 0);
                    }

                    $duplicate_filter = ["location_based_duplicate_filter" => $location_based_duplicate_filter,
                        "location_based_duplicate_filter_division_id" => $location_based_duplicate_filter_division_id];

                    $rental_unavailable_items = [];
                    // $rental_unavailable_items_opt = get_option('rental_unavailable_items');
                    // if ($rental_unavailable_items_opt && is_array($rental_unavailable_items_opt)) {
                    //     $rental_unavailable_items = $rental_unavailable_items_opt;
                    // }

                    // handling rentopian filters
                    $item_ids_list = '';
                    if ($data = get_rental_cache('rental_product_variant_list_filter', 'rental_product_variant_list_filter_expiration_date')) {
                        $item_ids_list = $data;
                    }

                    if (!$item_ids_list) {
                        // handeling rentopian filters
                        $item_ids_list = product_variant_list_filter($rental_unavailable_items, $division_id, [], 0, $duplicate_filter);

                        set_rental_cache($item_ids_list, 'rental_product_variant_list_filter', 'rental_product_variant_list_filter_expiration_date');
                    }

                    // $item_ids_list = product_variant_list_filter($rental_unavailable_items, $division_id, [], 0, $duplicate_filter);
                    $item_ids_list = $item_ids_list ? implode(',', $item_ids_list) : '';

                    // Merge with default attributes
                    $attrs = shortcode_atts(
                        [
                            'limit' => '-1', // Results limit.
                            'columns' => '', // Number of columns.
                            'orderby' => 'date', // Order by date by default.
                            'order' => 'desc', // Order by descending by default.
                            'ids' => $item_ids_list, // Comma separated IDs.
                            'class' => '', // HTML class.
                        ],
                        $attrs,
                        'featured_products'
                    );

                    // Generate the modified shortcode with updated attributes
                    $shortcode = '[featured_products ' . implode(' ', array_map(function ($key, $value) {
                        return "$key='$value'";
                    }, array_keys($attrs), $attrs)) . ']';

                    // Execute the modified shortcode and return the output
                    $output = do_shortcode($shortcode);

                    return $output;
                }
                add_shortcode('rs_featured_products', 'rs_featured_products_shortcode');


                function rs_best_selling_products_shortcode($attrs) {

                    $division_id = null;
                    if (isset($_POST['rental_division_id'])) {
                        $division_id = intval($_POST['rental_division_id']);
                    } elseif (isset($_COOKIE['rental_division_id'])) {
                        $division_id = intval($_COOKIE['rental_division_id']);
                    }

                    $decrypted_rental_zip = 0;
                    if (isset($_COOKIE['rental_zip']) && $_COOKIE['rental_zip']) {
                        $decrypted_rental_zip = decrypt_data($_COOKIE['rental_zip'], get_option('rental_encryption_key'));
                    }

                    $location_based_duplicate_filter = 0;
                    $location_based_duplicate_filter_division_id = 0;
                    if (
                        empty($division_id)
                        && ( get_option('rental_hide_zip')
                        || ( !get_option('rental_hide_zip') && (!isset($_COOKIE['rental_zip']) || empty($_COOKIE['rental_zip']) || $decrypted_rental_zip == 1)))
                    ) {
                        $location_based_duplicate_filter = get_option('rental_location_based_duplicate_filter', 0);
                        $location_based_duplicate_filter_division_id = get_option('rental_location_based_duplicate_filter_division_id', 0);
                    }

                    $duplicate_filter = ["location_based_duplicate_filter" => $location_based_duplicate_filter,
                        "location_based_duplicate_filter_division_id" => $location_based_duplicate_filter_division_id];

                    $rental_unavailable_items = [];
                    // $rental_unavailable_items_opt = get_option('rental_unavailable_items');
                    // if ($rental_unavailable_items_opt && is_array($rental_unavailable_items_opt)) {
                    //     $rental_unavailable_items = $rental_unavailable_items_opt;
                    // }

                    // Handling rentopian filters
                    $item_ids_list = '';
                    if ($data = get_rental_cache('rental_product_variant_list_filter', 'rental_product_variant_list_filter_expiration_date')) {
                        $item_ids_list = $data;
                    }

                    if (!$item_ids_list) {
                        // handeling rentopian filters
                        $item_ids_list = product_variant_list_filter($rental_unavailable_items, $division_id, [], 0, $duplicate_filter);

                        set_rental_cache($item_ids_list, 'rental_product_variant_list_filter', 'rental_product_variant_list_filter_expiration_date');
                    }

                    // $item_ids_list = product_variant_list_filter($rental_unavailable_items, $division_id, [], 0, $duplicate_filter);
                    $item_ids_list = $item_ids_list ? implode(',', $item_ids_list) : '';

                    // Merge with default attributes
                    $attrs = shortcode_atts(
                        [
                            'limit' => '-1', // Results limit.
                            'columns' => '', // Number of columns.
                            'orderby' => 'date', // Order by date by default.
                            'order' => 'desc', // Order by descending by default.
                            'ids' => $item_ids_list, // Comma separated IDs.
                            'class' => '', // HTML class.
                        ],
                        $attrs,
                        'best_selling_products'
                    );

                    // Generate the modified shortcode with updated attributes
                    $shortcode = '[best_selling_products ' . implode(' ', array_map(function ($key, $value) {
                        return "$key='$value'";
                    }, array_keys($attrs), $attrs)) . ']';

                    // Execute the modified shortcode and return the output
                    $output = do_shortcode($shortcode);

                    return $output;
                }
                add_shortcode('rs_best_selling_products', 'rs_best_selling_products_shortcode');


                function rs_top_rated_products_shortcode($attrs) {

                    $division_id = null;
                    if (isset($_POST['rental_division_id'])) {
                        $division_id = intval($_POST['rental_division_id']);
                    } elseif (isset($_COOKIE['rental_division_id'])) {
                        $division_id = intval($_COOKIE['rental_division_id']);
                    }

                    $decrypted_rental_zip = 0;
                    if (isset($_COOKIE['rental_zip']) && $_COOKIE['rental_zip']) {
                        $decrypted_rental_zip = decrypt_data($_COOKIE['rental_zip'], get_option('rental_encryption_key'));
                    }

                    $location_based_duplicate_filter = 0;
                    $location_based_duplicate_filter_division_id = 0;
                    if (
                        empty($division_id)
                        && ( get_option('rental_hide_zip')
                        || ( !get_option('rental_hide_zip') && (!isset($_COOKIE['rental_zip']) || empty($_COOKIE['rental_zip']) || $decrypted_rental_zip == 1)))
                    ) {
                        $location_based_duplicate_filter = get_option('rental_location_based_duplicate_filter', 0);
                        $location_based_duplicate_filter_division_id = get_option('rental_location_based_duplicate_filter_division_id', 0);
                    }

                    $duplicate_filter = ["location_based_duplicate_filter" => $location_based_duplicate_filter,
                        "location_based_duplicate_filter_division_id" => $location_based_duplicate_filter_division_id];

                    $rental_unavailable_items = [];
                    // $rental_unavailable_items_opt = get_option('rental_unavailable_items');
                    // if ($rental_unavailable_items_opt && is_array($rental_unavailable_items_opt)) {
                    //     $rental_unavailable_items = $rental_unavailable_items_opt;
                    // }

                    // Handling rentopian filters
                    $item_ids_list = '';
                    if ($data = get_rental_cache('rental_product_variant_list_filter', 'rental_product_variant_list_filter_expiration_date')) {
                        $item_ids_list = $data;
                    }

                    if (!$item_ids_list) {
                        // handeling rentopian filters
                        $item_ids_list = product_variant_list_filter($rental_unavailable_items, $division_id, [], 0, $duplicate_filter);

                        set_rental_cache($item_ids_list, 'rental_product_variant_list_filter', 'rental_product_variant_list_filter_expiration_date');
                    }

                    // $item_ids_list = product_variant_list_filter($rental_unavailable_items, $division_id, [], 0, $duplicate_filter);
                    $item_ids_list = $item_ids_list ? implode(',', $item_ids_list) : '';

                    // Merge with default attributes
                    $attrs = shortcode_atts(
                        [
                            'limit' => '-1', // Results limit.
                            'columns' => '', // Number of columns.
                            'orderby' => 'date', // Order by date by default.
                            'order' => 'desc', // Order by descending by default.
                            'ids' => $item_ids_list, // Comma separated IDs.
                            'class' => '', // HTML class.
                        ],
                        $attrs,
                        'top_rated_products'
                    );

                    // Generate the modified shortcode with updated attributes
                    $shortcode = '[top_rated_products ' . implode(' ', array_map(function ($key, $value) {
                        return "$key='$value'";
                    }, array_keys($attrs), $attrs)) . ']';

                    // Execute the modified shortcode and return the output
                    $output = do_shortcode($shortcode);

                    return $output;
                }
                add_shortcode('rs_top_rated_products', 'rs_top_rated_products_shortcode');


                function rs_sale_products_shortcode($attrs) {

                    $division_id = null;
                    if (isset($_POST['rental_division_id'])) {
                        $division_id = intval($_POST['rental_division_id']);
                    } elseif (isset($_COOKIE['rental_division_id'])) {
                        $division_id = intval($_COOKIE['rental_division_id']);
                    }

                    $decrypted_rental_zip = 0;
                    if (isset($_COOKIE['rental_zip']) && $_COOKIE['rental_zip']) {
                        $decrypted_rental_zip = decrypt_data($_COOKIE['rental_zip'], get_option('rental_encryption_key'));
                    }

                    $location_based_duplicate_filter = 0;
                    $location_based_duplicate_filter_division_id = 0;
                    if (
                        empty($division_id)
                        && ( get_option('rental_hide_zip')
                        || ( !get_option('rental_hide_zip') && (!isset($_COOKIE['rental_zip']) || empty($_COOKIE['rental_zip']) || $decrypted_rental_zip == 1)))
                    ) {
                        $location_based_duplicate_filter = get_option('rental_location_based_duplicate_filter', 0);
                        $location_based_duplicate_filter_division_id = get_option('rental_location_based_duplicate_filter_division_id', 0);
                    }

                    $duplicate_filter = ["location_based_duplicate_filter" => $location_based_duplicate_filter,
                        "location_based_duplicate_filter_division_id" => $location_based_duplicate_filter_division_id];

                    $rental_unavailable_items = [];
                    // $rental_unavailable_items_opt = get_option('rental_unavailable_items');
                    // if ($rental_unavailable_items_opt && is_array($rental_unavailable_items_opt)) {
                    //     $rental_unavailable_items = $rental_unavailable_items_opt;
                    // }

                    // Handling rentopian filters
                    $item_ids_list = '';
                    if ($data = get_rental_cache('rental_product_variant_list_filter', 'rental_product_variant_list_filter_expiration_date')) {
                        $item_ids_list = $data;
                    }

                    if (!$item_ids_list) {
                        // handeling rentopian filters
                        $item_ids_list = product_variant_list_filter($rental_unavailable_items, $division_id, [], 0, $duplicate_filter);

                        set_rental_cache($item_ids_list, 'rental_product_variant_list_filter', 'rental_product_variant_list_filter_expiration_date');
                    }

                    // $item_ids_list = product_variant_list_filter($rental_unavailable_items, $division_id, [], 0, $duplicate_filter);
                    $item_ids_list = $item_ids_list ? implode(',', $item_ids_list) : '';

                    // Merge with default attributes
                    $attrs = shortcode_atts(
                        [
                            'limit' => '-1', // Results limit.
                            'columns' => '', // Number of columns.
                            'orderby' => 'date', // Order by date by default.
                            'order' => 'desc', // Order by descending by default.
                            'ids' => $item_ids_list, // Comma separated IDs.
                            'class' => '', // HTML class.
                        ],
                        $attrs,
                        'sale_products'
                    );

                    $shortcode = '[sale_products ' . implode(' ', array_map(function ($key, $value) {
                        return "$key='$value'";
                    }, array_keys($attrs), $attrs)) . ']';

                    $output = do_shortcode($shortcode);

                    return $output;
                }
                add_shortcode('rs_sale_products', 'rs_sale_products_shortcode');

            }
        }
    */


    function rental_stock_display() {
        $woocommerce_stock_format = get_option('woocommerce_stock_format', '');
    
        if ( $woocommerce_stock_format === 'no_amount' ) {
            return '';
        }
    
        global $product;
        if ( ! $product ) {
            return;
        }
    
        $product_id = method_exists( $product, 'get_id' ) ? $product->get_id() : ( isset( $product->id ) ? $product->id : 0 );
    
        // Check rental meta
        $is_rental_set = get_post_meta( $product_id, '_rental_is_set', true );
        $rental_set_max_qty = get_post_meta( $product_id, '_rental_max_quantity', true);
    
        if ( $is_rental_set && !empty($rental_set_max_qty)) {
            // Use rental meta value (coerce to numeric)
            $product_quantity = wc_stock_amount( $rental_set_max_qty );
            $has_set_max_qty = true;
        } else {
            // fallback to real stock quantity
            $product_quantity = wc_stock_amount( $product->get_stock_quantity() );
            $has_set_max_qty = false;
        }
    
        if ( $woocommerce_stock_format === 'low_amount' ) {
            $woocommerce_notify_low_stock_amount = absint( get_option( 'woocommerce_notify_low_stock_amount', 2 ) );
            if ( $product_quantity <= $woocommerce_notify_low_stock_amount ) {
                product_stock_block( $product, $product_quantity, $has_set_max_qty );
            }
        } else {
            product_stock_block( $product, $product_quantity, $has_set_max_qty );
        }
    }
    add_action( 'woocommerce_after_shop_loop_item_title', 'rental_stock_display' );
    add_action( 'woocommerce_single_product_summary', 'rental_stock_display', 16 );
    
    function product_stock_block( $product, $product_quantity, $has_set_max_qty = false ) {
        $qty = intval( $product_quantity );
    
        if ( $has_set_max_qty ) {
            // If rental set meta is present, show rental max quantity (or out-of-stock if zero/negative)
            if ( $qty > 0 ) {
                echo '<div class="stock">' . esc_html( $qty ) . ' ' . esc_html__( 'in stock') . '</div>';
            } else {
                echo '<div class="out-of-stock">' . esc_html__( 'out of stock') . '</div>';
            }
        } else {
            // Normal product stock behaviour
            if ( $product->is_in_stock() ) {
                echo '<div class="stock">' . esc_html( $qty ) . ' ' . esc_html__( 'in stock') . '</div>';
            } else {
                echo '<div class="out-of-stock">' . esc_html__( 'out of stock') . '</div>';
            }
        }
    }
    


    function rental_remove_hidden_products_from_search_index($url, $type, $post) {
        if (
            ($post->post_type == 'product' || $post->post_type == 'product_variation')
            && is_object_in_term( $post->ID, 'product_visibility', 'hidden')
        ){
            return false;
        }

        if ($rental_no_index_items = get_option('rental_no_index_items', [])) {

            foreach($rental_no_index_items as $rental_no_index_item_id) {

                if ($post->ID == $rental_no_index_item_id) {
                    return false;
                }
            }
        }

        return $url;
    }
    add_filter( 'wpseo_sitemap_entry', 'rental_remove_hidden_products_from_search_index', 99, 3);



    add_action('woocommerce_after_add_to_cart_form', 'rental_show_variation_dimensions_weight');
    function rental_show_variation_dimensions_weight() {
        if ( class_exists('Rentpro_THA') && is_product() ) {
            echo '<div id="variation-dimensions-weight"></div><br/>';
        }
    }

    add_action('wp_enqueue_scripts', 'enqueue_variation_dimensions_scripts');
    function enqueue_variation_dimensions_scripts() {
        if ( is_product() && class_exists('Rentpro_THA') ) {
            // Enqueue the custom JS file.
            wp_enqueue_script(
                'rental-variation-dimensions',
                plugins_url('/assets/js/rental-variation-dimensions.js', __FILE__),
                array('jquery'),
                null,
                true
            );

            // Pass dynamic data to the script.
            wp_localize_script(
                'rental-variation-dimensions',
                'variationData',
                array(
                    'ajax_url'          => admin_url('admin-ajax.php'),
                    // Pass current product ID (for simple product usage).
                    'simple_product_id' => get_the_ID(),
                )
            );
        }
    }


    add_action('wp_ajax_get_dimensions_weight', 'get_dimensions_weight');
    add_action('wp_ajax_nopriv_get_dimensions_weight', 'get_dimensions_weight');

    function get_dimensions_weight() {
        // Check if either a variation_id or product_id was provided.
        if ( isset($_POST['variation_id']) || isset($_POST['product_id']) ) {
            // Use variation_id if provided, otherwise fallback to product_id.
            $id = isset($_POST['variation_id']) ? intval($_POST['variation_id']) : intval($_POST['product_id']);
            $product = wc_get_product($id);
    
            if ( $product && $product->exists() ) {
    
                // Retrieve weight and individual dimensions.
                if ( $product->is_type('simple') ) {
                    $weight = floatval( get_post_meta( $id, '_weight', true ) );
                    $length = floatval( get_post_meta( $id, '_length', true ) );
                    $width  = floatval( get_post_meta( $id, '_width', true ) );
                    $height = floatval( get_post_meta( $id, '_height', true ) );
                    $depth  = floatval( get_post_meta( $id, '_depth', true ) );
                } else {
                    // For variations (or other product types), use WooCommerce getter methods where available; depth is custom meta.
                    $weight = $product->get_weight();
                    $length = $product->get_length();
                    $width  = $product->get_width();
                    $height = $product->get_height();
                    $depth  = floatval( get_post_meta( $id, '_depth', true ) );
                }
    
                // Get the system defined units with fallbacks.
                if ( function_exists('wc_get_weight_unit') ) {
                    $weight_unit = wc_get_weight_unit();
                } else {
                    $weight_unit = get_option('woocommerce_weight_unit', 'lbs'); // default fallback
                }
    
                if ( function_exists('wc_get_dimension_unit') ) {
                    $dimension_unit = wc_get_dimension_unit();
                } else {
                    $dimension_unit = get_option('woocommerce_dimension_unit', 'in'); // default fallback
                }
    
                // Start output buffer to capture HTML.
                ob_start();
                ?>
                <table class="woocommerce-product-attributes shop_attributes">
                    <tbody>
                        <?php if ( $weight > 0 ) : ?>
                            <tr class="woocommerce-product-attributes-item woocommerce-product-attributes-item--weight">
                                <th class="woocommerce-product-attributes-item__label">Weight</th>
                                <td class="woocommerce-product-attributes-item__value">
                                    <?php echo esc_html($weight); ?> <?php echo esc_html($weight_unit); ?>
                                </td>
                            </tr>
                        <?php endif; ?>
                        <?php if ( $length > 0 || $width > 0 || $height > 0 || $depth > 0 ) : ?>
                            <tr class="woocommerce-product-attributes-item woocommerce-product-attributes-item--dimensions">
                                <th class="woocommerce-product-attributes-item__label">Dimensions</th>
                                <td class="woocommerce-product-attributes-item__value">
                                    <?php if ( $length > 0 ) : ?>
                                        <div><strong>Length:</strong> <?php echo esc_html($length); ?> <?php echo esc_html($dimension_unit); ?></div>
                                    <?php endif; ?>
                                    <?php if ( $width > 0 ) : ?>
                                        <div><strong>Width:</strong> <?php echo esc_html($width); ?> <?php echo esc_html($dimension_unit); ?></div>
                                    <?php endif; ?>
                                    <?php if ( $height > 0 ) : ?>
                                        <div><strong>Height:</strong> <?php echo esc_html($height); ?> <?php echo esc_html($dimension_unit); ?></div>
                                    <?php endif; ?>
                                    <?php if ( $depth > 0 ) : ?>
                                        <div><strong>Depth:</strong> <?php echo esc_html($depth); ?> <?php echo esc_html($dimension_unit); ?></div>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
                <?php
                $dimensions_weight_html = ob_get_clean();
                wp_send_json_success( array( 'dimensions_weight_html' => $dimensions_weight_html ) );
            } else {
                wp_send_json_error();
            }
        } else {
            wp_send_json_error();
        }
    }
    

    // blocking direct access to duplicate products, hidden from api products and add-ons
    function rental_redirect_products_and_variants_to_home_page() {

        if (is_product()) {
            global $post;
            $product_id = $post->ID;

            if (get_post_meta($product_id, '_rental_is_add_on', true)) {
                wp_redirect(home_url());
                exit;
           }
        }
    }
    add_action('template_redirect', 'rental_redirect_products_and_variants_to_home_page');


    // Load custom template for only the sale products page
    function rental_load_sale_products_template($template)
    {

        $is_enabled = get_option('rental_display_sale_products_page', '0');

        if (is_page('rntp-sale-products')) {
            if ($is_enabled == '1') {

                return plugin_dir_path(__FILE__) . 'templates/rntp-sale-template.php';
            } else {

                // Return 404 page if disabled
                global $wp_query;
                $wp_query->set_404();
                status_header(404);
                return get_404_template();
            }
        }

        return $template;
    }
    add_filter('template_include', 'rental_load_sale_products_template');


    add_action('woocommerce_cart_emptied', function () {
        delete_rental_session_data('total_excluded_extra_fees');
        delete_rental_session_data('rental_security_deposit_fee');
        // delete_option('rental_product_subtotal' . get_new_unique_id());

        delete_rental_session_data('rental_product_price_total');
        delete_rental_session_data('rental_product_subtotal');

        // delete_option('rental_payment_tip_id' . get_new_unique_id());
        delete_rental_session_data('rental_payment_tip_id');
        delete_rental_session_data('rental_payment_tip_amount');
        // delete_option('rental_payment_tip_amount' . get_new_unique_id());

        if (isset($_COOKIE['TIP_ID_SELECTED_BY_CUSTOMER' . get_new_unique_id()])) {
            unset($_COOKIE['TIP_ID_SELECTED_BY_CUSTOMER' . get_new_unique_id()]);
            setcookie('TIP_ID_SELECTED_BY_CUSTOMER' . get_new_unique_id(), '', time() - (31556952), "/", "", false, true);
        }

        // product uncertain addons data on a cart process
        if(get_rental_session_data('rental_uncertain_add_ons', [])) {
            delete_rental_session_data('rental_uncertain_add_ons');
        }

        if(get_rental_session_data('rental_referral_source_id', 0)) {
            delete_rental_session_data('rental_referral_source_id');
        }

        if(get_rental_session_data('rental_event_type_title', '')) {
            delete_rental_session_data('rental_event_type_title');
        }

    });

    // exlude js/css files and cookies from being cached
    require_once RENTOPIAN_SYNC_PATH . '/includes/wp-rocket-helper.php';

    // HTTP request timeout global increase
    add_filter('http_request_timeout', function($timeout) {
        return 300; // 5 minutes
    });


    /**
     * filter to hide cart row if it's a set child and hiding is enabled globally or per-set or defined hidden in set meta.
     */
    function rental_filter_cart_item_visible($visible, $cart_item, $cart_item_key) {

        $is_set_item = rental_is_set_child_item($cart_item);

        if (!$is_set_item) {
            return $visible;
        }

        $global_hide_set_items = get_option('rental_hide_set_items', false );
        // If global option enabled -> hide
        if ($global_hide_set_items) {
            return false;
        }

        $parent_set_pid = rental_get_parent_set_product_id($cart_item);

        // If global option is not enabled, still allow per-set meta to hide
        if (!$global_hide_set_items) {

            // If parent found and _rental_hide_items_on_website is true -> hide
            if ( $parent_set_pid && get_post_meta( $parent_set_pid, '_rental_hide_items_on_website', true ) ) {
                return false;
            }
        }
       

       
        // check for specific hidden item entries in set meta
        // If parent set meta marks this specific set item or item's addon as hidden -> hide
        $set_item_product_id = isset( $cart_item['product_id'] ) ? intval( $cart_item['product_id'] ) : 0;
        $set_item_variation_id = isset( $cart_item['variation_id'] ) ? intval( $cart_item['variation_id'] ) : 0;
        if ($parent_set_pid) {
            if ( rental_set_items_mark_hidden($parent_set_pid, $set_item_product_id, $set_item_variation_id ) ) {
                return false;
            }
        }

        // If neither global option nor per-set meta nor explicit hidden flag -> leave visible
        return $visible;
    }
    add_filter( 'woocommerce_cart_item_visible', 'rental_filter_cart_item_visible', 10, 3);
    add_filter( 'woocommerce_widget_cart_item_visible', 'rental_filter_cart_item_visible', 10, 3);
    add_filter( 'woocommerce_checkout_cart_item_visible', 'rental_filter_cart_item_visible', 10, 3);

    /**
     * Cart-badge count helpers. The implementation lives in the Sets
     * module (Rental_Sets_Cart_Visibility) so all hidden-item count logic
     * stays in includes/sets/ and the plugin owns it without any theme
     * code. These remain as thin, backward-compatible wrappers — the
     * `woocommerce_cart_contents_count` filter is registered by the sets
     * bootstrap, not here.
     *
     * @param int $count
     * @return int
     */
    function rental_fix_cart_contents_count($count) {
        return class_exists('Rental_Sets_Cart_Visibility')
            ? Rental_Sets_Cart_Visibility::filter_contents_count($count)
            : $count;
    }

    /**
     * Visibility-aware cart count for theme badges that can't use
     * get_cart_contents_count() (a custom raw-count "items" badge).
     * Delegates to Rental_Sets_Cart_Visibility::visible_count().
     *
     * @param string $mode 'total' or 'items'.
     * @return int
     */
    function rental_get_visible_cart_count( $mode = 'total' ) {
        return class_exists('Rental_Sets_Cart_Visibility')
            ? Rental_Sets_Cart_Visibility::visible_count($mode)
            : 0;
    }

    // enable local file sync / streaming
    if ($rental_api_url === 'http://host.docker.internal/api/v1') {
        add_filter( 'http_request_host_is_external', '__return_true' );
    }

    function rental_bypass_mime_check($data, $file, $filename, $mimes) {

		// If no mime type is determined, try to set one manually.
		if ( empty( $data['type'] ) ) {
			$extension = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
			$mime_types = array(
				'jpg'  => 'image/jpeg',
				'jpeg' => 'image/jpeg',
				'png'  => 'image/png',
				// 				'gif'  => 'image/gif',
				// 				'svg'  => 'image/svg+xml', 
				
			);
			$data['ext'] = $extension;
            
			// Use a known mime type or fallback to a generic type.
			$data['type'] = isset($mime_types[$extension]) ? $mime_types[$extension] : 'application/octet-stream';
			$data['proper_filename'] = $filename;
		}
		return $data;
	}


    function add_rental_overlay_markup() {
        if ( defined('EVENTORIAN_THEME_DIR') ) {
            echo '<div class="overlay"></div>';	
        }
    }
    add_action( 'wp_footer', 'add_rental_overlay_markup' );


    /**
     * Enqueue OwlCarousel core CSS/JS if not already enqueued.
     * 
     */
    function rentpro_enqueue_owlcarousel_core() {
        $version = '2.3.4';

        $handle_css_main  = 'owl-carousel-css';
        $handle_css_theme = 'owl-carousel-theme-css';
        $handle_js = 'owl-carousel-js';

        if ( ! wp_style_is( $handle_css_main, 'enqueued' ) ) {
            wp_enqueue_style(
                $handle_css_main,
                'https://cdnjs.cloudflare.com/ajax/libs/OwlCarousel2/2.3.4/assets/owl.carousel.min.css',
                array(),
                $version
            );
        }

        if ( ! wp_style_is( $handle_css_theme, 'enqueued' ) ) {
            wp_enqueue_style(
                $handle_css_theme,
                'https://cdnjs.cloudflare.com/ajax/libs/OwlCarousel2/2.3.4/assets/owl.theme.default.min.css',
                array( $handle_css_main ),
                $version
            );
        }

        if ( ! wp_script_is( $handle_js, 'enqueued' ) ) {
            wp_enqueue_script(
                $handle_js,
                'https://cdnjs.cloudflare.com/ajax/libs/OwlCarousel2/2.3.4/owl.carousel.min.js',
                array( 'jquery' ),
                $version,
                true
            );
        }
    }
    add_action('wp_enqueue_scripts', 'rentpro_enqueue_owlcarousel_core');


    /**
     * Shortcode: [rental_products_owl_carousel]
     *
     * Displays WooCommerce products inside an OwlCarousel slider with customizable options.
     *
     * Supported Attributes:
     * - per_page (int)     : Number of products to display. Default is 8.
     * - orderby (string)   : Field to sort by. Allowed values: 'date', 'ID', 'title', 'menu_order', 'rand'. Default is 'date'.
     * - order (string)     : Sorting direction. Allowed values: 'ASC', 'DESC'. Default is 'DESC'.
     * - category (string)  : Filter by product categories (slug or comma-separated slugs). Optional.
     * - tags (string)      : Filter by product tags (slug or comma-separated slugs). Optional.
     *
     * Requirements:
     * - OwlCarousel JS/CSS must be enqueued separately in your theme or plugin.
     *
     * Example Usage:
     * [rental_products_owl_carousel]
     * [rental_products_owl_carousel per_page="6"]
     * [rental_products_owl_carousel orderby="title" order="ASC"]
     * [rental_products_owl_carousel category="hats,shoes"]
     * [rental_products_owl_carousel tags="summer,new"]
     * [rental_products_owl_carousel category="hats" tags="sale,clearance"]
     *
     * @param array $atts Shortcode attributes.
     * @return string HTML output containing the OwlCarousel of products.
     */
    function rental_products_owl_carousel_shortcode($atts) {

        // Default attributes: per_page, orderby, order, category, tags
        $atts = shortcode_atts([
            'per_page' => 8,
            'orderby'  => 'date',   // default: latest by date
            'order'    => 'DESC',   // default descending
            'category' => '',       // slug or comma-separated slugs
            'tags'     => '',       // slug or comma-separated slugs
        ], $atts, 'rental_products_owl_carousel');

        // per_page sanitization
        $per_page = intval($atts['per_page']);
        if ($per_page <= 0) {
            $per_page = 8;
        }

        // Allowed orderby values for safety
        $allowed_orderbys = ['date', 'ID', 'title', 'menu_order', 'rand'];
        $orderby = in_array($atts['orderby'], $allowed_orderbys, true) ? $atts['orderby'] : 'date';

        // Allowed order directions
        $order = strtoupper($atts['order']) === 'ASC' ? 'ASC' : 'DESC';

        // Build base query args
        $args = [
            'post_type'           => 'product',
            'posts_per_page'      => $per_page,
            'orderby'             => $orderby,
            'order'               => $order,
            'post_status'         => 'publish',
            'ignore_sticky_posts' => 1,
        ];

        // Prepare tax_query for category and tags
        $tax_query = [];

        // Category filter
        if (!empty($atts['category'])) {
            $slugs = array_filter(array_map('trim', explode(',', $atts['category'])));
            if (!empty($slugs)) {
                // sanitize each slug
                $slugs = array_map('sanitize_text_field', $slugs);
                $tax_query[] = [
                    'taxonomy' => 'product_cat',
                    'field'    => 'slug',
                    'terms'    => $slugs,
                    // 'operator' can be 'IN' by default (matches any of the slugs)
                ];
            }
        }

        // Tags filter
        if (!empty($atts['tags'])) {
            $tag_slugs = array_filter(array_map('trim', explode(',', $atts['tags'])));
            if (!empty($tag_slugs)) {
                // sanitize each slug
                $tag_slugs = array_map('sanitize_text_field', $tag_slugs);
                $tax_query[] = [
                    'taxonomy' => 'product_tag',
                    'field'    => 'slug',
                    'terms'    => $tag_slugs,
                    // 'operator' default 'IN'
                ];
            }
        }

        if (!empty($tax_query)) {
            // If both category and tags present, ensure they are combined with AND
            if (count($tax_query) > 1) {
                $args['tax_query'] = array_merge([ 'relation' => 'AND' ], $tax_query);
            } else {
                $args['tax_query'] = $tax_query;
            }
        }

        $query = new WP_Query($args);
        if (!$query->have_posts()) {
            return '<p>No products found.</p>';
        }

        // Unique carousel ID
        $carousel_id = 'products-carousel-owl-' . uniqid();

        ob_start();
        ?>
        <div id="<?php echo esc_attr($carousel_id); ?>" class="products-carousel-owl owl-carousel">
            <?php
            while ($query->have_posts()) {
                $query->the_post();
                global $product;
                $product = wc_get_product(get_the_ID());
                if (!$product || !$product->is_visible()) {
                    continue;
                }
                ?>
                <div class="item">
                    <a href="<?php the_permalink(); ?>" class="owl-product-link">
                        <div class="owl-image-wrapper">
                            <?php echo woocommerce_get_product_thumbnail(); ?>
                        </div>
                        <h3 class="owl-product-title"><?php the_title(); ?></h3>
                        <span class="owl-product-price"><?php echo $product->get_price_html(); ?></span>
                    </a>
                </div>
                <?php
            }
            wp_reset_postdata();
            ?>
        </div>
        <script type="text/javascript">
        jQuery(document).ready(function($){
            var $owl = $('#<?php echo esc_js($carousel_id); ?>');
            if ($owl.length && typeof $owl.owlCarousel === 'function') {
                $owl.owlCarousel({
                    loop: true,
                    margin: 10,
                    nav: true,
                    dots: true,
                    responsive: {
                        0:   { items: 1 },
                        576: { items: 2 },
                        768: { items: 3 },
                        992: { items: 4 }
                    }
                });
            }
        });
        </script>
        <style>
            /* Basic styling; override via Additional CSS if needed */
            #<?php echo esc_attr($carousel_id); ?> .item {
                text-align: center;
                padding: 10px;
                box-sizing: border-box;
            }
            #<?php echo esc_attr($carousel_id); ?> .owl-product-link {
                display: block;
                text-decoration: none;
                color: inherit;
            }
            #<?php echo esc_attr($carousel_id); ?> .owl-product-title {
                font-size: 1rem;
                margin: 0.5em 0 0.2em;
            }
            #<?php echo esc_attr($carousel_id); ?> .owl-product-price {
                font-size: 0.9rem;
                color: #333;
            }
            #<?php echo esc_attr($carousel_id); ?> .owl-product-link img {
                max-width: 100%;
                height: auto;
                display: block;
                margin: 0 auto;
            }

            #<?php echo esc_attr($carousel_id); ?> .owl-stage-outer {
                padding-top: 1rem;
            }

            /* Fixed-height wrapper: all items same container height */
            .owl-image-wrapper {
                height: 200px;            /* choose desired uniform height */
                display: flex;
                align-items: center;
                justify-content: center;
                overflow: hidden;
                background: #fff;         /* optional background */
            }
            /* Ensure image scales down if taller, but not cropped: */
            .owl-image-wrapper img {
                max-height: 100%;
                width: auto;
                display: block;
            }

            /* 1. Ensure the carousel container shows dots */
            .products-carousel-owl .owl-dots {
                display: block !important;
                text-align: center;
                margin-top: 15px;
            }
            .products-carousel-owl .owl-dots .owl-dot {
                display: inline-block;
                margin: 0 5px;
            }
            .products-carousel-owl .owl-dots .owl-dot span {
                width: 10px;
                height: 10px;
                background: #ccc;
                display: block;
                border-radius: 50%;
                transition: background-color 0.3s;
            }
            .products-carousel-owl .owl-dots .owl-dot.active span {
                background: #0073aa;
            }
            .products-carousel-owl .owl-dots .owl-dot:hover span {
                background: #555;
            }

            /* 2. Border and hover effect on each item */
            .products-carousel-owl .item {
                border: 1px solid #e5e5e5;
                border-radius: 8px;
                overflow: hidden;
                transition: transform 0.3s, box-shadow 0.3s, border-color 0.3s;
                background: #fff;
            }
            .products-carousel-owl .item:hover {
                transform: translateY(-5px);
                box-shadow: 0 4px 15px rgba(0,0,0,0.1);
                border-color: #0073aa;
            }

            /* 3. Make sure images don’t overflow */
            .products-carousel-owl .owl-product-link img {
                max-width: 100%;
                height: auto;
                display: block;
                margin: 0 auto;
            }

            /* 4. Optional: style nav arrows */
            .products-carousel-owl .owl-nav button.owl-prev,
            .products-carousel-owl .owl-nav button.owl-next {
                background: rgba(0,0,0,0.5);
                color: #fff;
                border: none;
                border-radius: 50%;
                width: 30px;
                height: 30px;
                position: absolute;
                top: 50%;
                transform: translateY(-50%);
                opacity: 0.7;
                transition: opacity 0.3s, background 0.3s;
            }
            .products-carousel-owl .owl-nav button.owl-prev:hover,
            .products-carousel-owl .owl-nav button.owl-next:hover {
                opacity: 1;
                background: rgba(0,0,0,0.8);
            }
            /* Position arrows */
            .products-carousel-owl .owl-nav button.owl-prev { left: 5px; }
            .products-carousel-owl .owl-nav button.owl-next { right: 5px; }
            .products-carousel-owl .owl-product-link:hover .owl-product-title {
                color: #0073aa;
            }

            .products-owl-carousel-header {
                text-align: center;
                /* font-weight: bold; */
            }
        </style>
        <?php
        return ob_get_clean();
    }
    add_shortcode('rental_products_owl_carousel', 'rental_products_owl_carousel_shortcode');


    // Build the tree, with an optional limit on top-level cats
    // Main callback with working limit logic
    function rental_render_cat_nav( $atts ) {
        // pull limit (0 = no limit)
        $atts  = shortcode_atts( ['limit' => 0], $atts, 'rental_cat_tree_nav' );
        $limit = absint( $atts['limit'] );

        // get ALL top-level cats, then re-index numerically
        $all = get_terms([
            'taxonomy'   => 'product_cat',
            'hide_empty' => true,
            'parent'     => 0,
        ]);
        if ( is_wp_error($all) || empty($all) ) {
            return '<p>No categories found.</p>';
        }

        if ( $limit > 0 ) {
            // initial = first $limit cats
            $initial  = get_terms([
                'taxonomy'   => 'product_cat',
                'hide_empty' => true,
                'parent'     => 0,
                'number'     => $limit,
            ]);
            // overflow = the rest, starting at offset $limit
            $overflow = get_terms([
                'taxonomy'   => 'product_cat',
                'hide_empty' => true,
                'parent'     => 0,
                'offset'     => $limit,
            ]);
        } else {
            // no limit = everything
            $initial  = $all;
            $overflow = [];
        }

        $all = array_values( $all ); // ensure 0,1,2... keys

        // split into initial vs overflow
        if ( $limit > 0 && count($all) > $limit ) {
            $initial  = array_slice( $all, 0, $limit );
            $overflow = array_slice( $all, $limit );
        } else {
            $initial  = $all;
            $overflow = [];
        }

        // render initial
        $html  = '<div class="rentpro-cat-nav">';
        $html .= rental_cat_tree_nav_list( $initial );

        // overflow + show-more button
        if ( ! empty($overflow) ) {
            $html .= '<div class="overflow-cats" style="display:none;">'
                . rental_cat_tree_nav_list( $overflow )
                . '</div>';
            $html .= '<button type="button" class="show-more">Show more…</button>';
        }
        $html .= '</div>';

        // CSS + JS (same expand/collapse logic)
        $html .= <<<HTML
            <style>
                .rentpro-cat-nav ul { list-style:none; margin:0; padding-left:1em; }
                .rentpro-cat-nav ul ul { padding-left:2em; }
                .rentpro-cat-nav .collapsed > ul { display:none; }
                .rentpro-cat-nav .toggle { cursor:pointer; font-weight:bold; margin-right:.3em; }
                .show-more { display:block; margin:.5em 0; background:none; border:1px solid #ccc; padding:.3em .6em; cursor:pointer; color: #000; }
            </style>
            <script>
                document.addEventListener('DOMContentLoaded', function(){
                    // expand/collapse
                    document.querySelectorAll('.rentpro-cat-nav .has-children').forEach(function(li){
                        var btn = document.createElement('span');
                        btn.textContent = '[-]';
                        btn.className = 'toggle';
                        li.insertBefore(btn, li.firstChild);
                        btn.addEventListener('click', function(){
                            li.classList.toggle('collapsed');
                            btn.textContent = li.classList.contains('collapsed') ? '[+]' : '[-]';
                        });
                    });
                    // show/hide overflow
                    document.querySelectorAll('.rentpro-cat-nav').forEach(function(nav){
                        var moreBtn  = nav.querySelector('.show-more');
                        var overflow = nav.querySelector('.overflow-cats');
                        if ( moreBtn && overflow ) {
                            moreBtn.addEventListener('click', function(){
                            if ( overflow.style.display === 'none' ) {
                                overflow.style.display   = 'block';
                                moreBtn.textContent      = 'Show less…';
                            } else {
                                overflow.style.display   = 'none';
                                moreBtn.textContent      = 'Show more…';
                            }
                            });
                        }
                    });
                });
            </script>
        HTML;

        return $html;
    }

    // Recursive helper
    function rental_cat_tree_nav_list( $terms ) {
        $out = '<ul>';
        foreach ( $terms as $term ) {
            // rename “uncategorized”
            if ( strtolower($term->slug) === 'uncategorized' ) {
                $name = 'All Products';
                $link = get_post_type_archive_link('product');
            } else {
                $name = esc_html($term->name);
                $link = get_term_link($term);
            }
            if ( is_wp_error($link) ) continue;

            $children = get_terms([
                'taxonomy'   => 'product_cat',
                'hide_empty' => true,
                'parent'     => $term->term_id,
            ]);

            $cls = ! empty($children) ? 'has-children' : '';
            $out .= "<li class=\"{$cls}\">";
            $out .= "<a href=\"".esc_url($link)."\">{$name}</a>";

            if ( $children ) {
                $out .= rental_cat_tree_nav_list( $children );
            }
            $out .= '</li>';
        }
        $out .= '</ul>';
        return $out;
    }
    // Register the shortcode [rental_cat_tree_nav limit="5"]
    add_shortcode('rental_cat_tree_nav', 'rental_render_cat_nav');


    // rentpro specific popup
    function rental_enqueue_search_popup_assets() {
        // the css part must always load to hide the popup when necessary
        wp_enqueue_style('rental-search-popup-css', plugins_url('/assets/css/rental-search-popup.css', __FILE__), [], RENTOPIAN_SYNC_VERSION);

        if (class_exists('Rentpro_THA')) {
            wp_enqueue_script('rental-search-popup-js', plugins_url('/assets/js/rental-search-popup.js', __FILE__), ['jquery'], RENTOPIAN_SYNC_VERSION, true);
        }
    }
    add_action( 'wp_enqueue_scripts', 'rental_enqueue_search_popup_assets' );

    function rental_render_search_popup() {
        ?>
        <div id="rental-plugin-search-popup" class="rental-plugin-search-popup" aria-hidden="true">
            <div class="rental-plugin-popup-overlay"></div>
            <div class="rental-plugin-popup-inner">
                <button type="button" class="rental-plugin-popup-close" aria-label="<?php esc_attr_e( 'Close search', 'rental-plugin-textdomain' ); ?>">
                    &times;
                </button>
                <form role="search" method="get" action="<?php echo esc_url( home_url( '/' ) ); ?>" class="rental-plugin-search-form">
                    <input
                        type="search"
                        name="s"
                        class="rental-plugin-search-input"
                        placeholder="<?php esc_attr_e( 'Search...', 'rental-plugin-textdomain' ); ?>"
                        value="<?php echo get_search_query(); ?>"
                        aria-label="<?php esc_attr_e( 'Search', 'rental-plugin-textdomain' ); ?>"
                    />
                    <button type="submit" class="rental-plugin-search-submit">
                        <?php esc_html_e( 'Search', 'rental-plugin-textdomain' ); ?>
                    </button>
                </form>
            </div>
        </div>
        <?php
    }
    add_action( 'wp_footer', 'rental_render_search_popup' );


    // hidding the delivery debug mode notice
    function rental_hide_customer_matched_zone_notice( $message ) {
		// strip HTML and trim whitespace
		$text = trim( wp_strip_all_tags( $message ) );

		if ( false !== strpos( $text, 'Customer matched zone' ) ) {
			return '';
		}

		return $message;
	}
	add_filter( 'woocommerce_add_message', 'rental_hide_customer_matched_zone_notice', 10, 1 );

    function rentpro_render_popup_fly_cart() {
        if ( 
            (is_cart() 
            || is_checkout())
            && get_option('rental_form_layout') === "in-cart"
        ) {
            get_template_part( 'template-parts/popup-fly-cart' );
        }
    }
    add_action( 'wp_footer', 'rentpro_render_popup_fly_cart', 5 );


    add_action( 'wp_enqueue_scripts', 'rental_eventorian_remove_search_post_type_js' );
    function rental_eventorian_remove_search_post_type_js() {
        if ( is_admin() ) {
            return;
        }

        // Check the active theme. get_template() returns the parent theme folder (useful if a child theme is active).
        $theme = wp_get_theme();
        $template_slug   = $theme->get_template();   // parent theme folder name
        $stylesheet_slug = $theme->get_stylesheet(); // active theme folder name (child or parent)

        // theme folder slug
        $target_slug = 'eventorian';

        if ( $template_slug !== $target_slug && $stylesheet_slug !== $target_slug ) {
            // Not Eventorian (nor a child of Eventorian) — do nothing.
            return;
        }

        // The inline JS to remove the hidden post_type inside the WP search block wrapper
        $script = <<<'JS'
                document.addEventListener('DOMContentLoaded', function(){
                document.querySelectorAll('form .wp-block-search__inside-wrapper input[name="post_type"]').forEach(function(el){
                    el.remove();
                });
            });
        JS;

        // Register an (empty) script handle, enqueue it, then attach inline script.
        // Using a dedicated handle avoids depending on jQuery being present.
        wp_register_script( 'rsp-eventorian-remove-search-posttype', '' );
        wp_enqueue_script( 'rsp-eventorian-remove-search-posttype' );
        wp_add_inline_script( 'rsp-eventorian-remove-search-posttype', $script );
    }


    function rental_render_modern_date_form($checkout) {
        if (
            get_option('rental_dates_on_checkout', 0) != 1 
            || get_option('rental_allow_overbook', 1) != 1 
            || get_option('rental_checkout_layout_mode', 'classic') !== 'modern'
        ) {
            return;
        }

        // Adjust the path to match your plugin structure
        // $file = plugin_dir_path(__FILE__) . 'components/rental_date_from_modern.php';
        $file = RENTOPIAN_SYNC_PATH . '/components/rental_date_from_modern.php';
        if (file_exists($file)) {
            include $file;
        }
    }
    add_action('woocommerce_after_checkout_billing_form', 'rental_render_modern_date_form', 5);
    

} else {
// adding notices
    function rental_admin_notice() {
        ?>
        <div class="error">
            <p><?php _e('Please download and Install WooCommerce plugin to be able to use Rentopian Sync.', 'rentopian-sync'); ?></p>
        </div>
        <?php
    }
    add_action('admin_notices', 'rental_admin_notice');
}
