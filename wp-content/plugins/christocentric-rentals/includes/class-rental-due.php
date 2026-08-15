<?php

defined('ABSPATH') || exit;

/**
 * Late-return due dates and penalty calculation (Laravel RentalDue port).
 */
final class CCR_Rental_Due
{
    public static function grace_minutes(): int
    {
        return max(0, (int) get_option('ccr_grace_minutes', 30));
    }

    public static function daily_multiplier(): float
    {
        $m = (float) get_option('ccr_daily_rate_multiplier', 1);

        return $m > 0 ? $m : 1.0;
    }

    public static function due_soon_hours(): int
    {
        return max(1, (int) get_option('ccr_due_soon_hours', 2));
    }

    public static function due_at(WC_Order_Item_Product $item): ?DateTimeImmutable
    {
        $end = (string) $item->get_meta('_ccr_rental_end');
        if ($end === '') {
            return null;
        }
        $time = self::normalize_time((string) $item->get_meta('_ccr_return_time') ?: (string) get_option('ccr_default_return_time', '17:00'));
        $tz = wp_timezone();

        try {
            return new DateTimeImmutable($end . ' ' . $time, $tz);
        } catch (Exception $e) {
            return null;
        }
    }

    public static function calculate_penalty(WC_Order_Item_Product $item, ?DateTimeImmutable $returnedAt = null): float
    {
        $dueAt = self::due_at($item);
        if (! $dueAt) {
            return 0.0;
        }

        $returnedAt ??= new DateTimeImmutable('now', wp_timezone());
        $graceEnd = $dueAt->modify('+' . self::grace_minutes() . ' minutes');

        if ($returnedAt <= $graceEnd) {
            return 0.0;
        }

        $minutesLate = max(1, (int) floor(($returnedAt->getTimestamp() - $graceEnd->getTimestamp()) / 60));
        $lateDays = max(1, (int) ceil($minutesLate / 1440));

        $daily = (float) $item->get_meta('_ccr_price_per_day');
        if ($daily <= 0) {
            $product = $item->get_product();
            if ($product instanceof WC_Product && function_exists('ccr_product_regular_daily_price')) {
                $daily = ccr_product_regular_daily_price($product);
            }
        }
        if ($daily <= 0) {
            $qty = max(1, $item->get_quantity());
            $days = max(1, (int) $item->get_meta('_ccr_rental_days'));
            $daily = ((float) $item->get_total()) / ($qty * $days);
        }

        $qty = max(1, $item->get_quantity());

        return round($lateDays * $daily * $qty * self::daily_multiplier(), 2);
    }

    public static function late_days(WC_Order_Item_Product $item, ?DateTimeImmutable $returnedAt = null): int
    {
        $dueAt = self::due_at($item);
        if (! $dueAt) {
            return 0;
        }

        $returnedAt ??= new DateTimeImmutable('now', wp_timezone());
        $graceEnd = $dueAt->modify('+' . self::grace_minutes() . ' minutes');

        if ($returnedAt <= $graceEnd) {
            return 0;
        }

        $minutesLate = max(1, (int) floor(($returnedAt->getTimestamp() - $graceEnd->getTimestamp()) / 60));

        return max(1, (int) ceil($minutesLate / 1440));
    }

    /**
     * @return array{state:string,label:string,late_penalty:float,late_days:int,is_returned:bool}
     */
    public static function snapshot(WC_Order_Item_Product $item, ?DateTimeImmutable $reference = null): array
    {
        $reference ??= new DateTimeImmutable('now', wp_timezone());
        $returnedRaw = (string) $item->get_meta('_ccr_returned_at');
        $storedPenalty = (float) $item->get_meta('_ccr_late_penalty');
        $isReturned = $returnedRaw !== '';
        $returnedAt = null;

        if ($isReturned) {
            try {
                $returnedAt = new DateTimeImmutable($returnedRaw, wp_timezone());
            } catch (Exception $e) {
                $returnedAt = $reference;
            }
        }

        $dueAt = self::due_at($item);
        $compareAt = $returnedAt ?? $reference;
        $penalty = $isReturned ? $storedPenalty : ($dueAt && $compareAt > $dueAt ? self::calculate_penalty($item, $compareAt) : 0.0);
        $lateDays = self::late_days($item, $compareAt);

        if ($isReturned) {
            return [
                'state' => $penalty > 0 ? 'returned_late' : 'returned',
                'label' => $penalty > 0
                    ? sprintf(
                        /* translators: %s: formatted money */
                        __('Returned late — penalty %s', 'christocentric-rentals'),
                        function_exists('wc_price') ? wp_strip_all_tags(wc_price($penalty)) : (string) $penalty
                    )
                    : __('Returned on time', 'christocentric-rentals'),
                'late_penalty' => round($penalty, 2),
                'late_days' => $lateDays,
                'is_returned' => true,
            ];
        }

        if (! $dueAt) {
            return [
                'state' => 'none',
                'label' => '—',
                'late_penalty' => 0.0,
                'late_days' => 0,
                'is_returned' => false,
            ];
        }

        $secondsRemaining = $dueAt->getTimestamp() - $compareAt->getTimestamp();

        if ($secondsRemaining < 0) {
            return [
                'state' => 'overdue',
                'label' => sprintf(
                    /* translators: %s: duration */
                    __('Overdue by %s', 'christocentric-rentals'),
                    self::format_duration(abs($secondsRemaining))
                ),
                'late_penalty' => round(self::calculate_penalty($item, $reference), 2),
                'late_days' => $lateDays,
                'is_returned' => false,
            ];
        }

        if ($secondsRemaining <= self::due_soon_hours() * 3600) {
            return [
                'state' => 'due_soon',
                'label' => sprintf(
                    /* translators: %s: duration */
                    __('Due in %s', 'christocentric-rentals'),
                    self::format_duration($secondsRemaining)
                ),
                'late_penalty' => 0.0,
                'late_days' => 0,
                'is_returned' => false,
            ];
        }

        return [
            'state' => 'active',
            'label' => sprintf(
                /* translators: %s: duration */
                __('Due in %s', 'christocentric-rentals'),
                self::format_duration($secondsRemaining)
            ),
            'late_penalty' => 0.0,
            'late_days' => 0,
            'is_returned' => false,
        ];
    }

    public static function format_duration(int $seconds): string
    {
        $seconds = abs($seconds);
        if ($seconds < 60) {
            return $seconds . 's';
        }
        if ($seconds < 3600) {
            return intdiv($seconds, 60) . 'm';
        }
        if ($seconds < 86400) {
            $h = intdiv($seconds, 3600);
            $m = intdiv($seconds % 3600, 60);

            return $m > 0 ? "{$h}h {$m}m" : "{$h}h";
        }
        $d = intdiv($seconds, 86400);
        $h = intdiv($seconds % 86400, 3600);

        return $h > 0 ? "{$d}d {$h}h" : "{$d}d";
    }

    public static function mark_returned(WC_Order_Item_Product $item, WC_Order $order): float
    {
        $now = new DateTimeImmutable('now', wp_timezone());
        $penalty = self::calculate_penalty($item, $now);
        $item->update_meta_data('_ccr_returned_at', $now->format('Y-m-d H:i:s'));
        $item->update_meta_data('_ccr_late_penalty', $penalty);
        $item->save();

        $total = 0.0;
        foreach ($order->get_items() as $orderItem) {
            if ($orderItem instanceof WC_Order_Item_Product) {
                $total += (float) $orderItem->get_meta('_ccr_late_penalty');
            }
        }
        $order->update_meta_data('_ccr_total_late_penalty', round($total, 2));
        $order->add_order_note(
            $penalty > 0
                ? sprintf(
                    /* translators: 1: item name, 2: money */
                    __('Marked returned: %1$s — late penalty %2$s', 'christocentric-rentals'),
                    $item->get_name(),
                    wp_strip_all_tags(wc_price($penalty))
                )
                : sprintf(
                    /* translators: %s: item name */
                    __('Marked returned on time: %s', 'christocentric-rentals'),
                    $item->get_name()
                )
        );
        $order->save();

        if ($penalty > 0 && class_exists('CCR_Late_Notices')) {
            CCR_Late_Notices::send_returned_late_email($order, $item, $penalty);
        }

        return $penalty;
    }

    private static function normalize_time(string $time): string
    {
        $time = trim($time);
        if (preg_match('/^\d{1,2}:\d{2}/', $time, $m)) {
            return substr($m[0], 0, 5);
        }

        return '17:00';
    }
}
