# Auto-Translation (Polylang) — Workflows

This document summarizes the main workflows for the Auto-Translation module. For full context, see [README.md](README.md).

---

## 1. System initialization (load order)

```
Plugin load
    → rentopian-sync.php
    → require class-rental-polylang-integration.php
    → require rental-polylang-api-helpers.php
    → require translation/rental-translation-bootstrap.php
        → Load all 12 class files (dependency order)
        → add_filter('cron_schedules') → register 'every_minute' interval
        → Rental_Translation_Queue::get_instance() → register cron hook
        → Rental_Translation_Image_Propagator::init() → hook update_post_meta / update_term_meta
        → if (is_admin):
            → new Rental_Translation_Admin() → register 6 AJAX hooks
            → add_action('admin_init') → ensure cache table exists (dbDelta)
        → add_action('rental_after_synchronization', ..., 20) → bulk enqueue hook
```

---

## 2. Bulk sync → Translation queue

```
Admin triggers full Rentopian sync
    → rental_synchronization() runs
        → Products, variations, categories, tags, attributes created (English)
        → do_action('rental_after_synchronization')

            → Priority 10: Rental_Polylang_Integration::handle_post_sync()
                → Assigns default language to ALL synced content

            → Priority 20: Translation module hook (bootstrap.php)
                → rental_translation_enabled == '1'?
                    → YES:
                        → Rental_Translation_Queue::enqueue_bulk_sync_items()
                            → Log: "Bulk enqueue started"
                            → Scan products + variations (no translation for secondary langs)
                            → Scan product_cat, product_tag, product_brand, pa_* terms
                            → Enqueue each untranslated item
                            → Schedule WP-Cron if not already scheduled
                            → Log: "Added 347 items to queue. Total pending: 347"
                    → NO: skip
```

---

## 3. Webhook → Translation queue

```
Rentopian webhook fires (product create/update/etc.)
    → api.php handler runs
        → Product/variant/term created or updated in WordPress

    → rental_pll_assign_product( $product_id, $variant_ids )
        → Polylang language assigned to EN posts

    → rental_translation_queue_product( $product_id, $variant_ids )
        → Translation enabled?
            → YES:
                → Get post data (title, content, excerpt)
                → Enqueue post + each variant
                → Schedule WP-Cron
            → NO: no-op
```

---

## 4. Queue processing (WP-Cron tick)

```
WP-Cron fires (every 60 seconds)
    → Rental_Translation_Queue::process()

    ── BATCH START ──────────────────────────────────────────
    → Acquire lock (option-based, 300s timeout)
        → FAIL → Log warning "lock already held" → exit
    → Read queue from wp_options
    → Queue empty? → Unschedule cron → exit
    → Splice first 20 items from queue → $batch
    → Log: "BATCH START — Processing 20 items (of 347 total)"

    ── PER ITEM ─────────────────────────────────────────────
    → Create Rental_Translation_Processor instance
    → For each item in $batch:
        → Start timer
        → item.type == 'post'?
            → processor->translate_post(object_id, post_type, fields)
        → item.type == 'term'?
            → processor->translate_term(object_id, taxonomy, fields)
        → Success? → increment success_count, log timing "OK"
        → Exception? → increment error_count, log error with message + trace

    ── BATCH END ────────────────────────────────────────────
    → Save remaining queue to wp_options
    → Queue still has items? → Keep cron scheduled
    → Queue empty? → Unschedule cron
    → Release lock
    → Log: "BATCH END — Processed: 19, Errors: 1, Remaining: 327. Cron rescheduled."
```

---

## 5. Translate a post (product / variation / set)

```
Rental_Translation_Processor::translate_post($source_post_id, $post_type, $fields)
    │
    ── 1. START ─────────────────────────────────────────────
    │
    ├─ get_post($source_post_id)
    │   └─ Not found? → Log warning → return
    │
    ├─ Log: "POST START — #5412 (product) 'Camping Chair' → [fr, de]"
    │
    ├─ Ensure source has default language
    │   └─ No language? → pll_set_post_language → Log notice
    │
    ├─ Get existing translations: pll_get_post_translations()
    │
    ├─ Prepare fields: title, content, excerpt
    │
    ── 2. MIDWAY (per secondary language) ───────────────────
    │
    ├─ For each lang in [fr, de]:
    │   │
    │   ├─ translate_with_cache(texts, lang)
    │   │   │
    │   │   ├─ DB cache lookup: get_batch(texts, lang)
    │   │   │   → Cache HIT?  → Use cached translation (zero API cost)
    │   │   │   → Cache MISS? → Continue to API
    │   │   │
    │   │   ├─ Log: "[post #5412 → fr] Cache: 2 hits, 1 miss. Calling DeepL for 1 text (45 chars)."
    │   │   │
    │   │   ├─ translator->translate_batch(misses_only)
    │   │   │   → DeepL API call (POST /v2/translate)
    │   │   │   → Log timing: "API translate_batch took 523ms"
    │   │   │
    │   │   ├─ Store API results in DB cache: cache->set()
    │   │   │   → INSERT ... ON DUPLICATE KEY UPDATE
    │   │   │   → Log: "Stored 1 new translation in DB cache"
    │   │   │
    │   │   └─ Return merged results (cache hits + API results)
    │   │
    │   ├─ Translation already exists for this lang?
    │   │   ├─ YES → wp_update_post(existing_id, translated_content)
    │   │   │        → Log: "Updated existing translation post #6801"
    │   │   │
    │   │   └─ NO → create_translated_post()
    │   │        → wp_insert_post(translated_title, content, excerpt)
    │   │        → pll_set_post_language(new_id, lang)
    │   │        → copy_post_meta(source → new)
    │   │        │   → Copy all meta (skip _edit_lock, _edit_last)
    │   │        │   → Copy term relationships (use translated terms)
    │   │        → Log: "Created new translation post #6801"
    │   │
    │   └─ Add to translation_map: { en: 5412, fr: 6801, de: 6802 }
    │
    ── 3. END ───────────────────────────────────────────────
    │
    ├─ pll_save_post_translations(translation_map)
    │
    └─ Log: "POST END — #5412 'Camping Chair': created 1, updated 1, total: 3 languages."
```

---

## 6. Translate a term (category / tag / attribute / brand)

```
Rental_Translation_Processor::translate_term($source_term_id, $taxonomy, $fields)
    │
    ── 1. START ─────────────────────────────────────────────
    │
    ├─ get_term($source_term_id, $taxonomy)
    │   └─ Not found? → Log warning → return
    │
    ├─ Log: "TERM START — #89 (product_cat) 'Seating' → [fr, de]"
    │
    ├─ Ensure source has default language
    │
    ── 2. MIDWAY (per secondary language) ───────────────────
    │
    ├─ For each lang in [fr, de]:
    │   │
    │   ├─ translate_with_cache(name + description, lang)
    │   │   → Same cache lookup/API/store flow as posts (see workflow #5)
    │   │
    │   ├─ Translation already exists?
    │   │   ├─ YES → wp_update_term(existing_id, translated_name, description)
    │   │   │
    │   │   └─ NO → wp_insert_term(translated_name, taxonomy, slug, parent)
    │   │        ├─ term_exists error? → Reuse existing term ID
    │   │        ├─ pll_set_term_language(new_id, lang)
    │   │        └─ copy_term_meta(source → new)
    │   │
    │   └─ Add to translation_map
    │
    ── 3. END ───────────────────────────────────────────────
    │
    ├─ pll_save_term_translations(translation_map)
    │
    └─ Log: "TERM END — #89 'Seating' (product_cat): created 2, total: 3 languages."
```

---

## 7. Translation cache lookup flow

```
translate_with_cache(texts, target_lang)
    │
    ├─ texts = { title: "Camping Chair", content: "A folding chair", excerpt: "" }
    │
    ├─ Filter empty → non_empty = { title: "Camping Chair", content: "A folding chair" }
    │
    ├─ DB cache batch lookup:
    │   cache->get_batch(non_empty, 'fr', 'en')
    │   → SQL: SELECT source_hash, translated_text
    │          FROM wp_rental_translation_cache
    │          WHERE source_hash IN ('a7b3c9...', 'f8e2d1...')
    │            AND target_lang = 'fr'
    │   → Returns: { hits: { title: "Chaise de camping" }, misses: { content: "A folding chair" } }
    │
    ├─ hits found? → Apply to result
    │
    ├─ misses found?
    │   → YES:
    │       → Log: "Cache: 1 hit, 1 miss. Calling DeepL for 1 text (15 chars)"
    │       → translator->translate_batch({ content: "A folding chair" }, 'FR', 'EN')
    │           → DeepL returns: { content: "Une chaise pliante" }
    │       → cache->set("A folding chair", "Une chaise pliante", 'fr', 'en', 'deepl')
    │           → INSERT ... ON DUPLICATE KEY UPDATE
    │       → Apply to result
    │   → NO:
    │       → Log: "All 2 texts served from cache. No API call needed."
    │
    └─ Return: { title: "Chaise de camping", content: "Une chaise pliante", excerpt: "" }
```

---

## 8. Image propagation flow

```
Image sync background job processes images:
    → Rental_Image_Relation_Attacher::attach_to_products()
        → update_post_meta( 5412, '_thumbnail_id', 4521 )

    → WordPress fires 'updated_post_meta' action

    → Rental_Translation_Image_Propagator::on_post_meta_updated()
        → meta_key == '_thumbnail_id'? YES
        → Not currently propagating (recursion guard)? YES
        → Polylang active? YES
        → Post #5412 is default language ('en')? YES
        → Post type is 'product' or 'product_variation'? YES
        → Value 4521 is a real WP attachment? YES (get_post_type(4521) == 'attachment')
        → pll_get_post_translations(5412) → { en: 5412, fr: 6801, de: 6802 }
        → Set propagating = true (recursion guard ON)
        → update_post_meta( 6801, '_thumbnail_id', 4521 )  ← FR product gets image
        → update_post_meta( 6802, '_thumbnail_id', 4521 )  ← DE product gets image
        → Set propagating = false
        → Log: "Image propagated: _thumbnail_id=4521 on post #5412 (product) → 2 translations."
```

Same flow applies to:
- `_product_image_gallery` on products/variations
- `thumbnail_id` on category/brand terms
- `banner_id` on category terms

---

## 9. Admin: Configure translation

```
Admin navigates to Rentopian Sync → Settings → General Settings
    → Scrolls to "Auto-Translation (Polylang)" section
    → Checks "Enable Auto-Translation"
        → Dependent fields appear (provider, API key, queue, usage, cache)
    → Selects "DeepL" provider
    → Enters API key
    → Clicks "Save General Settings"
        → Form submits via existing AJAX handler
        → Rental_Translation_Admin::save_on_general_section() fires (priority 5)
            → update_option('rental_translation_enabled', '1')
            → update_option('rental_translation_provider', 'deepl')
            → update_option('rental_translation_api_key', 'xxx...')
            → Rental_Translation_Factory::reset() (clear cached provider)
```

---

## 10. Admin: Queue all and process

```
Admin clicks "Queue All for Translation"
    → JS: fetch(ajaxurl, { action: 'rental_translation_queue_all' })
    → Rental_Translation_Admin::ajax_queue_all()
        → Rental_Translation_Queue::enqueue_bulk_sync_items()
            → Scan all products/variations without full translations
            → Scan all terms without full translations
            → Add to queue
            → Schedule WP-Cron
    → Response: { success: true, message: "347 items queued", pending: 347 }
    → UI updates pending count

Admin clicks "Process Queue Now" (local testing)
    → JS: fetch(ajaxurl, { action: 'rental_translation_process_now' })
    → Rental_Translation_Admin::ajax_process_now()
        → Rental_Translation_Queue::process() (synchronous, one batch)
    → Response: { success: true, message: "Processed 20 items. 327 remaining.", pending: 327 }
    → UI updates pending count
    → Admin clicks again... and again... until queue is empty
```

---

## 11. Full end-to-end: New product via webhook

```
Timeline:

t0: Rentopian creates a new product "Camping Chair" with 2 variants
    → Webhook POST to api.php
    → rentopian_product_create() runs
        → wp_insert_post() → product #5412
        → wp_insert_post() → variant #5413, #5414
        → Assign meta, categories, tags, attributes

t1: Polylang language assignment
    → rental_pll_assign_product( 5412, [5413, 5414] )
        → pll_set_post_language( 5412, 'en' )
        → pll_set_post_language( 5413, 'en' )
        → pll_set_post_language( 5414, 'en' )

t2: Translation queued
    → rental_translation_queue_product( 5412, [5413, 5414] )
        → Enqueue 3 items (1 product + 2 variants)
        → Schedule WP-Cron

t3: WP-Cron fires (within 60 seconds)
    → Queue processes 3 items:
        → #5412: DB cache MISS → DeepL: "Camping Chair" → "Chaise de camping"
            → Create FR post #6801, link translations
        → #5413: DB cache MISS → DeepL: "Camping Chair - Blue" → "Chaise de camping - Bleu"
            → Create FR post #6802, set parent = #6801, link translations
        → #5414: similar

t4: Image sync job runs (background, may be minutes later)
    → Downloads product image, creates WP attachment #500
    → update_post_meta( 5412, '_thumbnail_id', 500 )
    → Image propagator fires:
        → update_post_meta( 6801, '_thumbnail_id', 500 )
    → French product #6801 now has the correct image

t5: User visits /fr/shop/
    → Sees "Chaise de camping" with correct image ✓
```

---

## 12. Full end-to-end: Re-sync (all content)

```
Admin triggers re-sync
    → rental_synchronization() wipes and recreates all WP content (new IDs)
    → Products get fresh WP IDs (e.g. old #5412 → new #7001)
    → do_action('rental_after_synchronization')

Priority 10: Polylang assigns EN language to all new posts/terms

Priority 20: Translation module
    → enqueue_bulk_sync_items() scans 500 untranslated items
    → Queue starts processing:
        → For "Camping Chair" → fr:
            → DB cache: MD5("en:Camping Chair") → HIT! "Chaise de camping"
            → No API call needed (zero cost)
            → Create new FR post #8001, link to #7001
        → Repeat for all items
    → Result: All 500 items translated, mostly from cache
    → API usage: only items with changed text consume quota

Image sync runs separately:
    → Updates _thumbnail_id on EN products
    → Propagator automatically copies to FR products
```

---

## 13. State diagram: Queue item lifecycle

```
    ┌─────────────┐
    │   CREATED    │ ← enqueue_post() / enqueue_term()
    └──────┬──────┘
           │ WP-Cron picks it up
           ▼
    ┌─────────────┐
    │  PROCESSING  │ ← translate_post() / translate_term()
    └──┬───────┬──┘
       │       │
   success   error
       │       │
       ▼       ▼
    ┌─────┐  ┌────────┐
    │DONE │  │LOGGED  │ ← Error logged, item removed from queue
    └─────┘  │& DONE  │   (can be re-queued via "Queue All")
             └────────┘
```

Note: Items are always removed from the queue after processing, whether successful or not. To retry failed items, click "Queue All" — items with missing translations will be re-detected and re-queued.
