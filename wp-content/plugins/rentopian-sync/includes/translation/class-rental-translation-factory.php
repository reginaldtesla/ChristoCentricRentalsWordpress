<?php
/**
 * Translation Service Factory
 *
 * Resolves and returns the configured translation provider instance.
 * Centralises provider selection so consuming code never directly
 * instantiates a specific provider class.
 *
 * @package RentopianSync\Translation
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Rental_Translation_Factory {

    /**
     * Cached instance (singleton per request).
     *
     * @var Rental_Translation_Service|null
     */
    private static ?Rental_Translation_Service $instance = null;

    /**
     * Create or return the configured translation service.
     *
     * @return Rental_Translation_Service
     *
     * @throws RuntimeException If the configured provider is unknown.
     */
    public static function make(): Rental_Translation_Service {
        if ( null !== self::$instance ) {
            return self::$instance;
        }

        $provider = get_option( 'rental_translation_provider', 'deepl' );

        switch ( $provider ) {
            case 'google':
                self::$instance = new Rental_Translation_Google();
                break;
            case 'deepl':
            default:
                self::$instance = new Rental_Translation_DeepL();
                break;
        }

        return self::$instance;
    }

    /**
     * Reset the cached instance (useful in tests).
     */
    public static function reset(): void {
        self::$instance = null;
    }
}
