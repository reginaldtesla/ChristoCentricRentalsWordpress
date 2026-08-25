<?php
/**
 * @link       https://rentopian.com
 * @since      1.0.0
 *
 * @package    rentopian-sync
 */
require_once __DIR__ . '/exceptions/RentalException.php';

class ErrorHandler
{
    public function __construct() {
        set_error_handler([$this, 'errorHandler']);
        register_shutdown_function([$this, 'fatalErrorHandler']);
        set_exception_handler([$this, 'exceptionHandler']);
    }

    public function errorHandler($errno, $errstr, $errfile, $errline) {
        if ($errno == 0 || !(error_reporting() & $errno) ) return false;
        $this->registerErrorInLog($errstr, $errfile, $errline);
        if ($this->checkPath($errfile)) {
            $this->showError($errno, $errstr, $errfile, $errline);
        }

        return false;
    }

    public function fatalErrorHandler() {
        if (($error = error_get_last()) && $error['type']) {
            if ($error['type'] == 0 || !(error_reporting() & $error['type']) ) return false;
            $this->registerErrorInLog($error['message'], $error['file'], $error['line']);
            if ($this->checkPath($error['file'])) {
                ob_get_clean();
                $this->showError($error['type'], $error['message'], $error['file'], $error['line']);
            }
        }
    }

    public function exceptionHandler(Throwable $e) {
        $this->registerErrorInLog($e->getMessage(), $e->getFile(), $e->getLine());
        self::logUncaughtTrace($e);
        if ($e instanceof RentalException || $this->checkPath($e->getFile())) {
            $this->showError(get_class($e), $e->getMessage(), $e->getFile(), $e->getLine());
            return true;
        }

        return false;
    }

    /**
     * Record the call stack of an uncaught exception.
     *
     * An uncaught exception during a front-end render aborts the page (the body
     * stops mid-output), and the throw site alone does not say which caller
     * triggered it. The stack is written to the performance log so the
     * originating code path can be identified after the fact.
     */
    public static function logUncaughtTrace(Throwable $e) {
        if (!class_exists('Project_WP_Logger')) {
            return;
        }

        $uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '(cli)';

        $message = sprintf(
            "[UNCAUGHT %s] %s%s  at %s:%d%s  uri=%s%s  admin=%s ajax=%s%s  trace:%s%s",
            get_class($e),
            $e->getMessage(),
            PHP_EOL,
            $e->getFile(),
            $e->getLine(),
            PHP_EOL,
            $uri,
            PHP_EOL,
            (is_admin() ? 'yes' : 'no'),
            ((defined('DOING_AJAX') && DOING_AJAX) ? 'yes' : 'no'),
            PHP_EOL,
            PHP_EOL,
            $e->getTraceAsString()
        );

        $target = 'wp-content/uploads/wc-logs/rentpro-performance-' . gmdate('Y-m-d') . '.log';

        Project_WP_Logger::write($message, 'error', 'rentpro-performance', $target);
    }

    public function showError($errno, $errstr, $errfile, $errline, $statusCode = 500) {
        //if (current_user_can('manage_options')) {
            if (defined( 'DOING_AJAX' ) && DOING_AJAX) {
                wp_send_json([
                    'message' => $errstr,
                    'file' => $errfile,
                    'line' => $errline
                ], $statusCode);
            } else {
//                header("HTTP/1.1 {$statusCode}");
                echo "error: $errno</br>"
                    . "message: $errstr</br>"
                    . "file: $errfile</br>"
                    . "line: $errline</br><hr>";

            }
        //}
    }

    /**
     * Guards against re-entry: writing the log row can itself raise a PHP
     * notice, which would come straight back through the error handler.
     *
     * @var bool
     */
    private static $writingLog = false;

    public static function registerErrorInLog($errstr, $errfile, $errline, $type = RentalException::TYPE_RUNTIME, $sync_time = null, $statusCode = 500, $data = null) {
        global $wpdb, $rental_tables;

        $isSyncError = ($type == RentalException::TYPE_SYNC_GLOBAL || $type == RentalException::TYPE_SYNC_RUNTIME);

        if (!$isSyncError && get_option('rental_runtime_log_setting') != 1) {
            return;
        }

        if (self::$writingLog) {
            return;
        }
        self::$writingLog = true;

        $url = isset($_SERVER['HTTP_HOST']) ? "http://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]" : '';

        // Keep a failed log write to ourselves. Reporting it through
        // $wpdb->show_errors() flips a global, sticky switch: every later
        // query in the request then prints its errors straight into the
        // page, which is how an unrelated admin action ends up displaying
        // raw SQL. Nothing here is worth breaking a page over.
        $suppressed = $wpdb->suppress_errors(true);

        $wpdb->insert($wpdb->prefix . $rental_tables["error_log"], [
            'status' => $statusCode,
            'url' => $url,
            'file' => $errfile,
            'line' => $errline,
            'message' => $errstr,
            'type' => $type,
            'sync_time' => $sync_time,
            'register_time' => time(),
            'data' => $data
        ]);

        $insertError = $wpdb->last_error;

        $wpdb->suppress_errors($suppressed);

        if ($insertError !== '' && class_exists('Project_WP_Logger')) {
            Project_WP_Logger::write(
                sprintf('Could not record an error in %s: %s (original error: %s at %s:%s)',
                    $rental_tables["error_log"], $insertError, $errstr, $errfile, $errline),
                'error',
                'rentopian-sync'
            );
        }

        self::$writingLog = false;
    }

    private function checkPath($path) {
        return (strpos($path, RENTOPIAN_SYNC_PATH) !== false);
    }
}