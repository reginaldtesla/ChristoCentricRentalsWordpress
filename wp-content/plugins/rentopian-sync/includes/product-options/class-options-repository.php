<?php
/**
 * Rental_Options_Repository
 *
 * Reads option definitions and hands back one normalized shape.
 *
 * `get_product_options()` / `get_set_options()` return whatever the sync wrote
 * into the options table, JSON-decoded and otherwise untouched: ids can be
 * strings, flags can be "1", and the value list can be missing. Normalizing
 * once here means the defaults resolver, the validator and the renderer all
 * reason about the same data.
 *
 * Definitions are memoized per request — the add-to-cart path alone would
 * otherwise re-query them several times.
 *
 * @package RentopianSync\ProductOptions
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'Rental_Options_Repository', false ) ) :

class Rental_Options_Repository {

    /**
     * @var array<string,array> Memoized definitions keyed "{product_id}:{is_set}".
     */
    protected static $cache = array();

    /**
     * Whether the plugin is currently rendering option selectors.
     *
     * The option markup and the legacy validator only register while the sync
     * is on and the API key has been accepted. Gating the module on the same
     * conditions keeps it from demanding a choice for selectors that were
     * never drawn — the asymmetry the previous gate suffered from.
     *
     * The cached key-validity option is read directly rather than through
     * rental_check_api_key(), which falls back to an HTTP call and has no
     * business running inside add-to-cart validation.
     *
     * @return bool
     */
    public static function module_is_active() {
        return (bool) get_option( 'rental_synchronize_status' )
            && (bool) get_option( 'rental_api_key_is_valid', 0 );
    }

    /**
     * Whether a product is a set.
     *
     * A variation carries no set flag of its own, so the parent is consulted.
     *
     * @param int $product_id
     * @param int $parent_id Optional parent product id for a variation.
     * @return bool
     */
    public static function is_set( $product_id, $parent_id = 0 ) {
        if ( get_post_meta( (int) $product_id, '_rental_is_set', true ) ) {
            return true;
        }
        if ( $parent_id && get_post_meta( (int) $parent_id, '_rental_is_set', true ) ) {
            return true;
        }
        return false;
    }

    /**
     * Normalized option definitions for a product or set.
     *
     * @param int  $product_id
     * @param bool $is_set
     * @return array List of options keyed by position, each with int `id`.
     */
    public static function get( $product_id, $is_set = false ) {
        $product_id = (int) $product_id;
        if ( ! $product_id ) {
            return array();
        }

        $key = $product_id . ':' . ( $is_set ? 1 : 0 );
        if ( isset( self::$cache[ $key ] ) ) {
            return self::$cache[ $key ];
        }

        $raw = array();
        if ( $is_set ) {
            if ( function_exists( 'get_set_options' ) ) {
                $raw = get_set_options( $product_id );
            }
        } elseif ( function_exists( 'get_product_options' ) ) {
            $raw = get_product_options( $product_id );
        }

        self::$cache[ $key ] = self::normalize( $raw );

        return self::$cache[ $key ];
    }

    /**
     * Same as get(), keyed by option id.
     *
     * @param int  $product_id
     * @param bool $is_set
     * @return array<int,array>
     */
    public static function get_by_id( $product_id, $is_set = false ) {
        $out = array();
        foreach ( self::get( $product_id, $is_set ) as $option ) {
            $out[ (int) $option['id'] ] = $option;
        }
        return $out;
    }

    /**
     * A single value definition inside an option.
     *
     * @param array $option
     * @param int   $value_id
     * @return array|null
     */
    public static function find_value( $option, $value_id ) {
        $value_id = (int) $value_id;
        if ( empty( $option['option_values'] ) || ! is_array( $option['option_values'] ) ) {
            return null;
        }
        foreach ( $option['option_values'] as $value ) {
            if ( isset( $value['id'] ) && (int) $value['id'] === $value_id ) {
                return $value;
            }
        }
        return null;
    }

    /**
     * Whether a value id is a real choice for an option.
     *
     * @param array $option
     * @param int   $value_id
     * @return bool
     */
    public static function is_valid_value( $option, $value_id ) {
        $value_id = (int) $value_id;
        if ( $value_id === Rental_Options_Defaults::PLACEHOLDER_VALUE_ID || $value_id <= 0 ) {
            return false;
        }
        return self::find_value( $option, $value_id ) !== null;
    }

    /**
     * Coerce raw definitions into the shape the rest of the module expects.
     *
     * Drops options with no usable id, casts ids to int, guarantees
     * `option_values` is a list, and strips any stored placeholder so the
     * placeholder exists only where the renderer puts it.
     *
     * @param mixed $raw
     * @return array
     */
    protected static function normalize( $raw ) {
        if ( empty( $raw ) || ! is_array( $raw ) ) {
            return array();
        }

        $out = array();
        foreach ( $raw as $option ) {
            if ( ! is_array( $option ) || empty( $option['id'] ) ) {
                continue;
            }

            $values = array();
            if ( ! empty( $option['option_values'] ) && is_array( $option['option_values'] ) ) {
                foreach ( $option['option_values'] as $value ) {
                    if ( ! is_array( $value ) || ! isset( $value['id'] ) ) {
                        continue;
                    }
                    if ( (int) $value['id'] === Rental_Options_Defaults::PLACEHOLDER_VALUE_ID ) {
                        continue;
                    }
                    $value['id']    = (int) $value['id'];
                    $value['title'] = isset( $value['title'] ) ? (string) $value['title'] : '';
                    $value['price'] = isset( $value['price'] ) ? $value['price'] : 0;
                    $values[]       = $value;
                }
            }

            $option['id']             = (int) $option['id'];
            $option['title']          = isset( $option['title'] ) ? (string) $option['title'] : '';
            $option['option_values']  = $values;
            $option['once_per_order'] = ! empty( $option['once_per_order'] )
                && Rental_Options_Defaults::is_truthy( $option['once_per_order'] );

            $out[] = $option;
        }

        return $out;
    }

    /**
     * Drop the memoized definitions.
     *
     * @return void
     */
    public static function flush() {
        self::$cache = array();
    }
}

endif;
