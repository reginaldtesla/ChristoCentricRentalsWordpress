<?php

defined('ABSPATH') || exit;

/**
 * 24-hour rental pricing (ceil of elapsed time / 24h).
 */
final class CCR_Rental_Pricing
{
    /**
     * Number of 24-hour rental periods between pickup and return.
     * Exactly 24 hours = 1 period; anything into the next hour block (e.g. 24h+1s) = 2.
     */
    public static function rental_days(
        string $start,
        string $end,
        ?string $pickupTime = null,
        ?string $returnTime = null
    ): int {
        $pickup = self::normalize_time($pickupTime ?: '00:00');
        $return = self::normalize_time($returnTime ?: '23:59');

        $startTs = strtotime($start . ' ' . $pickup . ':00');
        $endTs = strtotime($end . ' ' . $return . ':00');

        if ($startTs === false || $endTs === false || $endTs <= $startTs) {
            return 0;
        }

        $periods = (int) ceil(($endTs - $startTs) / DAY_IN_SECONDS);

        return max(1, $periods);
    }

    public static function line_total(float $pricePerDay, int $days, int $quantity = 1): float
    {
        return round($pricePerDay * $days * $quantity, 2);
    }

    public static function format(float $amount): string
    {
        return '₵' . number_format($amount, 2);
    }

    public static function format_time(?string $time): ?string
    {
        if ($time === null || trim($time) === '') {
            return null;
        }

        $timestamp = strtotime($time);

        return $timestamp ? gmdate('g:i A', $timestamp + (get_option('gmt_offset') * HOUR_IN_SECONDS)) : null;
    }

    public static function format_schedule(string $date, ?string $time): string
    {
        $timestamp = strtotime($date);
        $line = $timestamp ? date_i18n('M j, Y', $timestamp) : $date;
        $formattedTime = self::format_time($time);

        return $formattedTime ? "{$line} at {$formattedTime}" : $line;
    }

    /**
     * Pickup datetime + N hours → [date Y-m-d, time H:i], clamped to office closing on that day.
     *
     * @return array{0:string,1:string}|null
     */
    public static function add_hours_clamped(string $date, string $time, int $hours, ?string $latestReturn = null): ?array
    {
        $time = self::normalize_time($time);
        $tz = wp_timezone();

        try {
            $dt = new DateTimeImmutable($date . ' ' . $time . ':00', $tz);
        } catch (Exception $e) {
            return null;
        }

        $dt = $dt->modify('+' . max(0, $hours) . ' hours');
        $outDate = $dt->format('Y-m-d');
        $outTime = $dt->format('H:i');
        $latest = self::normalize_time($latestReturn ?: (string) get_option('ccr_latest_return_time', '20:50'));

        if ($outTime > $latest) {
            $outTime = $latest;
        }

        return [$outDate, $outTime];
    }

    private static function normalize_time(string $time): string
    {
        if (preg_match('/^(\d{1,2}):(\d{2})/', trim($time), $m)) {
            return sprintf('%02d:%02d', min(23, max(0, (int) $m[1])), min(59, max(0, (int) $m[2])));
        }

        return '00:00';
    }
}
