<?php
/**
 * General purpose WP logger with optional timing helpers.
 *
 * Usage examples:
 *   // timing:
 *   $token = Project_WP_Logger::start('expensive_op');
 *   // ... code ...
 *   Project_WP_Logger::stop( $token, 'expensive_op', 'info', 'rentopian-sync', 0, 'extra description here' );
 *
 *   // write-only (no timing):
 *   Project_WP_Logger::write( 'A descriptive message', 'notice', 'my-source', 'logs/custom.log' );
 *
 * Notes:
 *  - $target_path can be a path relative to ABSPATH (e.g. 'wp-content/custom/logs/my.log')
 *    or an absolute path — but absolute paths are only allowed if they are inside ABSPATH.
 *  - If $target_path is null, the class prefers wc_get_logger() (if available) and otherwise
 *    writes to wp-content/uploads/wc-logs/<source>.log.
 *
 * @package RentopianSync (generalized logger)
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'Project_WP_Logger', false ) ) :

class Project_WP_Logger {

    /**
     * Timer storage for the current request.
     *
     * @var array
     */
    private static $timers = array();

    /**
     * Allowed PSR-3 log levels.
     *
     * @var array
     */
    private static $allowed_levels = array( 'emergency','alert','critical','error','warning','notice','info','debug' );

    /**
     * Start a timer and return a token.
     *
     * @param string $name Optional human name for token prefix.
     * @return string Token to pass to stop()
     */
    public static function start( $name = 'timer' ) {
        $token = uniqid( $name . '-', true );
        self::$timers[ $token ] = microtime( true );
        return $token;
    }

    /**
     * Stop a timer and optionally log elapsed time + description.
     *
     * @param string $token        Token returned from start()
     * @param string $label        Human label for log message (function name / description)
     * @param string $level        Log level (debug|info|notice|warning|error|...)
     * @param string $source       Log source / filename base (default 'rentopian-sync')
     * @param float  $min_elapsed  Optional: only log if elapsed >= this seconds (default 0 — always log)
     * @param string $description  Optional descriptive message to include in the log line
     * @param string|null $target_path Optional custom path (relative to ABSPATH or absolute inside ABSPATH)
     * @return float|null Elapsed seconds or null if token not found
     */
    public static function stop( $token, $label = 'unnamed', $level = 'info', $source = 'rentopian-sync', $min_elapsed = 0, $description = '', $target_path = null ) {

        if ( ! isset( self::$timers[ $token ] ) ) {
            // token missing -> still record a warning to default logger
            self::log_internal( sprintf( 'timer token not found: %s for label %s', $token, $label ), 'warning', $source, $target_path );
            return null;
        }

        $start = self::$timers[ $token ];
        unset( self::$timers[ $token ] );

        $end = microtime( true );
        $elapsed = $end - $start;

        // Only log if elapsed large enough OR a description is provided and we want to force a write.
        if ( $elapsed >= (float) $min_elapsed ) {
            $elapsed_str = self::format_seconds( $elapsed );
            $msg = sprintf( 'It took %s to run %s.', $elapsed_str, $label );
            if ( '' !== trim( $description ) ) {
                $msg .= ' ' . trim( $description );
            }
            self::log_internal( $msg, $level, $source, $target_path );
        }

        return $elapsed;
    }

    /**
     * Write an arbitrary message/description (no elapsed time calculation).
     *
     * @param string $message
     * @param string $level
     * @param string $source
     * @param string|null $target_path Relative path (to ABSPATH) or absolute path inside ABSPATH; if null, default wc-logs behaviour.
     * @return void
     */
    public static function write( $message, $level = 'info', $source = 'rentopian-sync', $target_path = null ) {
        self::log_internal( $message, $level, $source, $target_path );
    }

    /**
     * Internal logging function — either uses WooCommerce logger (preferred) or writes to a file.
     *
     * @param string $message
     * @param string $level
     * @param string $source
     * @param string|null $target_path
     * @return void
     */
    private static function log_internal( $message, $level = 'info', $source = 'rentopian-sync', $target_path = null ) {
        // sanitize level
        if ( ! in_array( $level, self::$allowed_levels, true ) ) {
            $level = 'info';
        }

        $timestamp = date( 'c' );
        // Prepare the line to write
        $line = sprintf( "%s [%s] %s%s", $timestamp, $level, trim( $message ), PHP_EOL );

        // Allow a module to redirect a given source to a specific file (e.g.
        // a single unified module log) WITHOUT every call site passing a
        // path. Only consulted when the caller didn't pass an explicit one.
        if ( null === $target_path && function_exists( 'apply_filters' ) ) {
            $redirect = apply_filters( 'project_wp_logger_target_path', null, $source, $level );
            if ( is_string( $redirect ) && '' !== $redirect ) {
                $target_path = $redirect;
            }
        }

        // Explicit or redirected target path — write directly (enforced
        // inside ABSPATH), but fall back to the WC logger when the target
        // isn't writable so a permission error can never surface or leak
        // into a response.
        if ( null !== $target_path ) {
            $abs_file = self::resolve_path_inside_abspath( $target_path );
            if ( false !== $abs_file && self::write_line_to_file( $abs_file, $line ) ) {
                return;
            }
            // Unwritable / invalid path → fall through to the WC logger.
        }

        // Default: WooCommerce logger (preferred), then a per-source file.
        // The WC file handler silently drops entries when the WordPress
        // filesystem abstraction is unusable (e.g. web requests resolving
        // to an FTP method because of file ownership), so only use it when
        // a real write can succeed.
        if ( function_exists( 'wc_get_logger' ) && self::wc_logging_usable() ) {
            try {
                wc_get_logger()->log( $level, trim( $message ), array( 'source' => $source ) );
                return; // done
            } catch ( Throwable $t ) {
                // fall through to fallback file writer
            }
        }

        if ( function_exists( 'wp_upload_dir' ) ) {
            $upload = wp_upload_dir();
            if ( ! empty( $upload['basedir'] ) ) {
                self::write_line_to_file(
                    trailingslashit( $upload['basedir'] ) . 'wc-logs/' . self::fallback_filename( $source ),
                    $line
                );
            }
        }
    }

    /**
     * Whether the WooCommerce file log handler can actually write.
     * Cached per request.
     *
     * @return bool
     */
    private static function wc_logging_usable() {
        static $usable = null;

        if ( null !== $usable ) {
            return $usable;
        }

        $usable = true;

        if ( class_exists( '\Automattic\WooCommerce\Internal\Utilities\FilesystemUtil' ) ) {
            try {
                \Automattic\WooCommerce\Internal\Utilities\FilesystemUtil::get_wp_filesystem();
            } catch ( Throwable $t ) {
                $usable = false;
            }
        }

        return $usable;
    }

    /**
     * Fallback log filename inside wc-logs. Mirrors the WooCommerce FileV2
     * convention (source-date-hash.log) so the file is the SAME one the WC
     * handler would use: unguessable over HTTP and listed in the
     * WooCommerce log viewer. Falls back to a plain per-source name when
     * the FileV2 classes are unavailable.
     *
     * @param string $source
     * @return string
     */
    private static function fallback_filename( $source ) {
        if ( class_exists( '\Automattic\WooCommerce\Internal\Admin\Logging\FileV2\File' ) ) {
            try {
                $file_id = \Automattic\WooCommerce\Internal\Admin\Logging\FileV2\File::generate_file_id( $source, null, time() );
                $hash    = \Automattic\WooCommerce\Internal\Admin\Logging\FileV2\File::generate_hash( $file_id );
                return "{$file_id}-{$hash}.log";
            } catch ( Throwable $t ) {
                // fall through to the plain name
            }
        }

        $safe_source = preg_replace( '/[^a-z0-9_\-]/i', '-', $source );
        return $safe_source . '.log';
    }

    /**
     * Append a line to a file, creating its directory if needed. Returns
     * false (without emitting a warning) when the directory or an existing
     * file isn't writable, so the caller can fall back gracefully.
     *
     * @param string $abs_file
     * @param string $line
     * @return bool
     */
    private static function write_line_to_file( $abs_file, $line ) {
        $dir = dirname( $abs_file );
        if ( ! file_exists( $dir ) ) {
            if ( function_exists( 'wp_mkdir_p' ) ) {
                wp_mkdir_p( $dir );
            } else {
                @mkdir( $dir, 0755, true );
            }
        }
        if ( ! is_dir( $dir ) || ! is_writable( $dir ) ) {
            return false;
        }
        if ( file_exists( $abs_file ) && ! is_writable( $abs_file ) ) {
            return false;
        }
        return false !== @file_put_contents( $abs_file, $line, FILE_APPEND | LOCK_EX );
    }

    /**
     * Resolve a user-supplied target path into an absolute path that must be inside ABSPATH.
     *
     * Rules:
     *  - If $path looks absolute, we accept it only if it's inside ABSPATH.
     *  - If $path is relative, we consider it relative to ABSPATH.
     *  - Returns false if the resolved path is outside ABSPATH or invalid.
     *
     * @param string $path Relative or absolute path.
     * @return string|false Absolute file path on success, false on invalid/unsafe path.
     */
    private static function resolve_path_inside_abspath( $path ) {
        $path = trim( $path );
        if ( '' === $path ) {
            return false;
        }

        // Normalize ABSPATH and provided path
        $abspath_norm = wp_normalize_path( ABSPATH );

        // If the path is absolute (starts with / or drive letter), normalize and test.
        if ( preg_match( '#^(?:[A-Za-z]:\\\\|/)#', $path ) ) {
            $candidate = wp_normalize_path( $path );
        } else {
            // relative path -> place under ABSPATH
            $candidate = wp_normalize_path( rtrim( ABSPATH, '/\\' ) . '/' . ltrim( $path, '/\\' ) );
        }

        // Resolve parent components where possible (we can't rely on realpath because file may not exist).
        $candidate = self::normalize_path_components( $candidate );

        // Ensure it's inside ABSPATH
        if ( 0 !== strpos( $candidate, $abspath_norm ) ) {
            return false;
        }

        return $candidate;
    }

    /**
     * Normalize path components (resolve ../ and ./) without requiring the path to exist.
     *
     * @param string $path
     * @return string Normalized path
     */
    private static function normalize_path_components( $path ) {
        $is_windows = ( '/' !== DIRECTORY_SEPARATOR && '\\' === DIRECTORY_SEPARATOR ) ? true : false;
        $parts = explode( '/', str_replace( '\\', '/', $path ) );
        $ab = array();
        foreach ( $parts as $p ) {
            if ( '' === $p || '.' === $p ) {
                continue;
            }
            if ( '..' === $p ) {
                array_pop( $ab );
                continue;
            }
            $ab[] = $p;
        }
        $normalized = ( substr( $path, 0, 1 ) === '/' ? '/' : '' ) . implode( '/', $ab );
        // On Windows, preserve drive letter if present (e.g. C:/...)
        if ( preg_match( '#^[A-Za-z]:#', $parts[0] ?? '' ) ) {
            $normalized = $parts[0] . ':' . ( substr( $normalized, 0, 1 ) === '/' ? '' : '/' ) . implode( '/', array_slice( $ab, 1 ) );
        }
        return wp_normalize_path( $normalized );
    }

    /**
     * Format elapsed seconds into a friendly string.
     *
     * @param float $seconds
     * @return string e.g. "0.523s", "523.00ms", "120ms", "12µs"
     */
    private static function format_seconds( $seconds ) {
        if ( $seconds < 0.000001 ) {
            // nanoseconds
            return round( $seconds * 1e9 ) . 'ns';
        } elseif ( $seconds < 0.001 ) {
            return round( $seconds * 1e6 ) . 'µs';
        } elseif ( $seconds < 1 ) {
            return number_format( $seconds * 1000, 2 ) . 'ms';
        } elseif ( $seconds < 60 ) {
            return number_format( $seconds, 4 ) . 's';
        } else {
            $mins = floor( $seconds / 60 );
            $secs = $seconds - ( $mins * 60 );
            return sprintf( '%dm %.4fs', $mins, $secs );
        }
    }
}

endif;
