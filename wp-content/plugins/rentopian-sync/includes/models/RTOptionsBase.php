<?php
/**
 * RTOptionsBase - Abstract base class for rental options (products and sets).
 *
 * This class provides the common functionality for managing rental options,
 * including CRUD operations with proper SQL injection prevention and
 * consistent error handling.
 *
 * @package    Rentopian_Sync
 * @subpackage Rentopian_Sync/includes/models
 * @since      2.13.0
 * @author     Rentopian
 * @see        docs/options/README.md for full documentation
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * Abstract base class for rental options.
 *
 * Provides common functionality for both product options and set options,
 *
 * @abstract
 */
abstract class RTOptionsBase
{
    /**
     * Option type constants for relation types.
     */
    const RELATION_TYPE_PRODUCT  = 1;
    const RELATION_TYPE_CATEGORY = 2;

    /**
     * Option ID from Rentopian.
     *
     * @var int
     */
    public $id;

    /**
     * Option title/name.
     *
     * @var string
     */
    public $title;

    /**
     * Option details including values and attached items.
     *
     * @var array
     */
    public $details;

    /**
     * Whether option applies once per order.
     *
     * @var int
     */
    public $once_per_order;

    /**
     * WordPress database object.
     *
     * @var wpdb
     */
    protected $wpdb;

    /**
     * Rental tables configuration.
     *
     * @var array
     */
    protected $rental_tables;

    /**
     * Create a new instance.
     *
     * @param int    $id             Option ID from Rentopian.
     * @param string $title          Option title.
     * @param int    $once_per_order Whether option applies once per order (0 or 1).
     * @param array  $details        Option details including values and attachments.
     */
    public function __construct($id, $title = '', $once_per_order = 0, $details = [])
    {
        global $wpdb, $rental_tables;

        $this->wpdb          = $wpdb;
        $this->rental_tables = $rental_tables;
        $this->id            = intval($id);
        $this->title         = sanitize_text_field($title);
        $this->once_per_order = intval($once_per_order);
        $this->details       = is_array($details) ? $details : [];
    }

    /**
     * Get the options table name.
     *
     * @abstract
     * @return string Full table name with prefix.
     */
    abstract protected function get_options_table();

    /**
     * Get the option relations table name.
     *
     * @abstract
     * @return string Full table name with prefix.
     */
    abstract protected function get_relations_table();

    /**
     * Get the item relations table name (products or sets).
     *
     * @abstract
     * @return string Full table name with prefix.
     */
    abstract protected function get_item_relations_table();

    /**
     * Get the post meta key for storing option IDs.
     *
     * @abstract
     * @return string Meta key (e.g., '_product_options' or '_set_options').
     */
    abstract protected function get_meta_key();

    /**
     * Get the attached items from details.
     *
     * @abstract
     * @return array Array of attached item details.
     */
    abstract protected function get_attached_items();

    /**
     * Get the relation ID column name.
     *
     * @abstract
     * @return string Column name (e.g., 'po_id' or 'set_option_id').
     */
    abstract protected function get_relation_id_column();

    /**
     * Build INSERT values for relations with prepared statements.
     *
     * @abstract
     * @param int   $option_id       Option ID.
     * @param int   $rental_id       Rental item ID.
     * @param int   $wp_id           WordPress post ID.
     * @param array &$params         Reference to params array for prepared statement.
     * @param array &$placeholders   Reference to placeholders array.
     * @return void
     */
    abstract protected function build_relation_insert($option_id, $rental_id, $wp_id, &$params, &$placeholders);

    /**
     * Check if option supports category attachment.
     *
     * @return bool True if categories are supported.
     */
    protected function supports_categories()
    {
        return false; // Override in RTProductOptions
    }

    /**
     * Get category relations table if supported.
     *
     * @return string|null Table name or null if not supported.
     */
    protected function get_category_relations_table()
    {
        return null; // Override in RTProductOptions
    }

    /**
     * Create or update an option.
     *
     * @return int|false Option ID on success, false on failure.
     */
    public function save()
    {
        $options_table = $this->get_options_table();
        $option_id     = $this->id;

        // Check if option exists
        $exists = $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT `id` FROM {$options_table} WHERE `id` = %d",
                $this->id
            )
        );

        // Prepare option values JSON
        $option_values = isset($this->details['option_values'])
            ? wp_json_encode($this->details['option_values'])
            : '[]';

        if ($exists) {
            // Update existing option
            $result = $this->wpdb->update(
                $options_table,
                [
                    'title'          => $this->title,
                    'once_per_order' => $this->once_per_order,
                    'option_values'  => $option_values,
                ],
                ['id' => $option_id],
                ['%s', '%d', '%s'],
                ['%d']
            );

            if (false !== $result) {
                $this->update_relations($option_id);
            }
        } else {
            // Insert new option
            $result = $this->wpdb->insert(
                $options_table,
                [
                    'id'             => $option_id,
                    'title'          => $this->title,
                    'once_per_order' => $this->once_per_order,
                    'option_values'  => $option_values,
                ],
                ['%d', '%s', '%d', '%s']
            );

            if (false !== $result) {
                $this->create_relations($option_id);
            }
        }

        if (false === $result) {
            return false;
        }

        return $option_id;
    }

    /**
     * Delete an option and its relations.
     *
     * @return int|false Option ID on success, false on failure.
     */
    public function delete()
    {
        $options_table   = $this->get_options_table();
        $relations_table = $this->get_relations_table();
        $meta_key        = $this->get_meta_key();
        $relation_col    = $this->get_relation_id_column();

        // Get all items attached to this option
        $attached_wp_ids = $this->get_attached_wp_ids_for_delete();

        // Update postmeta to remove this option ID
        foreach ($attached_wp_ids as $wp_id) {
            $this->remove_option_from_postmeta($wp_id, $this->id, $meta_key);
        }

        // Delete the option
        $result = (bool) $this->wpdb->delete(
            $options_table,
            ['id' => $this->id],
            ['%d']
        );

        if ($result) {
            // Delete relations
            $this->wpdb->delete(
                $relations_table,
                [$relation_col => $this->id],
                ['%d']
            );
            return $this->id;
        }

        return false;
    }

    /**
     * Update relations for an existing option.
     *
     * This method rebuilds all relations for the updated option while preserving
     * relations for other options.
     *
     * @param int $option_id Option ID being updated.
     * @return void
     */
    protected function update_relations($option_id)
    {
        $relations_table = $this->get_relations_table();
        $relation_col    = $this->get_relation_id_column();
        $meta_key        = $this->get_meta_key();

        // Delete existing relations for this option
        $this->wpdb->delete(
            $relations_table,
            [$relation_col => $option_id],
            ['%d']
        );

        // Build new relations
        $this->build_and_insert_relations($option_id, true);
    }

    /**
     * Create relations for a new option.
     *
     * @param int $option_id Option ID being created.
     * @return void
     */
    protected function create_relations($option_id)
    {
        $this->build_and_insert_relations($option_id, false);
    }

    /**
     * Build and insert option relations with proper SQL safety.
     *
     * @param int  $option_id    Option ID.
     * @param bool $is_update    Whether this is an update operation.
     * @return void
     */
    protected function build_and_insert_relations($option_id, $is_update)
    {
        $relations_table      = $this->get_relations_table();
        $item_relations_table = $this->get_item_relations_table();
        $meta_key             = $this->get_meta_key();

        // Get item mappings (rental_id => wp_id)
        $item_mappings = $this->wpdb->get_results(
            "SELECT id, rental_id FROM {$item_relations_table}",
            ARRAY_A
        );

        $attached_items = $this->get_attached_items();
        $option_ids_map = []; // wp_id => [option_ids]
        $relation_params = [];
        $relation_placeholders = [];

        // Process attached items
        if (!empty($attached_items['items'])) {
            foreach ($item_mappings as $mapping) {
                if (in_array($mapping['rental_id'], $attached_items['items'])) {
                    $wp_id = intval($mapping['id']);
                    $rental_id = intval($mapping['rental_id']);

                    $option_ids_map[$wp_id][] = $option_id;
                    $this->build_relation_insert(
                        $option_id,
                        $rental_id,
                        $wp_id,
                        $relation_params,
                        $relation_placeholders
                    );
                }
            }
        }

        // Process categories if supported
        if ($this->supports_categories() && !empty($attached_items['categories'])) {
            $this->process_category_attachments(
                $option_id,
                $attached_items['categories'],
                $option_ids_map,
                $relation_params,
                $relation_placeholders
            );
        }

        // Insert relations using prepared statement
        if (!empty($relation_placeholders)) {
            $this->insert_relations_safe($relation_placeholders, $relation_params);
        }

        // Update postmeta
        $this->update_options_postmeta($option_ids_map, $meta_key, $is_update);
    }

    /**
     * Insert relations using prepared statements for SQL safety.
     *
     * @param array $placeholders Array of placeholder strings.
     * @param array $params       Array of values for placeholders.
     * @return void
     */
    protected function insert_relations_safe($placeholders, $params)
    {
        if (empty($placeholders) || empty($params)) {
            return;
        }

        $relations_table = $this->get_relations_table();
        $columns = $this->get_relation_columns();

        $sql = "INSERT INTO {$relations_table} ({$columns}) VALUES " . implode(',', $placeholders);

        // Execute with prepared statement
        $this->wpdb->query($this->wpdb->prepare($sql, $params));
    }

    /**
     * Get relation table columns for INSERT statement.
     *
     * @return string Comma-separated column names.
     */
    protected function get_relation_columns()
    {
        return '`' . $this->get_relation_id_column() . '`, `rental_id`, `wp_id`';
    }

    /**
     * Process category attachments (for product options).
     *
     * @param int   $option_id            Option ID.
     * @param array $category_rental_ids  Array of rental category IDs.
     * @param array &$option_ids_map      Reference to option IDs map.
     * @param array &$relation_params     Reference to relation params.
     * @param array &$relation_placeholders Reference to placeholders.
     * @return void
     */
    protected function process_category_attachments(
        $option_id,
        $category_rental_ids,
        &$option_ids_map,
        &$relation_params,
        &$relation_placeholders
    ) {
        // This method is overridden in RTProductOptions
    }

    /**
     * Update postmeta for affected items.
     *
     * @param array  $option_ids_map Map of wp_id => [option_ids].
     * @param string $meta_key       Meta key to update.
     * @param bool   $is_update      Whether this is an update operation.
     * @return void
     */
    protected function update_options_postmeta($option_ids_map, $meta_key, $is_update)
    {
        if (empty($option_ids_map)) {
            return;
        }

        // For updates, we need to rebuild all options for affected products
        if ($is_update) {
            // Delete existing meta for ALL products with this meta key
            // This is necessary because the update might remove products from this option
            $this->wpdb->query(
                $this->wpdb->prepare(
                    "DELETE FROM {$this->wpdb->postmeta} WHERE meta_key = %s",
                    $meta_key
                )
            );

            // Get all options and their relations to rebuild
            $all_option_ids = $this->get_all_option_ids_grouped();

            // Merge with current changes
            foreach ($option_ids_map as $wp_id => $new_options) {
                if (!isset($all_option_ids[$wp_id])) {
                    $all_option_ids[$wp_id] = [];
                }
                $all_option_ids[$wp_id] = array_unique(
                    array_merge($all_option_ids[$wp_id], $new_options)
                );
            }

            $option_ids_map = $all_option_ids;
        }

        // Build and insert new meta values
        $meta_values = [];
        $wp_ids = array_keys($option_ids_map);

        if ($is_update === false && !empty($wp_ids)) {
            // For create, merge with existing meta
            foreach ($wp_ids as $wp_id) {
                $existing = get_post_meta($wp_id, $meta_key, true);
                $existing_ids = is_array($existing) ? $existing : 
                    (is_string($existing) && !empty($existing) ? json_decode($existing, true) : []);
                
                if (is_array($existing_ids)) {
                    $option_ids_map[$wp_id] = array_unique(
                        array_merge($existing_ids, $option_ids_map[$wp_id])
                    );
                }
            }
        }

        // Insert all meta values
        foreach ($option_ids_map as $wp_id => $option_ids) {
            $unique_ids = array_values(array_unique($option_ids));
            $json_value = wp_json_encode($unique_ids);

            // Delete existing and insert new
            delete_post_meta($wp_id, $meta_key);
            add_post_meta($wp_id, $meta_key, $json_value, true);
        }
    }

    /**
     * Get all option IDs grouped by WP ID from relations table.
     *
     * @return array Map of wp_id => [option_ids].
     */
    protected function get_all_option_ids_grouped()
    {
        $relations_table = $this->get_relations_table();
        $relation_col = $this->get_relation_id_column();

        $results = $this->wpdb->get_results(
            "SELECT wp_id, {$relation_col} as option_id FROM {$relations_table}",
            ARRAY_A
        );

        $grouped = [];
        foreach ($results as $row) {
            $wp_id = intval($row['wp_id']);
            $opt_id = intval($row['option_id']);
            if (!isset($grouped[$wp_id])) {
                $grouped[$wp_id] = [];
            }
            $grouped[$wp_id][] = $opt_id;
        }

        return $grouped;
    }

    /**
     * Remove an option ID from a post's options meta.
     *
     * @param int    $wp_id     WordPress post ID.
     * @param int    $option_id Option ID to remove.
     * @param string $meta_key  Meta key.
     * @return void
     */
    protected function remove_option_from_postmeta($wp_id, $option_id, $meta_key)
    {
        $existing = get_post_meta($wp_id, $meta_key, true);
        $existing_ids = is_array($existing) ? $existing :
            (is_string($existing) && !empty($existing) ? json_decode($existing, true) : []);

        if (!is_array($existing_ids)) {
            return;
        }

        $updated_ids = array_values(array_filter($existing_ids, function ($id) use ($option_id) {
            return intval($id) !== intval($option_id);
        }));

        if (empty($updated_ids)) {
            delete_post_meta($wp_id, $meta_key);
        } else {
            update_post_meta($wp_id, $meta_key, wp_json_encode($updated_ids));
        }
    }

    /**
     * Get attached WP IDs for delete operation.
     *
     * @return array Array of WordPress post IDs.
     */
    protected function get_attached_wp_ids_for_delete()
    {
        $relations_table = $this->get_relations_table();
        $relation_col = $this->get_relation_id_column();

        $results = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT DISTINCT wp_id FROM {$relations_table} WHERE {$relation_col} = %d",
                $this->id
            ),
            ARRAY_A
        );

        return array_map('intval', wp_list_pluck($results, 'wp_id'));
    }
}
