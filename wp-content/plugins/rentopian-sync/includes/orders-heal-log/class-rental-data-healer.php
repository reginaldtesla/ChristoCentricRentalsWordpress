<?php
/**
 * Rental Data Healer
 *
 * Centralized "never fail" healing logic. Every method takes whatever the
 * caller could find and returns a guaranteed-usable value, with metadata
 * describing what (if anything) had to be substituted.
 *
 * Return shape (every healing method):
 * ------------------------------------
 *   array(
 *     'value'    => string,                      // The guaranteed-usable result.
 *     'healed'   => bool,                        // true if a fallback had to be used.
 *     'source'   => string,                      // SOURCE_* constant.
 *     'original' => mixed,                       // Whatever was originally supplied.
 *     'reason'   => string|null,                 // Diagnostic, populated when healed=true.
 *   )
 *
 * Fallback priority (start date):
 *   1. SOURCE_INPUT           — caller-supplied value, if valid.
 *   2. SOURCE_ORDER_META      — pre-existing _rental_start_date meta on the order.
 *   3. SOURCE_WC_DATE_CREATED — order's date_created + default start time.
 *   4. SOURCE_TODAY           — current_time('Y/m/d') + default start time.
 *
 * Fallback priority (end date):
 *   1. SOURCE_INPUT           — if valid AND chronologically after start.
 *   2. SOURCE_ORDER_META      — if valid AND chronologically after start.
 *   3. SOURCE_DERIVED         — derive from start + default end time.
 *
 * For ZIP: cookie → billing postcode → empty string.
 *
 * Every healing event is meant to be logged by the caller via
 * Rentopian_Order_Logger::heal() and queued as an incident via
 * Rentopian_Incident_Reporter::queue() so the support team sees it.
 *
 * @package rentopian-sync
 * @since   1.x.x
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Rental_Data_Healer', false ) ) :

class Rental_Data_Healer {

	const SOURCE_INPUT           = 'input';
	const SOURCE_ORDER_META      = 'order_meta';
	const SOURCE_WC_DATE_CREATED = 'wc_date_created';
	const SOURCE_DERIVED         = 'derived_from_start';
	const SOURCE_TODAY           = 'today_with_default_time';
	const SOURCE_BILLING         = 'wc_billing_postcode';
	const SOURCE_EMPTY           = 'empty_fallback';

	/**
	 * Heal a rental start date.
	 *
	 * @param mixed    $input        Raw input (decrypted cookie value, etc.)
	 * @param WC_Order $wc_order
	 * @param string   $default_time Default start time setting, e.g. "09:00 AM".
	 * @return array
	 */
	public static function heal_start_date( $input, $wc_order, $default_time = '09:00 AM' ) {

		// 1) Caller-supplied value, if valid.
		if ( Rental_Date_Validator::is_valid( $input ) ) {
			$normalized = Rental_Date_Validator::normalize( $input, $default_time );
			if ( false !== $normalized ) {
				return self::ok( $normalized, $input, self::SOURCE_INPUT );
			}
		}

		$diagnose = Rental_Date_Validator::diagnose( $input );

		// 2) Pre-existing order meta (e.g. previous sync attempt).
		$meta = get_post_meta( $wc_order->get_id(), '_rental_start_date', true );
		if ( Rental_Date_Validator::is_valid( $meta ) ) {
			$normalized = Rental_Date_Validator::normalize( $meta, $default_time );
			if ( false !== $normalized ) {
				return self::healed( $normalized, $input, self::SOURCE_ORDER_META, $diagnose['reason'] ?? 'input_invalid' );
			}
		}

		// 3) WC order date_created with default start time.
		$created = $wc_order->get_date_created();
		if ( $created instanceof DateTime || ( class_exists( 'WC_DateTime' ) && $created instanceof WC_DateTime ) ) {
			$time_part = self::safe_time( $default_time, '09:00 AM' );
			$value     = $created->format( 'Y/m/d' ) . ' ' . $time_part;
			$norm      = Rental_Date_Validator::normalize( $value );
			if ( false !== $norm ) {
				return self::healed( $norm, $input, self::SOURCE_WC_DATE_CREATED, $diagnose['reason'] ?? 'input_invalid' );
			}
		}

		// 4) Last resort: today + default start time.
		$time_part = self::safe_time( $default_time, '09:00 AM' );
		$value     = current_time( 'Y/m/d' ) . ' ' . $time_part;
		$norm      = Rental_Date_Validator::normalize( $value );
		if ( false === $norm ) {
			// Shouldn't happen, but guarantee a return value.
			$norm = current_time( 'Y/m/d' ) . ' 09:00am';
		}
		return self::healed( $norm, $input, self::SOURCE_TODAY, $diagnose['reason'] ?? 'input_invalid' );
	}

	/**
	 * Heal a rental end date, given the already-healed start date.
	 *
	 * @param mixed    $input              Raw input.
	 * @param string   $healed_start_value Canonical start date string (must be valid).
	 * @param WC_Order $wc_order
	 * @param string   $default_end_time   e.g. "05:00 PM".
	 * @return array
	 */
	public static function heal_end_date( $input, $healed_start_value, $wc_order, $default_end_time = '05:00 PM' ) {

		$start_ts = Rental_Date_Validator::is_valid( $healed_start_value )
			? strtotime( $healed_start_value )
			: false;

		// 1) Input is valid AND chronologically >= start.
		if ( Rental_Date_Validator::is_valid( $input ) ) {
			$normalized = Rental_Date_Validator::normalize( $input, $default_end_time );
			if ( false !== $normalized ) {
				$end_ts = strtotime( $normalized );
				if ( false !== $end_ts && ( false === $start_ts || $end_ts >= $start_ts ) ) {
					return self::ok( $normalized, $input, self::SOURCE_INPUT );
				}
			}
		}

		$diagnose = Rental_Date_Validator::diagnose( $input );

		// 2) Order meta with chronology check.
		$meta = get_post_meta( $wc_order->get_id(), '_rental_end_date', true );
		if ( Rental_Date_Validator::is_valid( $meta ) ) {
			$normalized = Rental_Date_Validator::normalize( $meta, $default_end_time );
			if ( false !== $normalized ) {
				$end_ts = strtotime( $normalized );
				if ( false !== $end_ts && ( false === $start_ts || $end_ts >= $start_ts ) ) {
					return self::healed( $normalized, $input, self::SOURCE_ORDER_META, $diagnose['reason'] ?? 'input_invalid_or_non_chronological' );
				}
			}
		}

		// 3) Derive from start: same day with end time, or +1 day if that's earlier than start.
		return self::derive_end_from_start( $healed_start_value, $default_end_time, $input, $diagnose['reason'] ?? 'input_invalid_or_non_chronological' );
	}

	/**
	 * Derive an end date from a known-good start date.
	 */
	private static function derive_end_from_start( $start_value, $end_time, $original_input, $reason ) {
		$start_ts = strtotime( $start_value );
		if ( false === $start_ts ) {
			$start_ts = strtotime( current_time( 'Y/m/d 09:00am' ) );
		}
		$time_part = self::safe_time( $end_time, '05:00 PM' );
		$candidate = date( 'Y/m/d', $start_ts ) . ' ' . $time_part;
		$cand_ts   = strtotime( $candidate );
		if ( false === $cand_ts || $cand_ts < $start_ts ) {
			$candidate = date( 'Y/m/d', strtotime( '+1 day', $start_ts ) ) . ' ' . $time_part;
		}
		$norm = Rental_Date_Validator::normalize( $candidate );
		if ( false === $norm ) {
			$norm = $candidate;
		}
		return self::healed( $norm, $original_input, self::SOURCE_DERIVED, $reason );
	}

	/**
	 * Heal the ZIP code used for delivery/division lookup.
	 *
	 * @param mixed    $cookie_raw     Encrypted cookie value (or empty).
	 * @param string   $encryption_key
	 * @param WC_Order $wc_order
	 * @return array
	 */
	public static function heal_zip( $cookie_raw, $encryption_key, $wc_order ) {
		if ( ! empty( $cookie_raw ) && function_exists( 'decrypt_data' ) ) {
			$decrypted = (string) decrypt_data( $cookie_raw, $encryption_key );
			$decrypted = trim( $decrypted );
			if ( '' !== $decrypted ) {
				return self::ok( $decrypted, $cookie_raw, self::SOURCE_INPUT );
			}
		}

		$billing = trim( (string) $wc_order->get_billing_postcode() );
		if ( '' !== $billing ) {
			return self::healed( $billing, $cookie_raw, self::SOURCE_BILLING, 'cookie_missing_or_empty' );
		}

		return self::healed( '', $cookie_raw, self::SOURCE_EMPTY, 'no_zip_available' );
	}

	/**
	 * Ensure chronological order. If start > end, swap or extend.
	 * Returns the healed [start, end] pair plus a flag.
	 */
	public static function ensure_chronological( $start, $end, $default_end_time = '05:00 PM' ) {
		if ( ! Rental_Date_Validator::is_valid( $start ) || ! Rental_Date_Validator::is_valid( $end ) ) {
			return array(
				'start'  => $start,
				'end'    => $end,
				'healed' => false,
				'reason' => 'one_or_both_invalid',
			);
		}
		$s = strtotime( $start );
		$e = strtotime( $end );
		if ( false === $s || false === $e || $s <= $e ) {
			return array(
				'start'  => $start,
				'end'    => $end,
				'healed' => false,
				'reason' => null,
			);
		}

		// Repair: derive end from start.
		$time_part   = self::safe_time( $default_end_time, '05:00 PM' );
		$new_end     = date( 'Y/m/d', $s ) . ' ' . $time_part;
		$new_end_ts  = strtotime( $new_end );
		if ( false === $new_end_ts || $new_end_ts < $s ) {
			$new_end = date( 'Y/m/d', strtotime( '+1 day', $s ) ) . ' ' . $time_part;
		}
		$norm = Rental_Date_Validator::normalize( $new_end );
		return array(
			'start'  => $start,
			'end'    => false !== $norm ? $norm : $new_end,
			'healed' => true,
			'reason' => 'end_before_start',
		);
	}

	// ---- Helpers ----------------------------------------------------------

	private static function safe_time( $time, $default ) {
		$norm = Rental_Date_Validator::normalize_time_component( $time );
		if ( false !== $norm ) {
			return $norm;
		}
		$norm = Rental_Date_Validator::normalize_time_component( $default );
		return false !== $norm ? $norm : '09:00 AM';
	}

	private static function ok( $value, $original, $source ) {
		return array(
			'value'    => $value,
			'healed'   => false,
			'source'   => $source,
			'original' => is_string( $original ) ? substr( $original, 0, 80 ) : $original,
			'reason'   => null,
		);
	}

	private static function healed( $value, $original, $source, $reason ) {
		return array(
			'value'    => $value,
			'healed'   => true,
			'source'   => $source,
			'original' => is_string( $original ) ? substr( $original, 0, 80 ) : $original,
			'reason'   => $reason,
		);
	}
}

endif;
