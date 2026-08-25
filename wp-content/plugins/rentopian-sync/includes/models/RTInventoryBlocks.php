<?php

class RTInventoryBlocks
{
    /**
     * Expected fields from Rentopian.
     */
    public $id
        ,$division_id
        ,$start_date
        ,$end_date
        ,$type
        ,$inventories;


    /**
     * Create a new instance.
     *
     * @param int $id
     * @param string $title
     * @param longtext $option_values
     */
    public function __construct($id, $division_id = 0, $start_date = '', $end_date = '', $type = 1, $specific_inventories = [])
    {
        $this->id = intval($id);
        $this->division_id = $division_id;
        $this->start_date = $start_date;
        $this->end_date = $end_date;
        $this->type = $type;
        $this->inventories = $specific_inventories;
    }


    /**
     * Create or update a product_options.
     *
     * @return object
     */
    public function save()
    {
        global $wpdb, $rental_tables;
        $rental_inventory_blocks = $wpdb->prefix . $rental_tables["inventory_blocks"];
        $rental_inventory_block_relations = $wpdb->prefix . $rental_tables["inventory_block_relations"];
        $rental_product_relations = $wpdb->prefix . $rental_tables["product_relations"];
        $rental_variant_relations = $wpdb->prefix . $rental_tables["variant_relations"];

        $rental_inventory_block_relations_sql = [];
        $blocked_id = $this->id;
        $exists = $wpdb->get_var( $wpdb->prepare( "SELECT `id` FROM $rental_inventory_blocks WHERE `id` = %d ", $blocked_id ) );
        if ($exists) {
            // update
            $where = ['id' => $blocked_id];
            $result = $wpdb->update( $rental_inventory_blocks, [ 
                'division_id' => $this->division_id
                ,'start_date' => $this->start_date
                ,'end_date' => $this->end_date
                ,'type' => $this->type
            ], $where );

            // delete existing block inventory relations
            $wpdb->delete($rental_inventory_block_relations, [
                "block_id" => $blocked_id
            ]);

            if (!empty($this->inventories)) {
                $wp_product_ids_pack = [];
                $wp_product_variant_ids_pack = [];
                foreach($this->inventories as $key => $inv_block) {
                    $id = $inv_block['id'];
                    $inventory_id = $inv_block['inventory_id'];
                    $product_id = $inv_block['product_id'];
                    $product_variant_id = $inv_block['product_variant_id'];

                    $sql = "SELECT id FROM {$rental_product_relations} WHERE rental_id = %d";
                    $wp_product_ids = $wpdb->get_col($wpdb->prepare($sql, $product_id));
                    if ($wp_product_ids) {
                        $wp_product_ids_pack[$product_id] = [
                            'block_id' => $id,
                            'wp_product_ids' => $wp_product_ids
                        ];
                    }
                    
                    $sql = "SELECT id FROM {$rental_variant_relations} WHERE rental_id = %d";
                    $wp_variant_ids = $wpdb->get_col($wpdb->prepare($sql, $product_variant_id));
                    if ($wp_variant_ids) {
                        $wp_product_variant_ids_pack[$product_variant_id] = [
                            'block_id' => $id,
                            'wp_variant_ids' => $wp_variant_ids
                        ];
                    }
                }

                if ($wp_product_ids_pack) {
                    foreach($wp_product_ids_pack as $rental_product_id => $data) {
                        $block_id = $data["block_id"];
                        $rental_id = $rental_product_id;
                        $ids = $data["wp_product_ids"];
                        if ($ids) {
                            foreach($ids as $id) {
                                $rental_inventory_block_relations_sql[] = "($block_id, $rental_id, $id)";
                            }
                        }
                    }
                }
                
                if ($wp_product_variant_ids_pack) {
                    foreach($wp_product_variant_ids_pack as $rental_variant_id => $data) {
                        $block_id = $data["block_id"];
                        $rental_id = $rental_variant_id;
                        $ids = $data["wp_variant_ids"];
                        if ($ids) {
                            foreach($ids as $id) {
                                $rental_inventory_block_relations_sql[] = "($block_id, $rental_id, $id)";
                            }
                        }
                    }
                }
                
                if ($rental_inventory_block_relations_sql) {
                    $rental_inventory_block_relations_sql_str = implode(", ", $rental_inventory_block_relations_sql);
                    $wpdb->query("INSERT INTO `$rental_inventory_block_relations` (`block_id`, `rental_id`, `id`) VALUES " . $rental_inventory_block_relations_sql_str);
                }
            }

        } else {
            // insert
            $result = $wpdb->insert( $rental_inventory_blocks, [ 
                'id' => $blocked_id
                ,'division_id' => $this->division_id
                ,'start_date' => $this->start_date
                ,'end_date' => $this->end_date
                ,'type' => $this->type
            ]);

            if (!empty($this->inventories)) {
                $wp_product_ids_pack = [];
                $wp_product_variant_ids_pack = [];
                foreach($this->inventories as $key => $inv_block) {
                    $id = $inv_block['id'];
                    $inventory_id = $inv_block['inventory_id'];
                    $product_id = $inv_block['product_id'];
                    $product_variant_id = $inv_block['product_variant_id'];

                    $sql = "SELECT id FROM {$rental_product_relations} WHERE rental_id = %d";
                    $wp_product_ids = $wpdb->get_col($wpdb->prepare($sql, $product_id));
                    if ($wp_product_ids) {
                        $wp_product_ids_pack[$product_id] = [
                            'block_id' => $id,
                            'wp_product_ids' => $wp_product_ids
                        ];
                    }
                    
                    $sql = "SELECT id FROM {$rental_variant_relations} WHERE rental_id = %d";
                    $wp_variant_ids = $wpdb->get_col($wpdb->prepare($sql, $product_variant_id));
                    if ($wp_variant_ids) {
                        $wp_product_variant_ids_pack[$product_variant_id] = [
                            'block_id' => $id,
                            'wp_variant_ids' => $wp_variant_ids
                        ];
                    }
                }

                if ($wp_product_ids_pack) {
                    foreach($wp_product_ids_pack as $rental_product_id => $data) {
                        $block_id = $data["block_id"];
                        $rental_id = $rental_product_id;
                        $ids = $data["wp_product_ids"];
                        if ($ids) {
                            foreach($ids as $id) {
                                $rental_inventory_block_relations_sql[] = "($block_id, $rental_id, $id)";
                            }
                        }
                    }
                }
                
                if ($wp_product_variant_ids_pack) {
                    foreach($wp_product_variant_ids_pack as $rental_variant_id => $data) {
                        $block_id = $data["block_id"];
                        $rental_id = $rental_variant_id;
                        $ids = $data["wp_variant_ids"];
                        if ($ids) {
                            foreach($ids as $id) {
                                $rental_inventory_block_relations_sql[] = "($block_id, $rental_id, $id)";
                            }
                        }
                    }
                }

                if ($rental_inventory_block_relations_sql) {
                    $rental_inventory_block_relations_sql_str = implode(", ", $rental_inventory_block_relations_sql);
                    $wpdb->query("INSERT INTO `$rental_inventory_block_relations` (`block_id`, `rental_id`, `id`) VALUES " . $rental_inventory_block_relations_sql_str);
                }
            }

            if (false === $result) {
                return $result;
            }
        }

        return $blocked_id;
    }

    /**
     * Delete a product_options.
     *
     * @return array
     */
    public function delete()
    {
        global $wpdb, $rental_tables;
        $rental_inventory_blocks = $wpdb->prefix . $rental_tables["inventory_blocks"];
        $rental_inventory_block_relations = $wpdb->prefix . $rental_tables["inventory_block_relations"];
        
        $blocked_id = $this->id;
        $wpdb->delete($rental_inventory_blocks, [
            "id" => $blocked_id
        ]);
        // delete existing block inventory relations
        $wpdb->delete($rental_inventory_block_relations, [
            "block_id" => $blocked_id
        ]);
        return $blocked_id;
    }

}