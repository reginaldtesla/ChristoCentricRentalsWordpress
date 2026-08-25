<?php
namespace WP_Rocket\Helpers\wproptions\toggle_options_under_some_conditions;
namespace WP_Rocket\Helpers\cache\no_cache_for_page;
namespace WP_Rocket\Helpers\cache\dynamic_cookie;


if ( function_exists( 'is_plugin_active' ) && is_plugin_active( 'wp-rocket/wp-rocket.php' ) ) {

    //  Define cookie ID for dynamic caches.
    function cache_dynamic_cookie( array $cookies ) {
        $cookies[] = 'rental_start_date';
        $cookies[] = 'rental_end_date';
        // $cookies[] = 'product_data';
        $cookies[] = 'rental_division_id';
        $cookies[] = 'rental_zip';
        $cookies[] = 'rental_products_img_last_id';
        $cookies[] = 'rental_products_img_count';
        $cookies[] = 'rental_last_selected_product';
        $cookies[] = 'rental_product_added_to_cart_message';
        $cookies[] = 'rental_taxes';
        $cookies[] = 'rental_exempt_waiver';
        $cookies[] = 'rental_pick_up';
        $cookies[] = 'event_time';
        return $cookies;
    }

    // Add cookie ID to cookkies for dynamic caches.
    add_filter( 'rocket_cache_dynamic_cookies', __NAMESPACE__ . '\cache_dynamic_cookie' );

    // Remove .htaccess-based rewrites, since we need to detect the cookie,
    // which happens in inc/front/process.php.
    add_filter( 'rocket_htaccess_mod_rewrite', '__return_false' );


    /**
     * Updates .htaccess, regenerates WP Rocket config file.
     */
    function flush_wp_rocket() {

        if ( ! function_exists( 'flush_rocket_htaccess' )
        || ! function_exists( 'rocket_generate_config_file' ) ) {
            return false;
        }

        // Update WP Rocket .htaccess rules.
        \flush_rocket_htaccess();

        // Regenerate WP Rocket config file.
        \rocket_generate_config_file();
    }

    /**
     * Add customizations, updates .htaccess, regenerates config file.
     */
    function activate() {

        // Add customizations upon activation.
        add_filter( 'rocket_htaccess_mod_rewrite', '__return_false' );
        add_filter( 'rocket_cache_dynamic_cookies', __NAMESPACE__ . '\cache_dynamic_cookie' );

        // Flush .htaccess rules, and regenerate WP Rocket config file.
        flush_wp_rocket();
    }
    register_activation_hook( __FILE__, __NAMESPACE__ . '\activate' );

    /**
     * Removes customizations, updates .htaccess, regenerates config file.
     */
    function deactivate() {

        // Remove customizations upon deactivation.
        remove_filter( 'rocket_htaccess_mod_rewrite', '__return_false' );
        remove_filter( 'rocket_cache_dynamic_cookies', __NAMESPACE__ . '\cache_dynamic_cookie' );

        // Flush .htaccess rules, and regenerate WP Rocket config file.
        flush_wp_rocket();
    }
    register_deactivation_hook( __FILE__, __NAMESPACE__ . '\deactivate' );


    function toggle_options() {

        // if it was any of woocommerce pages
        if ( function_exists( 'is_woocommerce' ) && is_woocommerce() ) {
            
            // Activate WP Rocket options below
            add_filter( 'pre_get_rocket_option_exclude_css', '__return_true' );
            add_filter( 'pre_get_rocket_option_exclude_defer_js', '__return_true' );
            add_filter( 'pre_get_rocket_option_exclude_inline_js', '__return_true' );
            add_filter( 'pre_get_rocket_option_exclude_js', '__return_true' );
            // add_filter( 'pre_get_rocket_option_exclude_lazyload', '__return_true' );
            add_filter( 'pre_get_rocket_option_cache_reject_cookies', '__return_true' );

            // Deactivate WP Rocket options below
            // js
            add_filter( 'pre_get_rocket_option_defer_all_js', '__return_zero' );
            add_filter( 'pre_get_rocket_option_delay_js', '__return_zero' );
            // retired
            // add_filter( 'pre_get_rocket_option_minify_concatenate_js', '__return_zero' );
            add_filter( 'pre_get_rocket_option_minify_js', '__return_zero' );
            add_filter( 'pre_get_rocket_option_minify_js_key', '__return_zero' );
            // add_filter( 'pre_get_rocket_option_delay_js_exclusions', '__return_zero' );
            // css
            // retired
            // add_filter( 'pre_get_rocket_option_minify_concatenate_css', '__return_zero' );
            add_filter( 'pre_get_rocket_option_minify_css', '__return_zero' );
            add_filter( 'pre_get_rocket_option_minify_css_key', '__return_zero' );
            add_filter( 'pre_get_rocket_option_remove_unused_css', '__return_zero' );
            add_filter( 'pre_get_rocket_option_remove_unused_css_safelist', '__return_zero' );
            add_filter( 'pre_get_rocket_option_async_css', '__return_zero' );
            add_filter( 'pre_get_rocket_option_async_css_mobile', '__return_zero' );
            
            // here is the full list of all available filters: https://docs.wp-rocket.me/article/1564-list-of-pre-get-rocket-option-filters
        }
    }
    add_action( 'wp', __NAMESPACE__ . '\toggle_options' );
        
    /**
     * Cleans entire cache on activation and deactivation
     */
    function clean_domain() {
        
        if ( ! function_exists( 'rocket_clean_domain' ) ) {
            return false;
        }
        
        // Purge entire cache
        \rocket_clean_domain();
    }
    register_activation_hook( __FILE__, __NAMESPACE__ . '\clean_domain' );
    register_deactivation_hook( __FILE__, __NAMESPACE__ . '\clean_domain' );
}