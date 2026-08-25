<?php
/**
 * RTProductOptions - Product options model for Rentopian Sync.
 *
 * Handles CRUD operations for product options that can be attached
 * to individual products or product categories.
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
 * Product Options Model.
 *
 * Manages product options that can be attached to products or categories.
 * Extends RTOptionsBase for common functionality.
 *
 * @since 1.0.0
 * @since 2.13.0 Now extends RTOptionsBase
 */
class RTProductOptions extends RTOptionsBase
{
    /**
     * Get the options table name.
     *
     * @return string Full table name with prefix.
     */
    protected function get_options_table()
    {
        return $this->wpdb->prefix . $this->rental_tables['product_options'];
    }

    /**
     * Get the option relations table name.
     *
     * @return string Full table name with prefix.
     */
    protected function get_relations_table()
    {
        return $this->wpdb->prefix . $this->rental_tables['product_option_relations'];
    }

    /**
     * Get the product relations table name.
     *
     * @return string Full table name with prefix.
     */
    protected function get_item_relations_table()
    {
        return $this->wpdb->prefix . $this->rental_tables['product_relations'];
    }

    /**
     * Get the post meta key for storing option IDs.
     *
     * @return string Meta key '_product_options'.
     */
    protected function get_meta_key()
    {
        return '_product_options';
    }

    /**
     * Get the relation ID column name.
     *
     * @return string Column name 'po_id'.
     */
    protected function get_relation_id_column()
    {
        return 'po_id';
    }

    /**
     * Check if option supports category attachment.
     *
     * Product options support both product and category attachments.
     *
     * @return bool Always true for product options.
     */
    protected function supports_categories()
    {
        return true;
    }

    /**
     * Get category relations table.
     *
     * @return string Full table name with prefix.
     */
    protected function get_category_relations_table()
    {
        return $this->wpdb->prefix . $this->rental_tables['category_relations'];
    }

    /**
     * Get relation table columns for INSERT statement.
     *
     * Product options have an additional 'type' column.
     *
     * @return string Comma-separated column names.
     */
    protected function get_relation_columns()
    {
        return '`po_id`, `rental_id`, `wp_id`, `type`';
    }

    /**
     * Get the attached items from details.
     *
     * @return array Array with 'items' (products) and 'categories' keys.
     */
    protected function get_attached_items()
    {
        return [
            'items'      => isset($this->details['products_attached']) 
                ? $this->details['products_attached'] 
                : [],
            'categories' => isset($this->details['categories_attached']) 
                ? $this->details['categories_attached'] 
                : [],
        ];
    }

    /**
     * Build INSERT values for relations with prepared statements.
     *
     * @param int   $option_id       Option ID.
     * @param int   $rental_id       Rental product ID.
     * @param int   $wp_id           WordPress post ID.
     * @param array &$params         Reference to params array for prepared statement.
     * @param array &$placeholders   Reference to placeholders array.
     * @return void
     */
    protected function build_relation_insert($option_id, $rental_id, $wp_id, &$params, &$placeholders)
    {
        $placeholders[] = '(%d, %d, %d, %d)';
        $params[] = intval($option_id);
        $params[] = intval($rental_id);
        $params[] = intval($wp_id);
        $params[] = self::RELATION_TYPE_PRODUCT; // Type 1 = product
    }

    /**
     * Process category attachments for product options.
     *
     * Maps rental category IDs to WordPress term IDs and finds all products
     * in those categories to attach the option.
     *
     * @param int   $option_id            Option ID.
     * @param array $category_rental_ids  Array of rental category IDs.
     * @param array &$option_ids_map      Reference to option IDs map (wp_id => [option_ids]).
     * @param array &$relation_params     Reference to relation params for prepared statement.
     * @param array &$relation_placeholders Reference to placeholders for prepared statement.
     * @return void
     */
    protected function process_category_attachments(
        $option_id,
        $category_rental_ids,
        &$option_ids_map,
        &$relation_params,
        &$relation_placeholders
    ) {
        if (empty($category_rental_ids)) {
            return;
        }

        $category_relations_table = $this->get_category_relations_table();

        // Get category mappings (rental_id => wp_id)
        $category_mappings = $this->wpdb->get_results(
            "SELECT id, rental_id FROM {$category_relations_table}",
            ARRAY_A
        );

        $wp_category_ids = [];
        $category_rental_wp_map = []; // rental_id => wp_id

        foreach ($category_mappings as $mapping) {
            if (in_array($mapping['rental_id'], $category_rental_ids)) {
                $wp_cat_id = intval($mapping['id']);
                $rental_cat_id = intval($mapping['rental_id']);

                $wp_category_ids[] = $wp_cat_id;
                $category_rental_wp_map[$rental_cat_id] = $wp_cat_id;
            }
        }

        // Add category relations
        foreach ($category_rental_wp_map as $rental_cat_id => $wp_cat_id) {
            $relation_placeholders[] = '(%d, %d, %d, %d)';
            $relation_params[] = intval($option_id);
            $relation_params[] = intval($rental_cat_id);
            $relation_params[] = intval($wp_cat_id);
            $relation_params[] = self::RELATION_TYPE_CATEGORY; // Type 2 = category
        }

        // Get products in these categories
        if (!empty($wp_category_ids)) {
            $product_ids = $this->get_products_in_categories($wp_category_ids);

            foreach ($product_ids as $wp_id) {
                if (!isset($option_ids_map[$wp_id])) {
                    $option_ids_map[$wp_id] = [];
                }
                $option_ids_map[$wp_id][] = $option_id;
            }
        }
    }

    /**
     * Get all product IDs in the specified categories.
     *
     * @param array $category_ids Array of WordPress term/category IDs.
     * @return array Array of product post IDs.
     */
    protected function get_products_in_categories($category_ids)
    {
        if (empty($category_ids)) {
            return [];
        }

        $placeholders = array_fill(0, count($category_ids), '%d');
        $placeholders_str = implode(', ', $placeholders);

        $sql = $this->wpdb->prepare(
            "SELECT DISTINCT object_id
             FROM {$this->wpdb->term_relationships} terms
             LEFT JOIN {$this->wpdb->posts} posts ON posts.ID = terms.object_id
             WHERE term_taxonomy_id IN ({$placeholders_str})
               AND posts.post_type = 'product'
               AND posts.post_status = 'publish'",
            $category_ids
        );

        $results = $this->wpdb->get_results($sql, ARRAY_A);

        return array_map('intval', wp_list_pluck($results, 'object_id'));
    }

    /**
     * Get attached WP IDs for delete operation.
     *
     * For product options, we need to include both directly attached products
     * and products in attached categories.
     *
     * @return array Array of WordPress post IDs.
     */
    protected function get_attached_wp_ids_for_delete()
    {
        $relations_table = $this->get_relations_table();
        $options_table = $this->get_options_table();

        // Get option with its relations
        $sql = $this->wpdb->prepare(
            "SELECT 
                GROUP_CONCAT(DISTINCT CASE WHEN type = 1 THEN wp_id END) as product_wp_ids,
                GROUP_CONCAT(DISTINCT CASE WHEN type = 2 THEN wp_id END) as category_wp_ids
             FROM {$relations_table}
             WHERE po_id = %d",
            $this->id
        );

        $result = $this->wpdb->get_row($sql, ARRAY_A);

        $product_ids = [];

        // Direct product attachments
        if (!empty($result['product_wp_ids'])) {
            $product_ids = array_map('intval', explode(',', $result['product_wp_ids']));
        }

        // Products in attached categories
        if (!empty($result['category_wp_ids'])) {
            $category_ids = array_map('intval', explode(',', $result['category_wp_ids']));
            $category_products = $this->get_products_in_categories($category_ids);
            $product_ids = array_unique(array_merge($product_ids, $category_products));
        }

        return $product_ids;
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
