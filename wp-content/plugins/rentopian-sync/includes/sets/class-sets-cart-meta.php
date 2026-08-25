<?php
/**
 * Rental_Sets_Cart_Meta
 *
 * Central registry for the modern composite-group meta keys that travel
 * across the cart-item -> order-item -> WP->Core payload pipeline.
 *
 * Everything modern adds to existing cart/order data is namespaced under
 * `rental_set_group_*`. The neighbouring entity-1/entity-2 keys
 * (`rental_set_id`, `set_id`, `parent_set_item_product_id`, etc.) keep
 * their names and their cart-side meaning, so classic sets behave exactly
 * as today.
 *
 * Two independent key groups travel to the order line:
 *
 *   - all_keys()          composite-GROUP context, for group children.
 *   - addon_parent_keys() add-on parentage, for add-ons attached to a set
 *                         item. Mirrored so order-side code can tell an
 *                         add-on line apart from an ordinary line of the
 *                         same product — one product can now legitimately
 *                         fill both roles in one set.
 *
 * The two are mutually exclusive on any given line.
 *
 * The key strings are exposed as constants and through a single helper
 * map so when the core API contract finalises, renaming is a one-spot
 * change. Cart/order/display/payload code reads through this class.
 *
 * @package RentopianSync\Sets
 * @since   2.14.7
 *
 * PHP requirement: 7.4+
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'Rental_Sets_Cart_Meta', false ) ) :

class Rental_Sets_Cart_Meta {

    /**
     * Marker that a cart line came from a composite-group child.
     * Truthy = this line is a chosen item out of an entity-3 group.
     */
    const KEY_IS_GROUP_CHILD = 'rental_set_group_is_child';

    /**
     * Rental ID of the parent group (rental_id from
     * `inventory_sets_groups_relation`, as shipped in
     * `_rental_set_grouped_items` postmeta).
     */
    const KEY_GROUP_ID = 'rental_set_group_id';

    /**
     * Group's deterministic UID (e.g. "7-grp-abc...").
     */
    const KEY_GROUP_UID = 'rental_set_group_uid';

    /**
     * UID of the chosen child item inside the group.
     */
    const KEY_GROUP_ITEM_UID = 'rental_set_group_item_uid';

    /**
     * Group's price (group_price from server). Null/empty when admin
     * left it blank — payload-builder uses default-variant price then.
     */
    const KEY_GROUP_PRICE = 'rental_set_group_price';

    /**
     * Group's own quantity (group_quantity). Its role is the product-page
     * qty-stepper default: the group's default child seeds its stepper to
     * this value (see the multi-select / dropdown-qty section partials).
     * It is NOT a cart-line billing multiplier — a group child bills by its
     * own picked units (per_set_quantity), not by group_quantity. The value
     * rides along on the cart line and is forwarded as metadata on
     * quote / order creation.
     */
    const KEY_GROUP_QTY = 'rental_set_group_qty';

    /**
     * Group's required flag — used by the cart "remove" gate.
     */
    const KEY_GROUP_REQUIRED = 'rental_set_group_required';

    /**
     * Group's multiple_selection flag — informational on cart side.
     */
    const KEY_GROUP_MULTIPLE_SELECTION = 'rental_set_group_multiple_selection';

    /**
     * Group's display name — shown in cart/email/admin to give the
     * customer context ("Chairs: Charcoal Gray Folding Chairs").
     */
    const KEY_GROUP_NAME = 'rental_set_group_name';

    /**
     * WP product id of the SET ITEM an add-on line hangs off. Stamped at
     * add-to-cart time by the legacy child builder. Present ONLY on add-on
     * lines — a plain set item, a variant choice and a group option never
     * carry it — which is what makes it the discriminator for "this line
     * is an add-on" as opposed to "this product happens to be flagged as
     * an add-on product".
     */
    const KEY_ADDON_PARENT_PRODUCT = 'parent_set_item_product_id';

    /**
     * All composite-GROUP keys this class manages, in a stable order. Used
     * by the order-line-item copy step so we don't have to enumerate them
     * inline.
     *
     * @return string[]
     */
    public static function all_keys() {
        return array(
            self::KEY_IS_GROUP_CHILD,
            self::KEY_GROUP_ID,
            self::KEY_GROUP_UID,
            self::KEY_GROUP_ITEM_UID,
            self::KEY_GROUP_PRICE,
            self::KEY_GROUP_QTY,
            self::KEY_GROUP_REQUIRED,
            self::KEY_GROUP_MULTIPLE_SELECTION,
            self::KEY_GROUP_NAME,
        );
    }

    /**
     * Order-item meta key prefix. Every cart-item key is mirrored to
     * `_<key>` on the order line so it stays hidden from the WC default
     * admin-meta UI.
     *
     * @return string
     */
    public static function order_item_meta_prefix() {
        return '_';
    }

    /**
     * Convert a cart-item key to its order-item-meta counterpart.
     *
     * @param string $cart_key
     * @return string
     */
    public static function to_order_item_key( $cart_key ) {
        return self::order_item_meta_prefix() . $cart_key;
    }

    /**
     * Add-on parentage keys mirrored onto the order line. Separate from
     * all_keys() because the two groups describe different line kinds and
     * are copied under different conditions.
     *
     * @return string[]
     */
    public static function addon_parent_keys() {
        return array(
            self::KEY_ADDON_PARENT_PRODUCT,
        );
    }

    /**
     * Quick test: is this cart line a composite-group child?
     *
     * @param array $cart_item
     * @return bool
     */
    public static function is_group_child( $cart_item ) {
        return ! empty( $cart_item[ self::KEY_IS_GROUP_CHILD ] );
    }

    /**
     * Quick test: is this cart line an add-on attached to a set item?
     *
     * @param array $cart_item
     * @return bool
     */
    public static function is_set_item_addon( $cart_item ) {
        return ! empty( $cart_item[ self::KEY_ADDON_PARENT_PRODUCT ] );
    }

    /**
     * Read a single value from a cart-item array. Returns $default when
     * the key is absent — never throws.
     *
     * @param array  $cart_item
     * @param string $key       One of the constants above.
     * @param mixed  $default
     * @return mixed
     */
    public static function read( $cart_item, $key, $default = null ) {
        return isset( $cart_item[ $key ] ) ? $cart_item[ $key ] : $default;
    }

    /**
     * Build the canonical group-meta payload from raw POST input. Used by
     * the cart-handler when it splits the `rental_add_ons[]` array. The
     * input shape mirrors the entity-3 fields the modern renderer's
     * hidden inputs emit. Pure transform with no UI source — the JS
     * controller is responsible for shipping a complete payload.
     *
     * Anything missing from $source is dropped silently. The cart handler
     * will only set KEY_IS_GROUP_CHILD when this returns a non-empty map.
     *
     * @param array $source Raw fields off one `rental_add_ons` entry.
     * @return array<string,mixed>
     */
    public static function build_from_input( array $source ) {
        $out = array();

        if ( ! empty( $source['rental_set_group_is_child'] ) || ( isset( $source['item_type'] ) && 'grouped_child' === $source['item_type'] ) ) {
            $out[ self::KEY_IS_GROUP_CHILD ] = 1;
        } else {
            // Not a group child — return nothing so the handler doesn't
            // tag this cart line.
            return array();
        }

        if ( isset( $source['rental_set_group_id'] ) ) {
            $out[ self::KEY_GROUP_ID ] = (int) $source['rental_set_group_id'];
        }
        if ( isset( $source['rental_set_group_uid'] ) ) {
            $out[ self::KEY_GROUP_UID ] = (string) $source['rental_set_group_uid'];
        }
        if ( isset( $source['rental_set_group_item_uid'] ) ) {
            $out[ self::KEY_GROUP_ITEM_UID ] = (string) $source['rental_set_group_item_uid'];
        }
        if ( isset( $source['rental_set_group_price'] ) && '' !== $source['rental_set_group_price'] ) {
            $out[ self::KEY_GROUP_PRICE ] = $source['rental_set_group_price'];
        }
        if ( isset( $source['rental_set_group_qty'] ) ) {
            $out[ self::KEY_GROUP_QTY ] = (int) $source['rental_set_group_qty'];
        }
        if ( isset( $source['rental_set_group_required'] ) ) {
            $out[ self::KEY_GROUP_REQUIRED ] = $source['rental_set_group_required'] ? 1 : 0;
        }
        if ( isset( $source['rental_set_group_multiple_selection'] ) ) {
            $out[ self::KEY_GROUP_MULTIPLE_SELECTION ] = $source['rental_set_group_multiple_selection'] ? 1 : 0;
        }
        if ( isset( $source['rental_set_group_name'] ) ) {
            $out[ self::KEY_GROUP_NAME ] = (string) $source['rental_set_group_name'];
        }

        return apply_filters( 'rental_sets_modern_cart_item_data', $out, $source );
    }
}

endif;
