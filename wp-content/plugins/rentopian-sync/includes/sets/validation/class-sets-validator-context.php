<?php
/**
 * Rental_Sets_Validator_Context
 *
 * Immutable bag of the inputs every rule needs to do its job. Built once
 * by the orchestrator (`Cart_Validator`) and passed to each rule. Rules
 * read from it but never write — this keeps the rule pipeline easy to
 * reason about (no rule can affect a later rule's inputs).
 *
 * What it carries:
 *   - $set_id       — WP product id of the set
 *   - $rental_set_id — The Rentopian (core) set id
 *   - $quantity     — The submitted parent set quantity
 *   - $is_update    — Whether this is a cart-update vs an add
 *   - $cart_item_key — When updating, the WC cart item key
 *   - $is_from_archive — Did the request originate from a non-product page?
 *
 *   Set metadata (cached once at construction):
 *   - $set_items    — `_rental_set_items` postmeta (entity 1+2 combined)
 *   - $grouped_items — `_rental_set_grouped_items` postmeta (entity 3)
 *   - $hide_items_on_website
 *   - $item_based_total
 *   - $max_quantity
 *
 *   Submitted modern selections:
 *   - $modern_selections — `$_POST['rental_set_selections']` parsed
 *
 * @package RentopianSync\Sets\Validation
 * @since   2.14.7
 *
 * PHP requirement: 7.4+
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'Rental_Sets_Validator_Context', false ) ) :

class Rental_Sets_Validator_Context {

    /** @var int */
    protected $set_id;

    /** @var int */
    protected $rental_set_id;

    /** @var int */
    protected $quantity;

    /** @var bool */
    protected $is_update;

    /** @var string|null */
    protected $cart_item_key;

    /** @var bool */
    protected $is_from_archive;

    /** @var array */
    protected $set_items;

    /** @var array */
    protected $grouped_items;

    /** @var bool */
    protected $hide_items_on_website;

    /** @var bool */
    protected $item_based_total;

    /** @var int */
    protected $max_quantity;

    /** @var array */
    protected $modern_selections;

    /**
     * Build from raw inputs. The orchestrator reads $_POST and
     * postmeta, then passes everything via this constructor.
     *
     * @param array $args
     */
    public function __construct( array $args ) {
        $this->set_id                = isset( $args['set_id'] ) ? (int) $args['set_id'] : 0;
        $this->rental_set_id         = isset( $args['rental_set_id'] ) ? (int) $args['rental_set_id'] : 0;
        $this->quantity              = isset( $args['quantity'] ) ? (int) $args['quantity'] : 1;
        $this->is_update             = ! empty( $args['is_update'] );
        $this->cart_item_key         = isset( $args['cart_item_key'] ) ? (string) $args['cart_item_key'] : null;
        $this->is_from_archive       = ! empty( $args['is_from_archive'] );
        $this->set_items             = isset( $args['set_items'] ) && is_array( $args['set_items'] ) ? $args['set_items'] : array();
        $this->grouped_items         = isset( $args['grouped_items'] ) && is_array( $args['grouped_items'] ) ? $args['grouped_items'] : array();
        $this->hide_items_on_website = ! empty( $args['hide_items_on_website'] );
        $this->item_based_total      = ! empty( $args['item_based_total'] );
        $this->max_quantity          = isset( $args['max_quantity'] ) ? (int) $args['max_quantity'] : 0;
        $this->modern_selections     = isset( $args['modern_selections'] ) && is_array( $args['modern_selections'] ) ? $args['modern_selections'] : array();
    }

    /**
     * Construct directly from the WP request, reading postmeta and POST.
     *
     * @param int    $set_id
     * @param int    $quantity
     * @param string|null $cart_item_key
     * @param bool   $is_update
     * @return self
     */
    public static function build( $set_id, $quantity, $cart_item_key = null, $is_update = false ) {
        global $wpdb, $rental_tables;

        $set_id = (int) $set_id;

        // Resolve rental_set_id via the relations table when available.
        $rental_set_id = 0;
        if ( ! empty( $rental_tables['set_relations'] ) ) {
            $tbl    = $wpdb->prefix . $rental_tables['set_relations'];
            $rental_set_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT `rental_id` FROM {$tbl} WHERE `id` = %d", $set_id ) );
        }

        // Selection-aware read — the validator must see the customer's
        // in-progress picks (session overlay), not the pristine global
        // definition, so classic-flow selections validate correctly.
        $set_items     = function_exists( 'rental_set_items_resolved' )
            ? rental_set_items_resolved( $set_id )
            : get_post_meta( $set_id, '_rental_set_items', true );
        $grouped_items = get_post_meta( $set_id, '_rental_set_grouped_items', true );

        // POST shape from the modern renderer.
        $modern_selections = array();
        if ( isset( $_POST['rental_set_selections'] ) && is_array( $_POST['rental_set_selections'] ) ) {
            $modern_selections = wp_unslash( $_POST['rental_set_selections'] );
        }

        return new self( array(
            'set_id'                => $set_id,
            'rental_set_id'         => $rental_set_id,
            'quantity'              => max( 1, (int) $quantity ),
            'is_update'             => (bool) $is_update,
            'cart_item_key'         => $cart_item_key,
            'is_from_archive'       => self::detect_archive_origin( $set_id ),
            'set_items'             => is_array( $set_items ) ? $set_items : array(),
            'grouped_items'         => is_array( $grouped_items ) ? $grouped_items : array(),
            'hide_items_on_website' => (bool) get_post_meta( $set_id, '_rental_hide_items_on_website', true ),
            'item_based_total'      => (bool) get_post_meta( $set_id, '_rental_item_based_total', true ),
            'max_quantity'          => (int) get_post_meta( $set_id, '_rental_max_quantity', true ),
            'modern_selections'     => $modern_selections,
        ) );
    }

    /**
     * Did this add-to-cart originate from somewhere other than the set's
     * own product page? Drives the archive-context rule, which forces the
     * customer back to the configurator.
     *
     * The detection layers from most to least reliable:
     *
     *   1. The modern product-page marker (`_rental_set_from_product_page`)
     *      when present in POST — definitive.
     *
     *   2. A non-AJAX POST add-to-cart. The ONLY thing in WooCommerce that
     *      issues a non-AJAX POST add-to-cart is the single-product
     *      add-to-cart form. Archive / shop / upsell "Add to cart" buttons
     *      are either AJAX (POST to the `?wc-ajax=add_to_cart` endpoint) or,
     *      when AJAX-on-archives is disabled, plain GET `?add-to-cart=ID`
     *      links — never a non-AJAX POST to the page. So a non-AJAX POST add
     *      of a set IS a product-page submit, by definition, regardless of
     *      which field carries the product id or whether the Referer header
     *      survived. This is the layer that permanently fixes the
     *      false-positive that flagged a completed product-page add as
     *      "needs configuration", redirected, and wiped the selections.
     *      (This function is only ever called while validating an add of
     *      THIS set, so we don't need to re-match the product id here.)
     *
     *   3. Referer comparison against the permalink — secondary heuristic
     *      for the remaining cases (GET links, AJAX archive adds). A missing
     *      referer at this point is not a product-page form submit (those
     *      already returned in layer 2), so treating it as archive is safe.
     *
     * @param int $set_id
     * @return bool
     */
    protected static function detect_archive_origin( $set_id ) {
        // Layer 1 — explicit marker.
        if ( isset( $_POST['_rental_set_from_product_page'] ) && '1' === (string) $_POST['_rental_set_from_product_page'] ) {
            return false;
        }

        // Layer 2 — any non-AJAX POST add-to-cart is the single-product form.
        $add_to_cart_id = isset( $_REQUEST['add-to-cart'] ) ? (int) $_REQUEST['add-to-cart'] : 0;
        $is_post        = isset( $_SERVER['REQUEST_METHOD'] )
            && 0 === strcasecmp( (string) $_SERVER['REQUEST_METHOD'], 'POST' );
        $is_ajax_add    = ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() )
            || isset( $_GET['wc-ajax'] );
        if ( $is_post && ! $is_ajax_add ) {
            return false;
        }

        // Layer 3 — referer heuristic.
        $referer   = wp_get_referer();
        $permalink = get_permalink( $set_id );

        if ( ! $referer ) {
            // Not a product-page form submit (layer 2 would have caught it)
            // and origin unknown → treat as archive (safe).
            self::log_archive_decision( $set_id, true, 'no_referer', $add_to_cart_id, $is_post, $is_ajax_add, $referer, $permalink );
            return true;
        }

        if ( ! $permalink ) {
            return false;
        }

        $clean = function ( $u ) {
            $u = strtok( $u, '?' );
            return rtrim( (string) $u, '/' );
        };
        $is_archive = $clean( $referer ) !== $clean( $permalink );
        if ( $is_archive ) {
            self::log_archive_decision( $set_id, true, 'referer_mismatch', $add_to_cart_id, $is_post, $is_ajax_add, $referer, $permalink );
        }
        return $is_archive;
    }

    /**
     * Diagnostic: record why a request was classified as an archive add.
     * Only fires on the archive=true branch (the actionable case) so the
     * log stays quiet for normal product-page adds. Helps pinpoint any
     * residual mis-classification without guessing.
     *
     * @return void
     */
    protected static function log_archive_decision( $set_id, $decision, $reason, $add_to_cart_id, $is_post, $is_ajax_add, $referer, $permalink ) {
        if ( ! class_exists( 'Rental_Sets_Logger', false ) ) {
            return;
        }
        Rental_Sets_Logger::warn( 'archive_detect', 'classified as archive add', array(
            'set'        => (int) $set_id,
            'reason'     => (string) $reason,
            'atc'        => (int) $add_to_cart_id,
            'post'       => $is_post ? 1 : 0,
            'ajax'       => $is_ajax_add ? 1 : 0,
            'ref'        => $referer ? (string) $referer : '(none)',
            'permalink'  => $permalink ? (string) $permalink : '(none)',
        ) );
    }

    // --- Getters --------------------------------------------------

    public function set_id() { return $this->set_id; }
    public function rental_set_id() { return $this->rental_set_id; }
    public function quantity() { return $this->quantity; }
    public function is_update() { return $this->is_update; }
    public function cart_item_key() { return $this->cart_item_key; }
    public function is_from_archive() { return $this->is_from_archive; }
    public function set_items() { return $this->set_items; }
    public function grouped_items() { return $this->grouped_items; }
    public function hide_items_on_website() { return $this->hide_items_on_website; }
    public function item_based_total() { return $this->item_based_total; }
    public function max_quantity() { return $this->max_quantity; }
    public function modern_selections() { return $this->modern_selections; }

    /**
     * Selections for one specific group, keyed by item_uid. Returns
     * an empty array when the group wasn't submitted.
     *
     * @param string $group_uid
     * @return array
     */
    public function selections_for_group( $group_uid ) {
        if ( ! isset( $this->modern_selections[ $group_uid ] ) ) {
            return array();
        }
        $g = $this->modern_selections[ $group_uid ];
        if ( ! is_array( $g ) || ! isset( $g['selections'] ) || ! is_array( $g['selections'] ) ) {
            return array();
        }
        return $g['selections'];
    }
}

endif;
