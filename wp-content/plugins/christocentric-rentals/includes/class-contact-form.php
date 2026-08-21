<?php

defined('ABSPATH') || exit;

final class CCR_Contact_Form
{
    public const ACTION = 'ccr_contact_form';

    public static function init(): void
    {
        add_action('admin_post_' . self::ACTION, [self::class, 'handle']);
        add_action('admin_post_nopriv_' . self::ACTION, [self::class, 'handle']);
    }

    public static function action_url(): string
    {
        return admin_url('admin-post.php');
    }

    public static function flash(): ?array
    {
        $key = sanitize_text_field(wp_unslash($_GET['ccr_contact'] ?? '')); // phpcs:ignore

        if ($key === '') {
            return null;
        }

        $data = get_transient('ccr_contact_' . $key);

        if (! is_array($data)) {
            return null;
        }

        delete_transient('ccr_contact_' . $key);

        return $data;
    }

    public static function handle(): void
    {
        if (! isset($_POST['ccr_contact_nonce']) || ! wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['ccr_contact_nonce'])), self::ACTION)) { // phpcs:ignore
            self::redirect(['errors' => [__('Security check failed. Please try again.', 'christocentric-rentals')]]);
        }

        if (! empty($_POST['ccr_website'])) { // phpcs:ignore Honeypot
            self::redirect(['success' => true]);
        }

        $name = sanitize_text_field(wp_unslash($_POST['name'] ?? '')); // phpcs:ignore
        $email = sanitize_email(wp_unslash($_POST['email'] ?? '')); // phpcs:ignore
        $message = sanitize_textarea_field(wp_unslash($_POST['message'] ?? '')); // phpcs:ignore

        $errors = [];

        if ($name === '') {
            $errors[] = __('Please enter your name.', 'christocentric-rentals');
        }

        if ($email === '' || ! is_email($email)) {
            $errors[] = __('Please enter a valid email address.', 'christocentric-rentals');
        }

        if ($message === '') {
            $errors[] = __('Please enter a message.', 'christocentric-rentals');
        }

        if (strlen($message) > 5000) {
            $errors[] = __('Message is too long (max 5000 characters).', 'christocentric-rentals');
        }

        if ($errors !== []) {
            self::redirect([
                'errors' => $errors,
                'old' => compact('name', 'email', 'message'),
            ]);
        }

        $to = self::support_email();
        $subject = sprintf('[Christocentric Rentals] Contact from %s', $name);
        $inner = '<p style="margin:0 0 8px;"><strong>' . esc_html__('Name:', 'christocentric-rentals') . '</strong> ' . esc_html($name) . '</p>'
            . '<p style="margin:0 0 14px;"><strong>' . esc_html__('Email:', 'christocentric-rentals') . '</strong> ' . esc_html($email) . '</p>'
            . '<p style="margin:0 0 8px;"><strong>' . esc_html__('Message:', 'christocentric-rentals') . '</strong></p>'
            . '<p style="margin:0;white-space:pre-wrap;">' . nl2br(esc_html($message)) . '</p>';
        $headers = CCR_Email::html_headers([
            'Reply-To: ' . $name . ' <' . $email . '>',
        ]);

        $sent = wp_mail($to, $subject, CCR_Email::wrap_html($subject, $inner), $headers);

        if (! $sent) {
            self::redirect([
                'errors' => [__('Could not send your message. Please email us directly.', 'christocentric-rentals')],
                'old' => compact('name', 'email', 'message'),
            ]);
        }

        self::redirect(['success' => true]);
    }

    private static function support_email(): string
    {
        if (class_exists('CCR_Settings')) {
            return CCR_Settings::contact_email();
        }

        $admin = get_option('admin_email');

        return is_string($admin) && is_email($admin) ? $admin : (class_exists('CCR_Settings') ? CCR_Settings::CONTACT_EMAIL : 'christocentricrentals@gmail.com');
    }

    private static function redirect(array $data): void
    {
        $key = wp_generate_password(12, false);
        set_transient('ccr_contact_' . $key, $data, 5 * MINUTE_IN_SECONDS);

        $redirect = wp_get_referer() ?: home_url('/contact/');
        wp_safe_redirect(add_query_arg('ccr_contact', $key, $redirect));
        exit;
    }
}
