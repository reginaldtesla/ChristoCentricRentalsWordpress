<?php
/**
 * Sets Module — Dropdown-with-Quantity Section Partial
 *
 * Single-select group whose effective quantity can vary. Reached when
 * `multiple_selection=0` AND (`group_quantity > 1` OR `group_quantity_max > 1`).
 * Visually a `<select>` plus a stepper. The stepper drives the per-pick
 * quantity; the select narrows the choice. The combination produces ONE
 * group child line whose quantity is the stepper value.
 *
 * Quantity bounds:
 *   - `quantity_min` = min count when this group counts as filled.
 *   - `quantity_max` = upper cap; 0 means "no upper bound from postmeta",
 *      in which case the renderer falls back to a sensible cap of 99.
 *
 * Locals available:
 *
 * @var array $section  Section array (see outer wrapper for full shape).
 *
 * @package RentopianSync\Sets
 *
 * PHP requirement: 7.4+
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$items     = isset( $section['items'] ) && is_array( $section['items'] ) ? $section['items'] : array();
$title     = isset( $section['title'] ) ? (string) $section['title'] : '';
$descr     = isset( $section['description'] ) ? (string) $section['description'] : '';
$required  = ! empty( $section['required'] );

$g_qty       = isset( $section['group_quantity'] ) ? (int) $section['group_quantity'] : 1;
$qty_min     = isset( $section['quantity_min'] ) ? (int) $section['quantity_min'] : 0;
$qty_max_raw = isset( $section['quantity_max'] ) ? (int) $section['quantity_max'] : 0;

// `quantity_max=0` from server means "no cap"; pick a humane default.
$qty_max = $qty_max_raw > 0 ? $qty_max_raw : 99;
// Clamp the default-value seed: prefer the admin's group_quantity, else
// the floor (qty_min) when the group is required, else 0.
$qty_default = max( $required ? max( 1, $qty_min ) : 0, min( $g_qty, $qty_max ) );

$select_id = 'rntp-section-' . sanitize_html_class( $section['uid'] );
$qty_id    = $select_id . '-qty';
?>
<div
    class="rntp-section rntp-section--dropdown-qty"
    data-section-type="dropdown_qty"
    data-section-uid="<?php echo esc_attr( $section['uid'] ); ?>"
    data-section-origin="<?php echo esc_attr( $section['origin'] ); ?>"
    data-required="<?php echo $required ? '1' : '0'; ?>"
    data-group-id="<?php echo esc_attr( (int) ( $section['group_id'] ?? 0 ) ); ?>"
    data-group-quantity="<?php echo esc_attr( $g_qty ); ?>"
    data-quantity-min="<?php echo esc_attr( $qty_min ); ?>"
    data-quantity-max="<?php echo esc_attr( $qty_max ); ?>"
    data-multiple-selection="0"
>
    <div class="rntp-section-header">
        <?php
        $rntp_tb_required   = $required;
        $rntp_tb_badge_mode = 'marker';
        include dirname( __FILE__ ) . '/section-titlebar.php';
        ?>
        <?php if ( '' !== $descr ) : ?>
            <p class="rntp-section-description"><?php echo esc_html( $descr ); ?></p>
        <?php endif; ?>
        <?php if ( $qty_min > 0 || $qty_max_raw > 0 ) : ?>
            <p class="rntp-section-bounds">
                <?php
                if ( $qty_min > 0 && $qty_max_raw > 0 ) {
                    /* translators: 1 = min, 2 = max */
                    printf( esc_html__( 'Pick between %1$d and %2$d.', 'rentopian-sync' ), (int) $qty_min, (int) $qty_max_raw );
                } elseif ( $qty_min > 0 ) {
                    /* translators: %d = min */
                    printf( esc_html__( 'Pick at least %d.', 'rentopian-sync' ), (int) $qty_min );
                } elseif ( $qty_max_raw > 0 ) {
                    /* translators: %d = max */
                    printf( esc_html__( 'Pick up to %d.', 'rentopian-sync' ), (int) $qty_max_raw );
                }
                ?>
            </p>
        <?php endif; ?>
    </div>

    <?php
    // "No Thanks" opt-out. Same pattern as
    // section-dropdown.php — replaces the generic "Choose an option"
    // placeholder with an explicit opt-out for non-required groups.
    $rntp_no_thanks_label = __( "No Thanks, I don't need this", 'rentopian-sync' );
    $rntp_no_thanks_value = '__no_thanks__';

    // Resolve the source-configured default item (is_default), honoured on
    // load for required AND optional sections. "No Thanks" pre-selects only
    // when an optional section has no configured default.
    $rntp_default_item = null;
    foreach ( $items as $rntp_cand ) {
        if ( ! empty( $rntp_cand['is_default'] ) ) {
            $rntp_default_item = $rntp_cand;
            break;
        }
    }
    $rntp_use_no_thanks = ( ! $required ) && ( null === $rntp_default_item );
    ?>
    <div class="rntp-dropdown-qty-row">
        <select
            id="<?php echo esc_attr( $select_id ); ?>"
            class="rntp-section-select"
            data-role="section-select"
            <?php echo $required ? 'aria-required="true"' : ''; ?>
        >
            <?php if ( ! $required ) : ?>
                <?php // Optional section → "No Thanks" available; pre-selected only when no source default is configured. ?>
                <option value="<?php echo esc_attr( $rntp_no_thanks_value ); ?>" data-no-thanks="1" <?php selected( $rntp_use_no_thanks, true ); ?>><?php echo esc_html( $rntp_no_thanks_label ); ?></option>
            <?php endif; ?>

            <?php foreach ( $items as $item_index => $item ) :
                $item_uid    = isset( $item['uid'] ) ? (string) $item['uid'] : '';
                $product_id  = isset( $item['product_id'] ) ? (int) $item['product_id'] : 0;
                $variant_id  = isset( $item['variant_id'] ) ? (int) $item['variant_id'] : 0;
                $inv_id      = isset( $item['inv_id'] ) ? (int) $item['inv_id'] : 0;
                $name        = isset( $item['name'] ) ? (string) $item['name'] : '';
                $price       = isset( $item['price'] ) ? $item['price'] : '';
                $is_default  = ! empty( $item['is_default'] );

                $option_key  = '' !== $item_uid
                    ? $item_uid
                    : 'opt-' . $product_id . '-' . $variant_id . '-' . $item_index;
            ?>
                <?php
                // Empty while prices are hidden, which drops the label below.
                $price_plain = rental_price_label( $price );
                ?>
                <option
                    value="<?php echo esc_attr( $option_key ); ?>"
                    data-item-uid="<?php echo esc_attr( $item_uid ); ?>"
                    data-product-id="<?php echo esc_attr( $product_id ); ?>"
                    data-variant-id="<?php echo esc_attr( $variant_id ); ?>"
                    data-inv-id="<?php echo esc_attr( $inv_id ); ?>"
                    data-price="<?php echo esc_attr( (string) $price ); ?>"
                    <?php
                    // Pre-select the configured default item (required, or
                    // optional-with-default). Optional-without-default falls
                    // through to "No Thanks".
                    if ( ! $rntp_use_no_thanks ) {
                        selected( $is_default, true );
                    }
                    ?>
                >
                    <?php echo esc_html( $name ); ?>
                    <?php if ( '' !== $price_plain ) : ?>
                        <?php echo esc_html( ' — ' . $price_plain ); ?>
                    <?php endif; ?>
                </option>
            <?php endforeach; ?>
        </select>

        <?php
        // Custom dropdown shell — mirrors section-dropdown.php. Renders
        // each option as a thumbnail + name + price. Qty here lives in
        // the adjacent stepper, so we don't repeat it on the rows; the
        // trigger label stays narrow next to the qty controls.
        // Optional sections default to "No Thanks" — trigger shows the
        // opt-out label, no thumb/price, on first paint.
        $rntp_initial           = null;
        $rntp_initial_no_thanks = $rntp_use_no_thanks;
        if ( ! $rntp_use_no_thanks ) {
            // Required, or optional-with-default: show the configured default
            // item, falling back to the first item when none is flagged.
            $rntp_initial = $rntp_default_item;
            if ( null === $rntp_initial && ! empty( $items ) ) {
                $rntp_initial = reset( $items );
            }
        }
        $rntp_initial_image = is_array( $rntp_initial ) && ! empty( $rntp_initial['image'] ) ? (string) $rntp_initial['image'] : '';
        $rntp_initial_name  = is_array( $rntp_initial ) && isset( $rntp_initial['name'] )  ? (string) $rntp_initial['name']  : '';
        $rntp_initial_price_plain = is_array( $rntp_initial ) && isset( $rntp_initial['price'] )
            ? rental_price_label( $rntp_initial['price'] )
            : '';
        ?>
        <div class="rntp-cs<?php echo $rntp_initial_no_thanks ? ' rntp-cs--no-thanks' : ''; ?>" data-role="custom-select" data-for-select="<?php echo esc_attr( $select_id ); ?>">
            <button type="button" class="rntp-cs-trigger" aria-haspopup="listbox" aria-expanded="false" aria-label="<?php echo esc_attr( $title ); ?>">
                <span class="rntp-cs-thumb" data-role="cs-trigger-thumb">
                    <?php if ( '' !== $rntp_initial_image ) : ?>
                        <?php echo wp_kses_post( $rntp_initial_image ); ?>
                    <?php else : ?>
                        <span class="rntp-thumb-placeholder" aria-hidden="true"></span>
                    <?php endif; ?>
                </span>
                <span class="rntp-cs-label" data-role="cs-trigger-label">
                    <span class="rntp-cs-name"><?php echo esc_html( $rntp_initial_no_thanks ? $rntp_no_thanks_label : $rntp_initial_name ); ?></span>
                    <?php if ( '' !== $rntp_initial_price_plain ) : ?>
                        <span class="rntp-cs-meta">
                            <span class="rntp-cs-price"><?php echo esc_html( $rntp_initial_price_plain ); ?></span>
                        </span>
                    <?php endif; ?>
                </span>
                <span class="rntp-cs-chevron" aria-hidden="true">&#9662;</span>
            </button>
            <ul class="rntp-cs-panel" role="listbox" hidden>
                <?php if ( ! $required ) : ?>
                    <?php /* No image / thumbnail / placeholder for No Thanks. */ ?>
                    <li class="rntp-cs-option rntp-cs-option--no-thanks" role="option" data-value="<?php echo esc_attr( $rntp_no_thanks_value ); ?>" data-no-thanks="1" aria-selected="<?php echo $rntp_use_no_thanks ? 'true' : 'false'; ?>">
                        <span class="rntp-cs-option-text">
                            <span class="rntp-cs-name"><?php echo esc_html( $rntp_no_thanks_label ); ?></span>
                        </span>
                    </li>
                <?php endif; ?>
                <?php foreach ( $items as $item_index => $item ) :
                    $item_uid   = isset( $item['uid'] ) ? (string) $item['uid'] : '';
                    $product_id = isset( $item['product_id'] ) ? (int) $item['product_id'] : 0;
                    $variant_id = isset( $item['variant_id'] ) ? (int) $item['variant_id'] : 0;
                    $name       = isset( $item['name'] ) ? (string) $item['name'] : '';
                    $price      = isset( $item['price'] ) ? $item['price'] : '';
                    $image_html = isset( $item['image'] ) ? (string) $item['image'] : '';
                    $permalink  = isset( $item['permalink'] ) ? (string) $item['permalink'] : '';
                    $is_default = ! empty( $item['is_default'] );
                    // Complete "{product} - {attributes}" label. Identical to
                    // $name unless the admin shortened variant labels, in which
                    // case it keeps the link description unambiguous.
                    $name_full  = isset( $item['name_full'] ) ? (string) $item['name_full'] : $name;

                    $option_key = '' !== $item_uid ? $item_uid : 'opt-' . $product_id . '-' . $variant_id . '-' . $item_index;

                    $price_plain = rental_price_label( $price );
                ?>
                    <li class="rntp-cs-option" role="option" data-value="<?php echo esc_attr( $option_key ); ?>" aria-selected="<?php echo $is_default ? 'true' : 'false'; ?>">
                        <span class="rntp-cs-thumb">
                            <?php if ( '' !== $image_html ) : ?>
                                <?php echo wp_kses_post( $image_html ); ?>
                            <?php else : ?>
                                <span class="rntp-thumb-placeholder" aria-hidden="true"></span>
                            <?php endif; ?>
                        </span>
                        <span class="rntp-cs-option-text">
                            <span class="rntp-cs-name" title="<?php echo esc_attr( $name_full ); ?>"><?php echo esc_html( $name ); ?></span>
                            <?php if ( '' !== $price_plain ) : ?>
                                <span class="rntp-cs-meta"><span class="rntp-cs-price"><?php echo esc_html( $price_plain ); ?></span></span>
                            <?php endif; ?>
                        </span>
                        <?php if ( '' !== $permalink ) : ?>
                            <a
                                class="rntp-cs-external-link"
                                data-role="external-link"
                                href="<?php echo esc_url( $permalink ); ?>"
                                target="_blank"
                                rel="noopener noreferrer"
                                aria-label="<?php echo esc_attr( sprintf( __( 'Open %s in a new tab', 'rentopian-sync' ), $name_full ) ); ?>"
                            >
                                <svg class="rntp-cs-external-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
                                    <path d="M14 3h7v7" />
                                    <path d="M10 14L21 3" />
                                    <path d="M21 14v5a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5" />
                                </svg>
                            </a>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>

        <?php
        // Trigger-level external-link — sits between the dropdown shell
        // and the qty stepper. Use the initial selection's permalink. A
        // sibling is substituted ONLY when there is no real initial pick
        // (the section defaults to "No Thanks" or has no default). When a
        // real item is selected but has no public page (a hidden add-on /
        // hidden-on-website item), the icon stays absent — never pointed at
        // a different item. The JS keeps the href in sync applying the same
        // rule.
        $rntp_trigger_permalink = '';
        if ( is_array( $rntp_initial ) && ! empty( $rntp_initial['permalink'] ) ) {
            $rntp_trigger_permalink = (string) $rntp_initial['permalink'];
        }
        if ( '' === $rntp_trigger_permalink && ( $rntp_initial_no_thanks || null === $rntp_initial ) ) {
            foreach ( $items as $rntp_cand ) {
                if ( ! empty( $rntp_cand['permalink'] ) ) {
                    $rntp_trigger_permalink = (string) $rntp_cand['permalink'];
                    break;
                }
            }
        }
        ?>
        <?php if ( '' !== $rntp_trigger_permalink ) : ?>
            <a
                class="rntp-section-external-link"
                data-role="section-external-link"
                href="<?php echo esc_url( $rntp_trigger_permalink ); ?>"
                target="_blank"
                rel="noopener noreferrer"
                aria-label="<?php esc_attr_e( 'Open selected item in a new tab', 'rentopian-sync' ); ?>"
            >
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
                    <path d="M14 3h7v7" />
                    <path d="M10 14L21 3" />
                    <path d="M21 14v5a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5" />
                </svg>
            </a>
        <?php endif; ?>

        <div class="rntp-stepper" data-role="qty-stepper">
            <button
                type="button"
                class="rntp-stepper-btn rntp-stepper-btn--minus"
                data-action="decrement"
                aria-label="<?php esc_attr_e( 'Decrease quantity', 'rentopian-sync' ); ?>"
            >−</button>
            <input
                id="<?php echo esc_attr( $qty_id ); ?>"
                type="number"
                class="rntp-stepper-input"
                data-role="qty-input"
                value="<?php echo esc_attr( $qty_default ); ?>"
                min="<?php echo esc_attr( max( 0, $qty_min ) ); ?>"
                max="<?php echo esc_attr( $qty_max ); ?>"
                step="1"
                inputmode="numeric"
            />
            <button
                type="button"
                class="rntp-stepper-btn rntp-stepper-btn--plus"
                data-action="increment"
                aria-label="<?php esc_attr_e( 'Increase quantity', 'rentopian-sync' ); ?>"
            >+</button>
        </div>
    </div>

    <p class="rntp-section-error" data-role="section-error" role="alert" aria-live="polite" hidden></p>
</div>
