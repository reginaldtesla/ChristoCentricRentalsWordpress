<?php
/**
 * Google Cloud Translation Service
 *
 * Implements the Rental_Translation_Service interface using Google Cloud
 * Translation API v2 (Basic).
 *
 * Free tier: 500,000 characters/month.
 * Batch: up to 128 segments per request.
 *
 * @package RentopianSync\Translation
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Rental_Translation_Google implements Rental_Translation_Service {

    /**
     * Google Cloud API key.
     *
     * @var string
     */
    private string $api_key;

    /**
     * API endpoint.
     *
     * @var string
     */
    private string $api_url = 'https://translation.googleapis.com/language/translate/v2';

    /**
     * Maximum texts per batch request (Google limit is 128).
     *
     * @var int
     */
    private int $batch_limit = 100;

    /**
     * In-memory translation cache.
     *
     * @var array<string, string>
     */
    private array $cache = [];

    /**
     * Constructor.
     */
    public function __construct() {
        $this->api_key = get_option( 'rental_translation_api_key', '' );
    }

    /**
     * {@inheritdoc}
     */
    public function translate( string $text, string $target_lang, string $source_lang = 'en' ): string {
        if ( empty( trim( $text ) ) ) {
            return $text;
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

        $to_translate = [];
        foreach ( $texts as $key => $text ) {
            if ( ! empty( trim( $text ) ) ) {
                $cache_key = md5( $source_lang . ':' . $target_lang . ':' . $text );
                if ( isset( $this->cache[ $cache_key ] ) ) {
                    $texts[ $key ] = $this->cache[ $cache_key ];
                    continue;
                }
                $to_translate[ $key ] = $text;
            }
        }

        $chunks = array_chunk( $to_translate, $this->batch_limit, true );

        foreach ( $chunks as $chunk ) {
            $translations = $this->api_request( array_values( $chunk ), $target_lang, $source_lang );

            if ( null === $translations ) {
                continue;
            }

            $chunk_keys = array_keys( $chunk );
            foreach ( $translations as $index => $translated_text ) {
                if ( isset( $chunk_keys[ $index ] ) ) {
                    $original_key  = $chunk_keys[ $index ];
                    $original_text = $chunk[ $original_key ];
                    $cache_key     = md5( $source_lang . ':' . $target_lang . ':' . $original_text );

                    $this->cache[ $cache_key ]  = $translated_text;
                    $texts[ $original_key ]     = $translated_text;
                }
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
        return 'Google Cloud Translation';
    }

    /**
     * Make the Google Translate API request.
     *
     * @param string[] $texts       Indexed array of texts.
     * @param string   $target_lang Target language.
     * @param string   $source_lang Source language.
     *
     * @return string[]|null
     */
    private function api_request( array $texts, string $target_lang, string $source_lang ): ?array {
        $body = [
            'q'      => $texts,
            'target' => $target_lang,
            'source' => $source_lang,
            'format' => 'html',
            'key'    => $this->api_key,
        ];

        $response = wp_remote_post(
            $this->api_url,
            [
                'timeout' => 30,
                'headers' => [ 'Content-Type' => 'application/json' ],
                'body'    => wp_json_encode( $body ),
            ]
        );

        if ( is_wp_error( $response ) ) {
            error_log( '[Rentopian Translation] Google API error: ' . $response->get_error_message() );
            return null;
        }

        $code = wp_remote_retrieve_response_code( $response );

        if ( 200 !== $code ) {
            error_log( sprintf( '[Rentopian Translation] Google API HTTP %d: %s', $code, wp_remote_retrieve_body( $response ) ) );
            return null;
        }

        $data = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( empty( $data['data']['translations'] ) ) {
            return null;
        }

        return array_map(
            static fn( $item ) => html_entity_decode( $item['translatedText'] ?? '', ENT_QUOTES, 'UTF-8' ),
            $data['data']['translations']
        );
    }
}
