<?php
/**
 * Rental_Sets_Options_Query
 *
 * Read-side counterpart to Rental_Sets_Options_Repository. This class holds
 * the three runtime queries used by cart/checkout code 
 *
 *   - `get_options_for_set($set_id)`           <- ports `get_set_options()`
 *   - `get_once_per_order_options($ids)`       <- ports `get_once_per_order_set_options_by_option_ids()`
 *   - `cart_has_once_per_order_set_option()`   <- ports `rental_order_options_of_sets_check()`
 *
 * @package RentopianSync\Sets
 * @since   2.14.7
 *
 * PHP requirement: 7.4+
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'Rental_Sets_Options_Query', false ) ) :

class Rental_Sets_Options_Query {

    /**
     * @var Rental_Sets_Tables
     */
    protected $tables;

    /**
     * @var wpdb
     */
    protected $wpdb;

    /**
     * @var string
     */
    protected $log_source = 'rentopian-sets-sync';

    /**
     * Constructor.
     *
     * @param Rental_Sets_Tables|null $tables Optional injected tables helper.
     */
    public function __construct( $tables = null ) {
        $this->tables = ( $tables instanceof Rental_Sets_Tables ) ? $tables : Rental_Sets_Tables::instance();
        $this->wpdb   = $this->tables->db();
    }

    /**
     * Return the full option records attached to a given set post.
     *
     * Similar to : `get_set_options()` 
     *
     * @param int $set_id WordPress post ID of the set.
     * @return array<int,array<string,mixed>> Option rows (may be empty).
     */
    public function get_options_for_set( $set_id ) {
        $set_id = intval( $set_id );
        if ( $set_id <= 0 ) {
            return array();
        }

        $is_add_on = get_post_meta( $set_id, '_rental_is_add_on', true );
        if ( $is_add_on ) {
            return array();
        }

        $set_options_table   = $this->tables->set_options();
        $relations_table     = $this->tables->set_option_relations();

        $option_ids_list_json = get_post_meta( $set_id, '_set_options', true );
        $option_ids           = array();

        if ( $option_ids_list_json ) {
            $decoded = json_decode( $option_ids_list_json, true );
            if ( is_array( $decoded ) ) {
                $option_ids = $decoded;
            }
        }

        // Fallback: derive from relations table and self-heal.
        if ( empty( $option_ids ) ) {
            $relation_ids = $this->wpdb->get_col(
                $this->wpdb->prepare(
                    "SELECT DISTINCT set_option_id FROM {$relations_table} WHERE wp_id = %d",
                    $set_id
                )
            );
            if ( ! empty( $relation_ids ) ) {
                $option_ids = array_map( 'intval', $relation_ids );
                update_post_meta( $set_id, '_set_options', wp_json_encode( $option_ids ) );
            }
        }

        if ( empty( $option_ids ) ) {
            return array();
        }

        $placeholders = array_fill( 0, count( $option_ids ), '%d' );
        $placeholder_list = implode( ', ', $placeholders );

        $sql = "SELECT op.* FROM {$set_options_table} op WHERE id IN ({$placeholder_list})";
        $options = $this->wpdb->get_results( $this->wpdb->prepare( $sql, $option_ids ), ARRAY_A );
        if ( ! is_array( $options ) ) {
            return array();
        }

        foreach ( $options as $k => $row ) {
            $options[ $k ]['option_values'] = json_decode( $row['option_values'], true );
        }

        return $options;
    }

    /**
     * Return the subset of the provided option IDs whose `once_per_order`
     * flag is set. Similar to : `get_once_per_order_set_options_by_option_ids()`.
     *
     * @param int[] $set_option_ids
     * @return array<int,array<string,mixed>>
     */
    public function get_once_per_order_options( $set_option_ids ) {
        if ( empty( $set_option_ids ) || ! is_array( $set_option_ids ) ) {
            return array();
        }

        $set_options_table = $this->tables->set_options();
        $placeholders      = array_fill( 0, count( $set_option_ids ), '%d' );
        $placeholder_list  = implode( ', ', $placeholders );

        $sql = "SELECT op.* FROM {$set_options_table} op
                WHERE once_per_order = 1
                  AND id IN ({$placeholder_list})";

        $rows = $this->wpdb->get_results( $this->wpdb->prepare( $sql, $set_option_ids ), ARRAY_A );
        if ( ! is_array( $rows ) ) {
            return array();
        }

        foreach ( $rows as $k => $row ) {
            $rows[ $k ]['option_values'] = json_decode( $row['option_values'], true );
        }
        return $rows;
    }

    /**
     * Return true if any set in the current cart has at least one
     * once-per-order option. Similar to :
     * `rental_order_options_of_sets_check()`.
     *
     * @return bool
     */
    public function cart_has_once_per_order_set_option() {
        if ( ! function_exists( 'WC' ) || ! WC() || ! WC()->cart ) {
            return false;
        }

        foreach ( WC()->cart->get_cart() as $cart_item ) {
            $product_id = ! empty( $cart_item['variation_id'] ) ? $cart_item['variation_id'] : $cart_item['product_id'];
            if ( ! get_post_meta( $product_id, '_rental_is_set', true ) ) {
                continue;
            }
            $options = $this->get_options_for_set( $product_id );
            if ( empty( $options ) ) {
                continue;
            }
            foreach ( $options as $option ) {
                if ( ! empty( $option['once_per_order'] ) ) {
                    return true;
                }
            }
        }
        return false;
    }
}

endif;
