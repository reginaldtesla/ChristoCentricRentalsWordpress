<?php
/**
 * DeepL Translation Service
 *
 * Implements the Rental_Translation_Service interface using the DeepL API.
 * Supports both Free and Pro API endpoints.
 *
 * Free tier: 500,000 characters/month.
 * Batch endpoint: up to 50 texts per request.
 *
 * @package RentopianSync\Translation
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Rental_Translation_DeepL implements Rental_Translation_Service {

    /**
     * DeepL API key.
     *
     * @var string
     */
    private string $api_key;

    /**
     * DeepL API base URL.
     *
     * Free keys end in ':fx' and use the free endpoint.
     *
     * @var string
     */
    private string $api_url;

    /**
     * Maximum texts per batch request (DeepL limit is 50).
     *
     * @var int
     */
    private int $batch_limit = 50;

    /**
     * In-memory translation cache to avoid duplicate API calls within one request.
     *
     * @var array<string, string>
     */
    private array $cache = [];

    /**
     * Constructor.
     */
    public function __construct() {
        $this->api_key = get_option( 'rental_translation_api_key', '' );
        $this->api_url = $this->resolve_api_url();
    }

    /**
     * {@inheritdoc}
     */
    public function translate( string $text, string $target_lang, string $source_lang = 'en' ): string {
        if ( empty( trim( $text ) ) ) {
            return $text;
        }

        $cache_key = $this->build_cache_key( $text, $target_lang, $source_lang );

        if ( isset( $this->cache[ $cache_key ] ) ) {
            return $this->cache[ $cache_key ];
        }

        $result = $this->translate_batch( [ $text ], $target_lang, $source_lang );

        return $result[0] ?? $text;
    }

    /**
     * {@inheritdoc}
     */
    public function translate_batch( array $texts, string $target_lang, string $source_lang = 'en' ): array {
        if ( ! $this->is_available() || empty( $texts ) ) {
            return $texts;
        }

        // Filter out empty strings but preserve keys.
        $to_translate = [];
        foreach ( $texts as $key => $text ) {
            if ( ! empty( trim( $text ) ) ) {
                $cache_key = $this->build_cache_key( $text, $target_lang, $source_lang );
                if ( isset( $this->cache[ $cache_key ] ) ) {
                    continue; // Already cached.
                }
                $to_translate[ $key ] = $text;
            }
        }

        // Process in chunks of batch_limit.
        $chunks = array_chunk( $to_translate, $this->batch_limit, true );

        foreach ( $chunks as $chunk ) {
            $translations = $this->api_request( array_values( $chunk ), $target_lang, $source_lang );

            if ( null === $translations ) {
                continue; // API failure — originals will be returned.
            }

            $chunk_keys = array_keys( $chunk );
            foreach ( $translations as $index => $translated_text ) {
                if ( isset( $chunk_keys[ $index ] ) ) {
                    $original_key   = $chunk_keys[ $index ];
                    $original_text  = $chunk[ $original_key ];
                    $cache_key      = $this->build_cache_key( $original_text, $target_lang, $source_lang );

                    $this->cache[ $cache_key ]   = $translated_text;
                    $texts[ $original_key ]      = $translated_text;
                }
            }
        }

        // Fill any remaining cached entries.
        foreach ( $texts as $key => $text ) {
            $cache_key = $this->build_cache_key( $text, $target_lang, $source_lang );
            if ( isset( $this->cache[ $cache_key ] ) ) {
                $texts[ $key ] = $this->cache[ $cache_key ];
            }
        }

        return $texts;
    }

    /**
     * {@inheritdoc}
     */
    public function is_available(): bool {
        return ! empty( $this->api_key );
    }

    /**
     * {@inheritdoc}
     */
    public function get_provider_name(): string {
        return 'DeepL';
    }

    // ─────────────────────────────────────────────────────────────
    //  Private helpers
    // ─────────────────────────────────────────────────────────────

    /**
     * Make the actual DeepL API request.
     *
     * @param string[] $texts       Indexed array of texts.
     * @param string   $target_lang Target language.
     * @param string   $source_lang Source language.
     *
     * @return string[]|null Array of translated texts or null on failure.
     */
    private function api_request( array $texts, string $target_lang, string $source_lang ): ?array {
        $body = [
            'text'        => $texts,
            'target_lang' => strtoupper( $target_lang ),
            'source_lang' => strtoupper( $source_lang ),
            'tag_handling' => 'html',
        ];

        $response = wp_remote_post(
            $this->api_url . '/v2/translate',
            [
                'timeout' => 30,
                'headers' => [
                    'Authorization' => 'DeepL-Auth-Key ' . $this->api_key,
                    'Content-Type'  => 'application/json',
                ],
                'body'    => wp_json_encode( $body ),
            ]
        );

        if ( is_wp_error( $response ) ) {
            $this->log_error( 'DeepL API request failed: ' . $response->get_error_message() );
            return null;
        }

        $code = wp_remote_retrieve_response_code( $response );

        if ( 200 !== $code ) {
            $this->log_error( sprintf( 'DeepL API returned HTTP %d: %s', $code, wp_remote_retrieve_body( $response ) ) );
            return null;
        }

        $data = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( empty( $data['translations'] ) || ! is_array( $data['translations'] ) ) {
            $this->log_error( 'DeepL API returned unexpected response structure.' );
            return null;
        }

        return array_map(
            static fn( $item ) => $item['text'] ?? '',
            $data['translations']
        );
    }

    /**
     * Determine the correct API URL based on key type.
     *
     * @return string
     */
    private function resolve_api_url(): string {
        // DeepL free keys end with ':fx'.
        if ( substr( $this->api_key, -3 ) === ':fx' ) {
            return 'https://api-free.deepl.com';
        }

        return 'https://api.deepl.com';
    }

    /**
     * Build a cache key for a given text + language pair.
     *
     * @param string $text        Original text.
     * @param string $target_lang Target language.
     * @param string $source_lang Source language.
     *
     * @return string
     */
    private function build_cache_key( string $text, string $target_lang, string $source_lang ): string {
        return md5( $source_lang . ':' . $target_lang . ':' . $text );
    }

    /**
     * Log a translation error.
     *
     * @param string $message Error message.
     */
    private function log_error( string $message ): void {
        if ( class_exists( 'ErrorHandler' ) ) {
            ErrorHandler::registerErrorInLog(
                $message,
                __FILE__,
                __LINE__,
                'translation_error',
                null,
                '500'
            );
        } else {
            error_log( '[Rentopian Translation] ' . $message );
        }
    }
}
