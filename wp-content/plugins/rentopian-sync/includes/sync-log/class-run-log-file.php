<?php
/**
 * Rental_Run_Log_File
 *
 * Per-run log files on disk: one file per synchronization run, appended to
 * while the run progresses and read back by the log panel (tail + download).
 * One file per run means the panel never has to scan a mixed daily file.
 *
 * Each module owns its own instance so the directories and the filename
 * hashes stay separate. The hash is salt-derived, so a log cannot be fetched
 * over HTTP by guessing a sync id.
 *
 * @package RentopianSync\SyncLog
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Rental_Run_Log_File {

    /**
     * Directory holding the run files, relative to the uploads base.
     *
     * @var string
     */
    private $subdir;

    /**
     * Prefix mixed into the filename hash, keeping two modules from
     * producing the same filename for the same sync id.
     *
     * @var string
     */
    private $hash_prefix;

    /**
     * @param string $subdir      e.g. 'rentopian-data-sync/logs'
     * @param string $hash_prefix e.g. 'rental-data-sync-log-'
     */
    public function __construct( $subdir, $hash_prefix ) {
        $this->subdir      = (string) $subdir;
        $this->hash_prefix = (string) $hash_prefix;
    }

    /**
     * Absolute path of the directory holding the run files.
     *
     * @param bool $create
     * @return string '' when uploads are unavailable.
     */
    public function dir( $create = true ) {
        $upload = wp_upload_dir();
        if ( empty( $upload['basedir'] ) ) {
            return '';
        }

        $dir = trailingslashit( $upload['basedir'] ) . $this->subdir;

        if ( $create && ! is_dir( $dir ) ) {
            wp_mkdir_p( $dir );
            // Directory listing off, direct fetch denied on Apache; the
            // hashed filename covers servers that ignore .htaccess.
            @file_put_contents( $dir . '/index.html', '' );
            @file_put_contents( $dir . '/.htaccess', "Deny from all\n" );
        }

        return is_dir( $dir ) ? $dir : '';
    }

    /**
     * Absolute path of one run's log file.
     *
     * @param string $run_id
     * @param bool   $create Create the directory when missing.
     * @return string '' when the directory is unavailable.
     */
    public function path( $run_id, $create = true ) {
        $run_id = (string) $run_id;
        if ( '' === $run_id ) {
            return '';
        }

        $dir = $this->dir( $create );
        if ( '' === $dir ) {
            return '';
        }

        $hash = substr( wp_hash( $this->hash_prefix . $run_id ), 0, 12 );

        return $dir . '/' . sanitize_key( $run_id ) . '-' . $hash . '.log';
    }

    /**
     * @param string $run_id
     * @return bool
     */
    public function exists( $run_id ) {
        $path = $this->path( $run_id, false );
        return '' !== $path && is_file( $path );
    }

    /**
     * @param string $run_id
     * @return int Size in bytes (0 when absent).
     */
    public function size( $run_id ) {
        $path = $this->path( $run_id, false );
        return ( '' !== $path && is_file( $path ) ) ? (int) filesize( $path ) : 0;
    }

    /**
     * Last N lines of a run's log, oldest first.
     *
     * Reads backwards in blocks so a multi-megabyte file never has to be
     * loaded into memory to render the admin panel.
     *
     * @param string $run_id
     * @param int    $lines Maximum lines to return.
     * @return array { lines: string[], total_bytes: int, truncated: bool }
     */
    public function tail( $run_id, $lines = 200 ) {
        $empty = [ 'lines' => [], 'total_bytes' => 0, 'truncated' => false ];

        $path = $this->path( $run_id, false );
        if ( '' === $path || ! is_file( $path ) ) {
            return $empty;
        }

        $lines  = max( 1, (int) $lines );
        $size   = (int) filesize( $path );
        $handle = @fopen( $path, 'rb' );

        if ( ! $handle ) {
            return $empty;
        }

        $block  = 8192;
        $buffer = '';
        $pos    = $size;
        $found  = 0;

        while ( $pos > 0 && $found <= $lines ) {
            $read = min( $block, $pos );
            $pos -= $read;
            fseek( $handle, $pos );
            $chunk  = (string) fread( $handle, $read );
            $buffer = $chunk . $buffer;
            $found  = substr_count( $buffer, "\n" );
        }

        fclose( $handle );

        $all = preg_split( '/\r\n|\n|\r/', trim( $buffer ) );
        $all = array_values( array_filter( (array) $all, static function ( $l ) {
            return '' !== trim( $l );
        } ) );

        return [
            'lines'       => array_slice( $all, -$lines ),
            'total_bytes' => $size,
            'truncated'   => ( $pos > 0 ) || count( $all ) > $lines,
        ];
    }

    /**
     * Append one already-formatted line to a run's file.
     *
     * @param string $run_id
     * @param string $line
     * @param string $level
     */
    public function append( $run_id, $line, $level ) {
        $path = $this->path( $run_id );
        if ( '' === $path ) {
            return;
        }

        $entry = sprintf( "%s [%s] %s%s", gmdate( 'c' ), $level, trim( $line ), PHP_EOL );

        @file_put_contents( $path, $entry, FILE_APPEND | LOCK_EX );
    }

    /**
     * @param string $run_id
     * @return bool
     */
    public function delete( $run_id ) {
        $path = $this->path( $run_id, false );
        if ( '' === $path || ! is_file( $path ) ) {
            return false;
        }
        return @unlink( $path );
    }

    /**
     * Stream a run's log file to the browser as a download.
     *
     * @param string $run_id
     * @param string $filename
     * @return bool False when there is no file to send.
     */
    public function stream( $run_id, $filename ) {
        $path = $this->path( $run_id, false );
        if ( '' === $path || ! is_file( $path ) ) {
            return false;
        }

        nocache_headers();
        header( 'Content-Type: text/plain; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $filename ) . '"' );
        header( 'Content-Length: ' . filesize( $path ) );

        readfile( $path );

        return true;
    }
}
