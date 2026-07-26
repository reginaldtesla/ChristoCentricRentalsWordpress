# Christocentric Rentals (WordPress)

WooCommerce rental shop for [christocentricrentals.com](https://christocentricrentals.com/) — daily rates in GHS, Paystack + pay-on-pickup, custom `christocentric` theme and `christocentric-rentals` plugin.

The Laravel app at `../ChristocentricRentals/` is reference + export source only; this folder is the WordPress site.

## Local URLs

| | |
|--|--|
| Site | http://christocentricrentalswordpress.test |
| Admin | http://christocentricrentalswordpress.test/wp-login.php |

## Requirements

- [Laragon](https://laragon.org/) (Apache + MySQL + PHP 8.1+)
- MySQL database `christocentric_wp` (user `root`, empty password by default)

## Quick start

1. **Start Laragon** → Start All (Apache + MySQL).

2. **Create the database** (once):

```sql
CREATE DATABASE christocentric_wp CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

3. **Configure WordPress** — `wp-config.php` should already match Laragon:

```php
define( 'DB_NAME', 'christocentric_wp' );
define( 'DB_USER', 'root' );
define( 'DB_PASSWORD', '' );
define( 'DB_HOST', 'localhost' );
```

Site URL constants point at `http://christocentricrentalswordpress.test`.

4. **Install WordPress** if the DB is empty — open the site URL and complete the installer.

5. **Activate WooCommerce** in **Plugins**, finish the wizard (Ghana / GHS), then run:

```powershell
cd C:\laragon\www\ChristoCentricRentalsWordpress
php scripts\activate-theme.php
php scripts\setup-christocentric.php
php scripts\check-admin.php
```

6. **Payments** — WooCommerce → Settings → Payments: enable Paystack and Pay on pickup (cash).

## What’s in this repo

| Path | Purpose |
|------|---------|
| `wp-content/themes/christocentric/` | Custom storefront theme |
| `wp-content/plugins/` | Only: WooCommerce, Christocentric Rentals, Paystack |
| `migration/` | Product CSV, pages, images (local export; often gitignored) |
| `scripts/` | CLI setup / checks (`setup-christocentric.php`, `check-admin.php`, …) |

## Docs

- [IMPLEMENTATION.md](IMPLEMENTATION.md) — architecture and phased build plan
- [DEPLOYMENT.md](DEPLOYMENT.md) — production / Hostinger deploy guide

## Common issue

**Error establishing a database connection** — Laragon MySQL is stopped, or `christocentric_wp` does not exist. Start MySQL and create the database above.
