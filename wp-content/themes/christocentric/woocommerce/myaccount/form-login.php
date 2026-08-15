<?php
/**
 * One form: continue with Google or email. System decides login vs new account.
 *
 * @see woocommerce/templates/myaccount/form-login.php
 */

defined('ABSPATH') || exit;

$email = (! empty($_POST['email']) && is_string($_POST['email'])) ? wp_unslash($_POST['email']) : '';
$redirectTo = class_exists('CCR_Google_Auth') ? CCR_Google_Auth::requested_redirect() : '';
$toCheckout = $redirectTo !== '' && str_contains($redirectTo, 'checkout');
?>

<div class="ccr-auth" id="customer_login">
    <h1 class="ccr-auth-title"><?php esc_html_e('Continue to rent gear', 'christocentric'); ?></h1>
    <p class="ccr-auth-subtitle"><?php echo esc_html($toCheckout
        ? __('Sign in or create an account to complete checkout. If you already have an account we will sign you in.', 'christocentric')
        : __('If you already have an account we will sign you in. If you are new, we will create one.', 'christocentric')); ?></p>

    <?php do_action('woocommerce_before_customer_login_form'); ?>

    <form class="woocommerce-form ccr-auth-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" novalidate>
        <input type="hidden" name="action" value="ccr_account_continue">
        <?php if ($redirectTo !== '') : ?>
            <input type="hidden" name="redirect_to" value="<?php echo esc_attr($redirectTo); ?>">
        <?php endif; ?>
        <?php wp_nonce_field('ccr_account_continue'); ?>

        <p class="form-row form-row-wide">
            <label for="ccr_auth_email"><?php esc_html_e('Email', 'christocentric'); ?></label>
            <input type="email" class="woocommerce-Input input-text" name="email" id="ccr_auth_email" autocomplete="email" value="<?php echo esc_attr($email); ?>" required>
        </p>
        <p class="form-row form-row-wide">
            <label for="ccr_auth_password"><?php esc_html_e('Password', 'christocentric'); ?></label>
            <input class="woocommerce-Input input-text" type="password" name="password" id="ccr_auth_password" autocomplete="current-password" required>
        </p>
        <p class="form-row ccr-auth-row">
            <label class="woocommerce-form__label woocommerce-form__label-for-checkbox">
                <input class="woocommerce-form__input woocommerce-form__input-checkbox" name="rememberme" type="checkbox" id="rememberme" value="forever">
                <span><?php esc_html_e('Keep me signed in', 'christocentric'); ?></span>
            </label>
            <a class="ccr-auth-forgot" href="<?php echo esc_url(wp_lostpassword_url()); ?>"><?php esc_html_e('Forgot password?', 'christocentric'); ?></a>
        </p>
        <button type="submit" class="woocommerce-button button"><?php esc_html_e('Continue', 'christocentric'); ?></button>
    </form>
</div>

<?php do_action('woocommerce_after_customer_login_form'); ?>
