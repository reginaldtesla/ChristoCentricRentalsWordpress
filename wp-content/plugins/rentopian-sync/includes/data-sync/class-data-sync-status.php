<?php
/**
 * Data Sync Status & Phase Constants
 *
 * Statuses intentionally mirror the file-sync module so both log panels
 * and the Laravel session table read the same way.
 *
 * The chunk cursor encodes progress as:
 *   cursor = phase * PHASE_BASE + within-phase offset
 * which guarantees a strictly increasing `last_index` across the whole run
 * (the Laravel chunk job treats a non-advancing cursor as a stall).
 *
 * @package RentopianSync\DataSync
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Rental_Data_Sync_Status {

    const STATUS_CREATED    = 1;
    const STATUS_PROCESSING = 2;
    const STATUS_COMPLETED  = 3;
    const STATUS_FAILED     = 4;
    const STATUS_CANCELED   = 5;

    const MODE_SYNC = 1;

    /**
     * Cursor stride per phase. Any within-phase offset must stay below this.
     */
    const PHASE_BASE = 1000000;

    /*
     * Phase order honors entity dependencies: purge wipes the catalog before
     * anything is rebuilt; config and taxonomies before products; variants
     * are STAGED to disk before products because the product writer
     * prices/stocks the product from its embedded variants; sets after
     * products; sweep only after every fetch phase succeeded.
     *
     * Purge runs after config so the run has already proven it can reach the
     * API before anything is deleted.
     */
    const PHASE_CONFIG     = 0;
    const PHASE_PURGE      = 1;
    const PHASE_TAXONOMIES = 2;
    const PHASE_VARIANTS   = 3;
    const PHASE_PRODUCTS   = 4;
    const PHASE_SETS       = 5;
    const PHASE_EXTRAS     = 6;
    const PHASE_SWEEP      = 7;
    const PHASE_FINALIZE   = 8;

    /**
     * @return array phase => label
     */
    public static function phase_labels() {
        return [
            self::PHASE_CONFIG     => 'config',
            self::PHASE_PURGE      => 'purge',
            self::PHASE_TAXONOMIES => 'taxonomies',
            self::PHASE_VARIANTS   => 'variants-staging',
            self::PHASE_PRODUCTS   => 'products',
            self::PHASE_SETS       => 'sets',
            self::PHASE_EXTRAS     => 'extras',
            self::PHASE_SWEEP      => 'sweep',
            self::PHASE_FINALIZE   => 'finalize',
        ];
    }

    /**
     * @param int $phase
     * @return string
     */
    public static function phase_label( $phase ) {
        $labels = self::phase_labels();
        return isset( $labels[ $phase ] ) ? $labels[ $phase ] : "phase-{$phase}";
    }

    /**
     * Share of a run each phase represents, roughly proportional to how
     * long it takes on a typical catalog. Used to drive an honest progress
     * bar: the pull API exposes no totals, so a phase count alone would
     * make the bar jump from 12% to 50% when products start.
     *
     * @return array phase => weight (summing to 100)
     */
    public static function phase_weights() {
        return [
            self::PHASE_CONFIG     => 3,
            self::PHASE_PURGE      => 2,
            self::PHASE_TAXONOMIES => 7,
            self::PHASE_VARIANTS   => 10,
            self::PHASE_PRODUCTS   => 45,
            self::PHASE_SETS       => 15,
            self::PHASE_EXTRAS     => 5,
            self::PHASE_SWEEP      => 3,
            self::PHASE_FINALIZE   => 10,
        ];
    }

    /**
     * Progress across the whole run, 0–100.
     *
     * Completed phases contribute their full weight. The phase in flight
     * contributes a saturating fraction of its own weight, so the bar keeps
     * creeping forward on a long phase without ever reaching the next
     * phase's share or going backwards.
     *
     * @param int $phase           Phase currently in flight.
     * @param int $steps_in_phase  Steps completed within that phase.
     * @return int
     */
    public static function progress_percent( $phase, $steps_in_phase = 0 ) {
        $weights = self::phase_weights();
        $phase   = (int) $phase;
        $done    = 0;

        foreach ( $weights as $p => $weight ) {
            if ( $p < $phase ) {
                $done += $weight;
            }
        }

        $steps   = max( 0, (int) $steps_in_phase );
        $current = isset( $weights[ $phase ] ) ? $weights[ $phase ] : 0;
        $done   += $current * ( $steps / ( $steps + 4 ) );

        return max( 0, min( 99, (int) round( $done ) ) );
    }

    /**
     * Build a cursor value from phase + offset.
     *
     * @param int $phase
     * @param int $offset
     * @return int
     */
    public static function cursor( $phase, $offset = 0 ) {
        $offset = max( 0, min( (int) $offset, self::PHASE_BASE - 1 ) );
        return ( (int) $phase * self::PHASE_BASE ) + $offset;
    }

    /**
     * @param int $cursor
     * @return int Phase number.
     */
    public static function cursor_phase( $cursor ) {
        return (int) floor( ( (int) $cursor ) / self::PHASE_BASE );
    }

    /**
     * @param int $cursor
     * @return int Within-phase offset.
     */
    public static function cursor_offset( $cursor ) {
        return ( (int) $cursor ) % self::PHASE_BASE;
    }
}
