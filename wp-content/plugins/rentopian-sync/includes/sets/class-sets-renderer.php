<?php
/**
 * Rental_Sets_Renderer
 *
 * Server-side orchestrator for the modern set product page. Owns three
 * concerns:
 *
 *   1. **Display gate** — `will_render()` decides whether the modern
 *      renderer is taking over the current request. It is the single
 *      source of truth used both internally (to decide whether to print)
 *      and by the legacy `rental_set_products_list()` self-skip guard.
 *      Returning true here means classic stands down on this product.
 *
 *   2. **Output** — when active, hooks `woocommerce_before_add_to_cart_form`
 *      at priority 5, walks the section list produced by
 *      `Rental_Sets_Section_Presenter`, and pulls in the appropriate
 *      partial template per section. The outer wrapper template plus
 *      four section partials live under `views/` and `views/partials/`,
 *      and may be overridden by the active theme via `locate_template()`.
 *
 *   3. **Asset enqueue** — registers `rental-sets-modern` CSS and JS on
 *      single product pages of sets. The classic `rental-sets` handle
 *      is registered separately by `Rental_Sets_Assets`; both can be
 *      active at once when classic mode is in use, but the modern
 *      bundle is gated on `is_modern() && is_set_product()`.
 *
 * Self-skip rules. The renderer aborts (and `will_render()` returns
 * false) when any of the following holds:
 *
 *   - Layout option is set to `classic`.
 *   - The current product is not a set (`_rental_is_set` false).
 *   - The product has no items at all (entity 1+2+3 all empty).
 *
 * The third condition is defensive — a half-synced set with empty
 * postmeta should not produce an empty `<div class="rental-sets-modern">`.
 *
 * Theme overrides. Themes can override any view by placing a file at:
 *
 *     <active-theme>/rentopian-sync/sets/single-product-modern.php
 *     <active-theme>/rentopian-sync/sets/partials/section-fixed-item.php
 *     <active-theme>/rentopian-sync/sets/partials/section-dropdown.php
 *     <active-theme>/rentopian-sync/sets/partials/section-dropdown-qty.php
 *     <active-theme>/rentopian-sync/sets/partials/section-multi-select.php
 *
 * `locate_template()` resolves the override; the bundled view in
 * `views/` is the fallback.
 *
 * Filters. `rental_sets_modern_sections` exposes the section list for
 * last-mile mutation (add a custom section type, hide one, reorder).
 *
 * @package RentopianSync\Sets
 * @since   2.14.7
 *
 * PHP requirement: 7.4+
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'Rental_Sets_Renderer', false ) ) :

class Rental_Sets_Renderer {

    /**
     * Asset handles. Distinct from the classic ones (`rental-sets`,
     * `rental-sets-style`) so themes can dequeue one without affecting
     * the other.
     */
    const SCRIPT_HANDLE = 'rental-sets-modern';
    const STYLE_HANDLE  = 'rental-sets-modern-style';

    /**
     * Theme override location relative to the active theme root.
     */
    const THEME_OVERRIDE_DIR = 'rentopian-sync/sets/';

    /**
     * Memoised result of will_render() per request. The check involves
     * a postmeta read; called from both the integration guard and the
     * renderer's own boot, so worth caching.
     *
     * @var array<int,bool>
     */
    protected static $will_render_cache = array();

    /**
     * @var self|null
     */
    protected static $instance = null;

    /**
     * Singleton accessor — used by the integration guard.
     *
     * @return self
     */
    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Idempotent registration entry-point matching the convention
     * used by the rest of the modern handlers.
     *
     * @return void
     */
    public static function register() {
        $inst = self::instance();
        $inst->boot();
    }

    /**
     * @var bool
     */
    protected $booted = false;

    /**
     * Hook in. Priority 5 on `woocommerce_before_add_to_cart_form` puts
     * the modern markup just above the WooCommerce add-to-cart form
     * (and above any third-party plugin output that defaults to 10).
     *
     * @return void
     */
    protected function boot() {
        if ( $this->booted ) {
            return;
        }
        $this->booted = true;

        add_action( 'woocommerce_before_add_to_cart_form', array( $this, 'maybe_render' ), 5 );
        add_action( 'wp_enqueue_scripts',                  array( $this, 'enqueue' ),     20 );
    }

    /**
     * Public predicate — used both by `maybe_render()` and by the
     * integration guard at the top of the legacy
     * `rental_set_products_list()` function. When this returns true the
     * legacy renderer must stand down.
     *
     * @param int|null $product_id Optional explicit set id. Defaults to
     *                             the current queried product.
     * @return bool
     */
    public static function will_render( $product_id = null ) {
        $product_id = (int) ( $product_id ?: self::resolve_current_product_id() );
        if ( ! $product_id ) {
            return false;
        }

        if ( isset( self::$will_render_cache[ $product_id ] ) ) {
            return self::$will_render_cache[ $product_id ];
        }

        $decision = self::compute_will_render( $product_id );
        self::$will_render_cache[ $product_id ] = $decision;
        return $decision;
    }

    /**
     * Concrete predicate logic, separated so the cached `will_render()`
     * can wrap it.
     *
     * @param int $product_id
     * @return bool
     */
    protected static function compute_will_render( $product_id ) {
        // Layout option must be modern.
        if ( ! Rental_Sets_Admin_Settings::is_modern() ) {
            if ( class_exists( 'Rental_Sets_Logger', false ) ) {
                Rental_Sets_Logger::gate( $product_id, false, 'classic_layout' );
            }
            return false;
        }

        // Must be a set.
        if ( ! get_post_meta( $product_id, '_rental_is_set', true ) ) {
            if ( class_exists( 'Rental_Sets_Logger', false ) ) {
                Rental_Sets_Logger::gate( $product_id, false, 'not_a_set' );
            }
            return false;
        }

        // Must have something to render.
        $items   = get_post_meta( $product_id, '_rental_set_items', true );
        $groups  = get_post_meta( $product_id, '_rental_set_grouped_items', true );
        $has_any = ( is_array( $items ) && ! empty( $items ) )
                || ( is_array( $groups ) && ! empty( $groups ) );

        if ( ! $has_any ) {
            if ( class_exists( 'Rental_Sets_Logger', false ) ) {
                Rental_Sets_Logger::gate( $product_id, false, 'no_items' );
            }
            return false;
        }

        $will = (bool) apply_filters( 'rental_sets_modern_will_render', true, $product_id );
        if ( class_exists( 'Rental_Sets_Logger', false ) ) {
            Rental_Sets_Logger::gate( $product_id, $will, $will ? 'ok' : 'filtered_out' );
        }
        return $will;
    }

    /**
     * Best-effort resolution of the current product id without forcing a
     * dependency on the WC product loop.
     *
     * @return int
     */
    protected static function resolve_current_product_id() {
        if ( function_exists( 'is_product' ) && is_product() ) {
            $qid = get_queried_object_id();
            if ( $qid ) {
                return (int) $qid;
            }
        }
        global $product;
        if ( is_object( $product ) && method_exists( $product, 'get_id' ) ) {
            return (int) $product->get_id();
        }
        return 0;
    }

    /**
     * The main `woocommerce_before_add_to_cart_form` callback. Resolves
     * the current set and prints the outer wrapper template; the wrapper
     * iterates sections and includes per-section partials.
     *
     * @return void
     */
    public function maybe_render() {
        $set_id = self::resolve_current_product_id();
        if ( ! $set_id || ! self::will_render( $set_id ) ) {
            return;
        }

        $sections = $this->build_sections( $set_id );
        if ( empty( $sections ) ) {
            return;
        }

        $is_edit_mode = false;
        if ( class_exists( 'Rental_Sets_Cart_Edit_Mode', false ) ) {
            $edit_inst = Rental_Sets_Cart_Edit_Mode::instance();
            if ( null !== $edit_inst ) {
                $is_edit_mode = (bool) $edit_inst->get_editing_key();
            }
        }

        $template = $this->locate_template( 'single-product-modern.php' );
        if ( ! $template ) {
            return;
        }

        // Variables exposed to the template.
        $rntp_set_id       = (int) $set_id;
        $rntp_sections     = $sections;
        $rntp_is_edit_mode = $is_edit_mode;
        $rntp_partial_lookup = function ( $type ) {
            return $this->locate_template( 'partials/section-' . $this->partial_basename( $type ) . '.php' );
        };

        include $template;
    }

    /**
     * Build the section list and run the public filter through it.
     *
     * @param int $set_id
     * @return array
     */
    public function build_sections( $set_id ) {
        $presenter = Rental_Sets_Section_Presenter::from_meta( $set_id );
        $sections  = $presenter->sections();

        $sections  = apply_filters( 'rental_sets_modern_sections', $sections, $set_id );

        // Drop anything an extension may have nulled.
        $sections = array_values( array_filter( $sections, 'is_array' ) );

        return $sections;
    }

    /**
     * Map a section TYPE to the partial filename basename.
     *
     * @param string $type
     * @return string
     */
    protected function partial_basename( $type ) {
        switch ( $type ) {
            case Rental_Sets_Section_Presenter::TYPE_FIXED_ITEM:
                return 'fixed-item';
            case Rental_Sets_Section_Presenter::TYPE_DROPDOWN:
                return 'dropdown';
            case Rental_Sets_Section_Presenter::TYPE_DROPDOWN_QTY:
                return 'dropdown-qty';
            case Rental_Sets_Section_Presenter::TYPE_MULTI_SELECT:
                return 'multi-select';
        }
        // Defensive fallback: if a third-party section type leaks through,
        // sanitise its name into a filename and try to find a partial.
        $clean = preg_replace( '/[^a-z0-9_\-]+/i', '-', (string) $type );
        return strtolower( trim( $clean, '-' ) );
    }

    /**
     * Resolve a view path. Active theme wins; otherwise the bundled
     * `views/` copy.
     *
     * @param string $relative  Path relative to `views/` (e.g.
     *                          `single-product-modern.php` or
     *                          `partials/section-dropdown.php`).
     * @return string|false  Absolute path or false when not found.
     */
    public function locate_template( $relative ) {
        $relative = ltrim( (string) $relative, '/' );

        if ( function_exists( 'locate_template' ) ) {
            $found = locate_template( self::THEME_OVERRIDE_DIR . $relative );
            if ( $found ) {
                return $found;
            }
        }

        $bundled = __DIR__ . '/views/' . $relative;
        return file_exists( $bundled ) ? $bundled : false;
    }

    /**
     * Register and enqueue the modern bundle. Gated to set product pages
     * in modern mode so non-set product pages stay clean.
     *
     * @return void
     */
    public function enqueue() {
        if ( is_admin() ) {
            return;
        }
        if ( ! function_exists( 'is_product' ) || ! is_product() ) {
            return;
        }

        $set_id = self::resolve_current_product_id();
        if ( ! self::will_render( $set_id ) ) {
            return;
        }

        $base_url = $this->base_url();

        wp_enqueue_style(
            self::STYLE_HANDLE,
            $base_url . 'includes/sets/assets/css/rental-sets-modern.css',
            array(),
            $this->asset_version( 'includes/sets/assets/css/rental-sets-modern.css' )
        );

        wp_enqueue_script(
            self::SCRIPT_HANDLE,
            $base_url . 'includes/sets/assets/js/rental-sets-modern.js',
            array(),
            $this->asset_version( 'includes/sets/assets/js/rental-sets-modern.js' ),
            true
        );

        // Bridge the classic AJAX surface to the modern controller.
        // The legacy add-to-cart validator (rentopian-sync.php:1026)
        // reads `_rental_set_items` postmeta to verify that each
        // addon with `variants_optional` carries a chosen variant_id.
        // Modern JS now calls the same endpoints classic does so the
        // postmeta stays in sync with what the customer picked, and
        // validation passes without breaking classic flow.
        //
        // Three hand-offs the JS needs:
        //   - ajaxUrl          → POST target for action=… AJAX
        //   - setId            → which set this configurator is for
        //   - itemBasedTotal   → whether to fire the price-recalc
        //                        endpoint after each selection change
        //   - priceSelector    → DOM target for displaying the new
        //                        total (matches classic's behaviour)
        // WC currency-format bridge for the JS price formatter.
        // `wc_price()` is a PHP function and can't be called from the
        // browser, so we pass WC's currency configuration through and
        // re-implement the formatter in JS. The defaults below mirror
        // wc_get_price_format / wc_get_price_decimal_separator etc.
        $currency_format = '%1$s%2$s';
        $currency_position = 'left';
        if ( function_exists( 'get_woocommerce_price_format' ) ) {
            $currency_format = (string) get_woocommerce_price_format();
        }
        if ( function_exists( 'get_option' ) ) {
            $currency_position = (string) get_option( 'woocommerce_currency_pos', 'left' );
        }

        // Rentopian-side rental set id. The legacy
        // rental_add_product_to_cart price resolver enters its
        // "set item" branch only when both `set_id` (Rentopian id)
        // AND `parent_set_id` (WP id) are present on the
        // rental_add_ons[i] row. Resolve here so the JS can
        // include it on every emitted row.
        $rental_set_id = 0;
        global $wpdb;
        if ( isset( $wpdb ) ) {
            $rel_table = $wpdb->prefix . 'rental_set_relations';
            // Some installs use a different prefix for rental
            // mapping tables (see $rental_tables). Read the
            // canonical option when available; fall back to the
            // common table name otherwise.
            $maybe_tables = function_exists( 'get_option' ) ? get_option( 'rental_tables', null ) : null;
            if ( is_array( $maybe_tables ) && ! empty( $maybe_tables['set_relations'] ) ) {
                $rel_table = $wpdb->prefix . $maybe_tables['set_relations'];
            }
            // Guard against tables that don't exist yet on fresh
            // installs — keep $rental_set_id = 0 instead of
            // crashing the page.
            $exists = $wpdb->get_var( $wpdb->prepare(
                'SHOW TABLES LIKE %s',
                $rel_table
            ) );
            if ( $exists === $rel_table ) {
                $rental_set_id = (int) $wpdb->get_var( $wpdb->prepare(
                    "SELECT rental_id FROM `{$rel_table}` WHERE id = %d LIMIT 1",
                    $set_id
                ) );
            }
        }

        // Parent base price for the configurator's running total.
        //   - aggregated (item_based_total = 1) → 0; the children carry
        //     the whole total, so the parent contributes nothing.
        //   - fixed_bundle (item_based_total = 0) → the set's own
        //     `_regular_price` (per rental-day rate). The configurator
        //     total is a per-day rollup (no duration multiplier), so this
        //     is the un-multiplied base; the cart applies duration.
        // Without this the product-page total summed ONLY the children —
        // a fixed-price set showed its included items' prices instead of
        // its fixed bundle price.
        $rntp_item_based = (bool) get_post_meta( $set_id, '_rental_item_based_total', true );
        $rntp_parent_base = 0.0;
        if ( ! $rntp_item_based ) {
            $rntp_regular = get_post_meta( $set_id, '_regular_price', true );
            if ( '' === $rntp_regular || null === $rntp_regular ) {
                $rntp_regular = get_post_meta( $set_id, '_price', true );
            }
            $rntp_parent_base = ( '' === $rntp_regular || null === $rntp_regular ) ? 0.0 : (float) $rntp_regular;
        }

        wp_localize_script(
            self::SCRIPT_HANDLE,
            'RentalSetsModern',
            array(
                'ajaxUrl'         => admin_url( 'admin-ajax.php' ),
                'setId'           => (int) $set_id,
                'rentalSetId'     => (int) $rental_set_id,
                'itemBasedTotal'  => $rntp_item_based,
                'parentBasePrice' => $rntp_parent_base,
                'priceSelector'   => '.entry-price-wrap',
                // Price gate for the live total and the per-item price
                // labels the JS repaints. Localized as a string, so the JS
                // compares it numerically.
                'hidePrices'      => ( function_exists( 'rental_prices_are_hidden' ) && rental_prices_are_hidden( 'catalog' ) ) ? 1 : 0,
                'currency'        => array(
                    'symbol'              => function_exists( 'get_woocommerce_currency_symbol' ) ? html_entity_decode( get_woocommerce_currency_symbol() ) : '$',
                    'position'            => $currency_position,
                    'thousand_separator'  => function_exists( 'wc_get_price_thousand_separator' ) ? wc_get_price_thousand_separator() : ',',
                    'decimal_separator'   => function_exists( 'wc_get_price_decimal_separator' ) ? wc_get_price_decimal_separator() : '.',
                    'decimals'            => function_exists( 'wc_get_price_decimals' ) ? (int) wc_get_price_decimals() : 2,
                    'format'              => $currency_format,
                ),
            )
        );
    }

    /**
     * Plugin base URL with a trailing slash.
     *
     * @return string
     */
    protected function base_url() {
        if ( defined( 'RENTOPIAN_SYNC_PATH' ) ) {
            return plugin_dir_url( RENTOPIAN_SYNC_PATH . '/rentopian-sync.php' );
        }
        return plugin_dir_url( dirname( dirname( __DIR__ ) ) . '/rentopian-sync.php' );
    }

    /**
     * Cache-busting version for one bundled asset.
     *
     * The plugin version only moves on a release, so a fix shipped to the
     * configurator's JS between releases keeps the same asset URL and
     * browsers and page caches keep serving the old file. The file's own
     * mtime changes whenever the asset does, which is what this needs to
     * express. Falls back to the plugin version when the mtime is
     * unreadable.
     *
     * @param string $relative Path relative to the plugin root.
     * @return string|null
     */
    protected function asset_version( $relative ) {
        $plugin_version = defined( 'RENTOPIAN_SYNC_VERSION' ) ? RENTOPIAN_SYNC_VERSION : null;

        $path = defined( 'RENTOPIAN_SYNC_PATH' )
            ? RENTOPIAN_SYNC_PATH . '/' . $relative
            : dirname( dirname( __DIR__ ) ) . '/' . $relative;

        if ( ! file_exists( $path ) ) {
            return $plugin_version;
        }

        $mtime = filemtime( $path );
        if ( ! $mtime ) {
            return $plugin_version;
        }

        return ( null === $plugin_version ? '' : $plugin_version . '.' ) . $mtime;
    }
}

endif;
