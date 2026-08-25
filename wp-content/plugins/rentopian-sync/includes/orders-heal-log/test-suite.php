<?php
/**
 * Test harness for the v2 fix — validator + healer.
 *
 * Runs outside WordPress with minimal shims for the WP/WC functions the
 * classes touch. Tests the pure logic that decides:
 *   - is_valid()
 *   - normalize()
 *   - is_chronological()
 *   - heal_start_date() (priority chain)
 *   - heal_end_date()   (chronology repair)
 *   - ensure_chronological()
 */

if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }

// --- WP function shims -------------------------------------------------------
if ( ! function_exists( 'current_time' ) ) {
	function current_time( $type ) {
		if ( $type === 'mysql' ) return date( 'Y-m-d H:i:s' );
		return date( $type );
	}
}
if ( ! function_exists( 'get_post_meta' ) ) {
	$GLOBALS['__meta'] = array();
	function get_post_meta( $id, $key, $single = false ) {
		return isset( $GLOBALS['__meta'][ $id ][ $key ] ) ? $GLOBALS['__meta'][ $id ][ $key ] : '';
	}
}
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $v, $f = 0 ) { return json_encode( $v, $f ); }
}

// Minimal WC_Order shim.
class WC_Order_Stub {
	private $id;
	private $created;
	private $postcode;
	public function __construct( $id, $created_str, $postcode = '12345' ) {
		$this->id       = $id;
		$this->created  = new DateTime( $created_str );
		$this->postcode = $postcode;
	}
	public function get_id() { return $this->id; }
	public function get_date_created() { return $this->created; }
	public function get_billing_postcode() { return $this->postcode; }
}

require_once __DIR__ . '/class-rental-date-validator.php';
require_once __DIR__ . '/class-rental-data-healer.php';

$pass = 0; $fail = 0;
function check( $label, $cond ) {
	global $pass, $fail;
	if ( $cond ) { echo "[PASS] $label\n"; $pass++; }
	else         { echo "[FAIL] $label\n"; $fail++; }
}

// --- Validator round-trip ----------------------------------------------------
echo "\n== Validator ==\n";
check( 'canonical date+time valid',       Rental_Date_Validator::is_valid( '2026/06/05 09:00am' ) );
check( 'iso date-only valid',             Rental_Date_Validator::is_valid( '2026-06-05' ) );
check( 'bug Jun 9:00 AM rejected',        ! Rental_Date_Validator::is_valid( 'Jun 9:00 AM' ) );
check( 'localized Jun 5, 2026 rejected',  ! Rental_Date_Validator::is_valid( 'Jun 5, 2026' ) );
check( 'empty rejected',                  ! Rental_Date_Validator::is_valid( '' ) );
check( 'normalize canonical',             Rental_Date_Validator::normalize( '2026/06/05 09:00 AM' ) === '2026/06/05 09:00am' );
check( 'is_chronological correct order',  Rental_Date_Validator::is_chronological( '2026/06/05 09:00am', '2026/06/06 05:00pm' ) );
check( 'is_chronological reversed false', ! Rental_Date_Validator::is_chronological( '2026/06/23 09:00am', '2026/06/06 05:00pm' ) );

// --- Healer ------------------------------------------------------------------
echo "\n== Healer — start date ==\n";

$wc = new WC_Order_Stub( 27451, '2026-05-23 05:30:00' );

// Case 1: valid input passes through.
$r = Rental_Data_Healer::heal_start_date( '2026/06/05 09:00 AM', $wc, '09:00 AM' );
check( 'valid input not healed', $r['healed'] === false && $r['value'] === '2026/06/05 09:00am' );

// Case 2: the actual bug — "Jun 9:00 AM".
$r = Rental_Data_Healer::heal_start_date( 'Jun 9:00 AM', $wc, '09:00 AM' );
check( 'corrupt input healed', $r['healed'] === true );
check( 'corrupt input falls back to WC date_created', $r['source'] === 'wc_date_created' );
check( 'healed value is valid date', Rental_Date_Validator::is_valid( $r['value'] ) );
check( 'healed value preserves WC created date', strpos( $r['value'], '2026/05/23' ) === 0 );

// Case 3: empty input.
$r = Rental_Data_Healer::heal_start_date( '', $wc, '09:00 AM' );
check( 'empty input healed', $r['healed'] === true );
check( 'empty input → WC date_created', $r['source'] === 'wc_date_created' );

// Case 4: pre-existing order meta wins over WC date.
$GLOBALS['__meta'][ 27451 ]['_rental_start_date'] = '2026/06/01 10:00 AM';
$r = Rental_Data_Healer::heal_start_date( 'Jun 9:00 AM', $wc, '09:00 AM' );
check( 'order meta beats WC date', $r['source'] === 'order_meta' && strpos( $r['value'], '2026/06/01' ) === 0 );
unset( $GLOBALS['__meta'][ 27451 ]['_rental_start_date'] );

echo "\n== Healer — end date ==\n";

$start = '2026/06/05 09:00am';

// End valid + chronological.
$r = Rental_Data_Healer::heal_end_date( '2026/06/06 05:00 PM', $start, $wc, '05:00 PM' );
check( 'valid end not healed', $r['healed'] === false );

// End valid but BEFORE start → must heal.
$r = Rental_Data_Healer::heal_end_date( '2026/06/04 05:00 PM', $start, $wc, '05:00 PM' );
check( 'non-chrono end healed', $r['healed'] === true );
check( 'non-chrono end source = derived', $r['source'] === 'derived_from_start' );
check( 'derived end >= start', strtotime( $r['value'] ) >= strtotime( $start ) );

// End invalid → derive from start.
$r = Rental_Data_Healer::heal_end_date( 'garbage', $start, $wc, '05:00 PM' );
check( 'invalid end healed via derivation', $r['healed'] === true && $r['source'] === 'derived_from_start' );

echo "\n== Healer — chronology repair ==\n";

// The exact #27451 scenario: start was healed to something later than the (valid) end.
$result = Rental_Data_Healer::ensure_chronological(
	'2026/06/23 09:00am',  // healed-wrong start
	'2026/06/06 05:00pm',  // valid end
	'05:00 PM'
);
check( '#27451 scenario detected', $result['healed'] === true );
check( '#27451 reason = end_before_start', $result['reason'] === 'end_before_start' );
check( '#27451 repaired end >= start', strtotime( $result['end'] ) >= strtotime( $result['start'] ) );

echo "\n== Healer — ZIP ==\n";

// No cookie value → use billing postcode.
$wc_with_zip = new WC_Order_Stub( 99, '2026-05-23 05:30:00', 'K7K 0C2' );
$r = Rental_Data_Healer::heal_zip( '', 'fake-key', $wc_with_zip );
check( 'empty cookie zip falls back to billing', $r['healed'] === true && $r['value'] === 'K7K 0C2' );

// No cookie, no billing → empty.
$wc_no_zip = new WC_Order_Stub( 100, '2026-05-23 05:30:00', '' );
$r = Rental_Data_Healer::heal_zip( '', 'fake-key', $wc_no_zip );
check( 'no zip available → empty source', $r['source'] === 'empty_fallback' );

// --- Summary -----------------------------------------------------------------
echo "\n=================\n";
echo "Total: $pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
