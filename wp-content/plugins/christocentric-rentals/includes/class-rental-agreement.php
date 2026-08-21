<?php

defined('ABSPATH') || exit;

/**
 * Client data verification / rental agreement (modal form after signup).
 */
final class CCR_Rental_Agreement
{
    public const ENDPOINT = 'rental-agreement';

    public static function init(): void
    {
        add_action('init', [self::class, 'register_endpoint']);
        add_filter('query_vars', [self::class, 'add_query_var']);
        add_filter('woocommerce_get_query_vars', [self::class, 'wc_query_vars']);
        add_filter('woocommerce_account_menu_items', [self::class, 'menu_items'], 20);
        add_action('woocommerce_account_' . self::ENDPOINT . '_endpoint', [self::class, 'render']);
        add_action('admin_post_ccr_save_rental_agreement', [self::class, 'handle_save']);
        add_action('template_redirect', [self::class, 'maybe_block_checkout'], 20);
        add_action('woocommerce_checkout_create_order', [self::class, 'copy_to_order'], 20, 1);
        add_action('woocommerce_admin_order_data_after_billing_address', [self::class, 'render_order_id']);
        add_filter('the_title', [self::class, 'endpoint_title'], 10, 2);
    }

    public static function register_endpoint(): void
    {
        add_rewrite_endpoint(self::ENDPOINT, EP_ROOT | EP_PAGES);

        if (get_option('ccr_rental_agreement_rewrites') !== '1') {
            flush_rewrite_rules(false);
            update_option('ccr_rental_agreement_rewrites', '1');
        }

        if (get_option('ccr_enabled_account_registration') !== 'yes') {
            update_option('woocommerce_enable_myaccount_registration', 'yes');
            update_option('ccr_enabled_account_registration', 'yes');
        }
    }

    public static function add_query_var(array $vars): array
    {
        $vars[] = self::ENDPOINT;

        return $vars;
    }

    public static function wc_query_vars(array $vars): array
    {
        $vars[self::ENDPOINT] = self::ENDPOINT;

        return $vars;
    }

    public static function url(): string
    {
        if (function_exists('wc_get_account_endpoint_url')) {
            return wc_get_account_endpoint_url(self::ENDPOINT);
        }

        return home_url('/my-account/' . self::ENDPOINT . '/');
    }

    public static function menu_items(array $items): array
    {
        $new = [];
        foreach ($items as $key => $label) {
            $new[$key] = $label;
            if ($key === 'edit-account') {
                $new[self::ENDPOINT] = __('Client verification', 'christocentric-rentals');
            }
        }

        if (! isset($new[self::ENDPOINT])) {
            $new[self::ENDPOINT] = __('Client verification', 'christocentric-rentals');
        }

        return $new;
    }

    public static function endpoint_title($title, $id = 0)
    {
        if (in_the_loop() && is_account_page() && is_wc_endpoint_url(self::ENDPOINT)) {
            return __('Client Data Verification', 'christocentric-rentals');
        }

        return $title;
    }

    public static function is_complete(int $userId = 0): bool
    {
        $userId = $userId ?: get_current_user_id();
        if ($userId <= 0) {
            return false;
        }

        if (user_can($userId, 'manage_woocommerce')) {
            return true;
        }

        if (class_exists('CCR_Client_Store')) {
            CCR_Client_Store::migrate_user($userId);
            if (CCR_Client_Store::is_complete($userId)) {
                return true;
            }
        }

        if (get_user_meta($userId, '_ccr_agreement_completed', true) === 'yes') {
            return true;
        }

        if (function_exists('wc_get_orders')) {
            $orders = wc_get_orders([
                'customer_id' => $userId,
                'status' => ['processing', 'completed', 'on-hold'],
                'limit' => 1,
                'return' => 'ids',
            ]);
            if ($orders !== []) {
                return true;
            }
        }

        return false;
    }

    public static function maybe_block_checkout(): void
    {
        if (! function_exists('is_checkout') || ! is_checkout() || is_order_received_page()) {
            return;
        }

        if (! is_user_logged_in()) {
            return;
        }

        if (self::is_complete()) {
            return;
        }

        wc_add_notice(__('Complete client verification before checkout.', 'christocentric-rentals'), 'notice');
        wp_safe_redirect(self::url());
        exit;
    }

    public static function render(): void
    {
        if (! is_user_logged_in()) {
            return;
        }

        $userId = get_current_user_id();
        $values = self::current_values($userId);
        $complete = get_user_meta($userId, '_ccr_agreement_completed', true) === 'yes';
        $template = get_stylesheet_directory() . '/woocommerce/myaccount/form-rental-agreement.php';
        if (! is_file($template)) {
            $template = get_template_directory() . '/woocommerce/myaccount/form-rental-agreement.php';
        }

        if (is_file($template)) {
            include $template;

            return;
        }

        echo '<p>' . esc_html__('Agreement form template is missing.', 'christocentric-rentals') . '</p>';
    }

    /**
     * @return array<string,string>
     */
    public static function text_field_map(): array
    {
        return [
            'company_name' => '_ccr_agreement_company',
            'full_name' => '_ccr_agreement_name',
            'popular_name' => '_ccr_agreement_popular_name',
            'email' => '_ccr_agreement_email',
            'phone' => '_ccr_agreement_phone',
            'emergency_phone' => '_ccr_agreement_emergency_phone',
            'occupation' => '_ccr_agreement_occupation',
            'id_card_type' => '_ccr_agreement_id_type',
            'id_card_number' => '_ccr_agreement_ghana_card',
            'place_of_residence' => '_ccr_agreement_address',
            'gps_address' => '_ccr_agreement_gps',
            'nearest_landmark' => '_ccr_agreement_landmark',
            'instagram' => '_ccr_agreement_instagram',
            'tiktok' => '_ccr_agreement_tiktok',
            'facebook' => '_ccr_agreement_facebook',
            'g1_name' => '_ccr_agreement_g1_name',
            'g1_relationship' => '_ccr_agreement_g1_relationship',
            'g1_phone' => '_ccr_agreement_g1_phone',
            'g1_residence' => '_ccr_agreement_g1_residence',
            'g2_name' => '_ccr_agreement_g2_name',
            'g2_relationship' => '_ccr_agreement_g2_relationship',
            'g2_phone' => '_ccr_agreement_g2_phone',
            'g2_residence' => '_ccr_agreement_g2_residence',
        ];
    }

    /**
     * @return array<string,string>
     */
    public static function file_field_map(): array
    {
        return [
            'id_card_file' => '_ccr_agreement_id_file',
            'gps_photo' => '_ccr_agreement_gps_photo',
            'g1_id_file' => '_ccr_agreement_g1_id_file',
            'g2_id_file' => '_ccr_agreement_g2_id_file',
        ];
    }

    /**
     * @return array<string,string>
     */
    public static function current_values(int $userId): array
    {
        $user = get_userdata($userId);
        $first = (string) get_user_meta($userId, 'billing_first_name', true) ?: (string) get_user_meta($userId, 'first_name', true);
        $last = (string) get_user_meta($userId, 'billing_last_name', true) ?: (string) get_user_meta($userId, 'last_name', true);
        $full = trim($first . ' ' . $last);
        if ($full === '' && $user) {
            $full = $user->display_name;
        }

        $stored = [];
        if (class_exists('CCR_Client_Store')) {
            CCR_Client_Store::migrate_user($userId);
            $stored = CCR_Client_Store::get_payload($userId);
        }

        $values = [];
        foreach (self::text_field_map() as $field => $meta) {
            $values[$field] = (string) ($stored[$field] ?? get_user_meta($userId, $meta, true));
        }
        foreach (self::file_field_map() as $field => $meta) {
            $values[$field] = (string) ($stored[$field] ?? get_user_meta($userId, $meta, true));
        }

        if ($values['full_name'] === '') {
            $values['full_name'] = $full;
        }
        if ($values['email'] === '' && $user) {
            $values['email'] = $user->user_email;
        }
        if ($values['phone'] === '') {
            $values['phone'] = (string) get_user_meta($userId, 'billing_phone', true);
        }
        if ($values['place_of_residence'] === '') {
            $values['place_of_residence'] = (string) get_user_meta($userId, 'billing_address_1', true);
        }
        // Legacy emergency name no longer collected; keep phone only.
        if ($values['emergency_phone'] === '') {
            $values['emergency_phone'] = (string) get_user_meta($userId, '_ccr_agreement_emergency_name', true);
        }

        return $values;
    }

    public static function handle_save(): void
    {
        if (! is_user_logged_in()) {
            wp_die(esc_html__('You must be signed in.', 'christocentric-rentals'));
        }
        check_admin_referer('ccr_rental_agreement');

        // Ensure WooCommerce notices/session work on admin-post.
        if (function_exists('wc_load_cart')) {
            wc_load_cart();
        }

        $userId = get_current_user_id();
        $fields = [];
        foreach (array_keys(self::text_field_map()) as $field) {
            $raw = sanitize_text_field(wp_unslash((string) ($_POST[$field] ?? '')));
            if ($field === 'id_card_number' || $field === 'email') {
                $raw = $field === 'email' ? sanitize_email(wp_unslash((string) ($_POST[$field] ?? ''))) : strtoupper($raw);
            }
            $fields[$field] = $raw;
        }

        $existingFiles = [];
        $stored = class_exists('CCR_Client_Store') ? CCR_Client_Store::get_payload($userId) : [];
        foreach (self::file_field_map() as $field => $meta) {
            $existingFiles[$field] = (string) ($stored[$field] ?? get_user_meta($userId, $meta, true));
        }

        $uploads = [];
        foreach (array_keys(self::file_field_map()) as $field) {
            $uploaded = self::handle_upload($field, $userId);
            if (is_wp_error($uploaded)) {
                wc_add_notice($uploaded->get_error_message(), 'error');
                wp_safe_redirect(self::url());
                exit;
            }
            if ($uploaded !== '' && $uploaded !== 0) {
                $uploads[$field] = (string) $uploaded;
            }
        }

        $fileIds = array_merge($existingFiles, $uploads);
        $errors = self::validate(
            $fields,
            $fileIds,
            ! empty($_POST['declare_read']),
            ! empty($_POST['declare_consent']),
            ! empty($_POST['declare_rights'])
        );

        if ($errors !== []) {
            foreach ($errors as $error) {
                wc_add_notice($error, 'error');
            }
            wp_safe_redirect(self::url());
            exit;
        }

        if (class_exists('CCR_Client_Store') && CCR_Client_Store::is_ready()) {
            CCR_Client_Store::save($userId, $fields, $fileIds, true);
        }

        foreach (self::text_field_map() as $field => $meta) {
            update_user_meta($userId, $meta, $fields[$field]);
        }
        foreach (self::file_field_map() as $field => $meta) {
            if (! empty($fileIds[$field])) {
                update_user_meta($userId, $meta, (string) $fileIds[$field]);
            }
        }

        // Keep legacy keys used by older order display / pickup copy.
        update_user_meta($userId, '_ccr_agreement_city', '');
        update_user_meta($userId, '_ccr_agreement_emergency_name', $fields['g1_name']);
        update_user_meta($userId, '_ccr_agreement_signature', $fields['full_name']);
        update_user_meta($userId, '_ccr_agreement_completed', 'yes');
        update_user_meta($userId, '_ccr_agreement_signed_at', gmdate('c'));
        update_user_meta($userId, '_ccr_needs_rental_agreement', 'no');

        $parts = preg_split('/\s+/', $fields['full_name']) ?: [];
        $first = array_shift($parts) ?: $fields['full_name'];
        $last = implode(' ', $parts);
        update_user_meta($userId, 'first_name', $first);
        update_user_meta($userId, 'last_name', $last);
        update_user_meta($userId, 'billing_first_name', $first);
        update_user_meta($userId, 'billing_last_name', $last);
        update_user_meta($userId, 'billing_phone', $fields['phone']);
        update_user_meta($userId, 'billing_email', $fields['email']);
        update_user_meta($userId, 'billing_address_1', $fields['place_of_residence']);
        update_user_meta($userId, 'billing_country', 'GH');

        wc_add_notice(__('Client verification saved. You can now rent gear.', 'christocentric-rentals'), 'success');

        $next = wc_get_page_permalink('shop');
        if (function_exists('WC')) {
            $wc = WC();
            if ($wc && isset($wc->cart) && $wc->cart && (int) $wc->cart->get_cart_contents_count() > 0) {
                $next = wc_get_checkout_url();
            }
        }

        wp_safe_redirect($next ?: home_url('/'));
        exit;
    }

    /**
     * @return string|int|WP_Error File ref, 0 if no file, or error.
     */
    private static function handle_upload(string $field, int $userId)
    {
        if (class_exists('CCR_Client_Store') && CCR_Client_Store::is_ready()) {
            $stored = CCR_Client_Store::store_upload($field, $userId);
            if ($stored === '') {
                return 0;
            }

            return $stored;
        }

        if (empty($_FILES[$field]) || ! is_array($_FILES[$field])) {
            return 0;
        }

        $file = $_FILES[$field];
        $errorCode = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($errorCode === UPLOAD_ERR_NO_FILE) {
            return 0;
        }
        if ($errorCode !== UPLOAD_ERR_OK) {
            return new WP_Error('ccr_upload', sprintf(
                /* translators: %s: field label */
                __('Could not upload %s. Try a smaller JPG, PNG, or PDF (under 5MB).', 'christocentric-rentals'),
                $field
            ));
        }

        // Phone photos can be huge; reject oversized files before WordPress tries to process them.
        $maxBytes = 8 * MB_IN_BYTES;
        $size = (int) ($file['size'] ?? 0);
        if ($size > $maxBytes) {
            return new WP_Error('ccr_upload', __('One of your files is too large. Please upload images under 8MB.', 'christocentric-rentals'));
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $overrides = [
            'test_form' => false,
            'mimes' => [
                'jpg|jpeg|jpe' => 'image/jpeg',
                'png' => 'image/png',
                'webp' => 'image/webp',
                'pdf' => 'application/pdf',
            ],
        ];

        add_filter('upload_dir', ['CCR_Private_Media', 'upload_dir']);
        $moved = wp_handle_upload($file, $overrides);
        remove_filter('upload_dir', ['CCR_Private_Media', 'upload_dir']);

        if (! is_array($moved) || ! empty($moved['error'])) {
            return new WP_Error('ccr_upload', is_array($moved) ? (string) $moved['error'] : __('Upload failed.', 'christocentric-rentals'));
        }

        $attachment = [
            'post_mime_type' => $moved['type'],
            'post_title' => sanitize_file_name(pathinfo((string) $moved['file'], PATHINFO_FILENAME)),
            'post_content' => '',
            'post_status' => 'private',
            'post_author' => $userId,
        ];
        $attachId = wp_insert_attachment($attachment, $moved['file']);
        if (is_wp_error($attachId) || ! $attachId) {
            return new WP_Error('ccr_upload', __('Could not save uploaded file.', 'christocentric-rentals'));
        }

        if (class_exists('CCR_Private_Media')) {
            CCR_Private_Media::mark_attachment((int) $attachId);
        }

        // Skip intermediate image sizes — ID docs are private and large phone photos often fatal the site.
        add_filter('intermediate_image_sizes_advanced', '__return_empty_array', 999);
        add_filter('wp_generate_attachment_metadata', [self::class, 'strip_image_sizes'], 999);
        try {
            $meta = wp_generate_attachment_metadata((int) $attachId, $moved['file']);
            if (is_array($meta)) {
                unset($meta['sizes']);
                wp_update_attachment_metadata((int) $attachId, $meta);
            }
        } catch (Throwable $e) {
            // File is already saved; metadata is optional for verification docs.
            error_log('[Christocentric Rentals] attachment metadata skipped: ' . $e->getMessage());
        }
        remove_filter('intermediate_image_sizes_advanced', '__return_empty_array', 999);
        remove_filter('wp_generate_attachment_metadata', [self::class, 'strip_image_sizes'], 999);

        return (int) $attachId;
    }

    /**
     * @param array<string,mixed> $metadata
     * @return array<string,mixed>
     */
    public static function strip_image_sizes(array $metadata): array
    {
        $metadata['sizes'] = [];

        return $metadata;
    }

    /**
     * @param array<string,string> $fields
     * @param array<string,string|int> $fileIds
     * @return list<string>
     */
    private static function validate(array $fields, array $fileIds, bool $read, bool $consent, bool $rights): array
    {
        $errors = [];
        $required = [
            'company_name' => __('Enter your company name (or “Individual”).', 'christocentric-rentals'),
            'full_name' => __('Enter your full name.', 'christocentric-rentals'),
            'popular_name' => __('Enter your popular / known-as name.', 'christocentric-rentals'),
            'email' => __('Enter your active email address.', 'christocentric-rentals'),
            'phone' => __('Enter your active phone number.', 'christocentric-rentals'),
            'emergency_phone' => __('Enter an active emergency number.', 'christocentric-rentals'),
            'occupation' => __('Enter your occupation / profession.', 'christocentric-rentals'),
            'id_card_type' => __('Select your ID card type.', 'christocentric-rentals'),
            'id_card_number' => __('Enter your ID card number.', 'christocentric-rentals'),
            'place_of_residence' => __('Enter your place of residence.', 'christocentric-rentals'),
            'gps_address' => __('Enter your GPS address.', 'christocentric-rentals'),
            'nearest_landmark' => __('Enter the nearest landmark.', 'christocentric-rentals'),
            'instagram' => __('Enter your Instagram handle.', 'christocentric-rentals'),
            'g1_name' => __('Enter guarantor 1 full name.', 'christocentric-rentals'),
            'g1_relationship' => __('Select guarantor 1 relationship.', 'christocentric-rentals'),
            'g1_phone' => __('Enter guarantor 1 phone number.', 'christocentric-rentals'),
            'g1_residence' => __('Enter guarantor 1 place of residence.', 'christocentric-rentals'),
        ];

        foreach ($required as $key => $message) {
            if (trim($fields[$key] ?? '') === '') {
                $errors[] = $message;
            }
        }

        if ($fields['email'] !== '' && ! is_email($fields['email'])) {
            $errors[] = __('Enter a valid email address.', 'christocentric-rentals');
        }

        $allowedId = ['ghana_card', 'drivers_license', 'passport'];
        if ($fields['id_card_type'] !== '' && ! in_array($fields['id_card_type'], $allowedId, true)) {
            $errors[] = __('Choose a valid ID card type.', 'christocentric-rentals');
        }

        if (strlen(preg_replace('/\s+/', '', (string) $fields['id_card_number']) ?: '') < 5) {
            $errors[] = __('ID card number looks too short.', 'christocentric-rentals');
        }

        if (empty($fileIds['id_card_file'])) {
            $errors[] = __('Upload a clear photo of your ID (front and back).', 'christocentric-rentals');
        }
        if (empty($fileIds['gps_photo'])) {
            $errors[] = __('Upload a photo of your residential GPS address.', 'christocentric-rentals');
        }
        if (empty($fileIds['g1_id_file'])) {
            $errors[] = __('Upload guarantor 1 Ghana Card (front and back).', 'christocentric-rentals');
        }

        $g2Filled = $fields['g2_name'] !== '' || $fields['g2_relationship'] !== '' || $fields['g2_phone'] !== '' || $fields['g2_residence'] !== '';
        if ($g2Filled) {
            foreach (['g2_name', 'g2_relationship', 'g2_phone', 'g2_residence'] as $key) {
                if (trim($fields[$key]) === '') {
                    $errors[] = __('Complete all guarantor 2 fields, or leave them blank.', 'christocentric-rentals');
                    break;
                }
            }
            if (empty($fileIds['g2_id_file'])) {
                $errors[] = __('Upload guarantor 2 Ghana Card (front and back).', 'christocentric-rentals');
            }
        }

        if (! $read || ! $consent || ! $rights) {
            $errors[] = __('Confirm all three declarations to submit.', 'christocentric-rentals');
        }

        return array_values(array_unique($errors));
    }

    /**
     * @return list<string>
     */
    private static function agreement_meta_keys(): array
    {
        $keys = array_values(self::text_field_map());
        $keys = array_merge($keys, array_values(self::file_field_map()));
        $keys[] = '_ccr_agreement_signed_at';
        $keys[] = '_ccr_agreement_completed';
        $keys[] = '_ccr_agreement_signature';
        $keys[] = '_ccr_agreement_emergency_name';
        $keys[] = '_ccr_agreement_id_file_url';
        $keys[] = '_ccr_agreement_gps_photo_url';
        $keys[] = '_ccr_agreement_g1_id_file_url';
        $keys[] = '_ccr_agreement_g2_id_file_url';
        $keys[] = '_ccr_agreement_imported';

        return array_values(array_unique($keys));
    }

    public static function copy_to_order(WC_Order $order): void
    {
        $userId = $order->get_user_id();
        if ($userId <= 0) {
            return;
        }

        foreach (self::agreement_meta_keys() as $key) {
            $value = '';
            if (class_exists('CCR_Client_Store')) {
                $payload = CCR_Client_Store::get_payload($userId);
                $flip = array_flip(array_merge(self::text_field_map(), self::file_field_map()));
                if (isset($flip[$key]) && isset($payload[$flip[$key]])) {
                    $value = (string) $payload[$flip[$key]];
                }
            }
            if ($value === '') {
                $value = (string) get_user_meta($userId, $key, true);
            }
            if ($value !== '') {
                $order->update_meta_data($key, $value);
            }
        }
    }

    /**
     * @return array<string,string>
     */
    private static function agreement_from_order(WC_Order $order): array
    {
        $userId = $order->get_user_id();
        $data = [];
        foreach (self::agreement_meta_keys() as $key) {
            $value = (string) $order->get_meta($key);
            if ($value === '' && $userId > 0) {
                $value = (string) get_user_meta($userId, $key, true);
            }
            $data[$key] = $value;
        }

        return $data;
    }

    private static function file_link(string $attachId, string $fallbackUrl = ''): string
    {
        if (class_exists('CCR_Client_Store') && CCR_Client_Store::parse_file_id($attachId) > 0) {
            $url = CCR_Client_Store::file_url($attachId);
            $label = CCR_Client_Store::file_display_name($attachId) ?: __('View file', 'christocentric-rentals');
            if ($url !== '') {
                return '<a href="' . esc_url($url) . '" target="_blank" rel="noopener">' . esc_html($label) . '</a>';
            }
        }

        $id = (int) $attachId;
        if ($id > 0) {
            $url = wp_get_attachment_url($id);
            if ($url) {
                return '<a href="' . esc_url($url) . '" target="_blank" rel="noopener">' . esc_html__('View file', 'christocentric-rentals') . '</a>';
            }
        }

        $fallbackUrl = esc_url_raw($fallbackUrl);
        if ($fallbackUrl !== '') {
            return '<a href="' . esc_url($fallbackUrl) . '" target="_blank" rel="noopener">' . esc_html__('View Drive / file link', 'christocentric-rentals') . '</a>';
        }

        return '';
    }

    public static function render_order_id(WC_Order $order): void
    {
        $data = self::agreement_from_order($order);
        if ($data['_ccr_agreement_name'] === '' && $data['_ccr_agreement_ghana_card'] === '') {
            return;
        }

        $signedAt = $data['_ccr_agreement_signed_at'];
        if ($signedAt !== '') {
            $ts = strtotime($signedAt);
            $signedAt = $ts ? wp_date(get_option('date_format') . ' ' . get_option('time_format'), $ts) : $signedAt;
        }

        $idTypes = [
            'ghana_card' => __('Ghana Card', 'christocentric-rentals'),
            'drivers_license' => __('Driver\'s License', 'christocentric-rentals'),
            'passport' => __('National Passport', 'christocentric-rentals'),
        ];
        $idType = $idTypes[$data['_ccr_agreement_id_type']] ?? $data['_ccr_agreement_id_type'];

        $rows = [
            __('Company', 'christocentric-rentals') => $data['_ccr_agreement_company'],
            __('Full name', 'christocentric-rentals') => $data['_ccr_agreement_name'],
            __('Popular name', 'christocentric-rentals') => $data['_ccr_agreement_popular_name'],
            __('Email', 'christocentric-rentals') => $data['_ccr_agreement_email'],
            __('Phone', 'christocentric-rentals') => $data['_ccr_agreement_phone'],
            __('Emergency number', 'christocentric-rentals') => $data['_ccr_agreement_emergency_phone'],
            __('Occupation', 'christocentric-rentals') => $data['_ccr_agreement_occupation'],
            __('ID type', 'christocentric-rentals') => $idType,
            __('ID number', 'christocentric-rentals') => $data['_ccr_agreement_ghana_card'],
            __('Residence', 'christocentric-rentals') => $data['_ccr_agreement_address'],
            __('GPS address', 'christocentric-rentals') => $data['_ccr_agreement_gps'],
            __('Nearest landmark', 'christocentric-rentals') => $data['_ccr_agreement_landmark'],
            __('Instagram', 'christocentric-rentals') => $data['_ccr_agreement_instagram'],
            __('TikTok', 'christocentric-rentals') => $data['_ccr_agreement_tiktok'],
            __('Facebook', 'christocentric-rentals') => $data['_ccr_agreement_facebook'],
            __('Guarantor 1', 'christocentric-rentals') => trim($data['_ccr_agreement_g1_name'] . ' · ' . $data['_ccr_agreement_g1_relationship'] . ' · ' . $data['_ccr_agreement_g1_phone']),
            __('Guarantor 1 residence', 'christocentric-rentals') => $data['_ccr_agreement_g1_residence'],
            __('Guarantor 2', 'christocentric-rentals') => trim($data['_ccr_agreement_g2_name'] . ' · ' . $data['_ccr_agreement_g2_relationship'] . ' · ' . $data['_ccr_agreement_g2_phone']),
            __('Guarantor 2 residence', 'christocentric-rentals') => $data['_ccr_agreement_g2_residence'],
            __('Signed at', 'christocentric-rentals') => $signedAt,
        ];

        $files = [
            __('Client ID upload', 'christocentric-rentals') => self::file_link($data['_ccr_agreement_id_file'] ?? '', $data['_ccr_agreement_id_file_url'] ?? ''),
            __('GPS photo', 'christocentric-rentals') => self::file_link($data['_ccr_agreement_gps_photo'] ?? '', $data['_ccr_agreement_gps_photo_url'] ?? ''),
            __('Guarantor 1 ID', 'christocentric-rentals') => self::file_link($data['_ccr_agreement_g1_id_file'] ?? '', $data['_ccr_agreement_g1_id_file_url'] ?? ''),
            __('Guarantor 2 ID', 'christocentric-rentals') => self::file_link($data['_ccr_agreement_g2_id_file'] ?? '', $data['_ccr_agreement_g2_id_file_url'] ?? ''),
        ];

        echo '<div class="ccr-order-agreement" style="margin:12px 0 0;padding:12px;border:1px solid #c3c4c7;border-radius:4px;background:#f6f7f7;max-width:520px">';
        echo '<h3 style="margin:0 0 8px;font-size:13px">' . esc_html__('Client verification / rental agreement', 'christocentric-rentals') . '</h3>';
        foreach ($rows as $label => $value) {
            $value = trim((string) $value, " ·");
            if ($value === '') {
                continue;
            }
            echo '<p class="form-field" style="margin:0 0 4px"><strong>' . esc_html($label) . ':</strong> '
                . esc_html($value) . '</p>';
        }
        foreach ($files as $label => $html) {
            if ($html === '') {
                continue;
            }
            echo '<p class="form-field" style="margin:0 0 4px"><strong>' . esc_html($label) . ':</strong> '
                . $html . '</p>';
        }
        echo '</div>';
    }
}
