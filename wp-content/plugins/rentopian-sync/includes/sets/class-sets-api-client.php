<?php
/**
 * Rental_Sets_Api_Client
 *
 * Thin wrapper around `rental_curl()` for the three Rentopian endpoints
 * consumed by the sets subsystem:
 *   - GET inventories/sets          -> list of sets
 *   - GET inventories/sets/tags     -> list of set tags
 *   - GET inventories/sets/options  -> list of set options
 *
 * The class exists to apply the Dependency Inversion Principle: higher-level
 * sets code depends on this client abstraction rather than directly on the
 * procedural `rental_curl` function. This makes the processors testable with
 * fixture data and keeps HTTP concerns out of the domain logic.
 *
 * @package RentopianSync\Sets
 * @since   2.14.7
 *
 * PHP requirement: 7.4+
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'Rental_Sets_Api_Client', false ) ) :

/**
 * Class Rental_Sets_Api_Client
 *
 * An instance is constructed with an API key. Creation without a key is
 * still permitted (falls back to `get_option('rental_api_key')` at call
 * time) to match the two call-site styles that exist today.
 */
class Rental_Sets_Api_Client {

    /**
     * The Rentopian API key. May be empty; in that case the client reads
     * `rental_api_key` at request time.
     *
     * @var string
     */
    protected $api_key;

    /**
     * Log source used for timing/error writes.
     *
     * @var string
     */
    protected $log_source = 'rentopian-sets-sync';

    /**
     * Constructor.
     *
     * @param string $api_key Optional explicit API key. Empty = lazy-resolve.
     */
    public function __construct( $api_key = '' ) {
        $this->api_key = is_string( $api_key ) ? $api_key : '';
    }

    /**
     * Retrieve all sets from the Rentopian API.
     *
     * Equivalent to:
     *   $sets = rental_curl('inventories/sets', $api_key);
     *
     * @return array|mixed Decoded response exactly as rental_curl returns it.
     */
    public function fetch_sets() {
        return $this->timed_curl( 'inventories/sets', 'Rental_Sets_Api_Client::fetch_sets' );
    }

    /**
     * Retrieve all set tags.
     *
     * Equivalent to:
     *   $sets_tags = rental_curl('inventories/sets/tags', $api_key);
     *
     * @return array|mixed
     */
    public function fetch_sets_tags() {
        return $this->timed_curl( 'inventories/sets/tags', 'Rental_Sets_Api_Client::fetch_sets_tags' );
    }

    /**
     * Retrieve set options as a decoded PHP array.
     *
     * Equivalent to:
     *   json_decode( rental_curl('inventories/sets/options', get_option('rental_api_key'), false), true );
     *
     * Note the `false` third argument to rental_curl (raw response) and the
     * subsequent json_decode to an associative array. This exactly mirrors
     * the existing behavior inside `rental_add_set_options()`.
     *
     * @return array Decoded options array (may be empty).
     */
    public function fetch_sets_options_decoded() {
        $token = Project_WP_Logger::start( 'sets_api_fetch_options' );

        $raw = rental_curl( 'inventories/sets/options', $this->resolve_api_key(), false );

        Project_WP_Logger::stop(
            $token,
            'Rental_Sets_Api_Client::fetch_sets_options_decoded',
            'info',
            $this->log_source,
            0,
            'Fetched raw inventories/sets/options payload.'
        );

        $decoded = json_decode( $raw, true );
        if ( ! is_array( $decoded ) ) {
            // Match legacy behavior: falsy / invalid JSON is treated as "no options".
            return array();
        }
        return $decoded;
    }

    /**
     * Resolve the API key, preferring the constructor-supplied value.
     *
     * @return string
     */
    protected function resolve_api_key() {
        if ( '' !== $this->api_key ) {
            return $this->api_key;
        }
        $opt = get_option( 'rental_api_key' );
        return is_string( $opt ) ? $opt : '';
    }

    /**
     * Timed wrapper around `rental_curl` for the simple (decode-true, no-body) case.
     *
     * @param string $endpoint  Rentopian endpoint (relative).
     * @param string $label     Logger label.
     * @return mixed
     */
    protected function timed_curl( $endpoint, $label ) {
        $token = Project_WP_Logger::start( 'sets_api_' . str_replace( '/', '_', $endpoint ) );

        $result = rental_curl( $endpoint, $this->resolve_api_key() );

        Project_WP_Logger::stop(
            $token,
            $label,
            'info',
            $this->log_source,
            0,
            sprintf( 'Endpoint %s fetched.', $endpoint )
        );

        return $result;
    }
}

endif;
