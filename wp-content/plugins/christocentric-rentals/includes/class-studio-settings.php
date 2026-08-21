<?php

defined('ABSPATH') || exit;

/**
 * Global studio booking page settings (hero, stats, add-ons, hours, deposits).
 */
final class CCR_Studio_Settings
{
    public const OPTION = 'ccr_studio_booking_settings';

    public static function init(): void
    {
        add_action('admin_menu', [self::class, 'menu']);
        add_action('admin_init', [self::class, 'register']);
        add_action('init', [self::class, 'maybe_upgrade_catalog'], 25);
        add_action('admin_enqueue_scripts', [self::class, 'admin_assets']);
    }

    public static function admin_assets(string $hook): void
    {
        if ($hook !== 'ccr_studio_page_ccr-studio-settings') {
            return;
        }
        wp_enqueue_media();
    }

    /** Seed the full add-on catalog once (or when still on the old 3-item list). */
    public static function maybe_upgrade_catalog(): void
    {
        $stored = get_option(self::OPTION, []);
        if (! is_array($stored)) {
            $stored = [];
        }
        $changed = false;
        $version = (int) ($stored['addons_catalog_version'] ?? 0);
        if ($version < 5) {
            if (! empty($stored['addons']) && is_array($stored['addons'])) {
                $stored['addons'] = array_values(array_filter($stored['addons'], static function ($addon): bool {
                    $id = is_array($addon) ? (string) ($addon['id'] ?? '') : '';

                    return ! in_array($id, ['crane_jib', 'scaffold'], true);
                }));
            }
            $stored['addons_catalog_version'] = 5;
            $changed = true;
        }
        if ($version < 6) {
            $stored['promo_text'] = '';
            $stored['promo_badge'] = '';
            $stored['promo_url'] = '';
            $stored['addons_catalog_version'] = 6;
            $changed = true;
        }
        if (empty($stored['close_time'])) {
            $siteClose = (string) get_option('ccr_latest_return_time', '20:50');
            $stored['close_time'] = preg_match('/^\d{1,2}:\d{2}$/', $siteClose) ? $siteClose : '20:50';
            unset($stored['close_hour']);
            $changed = true;
        }
        // Opening hour is 8 AM (not 7).
        if (! isset($stored['open_hour']) || (int) $stored['open_hour'] === 7) {
            $stored['open_hour'] = 8;
            $changed = true;
        }
        // Studio bookings are full MoMo payment only.
        if (($stored['deposit_50'] ?? '') !== 'no' || ($stored['deposit_100'] ?? '') !== 'yes') {
            $stored['deposit_50'] = 'no';
            $stored['deposit_100'] = 'yes';
            $changed = true;
        }
        if (empty($stored['momo_reference'])) {
            $stored['momo_reference'] = 'Studio Rentals';
            $changed = true;
        }
        if (empty($stored['momo_pay_number'])) {
            $stored['momo_pay_number'] = (string) (self::defaults()['momo_pay_number'] ?? '233532670582');
            $changed = true;
        }
        if ($changed) {
            update_option(self::OPTION, $stored, false);
        }
    }

    public static function menu(): void
    {
        add_submenu_page(
            'edit.php?post_type=' . CCR_Studio_Cpt::POST_TYPE,
            __('Studio Settings', 'christocentric-rentals'),
            __('Settings', 'christocentric-rentals'),
            'manage_options',
            'ccr-studio-settings',
            [self::class, 'render']
        );
    }

    public static function register(): void
    {
        register_setting('ccr_studio_settings', self::OPTION, [
            'type' => 'array',
            'sanitize_callback' => [self::class, 'sanitize'],
            'default' => self::defaults(),
        ]);
    }

    public static function defaults(): array
    {
        $phone = '233532670582';
        if (function_exists('ccr_site_config')) {
            $raw = (string) ccr_site_config('contact.phone', '+233532670582');
            $digits = preg_replace('/\D+/', '', $raw) ?: $phone;
            $phone = $digits;
        }

        return [
            'eyebrow' => 'Kumasi · Bomso · Open daily',
            'title_line_1' => 'Reserve',
            'title_line_2' => 'Your',
            'title_emphasis' => 'Studio',
            'lead' => 'Book a set in our Bomso studio for interviews, portraits, and content shoots — confirmed after MoMo payment.',
            'address' => 'Bomso, near Obesse Gaming Center, Kumasi, Ghana',
            'whatsapp' => $phone,
            'notify_email' => 'christocentricrentals@gmail.com',
            'hero_image_id' => 0,
            'open_hour' => 8,
            'close_time' => '20:50',
            'minute_offsets' => [0, 15, 30, 45],
            'deposit_50' => 'no',
            'deposit_100' => 'yes',
            'momo_pay_number' => $phone,
            'momo_pay_network' => 'MTN',
            'momo_reference' => 'Studio Rentals',
            'promo_text' => '',
            'promo_badge' => '',
            'promo_url' => '',
            'stats' => [
                ['value' => '5', 'label' => 'Sets'],
                ['value' => 'Ready', 'label' => 'Lighting setup'],
                ['value' => 'Full day', 'label' => 'Max session'],
                ['value' => 'Gear', 'label' => 'Can pair rentals'],
            ],
            'addons' => [
                [
                    'id' => 'extend',
                    'name' => 'Extend My Session (book extra hours in advance)',
                    'price' => 0,
                    'hint' => 'Charged at your Pictures or Video hourly rate',
                    'status' => 'session_rate',
                ],
                [
                    'id' => 'makeup',
                    'name' => 'Makeup & Beauty Space',
                    'price' => 70,
                    'hint' => 'GHS 70 / model',
                    'status' => 'active',
                ],
                [
                    'id' => 'changing_room',
                    'name' => 'Changing Room',
                    'price' => 0,
                    'hint' => 'Contact us for price',
                    'status' => 'contact',
                ],
                [
                    'id' => 'parking',
                    'name' => 'Parking Space',
                    'price' => 0,
                    'hint' => 'Contact us for price',
                    'status' => 'contact',
                ],
                [
                    'id' => 'setup',
                    'name' => 'Setup Time (before your production starts)',
                    'price' => 1000,
                    'hint' => 'GHS 1,000 flat (up to 5 hrs)',
                    'status' => 'active',
                ],
                [
                    'id' => 'vip_room',
                    'name' => 'VIP Room (for the day of your booking)',
                    'price' => 1000,
                    'hint' => '',
                    'status' => 'active',
                ],
                [
                    'id' => 'colour_full',
                    'name' => 'Full Studio Colour Change',
                    'price' => 800,
                    'hint' => 'Complete colour transformation of the entire cyclorama',
                    'status' => 'active',
                ],
                [
                    'id' => 'colour_section',
                    'name' => 'Section Colour Change (16ft)',
                    'price' => 500,
                    'hint' => 'Uniform colour change for a 16ft section of the studio',
                    'status' => 'active',
                ],
                [
                    'id' => 'colour_canvas',
                    'name' => 'Canvas Colour Design (10ft)',
                    'price' => 1200,
                    'hint' => 'Custom canvas design applied to a 10ft section',
                    'status' => 'active',
                ],
                [
                    'id' => 'lenses',
                    'name' => 'Lenses',
                    'price' => 70,
                    'hint' => 'GHS 70 for the studio session',
                    'status' => 'active',
                ],
                [
                    'id' => 'cameras',
                    'name' => 'Cameras',
                    'price' => 80,
                    'hint' => 'GHS 80 for standard cameras (studio session)',
                    'status' => 'active',
                ],
                [
                    'id' => 'blackmagic_camera',
                    'name' => 'Blackmagic Camera',
                    'price' => 100,
                    'hint' => 'GHS 100 for the studio session',
                    'status' => 'active',
                ],
                [
                    'id' => 'sf6_camera',
                    'name' => 'SF6 Camera',
                    'price' => 100,
                    'hint' => 'GHS 100 for the studio session',
                    'status' => 'active',
                ],
                [
                    'id' => 'lights',
                    'name' => 'Lights & modifiers',
                    'price' => 0,
                    'hint' => 'Contact us for lighting kits and rates',
                    'status' => 'contact',
                ],
                [
                    'id' => 'audio_gear',
                    'name' => 'Audio gear',
                    'price' => 0,
                    'hint' => 'Contact us for mics, recorders, and kits',
                    'status' => 'contact',
                ],
                [
                    'id' => 'other_gear',
                    'name' => 'Other rental gear',
                    'price' => 0,
                    'hint' => 'Stands and more — contact us',
                    'status' => 'contact',
                ],
            ],
            'addons_catalog_version' => 6,
        ];
    }

    public static function get(): array
    {
        $stored = get_option(self::OPTION, []);
        if (! is_array($stored)) {
            $stored = [];
        }

        $out = array_replace_recursive(self::defaults(), $stored);
        if (empty($out['close_time'])) {
            if (! empty($stored['close_hour'])) {
                $out['close_time'] = sprintf('%02d:00', max(0, min(23, (int) $stored['close_hour'])));
            } else {
                $siteClose = (string) get_option('ccr_latest_return_time', '20:50');
                $out['close_time'] = preg_match('/^\d{1,2}:\d{2}$/', $siteClose) ? $siteClose : '20:50';
            }
        }
        if (preg_match('/^(\d{1,2}):(\d{2})$/', (string) $out['close_time'], $m)) {
            $out['close_time'] = sprintf('%02d:%02d', (int) $m[1], (int) $m[2]);
        } else {
            $out['close_time'] = '20:50';
        }
        $out['hero_image_id'] = absint($out['hero_image_id'] ?? 0);
        $out['hero_image_url'] = '';
        if ($out['hero_image_id'] > 0) {
            $url = wp_get_attachment_image_url($out['hero_image_id'], 'full');
            if (is_string($url) && $url !== '') {
                $out['hero_image_url'] = $url;
            }
        }
        // Studio is always full MoMo payment.
        $out['deposit_50'] = 'no';
        $out['deposit_100'] = 'yes';
        if (empty($out['momo_reference'])) {
            $out['momo_reference'] = 'Studio Rentals';
        }
        if (empty($out['momo_pay_network'])) {
            $out['momo_pay_network'] = 'MTN';
        }
        if (! empty($out['addons']) && is_array($out['addons'])) {
            $out['addons'] = array_values(array_filter($out['addons'], static function ($addon): bool {
                $id = is_array($addon) ? (string) ($addon['id'] ?? '') : '';

                return ! in_array($id, ['crane_jib', 'scaffold'], true);
            }));
        }
        if (class_exists('CCR_Settings')) {
            $out['notify_email'] = CCR_Settings::contact_email();
        }

        return $out;
    }

    public static function sanitize(mixed $input): array
    {
        $defaults = self::defaults();
        if (! is_array($input)) {
            return $defaults;
        }

        $out = $defaults;
        foreach (['eyebrow', 'title_line_1', 'title_line_2', 'title_emphasis', 'lead', 'address', 'promo_text', 'promo_badge'] as $key) {
            $out[$key] = sanitize_text_field((string) ($input[$key] ?? $defaults[$key]));
        }
        $out['lead'] = sanitize_textarea_field((string) ($input['lead'] ?? $defaults['lead']));
        $out['whatsapp'] = preg_replace('/\D+/', '', (string) ($input['whatsapp'] ?? $defaults['whatsapp'])) ?: $defaults['whatsapp'];
        $out['notify_email'] = sanitize_email((string) ($input['notify_email'] ?? $defaults['notify_email']));
        $out['hero_image_id'] = absint($input['hero_image_id'] ?? 0);
        $out['open_hour'] = max(0, min(23, (int) ($input['open_hour'] ?? 8)));
        $closeTime = sanitize_text_field((string) ($input['close_time'] ?? $defaults['close_time']));
        if (! preg_match('/^(\d{1,2}):(\d{2})$/', $closeTime, $m)) {
            $closeTime = '20:50';
            $m = [1 => 20, 2 => 50];
        }
        $out['close_time'] = sprintf('%02d:%02d', max(0, min(23, (int) $m[1])), max(0, min(59, (int) $m[2])));
        unset($out['close_hour']);
        $out['deposit_50'] = 'no';
        $out['deposit_100'] = 'yes';
        $out['momo_pay_number'] = preg_replace('/\D+/', '', (string) ($input['momo_pay_number'] ?? $defaults['momo_pay_number'])) ?: $defaults['momo_pay_number'];
        $out['momo_pay_network'] = sanitize_text_field((string) ($input['momo_pay_network'] ?? $defaults['momo_pay_network']));
        $out['momo_reference'] = sanitize_text_field((string) ($input['momo_reference'] ?? $defaults['momo_reference'])) ?: 'Studio Rentals';
        $out['promo_url'] = esc_url_raw((string) ($input['promo_url'] ?? ''));

        $offsets = [];
        if (! empty($input['minute_offsets']) && is_array($input['minute_offsets'])) {
            foreach ($input['minute_offsets'] as $m) {
                $n = (int) $m;
                if (in_array($n, [0, 15, 30, 45], true)) {
                    $offsets[] = $n;
                }
            }
        }
        $out['minute_offsets'] = $offsets !== [] ? array_values(array_unique($offsets)) : [0, 15, 30, 45];

        $stats = [];
        if (! empty($input['stats']) && is_array($input['stats'])) {
            foreach (array_slice($input['stats'], 0, 4) as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $stats[] = [
                    'value' => sanitize_text_field((string) ($row['value'] ?? '')),
                    'label' => sanitize_text_field((string) ($row['label'] ?? '')),
                ];
            }
        }
        $out['stats'] = $stats !== [] ? $stats : $defaults['stats'];

        $addons = [];
        if (! empty($input['addons']) && is_array($input['addons'])) {
            foreach ($input['addons'] as $i => $row) {
                if (! is_array($row)) {
                    continue;
                }
                $name = sanitize_text_field((string) ($row['name'] ?? ''));
                if ($name === '') {
                    continue;
                }
                $status = sanitize_key((string) ($row['status'] ?? 'active'));
                if (! in_array($status, ['active', 'free', 'coming_soon', 'contact', 'session_rate'], true)) {
                    $status = 'active';
                }
                $addons[] = [
                    'id' => sanitize_key((string) ($row['id'] ?? ('addon' . ($i + 1)))),
                    'name' => $name,
                    'price' => max(0, (float) ($row['price'] ?? 0)),
                    'hint' => sanitize_text_field((string) ($row['hint'] ?? '')),
                    'status' => $status,
                ];
            }
        }
        $out['addons'] = $addons;
        $out['addons_catalog_version'] = 6;

        return $out;
    }

    public static function render(): void
    {
        if (! current_user_can('manage_options')) {
            return;
        }
        $s = self::get();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Studio Booking Settings', 'christocentric-rentals'); ?></h1>
            <p><?php esc_html_e('Customize the /studio/ booking page look, copy, hours, add-ons, and deposits. Manage Set 1–5 under Studio sets — guests book a set, not the whole studio.', 'christocentric-rentals'); ?></p>
            <form method="post" action="options.php">
                <?php settings_fields('ccr_studio_settings'); ?>
                <h2><?php esc_html_e('Hero / left panel', 'christocentric-rentals'); ?></h2>
                <table class="form-table" role="presentation">
                    <?php
                    self::field_hero_image((int) ($s['hero_image_id'] ?? 0), (string) ($s['hero_image_url'] ?? ''));
                    self::field_text('eyebrow', __('Eyebrow', 'christocentric-rentals'), $s['eyebrow']);
                    self::field_text('title_line_1', __('Title line 1', 'christocentric-rentals'), $s['title_line_1']);
                    self::field_text('title_line_2', __('Title line 2', 'christocentric-rentals'), $s['title_line_2']);
                    self::field_text('title_emphasis', __('Title emphasis (italic)', 'christocentric-rentals'), $s['title_emphasis']);
                    self::field_textarea('lead', __('Lead text', 'christocentric-rentals'), $s['lead']);
                    self::field_text('address', __('Address', 'christocentric-rentals'), $s['address']);
                    self::field_text('whatsapp', __('WhatsApp number (digits)', 'christocentric-rentals'), $s['whatsapp']);
                    ?>
                    <tr>
                        <th><?php esc_html_e('Admin notify email', 'christocentric-rentals'); ?></th>
                        <td>
                            <code><?php echo esc_html(class_exists('CCR_Settings') ? CCR_Settings::contact_email() : (string) ($s['notify_email'] ?? '')); ?></code>
                            <p class="description"><?php esc_html_e('Uses the SMTP From email (WooCommerce → Christocentric Rentals).', 'christocentric-rentals'); ?></p>
                        </td>
                    </tr>
                </table>

                <h2><?php esc_html_e('Stats (4 cards)', 'christocentric-rentals'); ?></h2>
                <table class="widefat striped">
                    <thead><tr><th><?php esc_html_e('Value', 'christocentric-rentals'); ?></th><th><?php esc_html_e('Label', 'christocentric-rentals'); ?></th></tr></thead>
                    <tbody>
                    <?php for ($i = 0; $i < 4; $i++) :
                        $row = $s['stats'][$i] ?? ['value' => '', 'label' => ''];
                        ?>
                        <tr>
                            <td><input type="text" name="<?php echo esc_attr(self::OPTION); ?>[stats][<?php echo (int) $i; ?>][value]" value="<?php echo esc_attr($row['value']); ?>" class="regular-text"></td>
                            <td><input type="text" name="<?php echo esc_attr(self::OPTION); ?>[stats][<?php echo (int) $i; ?>][label]" value="<?php echo esc_attr($row['label']); ?>" class="regular-text"></td>
                        </tr>
                    <?php endfor; ?>
                    </tbody>
                </table>

                <h2><?php esc_html_e('Hours & MoMo payment', 'christocentric-rentals'); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th><?php esc_html_e('Open hour (0–23)', 'christocentric-rentals'); ?></th>
                        <td><input type="number" min="0" max="23" name="<?php echo esc_attr(self::OPTION); ?>[open_hour]" value="<?php echo esc_attr((string) $s['open_hour']); ?>"></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Closing time', 'christocentric-rentals'); ?></th>
                        <td>
                            <input type="time" name="<?php echo esc_attr(self::OPTION); ?>[close_time]" value="<?php echo esc_attr((string) ($s['close_time'] ?? '20:50')); ?>">
                            <p class="description"><?php esc_html_e('Sessions must finish by this time (default 8:50 PM).', 'christocentric-rentals'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Payment', 'christocentric-rentals'); ?></th>
                        <td>
                            <p><?php esc_html_e('Studio bookings require full payment by Mobile Money (manual transfer).', 'christocentric-rentals'); ?></p>
                            <input type="hidden" name="<?php echo esc_attr(self::OPTION); ?>[deposit_50]" value="no">
                            <input type="hidden" name="<?php echo esc_attr(self::OPTION); ?>[deposit_100]" value="yes">
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('MoMo number (receive)', 'christocentric-rentals'); ?></th>
                        <td><input type="text" class="regular-text" name="<?php echo esc_attr(self::OPTION); ?>[momo_pay_number]" value="<?php echo esc_attr((string) ($s['momo_pay_number'] ?? '')); ?>"></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('MoMo network', 'christocentric-rentals'); ?></th>
                        <td>
                            <select name="<?php echo esc_attr(self::OPTION); ?>[momo_pay_network]">
                                <?php foreach (['MTN', 'Vodafone', 'AirtelTigo'] as $net) : ?>
                                    <option value="<?php echo esc_attr($net); ?>" <?php selected(($s['momo_pay_network'] ?? 'MTN'), $net); ?>><?php echo esc_html($net); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('MoMo reference', 'christocentric-rentals'); ?></th>
                        <td>
                            <input type="text" class="regular-text" name="<?php echo esc_attr(self::OPTION); ?>[momo_reference]" value="<?php echo esc_attr((string) ($s['momo_reference'] ?? 'Studio Rentals')); ?>">
                            <p class="description"><?php esc_html_e('Customers must use this reference when sending MoMo.', 'christocentric-rentals'); ?></p>
                        </td>
                    </tr>
                </table>

                <h2><?php esc_html_e('Promo strip', 'christocentric-rentals'); ?></h2>
                <table class="form-table" role="presentation">
                    <?php
                    self::field_textarea('promo_text', __('Promo text', 'christocentric-rentals'), $s['promo_text']);
                    self::field_text('promo_badge', __('Badge label', 'christocentric-rentals'), $s['promo_badge']);
                    self::field_text('promo_url', __('Promo URL (optional)', 'christocentric-rentals'), $s['promo_url']);
                    ?>
                </table>

                <h2><?php esc_html_e('Add-ons', 'christocentric-rentals'); ?></h2>
                <table class="widefat striped" id="ccr-studio-addons">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Name', 'christocentric-rentals'); ?></th>
                            <th><?php esc_html_e('Price', 'christocentric-rentals'); ?></th>
                            <th><?php esc_html_e('Hint', 'christocentric-rentals'); ?></th>
                            <th><?php esc_html_e('Status', 'christocentric-rentals'); ?></th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($s['addons'] as $i => $addon) : ?>
                        <tr>
                            <td>
                                <input type="hidden" name="<?php echo esc_attr(self::OPTION); ?>[addons][<?php echo (int) $i; ?>][id]" value="<?php echo esc_attr($addon['id']); ?>">
                                <input type="text" name="<?php echo esc_attr(self::OPTION); ?>[addons][<?php echo (int) $i; ?>][name]" value="<?php echo esc_attr($addon['name']); ?>" class="regular-text">
                            </td>
                            <td><input type="number" min="0" step="0.01" name="<?php echo esc_attr(self::OPTION); ?>[addons][<?php echo (int) $i; ?>][price]" value="<?php echo esc_attr((string) $addon['price']); ?>" style="width:6rem"></td>
                            <td><input type="text" name="<?php echo esc_attr(self::OPTION); ?>[addons][<?php echo (int) $i; ?>][hint]" value="<?php echo esc_attr($addon['hint']); ?>"></td>
                            <td>
                                <select name="<?php echo esc_attr(self::OPTION); ?>[addons][<?php echo (int) $i; ?>][status]">
                                    <option value="active" <?php selected($addon['status'], 'active'); ?>><?php esc_html_e('Active', 'christocentric-rentals'); ?></option>
                                    <option value="session_rate" <?php selected($addon['status'], 'session_rate'); ?>><?php esc_html_e('Session hourly rate', 'christocentric-rentals'); ?></option>
                                    <option value="free" <?php selected($addon['status'], 'free'); ?>><?php esc_html_e('Free', 'christocentric-rentals'); ?></option>
                                    <option value="contact" <?php selected($addon['status'], 'contact'); ?>><?php esc_html_e('Contact for price', 'christocentric-rentals'); ?></option>
                                    <option value="coming_soon" <?php selected($addon['status'], 'coming_soon'); ?>><?php esc_html_e('Coming soon', 'christocentric-rentals'); ?></option>
                                </select>
                            </td>
                            <td><button type="button" class="button ccr-remove-addon"><?php esc_html_e('Remove', 'christocentric-rentals'); ?></button></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <p><button type="button" class="button" id="ccr-add-addon"><?php esc_html_e('Add add-on', 'christocentric-rentals'); ?></button></p>
                <?php submit_button(); ?>
            </form>
        </div>
        <script>
        (function(){
            var table = document.getElementById('ccr-studio-addons');
            var addBtn = document.getElementById('ccr-add-addon');
            var opt = <?php echo wp_json_encode(self::OPTION); ?>;
            if (table && addBtn) {
                addBtn.addEventListener('click', function(){
                    var i = table.tBodies[0].rows.length;
                    var tr = document.createElement('tr');
                    tr.innerHTML = '<td><input type="hidden" name="'+opt+'[addons]['+i+'][id]" value="addon'+i+'"><input type="text" name="'+opt+'[addons]['+i+'][name]" class="regular-text"></td>'+
                        '<td><input type="number" min="0" step="0.01" name="'+opt+'[addons]['+i+'][price]" value="0" style="width:6rem"></td>'+
                        '<td><input type="text" name="'+opt+'[addons]['+i+'][hint]"></td>'+
                        '<td><select name="'+opt+'[addons]['+i+'][status]"><option value="active">Active</option><option value="session_rate">Session hourly rate</option><option value="free">Free</option><option value="contact">Contact for price</option><option value="coming_soon">Coming soon</option></select></td>'+
                        '<td><button type="button" class="button ccr-remove-addon">Remove</button></td>';
                    table.tBodies[0].appendChild(tr);
                });
                table.addEventListener('click', function(e){
                    if (e.target && e.target.classList.contains('ccr-remove-addon')) {
                        var row = e.target.closest('tr');
                        if (row) row.remove();
                    }
                });
            }

            var pick = document.getElementById('ccr-studio-hero-pick');
            var clear = document.getElementById('ccr-studio-hero-clear');
            var input = document.getElementById('ccr-studio-hero-image-id');
            var preview = document.getElementById('ccr-studio-hero-preview');
            if (pick && input && preview && typeof wp !== 'undefined' && wp.media) {
                var frame;
                pick.addEventListener('click', function(e){
                    e.preventDefault();
                    if (frame) { frame.open(); return; }
                    frame = wp.media({
                        title: 'Select hero image',
                        button: { text: 'Use image' },
                        multiple: false
                    });
                    frame.on('select', function(){
                        var att = frame.state().get('selection').first().toJSON();
                        input.value = att.id || '';
                        preview.innerHTML = att.url ? '<img src="'+att.url+'" alt="" style="max-width:280px;height:auto;border-radius:6px;">' : '';
                    });
                    frame.open();
                });
                if (clear) {
                    clear.addEventListener('click', function(e){
                        e.preventDefault();
                        input.value = '0';
                        preview.innerHTML = '';
                    });
                }
            }
        })();
        </script>
        <?php
    }

    private static function field_hero_image(int $id, string $url): void
    {
        $name = self::OPTION . '[hero_image_id]';
        echo '<tr><th><label for="ccr-studio-hero-image-id">' . esc_html__('Hero background image', 'christocentric-rentals') . '</label></th><td>';
        echo '<input type="hidden" id="ccr-studio-hero-image-id" name="' . esc_attr($name) . '" value="' . esc_attr((string) $id) . '">';
        echo '<div id="ccr-studio-hero-preview" style="margin-bottom:8px;">';
        if ($url !== '') {
            echo '<img src="' . esc_url($url) . '" alt="" style="max-width:280px;height:auto;border-radius:6px;">';
        }
        echo '</div>';
        echo '<button type="button" class="button" id="ccr-studio-hero-pick">' . esc_html__('Select image', 'christocentric-rentals') . '</button> ';
        echo '<button type="button" class="button" id="ccr-studio-hero-clear">' . esc_html__('Remove', 'christocentric-rentals') . '</button>';
        echo '<p class="description">' . esc_html__('Shown behind the left panel text on /studio/. Set cards still use each set’s Featured image.', 'christocentric-rentals') . '</p>';
        echo '</td></tr>';
    }

    private static function field_text(string $key, string $label, string $value): void
    {
        $name = self::OPTION . '[' . $key . ']';
        echo '<tr><th><label for="' . esc_attr($name) . '">' . esc_html($label) . '</label></th><td>';
        echo '<input type="text" class="regular-text" id="' . esc_attr($name) . '" name="' . esc_attr($name) . '" value="' . esc_attr($value) . '">';
        echo '</td></tr>';
    }

    private static function field_textarea(string $key, string $label, string $value): void
    {
        $name = self::OPTION . '[' . $key . ']';
        echo '<tr><th><label for="' . esc_attr($name) . '">' . esc_html($label) . '</label></th><td>';
        echo '<textarea class="large-text" rows="3" id="' . esc_attr($name) . '" name="' . esc_attr($name) . '">' . esc_textarea($value) . '</textarea>';
        echo '</td></tr>';
    }
}
