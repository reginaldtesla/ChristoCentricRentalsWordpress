# Background Data Sync — Documentation

**Component:** Rentopian Sync — Background Catalog Data Sync Module
**Audience:** Developers, technical maintainers
**Module path:** `includes/data-sync/`
**Conventions:** This document follows the same single-source structure as `file-sync/README.md` so both background sync modules read the same way.

---

## Table of contents

1. [Introduction](#1-introduction)
2. [Prerequisites](#2-prerequisites)
3. [Architecture](#3-architecture)
4. [Phases and the cursor](#4-phases-and-the-cursor)
5. [Database schema](#5-database-schema)
6. [Class reference](#6-class-reference)
7. [Workflows](#7-workflows)
8. [REST API endpoints](#8-rest-api-endpoints)
9. [Admin AJAX endpoints](#9-admin-ajax-endpoints)
10. [Admin UI](#10-admin-ui)
11. [WP Options reference](#11-wp-options-reference)
12. [Logging](#12-logging)
13. [Email reporting](#13-email-reporting)
14. [Error handling and safety rails](#14-error-handling-and-safety-rails)
15. [Relationship to the legacy sync and the file sync](#15-relationship-to-the-legacy-sync-and-the-file-sync)
16. [Testing checklist](#16-testing-checklist)
17. [Troubleshooting](#17-troubleshooting)

---

## 1. Introduction

### 1.1 Purpose

The legacy catalog sync (`rental_synchronization()` in `functions.php`, reached via
`action=rental_sync`) imports the **entire** catalog inside one AJAX request. It pages
`/products` and `/products/variants` but `array_merge`s every page into memory, holds
every other entity as a full dump, and builds giant SQL-string arrays multiplied per
division. It raises the time limit but never the memory limit, so on catalogs of large number of products (1500+ items)  PHP exhausts `memory_limit` and WordPress renders
*"There has been a critical error on this website."*

This module replaces that approach for large catalogs with a **chunked background sync**
that keeps the legacy **wipe-first** guarantee:

- Work is split into small steps; each HTTP callback does one bounded unit of work.
- Memory stays flat regardless of catalog size.
- The catalog is **wiped once up front**, then rebuilt — so every field matches the feed.
- The wipe only happens after the feed has been proven readable.
- Every chunk is idempotent, so replays are harmless.

### 1.1a Why wipe-first

The `api.php` handlers populate several fields **only on the create path**. An in-place
upsert therefore leaves them permanently stale, whatever the feed says:

| Field | Symptom when only updated |
|-------|---------------------------|
| Product type (`simple`/`variable`) | A product that gains attributes keeps rendering with no variation dropdowns |
| Variant relation for a simple product | A phantom `product_variation` is created and never removed |
| `_rental_is_sale` | A product flipped rental ↔ sale never changes on the storefront |
| `_rental_by_interval` / `_rental_by_slot` on parents | Interval/slot pricing mode goes stale |
| `wc_product_meta_lookup` `min_price`/`max_price` | The parent price range only ever widens, never narrows |

Rebuilding from an empty catalog forces every record through the create path, which fixes
all of them by construction — no handler changes required.

**The trade-off is explicit:** the storefront is empty for the duration of a run, and a run
that dies *after* the purge leaves a partial catalog until a later run completes. That is
the accepted cost of the freshness guarantee.

### 1.2 Design principles

| Principle | How it is achieved |
|-----------|--------------------|
| **Never hold the catalog in memory** | One API page per callback; variant rows staged to per-product JSONL files on disk. |
| **Never wipe blindly** | The purge probes the product and variant feeds first; an unreadable feed aborts the step with the catalog intact. |
| **Wipe exactly once** | The purge is guarded by a per-run `purged` flag, so a replay cannot empty a catalog that is already being rebuilt. |
| **Idempotent** | Every write is keyed on the Rentopian id; chunks may be delivered more than once. |
| **Identical results to webhooks** | Writes go through the existing `api.php` webhook handlers, fed webhook-shaped payloads. |
| **Reuse, don't fork** | The Laravel chunk driver, the file-sync module, the webhook handlers **and the classic `rental_empty_*` wipe helpers** are reused unchanged. |

### 1.3 Glossary

| Term | Meaning |
|------|---------|
| **Run** | One background data sync, identified by a `sync_id` (UUID v4). |
| **Phase** | A stage of the run (config, taxonomies, variants, products, sets, extras, sweep, finalize). |
| **Cursor** | A single integer encoding *phase + offset within phase*; it is what Laravel advances. |
| **Chunk / step** | One bounded unit of work performed by one REST callback. |
| **Chain** | Automatically starting the background file sync after the data phase completes. |
| **Sweep** | The guarded deletion pass that removes records the feed no longer contains. |
| **Staging** | Per-run working files under `uploads/rentopian-data-sync/<sync_id>-<hash>/`. |

### 1.4 What a run actually does

```
config → purge → taxonomies → variants (staged) → products → sets → extras → sweep → finalize
   │        │                                                          (skipped)        │
   │        └── wipes the catalog, only after the feed reads clean                      │
   └── proves the API is reachable before anything is deleted            chain (always) ▼
                                                                     background file sync
                                                                                  │
                                                                                  ▼
                                                                       report email sent
```

---

## 2. Prerequisites

- WooCommerce active (the module logs through the WooCommerce logger).
- A valid Rentopian API key saved as `rental_api_key`.
- The WP REST API reachable from the Rentopian (Laravel) server — it calls back into WP.
- The Laravel queue worker consuming the `webhooks` queue; without a worker no chunks are dispatched.
- `includes/file-sync/` present — the data sync chains it and reuses its session manager.

No Laravel-side changes are required. See §3.2.

---

## 3. Architecture

### 3.1 Module location

```
includes/data-sync/
├── bootstrap.php                        Loads classes, registers hooks/routes
├── class-data-sync-logger.php           wc-logs writer + per-run log files
├── class-data-sync-status.php           Status/phase constants, cursor math, progress weights
├── class-data-sync-session.php          Option-backed session, locks, heartbeat, stats, staging
├── class-data-sync-run-repository.php   `rental_data_sync_run` table (one row per run)
├── class-data-sync-watchdog.php         Preflight checks, stall diagnosis, auto-fail
├── class-data-sync-integrity.php        Repairs WordPress-side deletions (relations, attributes)
├── class-payload-composer.php           Pull-API rows → webhook-shaped payloads
├── class-record-writer.php              Feeds payloads to the api.php webhook handlers
├── class-data-sync-phases.php           One method per phase; the actual work
├── class-data-sync-chunk-worker.php     Cursor router; runs one step per callback
├── class-data-sync-scheduler.php        Start / cancel / chain the file phase
├── class-data-sync-rest-controller.php  Endpoints Laravel calls
├── class-data-sync-reporter.php         Combined email report
├── class-data-sync-ajax-controller.php  Admin AJAX (start, stop, status, run history)
└── assets/
    ├── css/data-sync-admin.css          Admin panel styling
    └── js/data-sync-admin.js            Admin panel behaviour
```

### 3.2 Who drives the loop

**Rentopian drives it, not WordPress.** This mirrors the file sync exactly, and reuses the
same Laravel machinery with **zero Laravel changes**:

1. WP calls `POST /api/v1/sync/start` **once**, passing its own callback URL
   (`/wp-json/rentopian-sync/v1/data-sync/process-chunk`).
2. Laravel queues `ProcessChunkJob`, which POSTs to that URL asking WP to process a chunk.
3. WP performs **one step**, then replies `{ success, last_index, completed }`.
4. Laravel reads the reply and queues the next job at `last_index`.
5. Repeat until WP replies `completed: true`.

`ProcessChunkJob` is endpoint-agnostic — it only relays `last_index`/`completed` — so
pointing it at a different WP endpoint is all that is needed. In exchange the module
inherits Laravel's queue retries, backoff, stall detection (`last_index` must strictly
increase), cancellation and self-heal.

**Consequence:** Laravel enforces **one sync session per company**
(`sync/start` cancels previous sessions). The data phase and the file phase therefore
**cannot** run at the same time — which is exactly the required order, since images can
only attach to products that already exist.

### 3.3 Load order

`rentopian-sync.php` requires `includes/data-sync/bootstrap.php`, which loads:

```
class-data-sync-logger.php          (no deps — everything logs through it)
class-data-sync-status.php          (constants + cursor math)
class-data-sync-session.php         → status
class-data-sync-run-repository.php  → status, logger
class-payload-composer.php          (pure transformation)
class-record-writer.php             → composer, logger, api.php handlers
class-data-sync-phases.php          → session, composer, writer, sets module, functions.php helpers
class-data-sync-chunk-worker.php    → phases, session, run repository
class-data-sync-scheduler.php       → session, run repository, file-sync scheduler
class-data-sync-rest-controller.php → chunk worker, session
class-data-sync-reporter.php        → session, scheduler
class-data-sync-ajax-controller.php → scheduler, session, run repository
```

Hooks registered by `bootstrap.php`:

| Hook | Callback | Purpose |
|------|----------|---------|
| `rest_api_init` | `Rental_Data_Sync_REST_Controller::register_routes` | Endpoints Laravel calls |
| `admin_init` | `Rental_Data_Sync_Run_Repository::install` | Create the run table when missing |
| `admin_init` | `Rental_Data_Sync_Watchdog::tick` | Fail abandoned runs, purge orphan staging |
| `rental_data_sync_chain_files` | `Rental_Data_Sync_Scheduler::maybe_chain_file_sync` | Deferred file-sync chain (cron) |
| `init` | `Rental_Data_Sync_Scheduler::maybe_chain_file_sync` | Fallback when WP-Cron is unreliable |
| `rental_file_sync_completed` | `Rental_Data_Sync_Reporter::on_file_sync_completed` | Send the combined report |

---

## 4. Phases and the cursor

### 4.1 Cursor encoding

Laravel treats a non-advancing `last_index` as a stall (5 strikes → session FAILED). A
single monotonically increasing integer is therefore required across the whole run, not
just within a phase:

```
cursor = phase × 1 000 000 + offset-within-phase
```

`Rental_Data_Sync_Status::cursor()`, `cursor_phase()` and `cursor_offset()` encode and
decode it. `PHASE_BASE` is `1000000`.

### 4.2 Phase table

| # | Phase | Source | Steps | What it writes |
|---|-------|--------|-------|----------------|
| 0 | `config` | `settings/company`, `divisions`, `referral_sources`, `event_types`, `payment_tips`, `shipping/settings` | 1 | Options only (reuses the legacy helpers in `functions.php`) |
| 1 | `purge` | `products`, `products/variants` (1-row probe only) | 1 | **Deletes** — the full catalog wipe (see §4.2a) |
| 2 | `taxonomies` | `products/attributes`, `.../values`, `products/categories`, `products/tags`, `products/brands`, `inventories/sets/tags` | 3 | Attributes, attribute values, categories, brands; builds the composer maps |
| 3 | `variants-staging` | `products/variants?start&limit` | 1 per page | **Nothing** — rows are staged to disk per product |
| 4 | `products` | `products?start&limit` | 1 per page | Products **and** their variants, via the webhook handlers |
| 5 | `sets` | `inventories/sets`, `inventories/sets/options` | 2 | Sets (delete + rebuild), set options, set tags |
| 6 | `extras` | `coupons`, `price_multipliers`, `inventories/blocked`, `products/options` | 1 | Small full rebuilds using the legacy `rental_empty_* + rental_add_*` pairs |
| 7 | `sweep` | none | 1 | Skipped after a wipe — nothing can be orphaned (see §14.3) |
| 8 | `finalize` | `products/categories` | 1 | Category order, cache clear, chain the file sync, record success |

### 4.2a The purge phase

Runs the classic sync's wipe prelude, in the same order, by **calling** the shared helpers
— they are not modified, so the legacy sync keeps its exact behaviour:

```
rental_empty_inventory_blocks()    rental_empty_coupons()
rental_empty_product_options()     rental_empty_price_multipliers()
rental_empty_set_options()         rental_empty_products()
rental_empty_shipping_zones()      (unless rental_do_not_use_rentopian_shipping)
rental_empty_yoast()               (only when WPSEO_VERSION is defined)
```

Two safety rails:

1. **Viability probe.** One row is fetched from `products` and from `products/variants`
   first. `fetch_page()` throws on a body that is not a list, so an unreadable feed aborts
   the step **before** any deletion; the driver retries with backoff and turns terminal
   after `MAX_CONSECUTIVE_ERRORS`. A `5xx` from the variant feed (an inactive-company key)
   is terminal immediately, matching the variants phase.
2. **Once per run.** Completing the wipe sets the session flag `purged`. A replayed chunk
   sees the flag and returns without deleting, so a catalog already being rebuilt is never
   emptied a second time.

**Images survive the wipe.** `rental_empty_products()` keeps `rental_image_relations` rows
whose attachment still exists and never deletes attachment posts, so the chained file sync
reuses the media it already downloaded
(`Rental_Image_Downloader::resolve_existing_attachment()`) instead of refetching the whole
library. Completeness does not depend on that: `Rental_Image_Integrity::repair()` clears
every reference to a missing attachment before the file phase, the attacher repairs broken
thumbnails and galleries, and the report email lists anything still without an image.

Product and variation **post IDs change on every run** (delete + reinsert), exactly as with
the classic sync. Attachment IDs do not.

### 4.3 Why variants are staged before products

The product writer needs a product's inventory rows to build its payload (price, stock,
attributes, and the per-division split). Fetching them per product would be an N+1 against
the API. Instead the variants phase walks `/products/variants` once, bucketing rows into
`variants-<product_id>.jsonl` files inside the run's staging directory. The products phase
then reads one small file per product and deletes it immediately after use.

This keeps memory flat: at no point is more than one API page plus one product's variants
in memory.

---

## 5. Database schema

### 5.1 `{prefix}rental_data_sync_run`

Created by `Rental_Data_Sync_Run_Repository::install()` on `admin_init`, guarded by the
`rental_data_sync_db_version` option. The version is stamped **only after** the table is
confirmed to exist, so a silent `dbDelta` failure retries instead of hiding.

**One row per run — metadata only.** The line-by-line trace of every phase lives in the
per-run log file (§12), so this table stays small enough to list hundreds of runs without
loading any of their contents.

| Column | Type | Purpose |
|--------|------|---------|
| `id` | BIGINT UNSIGNED AUTO_INCREMENT | Primary key |
| `sync_id` | VARCHAR(64), unique | Run identifier |
| `status` | TINYINT | 1 created, 2 processing, 3 completed, 4 failed, 5 canceled |
| `phase` | TINYINT | Phase reached |
| `processed_count` | INT UNSIGNED | Rows processed |
| `failed_count` | INT UNSIGNED | Records the writers could not import |
| `message` | TEXT | Outcome summary or failure reason |
| `file_sync_id` | VARCHAR(64) | The chained file sync |
| `started_by` | BIGINT UNSIGNED | WP user who started the run |
| `started_at` / `updated_at` / `finished_at` | DATETIME | Lifecycle timestamps |
| `duration` | INT UNSIGNED | Seconds from start to finish |
| `register_time` | INT UNSIGNED | Unix timestamp of the start |

Installing version `2` drops the superseded `{prefix}rental_data_sync_log` table, which
held one row per phase step.

### 5.2 Tables written by the run

The module creates no catalog tables of its own. It writes through the webhook handlers
into the standard WordPress/WooCommerce tables (`posts`, `postmeta`, `terms`,
`term_relationships`, `wc_product_meta_lookup`) and the plugin's relation tables
(`rental_product_relations`, `rental_variant_relations`, `rental_set_relations`,
`rental_category_relations`, `rental_brand_relations`, …).

Relation tables are the source of truth mapping **Rentopian id → WP post/term id**, and are
what both the upsert lookups and the sweep are keyed on.

---

## 6. Class reference

### 6.1 `Rental_Data_Sync_Logger`

Single logging entry point, writing to two destinations at once:

1. The wc-logs source `rentopian-data-sync`, listed in **WooCommerce → Status → Logs**.
2. The **bound run's own file**, `uploads/rentopian-data-sync/logs/<sync_id>-<hash>.log`,
   which is what the admin panel tails, downloads and deletes.

```php
Rental_Data_Sync_Logger::bind_run( $sync_id );                       // scheduler / worker
Rental_Data_Sync_Logger::write( $message, $level, $channel, $sync_id = '' );
Rental_Data_Sync_Logger::tail( $sync_id, $lines );                   // reverse block read
Rental_Data_Sync_Logger::run_log_path( $sync_id, $create = true );
Rental_Data_Sync_Logger::delete_run_log( $sync_id );
```

`$channel` is a short stage tag (`rest`, `phase`, `write`, `sweep`, `report`, `chunk`,
`schedule`, `chain`, `watchdog`) prefixed to the line so a single stage can be grepped.

`bind_run()` means call sites deep in the phase engine do not have to pass the sync id.

Both filenames carry a salt-derived hash so neither can be fetched over HTTP by guessing
the URL; the log directory also gets an `index.html` and a `Deny from all` `.htaccess`.

`tail()` reads the file backwards in 8 KB blocks, so rendering the panel never loads a
multi-megabyte log into memory.

### 6.2 `Rental_Data_Sync_Status`

Constants and cursor math.

```php
STATUS_CREATED = 1;  STATUS_PROCESSING = 2;  STATUS_COMPLETED = 3;
STATUS_FAILED  = 4;  STATUS_CANCELED   = 5;
MODE_SYNC = 1;
PHASE_BASE = 1000000;
PHASE_CONFIG = 0 … PHASE_FINALIZE = 7;

Rental_Data_Sync_Status::cursor( $phase, $offset );   // encode
Rental_Data_Sync_Status::cursor_phase( $cursor );     // decode phase
Rental_Data_Sync_Status::cursor_offset( $cursor );    // decode offset
Rental_Data_Sync_Status::phase_label( $phase );       // 'products', 'sweep', …
Rental_Data_Sync_Status::progress_percent( $phase, $steps_in_phase );
```

`progress_percent()` drives the admin progress bar. The pull API exposes no totals, so a
plain phase count would make the bar jump from 12% to 50% the moment products start.
Instead each phase carries a weight roughly proportional to its cost
(`phase_weights()`: products 45, sets 15, sweep 10, …); completed phases contribute their
full weight and the phase in flight contributes a saturating fraction `steps/(steps+4)` of
its own. The bar therefore always creeps forward, never jumps a phase ahead, and never
goes backwards.

### 6.3 `Rental_Data_Sync_Session`

Option-backed session store (`rental_data_sync_sessions`), mirroring the file-sync session
manager, plus run-scoped extras.

| Method | Purpose |
|--------|---------|
| `get()` / `update()` / `remove()` | Session CRUD |
| `cancel_previous_sessions()` | One live run at a time |
| `acquire_lock()` / `release_lock()` | Per-session transient lock (default 300 s) |
| `generation_current()` / `generation_increment()` | Invalidate stale in-flight jobs |
| `get_stored_cursor()` / `store_cursor()` | Retry fast-forward |
| `heartbeat()` | Stamp `last_chunk_at` / `first_chunk_at` on every callback |
| `mark_phase_done()` / `phases_done()` | The sweep guard |
| `add_stats()` / `get_stats()` | Per-entity counters for the report |
| `add_failures()` / `get_failures()` | Which records failed and why (capped sample) |
| `add_seen_ids()` / `get_seen_ids()` | Sweep input |
| `staging_dir()` | Per-run working directory |
| `cleanup_staging()` | Drop the staging files of a stopped run |
| `purge_orphan_staging()` | Sweep staging left by runs that died without cleaning up |
| `cleanup_run_artifacts()` | Delete per-run options + staging files |

**Seen-id note:** ids are plain ints *or* `"id:division"` composites. Composites must keep
their string form — an int cast would collapse `"55:1"` to `55` and make the sweep delete
live rows.

**Heartbeat note:** `first_chunk_at` is what separates *"the queue never delivered
anything"* from *"the run stalled part-way"* — two different problems with two different
fixes (§6.4).

### 6.4 `Rental_Data_Sync_Run_Repository`

The run history table. See §5.1 for the schema.

```php
Rental_Data_Sync_Run_Repository::install();
Rental_Data_Sync_Run_Repository::start( $sync_id, $message );
Rental_Data_Sync_Run_Repository::touch( $sync_id, [ 'status' => …, 'phase' => … ] );
Rental_Data_Sync_Run_Repository::finish( $sync_id, $status, $message, $fields = [] );
Rental_Data_Sync_Run_Repository::get_page( $page, $per_page );
Rental_Data_Sync_Run_Repository::last_finished();   // drives "Last synchronization"
Rental_Data_Sync_Run_Repository::delete( $sync_id ); // row + log file
```

`finish()` stamps `finished_at` and derives `duration` from `started_at`, so every
terminal state — completed, failed or canceled — is recorded with a time and a reason.

### 6.4b `Rental_Data_Sync_Watchdog`

Turns "nothing is happening" into a named cause. The run loop is driven from the Rentopian
server, so every way it can break looks identical from the settings page unless it is
diagnosed explicitly.

```php
Rental_Data_Sync_Watchdog::preflight( $api_key );   // before sync/start
Rental_Data_Sync_Watchdog::diagnose( $session );    // while running
Rental_Data_Sync_Watchdog::enforce( $sync_id );     // fail an abandoned run
Rental_Data_Sync_Watchdog::tick();                  // rate-limited background pass
```

**Preflight** (blocking unless noted) runs before the start request:

| Check | Outcome |
|-------|---------|
| API key present | blocker |
| WooCommerce active | blocker |
| Run table installed | blocker |
| Log directory writable | warning |
| Callback route answers as the chunk endpoint | blocker on a wrong HTTP status, warning on a transport error |
| Callback host resolves to a public address | warning |

The route probe POSTs to the callback URL with a deliberately invalid token: a healthy
route answers `403 Invalid token`. A transport error only warns, because a loopback
self-call can fail on hosts that block it while the route is fine. Set
`rental_data_sync_skip_route_probe` to `1` to skip the probe entirely.

The host check resolves the name rather than matching a pattern, so container hostnames
(`wp.docker`) and private-IP vhosts are caught alongside `localhost`.

**Diagnosis** thresholds, measured from the last sign of life:

| Code | Condition | Severity |
|------|-----------|----------|
| `starting` | no callback yet, under `STARTUP_GRACE` (120 s) | info |
| `no_callback` | no callback ever, past the grace period | error |
| `stalled` | callbacks stopped for `STALL_AFTER` (420 s) | warning |
| `failed` | session is FAILED | error |

Every diagnosis carries `hints[]` — what to check, in order of likelihood. `no_callback`
names the queue worker (`php artisan queue:work --queue=webhooks`) and the callback URL.

**Enforcement:** past `FAIL_AFTER` (1800 s) with no callback, the run is marked FAILED, the
run record is closed, staging is cleaned, and the failure email goes out — so the controls
unlock and an outcome is recorded instead of a run stuck at "created" forever.

### 6.5 `Rental_Data_Sync_Payload_Composer`

Converts pull-API rows into the exact payload shapes the `api.php` webhook handlers were
written against (matching the Laravel `ProductWebhook` / `VariantWebhook` builders), so
sync output is identical to webhook output by construction.

Shape rules it reproduces:

- `divisions` is a **JSON string** like `"[1,2]"` — the product's divisions, not the pull
  row's company-wide list.
- `variants` = the product's inventory rows for **one** division;
  `all_variants` = rows across all divisions.
- `product_attribute_values` rows carry
  `{id, slug, title, attribute_id, color, img_id, variant_id, default}`.
- `images` is always `[]` — the file sync owns image work.
- Payloads are returned as **slashed JSON strings**, because every handler does
  `json_decode( stripslashes( $payload ) )`.

Helpers: `decode_json_field()`, `decode_id_list()` (tolerates the `SKUs`-style
`[A,B]` token lists that are *not* valid JSON), `encode()`.

### 6.6 `Rental_Data_Sync_Record_Writer`

Feeds composed payloads to the existing webhook handlers.

- `load_handlers()` — includes `api.php` once. The constant
  `RENTAL_DATA_SYNC_HANDLER_LOAD` makes that file return before dispatching, while PHP's
  early binding still registers every handler function.
- `upsert_product()` — per (product × division): relation lookup → `product/update`, else
  `product/create`. A relation pointing at a missing or trashed post is treated as stale
  and recreated.
- `upsert_division_variants()` — per inventory row: `variant/update`, else `variant/create`.
- `upsert_category()`, `upsert_brand()`, `upsert_attribute()`,
  `upsert_attribute_value()`, `upsert_set()`.
- `delete_record()` — sweep deletions through the matching `*/delete` handler.

Because handlers `echo` their webhook responses and set HTTP codes instead of returning,
every call is wrapped in output buffering and the response code is reset. `$_POST`-based
handlers (brand, attribute, attribute value) get a scoped superglobal shim that is restored
afterwards.

### 6.7 `Rental_Data_Sync_Phases`

One method per phase. Each returns `[ 'offset' => int, 'done' => bool, 'processed' => int ]`
where `offset` is the next within-phase offset and must strictly increase.

Notable internals: `import_sets()` (delete + rebuild through the sets module's sync pass),
`rebuild_sets_tags()`, `build_sets_pass_maps()`, `find_orphans()`, `sweep_is_safe()`, plus
the staging helpers (`append_jsonl()`, `read_jsonl()`, …).

### 6.8 `Rental_Data_Sync_Chunk_Worker`

The cursor router called by the REST controller:

1. Validate the session (exists, not canceled, generation matches).
2. Fast-forward to the stored cursor when Laravel retries an old index.
3. Acquire the run lock.
4. Decode the cursor → run one phase step.
5. Advance the cursor; mark the phase done when the step reports `done`.
6. Persist progress and respond.

`MAX_CONSECUTIVE_ERRORS = 5` — after five consecutive step failures the run is marked
FAILED and a failure report is sent, instead of letting Laravel retry indefinitely.

### 6.9 `Rental_Data_Sync_Scheduler`

Start, cancel and chain.

```php
Rental_Data_Sync_Scheduler::schedule( $api_key );
Rental_Data_Sync_Scheduler::cancel( $sync_id = '', $cancel_files = true );
Rental_Data_Sync_Scheduler::last_success();     // drives Start vs Resync
Rental_Data_Sync_Scheduler::last_run();         // drives "Last synchronization"
Rental_Data_Sync_Scheduler::record_success();
Rental_Data_Sync_Scheduler::maybe_chain_file_sync();
```

`schedule()` runs the watchdog preflight, creates the session, the staging directory and
the run record, then calls `sync/start` on Laravel pointing at this module's chunk
endpoint. Three things make a failed start reportable rather than silent:

- the call carries a **30 s timeout** (`START_TIMEOUT`), so a hung server cannot leave the
  admin request waiting until PHP gives up with no usable error;
- it catches `Throwable`, not `Exception`, so a PHP `Error` cannot escape as a fatal that
  destroys the JSON response;
- a `200` is not taken as success on its own — `rejection_reason()` inspects the body for
  an empty response, `success: false` or an `error` field.

Any of those paths goes through `fail_start()`, which marks the session FAILED, closes the
run record with the reason, logs it and emails the failure report. The AJAX response
carries `message`, `reason` and `hints[]` so the panel names the cause.

`cancel()` marks the session canceled, releases the lock, drops any pending chain, notifies
Laravel, closes the run record, deletes the staging files **and** cancels an in-flight file
sync so one button aborts the whole pipeline. It does that through the file-sync module's
own session manager and the same remote `sync/cancel` call — the file-sync module itself is
not modified.

`maybe_chain_file_sync()` is deferred by design: it must not call `sync/start` while the
Laravel chunk job still holds the per-company overlap lock, or the file job would be
discarded by `WithoutOverlapping`.

### 6.10 `Rental_Data_Sync_REST_Controller`

See §8.

### 6.11 `Rental_Data_Sync_Reporter`

Builds and sends the combined report. See §13.

### 6.12 `Rental_Data_Sync_Ajax_Controller`

See §9.

---

## 7. Workflows

Step-by-step diagrams live in [WORKFLOWS.md](WORKFLOWS.md). Summary:

| Workflow | Entry point |
|----------|-------------|
| Start a run | Admin button → `rental_data_sync_start` → `Scheduler::schedule()` |
| Process a chunk | Laravel → `POST /data-sync/process-chunk` → `Chunk_Worker::process()` |
| Stop a run | Admin button → `rental_data_sync_cancel` → `Scheduler::cancel()` |
| Chain the file sync | `finalize` → cron `rental_data_sync_chain_files` → `maybe_chain_file_sync()` |
| Report | `rental_file_sync_completed` → `Reporter::on_file_sync_completed()` |

---

## 8. REST API endpoints

Namespace `rentopian-sync/v1`. Called by the Laravel server; authenticated by the
per-session `wp_token` (validated inside the handler, so `permission_callback` is
`__return_true`).

### 8.1 `POST /data-sync/process-chunk`

Request:

```json
{ "sync_id": "uuid", "start_index": 0, "chunk_size": 8, "mode": 1, "wp_token": "secret" }
```

Response:

```json
{ "success": true, "last_index": 3000000, "completed": false,
  "failed_ids": [], "processed_count": 120, "total_count": 0 }
```

- `chunk_size` from Laravel is **ignored**; the module uses its own page size
  (`rental_data_sync_page_size`, default 100, clamped 10–300).
- `failed_ids` is always `[]` — it exists only because Laravel dispatches image-specific
  retry jobs from it.
- `total_count` is `0`: the pull API has no count endpoints, so completion is signalled
  explicitly via `completed` rather than inferred from counts.

### 8.2 `GET /data-sync/status`

Params `sync_id`, `wp_token` → `{ success, status, last_index, completed }`. Used by
Laravel's status-fallback path when a chunk response is lost.

### 8.3 `POST /data-sync/retry-images`

A no-op returning `200`. It exists only because Laravel derives this sibling endpoint from
the chunk endpoint; the data sync has no per-image retry concept.

---

## 9. Admin AJAX endpoints

All require `manage_options`. State-changing actions also require the
`rental_data_sync_admin` nonce, passed as `nonce`; read-only polling does not, so a page
left open overnight keeps reporting instead of silently failing.

| Action | Method | Nonce | Purpose |
|--------|--------|-------|---------|
| `rental_data_sync_start` | POST | yes | Start a run. Param: `api_key`. |
| `rental_data_sync_cancel` | POST | yes | Stop the run (and any chained file sync). |
| `rental_data_sync_status` | GET | no | Full pipeline snapshot (see below). |
| `rental_data_sync_runs` | GET | no | Paginated run history. |
| `rental_data_sync_run_log` | GET | no | Tail of one run's log file (default 200 lines). |
| `rental_data_sync_download_log` | GET | yes | Stream one run's log file as an attachment. |
| `rental_data_sync_delete_run` | POST | yes | Delete a run's record and log file. |

`rental_data_sync_status` always answers `200` with a coherent state, including
"never run", so the UI can label the button correctly on first load. It also runs
`Watchdog::enforce()` first, so a run the driver abandoned is closed before it is reported:

```json
{
  "success": true,
  "has_previous_success": true,
  "last_success": { "sync_id": "…", "completed_at": "2026-07-20 18:14:35" },
  "last_run": { "sync_id": "…", "status": 3, "status_label": "completed",
                "finished_at": "2026-07-20 18:14:35", "duration": 461,
                "message": "Completed." },
  "button_label": "Resynchronize Now",
  "is_running": false,
  "diagnosis": null,
  "data":  { "status": 3, "status_label": "completed", "phase": 7,
             "phase_label": "finalize", "phase_total": 8, "percent": 100,
             "processed_count": 261, "idle_seconds": 4,
             "stats": { … }, "file_chain": { … } },
  "files": { "sync_id": "…", "status": 3, "is_running": false,
             "processed_count": 88, "total_count": 88, "percent": 100 }
}
```

`diagnosis` is `null` while the run is healthy and otherwise carries
`{ code, severity, message, hints[], idle_seconds }` — see §6.4b.

`rental_data_sync_delete_run` refuses a run that is still in flight (409), so a live log is
never pulled out from under the worker.

---

## 10. Admin UI

Rendered on the plugin settings page (`admin.php?page=rentopian-sync/functions.php`) in a
panel titled **Background Synchronization (Data + Files)**.

### 10.1 Controls

| Control | Behaviour |
|---------|-----------|
| **Primary button** | Labels itself **Start Synchronization** when no run has ever completed, and **Resynchronize Now** once one has. While a run is live it shows *Synchronizing…* with a spinning icon and is disabled. |
| **Stop** | Enabled only while something is running. Cancels the data run and any chained file sync. |
| **Last synchronization** | The last terminal outcome with a status badge, timestamp, duration and summary — a failure is reported as plainly as a success. Refreshed on every poll, so it is never stale after a run ends. |
| **Problem banner** | The server's named cause plus its `hints[]` as a checklist. Cleared automatically once the run recovers; outcome messages the admin triggered are left alone. |
| **Status block** | Per-stage rows (Data, Files) with state, progress bar, percentage and counters, plus per-entity change chips. |
| **Recent runs** | Run history with started / finished / duration / status / processed, **Details** (log tail + **Download log**) and **Delete**. |

The initial button label and the last-run line are **server-rendered**, so both are already
correct before the polling script runs; the script then keeps them in sync.

Clicking the primary button resets the panel to *Starting…* before the request goes out, so
a resync visibly restarts rather than appearing to resume the previous run's progress.

### 10.2 Polling

The panel polls `rental_data_sync_status` **only while a run is live**, and stops as soon
as the pipeline is idle. On page load it polls once to decide whether to resume.

The cadence is 2 s for the first minute after a start — that is the window in which the
first callback either arrives or does not — and 5 s afterwards.

**"Live" includes the gap before the file phase starts.** The chain is deliberately
deferred (§6.9), so for roughly a minute after the data phase completes neither session
reports as running. Treating that as idle stopped the poll permanently, and the Files row
stayed on *waiting* while the file sync went on to run and finish unobserved. The status
endpoint therefore also reports `chain_pending`, true from the moment the data phase
completes until the file phase finishes, the chain is refused, or the report goes out;
`is_running` includes it, and the Files row shows *starting soon* for that window.

### 10.3 The run log viewer

**Details** loads the tail of that run's log file (§12), rendered one line per entry with
its time, channel and level colouring. When the file is longer than the tail, the panel
says so and offers **Download log** for the complete file rather than pushing thousands of
lines into the settings page.

### 10.4 Assets

```
includes/data-sync/assets/css/data-sync-admin.css   (handle: rental-data-sync-admin)
includes/data-sync/assets/js/data-sync-admin.js     (handle: rental-data-sync-admin)
```

Both are enqueued only on the plugin settings page. All CSS is scoped under
`.rental-ds-panel`. Strings are passed through `wp_localize_script()` as
`rentalDataSyncObj.i18n`, so the JS contains no hard-coded English that cannot be
translated.

---

## 11. WP Options reference

| Option | Autoload | Purpose |
|--------|----------|---------|
| `rental_data_sync_sessions` | no | All sessions keyed by `sync_id` |
| `rental_data_sync_current_id` | yes | The current/most recent run id |
| `rental_data_sync_generation` | yes | Generation counter for stale-job rejection |
| `rental_data_sync_all_canceled` | yes | Global kill switch |
| `rental_data_sync_canceled_reason` | yes | Reason shown after a global cancel |
| `rental_data_sync_last_success` | no | `{ sync_id, completed_at }` — drives Start vs Resync |
| `rental_data_sync_last_run` | no | Run record used to build the report email |
| `rental_data_sync_pending_chain` | yes | Deferred file-sync chain marker |
| `rental_data_sync_page_size` | yes | API page size (default 100, clamped 10–300) |
| `rental_data_sync_email_recipients` | yes | Extra report recipients (comma-separated) |
| `rental_data_sync_db_version` | yes | Run-table schema version |
| `rental_data_sync_sweep_force` | yes | Bypass the mass-deletion guard (see §14.3) |
| `rental_data_sync_skip_route_probe` | yes | Skip the preflight callback-route self-test |
| `rental_data_sync_cursor_{sync_id}` | no | Per-run cursor |
| `rental_data_sync_phases_done_{sync_id}` | no | Per-run phase completion flags |
| `rental_data_sync_stats_{sync_id}` | no | Per-run counters |
| `rental_data_sync_failures_{sync_id}` | no | Per-run failing records for the report |
| `rental_data_sync_seen_{entity}_{sync_id}` | no | Per-run seen ids (sweep input) |

Per-run options and the staging directory are deleted by
`Rental_Data_Sync_Session::cleanup_run_artifacts()` once the report is sent.

---

## 12. Logging

The split is deliberate: **the database records what happened to a run, the file records
how.**

| Destination | Granularity | Serves |
|-------------|-------------|--------|
| `{prefix}rental_data_sync_run` | one row per run | The run history list and the "Last synchronization" line: status, start, finish, duration, counts |
| `uploads/rentopian-data-sync/logs/<sync_id>-<hash>.log` | one line per step | The **Details** panel, the **Download log** button, and per-run deletion |
| WooCommerce → Status → Logs, source `rentopian-data-sync` | one line per step | The same lines, in one daily file across all runs, for grepping alongside the rest of the plugin |

Log lines carry a level and a channel tag:

```
2026-07-20T18:10:22+00:00 [warning] [attach] Product attach skipped for REN ID 1234: …
2026-07-20T18:10:23+00:00 [info] [chain] File sync chained: 87261a12-… (after data sync b10ed104-…)
2026-07-20T18:10:24+00:00 [error] [phase] Phase products error (5/5, TERMINAL): …
```

Channels: `rest`, `chunk`, `phase`, `write`, `sweep`, `report`, `schedule`, `chain`,
`watchdog`, `log`.

### 12.1 The `uploads/rentopian-data-sync` directory

```
uploads/rentopian-data-sync/
├── index.html
├── logs/                             per-run log files (kept until the run is deleted)
│   ├── .htaccess                     Deny from all
│   └── <sync_id>-<hash>.log
└── <sync_id>-<hash>/                 per-run staging (variant JSONL buckets, sets dump)
```

Staging is working state for a run in flight, and is deleted the moment the run stops for
any reason — completed (reporter), canceled (scheduler), or failed (chunk worker /
watchdog). `Session::purge_orphan_staging()` runs on the watchdog tick and removes anything
older than 24 hours, so a run killed mid-flight by a fatal cannot leave the directory
growing.

Log files are **not** touched by that cleanup: they outlive the run and are removed only
when the admin deletes that run from the panel.

---

## 13. Email reporting

One email per pipeline run, sent when the **whole** pipeline finishes — that is, after the
chained file sync completes.

Recipients, merged and de-duplicated:

1. `Rental_Data_Sync_Reporter::DEFAULT_RECIPIENTS` (in code)
2. The `rental_data_sync_email_recipients` option (comma-separated)
3. The `rental_data_sync_report_emails` filter

```php
add_filter( 'rental_data_sync_report_emails', function ( $emails ) {
    $emails[] = 'ops@example.com';
    return $emails;
} );
```

Contents: a verdict block, per-phase durations, per-entity
created/updated/deleted/skipped/failed counts, the records that failed and why, whether the
sweep was skipped, both sync ids, and the site URL.

`created` and `updated` are told apart by asking the same model the handler will use
(`RTBrand::getBrand()`, `RTAttribute::getAttribute()`,
`RTAttributeValue::getAttributeValue()`) before the write, so a rebuild reports what it
actually did. The probe is read-only; when a model cannot be loaded the write counts as
`updated`, which understates novelty rather than inventing it.

The body opens with the verdict, so the first two lines answer "must I do anything?":

```
Result: COMPLETED — the catalog is live and current.
No action required for the storefront. A few records need a correction in Rentopian — they are named below.
```

### Outcomes

The subject carries both a severity and its magnitude:

| Subject suffix | When |
|---|---|
| `COMPLETED · everything imported` | No failures, sweep ran. |
| `COMPLETED · N of M records need attention` | Failures confined to non-critical entities, each under `FAILURE_RATIO_LIMIT` (2%) of its entity. The storefront is intact. |
| `ACTION NEEDED · <n products and n sets> did not import` | Any failure in `CRITICAL_ENTITIES` (products, variants, sets), or any entity failing above the ratio limit. |
| `ACTION NEEDED · stale records may remain` | The orphan sweep was skipped, so records deleted upstream may still be on the storefront. |
| `IMAGES INCOMPLETE` | Data phase completed, file phase was abandoned. |
| `FAILED` | The run turned terminal. |

`ACTION NEEDED` is deliberately reserved for a result the storefront is *wrong* about — a
handful of unwritable taxonomy values is reported with a number, not an alarm.

### Failing records

The writer keeps the reason each handler gave (its echoed error envelope, or the thrown
message) alongside the record's label, up to `Record_Writer::MAX_FAILURES` per chunk. The
chunk worker appends them to a per-run option capped at
`Session::MAX_RECORDED_FAILURES` (50), and `finalize` copies them into
`rental_data_sync_last_run` — the per-run options are deleted before the report is composed.

The email names the first `FAILURES_LISTED` (15) and states how many more the log holds:

```
Records that need attention — 5 of 8,761:
  attribute_values: 5 failed

  attribute value 88214 — Product attribute value could not be saved: A term name is required
  …
```

A failure report is also sent when a run is aborted by repeated step errors or when the
file-sync chain cannot be scheduled; it lists the same records for the part of the run that
did execute.

---

## 14. Error handling and safety rails

### 14.1 Per-record isolation

Every record write is wrapped in try/catch. A failing record is logged and counted, and the
run continues — one bad product never aborts the catalog.

### 14.2 Per-step isolation

A step that throws is logged with its phase and cursor. The response still returns the
current cursor so Laravel can retry. After `MAX_CONSECUTIVE_ERRORS` (5) consecutive
failures the run is marked FAILED and reported.

### 14.3 Sweep guards

**A skipped record is not an orphan.** The sweep decides what to delete from what
it did *not* see, so a product the writer declined to write looks identical to
one deleted upstream. `upsert_product()` records every such id
(`Session::add_skipped_ids()`), and `find_orphans()` holds those ids back for the
run.

The exclusion is keyed on the **product id alone**, deliberately: a skipped
product produced no payload for any division, so no division of it may be swept.
Products that *did* import stay keyed on `id:division`, so withdrawing one from a
single division still deletes exactly that division's post. Both properties are
covered in §16.

Consequence worth knowing: a product whose inventory rows are all inactive is now
**kept** rather than deleted. It was previously removed as a side effect of the
same code path that caused spurious deletions, not by intent.


**In wipe mode the sweep does not run at all.** After a purge every record that exists was
written by this run, so nothing can be orphaned; the phase logs that it was not needed and
returns immediately. The guards below apply to the fallback path — a run where the purge
did not happen — and are kept so that path stays safe.

Deletions are the only destructive operation, so they are guarded three ways:

1. **Phase guard** — the sweep runs only when every fetch phase completed in this run.
   A partial run can never delete.
2. **Mass-deletion guard** — `sweep_is_safe()` refuses to sweep an entity when there are
   more than 20 orphans *and* orphans outnumber the ids seen this run. A truncated or
   wrong-scope feed therefore cannot wipe the catalog. Override deliberately with
   `rental_data_sync_sweep_force`.
3. **Composite keys** — products and variants are swept on `id:division`, so a record that
   exists in one division is not deleted because another division dropped it.

When a sweep is skipped the run is still marked complete, `sweep_skipped` is recorded, and
the report email says so.

### 14.4 Idempotency

Chunks may be delivered more than once (retries, self-heal, stall re-dispatch). Every write
is an upsert keyed on the Rentopian id, and the stored cursor fast-forwards a retried
`start_index`, so replays are harmless. The one step that is **not** naturally repeatable is
the purge — deleting twice would destroy a rebuild in progress — so it carries its own
`purged` flag (§4.2a).

### 14.5 Terminal failures

`/products/variants` returns HTTP 500 for API keys whose company is not active. That is not
retryable — the run is failed and reported rather than burning the stall budget. The purge
probes that same endpoint, so the rule applies there too, and it fires before the wipe.

### 14.6 Relation lookup indexes

The relation tables were built for the legacy sync, which truncated and
bulk-inserted and so never looked a row up by `rental_id`. Their only index is
`UNIQUE KEY id (id)`. This module inverts that access pattern — "does this
Rentopian record already exist here?" is the hottest query in the pipeline — so
without an index every upsert is a full table scan, and a large catalog never
finishes.

`Rental_Data_Sync_Integrity::ensure_relation_indexes()` adds a `rental_lookup`
key to every relation table (composite with `rental_division_id` where the
column exists), plus `wp_lookup` / `po_lookup` on the option tables. It is
guarded by `rental_relations_index_version` so it runs once, and is called both
from `admin_init` and at the start of every run.

Measured on the relation tables: `EXPLAIN` goes from `type=ALL key=NULL` to
`type=ref key=rental_lookup`.

### 14.7 Paged fetches insist on a list

`rental_curl()` throws on a non-200, but a **200 with a body that will not
decode** returns `null` — a connection reset mid-body, an HTML error page from a
proxy, a PHP notice printed before the JSON. Treating that as an empty array
would read as "zero rows", end the phase early, and complete the run having
imported a fraction of the catalog — after which the sweep deletes everything it
never saw.

`fetch_page()` therefore throws when the body is not a list. A thrown step is
retried by the driver with backoff and turns terminal after
`MAX_CONSECUTIVE_ERRORS`, which is the intended handling for a broken feed.

### 14.8 The products step is repeatable

The cursor advances once per page, so a throw half-way through a page sends the
driver back to the same offset. Variant buckets are therefore deleted **after**
the whole page has been written, never inside the loop: deleting per product
made a retry find the already-handled products' variants gone, import them as
empty, and expose them to the sweep.

### 14.9 WordPress-side tampering is repaired, not inherited

Every upsert decides "create or update" by looking up a relation row
(rental id → WP id). When the WordPress object behind that row is deleted or
trashed by hand, the row survives, the sync takes the **update** path, and the
update silently does nothing — so the record is never restored. The legacy
one-shot sync never hit this because it truncated and rebuilt everything.

`Rental_Data_Sync_Integrity` restores that guarantee without truncating:

| Pass | When | What it does |
|------|------|--------------|
| `purge_stale_relations()` | phase `config`, before any write | Drops relation rows whose WP target is gone, so the entity takes the create path this run |
| `live_relation()` (record writer) | per record | Same check inline, for rows that die mid-run |
| `reconcile_product()` | after each product's variants | Rebuilds `_product_attributes` and parent attribute terms from the product's own live variations |
| `dedupe_variations()` | inside `reconcile_product()` | Removes live variations covering an identical attribute combination |
| `purge_orphan_posts()` | phase `finalize` | Hard-deletes superseded variations and stale `wc_product_meta_lookup` rows |
| `flush_product_caches()` | after each product and set | Clears `wc_var_prices_*` / `wc_product_children_*` so the storefront reflects the run |

Covered relation tables: products, variants, sets, coupons (post targets);
categories, brands, tags, set tags (term targets); attributes (WooCommerce
attribute ids — **not** term ids, which is why checking them as terms silently
passes); product options (`type` 1 = post, 2 = term); set options.

**Why `reconcile_product()` derives from children.** `product/update` refreshes
fields and stock only. A parent whose `_product_attributes` was removed keeps
rendering **no** variation dropdowns however many times the sync runs — the
variations exist and are purchasable, but WooCommerce builds the dropdowns from
the parent. Deriving the parent from its own live variations heals that whatever
removed the metadata. It is also what fixes "the variation is in the admin but
not on the product page", which is usually the parent's cached children list.

### 14.10 Image references are verified, never assumed

Between the data and file phases the pipeline runs
`Rental_Image_Integrity::repair()` (see the file-sync README §5.9). It clears
`_thumbnail_id`s, gallery entries, term images and `rental_image_relations` rows
that name an attachment which no longer exists.

This is sequenced, not incidental. A dangling reference is invisible — the
product renders an empty image slot while `has_post_thumbnail()` still answers
true — and the old relation row made the file sync skip the download, so the
corruption survived every subsequent run. Clearing the references first means the
file phase that follows re-downloads exactly what was freed.

After the file phase, the reporter runs `promote_gallery_images()` and lists
anything still without an image in the report email, so a genuinely image-less
product in Rentopian is reported rather than silently blank.

### 14.11 A run can never end in limbo

Every terminal state closes the run record with a timestamp and a reason:

| Ending | Closed by |
|--------|-----------|
| Finalize completed | `Chunk_Worker::process()` |
| Five consecutive step errors, or a terminal variants error | `Chunk_Worker::handle_step_error()` |
| Preflight refused, start rejected, staging unwritable | `Scheduler::fail_start()` |
| Admin pressed Stop | `Scheduler::cancel()` |
| Driver went silent past `FAIL_AFTER` | `Watchdog::enforce()` |

The last row is what prevents the original symptom — a run that starts, is never picked up,
and sits at "created" with the controls locked and no explanation.

---

## 15. Relationship to the two existing sync methods

The plugin now offers three synchronization methods. The two older ones are
**untouched and still work independently** — this module adds a third:

| # | Method | Data phase | File phase | Trigger |
|---|--------|-----------|------------|---------|
| 1 | Classic sync | HTTP request (blocking) | HTTP request (blocking loop) | `action=rental_sync`, then `rental_upload_images` |
| 2 | Classic data + background files | HTTP request (blocking) | Background (Laravel-driven) | `action=rental_sync`, then `rental_sync_files_bg` |
| 3 | **Full background sync** (this module) | **Background (chunked)** | **Background, chained automatically** | `rental_data_sync_start` |

Method 3 is a single process: the data phase always runs first and the file phase is
chained automatically when it completes. There is no way to run one phase without the
other, because images can only attach to products that already exist and Laravel permits
only one sync session per company (§3.2). Methods 1 and 2 remain available for small
catalogs and for anyone who wants the old behaviour.

The data sync **reuses** rather than replaces:

- the Laravel `sync/start` pacemaker (no Laravel changes),
- the `api.php` webhook handlers as record writers,
- the sets module's sync pass for sets,
- the legacy `rental_empty_*` / `rental_add_*` helpers for the small entities,
- the classic sync's `rental_empty_*` wipe prelude for the purge phase,
- the file-sync module for the image phase.

Methods 1 and 2 are **left exactly as they are** by the wipe-first work: the classic
buttons, `rental_synchronization()` and the shared `rental_empty_*` helpers are called, not
modified.

The only edits outside `includes/data-sync/` are: the `require_once` in
`rentopian-sync.php`, the settings-page panel and enqueues in `functions.php`, a
`RENTAL_DATA_SYNC_HANDLER_LOAD` early-return guard in `api.php`, and a
`do_action( 'rental_file_sync_completed', … )` in the file-sync chunk worker so the reporter
knows when the file phase ended.

Note that `rental_curl()` gained no behaviour here — the scheduler simply passes its
existing `$timeout` argument, which previously defaulted to unbounded.

---

## 16. Testing checklist

- [ ] **Fresh start** — button reads *Start Synchronization*; after a successful run it reads *Resynchronize Now*.
- [ ] **Full run** — completes through all 9 phases; each chunk returns a strictly increasing cursor.
- [ ] **Memory** — peak memory per callback stays flat regardless of catalog size.
- [ ] **Wipe happens** — the log shows the `purge` line; mid-run the catalog is empty, and at finalize it is fully back.
- [ ] **Wipe is guarded** — point at an unreachable API or an invalid key: the run fails in `config`/`purge` with the catalog **untouched**.
- [ ] **Wipe happens once** — a replayed purge chunk logs *already wiped* and deletes nothing.
- [ ] **Freshness** — no `product_variation` under a `simple` product; a product that gained attributes renders as `variable`; `_rental_is_sale`, the interval/slot flags and the parent `min_price`/`max_price` all match the feed.
- [ ] **Idempotency** — running twice in a row rebuilds cleanly, with no duplicates and no phantom variations.
- [ ] **Chaining** — the file sync starts automatically once the data phase completes, and the report email arrives at the end.
- [ ] **Images reused** — the file-sync log shows attachment reuse and repairs rather than a full re-download; every product ends with an image or is named in the report.
- [ ] **Stop** — cancelling mid-run halts the pipeline; a following run rebuilds the catalog cleanly.
- [ ] **Removals** — a product deleted in Rentopian is absent after the next run (it is simply never rebuilt).
- [ ] **Sweep skipped** — after a wipe the sweep logs that it was not needed, and the report does **not** warn about a skipped sweep.
- [ ] **Integrity** — no orphan relation rows; no variations with a dead parent.
- [ ] **Logs** — entries appear in WooCommerce → Status → Logs under `rentopian-data-sync`, and in the run's own file.
- [ ] **Queue down** — stop the `webhooks` worker, start a run: within two minutes the panel names the queue as the cause; within 30 minutes the run is marked failed and the controls unlock.
- [ ] **Preflight** — clear the API key, or point the site at a private host: the start is refused (or warned) with the specific reason, not "check the logs".
- [ ] **Progress** — both bars start at zero on a resync and advance while the run proceeds.
- [ ] **Run history** — every ending records a finish time, a duration and a status; **Download log** returns the full file; **Delete** removes the row and the file.
- [ ] **WP-side tampering** — trash a variation, delete a category term, trash a set post, and wipe a product's `_product_attributes`; one resync restores all four.
- [ ] **No duplicates** — resyncing after a restore leaves one variation per attribute combination, not two.
- [ ] **No leftovers** — after a run there are no trashed `product_variation` posts and no `wc_product_meta_lookup` rows for missing posts.
- [ ] **Skipped ≠ orphan** — a product the writer skips for "no active inventory rows" survives the sweep.
- [ ] **Division scoping** — a product imported in one division and withdrawn from another loses only the withdrawn division's post.
- [ ] **Broken feed** — a paged endpoint answering 200 with an undecodable body fails the step instead of ending the phase.
- [ ] **Indexes** — `EXPLAIN` on a relation lookup reports `type=ref key=rental_lookup`, not `type=ALL`.

---

## 17. Troubleshooting

**Start here: the panel names the cause.** A run that misbehaves shows a banner with the
diagnosis and a hint list; the table below is for anything that banner does not cover.

| Symptom | Likely cause | Where to look |
|---------|--------------|---------------|
| Banner: *"Rentopian accepted the request but has not called WordPress back"* | No queue worker consuming `webhooks` | Run `php artisan queue:work --queue=webhooks` on the Rentopian server |
| Banner: *"The callback URL host does not resolve to a public address"* | Local/staging site the Rentopian server cannot reach | Expose the site publicly and set the WordPress Site Address to that URL |
| Banner: *"did not answer as the chunk endpoint"* | REST API blocked, plain permalinks, or a security plugin | Open the callback URL — it must return JSON, not HTML |
| Banner: *"No progress for N minutes"* | Worker stopped or is retrying with backoff | The run's log tail shows the last completed step |
| Start fails with no reason at all | PHP fatal during the request | WooCommerce → Status → Logs, source `rentopian-data-sync` |
| Chunks stop after ~5 identical cursors | Laravel stall detection fired | The run's log; look for the repeating phase |
| `Invalid token` in the log | Session was replaced by a newer run | Start a fresh run |
| Images not attached to products | A file sync ran before any data sync existed | The background sync always runs data before files; check for *"no WP products are mapped to rental ids"* warnings |
| A product shows an empty image slot | Its `_thumbnail_id` names a deleted attachment | Resynchronize: the integrity pass (§14.7) clears it and the file phase re-downloads. The log line starts `Repaired product` |
| A variable product shows no variation dropdowns | The parent's `_product_attributes` was removed; the variations themselves are fine | Resynchronize: `reconcile_product()` rebuilds it from the live variations (§14.9). Log line starts `Rebuilt attributes for product` |
| A record deleted in WP never comes back | Its relation row outlived it, so the sync kept "updating" nothing | Fixed in §14.9 — `purge_stale_relations()` drops the row so the record is recreated |
| The admin shows a variation the product page does not | Stale `wc_product_children_*` / `wc_var_prices_*` transients | Cleared per product on every run by `flush_product_caches()` |
| A product has no image after a full run | It has no image in Rentopian | The report email lists every product still without one |
| Sweep deleted nothing | Guard tripped, or a phase did not complete | Search the log for `Sweep aborted` / `Sweep skipped` |
| No report email | `wp_mail` unavailable (no SMTP) | Log line `wp_mail FAILED`; configure SMTP |
| Run table missing | `dbDelta` failed | `admin_init` retries; check the `Run table creation failed` line |
| Details panel empty | The run predates per-run log files, or uploads is not writable | Preflight warns `log_dir_unwritable` |
