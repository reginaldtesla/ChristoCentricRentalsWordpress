<?php

require_once('RTImage.php');

class RTBrand
{
    /**
     * Expected fields from Rentopian.
     */
    public $rental_id,
        $title,
        $logo_id;

    /**
     * Create a new instance.
     *
     * @param int $rental_id
     * @param string $title
     * @param int $logo_id
     */
    public function __construct($rental_id, $title = null, $logo_id = null)
    {
        $this->rental_id = $rental_id;
        $this->title = $title;
        $this->logo_id = $logo_id;
    }

    /**
     * Get product brand by id.
     *
     * @return object|null
     */
    public function getBrand()
    {
        global $wpdb, $rental_tables;
        $rental_brand_relations = $wpdb->prefix . $rental_tables["brand_relations"];

        return $wpdb->get_row("SELECT `$wpdb->terms`.`slug`, `$wpdb->terms`.`name`, `tt`.`term_taxonomy_id`, `tt`.`term_id` FROM `$rental_brand_relations` " .
            "INNER JOIN `$wpdb->terms` ON `$wpdb->terms`.`term_id` = `$rental_brand_relations`.`id` " .
            "INNER JOIN `$wpdb->term_taxonomy` AS `tt` ON `tt`.`term_id` = `$wpdb->terms`.`term_id` " .
            "WHERE `$rental_brand_relations`.`rental_id` = $this->rental_id AND `tt`.`taxonomy` = 'product_brand'");
    }

    /**
     * Set `thumbnail_id` in `termmeta` table.
     *
     * @param int $term_id
     * @return void
     */
    private function setLogo($term_id)
    {
        $thumbnail_id = 0;
        if ($this->logo_id) {
            $images = (new RTImage())->getImages([$this->logo_id]);
            if (isset($images[$this->logo_id])) {
                $thumbnail_id = $images[$this->logo_id];
            }
        }

        update_term_meta($term_id, "thumbnail_id", $thumbnail_id);
    }

    /**
     * Insert row in `rental_brand_relations` table.
     *
     * @param int $term_id
     * @param int $rental_id
     * @return void
     */
    private function setRentalRelation($term_id, $rental_id)
    {
        global $wpdb, $rental_tables;
        $rental_brand_relations = $wpdb->prefix . $rental_tables["brand_relations"];

        $wpdb->insert($rental_brand_relations, [
            "id" => $term_id,
            "rental_id" => $rental_id
        ]);
    }

    /**
     * Create product brand.
     *
     * @return object
     */
    public function create()
    {
        register_taxonomy("product_brand", "product");
        $term = wp_insert_term($this->title, "product_brand");
        if (is_wp_error($term)) {
            $term = ["term_id" => $term->error_data["term_exists"]];
        }
        $term = (object) $term;

        $this->setLogo($term->term_id);

        $this->setRentalRelation($term->term_id, $this->rental_id);

        return $term;
    }

    /**
     * Update product brand.
     *
     * @param object $brand
     * @return object
     */
    public function update($brand)
    {
        register_taxonomy("product_brand", "product");
        $term = wp_update_term($brand->term_id, "product_brand", [
            "name" => $this->title,
            "slug" => sanitize_title($this->title)
        ]);
        $term = (object) $term;

        $this->setLogo($term->term_id);

        return $term;
    }

    /**
     * Create or update product brand.
     *
     * @return object
     */
    public function save()
    {
        $brand = $this->getBrand();
        if ($brand) {
            return $this->update($brand);
        }
        return $this->create();
    }

    /**
     * Delete product brand.
     *
     * @return array
     */
    public function delete()
    {
        global $wpdb, $rental_tables;
        $rental_brand_relations = $wpdb->prefix . $rental_tables["brand_relations"];

        $data = ["rental_id" => $this->rental_id];
        $brand = $this->getBrand();
        if ($brand) {
            $data["term_id"] = $brand->term_id;
            $data["delete_term"] = wp_delete_term($brand->term_id, "product_brand");
        }

        $data["delete_relation"] = $wpdb->delete($rental_brand_relations, [
            "rental_id" => $this->rental_id
        ]);

        return $data;
    }
}