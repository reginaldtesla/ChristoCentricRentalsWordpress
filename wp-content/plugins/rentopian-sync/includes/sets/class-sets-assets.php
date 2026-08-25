<?php
/**
 * Rental_Sets_Assets
 *
 * Owns front-end asset registration for the Sets module:
 * the `rental-sets` script and `rental-sets-style` stylesheet
 * that drive the variant-picker modal, the set price recalculation
 * and the set items table.
 *
 * Why it lives here:
 * Historically the script was enqueued from `functions.php` with no
 * direct relationship to the rest of the Sets module. Centralising
 * registration inside the module keeps every concern about the Set
 * entity in one place — markup, JS, CSS, PHP services and admin
 * settings — so future changes only have to touch one folder.
 *
 * Backward compatibility:
 *   - The script handle (`rental-sets`) is preserved, so any third-party
 *     code calling `wp_dequeue_script('rental-sets')` keeps working.
 *   - The localized object name (`rentalObj`) and shape are preserved.
 *   - Enqueue conditions match the previous behaviour: front-end only,
 *     on a single product page (`is_product()`).
 *
 * @package RentopianSync\Sets
 * @since   2.14.7
 *
 * PHP requirement: 7.4+
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'Rental_Sets_Assets', false ) ) :

class Rental_Sets_Assets {

    /**
     * Script handle used by `wp_enqueue_script` / `wp_dequeue_script`.
     *
     * @var string
     */
    const SCRIPT_HANDLE = 'rental-sets';

    /**
     * Style handle used by `wp_enqueue_style` / `wp_dequeue_style`.
     *
     * @var string
     */
    const STYLE_HANDLE = 'rental-sets-style';

    /**
     * Wire WordPress hooks. Idempotent: callers can invoke this from
     * the bootstrap without worrying about double-registration in
     * scenarios where the bootstrap is loaded twice (e.g. tests).
     *
     * @return void
     */
    public static function register_hooks() {
        if ( did_action( 'rntp_sets_assets_registered' ) ) {
            return;
        }
        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
        do_action( 'rntp_sets_assets_registered' );
    }

    /**
     * Enqueue Sets module assets on the front-end.
     *
     * Mirrors the legacy guard from `functions.php`: only on single
     * product pages, never in admin context. The handle and the
     * `rentalObj` localized var are preserved for backward compatibility.
     *
     * @return void
     */
    public static function enqueue() {
        if ( is_admin() ) {
            return;
        }

        if ( ! function_exists( 'is_product' ) || ! is_product() ) {
            return;
        }

        $base_url = self::base_url();
        $version  = defined( 'RENTOPIAN_SYNC_VERSION' ) ? RENTOPIAN_SYNC_VERSION : null;

        wp_enqueue_style(
            self::STYLE_HANDLE,
            $base_url . 'includes/sets/assets/css/rental-sets.css',
            array( 'rental-style' ),
            $version
        );

        wp_enqueue_script(
            self::SCRIPT_HANDLE,
            $base_url . 'includes/sets/assets/js/rental-sets.js',
            array( 'jquery' ),
            $version,
            true
        );

        wp_localize_script(
            self::SCRIPT_HANDLE,
            'rentalObj',
            array(
                'url' => admin_url( 'admin-ajax.php' ),
            )
        );
    }

    /**
     * Plugin base URL with a trailing slash. Resolved through the
     * canonical entry-point so it works regardless of where the file
     * structure is deployed.
     *
     * @return string
     */
    protected static function base_url() {
        if ( defined( 'RENTOPIAN_SYNC_PATH' ) ) {
            return plugin_dir_url( RENTOPIAN_SYNC_PATH . '/rentopian-sync.php' );
        }
        // Defensive fallback — module file -> plugin root.
        return plugin_dir_url( dirname( dirname( __DIR__ ) ) . '/rentopian-sync.php' );
    }
}

endif;
