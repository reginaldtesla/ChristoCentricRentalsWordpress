# Modern Checkout Builder — Workflows

This document summarizes the main workflows for the Modern Checkout Builder. For full context, see [README.md](README.md).

---

## 1. System initialization (load order)

```
Plugin load
    → rentopian-sync.php / init
    → rental_init_checkout_layout_manager() [init, priority 5]
        → Rental_Checkout_Layout_Manager::get_instance()
        → Rental_Checkout_Template_Manager::get_instance() (admin only)
    → rental_init_checkout_validator() [init, wp_loaded, priority 5]
        → Rental_Checkout_Validator::get_instance()
    → [Frontend request] wp
    → rental_init_checkout_layout_renderer() [wp, priority 10]
        → rental_checkout_layout_renderer()->init() (if modern layout enabled)
        → Rental_Checkout_Order_Display::get_instance()
```

---

## 2. Admin: open Checkout Layout page

```
Admin clicks Rentopian Sync → Checkout Layout
    → Layout Manager: add_admin_menu() registered
    → Admin page callback loads templates/checkout/admin-checkout-layout.php
    → Admin enqueue: checkout-layout-admin.js, admin CSS
    → Page JS requests current layout (AJAX: rental_checkout_layout_get)
    → Config returns get_layout() → JSON shown in editor
    → Optional: load template list (Template Manager), refresh dynamic fields
```

---

## 3. Admin: save layout

```
Admin edits JSON and clicks Save
    → JS sends POST to wp-admin/admin-ajax.php
        action: rental_checkout_layout_save
        config: <JSON string>
    → Layout Manager::ajax_save_layout()
    → Layout Config::save_layout($config)
        → validate_config($config)
        → update_option(OPTION_KEY, $config)
        → do_action('rental_checkout_layout_saved', $config)
    → Response: success or WP_Error message
    → UI shows success or error
```

---

## 4. Frontend: load checkout page (modern layout on)

```
User opens /checkout/
    → wp hook: rental_init_checkout_layout_renderer()
    → Config::is_enabled() === true
    → Renderer::init()
        → body_class: rentopian-modern-checkout
        → enqueue_scripts: checkout-layout-frontend.css, .js
        → add_action(woocommerce_checkout_before_customer_details, render_checkout_layout, 5)
        → add_filter(woocommerce_checkout_fields, modify_checkout_fields, 100)
        → manage_default_hooks(): remove default rental hooks (date form, referral, etc.)
    → WooCommerce runs checkout_before_customer_details
    → Renderer::render_checkout_layout()
        → Config::get_layout()
        → For each section → row → column:
            → Field Renderer::render(column['field'])
        → output_hide_default_fields_css()
    → WooCommerce continues (order review, payment, etc.)
```

---

## 5. Frontend: place order (validation)

```
User clicks Place order
    → [Optional] Frontend JS: sync visible billing/shipping values to hidden/duplicate inputs
    → Form submit (or AJAX checkout)
    → WooCommerce: woocommerce_checkout_process
    → Rental_Checkout_Validator::validate_checkout_fields() [priority 5]
        → is_modern_checkout_enabled() ? continue : return
        → validate_miles_based_address()
        → validate_referral_source()
        → validate_event_type()
        → validate_delivery_time()
        → validate_pickup_time()
        → validate_layout_fields()
    → WooCommerce validates billing/shipping/payment
    → If errors: re-render checkout with notices
    → If no errors: create order, redirect to order-received
```

---

## 6. Order-received (thank-you only mode)

```
User lands on order-received
    → Order Display: thank-you only option enabled
        → add_action(woocommerce_thankyou, render_only_thank_you_message, 1)
        → add_action(wp_head, hide_all_order_content_css)
        → remove_action(woocommerce_thankyou, woocommerce_order_details_table, 10)
    → render_only_thank_you_message(): output div.rentopian-thank-you-message.rentopian-thank-you-only-mode
    → hide_all_order_content_css(): output CSS that hides .left-box * except .rentopian-thank-you-message
    → User sees only thank-you message (and optional notice)
```

---

## 7. Adding a new field (developer)

```
1. Register field
   → add_action('rental_checkout_register_fields', function($registry) {
         $registry->register_field('my_field_id', [ 'label' => '...', 'type' => '...', 'required' => true, 'source' => '...' ]);
     });

2. Render (if custom type)
   → In Field Renderer, add case in render() or new method for your source/type.

3. Validate (if required)
   → In Validator, ensure validate_layout_fields() or a dedicated method checks $_POST for your field when required.

4. Use in layout
   → Add column in JSON: { "width": 6, "field": "my_field_id", "active": 1 }
```

---

## 8. Admin: manage templates

```
Admin opens Templates tab
    → Page loads, triggers loadTemplates() AJAX
    → Template Manager: ajax_list_templates()
        → Returns lightweight list (no full JSON for performance)
    → UI renders template list with actions

Preview template:
    → Click Preview (eye icon)
    → AJAX: rental_load_layout_template
    → Large modal shows template JSON, metadata
    → Options: Copy JSON, Apply This Template, Close

Apply template:
    → Click Apply (from list or preview modal)
    → Confirmation dialog with template name
    → AJAX: rental_apply_layout_template
        → Template Manager::apply_template($template_id)
        → Layout Config::save_layout($template['layout'])
    → Layout saved immediately (no additional save needed)
    → JSON editor updated, switched to JSON Editor tab
    → Success message: "Template applied and saved!"

Save new template:
    → Enter name, description
    → Paste JSON or click "Copy Current Layout"
    → Optional: Validate JSON button
    → Click Save Template
    → AJAX: rental_save_layout_template
    → Template added to list (max 20)

Delete template:
    → Click Delete (trash icon)
    → Confirmation dialog with template name
    → AJAX: rental_delete_layout_template
    → Template removed from list with animation
```

---

## 9. Visual Builder: reorder sections and rows

The Visual Builder provides three methods for reordering sections and rows.

### Method A — Drag and drop (handle)

```
User grabs the ☰ drag handle on a section/row header
    → jQuery UI Sortable activates (handle matched within each item)
    → Semi-transparent ghost follows cursor (opacity: 0.85)
    → Blue dashed placeholder shows target position
    → User drops at new position
    → Sortable update callback fires:
        → self.dirty()                    (marks unsaved changes)
        → self.updateAllWidthBars()       (rows only)
        → self.updateOrderButtons()       (refresh disabled states)
    → Item flashes blue glow (rvb-flash, 0.7s animation)
```

### Method B — Move up / move down buttons (WP dashboard pattern)

```
User clicks ▲ (move higher) or ▼ (move lower) on a section/row header
    → Event handler calls moveItemUp() or moveItemDown()
        → Finds prev/next sibling of same type (.rvb-section or .rvb-row)
        → If no sibling → no-op (button is already disabled)
        → DOM move: $prev.before($item) or $next.after($item)
        → flashItem($item)               (blue glow animation)
        → dirty()                         (mark unsaved)
        → updateAllWidthBars()
        → updateOrderButtons()
            → First item's ▲ disabled
            → Last item's ▼ disabled
            → All others enabled
```

### Method C — Row collapse for easier navigation

```
User clicks chevron (▲/▼) on a row header
    → .rvb-row toggleClass('rvb-row--collapsed')
    → .rvb-row-body (containing .rvb-cols-container) hides/shows
    → Icon toggles between arrow-up-alt2 and arrow-down-alt2
    → Collapsed rows can still be reordered via drag or buttons
```

### Sortable handle selector rules

```
jQuery UI Sortable handle option:
    ✓ Matched WITHIN each sortable item (not from container)
    ✗ Must NOT include the item's own class in the selector path

Sections:  items = '> .rvb-section'     → handle: '.rvb-section-header .rvb-drag-handle'
Rows:      items = '> .rvb-row'         → handle: '.rvb-row-header .rvb-drag-handle'
Columns:   items = '> .rvb-column'      → handle: '.rvb-col-head > .rvb-drag-handle'
```

---

## 10. Frontend: pickup address persistence

```
User checks "Different pickup address"
    → bindPickupAddressPersistence() saves to sessionStorage:
        - rental_different_pickup_address (checkbox state)
        - Pickup address field values
    → On page load or update_checkout:
        → restorePickupAddressData() restores values
        → Prevents data loss during AJAX updates
```

---

*For full documentation, see [README.md](README.md).*
