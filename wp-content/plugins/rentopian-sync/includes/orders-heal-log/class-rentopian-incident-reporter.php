<?php
/**
 * Rentopian Incident Reporter
 *
 * Collects healing/error incidents during a single order sync and dispatches
 * ONE summary email per order (instead of N emails per healing event).
 *
 * @package rentopian-sync
 * @since   1.x.x
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Rentopian_Incident_Reporter', false ) ) :

class Rentopian_Incident_Reporter {

	const DEFAULT_RECIPIENT      = 'babakhani.aarony@gmail.com';
	const OPTION_RECIPIENTS      = 'rental_incident_email_recipients';
	const OPTION_ENABLED         = 'rental_incident_emails_enabled';
	const DEDUPE_TTL_SECONDS     = 1800;  // 30 min throttle per order+type-set.

	/**
	 * Queued incidents, keyed by order_id.
	 *
	 * @var array<int, array<int, array{type:string, context:array, timestamp:int}>>
	 */
	private static $queue = array();

	/**
	 * Queue an incident for an order.
	 */
	public static function queue( $order_id, $type, array $context = array() ) {
		$order_id = (int) $order_id;
		if ( ! isset( self::$queue[ $order_id ] ) ) {
			self::$queue[ $order_id ] = array();
		}
		self::$queue[ $order_id ][] = array(
			'type'      => (string) $type,
			'context'   => $context,
			'timestamp' => time(),
		);
		self::ensure_shutdown_handler();
	}

	/**
	 * Number of incidents currently queued for an order.
	 */
	public static function queued_count( $order_id ) {
		$order_id = (int) $order_id;
		return isset( self::$queue[ $order_id ] ) ? count( self::$queue[ $order_id ] ) : 0;
	}

	/**
	 * Dispatch the summary email for an order (if any incidents are queued)
	 * and clear the queue.
	 *
	 * @return bool True if an email was sent OR no incidents existed; false on send failure.
	 */
	public static function dispatch( $order_id ) {
		$order_id  = (int) $order_id;
		$incidents = isset( self::$queue[ $order_id ] ) ? self::$queue[ $order_id ] : array();
		unset( self::$queue[ $order_id ] );

		if ( empty( $incidents ) ) {
			return true;
		}

		// Kill switch.
		$enabled = get_option( self::OPTION_ENABLED, true );
		if ( ! $enabled ) {
			self::log_dispatch_event( $order_id, 'EMAIL_DISABLED_BY_OPTION', count( $incidents ) );
			return true;
		}

		// Throttle.
		$type_signature = self::signature_for_throttle( $incidents );
		$dedupe_key     = 'rentopian_inc_' . $order_id . '_' . substr( $type_signature, 0, 12 );
		if ( get_transient( $dedupe_key ) ) {
			self::log_dispatch_event( $order_id, 'EMAIL_THROTTLED', count( $incidents ), array( 'dedupe_key' => $dedupe_key ) );
			return true;
		}
		set_transient( $dedupe_key, 1, self::DEDUPE_TTL_SECONDS );

		$recipients = self::get_recipients();
		if ( empty( $recipients ) ) {
			self::log_dispatch_event( $order_id, 'EMAIL_NO_RECIPIENTS', count( $incidents ) );
			return false;
		}

		$subject = self::build_subject( $order_id, $incidents );
		$body    = self::build_body( $order_id, $incidents );
		$headers = array(
			'Content-Type: text/html; charset=UTF-8',
			'X-Rentopian-Incident: 1',
			'X-Rentopian-Order-Id: ' . $order_id,
		);

		$sent = wp_mail( $recipients, $subject, $body, $headers );

		self::log_dispatch_event(
			$order_id,
			$sent ? 'EMAIL_SENT' : 'EMAIL_FAILED',
			count( $incidents ),
			array(
				'recipients'      => count( $recipients ),
				'incident_types'  => implode( ',', wp_list_pluck( $incidents, 'type' ) ),
			)
		);

		return (bool) $sent;
	}

	/**
	 * Resolve the recipient list.
	 *
	 * @return string[] Valid email addresses, deduplicated.
	 */
	public static function get_recipients() {
		$stored = (string) get_option( self::OPTION_RECIPIENTS, '' );
		$list   = array_filter( array_map( 'trim', explode( ',', $stored ) ) );

		// Default recipient is always included unless filter removes it explicitly.
		if ( ! in_array( self::DEFAULT_RECIPIENT, $list, true ) ) {
			$list[] = self::DEFAULT_RECIPIENT;
		}

		/**
		 * Filter the incident email recipients.
		 *
		 * @param string[] $list Email addresses.
		 */
		$list = apply_filters( 'rental_incident_email_recipients', $list );

		$list = array_filter( (array) $list, function ( $e ) {
			return is_string( $e ) && is_email( $e );
		} );

		return array_values( array_unique( $list ) );
	}

	/**
	 * Public alias of queue() + immediate dispatch — useful for catastrophic
	 * events where you don't want to wait for a normal dispatch.
	 */
	public static function report_now( $order_id, $type, array $context = array() ) {
		self::queue( $order_id, $type, $context );
		return self::dispatch( $order_id );
	}

	// =====================================================================
	// Internals
	// =====================================================================

	private static function signature_for_throttle( array $incidents ) {
		$types = array_unique( wp_list_pluck( $incidents, 'type' ) );
		sort( $types );
		return md5( implode( '|', $types ) );
	}

	private static function build_subject( $order_id, array $incidents ) {
		$site   = wp_parse_url( home_url(), PHP_URL_HOST );
		$types  = array_unique( wp_list_pluck( $incidents, 'type' ) );
		$tagged = '[' . count( $incidents ) . ']';
		return sprintf( '[Rentopian Sync Incident] %s Order #%d on %s — %s', $tagged, $order_id, $site, implode( ', ', $types ) );
	}

	private static function build_body( $order_id, array $incidents ) {
		$wc_order  = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
		$site_url  = home_url();
		$site_name = get_bloginfo( 'name' );
		$admin_url = admin_url( 'post.php?post=' . $order_id . '&action=edit' );
		$log_path  = 'wp-content/uploads/wc-logs/rentopian-orders-' . gmdate( 'Y-m-d' ) . '.log';
		$now_utc   = gmdate( 'Y-m-d H:i:s' ) . ' UTC';

		$customer_block = '';
		if ( $wc_order ) {
			$created = $wc_order->get_date_created();
			$customer_block = sprintf(
				'<tr><td><strong>Customer</strong></td><td>%s</td></tr>
				 <tr><td><strong>Email</strong></td><td>%s</td></tr>
				 <tr><td><strong>Phone</strong></td><td>%s</td></tr>
				 <tr><td><strong>Total</strong></td><td>%s</td></tr>
				 <tr><td><strong>Status</strong></td><td>%s</td></tr>
				 <tr><td><strong>Created</strong></td><td>%s</td></tr>',
				esc_html( trim( $wc_order->get_billing_first_name() . ' ' . $wc_order->get_billing_last_name() ) ),
				esc_html( $wc_order->get_billing_email() ),
				esc_html( $wc_order->get_billing_phone() ),
				esc_html( html_entity_decode( wp_strip_all_tags( wc_price( $wc_order->get_total() ) ) ) ),
				esc_html( $wc_order->get_status() ),
				esc_html( $created instanceof DateTime ? $created->format( 'Y-m-d H:i:s T' ) : 'n/a' )
			);
		}

		$rows = '';
		foreach ( $incidents as $i => $inc ) {
			$rows .= sprintf(
				'<tr><td valign="top" style="padding:6px 10px;border-top:1px solid #ddd"><strong>#%d</strong></td>
				 <td valign="top" style="padding:6px 10px;border-top:1px solid #ddd"><strong>%s</strong><br><pre style="white-space:pre-wrap;margin:6px 0 0;font-size:12px">%s</pre></td></tr>',
				$i + 1,
				esc_html( $inc['type'] ),
				esc_html( wp_json_encode( $inc['context'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) )
			);
		}

		return '<!doctype html><html><body style="font-family:Arial,sans-serif;font-size:14px;color:#222;max-width:800px">
			<h2 style="margin:0 0 6px">Rentopian Sync Incident</h2>
			<p style="margin:0 0 14px;color:#555">An order required automatic data healing before sync. The order WAS sent to Rentopian with the healed values shown below. Verify on the Rentopian side and adjust if needed.</p>

			<table cellspacing="0" cellpadding="6" style="border-collapse:collapse;border:1px solid #ddd;width:100%;margin-bottom:18px">
				<tr><td width="180"><strong>Site</strong></td><td>' . esc_html( $site_name ) . ' &mdash; <a href="' . esc_url( $site_url ) . '">' . esc_html( $site_url ) . '</a></td></tr>
				<tr><td><strong>WP Order ID</strong></td><td>#' . esc_html( $order_id ) . ' &mdash; <a href="' . esc_url( $admin_url ) . '">open in admin</a></td></tr>
				<tr><td><strong>Incident Time</strong></td><td>' . esc_html( $now_utc ) . '</td></tr>
				<tr><td><strong>Incident Count</strong></td><td>' . count( $incidents ) . '</td></tr>
				' . $customer_block . '
				<tr><td><strong>Log File</strong></td><td><code>' . esc_html( $log_path ) . '</code><br><small>Accessible via WP file manager plugins (no FTP needed).</small></td></tr>
			</table>

			<h3 style="margin:0 0 6px">Incidents</h3>
			<table cellspacing="0" cellpadding="0" style="border-collapse:collapse;border:1px solid #ddd;width:100%">
				' . $rows . '
			</table>

			<p style="margin-top:18px;color:#777;font-size:12px">This message was sent automatically by the rentopian-sync plugin. To change recipients, update the <code>rental_incident_email_recipients</code> option (comma-separated). To disable, set <code>rental_incident_emails_enabled</code> to <code>0</code>.</p>
		</body></html>';
	}

	private static function log_dispatch_event( $order_id, $event, $count, array $extra = array() ) {
		if ( class_exists( 'Rentopian_Order_Logger', false ) ) {
			Rentopian_Order_Logger::log(
				$order_id,
				'INCIDENT_DISPATCH_' . $event,
				array_merge( array( 'incident_count' => $count ), $extra ),
				strpos( $event, 'FAILED' ) !== false ? 'error' : 'info'
			);
		}
	}

	/**
	 * Register a shutdown handler exactly once that auto-dispatches any
	 * queued incidents (catches fatal errors mid-flow).
	 *
	 * Uses a static local flag for once-per-request idempotency. Compatible
	 * with PHP 7.0+ — register_shutdown_function is core.
	 */
	private static function ensure_shutdown_handler() {
		static $registered = false;
		if ( $registered ) {
			return;
		}
		$registered = true;
		register_shutdown_function( array( __CLASS__, 'shutdown_flush' ) );
	}

	/**
	 * Shutdown safety net: dispatch any queued incidents that weren't manually flushed.
	 */
	public static function shutdown_flush() {
		if ( empty( self::$queue ) ) {
			return;
		}
		foreach ( array_keys( self::$queue ) as $order_id ) {
			// Append a synthetic incident noting we hit shutdown without dispatch.
			self::$queue[ $order_id ][] = array(
				'type'      => 'flushed_at_shutdown',
				'context'   => array( 'note' => 'caller did not call dispatch(); possible fatal error mid-flow' ),
				'timestamp' => time(),
			);
			self::dispatch( $order_id );
		}
	}
}

endif;
