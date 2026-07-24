admin ligin :: http://christocentricrentalswordpress.test/wp-login.php?loggedout=true&wp_lang=en_US


# Christocentric Rentals — WordPress Implementation Plan

This folder is the **new WordPress site**. The Laravel app at `../ChristocentricRentals/` stays untouched — we **copy** data and assets from it when needed.

---

## Architecture

```
ChristocentricRentals/              ← Laravel (keep as reference + export source)
ChristoCentricRentalsWordpress/     ← WordPress (this folder)
  wp-content/plugins/christocentric-rentals/   ← Custom rental + Rentopian plugin
  migration/                        ← Exported CSV/JSON from Laravel (generated)
```

| Laravel feature | WordPress approach |
|-----------------|-------------------|
| Shop + products | **WooCommerce** simple products |
| Daily rental pricing | Custom plugin: `_ccr_price_per_day` + date fields on cart |
| Availability / holds | Custom plugin: `CCR_Rental_Availability` |
| Paystack | **Paystack for WooCommerce** plugin (official) |
| Pay on pickup | Custom plugin: `CCR_Gateway_Pickup_Cash` |
| Rentopian sync | Custom plugin: `CCR_Rentopian_Sync` |
| Homepage CMS | **ACF Pro** options pages (or Kadence + custom fields) |
| Compare products | Phase 2 — small custom plugin or theme JS |
| Newsletter | MailPoet / Newsletter plugin, or Contact Form 7 + list |
| Admin returns/penalties | Phase 2 — WooCommerce order meta + admin UI |

---

## Phase 1 — Local setup (do this first)

### 1. Create MySQL database

```sql
CREATE DATABASE christocentric_wp CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

### 2. Configure WordPress

Copy `wp-config-sample.php` → `wp-config.php` and set:

```php
define('DB_NAME', 'christocentric_wp');
define('DB_USER', 'root');
define('DB_PASSWORD', 'your_password');
define('DB_HOST', 'localhost');
```

Generate salts: https://api.wordpress.org/secret-key/1.1/salt/

### 3. Run WordPress installer

Open in browser:

```
http://localhost/ChristocentricRentals/ChristoCentricRentalsWordpress/
```

Complete the 5-minute install. Use a strong admin password.

### 4. Install required plugins

| Plugin | Purpose |
|--------|---------|
| **WooCommerce** | Products, cart, checkout, orders |
| **Paystack for WooCommerce** | Online payments (GHS) |
| **Christocentric Rentals** | Already in `wp-content/plugins/` — activate after WooCommerce |
| **Advanced Custom Fields (ACF)** | Homepage/footer CMS (Phase 2) |
| **Contact Form 7** or **WPForms** | Contact page |
| **Yoast SEO** or **Rank Math** | SEO (optional) |

### 5. WooCommerce setup wizard

- Store country: **Ghana**
- Currency: **GHS (₵)**
- Enable guest checkout: **No** (match Laravel — login required)
- Pages: let WooCommerce create Shop, Cart, Checkout, My Account

### 6. Configure Christocentric Rentals plugin

**WooCommerce → Christocentric Rentals**

- Rentopian API key (when Rentopian confirms)
- Rentopian base URL: `https://api.rentopian.com`
- Pickup-cash hold: **72 hours**
- Abandoned checkout hold: **2 hours**

**WooCommerce → Settings → Payments**

- Enable **Paystack**
- Enable **Pay on pickup (cash)**

---

## Phase 2 — Copy data from Laravel (read-only export)

From the Laravel folder (does **not** modify Laravel):

```powershell
cd C:\Apache24\htdocs\ChristocentricRentals\ChristocentricRentals
php artisan catalog:export-wordpress --copy-images
```

This creates:

```
ChristoCentricRentalsWordpress/migration/
  woocommerce-products.csv    ← Import in WooCommerce
  categories.json
  site-settings.json          ← Homepage/footer/contact defaults
  pages/*.json                ← About, FAQ, Terms, etc.
  images/                     ← Product + brand images
```

### Import products

1. **WooCommerce → Products → Import**
2. Upload `migration/woocommerce-products.csv`
3. Map columns; ensure custom meta columns import (may need **Product Import Export for WooCommerce** for meta, or use WP-CLI script below)

### Copy all media (alternative / full tree)

```powershell
.\scripts\copy-assets-from-laravel.ps1
```

Copies `ChristocentricRentals/public/images/` → `wp-content/uploads/christocentric/` without touching Laravel.

---

## Phase 3 — Theme & design

The Laravel site uses Tailwind + custom Blade. For WordPress:

**Option A (recommended):** Child theme of **Kadence** or **Blocksy** + custom CSS copied from Laravel `resources/css/app.css` (colors, product cards, header).

**Option B:** Custom block theme `christocentric-rentals-theme` — more work, full control.

**Copy from Laravel (reference only):**

| Laravel | Use in WordPress |
|---------|------------------|
| `resources/views/components/header.blade.php` | Theme header template |
| `resources/views/components/product-card.blade.php` | WooCommerce loop template override |
| `public/images/brand/logo.png` | Site logo (Customizer) |
| `config/site.php` | ACF options (hero slides, footer, newsletter) |

Homepage sections to rebuild:

- Hero slider
- Deals / weekly picks
- Product tabs (new / featured / top rated)
- Newsletter band
- Trust bar + brand strip

---

## Phase 4 — Rentopian integration

Already implemented in `christocentric-rentals` plugin — mirrors Laravel `RentopianSyncService.php`.

**When it fires:** Order is **paid** (Paystack complete, or admin marks pickup order paid).

**Endpoint:** `POST {base_url}/orders`

**Payload:** Same as Laravel — `website_url`, `order_number`, `customer`, `rental_start`, `rental_end`, `total`, `items[]`.

**Pickup cash:** Order stays `on-hold` until staff uses **Order actions → Mark paid & sync to Rentopian**.

**Configure:** WooCommerce → Christocentric Rentals → API key.

---

## Phase 5 — Pages & content

Create WordPress pages and assign templates:

| Page | Slug | Content source |
|------|------|----------------|
| Home | `/` | Front page + ACF |
| Shop | `/shop/` | WooCommerce shop page |
| About | `/about/` | `migration/pages/about.json` |
| FAQ | `/faq/` | `migration/pages/faq.json` |
| Help | `/help/` | `migration/pages/help.json` |
| Terms | `/terms/` | `migration/pages/terms.json` |
| Privacy | `/privacy/` | `migration/pages/privacy.json` |
| Contact | `/contact/` | Contact form + address from site-settings |

---

## Phase 6 — Production (Hostinger)

1. Upload `ChristoCentricRentalsWordpress/` to hosting (or deploy via Git)
2. Point domain document root to this folder
3. Import DB or run WordPress install on server
4. Set `wp-config.php` with production DB + `DISALLOW_FILE_EDIT`
5. Install SSL (Hostinger Auto SSL)
6. Configure Paystack **live** keys in WooCommerce
7. Configure Hostinger SMTP (WP Mail SMTP plugin)
8. Set Rentopian API key
9. Run product import on production
10. Test full flow: browse → dates → cart → Paystack + pickup cash

---

## Phase 7 — Post-launch (nice to have)

- [ ] Product compare (session, max 4)
- [ ] Late return penalties in admin
- [ ] Paystack webhook handler (`charge.success`)
- [ ] Expired pickup-cash auto-cancel cron
- [ ] Queued order emails
- [ ] Shop pagination / performance
- [ ] Redirect old Laravel URLs if any were indexed

---

## What stays in Laravel

The Laravel app is **not deleted**. Keep it for:

- Reference implementation of business rules
- Re-exporting catalog if products change
- Running `catalog:apply-descriptions` then re-exporting
- Fallback during migration testing

---

## Quick commands reference

```powershell
# Export Laravel → WordPress migration files
cd ChristocentricRentals
php artisan catalog:export-wordpress --copy-images

# Copy image tree to WordPress uploads
cd ..\ChristoCentricRentalsWordpress
.\scripts\copy-assets-from-laravel.ps1
```

---

## Current status

| Item | Status |
|------|--------|
| WordPress core | Installed in this folder |
| Custom plugin (rental, pickup, Rentopian) | Scaffolded |
| Laravel export command | `php artisan catalog:export-wordpress` |
| WooCommerce | **You install** via WP admin |
| Theme matching Laravel design | **Phase 3 — not started** |
| Product import | **Run export, then import CSV** |
| ACF homepage CMS | **Phase 3 — not started** |
