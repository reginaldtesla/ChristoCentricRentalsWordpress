# Product Options: Selection and Validation

How the plugin decides which option value is chosen, and whether an
add-to-cart may proceed. Read this before touching the add-to-cart gate, the
option renderer, or anything that reads an option selection.

The module lives in `includes/product-options/` and mirrors the layout of
`includes/sets/`. See [Related files](#related-files) for the map.

## Contents

1. [The problem this module solves](#the-problem-this-module-solves)
2. [Two states: staged and committed](#two-states-staged-and-committed)
3. [Precedence: one answer per state](#precedence-one-answer-per-state)
4. [Default values](#default-values)
5. [The validation pipeline](#the-validation-pipeline)
6. [POST shape](#post-shape)
7. [Entry points](#entry-points)
8. [What the client does](#what-the-client-does)
9. [Never touch state WooCommerce owns](#never-touch-state-woocommerce-owns)
10. [Session stores](#session-stores)
11. [Where the module is active](#where-the-module-is-active)
12. [Themes](#themes)
13. [Extending](#extending)
14. [Diagnosing a blocked add](#diagnosing-a-blocked-add)
15. [Related files](#related-files)

---

## The problem this module solves

Option selections used to live in three places that were written on different
code paths and read by different callers:

| Store | Written by | Read by |
|-------|-----------|---------|
| `<pid>_selected_options` session | the option AJAX, the render endpoint | the renderer |
| `rental_product_options_valuables` session | the option AJAX, part of the render endpoint | the add-to-cart gate |
| the cart line | the capture step at add | cart, checkout, order, and the renderer |

The submission itself — the `<select>` values the customer was looking at —
was read only *after* validation had already voted.

That produced two reports:

- **A product page shows every option answered, but Add to Cart says one is
  not.** The page had rendered from the cart line, which does not write the
  session store the gate read. Any option without an explicit default flag was
  then reported missing.
- **Changing an option still errors.** The change was written by a debounced
  AJAX call, and only for the option that changed. An add submitted before it
  landed — or on a product with other untouched options — still saw stale state.

Both are the same defect: several stores, no precedence, and the authoritative
one ignored.

## Two states: staged and committed

This is the model everything else follows.

**Staged** is the configuration the customer is building on the product page
for their *next* add. It lives in the session stores only.

**Committed** is what a line in the cart actually holds. It lives on the cart
line, and it is what the cart, mini-cart, checkout and the order all display.

They are deliberately separate:

- Changing an option on the product page **never** touches a line already in
  the cart. The customer is configuring the next add, not editing a decision
  they already made. Letting the product page write the cart line is what made
  a change there show up on checkout while the cart and mini-cart still showed
  the old value.
- Adding to cart **commits** the staged selection, replacing the line for that
  product.
- Editing on the **cart page** changes the committed line; cart, mini-cart and
  checkout move together because they all read that line.

`Rental_Options_Selection::CONTEXT_STAGING` (the default) and
`CONTEXT_COMMITTED` select which of the two a caller means. The product page
and the add-to-cart gate both use STAGING, so they always agree about what is
selected.

## A cart row answers only for itself

One option definition can belong to several products, and one product can
occupy several cart lines. A row therefore has to be resolved from **its own
cart line**, keyed by cart item key — never from a store keyed by product id.

`rental_product_options_valuables` is keyed by product id and is written with a
staged pick by one path and rebuilt from the cart lines by another. Reading it
as a per-row fallback is what let one row display a value belonging to another
row, to another line of the same product, or to a product page visit that was
never added to the cart; whichever write happened last appeared to apply to
every row at once.

`Rental_Options_Selection::for_cart_line()` is that rule in one place: the
line, then the option's own default, and nothing else.
`unanswered_for_cart_line()` is its "what is still missing" counterpart, which
skips once-per-order options. Every reader that knows which line it is asking
about goes through them:

- **Display** — `wp_ajax_rental_get_all_options_of_cart_products()` answers each
  row from its own line, and from the option's default when that line holds
  nothing for the option.
- **Pricing** — `calculate_cart_totals()` follows the same rule, so a value the
  customer is only trying out on a product page cannot price a line already in
  the cart.
- **Order payload** — each line's `product_options` / `set_options` come from
  that line. Two lines of one product used to collapse to a single entry, so
  one of them was submitted to Laravel carrying the other's options.
- **Order item and mini-cart** —
  `Rental_Options_WC_Integration::get_selected_options_from_session()` returns
  immediately when it is handed a cart line. Its fallback chain finds answers by
  product id — the first matching cart line, then session stores — which is
  what reached the saved order item for the wrong line.
- **Checkout gate** — `rental_check_selected_options()` and its AJAX twin judge
  each line separately, so an unanswered line cannot pass on the strength of a
  different line of the same product.

Client side, every cart control is addressed by cart key as well as option id
(`rental-cart-option-<cartkey>-<optionid>`), the option id travels in
`data-option-id` and the value id in `data-value-id`, and each select marks
exactly one value selected. Two rows sharing an option are therefore distinct
in the DOM as well as on the server.

What remains product-id keyed is the product page's own staging store, which is
correct — the product page really is asking about a product, not a line.
`rental_product_options_valuables` is still written for backward compatibility
and is read only in COMMITTED context, where the line already outranks it.

## Precedence: one answer per state

`Rental_Options_Selection::resolve()` is the only thing that answers "what is
selected". It walks these in order and stops at the first source that offers a
value the option actually defines:

1. **`$_POST['rental_options_selection']`** — what the customer is submitting
   in this request. Authoritative whenever present.
2. **The cart line** — `rental_selected_options`, or
   `rental_selected_set_options` for a set. **COMMITTED reads only.**
3. **The per-product session store** — `<pid>_selected_options`.
4. **The shared session store** — `rental_product_options_valuables`.
   **COMMITTED reads only**, for the same reason as the cart line: a cart
   render rebuilds this map from the cart lines, so reading it while staging
   reintroduces the committed value one hop later under another name.
5. **The option default** — see below.

An option that survives all five without a value is genuinely unanswered, and
is the only thing the validator may block on. A value that is not one of the
option's own values is ignored rather than accepted, so a stale or tampered id
falls through to the next source.

Every resolved entry records where it came from in `source`, which is what the
trace log prints.

## Default values

`Rental_Options_Defaults::resolve()` is the single default rule:

1. The first value flagged default wins. The flag is read from `is_default` or
   `default`, and is accepted as `1`, `"1"`, `true`, `"true"`, `"yes"` or
   `"on"` — the options payload is stored verbatim from the API, so the
   spelling varies.
2. Otherwise, an option offering exactly one real value uses that value. There
   is nothing for the customer to decide.
3. Otherwise there is no default and the customer must choose.

The `-1` "Please select an option" entry is a renderer placeholder, never a
value. It is stripped from definitions and rejected as a selection.

Once-per-order options are asked at order level and never require a
per-product choice.

## The validation pipeline

Mirrors `includes/sets/validation/`:

```
Rental_Options_Cart_Validator          orchestrator, woocommerce_add_to_cart_validation @ 25
  └── Rental_Options_Validator_Context immutable, resolves the selection once
  └── Rental_Options_Rule_Required     the only built-in rule
  └── Rental_Options_Validation_Result codes + messages
```

The orchestrator:

- returns early when `$passed` is already false, so it never stacks an options
  error on top of an unrelated failure (a missing date, an availability
  rejection);
- skips add-on products, and skips modern sets, which run their own pipeline
  (`Rental_Sets_Cart_Validator`);
- on **pass**, keeps the accepted selection for the capture step and writes it
  to both session stores, so what is stored is what was validated;
- on **fail**, raises the notices.

Keeping the customer's picks after a rejection is handled by the final-verdict
hook at priority 999, not by the orchestrator. The orchestrator returns early
when an earlier callback has already failed, so on its own it would only
remember picks for rejections it raised itself — a missing rental date or an
unavailable product would reset the selects for no reason. The submission is
stored under `rental_options_failed_selection_<pid>`, against both the variation
and the parent id (the product page renders a variable product from the parent),
and cleared once the item reaches the cart.

Error codes:

| Code | Meaning |
|------|---------|
| `option_not_chosen` | The form was submitted with an option still unanswered. |
| `needs_product_page` | The request carried no options form at all, and an option needs a choice. |

## POST shape

The product-page selects submit with the add-to-cart form:

```
rental_options_selection[<option_id>] = <value_id>
```

`-1` means the placeholder is still selected. An absent field is not the same
as an empty one: absent means the request carries no options form (an archive
or quick-view add), which the validator reports differently.

## Entry points

| Entry point | Carries the form? | Behaviour |
|-------------|-------------------|-----------|
| Single product page | yes | Normal path. The submission is authoritative. |
| Variable product | yes | Options load from the PARENT on paint. They no longer wait for every attribute to be chosen. |
| Shop loop / quick view | no | Defaults are applied. If an option needs a real choice, the button becomes a "Select options" link to the product page and AJAX add-to-cart is withdrawn (`Rental_Options_Archive_Gate`). |
| Cart update | yes | Options are not re-validated; the line was validated on the original add. |
| Cart page edit | n/a | COMMITTED: writes the cart line, targeted by the cart item key the row already carries. Cart, mini-cart and checkout follow. |
| Programmatic add | no | Falls back to a fresh resolve, so defaults are still recorded. |

The archive gate answers from the post-meta cache first, so a product with no
options costs no extra query in a loop.

## What the client does

`includes/product-options/assets/js/product-options.js`:

- asks for options with the **parent** product id, immediately on ready. It used
  to ask with `input.variation_id`, which is `0` until every attribute has been
  chosen, so a variable product showed an empty Options heading until the
  customer had picked a size and a colour;
- locks Add to Cart before the options request is sent — the selects do not
  exist yet, so an add during that window would carry nothing;
- renders each select from the server's `selected_value_id` rather than
  re-deciding the default in the browser;
- keeps the button locked while any select shows the placeholder, and names the
  outstanding options inline;
- refuses the submit and the button click as a last resort.

The server never trusts any of this — it is there so the customer sees the same
answer the gate would give, before they submit.

`requires_choice` in the endpoint response tells the client which options are
still open.

### Never touch state WooCommerce owns

This is the rule that matters most in this file.

WooCommerce's variable-product guard is **class-based**: it puts `disabled` and
`wc-variation-selection-needed` on `.single_add_to_cart_button` while no
variation is chosen, and its own click handler is literally
`if ( $( this ).is( '.disabled' ) )`. The RentPro theme's AJAX add checks the
same class. Both treat it as the single "not ready" signal.

An earlier version of this module used that same class to say "options are
fine", so releasing the options lock also released WooCommerce's variation
guard. The form then submitted with `variation_id=0` and WooCommerce answered
with its own *Please choose product options for X.* — an error about a
variation, phrased as if it were about options, on a page where the options
were plainly answered.

So:

- the lock class is `rental-options-locked`, plus `aria-disabled`. Never
  `disabled`, never a `wc-*` class, never the `disabled` attribute;
- blocking happens in a **capture-phase** listener that runs before
  WooCommerce's and the theme's handlers, and only when
  `rentalOptionsBlockReason()` returns something. When it returns `''` the
  event is passed through completely untouched;
- a missing variation is never our message. WooCommerce already blocks it and
  says so; we stay silent.

`rentalOptionsBlockReason()` is the only place that decides whether we
interfere. It speaks about options and nothing else.

The gate listeners are registered **before** the options request is fired, and
`block()` / `unblock()` swallow their own errors, because blockUI is not
guaranteed to be present — an exception there used to abort the ready handler
and leave the gate unregistered.

### Variations

Options hang off the parent product, and `get_product_options()` falls back to
the parent for a variation — so the parent's set is the right one to show
before any attribute is picked.

A variation *may* still declare option ids of its own. `Rental_Options_Assets`
localizes `rentalProductOptions.variationOverrides` with the variations that do,
and the renderer re-fetches on a variation change **only** for those. When the
list is empty — the normal case — no variation change causes a request.

`wp_localize_script` casts every scalar to a string, arrays included, so those
ids arrive as `["11585"]`. Compare them numerically
(`rentalOptionsVariationOverrides()` normalizes once).

## Where the module is active

The option markup only renders while the sync is on and the API key has been
accepted. `Rental_Options_Repository::module_is_active()` reads the same two
options, and both the gate and the asset enqueue defer to it — otherwise the
validator would demand a choice for selectors that were never drawn.

It reads the cached `rental_api_key_is_valid` option directly rather than
calling `rental_check_api_key()`, which falls back to an HTTP request.

## Themes

RentPro and Eventorian do not render option controls. They contribute only:

| File | Contribution |
|------|--------------|
| `rentpro/woocommerce/cart/cart-content.php` | An empty `<td class="product-options">` with `data-product-id` / `data-cart-key`; the script fills it. |
| `eventorian/woocommerce/cart/cart.php` | The same cell. |
| `rentpro/framework/woocommerce/quick-view.php` | `remove_action( 'woocommerce_before_add_to_cart_button', 'rental_generate_product_options' )` — quick view deliberately omits options, so a product needing a choice is sent to its page by the archive gate. |

Both `checkout/review-order.php` templates previously carried a large
commented-out options renderer, disabled because it duplicated the plugin's
output. They have been removed; options on checkout come from
`Rental_Product_Options_Manager::display_options_in_cart()` via
`woocommerce_get_item_data`.

Nothing in either theme references the script handle, so the module owns its
own assets.

## Session stores

Both stores are written together by `Rental_Options_Selection::persist()`, and
nothing else should write them directly. Writing one without the other is the
drift that produced the original bug.

| Key | Scope |
|-----|-------|
| `<pid>_selected_options` / `<pid>_selected_options_of_set` | Working copy for one product. |
| `rental_product_options_valuables` | Shared map, read by pricing and mini-cart display. |
| `rental_options_failed_selection_<pid>` | A rejected submission awaiting re-prefill. |

All rental session keys are namespaced per cart, so a guest-to-logged-in
transition changes the namespace and reads as empty. The cart line survives
that, which is why it outranks both session stores.

## Extending

Add or remove rules without touching the orchestrator:

```php
add_filter( 'rental_product_options_validation_rules', function ( $rules, $ctx ) {
    $rules[] = new My_Options_Rule();

    return $rules;
}, 10, 2 );
```

A rule implements `Rental_Options_Validation_Rule`: read the context, append to
the result, decide nothing.

Procedural entry points are in `compat-functions.php`:

| Function | Returns |
|----------|---------|
| `rental_options_resolve_selection( $pid, $is_set, $cart_item_key )` | option_id => entry |
| `rental_options_are_satisfied( $pid, $is_set )` | bool |
| `rental_options_default_value_id( $option )` | int, 0 when a choice is required |
| `rental_options_persist_selection( $pid, $selection, $is_set )` | void |

## Diagnosing a blocked add

Every stage writes to `wp-content/uploads/wc-logs/rentopian-options-trace-*.log`
via `Rental_Options_Logger` (the procedural `rental_options_trace()` is a thin
wrapper kept for existing callers):

| Stage | Tells you |
|-------|-----------|
| `get_all_options_resolved` | What the page was rendered with, and from which source. |
| `validate_passed` / `validate_blocked` | What was submitted, what resolved, what stayed unanswered. |
| `post_capture_on_add` | What was written to the cart line. |
| `add_verdict` | The final pass/fail, with the notice text the customer saw. |

`add_verdict` runs last and records the actual notice, so a report of "it
blocked me" can be traced to the callback that raised it rather than guessed.

A `resolved` entry reads `<value_id>:<source>`, e.g. `43:default`, `56:post`.

## Tests

```
wp eval-file wp-content/plugins/rentopian-sync/tests/test-product-options-validation.php
wp eval-file wp-content/plugins/rentopian-sync/tests/test-product-options-repro.php [product_id]
wp eval-file wp-content/plugins/rentopian-sync/tests/test-product-options-cart-isolation.php
```

The first builds its own throwaway products and option rows and covers the
default, precedence and gate matrix. The second replays the reported add-to-cart
failure against a real product. The third finds two published products that
share an option, puts both in the cart and asserts that each row keeps its own
value through edits, through a silent line, and through a cart render; it then
puts one product on two lines and checks the order payload, the saved order
item and the checkout gate. All three read products without writing to them and
clean up only what they created, deleting their own session row by its exact
key.

The browser side has its own harness. Open it as an administrator:

```
/wp-content/plugins/rentopian-sync/tests/test-product-options-gate.php
```

It drives the real script against a synthetic add-to-cart form and asserts that
WooCommerce keeps its own classes in every state, and that an answered option
with no variation chosen is passed through to WooCommerce untouched.

## Related files

| File | Role |
|------|------|
| `includes/product-options/bootstrap.php` | Load order, hook registration, the final-verdict diagnostic. |
| `includes/product-options/class-options-defaults.php` | The default rule. |
| `includes/product-options/class-options-repository.php` | Definition lookup and normalization, memoized per request. |
| `includes/product-options/class-options-selection.php` | The precedence resolver and the session writer. |
| `includes/product-options/class-options-prefill.php` | Re-prefill after a rejected add. |
| `includes/product-options/class-options-archive-gate.php` | Shop-loop behaviour. |
| `includes/product-options/class-options-assets.php` | Registers the script, the stylesheet and the localized page data. |
| `includes/product-options/class-options-logger.php` | The options trace, written to wc-logs/rentopian-options-trace-<date>.log. |
| `includes/product-options/assets/js/product-options.js` | Product-page renderer, cart Options column, order options, the client-side lock. |
| `includes/product-options/assets/css/product-options.css` | Every option-related rule. |
| `includes/product-options/validation/` | Result, context, rule contract, the required rule, the orchestrator. |
| `includes/product-options/compat-functions.php` | Procedural entry points. |
| `includes/class-rental-product-options-manager.php` | Display in cart, checkout and order. Not a gate. |
| `includes/class-rental-options-wc-integration.php` | Secondary display integration. |
