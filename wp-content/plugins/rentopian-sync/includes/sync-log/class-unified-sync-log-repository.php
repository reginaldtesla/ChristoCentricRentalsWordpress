<?php
/**
 * Rental_Unified_Sync_Log
 *
 * One reading of every synchronization this site has ever run, across the
 * three generations the plugin has shipped:
 *
 *   pipeline — data and files both driven in the background by Rentopian
 *              (`rental_data_sync_run` + the file run it chained).
 *   files    — a background file sync that no data run owns: the generation
 *              that ran data in the request and files in the background,
 *              and every manual re-sync (`rental_file_sync_log`).
 *   legacy   — a run driven entirely inside the admin request, recorded by
 *              the error handler under one `sync_time` (`rental_error_log`).
 *
 * Nothing is migrated and nothing is written here: the three stores are read
 * where they already are and merged at request time, so a site that has only
 * ever run the old pipelines still sees its whole history.
 *
 * A row is addressed by a key of `<source>:<identifier>`, which is what the
 * panel sends back for detail, download and delete.
 *
 * The generations never recorded a link between a legacy data run and the
 * background file sync that may have followed it, so those stay separate
 * rows rather than being paired on a guess about their timestamps. Only the
 * current pipeline states the link itself (`file_sync_id`), and only that
 * link is used to fold two runs into one row.
 *
 * @package RentopianSync\SyncLog
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Rental_Unified_Sync_Log {

    const SOURCE_PIPELINE = 'pipeline';
    const SOURCE_FILES    = 'files';
    const SOURCE_LEGACY   = 'legacy';

    /**
     * Lines of a run log the panel renders inline. Anything longer is served
     * by the download endpoint instead of being pushed through the page.
     */
    const TAIL_LINES = 200;

    /**
     * Table existence, resolved once per request. A site that has never run
     * a given generation simply has no such table.
     *
     * @var array
     */
    private static $tables = [];

    /* ──────────────────────────────────────────────────────────
     * Tables
     * ────────────────────────────────────────────────────────── */

    /**
     * @return string Run table of the current pipeline.
     */
    private static function run_table() {
        global $wpdb;
        return $wpdb->prefix . 'rental_data_sync_run';
    }

    /**
     * @return string Background file-sync log table.
     */
    private static function file_table() {
        global $wpdb;
        return $wpdb->prefix . 'rental_file_sync_log';
    }

    /**
     * @return string Legacy error/sync log table.
     */
    private static function legacy_table() {
        global $wpdb;
        global $rental_tables;

        $name = isset( $rental_tables['error_log'] ) ? $rental_tables['error_log'] : 'rental_error_log';

        return $wpdb->prefix . $name;
    }

    /**
     * @param string $table
     * @return bool
     */
    private static function has_table( $table ) {
        global $wpdb;

        if ( ! isset( self::$tables[ $table ] ) ) {
            self::$tables[ $table ] = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table );
        }

        return self::$tables[ $table ];
    }

    /* ──────────────────────────────────────────────────────────
     * Listing
     * ────────────────────────────────────────────────────────── */

    /**
     * The SELECT fragments feeding the merged list, one per available
     * source. Each yields (source, ident, started_at) so the merged set can
     * be ordered and paginated in the database rather than in PHP.
     *
     * Every text expression is converted to one charset first. The three
     * tables were created by different code paths over the plugin's life
     * and can carry different collations; MySQL refuses both to compare and
     * to UNION columns that mix them, which would empty the whole panel.
     *
     * @return string[]
     */
    private static function union_parts() {
        $run    = self::run_table();
        $files  = self::file_table();
        $legacy = self::legacy_table();

        $has_run = self::has_table( $run );
        $parts   = [];

        if ( $has_run ) {
            $parts[] = sprintf(
                'SELECT %s AS source, %s AS ident,
                        IF(register_time > 0, register_time, UNIX_TIMESTAMP(started_at)) AS started_at
                 FROM %s',
                self::source_literal( self::SOURCE_PIPELINE ),
                self::comparable( 'sync_id' ),
                $run
            );
        }

        if ( self::has_table( $files ) ) {
            // A file run the current pipeline chained is reported inside
            // that pipeline's row, never again on its own.
            $not_chained = $has_run
                ? sprintf(
                    "WHERE %s NOT IN (SELECT %s FROM %s WHERE file_sync_id <> '')",
                    self::comparable( 'sync_id' ),
                    self::comparable( 'file_sync_id' ),
                    $run
                )
                : '';

            $parts[] = sprintf(
                'SELECT %s AS source, %s AS ident, MIN(register_time) AS started_at
                 FROM %s %s GROUP BY sync_id',
                self::source_literal( self::SOURCE_FILES ),
                self::comparable( 'sync_id' ),
                $files,
                $not_chained
            );
        }

        if ( self::has_table( $legacy ) ) {
            $parts[] = sprintf(
                'SELECT %s AS source, %s AS ident, MIN(register_time) AS started_at
                 FROM %s WHERE sync_time IS NOT NULL AND sync_time > 0 GROUP BY sync_time',
                self::source_literal( self::SOURCE_LEGACY ),
                self::comparable( 'CAST(sync_time AS CHAR)' ),
                $legacy
            );
        }

        return $parts;
    }

    /**
     * A text expression in a single charset, so values from tables with
     * different collations can be compared and merged.
     *
     * `CONVERT(expr USING charset)` is standard on both MySQL and MariaDB
     * and yields that charset's default collation, so two converted
     * expressions always meet in the same one whatever their columns were
     * declared with. Verified against MySQL 5.6 and 8.0 and MariaDB 10.4
     * and 11.4.
     *
     * @param string $expression
     * @return string
     */
    private static function comparable( $expression ) {
        return 'CONVERT(' . $expression . ' USING ' . self::charset() . ')';
    }

    /**
     * The charset every comparison is funnelled through.
     *
     * Taken from what the server actually supports rather than assumed:
     * utf8mb4 needs MySQL 5.5.3+, and an older server would reject the
     * conversion outright. Both sides of a comparison always get the same
     * answer here, which is what makes them comparable.
     *
     * @return string
     */
    private static function charset() {
        global $wpdb;

        return $wpdb->has_cap( 'utf8mb4' ) ? 'utf8mb4' : 'utf8';
    }

    /**
     * @param string $source One of the SOURCE_* constants.
     * @return string The constant as a comparable SQL literal.
     */
    private static function source_literal( $source ) {
        return self::comparable( "'" . $source . "'" );
    }

    /**
     * One page of runs, newest first, merged across every source.
     *
     * @param int $page
     * @param int $per_page
     * @return array[] Normalised run rows.
     */
    public static function get_page( $page = 1, $per_page = 10 ) {
        global $wpdb;

        $parts = self::union_parts();
        if ( ! $parts ) {
            return [];
        }

        $page     = max( 1, (int) $page );
        $per_page = max( 1, (int) $per_page );

        $rows = $wpdb->get_results( $wpdb->prepare(
            'SELECT source, ident, started_at FROM (' . implode( ' UNION ALL ', $parts ) . ') u
             ORDER BY started_at DESC, ident DESC LIMIT %d OFFSET %d',
            $per_page,
            ( $page - 1 ) * $per_page
        ), ARRAY_A );

        return self::hydrate( (array) $rows );
    }

    /**
     * @return int Total number of runs across every source.
     */
    public static function count() {
        global $wpdb;

        $parts = self::union_parts();
        if ( ! $parts ) {
            return 0;
        }

        return (int) $wpdb->get_var(
            'SELECT COUNT(*) FROM (' . implode( ' UNION ALL ', $parts ) . ') u'
        );
    }

    /**
     * Turn the merged (source, ident) list into full rows, batching one
     * query per source rather than one per row.
     *
     * @param array $keys Rows of { source, ident, started_at }.
     * @return array[]
     */
    private static function hydrate( array $keys ) {
        $by_source = [ self::SOURCE_PIPELINE => [], self::SOURCE_FILES => [], self::SOURCE_LEGACY => [] ];

        foreach ( $keys as $key ) {
            if ( isset( $by_source[ $key['source'] ] ) ) {
                $by_source[ $key['source'] ][] = $key['ident'];
            }
        }

        $hydrated = self::hydrate_pipelines( $by_source[ self::SOURCE_PIPELINE ] )
            + self::hydrate_file_runs( $by_source[ self::SOURCE_FILES ] )
            + self::hydrate_legacy( $by_source[ self::SOURCE_LEGACY ] );

        // Reassemble in the merged order the database already decided.
        $out = [];
        foreach ( $keys as $key ) {
            $id = $key['source'] . ':' . $key['ident'];
            if ( isset( $hydrated[ $id ] ) ) {
                $out[] = $hydrated[ $id ];
            }
        }

        return $out;
    }

    /**
     * @param array $ids
     * @return array Rows keyed by row key.
     */
    private static function hydrate_pipelines( array $ids ) {
        global $wpdb;

        if ( ! $ids || ! self::has_table( self::run_table() ) ) {
            return [];
        }

        $rows = $wpdb->get_results( $wpdb->prepare(
            'SELECT * FROM ' . self::run_table() . ' WHERE sync_id IN (' . self::placeholders( $ids ) . ')',
            $ids
        ), ARRAY_A );

        // Counters of the file phase each pipeline chained.
        $file_ids = [];
        foreach ( (array) $rows as $row ) {
            if ( ! empty( $row['file_sync_id'] ) ) {
                $file_ids[] = $row['file_sync_id'];
            }
        }
        $file_summaries = self::file_summaries( $file_ids );
        $with_failures  = self::runs_with_failed_images( $file_ids );

        $out = [];
        foreach ( (array) $rows as $row ) {
            $sync_id      = (string) $row['sync_id'];
            $file_sync_id = (string) $row['file_sync_id'];
            $files        = isset( $file_summaries[ $file_sync_id ] ) ? $file_summaries[ $file_sync_id ] : null;
            $status       = (int) $row['status'];
            $started      = (int) $row['register_time'] ?: Rental_Sync_Time::to_epoch( $row['started_at'] );
            $finished     = Rental_Sync_Time::to_epoch( $row['finished_at'] ?? '' );

            $out[ self::SOURCE_PIPELINE . ':' . $sync_id ] = [
                'key'          => self::SOURCE_PIPELINE . ':' . $sync_id,
                'source'       => self::SOURCE_PIPELINE,
                'sync_id'      => $sync_id,
                'file_sync_id' => $file_sync_id,
                'type_label'   => __( 'Data + Files (background)', 'rentopian-sync' ),
                // Only the file phase records a mode; the data phase has none.
                'mode_label'   => $files ? self::mode_label( (int) $files['mode'] ) : '',
                'phase_label'  => Rental_Data_Sync_Status::phase_label( (int) $row['phase'] ),
                'status'       => $status,
                'status_label' => Rental_Data_Sync_Run_Repository::status_label( $status ),
                'started_at'   => $started,
                'finished_at'  => $finished,
                'duration'     => (int) $row['duration'] ?: max( 0, $finished - $started ),
                'processed'    => (int) $row['processed_count'],
                // Records and images are different things and are never
                // shown as one fraction.
                'total'        => 0,
                'counts_label' => self::pipeline_counts_label( (int) $row['processed_count'], $files ),
                'failed'       => (int) $row['failed_count'],
                'message'      => (string) $row['message'],
                'files'        => $files ? [
                    'sync_id'    => $file_sync_id,
                    'processed'  => (int) $files['processed'],
                    'total'      => (int) $files['total'],
                    'started_at' => (int) $files['started_at'],
                    'last_at'    => (int) $files['last_at'],
                    'running'    => ! self::is_terminal_file_status( (int) $files['status'] ),
                ] : null,
                'is_running'   => in_array( $status, [ Rental_Data_Sync_Status::STATUS_CREATED, Rental_Data_Sync_Status::STATUS_PROCESSING ], true ),
                'has_failures' => isset( $with_failures[ $file_sync_id ] ),
                'has_log'      => Rental_Data_Sync_Logger::run_log_exists( $sync_id )
                    || ( $file_sync_id && Rental_File_Sync_Logger::run_log_exists( $file_sync_id ) )
                    || null !== $files,
            ];
        }

        return $out;
    }

    /**
     * @param array $ids
     * @return array Rows keyed by row key.
     */
    private static function hydrate_file_runs( array $ids ) {
        $summaries     = self::file_summaries( $ids );
        $with_failures = self::runs_with_failed_images( array_keys( $summaries ) );
        $out           = [];

        foreach ( $summaries as $sync_id => $s ) {
            $status = (int) $s['status'];

            $out[ self::SOURCE_FILES . ':' . $sync_id ] = [
                'key'          => self::SOURCE_FILES . ':' . $sync_id,
                'source'       => self::SOURCE_FILES,
                'sync_id'      => (string) $sync_id,
                'file_sync_id' => (string) $sync_id,
                'type_label'   => __( 'Files (background)', 'rentopian-sync' ),
                'mode_label'   => self::mode_label( (int) $s['mode'] ),
                'phase_label'  => '',
                'status'       => $status,
                'status_label' => Rental_Sync_Status::label( $status ),
                'started_at'   => (int) $s['started_at'],
                'finished_at'  => self::is_terminal_file_status( $status ) ? (int) $s['last_at'] : 0,
                'duration'     => max( 0, (int) $s['last_at'] - (int) $s['started_at'] ),
                'processed'    => (int) $s['processed'],
                'total'        => (int) $s['total'],
                'counts_label' => sprintf(
                    /* translators: 1: processed images, 2: total images */
                    __( '%1$d/%2$d images', 'rentopian-sync' ),
                    (int) $s['processed'],
                    (int) $s['total']
                ),
                'failed'       => 0,
                'message'      => '',
                'files'        => [
                    'sync_id'    => (string) $sync_id,
                    'processed'  => (int) $s['processed'],
                    'total'      => (int) $s['total'],
                    'started_at' => (int) $s['started_at'],
                    'last_at'    => (int) $s['last_at'],
                    'running'    => ! self::is_terminal_file_status( $status ),
                ],
                'is_running'   => in_array( $status, [ Rental_Sync_Status::STATUS_CREATED, Rental_Sync_Status::STATUS_PROCESSING ], true ),
                'has_failures' => isset( $with_failures[ $sync_id ] ),
                'has_log'      => true,
            ];
        }

        return $out;
    }

    /**
     * @param array $times Legacy `sync_time` values.
     * @return array Rows keyed by row key.
     */
    private static function hydrate_legacy( array $times ) {
        global $wpdb;

        if ( ! $times || ! self::has_table( self::legacy_table() ) ) {
            return [];
        }

        $times = array_map( 'intval', $times );

        $rows = $wpdb->get_results( $wpdb->prepare(
            'SELECT sync_time,
                    MIN(register_time) AS started_at,
                    MAX(register_time) AS last_at,
                    COUNT(*)           AS entries,
                    SUM(CASE WHEN CAST(status AS UNSIGNED) >= 400 THEN 1 ELSE 0 END) AS error_count
             FROM ' . self::legacy_table() . '
             WHERE sync_time IN (' . self::placeholders( $times ) . ')
             GROUP BY sync_time',
            $times
        ), ARRAY_A );

        $out = [];
        foreach ( (array) $rows as $row ) {
            $time   = (string) (int) $row['sync_time'];
            $failed = (int) $row['error_count'];

            $out[ self::SOURCE_LEGACY . ':' . $time ] = [
                'key'          => self::SOURCE_LEGACY . ':' . $time,
                'source'       => self::SOURCE_LEGACY,
                'sync_id'      => $time,
                'file_sync_id' => '',
                'type_label'   => __( 'Legacy (in-request)', 'rentopian-sync' ),
                'mode_label'   => '',
                'phase_label'  => '',
                'status'       => $failed ? Rental_Sync_Status::STATUS_FAILED : Rental_Sync_Status::STATUS_COMPLETED,
                'status_label' => $failed ? 'failed' : 'completed',
                'started_at'   => (int) $row['started_at'],
                'finished_at'  => (int) $row['last_at'],
                'duration'     => max( 0, (int) $row['last_at'] - (int) $row['started_at'] ),
                // The legacy log records messages, never a row count.
                'processed'    => 0,
                'total'        => 0,
                'counts_label' => sprintf(
                    /* translators: %d: number of log entries */
                    _n( '%d entry', '%d entries', (int) $row['entries'], 'rentopian-sync' ),
                    (int) $row['entries']
                ),
                'failed'       => $failed,
                'message'      => '',
                'files'        => null,
                'is_running'   => false,
                'has_failures' => false,
                'has_log'      => (int) $row['entries'] > 0,
            ];
        }

        return $out;
    }

    /**
     * Which of the given file runs still have images that failed to import.
     * Those runs keep offering the retry action the log panel has always
     * carried, so the only way to retry them does not disappear with the
     * panel it used to live in.
     *
     * @param array $ids
     * @return array Map sync_id => true.
     */
    private static function runs_with_failed_images( array $ids ) {
        global $wpdb;

        $ids = array_values( array_unique( array_filter( $ids ) ) );
        if ( ! $ids ) {
            return [];
        }

        $out   = [];
        $table = $wpdb->prefix . 'rental_failed_images';

        if ( self::has_table( $table ) ) {
            $rows = $wpdb->get_col( $wpdb->prepare(
                "SELECT DISTINCT sync_id FROM {$table}
                 WHERE resolved = 0 AND sync_id IN (" . self::placeholders( $ids ) . ')',
                $ids
            ) );

            foreach ( (array) $rows as $id ) {
                $out[ $id ] = true;
            }
        }

        // Sessions can carry failures the table never received.
        $sessions = get_option( 'rental_sync_sessions', [] );
        if ( is_array( $sessions ) ) {
            foreach ( $ids as $id ) {
                if ( ! empty( $sessions[ $id ]['failed_ids'] ) ) {
                    $out[ $id ] = true;
                }
            }
        }

        return $out;
    }

    /**
     * Counters of one or more background file runs.
     *
     * @param array $ids
     * @return array Keyed by sync_id.
     */
    private static function file_summaries( array $ids ) {
        global $wpdb;

        $ids = array_values( array_unique( array_filter( $ids ) ) );
        if ( ! $ids || ! self::has_table( self::file_table() ) ) {
            return [];
        }

        // The run's status is the one on its newest row: MAX() would rank
        // "canceled" above "completed" purely because its constant is higher.
        $rows = $wpdb->get_results( $wpdb->prepare(
            'SELECT sync_id,
                    MIN(register_time) AS started_at,
                    MAX(register_time) AS last_at,
                    COUNT(*)             AS entries,
                    MAX(processed_count) AS processed,
                    MAX(total_count)     AS total,
                    MAX(mode)            AS mode,
                    SUBSTRING_INDEX(GROUP_CONCAT(status ORDER BY id DESC), \',\', 1) AS status
             FROM ' . self::file_table() . '
             WHERE sync_id IN (' . self::placeholders( $ids ) . ')
             GROUP BY sync_id',
            $ids
        ), ARRAY_A );

        $out = [];
        foreach ( (array) $rows as $row ) {
            $out[ $row['sync_id'] ] = $row;
        }

        return $out;
    }

    /* ──────────────────────────────────────────────────────────
     * Detail
     * ────────────────────────────────────────────────────────── */

    /**
     * One run row by key, without reading any log.
     *
     * @param string $key `<source>:<identifier>`
     * @return array|null Null when the key names no run.
     */
    public static function get_run( $key ) {
        list( $source, $ident ) = self::split_key( $key );
        if ( '' === $source ) {
            return null;
        }

        $rows = self::hydrate( [ [ 'source' => $source, 'ident' => $ident ] ] );

        return $rows ? $rows[0] : null;
    }

    /**
     * The readable detail of one run: its metadata plus the tail of every
     * log it produced. A run of the current pipeline has two logs (data and
     * files) and returns two sections.
     *
     * @param string $key   `<source>:<identifier>`
     * @param int    $lines Tail length per section.
     * @return array|null Null when the key names no run.
     */
    public static function get_detail( $key, $lines = self::TAIL_LINES ) {
        $run = self::get_run( $key );
        if ( ! $run ) {
            return null;
        }

        return [
            'run'          => $run,
            'sections'     => self::sections( $run, $lines ),
            'download_url' => self::download_url( $run['key'] ),
        ];
    }

    /**
     * @param array $run
     * @param int   $lines
     * @return array[] { title, entries, truncated, note }
     */
    private static function sections( array $run, $lines ) {
        if ( self::SOURCE_LEGACY === $run['source'] ) {
            return [ self::legacy_section( (int) $run['sync_id'], $lines ) ];
        }

        $sections = [];

        if ( self::SOURCE_PIPELINE === $run['source'] ) {
            $sections[] = self::phase_section(
                __( 'Data phase', 'rentopian-sync' ),
                Rental_Data_Sync_Logger::files(),
                Rental_Data_Sync_Logger::SOURCE,
                $run['sync_id'],
                self::data_window( $run ),
                $lines
            );
        }

        $file_sync_id = ( self::SOURCE_FILES === $run['source'] ) ? $run['sync_id'] : $run['file_sync_id'];

        if ( $file_sync_id ) {
            $sections[] = self::phase_section(
                __( 'File phase', 'rentopian-sync' ),
                Rental_File_Sync_Logger::files(),
                Rental_File_Sync_Logger::SOURCE,
                $file_sync_id,
                self::file_window( $run ),
                $lines,
                $file_sync_id
            );
        }

        return $sections;
    }

    /**
     * One phase's log, from the best source that still has it:
     *
     *   1. the phase's own per-run file — the complete trace;
     *   2. the shared daily log, sliced to the run's window — also the
     *      complete trace, for runs from before per-run files existed;
     *   3. the run record — start, a progress snapshot a minute, and the
     *      outcome, which is all that table was ever meant to hold.
     *
     * @param string              $title
     * @param Rental_Run_Log_File $store
     * @param string              $daily_source Log source of the shared daily file.
     * @param string              $run_id
     * @param array               $window  { from, to } Unix times.
     * @param int                 $lines
     * @param string              $file_sync_id Set on the file phase, for the record fallback.
     * @return array
     */
    private static function phase_section( $title, Rental_Run_Log_File $store, $daily_source, $run_id, array $window, $lines, $file_sync_id = '' ) {
        $tail = $store->tail( $run_id, $lines );

        if ( $tail['total_bytes'] > 0 ) {
            return [
                'title'     => $title,
                'entries'   => array_map( [ __CLASS__, 'parse_line' ], $tail['lines'] ),
                'truncated' => (bool) $tail['truncated'],
                'note'      => '',
            ];
        }

        $daily = new Rental_Daily_Log_Reader( $daily_source );
        $slice = $daily->slice( $window['from'], $window['to'], $lines );

        if ( $slice['lines'] ) {
            return [
                'title'     => $title,
                'entries'   => array_map( [ __CLASS__, 'parse_line' ], $slice['lines'] ),
                'truncated' => (bool) $slice['truncated'],
                'note'      => __( 'This run predates per-run logs — read from the daily log for its time window.', 'rentopian-sync' ),
            ];
        }

        if ( $file_sync_id ) {
            return self::file_rows_section( $file_sync_id, $lines );
        }

        return [
            'title'     => $title,
            'entries'   => [],
            'truncated' => false,
            'note'      => __( 'No log survives for this phase — its daily log has been cleared.', 'rentopian-sync' ),
        ];
    }

    /**
     * When the data phase ran. A run still going has no end yet, so the
     * window stays open to now rather than closing on a stale timestamp.
     *
     * @param array $run
     * @return array { from, to }
     */
    private static function data_window( array $run ) {
        return [
            'from' => (int) $run['started_at'],
            'to'   => (int) $run['finished_at'] ?: time(),
        ];
    }

    /**
     * When the file phase ran.
     *
     * @param array $run
     * @return array { from, to }
     */
    private static function file_window( array $run ) {
        $files = is_array( $run['files'] ?? null ) ? $run['files'] : [];

        if ( ! $files ) {
            return self::data_window( $run );
        }

        return [
            'from' => (int) $files['started_at'],
            'to'   => empty( $files['running'] ) ? (int) $files['last_at'] : time(),
        ];
    }

    /**
     * File-sync detail rebuilt from the run record, for runs that have no
     * per-run log file.
     *
     * @param string $file_sync_id
     * @param int    $lines
     * @return array
     */
    private static function file_rows_section( $file_sync_id, $lines ) {
        global $wpdb;

        $entries = [];
        $total   = 0;

        if ( self::has_table( self::file_table() ) ) {
            $total = (int) $wpdb->get_var( $wpdb->prepare(
                'SELECT COUNT(*) FROM ' . self::file_table() . ' WHERE sync_id = %s',
                $file_sync_id
            ) );

            $rows = $wpdb->get_results( $wpdb->prepare(
                'SELECT * FROM ' . self::file_table() . ' WHERE sync_id = %s ORDER BY id DESC LIMIT %d',
                $file_sync_id,
                max( 1, (int) $lines )
            ), ARRAY_A );

            foreach ( array_reverse( (array) $rows ) as $row ) {
                $entries[] = [
                    'time'    => (int) $row['register_time'],
                    'level'   => (string) $row['level'],
                    'channel' => 'record',
                    'message' => self::file_row_message( $row ),
                ];
            }
        }

        return [
            'title'     => __( 'File phase', 'rentopian-sync' ),
            'entries'   => $entries,
            'truncated' => $total > count( $entries ),
            'note'      => $entries
                ? __( 'This run predates per-run file logs — showing its recorded progress instead.', 'rentopian-sync' )
                : __( 'Nothing was recorded for this file synchronization.', 'rentopian-sync' ),
        ];
    }

    /**
     * Legacy detail rebuilt from the error-handler rows of one run.
     *
     * @param int $sync_time
     * @param int $lines
     * @return array
     */
    private static function legacy_section( $sync_time, $lines ) {
        global $wpdb;

        $entries = [];
        $total   = 0;

        if ( self::has_table( self::legacy_table() ) ) {
            $total = (int) $wpdb->get_var( $wpdb->prepare(
                'SELECT COUNT(*) FROM ' . self::legacy_table() . ' WHERE sync_time = %d',
                $sync_time
            ) );

            $rows = $wpdb->get_results( $wpdb->prepare(
                'SELECT * FROM ' . self::legacy_table() . ' WHERE sync_time = %d ORDER BY id DESC LIMIT %d',
                $sync_time,
                max( 1, (int) $lines )
            ), ARRAY_A );

            foreach ( array_reverse( (array) $rows ) as $row ) {
                $status = (int) $row['status'];

                $entries[] = [
                    'time'    => (int) $row['register_time'],
                    'level'   => $status >= 400 ? 'error' : 'info',
                    'channel' => $status ? (string) $status : '',
                    'message' => self::legacy_row_message( $row ),
                ];
            }
        }

        return [
            'title'     => __( 'Synchronization', 'rentopian-sync' ),
            'entries'   => $entries,
            'truncated' => $total > count( $entries ),
            'note'      => $entries ? '' : __( 'Nothing was recorded for this run.', 'rentopian-sync' ),
        ];
    }

    /**
     * @param array $row
     * @return string
     */
    private static function file_row_message( array $row ) {
        $facts = [ sprintf( '%d/%d images', (int) $row['processed_count'], (int) $row['total_count'] ) ];

        if ( (int) $row['last_index'] ) {
            $facts[] = 'cursor ' . (int) $row['last_index'];
        }
        if ( (float) $row['elapsed'] > 0 ) {
            $facts[] = sprintf( '%.2fs', (float) $row['elapsed'] );
        }

        return trim( (string) $row['message'] . ' (' . implode( ', ', $facts ) . ')' );
    }

    /**
     * @param array $row
     * @return string
     */
    private static function legacy_row_message( array $row ) {
        $message = (string) $row['message'];

        if ( ! empty( $row['file'] ) ) {
            $message .= sprintf( ' (%s:%s)', basename( (string) $row['file'] ), (string) $row['line'] );
        }

        return $message;
    }

    /**
     * Split a stored log line into the parts the panel renders.
     *
     * Two shapes reach the logs: `<time> [level] [channel] message` from a
     * direct write, and `<time> LEVEL [channel] message` from WooCommerce's
     * own handler. Both are understood, so a run reads the same however its
     * lines happened to be written.
     *
     * @param string $line
     * @return array { time, level, channel, message }
     */
    public static function parse_line( $line ) {
        $entry = [ 'time' => 0, 'level' => 'info', 'channel' => '', 'message' => $line ];

        $matched = preg_match( '/^(\S+)\s+\[([a-z]+)\]\s*(.*)$/i', $line, $m )
            || preg_match( '/^(\S+)\s+(EMERGENCY|ALERT|CRITICAL|ERROR|WARNING|NOTICE|INFO|DEBUG)\s+(.*)$/', $line, $m );

        if ( ! $matched ) {
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

    /* ──────────────────────────────────────────────────────────
     * Download
     * ────────────────────────────────────────────────────────── */

    /**
     * How many rebuilt entries a download may contain when a run has no log
     * file of its own. Real log files are streamed whole and ignore this.
     */
    const DOWNLOAD_ROW_LIMIT = 100000;

    /**
     * Send one run's whole log as a plain-text download.
     *
     * Real log files are streamed rather than read into memory, so a long
     * run downloads at any size.
     *
     * @param string $key
     * @return bool False when the key names no run.
     */
    public static function stream_download( $key ) {
        $run = self::get_run( $key );
        if ( ! $run ) {
            return false;
        }

        nocache_headers();
        header( 'Content-Type: text/plain; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="' . self::download_filename( $run ) . '"' );

        echo self::download_header( $run );

        if ( self::SOURCE_LEGACY === $run['source'] ) {
            $section = self::legacy_section( (int) $run['sync_id'], self::DOWNLOAD_ROW_LIMIT );
            self::echo_entries( $section['entries'] );

            return true;
        }

        if ( self::SOURCE_PIPELINE === $run['source'] ) {
            echo PHP_EOL . '===== ' . __( 'DATA PHASE', 'rentopian-sync' ) . ' =====' . PHP_EOL;
            self::echo_phase_log(
                Rental_Data_Sync_Logger::files(),
                Rental_Data_Sync_Logger::SOURCE,
                $run['sync_id'],
                self::data_window( $run )
            );
        }

        $file_sync_id = ( self::SOURCE_FILES === $run['source'] ) ? $run['sync_id'] : $run['file_sync_id'];
        if ( $file_sync_id ) {
            echo PHP_EOL . '===== ' . __( 'FILE PHASE', 'rentopian-sync' ) . ' =====' . PHP_EOL;
            self::echo_phase_log(
                Rental_File_Sync_Logger::files(),
                Rental_File_Sync_Logger::SOURCE,
                $file_sync_id,
                self::file_window( $run ),
                $file_sync_id
            );
        }

        return true;
    }

    /**
     * Stream one phase's whole log, from the best source that still has it
     * — the same order the panel reads: per-run file, then the daily log
     * sliced to the run's window, then the run record.
     *
     * @param Rental_Run_Log_File $store
     * @param string              $daily_source
     * @param string              $run_id
     * @param array               $window
     * @param string              $file_sync_id Set on the file phase.
     */
    private static function echo_phase_log( Rental_Run_Log_File $store, $daily_source, $run_id, array $window, $file_sync_id = '' ) {
        $path = $store->path( $run_id, false );

        if ( '' !== $path && is_file( $path ) ) {
            readfile( $path );
            return;
        }

        $daily = new Rental_Daily_Log_Reader( $daily_source );
        if ( $daily->stream( $window['from'], $window['to'] ) > 0 ) {
            return;
        }

        if ( $file_sync_id ) {
            self::echo_entries( self::file_rows_section( $file_sync_id, self::DOWNLOAD_ROW_LIMIT )['entries'] );
        }
    }

    /**
     * @param array $entries
     */
    private static function echo_entries( array $entries ) {
        foreach ( $entries as $entry ) {
            echo sprintf(
                '%s [%s] %s%s%s',
                $entry['time'] ? gmdate( 'c', $entry['time'] ) : '',
                $entry['level'],
                $entry['channel'] ? '[' . $entry['channel'] . '] ' : '',
                $entry['message'],
                PHP_EOL
            );
        }
    }

    /**
     * @param array $run
     * @return string
     */
    private static function download_header( array $run ) {
        $rows = [
            __( 'site', 'rentopian-sync' )      => home_url(),
            __( 'type', 'rentopian-sync' )      => $run['type_label'],
            __( 'sync id', 'rentopian-sync' )   => $run['sync_id'],
            __( 'file sync id', 'rentopian-sync' ) => $run['file_sync_id'],
            __( 'mode', 'rentopian-sync' )      => $run['mode_label'],
            __( 'phase', 'rentopian-sync' )     => $run['phase_label'],
            __( 'status', 'rentopian-sync' )    => $run['status_label'],
            __( 'started', 'rentopian-sync' )   => $run['started_at'] ? gmdate( 'c', $run['started_at'] ) : '',
            __( 'finished', 'rentopian-sync' )  => $run['finished_at'] ? gmdate( 'c', $run['finished_at'] ) : '',
            __( 'duration', 'rentopian-sync' )  => $run['duration'] ? $run['duration'] . 's' : '',
            __( 'processed', 'rentopian-sync' ) => $run['processed'] ?: '',
            __( 'failed', 'rentopian-sync' )    => $run['failed'] ?: '',
            __( 'message', 'rentopian-sync' )   => $run['message'],
        ];

        $lines = [ '===== Rentopian synchronization log =====' ];
        foreach ( $rows as $label => $value ) {
            if ( '' !== (string) $value ) {
                $lines[] = sprintf( '  %-14s %s', $label, $value );
            }
        }

        return implode( PHP_EOL, $lines ) . PHP_EOL;
    }

    /**
     * @param array $run
     * @return string
     */
    private static function download_filename( array $run ) {
        return sanitize_file_name( sprintf(
            'rentopian-sync-%s-%s.log',
            $run['source'],
            $run['sync_id'] ?: 'run'
        ) );
    }

    /* ──────────────────────────────────────────────────────────
     * Delete
     * ────────────────────────────────────────────────────────── */

    /**
     * Delete one run: its record in whichever store holds it, plus any log
     * file it wrote. A run still in flight is refused, so a live log is
     * never pulled out from under a worker.
     *
     * @param string $key
     * @return array { success, message }
     */
    public static function delete( $key ) {
        global $wpdb;

        $detail = self::get_detail( $key, 1 );
        if ( ! $detail ) {
            return [ 'success' => false, 'message' => __( 'That run no longer exists.', 'rentopian-sync' ) ];
        }

        $run = $detail['run'];

        if ( ! empty( $run['is_running'] ) ) {
            return [
                'success' => false,
                'message' => __( 'That run is still in progress. Stop it before deleting its log.', 'rentopian-sync' ),
            ];
        }

        switch ( $run['source'] ) {
            case self::SOURCE_PIPELINE:
                Rental_Data_Sync_Run_Repository::delete( $run['sync_id'] );
                Rental_Data_Sync_Session::remove( $run['sync_id'] );
                self::delete_file_run( $run['file_sync_id'] );
                break;

            case self::SOURCE_FILES:
                self::delete_file_run( $run['sync_id'] );
                break;

            case self::SOURCE_LEGACY:
                if ( self::has_table( self::legacy_table() ) ) {
                    $wpdb->delete( self::legacy_table(), [ 'sync_time' => (int) $run['sync_id'] ], [ '%d' ] );
                }
                break;
        }

        return [ 'success' => true, 'message' => __( 'Run deleted.', 'rentopian-sync' ) ];
    }

    /**
     * @param string $file_sync_id
     */
    private static function delete_file_run( $file_sync_id ) {
        if ( ! $file_sync_id ) {
            return;
        }

        Rental_File_Sync_Logger::delete_run_log( $file_sync_id );

        if ( self::has_table( self::file_table() ) ) {
            Rental_Sync_Log_Repository::delete_by_sync( $file_sync_id );
        }
    }

    /* ──────────────────────────────────────────────────────────
     * Helpers
     * ────────────────────────────────────────────────────────── */

    /**
     * @param string $key
     * @return array { source, ident } — source is '' when the key is unknown.
     */
    private static function split_key( $key ) {
        $parts  = explode( ':', (string) $key, 2 );
        $source = isset( $parts[0] ) ? $parts[0] : '';
        $ident  = isset( $parts[1] ) ? $parts[1] : '';

        $known = [ self::SOURCE_PIPELINE, self::SOURCE_FILES, self::SOURCE_LEGACY ];

        return ( in_array( $source, $known, true ) && '' !== $ident ) ? [ $source, $ident ] : [ '', '' ];
    }

    /**
     * @param array $values
     * @return string Comma-separated placeholders for a prepared IN clause.
     */
    private static function placeholders( array $values ) {
        $type = ( $values && is_int( reset( $values ) ) ) ? '%d' : '%s';

        return implode( ',', array_fill( 0, count( $values ), $type ) );
    }

    /**
     * What a pipeline run got through: catalog records in the data phase,
     * images in the file phase it chained.
     *
     * @param int        $processed Records written by the data phase.
     * @param array|null $files     File-phase summary.
     * @return string
     */
    private static function pipeline_counts_label( $processed, $files ) {
        /* translators: %d: number of catalog records */
        $label = sprintf( _n( '%d record', '%d records', $processed, 'rentopian-sync' ), $processed );

        if ( $files ) {
            $label .= sprintf(
                /* translators: 1: processed images, 2: total images */
                __( ' · %1$d/%2$d images', 'rentopian-sync' ),
                (int) $files['processed'],
                (int) $files['total']
            );
        }

        return $label;
    }

    /**
     * @param int $mode
     * @return string
     */
    private static function mode_label( $mode ) {
        return ( (int) $mode === Rental_Sync_Status::MODE_RESYNC )
            ? __( 're-sync', 'rentopian-sync' )
            : __( 'sync', 'rentopian-sync' );
    }

    /**
     * @param int $status
     * @return bool
     */
    private static function is_terminal_file_status( $status ) {
        return in_array(
            (int) $status,
            [ Rental_Sync_Status::STATUS_COMPLETED, Rental_Sync_Status::STATUS_FAILED, Rental_Sync_Status::STATUS_CANCELED ],
            true
        );
    }

    /**
     * @param string $key
     * @return string
     */
    private static function download_url( $key ) {
        return add_query_arg( [
            'action' => 'rental_sync_log_download',
            'key'    => rawurlencode( $key ),
            'nonce'  => wp_create_nonce( Rental_Unified_Sync_Log_Ajax_Controller::NONCE_ACTION ),
        ], admin_url( 'admin-ajax.php' ) );
    }
}
