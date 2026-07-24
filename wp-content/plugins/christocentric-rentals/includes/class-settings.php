<?php

defined('ABSPATH') || exit;

final class CCR_Settings
{
    public static function init(): void
    {
        add_action('admin_menu', [self::class, 'register_menu']);
        add_action('admin_init', [self::class, 'register_settings']);
        add_action('woocommerce_order_actions', [self::class, 'add_mark_paid_action']);
        add_action('woocommerce_order_action_ccr_mark_paid', [self::class, 'handle_mark_paid']);
    }

    public static function register_menu(): void
    {
        add_submenu_page(
            'woocommerce',
            __('Christocentric Rentals', 'christocentric-rentals'),
            __('Christocentric Rentals', 'christocentric-rentals'),
            'manage_woocommerce',
            'christocentric-rentals',
            [self::class, 'render_page']
        );
    }

    public static function register_settings(): void
    {
        register_setting('ccr_settings', 'ccr_rentopian_api_key', ['sanitize_callback' => 'sanitize_text_field']);
        register_setting('ccr_settings', 'ccr_rentopian_base_url', ['sanitize_callback' => 'esc_url_raw']);
        register_setting('ccr_settings', 'ccr_pickup_cash_hold_hours', ['sanitize_callback' => 'absint']);
        register_setting('ccr_settings', 'ccr_online_hold_hours', ['sanitize_callback' => 'absint']);
        register_setting('ccr_settings', 'ccr_default_pickup_time', ['sanitize_callback' => 'sanitize_text_field']);
        register_setting('ccr_settings', 'ccr_default_return_time', ['sanitize_callback' => 'sanitize_text_field']);
        register_setting('ccr_settings', 'ccr_newsletter_send_welcome', ['sanitize_callback' => [self::class, 'sanitize_yes_no']]);
        register_setting('ccr_settings', 'ccr_newsletter_notify_admin', ['sanitize_callback' => [self::class, 'sanitize_yes_no']]);
        register_setting('ccr_settings', 'ccr_newsletter_notify_email', ['sanitize_callback' => 'sanitize_email']);
        register_setting('ccr_settings', 'ccr_newsletter_from_name', ['sanitize_callback' => 'sanitize_text_field']);
        register_setting('ccr_settings', 'ccr_newsletter_from_email', ['sanitize_callback' => 'sanitize_email']);
    }

    public static function sanitize_yes_no(mixed $value): string
    {
        return $value === 'yes' ? 'yes' : 'no';
    }

    public static function render_page(): void
    {
        if (! current_user_can('manage_woocommerce')) {
            return;
        }

        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Christocentric Rentals', 'christocentric-rentals'); ?></h1>
            <p><?php esc_html_e('Rental availability, pickup-cash holds, and Rentopian order sync. Mirrors the Laravel app settings.', 'christocentric-rentals'); ?></p>
            <form method="post" action="options.php">
                <?php settings_fields('ccr_settings'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="ccr_rentopian_api_key"><?php esc_html_e('Rentopian API key', 'christocentric-rentals'); ?></label></th>
                        <td><input type="password" class="regular-text" id="ccr_rentopian_api_key" name="ccr_rentopian_api_key" value="<?php echo esc_attr(get_option('ccr_rentopian_api_key', '')); ?>" autocomplete="off"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="ccr_rentopian_base_url"><?php esc_html_e('Rentopian base URL', 'christocentric-rentals'); ?></label></th>
                        <td><input type="url" class="regular-text" id="ccr_rentopian_base_url" name="ccr_rentopian_base_url" value="<?php echo esc_attr(get_option('ccr_rentopian_base_url', 'https://api.rentopian.com')); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="ccr_pickup_cash_hold_hours"><?php esc_html_e('Pickup-cash stock hold (hours)', 'christocentric-rentals'); ?></label></th>
                        <td><input type="number" min="1" id="ccr_pickup_cash_hold_hours" name="ccr_pickup_cash_hold_hours" value="<?php echo esc_attr(get_option('ccr_pickup_cash_hold_hours', 72)); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="ccr_online_hold_hours"><?php esc_html_e('Abandoned online checkout hold (hours)', 'christocentric-rentals'); ?></label></th>
                        <td><input type="number" min="1" id="ccr_online_hold_hours" name="ccr_online_hold_hours" value="<?php echo esc_attr(get_option('ccr_online_hold_hours', 2)); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="ccr_default_pickup_time"><?php esc_html_e('Default pickup time', 'christocentric-rentals'); ?></label></th>
                        <td><input type="time" id="ccr_default_pickup_time" name="ccr_default_pickup_time" value="<?php echo esc_attr(get_option('ccr_default_pickup_time', '09:00')); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="ccr_default_return_time"><?php esc_html_e('Default return time', 'christocentric-rentals'); ?></label></th>
                        <td><input type="time" id="ccr_default_return_time" name="ccr_default_return_time" value="<?php echo esc_attr(get_option('ccr_default_return_time', '17:00')); ?>"></td>
                    </tr>
                </table>
                <h2 class="title"><?php esc_html_e('Newsletter emails', 'christocentric-rentals'); ?></h2>
                <p class="description"><?php esc_html_e('Sent when someone subscribes via the footer form. Local dev: check Laragon Mailpit if installed (http://localhost:8025).', 'christocentric-rentals'); ?></p>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e('Welcome email', 'christocentric-rentals'); ?></th>
                        <td>
                            <input type="hidden" name="ccr_newsletter_send_welcome" value="no">
                            <label>
                                <input type="checkbox" name="ccr_newsletter_send_welcome" value="yes" <?php checked(get_option('ccr_newsletter_send_welcome', 'yes'), 'yes'); ?>>
                                <?php esc_html_e('Send a welcome email to new subscribers', 'christocentric-rentals'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Admin notification', 'christocentric-rentals'); ?></th>
                        <td>
                            <input type="hidden" name="ccr_newsletter_notify_admin" value="no">
                            <label>
                                <input type="checkbox" name="ccr_newsletter_notify_admin" value="yes" <?php checked(get_option('ccr_newsletter_notify_admin', 'yes'), 'yes'); ?>>
                                <?php esc_html_e('Email staff when someone new subscribes', 'christocentric-rentals'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="ccr_newsletter_notify_email"><?php esc_html_e('Notification email', 'christocentric-rentals'); ?></label></th>
                        <td>
                            <input type="email" class="regular-text" id="ccr_newsletter_notify_email" name="ccr_newsletter_notify_email" value="<?php echo esc_attr(get_option('ccr_newsletter_notify_email', self::default_support_email())); ?>">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="ccr_newsletter_from_name"><?php esc_html_e('From name', 'christocentric-rentals'); ?></label></th>
                        <td>
                            <input type="text" class="regular-text" id="ccr_newsletter_from_name" name="ccr_newsletter_from_name" value="<?php echo esc_attr(get_option('ccr_newsletter_from_name', get_bloginfo('name'))); ?>">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="ccr_newsletter_from_email"><?php esc_html_e('From email', 'christocentric-rentals'); ?></label></th>
                        <td>
                            <input type="email" class="regular-text" id="ccr_newsletter_from_email" name="ccr_newsletter_from_email" value="<?php echo esc_attr(get_option('ccr_newsletter_from_email', self::default_support_email())); ?>">
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
            <hr>
            <h2><?php esc_html_e('Rentopian status', 'christocentric-rentals'); ?></h2>
            <p><?php echo CCR_Rentopian_Sync::is_configured()
                ? esc_html__('Configured — paid orders will POST to Rentopian.', 'christocentric-rentals')
                : esc_html__('Not configured — add API key above.', 'christocentric-rentals'); ?></p>
            <p><?php esc_html_e('Pickup-cash orders sync only after you mark them paid in WooCommerce → Orders.', 'christocentric-rentals'); ?></p>
            <p>
                <?php
                printf(
                    /* translators: %s: admin menu link */
                    esc_html__('Newsletter subscribers: %s', 'christocentric-rentals'),
                    '<a href="' . esc_url(admin_url('admin.php?page=ccr-newsletter-subscribers')) . '">' . esc_html__('View list', 'christocentric-rentals') . '</a>'
                );
                ?>
            </p>
        </div>
        <?php
    }

    public static function default_support_email(): string
    {
        if (function_exists('ccr_site_config')) {
            $email = ccr_site_config('contact.support_email');

            if (is_string($email) && is_email($email)) {
                return $email;
            }
        }

        $admin = get_option('admin_email');

        return is_string($admin) && is_email($admin) ? $admin : 'support@christocentricrentals.com';
    }

    public static function add_mark_paid_action(array $actions): array
    {
        $actions['ccr_mark_paid'] = __('Mark paid & sync to Rentopian', 'christocentric-rentals');

        return $actions;
    }

    public static function handle_mark_paid(WC_Order $order): void
    {
        if ($order->is_paid()) {
            return;
        }

        $order->payment_complete();
        $order->update_status('processing', __('Marked paid at pickup.', 'christocentric-rentals'));
        do_action('ccr_order_marked_paid', $order->get_id());
    }
}
