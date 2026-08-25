<?php
/**
 * Rental_Sets_Logger
 *
 * Focused, request-scoped logging for the sets module. Routes through
 * Project_WP_Logger; the sets bootstrap redirects this source (and the
 * other `rentopian-sets-*` sources) into ONE unified daily file so the
 * whole module is debuggable from a single place:
 *
 *     wp-content/uploads/wc-logs/rentopian-new-sets-logs-YYYY-MM-DD.log
 *
 * Levels: info / warning / error are the curated, always-on lifecycle log
 * (add-to-cart, merge/reconcile, validation outcomes, hide + price
 * decisions, sync/webhook). debug is verbose (per-page gate, per-section
 * render, sync internals) and only emits when debug logging is enabled
 * (WP_DEBUG, the `rental_sets_log_debug` option, or the
 * `rental_sets_logger_debug_enabled` filter).
 *
 * Goals:
 *   - One line per logical event in the sets lifecycle. No multi-line
 *     blobs. No dumping cart_contents.
 *   - Stable structured prefix per event so a `grep` against the file
 *     is enough to follow one set / one cart key through the request.
 *   - Cheap when disabled — the global enable flag short-circuits
 *     before any string concatenation.
 *
 * Enable / disable. The logger is off unless one of the following
 * conditions holds:
 *   - WP_DEBUG is true (development convenience), OR
 *   - The option `rental_sets_log_enabled` is truthy, OR
 *   - The filter `rental_sets_logger_enabled` returns true.
 *
 * Levels.
 *   - info   — normal lifecycle (render decisions, add-to-cart events,
 *              merger / reconciler outcomes)
 *   - debug  — verbose internals (per-section counts, per-child uid
 *              decisions). Only emitted when the global enable flag
 *              AND `rental_sets_log_debug` are both truthy.
 *   - warning — recoverable anomalies (mismatched uid, stale edit key,
 *               malformed POST data)
 *   - error  — unexpected failures (handlers should still continue, but
 *              the cart pipeline operator wants visibility)
 *
 * Event channels (each method is one channel for grep-ability):
 *
 *   render($set_id, $context)         — section presenter built sections
 *   gate($set_id, $will, $reason)     — modern renderer decision
 *   add_to_cart($cart_key, $context)  — parent / child line committed
 *   merger($survivor, $context)       — parent-merge collapse outcome
 *   reconciler($parent, $context)     — child reconciliation outcome
 *   edit_mode($edit_key, $context)    — edit-mode replacement outcome
 *   validation($set_id, $context)     — cart-validator rule outcome
 *   sync($set_id, $context)           — sync-time decisions (option)
 *
 * Each helper concatenates the channel prefix, set / cart key, and
 * a short context blob (key=value pairs).
 *
 * @package RentopianSync\Sets
 * @since   2.14.7
 *
 * PHP requirement: 7.4+
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'Rental_Sets_Logger', false ) ) :

class Rental_Sets_Logger {

    /**
     * Log-source slug; becomes the WC log file basename.
     */
    const SOURCE = 'rentopian-sets';

    /**
     * Per-request memo for the "should we log?" decision so we
     * don't re-evaluate the option/filter on every call.
     *
     * @var bool|null
     */
    protected static $enabled = null;

    /**
     * Per-request memo for debug-level logging.
     *
     * @var bool|null
     */
    protected static $debug_enabled = null;

    /**
     * Public enable check. Other classes can call this to skip
     * expensive context-building when logging is off.
     *
     * @return bool
     */
    public static function is_enabled() {
        if ( null !== self::$enabled ) {
            return self::$enabled;
        }
        $on = ( defined( 'WP_DEBUG' ) && WP_DEBUG )
            || (bool) get_option( 'rental_sets_log_enabled', false );
        $on = (bool) apply_filters( 'rental_sets_logger_enabled', $on );
        self::$enabled = $on;
        return $on;
    }

    /**
     * Debug-level enable check (gated on is_enabled() too).
     *
     * @return bool
     */
    public static function debug_enabled() {
        if ( ! self::is_enabled() ) {
            return false;
        }
        if ( null !== self::$debug_enabled ) {
            return self::$debug_enabled;
        }
        $on = (bool) get_option( 'rental_sets_log_debug', false );
        $on = (bool) apply_filters( 'rental_sets_logger_debug_enabled', $on );
        self::$debug_enabled = $on;
        return $on;
    }

    /*
    |--------------------------------------------------------------------------
    | Event channels
    |--------------------------------------------------------------------------
    | Each method takes a primary identifier (set_id / cart_key) and a
    | context array, and writes one structured line. Context keys with
    | empty / null values are dropped so log noise stays low.
    */

    /**
     * Renderer / section-presenter decision.
     *
     * @param int   $set_id
     * @param array $context  Keys: will (bool), sections (int), simple
     *                        (int), selectable (int), groups (int),
     *                        set_order_len (int), item_based_total (bool).
     * @return void
     */
    public static function render( $set_id, array $context = array() ) {
        // Per-set-page detail — verbose, debug-only.
        if ( ! self::debug_enabled() ) {
            return;
        }
        self::emit( 'debug', 'render', sprintf( 'set=%d', (int) $set_id ), $context );
    }

    /**
     * Modern renderer's will_render gate decision.
     *
     * @param int    $set_id
     * @param bool   $will
     * @param string $reason
     * @return void
     */
    public static function gate( $set_id, $will, $reason = '' ) {
        // Fires on every product page render — verbose, debug-only.
        if ( ! self::debug_enabled() ) {
            return;
        }
        $ctx = array(
            'will'   => $will ? 1 : 0,
            'reason' => $reason,
        );
        self::emit( 'debug', 'gate', sprintf( 'set=%d', (int) $set_id ), $ctx );
    }

    /**
     * Cart add-to-cart event. Use $cart_key as the primary identifier
     * so multi-line traces for one submission can be greppable by key.
     *
     * @param string $cart_key
     * @param array  $context Keys: kind (parent|child), product (int),
     *                        variation (int), qty (int), set (int),
     *                        group_uid, item_uid, parent_key.
     * @return void
     */
    public static function add_to_cart( $cart_key, array $context = array() ) {
        self::emit( 'info', 'add_to_cart', 'key=' . self::short_key( $cart_key ), $context );
    }

    /**
     * Cart_Merger outcome.
     *
     * @param string $survivor_key
     * @param array  $context Keys: dropped (str), product, qty_summed,
     *                        children_reparented (int).
     * @return void
     */
    public static function merger( $survivor_key, array $context = array() ) {
        self::emit( 'info', 'merger', 'survivor=' . self::short_key( $survivor_key ), $context );
    }

    /**
     * Cart_Reconciler outcome.
     *
     * @param string $parent_key
     * @param array  $context Keys: removed (int), merged (int),
     *                        submission_pairs (int).
     * @return void
     */
    public static function reconciler( $parent_key, array $context = array() ) {
        self::emit( 'info', 'reconciler', 'parent=' . self::short_key( $parent_key ), $context );
    }

    /**
     * Cart_Edit_Mode replacement outcome.
     *
     * @param string $edit_key
     * @param array  $context Keys: new_key (str), removed_children (int),
     *                        outcome (replaced|stale|skipped).
     * @return void
     */
    public static function edit_mode( $edit_key, array $context = array() ) {
        self::emit( 'info', 'edit_mode', 'edit=' . self::short_key( $edit_key ), $context );
    }

    /**
     * Cart validation outcome. Usually `warning` level when a rule
     * blocked the add.
     *
     * @param int    $set_id
     * @param array  $context Keys: rule, ok (bool), message, picked,
     *                        min, max.
     * @param string $level   info|warning
     * @return void
     */
    public static function validation( $set_id, array $context = array(), $level = 'info' ) {
        self::emit( $level, 'validation', sprintf( 'set=%d', (int) $set_id ), $context );
    }

    /**
     * Sync-time event. Most of these will only ever fire in debug.
     *
     * @param int   $set_id
     * @param array $context
     * @return void
     */
    public static function sync( $set_id, array $context = array() ) {
        if ( ! self::debug_enabled() ) {
            return;
        }
        self::emit( 'debug', 'sync', sprintf( 'set=%d', (int) $set_id ), $context );
    }

    /**
     * Generic warning channel for unexpected-but-recoverable state.
     *
     * @param string $where Short channel-like prefix.
     * @param string $msg
     * @param array  $context
     * @return void
     */
    public static function warn( $where, $msg, array $context = array() ) {
        $context['msg'] = $msg;
        self::emit( 'warning', $where, '', $context );
    }

    /**
     * Hidden-item resolution + selection-overlay decisions (the customer
     * never sees these items; the log explains what the server did).
     *
     * @param int   $set_id
     * @param array $context Keys: action, hide_all, some_hidden, decisions.
     * @return void
     */
    public static function hide( $set_id, array $context = array() ) {
        self::emit( 'info', 'hide', sprintf( 'set=%d', (int) $set_id ), $context );
    }

    /**
     * Set-child pricing decision — e.g. a separate_price child billed on
     * top of a fixed bundle, or a composite-group price override.
     *
     * @param array $context Keys: set, product, variant, price, reason.
     * @return void
     */
    public static function price( array $context = array() ) {
        self::emit( 'info', 'price', '', $context );
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * Compose and dispatch one log line.
     *
     * Final format:
     *   <channel> <head> <k1=v1> <k2=v2> ...
     *
     * We bypass `Project_WP_Logger::write()` and go straight to a
     * dedicated daily file in `wp-content/uploads/wc-logs/`. The
     * reason: `Project_WP_Logger` first tries `wc_get_logger()`, and
     * WC's file handler silently filters anything below
     * `WC_LOG_HANDLER_THRESHOLD` (defaults to `info`, but some
     * installations bump it higher). The result was a quiet no-op
     * with zero feedback. Writing directly here matches the
     * `wc-logs/rentopian-<source>-YYYY-MM-DD.log` convention already
     * used by `rentopian-orders` and `rentopian-file-delete` logs on
     * this codebase, so log inspection tools keep working.
     *
     * @param string $level
     * @param string $channel
     * @param string $head    Pre-formatted primary identifier.
     * @param array  $context Key=value pairs to append.
     * @return void
     */
    protected static function emit( $level, $channel, $head, array $context ) {
        // info / warning / error form the curated, always-on lifecycle log;
        // debug lines are emitted only when debug logging is enabled.
        if ( 'debug' === $level && ! self::debug_enabled() ) {
            return;
        }

        $parts = array( $channel );
        if ( '' !== $head ) {
            $parts[] = $head;
        }
        foreach ( $context as $k => $v ) {
            if ( null === $v ) {
                continue;
            }
            if ( '' === $v && 0 !== $v && '0' !== $v ) {
                continue;
            }
            if ( is_bool( $v ) ) {
                $v = $v ? '1' : '0';
            } elseif ( is_array( $v ) || is_object( $v ) ) {
                $v = wp_json_encode( $v );
            }
            $parts[] = $k . '=' . self::escape_scalar( (string) $v );
        }
        $line = implode( ' ', $parts );

        // Route through the shared logger; the sets bootstrap redirects the
        // SOURCE into the single unified daily file via the
        // `project_wp_logger_target_path` filter.
        if ( class_exists( 'Project_WP_Logger', false ) && method_exists( 'Project_WP_Logger', 'write' ) ) {
            Project_WP_Logger::write( $line, $level, self::SOURCE );
        }
    }

    /**
     * Cart keys are 32-char MD5 hashes. Logging the full hash is noisy
     * and the leading 8 chars are unique-enough for one request.
     *
     * @param string $key
     * @return string
     */
    protected static function short_key( $key ) {
        $key = (string) $key;
        if ( '' === $key ) {
            return '-';
        }
        if ( strlen( $key ) > 12 ) {
            return substr( $key, 0, 8 ) . '…';
        }
        return $key;
    }

    /**
     * Quote a scalar value for log emission if it contains whitespace.
     * Keep simple values bare so logs stay scannable.
     *
     * @param string $v
     * @return string
     */
    protected static function escape_scalar( $v ) {
        if ( '' === $v ) {
            return '""';
        }
        if ( preg_match( '/\s/', $v ) ) {
            return '"' . str_replace( '"', '\"', $v ) . '"';
        }
        return $v;
    }
}

endif;
