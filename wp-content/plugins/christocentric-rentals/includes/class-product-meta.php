<?php

defined('ABSPATH') || exit;

/**
 * Product meta: daily rates, sale rates, kit flag (kit items in CCR_Product_Kits).
 */
final class CCR_Product_Meta
{
    public static function init(): void
    {
        add_action('woocommerce_product_options_general_product_data', [self::class, 'render_fields']);
        add_action('woocommerce_process_product_meta', [self::class, 'save_fields']);
        add_action('woocommerce_product_options_inventory_product_data', [self::class, 'render_inventory_note']);
    }

    public static function render_fields(): void
    {
        echo '<div class="options_group ccr-rental-fields">';

        woocommerce_wp_text_input([
            'id' => '_ccr_price_per_day',
            'label' => __('Daily rental rate (₵)', 'christocentric-rentals'),
            'desc_tip' => true,
            'description' => __('Regular price per calendar day.', 'christocentric-rentals'),
            'type' => 'number',
            'custom_attributes' => ['step' => '0.01', 'min' => '0'],
        ]);

        woocommerce_wp_text_input([
            'id' => '_ccr_sale_price_per_day',
            'label' => __('Sale daily rate (₵)', 'christocentric-rentals'),
            'desc_tip' => true,
            'description' => __('Promo price per day. Uses WooCommerce sale schedule if set on this product.', 'christocentric-rentals'),
            'type' => 'number',
            'custom_attributes' => ['step' => '0.01', 'min' => '0'],
        ]);

        woocommerce_wp_checkbox([
            'id' => '_ccr_is_featured',
            'label' => __('Featured on homepage', 'christocentric-rentals'),
        ]);

        woocommerce_wp_checkbox([
            'id' => '_ccr_is_new',
            'label' => __('Mark as new', 'christocentric-rentals'),
        ]);

        woocommerce_wp_text_input([
            'id' => '_ccr_rating',
            'label' => __('Display rating (1–5)', 'christocentric-rentals'),
            'type' => 'number',
            'custom_attributes' => ['min' => '1', 'max' => '5', 'step' => '1'],
        ]);

        woocommerce_wp_text_input([
            'id' => '_ccr_rentopian_id',
            'label' => __('Rentopian product ID', 'christocentric-rentals'),
            'description' => __('Optional external ID for Rentopian inventory sync.', 'christocentric-rentals'),
        ]);

        echo '</div>';
    }

    public static function render_inventory_note(): void
    {
        echo '<p class="form-field"><strong>' . esc_html__('Rental quantity', 'christocentric-rentals') . '</strong>: '
            . esc_html__('Use WooCommerce stock quantity for units available to rent.', 'christocentric-rentals') . '</p>';
    }

    public static function save_fields(int $postId): void
    {
        $daily = isset($_POST['_ccr_price_per_day']) ? wc_format_decimal(wp_unslash($_POST['_ccr_price_per_day'])) : ''; // phpcs:ignore
        $sale = isset($_POST['_ccr_sale_price_per_day']) ? wc_format_decimal(wp_unslash($_POST['_ccr_sale_price_per_day'])) : ''; // phpcs:ignore

        update_post_meta($postId, '_ccr_price_per_day', $daily);
        update_post_meta($postId, '_ccr_sale_price_per_day', $sale);
        update_post_meta($postId, '_ccr_rating', absint($_POST['_ccr_rating'] ?? 0)); // phpcs:ignore
        update_post_meta($postId, '_ccr_rentopian_id', sanitize_text_field(wp_unslash($_POST['_ccr_rentopian_id'] ?? ''))); // phpcs:ignore
        update_post_meta($postId, '_ccr_is_featured', isset($_POST['_ccr_is_featured']) ? 'yes' : 'no'); // phpcs:ignore
        update_post_meta($postId, '_ccr_is_new', isset($_POST['_ccr_is_new']) ? 'yes' : 'no'); // phpcs:ignore

        if ($daily === '') {
            return;
        }

        update_post_meta($postId, '_regular_price', $daily);

        $saleActive = $sale !== '' && (float) $sale > 0 && (float) $sale < (float) $daily;

        if ($saleActive) {
            update_post_meta($postId, '_sale_price', $sale);
            update_post_meta($postId, '_price', $sale);
        } else {
            delete_post_meta($postId, '_sale_price');
            update_post_meta($postId, '_price', $daily);
            update_post_meta($postId, '_ccr_sale_price_per_day', '');
        }

        wc_delete_product_transients($postId);
    }
}
