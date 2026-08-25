<?php

class RTDelivery {

	/**
	 * Apply default times to start/end where the UI hides the time pickers.
	 *
	 * Behavior matrix (when $opt_hide_time_pickers or $opt_hide_end_date is on):
	 *   - input valid    → return canonical date_part + default_time
	 *   - input invalid  → return input unchanged so the healer can deal with it
	 *   - validator missing → original explode() fallback (legacy behavior)
	 */
	public static function extractFinalRentalStartEndDate(
		$rental_start_date,
		$rental_end_date,
		$opt_hide_time_pickers,
		$opt_default_start_time,
		$opt_default_end_time,
		$opt_hide_end_date
	) {
		$validator_available = class_exists( 'Rental_Date_Validator', false );

		// START DATE adjustment.
		if ( $rental_start_date && $opt_hide_time_pickers ) {
			if ( $validator_available ) {
				$date_part = Rental_Date_Validator::extract_date_part( $rental_start_date );
				if ( false !== $date_part ) {
					$rental_start_date = $date_part . ' ' . $opt_default_start_time;
				}
				// else: leave unchanged — healer will handle it.
			} else {
				$start_date_exploded = explode( ' ', $rental_start_date );
				if ( isset( $start_date_exploded[0] ) && $start_date_exploded[0] ) {
					$rental_start_date = $start_date_exploded[0] . ' ' . $opt_default_start_time;
				}
			}
		}

		// END DATE — fill if missing and time pickers hidden.
		if ( ! $rental_end_date && ( $opt_hide_time_pickers || $opt_hide_end_date ) ) {
			if ( $validator_available && $rental_start_date ) {
				$date_part = Rental_Date_Validator::extract_date_part( $rental_start_date );
				if ( false !== $date_part ) {
					$rental_end_date = $date_part . ' ' . $opt_default_end_time;
				}
			} elseif ( $rental_start_date ) {
				$start_date_exploded = explode( ' ', $rental_start_date );
				if ( isset( $start_date_exploded[0] ) && $start_date_exploded[0] ) {
					$rental_end_date = $start_date_exploded[0] . ' ' . $opt_default_end_time;
				}
			}
		}

		// END DATE adjustment when present.
		if ( $rental_end_date && ( $opt_hide_time_pickers || $opt_hide_end_date ) ) {
			if ( $validator_available ) {
				$date_part = Rental_Date_Validator::extract_date_part( $rental_end_date );
				if ( false !== $date_part ) {
					$rental_end_date = $date_part . ' ' . $opt_default_end_time;
				}
			} else {
				$end_date_exploded = explode( ' ', $rental_end_date );
				if ( isset( $end_date_exploded[0] ) && $end_date_exploded[0] ) {
					$rental_end_date = $end_date_exploded[0] . ' ' . $opt_default_end_time;
				}
			}
		}

		return array(
			'rental_start_date' => $rental_start_date,
			'rental_end_date'   => $rental_end_date,
		);
	}

	public static function extractDeliveryStartEndTime( $rental_start_date, $timezone, $rental_selected_delivery_selection_time_cookie ) {
		$startDateTime = '';
		$endDateTime   = '';
		$payload       = json_decode( stripslashes( $rental_selected_delivery_selection_time_cookie ), true );
		if ( ! is_array( $payload ) ) {
			return array( 'start_time' => '', 'end_time' => '' );
		}

		$arrival_start_time = isset( $payload['start_time'] ) && $payload['start_time'] ? $payload['start_time'] : '';
		$arrival_end_time   = isset( $payload['end_time'] ) && $payload['end_time'] ? $payload['end_time'] : '';

		$start_ts = strtotime( $rental_start_date );
		if ( false === $start_ts ) {
			return array( 'start_time' => '', 'end_time' => '' );
		}
		$start_date_only = date( 'Y/m/d', $start_ts );

		if ( $arrival_start_time ) {
			$dt = DateTime::createFromFormat( 'Y/m/d h:i A', $start_date_only . ' ' . $arrival_start_time, $timezone );
			if ( $dt instanceof DateTime ) {
				$startDateTime = $dt->getTimestamp();
			}
		}
		if ( $arrival_end_time ) {
			$dt = DateTime::createFromFormat( 'Y/m/d h:i A', $start_date_only . ' ' . $arrival_end_time, $timezone );
			if ( $dt instanceof DateTime ) {
				$endDateTime = $dt->getTimestamp();
			}
		}

		return array( 'start_time' => $startDateTime, 'end_time' => $endDateTime );
	}

	public static function extractPickupStartEndTime( $rental_end_date, $timezone, $rental_selected_pickup_selection_time_cookie ) {
		$pickupStartDateTime = '';
		$pickupEndDateTime   = '';
		$payload             = json_decode( stripslashes( $rental_selected_pickup_selection_time_cookie ), true );
		if ( ! is_array( $payload ) ) {
			return array( 'start_time' => '', 'end_time' => '' );
		}

		$pickup_start_time = isset( $payload['start_time'] ) && $payload['start_time'] ? $payload['start_time'] : '';
		$pickup_end_time   = isset( $payload['end_time'] ) && $payload['end_time'] ? $payload['end_time'] : '';

		$end_ts = strtotime( $rental_end_date );
		if ( false === $end_ts ) {
			return array( 'start_time' => '', 'end_time' => '' );
		}
		$end_date_only = date( 'Y/m/d', $end_ts );

		if ( $pickup_start_time ) {
			$dt = DateTime::createFromFormat( 'Y/m/d h:i A', $end_date_only . ' ' . $pickup_start_time, $timezone );
			if ( $dt instanceof DateTime ) {
				$pickupStartDateTime = $dt->getTimestamp();
			}
		}
		if ( $pickup_end_time ) {
			$dt = DateTime::createFromFormat( 'Y/m/d h:i A', $end_date_only . ' ' . $pickup_end_time, $timezone );
			if ( $dt instanceof DateTime ) {
				$pickupEndDateTime = $dt->getTimestamp();
			}
		}

		return array( 'start_time' => $pickupStartDateTime, 'end_time' => $pickupEndDateTime );
	}
}