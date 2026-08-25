# Background Data Sync — Workflows

Step-by-step flows for every operation in the background catalog data sync.
Companion to [README.md](README.md).

---

## 1. System initialization (load order)

```
rentopian-sync.php
  └── require_once includes/data-sync/bootstrap.php
        ├── require class-data-sync-logger.php          (logger first — everything uses it)
        ├── require class-data-sync-status.php          (constants + cursor math)
        ├── require class-data-sync-session.php
        ├── require class-data-sync-run-repository.php
        ├── require class-payload-composer.php
        ├── require class-record-writer.php
        ├── require class-data-sync-phases.php
        ├── require class-data-sync-chunk-worker.php
        ├── require class-data-sync-scheduler.php
        ├── require class-data-sync-rest-controller.php
        ├── require class-data-sync-reporter.php
        ├── require class-data-sync-ajax-controller.php
        │
        ├── add_action('rest_api_init',  REST_Controller::register_routes)
        ├── add_action('admin_init',     Run_Repository::install)
        ├── add_action('admin_init',     Watchdog::tick)              ← fail abandoned runs
        ├── add_action('rental_data_sync_chain_files', Scheduler::maybe_chain_file_sync)
        ├── add_action('init',           Scheduler::maybe_chain_file_sync)   ← cron fallback
        ├── add_action('rental_file_sync_completed', Reporter::on_file_sync_completed)
        └── (new Rental_Data_Sync_Ajax_Controller)->register_hooks()
```

---

## 2. Start a run (admin)

```
Admin clicks the primary button
  │
  ├── JS reads #rental_api_key
  ├── confirm() dialog
  │
  └── POST admin-ajax.php  action=rental_data_sync_start
        │  params: api_key
        │
        ├── Ajax_Controller::start()
        │     ├── guard: current_user_can('manage_options') + nonce   → 403
        │     ├── api_key fallback to option rental_api_key
        │     ├── Watchdog::enforce( current )   ← close a run the driver abandoned
        │     └── still running? (CREATED|PROCESSING)                 → 409
        │
        └── Scheduler::schedule( api_key )
              │
              ├── Run_Repository::install()          (create table if missing)
              │
              ├── Watchdog::preflight( api_key )     ← refuse a start that cannot work
              │     ├── api key present / WooCommerce active / run table present
              │     ├── probe the callback route (expects 403 Invalid token)
              │     └── callback host resolves publicly?
              │     └── any blocker → 400 { message, reason, hints[] }   STOP
              │
              ├── clear global disable + bump generation (if previously killed)
              ├── sync_id = wp_generate_uuid4(), wp_token = random 32
              ├── Logger::bind_run( sync_id )        ← every line lands in this run's file
              ├── update options: rental_api_key, rental_data_sync_current_id,
              │                   rental_synchronize_status = 2, rental_sync_time
              ├── Session::cancel_previous_sessions( sync_id )
              ├── Session::update( sync_id, { status: CREATED, callback_url, … } )
              ├── Session::store_cursor( sync_id, 0 )
              ├── Run_Repository::start( sync_id )   ← started_at, status = CREATED
              ├── Session::staging_dir( sync_id )     → fail_start() if not writable
              │
              └── rental_curl('sync/start', api_key, [ … ], timeout = 30)
                    │
                    ├── throws            → fail_start( 'remote_rejected' )
                    ├── not queued        → fail_start( 'remote_not_queued' )
                    │     (empty body, success:false, or an error field)
                    │
                    └── Laravel: create SyncSession → dispatch ProcessChunkJob

fail_start( sync_id, message, reason, hints )
  ├── Session::update({ status: FAILED, last_error })
  ├── Run_Repository::finish( FAILED, message )      ← finished_at + duration recorded
  ├── Logger::write( reason )
  ├── Reporter::send_failure_report()
  └── 400 { success: false, message, reason, hints[] }   ← the panel names the cause
```

On exception the session is marked FAILED and a failure report is sent.

---

## 3. Process one chunk (called by Laravel)

```
Laravel ProcessChunkJob
  └── POST /wp-json/rentopian-sync/v1/data-sync/process-chunk
        { sync_id, start_index, chunk_size, mode, wp_token }
        │
        └── REST_Controller::process_chunk_handler()
              ├── required params present?                    → 400
              ├── Session::get(sync_id) and wp_token match?   → 403
              ├── status === CANCELED?                        → 200 { canceled }
              ├── generation matches current?                 → 409 (stale session)
              │
              └── Chunk_Worker::process( start_index, sync_id )
                    │
                    ├── set_time_limit(300)
                    ├── stored_cursor = Session::get_stored_cursor()
                    ├── cursor = max( start_index, stored_cursor )   ← retry fast-forward
                    ├── Session::acquire_lock()                      → deferred if held
                    │
                    ├── phase  = Status::cursor_phase( cursor )
                    ├── offset = Status::cursor_offset( cursor )
                    │
                    ├── Phases::run_step( phase, offset )
                    │     └── returns { offset, done, processed }
                    │
                    ├── if step.done:
                    │     ├── Session::mark_phase_done( phase )
                    │     └── next_cursor = Status::cursor( phase + 1, 0 )
                    │   else:
                    │     └── next_cursor = Status::cursor( phase, step.offset )
                    │
                    ├── Session::store_cursor( next_cursor )
                    ├── Session::add_stats( writer counters )
                    ├── Session::update({ status: PROCESSING, phase, phase_steps,
                    │                     processed_count })
                    ├── Logger::write( step summary )        ← run log file + wc-logs
                    ├── Run_Repository::touch({ status, phase, processed_count })
                    ├── Session::release_lock()
                    │
                    └── completed = ( phase === PHASE_FINALIZE && step.done )
                          └── if completed:
                                ├── Session::update({ status: COMPLETED })
                                └── Run_Repository::finish( COMPLETED, outcome summary )
        │
        └── 200 { success, last_index: next_cursor, completed,
                  failed_ids: [], processed_count, total_count: 0 }
              │
              └── Laravel: last_index advanced? → dispatch next job
                           completed? → mark session COMPLETED, stop
```

### Step error path

```
Phases::run_step() throws
  └── Chunk_Worker::handle_step_error()
        ├── Logger::write( error + trace, phase )
        ├── error_count++
        ├── error_count >= MAX_CONSECUTIVE_ERRORS (5), or a 5xx from variants?
        │     ├── Session::update({ status: FAILED, last_error })
        │     ├── Run_Repository::finish( FAILED, reason )
        │     ├── Session::cleanup_staging()
        │     └── Reporter::send_failure_report()
        └── respond with the CURRENT cursor so Laravel can retry
```

---

## 4. Phase 0 — config

```
Phases::phase_config()
  ├── rental_clear_cache()
  ├── rental_sync_company_settings( api_key )        → returns settings
  ├── set_default_settings()
  ├── update_option rental_filter_unavailable_products = 0
  ├── rental_sync_divisions( api_key )
  ├── rental_set_filter_duplicate_products_options( divisions, location_based_filter )
  ├── api_seo_no_index_filter → rental_do_not_index_hidden_duplicate_products_for_seo
  ├── rental_sync_main_division_address( divisions )
  ├── update_option rental_pickup_delivery = 'company_delivery_return'
  ├── rental_curl('referral_sources')  → option
  ├── rental_curl('event_types')       → option
  ├── rental_curl('payment_tips')      → option (warn if unreachable)
  ├── rental_curl('shipping/settings') → option (unless rental_do_not_use_rentopian_shipping)
  └── rental_curl('settings/plugin_path/update', [plugin_path])

  return { offset: 1, done: true }
```

All of these are the **existing** legacy helpers from `functions.php`; the phase only
sequences them.

---

## 5. Phase 1 — taxonomies (3 steps)

```
offset 0 — attributes
  ├── rental_curl('products/attributes')          → foreach: Writer::upsert_attribute()
  ├── rental_curl('products/attributes/values')   → foreach: Writer::upsert_attribute_value()
  ├── maps['attributes'][id]        = row
  ├── maps['attribute_values'][id]  = row
  └── save_maps() → staging/maps.json          { offset: 1, done: false }

offset 1 — categories (parents before children)
  ├── rental_curl('products/categories')
  ├── sort_parents_first()                      ← bounded passes; cycles appended last
  ├── foreach: Writer::upsert_category()
  ├── maps['categories_by_product'][pid][cid] = title
  ├── Session::add_seen_ids('categories', ids)
  └── save_maps()                                { offset: 2, done: false }

offset 2 — tags, brands, sets tags
  ├── rental_curl('products/tags')   → maps['tags_by_product'][pid][tid] = title
  ├── rental_curl('products/brands') → foreach: Writer::upsert_brand()
  │                                     └── empty title → skipped (cannot become a WP term)
  ├── Session::add_seen_ids('brands', ids)
  ├── maps['sets_tags'] = rental_curl('inventories/sets/tags')
  └── save_maps()                                { offset: 3, done: true }
```

The maps are persisted to `staging/maps.json` because each callback is a fresh PHP request.

---

## 6. Phase 2 — variants staging (no DB writes)

```
Phases::phase_variants( offset )
  ├── limit = page_size()                        (option, default 100, clamp 10–300)
  ├── rows = rental_curl('products/variants', { start: offset, limit })
  │
  ├── bucket rows by product_id
  ├── foreach bucket: append_jsonl( "variants-<product_id>.jsonl", rows )
  ├── Session::add_seen_ids('variants', "<variant_id>:<division_id>" …)
  │
  └── return { offset: offset + count,
               done:   count < limit }
```

Nothing is written to the database in this phase — it only stages rows so the products
phase can build payloads without an N+1 against the API.

---

## 7. Phase 3 — products (the main write phase)

```
Phases::phase_products( offset )
  ├── maps = load_maps()
  ├── rows = rental_curl('products', { start: offset, limit: page_size() })
  │
  └── foreach product:
        ├── bucket = read_jsonl( "variants-<product_id>.jsonl" )
        │
        ├── Writer::upsert_product( product, bucket, maps )
        │     │
        │     ├── Composer::compose_product_payloads()
        │     │     └── one payload per DIVISION present in the bucket
        │     │         (divisions JSON string, variants for that division,
        │     │          all_variants across divisions, images: [])
        │     │
        │     └── foreach division payload:
        │           ├── relation lookup (rental_id + division) in rental_product_relations
        │           ├── relation → missing/trashed post?  → treat as absent
        │           │
        │           ├── exists → rentopian_product_update( payload )
        │           │             ├── ok    → count updated
        │           │             └── fail  → rentopian_product_create() (heal), count created
        │           │           then upsert_division_variants()
        │           │
        │           └── absent → rentopian_product_create( payload )
        │                         └── variants are created inline by the handler
        │
        ├── Session::add_seen_ids('products', "<product_id>:<division_id>" …)
        ├── append products-data.jsonl entry           (for the sets pass)
        └── delete_staging_file( "variants-<product_id>.jsonl" )

  return { offset: offset + count, done: count < limit }
```

### 7.1 Per-variant upsert

```
upsert_division_variants( product, bucket, maps, division_id )
  └── foreach row in bucket where row.division_id === division_id:
        ├── Composer::compose_variant_payload()
        ├── relation lookup in rental_variant_relations (rental_id + division)
        ├── relation → missing/trashed post? → treat as absent
        │
        ├── exists → rentopian_variant_update()
        │              ├── ok   → count updated
        │              └── fail → rentopian_variant_create() (heal), count created
        └── absent → rentopian_variant_create()  → count created
```

### 7.2 Handler invocation contract

```
Writer::call_handler( fn, payload, context )
  ├── ob_start()                          ← handlers echo their webhook response
  ├── fn( payload )                       ← payload = wp_slash(wp_json_encode(…))
  ├── echoed = ob_get_clean()
  ├── http_response_code() >= 400 ? → ok = false, reset code to 200
  └── log echoed output only when the call failed
```

`$_POST`-based handlers (brand, attribute, attribute value) additionally get a scoped
`$_POST` shim that is restored in a `finally` block.

---

## 8. Phase 4 — sets (2 steps)

```
offset 0 — fetch and stage
  ├── sets = rental_curl('inventories/sets')
  └── write_staging_json('sets.json', sets)        { offset: 1, done: false }

offset 1 — import
  ├── sets = read_staging_json('sets.json')
  ├── import_sets( sets )
  │     ├── snapshot existing set rental_ids            (for created/updated stats)
  │     ├── DELETE old set posts + meta + term rels + lookup rows
  │     ├── DELETE FROM rental_set_relations
  │     ├── build_sets_pass_maps()                      (products_data, variant_ids
  │     │                                                from products-data.jsonl + relations)
  │     ├── rental_run_sets_sync_pass([...])            ← sets module, unchanged
  │     ├── rental_insert() the returned posts/meta/lookup/relations/term rows
  │     ├── rebuild_sets_tags( set_ids )
  │     ├── rental_set_sets_up_sells_cross_sells(...)
  │     ├── store coupon exclusions for phase 5
  │     └── Session::add_stats('sets', …) + add_seen_ids('sets', …)
  │
  ├── rental_empty_set_options() + rental_add_set_options()
  └── return { offset: 2, done: true }
```

Sets are delete-and-rebuild (matching legacy semantics) because set membership is
positional; set counts are small enough to fit one step.

---

## 9. Phase 5 — extras

```
Phases::phase_extras()
  ├── excludes = coupon exclusions produced by the sets pass
  ├── rental_empty_coupons()            + rental_add_coupons( base_id, excludes )
  ├── rental_empty_price_multipliers()  + rental_add_price_multipliers()
  ├── rental_empty_inventory_blocks()   + rental_add_inventory_blocks()
  ├── rental_empty_product_options()    + rental_add_product_options()
  └── return { offset: 1, done: true }
```

These are small, bounded tables, so the legacy empty+rebuild pairs are reused as-is.

---

## 10. Phase 6 — sweep (guarded deletion)

```
Phases::phase_sweep( offset )
  │
  ├── GUARD 1: Session::phases_done([0,1,2,3,4,5]) ?
  │     └── no → log error, mark sweep_skipped, return done  ← a partial run never deletes
  │
  ├── entity = entities[ floor(offset / SWEEP_ENTITY_STRIDE) ]
  │            (products, variants, sets, categories, brands)
  │
  ├── orphans = find_orphans( entity )
  │     ├── products/variants: DISTINCT (rental_id, division) not in seen ids
  │     └── others:            DISTINCT rental_id not in seen ids
  │
  ├── GUARD 2: sweep_is_safe( entity, count(orphans) ) ?
  │     └── orphans > 20 AND orphans > seen  → refuse, mark sweep_skipped, next entity
  │         (override with option rental_data_sync_sweep_force)
  │
  ├── batch = first SWEEP_BATCH (200) orphans
  ├── foreach: Writer::delete_record( entity, rental_id, division_id )
  │              └── rentopian_{product,variant,set,category}_delete / delete_brand
  │
  └── more orphans of this entity?
        ├── yes → { offset: offset + processed, done: false }
        └── no  → { offset: next entity boundary, done: last entity? }
```

---

## 11. Phase 7 — finalize and chain

```
Phases::phase_finalize()
  ├── rental_curl('products/categories') → sort_categories( …, false )
  ├── rental_clear_cache()
  ├── do_action('rental_after_synchronization')       ← Polylang / translation hooks
  │
  ├── update_option rental_data_sync_last_run {
  │        sync_id, started_at, data_completed_at, stats, sweep_skipped }
  │
  ├── Scheduler::record_success( sync_id )            ← flips the button to "Resync"
  │
  ├── update_option rental_data_sync_pending_chain { sync_id, not_before: +60s }
  ├── wp_schedule_single_event( +75s, 'rental_data_sync_chain_files' )
  │
  └── return { offset: 1, done: true }   → chunk worker marks the session COMPLETED
```

### 11.1 Why the chain is deferred

While the finalize callback is executing, the Laravel `ProcessChunkJob` is still holding
the per-company `WithoutOverlapping` lock waiting for the response. Calling `sync/start`
for the file sync from inside the callback would dispatch a job that Laravel immediately
discards. The chain is therefore deferred by ~75 s and re-checked on `init` in case
WP-Cron is unreliable.

```
cron 'rental_data_sync_chain_files'  (or init fallback)
  └── Scheduler::maybe_chain_file_sync()
        ├── pending chain exists and time >= not_before ?      else return
        ├── transient 're-entrancy' guard                      (120 s)
        ├── session status === COMPLETED ?                     else drop the chain
        ├── delete pending-chain option
        │
        ├── Rental_Sync_Scheduler::schedule( MODE_SYNC )       ← existing file-sync module
        │
        ├── record file_sync_id / file_chained_at / file_chain_ok on the run record
        └── failure → log + Reporter::send_failure_report()
```

---

## 12. Report email

```
File phase completes
  └── file-sync chunk worker: do_action('rental_file_sync_completed', sync_id, duration)
        │
        └── Reporter::on_file_sync_completed( file_sync_id, duration )
              ├── run record matches this file_sync_id and not yet reported ?
              ├── record file_completed_at + file_duration
              ├── send_success_report( run )
              │     ├── durations (data, file, total)
              │     ├── per-entity created/updated/deleted/skipped/failed
              │     ├── sweep-skipped notice
              │     └── both sync ids + site URL
              ├── mark reported_at
              └── Session::cleanup_run_artifacts()     ← per-run options + staging files
```

---

## 13. Stop / cancel

```
Admin clicks Stop
  └── POST admin-ajax.php  action=rental_data_sync_cancel
        │
        └── Scheduler::cancel( sync_id = current, cancel_files = true )
              │
              ├── no data run? → still try cancel_file_sync() (chained file phase)
              │
              ├── Session::update({ status: CANCELED, canceled_at })
              ├── Session::release_lock()
              ├── delete pending chain (if it belongs to this run)
              ├── rental_curl('sync/cancel', { sync_id })          ← stop Laravel dispatch
              ├── Logger::write('Data sync canceled by admin')
              ├── Run_Repository::finish( CANCELED, 'Canceled by an administrator.' )
              ├── Session::cleanup_staging()
              │
              └── cancel_file_sync()
                    ├── file_sync_id = option rental_current_sync_id
                    ├── still CREATED/PROCESSING ?                 else no-op
                    ├── Rental_Sync_Session_Manager::update({ CANCELED })   ← file-sync module API
                    ├── Rental_Sync_Session_Manager::release_lock()
                    └── rental_curl('sync/cancel', { sync_id: file_sync_id })
```

The next chunk callback (if one is already in flight) sees `status === CANCELED` and
returns without doing work.

---

## 14. Admin panel state machine

```
page load
  └── GET rental_data_sync_status
        ├── Watchdog::enforce()   ← close an abandoned run before reporting on it
        ├── is_running = data running OR file running
        │
        ├── is_running ─────► button: disabled, "Synchronizing…", spinner
        │                     stop:   enabled
        │                     poll every 2 s for the first minute, then 5 s
        │
        └── idle ───────────► button: enabled
                              label:  has_previous_success ? "Resynchronize Now"
                                                           : "Start Synchronization"
                              stop:   disabled
                              polling stopped, run history refreshed

every response
  ├── data bar    = Status::progress_percent( phase, steps in phase )
  ├── files bar   = processed / total  (2% while queued but uncounted)
  ├── last-run line = Run_Repository::last_finished()   ← success or failure
  └── diagnosis ─► severity info  : ignored (the run is merely starting)
                   warning / error: banner with message + hints[]
                   null           : a problem banner, if any, is cleared
```

Pressing the primary button resets the panel to *Starting…* before the request goes out, so
a resync visibly restarts rather than appearing to resume the previous run.

The initial label and the last-run line are rendered server-side, so both are correct
before the script runs.

---

## 15. Session state transitions

```
                    schedule()
                        │
             preflight blocker,
             remote rejection,   ────────────────────────┐
             staging unwritable                          │
                        │                                │
                        ▼                                │
                    CREATED (1)                          │
                        │  first chunk processed         │
                        ▼                                │
                   PROCESSING (2) ──────────────┐        │
                        │                       │ admin Stop / Laravel cancel
      finalize step done│                       ▼        │
                        │                  CANCELED (5)  │
                        ▼                                │
                   COMPLETED (3)                         │
                                                         │
      5 consecutive step errors, a 5xx from variants,    │
      or no callback for FAIL_AFTER (watchdog)  ─────────┤
                                                         ▼
                                                     FAILED (4)
```

Every arrow into a terminal state also calls `Run_Repository::finish()`, so a run always
ends with a recorded time, duration and reason — see §14.6 of the README.

---

## 15.1 Watchdog

```
Watchdog::tick()                     (admin_init, rate-limited to once a minute)
  ├── enforce( current run )
  │     ├── idle_seconds = now - ( last_chunk_at ?: started_at )
  │     └── idle >= FAIL_AFTER (1800 s) and still CREATED/PROCESSING?
  │           ├── Session::update({ FAILED, last_error = named reason })
  │           ├── Run_Repository::finish( FAILED, reason )
  │           ├── Session::cleanup_staging()
  │           └── Reporter::send_failure_report()
  └── Session::purge_orphan_staging()   (staging dirs older than 24 h)

Watchdog::diagnose( session )        (every status poll)
  ├── FAILED                                  → 'failed'       (error)
  ├── no first_chunk_at, idle <  120 s        → 'starting'     (info)
  ├── no first_chunk_at, idle >= 120 s        → 'no_callback'  (error)
  ├── idle >= 420 s                           → 'stalled'      (warning)
  └── otherwise                               → null (healthy)
```

`first_chunk_at` is the discriminator: *the queue never delivered anything* and *the run
stalled part-way* are different problems, and `diagnose()` returns different hints for each.

---

## 16. Lock and retry flow

```
chunk callback
  ├── Session::acquire_lock( sync_id, ttl = 300 )
  │     ├── transient present → return { last_index: current cursor, completed: false }
  │     │                       (Laravel simply retries; no work is duplicated)
  │     └── absent → set transient, continue
  │
  ├── … perform one step …
  │
  └── Session::release_lock( sync_id )     ← also released on the error path
```

Laravel retrying an **older** `start_index` is harmless: the worker fast-forwards to
`Session::get_stored_cursor()` before decoding the phase, so work already done is never
repeated.
