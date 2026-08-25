# Attribute Value Groups

## Overview

An attribute value group is a value that stands for several other values of the
same attribute. A store with dozens of shades of navy can present one "Navy"
swatch in the shop filters, which selects every shade behind it, while each
product keeps showing its own exact value.

Grouping affects the **filter facet only**. Product pages, variation swatches
and the product-loop colour dots are untouched.

Rentopian owns the group definitions; the plugin stores them and the theme's
layered-nav widget renders them.

## What a group is

A group is an ordinary attribute value carrying `is_group = 1`. It has its own
slug, title, colour and image, and it becomes an ordinary WooCommerce term in
the attribute taxonomy, so the same rendering code draws its swatch or thumb.

A group is never linked to a variant. Because it holds no products, a
`hide_empty` term query — which is what the product page, the loop and the
facet all use — never returns it. That is what makes the whole feature inert
until the facet is explicitly told to use groups.

## Database

| Table | Purpose |
|-------|---------|
| `{prefix}rental_attribute_value_relations` | Rentopian value id ↔ WordPress term id, plus the `is_group` flag |
| `{prefix}rental_attribute_value_groups` | Group → member map, both sides Rentopian value ids |

`rental_attribute_value_relations` did not exist before this feature: attribute
values used to be matched by slug alone, and nothing recorded their Rentopian
id. Membership needs that id, so every value write now stores the pair.

Membership is stored **by Rentopian id, never by term id**. A membership sync
may arrive before its member value's own `create` webhook; an id that cannot be
resolved yet contributes nothing and starts working the moment the value
arrives, with no reconciliation pass.

### Schema creation

`rental_create_tables()` only runs on plugin activation, so an existing site
receiving this as an update would never get the tables.
`Rental_Attribute_Groups::ensure_tables()` creates them, guarded by
`rental_attribute_groups_schema_version`; it runs on `admin_init`, from the
webhook handler and from both sync paths.

## Webhooks

### `attribute/value/create` · `attribute/value/update` · `attribute/value/delete`

Unchanged actions, with two extra fields read from `attribute_value`:

```json
{ "id": 123, "attribute_id": 45, "slug": "navy", "title": "Navy",
  "color": "#000080", "img_id": 0, "is_group": 1 }
```

`id` and `is_group` are persisted alongside the term. A delete removes the
value and every membership row naming it — as a group or as a member.

### `attribute/value/group/sync`

```json
{ "group_value": { "id": 123, "attribute_id": 45, "slug": "navy",
                   "title": "Navy", "color": "#000080", "img_id": 0, "is_group": 1 },
  "members": [201, 202, 203] }
```

Replaces the group's entire member set. Delivering the same payload twice
leaves the same rows. The group value itself is upserted first, so this action
also works as the group's `create`.

- `members: []` — the group has no members.
- `is_group: 0` — the value was converted back to an ordinary one: its members
  are dropped and the incoming list ignored.
- Unknown member ids are stored, not rejected.

Responses are `200` with `members_saved`, or `200` with `stored: false` when
the plugin build has no group storage. Only a real failure to write is a `4xx`.

## Full pull

| Endpoint | Used for |
|----------|----------|
| `GET products/attributes/values` | Values, now including `is_group` |
| `GET products/attributes/value-groups` | Membership pairs `{ group_value_id, value_id, attribute_id }` |

Both sync paths consume them: the background data sync
(`Rental_Data_Sync_Phases::sync_attribute_value_groups()`) and the legacy
`rental_synchronization()`.

Membership is replaced wholesale, so it is only touched when the endpoint
actually answered. `rental_curl()` throws on any non-200, and a core that does
not serve `value-groups` yet returns 404 — taking that as "no groups" would
erase every group on the site, so the failure is logged and the previous
membership kept. In the legacy path the fetch happens before the catalog purge,
so a failure costs nothing.

## The filter facet

Setting: **Products → Group attribute values in filters**
(`rental_group_attribute_values_in_filters`, default off).

With the setting on, an attribute that has at least one group shows:

- every group, drawn by the attribute's type (colour swatch / thumbnail / label);
- every value that belongs to no group, exactly as before.

Values that belong to a group are not listed on their own.

Clicking a group writes its member slugs into the ordinary `filter_<attribute>`
argument and adds `query_type_<attribute>=or`:

```
?filter_color=navy-gold,navy-silver&query_type_color=or&filtering=1
```

Nothing downstream needs to know about groups: the tax query, the lookup-table
integrity guard, load-more, chunked term lists and the page cache all see a
normal value filter. Turning the setting off later leaves existing links
working.

### Hover card

Hovering a group names the values it stands for, so a click is never a guess.
The list follows the attribute's own ordering and is capped — six names, then
`+N more` — so a group holding dozens of values cannot grow the card past the
viewport. It names the values that actually filter something, which is exactly
the set the link carries.

The same card also names a **swatch whose label is switched off**, which
otherwise shows nothing readable: the theme hides `.term-name` on a
`show-labels-off` list and suppresses its own tooltip on swatch lists. An entry
gets a card when it is a group, or when the widget's "Show labels" setting is
off. A value whose name is already visible does not get a redundant one — that
setting is the single thing that decides, so the compact colour-chip grid only
applies with labels off, and neither names nor counts are hidden behind the
widget's back.

It is a real element — `.rentpro-filter-hint` inside the link, `aria-hidden`
because the link's `aria-label` already carries the name — not a css tooltip.
The theme owns `.term-link:before` / `:after` in every list style: swatch lists
hide them outright (`list-style-color.show-labels-on .term-link:after
{display:none}`) and plain lists use `:after` for the hover underline
(`content:''`, `background:currentcolor`), which turns any generated-content
tooltip into an empty white box. Cards in the first and last grid column anchor
to their own edge rather than centring, so they cannot hang off the sidebar.

Three consequences worth knowing:

- Selecting a group makes that attribute read as "any of" for the whole
  request, including any ungrouped value selected next to it. One filter
  argument cannot express "all of" and "any of" at once.
- A value **promoted** to a group keeps the products that were tagged with it
  directly, so a group filters on its own value as well as on its members and
  is counted the same way. Without that, those products would be unreachable
  from the facet. A group that never held products adds nothing.
- Counts are `COUNT(DISTINCT product)` per group, as one query branch per
  group. Summing the members would count a product twice when it carries two
  of them, and mapping members to groups in a single `CASE` would drop a value
  that belongs to **two** groups from the second one — the first `WHEN` wins.

A group with no member that matches anything counts 0 and is dropped from the
facet, like any other empty value.

## Read API

```php
Rental_Attribute_Groups::enabled();                    // setting on and tables present
Rental_Attribute_Groups::groups_for_taxonomy( 'pa_color' );  // [ group term id => member term ids ]
```

The theme calls both behind `class_exists()`, so the theme works without the
plugin and the plugin without the theme. Results are memoized per request and
cached in a transient whose key carries `rental_attribute_groups_cache_version`;
any write bumps that version once, at shutdown.

## Files

| File | Role |
|------|------|
| `includes/attribute-groups/class-attribute-groups.php` | Storage, delete cascade, read API, cache |
| `includes/attribute-groups/bootstrap.php` | Load + schema check |
| `includes/models/RTAttributeValue.php` | Writes the value ↔ term map on save, cascades on delete |
| `api.php` | `attribute/value/group/sync` handler, `is_group` on the value handler |
| `includes/data-sync/class-data-sync-phases.php` | Membership rebuild in the background sync |
| `functions.php` | Tables, purge, legacy sync, the setting's admin field |
| `themes/rentpro/widgets/product-layered-nav.php` | Group composition, links, chosen state, hover text |
| `themes/rentpro/widgets/class-wc-widget-base.php` | Grouped count query |
| `themes/rentpro/framework/woocommerce/shop-filters.php` | Hover-card styling and edge-column anchoring |
