<?php
/**
 * Rental_Sets_Order_Display
 *
 * Surfaces the "Group" context on order line items — covering:
 *
 *   - Thank-you / order-received page
 *   - My-account → order details
 *   - Customer order emails (HTML and plain-text)
 *   - Admin order edit screen (line-item meta box)
 *
 * Two hooks do the work:
 *
 *   1. `woocommerce_order_item_meta_end` — fires after the auto-rendered
 *      meta on customer-facing order line items. We print "Group: <name>"
 *      below the product title for group-child lines.
 *
 *   2. `woocommerce_after_order_itemmeta` — fires after the meta box on
 *      the admin order edit screen. Same printout, with admin-friendly
 *      formatting (bold label, p-tag).
 *
 * The Order_Meta class already mirrors group meta to order
 * line items with underscore-prefixed keys. WC auto-hides those, so
 * there's no double-display risk — we're the only producer of group
 * context output.
 *
 * Plain-text email coverage: WC plain-text emails iterate items via
 * the same `woocommerce_order_item_meta_end` action. Our HTML output
 * needs to gracefully degrade. We use a `<small>` tag with no
 * styling — readable as plain text after strip_tags.
 *
 * @package RentopianSync\Sets
 * @since   2.14.7
 *
 * PHP requirement: 7.4+
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'Rental_Sets_Order_Display', false ) ) :

class Rental_Sets_Order_Display {

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

    protected function boot() {
        add_action( 'woocommerce_order_item_meta_end',  array( $this, 'render_customer_meta' ), 20, 4 );
        add_action( 'woocommerce_after_order_itemmeta', array( $this, 'render_admin_meta' ), 20, 3 );

        // Hide the underscore-prefixed group meta from the admin's
        // default itemmeta box (it's already rendered by render_admin_meta
        // and the raw underscore keys would be redundant). WC's filter
        // expects an array of keys to hide.
        add_filter( 'woocommerce_hidden_order_itemmeta', array( $this, 'hide_internal_meta' ), 20 );
    }

    /**
     * Customer-facing: thank-you page, my-account, emails.
     *
     * @param int           $item_id
     * @param WC_Order_Item $item
     * @param WC_Order      $order
     * @param bool          $plain_text
     * @return void
     */
    public function render_customer_meta( $item_id, $item, $order = null, $plain_text = false ) {
        if ( ! is_object( $item ) ) {
            return;
        }

        // Group children get a "Group: <name>" cue. Non-group set children
        // (selectables, simples, addons) get a "Part of set: <name>" cue.
        // Mutually exclusive — a line is at most one of the two.
        $group_name = $this->resolve_group_name( $item );
        if ( '' !== $group_name ) {
            if ( $plain_text ) {
                echo "\n" . esc_html__( 'Group', 'rentopian-sync' ) . ': ' . esc_html( $group_name );
                return;
            }
            printf(
                '<small class="rental-set-group-meta">%s: %s</small>',
                esc_html__( 'Group', 'rentopian-sync' ),
                esc_html( $group_name )
            );
            return;
        }

        $parent_set_name = $this->resolve_parent_set_name( $item );
        if ( '' !== $parent_set_name ) {
            if ( $plain_text ) {
                echo "\n" . esc_html__( 'Part of set', 'rentopian-sync' ) . ': ' . esc_html( $parent_set_name );
                return;
            }
            printf(
                '<small class="rental-set-parent-meta">%s: %s</small>',
                esc_html__( 'Part of set', 'rentopian-sync' ),
                esc_html( $parent_set_name )
            );
        }
    }

    /**
     * Admin order edit: line-item meta box.
     *
     * @param int           $item_id
     * @param WC_Order_Item $item
     * @param WC_Product    $product
     * @return void
     */
    public function render_admin_meta( $item_id, $item, $product = null ) {
        if ( ! is_object( $item ) ) {
            return;
        }

        $group_name = $this->resolve_group_name( $item );
        if ( '' !== $group_name ) {
            printf(
                '<p class="rental-set-group-meta-admin"><strong>%s:</strong> %s</p>',
                esc_html__( 'Group', 'rentopian-sync' ),
                esc_html( $group_name )
            );
            return;
        }

        $parent_set_name = $this->resolve_parent_set_name( $item );
        if ( '' !== $parent_set_name ) {
            printf(
                '<p class="rental-set-parent-meta-admin"><strong>%s:</strong> %s</p>',
                esc_html__( 'Part of set', 'rentopian-sync' ),
                esc_html( $parent_set_name )
            );
        }
    }

    /**
     * Read the group name off an order line item. Order_Meta
     * stored it as `_rental_set_group_name`. Falls back to looking up
     * the group via `_rental_set_group_uid` against the parent set's
     * postmeta.
     *
     * @param WC_Order_Item $item
     * @return string  Group name, or empty string when this isn't a
     *                 group child.
     */
    protected function resolve_group_name( $item ) {
        if ( ! method_exists( $item, 'get_meta' ) ) {
            return '';
        }

        // Confirm this is a group child; non-children skip.
        $is_child_key = Rental_Sets_Cart_Meta::to_order_item_key( Rental_Sets_Cart_Meta::KEY_IS_GROUP_CHILD );
        $is_child     = $item->get_meta( $is_child_key, true );
        if ( ! $is_child ) {
            return '';
        }

        // Direct read from the stored name.
        $name_key = Rental_Sets_Cart_Meta::to_order_item_key( Rental_Sets_Cart_Meta::KEY_GROUP_NAME );
        $name     = $item->get_meta( $name_key, true );
        if ( is_string( $name ) && '' !== trim( $name ) ) {
            return (string) $name;
        }

        // Fallback: resolve via group_uid against the parent set's postmeta.
        $uid_key = Rental_Sets_Cart_Meta::to_order_item_key( Rental_Sets_Cart_Meta::KEY_GROUP_UID );
        $uid     = (string) $item->get_meta( $uid_key, true );
        if ( '' === $uid ) {
            return '';
        }

        // Walk the order's parent line to find the set product id.
        $parent_product_id = $this->resolve_parent_set_product_id( $item );
        if ( ! $parent_product_id ) {
            return '';
        }

        $groups = get_post_meta( $parent_product_id, '_rental_set_grouped_items', true );
        if ( ! is_array( $groups ) ) {
            return '';
        }
        foreach ( $groups as $g ) {
            if ( isset( $g['uid'] ) && (string) $g['uid'] === $uid ) {
                return isset( $g['group_name'] ) ? (string) $g['group_name'] : '';
            }
        }
        return '';
    }

    /**
     * Resolve the parent set's display name for a non-group set child.
     *
     * The legacy `rental_add_products_order_item_meta` handler stores
     * `_rental_add_on_parent_id` (the parent set's WP product id) on every
     * set-child order line. We read that, look up the product, and return
     * its name. Returns '' when the line isn't a set child, the parent id
     * isn't stored, or the product can't be loaded.
     *
     * Skipped for group children — those already render a "Group: <name>"
     * cue via resolve_group_name(), and the two are mutually exclusive
     * (see render_customer_meta / render_admin_meta).
     *
     * @param WC_Order_Item $item
     * @return string
     */
    protected function resolve_parent_set_name( $item ) {
        if ( ! method_exists( $item, 'get_meta' ) ) {
            return '';
        }

        // Mutual exclusion with the "Group" cue.
        $is_group_child_key = Rental_Sets_Cart_Meta::to_order_item_key( Rental_Sets_Cart_Meta::KEY_IS_GROUP_CHILD );
        if ( $item->get_meta( $is_group_child_key, true ) ) {
            return '';
        }

        $parent_id = (int) $item->get_meta( '_rental_add_on_parent_id', true );
        if ( ! $parent_id ) {
            return '';
        }

        $parent_product = function_exists( 'wc_get_product' ) ? wc_get_product( $parent_id ) : null;
        if ( ! is_object( $parent_product ) || ! method_exists( $parent_product, 'get_name' ) ) {
            return '';
        }

        // Defensive: only label as "part of set" when the parent product
        // really is a set. Stops accidental labels on legacy product
        // addons that happen to share the `_rental_add_on_parent_id`
        // meta but whose parent isn't a configured set.
        if ( ! get_post_meta( $parent_id, '_rental_is_set', true ) ) {
            return '';
        }

        return (string) $parent_product->get_name();
    }

    /**
     * Resolve the parent set's WP product ID from a child order line item.
     * The cart-side `rental_add_on_of` maps to the parent's cart_item_key,
     * which becomes a WC line item id at order creation. The Order_Meta
     * class doesn't currently mirror parent_id explicitly, so we fall back
     * to scanning the order's items for the one whose product is a set.
     *
     * @param WC_Order_Item $item
     * @return int
     */
    protected function resolve_parent_set_product_id( $item ) {
        if ( ! method_exists( $item, 'get_order' ) ) {
            return 0;
        }
        $order = $item->get_order();
        if ( ! is_object( $order ) || ! method_exists( $order, 'get_items' ) ) {
            return 0;
        }
        foreach ( $order->get_items() as $line ) {
            if ( ! method_exists( $line, 'get_product_id' ) ) {
                continue;
            }
            $pid = (int) $line->get_product_id();
            if ( $pid && get_post_meta( $pid, '_rental_is_set', true ) ) {
                return $pid;
            }
        }
        return 0;
    }

    /**
     * Hide our internal underscore-prefixed set meta (group context +
     * add-on parentage) from the admin's default itemmeta box. We render a
     * friendly version via `render_admin_meta`; the raw keys would
     * otherwise show up as "_rental_set_group_id: 7" which is noise.
     *
     * @param array $hidden
     * @return array
     */
    public function hide_internal_meta( $hidden ) {
        if ( ! is_array( $hidden ) ) {
            $hidden = array();
        }
        $keys = array_merge(
            Rental_Sets_Cart_Meta::all_keys(),
            Rental_Sets_Cart_Meta::addon_parent_keys()
        );
        foreach ( $keys as $key ) {
            $hidden[] = Rental_Sets_Cart_Meta::to_order_item_key( $key );
        }
        return $hidden;
    }
}

endif;
