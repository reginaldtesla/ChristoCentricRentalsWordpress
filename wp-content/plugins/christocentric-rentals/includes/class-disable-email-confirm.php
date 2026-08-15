<?php

defined('ABSPATH') || exit;

/**
 * Hide WooCommerce My Account “Confirm email address” / guest-order linking prompt.
 * Accounts are created via Continue / Google, so this extra step is not needed.
 */
final class CCR_Disable_Email_Confirm
{
    public static function init(): void
    {
        add_action('wp_login', [self::class, 'mark_verified_on_login'], 20, 2);
        add_action('woocommerce_created_customer', [self::class, 'mark_verified_user'], 20, 1);
        add_action('woocommerce_before_account_orders', [self::class, 'mark_current_verified'], 1);
        add_action('template_redirect', [self::class, 'mark_current_verified'], 5);
        add_action('wp_footer', [self::class, 'hide_prompt_script'], 50);
        add_filter('woocommerce_email_enabled_customer_verify_email', '__return_false');
        add_filter('woocommerce_get_notices', [self::class, 'strip_confirm_notices']);
    }

    /**
     * @param string $userLogin
     * @param WP_User $user
     */
    public static function mark_verified_on_login($userLogin, $user): void
    {
        if ($user instanceof WP_User) {
            self::mark_verified_user($user->ID);
        }
    }

    public static function mark_current_verified(): void
    {
        $userId = get_current_user_id();
        if ($userId > 0) {
            self::mark_verified_user($userId);
        }
    }

    public static function mark_verified_user(int $userId): void
    {
        if ($userId <= 0) {
            return;
        }

        $user = get_userdata($userId);
        if (! $user || ! is_email($user->user_email)) {
            return;
        }

        $email = strtolower($user->user_email);
        if ((string) get_user_meta($userId, '_wc_email_verified', true) !== $email) {
            update_user_meta($userId, '_wc_email_verified', $email);
        }
    }

    /**
     * @param array<string,list<array<string,mixed>>> $notices
     * @return array<string,list<array<string,mixed>>>
     */
    public static function strip_confirm_notices(array $notices): array
    {
        foreach ($notices as $type => $items) {
            if (! is_array($items)) {
                continue;
            }
            $notices[$type] = array_values(array_filter($items, static function ($notice): bool {
                $text = is_array($notice) ? (string) ($notice['notice'] ?? '') : (string) $notice;
                $lower = strtolower(wp_strip_all_tags($text));

                return ! str_contains($lower, 'confirm email address')
                    && ! str_contains($lower, 'check for past orders');
            }));
        }

        return $notices;
    }

    public static function hide_prompt_script(): void
    {
        if (! function_exists('is_account_page') || ! is_account_page()) {
            return;
        }
        ?>
        <script>
        (function () {
            var root = document.querySelector('.woocommerce-MyAccount-content');
            if (!root) return;
            root.querySelectorAll('div, section, form, .woocommerce-info, .woocommerce-message, .wc-block-components-notice-banner').forEach(function (el) {
                var text = (el.textContent || '').toLowerCase();
                if (text.indexOf('confirm email address') !== -1 || text.indexOf('check for past orders') !== -1) {
                    el.style.display = 'none';
                }
            });
        })();
        </script>
        <?php
    }
}
