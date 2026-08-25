<?php
/**
 * Rental_Sync_Time
 *
 * Turning a stored datetime back into a real moment.
 *
 * Both sync modules record their timestamps with `current_time('mysql')`,
 * which is SITE-LOCAL, and compare them against `time()`, which is UTC.
 * Bridging the two with `mysql2date('U', …)` is wrong: for the 'U' format
 * WordPress returns `getTimestamp() + getOffset()` — its own docblock calls
 * that "a sum of timestamp with timezone offset. Ideally should never be
 * used." On a site at UTC-8 it lands eight hours in the past, which is long
 * enough for the watchdog to declare a run that started a second ago
 * abandoned.
 *
 * Every conversion goes through here so that mistake has one place to live,
 * and none.
 *
 * @package RentopianSync\SyncLog
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Rental_Sync_Time {

    /**
     * Real Unix time for a datetime written in site-local time.
     *
     * @param string $mysql_datetime e.g. '2026-07-30 06:28:35'
     * @return int 0 when the value cannot be read as a date.
     */
    public static function to_epoch( $mysql_datetime ) {
        $mysql_datetime = trim( (string) $mysql_datetime );

        if ( '' === $mysql_datetime ) {
            return 0;
        }

        $datetime = date_create( $mysql_datetime, wp_timezone() );

        return $datetime ? $datetime->getTimestamp() : 0;
    }

    /**
     * When a run began, in real Unix time.
     *
     * A run started since this was introduced records the epoch itself and
     * needs no conversion at all; older ones fall back to their local
     * string. Preferring the epoch keeps a run immune to the site's
     * timezone being changed underneath it mid-run.
     *
     * @param array  $record     Session or run row.
     * @param string $epoch_key  Field holding a Unix time.
     * @param string $string_key Field holding a local datetime.
     * @return int 0 when neither is present.
     */
    public static function started( array $record, $epoch_key = 'started_epoch', $string_key = 'started_at' ) {
        $epoch = (int) ( $record[ $epoch_key ] ?? 0 );

        if ( $epoch > 0 ) {
            return $epoch;
        }

        return self::to_epoch( $record[ $string_key ] ?? '' );
    }

    /**
     * Seconds a run has been going, never negative and never longer than
     * the run itself could have lasted.
     *
     * @param array  $record
     * @param string $epoch_key
     * @param string $string_key
     * @return int
     */
    public static function age( array $record, $epoch_key = 'started_epoch', $string_key = 'started_at' ) {
        $started = self::started( $record, $epoch_key, $string_key );

        return $started ? max( 0, time() - $started ) : 0;
    }
}
