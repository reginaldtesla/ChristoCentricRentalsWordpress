<?php

require_once('RTAttribute.php');
require_once('RTImage.php');

class RTAttributeValue
{
    /**
     * Expected fields from Rentopian.
     */
    public $attribute_rental_id,
        $slug,
        $title,
        $color,
        $img_id,
        $old_slug,
        $rental_id,
        $is_group;

    /**
     * Create a new instance.
     *
     * @param int $attribute_rental_id
     * @param string $slug
     * @param string $title
     * @param string $color
     * @param int $img_id
     * @param string $old_slug
     * @param int $rental_id Rentopian value id.
     * @param int|null $is_group Whether the value is a group of other values.
     *                           NULL when the payload did not say.
     */
    public function __construct($attribute_rental_id, $slug, $title, $color, $img_id, $old_slug, $rental_id = 0, $is_group = null)
    {
        $this->attribute_rental_id = $attribute_rental_id;
        $this->slug = $slug;
        $this->title = $title;
        $this->color = $color;
        $this->img_id = $img_id;
        $this->old_slug = $old_slug;
        $this->rental_id = (int) $rental_id;
        $this->is_group = is_null($is_group)? null: ($is_group? 1: 0);
    }

    /**
     * Store which term this value occupies, and whether it is a group.
     *
     * @param int $term_id
     * @return void
     */
    private function saveGroupRelation($term_id)
    {
        if ( !$this->rental_id || !class_exists('Rental_Attribute_Groups')) {
            return;
        }

        Rental_Attribute_Groups::ensure_tables();
        Rental_Attribute_Groups::save_value($term_id, $this->rental_id, $this->attribute_rental_id, $this->is_group);
    }

    /**
     * Get product attribute value by slug and taxonomy.
     *
     * @param string $slug
     * @param string $taxonomy
     * @return object|null
     */
    public function getAttributeValue($slug, $taxonomy)
    {
        global $wpdb;

        return $wpdb->get_row("SELECT `$wpdb->terms`.`slug`, `$wpdb->terms`.`name`, `tt`.`term_taxonomy_id`, `tt`.`term_id` FROM `$wpdb->terms` " .
            "INNER JOIN `$wpdb->term_taxonomy` AS `tt` ON `tt`.`term_id` = `$wpdb->terms`.`term_id` " .
            "WHERE `$wpdb->terms`.`slug` = '$slug' AND `tt`.`taxonomy` = 'pa_$taxonomy'");
    }

    /**
     * Set meta field to attribute value depending on the attribute swatch_type.
     *
     * @param int $attribute_id
     * @param string $attribute_name
     * @param int $term_id
     * @return void
     */
    private function setMetaDependingOnAttributeType($attribute_id, $attribute_name, $term_id, $color = '', $img_id = 0) {
        global $wpdb;

        if (defined('ZOO_CW_VERSION')) {
            $attribute_type = $wpdb->get_var("SELECT `swatch_type` FROM `" . $wpdb->prefix . "zoo_cw_product_attribute_swatch_type` " .
            "WHERE `attribute_id` = $attribute_id");

            if ($attribute_type == "color") {
                update_term_meta($term_id, "slctd_clr", $this->color?: "");

            } elseif ($attribute_type == "image") {
                $image = "";
                if ($this->img_id) {
                    $images = (new RTImage())->getImages([$this->img_id]);
                    if (isset($images[$this->img_id])) {
                        $image = wp_get_attachment_image_url($images[$this->img_id]);
                    }
                }
                update_term_meta($term_id, "slctd_img", $image);
            }
        }

        if (defined('RENTPRO_SWATCHES_PATH')) {
            if ($color) {
                update_term_meta($term_id, "sw_color", $color);
                // A value that switched from an image to a colour must not keep
                // drawing the old thumbnail.
                delete_term_meta($term_id, "sw_image");
                update_term_meta($term_id, "sw_tooltip", $this->title?: "");

            } else if ($img_id) {
                // sw_image holds a WordPress attachment id, not a Rentopian
                // file id: the swatch renders it through
                // wp_get_attachment_thumb_url(), and an unresolved id produces
                // an empty background instead of a picture. getImages() maps
                // the id, downloading the file when it is not here yet.
                $attachment_id = 0;
                try {
                    $images = (new RTImage())->getImages([$img_id]);
                    $attachment_id = isset($images[$img_id])? (int) $images[$img_id]: 0;
                } catch (Exception $e) {
                    // An image the API cannot serve right now must not fail the
                    // whole value; the swatch keeps whatever it already had.
                    $attachment_id = (int) get_term_meta($term_id, "sw_image", true);
                }

                if ($attachment_id) {
                    update_term_meta($term_id, "sw_image", $attachment_id);
                } else {
                    // Better an honest placeholder than an empty square.
                    delete_term_meta($term_id, "sw_image");
                }
                delete_term_meta($term_id, "sw_color");
                update_term_meta($term_id, "sw_tooltip", $this->title?: "");

            }
        }

        if (defined('WOOF_PATH')) {
            $attribute_name = "pa_$attribute_name";
            $woof_settings = get_option('woof_settings');
            if (empty($woof_settings) || !isset($woof_settings["tax_type"]) || !isset($woof_settings["tax_type"][$attribute_name])) {
                return;
            }
            $attribute_type = $woof_settings["tax_type"][$attribute_name];
            if ($attribute_type == "color") {
                if ( !isset($woof_settings["color"])) {
                    $woof_settings["color"] = [$attribute_name => []];
                } elseif ( !isset($woof_settings["color"][$attribute_name])) {
                    $woof_settings["color"][$attribute_name] = [];
                }
                $woof_settings["color"][$attribute_name][$this->slug] = $this->color?: "#000000";
                update_option("woof_settings", $woof_settings);
            } elseif ($attribute_type == "image") {
                if ( !isset($image)) {
                    $image = "";
                    if ($this->img_id) {
                        $images = (new RTImage())->getImages([$this->img_id]);
                        if (isset($images[$this->img_id])) {
                            $image = wp_get_attachment_image_url($images[$this->img_id]);
                        }
                    }
                }
                if ( !isset($woof_settings["images_term_$term_id"])) {
                    $woof_settings["images_term_$term_id"] = [];
                }
                $woof_settings["images_term_$term_id"]["image_url"] = $image;
                update_option("woof_settings", $woof_settings);
            }
        }
    }

    /**
     * Change attribute value slug everywhere
     *
     * @param string $attribute_name
     * @param string $new_slug
     * @param string $old_slug
     * @return void
     */
    private function changeAttributeValueSlug($attribute_name, $new_slug, $old_slug)
    {
        global $wpdb;

        // update `meta_key` id `postmeta` table
        $wpdb->update($wpdb->postmeta, [
            "meta_value" => $new_slug
        ], [
            "meta_key" => "attribute_pa_$attribute_name",
            "meta_value" => $old_slug
        ]);
    }

    /**
     * Create product attribute value.
     *
     * @param object $attribute
     * @return object|WP_Error
     */
    public function create($attribute)
    {
        $taxonomy = "pa_$attribute->attribute_name";
        register_taxonomy($taxonomy, "product");

        $term = wp_insert_term($this->title, $taxonomy, ["slug" => $this->slug]);

        // wp_insert_term reports a collision, an empty name and a failed
        // insert the same way, as a WP_Error. Reading it as an array is a
        // fatal, which takes down the whole request instead of failing one
        // value, so the error is resolved or handed back to the caller.
        if (is_wp_error($term)) {
            $existing = $this->existingTermId($term, $taxonomy);
            if ( !$existing) {
                return $term;
            }
            $term = ["term_id" => $existing];
        }

        $this->setMetaDependingOnAttributeType($attribute->attribute_id, $attribute->attribute_name, $term["term_id"], $this->color, $this->img_id);

        $this->saveGroupRelation($term["term_id"]);

        $term["slug"] = $this->slug;
        $term["name"] = $this->title;
        return (object) $term;
    }

    /**
     * Term this value already occupies, when the insert failed because it
     * exists under a different slug or name.
     *
     * @param WP_Error $error
     * @param string $taxonomy
     * @return int 0 when the error is not a collision.
     */
    private function existingTermId($error, $taxonomy)
    {
        $data = $error->get_error_data();
        if (is_numeric($data) && (int) $data > 0) {
            return (int) $data;
        }

        foreach ([$this->slug, $this->title] as $needle) {
            if ( !$needle) {
                continue;
            }

            $found = term_exists($needle, $taxonomy);
            if (is_array($found) && !empty($found["term_id"])) {
                return (int) $found["term_id"];
            }
            if (is_numeric($found) && (int) $found > 0) {
                return (int) $found;
            }
        }

        return 0;
    }

    /**
     * Update product attribute value.
     *
     * @param object $attribute
     * @param object $attribute_value
     * @return object
     */
    public function update($attribute, $attribute_value)
    {
        global $wpdb;

        $update = $wpdb->update($wpdb->terms, [
            "slug" => $this->slug,
            "name" => $this->title,
        ], [
            "term_id" => $attribute_value->term_id
        ]);
        if ($update) {
            if ($attribute_value->slug != $this->slug) {
                $this->changeAttributeValueSlug($attribute->attribute_name, $this->slug, $attribute_value->slug);
                $attribute_value->slug = $this->slug;
            }
            $attribute_value->name = $this->title;
        }
        $this->setMetaDependingOnAttributeType($attribute->attribute_id, $attribute->attribute_name, $attribute_value->term_id, $this->color, $this->img_id);

        $this->saveGroupRelation($attribute_value->term_id);

        return $attribute_value;
    }

    /**
     * Create or update product attribute value.
     *
     * @return object|WP_Error|false
     */
    public function save()
    {
        $attribute = (new RTAttribute($this->attribute_rental_id))->getAttribute();
        if ( !$attribute) {
            return false;
        }

        if ($this->old_slug && $this->old_slug != $this->slug) {
            $attribute_value = $this->getAttributeValue($this->old_slug, $attribute->attribute_name);
        } else {
            $attribute_value = $this->getAttributeValue($this->slug, $attribute->attribute_name);
        }

        if ($attribute_value) {
            return $this->update($attribute, $attribute_value);
        }
        return $this->create($attribute);
    }

    /**
     * Delete product attribute value.
     *
     * @return object|false
     */
    public function delete()
    {
        // The value and every membership row naming it — as a group or as a
        // member — go regardless of whether the term is still here, so a term
        // removed by hand cannot leave the group map pointing at nothing.
        if ($this->rental_id && class_exists('Rental_Attribute_Groups')) {
            Rental_Attribute_Groups::ensure_tables();
            Rental_Attribute_Groups::delete_value($this->rental_id);
        }

        $attribute = (new RTAttribute($this->attribute_rental_id))->getAttribute();
        if ( !$attribute) {
            return false;
        }

        $attribute_value = $this->getAttributeValue($this->slug, $attribute->attribute_name);
        if ( !$attribute_value) {
            return false;
        }

        $attribute_value->deleted = wp_delete_term($attribute_value->term_id, "pa_$attribute->attribute_name");

        return $attribute_value;
    }
}