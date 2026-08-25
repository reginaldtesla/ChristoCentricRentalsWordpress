<?php
/**
 * Data Sync Watchdog
 *
 * Turns "nothing is happening" into a named cause.
 *
 * The run loop is driven from the Rentopian server: WordPress asks it to
 * start, and it calls back once per chunk. Every way that loop can break —
 * a queue worker that is not consuming, a callback URL the server cannot
 * reach, a REST route that answers with something other than the chunk
 * endpoint — looks identical from the settings page unless it is diagnosed
 * explicitly.
 *
 * Two jobs:
 *
 *   - `preflight()` runs before the start request and refuses to start (or
 *     warns) when the callback path is provably broken.
 *   - `diagnose()` / `enforce()` watch the heartbeat afterwards, name the
 *     failure for the admin, and fail the run instead of leaving it
 *     "created" forever.
 *
 * @package RentopianSync\DataSync
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Rental_Data_Sync_Watchdog {

    /**
     * Seconds a freshly started run may wait for its first callback before
     * the queue is reported as not delivering.
     */
    const STARTUP_GRACE = 120;

    /**
     * Seconds without a callback, mid-run, before the run is reported stalled.
     */
    const STALL_AFTER = 420;

    /**
     * Seconds without any callback before the run is failed outright, so the
     * controls unlock and the outcome is recorded.
     */
    const FAIL_AFTER = 1800;

    /* ──────────────────────────────────────────────────────────
     * Preflight
     * ────────────────────────────────────────────────────────── */

    /**
     * Check everything the run depends on before asking Rentopian to start.
     *
     * @param string $api_key
     * @return array { ok: bool, blockers: array[], warnings: array[], callback_url: string }
     */
    public static function preflight( $api_key ) {
        $callback_url = get_rest_url( null, '/rentopian-sync/v1/data-sync/process-chunk' );

        $blockers = [];
        $warnings = [];

        if ( ! $api_key ) {
            $blockers[] = self::issue(
                'missing_api_key',
                __( 'The Rentopian API key is empty.', 'rentopian-sync' ),
                [ __( 'Enter the API key on this page and save the settings before synchronizing.', 'rentopian-sync' ) ]
            );
        }

        if ( ! class_exists( 'WooCommerce' ) ) {
            $blockers[] = self::issue(
                'woocommerce_inactive',
                __( 'WooCommerce is not active.', 'rentopian-sync' ),
                [ __( 'The sync writes products through WooCommerce. Activate WooCommerce and try again.', 'rentopian-sync' ) ]
            );
        }

        if ( ! Rental_Data_Sync_Run_Repository::is_installed() ) {
            $blockers[] = self::issue(
                'run_table_missing',
                __( 'The synchronization history table could not be created.', 'rentopian-sync' ),
                [ __( 'The database user needs CREATE TABLE permission. Check the site database credentials.', 'rentopian-sync' ) ]
            );
        }

        if ( '' === Rental_Data_Sync_Logger::logs_dir() ) {
            $warnings[] = self::issue(
                'log_dir_unwritable',
                __( 'The synchronization log directory is not writable, so per-run logs will be unavailable.', 'rentopian-sync' ),
                [ __( 'Make wp-content/uploads writable by the web server.', 'rentopian-sync' ) ]
            );
        }

        $route = self::probe_callback_route( $callback_url );
        if ( 'ok' !== $route['result'] ) {
            $issue = self::issue( 'callback_unreachable', $route['message'], $route['hints'] );
            if ( 'blocked' === $route['result'] ) {
                $blockers[] = $issue;
            } else {
                $warnings[] = $issue;
            }
        }

        $private = self::private_host_warning( $callback_url );
        if ( $private ) {
            $warnings[] = $private;
        }

        return [
            'ok'           => empty( $blockers ),
            'blockers'     => $blockers,
            'warnings'     => $warnings,
            'callback_url' => $callback_url,
        ];
    }

    /**
     * Call the chunk endpoint with a deliberately invalid token. A healthy
     * route answers 403 "Invalid token" — anything else means the URL the
     * Rentopian server was given does not resolve to this endpoint.
     *
     * @param string $callback_url
     * @return array { result: ok|blocked|warn, message: string, hints: string[] }
     */
    private static function probe_callback_route( $callback_url ) {
        // Escape hatch for hosts where the self-call is unreliable enough
        // that the probe would block a run that would otherwise work.
        if ( (int) get_option( 'rental_data_sync_skip_route_probe', 0 ) === 1 ) {
            return [ 'result' => 'ok', 'message' => '', 'hints' => [] ];
        }

        $response = wp_remote_post( $callback_url, [
            'timeout'   => 12,
            'sslverify' => false,
            'body'      => [
                'sync_id'     => 'preflight-probe',
                'start_index' => 0,
                'wp_token'    => 'preflight-probe',
            ],
        ] );

        if ( is_wp_error( $response ) ) {
            // A loopback request can fail on hosts that block self-calls
            // while the route itself is fine, so this only warns.
            return [
                'result'  => 'warn',
                'message' => sprintf(
                    /* translators: 1: callback URL, 2: transport error */
                    __( 'WordPress could not reach its own synchronization callback URL (%1$s): %2$s', 'rentopian-sync' ),
                    $callback_url,
                    $response->get_error_message()
                ),
                'hints'   => [
                    __( 'This is only a self-test — it can fail on hosts that block loopback requests.', 'rentopian-sync' ),
                    __( 'It matters because the Rentopian server calls this exact URL once per chunk. Confirm it is reachable from outside.', 'rentopian-sync' ),
                ],
            ];
        }

        $code = (int) wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( 403 === $code && is_array( $body ) && isset( $body['message'] ) ) {
            return [ 'result' => 'ok', 'message' => '', 'hints' => [] ];
        }

        return [
            'result'  => 'blocked',
            'message' => sprintf(
                /* translators: 1: callback URL, 2: HTTP status code */
                __( 'The synchronization callback URL (%1$s) did not answer as the chunk endpoint — it returned HTTP %2$d.', 'rentopian-sync' ),
                $callback_url,
                $code
            ),
            'hints'   => [
                __( 'The WordPress REST API must be reachable. A security plugin, a redirect, or "Disable REST API" settings will break the sync.', 'rentopian-sync' ),
                __( 'Open the URL in a browser: it should answer with a JSON error, not an HTML page.', 'rentopian-sync' ),
                __( 'Check that permalinks are not set to Plain.', 'rentopian-sync' ),
            ],
        ];
    }

    /**
     * The Rentopian server calls WordPress back over the public internet, so
     * a callback URL that only resolves on this machine or this LAN can
     * never be delivered — the classic reason a local or staging run starts
     * and then sits at "created" forever.
     *
     * Resolution is used rather than a name pattern, so container hostnames
     * (`wp.docker`) and private-IP vhosts are caught as well as `localhost`.
     *
     * @param string $callback_url
     * @return array|null
     */
    private static function private_host_warning( $callback_url ) {
        $host = wp_parse_url( $callback_url, PHP_URL_HOST );
        if ( ! $host ) {
            return null;
        }

        if ( self::resolves_publicly( $host ) ) {
            return null;
        }

        return self::issue(
            'private_callback_host',
            sprintf(
                /* translators: %s: callback host name */
                __( 'The callback URL host (%s) does not resolve to a public address, so the Rentopian server cannot reach it from the internet.', 'rentopian-sync' ),
                $host
            ),
            [
                __( 'On a local or staging site, expose it through a tunnel and set the WordPress Site Address to that public URL.', 'rentopian-sync' ),
                __( 'Until the Rentopian server can call back, the run will start but never progress past "created".', 'rentopian-sync' ),
                __( 'Ignore this if the site is reached through a proxy that resolves differently from the outside.', 'rentopian-sync' ),
            ],
            'warning'
        );
    }

    /**
     * @param string $host Hostname or IP literal.
     * @return bool TRUE when the host resolves to at least one public address.
     */
    private static function resolves_publicly( $host ) {
        $public = static function ( $ip ) {
            return (bool) filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
        };

        if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
            return $public( $host );
        }

        $addresses = function_exists( 'gethostbynamel' ) ? gethostbynamel( $host ) : false;
        if ( ! is_array( $addresses ) ) {
            return false;
        }

        foreach ( $addresses as $ip ) {
            if ( $public( $ip ) ) {
                return true;
            }
        }

        return false;
    }

    /* ──────────────────────────────────────────────────────────
     * Runtime diagnosis
     * ────────────────────────────────────────────────────────── */

    /**
     * Explain the current state of a run in the admin's terms.
     *
     * @param array $session
     * @return array|null { code, severity, message, hints[], idle_seconds }
     */
    public static function diagnose( array $session ) {
        $status = (int) ( $session['status'] ?? 0 );

        if ( Rental_Data_Sync_Status::STATUS_FAILED === $status ) {
            return self::issue(
                'failed',
                (string) ( $session['last_error'] ?? __( 'The synchronization failed.', 'rentopian-sync' ) ),
                [ __( 'The previous catalog is untouched — records are updated in place and never truncated.', 'rentopian-sync' ) ],
                'error'
            );
        }

        if ( ! in_array( $status, [ Rental_Data_Sync_Status::STATUS_CREATED, Rental_Data_Sync_Status::STATUS_PROCESSING ], true ) ) {
            return null;
        }

        $idle       = self::idle_seconds( $session );
        $had_chunk  = ! empty( $session['first_chunk_at'] );
        $callback   = (string) ( $session['callback_url'] ?? get_rest_url( null, '/rentopian-sync/v1/data-sync/process-chunk' ) );

        if ( ! $had_chunk ) {
            if ( $idle < self::STARTUP_GRACE ) {
                return self::issue(
                    'starting',
                    __( 'Waiting for the Rentopian server to pick up the run.', 'rentopian-sync' ),
                    [],
                    'info',
                    $idle
                );
            }

            return self::issue(
                'no_callback',
                sprintf(
                    /* translators: %s: human readable duration */
                    __( 'Rentopian accepted the request but has not called WordPress back in %s. No catalog data has been processed.', 'rentopian-sync' ),
                    self::humanize( $idle )
                ),
                [
                    __( 'Most common cause: no queue worker is consuming the "webhooks" queue on the Rentopian server (php artisan queue:work --queue=webhooks).', 'rentopian-sync' ),
                    sprintf(
                        /* translators: %s: callback URL */
                        __( 'Second cause: the Rentopian server cannot reach the callback URL %s.', 'rentopian-sync' ),
                        $callback
                    ),
                    __( 'Stop the run, fix the cause, then start it again — nothing has been written yet.', 'rentopian-sync' ),
                ],
                'error',
                $idle
            );
        }

        if ( $idle >= self::STALL_AFTER ) {
            return self::issue(
                'stalled',
                sprintf(
                    /* translators: 1: duration, 2: phase name */
                    __( 'No progress for %1$s. The run stopped during the "%2$s" phase.', 'rentopian-sync' ),
                    self::humanize( $idle ),
                    Rental_Data_Sync_Status::phase_label( (int) ( $session['phase'] ?? 0 ) )
                ),
                [
                    __( 'The queue worker on the Rentopian server may have stopped or be retrying with backoff.', 'rentopian-sync' ),
                    __( 'The per-run log below shows the last step that completed.', 'rentopian-sync' ),
                ],
                'warning',
                $idle
            );
        }

        return null;
    }

    /**
     * Fail runs that have gone quiet for longer than any retry would take,
     * so the panel reports an outcome instead of a permanent "created".
     *
     * @param string $sync_id
     * @return bool TRUE when the run was failed by this call.
     */
    public static function enforce( $sync_id ) {
        if ( ! $sync_id ) {
            return false;
        }

        $session = Rental_Data_Sync_Session::get( $sync_id );
        if ( ! $session ) {
            return false;
        }

        $status = (int) ( $session['status'] ?? 0 );
        if ( ! in_array( $status, [ Rental_Data_Sync_Status::STATUS_CREATED, Rental_Data_Sync_Status::STATUS_PROCESSING ], true ) ) {
            return false;
        }

        $idle = self::idle_seconds( $session );
        if ( $idle < self::FAIL_AFTER ) {
            return false;
        }

        $had_chunk = ! empty( $session['first_chunk_at'] );
        $reason    = $had_chunk
            ? sprintf(
                /* translators: 1: duration, 2: phase name */
                __( 'Abandoned by the Rentopian queue: no chunk callback for %1$s during the "%2$s" phase.', 'rentopian-sync' ),
                self::humanize( $idle ),
                Rental_Data_Sync_Status::phase_label( (int) ( $session['phase'] ?? 0 ) )
            )
            : sprintf(
                /* translators: %s: duration */
                __( 'The Rentopian server never called WordPress back (%s after the start request). The "webhooks" queue worker is most likely not running.', 'rentopian-sync' ),
                self::humanize( $idle )
            );

        Rental_Data_Sync_Session::update( $sync_id, [
            'status'     => Rental_Data_Sync_Status::STATUS_FAILED,
            'last_error' => $reason,
        ] );
        Rental_Data_Sync_Session::release_lock( $sync_id );

        Rental_Data_Sync_Run_Repository::finish(
            $sync_id,
            Rental_Data_Sync_Status::STATUS_FAILED,
            $reason,
            [
                'phase'           => (int) ( $session['phase'] ?? 0 ),
                'processed_count' => (int) ( $session['processed_count'] ?? 0 ),
            ]
        );

        Rental_Data_Sync_Session::cleanup_staging( $sync_id );
        update_option( 'rental_synchronize_status', 0 );

        Rental_Data_Sync_Logger::write( 'Run failed by watchdog: ' . $reason, 'error', 'watchdog', $sync_id );

        if ( class_exists( 'Rental_Data_Sync_Reporter', false ) ) {
            Rental_Data_Sync_Reporter::send_failure_report( $sync_id, $reason, (int) ( $session['phase'] ?? 0 ) );
        }

        return true;
    }

    /**
     * Watchdog tick, rate-limited so it costs nothing on a busy site. Runs
     * without an admin watching the panel.
     */
    public static function tick() {
        if ( get_transient( 'rental_data_sync_watchdog_tick' ) ) {
            return;
        }
        set_transient( 'rental_data_sync_watchdog_tick', 1, 60 );

        self::enforce( (string) get_option( 'rental_data_sync_current_id', '' ) );
        Rental_Data_Sync_Scheduler::advance_chain();
        Rental_Data_Sync_Session::purge_orphan_staging();
    }

    /* ──────────────────────────────────────────────────────────
     * Helpers
     * ────────────────────────────────────────────────────────── */

    /**
     * Seconds since the run last showed a sign of life: the last chunk
     * callback, or the start request when none has arrived.
     *
     * @param array $session
     * @return int
     */
    public static function idle_seconds( array $session ) {
        $last = (int) ( $session['last_chunk_at'] ?? 0 );

        if ( ! $last ) {
            $last = Rental_Sync_Time::started( $session );
        }

        return $last ? max( 0, time() - $last ) : 0;
    }

    /**
     * @param string   $code
     * @param string   $message
     * @param string[] $hints
     * @param string   $severity info | warning | error
     * @param int      $idle
     * @return array
     */
    private static function issue( $code, $message, array $hints = [], $severity = 'error', $idle = 0 ) {
        return [
            'code'         => $code,
            'severity'     => $severity,
            'message'      => $message,
            'hints'        => $hints,
            'idle_seconds' => (int) $idle,
        ];
    }

    /**
     * @param int $seconds
     * @return string
     */
    private static function humanize( $seconds ) {
        $seconds = max( 0, (int) $seconds );

        if ( $seconds < 60 ) {
            /* translators: %d: number of seconds */
            return sprintf( _n( '%d second', '%d seconds', $seconds, 'rentopian-sync' ), $seconds );
        }

        $minutes = (int) round( $seconds / 60 );
        if ( $minutes < 60 ) {
            /* translators: %d: number of minutes */
            return sprintf( _n( '%d minute', '%d minutes', $minutes, 'rentopian-sync' ), $minutes );
        }

        $hours = (int) round( $minutes / 60 );
        /* translators: %d: number of hours */
        return sprintf( _n( '%d hour', '%d hours', $hours, 'rentopian-sync' ), $hours );
    }
}
