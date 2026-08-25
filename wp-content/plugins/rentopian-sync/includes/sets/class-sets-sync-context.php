<?php
/**
 * Rental_Sets_Sync_Context
 *
 * Carries the read-only inputs the sets sync pass needs. Exists so the
 * processor, item-builder and sql-builder don't have to reach into global
 * scope or juggle long positional argument lists.
 *
 * Everything in here is input: the sets payload from the API, the
 * product/variant id maps the outer sync built before we got here, the
 * starting post-id counter, and the formatted date strings that get
 * stamped into posts table inserts.
 *
 * @package RentopianSync\Sets
 * @since   2.14.7
 *
 * PHP requirement: 7.4+
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'Rental_Sets_Sync_Context', false ) ) :

class Rental_Sets_Sync_Context {

    /**
     * Sets payload from `inventories/sets`.
     *
     * @var array
     */
    protected $sets;

    /**
     * Starting post id. The loop increments this before assigning it to
     * the first set, so the first set gets ($starting_id + 1).
     *
     * @var int
     */
    protected $starting_id;

    /**
     * rental_product_id => [ 'ids' => [ division_id => wp_product_id ], ... ]
     *
     * @var array
     */
    protected $products_data;

    /**
     * rental_variant_id => [ division_id => wp_variant_id ]
     *
     * @var array
     */
    protected $variant_ids;

    /**
     * term_taxonomy_id of the "simple" product type.
     *
     * @var int
     */
    protected $simple_tt_id;

    /**
     * Post-date string (local).
     *
     * @var string
     */
    protected $date;

    /**
     * Post-date string (GMT).
     *
     * @var string
     */
    protected $gmdate;

    /**
     * Site domain used to build the guid value.
     *
     * @var string
     */
    protected $domain;

    /**
     * Running slug-counter map from the outer sync (sets share it with
     * products/variants so collisions across the three populations get
     * numbered consistently).
     *
     * @var array<string,int>
     */
    protected $products_slug;

    /**
     * The rental_img_variant_rel map the outer sync has accumulated so
     * far. Sets append their own image->id pairs to this.
     *
     * @var array<int,int[]>
     */
    protected $rental_img_variant_rel;

    /**
     * The non-discountable product id list the outer sync is building.
     * Non-discountable sets get appended to this.
     *
     * @var int[]
     */
    protected $coupons_excluded_product_ids;

    /**
     * Whether the outer sync defined $product_meta_lookup_sql. If it
     * didn't, the sql-builder skips the wc lookup row.
     *
     * @var bool
     */
    protected $has_product_meta_lookup_sql;

    /**
     * Accepts an associative array of all inputs. Every key is optional
     * except `sets` and `starting_id`.
     *
     * @param array $input
     */
    public function __construct( array $input ) {
        $this->sets                         = isset( $input['sets'] ) && is_array( $input['sets'] ) ? $input['sets'] : array();
        $this->starting_id                  = isset( $input['starting_id'] ) ? (int) $input['starting_id'] : 0;
        $this->products_data                = isset( $input['products_data'] ) && is_array( $input['products_data'] ) ? $input['products_data'] : array();
        $this->variant_ids                  = isset( $input['variant_ids'] ) && is_array( $input['variant_ids'] ) ? $input['variant_ids'] : array();
        $this->simple_tt_id                 = isset( $input['simple_tt_id'] ) ? (int) $input['simple_tt_id'] : 0;
        $this->date                         = isset( $input['date'] ) ? (string) $input['date'] : '';
        $this->gmdate                       = isset( $input['gmdate'] ) ? (string) $input['gmdate'] : '';
        $this->domain                       = isset( $input['domain'] ) ? (string) $input['domain'] : '';
        $this->products_slug                = isset( $input['products_slug'] ) && is_array( $input['products_slug'] ) ? $input['products_slug'] : array();
        $this->rental_img_variant_rel       = isset( $input['rental_img_variant_rel'] ) && is_array( $input['rental_img_variant_rel'] ) ? $input['rental_img_variant_rel'] : array();
        $this->coupons_excluded_product_ids = isset( $input['coupons_excluded_product_ids'] ) && is_array( $input['coupons_excluded_product_ids'] ) ? $input['coupons_excluded_product_ids'] : array();
        $this->has_product_meta_lookup_sql  = ! empty( $input['has_product_meta_lookup_sql'] );
    }

    /** @return array */
    public function sets() { return $this->sets; }

    /** @return int */
    public function starting_id() { return $this->starting_id; }

    /** @return array */
    public function products_data() { return $this->products_data; }

    /** @return array */
    public function variant_ids() { return $this->variant_ids; }

    /** @return int */
    public function simple_tt_id() { return $this->simple_tt_id; }

    /** @return string */
    public function date() { return $this->date; }

    /** @return string */
    public function gmdate() { return $this->gmdate; }

    /** @return string */
    public function domain() { return $this->domain; }

    /** @return array */
    public function products_slug() { return $this->products_slug; }

    /** @return array */
    public function rental_img_variant_rel() { return $this->rental_img_variant_rel; }

    /** @return int[] */
    public function coupons_excluded_product_ids() { return $this->coupons_excluded_product_ids; }

    /** @return bool */
    public function has_product_meta_lookup_sql() { return $this->has_product_meta_lookup_sql; }
}

endif;
