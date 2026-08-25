<?php
/**
 * Sets Module — Bootstrap
 *
 * Loads every class the sets subsystem owns in dependency order, then
 * wires up WordPress hook registration and the backward-compatible
 * function aliases. The main plugin file only ever needs to require
 * this single bootstrap; everything else is pulled in from here.
 *
 * Load groups, in order:
 *
 *   1. Foundation models (RTOptionsBase, RTSetOptions) — re-used by the
 *      options repository for single-option webhook operations.
 *   2. Sync-side classes (entity 1+2 ingest pipeline) — drivers for the
 *      Laravel → WP product/postmeta sync, including the group-builder
 *      that the sync processor instantiates per-set.
 *   3. Cart pipeline classes (composite-group provenance on cart lines,
 *      including the post-add reconciler that drops stale children).
 *   4. Renderer (section presenter + server orchestrator).
 *   5. Cart / order display surfaces.
 *   6. Webhook shape defense and price recalculation services.
 *   7. Validation pipeline (orchestrator + rules + client-mirror exporter).
 *   8. Hook registration (the only place we call `add_action` outside of
 *      a class's own `boot()`).
 *   9. Backward-compatible procedural function aliases.
 *
 * Every modern handler self-skips when no group meta is present on the
 * cart-item / postmeta it inspects, so a classic-only set, a non-set
 * product, or a submission without `rental_add_ons[]` sees byte-
 * identical behaviour to the legacy build. The renderer additionally
 * requires `_rental_set_items` to carry a `uid` (written by the modern
 * item builder) before it will display a set in modern mode — pre-
 * existing sets synced under the classic builder need a re-sync to
 * surface in the modern renderer. See DEPLOY.md for the full rollout.
 *
 * @package RentopianSync\Sets
 * @since   2.14.7
 *
 * PHP requirement: 7.4+
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/*
|--------------------------------------------------------------------------
| 1. Required existing dependencies
|--------------------------------------------------------------------------
| The options repository re-uses the already-audited RTSetOptions /
| RTOptionsBase models for single-option webhook operations. They are
| loaded here so a direct `require_once` of this bootstrap always produces
| a fully-functional module, regardless of which other plugin files
| happen to be loaded first.
*/
$sets_module_dir = __DIR__;
$sets_models_dir = dirname( $sets_module_dir ) . '/models';

if ( file_exists( $sets_models_dir . '/RTOptionsBase.php' ) ) {
    require_once $sets_models_dir . '/RTOptionsBase.php';
}
if ( file_exists( $sets_models_dir . '/RTSetOptions.php' ) ) {
    require_once $sets_models_dir . '/RTSetOptions.php';
}

// Logger loads first so every class below can call it during construct.
require_once $sets_module_dir . '/class-sets-logger.php';

/*
|--------------------------------------------------------------------------
| 2. Sync-side classes — entity 1+2 ingest
|--------------------------------------------------------------------------
| Order matters: low-level utilities first, then the data-builders
| (item builder + group builder), then the orchestrator that consumes
| them. The group builder is intentionally listed BEFORE the processor
| because `Rental_Sets_Processor` instantiates it in its constructor —
| the previous build relied on autoload-by-classname behaviour that does
| not exist in this codebase, which produced a fatal on first sync.
*/
require_once $sets_module_dir . '/class-sets-tables.php';
require_once $sets_module_dir . '/class-sets-api-client.php';
require_once $sets_module_dir . '/class-sets-options-repository.php';
require_once $sets_module_dir . '/class-sets-options-query.php';
require_once $sets_module_dir . '/class-sets-divisions-query.php';
require_once $sets_module_dir . '/class-sets-up-cross-sells-processor.php';
require_once $sets_module_dir . '/class-sets-image-linker.php';
require_once $sets_module_dir . '/class-sets-gallery-collector.php';
require_once $sets_module_dir . '/class-sets-sync-context.php';
require_once $sets_module_dir . '/class-sets-sync-result.php';
require_once $sets_module_dir . '/class-sets-item-builder.php';
require_once $sets_module_dir . '/class-sets-group-builder.php';
require_once $sets_module_dir . '/class-sets-sql-builder.php';
require_once $sets_module_dir . '/class-sets-processor.php';
require_once $sets_module_dir . '/class-sets-categories-linker.php';
require_once $sets_module_dir . '/class-sets-tags-processor.php';
require_once $sets_module_dir . '/class-sets-assets.php';
require_once $sets_module_dir . '/class-sets-admin-settings.php';
require_once $sets_module_dir . '/class-sets-cart-visibility.php';
// Selection store: owns the per-customer overlay + definition read path
// (stale-overlay invalidation) and hidden-item default resolution. The
// procedural rental_set_* helpers in functions.php delegate to it.
require_once $sets_module_dir . '/class-sets-selection.php';

/*
|--------------------------------------------------------------------------
| 3. Cart pipeline — composite-group provenance on cart lines
|--------------------------------------------------------------------------
| Loaded unconditionally so the WC filter hooks are always registered;
| each handler self-skips when no group meta is present so classic flows
| are byte-identical to before. Cart_Meta is the source of truth for the
| `rental_set_group_*` namespaced keys; everything below depends on it.
*/
// Keeps set member references valid when the product sync replaces the
// variation posts they point at. Loaded before the cart pipeline because
// api.php calls it during sync, outside any WooCommerce hook.
require_once $sets_module_dir . '/class-sets-variant-remapper.php';

require_once $sets_module_dir . '/class-sets-cart-meta.php';
require_once $sets_module_dir . '/class-sets-cart-handler.php';
require_once $sets_module_dir . '/class-sets-cart-merger.php';
require_once $sets_module_dir . '/class-sets-cart-edit-mode.php';
require_once $sets_module_dir . '/class-sets-cart-reconciler.php';
require_once $sets_module_dir . '/class-sets-cart-edit-link.php';
require_once $sets_module_dir . '/class-sets-archive-guard.php'; // deprecated shim
require_once $sets_module_dir . '/class-sets-product-page-prefill.php';
require_once $sets_module_dir . '/class-sets-order-meta.php';
require_once $sets_module_dir . '/class-sets-payload-extender.php';
require_once $sets_module_dir . '/class-sets-payload-parser.php';
require_once $sets_module_dir . '/class-sets-payload-consumer.php';

/*
|--------------------------------------------------------------------------
| 4. Modern renderer
|--------------------------------------------------------------------------
| Section_Presenter walks the postmeta and yields a unified ordered list
| of sections (fixed item / dropdown / dropdown-with-qty / multi-select);
| Renderer hooks `woocommerce_before_add_to_cart_form` and prints the
| sections through the bundled view templates.
*/
require_once $sets_module_dir . '/class-sets-section-presenter.php';
require_once $sets_module_dir . '/class-sets-renderer.php';

/*
|--------------------------------------------------------------------------
| 5. Cart / order display of group context
|--------------------------------------------------------------------------
| Adds a "Group: <name>" affordance to the cart, mini-cart, checkout
| review, thank-you page, customer emails, my-account → orders, and the
| admin order edit screen. Pure read-side enrichment.
*/
require_once $sets_module_dir . '/class-sets-cart-display.php';
require_once $sets_module_dir . '/class-sets-order-display.php';

/*
|--------------------------------------------------------------------------
| 6. Webhook shape defense + persistence + price recalculation
|--------------------------------------------------------------------------
| Webhook_Defense is registered on `plugins_loaded` (see below) so its
| `update_post_metadata` filter is in place before any webhook callback
| runs. Webhook_Meta_Guard, Webhook_Item_Persister,
| Webhook_Group_Persister and Webhook_Handler are static helpers
| invoked directly from api.php — they have no hooks, so no
| registration step is needed; just being available via require_once
| is enough. The Webhook_Handler orchestrates set/create + set/update
| + set/delete using the other three helpers internally. Pricing
| recomputes set parent line prices when `_rental_item_based_total = 1`,
| mirroring the admin-side rule.
*/
require_once $sets_module_dir . '/class-sets-webhook-defense.php';
require_once $sets_module_dir . '/class-sets-webhook-meta-guard.php';
require_once $sets_module_dir . '/class-sets-webhook-item-persister.php';
require_once $sets_module_dir . '/class-sets-webhook-group-persister.php';
require_once $sets_module_dir . '/class-sets-webhook-handler.php';
require_once $sets_module_dir . '/class-sets-price-engine.php';
require_once $sets_module_dir . '/class-sets-pricing.php';

/*
|--------------------------------------------------------------------------
| 7. Validation pipeline
|--------------------------------------------------------------------------
| Order matters: result + context + the rule interface load first; each
| concrete rule loads next; the orchestrator that uses them last; finally
| the client-mirror exporter. The orchestrator hooks
| `woocommerce_add_to_cart_validation` at priority 15, sitting between
| the legacy validator (priority 10) and any later WC hooks.
*/
$sets_validation_dir = $sets_module_dir . '/validation';
require_once $sets_validation_dir . '/class-sets-validation-result.php';
require_once $sets_validation_dir . '/class-sets-validator-context.php';
require_once $sets_validation_dir . '/class-sets-validation-rule.php';
require_once $sets_validation_dir . '/class-sets-rule-archive-context.php';
require_once $sets_validation_dir . '/class-sets-rule-max-quantity.php';
require_once $sets_validation_dir . '/class-sets-rule-set-options.php';
require_once $sets_validation_dir . '/class-sets-rule-selectable-items.php';
require_once $sets_validation_dir . '/class-sets-rule-group-required.php';
require_once $sets_validation_dir . '/class-sets-rule-group-quantity.php';
require_once $sets_validation_dir . '/class-sets-rule-availability.php';
require_once $sets_validation_dir . '/class-sets-cart-validator.php';
require_once $sets_validation_dir . '/class-sets-rules-exporter.php';

/*
|--------------------------------------------------------------------------
| Release defaults
|--------------------------------------------------------------------------
| `Rental_Sets_Admin_Settings::apply_release_defaults()` is not registered
| here. It is driven by the plugin-wide upgrade flow instead, so every
| module's migration runs from one place: `rental_run_upgrade_steps()` in
| plugin_updater.php, and `rental_plugin_activate()` in rentopian-sync.php
| on activation.
*/

/*
|--------------------------------------------------------------------------
| Cart visibility cache invalidation
|--------------------------------------------------------------------------
| Reset the per-request visibility cache whenever the cart is modified so
| the hidden-item lookup always reflects the live cart state.
*/
add_action( 'woocommerce_cart_updated',       array( 'Rental_Sets_Cart_Visibility', 'reset_instance' ) );
add_action( 'woocommerce_cart_item_removed',  array( 'Rental_Sets_Cart_Visibility', 'reset_instance' ) );
add_action( 'woocommerce_cart_item_restored', array( 'Rental_Sets_Cart_Visibility', 'reset_instance' ) );

/*
|--------------------------------------------------------------------------
| Add-to-cart validation diagnostic
|--------------------------------------------------------------------------
| Runs LAST on the validation chain for set products and records the final
| pass/fail plus the actual WC error notice text into the unified Sets log,
| so a blocked add can be diagnosed precisely instead of guessed at.
*/
add_filter( 'woocommerce_add_to_cart_validation', static function ( $passed, $product_id, $quantity ) {
    if ( ! class_exists( 'Rental_Sets_Logger', false ) ) {
        return $passed;
    }
    if ( ! get_post_meta( (int) $product_id, '_rental_is_set', true ) ) {
        return $passed;
    }
    $messages = array();
    if ( function_exists( 'wc_get_notices' ) ) {
        foreach ( (array) wc_get_notices( 'error' ) as $notice ) {
            $text = is_array( $notice ) ? ( $notice['notice'] ?? '' ) : (string) $notice;
            $text = trim( wp_strip_all_tags( (string) $text ) );
            if ( '' !== $text ) {
                $messages[] = $text;
            }
        }
    }
    Rental_Sets_Logger::warn(
        'add_validation_result',
        $passed ? 'passed' : 'BLOCKED',
        array(
            'set'      => (int) $product_id,
            'qty'      => (int) $quantity,
            'passed'   => $passed ? 1 : 0,
            'errors'   => $messages ? implode( ' || ', $messages ) : '-',
        )
    );
    return $passed;
}, 999, 3 );

/*
|--------------------------------------------------------------------------
| Set-item hiding via standard WooCommerce visibility filters
|--------------------------------------------------------------------------
| The plugin owns hiding of hidden set items / addons through WC's own
| row-visibility filters, so theme templates carry no Sets-specific code.
| Covers the cart, mini-cart, checkout review, and order details / emails.
| Returns the row hidden when Cart_Visibility flags the item; otherwise
| passes WooCommerce's decision through unchanged.
*/
add_filter( 'woocommerce_cart_item_visible',          array( 'Rental_Sets_Cart_Visibility', 'filter_cart_item_visible' ), 10, 3 );
add_filter( 'woocommerce_widget_cart_item_visible',   array( 'Rental_Sets_Cart_Visibility', 'filter_cart_item_visible' ), 10, 3 );
add_filter( 'woocommerce_checkout_cart_item_visible', array( 'Rental_Sets_Cart_Visibility', 'filter_cart_item_visible' ), 10, 3 );
add_filter( 'woocommerce_order_item_visible',         array( 'Rental_Sets_Cart_Visibility', 'filter_order_item_visible' ), 10, 2 );

// Cart badge count — exclude hidden set children from the cart-icon number.
// Theme-agnostic: any theme reading get_cart_contents_count() is corrected
// with no theme code. (Themes with a custom raw-count badge can read
// Rental_Sets_Cart_Visibility::visible_count() instead.)
add_filter( 'woocommerce_cart_contents_count',        array( 'Rental_Sets_Cart_Visibility', 'filter_contents_count' ), 20 );

/*
|--------------------------------------------------------------------------
| Unified Sets log routing
|--------------------------------------------------------------------------
| Every Sets log source is redirected into ONE daily file
| (wp-content/uploads/wc-logs/rentopian-new-sets-logs-YYYY-MM-DD.log)
| through Project_WP_Logger, so the whole module is debuggable from a
| single place. Non-sets sources are untouched (the filter returns the
| incoming target unchanged).
*/
add_filter( 'project_wp_logger_target_path', static function ( $path, $source, $level ) {
    unset( $level );
    if ( null !== $path ) {
        return $path; // a prior handler already chose a target
    }
    $sets_sources = array(
        'rentopian-sets',       // Rental_Sets_Logger lifecycle channels
        'rentopian-sets-sync',  // sync / webhook / cart-pipeline classes
        'rentopian-sets-hide',  // hidden-item resolution + overlay drops
        'rentopian-sets-price', // separate-price billing
    );
    if ( in_array( (string) $source, $sets_sources, true ) ) {
        return 'wp-content/uploads/wc-logs/rentopian-new-sets-logs-' . gmdate( 'Y-m-d' ) . '.log';
    }
    return $path;
}, 10, 3 );

/*
|--------------------------------------------------------------------------
| 8. Modern handler registration
|--------------------------------------------------------------------------
| Each handler class registers its own WC hooks. They all check
| Rental_Sets_Cart_Meta::is_group_child() (or equivalent guard) up front
| and return early when nothing modern is in play, so classic carts /
| orders / payloads are untouched. Registering on `init` so every WC hook
| is available.
|
| Order matters slightly: edit-mode registers at priority 5 (so it can
| detect the query var early) while the merger / handler register at 20.
| Cart_Validator at priority 15 inside `woocommerce_add_to_cart_validation`
| sits between the legacy validator (priority 10) and any later WC hooks.
*/
add_action( 'init', array( 'Rental_Sets_Cart_Edit_Mode',      'register' ), 5 );
add_action( 'init', array( 'Rental_Sets_Cart_Handler',        'register' ), 20 );
add_action( 'init', array( 'Rental_Sets_Cart_Merger',         'register' ), 20 );
// Reconciler MUST register after Cart_Merger (priority 99) and
// Cart_Edit_Mode (priority 100). It hooks at priority 105 so it
// always sees the post-merge / post-edit-replace cart state.
add_action( 'init', array( 'Rental_Sets_Cart_Reconciler',     'register' ), 20 );
add_action( 'init', array( 'Rental_Sets_Cart_Edit_Link',      'register' ), 20 );
add_action( 'init', array( 'Rental_Sets_Cart_Validator',      'register' ), 20 );
add_action( 'init', array( 'Rental_Sets_Product_Page_Prefill', 'register' ), 20 );
add_action( 'init', array( 'Rental_Sets_Order_Meta',          'register' ), 20 );
add_action( 'init', array( 'Rental_Sets_Payload_Extender',    'register' ), 20 );
// Payload_Parser is observation-only: it logs the normalized JSON
// payload the modern configurator emits under `rental_set_payload`
// and diffs it against the legacy synthesizer's output at priority 11.
add_action( 'init', array( 'Rental_Sets_Payload_Parser',      'register' ), 20 );
// Payload_Consumer rebuilds $_POST['rental_add_ons'] from
// the v2 payload at priority 8 — between the observer (5) and the
// legacy synthesizer (10). When the payload is absent / invalid, it
// no-ops and the legacy path runs as before.
add_action( 'init', array( 'Rental_Sets_Payload_Consumer',    'register' ), 20 );
add_action( 'init', array( 'Rental_Sets_Renderer',            'register' ), 20 );
add_action( 'init', array( 'Rental_Sets_Cart_Display',        'register' ), 20 );
add_action( 'init', array( 'Rental_Sets_Order_Display',       'register' ), 20 );
add_action( 'init', array( 'Rental_Sets_Pricing',             'register' ), 20 );

// Webhook_Defense registers on plugins_loaded so its filter is in place
// before any webhook callback runs (webhooks hit api.php which is loaded
// via the main plugin file).
add_action( 'plugins_loaded', array( 'Rental_Sets_Webhook_Defense', 'register' ), 5 );

/*
|--------------------------------------------------------------------------
| 9. Backward-compatible function aliases
|--------------------------------------------------------------------------
| Any code in the plugin or in third-party themes calling the old
| procedural functions will continue to work — these are thin
| delegations to the new classes.
*/
require_once $sets_module_dir . '/compat-functions.php';

/*
|--------------------------------------------------------------------------
| Front-end asset registration
|--------------------------------------------------------------------------
| Registers the Set entity script, style and the `rentalObj` localized
| var. Centralised here so the module owns its own enqueue, instead of
| relying on `functions.php` to do it. The modern renderer enqueues its
| own additional handles separately (see Rental_Sets_Renderer::enqueue()).
*/
Rental_Sets_Assets::register_hooks();
