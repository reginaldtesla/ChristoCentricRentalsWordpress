<?php
/**
 * Rental_Options_Selection
 *
 * Resolves "which value is chosen for each option of this product, right now",
 * from one fixed precedence, for every reader in the module.
 *
 * The desync this replaces: the product page rendered its selects from the
 * cart line, the add-to-cart gate read only the `rental_product_options_valuables`
 * session key, and the customer's actual submission sat unread in `$_POST`
 * until after the gate had already voted. Three stores, three answers, one
 * error message.
 *
 * Precedence, highest first:
 *   1. `$_POST[rental_options_selection]` — what the customer is submitting in
 *      this very request. Authoritative whenever the field is present.
 *   2. The cart line, when the product is already in the cart.
 *   3. The per-product session working copy.
 *   4. The shared `rental_product_options_valuables` session map.
 *   5. The option's default (Rental_Options_Defaults).
 *
 * An option that survives all five without a value is genuinely unanswered and
 * is the only thing the validator may block on.
 *
 * @package RentopianSync\ProductOptions
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'Rental_Options_Selection', false ) ) :

class Rental_Options_Selection {

    /**
     * Form field the product-page selects submit under.
     * Shape: rental_options_selection[<option_id>] = <value_id>
     */
    const POST_FIELD = 'rental_options_selection';

    /** Shared session map, read by pricing and mini-cart display. */
    const SESSION_VALUABLES = 'rental_product_options_valuables';

    /** Where a resolved value came from. */
    const SOURCE_POST      = 'post';
    const SOURCE_CART      = 'cart';
    const SOURCE_SESSION   = 'session';
    const SOURCE_VALUABLES = 'valuables';
    const SOURCE_DEFAULT   = 'default';

    /**
     * What the caller is asking about.
     *
     * STAGING — the configuration the customer is building on the product page
     * for their NEXT add. It must not read the cart line: a line already in the
     * cart is a finished purchase decision, and letting it outrank the staged
     * value meant a change on the product page appeared to revert.
     *
     * COMMITTED — what a line in the cart actually holds. Used by the cart-page
     * editor, which really is editing that line.
     *
     * The product page and the add-to-cart gate both use STAGING, so they
     * always agree about what is selected.
     */
    const CONTEXT_STAGING   = 'staging';
    const CONTEXT_COMMITTED = 'committed';

    /**
     * Per-product session key holding the working copy.
     *
     * @param int  $product_id
     * @param bool $is_set
     * @return string
     */
    public static function session_key( $product_id, $is_set = false ) {
        return (int) $product_id . ( $is_set ? '_selected_options_of_set' : '_selected_options' );
    }

    /**
     * Cart-line key holding the committed selection.
     *
     * @param bool $is_set
     * @return string
     */
    public static function cart_key( $is_set = false ) {
        return $is_set ? 'rental_selected_set_options' : 'rental_selected_options';
    }

    /**
     * The raw submission for this request.
     *
     * Returns null when the field is absent entirely — that means the request
     * carries no options form at all (an archive or quick-view add), which is
     * a different situation from a form submitted with nothing chosen.
     *
     * @return array<int,int>|null option_id => value_id
     */
    public static function from_post() {
        if ( ! isset( $_POST[ self::POST_FIELD ] ) || ! is_array( $_POST[ self::POST_FIELD ] ) ) {
            return null;
        }

        $out = array();
        foreach ( wp_unslash( $_POST[ self::POST_FIELD ] ) as $option_id => $value_id ) {
            $out[ (int) $option_id ] = (int) $value_id;
        }

        return $out;
    }

    /**
     * The committed selection on a cart line, normalized.
     *
     * @param string $cart_item_key
     * @param bool   $is_set
     * @return array<int,array>
     */
    public static function from_cart_item( $cart_item_key, $is_set = false ) {
        if ( ! $cart_item_key || ! function_exists( 'WC' ) || ! WC()->cart ) {
            return array();
        }
        $contents = WC()->cart->get_cart();
        if ( ! isset( $contents[ $cart_item_key ] ) ) {
            return array();
        }
        $key = self::cart_key( $is_set );

        return self::normalize_stored( isset( $contents[ $cart_item_key ][ $key ] ) ? $contents[ $cart_item_key ][ $key ] : array() );
    }

    /**
     * The cart line that holds this product's options, if any.
     *
     * Add-on child lines are skipped — options live on the parent line.
     *
     * @param int $product_id
     * @return string|null
     */
    public static function find_cart_item_key( $product_id ) {
        if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
            return null;
        }
        $product_id = (int) $product_id;
        foreach ( WC()->cart->get_cart() as $key => $item ) {
            if ( ! empty( $item['rental_add_on_of'] ) ) {
                continue;
            }
            $pid = ! empty( $item['variation_id'] ) ? (int) $item['variation_id'] : (int) $item['product_id'];
            if ( $pid === $product_id ) {
                return $key;
            }
        }
        return null;
    }

    /**
     * Resolve every option of a product to a chosen value.
     *
     * @param int         $product_id
     * @param bool        $is_set
     * @param string|null $cart_item_key Restrict the cart read to one line.
     * @param array|null  $post_override Use this instead of reading $_POST.
     * @param string      $context       CONTEXT_STAGING or CONTEXT_COMMITTED.
     * @return array<int,array> option_id => entry, only for options that resolved.
     */
    public static function resolve( $product_id, $is_set = false, $cart_item_key = null, $post_override = null, $context = self::CONTEXT_STAGING ) {
        $product_id = (int) $product_id;
        $options    = Rental_Options_Repository::get( $product_id, $is_set );
        if ( empty( $options ) ) {
            return array();
        }

        $post = null === $post_override ? self::from_post() : $post_override;

        // Only a caller editing a cart line reads that line. See CONTEXT_*.
        $cart = array();
        if ( self::CONTEXT_COMMITTED === $context ) {
            $cart_key = $cart_item_key ? $cart_item_key : self::find_cart_item_key( $product_id );
            $cart     = self::from_cart_item( $cart_key, $is_set );
        }

        $session = self::normalize_stored( get_rental_session_data( self::session_key( $product_id, $is_set ), array() ) );

        // The shared map is rebuilt from the cart on every cart and checkout
        // render, so reading it while staging reintroduces the committed value
        // that this context deliberately skips above — one hop later, and under
        // a different name. Staging stops at the per-product session key, which
        // persist() always writes alongside it.
        $valuables = array();
        if ( self::CONTEXT_COMMITTED === $context ) {
            $valuables = get_rental_session_data( self::SESSION_VALUABLES, array() );
            $valuables = self::normalize_stored( isset( $valuables[ $product_id ] ) ? $valuables[ $product_id ] : array() );
        }

        $resolved = array();

        foreach ( $options as $option ) {
            $option_id = (int) $option['id'];

            $candidates = array(
                self::SOURCE_POST      => is_array( $post ) && isset( $post[ $option_id ] ) ? $post[ $option_id ] : null,
                self::SOURCE_CART      => isset( $cart[ $option_id ] ) ? $cart[ $option_id ]['value_id'] : null,
                self::SOURCE_SESSION   => isset( $session[ $option_id ] ) ? $session[ $option_id ]['value_id'] : null,
                self::SOURCE_VALUABLES => isset( $valuables[ $option_id ] ) ? $valuables[ $option_id ]['value_id'] : null,
            );

            $chosen = null;
            $source = '';
            foreach ( $candidates as $candidate_source => $value_id ) {
                if ( null === $value_id ) {
                    continue;
                }
                if ( ! Rental_Options_Repository::is_valid_value( $option, $value_id ) ) {
                    continue;
                }
                $chosen = (int) $value_id;
                $source = $candidate_source;
                break;
            }

            if ( null === $chosen ) {
                $default = Rental_Options_Defaults::resolve( $option );
                if ( null === $default ) {
                    continue; // Genuinely unanswered.
                }
                $chosen = (int) $default['id'];
                $source = self::SOURCE_DEFAULT;
            }

            $resolved[ $option_id ] = self::build_entry( $option, $chosen, $source );
        }

        return $resolved;
    }

    /**
     * A full selection entry for one chosen value.
     *
     * @param array  $option
     * @param int    $value_id
     * @param string $source
     * @return array
     */
    public static function build_entry( $option, $value_id, $source = '' ) {
        $value = Rental_Options_Repository::find_value( $option, $value_id );

        return array(
            'option_id'         => (int) $option['id'],
            'option_title'      => $option['title'],
            'value_id'          => (int) $value_id,
            'selected_value_id' => (int) $value_id,
            'value_title'       => $value ? $value['title'] : '',
            'price'             => $value ? $value['price'] : 0,
            'source'            => $source,
        );
    }

    /**
     * Bring a stored map into the canonical shape.
     *
     * Stored maps come from several eras of this code and use `value_id` or
     * `selected_value_id`, string or int keys, and sometimes a bare scalar.
     *
     * @param mixed $stored
     * @return array<int,array>
     */
    public static function normalize_stored( $stored ) {
        if ( empty( $stored ) || ! is_array( $stored ) ) {
            return array();
        }

        $out = array();
        foreach ( $stored as $option_id => $entry ) {
            $option_id = (int) $option_id;
            if ( ! $option_id ) {
                continue;
            }

            if ( ! is_array( $entry ) ) {
                $value_id = (int) $entry;
                $entry    = array();
            } else {
                $value_id = isset( $entry['value_id'] )
                    ? (int) $entry['value_id']
                    : ( isset( $entry['selected_value_id'] ) ? (int) $entry['selected_value_id'] : 0 );
            }

            if ( $value_id <= 0 || $value_id === Rental_Options_Defaults::PLACEHOLDER_VALUE_ID ) {
                continue;
            }

            $entry['option_id']         = $option_id;
            $entry['value_id']          = $value_id;
            $entry['selected_value_id'] = $value_id;
            $entry['price']             = isset( $entry['price'] ) ? $entry['price'] : 0;

            $out[ $option_id ] = $entry;
        }

        return $out;
    }

    /**
     * Write a selection to both session stores at once.
     *
     * The two keys drifting apart is what produced the original bug: the
     * per-product key was written on one code path and the shared map on
     * another, so a selection could exist in one and not the other. They are
     * only ever written together now.
     *
     * @param int   $product_id
     * @param bool  $is_set
     * @param array $selection option_id => entry
     * @return void
     */
    public static function persist( $product_id, $is_set, $selection ) {
        $product_id = (int) $product_id;
        $selection  = self::normalize_stored( $selection );

        set_rental_session_data( self::session_key( $product_id, $is_set ), $selection );

        $valuables                = get_rental_session_data( self::SESSION_VALUABLES, array() );
        $valuables                = is_array( $valuables ) ? $valuables : array();
        $valuables[ $product_id ] = $selection;
        set_rental_session_data( self::SESSION_VALUABLES, $valuables );
    }

    /**
     * The product a cart line is for, and whether it is a set.
     *
     * A set child carries its parent's options, never its own, so it is
     * reported as owning nothing.
     *
     * @param array $cart_item
     * @return array{product_id:int,is_set:bool}|null Null when the line has no options of its own.
     */
    public static function cart_line_subject( $cart_item ) {
        if ( empty( $cart_item ) || ! is_array( $cart_item ) ) {
            return null;
        }

        $product_id = ! empty( $cart_item['variation_id'] )
            ? (int) $cart_item['variation_id']
            : (int) ( isset( $cart_item['product_id'] ) ? $cart_item['product_id'] : 0 );

        if ( ! $product_id ) {
            return null;
        }

        $is_set = Rental_Options_Repository::is_set( $product_id, isset( $cart_item['product_id'] ) ? (int) $cart_item['product_id'] : 0 );

        if ( ! $is_set && isset( $cart_item['rental_set_id'] ) ) {
            return null;
        }

        return array(
            'product_id' => $product_id,
            'is_set'     => $is_set,
        );
    }

    /**
     * What one cart line holds, answered only from that line.
     *
     * The line first, then the option's own default. No product-id-keyed store
     * is consulted, so two lines of the same product — or two products that
     * share an option definition — can never answer for each other. This is
     * the rule the cart display, pricing, the checkout gate and the order
     * payload all follow.
     *
     * @param string     $cart_item_key
     * @param array|null $cart_item Read from the cart when omitted.
     * @return array<int,array> option_id => entry, only for options that resolved.
     */
    public static function for_cart_line( $cart_item_key, $cart_item = null ) {
        if ( null === $cart_item ) {
            if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
                return array();
            }
            $contents  = WC()->cart->get_cart();
            $cart_item = isset( $contents[ $cart_item_key ] ) ? $contents[ $cart_item_key ] : null;
        }

        $subject = self::cart_line_subject( $cart_item );
        if ( null === $subject ) {
            return array();
        }

        $committed = self::from_cart_item( $cart_item_key, $subject['is_set'] );
        $resolved  = array();

        foreach ( Rental_Options_Repository::get( $subject['product_id'], $subject['is_set'] ) as $option ) {
            $option_id = (int) $option['id'];

            if ( isset( $committed[ $option_id ] )
                && Rental_Options_Repository::is_valid_value( $option, $committed[ $option_id ]['value_id'] ) ) {
                $resolved[ $option_id ] = self::build_entry( $option, (int) $committed[ $option_id ]['value_id'], self::SOURCE_CART );
                continue;
            }

            $default = Rental_Options_Defaults::resolve( $option );
            if ( null !== $default ) {
                $resolved[ $option_id ] = self::build_entry( $option, (int) $default['id'], self::SOURCE_DEFAULT );
            }
        }

        return $resolved;
    }

    /**
     * Options of one cart line that neither the line nor a default answers.
     *
     * Once-per-order options are asked at order level and are never counted.
     *
     * @param string     $cart_item_key
     * @param array|null $cart_item
     * @return array List of option definitions.
     */
    public static function unanswered_for_cart_line( $cart_item_key, $cart_item = null ) {
        if ( null === $cart_item ) {
            if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
                return array();
            }
            $contents  = WC()->cart->get_cart();
            $cart_item = isset( $contents[ $cart_item_key ] ) ? $contents[ $cart_item_key ] : null;
        }

        $subject = self::cart_line_subject( $cart_item );
        if ( null === $subject ) {
            return array();
        }

        $resolved = self::for_cart_line( $cart_item_key, $cart_item );
        $missing  = array();

        foreach ( Rental_Options_Repository::get( $subject['product_id'], $subject['is_set'] ) as $option ) {
            if ( ! empty( $option['once_per_order'] ) ) {
                continue;
            }
            if ( ! isset( $resolved[ (int) $option['id'] ] ) ) {
                $missing[] = $option;
            }
        }

        return $missing;
    }

    /**
     * Options of a product that no source could answer.
     *
     * @param int         $product_id
     * @param bool        $is_set
     * @param string|null $cart_item_key
     * @param array|null  $post_override
     * @param string      $context
     * @return array List of option definitions.
     */
    public static function unanswered( $product_id, $is_set = false, $cart_item_key = null, $post_override = null, $context = self::CONTEXT_STAGING ) {
        $resolved = self::resolve( $product_id, $is_set, $cart_item_key, $post_override, $context );
        $missing  = array();

        foreach ( Rental_Options_Repository::get( $product_id, $is_set ) as $option ) {
            if ( ! empty( $option['once_per_order'] ) ) {
                continue;
            }
            if ( ! isset( $resolved[ (int) $option['id'] ] ) ) {
                $missing[] = $option;
            }
        }

        return $missing;
    }
}

endif;
