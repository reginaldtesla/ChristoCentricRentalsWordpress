# Christocentric Rentals — WordPress Implementation Plan

> For day-to-day local setup, see [README.md](README.md).  
> Production steps: [DEPLOYMENT.md](DEPLOYMENT.md).  
> Admin: http://christocentricrentalswordpress.test/wp-login.php

This folder is the **WordPress / WooCommerce** storefront.

---

## Architecture

```
ChristocentricRentals/              ← Laravel (reference + export)
ChristoCentricRentalsWordpress/     ← WordPress (this site)
  wp-content/themes/christocentric/
  wp-content/plugins/christocentric-rentals/
  wp-content/plugins/woocommerce/
  wp-content/plugins/woo-paystack/
  wp-content/plugins/advanced-custom-fields/   ← Homepage CMS (free)
  migration/                        ← CSV/JSON/images from Laravel export
```

| Laravel feature | WordPress approach |
|-----------------|-------------------|
| Shop + products | WooCommerce simple products |
| Daily rental pricing | `_ccr_price_per_day` + cart date fields |
| Availability / holds | `CCR_Rental_Availability` |
| Paystack | woo-paystack |
| Pay on pickup | `CCR_Gateway_Pickup_Cash` |
| Rentopian sync | `CCR_Rentopian_Sync` |
| Homepage CMS | **ACF Free** on private `Homepage CMS` page |
| Compare | `CCR_Compare` (cookie + AJAX, max 4) |
| Newsletter | `CCR_Newsletter` |
| Late returns / holds | Order admin + hold expiry cron |
| Legacy URLs | `CCR_Legacy_Redirects` |

---

## Current status (updated)

| Area | Status |
|------|--------|
| WordPress + WooCommerce + theme | Done |
| Catalog import / daily pricing / kits | Done |
| Paystack (test) + pickup cash | Done locally — swap to live keys for production |
| Homepage (hero, popular, deals, lighting, brands, tabs) | Done — mostly dynamic from products/ACF |
| Compare, Studio, Rank Math SEO | Done |
| Late-return / hold expiry / legacy redirects | Done |
| Homepage CMS (ACF Free) | Done — **Appearance → Homepage CMS** |
| SMTP / forgotten password emails | Form works — **enable SMTP for delivery** |
| Production Hostinger deploy | See DEPLOYMENT.md |
| Rentopian live API key | Optional until confirmed |

---

## Homepage CMS (ACF Free)

Free ACF has no Options Pages, so content lives on a private page:

1. Go to **Appearance → Homepage CMS** (opens the private page editor)
2. Edit fields:
   - Browse-by-category banners
   - Newsletter band copy
   - Trust bar features
   - Brand strip logos

If ACF fields are empty, the theme falls back to live product categories / `migration/site-settings.json`.

---

## Performance defaults

- Shop loop: **12 products per page** + Woo pagination
- Product cards use `woocommerce_thumbnail` (not full-size originals)
- Short cache headers on anonymous catalog pages (`max-age=300`)
- Dequeues unused block/WC block CSS on the storefront

For page caching on Hostinger, enable host/CDN cache (or a caching plugin) after go-live — keep cart/checkout/account excluded.

---

## Go-live checklist (remaining)

1. Enable SMTP and prove password-reset + order emails arrive
2. Switch Paystack to live keys + webhook
3. End-to-end rental checkout (Paystack + pickup)
4. Deploy per DEPLOYMENT.md (SSL, production `wp-config`)
5. Spot-check product categories/images
6. Confirm Laravel URL redirects on production

---

## Quick commands

```powershell
cd C:\laragon\www\ChristoCentricRentalsWordpress
php scripts\activate-theme.php
php scripts\setup-christocentric.php
php scripts\configure-paystack.php
php scripts\ensure-product-stock.php
```

Laravel export (from Laravel app):

```powershell
php artisan catalog:export-wordpress --copy-images
```
