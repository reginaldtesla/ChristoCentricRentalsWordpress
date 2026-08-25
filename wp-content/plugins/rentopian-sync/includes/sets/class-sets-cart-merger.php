<?php
/**
 * Rental_Sets_Cart_Merger
 *
 * Two responsibilities:
 *
 *   1. PARENT SET MERGE
 *      When a parent set is added twice (same WP product id, same division)
 *      we collapse the two add events onto the existing cart line. Set
 *      options (`rental_selected_set_options`) are *replaced* by the
 *      latest add — they don't sum or merge per your spec ("options do
 *      not add up and merge — they just exist once for each set and just
 *      get replaced with the same values or different values").
 *
 *      The parent quantity sums (per F1: classic Option-2 semantics — same
 *      key sums). Children added in the same submission go through their
 *      own merge path described below.
 *
 *   2. GROUP-CHILD CART-ID DISCRIMINATION
 *      Composite-group children carry namespaced `rental_set_group_*`
 *      keys in their cart_item_data. WC's `generate_cart_id()` hashes the
 *      entire cart_item_data array — so two adds of the same child within
 *      the same group naturally produce the same cart_item_key and merge.
 *      Two adds of "Chairs:Gray" inside two different groups produce
 *      different keys (different `rental_set_group_uid`), so they stay
 *      separate. No custom merger needed here — WC + the cart-handler's data
 *      enrichment do this for free. This class just ensures the cart-id
 *      computation includes the group keys (idempotent).
 *
 * Edit mode (the "Edit" affordance on the cart page) bypasses both paths
 * — see Rental_Sets_Cart_Edit_Mode.
 *
 * @package RentopianSync\Sets
 * @since   2.14.7
 *
 * PHP requirement: 7.4+
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'Rental_Sets_Cart_Merger', false ) ) :

class Rental_Sets_Cart_Merger {

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
     * Edit-mode bridge. The Cart_Edit_Mode class flips this flag for the
     * duration of one add-to-cart submission so the merger knows to skip
     * its merging logic and let the edit-replace path run instead.
     *
     * @var bool
     */
    protected $skip_for_edit = false;

    /**
     * Public static bridge used by Cart_Edit_Mode. The merger is a
     * singleton; this routes to whichever instance is currently
     * registered. Idempotent and a no-op when the merger isn't loaded.
     *
     * @param bool $skip
     * @return void
     */
    public static function set_skip_for_edit_static( $skip ) {
        if ( null === self::$instance ) {
            self::register();
        }
        if ( null !== self::$instance ) {
            self::$instance->set_skip_for_edit( (bool) $skip );
        }
    }

    /**
     * Hook order:
     *   - `woocommerce_add_cart_item_data` (priority 30) runs after the
     *     Cart_Handler (priority 20) so all `rental_set_group_*` keys are
     *     already on $cart_item_data. We use this to (a) tag the parent
     *     line with a stable merge key that excludes options, and (b)
     *     leave child lines untouched (their natural cart-id already
     *     discriminates by group).
     *
     *   - `woocommerce_add_to_cart` (priority 10) runs after WC has
     *     committed the line. We post-process to merge parent lines with
     *     identical merge keys and replace options on the survivor.
     */
    protected function boot() {
        add_filter( 'woocommerce_add_cart_item_data', array( $this, 'tag_parent_for_merge' ), 30, 3 );
        add_action( 'woocommerce_add_to_cart',         array( $this, 'collapse_parent_duplicates' ), 99, 6 );
    }

    /**
     * Mark this method as "skip parent merge" for the current request.
     * Called by the edit-mode handler before the form submission so the
     * edit-replace path runs cleanly.
     *
     * @param bool $skip
     * @return void
     */
    public function set_skip_for_edit( $skip ) {
        $this->skip_for_edit = (bool) $skip;
    }

    /**
     * Tag a parent set cart-item-data with a stable merge key that
     * excludes the volatile `rental_selected_set_options` payload. WC's
     * `generate_cart_id` hashes the whole cart_item_data array, so two
     * adds with different options would produce different cart ids and
     * become separate lines. We don't want that — we want them to merge
     * with options-replace.
     *
     * Strategy: store the options under a transient marker; the
     * post-add hook reads it back and writes it onto the surviving
     * cart line. The hash itself doesn't include the options.
     *
     * @param array $cart_item_data
     * @param int   $product_id
     * @param int   $variation_id
     * @return array
     */
    public function tag_parent_for_merge( $cart_item_data, $product_id, $variation_id ) {

        // Only act on parent set lines. A parent set line is a cart line
        // whose product is a `_rental_is_set` post AND not itself a
        // child (no `rental_add_on_of`).
        $is_set_parent = $this->is_set_parent_input( $cart_item_data, $product_id );
        if ( ! $is_set_parent ) {
            return $cart_item_data;
        }

        // Stash options off the cart_item_data so they don't influence
        // WC's cart-id hash. We re-attach in collapse_parent_duplicates.
        if ( isset( $cart_item_data['rental_selected_set_options'] ) ) {
            $cart_item_data['__rental_pending_set_options'] = $cart_item_data['rental_selected_set_options'];
            unset( $cart_item_data['rental_selected_set_options'] );
        }

        // Tag the line so the post-add handler can identify it.
        $cart_item_data['__rental_set_parent_marker'] = 1;

        return $cart_item_data;
    }

    /**
     * After WC commits the new cart line, walk the cart and find any
     * earlier parent set line with the same product/variation. If found,
     * collapse: sum quantity, copy options from the new line to the
     * survivor, remove the new line.
     *
     * Hook signature follows `woocommerce_add_to_cart`:
     *   ( $cart_item_key, $product_id, $quantity, $variation_id,
     *     $variation, $cart_item_data )
     *
     * @return void
     */
    public function collapse_parent_duplicates( $cart_item_key, $product_id, $quantity, $variation_id, $variation, $cart_item_data ) {

        if ( $this->skip_for_edit ) {
            // Edit mode handles its own line replacement; we stand down.
            return;
        }

        if ( ! function_exists( 'WC' ) || ! WC() || ! WC()->cart ) {
            return;
        }

        $contents = WC()->cart->cart_contents;
        if ( ! isset( $contents[ $cart_item_key ] ) ) {
            return;
        }

        $new = $contents[ $cart_item_key ];

        // Confirm this is a set parent line we tagged.
        if ( empty( $new['__rental_set_parent_marker'] ) ) {
            return;
        }

        $new_product    = (int) $new['product_id'];
        $new_variation  = (int) ( $new['variation_id'] ?? 0 );

        // Find any earlier parent line for the same set.
        $survivor_key = null;
        foreach ( $contents as $key => $item ) {
            if ( $key === $cart_item_key ) {
                continue;
            }
            if ( empty( $item['__rental_set_parent_marker'] ) ) {
                continue;
            }
            if ( (int) $item['product_id'] !== $new_product ) {
                continue;
            }
            if ( (int) ( $item['variation_id'] ?? 0 ) !== $new_variation ) {
                continue;
            }
            $survivor_key = $key;
            break;
        }

        // No earlier line — promote pending options to canonical and exit.
        if ( null === $survivor_key ) {
            $this->finalize_options_on_line( $cart_item_key );
            return;
        }

        // Survivor exists. Sum qty, replace options on survivor, drop new line.
        $survivor_item       = $contents[ $survivor_key ];
        $survivor_qty        = (int) $survivor_item['quantity'];
        $new_qty             = (int) $new['quantity'];
        $combined_qty        = $survivor_qty + $new_qty;

        // Copy pending options from new line over to survivor (replace).
        $pending_options = isset( $new['__rental_pending_set_options'] )
            ? $new['__rental_pending_set_options']
            : array();

        WC()->cart->cart_contents[ $survivor_key ]['rental_selected_set_options'] = $pending_options;

        // Remove the marker bookkeeping field from survivor — it was set
        // when survivor was first added. Refresh it so the next add still
        // identifies survivor as a parent.
        unset( WC()->cart->cart_contents[ $survivor_key ]['__rental_pending_set_options'] );

        // Persist the options copy immediately. The set_quantity() call
        // below also writes the session, but doing it here guarantees the
        // options survive even if a later handler short-circuits the qty
        // update — without it the survivor's options would vanish on the
        // next session reload (mini-cart fragment refresh).
        WC()->cart->set_session();

        // Update qty *before* removing the new line, so the cart total
        // reflects the merged value.
        WC()->cart->set_quantity( $survivor_key, $combined_qty, true );

        // Reparent any children that were added in the same submission
        // pointing at the new (about-to-be-removed) parent.
        $this->reparent_children( $cart_item_key, $survivor_key );

        // Drop the duplicate parent.
        WC()->cart->remove_cart_item( $cart_item_key );

        if ( class_exists( 'Rental_Sets_Logger', false ) ) {
            Rental_Sets_Logger::merger( $survivor_key, array(
                'dropped'    => $cart_item_key,
                'product'    => $new_product,
                'variation'  => $new_variation,
                'qty_summed' => $combined_qty,
            ) );
        }
    }

    /**
     * Promote a pending-options blob to the canonical key on a freshly
     * added line that didn't merge into anything.
     *
     * @param string $key
     * @return void
     */
    protected function finalize_options_on_line( $key ) {
        if ( ! isset( WC()->cart->cart_contents[ $key ] ) ) {
            return;
        }
        $line = WC()->cart->cart_contents[ $key ];
        if ( isset( $line['__rental_pending_set_options'] ) ) {
            WC()->cart->cart_contents[ $key ]['rental_selected_set_options'] = $line['__rental_pending_set_options'];
            unset( WC()->cart->cart_contents[ $key ]['__rental_pending_set_options'] );

            // Persist the promotion. We wrote `rental_selected_set_options`
            // straight into cart_contents (not via a WC API), so without an
            // explicit session write it lives only for the current request:
            // the add-time render shows the options, but the next session
            // reload (mini-cart `get_refreshed_fragments`) finds none and
            // the options "disappear". set_session() makes them durable.
            WC()->cart->set_session();
        }
    }

    /**
     * Repoint any cart lines whose `rental_add_on_of` matches the
     * dropped key to the survivor key. Without this, children added in
     * the same submission would orphan when the merger drops the new
     * parent line, and the existing
     * `rental_validate_and_update_add_on_quantity_in_cart` cleanup pass
     * would zero them out.
     *
     * @param string $dropped_key
     * @param string $survivor_key
     * @return void
     */
    protected function reparent_children( $dropped_key, $survivor_key ) {
        foreach ( WC()->cart->cart_contents as $key => $item ) {
            if ( ! isset( $item['rental_add_on_of'] ) ) {
                continue;
            }
            if ( $item['rental_add_on_of'] !== $dropped_key ) {
                continue;
            }
            WC()->cart->cart_contents[ $key ]['rental_add_on_of'] = $survivor_key;
        }
    }

    /**
     * Heuristic: is this incoming cart_item_data describing a set parent
     * (not a child)? Set parents have `_rental_is_set` postmeta = 1 on
     * the product, and don't carry `rental_add_on_of`.
     *
     * @param array $cart_item_data
     * @param int   $product_id
     * @return bool
     */
    protected function is_set_parent_input( $cart_item_data, $product_id ) {
        if ( ! empty( $cart_item_data['rental_add_on_of'] ) ) {
            return false;
        }
        return (bool) get_post_meta( (int) $product_id, '_rental_is_set', true );
    }
}

endif;
