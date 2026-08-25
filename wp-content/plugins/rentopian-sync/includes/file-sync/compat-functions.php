<?php
/**
 * Backward-Compatible Function Aliases
 *
 * Thin wrappers that delegate to the new OOP classes. These keep any
 * existing call-sites (in functions.php, templates, third-party code)
 * working without modification.
 *
 * @package RentopianSync\FileSync
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/*
|--------------------------------------------------------------------------
| Rentopian_Sync_REST constant alias
|--------------------------------------------------------------------------
| The old class name is referenced in many places as:
|   Rentopian_Sync_REST::STATUS_CANCELED
|   Rentopian_Sync_REST::MODE_SYNC  etc.
|
| We keep the class shell so nothing breaks.
*/
if ( ! class_exists( 'Rentopian_Sync_REST', false ) ) {
    class Rentopian_Sync_REST {
        const STATUS_CREATED    = 1;
        const STATUS_PROCESSING = 2;
        const STATUS_COMPLETED  = 3;
        const STATUS_FAILED     = 4;
        const STATUS_CANCELED   = 5;
        const MODE_SYNC         = 1;
        const MODE_RESYNC       = 2;
    }
}

/*
|--------------------------------------------------------------------------
| Sync Log aliases
|--------------------------------------------------------------------------
*/
if ( ! function_exists( 'rental_file_sync_log_write' ) ) {
    function rental_file_sync_log_write( $sync_id, $level, $message, $data = '', $last_index = 0, $processed_count = 0, $total_count = 0, $elapsed = 0.0, $status = 2, $mode = 1 ) {
        return Rental_Sync_Log_Repository::write( $sync_id, $level, $message, $data, $last_index, $processed_count, $total_count, $elapsed, $status, $mode );
    }
}

if ( ! function_exists( 'rental_file_sync_log_get_grouped' ) ) {
    function rental_file_sync_log_get_grouped( $limit = 20, $offset = 0 ) {
        return Rental_Sync_Log_Repository::get_grouped( $limit, $offset );
    }
}

if ( ! function_exists( 'rental_file_sync_log_get_entries' ) ) {
    function rental_file_sync_log_get_entries( $sync_id ) {
        return Rental_Sync_Log_Repository::get_entries( $sync_id );
    }
}

if ( ! function_exists( 'rental_file_sync_log_get_grouped_count' ) ) {
    function rental_file_sync_log_get_grouped_count() {
        return Rental_Sync_Log_Repository::get_grouped_count();
    }
}

if ( ! function_exists( 'rental_file_sync_log_get_grouped_page' ) ) {
    function rental_file_sync_log_get_grouped_page( $page = 1, $per_page = 20 ) {
        return Rental_Sync_Log_Repository::get_grouped_page( $page, $per_page );
    }
}

if ( ! function_exists( 'rental_file_sync_log_get_entries_paginated' ) ) {
    function rental_file_sync_log_get_entries_paginated( $sync_id, $page = 1, $per_page = 20 ) {
        return Rental_Sync_Log_Repository::get_entries_paginated( $sync_id, $page, $per_page );
    }
}

if ( ! function_exists( 'rental_file_sync_log_get_last_elapsed' ) ) {
    function rental_file_sync_log_get_last_elapsed( $sync_id ) {
        return Rental_Sync_Log_Repository::get_last_elapsed( $sync_id );
    }
}

if ( ! function_exists( 'rental_file_sync_log_had_recent_error' ) ) {
    function rental_file_sync_log_had_recent_error( $sync_id, $seconds = 900 ) {
        return Rental_Sync_Log_Repository::had_recent_error( $sync_id, $seconds );
    }
}

if ( ! function_exists( 'rental_get_last_completed_sync_session' ) ) {
    function rental_get_last_completed_sync_session() {
        return Rental_Sync_Log_Repository::get_last_completed_sync_session();
    }
}

/*
|--------------------------------------------------------------------------
| Failed Images aliases
|--------------------------------------------------------------------------
*/
if ( ! function_exists( 'rental_failed_table_name' ) ) {
    function rental_failed_table_name() {
        global $wpdb, $rental_tables;
        return $wpdb->prefix . $rental_tables['failed_images'];
    }
}

if ( ! function_exists( 'rental_failed_mark' ) ) {
    function rental_failed_mark( $rental_id, $sync_id = null, $error = '' ) {
        return Rental_Failed_Image_Repository::mark( $rental_id, $sync_id, $error );
    }
}

if ( ! function_exists( 'rental_failed_get_ids_by_sync' ) ) {
    function rental_failed_get_ids_by_sync( $sync_id = null ) {
        return Rental_Failed_Image_Repository::get_ids_by_sync( $sync_id );
    }
}

if ( ! function_exists( 'rental_failed_get_retryable_ids' ) ) {
    function rental_failed_get_retryable_ids( $sync_id, $max_attempts = 3 ) {
        return Rental_Failed_Image_Repository::get_retryable_ids( $sync_id, $max_attempts );
    }
}

if ( ! function_exists( 'rental_failed_mark_resolved' ) ) {
    function rental_failed_mark_resolved( array $rental_ids = [], $resolved_by = 'system' ) {
        return Rental_Failed_Image_Repository::mark_resolved( $rental_ids, $resolved_by );
    }
}

if ( ! function_exists( 'rental_failed_clear_for_sync' ) ) {
    function rental_failed_clear_for_sync( $sync_id ) {
        return Rental_Failed_Image_Repository::clear_for_sync( $sync_id );
    }
}

if ( ! function_exists( 'rental_failed_clear_all' ) ) {
    function rental_failed_clear_all() {
        return Rental_Failed_Image_Repository::clear_all();
    }
}

if ( ! function_exists( 'rental_failed_get_ids_for_sync_paginated' ) ) {
    function rental_failed_get_ids_for_sync_paginated( $sync_id, $page = 1, $per_page = 50 ) {
        return Rental_Failed_Image_Repository::get_ids_paginated( $sync_id, $page, $per_page );
    }
}

if ( ! function_exists( 'rental_failed_get_rows' ) ) {
    function rental_failed_get_rows( $limit = 100 ) {
        return Rental_Failed_Image_Repository::get_rows( $limit );
    }
}

/*
|--------------------------------------------------------------------------
| Session / Generation aliases
|--------------------------------------------------------------------------
*/
if ( ! function_exists( 'rental_sync_generation_current' ) ) {
    function rental_sync_generation_current() {
        return Rental_Sync_Session_Manager::generation_current();
    }
}

if ( ! function_exists( 'rental_sync_is_globally_disabled' ) ) {
    function rental_sync_is_globally_disabled() {
        return Rental_Sync_Session_Manager::is_globally_disabled();
    }
}

if ( ! function_exists( 'rental_sync_guard_or_null' ) ) {
    function rental_sync_guard_or_null( $sync_id = null ) {
        return Rental_Sync_Session_Manager::guard_or_null( $sync_id );
    }
}

if ( ! function_exists( 'rental_compute_resume_index' ) ) {
    function rental_compute_resume_index( $sync_id ) {
        return Rental_Sync_Session_Manager::compute_resume_index( $sync_id );
    }
}

if ( ! function_exists( 'rental_prepare_session_for_resume' ) ) {
    function rental_prepare_session_for_resume( $sync_id ) {
        return Rental_Sync_Session_Manager::prepare_for_resume( $sync_id );
    }
}

if ( ! function_exists( 'rental_pick_latest_broken_sync_id' ) ) {
    function rental_pick_latest_broken_sync_id() {
        return Rental_Sync_Session_Manager::pick_latest_broken_sync_id();
    }
}

/*
|--------------------------------------------------------------------------
| Worker / Uploader aliases
|--------------------------------------------------------------------------
*/
if ( ! function_exists( 'rental_process_images_chunk' ) ) {
    function rental_process_images_chunk( $start, $limit, $sync_id, $is_resync = false ) {
        return Rental_Chunk_Worker::process( $start, $limit, $sync_id, $is_resync );
    }
}

if ( ! function_exists( 'rental_adapt_chunk_size' ) ) {
    function rental_adapt_chunk_size( $sync_id, $incoming_limit ) {
        return Rental_Chunk_Worker::adapt_chunk_size( $sync_id, $incoming_limit );
    }
}

if ( ! function_exists( 'rental_process_images_array_for_retry' ) ) {
    function rental_process_images_array_for_retry( $sync_id, $image_ids = [] ) {
        return Rental_Retry_Processor::process( $sync_id, $image_ids );
    }
}

if ( ! function_exists( 'rental_upload_images_bg' ) ) {
    function rental_upload_images_bg( $start, $limit, $sync_id ) {
        return Rental_Sync_Uploader::process( $start, $limit, $sync_id );
    }
}

if ( ! function_exists( 'rental_resync_upload_images_bg' ) ) {
    function rental_resync_upload_images_bg( $start, $limit, $sync_id ) {
        return Rental_Resync_Uploader::process( $start, $limit, $sync_id );
    }
}

/*
|--------------------------------------------------------------------------
| Image processing aliases
|--------------------------------------------------------------------------
*/
if ( ! function_exists( 'rental_mark_image_failed' ) ) {
    function rental_mark_image_failed( $rental_image_id, $reason = '', $error_msg = '' ) {
        $sync_id = get_option( 'rental_current_sync_id', '' );
        Rental_Failed_Image_Repository::mark( intval( $rental_image_id ), $sync_id ?: null, $error_msg );
        update_option( 'rental_image_upload_completed', false );

        if ( ! empty( $reason ) ) {
            Rental_Sync_Log_Repository::write(
                $sync_id,
                'error',
                "Image failed id={$rental_image_id} reason={$reason}",
                [ 'error_msg' => $error_msg ],
                intval( $rental_image_id ),
                intval( get_option( "rental_products_img_processed_{$sync_id}", 0 ) ),
                intval( get_option( 'rental_products_img_count', 0 ) ),
                0.0,
                Rental_Sync_Status::STATUS_PROCESSING
            );
        }

        if ( class_exists( 'ErrorHandler', false ) && class_exists( 'RentalException', false ) ) {
            ErrorHandler::registerErrorInLog(
                "Following image {$rental_image_id} has errors : {$error_msg}",
                __FILE__, __LINE__,
                RentalException::TYPE_SYNC_RUNTIME
            );
        }
    }
}

if ( ! function_exists( 'rental_update_attachment_metadata_with_fallback' ) ) {
    function rental_update_attachment_metadata_with_fallback( $attachment_id, $file, $use_custom = true ) {
        return Rental_Image_Subsizer::update_metadata_with_fallback( $attachment_id, $file, $use_custom );
    }
}

if ( ! function_exists( 'rental_make_image_subsizes' ) ) {
    function rental_make_image_subsizes( $file, $attachment_id ) {
        return Rental_Image_Subsizer::make_subsizes( $file, $attachment_id );
    }
}

if ( ! function_exists( 'rental_make_image_subsizes_2' ) ) {
    function rental_make_image_subsizes_2( $file, $attachment_id ) {
        return Rental_Image_Subsizer::make_subsizes( $file, $attachment_id );
    }
}

if ( ! function_exists( 'rental_resize_image_multiple_gd' ) ) {
    function rental_resize_image_multiple_gd( $filename, $sizes, $image_meta ) {
        return Rental_Image_Subsizer::resize_multiple_gd( $filename, $sizes, $image_meta );
    }
}

if ( ! function_exists( 'rental_calc_crop_box' ) ) {
    function rental_calc_crop_box( $origW, $origH, $tW, $tH, $crop ) {
        return Rental_Image_Subsizer::calc_crop_box( $origW, $origH, $tW, $tH, $crop );
    }
}

if ( ! function_exists( 'resizeImageMultiple' ) ) {
    function resizeImageMultiple( $filename, $sizes, $image_meta ) {
        return Rental_Image_Subsizer::resize_multiple_gd( $filename, $sizes, $image_meta );
    }
}

if ( ! function_exists( 'rental_resync_find_attachment_by_filename' ) ) {
    function rental_resync_find_attachment_by_filename( $filename ) {
        return Rental_Image_Downloader::find_attachment_by_filename( $filename );
    }
}

if ( ! function_exists( 'rental_resync_resolve_existing_attachment' ) ) {
    function rental_resync_resolve_existing_attachment( $rental_id, $image_url ) {
        return Rental_Image_Downloader::resolve_existing_attachment( $rental_id, $image_url );
    }
}

if ( ! function_exists( 'rental_resync_download_image_to_attachment' ) ) {
    function rental_resync_download_image_to_attachment( $image_url, $rental_id, $sync_id ) {
        return Rental_Image_Downloader::download_and_attach( $image_url, $rental_id, $sync_id );
    }
}

if ( ! function_exists( 'rental_resync_attach_to_products' ) ) {
    function rental_resync_attach_to_products( $image, $attach_id ) {
        Rental_Image_Relation_Attacher::attach_to_products( is_array( $image ) ? (object) $image : $image, $attach_id );
    }
}

if ( ! function_exists( 'rental_resync_attach_to_all_relations' ) ) {
    function rental_resync_attach_to_all_relations( $image, $attach_id, &$variant_gallery, &$set_gallery ) {
        Rental_Image_Relation_Attacher::attach_to_all( $image, $attach_id, $variant_gallery, $set_gallery );
    }
}

/*
|--------------------------------------------------------------------------
| Image performance filter aliases
|--------------------------------------------------------------------------
*/
if ( ! function_exists( 'rentopian_enable_image_perf_filters' ) ) {
    function rentopian_enable_image_perf_filters() {
        Rental_Image_Performance_Filter::enable();
    }
}

if ( ! function_exists( 'rentopian_disable_image_perf_filters' ) ) {
    function rentopian_disable_image_perf_filters() {
        Rental_Image_Performance_Filter::disable();
    }
}

if ( ! function_exists( 'rentopian_big_image_cap' ) ) {
    function rentopian_big_image_cap( $pixels ) {
        return Rental_Image_Performance_Filter::cap_big_image( $pixels );
    }
}

if ( ! function_exists( 'rentopian_trim_sizes' ) ) {
    function rentopian_trim_sizes( $sizes, $metadata ) {
        return Rental_Image_Performance_Filter::trim_sizes( $sizes, $metadata );
    }
}

if ( ! function_exists( 'rentopian_output_format' ) ) {
    function rentopian_output_format( $formats ) {
        return Rental_Image_Performance_Filter::output_format( $formats );
    }
}

/*
|--------------------------------------------------------------------------
| Scheduler alias
|--------------------------------------------------------------------------
*/
if ( ! function_exists( 'rental_schedule_file_sync' ) ) {
    function rental_schedule_file_sync( $mode = 1 ) {
        return Rental_Sync_Scheduler::schedule( $mode );
    }
}
