<?php
/**
 * Product Options module bootstrap.
 *
 * The single require target for the product-options module, mirroring
 * includes/sets/bootstrap.php. Loads the classes in dependency order and is
 * the only place the module registers hooks outside a class's own boot().
 *
 * Load order matters: the defaults resolver and the repository are read by the
 * selection resolver, which the validation context reads, which the rules read,
 * which the orchestrator runs.
 *
 * What the module owns:
 *   - resolving which option value is selected, from one precedence
 *   - deciding whether an add-to-cart may proceed
 *   - keeping the two session stores and the cart line in agreement
 *   - the shop-loop behaviour for products whose options need a decision
 *
 * What it deliberately leaves alone: option display in cart, checkout and
 * order emails (Rental_Product_Options_Manager and
 * Rental_Options_WC_Integration), and set options on the modern layout
 * (the sets module runs its own rule pipeline).
 *
 * @package RentopianSync\ProductOptions
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! defined( 'RENTAL_PRODUCT_OPTIONS_PATH' ) ) {
    define( 'RENTAL_PRODUCT_OPTIONS_PATH', plugin_dir_path( __FILE__ ) );
}

// 1. Foundation — no dependencies.
require_once RENTAL_PRODUCT_OPTIONS_PATH . 'class-options-logger.php';
require_once RENTAL_PRODUCT_OPTIONS_PATH . 'class-options-defaults.php';
require_once RENTAL_PRODUCT_OPTIONS_PATH . 'class-options-repository.php';

// 2. Selection resolution — reads 1.
require_once RENTAL_PRODUCT_OPTIONS_PATH . 'class-options-selection.php';

// 3. Validation — result, context, rule contract, rules, orchestrator.
require_once RENTAL_PRODUCT_OPTIONS_PATH . 'validation/class-options-validation-result.php';
require_once RENTAL_PRODUCT_OPTIONS_PATH . 'validation/class-options-validator-context.php';
require_once RENTAL_PRODUCT_OPTIONS_PATH . 'validation/class-options-validation-rule.php';
require_once RENTAL_PRODUCT_OPTIONS_PATH . 'validation/class-options-rule-required.php';
require_once RENTAL_PRODUCT_OPTIONS_PATH . 'validation/class-options-cart-validator.php';

// 4. Presentation helpers — read 1-3.
require_once RENTAL_PRODUCT_OPTIONS_PATH . 'class-options-prefill.php';
require_once RENTAL_PRODUCT_OPTIONS_PATH . 'class-options-archive-gate.php';
require_once RENTAL_PRODUCT_OPTIONS_PATH . 'class-options-assets.php';

// 5. Backward-compatible procedural entry points.
require_once RENTAL_PRODUCT_OPTIONS_PATH . 'compat-functions.php';

/**
 * Register the module once WooCommerce and the option helpers are available.
 *
 * @return void
 */
function rental_product_options_module_register() {
    Rental_Options_Cart_Validator::register();
    Rental_Options_Archive_Gate::register();
    Rental_Options_Assets::register();
}
add_action( 'init', 'rental_product_options_module_register', 5 );

/**
 * Record the final verdict of an add-to-cart together with the notice the
 * customer actually saw.
 *
 * A blocked add is otherwise only visible as a message on screen, which makes
 * a report like "it says I must choose an option that is already chosen"
 * impossible to trace. This runs last so it sees every notice.
 *
 * @param bool $passed
 * @param int  $product_id
 * @return bool
 */
function rental_product_options_trace_verdict( $passed, $product_id, $quantity = 1, $variation_id = 0 ) {
    if ( ! Rental_Options_Cart_Validator::owns( $product_id, $variation_id ) ) {
        return $passed;
    }

    $actual_id = $variation_id ? (int) $variation_id : (int) $product_id;
    if ( ! Rental_Options_Repository::get( $actual_id, Rental_Options_Repository::is_set( $actual_id, (int) $product_id ) ) ) {
        return $passed;
    }

    // Whoever rejected this add, the customer keeps their option picks. Their
    // next view of the product page re-fills them instead of starting over.
    if ( ! $passed ) {
        Rental_Options_Cart_Validator::remember_submission( $product_id, $variation_id );
    }

    if ( ! function_exists( 'rental_options_trace' ) || ! function_exists( 'wc_get_notices' ) ) {
        return $passed;
    }

    $notices  = wc_get_notices( 'error' );
    $messages = array();
    foreach ( (array) $notices as $notice ) {
        $messages[] = is_array( $notice ) && isset( $notice['notice'] )
            ? wp_strip_all_tags( $notice['notice'] )
            : wp_strip_all_tags( (string) $notice );
    }

    rental_options_trace( 'add_verdict', array(
        'pid'      => $actual_id,
        'passed'   => $passed ? 1 : 0,
        'notices'  => empty( $messages ) ? 'none' : $messages,
    ) );

    return $passed;
}
add_filter( 'woocommerce_add_to_cart_validation', 'rental_product_options_trace_verdict', 999, 4 );
