<?php
/**
 * Data Sync AJAX Controller
 *
 * Admin AJAX endpoints for the background data sync: start, cancel, status
 * polling, and the run history panel (tail, download and delete a run's
 * log).
 *
 * @package RentopianSync\DataSync
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Rental_Data_Sync_Ajax_Controller {

    /**
     * Nonce action shared by every endpoint here.
     */
    const NONCE_ACTION = 'rental_data_sync_admin';

    /**
     * Lines of a run log the panel renders inline. Anything longer is
     * served by the download endpoint instead of being pushed through the
     * admin page.
     */
    const TAIL_LINES = 200;

    /**
     * How long a file-sync session may go without logging any activity
     * before the panel stops treating it as live. Matches the file-sync
     * module's own stuck-session threshold.
     */
    const FILE_SYNC_STALE_AFTER = 1200; // 20 minutes

    public function register_hooks() {
        add_action( 'wp_ajax_rental_data_sync_start', [ $this, 'start' ] );
        add_action( 'wp_ajax_rental_data_sync_cancel', [ $this, 'cancel' ] );
        add_action( 'wp_ajax_rental_data_sync_cancel_all', [ $this, 'cancel_all' ] );
        add_action( 'wp_ajax_rental_data_sync_status', [ $this, 'status' ] );
        add_action( 'wp_ajax_rental_data_sync_runs', [ $this, 'runs' ] );
        add_action( 'wp_ajax_rental_data_sync_run_log', [ $this, 'run_log' ] );
        add_action( 'wp_ajax_rental_data_sync_download_log', [ $this, 'download_log' ] );
        add_action( 'wp_ajax_rental_data_sync_delete_run', [ $this, 'delete_run' ] );
        add_action( 'wp_ajax_rental_data_sync_save_report_email', [ $this, 'save_report_email' ] );
    }

    /**
     * Reject non-admin callers, and cross-site requests on anything that
     * changes state.
     *
     * @param bool $check_nonce
     */
    private function guard( $check_nonce = true ) {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json( [ 'success' => false, 'message' => __( 'Insufficient permissions.', 'rentopian-sync' ) ], 403 );
        }

        if ( $check_nonce && ! check_ajax_referer( self::NONCE_ACTION, 'nonce', false ) ) {
            wp_send_json( [ 'success' => false, 'message' => __( 'The page session expired. Reload the settings page and try again.', 'rentopian-sync' ) ], 403 );
        }
    }

    /* ──────────────────────────────────────────────────────────
     * Controls
     * ────────────────────────────────────────────────────────── */

    public function start() {
        $this->guard();

        $api_key = isset( $_POST['api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) : '';
        if ( ! $api_key ) {
            $api_key = (string) get_option( 'rental_api_key', '' );
        }

        // One run at a time on the WP side too — unless the one on record
        // was abandoned, in which case the watchdog closes it first.
        $current = get_option( 'rental_data_sync_current_id', '' );
        if ( $current ) {
            Rental_Data_Sync_Watchdog::enforce( $current );

            $session = Rental_Data_Sync_Session::get( $current );
            $status  = (int) ( $session['status'] ?? 0 );

            if ( in_array( $status, [ Rental_Data_Sync_Status::STATUS_CREATED, Rental_Data_Sync_Status::STATUS_PROCESSING ], true ) ) {
                wp_send_json( [
                    'success' => false,
                    'message' => __( 'A synchronization is already running. Stop it before starting another.', 'rentopian-sync' ),
                    'reason'  => 'already_running',
                    'sync_id' => $current,
                ], 409 );
            }
        }

        $result = Rental_Data_Sync_Scheduler::schedule( $api_key );

        wp_send_json( $result, empty( $result['success'] ) ? 400 : 200 );
    }

    public function cancel() {
        $this->guard();

        $sync_id = isset( $_POST['sync_id'] ) ? sanitize_text_field( wp_unslash( $_POST['sync_id'] ) ) : '';
        $result  = Rental_Data_Sync_Scheduler::cancel( $sync_id );

        wp_send_json( $result, empty( $result['success'] ) ? 400 : 200 );
    }

    /**
     * Stop every run in both phases and refuse more until the next start.
     */
    public function cancel_all() {
        $this->guard();

        wp_send_json( Rental_Data_Sync_Scheduler::cancel_all(), 200 );
    }

    /**
     * Save who receives the run report: an administrator picked from the
     * list, an address typed in beside it, or neither.
     *
     * An address that is not an address is refused rather than stored and
     * silently ignored at send time.
     */
    public function save_report_email() {
        $this->guard();

        $user_id = isset( $_POST['user_id'] ) ? max( 0, (int) $_POST['user_id'] ) : 0;
        $extra   = isset( $_POST['extra'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['extra'] ) ) ) : '';

        if ( $user_id > 0 ) {
            $user = get_userdata( $user_id );
            if ( ! $user || ! user_can( $user, 'manage_options' ) ) {
                wp_send_json( [
                    'success' => false,
                    'message' => __( 'That user cannot be a report recipient.', 'rentopian-sync' ),
                ], 400 );
            }
        }

        $clean = [];
        foreach ( preg_split( '/[,;\s]+/', $extra, -1, PREG_SPLIT_NO_EMPTY ) as $typed ) {
            $email = sanitize_email( $typed );
            if ( ! $email || ! is_email( $email ) ) {
                wp_send_json( [
                    'success' => false,
                    /* translators: %s: the address as it was typed */
                    'message' => sprintf( __( '"%s" is not a valid email address.', 'rentopian-sync' ), $typed ),
                ], 400 );
            }
            $clean[] = $email;
        }

        update_option( Rental_Data_Sync_Reporter::OPTION_REPORT_USER, $user_id, false );
        update_option( Rental_Data_Sync_Reporter::OPTION_RECIPIENTS, implode( ', ', $clean ), false );

        $recipients = Rental_Data_Sync_Reporter::recipients();

        wp_send_json( [
            'success'    => true,
            'recipients' => $recipients,
            'message'    => $recipients
                ? sprintf(
                    /* translators: %s: comma-separated email addresses */
                    __( 'Run reports will be emailed to %s.', 'rentopian-sync' ),
                    implode( ', ', $recipients )
                )
                : __( 'No recipient set — run reports will not be emailed.', 'rentopian-sync' ),
        ], 200 );
    }

    /* ──────────────────────────────────────────────────────────
     * Status
     * ────────────────────────────────────────────────────────── */

    /**
     * Full pipeline snapshot driving the admin panel: data phase, chained
     * file phase, the last recorded outcome, and — when the run is not
     * behaving — a named diagnosis. Always answers 200 with a coherent
     * state, including "never run".
     */
    public function status() {
        $this->guard( false );

        $sync_id = isset( $_GET['sync_id'] ) ? sanitize_text_field( wp_unslash( $_GET['sync_id'] ) ) : '';
        $sync_id = $sync_id ?: get_option( 'rental_data_sync_current_id', '' );

        // Close out a run the driver abandoned before reporting on it. Both
        // phases are checked: the pipeline is only over when the file phase
        // is, and it can be abandoned on its own.
        Rental_Data_Sync_Watchdog::enforce( $sync_id );
        if ( class_exists( 'Rental_File_Sync_Watchdog' ) ) {
            Rental_File_Sync_Watchdog::enforce( (string) get_option( 'rental_current_sync_id', '' ) );
        }

        // The panel polls every couple of seconds while a run is live, which
        // makes it the promptest trigger there is for the file chain — and
        // the only one that works at all when cron cannot run.
        Rental_Data_Sync_Scheduler::advance_chain();

        $session      = $sync_id ? Rental_Data_Sync_Session::get( $sync_id ) : null;
        $last_success = Rental_Data_Sync_Scheduler::last_success();
        $files        = $this->file_sync_state( $sync_id );

        $payload = [
            'success'              => true,
            'has_previous_success' => (bool) $last_success,
            'last_success'         => $last_success,
            'last_run'             => Rental_Data_Sync_Scheduler::last_run(),
            'button_label'         => $last_success
                ? __( 'Resynchronize Now', 'rentopian-sync' )
                : __( 'Start Synchronization', 'rentopian-sync' ),
            'data'                 => null,
            'files'                => $files,
            'diagnosis'            => null,
            'is_running'           => ! empty( $files['is_running'] ),
            'can_stop'             => $this->can_stop( null, $files ),
        ];

        if ( $session ) {
            $status      = (int) ( $session['status'] ?? 0 );
            $phase_steps = (array) ( $session['phase_steps'] ?? [] );

            // The per-run cursor option is deleted once the report is sent,
            // so the session's own phase is the durable one.
            $phase = max(
                (int) ( $session['phase'] ?? 0 ),
                Rental_Data_Sync_Status::cursor_phase( Rental_Data_Sync_Session::get_stored_cursor( $sync_id ) )
            );

            $data_running = in_array(
                $status,
                [ Rental_Data_Sync_Status::STATUS_CREATED, Rental_Data_Sync_Status::STATUS_PROCESSING ],
                true
            );

            $percent = 0;
            if ( Rental_Data_Sync_Status::STATUS_COMPLETED === $status ) {
                $phase   = Rental_Data_Sync_Status::PHASE_FINALIZE;
                $percent = 100;
            } else {
                $percent = Rental_Data_Sync_Status::progress_percent( $phase, (int) ( $phase_steps[ $phase ] ?? 0 ) );
            }

            $run   = get_option( 'rental_data_sync_last_run', [] );
            $chain = ( is_array( $run ) && ( $run['sync_id'] ?? '' ) === $sync_id ) ? [
                'file_sync_id'      => $run['file_sync_id'] ?? '',
                'file_chain_ok'     => $run['file_chain_ok'] ?? null,
                'reported_at'       => $run['reported_at'] ?? '',
                'report_sent'       => ! empty( $run['report_sent'] ),
                'report_recipients' => (int) ( $run['report_recipients'] ?? 0 ),
            ] : null;

            $payload['data'] = [
                'sync_id'         => $sync_id,
                'status'          => $status,
                'status_label'    => Rental_Data_Sync_Run_Repository::status_label( $status ),
                'is_running'      => $data_running,
                'phase'           => $phase,
                'phase_label'     => Rental_Data_Sync_Status::phase_label( $phase ),
                'phase_total'     => Rental_Data_Sync_Status::PHASE_FINALIZE + 1,
                'percent'         => $percent,
                'processed_count' => (int) ( $session['processed_count'] ?? 0 ),
                'last_error'      => (string) ( $session['last_error'] ?? '' ),
                'started_at'      => (string) ( $session['started_at'] ?? '' ),
                'updated_at'      => (string) ( $session['updated_at'] ?? '' ),
                'idle_seconds'    => Rental_Data_Sync_Watchdog::idle_seconds( $session ),
                'stats'           => Rental_Data_Sync_Session::get_stats( $sync_id ),
                'file_chain'      => $chain,
            ];

            // The file phase is chained on a delay, so between the data
            // phase finishing and that chain firing nothing reports as
            // running. Treating the pipeline as idle there stops the poll
            // for good, and the Files row stays on "waiting" even though
            // the file sync goes on to run and finish.
            $chain_pending = $this->chain_pending( $sync_id, $session, $files );

            $payload['data']['chain_pending'] = $chain_pending;
            $payload['diagnosis']             = Rental_Data_Sync_Watchdog::diagnose( $session );
            $payload['is_running']            = $data_running || ! empty( $files['is_running'] ) || $chain_pending;
            $payload['can_stop']              = $this->can_stop( $session, $files ) || $chain_pending;
        }

        wp_send_json( $payload, 200 );
    }

    /**
     * Whether anything is left to stop.
     *
     * Deliberately not the same question as "is it running". A phase whose
     * driver went quiet still holds an open session, still blocks the next
     * run, and is exactly when an admin reaches for Stop — so liveness is
     * taken from the status alone here, with no staleness test.
     *
     * @param array|null $session Data-phase session, when there is one.
     * @param array      $files   File-phase snapshot.
     * @return bool
     */
    private function can_stop( $session, array $files ) {
        $open = static function ( $status, $created, $processing ) {
            return in_array( (int) $status, [ $created, $processing ], true );
        };

        if ( is_array( $session ) && $open(
            $session['status'] ?? 0,
            Rental_Data_Sync_Status::STATUS_CREATED,
            Rental_Data_Sync_Status::STATUS_PROCESSING
        ) ) {
            return true;
        }

        return class_exists( 'Rental_Sync_Status' ) && $open(
            $files['status'] ?? 0,
            Rental_Sync_Status::STATUS_CREATED,
            Rental_Sync_Status::STATUS_PROCESSING
        );
    }

    /**
     * Read-only snapshot of the background FILE sync (its module owns the
     * state; this only reports it so one panel shows the whole pipeline).
     *
     * A session abandoned by the Rentopian driver stays CREATED/PROCESSING
     * forever, which would leave the Start button permanently disabled, so
     * liveness is taken from the last activity logged for that run rather
     * than from the status alone.
     *
     * @param string $data_sync_id The run this panel is reporting on.
     * @return array
     */
    private function file_sync_state( $data_sync_id = '' ) {
        $idle = [ 'sync_id' => '', 'is_running' => false, 'is_stale' => false, 'percent' => 0 ];

        $file_sync_id = (string) get_option( 'rental_current_sync_id', '' );
        if ( ! $file_sync_id || ! class_exists( 'Rental_Sync_Session_Manager' ) ) {
            return $idle;
        }

        // The file-sync id on record belongs to whichever run chained it
        // last. Reporting it against a newer data run would show that run
        // starting with a finished Files bar, so it is only shown once
        // this run has actually chained it.
        if ( $data_sync_id && ! $this->file_sync_belongs_to( $data_sync_id, $file_sync_id ) ) {
            return $idle;
        }

        $session = Rental_Sync_Session_Manager::get( $file_sync_id );
        if ( ! $session ) {
            return [ 'sync_id' => $file_sync_id, 'is_running' => false, 'is_stale' => false, 'percent' => 0 ];
        }

        $status = (int) ( $session['status'] ?? 0 );
        $total  = (int) ( $session['total_count'] ?? 0 );
        $done   = (int) ( $session['processed_count'] ?? 0 );
        $active = in_array( $status, [ Rental_Sync_Status::STATUS_CREATED, Rental_Sync_Status::STATUS_PROCESSING ], true );

        $idle_for = $active ? $this->file_sync_idle_seconds( $file_sync_id, $session ) : 0;
        $stale    = $active && $idle_for > self::FILE_SYNC_STALE_AFTER;

        if ( Rental_Sync_Status::STATUS_COMPLETED === $status ) {
            $percent = 100;
        } elseif ( $total > 0 ) {
            $percent = min( 99, (int) round( ( $done / $total ) * 100 ) );
        } else {
            // Queued but not counted yet — show motion rather than an
            // empty bar, so the admin can tell the phase has begun.
            $percent = $active ? 2 : 0;
        }

        return [
            'sync_id'         => $file_sync_id,
            'status'          => $status,
            'is_running'      => $active && ! $stale,
            'is_stale'        => $stale,
            'idle_seconds'    => $idle_for,
            'processed_count' => $done,
            'total_count'     => $total,
            'percent'         => $percent,
        ];
    }

    /**
     * Whether the run finished its data phase but the file phase has not
     * finished yet — including the window before the deferred chain fires,
     * when no session reports as running at all.
     *
     * @param string $sync_id
     * @param array  $session Data-sync session.
     * @param array  $files   file_sync_state() snapshot.
     * @return bool
     */
    private function chain_pending( $sync_id, array $session, array $files ) {
        if ( (int) ( $session['status'] ?? 0 ) !== Rental_Data_Sync_Status::STATUS_COMPLETED ) {
            return false;
        }

        // The file phase already finished — the pipeline is done whether or
        // not the report has gone out yet.
        if ( class_exists( 'Rental_Sync_Status' )
            && (int) ( $files['status'] ?? 0 ) === Rental_Sync_Status::STATUS_COMPLETED ) {
            return false;
        }

        $run = get_option( 'rental_data_sync_last_run', [] );
        if ( ! is_array( $run ) || ( $run['sync_id'] ?? '' ) !== $sync_id ) {
            return false;
        }

        // Report sent: the whole pipeline is over.
        if ( ! empty( $run['reported_at'] ) ) {
            return false;
        }

        // The chain was attempted and refused, so no file phase is coming.
        if ( array_key_exists( 'file_chain_ok', $run ) && empty( $run['file_chain_ok'] ) ) {
            return false;
        }

        return true;
    }

    /**
     * Whether a file sync was chained by the given data run.
     *
     * @param string $data_sync_id
     * @param string $file_sync_id
     * @return bool
     */
    private function file_sync_belongs_to( $data_sync_id, $file_sync_id ) {
        $run = Rental_Data_Sync_Run_Repository::get( $data_sync_id );
        if ( $run && ! empty( $run['file_sync_id'] ) ) {
            return $run['file_sync_id'] === $file_sync_id;
        }

        $last = get_option( 'rental_data_sync_last_run', [] );
        if ( is_array( $last ) && ( $last['sync_id'] ?? '' ) === $data_sync_id ) {
            return ( $last['file_sync_id'] ?? '' ) === $file_sync_id;
        }

        return false;
    }

    /**
     * Seconds since the file sync last recorded activity.
     *
     * @param string $file_sync_id
     * @param array  $session
     * @return int
     */
    private function file_sync_idle_seconds( $file_sync_id, array $session ) {
        return class_exists( 'Rental_File_Sync_Watchdog' )
            ? Rental_File_Sync_Watchdog::idle_seconds( $file_sync_id, $session )
            : 0;
    }

    /* ──────────────────────────────────────────────────────────
     * Run history
     * ────────────────────────────────────────────────────────── */

    public function runs() {
        $this->guard( false );

        $page     = isset( $_GET['page'] ) ? max( 1, (int) $_GET['page'] ) : 1;
        $per_page = isset( $_GET['per_page'] ) ? max( 1, min( 50, (int) $_GET['per_page'] ) ) : 10;

        wp_send_json( [
            'success'  => true,
            'runs'     => Rental_Data_Sync_Run_Repository::get_page( $page, $per_page ),
            'total'    => Rental_Data_Sync_Run_Repository::count(),
            'page'     => $page,
            'per_page' => $per_page,
        ], 200 );
    }

    /**
     * Tail of one run's log file — the same lines written during the run,
     * capped so a long run never bloats the settings page.
     */
    public function run_log() {
        $this->guard( false );

        $sync_id = isset( $_GET['sync_id'] ) ? sanitize_text_field( wp_unslash( $_GET['sync_id'] ) ) : '';
        if ( ! $sync_id ) {
            wp_send_json( [ 'success' => false, 'message' => __( 'Missing run id.', 'rentopian-sync' ) ], 400 );
        }

        $lines = isset( $_GET['lines'] ) ? max( 20, min( 1000, (int) $_GET['lines'] ) ) : self::TAIL_LINES;
        $tail  = Rental_Data_Sync_Logger::tail( $sync_id, $lines );

        wp_send_json( [
            'success'      => true,
            'sync_id'      => $sync_id,
            'entries'      => array_map( [ $this, 'parse_log_line' ], $tail['lines'] ),
            'truncated'    => $tail['truncated'],
            'total_bytes'  => $tail['total_bytes'],
            'has_log'      => $tail['total_bytes'] > 0,
            'download_url' => $this->download_url( $sync_id ),
        ], 200 );
    }

    /**
     * Stream one run's log file as a download.
     */
    public function download_log() {
        $this->guard();

        $sync_id = isset( $_GET['sync_id'] ) ? sanitize_text_field( wp_unslash( $_GET['sync_id'] ) ) : '';
        $path    = $sync_id ? Rental_Data_Sync_Logger::run_log_path( $sync_id, false ) : '';

        if ( ! $path || ! is_file( $path ) ) {
            wp_die( esc_html__( 'No log file exists for that run.', 'rentopian-sync' ), '', [ 'response' => 404 ] );
        }

        nocache_headers();
        header( 'Content-Type: text/plain; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="data-sync-' . sanitize_file_name( $sync_id ) . '.log"' );
        header( 'Content-Length: ' . filesize( $path ) );

        readfile( $path );
        exit;
    }

    /**
     * Delete one run: its history row and its log file. A run still in
     * flight must be stopped first, so a live log is never pulled out from
     * under the worker.
     */
    public function delete_run() {
        $this->guard();

        $sync_id = isset( $_POST['sync_id'] ) ? sanitize_text_field( wp_unslash( $_POST['sync_id'] ) ) : '';
        if ( ! $sync_id ) {
            wp_send_json( [ 'success' => false, 'message' => __( 'Missing run id.', 'rentopian-sync' ) ], 400 );
        }

        $run = Rental_Data_Sync_Run_Repository::get( $sync_id );
        if ( $run && $run['is_running'] ) {
            wp_send_json( [
                'success' => false,
                'message' => __( 'That run is still in progress. Stop it before deleting its log.', 'rentopian-sync' ),
            ], 409 );
        }

        Rental_Data_Sync_Run_Repository::delete( $sync_id );
        Rental_Data_Sync_Session::remove( $sync_id );

        wp_send_json( [
            'success' => true,
            'message' => __( 'Run deleted.', 'rentopian-sync' ),
            'sync_id' => $sync_id,
        ], 200 );
    }

    /* ──────────────────────────────────────────────────────────
     * Internals
     * ────────────────────────────────────────────────────────── */

    /**
     * Split a stored log line into the parts the panel renders.
     *
     * Lines are written as `<ISO timestamp> [level] [channel] message`.
     *
     * @param string $line
     * @return array { time, level, channel, message }
     */
    private function parse_log_line( $line ) {
        $entry = [ 'time' => 0, 'level' => 'info', 'channel' => '', 'message' => $line ];

        if ( ! preg_match( '/^(\S+)\s+\[([a-z]+)\]\s*(.*)$/i', $line, $m ) ) {
            return $entry;
        }

        $entry['time']    = (int) strtotime( $m[1] );
        $entry['level']   = strtolower( $m[2] );
        $entry['message'] = $m[3];

        if ( preg_match( '/^\[([a-z0-9_-]+)\]\s*(.*)$/i', $entry['message'], $c ) ) {
            $entry['channel'] = $c[1];
            $entry['message'] = $c[2];
        }

        return $entry;
    }

    /**
     * @param string $sync_id
     * @return string
     */
    private function download_url( $sync_id ) {
        return add_query_arg( [
            'action'  => 'rental_data_sync_download_log',
            'sync_id' => rawurlencode( $sync_id ),
            'nonce'   => wp_create_nonce( self::NONCE_ACTION ),
        ], admin_url( 'admin-ajax.php' ) );
    }
}
