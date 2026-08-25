<?php
/**
 * Sets Module — Multi-Select Section Partial
 *
 * Composite group with `multiple_selection=1`. The customer can pick
 * any number of children, each with its own quantity, subject to the
 * group's `quantity_min` / `quantity_max` total bound.
 *
 * Visually a grid of cards. Each card carries:
 *
 *   - the product image, name, optional price
 *   - a stepper (minus / number input / plus)
 *   - a "selected" outline state when stepper > 0
 *
 * The per-card data-* attributes carry every field the JS controller
 * needs to emit a complete `rental_set_selections[<group_uid>]
 * .selections[<item_uid>]` payload.
 *
 * Quantity-bound rendering. The total is `Σ child_qty` across cards.
 * The aggregate stays inside `[quantity_min, quantity_max]`. The JS
 * enforces the bounds inline; the server validator is the source of
 * truth on submit.
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
$qty_max     = $qty_max_raw > 0 ? $qty_max_raw : 99;
?>
<div
    class="rntp-section rntp-section--multi-select"
    data-section-type="multi_select"
    data-section-uid="<?php echo esc_attr( $section['uid'] ); ?>"
    data-section-origin="<?php echo esc_attr( $section['origin'] ); ?>"
    data-required="<?php echo $required ? '1' : '0'; ?>"
    data-group-id="<?php echo esc_attr( (int) ( $section['group_id'] ?? 0 ) ); ?>"
    data-group-quantity="<?php echo esc_attr( $g_qty ); ?>"
    data-quantity-min="<?php echo esc_attr( $qty_min ); ?>"
    data-quantity-max="<?php echo esc_attr( $qty_max ); ?>"
    data-multiple-selection="1"
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
        <?php
        // "Selected: X of Y" — distinct items chosen over total items in
        // the group. The JS (MultiSection.refreshRunningTotal) recomputes
        // X on every card change; Y is fixed by the rendered card count.
        $rntp_total_items = count( $items );
        ?>
        <p class="rntp-selected-count">
            <?php /* translators: 1 = items selected, 2 = total items available */ ?>
            <?php printf(
                esc_html__( 'Selected: %1$s of %2$d', 'rentopian-sync' ),
                '<span data-role="selected-count">0</span>',
                (int) $rntp_total_items
            ); ?>
        </p>
        <?php if ( $qty_min > 0 || $qty_max_raw > 0 ) : ?>
            <p class="rntp-section-bounds">
                <?php
                if ( $qty_min > 0 && $qty_max_raw > 0 ) {
                    /* translators: 1 = min, 2 = max */
                    printf( esc_html__( 'Choose between %1$d and %2$d items in total.', 'rentopian-sync' ), (int) $qty_min, (int) $qty_max_raw );
                } elseif ( $qty_min > 0 ) {
                    /* translators: %d = min */
                    printf( esc_html__( 'Choose at least %d in total.', 'rentopian-sync' ), (int) $qty_min );
                } elseif ( $qty_max_raw > 0 ) {
                    /* translators: %d = max */
                    printf( esc_html__( 'Choose up to %d in total.', 'rentopian-sync' ), (int) $qty_max_raw );
                }
                ?>
                <span class="rntp-running-total" data-role="running-total">
                    (<?php echo esc_html__( 'selected: 0', 'rentopian-sync' ); ?>)
                </span>
            </p>
        <?php endif; ?>
    </div>

    <ul class="rntp-multi-grid" role="list">
        <?php foreach ( $items as $item_index => $item ) :
            $item_uid    = isset( $item['uid'] ) ? (string) $item['uid'] : '';
            $product_id  = isset( $item['product_id'] ) ? (int) $item['product_id'] : 0;
            $variant_id  = isset( $item['variant_id'] ) ? (int) $item['variant_id'] : 0;
            $inv_id      = isset( $item['inv_id'] ) ? (int) $item['inv_id'] : 0;
            $name        = isset( $item['name'] ) ? (string) $item['name'] : '';
            $price       = isset( $item['price'] ) ? $item['price'] : '';
            $image_html  = isset( $item['image'] ) ? (string) $item['image'] : '';
            $permalink   = isset( $item['permalink'] ) ? (string) $item['permalink'] : '';
            $is_default  = ! empty( $item['is_default'] );
            // Complete "{product} - {attributes}" label. Identical to $name
            // unless the admin shortened variant labels, in which case it
            // keeps the link / stepper descriptions unambiguous.
            $name_full   = isset( $item['name_full'] ) ? (string) $item['name_full'] : $name;

            $card_id = 'rntp-card-' . sanitize_html_class( $section['uid'] ) . '-' . $item_index;
            $option_key = '' !== $item_uid
                ? $item_uid
                : 'opt-' . $product_id . '-' . $variant_id . '-' . $item_index;
        ?>
            <li
                class="rntp-card"
                data-role="multi-card"
                <?php
                // First-paint selected state. A default item seeds a
                // positive initial quantity, so mark the card selected up
                // front; the JS keeps it in sync on every change.
                echo $is_default ? 'data-selected="1"' : '';
                ?>
                data-option-key="<?php echo esc_attr( $option_key ); ?>"
                data-item-uid="<?php echo esc_attr( $item_uid ); ?>"
                data-product-id="<?php echo esc_attr( $product_id ); ?>"
                data-variant-id="<?php echo esc_attr( $variant_id ); ?>"
                data-inv-id="<?php echo esc_attr( $inv_id ); ?>"
                data-price="<?php echo esc_attr( (string) $price ); ?>"
            >
                <?php if ( '' !== $image_html ) : ?>
                    <div class="rntp-card-image">
                        <?php
                        // WC's get_image() returns escaped HTML. wp_kses_post
                        // adds defence-in-depth without breaking the markup.
                        echo wp_kses_post( $image_html );
                        ?>
                    </div>
                <?php endif; ?>

                <div class="rntp-card-body">
                    <div class="rntp-card-name-row">
                        <?php if ( '' !== $permalink ) : ?>
                            <a href="<?php echo esc_url( $permalink ); ?>" class="rntp-card-name" target="_blank" rel="noopener noreferrer" title="<?php echo esc_attr( $name_full ); ?>">
                                <?php echo esc_html( $name ); ?>
                            </a>
                        <?php else : ?>
                            <span class="rntp-card-name" title="<?php echo esc_attr( $name_full ); ?>"><?php echo esc_html( $name ); ?></span>
                        <?php endif; ?>
                    </div>

                    <?php $price_label = rental_price_label( $price ); ?>
                    <?php if ( '' !== $price_label ) : ?>
                        <span class="rntp-card-price"><?php echo esc_html( $price_label ); ?></span>
                    <?php endif; ?>

                    <?php
                    // Per-item quantity badge — visible whenever the card is
                    // selected (qty > 0). The JS toggles the visible state
                    // via `data-selected` on the card and rewrites the
                    // number inside `[data-role="card-qty-value"]` so the
                    // customer always sees the live picked count without
                    // having to read the stepper input itself.
                    // Multi-select cards count UNITS — the group max bounds
                    // the unit SUM across cards. A default-selected card
                    // preselects the GROUP's default quantity (group_quantity,
                    // the admin's "Selected quantity" setting), clamped to the
                    // group max. The group quantity is the source of truth, so
                    // a group configured with Selected qty 4 / Max 4 lands its
                    // default item at 4 — not a hardcoded 1. Non-default cards
                    // start at 0.
                    $rntp_initial_qty = $is_default ? min( max( 1, $g_qty ), $qty_max ) : 0;
                    ?>
                    <span class="rntp-card-qty" data-role="card-qty">
                        <?php /* translators: %s = quantity number, rendered live by JS */ ?>
                        <?php printf(
                            esc_html__( 'Qty: %s', 'rentopian-sync' ),
                            '<span data-role="card-qty-value">' . (int) $rntp_initial_qty . '</span>'
                        ); ?>
                    </span>
                </div>

                <div class="rntp-card-footer">
                    <?php if ( '' !== $permalink ) : ?>
                        <?php /* External-link icon — boxed, shares the stepper's row so every selectable / grouped item exposes the same "open product details" affordance in the same position as the dropdown rows. */ ?>
                        <a
                            class="rntp-card-external-link"
                            href="<?php echo esc_url( $permalink ); ?>"
                            target="_blank"
                            rel="noopener noreferrer"
                            aria-label="<?php echo esc_attr( sprintf( __( 'Open %s in a new tab', 'rentopian-sync' ), $name_full ) ); ?>"
                        >
                            <svg class="rntp-card-external-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
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
                            id="<?php echo esc_attr( $card_id ); ?>"
                            type="number"
                            class="rntp-stepper-input"
                            data-role="qty-input"
                            value="<?php echo esc_attr( $rntp_initial_qty ); ?>"
                            min="0"
                            max="<?php echo esc_attr( $qty_max ); ?>"
                            step="1"
                            inputmode="numeric"
                            aria-label="<?php echo esc_attr( sprintf( /* translators: %s = item name */ __( 'Quantity for %s', 'rentopian-sync' ), $name_full ) ); ?>"
                        />
                        <button
                            type="button"
                            class="rntp-stepper-btn rntp-stepper-btn--plus"
                            data-action="increment"
                            aria-label="<?php esc_attr_e( 'Increase quantity', 'rentopian-sync' ); ?>"
                        >+</button>
                    </div>
                </div>
            </li>
        <?php endforeach; ?>
    </ul>

    <p class="rntp-section-error" data-role="section-error" role="alert" aria-live="polite" hidden></p>
</div>
