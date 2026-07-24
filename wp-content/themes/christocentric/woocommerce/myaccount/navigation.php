<?php
/**
 * Account navigation — horizontal pill tabs like Laravel account._nav.
 *
 * @see woocommerce/templates/myaccount/navigation.php
 */

defined('ABSPATH') || exit;

do_action('woocommerce_before_account_navigation');
?>
<nav class="woocommerce-MyAccount-navigation ccr-account-nav" aria-label="<?php esc_attr_e('Account pages', 'woocommerce'); ?>">
    <div class="ccr-account-nav-list">
        <?php foreach (wc_get_account_menu_items() as $endpoint => $label) : ?>
            <?php
            $is_active = wc_is_current_account_menu_item($endpoint);
            $classes = 'ccr-account-nav-link' . ($is_active ? ' is-active' : '');
            ?>
            <a
                href="<?php echo esc_url(wc_get_account_endpoint_url($endpoint)); ?>"
                class="<?php echo esc_attr($classes); ?>"
                <?php echo $is_active ? 'aria-current="page"' : ''; ?>
            >
                <?php echo esc_html($label); ?>
            </a>
        <?php endforeach; ?>
    </div>
</nav>
<?php do_action('woocommerce_after_account_navigation'); ?>
