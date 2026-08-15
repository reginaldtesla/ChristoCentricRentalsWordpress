# Christocentric Rentals — WordPress

WooCommerce rental store for **[christocentricrentals.com](https://christocentricrentals.com/)**.

Camera, lens, and lighting gear rented by the day (GHS), with Paystack online payments and pay-on-pickup cash. This repo is the WordPress rebuild; the Laravel app at `../ChristocentricRentals/` stays as reference and data-export source only.

| | URL |
|--|--|
| Local site | http://christocentricrentalswordpress.test |
| Admin | http://christocentricrentalswordpress.test/wp-login.php |
| Production | https://christocentricrentals.com |

---

## Features

- **Shop + categories + search** — catalog with category nav and header/mobile search
- **Daily rental pricing** — per-day rates, pickup/return dates on cart and checkout
- **Promo / sale pricing** — sale daily rates with strikethrough on cards and product pages
- **Rental kits** — bundled products that add multiple items to the cart together
- **Availability holds** — inventory reserved for pickup-cash and abandoned checkouts
- **Payments** — Paystack (cards / mobile money) + Pay on pickup (cash)
- **Static pages** — About, FAQ, Contact, Terms, Privacy, Help
- **Contact form** — posts to admin email with success/error feedback
- **Newsletter** — local subscriber list + optional Mailchimp / webhook sync (FluentCRM, Zapier, etc.)
- **Rentopian sync** — optional inventory sync via API
- **Storefront** — custom `christocentric` theme
- **Homepage CMS** — Advanced Custom Fields (free) via **Appearance → Homepage CMS**
- **Compare products** — up to 4 items, cookie + AJAX
- **Studio booking page** — `/studio/`

---

## Stack

| Layer | Package |
|-------|---------|
| CMS / shop | WordPress + WooCommerce |
| Theme | `wp-content/themes/christocentric` |
| Rentals plugin | `wp-content/plugins/christocentric-rentals` |
| Homepage CMS | `wp-content/plugins/advanced-custom-fields` |
| Online payments | `wp-content/plugins/woo-paystack` |
| Currency / market | GHS · Ghana |

Only those three plugins ship in this project (no Jetpack, MailPoet, ads, or social commerce bloat). Twenty Twenty-Five remains as a WordPress fallback theme.

---

## Requirements

- [Laragon](https://laragon.org/) — Apache, MySQL, PHP **8.1+**
- Database: `christocentric_wp` (user `root`, empty password on Laragon)

---

## Local setup

### 1. Start Laragon

Open Laragon → **Start All**.

### 2. Create the database

```sql
CREATE DATABASE christocentric_wp CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

### 3. Configure `wp-config.php`

```php
define( 'DB_NAME', 'christocentric_wp' );
define( 'DB_USER', 'root' );
define( 'DB_PASSWORD', '' );
define( 'DB_HOST', 'localhost' );

define( 'WP_HOME', 'http://christocentricrentalswordpress.test' );
define( 'WP_SITEURL', 'http://christocentricrentalswordpress.test' );
```

### 4. Install WordPress

Open http://christocentricrentalswordpress.test/ and complete the installer if the database is empty.

### 5. Activate WooCommerce

In **Plugins**, activate **WooCommerce**. Finish the setup wizard:

- Country: **Ghana**
- Currency: **GHS (₵)**
- Guest checkout: **Off** (login required)

### 6. Run project setup

```powershell
cd C:\laragon\www\ChristoCentricRentalsWordpress
php scripts\activate-theme.php
php scripts\setup-christocentric.php
php scripts\check-admin.php
```

This activates the Christocentric theme + plugins, sets store options, and imports products from `migration/woocommerce-products.csv` when present.

### 7. Enable payments & ops

```powershell
php scripts\configure-paystack.php   # loads test keys from Laravel .env
php scripts\ensure-product-stock.php # qty 1 per item (kits use components)
```

**WooCommerce → Settings → Payments**

- **Paystack** — enabled in **test mode** after the script (swap to live keys before production)
- **Pay on pickup (cash)** — enabled

**WooCommerce → Christocentric Rentals**

- Staff booking workflow (Orders list shows rental dates)
- **SMTP** — enable and enter Hostinger mailbox host/user/pass, then send a test email
- Rentopian — leave empty (not required)

Webhook for Paystack (when live): `https://yoursite.com/?wc-api=wc_gateway_paystack`

---

## Project layout

```
ChristoCentricRentalsWordpress/
├── wp-content/
│   ├── themes/christocentric/           # Storefront theme
│   └── plugins/
│       ├── woocommerce/                 # Shop core
│       ├── christocentric-rentals/      # Rentals, holds, newsletter, pickup gateway
│       └── woo-paystack/                # Paystack
├── migration/                           # CSV/JSON/images from Laravel export
├── scripts/                             # CLI setup & checks
├── README.md                            # This file
├── IMPLEMENTATION.md                    # Architecture & phased plan
└── DEPLOYMENT.md                        # Production / Hostinger guide
```

### Christocentric Rentals plugin (`includes/`)

| Class | Role |
|-------|------|
| `CCR_Rental_Pricing` | Daily rate calculation |
| `CCR_Rental_Cart` | Date fields on cart / checkout |
| `CCR_Rental_Availability` | Stock holds |
| `CCR_Pickup_Cash_Gateway` | Pay on pickup |
| `CCR_Rentopian_Sync` | Inventory API sync |
| `CCR_Newsletter` | Subscriber list + emails |
| `CCR_Contact_Form` | Contact handling |
| `CCR_Product_Meta` / `CCR_Settings` | Product meta & admin options |

---

## Useful scripts

Run from the project root with PHP on your PATH (Laragon terminal is fine):

| Command | Purpose |
|---------|---------|
| `php scripts\activate-theme.php` | Switch to `christocentric` theme |
| `php scripts\activate-plugins.php` | Activate WooCommerce + Christocentric plugin |
| `php scripts\setup-christocentric.php` | Full local setup + product import |
| `php scripts\ensure-storefront.php` | Pages, permalinks, sample sales & kits |
| `php scripts\check-admin.php` | Print site URL, plugins, product count |
| `php scripts\check-payments.php` | Payment gateway status |
| `php scripts\setup-newsletter.php` | Newsletter tables / options |
| `.\scripts\copy-assets-from-laravel.ps1` | Copy images from Laravel `public/images` |

---

## Migration data

`migration/` is generated from the Laravel app (often gitignored; regenerate when needed):

```
migration/
  woocommerce-products.csv
  categories.json
  site-settings.json
  pages/
  images/
```

Import products via the setup script, or manually: **WooCommerce → Products → Import**.

---

## Docs

| File | Contents |
|------|----------|
| [IMPLEMENTATION.md](IMPLEMENTATION.md) | Architecture, phases, Laravel → WP mapping |
| [DEPLOYMENT.md](DEPLOYMENT.md) | Hostinger deploy, SSL, Paystack live keys, go-live checklist |

---

## Troubleshooting

| Problem | Fix |
|---------|-----|
| **Error establishing a database connection** | Start Laragon MySQL; create `christocentric_wp` |
| Site opens but looks wrong | Run `php scripts\activate-theme.php` |
| No products | Ensure `migration/woocommerce-products.csv` exists, then run `setup-christocentric.php` |
| Missing payment methods | Activate `woo-paystack` and enable gateways under WooCommerce → Settings → Payments |
| Plugin FTP prompts | Keep `define( 'FS_METHOD', 'direct' );` in local `wp-config.php` |

---

## License

WordPress core is GPL. Custom theme and `christocentric-rentals` plugin code in this project are for Christocentric Rentals.
