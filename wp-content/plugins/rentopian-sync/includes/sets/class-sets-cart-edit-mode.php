<?php
/**
 * Rental_Sets_Cart_Edit_Mode
 *
 * Implements F4a: edit-mode submits replace the existing cart line
 * instead of merging. Default (no edit param) submits go through the
 * merger as usual.
 *
 * Flow:
 *   1. Cart page renders an "Edit" link on each set parent line. The
 *      link points to the product page with `?rental_edit_cart=<KEY>`.
 *      (See Rental_Sets_Cart_Edit_Link for the link rendering.)
 *
 *   2. The product page detects the query var on load and exposes the
 *      cart item's existing meta to the renderer/JS as prefill data.
 *      (See Rental_Sets_Product_Page_Prefill.)
 *
 *   3. The user changes selections and submits. The form carries a
 *      hidden field `_rental_edit_cart_key=<KEY>`. This class detects
 *      it during the add-to-cart flow:
 *        a. Tells Cart_Merger to skip parent-line merging.
 *        b. After WC commits the new line, removes the OLD line and its
 *           children, leaving only the new line and its new children.
 *
 *   4. If the edit key is invalid or the cart line no longer exists,
 *      we fall back to default merge behavior — never silently fail.
 *
 * @package RentopianSync\Sets
 * @since   2.14.7
 *
 * PHP requirement: 7.4+
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'Rental_Sets_Cart_Edit_Mode', false ) ) :

class Rental_Sets_Cart_Edit_Mode {

    /**
     * Query var name used in the URL.
     */
    const QUERY_VAR = 'rental_edit_cart';

    /**
     * Hidden form field name carried in the add-to-cart POST.
     */
    const POST_FIELD = '_rental_edit_cart_key';

    /**
     * @var string
     */
    protected $log_source = 'rentopian-sets-sync';

    /**
     * @var self|null
     */
    protected static $instance = null;

    /**
     * The cart key being edited in the current request, or null.
     *
     * @var string|null
     */
    protected $editing_key = null;

    /**
     * Per-submission unique id stamped onto the parent set's cart_item_data
     * so WC's cart_id hash differs from the line being edited. Without
     * this the parent line's cart_id collides (its cart_item_data doesn't
     * change when only children change), the new line and old line share
     * a key, and finalize_replacement below would remove BOTH the new
     * children and the old children (they all point to the same parent
     * key). See `tag_parent_for_edit`.
     *
     * @var string|null
     */
    protected $edit_request_id = null;

    public static function register() {
        if ( null !== self::$instance ) {
            return;
        }
        self::$instance = new self();
        self::$instance->boot();
    }

    /**
     * Singleton accessor — used by the prefill class to read the
     * current edit key.
     *
     * @return self|null
     */
    public static function instance() {
        return self::$instance;
    }

    protected function boot() {
        // Detect edit-mode AFTER WC has loaded the cart from session, on
        // every request.
        add_action( 'woocommerce_cart_loaded_from_session', array( $this, 'detect_edit_mode' ), 5 );

        // When edit mode is active, stamp a per-submission unique id onto
        // the parent set's cart_item_data BEFORE WC computes the
        // cart_item_key.
        add_filter( 'woocommerce_add_cart_item_data', array( $this, 'tag_parent_for_edit' ), 35, 3 );

        // After WC commits the new parent line, drop the old one.
        add_action( 'woocommerce_add_to_cart', array( $this, 'finalize_replacement' ), 100, 6 );
    }

    /**
     * Read the query var (on GET / page load) and the POST field (on
     * submit). The product page renders a hidden input carrying the
     * key it picked up from the query string, so the submit knows
     * which line to replace.
     *
     * @return void
     */
    public function detect_edit_mode() {
        $key = null;

        // Submit-time: hidden form field wins.
        if ( isset( $_POST[ self::POST_FIELD ] ) && is_string( $_POST[ self::POST_FIELD ] ) ) {
            $key = sanitize_text_field( wp_unslash( $_POST[ self::POST_FIELD ] ) );
        }

        // Page-load: query var.
        if ( null === $key && isset( $_GET[ self::QUERY_VAR ] ) ) {
            $key = sanitize_text_field( wp_unslash( $_GET[ self::QUERY_VAR ] ) );
        }

        if ( ! $key ) {
            return;
        }

        // Validate against the current cart. If WC isn't ready yet
        // (early `init`), we re-validate at submit time.
        $this->editing_key = $key;

        // If we're at submit time AND we have a confirmed key, signal
        // the merger to step aside.
        if ( ! empty( $_POST ) && function_exists( 'WC' ) && WC() && WC()->cart ) {
            if ( isset( WC()->cart->cart_contents[ $key ] ) ) {

                $this->signal_merger_skip( true );

                if ( class_exists( 'Rental_Sets_Logger', false ) ) {
                    Rental_Sets_Logger::edit_mode( $key, array( 'outcome' => 'detected' ) );
                }

            } else {

                // Stale key — fall through to default behavior.
                if ( class_exists( 'Rental_Sets_Logger', false ) ) {
                    Rental_Sets_Logger::edit_mode( $key, array( 'outcome' => 'stale' ) );
                }

                $this->editing_key = null;
            }
        }
    }

    /**
     * Read accessor used by Product_Page_Prefill.
     *
     * @return string|null
     */
    public function get_editing_key() {
        return $this->editing_key;
    }

    /**
     * `woocommerce_add_cart_item_data` filter callback. When edit mode is
     * active for THIS request, attaches a per-submission unique id onto
     * the set parent's cart_item_data. This forces WC's
     * `generate_cart_id()` hash to differ from the line being edited so
     * the new line gets a new cart_item_key — without this stamp the new
     * and old parent share a key (the parent's data doesn't change when
     * only CHILDREN are reselected), and finalize_replacement's
     * "remove all children of $old_key" pass would also remove the new
     * children that were just added in this same request (they also
     * point to that same key), wiping the set from the cart.
     *
     * Selectivity:
     *   - Only fires when an edit key is active in this request.
     *   - Only stamps SET PARENT inputs (skips children).
     *   - Idempotent within a single request — generates the unique id
     *     lazily, reuses it across any subsequent filter calls.
     *   - Future, non-edit-mode adds of the same set do NOT get this
     *     field — their cart_id is normal, and the merger collapses
     *     them by product_id/variation as usual.
     *
     * @param array $cart_item_data
     * @param int   $product_id
     * @param int   $variation_id
     * @return array
     */
    public function tag_parent_for_edit( $cart_item_data, $product_id, $variation_id ) {
        if ( null === $this->editing_key ) {
            return $cart_item_data;
        }
        if ( ! empty( $cart_item_data['rental_add_on_of'] ) ) {
            return $cart_item_data; // child line, skip
        }
        if ( ! get_post_meta( (int) $product_id, '_rental_is_set', true ) ) {
            return $cart_item_data;
        }
        if ( null === $this->edit_request_id ) {
            $this->edit_request_id = function_exists( 'wp_generate_uuid4' )
                ? wp_generate_uuid4()
                : uniqid( 'rntp-edit-', true );
        }
        $cart_item_data['__rental_edit_request_id'] = $this->edit_request_id;
        return $cart_item_data;
    }

    /**
     * After WC commits the new parent line, remove the OLD line and any
     * children that pointed at it. Children that were added in the same
     * submission (pointing at the NEW line) are untouched.
     *
     * @return void
     */
    public function finalize_replacement( $cart_item_key, $product_id, $quantity, $variation_id, $variation, $cart_item_data ) {
        if ( null === $this->editing_key ) {
            return;
        }

        // Only act once — we want to find the parent commit, not child
        // commits that follow.
        if ( ! function_exists( 'WC' ) || ! WC() || ! WC()->cart ) {
            return;
        }

        $contents = WC()->cart->cart_contents;
        if ( ! isset( $contents[ $cart_item_key ] ) ) {
            return;
        }

        $new = $contents[ $cart_item_key ];

        // Skip until we see the new parent line commit.
        if ( empty( $new['__rental_set_parent_marker'] )
            && empty( $new['rental_set_id'] )
            && empty( get_post_meta( (int) $new['product_id'], '_rental_is_set', true ) ) ) {
            return;
        }
        if ( ! empty( $new['rental_add_on_of'] ) ) {
            return; // a child line, not the parent.
        }

        $old_key = $this->editing_key;
        if ( ! isset( $contents[ $old_key ] ) ) {
            // Old line gone — nothing to do; signal merger to resume.
            $this->signal_merger_skip( false );
            $this->editing_key = null;
            return;
        }

        // Hard safety net: if the new cart_item_key collides with the old
        // one (i.e. the same key), the "remove all children of $old_key"
        // pass below would also remove the new children we just added —
        // they all point to the same key. That wipes the set from the
        // cart. The `tag_parent_for_edit` filter above is supposed to
        // prevent this collision by stamping a per-submission unique id
        // onto the parent's cart_item_data. If that didn't fire for some
        // reason (older cache, missing filter), refuse to run the
        // destructive pass — leave the cart as the merger / native add
        // left it. Better a slightly duplicated state than an empty set.
        if ( $cart_item_key === $old_key ) {
            if ( class_exists( 'Rental_Sets_Logger', false ) ) {
                Rental_Sets_Logger::warn(
                    'edit_mode_collision_skipped',
                    'new cart_item_key matched the old key; refusing to nuke shared children',
                    array( 'edit' => substr( (string) $old_key, 0, 8 ), 'new' => substr( (string) $cart_item_key, 0, 8 ) )
                );
            }
            $this->signal_merger_skip( false );
            $this->editing_key = null;
            return;
        }

        // The edit form submits the quantity the customer wants
        // Drop the old parent's children first (they point at $old_key).
        $removed_children = 0;
        foreach ( $contents as $key => $item ) {
            if ( $key === $cart_item_key ) {
                continue;
            }
            if ( ! isset( $item['rental_add_on_of'] ) ) {
                continue;
            }
            if ( $item['rental_add_on_of'] === $old_key ) {
                WC()->cart->remove_cart_item( $key );
                $removed_children++;
            }
        }

        // Drop the old parent.
        WC()->cart->remove_cart_item( $old_key );

        // Snap every new child to parent_qty × per_set_qty (parent_qty is
        // the submitted quantity WC just committed) via the reconciler's
        // shared normalizer. This keeps edit-replace aligned with the same
        // invariant the increase/decrease handlers enforce.
        if ( class_exists( 'Rental_Sets_Cart_Reconciler', false ) ) {
            Rental_Sets_Cart_Reconciler::normalize_children_qty( $cart_item_key );
        }

        if ( class_exists( 'Rental_Sets_Logger', false ) ) {
            $new_parent_qty = isset( WC()->cart->cart_contents[ $cart_item_key ]['quantity'] )
                ? (int) WC()->cart->cart_contents[ $cart_item_key ]['quantity']
                : (int) $quantity;
            Rental_Sets_Logger::edit_mode( $old_key, array(
                'outcome'          => 'replaced',
                'new_key'          => $cart_item_key,
                'product'          => (int) $product_id,
                'removed_children' => $removed_children,
                'submitted_qty'    => $new_parent_qty,
            ) );
        }

        // Reset state — once per submission.
        $this->signal_merger_skip( false );
        $this->editing_key = null;
    }

    /**
     * Toggle the merger's skip flag through its public accessor.
     *
     * @param bool $skip
     * @return void
     */
    protected function signal_merger_skip( $skip ) {
        Rental_Sets_Cart_Merger::set_skip_for_edit_static( (bool) $skip );
    }
}

endif;
