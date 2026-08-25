# Rentopian Sync — Heal & Log pipeline (v3)

Self-contained module that lives in `wp-content/plugins/rentopian-sync/includes/orders-heal-log/`.

**Minimum PHP: 7.4** (verified by token-level scan). Compatible up to PHP 8.3.

---

## One-line install

Add a single line to `rentopian-sync.php`:

```php
require_once plugin_dir_path( __FILE__ ) . 'includes/orders-heal-log/bootstrap.php';
```

That's it. The bootstrap:

- Checks PHP version (refuses to load on < 7.4 but doesn't crash the site).
- Auto-discovers and loads `Project_WP_Logger` from the parent `includes/` directory if not already loaded.
- Loads the four helper classes in the correct order.
- Logs a clear error if anything fails to load.

---

## Philosophy

The WC order has **already been created** on the WordPress side when `rental_create_order()` fires. The rule is: **every WC order reaches Laravel**, with TWO documented exceptions.

| Concern | Old behavior | v3 behavior |
|---|---|---|
| Corrupt start date (`"Jun 9:00 AM"`) | Abort | Heal via fallback chain, **send**, email |
| Missing `rental_start_date` cookie | Abort | Use WC order's `date_created`, **send**, email |
| Missing `rental_zip` cookie | Abort | Use billing postcode, **send**, email |
| End < start | Abort | Repair end from start, **send**, email |
| **Empty inventories** | Abort | **Log + email, DO NOT send** (exception (b)) |
| Order already synced | Return | Return (exception (a) — idempotency) |
| Inline `Project_WP_Logger::write()` calls | Scattered | **Removed.** All logging through `Rentopian_Order_Logger` |

### Why empty inventories is now a non-send

Sending an empty-shell order to Laravel would create a malformed quote there — a record with no products, no totals to reconcile, no inventory to allocate. That's worse than not sending: it pollutes the Laravel database and forces manual cleanup on both sides. So for this case we:

1. Log it as `INCIDENT_EMPTY_INVENTORIES_NOT_SENT` (critical level).
2. Queue + dispatch the incident email immediately (so the team knows).
3. Tag the WP order with `_rental_sync_blocked = 1` + `_rental_sync_blocked_reason = empty_inventories` + `_rental_sync_blocked_at` timestamp.
4. Return without calling `rental_send_order()`.

The team gets the email, opens the WP admin, sees the blocked tag, and investigates the underlying product configuration. Nothing silent, nothing corrupting Laravel.

---

## Folder layout

```
plugins/rentopian-sync/
├── rentopian-sync.php        ← add the one require_once line here
└── includes/
    ├── class-logger-with-timer.php   ← existing Project_WP_Logger
    └── orders-heal-log/              ← new module (this directory)
        ├── bootstrap.php             ← entry point (single-line include target)
        ├── class-rental-date-validator.php
        ├── class-rental-data-healer.php
        ├── class-rentopian-incident-reporter.php
        ├── class-rentopian-order-logger.php
        ├── class-rt-delivery-patched.php
        ├── rental_create_order-patched.php
        ├── test-suite.php
        └── README.md
```

---

## Files

| File | Purpose |
|---|---|
| `bootstrap.php` | Entry point. Auto-discovery + load order + version guard. |
| `class-rental-date-validator.php` | Strict validation gate. Detects `"Jun 9:00 AM"` and other malformed shapes. |
| `class-rental-data-healer.php` | Priority-chain fallback for every healable field. |
| `class-rentopian-incident-reporter.php` | Queues incidents, sends one summary email per order, throttled. |
| `class-rentopian-order-logger.php` | Single funnel for all writes. Forces `wc-logs/`. `heal()`, `incident()`, `dispatch_incidents()`. |
| `class-rt-delivery-patched.php` | Drop-in replacement. Never returns empty; defers healing. |
| `rental_create_order-patched.php` | Reference patch — copy the scaffolding changes into your existing function. |
| `test-suite.php` | 26 unit tests. `php test-suite.php` from this folder. |

---

## Architecture

```
┌──────────────────────────────────────────────────────────────────┐
│                    rental_create_order($id)                      │
│                                                                  │
│  Exception (a) — already synced  ──►  return                     │
│                                                                  │
│  1. Cookie decrypt + log presence                                │
│                                                                  │
│  2. Cart iteration  ──►  $inventories                            │
│                                                                  │
│  Exception (b) — empty inventories                               │
│       ├─►  log INCIDENT_EMPTY_INVENTORIES_NOT_SENT               │
│       ├─►  tag _rental_sync_blocked meta                         │
│       ├─►  dispatch incidents (email)                            │
│       └─►  return  (NOT sent to Laravel)                         │
│                                                                  │
│  3. RTDelivery::extractFinalRentalStartEndDate                   │
│                                                                  │
│  4. Rental_Data_Healer::heal_start_date    ┐                     │
│       ├ input (if valid)                   │                     │
│       ├ order meta                         │ Priority            │
│       ├ WC order date_created              │ chain               │
│       └ today + default time               │ per                 │
│                                            │ field               │
│  5. Rental_Data_Healer::heal_end_date      │                     │
│       (with chronology check baked in)     │                     │
│                                            │                     │
│  6. ensure_chronological (safety net)      │                     │
│                                            │                     │
│  7. heal_zip                               ┘                     │
│                                                                  │
│  8. Build $data  ──►  rental_send_order()  ──►  Laravel          │
│                                                                  │
│  9. finally { Rentopian_Order_Logger::dispatch_incidents(); }    │
│                                                                  │
│           ▼                                                      │
│       One summary email per order (throttled)                    │
│       + register_shutdown_function safety net                    │
└──────────────────────────────────────────────────────────────────┘
```

---

## Configuration

```php
// Recipients (comma-separated). babakhani.aarony@gmail.com is always included
// unless the filter below removes it.
update_option( 'rental_incident_email_recipients', 'babakhani.aarony@gmail.com, devops@example.com' );

// Kill switch — logs continue, emails stop.
update_option( 'rental_incident_emails_enabled', 0 );

// Full payload dump per order to wc-logs/ for replay debugging.
update_option( 'rental_log_full_payload', 1 );

// Programmatic recipient addition:
add_filter( 'rental_incident_email_recipients', function( $list ) {
    $list[] = 'oncall@example.com';
    return $list;
} );
```

---

## Sample incident email

**Subject:** `[Rentopian Sync Incident] [2] Order #27451 on kingstonpartyrentals.com — heal:rental_start_date, missing_rental_start_date_cookie`

Body: HTML table with site info, WP admin link, customer info, total, status, and a section per incident with full JSON context. Includes the relative path to today's log file so the recipient can open it via WP file-manager plugin (no FTP).

For empty-inventories blocks, the subject is `… — empty_inventories_not_sent` and the body explicitly states "this order was NOT sent to Laravel."

---

## Sample log output for order #27451 — what runs now

```
ORDER_START | wp_id=27451 | mode=daily
COOKIE | wp_id=27451 | cookie=rental_start_date | present=true | decrypted_ok=false
COOKIE | wp_id=27451 | cookie=rental_zip | present=true | decrypted_ok=true
DATE_CHECK | wp_id=27451 | field=rental_start_date_raw | valid=false | reason=malformed_month_plus_time_only | preview=Jun 9:00 AM
DATE_CHECK | wp_id=27451 | field=rental_end_date_raw | valid=true | preview=2026/06/06 05:00pm
HEAL | wp_id=27451 | field=rental_start_date | source=wc_date_created | reason=malformed_month_plus_time_only | original=Jun 9:00 AM | healed_to=2026/05/23 09:00am
DATES_RESOLVED | wp_id=27451 | start=2026/05/23 09:00am | end=2026/06/06 05:00pm | days=14 | start_healed=true | end_healed=false | chrono_fixed=false
ORDER_SEND | wp_id=27451 | inventories=3 | total=1247.50 | start=2026/05/23 09:00am | end=2026/06/06 05:00pm | has_incidents=true
ORDER_DONE | wp_id=27451 | status=sent | incident_count=1
INCIDENT_DISPATCH_BEGIN | wp_id=27451 | incident_count=1
INCIDENT_DISPATCH_EMAIL_SENT | wp_id=27451 | recipients=1 | incident_types=heal:rental_start_date
```

---

## Sample log output for an empty-inventories block

```
ORDER_START | wp_id=27500 | mode=daily
COOKIE | wp_id=27500 | cookie=rental_start_date | present=true | decrypted_ok=true
COOKIE | wp_id=27500 | cookie=rental_zip | present=true | decrypted_ok=true
ITEM_SKIP | wp_id=27500 | product_id=812 | reason=is_set
ITEM_SKIP | wp_id=27500 | product_id=813 | reason=no_rental_id
INCIDENT_EMPTY_INVENTORIES_NOT_SENT | wp_id=27500 | note=Order has NO syncable inventories. NOT sending to Laravel; manual review required. | cart_items_count=2 | order_items_count=2 | customer=John Doe | customer_email=jd@example.com | wc_order_total=550
ORDER_NOT_SENT | wp_id=27500 | reason=empty_inventories
INCIDENT_DISPATCH_BEGIN | wp_id=27500 | incident_count=1
INCIDENT_DISPATCH_EMAIL_SENT | wp_id=27500 | recipients=1 | incident_types=empty_inventories_not_sent
```

No `ORDER_SEND` line, no call to Laravel. Just a clear audit trail and an email.

---

## Finding healed or blocked orders later

```sql
-- Healed orders (sent to Laravel with substituted data):
SELECT pm.post_id, pm2.meta_value AS healed_at
FROM wp_postmeta pm
JOIN wp_postmeta pm2 ON pm2.post_id = pm.post_id AND pm2.meta_key = '_rental_sync_healed_at'
WHERE pm.meta_key = '_rental_sync_healed' AND pm.meta_value = '1'
ORDER BY pm2.meta_value DESC;

-- Blocked orders (NOT sent — need manual review):
SELECT pm.post_id, pm2.meta_value AS reason, pm3.meta_value AS blocked_at
FROM wp_postmeta pm
JOIN wp_postmeta pm2 ON pm2.post_id = pm.post_id AND pm2.meta_key = '_rental_sync_blocked_reason'
JOIN wp_postmeta pm3 ON pm3.post_id = pm.post_id AND pm3.meta_key = '_rental_sync_blocked_at'
WHERE pm.meta_key = '_rental_sync_blocked' AND pm.meta_value = '1'
ORDER BY pm3.meta_value DESC;
```

---

## PHP 7.4 compatibility

The code deliberately avoids every PHP 8.x-only construct:

| PHP 8.x feature | Used? | Used instead |
|---|---|---|
| `match` expression | No | `switch` / `if-elseif` chains |
| Nullsafe `?->` | No | `isset() ? $obj->method() : null` |
| Named arguments | No | Positional only |
| Constructor promotion | No | Explicit property declarations |
| `readonly` properties | No | `private` properties |
| Enums | No | Class constants |
| `mixed` / `never` return types | No | (no return type / `void`) |
| `str_contains`, `str_starts_with`, `str_ends_with` | No | `strpos() !== false`, `0 === strpos()` |
| Union types `int\|string` | No | (no type hint or single type) |
| `throw` as expression | No | Statement form |
| First-class callable `func(...)` | No | `[Class::class, 'method']` or closures |

`Throwable` (used in `catch (Throwable $e)`) is PHP 7.0+. Verified by token-level scan: zero matches for any 8.x-only construct outside docblock annotations.

---

## Verification

After install, run:

```sh
cd wp-content/plugins/rentopian-sync/includes/orders-heal-log
php test-suite.php
# Expected: Total: 26 passed, 0 failed
```

Tail today's log:

```sh
tail -f wp-content/uploads/wc-logs/rentopian-orders-$(date +%F).log
```


# How to test on local docker env
# Command : 

docker exec -it wp-php-fpm php /usr/share/nginx/html/wp-content/plugins/rentopian-sync/includes/orders-heal-log/test-suite.php

# OR another command :

docker exec -it wp-php-fpm bash -c "cd /usr/share/nginx/html/wp-content/plugins/rentopian-sync/includes/orders-heal-log && php test-suite.php"

# expected output (last few lines) : 

<!-- 
[PASS] empty cookie zip falls back to billing
[PASS] no zip available → empty source

=================
Total: 26 passed, 0 failed 
-->


---

## Additional robustness — suggestions for what comes next

The current fix guarantees that every order either reaches Laravel (with healing if needed) or is explicitly blocked + reported (empty inventories). The list below covers remaining failure modes, ordered roughly by impact.

### Dead-letter queue for failed API sends

`rental_send_order()` can fail for non-data reasons: Laravel API down, network timeout, 5xx response, expired token. Today those failures are silent.

**Recommendation:** persist a row in a new `rental_send_queue` table on every send attempt. Update to `sent` on success or `failed_attempt_N` on failure. A wp-cron job (every 5 min) retries `failed_*` rows with exponential backoff (1m, 5m, 30m, 2h, 12h, 24h), then escalates to `permanent_failure` which fires an incident email. **Single biggest gap right now.**

### Encryption-key rotation grace period

The most likely root cause of `"Jun 9:00 AM"` is `rental_encryption_key` having rotated while a customer's session held cookies encrypted with the old key. `decrypt_data()` then returned garbage and the corruption pipeline kicked in.

**Recommendation:** add an option `rental_encryption_key_previous`. When the active key fails to decrypt, fall back to the previous key. Auto-expire after 7 days. Update `decrypt_data()` to try both, log which one succeeded.

### Pre-checkout validation

Block invalid checkout submissions server-side via `woocommerce_after_checkout_validation` — surface a user-friendly error before the WC order is even created. The healer stays as the safety net.

### Inventory reconstruction from order items

If `WC()->cart` is empty by the time the function fires (some payment gateways clear it early), `$inventories` is empty even though the order has line items. Rebuild from `$wc_order->get_items()` using the rental metadata copied to item meta. If reconstruction succeeds, send normally; if it fails, fall back to the empty-inventories block path.

### Webhook delivery alongside email

Emails get filtered, lost, or ignored. Add `rental_incident_webhook_urls` (comma-separated). On every email, POST the same incident JSON to each webhook. Slack / Discord / Teams / PagerDuty all consume JSON webhooks. Lower MTTR for critical incidents.

### Idempotency key on the Laravel side

The current "already synced" check uses the WP-side relation table. If that table loses a row (DB restore, migration), the WP order re-syncs and Laravel gets a duplicate. Send an idempotency key in the API payload: `sha256(site_url . '|' . wp_order_id . '|' . wp_order_created)`. Laravel checks before creating; if seen, returns the existing rental_id.

### Healthcheck endpoint

Add a WP REST route `/wp-json/rentopian/v1/healthcheck` (admin-only) returning last_successful_send, dlq_count, healed_orders_last_24h, blocked_orders_last_24h, incident_emails_sent_last_24h, log_dir_writable, encryption_key_age_days. Pair with an external monitor (Pingdom / UptimeRobot / Better Stack).

### Per-order incident meta box in WP admin

Add a meta box to the order edit screen showing `_rental_sync_healed` / `_rental_sync_blocked` status, the healing context (parsed from log or stored as JSON meta), and a "Mark reconciled" button. Saves the team from grepping logs.

### Async incident email via wp-cron

The current synchronous `wp_mail()` adds 0.5-3 seconds when SMTP is slow. Move to `wp_schedule_single_event( time(), 'rentopian_dispatch_incident_email', [$order_id, $incidents] )`. Serialize the incidents queue to a transient or DB row since cron runs in a different request.

### Log retention cron

Daily-rotated logs accumulate forever. Add a daily wp-cron that deletes files older than 30 days from `wc-logs/`. ~15 lines. Without this, busy sites end up with thousands of files.

### Periodic reconciliation digest

Once a day, query for healed/blocked orders with no `_rental_reconciled` meta. Email a digest: "47 healed orders, 2 blocked orders in the last 24h — review and mark reconciled." Pairs with #8.


### Sentry / error-tracking integration

`Rentopian_Order_Logger::order_exception()` already structures the data. Adding an optional Sentry / Bugsnag / Rollbar reporter (gated by `class_exists()`) gives the team a unified incident UI and per-exception aggregation.

### Order-side duplicate detection

If a WC order line item is unintentionally duplicated, Rentopian gets the dupe. Add `md5(inventories_sorted)` per-order. If a previous send for this order had the same hash, log and skip the API call.