# Background File Sync — Workflows

This document summarizes the main workflows for the Background File Sync module. For full context, see [README.md](README.md).

---

## 1. System initialization (load order)

```
Plugin load
    → rentopian-sync.php
    → require class-process-timer.php         (Rental_Timer)
    → require class-logger-with-timer.php     (Project_WP_Logger)
    → require file-sync/bootstrap.php
        → Load all 15 class files (dependency order)
        → require compat-functions.php        (40+ aliases)
        → add_action('rest_api_init')         → Rental_Sync_REST_Controller::register_routes
        → new Rental_Sync_Ajax_Controller()   → register 9 AJAX hooks
    → require functions.php
        → Image subsizing functions skipped (function_exists guards)
    → require error_handler/ErrorHandler.php
```

---

## 2. Start initial sync

```
Admin clicks "Sync Files"
    → JS: $.post(ajaxurl, { action: 'rental_sync_files_bg' })
    → Rental_Sync_Ajax_Controller::sync_files_bg()
        → Rental_Timer::start_persistent()
        → Rental_Sync_Scheduler::schedule( MODE_SYNC )
            → Generate sync_id (UUID) + wp_token (32-char random)
            → update_option('rental_current_sync_id', sync_id)
            → Fetch image count: rental_curl('files/images/count', api_key)
            → Rental_Sync_Session_Manager::cancel_previous_sessions(sync_id)
            → Rental_Sync_Session_Manager::update(sync_id, { status: CREATED, ... })
            → Rental_Sync_Log_Repository::write(sync_id, 'info', 'Sync started')
            → Init options: last_id=0, processed=0, completed=false
            → rental_curl('sync/start', { wp_endpoint, wp_token, sync_id, chunk_size })
    → JS receives { success: true, sync_id: '...', response_from_rental: {...} }
```

---

## 3. Process chunk (called by Laravel)

```
Laravel dispatches:
    → POST /rentopian-sync/v1/process-chunk
        { sync_id, start_index, chunk_size, wp_token, mode }

    → Rental_Sync_REST_Controller::process_chunk_handler()
        → Validate: sync_id, start_index, chunk_size, wp_token present?
        → Load session: Rental_Sync_Session_Manager::get(sync_id)
        → Token match?      NO → 403
        → Session canceled?  YES → return { status: CANCELED }
        → Generation match?  NO → 409 (stale)
        → Rental_Chunk_Worker::process(start, limit, sync_id, is_resync)
            → Acquire lock: set_transient('rental_sync_lock_{id}', ..., 120)
                → FAIL → return { completed: false } (tell Laravel to retry)
            → Delegate to uploader (see workflow #4 or #5)
            → Read results from options
            → Update session: last_index, failed_ids, status
            → Write log entry
            → Release lock
            → If completed:
                → update_option('rental_synchronize_status', 1)
                → Rental_Timer::stop_persistent()
                → Write completion log
        → return { success, last_index, failed_ids, failed_count, completed }

    → Laravel reads last_index → computes next start_index
    → Repeat until completed=true
```

---

## 4. Sync uploader (initial)

```
Rental_Sync_Uploader::process(start, limit, sync_id)
    → set_time_limit(800)
    → Load already-uploaded from rental_image_relations (WHERE rental_id > start)
    → rental_curl('files/images/stream', { start, limit, uploaded_images })
    → No images returned? → mark completed → return

    → For each image:
        │
        ├─ Cancel check: session.status == CANCELED? → stop, return
        ├─ Generation check: mismatch? → break
        │
        ├─ Per-image lock: get_transient(lock_{id})
        │   └─ Already locked? → skip (continue)
        │
        ├─ image.already_uploaded == true?
        │   └─ YES → clear stale thumbnails → release lock → skip
        │
        ├─ Exists in relations table?
        │   ├─ YES → reuse attach_id
        │   └─ NO → Rental_Image_Downloader::download_and_attach()
        │       └─ Returns 0? → release lock → skip
        │
        ├─ Update cursor: rental_products_img_last_id_{sync_id}
        ├─ Release per-image lock
        ├─ Rental_Image_Relation_Attacher::attach_to_all()
        └─ Increment processed count

    → Bulk INSERT into rental_image_relations
    → commit_variant_galleries()
    → commit_set_galleries()
```

---

## 5. Resync uploader

```
Rental_Resync_Uploader::process(start, limit, sync_id)
    → rental_curl('files/images/stream', { start, limit })
    → No images? → mark completed → return

    → For each image:
        │
        ├─ Per-image lock
        │
        ├─ Rental_Image_Downloader::resolve_existing_attachment(rental_id, url)
        │   ├─ Check rental_image_relations table
        │   ├─ Check _wp_attached_file postmeta by filename
        │   └─ Check posts.guid by filename
        │
        ├─ Still no attachment?
        │   └─ Rental_Image_Downloader::download_and_attach()
        │
        ├─ $wpdb->replace() into rental_image_relations
        ├─ Rental_Image_Relation_Attacher::attach_to_all()
        └─ Release lock

    → commit_variant_galleries()
    → commit_set_galleries()
    → Update cursor + processed count options
```

---

## 6. Image download pipeline

```
Rental_Image_Downloader::download_and_attach(url, rental_id, sync_id)
    │
    ├─ download_url(url) → tmp_file
    │   └─ WP_Error? → mark_failed → return 0
    │
    ├─ add_filter('wp_check_filetype_and_ext', 'rental_bypass_mime_check')
    ├─ wp_upload_bits(basename(url), null, file_get_contents(tmp_file))
    │   ├─ upload['error']? → mark_failed → cleanup_tmp → return 0
    │   └─ upload['file'] missing? → mark_failed → cleanup_tmp → return 0
    │
    ├─ wp_insert_attachment(attachment_data, upload['file'], 0)
    │   └─ WP_Error? → mark_failed → cleanup_tmp → return 0
    │
    ├─ Rental_Image_Performance_Filter::enable()
    │   ├─ big_image_size_threshold → 2560
    │   ├─ intermediate_image_sizes_advanced → remove 2048x2048, 1536x1536, large
    │   └─ image_editor_output_format → PNG→JPEG
    │
    ├─ Rental_Image_Subsizer::update_metadata_with_fallback()
    │   ├─ Try: make_subsizes()
    │   │   ├─ Scale to 2560 threshold
    │   │   ├─ wp_create_image_subsizes()
    │   │   └─ Fallback: resize_multiple_gd() (manual GD)
    │   ├─ Fallback: wp_generate_attachment_metadata()
    │   └─ Last resort: register minimal meta (width, height, file)
    │
    ├─ Rental_Image_Performance_Filter::disable()
    ├─ cleanup_tmp(tmp_file)
    └─ return attach_id
```

---

## 7. Image relation attachment

```
Rental_Image_Relation_Attacher::attach_to_all(image, attach_id, &vg, &sg)
    │
    ├─ attach_to_products()
    │   → For each product_id in image.products:
    │     → Lookup WC product via rental_product_relations
    │     → If image.is_thumbnail → set_post_thumbnail(product_id, attach_id)
    │     → Else → append to _product_image_gallery
    │
    ├─ attach_to_term_meta(image_id, attach_id, 'rental_img_category_rel', 'thumbnail_id')
    │   → Lookup term via option → update_term_meta(term_id, 'thumbnail_id', attach_id)
    │
    ├─ attach_to_term_meta(..., 'rental_banner_img_category_rel', 'banner_id')
    │
    ├─ attach_variant_thumbnails(image_id, attach_id)
    │   → Lookup variant_ids via rental_variant_relations
    │   → For each variant: set_post_thumbnail(variant_id, attach_id)
    │
    ├─ attach_attribute_swatches(image_id, attach_id)
    │   → Set 'slctd_img' / 'sw_image' term meta for attribute values
    │
    ├─ attach_to_term_meta(..., 'rental_img_brand_rel', 'thumbnail_id')
    │
    ├─ collect_variant_gallery(image, attach_id, ..., &variant_gallery)
    │   → Accumulate attach_ids per variant_id for bulk commit later
    │
    └─ collect_set_gallery(image, attach_id, ..., &set_gallery)
        → Accumulate attach_ids per set_id for bulk commit later

After loop:
    → commit_variant_galleries(variant_gallery)
        → For each variant: update_post_meta(id, 'zoo-cw-variation-gallery', ids)
    → commit_set_galleries(set_gallery)
        → For each set: update_post_meta(id, '_product_image_gallery', ids)
```

---

## 8. Cancel single sync

```
Admin clicks "Cancel"
    → JS: $.post(ajaxurl, { action: 'rental_cancel_sync_bg' })
    → Rental_Sync_Ajax_Controller::cancel_sync_bg()
        → sync_id = get_option('rental_current_sync_id')
        → Rental_Sync_Session_Manager::update(sync_id, { status: CANCELED })
        → Rental_Sync_Session_Manager::release_lock(sync_id)
        → rental_curl('sync/cancel', { sync_id })
        → Rental_Sync_Log_Repository::write(sync_id, 'warning', 'Canceled by admin')
    → JS receives { success: true }

    Next chunk request from Laravel:
        → REST controller checks session.status == CANCELED
        → Returns { success: false, status: CANCELED }
        → Laravel stops dispatching
```

---

## 9. Cancel ALL syncs

```
Admin clicks "Cancel All"
    → JS: $.post(ajaxurl, { action: 'rental_cancel_all_sync_bg' })
    → Rental_Sync_Ajax_Controller::cancel_all_sync_bg()
        → Rental_Sync_Session_Manager::generation_increment()
        → update_option('rental_sync_all_canceled', 1)
        → Rental_Sync_Session_Manager::cancel_all_sessions()
        → Delete all transients: rental_image_download_lock_*, rental_sync_lock_*
        → Clear rental_current_sync_id + wp_token
        → rental_curl('sync/cancel-all', {})
            └─ Fallback: per-session cancel calls
    → JS receives { success: true, generation: N }

    Any subsequent chunk request:
        → REST controller: session.generation != current → 409 Conflict
        → Laravel discards the session
```

---

## 10. Resume stuck sync

```
Admin clicks "Resume"
    → JS: $.post(ajaxurl, { action: 'rental_resume_sync_bg', sync_id: '...' })
    → Rental_Sync_Ajax_Controller::resume_sync_bg()
        → sync_id provided? Use it. Else:
            → Rental_Sync_Session_Manager::pick_latest_broken_sync_id()
              (most recent session with status != COMPLETED and != CANCELED)
        → Rental_Sync_Session_Manager::prepare_for_resume(sync_id)
            → Generate new wp_token
            → Set session.generation = current generation
            → Release old locks
            → Set status = PROCESSING
        → start_index = Rental_Sync_Session_Manager::compute_resume_index(sync_id)
            → Priority: option rental_products_img_last_id_{sync_id}
                      → MAX(last_index) from file_sync_log
                      → MAX(rental_id) from image_relations
                      → 0
        → rental_curl('sync/start', { wp_endpoint, wp_token, sync_id, start_index, chunk_size=1 })
    → Laravel resumes dispatching from computed index
```

---

## 11. Retry failed images

```
Admin clicks "Retry Failed"
    → JS: $.post(ajaxurl, { action: 'rental_retry_failed_images', sync_id: '...' })
    → Rental_Sync_Ajax_Controller::retry_failed_images()
        → Rental_Failed_Image_Repository::get_ids_by_sync(sync_id)
        → Rental_Retry_Processor::process(sync_id, image_ids)
            → Acquire session lock
            → DELETE FROM rental_image_relations WHERE rental_id IN (failed_ids)
            → rental_curl('files/images/stream', { ids: JSON(failed_ids) })
            → For each image:
                → Rental_Image_Downloader::download_and_attach()
                → Rental_Image_Relation_Attacher::attach_to_all()
            → Bulk INSERT into rental_image_relations
            → Commit galleries
            → Rental_Failed_Image_Repository::mark_resolved(succeeded_ids)
            → Release lock
    → JS receives { succeeded_ids, failed_ids, processed_count }
```

---

## 12. Session state transitions

```
         ┌──────────┐
         │ CREATED  │ (1) ← Scheduler creates session
         └────┬─────┘
              │ first chunk arrives
              ▼
         ┌──────────────┐
    ┌───►│ PROCESSING   │ (2) ← Chunks being processed
    │    └──┬───────┬───┘
    │       │       │
    │  completed  cancel/error
    │       │       │
    │       ▼       ▼
    │  ┌─────────┐ ┌──────────┐
    │  │COMPLETED│ │ CANCELED │ (5)
    │  │  (3)    │ └──────────┘
    │  └─────────┘
    │
    └── Resume (from PROCESSING with stale generation)
```

---

## 13. Lock acquisition flow

```
Rental_Chunk_Worker::process()
    → Rental_Sync_Session_Manager::acquire_lock(sync_id)
        → get_transient('rental_sync_lock_{sync_id}')
        → EXISTS? → return false (another worker is running)
        → NOT EXISTS? → set_transient(..., time(), 120) → return true

    → [process chunk]

    → Rental_Sync_Session_Manager::release_lock(sync_id)
        → delete_transient('rental_sync_lock_{sync_id}')

Per-image (inside uploader):
    → get_transient('rental_image_download_lock_{rental_id}')
    → EXISTS? → skip image (continue)
    → NOT EXISTS? → set_transient(..., time(), 300)
    → [download + process]
    → delete_transient('rental_image_download_lock_{rental_id}')
```
