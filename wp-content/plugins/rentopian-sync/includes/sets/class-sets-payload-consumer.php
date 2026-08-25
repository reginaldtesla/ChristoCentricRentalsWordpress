<?php
/**
 * Rental_Sets_Payload_Consumer
 *
 *
 * Runs at `woocommerce_add_to_cart_validation` priority 8 — between the
 * Payload_Parser's observer (priority 5) and the legacy synthesizer in
 * `rental_validate_cart_item` (hooks via WC's own runner at priority 10).
 * When a v2 payload is present and validates against the schema, this
 * class REBUILDS `$_POST['rental_add_ons']` from the v2 picks so the
 * legacy synthesizer downstream consumes a single, browser-controlled
 * source of truth.
 *
 * What the rebuild replaces: the historically fragile nested-form
 * shape where the JS layer had to construct legacy `rental_add_ons[i]`
 * entries (selectable variant picks, addon variant picks, full group
 * children) directly. With this class enabled the JS can emit ONLY the
 * v2 payload and this consumer materialises the legacy shape server-
 * side. The legacy nested shape remains the internal contract between
 * this consumer and the legacy synthesizer — only the BROWSER-FACING
 * input shape gets simplified.
 *
 * Safety properties:
 *
 *   - Always passes `$passed` through unchanged. CANNOT block a cart add.
 *   - No-ops when:
 *       * the v2 payload isn't present
 *       * schema validation fails
 *       * the product isn't a set
 *       * the `rental_sets_payload_consume_enabled` filter returns false
 *     In each of these cases the legacy synthesizer sees exactly the
 *     same `$_POST['rental_add_ons']` it would have seen without this
 *     class loaded — byte-identical.
 *
 *   - Never throws. Any error in conversion is caught + logged, and
 *     `$_POST['rental_add_ons']` is left untouched so the legacy path
 *     still runs.
 *
 *   - Stamps `$_POST['__rental_set_payload_consumed'] = 1` so downstream
 *     code can branch on the new source-of-truth when relevant
 *     (currently only used by the payload-diff observer at priority 11
 *     to know it's comparing post-consume).
 *
 * Filter:
 *   `rental_sets_payload_consume_enabled` (bool, default true)
 *     Set to false to disable per-request — useful as a kill-switch
 *     during incident response.
 *
 * @package RentopianSync\Sets
 * @since   2.15.0
 *
 * PHP requirement: 7.4+
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'Rental_Sets_Payload_Consumer', false ) ) :

class Rental_Sets_Payload_Consumer {

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
        // Priority 8 — AFTER Payload_Parser::observe_add_to_cart (5)
        // captures the raw browser submission for the observer log,
        // BEFORE the legacy synthesizer at WC's priority 10 reads
        // $_POST['rental_add_ons'].
        add_filter( 'woocommerce_add_to_cart_validation', array( $this, 'consume' ), 8, 3 );
    }

    /**
     * Convert the v2 payload to legacy nested shape and stamp it onto
     * `$_POST['rental_add_ons']`. See class header for the safety
     * properties.
     *
     * @param bool $passed
     * @param int  $product_id
     * @param int  $quantity
     * @return bool
     */
    public function consume( $passed, $product_id, $quantity ) {
        try {
            if ( ! apply_filters( 'rental_sets_payload_consume_enabled', true, $product_id, $quantity ) ) {
                return $passed;
            }
            if ( ! get_post_meta( (int) $product_id, '_rental_is_set', true ) ) {
                return $passed;
            }

            $payload = Rental_Sets_Payload_Parser::parse_from_post();
            if ( null === $payload ) {
                return $passed;
            }
            $issues = Rental_Sets_Payload_Parser::validate_schema( $payload );
            if ( ! empty( $issues ) ) {
                $this->log_skip( $product_id, 'schema_invalid', array( 'issues' => implode( ',', $issues ) ) );
                return $passed;
            }

            $derived = Rental_Sets_Payload_Parser::build_legacy_add_ons_from_payload( $payload );

            $before_count = isset( $_POST['rental_add_ons'] ) && is_array( $_POST['rental_add_ons'] )
                ? count( $_POST['rental_add_ons'] )
                : 0;

            // Always stamp the derived shape — including empty array
            // when v2 says "no picks" — so the legacy synthesizer sees
            // a deterministic input. If the schema validates but
            // produces zero entries (e.g. a set with only simple
            // items), that's correct: the synthesizer will fill simples
            // from postmeta on its own.
            $_POST['rental_add_ons']                  = $derived;
            $_POST['__rental_set_payload_consumed']   = 1;

            $this->log_consume( $product_id, $before_count, $derived, $payload );
        } catch ( \Throwable $e ) {
            // Conversion bug must not regress the cart. Leave
            // $_POST['rental_add_ons'] alone; the legacy synthesizer
            // will fall back to its prior behaviour.
            $this->log_skip( $product_id, 'exception', array( 'message' => $e->getMessage() ) );
        }
        return $passed;
    }

    /**
     * Structured log of a successful consume — counts of each kind so
     * the operator can spot-check shape against the diff observer log
     * at priority 11.
     *
     * @param int   $product_id
     * @param int   $before_count
     * @param array $derived
     * @param array $payload
     * @return void
     */
    protected function log_consume( $product_id, $before_count, array $derived, array $payload ) {
        if ( ! class_exists( 'Rental_Sets_Logger', false ) ) {
            return;
        }

        $group_kids = 0;
        $non_group  = 0;
        $addons     = 0;
        foreach ( $derived as $entry ) {
            if ( ! is_array( $entry ) ) {
                continue;
            }
            $is_group = ( isset( $entry['item_type'] ) && 'grouped_child' === $entry['item_type'] )
                || ! empty( $entry['rental_set_group_is_child'] );
            if ( $is_group ) {
                $group_kids++;
            } elseif ( ! empty( $entry['parent_set_item_product_id'] ) ) {
                $addons++;
            } else {
                $non_group++;
            }
        }

        Rental_Sets_Logger::warn(
            'payload_consume',
            sprintf(
                'replaced rental_add_ons: %d -> %d entries (group_kids=%d non_group=%d addons=%d)',
                (int) $before_count,
                count( $derived ),
                $group_kids,
                $non_group,
                $addons
            ),
            array(
                'set'         => (int) $product_id,
                'before'      => (int) $before_count,
                'after'       => count( $derived ),
                'group_kids'  => $group_kids,
                'non_group'   => $non_group,
                'addons'      => $addons,
                'p_groups'    => isset( $payload['groups'] )      && is_array( $payload['groups'] )      ? count( $payload['groups'] )      : 0,
                'p_simples'   => isset( $payload['simples'] )     && is_array( $payload['simples'] )     ? count( $payload['simples'] )     : 0,
                'p_addons'    => isset( $payload['addons'] )      && is_array( $payload['addons'] )      ? count( $payload['addons'] )      : 0,
            )
        );
    }

    /**
     * Structured log of a skip — schema invalid, exception, or filter
     * opt-out. Helps the operator see why a submission fell back to
     * legacy.
     *
     * @param int    $product_id
     * @param string $reason
     * @param array  $context
     * @return void
     */
    protected function log_skip( $product_id, $reason, array $context = array() ) {
        if ( ! class_exists( 'Rental_Sets_Logger', false ) ) {
            return;
        }
        Rental_Sets_Logger::warn(
            'payload_consume_skip',
            $reason,
            array_merge( array( 'set' => (int) $product_id, 'reason' => $reason ), $context )
        );
    }
}

endif;
