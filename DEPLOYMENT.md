# Christocentric Rentals — Production Deployment Guide

Deploy the WordPress project in this folder to replace/update the live store at **[christocentricrentals.com](https://christocentricrentals.com/)**.

The live site today is a **WooCommerce rental shop** (daily rates in GHS, categories like Cameras/Lens/Lighting, account/cart/checkout, newsletter). This project rebuilds that experience with:

| Live site feature | This project |
|-------------------|--------------|
| Product catalog & shop | WooCommerce + 130 imported products |
| Daily rental pricing (₵/Day) | `christocentric-rentals` plugin + date fields on cart |
| Paystack / online pay | **Paystack for WooCommerce** (`woo-paystack`) |
| Pay on pickup | Custom **Pay on pickup (cash)** gateway |
| Newsletter signup | Custom subscriber list + welcome/admin emails |
| Homepage sections (hero, deals, tabs) | `christocentric` theme + `migration/site-settings.json` |
| Contact / About / FAQ / Terms / Privacy | Theme page templates + `migration/pages/*.json` |
| Rentopian inventory sync | `CCR_Rentopian_Sync` in custom plugin |

**Hosting (planned):** [Hostinger](https://www.hostinger.com/) — WordPress hosting with SSL, MySQL, and email. Adjust paths if the client uses a different host.

---

## 1. Before you deploy

### 1.1 Gather credentials

- [ ] Hostinger hPanel login
- [ ] MySQL database name, user, password, host
- [ ] FTP/SFTP or File Manager access
- [ ] Domain DNS (christocentricrentals.com → Hostinger)
- [ ] **Paystack live** public + secret keys ([Paystack Dashboard](https://dashboard.paystack.com))
- [ ] **Rentopian** API key (if inventory sync is required)
- [ ] Business email: `support@christocentricrentals.com` (or Hostinger mailbox)

### 1.2 Verify local site is ready

On Laragon, confirm:

```powershell
cd C:\laragon\www\ChristoCentricRentalsWordpress
php scripts\check-admin.php
php scripts\setup-newsletter.php
```

Expected: WooCommerce active, Christocentric plugin active, 130 products, Paystack active, theme `christocentric`.

### 1.3 What NOT to upload

Exclude from production uploads (or delete on server after upload):

- `wp-content/debug.log`
- `.git/` (if present)
- Local-only `wp-config.php` (create a **new** production `wp-config.php` on the server)
- Unused marketing plugins — do not install Jetpack, MailPoet, ads/social Woo extensions, etc. (see plugins section)

---

## 2. Recommended deployment strategy

Use a **staging subdomain first**, then cut over the main domain.

| Phase | URL | Purpose |
|-------|-----|---------|
| Staging | `staging.christocentricrentals.com` | Test payments, emails, orders |
| Production | `https://christocentricrentals.com` | Go live |

**Why:** Paystack webhooks and SSL must be tested before switching DNS. The current live site keeps running until you point the domain to the new build.

---

## 3. Create the server environment (Hostinger)

### 3.1 WordPress / PHP

1. hPanel → **Websites** → select the site (or create one).
2. **PHP configuration:** PHP **8.1+** (project requires 8.1; 8.2–8.3 recommended).
3. Enable extensions: `mysqli`, `curl`, `gd`, `intl`, `mbstring`, `zip`, `openssl`.

### 3.2 MySQL database

1. hPanel → **Databases** → **MySQL Databases**.
2. Create database + user with **All privileges**.
3. Note: `DB_NAME`, `DB_USER`, `DB_PASSWORD`, `DB_HOST` (often `localhost`).

### 3.3 SSL

1. hPanel → **SSL** → enable **Free SSL** for `christocentricrentals.com`.
2. Force HTTPS (Hostinger “Force HTTPS” toggle or redirect in `.htaccess`).

### 3.4 Document root

Point the domain document root to the folder containing WordPress `index.php` (often `public_html/`).

---

## 4. Upload files

### Option A — File Manager / FTP (simplest)

1. Zip the project locally **excluding** `wp-config.php` and `debug.log`.
2. Upload to `public_html/` and extract.
3. Ensure WordPress files are at the web root (`index.php`, `wp-admin/`, `wp-content/`, etc.).

### Option B — Git (if Hostinger Git deploy is set up)

1. Push repo to GitHub/GitLab.
2. Connect Hostinger Git deployment to `public_html`.
3. Never commit production `wp-config.php` or secrets.

### Required folders on server

```
public_html/
  wp-admin/
  wp-includes/
  wp-content/
    themes/christocentric/          ← custom theme
    plugins/christocentric-rentals/   ← custom rental plugin
    plugins/woocommerce/
    plugins/woo-paystack/
    uploads/                          ← product images
  migration/                          ← site-settings.json, CSV (optional on prod)
  scripts/                            ← setup CLI scripts (optional, protect with .htaccess)
  index.php
  wp-config.php                       ← create on server (§6)
```

### File permissions

- Folders: `755`
- Files: `644`
- `wp-content/uploads/`: writable by web server (`755` or `775` depending on host)

---

## 5. Export local database & import on server

### 5.1 Export from Laragon

```powershell
& "C:\laragon\bin\mysql\mysql-8.4.3-winx64\bin\mysqldump.exe" -uroot christocentric_wp > christocentric_wp_export.sql
```

Or use **phpMyAdmin** (Laragon) → Export → `christocentric_wp`.

### 5.2 Import on Hostinger

1. hPanel → **phpMyAdmin** → select production database → **Import** → upload `.sql`.

### 5.3 Replace URLs (critical)

After import, replace local URLs with production:

**In phpMyAdmin → SQL:**

```sql
UPDATE wp_options
SET option_value = 'https://christocentricrentals.com'
WHERE option_name IN ('siteurl', 'home');

UPDATE wp_posts
SET guid = REPLACE(guid, 'http://christocentricrentalswordpress.test', 'https://christocentricrentals.com');

UPDATE wp_posts
SET post_content = REPLACE(post_content, 'http://christocentricrentalswordpress.test', 'https://christocentricrentals.com');

UPDATE wp_postmeta
SET meta_value = REPLACE(meta_value, 'http://christocentricrentalswordpress.test', 'https://christocentricrentals.com');
```

Also run **Better Search Replace** plugin or [WP-CLI search-replace](https://developer.wordpress.org/cli/commands/search-replace/) if serialized data breaks:

```bash
wp search-replace 'http://christocentricrentalswordpress.test' 'https://christocentricrentals.com' --all-tables
```

---

## 6. Production `wp-config.php`

Create on the server (do **not** copy the Laragon file as-is):

```php
<?php
define( 'DB_NAME', 'YOUR_HOSTINGER_DB_NAME' );
define( 'DB_USER', 'YOUR_HOSTINGER_DB_USER' );
define( 'DB_PASSWORD', 'YOUR_HOSTINGER_DB_PASSWORD' );
define( 'DB_HOST', 'localhost' );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );

/** Generate fresh keys: https://api.wordpress.org/secret-key/1.1/salt/ */
define( 'AUTH_KEY',         '...unique...' );
define( 'SECURE_AUTH_KEY',  '...unique...' );
// ... all 8 salts ...

$table_prefix = 'wp_';

define( 'WP_DEBUG', false );
define( 'WP_DEBUG_LOG', false );
define( 'WP_DEBUG_DISPLAY', false );

define( 'DISALLOW_FILE_EDIT', true );
define( 'FS_METHOD', 'direct' );

/** Production URLs — use HTTPS */
define( 'WP_HOME', 'https://christocentricrentals.com' );
define( 'WP_SITEURL', 'https://christocentricrentals.com' );

if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', __DIR__ . '/' );
}
require_once ABSPATH . 'wp-settings.php';
```

---

## 7. Plugins — what to enable on production

### Must be **active**

| Plugin | Slug | Role |
|--------|------|------|
| WooCommerce | `woocommerce/woocommerce.php` | Shop, cart, orders |
| Christocentric Rentals | `christocentric-rentals/christocentric-rentals.php` | Rentals, pickup cash, Rentopian, newsletter |
| Paystack for WooCommerce | `woo-paystack/woo-paystack.php` | Online payments (GHS, cards, mobile money) |

### Do **not** install marketing bloat

This project keeps only the three plugins above. Do not add Jetpack, MailPoet, Google Listings, Pinterest/Reddit/Snapchat for WooCommerce, PayPal Payments, or Akismet unless the client explicitly needs them.

Re-sync the local stack with:

```powershell
php scripts\setup-christocentric.php
```

---

## 8. WooCommerce production settings

**WooCommerce → Settings → General**

- Store address: Bomso, Kumasi, Ghana
- Currency: **GHS (₵)**
- Selling location: Ghana

**WooCommerce → Settings → Accounts & Privacy**

- Guest checkout: **Off** (login required — matches live site account flow)

**WooCommerce → Settings → Products → Inventory**

- Enable stock management

**Settings → Reading**

- Homepage: static page **Home**
- Permalinks: **Post name** (`/%postname%/`) → Save

**WooCommerce → Christocentric Rentals**

| Setting | Value |
|---------|--------|
| Rentopian API key | From Rentopian |
| Rentopian base URL | `https://api.rentopian.com` |
| Pickup-cash hold | 72 hours |
| Abandoned checkout hold | 2 hours |
| Newsletter from email | `support@christocentricrentals.com` |
| Newsletter notify email | `support@christocentricrentals.com` |

---

## 9. Paystack (production)

Matches how Ghana merchants use Paystack on WooCommerce ([plugin docs](https://woocommerce.com/document/paystack/)).

1. **Plugins** → ensure **Paystack WooCommerce Payment Gateway** is active.
2. **WooCommerce → Settings → Payments → Paystack → Manage**
3. Enable gateway.
4. **Turn off Test Mode.**
5. Enter **Live Public Key** and **Live Secret Key** from [Paystack Dashboard → Settings → API Keys](https://dashboard.paystack.com).
6. Copy the **Webhook URL** shown in Paystack settings.
7. In Paystack Dashboard → **Settings → API Keys & Webhooks** → paste webhook URL.
8. Enable channels: **Card**, **Mobile Money** (MTN MoMo, Telecel, AirtelTigo for Ghana).

Also enable **Pay on pickup (cash)** under Payments for in-person Bomso pickups.

### Test before go-live

Use Paystack **test keys** on staging only. On production, run one small real payment (or Paystack test mode on staging subdomain) and confirm order status becomes **Processing/Completed**.

---

## 10. Email (newsletter + contact + WooCommerce)

Local dev uses Laragon **Mailpit**. Production must send real mail.

### Hostinger email

1. Create mailbox `support@christocentricrentals.com` in hPanel.
2. Install **WP Mail SMTP** (recommended) and configure Hostinger SMTP:
   - Host: `smtp.hostinger.com`
   - Port: `465` (SSL) or `587` (TLS)
   - Auth: mailbox user + password

### Newsletter (custom plugin)

Configured under **WooCommerce → Christocentric Rentals → Newsletter emails**:

- Welcome email to subscriber (includes unsubscribe link)
- Admin notification on new signup

Subscribers list: **WooCommerce → Newsletter** → Export CSV.

Run once after deploy (SSH/terminal on server, or locally before export):

```bash
php scripts/setup-newsletter.php
```

---

## 11. Theme & content checklist

Match [christocentricrentals.com](https://christocentricrentals.com/) structure:

| Page | Slug | Notes |
|------|------|--------|
| Home | `/` | Hero, deals, product tabs, newsletter band |
| Shop | `/shop/` | WooCommerce shop page |
| About | `/about/` | |
| Contact | `/contact/` | Contact form (custom plugin) |
| FAQ | `/faq/` | |
| Help | `/help/` | |
| Terms | `/terms/` | |
| Privacy | `/privacy/` | |

**Appearance → Themes** → **Christocentric** active.

Upload logo: **Appearance → Customize → Site Identity** (use brand assets from `wp-content/uploads/christocentric/brand/`).

---

## 12. Rentopian order sync

When an order is **paid** (Paystack complete, or admin marks pickup order paid):

- Plugin POSTs to `https://api.rentopian.com/orders`
- Pickup-cash orders: **WooCommerce → Orders → Order actions → Mark paid & sync to Rentopian**

Configure API key under **WooCommerce → Christocentric Rentals**.

---

## 13. Security hardening

- [ ] SSL forced (`https://`)
- [ ] Strong admin password; limit admin users
- [ ] `DISALLOW_FILE_EDIT` in `wp-config.php`
- [ ] Confirm only WooCommerce + Christocentric Rentals + Paystack are installed
- [ ] Install **Wordfence** or use Hostinger security tools (optional)
- [ ] Disable `WP_DEBUG` on production
- [ ] Block public access to `/scripts/` (`.htaccess` deny or move outside web root)
- [ ] Regular backups: Hostinger backup or **UpdraftPlus**

---

## 14. Go-live cutover

When staging tests pass:

1. **Backup** current live site (files + database) — Hostinger backup or manual export.
2. Deploy new build to `public_html` (or swap staging → production).
3. Update `wp-config.php` URLs to `https://christocentricrentals.com`.
4. Run URL search-replace (§5.3).
5. **Settings → Permalinks → Save**.
6. Clear Hostinger / plugin cache.
7. Test critical paths (below).
8. Monitor Paystack dashboard for successful charges.

### DNS (if new server)

Point `christocentricrentals.com` A record to Hostinger IP (hPanel → DNS Zone Editor). TTL propagation can take up to 24 hours.

---

## 15. Post-deploy testing checklist

| Test | Expected |
|------|----------|
| Homepage loads over HTTPS | Hero, categories, no mixed content |
| `/shop/` | Products show daily rates |
| Product page | Pickup/return date fields, add to cart |
| Checkout logged in | Paystack + Pay on pickup options |
| Paystack test/live payment | Order → Processing/Completed |
| Pickup cash order | Order → On hold |
| Mark pickup paid | Order → Processing, Rentopian sync if configured |
| Newsletter footer form | Success message + email in admin list |
| Contact form | Email to support@ |
| My Account | Orders list, profile |
| Unsubscribe link | `/newsletter/unsubscribe/{token}/` works |
| Mobile view | Menu, cart, checkout usable |

---

## 16. Useful CLI scripts (run from project root)

| Script | Purpose |
|--------|---------|
| `php scripts/setup-christocentric.php` | WooCommerce GHS/Ghana settings, import products, enable pickup gateway |
| `php scripts/setup-newsletter.php` | Newsletter table + default email options |
| `php scripts/activate-theme.php` | Switch to `christocentric` theme |
| `php scripts/check-admin.php` | Verify plugins, product count |
| `php scripts/import-newsletter-subscribers.php` | Import subscribers from export |
| `php scripts/test-newsletter.php` | Smoke-test subscribe/unsubscribe |

Requires PHP CLI on server (Hostinger SSH) or run locally before DB export.

---

## 17. Rollback plan

If go-live fails:

1. Restore Hostinger backup (files + DB).
2. Re-point DNS if changed.
3. Put site in maintenance mode during fix.

Keep the old site backup for at least 30 days.

---

## 18. Ongoing maintenance

| Task | Frequency |
|------|-----------|
| WordPress + WooCommerce + Paystack plugin updates | Monthly (test on staging first) |
| Database backup | Weekly |
| Review failed orders / Paystack logs | Weekly |
| Export newsletter subscribers | Before major changes |

---

## Quick reference — local vs production

| | Local (Laragon) | Production |
|--|-----------------|------------|
| URL | `http://christocentricrentalswordpress.test` | `https://christocentricrentals.com` |
| DB password | (empty) | Hostinger MySQL password |
| Paystack | Test keys | Live keys + webhook |
| Email | Mailpit `http://localhost:8025` | Hostinger SMTP |
| Debug | `WP_DEBUG` true | `WP_DEBUG` false |

---

## Support contacts (from site config)

- **Support email:** support@christocentricrentals.com  
- **Phone:** (+233) 532 670 582  
- **Pickup:** Bomso, near Abesse Gaming Center, Kumasi, Ghana  

---

*Last updated: July 2026 — aligned with Christocentric Rentals WordPress rebuild and [christocentricrentals.com](https://christocentricrentals.com/) live store features.*
