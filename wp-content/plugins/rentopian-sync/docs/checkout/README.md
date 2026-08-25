# Modern Checkout Builder — Documentation

**Component:** Rentopian Sync — Checkout Layout System  
**Audience:** Developers, technical maintainers  
**Last updated:** 2026-06-22  
**Version:** 2.16.0  
**Conventions:** This document follows a single-source structure so it can be split into separate files later (e.g. `architecture.md`, `workflows.md`) without losing context.

---

## Table of contents

1. [Introduction](#1-introduction)
2. [Prerequisites and activation](#2-prerequisites-and-activation)
3. [Architecture](#3-architecture)
4. [Configuration](#4-configuration)
5. [Workflows](#5-workflows)
6. [Template Manager](#6-template-manager)
7. [Visual Builder](#7-visual-builder)
8. [File and option reference](#8-file-and-option-reference)
9. [Frontend features](#9-frontend-features)
10. [Extending the system](#10-extending-the-system)
11. [Examples](#11-examples)
12. [Documentation maintenance](#12-documentation-maintenance)

---

## 1. Introduction

### 1.1 Purpose

The **Modern Checkout Builder** is a layout engine for the WooCommerce checkout page. It lets you:

- **Arrange fields** in a grid (sections → rows → columns) instead of the default order.
- **Control visibility** per field (active, hidden for display only).
- **Support dependencies** (e.g. show shipping fields only when “Ship to different address” is checked).
- **Keep compatibility** with WooCommerce validation, payment gateways, and order processing.

It does **not** replace WooCommerce checkout logic; it only changes how and where fields are rendered and how they are validated when the layout is active.

### 1.2 When the builder is used

The custom layout is used only when **all** of the following are true:

- **Dates on checkout** is enabled (`rental_dates_on_checkout`).
- **Allow overbook** (or equivalent) is enabled (`rental_allow_overbook`).
- **Checkout layout mode** is set to **Modern** (`rental_checkout_layout_mode === 'modern'`).

If any of these conditions fail, the standard WooCommerce/rental checkout is shown.

### 1.3 Glossary

| Term | Meaning |
|------|--------|
| **Layout** | JSON configuration that defines sections, rows, and which field appears in which column. |
| **Section** | Group of rows (e.g. “Billing”, “Rental options”). |
| **Row** | Horizontal band; contains one or more columns. |
| **Column** | Cell in a row; holds one **field** and a **width** (e.g. 1–12). |
| **Field** | A single input (e.g. `billing_first_name`, `rental_date_form_modern`). |
| **Registry** | Central list of all available fields and their metadata (label, type, required). |
| **Rendered** | Output of HTML for the checkout form by the layout renderer. |

---

## 2. Prerequisites and activation

### 2.1 Requirements

- WordPress 5.0+
- WooCommerce active
- Rentopian Sync plugin active
- PHP 7.4+ (or as required by the plugin)

### 2.2 Enabling the Modern Checkout Builder

1. In **Rentopian Sync** settings, enable **Dates on Checkout** and **Allow overbook** (or equivalent options that gate “modern” checkout).
2. Set **Checkout layout mode** to **Modern**.

Until the layout mentioned settings are enabled and saved, the default WooCommerce/rental checkout is shown.

### 2.3 Where the documentation lives

Recommended place in the plugin tree:

```text
rentopian-sync/
├── docs/
│   └── checkout/
│       ├── README.md          ← This file (main entry)
│       ├── architecture.md   ← (optional) Split from §3
│       ├── workflows.md      ← (optional) Split from §5
│       └── extending.md      ← (optional) Split from §7
├── includes/
│   └── checkout/             ← All PHP classes for the builder
├── assets/
│   ├── js/
│   │   ├── checkout-layout-frontend.js
│   │   └── checkout-layout-admin.js
│   └── css/
│       └── checkout-layout-frontend.css
└── templates/
    └── checkout/             ← Admin UI and sample JSON
```

New features (e.g. new field types, new workflows) should be described here or in the optional split files, with a short note in this README.

---

## 3. Architecture

### 3.1 High-level flow

```text
┌─────────────────────────────────────────────────────────────────────────┐
│                           ADMIN (Configuration)                           │
├─────────────────────────────────────────────────────────────────────────┤
│  Layout Manager  →  Config (load/save JSON)  →  Field Registry (metadata) │
│  Template Manager (save/load/apply layout templates)                      │
│  Admin UI: templates/checkout/admin-checkout-layout.php                   │
└─────────────────────────────────────────────────────────────────────────┘
                    │
                    │  Stored in wp_options
                    ▼
┌─────────────────────────────────────────────────────────────────────────┐
│                         FRONTEND (Rendering)                             │
├─────────────────────────────────────────────────────────────────────────┤
│  Loader (checkout-layout-loader.php)                                     │
│       → Layout Renderer  →  Config + Registry + Field Renderer           │
│       → Output: sections / rows / columns; each column = one field       │
│  Validator (woocommerce_checkout_process)                                │
│       → Validates required rental fields AND visible layout billing/      │
│         shipping fields (depends_on visibility mirrored from frontend)   │
│  Order Display → order-received and my-account order view                 │
└─────────────────────────────────────────────────────────────────────────┘
```

### 3.2 Component roles

| Component | File | Responsibility |
|-----------|------|----------------|
| **Loader** | `checkout-layout-loader.php` | Requires all checkout classes; inits Manager, Renderer, Validator, Order Display on the right hooks. |
| **Layout Config** | `class-checkout-layout-config.php` | Reads/writes layout JSON to `wp_options`; validates structure; provides default layout. |
| **Field Registry** | `class-checkout-field-registry.php` | Registers WooCommerce billing/shipping, rental static/dynamic fields; exposes metadata (label, type, required). |
| **Layout Manager** | `class-checkout-layout-manager.php` | Admin: menu, AJAX (save/get/reset/import/export/toggle/thank-you), enqueue admin assets. |
| **Layout Renderer** | `class-checkout-layout-renderer.php` | Frontend: outputs layout (sections/rows/columns); tells WC which fields are “handled” to avoid duplicate output. |
| **Field Renderer** | `class-checkout-field-renderer.php` | Renders a single field by ID (WC fields via `woocommerce_form_field`, rental/component/dynamic via plugin helpers). |
| **Template Manager** | `class-checkout-template-manager.php` | Saves/loads/applies named layout templates (stored in options). |
| **Validator** | `class-checkout-validator.php` | Hooks `woocommerce_checkout_process`; validates rental-specific and layout-required fields (incl. visible billing/shipping) using the same `depends_on` visibility as the frontend; miles-based address; section-qualified, locale/override-aware error labels with field-id anchors.|
| **Order Display** | `class-checkout-order-display.php` | Order-received and my-account: thank-you message, optional “thank you only” mode, rental/order data display. |

### 3.3 Data flow (frontend)

1. **Request checkout page**  
   WooCommerce loads checkout; `wp` hook runs.

2. **Renderer init**  
   `rental_init_checkout_layout_renderer()` runs; if modern layout is enabled, `Rental_Checkout_Layout_Renderer::init()` registers:
   - `woocommerce_checkout_before_customer_details` → `render_checkout_layout()`
   - `woocommerce_checkout_fields` (filter) → `modify_checkout_fields()` so handled fields are not rendered again by WC.

3. **Rendering**  
   - Config returns layout JSON (from option `rental_checkout_layout_config`).
   - For each section → row → column, the Field Renderer outputs the field HTML (WC or rental).
   - Default WC billing/shipping blocks are hidden via CSS for fields we handle.

4. **Validation**  
   On “Place order”, WooCommerce runs `woocommerce_checkout_process`. The Validator runs at priority 5 and validates rental fields, miles-based address, and the **visible** billing/shipping layout fields (applying the same `depends_on` visibility as the frontend, so a field hidden by an unmet dependency is never validated). WooCommerce continues its own billing/shipping checks; duplicate notices are de-duplicated.

5. **Order creation**  
   Unchanged: WooCommerce and the rest of Rentopian Sync create the order and redirect to order-received.

### 3.4 Dependencies between components

- **Config** does not depend on Registry or Manager.
- **Registry** is used by Config (for validation), Manager (for admin field list), and Field Renderer (for rendering).
- **Renderer** depends on Config and Registry (and instantiates Field Renderer).
- **Validator** depends on Config/Registry only to know which fields exist and whether they are required; it does not render.

---

## 4. Configuration

### 4.1 Where configuration is stored

- **Layout JSON:** `wp_options` key `rental_checkout_layout_config`.
- **Thank-you message:** `rental_checkout_thank_you_message`.
- **Thank-you only mode:** `rental_checkout_thank_you_only`.

### 4.2 Layout JSON structure

Layout is a single JSON object with at least:

- **version** (string): e.g. `"1.0.0"`.
- **grid_system** (string): e.g. `"rentopian"` (used for CSS class names).
- **container_class** (string): e.g. `"rentopian-checkout-form"`.
- **sections** (array): list of section objects.

Each **section** has:

- **id** (string): unique section id.
- **title** (string, optional): section heading.
- **description** (string, optional): text under the heading.
- **class** (string, optional): CSS class for the section wrapper.
- **show_title** / **show_description** (boolean, optional): whether to show title/description.
- **rows** (array): list of row objects.

Each **row** has:

- **id** (string, optional): row id.
- **class** (string, optional): CSS class for the row.
- **columns** (array): list of column objects.

Each **column** has:

- **width** (integer): 1–12 (grid columns per row; total per row should be ≤ 12).
- **field** (string): field ID from the Field Registry (e.g. `billing_first_name`, `rental_date_form_modern`).
- **class** (string, optional): CSS class for the column wrapper.
- **active** (boolean, optional): if `false`, field is omitted from layout and validation can treat it as optional/hidden. Default `true`.
- **required** (boolean, optional): whether the field is required at checkout. Mandatory fields are always forced required.
- **hidden_visual** (boolean, optional): if `true`, field is rendered but hidden (e.g. for hidden required fields).
- **depends_on** (string, optional): field ID or condition; used by frontend JS to show/hide (e.g. ship-to-different-address).
- **label_override** (string, optional): custom field label. Highest-priority label layer — overrides the textual-settings label and the registry default. See §9.6.
- **checked** (boolean, optional): for checkbox fields only — render the box checked by default when the field has no submitted value. Ignored for non-checkbox fields.

### 4.3 Example layout snippet

```json
{
  "version": "1.0.0",
  "grid_system": "rentopian",
  "container_class": "rentopian-checkout-form",
  "sections": [
    {
      "id": "billing_section",
      "title": "Billing Information",
      "description": "Please enter your billing details.",
      "class": "rentopian-billing-section",
      "rows": [
        {
          "id": "row_customer_name",
          "columns": [
            { "width": 6, "field": "billing_first_name", "active": 1 },
            { "width": 6, "field": "billing_last_name", "active": 1 }
          ]
        },
        {
          "id": "row_contact",
          "columns": [
            { "width": 6, "field": "billing_phone", "active": 1 },
            { "width": 6, "field": "billing_email", "active": 1 }
          ]
        }
      ]
    }
  ]
}
```

### 4.4 Validation rules (backend)

- **Config** validates before save:
  - `sections` is an array; each section has `rows`; each row has `columns`.
  - Per row, sum of column `width` ≤ 12.
  - Each `column.field` must exist in the Field Registry (or be a special value like `empty_spacer`).
  - Required dynamic custom fields (from Rentopian) must appear in the layout and be active.

Invalid config returns `WP_Error` and is not saved.

---

## 5. Workflows

### 5.1 Admin: editing and saving a layout

1. Admin opens **Rentopian Sync → Checkout Layout**.
2. Page loads current layout (AJAX: `rental_checkout_layout_get`) or a template.
3. Admin edits JSON (or uses UI if provided): sections, rows, columns, field IDs, widths, `active` / `hidden_visual` / `depends_on`.
4. Admin clicks **Save**.
5. Frontend sends JSON via AJAX to `rental_checkout_layout_save`.
6. **Layout Config** validates; if valid, config is stored in `rental_checkout_layout_config` and cache is cleared.
7. Admin sees success or error message.

Optional: **Import** (paste JSON), **Export** (download JSON), **Reset** (load default), **Templates** (save/load/apply named layouts).

### 5.2 Frontend: loading the checkout page

1. User opens the checkout URL.
2. `wp` fires; `rental_init_checkout_layout_renderer()` runs.
3. If modern layout is **not** enabled, normal checkout is shown; no custom layout.
4. If enabled:
   - Layout Renderer enqueues CSS/JS (`checkout-layout-frontend.css`, `checkout-layout-frontend.js`).
   - On `woocommerce_checkout_before_customer_details`, Renderer calls `render_checkout_layout()`.
   - Config returns layout; Renderer iterates sections → rows → columns; Field Renderer outputs each field.
   - Filter `woocommerce_checkout_fields` marks handled fields so WC does not render them again.
   - Default WC billing/shipping wrappers are hidden with CSS for our fields.
5. User sees the custom grid; WooCommerce and rental scripts (e.g. date picker, delivery/pickup) still run as usual.

### 5.3 Frontend: placing an order (validation)

1. User fills the form and clicks **Place order**.
2. Frontend JS (e.g. `checkout-layout-frontend.js`) may sync visible field values into hidden/duplicate inputs so that serialized form data is up to date.
3. Request is sent to WooCommerce (checkout form POST or AJAX).
4. WooCommerce runs `woocommerce_checkout_process`.
5. **Rental_Checkout_Validator** (priority 5):
   - Validates miles-based shipping (address required when miles-based is chosen).
   - Validates referral source, event type, delivery/pickup time if required.
   - Validates layout-defined custom/dynamic required fields.
   - Validates **visible** required billing/shipping layout fields, skipping any hidden by an unmet `depends_on` (mirrors the frontend). Error labels are section-qualified (e.g. “Venue Details — …”), use the field’s on-screen label (layout override → country locale → default), and carry a hidden field-id marker so the frontend anchors the error to the exact input.
6. WooCommerce validates billing/shipping and payment; duplicate field notices are de-duplicated.
7. If there are errors, checkout is re-rendered with notices; if none, order is created and user is redirected to order-received.

### 5.4 Order-received and thank-you only mode

- **Order Display** hooks into `woocommerce_thankyou` and order details.
- If **thank-you only** is enabled:
  - Only the custom thank-you message is shown (and optionally order-received notice).
  - Other content (order details, rental dates, quote number, addresses) is hidden via CSS and/or hooks.
- If thank-you only is disabled, the thank-you message is shown together with order details and rental data as configured.

---

## 6. Template Manager

The Template Manager allows saving, loading, and applying named layout templates for quick switching between configurations.

### 6.1 Overview

- **Maximum templates:** 20 (configurable via `MAX_TEMPLATES` constant)
- **Storage:** `wp_options` key `rental_checkout_layout_templates`
- **AJAX endpoints:** save, load, delete, list, update, apply

### 6.2 Template operations

| Operation | Description |
|-----------|-------------|
| **Save** | Create a new template from JSON input (name, description, layout JSON required) |
| **Load/Preview** | View template JSON in a large modal without applying |
| **Apply** | Apply template to active layout (saves immediately, no additional save needed) |
| **Edit** | Update template name/description, optionally update layout from current editor |
| **Delete** | Remove template permanently (with confirmation) |
| **Refresh** | Reload template list from server |

### 6.3 Admin UI (Templates tab)

The Templates tab provides:

1. **Available Templates list** — Shows all saved templates with actions (Preview, Apply, Edit, Delete)
2. **Save New Template form** — Create templates by:
   - Entering a name and description
   - Pasting layout JSON directly, or
   - Clicking "Copy Current Layout" to copy from JSON Editor tab
   - "Validate JSON" button to check syntax before saving
3. **Template limit notice** — Shows "(max 20)" next to template count

### 6.4 Preview modal

Clicking the **Preview** (eye icon) button opens a large modal showing:
- Template name, description, version, last updated
- Full JSON in a read-only textarea
- **Copy JSON** button — copies to clipboard
- **Apply This Template** button — applies directly from preview

### 6.5 Applying templates

When you apply a template:
1. Confirmation dialog shows template name
2. Template layout is saved as active layout via `Rental_Checkout_Layout_Config::save_layout()`
3. JSON Editor tab opens showing the applied layout
4. Success message confirms: "Template applied and saved! This layout is now live on your checkout page (no additional save needed)."

**Important:** Applied templates are saved immediately — no need to click "Save Layout" again.

### 6.6 Template data structure

```json
{
  "id": "tpl_abc123...",
  "name": "My Template",
  "description": "Template description",
  "layout": { /* full layout JSON */ },
  "version": "1.0.0",
  "created_at": "2026-01-30 10:00:00",
  "updated_at": "2026-01-30 10:00:00"
}
```

---

## 7. Visual Builder

### 7.1 Overview

The **Visual Builder** is a drag-and-drop admin interface for configuring the checkout layout. It is a graphical alternative to the JSON Editor tab — both read and write the same layout JSON stored in `rental_checkout_layout_config`.

**Location:** WordPress Admin → Rentopian → Checkout Layout Builder → **Visual Builder** tab.

**Files:**

| File | Purpose |
|------|---------|
| `assets/js/checkout-visual-builder.js` | JavaScript logic (palette, canvas, sortable, save) |
| `assets/css/checkout-visual-builder.css` | All Visual Builder styles |

### 7.2 UI components

The Visual Builder has three areas:

1. **Toolbar** — Add Section, Expand/Collapse All, Save Layout button with inline message.
2. **Field Palette** (left sidebar) — All registered fields grouped by type (Billing, Shipping, Order, Rental Components, Rental Custom/API, Other). Searchable. Placed fields are dimmed.
3. **Canvas** (right area) — Sections → Rows → Columns hierarchy matching the JSON structure.

### 7.3 Section properties

Each section supports:

| Property | UI control | JSON key | Description |
|----------|-----------|----------|-------------|
| ID | Read-only label | `id` | Unique section identifier |
| Title | Text input | `title` | Heading shown on frontend |
| Show Title | Checkbox | `show_title` | Toggle title visibility on frontend |
| Description | Text input | `description` | Sub-text below the heading |
| Show Description | Checkbox | `show_description` | Toggle description visibility on frontend |
| CSS Class | Text input | `class` | Custom CSS class for styling |

Section actions: collapse/expand, add row, remove (with confirmation), drag to reorder, move up/down buttons.

### 7.4 Row properties

Each row header shows:

| Element | Description |
|---------|-------------|
| Drag handle (☰) | Grab to drag-and-drop reorder |
| Row ID label | Display identifier (e.g. `row_0`) |
| Width bar | Visual indicator of total column widths (see §7.12) |
| Move up/down buttons (▲▼) | WP dashboard-style order buttons |
| Collapse/expand toggle (chevron) | Hide/show columns for easier navigation |
| Remove button (trash) | Delete row and release its fields |

Row actions: collapse/expand, drag to reorder, move up/down buttons, remove (with confirmation). Rows can be moved between sections via drag-and-drop.

### 7.5 Column (field) properties

Each column/field card exposes:

| Property | UI control | JSON key | Description |
|----------|-----------|----------|-------------|
| Width | Dropdown (3–12) | `width` | Grid column width |
| Label | Text input | `label_override` | Custom field label; overrides textual-settings and default labels (see §9.6) |
| Required | Checkbox | `required` | Whether field is required at checkout |
| Active | Checkbox | `active` | Whether field is rendered (1=yes, 0=no) |
| Hidden Visual | Checkbox (conditional) | `hidden_visual` | In DOM but CSS-hidden; see §7.6 |
| Checked | Checkbox (checkbox fields only) | `checked` | Render the box checked by default; see below |
| Depends On | Dropdown | `depends_on` | Parent field for conditional visibility; see §7.7 |

Badges: **M** (Mandatory, red) — cannot be removed/deactivated. **R** (Required, yellow). **O** (Optional, gray).

**Checked (default-checked):** The **Checked** control appears only for fields rendered as a checkbox (any field whose registry type is `checkbox`, e.g. `ship_to_different_address`, or a Rentopian checkbox custom field). When enabled, the box renders pre-ticked on first load (no submitted value); when disabled it renders unticked. Combined with **Hidden Visual** on `ship_to_different_address`, this yields an always-on shipping section with no visible toggle.

### 7.6 Hidden Visual — restrictions

The `hidden_visual` checkbox is **only shown** for address sub-fields that can be auto-populated by Google Places autocomplete:

| Billing | Shipping | Pickup |
|---------|----------|--------|
| `billing_address_2` | `shipping_address_2` | `pickup_address_2` |
| `billing_city` | `shipping_city` | `pickup_city` |
| `billing_state` | `shipping_state` | `pickup_state` |
| `billing_postcode` | `shipping_postcode` | `pickup_postcode` |
| `billing_country` | `shipping_country` | `pickup_country` |

Street address fields (`*_address_1`) and all non-address fields **cannot** have `hidden_visual`.

**Google API key requirement:** The address breakdown fields (`*_city`, `*_state`, `*_postcode`) may only stay hidden-visual when a Google Address Autocomplete API key (`rental_google_map_key`) is configured — autocomplete then fills them. Without a key, the config sanitizer forces these fields visible on save so customers can enter them manually. `ship_to_different_address` may be hidden-visual only when it is also activated/checked (otherwise it stays visible so the toggle remains usable).

### 7.7 Depends On — field dependencies

The `depends_on` property creates a parent → child relationship where child fields are only visible when the parent is checked/active.

**Available parent fields:**

| Parent field ID | Description |
|----------------|-------------|
| `ship_to_different_address` | "Ship to a different address?" checkbox |
| `rental_different_pickup_address` | "Different pickup address?" checkbox |
| `rental_multi_day_event` | "Multi day event?" checkbox |

**Visual indicators on the canvas:**

- **Child badge** (blue, ⤴) — on the dependent field, shows which parent it depends on.
- **Parent badge** (purple, ⤵) — on the parent field, shows how many children depend on it.

Both update in real-time when dependencies are added or removed.

**Example — Shipping section:**

```json
{
  "id": "row_shipping_name",
  "columns": [
    { "width": 6, "field": "shipping_first_name", "active": 1, "depends_on": "ship_to_different_address" },
    { "width": 6, "field": "shipping_last_name", "active": 1, "depends_on": "ship_to_different_address" }
  ]
}
```

In the Visual Builder, `ship_to_different_address` shows "⤵ 2 dependents" and each shipping name field shows "⤴ ship_to_different_address".

### 7.8 Mandatory fields

These fields cannot be removed, deactivated, or made non-required:

`billing_first_name`, `billing_last_name`, `billing_email`, `billing_address_1`, `billing_country`, `billing_city`, `billing_state`, `billing_postcode`, `shipping_country`, `rental_date_form_modern`.

They display a red **M** badge and a lock icon instead of a remove button. The mandatory set mirrors the order API's required fields (start date, name, email, address, country, city, state, postcode). The store base country is filled from WooCommerce settings and locked read-only on the form for both billing and shipping country.

### 7.9 Drag and drop

The Visual Builder uses jQuery UI Sortable for all drag-and-drop reordering, plus HTML5 native drag for palette → canvas drops.

#### Palette → Canvas (adding fields)

- Drag a field from the palette; active drop zones highlight in blue.
- Drop on a **row drop zone** (bottom of section) → creates a new row with that field.
- Drop on a **column drop zone** (end of row) → adds the field to that row.
- Already-placed fields are dimmed and cannot be dragged again.

#### Reordering on canvas (drag handles)

Sections, rows, and columns each have a drag handle (☰ move icon) for reordering via drag and drop.

| Element | Handle selector (within item) | Sortable container | Cross-container |
|---------|-------------------------------|-------------------|-----------------|
| Section | `.rvb-section-header .rvb-drag-handle` | `#rvb-canvas` | No |
| Row | `.rvb-row-header .rvb-drag-handle` | `.rvb-rows-container` | Yes (between sections) |
| Column | `.rvb-col-head > .rvb-drag-handle` | `.rvb-cols-container` | Yes (between rows) |

**Technical note:** jQuery UI Sortable's `handle` option matches selectors **within each sortable item**, not from the container. The handle must not include the item's own class in the selector path. For example, for sections (items = `.rvb-section`), the handle is `.rvb-section-header .rvb-drag-handle` — not `> .rvb-section > .rvb-section-header > .rvb-drag-handle`.

#### Sortable initialization

`initSortable()` is called after canvas build and after adding new rows/sections. It:

1. Destroys existing sortable instances (prevents duplicate binding).
2. Initializes section, row, and column sortables with correct handle selectors.
3. Sets up palette drag listeners.
4. Calls `updateOrderButtons()` to sync move-up/down disabled states.

#### Visual feedback

- **Drag opacity:** Items become semi-transparent (85% opacity) while being dragged.
- **Placeholder:** A blue dashed outline shows where the item will land.
- **Flash highlight:** After any reorder (drag-drop or button click), the moved item flashes with a blue glow animation (`rvb-flash` class, 0.7s ease-out).

### 7.10 Move up / move down buttons (WP dashboard pattern)

In addition to drag-and-drop, sections and rows have **move-up** (▲) and **move-down** (▼) arrow buttons — the same pattern used by WordPress dashboard widgets (`.order-higher-indicator` / `.order-lower-indicator`).

#### UI location

| Element | Button location | CSS classes |
|---------|----------------|-------------|
| Section | Right side of section header, before collapse/add/remove buttons | `.rvb-order-btns` → `.rvb-order-higher` / `.rvb-order-lower` |
| Row | Right side of row header (inside `.rvb-row-btns`), before collapse/remove buttons | Same classes |

The buttons are stacked vertically (up on top, down on bottom) using CSS triangles that match WordPress core's visual style.

#### Disabled states

`updateOrderButtons()` runs after every add, remove, or reorder operation and manages disabled states:

- **First item's "up"** button is disabled (`aria-disabled="true"`, `pointer-events: none`, 30% opacity).
- **Last item's "down"** button is disabled.
- All other buttons are enabled.

This applies independently to sections (within `#rvb-canvas`) and rows (within each section's `.rvb-rows-container`).

#### Implementation

The `moveItemUp($item, siblingSel)` and `moveItemDown($item, siblingSel)` methods are **shared** by both sections and rows (DRY). They:

1. Find the previous/next sibling matching `siblingSel`.
2. Move the DOM element before/after the sibling.
3. Flash the moved item.
4. Mark layout as dirty.
5. Update width bars and order button states.

### 7.11 Row collapse/expand

Each row has a collapse/expand toggle button (▲/▼ chevron icon) in its header. When collapsed:

- The row's `.rvb-row-body` (wrapper around `.rvb-cols-container`) is hidden.
- The row header border-bottom is removed for a clean collapsed appearance.
- The toggle icon switches from `dashicons-arrow-up-alt2` to `dashicons-arrow-down-alt2`.

This helps when working with layouts that have many rows — collapse rows to see the overall structure, then expand individual rows to edit their columns. Collapsed rows can still be reordered via drag-and-drop or move-up/down buttons.

### 7.12 Save behavior

- The save button shows a **blue glow ring** when there are unsaved changes.
- The save message appears **inline beside** the button (not above/below) using a fixed-height flex container, so the button never jumps or shifts position.
- After saving, the JSON Editor textarea is updated with the new layout so both tabs stay in sync.
- Whichever tab saves last determines the active layout.
- All save buttons (header and footer) show a consistent **spinning loader animation** (`.rental-btn-saving` class) during the AJAX request.

### 7.13 Width bar

Each row shows a colored width bar indicating the sum of column widths:

| Color | Meaning |
|-------|---------|
| Green | Sum = 12 (perfect) |
| Yellow | Sum < 12 (under-filled) |
| Red | Sum > 12 (over-filled, will wrap) |

---

## 8. File and option reference

### 11.1 PHP (includes/checkout)

| File | Purpose |
|------|--------|
| `checkout-layout-loader.php` | Bootstrap: require classes, register init hooks for Manager, Renderer, Validator, Order Display; error notice filter. |
| `class-checkout-layout-config.php` | Load/save/validate layout JSON; default layout; option keys. |
| `class-checkout-field-registry.php` | Register billing, shipping, rental static/dynamic fields; expose to Config and Field Renderer. |
| `class-checkout-layout-manager.php` | Admin menu, AJAX handlers, enqueue admin assets. |
| `class-checkout-layout-renderer.php` | Render layout on checkout; modify WC fields; manage default hooks. |
| `class-checkout-field-renderer.php` | Render one field by ID (WC, rental, component, dynamic). |
| `class-checkout-template-manager.php` | Template CRUD and apply. |
| `class-checkout-validator.php` | Checkout process validation for rental and layout fields. |
| `class-checkout-order-display.php` | Thank-you message and order-received / my-account display. |

### 11.2 Assets

| File | Purpose |
|------|--------|
| `assets/js/checkout-layout-admin.js` | Admin UI: edit layout, save/get/reset/import/export, templates, validation. |
| `assets/js/checkout-visual-builder.js` | Visual Builder: palette, canvas, drag-drop, sortable, save, dependency indicators. |
| `assets/js/checkout-layout-frontend.js` | Frontend: dependencies, visibility, validation feedback, sync billing fields before submit, loading state, error order. |
| `assets/css/checkout-visual-builder.css` | Visual Builder admin styles (toolbar, palette, canvas, columns, badges, drop zones). |
| `assets/css/checkout-layout-frontend.css` | Grid and layout styling for checkout form. |

### 11.3 Options (wp_options)

| Option | Meaning |
|--------|--------|
| `rental_checkout_layout_config` | JSON layout (sections, rows, columns). |
| `rental_checkout_thank_you_message` | HTML/text for thank-you message. |
| `rental_checkout_thank_you_only` | If set, order-received shows only thank-you message. |
| `rental_checkout_layout_mode` | `'classic'` or `'modern'`. |
| `rental_dates_on_checkout` | Dates on checkout page (required for modern). |
| `rental_allow_overbook` | Allow overbook (required for modern). |
| `rental_checkout_layout_templates` | Stored layout templates (template manager). |
| `rental_google_map_key` | Google Address Autocomplete API key. Gates whether address breakdown fields may be hidden-visual (see §7.6). |
| `rental_street_address_label_text` | Textual label for the street address field; one of the **Textual Labels** options consumed as the layer-2 label (see §9.6). |


---

## 9. Frontend features

### 11.1 Field validation and error handling

The frontend provides intelligent error handling:

- **Error deduplication:** Prevents duplicate error messages (e.g., same field error from both WC and custom validation)
- **Error ordering:** Errors are sorted by field order priority to match visual form layout
- **Exact error anchoring:** Each server error carries a hidden marker with the target field id (billing and shipping share labels like “First Name”/“County”, so text alone is ambiguous). The frontend resolves the error to that exact input for scrolling, highlighting, and live-resolution; it falls back to keyword/label matching only for markerless notices (e.g. WooCommerce core)
- **Hidden-visual filtering (field-based):** An error is hidden from the summary only when its resolved field is genuinely hidden-visual (in DOM but CSS-hidden), not by matching keywords in the message text. Visible fields always show their error even if the label contains a word like “address”. Keyword matching remains a fallback for markerless notices
- **Field highlighting:** Invalid fields receive .rentopian-field-error class with red border
- **Reactive error resolution:** Each summary entry is linked to its field; correcting a field removes its error from the summary and clears the highlight immediately, and emptying a corrected field brings the error back. The summary hides itself once all field errors are resolved — no resubmission needed
- **Dependency-aware errors:** Fields hidden by a dependency toggle (e.g. shipping fields when "Ship to different address" is unchecked) drop their errors from the summary; re-showing them re-evaluates their state
- **Single scroll per validation:** Each server validation response is processed once, so the page scrolls to the summary one time; live error updates never scroll
- **Stuck-submission recovery:** After a validation error, the Place Order click could be silently swallowed so the form appeared frozen under the loading overlay (recoverable only by reloading). Root cause was WooCommerce's submit being short-circuited by a leftover lock: the form's `processing` class, or a blockUI overlay left on the payment/review-order area by an `update_order_review` request — that overlay sits over the Place Order button. The plugin also made this worse by triggering `change` on every billing field on Place Order mousedown, spawning a burst of `update_order_review` requests right as the user clicked. Fixes: the mousedown sync no longer triggers `change` (values are still synced for serialization); a single `unstickStaleProcessing()` clears the `processing` class and blockUI overlays before each submit attempt (on mousedown, click, and submit-capture), but never while a real checkout request is in flight; the loading overlay only shows when a request is actually in flight or the form is genuinely processing, so it can never outlive a non-request; a watchdog aborts a request that truly never completes; and the form gets `novalidate` (validation is server-side, so native browser validation must not cancel submission via hidden required fields). A diagnostic endpoint (`rental_checkout_diag`, nonce-verified, logging-only) records each stuck/recovery event to the order logs (`CHECKOUT_STUCK | …`) for production confirmation
- **Fatal-error recovery during order processing:** WooCommerce's `process_checkout()` only catches `Exception`, so a fatal `Error`/`TypeError` thrown by any hook between validation and order save (e.g. `woocommerce_checkout_create_order`) escapes uncaught — the request dies with no JSON body and the browser's checkout AJAX never resolves, freezing the customer under the loading overlay until they reload. A shutdown guard (`rental_arm_checkout_fatal_guard`, armed on `woocommerce_checkout_process`, modern checkout only) detects such a fatal, logs its exact `file:line` to the order logs (`CHECKOUT_FATAL | …`, source `rentopian-checkout`), and emits the JSON failure response the frontend expects so the form unlocks and the customer can retry. It only acts on a genuine fatal; normal success/failure responses are untouched. The frontend also selects the first payment method before submit if none is checked, since fragment replacement during the error flow can leave `payment_method` empty (an input observed alongside these failures)
- **Checkout API timeouts:** Rentopian API calls made during the checkout request (order add/pay, blacklist check) have connect and total timeouts so a slow API cannot hang the checkout POST; a timed-out order sync is logged and healed later while the order completes normally

### 11.2 Pickup address persistence

When using a different pickup address:
- Checkbox state and pickup address fields are saved to sessionStorage
- Values are restored after page refresh or update_checkout AJAX
- Prevents data loss during checkout updates

### 11.3 Google Maps autocomplete

- Autocomplete attaches to visible street address inputs
- Populates city, state, postcode, country (including hidden inputs)
- Works with both billing and pickup address fields
- Hidden address fields are rendered unconditionally to ensure form submission works

### 11.4 Rental dates: ordering safety

- End date calendar minimum date syncs with start date selection.
- Choosing a start date later than the current end date clears the stale end date (handled on both date selection and the Multi-Day Event toggle), so an end-before-start pair can never be submitted.
- Toggling Multi-Day Event correctly initializes the end date picker.
- Date/time summary updates without page refresh via AJAX.
- The order pipeline also validates/repairs date chronology before sending to the API, so a start date later than the end date is never transmitted.

### 11.5 Loading states

- Loading overlay during checkout submission
- Spinner and Processing please wait text
- Disabled form elements during submission
- Individual template item loading states in admin

### 9.6 Field label resolution (3 layers)

Every checkout field label resolves through three layers, each overriding the previous:

1. **Default** — the registry/WooCommerce default label (e.g. “Street Address”, “State / Province”).
2. **Textual** — the value from the **Textual Labels** settings (e.g. `rental_street_address_label_text`, event/referral/delivery options). The Field Registry reads these options, so a set value becomes the field’s base label.
3. **Custom** — the per-field `label_override` from the layout column (Visual Builder “Label” field). Highest priority.

Resolution order is **custom → textual → default**, applied consistently in:

- **Server render** — `render()` applies `label_override` over the registry label (which already includes the textual value).
- **Client re-assertion** — WooCommerce’s address i18n rewrites state/postcode/country labels on load and on country change; a localized `labelOverrides` map is re-applied after those events (preserving the required asterisk) so custom labels stick. The legacy street-address label setter also prefers the custom override.
- **Validation errors** — the error label uses the same resolved label (override → country locale → default), so a missing-field message matches exactly what the customer sees (e.g. “Venue Details — Post Code is a required field”).

**Required asterisk:** the store base country’s `state`/`postcode`/`city` are forced required and visible via a `woocommerce_get_country_locale` override (registered early so WooCommerce’s locale cache cannot drop it), and the asterisk is forced visible in CSS with high specificity so a theme that hides WooCommerce’s `.required` marker cannot blank it.

---

## 10. Extending the system

### 11.1 Adding a new field (available in the builder)

1. **Register the field** in the Field Registry (e.g. in `register_rental_fields()` or in a custom hook):
   - Call `$registry->register_field($field_id, $config)`.
   - `$config` should include at least: `label`, `type`, `required`, `source` (`woocommerce`, `rental_custom`, `rental_dynamic`, etc.).

2. **Render the field** in the Field Renderer:
   - If it fits an existing `source` and type, no change.
   - If it is a new type (e.g. custom component), add a branch in `render()` or a new method and call it from `render()`.

3. **Optional: validation**  
   If the field must be required at checkout, ensure the Validator knows it (e.g. add a case in `validate_layout_fields()` or a dedicated validator method), and that the Registry marks it as `required` where appropriate.

4. **Add to a layout**  
   In the layout JSON, add a column with `"field": "your_field_id"` in the desired section/row.

Example (conceptual):

```php
// In a plugin or theme, or in Field Registry's init:
add_action('rental_checkout_register_fields', function($registry) {
    $registry->register_field('my_custom_field', array(
        'label'    => __('My Field', 'my-textdomain'),
        'type'     => 'text',
        'required' => true,
        'source'   => 'rental_custom',
    ));
});
```

### 11.2 Order Display: dynamic fields and checkbox (Yes/No) display

Order-received and my-account order view show rental data in sections: **Rental Summary**, **Delivery Time(s)**, **Event Details**, **Additional Information**. Data comes from:

- Order meta keys (e.g. `_rental_start_date`, `_rental_event_type`).
- Layout used field IDs (saved at checkout from layout + registry).
- `rental_custom_fields` option (fields stored by **slug**).
- Any other `_rental_*` order meta (from dynamic layout/component fields).

**Checkbox / boolean fields** (e.g. “Outside Event”, “Multi-Day Event”, “Flexible with time”) must display as **Yes/No**, not raw `1` or `yes`. The plugin keeps a list of meta keys and slugs that are formatted as Yes/No. If you add a **new** checkbox field (e.g. “Outside Event” with slug `outside_event`), it is determined by **field type**:

1. **Type-based (automatic)**  
   When the field is known as a checkbox (option type = 3 or Registry type = checkbox), its value is shown as Yes/No. New checkbox keys do not break the system. (The legacy list of keys such as `outdoor_event`, `multi_day`, etc.) is used only as fallback when the field is not in the option or Registry.)

2. **Fallback** For fields not in the option or Registry (e.g. third-party or legacy), use the filter `rentopian_order_display_yes_no_keys` to add the key/slug so it displays as Yes/No.

**Filters for Order Display**

| Filter | Purpose |
|--------|--------|
| **rentopian_order_display_yes_no_keys** | Array of meta keys (e.g. `_rental_outdoor_event`) and/or slugs (e.g. `outside_event`) that should display as “Yes”/“No”. Add your checkbox field’s slug or meta key here. |
| **rentopian_order_display_section_placement** | Array `event_details` and `delivery_info`, each mapping meta key or slug → label. Use to put a field in Event Details or Delivery Time(s) instead of Additional Information. |
| **rentopian_checkout_post_to_order_meta_map** | Map POST key → order meta key when saving at checkout (e.g. `rental_multi_day_event` → `_rental_multi_day`). Use when the form posts a key that should be stored under a different meta key. |
| **rentopian_order_rental_data** | Final rental data array (sections) before output; passes `$data` and `$order`. Use to add or modify sections. |

Example: ensure a custom checkbox “Outside Event” (slug `outside_event`) displays as Yes/No and appears in Event Details:

```php
add_filter('rentopian_order_display_yes_no_keys', function($keys) {
    $keys[] = 'outside_event';       // slug from rental_custom_fields
    $keys[] = '_rental_outside_event'; // if saved with _rental_ prefix
    return $keys;
});
add_filter('rentopian_order_display_section_placement', function($map) {
    $map['event_details']['outside_event'] = __('Outside Event', 'my-textdomain');
    $map['event_details']['_rental_outside_event'] = __('Outside Event', 'my-textdomain');
    return $map;
});
```

"Outside Event" (POST name `rental_outside_event`) is already in the default POST → meta map. For another field (e.g. `rental_my_checkbox`), add to the map:

```php
add_filter('rentopian_checkout_post_to_order_meta_map', function($map) {
    $map['rental_my_checkbox'] = '_rental_my_checkbox';
    return $map;
});
```

### 11.3 Hooks and filters (general examples)

- **rental_checkout_register_fields**  
  Passes the Field Registry instance; use it to register new fields.

- **rental_checkout_layout_saved**  
  Fires after layout config is saved; passes the new config array.

- **woocommerce_checkout_fields**  
  The Layout Renderer uses this to mark handled fields (`rentopian_handled`); you can further adjust fields here if needed.

When adding new hooks in code, document them in this section (or in `extending.md`) with: name, arguments, when it runs, and a one-line example.

### 11.4 Adding a new section type or row behavior

- Sections are currently a simple loop in the Layout Renderer; no “section types” exist. To add behavior (e.g. collapsible section), you can:
  - Add a section `class` or `data-*` in the layout JSON and handle it in CSS/JS, or
  - Extend `render_section()` in the Layout Renderer to support a new section property (e.g. `collapsible: true`) and output extra markup/attributes.

Document any new section/row options in §4 (Configuration) and §8 (Examples).

---

## 11. Examples

### 11.1 Two-column row (name + contact)

Same as in §4.3: one row, two columns of width 6, with `billing_first_name`, `billing_last_name`; another row with `billing_phone`, `billing_email`.

### 11.2 Single full-width component

One row, one column of width 12, for the modern date form:

```json
{
  "id": "row_dates",
  "columns": [
    { "width": 12, "field": "rental_date_form_modern", "active": 1 }
  ]
}
```

### 11.3 Field that depends on “Ship to different address”

In the layout JSON, set the column’s `depends_on` to the field ID that controls shipping (e.g. `ship_to_different_address`). The frontend JS (`checkout-layout-frontend.js`) uses `data-depends-on` to show/hide the column. Example:

```json
{ "width": 12, "field": "shipping_address_1", "active": 1, "depends_on": "ship_to_different_address" }
```

(Exact `depends_on` value must match what the script expects.)

### 11.4 Hidden required field (e.g. country)

Use `hidden_visual: 1` so the field is rendered (and submitted) but not visible. The validator can still require it; the frontend automatically omits its error from the summary because the resolved field is hidden-visual (no keyword list needed — see §9.1). Note the Google API key requirement for hiding `*_city`/`*_state`/`*_postcode` (see §7.6); `billing_country`/`shipping_country` are filled from the store base country and locked.

```json
{ "width": 12, "field": "billing_country", "active": 1, "hidden_visual": 1 }
```

### 11.5 Default layout

The default layout is generated by `Rental_Checkout_Layout_Config::get_default_layout()`. It typically includes multiple sections (billing, rental options, etc.) and is used when no config is saved yet or after “Reset”. See `get_default_layout()` in `class-checkout-layout-config.php` for the exact structure.

### 11.6 Adding a checkbox field (e.g. “Outside Event”)

**"Outside Event"** (slug `outside_event`, POST name `rental_outside_event`) is fully supported by default: saved to `_rental_outside_event`, displayed as Yes/No, and shown in **Event Details**. No filters needed.

For another checkbox (e.g. slug `my_checkbox`):

1. Add the field in your data source (e.g. Rentopian API custom fields or Field Registry and layout).
2. At checkout, the value is saved to order meta (by slug for API custom fields, or by layout/registry).
3. On order-received and my-account, the value appears in **Additional Information** (or **Event Details** / **Delivery Time(s)** if you add it to the section placement filter).
4. To show **Yes/No**, add your slug/meta key via the filter (the default list already includes `outside_event` and `outdoor_event`):

```php
add_filter('rentopian_order_display_yes_no_keys', function($keys) {
    $keys[] = 'my_checkbox';
    $keys[] = '_rental_my_checkbox';
    return $keys;
});
```

Then the field is covered end-to-end: saved at checkout and displayed as Yes/No on order and emails.

---

## 12. Documentation maintenance

- **Version:** When the layout format or behavior changes (e.g. new required keys, new validation rules), update the “Last updated” at the top and the relevant section; consider a short “Configuration changelog” under §4.
- **New features:** Add a subsection under the appropriate major section (e.g. new field type under §7, new workflow under §5) and, if useful, an example under §8.
- **Splitting:** If this file becomes too long, move §3 to `architecture.md`, §5 to `workflows.md`, and §7–§8 to `extending.md`, and keep in this README only the TOC, §1, §2, §6, and §9 plus one-sentence links to the other files.
