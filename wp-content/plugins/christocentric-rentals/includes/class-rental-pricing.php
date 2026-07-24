<?php

defined('ABSPATH') || exit;

/**
 * Mirrors Laravel App\Services\RentalPricing.
 */
final class CCR_Rental_Pricing
{
    public static function rental_days(string $start, string $end): int
    {
        $startDate = strtotime($start . ' 00:00:00');
        $endDate = strtotime($end . ' 00:00:00');

        if ($startDate === false || $endDate === false || $endDate < $startDate) {
            return 0;
        }

        $days = (int) floor(($endDate - $startDate) / DAY_IN_SECONDS) + 1;

        return max(1, $days);
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
}
