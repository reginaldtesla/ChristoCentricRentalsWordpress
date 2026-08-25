<?php
/**
 * Rental_Sets_Divisions_Query
 *
 * Ports `rental_get_sets_divisions()` to a
 * class. The query returns the `id` and `rental_division_id` from
 * `rental_set_relations` and is cached via the existing
 * `get_rental_cache()` / `set_rental_cache()` helpers. Cache key and
 * expiration-key names are preserved exactly so any code paths that bust
 * the cache will still work.
 *
 * @package RentopianSync\Sets
 * @since   2.14.7
 *
 * PHP requirement: 7.4+
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'Rental_Sets_Divisions_Query', false ) ) :

class Rental_Sets_Divisions_Query {

    const CACHE_KEY       = 'rental_sets_divisions';
    const CACHE_EXPIRE_KEY = 'rental_sets_divisions_expiration_date';

    /**
     * @var Rental_Sets_Tables
     */
    protected $tables;

    /**
     * @var wpdb
     */
    protected $wpdb;

    public function __construct( $tables = null ) {
        $this->tables = ( $tables instanceof Rental_Sets_Tables ) ? $tables : Rental_Sets_Tables::instance();
        $this->wpdb   = $this->tables->db();
    }

    /**
     * Returns an array of ['id' => wp_post_id, 'rental_division_id' => int]
     * rows from the set relations table, cached.
     *
     * @return array
     */
    public function get() {
        // Use the existing cache helpers — signature and keys preserved.
        if ( function_exists( 'get_rental_cache' ) ) {
            $cached = get_rental_cache( self::CACHE_KEY, self::CACHE_EXPIRE_KEY );
            if ( $cached ) {
                return $cached;
            }
        }

        $table = $this->tables->set_relations();
        $rows  = $this->wpdb->get_results(
            "SELECT id, rental_division_id FROM {$table}",
            ARRAY_A
        );
        if ( ! is_array( $rows ) ) {
            $rows = array();
        }

        if ( function_exists( 'set_rental_cache' ) ) {
            set_rental_cache( $rows, self::CACHE_KEY, self::CACHE_EXPIRE_KEY );
        }

        return $rows;
    }
}

endif;
