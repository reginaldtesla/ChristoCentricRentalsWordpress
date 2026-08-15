<?php

defined('ABSPATH') || exit;

/**
 * Google OAuth sign-in / sign-up for WooCommerce customers.
 */
final class CCR_Google_Auth
{
    public static function init(): void
    {
        add_action('admin_post_nopriv_ccr_google_start', [self::class, 'start']);
        add_action('admin_post_ccr_google_start', [self::class, 'start']);
        add_action('template_redirect', [self::class, 'maybe_handle_callback'], 1);
        add_action('template_redirect', [self::class, 'maybe_redirect_checkout'], 15);
        add_action('woocommerce_before_customer_login_form', [self::class, 'render_button']);
        add_action('admin_post_nopriv_ccr_account_continue', [self::class, 'handle_email_continue']);
        add_action('admin_post_ccr_account_continue', [self::class, 'handle_email_continue']);
        add_filter('woocommerce_registration_redirect', [self::class, 'registration_redirect']);
        add_filter('woocommerce_login_redirect', [self::class, 'login_redirect'], 20, 2);
        add_filter('woocommerce_checkout_registration_enabled', '__return_false');
        add_action('woocommerce_created_customer', [self::class, 'flag_new_customer']);
        add_action('init', [self::class, 'disable_checkout_signup'], 20);
    }

    /**
     * Account creation happens on My Account Continue, not on checkout.
     */
    public static function disable_checkout_signup(): void
    {
        if (get_option('ccr_checkout_login_gate') === 'yes') {
            return;
        }

        update_option('woocommerce_enable_guest_checkout', 'no');
        update_option('woocommerce_enable_signup_and_login_from_checkout', 'no');
        update_option('ccr_checkout_login_gate', 'yes');
    }

    /**
     * Logged-out shoppers must Continue (sign in or create an account) before checkout.
     */
    public static function maybe_redirect_checkout(): void
    {
        if (is_admin() || wp_doing_ajax() || is_user_logged_in()) {
            return;
        }
        if (! function_exists('is_checkout') || ! is_checkout()) {
            return;
        }
        if (function_exists('is_order_received_page') && is_order_received_page()) {
            return;
        }
        if (function_exists('is_checkout_pay_page') && is_checkout_pay_page()) {
            return;
        }

        wc_add_notice(__('Sign in or create an account to complete checkout.', 'christocentric-rentals'), 'notice');
        wp_safe_redirect(add_query_arg('redirect_to', wc_get_checkout_url(), wc_get_page_permalink('myaccount')));
        exit;
    }

    public static function is_configured(): bool
    {
        return get_option('ccr_google_enabled', 'no') === 'yes'
            && self::client_id() !== ''
            && self::client_secret() !== '';
    }

    public static function client_id(): string
    {
        return trim((string) get_option('ccr_google_client_id', ''));
    }

    public static function client_secret(): string
    {
        return trim((string) get_option('ccr_google_client_secret', ''));
    }

    public static function redirect_uri(): string
    {
        return home_url('/?ccr_google_auth=1');
    }

    public static function start_url(): string
    {
        $url = admin_url('admin-post.php?action=ccr_google_start');
        $redirectTo = self::requested_redirect();
        if ($redirectTo !== '') {
            $url = add_query_arg('redirect_to', $redirectTo, $url);
        }

        return wp_nonce_url($url, 'ccr_google_start');
    }

    public static function requested_redirect(): string
    {
        $fromGet = isset($_GET['redirect_to']) ? (string) wp_unslash($_GET['redirect_to']) : '';
        $fromPost = isset($_POST['redirect_to']) ? (string) wp_unslash($_POST['redirect_to']) : '';

        return self::sanitize_redirect($fromPost !== '' ? $fromPost : $fromGet);
    }

    public static function start(): void
    {
        if (! self::is_configured()) {
            wc_add_notice(__('Google sign-in is not configured yet.', 'christocentric-rentals'), 'error');
            wp_safe_redirect(wc_get_page_permalink('myaccount'));
            exit;
        }

        check_admin_referer('ccr_google_start');

        $state = wp_generate_password(32, false);
        $redirectTo = self::requested_redirect();
        set_transient('ccr_google_state_' . $state, [
            'redirect_to' => $redirectTo,
            'created' => time(),
        ], 10 * MINUTE_IN_SECONDS);

        $params = [
            'client_id' => self::client_id(),
            'redirect_uri' => self::redirect_uri(),
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'state' => $state,
            'access_type' => 'online',
            'prompt' => 'select_account',
        ];

        wp_redirect('https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986));
        exit;
    }

    public static function maybe_handle_callback(): void
    {
        if (! isset($_GET['ccr_google_auth'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            return;
        }

        if (isset($_GET['error'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            wc_add_notice(__('Google sign-in was cancelled.', 'christocentric-rentals'), 'notice');
            wp_safe_redirect(wc_get_page_permalink('myaccount'));
            exit;
        }

        $code = sanitize_text_field(wp_unslash((string) ($_GET['code'] ?? '')));
        $state = sanitize_text_field(wp_unslash((string) ($_GET['state'] ?? '')));
        $stored = $state !== '' ? get_transient('ccr_google_state_' . $state) : false;
        delete_transient('ccr_google_state_' . $state);

        if ($code === '' || ! is_array($stored)) {
            wc_add_notice(__('Google sign-in expired. Please try again.', 'christocentric-rentals'), 'error');
            wp_safe_redirect(wc_get_page_permalink('myaccount'));
            exit;
        }

        $profile = self::exchange_code($code);
        if (is_wp_error($profile)) {
            wc_add_notice($profile->get_error_message(), 'error');
            wp_safe_redirect(wc_get_page_permalink('myaccount'));
            exit;
        }

        $result = self::login_or_register($profile);
        if (is_wp_error($result)) {
            wc_add_notice($result->get_error_message(), 'error');
            wp_safe_redirect(wc_get_page_permalink('myaccount'));
            exit;
        }

        [$user, $isNew] = $result;
        wp_set_current_user($user->ID);
        wp_set_auth_cookie($user->ID, true);
        do_action('wp_login', $user->user_login, $user);

        $needsAgreement = get_user_meta($user->ID, '_ccr_needs_rental_agreement', true) === 'yes';
        $redirect = class_exists('CCR_Rental_Agreement') && ($isNew || $needsAgreement)
            ? CCR_Rental_Agreement::url()
            : self::sanitize_redirect((string) ($stored['redirect_to'] ?? ''));

        if ($redirect === '') {
            $redirect = wc_get_page_permalink('myaccount');
        }

        wp_safe_redirect($redirect);
        exit;
    }

    /**
     * @return array{email:string,sub:string,given_name:string,family_name:string,name:string}|WP_Error
     */
    private static function exchange_code(string $code)
    {
        $tokenResponse = wp_remote_post('https://oauth2.googleapis.com/token', [
            'timeout' => 20,
            'body' => [
                'code' => $code,
                'client_id' => self::client_id(),
                'client_secret' => self::client_secret(),
                'redirect_uri' => self::redirect_uri(),
                'grant_type' => 'authorization_code',
            ],
        ]);

        if (is_wp_error($tokenResponse)) {
            return new WP_Error('ccr_google_http', __('Could not reach Google. Try again.', 'christocentric-rentals'));
        }

        $token = json_decode((string) wp_remote_retrieve_body($tokenResponse), true);
        $access = is_array($token) ? (string) ($token['access_token'] ?? '') : '';
        if ($access === '') {
            return new WP_Error('ccr_google_token', __('Google did not return a valid token.', 'christocentric-rentals'));
        }

        $infoResponse = wp_remote_get('https://openidconnect.googleapis.com/v1/userinfo', [
            'timeout' => 20,
            'headers' => [
                'Authorization' => 'Bearer ' . $access,
            ],
        ]);

        if (is_wp_error($infoResponse)) {
            return new WP_Error('ccr_google_http', __('Could not load your Google profile.', 'christocentric-rentals'));
        }

        $info = json_decode((string) wp_remote_retrieve_body($infoResponse), true);
        if (! is_array($info) || empty($info['email'])) {
            return new WP_Error('ccr_google_profile', __('Google did not return an email address.', 'christocentric-rentals'));
        }

        if (empty($info['email_verified'])) {
            return new WP_Error('ccr_google_unverified', __('Please verify your Google email, then try again.', 'christocentric-rentals'));
        }

        return [
            'email' => sanitize_email((string) $info['email']),
            'sub' => sanitize_text_field((string) ($info['sub'] ?? '')),
            'given_name' => sanitize_text_field((string) ($info['given_name'] ?? '')),
            'family_name' => sanitize_text_field((string) ($info['family_name'] ?? '')),
            'name' => sanitize_text_field((string) ($info['name'] ?? '')),
        ];
    }

    /**
     * @param array{email:string,sub:string,given_name:string,family_name:string,name:string} $profile
     * @return array{0:WP_User,1:bool}|WP_Error
     */
    private static function login_or_register(array $profile)
    {
        if (! is_email($profile['email'])) {
            return new WP_Error('ccr_google_email', __('Google returned an invalid email.', 'christocentric-rentals'));
        }

        $user = get_user_by('email', $profile['email']);
        if ($user instanceof WP_User) {
            if ($profile['sub'] !== '') {
                update_user_meta($user->ID, '_ccr_google_id', $profile['sub']);
            }

            return [$user, false];
        }

        if (! function_exists('wc_create_new_customer')) {
            return new WP_Error('ccr_google_wc', __('WooCommerce is required to create an account.', 'christocentric-rentals'));
        }

        $username = wc_create_new_customer_username($profile['email'], [
            'first_name' => $profile['given_name'],
            'last_name' => $profile['family_name'],
        ]);
        $customerId = wc_create_new_customer($profile['email'], $username, wp_generate_password(24));
        if (is_wp_error($customerId)) {
            return $customerId;
        }

        $user = get_user_by('id', (int) $customerId);
        if (! $user instanceof WP_User) {
            return new WP_Error('ccr_google_user', __('Account was created but could not be loaded.', 'christocentric-rentals'));
        }

        if ($profile['given_name'] !== '') {
            update_user_meta($user->ID, 'first_name', $profile['given_name']);
            update_user_meta($user->ID, 'billing_first_name', $profile['given_name']);
        }
        if ($profile['family_name'] !== '') {
            update_user_meta($user->ID, 'last_name', $profile['family_name']);
            update_user_meta($user->ID, 'billing_last_name', $profile['family_name']);
        }
        if ($profile['name'] !== '') {
            wp_update_user([
                'ID' => $user->ID,
                'display_name' => $profile['name'],
            ]);
        }
        update_user_meta($user->ID, '_ccr_google_id', $profile['sub']);
        self::flag_new_customer($user->ID);

        return [$user, true];
    }

    public static function flag_new_customer(int $customerId): void
    {
        update_user_meta($customerId, '_ccr_needs_rental_agreement', 'yes');
    }

    public static function handle_email_continue(): void
    {
        check_admin_referer('ccr_account_continue');

        $email = sanitize_email(wp_unslash((string) ($_POST['email'] ?? '')));
        $password = (string) ($_POST['password'] ?? '');
        $remember = ! empty($_POST['rememberme']);

        if (! is_email($email)) {
            self::fail_and_back(__('Enter a valid email address.', 'christocentric-rentals'));
        }
        if (strlen($password) < 6) {
            self::fail_and_back(__('Password must be at least 6 characters.', 'christocentric-rentals'));
        }

        $user = get_user_by('email', $email);
        $isNew = false;

        if ($user instanceof WP_User) {
            $auth = wp_authenticate($user->user_login, $password);
            if (is_wp_error($auth)) {
                self::fail_and_back(__('That password does not match this email. Try again, or use Forgot password.', 'christocentric-rentals'));
            }
            $user = $auth;
        } else {
            if (! function_exists('wc_create_new_customer')) {
                self::fail_and_back(__('Could not create an account right now. Please try again.', 'christocentric-rentals'));
            }

            $username = function_exists('wc_create_new_customer_username')
                ? wc_create_new_customer_username($email)
                : sanitize_user(current(explode('@', $email)), true);
            $customerId = wc_create_new_customer($email, $username, $password);
            if (is_wp_error($customerId)) {
                self::fail_and_back($customerId->get_error_message());
            }

            $user = get_user_by('id', (int) $customerId);
            if (! $user instanceof WP_User) {
                self::fail_and_back(__('Account was created but could not be opened. Please try signing in.', 'christocentric-rentals'));
            }

            $isNew = true;
            self::flag_new_customer($user->ID);
        }

        wp_set_current_user($user->ID);
        wp_set_auth_cookie($user->ID, $remember);
        do_action('wp_login', $user->user_login, $user);

        $needsAgreement = $isNew || get_user_meta($user->ID, '_ccr_needs_rental_agreement', true) === 'yes';
        $intended = self::requested_redirect();
        $redirect = $needsAgreement && class_exists('CCR_Rental_Agreement')
            ? CCR_Rental_Agreement::url()
            : ($intended !== '' ? $intended : wc_get_page_permalink('myaccount'));

        wp_safe_redirect($redirect);
        exit;
    }

    private static function fail_and_back(string $message): void
    {
        wc_add_notice($message, 'error');
        $url = wc_get_page_permalink('myaccount');
        $intended = self::requested_redirect();
        if ($intended !== '') {
            $url = add_query_arg('redirect_to', $intended, $url);
        }
        wp_safe_redirect($url);
        exit;
    }

    public static function registration_redirect(string $redirect): string
    {
        if (class_exists('CCR_Rental_Agreement')) {
            return CCR_Rental_Agreement::url();
        }

        return $redirect;
    }

    /**
     * @param string $redirect
     * @param WP_User $user
     */
    public static function login_redirect($redirect, $user): string
    {
        if ($user instanceof WP_User && get_user_meta($user->ID, '_ccr_needs_rental_agreement', true) === 'yes' && class_exists('CCR_Rental_Agreement')) {
            return CCR_Rental_Agreement::url();
        }

        $intended = self::requested_redirect();
        if ($intended !== '') {
            return $intended;
        }

        return is_string($redirect) ? $redirect : wc_get_page_permalink('myaccount');
    }

    public static function render_button(): void
    {
        if (! self::is_configured() || is_user_logged_in()) {
            return;
        }

        echo '<div class="ccr-google-auth">';
        echo '<a class="ccr-google-btn" href="' . esc_url(self::start_url()) . '">';
        echo '<svg class="ccr-google-btn-icon" viewBox="0 0 24 24" aria-hidden="true"><path fill="#4285F4" d="M23.49 12.27c0-.82-.07-1.64-.23-2.43H12v4.6h6.46a5.52 5.52 0 0 1-2.4 3.63v3.01h3.88c2.27-2.09 3.55-5.17 3.55-8.81z"/><path fill="#34A853" d="M12 24c3.24 0 5.96-1.07 7.95-2.92l-3.88-3.01c-1.08.73-2.47 1.16-4.07 1.16-3.13 0-5.78-2.11-6.73-4.96H1.26v3.11A12 12 0 0 0 12 24z"/><path fill="#FBBC05" d="M5.27 14.27A7.2 7.2 0 0 1 4.89 12c0-.79.14-1.56.38-2.27V6.62H1.26A12 12 0 0 0 0 12c0 1.94.46 3.77 1.26 5.38l4.01-3.11z"/><path fill="#EA4335" d="M12 4.75c1.76 0 3.34.61 4.58 1.8l3.44-3.44C17.95 1.19 15.24 0 12 0 7.31 0 3.26 2.69 1.26 6.62l4.01 3.11C6.22 6.86 8.87 4.75 12 4.75z"/></svg>';
        echo '<span>' . esc_html__('Continue with Google', 'christocentric-rentals') . '</span>';
        echo '</a>';
        echo '<p class="ccr-google-auth-or">' . esc_html__('or continue with email', 'christocentric-rentals') . '</p>';
        echo '</div>';
    }

    private static function sanitize_redirect(string $url): string
    {
        $url = wp_validate_redirect(esc_url_raw($url), '');
        if ($url === '') {
            return '';
        }

        $path = (string) wp_parse_url($url, PHP_URL_PATH);

        return str_contains($path, 'wp-login.php') ? '' : $url;
    }
}
