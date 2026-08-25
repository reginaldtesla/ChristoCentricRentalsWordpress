<?php
/**
 * Translation Image Propagator
 *
 * Solves the timing problem where:
 *  1. Bulk sync creates products with Rentopian image IDs as _thumbnail_id
 *  2. Translation queue creates French products, copying the Rentopian ID
 *  3. Image sync job later replaces the Rentopian ID with a real WP attachment ID
 *  4. But the French products still have the old Rentopian ID → broken images
 *
 * This class hooks into WordPress `update_post_meta` / `update_term_meta` and
 * whenever _thumbnail_id or _product_image_gallery is updated on a source
 * (default language) post, it propagates the same value to all Polylang
 * translation posts.
 *
 * Also handles:
 *  - Variant thumbnails (_thumbnail_id on product_variation)
 *  - Product galleries (_product_image_gallery)
 *  - Category thumbnails (thumbnail_id term meta)
 *  - Brand thumbnails (thumbnail_id term meta)
 *
 * @package RentopianSync\Translation
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Rental_Translation_Image_Propagator {

    /**
     * Post meta keys to propagate.
     *
     * @var string[]
     */
    private const POST_META_KEYS = [
        '_thumbnail_id',
        '_product_image_gallery',
    ];

    /**
     * Term meta keys to propagate.
     *
     * @var string[]
     */
    private const TERM_META_KEYS = [
        'thumbnail_id',
        'banner_id',
    ];

    /**
     * Log source.
     *
     * @var string
     */
    private const LOG_SOURCE = 'rentopian-translation';

    /**
     * Guard against infinite recursion (our own update triggers the hook again).
     *
     * @var bool
     */
    private static bool $propagating = false;

    /**
     * Wire up hooks.
     */
    public static function init(): void {
        // Hook after post meta is updated.
        add_action( 'updated_post_meta', [ __CLASS__, 'on_post_meta_updated' ], 10, 4 );
        add_action( 'added_post_meta', [ __CLASS__, 'on_post_meta_added' ], 10, 4 );

        // Hook after term meta is updated.
        add_action( 'updated_term_meta', [ __CLASS__, 'on_term_meta_updated' ], 10, 4 );
        add_action( 'added_term_meta', [ __CLASS__, 'on_term_meta_added' ], 10, 4 );
    }

    // ─────────────────────────────────────────────────────────────
    //  Post meta hooks
    // ─────────────────────────────────────────────────────────────

    /**
     * Called when post meta is updated.
     *
     * @param int    $meta_id    Meta row ID.
     * @param int    $post_id    Post ID.
     * @param string $meta_key   Meta key.
     * @param mixed  $meta_value New meta value.
     */
    public static function on_post_meta_updated( $meta_id, $post_id, $meta_key, $meta_value ): void {
        self::maybe_propagate_post_meta( $post_id, $meta_key, $meta_value );
    }

    /**
     * Called when post meta is added.
     *
     * @param int    $meta_id    Meta row ID.
     * @param int    $post_id    Post ID.
     * @param string $meta_key   Meta key.
     * @param mixed  $meta_value Meta value.
     */
    public static function on_post_meta_added( $meta_id, $post_id, $meta_key, $meta_value ): void {
        self::maybe_propagate_post_meta( $post_id, $meta_key, $meta_value );
    }

    /**
     * Check if this post meta change should be propagated to translations.
     *
     * @param int    $post_id    Post ID.
     * @param string $meta_key   Meta key.
     * @param mixed  $meta_value New value.
     */
    private static function maybe_propagate_post_meta( int $post_id, string $meta_key, $meta_value ): void {
        // Guard: only relevant meta keys.
        if ( ! in_array( $meta_key, self::POST_META_KEYS, true ) ) {
            return;
        }

        // Guard: prevent recursion.
        if ( self::$propagating ) {
            return;
        }

        // Guard: Polylang must be active.
        if ( ! function_exists( 'pll_get_post_translations' ) || ! function_exists( 'pll_default_language' ) ) {
            return;
        }

        // Guard: only propagate from default language posts.
        $default_lang = pll_default_language( 'slug' );
        if ( empty( $default_lang ) ) {
            return;
        }

        $post_lang = function_exists( 'pll_get_post_language' )
            ? pll_get_post_language( $post_id, 'slug' )
            : '';

        if ( $post_lang !== $default_lang ) {
            return; // This is a translation post, not the source — skip.
        }

        // Guard: only product-related post types.
        $post_type = get_post_type( $post_id );
        if ( ! in_array( $post_type, [ 'product', 'product_variation' ], true ) ) {
            return;
        }

        // Guard: value must be meaningful (not empty, not a Rentopian UUID-like string).
        if ( $meta_key === '_thumbnail_id' ) {
            $val = (int) $meta_value;
            if ( $val <= 0 ) {
                return; // Empty or invalid.
            }
            // Check it's a real WP attachment (not a Rentopian UUID stored as string).
            if ( get_post_type( $val ) !== 'attachment' ) {
                return; // Not a real attachment yet — image sync hasn't resolved it.
            }
        }

        // Get all translations of this post.
        $translations = pll_get_post_translations( $post_id );

        if ( empty( $translations ) || count( $translations ) <= 1 ) {
            return; // No translations to update.
        }

        self::$propagating = true;

        $propagated_count = 0;

        foreach ( $translations as $lang => $translated_post_id ) {
            if ( $lang === $default_lang ) {
                continue; // Skip the source post itself.
            }

            $translated_post_id = (int) $translated_post_id;
            if ( $translated_post_id <= 0 || $translated_post_id === $post_id ) {
                continue;
            }

            update_post_meta( $translated_post_id, $meta_key, $meta_value );
            $propagated_count++;
        }

        self::$propagating = false;

        if ( $propagated_count > 0 && class_exists( 'Project_WP_Logger', false ) ) {
            Project_WP_Logger::write(
                sprintf(
                    'Image propagated: %s=%s on post #%d (%s) → %d translations.',
                    $meta_key,
                    is_string( $meta_value ) ? substr( $meta_value, 0, 50 ) : (string) $meta_value,
                    $post_id,
                    $post_type,
                    $propagated_count
                ),
                'debug',
                self::LOG_SOURCE
            );
        }
    }

    // ─────────────────────────────────────────────────────────────
    //  Term meta hooks
    // ─────────────────────────────────────────────────────────────

    /**
     * Called when term meta is updated.
     */
    public static function on_term_meta_updated( $meta_id, $term_id, $meta_key, $meta_value ): void {
        self::maybe_propagate_term_meta( (int) $term_id, $meta_key, $meta_value );
    }

    /**
     * Called when term meta is added.
     */
    public static function on_term_meta_added( $meta_id, $term_id, $meta_key, $meta_value ): void {
        self::maybe_propagate_term_meta( (int) $term_id, $meta_key, $meta_value );
    }

    /**
     * Check if this term meta change should be propagated to translations.
     *
     * @param int    $term_id    Term ID.
     * @param string $meta_key   Meta key.
     * @param mixed  $meta_value New value.
     */
    private static function maybe_propagate_term_meta( int $term_id, string $meta_key, $meta_value ): void {
        if ( ! in_array( $meta_key, self::TERM_META_KEYS, true ) ) {
            return;
        }

        if ( self::$propagating ) {
            return;
        }

        if ( ! function_exists( 'pll_get_term_translations' ) || ! function_exists( 'pll_default_language' ) ) {
            return;
        }

        $default_lang = pll_default_language( 'slug' );
        if ( empty( $default_lang ) ) {
            return;
        }

        $term_lang = function_exists( 'pll_get_term_language' )
            ? pll_get_term_language( $term_id, 'slug' )
            : '';

        if ( $term_lang !== $default_lang ) {
            return;
        }

        // Value must be a real attachment ID.
        $val = (int) $meta_value;
        if ( $val <= 0 || get_post_type( $val ) !== 'attachment' ) {
            return;
        }

        $translations = pll_get_term_translations( $term_id );

        if ( empty( $translations ) || count( $translations ) <= 1 ) {
            return;
        }

        self::$propagating = true;

        $propagated_count = 0;

        foreach ( $translations as $lang => $translated_term_id ) {
            if ( $lang === $default_lang ) {
                continue;
            }

            $translated_term_id = (int) $translated_term_id;
            if ( $translated_term_id <= 0 || $translated_term_id === $term_id ) {
                continue;
            }

            update_term_meta( $translated_term_id, $meta_key, $meta_value );
            $propagated_count++;
        }

        self::$propagating = false;

        if ( $propagated_count > 0 && class_exists( 'Project_WP_Logger', false ) ) {
            Project_WP_Logger::write(
                sprintf(
                    'Image propagated: %s=%d on term #%d → %d translations.',
                    $meta_key,
                    $val,
                    $term_id,
                    $propagated_count
                ),
                'debug',
                self::LOG_SOURCE
            );
        }
    }
}
