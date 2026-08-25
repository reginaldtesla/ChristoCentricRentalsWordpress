<?php
/**
 * Component: RentPro Single Product Carousel
 * Enqueue OwlCarousel core + custom JS/CSS for the main product-image carousel.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Helper: enqueue OwlCarousel core CSS & JS from CDN, only once.
 * Uses handles 'owl-carousel-css', 'owl-carousel-theme-css', 'owl-carousel-js'.
 */
function rpc_enqueue_owlcarousel_core() {
    $version = '2.3.4';
    $handle_css_main  = 'owl-carousel-css';
    $handle_css_theme = 'owl-carousel-theme-css';
    $handle_js        = 'owl-carousel-js';

    // Enqueue main CSS if not already enqueued
    if ( ! wp_style_is( $handle_css_main, 'enqueued' ) ) {
        wp_enqueue_style(
            $handle_css_main,
            'https://cdnjs.cloudflare.com/ajax/libs/OwlCarousel2/2.3.4/assets/owl.carousel.min.css',
            array(),
            $version
        );
    }
    // Enqueue theme CSS if not already enqueued
    if ( ! wp_style_is( $handle_css_theme, 'enqueued' ) ) {
        wp_enqueue_style(
            $handle_css_theme,
            'https://cdnjs.cloudflare.com/ajax/libs/OwlCarousel2/2.3.4/assets/owl.theme.default.min.css',
            array( $handle_css_main ),
            $version
        );
    }
    // Enqueue JS if not already enqueued
    if ( ! wp_script_is( $handle_js, 'enqueued' ) ) {
        wp_enqueue_script(
            $handle_js,
            'https://cdnjs.cloudflare.com/ajax/libs/OwlCarousel2/2.3.4/owl.carousel.min.js',
            array( 'jquery' ),
            $version,
            true
        );
    }
}

/**
 * Enqueue & initialize the RentPro main product-image carousel on single product pages.
 */
function rpc_enqueue_and_init_single_product_carousel() {
    if ( ! function_exists( 'is_product' ) || ! is_product() ) {
        return;
    }

    // Enqueue OwlCarousel core
    rpc_enqueue_owlcarousel_core();

    // Enqueue custom CSS for the main carousel
    $css_file_rel = 'assets/css/rentpro-single-product-carousel.css';
    $css_file_path = plugin_dir_path( __FILE__ ) . '../' . $css_file_rel;
    $css_file_url  = plugin_dir_url( __FILE__ ) . '../' . $css_file_rel;
    if ( file_exists( $css_file_path ) ) {
        wp_enqueue_style(
            'rentpro-single-product-carousel-css',
            $css_file_url,
            array( 'owl-carousel-css' ),
            '1.0.0'
        );
    }

    // Enqueue the separate JS file that initializes the carousel
    $js_file_rel = 'assets/js/rentpro-single-product-carousel.js';
    $js_file_path = plugin_dir_path( __FILE__ ) . '../' . $js_file_rel;
    $js_file_url  = plugin_dir_url( __FILE__ ) . '../' . $js_file_rel;
    if ( file_exists( $js_file_path ) ) {
        wp_enqueue_script(
            'rentpro-single-product-carousel-js',
            $js_file_url,
            array( 'jquery', 'owl-carousel-js' ),
            '1.0.0',
            true
        );
    }

}
add_action( 'wp_enqueue_scripts', 'rpc_enqueue_and_init_single_product_carousel', 99 );
