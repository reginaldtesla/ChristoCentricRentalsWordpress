<?php
/**
 * Sync Status & Mode Constants
 *
 * Centralises the status and mode integer constants
 *
 * @package RentopianSync\FileSync
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Rental_Sync_Status {

    /* ── Session Statuses ────────────────────────────────────── */
    const STATUS_CREATED    = 1;
    const STATUS_PROCESSING = 2;
    const STATUS_COMPLETED  = 3;
    const STATUS_FAILED     = 4;
    const STATUS_CANCELED   = 5;

    /* ── Sync Modes ──────────────────────────────────────────── */
    const MODE_SYNC   = 1;
    const MODE_RESYNC = 2;

    /**
     * Human-readable label for a status integer.
     *
     * @param int $status
     * @return string
     */
    public static function label( $status ) {
        $map = [
            self::STATUS_CREATED    => 'created',
            self::STATUS_PROCESSING => 'processing',
            self::STATUS_COMPLETED  => 'completed',
            self::STATUS_FAILED     => 'failed',
            self::STATUS_CANCELED   => 'canceled',
        ];

        return isset( $map[ $status ] ) ? $map[ $status ] : 'unknown';
    }

    /**
     * Human-readable label for a mode integer.
     *
     * @param int $mode
     * @return string
     */
    public static function mode_label( $mode ) {
        return ( $mode === self::MODE_RESYNC ) ? 'resync' : 'sync';
    }
}
