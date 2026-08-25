<?php
/**
 * Rental_Sets_Options_Repository
 *
 * This class applies:
 *   - Single Responsibility: one reason to change (the set-options data path).
 *   - Dependency Inversion: Tables and Api_Client are injected.
 *   - Open/Closed: the existing RTSetOptions model is still available for
 *     single-option webhook operations; this class handles the bulk path.
 *
 * Timing/logging is routed through Project_WP_Logger to
 * `wp-content/uploads/wc-logs/rentopian-sets-sync.log`.
 *
 * @package RentopianSync\Sets
 * @since   2.14.7
 *
 * PHP requirement: 7.4+
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'Rental_Sets_Options_Repository', false ) ) :

class Rental_Sets_Options_Repository {

    /**
     * @var Rental_Sets_Tables
     */
    protected $tables;

    /**
     * @var Rental_Sets_Api_Client
     */
    protected $api;

    /**
     * @var wpdb
     */
    protected $wpdb;

    /**
     * Log source.
     *
     * @var string
     */
    protected $log_source = 'rentopian-sets-sync';

    /**
     * Constructor.
     *
     * @param Rental_Sets_Tables|null     $tables Optional injected tables helper.
     * @param Rental_Sets_Api_Client|null $api    Optional injected API client.
     */
    public function __construct( $tables = null, $api = null ) {
        $this->tables = ( $tables instanceof Rental_Sets_Tables ) ? $tables : Rental_Sets_Tables::instance();
        $this->api    = ( $api instanceof Rental_Sets_Api_Client ) ? $api : new Rental_Sets_Api_Client();
        $this->wpdb   = $this->tables->db();
    }

    /**
     * Empty (truncate) the set-options tables.
     *
     * Mirrors `rental_empty_set_options()` exactly:
     *   - Existence check on `rental_set_option_relations`.
     *   - Two TRUNCATEs.
     *   - Throws RentalException on $wpdb->last_error.
     *   - Returns true on completion.
     *
     * @return bool
     * @throws RentalException On SQL error.
     */
    public function empty_options() {
        $token = Project_WP_Logger::start( 'sets_options_empty' );

        if ( $this->tables->set_option_relations_table_exists() ) {
            $set_options            = $this->tables->set_options();
            $set_option_relations   = $this->tables->set_option_relations();

            // TRUNCATE is intentional
            $this->wpdb->query( "TRUNCATE TABLE {$set_options}" );
            $this->wpdb->query( "TRUNCATE TABLE {$set_option_relations}" );
        }

        if ( '' !== $this->wpdb->last_error ) {
            $last_error = $this->wpdb->last_error;
            $last_query = $this->wpdb->last_query;

            Project_WP_Logger::write(
                'Rental_Sets_Options_Repository::empty_options() SQL error: ' . $last_error,
                'error',
                $this->log_source
            );

            throw new RentalException(
                "SQL_ERROR: {$last_error} (SQL: {$last_query})",
                RentalException::TYPE_SYNC_GLOBAL
            );
        }

        Project_WP_Logger::stop(
            $token,
            'Rental_Sets_Options_Repository::empty_options',
            'info',
            $this->log_source,
            0,
            'Truncated set_options and set_option_relations.'
        );

        return true;
    }

    /**
     * Bulk-insert set options fetched from Rentopian.
     *
     * Faithful port of `rental_add_set_options()` (functions.php 6063-6178):
     *   - Existence guard on set_option_relations.
     *   - Collect attached_sets per option.
     *   - Build prepared placeholders for the options INSERT.
     *   - Build prepared placeholders for the relation INSERT, respecting
     *     multi-division (one rental_id can map to many wp_ids).
     *   - Build prepared placeholders for the `_set_options` postmeta insert
     *     using the DISTINCT option-id list per wp_id.
     *
     * @return bool
     * @throws RentalException On SQL error.
     */
    public function sync_options() {
        $token = Project_WP_Logger::start( 'sets_options_sync' );

        if ( ! $this->tables->set_option_relations_table_exists() ) {
            Project_WP_Logger::write(
                'sync_options skipped: set_option_relations table missing.',
                'warning',
                $this->log_source
            );
            Project_WP_Logger::stop( $token, 'Rental_Sets_Options_Repository::sync_options', 'info', $this->log_source );
            return true;
        }

        $sets_options = $this->api->fetch_sets_options_decoded();
        if ( empty( $sets_options ) ) {
            Project_WP_Logger::stop(
                $token,
                'Rental_Sets_Options_Repository::sync_options',
                'info',
                $this->log_source,
                0,
                'No set options returned by API.'
            );
            return true;
        }

        $set_options_table          = $this->tables->set_options();
        $set_option_relations_table = $this->tables->set_option_relations();
        $set_relations_table        = $this->tables->set_relations();

        // Every division's rental set ids.
        $set_ids = $this->wpdb->get_results(
            "SELECT id, rental_id FROM {$set_relations_table}",
            ARRAY_A
        );
        if ( ! is_array( $set_ids ) ) {
            $set_ids = array();
        }

        $option_placeholders    = array();
        $option_params          = array();
        $relation_placeholders  = array();
        $relation_params        = array();
        $attached_items_grouped = array();

        foreach ( $sets_options as $option ) {
            $id             = isset( $option['id'] ) ? intval( $option['id'] ) : 0;
            $title          = isset( $option['title'] ) ? sanitize_text_field( $option['title'] ) : '';
            $once_per_order = isset( $option['once_per_order'] ) ? intval( $option['once_per_order'] ) : 0;
            $values_raw     = isset( $option['values'] ) ? $option['values'] : array();
            $values_encoded = wp_json_encode( $values_raw );

            // attached_sets is a JSON string that must be decoded once.
            $attached_sets_raw = isset( $option['attached_sets'] ) ? $option['attached_sets'] : array();
            $attached_sets     = is_string( $attached_sets_raw ) ? json_decode( $attached_sets_raw ) : $attached_sets_raw;
            if ( ! is_array( $attached_sets ) ) {
                $attached_sets = array();
            }

            $attached_items_grouped[ $id ] = array(
                'sets' => $attached_sets,
            );

            $option_placeholders[] = '(%d, %s, %d, %s)';
            $option_params[]       = $id;
            $option_params[]       = $title;
            $option_params[]       = $once_per_order;
            $option_params[]       = $values_encoded;
        }

        // Relations: option -> [rental_set_id -> [wp_id,...]].
        $option_wp_ids_map = array(); // wp_id => [option_ids]

        foreach ( $attached_items_grouped as $option_id => $items ) {
            if ( empty( $items['sets'] ) ) {
                continue;
            }

            $set_wp_id_collection = array();
            foreach ( $set_ids as $sid ) {
                if ( in_array( $sid['rental_id'], $items['sets'] ) ) {
                    $set_wp_id_collection[ $sid['rental_id'] ][] = $sid['id'];
                }
            }
            if ( empty( $set_wp_id_collection ) ) {
                continue;
            }

            foreach ( $set_wp_id_collection as $rental_set_id => $wp_id_list ) {
                foreach ( $wp_id_list as $wp_id ) {
                    $option_wp_ids_map[ $wp_id ][] = $option_id;

                    $relation_placeholders[] = '(%d, %d, %d)';
                    $relation_params[]       = (int) $option_id;
                    $relation_params[]       = (int) $rental_set_id;
                    $relation_params[]       = (int) $wp_id;
                }
            }
        }

        // Options insert.
        if ( ! empty( $option_placeholders ) ) {
            $sql = "INSERT INTO `{$set_options_table}` (`id`, `title`, `once_per_order`, `option_values`) VALUES "
                . implode( ',', $option_placeholders );
            $this->wpdb->query( $this->wpdb->prepare( $sql, $option_params ) );
        }

        // Relations + postmeta insert (only if we have relations).
        if ( ! empty( $relation_placeholders ) ) {
            $rel_sql = "INSERT INTO `{$set_option_relations_table}` (`set_option_id`, `rental_id`, `wp_id`) VALUES "
                . implode( ',', $relation_placeholders );
            $this->wpdb->query( $this->wpdb->prepare( $rel_sql, $relation_params ) );

            $this->write_postmeta( $option_wp_ids_map );
        }

        if ( '' !== $this->wpdb->last_error ) {
            $last_error = $this->wpdb->last_error;
            $last_query = $this->wpdb->last_query;
            Project_WP_Logger::write(
                'Rental_Sets_Options_Repository::sync_options() SQL error: ' . $last_error,
                'error',
                $this->log_source
            );
            throw new RentalException(
                "SQL_ERROR: {$last_error} (SQL: {$last_query})",
                RentalException::TYPE_SYNC_GLOBAL
            );
        }

        Project_WP_Logger::stop(
            $token,
            'Rental_Sets_Options_Repository::sync_options',
            'info',
            $this->log_source,
            0,
            sprintf(
                'Inserted %d set options, %d option-set relations, %d postmeta rows.',
                count( $option_placeholders ),
                count( $relation_placeholders ),
                count( $option_wp_ids_map )
            )
        );

        return true;
    }

    /**
     * Build and execute the `_set_options` postmeta insert using prepared
     * statements
     *
     * @param array<int, int[]> $option_wp_ids_map Map wp_id => array of option ids.
     * @return void
     */
    protected function write_postmeta( array $option_wp_ids_map ) {
        if ( empty( $option_wp_ids_map ) ) {
            return;
        }

        $placeholders = array();
        $params       = array();
        foreach ( $option_wp_ids_map as $wp_id => $option_ids ) {
            $unique_ids     = array_values( array_unique( $option_ids ) );
            $option_ids_json = wp_json_encode( $unique_ids );

            $placeholders[] = '(%d, %s, %s)';
            $params[]       = (int) $wp_id;
            $params[]       = '_set_options';
            $params[]       = $option_ids_json;
        }

        if ( empty( $placeholders ) ) {
            return;
        }

        $sql = "INSERT INTO `{$this->wpdb->postmeta}` (`post_id`, `meta_key`, `meta_value`) VALUES "
            . implode( ',', $placeholders );
        $this->wpdb->query( $this->wpdb->prepare( $sql, $params ) );
    }
}

endif;
