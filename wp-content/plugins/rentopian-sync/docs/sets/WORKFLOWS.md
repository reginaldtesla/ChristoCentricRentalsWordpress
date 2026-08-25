# Sets Module — Workflows

Visual flow summaries for the sets subsystem. For full context, see [README.md](README.md).

---

## 1. System initialization (load order, modern feature only)

```
Plugin load
    → rentopian-sync.php
    → require class-process-timer.php
    → require class-logger-with-timer.php
    → require sets/bootstrap.php
        → Load all sync-side classes (entity 1+2)
        → Load all modern-feature classes (cart, validation, renderer, display, defense, pricing)
        → Load all webhook helpers (Meta_Guard, Item_Persister, Group_Persister, Handler)
        → require compat-functions.php

plugins_loaded (priority 5):
    Rental_Sets_Webhook_Defense::register()

init (priority 5):
    Rental_Sets_Cart_Edit_Mode::register()

init (priority 20):
    Cart_Handler, Cart_Merger, Cart_Edit_Link,
    Cart_Validator, Product_Page_Prefill,
    Order_Meta, Payload_Extender, Renderer,
    Cart_Display, Order_Display, Pricing

(loaded but not hooked — invoked directly from api.php):
    Rental_Sets_Webhook_Meta_Guard          ← scoped postmeta wipe
    Rental_Sets_Webhook_Item_Persister      ← entity 1+2 builder
    Rental_Sets_Webhook_Group_Persister     ← entity 3 builder
    Rental_Sets_Webhook_Handler             ← orchestrator (composes the above)
```

---

## 2. Initial sync (one set with composite groups)

```
Admin clicks Sync
    → AJAX → Rental_Sets_Processor::process()
        → API call: GET /api/sets/get-for-api-raw
            ← response includes set rows with `grouped_items` field
        → Per set:
            → Rental_Sets_Item_Builder builds entity-1+2 array
            → Rental_Sets_SQL_Builder writes:
                _rental_is_set = 1
                _rental_set_items = [...]
                _rental_set_grouped_items = [...]
                _rental_set_has_grouped_items = 1
                _rental_set_some_hidden_groups = 0/1
                _rental_set_order = JSON [uid, uid, ...]
            → Categories, tags, up/cross-sells linked
        → File-sync handles images separately
```

---

## 3. Webhook update / create (unified pipeline)

```
Laravel admin saves a set
    → POST /api/rentopian/set-update  (or set/create, or set/delete)
    → api.php: rentopian_set_update($set)
        → class_exists( 'Rental_Sets_Webhook_Handler', false ) ? YES
        → Rental_Sets_Webhook_Handler::update($set)

Rental_Sets_Webhook_Handler::update($set)
    1. Decode payload (stripslashes + json_decode → stdClass)
    2. Resolve WP set id from rental_set_relations (404 if not found)
    3. wp_update_post(title, content, excerpt)
    4. update_post_meta calls for sku / regular_price / job_cost /
       price_multiplier_id / tax_status / price / exempt_waiver / is_sale
    5. UPDATE wc_product_meta_lookup row
    6. UPDATE rental_set_relations.rental_division_id (appended to $sql)
    7. wc_delete_product_transients

    8. Image processing:
       process_images($set, $set_id)
         → For each image: SELECT id FROM rental_image_relations WHERE rental_id = …
         → For unknown ones: rental_curl('files/images/stream', …)
         → For each downloaded: rentopian_webhook_download_image(...)
         → Return { thumbnail_id, gallery, relations_rows }
       update_post_meta thumbnail + gallery

    9. Entity 1+2 build:
       Rental_Sets_Webhook_Item_Persister::build($set_id, $set)
         → Collect every distinct (rental_id, division_id) pair from
           items + optional_items + addons + addon variants_optional
         → Two batched SELECTs (product_relations + variant_relations)
         → Build 14-field-per-item canonical shape
         → Return { items, has_optional_items, has_hidden_items }
       update_post_meta for _rental_set_items + _items_default +
         _have_optional_items + _hide_items_on_website +
         _some_hidden_items + _max_quantity
           ↓ each call fires update_post_metadata filter
           ↓
       Rental_Sets_Webhook_Defense::maybe_defend()
         For _rental_set_items:
           - Match new items to prior by (product_id, variant_id)
           - Restore price/separate_price/required/hide_on_website/note
             from prior when missing on new
           - For each item's addons[], cross-match `rental_inv_id || id`
             and backfill `rental_inv_id` from `id`
         If shape unchanged: let WP proceed
         Else: write merged value, short-circuit

   10. Append rental_image_relations INSERT to $sql; dbDelta($sql)

   11. Categories: rental_create_categories($set->categories, $set_id)
   12. Tags:       rental_create_set_tags($set->tags, $set_id, $product_tags)
   13. Up-sells / cross-sells:
       set_sets_up_sells($set_id, $up_sells_sets, $up_sells_products)
       set_sets_cross_sells($set_id, $cross_sells_sets, $cross_sells_products)

   14. Entity 3 build:
       Rental_Sets_Webhook_Group_Persister::persist($set_id, $set)
         Three-state contract:
           - $set->grouped_items absent       → no-op (downgrade safety)
           - $set->grouped_items === []        → write empty + flags=0 (admin removed groups)
           - $set->grouped_items non-empty     → resolve + write
         When non-empty:
           - Collect every (rental_id, division_id) pair from group items
           - Two batched SELECTs to resolve to WP ids
           - Skip rule: items resolving to neither product nor variant are dropped
           - Build canonical Group_Builder-equivalent shape
           - Write three postmeta keys:
               _rental_set_grouped_items       (resolved groups array)
               _rental_set_has_grouped_items   (0|1 flag)
               _rental_set_some_hidden_groups  (0|1 flag)
             ↓ Webhook_Defense fires on each write
             ↓ - normalises tinyints → int(0|1)
             ↓ - normalises null bounds → 0
             ↓ - normalises null/string items_order → []
             ↓ - validates group UID shape (informational warning only)

   15. rental_clear_cache()
   16. rental_pll_assign_post($set_id) + rental_translation_queue_post($set_id)

   17. respond_success(): http_response_code(200) + JSON body matching
       the legacy response shape exactly.

If class_exists('Rental_Sets_Webhook_Handler') === false at step 0:
    Falls through to the original inline api.php implementation
    (kept verbatim as backward-compat fallback).
```

### 3.1 set/create — recreate-in-place sub-flow

```
Rental_Sets_Webhook_Handler::create($set)
    → set_id already in rental_set_relations?
        YES (recreate-in-place):
            wp_update_post(status=publish, title, content, excerpt)
            DELETE FROM term_relationships WHERE object_id = $set_id

            Rental_Sets_Webhook_Meta_Guard::scoped_wipe_for_set_create($set_id)
              → Reads legacy_set_create_keys() — the 47 keys api.php's
                bulk INSERT will recreate.
              → DELETE FROM postmeta WHERE post_id = … AND meta_key IN (…)
              → Modern entity-3 keys + _rental_set_order +
                _rental_sets_layout_mode + _rental_item_based_total +
                user/theme custom postmeta all SURVIVE.

            UPDATE posts SET post_status='trash' WHERE post_parent = $set_id
            DELETE FROM wc_product_meta_lookup WHERE product_id = $set_id
            UPDATE rental_set_relations.rental_division_id  (appended to $sql)
            wc_delete_product_transients

        NO (fresh insert):
            wp_insert_post([...])  → $set_id
            INSERT INTO rental_set_relations VALUES (...)  (appended to $sql)

    → Continue into the image / item / postmeta / groups flow above.
    → Plus bulk INSERT of 47 legacy postmeta rows
      (the data the scoped wipe just cleared).
```

### 3.2 set/delete — minimal flow

```
Rental_Sets_Webhook_Handler::delete($set)
    1. Resolve set_id from rental_set_relations (404 if not found)
    2. wp_update_post(['ID' => $set_id, 'post_status' => 'trash'])
    3. rental_clear_cache()
    4. respond_success() — 200 + JSON
```

---

## 4. Customer add-to-cart from product page

```
Customer on set product page (modern mode)
    → Renderer outputs:
        form.cart
            ↓ (above .product-options-label)
        .rental-sets-modern
            ↓ Section 1, 2, 3...
            ↓ Hidden inputs container (added by JS)

    → wp_footer:
        Product_Page_Prefill emits:
            window.RentalSetsPrefill = {
                set_id, cart_prefill, rules, failed_selections
            }

    → JS boots:
        Wrapper iterates .rntp-section
        Each section gets a controller (FixedSection / DropdownSection /
            DropdownQtySection / MultiSection)
        Apply prefill (failed_selections wins, else cart_prefill)
        First sync: collect all selections, run client validation,
            write hidden inputs

    → Customer changes a selection:
        Section.fire() → Wrapper.sync():
            1. Collect from each section
            2. Run client validation mirror (group required, min/max,
               multiple_selection)
            3. Show inline feedback / clear inline feedback
            4. Show top-level error rollup / hide
            5. Write hidden inputs:
                rental_set_selections[<uid>][group_id]
                rental_set_selections[<uid>][selections][<item_uid>][...]
                rental_add_ons[<i>][...] (legacy shape)

    → Customer hits Add to Cart:
        POST submitted
            → woocommerce_add_to_cart_validation (priority 10):
                legacy rental_validate_cart_item runs (classic concerns)
            → woocommerce_add_to_cart_validation (priority 15):
                Rental_Sets_Cart_Validator::on_validate
                    → Build context from $_POST
                    → Run rules in order:
                        Rule_Archive_Context: skip (from product page)
                        Rule_Max_Quantity: pass
                        Rule_Set_Options: pass
                        Rule_Selectable_Items: pass
                        Rule_Group_Required: pass
                        Rule_Group_Quantity: pass
                        Rule_Availability: query rental_check_availability()
                    → If errors:
                        notice_all() + persist_selections_for_reprefill()
                        return false (WC aborts)
                    → If pass:
                        clear_persisted_selections()
                        return true
            → WC commits add:
                woocommerce_add_cart_item_data (priority 20):
                    Cart_Handler tags as group child
                woocommerce_add_cart_item_data (priority 30):
                    Cart_Merger stashes options, tags parent marker
                WC computes cart_item_key (hash includes group meta keys
                    on children, tagged parent marker on parent)
            → woocommerce_add_to_cart (action):
                Cart_Merger (priority 99): collapse parent duplicates
                Cart_Edit_Mode (priority 100): replace old line if editing
```

---

## 5. Failed validation → re-prefill

```
Customer submits → validation fails (e.g. group_min_qty)
    → Cart_Validator:
        result.add_error(ERR_GROUP_MIN_QTY, ...)
        notice_all() → wc_add_notice
        persist_selections_for_reprefill():
            set_rental_session_data(
                'rental_sets_failed_selections_5213',
                { modern_selections: $_POST['rental_set_selections'], ... }
            )
        return false → WC aborts add

    Customer redirected back to product page (or stays on AJAX)
    → wp_footer:
        Product_Page_Prefill::emit_prefill
            → Cart_Validator::instance()->read_persisted_selections($set_id)
            → window.RentalSetsPrefill.failed_selections = { ... }

    JS boots:
        applyPrefill() prefers failed_selections over cart_prefill
        Form re-populated with what customer just typed

    Customer fixes the issue and resubmits → validation passes →
        clear_persisted_selections() wipes the cache
```

---

## 6. Cart-page edit flow

```
Customer on cart page:
    Cart_Edit_Link adds <a href=".../?rental_edit_cart=KEY">Edit</a>
        next to each set parent line

    Customer clicks Edit
        → Lands on product page with query var
        → init priority 5: Cart_Edit_Mode::detect_edit_mode reads $_GET
            → Stashes editing_key
        → Renderer's outer wrapper template:
            <input type="hidden" name="_rental_edit_cart_key" value="KEY">
            <div class="rental-sets-modern" data-edit-mode="1">
        → Product_Page_Prefill builds payload with cart_prefill from
            the matching cart line
        → JS shows green-tinted edit-mode banner via CSS

    Customer changes selections → submits
        → init priority 5: detect_edit_mode reads $_POST field
            → Confirms cart line still exists
            → Rental_Sets_Cart_Merger::set_skip_for_edit_static(true)
        → Cart_Validator runs (skips if invalid)
        → WC commits new parent + new children
        → woocommerce_add_to_cart (priority 100):
            Cart_Edit_Mode::finalize_replacement
                → Removes children pointing at OLD parent_key
                → Removes OLD parent line
                → Logs replacement
                → Resets editing_key, signals merger to resume

Stale edit key (cart line removed since URL was generated):
    → detect_edit_mode validates against cart_contents
    → Falls back to default merge behavior with logged warning
```

---

## 7. Order placement → core API

```
Customer completes checkout:
    → woocommerce_checkout_create_order_line_item (priority 20):
        Order_Meta::on_checkout_create_order_line_item
            → For each cart item that's a group child:
                $item->add_meta_data('_rental_set_group_id', $val, true)
                ... etc for all KEYS_*

    → existing rental_create_order/rental_send_order builds payload:
        $data['inventories'] = json_encode([
            // entity 1+2 lines (legacy shape)
            // entity 3 children (legacy shape, missing namespaced fields)
        ])

    → filter rental_order_data_before_send (priority 50):
        Payload_Extender::on_data_before_send
            1. Decode inventories JSON
            2. Index cart by (rental_set_id, inv_id)
            3. For each line:
                - Look up cart entry by index key
                - Build addition:
                    division_id
                    is_set_group_item: 1 if group child, else 0
                - If cart entry is_group_child, extend addition:
                    set_group_id, _uid, _item_uid
                    set_group_price, _qty
                    set_group_required, _multiple_selection, _name
                - apply_filters('rental_sets_modern_payload_inventory_line')
                - merge addition into line
            4. Re-encode JSON
            5. apply_filters('rental_sets_modern_payload_set_total')

    → existing curl POST to {laravel}/orders/add
```

---

## 8. Cart pricing recalculation

```
WC fires woocommerce_before_calculate_totals
    → Pricing::recalculate (priority 20)
        1. Re-entry guard check
        2. Index cart_contents:
            children_by_parent[parent_key] = [child, child, ...]
        3. For each parent line that's a set:
            product_id = parent.product_id
            children = children_by_parent[parent_key]
            price = compute_parent_price(product_id, parent, children)
                → Read _rental_item_based_total
                → If item_based_total = 0:
                    return _regular_price postmeta
                → If item_based_total = 1:
                    return sum_item_based(product_id, children)
                        → For each child:
                            If is_group_child:
                                # group_quantity is NOT a billing multiplier —
                                # it is the product-page qty-stepper default and
                                # order/quote metadata. A group child bills by its
                                # own picked units, like any other child.
                                term = per_unit_price * resolve_per_set_quantity(...)
                            Else (classic child):
                                term = per_unit_price * resolve_per_set_quantity(...)
                            total += term
                        → return total
            $item['data']->set_price($price)
        4. Reset re-entry guard

WC then computes line_total = price * parent_qty for each set parent.
```

---

## 9. Cart / mini-cart / checkout / email / admin display

```
Cart-side surfaces (cart page, mini-cart, checkout review):
    woocommerce_get_item_data filter (priority 20):
        Cart_Display::add_group_meta
            → For each cart_item that is_group_child:
                Append [{ key: 'Group', value: <name>, display: <name> }]
            → Native WC styling renders as labeled meta row

Order-side surfaces (thank-you, my-account, emails, admin):
    woocommerce_order_item_meta_end action (priority 20):
        Order_Display::render_customer_meta
            → For each order item that is_group_child:
                if plain_text email: \nGroup: <name>
                else: <small class="rental-set-group-meta">Group: <name></small>

    woocommerce_after_order_itemmeta action (priority 20):
        Order_Display::render_admin_meta
            → <p class="rental-set-group-meta-admin">
                <strong>Group:</strong> <name>
              </p>

    woocommerce_hidden_order_itemmeta filter (priority 20):
        Order_Display::hide_internal_meta
            → Hides _rental_set_group_* keys from default WC meta box
```

---

## 10. Group merge semantics (current behaviour — Option-2 with reconciliation)

```
Customer adds set X with selection {Chairs:Gray×3, Tent:Std×1}
    → WC commits parent line A (qty=1) + child Gray@A (qty=3) + child Std@A (qty=1)

Customer adds set X with selection {Chairs:Gray×2, Lights:Bistro×4}
    → WC adds new parent line B (different options stash → different cart_item_key)
    → priority 10: rental_add_product_to_cart adds children pointing at B
        (Gray@B qty=2, Bistro@B qty=4)
    → priority 99: Cart_Merger.collapse_parent_duplicates
        Survivor = A. Parent qty: A.qty + B.qty = 2.
        Children of B reparented to A (rental_add_on_of: B → A).
        Parent line B dropped.
    → priority 105: Cart_Reconciler.on_add_to_cart
        Authoritative target = $_POST['rental_add_ons'] identities
            = {grp:Chairs:Gray, grp:Chairs:Bistro}.
        Children of A: Gray@A (qty=3), Std@A (qty=1), Gray@A_new (qty=2), Bistro@A (qty=4).
        Pass 1 — index by IDENTITY (group → grp:uid:iid, else pv:pid:vid):
            grp:Chairs:Gray   → [Gray@A, Gray@A_new]
            grp:Tent:Std      → [Std@A]
            grp:Chairs:Bistro → [Bistro@A]
        Pass 2 — reconcile (NO summing):
            grp:Chairs:Gray    in submission, 2 lines → keep first, drop Gray@A_new (dedup)
            grp:Tent:Std       NOT in submission → drop Std@A (stale)
            grp:Chairs:Bistro  in submission, 1 line → keep Bistro@A
    → then normalize_children_qty(A): parent_qty=2 ⇒ every child = 2 × per_set_qty.

Final cart:
    Set X parent (qty=2)
        Gray child (qty=2 × per_set)   — deduped, then normalized to parent qty
        Bistro child (qty=2 × per_set) — kept, then normalized to parent qty

Reconciliation removes children the latest submission no longer includes
and collapses duplicate lines for the same identity. It does NOT decide
the final quantity — `normalize_children_qty` does, by re-asserting
child = parent_qty × per_set_qty after the dedup (see §11.1a). The parent
qty itself sums (qty=2 = two configurations of the set, the latest being
authoritative for WHICH children exist).
```

### 10.1 Reconciliation guarantees

Cart_Reconciler runs on **every** parent set add-to-cart event at
priority 105 (after Cart_Merger and Cart_Edit_Mode). It treats
`$_POST['rental_add_ons']` as the authoritative target for which
children should exist under the just-touched parent.

**Universal child identity.** Reconciliation no longer matches only
composite-group pairs. Every child — selectable, simple, addon, or
composite-group — is reduced to a stable IDENTITY so the new submission
can be diffed against the existing cart:

- composite-group child → `grp:{group_uid}:{item_uid}`
- everything else        → `pv:{product_id}:{variant_id}`

The same identity function (`cart_item_identity` / `submission_identity`)
is applied to both cart lines and submission entries. This is what lets a
**changed selectable variant** be reconciled: when Baby bike Orange is
swapped for Yellow and re-added, the old `pv:bike:orange` line is absent
from the new submission's identity set and gets dropped, instead of
lingering as a duplicate next to `pv:bike:yellow`.

| Condition | Action |
|---|---|
| Identity in cart, in submission, 1 line | Keep as-is |
| Identity in cart, in submission, ≥2 lines | Keep the first line, drop the rest (dedup — NO summing) |
| Identity in cart, absent from submission | Drop every line for that identity (stale) |
| Identity in submission, absent from cart | (No-op — those lines were just added by WC.) |
| Line whose identity can't be resolved | Leave untouched (defensive) |

Reconciliation deliberately does **not** sum or set quantities. After it
removes stale lines and dedups, `normalize_children_qty` (§11.1a)
re-asserts `child = parent_qty × per_set_qty` for what remains. Splitting
the two concerns — WHICH children exist (reconciler) vs HOW MANY of each
(normalizer) — is what keeps reconfigure/re-add correct.

**Stand-down conditions.** The reconciler's stale-removal pass exits
early — leaving the cart byte-identical to the legacy build — when ANY of
the following holds:

- `$_POST['_rental_set_from_product_page']` is absent. Reconciliation is
  scoped to modern set product-page submissions, which emit this marker
  (`single-product-modern.php`). Classic / non-modern / REST / AJAX adds
  never trigger removal.
- `$_POST['rental_add_ons']` is absent or empty. Without an authoritative
  target, removing children would be destructive (e.g. a JS error that
  shipped an empty payload must not wipe a set's children).
- The hooked event isn't for a set parent (`_rental_is_set` falsey
  on the product, or the line carries `rental_add_on_of`).
- The set product has no surviving parent line in the cart.

Note: quantity normalization is NOT gated by these conditions — it runs
for every set parent add unconditionally (see §11.1a).

**Multi-parent safety.** The reconciler keys its per-request guard on
the surviving parent's cart_item_key, so reconciliation runs at most
once per distinct parent line per request even when bundled /
composited add events fire multiple parent hooks.

**Settled-state safety net.** A second pass hooks
`woocommerce_cart_updated` (priority 30) and re-runs
`normalize_children_qty` for every set parent in the cart. This catches
any timing race where the add-time normalize ran before the parent
reached its final merged quantity (the historical "children stayed at 2
while parent became 4" bug). It is idempotent — a no-op when the cart is
already consistent — and guarded by a static re-entry flag because
`set_quantity` can re-fire `woocommerce_cart_updated`.

**Edit-mode interaction.** Cart_Edit_Mode (priority 100) removes the
old parent and its children before Cart_Reconciler runs. The
reconciler then walks the new parent's children — all matching
$_POST identities — and exits clean. Edit-mode flows are unaffected.

---

## 11. Cart quantity sync + pricing models

This is the authoritative spec for how a set's parent line, its child
lines, and their prices behave through cart quantity changes and
removal. It mirrors the Laravel order calculator exactly.

### 11.1 Quantity propagation (parent ⇒ children)

Rule: **every child's cart quantity is `parent_qty × per_set_qty`**,
where `per_set_qty` is the admin-defined per-set-unit quantity of that
child (1 for a single pick, N for a "pack of N").

- `per_set_qty` is stamped onto each child cart line as
  `rental_per_set_quantity` at add-to-cart time (in
  `rental_add_product_to_cart`, both the nested set-item-addon path and
  the top-level child path).
- `rental_after_cart_item_quantity_update` ([rentopian-sync.php]) uses
  ABSOLUTE math: `child_qty = parent_qty × per_set_qty`. It falls back
  to the legacy RATIO math (`child / old_parent × new_parent`) only for
  cart lines with no stored `per_set_qty` (old carts, non-set product
  addons).

Why absolute, not ratio: ratio math compounds desyncs and rounds to
zero on decrease. The historical bug — "decreasing a set removes its
children" — was `floor(1 / 2 × 1) = 0` firing on a child that had been
left unsynced at qty 1 while the parent was at 2. Absolute math can
never round a positive selection down to zero.

Child quantity is NOT independently editable in the cart: the
`woocommerce_cart_item_quantity` filter renders child lines as a
read-only `<div>` (no qty input). Required children also have their
remove link suppressed (`woocommerce_cart_item_remove_link`).

### 11.1a The normalization invariant (reconfigure / re-add)

The increase/decrease handler keeps children in sync during qty changes,
but it does NOT fire when a set is **reconfigured** (variant changed and
re-added) — that goes through the add-to-cart path, where the merger
collapses the new parent into the existing line and the freshly-added
children land at their add-time quantity (`per_set_qty`, usually 1) next
to a parent that's already at qty N. Result without intervention: a new
child at qty 1 beside a parent at qty 3.

`Rental_Sets_Cart_Reconciler::normalize_children_qty( $parent_key )` is
the authoritative enforcement of the invariant. It runs:

- on **every** set-parent `woocommerce_add_to_cart` (priority 105),
  AFTER the merger collapse and any stale-child reconciliation — so the
  parent's quantity at that point is known;
- from **edit-mode** `finalize_replacement` (priority 100), after the
  old line's quantity has been re-applied to the replacement parent;
- on **`woocommerce_cart_updated`** (priority 30) for every set parent —
  the settled-state safety net that catches the case where the add-time
  pass ran before the parent reached its final merged quantity (see
  §10.1, "Settled-state safety net").

It walks every child of the parent and forces
`child_qty = parent_qty × per_set_qty`. Because it's the same shared
method used by the re-add, edit, and settled-state paths, all five
mutation paths (initial add, increase, decrease, reconfigure/re-add,
edit-replace) end on the identical invariant.

Note the normalization runs for ALL set types — selectable, simple, and
composite-group. It is **unconditional** for set parents (no gate). The
`should_reconcile()` gate (`_rental_set_from_product_page` marker) scopes
ONLY the stale-child removal pass, never the quantity normalization.

When normalization changes any line it logs a `cart_qty_normalize`
warning naming the parent key, the parent quantity, and each child's
`key parent_qty×per_set old→new` transition — so a re-test makes the
exact propagation visible in `rentopian-sets-YYYY-MM-DD.log`.

### 11.1b Edit-mode quantity preservation

Editing a set's CONFIGURATION must not reset its quantity to the add-to-
cart form default. `Cart_Edit_Mode::finalize_replacement` captures the
quantity of the line being edited BEFORE removing it, re-applies that
quantity to the replacement parent, then runs `normalize_children_qty`.
A 3-up set stays 3-up after a variant change.

### 11.2 Removal cascade

- **Parent removed** → `rental_cart_item_removed` moves every child
  (`rental_add_on_of === parent_key`) into `removed_cart_contents`. The
  whole set leaves the cart together.
- **Orphan guard** → `rental_validate_and_update_add_on_quantity_in_cart`
  (on `woocommerce_cart_updated`) sets any child whose parent is gone to
  qty 0. This is a safety net, not the primary path.
- **Parent qty → 0** → `woocommerce_before_cart_item_quantity_zero`
  routes through the same handler with `quantity = 0`, zeroing all
  children.

### 11.3 The two pricing models

> **Full price spec lives in [PRICE-RULES.md](PRICE-RULES.md).** That
> file is the authoritative, standalone reference for display vs.
> calculation, the three display cases (CASE 1/2/3), and the single
> source of truth (`rental_set_child_effective_unit_price`). The summary
> below is just the model selector.

The set's `_rental_item_based_total` postmeta selects the model. Both
are verified against the Laravel order screen.

```
pricing_model: aggregated_children   (_rental_item_based_total = 1)
  set_total = parent_base × parent_qty × duration
            + Σ child( child_unit × child_qty × duration )
  - each child contributes its own price
  - rental_add_on_price on each child line = the child's resolved price
  - example (qty 1, 2d):
      parent 400×1×2 = 800
      baby   99 ×1×2 = 198
      bike   320×1×2 = 640
      shoe1  32 ×1×2 = 64
      shoe2  32.5×1×2 = 65
      set_total = 1767
  - example (qty 2, 2d): everything ×2 → set_total = 3534

pricing_model: fixed_bundle_price    (_rental_item_based_total = 0)
  set_total = parent_base × parent_qty × duration
  - children are informational/reference only
  - rental_add_on_price on each child line = 0 (they don't add to total)
  - child quantities still scale with parent_qty (display parity), but
    contribute nothing to the bill
  - example (qty 1, 2d): 199×1×2 = 398   (children shown but not billed)
  - example (qty 2, 2d): 199×2×2 = 796
```

### 11.4 Where each price is set

| Concern | Owner | Behaviour |
|---|---|---|
| Parent line per-unit price | `Rental_Sets_Pricing::compute_parent_price` (hook `woocommerce_before_calculate_totals` pri 20) | always `_regular_price × duration`; WC multiplies by parent_qty |
| Child line per-unit price | legacy `rental_calculate_cart_item_price` | `rental_add_on_price × duration`; WC multiplies by child_qty |
| Child `rental_add_on_price` at add-time | `rental_add_product_to_cart` | item_based_total=1 → resolved variant price; item_based_total=0 → 0 |
| Variant price resolution | `rental_resolve_variant_price` | `_regular_price` first (classic-parity), then parent's, then `_price`, then parent's |

### 11.5 Extending for new item types

To add a new set-item entity type (beyond simple / selectable /
composite-group), wire it into these four seams:

1. **Section presenter** — add a `build_<type>_section()` and emit it
   from `sections()`; set each option's `price` via
   `variant_price_for_display()` so the `_regular_price`-first chain
   applies.
2. **Modern JS `writeHiddenInputs`** — emit the child as a
   `rental_add_ons[i]` row carrying `parent_set_id`, `inherit_price`,
   `price`, and (critically) `quantity` so `per_set_qty` is captured.
3. **Pricing model** — decide whether the new type's children
   contribute (aggregated) or not (fixed bundle). Children contribute
   iff their `rental_add_on_price` is non-zero, which the legacy add
   path already gates on `_rental_item_based_total`.
4. **Qty sync** — nothing to do; absolute scaling in
   `rental_after_cart_item_quantity_update` already applies to any line
   carrying `rental_add_on_of` + `rental_per_set_quantity`.

---

## 12. Reliability invariants (Phase 2/4/6 reference)

The pre-checkout flow has invariants worth recording so the same bug class
can't return silently. Each subsection answers "where is the source of
truth, and what blocks divergence?"

### 12.1 Variant resolution — browser POST is the source of truth

For every variant the customer picks (selectable / addon / group child),
the **browser's `$_POST['rental_add_ons']` is authoritative**, not
postmeta. Postmeta lags behind the configurator state because
`persistSectionChange` updates it via async AJAX; submitting before that
AJAX lands would otherwise restore a stale variant.

| Child kind            | Form-first lookup site                              |
|-----------------------|-----------------------------------------------------|
| Selectable set item   | top of the `foreach $set_items_as_add_ons` loop in `rental_validate_cart_item` |
| Set-item addon        | inside the `foreach $set_item['addons']` loop in same function |
| Composite-group child | preserved verbatim from the browser POST via the grouped-child preservation step (Phase 2 grouped-children fix) |

Each lookup matches by `product_id` (and `parent_set_item_product_id`
for addons) and **trusts the POST's `variant_id`** when positive.
Postmeta is the fallback when the POST entry is absent or zero.

This makes "Pick variant → click Add to Cart immediately" produce the
correct cart line every time, regardless of AJAX-bridge timing.

### 12.2 Prefill match priority — `uid > variant > product`

`DropdownSection.applyPrefill` and `MultiSection.applyPrefill` scan all
options/cards and keep the **strongest** match seen:

1. `itemUid` exact match (server-assigned uid; uniquely identifies one item).
2. `variantId` exact match (the chosen variation).
3. `productId` match (last-resort, only meaningful for non-variant items).

Reason this matters: a dropdown of sibling variants shares
`data-product-id` across every option, so a naive
"first-OR-matching-criterion wins" loop locks onto option 1 every time
and ignores the actual `variantId` in the cart. The bug class
"edit shows the wrong variant" is this.

Same rule applies to multi-select cards (Baby Bike cards all share
product_id; only `variantId` distinguishes them).

### 12.3 Edit-mode line replacement — no cart_item_key collisions

Editing only **children** doesn't change the parent's `cart_item_data`
→ WC's `generate_cart_id` produces the same hash → the new line key
matches the line being edited. `finalize_replacement` then removes
every child of `old_key`, including the **new** ones just added (they
all point to that same key), wiping the set from the cart.

Two-layer prevention:

1. **`Cart_Edit_Mode::tag_parent_for_edit`** (priority 35 on
   `woocommerce_add_cart_item_data`) stamps a per-submission
   `__rental_edit_request_id` (UUID) onto the parent's cart_item_data
   when edit mode is active. WC's hash differs → fresh key →
   `finalize_replacement`'s loop correctly targets only the old
   children.

2. **Hard safety net in `finalize_replacement`** — if the new key
   somehow still equals the old key (Layer 1 failed to fire for some
   reason), refuse to run the destructive pass. Log
   `edit_mode_collision_skipped` and leave the cart as the merger left
   it. Slightly duplicated > empty.

Future non-edit-mode adds of the same set do **not** carry
`__rental_edit_request_id` (only set when `editing_key` is non-null),
so the standard merger's product-id-based collapse still works.

### 12.4 Sync batching + the `flushSync` rule

The configurator runs the validate + applyErrorVisuals +
writeHiddenInputs pass on every controller change. For perf, rapid
input is coalesced to one rebuild per animation frame:

- `Wrapper.requestSync()` schedules a `sync()` via
  `requestAnimationFrame` and stores the frame id (`_syncRafId`).
- A second call in the same frame is a no-op.
- `writeHiddenInputs` short-circuits when the serialized payload is
  byte-identical to the last write (delta-skip).

**Rule for any code path that submits the form (click handler, submit
handler, or programmatic submit):** call `Wrapper.flushSync()` FIRST.
That:

1. Cancels the pending rAF (so no late, post-submit sync).
2. Runs `sync()` synchronously — hidden inputs reflect the live state.

Without `flushSync`, the customer can pick a variant and click Add to
Cart in the same frame; the rAF hadn't fired; the theme's
`rentpro_add_to_cart` AJAX serializes the form with the **previous**
state. That's the bug class "picked X, cart has Y." Currently called
from `installSubmitGuard`'s click-capture handler and the form
`submit` handler.

### 12.5 Modern validator activation

`Rental_Sets_Cart_Validator`'s group rules (`Rule_Group_Required`,
`Rule_Group_Quantity`) read `$_POST['rental_set_selections']`. The JS
now routes `rental_set_selections[...]` into the form-internal host
(`appendInput`'s `routeToForm` allowlist) so the validator's
`modern_selections` is populated and rules actually enforce on submit:

- Required groups must have ≥ 1 pick.
- Multi-select min/max are enforced against `Σ child_qty` (group_quantity
  is not a factor here — it is the product-page qty-stepper default and
  order/quote metadata).
- Single-select groups must have ≤ 1 distinct pick (the "pick at most
  one" rule).

This is what stops invalid configurations (e.g. Tent Side Walls
exceeding `quantity_max`) from being added even when JS is disabled.
Together with the click-capture client guard in `installSubmitGuard`,
both layers refuse invalid submissions, the messages stay consistent
(both layers share `error_codes_dictionary`).

### 12.6 Phase 2 payload (observation-only — not the source of truth yet)

A normalized `rental_set_payload` JSON input is emitted alongside the
legacy nested array shapes for inspection / future cutover. The
server-side `Rental_Sets_Payload_Parser` observes it at two priorities
on `woocommerce_add_to_cart_validation`:

- **Priority 5** — captures the raw browser submission, schema-checks
  (`payload_observe ... schema_ok=1 issues=-`).
- **Priority 11** — diffs counts against the legacy synthesized
  `$_POST['rental_add_ons']` (`payload_diff ... all_match=1`).

Both observers wrap the body in `try / catch (\Throwable)` and ALWAYS
return `$passed` unchanged — they can never block an add.

The legacy nested shape is still the actual data the cart insertion
reads. Adoption of `rental_set_payload` as the canonical input is a
future phase, gated on consistent `all_match=1` across real traffic.

