<?php
if ( ! defined('ABSPATH') ) { exit; }

function rentopian_enable_image_perf_filters() {
    // Cap originals to 2560px max dimension
    add_filter('big_image_size_threshold', 'rentopian_big_image_cap', 10, 1);
    // Trim heavy sizes
    add_filter('intermediate_image_sizes_advanced', 'rentopian_trim_sizes', 10, 2);
    // Convert giant PNGs to JPEG when possible (no alpha)
    add_filter('image_editor_output_format', 'rentopian_output_format', 10, 3);
}

function rentopian_disable_image_perf_filters() {
    remove_filter('big_image_size_threshold', 'rentopian_big_image_cap', 10);
    remove_filter('intermediate_image_sizes_advanced', 'rentopian_trim_sizes', 10);
    remove_filter('image_editor_output_format', 'rentopian_output_format', 10);
}

function rentopian_big_image_cap( $pixels ) {
    return 2560;
}

function rentopian_trim_sizes( $sizes, $metadata ) {
    // Remove the heaviest sizes
    unset($sizes['2048x2048'], $sizes['1536x1536'], $sizes['large']);
    return $sizes;
}

function rentopian_output_format( $formats, $filename = '', $mime_type = '' ) {
    // WordPress does NOT detect transparency here, and GD paints transparent
    // pixels black — a dark icon on a transparent background would arrive as a
    // solid black rectangle. The class owns the check; this legacy copy keeps
    // the conversion off when it is not available rather than guess.
    if ( class_exists( 'Rental_Image_Performance_Filter', false ) ) {
        return Rental_Image_Performance_Filter::output_format( $formats, $filename, $mime_type );
    }

    return $formats;
}
