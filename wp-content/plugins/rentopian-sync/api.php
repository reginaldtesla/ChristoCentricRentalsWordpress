<?php
/**
 * @link       https://rentopian.com
 * @since      1.0.0
 *
 * @package    rentopian-sync
 */

//$plugin_folder_name = basename(__DIR__);
//require_once(str_replace("wp-content/plugins/$plugin_folder_name", "wp-blog-header.php", __DIR__));

// require_once('../../../wp-blog-header.php');
// Direct webhook hits bootstrap WordPress; in library mode it is already loaded.
if ( ! defined('ABSPATH') ) {
    require_once __DIR__ . "/../../../wp-load.php";
}

require_once RENTOPIAN_SYNC_PATH . '/includes/rental-polylang-api-helpers.php';

if ( !defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

// Library mode: the background data sync includes this file only for its
// handler functions; skip the webhook dispatcher below entirely.
if (defined('RENTAL_DATA_SYNC_HANDLER_LOAD')) {
    return;
}

header("Content-Type: application/json");

if ( !isset($_POST['action'])) {
    http_response_code(404);
    echo json_encode(["error" => "Not found"]);
    exit;
}
$action = $_POST['action'];

if ($action === '0/sync/status') {
    echo json_encode(["sync_status_0" => update_option("rental_synchronize_status", 0)]);
    exit;
}

$headers = [];
if ( function_exists( 'getallheaders' ) ) {
    $headers = getallheaders();
} else {
    function getallheaders() {
        $headers = [];
        foreach ($_SERVER as $name => $value) {
            if (substr($name, 0, 5) == 'HTTP_') {
                $headers[str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))))] = $value;
            }
        }
        return $headers;
    }
    $headers = getallheaders();
}

$rentopian_authorization = isset($headers["Rentopian-Authorization"])? $headers["Rentopian-Authorization"]:
    (isset($headers["rentopian-authorization"])? $headers["rentopian-authorization"]: '');
$api_key_in_header = $rentopian_authorization? trim(str_ireplace('bearer', '', $rentopian_authorization)): '';
$api_key = get_option('rental_api_key');

if ( !$api_key_in_header || $api_key_in_header !== $api_key) {
    http_response_code(401);
    echo json_encode([
        "error" => "Wrong API key provided.",
        "headers" => $headers,
        "rentopian_authorization_in_header" => $rentopian_authorization,
        "api_key_in_header" => $api_key_in_header,
        "local_api_key_exists" => (bool) $api_key,
    ]);
    //exit;
}

if ( !get_option('rental_synchronize_status')) {
    http_response_code(401);
    echo json_encode(["error" => "Synchronization was not completed."]);
    exit;
}

switch ($action) {
    case 'product/create':
        if (isset($_POST['product']) && $product = $_POST['product']) {
            rentopian_product_create($product);
        }
        break;
    case 'product/update':
        if (isset($_POST['product']) && $product = $_POST['product']) {
            rentopian_product_update($product);
        }
        break;
    case 'product/delete':
        if (isset($_POST['product']) && $product = $_POST['product']) {
            rentopian_product_delete($product);
        }
        break;
    case 'product/images':
        if (isset($_POST['product']) && $product = $_POST['product']) {
            rentopian_product_image($product);
        }
        break;
    case 'attribute/attach':
        rentopian_attach_attribute_to_product();
        break;
    case 'attribute/detach':
        rentopian_detach_attribute_from_product();
        break;
    case 'attribute/create':
    case 'attribute/update':
        rentopian_save_product_attribute();
        break;
    case 'attribute/value/create':
    case 'attribute/value/update':
        rentopian_product_attribute_value_handler('save');
        break;
    case 'attribute/value/delete':
        rentopian_product_attribute_value_handler('delete');
        break;
    case 'attribute/value/group/sync':
        rentopian_attribute_value_group_sync();
        break;
    case 'variant/create':
        if (isset($_POST['variant']) && $variant = $_POST['variant']) {
            rentopian_variant_create($variant);
        }
        break;
    case 'variant/update':
        if (isset($_POST['variant']) && $variant = $_POST['variant']) {
            rentopian_variant_update($variant);
        }
        break;
    case 'variant/delete':
        if (isset($_POST['variant']) && $variant = $_POST['variant']) {
            rentopian_variant_delete($variant);
        }
        break;
    case 'variant/update_items_qty':
        if (isset($_POST['items']) && $items = $_POST['items']) {
            rentopian_update_items_qty($items);
        }
        break;
/*
    case 'variant/image/create':
        if (isset($_POST['variant_image']) && $image = $_POST['variant_image']) {
            rentopian_variant_image_create($image);
        }
        break;
    case 'variant/image/delete':
        if (isset($_POST['variant_image']) && $image = $_POST['variant_image']) {
            rentopian_variant_image_delete($image);
        }
        break;
*/
    case 'set/create':
        if (isset($_POST['set']) && $set = $_POST['set']) {
            rentopian_set_create($set);
        }
        break;
    case 'set/update':
        if (isset($_POST['set']) && $set = $_POST['set']) {
            rentopian_set_update($set);
        }
        break;
    case 'set/delete':
        if (isset($_POST['set']) && $set = $_POST['set']) {
            rentopian_set_delete($set);
        }
        break;
    case 'category/create':
        if (isset($_POST['category']) && $category = $_POST['category']) {
            rentopian_category_create($category);
        }
        break;
    case 'category/update':
        if (isset($_POST['category']) && $category = $_POST['category']) {
            rentopian_category_update($category);
        }
        break;
    case 'category/delete':
        if (isset($_POST['category']) && $category = $_POST['category']) {
            rentopian_category_delete($category);
        }
        break;
    case 'categories/sorted':
        if (isset($_POST['categories']) && $categories = $_POST['categories']) {
            rentopian_categories_sorted($categories);
        }
        break;
    case 'brand/create':
    case 'brand/update':
        rentopian_save_brand();
        break;
    case 'brand/delete':
        rentopian_delete_brand();
        break;
    case 'divisions/update':
        rentopian_update_divisions();
        break;
    //shipping zone api endpoints
    case 'shipping/zone/create':
        if (isset($_POST['shipping_zone']) && $shipping_zone = $_POST['shipping_zone']) {
            rentopian_save_shipping_zone($shipping_zone);
        }
        break;
    case 'shipping/zone/update':
        if (isset($_POST['shipping_zone']) && $shipping_zone = $_POST['shipping_zone']) {
            rentopian_update_shipping_zone($shipping_zone);
        }
        break;
    case 'shipping/zone/delete':
        if (isset($_POST['shipping_zone']) && $shipping_zone = $_POST['shipping_zone']) {
            rentopian_delete_shipping_zone($shipping_zone);
        }
        break;
    case 'shipping/settings/update':
        if (isset($_POST['settings']) && $shipping_settings = $_POST['settings']) {
            rentopian_update_shipping_settings($shipping_settings);
        }
        break;
    case 'coupon/create':
    case 'coupon/update':
        rentopian_save_coupon();
        break;
    case 'coupon/delete':
        rentopian_delete_coupon();
        break;
    case 'price_multiplier/set':
        rentopian_save_price_multiplier();
        break;
    case 'price_multiplier/delete':
        rentopian_delete_price_multiplier();
        break;
    case 'option/create':
    case 'option/update':
        rentopian_save_product_options();
        break;
    case 'option/delete':
        rentopian_delete_product_options();
    break;
    case 'inventoryBlock/create':
    case 'inventoryBlock/update':
        rentopian_save_blocked_inventories();
        break;
    case 'inventoryBlock/delete':
        rentopian_delete_blocked_inventories();
        break;
    case 'api/key_updated':
        rentopian_api_key_updated();
        break;
    case 'order_settings/update':
        rentopian_order_settings_updated();
        break;
    case 'company_settings/updated':
        rentopian_company_settings_updated();
        break;
    case 'set/option/create':
    case 'set/option/update':
        rentopian_save_set_options();
        break;
    case 'set/option/delete':
        rentopian_delete_set_options();
    break;
    case 'file/update':
        rentopian_file_update();
    break;
    case 'referral_sources/set':
        rentopian_set_referral_sources();
    case 'event_types/set':
        rentopian_set_event_types();
    case 'payment_tips/set':
        rentopian_set_payment_tips();
    break;
    case 'order/retry-invalid':
        rentopian_retry_failed_order();
    break;
    case 'images/heal':
        rentopian_heal_broken_images();
    break;
    default:
        http_response_code(400);
        echo json_encode([
            "error" => "Wrong action",
            "action" => $action
        ]);
}

/**
 * Centralized webhook image download helper.
 *
 * Delegates to Rental_Image_Downloader::download_and_attach() so every
 * webhook handler (product/create, set/create, set/update, product/images,
 * category/create, category/update, RTImage, etc.) uses the same path that
 * includes:
 *   - rental_bypass_mime_check filter (prevents mime-type rejections)
 *   - Rental_Image_Performance_Filter around subsizing
 *   - Proper tmp file cleanup
 *   - Consistent error logging
 *
 * Falls back to inline download+upload when Rental_Image_Downloader is not
 * available (backward compatibility with older plugin builds).
 *
 * @param string      $url        Direct/signed URL to download.
 * @param int         $rental_id  Rentopian image ID.
 * @param object|null $image_meta Optional API image object (->mime, ->label, ->description).
 * @param int         $parent_id  Optional WP post parent for the attachment.
 * @return int Attachment ID on success, 0 on failure.
 */
function rentopian_webhook_download_image( $url, $rental_id, $image_meta = null, $parent_id = 0 ) {

    // ── Preferred path: use the centralized Rental_Image_Downloader ─────
    if ( class_exists( 'Rental_Image_Downloader', false ) ) {
        // download_and_attach() handles: mime filter, perf filters,
        // subsizing, tmp cleanup, error logging — everything.
        $sync_id = 'webhook_' . time();
        $attach_id = Rental_Image_Downloader::download_and_attach( $url, $rental_id, $sync_id, $image_meta );
        return (int) $attach_id;
    }

    // ── Fallback: inline download + upload (same logic, but with fixes) ─
    // This runs only if the file-sync module is not loaded (e.g. older build).
    if ( ! function_exists( 'download_url' ) ) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
    }
    require_once ABSPATH . 'wp-admin/includes/image.php';

    $rental_id = (int) $rental_id;

    // Download
    $tmp_file = download_url( $url );
    if ( is_wp_error( $tmp_file ) ) {
        if ( class_exists( 'ErrorHandler', false ) && class_exists( 'RentalException', false ) ) {
            ErrorHandler::registerErrorInLog(
                "Download failed for image {$rental_id} in webhook: " . $tmp_file->get_error_message(),
                __FILE__, __LINE__,
                RentalException::TYPE_SYNC_RUNTIME
            );
        }
        return 0;
    }

    // Upload with mime bypass
    $has_mime_filter = function_exists( 'rental_bypass_mime_check' );
    if ( $has_mime_filter ) {
        add_filter( 'wp_check_filetype_and_ext', 'rental_bypass_mime_check', 10, 4 );
    }

    // Strip query string from URL before extracting filename for wp_upload_bits
    $clean_url = strtok( $url, '?' );
    $filename  = basename( $clean_url );
    // Fallback if basename still yields nothing useful
    if ( empty( $filename ) || strpos( $filename, '.' ) === false ) {
        $filename = $rental_id . '.jpg';
    }

    $upload = wp_upload_bits( $filename, null, file_get_contents( $tmp_file ) );

    if ( $has_mime_filter ) {
        remove_filter( 'wp_check_filetype_and_ext', 'rental_bypass_mime_check', 10 );
    }

    // Always clean up tmp file after reading its contents
    if ( is_string( $tmp_file ) && file_exists( $tmp_file ) ) {
        @unlink( $tmp_file );
    }

    if ( ! empty( $upload['error'] ) ) {
        if ( class_exists( 'ErrorHandler', false ) && class_exists( 'RentalException', false ) ) {
            ErrorHandler::registerErrorInLog(
                "Upload error for image {$rental_id} in webhook: " . $upload['error'],
                __FILE__, __LINE__,
                RentalException::TYPE_SYNC_RUNTIME
            );
        }
        return 0;
    }

    if ( ! isset( $upload['file'] ) ) {
        return 0;
    }

    // Create attachment
    $wp_upload_dir = wp_upload_dir();
    $mime = isset( $image_meta->mime ) ? $image_meta->mime : wp_check_filetype( $upload['file'] )['type'];

    $attachment = [
        'guid'           => $wp_upload_dir['baseurl'] . '/' . _wp_relative_upload_path( $upload['file'] ),
        'post_mime_type' => $mime,
        'post_title'     => isset( $image_meta->label ) ? $image_meta->label : '',
        'post_content'   => isset( $image_meta->description ) && $image_meta->description ? $image_meta->description : '',
        'post_status'    => 'inherit',
    ];

    $attach_id = wp_insert_attachment( $attachment, $upload['file'], $parent_id );

    if ( is_wp_error( $attach_id ) || ! $attach_id ) {
        if ( class_exists( 'ErrorHandler', false ) && class_exists( 'RentalException', false ) ) {
            ErrorHandler::registerErrorInLog(
                "wp_insert_attachment failed for image {$rental_id} in webhook: " . ( is_wp_error( $attach_id ) ? $attach_id->get_error_message() : 'unknown' ),
                __FILE__, __LINE__,
                RentalException::TYPE_SYNC_RUNTIME
            );
        }
        return 0;
    }

    // Generate sub-sizes with performance filters if available
    if ( class_exists( 'Rental_Image_Performance_Filter', false ) ) {
        Rental_Image_Performance_Filter::enable( $upload['file'] );
    }

    if ( function_exists( 'rental_make_image_subsizes' ) ) {
        rental_make_image_subsizes( $upload['file'], $attach_id );
    } else {
        $attach_data = wp_generate_attachment_metadata( $attach_id, $upload['file'] );
        wp_update_attachment_metadata( $attach_id, $attach_data );
    }

    if ( class_exists( 'Rental_Image_Performance_Filter', false ) ) {
        Rental_Image_Performance_Filter::disable();
    }

    return (int) $attach_id;
}


// Product
function rentopian_product_create($product) {
    global $wpdb, $rental_tables;
    $rental_product_relations = $wpdb->prefix . $rental_tables["product_relations"];
    $rental_variant_relations = $wpdb->prefix . $rental_tables["variant_relations"];
    $rental_image_relations = $wpdb->prefix . $rental_tables["image_relations"];
    $rental_attribute_relations = $wpdb->prefix . $rental_tables["attribute_relations"];

    $product = json_decode(stripslashes($product));
    if ( !$product) {
        http_response_code(400);
        echo json_encode(["error" => "Product is empty."]);
        return;
    }

    // set max_execution_time
    set_time_limit(200);

    // Set-member reference bookkeeping. `before` holds the WP variation ids
    // sets reference today; `after` is filled as replacement posts are
    // created. Both stay empty for a brand-new product, where no set can be
    // referencing anything yet.
    $rental_set_variant_before = [];
    $rental_set_variant_after  = [];

    $product_id = $wpdb->get_var("SELECT `id` FROM $rental_product_relations WHERE `rental_id` = $product->id AND `rental_division_id` = $product->division_id");
    if ($product_id) {
        // update product

        $full_description = isset($product->full_description) ? $product->full_description : '';
        if (wp_update_post([
            'ID' => $product_id,
            'post_title' => $product->name,
            'post_content' => $full_description,
            'post_excerpt' => $product->description,
            'post_status' => 'publish',
        ])) {
            $wpdb->query("DELETE FROM $wpdb->term_relationships WHERE `object_id` = $product_id");
            $wpdb->query("DELETE FROM $wpdb->postmeta WHERE `post_id` = $product_id");
            // delete product cache
            wc_delete_product_transients($product_id);

            $old_variants = "";
            foreach ($product->variants as $variant) {
                $old_variants .= ($old_variants? ", ": "") . $variant->id;
            }

            // The variation posts below are about to be replaced. Record the
            // ids sets currently reference, keyed by the Rentopian variant id
            // that survives the swap, so those references can be repointed
            // once the new posts exist.
            if (class_exists('Rental_Sets_Variant_Remapper', false)) {
                $rental_set_variant_before = Rental_Sets_Variant_Remapper::snapshot($product->variants, $product->division_id);
            }

            if ($old_variants) {
                $wpdb->query("DELETE FROM $rental_variant_relations WHERE `rental_id` IN ($old_variants) AND `rental_division_id` = $product->division_id");
            }
            $wpdb->query("UPDATE `$wpdb->posts` SET `post_status` = 'trash' WHERE `post_parent` =  $product_id AND `post_type` = 'product_variation' AND `post_status` = 'publish'");

            if (isset($wpdb->wc_product_meta_lookup)) {
                $wpdb->query("DELETE FROM $wpdb->wc_product_meta_lookup WHERE `product_id` = $product_id");
            }
        } else {
            $wpdb->query("DELETE FROM $rental_product_relations WHERE `rental_id` = $product->id AND `rental_division_id` = $product->division_id");
            $product_id = 0;
        }
        $product_relations_sql = "";
    }
    if ( !$product_id) {
        // insert product
        $product_id = wp_insert_post([
            'post_author' => '1',
            'post_title' => $product->name,
            'post_content' => $product->full_description,
            'post_excerpt' => $product->description,
            'post_status' => 'publish',
            'comment_status' => 'open',
            'post_type' => 'product',
        ]);
        $product_relations_sql = "($product_id, $product->id, $product->division_id)";
    }

    $product_attributes_count = 0;

    $img_rel = [];
    $product_image_gallery = [];
	$variant_image_gallery = [];
    $thumbnail_id = "";
    $image_relations_sql = [];

    // Resolve the canonical product thumbnail rental image id with proper precedence:
    //   1. $product->img_id is authoritative when set/non-zero.
    //   2. $product->variant_img_id is ONLY a fallback when img_id is empty
    $product_main_img_id   = isset($product->img_id) ? (int) $product->img_id : 0;
    $product_variant_img_id = isset($product->variant_img_id) ? (int) $product->variant_img_id : 0;
    $thumb_rental_id       = $product_main_img_id ?: $product_variant_img_id;

    if ( !empty($product->images)) {
        $get_images = [];
        foreach ($product->images as $img) {
        	if ( !isset($variant_image_gallery[$img->variant_id])) {
		        $variant_image_gallery[$img->variant_id] = [];
	        }
	        $variant_image_gallery[$img->variant_id][] = $img->id;
	        if (isset($img_rel[$img->id])) {
	        	continue;
	        }

            $img_id = $wpdb->get_var("SELECT `id` FROM $rental_image_relations WHERE `rental_id` = $img->id");
            if ($img_id) {
                if ($thumb_rental_id && (int) $img->id === $thumb_rental_id) {
                    $thumbnail_id = $img_id;
                } else {
                    $product_image_gallery[] = $img_id;
                }
                $img_rel[$img->id] = $img_id;
            } else {
	            $img_rel[$img->id] = null;
                $get_images[] = $img->id;
            }
        }

        if ( !empty($get_images)) {
            $api_key = get_option('rental_api_key');

            // $new_images = rental_curl('files/images', $api_key, true, ['images' => json_encode($get_images)]);

            try {
                $new_images = rental_curl('files/images/stream', $api_key, true, ['images' => json_encode($get_images)]);
            } catch (RentalException $e) {

                ErrorHandler::registerErrorInLog(
                    "API fetch failed in product create webhook: " . $e->getMessage(),
                    __FILE__, __LINE__,
                    $e->getType(),   // preserve type
                    null,
                    $e->getStatusCode()
                );
                throw $e;
            }

            // upload product images via centralized helper
            foreach ($new_images as $image) {

                if ( !$image->url) {
                    continue;
                }

                $attach_id = rentopian_webhook_download_image(
                    $image->url,
                    $image->id,
                    $image,
                    $product_id
                );

                if ( !$attach_id) {
                    continue;
                }

                $image_relations_sql[] = "($attach_id, $image->id)";

                if ( !$thumbnail_id && $thumb_rental_id && (int) $image->id === $thumb_rental_id ) {
                    $thumbnail_id = $attach_id;
                } else {
                    $product_image_gallery[] = $attach_id;
                }
                $img_rel[$image->id] = $attach_id;
            }
        }
    }

    $product_image_gallery = empty($product_image_gallery)? "": implode(",", $product_image_gallery);

    $variantmeta_sql = [];
    $term_relation_sql = [];
    $variant_relations_sql = [];
    $attribute_relations_sql = [];

    $simple = $wpdb->get_var("SELECT `term_id` FROM $wpdb->terms WHERE `name` = 'simple' AND `slug` = 'simple'");
    $variable = $wpdb->get_var("SELECT `term_id` FROM $wpdb->terms WHERE `name` = 'variable' AND `slug` = 'variable'");
    $outofstock = $wpdb->get_var("SELECT `term_id` FROM $wpdb->terms WHERE `name` = 'outofstock' AND `slug` = 'outofstock'");

    $stock_status_despite_quantity = !get_option('rental_inactive_inv_by_quantity');
    $stock_status = $product->variants[0]->inventory && ($stock_status_despite_quantity || $product->variants[0]->quantity)? 'instock': 'outofstock';

    $tax_status = $product->variants[0]->taxable? 'taxable': 'none';

    $job_cost = $product->variants[0]->job_cost? $product->variants[0]->job_cost: 0;

    $price = $product->variants[0]->rental_price;
    $sale_price = '';
    if ($product->variants[0]->sale_price > 0) {
        $price = $sale_price = $product->variants[0]->sale_price;
    }
    $is_add_on = $product->is_add_on || $product->hidden_from_api? 1: 0;

    $price_multiplier_id = empty($product->variants[0]->price_multiplier_id) ? 0 : $product->variants[0]->price_multiplier_id;

    // rental by interval/rental by time slot data
    $rental_interval = empty($product->variants[0]->rental_interval) ? 0 : $product->variants[0]->rental_interval;
    $interval_steps = empty($product->variants[0]->interval_steps) ? 0 : $product->variants[0]->interval_steps;
    $rental_time_slots = empty($product->variants[0]->rental_time_slots) ? "" : $product->variants[0]->rental_time_slots;

    $divisions = isset($product->divisions) ? json_decode($product->divisions, 1) : [];
    $divisions_synced = get_option('rental_divisions');
    $possible_duplicate = false;
    if ($divisions) {
        foreach($divisions_synced as $div_sync) {
            if ($div_sync->main_division == 1) {
                
                if (count($divisions) > 1) {
                    foreach($divisions as $prod_div) {
                    
                        if ($prod_div == $div_sync->id) {
                            $possible_duplicate = true;
                        }
                    }
                }
            }
        }
    }

    $has_duplicates = $divisions && count($divisions) > 1 && $possible_duplicate ? 1 : 0;

    $duplicate_product_ids = [];
    if ($has_duplicates && $product->division_id != get_option('rental_location_based_duplicate_filter_division_id', 0)) {
        $duplicate_product_ids[] = $product_id;

        // rental_set_product_catalog_visibility($product_id, 'hidden');
        update_post_meta($product_id, '_rental_is_add_on', 1);
    }

    
    $product_weight = $product_length = $product_width = $product_height = $product_depth = $sku = '';
    if (count($product->variants) < 2) {
        $product_weight = $product->variants[0]->weight;
        $product_length = $product->variants[0]->length;
        $product_width = $product->variants[0]->width;
        $product_height = $product->variants[0]->height;
        $product_depth = isset($product->variants[0]->depth) ? $product->variants[0]->depth : '';

        $sku = isset($product->variants[0]->sku) ? addslashes($product->variants[0]->sku) : '';
    }

    // add product data for insert in postmeta table
    $productmeta_sql = "($product_id, '_wc_review_count', '0' ),
        ($product_id, '_wc_rating_count', 'a:0:{}'),
        ($product_id, '_wc_average_rating', '0'),
        ($product_id, '_edit_last', ''),
        ($product_id, '_edit_lock', ''),
        ($product_id, '_sku', '$sku' ),
        ($product_id, '_regular_price', '" . $product->variants[0]->rental_price . "'),
        ($product_id, '_job_cost', '$job_cost'),
        ($product_id, '_price_multiplier_id', '$price_multiplier_id'),
        ($product_id, '_sale_price', '$sale_price'),
        ($product_id, '_sale_price_dates_from', ''),
        ($product_id, '_sale_price_dates_to', ''),
        ($product_id, 'total_sales', '0'),
        ($product_id, '_tax_status', '$tax_status'),
        ($product_id, '_tax_class', '' ),
        ($product_id, '_manage_stock', 'no' ),
        ($product_id, '_backorders', 'yes' ),
        ($product_id, '_sold_individually', 'no' ),
        ($product_id, '_weight', '" . $product_weight . "'),
        ($product_id, '_length', '" . $product_length . "'),
        ($product_id, '_width', '" . $product_width . "'),
        ($product_id, '_height', '" . $product_height . "'),
        ($product_id, '_depth', '" . $product_depth . "'),
        ($product_id, '_upsell_ids', 'a:0:{}' ),
        ($product_id, '_crosssell_ids', 'a:0:{}' ),
        ($product_id, '_purchase_note', '' ),
        ($product_id, '_virtual', 'no' ),
        ($product_id, '_downloadable', 'no' ),
        ($product_id, '_download_limit', '-1' ),
        ($product_id, '_download_expiry', '-1' ),
        ($product_id, '_stock', '" . $product->variants[0]->quantity . "' ),
        ($product_id, '_stock_status', '$stock_status'),
        ($product_id, '_product_version', '3.2.3'),
        ($product_id, 'zoo_cw_product_swatch_data', 'a:0:{}'),
        ($product_id, '_thumbnail_id', '$thumbnail_id'),
        ($product_id, '_product_image_gallery', '$product_image_gallery'),
        ($product_id, '_price', '$price'),
        ($product_id, '_rental_inventory_id', '" . $product->variants[0]->inventory_id . "'),
        ($product_id, '_rental_exempt_waiver', '$product->exempt_waiver'),
        ($product_id, '_rental_is_sale', '$product->is_sale'),
        ($product_id, '_rental_by_interval', '$product->rental_by_interval'),
        ($product_id, '_rental_by_slot', '$product->rental_by_slot'),
        ($product_id, '_rental_interval', '$rental_interval'),
        ($product_id, '_rental_interval_steps', '$interval_steps'),
        ($product_id, '_rental_interval_price', '" . $product->variants[0]->rental_interval_price . "'),
        ($product_id, '_rental_interval_sale_price', '" . $product->variants[0]->rental_interval_sale_price . "'),
        ($product_id, '_rental_additional_hourly_price', '" . $product->variants[0]->additional_hourly_price . "'),
        ($product_id, '_rental_time_slots', '$rental_time_slots'),
        ($product_id, '_rental_is_add_on', '$is_add_on')";
    //        ($product_id, '_manage_stock', 'yes' ),

        $productmeta_sql .= ",($product_id, '_rental_is_duplicate', '$has_duplicates')";

    if ($product->add_ons) {
        $product_add_ons = [];
        foreach ($product->add_ons as $add_on) {
            if ( !($add_on_id = $wpdb->get_var("SELECT `id` FROM $rental_product_relations WHERE `rental_id` = $add_on->product_id AND `rental_division_id` = $product->division_id"))) {
                continue;
            }
            if ( !$add_on->variant_id || !($add_on_variant_id = $wpdb->get_var("SELECT `id` FROM $rental_variant_relations WHERE `rental_id` = $add_on->variant_id AND `rental_division_id` = $product->division_id"))) {
                $add_on_variant_id = 0;
            }
            $product_add_ons[$add_on_variant_id?: $add_on_id] = [
                'product_id' => $add_on_id,
                'variant_id' => $add_on_variant_id,
                'quantity' => $add_on->quantity,
                'price' => $add_on->price,
                'required' => $add_on->required,
                'hidden' => isset($add_on->hidden) ? $add_on->hidden : 0,
                'product_price' => isset($add_on->product_price) ? $add_on->product_price : 0,
                'inherit_price' => isset($add_on->inherit_price) ? $add_on->inherit_price : 0,
            ];
        }
        if ( !empty($product_add_ons)) {
            $productmeta_sql .= ",($product_id, '_rental_add_ons', '" . addslashes(serialize($product_add_ons)) . "')";
        }
    }

    if (isset($wpdb->wc_product_meta_lookup)) {
        $product_meta_lookup_sql = "";
    }
    $min_price = $max_price = $price;
    
    $variants = [];
    if ( !empty($product->product_attributes)) {
        $product_attributes_count = count($product->product_attributes);
        $i = 0;
        $product_attributes = [];
        $attribute_slugs = [];
        $attribute_relations_sql = [];
        $wc_attribute_taxonomies = get_option('_transient_wc_attribute_taxonomies');
        if ( !is_array($wc_attribute_taxonomies)) {
            $wc_attribute_taxonomies = [];
        }
        foreach ($product->product_attributes as $attribute) {
            $attribute_slugs[$attribute->id] = $attribute->slug;
            $product_attributes["pa_$attribute->slug"] = [
                "name" => "pa_$attribute->slug",
                "value" => "",
                "position" => $i,
                "is_visible" => 1,
                "is_variation" => 1,
                "is_taxonomy" => 1
            ];
            $i++;
            $attribute_id = $wpdb->get_var("SELECT `id` FROM $rental_attribute_relations WHERE `rental_id` = $attribute->id");
            // insert new attributes
            if ( !$attribute_id) {
                $attr = [
                    "attribute_name" => $attribute->slug,
                    "attribute_label" => $attribute->title,
                    "attribute_type" => "select",
                    "attribute_orderby" => "menu_order",
                    "attribute_public" => 0
                ];
                $wpdb->insert($wpdb->prefix . "woocommerce_attribute_taxonomies", $attr);
                $attribute_id = $wpdb->insert_id;
                $attr["attribute_id"] = $attribute_id;

                $attribute_relations_sql[] = "($attribute_id, $attribute->id)";

                // add attributes date for _transient_wc_attribute_taxonomies option
                $wc_attribute_taxonomies[] = (object) $attr;
            }
        }
        if ( !empty($attribute_relations_sql)) {
            $wpdb->query("INSERT INTO `$rental_attribute_relations` (`id`, `rental_id`) VALUES " . implode(", ", $attribute_relations_sql));
        }
        update_option('_transient_wc_attribute_taxonomies', $wc_attribute_taxonomies);
        unset($wc_attribute_taxonomies);
        $product_attributes = serialize($product_attributes);

        $productmeta_sql .= ",($product_id, '_product_attributes', '$product_attributes')";

        $term_relation_sql[] = "($product_id, $variable, 0)";

        $attached_attr_val = [];
        $variant_attr_val = [];
        foreach ($product->product_attribute_values as $attribute_value) {
            if ( !isset($attribute_slugs[$attribute_value->attribute_id])) {
                continue;
            }
            $attribute_slug = $attribute_slugs[$attribute_value->attribute_id];
            if (isset($variant_attr_val[$attribute_value->variant_id])) {
                $variant_attr_val[$attribute_value->variant_id]["pa_$attribute_slug"] = $attribute_value->slug;
            } else {
                $variant_attr_val[$attribute_value->variant_id] = ["pa_$attribute_slug" => $attribute_value->slug];
            }

            if (isset($attached_attr_val[$attribute_value->id])) {
                continue;
            }
            // get attribute value id
            $term_taxonomy_id = $wpdb->get_var("SELECT `$wpdb->term_taxonomy`.`term_taxonomy_id` FROM `$wpdb->term_taxonomy`
                LEFT JOIN `$wpdb->terms` ON `$wpdb->term_taxonomy`.`term_id` = `$wpdb->terms`.`term_id`
                WHERE `$wpdb->term_taxonomy`.`taxonomy` = 'pa_$attribute_slug' AND `$wpdb->terms`.`slug` = '$attribute_value->slug'");
            if ( !$term_taxonomy_id) {
                // insert attribute value
                register_taxonomy("pa_$attribute_slug", 'product');
                $term = wp_insert_term($attribute_value->title, "pa_$attribute_slug", ["slug" => $attribute_value->slug]);
                $term_taxonomy_id = $term["term_taxonomy_id"];
            }
            $term_relation_sql[] = "($product_id, $term_taxonomy_id, 0)";
            $attached_attr_val[$attribute_value->id] = true;
        }

        foreach ($product->variants as $variant) {
            if ( !isset($variant_attr_val[$variant->id])) {
                continue;
            }
            // insert product variant
            $variant_id = wp_insert_post([
                'post_author' => '1',
                'post_title' => $variant->name,
                'post_status' => 'publish',
                'comment_status' => 'closed',
                'post_parent' => $product_id,
                'post_type' => 'product_variation',
            ]);

            if ($variant->inventory && ($stock_status_despite_quantity || $variant->quantity)) {
                $stock_status = 'instock';
            } else {
                $term_relation_sql[] = "($variant_id, $outofstock, 0)";
                $stock_status = 'outofstock';
            }

            $tax_status = $variant->taxable? 'taxable': 'none';

            $job_cost = $variant->job_cost? $variant->job_cost: 0;

            $variant_relations_sql[] = "($variant_id, $variant->id, $product->division_id)";
            $rental_set_variant_after[(int) $variant->id] = (int) $variant_id;

            $sku = isset($variant->sku) ? addslashes($variant->sku) : '';

            $price = $variant->rental_price;
            $variant_sale_price = '';
            if ($variant->sale_price > 0) {
                $price = $variant_sale_price = $variant->sale_price;
            }

            $thumbnail_id = '';
            if ($variant->img_id && isset($img_rel[$variant->img_id])) {
                $thumbnail_id = $img_rel[$variant->img_id];
            }

	        $variation_gallery = "";
            if (isset($variant_image_gallery[$variant->id])) {
	            foreach ($variant_image_gallery[$variant->id] as $img_id) {
			        if ($img_id != $variant->img_id && isset($img_rel[$img_id])) {
				        $variation_gallery .= $variation_gallery? "," . $img_rel[$img_id]: $img_rel[$img_id];
			        }
            	}
            }

            $variantmeta_sql[$variant_id] = "";
            foreach ($variant_attr_val[$variant->id] as $attr_title => $attr_value) {
                $variantmeta_sql[$variant_id] .= "($variant_id, 'attribute_$attr_title', '$attr_value'),";
            }
            if (isset($product_attributes_count) && $product_attributes_count && $product_attributes_count < 2) {
                if ($variant->default) {
                    $productmeta_sql .= ",($product_id, '_default_attributes', '" . addslashes(serialize($variant_attr_val[$variant->id])) . "')";
                }
            }
            

            $price_multiplier_id = empty($variant->price_multiplier_id) ? 0 : $variant->price_multiplier_id;

            // rental by interval/rental by time slot data
            $rental_interval = empty($variant->rental_interval) ? 0 : $variant->rental_interval;
            $interval_steps = empty($variant->interval_steps) ? 0 : $variant->interval_steps;
            $rental_time_slots = empty($variant->rental_time_slots) ? "" : $variant->rental_time_slots;


            // add variant data for insert in postmeta table
            $variantmeta_sql[$variant_id] .= "($variant_id, '_variation_description', '" . addslashes($variant->description) . "'),
                ($variant_id, '_wc_rating_count', 'a:0:{}'),
                ($variant_id, '_wc_review_count', '0'),
                ($variant_id, '_wc_average_rating', '0'),
                ($variant_id, '_sku', '$sku'),
                ($variant_id, '_regular_price', '$variant->rental_price'),
                ($variant_id, '_job_cost', '$job_cost'),
                ($variant_id, '_price_multiplier_id', '$price_multiplier_id'),
                ($variant_id, '_sale_price', '$variant_sale_price'),
                ($variant_id, '_sale_price_dates_from', ''),
                ($variant_id, '_sale_price_dates_to', ''),
                ($variant_id, 'total_sales', '0'),
                ($variant_id, '_tax_status', '$tax_status'),
                ($variant_id, '_tax_class', '' ),
                ($variant_id, '_manage_stock', 'no' ),
                ($variant_id, '_backorders', 'yes' ),
                ($variant_id, '_sold_individually', 'no' ),
                ($variant_id, '_weight', '$variant->weight'),
                ($variant_id, '_length', '$variant->length'),
                ($variant_id, '_width', '$variant->width'),
                ($variant_id, '_height', '$variant->height'),
                ($variant_id, '_depth', '$variant->depth'),
                ($variant_id, '_upsell_ids', 'a:0:{}' ),
                ($variant_id, '_crosssell_ids', 'a:0:{}' ),
                ($variant_id, '_purchase_note', '' ),
                ($variant_id, '_virtual', 'no' ),
                ($variant_id, '_downloadable', 'no' ),
                ($variant_id, '_product_image_gallery', '' ),
                ($variant_id, '_download_limit', '-1' ),
                ($variant_id, '_download_expiry', '-1' ),
                ($variant_id, '_stock', '$variant->quantity' ),
                ($variant_id, '_stock_status', '$stock_status'),
                ($variant_id, '_product_version', '3.2.3'),
                ($variant_id, '_price', '$price'),
                ($variant_id, '_rental_inventory_id', '$variant->inventory_id'),
                ($variant_id, 'zoo-cw-variation-gallery', '$variation_gallery'),
                ($variant_id, '_thumbnail_id', '$thumbnail_id'),
                ($variant_id, '_rental_by_interval', '$variant->rental_by_interval'),
                ($variant_id, '_rental_by_slot', '$variant->rental_by_slot'),
                ($variant_id, '_rental_interval', '$rental_interval'),
                ($variant_id, '_rental_interval_steps', '$interval_steps'),
                ($variant_id, '_rental_interval_price', '$variant->rental_interval_price'),
                ($variant_id, '_rental_interval_sale_price', '$variant->rental_interval_sale_price'),
                ($variant_id, '_rental_additional_hourly_price', '$variant->additional_hourly_price'),
                ($variant_id, '_rental_time_slots', '$rental_time_slots'),
                ($variant_id, '_downloadable_files', 'a:0:{}')";

                // $variantmeta_sql[$variant_id] .= ",($variant_id, '_rental_is_duplicate', '$variant_has_duplicates')";

            // ($variant_id, '_manage_stock', 'yes' ),
            if (isset($product_meta_lookup_sql)) {
                $on_sale = $variant_sale_price? 1: 0;
                $product_meta_lookup_sql .= "($variant_id, '$sku', 0, 0, $price, $price, $on_sale, $variant->quantity, '$stock_status', '$tax_status'),";
                if ($price < $min_price) {
                    $min_price = $price;
                } elseif ($price > $max_price) {
                    $max_price = $price;
                }
            }
            $variants[] = [
                "id" => $variant_id,
                "stock_status" => $stock_status,
                "img" => $thumbnail_id,
            ];
        }
        $sale_price = '';
    } else {
        $term_relation_sql[] = "($product_id, $simple, 0)";
    }

    if ($product->is_featured) {
        $featured = $wpdb->get_var("SELECT `term_id` FROM $wpdb->terms WHERE `name` = 'featured' AND `slug` = 'featured'");
        $term_relation_sql[] = "($product_id, $featured, 0)";
    }

    if ($product->brand_id) {
        $rental_brand_relations = $wpdb->prefix . $rental_tables["brand_relations"];
        $brand_id = $wpdb->get_var("SELECT `id` FROM $rental_brand_relations WHERE `rental_id` = $product->brand_id");
        if ($brand_id) {
            $term_relation_sql[] = "($product_id, $brand_id, 0)";
        }
    }

    if (isset($product_meta_lookup_sql)) {
        $on_sale = $sale_price? 1: 0;
        $product_meta_lookup_sql .= "($product_id, '', 0, 0, $min_price, $max_price, $on_sale, " . $product->variants[0]->quantity . ", '$stock_status', '$tax_status')";
    }


    $cats = [];
    if ($product->categories) {
        $cats = rental_create_categories($product->categories, $product_id);
    }
    $tags = [];
    if ($product->tags) {
        $product_set_tags = isset($product->sets_tags) ? $product->sets_tags : [];
        $tags = rental_create_tags($product->tags, $product_id, $product_set_tags);
    }


    if (empty($variantmeta_sql)) {
        $postmeta_sql = $productmeta_sql;
    } else {
        $variantmeta_sql = implode(", ", $variantmeta_sql);
        $postmeta_sql = $productmeta_sql . ", " . $variantmeta_sql;
    }

    $wpdb->query("INSERT INTO `$wpdb->postmeta` (`post_id`, `meta_key`, `meta_value`)
    VALUES $postmeta_sql");

    if (isset($product_meta_lookup_sql)) {
        $wpdb->query("INSERT INTO `$wpdb->wc_product_meta_lookup` (`product_id`, `sku`, `virtual`, `downloadable`, " .
            "`min_price`, `max_price`, `onsale`, `stock_quantity`, `stock_status`, `tax_status`) VALUES $product_meta_lookup_sql");
    }

    $sql = "";
    if ($product_relations_sql) {
        $sql .= "INSERT INTO `$rental_product_relations` (`id`, `rental_id`, `rental_division_id`) VALUES $product_relations_sql;";
    }
    if ( !empty($term_relation_sql)) {
        $term_relation_sql = implode(", ", $term_relation_sql);
        $sql .= "INSERT INTO `$wpdb->term_relationships` (`object_id`, `term_taxonomy_id`, `term_order`)
              VALUES $term_relation_sql;";
    }
    if ( !empty($variant_relations_sql)) {
        $variant_relations_sql = implode(", ", $variant_relations_sql);
        $sql .= "INSERT INTO `$rental_variant_relations` (`id`, `rental_id`, `rental_division_id`) VALUES $variant_relations_sql;";
    }
    if ( !empty($image_relations_sql)) {
        $image_relations_sql = implode(", ", $image_relations_sql);
        $sql .= "INSERT INTO `$rental_image_relations` (`id`, `rental_id`) VALUES $image_relations_sql;";
    }


    if ($sql) {
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    // Repoint sets at the variation posts that replaced the ones removed
    // above. Runs after the relation rows are committed so a set repaired
    // here matches what the rest of the plugin will resolve.
    if ($rental_set_variant_before && class_exists('Rental_Sets_Variant_Remapper', false)) {
        Rental_Sets_Variant_Remapper::apply(
            $rental_set_variant_before,
            $rental_set_variant_after,
            Rental_Sets_Variant_Remapper::inventory_map($product->variants),
            (int) $product_id,
            (int) $product->division_id
        );
    }

    if (isset($product->all_variants)) {
        if ($variants = $product->all_variants) {
        
            foreach($variants as $variant) {
    
                $variant_has_duplicates = 0;
                $variant_stock = $variant->quantity;
    
                if (isset($variant->divisions)) {
                    $variant_divisions = json_decode($variant->divisions);
                    $variant_has_duplicates = $variant_divisions && count($variant_divisions) > 1 ? 1 : 0;
    
                    if (
                        $variant_has_duplicates 
                        && get_option('rental_location_based_duplicate_filter', 0) == 1 
                        && isset($variant->product_summed_qty)
                    ) {
                        $variant_stock = $variant->product_summed_qty;
                    }


                    $vid = $wpdb->get_var("SELECT `id` FROM $rental_variant_relations WHERE `rental_id` = $variant->id AND `rental_division_id` = $variant->division_id");
                    $pid = $wpdb->get_var("SELECT `id` FROM $rental_product_relations WHERE `rental_id` = $variant->product_id AND `rental_division_id` = $variant->division_id");


                    if ($variant_has_duplicates && $variant->division_id != get_option('rental_location_based_duplicate_filter_division_id', 0)) {
                        
                        if ($vid) {
                            $duplicate_product_ids[] = $vid;

                            // rental_set_product_catalog_visibility($vid, 'hidden');
                            update_post_meta($vid, '_rental_is_add_on', 1);
                        }
                    }
                    
        
                    if ($vid) {
                        update_post_meta($vid, '_rental_is_duplicate', $variant_has_duplicates);
        
                        update_post_meta($vid, '_stock', $variant_stock);
            
                        $wpdb->update( $wpdb->wc_product_meta_lookup, [ 
                            'stock_quantity' => $variant_stock
                        ], ['product_id' => $vid] );
                    }
        

                    if ($pid) {
                        update_post_meta($pid, '_stock', $variant_stock);
        
                        $wpdb->update( $wpdb->wc_product_meta_lookup, [ 
                            'stock_quantity' => $variant_stock
                        ], ['product_id' => $pid] );
                    }
                }
                
            }
        }
    }
    

    $product_up_sells = isset($product->up_sells) && $product->up_sells ? $product->up_sells : [];
    $product_cross_sells = isset($product->cross_sells) && $product->cross_sells ? $product->cross_sells : [];

    // sync product up sells and cross sells
    set_products_up_sells_cross_sells($product_id, $product_up_sells, $product_cross_sells);
    // rental_set_product_up_sells_cross_sells_for_api($product_up_sells, $product_cross_sells);

    $do_not_index_hidden_duplicate_products_for_seo = get_option('rental_do_not_index_hidden_duplicate_products_for_seo', 0);

    if ($product->hidden_from_api) {
        rental_set_product_catalog_visibility($product_id, 'hidden');

        if ($do_not_index_hidden_duplicate_products_for_seo) {
            // rental_set_product_general_visibility($product_id, 'private');

            // rental_set_product_catalog_visibility($product_id, 'hidden');
            update_post_meta($product_id, '_rental_is_add_on', 1);

            toggle_index_yoast_seo($product_id);
        }
    }


    if ($do_not_index_hidden_duplicate_products_for_seo && get_option('rental_location_based_duplicate_filter', 0)) {

        foreach($duplicate_product_ids as $duplicate_product_id) {
            // rental_set_product_general_visibility($duplicate_product_id, 'private');

            // rental_set_product_catalog_visibility($duplicate_product_id, 'hidden');
            // update_post_meta($duplicate_product_id, '_rental_is_add_on', 1);

            toggle_index_yoast_seo($duplicate_product_id);
        }
    }

    if ( class_exists( 'WooCommerce_Single_Variations' ) && defined('WC_SINGLE_VAR_VERSION')) {
        $admin_wcs_variation = new WooCommerce_Single_Variations_Admin('plugin', WC_SINGLE_VAR_VERSION);
        $admin_wcs_variation->init();
        // $admin_wcs_variation->update_variations(false);
        $admin_wcs_variation->update_variations(false, true);
        $admin_wcs_variation->reset_transients(false);
    }

    if ($wpdb->last_error !== '') {
        $error = "SQL_ERROR: $wpdb->last_error (SQL: $wpdb->last_query)";
        http_response_code(400);
        echo json_encode(["error" => $error]);
        throw new RentalException($error);
    }

    rental_clear_cache();

    // Polylang: assign default language to product + all created variants
    $created_variant_ids = array_column( $variants, 'id' );
    rental_pll_assign_product( (int) $product_id, array_map( 'intval', $created_variant_ids ) );
    rental_translation_queue_product( (int) $product_id, array_map( 'intval', $created_variant_ids ) );

    http_response_code(200);
    echo json_encode([
        "product" => [
            "rental_id" => $product->id,
            "id" => $product_id,
            "image" => $thumbnail_id,
            "stock_status" => $stock_status,
            "variants" => $variants,
            "categories" => $cats,
            "tags" => $tags,
        ],
        "message" => "Product successfully created!"
    ]);
    return;
}

function rentopian_product_update($product) {
    global $wpdb, $rental_tables;
    $rental_product_relations = $wpdb->prefix . $rental_tables["product_relations"];
    $rental_variant_relations = $wpdb->prefix . $rental_tables["variant_relations"];

    $product = json_decode(stripslashes($product));
    if ( !$product) {
        http_response_code(400);
        echo json_encode(["error" => "Product is empty."]);
        return;
    }

    // set max_execution_time
    set_time_limit(200);

    $product_id = $wpdb->get_var("SELECT `id` FROM $rental_product_relations WHERE `rental_id` = $product->id AND `rental_division_id` = $product->division_id");
    if ( !$product_id) {
        http_response_code(404);
        echo json_encode(["error" => "Product with rental_id $product->id not found."]);
        return;
    }

    if ( !wp_update_post([
        'ID' => $product_id,
        'post_title' => $product->name,
        'post_content' => $product->full_description,
        'post_excerpt' => $product->description,
    ])) {
        http_response_code(404);
        echo json_encode(["error" => "Post with ID $product_id not found."]);
        return;
    }

    update_post_meta($product_id, '_rental_exempt_waiver', $product->exempt_waiver);

    $product_add_ons = [];
    if ($product->add_ons) {
        foreach ($product->add_ons as $add_on) {
            if ( !($add_on_id = $wpdb->get_var("SELECT `id` FROM $rental_product_relations WHERE `rental_id` = $add_on->product_id AND `rental_division_id` = $product->division_id"))) {
                continue;
            }
            if ( !$add_on->variant_id || !($add_on_variant_id = $wpdb->get_var("SELECT `id` FROM $rental_variant_relations WHERE `rental_id` = $add_on->variant_id AND `rental_division_id` = $product->division_id"))) {
                $add_on_variant_id = 0;
            }
            $product_add_ons[$add_on_variant_id?: $add_on_id] = [
                'product_id' => $add_on_id,
                'variant_id' => $add_on_variant_id,
                'quantity' => $add_on->quantity,
                'price' => $add_on->price,
                'required' => $add_on->required,
                'hidden' => isset($add_on->hidden) ? $add_on->hidden : 0,
                'product_price' => isset($add_on->product_price) ? $add_on->product_price : 0,
                'inherit_price' => isset($add_on->inherit_price) ? $add_on->inherit_price : 0,
            ];
        }
    }
    update_post_meta($product_id, '_rental_add_ons', $product_add_ons);
    update_post_meta($product_id, '_rental_is_add_on', $product->is_add_on || $product->hidden_from_api? 1: 0);


    $divisions = isset($product->divisions) ? json_decode($product->divisions, 1) : [];
    $divisions_synced = get_option('rental_divisions');
    $possible_duplicate = false;
    if ($divisions) {
        foreach($divisions_synced as $div_sync) {
            if ($div_sync->main_division == 1) {
                
                if (count($divisions) > 1) {
                    foreach($divisions as $prod_div) {
                    
                        if ($prod_div == $div_sync->id) {
                            $possible_duplicate = true;
                        }
                    }
                }
            }
        }
    }

    $has_duplicates = $divisions && count($divisions) > 1 && $possible_duplicate ? 1 : 0;

    update_post_meta($product_id, '_rental_is_duplicate', $has_duplicates);

    $duplicate_product_ids = [];
    if ($has_duplicates && $product->division_id != get_option('rental_location_based_duplicate_filter_division_id', 0)) {
        $duplicate_product_ids[] = $product_id;

        // rental_set_product_catalog_visibility($product_id, 'hidden');
        update_post_meta($product_id, '_rental_is_add_on', 1);
    }

    $product_stock_collection = [];
    if ($variants = $product->variants) {
        
        foreach($variants as $variant) {

            $variant_has_duplicates = 0;
            $variant_stock = $variant->quantity;

            if (isset($variant->divisions)) {
                $variant_divisions = json_decode($variant->divisions);
                $variant_has_duplicates = $variant_divisions && count($variant_divisions) > 1 ? 1 : 0;

                if (
                    $variant_has_duplicates 
                    && get_option('rental_location_based_duplicate_filter', 0) == 1 
                    && isset($variant->product_summed_qty)
                ) {
                    $variant_stock = $variant->product_summed_qty;
                }

                $vid = $wpdb->get_var("SELECT `id` FROM $rental_variant_relations WHERE `rental_id` = $variant->id AND `rental_division_id` = $variant->division_id");
                $pid = $wpdb->get_var("SELECT `id` FROM $rental_product_relations WHERE `rental_id` = $variant->product_id AND `rental_division_id` = $variant->division_id");

                if ($variant_has_duplicates && $variant->division_id != get_option('rental_location_based_duplicate_filter_division_id', 0)) {
                    
                    if ($vid) {
                        $duplicate_product_ids[] = $vid;

                        // rental_set_product_catalog_visibility($vid, 'hidden');
                        update_post_meta($vid, '_rental_is_add_on', 1);
                    }
                }

                if ($vid) {
                    update_post_meta($vid, '_rental_is_duplicate', $variant_has_duplicates);
    
                    update_post_meta($vid, '_stock', $variant_stock);

                    $wpdb->update( $wpdb->wc_product_meta_lookup, [ 
                        'stock_quantity' => $variant_stock
                    ], ['product_id' => $vid] );

                    $product_stock_collection[$vid] = $variant_stock;
                }
    
                if ($pid) {

                    update_post_meta($pid, '_stock', $variant_stock);
    
                    $wpdb->update( $wpdb->wc_product_meta_lookup, [ 
                        'stock_quantity' => $variant_stock
                    ], ['product_id' => $pid] );

                    $product_stock_collection[$pid] = $variant_stock;
                }
            }
            
        }
    }

    wp_remove_object_terms($product_id, 'featured', 'product_visibility');
    if ($product->is_featured) {
        wp_set_post_terms($product_id, 'featured', 'product_visibility', true);
    }

    register_taxonomy("product_brand", "product");
    wp_delete_object_term_relationships($product_id, "product_brand");
    if ($product->brand_id) {
        $rental_brand_relations = $wpdb->prefix . $rental_tables["brand_relations"];
        $brand_id = (int) $wpdb->get_var("SELECT `id` FROM $rental_brand_relations WHERE `rental_id` = $product->brand_id");
        if ($brand_id) {
            wp_set_post_terms($product_id, [$brand_id], "product_brand", true);
        }
    }

    $cats = rental_create_categories($product->categories, $product_id);

    $product_set_tags = isset($product->sets_tags) ? $product->sets_tags : [];
    $tags = rental_create_tags($product->tags, $product_id, $product_set_tags);


    $product_up_sells = isset($product->up_sells) && $product->up_sells ? $product->up_sells : [];
    $product_cross_sells = isset($product->cross_sells) && $product->cross_sells ? $product->cross_sells : [];

    // sync product up sells and cross sells
    set_products_up_sells_cross_sells($product_id, $product_up_sells, $product_cross_sells);
    // rental_set_product_up_sells_cross_sells_for_api($product_up_sells, $product_cross_sells);

    $do_not_index_hidden_duplicate_products_for_seo = get_option('rental_do_not_index_hidden_duplicate_products_for_seo', 0);

    if ($product->hidden_from_api) {
        rental_set_product_catalog_visibility($product_id, 'hidden');

        if ($do_not_index_hidden_duplicate_products_for_seo) {
            // rental_set_product_general_visibility($product_id, 'private');

            // rental_set_product_catalog_visibility($product_id, 'hidden');
            update_post_meta($product_id, '_rental_is_add_on', 1);

            toggle_index_yoast_seo($product_id);
        }

    } else {
        rental_set_product_catalog_visibility($product_id, 'visible');

        // rental_set_product_general_visibility($product_id, 'publish');

        $product_is_duplicate_add_on = $has_duplicates && $product->division_id != get_option('rental_location_based_duplicate_filter_division_id', 0);
        $is_add_on_flag = ( !empty($product->is_add_on) || $product_is_duplicate_add_on) ? 1 : 0;
        update_post_meta($product_id, '_rental_is_add_on', $is_add_on_flag);

        toggle_index_yoast_seo($product_id, 0);

        if (isset($product_stock_collection[$product_id])) {
            update_post_meta($product_id, '_stock', $product_stock_collection[$product_id]);
    
            $wpdb->update( $wpdb->wc_product_meta_lookup, [ 
                'stock_quantity' => $product_stock_collection[$product_id]
            ], ['product_id' => $product_id] );
        }
    }

    if ($do_not_index_hidden_duplicate_products_for_seo && get_option('rental_location_based_duplicate_filter', 0) ) {

        foreach($duplicate_product_ids as $duplicate_product_id) {
            // rental_set_product_general_visibility($duplicate_product_id, 'private');

            // rental_set_product_catalog_visibility($duplicate_product_id, 'hidden');
            // update_post_meta($duplicate_product_id, '_rental_is_add_on', 1);

            toggle_index_yoast_seo($duplicate_product_id);
        }
    }


    if ( class_exists( 'WooCommerce_Single_Variations' ) && defined('WC_SINGLE_VAR_VERSION')) {
        $admin_wcs_variation = new WooCommerce_Single_Variations_Admin('plugin', WC_SINGLE_VAR_VERSION);
        $admin_wcs_variation->init();
        // $admin_wcs_variation->update_variations(false);
        $admin_wcs_variation->update_variations(false, true);
        $admin_wcs_variation->reset_transients(false);
    }

    rental_clear_cache();

    // Polylang: ensure product retains default language after update
    rental_pll_assign_post( (int) $product_id );
    rental_translation_queue_post( (int) $product_id );

    http_response_code(200);
    echo json_encode([
        "product" => [
            "rental_id" => $product->id,
            "id" => $product_id,
            "add_ons" => $product_add_ons,
            "categories" => $cats,
            "tags" => $tags,
        ],
        "message" => "Product successfully updated!"
    ]);
    return;
}

function rentopian_product_delete($product) {
    global $wpdb, $rental_tables;
    $rental_product_relations = $wpdb->prefix . $rental_tables["product_relations"];

    $product = json_decode(stripslashes($product));
    if ( !$product) {
        http_response_code(400);
        echo json_encode(["error" => "Product is empty."]);
        return;
    }

    $id = $wpdb->get_var("SELECT `id` FROM $rental_product_relations WHERE `rental_id` = $product->id AND `rental_division_id` = $product->division_id");
    if ( !$id) {
        http_response_code(404);
        echo json_encode(["error" => "Product with rental_id $product->id not found."]);
        return;
    }

    wp_update_post(['ID' => $id, 'post_status' => 'trash',]);
    $wpdb->query("UPDATE `$wpdb->posts` SET `post_status` = 'trash' WHERE `post_parent` =  $id AND `post_type` = 'product_variation' AND `post_status` = 'publish'");
    
    
    // if product options are activated then check to detach them from the product
    $rental_product_option_relations = $wpdb->prefix . $rental_tables["product_option_relations"];
    if ($wpdb->get_var("show tables like '$rental_product_option_relations'") == $rental_product_option_relations) {
        // detach product option relation
        $wpdb->delete($rental_product_option_relations, [
            "rental_id" => $product->id,
            "wp_id" => $id,
            "type" => 1,
        ]);
        // delete postmeta option ids
        delete_post_meta($id, '_product_options');
    }

    if ( class_exists( 'WooCommerce_Single_Variations' ) && defined('WC_SINGLE_VAR_VERSION')) {
        $admin_wcs_variation = new WooCommerce_Single_Variations_Admin('plugin', WC_SINGLE_VAR_VERSION);
        $admin_wcs_variation->init();
        // $admin_wcs_variation->update_variations(false);
        $admin_wcs_variation->update_variations(false, true);
        $admin_wcs_variation->reset_transients(false);
    }
    
    if ($wpdb->last_error !== '') {
        http_response_code(400);
        echo json_encode(["error" => "SQL_ERROR: $wpdb->last_error (SQL: $wpdb->last_query)"]);
        throw new RentalException("SQL_ERROR: $wpdb->last_error (SQL: $wpdb->last_query)");
    }

    rental_clear_cache();
    
    http_response_code(200);
    echo json_encode([
        "product" => [
            "rental_id" => $product->id,
            "id" => $id,
        ],
        "message" => "Product deleted successfully!"
    ]);
    return;
}

function rentopian_product_image($product) {
    global $wpdb, $rental_tables;
    $rental_product_relations = $wpdb->prefix . $rental_tables["product_relations"];

    $product = json_decode(stripslashes($product));
    if ( !$product) {
        http_response_code(400);
        echo json_encode(["error" => "Product is empty."]);
        return;
    }

    $id = $wpdb->get_var("SELECT `id` FROM $rental_product_relations WHERE `rental_id` = $product->id AND `rental_division_id` = $product->division_id");
    if ( !$id) {
        http_response_code(404);
        echo json_encode(["error" => "Product with rental_id $product->id not found."]);
        return;
    }

    $thumbnail_id = "";
    $gallery = "";
    if ($product->images) {
        require_once("includes/models/RTImage.php");
        $images = (new RTImage())->getImages($product->images);

        // Resolve the canonical product thumbnail rental image id with proper precedence:
        //   1. $product->img_id is authoritative when set/non-zero.
        //   2. $product->variant_img_id is ONLY a fallback when img_id is empty
        $product_img_id = isset($product->img_id) ? (int) $product->img_id : 0;
        $variant_img_id = isset($product->variant_img_id) ? (int) $product->variant_img_id : 0;
        $thumb_rental_id = $product_img_id ?: $variant_img_id;

        foreach ($images as $rental_img_id => $img_id) {
            if ($thumb_rental_id && (int) $rental_img_id === $thumb_rental_id) {
                $thumbnail_id = $img_id;
            } else {
                $gallery .= ($gallery? ',': '') . $img_id;
            }
        }
    }

    update_post_meta($id, '_product_image_gallery', $gallery);
    update_post_meta($id, '_thumbnail_id', $thumbnail_id);

    // Clear product cache so listing/category/search pages reflect the updated thumbnail immediately
    wc_delete_product_transients($id);
    rental_clear_cache();

    http_response_code(200);
    echo json_encode([
        "product" => [
            "rental_id" => $product->id,
            "id" => $id,
            "image" => $thumbnail_id,
            "gallery" => $gallery,
        ],
        "message" => "Product image successfully updated!"
    ]);
    return;
}

// Product attribute
function rentopian_attach_attribute_to_product() {
    global $wpdb, $rental_tables;
    $rental_product_relations = $wpdb->prefix . $rental_tables["product_relations"];
    $rental_variant_relations = $wpdb->prefix . $rental_tables["variant_relations"];
    $rental_attribute_relations = $wpdb->prefix . $rental_tables["attribute_relations"];

    if ( !isset($_POST["attribute"])) {
        http_response_code(400);
        echo json_encode(["error" => "The 'attribute' is required."]);
        return;
    }
    $attribute = json_decode(stripslashes($_POST["attribute"]));
    if (empty($attribute)) {
        http_response_code(400);
        echo json_encode(["error" => "The 'attribute' is empty."]);
        return;
    }

    $products = $wpdb->get_results("SELECT `id`, `rental_division_id` FROM $rental_product_relations WHERE `rental_id` = $attribute->product_id");
    if (empty($products)) {
        http_response_code(404);
        echo json_encode(["error" => "Product with rental_id $attribute->product_id not found."]);
        return;
    }

    $attribute_slug = $wpdb->get_var("SELECT `attributes`.`attribute_name` FROM `$rental_attribute_relations` " .
        "INNER JOIN `" . $wpdb->prefix . "woocommerce_attribute_taxonomies` AS `attributes` ON `attributes`.`attribute_id` = `$rental_attribute_relations`.`id` " .
        "WHERE `$rental_attribute_relations`.`rental_id` = $attribute->id");
    $attr = ["attribute_name" => $attribute_slug];
    if ( !$attribute_slug) {
        require_once("includes/models/RTAttribute.php");
        $attr = (new RTAttribute($attribute->id, $attribute->slug, $attribute->title, $attribute->type))
            ->create();
        $attribute_slug = $attr->attribute_name;
        /*
                $attr = [
                    "attribute_name" => $attribute->slug,
                    "attribute_label" => $attribute->title,
                    "attribute_type" => "select",
                    "attribute_orderby" => "menu_order",
                    "attribute_public" => 0
                ];
                $wpdb->insert($wpdb->prefix . "woocommerce_attribute_taxonomies", $attr);
                $attr["attribute_id"] = $wpdb->insert_id;

                $wpdb->insert($rental_attribute_relations, [
                    "id" => $attr["attribute_id"],
                    "rental_id" => $attribute->id
                ]);

                $wc_attribute_taxonomies = get_option('_transient_wc_attribute_taxonomies');
                if ( !is_array($wc_attribute_taxonomies)) {
                    $wc_attribute_taxonomies = [];
                }
                $wc_attribute_taxonomies[] = (object) $attr;
                update_option('_transient_wc_attribute_taxonomies', $wc_attribute_taxonomies);
                unset($wc_attribute_taxonomies);

                if (defined('ZOO_CW_VERSION')) {
                    $attribute_type = $attribute->type == 2? "color": ($attribute->type == 3? "image": "");
                    if ($attribute_type) {
                        $wpdb->insert($wpdb->prefix . "zoo_cw_product_attribute_swatch_type", [
                            "attribute_id" => $attr["attribute_id"],
                            "swatch_type" => $attribute_type
                        ]);
                    }
                }
        */
    }

    $attr_val = [];
    $attr_val_ids = [];
    foreach ($attribute->attribute_values as $value) {
        if ( !isset($attr_val[$value->id])) {
            // get attribute value term_taxonomy_id
            $term = term_exists($value->slug, "pa_$attribute_slug");
            if ( !$term) {
                register_taxonomy("pa_$attribute_slug", 'product');
                $term = wp_insert_term($value->title, "pa_$attribute_slug", ["slug" => $value->slug]);
            }
            $term_taxonomy_id = (integer) $term["term_taxonomy_id"];
            $attr_val[$value->id] = [
                "term_taxonomy_id" => $term_taxonomy_id,
                "title" => $value->title,
                "slug" => $value->slug,
                "default" => (bool) $value->default,
                "variants" => [$value->variant_id]
            ];
            $attr_val_ids[] = $term_taxonomy_id;
        }
        $attr_val[$value->id]["variants"][] = $value->variant_id;
        if ($value->default) {
            $attr_val[$value->id]["default"] = true;
        }
    }

    foreach ($products as $product) {
        $product_id = $product->id;

        $type = wp_get_post_terms($product_id, 'product_type')[0]->slug;
        if ($type != 'variable') {
            wp_set_post_terms($product_id, 'variable', 'product_type');
            $post = get_post($product_id);
            $post_meta = get_post_meta($product_id);

            $variant_id = wp_insert_post([
                'post_author' => '1',
                'post_title' => $post->post_title,
                'post_status' => 'publish',
                'comment_status' => 'closed',
                'post_parent' => $product_id,
                'post_type' => 'product_variation',
            ]);

            $variantmeta_sql = "($variant_id, '_variation_description', '" . addslashes($post->post_content) . "'),
            ($variant_id, '_wc_rating_count', 'a:0:{}'),
            ($variant_id, '_wc_review_count', '0'),
            ($variant_id, '_wc_average_rating', '0'),
            ($variant_id, '_sku', '" . $post_meta['_sku'][0] . "'),
            ($variant_id, '_regular_price', '" . $post_meta['_regular_price'][0] . "'),
            ($variant_id, '_job_cost', '" . $post_meta['_job_cost'][0] . "'),
            ($variant_id, '_sale_price', '" . $post_meta['_sale_price'][0] . "'),
            ($variant_id, '_sale_price_dates_from', '' ),
            ($variant_id, '_sale_price_dates_to', '' ),
            ($variant_id, 'total_sales', '0' ),
            ($variant_id, '_tax_status', '" . $post_meta['_tax_status'][0] . "' ),
            ($variant_id, '_tax_class', '' ),
            ($variant_id, '_manage_stock', 'no' ),
            ($variant_id, '_backorders', 'yes' ),
            ($variant_id, '_sold_individually', 'no' ),
            ($variant_id, '_weight', '" . $post_meta['_weight'][0] . "'),
            ($variant_id, '_length', '" . $post_meta['_length'][0] . "'),
            ($variant_id, '_width', '" . $post_meta['_width'][0] . "'),
            ($variant_id, '_height', '" . $post_meta['_height'][0] . "'),
            ($variant_id, '_depth', '" . $post_meta['_depth'][0] . "'),
            ($variant_id, '_upsell_ids', 'a:0:{}' ),
            ($variant_id, '_crosssell_ids', 'a:0:{}' ),
            ($variant_id, '_purchase_note', '' ),
            ($variant_id, '_virtual', 'no' ),
            ($variant_id, '_downloadable', 'no' ),
            ($variant_id, '_product_image_gallery', '' ),
            ($variant_id, '_download_limit', '-1' ),
            ($variant_id, '_download_expiry', '-1' ),
            ($variant_id, '_stock', '" . $post_meta['_stock'][0] . "' ),
            ($variant_id, '_stock_status', '" . $post_meta['_stock_status'][0] . "' ),
            ($variant_id, '_product_version', '3.2.3'),
            ($variant_id, '_price', '" . $post_meta['_price'][0] . "'),
            ($variant_id, '_rental_inventory_id', '" . $post_meta['_rental_inventory_id'][0] . "'),
            ($variant_id, 'zoo-cw-variation-gallery', '" . $post_meta['_product_image_gallery'][0] . "'),
            ($variant_id, '_thumbnail_id', '" . $post_meta['_thumbnail_id'][0] . "'),
            ($variant_id, '_downloadable_files', 'a:0:{}')";

            $wpdb->query("INSERT INTO `$wpdb->postmeta` (`post_id`, `meta_key`, `meta_value`) VALUES $variantmeta_sql");
            if ($wpdb->last_error !== '') {
                http_response_code(400);
                echo json_encode(["error" => "SQL_ERROR: $wpdb->last_error (SQL: $wpdb->last_query)"]);
                throw new RentalException("SQL_ERROR: $wpdb->last_error (SQL: $wpdb->last_query)");
            }
            $wpdb->query("INSERT INTO `$rental_variant_relations` (`id`, `rental_id`, `rental_division_id`) " .
                "VALUES ($variant_id, {$attribute->attribute_values[0]->variant_id}, $product->rental_division_id)");
            if ($wpdb->last_error !== '') {
                http_response_code(400);
                echo json_encode(["error" => "SQL_ERROR: $wpdb->last_error (SQL: $wpdb->last_query)"]);
                throw new RentalException("SQL_ERROR: $wpdb->last_error (SQL: $wpdb->last_query)");
            }

            if (isset($wpdb->wc_product_meta_lookup)) {

                $on_sale = ! empty($post_meta['_sale_price'][0]) ? 1 : 0;
            
                $price = isset($post_meta['_price'][0]) && $post_meta['_price'][0] !== ''
                    ? (float) $post_meta['_price'][0]
                    : 0;
            
                // If _stock is missing or empty, send NULL to MySQL instead of nothing
                if (isset($post_meta['_stock'][0]) && $post_meta['_stock'][0] !== '') {
                    $stock_sql = (int) $post_meta['_stock'][0];   
                } else {
                    $stock_sql = 'NULL';                          
                }
            
                $sku          = isset($post_meta['_sku'][0])          ? $post_meta['_sku'][0]          : '';
                $stock_status = isset($post_meta['_stock_status'][0]) ? $post_meta['_stock_status'][0] : 'instock';
                $tax_status   = isset($post_meta['_tax_status'][0])   ? $post_meta['_tax_status'][0]   : 'taxable';
            
                // Build SQL; $stock_sql is injected as a literal (int or NULL), others are escaped
                $sql = $wpdb->prepare(
                    "INSERT INTO `$wpdb->wc_product_meta_lookup`
                    (`product_id`, `sku`, `virtual`, `downloadable`,
                     `min_price`, `max_price`, `onsale`, `stock_quantity`,
                     `stock_status`, `tax_status`)
                     VALUES (%d, %s, %d, %d, %f, %f, %d, $stock_sql, %s, %s)",
                    $variant_id,
                    $sku,
                    0,
                    0,
                    $price,
                    $price,
                    $on_sale,
                    $stock_status,
                    $tax_status
                );
            
                $wpdb->query($sql);
            
                if ($wpdb->last_error !== '') {
                    http_response_code(400);
                    echo json_encode(["error" => "SQL_ERROR: $wpdb->last_error (SQL: $wpdb->last_query)"]);
                    throw new RentalException("SQL_ERROR: $wpdb->last_error (SQL: $wpdb->last_query)");
                }
            }
            

            // delete product cache
            wc_delete_product_transients($product_id);
        }

        $product_attributes = get_post_meta($product_id, '_product_attributes', true);
        if ( !is_array($product_attributes)) {
            $product_attributes = [];
        }
        $product_attributes["pa_$attribute_slug"] = [
            "name" => "pa_$attribute_slug",
            "value" => "",
            "position" => count($product_attributes),
            "is_visible" => 1,
            "is_variation" => 1,
            "is_taxonomy" => 1
        ];
        update_post_meta($product_id, '_product_attributes', $product_attributes);

        $product_attributes_count = 0;
        if (isset($attribute->product_attrs_count) && !empty($attribute->product_attrs_count)) {
            $product_attributes_count = $attribute->product_attrs_count;
        }
        if ( !empty($attr_val_ids)) {
            wp_set_post_terms($product_id, $attr_val_ids, "pa_$attribute_slug", true);
        }

        foreach ($attr_val as $val) {
            foreach ($val["variants"] as $variant_rental_id) {
                $variant_id = $wpdb->get_var("SELECT `id` FROM $rental_variant_relations WHERE `rental_id` = $variant_rental_id AND `rental_division_id` = $product->rental_division_id");
                if ($variant_id) {
                    update_post_meta($variant_id, "attribute_pa_$attribute_slug", $val["slug"]);
                }
            }
            if ($val["default"]) {
                $default_attributes = get_post_meta($product_id, '_default_attributes', true);
                if ( !is_array($default_attributes)) {
                    $default_attributes = [];
                }
                $default_attributes["pa_$attribute_slug"] = $val["slug"];
                if (isset($product_attributes_count) && $product_attributes_count < 2) {
                    update_post_meta($product_id, "_default_attributes", $default_attributes);
                }
            }
        }
    }

    // Polylang: assign default language to any newly created variants and attribute terms
    $pll_post_ids = [];
    $pll_term_ids = [];
    foreach ( $products as $product ) {
        $pll_post_ids[] = (int) $product->id;
    }
    foreach ( $attr_val_ids as $tt_id ) {
        // attr_val_ids contains term_taxonomy_ids; for Polylang we need term_ids
        $pll_term_ids[] = (int) $tt_id;
    }
    if ( ! empty( $pll_post_ids ) ) {
        rental_pll_assign_product( $pll_post_ids[0], array_slice( $pll_post_ids, 1 ) );
        rental_translation_queue_product( $pll_post_ids[0], array_slice( $pll_post_ids, 1 ) );
    }
    if ( ! empty( $pll_term_ids ) ) {
        rental_pll_assign_terms( $pll_term_ids );
        rental_translation_queue_terms( $pll_term_ids );
    }

    http_response_code(200);
    echo json_encode([
        "products" => $products,
        "attribute" => $attr,
        "attribute_values" => $attr_val,
        "message" => "Product attribute attached successfully."
    ]);
    return;
}

function rentopian_detach_attribute_from_product() {
    global $wpdb, $rental_tables;
    $rental_product_relations = $wpdb->prefix . $rental_tables["product_relations"];
    $rental_attribute_relations = $wpdb->prefix . $rental_tables["attribute_relations"];

    if ( !isset($_POST["attribute"])) {
        http_response_code(400);
        echo json_encode(["error" => "The 'attribute' is required."]);
        return;
    }
    $attribute = json_decode(stripslashes($_POST["attribute"]));
    if (empty($attribute)) {
        http_response_code(400);
        echo json_encode(["error" => "The 'attribute' is empty."]);
        return;
    }

    $products = $wpdb->get_results("SELECT `id`, `rental_division_id` FROM $rental_product_relations WHERE `rental_id` = $attribute->product_id");
    if (empty($products)) {
        http_response_code(404);
        echo json_encode(["error" => "Product with rental_id $attribute->product_id not found."]);
        return;
    }

    $attribute_slug = $wpdb->get_var("SELECT `attributes`.`attribute_name` FROM `$rental_attribute_relations` " .
        "INNER JOIN `" . $wpdb->prefix . "woocommerce_attribute_taxonomies` AS `attributes` ON `attributes`.`attribute_id` = `$rental_attribute_relations`.`id` " .
        "WHERE `$rental_attribute_relations`.`rental_id` = $attribute->id");
    if ( !$attribute_slug) {
        http_response_code(404);
        echo json_encode(["error" => "Product attribute with rental_id $attribute->id not found."]);
        return;
    }

    foreach ($products as $product) {
        $product_id = $product->id;
        $taxonomy = "pa_$attribute_slug";

        $product_attributes = get_post_meta($product_id, '_product_attributes', true);
        if (is_array($product_attributes) && isset($product_attributes[$taxonomy])) {
            unset($product_attributes[$taxonomy]);
            update_post_meta($product_id, '_product_attributes', $product_attributes);
        }

        $wpdb->query("DELETE `tr` FROM `$wpdb->term_relationships` AS `tr`" .
            "INNER JOIN `$wpdb->term_taxonomy` AS `tt` ON `tt`.`term_taxonomy_id` = `tr`.`term_taxonomy_id` " .
            "WHERE `tr`.`object_id` = $product_id AND `tt`.`taxonomy` = '$taxonomy'");
    }

    http_response_code(200);
    echo json_encode([
        "products" => $products,
        "attribute_slug" => $attribute_slug,
        "message" => "Product attribute detached successfully."
    ]);
}

function rentopian_save_product_attribute() {
    if ( !isset($_POST["attribute"])) {
        http_response_code(400);
        echo json_encode(["error" => "The 'attribute' is required."]);
        return;
    }
    $attribute = json_decode(stripslashes($_POST["attribute"]));
    if (empty($attribute)) {
        http_response_code(400);
        echo json_encode(["error" => "The 'attribute' is empty."]);
        return;
    }

    require_once("includes/models/RTAttribute.php");
    $attribute = (new RTAttribute($attribute->id, $attribute->slug, $attribute->title, $attribute->type))
        ->save();

    http_response_code(200);
    echo json_encode([
        "attribute" => $attribute,
        "message" => "Product attribute saved successfully."
    ]);
}

// Product attribute value
function rentopian_product_attribute_value_handler($action) {
    if ( !isset($_POST["attribute_value"])) {
        http_response_code(400);
        echo json_encode(["error" => "The 'attribute_value' is required."]);
        return;
    }
    $attribute_value = json_decode(stripslashes($_POST["attribute_value"]));
    if (empty($attribute_value)) {
        http_response_code(400);
        echo json_encode(["error" => "The 'attribute_value' is empty."]);
        return;
    }

    require_once("includes/models/RTAttributeValue.php");
    $old_slug = isset($attribute_value->old_slug)? $attribute_value->old_slug: null;
    $rental_id = isset($attribute_value->id)? $attribute_value->id: 0;
    // A payload that carries no flag leaves the grouping as it is, so an
    // ordinary value update cannot strip a group of its members.
    $is_group = property_exists($attribute_value, 'is_group')? $attribute_value->is_group: null;
    $attribute_value = new RTAttributeValue($attribute_value->attribute_id, $attribute_value->slug, $attribute_value->title, $attribute_value->color, $attribute_value->img_id, $old_slug, $rental_id, $is_group);

    if ($action === 'delete') {
        $attribute_value = $attribute_value->delete();
    } else {
        $attribute_value = $attribute_value->save();
    }

    // A value WordPress refused to store is reported as a failure with its
    // reason, so the sync counts it and names it instead of recording it as
    // written.
    if (is_wp_error($attribute_value)) {
        http_response_code(422);
        echo json_encode([
            "error" => "Product attribute value could not be {$action}d: " . $attribute_value->get_error_message(),
            "code" => $attribute_value->get_error_code(),
        ]);
        return;
    }

    // Polylang: assign default language to attribute value term
    if ( $action !== 'delete' && is_object( $attribute_value ) && isset( $attribute_value->term_id ) ) {

        rental_pll_assign_term( (int) $attribute_value->term_id );
        rental_translation_queue_term( (int)  $attribute_value->term_id );

    } elseif ( $action !== 'delete' && is_array( $attribute_value ) && isset( $attribute_value['term_id'] ) ) {
       
        rental_pll_assign_term( (int) $attribute_value['term_id'] );
        rental_translation_queue_term( (int)  $attribute_value['term_id'] );

    }

    http_response_code(200);
    echo json_encode([
        "attribute_value" => $attribute_value,
        "message" => "Product attribute value {$action}d successfully."
    ]);
}

/**
 * Replace the member set of one attribute value group.
 *
 * The group value itself is upserted first, so a membership sync that arrives
 * before the value's own create webhook still lands: the group gets its term,
 * its swatch and its flag from this payload. Member ids are stored whether or
 * not the values exist here yet — an id that cannot be resolved contributes
 * nothing to the facet until its value arrives, and needs no reconciliation.
 *
 * A payload whose group carries `is_group = 0` is a value converted back to an
 * ordinary one: its members are dropped and the incoming list ignored.
 */
function rentopian_attribute_value_group_sync() {
    if ( !isset($_POST["group"])) {
        http_response_code(400);
        echo json_encode(["error" => "The 'group' is required."]);
        return;
    }

    $group = json_decode(stripslashes($_POST["group"]));
    if (empty($group) || empty($group->group_value) || empty($group->group_value->id)) {
        http_response_code(400);
        echo json_encode(["error" => "The 'group' is empty or has no group_value."]);
        return;
    }

    $group_value = $group->group_value;
    $group_id = (int) $group_value->id;
    $attribute_id = isset($group_value->attribute_id)? (int) $group_value->attribute_id: 0;
    $is_group = !empty($group_value->is_group);
    $members = [];

    if ($is_group && isset($group->members) && is_array($group->members)) {
        $members = $group->members;
    }

    // Storage is optional in the sense that its absence must not fail the
    // delivery: report it and let the next sync reconcile.
    if ( !class_exists('Rental_Attribute_Groups')) {
        http_response_code(200);
        echo json_encode([
            "group_value_id" => $group_id,
            "stored" => false,
            "message" => "Attribute groups are not supported by this plugin build.",
        ]);
        return;
    }

    Rental_Attribute_Groups::ensure_tables();

    // The group value is a value like any other: same term, same swatch meta,
    // same rename handling.
    require_once("includes/models/RTAttributeValue.php");
    $old_slug = isset($group_value->old_slug)? $group_value->old_slug: null;
    $saved_value = (new RTAttributeValue(
        $attribute_id,
        isset($group_value->slug)? $group_value->slug: '',
        isset($group_value->title)? $group_value->title: '',
        isset($group_value->color)? $group_value->color: '',
        isset($group_value->img_id)? $group_value->img_id: 0,
        $old_slug,
        $group_id,
        $is_group? 1: 0
    ))->save();

    if (is_wp_error($saved_value)) {
        http_response_code(422);
        echo json_encode([
            "error" => "Group value could not be saved: " . $saved_value->get_error_message(),
            "code" => $saved_value->get_error_code(),
        ]);
        return;
    }

    $term_id = 0;
    if (is_object($saved_value) && isset($saved_value->term_id)) {
        $term_id = (int) $saved_value->term_id;
    } elseif (is_array($saved_value) && isset($saved_value["term_id"])) {
        $term_id = (int) $saved_value["term_id"];
    }

    if ($term_id) {
        rental_pll_assign_term($term_id);
        rental_translation_queue_term($term_id);
    }

    if ($is_group) {
        $stored = Rental_Attribute_Groups::replace_members($group_id, $attribute_id, $members);
    } else {
        Rental_Attribute_Groups::clear_group($group_id);
        $stored = 0;
    }

    http_response_code(200);
    echo json_encode([
        "group_value_id" => $group_id,
        "is_group" => $is_group? 1: 0,
        "stored" => true,
        "members_saved" => $stored,
        "message" => "Attribute value group synced successfully.",
    ]);
}

// Product Variant
function rentopian_variant_create($variant) {
    global $wpdb, $rental_tables;
    $rental_product_relations = $wpdb->prefix . $rental_tables["product_relations"];
    $rental_variant_relations = $wpdb->prefix . $rental_tables["variant_relations"];
    $rental_image_relations = $wpdb->prefix . $rental_tables["image_relations"];
    $rental_attribute_relations = $wpdb->prefix . $rental_tables["attribute_relations"];

    $variant = json_decode(stripslashes($variant));
    $parent_product = isset($variant->parent_product) ? $variant->parent_product: "";
    if ( !$variant) {
        http_response_code(400);
        echo json_encode(["error" => "Variant is empty."]);
        return;
    }

    // set max_execution_time
    set_time_limit(200);

    // get product id
    $product_id = $wpdb->get_var("SELECT `id` FROM $rental_product_relations WHERE `rental_id` = $variant->product_id AND `rental_division_id` = $variant->division_id");
    if ( !$product_id) {
        http_response_code(404);
        echo json_encode(["error" => "Product with rental_id $variant->product_id not found."]);
        return;
    }

    // unhiding the only remaining variant
    $remaining_variant_id = get_post_meta($product_id, '_rental_remaining_variant_id', true);
    if (isset($remaining_variant_id) && !empty($remaining_variant_id) && $parent_product) {
        wp_update_post(['ID' => $remaining_variant_id, 'post_status' => 'publish']);
        $wpdb->query("DELETE FROM $wpdb->postmeta WHERE `post_id` = $product_id");
    
        // recreating parent product's post meta data
        $is_add_on = $parent_product->is_add_on || $parent_product->hidden_from_api? 1: 0;

        $product_attributes = [];
        if ($parent_product->product_attributes) {
            $i = 0;
            foreach ($parent_product->product_attributes as $attr_data) {
                // collect data for postmeta _product_attributes
                $attribute = $attr_data->slug;
                $product_attributes["pa_$attribute"] = [
                    "name" => "pa_$attribute",
                    "value" => "",
                    "position" => $i,
                    "is_visible" => 1,
                    "is_variation" => 1,
                    "is_taxonomy" => 1
                ];
                $i++;
            }
            $product_attributes = serialize($product_attributes);
        }
    
        $img_id = "";
        if ($parent_product) {
            // Resolve rental image ID to WP attachment ID for _thumbnail_id
            if ($parent_product->img_id) {
                $rental_image_relations = $wpdb->prefix . $rental_tables["image_relations"];
                $resolved_img_id = $wpdb->get_var("SELECT `id` FROM $rental_image_relations WHERE `rental_id` = $parent_product->img_id");
                $img_id = $resolved_img_id ? $resolved_img_id : "";
            }
        }
        
        $productmeta_sql[$product_id] = "($product_id, '_wc_review_count', '0' ),
            ($product_id, '_wc_rating_count', 'a:0:{}'),
            ($product_id, '_wc_average_rating', '0'),
            ($product_id, '_edit_last', ''),
            ($product_id, '_edit_lock', ''),
            ($product_id, '_sku', '' ),
            ($product_id, '_regular_price', ''),
            ($product_id, '_sale_price', '' ),
            ($product_id, '_sale_price_dates_from', '' ),
            ($product_id, '_sale_price_dates_to', '' ),
            ($product_id, 'total_sales', '0' ),
            ($product_id, '_tax_status', 'taxable' ),
            ($product_id, '_tax_class', '' ),
            ($product_id, '_manage_stock', 'no' ),
            ($product_id, '_backorders', 'yes' ),
            ($product_id, '_sold_individually', 'no' ),
            ($product_id, '_weight', '' ),
            ($product_id, '_length', '' ),
            ($product_id, '_width', '' ),
            ($product_id, '_height', '' ),
            ($product_id, '_depth', '' ),
            ($product_id, '_upsell_ids', 'a:0:{}' ),
            ($product_id, '_crosssell_ids', 'a:0:{}' ),
            ($product_id, '_purchase_note', '' ),
            ($product_id, '_virtual', 'no' ),
            ($product_id, '_downloadable', 'no' ),
            ($product_id, '_product_image_gallery', '' ),
            ($product_id, '_download_limit', '-1' ),
            ($product_id, '_download_expiry', '-1' ),
            ($product_id, '_stock', '1' ),
            ($product_id, '_stock_status', 'instock'),
            ($product_id, '_product_version', '3.2.3'),
            ($product_id, '_price', ''),
            ($product_id, 'zoo_cw_product_swatch_data', 'a:0:{}'),
            ($product_id, '_thumbnail_id', '$img_id'),
            ($product_id, '_product_attributes', '$product_attributes'),
            ($product_id, '_rental_exempt_waiver', '$parent_product->exempt_waiver'),
            ($product_id, '_rental_is_sale', '$parent_product->is_sale'),
            ($product_id, '_rental_by_interval', '$parent_product->rental_by_interval'),
            ($product_id, '_rental_by_slot', '$parent_product->rental_by_slot'),
            ($product_id, '_rental_is_add_on', '$is_add_on')";
        rental_insert("INSERT INTO `$wpdb->postmeta` (`post_id`, `meta_key`, `meta_value`) VALUES", $productmeta_sql);
    
        $variable = $wpdb->get_var("SELECT `term_id` FROM $wpdb->terms WHERE `name` = 'variable' AND `slug` = 'variable'");
        $wpdb->query("UPDATE $wpdb->term_relationships SET term_taxonomy_id = $variable WHERE object_id = $product_id limit 1");
    
    }
    

    $variant_id = $wpdb->get_var("SELECT `id` FROM $rental_variant_relations WHERE `rental_id` = $variant->id AND `rental_division_id` = $variant->division_id");

    // The id sets reference for this variant today. Compared against the id
    // this call ends up with, so a replaced post is repointed in every set.
    $rental_set_variant_old = (int) $variant_id;

    if ($variant_id) {
        // update product variant
        if (wp_update_post([
            'ID' => $variant_id,
            'post_title' => $variant->name,
            'post_parent' => $product_id,
            'post_status' => 'publish',
        ])) {
            $wpdb->query("DELETE FROM $wpdb->postmeta WHERE `post_id` = $variant_id");
            if (isset($wpdb->wc_product_meta_lookup)) {
                $wpdb->query("DELETE FROM $wpdb->wc_product_meta_lookup WHERE `product_id` = $variant_id");
            }
        } else {
            $wpdb->query("DELETE FROM $rental_variant_relations WHERE `rental_id` = $variant->id AND `rental_division_id` = $variant->division_id");
            $variant_id = 0;
        }
        $variant_relations_sql = "";
    }
    if ( !$variant_id) {
        // insert product variant
        $variant_id = wp_insert_post([
            'post_author' => '1',
            'post_title' => $variant->name,
            'post_status' => 'publish',
            'comment_status' => 'closed',
            'post_parent' => $product_id,
            'post_type' => 'product_variation',
        ]);
        $variant_relations_sql = "($variant_id, $variant->id, $variant->division_id)";
    }

    $product_attributes_count = 0;
    if (isset($variant->product_attrs_count) && !empty($variant->product_attrs_count)) {
        $product_attributes_count = $variant->product_attrs_count;
    }

    $variantmeta_sql = "";

    if ($variant->attribute_values) {
        $attr_val = [];
        $variants = null;
        foreach ($variant->attribute_values as $attribute_value) {
            $attribute_slug = $wpdb->get_var("SELECT `attributes`.`attribute_name` FROM `$rental_attribute_relations` " .
                "INNER JOIN `" . $wpdb->prefix . "woocommerce_attribute_taxonomies` AS `attributes` ON `attributes`.`attribute_id` = `$rental_attribute_relations`.`id` " .
                "WHERE `$rental_attribute_relations`.`rental_id` = $attribute_value->attribute_id");
            if ( !$attribute_slug) {
                continue;
            }

            $taxonomy = "pa_$attribute_slug";
            $attr_val[$taxonomy] = $attribute_value->slug;

            // Sanitize the title to fix double-escaped quotes
            $attribute_value->title = wp_unslash($attribute_value->title);

            $term_exists_result = term_exists($attribute_value->title, $taxonomy);
            if ($term_exists_result === 0 || $term_exists_result === null) {
                // Term does not exist, so create new one

                // insert attribute value
                $term = wp_insert_term($attribute_value->title, $taxonomy, [
                    "slug" => $attribute_value->slug,
                    // 'description' => '',
                ]);

                $term_taxonomy_id = (integer) (is_wp_error($term)? $term->error_data["term_exists"]: $term["term_taxonomy_id"]);

            } else {
                // Term already exists, so update it

                // update attribute value
                $term = wp_update_term( $term_exists_result['term_id'], $taxonomy, [
                    "slug" => $attribute_value->slug,
                    // 'description' => '',
                ]);

                $term_taxonomy_id = (is_wp_error($term)? $term->error_data : $term["term_taxonomy_id"]);
            }


            // if there was no error on term insert/update then continue
            if (!is_array($term_taxonomy_id)) {

                // relate product with attribute value
                wp_set_post_terms($product_id, [$term_taxonomy_id], $taxonomy, true);

                $attribute_value_slug = get_post_meta($variant_id, "attribute_$taxonomy", true);
                if ($attribute_value_slug && $attribute_value_slug != $attribute_value->slug) {
                    update_post_meta($variant_id, "attribute_$taxonomy", $attribute_value->slug);
                    if ($variants === null) {
                        $variants = $wpdb->get_col("SELECT `ID` FROM `$wpdb->posts` WHERE `ID` != $variant_id AND `post_parent` = $product_id AND `post_type` = 'product_variation' AND `post_status` != 'trash'");
                    }
                    rentopian_detach_attribute_value_from_product($product_id, $taxonomy, $attribute_value_slug, $variants);
                }
            }
            
            // if ($term_exists_result === 0) {
                $variantmeta_sql .= "($variant_id, 'attribute_pa_$attribute_slug', '$attribute_value->slug'),";
            // }
            

        }
        if (isset($product_attributes_count) && $product_attributes_count < 2) {
            if ($variant->default) {
                update_post_meta($product_id, "_default_attributes", $attr_val);
            }
        }
        
    }

    $stock_status_despite_quantity = !get_option('rental_inactive_inv_by_quantity');
    $stock_status = $variant->inventory && ($stock_status_despite_quantity || $variant->quantity)? 'instock': 'outofstock';

    $tax_status = $variant->taxable? 'taxable': 'none';

    $job_cost = $variant->job_cost? $variant->job_cost: 0;

    $sku = addslashes($variant->sku);

    $price = $variant->rental_price;
    $sale_price = '';
    if ($variant->sale_price > 0) {
        $price = $sale_price = $variant->sale_price;
    }

    $thumbnail_id = "";
    if ($variant->img_id) {
        $resolved_thumb = $wpdb->get_var("SELECT `id` FROM $rental_image_relations WHERE `rental_id` = $variant->img_id");
        if ($resolved_thumb) {
            $thumbnail_id = $resolved_thumb;
        } else {
            // Image not yet synced — attempt to download it now via RTImage
            require_once("includes/models/RTImage.php");
            $downloaded = (new RTImage())->getImages([$variant->img_id]);
            if (isset($downloaded[$variant->img_id])) {
                $thumbnail_id = $downloaded[$variant->img_id];
            }
        }
    }

	$variation_gallery = "";
	if ( !empty($variant->images)) {
		foreach ($variant->images as $gallery_img_id) {
			if ($gallery_img_id != $variant->img_id) {
				$wp_img_id = $wpdb->get_var("SELECT `id` FROM $rental_image_relations WHERE `rental_id` = $gallery_img_id");
				if (!$wp_img_id) {
					// Gallery image not yet synced — attempt download
					require_once("includes/models/RTImage.php");
					$downloaded = (new RTImage())->getImages([$gallery_img_id]);
					$wp_img_id = isset($downloaded[$gallery_img_id]) ? $downloaded[$gallery_img_id] : null;
				}
				if ($wp_img_id) {
					$variation_gallery .= $variation_gallery ? ",$wp_img_id" : $wp_img_id;
				}
			}
		}
	}

    $price_multiplier_id = empty($variant->price_multiplier_id) ? 0 : $variant->price_multiplier_id;

    // rental by interval/rental by time slot data
    $rental_interval = empty($variant->rental_interval) ? 0 : $variant->rental_interval;
    $interval_steps = empty($variant->interval_steps) ? 0 : $variant->interval_steps;
    $rental_time_slots = empty($variant->rental_time_slots) ? "" : $variant->rental_time_slots;

    $variant_ids_without_division = [];
    $variant_pids = [];

    $product_stock_collection = [];
    $duplicate_product_ids = [];
    $variant_has_duplicates_current = 0;

    $variant_divs = isset($variant->divisions) ? $variant->divisions : null;
    $product_summed_qty = $variant->product_summed_qty;

    if ($variants = $variant->product_variants) {
        
        $total_variants = count($variants);
        $counter = 0;
        foreach($variants as $var) {
            $counter++;

            if (!in_array($var->id, $variant_ids_without_division)) {
                $variant_ids_without_division[] = $var->id;
            }

            $variant_has_duplicates = 0;
            $variant_stock = $var->quantity;

            if ($variant_divs) {
                $variant_divisions = json_decode($variant_divs);
                $variant_has_duplicates = $variant_divisions && count($variant_divisions) > 1 ? 1 : 0;

                if (
                    $variant_has_duplicates 
                    && get_option('rental_location_based_duplicate_filter', 0) == 1 
                    && isset($product_summed_qty)
                ) {
                    $variant_stock = $product_summed_qty;
                }

                $vid = $wpdb->get_var("SELECT `id` FROM $rental_variant_relations WHERE `rental_id` = $var->id AND `rental_division_id` = $var->division_id");
                $pid = $wpdb->get_var("SELECT `id` FROM $rental_product_relations WHERE `rental_id` = $var->product_id AND `rental_division_id` = $var->division_id");
    
                if ($variant_has_duplicates && $var->division_id != get_option('rental_location_based_duplicate_filter_division_id', 0)) {
                    
                    if ($vid) {
                        $duplicate_product_ids[] = $vid;

                        // rental_set_product_catalog_visibility($vid, 'hidden');
                        update_post_meta($vid, '_rental_is_add_on', 1);
                    }

                    if ($pid) {
                        $duplicate_product_ids[] = $pid;

                        // rental_set_product_catalog_visibility($pid, 'hidden');
                        update_post_meta($pid, '_rental_is_add_on', 1);
                    }
                }

                if ($vid) {
                    update_post_meta($vid, '_rental_is_duplicate', $variant_has_duplicates);
                    
                    update_post_meta($vid, '_stock', $variant_stock);
        
                    $wpdb->update( $wpdb->wc_product_meta_lookup, [ 
                        'stock_quantity' => $variant_stock
                    ], ['product_id' => $vid] );

                    $product_stock_collection[$vid] = $variant_stock;
                }
    
    
                if ($pid) {

                    $variant_pids[] = $pid;

                    update_post_meta($pid, '_stock', $variant_stock);
    
                    $wpdb->update( $wpdb->wc_product_meta_lookup, [ 
                        'stock_quantity' => $variant_stock
                    ], ['product_id' => $pid] );

                    $product_stock_collection[$pid] = $variant_stock;
                }

                
                if ($variant_id == $vid) {
                    $variant_has_duplicates_current = $variant_has_duplicates;
                }

                if ($counter === $total_variants) {

                    if (count($variant_pids) > 1) {

                        foreach($variant_pids as $pid) {

                            // if the variants count are more than 1 and it is the last iteration 
                            // so update the parent product dimensions to empty values
                            if (count($variant_ids_without_division) > 1) {
                                update_post_meta($pid, '_weight', '');
                                update_post_meta($pid, '_length', '');
                                update_post_meta($pid, '_width', '');
                                update_post_meta($pid, '_height', '');
                                update_post_meta($pid, '_depth', '');
                            }

                            // if the variants count are less than 2 and it is the last iteration 
                            // so update the parent product dimensions to variant dimensions
                            if (count($variant_ids_without_division) < 2) {
                                update_post_meta($pid, '_weight', $var->weight);
                                update_post_meta($pid, '_length', $var->length);
                                update_post_meta($pid, '_width', $var->width);
                                update_post_meta($pid, '_height', $var->height);
                                update_post_meta($pid, '_depth', isset($var->depth) ? $var->depth : "");
                            }
                        }

                    } else {

                        // if the variants count are more than 1 and it is the last iteration 
                        // so update the parent product dimensions to empty values
                        if (count($variant_ids_without_division) > 1) {
                            update_post_meta($pid, '_weight', '');
                            update_post_meta($pid, '_length', '');
                            update_post_meta($pid, '_width', '');
                            update_post_meta($pid, '_height', '');
                            update_post_meta($pid, '_depth', '');
                        }

                        // if the variants count are less than 2 and it is the last iteration 
                        // so update the parent product dimensions to variant dimensions
                        if (count($variant_ids_without_division) < 2) {
                            update_post_meta($pid, '_weight', $var->weight);
                            update_post_meta($pid, '_length', $var->length);
                            update_post_meta($pid, '_width', $var->width);
                            update_post_meta($pid, '_height', $var->height);
                            update_post_meta($pid, '_depth', isset($var->depth) ? $var->depth : "");
                        }
                    }
                    
                }

            }
            
        }
    }


    // add variant data for insert in postmeta table
    $variantmeta_sql .= "($variant_id, '_variation_description', '" . addslashes($variant->description) . "'),
        ($variant_id, '_wc_rating_count', 'a:0:{}'),
        ($variant_id, '_wc_review_count', '0'),
        ($variant_id, '_wc_average_rating', '0'),
        ($variant_id, '_sku', '$sku'),
        ($variant_id, '_regular_price', '$variant->rental_price'),
        ($variant_id, '_job_cost', '$job_cost'),
        ($variant_id, '_price_multiplier_id', '$price_multiplier_id'),
        ($variant_id, '_sale_price', '$sale_price'),
        ($variant_id, '_sale_price_dates_from', ''),
        ($variant_id, '_sale_price_dates_to', ''),
        ($variant_id, 'total_sales', '0'),
        ($variant_id, '_tax_status', '$tax_status'),
        ($variant_id, '_tax_class', '' ),
        ($variant_id, '_manage_stock', 'no' ),
        ($variant_id, '_backorders', 'yes' ),
        ($variant_id, '_sold_individually', 'no' ),
        ($variant_id, '_weight', '$variant->weight'),
        ($variant_id, '_length', '$variant->length'),
        ($variant_id, '_width', '$variant->width'),
        ($variant_id, '_height', '$variant->height'),
        ($variant_id, '_depth', '$variant->depth'),
        ($variant_id, '_upsell_ids', 'a:0:{}' ),
        ($variant_id, '_crosssell_ids', 'a:0:{}' ),
        ($variant_id, '_purchase_note', '' ),
        ($variant_id, '_virtual', 'no' ),
        ($variant_id, '_downloadable', 'no' ),
        ($variant_id, '_product_image_gallery', '' ),
        ($variant_id, '_download_limit', '-1' ),
        ($variant_id, '_download_expiry', '-1' ),
        ($variant_id, '_stock', '$variant_stock' ),
        ($variant_id, '_stock_status', '$stock_status'),
        ($variant_id, '_product_version', '3.2.3'),
        ($variant_id, '_price', '$price'),
        ($variant_id, '_rental_inventory_id', '$variant->inventory_id'),
        ($variant_id, 'zoo-cw-variation-gallery', '$variation_gallery'),
        ($variant_id, '_thumbnail_id', '$thumbnail_id'),
        ($variant_id, '_rental_by_interval', '$variant->rental_by_interval'),
        ($variant_id, '_rental_by_slot', '$variant->rental_by_slot'),
        ($variant_id, '_rental_interval', '$rental_interval'),
        ($variant_id, '_rental_interval_steps', '$interval_steps'),
        ($variant_id, '_rental_interval_price', '$variant->rental_interval_price'),
        ($variant_id, '_rental_interval_sale_price', '$variant->rental_interval_sale_price'),
        ($variant_id, '_rental_additional_hourly_price', '$variant->additional_hourly_price'),
        ($variant_id, '_rental_time_slots', '$rental_time_slots'),
        ($variant_id, '_downloadable_files', 'a:0:{}')";
        // ($variant_id, '_manage_stock', 'yes' ),

    $variantmeta_sql .= ",($variant_id, '_rental_is_duplicate', '$variant_has_duplicates_current')";

    if ($variant_has_duplicates_current) {
        $duplicate_product_ids[] = $variant_id;

        // rental_set_product_catalog_visibility($variant_id, 'hidden');
        update_post_meta($variant_id, '_rental_is_add_on', 1);

        $wpdb->update( $wpdb->wc_product_meta_lookup, [ 
            'stock_quantity' => $variant_stock
        ], ['product_id' => $variant_id] );
    }

    $wpdb->query("INSERT INTO `$wpdb->postmeta` (`post_id`, `meta_key`, `meta_value`)
          VALUES $variantmeta_sql");
    if ($wpdb->last_error !== '') {
        http_response_code(400);
        echo json_encode(["error" => "SQL_ERROR: $wpdb->last_error (SQL: $wpdb->last_query)"]);
        throw new RentalException("SQL_ERROR: $wpdb->last_error (SQL: $wpdb->last_query)");
    }
    if ($variant_relations_sql) {
        $wpdb->query("INSERT INTO `$rental_variant_relations` (`id`, `rental_id`, `rental_division_id`)
              VALUES $variant_relations_sql");
        if ($wpdb->last_error !== '') {
            http_response_code(400);
            echo json_encode(["error" => "SQL_ERROR: $wpdb->last_error (SQL: $wpdb->last_query)"]);
            throw new RentalException("SQL_ERROR: $wpdb->last_error (SQL: $wpdb->last_query)");
        }
    }

    // This variant got a different post than the one sets reference, so every
    // set pointing at the old id is repointed. No-op when the post was updated
    // in place, which is the normal path.
    if ($rental_set_variant_old && $rental_set_variant_old !== (int) $variant_id
        && class_exists('Rental_Sets_Variant_Remapper', false)) {
        Rental_Sets_Variant_Remapper::apply(
            [(int) $variant->id => $rental_set_variant_old],
            [(int) $variant->id => (int) $variant_id],
            Rental_Sets_Variant_Remapper::inventory_map([$variant]),
            (int) $product_id,
            (int) $variant->division_id
        );
    }
    if (isset($wpdb->wc_product_meta_lookup)) {
        $on_sale = $sale_price? 1: 0;
        $wpdb->query("INSERT INTO `$wpdb->wc_product_meta_lookup` (`product_id`, `sku`, `virtual`, `downloadable`, " .
            "`min_price`, `max_price`, `onsale`, `stock_quantity`, `stock_status`, `tax_status`) " .
            "VALUES ($variant_id, '$sku', 0, 0, $price, $price, $on_sale, $variant_stock, '$stock_status', '$tax_status')");

        $product_meta_lookup = $wpdb->get_row("SELECT `min_price`, `max_price` FROM `$wpdb->wc_product_meta_lookup` WHERE `product_id` = $product_id");
        if ($product_meta_lookup) {
            if ($price < $product_meta_lookup->min_price) {
                $wpdb->query("UPDATE `$wpdb->wc_product_meta_lookup` SET `min_price` = $price WHERE `product_id` = $product_id");
            } elseif ($price > $product_meta_lookup->max_price) {
                $wpdb->query("UPDATE `$wpdb->wc_product_meta_lookup` SET `max_price` = $price WHERE `product_id` = $product_id");
            }
        }

        if ($wpdb->last_error !== '') {
            http_response_code(400);
            echo json_encode(["error" => "SQL_ERROR: $wpdb->last_error (SQL: $wpdb->last_query)"]);
            throw new RentalException("SQL_ERROR: $wpdb->last_error (SQL: $wpdb->last_query)");
        }
    }

    // delete product cache
    wc_delete_product_transients($product_id);


    $do_not_index_hidden_duplicate_products_for_seo = get_option('rental_do_not_index_hidden_duplicate_products_for_seo', 0);

    if ($parent_product) {
        if ($parent_product->hidden_from_api) {
            rental_set_product_catalog_visibility($product_id, 'hidden');

            if ($do_not_index_hidden_duplicate_products_for_seo) {
                // rental_set_product_general_visibility($product_id, 'private');

                // rental_set_product_catalog_visibility($product_id, 'hidden');
                update_post_meta($product_id, '_rental_is_add_on', 1);

                toggle_index_yoast_seo($product_id);
            }

        } else {

            rental_set_product_catalog_visibility($product_id, 'visible');

            // rental_set_product_general_visibility($product_id, 'publish');
            
            $parent_is_add_on_flag = !empty($parent_product->is_add_on) ? 1 : 0;
            update_post_meta($product_id, '_rental_is_add_on', $parent_is_add_on_flag);

            toggle_index_yoast_seo($product_id, 0);

            if (isset($product_stock_collection[$product_id])) {
                update_post_meta($product_id, '_stock', $product_stock_collection[$product_id]);
    
                $wpdb->update( $wpdb->wc_product_meta_lookup, [ 
                    'stock_quantity' => $product_stock_collection[$product_id]
                ], ['product_id' => $product_id] );
            }
        }
    }


    if ($do_not_index_hidden_duplicate_products_for_seo && get_option('rental_location_based_duplicate_filter', 0) ) {

        foreach($duplicate_product_ids as $duplicate_product_id) {
            // rental_set_product_general_visibility($duplicate_product_id, 'private');

            // rental_set_product_catalog_visibility($duplicate_product_id, 'hidden');
            // update_post_meta($duplicate_product_id, '_rental_is_add_on', 1);

            toggle_index_yoast_seo($duplicate_product_id);
        }
    }


    if ( class_exists( 'WooCommerce_Single_Variations' ) && defined('WC_SINGLE_VAR_VERSION')) {
        $admin_wcs_variation = new WooCommerce_Single_Variations_Admin('plugin', WC_SINGLE_VAR_VERSION);
        $admin_wcs_variation->init();
        // $admin_wcs_variation->update_variations(false);
        $admin_wcs_variation->update_variations(false, true);
        $admin_wcs_variation->reset_transients(false);
    }

    rental_clear_cache();

    // Polylang: assign default language to new variant and ensure parent product has language
    rental_pll_assign_product( (int) $product_id, [ (int) $variant_id ] );
    rental_translation_queue_product( (int) $product_id, [ (int) $variant_id ] );

    http_response_code(200);
    echo json_encode([
        "variant" => [
            "rental_id" => $variant->id,
            "id" => $variant_id,
            "product_id" => $product_id,
            "stock_status" => $stock_status,
        ],
        "message" => "Product variant successfully created!"
    ]);
    return;
}


function rentopian_variant_update($variant) {
    global $wpdb, $rental_tables;
    $rental_product_relations = $wpdb->prefix . $rental_tables["product_relations"];
    $rental_variant_relations = $wpdb->prefix . $rental_tables["variant_relations"];
    $rental_image_relations = $wpdb->prefix . $rental_tables["image_relations"];
    $rental_attribute_relations = $wpdb->prefix . $rental_tables["attribute_relations"];

    $variant = json_decode(stripslashes($variant));
    if ( !$variant) {
        http_response_code(400);
        echo json_encode(["error" => "Variant is empty."]);
        return;
    }

    // set max_execution_time
    set_time_limit(200);

    // get product id
    $product_id = $wpdb->get_var("SELECT `id` FROM $rental_product_relations WHERE `rental_id` = $variant->product_id AND `rental_division_id` = $variant->division_id");
    if ( !$product_id) {
        http_response_code(404);
        echo json_encode(["error" => "Product with rental_id $variant->product_id not found."]);
        return;
    }

    $id = $product_id;
    if ($variant->attribute_values) {
        // get variant id
        $id = $wpdb->get_var("SELECT `id` FROM $rental_variant_relations WHERE `rental_id` = $variant->id AND `rental_division_id` = $variant->division_id");
        if ( !$id) {
            http_response_code(404);
            echo json_encode(["error" => "Variant with rental_id $variant->id not found."]);
            return;
        }

        $product_attributes_count = 0;
        if (isset($variant->product_attrs_count) && $variant->product_attrs_count) {
            $product_attributes_count = $variant->product_attrs_count;
        }

        $attr_val = [];
        $variants = null;
        foreach ($variant->attribute_values as $attribute_value) {
            $attribute_slug = $wpdb->get_var("SELECT `attributes`.`attribute_name` FROM `$rental_attribute_relations` " .
                "INNER JOIN `" . $wpdb->prefix . "woocommerce_attribute_taxonomies` AS `attributes` ON `attributes`.`attribute_id` = `$rental_attribute_relations`.`id` " .
                "WHERE `$rental_attribute_relations`.`rental_id` = $attribute_value->attribute_id");
            if ( !$attribute_slug) {
                continue;
            }
            $taxonomy = "pa_$attribute_slug";
            $attr_val[$taxonomy] = $attribute_value->slug;
            
            // Sanitize the title to fix double-escaped quotes
            $attribute_value->title = wp_unslash($attribute_value->title);

            $term_exists_result = term_exists($attribute_value->title, $taxonomy);
            if ($term_exists_result === 0 || $term_exists_result === null) {
                // Term does not exist, so create new one

                // insert attribute value
                $term = wp_insert_term($attribute_value->title, $taxonomy, [
                    "slug" => $attribute_value->slug,
                    // 'description' => '',
                ]);

                $term_taxonomy_id = (integer) (is_wp_error($term)? $term->error_data["term_exists"]: $term["term_taxonomy_id"]);

            } else {
                // Term already exists, so update it

                 // update attribute value
                $term = wp_update_term( $term_exists_result['term_id'], $taxonomy, [
                    "slug" => $attribute_value->slug,
                    // 'description' => '',
                ]);

                $term_taxonomy_id = (is_wp_error($term)? $term->error_data : $term["term_taxonomy_id"]);

            }

            // if there was no error on term insert/update then continue
            if (!is_array($term_taxonomy_id)) {

                // relate product with attribute value
                wp_set_post_terms($product_id, [$term_taxonomy_id], $taxonomy, true);

                $attribute_value_slug = get_post_meta($id, "attribute_$taxonomy", true);
                if ($attribute_value_slug && $attribute_value_slug != $attribute_value->slug) {
                    update_post_meta($id, "attribute_$taxonomy", $attribute_value->slug);
                    if ($variants === null) {
                        $variants = $wpdb->get_col("SELECT `ID` FROM `$wpdb->posts` WHERE `ID` != $id AND `post_parent` = $product_id AND `post_type` = 'product_variation' AND `post_status` != 'trash'");
                    }
                    rentopian_detach_attribute_value_from_product($product_id, $taxonomy, $attribute_value_slug, $variants);
                }
            }

            // ensure WC variation attribute meta
            update_post_meta($id, "attribute_pa_$attribute_slug", $attribute_value->slug);

        }

        if (isset($product_attributes_count) && $product_attributes_count < 2) {
            if ($variant->default) {
                update_post_meta($product_id, "_default_attributes", $attr_val);
            }
        }
    }

    $updated = $wpdb->update(
        $wpdb->posts,
        ['post_title' => $variant->name],
        ['ID' => $id],
        ['%s'],
        ['%d']
    );

    // update product variant
    // wpdb->update returns 0 rows when the title is unchanged (idempotent
    // no-op); only a strict false is a real failure. Treating 0 as failure
    // would abort the stock/price/meta updates below on every unchanged
    // re-sync.
    if ( $updated === false ) {
        http_response_code(404);
        echo json_encode(["error" => "Post with ID $id not found."]);
        return;
    }

     clean_post_cache($id);
    


    $variant_ids_without_division = [];
    $variant_pids = [];

    $duplicate_product_ids = [];
    $variant_divs = isset($variant->divisions) ? $variant->divisions : null;
    $product_summed_qty = $variant->product_summed_qty;

    $variant_stock = $variant->quantity;
    if ($variants = $variant->product_variants) {
        
        $total_variants = count($variants);
        $counter = 0;
        foreach($variants as $var) {
            $counter++;

            if (!in_array($var->id, $variant_ids_without_division)) {
                $variant_ids_without_division[] = $var->id;
            }

            $variant_has_duplicates = 0;
            $variant_stock = $var->quantity;

            if ($variant_divs) {
                $variant_divisions = json_decode($variant_divs);
                $variant_has_duplicates = $variant_divisions && count($variant_divisions) > 1 ? 1 : 0;

                if (
                    $variant_has_duplicates 
                    && get_option('rental_location_based_duplicate_filter', 0) == 1 
                    && isset($product_summed_qty)
                ) {
                    $variant_stock = $product_summed_qty;
                }

              
                $vid = $wpdb->get_var("SELECT `id` FROM $rental_variant_relations WHERE `rental_id` = $var->id AND `rental_division_id` = $var->division_id");
                $pid = $wpdb->get_var("SELECT `id` FROM $rental_product_relations WHERE `rental_id` = $var->product_id AND `rental_division_id` = $var->division_id");
                
                if ($variant_has_duplicates && $var->division_id != get_option('rental_location_based_duplicate_filter_division_id', 0)) {
                   
                    if ($vid) {
                        $duplicate_product_ids[] = $vid;

                        // rental_set_product_catalog_visibility($vid, 'hidden');
                        update_post_meta($vid, '_rental_is_add_on', 1);
                    }
                }


                if ($vid) {
                    update_post_meta($vid, '_rental_is_duplicate', $variant_has_duplicates);
                
                    update_post_meta($vid, '_stock', $variant_stock);
        
                    $wpdb->update( $wpdb->wc_product_meta_lookup, [ 
                        'stock_quantity' => $variant_stock
                    ], ['product_id' => $vid] );
                }
    

                if ($pid) {

                    $variant_pids[] = $pid; 

                    update_post_meta($pid, '_stock', $variant_stock);
    
                    $wpdb->update( $wpdb->wc_product_meta_lookup, [ 
                        'stock_quantity' => $variant_stock
                    ], ['product_id' => $pid] );
                }

                
                if ($counter === $total_variants) {

                    if (count($variant_pids) > 1) {

                        foreach($variant_pids as $pid) {

                            // if the variants count are more than 1 and it is the last iteration 
                            // so update the parent product dimensions to empty values
                            if (count($variant_ids_without_division) > 1) {
                                update_post_meta($pid, '_weight', '');
                                update_post_meta($pid, '_length', '');
                                update_post_meta($pid, '_width', '');
                                update_post_meta($pid, '_height', '');
                                update_post_meta($pid, '_depth', '');
                            }

                            // if the variants count are less than 2 and it is the last iteration 
                            // so update the parent product dimensions to variant dimensions
                            if (count($variant_ids_without_division) < 2) {
                                update_post_meta($pid, '_weight', $var->weight);
                                update_post_meta($pid, '_length', $var->length);
                                update_post_meta($pid, '_width', $var->width);
                                update_post_meta($pid, '_height', $var->height);
                                update_post_meta($pid, '_depth', isset($var->depth) ? $var->depth : "");
                            }
                        }

                    } else {

                        // if the variants count are more than 1 and it is the last iteration 
                        // so update the parent product dimensions to empty values
                        if (count($variant_ids_without_division) > 1) {
                            update_post_meta($pid, '_weight', '');
                            update_post_meta($pid, '_length', '');
                            update_post_meta($pid, '_width', '');
                            update_post_meta($pid, '_height', '');
                            update_post_meta($pid, '_depth', '');
                        }

                        // if the variants count are less than 2 and it is the last iteration 
                        // so update the parent product dimensions to variant dimensions
                        if (count($variant_ids_without_division) < 2) {
                            update_post_meta($pid, '_weight', $var->weight);
                            update_post_meta($pid, '_length', $var->length);
                            update_post_meta($pid, '_width', $var->width);
                            update_post_meta($pid, '_height', $var->height);
                            update_post_meta($pid, '_depth', isset($var->depth) ? $var->depth : "");
                        }
                    }
                    
                }
            }
            
        }
    }

    
    $do_not_index_hidden_duplicate_products_for_seo = get_option('rental_do_not_index_hidden_duplicate_products_for_seo', 0);
    if ($do_not_index_hidden_duplicate_products_for_seo && get_option('rental_location_based_duplicate_filter', 0) ) {

        foreach($duplicate_product_ids as $duplicate_product_id) {
            // rental_set_product_general_visibility($duplicate_product_id, 'private');

            // rental_set_product_catalog_visibility($duplicate_product_id, 'hidden');
            // update_post_meta($duplicate_product_id, '_rental_is_add_on', 1);

            toggle_index_yoast_seo($duplicate_product_id);
        }
    }

    $stock_status_despite_quantity = !get_option('rental_inactive_inv_by_quantity');
    $stock_status = $variant->inventory && ($stock_status_despite_quantity || $variant_stock)? 'instock': 'outofstock';
    $tax_status = $variant->taxable? 'taxable': 'none';
	$variation_gallery = "";
	if ( !empty($variant->images)) {
		foreach ($variant->images as $gallery_img_id) {
			if ($gallery_img_id != $variant->img_id) {
				$wp_img_id = $wpdb->get_var("SELECT `id` FROM $rental_image_relations WHERE `rental_id` = $gallery_img_id");
				if (!$wp_img_id) {
					require_once("includes/models/RTImage.php");
					$downloaded = (new RTImage())->getImages([$gallery_img_id]);
					$wp_img_id = isset($downloaded[$gallery_img_id]) ? $downloaded[$gallery_img_id] : null;
				}
				if ($wp_img_id) {
					$variation_gallery .= $variation_gallery ? ",$wp_img_id" : $wp_img_id;
				}
			}
		}
	}

    $price = $variant->rental_price;
    $sale_price = '';
    if ($variant->sale_price > 0) {
        $price = $sale_price = $variant->sale_price;
    }

    $price_multiplier_id = empty($variant->price_multiplier_id) ? 0 : $variant->price_multiplier_id;

    // rental by interval/rental by time slot data
    $rental_interval = empty($variant->rental_interval) ? 0 : $variant->rental_interval;
    $interval_steps = empty($variant->interval_steps) ? 0 : $variant->interval_steps;
    $rental_time_slots = empty($variant->rental_time_slots) ? "" : $variant->rental_time_slots;

    // update product variant meta
    update_post_meta($id, '_variation_description', $variant->description);
    update_post_meta($id, '_sku', $variant->sku);
    update_post_meta($id, '_regular_price', $variant->rental_price);
    update_post_meta($id, '_sale_price', $sale_price);
    update_post_meta($id, '_job_cost', $variant->job_cost? $variant->job_cost: 0);
    update_post_meta($id, '_price_multiplier_id', $price_multiplier_id);
    update_post_meta($id, '_stock', $variant_stock);
    update_post_meta($id, '_stock_status', $stock_status);
    update_post_meta($id, '_tax_status', $tax_status);
    update_post_meta($id, '_weight', $variant->weight);
    update_post_meta($id, '_length', $variant->length);
    update_post_meta($id, '_width', $variant->width);
    update_post_meta($id, '_height', $variant->height);
    update_post_meta($id, '_depth', isset($variant->depth) ? $variant->depth : "");
    update_post_meta($id, '_price', $price);
    update_post_meta($id, '_rental_inventory_id', $variant->inventory_id);
    update_post_meta($id, 'zoo-cw-variation-gallery', $variation_gallery);

    // rental by interval/rental by time slot data
    update_post_meta($id, '_rental_by_interval', $variant->rental_by_interval);
    update_post_meta($id, '_rental_by_slot', $variant->rental_by_slot);
    update_post_meta($id, '_rental_interval', $rental_interval);
    update_post_meta($id, '_rental_interval_steps', $interval_steps);
    update_post_meta($id, '_rental_interval_price', $variant->rental_interval_price);
    update_post_meta($id, '_rental_interval_sale_price', $variant->rental_interval_sale_price);
    update_post_meta($id, '_rental_additional_hourly_price', $variant->additional_hourly_price);
    update_post_meta($id, '_rental_time_slots', $rental_time_slots);

    // Resolve variant thumbnail with fallback download if image not yet synced
    $variant_thumbnail_id = "";
    if ($variant->img_id) {
        $resolved_thumb = $wpdb->get_var("SELECT `id` FROM $rental_image_relations WHERE `rental_id` = $variant->img_id");
        if ($resolved_thumb) {
            $variant_thumbnail_id = $resolved_thumb;
        } else {
            require_once("includes/models/RTImage.php");
            $downloaded = (new RTImage())->getImages([$variant->img_id]);
            if (isset($downloaded[$variant->img_id])) {
                $variant_thumbnail_id = $downloaded[$variant->img_id];
            }
        }
    }
    update_post_meta($id, '_thumbnail_id', $variant_thumbnail_id);

    // update_post_meta($id, '_rental_is_duplicate', $variant_has_duplicates);

    if (isset($wpdb->wc_product_meta_lookup)) {
        $on_sale = $sale_price? 1: 0;
        $wpdb->query("UPDATE `$wpdb->wc_product_meta_lookup` SET " .
            "`sku` = '" . addslashes($variant->sku) . "', " .
            "`min_price` = $price, " .
            "`max_price` = $price, " .
            "`onsale` = $on_sale, " .
            "`stock_quantity` = $variant_stock, " .
            "`stock_status` = '$stock_status', " .
            "`tax_status` = '$tax_status' " .
            "WHERE `product_id` = $id");

        if ($id != $product_id) {
            $product_meta_lookup = $wpdb->get_row("SELECT `min_price`, `max_price` FROM `$wpdb->wc_product_meta_lookup` WHERE `product_id` = $product_id");
            if ($product_meta_lookup) {
                if ($price < $product_meta_lookup->min_price) {
                    $wpdb->query("UPDATE `$wpdb->wc_product_meta_lookup` SET `min_price` = $price WHERE `product_id` = $product_id");
                } elseif ($price > $product_meta_lookup->max_price) {
                    $wpdb->query("UPDATE `$wpdb->wc_product_meta_lookup` SET `max_price` = $price WHERE `product_id` = $product_id");
                }
            }
        }

        if ($wpdb->last_error !== '') {
            http_response_code(400);
            echo json_encode(["error" => "SQL_ERROR: $wpdb->last_error (SQL: $wpdb->last_query)"]);
            throw new RentalException("SQL_ERROR: $wpdb->last_error (SQL: $wpdb->last_query)");
        }
    }

    // delete product cache
    wc_delete_product_transients($product_id);

            
    if ( class_exists( 'WooCommerce_Single_Variations' ) && defined('WC_SINGLE_VAR_VERSION')) {
        $admin_wcs_variation = new WooCommerce_Single_Variations_Admin('plugin', WC_SINGLE_VAR_VERSION);
        $admin_wcs_variation->init();
        // $admin_wcs_variation->update_variations(false);
        $admin_wcs_variation->update_variations(false, true);
        $admin_wcs_variation->reset_transients(false);
    }

    rental_clear_cache();

    // Polylang: ensure variant/product retains default language
    rental_pll_assign_post( (int) $id );
    rental_translation_queue_post( (int) $id );


    http_response_code(200);
    echo json_encode([
        "product or variant" => [
            "rental_id" => $variant->id,
            "id" => $id,
        ],
        "message" => "Product or variant successfully updated!"
    ]);
    return;
}

function rentopian_variant_delete($variant) {
    global $wpdb, $rental_tables;
    $rental_variant_relations = $wpdb->prefix . $rental_tables["variant_relations"];

    $variant = json_decode(stripslashes($variant));
    if ( !$variant) {
        http_response_code(400);
        echo json_encode(["error" => "Variant is empty."]);
        return;
    }

    $id = $wpdb->get_var("SELECT `id` FROM $rental_variant_relations WHERE `rental_id` = $variant->id AND `rental_division_id` = $variant->division_id");
    if ( !$id) {
        http_response_code(404);
        echo json_encode(["error" => "Variant with rental_id $variant->id not found."]);
        return;
    }

    wp_update_post(['ID' => $id, 'post_status' => 'trash',]);

    // delete product cache
    if ($product_id = wp_get_post_parent_id($id)) {
        $product_attributes = get_post_meta($product_id, '_product_attributes', true);
        $variants = [];

        if ($product_attributes) {
            foreach ($product_attributes as $attribute) {
                $taxonomy = $attribute['name'];
                $attribute_value_slug = get_post_meta($id, "attribute_$taxonomy", true);
                if ($attribute_value_slug) {
                    if (empty($variants)) {
                        $variants = $wpdb->get_col("SELECT `ID` FROM `$wpdb->posts` WHERE `post_parent` = $product_id AND `post_type` = 'product_variation' AND `post_status` != 'trash'");
                    }
                    rentopian_detach_attribute_value_from_product($product_id, $taxonomy, $attribute_value_slug, $variants);
                }
            }
        }
        wc_delete_product_transients($product_id);

        // converting variant to simple product
        if (count($variants) === 1) {
            $remaining_variant_id = $variants[0];
            // wp_update_post(['ID' => $remaining_variant_id, 'post_parent' => 0, 'post_type' => 'product', 'post_status' => 'trash']);
            wp_update_post(['ID' => $remaining_variant_id, 'post_status' => 'trash']);
            // $wpdb->query("DELETE FROM $wpdb->postmeta WHERE `meta_key` LIKE 'attribute_%' AND `post_id` = $remaining_variant_id");
            $wpdb->query("DELETE FROM $wpdb->postmeta WHERE `post_id` = $product_id");
            $replacement_post_meta_data = $wpdb->get_results("SELECT * FROM $wpdb->postmeta WHERE `post_id` = $remaining_variant_id");
            
            $description = $_sku = $_regular_price = $_job_cost = $_price_multiplier_id = $_sale_price = $_tax_status = $_weight = $_length = $_width = $_height = $_depth = "";
            $_stock = $_stock_status = $_price = $_rental_inventory_id = $_thumbnail_id = $_rental_by_slot = $_rental_by_interval = $_rental_interval = $_rental_interval_steps = "";
            $_rental_interval_price = $_rental_interval_sale_price = $_rental_additional_hourly_price = $_rental_time_slots = "";
            foreach($replacement_post_meta_data as $post_meta_data) {
                if ($post_meta_data->meta_key == '_variation_description') {
                    $description = $post_meta_data->meta_value;
                }
                if ($post_meta_data->meta_key == '_sku') {
                    $_sku = $post_meta_data->meta_value;
                }
                if ($post_meta_data->meta_key == '_regular_price') {
                    $_regular_price = $post_meta_data->meta_value;
                }
                if ($post_meta_data->meta_key == '_job_cost') {
                    $_job_cost = $post_meta_data->meta_value;
                }
                if ($post_meta_data->meta_key == '_price_multiplier_id') {
                    $_price_multiplier_id = $post_meta_data->meta_value;
                }
                if ($post_meta_data->meta_key == '_sale_price') {
                    $_sale_price = $post_meta_data->meta_value;
                }
                if ($post_meta_data->meta_key == '_tax_status') {
                    $_tax_status = $post_meta_data->meta_value;
                }
                if ($post_meta_data->meta_key == '_weight') {
                    $_weight = $post_meta_data->meta_value;
                }
                if ($post_meta_data->meta_key == '_length') {
                    $_length = $post_meta_data->meta_value;
                }
                if ($post_meta_data->meta_key == '_width') {
                    $_width = $post_meta_data->meta_value;
                }
                if ($post_meta_data->meta_key == '_height') {
                    $_height = $post_meta_data->meta_value;
                }
                if ($post_meta_data->meta_key == '_depth') {
                    $_depth = $post_meta_data->meta_value;
                }
                
                if ($post_meta_data->meta_key == '_stock') {
                    $_stock = $post_meta_data->meta_value;
                }
                if ($post_meta_data->meta_key == '_stock_status') {
                    $_stock_status = $post_meta_data->meta_value;
                }
                if ($post_meta_data->meta_key == '_price') {
                    $_price = $post_meta_data->meta_value;
                }
                if ($post_meta_data->meta_key == '_rental_inventory_id') {
                    $_rental_inventory_id = $post_meta_data->meta_value;
                }
                if ($post_meta_data->meta_key == '_thumbnail_id') {
                    $_thumbnail_id = $post_meta_data->meta_value;
                }
                if ($post_meta_data->meta_key == '_rental_by_interval') {
                    $_rental_by_interval = $post_meta_data->meta_value;
                }
                if ($post_meta_data->meta_key == '_rental_by_slot') {
                    $_rental_by_slot = $post_meta_data->meta_value;
                }
                if ($post_meta_data->meta_key == '_rental_interval') {
                    $_rental_interval = $post_meta_data->meta_value;
                }
                if ($post_meta_data->meta_key == '_rental_interval_steps') {
                    $_rental_interval_steps = $post_meta_data->meta_value;
                }
                if ($post_meta_data->meta_key == '_rental_interval_price') {
                    $_rental_interval_price = $post_meta_data->meta_value;
                }
                if ($post_meta_data->meta_key == '_rental_interval_sale_price') {
                    $_rental_interval_sale_price = $post_meta_data->meta_value;
                }
                if ($post_meta_data->meta_key == '_rental_additional_hourly_price') {
                    $_rental_additional_hourly_price = $post_meta_data->meta_value;
                }
                if ($post_meta_data->meta_key == '_rental_time_slots') {
                    $_rental_time_slots = $post_meta_data->meta_value;
                }
            }

            $replacement_meta_sql = "($product_id, '_variation_description', '" . $description . "'),
                ($product_id, '_wc_rating_count', 'a:0:{}'),
                ($product_id, '_wc_review_count', '0'),
                ($product_id, '_wc_average_rating', '0'),
                ($product_id, '_sku', '$_sku'),
                ($product_id, '_regular_price', '$_regular_price'),
                ($product_id, '_job_cost', '$_job_cost'),
                ($product_id, '_price_multiplier_id', '$_price_multiplier_id'),
                ($product_id, '_sale_price', '$_sale_price'),
                ($product_id, '_sale_price_dates_from', ''),
                ($product_id, '_sale_price_dates_to', ''),
                ($product_id, 'total_sales', '0'),
                ($product_id, '_tax_status', '$_tax_status'),
                ($product_id, '_tax_class', '' ),
                ($product_id, '_manage_stock', 'no' ),
                ($product_id, '_backorders', 'yes' ),
                ($product_id, '_sold_individually', 'no' ),
                ($product_id, '_weight', '$_weight'),
                ($product_id, '_length', '$_length'),
                ($product_id, '_width', '$_width'),
                ($product_id, '_height', '$_height'),
                ($product_id, '_depth', '$_depth'),
                ($product_id, '_upsell_ids', 'a:0:{}' ),
                ($product_id, '_crosssell_ids', 'a:0:{}' ),
                ($product_id, '_purchase_note', '' ),
                ($product_id, '_virtual', 'no' ),
                ($product_id, '_downloadable', 'no' ),
                ($product_id, '_product_image_gallery', '' ),
                ($product_id, '_download_limit', '-1' ),
                ($product_id, '_download_expiry', '-1' ),
                ($product_id, '_stock', '$_stock' ),
                ($product_id, '_stock_status', '$_stock_status'),
                ($product_id, '_product_version', '3.2.3'),
                ($product_id, '_price', '$_price'),
                ($product_id, '_rental_inventory_id', '$_rental_inventory_id'),
                ($product_id, '_thumbnail_id', '$_thumbnail_id'),
                ($product_id, '_rental_by_interval', '$_rental_by_interval'),
                ($product_id, '_rental_by_slot', '$_rental_by_slot'),
                ($product_id, '_rental_interval', '$_rental_interval'),
                ($product_id, '_rental_interval_steps', '$_rental_interval_steps'),
                ($product_id, '_rental_interval_price', '$_rental_interval_price'),
                ($product_id, '_rental_interval_sale_price', '$_rental_interval_sale_price'),
                ($product_id, '_rental_additional_hourly_price', '$_rental_additional_hourly_price'),
                ($product_id, '_rental_time_slots', '$_rental_time_slots'),
                ($product_id, '_downloadable_files', 'a:0:{}')";

            $wpdb->query("INSERT INTO `$wpdb->postmeta` (`post_id`, `meta_key`, `meta_value`)
                VALUES $replacement_meta_sql");

            add_post_meta($product_id, '_rental_remaining_variant_id', $remaining_variant_id);
            $simple = $wpdb->get_var("SELECT `term_id` FROM $wpdb->terms WHERE `name` = 'simple' AND `slug` = 'simple'");
            $wpdb->query("UPDATE $wpdb->term_relationships SET term_taxonomy_id = $simple WHERE object_id = $product_id limit 1");
        }
    }

    if ( class_exists( 'WooCommerce_Single_Variations' ) && defined('WC_SINGLE_VAR_VERSION')) {
        $admin_wcs_variation = new WooCommerce_Single_Variations_Admin('plugin', WC_SINGLE_VAR_VERSION);
        $admin_wcs_variation->init();
        // $admin_wcs_variation->update_variations(false);
        $admin_wcs_variation->update_variations(false, true);
        $admin_wcs_variation->reset_transients(false);
    }

    rental_clear_cache();

    http_response_code(200);
    echo json_encode([
        "product" => [
            "rental_id" => $variant->id,
            "id" => $id,
        ],
        "message" => "Product variant deleted successfully!"
    ]);
    return;
}

function rentopian_detach_attribute_value_from_product($product_id, $taxonomy, $attribute_value_slug, $variants) {
    global $wpdb;

    if ($variants) {
        $variants = implode(",", $variants);
        $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM `$wpdb->postmeta` WHERE `post_id` IN ($variants) AND `meta_key` = 'attribute_$taxonomy' AND `meta_value` = '$attribute_value_slug'");
        if ($count) {
            return false;
        }
    }
    return wp_remove_object_terms($product_id, [$attribute_value_slug], $taxonomy);
}


function rentopian_update_items_qty($items) {
    $items = json_decode(stripslashes($items));
    if ( !$items) {
        http_response_code(400);
        echo json_encode(["error" => "Items list is empty."]);
        return;
    }

    global $wpdb, $rental_tables;
    $rental_product_relations = $wpdb->prefix . $rental_tables["product_relations"];
    $rental_variant_relations = $wpdb->prefix . $rental_tables["variant_relations"];

    foreach($items as $item) {

        $product_id = $wpdb->get_var("SELECT `id` FROM $rental_product_relations WHERE `rental_id` = $item->product_id AND `rental_division_id` = $item->division_id");
        $variant_id = $wpdb->get_var("SELECT `id` FROM $rental_variant_relations WHERE `rental_id` = $item->product_variant_id AND `rental_division_id` = $item->division_id");

        if ($variant_id) {

            $product_variant_stock_quantity = get_post_meta($variant_id, '_stock', true);
            update_post_meta($variant_id, '_stock', $product_variant_stock_quantity - (int) $item->unrepair_qty);

            $wpdb->update( $wpdb->wc_product_meta_lookup, [ 
                'stock_quantity' => $product_variant_stock_quantity - (int) $item->unrepair_qty
            ], ['product_id' => $variant_id] );
        }

        if ($product_id) {

            $product_stock_quantity = get_post_meta($product_id, '_stock', true);
            update_post_meta($product_id, '_stock', $product_stock_quantity - $item->unrepair_qty);

            $wpdb->update( $wpdb->wc_product_meta_lookup, [ 
                'stock_quantity' => $product_stock_quantity - $item->unrepair_qty
            ], ['product_id' => $product_id] );
        }
       
    }

    http_response_code(200);
    echo json_encode([
        "items" => $items,
        "message" => "Items quantity successfully updated!"
    ]);
    return;
}
/*
function rentopian_variant_image_create($image) {
    global $wpdb, $rental_tables;
    $rental_product_relations = $wpdb->prefix . $rental_tables["product_relations"];
    $rental_image_relations = $wpdb->prefix . $rental_tables["image_relations"];

    $image = json_decode(stripslashes($image));
    if ( !$image) {
        http_response_code(400);
        echo json_encode(["error" => "Image is empty."]);
        return;
    }

    $id = $wpdb->get_var("SELECT `id` FROM $rental_product_relations WHERE `rental_id` = $image->product_id");
    if ( !$id) {
        http_response_code(404);
        echo json_encode(["error" => "Product with rental_id $image->product_id not found."]);
        return;
    }

    $upload = wp_upload_bits($image->filename, null, base64_decode($image->img_content));

    $attachment = ['guid' => $upload['url'], 'post_mime_type' => $image->mime, 'post_title' => $image->label,
        'post_content' => $image->description? $image->description: "", 'post_status' => 'inherit'];

    $attach_id = wp_insert_attachment($attachment, $upload['file'], $id);

    require_once(ABSPATH . 'wp-admin/includes/image.php');

    $attach_data = wp_generate_attachment_metadata($attach_id, $upload['file']);
    wp_update_attachment_metadata($attach_id, $attach_data);

    $gallery = get_post_meta($id, '_product_image_gallery', true);
    if ($gallery) {
        $gallery .= ',' . $attach_id;
    } else {
        $gallery .= '' . $attach_id;
    }
    update_post_meta($id, '_product_image_gallery', $gallery);

    $wpdb->query("INSERT INTO `$rental_image_relations` (`id`, `rental_id`) VALUES ($attach_id, $image->id);");

    http_response_code(200);
    echo json_encode([
        "image" => [
            "rental_id" => $image->id,
            "id" => $attach_id,
            "product_id" => $id,
            "gallery" => $gallery,
        ],
        "message" => "Product variant image successfully created!"
    ]);
    return;
}

function rentopian_variant_image_delete($image) {
    global $wpdb, $rental_tables;
    $rental_product_relations = $wpdb->prefix . $rental_tables["product_relations"];
    $rental_variant_relations = $wpdb->prefix . $rental_tables["variant_relations"];
    $rental_image_relations = $wpdb->prefix . $rental_tables["image_relations"];

    $image = json_decode(stripslashes($image));
    if ( !$image) {
        http_response_code(400);
        echo json_encode(["error" => "Image is empty."]);
        return;
    }

    $img_id = $wpdb->get_var("SELECT `id` FROM $rental_image_relations WHERE `rental_id` = $image->img_id");
    if ( !$img_id) {
        http_response_code(404);
        echo json_encode(["error" => "Image with rental_id $image->img_id not found."]);
        return;
    }

    $product_id = $wpdb->get_var("SELECT `id` FROM $rental_product_relations WHERE `rental_id` = $image->product_id AND `rental_division_id` = $image->division_id");
    if ( !$product_id) {
        http_response_code(404);
        echo json_encode(["error" => "Product with rental_id $image->product_id not found."]);
        return;
    }

    $gallery = implode(',', array_diff(explode(',', get_post_meta($product_id, '_product_image_gallery', true)), [$img_id]));
    update_post_meta($product_id, '_product_image_gallery', $gallery);

    $variant_id = $wpdb->get_var("SELECT `id` FROM $rental_variant_relations WHERE `rental_id` = $image->variant_id AND `rental_division_id` = $image->division_id");
    if ($variant_id) {
        if (get_post_meta($variant_id, '_thumbnail_id', true) == $img_id) {
            update_post_meta($variant_id, '_thumbnail_id', '');
        }
    }

    http_response_code(200);
    echo json_encode([
        "image" => [
            "rental_id" => $image->img_id,
            "id" => $img_id,
            "product_id" => $product_id,
            "gallery" => $gallery,
        ],
        "message" => "Product variant image successfully deleted!"
    ]);
    return;
}
*/

// Set
function rentopian_set_create($set) {

    // Webhook sets unification — delegate to the sets module when
    // available.

    if ( class_exists( 'Rental_Sets_Webhook_Handler', false ) ) {
        Rental_Sets_Webhook_Handler::create( $set );
        return;
    }

    global $wpdb, $rental_tables;
    $rental_product_relations = $wpdb->prefix . $rental_tables["product_relations"];
    $rental_variant_relations = $wpdb->prefix . $rental_tables["variant_relations"];
    $rental_set_relations = $wpdb->prefix . $rental_tables["set_relations"];
    $rental_image_relations = $wpdb->prefix . $rental_tables["image_relations"];

    $set = json_decode(stripslashes($set));

    if (!$set) {
        http_response_code(400);
        echo json_encode(["error" => "Set is empty."]);
        return;
    }

    // getting full/short description
    $full_description = isset($set->full_description) && !empty($set->full_description) ? $set->full_description : '';
    $description = isset($set->description) && !empty($set->description) ? $set->description : '';

    $set_id = $wpdb->get_var("SELECT `id` FROM $rental_set_relations WHERE `rental_id` = $set->id");
    $sql = "";
    if ($set_id) {
        // update set
        
        if (wp_update_post([
            'ID' => $set_id,
            'post_title' => $set->title,
            'post_content' => $full_description,
            'post_excerpt' => $description,
            'post_status' => 'publish',
        ])) {
            $wpdb->query("DELETE FROM $wpdb->term_relationships WHERE `object_id` = $set_id");
            $wpdb->query("DELETE FROM $wpdb->postmeta WHERE `post_id` = $set_id");
            $wpdb->query("UPDATE `$wpdb->posts` SET `post_status` = 'trash' WHERE `post_parent` =  $set_id AND `post_type` = 'product_variation' AND `post_status` = 'publish'");
            if (isset($wpdb->wc_product_meta_lookup)) {
                $wpdb->query("DELETE FROM $wpdb->wc_product_meta_lookup WHERE `product_id` = $set_id");
            }

            // update rental_set_relations rental_division_id column
            $sql .= "UPDATE `$rental_set_relations` SET `rental_division_id` = $set->division_id WHERE `rental_id` = $set->id;";

            // delete product cache
            wc_delete_product_transients($set_id);
        } else {
            $wpdb->query("DELETE FROM $rental_set_relations WHERE `rental_id` = $set->id");
            $set_id = 0;
        }
    }
    if ( !$set_id) {
    // insert set
        $set_id = wp_insert_post([
            'post_author' => '1',
            'post_title' => $set->title,
            'post_content' => $full_description,
            'post_excerpt' => $description,
            'post_status' => 'publish',
            'comment_status' => 'open',
            'post_type' => 'product',
        ]);
        $sql .= "INSERT INTO `$rental_set_relations` (`id`, `rental_id`, `rental_division_id`) VALUES ($set_id, $set->id, $set->division_id);";
    }



    $set_image_gallery = [];
    $thumbnail_id = "";
    $image_relations_sql = [];

    if ($set->images) {

        $get_images = [];

        foreach ($set->images as $img) {

            $img_id = $wpdb->get_var("SELECT `id` FROM $rental_image_relations WHERE `rental_id` = $img->id");
            
            if ($img_id) {
                if ($img->id == $set->img_id) {
                    $thumbnail_id = $img_id;
                } else {
                    $set_image_gallery[] = $img_id;
                }

            } else {

                $get_images[] = $img->id;
            }
        }

        if ( $get_images) {
            $api_key = get_option('rental_api_key');

            // $new_images = rental_curl('files/images', $api_key, true, ['images' => json_encode($get_images)]);

            try {
                $new_images = rental_curl('files/images/stream', $api_key, true, ['images' => json_encode($get_images)]);
            } catch (RentalException $e) {

                ErrorHandler::registerErrorInLog(
                    "API fetch failed in product create webhook: " . $e->getMessage(),
                    __FILE__, __LINE__,
                    $e->getType(),   // preserve type
                    null,
                    $e->getStatusCode()
                );
                throw $e;
            }

            // upload set images via centralized helper
            foreach ($new_images as $image) {

                if ( !$image->url) {
                    continue;
                }

                $attach_id = rentopian_webhook_download_image(
                    $image->url,
                    $image->id,
                    $image,
                    $set_id
                );

                if ( !$attach_id) {
                    continue;
                }

                $image_relations_sql[] = "($attach_id, $image->id)";

                if ( !$thumbnail_id && $image->id == $set->img_id) {
                    $thumbnail_id = $attach_id;
                } else {
                    $set_image_gallery[] = $attach_id;
                }
            }
            
        }
    }

    $set_image_gallery = empty($set_image_gallery)? "": implode(",", $set_image_gallery);


    /*

    $thumbnail_id = '';
    if ($set->img_id) {
        require_once("includes/models/RTImage.php");
        $images = (new RTImage())->getImages([$set->img_id]);
        if (isset($images[$set->img_id])) {
            $thumbnail_id = $images[$set->img_id];
        }
    }

    */

    $set_items = [];
    $set_items_have_optional_items = false;
    $set_items_have_some_hidden_items = false;
    $items = json_decode($set->items);
    if ($items) {
        foreach ($items as $item) {
            $product_id = $wpdb->get_var("SELECT `id` FROM $rental_product_relations WHERE `rental_id` = $item->product_id AND `rental_division_id` = $item->division_id");
            
            if ($product_id) {

                $optional_items = [];
                if (isset($item->optional_items)) {
                    foreach ($item->optional_items as $optional_item) {
    
                        $optional_items[] = [
                            'product_id' => $product_id,
                            'variant_id' => $wpdb->get_var("SELECT `id` FROM $rental_variant_relations WHERE `rental_id` = $optional_item->variant_id AND `rental_division_id` = $optional_item->division_id"),
                            'quantity' => $optional_item->quantity,
                            'is_selected' => isset($optional_item->is_selected) && $optional_item->is_selected ? 1 : 0,
                        ];
                    }

                    if ($optional_items) {
                        $set_items_have_optional_items = true;
                    }
                }

                
                $set_item_addons = [];
                if (isset($item->addons) && $item->addons) {

                    foreach ($item->addons as $item_addon) {

                        $addon_variants_optional = [];
                        if (isset($item_addon->variants_optional) && $item_addon->variants_optional) {

                            foreach ($item_addon->variants_optional as $addon_variant) {
                                
                                $optional_variant_product_id = $wpdb->get_var("SELECT `id` FROM $rental_product_relations WHERE `rental_id` = $addon_variant->product_id AND `rental_division_id` = $addon_variant->division_id");
                                $optional_variant_variant_id = $wpdb->get_var("SELECT `id` FROM $rental_variant_relations WHERE `rental_id` = $addon_variant->variant_id AND `rental_division_id` = $addon_variant->division_id");

                                $addon_variants_optional[] = [
                                    'product_id' => $optional_variant_product_id ? $optional_variant_product_id : 0,
                                    'rental_product_id' => $addon_variant->product_id,
                                    'variant_id' => $optional_variant_variant_id ? $optional_variant_variant_id : 0,
                                    'rental_variant_id' => $addon_variant->variant_id,
                                    'quantity' => $addon_variant->quantity,
                                    'default' => $addon_variant->default,
                                ];
                            }
                        }


                        $add_on_id = $wpdb->get_var("SELECT `id` FROM $rental_product_relations WHERE `rental_id` = $item_addon->product_id AND `rental_division_id` = $item_addon->division_id");
                        $add_on_variant_id = $wpdb->get_var("SELECT `id` FROM $rental_variant_relations WHERE `rental_id` = $item_addon->add_on_variant_id AND `rental_division_id` = $item_addon->division_id");

                        $set_item_addons[] = [
                            'rental_inv_id' => $item_addon->id,
                            'product_id' => $add_on_id ? $add_on_id : 0,
                            'rental_product_id' => $item_addon->product_id,
                            'variant_id' => $add_on_variant_id ? $add_on_variant_id : 0,
                            'rental_variant_id' => $item_addon->add_on_variant_id,
                            'quantity' => $item_addon->add_on_quantity,
                            'price' => $item_addon->price,
                            'required' => $item_addon->required,
                            'hidden' => isset($item_addon->hidden) ? $item_addon->hidden : 0,
                            'product_price' => isset($item_addon->product_price) ? $item_addon->product_price : 0,
                            'inherit_price' => isset($item_addon->inherit_price) ? $item_addon->inherit_price : 0,
                            'variants_optional' => $addon_variants_optional,
                        ];
                    }


                    // if ($set_item_addons && !get_option('rental_set_items_have_addons', false)) {
                    //     update_option('rental_set_items_have_addons', true);
                    // }
                }

                $set_item_variant_id = isset($item->optional_items) ? 0 : $wpdb->get_var("SELECT `id` FROM $rental_variant_relations WHERE `rental_id` = $item->variant_id AND `rental_division_id` = $item->division_id");
                if (isset($item->has_selected) && $item->has_selected && $item->variant_id) {
                    $set_item_variant_id = $wpdb->get_var("SELECT `id` FROM $rental_variant_relations WHERE `rental_id` = $item->variant_id AND `rental_division_id` = $item->division_id");
                }

                if (isset($item->hidden) && $item->hidden) {
                    $set_items_have_some_hidden_items = true;
                }

                $set_items[] = [
                    'product_id' => $product_id,
                    'variant_id' => $set_item_variant_id,
                    'quantity' => $item->quantity,
                    'optional_items' => $optional_items,
                    'has_selected' => isset($item->has_selected) && $item->has_selected ? 1 : 0,
                    'optional_item_price_update_needed' => isset($item->has_selected) && $item->has_selected ? 1 : 0, // 0: no need to update, 1: needs update, 2: is updated
                    'hidden' => isset($item->hidden) && $item->hidden ? 1 : 0,
                    'addons' => $set_item_addons,
                ];
            }
        }
    }
   

    $sku = addslashes($set->number);
    $tax_status = $set->taxable? 'taxable': 'none';

    $price_multiplier_id = empty($set->price_multiplier_id) ? 0 : $set->price_multiplier_id;

    $hide_items_on_website = isset($set->hide_items_on_website) ? $set->hide_items_on_website : 0;
    
    $set_max_qty = $set->max_quantity ?? '';

    // Add set data to insert in the postmeta table
    $postmeta_sql = "($set_id, '_wc_review_count', '0' ),
        ($set_id, '_wc_rating_count', 'a:0:{}'),
        ($set_id, '_wc_average_rating', '0'),
        ($set_id, '_edit_last', ''),
        ($set_id, '_edit_lock', ''),
        ($set_id, '_sku', '$sku'),
        ($set_id, '_regular_price', '$set->rental_price'),
        ($set_id, '_job_cost', '$set->job_cost'),
        ($set_id, '_price_multiplier_id', '$price_multiplier_id'),
        ($set_id, '_sale_price', '' ),
        ($set_id, '_sale_price_dates_from', '' ),
        ($set_id, '_sale_price_dates_to', '' ),
        ($set_id, 'total_sales', '0' ),
        ($set_id, '_tax_status', '$tax_status'),
        ($set_id, '_tax_class', '' ),
        ($set_id, '_manage_stock', 'no' ),
        ($set_id, '_backorders', 'yes' ),
        ($set_id, '_sold_individually', 'no' ),
        ($set_id, '_weight', '' ),
        ($set_id, '_length', '' ),
        ($set_id, '_width', '' ),
        ($set_id, '_height', '' ),
        ($set_id, '_depth', '' ),
        ($set_id, '_upsell_ids', 'a:0:{}' ),
        ($set_id, '_crosssell_ids', 'a:0:{}' ),
        ($set_id, '_purchase_note', '' ),
        ($set_id, '_default_attributes', 'a:0:{}' ),
        ($set_id, '_virtual', 'no' ),
        ($set_id, '_downloadable', 'no' ),
        ($set_id, '_product_image_gallery', '$set_image_gallery' ),
        ($set_id, '_download_limit', '-1' ),
        ($set_id, '_download_expiry', '-1' ),
        ($set_id, '_stock', '1' ),
        ($set_id, '_stock_status', 'instock'),
        ($set_id, '_product_version', '3.2.3'),
        ($set_id, '_price', '$set->rental_price'),
        ($set_id, 'zoo_cw_product_swatch_data', 'a:0:{}'),
        ($set_id, '_thumbnail_id', '$thumbnail_id'),
        ($set_id, '_rental_exempt_waiver', '$set->exempt_waiver'),
        ($set_id, '_rental_is_sale', '$set->is_sale'),
        ($set_id, '_rental_is_add_on', '0'),
        ($set_id, '_rental_set_items_have_optional_items', '" . $set_items_have_optional_items . "'),
        ($set_id, '_rental_set_items', '" . addslashes(serialize($set_items)) . "'),
        ($set_id, '_rental_set_items_default', '" . addslashes(serialize($set_items)) . "'),
        ($set_id, '_rental_hide_items_on_website', '$hide_items_on_website'),
        ($set_id, '_rental_some_hidden_items', '$set_items_have_some_hidden_items'),
        ($set_id, '_rental_max_quantity', '$set_max_qty'),
        ($set_id, '_rental_is_set', '1')";

    $wpdb->query("INSERT INTO `$wpdb->postmeta` (`post_id`, `meta_key`, `meta_value`)
        VALUES $postmeta_sql");

    $simple = $wpdb->get_var("SELECT `term_id` FROM $wpdb->terms WHERE `name` = 'simple' AND `slug` = 'simple'");
    $sql .= "INSERT INTO `$wpdb->term_relationships` (`object_id`, `term_taxonomy_id`, `term_order`)
              VALUES ($set_id, $simple, 0);";

    if (isset($wpdb->wc_product_meta_lookup)) {
        $wpdb->query("INSERT INTO `$wpdb->wc_product_meta_lookup` (`product_id`, `sku`, `virtual`, `downloadable`, " .
            "`min_price`, `max_price`, `onsale`, `stock_quantity`, `stock_status`, `tax_status`) " .
            "VALUES ($set_id, '$sku', 0, 0, $set->rental_price, $set->rental_price, 0, 1, 'instock', '$tax_status')");
    }

    if ( !empty($image_relations_sql)) {
        $image_relations_sql = implode(", ", $image_relations_sql);
        $sql .= "INSERT INTO `$rental_image_relations` (`id`, `rental_id`) VALUES $image_relations_sql;";
    }

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);

    $cats = [];
    if ($set->categories) {
        $cats = rental_create_categories($set->categories, $set_id);
    }

    $tags = [];
    if ($set->tags) {
        $product_tags = isset($set->product_tags) ? $set->product_tags : [];
        $tags = rental_create_set_tags($set->tags, $set_id, $product_tags);
    }

    $set_upsells_sets = isset($set->up_sells_sets) && $set->up_sells_sets ? $set->up_sells_sets : [];
    $set_up_sells_products = isset($set->up_sells_products) && $set->up_sells_products ? $set->up_sells_products : [];
    $set_cross_sells_sets = isset($set->cross_sells_sets) && $set->cross_sells_sets ? $set->cross_sells_sets : [];
    $set_cross_sells_products = isset($set->cross_sells_products) && $set->cross_sells_products ? $set->cross_sells_products : [];

    set_sets_up_sells($set_id, $set_upsells_sets, $set_up_sells_products);
    set_sets_cross_sells($set_id, $set_cross_sells_sets, $set_cross_sells_products);

    if ($wpdb->last_error !== '') {
        $error = "SQL_ERROR: $wpdb->last_error (SQL: $wpdb->last_query)";
        http_response_code(400);
        echo json_encode(["error" => $error]);
        throw new RentalException($error);
    }

    rental_clear_cache();

    // Polylang: assign default language to new set
    rental_pll_assign_post( (int) $set_id );
    rental_translation_queue_post( (int) $set_id );

    http_response_code(200);
    echo json_encode([
        "set" => [
            "rental_id" => $set->id,
            "id" => $set_id,
            "image" => $thumbnail_id,
            "items" => $set_items,
            "categories" => $cats,
            "tags" => $tags,
        ],
        "message" => "Set successfully created!"
    ]);
    return;
}

function set_products_up_sells_cross_sells($wp_product_id, $up_sells, $cross_sells) {
    global $wpdb, $rental_tables;
    $rental_product_relations = $wpdb->prefix . $rental_tables["product_relations"];
    $rental_variant_relations = $wpdb->prefix . $rental_tables["variant_relations"];
    
    $wp_up_sells_pack = [];
    if ($up_sells) {

        $placeholders_up_sells = array_fill(0, count($up_sells), '%d');
        $placeholders_up_sells_format = implode(', ', $placeholders_up_sells);
        
        $sql = "
            SELECT 
                id
            FROM 
                {$rental_product_relations} products
            WHERE
                rental_id IN ({$placeholders_up_sells_format})
        ";
        $wp_up_sells_prods = $wpdb->get_results($wpdb->prepare($sql, $up_sells), ARRAY_A);

        $sql = "
            SELECT 
                id
            FROM 
                {$rental_variant_relations} variants
            WHERE
                rental_id IN ({$placeholders_up_sells_format})
        ";
        $wp_up_sells_vars = $wpdb->get_results($wpdb->prepare($sql, $up_sells), ARRAY_A);

        $wp_up_sells = array_merge($wp_up_sells_prods, $wp_up_sells_vars);

        if ($wp_up_sells) {
            $wp_up_sells_pack = array_unique(array_column($wp_up_sells, 'id'));
        }
    }

    $wp_cross_sells_pack = [];
    if ($cross_sells) {
        $placeholders_cross_sells = array_fill(0, count($cross_sells), '%d');
        $placeholders_cross_sells_format = implode(', ', $placeholders_cross_sells);
        
        $sql = "
            SELECT 
                id
            FROM 
                {$rental_product_relations} products
            WHERE
                rental_id IN ({$placeholders_cross_sells_format})
        ";
        $wp_cross_sells_prods = $wpdb->get_results($wpdb->prepare($sql, $cross_sells), ARRAY_A);

        $sql = "
            SELECT 
                id
            FROM 
                {$rental_variant_relations} variants
            WHERE
                rental_id IN ({$placeholders_cross_sells_format})
        ";
        $wp_cross_sells_vars = $wpdb->get_results($wpdb->prepare($sql, $cross_sells), ARRAY_A);
        
        $wp_cross_sells = array_merge($wp_cross_sells_prods, $wp_cross_sells_vars);
        
        if ($wp_cross_sells) {
            $wp_cross_sells_pack = array_unique(array_column($wp_cross_sells, 'id'));
        }
    }
    
    wp_set_object_terms($wp_product_id, $wp_up_sells_pack, '_upsell_ids');
    update_post_meta($wp_product_id, '_upsell_ids', $wp_up_sells_pack);

    wp_set_object_terms($wp_product_id, $wp_cross_sells_pack, '_crosssell_ids');
    update_post_meta($wp_product_id, '_crosssell_ids', $wp_cross_sells_pack);
}

function set_sets_up_sells($wp_set_id, $up_sells_sets, $up_sells_products) {
    global $wpdb, $rental_tables;
    $rental_product_relations = $wpdb->prefix . $rental_tables["product_relations"];
    $rental_set_relations = $wpdb->prefix . $rental_tables["set_relations"];

    $wp_up_sells_pack = [];
    if ($up_sells_sets) {
        $placeholders_up_sells_sets = array_fill(0, count($up_sells_sets), '%d');
        $placeholders_up_sells_sets_format = implode(', ', $placeholders_up_sells_sets);
        $sql = "
            SELECT 
                id
            FROM 
                {$rental_set_relations} sets
            WHERE
                rental_id IN ({$placeholders_up_sells_sets_format})
        ";
        $wp_up_sells_sets = $wpdb->get_results($wpdb->prepare($sql, $up_sells_sets), ARRAY_A);

        if ($wp_up_sells_sets) {

            $wp_up_sells_pack = array_unique(array_column($wp_up_sells_sets, 'id'));
        }
    }

    if ($up_sells_products) {
        $placeholders_up_sells_products = array_fill(0, count($up_sells_products), '%d');
        $placeholders_up_sells_products_format = implode(', ', $placeholders_up_sells_products);
        $sql = "
            SELECT 
                id
            FROM 
                {$rental_product_relations} sets
            WHERE
                rental_id IN ({$placeholders_up_sells_products_format})
        ";
        $wp_up_sells_products = $wpdb->get_results($wpdb->prepare($sql, $up_sells_products), ARRAY_A);

        $flattened_wp_up_sells_products = [];
        if ($wp_up_sells_products) {
            $flattened_wp_up_sells_products = array_unique(array_column($wp_up_sells_products, 'id'));
        }

        $wp_up_sells_pack = array_merge($wp_up_sells_pack, $flattened_wp_up_sells_products);
    }

    wp_set_object_terms($wp_set_id, $wp_up_sells_pack, '_upsell_ids');
    update_post_meta($wp_set_id, '_upsell_ids', $wp_up_sells_pack);
}

function set_sets_cross_sells($wp_set_id, $cross_sells_sets, $cross_sells_products) {
    global $wpdb, $rental_tables;
    $rental_product_relations = $wpdb->prefix . $rental_tables["product_relations"];
    $rental_set_relations = $wpdb->prefix . $rental_tables["set_relations"];

    $wp_cross_sells_pack = [];
    if ($cross_sells_sets) {
        $placeholders_cross_sells_sets = array_fill(0, count($cross_sells_sets), '%d');
        $placeholders_cross_sells_sets_format = implode(', ', $placeholders_cross_sells_sets);
        $sql = "
            SELECT 
                id
            FROM 
                {$rental_set_relations} sets
            WHERE
                rental_id IN ({$placeholders_cross_sells_sets_format})
        ";
        $wp_cross_sells_sets = $wpdb->get_results($wpdb->prepare($sql, $cross_sells_sets), ARRAY_A);

        if ($wp_cross_sells_sets) {

            $wp_cross_sells_pack = array_unique(array_column($wp_cross_sells_sets, 'id'));
        }
    }

    if ($cross_sells_products) {
        $placeholders_cross_sells_products = array_fill(0, count($cross_sells_products), '%d');
        $placeholders_cross_sells_products_format = implode(', ', $placeholders_cross_sells_products);
        $sql = "
            SELECT 
                id
            FROM 
                {$rental_product_relations} sets
            WHERE
                rental_id IN ({$placeholders_cross_sells_products_format})
        ";
        $wp_cross_sells_products = $wpdb->get_results($wpdb->prepare($sql, $cross_sells_products), ARRAY_A);

        $flattened_wp_cross_sells_products = [];
        if ($wp_cross_sells_products) {
            $flattened_wp_cross_sells_products = array_unique(array_column($wp_cross_sells_products, 'id'));
        }

        $wp_cross_sells_pack = array_merge($wp_cross_sells_pack, $flattened_wp_cross_sells_products);
    }

    wp_set_object_terms($wp_set_id, $wp_cross_sells_pack, '_crosssell_ids');
    update_post_meta($wp_set_id, '_crosssell_ids', $wp_cross_sells_pack);
}

function rentopian_set_update($set) {

    // Webhook sets unification
    if ( class_exists( 'Rental_Sets_Webhook_Handler', false ) ) {
        Rental_Sets_Webhook_Handler::update( $set );
        return;
    }

    global $wpdb, $rental_tables;
    $rental_product_relations = $wpdb->prefix . $rental_tables["product_relations"];
    $rental_variant_relations = $wpdb->prefix . $rental_tables["variant_relations"];
    $rental_set_relations = $wpdb->prefix . $rental_tables["set_relations"];
    $rental_image_relations = $wpdb->prefix . $rental_tables["image_relations"];

    $set = json_decode(stripslashes($set));
    if ( !$set) {
        http_response_code(400);
        echo json_encode(["error" => "Set is empty."]);
        return;
    }

    // getting full/short description
    $full_description = isset($set->full_description) && !empty($set->full_description) ? $set->full_description : '';
    $description = isset($set->description) && !empty($set->description) ? $set->description : '';

    $set_id = $wpdb->get_var("SELECT `id` FROM $rental_set_relations WHERE `rental_id` = $set->id");
    if ( !$set_id) {
        http_response_code(404);
        echo json_encode(["error" => "Set with rental_id $set->id not found."]);
        return;
    }

    if ( !wp_update_post([
        'ID' => $set_id,
        'post_title' => $set->title,
        'post_content' => $full_description,
        'post_excerpt' => $description,
    ])) {
        http_response_code(404);
        echo json_encode(["error" => "Post with ID $set_id not found."]);
        return;
    }

    $tax_status = $set->taxable? 'taxable': 'none';

    $price_multiplier_id = empty($set->price_multiplier_id) ? 0 : $set->price_multiplier_id;

    update_post_meta($set_id, '_sku', $set->number);
    update_post_meta($set_id, '_regular_price', $set->rental_price);
    update_post_meta($set_id, '_job_cost', $set->job_cost);
    update_post_meta($set_id, '_price_multiplier_id', $price_multiplier_id);
    update_post_meta($set_id, '_tax_status', $tax_status);
    update_post_meta($set_id, '_price', $set->rental_price);
    update_post_meta($set_id, '_rental_exempt_waiver', $set->exempt_waiver);
    update_post_meta($set_id, '_rental_is_sale', $set->is_sale);

    if (isset($wpdb->wc_product_meta_lookup)) {
        $wpdb->query("UPDATE `$wpdb->wc_product_meta_lookup` SET " .
            "`sku` = '" . addslashes($set->number) . "', " .
            "`min_price` = $set->rental_price, " .
            "`max_price` = $set->rental_price, " .
            "`tax_status` = '$tax_status' " .
            "WHERE `product_id` = $set_id");
    }

    // update rental_set_relations rental_division_id column
    $sql = "UPDATE `$rental_set_relations` SET `rental_division_id` = $set->division_id WHERE `rental_id` = $set->id;";

    // require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    // dbDelta($sql);

    // delete product cache
    wc_delete_product_transients($set_id);


    $set_image_gallery = [];
    $thumbnail_id = "";
    $image_relations_sql = [];

    if ($set->images) {

        $get_images = [];

        foreach ($set->images as $img) {

            $img_id = $wpdb->get_var("SELECT `id` FROM $rental_image_relations WHERE `rental_id` = $img->id");
            
            if ($img_id) {
                if ($img->id == $set->img_id) {
                    $thumbnail_id = $img_id;
                } else {
                    $set_image_gallery[] = $img_id;
                }

            } else {

                $get_images[] = $img->id;
            }
        }

        if ( $get_images) {
            $api_key = get_option('rental_api_key');

            // $new_images = rental_curl('files/images', $api_key, true, ['images' => json_encode($get_images)]);

            try {
                $new_images = rental_curl('files/images/stream', $api_key, true, ['images' => json_encode($get_images)]);
            } catch (RentalException $e) {

                ErrorHandler::registerErrorInLog(
                    "API fetch failed in product create webhook: " . $e->getMessage(),
                    __FILE__, __LINE__,
                    $e->getType(),   // preserve type
                    null,
                    $e->getStatusCode()
                );
                throw $e;
            }

            // upload set images via centralized helper
            foreach ($new_images as $image) {

                if ( !$image->url) {
                    continue;
                }

                $attach_id = rentopian_webhook_download_image(
                    $image->url,
                    $image->id,
                    $image,
                    $set_id
                );

                if ( !$attach_id) {
                    continue;
                }

                $image_relations_sql[] = "($attach_id, $image->id)";

                if ( !$thumbnail_id && $image->id == $set->img_id) {
                    $thumbnail_id = $attach_id;
                } else {
                    $set_image_gallery[] = $attach_id;
                }
            }
        }
    }

    $set_image_gallery = empty($set_image_gallery)? "": implode(",", $set_image_gallery);


    // $thumbnail_id = '';
    // if ($set->img_id) {
    //     require_once("includes/models/RTImage.php");
    //     $images = (new RTImage())->getImages([$set->img_id]);
    //     if (isset($images[$set->img_id])) {
    //         $thumbnail_id = $images[$set->img_id];
    //     }
    // }

    update_post_meta($set_id, '_thumbnail_id', $thumbnail_id);
    update_post_meta($set_id, '_product_image_gallery', $set_image_gallery);

    $set_items = [];
    $set_items_have_optional_items = false;
    $set_items_have_some_hidden_items = false;
    $items = json_decode($set->items);
    if ($items) {

        foreach ($items as $item) {
            $product_id = $wpdb->get_var("SELECT `id` FROM $rental_product_relations WHERE `rental_id` = $item->product_id AND `rental_division_id` = $item->division_id");
            
            if ($product_id) {

                $optional_items = [];
                if (isset($item->optional_items)) {
                    foreach ($item->optional_items as $optional_item) {
    
                        $optional_items[] = [
                            'product_id' => $product_id,
                            'variant_id' => $wpdb->get_var("SELECT `id` FROM $rental_variant_relations WHERE `rental_id` = $optional_item->variant_id AND `rental_division_id` = $optional_item->division_id"),
                            'quantity' => $optional_item->quantity,
                            'is_selected' => isset($optional_item->is_selected) && $optional_item->is_selected ? 1 : 0,
                        ];
                    }

                    if ($optional_items) {
                        $set_items_have_optional_items = true;
                    }
                }

                $set_item_addons = [];
                if (isset($item->addons) && $item->addons) {

                    foreach ($item->addons as $item_addon) {

                        $addon_variants_optional = [];
                        if (isset($item_addon->variants_optional) && $item_addon->variants_optional) {

                            foreach ($item_addon->variants_optional as $addon_variant) {
                                
                                $optional_variant_product_id = $wpdb->get_var("SELECT `id` FROM $rental_product_relations WHERE `rental_id` = $addon_variant->product_id AND `rental_division_id` = $addon_variant->division_id");
                                $optional_variant_variant_id = $wpdb->get_var("SELECT `id` FROM $rental_variant_relations WHERE `rental_id` = $addon_variant->variant_id AND `rental_division_id` = $addon_variant->division_id");

                                $addon_variants_optional[] = [
                                    'product_id' => $optional_variant_product_id ? $optional_variant_product_id : 0,
                                    'rental_product_id' => $addon_variant->product_id,
                                    'variant_id' => $optional_variant_variant_id ? $optional_variant_variant_id : 0,
                                    'rental_variant_id' => $addon_variant->variant_id,
                                    'quantity' => $addon_variant->quantity,
                                    'default' => $addon_variant->default,
                                ];
                            }
                        }


                        $add_on_id = $wpdb->get_var("SELECT `id` FROM $rental_product_relations WHERE `rental_id` = $item_addon->product_id AND `rental_division_id` = $item_addon->division_id");
                        $add_on_variant_id = $wpdb->get_var("SELECT `id` FROM $rental_variant_relations WHERE `rental_id` = $item_addon->add_on_variant_id AND `rental_division_id` = $item_addon->division_id");

                        $set_item_addons[] = [
                            'rental_inv_id' => $item_addon->id,
                            'product_id' => $add_on_id ? $add_on_id : 0,
                            'rental_product_id' => $item_addon->product_id,
                            'variant_id' => $add_on_variant_id ? $add_on_variant_id : 0,
                            'rental_variant_id' => $item_addon->add_on_variant_id,
                            'quantity' => $item_addon->add_on_quantity,
                            'price' => $item_addon->price,
                            'required' => $item_addon->required,
                            'hidden' => isset($item_addon->hidden) ? $item_addon->hidden : 0,
                            'product_price' => isset($item_addon->product_price) ? $item_addon->product_price : 0,
                            'inherit_price' => isset($item_addon->inherit_price) ? $item_addon->inherit_price : 0,
                            'variants_optional' => $addon_variants_optional,
                        ];
                    }


                    // if ($set_item_addons && !get_option('rental_set_items_have_addons', false)) {
                    //     update_option('rental_set_items_have_addons', true);
                    // }
                }

                $set_item_variant_id = isset($item->optional_items) ? 0 : $wpdb->get_var("SELECT `id` FROM $rental_variant_relations WHERE `rental_id` = $item->variant_id AND `rental_division_id` = $item->division_id");
                if (isset($item->has_selected) && $item->has_selected && $item->variant_id) {
                    $set_item_variant_id = $wpdb->get_var("SELECT `id` FROM $rental_variant_relations WHERE `rental_id` = $item->variant_id AND `rental_division_id` = $item->division_id");
                }
  
                if (isset($item->hidden) && $item->hidden) {
                    $set_items_have_some_hidden_items = true;
                }

                $set_items[] = [
                    'product_id' => $product_id,
                    'variant_id' => $set_item_variant_id,
                    'quantity' => $item->quantity,
                    'optional_items' => $optional_items,
                    'has_selected' => isset($item->has_selected) && $item->has_selected ? 1 : 0,
                    'optional_item_price_update_needed' => isset($item->has_selected) && $item->has_selected ? 1 : 0, // 0: no need to update, 1: needs update, 2: is updated
                    'hidden' => isset($item->hidden) && $item->hidden ? 1 : 0,
                    'addons' => $set_item_addons,
                ];
            }
        }

    }

    update_post_meta($set_id, '_rental_set_items_default', $set_items);
    update_post_meta($set_id, '_rental_set_items', $set_items);
    update_post_meta($set_id, '_rental_set_items_have_optional_items', $set_items_have_optional_items);

    $hide_items_on_website = isset($set->hide_items_on_website) ? $set->hide_items_on_website : 0;
    update_post_meta($set_id, '_rental_hide_items_on_website', $hide_items_on_website);
    update_post_meta($set_id, '_rental_some_hidden_items', $set_items_have_some_hidden_items);

    $set_max_qty = $set->max_quantity ?? '';
    update_post_meta($set_id, '_rental_max_quantity', $set_max_qty);



    if ($image_relations_sql) {
        $image_relations_sql = implode(", ", $image_relations_sql);
        $sql .= "INSERT INTO `$rental_image_relations` (`id`, `rental_id`) VALUES $image_relations_sql;";
    }

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);

    $cats = rental_create_categories($set->categories, $set_id);
    
    $product_tags = isset($set->product_tags) ? $set->product_tags : [];
    $tags = rental_create_set_tags($set->tags, $set_id, $product_tags);

    $set_upsells_sets = isset($set->up_sells_sets) && $set->up_sells_sets ? $set->up_sells_sets : [];
    $set_up_sells_products = isset($set->up_sells_products) && $set->up_sells_products ? $set->up_sells_products : [];
    $set_cross_sells_sets = isset($set->cross_sells_sets) && $set->cross_sells_sets ? $set->cross_sells_sets : [];
    $set_cross_sells_products = isset($set->cross_sells_products) && $set->cross_sells_products ? $set->cross_sells_products : [];

    set_sets_up_sells($set_id, $set_upsells_sets, $set_up_sells_products);
    set_sets_cross_sells($set_id, $set_cross_sells_sets, $set_cross_sells_products);

    rental_clear_cache();

    // Polylang: ensure set retains default language
    rental_pll_assign_post( (int) $set_id );
    rental_translation_queue_post( (int) $set_id );

    http_response_code(200);
    echo json_encode([
        "set" => [
            "rental_id" => $set->id,
            "id" => $set_id,
            "categories" => $cats,
            "items" => $set_items,
            "tags" => $tags,
        ],
        "message" => "Set successfully updated!"
    ]);
    return;
}

function rentopian_set_delete($set) {

    // Webhook sets unification 
    if ( class_exists( 'Rental_Sets_Webhook_Handler', false ) ) {
        Rental_Sets_Webhook_Handler::delete( $set );
        return;
    }

    global $wpdb, $rental_tables;
    $rental_set_relations = $wpdb->prefix . $rental_tables["set_relations"];

    $set = json_decode(stripslashes($set));
    if ( !$set) {
        http_response_code(400);
        echo json_encode(["error" => "Set is empty."]);
        return;
    }

    $set_id = $wpdb->get_var("SELECT `id` FROM $rental_set_relations WHERE `rental_id` = $set->id");
    if ( !$set_id) {
        http_response_code(404);
        echo json_encode(["error" => "Set with rental_id $set->id not found."]);
        return;
    }

    wp_update_post(['ID' => $set_id, 'post_status' => 'trash',]);

    rental_clear_cache();

    http_response_code(200);
    echo json_encode([
        "set" => [
            "rental_id" => $set->id,
            "id" => $set_id,
        ],
        "message" => "Set successfully deleted!"
    ]);
    return;
}

// Category
function rentopian_category_create($category) {
    global $wpdb, $rental_tables;
    $rental_category_relations = $wpdb->prefix . $rental_tables["category_relations"];

    $category = json_decode(stripslashes($category));
    if ( !$category) {
        http_response_code(400);
        echo json_encode(["error" => "Category is empty."]);
        return;
    }

    $parent = 0;
    if ($category->parent_id) {
        $parent_id = $wpdb->get_var("SELECT `id` FROM $rental_category_relations WHERE `rental_id` = $category->parent_id");
        if ($parent_id) {
            $parent = $parent_id;
        }
    }

    $img_id = 0;
    if ($category->img_id) {
        require_once("includes/models/RTImage.php");
        $images = (new RTImage())->getImages([$category->img_id]);
        if (isset($images[$category->img_id])) {
            $img_id = $images[$category->img_id];
        }
    }
    
    $banner_img_id = 0;
    if ($category->banner_img_id) {
        require_once("includes/models/RTImage.php");
        $images = (new RTImage())->getImages([$category->banner_img_id]);
        if (isset($images[$category->banner_img_id])) {
            $banner_img_id = $images[$category->banner_img_id];
        }
    }

    $cid = wp_insert_term($category->title, 'product_cat', ['parent' => $parent, 'description' => $category->description]);
    $cat_id = is_wp_error($cid)? $cid->error_data["term_exists"]: $cid["term_taxonomy_id"];
    add_term_meta($cat_id, "order", $category->order);
    add_term_meta($cat_id, "thumbnail_id", $img_id);
    add_term_meta($cat_id, "banner_id", $banner_img_id);
    add_term_meta($cat_id, "rental_icon", $category->icon?: "");
    $category_relations_sql = "($cat_id, $category->id)";
    $wpdb->query("INSERT INTO `$rental_category_relations` (`id`, `rental_id`) VALUES $category_relations_sql;");


    // Polylang: assign default language to new category term
    rental_pll_assign_term( (int) $cat_id );
    rental_translation_queue_term( (int) $cat_id);

    if ( class_exists( 'WooCommerce_Single_Variations' ) && defined('WC_SINGLE_VAR_VERSION')) {
        $admin_wcs_variation = new WooCommerce_Single_Variations_Admin('plugin', WC_SINGLE_VAR_VERSION);
        $admin_wcs_variation->init();
        // $admin_wcs_variation->update_variations(false);
        $admin_wcs_variation->update_variations(false, true);
        $admin_wcs_variation->reset_transients(false);
    }

    if ($wpdb->last_error !== '') {
        http_response_code(400);
        echo json_encode(["error" => "SQL_ERROR: $wpdb->last_error (SQL: $wpdb->last_query)"]);
        throw new RentalException("SQL_ERROR: $wpdb->last_error (SQL: $wpdb->last_query)");
    }

    http_response_code(200);
    echo json_encode([
        "category" => [
            "rental_id" => $category->id,
            "id" => $cat_id,
            "items" => $img_id,
            "parent" => $parent,
        ],
        "message" => "Product category successfully created!"
    ]);
    return;
}

// function rentopian_category_update_OLD($category) {
//     global $wpdb, $rental_tables;
//     $rental_category_relations = $wpdb->prefix . $rental_tables["category_relations"];

//     $category = json_decode(stripslashes($category));
//     if ( !$category) {
//         http_response_code(400);
//         echo json_encode(["error" => "Category is empty."]);
//         return;
//     }

//     $cat_id = $wpdb->get_var("SELECT `id` FROM $rental_category_relations WHERE `rental_id` = $category->id");
//     if ( !$cat_id) {
//         http_response_code(404);
//         echo json_encode(["error" => "Category with rental_id $category->id not found."]);
//         return;
//     }

//     $parent = 0;
//     if ($category->parent_id) {
//         $parent_id = $wpdb->get_var("SELECT `id` FROM $rental_category_relations WHERE `rental_id` = $category->parent_id");
//         if ($parent_id) {
//             $parent = $parent_id;
//         }
//     }

//     $img_id = 0;
//     if ($category->img_id) {
//         require_once("includes/models/RTImage.php");
//         $images = (new RTImage())->getImages([$category->img_id]);
//         if (isset($images[$category->img_id])) {
//             $img_id = $images[$category->img_id];
//         }
//     }

//     $banner_img_id = 0;
//     if ($category->banner_img_id) {
//         require_once("includes/models/RTImage.php");
//         $images = (new RTImage())->getImages([$category->banner_img_id]);
//         if (isset($images[$category->banner_img_id])) {
//             $banner_img_id = $images[$category->banner_img_id];
//         }
//     }

//     wp_update_term($cat_id, 'product_cat', ['name' => $category->title, 'parent' => $parent, 'description' => $category->description]);
//     update_term_meta($cat_id, "order", $category->order);
//     update_term_meta($cat_id, "thumbnail_id", $img_id);
//     update_term_meta($cat_id, "banner_id", $banner_img_id);
//     update_term_meta($cat_id, "rental_icon", $category->icon?: "");


//     if ( class_exists( 'WooCommerce_Single_Variations' ) && defined('WC_SINGLE_VAR_VERSION')) {
//         $admin_wcs_variation = new WooCommerce_Single_Variations_Admin('plugin', WC_SINGLE_VAR_VERSION);
//         $admin_wcs_variation->init();
//         // $admin_wcs_variation->update_variations(false);
//         $admin_wcs_variation->update_variations(false, true);
//         $admin_wcs_variation->reset_transients(false);
//     }

//     http_response_code(200);
//     echo json_encode([
//         "category" => [
//             "rental_id" => $category->id,
//             "id" => $cat_id,
//             "image" => $img_id,
//             "parent" => $parent,
//         ],
//         "message" => "Product category successfully updated!"
//     ]);
//     return;
// }

function rentopian_category_update($category) {
    global $wpdb, $rental_tables;
    $rental_category_relations = $wpdb->prefix . $rental_tables["category_relations"];

    $category = json_decode(stripslashes($category));
    if (!$category) {
        http_response_code(400);
        echo json_encode(["error" => "Category is empty."]);
        return;
    }

    $cat_id = $wpdb->get_var($wpdb->prepare(
        "SELECT `id` FROM {$rental_category_relations} WHERE `rental_id` = %d",
        (int)$category->id
    ));
    if (!$cat_id) {
        http_response_code(404);
        echo json_encode(["error" => "Category with rental_id {$category->id} not found."]);
        return;
    }

    // get old term to capture old slug (for redirects)
    $old_term = get_term($cat_id, 'product_cat');
    $old_slug = $old_term && !is_wp_error($old_term) ? $old_term->slug : '';

    $parent = 0;
    if (!empty($category->parent_id)) {
        $parent_id = $wpdb->get_var($wpdb->prepare(
            "SELECT `id` FROM {$rental_category_relations} WHERE `rental_id` = %d",
            (int)$category->parent_id
        ));
        if ($parent_id) $parent = (int)$parent_id;
    }

    $img_id = 0;
    if (!empty($category->img_id)) {
        require_once("includes/models/RTImage.php");
        $images = (new RTImage())->getImages([$category->img_id]);
        if (isset($images[$category->img_id])) $img_id = (int)$images[$category->img_id];
    }

    $banner_img_id = 0;
    if (!empty($category->banner_img_id)) {
        require_once("includes/models/RTImage.php");
        $images = (new RTImage())->getImages([$category->banner_img_id]);
        if (isset($images[$category->banner_img_id])) $banner_img_id = (int)$images[$category->banner_img_id];
    }

    // Build args for wp_update_term; include slug only if provided.
    $update_args = [
        'name' => $category->title,
        'parent' => $parent,
        'description' => $category->description
    ];
    if (isset($category->slug) && $category->slug !== '') {
        // safe slug
        $update_args['slug'] = sanitize_title($category->slug);
    }

    wp_update_term($cat_id, 'product_cat', $update_args);

    update_term_meta($cat_id, "order", isset($category->order) ? $category->order : 0);
    update_term_meta($cat_id, "thumbnail_id", $img_id);
    update_term_meta($cat_id, "banner_id", $banner_img_id);
    update_term_meta($cat_id, "rental_icon", isset($category->icon) ? $category->icon : "");

    // If slug changed, store a redirect mapping old_slug => new_slug for later redirecting
    if (!empty($old_slug) && isset($update_args['slug']) && $update_args['slug'] !== $old_slug) {
        $new_slug = $update_args['slug'];
        $redirects = get_option('rental_category_slug_redirects', []);
        // store or update mapping
        $redirects[$old_slug] = $new_slug;
        update_option('rental_category_slug_redirects', $redirects);
    }

    // Clear term cache and product transients to make filters reflect change immediately
    clean_term_cache($cat_id, 'product_cat');

    // Polylang: ensure category term retains default language
    rental_pll_assign_term( (int) $cat_id );
    rental_translation_queue_term( (int) $cat_id);


    // Clear products' transients that belong to this category so widgets/filter counts update
    $products_in_term = get_objects_in_term($cat_id, 'product_cat'); // returns post IDs
    if (is_array($products_in_term) && !empty($products_in_term)) {
        foreach ($products_in_term as $pid) {
            if (function_exists('wc_delete_product_transients')) {
                wc_delete_product_transients($pid);
            } else {
                // fallback to generic post cache clear
                clean_post_cache($pid);
            }
        }
    }

    // run single-variations plugin hooks if present
    if ( class_exists( 'WooCommerce_Single_Variations' ) && defined('WC_SINGLE_VAR_VERSION')) {
        $admin_wcs_variation = new WooCommerce_Single_Variations_Admin('plugin', WC_SINGLE_VAR_VERSION);
        $admin_wcs_variation->init();
        $admin_wcs_variation->update_variations(false, true);
        $admin_wcs_variation->reset_transients(false);
    }

    http_response_code(200);
    echo json_encode([
        "category" => [
            "rental_id" => (int)$category->id,
            "id" => (int)$cat_id,
            "image" => $img_id,
            "parent" => $parent,
        ],
        "message" => "Product category successfully updated!"
    ]);
    return;
}


function rentopian_category_delete($category) {
    global $wpdb, $rental_tables;
    $rental_category_relations = $wpdb->prefix . $rental_tables["category_relations"];

    $category = json_decode(stripslashes($category));
    if ( !$category) {
        http_response_code(400);
        echo json_encode(["error" => "Category is empty."]);
        return;
    }

    $cat_id = $wpdb->get_var("SELECT `id` FROM $rental_category_relations WHERE `rental_id` = $category->id");
    if ( !$cat_id) {
        http_response_code(404);
        echo json_encode(["error" => "Category with rental_id $category->id not found."]);
        return;
    }

    wp_delete_term($cat_id, 'product_cat');

    // if product options are activated then check to detach them from the category
    $rental_product_option_relations = $wpdb->prefix . $rental_tables["product_option_relations"];
    if ($wpdb->get_var("show tables like '$rental_product_option_relations'") == $rental_product_option_relations) {
        // detach category option relation
        
        $sql = "
            SELECT
                po_id
            FROM
                {$rental_product_option_relations}
            WHERE
                wp_id = %d
                AND rental_id = %d
                AND type = 2
        ";
        // option ids to delete
        $option_ids = $wpdb->get_results($wpdb->prepare($sql, [$cat_id, $category->id]), ARRAY_A);
        if (!empty($option_ids)) {
            foreach($option_ids as $key=>$option_id) {
                $option_ids[$key] = $option_id['po_id'];
            }
            
            $_option_ids = [];
            $category_product_ids = [];
            $sql = "
                SELECT 
                    object_id
                FROM 
                    {$wpdb->term_relationships} terms
                LEFT JOIN {$wpdb->posts} posts ON posts.id = terms.object_id
                WHERE
                    term_taxonomy_id = %d
                    AND posts.post_type = 'product'
                    AND posts.post_status = 'publish'
            ";
            $product_ids_by_category_id = $wpdb->get_results($wpdb->prepare($sql, $cat_id), ARRAY_A);
            if ($product_ids_by_category_id) {
                foreach($product_ids_by_category_id as $pid) {
                    $category_product_ids[] = $pid["object_id"];
                }
            }
            
            if (!empty($category_product_ids)) {
                foreach($option_ids as $option_id) {
                    foreach ($category_product_ids as $wp_id) {
                        $_option_ids[$wp_id][] = $option_id;
                    }
                }
                $delete_postmeta_ids = [];
                $productmeta_sql = [];
                foreach($_option_ids as $wp_id => $option_ids) {

                    $sql = "SELECT postmeta.*, rel.po_id, rel.wp_id wp_product_id, rel.rental_id rental_product_id  FROM {$wpdb->postmeta} postmeta
                    LEFT JOIN {$rental_product_option_relations} rel ON rel.wp_id = postmeta.post_id AND rel.type = 1
                    WHERE post_id = %d AND meta_key = '_product_options'";
                    $existing_options = $wpdb->get_results($wpdb->prepare($sql, $wp_id), ARRAY_A);
                    
                    // must not be deleted options (because they are also related from a product)
                    $must_not_be_deleted_options = [];
                    foreach($existing_options as $ext_opt) {
                        if ($ext_opt['po_id']) {
                            $must_not_be_deleted_options[] = $ext_opt['po_id'];
                        }
                    }

                    foreach($option_ids as $option_id_to_delete) {

                        // if the option id is also related from a product, so don't delete it!
                        foreach($existing_options as $ext_opt) {
                            // if ($ext_opt['po_id'] && $option_id_to_delete == $ext_opt['po_id']) {
                            //     continue;
                            // }

                            $existing_options_decoded = [];
                            $final_options = [];
                            if (!empty($ext_opt['meta_value'])) {
                                // existing options update
                                $existing_options_decoded = json_decode($ext_opt['meta_value'], true);
                                if (!empty($existing_options_decoded)) {
                                    foreach($existing_options_decoded as $ext_option) {
                                        if (in_array($ext_option, $must_not_be_deleted_options) && $ext_option != $option_id_to_delete) {
                                            $final_options[] = $ext_option;
                                        }
                                    }
                                }

                                $option_ids_rearranged = array_values(array_unique($final_options));
                                $option_ids_updated = json_encode($option_ids_rearranged);
                                $productmeta_sql[$wp_id] = "($wp_id, '_product_options', '$option_ids_updated')";
                            }
                        }
                    }

                    
                    $delete_postmeta_ids[] = $wp_id;
                }

                $placeholders = array_fill(0, count($delete_postmeta_ids), '%d');
                $placeholders_format = implode(', ', $placeholders);
                // delete all product options postmeta with specified post(product) ids
                $sql_delete = "DELETE FROM {$wpdb->postmeta} WHERE meta_key = '_product_options' AND post_id IN ({$placeholders_format})";
                $wpdb->query($wpdb->prepare($sql_delete, $delete_postmeta_ids));
                // insert updated postmeta values
                rental_insert("INSERT INTO `$wpdb->postmeta` (`post_id`, `meta_key`, `meta_value`) VALUES", $productmeta_sql);
            }

            $wpdb->delete($rental_product_option_relations, [
                "rental_id" => $category->id,
                "wp_id" => $cat_id,
                "type" => 2
            ]);
        }
    }
    
    

    http_response_code(200);
    echo json_encode([
        "category" => [
            "rental_id" => $category->id,
            "id" => $cat_id,
        ],
        "message" => "Product category successfully deleted!"
    ]);
    return;
}

function rentopian_categories_sorted($categories) {
    $categories = json_decode(stripslashes($categories));
    if ( !$categories) {
        http_response_code(400);
        echo json_encode(["error" => "Categories list is empty."]);
        return;
    }

    sort_categories($categories);

    http_response_code(200);
    echo json_encode([
        "categories" => $categories,
        "message" => "Product categories' sort/parent successfully updated!"
    ]);
    return;
}

// Brand
function rentopian_save_brand() {
    if ( !isset($_POST["brand"])) {
        http_response_code(400);
        echo json_encode(["error" => "The 'brand' is required."]);
        return;
    }
    $brand = json_decode(stripslashes($_POST["brand"]));
    if (empty($brand)) {
        http_response_code(400);
        echo json_encode(["error" => "The 'brand' is empty."]);
        return;
    }

    require_once("includes/models/RTBrand.php");
    $brand = (new RTBrand($brand->id, $brand->title, $brand->logo_id))
        ->save();

    // Polylang: assign default language to brand term
    if ( isset( $brand->term_id ) ) {

        rental_pll_assign_term( (int) $brand->term_id );
        rental_translation_queue_term( (int) $brand->term_id);

    } elseif ( isset( $brand['term_id'] ) ) {

        rental_pll_assign_term( (int) $brand['term_id'] );
        rental_translation_queue_term( (int) $brand['term_id']);

    }

    http_response_code(200);
    echo json_encode([
        "brand" => $brand,
        "message" => "Product brand saved successfully."
    ]);
}

function rentopian_delete_brand() {
    if ( !isset($_POST["brand"])) {
        http_response_code(400);
        echo json_encode(["error" => "The 'brand' is required."]);
        return;
    }
    $brand = json_decode(stripslashes($_POST["brand"]));
    if (empty($brand)) {
        http_response_code(400);
        echo json_encode(["error" => "The 'brand' is empty."]);
        return;
    }

    require_once("includes/models/RTBrand.php");
    $data = (new RTBrand($brand->id))
        ->delete();

    http_response_code(200);
    echo json_encode([
        "brand" => $data,
        "message" => "Product brand deleted successfully."
    ]);
}


// Coupons
function rentopian_save_coupon() {
    if ( !isset($_POST["coupon"])) {
        http_response_code(400);
        echo json_encode(["error" => "The 'coupon' is required."]);
        return;
    }
    if ( !isset($_POST["coupon_divisions"])) {
        http_response_code(400);
        echo json_encode(["error" => "The 'coupon_divisions' are required."]);
        return;
    }
    $coupon = json_decode(stripslashes($_POST["coupon"]));
    $coupon_divisions = !empty(trim($_POST["coupon_divisions"])) ? trim($_POST["coupon_divisions"]) : "";
    if (empty($coupon)) {
        http_response_code(400);
        echo json_encode(["error" => "The 'coupon' is empty."]);
        return;
    }

    require_once("includes/models/RTCoupon.php");
    $coupon = (new RTCoupon(
        $coupon->id
        , $coupon->coupon_id
        , $coupon->description
        , $coupon->target
        , $coupon->unit
        , $coupon->quantity
        , $coupon->unlimited_qty
        , $coupon->coupon_use
        , $coupon->end_date
        , $coupon->no_expiry
        , $coupon->status
        , $coupon->all_divisions
        , $coupon_divisions
    ))->save();

    http_response_code(200);
    echo json_encode([
        "coupon" => $coupon,
        "message" => "Coupon saved successfully."
    ]);
}

function rentopian_delete_coupon() {
    if ( !isset($_POST["coupon"])) {
        http_response_code(400);
        echo json_encode(["error" => "The 'coupon' is required."]);
        return;
    }
    $coupon = json_decode(stripslashes($_POST["coupon"]));
    if (empty($coupon)) {
        http_response_code(400);
        echo json_encode(["error" => "The 'coupon' is empty."]);
        return;
    }

    require_once("includes/models/RTCoupon.php");
    $data = (new RTCoupon($coupon->id))
        ->delete();

    http_response_code(200);
    echo json_encode([
        "coupon" => $data,
        "message" => "Coupon deleted successfully."
    ]);
}

function rentopian_save_price_multiplier() {
    if ( !isset($_POST["price_multiplier"])) {
        http_response_code(400);
        echo json_encode(["error" => "The '[Price multiplier]' is required."]);
        return;
    }
    $price_multiplier = json_decode(stripslashes($_POST["price_multiplier"]), true);
    if (empty($price_multiplier)) {
        http_response_code(400);
        echo json_encode(["error" => "The 'Price multiplier' is empty."]);
        return;
    }
    
    require_once("includes/models/RTPriceMultiplier.php");
    $price_multiplier_id = (new RTPriceMultiplier(
        $price_multiplier['id']
        , $price_multiplier['title']
        , $price_multiplier['slug']
        , $price_multiplier['is_monthly']
        , $price_multiplier['is_repeat']
        // , addslashes(serialize($price_multiplier['items']))
        , serialize($price_multiplier['items'])
    ))->save();


    if (false === $price_multiplier_id) {
        http_response_code(400);
        echo json_encode([
            "price_multiplier" => $price_multiplier_id,
            "message" => "Operation failure!"
        ]);
    } else {
        // success
        http_response_code(200);
        echo json_encode([
            "price_multiplier" => $price_multiplier_id,
            "message" => "Price multiplier saved successfully."
        ]);
    }
}

function rentopian_delete_price_multiplier() {
    if ( !isset($_POST["price_multiplier"])) {
        http_response_code(400);
        echo json_encode(["error" => "The 'Price multiplier' is required."]);
        return;
    }
    $price_multiplier = json_decode(stripslashes($_POST["price_multiplier"]));
    if (empty($price_multiplier)) {
        http_response_code(400);
        echo json_encode(["error" => "The 'Price multiplier' is empty."]);
        return;
    }

    require_once("includes/models/RTPriceMultiplier.php");
    $result = (new RTPriceMultiplier($price_multiplier->id))
        ->delete();

    if (false === $result) {
        http_response_code(400);
        echo json_encode([
            "price_multiplier" => $result,
            "message" => "Operation failure!"
        ]);
    } else {
        // success
        http_response_code(200);
        echo json_encode([
            "price_multiplier" => $result,
            "message" => "Price multiplier deleted successfully."
        ]);
    }
}

function rentopian_save_product_options() {
    if ( !isset($_POST["option"])) {
        http_response_code(400);
        echo json_encode(["error" => "The 'Product Option' is required."]);
        return;
    }
    set_time_limit(120);
    $option = json_decode(stripslashes($_POST["option"]), true);
    if (empty($option)) {
        http_response_code(400);
        echo json_encode(["error" => "The 'Product Option' is empty."]);
        return;
    }

    require_once("includes/models/RTProductOptions.php");
    $product_options_id = (new RTProductOptions(
        $option['id']
        , $option['title']
        , $option['once_per_order']
        , $option['details']
    ))->save();

    if (false === $product_options_id) {
        http_response_code(400);
        echo json_encode([
            "option" => $product_options_id,
            "message" => "Operation failure!"
        ]);
    } else {
        // success
        http_response_code(200);
        echo json_encode([
            "option" => $product_options_id,
            "message" => "Product option saved successfully."
        ]);
    }
}

function rentopian_delete_product_options() {
    if ( !isset($_POST["option"])) {
        http_response_code(400);
        echo json_encode(["error" => "The 'Product Option' is required."]);
        return;
    }
    $option = json_decode(stripslashes($_POST["option"]));
    if (empty($option)) {
        http_response_code(400);
        echo json_encode(["error" => "The 'Product Option' is empty."]);
        return;
    }

    require_once("includes/models/RTProductOptions.php");
    $result = (new RTProductOptions($option->id))
        ->delete();

    if (false === $result) {
        http_response_code(400);
        echo json_encode([
            "option" => $result,
            "message" => "Operation failure!"
        ]);
    } else {
        // success
        http_response_code(200);
        echo json_encode([
            "option" => $result,
            "message" => "Product Option deleted successfully."
        ]);
    }
}

function rentopian_save_set_options() {
    if ( !isset($_POST["option"])) {
        http_response_code(400);
        echo json_encode(["error" => "The 'Product Option' is required."]);
        return;
    }
    set_time_limit(120);
    $option = json_decode(stripslashes($_POST["option"]), true);
    if (empty($option)) {
        http_response_code(400);
        echo json_encode(["error" => "The 'Set Option' is empty."]);
        return;
    }

    require_once("includes/models/RTSetOptions.php");
    $set_options_id = (new RTSetOptions(
        $option['id']
        , $option['title']
        , $option['once_per_order']
        , $option['details']
    ))->save();

    if (false === $set_options_id) {
        http_response_code(400);
        echo json_encode([
            "option" => $set_options_id,
            "message" => "Operation failure!"
        ]);
    } else {
        // success
        http_response_code(200);
        echo json_encode([
            "option" => $set_options_id,
            "message" => "Set option saved successfully."
        ]);
    }
}

function rentopian_delete_set_options() {
    if ( !isset($_POST["option"])) {
        http_response_code(400);
        echo json_encode(["error" => "The 'Set Option' is required."]);
        return;
    }
    $option = json_decode(stripslashes($_POST["option"]));
    if (empty($option)) {
        http_response_code(400);
        echo json_encode(["error" => "The 'Set Option' is empty."]);
        return;
    }

    require_once("includes/models/RTSetOptions.php");
    $result = (new RTSetOptions($option->id))
        ->delete();

    if (false === $result) {
        http_response_code(400);
        echo json_encode([
            "option" => $result,
            "message" => "Operation failure!"
        ]);
    } else {
        // success
        http_response_code(200);
        echo json_encode([
            "option" => $result,
            "message" => "Set Option deleted successfully."
        ]);
    }
}

function rentopian_save_blocked_inventories() {
    if ( !isset($_POST["blocked"])) {
        http_response_code(400);
        echo json_encode(["error" => "The 'Blocked Inventories' are required."]);
        return;
    }
    $blocked = json_decode(stripslashes($_POST["blocked"]), true);
    if (empty($blocked)) {
        http_response_code(400);
        echo json_encode(["error" => "The 'Blocked Inventories' is empty."]);
        return;
    }

    require_once("includes/models/RTInventoryBlocks.php");
    $blocked_id = (new RTInventoryBlocks(
        $blocked['id']
        , $blocked['division_id']
        , $blocked['start_date']
        , $blocked['end_date']
        , $blocked['type']
        , $blocked['specific']
    ))->save();

    rental_clear_cache();

    if (false === $blocked_id) {
        http_response_code(400);
        echo json_encode([
            "option" => $blocked_id,
            "message" => "Operation failure!"
        ]);
    } else {
        // success
        http_response_code(200);
        echo json_encode([
            "option" => $blocked_id,
            "message" => "Inventories blocked successfully."
        ]);
    }
}   

function rentopian_delete_blocked_inventories() {
    if ( !isset($_POST["blocked"])) {
        http_response_code(400);
        echo json_encode(["error" => "The 'Blocked Inventories' are required."]);
        return;
    }
    $blocked = json_decode(stripslashes($_POST["blocked"]));
    if (empty($blocked)) {
        http_response_code(400);
        echo json_encode(["error" => "The 'Blocked Inventories' is empty."]);
        return;
    }

    require_once("includes/models/RTInventoryBlocks.php");
    $result = (new RTInventoryBlocks($blocked->id))->delete();

    rental_clear_cache();
    
    if (false === $result) {
        http_response_code(400);
        echo json_encode([
            "option" => $result,
            "message" => "Operation failure!"
        ]);
    } else {
        // success
        http_response_code(200);
        echo json_encode([
            "option" => $result,
            "message" => "Inventories unblocked successfully."
        ]);
    }
}

function rentopian_api_key_updated() {
    if ( !isset($_POST["updated"])) {
        http_response_code(400);
        echo json_encode(["error" => "The 'Request' is invalid."]);
        return;
    }
    $updated = intval($_POST["updated"]);
    if ($updated == 1) {
        update_option('rental_api_key_is_valid', 0);
    }

    // success
    http_response_code(200);
    echo json_encode([
        "message" => "The Website API key invalidated successfully."
    ]);
}

function rentopian_order_settings_updated() {
    if ( !isset($_POST["order_settings"])) {
        http_response_code(400);
        echo json_encode(["error" => "The 'Request' is invalid."]);
        return;
    }
    $order_settings = json_decode(stripslashes($_POST["order_settings"]), true);
    if (empty($order_settings)) {
        http_response_code(400);
        echo json_encode(["error" => "The 'Order Settings' is empty."]);
        return;
    }
    
    if (isset($order_settings['auto_tax'])) {
        // rental_tax_type : 1 = manual, 2 = auto tax
        update_option('rental_tax_type', $order_settings['auto_tax'] == 1 ? 2 : 1);
    }

    // success
    http_response_code(200);
    echo json_encode([
        "message" => "The 'Order Settings' updated successfully."
    ]);
}

function rentopian_company_settings_updated() {
    if ( !isset($_POST["company_settings"])) {
        http_response_code(400);
        echo json_encode(["error" => "The 'Request' is invalid."]);
        return;
    }
    $company_settings = json_decode(stripslashes($_POST["company_settings"]), true);
    if (empty($company_settings)) {
        http_response_code(400);
        echo json_encode(["error" => "The 'Company Settings' is empty."]);
        return;
    }
    
    if (isset($company_settings['allow_overbook'])) {
        update_option('rental_allow_overbook', $company_settings['allow_overbook']);
        update_option('rental_inactive_inv_by_quantity', $company_settings['inactive_inv_by_quantity']);
        if (isset($company_settings['theme_options'])) {
            rental_set_theme_options($company_settings['theme_options']);
        }
    }

    $location_based_filter = isset($company_settings['location_based_filter']) ? $company_settings['location_based_filter'] : 0;
    rental_set_filter_duplicate_products_options(get_option('rental_divisions'), $location_based_filter);

    if (isset($company_settings['api_seo_no_index_filter'])) {
        update_option('rental_do_not_index_hidden_duplicate_products_for_seo', $company_settings['api_seo_no_index_filter']);
    }

    // success
    http_response_code(200);
    echo json_encode([
        "message" => "The 'Company Settings' updated successfully."
    ]);
}

function rentopian_set_referral_sources() {
    if ( !isset($_POST["referral_sources"])) {
        http_response_code(400);
        echo json_encode(["error" => "The 'Request' is invalid."]);
        return;
    }

    $referral_sources = json_decode(stripslashes($_POST["referral_sources"]));
    
    update_option('rental_referral_sources', $referral_sources);

    // success
    http_response_code(200);
    echo json_encode([
        "message" => "The 'Referral Sources' updated successfully."
    ]);
    return;
}

function rentopian_set_event_types() {
    if ( !isset($_POST["event_types"])) {
        http_response_code(400);
        echo json_encode(["error" => "The 'Request' is invalid."]);
        return;
    }

    $event_types = json_decode(stripslashes($_POST["event_types"]));
    
    update_option('rental_event_types', $event_types);

    // success
    http_response_code(200);
    echo json_encode([
        "message" => "The 'Event Types' updated successfully."
    ]);
    return;
}


function rentopian_set_payment_tips() {
    if ( !isset($_POST["payment_tips"])) {
        http_response_code(400);
        echo json_encode(["error" => "The 'Request' is invalid."]);
        return;
    }

    $payment_tips = json_decode(stripslashes($_POST["payment_tips"]));
    update_option('rental_payment_tips', $payment_tips);

    // success
    http_response_code(200);
    echo json_encode([
        "message" => "The 'Payment Tips' updated successfully."
    ]);
    return;
}


function rentopian_file_update() {
    // Ensure JSON response header (if nothing sent yet)
    if ( ! headers_sent() ) {
        header('Content-Type: application/json; charset=utf-8');
    }

    // Basic request validation
    if ( ! isset($_POST['file']) ) {
        http_response_code(400);
        echo json_encode(["error" => "The 'Request' is invalid."]);
        return;
    }

    // Use wp_unslash for safety in WP context
    $raw_file = function_exists('wp_unslash') ? wp_unslash($_POST['file']) : stripslashes($_POST['file']);
    $file     = json_decode($raw_file, true);

    // JSON decode error / invalid structure
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($file)) {
        http_response_code(400);
        echo json_encode(["error" => "The 'File' payload is not valid JSON."]);
        return;
    }

    // Ensure we have a valid rental file id
    if (empty($file['id']) || !is_numeric($file['id'])) {
        http_response_code(400);
        echo json_encode(["error" => "The 'File' id is missing or invalid."]);
        return;
    }

    global $wpdb, $rental_tables;
    if (empty($rental_tables['image_relations'])) {
        http_response_code(500);
        echo json_encode(["error" => "The 'image_relations' table is not defined."]);
        return;
    }

    $rental_image_relations = $wpdb->prefix . $rental_tables["image_relations"];

    // Find mapped attachment id for this rental file
    $file_post_id = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT id FROM {$rental_image_relations} WHERE `rental_id` = %d",
            (int) $file['id']
        )
    );

    // Nothing mapped -> just respond back
    if ( !$file_post_id ) {
        http_response_code(200);
        echo json_encode([
            "message" => "No mapped attachment found for this file; nothing was updated.",
            "updated" => false,
        ]);
        return;
    }

    $alt         = isset($file['alt'])         ? $file['alt']         : '';
    $description = isset($file['description']) ? $file['description'] : '';
    $label       = isset($file['label'])       ? $file['label']       : '';

    if (function_exists('sanitize_text_field')) {
        $alt   = sanitize_text_field($alt);
        $label = sanitize_text_field($label);
    }
    if (function_exists('wp_kses_post')) {
        $description = wp_kses_post($description);
    }

    // Get existing _wp_attachment_cropped meta and normalize it to an array
    $_wp_attachment_cropped = get_post_meta($file_post_id, '_wp_attachment_cropped', true);

    // Case 1: it's already an array (typical when WP has unserialized meta)
    if (!is_array($_wp_attachment_cropped)) {

        // Case 2: string – might be JSON
        if (is_string($_wp_attachment_cropped) && $_wp_attachment_cropped !== '') {
            $decoded = json_decode($_wp_attachment_cropped, true);

            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $_wp_attachment_cropped = $decoded;
            } else {
                // Not valid JSON -> reset to empty array to avoid type issues
                $_wp_attachment_cropped = [];
            }
        } else {
            // Any other type/null/empty string -> normalize to array
            $_wp_attachment_cropped = [];
        }
    }

    $_wp_attachment_cropped['alt'] = $alt;

    // Persist the updated meta
    update_post_meta($file_post_id, '_wp_attachment_cropped', $_wp_attachment_cropped);

    if (function_exists('update_media_metadata')) {
        update_media_metadata($file_post_id, $alt, $description, $label);
    }

    // success
    http_response_code(200);
    echo json_encode([
        "message" => "The 'File' updated successfully.",
        "updated" => true,
    ]);
    return;
}


function update_media_metadata($media_id, $new_alt_text, $new_description, $new_label) {
    $attachment_metadata = wp_get_attachment_metadata($media_id);

    $update_array = ['ID' => $media_id];
    if ($new_label) {
        $update_array['post_title'] = $new_label; 
    }
    if ($new_alt_text) {
        $attachment_metadata['image_meta']['alt'] = $new_alt_text;
        update_post_meta( $media_id, '_wp_attachment_image_alt', $new_alt_text );

    }
    if ($new_description) {
        $attachment_metadata['image_meta']['description'] = $new_description;
        update_post_meta( $media_id, '_wc_attachment_image_description', $new_description ); 

        $update_array['post_excerpt'] = $new_description; 
        $update_array['post_content'] = $new_description; 
        $update_array['post_content_filtered'] = $new_description; 
    }

    wp_update_attachment_metadata($media_id, $attachment_metadata);

    if ($new_alt_text || $new_description) {
        wp_update_post($update_array);
    }
}

function rentopian_update_divisions() {
    if ( !isset($_POST['divisions'])) {
        http_response_code(400);
        echo json_encode(["error" => "The 'divisions' is required."]);
        return;
    }

    $divisions = json_decode(stripslashes($_POST['divisions']));
    update_option('rental_divisions', $divisions);

    // sync company main division address
    rental_sync_main_division_address(get_option('rental_divisions'));

    http_response_code(200);
    echo json_encode([
        "divisions" => $divisions,
        "message" => "Divisions information updated successfully."
    ]);
    return;
}


function rental_create_categories($categories, $product_id) {
    global $wpdb, $rental_tables;
    $rental_category_relations = $wpdb->prefix . $rental_tables["category_relations"];

    $cats_id = [];
    $category_relations_sql = [];

    if ( !empty($categories)) {
        foreach ($categories as $category_id => $category) {

            // get category id
            $cat_id = (int)$wpdb->get_var("SELECT `id` FROM $rental_category_relations WHERE `rental_id` = $category_id");
            if ( !$cat_id) {
                // insert product category
                $cid = wp_insert_term($category, 'product_cat');
                $cat_id = is_wp_error($cid)? $cid->error_data["term_exists"]: $cid["term_taxonomy_id"];
                $category_relations_sql[] = "($cat_id, $category_id)";
            }

            $cats_id[] = (integer) $cat_id;
            // wp_set_object_terms($product_id, $cat_id, 'product_cat', true);

        }
    }

    // relate product with categories
    wp_set_post_terms($product_id, $cats_id, 'product_cat');
    
    if ( !empty($category_relations_sql)) {
        $category_relations_sql = implode(", ", $category_relations_sql);
        $wpdb->query("INSERT INTO `$rental_category_relations` (`id`, `rental_id`) VALUES $category_relations_sql;");
        if ($wpdb->last_error !== '') {
            http_response_code(400);
            echo json_encode(["error" => "SQL_ERROR: $wpdb->last_error (SQL: $wpdb->last_query)"]);
            throw new RentalException("SQL_ERROR: $wpdb->last_error (SQL: $wpdb->last_query)");
        }
    }

    // Polylang: assign default language to all category terms
    if ( ! empty( $cats_id ) ) {
        rental_pll_assign_terms( $cats_id );
        rental_translation_queue_terms( $cats_id );
    }

    return $cats_id;
}

function rental_create_tags($tags, $product_id, $set_tags) {
    global $wpdb, $rental_tables;
    $rental_tag_relations = $wpdb->prefix . $rental_tables["tag_relations"];
    

    $tags_id = [];
    $tag_relations_sql = [];
    
    if ($tags) {
        foreach ($tags as $rental_tag_id => $tag) {

            $product_slug_tag = sanitize_title($tag);

            // handle any product/set tag title similarity conflicts 
            if ($set_tags) {
                // $set_tag_ids = [];
                // $set_id = 0;

                foreach ($set_tags as $set_tag) {
                    $set_slug_tag = sanitize_title($set_tag->title);
        
                    if ($product_slug_tag == $set_slug_tag) {
                        $set_slug_tag = $set_slug_tag . '-sets';

                        $rental_sets_tag_relations = $wpdb->prefix . $rental_tables["sets_tag_relations"];
                        $set_tag_row = $wpdb->get_row("SELECT `id`, `rental_id`  FROM $rental_sets_tag_relations WHERE `rental_id` = $set_tag->id", ARRAY_A);

                        if ($set_tag_row) {
                            $set_tag_id = (int) $set_tag_row['id'];

                            $args = [
                                'alias_of'    => '',
                                'description' => '',
                                'parent'      => 0,
                                'slug'        => $set_slug_tag,
                            ];
                            wp_update_term( $set_tag_id, 'product_tag', $args);

                            // $set_tag_ids[] = $set_tag_id;
                            // $set_id = $set_tag_row['rental_id'];
                        }
                    }
                }

                // if ($set_id && $set_tag_ids) {
                //     wp_set_post_terms($product_id, $set_tag_ids, 'product_tag');
                // }
            }

            // get tag id
            $tag_id = (int)$wpdb->get_var("SELECT `id` FROM $rental_tag_relations WHERE `rental_id` = $rental_tag_id");
            if ( !$tag_id) {
                
                $args = [
                    'alias_of'    => '',
                    'description' => '',
                    'parent'      => 0,
                    'slug'        => $product_slug_tag,
                ];
                // insert product tag
                $cid = wp_insert_term($tag, 'product_tag', $args);
                $tag_id = is_wp_error($cid)? $cid->error_data["term_exists"]: $cid["term_taxonomy_id"];
                $tag_relations_sql[] = "($tag_id, $rental_tag_id)";
            }

            $tags_id[] = (integer) $tag_id;
        }
    }

    // relate product with tags
    wp_set_post_terms($product_id, $tags_id, 'product_tag');

    if ($tag_relations_sql) {
        $tag_relations_sql = implode(", ", $tag_relations_sql);
        $wpdb->query("INSERT INTO `$rental_tag_relations` (`id`, `rental_id`) VALUES $tag_relations_sql;");
        if ($wpdb->last_error !== '') {
            http_response_code(400);
            echo json_encode(["error" => "SQL_ERROR: $wpdb->last_error (SQL: $wpdb->last_query)"]);
            throw new RentalException("SQL_ERROR: $wpdb->last_error (SQL: $wpdb->last_query)");
        }
    }

    // Polylang: assign default language to all tag terms
    if ( ! empty( $tags_id ) ) {
        rental_pll_assign_terms( $tags_id );
        rental_translation_queue_terms( $tags_id );
    }

    return $tags_id;
}

function rental_create_set_tags($tags, $set_id, $product_tags = []) {
    global $wpdb, $rental_tables;
    $rental_sets_tag_relations = $wpdb->prefix . $rental_tables["sets_tag_relations"];

    $tags_id = [];
    $sets_tag_relations_sql = [];
    
    if ($tags) {
        foreach ($tags as $rental_tag_id => $tag) {

            $set_slug_tag = sanitize_title($tag);

            if ($product_tags) {
                foreach ($product_tags as $product_tag) {
                    $product_slug_tag = sanitize_title($product_tag->title);
        
                    if ($set_slug_tag == $product_slug_tag) {
                        $set_slug_tag = $set_slug_tag . '-sets';
                    }
                }
            }

            $args = [
                'alias_of'    => '',
                'description' => '',
                'parent'      => 0,
                'slug'        => $set_slug_tag,
            ];

            // get tag id
            $tag_id = (int)$wpdb->get_var("SELECT `id` FROM $rental_sets_tag_relations WHERE `rental_id` = $rental_tag_id");
            if ( !$tag_id) {
                
                // insert product tag
                $cid = wp_insert_term($tag, 'product_tag', $args);
                $tag_id = is_wp_error($cid)? $cid->error_data["term_exists"]: $cid["term_taxonomy_id"];
                $sets_tag_relations_sql[] = "($tag_id, $rental_tag_id)";
            }

            $tags_id[] = (integer) $tag_id;
        }
    }

    // relate product with set tags
    wp_set_post_terms($set_id, $tags_id, 'product_tag');

    if ($sets_tag_relations_sql) {
        $sets_tag_relations_sql = implode(", ", $sets_tag_relations_sql);
        $wpdb->query("INSERT INTO `$rental_sets_tag_relations` (`id`, `rental_id`) VALUES $sets_tag_relations_sql;");
        if ($wpdb->last_error !== '') {
            http_response_code(400);
            echo json_encode(["error" => "SQL_ERROR: $wpdb->last_error (SQL: $wpdb->last_query)"]);
            throw new RentalException("SQL_ERROR: $wpdb->last_error (SQL: $wpdb->last_query)");
        }
    }


    // Polylang: assign default language to all set tag terms
    if ( ! empty( $tags_id ) ) {
        rental_pll_assign_terms( $tags_id );
        rental_translation_queue_terms( $tags_id );
    }


    return $tags_id;
}

/**
 * Save New Shipping Zone
 */
function rentopian_save_shipping_zone($shipping_zone) {

    if (get_option('rental_do_not_use_rentopian_shipping') == 1) {
        http_response_code(200);
        echo json_encode([
            "message" => "Do not use Rentopian shipping is enabled."
        ]);
        return;
    }

    global $wpdb, $rental_tables;
    $rental_shipping_zone_relations = $wpdb->prefix . $rental_tables["shipping_zone_relations"];
    // $woocommerce_shipping_zones = $wpdb->prefix . 'woocommerce_shipping_zones';
    // $woocommerce_shipping_zone_locations = $wpdb->prefix . 'woocommerce_shipping_zone_locations';
    $woocommerce_shipping_zone_methods = $wpdb->prefix . 'woocommerce_shipping_zone_methods';
    
    $shipping_zone = json_decode(stripslashes($shipping_zone));
    if ( !$shipping_zone) {
        http_response_code(400);
        echo json_encode(["error" => "Shipping zone is empty."]);
        return;
    }

    $shipping_zone_id = $wpdb->get_var("SELECT `id` FROM $rental_shipping_zone_relations WHERE `rental_id` = $shipping_zone->id AND `rental_division_id` = $shipping_zone->division_id");

    if(!$shipping_zone_id){
        
        $shipping_zone_obj = new WC_Shipping_Zone();
        $shipping_zone_obj->set_zone_name($shipping_zone->country);
        $shipping_zone_obj->add_location($shipping_zone->country.':'.$shipping_zone->state, 'state');
        if( is_array($shipping_zone->zip) ){
            foreach( $shipping_zone->zip as $postcode){
                $shipping_zone_obj->add_location( $postcode, 'postcode');
            }
        }else{
            $shipping_zone_obj->add_location( $shipping_zone->zip, 'postcode');
        }
        // assign shipping method
        require_once RENTOPIAN_SYNC_PATH . '/includes/class-reduced-rate-shipping.php';
        // if(class_exists('WC_Reduced_Rate_Shipping_Method')){
        //     $shipping_method_obj = new WC_Reduced_Rate_Shipping_Method($shipping_zone->id);
        // }
        
        // $shipping_method_id = $shipping_zone_obj->add_shipping_method($shipping_method_obj);
        
        $shipping_zone_obj->save();
        $shipping_zone_id = $shipping_zone_obj->get_id();
        // $shipping_zone_id = $shipping_zone_obj->get_zone_id(); // deprecated
        

        // $shipping_zone_sql =  "($shipping_zone_id, '".$shipping_zone->country."', 0)";
        // $shipping_zone_locations_sql = [];

        // if ($shipping_zone->state) {
        //     $shipping_zone_locations_sql[] = "($shipping_zone_id, '{$shipping_zone->country}:{$shipping_zone->state}', 'state')";
        // } else {
        //     $shipping_zone_locations_sql[] = "($shipping_zone_id, '$shipping_zone->country', 'country')";
        // }
        // if ( !empty($shipping_zone->zip)) {
        //     if (is_array($shipping_zone->zip)) {
        //         foreach ($shipping_zone->zip as $postcode) {
        //             $shipping_zone_locations_sql[] = "($shipping_zone_id, '$postcode', 'postcode')";
        //         }
        //     } else {
        //         $shipping_zone_locations_sql[] = "($shipping_zone_id, '$shipping_zone->zip', 'postcode')";
        //     }
        // }
        // $shipping_zone_locations_sql = implode(", ", $shipping_zone_locations_sql);


        $shipping_zone_methods_sql = "($shipping_zone_id, $shipping_zone_id, 'reduced_rate', 1, 1)";
        
        $min_order_price = $shipping_zone->min_order_price;
        $shipping_rate = $shipping_zone->shipping_rate;
        if (empty($shipping_zone->min_order_price)) {
            if (!empty($shipping_zone->shipping_rate)) {
                // not syncing
                $min_order_price = $shipping_rate = null;
            }
        } else {
            if (empty($shipping_zone->shipping_rate)) {
                // not syncing
                $min_order_price = $shipping_rate = null;
            }
        }

        if (empty($shipping_zone->shipping_rate)) {
            if (!empty($shipping_zone->min_order_price)) {
                // not syncing
                $min_order_price = $shipping_rate = null;
            }
        } else {
            if (empty($shipping_zone->min_order_price)) {
                // not syncing
                $min_order_price = $shipping_rate = null;
            }
        }
        update_option( 'woocommerce_reduced_rate_'.$shipping_zone_id.'_settings', array(
            'min_order_price' => $min_order_price,
            'shipping_rate' => $shipping_rate,
            'regular_shipping_rate' => $shipping_zone->regular_shipping_rate
        ));

        $shipping_zone_relations_sql = "($shipping_zone_id, $shipping_zone->id, $shipping_zone->division_id)";
        
        $sql = "";
        
        // query to insert shipping zone
        // if ($shipping_zone_sql) {
        //     $sql .= "INSERT INTO `$woocommerce_shipping_zones` (`zone_id`, `zone_name`, `zone_order`) VALUES $shipping_zone_sql;";
        // }
        // query to insert shipping zone locations
        // if ($shipping_zone_locations_sql) {
        //     $sql .= "INSERT INTO `$woocommerce_shipping_zone_locations` (`zone_id`, `location_code`, `location_type`) VALUES $shipping_zone_locations_sql;";
        // }
        // query to insert shipping zone methods
        if ($shipping_zone_methods_sql) {
            $sql .= "INSERT INTO `$woocommerce_shipping_zone_methods` (`zone_id`, `instance_id`, `method_id`, `method_order`, `is_enabled`) VALUES $shipping_zone_methods_sql;";
        }

        // query to insert shipping zone relations
        if ($shipping_zone_relations_sql) {
            $sql .= "INSERT INTO `$rental_shipping_zone_relations` (`id`, `rental_id`, `rental_division_id`) VALUES $shipping_zone_relations_sql;";
        }

        if ($sql) {
            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
            dbDelta($sql);
        }

        if ($wpdb->last_error !== '') {
            http_response_code(400);
            echo json_encode(["error" => "SQL_ERROR: $wpdb->last_error (SQL: $wpdb->last_query)"]);
            throw new RentalException("SQL_ERROR: $wpdb->last_error (SQL: $wpdb->last_query)");
        }

        http_response_code(200);
        echo json_encode([
            "id" => $shipping_zone->id,
            "message" => "Shipping zone created successfully."
        ]);
        return;
    }
}

/**
 * Update Shipping Zone
 */
function rentopian_update_shipping_zone($shipping_zone) {

    if (get_option('rental_do_not_use_rentopian_shipping') == 1) {
        http_response_code(200);
        echo json_encode([
            "message" => "Do not use Rentopian shipping is enabled."
        ]);
        return;
    }

    global $wpdb, $rental_tables;
    $rental_shipping_zone_relations = $wpdb->prefix . $rental_tables["shipping_zone_relations"];
    $woocommerce_shipping_zones = $wpdb->prefix . 'woocommerce_shipping_zones';
    $woocommerce_shipping_zone_locations = $wpdb->prefix . 'woocommerce_shipping_zone_locations';
    $woocommerce_shipping_zone_methods = $wpdb->prefix . 'woocommerce_shipping_zone_methods';
    
    $shipping_zone = json_decode(stripslashes($shipping_zone));
    if ( !$shipping_zone) {
        http_response_code(400);
        echo json_encode(["error" => "Shipping zone is empty."]);
        return;
    }

    $shipping_zone_id = $wpdb->get_var("SELECT `id` FROM $rental_shipping_zone_relations WHERE `rental_id` = $shipping_zone->id AND `rental_division_id` = $shipping_zone->division_id");
    if(!$shipping_zone_id){
        http_response_code(404);
        echo json_encode(["error" => "Shipping zone with id $shipping_zone->id not found."]);
        return;
    }

    if($shipping_zone_id){
        
        $shipping_zone_sql =  "SET `zone_name`='".$shipping_zone->country."', `zone_order`=0 WHERE `zone_id`=$shipping_zone_id";

        $shipping_zone_locations_sql = $shipping_zone->state ?
            "($shipping_zone_id, '{$shipping_zone->country}:{$shipping_zone->state}', 'state')" :
            "($shipping_zone_id, '$shipping_zone->country', 'country')";
        if ( !empty($shipping_zone->zip)) {
            if (is_array($shipping_zone->zip)) {
                foreach ($shipping_zone->zip as $postcode) {
                    $shipping_zone_locations_sql .= ", ($shipping_zone_id, '$postcode', 'postcode')";
                }
            } else {
                $shipping_zone_locations_sql .= ", ($shipping_zone_id, '$shipping_zone->zip', 'postcode')";
            }
        }

        $shipping_zone_methods_sql = "SET `method_id`='reduced_rate', `is_enabled`=1 WHERE `zone_id`=$shipping_zone_id";

        $shipping_zone_relations_sql = "SET `rental_id`=$shipping_zone->id, `rental_division_id`=$shipping_zone->division_id WHERE `id`=$shipping_zone_id";

        $min_order_price = $shipping_zone->min_order_price;
        $shipping_rate = $shipping_zone->shipping_rate;
        if (empty($shipping_zone->min_order_price)) {
            if (!empty($shipping_zone->shipping_rate)) {
                // not syncing
                $min_order_price = $shipping_rate = null;
            }
        } else {
            if (empty($shipping_zone->shipping_rate)) {
                // not syncing
                $min_order_price = $shipping_rate = null;
            }
        }

        if (empty($shipping_zone->shipping_rate)) {
            if (!empty($shipping_zone->min_order_price)) {
                // not syncing
                $min_order_price = $shipping_rate = null;
            }
        } else {
            if (empty($shipping_zone->min_order_price)) {
                // not syncing
                $min_order_price = $shipping_rate = null;
            }
        }
        update_option('woocommerce_reduced_rate_' . $shipping_zone_id . '_settings', [
            'min_order_price' => $min_order_price,
            'shipping_rate' => $shipping_rate,
            'regular_shipping_rate' => $shipping_zone->regular_shipping_rate
        ]);
        
        $sql = "";

        // query to update shipping zone
        if ($shipping_zone_sql) {
            $sql .= "UPDATE `$woocommerce_shipping_zones` $shipping_zone_sql;";
        }
        // query to update shipping zone locations
        $wpdb->query("DELETE FROM `$woocommerce_shipping_zone_locations` WHERE `zone_id` = $shipping_zone_id");
        $wpdb->query("INSERT INTO `$woocommerce_shipping_zone_locations` (`zone_id`, `location_code`, `location_type`) VALUES $shipping_zone_locations_sql");
        
        // query to update shipping zone methods
        if ($shipping_zone_methods_sql) {
            $sql .= "UPDATE `$woocommerce_shipping_zone_methods` $shipping_zone_methods_sql;";
        }
        
        // query to update shipping zone relations
        if ($shipping_zone_relations_sql) {
            $sql .= "UPDATE `$rental_shipping_zone_relations` $shipping_zone_relations_sql;";
        }

        if ($sql) {
            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
            dbDelta($sql);
        }

        if ($wpdb->last_error !== '') {
            http_response_code(400);
            echo json_encode(["error" => "SQL_ERROR: $wpdb->last_error (SQL: $wpdb->last_query)"]);
            throw new RentalException("SQL_ERROR: $wpdb->last_error (SQL: $wpdb->last_query)");
        }

        http_response_code(200);
        echo json_encode([
            "id" => $shipping_zone->id,
            "message" => "Shipping zone updated successfully."
        ]);
        return;
    }
}

/**
 * Delete Shipping Zone
 */
function rentopian_delete_shipping_zone($shipping_zone) {

    if (get_option('rental_do_not_use_rentopian_shipping') == 1) {
        http_response_code(200);
        echo json_encode([
            "message" => "Do not use Rentopian shipping is enabled."
        ]);
        return;
    }

    global $wpdb, $rental_tables;
    $rental_shipping_zone_relations = $wpdb->prefix . $rental_tables["shipping_zone_relations"];
    $woocommerce_shipping_zones = $wpdb->prefix . 'woocommerce_shipping_zones';
    $woocommerce_shipping_zone_locations = $wpdb->prefix . 'woocommerce_shipping_zone_locations';
    $woocommerce_shipping_zone_methods = $wpdb->prefix . 'woocommerce_shipping_zone_methods';
    
    $shipping_zone = json_decode(stripslashes($shipping_zone));
    if ( !$shipping_zone) {
        http_response_code(400);
        echo json_encode(["error" => "Shipping zone is empty."]);
        return;
    }

    $shipping_zone_id = $wpdb->get_var("SELECT `id` FROM $rental_shipping_zone_relations WHERE `rental_id` = $shipping_zone->id AND `rental_division_id` = $shipping_zone->division_id");
    if(!$shipping_zone_id){
        http_response_code(404);
        echo json_encode(["error" => "Shipping zone with id $shipping_zone->id not found."]);
        return;
    }

    $condition =  "WHERE `zone_id` = $shipping_zone_id";

    // query to delete shipping zone locations
    $wpdb->query("DELETE FROM `$woocommerce_shipping_zone_locations` $condition");
    // query to delete shipping zone methods
    $wpdb->query("DELETE FROM `$woocommerce_shipping_zone_methods` $condition");
    // query to delete shipping zone
    $wpdb->query("DELETE FROM `$woocommerce_shipping_zones` $condition");
    // query to delete shipping zone relations
    $wpdb->query("DELETE FROM `$rental_shipping_zone_relations` WHERE `id` = $shipping_zone_id");

    if ($wpdb->last_error !== '') {
        http_response_code(400);
        echo json_encode(["error" => "SQL_ERROR: $wpdb->last_error (SQL: $wpdb->last_query)"]);
        throw new RentalException("SQL_ERROR: $wpdb->last_error (SQL: $wpdb->last_query)");
    }

    http_response_code(200);
    echo json_encode([
        "id" => $shipping_zone->id,
        "message" => "Shipping zone deleted successfully."
    ]);
    return;
}
/**
 * Update shipping settings
 */
function rentopian_update_shipping_settings($shipping_settings){

    if (get_option('rental_do_not_use_rentopian_shipping') == 1) {
        http_response_code(200);
        echo json_encode([
            "message" => "Do not use Rentopian shipping is enabled."
        ]);
        return;
    }
    
    // global $wpdb, $rental_tables;
    // $rental_shipping_zone_relations = $wpdb->prefix . $rental_tables["shipping_zone_relations"];

    $shipping_settings = json_decode(stripslashes($shipping_settings));
    // If settings empty send error
    if ( !$shipping_settings) {
        http_response_code(400);
        echo json_encode(["error" => "Shipping settings is empty."]);
        return;
    }

    update_option('rental_shipping_settings', $shipping_settings);

    // removing all shipping zones
    rental_empty_shipping_zones();

    // if ($shipping_settings->shipping_by === 'mile' || $shipping_settings->shipping_by === 'kilometre') {

        // $shipping_title = $shipping_settings && $shipping_settings->shipping_by_title ? $shipping_settings->shipping_by_title : 'Miles';

        // update_option('woocommerce_miles_based_settings', array(
        //     'enabled'           =>  'yes',
        //     'title'             =>  $shipping_title . ' Based Shipping',
        //     'first_miles'       =>  $shipping_settings->min_miles,
        //     'first_miles_rate'  =>  $shipping_settings->min_miles_cost,
        //     'per_mile_rate'     =>  $shipping_settings->per_mile_cost,
        // ));

        // update_option('woocommerce_enable_shipping_calc', 'no');

        // if (isset($shipping_settings->google_map_key) && $shipping_settings->google_map_key) {
        //     update_option('rental_google_distance_key', $shipping_settings->google_map_key);
        // }

    // } else {

        // set max_execution_time
        // set_time_limit(60);

        // update_option('woocommerce_miles_based_settings', array(
        //     'enabled'           =>  'no',
        // ));

        // $api_key = get_option('rental_api_key');
        // $shipping_zones = rental_curl('shipping/zones', $api_key);
        // if ( !empty($shipping_zones)) {
        //     /** Arrays to hold shipping zone related sqls */
        //     $shipping_zone_sql = [];
        //     $shipping_zone_locations_sql = [];
        //     $shipping_zone_methods_sql = [];
        //     $shipping_zone_options_sql = [];
        //     $shipping_zone_relations_sql = [];

        //     foreach($shipping_zones as $zone){

        //         $shipping_zone_sql[] =  "($zone->id, '".$zone->country."', 0)";

        //         if ($zone->state) {
        //             $shipping_zone_locations_sql[] = "($zone->id, '{$zone->country}:{$zone->state}', 'state')";
        //         } else {
        //             $shipping_zone_locations_sql[] = "($zone->id, '$zone->country', 'country')";
        //         }
        //         if ( !empty($zone->zip)) {
        //             if (is_array($zone->zip)) {
        //                 foreach ($zone->zip as $postcode) {
        //                     $shipping_zone_locations_sql[] = "($zone->id, '$postcode', 'postcode')";
        //                 }
        //             } else {
        //                 $shipping_zone_locations_sql[] = "($zone->id, '$zone->zip', 'postcode')";
        //             }
        //         }

        //         $shipping_zone_methods_sql[] = "($zone->id, $zone->id, 'reduced_rate', 1, 1)";

        //         $shipping_zone_options_sql[] = "('woocommerce_reduced_rate_" . $zone->id . "_settings', '" . addslashes(serialize([
        //                 'min_order_price' => $zone->min_order_price,
        //                 'shipping_rate' => $zone->shipping_rate,
        //                 'regular_shipping_rate' => $zone->regular_shipping_rate
        //             ])) . "')";

        //         $shipping_zone_relations_sql[] = "($zone->id, $zone->id, $zone->division_id)";
        //     }

        //     /**
        //      * shipping zone relations insert queries
        //      */
        //     $woocommerce_shipping_zones = $wpdb->prefix . 'woocommerce_shipping_zones';
        //     $woocommerce_shipping_zone_locations = $wpdb->prefix . 'woocommerce_shipping_zone_locations';
        //     $woocommerce_shipping_zone_methods = $wpdb->prefix . 'woocommerce_shipping_zone_methods';
        //     // query to insert shipping zone
        //     $wpdb->query("INSERT INTO `$woocommerce_shipping_zones` (`zone_id`, `zone_name`, `zone_order`) VALUES " . implode(", ", $shipping_zone_sql));
        //     // query to insert shipping zone locations
        //     $wpdb->query("INSERT INTO `$woocommerce_shipping_zone_locations` (`zone_id`, `location_code`, `location_type`) VALUES " . implode(", ", $shipping_zone_locations_sql));
        //     // query to insert shipping zone methods
        //     $wpdb->query("INSERT INTO `$woocommerce_shipping_zone_methods` (`zone_id`, `instance_id`, `method_id`, `method_order`, `is_enabled`) VALUES " . implode(", ", $shipping_zone_methods_sql));
        //     // query to insert shipping zone options
        //     $wpdb->query("INSERT INTO `$wpdb->options` (`option_name`, `option_value`) VALUES " . implode(", ", $shipping_zone_options_sql));
        //     // query to insert shipping zone relations
        //     $wpdb->query("INSERT INTO `$rental_shipping_zone_relations` (`id`, `rental_id`, `rental_division_id`) VALUES " . implode(", ", $shipping_zone_relations_sql));

        //     if ($wpdb->last_error !== '') {
        //         http_response_code(400);
        //         echo json_encode(["error" => "SQL_ERROR: $wpdb->last_error (SQL: $wpdb->last_query)"]);
        //         throw new RentalException("SQL_ERROR: $wpdb->last_error (SQL: $wpdb->last_query)");
        //     }
        // }
    // }

    // to bypass shipping rate cache
    // update_option('woocommerce_shipping_debug_mode', 'yes');

    rental_set_no_customer_pickup_items($shipping_settings);

    $delivery_time_selections = isset($shipping_settings->delivery_time_selections) && $shipping_settings->delivery_time_selections ? $shipping_settings->delivery_time_selections : [];
    update_option('delivery_time_selections', $delivery_time_selections);

    http_response_code(200);
    echo json_encode([
        "message" => "Shipping settings updated successfully."
    ]);
    return;
}

function rentopian_retry_failed_order() {
    $order_id = isset($_POST['wp_order_id']) ? (int) $_POST['wp_order_id'] : 0;
    $recovery_api_log_id = isset($_POST['recovery_api_log_id'])
        ? (int) $_POST['recovery_api_log_id']
        : 0;

    $result = rental_resend_order_internal($order_id, $recovery_api_log_id);

    if (!$result['success'] && $result['code'] !== 200) {
        http_response_code($result['code']);
    } else {
        http_response_code(200);
    }

    header('Content-Type: application/json; charset=utf-8');

    echo json_encode([
        'success'             => $result['success'],
        'log'                 => $result['log'],
        'message'             => $result['message'],
        'recovery_api_log_id' => $recovery_api_log_id,
    ]);
    return;
}


/**
 * Self-healing image repair for products, variants, and sets.
 *
 * Scans published WooCommerce products/sets and their variations for missing
 * or broken _thumbnail_id references. A _thumbnail_id is considered broken when:
 *   1. It is empty or zero.
 *   2. It points to a WP attachment that no longer exists (deleted media).
 *   3. It contains a Rentopian rental ID instead of a WP attachment ID
 *
 * For each broken reference the function attempts repair using only data that
 * already exists on the WP side —
 * the image_relations mapping table.  When no local data can resolve the issue
 * the post is reported in the "skipped" list so a product/images webhook can be
 * triggered from Rentopian.
 *
 * Called via the webhook API:  action = images/heal
 *
 * Optional POST parameters:
 *   - limit   (int)  Max posts to scan.  Default 50, ceiling 200.
 *   - post_id (int)  Restrict scan to one product/set (+ its variations).
 *
 * @return void  Outputs JSON with repaired / skipped / errors arrays.
 */
function rentopian_heal_broken_images() {
    global $wpdb, $rental_tables;

    set_time_limit(300);

    $rental_image_relations   = $wpdb->prefix . $rental_tables["image_relations"];
    $rental_product_relations = $wpdb->prefix . $rental_tables["product_relations"];
    $rental_variant_relations = $wpdb->prefix . $rental_tables["variant_relations"];
    $rental_set_relations     = $wpdb->prefix . $rental_tables["set_relations"];

    $limit   = isset($_POST['limit'])   ? min( (int) $_POST['limit'], 200 ) : 50;
    $post_id = isset($_POST['post_id']) ? (int) $_POST['post_id']          : 0;

    // Accept Rentopian entity IDs and resolve to WP post IDs for targeted healing
    $rental_product_id = isset($_POST['rental_product_id']) ? (int) $_POST['rental_product_id'] : 0;
    $rental_variant_id = isset($_POST['rental_variant_id']) ? (int) $_POST['rental_variant_id'] : 0;
    $rental_set_id     = isset($_POST['rental_set_id'])     ? (int) $_POST['rental_set_id']     : 0;
    $division_id       = isset($_POST['division_id'])       ? (int) $_POST['division_id']       : 0;

    // Resolve rental IDs → WP post IDs (only if post_id not already provided)
    if (!$post_id) {
        if ($rental_product_id) {
            $where_div = $division_id
                ? $wpdb->prepare(" AND `rental_division_id` = %d", $division_id)
                : "";
            $post_id = (int) $wpdb->get_var(
                "SELECT `id` FROM `$rental_product_relations` WHERE `rental_id` = $rental_product_id" . $where_div
            );
        } elseif ($rental_variant_id) {
            $where_div = $division_id
                ? $wpdb->prepare(" AND `rental_division_id` = %d", $division_id)
                : "";
            $resolved_variant = (int) $wpdb->get_var(
                "SELECT `id` FROM `$rental_variant_relations` WHERE `rental_id` = $rental_variant_id" . $where_div
            );
            // Use the variant's parent product as post_id so the query covers
            // both the parent product and all its variations
            if ($resolved_variant) {
                $post_id = (int) wp_get_post_parent_id($resolved_variant);
                if (!$post_id) {
                    $post_id = $resolved_variant; // orphan variation, target it directly
                }
            }
        } elseif ($rental_set_id) {
            $post_id = (int) $wpdb->get_var(
                "SELECT `id` FROM `$rental_set_relations` WHERE `rental_id` = $rental_set_id"
            );
        }
    }

    $repaired = [];
    $skipped  = [];
    $errors   = [];

    // Collect candidate posts
    $where = "p.post_status = 'publish' AND p.post_type IN ('product','product_variation')";
    if ( $post_id ) {
        $where .= $wpdb->prepare( " AND ( p.ID = %d OR p.post_parent = %d )", $post_id, $post_id );
    }

    $sql = "SELECT p.ID, p.post_type, p.post_parent,
                   pm.meta_value AS thumbnail_id
            FROM `$wpdb->posts` p
            LEFT JOIN `$wpdb->postmeta` pm
                   ON pm.post_id = p.ID AND pm.meta_key = '_thumbnail_id'
            WHERE $where
            LIMIT $limit";

    $candidates = $wpdb->get_results( $sql );

    if ( empty( $candidates ) ) {
        http_response_code(200);
        echo json_encode([
            "message"  => "No candidates found.",
            "repaired" => [],
            "skipped"  => [],
            "errors"   => [],
        ]);
        return;
    }

    // Cache of attachment-existence checks to avoid repeated queries
    $attachment_cache = [];

    /**
     * Helper: returns true when $id is a valid WP attachment.
     */
    $attachment_exists = function ( $id ) use ( $wpdb, &$attachment_cache ) {
        $id = (int) $id;
        if ( $id < 1 ) return false;
        if ( isset( $attachment_cache[ $id ] ) ) return $attachment_cache[ $id ];
        $found = (bool) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT ID FROM `$wpdb->posts` WHERE ID = %d AND post_type = 'attachment'",
                $id
            )
        );
        $attachment_cache[ $id ] = $found;
        return $found;
    };

    /**
     * Helper: clear WC transients for a post and its parent.
     */
    $clear_cache_for = function ( $wp_id, $parent_id = 0 ) {
        if ( function_exists( 'wc_delete_product_transients' ) ) {
            wc_delete_product_transients( $wp_id );
            if ( $parent_id ) {
                wc_delete_product_transients( $parent_id );
            }
        }
    };

    foreach ( $candidates as $post ) {
        $wp_id       = (int) $post->ID;
        $thumb_value = $post->thumbnail_id;
        $parent_id   = (int) $post->post_parent;

        // Is the thumbnail healthy?
        $needs_repair  = false;
        $repair_reason = '';

        if ( empty( $thumb_value ) || $thumb_value === '0' ) {
            $needs_repair  = true;
            $repair_reason = 'empty_thumbnail';
        } elseif ( ! $attachment_exists( $thumb_value ) ) {
            // Possibly a Rentopian rental_id stored by mistake
            $mapped_wp_id = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT id FROM `$rental_image_relations` WHERE rental_id = %d",
                    (int) $thumb_value
                )
            );
            if ( $mapped_wp_id && $attachment_exists( $mapped_wp_id ) ) {
                update_post_meta( $wp_id, '_thumbnail_id', $mapped_wp_id );
                $clear_cache_for( $wp_id, $parent_id );
                $repaired[] = [
                    'post_id' => $wp_id,
                    'reason'  => 'rental_id_stored_as_thumbnail',
                    'old'     => $thumb_value,
                    'new'     => $mapped_wp_id,
                ];
                continue;
            }
            $needs_repair  = true;
            $repair_reason = 'attachment_missing';
        }

        if ( ! $needs_repair ) {
            continue;
        }

        // Attempt local repair strategy
        // Look up the rental_id for this post and resolve via image_relations
        $rental_img_id = 0;

        // For products — try to find the rental product's img_id
        if ( $post->post_type === 'product' ) {
            $rental_row = $wpdb->get_row( $wpdb->prepare(
                "SELECT rental_id, rental_division_id FROM `$rental_product_relations` WHERE id = %d",
                $wp_id
            ));

            if ( $rental_row ) {
                // The product's img_id would have been stored when product was created.
                // We can find it by looking at what image_relations exist for images
                // that were originally attached to this WP post.
                $attached_img = $wpdb->get_var( $wpdb->prepare(
                    "SELECT ir.id
                     FROM `$rental_image_relations` ir
                     INNER JOIN `$wpdb->posts` att ON att.ID = ir.id
                     WHERE att.post_type = 'attachment'
                       AND att.post_parent = %d
                     LIMIT 1",
                    $wp_id
                ));
                if ( $attached_img && $attachment_exists( $attached_img ) ) {
                    update_post_meta( $wp_id, '_thumbnail_id', $attached_img );
                    $clear_cache_for( $wp_id );
                    $repaired[] = [
                        'post_id' => $wp_id,
                        'reason'  => 'resolved_from_attached_media',
                        'old'     => $thumb_value,
                        'new'     => $attached_img,
                    ];
                    continue;
                }
            }
        }

        // For variations — check the rental variant's img_id via image_relations
        if ( $post->post_type === 'product_variation' ) {
            $variant_rental_img = $wpdb->get_var( $wpdb->prepare(
                "SELECT ir.id
                 FROM `$rental_image_relations` ir
                 INNER JOIN `$wpdb->posts` att ON att.ID = ir.id
                 WHERE att.post_type = 'attachment'
                   AND att.post_parent = %d
                 LIMIT 1",
                $parent_id ? $parent_id : $wp_id
            ));
            if ( $variant_rental_img && $attachment_exists( $variant_rental_img ) ) {
                update_post_meta( $wp_id, '_thumbnail_id', $variant_rental_img );
                $clear_cache_for( $wp_id, $parent_id );
                $repaired[] = [
                    'post_id' => $wp_id,
                    'reason'  => 'resolved_variant_from_parent_media',
                    'old'     => $thumb_value,
                    'new'     => $variant_rental_img,
                ];
                continue;
            }
        }

        // No local repair possible
        $skipped[] = [
            'post_id' => $wp_id,
            'type'    => $post->post_type,
            'reason'  => $repair_reason,
            'note'    => 'No local image data available. Trigger product/images webhook from Rentopian to re-sync.',
        ];
    }

    rental_clear_cache();

    http_response_code(200);
    echo json_encode([
        "message"  => sprintf( "Scan complete. Repaired: %d, Skipped: %d, Errors: %d.",
                                count($repaired), count($skipped), count($errors) ),
        "repaired" => $repaired,
        "skipped"  => $skipped,
        "errors"   => $errors,
    ]);
    return;
}