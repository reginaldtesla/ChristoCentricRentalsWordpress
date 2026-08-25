# Background File Sync — Documentation

**Component:** Rentopian Sync — Background Image Sync Module  
**Audience:** Developers, technical maintainers  
**Last updated:** 2026-02-26  
**Version:** 2.14.2  
**Conventions:** This document follows a single-source structure so it can be split into separate files later (e.g. `architecture.md`, `workflows.md`) without losing context.

---

## Table of contents

1. [Introduction](#1-introduction)
2. [Prerequisites](#2-prerequisites)
3. [Architecture](#3-architecture)
4. [Database schema](#4-database-schema)
5. [Class reference](#5-class-reference)
6. [Workflows](#6-workflows)
7. [REST API endpoints](#7-rest-api-endpoints)
8. [Admin AJAX endpoints](#8-admin-ajax-endpoints)
9. [WP Options reference](#9-wp-options-reference)
10. [Error handling and retry logic](#10-error-handling-and-retry-logic)
11. [Backward compatibility](#11-backward-compatibility)
12. [Bug fixes applied during refactor](#12-bug-fixes-applied-during-refactor)
13. [Testing checklist](#13-testing-checklist)
14. [Files to delete](#14-files-to-delete)
15. [Integration steps](#15-integration-steps)
16. [Documentation maintenance](#16-documentation-maintenance)

---

## 1. Introduction

### 1.1 Purpose

The **Background File Sync** module manages the synchronisation of product images between the Rentopian Laravel back-end and a WooCommerce WordPress site. When a user triggers a file sync from the admin panel, the plugin:

1. Tells the Laravel server to start dispatching chunks via a REST API.
2. Each chunk callback downloads images, creates WP attachment posts, generates thumbnails, and assigns images to products / variants / categories / brands / attribute swatches.
3. Tracks progress, handles failures, supports cancellation, and allows resumption.

### 1.2 Sync modes

| Mode | Constant | Description |
|------|----------|-------------|
| **Initial sync** | `Rental_Sync_Status::MODE_SYNC` (1) | Downloads every image from scratch. Skips images already present in `rental_image_relations`. |
| **Resync** | `Rental_Sync_Status::MODE_RESYNC` (2) | Tries to reuse existing WP attachments; downloads only if missing. |
| **Retry** | N/A (ad-hoc) | Re-processes an explicit list of previously failed image IDs. |

### 1.3 Glossary

| Term | Meaning |
|------|---------|
| **Session** | One sync run, identified by a UUID `sync_id`. Stored in the `rental_sync_sessions` WP option. |
| **Chunk** | A batch of images (typically 5) processed in a single REST callback from Laravel. |
| **Generation** | An integer counter (`rental_sync_generation`). Incremented on "Cancel all" to invalidate every older session instantly. |
| **Lock** | A WP transient (`rental_sync_lock_{sync_id}`) preventing two workers from processing the same session concurrently. |
| **Image lock** | A per-image transient (`rental_image_download_lock_{rental_id}`) preventing duplicate downloads of the same image. |
| **Relations table** | `rental_image_relations` — maps WP attachment IDs to Rentopian image IDs. |



### 1.4 Admin Panel Sync Buttons Actions Explained

**Synchronize (Background Process)**: If no background sync was done before, we initialize with do a sync with pressing this button.
**Resynchronize (Background Process)**: If there were one or many sync before (and we have downloaded some/all images before) and want a re-sync due to webhooks failure or any other reason, this is the button to use.
Cancel Synchronization (Background Process): If for any reason (getting stuck on process and etc.), we needed to pause/cancel the sync process, this is the button to use.
**Cancel ALL Synchronization (Background Process)**: If we have triggered multiple syncs by mistake or for any reason we notice an old sync started working or if we have closed the browser and after hours when checking the system we realize we need to cancel current/old sync(s), this is the button to use. (If you have pressed "Cancel Synchronization (Background Process)", it is recommended to press it afterwards to make sure there are no stale jobs out there.)
**Resume Latest Synchronization (Background Process)**: We have lots of automated failure handling and self healing and retrying mechanisms for the running sync but if for any reason we got stuck in the process, we can first cancel the current sync (with Cancel Synchronization (Background Process) button), then press this button to resume the sync to recover the process and go on until it finishes.

---

## 2. Prerequisites

- WordPress 5.0+
- WooCommerce active
- Rentopian Sync plugin active
- PHP 7.4+ with GD extension (for fallback image subsizing)
- A valid `rental_api_key` set in plugin settings
- The Laravel back-end reachable from the WP server

---

## 3. Architecture

### 3.1 Module location

```
includes/file-sync/
├── bootstrap.php                    ← ENTRY POINT — loaded from rentopian-sync.php
├── class-sync-status.php            ← Constants (STATUS_*, MODE_*)
├── class-sync-log-repository.php    ← DB operations: rental_file_sync_log
├── class-failed-image-repository.php← DB operations: rental_failed_images
├── class-sync-session-manager.php   ← Session CRUD, generation counter, locks
├── class-image-performance-filter.php ← WP filters to reduce thumbnail overhead
├── class-image-subsizer.php         ← Thumbnail generation with GD fallback
├── class-image-downloader.php       ← download_url → wp_upload_bits → wp_insert_attachment
├── class-image-relation-attacher.php← Assign images to products/variants/categories/brands
├── class-image-integrity.php        ← Repair references to attachments that no longer exist
├── class-sync-uploader.php          ← Initial-sync chunk processor
├── class-resync-uploader.php        ← Resync chunk processor (reuse-first)
├── class-chunk-worker.php           ← Orchestrator: lock → delegate → log
├── class-retry-processor.php        ← Re-process failed image IDs
├── class-sync-scheduler.php         ← Start a new session, notify Laravel
├── class-sync-rest-controller.php   ← WP REST endpoints (Laravel calls these)
├── class-sync-ajax-controller.php   ← Admin AJAX endpoints (JS calls these)
└── compat-functions.php             ← Backward-compatible function aliases
```

### 3.2 Load order

```
rentopian-sync.php
  → require class-process-timer.php          (Rental_Timer)
  → require class-logger-with-timer.php      (Project_WP_Logger)
  → require file-sync/bootstrap.php
       → require class-sync-status.php
       → require class-sync-log-repository.php
       → require class-failed-image-repository.php
       → require class-sync-session-manager.php
       → require class-image-performance-filter.php
       → require class-image-subsizer.php
       → require class-image-downloader.php
       → require class-image-relation-attacher.php
       → require class-image-integrity.php
       → require class-sync-uploader.php
       → require class-resync-uploader.php
       → require class-chunk-worker.php
       → require class-retry-processor.php
       → require class-sync-scheduler.php
       → require class-sync-rest-controller.php
       → require class-sync-ajax-controller.php
       → require compat-functions.php
       → add_action('rest_api_init', ...) → register REST routes
       → new Rental_Sync_Ajax_Controller() → register AJAX hooks
  → require functions.php                    (general plugin functions)
  → ...
```

### 3.3 Dependency graph

```
Rental_Sync_REST_Controller
  └─ Rental_Chunk_Worker
       ├─ Rental_Sync_Uploader
       │    ├─ Rental_Image_Downloader
       │    │    ├─ Rental_Image_Performance_Filter
       │    │    └─ Rental_Image_Subsizer
       │    └─ Rental_Image_Relation_Attacher
       ├─ Rental_Resync_Uploader
       │    ├─ Rental_Image_Downloader
       │    └─ Rental_Image_Relation_Attacher
       ├─ Rental_Sync_Session_Manager
       ├─ Rental_Sync_Log_Repository
       └─ Rental_Failed_Image_Repository

Rental_Sync_Ajax_Controller
  ├─ Rental_Sync_Scheduler (start/resume)
  ├─ Rental_Retry_Processor (retry)
  ├─ Rental_Sync_Session_Manager (cancel/cancel-all)
  ├─ Rental_Sync_Log_Repository (details/paginate/delete)
  └─ Rental_Failed_Image_Repository (retry)
```

---

## 4. Database schema

### 4.1 `{prefix}_rental_file_sync_log`

Tracks every chunk processed for every sync session.

| Column | Type | Description |
|--------|------|-------------|
| `id` | bigint(20) AUTO_INCREMENT | Primary key |
| `sync_id` | varchar(255) | UUID of the sync session |
| `level` | varchar(50) | `info`, `warning`, `error` |
| `message` | text | Human-readable log line |
| `data` | longtext | JSON-encoded extra data |
| `last_index` | bigint(20) | Cursor position (last processed `rental_id`) |
| `processed_count` | int(11) | Running total of processed images |
| `total_count` | int(11) | Total images expected |
| `elapsed` | float | Seconds this chunk took |
| `status` | tinyint(4) | `Rental_Sync_Status::STATUS_*` at time of write |
| `mode` | tinyint(4) | 1 = sync, 2 = resync |
| `created_at` | timestamp | Row creation time |

### 4.2 `{prefix}_rental_failed_images`

Tracks images that failed to download/process.

| Column | Type | Description |
|--------|------|-------------|
| `id` | bigint(20) AUTO_INCREMENT | Primary key |
| `rental_id` | bigint(20) | The Rentopian image ID that failed |
| `sync_id` | varchar(255) NULL | Which sync session the failure occurred in |
| `error` | text | Error message |
| `attempts` | int(11) DEFAULT 1 | How many times this image has failed |
| `resolved` | tinyint(1) DEFAULT 0 | Whether it was resolved by a retry |
| `resolved_by` | varchar(50) NULL | Who/what resolved it (`system`, `admin`, etc.) |
| `created_at` | timestamp | First failure |
| `updated_at` | timestamp | Last failure or resolution |

### 4.3 `{prefix}_rental_image_relations`

Maps WP attachment IDs to Rentopian image IDs.

| Column | Type | Description |
|--------|------|-------------|
| `id` | bigint(20) | WP attachment post ID |
| `rental_id` | bigint(20) | Rentopian image ID |

---

## 5. Class reference

### 5.1 `Rental_Sync_Status`

Enum-like constants class. No methods.

| Constant | Value | Meaning |
|----------|-------|---------|
| `STATUS_CREATED` | 1 | Session created, not yet processing |
| `STATUS_PROCESSING` | 2 | Actively processing chunks |
| `STATUS_COMPLETED` | 3 | All images processed |
| `STATUS_FAILED` | 4 | Unrecoverable error |
| `STATUS_CANCELED` | 5 | Canceled by admin |
| `MODE_SYNC` | 1 | Initial full sync |
| `MODE_RESYNC` | 2 | Resync (reuse existing attachments) |

### 5.2 `Rental_Sync_Log_Repository`

All reads/writes to `rental_file_sync_log`.

| Method | Description |
|--------|-------------|
| `table()` | Returns the prefixed table name |
| `write($sync_id, $level, $message, $data, ...)` | Insert log entry + mirror to `Project_WP_Logger` |
| `get_grouped($limit, $offset)` | Distinct `sync_id` groups with aggregated stats |
| `get_grouped_count()` | Count of distinct `sync_id` values |
| `get_grouped_page($page, $per_page)` | Paginated grouped view |
| `get_entries($sync_id)` | All log entries for one sync |
| `get_entries_paginated($sync_id, $page, $per_page)` | Paginated entries |
| `get_last_elapsed($sync_id)` | Elapsed time of last chunk (for adaptive sizing) |
| `had_recent_error($sync_id, $seconds)` | Whether an error occurred in the last N seconds |
| `get_last_completed_sync_session()` | Most recent session with `STATUS_COMPLETED` |
| `delete_by_sync($sync_id)` | Delete all log entries for a session |

### 5.3 `Rental_Failed_Image_Repository`

All reads/writes to `rental_failed_images`.

| Method | Description |
|--------|-------------|
| `table()` | Returns the prefixed table name |
| `mark($rental_id, $sync_id, $error)` | Insert or increment `attempts` |
| `get_ids_by_sync($sync_id)` | Array of failed `rental_id` values |
| `mark_resolved($rental_ids, $resolved_by)` | Set `resolved = 1` for given IDs |
| `clear_for_sync($sync_id)` | Delete all rows for a sync |
| `clear_all()` | Truncate the table |
| `get_ids_paginated($sync_id, $page, $per_page)` | Paginated failed IDs |
| `get_rows($limit)` | Raw rows (for admin display) |

### 5.4 `Rental_Sync_Session_Manager`

Manages the `rental_sync_sessions` WP option and the generation counter.

| Method | Description |
|--------|-------------|
| `get($sync_id)` | Read one session's data |
| `update($sync_id, $fields)` | Merge fields into a session |
| `remove($sync_id)` | Delete a session |
| `get_all()` | All sessions |
| `save_all($sessions)` | Overwrite the entire option |
| `cancel_all_sessions()` | Set all to `STATUS_CANCELED` |
| `cancel_previous_sessions($current_id)` | Cancel all except the given ID |
| `generation_current()` | Read `rental_sync_generation` |
| `generation_increment()` | Atomically increment and return new value |
| `is_globally_disabled()` | Check `rental_sync_all_canceled` option |
| `guard_or_null($sync_id)` | Return null if globally disabled or generation mismatch |
| `acquire_lock($sync_id)` | Set a 120s transient lock |
| `release_lock($sync_id)` | Delete the lock transient |
| `compute_resume_index($sync_id)` | Best guess at where to resume (option → log → relations → 0) |
| `prepare_for_resume($sync_id)` | Refresh generation, mint token, clear locks |
| `pick_latest_broken_sync_id()` | Find most recent non-completed, non-canceled session |

### 5.5 `Rental_Image_Performance_Filter`

Hooks into WP's image generation pipeline to reduce overhead during sync.

| Method | Description |
|--------|-------------|
| `enable()` | Add WP filters (`big_image_size_threshold`, `intermediate_image_sizes_advanced`, `image_editor_output_format`) |
| `disable()` | Remove those filters |
| `cap_big_image($pixels)` | Returns 2560 (the threshold cap) |
| `trim_sizes($sizes, $metadata)` | Removes `2048x2048`, `1536x1536`, `large` from sizes array |
| `output_format($formats)` | Routes PNG → JPEG for smaller file sizes |

### 5.6 `Rental_Image_Subsizer`

Generates thumbnails with a multi-level fallback chain.

| Method | Description |
|--------|-------------|
| `update_metadata_with_fallback($attach_id, $file, $use_custom)` | Main entry: tries `make_subsizes()`, falls back to `wp_generate_attachment_metadata()`, minimal meta |
| `make_subsizes($file, $attachment_id)` | Core: scale → `wp_create_image_subsizes()` → manual GD fallback |
| `resize_multiple_gd($filename, $sizes, $image_meta)` | Manual GD resizer for JPEG/PNG/GIF |
| `calc_crop_box($origW, $origH, $tW, $tH, $crop)` | Compute crop coordinates without distortion |

### 5.7 `Rental_Image_Downloader`

Downloads a remote image and creates a WP attachment, and owns the single
authority on whether an attachment id is still worth anything.

| Method | Description |
|--------|-------------|
| `download_and_attach($url, $rental_id, $sync_id, $image_meta)` | Full pipeline: download → upload → insert attachment → generate subsizes. Returns attachment ID or 0. |
| `attachment_is_usable($attach_id)` | Post exists, is an `attachment`, and its file is on disk. Memoised per request. |
| `filter_usable($attach_ids)` | Keep only the ids that pass, de-duplicated, order preserved. |
| `forget_relation($rental_id, $attach_id)` | Drop a relation row whose attachment is gone, so the next pass re-downloads. |
| `resolve_existing_attachment($rental_id, $image_url)` | Relations table → filename match. Prunes stale relation rows as it goes. Returns attachment ID or 0. |
| `find_attachment_by_filename($filename)` | Search `_wp_attached_file` meta or `guid`. Returns attachment ID or 0. |

**Why `attachment_is_usable()` exists.** A relation row, a `_thumbnail_id` or a
gallery entry is a *claim*, not proof. When the attachment behind it is deleted —
a legacy truncating sync, a media purge, a half-finished migration — the claim is
indistinguishable from a good one until it is checked. Trusting it means the
product renders an empty image slot while `has_post_thumbnail()` still answers
true, so the corruption is invisible in the admin and survives every later sync.
Every read of a stored attachment id goes through this check.

### 5.8 `Rental_Image_Relation_Attacher`

Assigns a WP attachment to all related entities.

| Method | Description |
|--------|-------------|
| `attach_to_all($image, $attach_id, &$variant_gallery, &$set_gallery)` | Main orchestrator — calls all sub-attachers |
| `attach_to_products($img, $attach_id)` | Set `_thumbnail_id` or gallery for products |
| `merge_gallery_meta($post_id, $meta_key, $add, $exclude)` | Rewrite a gallery, dropping ids that no longer resolve. Shared with the Sets collector. |
| `commit_variant_galleries($variant_gallery)` | Bulk update `zoo-cw-variation-gallery` postmeta |
| `commit_set_galleries($set_gallery)` | Deprecated pass-through to `Rental_Sets_Gallery_Collector::commit()` |

The thumbnail slot counts as **free** when it is empty, when it still holds the
rental placeholder id, **or when it names an attachment that no longer exists**.
That third case is what repairs a product showing an empty image slot; without it
the new image was filed into the gallery and the dead thumbnail kept its place.

Galleries are rewritten rather than appended to. They used to be append-only, so
one deleted attachment stayed in the list forever and every later run appended
around it. `merge_gallery_meta()` filters the whole list on each touch, keeps the
featured image out of it, and deletes the meta row when nothing usable is left —
products, variants and sets all go through it.

### 5.9 `Rental_Image_Integrity`

Repairs image references that already point at attachments which no longer exist.
Fixing the code that creates dangling references does not heal the ones already
stored.

| Method | Description |
|--------|-------------|
| `repair()` | Clear unrenderable `_thumbnail_id`s, prune dead gallery ids, delete orphan meta rows and relation rows, clear dead term images. Returns counters. |
| `promote_gallery_images()` | Give a featured image to anything that has none but does have a usable gallery image. |
| `posts_without_images($limit)` | Products with nothing left to show. Variations are only listed when the parent has no image either. |

**Ordering is the whole design.** `repair()` runs *between* the data and file
phases (from `Rental_Data_Sync_Scheduler::maybe_chain_file_sync()`): it clears the
wreckage and prunes stale relations, and the file phase that immediately follows
re-downloads whatever that freed up. `promote_gallery_images()` runs *after* the
file phase, from the reporter, when every image that could be fetched has been.

This is why `repair()` is not exposed as a standalone button: run on its own it
would clear dead references and leave those products blank until the next sync.
It is only safe as the first half of a pair.

`repair()` is idempotent — a second run reports all zeros.

### 5.10 `Rental_Sync_Uploader`

Initial-sync chunk processor.

| Method | Description |
|--------|-------------|
| `process($start, $limit, $sync_id)` | Fetch images from API, download, attach. Checks for cancel/generation each iteration. |

Resolution order per image, each step falling through to the next:

1. **Relation row** from `rental_image_relations` — used only if
   `attachment_is_usable()` agrees; otherwise the row is pruned.
2. **`resolve_existing_attachment()`** — relations again, then filename match
   against `_wp_attached_file` and `guid`.
3. **Download.** When there is no URL to download from, the dangling references
   are cleared and the image is left for the next pass.

Step 1 previously returned the relation's id unconditionally. That single missing
check was the root cause of products rendering empty image slots: the attachment
was gone, no download was attempted, and the attacher then filed the (equally
stale) id into the gallery instead of replacing the thumbnail.

### 5.11 `Rental_Resync_Uploader`

Resync chunk processor (reuse-first strategy).

| Method | Description |
|--------|-------------|
| `process($start, $limit, $sync_id)` | Like Sync Uploader but calls `resolve_existing_attachment()` before downloading. |

### 5.12 `Rental_Chunk_Worker`

Orchestrates a single chunk of processing.

| Method | Description |
|--------|-------------|
| `adapt_chunk_size($sync_id, $incoming_limit)` | Dynamic chunk sizing based on elapsed time and recent errors |
| `process($start, $limit, $sync_id, $is_resync)` | Validates session → acquires lock → delegates to uploader → logs → releases lock |

### 5.13 `Rental_Retry_Processor`

Reprocesses failed images.

| Method | Description |
|--------|-------------|
| `process($sync_id, $image_ids)` | Deletes old relation rows, fetches metadata from API, re-downloads, re-attaches. Marks succeeded as resolved. |

### 5.14 `Rental_Sync_Scheduler`

Starts or schedules a new sync session.

| Method | Description |
|--------|-------------|
| `schedule($mode)` | Creates session, cancels previous, notifies Laravel via `rental_curl('sync/start', ...)` |

### 5.15 `Rental_Sync_REST_Controller`

REST API endpoints called by the Laravel server.

| Route | Method | Handler |
|-------|--------|---------|
| `/rentopian-sync/v1/process-chunk` | POST | `process_chunk_handler` |
| `/rentopian-sync/v1/status` | GET | `status_handler` |
| `/rentopian-sync/v1/cancel` | POST | `cancel_handler` |
| `/rentopian-sync/v1/retry-images` | POST | `retry_images_handler` |

### 5.16 `Rental_Sync_Ajax_Controller`

Admin AJAX endpoints called by `rental-admin-script.js`.

| Action | Method |
|--------|--------|
| `rental_sync_files_bg` | `sync_files_bg()` — Start initial sync |
| `rental_resync_files_bg` | `resync_files_bg()` — Start resync |
| `rental_cancel_sync_bg` | `cancel_sync_bg()` — Cancel current sync |
| `rental_cancel_all_sync_bg` | `cancel_all_sync_bg()` — Cancel ALL syncs globally |
| `rental_retry_failed_images` | `retry_failed_images()` — Retry failed images |
| `rental_resume_sync_bg` | `resume_sync_bg()` — Resume a stuck/failed sync |
| `rental_get_file_sync_details` | `get_file_sync_details()` — Paginated log entries |
| `rental_paginate_file_sync_log` | `paginate_file_sync_log()` — Paginated grouped log |
| `rental_delete_file_sync` | `delete_file_sync()` — Delete log + session |

---

## 6. Workflows

### 6.1 Initial sync — complete flow

```
Admin clicks "Sync Files" button in WP admin
  → JS fires AJAX: rental_sync_files_bg
  → Rental_Sync_Ajax_Controller::sync_files_bg()
     → Rental_Timer::start_persistent()
     → Rental_Sync_Scheduler::schedule( MODE_SYNC )
        1. Generate UUID sync_id + random wp_token
        2. Fetch image count from Laravel API
        3. Cancel all previous sessions
        4. Create new session in rental_sync_sessions option
        5. Init per-sync options (last_id=0, processed=0, completed=false)
        6. Notify Laravel: rental_curl('sync/start', { wp_endpoint, wp_token, sync_id, ... })
  → JS receives success + sync_id

Laravel server starts dispatching chunks:
  → POST /rentopian-sync/v1/process-chunk
     { sync_id, start_index, chunk_size, wp_token, mode }
  → Rental_Sync_REST_Controller::process_chunk_handler()
     1. Validate wp_token against session
     2. Check canceled status
     3. Check generation match
     4. Delegate to Rental_Chunk_Worker::process()
        a. Acquire session lock (transient, 120s TTL)
        b. Call Rental_Sync_Uploader::process(start, limit, sync_id)
           - rental_curl('files/images/stream', ...) → get image data
           - For each image:
             • Check cancel/generation
             • Acquire per-image lock (transient, 300s TTL)
             • If already_uploaded → clear stale thumbnails → skip
             • If exists in relations table → reuse attach_id
             • Else → Rental_Image_Downloader::download_and_attach()
                 ○ download_url() → tmp file
                 ○ wp_upload_bits() → uploads dir
                 ○ wp_insert_attachment() → WP post
                 ○ Rental_Image_Subsizer::update_metadata_with_fallback()
             • Rental_Image_Relation_Attacher::attach_to_all()
             • Release per-image lock
           - Bulk INSERT into rental_image_relations
           - Commit variant + set galleries
        c. Read results from options
        d. Update session
        e. Write log entry
        f. Release session lock
        g. If completed → update rental_synchronize_status, stop timer
     5. Return { last_index, failed_ids, failed_count, completed }
  → Laravel uses last_index to compute next chunk's start_index
  → Repeat until completed=true
```

### 6.2 Resync flow

Same as above except:
- `schedule(MODE_RESYNC)` is called
- `Rental_Resync_Uploader::process()` is used instead
- For each image, it first calls `Rental_Image_Downloader::resolve_existing_attachment()` to find existing WP attachments before downloading

### 6.3 Cancel single sync

```
Admin clicks "Cancel"
  → AJAX: rental_cancel_sync_bg
  → Rental_Sync_Ajax_Controller::cancel_sync_bg()
     1. Read rental_current_sync_id
     2. Update session status → STATUS_CANCELED
     3. Release session lock
     4. Notify Laravel: rental_curl('sync/cancel', { sync_id })
     5. Write cancellation log entry
  → Next time Laravel sends a chunk request, REST controller returns STATUS_CANCELED
  → Laravel stops dispatching
```

### 6.4 Cancel ALL syncs

```
Admin clicks "Cancel All"
  → AJAX: rental_cancel_all_sync_bg
  → Rental_Sync_Ajax_Controller::cancel_all_sync_bg()
     1. Increment generation counter (rental_sync_generation++)
     2. Set rental_sync_all_canceled = 1
     3. Set every session → STATUS_CANCELED
     4. Purge all transient locks (rental_image_download_lock_*, rental_sync_lock_*)
     5. Clear rental_current_sync_id + wp_token
     6. Notify Laravel: rental_curl('sync/cancel-all', {})
        - Fallback: per-session cancel calls if cancel-all fails
```

### 6.5 Resume sync

```
Admin clicks "Resume"
  → AJAX: rental_resume_sync_bg
  → Rental_Sync_Ajax_Controller::resume_sync_bg()
     1. If no sync_id provided → pick_latest_broken_sync_id()
     2. prepare_for_resume(sync_id):
        a. Refresh generation on session
        b. Mint new wp_token
        c. Clear old locks
        d. Set status → STATUS_PROCESSING
     3. compute_resume_index(sync_id):
        Priority: per-sync option → last log entry → max(rental_id) in relations → 0
     4. Notify Laravel: rental_curl('sync/start', { start_index, chunk_size=1 })
  → Laravel resumes dispatching from the computed index
```

### 6.6 Retry failed images

```
Admin clicks "Retry Failed"
  → AJAX: rental_retry_failed_images
  → Rental_Sync_Ajax_Controller::retry_failed_images()
     1. Get failed IDs from Rental_Failed_Image_Repository
     2. Call Rental_Retry_Processor::process(sync_id, image_ids)
        a. Acquire session lock
        b. Delete old relation rows for failed IDs
        c. Fetch image metadata from API
        d. For each image: download → attach → update relations
        e. Mark succeeded as resolved
        f. Release lock
     3. Return succeeded/failed counts
```

### 6.7 Image download + attachment pipeline (detail)

```
Rental_Image_Downloader::download_and_attach($url, $rental_id, $sync_id)
  │
  ├─ require_once wp-admin/includes/file.php
  ├─ download_url($url) → $tmp_file
  │   └─ FAIL? → mark_failed() → return 0
  │
  ├─ add_filter('wp_check_filetype_and_ext', 'rental_bypass_mime_check')
  ├─ wp_upload_bits(basename($url), null, file_get_contents($tmp_file))
  │   └─ FAIL? → mark_failed() → cleanup_tmp() → return 0
  │
  ├─ wp_insert_attachment($attachment, $upload['file'], 0)
  │   └─ FAIL? → mark_failed() → cleanup_tmp() → return 0
  │
  ├─ Rental_Image_Performance_Filter::enable()
  ├─ Rental_Image_Subsizer::update_metadata_with_fallback()
  │   ├─ Try make_subsizes() (scale + wp_create_image_subsizes + GD fallback)
  │   ├─ Fallback: wp_generate_attachment_metadata()
  │   └─ Last resort: register minimal meta (width, height, file, filesize)
  ├─ Rental_Image_Performance_Filter::disable()
  │
  ├─ cleanup_tmp($tmp_file)
  └─ return $attach_id
```

---

## 7. REST API endpoints

All endpoints are under namespace `rentopian-sync/v1`.

### 7.1 `POST /process-chunk`

**Called by:** Laravel server  
**Authentication:** `wp_token` matched against session

**Request body:**

```json
{
  "sync_id": "uuid",
  "start_index": 100,
  "chunk_size": 5,
  "wp_token": "random-32-char-string",
  "mode": 1
}
```

**Response (200):**

```json
{
  "success": true,
  "last_index": 105,
  "failed_ids": [102],
  "failed_count": 1,
  "completed": false
}
```

**Error responses:**
- `400` — Missing parameters
- `403` — Invalid token
- `409` — Generation mismatch (stale session)
- `500` — Exception during processing

### 7.2 `GET /status`

**Parameters:** `sync_id`, `wp_token`

**Response:**

```json
{
  "success": true,
  "last_index": 250,
  "failed_ids": [],
  "status": 2
}
```

### 7.3 `POST /cancel`

**Body:** `sync_id`, `wp_token`

### 7.4 `POST /retry-images`

**Body:** `sync_id`, `wp_token`, `image_ids` (optional — defaults to all failed)

---

## 8. Admin AJAX endpoints

All use `wp_ajax_{action}` hooks. Require logged-in admin.

| Action | HTTP | Key POST/GET params | Response shape |
|--------|------|---------------------|----------------|
| `rental_sync_files_bg` | POST | — | `{ success, sync_id, response_from_rental }` |
| `rental_resync_files_bg` | POST | — | `{ success, sync_id, message }` |
| `rental_cancel_sync_bg` | POST | — | `{ success, sync_id, laravel_cancel_ok }` |
| `rental_cancel_all_sync_bg` | POST | — | `{ success, generation, sessions_updated, locks_cleared }` |
| `rental_retry_failed_images` | POST | `sync_id`, `limit` | `{ success, data: { succeeded_ids, failed_ids, processed_count } }` |
| `rental_resume_sync_bg` | POST | `sync_id` (optional) | `{ success, response_from_rental }` |
| `rental_get_file_sync_details` | GET | `sync_id`, `page`, `per_page` | `{ success, entries, page, total, total_pages }` |
| `rental_paginate_file_sync_log` | GET | `page`, `per_page` | `{ success, groups, page, total, total_pages }` |
| `rental_delete_file_sync` | POST | `sync_id` | `{ success, deleted }` |

---

## 9. WP Options reference

### 9.1 Global options

| Option | Type | Description |
|--------|------|-------------|
| `rental_api_key` | string | API key for the Rentopian Laravel server |
| `rental_current_sync_id` | string | UUID of the currently active sync session |
| `rental_current_wp_token` | string | Token for the current session (sent to Laravel) |
| `rental_sync_generation` | int | Generation counter — incremented on "Cancel All" |
| `rental_sync_all_canceled` | int | 1 = globally disabled (no syncs will run) |
| `rental_sync_canceled_reason` | string | Human-readable reason for global cancel |
| `rental_sync_sessions` | array | `{ sync_id => { wp_token, last_index, failed_ids, status, ... } }` |
| `rental_products_img_count` | int | Total number of images (fetched from API) |
| `rental_synchronize_status` | int | 1 = sync completed (used by UI) |
| `rental_default_chunk_size` | int | Default chunk size (default: 8) |

### 9.2 Per-sync options

| Option | Type | Description |
|--------|------|-------------|
| `rental_products_img_last_id_{sync_id}` | int | Cursor — last processed rental_id |
| `rental_products_img_processed_{sync_id}` | int | Running count of processed images |
| `rental_image_upload_completed_{sync_id}` | bool | True when API returns no more images |
| `rental_effective_chunk_size_{sync_id}` | int | Adaptively computed chunk size |

### 9.3 Transients (locks)

| Transient | TTL | Description |
|-----------|-----|-------------|
| `rental_sync_lock_{sync_id}` | 120s | Session-level lock — prevents concurrent workers |
| `rental_image_download_lock_{rental_id}` | 300s | Per-image lock — prevents duplicate downloads |

---

## 10. Error handling and retry logic

### 10.1 Per-image failure

When any step fails (`download_url`, `wp_upload_bits`, `wp_insert_attachment`):
1. `Rental_Failed_Image_Repository::mark()` is called → inserts or increments `attempts` in `rental_failed_images`.
2. A log entry is written to `rental_file_sync_log`.
3. `ErrorHandler::registerErrorInLog()` is called (if available).
4. Processing continues with the next image (no chunk abort).

### 10.2 Retry

Failed images can be retried via:
- Admin UI → "Retry Failed" button → `rental_retry_failed_images` AJAX
- Laravel API → `POST /rentopian-sync/v1/retry-images`

The `Rental_Retry_Processor` fetches fresh metadata from the API, deletes old relation rows, and re-runs the full download pipeline. Successfully retried images are marked `resolved = 1`.

### 10.3 Lock management

**Session locks** prevent two chunk callbacks from running concurrently for the same session. If a lock can't be acquired, the worker returns `{ completed: false }` and Laravel will retry.

**Image locks** prevent the same image from being downloaded twice if two chunks overlap (rare, but possible under high concurrency).

All locks are WP transients with TTLs. The "Cancel All" action purges all lock transients.

### 10.4 Generation counter

The generation counter (`rental_sync_generation`) is the "kill switch". When incremented, every session whose `generation` doesn't match is treated as stale. The REST controller returns a `409` status, and the chunk worker silently exits.

---

## 16. Documentation maintenance

- When adding a **new sync mode** (e.g. selective sync), update section 1.2, add a new workflow in section 6, and update the REST/AJAX endpoint docs.
- When adding **new database columns**, update section 4.
- When adding **new classes**, update section 5 and the dependency graph in 3.4.
- When changing **Laravel API contracts**, update section 7 and the workflow diagrams in section 6.
