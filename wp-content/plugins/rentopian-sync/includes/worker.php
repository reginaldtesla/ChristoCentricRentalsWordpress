<?php

if (! function_exists('rental_adapt_chunk_size')) {
    function rental_adapt_chunk_size($sync_id, $incoming_limit) {
        $default = (int) get_option('rental_default_chunk_size', 8);

        $prev    = rental_file_sync_log_get_last_elapsed($sync_id);
        $hadErr  = rental_file_sync_log_had_recent_error($sync_id, 900); // last 15 min
    
        $effective = $incoming_limit ?: $default;
    
        if ($hadErr) {
            $effective = 1;
        } elseif ($prev !== null) {

            if ($prev > 35) {
                $effective = 1;
            } elseif ($prev > 25) {
                $effective = 4;
            } elseif ($prev < 15) {
                $effective = $default;
            }
        }
    
        // hard clamp to sane bounds
        if ($effective < 1) $effective = 1;
        if ($effective > 25) $effective = 25;
    
        // remember current pick for this sync
        update_option("rental_effective_chunk_size_{$sync_id}", (int)$effective, false);
    
        return (int)$effective;
    }
}
    

function rental_process_images_chunk($start, $limit, $sync_id, $is_resync = false) {
    $__req_t0 = microtime(true);

    $sessions = get_option('rental_sync_sessions', []);
    if (!isset($sessions[$sync_id])) {
        return ['last_index' => $start, 'failed_ids' => [], 'failed_count' => 0, 'failed_ids_truncated' => false, 'completed' => false];
    }

    // if canceled, return immediately
    if (isset($sessions[$sync_id]['status']) && $sessions[$sync_id]['status'] === Rentopian_Sync_REST::STATUS_CANCELED) {

        return ['last_index' => $start, 'failed_ids' => [], 'failed_count' => 0, 'failed_ids_truncated' => false, 'completed' => true];
    }

    $cur_gen  = rental_sync_generation_current();
    $sess_gen = isset($sessions[$sync_id]['generation']) ? (int)$sessions[$sync_id]['generation'] : -1;
    if ($sess_gen !== $cur_gen) {
        // Don’t process anything; this job is stale
        return ['last_index' => $start, 'failed_ids' => [], 'failed_count'=>0, 'failed_ids_truncated'=>false, 'completed' => false];
    }

    // transient-based lock: prevent concurrent jobs from processing same session
    $lock_key = "rental_sync_lock_{$sync_id}";
    $got_lock = get_transient($lock_key);
    if ($got_lock) {
        // Another worker is processing this sync_id - tell caller to retry later
        return ['last_index' => $start, 'failed_ids' => $sessions[$sync_id]['failed_ids'] ?? [], 'failed_count' => count($sessions[$sync_id]['failed_ids'] ?? []), 'failed_ids_truncated' => false, 'completed' => false];
    }
    set_transient($lock_key, time(), 60);

    try {

        // $effective_limit = $limit == 1 ? 1 : rental_adapt_chunk_size($sync_id, $limit);
        // $effective_limit = rental_adapt_chunk_size($sync_id, $limit);
        $effective_limit = 5;

        if ($is_resync) {
            // Re-Sync

            $bg_result = rental_resync_upload_images_bg(intval($start), $effective_limit, $sync_id);

        } else {
            // Initial Sync

            $bg_result = rental_upload_images_bg(intval($start), $effective_limit, $sync_id);
        }
  

        // After upload functions run, read options that they set
        $new_last_index = intval(get_option("rental_products_img_last_id_{$sync_id}", intval($start)));

        // authoritative failed ids live in DB; obtain list then produce a *sample* for responses
        $failed_ids = rental_failed_get_ids_by_sync($sync_id);
        $failed_count = count($failed_ids);

        $img_count = intval(get_option('rental_products_img_count', 0));
        $completed_flag = (bool) get_option("rental_image_upload_completed_{$sync_id}", false);
        $processed_count = intval(get_option("rental_products_img_processed_{$sync_id}", 0));

        // update sessions option
        $sessions[$sync_id]['last_index'] = $new_last_index;
        // $sessions[$sync_id]['failed_ids'] = $failed_ids_sample; // keep only sample in session snapshot to avoid memory blowup in options
        $sessions[$sync_id]['failed_ids'] = $failed_ids; // keep only sample in session snapshot to avoid memory blowup in options
        $sessions[$sync_id]['failed_count'] = $failed_count;
        $sessions[$sync_id]['status'] = $completed_flag ? Rentopian_Sync_REST::STATUS_COMPLETED : Rentopian_Sync_REST::STATUS_PROCESSING;
        $sessions[$sync_id]['processed_count'] = $processed_count;
        $sessions[$sync_id]['total_count'] = $img_count;

        update_option('rental_sync_sessions', $sessions);

        
        $elapsed = microtime(true) - $__req_t0;
        $mode_label  = $is_resync ? 'resync' : 'sync';

        $log_data = [
            'failed_count'         => $failed_count,
            'failed_ids_sample'    => $failed_ids,
            'sessions_last_error'  => isset($sessions[$sync_id]['last_error']) ? $sessions[$sync_id]['last_error'] : '',
            'sessions_last_index'  => $sessions[$sync_id]['last_index'] ?? '',
            'sessions_status'      => $sessions[$sync_id]['status'] ?? '',
            'elapsed'              => $elapsed,
            'prev_elapsed'         => rental_file_sync_log_get_last_elapsed($sync_id),
            'effective_limit'      => $effective_limit,
            'incoming_limit'       => intval($limit),
            'is_resync'            => $is_resync ? 1 : 0,
        ];
        
        $log_message = sprintf(
            'Processed chunk start=%d limit=%d last_index=%d processed=%d total=%d failed=%d',
            intval($start),             // 1) cursor start that WP saw
            intval($effective_limit),   // 2) real batch size used
            intval($new_last_index),    // 3) last rental_id / cursor after this chunk
            intval($processed_count),   // 4) how many images processed so far
            intval($img_count),         // 5) total images to sync (from count endpoint)
            intval($failed_count)       // 6) how many failed (unique)
        );

        // Log
        rental_file_sync_log_write(
            $sync_id,
            'info',
            $log_message,
            $log_data,
            $new_last_index,
            $processed_count,
            $img_count,
            $elapsed,
            Rentopian_Sync_REST::STATUS_PROCESSING,
            $is_resync ? Rentopian_Sync_REST::MODE_RESYNC : Rentopian_Sync_REST::MODE_SYNC
        );


        // Release lock
        delete_transient($lock_key);

        if ($completed_flag) {
            update_option('rental_synchronize_status', 1);
            update_option('rental_api_key_is_valid', 1);

            $duration = Rental_Timer::stop_persistent();
            update_option("rental_show_sync_duration",  $duration !== false ? $duration : '');

            rental_file_sync_log_write(
                $sync_id, 
                'info',
                sprintf('File sync completed (mode=%s)', $mode_label),
                [
                    'failed_count' => $failed_count,
                    'failed_ids' => $failed_ids
                ],
                $new_last_index,
                $processed_count,
                $img_count,
                floatval($duration),
                Rentopian_Sync_REST::STATUS_COMPLETED,
                $is_resync ? Rentopian_Sync_REST::MODE_RESYNC : Rentopian_Sync_REST::MODE_SYNC
            );
        }

        return [
            'last_index' => $new_last_index,
            'failed_ids' => $failed_ids,
            'failed_count' => $failed_count,
            'completed' => $completed_flag
        ];

    } catch (Exception $e) {
        // Save last error in session
        $sessions[$sync_id]['last_error'] = $e->getMessage();
        update_option('rental_sync_sessions', $sessions);

        // release lock
        delete_transient($lock_key);

        $elapsed = microtime(true) - $__req_t0;

        rental_file_sync_log_write(
            $sync_id,
            'error',
            'Exception in rental_process_images_chunk: ' . $e->getMessage(),
            ['trace'=> $e->getTraceAsString()],
            intval($start),
            intval(get_option("rental_products_img_processed_{$sync_id}",0)),
            intval(get_option('rental_products_img_count',0)),
            $elapsed,
            Rentopian_Sync_REST::STATUS_PROCESSING
        );

        return ['last_index' => $start, 'failed_ids' => [], 'failed_count' => 0, 'failed_ids_truncated' => false, 'completed' => false];
    }
}
