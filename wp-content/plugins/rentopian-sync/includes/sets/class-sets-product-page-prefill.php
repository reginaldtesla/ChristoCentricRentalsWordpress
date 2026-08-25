<?php
/**
 * Rental_Sets_Product_Page_Prefill
 *
 * Detects when a customer arrives on a set's product page and that set
 * already has a parent line in the cart, then exposes the existing
 * selections to the renderer / JS via a `window.RentalSetsPrefill`
 * inline object.
 *
 * Two pre-fill triggers:
 *
 *   1. Edit mode: explicit `?rental_edit_cart=<KEY>` query var. We read
 *      that exact line.
 *
 *   2. Auto pre-fill: the customer just visits the product page and a
 *      cart line for the same set already exists. We pick the first
 *      matching parent line.
 *
 * The exposed payload includes:
 *   - parent_qty          — current cart qty for the line
 *   - cart_item_key       — for the form's hidden edit field
 *   - is_edit_mode        — bool
 *   - children            — array of {product_id, variation_id, quantity, ...} per existing child line
 *   - selected_set_options— the stored `rental_selected_set_options`
 *
 * Filter exposed: `rental_sets_prefill_data` — last-mile mutation of
 * the payload before it's serialised to JS.
 *
 * The modern renderer's JS consumes `window.RentalSetsPrefill` to populate the
 * form fields. This class only emits data; it doesn't render the form.
 *
 * @package RentopianSync\Sets
 * @since   2.14.7
 *
 * PHP requirement: 7.4+
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'Rental_Sets_Product_Page_Prefill', false ) ) :

class Rental_Sets_Product_Page_Prefill {

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
        // wp_footer is reliable for inline JS on the product page.
        add_action( 'wp_footer', array( $this, 'emit_prefill' ), 5 );
    }

    /**
     * Emit the inline JS payload when conditions match.
     *
     * @return void
     */
    public function emit_prefill() {
        if ( ! function_exists( 'is_product' ) || ! is_product() ) {
            return;
        }

        $set_id = get_queried_object_id();
        if ( ! $set_id || ! get_post_meta( $set_id, '_rental_is_set', true ) ) {
            return;
        }

        if ( ! function_exists( 'WC' ) || ! WC() || ! WC()->cart ) {
            return;
        }

        $payload = $this->build_payload( $set_id );
        if ( null === $payload ) {
            return;
        }

        $payload = apply_filters( 'rental_sets_prefill_data', $payload, $set_id );

        // Inline JS — the renderer's JS reads window.RentalSetsPrefill.
        // Use wp_json_encode to escape correctly.
        $json = wp_json_encode( $payload );
        if ( false === $json ) {
            return;
        }

        echo '<script type="text/javascript">window.RentalSetsPrefill = ' . $json . ';</script>';

        $cart_prefill_present = ! empty( $payload['cart_prefill'] );
        $children_count       = $cart_prefill_present ? count( $payload['cart_prefill']['children'] ) : 0;
        $is_edit              = $cart_prefill_present && ! empty( $payload['cart_prefill']['is_edit_mode'] );

        Project_WP_Logger::write(
            sprintf(
                'Product_Page_Prefill: emitted prefill for set %d (cart_prefill=%s, edit_mode=%s, children=%d, failed_selections=%s).',
                (int) $set_id,
                $cart_prefill_present ? 'yes' : 'no',
                $is_edit ? 'yes' : 'no',
                $children_count,
                ! empty( $payload['failed_selections'] ) ? 'yes' : 'no'
            ),
            'info',
            $this->log_source
        );
    }

    /**
     * Build the JS-ready payload. Always returns a payload on a set
     * product page — `cart_prefill` is null when no parent line exists
     * in the cart, but `rules` and `failed_selections` always emit so
     * the renderer can drive client-side validation and recover failed
     * submissions.
     *
     * @param int $set_id
     * @return array|null
     */
    protected function build_payload( $set_id ) {

        $base = array(
            'set_id'           => (int) $set_id,
            'cart_prefill'     => null,
            'rules'            => Rental_Sets_Rules_Exporter::build_for_set( $set_id ),
            'failed_selections'=> $this->resolve_failed_selections( $set_id ),
        );

        $parent_key = $this->resolve_parent_key( $set_id );
        if ( ! $parent_key ) {
            // No matching cart line — rules + failed_selections still useful.
            return $base;
        }

        $contents = WC()->cart->cart_contents;
        if ( ! isset( $contents[ $parent_key ] ) ) {
            return $base;
        }

        $parent = $contents[ $parent_key ];

        $is_edit_mode = false;
        $edit_inst = Rental_Sets_Cart_Edit_Mode::instance();
        if ( null !== $edit_inst && $edit_inst->get_editing_key() === $parent_key ) {
            $is_edit_mode = true;
        }

        // Walk children of this parent line.
        $children = array();
        foreach ( $contents as $key => $item ) {
            if ( ! isset( $item['rental_add_on_of'] ) ) {
                continue;
            }
            if ( $item['rental_add_on_of'] !== $parent_key ) {
                continue;
            }

            $children[] = array(
                'cart_item_key'  => $key,
                'product_id'     => isset( $item['product_id'] ) ? (int) $item['product_id'] : 0,
                'variation_id'   => isset( $item['variation_id'] ) ? (int) $item['variation_id'] : 0,
                'quantity'       => isset( $item['quantity'] ) ? (int) $item['quantity'] : 0,
                'rental_set_id'  => isset( $item['rental_set_id'] ) ? (int) $item['rental_set_id'] : 0,
                'set_id'         => isset( $item['set_id'] ) ? (int) $item['set_id'] : 0,

                // Group context — empty for classic children.
                'group_meta'     => function_exists( 'rental_sets_cart_item_group_meta' )
                    ? rental_sets_cart_item_group_meta( $item )
                    : array(),

                // Parent set item identifiers (for selectable items).
                'parent_set_item_product_id' => isset( $item['parent_set_item_product_id'] ) ? (int) $item['parent_set_item_product_id'] : 0,
                'parent_set_item_variant_id' => isset( $item['parent_set_item_variant_id'] ) ? (int) $item['parent_set_item_variant_id'] : 0,
            );
        }

        $base['cart_prefill'] = array(
            'cart_item_key'         => $parent_key,
            'is_edit_mode'          => $is_edit_mode,
            'parent_qty'            => isset( $parent['quantity'] ) ? (int) $parent['quantity'] : 0,
            'parent_product_id'     => isset( $parent['product_id'] ) ? (int) $parent['product_id'] : 0,
            'rental_set_id'         => isset( $parent['rental_set_id'] ) ? (int) $parent['rental_set_id'] : 0,
            'selected_set_options'  => isset( $parent['rental_selected_set_options'] ) ? $parent['rental_selected_set_options'] : array(),
            'children'              => $children,
        );

        return $base;
    }

    /**
     * Read any cached failed-selections blob the validator stashed on
     * the previous request. Returns null when nothing was stashed (the
     * normal first-visit case).
     *
     * @param int $set_id
     * @return array|null
     */
    protected function resolve_failed_selections( $set_id ) {
        $validator = Rental_Sets_Cart_Validator::instance();
        if ( null === $validator ) {
            return null;
        }
        return $validator->read_persisted_selections( $set_id );
    }

    /**
     * Resolve which cart line to prefill from. Edit-mode wins; otherwise
     * fall back to the first existing parent line for the same WP set
     * product (per F2: same WP product id is the merge key).
     *
     * @param int $set_id WP product id of the current set.
     * @return string|null Cart item key, or null.
     */
    protected function resolve_parent_key( $set_id ) {
        // Edit mode wins.
        $edit_inst = Rental_Sets_Cart_Edit_Mode::instance();
        if ( null !== $edit_inst ) {
            $key = $edit_inst->get_editing_key();
            if ( $key && isset( WC()->cart->cart_contents[ $key ] ) ) {
                return $key;
            }
        }

        // Auto: first parent line for this set product.
        foreach ( WC()->cart->cart_contents as $key => $item ) {
            if ( ! empty( $item['rental_add_on_of'] ) ) {
                continue;
            }
            if ( ! isset( $item['product_id'] ) ) {
                continue;
            }
            if ( (int) $item['product_id'] !== (int) $set_id ) {
                continue;
            }
            return $key;
        }

        return null;
    }
}

endif;
