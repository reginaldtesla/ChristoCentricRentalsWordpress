<?php
/**
 * Data Sync Reporter
 *
 * Sends the combined run report by email: per-entity counters, sweep
 * summary, and the data / file / total durations. The success report fires
 * when the chained file sync completes; the failure report fires the
 * moment a run turns terminal.
 *
 * Recipients resolve from code, an option, and a filter (same pattern as
 * the orders incident reporter).
 *
 * @package RentopianSync\DataSync
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Rental_Data_Sync_Reporter {

    /**
     * Free-text recipients, for an address that belongs to nobody with an
     * account here — whoever is testing the site, most often.
     */
    const OPTION_RECIPIENTS = 'rental_data_sync_email_recipients';

    /**
     * The administrator chosen to receive reports (0 = nobody).
     */
    const OPTION_REPORT_USER = 'rental_data_sync_report_user';

    /**
     * wc-logs source for the final report of a whole pipeline run (data +
     * files). Kept separate from the per-run trace so the outcome of every
     * run can be read on its own, without an inbox and without scrolling a
     * chunk-by-chunk log.
     */
    const SOURCE_REPORT = 'rentopian-sync-final-report';

    /**
     * Entities a customer would notice the absence of. A failure here is
     * worth acting on however few there are, while a stray taxonomy value
     * is not — which is the difference the report's outcome expresses.
     */
    const CRITICAL_ENTITIES = [ 'products', 'variants', 'sets' ];

    /**
     * Share of an entity that may fail before the run stops being routine.
     * Below it the storefront is intact and a handful of records need a
     * data correction upstream; above it something systematic went wrong.
     */
    const FAILURE_RATIO_LIMIT = 0.02;

    /**
     * Failing records named in the body before the rest are summarized.
     */
    const FAILURES_LISTED = 15;

    /**
     * Hooked on `rental_file_sync_completed` (fired by the file-sync chunk
     * worker). Sends the combined report when that file sync was chained
     * from a data-sync run.
     *
     * @param string $file_sync_id
     * @param mixed  $file_duration Seconds (file-sync timer value).
     */
    public static function on_file_sync_completed( $file_sync_id, $file_duration = 0 ) {
        $run = get_option( 'rental_data_sync_last_run', [] );
        if ( ! is_array( $run ) || empty( $run['sync_id'] ) ) {
            return;
        }
        if ( ( $run['file_sync_id'] ?? '' ) !== $file_sync_id || ! empty( $run['reported_at'] ) ) {
            return;
        }

        Rental_Data_Sync_Logger::bind_run( $run['sync_id'] );

        // Every image that could be fetched now has been. Anything still
        // without a featured image can borrow one from its own gallery;
        // whatever is left has no image to show and is reported.
        if ( class_exists( 'Rental_Image_Integrity' ) ) {
            $run['images_promoted'] = Rental_Image_Integrity::promote_gallery_images();
            $run['images_missing']  = Rental_Image_Integrity::posts_without_images();
        }

        $run['file_completed_at'] = current_time( 'mysql' );
        $run['file_duration']     = (float) $file_duration;

        // What the panel shows depends on which of these happened, so the
        // outcome is recorded rather than assumed from the attempt.
        $run['report_sent']       = self::send_success_report( $run );
        $run['report_recipients'] = count( self::recipients() );
        $run['reported_at']       = current_time( 'mysql' );

        update_option( 'rental_data_sync_last_run', $run, false );

        Rental_Data_Sync_Logger::write( 'Pipeline finished: the chained file sync completed', 'info', 'report' );

        Rental_Data_Sync_Run_Repository::touch( $run['sync_id'], [
            'file_sync_id' => $file_sync_id,
            'message'      => __( 'Data and file synchronization completed.', 'rentopian-sync' ),
        ] );

        // The run's working artifacts are no longer needed.
        Rental_Data_Sync_Session::cleanup_run_artifacts( $run['sync_id'] );
    }

    /**
     * Hooked on `rental_file_sync_failed` (fired by the file-sync watchdog).
     * The pipeline is only over when the file phase is, so a file phase that
     * was abandoned ends the run — and is reported — rather than leaving it
     * open forever with no report at all.
     *
     * @param string $file_sync_id
     * @param string $reason
     */
    public static function on_file_sync_failed( $file_sync_id, $reason ) {
        $run = get_option( 'rental_data_sync_last_run', [] );
        if ( ! is_array( $run ) || empty( $run['sync_id'] ) ) {
            return;
        }
        if ( ( $run['file_sync_id'] ?? '' ) !== $file_sync_id || ! empty( $run['reported_at'] ) ) {
            return;
        }

        Rental_Data_Sync_Logger::bind_run( $run['sync_id'] );

        $run['file_completed_at'] = current_time( 'mysql' );
        $run['file_error']        = (string) $reason;
        $run['report_sent']       = self::send_file_failure_report( $run, $reason );
        $run['report_recipients'] = count( self::recipients() );
        $run['reported_at']       = current_time( 'mysql' );

        update_option( 'rental_data_sync_last_run', $run, false );

        Rental_Data_Sync_Logger::write( 'Pipeline ended: the chained file sync was abandoned — ' . $reason, 'error', 'report' );

        Rental_Data_Sync_Run_Repository::finish(
            $run['sync_id'],
            Rental_Data_Sync_Status::STATUS_FAILED,
            $reason,
            [ 'file_sync_id' => (string) $file_sync_id ]
        );

        Rental_Data_Sync_Session::cleanup_run_artifacts( $run['sync_id'] );
    }

    /**
     * Compose + send the report for a pipeline whose file phase never
     * finished. The catalog text is deliberate: the data phase did complete,
     * so the storefront has current products and stale or missing images —
     * a different situation from a failed data sync.
     *
     * @param array  $run
     * @param string $reason
     * @return bool Whether the email actually went out.
     */
    private static function send_file_failure_report( array $run, $reason ) {
        $site  = get_bloginfo( 'name' );
        $stats = is_array( $run['stats'] ?? null ) ? $run['stats'] : [];

        $data_secs = self::interval_seconds( $run['started_at'] ?? '', $run['data_completed_at'] ?? '' );

        $lines   = [];
        $lines[] = 'Rentopian full background sync did NOT finish on ' . home_url() . '.';
        $lines[] = '';
        $lines[] = 'The catalog data was imported. The image phase stopped before it completed.';
        $lines[] = '';
        $lines[] = 'Reason: ' . $reason;
        $lines[] = '';
        $lines[] = 'Durations:';
        $lines[] = '  Data sync:  ' . self::format_duration( $data_secs );
        $lines[] = '';
        $lines[] = 'Catalog changes (the data phase completed, so these were applied):';

        foreach ( $stats as $entity => $counts ) {
            $lines[] = sprintf(
                '  %-17s created %d, updated %d, deleted %d, failed %d',
                $entity . ':',
                (int) ( $counts['created'] ?? 0 ),
                (int) ( $counts['updated'] ?? 0 ),
                (int) ( $counts['deleted'] ?? 0 ),
                (int) ( $counts['failed'] ?? 0 )
            );
        }
        if ( empty( $stats ) ) {
            $lines[] = '  (no counters recorded)';
        }

        $lines[] = '';
        $lines[] = 'What to do: start the synchronization again. Images already fetched are kept, so the';
        $lines[] = 'next run resumes rather than re-downloading everything.';
        $lines[] = 'If it stops at the same point, check that a worker is consuming the "webhooks" queue';
        $lines[] = 'on the Rentopian server.';
        $lines[] = '';
        $lines[] = 'Details: WooCommerce → Status → Logs (source: rentopian-file-sync).';
        $lines[] = 'Data sync ID: ' . ( $run['sync_id'] ?? '' );
        $lines[] = 'File sync ID: ' . ( $run['file_sync_id'] ?? '' );

        $rows = [
            'site'          => home_url(),
            'data sync id'  => (string) ( $run['sync_id'] ?? '' ),
            'file sync id'  => (string) ( $run['file_sync_id'] ?? '' ),
            'started'       => (string) ( $run['started_at'] ?? '' ),
            'data finished' => (string) ( $run['data_completed_at'] ?? '' ),
            'reason'        => (string) $reason,
            'catalog state' => 'data imported — images incomplete until another run finishes',
        ];
        $rows += self::stat_rows( $stats );

        self::log_report( 'FILE PHASE ABANDONED', $rows );

        return self::send(
            sprintf( '[%s] Rentopian full background sync — IMAGES INCOMPLETE', $site ),
            implode( "\r\n", $lines )
        );
    }

    /**
     * Compose + send the success report.
     *
     * @param array $run The rental_data_sync_last_run record.
     * @return bool Whether the email actually went out.
     */
    private static function send_success_report( array $run ) {
        $site    = get_bloginfo( 'name' );
        $stats   = is_array( $run['stats'] ?? null ) ? $run['stats'] : [];
        $verdict = self::verdict( $run, $stats );

        $data_secs  = self::interval_seconds( $run['started_at'] ?? '', $run['data_completed_at'] ?? '' );
        $total_secs = self::interval_seconds( $run['started_at'] ?? '', $run['file_completed_at'] ?? '' );
        $file_secs  = self::file_seconds( $run, $total_secs );

        // The verdict leads, because the first thing the reader needs is
        // whether this run asks anything of them.
        $lines   = [];
        $lines[] = 'Result: ' . $verdict['status'] . ' — ' . $verdict['summary'];
        $lines[] = $verdict['action'];
        $lines[] = '';
        $lines[] = 'Rentopian full background sync finished on ' . home_url() . '.';
        if ( ! empty( $run['purged'] ) ) {
            $lines[] = 'Mode: full rebuild — the catalog was wiped first and reimported from scratch, so every field matches the feed.';
        }
        $lines[] = '';
        $lines[] = 'Durations:';
        $lines[] = '  Data sync:  ' . self::format_duration( $data_secs );
        $lines[] = '  File sync:  ' . self::format_duration( $file_secs );
        $lines[] = '  Total:      ' . self::format_duration( $total_secs );
        $lines[] = '';
        $lines[] = 'Catalog changes:';

        foreach ( $stats as $entity => $counts ) {
            $skipped = (int) ( $counts['skipped'] ?? 0 );

            $lines[] = sprintf(
                '  %-17s created %d, updated %d, deleted %d, failed %d%s',
                $entity . ':',
                (int) ( $counts['created'] ?? 0 ),
                (int) ( $counts['updated'] ?? 0 ),
                (int) ( $counts['deleted'] ?? 0 ),
                (int) ( $counts['failed'] ?? 0 ),
                $skipped > 0 ? ", skipped {$skipped}" : ''
            );
        }
        if ( empty( $stats ) ) {
            $lines[] = '  (no counters recorded)';
        }

        $lines[] = '';

        $missing = is_array( $run['images_missing'] ?? null ) ? $run['images_missing'] : [];
        if ( ! empty( $run['images_promoted'] ) || $missing ) {
            $lines[] = 'Images:';
            if ( ! empty( $run['images_promoted'] ) ) {
                $lines[] = sprintf( '  %d product(s) took their featured image from their own gallery.', (int) $run['images_promoted'] );
            }
            if ( $missing ) {
                $lines[] = sprintf( '  %d product(s)/variation(s) still have no image:', count( $missing ) );
                foreach ( array_slice( $missing, 0, 20 ) as $m ) {
                    $lines[] = sprintf( '    [%d] %s (%s)', $m['id'], $m['title'], $m['type'] );
                }
                if ( count( $missing ) > 20 ) {
                    $lines[] = sprintf( '    … and %d more.', count( $missing ) - 20 );
                }
                $lines[] = '  Check that these items carry an image in Rentopian.';
            }
            $lines[] = '';
        }

        if ( ! empty( $run['sweep_skipped'] ) ) {
            $lines[] = 'The orphan sweep was skipped this run (a fetch phase did not complete, or a mass-deletion guard';
            $lines[] = 'triggered). Records deleted in Rentopian may still be on the storefront until a run sweeps them.';
            $lines[] = '';
        }

        foreach ( self::failure_lines( $verdict['failed'], $verdict['total'], $verdict['by_entity'], $run['failures'] ?? [] ) as $line ) {
            $lines[] = $line;
        }

        $lines[] = 'Data sync ID: ' . ( $run['sync_id'] ?? '' );
        $lines[] = 'File sync ID: ' . ( $run['file_sync_id'] ?? '' );

        $subject = sprintf(
            '[%s] Rentopian full background sync — %s · %s',
            $site,
            $verdict['status'],
            $verdict['headline']
        );

        // The report is recorded whether or not mail is configured, so a
        // site without SMTP still keeps a durable outcome for every run.
        self::log_success_report( $run, $verdict );

        return self::send( $subject, implode( "\r\n", $lines ) );
    }

    /* ──────────────────────────────────────────────────────────
     * Outcome
     * ────────────────────────────────────────────────────────── */

    /**
     * Grade a finished pipeline.
     *
     * A single unwritable record and a catalog that may be serving deleted
     * products are both "not perfect" and nothing alike, so the outcome
     * separates them: ACTION NEEDED is reserved for a run whose result the
     * storefront is wrong about, and everything else COMPLETED — with the
     * number of affected records stated rather than implied.
     *
     * @param array $run
     * @param array $stats
     * @return array { status, headline, summary, action, failed, total, by_entity }
     */
    private static function verdict( array $run, array $stats ) {
        $failed    = 0;
        $total     = 0;
        $by_entity = [];
        $serious   = [];

        foreach ( $stats as $entity => $counts ) {
            // `repaired` is a second action on a record already counted as
            // created or updated, so it is not a record of its own.
            $written = 0;
            foreach ( [ 'created', 'updated', 'deleted', 'skipped', 'failed' ] as $key ) {
                $written += (int) ( $counts[ $key ] ?? 0 );
            }

            $entity_failed = (int) ( $counts['failed'] ?? 0 );

            $failed += $entity_failed;
            $total  += $written;

            if ( $entity_failed < 1 ) {
                continue;
            }

            $by_entity[ $entity ] = $entity_failed;

            $is_critical = in_array( $entity, self::CRITICAL_ENTITIES, true );
            $is_systemic = $written > 0 && ( $entity_failed / $written ) > self::FAILURE_RATIO_LIMIT;

            if ( $is_critical || $is_systemic ) {
                $serious[ $entity ] = $entity_failed;
            }
        }

        arsort( $by_entity );
        arsort( $serious );

        $base = [
            'failed'    => $failed,
            'total'     => $total,
            'by_entity' => $by_entity,
        ];

        if ( ! empty( $run['sweep_skipped'] ) ) {
            return $base + [
                'status'   => 'ACTION NEEDED',
                'headline' => 'stale records may remain',
                'summary'  => 'the catalog was imported, but the orphan sweep did not run.',
                'action'   => 'Records deleted in Rentopian may still be on the storefront. Run the synchronization again — the next complete run removes them.',
            ];
        }

        if ( $serious ) {
            return $base + [
                'status'   => 'ACTION NEEDED',
                'headline' => self::failure_phrase( $serious ) . ' did not import',
                'summary'  => 'the catalog was imported, but part of it is missing from the storefront.',
                'action'   => 'Review the records listed below, then run the synchronization again once their cause is resolved.',
            ];
        }

        if ( $failed > 0 ) {
            return $base + [
                'status'   => 'COMPLETED',
                'headline' => sprintf(
                    '%s of %s records need attention',
                    number_format_i18n( $failed ),
                    number_format_i18n( $total )
                ),
                'summary'  => 'the catalog is live and current.',
                'action'   => 'No action required for the storefront. A few records need a correction in Rentopian — they are named below.',
            ];
        }

        return $base + [
            'status'   => 'COMPLETED',
            'headline' => 'everything imported',
            'summary'  => 'the catalog is live and current.',
            'action'   => 'No action required.',
        ];
    }

    /**
     * "12 products", "12 products and 3 sets", "12 products, 3 sets and 1 variant".
     *
     * @param array $by_entity entity => failed count, highest first.
     * @return string
     */
    private static function failure_phrase( array $by_entity ) {
        $parts = [];
        foreach ( $by_entity as $entity => $count ) {
            $parts[] = number_format_i18n( $count ) . ' ' . self::entity_label( $entity, $count );
        }

        if ( count( $parts ) < 2 ) {
            return (string) reset( $parts );
        }

        $last = array_pop( $parts );

        return implode( ', ', $parts ) . ' and ' . $last;
    }

    /**
     * Counter key as a person would say it ("attribute_values" → "attribute
     * value" / "attribute values").
     *
     * @param string $entity
     * @param int    $count
     * @return string
     */
    private static function entity_label( $entity, $count = 2 ) {
        $label = str_replace( '_', ' ', (string) $entity );

        return ( 1 === (int) $count && 's' === substr( $label, -1 ) )
            ? substr( $label, 0, -1 )
            : $label;
    }

    /**
     * The "what needs attention" block: which records failed and why.
     *
     * Naming them is the point — "some records failed, check the logs"
     * sends the reader hunting through an hour of trace for five lines.
     *
     * @param int     $failed    Total failures across every entity.
     * @param int     $total     Records the run touched.
     * @param array   $by_entity entity => failed count.
     * @param array[] $failures  Recorded [ entity, context, reason ] entries.
     * @return string[] Body lines (empty when nothing failed).
     */
    private static function failure_lines( $failed, $total, array $by_entity, $failures ) {
        if ( $failed < 1 ) {
            return [];
        }

        $failures = is_array( $failures ) ? $failures : [];

        $lines   = [];
        $lines[] = sprintf(
            'Records that need attention — %s of %s:',
            number_format_i18n( $failed ),
            number_format_i18n( $total )
        );

        foreach ( $by_entity as $entity => $count ) {
            $lines[] = sprintf( '  %-17s %s failed', $entity . ':', number_format_i18n( $count ) );
        }

        if ( $failures ) {
            $lines[] = '';
            foreach ( array_slice( $failures, 0, self::FAILURES_LISTED ) as $failure ) {
                $lines[] = sprintf(
                    '  %s — %s',
                    (string) ( $failure['context'] ?? 'record' ),
                    (string) ( $failure['reason'] ?? '' )
                );
            }

            $named = min( count( $failures ), self::FAILURES_LISTED );
            if ( $failed > $named ) {
                $lines[] = sprintf( '  … and %s more, in the log.', number_format_i18n( $failed - $named ) );
            }
        }

        $lines[] = '';
        $lines[] = 'Everything else imported. Full detail: WooCommerce → Status → Logs';
        $lines[] = '(source: rentopian-data-sync), or the Data Sync log panel.';
        $lines[] = '';

        return $lines;
    }

    /**
     * Send a terminal-failure report immediately.
     *
     * @param string $sync_id
     * @param string $reason
     * @param int    $phase
     */
    public static function send_failure_report( $sync_id, $reason, $phase ) {
        $site     = get_bloginfo( 'name' );
        $session  = Rental_Data_Sync_Session::get( $sync_id ) ?: [];
        $stats    = Rental_Data_Sync_Session::get_stats( $sync_id );
        $failures = Rental_Data_Sync_Session::get_failures( $sync_id );

        $lines   = [];
        $lines[] = 'Rentopian background data sync FAILED on ' . home_url() . '.';
        $lines[] = '';
        $lines[] = 'Failed during phase: ' . Rental_Data_Sync_Status::phase_label( $phase );
        $lines[] = 'Reason: ' . $reason;
        $lines[] = 'Started at: ' . ( $session['started_at'] ?? 'unknown' );
        $lines[] = 'Processed rows before failure: ' . (int) ( $session['processed_count'] ?? 0 );
        $lines[] = '';

        if ( ! empty( $stats ) ) {
            $lines[] = 'Counters up to the failure:';
            foreach ( $stats as $entity => $counts ) {
                $lines[] = sprintf(
                    '  %-17s created %d, updated %d, deleted %d, failed %d',
                    $entity . ':',
                    (int) ( $counts['created'] ?? 0 ),
                    (int) ( $counts['updated'] ?? 0 ),
                    (int) ( $counts['deleted'] ?? 0 ),
                    (int) ( $counts['failed'] ?? 0 )
                );
            }
            $lines[] = '';
        }

        if ( $failures ) {
            $lines[] = 'Records that could not be written before the failure:';
            foreach ( array_slice( $failures, 0, self::FAILURES_LISTED ) as $failure ) {
                $lines[] = sprintf(
                    '  %s — %s',
                    (string) ( $failure['context'] ?? 'record' ),
                    (string) ( $failure['reason'] ?? '' )
                );
            }
            if ( count( $failures ) > self::FAILURES_LISTED ) {
                $lines[] = sprintf( '  … and %s more, in the log.', number_format_i18n( count( $failures ) - self::FAILURES_LISTED ) );
            }
            $lines[] = '';
        }

        // What the storefront is serving right now depends entirely on
        // whether the wipe had already run — the difference between "no
        // action needed" and "the catalog is empty until this is fixed".
        if ( ! empty( $session['purged'] ) ) {
            $lines[] = 'ACTION REQUIRED: the catalog had already been wiped when this run failed, so the storefront is empty or incomplete.';
            $lines[] = 'Start a new synchronization once the cause above is resolved — the catalog stays incomplete until one finishes.';
        } else {
            $lines[] = 'The catalog was NOT wiped before this failure, so the storefront still serves the previous data.';
        }
        $lines[] = '';
        $lines[] = 'Details: WooCommerce → Status → Logs (source: rentopian-data-sync).';
        $lines[] = 'Data sync ID: ' . $sync_id;

        $subject = sprintf( '[%s] Rentopian background data sync — FAILED', $site );

        $rows = [
            'site'            => home_url(),
            'data sync id'    => (string) $sync_id,
            'failed phase'    => Rental_Data_Sync_Status::phase_label( $phase ),
            'reason'          => (string) $reason,
            'started'         => (string) ( $session['started_at'] ?? 'unknown' ),
            'rows processed'  => (int) ( $session['processed_count'] ?? 0 ),
            'catalog state'   => ! empty( $session['purged'] )
                ? 'WIPED — the storefront is empty or incomplete until a run completes'
                : 'not wiped — the storefront still serves the previous data',
        ];
        $rows += self::stat_rows( $stats );

        foreach ( self::failure_rows( $failures ) as $row ) {
            $rows[] = $row;
        }

        self::log_report( 'FAILED', $rows );

        self::send( $subject, implode( "\r\n", $lines ) );
    }

    /* ──────────────────────────────────────────────────────────
     * Final-report log
     * ────────────────────────────────────────────────────────── */

    /**
     * Record the outcome of a whole pipeline run in the final-report log.
     *
     * Deliberately not the email body: the mail is addressed to a person
     * and explains what to do next, while this is a flat record of what a
     * run did, meant to be grepped and diffed against other runs. Both are
     * built from the same facts.
     *
     * The whole report is written as ONE entry so a concurrent writer
     * cannot interleave lines through the middle of it.
     *
     * @param string $outcome COMPLETED | ACTION NEEDED | FILE PHASE ABANDONED | FAILED
     * @param array  $rows    label => value (a null value prints the label alone).
     */
    private static function log_report( $outcome, array $rows ) {
        $lines = [ '===== Rentopian sync — final report: ' . $outcome . ' =====' ];

        foreach ( $rows as $label => $value ) {
            if ( null === $value || '' === $value ) {
                continue;
            }
            $lines[] = is_int( $label )
                ? '  ' . $value
                : sprintf( '  %-18s %s', $label, $value );
        }

        $lines[] = '===== end of report =====';

        // A run that asks something of an operator is findable by level,
        // not only by reading every entry.
        if ( 'FAILED' === $outcome ) {
            $level = 'error';
        } elseif ( 'COMPLETED' === $outcome ) {
            $level = 'info';
        } else {
            $level = 'warning';
        }

        if ( class_exists( 'Project_WP_Logger', false ) ) {
            Project_WP_Logger::write( implode( PHP_EOL, $lines ), $level, self::SOURCE_REPORT );
        }
    }

    /**
     * Flatten the per-entity counters into report rows.
     *
     * @param array $stats
     * @return array
     */
    private static function stat_rows( array $stats ) {
        $rows = [];

        foreach ( $stats as $entity => $counts ) {
            $bits = [];
            foreach ( [ 'created', 'updated', 'repaired', 'deleted', 'skipped', 'failed' ] as $key ) {
                if ( ! empty( $counts[ $key ] ) ) {
                    $bits[] = $key . ' ' . (int) $counts[ $key ];
                }
            }
            $rows[ $entity ] = $bits ? implode( ', ', $bits ) : 'no changes';
        }

        return $rows;
    }

    /**
     * Final-report entry for a completed pipeline (data + files).
     *
     * @param array $run
     * @param array $verdict As returned by verdict().
     */
    private static function log_success_report( array $run, array $verdict ) {
        $data_secs  = self::interval_seconds( $run['started_at'] ?? '', $run['data_completed_at'] ?? '' );
        $total_secs = self::interval_seconds( $run['started_at'] ?? '', $run['file_completed_at'] ?? '' );
        $file_secs  = self::file_seconds( $run, $total_secs );

        $missing = is_array( $run['images_missing'] ?? null ) ? $run['images_missing'] : [];

        $rows = [
            'site'           => home_url(),
            'verdict'        => $verdict['headline'] . ' — ' . $verdict['action'],
            'mode'           => ! empty( $run['purged'] )
                ? 'full rebuild (catalog wiped, then reimported)'
                : 'in-place update',
            'data sync id'   => (string) ( $run['sync_id'] ?? '' ),
            'file sync id'   => (string) ( $run['file_sync_id'] ?? '' ),
            'started'        => (string) ( $run['started_at'] ?? '' ),
            'data finished'  => (string) ( $run['data_completed_at'] ?? '' ),
            'file finished'  => (string) ( $run['file_completed_at'] ?? '' ),
            'duration'       => sprintf(
                'data %s | files %s | total %s',
                self::format_duration( $data_secs ),
                self::format_duration( $file_secs ),
                self::format_duration( $total_secs )
            ),
        ];

        $rows += self::stat_rows( is_array( $run['stats'] ?? null ) ? $run['stats'] : [] );

        if ( ! empty( $run['images_promoted'] ) ) {
            $rows['images promoted'] = (int) $run['images_promoted'] . ' took a featured image from their own gallery';
        }
        if ( $missing ) {
            $rows['images missing'] = count( $missing ) . ' item(s) still have no image';
            foreach ( array_slice( $missing, 0, 20 ) as $m ) {
                $rows[] = sprintf( 'no image: [%d] %s (%s)', $m['id'], $m['title'], $m['type'] );
            }
            if ( count( $missing ) > 20 ) {
                $rows[] = sprintf( 'no image: … and %d more', count( $missing ) - 20 );
            }
        }

        $rows['sweep'] = ! empty( $run['purged'] )
            ? 'not needed (catalog was wiped this run)'
            : ( ! empty( $run['sweep_skipped'] ) ? 'SKIPPED — stale records may remain' : 'completed' );

        // Appended, not merged: the image rows above are already keyed by
        // position, and `+` would keep those and drop these.
        foreach ( self::failure_rows( $run['failures'] ?? [] ) as $row ) {
            $rows[] = $row;
        }

        self::log_report( $verdict['status'], $rows );
    }

    /**
     * Failing records as report rows, so the log entry names them for the
     * same reason the email does.
     *
     * @param array[] $failures
     * @return array
     */
    private static function failure_rows( $failures ) {
        $failures = is_array( $failures ) ? $failures : [];
        if ( empty( $failures ) ) {
            return [];
        }

        $rows = [];
        foreach ( $failures as $failure ) {
            $rows[] = sprintf(
                'failed: %s — %s',
                (string) ( $failure['context'] ?? 'record' ),
                (string) ( $failure['reason'] ?? '' )
            );
        }

        return $rows;
    }

    /* ──────────────────────────────────────────────────────────
     * Internals
     * ────────────────────────────────────────────────────────── */

    /**
     * Who receives the run report: the administrator chosen in the sync
     * panel, plus any address typed in beside it.
     *
     * There is no built-in recipient. An unconfigured site sends nothing and
     * says so in the run log, which is a great deal better than quietly
     * mailing a stranger.
     *
     * @return string[] Sanitized, de-duplicated recipient list.
     */
    public static function recipients() {
        $recipients = [];

        $user_id = (int) get_option( self::OPTION_REPORT_USER, 0 );
        if ( $user_id > 0 ) {
            $user = get_userdata( $user_id );
            if ( $user && ! empty( $user->user_email ) ) {
                $recipients[] = $user->user_email;
            }
        }

        $stored = (string) get_option( self::OPTION_RECIPIENTS, '' );
        if ( '' !== $stored ) {
            foreach ( preg_split( '/[,;\s]+/', $stored ) as $email ) {
                if ( '' !== $email ) {
                    $recipients[] = $email;
                }
            }
        }

        $recipients = apply_filters( 'rental_data_sync_report_emails', $recipients );

        $clean = [];
        foreach ( (array) $recipients as $email ) {
            $email = sanitize_email( $email );
            $key   = strtolower( (string) $email );

            // First spelling wins, so the chosen administrator's own address
            // is the one used when it is also typed into the extra field.
            if ( $email && is_email( $email ) && ! isset( $clean[ $key ] ) ) {
                $clean[ $key ] = $email;
            }
        }

        return array_values( $clean );
    }

    /**
     * @param string $subject
     * @param string $body
     * @return bool Whether the report actually went out.
     */
    private static function send( $subject, $body ) {
        $recipients = self::recipients();

        if ( empty( $recipients ) ) {
            Rental_Data_Sync_Logger::write(
                'Report not emailed: no recipient is configured. Choose one under "Report email" in the synchronization panel.',
                'warning',
                'report'
            );

            return false;
        }

        $sent = wp_mail( $recipients, $subject, $body );

        Rental_Data_Sync_Logger::write(
            ( $sent
                ? 'Report emailed to '
                : 'Report NOT emailed — wp_mail refused it (the site has no working mail transport) for ' )
            . implode( ', ', $recipients ) . ' — ' . $subject,
            $sent ? 'info' : 'error',
            'report'
        );

        return (bool) $sent;
    }

    /**
     * How long the file phase took.
     *
     * The phase runs inside the pipeline, so it cannot have lasted longer
     * than the pipeline did. A reported value that fails that test came
     * from somewhere other than this run — an older build measured it with
     * a site-wide timer that no chained run ever started — and the interval
     * between chaining and finishing is used instead.
     *
     * @param array $run
     * @param int   $total_secs Whole-pipeline duration.
     * @return float
     */
    private static function file_seconds( array $run, $total_secs ) {
        $reported = (float) ( $run['file_duration'] ?? 0 );
        $measured = self::interval_seconds( $run['file_chained_at'] ?? '', $run['file_completed_at'] ?? '' );

        if ( $reported > 0 && ( ! $total_secs || $reported <= $total_secs ) ) {
            return $reported;
        }

        return $measured;
    }

    private static function interval_seconds( $from, $to ) {
        $a = $from ? strtotime( $from ) : 0;
        $b = $to ? strtotime( $to ) : 0;
        return ( $a && $b && $b >= $a ) ? ( $b - $a ) : 0;
    }

    private static function format_duration( $seconds ) {
        $seconds = (int) round( (float) $seconds );
        if ( $seconds < 60 ) {
            return $seconds . 's';
        }
        if ( $seconds < 3600 ) {
            return sprintf( '%dm %02ds', floor( $seconds / 60 ), $seconds % 60 );
        }
        return sprintf( '%dh %02dm %02ds', floor( $seconds / 3600 ), floor( ( $seconds % 3600 ) / 60 ), $seconds % 60 );
    }
}
