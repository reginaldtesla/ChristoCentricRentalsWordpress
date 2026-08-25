<?php

defined('ABSPATH') || exit;

final class CCR_Settings
{
    public static function init(): void
    {
        add_action('admin_menu', [self::class, 'register_menu']);
        add_action('admin_init', [self::class, 'register_settings']);
        add_action('admin_post_ccr_smtp_test', [self::class, 'handle_smtp_test']);
        add_action('admin_post_ccr_setup_ghana_tax', [self::class, 'handle_setup_ghana_tax']);
        add_action('admin_post_ccr_toggle_taxes', [self::class, 'handle_toggle_taxes']);
        add_action('woocommerce_order_actions', [self::class, 'add_mark_paid_action']);
        add_action('woocommerce_order_action_ccr_mark_paid', [self::class, 'handle_mark_paid']);
        add_filter('woocommerce_email_from_address', [self::class, 'contact_email'], 20);
        add_filter('woocommerce_email_recipient_new_order', [self::class, 'force_contact_recipient'], 20);
        add_filter('woocommerce_email_recipient_cancelled_order', [self::class, 'force_contact_recipient'], 20);
        add_filter('woocommerce_email_recipient_failed_order', [self::class, 'force_contact_recipient'], 20);
        add_action('init', [self::class, 'maybe_sync_contact_email'], 5);
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
        register_setting('ccr_settings', 'ccr_rentopian_website', ['sanitize_callback' => 'esc_url_raw']);
        register_setting('ccr_settings', 'ccr_rentopian_pull_products', ['sanitize_callback' => [self::class, 'sanitize_yes_no']]);
        register_setting('ccr_settings', 'ccr_rentopian_push_products', ['sanitize_callback' => [self::class, 'sanitize_yes_no']]);
        register_setting('ccr_settings', 'ccr_pickup_cash_hold_hours', ['sanitize_callback' => 'absint']);
        register_setting('ccr_settings', 'ccr_online_hold_hours', ['sanitize_callback' => 'absint']);
        register_setting('ccr_settings', 'ccr_default_pickup_time', ['sanitize_callback' => 'sanitize_text_field']);
        register_setting('ccr_settings', 'ccr_default_return_time', ['sanitize_callback' => 'sanitize_text_field']);
        register_setting('ccr_settings', 'ccr_latest_return_time', ['sanitize_callback' => 'sanitize_text_field']);
        register_setting('ccr_settings', 'ccr_newsletter_send_welcome', ['sanitize_callback' => [self::class, 'sanitize_yes_no']]);
        register_setting('ccr_settings', 'ccr_newsletter_notify_admin', ['sanitize_callback' => [self::class, 'sanitize_yes_no']]);
        register_setting('ccr_settings', 'ccr_newsletter_notify_email', ['sanitize_callback' => 'sanitize_email']);
        register_setting('ccr_settings', 'ccr_newsletter_from_name', ['sanitize_callback' => 'sanitize_text_field']);
        register_setting('ccr_settings', 'ccr_newsletter_from_email', ['sanitize_callback' => 'sanitize_email']);
        register_setting('ccr_settings', 'ccr_mailchimp_api_key', ['sanitize_callback' => 'sanitize_text_field']);
        register_setting('ccr_settings', 'ccr_mailchimp_list_id', ['sanitize_callback' => 'sanitize_text_field']);
        register_setting('ccr_settings', 'ccr_newsletter_webhook_url', ['sanitize_callback' => 'esc_url_raw']);
        register_setting('ccr_settings', 'ccr_smtp_enabled', ['sanitize_callback' => [self::class, 'sanitize_yes_no']]);
        register_setting('ccr_settings', 'ccr_smtp_host', ['sanitize_callback' => 'sanitize_text_field']);
        register_setting('ccr_settings', 'ccr_smtp_port', ['sanitize_callback' => 'absint']);
        register_setting('ccr_settings', 'ccr_smtp_encryption', ['sanitize_callback' => 'sanitize_text_field']);
        register_setting('ccr_settings', 'ccr_smtp_auth', ['sanitize_callback' => [self::class, 'sanitize_yes_no']]);
        register_setting('ccr_settings', 'ccr_smtp_user', ['sanitize_callback' => 'sanitize_text_field']);
        register_setting('ccr_settings', 'ccr_smtp_pass', ['sanitize_callback' => 'sanitize_text_field']);
        register_setting('ccr_settings', 'ccr_smtp_from_name', ['sanitize_callback' => 'sanitize_text_field']);
        register_setting('ccr_settings', 'ccr_smtp_from_email', ['sanitize_callback' => 'sanitize_email']);
        register_setting('ccr_settings', 'ccr_grace_minutes', ['sanitize_callback' => 'absint']);
        register_setting('ccr_settings', 'ccr_daily_rate_multiplier', ['sanitize_callback' => [self::class, 'sanitize_float']]);
        register_setting('ccr_settings', 'ccr_due_soon_hours', ['sanitize_callback' => 'absint']);
        register_setting('ccr_settings', 'ccr_global_discount_percent', ['sanitize_callback' => [self::class, 'sanitize_float']]);
        register_setting('ccr_settings', 'ccr_global_discount_ends_at', ['sanitize_callback' => 'sanitize_text_field']);
        register_setting('ccr_settings', 'ccr_banner_title', ['sanitize_callback' => 'sanitize_text_field']);
        register_setting('ccr_settings', 'ccr_banner_line_1', ['sanitize_callback' => 'sanitize_text_field']);
        register_setting('ccr_settings', 'ccr_banner_line_2', ['sanitize_callback' => 'sanitize_text_field']);
        register_setting('ccr_settings', 'ccr_banner_cta', ['sanitize_callback' => 'sanitize_text_field']);
        register_setting('ccr_settings', 'ccr_google_enabled', ['sanitize_callback' => [self::class, 'sanitize_yes_no']]);
        register_setting('ccr_settings', 'ccr_google_client_id', ['sanitize_callback' => 'sanitize_text_field']);
        register_setting('ccr_settings', 'ccr_google_client_secret', ['sanitize_callback' => 'sanitize_text_field']);
        register_setting('ccr_settings', 'ccr_whatsapp', ['sanitize_callback' => 'sanitize_text_field']);
    }

    public static function sanitize_yes_no(mixed $value): string
    {
        if (is_array($value)) {
            $value = end($value);
        }

        return $value === 'yes' ? 'yes' : 'no';
    }

    public static function sanitize_float(mixed $value): float
    {
        return max(0, (float) $value);
    }

    public static function render_page(): void
    {
        if (! current_user_can('manage_woocommerce')) {
            return;
        }

        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Christocentric Rentals', 'christocentric-rentals'); ?></h1>
            <p><?php esc_html_e('WooCommerce is the shop. Rentopian is optional: catalog can sync both ways, and paid orders are pushed to Rentopian.', 'christocentric-rentals'); ?></p>

            <?php if (! empty($_GET['ccr_rentopian'])) : // phpcs:ignore ?>
                <div class="notice notice-info is-dismissible"><p><?php echo esc_html(sanitize_text_field(wp_unslash((string) ($_GET['msg'] ?? '')))); ?></p></div>
            <?php endif; ?>

            <?php if (! empty($_GET['ccr_smtp_test'])) : // phpcs:ignore ?>
                <?php if ($_GET['ccr_smtp_test'] === 'ok') : // phpcs:ignore ?>
                    <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Test email sent.', 'christocentric-rentals'); ?></p></div>
                <?php else : ?>
                    <div class="notice notice-error is-dismissible"><p><?php echo esc_html(sanitize_text_field(wp_unslash((string) ($_GET['ccr_smtp_msg'] ?? 'SMTP test failed.')))); ?></p></div>
                <?php endif; ?>
            <?php endif; ?>

            <?php if (! empty($_GET['ccr_tax_setup']) && $_GET['ccr_tax_setup'] === 'ok') : // phpcs:ignore ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Ghana tax applied: VAT 15% + NHIL 2.5% + GETFund 2.5% (shop base address). Purge cache and recheck checkout.', 'christocentric-rentals'); ?></p></div>
            <?php endif; ?>

            <?php if (! empty($_GET['ccr_tax_toggle'])) : // phpcs:ignore ?>
                <div class="notice notice-success is-dismissible"><p><?php echo $_GET['ccr_tax_toggle'] === 'on' // phpcs:ignore
                    ? esc_html__('Taxes are ON. Checkout will add VAT, NHIL, and GETFund. Rates were not changed.', 'christocentric-rentals')
                    : esc_html__('Taxes are OFF. Checkout will not add tax. Ghana rates are still saved for when you turn this back on.', 'christocentric-rentals'); ?></p></div>
            <?php endif; ?>

            <?php if (! empty($_GET['ccr_import'])) : // phpcs:ignore ?>
                <div class="notice notice-success is-dismissible"><p>
                    <?php
                    echo esc_html(sprintf(
                        /* translators: 1: updated 2: created 3: skipped */
                        __('Verification import finished. Updated: %1$d. Accounts created: %2$d. Skipped: %3$d.', 'christocentric-rentals'),
                        (int) ($_GET['updated'] ?? 0),
                        (int) ($_GET['created'] ?? 0),
                        (int) ($_GET['skipped'] ?? 0)
                    ));
                    if (! empty($_GET['ccr_import_msg'])) { // phpcs:ignore
                        echo ' ' . esc_html(sanitize_text_field(wp_unslash((string) $_GET['ccr_import_msg'])));
                    }
                    ?>
                </p></div>
            <?php endif; ?>

            <div class="card" style="max-width:820px;padding:12px 16px;margin:16px 0">
                <h2 style="margin-top:0"><?php esc_html_e('Ghana tax (VAT / NHIL / GETFund)', 'christocentric-rentals'); ?></h2>
                <?php
                $taxOn = function_exists('wc_tax_enabled') && wc_tax_enabled();
                $taxBasedOn = (string) get_option('woocommerce_tax_based_on', '');
                global $wpdb;
                $rateCount = (int) $wpdb->get_var(
                    "SELECT COUNT(*) FROM {$wpdb->prefix}woocommerce_tax_rates WHERE tax_rate_country = 'GH'"
                );
                ?>
                <ul style="margin-left:1.2em">
                    <li><?php echo $taxOn
                        ? esc_html__('Tax calculations: ON', 'christocentric-rentals')
                        : esc_html__('Tax calculations: OFF', 'christocentric-rentals'); ?></li>
                    <li><?php echo esc_html(sprintf(
                        /* translators: %s: tax based on setting */
                        __('Calculate tax based on: %s', 'christocentric-rentals'),
                        $taxBasedOn !== '' ? $taxBasedOn : '—'
                    )); ?></li>
                    <li><?php echo esc_html(sprintf(
                        /* translators: %d: number of GH tax rates */
                        __('Ghana tax rates saved: %d', 'christocentric-rentals'),
                        $rateCount
                    )); ?></li>
                </ul>
                <p class="description"><?php esc_html_e('Turn taxes off to hide them at checkout without deleting rates. Turn them on again when you are ready. Apply rates only if the Ghana lines are missing or wrong.', 'christocentric-rentals'); ?></p>
                <div>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;margin-right:8px">
                        <input type="hidden" name="action" value="ccr_toggle_taxes">
                        <input type="hidden" name="ccr_taxes" value="<?php echo $taxOn ? 'off' : 'on'; ?>">
                        <?php wp_nonce_field('ccr_toggle_taxes'); ?>
                        <?php if ($taxOn) : ?>
                            <?php submit_button(__('Turn taxes off', 'christocentric-rentals'), 'secondary', 'submit', false); ?>
                        <?php else : ?>
                            <?php submit_button(__('Turn taxes on', 'christocentric-rentals'), 'primary', 'submit', false); ?>
                        <?php endif; ?>
                    </form>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block">
                        <input type="hidden" name="action" value="ccr_setup_ghana_tax">
                        <?php wp_nonce_field('ccr_setup_ghana_tax'); ?>
                        <?php submit_button(__('Apply Ghana tax rates', 'christocentric-rentals'), 'secondary', 'submit', false); ?>
                    </form>
                </div>
            </div>

            <div class="card" style="max-width:820px;padding:12px 16px;margin:16px 0">
                <h2 style="margin-top:0"><?php esc_html_e('Import old Google Form verifications', 'christocentric-rentals'); ?></h2>
                <p><?php esc_html_e('Export responses from Google Forms as CSV, then upload here. Rows are matched by email:', 'christocentric-rentals'); ?></p>
                <ul style="margin-left:1.2em">
                    <li><?php esc_html_e('If that email already has a WordPress/WooCommerce account → verification is filled and they skip the popup.', 'christocentric-rentals'); ?></li>
                    <li><?php esc_html_e('If not → optionally create an account (they use Forgot password / Continue later with the same email).', 'christocentric-rentals'); ?></li>
                    <li><?php esc_html_e('Google Drive file links are saved as links on the order (files stay in Drive unless you re-upload later).', 'christocentric-rentals'); ?></li>
                </ul>
                <p>
                    <a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=ccr_download_verification_template'), 'ccr_download_verification_template')); ?>">
                        <?php esc_html_e('Download CSV template', 'christocentric-rentals'); ?>
                    </a>
                </p>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="ccr_import_verification_csv">
                    <?php wp_nonce_field('ccr_import_verification_csv'); ?>
                    <p>
                        <input type="file" name="ccr_verification_csv" accept=".csv,text/csv" required>
                    </p>
                    <p>
                        <label>
                            <input type="checkbox" name="ccr_create_missing_accounts" value="1" checked>
                            <?php esc_html_e('Create WooCommerce accounts for emails that do not exist yet', 'christocentric-rentals'); ?>
                        </label>
                    </p>
                    <?php submit_button(__('Import verification CSV', 'christocentric-rentals'), 'primary', 'submit', false); ?>
                </form>
            </div>

            <div class="card" style="max-width:820px;padding:12px 16px;margin:16px 0">
                <h2 style="margin-top:0"><?php esc_html_e('Staff booking workflow', 'christocentric-rentals'); ?></h2>
                <ol style="margin-left:1.2em">
                    <li><?php esc_html_e('Customer books dates → Paystack or Pay on pickup.', 'christocentric-rentals'); ?></li>
                    <li><?php esc_html_e('Open WooCommerce → Orders. Rental dates show in the list and in the “Rental booking” box.', 'christocentric-rentals'); ?></li>
                    <li><?php esc_html_e('Pay on pickup: collect cash, then Order actions → Mark paid at pickup.', 'christocentric-rentals'); ?></li>
                    <li><?php esc_html_e('Hand out gear on pickup day. When returned, set status to Completed.', 'christocentric-rentals'); ?></li>
                </ol>
            </div>

            <form method="post" action="options.php">
                <?php settings_fields('ccr_settings'); ?>
                <h2 class="title"><?php esc_html_e('WhatsApp chat button', 'christocentric-rentals'); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="ccr_whatsapp"><?php esc_html_e('WhatsApp number or link', 'christocentric-rentals'); ?></label></th>
                        <td>
                            <input type="text" class="regular-text" id="ccr_whatsapp" name="ccr_whatsapp" value="<?php echo esc_attr((string) get_option('ccr_whatsapp', self::DEFAULT_WHATSAPP)); ?>" placeholder="233532670582">
                            <p class="description"><?php esc_html_e('Used by the green chat button. Paste a full WhatsApp link (https://wa.me/c/233532670582) or digits with country code (233532670582).', 'christocentric-rentals'); ?></p>
                        </td>
                    </tr>
                </table>
                <h2 class="title"><?php esc_html_e('Rental holds & defaults', 'christocentric-rentals'); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="ccr_rentopian_api_key"><?php esc_html_e('Rentopian API key (optional)', 'christocentric-rentals'); ?></label></th>
                        <td>
                            <input type="password" class="regular-text" id="ccr_rentopian_api_key" name="ccr_rentopian_api_key" value="<?php echo esc_attr(get_option('ccr_rentopian_api_key', '')); ?>" autocomplete="off">
                            <p class="description"><?php esc_html_e('From Rentopian → Settings → Company Details → API key. Leave empty to skip all Rentopian sync.', 'christocentric-rentals'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="ccr_rentopian_base_url"><?php esc_html_e('Rentopian base URL', 'christocentric-rentals'); ?></label></th>
                        <td><input type="url" class="regular-text" id="ccr_rentopian_base_url" name="ccr_rentopian_base_url" value="<?php echo esc_attr(get_option('ccr_rentopian_base_url', class_exists('CCR_Rentopian_Sync') ? CCR_Rentopian_Sync::default_base_url() : 'https://account.rentopian.com/api/v1')); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="ccr_rentopian_website"><?php esc_html_e('Rentopian website domain', 'christocentric-rentals'); ?></label></th>
                        <td>
                            <input type="url" class="regular-text" id="ccr_rentopian_website" name="ccr_rentopian_website" value="<?php echo esc_attr(get_option('ccr_rentopian_website', class_exists('CCR_Rentopian_Sync') ? CCR_Rentopian_Sync::website_domain() : 'https://christocentricrentals.com')); ?>">
                            <p class="description"><?php esc_html_e('Must match the Website URL used when the API key was created in Rentopian (usually the live site).', 'christocentric-rentals'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Catalog: Rentopian → this shop', 'christocentric-rentals'); ?></th>
                        <td>
                            <label>
                                <input type="hidden" name="ccr_rentopian_pull_products" value="no">
                                <input type="checkbox" name="ccr_rentopian_pull_products" value="yes" <?php checked(get_option('ccr_rentopian_pull_products', 'yes'), 'yes'); ?>>
                                <?php esc_html_e('Pull products (matched by Rentopian ID or SKU; never deletes existing products)', 'christocentric-rentals'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Catalog: this shop → Rentopian', 'christocentric-rentals'); ?></th>
                        <td>
                            <label>
                                <input type="hidden" name="ccr_rentopian_push_products" value="no">
                                <input type="checkbox" name="ccr_rentopian_push_products" value="yes" <?php checked(get_option('ccr_rentopian_push_products', 'no'), 'yes'); ?>>
                                <?php esc_html_e('Push products to Rentopian (leave off — Rentopian is the catalog)', 'christocentric-rentals'); ?>
                            </label>
                        </td>
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
                    <tr>
                        <th scope="row"><label for="ccr_latest_return_time"><?php esc_html_e('Latest return time (closing)', 'christocentric-rentals'); ?></label></th>
                        <td>
                            <input type="time" id="ccr_latest_return_time" name="ccr_latest_return_time" value="<?php echo esc_attr(get_option('ccr_latest_return_time', '20:50')); ?>">
                            <p class="description"><?php esc_html_e('Customers cannot choose a return time after this. Default 8:50 PM.', 'christocentric-rentals'); ?></p>
                        </td>
                    </tr>
                </table>

                <h2 class="title"><?php esc_html_e('Late-return penalties', 'christocentric-rentals'); ?></h2>
                <p class="description"><?php esc_html_e('Rentals are billed in 24-hour blocks. After the agreed return time (plus grace), each extra 24 hours adds a late fee. Customers get an email when a late period starts, and again when staff mark a late return.', 'christocentric-rentals'); ?></p>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="ccr_grace_minutes"><?php esc_html_e('Grace period (minutes)', 'christocentric-rentals'); ?></label></th>
                        <td><input type="number" min="0" id="ccr_grace_minutes" name="ccr_grace_minutes" value="<?php echo esc_attr(get_option('ccr_grace_minutes', 30)); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="ccr_daily_rate_multiplier"><?php esc_html_e('Late 24hr rate multiplier', 'christocentric-rentals'); ?></label></th>
                        <td>
                            <input type="number" min="0" step="0.1" id="ccr_daily_rate_multiplier" name="ccr_daily_rate_multiplier" value="<?php echo esc_attr(get_option('ccr_daily_rate_multiplier', 1)); ?>">
                            <p class="description"><?php esc_html_e('Penalty = late 24hr periods × daily rate × qty × multiplier.', 'christocentric-rentals'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="ccr_due_soon_hours"><?php esc_html_e('Due-soon warning (hours)', 'christocentric-rentals'); ?></label></th>
                        <td><input type="number" min="1" id="ccr_due_soon_hours" name="ccr_due_soon_hours" value="<?php echo esc_attr(get_option('ccr_due_soon_hours', 2)); ?>"></td>
                    </tr>
                </table>

                <h2 class="title"><?php esc_html_e('Store-wide sale', 'christocentric-rentals'); ?></h2>
                <p class="description"><?php esc_html_e('Applies a percent off the regular daily rate site-wide. Product sale prices still win if lower. Use {percent} and {ends} in banner text.', 'christocentric-rentals'); ?></p>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="ccr_global_discount_percent"><?php esc_html_e('Discount percent', 'christocentric-rentals'); ?></label></th>
                        <td><input type="number" min="0" max="99.99" step="0.01" id="ccr_global_discount_percent" name="ccr_global_discount_percent" value="<?php echo esc_attr(get_option('ccr_global_discount_percent', 0)); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="ccr_global_discount_ends_at"><?php esc_html_e('Ends at (optional)', 'christocentric-rentals'); ?></label></th>
                        <td><input type="datetime-local" id="ccr_global_discount_ends_at" name="ccr_global_discount_ends_at" value="<?php echo esc_attr(self::datetime_local_value((string) get_option('ccr_global_discount_ends_at', ''))); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="ccr_banner_title"><?php esc_html_e('Banner title', 'christocentric-rentals'); ?></label></th>
                        <td><input type="text" class="regular-text" id="ccr_banner_title" name="ccr_banner_title" value="<?php echo esc_attr(get_option('ccr_banner_title', 'Gear Sale')); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="ccr_banner_line_1"><?php esc_html_e('Banner line 1', 'christocentric-rentals'); ?></label></th>
                        <td><input type="text" class="large-text" id="ccr_banner_line_1" name="ccr_banner_line_1" value="<?php echo esc_attr(get_option('ccr_banner_line_1', '{percent}% off every rental — cameras, lenses, lights, and more.')); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="ccr_banner_line_2"><?php esc_html_e('Banner line 2', 'christocentric-rentals'); ?></label></th>
                        <td><input type="text" class="large-text" id="ccr_banner_line_2" name="ccr_banner_line_2" value="<?php echo esc_attr(get_option('ccr_banner_line_2', 'Discount applies automatically at checkout. No coupon needed.')); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="ccr_banner_cta"><?php esc_html_e('Banner CTA', 'christocentric-rentals'); ?></label></th>
                        <td><input type="text" class="regular-text" id="ccr_banner_cta" name="ccr_banner_cta" value="<?php echo esc_attr(get_option('ccr_banner_cta', 'Shop')); ?>"></td>
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
                        <th scope="row"><?php esc_html_e('Notification email', 'christocentric-rentals'); ?></th>
                        <td>
                            <code><?php echo esc_html(self::contact_email()); ?></code>
                            <p class="description"><?php esc_html_e('Uses the SMTP From email above.', 'christocentric-rentals'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="ccr_newsletter_from_name"><?php esc_html_e('From name', 'christocentric-rentals'); ?></label></th>
                        <td>
                            <input type="text" class="regular-text" id="ccr_newsletter_from_name" name="ccr_newsletter_from_name" value="<?php echo esc_attr(get_option('ccr_newsletter_from_name', get_bloginfo('name'))); ?>">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('From email', 'christocentric-rentals'); ?></th>
                        <td>
                            <code><?php echo esc_html(self::contact_email()); ?></code>
                            <p class="description"><?php esc_html_e('Uses the SMTP From email above.', 'christocentric-rentals'); ?></p>
                        </td>
                    </tr>
                </table>
                <h2 class="title"><?php esc_html_e('Newsletter sync (optional)', 'christocentric-rentals'); ?></h2>
                <p class="description"><?php esc_html_e('Subscribers are always stored locally. Optionally mirror them to Mailchimp and/or a webhook (FluentCRM, Zapier, Make, etc.).', 'christocentric-rentals'); ?></p>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="ccr_mailchimp_api_key"><?php esc_html_e('Mailchimp API key', 'christocentric-rentals'); ?></label></th>
                        <td><input type="password" class="regular-text" id="ccr_mailchimp_api_key" name="ccr_mailchimp_api_key" value="<?php echo esc_attr(get_option('ccr_mailchimp_api_key', '')); ?>" autocomplete="off"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="ccr_mailchimp_list_id"><?php esc_html_e('Mailchimp audience ID', 'christocentric-rentals'); ?></label></th>
                        <td><input type="text" class="regular-text" id="ccr_mailchimp_list_id" name="ccr_mailchimp_list_id" value="<?php echo esc_attr(get_option('ccr_mailchimp_list_id', '')); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="ccr_newsletter_webhook_url"><?php esc_html_e('Webhook URL', 'christocentric-rentals'); ?></label></th>
                        <td>
                            <input type="url" class="regular-text" id="ccr_newsletter_webhook_url" name="ccr_newsletter_webhook_url" value="<?php echo esc_attr(get_option('ccr_newsletter_webhook_url', '')); ?>" placeholder="https://...">
                            <p class="description"><?php esc_html_e('POST JSON: { email, source, site, subscribed_at }', 'christocentric-rentals'); ?></p>
                        </td>
                    </tr>
                </table>

                <h2 class="title"><?php esc_html_e('Google sign-in', 'christocentric-rentals'); ?></h2>
                <p class="description"><?php esc_html_e('Lets customers sign in or create an account with Google. After a new signup they are sent to the rental agreement form.', 'christocentric-rentals'); ?></p>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e('Enable Google', 'christocentric-rentals'); ?></th>
                        <td>
                            <input type="hidden" name="ccr_google_enabled" value="no">
                            <label>
                                <input type="checkbox" name="ccr_google_enabled" value="yes" <?php checked(get_option('ccr_google_enabled', 'no'), 'yes'); ?>>
                                <?php esc_html_e('Show “Continue with Google” on login / register', 'christocentric-rentals'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="ccr_google_client_id"><?php esc_html_e('Client ID', 'christocentric-rentals'); ?></label></th>
                        <td><input type="text" class="regular-text" id="ccr_google_client_id" name="ccr_google_client_id" value="<?php echo esc_attr(get_option('ccr_google_client_id', '')); ?>" autocomplete="off"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="ccr_google_client_secret"><?php esc_html_e('Client secret', 'christocentric-rentals'); ?></label></th>
                        <td><input type="password" class="regular-text" id="ccr_google_client_secret" name="ccr_google_client_secret" value="<?php echo esc_attr(get_option('ccr_google_client_secret', '')); ?>" autocomplete="new-password"></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Authorized redirect URI', 'christocentric-rentals'); ?></th>
                        <td>
                            <code><?php echo esc_html(class_exists('CCR_Google_Auth') ? CCR_Google_Auth::redirect_uri() : home_url('/?ccr_google_auth=1')); ?></code>
                            <p class="description"><?php esc_html_e('Google Cloud Console → APIs & Services → Credentials → OAuth 2.0 Client ID (Web application). Paste this exact URI under Authorized redirect URIs. Add your production URL too.', 'christocentric-rentals'); ?></p>
                        </td>
                    </tr>
                </table>

                <h2 class="title"><?php esc_html_e('SMTP (order & contact emails)', 'christocentric-rentals'); ?></h2>
                <p class="description"><?php esc_html_e('Required on Hostinger (and most hosts) so WooCommerce order emails actually arrive. Use your mailbox SMTP details.', 'christocentric-rentals'); ?></p>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e('Enable SMTP', 'christocentric-rentals'); ?></th>
                        <td>
                            <input type="hidden" name="ccr_smtp_enabled" value="no">
                            <label>
                                <input type="checkbox" name="ccr_smtp_enabled" value="yes" <?php checked(get_option('ccr_smtp_enabled', 'no'), 'yes'); ?>>
                                <?php esc_html_e('Send WordPress mail through SMTP', 'christocentric-rentals'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="ccr_smtp_host"><?php esc_html_e('SMTP host', 'christocentric-rentals'); ?></label></th>
                        <td><input type="text" class="regular-text" id="ccr_smtp_host" name="ccr_smtp_host" value="<?php echo esc_attr(get_option('ccr_smtp_host', '')); ?>" placeholder="smtp.hostinger.com"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="ccr_smtp_port"><?php esc_html_e('Port', 'christocentric-rentals'); ?></label></th>
                        <td><input type="number" id="ccr_smtp_port" name="ccr_smtp_port" value="<?php echo esc_attr((string) get_option('ccr_smtp_port', 587)); ?>" min="1"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="ccr_smtp_encryption"><?php esc_html_e('Encryption', 'christocentric-rentals'); ?></label></th>
                        <td>
                            <select id="ccr_smtp_encryption" name="ccr_smtp_encryption">
                                <?php
                                $enc = (string) get_option('ccr_smtp_encryption', 'tls');
                                foreach (['tls' => 'TLS', 'ssl' => 'SSL', 'none' => 'None'] as $value => $label) {
                                    printf('<option value="%s" %s>%s</option>', esc_attr($value), selected($enc, $value, false), esc_html($label));
                                }
                                ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('SMTP auth', 'christocentric-rentals'); ?></th>
                        <td>
                            <input type="hidden" name="ccr_smtp_auth" value="no">
                            <label>
                                <input type="checkbox" name="ccr_smtp_auth" value="yes" <?php checked(get_option('ccr_smtp_auth', 'yes'), 'yes'); ?>>
                                <?php esc_html_e('Use username & password', 'christocentric-rentals'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="ccr_smtp_user"><?php esc_html_e('Username', 'christocentric-rentals'); ?></label></th>
                        <td><input type="text" class="regular-text" id="ccr_smtp_user" name="ccr_smtp_user" value="<?php echo esc_attr(get_option('ccr_smtp_user', '')); ?>" autocomplete="off"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="ccr_smtp_pass"><?php esc_html_e('Password', 'christocentric-rentals'); ?></label></th>
                        <td><input type="password" class="regular-text" id="ccr_smtp_pass" name="ccr_smtp_pass" value="<?php echo esc_attr(get_option('ccr_smtp_pass', '')); ?>" autocomplete="new-password"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="ccr_smtp_from_name"><?php esc_html_e('From name', 'christocentric-rentals'); ?></label></th>
                        <td><input type="text" class="regular-text" id="ccr_smtp_from_name" name="ccr_smtp_from_name" value="<?php echo esc_attr(get_option('ccr_smtp_from_name', get_bloginfo('name'))); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="ccr_smtp_from_email"><?php esc_html_e('From email', 'christocentric-rentals'); ?></label></th>
                        <td>
                            <input type="email" class="regular-text" id="ccr_smtp_from_email" name="ccr_smtp_from_email" value="<?php echo esc_attr(get_option('ccr_smtp_from_email', self::default_support_email())); ?>">
                            <p class="description"><?php esc_html_e('This is the only public contact address: footer, Contact page, contact form, studio booking notices, newsletter notices, and WooCommerce “from” emails.', 'christocentric-rentals'); ?></p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>

            <?php
            $rentopianLog = class_exists('CCR_Rentopian_Catalog') ? CCR_Rentopian_Catalog::last_log() : [];
            ?>
            <div class="card" style="max-width:820px;padding:12px 16px;margin:16px 0">
                <h2 style="margin-top:0"><?php esc_html_e('Rentopian catalog sync', 'christocentric-rentals'); ?></h2>
                <p><?php esc_html_e('Rentopian’s API does not send prices. We imported your Inventory Export PDF (292 items, rental rates + quantities). After upload, click Apply saved rates & descriptions.', 'christocentric-rentals'); ?></p>
                <div>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;margin-right:8px">
                        <input type="hidden" name="action" value="ccr_rentopian_pull">
                        <?php wp_nonce_field('ccr_rentopian_pull'); ?>
                        <?php submit_button(__('Pull catalog from Rentopian', 'christocentric-rentals'), 'secondary', 'submit', false); ?>
                    </form>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block">
                        <input type="hidden" name="action" value="ccr_rentopian_push">
                        <?php wp_nonce_field('ccr_rentopian_push'); ?>
                        <?php submit_button(__('Push catalog to Rentopian', 'christocentric-rentals'), 'secondary', 'submit', false); ?>
                    </form>
                </div>
                <?php
                $catalogCounts = class_exists('CCR_Rentopian_Catalog') ? CCR_Rentopian_Catalog::catalog_counts() : ['local' => 0, 'rentopian' => 0];
                ?>
                <p class="description" style="margin-top:12px">
                    <?php echo esc_html(sprintf(
                        /* translators: 1: rentopian count 2: local count */
                        __('On this shop now: %1$d Rentopian products, %2$d local-only products.', 'christocentric-rentals'),
                        (int) $catalogCounts['rentopian'],
                        (int) $catalogCounts['local']
                    )); ?>
                </p>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:8px" onsubmit="return confirm('Move local-only products to Trash? Rentopian items stay.');">
                    <input type="hidden" name="action" value="ccr_rentopian_keep_only">
                    <?php wp_nonce_field('ccr_rentopian_keep_only'); ?>
                    <label>
                        <input type="checkbox" name="ccr_confirm_keep_rentopian" value="1" required>
                        <?php esc_html_e('I understand this trashes products that did not come from Rentopian. They can be restored from Trash.', 'christocentric-rentals'); ?>
                    </label>
                    <p>
                        <?php submit_button(__('Keep Rentopian catalog only', 'christocentric-rentals'), 'delete', 'submit', false); ?>
                    </p>
                </form>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:8px">
                    <input type="hidden" name="action" value="ccr_rentopian_apply_fallback">
                    <?php wp_nonce_field('ccr_rentopian_apply_fallback'); ?>
                    <p class="description"><?php esc_html_e('Sets daily rates from the Rentopian PDF, fills descriptions when we have them, and attaches photos from Media plus the product-images folder in this plugin (~129 shots from the old shop).', 'christocentric-rentals'); ?></p>
                    <?php submit_button(__('Apply saved rates & descriptions', 'christocentric-rentals'), 'primary', 'submit', false); ?>
                </form>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:8px">
                    <input type="hidden" name="action" value="ccr_apply_folder_photos">
                    <input type="hidden" name="ccr_reset_folder_photos" value="1">
                    <?php wp_nonce_field('ccr_apply_folder_photos'); ?>
                    <p class="description"><?php esc_html_e('Uploads photos from public_html/Products Images onto matching products. Name the first photo main.png (or main.jpg) inside that product’s folder. 00000 is optional. Runs in small batches so Hostinger does not time out. Keep the tab open until it finishes.', 'christocentric-rentals'); ?></p>
                    <?php submit_button(__('Attach photos from Products Images', 'christocentric-rentals'), 'primary', 'submit', false); ?>
                </form>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:8px">
                    <input type="hidden" name="action" value="ccr_rentopian_categorize">
                    <?php wp_nonce_field('ccr_rentopian_categorize'); ?>
                    <p class="description"><?php esc_html_e('Puts Rentopian items into Cameras, Lenses, Lighting, Audio, Gimbals, and the other shop categories from the product name.', 'christocentric-rentals'); ?></p>
                    <?php submit_button(__('Categorize Rentopian products', 'christocentric-rentals'), 'secondary', 'submit', false); ?>
                </form>
                <?php if ($rentopianLog !== []) : ?>
                    <p class="description">
                        <?php echo esc_html(sprintf(
                            /* translators: 1: action 2: datetime 3: message */
                            __('Last %1$s: %2$s — %3$s', 'christocentric-rentals'),
                            (string) ($rentopianLog['action'] ?? ''),
                            (string) ($rentopianLog['at'] ?? ''),
                            (string) ($rentopianLog['result']['message'] ?? '')
                        )); ?>
                    </p>
                <?php endif; ?>
            </div>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:8px">
                <input type="hidden" name="action" value="ccr_smtp_test">
                <?php wp_nonce_field('ccr_smtp_test'); ?>
                <label for="ccr_smtp_test_to"><?php esc_html_e('Send test email to', 'christocentric-rentals'); ?></label>
                <input type="email" id="ccr_smtp_test_to" name="ccr_smtp_test_to" value="<?php echo esc_attr(get_option('admin_email')); ?>" class="regular-text">
                <?php submit_button(__('Send test email', 'christocentric-rentals'), 'secondary', 'submit', false); ?>
            </form>

            <hr>
            <h2><?php esc_html_e('Integrations status', 'christocentric-rentals'); ?></h2>
            <ul>
                <li><?php echo class_exists('CCR_Google_Auth') && CCR_Google_Auth::is_configured()
                    ? esc_html__('Google sign-in: configured', 'christocentric-rentals')
                    : esc_html__('Google sign-in: not configured yet', 'christocentric-rentals'); ?></li>
                <li><?php echo CCR_Rentopian_Sync::is_configured()
                    ? esc_html__('Rentopian: configured', 'christocentric-rentals')
                    : esc_html__('Rentopian: not used (OK)', 'christocentric-rentals'); ?></li>
                <li><?php echo CCR_Smtp::is_enabled()
                    ? esc_html__('SMTP: enabled', 'christocentric-rentals')
                    : esc_html__('SMTP: not enabled yet — fill settings above for production mail.', 'christocentric-rentals'); ?></li>
            </ul>
            <p>
                <?php
                printf(
                    /* translators: %s: admin menu link */
                    esc_html__('Newsletter subscribers: %s', 'christocentric-rentals'),
                    '<a href="' . esc_url(admin_url('admin.php?page=ccr-newsletter-subscribers')) . '">' . esc_html__('View list', 'christocentric-rentals') . '</a>'
                );
                ?>
            </p>
            <p>
                <a href="<?php echo esc_url(admin_url('admin.php?page=wc-settings&tab=checkout&section=paystack')); ?>"><?php esc_html_e('Open Paystack settings', 'christocentric-rentals'); ?></a>
                &nbsp;|&nbsp;
                <a href="<?php echo esc_url(admin_url('edit.php?post_type=shop_order')); ?>"><?php esc_html_e('Open orders', 'christocentric-rentals'); ?></a>
                &nbsp;|&nbsp;
                <a href="<?php echo esc_url(admin_url('edit.php?post_type=product')); ?>"><?php esc_html_e('Open products / stock', 'christocentric-rentals'); ?></a>
            </p>
        </div>
        <?php
    }

    public static function handle_smtp_test(): void
    {
        if (! current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Forbidden', 'christocentric-rentals'));
        }
        check_admin_referer('ccr_smtp_test');

        $to = sanitize_email(wp_unslash((string) ($_POST['ccr_smtp_test_to'] ?? '')));
        $result = CCR_Smtp::send_test($to);
        $url = admin_url('admin.php?page=christocentric-rentals');

        if (is_wp_error($result)) {
            wp_safe_redirect(add_query_arg([
                'ccr_smtp_test' => 'fail',
                'ccr_smtp_msg' => rawurlencode($result->get_error_message()),
            ], $url));
            exit;
        }

        wp_safe_redirect(add_query_arg('ccr_smtp_test', 'ok', $url));
        exit;
    }

    public static function handle_setup_ghana_tax(): void
    {
        if (! current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Forbidden', 'christocentric-rentals'));
        }
        check_admin_referer('ccr_setup_ghana_tax');

        self::apply_ghana_tax_rates();

        wp_safe_redirect(add_query_arg('ccr_tax_setup', 'ok', admin_url('admin.php?page=christocentric-rentals')));
        exit;
    }

    public static function handle_toggle_taxes(): void
    {
        if (! current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Forbidden', 'christocentric-rentals'));
        }
        check_admin_referer('ccr_toggle_taxes');

        $enable = (string) ($_POST['ccr_taxes'] ?? '') === 'on';
        update_option('woocommerce_calc_taxes', $enable ? 'yes' : 'no');

        if (class_exists('WC_Cache_Helper')) {
            WC_Cache_Helper::invalidate_cache_group('taxes');
        }

        wp_safe_redirect(add_query_arg(
            'ccr_tax_toggle',
            $enable ? 'on' : 'off',
            admin_url('admin.php?page=christocentric-rentals')
        ));
        exit;
    }

    /**
     * Enable WooCommerce tax + install Ghana VAT 15% / NHIL 2.5% / GETFund 2.5%.
     */
    public static function apply_ghana_tax_rates(): void
    {
        if (! class_exists('WC_Tax')) {
            return;
        }

        update_option('woocommerce_calc_taxes', 'yes');
        update_option('woocommerce_prices_include_tax', 'no');
        update_option('woocommerce_tax_based_on', 'base');
        update_option('woocommerce_shipping_tax_class', '');
        update_option('woocommerce_tax_round_at_subtotal', 'no');
        update_option('woocommerce_tax_display_shop', 'excl');
        update_option('woocommerce_tax_display_cart', 'excl');
        update_option('woocommerce_price_display_suffix', 'excl. tax');
        update_option('woocommerce_tax_total_display', 'itemized');

        $country = (string) get_option('woocommerce_default_country');
        if ($country === '' || strpos($country, 'GH') !== 0) {
            update_option('woocommerce_default_country', 'GH:AH');
        }

        global $wpdb;
        $existing = $wpdb->get_col(
            "SELECT tax_rate_id FROM {$wpdb->prefix}woocommerce_tax_rates WHERE tax_rate_country = 'GH' AND tax_rate_class = ''"
        );
        foreach ($existing as $rateId) {
            $rateId = (int) $rateId;
            $wpdb->delete($wpdb->prefix . 'woocommerce_tax_rate_locations', ['tax_rate_id' => $rateId], ['%d']);
            WC_Tax::_delete_tax_rate($rateId);
        }

        $rates = [
            ['rate' => '15.0000', 'name' => 'VAT', 'order' => 1],
            ['rate' => '2.5000', 'name' => 'NHIL', 'order' => 2],
            ['rate' => '2.5000', 'name' => 'GETFund', 'order' => 3],
        ];

        foreach ($rates as $rate) {
            WC_Tax::_insert_tax_rate([
                'tax_rate_country' => 'GH',
                'tax_rate_state' => '',
                'tax_rate' => $rate['rate'],
                'tax_rate_name' => $rate['name'],
                'tax_rate_priority' => 1,
                'tax_rate_compound' => 0,
                'tax_rate_shipping' => 0,
                'tax_rate_order' => $rate['order'],
                'tax_rate_class' => '',
            ]);
        }

        if (class_exists('WC_Cache_Helper')) {
            WC_Cache_Helper::invalidate_cache_group('taxes');
        }
    }

    public static function default_support_email(): string
    {
        return self::contact_email();
    }

    public const CONTACT_EMAIL = 'christocentricrentals@gmail.com';
    public const DEFAULT_WHATSAPP = 'https://wa.me/c/233532670582';

    public static function whatsapp_url(): string
    {
        $raw = trim((string) get_option('ccr_whatsapp', ''));
        if ($raw === '' && function_exists('ccr_site_config')) {
            $raw = trim((string) ccr_site_config('contact.whatsapp', ''));
            if ($raw === '') {
                $raw = trim((string) ccr_site_config('contact.phone', ''));
            }
        }
        if ($raw === '') {
            $raw = self::DEFAULT_WHATSAPP;
        }
        if (preg_match('#^https?://#i', $raw)) {
            $url = esc_url_raw($raw);

            return $url !== '' ? $url : self::DEFAULT_WHATSAPP;
        }
        $digits = preg_replace('/\D+/', '', $raw) ?: '233532670582';
        if (str_contains(strtolower($raw), '/c/')) {
            return 'https://wa.me/c/' . $digits;
        }

        return 'https://wa.me/' . $digits;
    }

    /**
     * Fill SMTP From when empty or still on the old support@ mailbox.
     */
    public static function maybe_sync_contact_email(): void
    {
        $from = (string) get_option('ccr_smtp_from_email', '');
        if ($from !== self::CONTACT_EMAIL) {
            update_option('ccr_smtp_from_email', self::CONTACT_EMAIL);
        }
    }

    /**
     * One public/contact mailbox: SMTP From email, else SMTP username, else Gmail.
     */
    public static function contact_email($unused = null): string
    {
        $from = (string) get_option('ccr_smtp_from_email', '');
        if (is_email($from)) {
            return $from;
        }
        $user = (string) get_option('ccr_smtp_user', '');
        if (is_email($user)) {
            return $user;
        }

        return self::CONTACT_EMAIL;
    }

    public static function force_contact_recipient($recipient): string
    {
        $email = self::contact_email();

        return is_email($email) ? $email : (is_string($recipient) ? $recipient : '');
    }

    public static function datetime_local_value(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        try {
            $dt = new DateTimeImmutable($value, wp_timezone());

            return $dt->format('Y-m-d\TH:i');
        } catch (Exception $e) {
            return '';
        }
    }

    public static function add_mark_paid_action(array $actions): array
    {
        $actions['ccr_mark_paid'] = __('Mark paid at pickup', 'christocentric-rentals');

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
