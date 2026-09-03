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
        add_filter('woocommerce_product_get_price', [self::class, 'filter_display_price'], 10, 2);
        add_filter('woocommerce_product_get_regular_price', [self::class, 'filter_display_regular_price'], 10, 2);
        add_filter('woocommerce_is_purchasable', [self::class, 'filter_purchasable'], 10, 2);
    }

    /**
     * WooCommerce treats an empty price as not for sale. Use the daily rental rate when set.
     */
    public static function filter_display_price($price, $product)
    {
        if (! $product instanceof WC_Product) {
            return $price;
        }
        if ($price !== '' && $price !== null && (float) $price > 0) {
            return $price;
        }
        $daily = (string) $product->get_meta('_ccr_price_per_day');
        $sale = (string) $product->get_meta('_ccr_sale_price_per_day');
        if ($sale !== '' && (float) $sale > 0) {
            return $sale;
        }

        return $daily !== '' && (float) $daily > 0 ? $daily : $price;
    }

    public static function filter_display_regular_price($price, $product)
    {
        if (! $product instanceof WC_Product) {
            return $price;
        }
        if ($price !== '' && $price !== null && (float) $price > 0) {
            return $price;
        }
        $daily = (string) $product->get_meta('_ccr_price_per_day');

        return $daily !== '' && (float) $daily > 0 ? $daily : $price;
    }

    public static function filter_purchasable($purchasable, $product): bool
    {
        if ($purchasable || ! $product instanceof WC_Product) {
            return (bool) $purchasable;
        }
        if ($product->get_status() !== 'publish') {
            return false;
        }
        $daily = (float) $product->get_meta('_ccr_price_per_day');
        $wc = (float) $product->get_regular_price('edit');

        return $daily > 0 || $wc > 0;
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
            'description' => __('ID from Rentopian after catalog sync. Used to match products both ways.', 'christocentric-rentals'),
        ]);

        // Official Rentopian Sync meta — written by Synchronize, required for availability.
        $productId = isset($GLOBALS['post']->ID) ? (int) $GLOBALS['post']->ID : 0;
        $inventoryId = $productId ? (string) get_post_meta($productId, '_rental_inventory_id', true) : '';
        $inventoryLabel = $inventoryId !== '' && (int) $inventoryId > 0
            ? $inventoryId
            : __('Missing — run Rentopian Sync', 'christocentric-rentals');

        echo '<p class="form-field _rental_inventory_id_field">';
        echo '<label>' . esc_html__('Rentopian inventory ID', 'christocentric-rentals') . '</label>';
        echo '<span class="description" style="display:inline-block;padding-top:6px;">';
        echo esc_html($inventoryLabel);
        echo '</span><br/>';
        echo '<span class="description">' . esc_html__('Written by Rentopian Sync (_rental_inventory_id). Without this, the storefront always says “not available.”', 'christocentric-rentals') . '</span>';
        echo '</p>';

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
