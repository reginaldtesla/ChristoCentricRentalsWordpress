<?php

defined('ABSPATH') || exit;

/**
 * Email customers when a rental goes into a late 24hr penalty period.
 */
final class CCR_Late_Notices
{
    public const HOOK = 'ccr_check_late_rentals';

    public static function init(): void
    {
        add_action(self::HOOK, [self::class, 'run']);
        add_action('init', [self::class, 'maybe_schedule']);
    }

    public static function schedule(): void
    {
        if (! wp_next_scheduled(self::HOOK)) {
            wp_schedule_event(time() + 120, 'hourly', self::HOOK);
        }
    }

    public static function unschedule(): void
    {
        $timestamp = wp_next_scheduled(self::HOOK);
        while ($timestamp) {
            wp_unschedule_event($timestamp, self::HOOK);
            $timestamp = wp_next_scheduled(self::HOOK);
        }
    }

    public static function maybe_schedule(): void
    {
        self::schedule();
    }

    public static function run(): void
    {
        if (! function_exists('wc_get_orders') || ! class_exists('CCR_Rental_Due')) {
            return;
        }

        $orders = wc_get_orders([
            'status' => ['processing', 'completed', 'on-hold'],
            'limit' => 80,
            'orderby' => 'date',
            'order' => 'DESC',
            'return' => 'objects',
        ]);

        foreach ($orders as $order) {
            if (! $order instanceof WC_Order) {
                continue;
            }

            foreach ($order->get_items() as $item) {
                if (! $item instanceof WC_Order_Item_Product) {
                    continue;
                }
                if ((string) $item->get_meta('_ccr_rental_end') === '') {
                    continue;
                }
                if ((string) $item->get_meta('_ccr_returned_at') !== '') {
                    continue;
                }

                $snap = CCR_Rental_Due::snapshot($item);
                if (($snap['state'] ?? '') !== 'overdue') {
                    continue;
                }

                // Only after grace — first late 24hr block (hour 25+) and each new block.
                $lateDays = (int) ($snap['late_days'] ?? 0);
                if ($lateDays < 1) {
                    continue;
                }

                $lastNotified = (int) $item->get_meta('_ccr_late_notice_days');
                if ($lateDays <= $lastNotified) {
                    continue;
                }

                self::send_overdue_email($order, $item, $snap);
                $item->update_meta_data('_ccr_late_notice_days', $lateDays);
                $item->update_meta_data('_ccr_late_notice_sent_at', current_time('mysql'));
                $item->save();
            }
        }
    }

    /**
     * @param array{state:string,label:string,late_penalty:float,late_days:int,is_returned:bool} $snap
     */
    public static function send_overdue_email(WC_Order $order, WC_Order_Item_Product $item, array $snap): void
    {
        $to = $order->get_billing_email();
        if (! is_email($to)) {
            return;
        }

        $penalty = (float) ($snap['late_penalty'] ?? 0);
        $lateDays = max(1, (int) ($snap['late_days'] ?? 1));
        $dueAt = CCR_Rental_Due::due_at($item);
        $dueLabel = $dueAt ? $dueAt->format('M j, Y g:i a') : __('your agreed return time', 'christocentric-rentals');
        $penaltyLabel = function_exists('wc_price')
            ? wp_strip_all_tags(wc_price($penalty))
            : (string) $penalty;

        $subject = sprintf(
            /* translators: 1: site name, 2: order number */
            __('[%1$s] Late return notice — order #%2$s', 'christocentric-rentals'),
            get_bloginfo('name'),
            $order->get_order_number()
        );

        $body = '<p style="margin:0 0 14px;">' . esc_html(sprintf(
            /* translators: %s: customer first name */
            __('Hi %s,', 'christocentric-rentals'),
            $order->get_billing_first_name() ?: __('there', 'christocentric-rentals')
        )) . '</p>';
        $body .= '<p style="margin:0 0 14px;">' . esc_html(sprintf(
            /* translators: 1: product name, 2: due datetime */
            __('Your rental of “%1$s” was due back by %2$s. It is now past the 24-hour return window.', 'christocentric-rentals'),
            $item->get_name(),
            $dueLabel
        )) . '</p>';
        $body .= '<p style="margin:0 0 14px;">' . esc_html(sprintf(
            /* translators: 1: late 24hr periods, 2: money amount */
            __('A late fee is accruing for %1$d extra 24-hour period(s). Current estimated penalty: %2$s.', 'christocentric-rentals'),
            $lateDays,
            $penaltyLabel
        )) . '</p>';
        $body .= '<p style="margin:0 0 14px;">' . esc_html__(
            'Please return the gear as soon as possible during office hours to stop further charges. Contact us if you need to extend the rental.',
            'christocentric-rentals'
        ) . '</p>';
        $body .= '<p style="margin:0;">' . esc_html(sprintf(
            /* translators: %s: order number */
            __('Order #%s', 'christocentric-rentals'),
            $order->get_order_number()
        )) . '</p>';

        $html = class_exists('CCR_Email')
            ? CCR_Email::wrap_html($subject, $body)
            : $body;
        $headers = class_exists('CCR_Email') ? CCR_Email::html_headers() : ['Content-Type: text/html; charset=UTF-8'];

        wp_mail($to, $subject, $html, $headers);

        $order->add_order_note(sprintf(
            /* translators: 1: item name, 2: money */
            __('Late-return email sent for %1$s (estimated penalty %2$s).', 'christocentric-rentals'),
            $item->get_name(),
            $penaltyLabel
        ));
    }

    public static function send_returned_late_email(WC_Order $order, WC_Order_Item_Product $item, float $penalty): void
    {
        if ($penalty <= 0) {
            return;
        }

        $to = $order->get_billing_email();
        if (! is_email($to)) {
            return;
        }

        $penaltyLabel = function_exists('wc_price')
            ? wp_strip_all_tags(wc_price($penalty))
            : (string) $penalty;

        $subject = sprintf(
            /* translators: 1: site name, 2: order number */
            __('[%1$s] Late return fee — order #%2$s', 'christocentric-rentals'),
            get_bloginfo('name'),
            $order->get_order_number()
        );

        $body = '<p style="margin:0 0 14px;">' . esc_html(sprintf(
            __('Hi %s,', 'christocentric-rentals'),
            $order->get_billing_first_name() ?: __('there', 'christocentric-rentals')
        )) . '</p>';
        $body .= '<p style="margin:0 0 14px;">' . esc_html(sprintf(
            /* translators: 1: product name, 2: money */
            __('“%1$s” was marked returned after the agreed 24-hour window. A late fee of %2$s applies.', 'christocentric-rentals'),
            $item->get_name(),
            $penaltyLabel
        )) . '</p>';
        $body .= '<p style="margin:0;">' . esc_html__(
            'Our team will confirm how to settle the fee. Thank you.',
            'christocentric-rentals'
        ) . '</p>';

        $html = class_exists('CCR_Email')
            ? CCR_Email::wrap_html($subject, $body)
            : $body;
        $headers = class_exists('CCR_Email') ? CCR_Email::html_headers() : ['Content-Type: text/html; charset=UTF-8'];

        wp_mail($to, $subject, $html, $headers);
    }
}
