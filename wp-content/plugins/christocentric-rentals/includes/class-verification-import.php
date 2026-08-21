<?php

defined('ABSPATH') || exit;

/**
 * Import Google Form / CSV client verification rows into WP user meta (match by email).
 */
final class CCR_Verification_Import
{
    public static function init(): void
    {
        add_action('admin_post_ccr_import_verification_csv', [self::class, 'handle_import']);
        add_action('admin_post_ccr_download_verification_template', [self::class, 'download_template']);
    }

    public static function download_template(): void
    {
        if (! current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Forbidden', 'christocentric-rentals'));
        }
        check_admin_referer('ccr_download_verification_template');

        $headers = [
            'email',
            'company_name',
            'full_name',
            'popular_name',
            'phone',
            'emergency_phone',
            'occupation',
            'id_card_type',
            'id_card_number',
            'place_of_residence',
            'gps_address',
            'nearest_landmark',
            'instagram',
            'tiktok',
            'facebook',
            'g1_name',
            'g1_relationship',
            'g1_phone',
            'g1_residence',
            'g2_name',
            'g2_relationship',
            'g2_phone',
            'g2_residence',
            'id_card_file_url',
            'gps_photo_url',
            'g1_id_file_url',
            'g2_id_file_url',
            'create_account',
        ];

        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=ccr-verification-import-template.csv');
        $out = fopen('php://output', 'w');
        fputcsv($out, $headers);
        fputcsv($out, [
            'client@example.com',
            'Individual',
            'Jane Doe',
            'Maame Jane',
            '0240000000',
            '0200000000',
            'Videographer',
            'ghana_card',
            'GHA-123456789-1',
            'Bomso, Kumasi',
            'AK-000-0000',
            'Near Abesse',
            '@jane',
            '',
            '',
            'John Doe',
            'Parent',
            '0241111111',
            'Kumasi',
            '',
            '',
            '',
            '',
            'https://drive.google.com/file/d/xxx/view',
            'https://drive.google.com/file/d/yyy/view',
            'https://drive.google.com/file/d/zzz/view',
            '',
            'yes',
        ]);
        fclose($out);
        exit;
    }

    public static function handle_import(): void
    {
        if (! current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Forbidden', 'christocentric-rentals'));
        }
        check_admin_referer('ccr_import_verification_csv');

        if (empty($_FILES['ccr_verification_csv']['tmp_name']) || ! is_uploaded_file((string) $_FILES['ccr_verification_csv']['tmp_name'])) {
            self::redirect_result(0, 0, 0, ['No CSV file uploaded.']);
        }

        $createMissing = ! empty($_POST['ccr_create_missing_accounts']);
        $path = (string) $_FILES['ccr_verification_csv']['tmp_name'];
        $handle = fopen($path, 'r');
        if (! $handle) {
            self::redirect_result(0, 0, 0, ['Could not read CSV.']);
        }

        $header = fgetcsv($handle);
        if (! is_array($header) || $header === []) {
            fclose($handle);
            self::redirect_result(0, 0, 0, ['CSV has no header row.']);
        }

        // Strip UTF-8 BOM from first cell.
        if (isset($header[0])) {
            $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]) ?? (string) $header[0];
        }

        $map = self::map_headers($header);
        if (! isset($map['email'])) {
            fclose($handle);
            self::redirect_result(0, 0, 0, ['CSV must include an email column (e.g. “ACTIVE EMAIL ADDRESS” or “email”).']);
        }

        $updated = 0;
        $created = 0;
        $skipped = 0;
        $errors = [];

        while (($row = fgetcsv($handle)) !== false) {
            if (! is_array($row) || self::row_empty($row)) {
                continue;
            }

            $data = self::row_to_data($row, $map);
            $email = sanitize_email((string) ($data['email'] ?? ''));
            if (! is_email($email)) {
                $skipped++;
                $errors[] = 'Skipped row with invalid email.';
                continue;
            }

            $user = get_user_by('email', $email);
            $didCreate = false;
            if (! $user instanceof WP_User) {
                $shouldCreate = $createMissing || self::truthy($data['create_account'] ?? '');
                if (! $shouldCreate) {
                    $skipped++;
                    $errors[] = sprintf('No account for %s (enable “create missing accounts” or set create_account=yes).', $email);
                    continue;
                }
                if (! function_exists('wc_create_new_customer')) {
                    $skipped++;
                    $errors[] = sprintf('WooCommerce missing; cannot create %s.', $email);
                    continue;
                }
                $password = wp_generate_password(14, true);
                $username = function_exists('wc_create_new_customer_username')
                    ? wc_create_new_customer_username($email)
                    : sanitize_user(current(explode('@', $email)), true);
                $customerId = wc_create_new_customer($email, $username, $password);
                if (is_wp_error($customerId)) {
                    $skipped++;
                    $errors[] = sprintf('%s: %s', $email, $customerId->get_error_message());
                    continue;
                }
                $user = get_user_by('id', (int) $customerId);
                if (! $user instanceof WP_User) {
                    $skipped++;
                    continue;
                }
                $didCreate = true;
                $created++;
                update_user_meta($user->ID, '_ccr_imported_needs_password_reset', 'yes');
            }

            self::apply_row_to_user($user->ID, $data);
            if (! $didCreate) {
                $updated++;
            }
        }

        fclose($handle);
        self::redirect_result($updated, $created, $skipped, array_slice(array_unique($errors), 0, 8));
    }

    /**
     * @param array<string,string> $data
     */
    private static function apply_row_to_user(int $userId, array $data): void
    {
        $textMap = [
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

        foreach ($textMap as $field => $meta) {
            if (! isset($data[$field]) || trim($data[$field]) === '') {
                continue;
            }
            $value = sanitize_text_field($data[$field]);
            if ($field === 'email') {
                $value = sanitize_email($data[$field]);
            }
            if ($field === 'id_card_number') {
                $value = strtoupper($value);
            }
            if ($field === 'id_card_type') {
                $value = self::normalize_id_type($value);
            }
            update_user_meta($userId, $meta, $value);
        }

        $urlMap = [
            'id_card_file_url' => '_ccr_agreement_id_file_url',
            'gps_photo_url' => '_ccr_agreement_gps_photo_url',
            'g1_id_file_url' => '_ccr_agreement_g1_id_file_url',
            'g2_id_file_url' => '_ccr_agreement_g2_id_file_url',
        ];
        foreach ($urlMap as $field => $meta) {
            $url = esc_url_raw((string) ($data[$field] ?? ''));
            if ($url !== '') {
                update_user_meta($userId, $meta, $url);
            }
        }

        $fullName = (string) get_user_meta($userId, '_ccr_agreement_name', true);
        $phone = (string) get_user_meta($userId, '_ccr_agreement_phone', true);
        $address = (string) get_user_meta($userId, '_ccr_agreement_address', true);
        $email = (string) get_user_meta($userId, '_ccr_agreement_email', true);
        $g1Name = (string) get_user_meta($userId, '_ccr_agreement_g1_name', true);

        if ($fullName !== '') {
            $parts = preg_split('/\s+/', $fullName) ?: [];
            $first = array_shift($parts) ?: $fullName;
            $last = implode(' ', $parts);
            update_user_meta($userId, 'first_name', $first);
            update_user_meta($userId, 'last_name', $last);
            update_user_meta($userId, 'billing_first_name', $first);
            update_user_meta($userId, 'billing_last_name', $last);
            update_user_meta($userId, '_ccr_agreement_signature', $fullName);
        }
        if ($phone !== '') {
            update_user_meta($userId, 'billing_phone', $phone);
        }
        if ($address !== '') {
            update_user_meta($userId, 'billing_address_1', $address);
        }
        if ($email !== '' && is_email($email)) {
            update_user_meta($userId, 'billing_email', $email);
        }
        if ($g1Name !== '') {
            update_user_meta($userId, '_ccr_agreement_emergency_name', $g1Name);
        }

        update_user_meta($userId, 'billing_country', 'GH');
        update_user_meta($userId, '_ccr_agreement_completed', 'yes');
        update_user_meta($userId, '_ccr_needs_rental_agreement', 'no');
        if (get_user_meta($userId, '_ccr_agreement_signed_at', true) === '') {
            update_user_meta($userId, '_ccr_agreement_signed_at', gmdate('c'));
        }
        update_user_meta($userId, '_ccr_agreement_imported', 'yes');

        if (class_exists('CCR_Client_Store') && CCR_Client_Store::is_ready()) {
            $fields = [];
            foreach (array_keys($textMap) as $field) {
                $fields[$field] = (string) get_user_meta($userId, $textMap[$field], true);
            }
            CCR_Client_Store::save($userId, $fields, [], true);
        }
    }

    private static function normalize_id_type(string $value): string
    {
        $v = strtolower(trim($value));
        if (str_contains($v, 'driver')) {
            return 'drivers_license';
        }
        if (str_contains($v, 'passport')) {
            return 'passport';
        }

        return 'ghana_card';
    }

    /**
     * @param list<string> $header
     * @return array<string,int>
     */
    private static function map_headers(array $header): array
    {
        $aliases = [
            'email' => ['email', 'active email address', 'email address', 'e-mail'],
            'company_name' => ['company name', 'company'],
            'full_name' => ['full name', 'name'],
            'popular_name' => ['popular name in your area of residence', 'popular name'],
            'phone' => ['active phone number', 'phone', 'phone number'],
            'emergency_phone' => ['active emergency number', 'emergency number', 'emergency phone'],
            'occupation' => ['occupation / profession', 'occupation', 'profession'],
            'id_card_type' => ['id card type client', 'id card type', 'id type'],
            'id_card_number' => ['id card number', 'ghana card number', 'id number'],
            'place_of_residence' => ['place of residence', 'residence', 'address'],
            'gps_address' => ['gps address'],
            'nearest_landmark' => ['nearest landmark to place of residence', 'nearest landmark', 'landmark'],
            'instagram' => ['personal instagram handle', 'instagram'],
            'tiktok' => ['personal tiktok handle', 'tiktok'],
            'facebook' => ['personal facebook handle', 'facebook'],
            'g1_name' => ['full name of guarantor 1', 'guarantor 1 full name', 'guarantor 1 name'],
            'g2_name' => ['full name of guarantor 2', 'guarantor 2 full name', 'guarantor 2 name'],
            'create_account' => ['create_account', 'create account'],
            'id_card_file_url' => [
                'a scanned copy or clear photo of your ghana card (front and back)',
                'id card file url',
                'ghana card upload',
            ],
            'gps_photo_url' => ['gps photo url', 'photo of your residential gps address'],
            'g1_id_file_url' => [
                'a scanned copy or clear photo of guarantor\'s ghana card (front and back)',
                'g1 id file url',
                'guarantor 1 ghana card',
            ],
            'g2_id_file_url' => [
                'a scanned copy or clear photo of guarantor\'s ghana card (front and back)',
                'g2 id file url',
                'guarantor 2 ghana card',
            ],
        ];

        $map = [];
        $used = [];

        $matchField = static function (string $norm, array $names): bool {
            foreach ($names as $name) {
                $n = self::normalize_header($name);
                if ($norm === $n || str_contains($norm, $n)) {
                    return true;
                }
            }

            return false;
        };

        foreach ($header as $index => $label) {
            $norm = self::normalize_header((string) $label);
            if ($norm === '' || in_array($index, $used, true)) {
                continue;
            }
            foreach ($aliases as $field => $names) {
                if (isset($map[$field])) {
                    continue;
                }
                // Avoid binding client's "active phone number" to guarantor phone aliases later.
                if ($field === 'phone' && str_contains($norm, 'guarantor')) {
                    continue;
                }
                if ($field === 'place_of_residence' && str_contains($norm, 'guarantor')) {
                    continue;
                }
                if ($field === 'g1_id_file_url' && str_contains($norm, 'guarantor 2')) {
                    continue;
                }
                if ($field === 'g2_id_file_url' && str_contains($norm, 'your ghana card')) {
                    continue;
                }
                if ($matchField($norm, $names)) {
                    // Second identical guarantor ID upload column → g2.
                    if ($field === 'g1_id_file_url' && isset($map['g1_id_file_url'])) {
                        continue;
                    }
                    $map[$field] = $index;
                    $used[] = $index;
                    break;
                }
            }
        }

        // Duplicate Google Form headers (relationship / guarantor phone / residence / uploads).
        $dupQueues = [
            'relationship to you' => ['g1_relationship', 'g2_relationship'],
            'active phone number of guarantor' => ['g1_phone', 'g2_phone'],
            'place of residence of guarantor' => ['g1_residence', 'g2_residence'],
            'a scanned copy or clear photo of guarantor\'s ghana card (front and back)' => ['g1_id_file_url', 'g2_id_file_url'],
        ];
        foreach ($dupQueues as $label => $fields) {
            $queue = $fields;
            foreach ($header as $index => $raw) {
                if ($queue === [] || in_array($index, $used, true)) {
                    continue;
                }
                $norm = self::normalize_header((string) $raw);
                if ($norm !== self::normalize_header($label) && ! str_contains($norm, self::normalize_header($label))) {
                    continue;
                }
                $field = array_shift($queue);
                if ($field && ! isset($map[$field])) {
                    $map[$field] = $index;
                    $used[] = $index;
                }
            }
        }

        return $map;
    }

    private static function normalize_header(string $label): string
    {
        $label = strtolower(trim($label));
        $label = preg_replace('/\s+/', ' ', $label) ?? $label;

        return trim($label, " \t\n\r\0\x0B*");
    }

    /**
     * @param list<string|null> $row
     * @param array<string,int> $map
     * @return array<string,string>
     */
    private static function row_to_data(array $row, array $map): array
    {
        $data = [];
        foreach ($map as $field => $index) {
            $data[$field] = trim((string) ($row[$index] ?? ''));
        }

        return $data;
    }

    /**
     * @param list<string|null> $row
     */
    private static function row_empty(array $row): bool
    {
        foreach ($row as $cell) {
            if (trim((string) $cell) !== '') {
                return false;
            }
        }

        return true;
    }

    private static function truthy(string $value): bool
    {
        $v = strtolower(trim($value));

        return in_array($v, ['1', 'yes', 'y', 'true'], true);
    }

    /**
     * @param list<string> $errors
     */
    private static function redirect_result(int $updated, int $created, int $skipped, array $errors): void
    {
        $args = [
            'page' => 'christocentric-rentals',
            'ccr_import' => 1,
            'updated' => $updated,
            'created' => $created,
            'skipped' => $skipped,
        ];
        if ($errors !== []) {
            $args['ccr_import_msg'] = implode(' | ', $errors);
        }
        wp_safe_redirect(add_query_arg($args, admin_url('admin.php')));
        exit;
    }
}
