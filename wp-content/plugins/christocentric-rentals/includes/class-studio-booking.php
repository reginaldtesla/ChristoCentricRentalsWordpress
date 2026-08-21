<?php

defined('ABSPATH') || exit;

/**
 * Studio booking AJAX, availability, MoMo full payment, emails.
 */
final class CCR_Studio_Booking
{
    public const AJAX_CREATE = 'ccr_studio_create_booking';
    public const AJAX_SLOTS = 'ccr_studio_taken_slots';
    public const META_FLAG = '_ccr_is_studio_booking';
    /** Minutes an unpaid MoMo booking holds the slot before it is released. */
    public const AWAITING_HOLD_MINUTES = 10;

    public static function init(): void
    {
        add_action('wp_ajax_' . self::AJAX_CREATE, [self::class, 'ajax_create']);
        add_action('wp_ajax_nopriv_' . self::AJAX_CREATE, [self::class, 'ajax_create']);
        add_action('wp_ajax_' . self::AJAX_SLOTS, [self::class, 'ajax_taken_slots']);
        add_action('wp_ajax_nopriv_' . self::AJAX_SLOTS, [self::class, 'ajax_taken_slots']);
        add_action('woocommerce_payment_complete', [self::class, 'on_payment_complete'], 30);
        add_action('woocommerce_order_status_processing', [self::class, 'maybe_email_on_status'], 30);
        add_action('woocommerce_order_status_completed', [self::class, 'maybe_email_on_status'], 30);
        add_filter('woocommerce_get_return_url', [self::class, 'return_url'], 20, 2);
    }

    /**
     * After payment is marked paid in admin, land on the studio success view.
     *
     * @param string        $url   Default thank-you URL.
     * @param WC_Order|null $order Order being paid.
     */
    public static function return_url(string $url, $order = null): string
    {
        if (! $order instanceof WC_Order) {
            return $url;
        }
        if ((string) $order->get_meta(self::META_FLAG) !== '1') {
            return $url;
        }

        return add_query_arg(
            [
                'booking' => 'success',
                'order' => $order->get_id(),
            ],
            class_exists('CCR_Studio_Subdomain') ? CCR_Studio_Subdomain::url('/') : home_url('/studio/')
        );
    }

    /** Payload for front-end bootstrap. */
    public static function frontend_payload(): array
    {
        $settings = CCR_Studio_Settings::get();
        $studios = CCR_Studio_Cpt::get_published_studios();
        $promoUrl = (string) ($settings['promo_url'] ?? '');
        if ($promoUrl === '' && function_exists('ccr_shop_url')) {
            $promoUrl = ccr_shop_url();
        }

        return [
            'settings' => [
                'eyebrow' => $settings['eyebrow'],
                'title_line_1' => $settings['title_line_1'],
                'title_line_2' => $settings['title_line_2'],
                'title_emphasis' => $settings['title_emphasis'],
                'lead' => $settings['lead'],
                'address' => $settings['address'],
                'whatsapp' => $settings['whatsapp'],
                'hero_image' => (string) ($settings['hero_image_url'] ?? ''),
                'stats' => $settings['stats'],
                'open_hour' => (int) $settings['open_hour'],
                'close_time' => (string) ($settings['close_time'] ?? '20:50'),
                'minute_offsets' => $settings['minute_offsets'],
                'deposit_50' => false,
                'deposit_100' => true,
                'momo_pay_number' => (string) ($settings['momo_pay_number'] ?? ''),
                'momo_pay_network' => (string) ($settings['momo_pay_network'] ?? 'MTN'),
                'momo_reference' => (string) ($settings['momo_reference'] ?? 'Studio Rentals'),
                'promo_text' => $settings['promo_text'],
                'promo_badge' => $settings['promo_badge'],
                'promo_url' => $promoUrl,
                'addons' => $settings['addons'],
            ],
            'studios' => $studios,
            'ajaxUrl' => class_exists('CCR_Studio_Subdomain') ? CCR_Studio_Subdomain::ajax_url() : admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('ccr_studio_book'),
            'currency' => 'GHS',
            'i18n' => [
                'continue' => __('Continue', 'christocentric-rentals'),
                'back' => __('Back', 'christocentric-rentals'),
                'pay' => __('Confirm booking', 'christocentric-rentals'),
                'whatsapp' => __('Message us on WhatsApp', 'christocentric-rentals'),
                'error' => __('Something went wrong. Please try again.', 'christocentric-rentals'),
            ],
        ];
    }

    public static function ajax_taken_slots(): void
    {
        check_ajax_referer('ccr_studio_book', 'nonce');
        $studioId = absint($_POST['studio_id'] ?? 0); // phpcs:ignore
        $month = sanitize_text_field(wp_unslash($_POST['month'] ?? '')); // phpcs:ignore
        $date = sanitize_text_field(wp_unslash($_POST['date'] ?? '')); // phpcs:ignore

        if ($studioId <= 0) {
            wp_send_json_success(['slots' => [], 'by_date' => []]);
        }

        if (preg_match('/^\d{4}-\d{2}$/', $month)) {
            wp_send_json_success(['by_date' => self::taken_slots_for_month($studioId, $month)]);
        }

        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            wp_send_json_success(['slots' => []]);
        }
        wp_send_json_success(['slots' => self::taken_slots_for($studioId, $date)]);
    }

    /**
     * @return array<string, list<array{start:string,end:string}>>
     */
    public static function taken_slots_for_month(int $studioId, string $yearMonth): array
    {
        if (! function_exists('wc_get_orders')) {
            return [];
        }

        self::release_expired_awaiting_holds();

        $orders = wc_get_orders([
            'limit' => 120,
            'status' => ['on-hold', 'pending', 'processing', 'completed'],
            'return' => 'objects',
            'meta_query' => [
                'relation' => 'AND',
                [
                    'key' => self::META_FLAG,
                    'value' => '1',
                ],
                [
                    'key' => '_ccr_studio_id',
                    'value' => (string) $studioId,
                ],
                [
                    'key' => '_ccr_studio_date',
                    'value' => $yearMonth,
                    'compare' => 'LIKE',
                ],
            ],
        ]);

        $byDate = [];
        foreach ($orders as $order) {
            if (! $order instanceof WC_Order) {
                continue;
            }
            if (! self::order_blocks_slot($order)) {
                continue;
            }
            $orderDate = (string) $order->get_meta('_ccr_studio_date');
            if ($orderDate === '' || ! str_starts_with($orderDate, $yearMonth)) {
                continue;
            }
            $start = (string) $order->get_meta('_ccr_studio_start');
            $end = (string) $order->get_meta('_ccr_studio_end');
            if ($start === '' || $end === '') {
                continue;
            }
            if (! isset($byDate[$orderDate])) {
                $byDate[$orderDate] = [];
            }
            $byDate[$orderDate][] = ['start' => $start, 'end' => $end];
        }

        return $byDate;
    }

    /**
     * Paid/confirmed bookings block until the session end is past.
     * Awaiting MoMo bookings only block for AWAITING_HOLD_MINUTES.
     */
    public static function order_blocks_slot(WC_Order $order): bool
    {
        if ((string) $order->get_meta(self::META_FLAG) !== '1') {
            return false;
        }

        $status = $order->get_status(); // without wc- prefix
        $date = (string) $order->get_meta('_ccr_studio_date');
        $end = (string) $order->get_meta('_ccr_studio_end');
        if ($date === '' || $end === '' || ! self::session_still_upcoming($date, $end)) {
            return false;
        }

        if (in_array($status, ['processing', 'completed'], true)) {
            return true;
        }

        if (in_array($status, ['on-hold', 'pending'], true)) {
            return self::awaiting_hold_active($order);
        }

        return false;
    }

    public static function session_still_upcoming(string $date, string $endTime): bool
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || ! preg_match('/^\d{2}:\d{2}$/', $endTime)) {
            return false;
        }
        try {
            $tz = wp_timezone();
            $end = new DateTimeImmutable($date . ' ' . $endTime . ':00', $tz);

            return $end->getTimestamp() > time();
        } catch (Exception $e) {
            return false;
        }
    }

    public static function awaiting_hold_active(WC_Order $order): bool
    {
        $holdUntil = (int) $order->get_meta('_ccr_studio_hold_until');
        if ($holdUntil > 0) {
            return $holdUntil > time();
        }
        $created = $order->get_date_created();
        if (! $created) {
            return false;
        }

        return ($created->getTimestamp() + (self::AWAITING_HOLD_MINUTES * 60)) > time();
    }

    /**
     * After the 10-minute window, free the calendar slot but keep the order on-hold
     * so admin can still confirm MoMo later and reactivate the booking.
     */
    public static function release_expired_awaiting_holds(): void
    {
        if (! function_exists('wc_get_orders')) {
            return;
        }

        $orders = wc_get_orders([
            'limit' => 40,
            'status' => ['on-hold', 'pending'],
            'return' => 'objects',
            'meta_key' => self::META_FLAG,
            'meta_value' => '1',
        ]);

        foreach ($orders as $order) {
            if (! $order instanceof WC_Order) {
                continue;
            }
            if ((string) $order->get_meta(self::META_FLAG) !== '1') {
                continue;
            }
            if (self::awaiting_hold_active($order)) {
                continue;
            }
            if ((string) $order->get_meta('_ccr_studio_hold_released') === '1') {
                continue;
            }
            $order->update_meta_data('_ccr_studio_hold_released', '1');
            $order->add_order_note(sprintf(
                /* translators: %d: hold minutes */
                __('Studio MoMo soft hold expired after %d minutes — slot released for others. Confirm payment later to make this booking active again.', 'christocentric-rentals'),
                self::AWAITING_HOLD_MINUTES
            ));
            $order->save();
        }
    }

    /**
     * @return list<array{start:string,end:string}>
     */
    public static function taken_slots_for(int $studioId, string $date): array
    {
        $month = substr($date, 0, 7);
        $byDate = self::taken_slots_for_month($studioId, $month);

        return $byDate[$date] ?? [];
    }

    public static function ajax_create(): void
    {
        check_ajax_referer('ccr_studio_book', 'nonce');

        if (! class_exists('WooCommerce') || ! function_exists('wc_create_order')) {
            wp_send_json_error(['message' => __('Checkout is unavailable.', 'christocentric-rentals')], 500);
        }

        $studioId = absint($_POST['studio_id'] ?? 0); // phpcs:ignore
        $packageId = sanitize_text_field(wp_unslash($_POST['package_id'] ?? '')); // phpcs:ignore
        $sessionHours = max(1, min(12, absint($_POST['session_hours'] ?? 4))); // phpcs:ignore
        $extendHours = max(0, min(8, absint($_POST['extend_hours'] ?? 0))); // phpcs:ignore
        $date = sanitize_text_field(wp_unslash($_POST['date'] ?? '')); // phpcs:ignore
        $start = sanitize_text_field(wp_unslash($_POST['start'] ?? '')); // phpcs:ignore
        $end = sanitize_text_field(wp_unslash($_POST['end'] ?? '')); // phpcs:ignore
        // Studio bookings are full payment only (manual MoMo).
        $depositPct = 100;
        $name = sanitize_text_field(wp_unslash($_POST['name'] ?? '')); // phpcs:ignore
        $phone = sanitize_text_field(wp_unslash($_POST['phone'] ?? '')); // phpcs:ignore
        $email = sanitize_email(wp_unslash($_POST['email'] ?? '')); // phpcs:ignore
        $notes = sanitize_textarea_field(wp_unslash($_POST['notes'] ?? '')); // phpcs:ignore
        $addonIds = isset($_POST['addons']) && is_array($_POST['addons'])
            ? array_map('sanitize_key', wp_unslash($_POST['addons'])) // phpcs:ignore
            : [];

        $settings = CCR_Studio_Settings::get();
        $momoPayNumber = preg_replace('/\D+/', '', (string) ($settings['momo_pay_number'] ?? '')) ?: '';
        $momoPayNetwork = sanitize_text_field((string) ($settings['momo_pay_network'] ?? 'MTN'));
        $momoReference = sanitize_text_field((string) ($settings['momo_reference'] ?? 'Studio Rentals')) ?: 'Studio Rentals';

        $studio = get_post($studioId);
        if (! $studio instanceof WP_Post || $studio->post_type !== CCR_Studio_Cpt::POST_TYPE || $studio->post_status !== 'publish') {
            wp_send_json_error(['message' => __('Please select a set.', 'christocentric-rentals')], 400);
        }

        $package = null;
        foreach (CCR_Studio_Cpt::get_packages($studioId) as $pkg) {
            if ($pkg['id'] === $packageId) {
                $package = $pkg;
                break;
            }
        }
        if ($package === null) {
            wp_send_json_error(['message' => __('Please select a package.', 'christocentric-rentals')], 400);
        }

        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || ! preg_match('/^\d{2}:\d{2}$/', $start) || ! preg_match('/^\d{2}:\d{2}$/', $end)) {
            wp_send_json_error(['message' => __('Please choose a valid date and time.', 'christocentric-rentals')], 400);
        }
        if ($name === '' || $phone === '') {
            wp_send_json_error(['message' => __('Name and phone are required.', 'christocentric-rentals')], 400);
        }

        $addonCatalog = [];
        foreach ($settings['addons'] as $addon) {
            $addonCatalog[$addon['id']] = $addon;
        }
        $selectedAddons = [];
        $addonsTotal = 0.0;
        $hourly = (($package['pricing'] ?? 'hourly') !== 'flat');
        $rate = (float) $package['price'];
        $packagePrice = $hourly ? round($rate * $sessionHours, 2) : $rate;
        $packageLabel = $package['label'] . ($hourly ? (' · ' . $sessionHours . ' hr') : '');

        foreach ($addonIds as $aid) {
            if (! isset($addonCatalog[$aid])) {
                continue;
            }
            $addon = $addonCatalog[$aid];
            if (($addon['status'] ?? '') === 'coming_soon' || ($addon['status'] ?? '') === 'contact') {
                continue;
            }
            if (($addon['status'] ?? '') === 'session_rate' || $aid === 'extend') {
                if ($extendHours <= 0) {
                    continue;
                }
                $price = round($rate * $extendHours, 2);
                $selectedAddons[] = [
                    'id' => $addon['id'],
                    'name' => $addon['name'] . ' (+' . $extendHours . ' hr)',
                    'price' => $price,
                ];
                $addonsTotal += $price;
                continue;
            }
            $price = ($addon['status'] ?? '') === 'free' ? 0.0 : (float) $addon['price'];
            $selectedAddons[] = [
                'id' => $addon['id'],
                'name' => $addon['name'],
                'price' => $price,
            ];
            $addonsTotal += $price;
        }

        $hasExtend = false;
        foreach ($selectedAddons as $row) {
            if (($row['id'] ?? '') === 'extend') {
                $hasExtend = true;
                break;
            }
        }
        $totalHours = $sessionHours + ($hasExtend ? $extendHours : 0);

        $closeTime = (string) ($settings['close_time'] ?? '20:50');
        $endMins = self::minutes($start) + ($totalHours * 60);
        if ($endMins <= self::minutes($start)) {
            wp_send_json_error(['message' => __('Sessions cannot run overnight. Pick an earlier start.', 'christocentric-rentals')], 400);
        }
        if ($endMins > self::minutes($closeTime)) {
            wp_send_json_error([
                'message' => sprintf(
                    /* translators: %s: closing time HH:MM */
                    __('Sessions must finish by %s. Pick an earlier start or fewer hours.', 'christocentric-rentals'),
                    $closeTime
                ),
            ], 400);
        }
        $end = sprintf('%02d:%02d', intdiv($endMins, 60) % 24, $endMins % 60);

        // Overlap check
        foreach (self::taken_slots_for($studioId, $date) as $slot) {
            if (self::times_overlap($start, $end, $slot['start'], $slot['end'])) {
                wp_send_json_error(['message' => __('That time overlaps an existing booking. Pick another slot.', 'christocentric-rentals')], 409);
            }
        }

        $fullTotal = $packagePrice + $addonsTotal;
        $depositDue = round($fullTotal, 2);
        if ($depositDue <= 0) {
            wp_send_json_error(['message' => __('Invalid booking amount.', 'christocentric-rentals')], 400);
        }
        if ($momoPayNumber === '') {
            wp_send_json_error(['message' => __('MoMo payment is not configured. Please contact us on WhatsApp.', 'christocentric-rentals')], 500);
        }

        $order = wc_create_order();
        if (is_wp_error($order)) {
            wp_send_json_error(['message' => __('Could not create booking order.', 'christocentric-rentals')], 500);
        }

        $fee = new WC_Order_Item_Fee();
        $fee->set_name(sprintf(
            /* translators: 1: set name 2: package label */
            __('Studio set — %1$s (%2$s)', 'christocentric-rentals'),
            $studio->post_title,
            $packageLabel
        ));
        $fee->set_amount($depositDue);
        $fee->set_total($depositDue);
        $order->add_item($fee);

        $order->set_billing_first_name($name);
        $order->set_billing_phone($phone);
        if ($email !== '' && is_email($email)) {
            $order->set_billing_email($email);
        } else {
            $order->set_billing_email(sanitize_email('studio+' . $order->get_id() . '@christocentricrentals.com'));
        }

        $order->update_meta_data(self::META_FLAG, '1');
        $order->update_meta_data('_ccr_studio_id', $studioId);
        $order->update_meta_data('_ccr_studio_name', $studio->post_title);
        $order->update_meta_data('_ccr_studio_package_id', $package['id']);
        $order->update_meta_data('_ccr_studio_package_label', $packageLabel);
        $order->update_meta_data('_ccr_studio_package_hours', $totalHours);
        $order->update_meta_data('_ccr_studio_session_hours', $sessionHours);
        $order->update_meta_data('_ccr_studio_extend_hours', $hasExtend ? $extendHours : 0);
        $order->update_meta_data('_ccr_studio_hourly_rate', $rate);
        $order->update_meta_data('_ccr_studio_package_price', $packagePrice);
        $order->update_meta_data('_ccr_studio_date', $date);
        $order->update_meta_data('_ccr_studio_start', $start);
        $order->update_meta_data('_ccr_studio_end', $end);
        $order->update_meta_data('_ccr_studio_addons', $selectedAddons);
        $order->update_meta_data('_ccr_studio_addons_total', $addonsTotal);
        $order->update_meta_data('_ccr_studio_full_total', $fullTotal);
        $order->update_meta_data('_ccr_studio_deposit_pct', $depositPct);
        $order->update_meta_data('_ccr_studio_deposit_due', $depositDue);
        $order->update_meta_data('_ccr_studio_balance_due', 0);
        $order->update_meta_data('_ccr_studio_customer_name', $name);
        $order->update_meta_data('_ccr_studio_customer_phone', $phone);
        $order->update_meta_data('_ccr_studio_momo_pay_number', $momoPayNumber);
        $order->update_meta_data('_ccr_studio_momo_pay_network', $momoPayNetwork);
        $order->update_meta_data('_ccr_studio_momo_reference', $momoReference);
        $order->update_meta_data('_ccr_studio_notes', $notes);
        $order->update_meta_data('_ccr_studio_email_sent', '0');
        $order->update_meta_data('_ccr_studio_awaiting_email_sent', '0');
        $holdUntil = time() + (self::AWAITING_HOLD_MINUTES * 60);
        $order->update_meta_data('_ccr_studio_hold_until', $holdUntil);

        $order->set_payment_method('bacs');
        $order->set_payment_method_title(sprintf(
            /* translators: %s: MoMo network */
            __('Mobile Money (%s)', 'christocentric-rentals'),
            $momoPayNetwork
        ));

        $order->calculate_totals(false);
        $order->set_status(
            'on-hold',
            sprintf(
                /* translators: %d: hold minutes */
                __('Awaiting MoMo transfer — slot held for %d minutes.', 'christocentric-rentals'),
                self::AWAITING_HOLD_MINUTES
            )
        );
        $order->save();

        self::send_awaiting_payment_emails($order->get_id());

        $confirmUrl = add_query_arg(
            [
                'booking' => 'pending',
                'order' => $order->get_id(),
            ],
            class_exists('CCR_Studio_Subdomain') ? CCR_Studio_Subdomain::url('/') : home_url('/studio/')
        );

        wp_send_json_success([
            'order_id' => $order->get_id(),
            'confirm_url' => $confirmUrl,
            'deposit' => $depositDue,
            'full_total' => $fullTotal,
            'momo' => [
                'number' => $momoPayNumber,
                'network' => $momoPayNetwork,
                'reference' => $momoReference,
            ],
        ]);
    }

    private static function times_overlap(string $aStart, string $aEnd, string $bStart, string $bEnd): bool
    {
        $as = self::minutes($aStart);
        $ae = self::minutes($aEnd);
        $bs = self::minutes($bStart);
        $be = self::minutes($bEnd);
        if ($ae <= $as) {
            $ae += 24 * 60;
        }
        if ($be <= $bs) {
            $be += 24 * 60;
        }

        return $as < $be && $bs < $ae;
    }

    private static function minutes(string $hhmm): int
    {
        $parts = explode(':', $hhmm);

        return ((int) ($parts[0] ?? 0) * 60) + (int) ($parts[1] ?? 0);
    }

    public static function on_payment_complete(int $orderId): void
    {
        self::send_booking_emails($orderId);
    }

    public static function maybe_email_on_status(int $orderId): void
    {
        $order = wc_get_order($orderId);
        if (! $order instanceof WC_Order) {
            return;
        }
        if ((string) $order->get_meta(self::META_FLAG) !== '1') {
            return;
        }
        // Admin confirms MoMo — booking becomes active and blocks the slot again until session end.
        if ((string) $order->get_meta('_ccr_studio_reactivated_note') !== '1') {
            $order->update_meta_data('_ccr_studio_reactivated_note', '1');
            $order->add_order_note(__('Studio payment confirmed — booking is active and the time slot is reserved again.', 'christocentric-rentals'));
            $order->save();
        }
        self::send_booking_emails($orderId);
    }

    public static function send_awaiting_payment_emails(int $orderId): void
    {
        $order = wc_get_order($orderId);
        if (! $order instanceof WC_Order) {
            return;
        }
        if ((string) $order->get_meta(self::META_FLAG) !== '1') {
            return;
        }
        if ((string) $order->get_meta('_ccr_studio_awaiting_email_sent') === '1') {
            return;
        }

        $settings = CCR_Studio_Settings::get();
        $momoHtml = self::momo_instructions_html($order);
        $summaryHtml = self::summary_html($order);
        $title = sprintf(
            /* translators: %s: order number */
            __('Studio booking — send MoMo (#%s)', 'christocentric-rentals'),
            $order->get_order_number()
        );

        $customerEmail = $order->get_billing_email();
        if ($customerEmail && is_email($customerEmail) && ! str_contains($customerEmail, 'studio+')) {
            $intro = '<p>' . esc_html__('Your studio slot is held. Please send the full amount by Mobile Money using the details below, then WhatsApp us your payment screenshot.', 'christocentric-rentals') . '</p>';
            $body = class_exists('CCR_Email')
                ? CCR_Email::wrap_html($title, $intro . $momoHtml . $summaryHtml)
                : $intro . $momoHtml . $summaryHtml;
            wp_mail($customerEmail, $title, $body, ['Content-Type: text/html; charset=UTF-8']);
        }

        $adminTo = sanitize_email((string) ($settings['notify_email'] ?? ''));
        if ($adminTo === '' && class_exists('CCR_Settings')) {
            $adminTo = CCR_Settings::contact_email();
        }
        if ($adminTo === '') {
            $adminTo = get_option('admin_email');
        }
        $adminBody = class_exists('CCR_Email')
            ? CCR_Email::wrap_html($title, '<p>' . esc_html__('New studio booking awaiting MoMo payment.', 'christocentric-rentals') . '</p>' . $summaryHtml)
            : $summaryHtml;
        wp_mail($adminTo, '[Admin] ' . $title, $adminBody, ['Content-Type: text/html; charset=UTF-8']);

        $order->update_meta_data('_ccr_studio_awaiting_email_sent', '1');
        $order->save();
    }

    public static function send_booking_emails(int $orderId): void
    {
        $order = wc_get_order($orderId);
        if (! $order instanceof WC_Order) {
            return;
        }
        if ((string) $order->get_meta(self::META_FLAG) !== '1') {
            return;
        }
        if ((string) $order->get_meta('_ccr_studio_email_sent') === '1') {
            return;
        }

        $settings = CCR_Studio_Settings::get();
        $summaryHtml = self::summary_html($order);
        $title = sprintf(
            /* translators: %s: order number */
            __('Studio booking confirmed (#%s)', 'christocentric-rentals'),
            $order->get_order_number()
        );

        $customerEmail = $order->get_billing_email();
        if ($customerEmail && is_email($customerEmail) && ! str_contains($customerEmail, 'studio+')) {
            $body = class_exists('CCR_Email')
                ? CCR_Email::wrap_html($title, '<p>' . esc_html__('Thanks — your studio booking payment is confirmed.', 'christocentric-rentals') . '</p>' . $summaryHtml)
                : $summaryHtml;
            wp_mail($customerEmail, $title, $body, ['Content-Type: text/html; charset=UTF-8']);
        }

        $adminTo = sanitize_email((string) ($settings['notify_email'] ?? ''));
        if ($adminTo === '' && class_exists('CCR_Settings')) {
            $adminTo = CCR_Settings::contact_email();
        }
        if ($adminTo === '') {
            $adminTo = get_option('admin_email');
        }
        $adminBody = class_exists('CCR_Email')
            ? CCR_Email::wrap_html($title, '<p>' . esc_html__('Studio booking payment confirmed.', 'christocentric-rentals') . '</p>' . $summaryHtml)
            : $summaryHtml;
        wp_mail($adminTo, '[Admin] ' . $title, $adminBody, ['Content-Type: text/html; charset=UTF-8']);

        $order->update_meta_data('_ccr_studio_email_sent', '1');
        $order->save();
    }

    public static function momo_instructions_html(WC_Order $order): string
    {
        $settings = CCR_Studio_Settings::get();
        $number = (string) $order->get_meta('_ccr_studio_momo_pay_number');
        if ($number === '') {
            $number = (string) ($settings['momo_pay_number'] ?? '');
        }
        $network = (string) $order->get_meta('_ccr_studio_momo_pay_network');
        if ($network === '') {
            $network = (string) ($settings['momo_pay_network'] ?? 'MTN');
        }
        $reference = (string) $order->get_meta('_ccr_studio_momo_reference');
        if ($reference === '') {
            $reference = (string) ($settings['momo_reference'] ?? 'Studio Rentals');
        }
        $amount = (float) $order->get_meta('_ccr_studio_deposit_due');
        if ($amount <= 0) {
            $amount = (float) $order->get_meta('_ccr_studio_full_total');
        }

        $rows = [
            __('Amount', 'christocentric-rentals') => 'GHS ' . number_format($amount, 2),
            __('Network', 'christocentric-rentals') => $network,
            __('MoMo number', 'christocentric-rentals') => $number,
            __('Reference', 'christocentric-rentals') => $reference,
        ];
        $html = '<table style="width:100%;border-collapse:collapse;font-size:14px;margin:12px 0;">';
        foreach ($rows as $label => $value) {
            $html .= '<tr><td style="padding:6px 0;color:#6b7280;width:40%;">' . esc_html($label) . '</td><td style="padding:6px 0;font-weight:600;">' . esc_html($value) . '</td></tr>';
        }
        $html .= '</table>';
        $html .= '<p style="font-size:13px;color:#6b7280;">' . esc_html__('Use exactly this reference so we can match your payment.', 'christocentric-rentals') . '</p>';

        return $html;
    }

    public static function summary_html(WC_Order $order): string
    {
        $rows = [
            __('Set', 'christocentric-rentals') => (string) $order->get_meta('_ccr_studio_name'),
            __('Package', 'christocentric-rentals') => (string) $order->get_meta('_ccr_studio_package_label'),
            __('Date', 'christocentric-rentals') => (string) $order->get_meta('_ccr_studio_date'),
            __('Time', 'christocentric-rentals') => (string) $order->get_meta('_ccr_studio_start') . ' → ' . (string) $order->get_meta('_ccr_studio_end'),
            __('Customer', 'christocentric-rentals') => (string) $order->get_meta('_ccr_studio_customer_name'),
            __('Phone', 'christocentric-rentals') => (string) $order->get_meta('_ccr_studio_customer_phone'),
            __('Total due', 'christocentric-rentals') => 'GHS ' . number_format((float) $order->get_meta('_ccr_studio_full_total'), 2),
            __('Payment', 'christocentric-rentals') => __('Full payment via MoMo', 'christocentric-rentals'),
        ];
        $html = '<table style="width:100%;border-collapse:collapse;font-size:14px;">';
        foreach ($rows as $label => $value) {
            $html .= '<tr><td style="padding:6px 0;color:#6b7280;width:40%;">' . esc_html($label) . '</td><td style="padding:6px 0;font-weight:600;">' . esc_html($value) . '</td></tr>';
        }
        $html .= '</table>';

        return $html;
    }

    public static function whatsapp_message_for_order(WC_Order $order, string $context = 'paid'): string
    {
        $settings = CCR_Studio_Settings::get();
        $amount = (float) $order->get_meta('_ccr_studio_deposit_due');
        if ($amount <= 0) {
            $amount = (float) $order->get_meta('_ccr_studio_full_total');
        }
        $reference = (string) $order->get_meta('_ccr_studio_momo_reference');
        if ($reference === '') {
            $reference = (string) ($settings['momo_reference'] ?? 'Studio Rentals');
        }
        $number = (string) $order->get_meta('_ccr_studio_momo_pay_number');
        if ($number === '') {
            $number = (string) ($settings['momo_pay_number'] ?? '');
        }
        $network = (string) $order->get_meta('_ccr_studio_momo_pay_network');
        if ($network === '') {
            $network = (string) ($settings['momo_pay_network'] ?? 'MTN');
        }

        $lines = [
            'Studio booking — Christocentric Rentals',
            'Order #' . $order->get_order_number(),
            '',
            'Set: ' . (string) $order->get_meta('_ccr_studio_name'),
            'Package: ' . (string) $order->get_meta('_ccr_studio_package_label'),
            'Date: ' . (string) $order->get_meta('_ccr_studio_date'),
            'Time: ' . (string) $order->get_meta('_ccr_studio_start') . ' → ' . (string) $order->get_meta('_ccr_studio_end'),
            'Name: ' . (string) $order->get_meta('_ccr_studio_customer_name'),
            'Phone: ' . (string) $order->get_meta('_ccr_studio_customer_phone'),
            'Amount: GHS ' . number_format($amount, 2),
        ];

        if ($context === 'pending') {
            $lines[] = '';
            $lines[] = 'I have sent / am sending MoMo:';
            $lines[] = 'Network: ' . $network;
            $lines[] = 'To: ' . $number;
            $lines[] = 'Reference: ' . $reference;
            $lines[] = '(Attaching payment screenshot)';
        } else {
            $lines[] = 'Payment: Full MoMo — confirmed';
        }

        return implode("\n", $lines);
    }
}
