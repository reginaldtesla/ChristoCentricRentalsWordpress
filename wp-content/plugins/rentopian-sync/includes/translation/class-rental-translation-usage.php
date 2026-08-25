<?php
/**
 * Translation Usage Tracker
 *
 * Fetches real-time usage data from the translation provider's API
 * and provides formatted stats for display in the admin settings.
 *
 * Currently supports DeepL usage endpoint. Google Cloud Translation
 * does not have a simple usage API — usage is tracked via the Google
 * Cloud Console instead.
 *
 * @package RentopianSync\Translation
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Rental_Translation_Usage {

    /**
     * Transient key for caching usage data (avoid hitting API on every page load).
     *
     * @var string
     */
    private const TRANSIENT_KEY = 'rental_translation_usage';

    /**
     * How long to cache usage data (in seconds).
     *
     * @var int
     */
    private const CACHE_TTL = 300; // 5 minutes.

    /**
     * Fetch usage data from the configured provider.
     *
     * Returns cached data if available and not expired.
     *
     * @param bool $force_refresh Skip transient cache and fetch fresh data.
     *
     * @return array|null Usage data array or null on failure.
     *                    DeepL: ['character_count', 'character_limit', 'percent_used',
     *                            'characters_remaining', 'billing_period_start', 'billing_period_end']
     */
    public static function get_usage( bool $force_refresh = false ): ?array {
        if ( ! $force_refresh ) {
            $cached = get_transient( self::TRANSIENT_KEY );
            if ( false !== $cached ) {
                return $cached;
            }
        }

        $provider = get_option( 'rental_translation_provider', 'deepl' );
        $api_key  = get_option( 'rental_translation_api_key', '' );

        if ( empty( $api_key ) ) {
            return null;
        }

        switch ( $provider ) {
            case 'deepl':
                $usage = self::fetch_deepl_usage( $api_key );
                break;
            case 'google':
                $usage = self::fetch_google_usage_placeholder();
                break;
            default:
                $usage = null;
                break;
        }

        if ( null !== $usage ) {
            set_transient( self::TRANSIENT_KEY, $usage, self::CACHE_TTL );
        }

        return $usage;
    }

    /**
     * Clear the cached usage data.
     */
    public static function clear_cache(): void {
        delete_transient( self::TRANSIENT_KEY );
    }

    // ─────────────────────────────────────────────────────────────
    //  DeepL Usage
    // ─────────────────────────────────────────────────────────────

    /**
     * Fetch usage from the DeepL API.
     *
     * Supports both Free and Pro API endpoints.
     * Free keys end in ':fx'.
     *
     * @param string $api_key DeepL API key.
     *
     * @return array|null
     */
    private static function fetch_deepl_usage( string $api_key ): ?array {
        
        $base_url = substr( $api_key, -3 ) === ':fx'
            ? 'https://api-free.deepl.com'
            : 'https://api.deepl.com';

        $response = wp_remote_get(
            $base_url . '/v2/usage',
            [
                'timeout' => 10,
                'headers' => [
                    'Authorization' => 'DeepL-Auth-Key ' . $api_key,
                ],
            ]
        );

        if ( is_wp_error( $response ) ) {
            error_log( '[Rentopian Translation] DeepL usage API error: ' . $response->get_error_message() );
            return null;
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( 200 !== $code ) {
            error_log( sprintf( '[Rentopian Translation] DeepL usage API HTTP %d', $code ) );
            return null;
        }

        $data = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( ! isset( $data['character_count'] ) || ! isset( $data['character_limit'] ) ) {
            return null;
        }

        $count = (int) $data['character_count'];
        $limit = (int) $data['character_limit'];

        // Avoid division by zero. Limit of 1000000000000 means "unlimited" in DeepL.
        $is_unlimited = $limit >= 999999999999;
        $percent_used = $is_unlimited ? 0 : ( $limit > 0 ? round( ( $count / $limit ) * 100, 1 ) : 0 );
        $remaining    = $is_unlimited ? PHP_INT_MAX : max( 0, $limit - $count );

        $result = [
            'provider'             => 'DeepL',
            'character_count'      => $count,
            'character_limit'      => $is_unlimited ? 0 : $limit,
            'is_unlimited'         => $is_unlimited,
            'percent_used'         => $percent_used,
            'characters_remaining' => $remaining,
            'fetched_at'           => current_time( 'mysql' ),
        ];

        // Pro API may include billing period dates.
        if ( ! empty( $data['start_time'] ) ) {
            $result['billing_period_start'] = $data['start_time'];
        }
        if ( ! empty( $data['end_time'] ) ) {
            $result['billing_period_end'] = $data['end_time'];
        }

        // Include per-product breakdown if available (Pro API).
        if ( ! empty( $data['products'] ) && is_array( $data['products'] ) ) {
            $result['products'] = $data['products'];
        }

        return $result;
    }

    // ─────────────────────────────────────────────────────────────
    //  Google Usage (placeholder)
    // ─────────────────────────────────────────────────────────────

    /**
     * Google Cloud Translation does not have a simple usage API.
     * Usage must be tracked via the Google Cloud Console.
     *
     * @return array
     */
    private static function fetch_google_usage_placeholder(): array {
        return [
            'provider'             => 'Google Cloud Translation',
            'character_count'      => null,
            'character_limit'      => null,
            'is_unlimited'         => false,
            'percent_used'         => null,
            'characters_remaining' => null,
            'fetched_at'           => current_time( 'mysql' ),
            'note'                 => __( 'Google Cloud Translation usage is tracked via the Google Cloud Console. Visit console.cloud.google.com to view your quota.', 'rentopian-sync' ),
        ];
    }
}
