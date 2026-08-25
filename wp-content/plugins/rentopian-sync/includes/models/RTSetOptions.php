<?php
/**
 * RTSetOptions - Set options model for Rentopian Sync.
 *
 * Handles CRUD operations for set options that can be attached
 * to product sets (bundles).
 *
 * @package    Rentopian_Sync
 * @subpackage Rentopian_Sync/includes/models
 * @since      1.0.0
 * @since      2.13.0 Refactored to extend RTOptionsBase for DRY compliance.
 * @author     Rentopian
 * @see        docs/options/README.md for full documentation
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

// Require base class
require_once __DIR__ . '/RTOptionsBase.php';

/**
 * Set Options Model.
 *
 * Manages set options that can be attached to product sets/bundles.
 * Extends RTOptionsBase for common functionality.
 *
 * @since 1.0.0
 * @since 2.13.0 Now extends RTOptionsBase
 */
class RTSetOptions extends RTOptionsBase
{
    /**
     * Get the options table name.
     *
     * @return string Full table name with prefix.
     */
    protected function get_options_table()
    {
        return $this->wpdb->prefix . $this->rental_tables['set_options'];
    }

    /**
     * Get the option relations table name.
     *
     * @return string Full table name with prefix.
     */
    protected function get_relations_table()
    {
        return $this->wpdb->prefix . $this->rental_tables['set_option_relations'];
    }

    /**
     * Get the set relations table name.
     *
     * @return string Full table name with prefix.
     */
    protected function get_item_relations_table()
    {
        return $this->wpdb->prefix . $this->rental_tables['set_relations'];
    }

    /**
     * Get the post meta key for storing option IDs.
     *
     * @return string Meta key '_set_options'.
     */
    protected function get_meta_key()
    {
        return '_set_options';
    }

    /**
     * Get the relation ID column name.
     *
     * @return string Column name 'set_option_id'.
     */
    protected function get_relation_id_column()
    {
        return 'set_option_id';
    }

    /**
     * Check if option supports category attachment.
     *
     * Set options do not support category attachment.
     *
     * @return bool Always false for set options.
     */
    protected function supports_categories()
    {
        return false;
    }

    /**
     * Get the attached items from details.
     *
     * @return array Array with 'items' (sets) key.
     */
    protected function get_attached_items()
    {
        return [
            'items'      => isset($this->details['sets_attached']) 
                ? $this->details['sets_attached'] 
                : [],
            'categories' => [], // Sets don't support categories
        ];
    }

    /**
     * Build INSERT values for relations with prepared statements.
     *
     * Set options have a simpler relation structure without type column.
     *
     * @param int   $option_id       Option ID.
     * @param int   $rental_id       Rental set ID.
     * @param int   $wp_id           WordPress post ID.
     * @param array &$params         Reference to params array for prepared statement.
     * @param array &$placeholders   Reference to placeholders array.
     * @return void
     */
    protected function build_relation_insert($option_id, $rental_id, $wp_id, &$params, &$placeholders)
    {
        $placeholders[] = '(%d, %d, %d)';
        $params[] = intval($option_id);
        $params[] = intval($rental_id);
        $params[] = intval($wp_id);
    }

    /**
     * Legacy method: Update relations.
     *
     * @deprecated 2.13.0 Use save() which calls update_relations() internally.
     * @param int $updated_option_id Option ID.
     * @return void
     */
    public function update($updated_option_id)
    {
        $this->update_relations($updated_option_id);
    }

    /**
     * Legacy method: Create relations.
     *
     * @deprecated 2.13.0 Use save() which calls create_relations() internally.
     * @param int $created_option_id Option ID.
     * @return void
     */
    public function create($created_option_id)
    {
        $this->create_relations($created_option_id);
    }
}
