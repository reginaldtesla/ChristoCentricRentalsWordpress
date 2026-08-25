# Rental Options System Documentation

## Overview

The Rental Options System provides a unified architecture for managing product options and set options in the Rentopian Sync plugin. This system allows rental businesses to attach configurable options (with additional pricing) to products and product sets.

## Architecture

### Class Hierarchy

```
RTOptionsBase (Abstract Base Class)
├── RTProductOptions (Product-specific options)
└── RTSetOptions (Set-specific options)
```

### Database Tables

| Table | Purpose |
|-------|---------|
| `{prefix}_rental_product_options` | Stores product option definitions |
| `{prefix}_rental_product_option_relations` | Links product options to products/categories |
| `{prefix}_rental_set_options` | Stores set option definitions |
| `{prefix}_rental_set_option_relations` | Links set options to product sets |

### WordPress Post Meta

| Meta Key | Purpose |
|----------|---------|
| `_product_options` | JSON array of option IDs attached to a product |
| `_set_options` | JSON array of option IDs attached to a set |
| `_rental_is_set` | Boolean indicating if product is a set |

## Data Structures

### Option Definition

```php
[
    'id'             => (int),     // Unique option ID from Rentopian
    'title'          => (string),  // Display name
    'once_per_order' => (int),     // 0 or 1 - applies once per order
    'option_values'  => (array)    // Array of selectable values
]
```

### Option Value

```php
[
    'id'         => (int),     // Unique value ID
    'title'      => (string),  // Display name
    'price'      => (float),   // Additional cost
    'is_default' => (int),     // 0 or 1 - default selection
    'option_id'  => (int)      // Parent option ID
]
```

### Relation Types (Product Options)

| Type | Value | Description |
|------|-------|-------------|
| Product | 1 | Option attached directly to a product |
| Category | 2 | Option attached to a product category |

## Usage

### Retrieving Options

```php
// Get product options
$options = get_product_options($product_id);

// Get set options
$options = get_set_options($set_id);

// Get once-per-order options
$order_options = get_once_per_order_options_by_option_ids($option_ids);
```

### Saving Options Webhook (API)

Options are typically saved via the Rentopian API sync (overal sync that happens in this function : rental_synchronization($api_key)) or API (Webhook) calls via product/set updates in the core (Rentopian) system:

```php
// Product Options
$product_option = new RTProductOptions(
    $option['id'],
    $option['title'],
    $option['once_per_order'],
    $option['details']
);
$result = $product_option->save();

// Set Options
$set_option = new RTSetOptions(
    $option['id'],
    $option['title'],
    $option['once_per_order'],
    $option['details']
);
$result = $set_option->save();
```

### Session Storage

User selections are stored in session data:

| Session Key | Purpose |
|-------------|---------|
| `{product_id}_selected_options` | Product option selections |
| `{product_id}_selected_options_of_set` | Set option selections |
| `rental_order_selected_options` | Order-level product options |
| `rental_order_selected_options_of_sets` | Order-level set options |
| `rental_product_options_valuables` | All selected values with prices |

## AJAX Endpoints

| Action | Purpose |
|--------|---------|
| `rental_get_all_options_of_product` | Get options for a product/set; saves defaults to session; used on product page load |
| `rental_get_all_options_of_cart_products` | Get options for all cart items (e.g. cart page Options column) |
| `rental_check_selected_options_of_product` | Validate that required options are selected; used to enable/disable Add to Cart |
| `rental_update_product_option` | Update a single option (e.g. cart page select change) |
| `rental_update_product_options_batch` | Batch update options from product page (debounced) |
| `rental_sync_cart_item_options` | Sync current DOM select values to cart item data (confirmation sync after batch update) |
| `rental_get_cart_item_options_html` | Get HTML for editable option selects (Options column) |
| `rental_update_cart_item_options` | Update cart item options from cart page (Rental_Product_Options_Manager) |
| `rental_get_order_options` | Get order-level options |
| `rental_update_order_option` | Update order-level selection |
| `rental_check_selected_options` | Validate selections (e.g. checkout) |

## WooCommerce Integration

### Primary Display: Rental_Product_Options_Manager

Options display in cart, mini-cart, and checkout is driven by **Rental_Product_Options_Manager** via the standard WooCommerce `woocommerce_get_item_data` filter. This produces read-only `<dl class="variation">` markup compatible with all themes.

**File**: `includes/class-rental-product-options-manager.php`

#### Display Rules by Theme

| Context | Rentpro / Eventorian | Other themes |
|--------|----------------------|--------------|
| **Cart page** | Options appear **only** in the dedicated **Options column**. The column is filled by JavaScript (AJAX) in `rental-product-options-script.js`. No options under the product name. | Read-only options under the product name via `<dl class="variation">` from `woocommerce_get_item_data`. No editable selects. |
| **Mini-cart** | Read-only options under the product name via `<dl class="variation">` (same item_data filter). | Same. |
| **Checkout** | Read-only options in order review via `<dl class="variation">`. | Same. |

Detection uses `rental_uses_rentpro_or_eventorian_options_column()` (theme name/template: rentpro, eventorian). On Rentpro/Eventorian, options are **skipped** only when rendering the **cart table** (not the whole page), so the mini-cart widget still shows options even when viewed on the cart page. This is done via a flag set by `woocommerce_before_cart_contents` / `woocommerce_after_cart_contents`.

#### Cart Item Data Keys

| Key | Used for |
|-----|----------|
| `rental_selected_options` | Regular product options (stored in cart item and session) |
| `rental_selected_set_options` | Set product options |
| `rental_is_set` | Whether the cart item is a set |

Order submission and totals read options from **cart item data** (`WC()->cart->cart_contents`), not session, to avoid stale values.

#### Main Hooks (Rental_Product_Options_Manager)

| Hook | Method | Purpose |
|------|--------|---------|
| `woocommerce_get_item_data` | `display_options_in_cart()` | Add options to item_data (skipped for Rentpro only when rendering cart table) |
| `woocommerce_add_cart_item_data` | `add_options_to_cart_item_data()` | Store selected options when adding to cart |
| `woocommerce_get_cart_item_from_session` | `restore_options_from_session()` | Restore options from session |
| `woocommerce_before_calculate_totals` | (calculate_cart_totals in functions.php) | Add option prices to line totals |
| `woocommerce_checkout_create_order_line_item` | `save_options_to_order_item()` | Save options to order item meta |
| `woocommerce_order_item_get_formatted_meta_data` | `format_order_item_options()` | Format options for order/emails |
| `woocommerce_add_to_cart_fragments` | `update_mini_cart_fragment()` | Refresh mini-cart HTML |
| `woocommerce_cart_contents_count` | `rental_fix_cart_contents_count()` (rentopian-sync.php) | Exclude hidden set children from cart badge count |

#### rental-product-options-integration.php

| Hook | Function | Purpose |
|------|----------|---------|
| `woocommerce_after_cart_item_name` | `rental_display_options_after_cart_item_name()` | No output (Options column is filled by JS on Rentpro; other themes use item_data only) |
| `woocommerce_cart_item_name` | `rental_ensure_mini_cart_shows_options()` | No modification; options come from item_data |

### Secondary Integration (Rental_Options_WC_Integration)

**File**: `includes/class-rental-options-wc-integration.php`

Cart item data display via this class is **disabled** to avoid duplicates. The class still handles order meta save and email display. Theme compatibility and control (e.g. `rentopian_disable_cart_options_display`, `rentopian-custom-options-display`) remain available for customizations.

### Product Page: Add-to-Cart Button and Options

- Options are loaded 500ms after DOM ready via **AJAX** `rental_get_all_options_of_product`; response is used to build `<select>` elements and save defaults to session.
- **Add-to-cart button** is enabled/disabled by **`checkAddToCartPossibility()`**, which runs **only** from the **completion handler** of `get_options_of_single_product()`, to avoid a race where the check runs before session defaults are saved.
- When the user changes an option, `update_option_data_of_single_product()` batches changes (debounce), then calls `rental_update_product_options_batch`; on completion it calls `checkAddToCartPossibility()` again. A 500ms **confirmation sync** sends current DOM `<select>` values via `rental_sync_cart_item_options` to keep cart item data in sync.
- Session is **not** cleared after add-to-cart, so the product page keeps the last selected options. If session is empty, `rental_get_all_options_of_product` can repopulate from cart item data.

### Helper Functions

```php
// Check if theme uses dedicated Options column (Rentpro/Eventorian)
rental_uses_rentpro_or_eventorian_options_column(); // bool

// Render editable options HTML (e.g. for cart column content via AJAX)
$manager = rental_get_options_manager();
$html = $manager->render_options_selectors($product_id, $is_set, $cart_item_key);

// Shortcode for product page (if needed)
[rental_product_options product_id="123"]
```

### Controlling Integration

```php
// Disable cart options display (theme handles it)
add_filter('rentopian_disable_cart_options_display', '__return_true');
add_theme_support('rentopian-custom-options-display');
```

### Price Calculation

Options prices are added to product prices during cart calculation:

1. `calculate_cart_totals()` retrieves selected options
2. Option prices are added to base product price
3. Totals are stored in session for display

### Custom Validation

Use the `rentopian_validate_option_selection` filter:

```php
add_filter('rentopian_validate_option_selection', function($valid, $option_id, $value_id) {
    // Custom validation logic
    return $valid;
}, 10, 3);
```

## Related Files

- `includes/models/RTOptionsBase.php` - Base class
- `includes/models/RTProductOptions.php` - Product options
- `includes/models/RTSetOptions.php` - Set options
- `includes/class-rental-product-options-manager.php` - Cart/mini-cart/checkout display, merge, session restore
- `includes/rental-product-options-integration.php` - Theme detection, cart hooks (no duplicate output)
- `includes/class-rental-options-wc-integration.php` - Legacy integration (order meta, theme control)
- `includes/product-options/assets/js/product-options.js` - Product page options, cart Options column fill, add-to-cart check
- `functions.php` - Helper functions, AJAX handlers, calculate_cart_totals, order submission options
- `includes/product-options/` - Selection resolution and the add-to-cart gate. See [SELECTION-AND-VALIDATION.md](SELECTION-AND-VALIDATION.md)

## Selection and validation

Which option value counts as selected, and whether an add-to-cart may proceed,
are decided by the `includes/product-options/` module — not by the classes
listed above, which handle display. Read
[SELECTION-AND-VALIDATION.md](SELECTION-AND-VALIDATION.md) before changing the
add-to-cart gate, the option renderer, or any caller that reads a selection.
