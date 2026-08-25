<?php
/**
 * Rental_Options_Assets
 *
 * Registers the module's own script and stylesheet, the way the sets module
 * does. Both used to be scattered — the script enqueued from functions.php and
 * the rules mixed into the global stylesheet — so the module could not be
 * reasoned about or disabled as a unit.
 *
 * The script handle is unchanged so anything that declared a dependency on it
 * keeps resolving.
 *
 * @package RentopianSync\ProductOptions
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'Rental_Options_Assets', false ) ) :

class Rental_Options_Assets {

    const SCRIPT_HANDLE = 'rental-product-options-script';
    const STYLE_HANDLE  = 'rental-product-options-style';

    /** @var self|null */
    protected static $instance = null;

    /**
     * @return void
     */
    public static function register() {
        if ( null !== self::$instance ) {
            return;
        }
        self::$instance = new self();
        self::$instance->boot();
    }

    /**
     * @return void
     */
    protected function boot() {
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ), 20 );
    }

    /**
     * @return void
     */
    public function enqueue() {
        // Match the condition the option markup itself renders under, so a
        // site with the sync switched off does not ship the script.
        if ( ! Rental_Options_Repository::module_is_active() ) {
            return;
        }

        $version = defined( 'RENTOPIAN_SYNC_VERSION' ) ? RENTOPIAN_SYNC_VERSION : null;
        $base    = plugins_url( 'assets/', __FILE__ );

        wp_enqueue_style( self::STYLE_HANDLE, $base . 'css/product-options.css', array(), $version );

        wp_enqueue_script( self::SCRIPT_HANDLE, $base . 'js/product-options.js', array( 'jquery' ), $version, true );

        wp_localize_script( self::SCRIPT_HANDLE, 'rentalObj', array(
            'url'            => admin_url( 'admin-ajax.php' ),
            'optionsStrings' => array(
                'chooseOptions'   => __( 'Please choose: %s', 'rentopian-sync' ),
                'chooseAnyOption' => __( 'Please choose an option above.', 'rentopian-sync' ),
                'stillLoading'    => __( 'One moment — loading the options for this product.', 'rentopian-sync' ),
                'loadFailed'      => __( 'The options for this product could not be loaded. Please reload the page and try again.', 'rentopian-sync' ),
            ),
        ) );

        // The option selects and the order-options row are built in the browser
        // from the raw option prices, so the price gate has to travel with them.
        if ( function_exists( 'rental_price_visibility_script_data' ) ) {
            wp_localize_script( self::SCRIPT_HANDLE, 'rentalPriceVisibility', rental_price_visibility_script_data() );
        }

        wp_localize_script( self::SCRIPT_HANDLE, 'rentalProductOptions', $this->page_data() );
    }

    /**
     * Per-page data the renderer needs before it can ask for anything.
     *
     * `parentId` lets a variable product load its options on paint instead of
     * waiting for every attribute to be chosen — options are attached to the
     * parent, and a variation resolves to the same set.
     *
     * `variationOverrides` lists the variations that declare options of their
     * own. It is almost always empty, and when it is the renderer never
     * re-fetches on a variation change.
     *
     * @return array
     */
    protected function page_data() {
        $data = array(
            'parentId'           => 0,
            'variationOverrides' => array(),
        );

        if ( ! function_exists( 'is_product' ) || ! is_product() ) {
            return $data;
        }

        $product_id = get_queried_object_id();
        if ( ! $product_id ) {
            return $data;
        }

        $data['parentId']           = (int) $product_id;
        $data['variationOverrides'] = self::variation_overrides( $product_id );

        return $data;
    }

    /**
     * Variation ids of a product that carry their own option ids.
     *
     * @param int $product_id
     * @return array<int>
     */
    public static function variation_overrides( $product_id ) {
        global $wpdb;

        $product_id = (int) $product_id;
        if ( ! $product_id ) {
            return array();
        }

        $ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT pm.post_id
               FROM {$wpdb->postmeta} pm
               JOIN {$wpdb->posts} p ON p.ID = pm.post_id AND p.post_type = 'product_variation'
              WHERE p.post_parent = %d
                AND pm.meta_key = '_product_options'
                AND pm.meta_value NOT IN ('', '[]')",
            $product_id
        ) );

        return array_map( 'intval', (array) $ids );
    }
}

endif;
