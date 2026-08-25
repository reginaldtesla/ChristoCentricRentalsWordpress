<?php
/**
 * Data Sync Payload Composer
 *
 * Converts pull-API rows into the exact webhook payload shapes the
 * `api.php` handlers were written against (see the Laravel side:
 * ProductWebhook / VariantWebhook). The handlers then behave exactly as if
 * a live webhook had fired, which keeps sync output identical to webhook
 * output by construction.
 *
 * Key shape rules replicated from the Laravel webhook builders:
 *  - `divisions` is a JSON STRING like "[1,2]" (product's divisions, not
 *    the pull row's company-wide list).
 *  - `variants` = the product's inventory rows for ONE division;
 *    `all_variants` = rows across all divisions.
 *  - `product_attribute_values` rows carry
 *    {id, slug, title, attribute_id, color, img_id, variant_id, default}.
 *  - `images` is always [] here: file sync owns image work.
 *  - Payloads are passed as slashed JSON strings because every handler
 *    does json_decode(stripslashes($payload)).
 *
 * @package RentopianSync\DataSync
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Rental_Data_Sync_Payload_Composer {

    /**
     * Compose per-division product payloads.
     *
     * @param object $product     Decoded row from `/products`.
     * @param array  $bucket_rows Decoded rows from `/products/variants` for this product.
     * @param array  $maps        Shared maps (attributes, attribute_values, categories_by_product, tags_by_product, sets_tags).
     * @return array division_id => slashed JSON string. Empty when the product has no inventory rows.
     */
    public static function compose_product_payloads( $product, array $bucket_rows, array $maps ) {
        if ( empty( $bucket_rows ) ) {
            return [];
        }

        $product_id = (int) $product->id;

        $divisions        = [];
        $summed_qty       = 0;
        foreach ( $bucket_rows as $row ) {
            $divisions[ (int) $row->division_id ] = 1;
            $summed_qty += (int) ( $row->quantity ?? 0 );
        }
        $division_ids   = array_keys( $divisions );
        $divisions_json = '[' . implode( ',', $division_ids ) . ']';

        $all_variants = [];
        foreach ( $bucket_rows as $row ) {
            $all_variants[] = self::augment_variant_row( $row, $divisions_json, $summed_qty );
        }

        $base = [
            'id'                 => $product_id,
            'name'               => (string) ( $product->name ?? '' ),
            'description'        => (string) ( $product->description ?? '' ),
            'full_description'   => (string) ( $product->full_description ?? '' ),
            'brand_id'           => (int) ( $product->brand_id ?? 0 ),
            'img_id'             => (int) ( $product->img_id ?? 0 ),
            'variant_img_id'     => (int) ( $product->variant_img_id ?? 0 ),
            'is_sale'            => (int) ( $product->is_sale ?? 0 ),
            'is_add_on'          => (int) ( $product->is_add_on ?? 0 ),
            'is_featured'        => (int) ( $product->is_featured ?? 0 ),
            'exempt_waiver'      => (int) ( $product->exempt_waiver ?? 0 ),
            'hidden_from_api'    => (int) ( $product->hidden_from_api ?? 0 ),
            'rental_by_interval' => (int) ( $product->rental_by_interval ?? 0 ),
            'rental_by_slot'     => (int) ( $product->rental_by_slot ?? 0 ),

            'product_attributes'       => self::attribute_rows_for_product( $product, $maps ),
            'product_attribute_values' => self::attribute_value_rows_for_variants( $bucket_rows, $maps ),

            'categories' => self::map_for_product( $maps, 'categories_by_product', $product_id ),
            'tags'       => self::map_for_product( $maps, 'tags_by_product', $product_id ),
            'sets_tags'  => isset( $maps['sets_tags'] ) ? $maps['sets_tags'] : [],

            'images'      => [],
            'add_ons'     => self::decode_json_field( $product->add_ons ?? null, [] ),
            'up_sells'    => self::decode_id_list( $product->up_sells ?? null ),
            'cross_sells' => self::decode_id_list( $product->cross_sells ?? null ),

            'divisions'    => $divisions_json,
            'all_variants' => $all_variants,
        ];

        $payloads = [];
        foreach ( $division_ids as $division_id ) {
            $variants = [];
            foreach ( $all_variants as $row ) {
                if ( (int) $row['division_id'] === (int) $division_id ) {
                    $variants[] = $row;
                }
            }
            if ( empty( $variants ) ) {
                continue;
            }

            $payload                = $base;
            $payload['division_id'] = (int) $division_id;
            $payload['variants']    = $variants;

            $payloads[ (int) $division_id ] = self::encode( $payload );
        }

        return $payloads;
    }

    /**
     * Compose a standalone variant payload (variant/create|update shape).
     *
     * @param object $row         The bucket row for this variant × division.
     * @param object $product     Parent product row.
     * @param array  $bucket_rows All rows of the parent product.
     * @param array  $maps
     * @return string Slashed JSON.
     */
    public static function compose_variant_payload( $row, $product, array $bucket_rows, array $maps ) {
        $divisions  = [];
        $summed_qty = 0;
        foreach ( $bucket_rows as $r ) {
            $divisions[ (int) $r->division_id ] = 1;
            $summed_qty += (int) ( $r->quantity ?? 0 );
        }
        $divisions_json = '[' . implode( ',', array_keys( $divisions ) ) . ']';

        $payload = self::augment_variant_row( $row, $divisions_json, $summed_qty );

        $payload['product_attrs_count'] = count( self::attribute_rows_for_product( $product, $maps ) );
        $payload['images']              = [];
        $payload['attribute_values']    = self::attribute_value_rows_for_variants( [ $row ], $maps );

        $product_variants = [];
        foreach ( $bucket_rows as $r ) {
            $product_variants[] = self::augment_variant_row( $r, $divisions_json, $summed_qty );
        }
        $payload['product_variants'] = $product_variants;

        return self::encode( $payload );
    }

    /**
     * Normalize one pull variant row into the embedded-variant shape.
     *
     * The pull row is already a superset of what the handlers read; only
     * `divisions` and `product_summed_qty` need webhook semantics (the pull
     * `divisions` field lists ALL company divisions, not the product's).
     *
     * @param object $row
     * @param string $divisions_json
     * @param int    $summed_qty
     * @return array
     */
    private static function augment_variant_row( $row, $divisions_json, $summed_qty ) {
        $out = (array) $row;

        $out['divisions']          = $divisions_json;
        $out['product_summed_qty'] = isset( $out['product_summed_qty'] ) && (int) $out['product_summed_qty'] > 0
            ? (int) $out['product_summed_qty']
            : (int) $summed_qty;

        // Defaults for fields the handlers read without isset guards.
        $out += [
            'inventory'         => 1,
            'quantity'          => 0,
            'taxable'           => 0,
            'job_cost'          => 0,
            'rental_price'      => 0,
            'sale_price'        => 0,
            'img_id'            => 0,
            'rental_time_slots' => null,
        ];

        return $out;
    }

    /**
     * Full attribute rows for a product's `attributes` id list.
     *
     * @param object $product
     * @param array  $maps
     * @return array
     */
    private static function attribute_rows_for_product( $product, array $maps ) {
        $ids = self::decode_id_list( $product->attributes ?? null );
        if ( empty( $ids ) || empty( $maps['attributes'] ) ) {
            return [];
        }

        $rows = [];
        foreach ( $ids as $id ) {
            if ( isset( $maps['attributes'][ $id ] ) ) {
                $rows[] = $maps['attributes'][ $id ];
            }
        }
        return $rows;
    }

    /**
     * Attribute-value rows for a list of variant rows, in the
     * getAttributeValuesForProductVariants() shape.
     *
     * @param array $bucket_rows
     * @param array $maps
     * @return array
     */
    private static function attribute_value_rows_for_variants( array $bucket_rows, array $maps ) {
        if ( empty( $maps['attribute_values'] ) ) {
            return [];
        }

        $rows = [];
        foreach ( $bucket_rows as $row ) {
            $value_ids = self::decode_id_list( $row->attribute_values ?? null );
            foreach ( $value_ids as $vid ) {
                if ( ! isset( $maps['attribute_values'][ $vid ] ) ) {
                    continue;
                }
                $value               = (array) $maps['attribute_values'][ $vid ];
                $value['variant_id'] = (int) $row->id;
                $value['default']    = (int) ( $row->default ?? 0 );
                $rows[]              = $value;
            }
        }
        return $rows;
    }

    /**
     * @param array  $maps
     * @param string $key
     * @param int    $product_id
     * @return array id => title map (may be empty).
     */
    private static function map_for_product( array $maps, $key, $product_id ) {
        if ( empty( $maps[ $key ] ) || ! isset( $maps[ $key ][ $product_id ] ) ) {
            return [];
        }
        return (array) $maps[ $key ][ $product_id ];
    }

    /**
     * Decode a JSON field that may arrive as a string, array, or object.
     *
     * @param mixed $raw
     * @param mixed $default
     * @return mixed
     */
    public static function decode_json_field( $raw, $default ) {
        if ( is_array( $raw ) || is_object( $raw ) ) {
            return $raw;
        }
        if ( is_string( $raw ) && $raw !== '' ) {
            $decoded = json_decode( stripslashes( $raw ) );
            if ( $decoded === null ) {
                $decoded = json_decode( $raw );
            }
            if ( $decoded !== null ) {
                return $decoded;
            }
        }
        return $default;
    }

    /**
     * Decode an id list that may be JSON or a bare "[A,B]" token list
     * (the `SKUs`-style fields are not valid JSON).
     *
     * @param mixed $raw
     * @return int[]
     */
    public static function decode_id_list( $raw ) {
        if ( is_array( $raw ) ) {
            return array_values( array_filter( array_map( 'intval', $raw ) ) );
        }
        if ( ! is_string( $raw ) || $raw === '' ) {
            return [];
        }

        $decoded = json_decode( stripslashes( $raw ), true );
        if ( ! is_array( $decoded ) ) {
            $decoded = json_decode( $raw, true );
        }
        if ( is_array( $decoded ) ) {
            return array_values( array_filter( array_map( 'intval', $decoded ) ) );
        }

        // Bare token list fallback: strip brackets, split on comma.
        $trimmed = trim( $raw, "[] \t\n\r" );
        if ( $trimmed === '' ) {
            return [];
        }
        return array_values( array_filter( array_map( 'intval', explode( ',', $trimmed ) ) ) );
    }

    /**
     * Encode a payload the way handlers expect: slashed JSON, so
     * json_decode(stripslashes($payload)) round-trips exactly.
     *
     * @param mixed $payload
     * @return string
     */
    public static function encode( $payload ) {
        return wp_slash( wp_json_encode( $payload ) );
    }
}
