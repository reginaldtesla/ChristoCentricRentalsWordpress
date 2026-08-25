<?php
/**
 * Translation Processor
 *
 * Performs the actual translation of a post or term with full logging:
 *
 *  translate_post / translate_term:
 *   1. START  — source ID, type, languages to translate, field count
 *   2. MIDWAY — per-language: cache hits vs API calls, created vs updated
 *   3. END    — translation map saved, total elapsed
 *
 *  translate_with_cache:
 *   - Cache hits count, cache misses count
 *   - API call made (with char count), or skipped (all from cache)
 *   - Cache store success/failure
 *
 * @package RentopianSync\Translation
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Rental_Translation_Processor {

    /**
     * @var Rental_Translation_Service
     */
    private Rental_Translation_Service $translator;

    /**
     * @var Rental_Translation_Cache
     */
    private Rental_Translation_Cache $cache;

    /**
     * @var string
     */
    private string $default_lang = '';

    /**
     * @var string[]
     */
    private array $secondary_languages = [];

    /**
     * @var bool
     */
    private bool $initialised = false;

    /**
     * Log source identifier.
     *
     * @var string
     */
    private const LOG_SOURCE = 'rentopian-translation';

    /**
     * Constructor.
     */
    public function __construct() {
        $this->translator = Rental_Translation_Factory::make();
        $this->cache      = Rental_Translation_Cache::get_instance();
        $this->init();
    }

    /**
     * Initialise Polylang language data.
     */
    private function init(): void {
        if ( ! function_exists( 'pll_default_language' ) || ! function_exists( 'pll_languages_list' ) ) {
            return;
        }

        $this->default_lang = pll_default_language( 'slug' );

        if ( empty( $this->default_lang ) ) {
            return;
        }

        $all = pll_languages_list( [ 'hide_empty' => false ] );

        $this->secondary_languages = array_values( array_diff( $all, [ $this->default_lang ] ) );
        $this->initialised         = ! empty( $this->secondary_languages ) && $this->translator->is_available();
    }

    /**
     * @return bool
     */
    public function is_ready(): bool {
        return $this->initialised;
    }

    // ─────────────────────────────────────────────────────────────
    //  Cache-aware translation (with logging)
    // ─────────────────────────────────────────────────────────────

    /**
     * Translate a batch of texts with DB cache lookup.
     *
     * Logs: cache hits, cache misses, API calls, chars sent, cache stores.
     *
     * @param array  $texts       Associative array: key => source_text.
     * @param string $target_lang Target language (Polylang slug).
     * @param string $context     Human context for logs (e.g. "post #123 → fr").
     *
     * @return array Associative array: key => translated_text.
     */
    private function translate_with_cache( array $texts, string $target_lang, string $context = '' ): array {
        if ( empty( $texts ) ) {
            return $texts;
        }

        $service_lang = $this->map_polylang_to_service_lang( $target_lang );
        $source_lang  = $this->map_polylang_to_service_lang( $this->default_lang );

        // Filter out empty texts.
        $non_empty = array_filter( $texts, fn( $v ) => ! empty( trim( $v ) ) );
        $result    = $texts;

        if ( empty( $non_empty ) ) {
            return $result;
        }

        // Check DB cache.
        $cache_result = $this->cache->get_batch( $non_empty, $target_lang, $this->default_lang );
        $hits         = $cache_result['hits'];
        $misses       = $cache_result['misses'];

        foreach ( $hits as $key => $translated ) {
            $result[ $key ] = $translated;
        }

        $hit_count  = count( $hits );
        $miss_count = count( $misses );

        // Translate only the misses via API.
        if ( ! empty( $misses ) ) {
            $chars_to_translate = array_sum( array_map( fn( $t ) => mb_strlen( $t, 'UTF-8' ), $misses ) );

            Project_WP_Logger::write(
                sprintf(
                    '[%s] Cache: %d hits, %d misses. Calling %s API for %d texts (%d chars).',
                    $context,
                    $hit_count,
                    $miss_count,
                    $this->translator->get_provider_name(),
                    $miss_count,
                    $chars_to_translate
                ),
                'info',
                self::LOG_SOURCE
            );

            $api_token   = Project_WP_Logger::start( 'api_call' );
            $api_results = $this->translator->translate_batch( $misses, $service_lang, $source_lang );

            Project_WP_Logger::stop(
                $api_token,
                'API translate_batch',
                'debug',
                self::LOG_SOURCE,
                0,
                sprintf( '[%s] %d texts sent, %d returned.', $context, $miss_count, count( $api_results ) )
            );

            // Store API results in DB cache.
            $provider     = $this->translator->get_provider_name();
            $cached_count = 0;

            foreach ( $api_results as $key => $translated ) {
                $result[ $key ] = $translated;

                if ( isset( $misses[ $key ] ) && $translated !== $misses[ $key ] ) {
                    $this->cache->set(
                        $misses[ $key ],
                        $translated,
                        $target_lang,
                        $this->default_lang,
                        strtolower( $provider )
                    );
                    $cached_count++;
                }
            }

            Project_WP_Logger::write(
                sprintf( '[%s] Stored %d new translations in DB cache.', $context, $cached_count ),
                'debug',
                self::LOG_SOURCE
            );

        } elseif ( $hit_count > 0 ) {
            Project_WP_Logger::write(
                sprintf( '[%s] All %d texts served from cache. No API call needed.', $context, $hit_count ),
                'debug',
                self::LOG_SOURCE
            );
        }

        return $result;
    }

    // ─────────────────────────────────────────────────────────────
    //  Post Translation (with logging)
    // ─────────────────────────────────────────────────────────────

    /**
     * Translate a post into all secondary languages.
     *
     * Logging:
     *  1. START  — post ID, type, title preview, languages
     *  2. MIDWAY — per-language cache/API decisions, create vs update
     *  3. END    — translation map linked, elapsed time
     *
     * @param int    $source_post_id The source post ID.
     * @param string $post_type      Post type.
     * @param array  $fields         Optional pre-fetched fields.
     */
    public function translate_post( int $source_post_id, string $post_type = 'product', array $fields = [] ): void {
        if ( ! $this->initialised ) {
            return;
        }

        $source_post = get_post( $source_post_id );

        if ( ! $source_post ) {
            Project_WP_Logger::write(
                sprintf( 'Post #%d not found — skipping translation.', $source_post_id ),
                'warning',
                self::LOG_SOURCE
            );
            return;
        }

        // START ─────────────────────────────────────────────
        $post_token  = Project_WP_Logger::start( 'translate_post_' . $source_post_id );
        $title_preview = mb_substr( $source_post->post_title, 0, 50, 'UTF-8' );

        Project_WP_Logger::write(
            sprintf(
                'POST START — #%d (%s) "%s" → languages: [%s]',
                $source_post_id,
                $post_type,
                $title_preview,
                implode( ', ', $this->secondary_languages )
            ),
            'info',
            self::LOG_SOURCE
        );

        // Ensure source has default language.
        $source_lang = function_exists( 'pll_get_post_language' )
            ? pll_get_post_language( $source_post_id, 'slug' )
            : '';

        if ( empty( $source_lang ) ) {
            pll_set_post_language( $source_post_id, $this->default_lang );
            Project_WP_Logger::write(
                sprintf( 'Post #%d had no language — assigned default "%s".', $source_post_id, $this->default_lang ),
                'notice',
                self::LOG_SOURCE
            );
        }

        $existing_translations = function_exists( 'pll_get_post_translations' )
            ? pll_get_post_translations( $source_post_id )
            : [];

        $title   = ! empty( $fields['title'] )   ? $fields['title']   : $source_post->post_title;
        $content = ! empty( $fields['content'] ) ? $fields['content'] : $source_post->post_content;
        $excerpt = ! empty( $fields['excerpt'] ) ? $fields['excerpt'] : $source_post->post_excerpt;

        $translation_map = $existing_translations;
        $translation_map[ $this->default_lang ] = $source_post_id;

        $created_count = 0;
        $updated_count = 0;

        // MIDWAY — Per-language processing ──────────────────
        foreach ( $this->secondary_languages as $lang ) {
            $texts_to_translate = array_filter( [
                'title'   => $title,
                'content' => $content,
                'excerpt' => $excerpt,
            ], fn( $v ) => ! empty( trim( $v ) ) );

            $context    = sprintf( 'post #%d → %s', $source_post_id, $lang );
            $translated = $this->translate_with_cache( $texts_to_translate, $lang, $context );

            if ( isset( $existing_translations[ $lang ] ) && get_post( $existing_translations[ $lang ] ) ) {
                wp_update_post( [
                    'ID'           => $existing_translations[ $lang ],
                    'post_title'   => $translated['title']   ?? $title,
                    'post_content' => $translated['content'] ?? $content,
                    'post_excerpt' => $translated['excerpt'] ?? $excerpt,
                ] );
                $updated_count++;

                Project_WP_Logger::write(
                    sprintf( '[%s] Updated existing translation post #%d.', $context, $existing_translations[ $lang ] ),
                    'debug',
                    self::LOG_SOURCE
                );
                continue;
            }

            $translated_post_id = $this->create_translated_post(
                $source_post,
                $translated['title']   ?? $title,
                $translated['content'] ?? $content,
                $translated['excerpt'] ?? $excerpt,
                $lang
            );

            if ( $translated_post_id ) {
                $translation_map[ $lang ] = $translated_post_id;
                pll_set_post_language( $translated_post_id, $lang );
                $this->copy_post_meta( $source_post_id, $translated_post_id, $post_type );
                $created_count++;

                Project_WP_Logger::write(
                    sprintf( '[%s] Created new translation post #%d.', $context, $translated_post_id ),
                    'debug',
                    self::LOG_SOURCE
                );
            } else {
                Project_WP_Logger::write(
                    sprintf( '[%s] FAILED to create translation post.', $context ),
                    'error',
                    self::LOG_SOURCE
                );
            }
        }

        // END ───────────────────────────────────────────────
        if ( count( $translation_map ) > 1 ) {
            pll_save_post_translations( $translation_map );
        }

        Project_WP_Logger::stop(
            $post_token,
            sprintf( 'translate_post #%d', $source_post_id ),
            'info',
            self::LOG_SOURCE,
            0,
            sprintf(
                'POST END — #%d "%s": created %d, updated %d, total linked: %d languages.',
                $source_post_id,
                $title_preview,
                $created_count,
                $updated_count,
                count( $translation_map )
            )
        );
    }

    /**
     * Create a translated WP post.
     */
    private function create_translated_post(
        WP_Post $source,
        string $translated_title,
        string $translated_content,
        string $translated_excerpt,
        string $lang
    ) {
        $slug = sanitize_title( $translated_title ) . '-' . $lang;

        $post_data = [
            'post_author'    => $source->post_author,
            'post_title'     => $translated_title,
            'post_content'   => $translated_content,
            'post_excerpt'   => $translated_excerpt,
            'post_status'    => $source->post_status,
            'post_type'      => $source->post_type,
            'post_parent'    => $this->get_translated_parent( $source->post_parent, $lang ),
            'post_name'      => $slug,
            'menu_order'     => $source->menu_order,
            'comment_status' => $source->comment_status,
            'ping_status'    => $source->ping_status,
        ];

        $new_post_id = wp_insert_post( $post_data, true );

        if ( is_wp_error( $new_post_id ) ) {
            Project_WP_Logger::write(
                sprintf( 'wp_insert_post failed for "%s" [%s]: %s', $translated_title, $lang, $new_post_id->get_error_message() ),
                'error',
                self::LOG_SOURCE
            );
            return false;
        }

        return $new_post_id;
    }

    /**
     * Resolve translated parent post ID for variations.
     */
    private function get_translated_parent( int $parent_id, string $lang ): int {
        if ( $parent_id <= 0 ) {
            return 0;
        }

        if ( ! function_exists( 'pll_get_post' ) ) {
            return $parent_id;
        }

        return pll_get_post( $parent_id, $lang ) ?: $parent_id;
    }

    /**
     * Copy relevant post meta from source to translated post.
     */
    private function copy_post_meta( int $source_id, int $target_id, string $post_type ): void {
        $skip_keys = [ '_edit_lock', '_edit_last', '_wp_old_slug', '_wp_old_date' ];
        $all_meta  = get_post_meta( $source_id );

        if ( empty( $all_meta ) ) {
            return;
        }

        foreach ( $all_meta as $key => $values ) {
            if ( in_array( $key, $skip_keys, true ) ) {
                continue;
            }
            if ( metadata_exists( 'post', $target_id, $key ) ) {
                continue;
            }
            foreach ( $values as $value ) {
                add_post_meta( $target_id, $key, maybe_unserialize( $value ) );
            }
        }

        $this->copy_term_relationships( $source_id, $target_id );
    }

    /**
     * Copy taxonomy term relationships using translated terms where available.
     */
    private function copy_term_relationships( int $source_id, int $target_id ): void {
        $taxonomies = get_object_taxonomies( get_post_type( $source_id ) );
        $exclude    = [ 'language', 'post_translations' ];

        foreach ( $taxonomies as $taxonomy ) {
            if ( in_array( $taxonomy, $exclude, true ) ) {
                continue;
            }

            $terms = wp_get_object_terms( $source_id, $taxonomy, [ 'fields' => 'ids' ] );

            if ( is_wp_error( $terms ) || empty( $terms ) ) {
                continue;
            }

            $translated_term_ids = [];
            foreach ( $terms as $term_id ) {
                if ( function_exists( 'pll_get_term' ) ) {
                    $translated_term = pll_get_term( $term_id, pll_get_post_language( $target_id, 'slug' ) );
                    $translated_term_ids[] = $translated_term ?: $term_id;
                } else {
                    $translated_term_ids[] = $term_id;
                }
            }

            wp_set_object_terms( $target_id, $translated_term_ids, $taxonomy );
        }
    }

    // ─────────────────────────────────────────────────────────────
    //  Term Translation (with logging)
    // ─────────────────────────────────────────────────────────────

    /**
     * Translate a term into all secondary languages.
     *
     * Logging:
     *  1. START  — term ID, taxonomy, name, languages
     *  2. MIDWAY — per-language cache/API, create vs update
     *  3. END    — translation map linked, elapsed time
     *
     * @param int    $source_term_id The source term ID.
     * @param string $taxonomy       Taxonomy name.
     * @param array  $fields         Optional pre-fetched fields.
     */
    public function translate_term( int $source_term_id, string $taxonomy, array $fields = [] ): void {
        if ( ! $this->initialised ) {
            return;
        }

        $source_term = get_term( $source_term_id, $taxonomy );

        if ( ! $source_term || is_wp_error( $source_term ) ) {
            Project_WP_Logger::write(
                sprintf( 'Term #%d (%s) not found — skipping.', $source_term_id, $taxonomy ),
                'warning',
                self::LOG_SOURCE
            );
            return;
        }

        // START ─────────────────────────────────────────────
        $term_token = Project_WP_Logger::start( 'translate_term_' . $source_term_id );

        Project_WP_Logger::write(
            sprintf(
                'TERM START — #%d (%s) "%s" → languages: [%s]',
                $source_term_id,
                $taxonomy,
                $source_term->name,
                implode( ', ', $this->secondary_languages )
            ),
            'info',
            self::LOG_SOURCE
        );

        $source_lang = function_exists( 'pll_get_term_language' )
            ? pll_get_term_language( $source_term_id, 'slug' )
            : '';

        if ( empty( $source_lang ) ) {
            pll_set_term_language( $source_term_id, $this->default_lang );
            Project_WP_Logger::write(
                sprintf( 'Term #%d had no language — assigned default "%s".', $source_term_id, $this->default_lang ),
                'notice',
                self::LOG_SOURCE
            );
        }

        $existing_translations = function_exists( 'pll_get_term_translations' )
            ? pll_get_term_translations( $source_term_id )
            : [];

        $name        = ! empty( $fields['name'] )        ? $fields['name']        : $source_term->name;
        $description = ! empty( $fields['description'] ) ? $fields['description'] : $source_term->description;

        $translation_map = $existing_translations;
        $translation_map[ $this->default_lang ] = $source_term_id;

        $created_count = 0;
        $updated_count = 0;

        // MIDWAY — Per-language processing ──────────────────
        foreach ( $this->secondary_languages as $lang ) {
            $texts_to_translate = array_filter( [
                'name'        => $name,
                'description' => $description,
            ], fn( $v ) => ! empty( trim( $v ) ) );

            $context    = sprintf( 'term #%d (%s) → %s', $source_term_id, $taxonomy, $lang );
            $translated = $this->translate_with_cache( $texts_to_translate, $lang, $context );

            if ( isset( $existing_translations[ $lang ] ) && get_term( $existing_translations[ $lang ] ) ) {
                wp_update_term( $existing_translations[ $lang ], $taxonomy, [
                    'name'        => $translated['name']        ?? $name,
                    'description' => $translated['description'] ?? $description,
                ] );
                $updated_count++;

                Project_WP_Logger::write(
                    sprintf( '[%s] Updated existing translation term #%d.', $context, $existing_translations[ $lang ] ),
                    'debug',
                    self::LOG_SOURCE
                );
                continue;
            }

            $translated_name = $translated['name'] ?? $name;
            $slug            = sanitize_title( $translated_name ) . '-' . $lang;

            $parent = 0;
            if ( $source_term->parent > 0 && function_exists( 'pll_get_term' ) ) {
                $translated_parent = pll_get_term( $source_term->parent, $lang );
                $parent = $translated_parent ?: $source_term->parent;
            }

            $new_term = wp_insert_term(
                $translated_name,
                $taxonomy,
                [
                    'slug'        => $slug,
                    'description' => $translated['description'] ?? $description,
                    'parent'      => $parent,
                ]
            );

            if ( is_wp_error( $new_term ) ) {
                if ( $new_term->get_error_code() === 'term_exists' ) {
                    $existing_id = $new_term->get_error_data();
                    if ( $existing_id ) {
                        $translation_map[ $lang ] = (int) $existing_id;
                        pll_set_term_language( (int) $existing_id, $lang );

                        Project_WP_Logger::write(
                            sprintf( '[%s] Term already exists as #%d — reused.', $context, $existing_id ),
                            'notice',
                            self::LOG_SOURCE
                        );
                        continue;
                    }
                }

                Project_WP_Logger::write(
                    sprintf( '[%s] FAILED to create term: %s', $context, $new_term->get_error_message() ),
                    'error',
                    self::LOG_SOURCE
                );
                continue;
            }

            $new_term_id = $new_term['term_id'];
            $translation_map[ $lang ] = $new_term_id;
            pll_set_term_language( $new_term_id, $lang );
            $this->copy_term_meta( $source_term_id, $new_term_id );
            $created_count++;

            Project_WP_Logger::write(
                sprintf( '[%s] Created new translation term #%d.', $context, $new_term_id ),
                'debug',
                self::LOG_SOURCE
            );
        }

        // END ───────────────────────────────────────────────
        if ( count( $translation_map ) > 1 ) {
            pll_save_term_translations( $translation_map );
        }

        Project_WP_Logger::stop(
            $term_token,
            sprintf( 'translate_term #%d', $source_term_id ),
            'info',
            self::LOG_SOURCE,
            0,
            sprintf(
                'TERM END — #%d "%s" (%s): created %d, updated %d, total linked: %d languages.',
                $source_term_id,
                $name,
                $taxonomy,
                $created_count,
                $updated_count,
                count( $translation_map )
            )
        );
    }

    /**
     * Copy term meta from source to target.
     */
    private function copy_term_meta( int $source_id, int $target_id ): void {
        $all_meta  = get_term_meta( $source_id );
        $skip_keys = [ 'rental_pretty_slug' ];

        if ( empty( $all_meta ) ) {
            return;
        }

        foreach ( $all_meta as $key => $values ) {
            if ( in_array( $key, $skip_keys, true ) ) {
                continue;
            }
            if ( metadata_exists( 'term', $target_id, $key ) ) {
                continue;
            }
            foreach ( $values as $value ) {
                add_term_meta( $target_id, $key, maybe_unserialize( $value ) );
            }
        }
    }

    // ─────────────────────────────────────────────────────────────
    //  Language code mapping
    // ─────────────────────────────────────────────────────────────

    /**
     * @param string $pll_slug Polylang language slug.
     * @return string Service-compatible language code.
     */
    private function map_polylang_to_service_lang( string $pll_slug ): string {
        $map = [
            'en' => 'EN', 'fr' => 'FR', 'de' => 'DE', 'es' => 'ES',
            'it' => 'IT', 'pt' => 'PT', 'nl' => 'NL', 'pl' => 'PL',
            'ru' => 'RU', 'ja' => 'JA', 'zh' => 'ZH', 'ko' => 'KO',
            'ar' => 'AR', 'tr' => 'TR',
        ];

        $map = apply_filters( 'rental_translation_language_map', $map, $pll_slug );

        return $map[ $pll_slug ] ?? strtoupper( $pll_slug );
    }
}
