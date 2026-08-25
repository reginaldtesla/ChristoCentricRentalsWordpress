<?php
/**
 * Image Performance Filters
 *
 * Hooks into WordPress image processing pipeline to cap dimensions,
 * trim heavy sizes, and convert PNG → JPEG during sync downloads.
 *
 *
 * @package RentopianSync\FileSync
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Rental_Image_Performance_Filter {

    /**
     * File currently being processed, so the format decision can look at the
     * real source rather than at whatever path the editor reports.
     *
     * @var string
     */
    private static $source = '';

    /**
     * Enable performance filters before generating subsizes.
     *
     * @param string $source Image being processed, when the caller knows it.
     */
    public static function enable( $source = '' ) {
        self::$source = (string) $source;

        add_filter( 'big_image_size_threshold', [ __CLASS__, 'cap_big_image' ], 10, 1 );
        add_filter( 'intermediate_image_sizes_advanced', [ __CLASS__, 'trim_sizes' ], 10, 2 );
        add_filter( 'image_editor_output_format', [ __CLASS__, 'output_format' ], 10, 3 );
    }

    /**
     * Remove the filters after subsizes are generated.
     */
    public static function disable() {
        self::$source = '';

        remove_filter( 'big_image_size_threshold', [ __CLASS__, 'cap_big_image' ], 10 );
        remove_filter( 'intermediate_image_sizes_advanced', [ __CLASS__, 'trim_sizes' ], 10 );
        remove_filter( 'image_editor_output_format', [ __CLASS__, 'output_format' ], 10 );
    }

    /**
     * Cap originals to 2560px max dimension.
     *
     * @param int $pixels
     * @return int
     */
    public static function cap_big_image( $pixels ) {
        return 2560;
    }

    /**
     * Remove the heaviest registered sizes to speed up sync.
     *
     * @param array $sizes
     * @param array $metadata
     * @return array
     */
    public static function trim_sizes( $sizes, $metadata ) {
        unset( $sizes['2048x2048'], $sizes['1536x1536'], $sizes['large'] );
        return $sizes;
    }

    /**
     * Convert PNG → JPEG, unless the source carries transparency.
     *
     * WordPress does NOT check for an alpha channel here — it applies the
     * mapping to every PNG — and GD paints transparent pixels black. A dark
     * logo or icon on a transparent background therefore arrives as a solid
     * black rectangle, and the readable original is left on disk unused.
     *
     * The check runs against the file the caller is processing, because the
     * `$filename` this filter receives is the *destination* and is null when
     * the editor overwrites in place.
     *
     * @param array  $formats
     * @param string $filename  Destination path, when the editor has one.
     * @param string $mime_type Source mime type, when the editor passes it.
     * @return array
     */
    public static function output_format( $formats, $filename = '', $mime_type = '' ) {
        $source = self::$source ? self::$source : $filename;

        if ( self::has_alpha( $source ) ) {
            return $formats;
        }

        $formats['image/png'] = 'image/jpeg';

        return $formats;
    }

    /**
     * Whether a PNG has transparency.
     *
     * Anything that cannot be read as an opaque PNG is reported as
     * transparent, so an unreadable or unrecognised file is left alone rather
     * than flattened.
     *
     * @param string $file
     * @return bool
     */
    private static function has_alpha( $file ) {
        if ( ! $file || ! is_readable( $file ) ) {
            return true;
        }

        // The colour type sits in the IHDR, right at the start; a palette's
        // transparency chunk follows the palette, well before the pixel data.
        $header = file_get_contents( $file, false, null, 0, 131072 );

        if ( false === $header || strlen( $header ) < 26 ) {
            return true;
        }

        if ( "\x89PNG" !== substr( $header, 0, 4 ) ) {
            // Not a PNG at all: the mapping does not apply to it anyway.
            return false;
        }

        $colour_type = ord( $header[25] );

        // 4 = greyscale + alpha, 6 = truecolour + alpha.
        if ( 4 === $colour_type || 6 === $colour_type ) {
            return true;
        }

        // 3 = palette, whose transparency lives in a tRNS chunk.
        return 3 === $colour_type && false !== strpos( $header, 'tRNS' );
    }
}
