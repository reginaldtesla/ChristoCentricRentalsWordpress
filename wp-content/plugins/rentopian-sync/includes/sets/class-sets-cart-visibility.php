<?php
/**
 * Cart & Order Set-Item Visibility Processor.
 *
 * Pre-processes WooCommerce cart or order items once per request to build
 * a lookup of product/variant IDs that should be visually hidden. This
 * centralises the per-item visibility logic that was previously duplicated
 * across every cart, mini-cart, checkout-review, and order-detail template
 * in both the rentpro and eventorian themes.
 *
 * An item must be hidden when:
 *  • It is part of a rental set whose _rental_hide_items_on_website flag is
 *    set, OR when the global rental_hide_set_items option is active.
 *  • It is individually marked hidden inside the set (_rental_some_hidden_items
 *    flag + set_item['hidden']).
 *  • It is an addon nested within a hidden set item (set_item['addons'][n]['hidden']).
 *    Scoped to the (host set item, addon) pair — the same product used as an
 *    ordinary set line, variant choice, or group option keeps its own row.
 *  • It is a standalone product addon (_rental_add_ons) with addon['hidden'] = true.
 *
 * Usage — cart / mini-cart / checkout review:
 *   $hidden_style = rental_get_cart_item_hidden_style(
 *       (int) $cart_item['product_id'],
 *       (int) $cart_item['variation_id']
 *   );
 *   // Returns 'display:none' or ''
 *
 * Callers holding the whole cart item should use is_cart_item_hidden()
 * instead — it can tell an addon line apart from a set line.
 *
 * Usage — order details:
 *   if ( rental_should_hide_order_item( $order, $item ) ) { continue; }
 *
 * @package RentopianSync\Sets
 * @since   2.14.7
 *
 * PHP requirement: 7.4+
 */

defined( 'ABSPATH' ) || exit;

class Rental_Sets_Cart_Visibility {

    // -----------------------------------------------------------------
    // Static state (per-request singletons)
    // -----------------------------------------------------------------

    /**
     * Cart-context singleton. Rebuilt fresh on each HTTP request.
     *
     * @var Rental_Sets_Cart_Visibility|null
     */
    private static $cart_instance = null;

    /**
     * Per-order instances keyed by WC order ID.
     *
     * @var array<int, Rental_Sets_Cart_Visibility>
     */
    private static $order_instances = [];

    // -----------------------------------------------------------------
    // Instance state
    // -----------------------------------------------------------------

    /** @var bool Whether hidden IDs have been collected yet. */
    private $initialized = false;

    /**
     * Parent product IDs whose cart/order rows must be hidden.
     *
     * @var array<int, true>
     */
    private $hidden_pids = [];

    /**
     * Variation / variant IDs whose cart/order rows must be hidden.
     *
     * @var array<int, true>
     */
    private $hidden_vids = [];

    /**
     * Hidden set-item add-ons, keyed "{parent_set_item_product_id}:{addon_product_id}".
     *
     * Kept apart from the maps above because the same product can be an
     * add-on on one line and an ordinary set line (item / variant choice /
     * group option) on another. Hiding by product id alone would drop the
     * ordinary line too, so an add-on hide only ever applies to the line
     * that was added as that add-on.
     *
     * @var array<string, true>
     */
    private $hidden_addon_pairs = [];

    /**
     * Product IDs contributed solely by hidden set-item add-ons. Only
     * consulted by the legacy product/variant-id API so its result stays
     * what it was before add-on hides became line-scoped.
     *
     * @var array<int, true>
     */
    private $hidden_addon_pids = [];

    /**
     * Variant IDs contributed solely by hidden set-item add-ons.
     *
     * @var array<int, true>
     */
    private $hidden_addon_vids = [];

    /**
     * Order-context only: whether any line item in this order records its
     * add-on parentage. Orders placed before Order_Meta started mirroring
     * it carry none, and must keep the flat product-id matching they were
     * rendered with. When it IS present, a line without it is proof the
     * line is not an add-on, so no add-on hide may touch it.
     *
     * @var bool
     */
    private $order_has_addon_parentage = false;

    // -----------------------------------------------------------------
    // Constructor
    // -----------------------------------------------------------------

    private function __construct() {}

    // -----------------------------------------------------------------
    // Public factory methods
    // -----------------------------------------------------------------

    /**
     * Return the cart-context singleton, lazily creating it on first use.
     *
     * @return self
     */
    public static function instance(): self {
        if ( null === self::$cart_instance ) {
            self::$cart_instance = new self();
        }
        return self::$cart_instance;
    }

    /**
     * Return a per-order visibility processor, cached by order ID.
     *
     * @param WC_Order $order
     * @return self
     */
    public static function for_order( WC_Order $order ): self {
        $order_id = $order->get_id();

        if ( ! isset( self::$order_instances[ $order_id ] ) ) {
            $inst = new self();
            $inst->init_from_order( $order );
            self::$order_instances[ $order_id ] = $inst;
        }

        return self::$order_instances[ $order_id ];
    }

    /**
     * Invalidate all cached instances (cart + orders).
     * Hooked to WooCommerce cart-update actions in bootstrap.php so the
     * lookup is always consistent with the live cart contents.
     *
     * @return void
     */
    public static function reset_instance(): void {
        self::$cart_instance  = null;
        self::$order_instances = [];
    }

    // -----------------------------------------------------------------
    // WooCommerce visibility filter callbacks
    // -----------------------------------------------------------------
    //
    // These let the plugin own set-item hiding through WooCommerce's own
    // standard row-visibility filters, so theme templates need no
    // Sets-specific code. Registered in the sets bootstrap.
    //
    // `woocommerce_cart_item_visible`         → cart page
    // `woocommerce_widget_cart_item_visible`  → mini-cart widget
    // `woocommerce_checkout_cart_item_visible`→ checkout review table
    // `woocommerce_order_item_visible`        → order details / emails

    /**
     * Cart / mini-cart / checkout row visibility. All three WC filters
     * share the `($visible, $cart_item, $cart_item_key)` signature.
     *
     * @param bool   $visible
     * @param array  $cart_item
     * @param string $cart_item_key
     * @return bool
     */
    public static function filter_cart_item_visible( $visible, $cart_item, $cart_item_key = '' ): bool {
        if ( ! $visible || ! is_array( $cart_item ) ) {
            return (bool) $visible;
        }
        return ! self::instance()->is_cart_item_hidden( $cart_item );
    }

    /**
     * Order line-item visibility (order details, thank-you, emails,
     * my-account). Signature: `($visible, $item)`.
     *
     * @param bool                   $visible
     * @param WC_Order_Item_Product  $item
     * @return bool
     */
    public static function filter_order_item_visible( $visible, $item ): bool {
        if ( ! $visible || ! ( $item instanceof WC_Order_Item_Product ) ) {
            return (bool) $visible;
        }
        $order = $item->get_order();
        if ( ! ( $order instanceof WC_Order ) ) {
            return (bool) $visible;
        }
        return ! self::for_order( $order )->is_order_item_hidden( $item );
    }

    // -----------------------------------------------------------------
    // Public visibility query API
    // -----------------------------------------------------------------

    /**
     * Check whether a cart/checkout item should be hidden.
     *
     * @param int $product_id   cart_item['product_id']  — always the parent.
     * @param int $variation_id cart_item['variation_id'] — 0 when no variant.
     * @return bool
     */
    public function is_hidden( int $product_id, int $variation_id = 0 ): bool {
        $this->maybe_init_from_cart();

        if ( isset( $this->hidden_pids[ $product_id ] ) || isset( $this->hidden_addon_pids[ $product_id ] ) ) {
            return true;
        }

        // Mirror original template logic:
        //   $product_variant_id = $cart_item['variation_id'] ?: $cart_item['product_id']
        // i.e. use the variation ID when available, otherwise fall back to the product ID.
        $vid = $variation_id > 0 ? $variation_id : $product_id;

        return isset( $this->hidden_vids[ $vid ] ) || isset( $this->hidden_addon_vids[ $vid ] );
    }

    /**
     * Line-aware variant of is_hidden(). Reads the cart item itself so an
     * add-on hide is applied only to the line that was added as that
     * add-on — never to a line where the same product is a set line, a
     * variant choice, or a group option.
     *
     * Prefer this over is_hidden() wherever the full cart item is in hand;
     * is_hidden() stays for the product/variant-id-only template helpers.
     *
     * @param array $cart_item
     * @return bool
     */
    public function is_cart_item_hidden( array $cart_item ): bool {
        $this->maybe_init_from_cart();

        $product_id   = isset( $cart_item['product_id'] ) ? (int) $cart_item['product_id'] : 0;
        $variation_id = isset( $cart_item['variation_id'] ) ? (int) $cart_item['variation_id'] : 0;
        $vid          = $variation_id > 0 ? $variation_id : $product_id;

        if ( isset( $this->hidden_pids[ $product_id ] ) || isset( $this->hidden_vids[ $vid ] ) ) {
            return true;
        }

        // Only an add-on line can be hidden by an add-on hide, and only by
        // the one recorded for its own parent set item.
        $parent_item_id = isset( $cart_item['parent_set_item_product_id'] )
            ? (int) $cart_item['parent_set_item_product_id']
            : 0;
        if ( $parent_item_id <= 0 ) {
            return false;
        }

        return isset( $this->hidden_addon_pairs[ $parent_item_id . ':' . $product_id ] );
    }

    /**
     * Return an inline CSS string suitable for the style="" attribute.
     * Returns 'display:none' when the item should be hidden, '' otherwise.
     *
     * @param int $product_id
     * @param int $variation_id
     * @return string
     */
    public function get_hidden_style( int $product_id, int $variation_id = 0 ): string {
        return $this->is_hidden( $product_id, $variation_id ) ? 'display:none' : '';
    }

    /**
     * Check whether a WooCommerce order line-item should be skipped.
     * Designed for instances created via ::for_order().
     *
     * For order items, WC_Product::get_id() returns the variation ID when the
     * product is a variation. We therefore check the raw ID against both the
     * parent-product map and the variant map.
     *
     * Add-on hides are line-scoped the same way the cart's
     * is_cart_item_hidden() scopes them, using the parentage Order_Meta
     * mirrors onto the line. Orders placed before that mirroring existed
     * carry no parentage and fall back to flat product-id matching, so
     * their rendering is unchanged.
     *
     * @param WC_Order_Item_Product $item
     * @return bool
     */
    public function is_order_item_hidden( WC_Order_Item_Product $item ): bool {
        $product = $item->get_product();

        if ( ! $product ) {
            return false;
        }

        // For a simple product: get_id() → product ID.
        // For a variation:      get_id() → variation ID.
        $actual_id = (int) $product->get_id();
        $parent_id = $product->is_type( 'variation' ) ? (int) $product->get_parent_id() : 0;

        // Item-scope hides (set items, group items, standalone product
        // addons, whole-set hides) apply whatever role the line has.
        if ( isset( $this->hidden_pids[ $actual_id ] )
            || isset( $this->hidden_vids[ $actual_id ] )
            || ( $parent_id > 0 && isset( $this->hidden_pids[ $parent_id ] ) ) ) {
            return true;
        }

        // Add-on line with recorded parentage — only the hide registered
        // for its own host set item applies.
        $addon_parent = $this->order_item_addon_parent( $item );
        if ( $addon_parent > 0 ) {
            $addon_product_id = $parent_id > 0 ? $parent_id : $actual_id;
            return isset( $this->hidden_addon_pairs[ $addon_parent . ':' . $addon_product_id ] );
        }

        // No parentage on this line. If the order records parentage
        // anywhere, this line is definitively not an add-on and no add-on
        // hide may reach it.
        if ( $this->order_has_addon_parentage ) {
            return false;
        }

        // Legacy order — nothing to scope by, so match flat as before.
        return isset( $this->hidden_addon_pids[ $actual_id ] )
            || isset( $this->hidden_addon_vids[ $actual_id ] )
            || ( $parent_id > 0 && isset( $this->hidden_addon_pids[ $parent_id ] ) );
    }

    /**
     * Read the host set item's product id off an order line, as mirrored by
     * Rental_Sets_Order_Meta. Returns 0 when the line is not a set-item
     * add-on or predates the mirroring.
     *
     * @param WC_Order_Item_Product $item
     * @return int
     */
    private function order_item_addon_parent( $item ): int {
        if ( ! is_object( $item ) || ! method_exists( $item, 'get_meta' ) ) {
            return 0;
        }
        if ( ! class_exists( 'Rental_Sets_Cart_Meta', false ) ) {
            return 0;
        }
        $key = Rental_Sets_Cart_Meta::to_order_item_key(
            Rental_Sets_Cart_Meta::KEY_ADDON_PARENT_PRODUCT
        );

        return (int) $item->get_meta( $key, true );
    }

    // -----------------------------------------------------------------
    // Cart badge count (visibility-aware) — keeps hidden set children
    // from inflating the cart-icon number, in the plugin (no theme code).
    // -----------------------------------------------------------------

    /**
     * `woocommerce_cart_contents_count` filter callback. Recomputes the
     * cart badge total so HIDDEN set children never inflate it: sums the
     * quantity of only the cart lines that pass the standard visibility
     * filter (the same one that hides their rows).
     *
     * Theme-agnostic — every theme that reads
     * WC()->cart->get_cart_contents_count() gets the corrected number with
     * no theme changes. Registered in the sets bootstrap.
     *
     * @param int $count WooCommerce's raw sum of all line quantities.
     * @return int
     */
    public static function filter_contents_count( $count ) {
        if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
            return $count;
        }

        $total = 0;
        foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) {
            if ( apply_filters( 'woocommerce_widget_cart_item_visible', true, $cart_item, $cart_item_key ) ) {
                $total += isset( $cart_item['quantity'] ) ? (int) $cart_item['quantity'] : 0;
            }
        }
        return $total;
    }

    /**
     * Visibility-aware cart count for theme badges that do NOT go through
     * get_cart_contents_count() — e.g. a custom "number of items" badge
     * that uses a raw count of the cart array. Such a count can't be fixed
     * by a WooCommerce filter (its only hook, woocommerce_get_cart_contents,
     * also feeds the order TOTAL, so hidden children must stay in it), so
     * the theme reads the corrected value from here instead.
     *
     *   'total' → WooCommerce's get_cart_contents_count() (already corrected
     *             by filter_contents_count + any third-party count filters).
     *   'items' → number of VISIBLE cart lines.
     *
     * @param string $mode 'total' or 'items'.
     * @return int
     */
    public static function visible_count( string $mode = 'total' ): int {
        if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
            return 0;
        }

        if ( 'total' === $mode ) {
            return (int) WC()->cart->get_cart_contents_count();
        }

        $lines = 0;
        foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) {
            if ( apply_filters( 'woocommerce_widget_cart_item_visible', true, $cart_item, $cart_item_key ) ) {
                $lines++;
            }
        }
        return $lines;
    }

    // -----------------------------------------------------------------
    // Initialisation (private)
    // -----------------------------------------------------------------

    /**
     * Lazily populate hidden-ID maps from the current WooCommerce cart.
     *
     * @return void
     */
    private function maybe_init_from_cart(): void {
        if ( $this->initialized ) {
            return;
        }

        $this->initialized = true;

        if ( ! WC()->cart ) {
            return;
        }

        $global_hide = (bool) get_option( 'rental_hide_set_items', 0 );

        foreach ( WC()->cart->get_cart() as $cart_item ) {
            $pid = (int) $cart_item['product_id'];
            $this->collect_from_set( $pid, $global_hide );
            $this->collect_from_addons( $pid );
        }
    }

    /**
     * Populate hidden-ID maps from the items of a specific order.
     *
     * @param WC_Order $order
     * @return void
     */
    private function init_from_order( WC_Order $order ): void {
        if ( $this->initialized ) {
            return;
        }

        $this->initialized = true;

        $global_hide = (bool) get_option( 'rental_hide_set_items', 0 );

        /** @var WC_Order_Item_Product $item */
        foreach ( $order->get_items() as $item ) {
            if ( ! $this->order_has_addon_parentage && $this->order_item_addon_parent( $item ) > 0 ) {
                $this->order_has_addon_parentage = true;
            }

            $product = $item->get_product();

            if ( ! $product ) {
                continue;
            }

            // Sets are always defined on the parent product, never on variations.
            $pid = $product->is_type( 'variation' )
                ? (int) $product->get_parent_id()
                : (int) $product->get_id();

            $this->collect_from_set( $pid, $global_hide );
            $this->collect_from_addons( $pid );
        }
    }

    // -----------------------------------------------------------------
    // Collection helpers (private)
    // -----------------------------------------------------------------

    /**
     * Inspect a set product's _rental_set_items meta and mark any
     * children that should be hidden according to visibility rules.
     *
     * @param int  $set_product_id WP post ID of the potential set product.
     * @param bool $global_hide    Whether rental_hide_set_items option is on.
     * @return void
     */
    private function collect_from_set( int $set_product_id, bool $global_hide ): void {
        if ( ! get_post_meta( $set_product_id, '_rental_is_set', true ) ) {
            return;
        }

        $hide_all    = $global_hide
            || (bool) get_post_meta( $set_product_id, '_rental_hide_items_on_website', true );
        $some_hidden = (bool) get_post_meta( $set_product_id, '_rental_some_hidden_items', true );
        $set_items   = get_post_meta( $set_product_id, '_rental_set_items', true ) ?: [];

        foreach ( $set_items as $set_item ) {
            $item_hidden = $hide_all || ( $some_hidden && ! empty( $set_item['hidden'] ) );

            if ( $item_hidden ) {
                $this->mark_hidden(
                    (int) ( $set_item['product_id'] ?? 0 ),
                    (int) ( $set_item['variant_id']  ?? 0 )
                );
            }

            // Addons nested inside a set item — hidden when their parent item
            // is hidden, or when the addon itself is explicitly flagged.
            foreach ( $set_item['addons'] ?? [] as $addon ) {
                if ( ! $item_hidden && empty( $addon['hidden'] ) ) {
                    continue;
                }

                $addon_pid = (int) ( $addon['product_id'] ?? 0 );
                $addon_vid = (int) ( $addon['variant_id'] ?? 0 );

                if ( $hide_all ) {
                    // Whole set hides its contents — every line goes, so
                    // there is nothing to disambiguate.
                    $this->mark_hidden( $addon_pid, $addon_vid );
                    continue;
                }

                $this->mark_addon_hidden(
                    (int) ( $set_item['product_id'] ?? 0 ),
                    $addon_pid,
                    $addon_vid
                );
            }
        }

        // Composite groups (entity-3). When a group is hidden — the whole
        // set hides items, or the group's own hide_on_website is set — every
        // child it could add is hidden in the cart/order even though it still
        // contributes to the total (resolved server-side as a default).
        $grouped_items = get_post_meta( $set_product_id, '_rental_set_grouped_items', true ) ?: [];
        if ( is_array( $grouped_items ) ) {
            foreach ( $grouped_items as $group ) {
                if ( ! is_array( $group ) ) {
                    continue;
                }
                if ( ! $hide_all && empty( $group['hide_on_website'] ) ) {
                    continue;
                }
                foreach ( $group['items'] ?? [] as $group_item ) {
                    if ( ! is_array( $group_item ) ) {
                        continue;
                    }
                    $this->mark_hidden(
                        (int) ( $group_item['product_id'] ?? 0 ),
                        (int) ( $group_item['variant_id']  ?? 0 )
                    );
                }
            }
        }
    }

    /**
     * Inspect a product's _rental_add_ons meta and mark any addon children
     * that carry the hidden flag.
     *
     * @param int $product_id
     * @return void
     */
    private function collect_from_addons( int $product_id ): void {
        $add_ons = get_post_meta( $product_id, '_rental_add_ons', true );

        if ( empty( $add_ons ) ) {
            return;
        }

        foreach ( $add_ons as $add_on ) {
            if ( ! empty( $add_on['hidden'] ) ) {
                $this->mark_hidden(
                    (int) ( $add_on['product_id'] ?? 0 ),
                    (int) ( $add_on['variant_id']  ?? 0 )
                );
            }
        }
    }

    /**
     * Add a product/variant pair to the hidden-ID maps.
     *
     * @param int $product_id
     * @param int $variant_id
     * @return void
     */
    private function mark_hidden( int $product_id, int $variant_id ): void {
        if ( $product_id > 0 ) {
            $this->hidden_pids[ $product_id ] = true;
        }
        if ( $variant_id > 0 ) {
            $this->hidden_vids[ $variant_id ] = true;
        }
    }

    /**
     * Record a hidden set-item add-on against the set item it hangs off.
     * The pair key is what line-aware lookups match on; the flat maps are
     * kept in step so the product/variant-id-only API still reports it.
     *
     * Keyed by product (not variant) because the customer's variant pick
     * is resolved at add-to-cart time and need not equal the variant the
     * definition stored.
     *
     * @param int $parent_item_product_id WP product id of the host set item.
     * @param int $product_id             WP product id of the add-on.
     * @param int $variant_id             WP variant id of the add-on, 0 when none.
     * @return void
     */
    private function mark_addon_hidden( int $parent_item_product_id, int $product_id, int $variant_id ): void {
        if ( $parent_item_product_id > 0 && $product_id > 0 ) {
            $this->hidden_addon_pairs[ $parent_item_product_id . ':' . $product_id ] = true;
        }
        if ( $product_id > 0 ) {
            $this->hidden_addon_pids[ $product_id ] = true;
        }
        if ( $variant_id > 0 ) {
            $this->hidden_addon_vids[ $variant_id ] = true;
        }
    }
}
