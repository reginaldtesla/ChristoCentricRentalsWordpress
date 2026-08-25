<?php
/**
 * Rental_Sets_Sync_Result
 *
 * Collects everything the sets sync pass produces. The processor populates
 * an instance as it iterates; the caller reads the getters afterward and
 * merges each piece back into its running arrays.
 *
 * This replaces the nine-ish parallel `$set_*` variables that the legacy
 * inline loop mutated in the enclosing function's scope.
 *
 * @package RentopianSync\Sets
 * @since   2.14.7
 *
 * PHP requirement: 7.4+
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'Rental_Sets_Sync_Result', false ) ) :

class Rental_Sets_Sync_Result {

    /** @var int Last set id allocated — caller continues its own id counter from here. */
    protected $last_set_id = 0;

    /** @var array<int,int> rental_set_id => wp_set_id */
    protected $set_ids = array();

    /** @var array<int,bool> wp_post_id => true for products/variants that belong to a set */
    protected $set_items_collection = array();

    /** @var array<int,array> wp_set_id => [rental_set_id, ...] */
    protected $up_sells_from_sets = array();

    /** @var array<int,array> wp_set_id => [rental_product_id, ...] */
    protected $up_sells_from_products = array();

    /** @var array<int,array> wp_set_id => [rental_set_id, ...] */
    protected $cross_sells_from_sets = array();

    /** @var array<int,array> wp_set_id => [rental_product_id, ...] */
    protected $cross_sells_from_products = array();

    /** @var int[] Non-discountable product ids (includes inherited from the caller). */
    protected $coupons_excluded_product_ids = array();

    /** @var array<string,int> Slug counter carried over and extended. */
    protected $products_slug = array();

    /** @var array<int,int[]> Image-to-ids map carried over and extended. */
    protected $rental_img_variant_rel = array();

    /** @var array<int,string> wp_set_id => posts INSERT value row */
    protected $product_sql = array();

    /** @var array<int,string> wp_set_id => postmeta INSERT value rows (concatenated) */
    protected $productmeta_sql = array();

    /** @var array<int,string> wp_set_id => wc_product_meta_lookup INSERT value row */
    protected $product_meta_lookup_sql = array();

    /** @var string[] Each element is a `($wp_set_id, $rental_id, $division_id)` tuple string. */
    protected $set_relations_sql = array();

    /** @var string[] Each element is a `($wp_set_id, $term_taxonomy_id, 0)` tuple string. */
    protected $term_relation_sql = array();

    /** @var bool Whether any set item carried at least one addon. */
    protected $any_set_has_addons = false;

    /* ---------------------------------------------------------------- */
    /* Mutators (called by the processor)                                */
    /* ---------------------------------------------------------------- */

    public function set_last_set_id( $id ) { $this->last_set_id = (int) $id; }

    public function map_set_id( $rental_id, $wp_id ) {
        $this->set_ids[ $rental_id ] = (int) $wp_id;
    }

    public function mark_set_item( $wp_id ) {
        $this->set_items_collection[ $wp_id ] = true;
    }

    public function record_up_sells_from_sets( $wp_set_id, $rental_pack ) {
        $this->up_sells_from_sets[ $wp_set_id ] = $rental_pack;
    }

    public function record_up_sells_from_products( $wp_set_id, $rental_pack ) {
        $this->up_sells_from_products[ $wp_set_id ] = $rental_pack;
    }

    public function record_cross_sells_from_sets( $wp_set_id, $rental_pack ) {
        $this->cross_sells_from_sets[ $wp_set_id ] = $rental_pack;
    }

    public function record_cross_sells_from_products( $wp_set_id, $rental_pack ) {
        $this->cross_sells_from_products[ $wp_set_id ] = $rental_pack;
    }

    public function add_coupons_excluded_id( $wp_id ) {
        $this->coupons_excluded_product_ids[] = (int) $wp_id;
    }

    public function seed_coupons_excluded_ids( array $ids ) {
        $this->coupons_excluded_product_ids = $ids;
    }

    public function set_products_slug( array $slug_map ) {
        $this->products_slug = $slug_map;
    }

    public function set_rental_img_variant_rel( array $map ) {
        $this->rental_img_variant_rel = $map;
    }

    public function bump_products_slug( $slug ) {
        if ( isset( $this->products_slug[ $slug ] ) ) {
            $this->products_slug[ $slug ]++;
        } else {
            $this->products_slug[ $slug ] = 1;
        }
        return $this->products_slug[ $slug ];
    }

    public function register_img_for_set( $img_id, $wp_set_id ) {
        if ( isset( $this->rental_img_variant_rel[ $img_id ] ) ) {
            $this->rental_img_variant_rel[ $img_id ][] = $wp_set_id;
        } else {
            $this->rental_img_variant_rel[ $img_id ] = [$wp_set_id];
        }
    }

    public function add_product_sql_fragment( $wp_set_id, $fragment ) {
        $this->product_sql[ $wp_set_id ] = $fragment;
    }

    public function add_productmeta_sql_fragment( $wp_set_id, $fragment ) {
        $this->productmeta_sql[ $wp_set_id ] = $fragment;
    }

    public function add_product_meta_lookup_sql_fragment( $wp_set_id, $fragment ) {
        $this->product_meta_lookup_sql[ $wp_set_id ] = $fragment;
    }

    public function append_set_relations_sql_row( $row ) {
        $this->set_relations_sql[] = $row;
    }

    public function append_term_relation_sql_row( $row ) {
        $this->term_relation_sql[] = $row;
    }

    public function mark_any_set_has_addons() {
        $this->any_set_has_addons = true;
    }

    /* ---------------------------------------------------------------- */
    /* Accessors (called by the caller after run() returns)              */
    /* ---------------------------------------------------------------- */

    public function last_set_id() { return $this->last_set_id; }

    public function set_ids() { return $this->set_ids; }

    public function set_items_collection() { return $this->set_items_collection; }

    public function up_sells_from_sets() { return $this->up_sells_from_sets; }

    public function up_sells_from_products() { return $this->up_sells_from_products; }

    public function cross_sells_from_sets() { return $this->cross_sells_from_sets; }

    public function cross_sells_from_products() { return $this->cross_sells_from_products; }

    public function coupons_excluded_product_ids() { return $this->coupons_excluded_product_ids; }

    public function products_slug() { return $this->products_slug; }

    public function rental_img_variant_rel() { return $this->rental_img_variant_rel; }

    public function product_sql() { return $this->product_sql; }

    public function productmeta_sql() { return $this->productmeta_sql; }

    public function product_meta_lookup_sql() { return $this->product_meta_lookup_sql; }

    public function set_relations_sql() { return $this->set_relations_sql; }

    public function term_relation_sql() { return $this->term_relation_sql; }

    public function any_set_has_addons() { return $this->any_set_has_addons; }
}

endif;
