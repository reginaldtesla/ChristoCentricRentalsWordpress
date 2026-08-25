<?php
/**
 * Rental_Sets_Rule_Archive_Context
 *
 * Replaces the standalone Archive_Guard. When the request originates
 * from a non-product page (shop, category, search, cart upsell, etc.),
 * any set that needs configuration triggers an error. The orchestrator
 * surfaces it as a notice and the legacy redirect path runs from there.
 *
 * "Needs configuration" mirrors what Archive_Guard had:
 *   - Set has options (clean_before / clean_after style).
 *   - Set has any item with `optional_items` requiring choice.
 *   - Set has any addon with `variants_optional` and no chosen variant.
 *   - Set has any composite group with `required=1`.
 *   - Set has any composite group with `multiple_selection=1`.
 *   - Set has any composite group whose default selection doesn't
 *     satisfy `group_quantity_min`.
 *
 * Filter `rental_sets_needs_configuration` is preserved.
 *
 * @package RentopianSync\Sets\Validation
 * @since   2.14.7
 *
 * PHP requirement: 7.4+
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'Rental_Sets_Rule_Archive_Context', false ) ) :

class Rental_Sets_Rule_Archive_Context extends Rental_Sets_Validation_Rule_Base {

    public function id() { return 'archive_context'; }

    public function check( Rental_Sets_Validator_Context $ctx, Rental_Sets_Validation_Result $result ) {

        if ( ! $ctx->is_from_archive() ) {
            return;
        }

        $needs = $this->compute_needs_configuration( $ctx );
        $needs = (bool) apply_filters( 'rental_sets_needs_configuration', $needs['needed'], $ctx->set_id(), $needs['reason'] );

        if ( ! $needs ) {
            return;
        }

        $permalink = get_permalink( $ctx->set_id() );
        $title     = get_the_title( $ctx->set_id() );

        $msg = sprintf(
            /* translators: %s = product title (linked) */
            __( 'Please configure your selections for %s before adding to cart.', 'rentopian-sync' ),
            sprintf( '<a href="%s">%s</a>', esc_url( $permalink ), esc_html( $title ) )
        );

        $result->add_error(
            Rental_Sets_Validation_Result::ERR_ARCHIVE_NEEDS_CONFIG,
            $msg,
            array(
                'redirect_to' => $permalink,
            )
        );
    }

    /**
     * Identical to the original Archive_Guard implementation, kept here
     * so the rule is self-contained.
     *
     * @param Rental_Sets_Validator_Context $ctx
     * @return array { needed: bool, reason: string }
     */
    protected function compute_needs_configuration( Rental_Sets_Validator_Context $ctx ) {

        if ( function_exists( 'get_set_options' ) ) {
            $opts = get_set_options( $ctx->set_id() );
            if ( ! empty( $opts ) ) {
                return array( 'needed' => true, 'reason' => 'set_options_present' );
            }
        }

        foreach ( $ctx->set_items() as $item ) {
            if ( ! empty( $item['optional_items'] )
                && ( empty( $item['has_selected'] ) || count( $item['optional_items'] ) > 1 ) ) {
                return array( 'needed' => true, 'reason' => 'item_optional_items' );
            }
            if ( ! empty( $item['addons'] ) && is_array( $item['addons'] ) ) {
                foreach ( $item['addons'] as $addon ) {
                    if ( empty( $addon['variant_id'] ) && ! empty( $addon['variants_optional'] ) ) {
                        return array( 'needed' => true, 'reason' => 'addon_variants_optional' );
                    }
                }
            }
        }

        foreach ( $ctx->grouped_items() as $group ) {
            if ( ! empty( $group['required'] ) ) {
                return array( 'needed' => true, 'reason' => 'group_required' );
            }
            if ( ! empty( $group['multiple_selection'] ) ) {
                return array( 'needed' => true, 'reason' => 'group_multiple_selection' );
            }
            $min = isset( $group['group_quantity_min'] ) ? (int) $group['group_quantity_min'] : 0;
            if ( $min > 0 && ! $this->group_default_satisfies_min( $group, $min ) ) {
                return array( 'needed' => true, 'reason' => 'group_min_qty_unsatisfied' );
            }
        }

        return array( 'needed' => false, 'reason' => '' );
    }

    /**
     * @param array $group
     * @param int   $min
     * @return bool
     */
    protected function group_default_satisfies_min( $group, $min ) {
        if ( empty( $group['items'] ) || ! is_array( $group['items'] ) ) {
            return false;
        }
        $sum = 0;
        foreach ( $group['items'] as $item ) {
            if ( ! empty( $item['is_default'] ) ) {
                $sum += isset( $item['quantity'] ) ? (int) $item['quantity'] : 0;
            }
        }
        return $sum >= $min;
    }
}

endif;
