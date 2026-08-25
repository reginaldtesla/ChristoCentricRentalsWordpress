# Rental Options Workflows

## Overview

This document describes the data flow and workflows for the rental options system,
including synchronization from Rentopian API, user interactions, and price calculations.

## Synchronization Workflow

### Initial Sync from Rentopian API

```
┌─────────────────┐     ┌──────────────────┐           ┌──────────────────┐
│  Rentopian API  │────>│ rental_synchronization()     |                  |
|                 |     | OR Webhook API Endpoint ────>│  RTOptionsBase   |
│  products/      │     │  rentopian_save  │           │  ->save()        │
│  options        │     │  _product_options│           │                  │
└─────────────────┘     └─────────┬─────────┘          └─────────┬────────┘
                                  │                              │
                    ┌─────────────┼──────────────────────────────┼─────┐
                    │             ▼                              ▼     │
                    │  ┌───────────────────┐     ┌────────────────┐    │
                    │  │ rental_product_   │◄────│ Build Relations│    │
                    │  │ option_relations  │     │ (prepared SQL) │    │
                    │  └───────────────────┘     └────────────────┘    │
                    │                                     │            │
                    │  ┌───────────────────┐     ┌────────▼────────┐   │
                    │  │ wp_postmeta       │◄────│ Update Postmeta │   │
                    │  │ _product_options  │     │ (JSON array)    │   │
                    │  └───────────────────┘     └─────────────────┘   │
                    │                                                  │
                    └──────────────────────────────────────────────────┘
```

### Webhook API Steps:

1. **API Request**: Rentopian sends option data to WordPress endpoint
2. **Validation**: Endpoint validates and sanitizes input data
3. **Instantiation**: Creates RTProductOptions or RTSetOptions instance
4. **Save**: Calls save() which determines if INSERT or UPDATE
5. **Relations**: Builds product/category relations with prepared statements
6. **Postmeta**: Updates `_product_options` or `_set_options` meta

## Product Page Workflow

### Loading Options on Product Page

1. Page load: empty `.rental-product-options` container (from `woocommerce_before_add_to_cart_button`).
2. JS: `get_options_of_single_product()` runs after 500ms → AJAX `rental_get_all_options_of_product` (session + cart fallback if session empty).
3. Response: build `<select>` elements; then **only** in completion handler call `checkAddToCartPossibility()` to enable/disable Add to Cart.
4. No standalone `checkAddToCartPossibility()` timeout (removed to fix race where button stayed disabled).

### User Selects an Option (Product Page)

1. `update_option_data_of_single_product()` queues change; debounce 250ms.
2. AJAX `rental_update_product_options_batch` → session and cart item data updated.
3. On complete: `checkAddToCartPossibility()` re-runs.
4. After 500ms: **confirmation sync** — read current values from DOM `<select>`s, send via `rental_sync_cart_item_options` so cart item data stays in sync.

## Cart Workflow

### Cart Page (Rentpro / Eventorian)

1. Template outputs cart table; each row has empty `<td class="product-options">` for items with options.
2. **Before** cart table body: `woocommerce_before_cart_contents` sets `Rental_Product_Options_Manager::$is_rendering_cart_table = true`.
3. For each row, `wc_get_formatted_cart_item_data($cart_item)` runs → `woocommerce_get_item_data` → `display_options_in_cart()`. Because `$is_rendering_cart_table` is true, it **returns unchanged** (no options under product name).
4. **After** cart table body: `woocommerce_after_cart_contents` sets `$is_rendering_cart_table = false`.
5. JS (`rental-product-options-script.js`): finds `td.product-options`, calls `rental_get_all_options_of_cart_products` (or per-item HTML), injects editable `<select>` markup into the Options column.

Result: options appear **only** in the Options column; no duplicate under the product name.

### Cart Page (Other themes)

- No dedicated Options column. `display_options_in_cart()` always adds options to item_data (flag not set).
- `wc_get_formatted_cart_item_data()` outputs read-only `<dl class="variation">` under the product name.
- `rental_display_options_after_cart_item_name()` outputs nothing (no editable selects on cart for non-Rentpro).

### Mini-cart (all themes)

- Mini-cart template calls `wc_get_formatted_cart_item_data($cart_item)`.
- When the mini-cart is rendered, `$is_rendering_cart_table` is **false** (that flag is only true during the main cart table loop).
- So `display_options_in_cart()` adds options to item_data → read-only `<dl class="variation">` under the product name in the mini-cart.
- Same for fragment refresh: `woocommerce_mini_cart()` runs without the cart-table flag, so options show.

### Cart Total Calculation

- `woocommerce_before_calculate_totals` (e.g. `calculate_cart_totals()` in functions.php): reads options from cart item data (`rental_selected_options` / `rental_selected_set_options`), adds option prices to the line total. Uses cart item as source of truth.
- Session `rental_product_options_valuables` is still updated for compatibility; order submission and display prefer cart item data.

### Cart Contents Count (badge)

- Filter `woocommerce_cart_contents_count`: `rental_fix_cart_contents_count()` (in rentopian-sync.php) recalculates the count so that **hidden set children** (add-ons) are excluded. Prevents the mini-cart badge from showing an inflated number (e.g. 116 instead of 2) when a set has many hidden items.

## WooCommerce Integration Workflow (current)

### Item Data Display Flow (Rental_Product_Options_Manager::display_options_in_cart)

- **When:** Any time WooCommerce calls `wc_get_formatted_cart_item_data($cart_item)` (cart table, mini-cart, checkout order review).
- **Rentpro/Eventorian:** If `$is_rendering_cart_table` is true (set by `woocommerce_before_cart_contents`), return `$item_data` unchanged. Otherwise add options from `$cart_item['rental_selected_options']` or `rental_selected_set_options` into `$item_data` (key/value for `<dl class="variation">`).
- **Other themes:** Always add options to `$item_data`.
- **Source of truth:** Cart item data (`rental_selected_options` / `rental_selected_set_options`), enriched with option/value titles via `enrich_option_data_for_display()`.

### Order Item Meta Save Flow

```
┌─────────────────┐     ┌──────────────────────┐     ┌─────────────────┐
│  Checkout       │────>│  woocommerce_        │────>│  save_options_  │
│  Create Order   │     │  checkout_create_    │     │  to_order_item()│
│                 │     │  order_line_item     │     │                 │
└─────────────────┘     └──────────────────────┘     └────────┬────────┘
                                                              │
                        ┌─────────────────────────────────────┘
                        ▼
┌─────────────────┐     ┌──────────────────────┐
│  Order Item     │◄────│  add_meta_data()     │
│  Meta Saved     │     │  - JSON blob         │
│                 │     │  - Individual fields │
└─────────────────┘     └──────────────────────┘
```

### Server-Side Rendering (product page)

Product page options are normally loaded via AJAX into `.rental-product-options`. For custom templates, the shortcode `[rental_product_options product_id="123"]` or a call to `rental_render_product_options($product_id)` can be used where supported.

## Order Workflow

### Order Creation and Options

- **Checkout create line item:** `Rental_Product_Options_Manager::save_options_to_order_item()` reads options from the **cart item** (`rental_selected_options` / `rental_selected_set_options`), not from session. This avoids sending stale options to the core/API.
- **Order meta:** Options are stored on the order line item (e.g. `_rental_product_options` and individual meta). Display in emails and order details uses `woocommerce_order_item_get_formatted_meta_data` → `format_order_item_options()`.
- **Session:** Session option data is **not** cleared after add-to-cart, so the product page keeps the last selection when the user returns.

## Session Data Structure

### Product Option Selections

```php
// Session key: {product_id}_selected_options
[
    'option_id_1' => [
        'selected_value_id' => 123,
        'price'             => 10.00
    ],
    'option_id_2' => [
        'selected_value_id' => 456,
        'price'             => 25.00
    ]
]
```

### Order-Level Options

```php
// Session key: rental_order_selected_options
[
    'option_id_1' => [
        'selected_value_id' => 789,
        'price'             => 50.00
    ]
]
```

### Aggregated Option Values

```php
// Session key: rental_product_options_valuables
[
    'product_id_1' => [
        'option_id_1' => [
            'id'    => 123,
            'price' => 10.00
        ]
    ],
    'product_id_2' => [
        'option_id_2' => [
            'id'    => 456,
            'price' => 25.00
        ]
    ]
]
```

## Error Handling

### Validation Errors

1. **Missing Required Options**: Checked before add-to-cart and checkout
2. **Invalid Option Value**: Validated against available values
3. **Price Mismatch**: Server recalculates prices, doesn't trust client

### Recovery Strategies

1. **Session Expiry**: Options cleared, user must re-select
2. **Option Deleted**: Gracefully handled, removed from selections
3. **Price Changed**: New price used, user notified via cart update

## Security Measures

### SQL Injection Prevention

All database queries use `$wpdb->prepare()` with parameterized values:

```php
// CORRECT (used in new implementation)
$this->wpdb->prepare(
    "INSERT INTO {$table} (po_id, rental_id, wp_id, type) VALUES (%d, %d, %d, %d)",
    $option_id, $rental_id, $wp_id, $type
);

// INCORRECT (old implementation - fixed)
// "('$option_id', '$rental_id', '$wp_id', '1')"
```

### Input Sanitization

All user input is sanitized before processing:

```php
$option_id = intval($_POST['option_id']);
$price     = floatval($_POST['price']);
$title     = sanitize_text_field($_POST['title']);
```

### Nonce Verification

AJAX requests should verify nonces (to be implemented):

```php
if (!wp_verify_nonce($_POST['nonce'], 'rental_options_nonce')) {
    wp_send_json_error('Invalid security token');
}
```

## Performance Considerations

### Caching

- Product options cached in post meta for quick retrieval
- Session data prevents repeated database queries during cart operations
- Option relations indexed for fast lookups

### Batch Operations

- Bulk sync uses batch inserts instead of individual queries
- Postmeta updates grouped where possible

### Lazy Loading

- Options loaded via AJAX only when needed
- Cart options loaded after page render to improve perceived performance
