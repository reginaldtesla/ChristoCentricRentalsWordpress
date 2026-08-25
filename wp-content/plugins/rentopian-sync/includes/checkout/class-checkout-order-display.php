<?php
/**
 * Checkout Order Display
 *
 * Handles the display of order data on order-received and my-account pages.
 * - Hides visually hidden address fields (city, state, zip, country)
 * - Shows custom rental fields in organized sections
 * - Displays configurable thank you message
 *
 * @package    Rentopian_Sync
 * @subpackage Checkout_Layout
 * @since      1.4.1
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class Rental_Checkout_Order_Display
 */
class Rental_Checkout_Order_Display {

    /**
     * Singleton instance
     *
     * @var Rental_Checkout_Order_Display
     */
    private static $instance = null;

    /**
     * Get singleton instance
     *
     * @return Rental_Checkout_Order_Display
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
        // CRITICAL: Only add thank-you page hooks if modern checkout is enabled
        // This prevents the Order Display class from affecting classic checkout mode
        if (!$this->is_modern_checkout_enabled()) {
            // In classic mode, only register admin settings - no frontend hooks
            add_action('admin_init', array($this, 'register_settings'));
            return;
        }
        
        // Check if "show only thank you message" is enabled
        $show_only_thank_you = get_option('rental_checkout_thank_you_only', false);
        
        if ($show_only_thank_you) {
            // When enabled, we need to hook very early to hide everything
            // and ONLY show our thank you message
            add_action('woocommerce_thankyou', array($this, 'render_only_thank_you_message'), 1);
            add_action('wp_head', array($this, 'hide_all_order_content_css'));
            
            // Remove default WooCommerce thank you content
            remove_action('woocommerce_thankyou', 'woocommerce_order_details_table', 10);
        } else {
            // Normal mode - output thank you and rental data on order-received in left column via woocommerce_thankyou
            add_action('woocommerce_thankyou', array($this, 'render_thank_you_on_thankyou'), 3, 1);
            add_action('woocommerce_thankyou', array($this, 'render_rental_data_on_thankyou'), 12, 1);
            add_action('woocommerce_order_details_before_order_table', array($this, 'display_thank_you_message'), 5);
            add_action('woocommerce_order_details_after_order_table', array($this, 'display_rental_order_data'), 10);
        }
        
        // Rental data on my-account view order comes from woocommerce_order_details_after_order_table (display_rental_order_data)
        
        // Filter address display to hide visual fields
        add_filter('woocommerce_order_formatted_billing_address', array($this, 'filter_billing_address'), 10, 2);
        add_filter('woocommerce_order_formatted_shipping_address', array($this, 'filter_shipping_address'), 10, 2);
        
        // Add CSS for order display
        add_action('wp_head', array($this, 'add_order_display_styles'));
        
        // Register admin settings
        add_action('admin_init', array($this, 'register_settings'));

        // When modern checkout is enabled, we output all rental/custom data in one place
        // so remove the legacy thank-you custom fields output to avoid duplicates
        add_action('wp', array($this, 'maybe_remove_legacy_thankyou_custom_fields'), 5);

        // Save rental display meta at checkout so order-received and my-account show full data
        add_action('woocommerce_checkout_update_order_meta', array($this, 'save_rental_display_order_meta'), 20, 2);

        // Sync rental/order data to order emails (same sections as order-received and my-account)
        add_action('woocommerce_email_after_order_table', array($this, 'render_rental_data_email'), 10, 4);

        // Apply review display settings to order totals (thank-you, my-account, emails)
        add_filter('woocommerce_get_order_item_totals', array($this, 'filter_order_item_totals_by_review_display'), 10, 3);

        // Output review display CSS on thank-you and my-account pages (for items CSS can't reach from renderer)
        add_action('wp_head', array($this, 'output_review_display_css_thankyou'));
    }

    /**
     * Check if modern checkout is enabled
     *
     * @return bool
     */
    private function is_modern_checkout_enabled() {
        $dates_on_checkout = get_option('rental_dates_on_checkout', 0) == 1;
        $allow_overbook = get_option('rental_allow_overbook', 1) == 1;
        $modern_mode = get_option('rental_checkout_layout_mode', 'classic') === 'modern';
        
        return $dates_on_checkout && $allow_overbook && $modern_mode;
    }

    /**
     * Save rental display meta at checkout dynamically from layout + registry and $_POST.
     * Iterates over layout fields and rental_* POST keys so any JSON layout or new fields are saved.
     *
     * @param int   $order_id Order ID
     * @param array $data     Posted checkout data (optional)
     */
    public function save_rental_display_order_meta($order_id, $data = array()) {
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        $saved_meta_keys = array();

        // 1. Get all field IDs used in current layout (dynamic from config)
        if (class_exists('Rental_Checkout_Layout_Config') && class_exists('Rental_Checkout_Field_Registry')) {
            $config   = Rental_Checkout_Layout_Config::get_instance();
            $registry = Rental_Checkout_Field_Registry::get_instance();
            if ($config->is_enabled()) {
                $used_field_ids = $config->get_used_field_ids();
                foreach ($used_field_ids as $field_id) {
                    $field = $registry->get_field($field_id);
                    if (!$field) {
                        continue;
                    }
                    $source = isset($field['source']) ? $field['source'] : '';
                    if ($source === 'woocommerce') {
                        continue;
                    }
                    if ($source === 'rental_dynamic') {
                        continue;
                    }
                    $post_name = $this->get_post_name_for_field($field_id, $field);
                    $value     = $this->get_posted_value_by_name($post_name);
                    if ($post_name === 'delivery_time_selections_id' || $post_name === 'pickup_time_selections_id') {
                        if ($value === '' || $value === null) {
                            $cookie_key = $post_name;
                            $value      = isset($_COOKIE[$cookie_key]) ? sanitize_text_field(wp_unslash($_COOKIE[$cookie_key])) : '';
                        }
                    }
                    if ($value === '' && $value !== '0') {
                        continue;
                    }
                    $resolved = $this->resolve_option_field_to_display_value($field_id, $post_name, $value);
                    if ($resolved !== null) {
                        $order->update_meta_data($resolved['meta_key'], $resolved['value']);
                        $saved_meta_keys[] = $resolved['meta_key'];
                    } elseif (in_array($field_id, array('rental_referral_source', 'rental_event_type', 'delivery_time_selections_id', 'pickup_time_selections_id'), true)) {
                        continue;
                    } else {
                        $meta_key = '_rental_' . $field_id;
                        $order->update_meta_data($meta_key, sanitize_text_field(is_array($value) ? implode(', ', $value) : $value));
                        $saved_meta_keys[] = $meta_key;
                    }
                }
            }
        }

        // 2. Explicitly save event type and referral source as titles (not IDs) when present in POST
        if (isset($_POST['rental_event_types_id']) && $_POST['rental_event_types_id'] !== '') {
            $event_id = absint($_POST['rental_event_types_id']);
            $resolved = $this->resolve_option_field_to_display_value('rental_event_type', 'rental_event_types_id', $event_id);
            if ($resolved !== null) {
                $order->update_meta_data($resolved['meta_key'], $resolved['value']);
                $saved_meta_keys[] = $resolved['meta_key'];
            }
        }
        if (isset($_POST['rental_referral_source_id']) && $_POST['rental_referral_source_id'] !== '') {
            $ref_id = absint($_POST['rental_referral_source_id']);
            $resolved = $this->resolve_option_field_to_display_value('rental_referral_source', 'rental_referral_source_id', $ref_id);
            if ($resolved !== null) {
                $order->update_meta_data($resolved['meta_key'], $resolved['value']);
                $saved_meta_keys[] = $resolved['meta_key'];
            }
        }

        // 3. Scan $_POST for other rental_* keys (component outputs and any future fields)
        $post_to_meta_map = array(
            'rental_multi_day_event'   => '_rental_multi_day',
            'rental_outdoor_event'     => '_rental_outdoor_event',
            'rental_outside_event'     => '_rental_outside_event',
            'rental_flexible_delivery' => '_rental_flexible_delivery',
            'rental_flexible_pickup'   => '_rental_flexible_pickup',
        );
        $post_to_meta_map = apply_filters('rentopian_checkout_post_to_order_meta_map', $post_to_meta_map);
        // Skip keys already saved by other functions (e.g. rental_save_pickup_fields())
        // to avoid duplicate meta with _rental_ prefix
        $post_keys_to_skip = array(
            'rental_event_types_id', 'rental_referral_source_id',
            // Pickup address fields - saved by rental_save_pickup_fields() in rentopian-sync.php
            'rental_different_pick_up_address',
            'rental_pick_up_address_1', 'rental_pick_up_address_2',
            'rental_pick_up_city', 'rental_pick_up_state', 'rental_pick_up_postcode', 'rental_pick_up_country',
            // Photo upload: IDs are internal session references, not for order meta display.
            // Photos themselves are attached as _rental_checkout_photos by the photo upload class.
            'rental_checkout_photo_ids', 'rental_checkout_photos',
        );

        foreach ($_POST as $post_key => $post_value) {
            if (!is_string($post_key) || strpos($post_key, 'rental_') !== 0) {
                continue;
            }
            if (in_array($post_key, array('rental_custom_fields'), true) || in_array($post_key, $post_keys_to_skip, true)) {
                continue;
            }
            $meta_key = isset($post_to_meta_map[$post_key]) ? $post_to_meta_map[$post_key] : '_rental_' . $post_key;
            if (in_array($meta_key, $saved_meta_keys, true)) {
                continue;
            }
            $value = is_array($post_value) ? implode(', ', array_map('sanitize_text_field', $post_value)) : sanitize_text_field(wp_unslash($post_value));
            if ($value === '') {
                continue;
            }
            $order->update_meta_data($meta_key, $value);
            $saved_meta_keys[] = $meta_key;
        }

        $order->save();
    }

    /**
     * Get POST name for a field (same logic as validator).
     *
     * @param string $field_id Field ID
     * @param array  $field    Field config from registry
     * @return string POST key
     */
    private function get_post_name_for_field($field_id, $field) {
        if (isset($field['form_field_name']) && $field['form_field_name'] !== '') {
            return $field['form_field_name'];
        }
        if (isset($field['source']) && $field['source'] === 'rental_dynamic') {
            $rentopian_id  = isset($field['rentopian_id']) ? $field['rentopian_id'] : null;
            $rentopian_slug = isset($field['rentopian_slug']) ? $field['rentopian_slug'] : null;
            $raw_id        = $rentopian_id ?: ($rentopian_slug ?: str_replace('rental_custom_', '', $field_id));
            return 'rental_custom_fields[' . $raw_id . ']';
        }
        return $field_id;
    }

    /**
     * Get posted value by POST name (handles array notation e.g. rental_custom_fields[id]).
     *
     * @param string $post_name POST key or array key like rental_custom_fields[123]
     * @return mixed Value or empty string
     */
    private function get_posted_value_by_name($post_name) {
        if (preg_match('/^([^\[]+)\[([^\]]+)\]$/', $post_name, $matches)) {
            $array_name = $matches[1];
            $array_key  = $matches[2];
            if (isset($_POST[$array_name]) && is_array($_POST[$array_name]) && isset($_POST[$array_name][$array_key])) {
                $v = $_POST[$array_name][$array_key];
                return is_array($v) ? implode(', ', array_map('sanitize_text_field', $v)) : sanitize_text_field(wp_unslash($v));
            }
            return '';
        }
        if (!isset($_POST[$post_name])) {
            return '';
        }
        $v = $_POST[$post_name];
        if (is_array($v)) {
            return implode(', ', array_map('sanitize_text_field', $v));
        }
        return sanitize_text_field(wp_unslash($v));
    }

    /**
     * Resolve option-ID fields to display label and meta key (for referral, event type, delivery/pickup time).
     *
     * @param string $field_id  Field ID
     * @param string $post_name POST key
     * @param mixed  $value     Raw value (ID or string)
     * @return array|null { meta_key, value } or null if not an option field
     */
    private function resolve_option_field_to_display_value($field_id, $post_name, $value) {
        if ($field_id === 'rental_referral_source' || $post_name === 'rental_referral_source_id') {
            $id = absint($value);
            if ($id && ($sources = get_option('rental_referral_sources', array()))) {
                foreach (is_array($sources) ? $sources : array() as $source) {
                    $src = is_object($source) ? $source : (object) $source;
                    if (isset($src->id) && (int) $src->id === $id && !empty($src->title)) {
                        return array('meta_key' => '_rental_referral_source', 'value' => $src->title);
                    }
                }
            }
            if (function_exists('get_rental_session_data')) {
                $ref_title = get_rental_session_data('rental_referral_source_title', '');
                if ($ref_title !== '') {
                    return array('meta_key' => '_rental_referral_source', 'value' => $ref_title);
                }
            }
            return null;
        }
        if ($field_id === 'rental_event_type' || $post_name === 'rental_event_types_id') {
            $id = absint($value);
            if ($id && ($types = get_option('rental_event_types', array()))) {
                foreach (is_array($types) || is_object($types) ? (array) $types : array() as $type) {
                    $t = is_object($type) ? $type : (object) $type;
                    if (isset($t->id) && (int) $t->id === $id && !empty($t->title)) {
                        return array('meta_key' => '_rental_event_type', 'value' => $t->title);
                    }
                }
            }
            if (function_exists('get_rental_session_data')) {
                $event_title = get_rental_session_data('rental_event_type_title', '');
                if ($event_title !== '') {
                    return array('meta_key' => '_rental_event_type', 'value' => $event_title);
                }
            }
            return null;
        }
        if ($field_id === 'delivery_time_selections_id' || $post_name === 'delivery_time_selections_id') {
            $id   = absint($value);
            $label = $id ? $this->get_delivery_or_pickup_time_label($id, 'delivery') : '';
            if ($label !== '') {
                return array('meta_key' => '_rental_delivery_time', 'value' => $label);
            }
            return null;
        }
        if ($field_id === 'pickup_time_selections_id' || $post_name === 'pickup_time_selections_id') {
            $id   = absint($value);
            $label = $id ? $this->get_delivery_or_pickup_time_label($id, 'pickup') : '';
            if ($label !== '') {
                return array('meta_key' => '_rental_pickup_time', 'value' => $label);
            }
            return null;
        }
        return null;
    }

    /**
     * Get display label for a delivery or pickup time selection by id.
     *
     * @param int    $selection_id Option id
     * @param string $type         'delivery' or 'pickup' (for filtering by day if needed)
     * @return string
     */
    private function get_delivery_or_pickup_time_label($selection_id, $type = 'delivery') {
        $selections = get_option('delivery_time_selections', array());
        if (empty($selections) || !is_array($selections)) {
            return '';
        }
        foreach ($selections as $option) {
            $opt = is_object($option) ? $option : (object) $option;
            if (!isset($opt->id) || (int) $opt->id !== (int) $selection_id) {
                continue;
            }
            $label = isset($opt->title) && $opt->title
                ? $opt->title . '  ' . $opt->start_time . '-' . $opt->end_time
                : $opt->start_time . '-' . $opt->end_time;
            return $label;
        }
        return '';
    }

    /**
     * Resolve order meta value to human-readable label when value is an option ID.
     * Used for event type, referral source, delivery/pickup time so "31" shows as "Wedding", etc.
     *
     * @param string $meta_key Order meta key (e.g. _rental_event_type, _rental_referral_source)
     * @param mixed  $value    Stored value (may be ID or already a label)
     * @return string Display value (label or original value)
     */
    public function resolve_order_meta_value_to_label($meta_key, $value) {
        if ($value === '' || $value === null) {
            return (string) $value;
        }
        $value = is_array($value) ? implode(', ', $value) : $value;
        $value = trim((string) $value);
        if ($value === '') {
            return $value;
        }

        // Event type: numeric ID → option title (also when stored under ID meta key)
        $event_type_keys = array('_rental_event_type', '_rental_rental_event_types_id', 'rental_event_types_id');
        if (in_array($meta_key, $event_type_keys, true)) {
            $id = absint($value);
            if ($id && (string) $id === (string) $value) {
                $types = get_option('rental_event_types', array());
                if (!empty($types) && is_array($types)) {
                    foreach ($types as $type) {
                        $t = is_object($type) ? $type : (object) $type;
                        if (isset($t->id) && (int) $t->id === $id && !empty($t->title)) {
                            return $t->title;
                        }
                    }
                }
            }
            return $meta_key === '_rental_event_type' ? $value : '';
        }

        // Referral source: numeric ID → option title (also when stored under ID meta key)
        $referral_keys = array('_rental_referral_source', '_rental_rental_referral_source_id', 'rental_referral_source_id');
        if (in_array($meta_key, $referral_keys, true)) {
            $id = absint($value);
            if ($id && (string) $id === (string) $value) {
                $sources = get_option('rental_referral_sources', array());
                if (!empty($sources) && is_array($sources)) {
                    foreach ($sources as $source) {
                        $src = is_object($source) ? $source : (object) $source;
                        if (isset($src->id) && (int) $src->id === $id && !empty($src->title)) {
                            return $src->title;
                        }
                    }
                }
            }
            return $meta_key === '_rental_referral_source' ? $value : '';
        }

        // Delivery / pickup time: numeric ID → time range label
        if ($meta_key === '_rental_delivery_time') {
            $id = absint($value);
            if ($id && $id == $value) {
                $label = $this->get_delivery_or_pickup_time_label($id, 'delivery');
                if ($label !== '') {
                    return $label;
                }
            }
            return $value;
        }
        if ($meta_key === '_rental_pickup_time') {
            $id = absint($value);
            if ($id && $id == $value) {
                $label = $this->get_delivery_or_pickup_time_label($id, 'pickup');
                if ($label !== '') {
                    return $label;
                }
            }
            return $value;
        }

        return $value;
    }

    /**
     * Get human-readable display value for order meta (for admin order view and anywhere else).
     * Resolves option IDs to labels (event type, referral source, delivery/pickup time) and formats checkboxes as Yes/No.
     *
     * @param string $meta_key Order meta key (e.g. _rental_event_type, _rental_flexible_delivery)
     * @param mixed  $value    Stored value
     * @return string Display value (label, Yes/No, or original value)
     */
    public function get_display_value_for_order_meta($meta_key, $value) {
        $resolved = $this->resolve_order_meta_value_to_label($meta_key, $value);
        if ($resolved !== (string) $value) {
            return $resolved;
        }
        $field_id = str_replace('_rental_', '', $meta_key);
        if ($this->is_checkbox_field_for_display($meta_key, $field_id, null)) {
            return $this->format_yes_no_display_value($value);
        }
        return is_array($value) ? implode(', ', $value) : (string) $value;
    }

    /**
     * Get hidden visual fields from layout configuration
     * When modern checkout layout is enabled, returns field IDs marked hidden_visual in the layout.
     * Otherwise returns empty array so no address parts are hidden by default.
     *
     * @return array Field IDs to hide from address display (e.g. billing_city, billing_state)
     */
    private function get_hidden_visual_fields() {
        if (!class_exists('Rental_Checkout_Layout_Config')) {
            return array();
        }
        $config = Rental_Checkout_Layout_Config::get_instance();
        if (!$config->is_enabled()) {
            return array();
        }
        return $config->get_hidden_visual_fields();
    }

    /**
     * Remove legacy thank-you custom fields output when modern checkout is enabled
     * so our render_rental_data_section is the single place for rental/custom data.
     */
    public function maybe_remove_legacy_thankyou_custom_fields() {
        if (!function_exists('is_checkout') || !is_checkout()) {
            return;
        }
        if (!class_exists('Rental_Checkout_Layout_Config')) {
            return;
        }
        $config = Rental_Checkout_Layout_Config::get_instance();
        if (!$config->is_enabled()) {
            return;
        }
        remove_action('woocommerce_thankyou', 'rental_display_custom_fields_on_thankyou', 10);
    }

    /**
     * Render ONLY the thank you message when "show only" is enabled
     * 
     * @param int $order_id Order ID
     */
    public function render_only_thank_you_message($order_id) {
        if (!is_wc_endpoint_url('order-received')) {
            return;
        }
        
        $thank_you_message = $this->get_thank_you_message();
        
        if (!empty($thank_you_message)) {
            echo '<div class="rentopian-thank-you-message rentopian-thank-you-only-mode">';
            echo wp_kses_post(wpautop($thank_you_message));
            echo '</div>';
        }
    }

    /**
     * Get the thank you message with fallback to rental_thank_you_message.
     *
     * @return string
     */
    private function get_thank_you_message() {
        $message = get_option('rental_checkout_thank_you_message', '');
        if ($message !== '') {
            return $message;
        }
        return get_option('rental_thank_you_message', __('Thank you. Your order has been received.', 'rentopian-sync'));
    }

    /**
     * CSS to hide ALL order content when "show only thank you" is enabled
     */
    public function hide_all_order_content_css() {
        if (!is_wc_endpoint_url('order-received')) {
            return;
        }
        
        if (!get_option('rental_checkout_thank_you_only', false)) {
            return;
        }
        ?>
        <style type="text/css">
            /* Hide right column (order details table, customer details) */
            .woocommerce-order-received .woocommerce-order .right-box,
            .woocommerce-order-received .right-box {
                display: none !important;
            }

            /*
             * ROBUST: Hide the entire left-box, then make ONLY the thank you message visible.
             * Using font-size:0 + color:transparent to hide text nodes that CSS selectors can't target
             * (e.g. "Rental Date(s)" rendered as bare text + <strong> inside .left-box).
             */
            .woocommerce-order-received .woocommerce-order .left-box,
            .woocommerce-order-received .left-box {
                font-size: 0 !important;
                color: transparent !important;
                line-height: 0 !important;
            }
            /* Hide ALL child elements inside left-box */
            .woocommerce-order-received .woocommerce-order .left-box > *,
            .woocommerce-order-received .left-box > * {
                display: none !important;
            }
            /* Show ONLY our thank you message container and restore text inside it.
             * IMPORTANT: Must use a concrete color (not inherit) because the parent
             * .left-box has color:transparent to hide bare text nodes. */
            .woocommerce-order-received .woocommerce-order .left-box .rentopian-thank-you-message,
            .woocommerce-order-received .woocommerce-order .left-box .rentopian-thank-you-only-mode,
            .woocommerce-order-received .left-box .rentopian-thank-you-message,
            .woocommerce-order-received .left-box .rentopian-thank-you-only-mode {
                display: block !important;
                font-size: 16px !important;
                color: #333333 !important;
                line-height: 1.6 !important;
                margin: 20px 0;
                padding: 20px;
            }
            .woocommerce-order-received .left-box .rentopian-thank-you-message *,
            .woocommerce-order-received .left-box .rentopian-thank-you-only-mode * {
                display: block !important;
                font-size: inherit !important;
                color: inherit !important;
                line-height: inherit !important;
            }
            /* Inline elements inside thank you should stay inline */
            .woocommerce-order-received .left-box .rentopian-thank-you-message a,
            .woocommerce-order-received .left-box .rentopian-thank-you-message span,
            .woocommerce-order-received .left-box .rentopian-thank-you-message strong,
            .woocommerce-order-received .left-box .rentopian-thank-you-message em,
            .woocommerce-order-received .left-box .rentopian-thank-you-message b,
            .woocommerce-order-received .left-box .rentopian-thank-you-message i,
            .woocommerce-order-received .left-box .rentopian-thank-you-only-mode a,
            .woocommerce-order-received .left-box .rentopian-thank-you-only-mode span,
            .woocommerce-order-received .left-box .rentopian-thank-you-only-mode strong,
            .woocommerce-order-received .left-box .rentopian-thank-you-only-mode em,
            .woocommerce-order-received .left-box .rentopian-thank-you-only-mode b,
            .woocommerce-order-received .left-box .rentopian-thank-you-only-mode i {
                display: inline !important;
            }

            /* Fallback: also explicitly hide known sections anywhere on the page */
            .woocommerce-order-received .woocommerce-order-overview,
            .woocommerce-order-received .woocommerce-thankyou-order-details,
            .woocommerce-order-received .woocommerce-order-details,
            .woocommerce-order-received .woocommerce-customer-details,
            .woocommerce-order-received .woocommerce-bacs-bank-details,
            .woocommerce-order-received .order_details,
            .woocommerce-order-received .woocommerce-notice--success,
            .woocommerce-order-received .woocommerce-thankyou-order-received,
            .woocommerce-order-received section.woocommerce-order-details,
            .woocommerce-order-received section.woocommerce-customer-details,
            .woocommerce-order-received .rental-dates-summary-wrapper,
            .woocommerce-order-received #rntp-rental-dates-summary-wrapper,
            .woocommerce-order-received .rentopian-order-rental-data,
            .woocommerce-order-received .wc-pickup-address,
            .woocommerce-order-received .woocommerce-columns--addresses {
                display: none !important;
            }
            .woocommerce-order-received .woocommerce-order {
                display: block !important;
            }
        </style>
        <?php
    }

    /**
     * Output thank you message on woocommerce_thankyou (order-received, left column)
     *
     * @param int $order_id Order ID
     */
    public function render_thank_you_on_thankyou($order_id) {
        if (!is_wc_endpoint_url('order-received')) {
            return;
        }
        $thank_you_message = $this->get_thank_you_message();
        if (empty($thank_you_message)) {
            return;
        }
        echo '<div class="rentopian-thank-you-message">';
        echo wp_kses_post(wpautop($thank_you_message));
        echo '</div>';
    }

    /**
     * Output rental data on woocommerce_thankyou (order-received, left column)
     *
     * @param int $order_id Order ID
     */
    public function render_rental_data_on_thankyou($order_id) {
        if (!is_wc_endpoint_url('order-received')) {
            return;
        }
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }
        $this->render_rental_data_section($order);
    }

    /**
     * Display thank you message on order details (e.g. my-account view order)
     * Skipped on order-received so we don't duplicate (content comes from woocommerce_thankyou).
     *
     * @param WC_Order $order The order object
     */
    public function display_thank_you_message($order) {
        if (is_wc_endpoint_url('order-received')) {
            return;
        }
        $thank_you_message = $this->get_thank_you_message();
        if (empty($thank_you_message)) {
            return;
        }
        echo '<div class="rentopian-thank-you-message">';
        echo wp_kses_post(wpautop($thank_you_message));
        echo '</div>';
    }

    /**
     * Display rental order data on order details (e.g. after order table on my-account view order).
     * Skipped on order-received so we don't duplicate (content comes from woocommerce_thankyou).
     *
     * @param int $order_id The order ID
     */
    public function display_rental_order_data($order_id) {
        if (is_wc_endpoint_url('order-received')) {
            return;
        }
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }
        $this->render_rental_data_section($order);
    }

    /**
     * Display rental order data on my-account order view
     *
     * @param int $order_id The order ID
     */
    public function display_rental_order_data_account($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        $this->render_rental_data_section($order);
    }

    /**
     * Render the rental data section
     *
     * @param WC_Order $order The order object
     */
    private function render_rental_data_section($order) {
        $rental_data = $this->get_rental_order_data($order);
        
        $has_any = !empty($rental_data['rental_summary']) || !empty($rental_data['event_details'])
            || !empty($rental_data['custom_fields']) || !empty($rental_data['delivery_info'])
            || !empty($rental_data['checkout_photos']);
        if (!$has_any) {
            return;
        }

        echo '<div class="rentopian-order-rental-data">';

        // Inspiration Photos Section (thank you + order details)
        if (!empty($rental_data['checkout_photos'])) {
            $section_title = get_option('rental_checkout_photo_upload_label', '');
            if ($section_title === '') {
                $section_title = __('Inspiration Photos', 'rentopian-sync');
            }
            echo '<h2 class="rentopian-order-section-title">' . esc_html($section_title) . '</h2>';
            echo '<div class="rentopian-checkout-photos-list">';
            foreach ($rental_data['checkout_photos'] as $photo) {
                if (empty($photo['url'])) {
                    continue;
                }
                $name = isset($photo['name']) ? $photo['name'] : __('Photo', 'rentopian-sync');
                echo '<div class="rentopian-checkout-photo-item">';
                echo '<a href="' . esc_url($photo['url']) . '" target="_blank" rel="noopener noreferrer" class="rentopian-checkout-photo-link">';
                echo '<img src="' . esc_url($photo['url']) . '" alt="' . esc_attr($name) . '" class="rentopian-checkout-photo-thumb" loading="lazy" />';
                echo '</a>';
                echo '<span class="rentopian-checkout-photo-name">' . esc_html($name) . '</span>';
                echo '</div>';
            }
            echo '</div>';
        }
        
        // Rental Summary Section (dates, main quote)
        if (!empty($rental_data['rental_summary'])) {
            echo '<h2 class="rentopian-order-section-title">' . esc_html__('Rental Summary', 'rentopian-sync') . '</h2>';
            echo '<table class="rentopian-order-details-table">';
            echo '<tbody>';
            foreach ($rental_data['rental_summary'] as $key => $item) {
                if (!empty($item['value'])) {
                    echo '<tr>';
                    echo '<th>' . esc_html($item['label']) . ':</th>';
                    echo '<td>' . esc_html($item['value']) . '</td>';
                    echo '</tr>';
                }
            }
            echo '</tbody>';
            echo '</table>';
        }

        // Delivery Time(s) Section (under Rental Summary)
        if (!empty($rental_data['delivery_info'])) {
            echo '<h2 class="rentopian-order-section-title">' . esc_html__('Delivery Time(s)', 'rentopian-sync') . '</h2>';
            echo '<table class="rentopian-order-details-table">';
            echo '<tbody>';
            
            foreach ($rental_data['delivery_info'] as $key => $item) {
                if (!empty($item['value'])) {
                    echo '<tr>';
                    echo '<th>' . esc_html($item['label']) . ':</th>';
                    echo '<td>' . esc_html($item['value']) . '</td>';
                    echo '</tr>';
                }
            }
            
            echo '</tbody>';
            echo '</table>';
        }

        // Event Details Section
        if (!empty($rental_data['event_details'])) {
            echo '<h2 class="rentopian-order-section-title">' . esc_html__('Event Details', 'rentopian-sync') . '</h2>';
            echo '<table class="rentopian-order-details-table">';
            echo '<tbody>';
            
            foreach ($rental_data['event_details'] as $key => $item) {
                if (!empty($item['value'])) {
                    echo '<tr>';
                    echo '<th>' . esc_html($item['label']) . ':</th>';
                    echo '<td>' . esc_html($item['value']) . '</td>';
                    echo '</tr>';
                }
            }
            
            echo '</tbody>';
            echo '</table>';
        }

        // Custom Fields / Additional Information Section
        if (!empty($rental_data['custom_fields'])) {
            echo '<h2 class="rentopian-order-section-title">' . esc_html__('Additional Information', 'rentopian-sync') . '</h2>';
            echo '<table class="rentopian-order-details-table">';
            echo '<tbody>';
            
            foreach ($rental_data['custom_fields'] as $key => $item) {
                if (!empty($item['value'])) {
                    echo '<tr>';
                    echo '<th>' . esc_html($item['label']) . ':</th>';
                    echo '<td>' . esc_html($item['value']) . '</td>';
                    echo '</tr>';
                }
            }
            
            echo '</tbody>';
            echo '</table>';
        }

        echo '</div>';
    }

    /**
     * Output rental order data in order emails (same sections as order-received and my-account).
     * Only runs when modern checkout layout is enabled; legacy handles classic mode.
     *
     * @param WC_Order $order       Order
     * @param bool     $sent_to_admin Whether email is to admin
     * @param bool     $plain_text  Whether email is plain text
     * @param WC_Email $email       Email object
     */
    public function render_rental_data_email($order, $sent_to_admin, $plain_text, $email) {
        if (!class_exists('Rental_Checkout_Layout_Config')) {
            return;
        }
        $config = Rental_Checkout_Layout_Config::get_instance();
        if (!$config->is_enabled()) {
            return;
        }

        $rental_data = $this->get_rental_order_data($order);
        $has_any = !empty($rental_data['rental_summary']) || !empty($rental_data['event_details'])
            || !empty($rental_data['custom_fields']) || !empty($rental_data['delivery_info'])
            || !empty($rental_data['checkout_photos']);
        if (!$has_any) {
            return;
        }

        if ($plain_text) {
            $this->render_rental_data_email_plain($rental_data);
        } else {
            $this->render_rental_data_email_html($rental_data);
        }
    }

    /**
     * Output rental data as plain text for emails
     *
     * @param array $rental_data From get_rental_order_data
     */
    private function render_rental_data_email_plain($rental_data) {
        $sections = array(
            'rental_summary'   => __('Rental Summary', 'rentopian-sync'),
            'delivery_info'    => __('Delivery Time(s)', 'rentopian-sync'),
            'event_details'    => __('Event Details', 'rentopian-sync'),
            'custom_fields'    => __('Additional Information', 'rentopian-sync'),
        );
        foreach ($sections as $key => $section_title) {
            if (empty($rental_data[$key])) {
                continue;
            }
            echo "\n" . $section_title . "\n";
            echo str_repeat('-', strlen($section_title)) . "\n";
            foreach ($rental_data[$key] as $item) {
                if (!empty($item['value'])) {
                    echo $item['label'] . ': ' . $item['value'] . "\n";
                }
            }
            echo "\n";
        }
        if (!empty($rental_data['checkout_photos'])) {
            $section_title = get_option('rental_checkout_photo_upload_label', '') ?: __('Inspiration Photos', 'rentopian-sync');
            echo "\n" . $section_title . "\n";
            echo str_repeat('-', strlen($section_title)) . "\n";
            foreach ($rental_data['checkout_photos'] as $photo) {
                echo (isset($photo['name']) ? $photo['name'] : __('Photo', 'rentopian-sync')) . "\n";
                if (!empty($photo['url'])) {
                    echo $photo['url'] . "\n";
                }
            }
            echo "\n";
        }
    }

    /**
     * Output rental data as HTML for emails (email-safe table markup)
     *
     * @param array $rental_data From get_rental_order_data
     */
    private function render_rental_data_email_html($rental_data) {
        $sections = array(
            'rental_summary'   => __('Rental Summary', 'rentopian-sync'),
            'delivery_info'    => __('Delivery Time(s)', 'rentopian-sync'),
            'event_details'    => __('Event Details', 'rentopian-sync'),
            'custom_fields'    => __('Additional Information', 'rentopian-sync'),
        );
        echo '<div class="rentopian-order-rental-data" style="margin-top:20px;">';
        foreach ($sections as $key => $section_title) {
            if (empty($rental_data[$key])) {
                continue;
            }
            echo '<h2 style="color:#333;font-size:1.2em;margin:25px 0 15px;padding-bottom:10px;border-bottom:2px solid #007cba;">' . esc_html($section_title) . '</h2>';
            echo '<table style="width:100%;border-collapse:collapse;margin-bottom:20px;">';
            echo '<tbody>';
            foreach ($rental_data[$key] as $item) {
                if (!empty($item['value'])) {
                    echo '<tr>';
                    echo '<th style="padding:10px 15px;border-bottom:1px solid #eee;text-align:left;width:35%;font-weight:500;color:#666;background:#f9f9f9;">' . esc_html($item['label']) . ':</th>';
                    echo '<td style="padding:10px 15px;border-bottom:1px solid #eee;text-align:left;color:#333;">' . esc_html($item['value']) . '</td>';
                    echo '</tr>';
                }
            }
            echo '</tbody></table>';
        }
        if (!empty($rental_data['checkout_photos'])) {
            $section_title = get_option('rental_checkout_photo_upload_label', '') ?: __('Inspiration Photos', 'rentopian-sync');
            echo '<h2 style="color:#333;font-size:1.2em;margin:25px 0 15px;padding-bottom:10px;border-bottom:2px solid #007cba;">' . esc_html($section_title) . '</h2>';
            echo '<table style="width:100%;border-collapse:collapse;margin-bottom:20px;">';
            echo '<tbody>';
            foreach ($rental_data['checkout_photos'] as $photo) {
                $name = isset($photo['name']) ? $photo['name'] : __('Photo', 'rentopian-sync');
                $url  = isset($photo['url']) ? $photo['url'] : '';
                echo '<tr>';
                echo '<td style="padding:10px 15px;border-bottom:1px solid #eee;text-align:left;color:#333;">' . esc_html($name) . '</td>';
                echo '<td style="padding:10px 15px;border-bottom:1px solid #eee;text-align:left;">';
                if ($url) {
                    echo '<a href="' . esc_url($url) . '" target="_blank" rel="noopener noreferrer" style="color:#007cba;">' . esc_html__('View', 'rentopian-sync') . '</a>';
                }
                echo '</td></tr>';
            }
            echo '</tbody></table>';
        }
        echo '</div>';
    }

    /**
     * Get rental order data from order meta.
     *
     * Coverage (all dynamic / extensible):
     * - rental_summary: dates + main quote (DB + meta).
     * - event_details / delivery_info: event type, times, outdoor, multi_day, flexible_* via
     *   get_section_placement_map(); Yes/No when field type is checkbox (type-based).
     * - custom_fields: referral, order notes, _rental_custom_fields, rental_custom_fields option by slug,
     *   then any other _rental_* order meta. Slugs in placement map are skipped (already in event/delivery).
     * - Yes/No display: when field type is checkbox (from option or Field Registry), value is shown as Yes/No.
     *   Unknown keys fall back to get_yes_no_display_keys(). Filter: rentopian_order_display_yes_no_keys.
     *
     * @param WC_Order $order The order object
     * @return array Organized rental data
     */
    private function get_rental_order_data($order) {
        $order_id = $order->get_id();
        $data = array(
            'rental_summary'   => array(),
            'event_details'    => array(),
            'custom_fields'    => array(),
            'delivery_info'    => array(),
            'checkout_photos'  => array(),
        );

        // Rental Summary (dates and quote from order meta)
        $rental_start = $order->get_meta('_rental_start_date');
        $rental_end   = $order->get_meta('_rental_end_date');
        if ($rental_start || $rental_end) {
            $date_format = 'M j, g:i A';
            if (get_option('rental_hide_time_pickers')) {
                $date_format = 'M j';
            }
            $dates_display = '';
            if ($rental_start) {
                $dates_display = date_i18n($date_format, strtotime($rental_start));
            }
            if ($rental_end) {
                $dates_display .= ($dates_display ? ' &rarr; ' : '') . date_i18n($date_format, strtotime($rental_end));
            }
            if ($dates_display) {
                $data['rental_summary']['rental_dates'] = array(
                    'label' => __('Rental Date(s)', 'rentopian-sync'),
                    'value' => $dates_display,
                );
            }
        }
        // Main order/quote number from rental_order_relations table
        global $wpdb, $rental_tables;
        if (!empty($rental_tables['order_relations'])) {
            $rental_order_relations = $wpdb->prefix . $rental_tables['order_relations'];
            $rental_order_id = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT `rental_id` FROM `{$rental_order_relations}` WHERE `id` = %d",
                    $order_id
                )
            );
            if ($rental_order_id) {
                $custom_order_text = get_option('rental_order_text', '');
                $custom_order_label = $custom_order_text !== '' ? $custom_order_text : __('Order', 'rentopian-sync');
                $data['rental_summary']['main_quote'] = array(
                    'label' => sprintf(__('Main %s number', 'rentopian-sync'), $custom_order_label),
                    'value' => $rental_order_id,
                );
            }
        }
        if (empty($data['rental_summary']['main_quote'])) {
            $main_quote = $order->get_meta('_rental_main_quote_number');
            if ($main_quote) {
                $data['rental_summary']['main_quote'] = array(
                    'label' => __('Main Quote number', 'rentopian-sync'),
                    'value' => $main_quote,
                );
            }
        }

        // Event Details (prefer title meta; fallback to ID meta and resolve)
        $event_type = $order->get_meta('_rental_event_type');
        if (($event_type === '' || $event_type === null)) {
            $event_type = $order->get_meta('rental_event_types_id');
            if ($event_type === '' || $event_type === null) {
                $event_type = $order->get_meta('_rental_rental_event_types_id');
            }
        }
        if ($event_type !== '' && $event_type !== null) {
            $data['event_details']['event_type'] = array(
                'label' => __('Event Type', 'rentopian-sync'),
                'value' => $this->resolve_order_meta_value_to_label('_rental_event_type', $event_type),
            );
        }

        $event_time = $order->get_meta('_rental_event_time');
        if ($event_time) {
            $data['event_details']['event_time'] = array(
                'label' => __('Event Time', 'rentopian-sync'),
                'value' => $event_time,
            );
        }

        // Event/delivery keys that may use different meta keys or slugs (dynamic placement + Yes/No)
        $this->add_placement_mapped_fields($order, $data);

        // Referral Source (prefer title meta; fallback to ID meta and resolve)
        $referral_source = $order->get_meta('_rental_referral_source');
        if (($referral_source === '' || $referral_source === null)) {
            $referral_source = $order->get_meta('rental_referral_source_id');
            if ($referral_source === '' || $referral_source === null) {
                $referral_source = $order->get_meta('_rental_rental_referral_source_id');
            }
        }
        if ($referral_source !== '' && $referral_source !== null) {
            $data['custom_fields']['referral_source'] = array(
                'label' => __('How Did You Find Us', 'rentopian-sync'),
                'value' => $this->resolve_order_meta_value_to_label('_rental_referral_source', $referral_source),
            );
        }

        // NOTE: Billing email is NOT added here - it's already displayed in the billing address section
        // by WooCommerce, so adding it here would create a duplicate in "Additional Information".

        // Order Notes
        $order_notes = $order->get_customer_note();
        if ($order_notes) {
            $data['custom_fields']['order_notes'] = array(
                'label' => __('Order Notes', 'rentopian-sync'),
                'value' => $order_notes,
            );
        }

        // Delivery Time(s) (under Rental Summary in display order)
        $delivery_time = $order->get_meta('_rental_delivery_time');
        if ($delivery_time !== '' && $delivery_time !== null) {
            $data['delivery_info']['delivery_time'] = array(
                'label' => __('Delivery Window', 'rentopian-sync'),
                'value' => $this->resolve_order_meta_value_to_label('_rental_delivery_time', $delivery_time),
            );
        }

        $pickup_time = $order->get_meta('_rental_pickup_time');
        if ($pickup_time !== '' && $pickup_time !== null) {
            $data['delivery_info']['pickup_time'] = array(
                'label' => __('Pickup Window', 'rentopian-sync'),
                'value' => $this->resolve_order_meta_value_to_label('_rental_pickup_time', $pickup_time),
            );
        }

        // NOTE: Pickup address is NOT added here - WooCommerce already displays it in a separate
        // formatted "Pickup address" section on the order-received and my-account pages.
        // Adding it here would create a duplicate under "Delivery Time(s)".

        // Flexible delivery/pickup are added by add_placement_mapped_fields() so they support dynamic keys

        // Custom Fields from Rentopian (_rental_custom_fields meta)
        $custom_fields = $order->get_meta('_rental_custom_fields');
        if (!empty($custom_fields) && is_array($custom_fields)) {
            foreach ($custom_fields as $field_id => $field_value) {
                if (!empty($field_value)) {
                    $field_label = $order->get_meta('_rental_custom_field_label_' . $field_id);
                    if (empty($field_label)) {
                        $field_label = ucwords(str_replace(array('_', '-'), ' ', $field_id));
                    }
                    if (is_array($field_value)) {
                        $field_value = implode(', ', $field_value);
                    }
                    $field_value = (string) $field_value;
                    if ($this->is_checkbox_field_for_display('_rental_' . $field_id, $field_id, null)) {
                        $field_value = $this->format_yes_no_display_value($field_value);
                    }
                    $data['custom_fields']['custom_' . $field_id] = array(
                        'label' => $field_label,
                        'value' => $field_value,
                    );
                }
            }
        }

        // Dynamic custom fields stored by slug (from rental_custom_fields option)
        // Skip slugs we already output (referral_source, order_notes, or any slug_* already added)
        $slugs_used = array('referral_source', 'order_notes');
        foreach (array_keys($data['custom_fields']) as $k) {
            if (strpos($k, 'slug_') === 0) {
                $slugs_used[] = str_replace('slug_', '', $k);
            } else {
                $slugs_used[] = $k;
            }
        }
        $placement_map = $this->get_section_placement_map();
        $slugs_in_placement = array();
        foreach (array('event_details', 'delivery_info') as $section) {
            if (!empty($placement_map[$section]) && is_array($placement_map[$section])) {
                $slugs_in_placement = array_merge($slugs_in_placement, array_keys($placement_map[$section]));
            }
        }

        $rental_custom_fields_option = get_option('rental_custom_fields', array());
        if (!empty($rental_custom_fields_option) && is_array($rental_custom_fields_option)) {
            foreach ($rental_custom_fields_option as $group) {
                if (!is_array($group)) {
                    continue;
                }
                foreach ($group as $field) {
                    if (!is_array($field) || empty($field['slug'])) {
                        continue;
                    }
                    $slug = $field['slug'];
                    if (in_array($slug, $slugs_used, true)) {
                        continue;
                    }
                    if (in_array($slug, $slugs_in_placement, true)) {
                        continue;
                    }
                    $val = $order->get_meta($slug);
                    if ($val === '' || $val === null) {
                        continue;
                    }
                    if (is_array($val)) {
                        $val = implode(', ', $val);
                    }
                    $val = (string) $val;
                    if ($this->is_checkbox_field_for_display('_rental_' . $slug, $slug, $field)) {
                        $val = $this->format_yes_no_display_value($val);
                    }
                    $label = isset($field['title']) ? $field['title'] : ucwords(str_replace(array('_', '-'), ' ', $slug));
                    $key = 'slug_' . $slug;
                    $data['custom_fields'][$key] = array(
                        'label' => $label,
                        'value' => $val,
                    );
                    $slugs_used[] = $slug;
                }
            }
        }

        // Any other _rental_* order meta (from dynamic layout/component fields) not yet in sections
        $known_meta_keys = array(
            '_rental_start_date', '_rental_end_date', '_rental_main_quote_number',
            '_rental_event_type', '_rental_event_time', '_rental_outdoor_event', '_rental_outside_event', '_rental_multi_day',
            '_rental_referral_source', '_rental_delivery_time', '_rental_pickup_time',
            '_rental_flexible_delivery', '_rental_flexible_pickup', '_rental_custom_fields',
            '_rental_rental_event_types_id', '_rental_rental_referral_source_id',
            // Days is redundant - already shown via rental dates
            '_rental_days',
            // Pickup address fields - already displayed in WooCommerce formatted "Pickup address" section
            // Note: These keys have double "rental_" because save_rental_display_order_meta() 
            // prefixes rental_pick_up_* POST keys with _rental_ to get _rental_rental_pick_up_*
            '_rental_rental_pick_up_address_1', '_rental_rental_pick_up_address_2',
            '_rental_rental_pick_up_city', '_rental_rental_pick_up_state', '_rental_rental_pick_up_postcode', '_rental_rental_pick_up_country',
            '_rental_rental_different_pick_up_address',
            // Checkout photo upload: stored as array of photo data, not for raw display
            '_rental_checkout_photos', '_rental_checkout_photos_count', '_rental_checkout_photos_attached',
            // Photo IDs (from hidden form fields) — never display raw IDs
            '_rental_rental_checkout_photo_ids', '_rental_checkout_photo_ids',
            'rental_checkout_photo_ids',
            // Pickup fields saved by rental_save_pickup_fields() without double prefix
            'rental_pick_up_address_1', 'rental_pick_up_address_2',
            'rental_pick_up_city', 'rental_pick_up_state', 'rental_pick_up_postcode', 'rental_pick_up_country',
            'rental_different_pick_up_address',
        );
        $meta_keys_in_placement = array();
        foreach (array('event_details', 'delivery_info') as $section) {
            if (!empty($placement_map[$section]) && is_array($placement_map[$section])) {
                foreach (array_keys($placement_map[$section]) as $k) {
                    $meta_keys_in_placement[] = (strpos($k, '_rental_') === 0) ? $k : '_rental_' . $k;
                }
            }
        }
        $all_meta = $order->get_meta_data();
        foreach ($all_meta as $meta) {
            $key = is_object($meta) && method_exists($meta, 'get_key') ? $meta->get_key() : (isset($meta->key) ? $meta->key : '');
            if (strpos($key, '_rental_') !== 0) {
                continue;
            }
            if (in_array($key, $known_meta_keys, true)) {
                continue;
            }
            if (in_array($key, $meta_keys_in_placement, true)) {
                continue;
            }
            $value = is_object($meta) && method_exists($meta, 'get_value') ? $meta->get_value() : (isset($meta->value) ? $meta->value : '');
            if ($value === '' && $value !== '0') {
                continue;
            }
            if (is_array($value)) {
                $value = $this->order_meta_array_to_display_string($value);
            }
            $value = (string) $value;
            $field_id = str_replace('_rental_', '', $key);
            $display_value = $this->get_display_value_for_order_meta($key, $value);
            $label = $this->get_label_for_rental_meta_key($key, $field_id);
            $data['custom_fields']['meta_' . $field_id] = array(
                'label' => $label,
                'value' => (string) $display_value !== '' ? $display_value : $value,
            );
        }

        // Checkout inspiration photos (for thank you, order details, email)
        $photos_meta = $order->get_meta('_rental_checkout_photos');
        if (!empty($photos_meta) && is_array($photos_meta)) {
            $upload_dir = wp_upload_dir();
            foreach ($photos_meta as $photo) {
                if (!is_array($photo)) {
                    continue;
                }
                $path = isset($photo['path']) ? $photo['path'] : '';
                $url = isset($photo['url']) && $photo['url'] ? $photo['url'] : '';

                // Build URL from path if URL not stored but path exists
                if (!$url && $path && $upload_dir['basedir'] && strpos($path, $upload_dir['basedir']) === 0) {
                    $url = $upload_dir['baseurl'] . substr($path, strlen($upload_dir['basedir']));
                }

                // Skip only if we have neither a valid file nor a URL
                if (!$url && (!$path || !file_exists($path))) {
                    continue;
                }

                $name = isset($photo['original_name']) ? $photo['original_name'] : (isset($photo['filename']) ? $photo['filename'] : __('Photo', 'rentopian-sync'));
                $data['checkout_photos'][] = array(
                    'url'  => $url,
                    'name' => $name,
                );
            }
        }

        // Allow plugins to modify the data
        $data = apply_filters('rentopian_order_rental_data', $data, $order);

        // Apply admin review display settings (hides items on thank-you, emails, order-details)
        $data = $this->apply_review_display_filter($data);

        return $data;
    }

    /**
     * Remove sections/items from rental order data based on admin review display settings.
     *
     * @param array $data Rental order data from get_rental_order_data().
     * @return array Filtered data.
     */
    private function apply_review_display_filter($data) {
        if (!class_exists('Rental_Checkout_Layout_Manager')) {
            return $data;
        }
        $settings = Rental_Checkout_Layout_Manager::get_review_display_settings();

        // Rental dates within rental_summary
        if (empty($settings['rental_dates']) && isset($data['rental_summary']['rental_dates'])) {
            unset($data['rental_summary']['rental_dates']);
        }

        return $data;
    }

    /**
     * Convert order meta array value to a display-safe string.
     * Handles both flat arrays (scalars) and nested arrays (e.g. photo records).
     *
     * @param array $value Meta value that is an array
     * @return string Safe string for display
     */
    private function order_meta_array_to_display_string($value) {
        if (!is_array($value)) {
            return (string) $value;
        }
        $flat = array();
        foreach ($value as $item) {
            if (is_array($item)) {
                if (isset($item['original_name'])) {
                    $flat[] = $item['original_name'];
                } elseif (isset($item['filename'])) {
                    $flat[] = $item['filename'];
                } else {
                    $flat[] = '(' . count($item) . ' items)';
                }
            } else {
                $flat[] = $item;
            }
        }
        return implode(', ', $flat);
    }

    /**
     * Whether a field should display as Yes/No (checkbox/boolean).
     * Uses field type when available (option or registry); falls back to legacy key list.
     * New checkbox keys are handled automatically when registered with type 'checkbox'.
     *
     * @param string      $meta_key         Full meta key e.g. _rental_outside_event
     * @param string      $slug_or_field_id Slug or field ID e.g. outside_event
     * @param array|null  $option_field     Field from rental_custom_fields option (has 'type' numeric or string)
     * @return bool
     */
    private function is_checkbox_field_for_display($meta_key, $slug_or_field_id, $option_field = null) {
        // 1. Option field from rental_custom_fields: type 3 = checkbox (Rentopian API)
        if (is_array($option_field) && isset($option_field['type'])) {
            $t = $option_field['type'];
            if ($t === 'checkbox' || (is_numeric($t) && (int) $t === 3)) {
                return true;
            }
        }

        // 2. Field Registry: resolve by slug/field_id and check type
        if (class_exists('Rental_Checkout_Field_Registry')) {
            $registry = Rental_Checkout_Field_Registry::get_instance();
            $candidates = array(
                'rental_custom_' . $slug_or_field_id,
                'rental_' . $slug_or_field_id,
                $slug_or_field_id,
                $meta_key,
                str_replace('_rental_', '', $meta_key),
            );
            foreach ($candidates as $field_id) {
                $field = $registry->get_field($field_id);
                if ($field && isset($field['type']) && $field['type'] === 'checkbox') {
                    return true;
                }
            }
        }

        // 3. Fallback: legacy key list (unknown/dynamic keys not in registry)
        $keys = $this->get_yes_no_display_keys();
        return in_array($meta_key, $keys, true) || in_array($slug_or_field_id, $keys, true);
    }

    /**
     * Meta keys and slugs that should display as Yes/No when type is unknown (fallback).
     * Used only when the field is not in rental_custom_fields option and not in the Field Registry.
     * Extensible via filter rentopian_order_display_yes_no_keys.
     *
     * @return array [ '_rental_outdoor_event', 'outdoor', ... ]
     */
    private function get_yes_no_display_keys() {
        $keys = array(
            '_rental_outdoor_event',
            '_rental_multi_day',
            '_rental_flexible_delivery',
            '_rental_flexible_pickup',
            'rental_outdoor_event',
            'rental_multi_day',
            'rental_flexible_delivery',
            'rental_flexible_pickup',
            'outdoor',
            'outdoor_event',
            'outside_event',
            'multi_day',
            'flexible_delivery',
            'flexible_pickup',
        );
        return apply_filters('rentopian_order_display_yes_no_keys', $keys);
    }

    /**
     * Section placement: which meta keys or slugs go to event_details vs delivery_info.
     * Keys can be _rental_* meta keys or slugs (no prefix). Extensible via filter.
     *
     * @return array [ 'event_details' => [ 'meta_key_or_slug' => 'Label', ... ], 'delivery_info' => [ ... ] ]
     */
    private function get_section_placement_map() {
        $map = array(
            'event_details' => array(
                '_rental_outdoor_event' => __('Outdoor Event', 'rentopian-sync'),
                '_rental_outside_event' => __('Outside Event', 'rentopian-sync'),
                '_rental_multi_day'     => __('Multi-Day Event', 'rentopian-sync'),
                'outdoor'               => __('Outdoor Event', 'rentopian-sync'),
                'outdoor_event'         => __('Outdoor Event', 'rentopian-sync'),
                'outside_event'         => __('Outside Event', 'rentopian-sync'),
                'multi_day'             => __('Multi-Day Event', 'rentopian-sync'),
            ),
            'delivery_info' => array(
                '_rental_flexible_delivery' => __('Flexible with Delivery Time', 'rentopian-sync'),
                '_rental_flexible_pickup'   => __('Flexible with Pickup Time', 'rentopian-sync'),
                'flexible_delivery'         => __('Flexible with Delivery Time', 'rentopian-sync'),
                'flexible_pickup'           => __('Flexible with Pickup Time', 'rentopian-sync'),
            ),
        );
        return apply_filters('rentopian_order_display_section_placement', $map);
    }

    /**
     * Format a value for Yes/No display (checkbox/boolean).
     *
     * @param mixed $value Raw value (yes, 1, true, etc.)
     * @return string
     */
    private function format_yes_no_display_value($value) {
        if ($value === 'yes' || $value === '1' || $value === 1 || $value === true || $value === 'on') {
            return __('Yes', 'rentopian-sync');
        }
        return __('No', 'rentopian-sync');
    }

    /**
     * Add fields that are mapped to event_details or delivery_info (dynamic keys + Yes/No).
     * Reads from get_section_placement_map(); for each key tries order meta by key and by _rental_ prefix.
     * 
     * NOTE: Deduplicates by label to avoid showing the same field twice (e.g., _rental_multi_day and multi_day
     * both resolve to "Multi-Day Event" label - only the first match is displayed).
     */
    private function add_placement_mapped_fields($order, &$data) {
        $placement = $this->get_section_placement_map();

        foreach (array('event_details', 'delivery_info') as $section) {
            if (empty($placement[$section]) || !is_array($placement[$section])) {
                continue;
            }
            
            // Track labels already added to this section to avoid duplicates
            $labels_added = array();
            if (!empty($data[$section])) {
                foreach ($data[$section] as $field) {
                    if (isset($field['label'])) {
                        $labels_added[] = $field['label'];
                    }
                }
            }
            
            foreach ($placement[$section] as $key => $label) {
                // Skip if this label has already been added (prevents duplicates like Multi-Day Event)
                if (in_array($label, $labels_added, true)) {
                    continue;
                }
                
                $meta_key = (strpos($key, '_rental_') === 0) ? $key : '_rental_' . $key;
                $val      = $order->get_meta($meta_key);
                if ($val === '' && $val !== '0' && $meta_key !== $key) {
                    $val = $order->get_meta($key);
                }
                if ($val === '' || $val === null) {
                    continue;
                }
                $is_yes_no = $this->is_checkbox_field_for_display($meta_key, $key, null);
                $value     = $is_yes_no ? $this->format_yes_no_display_value($val) : (string) (is_array($val) ? implode(', ', $val) : $val);
                $data[$section][$key] = array(
                    'label' => $label,
                    'value' => $value,
                );
                
                // Mark this label as added to prevent duplicates
                $labels_added[] = $label;
            }
        }
    }

    /**
     * Get display label for a _rental_* meta key (from registry or humanized key).
     *
     * @param string $meta_key  Full meta key e.g. _rental_rental_outdoor_event
     * @param string $field_id  Field ID without prefix e.g. rental_outdoor_event
     * @return string
     */
    private function get_label_for_rental_meta_key($meta_key, $field_id) {
        if (class_exists('Rental_Checkout_Field_Registry')) {
            $registry = Rental_Checkout_Field_Registry::get_instance();
            $field   = $registry->get_field($field_id);
            if ($field && !empty($field['label'])) {
                return $field['label'];
            }
        }
        return ucwords(str_replace(array('_', '-'), ' ', $field_id));
    }

    /**
     * Filter billing address to hide visual fields
     *
     * @param array    $address The address array
     * @param WC_Order $order   The order object
     * @return array Modified address
     */
    public function filter_billing_address($address, $order) {
        // Only filter on frontend order views
        if (is_admin() && !wp_doing_ajax()) {
            return $address;
        }

        // Check if modern checkout is enabled
        $config = Rental_Checkout_Layout_Config::get_instance();
        if (!$config->is_enabled()) {
            return $address;
        }

        $hidden = $this->get_hidden_visual_fields();
        foreach ($hidden as $field) {
            $field_key = str_replace('billing_', '', $field);
            if (isset($address[$field_key])) {
                unset($address[$field_key]);
            }
        }

        return $address;
    }

    /**
     * Filter shipping address to hide visual fields
     *
     * @param array    $address The address array
     * @param WC_Order $order   The order object
     * @return array Modified address
     */
    public function filter_shipping_address($address, $order) {
        // Only filter on frontend order views
        if (is_admin() && !wp_doing_ajax()) {
            return $address;
        }

        // Check if modern checkout is enabled
        $config = Rental_Checkout_Layout_Config::get_instance();
        if (!$config->is_enabled()) {
            return $address;
        }

        $hidden = $this->get_hidden_visual_fields();
        foreach ($hidden as $field) {
            $field_key = str_replace('shipping_', '', $field);
            if (isset($address[$field_key])) {
                unset($address[$field_key]);
            }
        }

        return $address;
    }

    /**
     * Add CSS for order display
     */
    public function add_order_display_styles() {
        // Only on order-received and my-account pages
        if (!is_wc_endpoint_url('order-received') && !is_wc_endpoint_url('view-order')) {
            return;
        }

        ?>
        <style type="text/css">
            /* Thank You Message */
            .rentopian-thank-you-message {
                background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
                border: 1px solid #dee2e6;
                border-left: 4px solid #6c757d;
                padding: 20px 25px;
                margin-bottom: 30px;
                border-radius: 4px;
            }
            
            .rentopian-thank-you-message p {
                margin: 0 0 10px 0;
                font-size: 16px;
                line-height: 1.6;
            }
            
            .rentopian-thank-you-message p:last-child {
                margin-bottom: 0;
            }

            /* Rental Order Data Section */
            .rentopian-order-rental-data {
                margin-top: 30px;
                padding-top: 20px;
                border-top: 1px solid #e5e5e5;
            }

            .rentopian-order-section-title {
                font-size: 1.2em;
                font-weight: 600;
                margin: 25px 0 15px 0;
                padding-bottom: 10px;
                border-bottom: 2px solid #dbdbdb;
                color: #333;
            }

            .rentopian-order-section-title:first-child {
                margin-top: 0;
            }

            .rentopian-order-details-table {
                width: 100%;
                border-collapse: collapse;
                margin-bottom: 20px;
            }

            .rentopian-order-details-table th,
            .rentopian-order-details-table td {
                padding: 10px 15px;
                border-bottom: 1px solid #eee;
                text-align: left;
            }

            .rentopian-order-details-table th {
                width: 35%;
                font-weight: 500;
                color: #666;
                background: #f9f9f9;
            }

            .rentopian-order-details-table td {
                color: #333;
            }

            .rentopian-order-details-table tr:last-child th,
            .rentopian-order-details-table tr:last-child td {
                border-bottom: none;
            }

            .rentopian-checkout-photos-list {
                display: flex;
                flex-wrap: wrap;
                gap: 15px;
                margin-bottom: 20px;
            }
            .rentopian-checkout-photo-item {
                display: flex;
                flex-direction: column;
                align-items: center;
                max-width: 120px;
            }
            .rentopian-checkout-photo-link {
                display: block;
                line-height: 0;
            }
            .rentopian-checkout-photo-thumb {
                max-width: 100px;
                max-height: 100px;
                width: auto;
                height: auto;
                object-fit: cover;
                border: 1px solid #eee;
                border-radius: 4px;
            }
            .rentopian-checkout-photo-name {
                font-size: 0.85em;
                color: #666;
                margin-top: 6px;
                word-break: break-word;
                text-align: center;
            }

            @media screen and (max-width: 768px) {
                .rentopian-order-details-table th,
                .rentopian-order-details-table td {
                    display: block;
                    width: 100%;
                }
                
                .rentopian-order-details-table th {
                    border-bottom: none;
                    padding-bottom: 5px;
                }
                
            .rentopian-order-details-table td {
                padding-top: 5px;
                }
            }

            /* Thank you message first on the left: move to top of left column */
            .woocommerce-order .left-box,
            .woocommerce-order-received .left-box {
                display: flex;
                flex-direction: column;
            }
            .woocommerce-order .left-box .rentopian-thank-you-message,
            .woocommerce-order .left-box .rentopian-thank-you-only-mode,
            .woocommerce-order-received .left-box .rentopian-thank-you-message,
            .woocommerce-order-received .left-box .rentopian-thank-you-only-mode {
                order: -1;
            }
            /* Hide theme's duplicate thank you / rental dates / main number when our block is present */
            .woocommerce-order .left-box > .woocommerce-notice--success:not(.rentopian-thank-you-message):not(.rentopian-thank-you-only-mode),
            .woocommerce-order-received .left-box > .woocommerce-notice--success:not(.rentopian-thank-you-message):not(.rentopian-thank-you-only-mode) {
                display: none !important;
            }
        </style>
        <?php
    }

    /**
     * Register admin settings
     */
    public function register_settings() {
        register_setting('rentopian_checkout_settings', 'rental_checkout_thank_you_message', array(
            'type'              => 'string',
            'sanitize_callback' => 'wp_kses_post',
            'default'           => '',
        ));
    }

    // =========================================================================
    // Review Order Display Settings — Thank-You / My-Account / Emails
    // =========================================================================

    /**
     * Filter order item totals rows based on review display settings.
     *
     * Applies on thank-you page, my-account view-order, and WC order emails.
     * Removes rows (subtotal, shipping, tax, fees, order_total) when the
     * admin has unchecked them in the Checkout Layout Builder.
     *
     * @param array    $total_rows  Associative array of totals rows.
     * @param WC_Order $order       The order object.
     * @param string   $tax_display Tax display mode.
     * @return array Filtered rows.
     */
    public function filter_order_item_totals_by_review_display($total_rows, $order, $tax_display) {
        if (!class_exists('Rental_Checkout_Layout_Manager')) {
            return $total_rows;
        }
        $settings = Rental_Checkout_Layout_Manager::get_review_display_settings();

        // Map WC row keys → our setting keys
        $key_map = array(
            'cart_subtotal' => 'subtotal',
            'shipping'      => 'shipping',
            'discount'      => 'coupon',
            'order_total'   => 'order_total',
        );

        foreach ($total_rows as $row_key => $row) {
            // Known mapped keys
            if (isset($key_map[$row_key]) && empty($settings[$key_map[$row_key]])) {
                unset($total_rows[$row_key]);
                continue;
            }
            // Fees (key starts with 'fee_')
            if (strpos($row_key, 'fee_') === 0 && empty($settings['fees'])) {
                unset($total_rows[$row_key]);
                continue;
            }
            // Tax rows (key starts with 'tax_')
            if (strpos($row_key, 'tax_') === 0 && empty($settings['tax'])) {
                unset($total_rows[$row_key]);
                continue;
            }
        }

        return $total_rows;
    }

    /**
     * Output CSS on thank-you and my-account pages to hide review items
     * that CSS from the layout renderer doesn't cover (e.g. rental dates
     * summary on thank-you page, cart items in order details table).
     */
    public function output_review_display_css_thankyou() {
        if (!is_wc_endpoint_url('order-received') && !is_wc_endpoint_url('view-order')) {
            return;
        }
        if (!class_exists('Rental_Checkout_Layout_Manager')) {
            return;
        }
        $settings = Rental_Checkout_Layout_Manager::get_review_display_settings();
        $rules = array();

        if (empty($settings['rental_dates'])) {
            $rules[] = '.rental-dates-summary-wrapper';
            $rules[] = '#rntp-rental-dates-summary-wrapper';
        }
        if (empty($settings['cart_items'])) {
            $rules[] = '.woocommerce-order-details .woocommerce-table--order-details tbody';
            $rules[] = '.woocommerce-table--order-details thead';
        }

        if (empty($rules)) {
            return;
        }
        echo '<style type="text/css">';
        echo implode(",\n", array_map('esc_html', $rules)) . ' { display: none !important; }';
        echo '</style>';
    }
}
