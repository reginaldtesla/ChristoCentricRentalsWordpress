<?php
/**
 * Rental_Sets_Webhook_Handler
 *
 * Orchestrator for the three single-set webhook actions: `set/create`,
 * `set/update`, `set/delete`. Drop-in replacement for the inline
 * functions `rentopian_set_create`, `rentopian_set_update`, and
 * `rentopian_set_delete` that have historically lived in api.php.
 *
 * Why this class exists. By the time Steps 1–3 are in place, the
 * webhook handlers in api.php are mostly already delegating their
 * domain work to the sets module:
 *
 *   - entity 1+2 items + addons → `Rental_Sets_Webhook_Item_Persister`
 *   - entity 3 grouped items     → `Rental_Sets_Webhook_Group_Persister`
 *   - scoped postmeta wipe       → `Rental_Sets_Webhook_Meta_Guard`
 *   - webhook payload shape      → `Rental_Sets_Webhook_Defense`
 *
 * What's left inline is the orchestration glue: the HTTP request
 * decoding, the wp_update_post / wp_insert_post calls, the relations-
 * table SQL, the image download, the categories / tags / up-sells /
 * cross-sells calls, the wp_product_meta_lookup writes, the Polylang
 * assignment, the JSON response. This class moves all of that into
 * the sets module so api.php can shrink to a three-line wrapper per
 * action and the whole set-sync flow lives in one folder.
 *
 * Backward compatibility. Each api.php wrapper keeps the original
 * implementation as the `else` branch of a `class_exists()` gate. If
 * the sets module fails to load for any reason — autoload race, plugin
 * deactivation midway, file permissions — the original inline code
 * still runs.
 *
 * Behavioural contract with the original handlers:
 *
 *   - The exact same HTTP status codes are emitted on success and
 *     failure (200, 400, 404).
 *   - The exact same JSON response bodies are emitted (`set.rental_id`,
 *     `set.id`, `set.items`, etc.).
 *   - The exact same SQL ordering: post insert/update first, then
 *     postmeta wipe (scoped via Step 2), then bulk INSERT (create) or
 *     update_post_meta (update), then term_relationships for `simple`,
 *     then wc_product_meta_lookup, then categories + tags, then
 *     up-sells + cross-sells, then entity 3 group persistence, then
 *     rental_clear_cache, then Polylang.
 *   - The same image-download flow via `rental_curl` and
 *     `rentopian_webhook_download_image`. `RentalException` is caught,
 *     logged via `ErrorHandler::registerErrorInLog`, and re-thrown —
 *     matching api.php's existing behaviour.
 *
 * What's improved over the original:
 *
 *   - `_rental_set_grouped_items` is now written on every webhook
 *     (Step 1).
 *   - The `set/create` postmeta wipe is scoped — modern entity-3 meta,
 *     `_rental_set_order`, `_rental_sets_layout_mode`,
 *     `_rental_item_based_total`, and any user/theme postmeta survive
 *     (Step 2).
 *   - Entity 1+2 items written with the full bulk-feed shape and
 *     resolved through two batched SELECTs instead of N+1 single-row
 *     queries (Step 3).
 *   - Existing payload shape gaps (price / separate_price / required /
 *     note on top-level items) are restored from prior postmeta by
 *     `Rental_Sets_Webhook_Defense` on the `update_post_meta` filter
 *     (no extra work required here).
 *
 * @package RentopianSync\Sets
 * @since   2.14.8
 *
 * PHP requirement: 7.4+
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'Rental_Sets_Webhook_Handler', false ) ) :

class Rental_Sets_Webhook_Handler {

    /**
     * Log source tag.
     */
    const LOG_SOURCE = 'rentopian-sets-sync';

    /*
    |==========================================================================
    | Static entry points (called by the three api.php wrappers)
    |==========================================================================
    */

    /**
     * Handle a `set/create` webhook.
     *
     * @param string $set_raw  Stripped POST value (api.php passes the raw form field).
     * @return void
     */
    public static function create( $set_raw ) {
        $set = self::decode_payload( $set_raw );
        if ( ! $set ) {
            self::respond_error( 400, 'Set is empty.' );
            return;
        }
        $instance = new self();
        $instance->run_create( $set );
    }

    /**
     * Handle a `set/update` webhook.
     *
     * @param string $set_raw
     * @return void
     */
    public static function update( $set_raw ) {
        $set = self::decode_payload( $set_raw );
        if ( ! $set ) {
            self::respond_error( 400, 'Set is empty.' );
            return;
        }
        $instance = new self();
        $instance->run_update( $set );
    }

    /**
     * Handle a `set/delete` webhook.
     *
     * @param string $set_raw
     * @return void
     */
    public static function delete( $set_raw ) {
        $set = self::decode_payload( $set_raw );
        if ( ! $set ) {
            self::respond_error( 400, 'Set is empty.' );
            return;
        }
        $instance = new self();
        $instance->run_delete( $set );
    }

    /*
    |==========================================================================
    | run_create
    |==========================================================================
    */

    /**
     * Mirror of the original `rentopian_set_create()`.
     *
     * @param object $set
     * @return void
     */
    protected function run_create( $set ) {
        global $wpdb, $rental_tables;

        $rental_set_relations   = $wpdb->prefix . $rental_tables['set_relations'];
        $rental_image_relations = $wpdb->prefix . $rental_tables['image_relations'];

        $full_description = isset( $set->full_description ) && ! empty( $set->full_description ) ? $set->full_description : '';
        $description      = isset( $set->description )      && ! empty( $set->description )      ? $set->description      : '';

        // Resolve any existing WP set id from the relations table.
        $set_id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$rental_set_relations} WHERE rental_id = %d",
            (int) $set->id
        ) );

        // Accumulated SQL string for the final dbDelta() call. Same
        // ordering as the original — `UPDATE rental_set_relations`
        // first when we're recycling, then `INSERT` rows are
        // appended later.
        $sql = '';

        if ( $set_id ) {
            // Recreate-in-place branch: existing WP post + the same
            // rental_id was found. Refresh the post and wipe the
            // legacy meta so the bulk INSERT below can repopulate.
            $updated = wp_update_post( array(
                'ID'           => $set_id,
                'post_title'   => $set->title,
                'post_content' => $full_description,
                'post_excerpt' => $description,
                'post_status'  => 'publish',
            ) );

            if ( $updated ) {
                // term_relationships wipe (categories + tags get
                // recreated by rental_create_categories / _set_tags
                // below).
                $wpdb->query( $wpdb->prepare(
                    "DELETE FROM {$wpdb->term_relationships} WHERE object_id = %d",
                    $set_id
                ) );

                // Scoped postmeta wipe — preserves modern entity-3
                // meta + custom keys. Step 2.
                Rental_Sets_Webhook_Meta_Guard::scoped_wipe_for_set_create( (int) $set_id );

                // Trash any existing product variations under this
                // set — matches original behaviour.
                $wpdb->query( $wpdb->prepare(
                    "UPDATE {$wpdb->posts} SET post_status = 'trash' WHERE post_parent = %d AND post_type = 'product_variation' AND post_status = 'publish'",
                    $set_id
                ) );

                if ( isset( $wpdb->wc_product_meta_lookup ) ) {
                    $wpdb->query( $wpdb->prepare(
                        "DELETE FROM {$wpdb->wc_product_meta_lookup} WHERE product_id = %d",
                        $set_id
                    ) );
                }

                $sql .= $wpdb->prepare(
                    "UPDATE {$rental_set_relations} SET rental_division_id = %d WHERE rental_id = %d;",
                    (int) $set->division_id,
                    (int) $set->id
                );

                if ( function_exists( 'wc_delete_product_transients' ) ) {
                    wc_delete_product_transients( $set_id );
                }
            } else {
                // The post id in $rental_set_relations was stale (the
                // referenced post no longer exists). Drop the
                // relations row, zero out $set_id, fall through to
                // the insert branch.
                $wpdb->query( $wpdb->prepare(
                    "DELETE FROM {$rental_set_relations} WHERE rental_id = %d",
                    (int) $set->id
                ) );
                $set_id = 0;
            }
        }

        if ( ! $set_id ) {
            $set_id = wp_insert_post( array(
                'post_author'    => '1',
                'post_title'     => $set->title,
                'post_content'   => $full_description,
                'post_excerpt'   => $description,
                'post_status'    => 'publish',
                'comment_status' => 'open',
                'post_type'      => 'product',
            ) );
            $sql .= $wpdb->prepare(
                "INSERT INTO {$rental_set_relations} (id, rental_id, rental_division_id) VALUES (%d, %d, %d);",
                (int) $set_id,
                (int) $set->id,
                (int) $set->division_id
            );
        }

        // Image processing.
        $image_result = $this->process_images( $set, (int) $set_id );
        $thumbnail_id          = $image_result['thumbnail_id'];
        $set_image_gallery     = $image_result['gallery'];
        $image_relations_rows  = $image_result['relations_rows'];

        // Entity 1+2 items via Step 3 persister.
        $built = Rental_Sets_Webhook_Item_Persister::build( (int) $set_id, $set );
        $set_items                        = $built['items'];
        $set_items_have_optional_items    = $built['has_optional_items'];
        $set_items_have_some_hidden_items = $built['has_hidden_items'];

        // Build the bulk-INSERT postmeta SQL. Uses $wpdb->prepare to
        // bind every dynamic value, matching the original logic but
        // safer (the original interpolated several values directly).
        $sku                   = $set->number;
        $tax_status            = $set->taxable ? 'taxable' : 'none';
        $price_multiplier_id   = empty( $set->price_multiplier_id ) ? 0 : (int) $set->price_multiplier_id;
        $hide_items_on_website = isset( $set->hide_items_on_website ) ? (int) $set->hide_items_on_website : 0;
        $set_max_qty           = isset( $set->max_quantity ) ? $set->max_quantity : '';

        $this->insert_postmeta_for_set_create(
            (int) $set_id,
            $set,
            $set_items,
            $set_items_have_optional_items,
            $set_items_have_some_hidden_items,
            array(
                'sku'                   => $sku,
                'tax_status'            => $tax_status,
                'price_multiplier_id'   => $price_multiplier_id,
                'hide_items_on_website' => $hide_items_on_website,
                'set_max_qty'           => $set_max_qty,
                'thumbnail_id'          => $thumbnail_id,
                'set_image_gallery'     => $set_image_gallery,
            )
        );

        // The 'simple' product_type term — required for WC to treat
        // this post as a simple product.
        $simple = $wpdb->get_var( $wpdb->prepare(
            "SELECT term_id FROM {$wpdb->terms} WHERE name = %s AND slug = %s",
            'simple', 'simple'
        ) );
        if ( $simple ) {
            $sql .= $wpdb->prepare(
                "INSERT INTO {$wpdb->term_relationships} (object_id, term_taxonomy_id, term_order) VALUES (%d, %d, 0);",
                (int) $set_id,
                (int) $simple
            );
        }

        // wc_product_meta_lookup.
        if ( isset( $wpdb->wc_product_meta_lookup ) ) {
            $wpdb->query( $wpdb->prepare(
                "INSERT INTO {$wpdb->wc_product_meta_lookup} (product_id, sku, virtual, downloadable, min_price, max_price, onsale, stock_quantity, stock_status, tax_status) VALUES (%d, %s, 0, 0, %f, %f, 0, 1, %s, %s)",
                (int) $set_id,
                (string) $sku,
                (float) $set->rental_price,
                (float) $set->rental_price,
                'instock',
                (string) $tax_status
            ) );
        }

        // image_relations rows.
        if ( ! empty( $image_relations_rows ) ) {
            $sql .= $this->build_image_relations_sql_fragment( $rental_image_relations, $image_relations_rows );
        }

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );

        $cats = array();
        if ( ! empty( $set->categories ) && function_exists( 'rental_create_categories' ) ) {
            $cats = rental_create_categories( $set->categories, $set_id );
        }
        $tags = array();
        if ( ! empty( $set->tags ) && function_exists( 'rental_create_set_tags' ) ) {
            $product_tags = isset( $set->product_tags ) ? $set->product_tags : array();
            $tags = rental_create_set_tags( $set->tags, $set_id, $product_tags );
        }

        $this->apply_up_sells_and_cross_sells( (int) $set_id, $set );

        // Entity 3 (composite groups)
        Rental_Sets_Webhook_Group_Persister::persist( (int) $set_id, $set );

        // Set-level pricing model flag (Fixed Total vs Item-Based Total).
        $this->persist_item_based_total( (int) $set_id, $set );

        // Display order across entity 1+2+3.
        $this->persist_set_order( (int) $set_id, $set );

        if ( $wpdb->last_error !== '' ) {
            $error = 'SQL_ERROR: ' . $wpdb->last_error . ' (SQL: ' . $wpdb->last_query . ')';
            self::respond_error( 400, $error );
            if ( class_exists( 'RentalException', false ) ) {
                throw new RentalException( $error );
            }
            return;
        }

        if ( function_exists( 'rental_clear_cache' ) ) {
            rental_clear_cache();
        }

        // Polylang: default-language assignment + translation queue.
        if ( function_exists( 'rental_pll_assign_post' ) ) {
            rental_pll_assign_post( (int) $set_id );
        }
        if ( function_exists( 'rental_translation_queue_post' ) ) {
            rental_translation_queue_post( (int) $set_id );
        }

        self::respond_success( array(
            'set' => array(
                'rental_id'  => (int) $set->id,
                'id'         => (int) $set_id,
                'image'      => $thumbnail_id,
                'items'      => $set_items,
                'categories' => $cats,
                'tags'       => $tags,
            ),
            'message' => 'Set successfully created!',
        ) );
    }

    /*
    |==========================================================================
    | run_update
    |==========================================================================
    */

    /**
     * Mirror of the original `rentopian_set_update()`.
     *
     * @param object $set
     * @return void
     */
    protected function run_update( $set ) {
        global $wpdb, $rental_tables;

        $rental_set_relations   = $wpdb->prefix . $rental_tables['set_relations'];
        $rental_image_relations = $wpdb->prefix . $rental_tables['image_relations'];

        $full_description = isset( $set->full_description ) && ! empty( $set->full_description ) ? $set->full_description : '';
        $description      = isset( $set->description )      && ! empty( $set->description )      ? $set->description      : '';

        $set_id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$rental_set_relations} WHERE rental_id = %d",
            (int) $set->id
        ) );
        if ( ! $set_id ) {
            self::respond_error( 404, sprintf( 'Set with rental_id %d not found.', (int) $set->id ) );
            return;
        }

        $updated = wp_update_post( array(
            'ID'           => $set_id,
            'post_title'   => $set->title,
            'post_content' => $full_description,
            'post_excerpt' => $description,
        ) );
        if ( ! $updated ) {
            self::respond_error( 404, sprintf( 'Post with ID %d not found.', (int) $set_id ) );
            return;
        }

        $tax_status          = $set->taxable ? 'taxable' : 'none';
        $price_multiplier_id = empty( $set->price_multiplier_id ) ? 0 : (int) $set->price_multiplier_id;

        update_post_meta( $set_id, '_sku',                  $set->number );
        update_post_meta( $set_id, '_regular_price',        $set->rental_price );
        update_post_meta( $set_id, '_job_cost',             $set->job_cost );
        update_post_meta( $set_id, '_price_multiplier_id',  $price_multiplier_id );
        update_post_meta( $set_id, '_tax_status',           $tax_status );
        update_post_meta( $set_id, '_price',                $set->rental_price );
        update_post_meta( $set_id, '_rental_exempt_waiver', $set->exempt_waiver );
        update_post_meta( $set_id, '_rental_is_sale',       $set->is_sale );

        if ( isset( $wpdb->wc_product_meta_lookup ) ) {
            $wpdb->query( $wpdb->prepare(
                "UPDATE {$wpdb->wc_product_meta_lookup} SET sku = %s, min_price = %f, max_price = %f, tax_status = %s WHERE product_id = %d",
                (string) $set->number,
                (float) $set->rental_price,
                (float) $set->rental_price,
                (string) $tax_status,
                (int) $set_id
            ) );
        }

        $sql = $wpdb->prepare(
            "UPDATE {$rental_set_relations} SET rental_division_id = %d WHERE rental_id = %d;",
            (int) $set->division_id,
            (int) $set->id
        );

        if ( function_exists( 'wc_delete_product_transients' ) ) {
            wc_delete_product_transients( $set_id );
        }

        // Image processing.
        $image_result = $this->process_images( $set, (int) $set_id );
        $thumbnail_id          = $image_result['thumbnail_id'];
        $set_image_gallery     = $image_result['gallery'];
        $image_relations_rows  = $image_result['relations_rows'];

        update_post_meta( $set_id, '_thumbnail_id',          $thumbnail_id );
        update_post_meta( $set_id, '_product_image_gallery', $set_image_gallery );

        // Entity 1+2 items via Step 3 persister.
        $built = Rental_Sets_Webhook_Item_Persister::build( (int) $set_id, $set );
        $set_items                        = $built['items'];
        $set_items_have_optional_items    = $built['has_optional_items'];
        $set_items_have_some_hidden_items = $built['has_hidden_items'];

        update_post_meta( $set_id, '_rental_set_items_default',              $set_items );
        update_post_meta( $set_id, '_rental_set_items',                      $set_items );
        update_post_meta( $set_id, '_rental_set_items_have_optional_items',  $set_items_have_optional_items );

        $hide_items_on_website = isset( $set->hide_items_on_website ) ? (int) $set->hide_items_on_website : 0;
        update_post_meta( $set_id, '_rental_hide_items_on_website', $hide_items_on_website );
        update_post_meta( $set_id, '_rental_some_hidden_items',     $set_items_have_some_hidden_items );

        $set_max_qty = isset( $set->max_quantity ) ? $set->max_quantity : '';
        update_post_meta( $set_id, '_rental_max_quantity', $set_max_qty );

        if ( ! empty( $image_relations_rows ) ) {
            $sql .= $this->build_image_relations_sql_fragment( $rental_image_relations, $image_relations_rows );
        }

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );

        $cats = array();
        if ( function_exists( 'rental_create_categories' ) ) {
            $cats = rental_create_categories( isset( $set->categories ) ? $set->categories : array(), $set_id );
        }
        $product_tags = isset( $set->product_tags ) ? $set->product_tags : array();
        $tags = array();
        if ( function_exists( 'rental_create_set_tags' ) ) {
            $tags = rental_create_set_tags( isset( $set->tags ) ? $set->tags : array(), $set_id, $product_tags );
        }

        $this->apply_up_sells_and_cross_sells( (int) $set_id, $set );

        // Entity 3 (composite groups) via Step 1 persister.
        Rental_Sets_Webhook_Group_Persister::persist( (int) $set_id, $set );

        // Set-level pricing model flag (Fixed Total vs Item-Based Total).
        $this->persist_item_based_total( (int) $set_id, $set );

        // Display order across entity 1+2+3.
        $this->persist_set_order( (int) $set_id, $set );

        if ( function_exists( 'rental_clear_cache' ) ) {
            rental_clear_cache();
        }

        // Polylang.
        if ( function_exists( 'rental_pll_assign_post' ) ) {
            rental_pll_assign_post( (int) $set_id );
        }
        if ( function_exists( 'rental_translation_queue_post' ) ) {
            rental_translation_queue_post( (int) $set_id );
        }

        self::respond_success( array(
            'set' => array(
                'rental_id'  => (int) $set->id,
                'id'         => (int) $set_id,
                'categories' => $cats,
                'items'      => $set_items,
                'tags'       => $tags,
            ),
            'message' => 'Set successfully updated!',
        ) );
    }

    /*
    |==========================================================================
    | run_delete
    |==========================================================================
    */

    /**
     * Mirror of the original `rentopian_set_delete()`.
     *
     * @param object $set
     * @return void
     */
    protected function run_delete( $set ) {
        global $wpdb, $rental_tables;
        $rental_set_relations = $wpdb->prefix . $rental_tables['set_relations'];

        $set_id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$rental_set_relations} WHERE rental_id = %d",
            (int) $set->id
        ) );
        if ( ! $set_id ) {
            self::respond_error( 404, sprintf( 'Set with rental_id %d not found.', (int) $set->id ) );
            return;
        }

        wp_update_post( array(
            'ID'          => $set_id,
            'post_status' => 'trash',
        ) );

        if ( function_exists( 'rental_clear_cache' ) ) {
            rental_clear_cache();
        }

        self::respond_success( array(
            'set' => array(
                'rental_id' => (int) $set->id,
                'id'        => (int) $set_id,
            ),
            'message' => 'Set successfully deleted!',
        ) );
    }

    /*
    |==========================================================================
    | Image processing — extracted from the original inline block
    |==========================================================================
    */

    /**
     * Walk $set->images, return:
     *   - thumbnail_id (WP attachment id for the marked thumbnail, or '')
     *   - gallery       (comma-separated string of attachment ids, or '')
     *   - relations_rows (array of [attach_id, rental_image_id] pairs ready
     *                     to INSERT into rental_image_relations)
     *
     * Identical flow to the original — fetches new images via
     * `rental_curl` / `rentopian_webhook_download_image`. RentalException
     * is logged and re-thrown the same way.
     *
     * @param object $set
     * @param int    $set_id
     * @return array
     */
    protected function process_images( $set, $set_id ) {
        global $wpdb, $rental_tables;
        $rental_image_relations = $wpdb->prefix . $rental_tables['image_relations'];

        $thumbnail_id      = '';
        $set_image_gallery = array();
        $relations_rows    = array();

        if ( empty( $set->images ) ) {
            return array(
                'thumbnail_id'   => $thumbnail_id,
                'gallery'        => '',
                'relations_rows' => $relations_rows,
            );
        }

        $get_images = array();
        $set_img_id = isset( $set->img_id ) ? (int) $set->img_id : 0;

        foreach ( $set->images as $img ) {
            $img_id = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$rental_image_relations} WHERE rental_id = %d",
                (int) $img->id
            ) );
            if ( $img_id ) {
                if ( (int) $img->id === $set_img_id ) {
                    $thumbnail_id = $img_id;
                } else {
                    $set_image_gallery[] = $img_id;
                }
            } else {
                $get_images[] = (int) $img->id;
            }
        }

        if ( ! empty( $get_images ) && function_exists( 'rental_curl' ) ) {
            $api_key = get_option( 'rental_api_key' );

            try {
                $new_images = rental_curl( 'files/images/stream', $api_key, true, array( 'images' => wp_json_encode( $get_images ) ) );
            } catch ( \Exception $e ) {
                if ( class_exists( 'ErrorHandler', false ) && method_exists( 'ErrorHandler', 'registerErrorInLog' ) ) {
                    $type        = method_exists( $e, 'getType' )       ? $e->getType()       : null;
                    $status_code = method_exists( $e, 'getStatusCode' ) ? $e->getStatusCode() : null;
                    ErrorHandler::registerErrorInLog(
                        'API fetch failed in product create webhook: ' . $e->getMessage(),
                        __FILE__, __LINE__,
                        $type,
                        null,
                        $status_code
                    );
                }
                throw $e;
            }

            if ( ! empty( $new_images ) && function_exists( 'rentopian_webhook_download_image' ) ) {
                foreach ( $new_images as $image ) {
                    if ( empty( $image->url ) ) {
                        continue;
                    }
                    $attach_id = rentopian_webhook_download_image( $image->url, $image->id, $image, $set_id );
                    if ( ! $attach_id ) {
                        continue;
                    }
                    $relations_rows[] = array( (int) $attach_id, (int) $image->id );

                    if ( ! $thumbnail_id && (int) $image->id === $set_img_id ) {
                        $thumbnail_id = $attach_id;
                    } else {
                        $set_image_gallery[] = $attach_id;
                    }
                }
            }
        }

        return array(
            'thumbnail_id'   => $thumbnail_id,
            'gallery'        => empty( $set_image_gallery ) ? '' : implode( ',', $set_image_gallery ),
            'relations_rows' => $relations_rows,
        );
    }

    /**
     * Build the appended INSERT fragment for rental_image_relations.
     * Matches the original `($attach_id, $image->id)` row layout.
     *
     * @param string $table
     * @param array  $rows   [ [attach_id, rental_image_id], ... ]
     * @return string  SQL fragment ending in ';' (safe to append to $sql).
     */
    protected function build_image_relations_sql_fragment( $table, array $rows ) {
        global $wpdb;
        if ( empty( $rows ) ) {
            return '';
        }
        $values  = array();
        $args    = array();
        foreach ( $rows as $row ) {
            $values[] = '(%d, %d)';
            $args[]   = (int) $row[0];
            $args[]   = (int) $row[1];
        }
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $sql = "INSERT INTO {$table} (id, rental_id) VALUES " . implode( ', ', $values ) . ';';
        return $wpdb->prepare( $sql, $args );
    }

    /*
    |==========================================================================
    | Set/create postmeta bulk-INSERT — extracted with same shape as the
    |                                    original
    |==========================================================================
    */

    /**
     * Build and run the `set/create` bulk postmeta INSERT. Wraps every
     * value through $wpdb->prepare for safety while preserving the
     * exact 47-key shape of the original.
     *
     * @param int    $set_id
     * @param object $set
     * @param array  $set_items
     * @param bool   $set_items_have_optional_items
     * @param bool   $set_items_have_some_hidden_items
     * @param array  $derived  { sku, tax_status, price_multiplier_id, hide_items_on_website, set_max_qty, thumbnail_id, set_image_gallery }
     * @return void
     */
    protected function insert_postmeta_for_set_create(
        $set_id,
        $set,
        $set_items,
        $set_items_have_optional_items,
        $set_items_have_some_hidden_items,
        array $derived
    ) {
        global $wpdb;

        $rental_price = isset( $set->rental_price ) ? $set->rental_price : 0;
        $job_cost     = isset( $set->job_cost )     ? $set->job_cost     : 0;

        $serialized_items = serialize( $set_items );

        // Each row uses (%d, %s, %s) — post_id is int; meta_key and
        // meta_value bind as strings. Keys ordered identically to the
        // original VALUES list.
        $rows = array(
            array( $set_id, '_wc_review_count',                       '0' ),
            array( $set_id, '_wc_rating_count',                       'a:0:{}' ),
            array( $set_id, '_wc_average_rating',                     '0' ),
            array( $set_id, '_edit_last',                             '' ),
            array( $set_id, '_edit_lock',                             '' ),
            array( $set_id, '_sku',                                   (string) $derived['sku'] ),
            array( $set_id, '_regular_price',                         (string) $rental_price ),
            array( $set_id, '_job_cost',                              (string) $job_cost ),
            array( $set_id, '_price_multiplier_id',                   (string) $derived['price_multiplier_id'] ),
            array( $set_id, '_sale_price',                            '' ),
            array( $set_id, '_sale_price_dates_from',                 '' ),
            array( $set_id, '_sale_price_dates_to',                   '' ),
            array( $set_id, 'total_sales',                            '0' ),
            array( $set_id, '_tax_status',                            (string) $derived['tax_status'] ),
            array( $set_id, '_tax_class',                             '' ),
            array( $set_id, '_manage_stock',                          'no' ),
            array( $set_id, '_backorders',                            'yes' ),
            array( $set_id, '_sold_individually',                     'no' ),
            array( $set_id, '_weight',                                '' ),
            array( $set_id, '_length',                                '' ),
            array( $set_id, '_width',                                 '' ),
            array( $set_id, '_height',                                '' ),
            array( $set_id, '_depth',                                 '' ),
            array( $set_id, '_upsell_ids',                            'a:0:{}' ),
            array( $set_id, '_crosssell_ids',                         'a:0:{}' ),
            array( $set_id, '_purchase_note',                         '' ),
            array( $set_id, '_default_attributes',                    'a:0:{}' ),
            array( $set_id, '_virtual',                               'no' ),
            array( $set_id, '_downloadable',                          'no' ),
            array( $set_id, '_product_image_gallery',                 (string) $derived['set_image_gallery'] ),
            array( $set_id, '_download_limit',                        '-1' ),
            array( $set_id, '_download_expiry',                       '-1' ),
            array( $set_id, '_stock',                                 '1' ),
            array( $set_id, '_stock_status',                          'instock' ),
            array( $set_id, '_product_version',                       '3.2.3' ),
            array( $set_id, '_price',                                 (string) $rental_price ),
            array( $set_id, 'zoo_cw_product_swatch_data',             'a:0:{}' ),
            array( $set_id, '_thumbnail_id',                          (string) $derived['thumbnail_id'] ),
            array( $set_id, '_rental_exempt_waiver',                  (string) $set->exempt_waiver ),
            array( $set_id, '_rental_is_sale',                        (string) $set->is_sale ),
            array( $set_id, '_rental_is_add_on',                      '0' ),
            array( $set_id, '_rental_set_items_have_optional_items',  $set_items_have_optional_items ? '1' : '' ),
            array( $set_id, '_rental_set_items',                      $serialized_items ),
            array( $set_id, '_rental_set_items_default',              $serialized_items ),
            array( $set_id, '_rental_hide_items_on_website',          (string) $derived['hide_items_on_website'] ),
            array( $set_id, '_rental_some_hidden_items',              $set_items_have_some_hidden_items ? '1' : '' ),
            array( $set_id, '_rental_max_quantity',                   (string) $derived['set_max_qty'] ),
            array( $set_id, '_rental_is_set',                         '1' ),
        );

        $placeholders = array();
        $args         = array();
        foreach ( $rows as $row ) {
            $placeholders[] = '(%d, %s, %s)';
            $args[]         = $row[0];
            $args[]         = $row[1];
            $args[]         = $row[2];
        }
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $sql = "INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value) VALUES " . implode( ', ', $placeholders );
        $wpdb->query( $wpdb->prepare( $sql, $args ) );
    }

    /*
    |==========================================================================
    | Up-sells + cross-sells
    |==========================================================================
    */

    /**
     * Apply up-sell + cross-sell rels. Delegates to the existing global
     * helper functions `set_sets_up_sells` / `set_sets_cross_sells` to
     * preserve behaviour exactly — those functions also do the
     * `wp_set_object_terms` calls that the original api.php was
     * running.
     *
     * @param int    $set_id
     * @param object $set
     * @return void
     */
    protected function apply_up_sells_and_cross_sells( $set_id, $set ) {
        $up_sells_sets        = ! empty( $set->up_sells_sets )        ? $set->up_sells_sets        : array();
        $up_sells_products    = ! empty( $set->up_sells_products )    ? $set->up_sells_products    : array();
        $cross_sells_sets     = ! empty( $set->cross_sells_sets )     ? $set->cross_sells_sets     : array();
        $cross_sells_products = ! empty( $set->cross_sells_products ) ? $set->cross_sells_products : array();

        if ( function_exists( 'set_sets_up_sells' ) ) {
            set_sets_up_sells( $set_id, $up_sells_sets, $up_sells_products );
        }
        if ( function_exists( 'set_sets_cross_sells' ) ) {
            set_sets_cross_sells( $set_id, $cross_sells_sets, $cross_sells_products );
        }
    }

    /*
    |==========================================================================
    | Set display order (_rental_set_order)
    |==========================================================================
    */

    /**
     * Normalise the webhook payload's `set_order` into the canonical
     * JSON string stored in the `_rental_set_order` postmeta, matching
     * the bulk-sync format (Rental_Sets_Sql_Builder::set_postmeta_rows
     * → `wp_json_encode( array_values( (array) $set_order ) )`).
     *
     * The single-set webhook (`set/create`, `set/update`) historically
     * never persisted this key, so reordering set items in Rentopian
     * and saving a SINGLE set produced no ordering change on the WP
     * side — only a full bulk re-sync did. This restores parity.
     *
     * Returns NULL when the payload does NOT carry a `set_order` key at
     * all, so the caller can PRESERVE whatever a prior bulk sync wrote
     * rather than clobbering it with an empty array. When the key IS
     * present (even an explicit empty array — "no custom order"), the
     * JSON string is returned and the caller overwrites the meta.
     *
     * @param object $set  Decoded webhook payload.
     * @return string|null JSON-encoded UID array, or null when absent.
     */
    protected function normalize_set_order( $set ) {
        if ( ! isset( $set->set_order ) ) {
            return null;
        }

        $raw = $set->set_order;

        // Laravel may ship set_order either as a JSON string (the raw
        // `inventory_sets.set_order` column) or as an already-decoded
        // array. Mirror the bulk SQL builder: decode strings first,
        // then re-encode a clean list.
        if ( is_string( $raw ) ) {
            $decoded = json_decode( $raw, true );
            $raw     = is_array( $decoded ) ? $decoded : array();
        }

        return wp_json_encode( array_values( (array) $raw ) );
    }

    /**
     * Persist `_rental_set_order` from the payload when present.
     * Uses update_post_meta (replace semantics) so the create-recreate
     * path — where Meta_Guard::scoped_wipe_for_set_create deliberately
     * PRESERVES the prior `_rental_set_order` — never ends up with two
     * rows for the key. No-ops when the payload omits set_order.
     *
     * @param int    $set_id
     * @param object $set
     * @return void
     */
    protected function persist_set_order( $set_id, $set ) {
        $set_order_json = $this->normalize_set_order( $set );

        if ( null === $set_order_json ) {
            // Payload omitted set_order entirely — preserve the existing
            // postmeta.
            if ( class_exists( 'Project_WP_Logger', false ) ) {
                Project_WP_Logger::write(
                    sprintf( 'Webhook set_order: ABSENT from payload for set %d (preserving existing order meta).', (int) $set_id ),
                    'info',
                    self::LOG_SOURCE
                );
            }
            return;
        }

        update_post_meta( (int) $set_id, '_rental_set_order', $set_order_json );

        if ( class_exists( 'Project_WP_Logger', false ) ) {
            Project_WP_Logger::write(
                sprintf( 'Webhook set_order: persisted for set %d → %s', (int) $set_id, $set_order_json ),
                'info',
                self::LOG_SOURCE
            );
        }
    }

    /**
     * Persist `_rental_item_based_total` from the payload when present.
     *
     * The single-set webhook historically never wrote this set-level
     * pricing flag, so toggling "Fixed Total" vs "Item-Based Total" in
     * Rentopian and saving a SINGLE set produced no change on the WP side
     * (only a full bulk re-sync did — that path writes the flag in
     * Rental_Sets_Sql_Builder). Mirrors the bulk sync, which reads
     * `$set->item_based_total`.
     *
     * Uses update_post_meta (replace semantics) so the create-recreate
     * path — where Meta_Guard deliberately PRESERVES the prior value
     * across the scoped wipe — never ends up with two rows for the key.
     * No-ops when the payload omits the field, so a partial payload never
     * clobbers a known value (mirrors persist_set_order's contract).
     *
     * @param int    $set_id
     * @param object $set
     * @return void
     */
    protected function persist_item_based_total( $set_id, $set ) {
        if ( ! isset( $set->item_based_total ) ) {
            if ( class_exists( 'Project_WP_Logger', false ) ) {
                Project_WP_Logger::write(
                    sprintf( 'Webhook item_based_total: ABSENT from payload for set %d (preserving existing).', (int) $set_id ),
                    'info',
                    self::LOG_SOURCE
                );
            }
            return;
        }

        $value = (int) $set->item_based_total ? '1' : '0';
        update_post_meta( (int) $set_id, '_rental_item_based_total', $value );

        if ( class_exists( 'Project_WP_Logger', false ) ) {
            Project_WP_Logger::write(
                sprintf( 'Webhook item_based_total: persisted %s for set %d.', $value, (int) $set_id ),
                'info',
                self::LOG_SOURCE
            );
        }
    }

    /*
    |==========================================================================
    | Payload + response helpers
    |==========================================================================
    */

    /**
     * Decode the raw `set` form field exactly the way api.php's three
     * inline functions did: `json_decode( stripslashes( $raw ) )`.
     *
     * @param string $raw
     * @return object|null
     */
    protected static function decode_payload( $raw ) {
        if ( ! is_string( $raw ) && ! is_numeric( $raw ) ) {
            return null;
        }
        $decoded = json_decode( stripslashes( (string) $raw ) );
        if ( ! is_object( $decoded ) ) {
            return null;
        }
        return $decoded;
    }

    /**
     * Emit `http_response_code` + a JSON error body and return. Used
     * for the 400 / 404 paths.
     *
     * @param int    $code
     * @param string $message
     * @return void
     */
    protected static function respond_error( $code, $message ) {
        http_response_code( (int) $code );
        echo wp_json_encode( array( 'error' => (string) $message ) );
    }

    /**
     * Emit a 200 + JSON body.
     *
     * @param array $body
     * @return void
     */
    protected static function respond_success( array $body ) {
        http_response_code( 200 );
        echo wp_json_encode( $body );
    }
}

endif;
