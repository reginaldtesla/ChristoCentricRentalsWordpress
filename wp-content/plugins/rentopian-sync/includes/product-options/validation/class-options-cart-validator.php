<?php
/**
 * Rental_Options_Cart_Validator
 *
 * The single add-to-cart gate for product options.
 *
 * Three integration points, mirroring the sets validator:
 *
 *   1. Hooked on `woocommerce_add_to_cart_validation` (priority 25), the slot
 *      the previous procedural gate occupied. Unlike that gate it honours the
 *      running `$passed` value, so it never stacks an options error on top of
 *      an unrelated failure from an earlier callback.
 *
 *   2. Public static `validate()` so the inline AJAX check answers from the
 *      same rules the gate applies — the client can no longer believe
 *      something the server will reject.
 *
 *   3. On a passing add it hands the resolved selection to the capture step,
 *      so what gets written to the cart line is exactly what was validated.
 *      On a failing add it caches the submission for the product page to
 *      re-prefill, instead of discarding the customer's work.
 *
 * The `rental_product_options_validation_rules` filter lets a store add or
 * remove rules without touching this class.
 *
 * @package RentopianSync\ProductOptions\Validation
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'Rental_Options_Cart_Validator', false ) ) :

class Rental_Options_Cart_Validator {

    /**
     * Session key prefix holding a rejected submission so the product page can
     * put the customer's picks back. The full key is "{prefix}{product_id}".
     */
    const SESSION_FAILED_PREFIX = 'rental_options_failed_selection_';

    /** @var self|null */
    protected static $instance = null;

    /**
     * Selection accepted for the add currently in flight, keyed by product id.
     *
     * @var array<int,array>
     */
    protected static $accepted = array();

    /**
     * @return void
     */
    public static function register() {
        if ( null !== self::$instance ) {
            return;
        }
        self::$instance = new self();
        self::$instance->boot();
    }

    /**
     * @return self|null
     */
    public static function instance() {
        return self::$instance;
    }

    /**
     * @return void
     */
    protected function boot() {
        add_filter( 'woocommerce_add_to_cart_validation', array( $this, 'on_validate' ), 25, 4 );
    }

    /**
     * WooCommerce validation entry point.
     *
     * @param bool $passed
     * @param int  $product_id
     * @param int  $quantity
     * @param int  $variation_id
     * @return bool
     */
    public function on_validate( $passed, $product_id, $quantity = 1, $variation_id = 0 ) {
        if ( ! $passed ) {
            return $passed;
        }

        if ( ! self::owns( $product_id, $variation_id ) ) {
            return $passed;
        }

        $actual_id = $variation_id ? (int) $variation_id : (int) $product_id;
        $parent_id = $variation_id ? (int) $product_id : 0;

        $cart_item_key = isset( $_POST['_rental_cart_item_key_for_update'] )
            ? sanitize_text_field( wp_unslash( $_POST['_rental_cart_item_key_for_update'] ) )
            : null;

        $ctx    = Rental_Options_Validator_Context::build( $actual_id, $quantity, $cart_item_key, ! empty( $cart_item_key ), $parent_id );
        $result = self::run_rules( $ctx );

        if ( $result->failed() ) {
            // The final-verdict hook remembers the submission for every
            // rejection, including ones raised elsewhere on the chain.
            $result->notice_all();
            self::trace( 'validate_blocked', $ctx, $result );

            return false;
        }

        // The gate and the writer must agree. Keep what was validated, and
        // align the session stores with it in the same request.
        self::$accepted[ $actual_id ] = $ctx->resolved();
        Rental_Options_Selection::persist( $actual_id, $ctx->is_set(), $ctx->resolved() );

        self::trace( 'validate_passed', $ctx, $result );

        return $passed;
    }

    /**
     * Whether this validator owns a product.
     *
     * Modern sets run their own rule pipeline, which understands groups, the
     * per-section required flag and the "No Thanks" opt-out. Add-ons carry no
     * options of their own.
     *
     * @param int $product_id
     * @param int $variation_id
     * @return bool
     */
    public static function owns( $product_id, $variation_id = 0 ) {
        $actual_id = $variation_id ? (int) $variation_id : (int) $product_id;

        if ( ! Rental_Options_Repository::module_is_active() ) {
            return false;
        }

        if ( get_post_meta( $actual_id, '_rental_is_add_on', true ) ) {
            return false;
        }

        if ( class_exists( 'Rental_Sets_Renderer' )
            && Rental_Sets_Renderer::will_render( (int) $product_id ) ) {
            return false;
        }

        return true;
    }

    /**
     * Run the rule pipeline over a context.
     *
     * @param Rental_Options_Validator_Context $ctx
     * @return Rental_Options_Validation_Result
     */
    public static function run_rules( Rental_Options_Validator_Context $ctx ) {
        $result = new Rental_Options_Validation_Result();

        $rules = apply_filters(
            'rental_product_options_validation_rules',
            array( new Rental_Options_Rule_Required() ),
            $ctx
        );

        foreach ( $rules as $rule ) {
            if ( $rule instanceof Rental_Options_Validation_Rule ) {
                $rule->check( $ctx, $result );
            }
        }

        return $result;
    }

    /**
     * Answer the same question the gate answers, without adding to the cart.
     *
     * @param int        $product_id
     * @param int        $quantity
     * @param array|null $post_override Selection to test instead of $_POST.
     * @return Rental_Options_Validation_Result
     */
    public static function validate( $product_id, $quantity = 1, $post_override = null ) {
        $product_id = (int) $product_id;

        if ( ! self::owns( $product_id ) ) {
            return new Rental_Options_Validation_Result();
        }

        $is_set    = Rental_Options_Repository::is_set( $product_id );
        $submitted = null === $post_override ? Rental_Options_Selection::from_post() : $post_override;

        $ctx = new Rental_Options_Validator_Context( array(
            'product_id' => $product_id,
            'quantity'   => $quantity,
            'is_set'     => $is_set,
            'options'    => Rental_Options_Repository::get( $product_id, $is_set ),
            'submitted'  => $submitted,
            'resolved'   => Rental_Options_Selection::resolve( $product_id, $is_set, null, $submitted ),
        ) );

        return self::run_rules( $ctx );
    }

    /**
     * The selection this validator accepted for a product in this request.
     *
     * The capture step writes this to the cart line, so the stored options are
     * the ones the gate saw rather than a second, independently resolved set.
     *
     * @param int $product_id
     * @return array|null
     */
    public static function accepted_selection( $product_id ) {
        $product_id = (int) $product_id;

        return isset( self::$accepted[ $product_id ] ) ? self::$accepted[ $product_id ] : null;
    }

    /**
     * A rejected submission, for the product page to re-prefill.
     *
     * @param int $product_id
     * @return array
     */
    public static function failed_selection( $product_id ) {
        $stored = get_rental_session_data( self::SESSION_FAILED_PREFIX . (int) $product_id, array() );

        return Rental_Options_Selection::normalize_stored( $stored );
    }

    /**
     * Keep the customer's option picks after an add was rejected — by anyone.
     *
     * This validator returns early when an earlier callback has already failed,
     * so on its own it only remembers picks for rejections it raised itself. A
     * missing rental date or an unavailable product would then bounce the
     * customer back to a product page with their selections reset, even though
     * the options were never the problem. Called from the final-verdict hook,
     * which sees the outcome of the whole chain.
     *
     * @param int $product_id
     * @param int $variation_id
     * @return void
     */
    public static function remember_submission( $product_id, $variation_id = 0 ) {
        $submitted = Rental_Options_Selection::from_post();
        if ( ! is_array( $submitted ) || empty( $submitted ) ) {
            return;
        }

        $actual_id = $variation_id ? (int) $variation_id : (int) $product_id;
        $is_set    = Rental_Options_Repository::is_set( $actual_id, (int) $product_id );
        $resolved  = Rental_Options_Selection::resolve( $actual_id, $is_set, null, $submitted );
        if ( empty( $resolved ) ) {
            return;
        }

        // The product page renders a variable product from its PARENT id, so
        // store against both — whichever id the page asks for will find it.
        $ids = array_unique( array_filter( array( $actual_id, (int) $product_id ) ) );
        foreach ( $ids as $id ) {
            set_rental_session_data( self::SESSION_FAILED_PREFIX . $id, $resolved );
        }

        self::trace_remembered( $actual_id, $resolved );
    }

    /**
     * @param int   $product_id
     * @param array $resolved
     * @return void
     */
    protected static function trace_remembered( $product_id, array $resolved ) {
        if ( ! function_exists( 'rental_options_trace' ) ) {
            return;
        }
        rental_options_trace( 'remembered_after_reject', array(
            'pid'  => $product_id,
            'kept' => array_map( function ( $entry ) { return (int) $entry['value_id']; }, $resolved ),
        ) );
    }

    /**
     * Record a verdict in the options trace.
     *
     * @param string                           $where
     * @param Rental_Options_Validator_Context $ctx
     * @param Rental_Options_Validation_Result $result
     * @return void
     */
    protected static function trace( $where, Rental_Options_Validator_Context $ctx, Rental_Options_Validation_Result $result ) {
        if ( ! function_exists( 'rental_options_trace' ) ) {
            return;
        }
        rental_options_trace( $where, array(
            'pid'        => $ctx->product_id(),
            'is_set'     => $ctx->is_set() ? 1 : 0,
            'submitted'  => $ctx->has_submission() ? $ctx->submitted() : 'absent',
            'resolved'   => $ctx->trace_map(),
            'unanswered' => wp_list_pluck( $ctx->unanswered(), 'title' ),
            'codes'      => $result->codes(),
        ) );
    }
}

endif;
