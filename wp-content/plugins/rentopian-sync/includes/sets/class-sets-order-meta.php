<?php
/**
 * Rental_Sets_Order_Meta
 *
 * Copies a cart line's set context onto the corresponding order line item
 * at checkout: the composite-group meta for group children, and the
 * add-on parentage for add-ons attached to a set item. Order-side meta is
 * keyed with a leading underscore so it stays hidden from the WC default
 * order-meta admin display.
 *
 * Two ways orders get created:
 *
 *   1. Standard checkout: `woocommerce_checkout_create_order_line_item`
 *      fires once per line item with the cart item array.
 *   2. Manual / programmatic: covered by hooking
 *      `woocommerce_new_order_item` as a fallback — looks up the cart
 *      line via cart_item_key.
 *
 * Reading the meta back later: `wc_get_order_item_meta( $item_id, $key )`
 * with the underscore-prefixed key.
 *
 * @package RentopianSync\Sets
 * @since   2.14.7
 *
 * PHP requirement: 7.4+
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'Rental_Sets_Order_Meta', false ) ) :

class Rental_Sets_Order_Meta {

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

    protected function boot() {
        // Standard checkout path.
        add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'on_checkout_create_order_line_item' ), 20, 4 );
    }

    /**
     * Mirror the cart line's set context onto the order line. The line item
     * exposes add_meta_data(), which goes through the WC data store
     * and stays consistent with `wc_get_order_item_meta` reads later.
     *
     * Two mutually exclusive kinds are handled:
     *
     *   - group children  → the composite-group context.
     *   - set-item addons → the parentage that identifies WHICH set item
     *     the line hangs off. Without it an order line is just
     *     "product X, variant Y", so order-side rules can't tell an add-on
     *     apart from an ordinary line of the same product — and one product
     *     can now be both in a single set.
     *
     * @param WC_Order_Item_Product $item
     * @param string                $cart_item_key
     * @param array                 $values   The cart item array.
     * @param WC_Order              $order
     * @return void
     */
    public function on_checkout_create_order_line_item( $item, $cart_item_key, $values, $order ) {
        $copied = 0;

        if ( Rental_Sets_Cart_Meta::is_group_child( $values ) ) {
            $copied += $this->copy_keys( $item, $values, Rental_Sets_Cart_Meta::all_keys() );
        }

        if ( Rental_Sets_Cart_Meta::is_set_item_addon( $values ) ) {
            $copied += $this->copy_keys( $item, $values, Rental_Sets_Cart_Meta::addon_parent_keys() );
        }

        if ( 0 === $copied ) {
            return;
        }

        Project_WP_Logger::write(
            sprintf(
                'Order_Meta: persisted %d set meta field(s) for order line of product %s in order %s.',
                (int) $copied,
                (string) $item->get_product_id(),
                (string) $order->get_id()
            ),
            'info',
            $this->log_source
        );
    }

    /**
     * Copy a set of cart-item keys onto the order line, underscore-prefixed
     * so WC treats them as internal and leaves them out of default
     * rendering. Absent keys are skipped.
     *
     * @param WC_Order_Item_Product $item
     * @param array                 $values Cart item array.
     * @param string[]              $keys
     * @return int Number of fields written.
     */
    protected function copy_keys( $item, array $values, array $keys ) {
        $written = 0;
        foreach ( $keys as $key ) {
            if ( ! isset( $values[ $key ] ) ) {
                continue;
            }
            $item->add_meta_data(
                Rental_Sets_Cart_Meta::to_order_item_key( $key ),
                $values[ $key ],
                true // unique
            );
            $written++;
        }
        return $written;
    }
}

endif;
