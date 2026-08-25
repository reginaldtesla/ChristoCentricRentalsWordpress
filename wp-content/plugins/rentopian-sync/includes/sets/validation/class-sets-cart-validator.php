<?php
/**
 * Rental_Sets_Cart_Validator
 *
 * The orchestrator. Builds a Validator_Context, instantiates each rule,
 * runs them in a fixed order, returns one Validation_Result.
 *
 * Three integration points:
 *
 *   1. Hooked on `woocommerce_add_to_cart_validation` (priority 15) so
 *      it runs *after* the legacy `rental_validate_cart_item` (priority
 *      10) for a hybrid pass — the legacy validator handles classic
 *      concerns at priority 10, this orchestrator handles group/archive
 *      concerns at priority 15.
 *
 *   2. Public static `validate()` so the renderer's AJAX endpoint can
 *      ask for inline validation feedback without committing to a full
 *      add-to-cart.
 *
 *   3. On failure, the orchestrator caches the submitted selections to
 *      the session under a per-set key — the contract is "abort but
 *      keep selections so the user doesn't re-create what they had".
 *      The product-page prefill class reads this on the next page load
 *      and the renderer re-populates the form.
 *
 * Filter `rental_sets_validation_rules` lets stores add or remove
 * rules without touching the orchestrator.
 *
 * @package RentopianSync\Sets\Validation
 * @since   2.14.7
 *
 * PHP requirement: 7.4+
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'Rental_Sets_Cart_Validator', false ) ) :

class Rental_Sets_Cart_Validator {

    /**
     * Session key prefix used to stash submitted selections when
     * validation fails. The product-page prefill reads from here when
     * the customer lands back on the form.
     *
     * The full key is "{prefix}{set_id}".
     */
    const SESSION_KEY_PREFIX = 'rental_sets_failed_selections_';

    /**
     * @var string
     */
    protected $log_source = 'rentopian-sets-sync';

    /**
     * @var self|null
     */
    protected static $instance = null;

    public static function register() {
        if ( null !== self::$instance ) {
            return;
        }
        self::$instance = new self();
        self::$instance->boot();
    }

    /**
     * Singleton accessor — used by Product_Page_Prefill to read failed
     * selections.
     *
     * @return self|null
     */
    public static function instance() {
        return self::$instance;
    }

    /**
     * Hook in. Priority 15 = after the legacy validator (priority 10),
     * before the rest of WC's own validation chain.
     */
    protected function boot() {
        add_filter( 'woocommerce_add_to_cart_validation', array( $this, 'on_validate' ), 15, 3 );
    }

    /**
     * WC validation hook entry point. We only act on set products;
     * everything else passes through.
     *
     * @param bool $passed
     * @param int  $product_id
     * @param int  $quantity
     * @return bool
     */
    public function on_validate( $passed, $product_id, $quantity ) {
        if ( ! $passed ) {
            return $passed;
        }

        if ( ! get_post_meta( (int) $product_id, '_rental_is_set', true ) ) {
            return $passed;
        }

        $cart_item_key = isset( $_POST['_rental_cart_item_key_for_update'] )
            ? sanitize_text_field( wp_unslash( $_POST['_rental_cart_item_key_for_update'] ) )
            : null;

        $is_update = ! empty( $cart_item_key );

        $ctx    = Rental_Sets_Validator_Context::build( $product_id, $quantity, $cart_item_key, $is_update );
        $result = $this->run_rules( $ctx );

        if ( $result->failed() ) {

            $this->persist_selections_for_reprefill( $ctx );

            $result->notice_all();

            // For non-AJAX archive submissions, redirect to the product
            // permalink so the renderer can rebuild with the cached
            // selections + show the error inline. AJAX paths fall
            // through and surface notices via the cart fragments.
            $archive_err = $this->find_error_by_code( $result, Rental_Sets_Validation_Result::ERR_ARCHIVE_NEEDS_CONFIG );
            if ( null !== $archive_err && ! wp_doing_ajax() && ! headers_sent() && ! empty( $archive_err['redirect_to'] ) ) {
                wp_safe_redirect( $archive_err['redirect_to'] );
                exit;
            }

            Project_WP_Logger::write(
                sprintf(
                    'Cart_Validator: failed for set %d (%d errors). Codes: %s',
                    (int) $product_id,
                    count( $result->errors() ),
                    implode( ',', wp_list_pluck( $result->errors(), 'code' ) )
                ),
                'warning',
                $this->log_source
            );

            return false;
        }

        // Success — clear any prior failed-selections cache for this set.
        $this->clear_persisted_selections( (int) $product_id );

        return $passed;
    }

    /**
     * Public static convenience entry point. Used by the AJAX inline-
     * validation handler in the modern renderer, and by any other code that wants
     * a one-shot "is this submission valid?" answer.
     *
     * @param int    $product_id
     * @param int    $quantity
     * @param string|null $cart_item_key
     * @param bool   $is_update
     * @return Rental_Sets_Validation_Result
     */
    public static function validate( $product_id, $quantity = 1, $cart_item_key = null, $is_update = false ) {
        // Lazy-instantiate so static callers don't need to register hooks first.
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        $ctx = Rental_Sets_Validator_Context::build( $product_id, $quantity, $cart_item_key, $is_update );
        return self::$instance->run_rules( $ctx );
    }

    /**
     * Run the rule pipeline.
     *
     * @param Rental_Sets_Validator_Context $ctx
     * @return Rental_Sets_Validation_Result
     */
    protected function run_rules( Rental_Sets_Validator_Context $ctx ) {
        $rules = $this->build_rules();
        $rules = apply_filters( 'rental_sets_validation_rules', $rules, $ctx );

        $result = new Rental_Sets_Validation_Result();

        foreach ( $rules as $rule ) {
            if ( ! ( $rule instanceof Rental_Sets_Validation_Rule ) ) {
                continue;
            }
            try {
                $rule->check( $ctx, $result );
            } catch ( \Throwable $e ) {
                Project_WP_Logger::write(
                    sprintf(
                        'Cart_Validator: rule "%s" threw: %s',
                        $rule->id(),
                        $e->getMessage()
                    ),
                    'error',
                    $this->log_source
                );
                // Don't abort — let the rest of the pipeline run.
            }
        }

        return $result;
    }

    /**
     * Default rule order. Archive context first (it short-circuits
     * via wp_safe_redirect), then per-concern rules.
     *
     * @return Rental_Sets_Validation_Rule[]
     */
    protected function build_rules() {
        return array(
            new Rental_Sets_Rule_Archive_Context(),
            new Rental_Sets_Rule_Max_Quantity(),
            new Rental_Sets_Rule_Set_Options(),
            new Rental_Sets_Rule_Selectable_Items(),
            new Rental_Sets_Rule_Group_Required(),
            new Rental_Sets_Rule_Group_Quantity(),
            new Rental_Sets_Rule_Availability(),
        );
    }

    /**
     * @param Rental_Sets_Validation_Result $result
     * @param string $code
     * @return array|null
     */
    protected function find_error_by_code( $result, $code ) {
        foreach ( $result->errors() as $err ) {
            if ( ( $err['code'] ?? '' ) === $code ) {
                return $err;
            }
        }
        return null;
    }

    /**
     * Persist the customer's submitted selections to the session so the
     * renderer can re-display them on the next page load. Without this,
     * a validation failure would force the customer to redo their work.
     *
     * Stored under a per-set session key. The Product_Page_Prefill class
     * reads from here and merges into its prefill payload.
     *
     * @param Rental_Sets_Validator_Context $ctx
     * @return void
     */
    protected function persist_selections_for_reprefill( Rental_Sets_Validator_Context $ctx ) {
        if ( ! function_exists( 'set_rental_session_data' ) ) {
            return;
        }

        $key = self::SESSION_KEY_PREFIX . $ctx->set_id();
        set_rental_session_data( $key, array(
            'set_id'            => $ctx->set_id(),
            'rental_set_id'     => $ctx->rental_set_id(),
            'parent_qty'        => $ctx->quantity(),
            'modern_selections' => $ctx->modern_selections(),
            'saved_at'          => time(),
        ) );
    }

    /**
     * Clear the failed-selections cache for a set. Called after a
     * successful add so the next visit to the product page doesn't
     * re-prefill stale data.
     *
     * @param int $set_id
     * @return void
     */
    protected function clear_persisted_selections( $set_id ) {
        if ( ! function_exists( 'set_rental_session_data' ) ) {
            return;
        }
        $key = self::SESSION_KEY_PREFIX . (int) $set_id;
        set_rental_session_data( $key, null );
    }

    /**
     * Read the failed-selections cache for a set. Public so the
     * Product_Page_Prefill class can use it.
     *
     * @param int $set_id
     * @return array|null  Same shape as persist_selections_for_reprefill, or null.
     */
    public function read_persisted_selections( $set_id ) {
        if ( ! function_exists( 'get_rental_session_data' ) ) {
            return null;
        }
        $key  = self::SESSION_KEY_PREFIX . (int) $set_id;
        $data = get_rental_session_data( $key, null );
        return is_array( $data ) ? $data : null;
    }
}

endif;
