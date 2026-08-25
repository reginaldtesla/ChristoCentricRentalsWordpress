<?php
/**
 * Rental Translation Module Bootstrap
 *
 * Loads all translation classes, registers cron intervals, and wires up
 * hooks to integrate with the Rentopian sync process and webhook API.
 *
 * Include this file once from your main plugin file:
 *   require_once RENTOPIAN_SYNC_PATH . '/includes/translation/rental-translation-bootstrap.php';
 *
 * @package RentopianSync\Translation
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ─────────────────────────────────────────────────────────────────
//  Autoload translation classes
// ─────────────────────────────────────────────────────────────────

$rental_translation_dir = __DIR__;

require_once $rental_translation_dir . '/interface-rental-translation-service.php';
require_once $rental_translation_dir . '/class-rental-translation-deepl.php';
require_once $rental_translation_dir . '/class-rental-translation-google.php';
require_once $rental_translation_dir . '/class-rental-translation-factory.php';
require_once $rental_translation_dir . '/class-rental-translation-cache.php';
require_once $rental_translation_dir . '/class-rental-translation-usage.php';
require_once $rental_translation_dir . '/class-rental-translation-processor.php';
require_once $rental_translation_dir . '/class-rental-translation-queue.php';
require_once $rental_translation_dir . '/class-rental-translation-admin.php';
require_once $rental_translation_dir . '/class-rental-translation-image-propagator.php';
require_once $rental_translation_dir . '/class-rental-translation-overrides-page.php';

// ─────────────────────────────────────────────────────────────────
//  Register custom cron interval
// ─────────────────────────────────────────────────────────────────

add_filter( 'cron_schedules', [ 'Rental_Translation_Queue', 'register_cron_interval' ] );

// ─────────────────────────────────────────────────────────────────
//  Instantiate core components
// ─────────────────────────────────────────────────────────────────

// Queue singleton (registers its cron hook).
Rental_Translation_Queue::get_instance();

// Image propagator — hooks into update_post_meta / update_term_meta
// to sync images from source posts to Polylang translations.
Rental_Translation_Image_Propagator::init();

// Admin settings, overrides page, and DB table creation.
if ( is_admin() ) {
    new Rental_Translation_Admin();
    new Rental_Translation_Overrides_Page();

    // Ensure the translation cache table exists (safe to call repeatedly).
    add_action( 'admin_init', function () {
        Rental_Translation_Cache::get_instance()->maybe_create_table();
    } );
}

// ─────────────────────────────────────────────────────────────────
//  Hook into bulk sync (rental_after_synchronization)
//
//  This fires AFTER the Polylang language assignment hook, so all
//  source content has its default language set before we enqueue
//  translation jobs.
// ─────────────────────────────────────────────────────────────────

add_action( 'rental_after_synchronization', function () {
    if ( '1' !== get_option( 'rental_translation_enabled', '0' ) ) {
        return;
    }

    $queue = Rental_Translation_Queue::get_instance();
    $queue->enqueue_bulk_sync_items();
}, 20 ); // Priority 20: runs after Polylang integration (priority 10).

// ─────────────────────────────────────────────────────────────────
//  Public helper functions for webhook API handlers
// ─────────────────────────────────────────────────────────────────

/**
 * Queue a product (+ optional variations) for translation.
 *
 * Call after rental_pll_assign_product() in api.php.
 *
 * @param int   $product_id  WP product post ID.
 * @param int[] $variant_ids WP variation post IDs.
 */
function rental_translation_queue_product( int $product_id, array $variant_ids = [] ): void {
    if ( '1' !== get_option( 'rental_translation_enabled', '0' ) ) {
        return;
    }

    $queue = Rental_Translation_Queue::get_instance();

    $post = get_post( $product_id );
    if ( $post ) {
        $queue->enqueue_post( $product_id, $post->post_type, [
            'title'   => $post->post_title,
            'content' => $post->post_content,
            'excerpt' => $post->post_excerpt,
        ] );
    }

    foreach ( $variant_ids as $variant_id ) {
        $variant_id = (int) $variant_id;
        $variant    = get_post( $variant_id );
        if ( $variant ) {
            $queue->enqueue_post( $variant_id, 'product_variation', [
                'title'   => $variant->post_title,
                'content' => $variant->post_content,
                'excerpt' => $variant->post_excerpt,
            ] );
        }
    }
}

/**
 * Queue a single post for translation.
 *
 * Call after rental_pll_assign_post() in api.php.
 *
 * @param int $post_id WP post ID.
 */
function rental_translation_queue_post( int $post_id ): void {
    if ( '1' !== get_option( 'rental_translation_enabled', '0' ) ) {
        return;
    }

    $post = get_post( $post_id );
    if ( ! $post ) {
        return;
    }

    $queue = Rental_Translation_Queue::get_instance();
    $queue->enqueue_post( $post_id, $post->post_type, [
        'title'   => $post->post_title,
        'content' => $post->post_content,
        'excerpt' => $post->post_excerpt,
    ] );
}

/**
 * Queue a single term for translation.
 *
 * Call after rental_pll_assign_term() in api.php.
 *
 * @param int    $term_id  WP term ID.
 * @param string $taxonomy Taxonomy name (auto-detected if empty).
 */
function rental_translation_queue_term( int $term_id, string $taxonomy = '' ): void {
    if ( '1' !== get_option( 'rental_translation_enabled', '0' ) ) {
        return;
    }

    if ( empty( $taxonomy ) ) {
        $term = get_term( $term_id );
        if ( ! $term || is_wp_error( $term ) ) {
            return;
        }
        $taxonomy = $term->taxonomy;
    } else {
        $term = get_term( $term_id, $taxonomy );
        if ( ! $term || is_wp_error( $term ) ) {
            return;
        }
    }

    $queue = Rental_Translation_Queue::get_instance();
    $queue->enqueue_term( $term_id, $taxonomy, [
        'name'        => $term->name,
        'description' => $term->description,
    ] );
}

/**
 * Queue multiple terms for translation.
 *
 * Call after rental_pll_assign_terms() in api.php.
 *
 * @param int[]  $term_ids Array of WP term IDs.
 * @param string $taxonomy Taxonomy name (auto-detected per term if empty).
 */
function rental_translation_queue_terms( array $term_ids, string $taxonomy = '' ): void {
    if ( '1' !== get_option( 'rental_translation_enabled', '0' ) ) {
        return;
    }

    foreach ( $term_ids as $term_id ) {
        rental_translation_queue_term( (int) $term_id, $taxonomy );
    }
}
