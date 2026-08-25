<?php
/**
 * Rental Date Validator
 *
 * Strict validation gate for rental date strings.
 *
 * Purpose: cleanly separate "this string is a usable date" from "this string is
 * garbage". The healer (Rental_Data_Healer) consults this class to decide
 * whether to use a value as-is or substitute a fallback.
 *
 * This class never mutates data on its own. It only inspects and reports.
 *
 * @package rentopian-sync
 * @since   1.x.x
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Rental_Date_Validator', false ) ) :

class Rental_Date_Validator {

	/** @var string[] */
	private static $date_only_formats = array(
		'Y/m/d', 'Y-m-d', 'd/m/Y', 'm/d/Y', 'd-m-Y', 'm-d-Y',
	);

	/** @var string[] */
	private static $datetime_formats = array(
		'Y/m/d h:ia', 'Y/m/d h:i a', 'Y/m/d h:iA', 'Y/m/d h:i A',
		'Y/m/d H:i', 'Y-m-d h:ia', 'Y-m-d h:i a', 'Y-m-d H:i:s', 'Y-m-d H:i',
	);

	/**
	 * Strict validity check.
	 *
	 * Rejects fragments like "Jun", "Jun 5, 2026", and the specific
	 * "Month TT:TT AM" shape produced by the legacy explode() corruption.
	 */
	public static function is_valid( $value ) {
		if ( ! is_string( $value ) ) {
			return false;
		}
		$value = trim( $value );
		if ( '' === $value ) {
			return false;
		}

		// Hard rejection for the legacy corruption shape.
		if ( preg_match( '/^[A-Za-z]{3,9}\s+\d{1,2}:\d{2}\s*[AaPp][Mm]?$/', $value ) ) {
			return false;
		}

		foreach ( array_merge( self::$datetime_formats, self::$date_only_formats ) as $format ) {
			$dt = DateTime::createFromFormat( $format, $value );
			if ( $dt instanceof DateTime ) {
				$errors = DateTime::getLastErrors();
				if ( false === $errors || ( is_array( $errors ) && 0 === $errors['warning_count'] && 0 === $errors['error_count'] ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Normalize a valid string into canonical "Y/m/d h:ia".
	 *
	 * @param mixed       $value
	 * @param string|null $default_time Used only for date-only inputs.
	 * @return string|false
	 */
	public static function normalize( $value, $default_time = null ) {
		if ( ! is_string( $value ) ) {
			return false;
		}
		$value = trim( $value );
		if ( '' === $value || ! self::is_valid( $value ) ) {
			return false;
		}

		foreach ( self::$datetime_formats as $format ) {
			$dt = DateTime::createFromFormat( $format, $value );
			if ( $dt instanceof DateTime ) {
				return $dt->format( 'Y/m/d h:ia' );
			}
		}

		if ( null === $default_time ) {
			return false;
		}
		$default_time_norm = self::normalize_time_component( $default_time );
		if ( false === $default_time_norm ) {
			return false;
		}

		foreach ( self::$date_only_formats as $format ) {
			$dt = DateTime::createFromFormat( $format . '|', $value );
			if ( $dt instanceof DateTime ) {
				$combined = $dt->format( 'Y/m/d' ) . ' ' . $default_time_norm;
				$check    = DateTime::createFromFormat( 'Y/m/d h:i A', $combined );
				if ( $check instanceof DateTime ) {
					return $check->format( 'Y/m/d h:ia' );
				}
			}
		}
		return false;
	}

	public static function normalize_time_component( $time ) {
		if ( ! is_string( $time ) ) {
			return false;
		}
		$time = trim( $time );
		if ( '' === $time ) {
			return false;
		}
		$formats = array( 'h:i A', 'h:iA', 'h:i a', 'h:ia', 'g:i A', 'g:i a', 'H:i', 'H:i:s' );
		foreach ( $formats as $format ) {
			$dt = DateTime::createFromFormat( $format, $time );
			if ( $dt instanceof DateTime ) {
				return $dt->format( 'h:i A' );
			}
		}
		return false;
	}

	public static function extract_date_part( $value ) {
		if ( ! self::is_valid( $value ) ) {
			return false;
		}
		foreach ( array_merge( self::$datetime_formats, self::$date_only_formats ) as $format ) {
			$dt = DateTime::createFromFormat( $format, $value );
			if ( $dt instanceof DateTime ) {
				return $dt->format( 'Y/m/d' );
			}
		}
		return false;
	}

	public static function is_chronological( $start, $end ) {
		if ( ! self::is_valid( $start ) || ! self::is_valid( $end ) ) {
			return false;
		}
		$s = strtotime( $start );
		$e = strtotime( $end );
		if ( false === $s || false === $e ) {
			return false;
		}
		return $s <= $e;
	}

	public static function diagnose( $value ) {
		if ( ! is_string( $value ) ) {
			return array(
				'type'   => gettype( $value ),
				'length' => 0,
				'valid'  => false,
				'reason' => 'not_a_string',
			);
		}
		$trimmed = trim( $value );
		$len     = strlen( $trimmed );
		$report  = array(
			'type'    => 'string',
			'length'  => $len,
			'valid'   => self::is_valid( $trimmed ),
			'preview' => $len > 0 ? substr( $trimmed, 0, 40 ) : '',
		);
		if ( ! $report['valid'] ) {
			if ( '' === $trimmed ) {
				$report['reason'] = 'empty';
			} elseif ( preg_match( '/^[A-Za-z]{3,9}\s+\d{1,2}:\d{2}\s*[AaPp][Mm]?$/', $trimmed ) ) {
				$report['reason'] = 'malformed_month_plus_time_only';
			} elseif ( ! preg_match( '/\d/', $trimmed ) ) {
				$report['reason'] = 'no_digits';
			} else {
				$report['reason'] = 'unrecognized_format';
			}
		}
		return $report;
	}
}

endif;
