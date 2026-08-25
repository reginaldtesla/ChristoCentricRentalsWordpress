# Sets Module — Documentation

**Component:** Rentopian Sync — Sets (composite/grouped products) Module
**Audience:** Developers, technical maintainers
**Last updated:** 2026-05-04
**Version:** 2.14.7
**Conventions:** Single-source structure; can be split later (e.g. `architecture.md`, `workflows.md`) without losing context.

---

## Table of contents

1. [Introduction](#1-introduction)
2. [Prerequisites](#2-prerequisites)
3. [Architecture](#3-architecture)
4. [Database / postmeta schema](#4-database--postmeta-schema)
5. [Class reference](#5-class-reference)
6. [Workflows](#6-workflows)
7. [Validation rules reference](#7-validation-rules-reference)
8. [POST shape reference (modern renderer → server)](#8-post-shape-reference)
9. [WP→Core payload shape](#9-wp-core-payload-shape)
10. [Filters](#10-filters)
11. [Hook priority order](#11-hook-priority-order)
12. [Settings](#12-settings)
13. [Backward compatibility](#13-backward-compatibility)
14. [Theme overrides](#14-theme-overrides)
15. [Integration steps](#15-integration-steps)
16. [Documentation maintenance](#16-documentation-maintenance)

> **Cart/checkout price rules** have their own dedicated reference:
> **[PRICE-RULES.md](PRICE-RULES.md)** — display vs. calculation, the
> three display cases, the two pricing models, and the single source of
> truth (`rental_set_child_effective_unit_price`). Quantity-sync +
> removal-cascade rules are in [WORKFLOWS.md §11](WORKFLOWS.md).

---

## 1. Introduction

### 1.1 Purpose

The **Sets** module manages "rental sets" — packages composed of multiple inventory items with optional configuration. It synchronises set data from the Rentopian Laravel back-end into WordPress, then renders configurable purchase forms on the storefront and round-trips customer selections back to the Laravel order system.

### 1.2 Three entity types per set

The Laravel side ships three kinds of items inside a set:

| Entity | Source table | Description |
|---|---|---|
| 1. Simple item | `inventory_sets_relation` | Fixed inventory entry, customer can't change |
| 2. Selectable item | `inventory_sets_products_relation` | Customer picks one variant from a list |
| 3. Composite group | `inventory_sets_groups_relation` + `inventory_sets_groups_items` | Named bucket with min/max/multiple-selection rules; the customer picks one or more children with their own quantities |

Entity 3 is the modern feature — added by the layout work. Entities 1 and 2 are pre-existing and continue to work in the new module.

### 1.3 Layout modes

The plugin supports two render modes for set product pages, controlled by the `rental_sets_layout_mode` option:

| Mode | Constant | UI |
|---|---|---|
| **Classic** | `'classic'` | The legacy `rental_set_products_list` table renderer |
| **Modern** | `'modern'` (set on first install) | The section-based renderer with dropdowns / multi-select cards / steppers |

Modern is the only mode that supports composite groups (entity 3). Classic-only sets still render in either mode.

### 1.4 Glossary

| Term | Meaning |
|---|---|
| **Set** | A WP product with `_rental_is_set = 1` postmeta. |
| **Set parent line** | A cart line for the set itself (qty = how many copies of the package). |
| **Set child line** | A cart line for a chosen item inside the package. Has `rental_add_on_of` pointing at the parent's cart_item_key. |
| **Group** | An entity-3 named bucket inside the set. Identified by a UID like `{division_id}-grp-{22ch base64url}`. |
| **Group child** | A cart line for a chosen item inside a composite group. Has `rental_set_group_*` meta keys. |
| **Synthetic UID** | A renderer-only group UID for selectable items rendered as single-select groups: `sel-{set_id}-{product_id}`. |
| **Item-based-total** | A set-level flag. When 1, the set's price is computed as the sum of its items' prices; when 0, the admin-set `rental_price` is authoritative. |

---

## 2. Prerequisites

- WordPress 5.0+
- WooCommerce active
- Rentopian Sync plugin active
- PHP 7.4+
- A valid `rental_api_key` set in plugin settings
- The Laravel back-end reachable from the WP server

---

## 3. Architecture

### 3.1 Module location

```
includes/sets/
├── bootstrap.php                            ← ENTRY POINT (loaded from rentopian-sync.php)
├── compat-functions.php                     ← Backward-compat function wrappers
├── (sync-side classes — entity 1+2 sync)
│   ├── class-sets-tables.php
│   ├── class-sets-api-client.php
│   ├── class-sets-options-repository.php
│   ├── class-sets-options-query.php
│   ├── class-sets-divisions-query.php
│   ├── class-sets-up-cross-sells-processor.php
│   ├── class-sets-image-linker.php
│   ├── class-sets-gallery-collector.php
│   ├── class-sets-sync-context.php
│   ├── class-sets-sync-result.php
│   ├── class-sets-item-builder.php
│   ├── class-sets-sql-builder.php
│   ├── class-sets-processor.php
│   ├── class-sets-categories-linker.php
│   ├── class-sets-tags-processor.php
│   ├── class-sets-assets.php
│   ├── class-sets-admin-settings.php
│   └── class-sets-cart-visibility.php
├── (modern feature — composite groups + cart pipeline)
│   ├── class-sets-cart-meta.php             ← Source of truth for rental_set_group_* keys
│   ├── class-sets-cart-handler.php          ← Tags cart lines as group children
│   ├── class-sets-cart-merger.php           ← Parent-line merge with options-replace
│   ├── class-sets-cart-edit-mode.php        ← ?rental_edit_cart=KEY edit-replaces line
│   ├── class-sets-cart-edit-link.php        ← "Edit" link on cart parent lines
│   ├── class-sets-archive-guard.php         ← DEPRECATED shim (logic moved to validator rule)
│   ├── class-sets-product-page-prefill.php  ← window.RentalSetsPrefill emitter
│   ├── class-sets-order-meta.php            ← Cart-item meta → order-item meta
│   └── class-sets-payload-extender.php      ← Inventories JSON enrichment
├── (validation pipeline)
│   └── validation/
│       ├── class-sets-validation-result.php
│       ├── class-sets-validator-context.php
│       ├── class-sets-validation-rule.php
│       ├── class-sets-rule-archive-context.php
│       ├── class-sets-rule-max-quantity.php
│       ├── class-sets-rule-set-options.php
│       ├── class-sets-rule-selectable-items.php
│       ├── class-sets-rule-group-required.php
│       ├── class-sets-rule-group-quantity.php
│       ├── class-sets-rule-availability.php
│       ├── class-sets-cart-validator.php
│       └── class-sets-rules-exporter.php
├── (modern renderer)
│   ├── class-sets-section-presenter.php
│   ├── class-sets-renderer.php
│   ├── views/
│   │   ├── single-product-modern.php
│   │   └── partials/
│   │       ├── section-fixed-item.php
│   │       ├── section-dropdown.php
│   │       ├── section-dropdown-qty.php
│   │       └── section-multi-select.php
│   └── assets/
│       ├── css/rental-sets-modern.css
│       └── js/rental-sets-modern.js
└── (cart/order display + webhook pipeline + pricing)
    ├── class-sets-cart-display.php          ← "Group" meta on cart/checkout
    ├── class-sets-order-display.php         ← "Group" meta on emails/admin
    ├── class-sets-webhook-defense.php       ← Webhook shape defense + UID validator
    ├── class-sets-webhook-meta-guard.php    ← Scoped postmeta wipe for set/create
    ├── class-sets-webhook-item-persister.php ← Entity 1+2 builder (api.php delegate)
    ├── class-sets-webhook-group-persister.php ← Entity 3 builder (api.php delegate)
    ├── class-sets-webhook-handler.php       ← Orchestrator: set/create + set/update + set/delete
    └── class-sets-pricing.php               ← Cart-side item_based_total recalc
```

### 3.2 Load order (selected, modern feature only)

```
plugins_loaded (priority 5):
    Rental_Sets_Webhook_Defense::register()         ← hooks `update_post_metadata` early

init (priority 5):
    Rental_Sets_Cart_Edit_Mode::register()          ← detects ?rental_edit_cart= early

init (priority 20):
    Rental_Sets_Cart_Handler::register()
    Rental_Sets_Cart_Merger::register()
    Rental_Sets_Cart_Edit_Link::register()
    Rental_Sets_Cart_Validator::register()
    Rental_Sets_Product_Page_Prefill::register()
    Rental_Sets_Order_Meta::register()
    Rental_Sets_Payload_Extender::register()
    Rental_Sets_Renderer::register()
    Rental_Sets_Cart_Display::register()
    Rental_Sets_Order_Display::register()
    Rental_Sets_Pricing::register()

(loaded but not hooked — invoked directly from api.php):
    Rental_Sets_Webhook_Meta_Guard
    Rental_Sets_Webhook_Item_Persister
    Rental_Sets_Webhook_Group_Persister
    Rental_Sets_Webhook_Handler                     ← orchestrator (composes the three above)
```

### 3.3 Dependency graph (modern feature)

```
Rental_Sets_Renderer
  └─ Rental_Sets_Section_Presenter
       (reads _rental_set_items, _rental_set_grouped_items, _rental_set_order)

Rental_Sets_Cart_Validator
  ├─ Rental_Sets_Validator_Context
  ├─ Rental_Sets_Validation_Result
  └─ rules:
       ├─ Rental_Sets_Rule_Archive_Context
       ├─ Rental_Sets_Rule_Max_Quantity
       ├─ Rental_Sets_Rule_Set_Options
       ├─ Rental_Sets_Rule_Selectable_Items
       ├─ Rental_Sets_Rule_Group_Required
       ├─ Rental_Sets_Rule_Group_Quantity
       └─ Rental_Sets_Rule_Availability

Rental_Sets_Product_Page_Prefill
  ├─ Rental_Sets_Cart_Edit_Mode (instance check)
  ├─ Rental_Sets_Cart_Validator (failed-selection blob read)
  └─ Rental_Sets_Rules_Exporter (rule data for window.RentalSetsPrefill.rules)

Rental_Sets_Cart_Handler
  └─ Rental_Sets_Cart_Meta (key registry)

Rental_Sets_Order_Meta / Cart_Display / Order_Display
  └─ Rental_Sets_Cart_Meta

Rental_Sets_Payload_Extender
  └─ Rental_Sets_Cart_Meta

Rental_Sets_Pricing
  └─ Rental_Sets_Cart_Meta (group-line de-dup)
```

---

## 4. Database / postmeta schema

The modern feature is **postmeta-only** — no new SQL tables. Existing relations tables (`{prefix}_rental_set_relations`, `{prefix}_rental_product_relations`, `{prefix}_rental_variant_relations`) are reused as-is.

### 4.1 Set-level postmeta

| Key | Type | Description |
|---|---|---|
| `_rental_is_set` | bool | 1 if this WP product is a set |
| `_rental_set_items` | array | Entity 1+2 items (legacy + extended for modern) |
| `_rental_set_grouped_items` | array | Entity 3 composite groups |
| `_rental_set_has_grouped_items` | bool | True if any groups exist |
| `_rental_set_some_hidden_groups` | bool | True if any group has `hide_on_website=1` |
| `_rental_set_order` | JSON string | UID array defining display order across entity 1+2+3 |
| `_rental_set_items_have_optional_items` | bool | True if any entity-2 items |
| `_rental_some_hidden_items` | bool | True if any entity-1 items with `hidden=1` |
| `_rental_hide_items_on_website` | bool | Whole-set "items hidden" flag |
| `_rental_item_based_total` | bool | When 1, the cart pricing recalculator computes the parent line price as the sum of its items' contributions; when 0, the admin-set rental price is authoritative |
| `_rental_max_quantity` | int | Max parent qty |
| `_rental_sets_layout_mode` | string | Per-set override (rare) |

### 4.1.1 Hide on website (hidden set items)

Two scopes hide a set's contents from customers:

- **Whole-set** — `_rental_hide_items_on_website` (per set) OR the global
  `rental_hide_set_items` option. All items are hidden.
- **Per-item** — an item's own `hidden` flag
  (`inventory_sets_relation.hide_on_website`); `_rental_some_hidden_items`
  is the per-set "any item hidden" summary. Groups use `hide_on_website`;
  addons use `hidden`.

How it is applied (single source of truth, no theme code):

1. **Product page** — the configurator never shows a hidden item. Per-item
   hidden sections are dropped by `Rental_Sets_Section_Presenter`; a
   whole-set hide makes the presenter emit **no sections** (the classic
   renderer already renders no item table), so the customer sees only the
   set + Add to Cart.

2. **Cart build** — hidden items are still added (they belong to the set)
   and **still contribute their price to the total** under the exact same
   rules as visible items (separate_price, item_based_total, a chosen/
   default variant, addons). They just don't render a row, qty stepper, or
   price in the UI — the price rides on the (hidden) cart line so the order
   total and the Rentopian payload match the in-system flow. (So an admin
   who doesn't want a hidden item to affect the total should set its price
   to 0.)

   Resolution is server-side (`Rental_Sets_Selection`), for classic and
   modern, old and new sets, replacing the former page-load
   `rental_set_items_default_update` AJAX:
   - **Simple items** keep their in-set price (zeroed only by the normal
     fixed-bundle rule, preserved for separate_price / item_based_total).
   - **Selectable items** (entity-2) are locked to their default option
     whenever the item has no variant yet — covering the case where the API
     marked `has_selected` but kept the variant on the option (previously
     added as a non-variant `$0` line). No resolvable default → skipped.
   - **Addons** are locked to their default variant; no default → dropped.
   - **Composite groups** (entity-3) — `resolve_hidden_group_children()`
     adds the group's default item(s) (single-select: one default or the
     sole item; multi-select: all defaults) as grouped-child lines, priced
     by the normal group rules (`group_price` → in-set price → variant
     chain). A group with no default is skipped.

3. **Cart / mini-cart / checkout / order / emails** — the plugin returns the
   row hidden through WooCommerce's standard visibility filters
   (`woocommerce_cart_item_visible`, `woocommerce_widget_cart_item_visible`,
   `woocommerce_checkout_cart_item_visible`, `woocommerce_order_item_visible`)
   via `Rental_Sets_Cart_Visibility`. Theme templates carry **no**
   Sets-specific hide logic.

### 4.1.2 Selection overlay vs definition (stale-overlay guard)

A customer's in-progress picks live in a per-session **overlay** of the
items array (`Rental_Sets_Selection`), while the `_rental_set_items`
postmeta is the authoritative **definition**. `resolve()` returns the
overlay only while it still matches the definition it was built against:
`store_override()` snapshots a `definition_fingerprint()` of the postmeta,
and `resolve()` drops the overlay when the live fingerprint differs.

The fingerprint covers every Sets DETAIL — `hidden`, `required`,
`separate_price`, in-set `price`, `quantity`, `note`, and the full
item / option / addon structure — so an admin/webhook edit (e.g. hiding an
item, or changing a price) reflects on the next page load instead of being
shadowed by a stale overlay. It is computed from postmeta both times
(never the overlay), so selection-induced price/variant mutations never
false-trigger it, and pure selection/default fields (`has_selected`,
`is_selected`, `optional_item_price_update_needed`) are excluded so an
in-progress pick survives a default-only change. The overlay self-heals:
on the next write it re-snapshots, and add-to-cart clears it entirely.

### 4.2 Cart-item meta (modern, set by Cart_Handler)

The keys live on `WC()->cart->cart_contents[<key>]` and survive session save/restore.

| Key constant | Description |
|---|---|
| `Cart_Meta::KEY_IS_GROUP_CHILD` (`rental_set_group_is_child`) | Marker, 1 = group child |
| `Cart_Meta::KEY_GROUP_ID` (`rental_set_group_id`) | Server `inventory_sets_groups_relation.id` |
| `Cart_Meta::KEY_GROUP_UID` (`rental_set_group_uid`) | Deterministic UID |
| `Cart_Meta::KEY_GROUP_ITEM_UID` (`rental_set_group_item_uid`) | UID of the chosen child |
| `Cart_Meta::KEY_GROUP_PRICE` (`rental_set_group_price`) | Group's `group_price` (nullable) |
| `Cart_Meta::KEY_GROUP_QTY` (`rental_set_group_qty`) | Group's `group_quantity` — product-page qty-stepper default; carried as order/quote metadata (not a cart-line billing multiplier) |
| `Cart_Meta::KEY_GROUP_REQUIRED` (`rental_set_group_required`) | 0/1 |
| `Cart_Meta::KEY_GROUP_MULTIPLE_SELECTION` (`rental_set_group_multiple_selection`) | 0/1 |
| `Cart_Meta::KEY_GROUP_NAME` (`rental_set_group_name`) | Display label |

### 4.3 Order-item meta (modern, set by Order_Meta)

Same keys as cart-item meta, but underscore-prefixed:

```
_rental_set_group_is_child
_rental_set_group_id
_rental_set_group_uid
... etc
```

The underscore prefix hides them from WC's default itemmeta box; `Rental_Sets_Order_Display::hide_internal_meta` reinforces this.

### 4.4 Session keys

| Key | Set by | Purpose |
|---|---|---|
| `rental_sets_failed_selections_{set_id}` | `Cart_Validator::persist_selections_for_reprefill` | Re-prefill on failed-submit — "abort but keep selections so the user doesn't re-create what they had" |

### 4.5 WP options

| Option | Type | Description |
|---|---|---|
| `rental_sets_layout_mode` | string | `'classic'` or `'modern'` (modern on first install) |
| `rental_set_listing_style` | string | Classic-mode table style (`'standard'` or other) |
| `rental_set_components_text` | string | Header label for classic table |
| `rental_hide_set_items` | bool | Hide entire classic items table |

---

## 5. Class reference

### 5.1 Sync-side (entities 1+2 — pre-existing)

| Class | Role |
|---|---|
| `Rental_Sets_Tables` | DB table accessors |
| `Rental_Sets_API_Client` | HTTP wrapper around the `sets/...` API |
| `Rental_Sets_Options_Repository` | CRUD on `RTSetOptions` |
| `Rental_Sets_Options_Query` | Read-side helpers for set options |
| `Rental_Sets_Divisions_Query` | Division resolution helpers |
| `Rental_Sets_Up_Cross_Sells_Processor` | Up/cross-sell links |
| `Rental_Sets_Image_Linker` | Set thumbnail / gallery wiring |
| `Rental_Sets_Gallery_Collector` | Bulk gallery collection during sync |
| `Rental_Sets_Sync_Context` | Per-sync immutable context |
| `Rental_Sets_Sync_Result` | Sync result bag |
| `Rental_Sets_Item_Builder` | Build entity-1+2 set items from API rows |
| `Rental_Sets_SQL_Builder` | Postmeta SQL for sync |
| `Rental_Sets_Processor` | Top-level sync orchestrator |
| `Rental_Sets_Categories_Linker` | Term-relation wiring |
| `Rental_Sets_Tags_Processor` | Tag taxonomy wiring |
| `Rental_Sets_Assets` | Frontend CSS/JS enqueue (classic) |
| `Rental_Sets_Admin_Settings` | Admin panel layout-mode toggle |
| `Rental_Sets_Cart_Visibility` | Hidden-item resolver |
| `Rental_Sets_Selection` | Per-customer selection overlay + definition read (stale-overlay guard) + hidden-item default resolution. The `rental_set_*` procedural helpers delegate here. |

### 5.2 Modern feature — Cart pipeline

| Class | Role |
|---|---|
| `Rental_Sets_Cart_Meta` | Source of truth for `rental_set_group_*` keys |
| `Rental_Sets_Cart_Handler` | Tags cart-item-data when POST contains `item_type: grouped_child` |
| `Rental_Sets_Cart_Merger` | Parent-line merge w/ options-replace; child-line merge "for free" via WC cart-id hash |
| `Rental_Sets_Cart_Edit_Mode` | Detects `?rental_edit_cart=KEY` / `_rental_edit_cart_key` POST; signals merger to skip; replaces old line on submit |
| `Rental_Sets_Cart_Edit_Link` | Adds "Edit" affordance on cart parent lines |
| `Rental_Sets_Archive_Guard` | DEPRECATED. No-op shim. Logic moved to `Rule_Archive_Context`. |
| `Rental_Sets_Product_Page_Prefill` | Emits `window.RentalSetsPrefill` on `wp_footer` |
| `Rental_Sets_Order_Meta` | Cart-item meta → order-item meta on checkout |
| `Rental_Sets_Payload_Extender` | Adds namespaced fields to inventories JSON via `rental_order_data_before_send` filter |

### 5.3 Validation

| Class | Role |
|---|---|
| `Rental_Sets_Validation_Result` | Immutable error bag with stable error codes |
| `Rental_Sets_Validator_Context` | Immutable input bag passed to every rule |
| `Rental_Sets_Validation_Rule` | Interface |
| `Rental_Sets_Validation_Rule_Base` | Abstract base with shared helpers (`group_label`, `selection_unit_count`) |
| `Rental_Sets_Rule_Archive_Context` | Block archive add when set needs configuration |
| `Rental_Sets_Rule_Max_Quantity` | `_rental_max_quantity` per-set check |
| `Rental_Sets_Rule_Set_Options` | Set-level options must be reviewed |
| `Rental_Sets_Rule_Selectable_Items` | Entity-2 + addon variants |
| `Rental_Sets_Rule_Group_Required` | Composite group `required=1` must have selection |
| `Rental_Sets_Rule_Group_Quantity` | Group min/max/multiple_selection |
| `Rental_Sets_Rule_Availability` | Inventory date-range check for chosen group children |
| `Rental_Sets_Cart_Validator` | Top-level orchestrator + persist-failed-selections |
| `Rental_Sets_Rules_Exporter` | Serialises rule data for `window.RentalSetsPrefill.rules` |

### 5.4 Renderer

| Class | Role |
|---|---|
| `Rental_Sets_Section_Presenter` | Walks `_rental_set_order`, builds unified ordered section list |
| `Rental_Sets_Renderer` | Server orchestrator; hooks `woocommerce_before_add_to_cart_form` priority 5 |

### 5.5 Display, webhook pipeline, pricing

| Class | Role |
|---|---|
| `Rental_Sets_Cart_Display` | Adds "Group" meta row on cart/mini-cart/checkout |
| `Rental_Sets_Order_Display` | Adds "Group" meta on thank-you, emails, my-account, admin order edit |
| `Rental_Sets_Webhook_Defense` | Defensive merge of entity-1/2 webhook-shape gaps (price/separate_price/required/note + addon cross-key matching) + entity-3 type normalisation (tinyints/nulls → int(0/1)/0/[]) + group UID format warning |
| `Rental_Sets_Webhook_Meta_Guard` | Scoped postmeta wipe for `set/create` on existing sets. Owns the 47-key legacy list and preserves modern entity-3 meta + `_rental_set_order` + `_rental_sets_layout_mode` + `_rental_item_based_total` + user/theme custom postmeta around the recreate |
| `Rental_Sets_Webhook_Item_Persister` | Entity 1+2 builder for the webhook path. Two batched SELECTs (no N+1), backfills `rental_*_id` / `parent_set_*` fields the receiver historically dropped, correctly resolves optional items by their own product id |
| `Rental_Sets_Webhook_Group_Persister` | Entity 3 builder for the webhook path. Writes `_rental_set_grouped_items` + `_rental_set_has_grouped_items` + `_rental_set_some_hidden_groups`. Three-state contract: missing key → no-op (downgrade safety); empty array → wipe; non-empty → resolve and write |
| `Rental_Sets_Webhook_Handler` | Orchestrator for `set/create` / `set/update` / `set/delete`. Drop-in replacement for the api.php inline functions; composes the four helpers above internally. Preserves the original HTTP responses and JSON shapes exactly |
| `Rental_Sets_Pricing` | `item_based_total` cart-line recalc with the composite-group de-dup rule |

---

## 6. Workflows

### 6.1 Initial sync (entity 1+2+3)

```
Admin clicks Sync
  → JS fires AJAX
  → Sets sync orchestrator (Rental_Sets_Processor)
  → Pulls sets from `inventory/sets` API including `grouped_items`
  → Per set:
     1. wp_insert_post() (or update) the WP product
     2. Postmeta written:
        - _rental_is_set = 1
        - _rental_set_items = [entity 1+2 array]
        - _rental_set_grouped_items = [entity 3 array]
        - _rental_set_has_grouped_items, _rental_max_quantity, etc.
     3. Categories, tags, up/cross-sells linked
     4. Set images attached (handled by file-sync module separately)
```

### 6.2 Webhook update (single set)

The full webhook pipeline, from Laravel ship → WP postmeta written. Four
helpers compose under one orchestrator (`Rental_Sets_Webhook_Handler`);
the api.php functions are thin `class_exists()`-gated wrappers.

```
Laravel admin saves a set
  → Webhook hits `/api/rentopian/set-update` on WP (or set/create, or set/delete)
  → api.php switch(action) → rentopian_set_update($set)
       ↓
  → class_exists('Rental_Sets_Webhook_Handler', false) ? yes
       ↓
  → Rental_Sets_Webhook_Handler::update($set)
       ↓ decode JSON payload (stripslashes + json_decode)
       ↓ resolve WP set id from rental_set_relations
       ↓ wp_update_post(title, content, excerpt)

  (1) Entity 1+2 build (Rental_Sets_Webhook_Item_Persister)
       1. Walk $set->items in one pass; collect every distinct
          (rental_id, division_id) pair across items + optional_items +
          addons + addon variants_optional.
       2. Run two batched SELECTs (product_relations + variant_relations)
          to resolve every Laravel id to its WP id. No N+1.
       3. Emit canonical 14-field-per-item shape matching the bulk-feed
          `Rental_Sets_Item_Builder`. Backfills `rental_product_id`,
          `rental_variant_id`, `parent_set_id` on items and
          `parent_set_*` on addons. Optional items resolve by their
          OWN product, not the parent's.

  (2) Postmeta writes
       update_post_meta($set_id, '_rental_set_items',                     $built['items']);
       update_post_meta($set_id, '_rental_set_items_default',             $built['items']);
       update_post_meta($set_id, '_rental_set_items_have_optional_items', $built['has_optional_items']);
       update_post_meta($set_id, '_rental_hide_items_on_website',         …);
       update_post_meta($set_id, '_rental_some_hidden_items',             $built['has_hidden_items']);
       update_post_meta($set_id, '_rental_max_quantity',                  …);

       Each call fires the `update_post_metadata` filter
            ↓
       Rental_Sets_Webhook_Defense::maybe_defend()
       For _rental_set_items (entity 1+2):
         - Match new items to prior by (product_id, variant_id).
         - Restore any of `price`, `separate_price`, `required`,
           `hide_on_website`, `note` that the webhook didn't ship
           but the prior bulk-feed value had.
         - For each item's addons[], match by `rental_inv_id || id`
           (cross-key); backfill `rental_inv_id` from `id` on the
           new value when missing.

  (3) Entity 3 build (Rental_Sets_Webhook_Group_Persister)
       1. Three-state contract:
            - grouped_items absent from payload → no-op (downgrade safety)
            - grouped_items = []                → wipe (admin removed all groups)
            - grouped_items non-empty           → resolve + write
       2. Same batched-SELECT pattern as (1).
       3. Writes:
            update_post_meta($set_id, '_rental_set_grouped_items',      $groups);
            update_post_meta($set_id, '_rental_set_has_grouped_items',  0|1);
            update_post_meta($set_id, '_rental_set_some_hidden_groups', 0|1);
       4. Each write triggers Webhook_Defense, which normalises types
          (tinyints → int(0|1), null bounds → 0, null items_order → []).
          Idempotent — no-op when types are already clean.

  (4) Categories, tags, up/cross-sells, polylang, cache
       (same as the legacy flow; the handler delegates to the
       existing global functions rental_create_categories,
       rental_create_set_tags, set_sets_up_sells,
       set_sets_cross_sells, rental_pll_assign_post,
       rental_translation_queue_post, rental_clear_cache)

  → respond_success(): http_response_code(200) + JSON body identical
    to the legacy response shape.

  → Final postmeta has full shape, normalised types, all three flags
    set, ready for the presenter / renderer to consume.
```

### 6.2.1 The `set/create` recreate-in-place branch

When a `set/create` webhook arrives for a set that ALREADY has a WP
post in `rental_set_relations`, the handler refreshes the existing
post rather than inserting a new one. The postmeta wipe before the
recreate is **scoped** so modern meta survives:

```
Rental_Sets_Webhook_Handler::create($set)
  → set_id already exists in rental_set_relations
  → wp_update_post(status=publish, title, content, excerpt)
  → DELETE term_relationships  (terms get rewritten by categories/tags)
  → Rental_Sets_Webhook_Meta_Guard::scoped_wipe_for_set_create($set_id)
       Deletes only the 47 legacy keys the bulk INSERT below will
       recreate. Modern entity-3 keys + `_rental_set_order` +
       `_rental_sets_layout_mode` + `_rental_item_based_total` +
       user/theme custom postmeta all survive.
  → Trash variations, wipe wc_product_meta_lookup row
  → ... continues into (1)–(4) above ...
```

### 6.2.2 Backward-compatibility gate

Every api.php wrapper has the same shape:

```php
function rentopian_set_update($set) {
    if ( class_exists( 'Rental_Sets_Webhook_Handler', false ) ) {
        Rental_Sets_Webhook_Handler::update( $set );
        return;
    }
    // … original inline implementation kept as fallback …
}
```

If the sets module fails to load for any reason — file permissions,
autoload race, plugin partial deactivation — the original inline code
runs unchanged. The 200 HTTP response from the legacy path is
identical in shape to the response from the new path.


### 6.3 Customer adds a set to cart (modern flow)

```
Customer on shop page → clicks Add to Cart
  → Rental_Sets_Cart_Validator::on_validate (priority 15)
     → Rule_Archive_Context detects: from archive + needs config
     → wc_add_notice + wp_safe_redirect to product permalink
     → Failed selections cached to session for re-prefill

Customer on product page (modern renderer):
  → Renderer outputs sections inside form.cart > .product-options-label
  → window.RentalSetsPrefill emitted on wp_footer:
       - rules { groups[], selectable_items[], error_codes }
       - cart_prefill (when matching cart line exists)
       - failed_selections (when previous submit failed)
  → JS hydrates form, applies prefill, drives section UIs

Customer fills form → clicks Add to Cart
  → POST contains:
     - product_id, quantity, add-to-cart
     - _rental_set_from_product_page = 1
     - _rental_edit_cart_key (when in edit mode)
     - rental_set_selections[<group_uid>][...]
     - rental_add_ons[i][...] (legacy shape, includes item_type=grouped_child)

Validation pipeline runs:
  → Cart_Validator::on_validate (priority 15)
     → Each rule runs in order; collects errors
     → On failure:
          • notice raised
          • selections persisted to session
          • returns false → WC aborts add
     → On success: clears persisted selections, returns passed

WC commits add-to-cart:
  → woocommerce_add_cart_item_data filters:
     - Cart_Handler (priority 20): tags as group child
     - Cart_Merger (priority 30): stashes set options, tags parent marker
  → woocommerce_add_to_cart actions:
     - Cart_Merger (priority 99): collapses parent duplicates
     - Cart_Edit_Mode (priority 100): replaces old line if editing
```

### 6.4 Cart display

```
Customer visits cart page:
  → WC iterates cart_contents
  → For each line, woocommerce_get_item_data filter fires:
     - Cart_Display: adds "Group: <name>" row on group children
  → For each line, woocommerce_cart_item_name filter fires:
     - Cart_Edit_Link: adds "Edit" link on parent set lines (not children)
```

### 6.5 Pricing recalc

> **Full spec: [PRICE-RULES.md](PRICE-RULES.md).** Read that for the
> display-vs-calculation rules, the three display cases, and the single
> source of truth. Summary below.

```
Cart subtotal recalculation:
  → woocommerce_before_calculate_totals fires
  → Rental_Sets_Pricing::recalculate (parent lines only)
     → parent per-unit price = _regular_price × rental_get_days()
       (ALWAYS — both pricing models; WC multiplies by parent_qty)

  Children are priced independently on their own cart lines via the
  legacy rental_calculate_cart_item_price + calculate_cart_totals,
  both reading the single source of truth:

  → rental_set_child_effective_unit_price($cart_item):
        CASE 1  item_based_total = true            → real per-unit price
        CASE 2  item_based_total = false + variant → real per-unit price
        CASE 3  item_based_total = false + fixed   → 0 (informational)
     → child line total = effective_unit × duration × child_qty

  set_total = parent line total + Σ child line totals
            (aggregated_children)
  set_total = parent line total only
            (fixed_bundle_price — children all CASE 3 = 0)
```

Quantity propagation (parent qty change ⇒ children scale by
`parent_qty × per_set_qty`, absolute math) and the removal cascade are
specified in [WORKFLOWS.md §11](WORKFLOWS.md).

### 6.6 Edit mode

```
Customer on cart page → clicks "Edit" on set line
  → Cart_Edit_Link rendered <a href=".../?rental_edit_cart=KEY">
  → Customer lands on product page with query var
  → Cart_Edit_Mode::detect_edit_mode (init priority 5)
     → Stashes editing_key
  → Product_Page_Prefill::emit_prefill (wp_footer priority 5)
     → cart_prefill { is_edit_mode: true, ... }
  → Renderer's outer wrapper template:
     <input type="hidden" name="_rental_edit_cart_key" value="KEY">

Customer changes selections → submits
  → Cart_Edit_Mode::detect_edit_mode reads POST field
     → Rental_Sets_Cart_Merger::set_skip_for_edit_static(true)
  → Cart_Validator runs as usual
  → WC commits new parent line (no merge happens — merger stood down)
  → woocommerce_add_to_cart action fires
     → Cart_Edit_Mode::finalize_replacement (priority 100):
        - Removes all children pointing at OLD key
        - Removes the OLD parent line
     → Cart now has only the new line
```

### 6.7 Order submission to Laravel

```
Customer completes checkout:
  → Rental_Sets_Order_Meta::on_checkout_create_order_line_item
     → Mirrors cart-item rental_set_group_* keys to order-item meta
       with underscore prefix (hidden from default WC display)

Existing rental_create_order/rental_send_order runs:
  → Builds inventories JSON from cart
  → Filter rental_order_data_before_send fires (priority 50)
     → Rental_Sets_Payload_Extender::on_data_before_send
        - Decodes inventories JSON
        - Indexes cart by (rental_set_id, inv_id)
        - For each set-tagged line: adds division_id + is_set_group_item (0|1)
        - For each line whose cart entry is a group child:
             enriches line with un-prefixed set_group_* fields
        - Re-encodes and writes back to $data['inventories']
  → curl POST to {laravel}/orders/add
```

---

## 7. Validation rules reference

Each rule is a self-contained class running through `Rental_Sets_Cart_Validator::run_rules`. Run order is fixed in `build_rules()`:

| # | Rule | Trigger | Error code |
|---|---|---|---|
| 1 | `Rule_Archive_Context` | Add-to-cart from non-product page on a set needing config | `archive_needs_config` |
| 2 | `Rule_Max_Quantity` | Submitted qty > `_rental_max_quantity` | `max_quantity` |
| 3 | `Rule_Set_Options` | Set has options but session has no picks | `set_options_not_picked` |
| 4 | `Rule_Selectable_Items` | `optional_items` has >1 option, no pick submitted | `item_optional_not_picked` |
| 5 | `Rule_Selectable_Items` | Addon with `variants_optional`, no pick | `addon_variant_not_picked` |
| 6 | `Rule_Group_Required` | Composite group `required=1`, no selection | `group_required` |
| 7 | `Rule_Group_Quantity` | `multiple_selection=0` with >1 child picked | `group_multiple_selection` |
| 8 | `Rule_Group_Quantity` | Σ(child_qty) < quantity_min | `group_min_qty` |
| 9 | `Rule_Group_Quantity` | Σ(child_qty) > quantity_max | `group_max_qty` |
| 10 | `Rule_Availability` | Inventory headroom < required for date range | `availability` |

Rules can be added/removed via `rental_sets_validation_rules` filter.

---

## 8. POST shape reference

When the modern renderer's form submits, `$_POST` contains:

```php
$_POST = [
    'product_id'  => 5213,
    'quantity'    => 2,
    'add-to-cart' => 5213,

    // Tells Rule_Archive_Context the request came from the configurator,
    // so it doesn't treat this as an archive add.
    '_rental_set_from_product_page' => '1',

    // Optional — when the customer entered via "Edit" on a cart line.
    '_rental_edit_cart_key' => 'abc123...',

    // Modern shape consumed by the validator.
    'rental_set_selections' => [
        '<group_uid>' => [
            'group_id' => <int>,
            'selections' => [
                '<item_uid>' => [
                    'inv_id'     => <int>,
                    'product_id' => <int>,
                    'variant_id' => <int>,
                    'quantity'   => <int>,
                    'price'      => <number>,
                ],
                ...
            ],
        ],
        ...
    ],

    // Legacy shape consumed by rental_add_product_to_cart. The renderer
    // emits both shapes simultaneously for backward compatibility.
    // For group children, item_type = 'grouped_child' triggers Cart_Handler
    // to tag the cart line.
    'rental_add_ons' => [
        [
            'product_id' => <int>,
            'variant_id' => <int>,
            'inv_id'     => <int>,
            'quantity'   => <int>,
            'required'   => 0|1,
            'item_type'  => 'grouped_child',
            'rental_set_group_id'                 => <int>,
            'rental_set_group_uid'                => '<group_uid>',
            'rental_set_group_item_uid'           => '<item_uid>',
            'rental_set_group_required'           => 0|1,
            'rental_set_group_multiple_selection' => 0|1,
            'rental_set_group_qty'                => <int>,
            'rental_set_group_name'               => '<display name>',
            'rental_set_group_price'              => <number>,
        ],
        ...
    ],
];
```

The two synthetic UID conventions:

- **Composite group**: `{division_id}-grp-{22ch base64url}` (server-generated, deterministic).
- **Selectable item synthetic group**: `sel-{rental_set_id}-{wp_product_id}` (renderer convention; not server-side).
- **Addon synthetic group**: `addon-{parent_product_id}-{addon_product_id}`.

---

## 9. WP→Core payload shape

The `inventories` JSON sent to `orders/add` contains, for every line that originated from a composite group, the following extra fields alongside the standard ones:

```php
[
    "division_id"                  => <int>,
    "is_set_group_item"            => 1,
    "set_group_id"                 => <int>,
    "set_group_uid"                => "<group_uid>",
    "set_group_item_uid"           => "<item_uid>",
    "set_group_price"              => <number|null>,
    "set_group_qty"                => <int>,
    "set_group_required"           => 0|1,
    "set_group_multiple_selection" => 0|1,
    "set_group_name"               => "Chairs",
]
```

Cart-item meta keeps the `rental_set_group_*` prefix internally; the prefix is dropped only at the send boundary. Set-tagged lines that are **not** group children carry only `division_id` + `is_set_group_item => 0`; lines outside any set produce byte-identical payloads to the legacy classic shape.

The two filters `rental_sets_modern_payload_inventory_line` and `rental_sets_modern_payload_set_total` allow last-mile renaming when the Laravel core team finalises the contract.

---

## 10. Filters

| Filter | Where | Args | Purpose |
|---|---|---|---|
| `rental_sets_modern_cart_item_data` | `Cart_Meta::build_from_input` | `($out, $source)` | Mutate group meta on cart tag |
| `rental_sets_modern_payload_inventory_line` | `Payload_Extender` | `($addition, $line, $cart_item, $order_id)` | Per-line rename/restructure for core API |
| `rental_sets_modern_payload_set_total` | `Payload_Extender` | `($data, $inventories, $order_id)` | Whole-payload mutation |
| `rental_sets_validation_rules` | `Cart_Validator::run_rules` | `($rules, $ctx)` | Add/remove/replace rules |
| `rental_sets_needs_configuration` | `Rule_Archive_Context` | `($needed, $set_id, $reason)` | Override "needs config" detection |
| `rental_sets_rules_exported` | `Rules_Exporter::build_for_set` | `($payload, $set_id)` | Mutate JS-bound rule data |
| `rental_sets_prefill_data` | `Product_Page_Prefill::emit_prefill` | `($payload, $set_id)` | Mutate prefill before serialise |
| `rental_sets_modern_sections` | `Renderer::build_sections` | `($sections, $set_id)` | Mutate section list |
| `rental_sets_webhook_defense_enabled` | `Webhook_Defense::maybe_defend` | `($enabled, $object_id, $meta_key)` | Disable shape defense per-set |
| `rental_sets_webhook_defense_item_fields` | `Webhook_Defense::boot` | `($fields)` | Modify the entity-1/2 set-item preserve list |
| `rental_sets_webhook_defense_addon_fields` | `Webhook_Defense::boot` | `($fields)` | Modify the addon preserve list |
| `rental_sets_webhook_defense_group_fields` | `Webhook_Defense::boot` | `($fields)` | Modify the entity-3 group preserve list (forward-compat) |
| `rental_sets_webhook_defense_group_item_fields` | `Webhook_Defense::boot` | `($fields)` | Modify the entity-3 group-item preserve list |
| `rental_sets_webhook_meta_guard_enabled` | `Webhook_Meta_Guard::scoped_wipe_for_set_create` | `($enabled, $wp_set_id)` | Disable the scoped postmeta wipe per-set (e.g. inspection builds) |
| `rental_sets_legacy_meta_keys_for_set_create` | `Webhook_Meta_Guard::legacy_set_create_keys` | `($keys)` | Extend the list of legacy postmeta keys wiped on `set/create` — useful for theme-side keys |
| `rental_sets_webhook_group_persister_enabled` | `Webhook_Group_Persister::persist` | `($enabled, $wp_set_id, $set_payload)` | Disable entity-3 webhook persistence per-set (kill-switch) |

---

## 11. Hook priority order

| Priority | Hook | Class | What |
|---|---|---|---|
| 5 | `plugins_loaded` | Webhook_Defense | Hooks `update_post_metadata` early so webhooks see the defense |
| 5 | `init` | Cart_Edit_Mode | Detects edit query var |
| 5 | `woocommerce_before_add_to_cart_form` | Renderer | Outputs sections inside form.cart |
| 5 | `wp_footer` | Product_Page_Prefill | Emits `window.RentalSetsPrefill` |
| 15 | `woocommerce_add_to_cart_validation` | Cart_Validator | Validates after legacy validator (priority 10) |
| 20 | `init` | All other modern classes | Register WC hooks |
| 20 | `woocommerce_add_cart_item_data` | Cart_Handler | Tags group-child cart-item-data |
| 20 | `woocommerce_get_cart_item_from_session` | Cart_Handler | Re-applies meta after session restore |
| 20 | `woocommerce_get_item_data` | Cart_Display | Adds "Group" row to cart display |
| 20 | `woocommerce_cart_item_name` | Cart_Edit_Link | Adds "Edit" link |
| 20 | `woocommerce_checkout_create_order_line_item` | Order_Meta | Cart→order meta copy |
| 20 | `woocommerce_order_item_meta_end` | Order_Display | Customer-facing "Group" meta |
| 20 | `woocommerce_after_order_itemmeta` | Order_Display | Admin order edit "Group" meta |
| 20 | `woocommerce_hidden_order_itemmeta` | Order_Display | Hide internal underscore-prefixed keys |
| 20 | `woocommerce_before_calculate_totals` | Pricing | Recalc parent line price |
| 30 | `woocommerce_add_cart_item_data` | Cart_Merger | Stash set options, tag parent marker |
| 50 | `rental_order_data_before_send` | Payload_Extender | Inject namespaced fields |
| 99 | `woocommerce_add_to_cart` | Cart_Merger | Collapse parent duplicates |
| 100 | `woocommerce_add_to_cart` | Cart_Edit_Mode | Replace old line in edit mode |

---

## 12. Settings

| Option | Type | Default | Description |
|---|---|---|---|
| `rental_sets_layout_mode` | string | `'modern'` | Per-site layout mode |
| `rental_sets_hide_variant_product_name` | bool | 1 | Drop the repeated product name from variant choices (modern only) |
| `rental_set_listing_style` | string | (varies) | Classic-mode table style |
| `rental_set_components_text` | string | (empty) | Header label for classic |
| `rental_hide_set_items` | bool | 0 | Hide entire classic items table |

The admin panel shows two radio rows: **Layout Mode** (classic / modern) and **Component Listing Style** (only used by classic).

Modern-only settings live in the `#rntp-sets-modern-options` wrapper, which `admin-settings.js` (`bindSetsLayoutModeVisibility`) shows and hides with the Layout Mode radio. The fields still submit while hidden, so a stored preference survives a round-trip through classic. `Rental_Sets_Admin_Settings::hides_variant_product_name()` gates the effect on modern being active as well.

**Hide Repeated Product Name.** Variant labels read `{product} - {attributes}`. With the setting on, `Section_Presenter::apply_variant_label_display()` shortens a choice to its attributes only where the product name is redundant — every choice in the list shares it, or it repeats the heading above the list. A group mixing several products keeps full labels, and a choice with no attribute part is never rewritten, so a label can never come out empty. Each item also carries `name_full` (never shortened) for tooltips and `aria-label`s. Section titles, the cart, checkout and the core payload are untouched.

---

## 13. Backward compatibility

The modern feature adds **zero edits** to existing plugin code. All wiring is filter-based. Classic-mode behaviour is byte-identical to before:

- A non-set product → all hooks self-skip.
- A classic set in classic mode → legacy `rental_set_products_list` renders; modern renderer self-skips via `Rental_Sets_Renderer::will_render() === false`.
- A classic set in modern mode → modern renders; legacy `rental_set_products_list` self-skips via the integration patch.
- A composite-group set in classic mode → renders without the entity-3 sections (groups invisible).
- A composite-group set in modern mode → all three entity types render.

`Rental_Sets_Admin_Settings::apply_release_defaults()` selects modern and enables `rental_sets_hide_variant_product_name`. It is driven by the plugin-wide upgrade flow, not by a hook in the Sets bootstrap:

- `rental_plugin_activate()` in `rentopian-sync.php` calls it on activation and on a fresh install.
- `rental_run_upgrade_steps()` in `plugin_updater.php` calls it on every request, admin or front-end.

It is deliberately not gated on a version comparison. The call already carries its own one-time flag, so a version gate would only add a way to miss it — a site whose stored `rental_sync_installed_version` already matched the build it was running would skip it forever. Whatever version a site arrives from or updates to, the step lands.

`upgrader_process_complete` cannot drive this either: WordPress fires that hook inside the build being replaced, so the outgoing release would have to already contain the incoming release's steps.

It runs one time per site and is guarded by the `rental_sets_release_defaults_applied` option. A re-activation or a newer plugin version never re-applies it, so an admin who selects classic afterwards keeps that choice permanently.

An unset, empty or unrecognised `rental_sets_layout_mode` still resolves to `'classic'`. The modern classes remain loaded either way, and their hooks self-skip while classic is active.

---

## 14. Theme overrides

Themes can override any partial by placing a file at:

```
<active-theme>/rentopian-sync/sets/single-product-modern.php
<active-theme>/rentopian-sync/sets/partials/section-fixed-item.php
<active-theme>/rentopian-sync/sets/partials/section-dropdown.php
<active-theme>/rentopian-sync/sets/partials/section-dropdown-qty.php
<active-theme>/rentopian-sync/sets/partials/section-multi-select.php
```

`Rental_Sets_Renderer::locate_template()` checks `locate_template()` first, falls back to the module's bundled view.

CSS retheming via `:root` variables — see `assets/css/rental-sets-modern.css` for the full list (`--rntp-accent`, `--rntp-radius`, etc.).

---

## 15. Integration steps

When upgrading an existing install to the modern feature:

1. Drop the new files into `includes/sets/` (and `includes/sets/validation/`, `includes/sets/views/`, `includes/sets/assets/`).
2. Replace `bootstrap.php` and `compat-functions.php`.
3. Add the **one-line guard** at the top of `rental_set_products_list()` in `rentopian-sync.php`:

   ```php
   if ( class_exists( 'Rental_Sets_Renderer', false )
       && Rental_Sets_Renderer::will_render() ) {
       return;
   }
   ```

4. Prepend the **six-line `class_exists` wrapper** to each of the three webhook handlers in `api.php` — `rentopian_set_create`, `rentopian_set_update`, `rentopian_set_delete`. The original inline implementations stay as the fallback `else` branch. See `CONSOLIDATED-PATCH.md` Part C for the exact text. Net effect after these wrappers are in place:

   - `_rental_set_grouped_items` is now written on every webhook (was never written before).
   - The `set/create` postmeta wipe is scoped — modern entity-3 meta + `_rental_set_order` + `_rental_sets_layout_mode` + `_rental_item_based_total` + user/theme custom postmeta survive.
   - The entity 1+2 builder uses two batched SELECTs (no N+1), produces the canonical 14-field-per-item shape, and correctly resolves optional items by their own product id.

5. Toggle the layout mode via Settings → Sets → Layout Mode if needed (modern is selected once on install/activation/first admin load; a later switch to classic is permanent).
6. Run a full sync to populate `_rental_set_grouped_items` postmeta on existing sets that pre-date the rebuild. (Sets edited via the Laravel admin UI after the api.php wrappers are in place will populate `_rental_set_grouped_items` on their first webhook.)

---

## 16. Documentation maintenance

- When adding a **new section type** to the renderer, update `Section_Presenter::sections()`, the partial, and section 5.4 / 6.3.
- When adding a **new validation rule**, add the class under `validation/`, register it in `Cart_Validator::build_rules()`, document it in section 7, and add the corresponding error code to `Rules_Exporter::error_codes_dictionary()`.
- When changing the **WP→Core payload shape**, update section 9 and the namespacing rationale in section 5.2.
- When adding a **filter**, document it in section 10.
- When changing **hook priority**, update section 11.
