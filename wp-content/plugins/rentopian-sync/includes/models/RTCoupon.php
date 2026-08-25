<?php

class RTCoupon
{
    /**
     * Expected fields from Rentopian.
     */
    public $rental_id
        ,$coupon_id
        ,$target
        ,$unit
        ,$quantity
        ,$unlimited_qty
        ,$coupon_use
        ,$end_date
        ,$no_expiry
        ,$status
        ,$all_divisions
        ,$divisions
        ,$discount_type
        ,$usage_limit
        ,$usage_limit_per_user
        ,$date_expires
        ,$coupon_amount
        ,$description;

    private $date, $gmdate, $domain;

    /**
     * Create a new instance.
     *
     * @param int $rental_id
     * @param string $code
     * @param float $target
     * @param int $unit
     * @param int $quantity
     * @param bool $unlimited_qty
     * @param int $coupon_use
     * @param date $end_date
     * @param bool $no_expiry
     * @param int $status
     * @param bool $all_divisions
     * @param string $divisions
     */
    public function __construct($rental_id, $code = '', $description = '', $target = 0, $unit = 1, $quantity = 0, $unlimited_qty = 0, $coupon_use = 1, $end_date = null
        , $no_expiry = 0, $status = 1, $all_divisions = 0, $divisions = '')
    {
        $this->rental_id = intval($rental_id);
        $this->coupon_id = $code;
        $this->description = esc_sql($description);
        $this->target = $target;
        $this->unit = $unit;
        $this->quantity = $quantity;
        $this->unlimited_qty = $unlimited_qty;
        $this->coupon_use = $coupon_use;
        $this->end_date = $end_date;
        $this->no_expiry = $no_expiry;
        $this->status = $status == 1 ? 'publish' : 'trash';
        $this->all_divisions = $all_divisions;
        $this->divisions = $divisions;


        $this->domain = get_option('siteurl');
        $this->date = date('Y-m-d H:i:s');
        $this->gmdate = gmdate('Y-m-d H:i:s');


        $this->discount_type = ($this->unit == 1) ? 'percent' : (($this->unit == 2) ? 'fixed_cart' : 'percent');
        $this->coupon_amount = $this->target;
        $this->usage_limit = !empty($this->unlimited_qty) ? '99999' : $this->quantity;
        $this->usage_limit_per_user = (!empty($this->coupon_use) && $this->coupon_use != 1) ? '99999' : 1;
        $this->date_expires = !empty($this->no_expiry) ? strtotime(date('Y-m-d', strtotime('+10 years'))) : $this->end_date;
    }

    /**
     * Get coupon by id.
     *
     * @return object|null
     */
    public function get()
    {
        global $wpdb, $rental_tables;
        $rental_coupon_relations = $wpdb->prefix . $rental_tables["coupon_relations"];
        
        $sql = "
            SELECT 
                `p`.*
                , `pm`.*
            FROM 
                `$rental_coupon_relations` " .
            "LEFT JOIN `$wpdb->posts` `p` ON `p`.`ID` = `$rental_coupon_relations`.`id` " .
            "LEFT JOIN `$wpdb->postmeta` `pm` ON `pm`.`post_id` = `p`.`ID` " .
            "WHERE 
                `$rental_coupon_relations`.`rental_id` = %d 
                AND `p`.`post_type` = 'shop_coupon'
        ";
        return $wpdb->get_row(
            $wpdb->prepare($sql, $this->rental_id )
        );
    }

    /**
     * Create/Update a coupon in `wp_posts` table.
     *
     * @param int $id Required (if it's an update)
     * @return int|WP_Error The post ID on success. The value 0 or WP_Error on failure.
     */
    private function setWpPost($id = 0)
    {
        if ($id) {
            // update
            return wp_update_post([
                'ID'                    => $id,
                'post_title'            => addslashes($this->coupon_id),
                'post_name'             => $this->make_slug($this->coupon_id),
                'post_excerpt'          => $this->description,
                'post_status'           => $this->status,
                'post_type'             => 'shop_coupon',
                'guid'                  => $this->domain.'/?post_type=shop_coupon&p='.$id,
                'import_id'             => 0,
                'context'               => '',
                'post_modified'         => $this->date,
                'post_modified_gmt'     => $this->gmdate,
                // 'post_date'             => $this->date,
                // 'post_date_gmt'         => $this->gmdate,
            ]);
        } else {
            // insert
            return wp_insert_post([
                'post_author'           => 1,
                'post_content'          => '',
                'post_content_filtered' => '',
                'post_title'            => $this->coupon_id,
                'post_name'             => $this->make_slug($this->coupon_id),
                'post_excerpt'          => $this->description,
                'post_status'           => $this->status,
                'post_type'             => 'shop_coupon',
                'comment_status'        => 'closed',
                'ping_status'           => 'closed',
                'post_password'         => '',
                'to_ping'               => '',
                'pinged'                => '',
                'post_parent'           => 0,
                'menu_order'            => 0,
                'guid'                  => "",
                'import_id'             => 0,
                'context'               => '',
                'post_modified'         => $this->date,
                'post_modified_gmt'     => $this->gmdate,
                'post_date'             => $this->date,
                'post_date_gmt'         => $this->gmdate,
            ]);

        }
        
    }

    private function update_post_guid($id) {
        if (!$id)
            return false;
        
        return wp_update_post([
            'ID'                    => $id,
            'guid'                  => $this->domain.'/?post_type=shop_coupon&p='.$id,
        ]);
    }

    /**
     * Create/Update a coupon options in `wp_postmeta` table.
     *
     * @param int $id Required (if it's an update)
     * @return int|WP_Error The post ID on success. The value 0 or WP_Error on failure.
    */
    private function setWpPostmeta($post_id, $meta_key, $meta_value)
    {
        if (metadata_exists( 'post', $post_id, $meta_key )) {
            // update
            update_post_meta( $post_id, $meta_key, $meta_value);
        } else {
            // insert
            add_post_meta( $post_id, $meta_key, $meta_value );
        }
    }
    
    /**
     * Insert row in `rental_coupon_relations` table.
     *
     * @param int $wp_posts_id
     * @param int $rental_id
     * @param string $rental_coupon_divisions
     * @return void
     */
    private function setRentalRelation($wp_posts_id, $rental_id, $rental_coupon_divisions)
    {
        global $wpdb, $rental_tables;
        $rental_coupon_relations = $wpdb->prefix . $rental_tables["coupon_relations"];

        $exists = $wpdb->get_var( $wpdb->prepare( "SELECT `id` FROM $rental_coupon_relations WHERE `id` = %d AND `rental_id` = %d", [$wp_posts_id, $rental_id] ) );
        if ($exists) {
            // update
            $wpdb->update($rental_coupon_relations, ["rental_coupon_divisions" => $rental_coupon_divisions] , ["id" => $wp_posts_id, "rental_id" => $rental_id]);
        } else {
            // insert
            $wpdb->insert($rental_coupon_relations, [
                "id" => $wp_posts_id,
                "rental_id" => $rental_id,
                "rental_coupon_divisions" => $rental_coupon_divisions
            ]);
        }
    }

    /**
     * Create product brand.
     *
     * @return object
     */
    public function create()
    {
        // insert coupon (into wp_posts)
        $last_insert_id = $this->setWpPost();
        // update guid
        $this->update_post_guid($last_insert_id);
        // insert coupon options (into wp_postmeta)
        $this->setWpPostmeta($last_insert_id, 'discount_type', $this->discount_type);
        $this->setWpPostmeta($last_insert_id, 'coupon_amount', $this->coupon_amount);
        $this->setWpPostmeta($last_insert_id, 'usage_limit', $this->usage_limit);
        $this->setWpPostmeta($last_insert_id, 'usage_limit_per_user', $this->usage_limit_per_user);
        $this->setWpPostmeta($last_insert_id, 'limit_usage_to_x_items', '0');
        $this->setWpPostmeta($last_insert_id, 'date_expires', $this->date_expires);
        $this->setWpPostmeta($last_insert_id, 'individual_use', 'yes');
        $this->setWpPostmeta($last_insert_id, 'usage_count', '0');
        $this->setWpPostmeta($last_insert_id, 'free_shipping', 'no');
        $this->setWpPostmeta($last_insert_id, 'exclude_sale_items', 'no');
        
        $divisions = '';
        if (!empty($this->divisions) && !$this->all_divisions) {
            $divisions = $this->divisions;
        } else if ($this->all_divisions) {
            $divisions = 'all';
        }
        // insert rental relations
        $this->setRentalRelation($last_insert_id, $this->rental_id, $divisions);

        return $last_insert_id;
    }

    /**
     * Update coupon.
     *
     * @param object $coupon
     * @return object
     */
    public function update($coupon)
    {
        // insert coupon (into wp_posts)
        $last_insert_id = $this->setWpPost($coupon->ID);
        // insert coupon options (into wp_postmeta)
        $this->setWpPostmeta($last_insert_id, 'discount_type', $this->discount_type);
        $this->setWpPostmeta($last_insert_id, 'coupon_amount', $this->coupon_amount);
        $this->setWpPostmeta($last_insert_id, 'usage_limit', $this->usage_limit);
        $this->setWpPostmeta($last_insert_id, 'usage_limit_per_user', $this->usage_limit_per_user);
        $this->setWpPostmeta($last_insert_id, 'limit_usage_to_x_items', '0');
        $this->setWpPostmeta($last_insert_id, 'date_expires', $this->date_expires);
        $this->setWpPostmeta($last_insert_id, 'individual_use', 'yes');
        $this->setWpPostmeta($last_insert_id, 'usage_count', '0');
        $this->setWpPostmeta($last_insert_id, 'free_shipping', 'no');
        $this->setWpPostmeta($last_insert_id, 'exclude_sale_items', 'no');

        $divisions = '';
        if (!empty($this->divisions) && !$this->all_divisions) {
            $divisions = $this->divisions;
        } else if ($this->all_divisions) {
            $divisions = 'all';
        }
        // insert rental relations
        $this->setRentalRelation($last_insert_id, $this->rental_id, $divisions);

        return $last_insert_id;
    }

    /**
     * Create or update a coupon.
     *
     * @return object
     */
    public function save()
    {
        $coupon = $this->get();
        if ($coupon) {
            return $this->update($coupon);
        }
        return $this->create();
    }

    /**
     * Delete a coupon.
     *
     * @return array
     */
    public function delete()
    {
        global $wpdb, $rental_tables;
        $rental_coupon_relations = $wpdb->prefix . $rental_tables["coupon_relations"];

        $data = ["rental_id" => $this->rental_id];
        $coupon = $this->get();

        if ($coupon) {
            // delete wp_postmeta
            $postmeta_deleted = delete_post_meta( $coupon->ID, $coupon->meta_key, $coupon->meta_value);

            if ($postmeta_deleted) {
                // delete wp_posts
                $data["delete_wp_posts"] = wp_delete_post($coupon->ID, true);
            }
        }

        // delete rental_coupon_relations
        $data["delete_rental_coupon_relations"] = (bool) $wpdb->delete($rental_coupon_relations, [
            "rental_id" => $this->rental_id
        ]);

        return $data;
    }


    private function make_slug($string) {
        $slug_array = [];
        $slug = strtolower(sanitize_title($string));
        if (isset($slug_array[$slug])) {
            $slug_array[$slug]++;
            $slug .= "-$slug_array[$slug]";
        } else {
            $slug_array[$slug] = 1;
        }
        return $slug;
    }
}