<?php
/**
 * Image Subsizer
 *
 * Generates WordPress image sub-sizes (thumbnails) with a custom GD fallback
 * when wp_create_image_subsizes() fails. Also provides the "metadata with
 * fallback" wrapper that ensures every attachment has at least minimal meta.
 *
 * @package RentopianSync\FileSync
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Rental_Image_Subsizer {

    /* ──────────────────────────────────────────────────────────
     * Public API
     * ────────────────────────────────────────────────────────── */

    /**
     * Generate attachment metadata with fallback to our custom GD subsizer.
     *
     * This wraps the entire subsizing pipeline:
     *  1. Try custom subsizer (rental_make_image_subsizes_2 logic).
     *  2. If that fails, fall back to wp_generate_attachment_metadata.
     *  3. If that also fails, register minimal meta (width/height/file).
     *
     * @param int    $attachment_id
     * @param string $file           Absolute path to the uploaded file.
     * @param bool   $use_custom     Whether to use custom subsizer first (default true).
     * @return mixed Updated metadata or false.
     */
    public static function update_metadata_with_fallback( $attachment_id, $file, $use_custom = true ) {

        if ( $use_custom ) {
            $attach_data = self::make_subsizes( $file, $attachment_id );
            self::log( "make_subsizes done for attach_id={$attachment_id}" );
        } else {
            $attach_data = wp_generate_attachment_metadata( $attachment_id, $file );

            $needs_fallback = is_wp_error( $attach_data )
                || empty( $attach_data )
                || ( is_array( $attach_data ) && empty( $attach_data['sizes'] ) );

            if ( $needs_fallback ) {
                $attach_data = self::make_subsizes( $file, $attachment_id );
            }
        }

        if ( ! is_wp_error( $attach_data ) && ! empty( $attach_data ) ) {
            $updated = wp_update_attachment_metadata( $attachment_id, $attach_data );
            if ( $updated ) {
                self::log( "attachment_metadata_updated for attach_id={$attachment_id}" );
                return $updated;
            }
        }

        // Last resort: register minimal meta so the attachment isn't "naked"
        return self::register_minimal_meta( $attachment_id, $file );
    }

    /**
     * Primary subsizer (replaces rental_make_image_subsizes_2).
     *
     * 1. Read original dimensions.
     * 2. Optionally scale down via big_image_size_threshold.
     * 3. Try wp_create_image_subsizes (core).
     * 4. Fall back to manual GD.
     *
     * @param string $file
     * @param int    $attachment_id
     * @return array|WP_Error
     */
    public static function make_subsizes( $file, $attachment_id ) {

        $imagesize = function_exists( 'wp_getimagesize' )
            ? wp_getimagesize( $file )
            : @getimagesize( $file );

        if ( ! $imagesize || empty( $imagesize[0] ) || empty( $imagesize[1] ) ) {
            return new WP_Error( 'rental_subsizes_no_image', 'Could not read image size for subsizes.' );
        }

        $image_meta = [
            'width'    => (int) $imagesize[0],
            'height'   => (int) $imagesize[1],
            'file'     => _wp_relative_upload_path( $file ),
            'filesize' => function_exists( 'wp_filesize' ) ? wp_filesize( $file ) : @filesize( $file ),
            'sizes'    => [],
        ];

        // ── Big image threshold ──────────────────────────────
        $threshold = apply_filters( 'big_image_size_threshold', 2560 );
        if ( $threshold && max( $image_meta['width'], $image_meta['height'] ) > $threshold ) {
            $file       = self::scale_to_threshold( $file, $threshold, $image_meta );
            // $image_meta is modified by reference inside scale_to_threshold
        }

        // ── Core subsizer ────────────────────────────────────
        // Core signature is ( $file, $attachment_id ); it derives its own
        // image meta from the file. The local $image_meta below is only for
        // the manual GD fallback.
        if ( function_exists( 'wp_create_image_subsizes' ) ) {
            $generated = wp_create_image_subsizes( $file, $attachment_id );
            if ( ! is_wp_error( $generated ) && ! empty( $generated['sizes'] ) ) {
                return $generated;
            }
        }

        // ── Manual GD fallback ───────────────────────────────
        $new_sizes = wp_get_registered_image_subsizes();
        $new_sizes = apply_filters( 'intermediate_image_sizes_advanced', $new_sizes, $image_meta, $attachment_id );

        $result = self::resize_multiple_gd( $file, $new_sizes, $image_meta );

        return $result ?: $image_meta;
    }

    /* ──────────────────────────────────────────────────────────
     * GD-based resizer
     * ────────────────────────────────────────────────────────── */

    /**
     * Generate multiple sub-sizes using GD (JPEG / PNG / GIF).
     *
     * Replaces both rental_resize_image_multiple_gd() and resizeImageMultiple().
     *
     * @param string $filename Absolute path to source image.
     * @param array  $sizes    Registered WP sizes.
     * @param array  $image_meta  Metadata array to populate.
     * @return array Updated $image_meta.
     */
    public static function resize_multiple_gd( $filename, $sizes, $image_meta ) {

        $info = @getimagesize( $filename );
        if ( ! $info ) {
            return $image_meta;
        }

        list( $origW, $origH, $type ) = $info;

        $supported = [ IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF ];
        if ( ! in_array( $type, $supported, true ) ) {
            return $image_meta;
        }

        $src = self::create_gd_resource( $filename, $type );
        if ( ! $src ) {
            return $image_meta;
        }

        $path = pathinfo( $filename );
        $base = $path['filename'];
        $ext  = strtolower( $path['extension'] );
        $mime = image_type_to_mime_type( $type );

        foreach ( $sizes as $name => $sz ) {
            $tW   = max( 0, (int) ( $sz['width'] ?? 0 ) );
            $tH   = max( 0, (int) ( $sz['height'] ?? 0 ) );
            $crop = $sz['crop'] ?? false;

            if ( $tW === 0 && $tH === 0 ) {
                continue;
            }

            $box = self::calc_crop_box( $origW, $origH, $tW, $tH, $crop );
            if ( ! $box ) {
                continue;
            }

            list( $srcX, $srcY, $srcW, $srcH, $dstW, $dstH ) = $box;

            $dst = imagecreatetruecolor( $dstW, $dstH );
            self::preserve_transparency( $dst, $src, $type );

            @imagecopyresampled( $dst, $src, 0, 0, $srcX, $srcY, $dstW, $dstH, $srcW, $srcH );

            $new_filename = "{$base}-{$dstW}x{$dstH}.{$ext}";
            $out          = $path['dirname'] . DIRECTORY_SEPARATOR . $new_filename;

            self::save_gd_image( $dst, $out, $type );

            $image_meta['sizes'][ $name ] = [
                'file'      => $new_filename,
                'width'     => $dstW,
                'height'    => $dstH,
                'mime-type' => $mime,
                'filesize'  => @filesize( $out ),
            ];

            imagedestroy( $dst );
        }

        imagedestroy( $src );
        return $image_meta;
    }

    /**
     * Compute a center (or positioned) crop box without distortion.
     *
     * @param int       $origW
     * @param int       $origH
     * @param int       $tW     Target width (0 = auto).
     * @param int       $tH     Target height (0 = auto).
     * @param bool|array $crop  WP crop parameter.
     * @return array|null [srcX, srcY, srcW, srcH, dstW, dstH]
     */
    public static function calc_crop_box( $origW, $origH, $tW, $tH, $crop ) {
        // One dimension missing → proportional scale
        if ( $tW === 0 && $tH > 0 ) {
            $ratio = $tH / $origH;
            return [ 0, 0, $origW, $origH, (int) round( $origW * $ratio ), $tH ];
        }
        if ( $tH === 0 && $tW > 0 ) {
            $ratio = $tW / $origW;
            return [ 0, 0, $origW, $origH, $tW, (int) round( $origH * $ratio ) ];
        }

        // Both provided — with crop
        if ( $crop ) {
            $targetAR = $tW / $tH;
            $origAR   = $origW / $origH;

            if ( $origAR > $targetAR ) {
                $srcH = $origH;
                $srcW = (int) round( $srcH * $targetAR );
            } else {
                $srcW = $origW;
                $srcH = (int) round( $srcW / $targetAR );
            }

            $hpos = 'center';
            $vpos = 'center';
            if ( is_array( $crop ) && ! empty( $crop ) ) {
                $hpos = isset( $crop[0] ) ? $crop[0] : 'center';
                $vpos = isset( $crop[1] ) ? $crop[1] : 'center';
            }

            switch ( $hpos ) {
                case 'left':  $srcX = 0; break;
                case 'right': $srcX = $origW - $srcW; break;
                default:      $srcX = (int) floor( ( $origW - $srcW ) / 2 );
            }
            switch ( $vpos ) {
                case 'top':    $srcY = 0; break;
                case 'bottom': $srcY = $origH - $srcH; break;
                default:       $srcY = (int) floor( ( $origH - $srcH ) / 2 );
            }

            return [ $srcX, $srcY, $srcW, $srcH, $tW, $tH ];
        }

        // No crop: scale to fit box
        $ratio = min( $tW / $origW, $tH / $origH );
        $dstW  = (int) floor( $origW * $ratio );
        $dstH  = (int) floor( $origH * $ratio );
        return [ 0, 0, $origW, $origH, $dstW, $dstH ];
    }

    /* ──────────────────────────────────────────────────────────
     * Private helpers
     * ────────────────────────────────────────────────────────── */

    /**
     * Scale source image to threshold via WP_Image_Editor.
     * Modifies $image_meta by reference.
     *
     * @param string $file
     * @param int    $threshold
     * @param array  &$image_meta
     * @return string Path to scaled file (or original on failure).
     */
    private static function scale_to_threshold( $file, $threshold, &$image_meta ) {
        $editor = wp_get_image_editor( $file );
        if ( is_wp_error( $editor ) ) {
            return $file;
        }

        $editor->resize( $threshold, $threshold, false );
        $scaled_path = $editor->generate_filename( 'scaled' );
        $saved       = $editor->save( $scaled_path );

        if ( ! is_wp_error( $saved ) && ! empty( $saved['path'] ) ) {
            $image_meta['file']           = _wp_relative_upload_path( $saved['path'] );
            $image_meta['width']          = (int) $saved['width'];
            $image_meta['height']         = (int) $saved['height'];
            $image_meta['filesize']       = (int) ( $saved['filesize'] ?? @filesize( $saved['path'] ) );
            $image_meta['original_image'] = basename( $image_meta['file'] );

            return $saved['path'];
        }

        return $file;
    }

    /**
     * Create a GD resource from file.
     *
     * @param string $filename
     * @param int    $type IMAGETYPE_*
     * @return resource|GdImage|false
     */
    private static function create_gd_resource( $filename, $type ) {
        switch ( $type ) {
            case IMAGETYPE_JPEG: return @imagecreatefromjpeg( $filename );
            case IMAGETYPE_PNG:  return @imagecreatefrompng( $filename );
            case IMAGETYPE_GIF:  return @imagecreatefromgif( $filename );
        }
        return false;
    }

    /**
     * Preserve transparency on destination GD image.
     */
    private static function preserve_transparency( $dst, $src, $type ) {
        if ( $type === IMAGETYPE_PNG ) {
            imagealphablending( $dst, false );
            imagesavealpha( $dst, true );
        } elseif ( $type === IMAGETYPE_GIF ) {
            $index = imagecolortransparent( $src );
            if ( $index >= 0 ) {
                $c     = imagecolorsforindex( $src, $index );
                $index = imagecolorallocate( $dst, $c['red'], $c['green'], $c['blue'] );
                imagefill( $dst, 0, 0, $index );
                imagecolortransparent( $dst, $index );
            }
        }
    }

    /**
     * Save GD image to disk.
     */
    private static function save_gd_image( $image, $path, $type ) {
        switch ( $type ) {
            case IMAGETYPE_JPEG:
                $quality = has_filter( 'jpeg_quality' )
                    ? (int) apply_filters( 'jpeg_quality', 82, 'image_resize' )
                    : 90;
                imagejpeg( $image, $path, $quality );
                break;
            case IMAGETYPE_PNG:
                $level = (int) apply_filters( 'wp_image_editors_png_compression_level', 3 );
                imagepng( $image, $path, $level );
                break;
            case IMAGETYPE_GIF:
                imagegif( $image, $path );
                break;
        }
    }

    /**
     * Register minimal meta so attachment isn't completely "naked".
     *
     * @param int    $attachment_id
     * @param string $file
     * @return mixed
     */
    private static function register_minimal_meta( $attachment_id, $file ) {
        $imagesize = function_exists( 'wp_getimagesize' )
            ? wp_getimagesize( $file )
            : @getimagesize( $file );

        if ( ! empty( $imagesize[0] ) && ! empty( $imagesize[1] ) ) {
            $minimal = [
                'width'  => (int) $imagesize[0],
                'height' => (int) $imagesize[1],
                'file'   => _wp_relative_upload_path( $file ),
                'sizes'  => [],
            ];

            $updated = wp_update_attachment_metadata( $attachment_id, $minimal );
            if ( $updated ) {
                self::log( "minimal metadata registered for attach_id={$attachment_id}" );
                return $updated;
            }
        }

        return false;
    }

    /**
     * @param string $message
     */
    private static function log( $message, $level = 'info' ) {
        Rental_File_Sync_Logger::write( $message, $level, 'subsize' );
    }
}
