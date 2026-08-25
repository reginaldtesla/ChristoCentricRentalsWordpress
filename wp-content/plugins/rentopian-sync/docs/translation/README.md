# Auto-Translation (Polylang) — Documentation

**Component:** Rentopian Sync — Multi-Language Translation Module  
**Audience:** Developers, technical maintainers  
**Last updated:** 2026-03-31  
**Version:** 1.0.0  

---

## Table of contents

1. [Introduction](#1-introduction)
2. [Prerequisites](#2-prerequisites)
3. [Architecture](#3-architecture)
4. [Database schema](#4-database-schema)
5. [Class reference](#5-class-reference)
6. [Workflows](#6-workflows)
7. [Settings UI reference](#7-settings-ui-reference)
8. [WP Options reference](#8-wp-options-reference)
9. [WP-Cron reference](#9-wp-cron-reference)
10. [Translation cache](#10-translation-cache)
11. [API usage tracking](#11-api-usage-tracking)
12. [Image propagation](#12-image-propagation)
13. [Logging](#13-logging)
14. [Error handling](#14-error-handling)
15. [Extending](#15-extending)
16. [Testing checklist](#16-testing-checklist)
17. [Integration steps](#17-integration-steps)
18. [Documentation maintenance](#18-documentation-maintenance)

---

## 1. Introduction

### 1.1 Purpose

The **Auto-Translation module** bridges Rentopian's product sync with Polylang's multi-language system. When products, categories, tags, attributes, brands, or sets are synced from Rentopian (in the default language — typically English), this module automatically translates them into every secondary Polylang language (e.g. French, German, Spanish) using a configurable external translation API (DeepL or Google Cloud Translation).

### 1.2 Design principles

| Principle | Implementation |
|-----------|----------------|
| **Non-blocking** | Translations are queued and processed in the background via WP-Cron. The sync process is never slowed down. |
| **Cost-efficient** | A persistent DB cache stores every translation. Re-syncs reuse cached translations — zero API cost for unchanged content. |
| **Provider-agnostic** | Translation providers are abstracted behind an interface. Swap DeepL ↔ Google without touching consumer code. |
| **Polylang-native** | Uses Polylang's public API (`pll_*` functions) exclusively. No direct taxonomy hacking. |
| **Image-aware** | A meta propagator automatically syncs images from source products to translations, even when images arrive later via the background file sync. |
| **Idempotent** | All operations are safe to re-run. Already-translated content is skipped. Already-linked translations are updated, not duplicated. |
| **Observable** | Every step is logged with timing via `Project_WP_Logger` to `wp-content/uploads/wc-logs/rentopian-translation.log`. |

### 1.3 Glossary

| Term | Meaning |
|------|---------|
| **Default language** | The Polylang default language (typically `en`). All Rentopian-synced content is created in this language. |
| **Secondary language** | Any active Polylang language that is not the default (e.g. `fr`, `de`). Translations are created for each. |
| **Translation post/term** | A separate WordPress post or term linked to the source via `pll_save_post_translations()` / `pll_save_term_translations()`. |
| **Queue** | A serialised array in `wp_options` containing items pending translation. Processed by WP-Cron. |
| **Cache** | The `wp_rental_translation_cache` database table. Stores source text → translated text mappings permanently. |
| **Provider** | An external translation API (DeepL or Google Cloud Translation). |
| **Propagator** | The `Rental_Translation_Image_Propagator` class that syncs image meta from source posts to translation posts. |

### 1.4 What gets translated

| Content Type | Translated Fields |
|-------------|-------------------|
| Products | Title, full description, short description |
| Variations | Title (variation name) |
| Sets | Title, full description, short description |
| Categories | Name, description |
| Tags | Name |
| Attribute terms | Name (e.g. "Red", "Large") |
| Brands | Name, description |

### 1.5 What gets copied (not translated)

- All WooCommerce product meta (`_price`, `_sku`, `_stock`, `_weight`, `_thumbnail_id`, etc.)
- Product image galleries (`_product_image_gallery`)
- Term relationships (categories, tags, attributes) — using translated terms where available
- Custom Rentopian meta fields (`_rental_*`)

---

## 2. Prerequisites

- WordPress 5.0+
- WooCommerce active
- **Polylang** (free or Pro) active with at least 2 languages configured
- Rentopian Sync plugin active
- PHP 8.0+ (uses `match`, named arguments, typed properties)
- A valid translation API key (DeepL Free or Google Cloud Translation)
- The `Project_WP_Logger` class loaded (for logging)

---

## 3. Architecture

### 3.1 Module location

```
includes/translation/
├── rental-translation-bootstrap.php            ← ENTRY POINT — loaded from rentopian-sync.php
├── interface-rental-translation-service.php    ← Provider contract
├── class-rental-translation-deepl.php          ← DeepL API provider
├── class-rental-translation-google.php         ← Google Cloud Translation provider
├── class-rental-translation-factory.php        ← Provider resolver (singleton)
├── class-rental-translation-cache.php          ← DB cache table (survives re-syncs)
├── class-rental-translation-usage.php          ← Real-time API usage from DeepL
├── class-rental-translation-processor.php      ← Creates translated posts/terms (cache-aware)
├── class-rental-translation-queue.php          ← WP-Cron queue with locking
├── class-rental-translation-admin.php          ← Settings UI (embedded in Settings page)
└── class-rental-translation-image-propagator.php ← Syncs images to translations
```

### 3.2 Load order

```
rentopian-sync.php
  → require class-rental-polylang-integration.php   (Polylang language assignment)
  → require rental-polylang-api-helpers.php          (Helper functions for api.php)
  → require translation/rental-translation-bootstrap.php
       → require interface-rental-translation-service.php
       → require class-rental-translation-deepl.php
       → require class-rental-translation-google.php
       → require class-rental-translation-factory.php
       → require class-rental-translation-cache.php
       → require class-rental-translation-usage.php
       → require class-rental-translation-processor.php
       → require class-rental-translation-queue.php
       → require class-rental-translation-admin.php
       → require class-rental-translation-image-propagator.php
       → add_filter('cron_schedules', ...)     → register 'every_minute' interval
       → Rental_Translation_Queue::get_instance()  → register cron hook
       → Rental_Translation_Image_Propagator::init() → hook into update_post_meta
       → if (is_admin):
           → new Rental_Translation_Admin()     → register AJAX hooks
           → add_action('admin_init', ...)      → ensure cache table exists
       → add_action('rental_after_synchronization', ..., 20) → bulk enqueue
```

### 3.3 Dependency graph

```
Rental_Translation_Queue (WP-Cron entry point)
  └─ Rental_Translation_Processor
       ├─ Rental_Translation_Cache (DB cache lookups/stores)
       ├─ Rental_Translation_Factory
       │    └─ Rental_Translation_DeepL  ── or ──  Rental_Translation_Google
       │         (implements Rental_Translation_Service interface)
       └─ Polylang API (pll_set_post_language, pll_save_post_translations, etc.)

Rental_Translation_Admin (settings UI + AJAX)
  ├─ Rental_Translation_Queue (queue/process/clear actions)
  ├─ Rental_Translation_Cache (stats, clear)
  └─ Rental_Translation_Usage (DeepL API usage)

Rental_Translation_Image_Propagator (meta hooks)
  └─ Polylang API (pll_get_post_translations, pll_get_term_translations)

Helper functions (rental-translation-bootstrap.php)
  └─ rental_translation_queue_product()
  └─ rental_translation_queue_post()
  └─ rental_translation_queue_term()
  └─ rental_translation_queue_terms()
```

### 3.4 Relationship with existing Polylang integration

```
┌──────────────────────────────────────────────────────────────┐
│  EXISTING: class-rental-polylang-integration.php             │
│  Responsibility: Assign default language to synced content   │
│  Hook: rental_after_synchronization (priority 10)            │
│  API helpers: rental_pll_assign_product/post/term/terms()    │
└──────────────────────────────┬───────────────────────────────┘
                               │ runs AFTER
                               ▼
┌──────────────────────────────────────────────────────────────┐
│  NEW: translation module                                     │
│  Responsibility: Create translated posts/terms in secondary  │
│  languages with auto-translated content                      │
│  Hook: rental_after_synchronization (priority 20)            │
│  API helpers: rental_translation_queue_product/post/term()   │
└──────────────────────────────────────────────────────────────┘
```

---

## 4. Database schema

### 4.1 `{prefix}_rental_translation_cache`

Persistent cache of translated strings. Survives re-syncs, plugin deactivation/reactivation.

| Column | Type | Description |
|--------|------|-------------|
| `id` | bigint(20) AUTO_INCREMENT | Primary key |
| `source_hash` | char(32) | MD5 of `source_lang:source_text` — indexed for fast lookups |
| `source_text` | longtext | Original text (for debugging/manual review) |
| `source_lang` | varchar(10) | Source language code (default: `en`) |
| `target_lang` | varchar(10) | Target language code (e.g. `fr`, `de`) |
| `translated_text` | longtext | The translated result |
| `provider` | varchar(20) | Which service produced this (`deepl`, `google`) |
| `char_count` | int unsigned | Character count of source_text (for usage tracking) |
| `created_at` | datetime | First translation timestamp |
| `updated_at` | datetime | Last update timestamp (auto-updated on `ON DUPLICATE KEY UPDATE`) |

**Indexes:**

| Index | Columns | Type |
|-------|---------|------|
| `PRIMARY` | `id` | PK |
| `source_target` | `(source_hash, target_lang)` | UNIQUE — the lookup key |
| `target_lang` | `target_lang` | INDEX |
| `provider` | `provider` | INDEX |

**Table creation:** Handled by `Rental_Translation_Cache::maybe_create_table()` on `admin_init`. Uses `dbDelta()` for safe migrations.

---

## 5. Class reference

### 5.1 `Rental_Translation_Service` (interface)

| Method | Returns | Description |
|--------|---------|-------------|
| `translate($text, $target_lang, $source_lang)` | `string` | Translate a single string |
| `translate_batch($texts, $target_lang, $source_lang)` | `string[]` | Translate multiple strings in one API call |
| `is_available()` | `bool` | Whether the provider is configured (API key set) |
| `get_provider_name()` | `string` | Human-readable name (`'DeepL'`, `'Google Cloud Translation'`) |

### 5.2 `Rental_Translation_DeepL`

Implements `Rental_Translation_Service`. Uses DeepL API v2 (`/v2/translate`).

- Auto-detects free vs pro endpoint based on key suffix (`:fx` = free)
- Batch limit: 50 texts per API call
- In-memory cache avoids duplicate API calls within one request
- HTML tag handling enabled (`tag_handling: 'html'`)
- Errors logged via `Project_WP_Logger` and `ErrorHandler` (if available)

### 5.3 `Rental_Translation_Google`

Implements `Rental_Translation_Service`. Uses Google Cloud Translation API v2.

- Batch limit: 100 texts per API call
- HTML format support
- HTML entities auto-decoded in responses

### 5.4 `Rental_Translation_Factory`

Singleton factory. Reads `rental_translation_provider` option and returns the corresponding provider instance. `reset()` clears the cached instance (used after settings change).

### 5.5 `Rental_Translation_Cache`

Manages the `wp_rental_translation_cache` table. Key methods:

| Method | Description |
|--------|-------------|
| `get($text, $target_lang, $source_lang)` | Single lookup — returns translated text or `null` |
| `get_batch($texts, $target_lang, $source_lang)` | Batch lookup — returns `{hits, misses}` arrays |
| `set($source, $translated, $target_lang, ...)` | Store one translation (`INSERT ... ON DUPLICATE KEY UPDATE`) |
| `set_batch($pairs, $target_lang, ...)` | Store multiple translations |
| `get_stats()` | Returns `{total, by_lang, total_chars_saved}` for admin UI |
| `clear_all()` | Truncate the entire cache |
| `clear_language($lang)` | Delete cache entries for one language |
| `maybe_create_table()` | Create/migrate the table schema |

### 5.6 `Rental_Translation_Usage`

Fetches real-time usage data from the DeepL API (`GET /v2/usage`).

| Method | Description |
|--------|-------------|
| `get_usage($force_refresh)` | Returns usage data array (cached 5 minutes via WP transient) |
| `clear_cache()` | Delete the transient to force fresh fetch |

Returns: `{provider, character_count, character_limit, percent_used, characters_remaining, billing_period_start, billing_period_end, fetched_at}`

### 5.7 `Rental_Translation_Processor`

The core translation engine. For each post or term:

1. Checks the DB cache (`get_batch`) for existing translations
2. Sends only cache misses to the translation API
3. Stores API results in the DB cache
4. Creates or updates translated WordPress posts/terms
5. Links translations via Polylang API
6. Copies meta data and term relationships

All steps are logged with timing via `Project_WP_Logger`.

| Method | Description |
|--------|-------------|
| `translate_post($post_id, $post_type, $fields)` | Translate a post into all secondary languages |
| `translate_term($term_id, $taxonomy, $fields)` | Translate a term into all secondary languages |
| `is_ready()` | Whether Polylang is active + provider is configured |

### 5.8 `Rental_Translation_Queue`

WP-Cron-based queue stored in `wp_options`.

| Method | Description |
|--------|-------------|
| `enqueue_post($post_id, $post_type, $fields)` | Add a post to the queue |
| `enqueue_term($term_id, $taxonomy, $fields)` | Add a term to the queue |
| `enqueue_bulk_sync_items()` | Scan for all untranslated content and enqueue it |
| `process()` | Process one batch (called by WP-Cron every minute) |
| `get_pending_count()` | Number of items in queue |

Processing: 20 items per cron tick. Uses option-based locking (300s timeout) to prevent concurrent runs.

### 5.9 `Rental_Translation_Admin`

Embeds translation settings into the existing Rentopian Settings page (General Settings tab).

| Method | Description |
|--------|-------------|
| `render_settings_section()` | Static — outputs the HTML section. Call from `rental_settings_page()`. |
| `ajax_save_settings()` | AJAX handler for saving translation options |
| `ajax_queue_all()` | AJAX handler to scan and enqueue all untranslated items |
| `ajax_clear_queue()` | AJAX handler to clear the pending queue |
| `ajax_process_now()` | AJAX handler to process one batch immediately (for local testing) |
| `ajax_clear_cache()` | AJAX handler to truncate the translation cache |
| `ajax_refresh_usage()` | AJAX handler to force-refresh DeepL usage data |

### 5.10 `Rental_Translation_Image_Propagator`

Hooks into `updated_post_meta`, `added_post_meta`, `updated_term_meta`, `added_term_meta`. When `_thumbnail_id` or `_product_image_gallery` is updated on a default-language post, the same value is copied to all Polylang translation posts.

**Why needed:** Product images sync separately via a background job. By the time translations are created, `_thumbnail_id` may contain a Rentopian image ID (not yet resolved to a WP attachment). The image sync job later replaces it with a real attachment ID, but only on the source post. The propagator bridges this gap.

**Safety guards:**
- Only triggers for `_thumbnail_id`, `_product_image_gallery`, `thumbnail_id`, `banner_id`
- Only propagates from default-language posts (not from translations)
- Verifies the value is a real WP attachment (not a Rentopian UUID)
- Recursion guard prevents infinite loops
- Also handles term meta (categories, brands)

---

## 6. Workflows

See [WORKFLOWS.md](WORKFLOWS.md) for detailed step-by-step workflows.

---

## 7. Settings UI reference

The translation settings are rendered as a section within the existing **Rentopian Sync → Settings → General Settings** tab. Added by calling:

```php
<?php Rental_Translation_Admin::render_settings_section(); ?>
```

### 7.1 Settings fields

| Field | Option key | Type | Default | Description |
|-------|-----------|------|---------|-------------|
| Enable Auto-Translation | `rental_translation_enabled` | checkbox | `0` | Master toggle. All dependent fields hidden when off. |
| Translation Provider | `rental_translation_provider` | radio | `deepl` | `deepl` or `google` |
| Translation API Key | `rental_translation_api_key` | password | empty | Provider API key |

### 7.2 Info displays

| Display | Source | Description |
|---------|--------|-------------|
| Active Languages | `pll_languages_list()` | Read-only list of configured Polylang languages |
| Translation Queue | `Rental_Translation_Queue::get_pending_count()` | Pending items + next scheduled run time |
| API Usage | `Rental_Translation_Usage::get_usage()` | Character count/limit + progress bar + billing period |
| Translation Cache | `Rental_Translation_Cache::get_stats()` | Total cached, per-language breakdown, chars saved |

### 7.3 Action buttons

| Button | AJAX Action | Description |
|--------|-------------|-------------|
| Queue All for Translation | `rental_translation_queue_all` | Scans for untranslated content and adds to queue |
| Process Queue Now | `rental_translation_process_now` | Manually triggers one batch (20 items). For local dev. |
| Clear Queue | `rental_translation_clear_queue` | Removes all pending items |
| Clear Cache | `rental_translation_clear_cache` | Truncates `wp_rental_translation_cache`. Use with caution. |
| Refresh (usage) | `rental_translation_refresh_usage` | Force-fetches usage from DeepL API |

---

## 8. WP Options reference

### 8.1 Settings options

| Option | Type | Description |
|--------|------|-------------|
| `rental_translation_enabled` | string (`'0'`/`'1'`) | Master toggle |
| `rental_translation_provider` | string | `'deepl'` or `'google'` |
| `rental_translation_api_key` | string | Provider API key |

### 8.2 Queue options

| Option | Type | Description |
|--------|------|-------------|
| `rental_translation_queue` | array | Serialised queue of pending items |
| `rental_translation_queue_lock` | int | Unix timestamp — lock expires after this time |

### 8.3 Cache options

| Option | Type | Description |
|--------|------|-------------|
| `rental_translation_cache_db_version` | string | Current schema version (for migrations) |

### 8.4 Transients

| Transient | TTL | Description |
|-----------|-----|-------------|
| `rental_translation_usage` | 300s | Cached DeepL usage API response |

---

## 9. WP-Cron reference

### 9.1 Custom interval

| Name | Interval | Registered by |
|------|----------|---------------|
| `every_minute` | 60 seconds | `Rental_Translation_Queue::register_cron_interval()` |

### 9.2 Cron hook

| Hook | Callback | When scheduled |
|------|----------|----------------|
| `rental_process_translation_queue` | `Rental_Translation_Queue::process()` | Automatically when items are added to queue. Unscheduled when queue is empty. |

### 9.3 Local development

WP-Cron only fires on page visits. On local environments with no traffic, use:

- **"Process Queue Now" button** in admin settings (processes 20 items per click)
- **System cron:** `* * * * * curl -s http://yourlocal.test/wp-cron.php > /dev/null 2>&1`

---

## 10. Translation cache

### 10.1 How it works

The cache table (`wp_rental_translation_cache`) maps source text to translated text using a composite key of `(source_hash, target_lang)`. The `source_hash` is `MD5(source_lang + ':' + source_text)`.

**Lookup flow:**

```
Processor receives "Camping Chair" to translate to French
  → hash = MD5("en:Camping Chair") → "a7b3c9..."
  → SELECT translated_text WHERE source_hash = 'a7b3c9...' AND target_lang = 'fr'
  → HIT? → return "Chaise de camping" (zero API cost)
  → MISS? → call DeepL API → store result → return
```

**Storage flow:**

```
INSERT INTO wp_rental_translation_cache (source_hash, source_text, target_lang, translated_text, ...)
VALUES ('a7b3c9...', 'Camping Chair', 'fr', 'Chaise de camping', ...)
ON DUPLICATE KEY UPDATE translated_text = VALUES(translated_text), updated_at = CURRENT_TIMESTAMP
```

### 10.2 Re-sync behaviour

| Scenario | Cache effect | API cost |
|----------|-------------|----------|
| Re-sync, same content | 100% cache hits | Zero |
| Re-sync, some products renamed | Cache hits for unchanged, misses for changed | Only changed texts |
| New language added | Cache miss for all content in new language | Full translation for new language only |
| Cache cleared manually | All cache misses | Full re-translation of everything |

### 10.3 Admin stats

The settings page shows: total cached translations, per-language breakdown, and total API characters saved by the cache (sum of `char_count` for all cached entries).

---

## 11. API usage tracking

### 11.1 DeepL

The module calls `GET /v2/usage` with the configured API key. Free keys (ending in `:fx`) use `api-free.deepl.com`; Pro keys use `api.deepl.com`.

Response fields used:

| Field | Usage |
|-------|-------|
| `character_count` | Characters consumed this billing period |
| `character_limit` | Monthly limit (500K for free, varies for Pro) |
| `start_time` / `end_time` | Billing period dates (Pro only) |

The admin UI displays a colour-coded progress bar: green (< 70%), yellow (70-90%), red (> 90%).

### 11.2 Google

Google Cloud Translation does not provide a simple usage API. The settings page shows a note directing users to the Google Cloud Console.

### 11.3 Caching

Usage data is cached in a WP transient (`rental_translation_usage`) for 5 minutes. The "Refresh" link in admin forces a fresh API call.

---

## 12. Image propagation

### 12.1 The timing problem

```
Timeline:
  t0: Bulk sync creates EN product #100 with _thumbnail_id = "rentopian-uuid-abc"
  t1: Translation queue creates FR product #200, copies _thumbnail_id = "rentopian-uuid-abc"
  t2: Image sync job downloads image, creates WP attachment #500
  t3: Image sync calls update_post_meta(100, '_thumbnail_id', 500) on EN product
      → EN product now has real image
      → FR product #200 still has "rentopian-uuid-abc" → BROKEN
```

### 12.2 The solution

`Rental_Translation_Image_Propagator` hooks into WordPress's `updated_post_meta` and `added_post_meta` actions. At `t3`, when the image sync updates `_thumbnail_id` on the EN product:

```
update_post_meta(100, '_thumbnail_id', 500)
  → WordPress fires 'updated_post_meta' hook
  → Propagator catches it:
      → Is this _thumbnail_id or _product_image_gallery? YES
      → Is post #100 in the default language? YES
      → Is 500 a real WP attachment? YES (get_post_type(500) === 'attachment')
      → Get all translations: pll_get_post_translations(100) → { en: 100, fr: 200 }
      → update_post_meta(200, '_thumbnail_id', 500)
      → FR product now has the real image
```

### 12.3 Covered meta keys

| Meta key | Entity | Description |
|----------|--------|-------------|
| `_thumbnail_id` | post | Product/variation featured image |
| `_product_image_gallery` | post | Product image gallery (comma-separated IDs) |
| `thumbnail_id` | term | Category/brand thumbnail |
| `banner_id` | term | Category banner image |

---

## 13. Logging

All translation operations are logged to `wp-content/uploads/wc-logs/rentopian-translation.log` via `Project_WP_Logger`.

### 13.1 Log source

All entries use source: `rentopian-translation`

### 13.2 Log levels used

| Level | Used for |
|-------|----------|
| `critical` | Fatal batch errors (catch-all) |
| `error` | Failed post/term creation, API errors, unknown item types |
| `warning` | Queue lock contention, missing posts/terms, skipped items |
| `notice` | Posts without language assigned, reused existing terms |
| `info` | Batch start/end, per-item start/end, cache hit/miss summaries, bulk enqueue |
| `debug` | API call timing, per-language create/update, cache store counts, image propagation |

### 13.3 Log structure

**Batch processing (3-point logging):**

```
[info]  BATCH START — Processing 20 items (of 347 total in queue). Batch size limit: 20.
[debug] It took 1.234s to run post #5412 (product). OK
[error] It took 0.012s to run post #5499 (product). FAILED: Post not found
[info]  It took 17.342s to run queue_batch. BATCH END — Processed: 19, Errors: 1, Remaining: 327.
```

**Per-item processing (3-point logging):**

```
[info]  POST START — #5412 (product) "Camping Chair" → languages: [fr, de]
[info]  [post #5412 → fr] Cache: 0 hits, 3 misses. Calling DeepL API for 3 texts (487 chars).
[debug] It took 523.00ms to run API translate_batch. [post #5412 → fr] 3 texts sent, 3 returned.
[debug] [post #5412 → fr] Stored 3 new translations in DB cache.
[debug] [post #5412 → fr] Created new translation post #6801.
[info]  It took 1.234s to run translate_post #5412. POST END — created 1, updated 1, total: 3 languages.
```

**Re-sync (cache hits):**

```
[debug] [post #5412 → fr] All 3 texts served from cache. No API call needed.
```

**Image propagation:**

```
[debug] Image propagated: _thumbnail_id=4521 on post #5412 (product) → 2 translations.
```

---

## 14. Error handling

### 14.1 API failures

When DeepL or Google returns an error (HTTP non-200, timeout, malformed response):
1. Error is logged via `Project_WP_Logger` and `ErrorHandler` (if available)
2. Original (untranslated) texts are returned as-is
3. The queue item is removed (not retried automatically)
4. The item can be re-queued via "Queue All" admin button

### 14.2 Post/term creation failures

If `wp_insert_post()` or `wp_insert_term()` returns a `WP_Error`:
1. Error is logged with the specific WordPress error message
2. Other languages continue processing (one language failing doesn't block others)
3. The translation map is saved with whatever languages succeeded

### 14.3 Queue processing failures

If an exception is thrown during item processing:
1. The specific item error is logged
2. Processing continues with the next item in the batch
3. If the entire batch orchestration fails (catch-all), a `critical` log entry is written

### 14.4 Lock contention

If a cron process can't acquire the lock (another process is running):
1. A warning is logged
2. The process exits silently
3. Next cron tick will try again

---

## 15. Extending

### 15.1 Add a new translation provider

1. Create `class-rental-translation-yourprovider.php` implementing `Rental_Translation_Service`
2. Add it to the `match` statement in `Rental_Translation_Factory::make()`
3. Add a radio button option in `Rental_Translation_Admin::render_settings_section()`
4. Add the require in `rental-translation-bootstrap.php`

### 15.2 Filter the language code mapping

```php
add_filter( 'rental_translation_language_map', function( $map, $pll_slug ) {
    $map['pt'] = 'PT-BR';  // Use Brazilian Portuguese
    return $map;
}, 10, 2 );
```

### 15.3 Add new translatable fields

To translate additional fields (e.g. SEO titles), modify the `$texts_to_translate` arrays in `Rental_Translation_Processor::translate_post()` and `translate_term()`.

### 15.4 Change batch size

Modify `$batch_size` property in `Rental_Translation_Queue` (default: 20 items per cron tick).

---

## 16. Testing checklist

### 16.1 Basic functionality

- [ ] Enable auto-translation, set DeepL API key, save settings
- [ ] Run a full Rentopian sync
- [ ] Verify products appear in French with translated titles/descriptions
- [ ] Verify categories, tags, attributes appear translated
- [ ] Check that product meta (price, SKU, stock) is preserved on translations
- [ ] Check the translation log for errors

### 16.2 Cache

- [ ] Run sync → note API usage
- [ ] Run sync again → verify zero additional API usage (all cache hits)
- [ ] Rename a product in Rentopian → re-sync → verify only the renamed product uses API
- [ ] Clear cache from admin → re-queue → verify full API usage

### 16.3 Images

- [ ] Run full sync + file sync
- [ ] Check French products have correct thumbnails
- [ ] Add a new product via webhook → verify image propagates to French translation
- [ ] Check the log for "Image propagated" entries

### 16.4 Queue / Cron

- [ ] Queue all items → verify pending count increases
- [ ] Click "Process Queue Now" → verify count decreases by ~20
- [ ] Clear queue → verify count is 0
- [ ] On a live server, verify WP-Cron processes items automatically

### 16.5 Edge cases

- [ ] Disable Polylang → verify all translation code silently no-ops
- [ ] Remove API key → verify queue processes but translations use original text
- [ ] Add a third language → verify only the new language triggers API calls

---

## 17. Integration steps

### 17.1 Plugin file

```php
// After existing Polylang includes:
require_once RENTOPIAN_SYNC_PATH . '/includes/class-rental-polylang-integration.php';
require_once RENTOPIAN_SYNC_PATH . '/includes/rental-polylang-api-helpers.php';

// Translation module:
require_once RENTOPIAN_SYNC_PATH . '/includes/translation/rental-translation-bootstrap.php';
```

### 17.2 Settings page

In `rental_settings_page()`, inside the General Settings form, after the "Visual Components" section:

```php
<?php Rental_Translation_Admin::render_settings_section(); ?>
```

### 17.3 Webhook handlers (api.php)

After every `rental_pll_assign_*()` call, add the corresponding `rental_translation_queue_*()` call. Example:

```php
// EXISTING:
rental_pll_assign_product( (int) $product_id, array_map( 'intval', $variant_ids ) );

// ADD:
rental_translation_queue_product( (int) $product_id, array_map( 'intval', $variant_ids ) );
```

Full list of integration points documented in `INTEGRATION-GUIDE.md`.

---

## 18. Documentation maintenance

- When adding a **new translation provider**, update sections 3.1, 5, and 15.1.
- When adding **new translatable fields**, update sections 1.4 and 5.7.
- When changing **database schema**, update section 4 and bump `DB_VERSION` in the cache class.
- When changing **queue behaviour**, update sections 5.8, 6 (workflows), and 9.
- When adding **new admin settings**, update section 7.
- When changing **meta propagation keys**, update section 12.3.
