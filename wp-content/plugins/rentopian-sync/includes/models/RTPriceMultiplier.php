<?php

class RTPriceMultiplier
{
    /**
     * Expected fields from Rentopian.
     */
    public $id
        ,$title
        ,$slug
        ,$is_monthly
        ,$is_repeat
        ,$items;

    /**
     * Create a new instance.
     *
     * @param int $id
     * @param string $title
     * @param string $slug
     * @param int $is_monthly
     * @param int $is_repeat
     * @param array $items
     */
    public function __construct($id, $title = '', $slug = '', $is_monthly = 0, $is_repeat = 0, $items = '')
    {
        $this->id = intval($id);
        $this->title = $title;
        $this->slug = $slug;
        $this->is_monthly = $is_monthly;
        $this->is_repeat = $is_repeat;
        $this->items = $items;
    }

    /**
     * Get price_multiplier by id.
     *
     * @return object|null
     */
    public function get()
    {
        global $wpdb, $rental_tables;
        $rental_price_multipliers = $wpdb->prefix . $rental_tables["price_multipliers"];
        
        $sql = "
            SELECT 
                `pm`.*
            FROM 
                `$rental_price_multipliers` pm " .
            "WHERE 
                `id` = %d 
        ";
        return $wpdb->get_row(
            $wpdb->prepare($sql, $this->id )
        );
    }

    /**
     * Create or update a price_multiplier.
     *
     * @return object
     */
    public function save()
    {
        global $wpdb, $rental_tables;
        $rental_price_multipliers = $wpdb->prefix . $rental_tables["price_multipliers"];

        $exists = $wpdb->get_var( $wpdb->prepare( "SELECT `id` FROM $rental_price_multipliers WHERE `id` = %d ", [$this->id] ) );
        if ($exists) {
            // update
            $where = ['id' => $this->id];
            $result = $wpdb->update( $rental_price_multipliers, [ 
                'title' => $this->title
                ,'slug' => $this->slug
                ,'is_monthly' => $this->is_monthly
                ,'is_repeat' => $this->is_repeat
                ,'items' => $this->items
            ], $where );

            if (false === $result) {
                return $result;
            } 

        } else {
            // insert
            $result = $wpdb->insert( $rental_price_multipliers, [ 
                'id' => $this->id
                ,'title' => $this->title
                ,'slug' => $this->slug
                ,'is_monthly' => $this->is_monthly
                ,'is_repeat' => $this->is_repeat
                ,'items' => $this->items
            ]);

            if (false === $result) {
                return $result;
            }
        }

        return $this->id;
    }

    /**
     * Delete a price_multiplier.
     *
     * @return array
     */
    public function delete()
    {
        global $wpdb, $rental_tables;
        $rental_price_multipliers = $wpdb->prefix . $rental_tables["price_multipliers"];

        // delete rental_price_multiplier_relations
        $result = (bool) $wpdb->delete($rental_price_multipliers, [
            "id" => $this->id
        ]);

        return $result;
    }

}