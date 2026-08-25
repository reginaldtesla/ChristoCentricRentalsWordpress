<?php
/**
 * Rental_Daily_Log_Reader
 *
 * Reads one run's lines back out of a shared daily wc-log.
 *
 * Both sync modules write every line to a daily file named after their log
 * source, and only later gained a per-run file of their own. For runs from
 * before that — and for any run whose per-run file was lost — the daily file
 * still holds the complete trace, and it is a far better answer than the
 * handful of progress snapshots the run record keeps.
 *
 * Lines are selected by TIME, not by sync id: only a small minority of them
 * carry an id, so filtering on it would return a fraction of the run. Both
 * sync modules run one session at a time, so a run's window belongs to that
 * run.
 *
 * @package RentopianSync\SyncLog
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Rental_Daily_Log_Reader {

    /**
     * Seconds of slack at each end of a run's window, covering lines written
     * either side of the timestamps the run record happens to hold.
     */
    const WINDOW_PAD = 3;

    /**
     * Log source, i.e. the daily file's name stem.
     *
     * @var string
     */
    private $source;

    /**
     * @param string $source e.g. 'rentopian-file-sync'
     */
    public function __construct( $source ) {
        $this->source = (string) $source;
    }

    /**
     * Directory holding the daily files.
     *
     * @return string '' when it cannot be located.
     */
    public function dir() {
        if ( defined( 'WC_LOG_DIR' ) && WC_LOG_DIR ) {
            return untrailingslashit( WC_LOG_DIR );
        }

        $upload = wp_upload_dir();
        if ( empty( $upload['basedir'] ) ) {
            return '';
        }

        return untrailingslashit( $upload['basedir'] ) . '/wc-logs';
    }

    /**
     * Daily files covering a window, oldest first. A run that crosses
     * midnight is written to two of them.
     *
     * @param int $from Unix time.
     * @param int $to   Unix time.
     * @return string[] Absolute paths.
     */
    public function files_for_range( $from, $to ) {
        $dir = $this->dir();
        if ( '' === $dir || ! is_dir( $dir ) ) {
            return [];
        }

        $files = [];

        for ( $day = (int) $from; $day <= (int) $to + DAY_IN_SECONDS; $day += DAY_IN_SECONDS ) {
            // The daily file carries a salt-derived hash after the date, so
            // the exact name cannot be predicted — only matched.
            $matches = glob( $dir . '/' . $this->source . '-' . gmdate( 'Y-m-d', $day ) . '*.log' );

            foreach ( (array) $matches as $match ) {
                if ( is_file( $match ) ) {
                    $files[ $match ] = true;
                }
            }

            if ( $day > (int) $to ) {
                break;
            }
        }

        $files = array_keys( $files );
        sort( $files );

        return $files;
    }

    /**
     * @param int $from
     * @param int $to
     * @return bool Whether any daily file covers the window.
     */
    public function has_lines( $from, $to ) {
        foreach ( $this->files_for_range( $from, $to ) as $path ) {
            $found = false;

            $this->walk( $path, $from, $to, static function () use ( &$found ) {
                $found = true;
                return false; // one is enough
            } );

            if ( $found ) {
                return true;
            }
        }

        return false;
    }

    /**
     * The run's lines, oldest first.
     *
     * @param int $from
     * @param int $to
     * @param int $max_lines 0 for every line; otherwise the most recent N.
     * @return array { lines: string[], total: int, truncated: bool }
     */
    public function slice( $from, $to, $max_lines = 0 ) {
        $lines = [];
        $total = 0;

        foreach ( $this->files_for_range( $from, $to ) as $path ) {
            $this->walk( $path, $from, $to, static function ( $line ) use ( &$lines, &$total, $max_lines ) {
                $total++;
                $lines[] = $line;

                // Keep only what will be returned, so a long run cannot
                // pull a whole day's logging into memory.
                if ( $max_lines > 0 && count( $lines ) > $max_lines ) {
                    array_shift( $lines );
                }

                return true;
            } );
        }

        return [
            'lines'     => $lines,
            'total'     => $total,
            'truncated' => $max_lines > 0 && $total > count( $lines ),
        ];
    }

    /**
     * Echo the run's lines as they are read, so a download of any size
     * never has to be held in memory.
     *
     * @param int $from
     * @param int $to
     * @return int Lines written.
     */
    public function stream( $from, $to ) {
        $written = 0;

        foreach ( $this->files_for_range( $from, $to ) as $path ) {
            $this->walk( $path, $from, $to, static function ( $line ) use ( &$written ) {
                echo $line . PHP_EOL;
                $written++;
                return true;
            } );
        }

        return $written;
    }

    /**
     * Read one daily file and hand every in-window line to a callback.
     *
     * A line that carries no timestamp of its own is a continuation of the
     * entry above it — the final report block is written that way — so it
     * inherits that entry's verdict and a multi-line entry is never cut in
     * half.
     *
     * @param string   $path
     * @param int      $from
     * @param int      $to
     * @param callable $emit Receives a line; returning false stops the read.
     */
    private function walk( $path, $from, $to, callable $emit ) {
        $handle = @fopen( $path, 'rb' );
        if ( ! $handle ) {
            return;
        }

        $from    = (int) $from - self::WINDOW_PAD;
        $to      = (int) $to + self::WINDOW_PAD;
        $keeping = false;

        while ( false !== ( $line = fgets( $handle ) ) ) {
            $line = rtrim( $line, "\r\n" );

            if ( '' === trim( $line ) ) {
                continue;
            }

            $stamp = $this->line_time( $line );

            if ( null !== $stamp ) {
                // Files are written in order, so once past the window there
                // is nothing left to find in this file.
                if ( $stamp > $to ) {
                    break;
                }

                $keeping = ( $stamp >= $from );
            }

            if ( $keeping && false === $emit( $line ) ) {
                break;
            }
        }

        fclose( $handle );
    }

    /**
     * A daily file holds two line shapes, because a line reaches it either
     * through WooCommerce's handler (`<time> INFO [channel] …`) or, when
     * that handler cannot write, through a direct write
     * (`<time> [info] [channel] …`). What both always start with is the
     * timestamp, so that is what an entry is recognised by — keying on the
     * level instead would read every WooCommerce-written line as a
     * continuation of the one above it and drag lines from outside the
     * window in with it.
     *
     * @param string $line
     * @return int|null Unix time, or null when the line opens no entry.
     */
    private function line_time( $line ) {
        $iso = '/^(\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}(?:[.,]\d+)?(?:Z|[+\-]\d{2}:?\d{2})?)/';

        if ( ! preg_match( $iso, $line, $m ) ) {
            return null;
        }

        $stamp = strtotime( $m[1] );

        return $stamp ? $stamp : null;
    }
}
