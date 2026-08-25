<?php
/**
 * Sets Module — Fixed-Item Section Partial
 *
 * Renders a single included item from `_rental_set_items` (origin
 * `simple`, concrete inventory id, no `optional_items`).
 *
 * Quantity model :
 *   - Every simple item shows its quantity.
 *   - `group_quantity` is the MAX qty. min = 1 (required) or 0 (optional).
 *   - When max > the effective min a +/- stepper is rendered; otherwise a
 *     static "Qty: N" pill (nothing to adjust).
 *
 * Two render modes, chosen by `required`:
 *   - REQUIRED → static "Included" card + qty (stepper when max > 1).
 *     FixedSection reads `[data-role="section-state"]` (+ stepper).
 *   - OPTIONAL → custom-select shell ("No Thanks" / item) + qty stepper
 *     beside it (dropdown_qty layout). DropdownQtySection drives it; the
 *     `__no_thanks__` value excludes the item (and its addons); the
 *     stepper disables while "No Thanks" is selected.
 *
 * @var array $section  Single section array (see outer wrapper).
 *
 * @package RentopianSync\Sets
 *
 * PHP requirement: 7.4+
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$item   = isset( $section['items'][0] ) && is_array( $section['items'][0] ) ? $section['items'][0] : array();
$title  = isset( $section['title'] ) ? (string) $section['title'] : '';
$descr  = isset( $section['description'] ) ? (string) $section['description'] : '';
$qty    = isset( $section['group_quantity'] ) ? (int) $section['group_quantity'] : 1;
if ( $qty < 1 ) {
    $qty = 1;
}

$image_html  = isset( $item['image'] ) ? (string) $item['image'] : '';
$product_id  = isset( $item['product_id'] ) ? (int) $item['product_id'] : 0;
$variant_id  = isset( $item['variant_id'] ) ? (int) $item['variant_id'] : 0;
$inv_id      = isset( $item['inv_id'] ) ? (int) $item['inv_id'] : 0;
$item_name   = isset( $item['name'] ) ? (string) $item['name'] : $title;
$permalink   = isset( $item['permalink'] ) ? (string) $item['permalink'] : '';
$item_uid    = isset( $item['uid'] ) ? (string) $item['uid'] : ( isset( $section['uid'] ) ? (string) $section['uid'] : '' );
$item_price  = isset( $item['price'] ) ? (string) $item['price'] : '';

$rntp_section_required = ! empty( $section['required'] );
$rntp_no_thanks_label  = __( "No Thanks, I don't need this", 'rentopian-sync' );
$rntp_no_thanks_value  = '__no_thanks__';

// Optional item default: "No Thanks" unless Rentopian flags the item as
// selected-by-default (`is_default`). This keeps optional sections from
// silently adding a priced item the customer never chose, and matches the
// dropdown sections' opt-out-is-default behaviour. Required items are
// always included, so this flag only affects the optional branch.
$rntp_item_default     = ! empty( $item['is_default'] );
$rntp_default_no_thanks = ( ! $rntp_section_required ) && ! $rntp_item_default;
$rntp_select_id        = 'rntp-section-' . sanitize_html_class( $section['uid'] );
$rntp_qty_id           = $rntp_select_id . '-qty';

// Quantity model:
//   - REQUIRED (included) items show their qty as a STATIC number only.
//     The package fixes how many are included (e.g. a tent that fits
//     exactly 2 tables) — the customer can't raise or lower it.
//   - OPTIONAL items get a +/- stepper [1..max] so the customer can take
//     fewer than the configured max (e.g. fewer chairs when there's
//     space for more). Skipping entirely is the "No Thanks" path.
$rntp_qty_max  = $qty;
$rntp_qty_min  = $rntp_section_required ? 1 : 0;
// Stepper is OPTIONAL-only, and only when there's a range to pick from.
$rntp_has_step = ( ! $rntp_section_required ) && $rntp_qty_max > 1;

// Optional items dispatch to the dropdown controller; `dropdown_qty`
// when a stepper is shown (max > 1) so the JS binds it, plain `dropdown`
// otherwise. Required items stay `fixed_item` (static card, no stepper).
$rntp_section_type = $rntp_section_required
    ? 'fixed_item'
    : ( $rntp_has_step ? 'dropdown_qty' : 'dropdown' );

// Plain-text price label shared by the trigger + option rows. Empty while
// prices are hidden, which drops every price element below.
$rntp_price_plain = rental_price_label( $item_price );

/**
 * Render the +/- quantity stepper markup (shared shape with
 * section-dropdown-qty). `data-role="qty-input"` is what the JS reads.
 */
$rntp_render_stepper = function () use ( $rntp_qty_id, $rntp_qty_min, $rntp_qty_max ) {
    ?>
    <div class="rntp-stepper" data-role="qty-stepper">
        <button type="button" class="rntp-stepper-btn rntp-stepper-btn--minus" data-action="decrement" aria-label="<?php esc_attr_e( 'Decrease quantity', 'rentopian-sync' ); ?>">&minus;</button>
        <input
            id="<?php echo esc_attr( $rntp_qty_id ); ?>"
            type="number"
            class="rntp-stepper-input"
            data-role="qty-input"
            value="<?php echo esc_attr( $rntp_qty_max ); ?>"
            min="1"
            max="<?php echo esc_attr( $rntp_qty_max ); ?>"
            step="1"
            inputmode="numeric"
        />
        <button type="button" class="rntp-stepper-btn rntp-stepper-btn--plus" data-action="increment" aria-label="<?php esc_attr_e( 'Increase quantity', 'rentopian-sync' ); ?>">+</button>
    </div>
    <?php
};
?>
<div
    class="rntp-section rntp-section--fixed-item<?php echo $rntp_section_required ? '' : ' rntp-section--optional'; ?>"
    data-section-type="<?php echo esc_attr( $rntp_section_type ); ?>"
    data-section-uid="<?php echo esc_attr( $section['uid'] ); ?>"
    data-required="<?php echo $rntp_section_required ? '1' : '0'; ?>"
    data-origin="<?php echo esc_attr( $section['origin'] ); ?>"
    data-group-quantity="<?php echo esc_attr( $qty ); ?>"
    data-quantity-min="<?php echo esc_attr( max( 0, $rntp_qty_min ) ); ?>"
    data-quantity-max="<?php echo esc_attr( $rntp_qty_max ); ?>"
    data-multiple-selection="0"
>
    <div class="rntp-section-header">
        <?php
        // Shared title-bar. Fixed items use the 'included' badge mode
        // (required → "Included", optional → "Optional").
        $rntp_tb_required   = $rntp_section_required;
        $rntp_tb_badge_mode = 'included';
        include dirname( __FILE__ ) . '/section-titlebar.php';
        ?>
        <?php if ( '' !== $descr ) : ?>
            <p class="rntp-section-description"><?php echo esc_html( $descr ); ?></p>
        <?php endif; ?>
    </div>

    <?php if ( $rntp_section_required ) : ?>

        <?php /* REQUIRED — static "Included" card, item always in cart. */ ?>
        <div class="rntp-fixed-card">
            <?php if ( '' !== $image_html ) : ?>
                <div class="rntp-fixed-card-image">
                    <?php echo wp_kses_post( $image_html ); ?>
                </div>
            <?php endif; ?>

            <div class="rntp-fixed-card-body">
                <?php
                // The item name is already shown as the bold section title
                // in the header, so it is not repeated on the card.
                ?>
                <?php if ( '' !== $rntp_price_plain ) : ?>
                    <span class="rntp-fixed-card-price"><?php echo esc_html( $rntp_price_plain ); ?></span>
                <?php endif; ?>

                <?php
                // Quantity (#1). Required/included items show the package
                // quantity as a STATIC number — fixed by the package, not
                // adjustable. (Optional items get a stepper in the other
                // branch below.)
                ?>
                <span class="rntp-fixed-card-qty">
                    <?php echo esc_html( sprintf( /* translators: %d quantity */ __( 'Qty: %d', 'rentopian-sync' ), $rntp_qty_max ) ); ?>
                </span>
            </div>

            <?php if ( '' !== $permalink ) : ?>
                <?php /* External-link icon — same boxed affordance as every other item, opens the product page in a new tab. */ ?>
                <a
                    class="rntp-section-external-link"
                    data-role="section-external-link"
                    href="<?php echo esc_url( $permalink ); ?>"
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
        </div>

        <?php
        // Static selection markers. FixedSection reads these; when a
        // stepper is present it reads the live stepper value instead of
        // data-quantity. data-quantity-min/max bound the stepper.
        ?>
        <div
            class="rntp-section-state"
            data-role="section-state"
            data-item-uid="<?php echo esc_attr( $item_uid ); ?>"
            data-product-id="<?php echo esc_attr( $product_id ); ?>"
            data-variant-id="<?php echo esc_attr( $variant_id ); ?>"
            data-inv-id="<?php echo esc_attr( $inv_id ); ?>"
            data-quantity="<?php echo esc_attr( $rntp_qty_max ); ?>"
            data-price="<?php echo esc_attr( (string) $item_price ); ?>"
            hidden
        ></div>

    <?php else : ?>

        <?php
        // OPTIONAL — custom-select shell ("No Thanks" / item) with the
        // price shown bold INSIDE the option (#5: no separate
        // "Selected price" line). A qty stepper sits beside the shell
        // (dropdown_qty layout) when the qty is adjustable.
        ?>
        <select
            id="<?php echo esc_attr( $rntp_select_id ); ?>"
            class="rntp-section-select"
            data-role="section-select"
        >
            <option value="<?php echo esc_attr( $rntp_no_thanks_value ); ?>" data-no-thanks="1" <?php selected( $rntp_default_no_thanks, true ); ?>><?php echo esc_html( $rntp_no_thanks_label ); ?></option>
            <option
                value="<?php echo esc_attr( $item_uid ); ?>"
                data-item-uid="<?php echo esc_attr( $item_uid ); ?>"
                data-product-id="<?php echo esc_attr( $product_id ); ?>"
                data-variant-id="<?php echo esc_attr( $variant_id ); ?>"
                data-inv-id="<?php echo esc_attr( $inv_id ); ?>"
                data-quantity="<?php echo esc_attr( $rntp_qty_max ); ?>"
                data-price="<?php echo esc_attr( (string) $item_price ); ?>"
                <?php selected( ! $rntp_default_no_thanks, true ); ?>
            ><?php echo esc_html( '' !== $rntp_price_plain ? $item_name . ' — ' . $rntp_price_plain : $item_name ); ?></option>
        </select>

        <div class="rntp-dropdown-row">
            <div class="rntp-cs<?php echo $rntp_default_no_thanks ? ' rntp-cs--no-thanks' : ''; ?>" data-role="custom-select" data-for-select="<?php echo esc_attr( $rntp_select_id ); ?>">
                <button type="button" class="rntp-cs-trigger" aria-haspopup="listbox" aria-expanded="false" aria-label="<?php echo esc_attr( $title ); ?>">
                    <span class="rntp-cs-thumb" data-role="cs-trigger-thumb">
                        <?php if ( '' !== $image_html && ! $rntp_default_no_thanks ) : ?>
                            <?php echo wp_kses_post( $image_html ); ?>
                        <?php else : ?>
                            <span class="rntp-thumb-placeholder" aria-hidden="true"></span>
                        <?php endif; ?>
                    </span>
                    <span class="rntp-cs-label" data-role="cs-trigger-label">
                        <?php if ( $rntp_default_no_thanks ) : ?>
                            <span class="rntp-cs-name"><?php echo esc_html( $rntp_no_thanks_label ); ?></span>
                        <?php else : ?>
                            <span class="rntp-cs-name" title="<?php echo esc_attr( $item_name ); ?>"><?php echo esc_html( $item_name ); ?></span>
                            <?php if ( '' !== $rntp_price_plain ) : ?>
                                <span class="rntp-cs-price"><?php echo esc_html( $rntp_price_plain ); ?></span>
                            <?php endif; ?>
                        <?php endif; ?>
                    </span>
                    <span class="rntp-cs-chevron" aria-hidden="true">&#9662;</span>
                </button>
                <ul class="rntp-cs-panel" role="listbox" hidden>
                    <li class="rntp-cs-option rntp-cs-option--no-thanks" role="option" data-value="<?php echo esc_attr( $rntp_no_thanks_value ); ?>" data-no-thanks="1" aria-selected="<?php echo $rntp_default_no_thanks ? 'true' : 'false'; ?>">
                        <span class="rntp-cs-option-text">
                            <span class="rntp-cs-name"><?php echo esc_html( $rntp_no_thanks_label ); ?></span>
                        </span>
                    </li>
                    <li class="rntp-cs-option" role="option" data-value="<?php echo esc_attr( $item_uid ); ?>" aria-selected="<?php echo $rntp_default_no_thanks ? 'false' : 'true'; ?>">
                        <span class="rntp-cs-thumb">
                            <?php if ( '' !== $image_html ) : ?>
                                <?php echo wp_kses_post( $image_html ); ?>
                            <?php else : ?>
                                <span class="rntp-thumb-placeholder" aria-hidden="true"></span>
                            <?php endif; ?>
                        </span>
                        <span class="rntp-cs-option-text">
                            <span class="rntp-cs-name" title="<?php echo esc_attr( $item_name ); ?>"><?php echo esc_html( $item_name ); ?></span>
                            <?php if ( '' !== $rntp_price_plain ) : ?>
                                <span class="rntp-cs-price"><?php echo esc_html( $rntp_price_plain ); ?></span>
                            <?php endif; ?>
                        </span>
                        <?php if ( '' !== $permalink ) : ?>
                            <a
                                class="rntp-cs-external-link"
                                data-role="external-link"
                                href="<?php echo esc_url( $permalink ); ?>"
                                target="_blank"
                                rel="noopener noreferrer"
                                aria-label="<?php echo esc_attr( sprintf( __( 'Open %s in a new tab', 'rentopian-sync' ), $item_name ) ); ?>"
                            >
                                <svg class="rntp-cs-external-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
                                    <path d="M14 3h7v7" />
                                    <path d="M10 14L21 3" />
                                    <path d="M21 14v5a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5" />
                                </svg>
                            </a>
                        <?php endif; ?>
                    </li>
                </ul>
            </div>

            <?php if ( '' !== $permalink ) : ?>
                <a
                    class="rntp-section-external-link"
                    data-role="section-external-link"
                    href="<?php echo esc_url( $permalink ); ?>"
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

            <?php
            // Qty stepper beside the dropdown (#4 / #6). Rendered only
            // when adjustable; the JS disables it while "No Thanks" is
            // the active selection.
            if ( $rntp_has_step ) {
                $rntp_render_stepper();
            }
            ?>
        </div>

        <?php if ( ! $rntp_has_step ) : ?>
            <?php
            // qty == 1 optional item: surface the qty (#4). Just "Qty: N"
            // — no "when selected" wording. The JS hides this note while
            // "No Thanks" is the active selection (data-role hook), so it
            // only shows when the item is actually chosen.
            ?>
            <p class="rntp-section-bounds" data-role="optional-qty-note"><?php echo esc_html( sprintf( /* translators: %d quantity */ __( 'Qty: %d', 'rentopian-sync' ), $rntp_qty_max ) ); ?></p>
        <?php endif; ?>

        <p class="rntp-section-error" data-role="section-error" role="alert" aria-live="polite" hidden></p>

    <?php endif; ?>

    <?php
    // Addons block — rendered for both modes when the set item carries any.
    if ( ! empty( $section['addons'] ) && is_array( $section['addons'] ) ) {
        $addons_partial = dirname( __FILE__ ) . '/section-addons.php';
        if ( is_readable( $addons_partial ) ) {
            include $addons_partial;
        }
    }
    ?>
</div>
