<?php
/**
 * Sets Module — Modern Single-Product View
 *
 * Outer wrapper rendered by `Rental_Sets_Renderer::maybe_render()` on
 * single product pages of sets when the layout option is `modern`. Hooks
 * inside `woocommerce_before_add_to_cart_form` so the configurator sits
 * directly above the WooCommerce add-to-cart form.
 *
 * Variables provided by the renderer:
 *
 * @var int      $rntp_set_id          WP product id of the set being rendered.
 * @var array    $rntp_sections        Ordered section list from `Section_Presenter`.
 * @var bool     $rntp_is_edit_mode    True when `?rental_edit_cart=KEY` is active.
 * @var callable $rntp_partial_lookup  fn(string $type): string|false — resolves a partial path.
 *
 * Each section in `$rntp_sections` is the array shape produced by
 * `Rental_Sets_Section_Presenter`:
 *
 *   [
 *     'type'               => 'fixed_item' | 'dropdown' | 'dropdown_qty' | 'multi_select',
 *     'origin'             => 'simple' | 'selectable' | 'group',
 *     'uid'                => string,
 *     'title'              => string,
 *     'description'        => string,
 *     'required'           => bool,
 *     'multiple_selection' => bool,
 *     'group_id'           => int,
 *     'group_quantity'     => int,
 *     'group_price'        => mixed (number|null|string),
 *     'quantity_min'       => int,
 *     'quantity_max'       => int,
 *     'items'              => [ ... display-ready items ... ],
 *     'parent_set_item_product_id' => int (selectable only),
 *   ]
 *
 * The partials read those keys directly via the local `$section` var.
 *
 * @package RentopianSync\Sets
 *
 * PHP requirement: 7.4+
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$rntp_edit_key = '';
if ( ! empty( $rntp_is_edit_mode ) && class_exists( 'Rental_Sets_Cart_Edit_Mode', false ) ) {
    $edit_inst = Rental_Sets_Cart_Edit_Mode::instance();
    if ( null !== $edit_inst ) {
        $rntp_edit_key = (string) $edit_inst->get_editing_key();
    }
}
?>
<div
    class="rental-sets-modern"
    data-set-id="<?php echo esc_attr( (int) $rntp_set_id ); ?>"
    data-edit-mode="<?php echo $rntp_is_edit_mode ? '1' : '0'; ?>"
>
    <?php if ( $rntp_is_edit_mode && '' !== $rntp_edit_key ) : ?>
        <input type="hidden" name="_rental_edit_cart_key" value="<?php echo esc_attr( $rntp_edit_key ); ?>" />
        <div class="rntp-edit-banner" role="status">
            <?php esc_html_e( 'You are editing an existing cart item. Submitting will replace it.', 'rentopian-sync' ); ?>
        </div>
    <?php endif; ?>

    <input type="hidden" name="_rental_set_from_product_page" value="1" />

    <?php
    /**
     * Top-level inline error rollup. The JS controllers populate this
     * from the client-mirror validator. Hidden by default; revealed
     * when JS sets data-has-errors="1" on the wrapper.
     */
    ?>
    <div class="rntp-errors" role="alert" aria-live="polite" hidden>
        <ul class="rntp-errors-list"></ul>
    </div>

    <?php foreach ( $rntp_sections as $section ) :
        if ( empty( $section['type'] ) ) {
            continue;
        }
        $partial = $rntp_partial_lookup( $section['type'] );
        if ( ! $partial ) {
            // Defensive: unknown section type with no partial — skip
            // silently rather than fataling the page.
            continue;
        }
        // The included partial reads the local `$section` variable.
        include $partial;
    endforeach; ?>

    <?php
    /**
     * Live summary — Total Price + Selected count. Updates on every
     * refreshState() in the JS controller.
     */
    $rntp_summary_partial = dirname( __FILE__ ) . '/partials/section-summary.php';
    if ( is_readable( $rntp_summary_partial ) ) {
        include $rntp_summary_partial;
    }
    ?>

    <?php
    /**
     * Hidden-input host. The JS rewrites this on every selection change
     * with the canonical `rental_set_selections[<group_uid>]...` and
     * `rental_add_ons[i]...` shapes the server expects on submit.
     */
    ?>
    <div class="rntp-hidden-inputs" data-role="hidden-inputs"></div>
</div>
