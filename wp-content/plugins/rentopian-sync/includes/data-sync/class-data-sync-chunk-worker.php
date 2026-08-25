<?php
/**
 * Data Sync Chunk Worker
 *
 * Runs one bounded phase step per inbound chunk request. The cursor
 * (phase * PHASE_BASE + offset) is stored after every step, strictly
 * increases across the run, and fast-forwards when the driver retries an
 * already-processed index after a lost response.
 *
 * Failure model: a step exception keeps the session alive so the Laravel
 * driver retries with backoff; MAX_CONSECUTIVE_ERRORS at the same stage or
 * a variants-fetch server error is terminal — the session fails, the
 * failure email goes out, and the /status endpoint reports the run as
 * canceled so the driver stops immediately.
 *
 * @package RentopianSync\DataSync
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Rental_Data_Sync_Chunk_Worker {

    const MAX_CONSECUTIVE_ERRORS = 5;

    /**
     * Process one chunk callback.
     *
     * @param int    $start_index Cursor from the driver.
     * @param string $sync_id
     * @return array { last_index, completed, failed_ids, failed_count, processed_count, total_count }
     */
    public static function process( $start_index, $sync_id ) {
        if ( function_exists( 'set_time_limit' ) ) {
            @set_time_limit( 300 );
        }

        Rental_Data_Sync_Logger::bind_run( $sync_id );

        $t0      = microtime( true );
        $start   = (int) $start_index;
        $session = Rental_Data_Sync_Session::get( $sync_id );

        if ( ! $session ) {
            Rental_Data_Sync_Logger::write( "Chunk rejected: no session for sync_id={$sync_id}", 'warning', 'chunk' );
            return self::result( $start, false );
        }

        $status = (int) ( $session['status'] ?? 0 );

        if ( $status === Rental_Data_Sync_Status::STATUS_CANCELED ) {
            Rental_Data_Sync_Logger::write( "Chunk rejected: session {$sync_id} is canceled", 'info', 'chunk' );
            return self::result( $start, true );
        }

        // "Stop all" raises this switch. Without honouring it here the switch
        // only refuses NEW runs, and everything the queue already holds keeps
        // arriving and keeps being processed.
        if ( Rental_Data_Sync_Session::is_globally_disabled() ) {
            Rental_Data_Sync_Logger::write( "Chunk rejected: synchronization is stopped site-wide ({$sync_id})", 'warning', 'chunk' );
            return self::result( $start, true );
        }

        if ( $status === Rental_Data_Sync_Status::STATUS_COMPLETED ) {
            $stored = Rental_Data_Sync_Session::get_stored_cursor( $sync_id );
            return self::result( max( $stored, $start ), true, (int) ( $session['processed_count'] ?? 0 ) );
        }

        // A chunk arriving is proof the driver is alive, which is the one
        // thing the watchdog cannot see. Rather than let the run carry on
        // under a verdict that has just been disproved — finishing
        // successfully while its record still reads "failed", and mailing
        // both — the run is put back to processing and says so.
        if ( $status === Rental_Data_Sync_Status::STATUS_FAILED ) {
            $session = Rental_Data_Sync_Session::revive( $sync_id ) ?: $session;
        }

        $cur_gen  = Rental_Data_Sync_Session::generation_current();
        $sess_gen = isset( $session['generation'] ) ? (int) $session['generation'] : -1;
        if ( $sess_gen !== $cur_gen ) {
            Rental_Data_Sync_Logger::write( "Chunk rejected: generation mismatch for {$sync_id} (session={$sess_gen}, current={$cur_gen})", 'warning', 'chunk' );
            return self::result( $start, false );
        }

        // Fast-forward when the driver retries a stale index.
        $stored = Rental_Data_Sync_Session::get_stored_cursor( $sync_id );
        if ( $stored > 0 && $start < $stored ) {
            Rental_Data_Sync_Logger::write( "Cursor advanced from {$start} to {$stored} for {$sync_id}", 'info', 'chunk' );
            $start = $stored;
        }

        if ( ! Rental_Data_Sync_Session::acquire_lock( $sync_id ) ) {
            Rental_Data_Sync_Logger::write( "Chunk deferred: lock not acquired for {$sync_id}", 'info', 'chunk' );
            return self::result( $start, false, (int) ( $session['processed_count'] ?? 0 ) );
        }

        $phase  = Rental_Data_Sync_Status::cursor_phase( $start );
        $offset = Rental_Data_Sync_Status::cursor_offset( $start );

        try {
            if ( $phase > Rental_Data_Sync_Status::PHASE_FINALIZE ) {
                Rental_Data_Sync_Session::release_lock( $sync_id );
                return self::result( $start, true, (int) ( $session['processed_count'] ?? 0 ) );
            }

            $phases = new Rental_Data_Sync_Phases( $sync_id, get_option( 'rental_api_key' ) );
            $step   = $phases->run_step( $phase, $offset );

            $writer_stats = $phases->step_stats();
            if ( ! empty( $writer_stats ) ) {
                Rental_Data_Sync_Session::add_stats( $sync_id, $writer_stats );
            }

            // The counters say how many failed; these say which ones and
            // why, so the report can name them.
            $writer_failures = $phases->step_failures();
            if ( ! empty( $writer_failures ) ) {
                Rental_Data_Sync_Session::add_failures( $sync_id, $writer_failures );
            }

            $run_completed = false;
            if ( ! empty( $step['done'] ) ) {
                Rental_Data_Sync_Session::mark_phase_done( $sync_id, $phase );
                $run_completed = ( $phase === Rental_Data_Sync_Status::PHASE_FINALIZE );
                $next          = Rental_Data_Sync_Status::cursor( $phase + 1, 0 );
            } else {
                $next = Rental_Data_Sync_Status::cursor( $phase, (int) $step['offset'] );
            }

            // The driver treats a non-advancing cursor as a stall.
            if ( $next <= $start ) {
                $next = $start + 1;
            }

            Rental_Data_Sync_Session::store_cursor( $sync_id, $next );

            $processed_total = (int) ( $session['processed_count'] ?? 0 ) + (int) ( $step['processed'] ?? 0 );
            $elapsed         = microtime( true ) - $t0;
            $status          = $run_completed ? Rental_Data_Sync_Status::STATUS_COMPLETED : Rental_Data_Sync_Status::STATUS_PROCESSING;

            $phase_steps            = (array) ( $session['phase_steps'] ?? [] );
            $phase_steps[ $phase ]  = (int) ( $phase_steps[ $phase ] ?? 0 ) + 1;

            Rental_Data_Sync_Session::update( $sync_id, [
                'last_index'      => $next,
                'phase'           => $phase,
                'phase_steps'     => $phase_steps,
                'processed_count' => $processed_total,
                'status'          => $status,
                'error_count'     => 0,
            ] );

            // The per-entity outcome of the step goes on the line as well:
            // when a run finishes with the wrong catalog, what was created,
            // updated, skipped and failed at each step is the record that
            // explains it.
            Rental_Data_Sync_Logger::write(
                rtrim( sprintf(
                    'Phase %s: offset %d -> %d, processed %d rows (%.2fs, mem %.1fMB) %s',
                    Rental_Data_Sync_Status::phase_label( $phase ),
                    $offset,
                    Rental_Data_Sync_Status::cursor_offset( $next ),
                    (int) ( $step['processed'] ?? 0 ),
                    $elapsed,
                    memory_get_peak_usage( true ) / 1048576,
                    self::describe_stats( $writer_stats )
                ) ),
                'info',
                'phase'
            );

            if ( $run_completed ) {
                Rental_Data_Sync_Logger::write( 'Data sync completed — file sync chain scheduled', 'info', 'phase' );

                Rental_Data_Sync_Run_Repository::finish(
                    $sync_id,
                    Rental_Data_Sync_Status::STATUS_COMPLETED,
                    self::outcome_summary( $sync_id ),
                    [
                        'phase'           => $phase,
                        'processed_count' => $processed_total,
                        'failed_count'    => self::failed_total( $sync_id ),
                    ]
                );
            } else {
                Rental_Data_Sync_Run_Repository::touch( $sync_id, [
                    'status'          => $status,
                    'phase'           => $phase,
                    'processed_count' => $processed_total,
                ] );
            }

            Rental_Data_Sync_Session::release_lock( $sync_id );

            return self::result( $next, $run_completed, $processed_total );

        } catch ( Throwable $e ) {
            Rental_Data_Sync_Session::release_lock( $sync_id );
            return self::handle_step_error( $sync_id, $session, $phase, $start, $e );
        }
    }

    /**
     * Classify a step failure as retryable or terminal.
     */
    private static function handle_step_error( $sync_id, array $session, $phase, $start, Throwable $e ) {
        $error_count = (int) ( $session['error_count'] ?? 0 ) + 1;

        // A server error from the variants stream never recovers by
        // retrying (inactive-company key); fail the run immediately. The
        // purge probes that same endpoint before wiping, so the same error
        // is just as terminal there.
        $probes_variants = in_array(
            $phase,
            [ Rental_Data_Sync_Status::PHASE_VARIANTS, Rental_Data_Sync_Status::PHASE_PURGE ],
            true
        );

        $terminal = ( $probes_variants
            && $e instanceof RentalException
            && (int) $e->getStatusCode() >= 500 );

        if ( $error_count >= self::MAX_CONSECUTIVE_ERRORS ) {
            $terminal = true;
        }

        Rental_Data_Sync_Logger::write(
            sprintf(
                'Phase %s error (%d/%d%s): %s',
                Rental_Data_Sync_Status::phase_label( $phase ),
                $error_count,
                self::MAX_CONSECUTIVE_ERRORS,
                $terminal ? ', TERMINAL' : '',
                $e->getMessage()
            ),
            'error',
            'phase',
            $sync_id
        );
        Rental_Data_Sync_Logger::write( 'Trace: ' . substr( $e->getTraceAsString(), 0, 2000 ), 'debug', 'phase', $sync_id );

        if ( $terminal ) {
            $reason = sprintf(
                /* translators: 1: phase name, 2: error message */
                __( 'Failed during the "%1$s" phase: %2$s', 'rentopian-sync' ),
                Rental_Data_Sync_Status::phase_label( $phase ),
                $e->getMessage()
            );

            Rental_Data_Sync_Session::update( $sync_id, [
                'status'      => Rental_Data_Sync_Status::STATUS_FAILED,
                'last_error'  => $reason,
                'error_count' => $error_count,
            ] );

            Rental_Data_Sync_Run_Repository::finish(
                $sync_id,
                Rental_Data_Sync_Status::STATUS_FAILED,
                $reason,
                [
                    'phase'           => $phase,
                    'processed_count' => (int) ( $session['processed_count'] ?? 0 ),
                    'failed_count'    => self::failed_total( $sync_id ),
                ]
            );

            Rental_Data_Sync_Session::cleanup_staging( $sync_id );

            if ( class_exists( 'Rental_Data_Sync_Reporter', false ) ) {
                Rental_Data_Sync_Reporter::send_failure_report( $sync_id, $reason, $phase );
            }
        } else {
            Rental_Data_Sync_Session::update( $sync_id, [
                'last_error'  => $e->getMessage(),
                'error_count' => $error_count,
            ] );

            Rental_Data_Sync_Run_Repository::touch( $sync_id, [
                'phase'        => $phase,
                'failed_count' => self::failed_total( $sync_id ),
                'message'      => $e->getMessage(),
            ] );
        }

        // success stays true so the driver keeps pacing; the unchanged
        // cursor triggers its stall backoff, and terminal runs stop via
        // the /status endpoint on the next poll.
        return self::result( $start, false, (int) ( $session['processed_count'] ?? 0 ) );
    }

    /**
     * Flatten the step's per-entity counters onto the log line, so both the
     * successful and the failed part of a step are on the record.
     *
     * @param array $stats entity => [ created => n, updated => n, … ]
     * @return string
     */
    private static function describe_stats( array $stats ) {
        // Only the phases that write through the record writer have these
        // counters; sets, extras, config and purge write through the sets
        // module and the legacy helpers instead. Claiming "no records
        // written" for those was reporting the absence of a counter as an
        // absence of work.
        if ( empty( $stats ) ) {
            return '';
        }

        $parts = [];
        foreach ( $stats as $entity => $counts ) {
            $bits = [];
            foreach ( [ 'created', 'updated', 'repaired', 'deleted', 'skipped', 'failed' ] as $key ) {
                if ( ! empty( $counts[ $key ] ) ) {
                    $bits[] = $counts[ $key ] . ' ' . $key;
                }
            }
            if ( $bits ) {
                $parts[] = $entity . ': ' . implode( ', ', $bits );
            }
        }

        return $parts ? '— ' . implode( ' | ', $parts ) : '— no records written';
    }

    /**
     * One-line outcome stored on the run record.
     *
     * @param string $sync_id
     * @return string
     */
    private static function outcome_summary( $sync_id ) {
        $failed = self::failed_total( $sync_id );

        if ( $failed > 0 ) {
            /* translators: %d: number of records that failed to import */
            return sprintf( _n( 'Completed with %d failed record.', 'Completed with %d failed records.', $failed, 'rentopian-sync' ), $failed );
        }

        $session = Rental_Data_Sync_Session::get( $sync_id ) ?: [];
        if ( ! empty( $session['sweep_skipped'] ) ) {
            return __( 'Completed; the orphan sweep was skipped.', 'rentopian-sync' );
        }

        return __( 'Completed.', 'rentopian-sync' );
    }

    /**
     * @param string $sync_id
     * @return int Records the writers could not import this run.
     */
    private static function failed_total( $sync_id ) {
        $failed = 0;
        foreach ( Rental_Data_Sync_Session::get_stats( $sync_id ) as $counts ) {
            $failed += (int) ( $counts['failed'] ?? 0 );
        }
        return $failed;
    }

    /**
     * @return array Driver response body.
     */
    private static function result( $last_index, $completed, $processed = 0 ) {
        return [
            'last_index'      => (int) $last_index,
            'completed'       => (bool) $completed,
            'failed_ids'      => [],
            'failed_count'    => 0,
            'processed_count' => (int) $processed,
            'total_count'     => 0,
        ];
    }
}
