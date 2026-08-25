<?php
/**
 * Rental Polylang Integration
 *
 * Assigns the default (English) language to all Rentopian-synced content
 * (products, variations, categories, tags, attributes) and creates empty
 * translation stubs for every other active Polylang language.
 *
 * Supports both:
 *  - Bulk sync (via `rental_after_synchronization` hook)
 *  - Individual item sync via API webhooks (via dedicated public methods)
 *
 * Design principles:
 *  - Silently skips all logic when Polylang is not active.
 *  - Hooks into the sync process AFTER bulk SQL inserts are complete.
 *  - Uses Polylang's public API (pll_*) exclusively — no direct taxonomy hacking.
 *  - Backward-compatible: does not alter the existing sync function's behaviour.
 *  - Batches DB operations for performance on large catalogues.
 *  - Individual methods are idempotent — safe to call on items that already have a language.
 *
 * @package RentopianSync
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class Rental_Polylang_Integration
 *
 * Single-responsibility: bridge between Rentopian sync and Polylang.
 */
class Rental_Polylang_Integration {

    /**
     * Default language slug (the language synced products are assigned to).
     *
     * @var string
     */
    private string $default_lang = '';

    /**
     * All active Polylang language slugs.
     *
     * @var string[]
     */
    private array $languages = [];

    /**
     * Non-default language slugs (languages that get empty stubs).
     *
     * @var string[]
     */
    private array $secondary_languages = [];

    /**
     * Batch size for chunked processing.
     *
     * @var int
     */
    private int $batch_size = 200;

    /**
     * Singleton instance.
     *
     * @var self|null
     */
    private static ?self $instance = null;

    // ─────────────────────────────────────────────────────────────
    //  Bootstrap
    // ─────────────────────────────────────────────────────────────

    /**
     * Wire up hooks.
     */
    public function __construct() {
        // Hook after the Rentopian bulk sync completes successfully.
        add_action( 'rental_after_synchronization', [ $this, 'handle_post_sync' ], 10, 0 );
    }

    /**
     * Get singleton instance.
     *
     * Ensures we reuse the same initialised object when called from api.php
     * without re-running constructor hooks multiple times.
     *
     * @return self
     */
    public static function get_instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    // ─────────────────────────────────────────────────────────────
    //  Guards
    // ─────────────────────────────────────────────────────────────

    /**
     * Check whether Polylang is active and its API is available.
     *
     * @return bool
     */
    private function is_polylang_active(): bool {
        return function_exists( 'pll_default_language' )
            && function_exists( 'pll_languages_list' )
            && function_exists( 'pll_set_post_language' )
            && function_exists( 'pll_set_term_language' )
            && function_exists( 'pll_save_post_translations' )
            && function_exists( 'pll_save_term_translations' );
    }

    /**
     * Ensure Polylang is active and language properties are initialised.
     *
     * Lazy-initialises on first call, then caches for subsequent calls
     * within the same request.
     *
     * @return bool True if Polylang is ready and at least one language exists.
     */
    private function ensure_initialised(): bool {
        if ( ! $this->is_polylang_active() ) {
            return false;
        }

        // Already initialised in this request.
        if ( ! empty( $this->default_lang ) ) {
            return true;
        }

        return $this->init_languages();
    }

    /**
     * Initialise language properties from Polylang.
     *
     * @return bool True if at least one language exists.
     */
    private function init_languages(): bool {
        $this->default_lang = pll_default_language( 'slug' );

        if ( empty( $this->default_lang ) ) {
            return false;
        }

        $this->languages = pll_languages_list( [ 'hide_empty' => false ] );

        if ( empty( $this->languages ) ) {
            return false;
        }

        $this->secondary_languages = array_values(
            array_diff( $this->languages, [ $this->default_lang ] )
        );

        return true;
    }

    // ─────────────────────────────────────────────────────────────
    //  Main entry point – Bulk sync
    // ─────────────────────────────────────────────────────────────

    /**
     * Called after a successful Rentopian bulk sync.
     *
     * Orchestrates the full Polylang assignment for all content types.
     */
    public function handle_post_sync(): void {
        if ( ! $this->ensure_initialised() ) {
            return;
        }

        // Products & Variations (posts).
        $this->assign_language_to_products();

        // Product categories (product_cat taxonomy).
        $this->assign_language_to_terms( 'product_cat' );

        // Product tags (product_tag taxonomy).
        $this->assign_language_to_terms( 'product_tag' );

        // Product attributes (pa_* taxonomies).
        $this->assign_language_to_attribute_terms();

        // Product brands if the taxonomy exists.
        if ( taxonomy_exists( 'product_brand' ) ) {
            $this->assign_language_to_terms( 'product_brand' );
        }

        // Flush Polylang's internal caches so counts are correct.
        $this->flush_polylang_cache();
    }

    // ─────────────────────────────────────────────────────────────
    //  Public API – Individual item language assignment
    //  Called from api.php webhook handlers
    // ─────────────────────────────────────────────────────────────

    /**
     * Assign default language to a single post (product, variation, or set).
     *
     * Idempotent: skips if the post already has a Polylang language.
     *
     * @param int $post_id The WordPress post ID.
     */
    public function assign_language_to_post( int $post_id ): void {
        if ( ! $this->ensure_initialised() || $post_id <= 0 ) {
            return;
        }

        if ( $this->post_has_language( $post_id ) ) {
            return;
        }

        pll_set_post_language( $post_id, $this->default_lang );
    }

    /**
     * Assign default language to multiple posts at once.
     *
     * Convenience wrapper for product + its variations after create/update.
     *
     * @param int[] $post_ids Array of WordPress post IDs.
     */
    public function assign_language_to_posts( array $post_ids ): void {
        if ( ! $this->ensure_initialised() ) {
            return;
        }

        foreach ( $post_ids as $post_id ) {
            $post_id = (int) $post_id;
            if ( $post_id <= 0 ) {
                continue;
            }

            if ( $this->post_has_language( $post_id ) ) {
                continue;
            }

            pll_set_post_language( $post_id, $this->default_lang );
        }

        $this->flush_polylang_cache();
    }

    /**
     * Assign default language to a single term (category, tag, attribute value, brand).
     *
     * Idempotent: skips if the term already has a Polylang language.
     *
     * @param int $term_id The WordPress term ID.
     */
    public function assign_language_to_term( int $term_id ): void {
        if ( ! $this->ensure_initialised() || $term_id <= 0 ) {
            return;
        }

        if ( $this->term_has_language( $term_id ) ) {
            return;
        }

        pll_set_term_language( $term_id, $this->default_lang );
    }

    /**
     * Assign default language to multiple terms at once.
     *
     * @param int[] $term_ids Array of WordPress term IDs.
     */
    public function assign_language_to_term_ids( array $term_ids ): void {
        if ( ! $this->ensure_initialised() ) {
            return;
        }

        foreach ( $term_ids as $term_id ) {
            $term_id = (int) $term_id;
            if ( $term_id <= 0 ) {
                continue;
            }

            if ( $this->term_has_language( $term_id ) ) {
                continue;
            }

            pll_set_term_language( $term_id, $this->default_lang );
        }

        $this->flush_polylang_cache();
    }

    /**
     * Assign language to a product and all its child variations.
     *
     * This is the primary method to call from product create/update webhooks.
     *
     * @param int   $product_id  The parent product post ID.
     * @param int[] $variant_ids Array of variation post IDs (can be empty for simple products).
     */
    public function assign_language_to_product_with_variants( int $product_id, array $variant_ids = [] ): void {
        if ( ! $this->ensure_initialised() ) {
            return;
        }

        $all_post_ids = array_merge( [ $product_id ], $variant_ids );
        $this->assign_language_to_posts( $all_post_ids );
    }

    // ─────────────────────────────────────────────────────────────
    //  Helpers – Check if language already assigned
    // ─────────────────────────────────────────────────────────────

    /**
     * Check if a post already has a Polylang language assigned.
     *
     * @param int $post_id The post ID.
     *
     * @return bool
     */
    private function post_has_language( int $post_id ): bool {
        if ( ! function_exists( 'pll_get_post_language' ) ) {
            return false;
        }

        $lang = pll_get_post_language( $post_id, 'slug' );

        return ! empty( $lang );
    }

    /**
     * Check if a term already has a Polylang language assigned.
     *
     * @param int $term_id The term ID.
     *
     * @return bool
     */
    private function term_has_language( int $term_id ): bool {
        if ( ! function_exists( 'pll_get_term_language' ) ) {
            return false;
        }

        $lang = pll_get_term_language( $term_id, 'slug' );

        return ! empty( $lang );
    }

    // ─────────────────────────────────────────────────────────────
    //  Posts (products + variations) – Bulk
    // ─────────────────────────────────────────────────────────────

    /**
     * Assign English to every product/variation that has no Polylang language.
     */
    private function assign_language_to_products(): void {
        $post_types = [ 'product', 'product_variation' ];

        foreach ( $post_types as $post_type ) {
            $this->process_posts_in_batches( $post_type );
        }
    }

    /**
     * Process posts of a given type in batches.
     *
     * @param string $post_type The post type to process.
     */
    private function process_posts_in_batches( string $post_type ): void {
        global $wpdb;

        $offset = 0;

        do {
            $post_ids = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT p.ID
                     FROM {$wpdb->posts} p
                     WHERE p.post_type = %s
                       AND p.post_status IN ('publish', 'draft', 'private')
                       AND p.ID NOT IN (
                           SELECT tr.object_id
                           FROM {$wpdb->term_relationships} tr
                           INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
                           WHERE tt.taxonomy = 'language'
                       )
                     ORDER BY p.ID ASC
                     LIMIT %d OFFSET %d",
                    $post_type,
                    $this->batch_size,
                    $offset
                )
            );

            if ( empty( $post_ids ) ) {
                break;
            }

            foreach ( $post_ids as $post_id ) {
                $post_id = (int) $post_id;
                pll_set_post_language( $post_id, $this->default_lang );
            }

            $offset += $this->batch_size;

            if ( function_exists( 'wp_cache_flush' ) ) {
                wp_cache_flush();
            }
            
        } while ( count( $post_ids ) >= $this->batch_size );
    }

    // ─────────────────────────────────────────────────────────────
    //  Terms (categories, tags, brands) – Bulk
    // ─────────────────────────────────────────────────────────────

    /**
     * Assign English to every term in the given taxonomy that has no language.
     *
     * @param string $taxonomy Taxonomy name.
     */
    private function assign_language_to_terms( string $taxonomy ): void {
        global $wpdb;

        $offset = 0;

        do {
            $term_ids = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT t.term_id
                     FROM {$wpdb->terms} t
                     INNER JOIN {$wpdb->term_taxonomy} tt ON t.term_id = tt.term_id
                     WHERE tt.taxonomy = %s
                       AND t.term_id NOT IN (
                           SELECT tr.object_id
                           FROM {$wpdb->term_relationships} tr
                           INNER JOIN {$wpdb->term_taxonomy} tt2 ON tr.term_taxonomy_id = tt2.term_taxonomy_id
                           WHERE tt2.taxonomy = 'term_language'
                       )
                     ORDER BY t.term_id ASC
                     LIMIT %d OFFSET %d",
                    $taxonomy,
                    $this->batch_size,
                    $offset
                )
            );

            if ( empty( $term_ids ) ) {
                break;
            }

            foreach ( $term_ids as $term_id ) {
                $term_id = (int) $term_id;
                pll_set_term_language( $term_id, $this->default_lang );
            }

            $offset += $this->batch_size;

            if ( function_exists( 'wp_cache_flush' ) ) {
                wp_cache_flush();
            }
            
        } while ( count( $term_ids ) >= $this->batch_size );
    }

    // ─────────────────────────────────────────────────────────────
    //  Attribute terms (pa_color, pa_size, etc.) – Bulk
    // ─────────────────────────────────────────────────────────────

    /**
     * Assign English to all product attribute terms (pa_*) that have no language.
     */
    private function assign_language_to_attribute_terms(): void {
        $attribute_taxonomies = wc_get_attribute_taxonomies();

        if ( empty( $attribute_taxonomies ) ) {
            return;
        }

        foreach ( $attribute_taxonomies as $attribute ) {
            $taxonomy = 'pa_' . $attribute->attribute_name;

            if ( taxonomy_exists( $taxonomy ) ) {
                $this->assign_language_to_terms( $taxonomy );
            }
        }
    }

    // ─────────────────────────────────────────────────────────────
    //  Cache management
    // ─────────────────────────────────────────────────────────────

    /**
     * Flush Polylang's cached language data so admin counts are updated.
     */
    private function flush_polylang_cache(): void {
        if ( function_exists( 'PLL' ) && isset( PLL()->model ) ) {
            if ( method_exists( PLL()->model, 'clean_languages_cache' ) ) {
                PLL()->model->clean_languages_cache();
            }
        }

        wp_cache_flush();
    }
}

// ─────────────────────────────────────────────────────────────────
//  Instantiate (safe — constructor only adds hooks)
// ─────────────────────────────────────────────────────────────────
Rental_Polylang_Integration::get_instance();
