<?php
/**
 * Sets Module — Dropdown Section Partial
 *
 * Single-select section. Two origins reach this partial:
 *
 *   - `group` with `multiple_selection=0` and effective qty == 1 (a
 *     composite group where exactly one child is chosen).
 *   - `selectable` (entity-2 selectable item presented as a synthetic
 *     single-select group; its synthetic UID is `sel-{set_id}-{prod_id}`).
 *
 * The JS controller listens for `change` on the `<select>`, finds the
 * matching item descriptor in the data-* index, and writes the canonical
 * selection through to the hidden-input host.
 *
 * Locals available:
 *
 * @var array $section  Section array (see outer wrapper for full shape).
 *                      Relevant keys: uid, title, description, required,
 *                      group_id, group_quantity, items[]. For selectable
 *                      origin, parent_set_item_product_id is also set.
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
$origin    = isset( $section['origin'] ) ? (string) $section['origin'] : 'group';
$select_id = 'rntp-section-' . sanitize_html_class( $section['uid'] );
?>
<div
    class="rntp-section rntp-section--dropdown"
    data-section-type="dropdown"
    data-section-uid="<?php echo esc_attr( $section['uid'] ); ?>"
    data-section-origin="<?php echo esc_attr( $origin ); ?>"
    data-required="<?php echo $required ? '1' : '0'; ?>"
    data-group-id="<?php echo esc_attr( (int) ( $section['group_id'] ?? 0 ) ); ?>"
    data-group-quantity="<?php echo esc_attr( (int) ( $section['group_quantity'] ?? 1 ) ); ?>"
    data-multiple-selection="0"
    data-parent-product-id="<?php echo esc_attr( (int) ( $section['parent_set_item_product_id'] ?? 0 ) ); ?>"
>
    <div class="rntp-section-header">
        <?php
        // Shared title-bar. Choice sections use 'marker' mode
        // (required → `*`, optional → "Optional" badge).
        $rntp_tb_required   = $required;
        $rntp_tb_badge_mode = 'marker';
        include dirname( __FILE__ ) . '/section-titlebar.php';
        ?>
        <?php if ( '' !== $descr ) : ?>
            <p class="rntp-section-description"><?php echo esc_html( $descr ); ?></p>
        <?php endif; ?>
    </div>

    <?php
    // "No Thanks" opt-out. Non-required sections must
    // expose a literal "No Thanks, I don't need this" option that, when
    // chosen, excludes this section from the cart submission.
    $rntp_no_thanks_label = __( "No Thanks, I don't need this", 'rentopian-sync' );
    $rntp_no_thanks_value = '__no_thanks__';

    // Resolve the source-configured default item (is_default). A configured
    // default is honoured on load for BOTH required and optional sections.
    // "No Thanks" is pre-selected ONLY for an optional section with no
    // configured default — it must never override one.
    $rntp_default_item = null;
    foreach ( $items as $rntp_cand ) {
        if ( ! empty( $rntp_cand['is_default'] ) ) {
            $rntp_default_item = $rntp_cand;
            break;
        }
    }
    $rntp_use_no_thanks = ( ! $required ) && ( null === $rntp_default_item );
    ?>
    <select
        id="<?php echo esc_attr( $select_id ); ?>"
        class="rntp-section-select"
        data-role="section-select"
        <?php echo $required ? 'aria-required="true"' : ''; ?>
    >
        <?php if ( ! $required ) : ?>
            <?php
            // Optional section → "No Thanks" stays available so the customer
            // can decline, but it is pre-selected ONLY when no source default
            // is configured. A configured `is_default` item wins (below).
            ?>
            <option value="<?php echo esc_attr( $rntp_no_thanks_value ); ?>" data-no-thanks="1" <?php selected( $rntp_use_no_thanks, true ); ?>><?php echo esc_html( $rntp_no_thanks_label ); ?></option>
        <?php elseif ( empty( $items ) ) : ?>
            <option value=""><?php esc_html_e( '— Choose an option —', 'rentopian-sync' ); ?></option>
        <?php endif; ?>

        <?php foreach ( $items as $item_index => $item ) :
            $item_uid    = isset( $item['uid'] ) ? (string) $item['uid'] : '';
            $product_id  = isset( $item['product_id'] ) ? (int) $item['product_id'] : 0;
            $variant_id  = isset( $item['variant_id'] ) ? (int) $item['variant_id'] : 0;
            $inv_id      = isset( $item['inv_id'] ) ? (int) $item['inv_id'] : 0;
            $name        = isset( $item['name'] ) ? (string) $item['name'] : '';
            $price       = isset( $item['price'] ) ? $item['price'] : '';
            $is_default  = ! empty( $item['is_default'] );
            $option_qty  = isset( $item['quantity'] ) ? (int) $item['quantity'] : 1;

            // Stable per-option key. `uid` wins; fall back to a synthetic
            // key so options without a server-issued uid (selectable
            // variants) still get a unique value.
            $option_key  = '' !== $item_uid
                ? $item_uid
                : 'opt-' . $product_id . '-' . $variant_id . '-' . $item_index;
        ?>
            <?php
            // Plain-text currency formatting for the <option> label.
            // <option> elements can't contain HTML, so the bolded
            // WC-style render lives in the .rntp-selected-price
            // element below the <select>; the option itself just gets
            // a readable "Name — $123.45". Empty while prices are hidden.
            $price_plain = rental_price_label( $price );
            ?>
            <option
                value="<?php echo esc_attr( $option_key ); ?>"
                data-item-uid="<?php echo esc_attr( $item_uid ); ?>"
                data-product-id="<?php echo esc_attr( $product_id ); ?>"
                data-variant-id="<?php echo esc_attr( $variant_id ); ?>"
                data-inv-id="<?php echo esc_attr( $inv_id ); ?>"
                data-quantity="<?php echo esc_attr( $option_qty ); ?>"
                data-price="<?php echo esc_attr( (string) $price ); ?>"
                <?php
                // Pre-select the configured default item. Optional sections
                // with no default fall through to "No Thanks" (selected
                // above), so only mark the default when it actually applies.
                if ( ! $rntp_use_no_thanks ) {
                    selected( $is_default, true );
                }
                ?>
            >
                <?php echo esc_html( $name ); ?>
                <?php if ( '' !== $price_plain ) : ?>
                    <?php echo esc_html( ' — ' . $price_plain ); ?>
                <?php endif; ?>
                <?php if ( $option_qty > 1 ) : ?>
                    <?php /* translators: %d = per-set quantity */ ?>
                    <?php echo esc_html( ' ' . sprintf( __( '(Qty: %d)', 'rentopian-sync' ), $option_qty ) ); ?>
                <?php endif; ?>
            </option>
        <?php endforeach; ?>
    </select>

    <?php
    // Custom dropdown shell — wraps the native <select> with a panel
    // that renders each option as a thumbnail + name + price + qty.
    // Native <option> markup cannot contain images, so this is the
    // only way to surface them while still letting the form submit
    // through the native select (we keep it as the value/source of
    // truth and dispatch 'change' on it when the customer picks).
    //
    // Resolve the initially-rendered option (the one with `is_default`
    // or, failing that, the first in the list) so the trigger button
    // shows a meaningful thumb + label on first paint, before JS runs.
    // Optional sections default to "No Thanks", so the trigger must show
    // the opt-out label (no thumb / price) on first paint — NOT the first
    // positive item. Required sections resolve to their default item, then
    // the first item as a fallback.
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
    $rntp_initial_qty   = is_array( $rntp_initial ) && isset( $rntp_initial['quantity'] ) ? (int) $rntp_initial['quantity'] : 1;
    $rntp_initial_price_plain = is_array( $rntp_initial ) && isset( $rntp_initial['price'] )
        ? rental_price_label( $rntp_initial['price'] )
        : '';
    $rntp_initial_permalink = is_array( $rntp_initial ) && ! empty( $rntp_initial['permalink'] ) ? (string) $rntp_initial['permalink'] : '';

    // External-link target = the initial selection's permalink. A sibling
    // is substituted ONLY when there is no real initial pick (the section
    // defaults to "No Thanks" or has no default) so the icon still opens a
    // relevant page. When a real item is selected but has no public page
    // (a hidden add-on / hidden-on-website item), the icon stays absent —
    // it is never pointed at a different item. The JS keeps the href in
    // sync with whichever option is picked, applying the same rule.
    $rntp_link_permalink = $rntp_initial_permalink;
    if ( '' === $rntp_link_permalink && ( $rntp_initial_no_thanks || null === $rntp_initial ) ) {
        foreach ( $items as $rntp_cand ) {
            if ( ! empty( $rntp_cand['permalink'] ) ) {
                $rntp_link_permalink = (string) $rntp_cand['permalink'];
                break;
            }
        }
    }
    ?>
    <div class="rntp-dropdown-row">
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
                <?php if ( '' !== $rntp_initial_price_plain || $rntp_initial_qty > 1 ) : ?>
                    <span class="rntp-cs-meta">
                        <?php if ( '' !== $rntp_initial_price_plain ) : ?>
                            <span class="rntp-cs-price"><?php echo esc_html( $rntp_initial_price_plain ); ?></span>
                        <?php endif; ?>
                        <?php if ( $rntp_initial_qty > 1 ) : ?>
                            <?php if ( '' !== $rntp_initial_price_plain ) : ?><span class="rntp-cs-sep" aria-hidden="true">·</span><?php endif; ?>
                            <span class="rntp-cs-qty"><?php echo esc_html( sprintf( __( 'Qty: %d', 'rentopian-sync' ), $rntp_initial_qty ) ); ?></span>
                        <?php endif; ?>
                    </span>
                <?php endif; ?>
            </span>
            <span class="rntp-cs-chevron" aria-hidden="true">&#9662;</span>
        </button>
        <ul class="rntp-cs-panel" role="listbox" hidden>
            <?php if ( ! $required ) : ?>
                <li class="rntp-cs-option rntp-cs-option--no-thanks" role="option" data-value="<?php echo esc_attr( $rntp_no_thanks_value ); ?>" data-no-thanks="1" aria-selected="<?php echo $rntp_use_no_thanks ? 'true' : 'false'; ?>">
                    <span class="rntp-cs-option-text">
                        <span class="rntp-cs-name"><?php echo esc_html( $rntp_no_thanks_label ); ?></span>
                    </span>
                </li>
            <?php endif; ?>
            <?php foreach ( $items as $item_index => $item ) :
                $item_uid    = isset( $item['uid'] ) ? (string) $item['uid'] : '';
                $product_id  = isset( $item['product_id'] ) ? (int) $item['product_id'] : 0;
                $variant_id  = isset( $item['variant_id'] ) ? (int) $item['variant_id'] : 0;
                $name        = isset( $item['name'] ) ? (string) $item['name'] : '';
                $price       = isset( $item['price'] ) ? $item['price'] : '';
                $option_qty  = isset( $item['quantity'] ) ? (int) $item['quantity'] : 1;
                $image_html  = isset( $item['image'] ) ? (string) $item['image'] : '';
                $permalink   = isset( $item['permalink'] ) ? (string) $item['permalink'] : '';
                $is_default  = ! empty( $item['is_default'] );
                // Complete "{product} - {attributes}" label. Identical to
                // $name unless the admin shortened variant labels, in which
                // case it keeps the link description unambiguous.
                $name_full   = isset( $item['name_full'] ) ? (string) $item['name_full'] : $name;

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
                        <?php if ( '' !== $price_plain || $option_qty > 1 ) : ?>
                            <span class="rntp-cs-meta">
                                <?php if ( '' !== $price_plain ) : ?>
                                    <span class="rntp-cs-price"><?php echo esc_html( $price_plain ); ?></span>
                                <?php endif; ?>
                                <?php if ( $option_qty > 1 ) : ?>
                                    <?php if ( '' !== $price_plain ) : ?><span class="rntp-cs-sep" aria-hidden="true">·</span><?php endif; ?>
                                    <span class="rntp-cs-qty"><?php echo esc_html( sprintf( __( 'Qty: %d', 'rentopian-sync' ), $option_qty ) ); ?></span>
                                <?php endif; ?>
                            </span>
                        <?php endif; ?>
                    </span>
                    <?php if ( '' !== $permalink ) : ?>
                        <?php /* External-link icon — clicking opens the product page in a new tab without selecting the option (the panel click handler ignores [data-role="external-link"]). */ ?>
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
    <?php if ( '' !== $rntp_link_permalink ) : ?>
        <?php
        // Trigger-level external-link icon — sibling of the custom
        // shell, opens the CURRENTLY SELECTED option's product page in
        // a new tab. The JS controller updates `href` on every
        // selection change via updateSectionExternalLink() so the
        // icon always points at whichever item is picked.
        ?>
        <a
            class="rntp-section-external-link"
            data-role="section-external-link"
            href="<?php echo esc_url( $rntp_link_permalink ); ?>"
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

    <p class="rntp-section-error" data-role="section-error" role="alert" aria-live="polite" hidden></p>

    <?php
    // Addons block — rendered only when the set item carries any.
    // Included via plain require so the locally-scoped $section
    // variable is available inside the partial.
    if ( ! empty( $section['addons'] ) && is_array( $section['addons'] ) ) {
        $addons_partial = dirname( __FILE__ ) . '/section-addons.php';
        if ( is_readable( $addons_partial ) ) {
            include $addons_partial;
        }
    }
    ?>
</div>
