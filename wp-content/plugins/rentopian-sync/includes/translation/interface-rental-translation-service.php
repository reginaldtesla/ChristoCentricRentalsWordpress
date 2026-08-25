<?php
/**
 * Translation Service Interface
 *
 * Abstraction layer for translation providers (DeepL, Google, etc.).
 * Allows swapping providers without changing consumer code.
 *
 * @package RentopianSync\Translation
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

interface Rental_Translation_Service {

    /**
     * Translate a single string.
     *
     * @param string $text        The text to translate.
     * @param string $target_lang Target language code (e.g. 'fr', 'es', 'de').
     * @param string $source_lang Source language code (default: 'en').
     *
     * @return string Translated text, or original text on failure.
     */
    public function translate( string $text, string $target_lang, string $source_lang = 'en' ): string;

    /**
     * Translate multiple strings in a single API call (batch).
     *
     * @param string[] $texts       Array of texts to translate.
     * @param string   $target_lang Target language code.
     * @param string   $source_lang Source language code (default: 'en').
     *
     * @return string[] Array of translated texts (same order/keys as input).
     */
    public function translate_batch( array $texts, string $target_lang, string $source_lang = 'en' ): array;

    /**
     * Check whether this service is configured and ready to use.
     *
     * @return bool
     */
    public function is_available(): bool;

    /**
     * Get the human-readable name of this provider.
     *
     * @return string
     */
    public function get_provider_name(): string;
}
