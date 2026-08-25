<?php
/**
 * Rental_Sets_Admin_Settings
 *
 * Renders and describes the "Sets" section of the Rentopian Sync
 * admin settings page.
 *
 * Responsibilities:
 *   - Single source of truth for the option keys that belong to the
 *     Sets section ({@see option_keys()}). Save handlers, default
 *     resolvers and tests all read from the same list instead of
 *     copy-pasting the strings.
 *   - Rendering of the section markup ({@see render()}). The output
 *     is byte-equivalent to the historical inline block in
 *     `functions.php` so theme overrides relying on the surrounding
 *     CSS/JS structure keep working.
 *
 * Backward compatibility:
 *   - Output structure (HTML element order, class names, IDs and
 *     translation contexts) is identical to the previous inline block.
 *   - The settings page caller can either let this class read the
 *     current option values itself or pass them in via $args — useful
 *     when the page already pre-resolves all options for the form.
 *
 * @package RentopianSync\Sets
 * @since   2.14.7
 *
 * PHP requirement: 7.4+
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'Rental_Sets_Admin_Settings', false ) ) :

class Rental_Sets_Admin_Settings {

    /**
     * Option key — Set listing style ("standard" | "minimal").
     */
    const OPT_LISTING_STYLE = 'rental_set_listing_style';

    /**
     * Option key — Hide set items boolean.
     */
    const OPT_HIDE_ITEMS = 'rental_hide_set_items';

    /**
     * Option key — Sets layout mode ("classic" | "modern").
     *
     * The classic layout is the long-standing rendering: a flat list of
     * components built from `_rental_set_items` only. The modern layout
     * adds the third entity — composite/grouped items collected from the
     * server's `inventory_sets_groups_*` tables — alongside the existing
     * data. Classic is the fallback when the option was never saved.
     * {@see apply_release_defaults()} sets modern one time per site; from
     * then on the settings page owns the value and can switch back to
     * classic for good.
     */
    const OPT_LAYOUT_MODE = 'rental_sets_layout_mode';

    /**
     * Option key — hide the repeated product name on variant choices
     * (modern layout only).
     *
     * A variant label reads "{product} - {attributes}". When the product
     * name is already visible above the list, or when every choice in the
     * list belongs to the same product, that prefix adds nothing and the
     * label can be shortened to the attributes alone.
     * {@see apply_release_defaults()} turns it on one time per site; from
     * then on the settings page owns the value and can turn it back off
     * for good.
     */
    const OPT_HIDE_VARIANT_PRODUCT_NAME = 'rental_sets_hide_variant_product_name';

    /**
     * Layout mode: classic (legacy rendering, items only).
     */
    const LAYOUT_MODE_CLASSIC = 'classic';

    /**
     * Layout mode: modern (items + selectable + composite/grouped items
     * + set_order interleaving). The shipped default.
     */
    const LAYOUT_MODE_MODERN = 'modern';

    /**
     * Option key — set once the shipped defaults have been applied here.
     *
     * Internal bookkeeping, not an admin setting, so it stays out of
     * {@see option_keys()} and the settings save handlers never touch it.
     */
    const OPT_RELEASE_DEFAULTS_APPLIED = 'rental_sets_release_defaults_applied';

    /**
     * The option keys this section is responsible for. Useful for save
     * handlers and migration tooling so they don't hard-code the strings.
     *
     * @return string[]
     */
    public static function option_keys() {
        return array(
            self::OPT_LISTING_STYLE,
            self::OPT_HIDE_ITEMS,
            self::OPT_LAYOUT_MODE,
            self::OPT_HIDE_VARIANT_PRODUCT_NAME,
        );
    }

    /**
     * Resolve the currently active layout mode, normalising to one of the
     * two recognised values. Only an explicitly saved "modern" enables the
     * modern layout; a missing or corrupted option resolves to classic.
     *
     * @return string Either self::LAYOUT_MODE_CLASSIC or self::LAYOUT_MODE_MODERN.
     */
    public static function get_layout_mode() {
        $val = get_option( self::OPT_LAYOUT_MODE, self::LAYOUT_MODE_CLASSIC );
        return ( self::LAYOUT_MODE_MODERN === $val )
            ? self::LAYOUT_MODE_MODERN
            : self::LAYOUT_MODE_CLASSIC;
    }

    /**
     * True when modern layout is active.
     *
     * @return bool
     */
    public static function is_modern() {
        return self::LAYOUT_MODE_MODERN === self::get_layout_mode();
    }

    /**
     * Whether variant choices drop the repeated product-name prefix.
     *
     * The setting only surfaces under the modern layout, so it is gated
     * on the active mode too: a site that saved it and then switched back
     * to classic keeps its stored preference without it taking effect.
     *
     * @return bool
     */
    public static function hides_variant_product_name() {
        return self::is_modern() && (bool) get_option( self::OPT_HIDE_VARIANT_PRODUCT_NAME, 0 );
    }

    /**
     * Seed the layout mode option when missing, using the classic default.
     *
     * Only writes when the option doesn't exist — an existing value, either
     * mode, is left untouched. Use {@see apply_release_defaults()} to push
     * the shipped defaults onto a site instead.
     *
     * @return void
     */
    public static function seed_default_layout_mode() {
        if ( false === get_option( self::OPT_LAYOUT_MODE, false ) ) {
            update_option( self::OPT_LAYOUT_MODE, self::LAYOUT_MODE_CLASSIC );
        }
    }

    /**
     * Apply the shipped defaults — the modern layout with the repeated
     * variant product name hidden — one time on this site.
     *
     * Called from `rental_plugin_activate()` on activation and from
     * `rental_run_upgrade_steps()` on every request, so it lands on a fresh
     * install, on an activation, and on the first request after any plugin
     * update — whichever comes first. Nothing about it is tied to a
     * particular version number.
     *
     * Once the flag is stored it never runs again — not on a re-activation
     * and not on a newer plugin version — so an admin who changes either
     * setting afterwards keeps that choice permanently.
     *
     * @param bool $force Ignore the applied flag.
     * @return void
     */
    public static function apply_release_defaults( $force = false ) {
        if ( ! $force && get_option( self::OPT_RELEASE_DEFAULTS_APPLIED, false ) ) {
            return;
        }

        update_option( self::OPT_LAYOUT_MODE, self::LAYOUT_MODE_MODERN );
        update_option( self::OPT_HIDE_VARIANT_PRODUCT_NAME, 1 );

        update_option( self::OPT_RELEASE_DEFAULTS_APPLIED, 1, true );
    }

    /**
     * Render the Sets section markup.
     *
     * Accepts an optional bag of pre-resolved values so a settings page
     * that already loaded everything in a single bulk-read can avoid
     * a second round of get_option() calls.
     *
     * Recognised keys in $args:
     *   - opt_set_listing_style (string)  Field name for the listing style radio.
     *   - opt_hide_set_items    (string)  Field name for the hide items checkbox.
     *   - opt_sets_layout_mode  (string)  Field name for the layout mode radio.
     *   - opt_hide_variant_product_name (string) Field name for the variant
     *                                            label checkbox.
     *   - val_set_listing_style (string)  Currently saved listing style.
     *   - val_hide_set_items    (mixed)   Currently saved hide-items flag.
     *   - val_sets_layout_mode  (string)  Currently saved layout mode.
     *   - val_hide_variant_product_name (mixed)  Currently saved variant
     *                                            label flag.
     *
     * @param array $args Optional pre-resolved field names / values.
     * @return void
     */
    public static function render( array $args = array() ) {
        $defaults = array(
            'opt_set_listing_style' => self::OPT_LISTING_STYLE,
            'opt_hide_set_items'    => self::OPT_HIDE_ITEMS,
            'opt_sets_layout_mode'  => self::OPT_LAYOUT_MODE,
            'opt_hide_variant_product_name' => self::OPT_HIDE_VARIANT_PRODUCT_NAME,
            'val_set_listing_style' => null,
            'val_hide_set_items'    => null,
            'val_sets_layout_mode'  => null,
            'val_hide_variant_product_name' => null,
        );
        $args = array_merge( $defaults, $args );

        if ( null === $args['val_set_listing_style'] ) {
            $args['val_set_listing_style'] = get_option( self::OPT_LISTING_STYLE, 'standard' );
        }
        if ( null === $args['val_hide_set_items'] ) {
            $args['val_hide_set_items'] = get_option( self::OPT_HIDE_ITEMS, 0 );
        }
        if ( null === $args['val_sets_layout_mode'] ) {
            $args['val_sets_layout_mode'] = self::get_layout_mode();
        }
        if ( null === $args['val_hide_variant_product_name'] ) {
            // Raw option, not hides_variant_product_name(): the checkbox has
            // to show what is stored even while classic is selected, so the
            // preference survives a round-trip through the other mode.
            $args['val_hide_variant_product_name'] = get_option( self::OPT_HIDE_VARIANT_PRODUCT_NAME, 0 );
        }

        $opt_set_listing_style = (string) $args['opt_set_listing_style'];
        $opt_hide_set_items    = (string) $args['opt_hide_set_items'];
        $opt_sets_layout_mode  = (string) $args['opt_sets_layout_mode'];
        $opt_hide_variant_product_name = (string) $args['opt_hide_variant_product_name'];
        $val_set_listing_style = $args['val_set_listing_style'];
        $val_hide_set_items    = $args['val_hide_set_items'];
        $val_sets_layout_mode  = $args['val_sets_layout_mode'];
        $val_hide_variant_product_name = $args['val_hide_variant_product_name'];

        // Allow third-party code to short-circuit / replace the section.
        $custom = apply_filters(
            'rntp_sets_admin_settings_section_pre_render',
            null,
            compact(
                'opt_set_listing_style',
                'opt_hide_set_items',
                'opt_sets_layout_mode',
                'opt_hide_variant_product_name',
                'val_set_listing_style',
                'val_hide_set_items',
                'val_sets_layout_mode',
                'val_hide_variant_product_name'
            )
        );
        if ( null !== $custom ) {
            echo $custom; // Caller takes responsibility for escaping.
            return;
        }

        $template = __DIR__ . '/views/admin-settings-section.php';
        if ( ! file_exists( $template ) ) {
            return;
        }

        include $template;
    }
}

endif;
