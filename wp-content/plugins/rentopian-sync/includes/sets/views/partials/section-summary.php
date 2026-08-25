<?php
/**
 * Sets Module — Summary Partial
 *
 * Rendered ONCE at the bottom of the modern set configurator, AFTER all
 * section partials. Displays a live-updating rollup of the customer's
 * current configuration:
 *
 *   - Total Price — sum of every chosen item's effective per-unit price
 *                   × its quantity × the parent set quantity. Mirrors
 *                   the same engine resolution the cart subtotal uses,
 *                   so the configurator number matches what WC will
 *                   bill once the customer adds the set.
 *   - Selected    — total count of chosen items across all sections
 *                   (sum of picked qty across simples, selectables,
 *                   groups, and addons), scaled by parent qty.
 *
 * The PHP renders the static frame and seeds the currency symbol from
 * WooCommerce so the JS doesn't have to localize. JS owns the live
 * updates — every refreshState() recomputes both values and writes
 * them into the `[data-role="summary-amount"]` and
 * `[data-role="summary-count"]` placeholders below.
 *
 * @package RentopianSync\Sets
 *
 * PHP requirement: 7.4+
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Currency symbol for the initial paint. JS writes only the numeric
// amount into `[data-role="summary-amount"]`; the symbol span stays.
$rntp_currency_symbol = function_exists( 'get_woocommerce_currency_symbol' )
    ? get_woocommerce_currency_symbol()
    : '$';

// The total row is dropped entirely while prices are hidden; the JS finds
// no `[data-role="summary-amount"]` and leaves the count row alone.
$rntp_hide_prices = function_exists( 'rental_prices_are_hidden' ) && rental_prices_are_hidden( 'catalog' );
?>
<div class="rntp-summary" data-role="summary">
    <?php if ( ! $rntp_hide_prices ) : ?>
        <div class="rntp-summary-row rntp-summary-row--total">
            <span class="rntp-summary-label">
                <?php esc_html_e( 'Total Price:', 'rentopian-sync' ); ?>
            </span>
            <span class="rntp-summary-value">
                <span class="rntp-summary-currency"><?php echo esc_html( $rntp_currency_symbol ); ?></span><span data-role="summary-amount">0.00</span>
            </span>
        </div>
    <?php endif; ?>
    <div class="rntp-summary-row rntp-summary-row--count">
        <span class="rntp-summary-label">
            <?php esc_html_e( 'Selected:', 'rentopian-sync' ); ?>
        </span>
        <span class="rntp-summary-value">
            <span data-role="summary-count">0</span>
        </span>
    </div>
</div>
