<?php
/**
 * Rental_Sets_Processor
 *
 * Orchestrates one pass over the sets payload from the API. For each set:
 *
 *   1. Allocates the next wp post id (continuing the counter the variant
 *      pass left off at).
 *   2. Decodes the four up-sell / cross-sell lists so the post-insert
 *      step can resolve them to wp ids once all set ids are known.
 *   3. Collects the set id into the non-discountable list when applicable.
 *   4. Numbers the slug if it collides with an existing product slug.
 *   5. Runs the item-builder to produce the `$set_items` payload.
 *   6. Runs the sql-builder to produce the posts / postmeta / relation
 *      rows and collects them into the result.
 *   7. Records any images referenced so the image sync picks them up.
 *   8. Tracks which wp product/variant ids belong to sets so the caller
 *      can fold them into `$set_items_collection`.
 *
 * The top-level `rental_set_items_have_addons` option is reset at the
 * start of a pass and flipped to true the first time we encounter any
 * addon.
 *
 * @package RentopianSync\Sets
 * @since   2.14.7
 *
 * PHP requirement: 7.4+
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'Rental_Sets_Processor', false ) ) :

class Rental_Sets_Processor {

    /** @var Rental_Sets_Sync_Context */
    protected $ctx;

    /** @var Rental_Sets_Item_Builder */
    protected $item_builder;

    /** @var Rental_Sets_Group_Builder */
    protected $group_builder;

    /** @var Rental_Sets_Sql_Builder */
    protected $sql_builder;

    /** @var string */
    protected $log_source = 'rentopian-sets-sync';

    public function __construct(
        Rental_Sets_Sync_Context $ctx,
        Rental_Sets_Item_Builder $item_builder = null,
        Rental_Sets_Sql_Builder $sql_builder = null,
        Rental_Sets_Group_Builder $group_builder = null
    ) {
        $this->ctx           = $ctx;
        $this->item_builder  = $item_builder  ?: new Rental_Sets_Item_Builder();
        $this->sql_builder   = $sql_builder   ?: new Rental_Sets_Sql_Builder();
        $this->group_builder = $group_builder ?: new Rental_Sets_Group_Builder();
    }

    /**
     * Run the pass and return the result.
     *
     * @return Rental_Sets_Sync_Result
     */
    public function run() {
        $token = Project_WP_Logger::start( 'sets_processor_run' );

        $result = new Rental_Sets_Sync_Result();
        
        $result->seed_coupons_excluded_ids( $this->ctx->coupons_excluded_product_ids() );
        $result->set_products_slug( $this->ctx->products_slug() );
        $result->set_rental_img_variant_rel( $this->ctx->rental_img_variant_rel() );

        // Reset the addon flag at the start of every pass. The option is
        // flipped true later if any set item carries an addon.
        update_option( 'rental_set_items_have_addons', false );

        $set_id       = $this->ctx->starting_id();
        $simple_tt_id = $this->ctx->simple_tt_id();

        foreach ( $this->ctx->sets() as $set ) {
            $set_id++;
            $result->map_set_id( $set->id, $set_id );

            // Decode the four up/cross-sell buckets. Kept as rental ids
            // here — the up/cross-sells processor resolves them to wp ids
            // after all set ids in this pass are known.
            $result->record_up_sells_from_sets(
                $set_id,
                ( isset( $set->up_sells_sets ) && ! empty( $set->up_sells_sets ) ) ? json_decode( $set->up_sells_sets, true ) : array()
            );
            $result->record_up_sells_from_products(
                $set_id,
                ( isset( $set->up_sells_products ) && ! empty( $set->up_sells_products ) ) ? json_decode( $set->up_sells_products, true ) : array()
            );
            $result->record_cross_sells_from_sets(
                $set_id,
                ( isset( $set->cross_sells_sets ) && ! empty( $set->cross_sells_sets ) ) ? json_decode( $set->cross_sells_sets, true ) : array()
            );
            $result->record_cross_sells_from_products(
                $set_id,
                ( isset( $set->cross_sells_products ) && ! empty( $set->cross_sells_products ) ) ? json_decode( $set->cross_sells_products, true ) : array()
            );

            $price_multiplier_id = empty( $set->price_multiplier_id ) ? 0 : $set->price_multiplier_id;

            // Non-discountable sets get excluded from every coupon later.
            if ( $set->discountable == 0 ) {
                $result->add_coupons_excluded_id( $set_id );
            }

            // Resolve slug. The counter is shared across products/variants
            // and sets — that's why we read/bump via the result instead of
            // holding a local array.
            $base_slug = sanitize_title( $set->title );
            $seen      = $result->bump_products_slug( $base_slug );
            $slug      = $seen > 1 ? $base_slug . '-' . $seen : $base_slug;

            // posts row + set_relations row + simple-type term relation.
            $result->add_product_sql_fragment(
                $set_id,
                $this->sql_builder->posts_row( $set, $set_id, $slug, $this->ctx )
            );
            $result->append_set_relations_sql_row( $this->sql_builder->set_relations_row( $set, $set_id ) );
            // $result->append_term_relation_sql_row( $this->sql_builder->simple_term_relation_row( $set, $set_id ) );
            $result->append_term_relation_sql_row( $this->sql_builder->simple_term_relation_row( $set_id, $simple_tt_id ) );

            // If the set has a main image id, register it in the image map
            // so the image sync attaches the attachment to this set.
            if ( ! empty( $set->img_id ) ) {
                $result->register_img_for_set( $set->img_id, $set_id );
            }

            // Build items + flags. This is the bulk of the work: optional
            // items, addons and nested variants_optional all resolve here.
            $built = $this->item_builder->build( $set, $set_id, $this->ctx );
            $items              = $built['items'];
            $has_optional_items = (bool) $built['has_optional_items'];
            $has_hidden_items   = (bool) $built['has_hidden_items'];
            $has_addons         = (bool) $built['has_addons'];

            // Mark each wp id the builder resolved as belonging to this
            // set, so downstream code can short-circuit its own checks.
            foreach ( $built['marker_ids'] as $wp_id ) {
                $result->mark_set_item( $wp_id );
            }

            // Build composite/grouped items — the third entity. Independent
            // of $items, lives in its own postmeta key, but its component
            // wp ids are added to the same set_items_collection so cart and
            // checkout code still recognises them as parts of a set.
            $built_groups      = $this->group_builder->build( $set, $set_id, $this->ctx );
            $groups            = $built_groups['groups'];
            $has_groups        = (bool) $built_groups['has_groups'];
            $has_hidden_groups = (bool) $built_groups['has_hidden_groups'];

            foreach ( $built_groups['marker_ids'] as $wp_id ) {
                $result->mark_set_item( $wp_id );
            }

            // Flip the global addon flag once we first see one.
            if ( $has_addons ) {
                $result->mark_any_set_has_addons();
                if ( ! get_option( 'rental_set_items_have_addons', false ) ) {
                    update_option( 'rental_set_items_have_addons', true );
                }
            }

            // postmeta + wc lookup rows. Modern-layout payload is always
            // emitted; readers get safe defaults when classic mode is
            // active or the server didn't send any grouped data.
            $result->add_productmeta_sql_fragment(
                $set_id,
                $this->sql_builder->postmeta_rows(
                    $set,
                    $set_id,
                    $items,
                    array(
                        'has_optional_items' => $has_optional_items,
                        'has_hidden_items'   => $has_hidden_items,
                        'has_groups'         => $has_groups,
                        'has_hidden_groups'  => $has_hidden_groups,
                    ),
                    $price_multiplier_id,
                    array(
                        'groups'      => $groups,
                        'set_order'   => isset( $set->set_order ) ? $set->set_order : array(),
                        'layout_mode' => Rental_Sets_Admin_Settings::get_layout_mode(),
                    )
                )
            );

            if ( $this->ctx->has_product_meta_lookup_sql() ) {
                $result->add_product_meta_lookup_sql_fragment(
                    $set_id,
                    $this->sql_builder->product_meta_lookup_row( $set, $set_id )
                );
            }
        }

        $result->set_last_set_id( $set_id );

        // Persist the image map so subsequent steps (image sync) read the
        // updated version. The outer function used to do this after the
        // loop — keeping the write here so this pass is self-contained.
        update_option( 'rental_img_variant_rel', $result->rental_img_variant_rel() );

        Project_WP_Logger::stop(
            $token,
            'Rental_Sets_Processor::run',
            'info',
            $this->log_source,
            0,
            sprintf(
                'Processed %d sets; last_set_id=%d; any_addons=%s.',
                count( $this->ctx->sets() ),
                $set_id,
                $result->any_set_has_addons() ? 'yes' : 'no'
            )
        );

        return $result;
    }
}

endif;
