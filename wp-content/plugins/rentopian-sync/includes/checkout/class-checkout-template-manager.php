<?php
/**
 * Checkout Layout Template Manager
 *
 * Handles saving, loading, listing, and managing checkout layout templates.
 * Templates are stored in wp_options as serialized arrays.
 *
 * @package Rentopian_Sync
 * @subpackage Checkout_Layout
 * @since 1.3.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class Rental_Checkout_Template_Manager
 *
 * Manages checkout layout templates for easy switching between layouts.
 */
class Rental_Checkout_Template_Manager {

    /**
     * Option name for storing templates
     *
     * @var string
     */
    const OPTION_NAME = 'rental_checkout_layout_templates';

    /**
     * Maximum number of templates allowed
     *
     * @var int
     */
    const MAX_TEMPLATES = 20;

    /**
     * Singleton instance
     *
     * @var Rental_Checkout_Template_Manager|null
     */
    private static $instance = null;

    /**
     * Get singleton instance
     *
     * @return Rental_Checkout_Template_Manager
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        // Register AJAX handlers
        add_action('wp_ajax_rental_save_layout_template', array($this, 'ajax_save_template'));
        add_action('wp_ajax_rental_load_layout_template', array($this, 'ajax_load_template'));
        add_action('wp_ajax_rental_delete_layout_template', array($this, 'ajax_delete_template'));
        add_action('wp_ajax_rental_list_layout_templates', array($this, 'ajax_list_templates'));
        add_action('wp_ajax_rental_update_layout_template', array($this, 'ajax_update_template'));
        add_action('wp_ajax_rental_apply_layout_template', array($this, 'ajax_apply_template'));
    }

    /**
     * Get all templates
     *
     * Returns templates as lightweight metadata (without full layout JSON)
     * for efficient loading in admin UI.
     *
     * @param bool $include_layout Whether to include full layout JSON (default: false)
     * @return array Array of templates
     */
    public function get_templates($include_layout = false) {
        $templates = get_option(self::OPTION_NAME, array());

        if (!is_array($templates)) {
            return array();
        }

        // Return lightweight version by default
        if (!$include_layout) {
            $lightweight = array();
            foreach ($templates as $id => $template) {
                $lightweight[$id] = array(
                    'id'          => $id,
                    'name'        => isset($template['name']) ? $template['name'] : __('Untitled Template', 'rentopian-sync'),
                    'description' => isset($template['description']) ? $template['description'] : '',
                    'created_at'  => isset($template['created_at']) ? $template['created_at'] : '',
                    'updated_at'  => isset($template['updated_at']) ? $template['updated_at'] : '',
                    'version'     => isset($template['version']) ? $template['version'] : '1.0.0',
                );
            }
            return $lightweight;
        }

        return $templates;
    }

    /**
     * Get a single template by ID
     *
     * @param string $template_id The template ID
     * @return array|null Template data or null if not found
     */
    public function get_template($template_id) {
        $templates = get_option(self::OPTION_NAME, array());

        if (!is_array($templates) || !isset($templates[$template_id])) {
            return null;
        }

        return $templates[$template_id];
    }

    /**
     * Save a new template
     *
     * @param string $name        Template name
     * @param string $description Template description
     * @param array  $layout      Layout configuration array
     * @return array|WP_Error Template data on success, WP_Error on failure
     */
    public function save_template($name, $description, $layout) {
        // Validate inputs
        if (empty($name)) {
            return new WP_Error('empty_name', __('Template name is required.', 'rentopian-sync'));
        }

        if (empty($layout) || !is_array($layout)) {
            return new WP_Error('invalid_layout', __('Valid layout configuration is required.', 'rentopian-sync'));
        }

        $templates = get_option(self::OPTION_NAME, array());

        if (!is_array($templates)) {
            $templates = array();
        }

        // Check max templates limit
        if (count($templates) >= self::MAX_TEMPLATES) {
            return new WP_Error(
                'max_templates_reached',
                sprintf(
                    __('Maximum number of templates (%d) reached. Please delete some templates first.', 'rentopian-sync'),
                    self::MAX_TEMPLATES
                )
            );
        }

        // Generate unique ID
        $template_id = 'tpl_' . wp_generate_uuid4();

        // Prepare template data
        $template = array(
            'id'          => $template_id,
            'name'        => sanitize_text_field($name),
            'description' => sanitize_textarea_field($description),
            'layout'      => $layout,
            'version'     => isset($layout['version']) ? $layout['version'] : '1.0.0',
            'created_at'  => current_time('mysql'),
            'updated_at'  => current_time('mysql'),
            'created_by'  => get_current_user_id(),
        );

        $templates[$template_id] = $template;

        // Save to database - update_option returns false if value already exists and unchanged
        // For new templates this should always succeed, but we verify anyway
        update_option(self::OPTION_NAME, $templates, false); // false = don't autoload

        // Verify the save by reading back
        $saved_templates = get_option(self::OPTION_NAME, array());
        if (!isset($saved_templates[$template_id])) {
            return new WP_Error('save_failed', __('Failed to save template.', 'rentopian-sync'));
        }

        return $template;
    }

    /**
     * Update an existing template
     *
     * @param string      $template_id Template ID
     * @param string|null $name        New name (null to keep existing)
     * @param string|null $description New description (null to keep existing)
     * @param array|null  $layout      New layout (null to keep existing)
     * @return array|WP_Error Updated template data or WP_Error on failure
     */
    public function update_template($template_id, $name = null, $description = null, $layout = null) {
        $templates = get_option(self::OPTION_NAME, array());

        if (!is_array($templates) || !isset($templates[$template_id])) {
            return new WP_Error('template_not_found', __('Template not found.', 'rentopian-sync'));
        }

        $template = $templates[$template_id];

        // Update fields if provided
        if ($name !== null) {
            $template['name'] = sanitize_text_field($name);
        }

        if ($description !== null) {
            $template['description'] = sanitize_textarea_field($description);
        }

        if ($layout !== null && is_array($layout)) {
            $template['layout'] = $layout;
            $template['version'] = isset($layout['version']) ? $layout['version'] : $template['version'];
        }

        $template['updated_at'] = current_time('mysql');
        $template['updated_by'] = get_current_user_id();

        $templates[$template_id] = $template;

        // Save to database - update_option returns false if value unchanged, so we verify by re-reading
        update_option(self::OPTION_NAME, $templates);
        
        // Verify the update by reading back
        $saved_templates = get_option(self::OPTION_NAME, array());
        if (!isset($saved_templates[$template_id])) {
            return new WP_Error('update_failed', __('Failed to update template.', 'rentopian-sync'));
        }

        return $template;
    }

    /**
     * Delete a template
     *
     * @param string $template_id Template ID to delete
     * @return bool|WP_Error True on success, WP_Error on failure
     */
    public function delete_template($template_id) {
        $templates = get_option(self::OPTION_NAME, array());

        if (!is_array($templates) || !isset($templates[$template_id])) {
            return new WP_Error('template_not_found', __('Template not found.', 'rentopian-sync'));
        }

        unset($templates[$template_id]);

        // Save to database - use delete_option if empty, otherwise update_option
        if (empty($templates)) {
            delete_option(self::OPTION_NAME);
        } else {
            update_option(self::OPTION_NAME, $templates);
        }

        return true;
    }

    /**
     * Apply a template to the current layout
     *
     * Copies the template's layout to the active checkout layout.
     *
     * @param string $template_id Template ID to apply
     * @return bool|WP_Error True on success, WP_Error on failure
     */
    public function apply_template($template_id) {
        $template = $this->get_template($template_id);

        if (!$template) {
            return new WP_Error('template_not_found', __('Template not found.', 'rentopian-sync'));
        }

        if (!isset($template['layout']) || !is_array($template['layout'])) {
            return new WP_Error('invalid_layout', __('Template has invalid layout data.', 'rentopian-sync'));
        }

        // Get the layout config instance
        $config = Rental_Checkout_Layout_Config::get_instance();
        
        // Save the template layout as the current layout
        $result = $config->save_layout($template['layout']);

        if (is_wp_error($result)) {
            return $result;
        }

        return true;
    }

    /**
     * AJAX: Save a new template
     */
    public function ajax_save_template() {
        // Verify nonce
        if (!check_ajax_referer('rental_checkout_layout_nonce', 'nonce', false)) {
            wp_send_json_error(array('message' => __('Security check failed.', 'rentopian-sync')));
        }

        // Check permissions
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('Permission denied.', 'rentopian-sync')));
        }

        $name = isset($_POST['name']) ? sanitize_text_field($_POST['name']) : '';
        $description = isset($_POST['description']) ? sanitize_textarea_field($_POST['description']) : '';
        $layout_json = isset($_POST['layout']) ? wp_unslash($_POST['layout']) : '';

        // Parse layout JSON
        $layout = json_decode($layout_json, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            wp_send_json_error(array('message' => __('Invalid layout JSON.', 'rentopian-sync')));
        }

        $result = $this->save_template($name, $description, $layout);

        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }

        // Return lightweight version (without full layout)
        wp_send_json_success(array(
            'message'  => __('Template saved successfully.', 'rentopian-sync'),
            'template' => array(
                'id'          => $result['id'],
                'name'        => $result['name'],
                'description' => $result['description'],
                'created_at'  => $result['created_at'],
                'version'     => $result['version'],
            ),
        ));
    }

    /**
     * AJAX: Load a template's full layout
     */
    public function ajax_load_template() {
        // Verify nonce
        if (!check_ajax_referer('rental_checkout_layout_nonce', 'nonce', false)) {
            wp_send_json_error(array('message' => __('Security check failed.', 'rentopian-sync')));
        }

        // Check permissions
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('Permission denied.', 'rentopian-sync')));
        }

        $template_id = isset($_POST['template_id']) ? sanitize_text_field($_POST['template_id']) : '';

        if (empty($template_id)) {
            wp_send_json_error(array('message' => __('Template ID is required.', 'rentopian-sync')));
        }

        $template = $this->get_template($template_id);

        if (!$template) {
            wp_send_json_error(array('message' => __('Template not found.', 'rentopian-sync')));
        }

        wp_send_json_success(array(
            'template' => $template,
        ));
    }

    /**
     * AJAX: Delete a template
     */
    public function ajax_delete_template() {
        // Verify nonce
        if (!check_ajax_referer('rental_checkout_layout_nonce', 'nonce', false)) {
            wp_send_json_error(array('message' => __('Security check failed.', 'rentopian-sync')));
        }

        // Check permissions
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('Permission denied.', 'rentopian-sync')));
        }

        $template_id = isset($_POST['template_id']) ? sanitize_text_field($_POST['template_id']) : '';

        if (empty($template_id)) {
            wp_send_json_error(array('message' => __('Template ID is required.', 'rentopian-sync')));
        }

        $result = $this->delete_template($template_id);

        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }

        wp_send_json_success(array(
            'message' => __('Template deleted successfully.', 'rentopian-sync'),
        ));
    }

    /**
     * AJAX: List all templates (lightweight)
     */
    public function ajax_list_templates() {
        // Verify nonce
        if (!check_ajax_referer('rental_checkout_layout_nonce', 'nonce', false)) {
            wp_send_json_error(array('message' => __('Security check failed.', 'rentopian-sync')));
        }

        // Check permissions
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('Permission denied.', 'rentopian-sync')));
        }

        $templates = $this->get_templates(false); // Lightweight - no layouts

        wp_send_json_success(array(
            'templates' => array_values($templates),
            'count'     => count($templates),
            'max'       => self::MAX_TEMPLATES,
        ));
    }

    /**
     * AJAX: Update a template
     */
    public function ajax_update_template() {
        // Verify nonce
        if (!check_ajax_referer('rental_checkout_layout_nonce', 'nonce', false)) {
            wp_send_json_error(array('message' => __('Security check failed.', 'rentopian-sync')));
        }

        // Check permissions
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('Permission denied.', 'rentopian-sync')));
        }

        $template_id = isset($_POST['template_id']) ? sanitize_text_field($_POST['template_id']) : '';
        $name = isset($_POST['name']) ? sanitize_text_field($_POST['name']) : null;
        $description = isset($_POST['description']) ? sanitize_textarea_field($_POST['description']) : null;
        $layout_json = isset($_POST['layout']) ? wp_unslash($_POST['layout']) : null;

        if (empty($template_id)) {
            wp_send_json_error(array('message' => __('Template ID is required.', 'rentopian-sync')));
        }

        // Parse layout JSON if provided
        $layout = null;
        if ($layout_json !== null) {
            $layout = json_decode($layout_json, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                wp_send_json_error(array('message' => __('Invalid layout JSON.', 'rentopian-sync')));
            }
        }

        $result = $this->update_template($template_id, $name, $description, $layout);

        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }

        wp_send_json_success(array(
            'message'  => __('Template updated successfully.', 'rentopian-sync'),
            'template' => array(
                'id'          => $result['id'],
                'name'        => $result['name'],
                'description' => $result['description'],
                'updated_at'  => $result['updated_at'],
                'version'     => $result['version'],
            ),
        ));
    }

    /**
     * AJAX: Apply a template to the current layout
     */
    public function ajax_apply_template() {
        // Verify nonce
        if (!check_ajax_referer('rental_checkout_layout_nonce', 'nonce', false)) {
            wp_send_json_error(array('message' => __('Security check failed.', 'rentopian-sync')));
        }

        // Check permissions
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('Permission denied.', 'rentopian-sync')));
        }

        $template_id = isset($_POST['template_id']) ? sanitize_text_field($_POST['template_id']) : '';

        if (empty($template_id)) {
            wp_send_json_error(array('message' => __('Template ID is required.', 'rentopian-sync')));
        }

        $result = $this->apply_template($template_id);

        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }

        // Get the applied layout to return
        $template = $this->get_template($template_id);

        wp_send_json_success(array(
            'message' => __('Template applied successfully. The checkout layout has been updated.', 'rentopian-sync'),
            'layout'  => $template ? $template['layout'] : null,
        ));
    }

    /**
     * Export a template as JSON
     *
     * @param string $template_id Template ID
     * @return string|WP_Error JSON string or WP_Error on failure
     */
    public function export_template($template_id) {
        $template = $this->get_template($template_id);

        if (!$template) {
            return new WP_Error('template_not_found', __('Template not found.', 'rentopian-sync'));
        }

        return wp_json_encode($template, JSON_PRETTY_PRINT);
    }

    /**
     * Import a template from JSON
     *
     * @param string $json JSON string containing template data
     * @return array|WP_Error Template data on success, WP_Error on failure
     */
    public function import_template($json) {
        $data = json_decode($json, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return new WP_Error('invalid_json', __('Invalid JSON data.', 'rentopian-sync'));
        }

        // Extract name and description
        $name = isset($data['name']) ? $data['name'] : __('Imported Template', 'rentopian-sync');
        $description = isset($data['description']) ? $data['description'] : '';

        // Get layout - could be in 'layout' key or the data itself could be the layout
        $layout = isset($data['layout']) ? $data['layout'] : $data;

        // Validate layout has required structure
        if (!isset($layout['sections'])) {
            return new WP_Error('invalid_layout', __('Imported data is not a valid layout (missing sections).', 'rentopian-sync'));
        }

        return $this->save_template($name, $description, $layout);
    }
}

/**
 * Get template manager instance
 *
 * @return Rental_Checkout_Template_Manager
 */
function rental_checkout_template_manager() {
    return Rental_Checkout_Template_Manager::get_instance();
}
