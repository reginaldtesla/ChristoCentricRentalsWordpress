<?php

class RTAttribute
{
    /**
     * Attribute types `swatch_type` in `zoo_cw_product_attribute_swatch_type` table.
     */
    const TYPES = [
        1 =>'select',
        2 => 'color',
        3 => 'image',
        4 => 'text',
    ];

    /**
     * Expected fields from Rentopian.
     */
    public $rental_id,
        $slug,
        $title,
        $type;

    /**
     * Create a new instance.
     *
     * @param int $rental_id
     * @param string $slug
     * @param string $title
     * @param int $type
     */
    public function __construct($rental_id, $slug = null, $title = null, $type = null)
    {
        $this->rental_id = $rental_id;
        $this->slug = $slug;
        $this->title = $title;
        $this->type = $type;
    }

    /**
     * Get product attribute by id.
     *
     * @return object|null
     */
    public function getAttribute()
    {
        global $wpdb, $rental_tables;
        $rental_attribute_relations = $wpdb->prefix . $rental_tables["attribute_relations"];

        return $wpdb->get_row("SELECT `attributes`.* FROM `$rental_attribute_relations` " .
            "INNER JOIN `" . $wpdb->prefix . "woocommerce_attribute_taxonomies` AS `attributes` ON `attributes`.`attribute_id` = `$rental_attribute_relations`.`id` " .
            "WHERE `$rental_attribute_relations`.`rental_id` = $this->rental_id");
    }

    /**
     * Insert row in `rental_attribute_relations` table.
     *
     * @param int $attribute_id
     * @param int $rental_id
     * @return void
     */
    private function setRentalRelation($attribute_id, $rental_id)
    {
        global $wpdb, $rental_tables;
        $rental_attribute_relations = $wpdb->prefix . $rental_tables["attribute_relations"];

        $wpdb->insert($rental_attribute_relations, [
            "id" => $attribute_id,
            "rental_id" => $rental_id
        ]);
    }

    /**
     * Get _transient_wc_attribute_taxonomies array.
     *
     * @return array
     */
    private function getAttributesTransient()
    {
        $wc_attribute_taxonomies = get_option('_transient_wc_attribute_taxonomies');
        return is_array($wc_attribute_taxonomies)? $wc_attribute_taxonomies: [];
    }

    /**
     * Append attribute to _transient_wc_attribute_taxonomies array.
     *
     * @param object $attribute
     * @return void
     */
    private function appendAttributeInTransient($attribute)
    {
        $wc_attribute_taxonomies = $this->getAttributesTransient();
        $wc_attribute_taxonomies[] = $attribute;
        update_option('_transient_wc_attribute_taxonomies', $wc_attribute_taxonomies);
    }

    /**
     * Update attribute in _transient_wc_attribute_taxonomies array.
     *
     * @param object $attribute
     * @return void
     */
    private function updateAttributeInTransient($attribute)
    {
        $wc_attribute_taxonomies = $this->getAttributesTransient();
        $count = count($wc_attribute_taxonomies);
        $i = 0;
        while ($i < $count) {
            if ($wc_attribute_taxonomies[$i]->attribute_id == $attribute->attribute_id) {
                break;
            }
            $i++;
        }
        $wc_attribute_taxonomies[$i] = $attribute;
        update_option('_transient_wc_attribute_taxonomies', $wc_attribute_taxonomies);
    }

    /**
     * Change attribute slug everywhere
     *
     * @param string $new_slug
     * @param string $old_slug
     * @return void
     */
    private function changeAttributeSlug($new_slug, $old_slug)
    {
        global $wpdb;

        $new_slug = "pa_$new_slug";
        $old_slug = "pa_$old_slug";
        // update `meta_key` id `postmeta` table
        $wpdb->update($wpdb->postmeta, [
            "meta_key" => "attribute_$new_slug"
        ], [
            "meta_key" => "attribute_$old_slug"
        ]);

        $taxonomies = $wpdb->get_results("SELECT `term_taxonomy_id` FROM `$wpdb->term_taxonomy` WHERE `taxonomy` = '$old_slug'");
        if (empty($taxonomies)) {
            return;
        }
        $taxonomy_ids = "";
        foreach ($taxonomies as $taxonomy) {
            $taxonomy_ids .= $taxonomy_ids? ", $taxonomy->term_taxonomy_id": $taxonomy->term_taxonomy_id;
        }
        // update `taxonomy` id `term_taxonomy` table
        $wpdb->query("UPDATE `$wpdb->term_taxonomy` SET `taxonomy` = '$new_slug' WHERE `term_taxonomy_id` IN ($taxonomy_ids)");

        $products = $wpdb->get_results("SELECT `object_id` FROM `$wpdb->term_relationships` WHERE `term_taxonomy_id` IN ($taxonomy_ids) GROUP BY `object_id`");
        foreach ($products as $product) {
            $product_id = $product->object_id;
            $product_attributes = get_post_meta($product_id, '_product_attributes', true);
            if (isset($product_attributes[$old_slug])) {
                $product_attributes[$new_slug] = $product_attributes[$old_slug];
                $product_attributes[$new_slug]["name"] = $new_slug;
                unset($product_attributes[$old_slug]);
                update_post_meta($product_id, '_product_attributes', $product_attributes);
            }
            $default_attributes = get_post_meta($product_id, '_default_attributes', true);
            if (isset($default_attributes[$old_slug])) {
                $default_attributes[$new_slug] = $default_attributes[$old_slug];
                unset($default_attributes[$old_slug]);
                if ($default_attributes && count($default_attributes) < 2) {
                    update_post_meta($product_id, "_default_attributes", $default_attributes);
                }
            }
        }
    }

    /**
     * Set swatch_type to attribute.
     *
     * @param int $attribute_id
     * @param int $type
     * @param bool $may_exist
     * @return void
     */
    private function setAttributeType($attribute_id, $type, $may_exist = false)
    {
        global $wpdb;

        if ( !defined('ZOO_CW_VERSION')) {
            return;
        }
        if ($may_exist) {
            $wpdb->delete($wpdb->prefix . "zoo_cw_product_attribute_swatch_type", [
                "attribute_id" => $attribute_id,
            ]);
        }
        if (isset(self::TYPES[$type])) {
            $wpdb->insert($wpdb->prefix . "zoo_cw_product_attribute_swatch_type", [
                "attribute_id" => $attribute_id,
                "swatch_type" => self::TYPES[$type]
            ]);
        }
    }

    /**
     * Create product attribute.
     *
     * @return object
     */
    public function create()
    {
        global $wpdb;

        $attribute = [
            "attribute_name" => $this->slug,
            "attribute_label" => $this->title,
            "attribute_type" => "select",
            "attribute_orderby" => "menu_order",
            "attribute_public" => 0
        ];
        $wpdb->insert($wpdb->prefix . "woocommerce_attribute_taxonomies", $attribute);
        $attribute["attribute_id"] = $wpdb->insert_id;
        $attribute = (object) $attribute;

        $this->setRentalRelation($attribute->attribute_id, $this->rental_id);

        $this->appendAttributeInTransient($attribute);

        $this->setAttributeType($attribute->attribute_id, $this->type);

        return $attribute;
    }

    /**
     * Update product attribute.
     *
     * @param object $attribute
     * @return object
     */
    public function update($attribute)
    {
        global $wpdb;

        $update = $wpdb->update($wpdb->prefix . "woocommerce_attribute_taxonomies", [
            "attribute_name" => $this->slug,
            "attribute_label" => $this->title,
        ], [
            "attribute_id" => $attribute->attribute_id
        ]);
        if ($update) {
            if ($attribute->attribute_name != $this->slug) {
                $this->changeAttributeSlug($this->slug, $attribute->attribute_name);
                $attribute->attribute_name = $this->slug;
            }
            $attribute->attribute_label = $this->title;

            $this->updateAttributeInTransient($attribute);
        }
        $this->setAttributeType($attribute->attribute_id, $this->type, true);

        return $attribute;
    }

    /**
     * Create or update product attribute.
     *
     * @return object
     */
    public function save()
    {
        $attribute = $this->getAttribute();
        if ($attribute) {
            return $this->update($attribute);
        }
        return $this->create();
    }
}