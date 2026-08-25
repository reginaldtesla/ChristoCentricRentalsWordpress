<?php
/**
 * Rental_Options_Validator_Context
 *
 * Immutable bag of everything a rule needs. Built once per validation pass by
 * the orchestrator; rules read from it and never write, so no rule can change
 * what a later rule sees.
 *
 * The context resolves the selection ONCE, from `$_POST` first — which is what
 * makes the gate agree with the page the customer is looking at.
 *
 * @package RentopianSync\ProductOptions\Validation
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'Rental_Options_Validator_Context', false ) ) :

class Rental_Options_Validator_Context {

    /** @var int Product or variation id the options belong to. */
    protected $product_id;

    /** @var int Parent product id when the add is a variation. */
    protected $parent_id;

    /** @var int */
    protected $quantity;

    /** @var bool */
    protected $is_set;

    /** @var bool */
    protected $is_update;

    /** @var string|null */
    protected $cart_item_key;

    /** @var array Option definitions, normalized. */
    protected $options;

    /** @var array|null Raw submission, null when the request carries no options form. */
    protected $submitted;

    /** @var array Resolved selection, option_id => entry. */
    protected $resolved;

    /**
     * @param array $args
     */
    public function __construct( array $args ) {
        $this->product_id    = isset( $args['product_id'] ) ? (int) $args['product_id'] : 0;
        $this->parent_id     = isset( $args['parent_id'] ) ? (int) $args['parent_id'] : 0;
        $this->quantity      = isset( $args['quantity'] ) ? (int) $args['quantity'] : 1;
        $this->is_set        = ! empty( $args['is_set'] );
        $this->is_update     = ! empty( $args['is_update'] );
        $this->cart_item_key = isset( $args['cart_item_key'] ) ? $args['cart_item_key'] : null;
        $this->options       = isset( $args['options'] ) && is_array( $args['options'] ) ? $args['options'] : array();
        $this->submitted     = isset( $args['submitted'] ) ? $args['submitted'] : null;
        $this->resolved      = isset( $args['resolved'] ) && is_array( $args['resolved'] ) ? $args['resolved'] : array();
    }

    /**
     * Build from the current request.
     *
     * @param int         $product_id
     * @param int         $quantity
     * @param string|null $cart_item_key
     * @param bool        $is_update
     * @param int         $parent_id
     * @return self
     */
    public static function build( $product_id, $quantity = 1, $cart_item_key = null, $is_update = false, $parent_id = 0 ) {
        $product_id = (int) $product_id;
        $is_set     = Rental_Options_Repository::is_set( $product_id, $parent_id );
        $options    = Rental_Options_Repository::get( $product_id, $is_set );
        $submitted  = Rental_Options_Selection::from_post();
        $resolved   = Rental_Options_Selection::resolve( $product_id, $is_set, $cart_item_key, $submitted );

        return new self( array(
            'product_id'    => $product_id,
            'parent_id'     => (int) $parent_id,
            'quantity'      => max( 1, (int) $quantity ),
            'is_set'        => $is_set,
            'is_update'     => (bool) $is_update,
            'cart_item_key' => $cart_item_key,
            'options'       => $options,
            'submitted'     => $submitted,
            'resolved'      => $resolved,
        ) );
    }

    /** @return int */
    public function product_id() {
        return $this->product_id;
    }

    /** @return int */
    public function parent_id() {
        return $this->parent_id;
    }

    /** @return int */
    public function quantity() {
        return $this->quantity;
    }

    /** @return bool */
    public function is_set() {
        return $this->is_set;
    }

    /** @return bool */
    public function is_update() {
        return $this->is_update;
    }

    /** @return string|null */
    public function cart_item_key() {
        return $this->cart_item_key;
    }

    /** @return array */
    public function options() {
        return $this->options;
    }

    /** @return bool */
    public function has_options() {
        return ! empty( $this->options );
    }

    /**
     * Whether the request carried an options form at all.
     *
     * False for an archive or quick-view add, where the selects do not exist
     * in the submitting DOM.
     *
     * @return bool
     */
    public function has_submission() {
        return is_array( $this->submitted );
    }

    /** @return array|null */
    public function submitted() {
        return $this->submitted;
    }

    /** @return array option_id => entry */
    public function resolved() {
        return $this->resolved;
    }

    /**
     * Options nothing could answer.
     *
     * @return array List of option definitions.
     */
    public function unanswered() {
        $missing = array();
        foreach ( $this->options as $option ) {
            if ( ! empty( $option['once_per_order'] ) ) {
                continue;
            }
            if ( ! isset( $this->resolved[ (int) $option['id'] ] ) ) {
                $missing[] = $option;
            }
        }
        return $missing;
    }

    /**
     * Compact map for logging.
     *
     * @return array option_id => "value_id:source"
     */
    public function trace_map() {
        $out = array();
        foreach ( $this->resolved as $option_id => $entry ) {
            $out[ $option_id ] = $entry['value_id'] . ':' . $entry['source'];
        }
        return $out;
    }
}

endif;
