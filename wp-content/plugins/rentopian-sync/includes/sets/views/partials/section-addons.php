<?php
/**
 * Sets Module — Addons Block Partial
 *
 * Rendered inline below the dropdown / fixed-item card whenever the
 * section's set item carries any addons. Mirrors classic mode's
 * affordance — each addon is listed under its parent set item, and
 * customers pick a variant for any addon that has `variants_optional`.
 *
 * Each card has data-* attributes the JS controller reads to assemble
 * `rental_add_ons[i]` entries on submit:
 *
 *   data-addon                      flags this DOM node as an addon picker
 *   data-product-id                 WP product id of the addon
 *   data-rental-inv-id              Laravel inventory id (`inv_id` in cart)
 *   data-quantity                   per-set-quantity multiplier
 *   data-required                   "1" when the addon is required
 *   data-parent-product-id          WP product id of the parent set item
 *
 * If the addon has variants_optional, the `<select>` carries the
 * current pick and emits a change event. The JS wrapper listens on
 * `change` and re-syncs the hidden inputs.
 *
 * Locals available:
 *
 * @var array $section   The full section array. Only `addons` and
 *                       `parent_set_item_product_id` are read here.
 *                       `parent_set_item_product_id` is the parent
 *                       set item's WP product id (selectable origin).
 *
 * @package RentopianSync\Sets
 *
 * PHP requirement: 7.4+
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** @var array<string,mixed> $section */

$addons = isset( $section['addons'] ) && is_array( $section['addons'] ) ? $section['addons'] : array();
if ( empty( $addons ) ) {
    return;
}

// Parent context. For selectable sections we have the explicit
// parent_set_item_product_id; for fixed-item sections we fall back to
// the first item's product id since the section represents that single
// item.
$parent_set_item_product_id = 0;
if ( isset( $section['parent_set_item_product_id'] ) ) {
    $parent_set_item_product_id = (int) $section['parent_set_item_product_id'];
} elseif ( isset( $section['items'][0]['product_id'] ) ) {
    $parent_set_item_product_id = (int) $section['items'][0]['product_id'];
}
?>
<div class="rntp-addons" data-role="addons">
    <p class="rntp-addons-heading">
        <?php esc_html_e( 'Included add-ons', 'rentopian-sync' ); ?>
    </p>

    <?php foreach ( $addons as $addon_index => $addon ) :
        $addon_product_id   = isset( $addon['product_id'] )    ? (int) $addon['product_id']    : 0;
        $addon_rental_inv_id = isset( $addon['rental_inv_id'] ) ? (int) $addon['rental_inv_id'] : 0;
        $addon_qty          = isset( $addon['quantity'] )      ? (int) $addon['quantity']      : 1;
        $addon_required     = ! empty( $addon['required'] );
        $addon_name         = isset( $addon['name'] )          ? (string) $addon['name']       : '';
        $addon_image        = isset( $addon['image'] )         ? (string) $addon['image']      : '';
        $has_variants       = ! empty( $addon['has_variants'] );
        $options            = isset( $addon['options'] ) && is_array( $addon['options'] ) ? $addon['options'] : array();
        $current_variant_id = isset( $addon['variant_id'] )    ? (int) $addon['variant_id']    : 0;

        // Per-addon raw price (the admin-configured custom add-on
        // price).
        $addon_raw_price = isset( $addon['price'] ) ? (string) $addon['price'] : '';

        // "No Thanks" opt-out for OPTIONAL addons. An optional addon must
        // expose a literal "No Thanks" choice so the customer can skip it
        // entirely (not submitted, $0, excluded from the count). It is
        // pre-selected ONLY when the addon is optional AND has no
        // configured default and no current selection — a default / current
        // pick wins. Mirrors the section dropdown's opt-out contract.
        $rntp_addon_no_thanks_label = __( "No Thanks, I don't need this", 'rentopian-sync' );
        $rntp_addon_has_default     = false;
        foreach ( $options as $rntp_opt_cand ) {
            if ( ! empty( $rntp_opt_cand['is_default'] ) ) {
                $rntp_addon_has_default = true;
                break;
            }
        }
        $rntp_addon_use_no_thanks = ( ! $addon_required ) && ( 0 === $current_variant_id ) && ! $rntp_addon_has_default;

        $select_id = 'rntp-addon-' . sanitize_html_class( $section['uid'] ) . '-' . $addon_index;
    ?>
        <div
            class="rntp-addon-card"
            data-role="addon-card"
            data-addon="1"
            data-product-id="<?php echo esc_attr( $addon_product_id ); ?>"
            data-rental-inv-id="<?php echo esc_attr( $addon_rental_inv_id ); ?>"
            data-quantity="<?php echo esc_attr( $addon_qty ); ?>"
            data-required="<?php echo $addon_required ? '1' : '0'; ?>"
            data-parent-product-id="<?php echo esc_attr( $parent_set_item_product_id ); ?>"
            data-variant-id="<?php echo esc_attr( $current_variant_id ); ?>"
            data-price="<?php echo esc_attr( $addon_raw_price ); ?>"
        >
            <?php if ( '' !== $addon_image ) : ?>
                <div class="rntp-addon-image">
                    <?php echo wp_kses_post( $addon_image ); ?>
                </div>
            <?php endif; ?>

            <div class="rntp-addon-body">
                <?php
                // Addon title-bar — name (truncates) + badge anchored at
                // the same spot as item cards (#3 consistency). Addons are
                // included by the package, so required → "Included",
                // optional → "Optional". The (i) info reveals the full
                // name on hover when truncated (JS).
                ?>
                <div class="rntp-addon-titlebar">
                    <span class="rntp-addon-name rntp-section-title" title="<?php echo esc_attr( $addon_name ); ?>"><?php echo esc_html( $addon_name ); ?></span>
                    <span class="rntp-title-info" role="img" aria-label="<?php echo esc_attr( $addon_name ); ?>" title="<?php echo esc_attr( $addon_name ); ?>">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
                            <circle cx="12" cy="12" r="10" />
                            <line x1="12" y1="16" x2="12" y2="12" />
                            <line x1="12" y1="8" x2="12.01" y2="8" />
                        </svg>
                    </span>
                    <?php if ( $addon_required ) : ?>
                        <span class="rntp-required-badge" aria-label="<?php esc_attr_e( 'Included', 'rentopian-sync' ); ?>"><?php esc_html_e( 'Included', 'rentopian-sync' ); ?></span>
                    <?php else : ?>
                        <span class="rntp-optional-badge" aria-label="<?php esc_attr_e( 'Optional', 'rentopian-sync' ); ?>"><?php esc_html_e( 'Optional', 'rentopian-sync' ); ?></span>
                    <?php endif; ?>
                </div>

                <?php if ( $has_variants ) : ?>
                    <label class="rntp-addon-label" for="<?php echo esc_attr( $select_id ); ?>">
                        <?php esc_html_e( 'Choose a variation', 'rentopian-sync' ); ?>
                    </label>
                    <select
                        id="<?php echo esc_attr( $select_id ); ?>"
                        class="rntp-addon-select"
                        data-role="addon-select"
                    >
                        <?php if ( ! $addon_required ) : ?>
                            <option value="__no_thanks__" data-no-thanks="1" data-price="" <?php selected( $rntp_addon_use_no_thanks, true ); ?>><?php echo esc_html( $rntp_addon_no_thanks_label ); ?></option>
                        <?php endif; ?>
                        <?php
                        // Capture the selected option's price for the
                        // initial WC-HTML render of .rntp-selected-price
                        // below the addon's <select>. Reset each
                        // iteration so we end up with the price of the
                        // option marked selected.
                        $addon_selected_price_raw = '';
                        foreach ( $options as $opt_index => $opt ) :
                            $opt_variant_id = isset( $opt['variant_id'] ) ? (int) $opt['variant_id'] : 0;
                            $opt_product_id = isset( $opt['product_id'] ) ? (int) $opt['product_id'] : 0;
                            $opt_name       = isset( $opt['name'] )       ? (string) $opt['name']    : '';
                            $opt_price      = isset( $opt['price'] )      ? (string) $opt['price']   : '';
                            $opt_qty        = isset( $opt['quantity'] )   ? (int) $opt['quantity']   : $addon_qty;
                            $opt_is_default = ! empty( $opt['is_default'] );
                            // Selection priority: explicit current_variant_id wins,
                            // else fall back to the default flag from the API. When
                            // the addon defaults to "No Thanks" no variant is marked.
                            $is_selected = ! $rntp_addon_use_no_thanks
                                && ( ( 0 !== $current_variant_id && $opt_variant_id === $current_variant_id )
                                    || ( 0 === $current_variant_id && $opt_is_default ) );

                            // Plain-text currency formatting for the
                            // option label (same constraint as the
                            // selectable section's options: <option>
                            // can't host HTML). Empty while prices are
                            // hidden.
                            $opt_price_plain = rental_price_label( $opt_price );
                            if ( $is_selected ) {
                                $addon_selected_price_raw = $opt_price;
                            }
                        ?>
                            <option
                                value="<?php echo esc_attr( $opt_variant_id ); ?>"
                                data-product-id="<?php echo esc_attr( $opt_product_id ); ?>"
                                data-variant-id="<?php echo esc_attr( $opt_variant_id ); ?>"
                                data-quantity="<?php echo esc_attr( $opt_qty ); ?>"
                                data-price="<?php echo esc_attr( $opt_price ); ?>"
                                <?php selected( $is_selected, true ); ?>
                            >
                                <?php echo esc_html( $opt_name ); ?>
                                <?php if ( '' !== $opt_price_plain ) : ?>
                                    <?php echo esc_html( ' — ' . $opt_price_plain ); ?>
                                <?php endif; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <?php
                    // Custom dropdown shell for the addon — mirrors the
                    // selectable section's shell so the visual contract is
                    // consistent: thumbnail + name + price per variant.
                    // The native <select> above stays as the form-bound
                    // value source; JS auto-wires this shell via the
                    // shared `.rntp-cs[data-role="custom-select"]` selector
                    // and delegates value changes back to the select.
                    // When the addon defaults to "No Thanks" the trigger shows
                    // the opt-out label (no thumb / price); otherwise it shows
                    // the current / default variant.
                    $rntp_initial = null;
                    if ( ! $rntp_addon_use_no_thanks ) {
                        foreach ( $options as $opt_cand ) {
                            $opt_cand_vid = isset( $opt_cand['variant_id'] ) ? (int) $opt_cand['variant_id'] : 0;
                            if ( 0 !== $current_variant_id && $opt_cand_vid === $current_variant_id ) {
                                $rntp_initial = $opt_cand;
                                break;
                            }
                            if ( ! empty( $opt_cand['is_default'] ) && null === $rntp_initial ) {
                                $rntp_initial = $opt_cand;
                            }
                        }
                        if ( null === $rntp_initial && ! empty( $options ) ) {
                            $rntp_initial = reset( $options );
                        }
                    }
                    $rntp_initial_image = is_array( $rntp_initial ) && ! empty( $rntp_initial['image'] ) ? (string) $rntp_initial['image'] : '';
                    $rntp_initial_name  = is_array( $rntp_initial ) && isset( $rntp_initial['name'] )  ? (string) $rntp_initial['name']  : '';
                    $rntp_initial_price_plain = is_array( $rntp_initial ) && isset( $rntp_initial['price'] )
                        ? rental_price_label( $rntp_initial['price'] )
                        : '';
                    $rntp_cs_trigger_name = $rntp_addon_use_no_thanks ? $rntp_addon_no_thanks_label : $rntp_initial_name;
                    ?>
                    <div class="rntp-cs rntp-cs--addon<?php echo $rntp_addon_use_no_thanks ? ' rntp-cs--no-thanks' : ''; ?>" data-role="custom-select" data-for-select="<?php echo esc_attr( $select_id ); ?>">
                        <button type="button" class="rntp-cs-trigger" aria-haspopup="listbox" aria-expanded="false" aria-label="<?php echo esc_attr( $addon_name ); ?>">
                            <span class="rntp-cs-thumb" data-role="cs-trigger-thumb">
                                <?php if ( '' !== $rntp_initial_image ) : ?>
                                    <?php echo wp_kses_post( $rntp_initial_image ); ?>
                                <?php else : ?>
                                    <span class="rntp-thumb-placeholder" aria-hidden="true"></span>
                                <?php endif; ?>
                            </span>
                            <span class="rntp-cs-label" data-role="cs-trigger-label">
                                <span class="rntp-cs-name"><?php echo esc_html( $rntp_cs_trigger_name ); ?></span>
                                <?php if ( '' !== $rntp_initial_price_plain ) : ?>
                                    <span class="rntp-cs-meta"><span class="rntp-cs-price"><?php echo esc_html( $rntp_initial_price_plain ); ?></span></span>
                                <?php endif; ?>
                            </span>
                            <span class="rntp-cs-chevron" aria-hidden="true">&#9662;</span>
                        </button>
                        <ul class="rntp-cs-panel" role="listbox" hidden>
                            <?php if ( ! $addon_required ) : ?>
                                <li class="rntp-cs-option rntp-cs-option--no-thanks" role="option" data-value="__no_thanks__" data-no-thanks="1" aria-selected="<?php echo $rntp_addon_use_no_thanks ? 'true' : 'false'; ?>">
                                    <span class="rntp-cs-option-text">
                                        <span class="rntp-cs-name"><?php echo esc_html( $rntp_addon_no_thanks_label ); ?></span>
                                    </span>
                                </li>
                            <?php endif; ?>
                            <?php foreach ( $options as $opt_index => $opt ) :
                                $opt_variant_id = isset( $opt['variant_id'] ) ? (int) $opt['variant_id'] : 0;
                                $opt_name       = isset( $opt['name'] )       ? (string) $opt['name']    : '';
                                // Complete "{product} - {attributes}" label,
                                // for the tooltip when $opt_name is shortened.
                                $opt_name_full  = isset( $opt['name_full'] )  ? (string) $opt['name_full'] : $opt_name;
                                $opt_price      = isset( $opt['price'] )      ? (string) $opt['price']   : '';
                                $opt_image      = isset( $opt['image'] )      ? (string) $opt['image']   : '';
                                $opt_is_default = ! empty( $opt['is_default'] );
                                $opt_is_selected = ! $rntp_addon_use_no_thanks
                                    && ( ( 0 !== $current_variant_id && $opt_variant_id === $current_variant_id )
                                        || ( 0 === $current_variant_id && $opt_is_default ) );

                                $opt_price_plain = rental_price_label( $opt_price );
                            ?>
                                <li class="rntp-cs-option" role="option" data-value="<?php echo esc_attr( $opt_variant_id ); ?>" aria-selected="<?php echo $opt_is_selected ? 'true' : 'false'; ?>">
                                    <span class="rntp-cs-thumb">
                                        <?php if ( '' !== $opt_image ) : ?>
                                            <?php echo wp_kses_post( $opt_image ); ?>
                                        <?php else : ?>
                                            <span class="rntp-thumb-placeholder" aria-hidden="true"></span>
                                        <?php endif; ?>
                                    </span>
                                    <span class="rntp-cs-option-text">
                                        <span class="rntp-cs-name" title="<?php echo esc_attr( $opt_name_full ); ?>"><?php echo esc_html( $opt_name ); ?></span>
                                        <?php if ( '' !== $opt_price_plain ) : ?>
                                            <span class="rntp-cs-meta"><span class="rntp-cs-price"><?php echo esc_html( $opt_price_plain ); ?></span></span>
                                        <?php endif; ?>
                                    </span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>

                    <span class="rntp-addon-qty"><?php echo esc_html( sprintf( /* translators: %d quantity */ __( 'Qty: %d', 'rentopian-sync' ), $addon_qty ) ); ?></span>
                <?php else :
                    // Non-variant addon (0 or 1 variant option).
                    $rntp_addon_static_price_plain = isset( $addon['price'] )
                        ? rental_price_label( $addon['price'] )
                        : '';
                    ?>
                    <?php if ( $addon_required ) : ?>
                        <?php
                        // Required → always included. Price shown on its own
                        // line (like the item-card price) plus a Qty line.
                        ?>
                        <div class="rntp-addon-meta">
                            <?php if ( '' !== $rntp_addon_static_price_plain ) : ?>
                                <span class="rntp-addon-price"><?php echo esc_html( $rntp_addon_static_price_plain ); ?></span>
                            <?php endif; ?>
                            <span class="rntp-addon-qty"><?php echo esc_html( sprintf( /* translators: %d quantity */ __( 'Qty: %d', 'rentopian-sync' ), $addon_qty ) ); ?></span>
                        </div>
                    <?php else : ?>
                        <?php
                        // Optional non-variant addon → a two-choice selector so
                        // the customer can INCLUDE it or decline ("No Thanks").
                        // Reuses the variant dropdown's JS contract
                        // (data-role="addon-select" + data-no-thanks), so
                        // opting out drops the addon from the summary, the
                        // count, and the cart submission. The default (include
                        // vs "No Thanks") follows the same rule as variant
                        // addons — pre-selected only when the addon carries no
                        // configured default.
                        $rntp_addon_include_label = $addon_name . ( '' !== $rntp_addon_static_price_plain ? ' — ' . $rntp_addon_static_price_plain : '' );
                        ?>
                        <label class="rntp-addon-label" for="<?php echo esc_attr( $select_id ); ?>">
                            <?php esc_html_e( 'Add-on', 'rentopian-sync' ); ?>
                        </label>
                        <select
                            id="<?php echo esc_attr( $select_id ); ?>"
                            class="rntp-addon-select"
                            data-role="addon-select"
                        >
                            <option value="<?php echo esc_attr( $current_variant_id ); ?>" data-product-id="<?php echo esc_attr( $addon_product_id ); ?>" data-variant-id="<?php echo esc_attr( $current_variant_id ); ?>" data-quantity="<?php echo esc_attr( $addon_qty ); ?>" data-price="<?php echo esc_attr( $addon_raw_price ); ?>" <?php selected( ! $rntp_addon_use_no_thanks, true ); ?>><?php echo esc_html( $rntp_addon_include_label ); ?></option>
                            <option value="__no_thanks__" data-no-thanks="1" data-price="" <?php selected( $rntp_addon_use_no_thanks, true ); ?>><?php echo esc_html( $rntp_addon_no_thanks_label ); ?></option>
                        </select>
                        <span class="rntp-addon-qty"><?php echo esc_html( sprintf( /* translators: %d quantity */ __( 'Qty: %d', 'rentopian-sync' ), $addon_qty ) ); ?></span>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
</div>
