<?php

defined('ABSPATH') || exit;

final class CCR_Newsletter
{
    public const SUBSCRIBE_ACTION = 'ccr_newsletter_subscribe';
    public const TABLE = 'ccr_newsletter_subscribers';

    public static function init(): void
    {
        add_action('admin_post_' . self::SUBSCRIBE_ACTION, [self::class, 'handle_subscribe']);
        add_action('admin_post_nopriv_' . self::SUBSCRIBE_ACTION, [self::class, 'handle_subscribe']);
        add_action('admin_post_ccr_export_subscribers', [self::class, 'export_csv']);
        add_action('admin_menu', [self::class, 'register_admin_menu'], 20);
        add_filter('query_vars', [self::class, 'register_query_vars']);
        add_action('init', [self::class, 'register_rewrite_rules']);
        add_action('template_redirect', [self::class, 'maybe_render_unsubscribe']);
    }

    public static function table_name(): string
    {
        global $wpdb;

        return $wpdb->prefix . self::TABLE;
    }

    public static function install(): void
    {
        global $wpdb;

        $table = self::table_name();
        $charset = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        dbDelta(
            "CREATE TABLE {$table} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                email VARCHAR(255) NOT NULL,
                unsubscribe_token VARCHAR(64) NOT NULL,
                subscribed_at DATETIME NOT NULL,
                unsubscribed_at DATETIME NULL DEFAULT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY email (email),
                KEY unsubscribe_token (unsubscribe_token)
            ) {$charset};"
        );

        self::register_rewrite_rules();
        flush_rewrite_rules();
    }

    public static function register_rewrite_rules(): void
    {
        add_rewrite_rule(
            '^newsletter/unsubscribe/([^/]+)/?$',
            'index.php?ccr_unsubscribe=$matches[1]',
            'top'
        );
    }

    public static function register_query_vars(array $vars): array
    {
        $vars[] = 'ccr_unsubscribe';

        return $vars;
    }

    public static function flash(): ?array
    {
        $key = sanitize_text_field(wp_unslash($_GET['ccr_newsletter'] ?? '')); // phpcs:ignore

        if ($key === '') {
            return null;
        }

        $data = get_transient('ccr_newsletter_' . $key);

        if (! is_array($data)) {
            return null;
        }

        delete_transient('ccr_newsletter_' . $key);

        return $data;
    }

    public static function handle_subscribe(): void
    {
        if (! isset($_POST['_wpnonce']) || ! wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'])), 'ccr_newsletter')) { // phpcs:ignore
            self::redirect(['errors' => [__('Security check failed. Please try again.', 'christocentric-rentals')]]);
        }

        if (! empty($_POST['ccr_company'])) { // phpcs:ignore Honeypot
            self::redirect(['success' => __("You're on the list — thanks for subscribing!", 'christocentric-rentals')]);
        }

        $email = sanitize_email(wp_unslash($_POST['email'] ?? '')); // phpcs:ignore

        if ($email === '' || ! is_email($email)) {
            self::redirect([
                'errors' => [__('Please enter a valid email address.', 'christocentric-rentals')],
                'old_email' => sanitize_text_field(wp_unslash($_POST['email'] ?? '')), // phpcs:ignore
            ]);
        }

        self::subscribe($email);

        self::redirect(['success' => __("You're on the list — thanks for subscribing!", 'christocentric-rentals')]);
    }

    /**
     * @return array{ok: bool, is_new: bool, token: string}
     */
    public static function subscribe(string $email): array
    {
        global $wpdb;

        $email = strtolower(trim($email));
        $table = self::table_name();
        $existing = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE email = %s", $email)); // phpcs:ignore

        $now = current_time('mysql');
        $isNew = ! $existing;
        $token = $existing->unsubscribe_token ?? wp_generate_password(48, false, false);

        if ($existing) {
            $ok = (bool) $wpdb->update(
                $table,
                [
                    'unsubscribed_at' => null,
                    'subscribed_at' => $now,
                ],
                ['id' => (int) $existing->id],
                ['%s', '%s'],
                ['%d']
            );
            $token = (string) $existing->unsubscribe_token;
        } else {
            $ok = (bool) $wpdb->insert(
                $table,
                [
                    'email' => $email,
                    'unsubscribe_token' => $token,
                    'subscribed_at' => $now,
                    'unsubscribed_at' => null,
                ],
                ['%s', '%s', '%s', '%s']
            );
        }

        if ($ok) {
            self::send_subscription_emails($email, $token, $isNew);
            self::sync_external($email);
        }

        return [
            'ok' => $ok,
            'is_new' => $isNew,
            'token' => $token,
        ];
    }

    public static function send_subscription_emails(string $email, string $token, bool $isNew): void
    {
        if (get_option('ccr_newsletter_send_welcome', 'yes') === 'yes') {
            self::send_welcome_email($email, $token);
        }

        if ($isNew && get_option('ccr_newsletter_notify_admin', 'yes') === 'yes') {
            self::send_admin_notification($email);
        }
    }

    /**
     * Push subscriber to Mailchimp and/or a generic webhook (FluentCRM, Zapier, Make, etc.).
     */
    public static function sync_external(string $email): void
    {
        self::sync_mailchimp($email);
        self::sync_webhook($email);
    }

    public static function sync_mailchimp(string $email): void
    {
        $apiKey = (string) get_option('ccr_mailchimp_api_key', '');
        $listId = (string) get_option('ccr_mailchimp_list_id', '');

        if ($apiKey === '' || $listId === '' || ! str_contains($apiKey, '-')) {
            return;
        }

        $dc = substr($apiKey, strrpos($apiKey, '-') + 1);
        $memberId = md5(strtolower($email));
        $url = sprintf('https://%s.api.mailchimp.com/3.0/lists/%s/members/%s', $dc, rawurlencode($listId), $memberId);

        wp_remote_request($url, [
            'method' => 'PUT',
            'timeout' => 12,
            'headers' => [
                'Authorization' => 'apikey ' . $apiKey,
                'Content-Type' => 'application/json',
            ],
            'body' => wp_json_encode([
                'email_address' => $email,
                'status_if_new' => 'subscribed',
                'status' => 'subscribed',
                'tags' => ['christocentric-rentals'],
            ]),
        ]);
    }

    public static function sync_webhook(string $email): void
    {
        $webhook = (string) get_option('ccr_newsletter_webhook_url', '');

        if ($webhook === '' || ! wp_http_validate_url($webhook)) {
            return;
        }

        wp_remote_post($webhook, [
            'timeout' => 12,
            'headers' => ['Content-Type' => 'application/json'],
            'body' => wp_json_encode([
                'email' => $email,
                'source' => 'christocentric-rentals',
                'site' => home_url('/'),
                'subscribed_at' => current_time('c'),
            ]),
        ]);
    }

    public static function send_welcome_email(string $email, string $token): void
    {
        $siteName = get_bloginfo('name');
        $unsubscribeUrl = home_url('/newsletter/unsubscribe/' . rawurlencode($token) . '/');
        $subject = sprintf(
            /* translators: %s: site name */
            __('Welcome to %s newsletter', 'christocentric-rentals'),
            $siteName
        );
        $inner = '<p style="margin:0 0 14px;">' . esc_html(sprintf(__('Thanks for subscribing to %s!', 'christocentric-rentals'), $siteName)) . '</p>'
            . '<p style="margin:0 0 14px;">' . esc_html__('You will receive updates about new gear, rental tips, and deals from our Kumasi inventory.', 'christocentric-rentals') . '</p>'
            . '<p style="margin:0;font-size:13px;color:#6b7280;">' . esc_html__('Unsubscribe anytime:', 'christocentric-rentals')
            . ' <a href="' . esc_url($unsubscribeUrl) . '">' . esc_html($unsubscribeUrl) . '</a></p>';

        wp_mail($email, $subject, CCR_Email::wrap_html($subject, $inner), CCR_Email::html_headers());
    }

    public static function send_admin_notification(string $email): void
    {
        $notify = get_option('ccr_newsletter_notify_email', '');

        if (! is_string($notify) || ! is_email($notify)) {
            $notify = class_exists('CCR_Settings') ? CCR_Settings::default_support_email() : get_option('admin_email');
        }

        if (! is_email($notify)) {
            return;
        }

        $subject = sprintf('[Christocentric Rentals] New newsletter subscriber: %s', $email);
        $listUrl = admin_url('admin.php?page=ccr-newsletter-subscribers');
        $inner = '<p style="margin:0 0 14px;"><strong>' . esc_html__('New newsletter signup', 'christocentric-rentals') . '</strong></p>'
            . '<p style="margin:0 0 8px;">' . esc_html__('Email:', 'christocentric-rentals') . ' ' . esc_html($email) . '</p>'
            . '<p style="margin:0 0 14px;">' . esc_html__('Time:', 'christocentric-rentals') . ' ' . esc_html(current_time('mysql')) . '</p>'
            . '<p style="margin:0;"><a href="' . esc_url($listUrl) . '">' . esc_html__('View all subscribers', 'christocentric-rentals') . '</a></p>';

        wp_mail($notify, $subject, CCR_Email::wrap_html($subject, $inner), CCR_Email::html_headers());
    }

    /**
     * @return string[]
     */
    private static function mail_headers(): array
    {
        return CCR_Email::html_headers();
    }

    public static function unsubscribe_by_token(string $token): bool
    {
        global $wpdb;

        $token = sanitize_text_field($token);

        if ($token === '') {
            return false;
        }

        $table = self::table_name();
        $subscriber = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE unsubscribe_token = %s", $token)); // phpcs:ignore

        if (! $subscriber || $subscriber->unsubscribed_at !== null) {
            return false;
        }

        return (bool) $wpdb->update(
            $table,
            ['unsubscribed_at' => current_time('mysql')],
            ['id' => (int) $subscriber->id],
            ['%s'],
            ['%d']
        );
    }

    public static function active_count(): int
    {
        global $wpdb;

        $table = self::table_name();

        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE unsubscribed_at IS NULL"); // phpcs:ignore
    }

    public static function register_admin_menu(): void
    {
        add_submenu_page(
            'woocommerce',
            __('Newsletter subscribers', 'christocentric-rentals'),
            __('Newsletter', 'christocentric-rentals'),
            'manage_woocommerce',
            'ccr-newsletter-subscribers',
            [self::class, 'render_admin_page']
        );
    }

    public static function render_admin_page(): void
    {
        if (! current_user_can('manage_woocommerce')) {
            return;
        }

        global $wpdb;

        if (isset($_GET['ccr_unsub'], $_GET['_wpnonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), 'ccr_unsub_' . absint($_GET['ccr_unsub']))) { // phpcs:ignore
            $id = absint($_GET['ccr_unsub']);
            $wpdb->update(
                self::table_name(),
                ['unsubscribed_at' => current_time('mysql')],
                ['id' => $id],
                ['%s'],
                ['%d']
            );
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Subscriber removed.', 'christocentric-rentals') . '</p></div>';
        }

        $table = self::table_name();
        $subscribers = $wpdb->get_results("SELECT * FROM {$table} ORDER BY subscribed_at DESC LIMIT 200"); // phpcs:ignore
        $active = self::active_count();
        $exportUrl = wp_nonce_url(admin_url('admin-post.php?action=ccr_export_subscribers'), 'ccr_export_subscribers');

        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Newsletter subscribers', 'christocentric-rentals'); ?></h1>
            <p><?php echo esc_html(sprintf(__('Active subscribers: %d', 'christocentric-rentals'), $active)); ?></p>
            <p>
                <a href="<?php echo esc_url($exportUrl); ?>" class="button"><?php esc_html_e('Export CSV', 'christocentric-rentals'); ?></a>
            </p>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Email', 'christocentric-rentals'); ?></th>
                        <th><?php esc_html_e('Status', 'christocentric-rentals'); ?></th>
                        <th><?php esc_html_e('Subscribed', 'christocentric-rentals'); ?></th>
                        <th><?php esc_html_e('Unsubscribed', 'christocentric-rentals'); ?></th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($subscribers === []) : ?>
                        <tr><td colspan="5"><?php esc_html_e('No subscribers yet.', 'christocentric-rentals'); ?></td></tr>
                    <?php else : ?>
                        <?php foreach ($subscribers as $row) : ?>
                            <tr>
                                <td><?php echo esc_html($row->email); ?></td>
                                <td><?php echo $row->unsubscribed_at ? esc_html__('Unsubscribed', 'christocentric-rentals') : esc_html__('Active', 'christocentric-rentals'); ?></td>
                                <td><?php echo esc_html($row->subscribed_at); ?></td>
                                <td><?php echo esc_html($row->unsubscribed_at ?: '—'); ?></td>
                                <td>
                                    <?php if (! $row->unsubscribed_at) : ?>
                                        <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=ccr-newsletter-subscribers&ccr_unsub=' . (int) $row->id), 'ccr_unsub_' . (int) $row->id)); ?>"><?php esc_html_e('Remove', 'christocentric-rentals'); ?></a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    public static function export_csv(): void
    {
        if (! current_user_can('manage_woocommerce') || ! check_admin_referer('ccr_export_subscribers')) {
            wp_die(esc_html__('Unauthorized', 'christocentric-rentals'));
        }

        global $wpdb;

        $table = self::table_name();
        $filename = 'newsletter-subscribers-' . gmdate('Y-m-d') . '.csv';

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=' . $filename);

        $out = fopen('php://output', 'w');
        fputcsv($out, ['email', 'status', 'subscribed_at', 'unsubscribed_at']);

        $rows = $wpdb->get_results("SELECT email, subscribed_at, unsubscribed_at FROM {$table} ORDER BY email ASC"); // phpcs:ignore

        foreach ($rows as $row) {
            fputcsv($out, [
                $row->email,
                $row->unsubscribed_at ? 'unsubscribed' : 'active',
                $row->subscribed_at,
                $row->unsubscribed_at ?? '',
            ]);
        }

        fclose($out);
        exit;
    }

    public static function maybe_render_unsubscribe(): void
    {
        $token = get_query_var('ccr_unsubscribe');

        if ($token === '' || $token === false) {
            return;
        }

        $ok = self::unsubscribe_by_token((string) $token);

        status_header(200);
        nocache_headers();

        get_header();
        ?>
        <section class="container-site py-16 md:py-20">
            <div class="mx-auto max-w-lg rounded-2xl border border-gray-200 bg-white p-8 text-center shadow-sm">
                <?php if ($ok) : ?>
                    <h1 class="text-2xl font-semibold text-gray-900"><?php esc_html_e('You have been unsubscribed', 'christocentric-rentals'); ?></h1>
                    <p class="mt-3 text-sm leading-relaxed text-gray-600">
                        <?php esc_html_e('You will no longer receive newsletter emails from Christocentric Rentals.', 'christocentric-rentals'); ?>
                    </p>
                <?php else : ?>
                    <h1 class="text-2xl font-semibold text-gray-900"><?php esc_html_e('Link not valid', 'christocentric-rentals'); ?></h1>
                    <p class="mt-3 text-sm leading-relaxed text-gray-600">
                        <?php esc_html_e('This unsubscribe link is invalid or has already been used.', 'christocentric-rentals'); ?>
                    </p>
                <?php endif; ?>
                <a href="<?php echo esc_url(home_url('/')); ?>" class="btn-solid mt-6 inline-flex"><?php esc_html_e('Back to homepage', 'christocentric-rentals'); ?></a>
            </div>
        </section>
        <?php
        get_footer();
        exit;
    }

    private static function redirect(array $data): void
    {
        $key = wp_generate_password(12, false);
        set_transient('ccr_newsletter_' . $key, $data, 5 * MINUTE_IN_SECONDS);

        $redirect = wp_get_referer() ?: home_url('/');

        wp_safe_redirect(add_query_arg('ccr_newsletter', $key, $redirect) . '#newsletter');
        exit;
    }
}
