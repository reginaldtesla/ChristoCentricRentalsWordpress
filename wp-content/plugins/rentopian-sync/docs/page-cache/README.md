# Page cache guard

Keeps the rental date gate correct on a site fronted by a full page cache.

## The problem it solves

Which button a product page shows — *Select Rental Dates* or *Add to Cart* — is
decided in PHP from the visitor's date cookies, in `rental_hide_add_to_cart_button()`.
That decision is then baked into HTML which, by default, carries **no cache
headers of any kind**: no `Cache-Control`, no `Expires`, no `Last-Modified`.
Nothing states how long it stays valid, so every layer that can hold a copy is
free to reuse one — the visitor's own browser first of all, and any proxy or
cache plugin in front of it. A reused copy never runs PHP, so it keeps showing
the decision that was made for whoever fetched it first.

This does not need a caching plugin to happen, and it is easy to misread as a
cookie fault: a customer chooses dates, the page reloads, and the button that
comes back is the one from the copy the browser already had.

It also runs in reverse. A copy stored while somebody had dates offers
*Add to Cart* to a visitor who has none, who then gets
"Sorry, this product is not available." when they click it.

Whether it looks broken depends on which state got stored first, which is why
the same site can seem fine for staff and broken for customers: staff usually
have dates set before they ever open a product page.

## What it does

Three independent measures, none tied to a particular host:

1. **The page states its own rules.** A response produced for a visitor who has
   dates is marked uncacheable — `Cache-Control: no-store`, plus
   `DONOTCACHEPAGE`, `X-Accel-Expires`, `X-Cache-Enabled` and
   `X-LiteSpeed-Cache-Control`, so browsers, proxies and cache plugins are all
   covered without having to know which one is present. **This is the measure
   that does the work**; the other two are there for layers it cannot reach.
2. **Shared caches let the visitor through.** As soon as the date form is
   accepted they are given a WooCommerce session cookie, which every common page
   cache already treats as a reason to skip the cache. This matters only where a
   server-side cache is in play — it does nothing for a browser's own copy.
3. **The browser repairs a stale copy.** `rental-page-cache-sync.js` compares
   the decision baked into the HTML (`#rntp-form-holder[data-has-start-date]`)
   with the `rental_form_filled` cookie, which is readable from JavaScript. If
   they disagree the page reloads once with a `rntp_nc` argument no cache can
   answer. This is the only measure that can rescue a copy stored *before* the
   headers existed, and the only one that survives a layer ignoring measure 1.

Note that measure 1 only governs responses the server actually sends. A copy a
browser already holds was stored under the old, header-less rules, so the very
first visit after an update can still come from it — measure 3 is what closes
that gap.

Measure 3 is capped at one reload per page per tab. If a cache ignores the query
string, a visitor without dates has the add-to-cart controls hidden instead, so
they cannot start an order that the server will refuse.

Visitors who have **not** chosen dates are left fully cacheable — that is the
correct page for them, and it keeps the cache worth having for new traffic,
crawlers and search engines.

## Deliberately inactive in dates-on-checkout mode

When `rental_dates_on_checkout` and `rental_allow_overbook` are both on, the
plugin seeds date cookies for every visitor and does not gate add to cart on
them. Treating those visitors as personalized would cost the site its cache for
no benefit, so the whole guard stands down.
