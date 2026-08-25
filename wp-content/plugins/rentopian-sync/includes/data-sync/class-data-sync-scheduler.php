<?php
/**
 * Data Sync Scheduler
 *
 * Starts / cancels background data-sync runs. Reuses the Laravel `sync/start`
 * pacemaker with this module's own chunk endpoint — the driver is
 * endpoint-agnostic, so no Laravel changes are involved. Starting a data
 * sync occupies the company's single sync slot (Laravel cancels previous
 * sessions), which is exactly the intended serial sequence:
 * data sync → file sync.
 *
 * @package RentopianSync\DataSync
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Rental_Data_Sync_Scheduler {

    /**
     * Option holding the last fully successful run (drives the Start vs
     * Resync button label).
     */
    const OPTION_LAST_SUCCESS = 'rental_data_sync_last_success';

    /**
     * Seconds the start request may take. The Rentopian endpoint only
     * queues a job, so a slow answer means the server is unhealthy — and
     * without a bound the admin request would hang until PHP gives up with
     * no usable error.
     */
    const START_TIMEOUT = 30;

    /**
     * Option holding the chain that is waiting to fire.
     */
    const OPTION_PENDING_CHAIN = 'rental_data_sync_pending_chain';

    /**
     * Option holding a chained file sync that has not proved it started.
     */
    const OPTION_CHAIN_WATCH = 'rental_data_sync_chain_watch';

    /**
     * Seconds to wait after the data phase before starting the file sync.
     *
     * The Laravel chunk job holds the company's overlap lock until it has
     * finished handling the final response, and a file job dispatched inside
     * that window is DISCARDED rather than retried. In practice the job ends
     * in the same second it receives the response, so this only has to cover
     * the response finishing its trip back — anything longer is dead time
     * between the two phases.
     */
    const CHAIN_DELAY = 15;

    /**
     * Extra seconds before the `init` fallback takes over from cron. Only a
     * site whose cron cannot run (no loopback, no system cron) waits this
     * long, and then only until its next request.
     */
    const CHAIN_CRON_GRACE = 15;

    /**
     * Seconds a chained file sync may show no sign of life before it is
     * assumed lost — a job discarded by the overlap lock leaves no trace, so
     * silence is the only symptom there is — and chained again.
     */
    const CHAIN_VERIFY_AFTER = 90;

    /**
     * How many times one data run may chain a file sync before giving up and
     * reporting it, so a persistent fault cannot loop.
     */
    const CHAIN_MAX_ATTEMPTS = 3;

    /**
     * Whether a data sync has ever completed successfully on this site.
     * Used by the admin UI to label the button Start vs Resync.
     *
     * @return array|null { sync_id, completed_at } or null.
     */
    public static function last_success() {
        $last = get_option( self::OPTION_LAST_SUCCESS, [] );
        return ( is_array( $last ) && ! empty( $last['completed_at'] ) ) ? $last : null;
    }

    /**
     * Record a fully completed data run.
     *
     * @param string $sync_id
     */
    public static function record_success( $sync_id ) {
        update_option( self::OPTION_LAST_SUCCESS, [
            'sync_id'      => (string) $sync_id,
            'completed_at' => current_time( 'mysql' ),
        ], false );
    }

    /**
     * Terminal state of the most recent run, whatever it was. The panel
     * shows this so a failure is as visible as a success.
     *
     * @return array|null { sync_id, status, status_label, finished_at, duration, message }
     */
    public static function last_run() {
        $row = Rental_Data_Sync_Run_Repository::last_finished();
        if ( ! $row ) {
            return null;
        }

        return [
            'sync_id'      => $row['sync_id'],
            'status'       => $row['status'],
            'status_label' => $row['status_label'],
            'finished_at'  => $row['finished_at'],
            'duration'     => $row['duration'],
            'message'      => (string) $row['message'],
        ];
    }

    /**
     * Schedule a new background sync: the catalog data phase, then the file
     * phase, which is chained automatically when the data phase completes.
     *
     * @param string $api_key Plugin API key (already validated by caller).
     * @return array { success, message, sync_id?, reason?, hints?, warnings? }
     */
    public static function schedule( $api_key ) {
        Rental_Data_Sync_Run_Repository::install();

        // The relation tables predate this module and carry no index on
        // rental_id, which makes every create-or-update lookup a full
        // table scan. Ensure the indexes before a run rather than after.
        Rental_Data_Sync_Integrity::ensure_relation_indexes();

        // Refuse to start when the callback path is provably broken: a run
        // that cannot be driven would otherwise sit at "created" with no
        // explanation.
        $preflight = Rental_Data_Sync_Watchdog::preflight( $api_key );
        if ( ! $preflight['ok'] ) {
            $blocker = $preflight['blockers'][0];

            Rental_Data_Sync_Logger::write(
                'Start refused by preflight (' . $blocker['code'] . '): ' . $blocker['message'],
                'error',
                'schedule'
            );

            return [
                'success'  => false,
                'message'  => $blocker['message'],
                'reason'   => $blocker['code'],
                'hints'    => $blocker['hints'],
                'warnings' => $preflight['warnings'],
            ];
        }

        if ( Rental_Data_Sync_Session::is_globally_disabled() ) {
            Rental_Data_Sync_Session::clear_global_disable();
            Rental_Data_Sync_Session::generation_increment();
        }

        $sync_id  = wp_generate_uuid4();
        $wp_token = wp_generate_password( 32, false );

        Rental_Data_Sync_Logger::bind_run( $sync_id );

        update_option( 'rental_api_key', $api_key );
        update_option( 'rental_data_sync_current_id', $sync_id );

        // Mirror the legacy sync-state semantics: 2 = in progress; the
        // chained file sync sets 1 on completion.
        update_option( 'rental_synchronize_status', 2 );
        update_option( 'rental_sync_time', time() );

        Rental_Data_Sync_Session::cancel_previous_sessions( $sync_id );

        Rental_Data_Sync_Session::update( $sync_id, [
            'wp_token'        => $wp_token,
            'status'          => Rental_Data_Sync_Status::STATUS_CREATED,
            'started_at'      => current_time( 'mysql' ),
            // Real Unix time as well, so nothing has to convert a local
            // string back to a moment to tell how long the run has been going.
            'started_epoch'   => time(),
            'phase'           => Rental_Data_Sync_Status::PHASE_CONFIG,
            'last_index'      => 0,
            'processed_count' => 0,
            'error_count'     => 0,
            'generation'      => Rental_Data_Sync_Session::generation_current(),
            'mode'            => Rental_Data_Sync_Status::MODE_SYNC,
            'callback_url'    => $preflight['callback_url'],
            'last_chunk_at'   => 0,
            'first_chunk_at'  => 0,
        ] );
        Rental_Data_Sync_Session::store_cursor( $sync_id, 0 );

        Rental_Data_Sync_Run_Repository::start( $sync_id, 'Data sync started' );

        if ( '' === Rental_Data_Sync_Session::staging_dir( $sync_id ) ) {
            return self::fail_start(
                $sync_id,
                __( 'The staging directory could not be created under wp-content/uploads.', 'rentopian-sync' ),
                'staging_unwritable',
                [ __( 'Make wp-content/uploads writable by the web server and start again.', 'rentopian-sync' ) ]
            );
        }

        Rental_Data_Sync_Logger::write( 'Data sync started; callback URL ' . $preflight['callback_url'], 'info', 'schedule' );

        foreach ( $preflight['warnings'] as $warning ) {
            Rental_Data_Sync_Logger::write( 'Preflight warning (' . $warning['code'] . '): ' . $warning['message'], 'warning', 'schedule' );
        }

        try {
            $response = rental_curl( 'sync/start', $api_key, true, [
                'wp_endpoint' => $preflight['callback_url'],
                'wp_token'    => $wp_token,
                'sync_id'     => $sync_id,
                'start_index' => 0,
                'chunk_size'  => 8,
                'mode'        => Rental_Data_Sync_Status::MODE_SYNC,
            ], null, false, self::START_TIMEOUT );

        } catch ( Throwable $e ) {
            return self::fail_start(
                $sync_id,
                sprintf(
                    /* translators: %s: error reported by the Rentopian server */
                    __( 'Rentopian rejected the start request: %s', 'rentopian-sync' ),
                    $e->getMessage()
                ),
                'remote_rejected',
                [
                    __( 'Check that the API key belongs to an active company and that the Rentopian server is reachable.', 'rentopian-sync' ),
                    __( 'Nothing was written to the catalog — the storefront is unaffected.', 'rentopian-sync' ),
                ]
            );
        }

        $rejection = self::rejection_reason( $response );
        if ( $rejection ) {
            return self::fail_start(
                $sync_id,
                sprintf(
                    /* translators: %s: reason reported by the Rentopian server */
                    __( 'Rentopian did not queue the run: %s', 'rentopian-sync' ),
                    $rejection
                ),
                'remote_not_queued',
                [ __( 'Confirm on the Rentopian server that the sync session was created and a worker is consuming the "webhooks" queue.', 'rentopian-sync' ) ]
            );
        }

        Rental_Data_Sync_Logger::write(
            'Rentopian accepted the start request: ' . wp_json_encode( $response ),
            'info',
            'schedule'
        );

        return [
            'success'              => true,
            'message'              => __( 'Background synchronization scheduled.', 'rentopian-sync' ),
            'sync_id'              => $sync_id,
            'warnings'             => $preflight['warnings'],
            'response_from_rental' => $response,
        ];
    }

    /**
     * Mark a run that never got off the ground as failed, so the panel
     * reports the cause instead of showing a run stuck at "created".
     *
     * @param string   $sync_id
     * @param string   $message
     * @param string   $reason  Machine-readable code.
     * @param string[] $hints
     * @return array
     */
    private static function fail_start( $sync_id, $message, $reason, array $hints = [] ) {
        Rental_Data_Sync_Session::update( $sync_id, [
            'status'     => Rental_Data_Sync_Status::STATUS_FAILED,
            'last_error' => $message,
        ] );

        Rental_Data_Sync_Run_Repository::finish( $sync_id, Rental_Data_Sync_Status::STATUS_FAILED, $message );

        update_option( 'rental_synchronize_status', 0 );

        Rental_Data_Sync_Logger::write( 'Start failed (' . $reason . '): ' . $message, 'error', 'schedule' );

        Rental_Data_Sync_Reporter::send_failure_report( $sync_id, $message, Rental_Data_Sync_Status::PHASE_CONFIG );

        return [
            'success' => false,
            'message' => $message,
            'reason'  => $reason,
            'hints'   => $hints,
            'sync_id' => $sync_id,
        ];
    }

    /**
     * A 200 from `sync/start` does not by itself mean the run was queued.
     *
     * @param mixed $response Decoded body.
     * @return string Reason when the run was not queued, '' when it was.
     */
    private static function rejection_reason( $response ) {
        if ( null === $response || false === $response || '' === $response ) {
            return __( 'the server returned an empty response', 'rentopian-sync' );
        }

        $body = is_object( $response ) ? get_object_vars( $response ) : ( is_array( $response ) ? $response : [] );

        if ( array_key_exists( 'success', $body ) && ! $body['success'] ) {
            return isset( $body['message'] ) && is_string( $body['message'] )
                ? $body['message']
                : __( 'the server reported the request as unsuccessful', 'rentopian-sync' );
        }

        if ( array_key_exists( 'error', $body ) && $body['error'] ) {
            return is_string( $body['error'] ) ? $body['error'] : __( 'the server reported an error', 'rentopian-sync' );
        }

        return '';
    }

    /**
     * Cancel the current (or given) run and notify the Laravel driver.
     * Also stops a file sync this run chained, so one button aborts the
     * whole pipeline.
     *
     * @param string $sync_id      Optional; defaults to the current run.
     * @param bool   $cancel_files Also cancel the chained/active file sync.
     * @return array { success, message }
     */
    public static function cancel( $sync_id = '', $cancel_files = true ) {
        $sync_id = $sync_id ?: get_option( 'rental_data_sync_current_id', '' );

        $session    = $sync_id ? ( Rental_Data_Sync_Session::get( $sync_id ) ?: [] ) : [];
        $data_alive = in_array(
            (int) ( $session['status'] ?? 0 ),
            [ Rental_Data_Sync_Status::STATUS_CREATED, Rental_Data_Sync_Status::STATUS_PROCESSING ],
            true
        );

        // A data phase that already finished is not cancelable — saying it was
        // would overwrite the record of a run that genuinely succeeded. What
        // is still stoppable in that state is the file phase it chained.
        if ( ! $data_alive ) {
            self::drop_chain_for( $sync_id );

            $files = $cancel_files ? self::cancel_file_sync() : [ 'canceled' => false, 'sync_id' => '' ];

            if ( ! empty( $files['canceled'] ) ) {
                return [
                    'success'      => true,
                    'message'      => __( 'The image synchronization was stopped.', 'rentopian-sync' ),
                    'sync_id'      => $sync_id,
                    'file_sync_id' => $files['sync_id'],
                ];
            }

            return [ 'success' => false, 'message' => __( 'Nothing is running to stop.', 'rentopian-sync' ) ];
        }

        Rental_Data_Sync_Logger::bind_run( $sync_id );

        // Everything the driver already holds is made stale, so a chunk
        // dispatched a moment before this click cannot land afterwards.
        Rental_Data_Sync_Session::generation_increment();

        Rental_Data_Sync_Session::update( $sync_id, [
            'status'      => Rental_Data_Sync_Status::STATUS_CANCELED,
            'canceled_at' => current_time( 'mysql' ),
        ] );
        Rental_Data_Sync_Session::release_lock( $sync_id );

        self::drop_chain_for( $sync_id );

        self::notify_remote_cancel( $sync_id );

        update_option( 'rental_synchronize_status', 0 );

        Rental_Data_Sync_Logger::write( 'Data sync canceled by admin', 'warning', 'schedule' );

        Rental_Data_Sync_Run_Repository::finish(
            $sync_id,
            Rental_Data_Sync_Status::STATUS_CANCELED,
            __( 'Canceled by an administrator.', 'rentopian-sync' ),
            [
                'phase'           => (int) ( $session['phase'] ?? 0 ),
                'processed_count' => (int) ( $session['processed_count'] ?? 0 ),
            ]
        );

        // The staging files only serve an in-flight run.
        Rental_Data_Sync_Session::cleanup_staging( $sync_id );

        $message = __( 'Synchronization canceled.', 'rentopian-sync' );
        $files   = [ 'canceled' => false, 'sync_id' => '' ];

        if ( $cancel_files ) {
            $files = self::cancel_file_sync();
            if ( ! empty( $files['canceled'] ) ) {
                $message = __( 'The data sync and the chained file sync were canceled.', 'rentopian-sync' );
            }
        }

        return [
            'success'      => true,
            'message'      => $message,
            'sync_id'      => $sync_id,
            'file_sync_id' => $files['sync_id'],
        ];
    }

    /**
     * Cancel an active background FILE sync, reusing the file-sync module's
     * own session manager and the same remote `sync/cancel` call its admin
     * action performs. The file-sync module itself is not modified.
     *
     * @return array { canceled, sync_id }
     */
    private static function cancel_file_sync() {
        $file_sync_id = get_option( 'rental_current_sync_id', '' );
        if ( ! $file_sync_id || ! class_exists( 'Rental_Sync_Scheduler' ) ) {
            return [ 'canceled' => false, 'sync_id' => '' ];
        }

        $session = Rental_Sync_Session_Manager::get( $file_sync_id );
        $status  = (int) ( $session['status'] ?? 0 );

        // Only intervene while it is still live.
        if ( ! in_array( $status, [ Rental_Sync_Status::STATUS_CREATED, Rental_Sync_Status::STATUS_PROCESSING ], true ) ) {
            return [ 'canceled' => false, 'sync_id' => $file_sync_id ];
        }

        // The file module owns how a file sync stops; this only decides that
        // one should. Two implementations of "stop" is how they drift apart.
        $result = Rental_Sync_Scheduler::cancel( $file_sync_id );

        Rental_Data_Sync_Logger::write( "Chained file sync canceled: {$file_sync_id}", 'warning', 'chunk' );

        return [ 'canceled' => ! empty( $result['success'] ), 'sync_id' => $file_sync_id ];
    }

    /**
     * Forget a file-sync chain belonging to one run, whether it was waiting
     * to fire or waiting to prove it started.
     *
     * @param string $sync_id
     */
    private static function drop_chain_for( $sync_id ) {
        foreach ( [ self::OPTION_PENDING_CHAIN, self::OPTION_CHAIN_WATCH ] as $option ) {
            $chain = get_option( $option, [] );
            if ( is_array( $chain ) && ( $chain['sync_id'] ?? '' ) === $sync_id ) {
                delete_option( $option );
            }
        }
    }

    /**
     * Stop everything, in both phases, and refuse more until the next start.
     *
     * The counterpart of the file module's "cancel all", extended over the
     * whole pipeline: one click has to reach the data run, the file run, the
     * chain waiting between them, and the Rentopian queue that drives all of
     * it. Anything left out is a way for work to resume after the admin was
     * told it had stopped.
     *
     * @param string $reason Recorded for the admin.
     * @return array
     */
    public static function cancel_all( $reason = '' ) {
        $reason = $reason ?: sprintf(
            /* translators: %s: local date and time */
            __( 'All synchronization stopped by an administrator at %s.', 'rentopian-sync' ),
            current_time( 'mysql' )
        );

        $generation = Rental_Data_Sync_Session::generation_increment();

        update_option( 'rental_data_sync_all_canceled', 1 );
        update_option( 'rental_data_sync_canceled_reason', $reason );

        // No chain may outlive the stop, whichever run set it up.
        delete_option( self::OPTION_PENDING_CHAIN );
        delete_option( self::OPTION_CHAIN_WATCH );
        delete_transient( 'rental_data_sync_chaining' );
        wp_clear_scheduled_hook( 'rental_data_sync_chain_files' );

        $sessions = Rental_Data_Sync_Session::get_all();
        $stopped  = 0;

        foreach ( $sessions as $sid => $session ) {
            $live = in_array(
                (int) ( $session['status'] ?? 0 ),
                [ Rental_Data_Sync_Status::STATUS_CREATED, Rental_Data_Sync_Status::STATUS_PROCESSING ],
                true
            );

            Rental_Data_Sync_Session::update( $sid, [
                'status'      => Rental_Data_Sync_Status::STATUS_CANCELED,
                'canceled_at' => current_time( 'mysql' ),
            ] );
            Rental_Data_Sync_Session::release_lock( $sid );
            Rental_Data_Sync_Session::cleanup_staging( $sid );

            // Only a run that was still going gets its record closed as
            // canceled; a finished one keeps the outcome it earned.
            if ( $live ) {
                $stopped++;
                Rental_Data_Sync_Run_Repository::finish(
                    $sid,
                    Rental_Data_Sync_Status::STATUS_CANCELED,
                    $reason,
                    [
                        'phase'           => (int) ( $session['phase'] ?? 0 ),
                        'processed_count' => (int) ( $session['processed_count'] ?? 0 ),
                    ]
                );
                self::notify_remote_cancel( $sid );
            }
        }

        update_option( 'rental_synchronize_status', 0 );

        Rental_Data_Sync_Logger::write( 'Stop all: ' . $reason, 'warning', 'schedule' );

        $files = class_exists( 'Rental_Sync_Scheduler' )
            ? Rental_Sync_Scheduler::cancel_all( $reason )
            : [ 'sessions_updated' => 0 ];

        return [
            'success'         => true,
            'message'         => __( 'All synchronization stopped. Starting a new one clears this.', 'rentopian-sync' ),
            'generation'      => $generation,
            'data_stopped'    => $stopped,
            'files_stopped'   => (int) ( $files['sessions_updated'] ?? 0 ),
            'reason'          => $reason,
        ];
    }

    /**
     * @param string $sync_id
     * @return bool Whether the Rentopian server acknowledged the cancel.
     */
    private static function notify_remote_cancel( $sync_id ) {
        try {
            rental_curl( 'sync/cancel', get_option( 'rental_api_key' ), true, [ 'sync_id' => $sync_id ], null, false, self::START_TIMEOUT );
            return true;
        } catch ( Throwable $e ) {
            Rental_Data_Sync_Logger::write( "sync/cancel notify failed for {$sync_id}: " . $e->getMessage(), 'warning', 'rest' );
            return false;
        }
    }

    /**
     * Queue the file phase to start as soon as it safely can, and record
     * what the file sync has to beat to be considered started.
     *
     * Called at the end of the data phase, from inside the request the
     * Laravel chunk job is still waiting on — which is exactly why the start
     * itself is deferred rather than done here.
     *
     * @param string $sync_id
     */
    public static function queue_file_chain( $sync_id ) {
        update_option( self::OPTION_PENDING_CHAIN, [
            'sync_id'    => (string) $sync_id,
            'not_before' => time() + self::CHAIN_DELAY,
            'attempts'   => 0,
        ], false );

        self::schedule_chain_event();
    }

    /**
     * Book the cron trigger for the file chain.
     *
     * Any earlier booking is cleared first. A site whose cron never runs
     * accumulates events that stay due forever, and WordPress refuses to
     * schedule an event that duplicates one within ten minutes — so without
     * this, a stale leftover would silently block the new run's trigger.
     */
    private static function schedule_chain_event() {
        wp_clear_scheduled_hook( 'rental_data_sync_chain_files' );
        wp_schedule_single_event( time() + self::CHAIN_DELAY, 'rental_data_sync_chain_files' );
    }

    /**
     * Move the file chain forward: start it when it is due, and restart it
     * when a chained sync never came to life.
     *
     * Called from cron, from `init`, from the watchdog and from the admin
     * status poll — whichever happens first wins, and the rest return
     * immediately. That is what keeps the gap between the two phases at the
     * deferral and no longer, on sites whose cron does not run.
     */
    public static function advance_chain() {
        self::maybe_chain_file_sync();
        self::verify_chain();
    }

    /**
     * Whether a chain is waiting and its deferral has elapsed.
     *
     * @return bool
     */
    public static function chain_is_due() {
        $pending = get_option( self::OPTION_PENDING_CHAIN, [] );

        return is_array( $pending )
            && ! empty( $pending['sync_id'] )
            && time() >= (int) ( $pending['not_before'] ?? 0 );
    }

    /**
     * Chain the background FILE sync after a completed data run.
     * Deferred (cron single event + init fallback) so it never runs while
     * the Laravel data chunk job still holds the per-company overlap lock.
     */
    public static function maybe_chain_file_sync() {
        $pending = get_option( self::OPTION_PENDING_CHAIN, [] );
        if ( ! is_array( $pending ) || empty( $pending['sync_id'] ) ) {
            return;
        }

        if ( time() < (int) ( $pending['not_before'] ?? 0 ) ) {
            return;
        }

        // Re-entrancy guard across cron + init fallback.
        if ( get_transient( 'rental_data_sync_chaining' ) ) {
            return;
        }
        set_transient( 'rental_data_sync_chaining', 1, 120 );

        $sync_id  = $pending['sync_id'];
        $attempts = (int) ( $pending['attempts'] ?? 0 );
        $session  = Rental_Data_Sync_Session::get( $sync_id );

        if ( ! $session || (int) ( $session['status'] ?? 0 ) !== Rental_Data_Sync_Status::STATUS_COMPLETED ) {
            delete_option( self::OPTION_PENDING_CHAIN );
            delete_transient( 'rental_data_sync_chaining' );
            return;
        }

        delete_option( self::OPTION_PENDING_CHAIN );

        Rental_Data_Sync_Logger::bind_run( $sync_id );

        // Between the phases is the only safe moment to clear image
        // references that no longer resolve: the catalog is settled, and
        // the file phase that follows re-downloads whatever this frees up.
        if ( class_exists( 'Rental_Image_Integrity' ) ) {
            $repair = Rental_Image_Integrity::repair();
            if ( $repair['total'] > 0 ) {
                Rental_Data_Sync_Logger::write(
                    sprintf(
                        'Cleared unresolvable image references before the file phase: %d thumbnails, %d galleries, %d orphan meta rows, %d relations, %d term images',
                        $repair['thumbnails_cleared'],
                        $repair['galleries_pruned'],
                        $repair['orphan_meta_deleted'],
                        $repair['relations_pruned'],
                        $repair['terms_cleared']
                    ),
                    'notice',
                    'integrity'
                );
            }
        }

        $result = Rental_Sync_Scheduler::schedule( Rental_Sync_Status::MODE_SYNC );

        Rental_Data_Sync_Run_Repository::set_file_sync( $sync_id, $result['sync_id'] ?? '' );

        $run = get_option( 'rental_data_sync_last_run', [] );
        if ( is_array( $run ) && ( $run['sync_id'] ?? '' ) === $sync_id ) {
            $run['file_sync_id']    = $result['sync_id'] ?? '';
            $run['file_chained_at'] = current_time( 'mysql' );
            $run['file_chain_ok']   = ! empty( $result['success'] );
            update_option( 'rental_data_sync_last_run', $run, false );
        }

        $attempts++;

        if ( empty( $result['success'] ) ) {
            self::chain_retry_or_fail( $sync_id, $attempts, (string) ( $result['message'] ?? 'unknown' ) );
        } else {
            Rental_Data_Sync_Logger::write(
                sprintf(
                    'File sync chained: %s (after data sync %s, attempt %d)',
                    $result['sync_id'] ?? '',
                    $sync_id,
                    $attempts
                ),
                'info',
                'chain'
            );

            // A dispatched job that the overlap lock discards leaves no
            // trace anywhere, so the only proof the file phase really began
            // is the file sync itself reporting progress.
            update_option( self::OPTION_CHAIN_WATCH, [
                'sync_id'      => $sync_id,
                'file_sync_id' => (string) ( $result['sync_id'] ?? '' ),
                'chained_at'   => time(),
                'attempts'     => $attempts,
            ], false );
        }

        delete_transient( 'rental_data_sync_chaining' );
    }

    /**
     * Re-chain a file sync that never started, or report it once the
     * attempts are spent.
     */
    public static function verify_chain() {
        $watch = get_option( self::OPTION_CHAIN_WATCH, [] );
        if ( ! is_array( $watch ) || empty( $watch['sync_id'] ) ) {
            return;
        }

        if ( self::file_sync_started( $watch['file_sync_id'] ) ) {
            delete_option( self::OPTION_CHAIN_WATCH );
            return;
        }

        if ( time() - (int) ( $watch['chained_at'] ?? 0 ) < self::CHAIN_VERIFY_AFTER ) {
            return;
        }

        delete_option( self::OPTION_CHAIN_WATCH );

        Rental_Data_Sync_Logger::bind_run( $watch['sync_id'] );

        self::chain_retry_or_fail(
            $watch['sync_id'],
            (int) ( $watch['attempts'] ?? 1 ),
            sprintf(
                'file sync %s showed no activity in %ds',
                $watch['file_sync_id'],
                self::CHAIN_VERIFY_AFTER
            )
        );
    }

    /**
     * Whether a chained file sync has shown any sign of life: real progress,
     * or a terminal state it reached on its own.
     *
     * @param string $file_sync_id
     * @return bool True also when it cannot be judged, so nothing is
     *              restarted on a guess.
     */
    private static function file_sync_started( $file_sync_id ) {
        if ( ! $file_sync_id || ! class_exists( 'Rental_Sync_Session_Manager' ) ) {
            return true;
        }

        $session = Rental_Sync_Session_Manager::get( $file_sync_id );
        if ( ! $session ) {
            return false;
        }

        $status = (int) ( $session['status'] ?? 0 );

        if ( in_array(
            $status,
            [ Rental_Sync_Status::STATUS_COMPLETED, Rental_Sync_Status::STATUS_FAILED, Rental_Sync_Status::STATUS_CANCELED ],
            true
        ) ) {
            return true;
        }

        return (int) ( $session['processed_count'] ?? 0 ) > 0
            || $status === Rental_Sync_Status::STATUS_PROCESSING;
    }

    /**
     * Try the chain again, or report it once the attempts are spent.
     *
     * @param string $sync_id
     * @param int    $attempts Attempts already made.
     * @param string $reason
     */
    private static function chain_retry_or_fail( $sync_id, $attempts, $reason ) {
        if ( $attempts < self::CHAIN_MAX_ATTEMPTS ) {
            Rental_Data_Sync_Logger::write(
                sprintf( 'File sync chain attempt %d did not take (%s) — retrying', $attempts, $reason ),
                'warning',
                'chain'
            );

            update_option( self::OPTION_PENDING_CHAIN, [
                'sync_id'    => $sync_id,
                'not_before' => time() + self::CHAIN_DELAY,
                'attempts'   => $attempts,
            ], false );

            self::schedule_chain_event();

            return;
        }

        self::chain_failed( $sync_id, $attempts, $reason );
    }

    /**
     * Record and report a chain that could not be established.
     *
     * @param string $sync_id
     * @param int    $attempts
     * @param string $reason
     */
    private static function chain_failed( $sync_id, $attempts, $reason ) {
        Rental_Data_Sync_Logger::write(
            sprintf( 'File sync chain failed after %d attempt(s): %s', $attempts, $reason ),
            'error',
            'chain'
        );

        $run = get_option( 'rental_data_sync_last_run', [] );
        if ( is_array( $run ) && ( $run['sync_id'] ?? '' ) === $sync_id ) {
            $run['file_chain_ok'] = 0;
            update_option( 'rental_data_sync_last_run', $run, false );
        }

        Rental_Data_Sync_Reporter::send_failure_report(
            $sync_id,
            'Data sync completed but the file sync could not be started: ' . $reason,
            Rental_Data_Sync_Status::PHASE_FINALIZE
        );
    }
}
