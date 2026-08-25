<?php
/**
 * Rentopian Order Logger
 *
 * Single funnel for ALL log writes in the order/quote sync flow. Replaces
 * every inline `Project_WP_Logger::write(...)` call previously sprinkled
 * across rental_create_order() and friends.
 *
 * - Writes to wp-content/uploads/wc-logs/ (visible to file-manager plugins).
 * - Falls back to wp-content/uploads/rentopian-logs/ if wc-logs/ isn't writable.
 * - Daily rotation: rentopian-orders-YYYY-MM-DD.log
 * - Structured key=value lines, greppable and machine-parseable.
 * - Bridges to Rentopian_Incident_Reporter for healing/error events that
 *   warrant an email.
 *
 * @package rentopian-sync
 * @since   1.x.x
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Rentopian_Order_Logger', false ) ) :

class Rentopian_Order_Logger {

	const SOURCE      = 'rentopian-orders';
	const FILE_PREFIX = 'rentopian-orders-';

	private static $log_dir_abs = null;
	private static $log_dir_rel = null;
	private static $verified    = false;

	// ---------------------------------------------------------------------
	// Directory resolution
	// ---------------------------------------------------------------------

	private static function resolve_log_dir() {
		if ( self::$verified && self::$log_dir_abs ) {
			return self::$log_dir_abs;
		}

		$upload = wp_upload_dir();
		if ( ! empty( $upload['error'] ) ) {
			self::php_error_log_once( 'wp_upload_dir error: ' . $upload['error'] );
			return false;
		}

		$candidates = array(
			array(
				'abs' => trailingslashit( $upload['basedir'] ) . 'wc-logs',
				'rel' => self::relative_to_abspath( trailingslashit( $upload['basedir'] ) . 'wc-logs' ),
			),
			array(
				'abs' => trailingslashit( $upload['basedir'] ) . 'rentopian-logs',
				'rel' => self::relative_to_abspath( trailingslashit( $upload['basedir'] ) . 'rentopian-logs' ),
			),
		);

		foreach ( $candidates as $candidate ) {
			$abs = $candidate['abs'];
			if ( ! file_exists( $abs ) ) {
				if ( ! wp_mkdir_p( $abs ) ) {
					continue;
				}
			}
			if ( ! is_writable( $abs ) ) {
				@chmod( $abs, 0775 );
				if ( ! is_writable( $abs ) ) {
					continue;
				}
			}
			self::protect_directory( $abs );
			self::$log_dir_abs = $abs;
			self::$log_dir_rel = $candidate['rel'];
			self::$verified    = true;
			return $abs;
		}

		self::php_error_log_once( 'Rentopian_Order_Logger: no writable log dir among candidates.' );
		return false;
	}

	private static function protect_directory( $abs_dir ) {
		$htaccess = trailingslashit( $abs_dir ) . '.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			@file_put_contents( $htaccess, "Order Deny,Allow\nDeny from all\n" );
		}
		$index = trailingslashit( $abs_dir ) . 'index.html';
		if ( ! file_exists( $index ) ) {
			@file_put_contents( $index, '' );
		}
	}

	private static function relative_to_abspath( $abs ) {
		$abspath_norm = wp_normalize_path( ABSPATH );
		$abs_norm     = wp_normalize_path( $abs );
		if ( 0 === strpos( $abs_norm, $abspath_norm ) ) {
			return ltrim( substr( $abs_norm, strlen( $abspath_norm ) ), '/\\' );
		}
		return $abs_norm;
	}

	private static function log_file_rel() {
		if ( false === self::resolve_log_dir() ) {
			return false;
		}
		return trailingslashit( self::$log_dir_rel ) . self::FILE_PREFIX . gmdate( 'Y-m-d' ) . '.log';
	}

	private static $error_log_written = array();
	private static function php_error_log_once( $msg ) {
		$key = md5( $msg );
		if ( isset( self::$error_log_written[ $key ] ) ) {
			return;
		}
		self::$error_log_written[ $key ] = true;
		error_log( '[rentopian-sync] ' . $msg );
	}

	// ---------------------------------------------------------------------
	// Formatting
	// ---------------------------------------------------------------------

	private static function format_context( array $context ) {
		$parts = array();
		foreach ( $context as $k => $v ) {
			if ( is_array( $v ) || is_object( $v ) ) {
				$v = wp_json_encode( $v );
			} elseif ( is_bool( $v ) ) {
				$v = $v ? 'true' : 'false';
			} elseif ( null === $v ) {
				$v = 'null';
			} else {
				$v = (string) $v;
			}
			if ( strlen( $v ) > 500 ) {
				$v = substr( $v, 0, 497 ) . '...';
			}
			$v       = str_replace( array( "\n", "\r", '|' ), array( ' ', ' ', '/' ), $v );
			$parts[] = $k . '=' . $v;
		}
		return implode( ' | ', $parts );
	}

	// ---------------------------------------------------------------------
	// Core write
	// ---------------------------------------------------------------------

	public static function log( $order_id, $event, array $context = array(), $level = 'info' ) {
		$rel = self::log_file_rel();

		$line = sprintf(
			'%s | wp_id=%s%s',
			$event,
			( '' !== (string) $order_id ) ? $order_id : 'n/a',
			$context ? ' | ' . self::format_context( $context ) : ''
		);

		if ( class_exists( 'Project_WP_Logger', false ) ) {
			Project_WP_Logger::write( $line, $level, self::SOURCE, $rel ?: null );
		} else {
			self::direct_write( $line, $level );
		}
	}

	private static function direct_write( $line, $level ) {
		$dir = self::resolve_log_dir();
		if ( false === $dir ) {
			self::php_error_log_once( 'fallback write: no dir. Line: ' . $line );
			return;
		}
		$file = trailingslashit( $dir ) . self::FILE_PREFIX . gmdate( 'Y-m-d' ) . '.log';
		@file_put_contents(
			$file,
			sprintf( "%s [%s] %s%s", gmdate( 'c' ), $level, $line, PHP_EOL ),
			FILE_APPEND | LOCK_EX
		);
	}

	// ---------------------------------------------------------------------
	// Lifecycle helpers — one method per flow stage
	// ---------------------------------------------------------------------

	public static function order_start( $order_id, array $context = array() ) {
		self::log( $order_id, 'ORDER_START', $context, 'info' );
	}

	/**
	 * IDEMPOTENCY skip — the order is already synced to Rentopian. This is
	 * the ONLY legitimate "skip" in the flow.
	 */
	public static function order_already_synced( $order_id, $rental_id ) {
		self::log( $order_id, 'ORDER_ALREADY_SYNCED', array( 'rental_id' => $rental_id ), 'info' );
	}

	public static function item_skip( $order_id, $product_id, $reason, array $context = array() ) {
		$context = array_merge( array( 'product_id' => $product_id, 'reason' => $reason ), $context );
		self::log( $order_id, 'ITEM_SKIP', $context, 'debug' );
	}

	/**
	 * A set (package) add was blocked because a member item is unavailable for
	 * the selected dates while overbooking is disabled. Keeps incomplete packages
	 * out of the order (and out of Rentopian).
	 *
	 * @param int|string $order_id       Order id when known, otherwise 'n/a' (cart/checkout stage).
	 * @param int        $set_product_id The set (package) product id.
	 */
	public static function set_add_blocked( $order_id, $set_product_id, array $context = array() ) {
		$context = array_merge( array( 'set_product_id' => $set_product_id ), $context );
		self::log( $order_id, 'SET_ADD_BLOCKED', $context, 'warning' );
	}

	/**
	 * A set (package) member would have been dropped under the legacy logic, but
	 * overbooking is enabled so it is kept — every set child must reach the order.
	 *
	 * @param int|string $order_id       Order id when known, otherwise 'n/a' (cart stage).
	 * @param int        $set_product_id The set (package) product id.
	 */
	public static function set_item_kept_overbook( $order_id, $set_product_id, array $context = array() ) {
		$context = array_merge( array( 'set_product_id' => $set_product_id ), $context );
		self::log( $order_id, 'SET_ITEM_KEPT_OVERBOOK', $context, 'debug' );
	}

	public static function cookie( $order_id, $cookie_name, $present, $decrypted_ok, array $context = array() ) {
		$context = array_merge(
			array( 'cookie' => $cookie_name, 'present' => $present, 'decrypted_ok' => $decrypted_ok ),
			$context
		);
		self::log( $order_id, 'COOKIE', $context, $present && $decrypted_ok ? 'debug' : 'warning' );
	}

	public static function date_check( $order_id, $field, $valid, array $context = array() ) {
		$context = array_merge( array( 'field' => $field, 'valid' => $valid ), $context );
		self::log( $order_id, 'DATE_CHECK', $context, $valid ? 'debug' : 'warning' );
	}

	/**
	 * Healing event — a value was substituted because the input was missing
	 * or invalid. Also queues an incident for the email summary.
	 */
	public static function heal( $order_id, $field, array $heal_result ) {
		$context = array(
			'field'    => $field,
			'source'   => $heal_result['source'] ?? 'unknown',
			'reason'   => $heal_result['reason'] ?? null,
			'original' => isset( $heal_result['original'] ) && is_string( $heal_result['original'] )
				? substr( $heal_result['original'], 0, 80 )
				: ( $heal_result['original'] ?? null ),
			'healed_to' => isset( $heal_result['value'] ) && is_scalar( $heal_result['value'] )
				? (string) $heal_result['value']
				: '[complex]',
		);
		self::log( $order_id, 'HEAL', $context, 'warning' );

		if ( class_exists( 'Rentopian_Incident_Reporter', false ) ) {
			Rentopian_Incident_Reporter::queue( $order_id, 'heal:' . $field, $context );
		}
	}

	/**
	 * Generic incident — log + queue for email summary. Use for failures the
	 * healer couldn't address but where the sync still proceeds.
	 */
	public static function incident( $order_id, $type, array $context = array(), $level = 'error' ) {
		self::log( $order_id, 'INCIDENT_' . strtoupper( $type ), $context, $level );
		if ( class_exists( 'Rentopian_Incident_Reporter', false ) ) {
			Rentopian_Incident_Reporter::queue( $order_id, $type, $context );
		}
	}

	public static function dates_resolved( $order_id, $start, $end, $days, array $context = array() ) {
		$context = array_merge( array( 'start' => $start, 'end' => $end, 'days' => $days ), $context );
		self::log( $order_id, 'DATES_RESOLVED', $context, 'info' );
	}

	public static function order_send( $order_id, array $context = array() ) {
		self::log( $order_id, 'ORDER_SEND', $context, 'info' );
	}

	public static function payload_dump( $order_id, array $payload ) {
		$dir = self::resolve_log_dir();
		if ( false === $dir ) {
			return;
		}
		$file = trailingslashit( $dir ) . self::FILE_PREFIX . 'payload-' . (int) $order_id . '-' . gmdate( 'Ymd-His' ) . '.json';
		@file_put_contents( $file, wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ), LOCK_EX );
		self::log( $order_id, 'PAYLOAD_DUMP', array( 'file' => basename( $file ) ), 'info' );
	}

	public static function order_done( $order_id, array $context = array() ) {
		self::log( $order_id, 'ORDER_DONE', $context, 'info' );
	}

	public static function order_exception( $order_id, $e, array $context = array() ) {
		$context = array_merge(
			array(
				'class'   => is_object( $e ) ? get_class( $e ) : gettype( $e ),
				'message' => is_object( $e ) && method_exists( $e, 'getMessage' ) ? $e->getMessage() : (string) $e,
				'file'    => is_object( $e ) && method_exists( $e, 'getFile' ) ? $e->getFile() : '',
				'line'    => is_object( $e ) && method_exists( $e, 'getLine' ) ? $e->getLine() : 0,
			),
			$context
		);
		self::log( $order_id, 'ORDER_EXCEPTION', $context, 'critical' );
		if ( class_exists( 'Rentopian_Incident_Reporter', false ) ) {
			Rentopian_Incident_Reporter::queue( $order_id, 'exception', $context );
		}
	}

	/**
	 * Flush incident emails at end of the order flow. Call this in a `finally`
	 * block in rental_create_order().
	 */
	public static function dispatch_incidents( $order_id ) {
		if ( class_exists( 'Rentopian_Incident_Reporter', false ) ) {
			$count = Rentopian_Incident_Reporter::queued_count( $order_id );
			if ( $count > 0 ) {
				self::log( $order_id, 'INCIDENT_DISPATCH_BEGIN', array( 'incident_count' => $count ), 'info' );
			}
			Rentopian_Incident_Reporter::dispatch( $order_id );
		}
	}
}

endif;
