<?php
/**
 * My Account layout — matches Laravel account pages.
 *
 * @see woocommerce/templates/myaccount/my-account.php
 */

defined('ABSPATH') || exit;

ccr_account_hero();
?>
<div class="container-site py-10 max-w-4xl">
    <?php do_action('woocommerce_account_navigation'); ?>
    <div class="woocommerce-MyAccount-content">
        <?php do_action('woocommerce_account_content'); ?>
        <?php ccr_account_logout_link(); ?>
    </div>
</div>
