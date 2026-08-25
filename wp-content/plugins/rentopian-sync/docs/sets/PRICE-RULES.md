# Sets Module — Price Rules (cart + checkout)

This is the authoritative, standalone spec for how a set's prices are
**displayed** and **calculated** through the cart and checkout. It exists
as its own file because the logic is subtle: the "Price" column and the
"Subtotal" column are computed by different WooCommerce code paths, and
keeping them consistent is the whole game.

It mirrors the Laravel order calculator exactly. If WP and Laravel ever
disagree on a number, this document is what WP is supposed to match.

---

## 0. Vocabulary

| Term | Meaning |
|---|---|
| **parent line** | the Set product's own cart line (`_rental_is_set = 1`, no `rental_add_on_of`) |
| **child line** | a cart line for a set item or addon (`rental_add_on_of = parent_key`) |
| **per-unit price** | the price of ONE of an item for the WHOLE rental period BEFORE the duration multiplier (i.e. the rental_price / `_regular_price`) |
| **duration** | `rental_get_days()` — number of rental days for the current cart |
| **display price** | the "Price" column value in cart/checkout (per-unit × duration) |
| **subtotal** | the line total = display price × quantity |
| **per_set_qty** | how many of a child belong to ONE unit of the set (admin-defined) |

A child's cart quantity is always `parent_qty × per_set_qty` — see
WORKFLOWS.md §11 for the quantity-sync rules.

---

## 1. The two pricing models

The set product's `_rental_item_based_total` postmeta selects the model.

### aggregated_children  (`_rental_item_based_total = 1`)

The parent line itself bills nothing. The set total is the sum of every
child's effective price:

```
set_total = Σ child( child_unit × child_qty × duration )
```

The parent's "Price" column reads `$0.00`, the parent line subtotal is
`$0`, and the cart total is driven exclusively by the children. The
parent's own `_regular_price` is treated as advisory metadata for
aggregated sets — it does NOT contribute to the cart.

**Why this rule** (changed 2026-06-05): aggregated sets are built so the
customer's PICK determines the price. Adding the parent's own
`_regular_price` on top would double-count the package fee for sets where
the children already represent the entire price. The previous behavior
inflated totals for every aggregated set that had a non-zero parent
`_regular_price`.

Example — "Demo Set A" (aggregated), duration 2d, qty 1. Children:
Selectable Item A ($99/day), Selectable Item B with chosen variant
($320/day), and two addons on Item B ($32/day, $32.50/day):

```
parent (Demo Set A)        ──────── = 0
selectable A      99 × 1 × 2  =  198
selectable B     320 × 1 × 2  =  640
  addon B-1       32 × 1 × 2  =   64
  addon B-2     32.5 × 1 × 2  =   65
                                ----
set_total                      =  967
```

At qty 3 (everything scales by qty):

```
parent (Demo Set A)        ──────── = 0
selectable A      99 × 3 × 2  =  594
selectable B     320 × 3 × 2  = 1920
  addon B-1       32 × 3 × 2  =  192
  addon B-2       29 × 3 × 2  =  174   (variant chosen at $29/day)
                                ----
set_total                      = 2880
```

### fixed_bundle_price  (`_rental_item_based_total = 0`)

The parent bills its fixed bundle price. Children's contribution depends
on whether they're variant-based (see §2 CASE 2 vs CASE 3):

```
set_total = parent_base × parent_qty × duration
          + Σ variant-based child( child_unit × child_qty × duration )
```

- **Variant-based children** (selectable items / entity-2, variable
  addons): the customer's pick affects the price, so they contribute their
  real per-unit price on top of the parent's fixed fee.
- **Simple included / optional items** (entity-1) — including ones that
  point to a specific variation: informational only, contribute nothing —
  UNLESS flagged `separate_price`, in which case they too bill on top of
  the bundle (see §2 CASE 4).

Example A — every child is a non-variant included item ("Demo Set B",
`_regular_price = $199`, duration 2d):

```
qty 1:  199 × 1 × 2 = 398   (included items shown but NOT billed)
qty 2:  199 × 2 × 2 = 796
```

Example B — fixed parent + variant children ("Demo Set C",
`_regular_price = $400`, duration 2d, qty 3, customer picks $320/day
variant on Selectable B with two addons at $32/day and $29/day, plus
Selectable A at $99/day):

```
parent (Demo Set C)    400 × 3 × 2 = 2400
  selectable B         320 × 3 × 2 = 1920   (variant-based → bills)
    addon B-1           32 × 3 × 2 =  192
    addon B-2           29 × 3 × 2 =  174
  selectable A          99 × 3 × 2 =  594
                                      ----
set_total                            = 5280
```

The same set re-flagged with `_rental_item_based_total = 1` would drop
to **2880** (parent contributes 0, children unchanged).

---

## 2. The price-DISPLAY rules (the subtle part)

Whether a child shows its real price or `$0.00` in the Price column is a
SEPARATE decision from how the set total is computed. The rule:

> **subtotal_visibility ≠ price_visibility.**
> If a child participates in pricing (its subtotal is non-zero) it MUST
> show a real per-unit price. A line that reads "price $0.00 — subtotal
> $1920.00" is a bug.

Three cases:

### CASE 1 — `item_based_total = true`
Every child contributes. **Show the real per-unit price.** Both Price
column and Subtotal are non-zero and consistent.

### CASE 2 — `item_based_total = false` AND child is a genuine variant CHOICE
A child is "variant-based" only when the customer actually CHOOSES among
options: a **selectable** (entity-2, the set item carries `optional_items`),
or a variable **addon** (which carries a non-zero `rental_add_on_price`).
The choice affects pricing, so **show the real per-unit price** — a `$0.00`
display would be misleading — and contribute to the total.

A plain **simple** set item (entity-1) is NOT variant-based even when it
points to a specific variation (it has a `variation_id`): there is no
customer choice, so it falls under CASE 3.
`Rental_Sets_Price_Engine::is_simple_set_item()` is the discriminator —
true for a top-level `_rental_set_items` entry that has no
`optional_items`.

### CASE 3 — `item_based_total = false` AND child is a simple included/optional item
A simple set item (entity-1) — **including one that points to a specific
variation** — or any non-variant included item. There is no customer
variant choice; the parent bundle price is authoritative and the child is
descriptive. **Show `$0.00`.** It does not contribute to the total
(unless flagged `separate_price` — CASE 4).

### CASE 4 — `separate_price` flag set (overrides CASE 3)
An included item (simple, selectable, or group child) flagged
`separate_price` is priced separately and **added on top of** the fixed
bundle fee. It **shows its real per-unit price** and contributes to the
total even in a `fixed_bundle_price` set where it would otherwise read
`$0.00`. The rate resolves through the normal priority chain (§3): the
in-set stored price first, then the live variant chain. This is the
admin's explicit "charge this item in addition to the bundle"
instruction. (Group children that carry a `group_price` keep using that
override — `group_price` still wins.)

Decision table:

| item_based_total | child variant-based? | separate_price | Price column | Contributes to total |
|:---:|:---:|:---:|:---:|:---:|
| true  | any   | any | real price | yes |
| false | yes   | any | real price | yes |
| false | no    | yes | real price | yes |
| false | no    | no  | $0.00      | no  |

"variant-based" here means a genuine customer CHOICE — a selectable
(entity-2) or a variable addon — NOT a simple item (entity-1) that merely
points to a variation. A simple included/optional item is always "no" in
the variant-based column, so in a fixed-bundle set it reads `$0.00` unless
flagged `separate_price`.

---

## 3. Single source of truth

To guarantee the Price column and the Subtotal never disagree, both read
from ONE function:

`Rental_Sets_Price_Engine::effective_child_unit_price( $cart_item )` — the
procedural `rental_set_child_effective_unit_price()` in [rentopian-sync.php]
is a thin backward-compatible wrapper around it. Returns the child's
effective **per-unit** price (before duration), or `null` for non-set lines
(caller uses its own default).

```
returns 0.0   → CASE 3 (informational; Price column shows $0.00)
returns >0    → CASE 1 / CASE 2 / CASE 4 (real price)
returns null  → not a set child (plain product addon / parent line)
```

The CASE-4 `separate_price` flag travels on the cart line (stamped at
add-time from the set definition) and flips the contribution gate so an
otherwise-informational included item bills its own rate. It does not
change the rate-resolution priority below — only WHETHER the child
contributes.

It delegates to `Rental_Sets_Price_Engine::child_unit_price()`, which
resolves through this priority chain:

1. **Composite group `group_price`** — any numeric value (incl. 0) wins.
2. **Set-level override (`stored_price`)** — `inventory_sets_relation.price`
   on the Laravel side, stamped onto the cart line at add-time as
   `rental_add_on_price`. Positive value wins over the live variant chain.
   This is the in-set price the admin configured for THIS product as
   part of THIS set, distinct from its standalone product price.
3. **Live variant chain** (`rental_resolve_variant_price`) — walks
   `_regular_price` first (classic-parity; survives WC's variation
   price-sync), then the parent product's `_regular_price`, then
   `_price`, then parent `_price`. Falls back here when no in-set
   override exists.
4. **0** — last resort.

The Section_Presenter mirrors the same priority for the product-page
labels (`build_simple_section`, `normalise_selectable_option`,
`normalise_group_item`), so the configurator dropdown / card and the
cart line subtotal always agree on the per-unit rate.

### Who calls it

| Concern | Function | File |
|---|---|---|
| Price column | `rental_calculate_cart_item_price` (child branch) | rentopian-sync.php |
| Subtotal (line total) | `calculate_cart_totals` (set-child override) | functions.php |
| Add-time stamp | `rental_add_product_to_cart` sets `rental_add_on_price` per the same CASE 1/2/3 gate | rentopian-sync.php |

Because the add-time stamp, the Price column, and the Subtotal all apply
the same gate, the three can't drift apart.

---

## 4. Parent line price

The parent line price depends on the pricing model:

| `_rental_item_based_total` | Parent per-unit price |
|:---:|---|
| 1 (aggregated) | **0** — parent bills nothing; children carry the total. |
| 0 (fixed bundle) | `_regular_price × duration` — parent bills the fixed bundle fee. |

Computed by `Rental_Sets_Price_Engine::parent_unit_price()` and applied
to the cart via `Rental_Sets_Pricing::recalculate()`
(`woocommerce_before_calculate_totals` priority 20). WooCommerce then
multiplies by the parent quantity to produce the line subtotal.

### 4.1. Parent line + selected product / set options

When the customer picks priced options on the set's product page (e.g.
"Yes with only one employee · $150"), each selected option's price is
**added to the parent line's per-unit price** by `calculate_cart_totals`
(functions.php). The parent line subtotal column = `(parent_base +
Σ selected option prices) × parent_qty`. This holds for BOTH pricing
models — options always add on top of the base.

Implementation note. `Rental_Sets_Pricing::recalculate()` hooks
`woocommerce_before_calculate_totals` at priority **5** to write the
BASE (parent_unit_price for the model). The legacy
`calculate_cart_totals` hook at priority **10** then reads that base
and layers the option prices on top. If the base writer ran later
(it used to register at priority 20), it would clobber the
option-augmented value and the parent line would render `$0.00` even
when paid options are selected. This priority order is load-bearing —
do not change without re-verifying the option-add flow in cart + mini-cart.

### 4.2. Composite-group `group_price` override

When a composite group has a non-empty `group_price` (including the
literal `0`), every option in that group bills at that rate — the
variant chain is skipped entirely. Empty / null means "no override".

```
if group.group_price is null or '':
    child_unit = variant_chain_or_stored_price   # existing CASE 1/2/3
else:
    child_unit = group.group_price               # override (0 is valid)
```

The same value drives both the product-page dropdown label and the cart
line subtotal — they share `child_unit_price()` so they can never
disagree. Use case: a "Same-rate Group" where every option in the group
bills at one flat rate regardless of which option the customer picks.

---

## 5. Worked end-to-end examples

All examples below use a single reference set, **"Demo Set"**, with the
following composition:

- Parent product: "Demo Set", `_regular_price = $400/day`
- Selectable A: a variable product. The set definition's
  `inventory_sets_relation.price` for the chosen variant is `$99/day`.
- Selectable B: a variable product. The chosen variant carries an
  in-set price of `$320/day`.
- Addon B-1 on Selectable B: variable addon, in-set price `$32/day`.
- Addon B-2 on Selectable B: variable addon, in-set price `$29/day`.
- Composite Group G (used in 5.3 only): the same Selectable B variants
  reorganised as a single-pick group.

Every per-unit number below is the **in-set price** stamped on
`_rental_set_items[i].price` (or the equivalent on optional_items /
addons / group items). When that field is empty, the live
`_regular_price`-first variant chain takes over (§3).

Duration: 2 days, parent qty: 3.

### 5.1. fixed_bundle (`_rental_item_based_total = 0`)

Parent bills its fixed $400/day. Variant-based children also bill —
CASE 2 in §2. A fixed, non-variant included item would read $0.00
(CASE 3).

```
LINE                         per-unit  ×dur  display  ×qty   subtotal
Demo Set (parent)               400      2     800      3      2400
  Selectable B                  320      2     640      3      1920
    Addon B-1                    32      2      64      3       192
    Addon B-2                    29      2      58      3       174
  Selectable A                   99      2     198      3       594
                                                              -----
cart subtotal                                                 5280
```

### 5.2. aggregated (`_rental_item_based_total = 1`)

Demo Set re-flagged to aggregated. Only the children bill; the $400/day
parent fee is dropped.

```
LINE                         per-unit  ×dur  display  ×qty   subtotal
Demo Set (parent)                 0      2       0      3         0
  Selectable B                  320      2     640      3      1920
    Addon B-1                    32      2      64      3       192
    Addon B-2                    29      2      58      3       174
  Selectable A                   99      2     198      3       594
                                                              -----
cart subtotal                                                 2880
```

Aggregated sets never bill the parent's own `_regular_price` — a set
whose `_regular_price = 0` and whose chosen children carry the cost
is the natural shape this model represents.

### 5.3. group_price override (composite group only)

Demo Set in aggregated mode, with Composite Group G carrying
`group_price = 200`. The customer's pick within the group still
determines WHICH item lands in the cart, but the per-unit rate is
fixed at the group level.

```
LINE                         per-unit  ×dur  display  ×qty   subtotal
Demo Set (parent)                 0      2       0      3         0
  Group G (pick from group)*    200      2     400      3      1200    ← 200 overrides 320
    Addon B-1                    32      2      64      3       192
    Addon B-2                    29      2      58      3       174
  Selectable A                   99      2     198      3       594
                                                              -----
cart subtotal                                                 2160
```

`* group_price = 200 overrides the variant's $320/day. Every option
in this group bills at $200/day regardless of which option the
customer picks. group_price applies to composite groups only — it
does NOT affect entity-2 selectable items (Selectable A above keeps
its $99/day).`

---

## 6. Extending for new set-item types

When you add a new entity type, wire it into the four seams and the price
rules apply automatically:

1. **Section presenter** — set each option's `price` through
   `variant_price_for_display()` (so the `_regular_price`-first chain
   applies to the dropdown labels).
2. **Modern JS `writeHiddenInputs`** — emit the child as a
   `rental_add_ons[i]` row carrying `parent_set_id`, `inherit_price`,
   `price`, and `quantity`.
3. **Pricing model** — decide whether the type contributes. It does iff
   `rental_set_child_effective_unit_price()` returns `> 0`, which is
   governed by the CASE 1/2/3 gate. For a type that should always bill
   (like addons), make sure it's variant-based or carries a non-zero
   `rental_add_on_price` so it lands in CASE 2.
4. **Quantity sync** — nothing to do; absolute scaling already applies to
   any line carrying `rental_add_on_of` + `rental_per_set_quantity`.
