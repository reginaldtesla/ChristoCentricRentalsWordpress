<?php
/**
 * Rental_Sets_Rule_Group_Required
 *
 * Composite groups marked `required=1` must have at least one child
 * with quantity > 0 selected. Hidden groups (`hide_on_website=1`) are
 * exempt — the customer can't see them and their defaults are auto-
 * applied at submit by the renderer.
 *
 * Stops at the first failure within a single group (one error per
 * unfilled required group, not one error per missing child).
 *
 * @package RentopianSync\Sets\Validation
 * @since   2.14.7
 *
 * PHP requirement: 7.4+
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'Rental_Sets_Rule_Group_Required', false ) ) :

class Rental_Sets_Rule_Group_Required extends Rental_Sets_Validation_Rule_Base {

    public function id() { return 'group_required'; }

    public function check( Rental_Sets_Validator_Context $ctx, Rental_Sets_Validation_Result $result ) {

        foreach ( $ctx->grouped_items() as $group ) {

            if ( empty( $group['required'] ) ) {
                continue;
            }
            if ( ! empty( $group['hide_on_website'] ) ) {
                // Hidden — defaults auto-fulfill; renderer never asks the user.
                continue;
            }

            $group_uid  = isset( $group['uid'] ) ? (string) $group['uid'] : '';
            $selections = $this->effective_selections( $ctx->selections_for_group( $group_uid ) );

            if ( ! empty( $selections ) ) {
                continue;
            }

            $label = $this->group_label( $group );
            $msg   = sprintf(
                /* translators: %s = group display name */
                __( 'Please choose an option for "%s" (required).', 'rentopian-sync' ),
                esc_html( $label )
            );

            $result->add_error(
                Rental_Sets_Validation_Result::ERR_GROUP_REQUIRED,
                $msg,
                array(
                    'group_uid'  => $group_uid,
                    'group_name' => $label,
                )
            );
        }
    }
}

endif;
