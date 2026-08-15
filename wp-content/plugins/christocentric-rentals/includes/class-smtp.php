<?php

defined('ABSPATH') || exit;

/**
 * Optional SMTP for WooCommerce / contact / newsletter emails.
 */
final class CCR_Smtp
{
    public static function init(): void
    {
        add_action('phpmailer_init', [self::class, 'configure_phpmailer']);
        add_action('wp_mail_failed', [self::class, 'log_failure']);
    }

    public static function is_enabled(): bool
    {
        return get_option('ccr_smtp_enabled', 'no') === 'yes'
            && trim((string) get_option('ccr_smtp_host', '')) !== '';
    }

    public static function configure_phpmailer($phpmailer): void
    {
        if (! self::is_enabled()) {
            return;
        }

        $phpmailer->isSMTP();
        $phpmailer->Host = (string) get_option('ccr_smtp_host', '');
        $phpmailer->Port = (int) get_option('ccr_smtp_port', 587);
        $phpmailer->SMTPAuth = get_option('ccr_smtp_auth', 'yes') === 'yes';
        $user = (string) get_option('ccr_smtp_user', '');
        $pass = (string) get_option('ccr_smtp_pass', '');
        if ($phpmailer->SMTPAuth) {
            $phpmailer->Username = $user;
            $phpmailer->Password = $pass;
        }

        $enc = (string) get_option('ccr_smtp_encryption', 'tls');
        if ($enc === 'ssl') {
            $phpmailer->SMTPSecure = 'ssl';
        } elseif ($enc === 'tls') {
            $phpmailer->SMTPSecure = 'tls';
        } else {
            $phpmailer->SMTPSecure = '';
            $phpmailer->SMTPAutoTLS = false;
        }

        $fromEmail = (string) get_option('ccr_smtp_from_email', '');
        $fromName = (string) get_option('ccr_smtp_from_name', get_bloginfo('name'));
        if ($fromEmail !== '' && is_email($fromEmail)) {
            $phpmailer->setFrom($fromEmail, $fromName !== '' ? $fromName : get_bloginfo('name'), false);
        }
    }

    public static function log_failure(WP_Error $error): void
    {
        error_log('[Christocentric Rentals] wp_mail failed: ' . $error->get_error_message());
    }

    public static function send_test(string $to): true|WP_Error
    {
        if (! is_email($to)) {
            return new WP_Error('ccr_smtp_bad_to', __('Enter a valid test email address.', 'christocentric-rentals'));
        }

        $ok = wp_mail(
            $to,
            sprintf('[%s] SMTP test', get_bloginfo('name')),
            CCR_Email::wrap_html(
                'SMTP test',
                '<p style="margin:0;">' . esc_html__('This is a test email from Christocentric Rentals. If you received it, SMTP is working.', 'christocentric-rentals') . '</p>'
            ),
            CCR_Email::html_headers()
        );

        if (! $ok) {
            return new WP_Error('ccr_smtp_send_failed', __('Test email failed. Check SMTP settings and server logs.', 'christocentric-rentals'));
        }

        return true;
    }
}
