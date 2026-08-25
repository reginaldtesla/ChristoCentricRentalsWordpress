<?php
/**
 * @link       https://rentopian.com
 * @since      1.0.0
 *
 * @package    rentopian-sync
 */

global $rental_api_url, $rental_tables;
// rentopian api url
$rental_api_url = 'https://account.rentopian.com/api/v1';
// $rental_api_url = 'https://staging2-proxy.rentopian.com/api/v1'; // to test Sync / Webhooks with staging2.rentopian.com
// $rental_api_url = 'http://host.docker.internal/api/v1';
// $rental_api_url = 'https://debug2-proxy.rentopian.com/api/v1'; // to test Sync / Webhooks with debug2.rentopian.com


// tables names
$rental_tables = [
    "error_log" => "rental_error_log",
    "file_sync_log" => "rental_file_sync_log",
    "failed_images" => "rental_failed_images",
    "product_relations" => "rental_product_relations",
    "variant_relations" => "rental_variant_relations",
    "set_relations" => "rental_set_relations",
    "category_relations" => "rental_category_relations",
    "tag_relations" => "rental_tag_relations",
    "sets_tag_relations" => "rental_sets_tag_relations",
    "attribute_relations" => "rental_attribute_relations",
    "attribute_value_relations" => "rental_attribute_value_relations",
    "attribute_value_groups" => "rental_attribute_value_groups",
    "brand_relations" => "rental_brand_relations",
    "image_relations" => "rental_image_relations",
    "order_relations" => "rental_order_relations",
    "day_tiers" => "rental_day_tiers",
    "shipping_zone_relations" => "rental_shipping_zone_relations",
    "coupon_relations" => "rental_coupon_relations",
    "price_multipliers" => "rental_price_multipliers",
    "product_options" => "rental_product_options",
    "product_option_relations" => "rental_product_option_relations",
    "inventory_blocks" => "rental_inventory_blocks",
    "inventory_block_relations" => "rental_inventory_block_relations",
    "lead_relations" => "rental_lead_relations",
    "set_options" => "rental_set_options",
    "set_option_relations" => "rental_set_option_relations",
];

const RELATION_LOGISTICS_CLIENT_PICK_UP_CLIENT_RETURN = 1;
const RELATION_LOGISTICS_CLIENT_PICK_UP_COMPANY_RETURN = 2;
const RELATION_LOGISTICS_COMPANY_PICK_UP_CLIENT_RETURN = 3;
const RELATION_LOGISTICS_COMPANY_PICK_UP_COMPANY_RETURN = 4;

// create all necessary tables
function rental_create_tables() {
    global $wpdb, $rental_tables;
    $rental_error_log = $wpdb->prefix . $rental_tables["error_log"];
    $rental_file_sync_log = $wpdb->prefix . $rental_tables["file_sync_log"];
    $rental_failed_images = $wpdb->prefix . $rental_tables["failed_images"];
    $rental_product_relations = $wpdb->prefix . $rental_tables["product_relations"];
    $rental_variant_relations = $wpdb->prefix . $rental_tables["variant_relations"];
    $rental_set_relations = $wpdb->prefix . $rental_tables["set_relations"];
    $rental_category_relations = $wpdb->prefix . $rental_tables["category_relations"];
    $rental_tag_relations = $wpdb->prefix . $rental_tables["tag_relations"];
    $rental_sets_tag_relations = $wpdb->prefix . $rental_tables["sets_tag_relations"];
    $rental_attribute_relations = $wpdb->prefix . $rental_tables["attribute_relations"];
    $rental_attribute_value_relations = $wpdb->prefix . $rental_tables["attribute_value_relations"];
    $rental_attribute_value_groups = $wpdb->prefix . $rental_tables["attribute_value_groups"];
    $rental_brand_relations = $wpdb->prefix . $rental_tables["brand_relations"];
    $rental_image_relations = $wpdb->prefix . $rental_tables["image_relations"];
    $rental_order_relations = $wpdb->prefix . $rental_tables["order_relations"];
    $rental_day_tiers = $wpdb->prefix . $rental_tables["day_tiers"];
    $rental_shipping_zone_relations = $wpdb->prefix . $rental_tables["shipping_zone_relations"];
    $rental_coupon_relations = $wpdb->prefix . $rental_tables["coupon_relations"];
    $rental_price_multipliers = $wpdb->prefix . $rental_tables["price_multipliers"];
    $rental_product_options = $wpdb->prefix . $rental_tables["product_options"];
    $rental_product_option_relations = $wpdb->prefix . $rental_tables["product_option_relations"];
    $rental_inventory_blocks = $wpdb->prefix . $rental_tables["inventory_blocks"];
    $rental_inventory_block_relations = $wpdb->prefix . $rental_tables["inventory_block_relations"];
    $rental_lead_relations = $wpdb->prefix . $rental_tables["lead_relations"];
    $rental_set_options = $wpdb->prefix . $rental_tables["set_options"];
    $rental_set_option_relations = $wpdb->prefix . $rental_tables["set_option_relations"];

    $sql = "";
    if ($wpdb->get_var("show tables like '$rental_error_log'") != $rental_error_log) {
        $sql .= "CREATE TABLE $rental_error_log (
          id BIGINT(11) NOT NULL AUTO_INCREMENT,
          status VARCHAR(255) NOT NULL,
          url VARCHAR(255),
          file VARCHAR(255),
          line VARCHAR(255),
          message TEXT,
          type SMALLINT,
          sync_time BIGINT(20),
          register_time BIGINT(20),
          data LONGTEXT,
          PRIMARY KEY id (id)
        );";
    }


    if ($wpdb->get_var("SHOW TABLES LIKE '$rental_file_sync_log'") != $rental_file_sync_log) {

        $sql .= "CREATE TABLE $rental_file_sync_log (
            id BIGINT(20) NOT NULL AUTO_INCREMENT,
            sync_id VARCHAR(64) NOT NULL,           -- UUID/string that identifies a sync run
            level VARCHAR(20) NOT NULL DEFAULT 'info', -- 'info', 'warning', 'error'
            status TINYINT(3) DEFAULT 0,            -- numeric status (1=created, 2=processing, 3=completed, 4=failed, 5=canceled)
            mode TINYINT(3) DEFAULT 1,            -- numeric status (1=sync,2=resync)
            message TEXT,
            data LONGTEXT,                          -- serialized or json payload for details
            last_index BIGINT(20) DEFAULT 0,        -- last processed rental image id in this entry
            processed_count BIGINT(20) DEFAULT 0,   -- processed count snapshot
            total_count BIGINT(20) DEFAULT 0,       -- total images
            elapsed DOUBLE DEFAULT 0,               -- seconds
            register_time BIGINT(20) NOT NULL,      -- epoch timestamp (seconds)
            PRIMARY KEY (id),
            INDEX idx_sync_id (sync_id),
            INDEX idx_register_time (register_time)
        );";
        // CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci

    } elseif ($wpdb->get_var("SHOW COLUMNS FROM `$rental_file_sync_log` WHERE Field = 'mode'") != 'mode') {
        $wpdb->query("ALTER TABLE `$rental_file_sync_log` ADD COLUMN mode TINYINT(3) DEFAULT 1");
    }

    $rental_failed_images = $wpdb->prefix . $rental_tables['failed_images'];
    if ($wpdb->get_var("SHOW TABLES LIKE '$rental_failed_images'") != $rental_failed_images) {
        $sql .= "CREATE TABLE $rental_failed_images (
            id BIGINT(20) NOT NULL AUTO_INCREMENT,
            sync_id VARCHAR(64) DEFAULT NULL,      -- which sync recorded this failure
            rental_id BIGINT(20) NOT NULL,         -- rental image id
            fail_count INT(11) DEFAULT 1,          -- how many times we've failed to process this id
            last_error TEXT DEFAULT NULL,          -- last error message
            first_failed_at BIGINT(20) DEFAULT 0,  -- epoch seconds
            last_failed_at BIGINT(20) DEFAULT 0,   -- epoch seconds
            resolved TINYINT(1) DEFAULT 0,         -- 0 = unresolved (still failed), 1 = removed/succeeded
            resolved_at BIGINT(20) DEFAULT NULL,
            UNIQUE KEY uk_rental_id (rental_id),
            INDEX idx_sync_id (sync_id),
            INDEX idx_resolved (resolved),
            PRIMARY KEY (id)
        );";
    }

    if ($wpdb->get_var("show tables like '$rental_product_relations'") != $rental_product_relations) {
        $sql .= "CREATE TABLE $rental_product_relations (
          id bigint(11) NOT NULL,
          rental_id bigint(11) NOT NULL,
          rental_division_id bigint(11) NOT NULL,
          UNIQUE KEY id (id)
        );";
    } elseif ($wpdb->get_var("SHOW COLUMNS FROM `$rental_product_relations` WHERE Field = 'rental_division_id'") != 'rental_division_id') {
        $wpdb->query("ALTER TABLE `$rental_product_relations` ADD COLUMN rental_division_id bigint(11) NOT NULL");
    }
    if ($wpdb->get_var("show tables like '$rental_variant_relations'") != $rental_variant_relations) {
        $sql .= "CREATE TABLE $rental_variant_relations (
          id bigint(11) NOT NULL,
          rental_id bigint(11) NOT NULL,
          rental_division_id bigint(11) NOT NULL,
          UNIQUE KEY id (id)
        );";
    } elseif ($wpdb->get_var("SHOW COLUMNS FROM `$rental_variant_relations` WHERE Field = 'rental_division_id'") != 'rental_division_id') {
        $wpdb->query("ALTER TABLE `$rental_variant_relations` ADD COLUMN rental_division_id bigint(11) NOT NULL");
    }
    if ($wpdb->get_var("show tables like '$rental_set_relations'") != $rental_set_relations) {
        $sql .= "CREATE TABLE $rental_set_relations (
          id bigint(11) NOT NULL,
          rental_id bigint(11) NOT NULL,
          rental_division_id bigint(11) NOT NULL,
          UNIQUE KEY id (id)
        );";
    } elseif ($wpdb->get_var("SHOW COLUMNS FROM `$rental_set_relations` WHERE Field = 'rental_division_id'") != 'rental_division_id') {
        $wpdb->query("ALTER TABLE `$rental_set_relations` ADD COLUMN rental_division_id bigint(11) NOT NULL");
    }
    if ($wpdb->get_var("show tables like '$rental_category_relations'") != $rental_category_relations) {
        $sql .= "CREATE TABLE $rental_category_relations (
          id bigint(11) NOT NULL,
          rental_id bigint(11) NOT NULL,
          UNIQUE KEY id (id)
        );";
    }
    if ($wpdb->get_var("show tables like '$rental_tag_relations'") != $rental_tag_relations) {
        $sql .= "CREATE TABLE $rental_tag_relations (
          id bigint(11) NOT NULL,
          rental_id bigint(11) NOT NULL,
          UNIQUE KEY id (id)
        );";
    }
    
    if ($wpdb->get_var("show tables like '$rental_sets_tag_relations'") != $rental_sets_tag_relations) {
        $sql .= "CREATE TABLE $rental_sets_tag_relations (
          id bigint(11) NOT NULL,
          rental_id bigint(11) NOT NULL,
          UNIQUE KEY id (id)
        );";
    }

    if ($wpdb->get_var("show tables like '$rental_attribute_relations'") != $rental_attribute_relations) {
        $sql .= "CREATE TABLE $rental_attribute_relations (
          id bigint(11) NOT NULL,
          rental_id bigint(11) NOT NULL,
          UNIQUE KEY id (id)
        );";
    }
    // create rental_attribute_value_relations table
    // id : term_id of the attribute value, rental_id : Rentopian value id
    if ($wpdb->get_var("show tables like '$rental_attribute_value_relations'") != $rental_attribute_value_relations) {
        $sql .= "CREATE TABLE $rental_attribute_value_relations (
          id bigint(11) NOT NULL,
          rental_id bigint(11) NOT NULL,
          rental_attribute_id bigint(11) NOT NULL,
          is_group tinyint(1) NOT NULL DEFAULT 0,
          UNIQUE KEY id (id),
          UNIQUE KEY rental_id (rental_id),
          KEY attribute_lookup (rental_attribute_id, is_group)
        );";
    }
    // create rental_attribute_value_groups table
    // both sides are Rentopian value ids, so membership survives a term rebuild
    if ($wpdb->get_var("show tables like '$rental_attribute_value_groups'") != $rental_attribute_value_groups) {
        $sql .= "CREATE TABLE $rental_attribute_value_groups (
          group_rental_id bigint(11) NOT NULL,
          member_rental_id bigint(11) NOT NULL,
          rental_attribute_id bigint(11) NOT NULL,
          UNIQUE KEY membership (group_rental_id, member_rental_id),
          KEY member_lookup (member_rental_id),
          KEY attribute_lookup (rental_attribute_id)
        );";
    }
    if ($wpdb->get_var("show tables like '$rental_brand_relations'") != $rental_brand_relations) {
        $sql .= "CREATE TABLE $rental_brand_relations (
          id bigint(11) NOT NULL,
          rental_id bigint(11) NOT NULL,
          UNIQUE KEY id (id)
        );";
    }
    
    if ($wpdb->get_var("show tables like '$rental_image_relations'") != $rental_image_relations) {

        $sql .= "CREATE TABLE $rental_image_relations (
          id bigint(11) NOT NULL,
          rental_id bigint(11) NOT NULL,
          UNIQUE KEY id (id),
          UNIQUE KEY rental_id (rental_id)
        );";

    } else {
        // Ensure indexes exist / are correct (id unique, rental_id unique)

        $idx_rows = $wpdb->get_results( "SHOW INDEX FROM `$rental_image_relations`", ARRAY_A );

        $has_unique_rental_id_single   = false;
        $has_nonunique_rental_id_single_named = false;

        // Map indexes
        $byKey = [];
        foreach ( $idx_rows as $r ) {
            $byKey[$r['Key_name']][] = $r;
        }

        foreach ( $byKey as $keyName => $rows ) {
            // columns in this index in order
            usort($rows, static fn($a,$b)=> (int)$a['Seq_in_index'] <=> (int)$b['Seq_in_index']);
            $cols = array_map(static fn($r)=> $r['Column_name'], $rows);
            $nonUnique = (int)$rows[0]['Non_unique']; // 0 = unique

            if (count($cols) === 1 && $cols[0] === 'rental_id') {
                if ($nonUnique === 0) $has_unique_rental_id_single = true;
                if ($nonUnique === 1 && $keyName === 'rental_id') {
                    $has_nonunique_rental_id_single_named = true;
                }
            }
        }

        $alter = [];

        // Ensure UNIQUE KEY `rental_id` (`rental_id`)
        if ( !$has_unique_rental_id_single ) {
            if ( $has_nonunique_rental_id_single_named ) {
                $alter[] = "DROP INDEX `rental_id`";
            }
            $alter[] = "ADD UNIQUE KEY `rental_id` (`rental_id`)";
        }

        if ( !empty($alter) ) {
            $sql_alter = "ALTER TABLE `$rental_image_relations` " . implode(', ', $alter);
            $wpdb->query($sql_alter);
        }
    }

    if ($wpdb->get_var("show tables like '$rental_order_relations'") != $rental_order_relations) {
        $sql .= "CREATE TABLE $rental_order_relations (
          id BIGINT(11) NOT NULL,
          rental_id BIGINT(11) NOT NULL,
          http_code SMALLINT(255),
          message TEXT,
          data LONGTEXT,
          register_time BIGINT(20),
          version VARCHAR(255),
          UNIQUE KEY id (id)
        );";
    } else {
        $columns = [];
        foreach ($wpdb->get_results("SHOW COLUMNS FROM `$rental_order_relations`") as $column) {
            $columns[] = $column->Field;
        }
        $add_columns_sql = "";
        if ( !in_array("http_code", $columns)) {
            $add_columns_sql = "ADD COLUMN http_code SMALLINT(255)";
        }
        if ( !in_array("message", $columns)) {
            $add_columns_sql .= ($add_columns_sql? ", ": "") . "ADD COLUMN message TEXT";
        }
        if ( !in_array("data", $columns)) {
            $add_columns_sql .= ($add_columns_sql? ", ": "") . "ADD COLUMN data LONGTEXT";
        }
        if ( !in_array("register_time", $columns)) {
            $add_columns_sql .= ($add_columns_sql? ", ": "") . "ADD COLUMN register_time BIGINT(20)";
        }
        if ( !in_array("version", $columns)) {
            $add_columns_sql .= ($add_columns_sql? ", ": "") . "ADD COLUMN version VARCHAR(255)";
        }
        if ($add_columns_sql) {
            $wpdb->query("ALTER TABLE `$rental_order_relations` $add_columns_sql");
        }
    }
    if ($wpdb->get_var("show tables like '$rental_day_tiers'") != $rental_day_tiers) {
        $sql .= "CREATE TABLE $rental_day_tiers (
          id BIGINT(11) NOT NULL AUTO_INCREMENT,
          min INT NOT NULL,
          max INT NOT NULL,
          day INT NOT NULL,
          PRIMARY KEY id (id)
        );";
    }
    // create shipping_zone_relations table
    if ($wpdb->get_var("show tables like '$rental_shipping_zone_relations'") != $rental_shipping_zone_relations) {
        $sql .= "CREATE TABLE $rental_shipping_zone_relations (
            id bigint(11) NOT NULL,
            rental_id bigint(11) NOT NULL,
            rental_division_id bigint(11) NOT NULL,
            UNIQUE KEY id (id)
        );";
    }
    // create rental_coupon_relations table
    if ($wpdb->get_var("show tables like '$rental_coupon_relations'") != $rental_coupon_relations) {
        $sql .= "CREATE TABLE $rental_coupon_relations (
            id BIGINT(11) NOT NULL,
            rental_id BIGINT(11) NOT NULL,
            rental_coupon_divisions TEXT COLLATE utf8_unicode_ci NULL DEFAULT NULL,
            UNIQUE KEY id (id)
        );";
    }

    // create rental_price_multipliers table
    if ($wpdb->get_var("show tables like '$rental_price_multipliers'") != $rental_price_multipliers) {
        $sql .= "CREATE TABLE $rental_price_multipliers (
            id BIGINT(11) NOT NULL,
            title VARCHAR(255),
            slug VARCHAR(255),
            is_monthly TINYINT(1) DEFAULT 0,
            is_repeat TINYINT(1) DEFAULT 0,
            items LONGTEXT COLLATE utf8_unicode_ci,
            UNIQUE KEY id (id)
        );";
    } else {
        $columns = [];
        foreach ($wpdb->get_results("SHOW COLUMNS FROM `$rental_price_multipliers`") as $column) {
            $columns[] = $column->Field;
        }
        $add_columns_sql = "";
        if ( !in_array("is_monthly", $columns)) {
            $add_columns_sql = "ADD COLUMN is_monthly TINYINT(1) DEFAULT 0";
        }
        if ( !in_array("is_repeat", $columns)) {
            $add_columns_sql .= ($add_columns_sql? ", ": "") . "ADD COLUMN is_repeat TINYINT(1) DEFAULT 0";
        }
        if ($add_columns_sql) {
            $wpdb->query("ALTER TABLE `$rental_price_multipliers` $add_columns_sql");
        }
    }

    // create rental_product_options table
    if ($wpdb->get_var("show tables like '$rental_product_options'") != $rental_product_options) {
        $sql .= "CREATE TABLE $rental_product_options (
            id BIGINT(11) NOT NULL,
            title VARCHAR(255),
            once_per_order TINYINT(1) DEFAULT 0,
            option_values LONGTEXT COLLATE utf8_unicode_ci,
            UNIQUE KEY id (id)
        );";
    } else {
        $columns = [];
        foreach ($wpdb->get_results("SHOW COLUMNS FROM `$rental_product_options`") as $column) {
            $columns[] = $column->Field;
        }
        $add_columns_sql = "";
        if ( !in_array("once_per_order", $columns)) {
            $add_columns_sql = "ADD COLUMN once_per_order TINYINT(1) DEFAULT 0";
        }
        if ($add_columns_sql) {
            $wpdb->query("ALTER TABLE `$rental_product_options` $add_columns_sql");
        }
    }

    // create rental_product_option_relations table
    // wp_id : product id or category id
    // type : 1:product, 2:category
    if ($wpdb->get_var("show tables like '$rental_product_option_relations'") != $rental_product_option_relations) {
        $sql .= "CREATE TABLE $rental_product_option_relations (
            po_id BIGINT(11) NOT NULL,
            rental_id BIGINT(11) NOT NULL,
            wp_id BIGINT(11) NOT NULL, 
            type TINYINT(1),
            CONSTRAINT rental_unique_cols UNIQUE (po_id , rental_id, wp_id, type)
        );";
    } 
    // create rental_inventory_blocks table
    // type : 1 = all items, 2 = specific items 
    if ($wpdb->get_var("show tables like '$rental_inventory_blocks'") != $rental_inventory_blocks) {
        $sql .= "CREATE TABLE $rental_inventory_blocks (
            id BIGINT(11) NOT NULL,
            division_id INT(11) NOT NULL,
            `start_date` BIGINT(11) NOT NULL,
            `end_date` BIGINT(11) NOT NULL,
            `type` TINYINT(1),
            UNIQUE KEY id (id)
        );";
    } 
    // create rental_inventory_block_relations table
    // id : product_id/product_variant_id
    if ($wpdb->get_var("show tables like '$rental_inventory_block_relations'") != $rental_inventory_block_relations) {
        $sql .= "CREATE TABLE $rental_inventory_block_relations (
            block_id BIGINT(11) NOT NULL,
            rental_id BIGINT(11) NOT NULL,
            id BIGINT(11) NOT NULL, 
            CONSTRAINT rental_unique_cols UNIQUE (id , rental_id, block_id)
        );";
    } 
    // create rental_lead_relations table
    if ($wpdb->get_var("show tables like '$rental_lead_relations'") != $rental_lead_relations) {
        $sql .= "CREATE TABLE $rental_lead_relations (
            id BIGINT(11) NOT NULL AUTO_INCREMENT,
            rental_id BIGINT(11) NOT NULL,
            http_code SMALLINT(255),
            message TEXT,
            data LONGTEXT,
            register_time BIGINT(20),
            version VARCHAR(255),
            PRIMARY KEY id (id)
        );";
    }

    if ($wpdb->get_var("show tables like '$rental_set_options'") != $rental_set_options) {
        $sql .= "CREATE TABLE $rental_set_options (
            id BIGINT(11) NOT NULL,
            title VARCHAR(255),
            once_per_order TINYINT(1) DEFAULT 0,
            option_values LONGTEXT COLLATE utf8_unicode_ci,
            UNIQUE KEY id (id)
        );";
    }
    if ($wpdb->get_var("show tables like '$rental_set_option_relations'") != $rental_set_option_relations) {
        $sql .= "CREATE TABLE $rental_set_option_relations (
            set_option_id BIGINT(11) NOT NULL,
            rental_id BIGINT(11) NOT NULL,
            wp_id BIGINT(11) NOT NULL, 
            CONSTRAINT rental_unique_cols UNIQUE (set_option_id , rental_id, wp_id)
        );";
    }



    if ($sql) {
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    if (get_option('rental_synchronize_status')) {
        try {
            $api_key = get_option('rental_api_key');
            // set plugin path
            rental_curl('settings/plugin_path/update', $api_key, false, [
                'plugin_path' => substr(RENTOPIAN_SYNC_PATH, strlen(ABSPATH))
            ]);
            // sync company divisions
            rental_sync_divisions($api_key);
        } catch (RentalException $e) {}
    }
}

// remove all necessary tables
function rental_remove_tables() {
    global $wpdb, $rental_tables;
    $rental_error_log = $wpdb->prefix . $rental_tables["error_log"];
    $rental_file_sync_log = $wpdb->prefix . $rental_tables["file_sync_log"];
    $rental_failed_images = $wpdb->prefix . $rental_tables["failed_images"];
    $rental_product_relations = $wpdb->prefix . $rental_tables["product_relations"];
    $rental_variant_relations = $wpdb->prefix . $rental_tables["variant_relations"];
    $rental_set_relations = $wpdb->prefix . $rental_tables["set_relations"];
    $rental_category_relations = $wpdb->prefix . $rental_tables["category_relations"];
    $rental_tag_relations = $wpdb->prefix . $rental_tables["tag_relations"];
    $rental_sets_tag_relations = $wpdb->prefix . $rental_tables["sets_tag_relations"];
    $rental_attribute_relations = $wpdb->prefix . $rental_tables["attribute_relations"];
    $rental_attribute_value_relations = $wpdb->prefix . $rental_tables["attribute_value_relations"];
    $rental_attribute_value_groups = $wpdb->prefix . $rental_tables["attribute_value_groups"];
    $rental_brand_relations = $wpdb->prefix . $rental_tables["brand_relations"];
    $rental_image_relations = $wpdb->prefix . $rental_tables["image_relations"];
    $rental_order_relations = $wpdb->prefix . $rental_tables["order_relations"];
    $rental_day_tiers = $wpdb->prefix . $rental_tables["day_tiers"];
    $rental_shipping_zone_relations = $wpdb->prefix . $rental_tables["shipping_zone_relations"];
    $rental_coupon_relations = $wpdb->prefix . $rental_tables["coupon_relations"];
    $rental_price_multipliers = $wpdb->prefix . $rental_tables["price_multipliers"];
    $rental_product_options = $wpdb->prefix . $rental_tables["product_options"];
    $rental_product_option_relations = $wpdb->prefix . $rental_tables["product_option_relations"];
    $rental_inventory_blocks = $wpdb->prefix . $rental_tables["inventory_blocks"];
    $rental_inventory_block_relations = $wpdb->prefix . $rental_tables["inventory_block_relations"];
    $rental_lead_relations = $wpdb->prefix . $rental_tables["lead_relations"];


    delete_option("rental_api_key");
    delete_option("rental_synchronize_status");
    delete_option("rental_attribute_groups_schema_version");
    delete_option("rental_attribute_groups_cache_version");

    // TBD : remove file sync log / error tables on plugin remove or not? 
    // $rental_file_sync_log
    // $rental_failed_images

    $wpdb->query("DROP TABLE IF EXISTS $rental_error_log, $rental_product_relations, $rental_variant_relations, ".
        "$rental_set_relations, $rental_category_relations, $rental_tag_relations, $rental_sets_tag_relations, $rental_attribute_relations, ".
        "$rental_attribute_value_relations, $rental_attribute_value_groups, ".
        "$rental_brand_relations, $rental_image_relations, $rental_order_relations, $rental_day_tiers, $rental_shipping_zone_relations, ".
        "$rental_coupon_relations, $rental_price_multipliers, $rental_product_options, $rental_product_option_relations, ".
        "$rental_inventory_blocks, $rental_inventory_block_relations, $rental_lead_relations");
}

// default settings on plugin activation 
function set_default_settings() {
    if (false === get_option('rental_form_layout')) {
        update_option('rental_form_layout', 'horizontal');
    }
    if (false === get_option('rental_hide_product_type_label')) {
        update_option('rental_hide_product_type_label', 1);
    }
    // if (false === get_option('rental_filter_unavailable_products')) {
    //     update_option('rental_filter_unavailable_products', 1);
    // }

    update_option( 'woocommerce_enable_reviews', 'no' );

    // $current_host = parse_url(home_url(), PHP_URL_HOST);
    // $excluded_domains = ['lootrentals.com', 'boweryandbash.com'];

    // // Check if the current host matches any of the excluded domains
    // $exclude = false;
    // foreach ($excluded_domains as $excluded_domain) {
    //     if (strpos($current_host, $excluded_domain) !== false) {
    //         $exclude = true;
    //         break;
    //     }
    // }

    // if (!$exclude) {
    //     update_option('woocommerce_stock_format', 'no_amount');
    // }
    

    if (false === get_option('rental_encryption_key')) {
        update_option('rental_encryption_key', bin2hex(random_bytes(32)));
    }
}


function register_sale_products_template() {
    $page_slug = 'rntp-sale-products';

    if (get_page_by_path($page_slug) === null) {
        $page_id = wp_insert_post([
            'post_title'   => 'Sale Products',
            'post_name'    => $page_slug,
            'post_status'  => 'publish',
            'post_type'    => 'page',
            'post_content' => '',
        ]);

        if (!is_wp_error($page_id)) {
            update_post_meta($page_id, '_wp_page_template', 'rntp-sale-template.php');
        }
    }
}

// enqueue script and style
function rental_enqueue_scripts() {
    wp_enqueue_script('moment', plugins_url('/assets/vendor/calentim/build/vendor/moment.min.js', __FILE__), [], '2.17.1', true);
    wp_enqueue_script('calentim', plugins_url('/assets/vendor/calentim/build/js/calentim.min.js', __FILE__), ['jquery'], '2.0.8', true);
    wp_enqueue_script('calentim-patch', plugins_url('/assets/vendor/calentim/js/calentim-patch.js', __FILE__), ['jquery'], '2.0.8', true);
    wp_enqueue_style('calentim', plugins_url('/assets/vendor/calentim/build/css/calentim.min.css', __FILE__));
    wp_enqueue_style('dashicons');

    // Event date offset display utility — loaded before all date form scripts
    wp_enqueue_script('rental-date-offsets', plugins_url('/assets/js/rental-date-offsets.js', __FILE__), ['jquery', 'moment'], RENTOPIAN_SYNC_VERSION, true);

    // if (is_product() || is_shop() || is_cart() || is_product_category() || is_product_tag()) {
    wp_enqueue_script('rental-script', plugins_url('/assets/js/rental-script.js', __FILE__), ['jquery', 'rental-date-offsets'], RENTOPIAN_SYNC_VERSION, true);
    wp_enqueue_style('rental-style', plugins_url('/assets/css/rntp-styles.css', __FILE__), [], RENTOPIAN_SYNC_VERSION);
    wp_localize_script('rental-script', 'rentalObj', ['url' => admin_url('admin-ajax.php')]);

    // Price gate for every amount the front-end scripts paint themselves.
    wp_localize_script('rental-script', 'rentalPriceVisibility', rental_price_visibility_script_data());

    wp_localize_script('rental-script', 'rentalAutoDateSettings', [
        'autoShowProductsOnDateSelection' => (int) get_option('rental_auto_date_show_product_selection', 0),
        'autoAddToCartOnDateSelection'    => rental_is_auto_add_to_cart_enabled() ? 1 : 0,
        'hideZip'                         => (int) get_option('rental_hide_zip', 0),
        'hideEndDate'                     => (int) get_option('rental_hide_end_date', 0),
    ]);

    // Event date offset settings — shared across all date form scripts
    wp_localize_script('rental-script', 'rentalDateOffsets', array(
        'startDateOffset'  => (int) get_option('rental_start_date_offset', 0),
        'endDateOffset'    => (int) get_option('rental_end_date_offset', 0),
        'defaultStartTime' => get_option('rental_default_start_time', '9:00 AM'),
        'defaultEndTime'   => get_option('rental_default_end_time', '05:00 PM'),
        'hideEndDate'      => (int) get_option('rental_hide_end_date', 0),
        'hideZip'          => (int) get_option('rental_hide_zip', 0),
    ));

    if(get_option('rental_form_layout') === "in-cart"){
        wp_enqueue_script('rental-min-date-form-script', plugins_url('/assets/js/rental-min-date-form-script.js', RENTOPIAN_SYNC_PATH . '/functions.php'), ['jquery', 'moment', 'rental-date-offsets'], RENTOPIAN_SYNC_VERSION, true );
        wp_enqueue_style('rental-min-date-form-style', plugins_url('/assets/css/rental-min-date-form-style.css', RENTOPIAN_SYNC_PATH . '/functions.php'), [], RENTOPIAN_SYNC_VERSION);

        // Auto add-to-cart flag for the mini-cart form
        wp_localize_script('rental-min-date-form-script', 'rentalAutoCartSettings', [
            'autoAddToCartOnDateSelection' => rental_is_auto_add_to_cart_enabled() ? 1 : 0,
        ]);

        wp_localize_script('rental-min-date-form-script', 'rentalPriceVisibility', rental_price_visibility_script_data());

        // Event date offset settings for mini-cart form
        wp_localize_script('rental-min-date-form-script', 'rentalDateOffsets', array(
            'startDateOffset'  => (int) get_option('rental_start_date_offset', 0),
            'endDateOffset'    => (int) get_option('rental_end_date_offset', 0),
            'defaultStartTime' => get_option('rental_default_start_time', '9:00 AM'),
            'defaultEndTime'   => get_option('rental_default_end_time', '05:00 PM'),
            'hideEndDate'      => (int) get_option('rental_hide_end_date', 0),
            'hideZip'          => (int) get_option('rental_hide_zip', 0),
        ));
    }

    if (get_option('rental_synchronized_product_type') == "hourly") {
        wp_enqueue_script('rental-hourly-product-script', plugins_url('/assets/js/rental-hourly-product-script.js', __FILE__), ['jquery'], RENTOPIAN_SYNC_VERSION, true);
        wp_localize_script('rental-hourly-product-script', 'rentalObj', ['url' => admin_url('admin-ajax.php')]);
    }
    
    if (is_product()) {
        wp_enqueue_script('rental-add-ons-script', plugins_url('/assets/js/rental-add-ons-script.js', __FILE__), ['jquery'], RENTOPIAN_SYNC_VERSION, true);
        wp_localize_script('rental-add-ons-script', 'rentalAddOnsParams', [
            'cartUrl' => wc_get_cart_url(),
        ]);
        wp_enqueue_script('rental-multiplier-script', plugins_url('/assets/js/rental-multiplier-script.js', __FILE__), ['jquery'], RENTOPIAN_SYNC_VERSION, true);
        wp_localize_script('rental-multiplier-script', 'rentalObj', ['url' => admin_url('admin-ajax.php'),]);
       
        // Load the product-page availability check only when the availability filter
        // is active. It enforces admin inventory blocks (blackouts) and division,
        // independent of the overbooking setting.
        if (class_exists('Rentopian_Availability_Filter')
            && Rentopian_Availability_Filter::instance()->is_active()) {
            // check product availability
            wp_enqueue_script('rental-product-check-availability-script', plugins_url('/assets/js/rental-product-check-availability-script.js', __FILE__), ['jquery'], RENTOPIAN_SYNC_VERSION, true);
            wp_localize_script('rental-product-check-availability-script', 'rentalObj', ['url' => admin_url('admin-ajax.php')]);
        }

        // The `rental-sets` script + style and the `rentalObj` localized
        // var are now registered by the Sets module itself
        // (see Rental_Sets_Assets::enqueue() in includes/sets/).
    }

    if (is_checkout() && !is_order_received_page()) {

        $decrypted_rental_zip = '';
        if (isset($_COOKIE['rental_zip'])) {
            $decrypted_rental_zip = decrypt_data($_COOKIE['rental_zip'], get_option('rental_encryption_key'));
        }

        wp_enqueue_script('rental-checkout-script', plugins_url('/assets/js/rental-checkout-script.js', __FILE__), ['jquery', 'rental-date-offsets'], RENTOPIAN_SYNC_VERSION, true);
        wp_localize_script('rental-checkout-script', 'rentalObj', [
            'url' => admin_url('admin-ajax.php'),
            'zip' => isset($_COOKIE['rental_zip']) && !get_option('rental_hide_zip')? $decrypted_rental_zip : '',
            'rentalShowLocation' => (get_option('rental_show_location') && isset($_COOKIE["rental_google_map_address"]) && $_COOKIE["rental_google_map_address"] && isset($_COOKIE["rental_address"]) && $_COOKIE["rental_address"]) ? 1 : 0,
            'isTipAllowed' => (get_option("rental_direct_only_bookings", 0) && get_option("rental_payment_tips_enabled", 0)) ? 1 : 0,
            'baseCountry' => (function_exists('WC') && WC()->countries) ? WC()->countries->get_base_country() : '',
            'nonce' => wp_create_nonce('rental_checkout_nonce')
        ]);

        wp_enqueue_script(
            'rental-date-form-modern',
            plugins_url('/assets/js/rental-date-form-modern.js', __FILE__),
            ['jquery', 'moment', 'calentim', 'rental-date-offsets'],
            RENTOPIAN_SYNC_VERSION,
            true
        );
        
    }

    if (is_order_received_page()) {

        wp_enqueue_script('rental-order-received-script', plugins_url('/assets/js/rental-order-received-script.js', __FILE__), ['jquery'], RENTOPIAN_SYNC_VERSION, true);
        wp_localize_script('rental-order-received-script', 'rentalObj', ['url' => admin_url('admin-ajax.php')]);
    }
    

    if (is_cart()) {
        wp_enqueue_script('rental-cart-script', plugins_url('/assets/js/rental-cart-script.js', __FILE__), ['jquery'], RENTOPIAN_SYNC_VERSION, true);
        wp_localize_script('rental-cart-script', 'rentalObj', ['url' => admin_url('admin-ajax.php')]);
    }

    wp_register_script('rental-divisions-modal-script', plugins_url('/assets/js/rental-divisions-modal-script.js', __FILE__), ['jquery'], RENTOPIAN_SYNC_VERSION, true);
    wp_register_style('rental-divisions-modal-style', plugins_url('/assets/css/rental-divisions-modal-style.css', __FILE__), [], RENTOPIAN_SYNC_VERSION);

    wp_register_script('rental-wishlist-form-script', plugins_url('/assets/js/rental-wishlist-form-script.js', __FILE__), ['jquery'], RENTOPIAN_SYNC_VERSION, true);
    wp_register_style('rental-wishlist-style', plugins_url('/assets/css/rental-wishlist-style.css', __FILE__), [], RENTOPIAN_SYNC_VERSION);
    wp_localize_script('rental-wishlist-form-script', 'rentalObj', ['url' => admin_url('admin-ajax.php')]);

    // load fly-cart.min.js in cart/checkout pages when Rentpro theme exists only
    if (class_exists('Rentpro_THA')) {
        if ( 
            (is_cart() 
            || is_checkout())
            && get_option('rental_form_layout') === "in-cart"
        ) {

            if ( !wp_script_is( 'rentpro-fly-cart', 'enqueued' ) ) {
                wp_register_script(
                    'rentpro-fly-cart',
                    get_template_directory_uri() . '/assets/js/woo/fly-cart.min.js',
                    array( 'jquery' ),
                    '2.0',
                    true
                );
                // Enqueue it everywhere on the front end
                wp_enqueue_script( 'rentpro-fly-cart' );
            }
        }
       
    }
  

    // load font-awesome css for Eventorian theme only
    if (defined('EVENTORIAN_THEME_DIR')) {
        wp_enqueue_style(
            'font-awesome-cdn',
            'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.7.0/css/font-awesome.min.css',
            [],
            '4.7.0'
        );
    }
}

/**
 * Cache-busting version for one bundled admin asset.
 *
 * The plugin version only moves on a release, so a fix shipped to an
 * admin script between releases keeps the same asset URL and browsers
 * keep serving the old file. The file's own mtime changes whenever the
 * asset does, which is what this needs to express. Falls back to the
 * plugin version when the mtime is unreadable.
 *
 * @param string $relative Path relative to the plugin root.
 * @return string
 */
function rental_admin_asset_version($relative) {
    $version = defined('RENTOPIAN_SYNC_VERSION') ? RENTOPIAN_SYNC_VERSION : '';
    $path    = plugin_dir_path(__FILE__) . ltrim($relative, '/');

    if (!file_exists($path)) {
        return $version;
    }

    $mtime = filemtime($path);

    return $mtime ? $version . '.' . $mtime : $version;
}

// enqueue script and style to admin page
function rental_enqueue_scripts_to_admin($hook) {

    $plugin_folder_name = basename(dirname(__FILE__));

    if (is_admin() && ($hook == 'toplevel_page_'.$plugin_folder_name.'/functions' || $hook == 'rentopian-sync_page_rentopian-settings'
            || $hook == 'rentopian-sync_page_rentopian-log')) {
        
        wp_enqueue_script('moment', plugins_url('/assets/vendor/calentim/build/vendor/moment.min.js', __FILE__), [], '2.17.1', true);
        wp_enqueue_script('calentim', plugins_url('/assets/vendor/calentim/build/js/calentim.min.js', __FILE__), ['jquery'], '2.0.8', true);
        wp_enqueue_style('calentim', plugins_url('/assets/vendor/calentim/build/css/calentim.min.css', __FILE__));

        wp_enqueue_script('rental-admin-script', plugins_url('/assets/js/rental-admin-script.js', __FILE__), ['jquery'], RENTOPIAN_SYNC_VERSION, true);
        wp_enqueue_style('rental-admin-style', plugins_url('/assets/css/rental-admin-style.css', __FILE__), [], RENTOPIAN_SYNC_VERSION);
        wp_localize_script('rental-admin-script', 'rentalObj', [
            'url'   => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce(class_exists('Rental_Sync_Ajax_Controller')
                ? Rental_Sync_Ajax_Controller::NONCE_ACTION
                : 'rental_file_sync_admin'),
        ]);

        wp_enqueue_style('rental-data-sync-admin', plugins_url('/includes/data-sync/assets/css/data-sync-admin.css', __FILE__), [], RENTOPIAN_SYNC_VERSION);
        wp_enqueue_script('rental-data-sync-admin', plugins_url('/includes/data-sync/assets/js/data-sync-admin.js', __FILE__), ['jquery'], RENTOPIAN_SYNC_VERSION, true);
        wp_localize_script('rental-data-sync-admin', 'rentalDataSyncObj', [
            'url'   => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce(class_exists('Rental_Data_Sync_Ajax_Controller')
                ? Rental_Data_Sync_Ajax_Controller::NONCE_ACTION
                : 'rental_data_sync_admin'),
            'i18n'  => [
                'idleNever'           => __('No background synchronization has been run yet.', 'rentopian-sync'),
                'idleSynced'          => __('No run in progress. The catalog was synchronized previously.', 'rentopian-sync'),
                'running'             => __('Synchronizing…', 'rentopian-sync'),
                'starting'            => __('Starting…', 'rentopian-sync'),
                'loading'             => __('Loading…', 'rentopian-sync'),
                'stageData'           => __('Data', 'rentopian-sync'),
                'stageFiles'          => __('Files', 'rentopian-sync'),
                'phase'               => __('phase', 'rentopian-sync'),
                'rows'                => __('rows', 'rentopian-sync'),
                'images'              => __('images', 'rentopian-sync'),
                'processing'          => __('processing', 'rentopian-sync'),
                'completed'           => __('completed', 'rentopian-sync'),
                'queued'              => __('queued', 'rentopian-sync'),
                'waiting'             => __('waiting', 'rentopian-sync'),
                'fileStarting'        => __('starting soon', 'rentopian-sync'),
                'details'             => __('Details', 'rentopian-sync'),
                'deleteRun'           => __('Delete', 'rentopian-sync'),
                'downloadLog'         => __('Download log', 'rentopian-sync'),
                'noRuns'              => __('No runs yet.', 'rentopian-sync'),
                'noLogFile'           => __('No log file is available for this run.', 'rentopian-sync'),
                'logTruncated'        => __('Showing the most recent lines — download the file for the full run.', 'rentopian-sync'),
                'needKey'             => __('Enter the API key first.', 'rentopian-sync'),
                'confirmStart'        => __('Are you sure you want to proceed with the data and file synchronization? The current website content will be removed and replaced with the latest content, so the storefront will be incomplete until the run finishes.', 'rentopian-sync'),
                'confirmStop'         => __('Stop the running synchronization? Already-synced records stay; the rest is skipped until the next run.', 'rentopian-sync'),
                'confirmDelete'       => __('Delete this run and its log file?', 'rentopian-sync'),
                'startedData'         => __('Background synchronization scheduled.', 'rentopian-sync'),
                'completedAll'        => __('Background synchronization completed.', 'rentopian-sync'),
                'startedWithWarnings' => __('Synchronization scheduled, but with warnings:', 'rentopian-sync'),
                'startFailedUnknown'  => __('The synchronization could not be started and the server gave no reason. The site may have hit a PHP error — check WooCommerce → Status → Logs (rentopian-data-sync).', 'rentopian-sync'),
                'confirmStopAll'      => __('Stop every synchronization, data and images, including any left running from an earlier attempt? Nothing already imported is removed, and starting a new synchronization re-enables them.', 'rentopian-sync'),
                'stopped'             => __('Synchronization canceled.', 'rentopian-sync'),
                'stoppedAll'          => __('All synchronization stopped.', 'rentopian-sync'),
                'stopFailed'          => __('Cancel failed.', 'rentopian-sync'),
                'stopAllFailed'       => __('The synchronizations could not be stopped.', 'rentopian-sync'),
                'nothingToStop'       => __('Nothing is running to stop.', 'rentopian-sync'),
                'deleteFailed'        => __('The run could not be deleted.', 'rentopian-sync'),
                'lastRun'             => __('Last synchronization:', 'rentopian-sync'),
                'outcomeCompleted'    => __('completed', 'rentopian-sync'),
                'outcomeFailed'       => __('failed', 'rentopian-sync'),
                'outcomeCanceled'     => __('canceled', 'rentopian-sync'),
                'reported'            => __('Report emailed at', 'rentopian-sync'),
                'reportFailed'        => __('Report could not be emailed — the site has no working mail transport. The full report is in this run\'s log.', 'rentopian-sync'),
                'reportNoRecipient'   => __('Report not emailed: no recipient is set. Choose one under "Report email" above.', 'rentopian-sync'),
                'reportSaveFailed'    => __('The report recipient could not be saved.', 'rentopian-sync'),
                'saving'              => __('Saving…', 'rentopian-sync'),
            ],
        ]);


        wp_enqueue_style('rental-sync-log-admin', plugins_url('/includes/sync-log/assets/css/sync-log-admin.css', __FILE__), [], RENTOPIAN_SYNC_VERSION);
        wp_enqueue_script('rental-sync-log-admin', plugins_url('/includes/sync-log/assets/js/sync-log-admin.js', __FILE__), ['jquery'], RENTOPIAN_SYNC_VERSION, true);
        wp_localize_script('rental-sync-log-admin', 'rentalSyncLogObj', [
            'url'   => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce(class_exists('Rental_Unified_Sync_Log_Ajax_Controller')
                ? Rental_Unified_Sync_Log_Ajax_Controller::NONCE_ACTION
                : 'rental_sync_log_admin'),
            'i18n'  => [
                'loading'       => __('Loading…', 'rentopian-sync'),
                'details'       => __('Details', 'rentopian-sync'),
                'deleteRun'     => __('Delete', 'rentopian-sync'),
                'retryFailed'   => __('Retry Failed Images', 'rentopian-sync'),
                'downloadLog'   => __('Download log', 'rentopian-sync'),
                'filesId'       => __('files:', 'rentopian-sync'),
                'noRuns'        => __('No synchronization has been recorded yet.', 'rentopian-sync'),
                'logTruncated'  => __('Showing the most recent lines — download the log for the whole run.', 'rentopian-sync'),
                'confirmDelete' => __('Delete this run and its log?', 'rentopian-sync'),
                'finished'      => __('finished', 'rentopian-sync'),
                'first'         => __('First page', 'rentopian-sync'),
                'last'          => __('Last page', 'rentopian-sync'),
                'timedOut'      => __('The server did not answer in time. Try again.', 'rentopian-sync'),
                'offline'       => __('The browser could not reach the server.', 'rentopian-sync'),
                'httpError'     => __('The server answered with an error', 'rentopian-sync'),
                'unknownError'  => __('The request failed and the server gave no reason.', 'rentopian-sync'),
            ],
        ]);

        wp_enqueue_script(
            'rentopian-sync-admin-settings',
            plugins_url('assets/js/admin-settings.js', __FILE__),
            ['jquery'],
            rental_admin_asset_version('assets/js/admin-settings.js'),
            true
        );
        
        wp_localize_script('rentopian-sync-admin-settings', 'rentalSettingsAdmin', [
            'i18n' => [
                'saving'         => __('Saving...', 'rentopian-sync'),
                'saved'          => __('Settings saved successfully.', 'rentopian-sync'),
                'error_generic'  => __('Error saving settings.', 'rentopian-sync'),
                'error_network'  => __('Network error while saving settings.', 'rentopian-sync'),
            ],
            'rentalObj' => [
                'url' => admin_url('admin-ajax.php')
            ]
        ]);
        
    }
}



// function ajax which is responsible for sync
function wp_ajax_rental_sync() {

    if (isset($_POST['api_key']) && $_POST['api_key']) {
        update_option('rental_api_key', $_POST['api_key']);
        update_option('rental_synchronize_status', 2);
        update_option('rental_api_key_is_valid', 0);
        // call rental_synchronization function
        $sync_time = time();
        update_option('rental_sync_time', $sync_time);

        try {
            if (rental_synchronization($_POST['api_key'])) {
                $data = ['start' => 0];
                ErrorHandler::registerErrorInLog( __('The import is now complete', 'rentopian-sync'), __FILE__, __LINE__, RentalException::TYPE_SYNC_GLOBAL, $sync_time, 200);
                wp_send_json($data, 200);
            }
        } catch (RentalException $e) {
            ErrorHandler::registerErrorInLog($e->getMessage(), $e->getFile(), $e->getLine(), $e->getType(), $sync_time, $e->getStatusCode());
            wp_send_json([
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ], $e->getStatusCode());
        }
    } else {
        $data = ["message" => __('The API key is required', 'rentopian-sync')];
        wp_send_json($data, 400);
    }
    wp_die();
}




function rental_delete_transients_by_prefix($prefix) {
    global $wpdb;
    // delete both value and timeout rows for this prefix
    $like = esc_sql('_transient_' . $prefix . '%');
    $like_to = esc_sql('_transient_timeout_' . $prefix . '%');
    $deleted_vals = $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '{$like}'" );
    $deleted_timeouts = $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '{$like_to}'" );
    return intval($deleted_vals) + intval($deleted_timeouts);
}



// function ajax which is responsible for uploading images
function wp_ajax_rental_upload_images() {
    if (isset($_GET['start'])) {

        $rental_file_sync_method = get_option('rental_file_sync_method', 1);

        $start = intval($_GET['start']);

        if ($start === 0) {
            Rental_Timer::start_persistent();
        }
       
        if (get_option('rental_sync_files_manual', 0) == 1) {
            update_option('rental_sync_files_manual_index', 0);
            $data = ['start' => $start, 'percent' => 100, 'sync_files_manual' => 1];
            $data['message'] = __('Synchronization process of data completed successfully', 'rentopian-sync');
            wp_send_json($data, 200);
        }

        $rental_image_upload_completed = get_option('rental_image_upload_completed');
        if ($rental_image_upload_completed === false) {
            $rental_image_upload_completed = true;
            update_option('rental_image_upload_completed', $rental_image_upload_completed);
        }

        $rental_products_img_last_id = get_option('rental_products_img_last_id');
        if ( $rental_products_img_last_id === false) {
            $rental_products_img_last_id = 0;
            update_option('rental_products_img_last_id', $rental_products_img_last_id);
        }

        $rental_sync_time = get_option('rental_sync_time');

        $img_count = get_option('rental_products_img_count', 0);
        if ($img_count) {
            if ($rental_file_sync_method == 1) {
                $limit = 3;
            } else {
                $limit = 5;
            }

            $last_id = $rental_products_img_last_id;
            try {

                if ($rental_file_sync_method == 1) {
                    // base 64 method
                    rental_upload_images($last_id, $limit);
                } else {
                    // nginx method
                    rental_upload_images_stream($last_id, $limit);
                }

            } catch (RentalException $e) {

                $rental_image_upload_completed = false;
                update_option('rental_image_upload_completed', false);
                
                $data = serialize(['start' => $last_id, 'limit' => $limit]);
                ErrorHandler::registerErrorInLog($e->getMessage(), $e->getFile(), $e->getLine(), $e->getType(), $rental_sync_time !== false ? $rental_sync_time : null, $e->getStatusCode(), $data);
            }

            $start += $limit;
        }

        if ( !$img_count || $start >= $img_count) {
            $data = ['start' => 0];
            if ($rental_image_upload_completed) {
                update_option('rental_synchronize_status', 1);
                update_option('rental_api_key_is_valid', 1);
                $data['message'] = __('Synchronization process completed successfully', 'rentopian-sync');
                $data['completed'] = true;
                $message = __('The images import is now complete', 'rentopian-sync');

                // Stop the timer and get duration
                $duration = Rental_Timer::stop_persistent();
                update_option("rental_show_sync_duration",  $duration !== false ? $duration : '');
                $data['show_duration'] = $duration;

            } else {
                $data['message'] = __('Some images were not retrieved during synchronization. Please check the log and try to re-upload the listed images.', 'rentopian-sync');
                $data['completed'] = false;
                $message = __('Some images were not retrieved during synchronization', 'rentopian-sync');

                $data['show_duration'] = '';
            }

            ErrorHandler::registerErrorInLog($message, __FILE__, __LINE__, RentalException::TYPE_SYNC_GLOBAL, $rental_sync_time !== false ? $rental_sync_time : null, 200);
            
            delete_option('rental_image_upload_completed');
            delete_option('rental_products_img_count');
            delete_option('rental_products_img_last_id');
            delete_option('rental_img_category_rel');
            delete_option('rental_img_variant_rel');
            delete_option('rental_img_attribute_value_rel');
            delete_option('rental_img_brand_rel');
            delete_option('rental_sync_time');
            delete_option('rental_sync_start_time');
            delete_option('rental_show_sync_duration');

        } else {
            $percent = floor(($start / $img_count) * 100);
            $data = ['start' => $start, 'percent' => $percent];
        }

        wp_send_json($data, 200);
    }

    wp_die();
}

function wp_ajax_rental_upload_images_manual() {
    if (isset($_GET['start']) && get_option('rental_sync_files_manual', 0) == 1) {
        $start = intval($_GET['start']);

        $rental_image_upload_completed = get_option('rental_image_upload_completed');
        if ( $rental_image_upload_completed === false) {
            $rental_image_upload_completed = true;
            update_option('rental_image_upload_completed', $rental_image_upload_completed);
        }

        $rental_products_img_last_id = get_option('rental_products_img_last_id');
        if ( $rental_products_img_last_id === false) {
            $rental_products_img_last_id = 0;
            update_option('rental_products_img_last_id', $rental_products_img_last_id);
        }

        $rental_sync_time = get_option('rental_sync_time');

        $rental_products_img_count = get_option('rental_products_img_count');
        $img_count = $rental_products_img_count !== false ? $rental_products_img_count : get_option('rental_sync_files_count', 0);
        if ($img_count) {
            $limit = 20; // manual file transfer limit

            $last_id = $rental_products_img_last_id;
            try {

                rental_upload_images($last_id, $limit);

            } catch (RentalException $e) {

                $rental_image_upload_completed = false;
                update_option('rental_image_upload_completed', false);

                $data = serialize(['start' => $last_id, 'limit' => $limit]);
                ErrorHandler::registerErrorInLog($e->getMessage(), $e->getFile(), $e->getLine(), $e->getType(), $rental_sync_time !== false ? $rental_sync_time : null, $e->getStatusCode(), $data);
            }

            $start += $limit;
            update_option('rental_sync_files_manual_index', $start);
        }

        if ( !$img_count || $start >= $img_count) {

            update_option('rental_sync_files_manual_index', $img_count);
            $data = ['start' => 0];

            if ($rental_image_upload_completed) {

                update_option('rental_synchronize_status', 1);
                update_option('rental_api_key_is_valid', 1);
                $data['message'] = __('Synchronization process completed successfully', 'rentopian-sync');
                $data['completed'] = true;
                $message = __('The images import is now complete', 'rentopian-sync');
            } else {

                $data['message'] = __('Some images were not retrieved during synchronization. Please check the log and try to re-upload the listed images.', 'rentopian-sync');
                $data['completed'] = false;
                $message = __('Some images were not retrieved during synchronization', 'rentopian-sync');
            }

            ErrorHandler::registerErrorInLog($message, __FILE__, __LINE__, RentalException::TYPE_SYNC_GLOBAL, $rental_sync_time !== false ? $rental_sync_time : null, 200);
            
            delete_option('rental_image_upload_completed');
            delete_option('rental_products_img_count');
            delete_option('rental_products_img_last_id');
            delete_option('rental_img_category_rel');
            delete_option('rental_img_variant_rel');
            delete_option('rental_img_attribute_value_rel');
            delete_option('rental_img_brand_rel');
            delete_option('rental_sync_time');

        } else {

            $data = ['start' => $start, 'percent' => 100];
            $data['message'] = __('Files Synchronization process completed partially (Imported files: '.$start.' , Remaining files: '. abs($img_count - $start).'  )', 'rentopian-sync');
        }
        wp_send_json($data, 200);
    }
    wp_die();
}

// strem with WP image size generator
function rental_upload_images(&$start, $limit) {
    set_time_limit(800);

    global $wpdb, $rental_tables;
    $rental_set_relations = $wpdb->prefix . $rental_tables["set_relations"];
    $rental_product_relations = $wpdb->prefix . $rental_tables["product_relations"];
    $rental_variant_relations = $wpdb->prefix . $rental_tables["variant_relations"];
    $rental_image_relations = $wpdb->prefix . $rental_tables["image_relations"];

    $uploaded = $wpdb->get_results("SELECT `rental_id`, `id` FROM $rental_image_relations WHERE `rental_id` > $start ORDER BY `rental_id` ASC LIMIT $limit", 'OBJECT_K');
    
    $images = rental_curl('files/images/stream', get_option('rental_api_key'), true, [
        'start' => $start,
        'limit' => $limit,
        'uploaded_images' => json_encode($uploaded),
    ]);


    $set_image_gallery = [];
    $product_image_gallery = [];
    $variant_image_gallery = [];
    $image_relations_sql = [];
    foreach ($images as $image) {

        $start = $image->id;

        if ( isset($image->already_uploaded) && $image->already_uploaded === true && !isset($uploaded[$image->id]) ) {

            if ($image->products) {
                $product_ids = json_decode(stripslashes($image->products));
                foreach ($product_ids as $id) {
                    $products = $wpdb->get_results("SELECT `id` FROM $rental_product_relations WHERE `rental_id` = $id");
                    foreach ($products as $product) {
                        if (get_post_meta($product->id, '_thumbnail_id', true) == $image->id) {
                            update_post_meta($product->id, '_thumbnail_id', '');
                        }
                    }
                }
            }

            continue;
        }

        
        if ( ! function_exists('download_url') ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        $tmp_file = download_url($image->url);

        if (is_wp_error($tmp_file)) {

            // TODO : log error

            // Download failed; skip this image
            continue;
        }

       

        if (isset($uploaded[$image->id])) {

            $attach_id = $uploaded[$image->id]->id;
        } else {

            // Temporarily bypass MIME type check.
            add_filter('wp_check_filetype_and_ext', 'rental_bypass_mime_check', 10, 4);

            $upload = wp_upload_bits(basename($image->url), null, file_get_contents($tmp_file));

            if (!empty($upload['error'])) {
                // throw new Exception("Upload error: " . $upload['error']);
                
                // TODO : log error
            }

            // Remove our temporary bypass after upload.
            remove_filter('wp_check_filetype_and_ext', 'rental_bypass_mime_check', 10);


            $wp_upload_dir = wp_upload_dir();

            if (isset($upload['file'])) {

                $attachment = ['guid' => $wp_upload_dir['baseurl'] . '/' . _wp_relative_upload_path($upload['file']),
                    'post_mime_type' => $image->mime,
                    'post_title' => $image->label,
                    'post_content' => $image->description? $image->description: "",
                    'post_status' => 'inherit'];

                $attach_id = wp_insert_attachment($attachment, $upload['file'], 0);

                if (is_wp_error($attach_id) || !$attach_id) {
                    
                    // TODO : log error
                    
                    continue;
                }

                require_once(ABSPATH . 'wp-admin/includes/image.php');

                $attach_data = wp_generate_attachment_metadata($attach_id, $upload['file']);
                wp_update_attachment_metadata($attach_id, $attach_data);

                $image_relations_sql[] = "($attach_id, $image->id)";
            }
        }


        update_option('rental_products_img_last_id', $start);

        if ($image->products) {

            if ( is_array($image->products))  {
                $products = implode(",", _rental_parse_ids($image->products) );
            } else {
                $products = implode(",", json_decode(stripslashes($image->products)));
            }
            $products = $wpdb->get_results("SELECT `id` FROM $rental_product_relations WHERE `rental_id` IN ($products)");

            foreach ($products as $product) {
                if (get_post_meta($product->id, '_thumbnail_id', true) == $image->id) {
                    update_post_meta($product->id, '_thumbnail_id', $attach_id);
                } else if (isset($product_image_gallery[$product->id])) {
                    $product_image_gallery[$product->id] .= ",$attach_id";
                } else {
                    $product_image_gallery[$product->id] = "$attach_id";
                }
            }
        }

        $rental_img_category_rel = get_option('rental_img_category_rel');
        if ($rental_img_category_rel !== false && isset($rental_img_category_rel[$image->id])) {
            foreach ($rental_img_category_rel[$image->id] as $cat_id) {
                update_term_meta($cat_id, 'thumbnail_id', $attach_id);
            }
        }

        $rental_banner_img_category_rel = get_option('rental_banner_img_category_rel');
        if ($rental_banner_img_category_rel !== false && isset($rental_banner_img_category_rel[$image->id])) {
            foreach ($rental_banner_img_category_rel[$image->id] as $cat_id) {
                update_term_meta($cat_id, 'banner_id', $attach_id);
            }
        }

        $variant_main_images = [];
        $rental_img_variant_rel = get_option('rental_img_variant_rel');
        if ($rental_img_variant_rel !== false && isset($rental_img_variant_rel[$image->id])) {
            foreach ($rental_img_variant_rel[$image->id] as $variant_id) {
                update_post_meta($variant_id, '_thumbnail_id', $attach_id);
	            $variant_main_images[$variant_id] = true;
            }
        }

        $rental_img_attribute_value_rel = get_option('rental_img_attribute_value_rel');
        if ($rental_img_attribute_value_rel !== false && isset($rental_img_attribute_value_rel[$image->id])) {
            foreach ($rental_img_attribute_value_rel[$image->id] as $attr_val_id) {
                if (defined('ZOO_CW_VERSION')) {
                    update_term_meta($attr_val_id, 'slctd_img', wp_get_attachment_image_url($attach_id));
                }
                if (defined('RENTPRO_SWATCHES_PATH')) {
                    update_term_meta($attr_val_id, 'sw_image', $attach_id);
                }
            }
        }

        $rental_img_brand_rel = get_option('rental_img_brand_rel');
        if ($rental_img_brand_rel !== false && isset($rental_img_brand_rel[$image->id])) {
            foreach ($rental_img_brand_rel[$image->id] as $brand_id) {
                update_term_meta($brand_id, 'thumbnail_id', $attach_id);
            }
        }

        
	    if ($image->variants) {

            if (is_array($image->variants)) {
                $variants = implode(",", _rental_parse_ids($image->variants) );
            } else {
                $variants = implode(",", json_decode(stripslashes($image->variants)));
            }

		    $variants = $wpdb->get_results("SELECT `id` FROM $rental_variant_relations WHERE `rental_id` IN ($variants)");
		    foreach ($variants as $variant) {
			    if (isset($variant_main_images[$variant->id])) {
				    continue;
			    }
			    if (isset($variant_image_gallery[$variant->id])) {
				    $variant_image_gallery[$variant->id] .= ",$attach_id";
			    } else {
				    $variant_image_gallery[$variant->id] = "$attach_id";
			    }
		    }
	    }


        // if ($image->sets) {

        //     if (is_array($image->sets)) {
        //         $sets = implode(",", _rental_parse_ids($image->sets) );
        //     } else {
        //         $sets = implode(",", json_decode(stripslashes($image->sets)));
        //     }

		//     $sets = $wpdb->get_results("SELECT `id` FROM $rental_set_relations WHERE `rental_id` IN ($sets)");
		//     foreach ($sets as $set) {
		// 	    if (isset($variant_main_images[$set->id])) {
		// 		    continue;
		// 	    }
		// 	    if (isset($set_image_gallery[$set->id])) {
		// 		    $set_image_gallery[$set->id] .= ",$attach_id";
		// 	    } else {
		// 		    $set_image_gallery[$set->id] = "$attach_id";
		// 	    }
		//     }
	    // }

        Rental_Sets_Image_Linker::attach( $image, $attach_id, $variant_main_images, $set_image_gallery );

    }

    if ($image_relations_sql) {
        $image_relations_sql = implode(", ", $image_relations_sql);
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta("INSERT INTO `$rental_image_relations` (`id`, `rental_id`) VALUES $image_relations_sql;");
    }

    foreach ($product_image_gallery as $id => $images) {
        $gallery = get_post_meta($id, '_product_image_gallery', true);
        if ($gallery) {
	        $images = $gallery . ',' . $images;
        }
        update_post_meta($id, '_product_image_gallery', $images);
    }

	foreach ($variant_image_gallery as $id => $images) {
		$gallery = get_post_meta($id, 'zoo-cw-variation-gallery', true);
		if ($gallery) {
			$images = $gallery . ',' . $images;
		}
		update_post_meta($id, 'zoo-cw-variation-gallery', $images);
	}

    foreach ($set_image_gallery as $id => $images) {
		$gallery = get_post_meta($id, '_product_image_gallery', true);
		if ($gallery) {
			$images = $gallery . ',' . $images;
		}
		update_post_meta($id, '_product_image_gallery', $images);
	}

    if ($wpdb->last_error !== '') {
        throw new RentalException("SQL_ERROR: $wpdb->last_error (SQL: $wpdb->last_query)", RentalException::TYPE_SYNC_RUNTIME);
    }
}


/*
// base 64 encode with WP image size generator 
function rental_upload_images_ORIGIN(&$start, $limit) {
    set_time_limit(800);

    global $wpdb, $rental_tables;
    $rental_set_relations = $wpdb->prefix . $rental_tables["set_relations"];
    $rental_product_relations = $wpdb->prefix . $rental_tables["product_relations"];
    $rental_variant_relations = $wpdb->prefix . $rental_tables["variant_relations"];
    $rental_image_relations = $wpdb->prefix . $rental_tables["image_relations"];

    // $failed_ids = get_option('rental_image_upload_failed_ids', []);
    // $fail_counts = get_option('rental_image_fail_counts', []);

    $uploaded = $wpdb->get_results("SELECT `rental_id`, `id` FROM $rental_image_relations WHERE `rental_id` > $start ORDER BY `rental_id` ASC LIMIT $limit", 'OBJECT_K');
    
    $images = rental_curl('files/images', get_option('rental_api_key'), true, [
        'start' => $start,
        'limit' => $limit,
        'uploaded_images' => json_encode($uploaded),
    ]);

    $set_image_gallery = [];
    $product_image_gallery = [];
    $variant_image_gallery = [];
    $image_relations_sql = [];
    foreach ($images as $image) {

        $start = $image->id;
        update_option('rental_products_img_last_id', $start);

        if ( !$image->img_content && !isset($uploaded[$image->id])) {
            if ($image->products) {
                $product_ids = json_decode(stripslashes($image->products));
                foreach ($product_ids as $id) {
                    $products = $wpdb->get_results("SELECT `id` FROM $rental_product_relations WHERE `rental_id` = $id");
                    foreach ($products as $product) {
                        if (get_post_meta($product->id, '_thumbnail_id', true) == $image->id) {
                            update_post_meta($product->id, '_thumbnail_id', '');
                        }
                    }
                }
            }
            continue;
        }

        if (isset($uploaded[$image->id])) {
            $attach_id = $uploaded[$image->id]->id;
        } else {
            // Temporarily bypass MIME type check.
            add_filter('wp_check_filetype_and_ext', 'rental_bypass_mime_check', 10, 4);

            $upload = wp_upload_bits($image->filename, null, base64_decode($image->img_content));

            // Remove our temporary bypass after upload.
            remove_filter('wp_check_filetype_and_ext', 'rental_bypass_mime_check', 10);

            // Check for upload errors
            if ( !empty($upload['error']) ) {

                rental_mark_image_failed($image->id, 'Error happend on this line : upload = wp_upload_bits(image->filename, null, base64_decode(image->img_content) ', $upload['error']);
            }

            $wp_upload_dir = wp_upload_dir();

            if (isset($upload['file'])) {

                $attachment = ['guid' => $wp_upload_dir['baseurl'] . '/' . _wp_relative_upload_path($upload['file']),
                    'post_mime_type' => $image->mime,
                    'post_title' => $image->label,
                    'post_content' => $image->description? $image->description: "",
                    'post_status' => 'inherit'];

                $attach_id = wp_insert_attachment($attachment, $upload['file'], 0);

                if (is_wp_error($attach_id) || !$attach_id) {
                    
                    rental_mark_image_failed($image->id, 'Error happend on this line : attach_id = wp_insert_attachment(attachment, upload["file"], 0) ', "Failed to insert attachment for this image : {$image->id}");
                    continue;
                }

                require_once(ABSPATH . 'wp-admin/includes/image.php');

                $attach_data = wp_generate_attachment_metadata($attach_id, $upload['file']);
                wp_update_attachment_metadata($attach_id, $attach_data);

                $image_relations_sql[] = "($attach_id, $image->id)";
            }
        }

        if ($image->products) {

	        $products = implode(",", json_decode(stripslashes($image->products)));
            $products = $wpdb->get_results("SELECT `id` FROM $rental_product_relations WHERE `rental_id` IN ($products)");

            foreach ($products as $product) {
                if (get_post_meta($product->id, '_thumbnail_id', true) == $image->id) {
                    update_post_meta($product->id, '_thumbnail_id', $attach_id);
                } else if (isset($product_image_gallery[$product->id])) {
                    $product_image_gallery[$product->id] .= ",$attach_id";
                } else {
                    $product_image_gallery[$product->id] = "$attach_id";
                }
            }
        }

        $rental_img_category_rel = get_option('rental_img_category_rel');
        if ($rental_img_category_rel !== false && isset($rental_img_category_rel[$image->id])) {
            foreach ($rental_img_category_rel[$image->id] as $cat_id) {
                update_term_meta($cat_id, 'thumbnail_id', $attach_id);
            }
        }

        $rental_banner_img_category_rel = get_option('rental_banner_img_category_rel');
        if ($rental_banner_img_category_rel !== false && isset($rental_banner_img_category_rel[$image->id])) {
            foreach ($rental_banner_img_category_rel[$image->id] as $cat_id) {
                update_term_meta($cat_id, 'banner_id', $attach_id);
            }
        }
        

        $variant_main_images = [];
        $rental_img_variant_rel = get_option('rental_img_variant_rel');
        if ($rental_img_variant_rel !== false && isset($rental_img_variant_rel[$image->id])) {
            foreach ($rental_img_variant_rel[$image->id] as $variant_id) {
                update_post_meta($variant_id, '_thumbnail_id', $attach_id);
	            $variant_main_images[$variant_id] = true;
            }
        }

        $rental_img_attribute_value_rel = get_option('rental_img_attribute_value_rel');
        if ($rental_img_attribute_value_rel !== false && isset($rental_img_attribute_value_rel[$image->id])) {
            foreach ($rental_img_attribute_value_rel[$image->id] as $attr_val_id) {
                if (defined('ZOO_CW_VERSION')) {
                    update_term_meta($attr_val_id, 'slctd_img', wp_get_attachment_image_url($attach_id));
                }
                if (defined('RENTPRO_SWATCHES_PATH')) {
                    update_term_meta($attr_val_id, 'sw_image', $attach_id);
                }
            }
        }

        $rental_img_brand_rel = get_option('rental_img_brand_rel');
        if ($rental_img_brand_rel !== false && isset($rental_img_brand_rel[$image->id])) {
            foreach ($rental_img_brand_rel[$image->id] as $brand_id) {
                update_term_meta($brand_id, 'thumbnail_id', $attach_id);
            }
        }

	    if ($image->variants) {
		    $variants = implode(",", json_decode(stripslashes($image->variants)));
		    $variants = $wpdb->get_results("SELECT `id` FROM $rental_variant_relations WHERE `rental_id` IN ($variants)");
		    foreach ($variants as $variant) {
			    if (isset($variant_main_images[$variant->id])) {
				    continue;
			    }
			    if (isset($variant_image_gallery[$variant->id])) {
				    $variant_image_gallery[$variant->id] .= ",$attach_id";
			    } else {
				    $variant_image_gallery[$variant->id] = "$attach_id";
			    }
		    }
	    }

        if ($image->sets) {
		    $sets = implode(",", json_decode(stripslashes($image->sets)));
		    $sets = $wpdb->get_results("SELECT `id` FROM $rental_set_relations WHERE `rental_id` IN ($sets)");
		    foreach ($sets as $set) {
			    if (isset($variant_main_images[$set->id])) {
				    continue;
			    }
			    if (isset($set_image_gallery[$set->id])) {
				    $set_image_gallery[$set->id] .= ",$attach_id";
			    } else {
				    $set_image_gallery[$set->id] = "$attach_id";
			    }
		    }
	    }
    }

    if ($image_relations_sql) {
        $image_relations_sql = implode(", ", $image_relations_sql);
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta("INSERT INTO `$rental_image_relations` (`id`, `rental_id`) VALUES $image_relations_sql;");
    }

    foreach ($product_image_gallery as $id => $images) {
        $gallery = get_post_meta($id, '_product_image_gallery', true);
        if ($gallery) {
	        $images = $gallery . ',' . $images;
        }
        update_post_meta($id, '_product_image_gallery', $images);
    }

	foreach ($variant_image_gallery as $id => $images) {
		$gallery = get_post_meta($id, 'zoo-cw-variation-gallery', true);
		if ($gallery) {
			$images = $gallery . ',' . $images;
		}
		update_post_meta($id, 'zoo-cw-variation-gallery', $images);
	}

    foreach ($set_image_gallery as $id => $images) {
		$gallery = get_post_meta($id, '_product_image_gallery', true);
		if ($gallery) {
			$images = $gallery . ',' . $images;
		}
		update_post_meta($id, '_product_image_gallery', $images);
	}

    if ($wpdb->last_error !== '') {
        throw new RentalException("SQL_ERROR: $wpdb->last_error (SQL: $wpdb->last_query)", RentalException::TYPE_SYNC_RUNTIME);
    }
}
*/

// strem with custom image size generator
function rental_upload_images_stream(&$start, $limit) {

    error_reporting(error_reporting() & ~E_WARNING);

    global $wpdb, $rental_tables;
    $rental_set_relations = $wpdb->prefix . $rental_tables["set_relations"];
    $rental_product_relations = $wpdb->prefix . $rental_tables["product_relations"];
    $rental_variant_relations = $wpdb->prefix . $rental_tables["variant_relations"];
    $rental_image_relations = $wpdb->prefix . $rental_tables["image_relations"];

    try {

        $images = rental_curl('files/images/stream', get_option('rental_api_key'), true, [
            'start' => $start,
            'limit' => $limit,
        ]);


    } catch (RentalException $e) {

        rental_mark_image_failed(0, 'Error happend on this line : images = rental_curl("files/images/stream",...) ', "API fetch failed: " . $e->getMessage() . " start/limit : ", serialize(['start'=>$start, 'limit'=>$limit]));
        throw $e;
    }

    
    $set_image_gallery = [];
    $product_image_gallery = [];
    $variant_image_gallery = [];
    $image_relations_sql = [];
    foreach ($images as $image) {

        $start = $image->id;

        if ( ! function_exists('download_url') ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        $tmp_file = download_url($image->url);

        if (is_wp_error($tmp_file)) {

            // rental_mark_image_failed($image->id, 'Error happend on this line : tmp_file = download_url($image->url) ', $tmp_file->get_error_message());

            // Download failed; skip this image
            continue;
        }


        $upload = wp_upload_bits(basename($image->url), null, file_get_contents($tmp_file));

        if ($upload['error']) {
            // rental_mark_image_failed($image->id, 'Error happend on this line : upload = wp_upload_bits(basename(image->url), null, file_get_contents(tmp_file)) ', $upload['error']);

            // throw new Exception("Upload error: " . $upload['error']);
        }
        
        $wp_upload_dir = wp_upload_dir();
        if (isset($upload['file'])) {

            $attachment = [
                'guid' => $wp_upload_dir['baseurl'] . '/' . _wp_relative_upload_path($upload['file']),
                'post_mime_type' => $image->mime,
                'post_title' => $image->label,
                'post_content' => $image->description? $image->description: "",
                'post_status' => 'inherit'
            ];

            $attach_id = wp_insert_attachment($attachment, $upload['file'], 0);

            if (is_wp_error($attach_id)) {

                // rental_mark_image_failed($image->id, 'Error happend on this line : attach_id = wp_insert_attachment(attachment, upload["file"], 0) ', $attach_id->get_error_message());
                continue;
            }

            require_once(ABSPATH . 'wp-admin/includes/image.php');
            
            $szs = rental_make_image_subsizes( $upload['file'], $attach_id);

            $image_relations_sql[] = "($attach_id, $image->id)";
        }


        update_option('rental_products_img_last_id', $start);

        if ($image->products) {

            $products = implode(",", _rental_parse_ids($image->products) );
            $products = $wpdb->get_results("SELECT `id` FROM $rental_product_relations WHERE `rental_id` IN ($products)");

	        foreach ($products as $product) {

                if (get_post_meta($product->id, '_thumbnail_id', true) == $image->id) {
                    
                    update_post_meta($product->id, '_thumbnail_id', $attach_id);

                } else if (isset($product_image_gallery[$product->id])) {
                    $product_image_gallery[$product->id] .= ",$attach_id";
                } else {
                    $product_image_gallery[$product->id] = "$attach_id";
                }
            }
        }

        $rental_img_category_rel = get_option('rental_img_category_rel');
        if ($rental_img_category_rel !== false && isset($rental_img_category_rel[$image->id])) {
            foreach ($rental_img_category_rel[$image->id] as $cat_id) {
                update_term_meta($cat_id, 'thumbnail_id', $attach_id);
            }
        }

        $rental_banner_img_category_rel = get_option('rental_banner_img_category_rel');
        if ($rental_banner_img_category_rel !== false && isset($rental_banner_img_category_rel[$image->id])) {
            foreach ($rental_banner_img_category_rel[$image->id] as $cat_id) {
                update_term_meta($cat_id, 'banner_id', $attach_id);
            }
        }
        

        $variant_main_images = [];
        $rental_img_variant_rel = get_option('rental_img_variant_rel');
        if ($rental_img_variant_rel !== false && isset($rental_img_variant_rel[$image->id])) {
            foreach ($rental_img_variant_rel[$image->id] as $variant_id) {
                update_post_meta($variant_id, '_thumbnail_id', $attach_id);
                $variant_main_images[$variant_id] = true;
            }
        }

        $rental_img_attribute_value_rel = get_option('rental_img_attribute_value_rel');
        if ($rental_img_attribute_value_rel !== false && isset($rental_img_attribute_value_rel[$image->id])) {
            foreach ($rental_img_attribute_value_rel[$image->id] as $attr_val_id) {
                if (defined('ZOO_CW_VERSION')) {
                    update_term_meta($attr_val_id, 'slctd_img', wp_get_attachment_image_url($attach_id));
                }
                if (defined('RENTPRO_SWATCHES_PATH')) {
                    update_term_meta($attr_val_id, 'sw_image', $attach_id);
                }
            }
        }

        $rental_img_brand_rel = get_option('rental_img_brand_rel');
        if ($rental_img_brand_rel !== false && isset($rental_img_brand_rel[$image->id])) {
            foreach ($rental_img_brand_rel[$image->id] as $brand_id) {
                update_term_meta($brand_id, 'thumbnail_id', $attach_id);
            }
        }

        if ($image->variants) {
            $variants = implode(",", _rental_parse_ids($image->variants) );
            $variants = $wpdb->get_results("SELECT `id` FROM $rental_variant_relations WHERE `rental_id` IN ($variants)");
            foreach ($variants as $variant) {
                if (isset($variant_main_images[$variant->id])) {
                continue;
                }
                if (isset($variant_image_gallery[$variant->id])) {
                $variant_image_gallery[$variant->id] .= ",$attach_id";
                } else {
                $variant_image_gallery[$variant->id] = "$attach_id";
                }
            }
        }

       
        // if ($image->sets) {
        //     $sets = implode(",", _rental_parse_ids($image->sets) );
        //     $sets = $wpdb->get_results("SELECT `id` FROM $rental_set_relations WHERE `rental_id` IN ($sets)");
        //     foreach ($sets as $set) {
        //         if (isset($variant_main_images[$set->id])) {
        //         continue;
        //         }
        //         if (isset($set_image_gallery[$set->id])) {
        //         $set_image_gallery[$set->id] .= ",$attach_id";
        //         } else {
        //         $set_image_gallery[$set->id] = "$attach_id";
        //         }
        //     }
        // }

        Rental_Sets_Image_Linker::attach( $image, $attach_id, $variant_main_images, $set_image_gallery );

    }

    if ($image_relations_sql) {
        $image_relations_sql = implode(", ", $image_relations_sql);
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

        dbDelta("INSERT INTO `$rental_image_relations` (`id`, `rental_id`) VALUES $image_relations_sql;");
    }

    foreach ($product_image_gallery as $id => $images) {

        $gallery = get_post_meta($id, '_product_image_gallery', true);
        if ($gallery) {
            $images = $gallery . ',' . $images;
        }

        update_post_meta($id, '_product_image_gallery', $images);
    }

    foreach ($variant_image_gallery as $id => $images) {
        $gallery = get_post_meta($id, 'zoo-cw-variation-gallery', true);
        if ($gallery) {
            $images = $gallery . ',' . $images;
        }
        update_post_meta($id, 'zoo-cw-variation-gallery', $images);
    }

    foreach ($set_image_gallery as $id => $images) {

        $gallery = get_post_meta($id, '_product_image_gallery', true);
        if ($gallery) {
            $images = $gallery . ',' . $images;
        }
        
        update_post_meta($id, '_product_image_gallery', $images);
    }

    if ($wpdb->last_error !== '') {
        throw new RentalException("SQL_ERROR: $wpdb->last_error (SQL: $wpdb->last_query)", RentalException::TYPE_SYNC_RUNTIME);
    }
}

// Wrapped — canonical impl now in includes/file-sync/class-image-subsizer.php
if ( ! function_exists( 'rental_make_image_subsizes_2' ) ) {
function rental_make_image_subsizes_2($file, $attachment_id) {

    // Use WP’s helper if available (better format coverage).
    $imagesize = function_exists('wp_getimagesize') ? wp_getimagesize($file) : @getimagesize($file);
    if (!$imagesize || empty($imagesize[0]) || empty($imagesize[1])) {
        return new WP_Error('rental_subsizes_no_image', 'Could not read image size for subsizes.');
    }

    $image_meta = array(
        'width'    => (int)$imagesize[0],
        'height'   => (int)$imagesize[1],
        'file'     => _wp_relative_upload_path($file),
        'filesize' => function_exists('wp_filesize') ? wp_filesize($file) : @filesize($file),
        'sizes'    => array(),
    );

    $threshold = apply_filters('big_image_size_threshold', 2560);
    if ($threshold && max($image_meta['width'], $image_meta['height']) > $threshold) {

        $editor = wp_get_image_editor($file);
        if ( !is_wp_error($editor) ) {
            // Constrain: longest side = $threshold, no hard crop
            $editor->resize($threshold, $threshold, false);
            $scaled_path = $editor->generate_filename('scaled');
            $saved = $editor->save($scaled_path);
            if ( !is_wp_error($saved) && !empty($saved['path']) ) {
                // Use the scaled image as the new “base”
                $file = $saved['path'];
                
                $image_meta['file']          = _wp_relative_upload_path($file);
                $image_meta['width']         = (int) $saved['width'];
                $image_meta['height']        = (int) $saved['height'];
                $image_meta['filesize']      = (int) ($saved['filesize'] ?? @filesize($file));
                $image_meta['original_image'] = basename($imagesize['file'] ?? $image_meta['file']); // record original
            }
        }
    }

    // If core helper exists, let it do the heavy lifting first.
    // This uses WP_Image_Editor (Imagick/GD) and mirrors core behavior.
    if (function_exists('wp_create_image_subsizes')) {
        $generated = wp_create_image_subsizes($file, $image_meta, $attachment_id);
        if (!is_wp_error($generated) && !empty($generated['sizes'])) {
            return $generated; // success via core path
        }
        // fall through to manual if core failed
    }

    // Manual GD fallback (JPEG/PNG/GIF).
    $new_sizes = wp_get_registered_image_subsizes();
    $new_sizes = apply_filters('intermediate_image_sizes_advanced', $new_sizes, $image_meta, $attachment_id);

    $res = rental_resize_image_multiple_gd($file, $new_sizes, $image_meta);
    return $res ?: $image_meta; // even if nothing generated, return base meta
}
} // end function_exists('rental_make_image_subsizes_2')

/**
 * Manual generator for JPEG/PNG/GIF using GD.
 * - avoids distortion on hard crop
 * - preserves transparency
 * - gracefully skips unsupported formats
 */
// Wrapped — canonical impl now in includes/file-sync/class-image-subsizer.php
if ( ! function_exists( 'rental_resize_image_multiple_gd' ) ) {
function rental_resize_image_multiple_gd($filename, $sizes, $image_meta) {

    $info = @getimagesize($filename);
    if (!$info) {
        return $image_meta;
    }
    list($origW, $origH, $type) = $info;

    // Only handle formats GD can create reliably.
    if (!in_array($type, array(IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF), true)) {
        return $image_meta;
    }

    // Create source
    switch ($type) {
        case IMAGETYPE_JPEG: $src = @imagecreatefromjpeg($filename); break;
        case IMAGETYPE_PNG:  $src = @imagecreatefrompng($filename);  break;
        case IMAGETYPE_GIF:  $src = @imagecreatefromgif($filename);  break;
    }
    if (!$src) {
        return $image_meta;
    }

    $path = pathinfo($filename);
    $base = $path['filename'];
    $ext  = strtolower($path['extension']);
    $mime = image_type_to_mime_type($type);

    foreach ($sizes as $name => $sz) {
        $tW = max(0, (int)($sz['width']  ?? 0));
        $tH = max(0, (int)($sz['height'] ?? 0));
        $crop = $sz['crop'] ?? false;

        // Skip invalid target
        if ($tW === 0 && $tH === 0) continue;

        // Compute target dims and src crop box (no distortion).
        $box = rental_calc_crop_box($origW, $origH, $tW, $tH, $crop);
        if (!$box) continue;

        list($srcX, $srcY, $srcW, $srcH, $dstW, $dstH) = $box;

        $dst = imagecreatetruecolor($dstW, $dstH);

        // Transparency
        if ($type === IMAGETYPE_PNG) {
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
        } elseif ($type === IMAGETYPE_GIF) {
            $index = imagecolortransparent($src);
            if ($index >= 0) {
                $c = imagecolorsforindex($src, $index);
                $index = imagecolorallocate($dst, $c['red'], $c['green'], $c['blue']);
                imagefill($dst, 0, 0, $index);
                imagecolortransparent($dst, $index);
            }
        }

        @imagecopyresampled($dst, $src, 0, 0, $srcX, $srcY, $dstW, $dstH, $srcW, $srcH);

        // Name like WP: -{w}x{h}
        $suffix = "-{$dstW}x{$dstH}";
        $new_filename = $base . $suffix . '.' . $ext;
        $out = $path['dirname'] . DIRECTORY_SEPARATOR . $new_filename;

        switch ($type) {
            case IMAGETYPE_JPEG:
                // Respect WP quality filter if available
                $quality = has_filter('jpeg_quality') ? (int) apply_filters('jpeg_quality', 82, 'image_resize') : 90;
                imagejpeg($dst, $out, $quality);
                break;
            case IMAGETYPE_PNG:
                // 0 (no compression) to 9 (max). WP typically uses 3.
                $png_compress = (int) apply_filters('wp_image_editors_png_compression_level', 3);
                imagepng($dst, $out, $png_compress);
                break;
            case IMAGETYPE_GIF:
                imagegif($dst, $out);
                break;
        }

        $image_meta['sizes'][$name] = array(
            'file'      => $new_filename,
            'width'     => $dstW,
            'height'    => $dstH,
            'mime-type' => $mime,
            'filesize'  => @filesize($out),
        );

        imagedestroy($dst);
    }

    imagedestroy($src);
    return $image_meta;
}
} // end function_exists('rental_resize_image_multiple_gd')

/**
 * Compute a center (or positioned) crop without distortion.
 * Accepts $crop = bool or array( hpos, vpos ) like WP (e.g. array('left','top')).
 * Returns [srcX, srcY, srcW, srcH, dstW, dstH].
 */
// Wrapped — canonical impl now in includes/file-sync/class-image-subsizer.php
if ( ! function_exists( 'rental_calc_crop_box' ) ) {
function rental_calc_crop_box($origW, $origH, $tW, $tH, $crop) {
    // If one dimension missing, scale proportionally (no crop).
    if ($tW === 0 && $tH > 0) {
        $ratio = $tH / $origH;
        return [0, 0, $origW, $origH, (int)round($origW * $ratio), $tH];
    }
    if ($tH === 0 && $tW > 0) {
        $ratio = $tW / $origW;
        return [0, 0, $origW, $origH, $tW, (int)round($origH * $ratio)];
    }

    // Both provided
    if ($crop) {
        $targetAR = $tW / $tH;
        $origAR   = $origW / $origH;

        if ($origAR > $targetAR) {
            // crop horizontally
            $srcH = $origH;
            $srcW = (int) round($srcH * $targetAR);
        } else {
            // crop vertically
            $srcW = $origW;
            $srcH = (int) round($srcW / $targetAR);
        }

        // position
        $hpos = 'center';
        $vpos = 'center';
        if (is_array($crop) && !empty($crop)) {
            $hpos = isset($crop[0]) ? $crop[0] : 'center';
            $vpos = isset($crop[1]) ? $crop[1] : 'center';
        }

        switch ($hpos) {
            case 'left':  $srcX = 0; break;
            case 'right': $srcX = $origW - $srcW; break;
            default:      $srcX = (int) floor(($origW - $srcW) / 2);
        }
        switch ($vpos) {
            case 'top':    $srcY = 0; break;
            case 'bottom': $srcY = $origH - $srcH; break;
            default:       $srcY = (int) floor(($origH - $srcH) / 2);
        }

        return [$srcX, $srcY, $srcW, $srcH, $tW, $tH];
    }

    // no crop: scale to fit box (maintain aspect)
    $ratio = min($tW / $origW, $tH / $origH);
    $dstW  = (int) floor($origW * $ratio);
    $dstH  = (int) floor($origH * $ratio);
    return [0, 0, $origW, $origH, $dstW, $dstH];
}
} // end function_exists('rental_calc_crop_box')


// Wrapped — canonical impl now in includes/file-sync/class-image-subsizer.php
if ( ! function_exists( 'rental_make_image_subsizes' ) ) {
function rental_make_image_subsizes($file, $attachment_id) {

    $imagesize = wp_getimagesize( $file );

    $image_meta = array(
        'width'    => $imagesize[0],
        'height'   => $imagesize[1],
        'file'     => _wp_relative_upload_path( $file ),
        'filesize' => wp_filesize( $file ),
        'sizes'    => array(),
    );

	$new_sizes = wp_get_registered_image_subsizes();

	/**
	 * Filters the image sizes automatically generated when uploading an image.
	 *
	 * @since 2.9.0
	 * @since 4.4.0 Added the `$image_meta` argument.
	 * @since 5.3.0 Added the `$attachment_id` argument.
	 *
	 * @param array $new_sizes     Associative array of image sizes to be created.
	 * @param array $image_meta    The image meta data: width, height, file, sizes, etc.
	 * @param int   $attachment_id The attachment post ID for the image.
	 */

	$new_sizes = apply_filters( 'intermediate_image_sizes_advanced', $new_sizes, $image_meta, $attachment_id );

    // We can filter unnecessary sizes if we need
    //	$new_sizes = array_filter($new_sizes, function($k) {
    //	    return strpos($k, 'woocommerce') === 0;
    //	}, ARRAY_FILTER_USE_KEY);

	$image_meta = resizeImageMultiple( $file, $new_sizes, $image_meta);
    return wp_update_attachment_metadata($attachment_id, $image_meta);
}
} // end function_exists('rental_make_image_subsizes')

// Wrapped — canonical impl now in includes/file-sync/class-image-subsizer.php
if ( ! function_exists( 'resizeImageMultiple' ) ) {
function resizeImageMultiple($filename, $sizes, $image_meta) {
    // Get original image dimensions and type

    list($origWidth, $origHeight, $imageType) = getimagesize($filename);

    // Extract file info
    $pathInfo = pathinfo($filename);
    $basename = $pathInfo['filename'];
    $extension = strtolower($pathInfo['extension']);

    // Create image from file based on type
    
    try {
        switch ($imageType) {
            case IMAGETYPE_JPEG:
                $sourceImage = @imagecreatefromjpeg($filename);
                break;
            case IMAGETYPE_PNG:
                $sourceImage = @imagecreatefrompng($filename);
                break;
            case IMAGETYPE_GIF:
                $sourceImage = @imagecreatefromgif($filename);
                break;
            default:
                return $image_meta;
        }
    } catch(Exception $e) {
       exit();
   }

    $imageMime = image_type_to_mime_type($imageType);

    foreach ($sizes as $size_name => $size) {

        if($size['crop'] && $size['width'] > 0 && $size['height'] > 0) {
            $newWidth = $size['width'];
            $newHeight = $size['height'];
        } else {
            // resize keeping the aspect ratio
            $aspectRatio = $origWidth / $origHeight;

            if ($size['height'] > 0 && ($size['width'] / $size['height'] > $aspectRatio)) {
                $newHeight = $size['height'];
                $newWidth = intval($size['width'] * $aspectRatio);
            } else {
                $newWidth = $size['width'];
                $newHeight = intval($size['width'] / $aspectRatio);
            }
        }

        // Create new image
        $resizedImage = imagecreatetruecolor($newWidth, $newHeight);

        // Preserve transparency for PNG and GIF
        if ($imageType == IMAGETYPE_PNG) {
            imagecolortransparent($resizedImage, imagecolorallocatealpha($resizedImage, 0, 0, 0, 127));
            imagealphablending($resizedImage, false);
            imagesavealpha($resizedImage, true);
        } elseif ($imageType == IMAGETYPE_GIF) {
            $transparencyIndex = imagecolortransparent($sourceImage);
            if ($transparencyIndex >= 0) {
                $transparencyColor = imagecolorsforindex($sourceImage, $transparencyIndex);
                $transparencyIndex = imagecolorallocate($resizedImage, $transparencyColor['red'], $transparencyColor['green'], $transparencyColor['blue']);
                imagefill($resizedImage, 0, 0, $transparencyIndex);
                imagecolortransparent($resizedImage, $transparencyIndex);
            }
        }

        // Resize
        @imagecopyresampled($resizedImage, $sourceImage, 0, 0, 0, 0, $newWidth, $newHeight, $origWidth, $origHeight);

        // Prepare output filename
        $suffix = "-{$size['width']}x{$size['height']}";
        $new_filename = $basename . $suffix . '.' . $extension;
        $outputFile = $pathInfo['dirname'] . DIRECTORY_SEPARATOR . $new_filename;

        // Save resized image
        switch ($imageType) {
            case IMAGETYPE_JPEG:
                imagejpeg($resizedImage, $outputFile, 90);
                break;
            case IMAGETYPE_PNG:
                imagepng($resizedImage, $outputFile);
                break;
            case IMAGETYPE_GIF:
                imagegif($resizedImage, $outputFile);
                break;
        }

        imagedestroy($resizedImage);
        $image_meta['sizes'][ $size_name ] = [
              'file' => $new_filename,
              'width' => $newWidth,
              'height' => $newHeight,
              'mime-type' => $imageMime,
              'filesize' => filesize($outputFile)
        ];
    }

    imagedestroy($sourceImage);
    return $image_meta;
}
} // end function_exists('resizeImageMultiple')
function _rental_parse_ids( $raw ) {
    if ( is_array( $raw ) ) {
        return $raw;
    }
    if ( empty( $raw ) ) {
        return [];
    }
    return json_decode( stripslashes( $raw ), true ) ?: [];
}

function format_time(float $seconds): string {
    if ($seconds < 0.001) {
        // under a millisecond
        return round($seconds * 1e6, 2) . ' under ms';
    } elseif ($seconds < 1) {
        // under a second
        return round($seconds * 1000, 2) . ' ms';
    } else {
        // one second or more
        return round($seconds, 2) . ' s';
    }
}









// action function for adding item in admin menus
/**
 * Get the admin menu slug for Rentopian Sync
 * 
 * @return string The menu slug
 */
function rental_get_admin_menu_slug() {
    return __FILE__;
}

function rental_add_pages() {
    $menu_slug = rental_get_admin_menu_slug();
    
    // Add a new top-level menu (ill-advised):
    add_menu_page(__( 'Rentopian Sync', 'rentopian-sync'), __( 'Rentopian Sync', 'rentopian-sync'), 'import', $menu_slug, 'rental_rentopian_sync_page', '', 57);

    // Add a submenu to the custom top-level menu:
    add_submenu_page($menu_slug, __( 'Rentopian Sync Settings', 'rentopian-sync'), __( 'Settings', 'rentopian-sync'), 'customize', 'rentopian-settings', 'rental_settings_page');

    // Add a submenu to the custom top-level menu:
    add_submenu_page($menu_slug, __( 'Rentopian Sync Log', 'rentopian-sync'), __( 'Log', 'rentopian-sync'), 'import', 'rentopian-log', 'rental_log_page');
}

// rental_rentopian_sync_page() displays the page content for the custom Rentopian Sync menu
function rental_rentopian_sync_page() {
    // variables for the field and option names
    $opt_api_key = 'rental_api_key';
    $opt_synchronize_status = 'rental_synchronize_status';
    $data_field_name = 'rental_api_key';

    $api_key_saved = false;
    if (isset($_POST["rental_save_api_key"])) {
        update_option($opt_api_key, $_POST[$opt_api_key]);
        update_option('rental_api_key_is_valid', 0);
        $api_key_saved = true;
    }

    // read in existing option value from database
    $opt_val = get_option($opt_api_key);
    $synchronize_status = get_option($opt_synchronize_status);
    $sync_files_manual = get_option('rental_sync_files_manual', 0);
    $rental_sync_files_manual_index = get_option('rental_sync_files_manual_index', 0);

    if ($synchronize_status == 1) {
        ?>
        <div class="rental-notice notice notice-success">
            <p><?php _e('Synchronization process completed successfully', 'rentopian-sync'); ?></p>
        </div>
        <?php
    } elseif ($synchronize_status == 2) {
        ?>
        <div class="rental-notice notice notice-error">
            <p><?php  _e('The synchronization is not complete, check the log section for details', 'rentopian-sync'); ?></p>
        </div>
        <?php
    } else {
        ?>
        <div class="rental-notice notice notice-warning">
            <p><?php _e('All your existing products will be deleted during synchronization! If you have any existing WooCommerce data, it is highly recommended to backup first.', 'rentopian-sync'); ?></p>
        </div>
        <?php
    }

    if ($api_key_saved) {
        ?>
        <div class="rental-notice notice notice-success">
            <p><?php _e('API key successfully saved', 'rentopian-sync');?></p>
        </div>
        <?php
    }
    ?>

    <div class="wrap">
        <div class="rntp-sync-panel panel-default">
            <div class="ajax-alert">
                <strong></strong>
            </div>

            <form method="post">
                <div class="panel-heading">
                    <h3 style="margin-top:6px"><?php _e('Synchronize with Rentopian system', 'rentopian-sync'); ?></h3>
                    <a href="https://rentopian.com/system-assets/rentopian-sync-documentation/" target="_blank" class="rntp-documentation-link">Documentation</a>
                    <div class="clearfix"></div>
                </div>
                <div class="panel-body">
                    <div class="rntp-sync-field">
                        <label for="rental_api_key" class="rental-label">
                            <?php _e("API Key:", 'rentopian-sync'); ?>
                        </label>

                        <input type="text" id="rental_api_key" class="rntp-input" name="<?php echo $data_field_name; ?>"
                               value="<?php echo $opt_val; ?>" size="30" />
                        <button type="submit" id="rental_save_api_key" name="rental_save_api_key" class="btn btn-inline-success btn-sm">
                            <span class="dashicons dashicons-yes"></span>
                            <?php _e('Save Key', 'rentopian-sync') ?>
                        </button>
                    </div>

                    <div class="rntp-help-block">
                        <div class="rntp-help-block-content">
                            <?php $api_key_url = esc_url('https://account.rentopian.com/admin/settings/company');
                            printf(
                                __( ' To get the API key, go to %1$s, click on "Copy to Clipboard" button and paste it into API key field.', 'rentopian-sync' ),
                                sprintf(
                                    '<a href="%s" target="_blank">%s</a>',
                                    $api_key_url,
                                    __( 'Company details section', 'rentopian-sync' )
                                )
                            );
                            ?>
                        </div>
                    </div>
                    <div class="clearfix"></div>
                </div>
                <div class="panel-body">
                    <div class="rntp-sync-form-title">
                        <label for="rental-checkbox-sync-files-manual" class="rental-label">
                            <?php _e('Enforce Manual File Synchronization', 'rentopian-sync')?>
                        </label>
                    </div>
                    <div class="rntp-sync-field">
                        <div class="rntp-checkbox inline">
                            <label>
                                <input id="rental-checkbox-sync-files-manual" name="rental-checkbox-sync-files-manual" type="checkbox" value="true" <?php if( esc_attr( $sync_files_manual )):?>checked="checked"<?php endif?> />
                                <span></span>
                            </label>
                        </div>
                    </div>
                    <div class="clearfix"></div>
                </div>
                <div class="panel-footer">
                    <div class="submit">

                        <?php if($synchronize_status == 1): ?>
                            <p>
                                <?php _e('Status: The synchronization has been completed successfully.', 'rentopian-sync') ?>
                        
                                <?php
                                    $rental_show_sync_duration = get_option("rental_show_sync_duration", 0);
                                    if ($rental_show_sync_duration):
                                ?>
                                    <strong> (Last File Synchronization took : <?php echo $rental_show_sync_duration; ?> to complete.) </strong>
                                <?php endif; ?>
                            </p>
                           

                            <button type="button" id="rental_resync" class="btn btn-danger">
                                <span class="dashicons dashicons-update"></span>
                                <?php _e('Resynchronize', 'rentopian-sync') ?>
                            </button>
                        <?php else:?>
                            <button type="submit" id="rental_sync" name="submit" class="btn btn-success">
                                <span class="dashicons dashicons-update"></span>
                                <?php _e('Synchronize', 'rentopian-sync') ?>
                            </button>
                        <?php endif?>

                        <?php
                            $rental_file_sync_method = get_option('rental_file_sync_method', 1);
                            if($sync_files_manual != 1): 
                        ?>
                            <div class="sync-options">
                                <p><strong>Select Sync Method:</strong></p>
                                <!-- <p style="color:#fc6223 !important; font-weight:bold !important;"> Notice : After changing the sync method, you MUST do a re-sync. </p> <br/> -->
                                
                                <label class="sync-radio" style="width: 10rem;">
                                    <input type="radio" name="sync_type" value="base64" <?php echo $rental_file_sync_method == 1 ? " checked " : " "; ?> >
                                    Base 64 Encode 
                                </label>

                                <label class="sync-radio" style="width: 10rem;">
                                    <input type="radio" name="sync_type" value="nginx" <?php echo $rental_file_sync_method == 2 ? " checked " : " "; ?>>
                                    Signed URL
                                </label>
                            </div>

                        <?php endif ?>

                        <?php if($sync_files_manual == 1 && $rental_sync_files_manual_index < get_option('rental_sync_files_count', 0)): ?>
                            <button type="submit" id="rental_sync_files_manual" name="submit" data-index="<?php echo $rental_sync_files_manual_index; ?>" class="btn btn-success">
                                <span class="dashicons dashicons-admin-media"></span>
                                <?php _e('Synchronize Files Manually', 'rentopian-sync') ?>
                            </button>
                        <?php endif?>

                        <?php if(defined('WPSEO_VERSION') && get_option('rental_do_not_index_hidden_duplicate_products_for_seo', 0) && !get_option('rental_sync_yoast_seo_plugin_finished', 0)): ?>
                            <button type="submit" id="rental_sync_yoast" name="rental_sync_yoast" class="btn btn-primary">
                                <?php _e('Synchronize Yoast SEO plugin', 'rentopian-sync') ?>
                            </button>
                        <?php endif?>


                        <br/>
                        <br/>
                        <hr/>
                        <br/>
                        <br/>

                        <?php
                            /*
                             * One entry point for this method: the catalog data is
                             * imported in this request, then the images are handed to
                             * the background file sync. Re-sync mode is used because it
                             * refreshes the image count and re-uses attachments that are
                             * already downloaded, which is correct on a first run too.
                             */
                        ?>
                        <button type="button" id="rental_resync_files_bg" class="btn btn-info">
                            <span class="dashicons dashicons-update"></span>
                            <?php _e('Synchronize (Background Files)', 'rentopian-sync') ?>
                        </button>

                        <button type="submit" id="rental_sync_background_cancel" name="submit" class="btn btn-danger">
                            <span class="dashicons dashicons-update"></span>
                            <?php _e('Cancel Synchronization (Background Process)', 'rentopian-sync') ?>
                        </button>

                        <button type="button" id="rental_sync_background_cancel_all" class="btn btn-warning">
                            <span class="dashicons dashicons-dismiss"></span>
                            <?php _e('Cancel ALL Synchronization (Background Process)', 'rentopian-sync'); ?>
                        </button>

                        <?php
                            $syncID = get_option('rental_current_sync_id', '');
                            $failed_ids = rental_failed_get_ids_by_sync($syncID);
                            $has_failed = !empty($failed_ids);
                        ?>

                        <?php if ($has_failed): ?>
                            <button type="button" id="rental_retry_failed" class="btn btn-warning">
                                <span class="dashicons dashicons-image-rotate"></span>
                                <?php _e('Retry Failed Images', 'rentopian-sync') ?>
                            </button>
                        <?php endif; ?>

                        <?php // Anchor for tools injected by other modules (image cleanup). ?>
                        <span id="rental-bg-tools" class="rental-bg-tools"></span>

                        <br/>
                        <br/>
                        <hr/>
                        <br/>

                        <?php
                            // Server-rendered initial state so the label and the
                            // last outcome are correct before the polling script runs.
                            $ds_last_success = class_exists('Rental_Data_Sync_Scheduler')
                                ? Rental_Data_Sync_Scheduler::last_success()
                                : null;
                            $ds_last_run = class_exists('Rental_Data_Sync_Scheduler')
                                ? Rental_Data_Sync_Scheduler::last_run()
                                : null;
                            $ds_button_label = $ds_last_success
                                ? __('Resynchronize Now', 'rentopian-sync')
                                : __('Start Synchronization', 'rentopian-sync');
                        ?>

                        <?php
                            // Report recipients. Both fields are empty until
                            // someone fills them in, and nothing is emailed
                            // until at least one holds a valid address.
                            $ds_report_user = class_exists('Rental_Data_Sync_Reporter')
                                ? (int) get_option(Rental_Data_Sync_Reporter::OPTION_REPORT_USER, 0)
                                : 0;
                            $ds_report_extra = class_exists('Rental_Data_Sync_Reporter')
                                ? (string) get_option(Rental_Data_Sync_Reporter::OPTION_RECIPIENTS, '')
                                : '';
                            $ds_report_users = get_users([
                                'capability' => 'manage_options',
                                'orderby'    => 'display_name',
                                'fields'     => ['ID', 'display_name', 'user_email'],
                            ]);
                        ?>

                        <div class="rntp-sync-panel panel-default rental-ds-panel">
                            <div class="panel-heading">
                                <h3><?php _e('Background Synchronization (Data + Files)', 'rentopian-sync'); ?></h3>

                                <span id="rental_data_sync_last_run" class="rental-ds-last-run"<?php echo $ds_last_run ? '' : ' style="display:none"'; ?>>
                                    <?php if ($ds_last_run): ?>
                                        <span class="rental-ds-badge is-<?php echo esc_attr($ds_last_run['status_label']); ?>">
                                            <?php echo esc_html($ds_last_run['status_label']); ?>
                                        </span>
                                        <?php printf(
                                            /* translators: %s: date/time the last run finished */
                                            esc_html__('Last synchronization: %s', 'rentopian-sync'),
                                            esc_html($ds_last_run['finished_at'])
                                        ); ?>
                                    <?php endif; ?>
                                </span>
                                <div class="clearfix"></div>
                            </div>

                            <div class="panel-body">
                                <div class="rental-form-group rental-ds-report" style="margin: 0 !important;">
                                    <div class="rntp-sync-form-title">
                                        <label class="rental-label" for="rental_data_sync_report_user">
                                            <?php _e('Report email', 'rentopian-sync'); ?>
                                        </label>
                                    </div>

                                    <div class="rntp-sync-field">
                                        <select id="rental_data_sync_report_user" class="rntp-input">
                                            <option value="0"><?php _e('— no administrator —', 'rentopian-sync'); ?></option>
                                            <?php foreach ($ds_report_users as $ds_user): ?>
                                                <?php if (empty($ds_user->user_email)) { continue; } ?>
                                                <option value="<?php echo esc_attr($ds_user->ID); ?>"
                                                    <?php selected($ds_report_user, (int) $ds_user->ID); ?>>
                                                    <?php echo esc_html($ds_user->display_name . ' (' . $ds_user->user_email . ')'); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>

                                        <input type="text"
                                               id="rental_data_sync_report_extra"
                                               class="rntp-input"
                                               size="30"
                                               value="<?php echo esc_attr($ds_report_extra); ?>"
                                               placeholder="<?php esc_attr_e('name@example.com', 'rentopian-sync'); ?>"/>

                                        <button type="button" id="rental_data_sync_report_save" class="btn btn-inline-success btn-sm">
                                            <span class="dashicons dashicons-yes"></span>
                                            <?php _e('Save', 'rentopian-sync'); ?>
                                        </button>

                                        <p id="rental_data_sync_report_status" class="rental-ds-muted"></p>
                                    </div>

                                    <div class="rntp-help-block">
                                        <div class="rntp-help-block-content">
                                            <?php _e('Where the summary of each run is sent. Leave both empty to send no email — the full report is always written to the synchronization log either way.', 'rentopian-sync'); ?>
                                        </div>
                                    </div>
                                    <!-- <div class="clearfix"></div> -->
                                </div>

                                <div id="rental_data_sync_notice" style="display:none"></div>

                                <div id="rental_data_sync_status" class="rental-ds-status is-idle">
                                    <p class="rental-ds-muted">
                                        <?php echo esc_html($ds_last_success
                                            ? __('No run in progress. The catalog was synchronized previously.', 'rentopian-sync')
                                            : __('No background data sync has been run yet.', 'rentopian-sync')); ?>
                                    </p>
                                </div>

                                <p class="rental-ds-muted rental-ds-open-log">
                                    <a href="<?php echo esc_url(admin_url('admin.php?page=rentopian-log')); ?>">
                                        <?php _e('Open the synchronization log', 'rentopian-sync'); ?>
                                    </a>
                                    <?php _e('— every run, of every type, with its full log.', 'rentopian-sync'); ?>
                                </p>
                            </div>

                            <div class="panel-footer settings-panel-footer">
                                <div class="submit rental-ds-controls">
                                    <button type="button" id="rental_data_sync_start_btn" class="btn btn-success">
                                        <span class="dashicons dashicons-update"></span>
                                        <span class="rental-ds-btn-label"><?php echo esc_html($ds_button_label); ?></span>
                                    </button>

                                    <button type="button" id="rental_data_sync_cancel_btn" class="btn btn-inline-danger" disabled>
                                        <span class="dashicons dashicons-no"></span>
                                        <?php _e('Stop', 'rentopian-sync'); ?>
                                    </button>

                                    <button type="button" id="rental_data_sync_cancel_all_btn" class="btn btn-inline-danger">
                                        <span class="dashicons dashicons-dismiss"></span>
                                        <?php _e('Stop All', 'rentopian-sync'); ?>
                                    </button>
                                </div>
                            </div>
                        </div>

                        <br/>
                        <br/>
                        <br/>
                        <br/>

                    </div>
                </div>
            </form>
        </div>
    </div>

    <div id="rental_load" class="rental-modal">
        <div class="rental-modal-content">
            <h3><?php _e('Rentopian Sync', 'rentopian-sync'); ?></h3>
            <hr/>
            <div class="rental-loader-block">
                <p><?php  _e('Synchronization is in progress, please wait...', 'rentopian-sync'); ?></p>
                <div class="rental-loader-border">
                    <div class="rental-loader-progress"></div>
                </div>
                <span class="rental-loader-percent">0%</span>
            </div>
            <div class="rental-error-block" style="display: none">
                <p class="rental-error-message"></p>
                <button class="rental-loading-finish button"><?php _e("Cancel", 'rentopian-sync'); ?></button>
            </div>
        </div>
    </div>

    <div id="rental_confirmation_resync" class="rental-modal">
        <div class="rental-modal-content">
            <h3><?php _e('Rentopian Sync', 'rentopian-sync'); ?></h3>
            <hr/>
            <p><?php  _e('Are you sure you want to re-run the synchronization process?', 'rentopian-sync'); ?></p>
            <div class="rental-modal-footer">
                <button class="rental-modal-cancel btn"><?php _e("Cancel", 'rentopian-sync'); ?></button>
                <button id="rental_confirm_resync" class="btn btn-danger"><?php _e("Confirm", 'rentopian-sync'); ?></button>
            </div>
        </div>
    </div>

    <?php
}


function wp_ajax_rental_sync_yoast_seo_plugin_in_chunks() {
    $offset = isset($_POST['offset']) ? intval($_POST['offset']) : 0;
    $batch_size = 50; 
    $rental_no_index_items = get_option('rental_no_index_items', []);

    if ($rental_no_index_items && get_option('rental_do_not_index_hidden_duplicate_products_for_seo', 0)) {

        $total_items = count($rental_no_index_items);
        $items_to_process = array_slice($rental_no_index_items, $offset, $batch_size);

        // foreach ($items_to_process as $duplicate_hidden_product_id) {
        //     $rental_not_private_items = get_option('rental_not_private_items', []);
        //     if ($rental_not_private_items && isset($rental_not_private_items[$duplicate_hidden_product_id])) {
        //         continue;
        //     }

        //     rental_set_product_general_visibility($duplicate_hidden_product_id, 'private');
        // }

        foreach ($items_to_process as $duplicate_hidden_product_id) {
            $rental_not_private_items = get_option('rental_not_private_items', []);
            if ($rental_not_private_items && isset($rental_not_private_items[$duplicate_hidden_product_id])) {
                continue;
            }

            // rental_set_product_catalog_visibility($duplicate_hidden_product_id, 'hidden');
            update_post_meta($duplicate_hidden_product_id, '_rental_is_add_on', 1);
        }


        foreach ($items_to_process as $duplicate_hidden_product_id) {
            toggle_index_yoast_seo($duplicate_hidden_product_id);
        }

        if ($offset + $batch_size < $total_items) {

            wp_send_json([
                'message' => 'Batch processed successfully',
                'next_offset' => $offset + $batch_size,
                'progress_percent' => floor((($offset + $batch_size) / $total_items) * 100),
                'total_items' => $total_items
            ]);

        } else {

            update_option('rental_sync_yoast_seo_plugin_finished', 1);

            wp_send_json([
                'message' => 'Yoast SEO synchronization was successful',
                'next_offset' => null,
                'progress_percent' => 100,
                'total_items' => $total_items
            ]);
        }

    } else {
        wp_send_json_error(['message' => 'There were no items to sync for Yoast SEO plugin!']);
    }
    wp_die();    
}

function wp_ajax_rental_update_file_sync_settings() {
    if (isset($_POST['sync_files_manual'])) {
        update_option('rental_sync_files_manual', intval($_POST['sync_files_manual']));
        wp_send_json(true, 200);
    }
    wp_die();    
}

function rental_settings_page() {
    global $wpdb, $rental_tables;
    $rental_day_tiers = $wpdb->prefix . $rental_tables["day_tiers"];

    // variables for the field and option names
    $opt_form_layout = 'rental_form_layout';
    $opt_set_listing_style = 'rental_set_listing_style';
    $opt_dates_on_checkout = 'rental_dates_on_checkout';
    $opt_checkout_layout_mode = 'rental_checkout_layout_mode';
    $opt_direct_only_bookings = 'rental_direct_only_bookings';
    $opt_allow_to_pay_deposit = 'rental_allow_to_pay_deposit';
    $opt_allow_to_pay_security_deposit = 'rental_allow_to_pay_security_deposit';
    $opt_allow_to_pay_another_amount = 'rental_allow_to_pay_another_amount';
    $opt_send_email = 'rental_send_email';
    $opt_rental_referral_sources_setting = 'rental_referral_sources_setting';
    $opt_rental_event_types_setting = 'rental_event_types_setting';
    $opt_rental_exclude_order_fees = 'rental_exclude_order_fees';
    $opt_rental_payment_tips_enabled = 'rental_payment_tips_enabled';
    $opt_rental_checkout_photo_upload_enabled = 'rental_checkout_photo_upload_enabled';
    $opt_min_order_amount = 'rental_min_order_amount';
    $opt_min_order_text = 'rental_min_order_text';
    $opt_min_order_pickup = 'rental_min_order_pickup';
    $opt_special_terms = 'rental_special_terms';
    $opt_client_blacklisted_notif_email = 'rental_client_blacklisted_notif_email';
    $opt_client_blacklisted_reject_msg = 'rental_client_blacklisted_reject_msg';
    $opt_dates_header_label = 'rental_dates_header_label';
    $opt_select_division = 'rental_select_division';
    $opt_hide_zip = 'rental_hide_zip';
    $opt_event_start_time = 'rental_event_start_time';
    $opt_hide_end_date = 'rental_hide_end_date';
    $opt_multi_day_event = 'rental_multi_day_event';
    $opt_hide_time_pickers = 'rental_hide_time_pickers';
    $opt_min_start_date = 'rental_min_start_date';
    $opt_min_dates_range = 'rental_min_dates_range';
    $opt_max_dates_range = 'rental_max_dates_range';
    $opt_date_step = 'rental_date_step';
    $opt_select_date_range = 'rental_select_date_range';
    $opt_hide_damage_waiver = 'rental_hide_damage_waiver';
    $opt_buy_damage_waiver_by_default = 'rental_buy_damage_waiver_by_default';
    $opt_hide_and_buy_damage_waiver_by_default = 'rental_hide_and_buy_damage_waiver_by_default';
    $opt_show_damage_waiver_on_cart = 'rental_show_damage_waiver_on_cart';
    $opt_disabled_week_days = 'rental_disabled_week_days';
    $opt_hide_set_items = 'rental_hide_set_items';
    $opt_filter_unavailable_products = 'rental_filter_unavailable_products';
    $opt_group_attribute_values_in_filters = Rental_Attribute_Groups::SETTING;
    $opt_display_sale_products_page = 'rental_display_sale_products_page';
    $opt_hide_product_type_label = 'rental_hide_product_type_label';
    $opt_hide_product_price = 'rental_hide_product_price';
    $opt_show_product_price_only_in_cart = 'rental_show_product_price_only_in_cart';
    $opt_do_not_use_rentopian_shipping = 'rental_do_not_use_rentopian_shipping';
    $opt_track_wc_shipping = 'rental_track_wc_shipping';
    $opt_different_pick_up_addresses = 'rental_different_pick_up_addresses';
    $opt_double_shipping_fee = 'rental_double_shipping_fee';
    $opt_combine_shipping_tax = 'rental_combine_shipping_tax';
    $opt_google_distance_key = 'rental_google_distance_key';
    $opt_free_shipping_amount = 'rental_free_shipping_amount';
    $opt_pickup_delivery = 'rental_pickup_delivery';
    $opt_full_payment_for_delivery = 'rental_full_payment_for_delivery';
    $opt_special_terms_placement = 'rental_special_terms_placement';
    $opt_overbook_text = 'rental_overbook_text';
    $opt_cart_button_text = 'rental_cart_button_text';
    $opt_order_text = 'rental_order_text';
    $opt_read_more_text = 'rental_read_more_text';
    $opt_checkout_button_text = 'rental_checkout_button_text';
    $opt_order_button_text = 'rental_order_button_text';
    $opt_daily_fee_text = 'rental_daily_fee_text';
    $opt_tax_text = 'rental_tax_text';
    $opt_shipping_text = 'rental_shipping_text';
    $opt_ship_to_dif_adrs_text = 'rental_ship_to_dif_adrs_text';
    $opt_select_option_text = 'rental_select_option_text';
    $opt_start_date_text = 'rental_start_date_text';
    $opt_end_date_text = 'rental_end_date_text';
    $opt_proceed_text = 'rental_proceed_text';
    $opt_set_components_text = 'rental_set_components_text';
    $opt_referral_sources_text = 'rental_referral_sources_text';
    $opt_event_types_text = 'rental_event_types_text';
    $opt_delivery_time_label = 'rental_delivery_time_label';
    $opt_pickup_time_label = 'rental_pickup_time_label';
    $opt_payment_tips_text = 'rental_payment_tips_text';
    $opt_thank_you_message = 'rental_thank_you_message';
    $opt_miles_text = 'rental_miles_shipping_label_text';
    $opt_billing_details_text = 'rental_billing_details_text';
    $opt_coupon_label_text = 'rental_coupon_label_text';
    $opt_street_address_label_text = 'rental_street_address_label_text';
    $opt_not_open_dates_form = 'rental_not_open_dates_form';
    $opt_auto_date_show_product_selection = 'rental_auto_date_show_product_selection';
    $opt_auto_add_to_cart_on_date_selection = 'rental_auto_add_to_cart_on_date_selection';
    $opt_location = 'rental_show_location';
    $opt_google_map_key = 'rental_google_map_key';
    $opt_select_a_day_by_default = 'rental_select_a_day_by_default';
    $opt_selected_default_day = 'rental_selected_default_day';
    $opt_default_zip_code = 'rental_default_zip_code';
    $opt_default_start_time = 'rental_default_start_time';
    $opt_default_end_time = 'rental_default_end_time';

    $not_only_sale_products = get_option('rental_synchronized_product_type') != "sale";
    $not_only_hourly_products = get_option('rental_synchronized_product_type') != "hourly";

    // read in existing option value from database
    $val_form_layout = get_option($opt_form_layout);
    $val_set_listing_style = get_option($opt_set_listing_style, 'standard');
    $val_dates_on_checkout = get_option($opt_dates_on_checkout);
    $val_checkout_layout_mode = get_option($opt_checkout_layout_mode, 'classic');
    if ($val_checkout_layout_mode !== 'modern') {
        $val_checkout_layout_mode = 'classic';
    }
    $val_direct_only_bookings = get_option($opt_direct_only_bookings);
    $val_allow_to_pay_deposit = get_option($opt_allow_to_pay_deposit);
    $val_allow_to_pay_another_amount = get_option($opt_allow_to_pay_another_amount);
    $val_allow_to_pay_security_deposit = get_option($opt_allow_to_pay_security_deposit);
    $val_send_email = get_option($opt_send_email);
    $val_rental_referral_sources_setting = get_option($opt_rental_referral_sources_setting);
    $val_rental_event_types_setting = get_option($opt_rental_event_types_setting);
    $val_rental_exclude_order_fees = get_option($opt_rental_exclude_order_fees);
    $val_rental_payment_tips_enabled = get_option($opt_rental_payment_tips_enabled);
    $val_rental_checkout_photo_upload_enabled = get_option($opt_rental_checkout_photo_upload_enabled);

    $val_client_blacklisted_notif_email = get_option($opt_client_blacklisted_notif_email);
    $val_min_order_text = get_option($opt_min_order_text);
    $val_min_order_pickup = get_option($opt_min_order_pickup);
    $val_special_terms = get_option($opt_special_terms);
    $val_client_blacklisted_reject_msg = get_option($opt_client_blacklisted_reject_msg, 'Sorry, your request has been declined');
    $val_dates_header_label = get_option($opt_dates_header_label);
    $val_select_division = get_option($opt_select_division);
    if ($not_only_sale_products && $not_only_hourly_products) {
        $val_hide_zip = get_option($opt_hide_zip);
        $val_event_start_time = get_option($opt_event_start_time);
        $val_hide_end_date = get_option($opt_hide_end_date);
        $val_multi_day_event = get_option($opt_multi_day_event);
        $val_hide_time_pickers = get_option($opt_hide_time_pickers);
        $val_min_start_date = get_option($opt_min_start_date);
        $val_min_dates_range = get_option($opt_min_dates_range);
        $val_max_dates_range = get_option($opt_max_dates_range);
        $val_date_step = get_option($opt_date_step);
        $val_select_date_range = get_option($opt_select_date_range);
        $val_hide_damage_waiver = get_option($opt_hide_damage_waiver);
        $val_buy_damage_waiver_by_default = get_option($opt_buy_damage_waiver_by_default);
        $val_hide_and_buy_damage_waiver_by_default = get_option($opt_hide_and_buy_damage_waiver_by_default);
        $val_show_damage_waiver_on_cart = get_option($opt_show_damage_waiver_on_cart);
        $val_disabled_week_days = get_option($opt_disabled_week_days);
        $val_location = get_option($opt_location);
        $val_select_a_day_by_default = get_option($opt_select_a_day_by_default);
        $val_selected_default_day = get_option($opt_selected_default_day);
        $val_default_start_time = get_option($opt_default_start_time, '9:00 AM');
        $val_default_end_time = get_option($opt_default_end_time, '5:00 PM');
    }
    $val_hide_set_items = get_option($opt_hide_set_items, 0);
    $val_filter_unavailable_products = get_option($opt_filter_unavailable_products, 1);
    $val_group_attribute_values_in_filters = get_option($opt_group_attribute_values_in_filters, 0);
    $val_display_sale_products_page = get_option($opt_display_sale_products_page, 0);
    $val_hide_product_type_label = get_option($opt_hide_product_type_label, 1);
    $val_hide_product_price = get_option($opt_hide_product_price);
    $val_show_product_price_only_in_cart = get_option($opt_show_product_price_only_in_cart);
    $val_cart_button_text = get_option($opt_cart_button_text);
    $val_checkout_button_text = get_option($opt_checkout_button_text);
    $val_order_button_text = get_option($opt_order_button_text);
    $val_order_text = get_option($opt_order_text);
    $val_read_more_text = get_option($opt_read_more_text);
    $val_daily_fee_text = get_option($opt_daily_fee_text);
    $val_shipping_text = get_option($opt_shipping_text);
    $val_ship_to_dif_adrs_text = get_option($opt_ship_to_dif_adrs_text);
    $val_tax_text = get_option($opt_tax_text);
    $val_select_option_text = get_option($opt_select_option_text);
    $val_start_date_text = get_option($opt_start_date_text);
    $val_end_date_text = get_option($opt_end_date_text);
    $val_proceed_text = get_option($opt_proceed_text);
    $val_set_components_text = get_option($opt_set_components_text);

    $val_referral_sources_text = get_option($opt_referral_sources_text);
    $val_event_types_text = get_option($opt_event_types_text);
    $val_delivery_time_label = get_option($opt_delivery_time_label);
    $val_pickup_time_label = get_option($opt_pickup_time_label);
    $val_payment_tips_text = get_option($opt_payment_tips_text);
    $val_thank_you_message = get_option($opt_thank_you_message);
    $val_miles_text = get_option($opt_miles_text);
    $val_billing_details_text = get_option($opt_billing_details_text);
    $val_coupon_label_text = get_option($opt_coupon_label_text);
    $val_street_address_label_text = get_option($opt_street_address_label_text);
    $val_do_not_use_rentopian_shipping = get_option($opt_do_not_use_rentopian_shipping);
    $val_track_wc_shipping = get_option($opt_track_wc_shipping);
    $val_different_pick_up_addresses = get_option($opt_different_pick_up_addresses);
    $val_double_shipping_fee = get_option($opt_double_shipping_fee);
    $val_combine_shipping_tax = get_option($opt_combine_shipping_tax);
    $val_google_distance_key = get_option($opt_google_distance_key);
    $val_free_shipping_amount = get_option($opt_free_shipping_amount);
    $val_pickup_delivery = get_option($opt_pickup_delivery, 'company_delivery_return');
    $val_full_payment_for_delivery = get_option($opt_full_payment_for_delivery);
    $val_special_terms_placement = get_option($opt_special_terms_placement, 'special_terms_on_top');
    $val_overbook_text = get_option($opt_overbook_text);
    $val_not_open_dates_form = get_option($opt_not_open_dates_form);
    $val_auto_date_show_product_selection = get_option($opt_auto_date_show_product_selection);
    $val_auto_add_to_cart_on_date_selection = get_option($opt_auto_add_to_cart_on_date_selection, 1);
    $val_google_map_key = get_option($opt_google_map_key);

    $tiers = $wpdb->get_results("SELECT * FROM $rental_day_tiers");
    
    ?>
    <div class="wrap">
        <h2 class="rental-title"><?php _e("Plugin Options", 'rentopian-sync'); ?></h2>
        <hr/>

        <div id="rental_settings" class="rntp-sync-panel">

            <?php wp_nonce_field( 'rental_settings_save', 'rental_settings_nonce' ); ?>

                <div class="rental-tabs">
                    <ul class="rental-tabs__list">
                        <li><a><?php _e('General Settings', 'rentopian-sync')?></a></li>
                        <li><a><?php _e('Textual Labels', 'rentopian-sync')?></a></li>
                        <li><a><?php _e('Multiplication Tiers', 'rentopian-sync')?></a></li>
                    </ul>
                    <div class="rental-tabs__tab-content">
                        <div class="rental-tab">

                        <form method="post"
                            class="rental-settings-section-form"
                            data-section="general"
                            id="rental_settings_general">

                            <?php wp_nonce_field('rental_settings_section_save', 'rental_settings_section_nonce'); ?>
                            <input type="hidden" name="action" value="rental_save_settings_section" />
                            <input type="hidden" name="section" value="general" />

                            <div class="section-wrapper">
                                <!-- Layout section -->
                                <div class="rental-inner-title">
                                    <h3><?php _e('Layout', 'rentopian-sync'); ?></h3>
                                </div>
                                    
                                    <div class="rental-form-group">
                                        <div class="rntp-sync-form-title">
                                            <label class="rental-label">
                                                <?php _e('Booking Form Type', 'rentopian-sync')?>
                                            </label>
                                        </div>
                                        <div class="rntp-sync-field">
                                            <div class="rntp-radio inline">
                                                <input id="rntp-form-horizontal"
                                                    name="<?php echo esc_attr($opt_form_layout); ?>"
                                                    required="required"
                                                    type="radio"
                                                    value="horizontal"
                                                    <?php if( esc_attr( $val_form_layout )  === 'horizontal' ):?>checked="checked"<?php endif?>
                                                />
                                                <label for="rntp-form-horizontal" class="radio-label">Standalone, Horizontal</label>
                                            </div>
                                            <div class="rntp-radio inline">
                                                <input id="rntp-form-in-cart"
                                                    required="required"
                                                    name="<?php echo esc_attr($opt_form_layout); ?>"
                                                    type="radio"
                                                    value="in-cart"
                                                    <?php if( esc_attr( $val_form_layout ) === 'in-cart'):?>checked="checked"<?php endif?>
                                                />
                                                <label for="rntp-form-in-cart" class="radio-label">Inside Fly-In Cart</label>
                                            </div>
                                        </div>
                                        <div class="rntp-help-block">
                                            <div class="rntp-help-block-content">
                                                <?php _e('The "Inside Fly-In Cart" option is not supported by all themes. It is recommended to use a theme provided by Rentopian for guaranteed compatibility.', 'rentopian-sync')?>
                                            </div>
                                        </div>
                                        <div class="clearfix"></div>
                                    </div>

                                    <div class="rental-form-group">
                                        <div class="rntp-sync-form-title">
                                            <label class="rental-label">
                                                <?php _e('Textual header (label) for Fly-In Cart booking type', 'rentopian-sync')?>
                                            </label>
                                        </div>
                                        <div class="rntp-sync-field">
                                            <textarea id="rental_dates_header_label" name="<?php echo $opt_dates_header_label; ?>"><?php echo $val_dates_header_label? $val_dates_header_label: ''; ?></textarea>
                                        </div>
                                        <div class="rntp-help-block">
                                            <div class="rntp-help-block-content">
                                                <?php _e('The "Textual header (label) for Fly-In Cart" option appears on top of the date selection form in fly-in cart section if not blank.', 'rentopian-sync')?>
                                            </div>
                                        </div>
                                        <div class="clearfix"></div>
                                    </div>
                                    
                                    <div class="rental-form-group">
                                        <div class="rntp-sync-form-title">
                                            <label for="rntp-checkbox-<?php echo esc_attr($opt_dates_on_checkout)?>" class="rental-label">
                                                <?php _e('Show the dates form only on the checkout page', 'rentopian-sync')?>
                                            </label>
                                        </div>
                                        <div class="rntp-sync-field">
                                            <div class="rntp-checkbox inline">
                                                <label>
                                                    <input id="rntp-checkbox-<?php echo esc_attr($opt_dates_on_checkout)?>" name="<?php echo esc_attr($opt_dates_on_checkout); ?>" type="checkbox" value="true" <?php if( esc_attr( $val_dates_on_checkout )):?>checked="checked"<?php endif; if (!get_option('rental_allow_overbook', 0)):?> disabled=true <?php endif;?> />
                                                    <span></span>
                                                </label>
                                            </div>
                                        </div>
                                        <div class="rntp-help-block">
                                            <div class="rntp-help-block-content">
                                                <?php _e('Show the start/end dates form only in checkout page.', 'rentopian-sync')?>
                                                <?php if (!get_option('rental_allow_overbook', 0)): _e('It works only when overbooks are allowed.', 'rentopian-sync'); endif;?>
                                            </div>
                                        </div>
                                        <div class="clearfix"></div>
                                    </div>

                                    <!-- Checkout Layout Mode -->
                                    <div class="rental-form-group"
                                         id="rental_checkout_layout_mode_wrapper"
                                         <?php if (empty($val_dates_on_checkout)) : ?>style="display:none"<?php endif; ?>>

                                        <div class="rntp-sync-form-title">
                                            <label class="rental-label">
                                                <?php _e('Checkout Layout Mode', 'rentopian-sync'); ?>
                                            </label>
                                        </div>
                                        <div class="rntp-sync-field">
                                            <div class="rntp-radio inline">
                                                <input id="rntp-checkout-layout-classic"
                                                       name="<?php echo esc_attr($opt_checkout_layout_mode); ?>"
                                                       type="radio"
                                                       value="classic"
                                                       <?php if ($val_checkout_layout_mode === 'classic') : ?>checked="checked"<?php endif; ?> />
                                                <label for="rntp-checkout-layout-classic" class="radio-label">
                                                    <?php _e('Classic', 'rentopian-sync'); ?>
                                                </label>
                                            </div>

                                            <div class="rntp-radio inline">
                                                <input id="rntp-checkout-layout-modern"
                                                       name="<?php echo esc_attr($opt_checkout_layout_mode); ?>"
                                                       type="radio"
                                                       value="modern"
                                                       <?php if ($val_checkout_layout_mode === 'modern') : ?>checked="checked"<?php endif; ?> />
                                                <label for="rntp-checkout-layout-modern" class="radio-label">
                                                    <?php _e('Modern', 'rentopian-sync'); ?>
                                                </label>
                                            </div>

                                             <!-- Checkout Layout Builder Link (shown only when modern mode is selected) -->
                                            <?php 
                                                $checkout_layout_url = admin_url('admin.php?page=rentopian-checkout-layout');
                                                $is_modern_mode = $val_checkout_layout_mode === 'modern';
                                            ?>
                                            <div id="rental-checkout-layout-link-wrapper" 
                                                class="rental-checkout-layout-link-wrapper"
                                                style="<?php echo $is_modern_mode ? '' : 'display:none;'; ?>margin-top: 15px;">
                                                <a href="<?php echo esc_url($checkout_layout_url); ?>" 
                                                target="_blank" 
                                                class="rental-checkout-layout-link">
                                                    <span class="dashicons dashicons-layout"></span>
                                                    <?php _e('Open Checkout Layout Builder', 'rentopian-sync'); ?>
                                                    <span class="dashicons dashicons-external"></span>
                                                </a>
                                            </div>
                                            
                                        </div>
                                        <div class="rntp-help-block">
                                            <div class="rntp-help-block-content">
                                                <?php _e(
                                                    'Select how the checkout layout should behave when the dates form is only shown on the checkout page.',
                                                    'rentopian-sync'
                                                ); ?>
                                            </div>
                                        </div>
                                        
                                       
                                        
                                        <div class="clearfix"></div>
                                    </div>

                                <!-- Layout section -->
                            </div>
                           


                            <div class="section-wrapper">
                                <!-- Quotes / Orders section -->
                                    <div class="rental-inner-title">
                                        <h3>Quotes / Orders</h3>
                                    </div>

                                        <div class="rental-form-group">
                                            <div class="rntp-sync-form-title">
                                                <label for="rntp-checkbox-<?php echo esc_attr($opt_direct_only_bookings)?>" class="rental-label">
                                                    <?php _e('Allow Direct Bookings', 'rentopian-sync')?>
                                                </label>
                                            </div>
                                            <div class="rntp-sync-field">
                                                <div class="rntp-checkbox inline">
                                                    <label>
                                                        <input id="rntp-checkbox-<?php echo esc_attr($opt_direct_only_bookings)?>" name="<?php echo esc_attr($opt_direct_only_bookings); ?>" type="checkbox" value="true" <?php if( esc_attr( $val_direct_only_bookings )):?>checked="checked"<?php endif?> />
                                                        <span></span>
                                                    </label>
                                                </div>
                                            </div>
                                            <div class="rntp-help-block">
                                                <div class="rntp-help-block-content">
                                                    <?php _e('Allow visitors add items to cart and checkout directly, without sending a quote first.', 'rentopian-sync')?>
                                                </div>
                                            </div>
                                            <div class="clearfix"></div>
                                        </div>
                                        <div class="rental-form-group">
                                            <div class="rntp-sync-form-title">
                                                <label class="rental-label" for="rntp-checkbox-<?php echo esc_attr($opt_allow_to_pay_deposit)?>">
                                                    <?php _e('Allow Deposit', 'rentopian-sync')?>
                                                </label>
                                            </div>
                                            <div class="rntp-sync-field">
                                                <div class="rntp-checkbox inline">
                                                    <label>
                                                        <input id="rntp-checkbox-<?php echo esc_attr($opt_allow_to_pay_deposit)?>"
                                                            name="<?php echo esc_attr($opt_allow_to_pay_deposit); ?>"
                                                            type="checkbox"
                                                            value="true"
                                                            <?php echo $val_allow_to_pay_deposit? 'checked': ''; echo $val_direct_only_bookings? '': ' disabled' ?>/>
                                                        <span></span>
                                                    </label>
                                                </div>
                                            </div>
                                            <div class="rntp-help-block">
                                                <div class="rntp-help-block-content">
                                                    <?php _e('Allow checkout by paying the deposit only.', 'rentopian-sync')?>
                                                </div>
                                            </div>
                                            <div class="clearfix"></div>
                                        </div>
                                        <div class="rental-form-group" id="rental-another-amount-group" <?php if( !$val_allow_to_pay_deposit): ?>style="display: none"<?php endif; ?>>
                                            <div>
                                                <input id='rental-disable-another-amount' name="<?php echo esc_attr($opt_allow_to_pay_another_amount); ?>"
                                                    type='radio' <?php echo $val_allow_to_pay_another_amount? '': 'checked'; ?> class='input-radio' value=''>
                                                <label for='rental-disable-another-amount'><?php _e('Either exact deposit sum or full sum must be paid.', 'rentopian-sync'); ?></label>
                                                <input id='rental-enable-another-amount' name="<?php echo esc_attr($opt_allow_to_pay_another_amount); ?>"
                                                    type='radio' <?php echo $val_allow_to_pay_another_amount? 'checked': ''; ?> class='input-radio' value='1'>
                                                <label for='rental-enable-another-amount'><?php _e('Any amount from deposit sum to order total sum is allowed.', 'rentopian-sync'); ?></label>
                                            </div>
                                            <div class="clearfix"></div>
                                        </div>
                                        <!-- security deposit -->
                                        <div class="rental-form-group">
                                            <div class="rntp-sync-form-title"> 
                                                <label class="rental-label" for="rntp-checkbox-<?php echo esc_attr($opt_allow_to_pay_security_deposit)?>">
                                                    <?php _e('Enforce Security Deposit', 'rentopian-sync')?>
                                                </label>
                                            </div>
                                            <div class="rntp-sync-field">
                                                <div class="rntp-checkbox inline">
                                                    <label>
                                                        <input id="rntp-checkbox-<?php echo esc_attr($opt_allow_to_pay_security_deposit)?>"
                                                            name="<?php echo esc_attr($opt_allow_to_pay_security_deposit); ?>"
                                                            type="checkbox"
                                                            value="true"
                                                            <?php echo $val_allow_to_pay_security_deposit? 'checked': ''; ?>/>
                                                        <span></span>
                                                    </label>
                                                </div>
                                            </div>
                                            <div class="rntp-help-block">
                                                <div class="rntp-help-block-content">
                                                    <?php _e('Allow checkout by paying the security deposit only.', 'rentopian-sync')?>
                                                </div>
                                            </div>
                                            <div class="clearfix"></div>
                                        </div>
                                        <!-- end of security deposit -->
                                        <div class="rental-form-group">
                                            <div class="rntp-sync-form-title">
                                                <label for="rntp-checkbox-<?php echo esc_attr($opt_send_email)?>" class="rental-label">
                                                    <?php _e('Send email once a quote/order is placed', 'rentopian-sync')?>
                                                </label>
                                            </div>
                                            <div class="rntp-sync-field">
                                                <div class="rntp-checkbox inline">
                                                    <label>
                                                        <input id="rntp-checkbox-<?php echo esc_attr($opt_send_email)?>" name="<?php echo esc_attr($opt_send_email); ?>" type="checkbox" value="true" <?php if(esc_attr($val_send_email)):?>checked="checked"<?php endif?> />
                                                        <span></span>
                                                    </label>
                                                </div>
                                            </div>
                                            <div class="rntp-help-block">
                                                <div class="rntp-help-block-content">
                                                    <?php _e('Send an email to the company about the new quote/order when it is registered in the Rentopian system.', 'rentopian-sync')?>
                                                </div>
                                            </div>
                                            <div class="clearfix"></div>
                                        </div>

                                        <div class="rental-form-group">
                                            <div class="rntp-sync-form-title">
                                                <label for="rntp-checkbox-<?php echo esc_attr($opt_rental_referral_sources_setting)?>" class="rental-label">
                                                    <?php _e('Checkout page - show referral sources selection', 'rentopian-sync')?>
                                                </label>
                                            </div>
                                            <div class="rntp-sync-field">
                                                <div class="rntp-checkbox inline">
                                                    <label>
                                                        <input id="rntp-checkbox-<?php echo esc_attr($opt_rental_referral_sources_setting)?>" name="<?php echo esc_attr($opt_rental_referral_sources_setting); ?>" type="checkbox" value="true" <?php if(esc_attr($val_rental_referral_sources_setting)):?>checked="checked"<?php endif?> />
                                                        <span></span>
                                                    </label>
                                                </div>
                                            </div>
                                            <div class="rntp-help-block">
                                                <div class="rntp-help-block-content">
                                                    <?php _e('Will show a dropdown on the checkout page, suggesting to select a referral source.', 'rentopian-sync')?>
                                                </div>
                                            </div>
                                            <div class="clearfix"></div>
                                        </div>

                                        <div class="rental-form-group">
                                            <div class="rntp-sync-form-title">
                                                <label for="rntp-checkbox-<?php echo esc_attr($opt_rental_event_types_setting)?>" class="rental-label">
                                                    <?php _e('Checkout page - show Event Types selection', 'rentopian-sync')?>
                                                </label>
                                            </div>
                                            <div class="rntp-sync-field">
                                                <div class="rntp-checkbox inline">
                                                    <label>
                                                        <input id="rntp-checkbox-<?php echo esc_attr($opt_rental_event_types_setting)?>" name="<?php echo esc_attr($opt_rental_event_types_setting); ?>" type="checkbox" value="true" <?php if(esc_attr($val_rental_event_types_setting)):?>checked="checked"<?php endif?> />
                                                        <span></span>
                                                    </label>
                                                </div>
                                            </div>
                                            <div class="rntp-help-block">
                                                <div class="rntp-help-block-content">
                                                    <?php _e('Will show a dropdown on the checkout page, suggesting to select an Event Type.', 'rentopian-sync')?>
                                                </div>
                                            </div>
                                            <div class="clearfix"></div>
                                        </div>

                                        <div class="rental-form-group">
                                            <div class="rntp-sync-form-title">
                                                <label for="rntp-checkbox-<?php echo esc_attr($opt_rental_exclude_order_fees)?>" class="rental-label">
                                                    <?php _e('Exclude auto applied fees from the quotes/orders', 'rentopian-sync')?>
                                                </label>
                                            </div>
                                            <div class="rntp-sync-field">
                                                <div class="rntp-checkbox inline">
                                                    <label>
                                                        <input id="rntp-checkbox-<?php echo esc_attr($opt_rental_exclude_order_fees)?>" name="<?php echo esc_attr($opt_rental_exclude_order_fees); ?>" type="checkbox" value="true" <?php if(esc_attr($val_rental_exclude_order_fees)):?>checked="checked"<?php endif?> />
                                                        <span></span>
                                                    </label>
                                                </div>
                                            </div>
                                            <div class="rntp-help-block">
                                                <div class="rntp-help-block-content">
                                                    <?php _e('Will remove the auto applied fees from the quotes/orders.', 'rentopian-sync')?>
                                                </div>
                                            </div>
                                            <div class="clearfix"></div>
                                        </div>

                                        <div class="rental-form-group">
                                            <div class="rntp-sync-form-title">
                                                <label for="rntp-checkbox-<?php echo esc_attr($opt_rental_payment_tips_enabled)?>" class="rental-label">
                                                    <?php _e('Enable payment tips', 'rentopian-sync')?>
                                                </label>
                                            </div>
                                            <div class="rntp-sync-field">
                                                <div class="rntp-checkbox inline">
                                                    <label>
                                                        <input id="rntp-checkbox-<?php echo esc_attr($opt_rental_payment_tips_enabled)?>" name="<?php echo esc_attr($opt_rental_payment_tips_enabled); ?>" type="checkbox" value="true" <?php if(esc_attr($val_rental_payment_tips_enabled)):?>checked="checked"<?php endif?> />
                                                        <span></span>
                                                    </label>
                                                </div>
                                            </div>
                                            <div class="rntp-help-block">
                                                <div class="rntp-help-block-content">
                                                    <?php _e('Will enable displaying the list of payment tips for customers to choose from on checkout page for orders with direct payment.', 'rentopian-sync')?>
                                                </div>
                                            </div>
                                            <div class="clearfix"></div>
                                        </div>

                                        <div class="rental-form-group">
                                            <div class="rntp-sync-form-title">
                                                <label for="rntp-checkbox-<?php echo esc_attr($opt_rental_checkout_photo_upload_enabled)?>" class="rental-label">
                                                    <?php _e('Enable checkout photo upload', 'rentopian-sync')?>
                                                </label>
                                            </div>
                                            <div class="rntp-sync-field">
                                                <div class="rntp-checkbox inline">
                                                    <label>
                                                        <input id="rntp-checkbox-<?php echo esc_attr($opt_rental_checkout_photo_upload_enabled)?>" name="<?php echo esc_attr($opt_rental_checkout_photo_upload_enabled); ?>" type="checkbox" value="true" <?php if(esc_attr($val_rental_checkout_photo_upload_enabled)):?>checked="checked"<?php endif?> />
                                                        <span></span>
                                                    </label>
                                                </div>
                                            </div>
                                            <div class="rntp-help-block">
                                                <div class="rntp-help-block-content">
                                                    <?php _e('Allow customers to upload up to 5 inspiration photos (JPG/PNG, max 2MB each) during checkout. Photos are sent with the order to Rentopian.', 'rentopian-sync')?>
                                                </div>
                                            </div>
                                            <div class="clearfix"></div>
                                        </div>

                                <!-- Quotes / Orders section -->
                            </div>

                            <div class="section-wrapper">
                                <!-- Blacklists section -->
                                <div class="rental-inner-title">
                                    <h3>Blacklists</h3>
                                </div>

                                    
                                    <div class="rental-form-group">
                                        <div class="rntp-sync-form-title">
                                            <label for="rntp-checkbox-<?php echo esc_attr($opt_client_blacklisted_notif_email)?>" class="rental-label">
                                                <?php _e('Notify admin about blacklisted client activity', 'rentopian-sync')?>
                                            </label>
                                        </div>
                                        <div class="rntp-sync-field">
                                            <div class="rntp-checkbox inline">
                                                <label>
                                                    <input id="rntp-checkbox-<?php echo esc_attr($opt_client_blacklisted_notif_email)?>" name="<?php echo esc_attr($opt_client_blacklisted_notif_email); ?>" type="checkbox" value="true" <?php if(esc_attr($val_client_blacklisted_notif_email)):?>checked="checked"<?php endif?> />
                                                    <span></span>
                                                </label>
                                            </div>
                                        </div>
                                        <div class="rntp-help-block">
                                            <div class="rntp-help-block-content">
                                                <?php _e('Send an email to the admin user(s) of the company about the blacklisted clients activity.', 'rentopian-sync')?>
                                            </div>
                                        </div>
                                        <div class="clearfix"></div>
                                    </div>
                                    <div class="rental-form-group">
                                        <div class="rntp-sync-form-title">
                                            <label class="rental-label" for="client_blacklisted_reject_msg">
                                                <?php _e('Blacklisted client rejection message', 'rentopian-sync')?>
                                            </label>
                                        </div>
                                        <div class="rntp-sync-field">
                                            <textarea id="client_blacklisted_reject_msg" name="<?php echo $opt_client_blacklisted_reject_msg; ?>"><?php echo $val_client_blacklisted_reject_msg? $val_client_blacklisted_reject_msg: 'Sorry, your request has been declined'; ?></textarea>
                                        </div>
                                        <div class="rntp-help-block">
                                            <div class="rntp-help-block-content">
                                                <?php _e('Rejection message for blacklisted client(s).', 'rentopian-sync')?>
                                            </div>
                                        </div>
                                        <div class="clearfix"></div>
                                    </div>

                                <!-- Blacklists section -->
                            </div>


                            <div class="section-wrapper">
                                <!-- Date / Time / ZIP Code / Location Options section -->
                                <?php if ($not_only_sale_products && $not_only_hourly_products) : ?>

                                    <div class="rental-inner-title">
                                        <h3>Date / Time / ZIP Code / Location Options</h3>
                                    </div>


                                        <div class="rental-form-group">
                                            <div class="rntp-sync-form-title">
                                                <label class="rental-label" for="rntp-checkbox-<?php echo esc_attr($opt_hide_end_date)?>">
                                                    <?php _e('Hide return date', 'rentopian-sync')?>
                                                </label>
                                            </div>
                                            <div class="rntp-sync-field">
                                                <div class="rntp-checkbox inline">
                                                    <label>
                                                        <input id="rntp-checkbox-<?php echo esc_attr($opt_hide_end_date)?>" name="<?php echo esc_attr($opt_hide_end_date); ?>" type="checkbox" value="true" <?php if( esc_attr( $val_hide_end_date)):?>checked="checked"<?php endif?> />
                                                        <span></span>
                                                    </label>
                                                </div>
                                            </div>
                                            <div class="rntp-help-block">
                                                <div class="rntp-help-block-content">
                                                    <?php _e('Hide end date field from shop page.', 'rentopian-sync')?>
                                                </div>
                                            </div>
                                            <div class="clearfix"></div>
                                        </div>

                                        <div class="rental-form-group">
                                            <div class="rntp-sync-form-title">
                                                <label class="rental-label" for="rntp-checkbox-<?php echo esc_attr($opt_multi_day_event)?>">
                                                    <?php _e('Show Multi Day Event checkbox', 'rentopian-sync')?>
                                                </label>
                                            </div>
                                            <div class="rntp-sync-field">
                                                <div class="rntp-checkbox inline">
                                                    <label>
                                                        <input id="rntp-checkbox-<?php echo esc_attr($opt_multi_day_event)?>" name="<?php echo esc_attr($opt_multi_day_event); ?>" type="checkbox" value="true" <?php if( esc_attr( $val_multi_day_event)):?>checked="checked"<?php endif?> />
                                                        <span></span>
                                                    </label>
                                                </div>
                                            </div>
                                            <div class="rntp-help-block">
                                                <div class="rntp-help-block-content">
                                                    <?php _e('Enable multi day event checkbox visibility for customers to select an end-date in the checkout page. (Note : works only when Dates On Checkout page (modern layout) setting is enabled.', 'rentopian-sync')?>
                                                </div>
                                            </div>
                                            <div class="clearfix"></div>
                                        </div>

                                        <div class="rental-form-group">
                                            <div class="rntp-sync-form-title">
                                                <label class="rental-label" for="rntp-checkbox-<?php echo esc_attr($opt_hide_time_pickers)?>">
                                                    <?php _e('Hide start and end time pickers', 'rentopian-sync')?>
                                                </label>
                                            </div>
                                            <div class="rntp-sync-field">
                                                <div class="rntp-checkbox inline">
                                                    <label>
                                                        <input id="rntp-checkbox-<?php echo esc_attr($opt_hide_time_pickers)?>" name="<?php echo esc_attr($opt_hide_time_pickers); ?>" type="checkbox" value="true" <?php if( esc_attr( $val_hide_time_pickers )):?>checked="checked"<?php endif?> />
                                                        <span></span>
                                                    </label>
                                                </div>
                                            </div>
                                            <div class="rntp-help-block">
                                                <div class="rntp-help-block-content">
                                                    <?php _e('If selected, the calendar picker will show start / end days and hide the times.', 'rentopian-sync')?>
                                                </div>
                                            </div>
                                            <div class="clearfix"></div>
                                        </div>
                                        <div class="rental-form-group">
                                            <div class="rntp-sync-form-title">
                                                <label class="rental-label" for="rntp-checkbox-<?php echo esc_attr($opt_hide_zip)?>">
                                                    <?php _e('Hide ZIP code', 'rentopian-sync')?>
                                                </label>
                                            </div>
                                            <div class="rntp-sync-field">
                                                <div class="rntp-checkbox inline">
                                                    <label>
                                                        <input id="rntp-checkbox-<?php echo esc_attr($opt_hide_zip) ?>"
                                                            name="<?php echo esc_attr($opt_hide_zip); ?>" type="checkbox"
                                                            <?php if ($val_hide_zip): ?>checked="checked"<?php endif ?>
                                                            <?php if ($val_select_division): ?>disabled<?php endif ?>
                                                            value="true"/>
                                                        <span></span>
                                                    </label>
                                                </div>
                                            </div>
                                            <div class="rntp-help-block">
                                                <div class="rntp-help-block-content">
                                                    <?php _e('If you have only one division and do not use mileage based delivery option, you can hide the ZIP code field.', 'rentopian-sync')?>
                                                </div>
                                            </div>
                                            <div class="clearfix"></div>
                                        </div>
                                        <div class="rental-form-group">
                                            <div class="rntp-sync-form-title">
                                                <label class="rental-label" for="rntp-checkbox-<?php echo esc_attr($opt_event_start_time)?>">
                                                    <?php _e('Show event start time', 'rentopian-sync')?>
                                                </label>
                                            </div>
                                            <div class="rntp-sync-field">
                                                <div class="rntp-checkbox inline">
                                                    <label>
                                                        <input id="rntp-checkbox-<?php echo esc_attr($opt_event_start_time)?>" name="<?php echo esc_attr($opt_event_start_time); ?>" type="checkbox" value="true" <?php if( esc_attr( $val_event_start_time )):?>checked="checked"<?php endif?> />
                                                        <span></span>
                                                    </label>
                                                </div>
                                            </div>
                                            <div class="rntp-help-block">
                                                <div class="rntp-help-block-content">
                                                    <?php _e('Adds an "Event start time" field in checkout section.', 'rentopian-sync')?>
                                                </div>
                                            </div>
                                            <div class="clearfix"></div>
                                        </div>
                                        <div class="rental-form-group">
                                            <div class="rntp-sync-form-title">
                                                <label class="rental-label" for="rntp-input-<?php echo esc_attr($opt_min_start_date)?>">
                                                    <?php _e('Orders offset (in days)', 'rentopian-sync')?>
                                                </label>
                                            </div>
                                            <div class="rntp-sync-field">
                                                <div class="rntp-input-text">
                                                    <input type="number" id="rntp-input-<?php echo esc_attr($opt_min_start_date)?>"
                                                        min="1" step="1"
                                                        name="<?php echo esc_attr($opt_min_start_date); ?>"
                                                        value="<?php echo esc_attr($val_min_start_date); ?>"/>
                                                </div>
                                            </div>
                                            <div class="rntp-help-block">
                                                <div class="rntp-help-block-content">
                                                    <?php _e('Blocks the ability to put orders for specified number of days starting from today.', 'rentopian-sync')?>
                                                </div>
                                            </div>
                                            <div class="clearfix"></div>
                                        </div>
                                        <div class="rental-form-group">
                                            <div class="rntp-sync-form-title">
                                                <label class="rental-label" for="rntp-input-<?php echo esc_attr($opt_min_dates_range)?>">
                                                    <?php _e('Minimum range (in days)', 'rentopian-sync')?>
                                                </label>
                                            </div>
                                            <div class="rntp-sync-field">
                                                <div class="rntp-input-text">
                                                    <input type="number" id="rntp-input-<?php echo esc_attr($opt_min_dates_range)?>"
                                                        min="1" step="1"
                                                        name="<?php echo esc_attr($opt_min_dates_range); ?>"
                                                        value="<?php echo esc_attr($val_min_dates_range); ?>"/>
                                                </div>
                                            </div>
                                            <div class="rntp-help-block">
                                                <div class="rntp-help-block-content">
                                                    <?php _e('The minimum interval between start date and end date.', 'rentopian-sync')?>
                                                </div>
                                            </div>
                                            <div class="clearfix"></div>
                                        </div>
                                        <div class="rental-form-group">
                                            <div class="rntp-sync-form-title">
                                                <label class="rental-label" for="rntp-input-<?php echo esc_attr($opt_max_dates_range)?>">
                                                    <?php _e('Maximum range (in days)', 'rentopian-sync')?>
                                                </label>
                                            </div>
                                            <div class="rntp-sync-field">
                                                <div class="rntp-input-text">
                                                    <input type="number" id="rntp-input-<?php echo esc_attr($opt_max_dates_range)?>"
                                                        min="1" step="1"
                                                        name="<?php echo esc_attr($opt_max_dates_range); ?>"
                                                        value="<?php echo esc_attr($val_max_dates_range); ?>"/>
                                                </div>
                                            </div>
                                            <div class="rntp-help-block">
                                                <div class="rntp-help-block-content">
                                                    <?php _e('The maximum interval between start date and end date.', 'rentopian-sync')?>
                                                </div>
                                            </div>
                                            <div class="clearfix"></div>
                                        </div>
                                        <div class="rental-form-group fixed-interval-group">
                                            <div class="rntp-sync-form-title">
                                                <label class="rental-label" for="rntp-input-<?php echo esc_attr($opt_date_step)?>">
                                                    <?php _e('Fixed Rental Interval (in days)', 'rentopian-sync'); ?>
                                                </label>
                                            </div>
                                            <div class="rntp-sync-field">
                                                <div class="rntp-input-text">
                                                    <input type="number" id="rntp-input-<?php echo esc_attr($opt_date_step)?>"
                                                        min="1" step="1"
                                                        name="<?php echo esc_attr($opt_date_step); ?>"
                                                        value="<?php echo esc_attr($val_date_step); ?>"/>
                                                </div>
                                            </div>
                                            <div class="rntp-help-block">
                                                <div class="rntp-help-block-content">
                                                    <?php _e('Allow renting only with specific intervals. For example, a 4 day interval means the item with rental start date of Sept 1, can be rented for Sep 1-5, or 1-9 but not 1-7.', 'rentopian-sync'); ?>
                                                </div>
                                            </div>
                                            <div class="clearfix"></div>
                                        </div>
                                        <div class="rental-form-group fixed-interval-group">
                                            <div class="rntp-sync-form-title">
                                                <label class="rental-label" for="rntp-checkbox-<?php echo esc_attr($opt_select_date_range) ?>">
                                                    <?php _e('Select Dates Range', 'rentopian-sync') ?>
                                                </label>
                                            </div>
                                            <div class="rntp-sync-field">
                                                <div class="rntp-checkbox inline">
                                                    <label>
                                                        <input type="checkbox" value="1"
                                                            name="<?php echo esc_attr($opt_select_date_range); ?>"
                                                            id="rntp-checkbox-<?php echo esc_attr($opt_select_date_range) ?>"
                                                            <?php echo $val_select_date_range? 'checked="checked"': '';
                                                            echo $val_date_step? '': ' disabled'; ?>/>
                                                        <span></span>
                                                    </label>
                                                </div>
                                            </div>
                                            <div class="rntp-help-block">
                                                <div class="rntp-help-block-content">
                                                    <?php _e('Select a rental date range instead of an end date. The "Fixed Rental interval (in days)" option above MUST be set to enable this option.', 'rentopian-sync') ?>

                                                </div>
                                            </div>
                                            <div class="clearfix"></div>
                                        </div>
                                        <div class="rental-form-group restricted-days-section">
                                            <div class="rntp-sync-form-title">
                                                <label class="rental-label">
                                                    <?php _e('Restrict orders to specific days of week', 'rentopian-sync')?>
                                                </label>
                                            </div>
                                            <div class="rntp-sync-field">
                                                <ul>
                                                    <li>
                                                        <input id="rntp-disabled-week-1" name="<?php echo esc_attr($opt_disabled_week_days); ?>[1]"
                                                            <?php if ($val_disabled_week_days && isset($val_disabled_week_days[1])): ?>checked<?php endif ?>
                                                            type="checkbox" value="1"/>
                                                        <label for="rntp-disabled-week-1"><?php _e('Monday', 'rentopian-sync')?></label>
                                                    </li>
                                                    <li>
                                                        <input id="rntp-disabled-week-2" name="<?php echo esc_attr($opt_disabled_week_days); ?>[2]"
                                                            <?php if ($val_disabled_week_days && isset($val_disabled_week_days[2])): ?>checked<?php endif ?>
                                                            type="checkbox" value="1"/>
                                                        <label for="rntp-disabled-week-2"><?php _e('Tuesday', 'rentopian-sync')?></label>
                                                    </li>
                                                    <li>
                                                        <input id="rntp-disabled-week-3" name="<?php echo esc_attr($opt_disabled_week_days); ?>[3]"
                                                            <?php if ($val_disabled_week_days && isset($val_disabled_week_days[3])): ?>checked<?php endif ?>
                                                            type="checkbox" value="1"/>
                                                        <label for="rntp-disabled-week-3"><?php _e('Wednesday', 'rentopian-sync')?></label>
                                                    </li>
                                                    <li>
                                                        <input id="rntp-disabled-week-4" name="<?php echo esc_attr($opt_disabled_week_days); ?>[4]"
                                                            <?php if ($val_disabled_week_days && isset($val_disabled_week_days[4])): ?>checked<?php endif ?>
                                                            type="checkbox" value="1"/>
                                                        <label for="rntp-disabled-week-4"><?php _e('Thursday', 'rentopian-sync')?></label>
                                                    </li>
                                                    <li>
                                                        <input id="rntp-disabled-week-5" name="<?php echo esc_attr($opt_disabled_week_days); ?>[5]"
                                                            <?php if ($val_disabled_week_days && isset($val_disabled_week_days[5])): ?>checked<?php endif ?>
                                                            type="checkbox" value="1"/>
                                                        <label for="rntp-disabled-week-5"><?php _e('Friday', 'rentopian-sync')?></label>
                                                    </li>
                                                    <li>
                                                        <input id="rntp-disabled-week-6" name="<?php echo esc_attr($opt_disabled_week_days); ?>[6]"
                                                            <?php if ($val_disabled_week_days && isset($val_disabled_week_days[6])): ?>checked<?php endif ?>
                                                            type="checkbox" value="1"/>
                                                        <label for="rntp-disabled-week-6"><?php _e('Saturday', 'rentopian-sync')?></label>
                                                    </li>
                                                    <li>
                                                        <input id="rntp-disabled-week-0" name="<?php echo esc_attr($opt_disabled_week_days); ?>[0]"
                                                            <?php if ($val_disabled_week_days && isset($val_disabled_week_days[0])): ?>checked<?php endif ?>
                                                            type="checkbox" value="1"/>
                                                        <label for="rntp-disabled-week-0"><?php _e('Sunday', 'rentopian-sync')?></label>
                                                    </li>
                                                </ul>
                                            </div>
                                            <div class="rntp-help-block">
                                                <div class="rntp-help-block-content">
                                                    <?php _e('Blocks the ability to put an order that starts on the day(s) of week specified here.', 'rentopian-sync')?>
                                                </div>
                                            </div>
                                            <div class="clearfix"></div>
                                        </div>
                                        <div class="rental-form-group">
                                            <div class="rntp-sync-form-title">
                                                <label class="rental-label" for="rntp-checkbox-<?php echo esc_attr($opt_location)?>">
                                                    <?php _e('Show Delivery Address Input', 'rentopian-sync')?>
                                                </label>
                                            </div>
                                            <div class="rntp-sync-field">
                                                <div class="rntp-checkbox inline">
                                                    <label>
                                                        <input id="rntp-checkbox-<?php echo esc_attr($opt_location)?>" name="<?php echo esc_attr($opt_location); ?>" type="checkbox" value="true" <?php if( esc_attr( $val_location )):?>checked="checked"<?php endif?> />
                                                        <span></span>
                                                    </label>
                                                </div>
                                            </div>
                                            <div class="rntp-help-block">
                                                <div class="rntp-help-block-content">
                                                    <?php _e('If selected, beside the start/end date fields, there will be a "Location" field for customers to enter full delivery address.', 'rentopian-sync')?>
                                                </div>
                                            </div>
                                            <div class="clearfix"></div>
                                        </div>
                                        <div class="rental-form-group google-key-wrapper">
                                            <div class="rntp-sync-form-title">
                                                <label class="rental-label" for="rntp-input-<?php echo esc_attr($opt_google_map_key)?>">
                                                    <?php _e('Google Address Autocomplete API Key', 'rentopian-sync')?>
                                                </label>

                                            </div>
                                            
                                            <div class="rntp-sync-field">
                                                <div class="rntp-input-text">
                                                    <input type="text" id="rntp-input-<?php echo esc_attr($opt_google_map_key)?>"
                                                        name="<?php echo esc_attr($opt_google_map_key); ?>"
                                                        value="<?php echo esc_attr($val_google_map_key); ?>"/>
                                                </div>
                                            </div>

                                            <div class="rntp-help-block">
                                                <div class="rntp-help-block-content">
                                                    <?php
                                                        $gmap_docs = RENTOPIAN_SYNC_DOCUMENTATION_URL.'#google-map-key';
                                                        echo sprintf(__( 'Will autocomplete the address for “Delivery address input” field and on the checkout page. Detailed instructions for retrieving a key are provided ', 'rentopian-sync').wp_kses( '<a href="%1s" target="_blank">%2s</a>.', array(  'a' => array( 'href' => array(), 'target' => array() ) ) ), esc_url( $gmap_docs ), __( 'here', 'rentopian-sync'));
                                                    ?>
                                                </div>
                                            </div>
                                            <div class="clearfix"></div>
                                        </div>

                                        <div class="rental-form-group">
                                            <div class="rntp-sync-form-title">
                                                <label class="rental-label" for="rntp-checkbox-<?php echo esc_attr($opt_select_a_day_by_default)?>">
                                                    <?php _e('Enable selecting a day by default in calendar', 'rentopian-sync')?>
                                                </label>
                                            </div>
                                            <div class="rntp-sync-field">
                                                <div class="rntp-checkbox inline">
                                                    <label>
                                                        <input id="rntp-checkbox-<?php echo esc_attr($opt_select_a_day_by_default)?>" name="<?php echo esc_attr($opt_select_a_day_by_default); ?>" type="checkbox" value="true" <?php if( esc_attr( $val_select_a_day_by_default )):?>checked="checked"<?php endif?> />
                                                        <span></span>
                                                    </label>
                                                </div>
                                            </div>
                                            <div class="rntp-help-block">
                                                <div class="rntp-help-block-content">
                                                    <?php _e('If checked, the calendar will have the current(or specified) day selected by default.', 'rentopian-sync')?>
                                                </div>
                                            </div>
                                            <div class="clearfix"></div>
                                        </div>
                                        <div class="rental-form-group rental-hide" id="<?php echo esc_attr($opt_selected_default_day). "_wrapper" ?>">
                                            <div class="rntp-sync-form-title">
                                                <label class="rental-label" for="rntp-selectbox-<?php echo esc_attr($opt_selected_default_day)?>">
                                                    <?php _e('Select a specific day by default', 'rentopian-sync')?>
                                                </label>
                                            </div>
                                            <div class="rntp-sync-field">
                                                <div class="rntp-selectbox">
                                                    <select id="<?php echo esc_attr($opt_selected_default_day)."-select" ?>"
                                                            data-min-day="<?php echo esc_attr(rental_get_min_selected_default_day()); ?>">
                                                        <?php
                                                            if (empty($val_selected_default_day)) {
                                                                $val_selected_default_day = 1;
                                                            }

                                                            // Days inside the orders offset stay listed but unselectable,
                                                            // so the reason a choice is unavailable is visible.
                                                            $min_default_day = rental_get_min_selected_default_day();
                                                            $max_default_day = rental_get_max_selected_default_day();

                                                            for ($day_value = 1; $day_value <= $max_default_day; $day_value++) {
                                                                printf(
                                                                    '<option value="%1$d"%2$s%3$s> %4$s </option>',
                                                                    $day_value,
                                                                    $val_selected_default_day == $day_value ? ' selected' : '',
                                                                    $day_value < $min_default_day ? ' disabled' : '',
                                                                    esc_html(rental_get_selected_default_day_label($day_value))
                                                                );
                                                            }
                                                        ?>
                                                    </select>

                                                    <input type="hidden" class="rental-disapper" id="rntp-input-<?php echo esc_attr($opt_selected_default_day) ?>"
                                                        name="<?php echo esc_attr($opt_selected_default_day); ?>"
                                                        value="<?php echo esc_attr($val_selected_default_day); ?>"/>
                                                </div>
                                            </div>
                                            <div class="rntp-help-block">
                                                <div class="rntp-help-block-content">
                                                    <?php _e('If an option is selected, the calendar will have the chosen day selected and will show available products (of that day) by default.', 'rentopian-sync')?>
                                                </div>
                                            </div>
                                            <div class="clearfix"></div>
                                        </div>
                                        <div class="rental-form-group">
                                            <div class="rntp-sync-form-title">
                                                <label class="rental-label" for="rental_default_start_time">
                                                    <?php _e('Default Start Time', 'rentopian-sync')?>
                                                </label>
                                            </div>
                                            <div class="rntp-sync-field">
                                                <div class="rntp-input-text">
                                                    <input type="text" id="rental_default_start_time"
                                                        name="<?php echo esc_attr($opt_default_start_time); ?>"
                                                        value="<?php echo esc_attr($val_default_start_time); ?>"
                                                    />
                                                </div>
                                            </div>
                                            <div class="rntp-help-block">
                                                <div class="rntp-help-block-content">
                                                    <?php _e('Set/Modify Default Start Time', 'rentopian-sync')?>
                                                </div>
                                            </div>
                                            <div class="clearfix"></div>
                                        </div>
                                        <div class="rental-form-group">
                                            <div class="rntp-sync-form-title">
                                                <label class="rental-label" for="rental_default_end_time">
                                                    <?php _e('Default End Time', 'rentopian-sync')?>
                                                </label>
                                            </div>
                                            <div class="rntp-sync-field">
                                                <div class="rntp-input-text">
                                                    <input type="text" id="rental_default_end_time"
                                                        name="<?php echo esc_attr($opt_default_end_time); ?>"
                                                        value="<?php echo esc_attr($val_default_end_time); ?>"
                                                    />
                                                </div>
                                            </div>
                                            <div class="rntp-help-block">
                                                <div class="rntp-help-block-content">
                                                    <?php _e('Set/Modify Default End Time', 'rentopian-sync')?>
                                                </div>
                                            </div>
                                            <div class="clearfix"></div>
                                        </div>

                                    
                                <?php endif; ?>
                                <!-- Date / Time / ZIP Code / Location Options section -->
                            </div>
                            
                            <div class="section-wrapper">
                                <!-- Prices Visibility section -->
                                <div class="rental-inner-title">
                                    <h3>Prices Visibility</h3>
                                </div>


                                    <div class="rental-form-group">
                                        <div class="rntp-sync-form-title">
                                            <label class="rental-label" for="rntp-checkbox-<?php echo esc_attr($opt_hide_product_price)?>">
                                                <?php _e('Hide Product Price', 'rentopian-sync')?>
                                            </label>
                                        </div>
                                        <div class="rntp-sync-field">
                                            <div class="rntp-checkbox inline">
                                                <label>
                                                    <input id="rntp-checkbox-<?php echo esc_attr($opt_hide_product_price)?>"
                                                        name="<?php echo esc_attr($opt_hide_product_price); ?>"
                                                        type="checkbox"
                                                        value="true"
                                                        <?php echo $val_hide_product_price ? 'checked="checked"': ''; echo $val_direct_only_bookings? ' disabled': '' ?>/>
                                                    <span></span>
                                                </label>
                                            </div>
                                        </div>
                                        <div class="rntp-help-block">
                                            <div class="rntp-help-block-content">
                                                <?php _e('Hide product prices from visitors, unless direct checkout is enabled, in which case the visitors will still be able to see the products\' prices.', 'rentopian-sync')?>
                                            </div>
                                        </div>
                                        <div class="clearfix"></div>
                                    </div>
                                    <div class="rental-form-group">
                                        <div class="rntp-sync-form-title">
                                            <label class="rental-label" for="rntp-checkbox-<?php echo esc_attr($opt_show_product_price_only_in_cart)?>">
                                                <?php _e('Show Prices only when items are in cart', 'rentopian-sync')?>
                                            </label>
                                        </div>
                                        <div class="rntp-sync-field">
                                            <div class="rntp-checkbox inline">
                                                <label>
                                                    <input id="rntp-checkbox-<?php echo esc_attr($opt_show_product_price_only_in_cart)?>"
                                                        name="<?php echo esc_attr($opt_show_product_price_only_in_cart); ?>"
                                                        type="checkbox"
                                                        value="true"
                                                        <?php echo $val_show_product_price_only_in_cart ? 'checked="checked"': ''; echo $val_hide_product_price? ' disabled': '' ?>/>
                                                    <span></span>
                                                </label>
                                            </div>
                                        </div>
                                        <div class="rntp-help-block">
                                            <div class="rntp-help-block-content">
                                                <?php _e('Show products prices only when items are in cart, unless hide product price is enabled, in which case the visitors will not still be able to see the products\' prices anywhere.', 'rentopian-sync')?>
                                            </div>
                                        </div>
                                        <div class="clearfix"></div>
                                    </div>
                                    <?php if ($not_only_sale_products && $not_only_hourly_products) : ?>
                                        <div class="rental-form-group">
                                            <div class="rntp-sync-form-title">
                                                <label class="rental-label" for="rntp-checkbox-<?php echo esc_attr($opt_hide_damage_waiver)?>">
                                                    <?php _e('Hide Damage Waiver', 'rentopian-sync')?>
                                                </label>
                                            </div>
                                            <div class="rntp-sync-field">
                                                <div class="rntp-checkbox inline">
                                                    <label>
                                                        <input id="rntp-checkbox-<?php echo esc_attr($opt_hide_damage_waiver) ?>" name="<?php echo esc_attr($opt_hide_damage_waiver); ?>" type="checkbox" value="true" <?php if ($val_hide_damage_waiver): ?>checked="checked"<?php endif ?> />
                                                        <span></span>
                                                    </label>
                                                </div>
                                            </div>
                                            <div class="rntp-help-block">
                                                <div class="rntp-help-block-content">
                                                    <?php _e('Hide Damage Waiver block from checkout page and place an order without damage waiver.', 'rentopian-sync')?>
                                                </div>
                                            </div>
                                            <div class="clearfix"></div>
                                        </div>
                                        <div class="rental-form-group">
                                            <div class="rntp-sync-form-title">
                                                <label class="rental-label" for="rntp-checkbox-<?php echo esc_attr($opt_buy_damage_waiver_by_default); ?>">
                                                    <?php _e('Charge the Damage Waiver by Default', 'rentopian-sync')?>
                                                </label>
                                            </div>
                                            <div class="rntp-sync-field">
                                                <div class="rntp-checkbox inline">
                                                    <label>
                                                        <input id="rntp-checkbox-<?php echo esc_attr($opt_buy_damage_waiver_by_default) ?>" name="<?php echo esc_attr($opt_buy_damage_waiver_by_default); ?>" type="checkbox" value="true" <?php if ($val_buy_damage_waiver_by_default): ?>checked="checked"<?php endif ?> />
                                                        <span></span>
                                                    </label>
                                                </div>
                                            </div>
                                            <div class="rntp-help-block">
                                                <div class="rntp-help-block-content">
                                                    <?php _e('The damage waiver option on the checkout page will be set to "Charge" by default.', 'rentopian-sync'); ?>
                                                </div>
                                            </div>
                                            <div class="clearfix"></div>
                                        </div>
                                        <div class="rental-form-group">
                                            <div class="rntp-sync-form-title">
                                                <label class="rental-label" for="rntp-checkbox-<?php echo esc_attr($opt_hide_and_buy_damage_waiver_by_default); ?>">
                                                    <?php _e('Hide the Damage Waiver and apply by default', 'rentopian-sync')?>
                                                </label>
                                            </div>
                                            <div class="rntp-sync-field">
                                                <div class="rntp-checkbox inline">
                                                    <label>
                                                        <input id="rntp-checkbox-<?php echo esc_attr($opt_hide_and_buy_damage_waiver_by_default) ?>" name="<?php echo esc_attr($opt_hide_and_buy_damage_waiver_by_default); ?>" type="checkbox" value="true" <?php if ($val_hide_and_buy_damage_waiver_by_default): ?>checked="checked"<?php endif ?> />
                                                        <span></span>
                                                    </label>
                                                </div>
                                            </div>
                                            <div class="rntp-help-block">
                                                <div class="rntp-help-block-content">
                                                    <?php _e('The damage waiver option on the checkout page will be set to "Charge" by default and it will be hidden.', 'rentopian-sync'); ?>
                                                </div>
                                            </div>
                                            <div class="clearfix"></div>
                                        </div>
                                        <div class="rental-form-group">
                                            <div class="rntp-sync-form-title">
                                                <label class="rental-label" for="rntp-checkbox-<?php echo esc_attr($opt_show_damage_waiver_on_cart); ?>">
                                                    <?php _e('Show the Damage Waiver on the cart page', 'rentopian-sync')?>
                                                </label>
                                            </div>
                                            <div class="rntp-sync-field">
                                                <div class="rntp-checkbox inline">
                                                    <label>
                                                        <input id="rntp-checkbox-<?php echo esc_attr($opt_show_damage_waiver_on_cart) ?>" name="<?php echo esc_attr($opt_show_damage_waiver_on_cart); ?>" type="checkbox" value="true" <?php if ($val_show_damage_waiver_on_cart): ?>checked="checked"<?php endif ?> />
                                                        <span></span>
                                                    </label>
                                                </div>
                                            </div>
                                            <div class="rntp-help-block">
                                                <div class="rntp-help-block-content">
                                                    <?php _e('The damage waiver option will also be shown on the cart page. It follows the same rules as the checkout page, so it stays hidden when the damage waiver is hidden or applied by default.', 'rentopian-sync'); ?>
                                                </div>
                                            </div>
                                            <div class="clearfix"></div>
                                        </div>
                                    <?php endif; ?>

                                <!-- Prices Visibility section -->
                            </div>
                            
                            <div class="section-wrapper">
                                <!-- Delivery section -->
                                <div class="rental-inner-title">
                                    <h3>Delivery</h3>
                                </div>

                                    <div class="rental-form-group">
                                        <div class="rntp-sync-form-title">
                                            <label class="rental-label" for="rntp-checkbox-<?php echo esc_attr($opt_do_not_use_rentopian_shipping)?>">
                                                <?php _e('Do not use Rentopian shipping', 'rentopian-sync')?>
                                            </label>
                                        </div>
                                        <div class="rntp-sync-field">
                                            <div class="rntp-checkbox inline">
                                                <label>
                                                    <input id="rntp-checkbox-<?php echo esc_attr($opt_do_not_use_rentopian_shipping)?>"
                                                        name="<?php echo esc_attr($opt_do_not_use_rentopian_shipping); ?>"
                                                        type="checkbox"
                                                        value="true"
                                                        <?php echo $val_do_not_use_rentopian_shipping ? 'checked="checked"': ''; ?>/>
                                                    <span></span>
                                                </label>
                                            </div>
                                        </div>
                                        <div class="rntp-help-block">
                                            <div class="rntp-help-block-content">
                                                <?php _e('Enable to use an alternative solution to Rentopian shipping, for example use woocommerce shipping or a third party shipping plugin instead.', 'rentopian-sync')?>
                                            </div>
                                        </div>
                                        <div class="clearfix"></div>
                                    </div>
                                    <div class="rental-form-group rental-delivery-form-group">
                                        <div class="rntp-sync-form-title">
                                            <label class="rental-label">
                                                <?php _e('Pickup / Delivery options', 'rentopian-sync')?>
                                            </label>
                                        </div>
                                        <div class="rntp-sync-field">
                                            <div class="rntp-radio inline">
                                                <input id="rntp-company-delivery-return"
                                                    name="<?php echo esc_attr($opt_pickup_delivery); ?>"
                                                    required="required"
                                                    type="radio"
                                                    value="company_delivery_return"
                                                    <?php if( esc_attr( $val_pickup_delivery )  === 'company_delivery_return' ):?>checked="checked"<?php endif?>
                                                />
                                                <label for="rntp-company-delivery-return" class="radio-label">Allow Company Delivery & return only</label>
                                            </div>
                                            <div class="rntp-radio inline">
                                                <input id="rntp-client-pickup-return"
                                                    required="required"
                                                    name="<?php echo esc_attr($opt_pickup_delivery); ?>"
                                                    type="radio"
                                                    value="client_pickup_return"
                                                    <?php if( esc_attr( $val_pickup_delivery ) === 'client_pickup_return'):?>checked="checked"<?php endif?>
                                                />
                                                <label for="rntp-client-pickup-return" class="radio-label">Allow Client Pickup & return only</label>
                                            </div>
                                            <div class="rntp-radio inline">
                                                <input id="rntp-company-client-delivery-return"
                                                    required="required"
                                                    name="<?php echo esc_attr($opt_pickup_delivery); ?>"
                                                    type="radio"
                                                    value="company_client_delivery_return"
                                                    <?php if( esc_attr( $val_pickup_delivery ) === 'company_client_delivery_return'):?>checked="checked"<?php endif?>
                                                />
                                                <label for="rntp-company-client-delivery-return" class="radio-label">Allow Both</label>
                                            </div>
                                        </div>
                                        <div class="rntp-help-block">
                                            <div class="rntp-help-block-content">
                                                <?php _e('Multiple Delivery/Pick Up options for company and clients.', 'rentopian-sync')?>
                                            </div>
                                        </div>
                                        <div class="clearfix"></div>
                                    </div>

                                    <?php // if(get_option($opt_do_not_use_rentopian_shipping) == 1): ?>
                                        <div class="rental-form-group track-wc-shipping">
                                            <div class="rntp-sync-form-title">
                                                <label class="rental-label" for="rntp-checkbox-<?php echo esc_attr($opt_track_wc_shipping)?>">
                                                    <?php _e('Track WooCommerce shipping methods', 'rentopian-sync')?>
                                                </label>
                                            </div>
                                            <div class="rntp-sync-field">
                                                <div class="rntp-checkbox inline">
                                                    <label>
                                                        <input id="rntp-checkbox-<?php echo esc_attr($opt_track_wc_shipping)?>"
                                                            name="<?php echo esc_attr($opt_track_wc_shipping); ?>"
                                                            type="checkbox"
                                                            value="true"
                                                            <?php echo $val_track_wc_shipping ? 'checked="checked"': ''; ?>/>
                                                        <span></span>
                                                    </label>
                                                </div>
                                            </div>
                                            <div class="rntp-help-block">
                                                <div class="rntp-help-block-content">
                                                    <?php _e('Track WooCommerce shipping methods and send them through to Rentopian system. (Only works if "Do not use Rentopian shipping" is enabled.)', 'rentopian-sync')?>
                                                </div>
                                            </div>
                                            <div class="clearfix"></div>
                                        </div>
                                    <?php // endif; ?>

                                    <div class="rental-form-group rental-delivery-form-group<?php echo $val_pickup_delivery === 'company_client_delivery_return' ? '' : ' rental-hide'; ?>"
                                        id="rntp-allow-both-options"
                                        <?php echo $val_do_not_use_rentopian_shipping ? 'style="display:none"': ''; ?>>
                                        <div class="rntp-sync-form-title">
                                            <label class="rental-label" for="rntp-checkbox-<?php echo esc_attr($opt_full_payment_for_delivery)?>">
                                                <?php _e('Full payment for delivery orders', 'rentopian-sync')?>
                                            </label>
                                        </div>
                                        <div class="rntp-sync-field">
                                            <div class="rntp-checkbox inline">
                                                <label>
                                                    <input id="rntp-checkbox-<?php echo esc_attr($opt_full_payment_for_delivery)?>"
                                                        name="<?php echo esc_attr($opt_full_payment_for_delivery); ?>"
                                                        type="checkbox"
                                                        value="true"
                                                        <?php echo $val_full_payment_for_delivery ? 'checked="checked"': ''; ?>/>
                                                    <span></span>
                                                </label>
                                            </div>
                                        </div>
                                        <div class="rntp-help-block">
                                            <div class="rntp-help-block-content">
                                                <?php _e('Offer the full payment option only when a delivery method is selected at checkout. Local pickup keeps both the deposit and the full payment options. Requires Rentopian shipping, "Allow Both" above, "Allow Direct Bookings" and "Allow Deposit".', 'rentopian-sync')?>
                                            </div>
                                        </div>
                                        <div class="clearfix"></div>
                                    </div>

                                <!-- Delivery section -->
                            </div>

                            <div class="section-wrapper">
                                <!-- Terms and Messages section -->
                                <div class="rental-inner-title">
                                    <h3>Terms and Messages</h3>
                                </div>


                                    <div class="rental-form-group">
                                        <div class="rntp-sync-form-title">
                                            <label class="rental-label" for="special_terms">
                                                <?php _e('Special Terms', 'rentopian-sync'); ?>
                                            </label>
                                        </div>
                                        <div class="rntp-sync-field">
                                            <?php 
                                                $content = $val_special_terms ? $val_special_terms : '';
                                                $editor_id = 'special_terms';
                                                $settings = array(
                                                    'textarea_name'  => $opt_special_terms,
                                                    'editor_class'   => 'rental-special-terms-editor',
                                                    'media_buttons'  => false,
                                                    // Open the visual editor regardless of the account-wide
                                                    // editor preference, so formatting is always available
                                                    'default_editor' => 'tinymce',
                                                    'textarea_rows'  => 10,
                                                );
                                                wp_editor( $content, $editor_id, $settings );
                                            ?>
                                        </div>
                                        <div class="rntp-help-block">
                                            <div class="rntp-help-block-content">
                                                <?php _e('Text for special terms, appears on checkout page.', 'rentopian-sync'); ?>
                                            </div>
                                        </div>
                                        <div class="clearfix"></div>
                                    </div>

                                    <div class="rental-form-group">
                                        <div class="rntp-sync-form-title">
                                            <label class="rental-label">
                                                <?php _e('Special Terms placement on checkout page', 'rentopian-sync')?>
                                            </label>
                                        </div>
                                        <div class="rntp-sync-field">
                                            <div class="rntp-radio inline">
                                                <input id="rntp-special-terms-top"
                                                    name="<?php echo esc_attr($opt_special_terms_placement); ?>"
                                                    required="required"
                                                    type="radio"
                                                    value="special_terms_on_top"
                                                    <?php if( esc_attr( $val_special_terms_placement )  === 'special_terms_on_top' ):?>checked="checked"<?php endif?>
                                                />
                                                <label for="rntp-special-terms-top" class="radio-label"> On the top of checkout page </label>
                                            </div>
                                            <div class="rntp-radio inline">
                                                <input id="rntp-special-terms-bottom"
                                                    required="required"
                                                    name="<?php echo esc_attr($opt_special_terms_placement); ?>"
                                                    type="radio"
                                                    value="special_terms_on_bottom"
                                                    <?php if( esc_attr( $val_special_terms_placement ) === 'special_terms_on_bottom'):?>checked="checked"<?php endif?>
                                                />
                                                <label for="rntp-special-terms-bottom" class="radio-label"> On the bottom of checkout page </label>
                                            </div>
                                        </div>
                                        <div class="rntp-help-block">
                                            <div class="rntp-help-block-content">
                                                <?php _e('Special Terms placement on checkout page. <br/> 
                                                        - On the top of checkout page (before customer details) <br/>
                                                        - On the bottom of checkout page (after customer details)
                                                    ', 'rentopian-sync')?>
                                            </div>
                                        </div>
                                        <div class="clearfix"></div>
                                    </div>

                                    <?php if (!get_option('rental_allow_overbook', 0)): ?>
                                        <div class="rental-form-group">
                                            <div class="rntp-sync-form-title">
                                                <label class="rental-label" for="rntp-checkbox-<?php echo esc_attr($opt_overbook_text)?>">
                                                    <?php _e('Overbooked product message', 'rentopian-sync')?>
                                                </label>
                                            </div>
                                            <div class="rntp-sync-field">
                                                <div class="rntp-input-text">
                                                    <input type="text" id="rntp-checkbox-<?php echo esc_attr($opt_overbook_text)?>"
                                                        name="<?php echo esc_attr($opt_overbook_text); ?>"
                                                        value="<?php echo esc_attr($val_overbook_text); ?>"/>
                                                </div>
                                            </div>
                                            <div class="rntp-help-block">
                                                <div class="rntp-help-block-content">
                                                    <?php _e('Set custom text for an overbooked product.', 'rentopian-sync')?>
                                                </div>
                                            </div>
                                            <div class="clearfix"></div>
                                        </div>
                                    <?php endif; ?>

                                <!-- Terms and Messages section -->
                            </div>

                            <?php
                            // Sets section — owned by the Sets module so all set-related
                            // markup, JS, CSS and PHP services evolve together.
                            // See includes/sets/class-sets-admin-settings.php.
                            Rental_Sets_Admin_Settings::render(array(
                                'opt_set_listing_style' => $opt_set_listing_style,
                                'opt_hide_set_items'    => $opt_hide_set_items,
                                'val_set_listing_style' => $val_set_listing_style,
                                'val_hide_set_items'    => $val_hide_set_items,
                            ));
                            ?>

                            <div class="section-wrapper">
                                <!-- Products section -->
                                <div class="rental-inner-title">
                                    <h3><?php _e('Products', 'rentopian-sync')?></h3>
                                </div>

                                    <div class="rental-form-group">
                                        <div class="rntp-sync-form-title">
                                            <label class="rental-label" for="rntp-checkbox-<?php echo esc_attr($opt_filter_unavailable_products)?>">
                                                <?php _e('Filter unavailable products/variants listing', 'rentopian-sync')?>
                                            </label>
                                        </div>
                                        <div class="rntp-sync-field">
                                            <div class="rntp-checkbox inline">
                                                <label>
                                                    <input id="rntp-checkbox-<?php echo esc_attr($opt_filter_unavailable_products)?>"
                                                            name="<?php echo esc_attr($opt_filter_unavailable_products); ?>"
                                                            type="checkbox"
                                                            value="true"
                                                        <?php echo $val_filter_unavailable_products? 'checked="checked"': ''; ?>/>
                                                    <span></span>
                                                </label>
                                            </div>
                                        </div>
                                        <div class="rntp-help-block">
                                            <div class="rntp-help-block-content">
                                                <?php _e('If enabled, unavailable products ,inventory blocked and duplicated products will be filtered in shop/search/category pages.', 'rentopian-sync')?>
                                            </div>
                                        </div>
                                        <div class="clearfix"></div>
                                    </div>

                                    <div class="rental-form-group">
                                        <div class="rntp-sync-form-title">
                                            <label class="rental-label" for="rntp-checkbox-<?php echo esc_attr($opt_group_attribute_values_in_filters)?>">
                                                <?php _e('Group attribute values in filters', 'rentopian-sync')?>
                                            </label>
                                        </div>
                                        <div class="rntp-sync-field">
                                            <div class="rntp-checkbox inline">
                                                <label>
                                                    <input id="rntp-checkbox-<?php echo esc_attr($opt_group_attribute_values_in_filters)?>"
                                                            name="<?php echo esc_attr($opt_group_attribute_values_in_filters); ?>"
                                                            type="checkbox"
                                                            value="true"
                                                        <?php echo $val_group_attribute_values_in_filters? 'checked="checked"': ''; ?>/>
                                                    <span></span>
                                                </label>
                                            </div>
                                        </div>
                                        <div class="rntp-help-block">
                                            <div class="rntp-help-block-content">
                                                <?php _e('If enabled, an attribute that has groups shows its groups in the shop filters instead of the values that belong to them. Values in no group keep showing individually, and product pages are unaffected.', 'rentopian-sync')?>
                                            </div>
                                        </div>
                                        <div class="clearfix"></div>
                                    </div>

                                    <div class="rental-form-group">
                                        <div class="rntp-sync-form-title">
                                            <label class="rental-label" for="rntp-checkbox-<?php echo esc_attr($opt_display_sale_products_page)?>">
                                                <?php _e('Display Sale Products page', 'rentopian-sync')?>
                                            </label>
                                        </div>
                                        <div class="rntp-sync-field">
                                            <div class="rntp-checkbox inline">
                                                <label>
                                                    <input id="rntp-checkbox-<?php echo esc_attr($opt_display_sale_products_page)?>"
                                                            name="<?php echo esc_attr($opt_display_sale_products_page); ?>"
                                                            type="checkbox"
                                                            value="true"
                                                        <?php echo $val_display_sale_products_page ? 'checked="checked"' : ''; ?>/>
                                                    <span></span>
                                                </label>
                                            </div>
                                        </div>
                                        <div class="rntp-help-block">
                                            <div class="rntp-help-block-content">
                                                <?php _e('If enabled sale products page will be available (/rntp-sale-products/) .', 'rentopian-sync')?>
                                            </div>
                                        </div>
                                        <div class="clearfix"></div>
                                    </div>

                                <!-- Products section -->
                            </div>

                            <div class="section-wrapper">
                                <!-- Visual Components section -->
                                <div class="rental-inner-title">
                                    <h3>Visual Components</h3>
                                </div>

                                    <?php if ($not_only_sale_products && $not_only_hourly_products) : ?>
                                        <div class="rental-form-group">
                                            <div class="rntp-sync-form-title">
                                                <label class="rental-label" for="rntp-checkbox-<?php echo esc_attr($opt_hide_product_type_label)?>">
                                                    <?php _e('Hide Product Type Label', 'rentopian-sync')?>
                                                </label>
                                            </div>
                                            <div class="rntp-sync-field">
                                                <div class="rntp-checkbox inline">
                                                    <label>
                                                        <input id="rntp-checkbox-<?php echo esc_attr($opt_hide_product_type_label)?>"
                                                            name="<?php echo esc_attr($opt_hide_product_type_label); ?>"
                                                            type="checkbox"
                                                            value="true"
                                                            <?php echo $val_hide_product_type_label? 'checked="checked"': ''; ?>/>
                                                        <span></span>
                                                    </label>
                                                </div>
                                            </div>
                                            <div class="rntp-help-block">
                                                <div class="rntp-help-block-content">
                                                    <?php _e('The label specifying if a product is rented or sold will not be shown.', 'rentopian-sync')?>
                                                </div>
                                            </div>
                                            <div class="clearfix"></div>
                                        </div>
                                    <?php endif?>

                                    <div class="rental-form-group">
                                        <div class="rntp-sync-form-title">
                                            <label class="rental-label" for="rntp-checkbox-<?php echo esc_attr($opt_select_division)?>">
                                                <?php _e('Enable Location selection', 'rentopian-sync')?>
                                            </label>
                                        </div>
                                        <div class="rntp-sync-field">
                                            <div class="rntp-checkbox inline">
                                                <label>
                                                    <input id="rntp-checkbox-<?php echo esc_attr($opt_select_division); ?>"
                                                        name="<?php echo esc_attr($opt_select_division); ?>" type="checkbox"
                                                        <?php if ($val_select_division): ?>checked="checked"<?php endif ?>
                                                        value="true"/>
                                                    <span></span>
                                                </label>
                                            </div>
                                        </div>
                                        <div class="rntp-help-block">
                                            <div class="rntp-help-block-content">
                                                <?php _e('Enable shortcode for location selection.', 'rentopian-sync')?>
                                            </div>
                                        </div>
                                        <div class="clearfix"></div>
                                    </div>

                                    <div class="rental-form-group">
                                        <div class="rntp-sync-form-title">
                                            <label class="rental-label" for="rntp-checkbox-<?php echo esc_attr($opt_not_open_dates_form)?>">
                                                <?php _e('Do not auto pull the fly-in form', 'rentopian-sync')?>
                                            </label>
                                        </div>
                                        <div class="rntp-sync-field">
                                            <div class="rntp-checkbox inline">
                                                <label>
                                                    <input id="rntp-checkbox-<?php echo esc_attr($opt_not_open_dates_form); ?>"
                                                        name="<?php echo esc_attr($opt_not_open_dates_form); ?>" type="checkbox"
                                                        <?php if ($val_not_open_dates_form): ?>checked="checked"<?php endif ?>
                                                        value="true"/>
                                                    <span></span>
                                                </label>
                                            </div>
                                        </div>
                                        <div class="rntp-help-block">
                                            <div class="rntp-help-block-content">
                                                <?php _e("Don't pull up the dates form automatically in product page if rental dates are not selected.", 'rentopian-sync')?>
                                            </div>
                                        </div>
                                        <div class="clearfix"></div>
                                    </div>

                                    <div class="rental-form-group">
                                        <div class="rntp-sync-form-title">
                                            <label class="rental-label" for="rntp-checkbox-<?php echo esc_attr($opt_auto_date_show_product_selection)?>">
                                                <?php _e('Automatically show products on date selection ( Standalone, Horizontal date selection type )', 'rentopian-sync')?>
                                            </label>
                                        </div>
                                        <div class="rntp-sync-field">
                                            <div class="rntp-checkbox inline">
                                                <label>
                                                    <input id="rntp-checkbox-<?php echo esc_attr($opt_auto_date_show_product_selection); ?>"
                                                        name="<?php echo esc_attr($opt_auto_date_show_product_selection); ?>" type="checkbox"
                                                        <?php if ($val_auto_date_show_product_selection): ?>checked="checked"<?php endif ?>
                                                        value="true"/>
                                                    <span></span>
                                                </label>
                                            </div>
                                        </div>
                                        <div class="rntp-help-block">
                                            <div class="rntp-help-block-content">
                                                <?php _e("Automatically trigger the 'Show Products' action once valid dates have been selected, without requiring the user to click the submit button. When the ZIP Code field is shown, it has to be filled in first.", 'rentopian-sync')?>
                                            </div>
                                        </div>
                                        <div class="clearfix"></div>
                                    </div>

                                    <div class="rental-form-group">
                                        <div class="rntp-sync-form-title">
                                            <label class="rental-label" for="rntp-checkbox-<?php echo esc_attr($opt_auto_add_to_cart_on_date_selection)?>">
                                                <?php _e('Automatically add the product to cart on date selection', 'rentopian-sync')?>
                                            </label>
                                        </div>
                                        <div class="rntp-sync-field">
                                            <div class="rntp-checkbox inline">
                                                <label>
                                                    <input id="rntp-checkbox-<?php echo esc_attr($opt_auto_add_to_cart_on_date_selection); ?>"
                                                        name="<?php echo esc_attr($opt_auto_add_to_cart_on_date_selection); ?>" type="checkbox"
                                                        <?php if ($val_auto_add_to_cart_on_date_selection): ?>checked="checked"<?php endif ?>
                                                        value="true"/>
                                                    <span></span>
                                                </label>
                                            </div>
                                        </div>
                                        <div class="rntp-help-block">
                                            <div class="rntp-help-block-content">
                                                <?php _e("Add the product to the cart when the rental dates are submitted from its product page. Products that need a choice — sets, products with add-ons and variable products — are never added automatically. Turn this off to let the customer add the product themselves.", 'rentopian-sync')?>
                                            </div>
                                        </div>
                                        <div class="clearfix"></div>
                                    </div>

                                <!-- Visual Components section -->
                            </div>

                            <?php Rental_Translation_Admin::render_settings_section(); ?>
                             
                                    <div class="panel-footer settings-panel-footer">
                                        <div class="submit">
                                            <button type="submit"
                                                    class="btn btn-success rental-settings-section-save"
                                                    data-section="general">
                                                <span class="dashicons dashicons-yes"></span>
                                                <?php _e('Save General Settings', 'rentopian-sync') ?>
                                            </button>
                                            <span class="rental-section-status" data-section="general"></span>
                                        </div>
                                    </div>
                            </form>
                        </div>

                        <!-- Tab : Textual Labels -->
                        <div class="rental-tab">

                        <form method="post"
                            class="rental-settings-section-form"
                            data-section="text_labels"
                            id="rental_settings_text_labels">

                            <?php wp_nonce_field('rental_settings_section_save', 'rental_settings_section_nonce'); ?>
                            <input type="hidden" name="action" value="rental_save_settings_section" />
                            <input type="hidden" name="section" value="text_labels" />
                                    
                                    

                                            <div class="rental-form-group">
                                                <div class="rntp-sync-form-title">
                                                    <label class="rental-label" for="rntp-checkbox-<?php echo esc_attr($opt_cart_button_text)?>">
                                                        <?php _e('"Cart" alternative label', 'rentopian-sync')?>
                                                    </label>
                                                </div>
                                                <div class="rntp-sync-field">
                                                    <div class="rntp-input-text">
                                                        <input type="text" id="rntp-checkbox-<?php echo esc_attr($opt_cart_button_text)?>"
                                                            name="<?php echo esc_attr($opt_cart_button_text); ?>"
                                                            value="<?php echo esc_attr($val_cart_button_text); ?>"/>
                                                    </div>
                                                </div>
                                                <div class="rntp-help-block">
                                                    <div class="rntp-help-block-content">
                                                        <?php _e('You can set custom text for "Add to cart" button', 'rentopian-sync')?>
                                                    </div>
                                                </div>
                                                <div class="clearfix"></div>
                                            </div>

                                            <div class="rental-form-group">
                                                <div class="rntp-sync-form-title">
                                                    <label class="rental-label" for="rntp-checkbox-<?php echo esc_attr($opt_order_text)?>">
                                                        <?php _e('"Order" alternative label', 'rentopian-sync')?>
                                                    </label>
                                                </div>
                                                <div class="rntp-sync-field">
                                                    <div class="rntp-input-text">
                                                        <input type="text" id="rntp-checkbox-<?php echo esc_attr($opt_order_text)?>"
                                                            name="<?php echo esc_attr($opt_order_text); ?>"
                                                            value="<?php echo esc_attr($val_order_text); ?>"/>
                                                    </div>
                                                </div>
                                                <div class="rntp-help-block">
                                                    <div class="rntp-help-block-content">
                                                        <?php _e('Replace the word "Order" anywhere in the website with a custom word.', 'rentopian-sync')?>
                                                    </div>
                                                </div>
                                                <div class="clearfix"></div>
                                            </div>

                                            <div class="rental-form-group">
                                                <div class="rntp-sync-form-title">
                                                    <label class="rental-label" for="rntp-checkbox-<?php echo esc_attr($opt_read_more_text)?>">
                                                        <?php _e('"Read More" alternative label', 'rentopian-sync')?>
                                                    </label>
                                                </div>
                                                <div class="rntp-sync-field">
                                                    <div class="rntp-input-text">
                                                        <input type="text" id="rntp-checkbox-<?php echo esc_attr($opt_read_more_text)?>"
                                                            name="<?php echo esc_attr($opt_read_more_text); ?>"
                                                            value="<?php echo esc_attr($val_read_more_text); ?>"/>
                                                    </div>
                                                </div>
                                                <div class="rntp-help-block">
                                                    <div class="rntp-help-block-content">
                                                        <?php _e('Replace all occurrences of the button with label "Read More" that shows for products with more than 1 variation or more than 1 option. If nothing is set, "Select Options" will be set.', 'rentopian-sync')?>
                                                    </div>
                                                </div>
                                                <div class="clearfix"></div>
                                            </div>

                                            <div class="rental-form-group">
                                                <div class="rntp-sync-form-title">
                                                    <label class="rental-label" for="rntp-checkbox-<?php echo esc_attr($opt_checkout_button_text)?>">
                                                        <?php _e('Proceed to checkout button text', 'rentopian-sync')?>
                                                    </label>
                                                </div>
                                                <div class="rntp-sync-field">
                                                    <div class="rntp-input-text">
                                                        <input type="text" id="rntp-checkbox-<?php echo esc_attr($opt_checkout_button_text)?>"
                                                            name="<?php echo esc_attr($opt_checkout_button_text); ?>"
                                                            value="<?php echo esc_attr($val_checkout_button_text); ?>"/>
                                                    </div>
                                                </div>
                                                <div class="rntp-help-block">
                                                    <div class="rntp-help-block-content">
                                                        <?php _e('You can set custom text for "Proceed to checkout" button', 'rentopian-sync')?>
                                                    </div>
                                                </div>
                                                <div class="clearfix"></div>
                                            </div>
                                            <div class="rental-form-group">
                                                <div class="rntp-sync-form-title">
                                                    <label class="rental-label" for="rntp-checkbox-<?php echo esc_attr($opt_order_button_text)?>">
                                                        <?php _e('Place order button text', 'rentopian-sync')?>
                                                    </label>
                                                </div>
                                                <div class="rntp-sync-field">
                                                    <div class="rntp-input-text">
                                                        <input type="text" id="rntp-checkbox-<?php echo esc_attr($opt_order_button_text)?>"
                                                            name="<?php echo esc_attr($opt_order_button_text); ?>"
                                                            value="<?php echo esc_attr($val_order_button_text); ?>"/>
                                                    </div>
                                                </div>
                                                <div class="rntp-help-block">
                                                    <div class="rntp-help-block-content">
                                                        <?php _e('You can set custom text for "Place order" button', 'rentopian-sync')?>
                                                    </div>
                                                </div>
                                                <div class="clearfix"></div>
                                            </div>

                                            <div class="rental-form-group">
                                                <div class="rntp-sync-form-title">
                                                    <label class="rental-label" for="rntp-checkbox-<?php echo esc_attr($opt_daily_fee_text)?>">
                                                        <?php _e('"Daily Fee" Alternative text', 'rentopian-sync')?>
                                                    </label>
                                                </div>
                                                <div class="rntp-sync-field">
                                                    <div class="rntp-input-text">
                                                        <input type="text" id="rntp-checkbox-<?php echo esc_attr($opt_daily_fee_text)?>"
                                                            name="<?php echo esc_attr($opt_daily_fee_text); ?>"
                                                            value="<?php echo isset($val_daily_fee_text) ? esc_attr($val_daily_fee_text) :  __('Daily fee', 'rentopian-sync'); ?>"/>
                                                    </div>
                                                </div>
                                                <div class="rntp-help-block">
                                                    <div class="rntp-help-block-content">
                                                        <?php _e('Replace the text of the "Daily fee" label with a custom one.', 'rentopian-sync')?>
                                                    </div>
                                                </div>
                                                <div class="clearfix"></div>
                                            </div>
                                            <div class="rental-form-group">
                                                <div class="rntp-sync-form-title">
                                                    <label class="rental-label" for="rntp-checkbox-<?php echo esc_attr($opt_tax_text)?>">
                                                        <?php _e('"Tax" Alternative text', 'rentopian-sync')?>
                                                    </label>
                                                </div>
                                                <div class="rntp-sync-field">
                                                    <div class="rntp-input-text">
                                                        <input type="text" id="rntp-checkbox-<?php echo esc_attr($opt_tax_text)?>"
                                                            name="<?php echo esc_attr($opt_tax_text); ?>"
                                                            value="<?php echo isset($val_tax_text) ? esc_attr($val_tax_text) :  __('Tax', 'rentopian-sync'); ?>"/>
                                                    </div>
                                                </div>
                                                <div class="rntp-help-block">
                                                    <div class="rntp-help-block-content">
                                                        <?php _e('You can set custom text for "Tax" label', 'rentopian-sync')?>
                                                    </div>
                                                </div>
                                                <div class="clearfix"></div>
                                            </div>
                                            <div class="rental-form-group">
                                                <div class="rntp-sync-form-title">
                                                    <label class="rental-label" for="rntp-checkbox-<?php echo esc_attr($opt_shipping_text)?>">
                                                        <?php _e('"Shipping" Alternative text', 'rentopian-sync')?>
                                                    </label>
                                                </div>
                                                <div class="rntp-sync-field">
                                                    <div class="rntp-input-text">
                                                        <input type="text" id="rntp-checkbox-<?php echo esc_attr($opt_shipping_text)?>"
                                                            name="<?php echo esc_attr($opt_shipping_text); ?>"
                                                            value="<?php echo isset($val_shipping_text) ? esc_attr($val_shipping_text) :  __('Shipping', 'rentopian-sync'); ?>"/>
                                                    </div>
                                                </div>
                                                <div class="rntp-help-block">
                                                    <div class="rntp-help-block-content">
                                                        <?php _e('You can set custom text for "Shipping" label', 'rentopian-sync')?>
                                                    </div>
                                                </div>
                                                <div class="clearfix"></div>
                                            </div>
                                            <div class="rental-form-group">
                                                <div class="rntp-sync-form-title">
                                                    <label class="rental-label" for="rntp-checkbox-<?php echo esc_attr($opt_ship_to_dif_adrs_text)?>">
                                                        <?php _e('"Ship to a different address?" Alternative text', 'rentopian-sync')?>
                                                    </label>
                                                </div>
                                                <div class="rntp-sync-field">
                                                    <div class="rntp-input-text">
                                                        <input type="text" id="rntp-checkbox-<?php echo esc_attr($opt_ship_to_dif_adrs_text)?>"
                                                            name="<?php echo esc_attr($opt_ship_to_dif_adrs_text); ?>"
                                                            value="<?php echo isset($val_ship_to_dif_adrs_text) ? esc_attr($val_ship_to_dif_adrs_text) : ''; ?>"/>
                                                    </div>
                                                </div>
                                                <div class="rntp-help-block">
                                                    <div class="rntp-help-block-content">
                                                        <?php _e('You can set custom text for "Ship to a different address?" label in Checkout page', 'rentopian-sync')?>
                                                    </div>
                                                </div>
                                                <div class="clearfix"></div>
                                            </div>
                                            <div class="rental-form-group">
                                                <div class="rntp-sync-form-title">
                                                    <label class="rental-label" for="rntp-checkbox-<?php echo esc_attr($opt_select_option_text)?>">
                                                        <?php _e('"Please select an option" Alternative text', 'rentopian-sync')?>
                                                    </label>
                                                </div>
                                                <div class="rntp-sync-field">
                                                    <div class="rntp-input-text">
                                                        <input type="text" id="rntp-checkbox-<?php echo esc_attr($opt_select_option_text)?>"
                                                            name="<?php echo esc_attr($opt_select_option_text); ?>"
                                                            value="<?php echo isset($val_select_option_text) ? esc_attr($val_select_option_text) :  __('Please select an option', 'rentopian-sync'); ?>"/>
                                                    </div>
                                                </div>
                                                <div class="rntp-help-block">
                                                    <div class="rntp-help-block-content">
                                                        <?php _e('You can set custom text for "Please select an option" label', 'rentopian-sync')?>
                                                    </div>
                                                </div>
                                                <div class="clearfix"></div>
                                            </div>
                                            <div class="rental-form-group">
                                                <div class="rntp-sync-form-title">
                                                    <label class="rental-label" for="rntp-checkbox-<?php echo esc_attr($opt_start_date_text)?>">
                                                        <?php _e('"Start Date" Alternative text', 'rentopian-sync')?>
                                                    </label>
                                                </div>
                                                <div class="rntp-sync-field">
                                                    <div class="rntp-input-text">
                                                        <input type="text" id="rntp-checkbox-<?php echo esc_attr($opt_start_date_text)?>"
                                                            name="<?php echo esc_attr($opt_start_date_text); ?>"
                                                            value="<?php echo isset($val_start_date_text) ? esc_attr($val_start_date_text) :  __('Start Date', 'rentopian-sync'); ?>"/>
                                                    </div>
                                                </div>
                                                <div class="rntp-help-block">
                                                    <div class="rntp-help-block-content">
                                                        <?php _e('You can set custom text for "Start Date" label', 'rentopian-sync')?>
                                                    </div>
                                                </div>
                                                <div class="clearfix"></div>
                                            </div>

                                            <div class="rental-form-group">
                                                <div class="rntp-sync-form-title">
                                                    <label class="rental-label" for="rntp-checkbox-<?php echo esc_attr($opt_end_date_text)?>">
                                                        <?php _e('"Return Date" Alternative text', 'rentopian-sync')?>
                                                    </label>
                                                </div>
                                                <div class="rntp-sync-field">
                                                    <div class="rntp-input-text">
                                                        <input type="text" id="rntp-checkbox-<?php echo esc_attr($opt_end_date_text)?>"
                                                            name="<?php echo esc_attr($opt_end_date_text); ?>"
                                                            value="<?php echo isset($val_end_date_text) ? esc_attr($val_end_date_text) :  __('Return Date', 'rentopian-sync'); ?>"/>
                                                    </div>
                                                </div>
                                                <div class="rntp-help-block">
                                                    <div class="rntp-help-block-content">
                                                        <?php _e('You can set custom text for "Return Date" label', 'rentopian-sync')?>
                                                    </div>
                                                </div>
                                                <div class="clearfix"></div>
                                            </div>

                                            <div class="rental-form-group">
                                                <div class="rntp-sync-form-title">
                                                    <label class="rental-label" for="rntp-checkbox-<?php echo esc_attr($opt_proceed_text)?>">
                                                        <?php _e('"Proceed" Alternative text', 'rentopian-sync')?>
                                                    </label>
                                                </div>
                                                <div class="rntp-sync-field">
                                                    <div class="rntp-input-text">
                                                        <input type="text" id="rntp-checkbox-<?php echo esc_attr($opt_proceed_text)?>"
                                                            name="<?php echo esc_attr($opt_proceed_text); ?>"
                                                            value="<?php echo isset($val_proceed_text) ? esc_attr($val_proceed_text) : ""; ?>"/>
                                                    </div>
                                                </div>
                                                <div class="rntp-help-block">
                                                    <div class="rntp-help-block-content">
                                                        <?php _e('You can set custom text for "Proceed" label of horizontal form in Checkout page.', 'rentopian-sync')?>
                                                    </div>
                                                </div>
                                                <div class="clearfix"></div>
                                            </div>

                                            <div class="rental-form-group">
                                                <div class="rntp-sync-form-title">
                                                    <label class="rental-label" for="rntp-checkbox-<?php echo esc_attr($opt_set_components_text)?>">
                                                        <?php _e('"Sets Components" Alternative text', 'rentopian-sync')?>
                                                    </label>
                                                </div>
                                                <div class="rntp-sync-field">
                                                    <div class="rntp-input-text">
                                                        <input type="text" id="rntp-checkbox-<?php echo esc_attr($opt_set_components_text)?>"
                                                            name="<?php echo esc_attr($opt_set_components_text); ?>"
                                                            value="<?php echo isset($val_set_components_text) ? esc_attr($val_set_components_text) : "Set Components"; ?>"/>
                                                    </div>
                                                </div>
                                                <div class="rntp-help-block">
                                                    <div class="rntp-help-block-content">
                                                        <?php _e('If set to blank, the set components table header will not be generated.', 'rentopian-sync')?>
                                                    </div>
                                                </div>
                                                <div class="clearfix"></div>
                                            </div>

                                            <div class="rental-form-group">
                                                <div class="rntp-sync-form-title">
                                                    <label class="rental-label" for="rntp-checkbox-<?php echo esc_attr($opt_referral_sources_text)?>">
                                                        <?php _e('"Referral Sources" alternative text', 'rentopian-sync')?>
                                                    </label>
                                                </div>
                                                <div class="rntp-sync-field">
                                                    <div class="rntp-input-text">
                                                        <input type="text" id="rntp-checkbox-<?php echo esc_attr($opt_referral_sources_text)?>"
                                                            name="<?php echo esc_attr($opt_referral_sources_text); ?>"
                                                            value="<?php echo isset($val_referral_sources_text) ? esc_attr($val_referral_sources_text) : ""; ?>"/>
                                                    </div>
                                                </div>
                                                <div class="rntp-help-block">
                                                    <div class="rntp-help-block-content">
                                                        <?php _e('You can set custom text for "Referral Sources" label of dropdown list in Checkout page.', 'rentopian-sync')?>
                                                    </div>
                                                </div>
                                                <div class="clearfix"></div>
                                            </div>

                                            <div class="rental-form-group">
                                                <div class="rntp-sync-form-title">
                                                    <label class="rental-label" for="rntp-checkbox-<?php echo esc_attr($opt_event_types_text)?>">
                                                        <?php _e('"Event Types" alternative text', 'rentopian-sync')?>
                                                    </label>
                                                </div>
                                                <div class="rntp-sync-field">
                                                    <div class="rntp-input-text">
                                                        <input type="text" id="rntp-checkbox-<?php echo esc_attr($opt_event_types_text)?>"
                                                            name="<?php echo esc_attr($opt_event_types_text); ?>"
                                                            value="<?php echo isset($val_event_types_text) ? esc_attr($val_event_types_text) : ""; ?>"/>
                                                    </div>
                                                </div>
                                                <div class="rntp-help-block">
                                                    <div class="rntp-help-block-content">
                                                        <?php _e('You can set custom text for "Event Types" label of dropdown list in Checkout page.', 'rentopian-sync')?>
                                                    </div>
                                                </div>
                                                <div class="clearfix"></div>
                                            </div>

                                            <div class="rental-form-group">
                                                <div class="rntp-sync-form-title">
                                                    <label class="rental-label" for="rntp-input-<?php echo esc_attr($opt_delivery_time_label)?>">
                                                        <?php _e('"Delivery Time Window" alternative text', 'rentopian-sync')?>
                                                    </label>
                                                </div>
                                                <div class="rntp-sync-field">
                                                    <div class="rntp-input-text">
                                                        <input type="text" id="rntp-input-<?php echo esc_attr($opt_delivery_time_label)?>"
                                                            name="<?php echo esc_attr($opt_delivery_time_label); ?>"
                                                            value="<?php echo isset($val_delivery_time_label) ? esc_attr($val_delivery_time_label) : ""; ?>"
                                                            placeholder="<?php esc_attr_e('Delivery Time Window', 'rentopian-sync'); ?>"/>
                                                    </div>
                                                </div>
                                                <div class="rntp-help-block">
                                                    <div class="rntp-help-block-content">
                                                        <?php _e('You can set custom text for the "Delivery Time Window" dropdown label on the Checkout page. (Used when delivery time selections are enabled.)', 'rentopian-sync')?>
                                                    </div>
                                                </div>
                                                <div class="clearfix"></div>
                                            </div>

                                            <div class="rental-form-group">
                                                <div class="rntp-sync-form-title">
                                                    <label class="rental-label" for="rntp-input-<?php echo esc_attr($opt_pickup_time_label)?>">
                                                        <?php _e('"Pickup/Strike Time Window" alternative text', 'rentopian-sync')?>
                                                    </label>
                                                </div>
                                                <div class="rntp-sync-field">
                                                    <div class="rntp-input-text">
                                                        <input type="text" id="rntp-input-<?php echo esc_attr($opt_pickup_time_label)?>"
                                                            name="<?php echo esc_attr($opt_pickup_time_label); ?>"
                                                            value="<?php echo isset($val_pickup_time_label) ? esc_attr($val_pickup_time_label) : ""; ?>"
                                                            placeholder="<?php esc_attr_e('Pickup Time Window', 'rentopian-sync'); ?>"/>
                                                    </div>
                                                </div>
                                                <div class="rntp-help-block">
                                                    <div class="rntp-help-block-content">
                                                        <?php _e('You can set custom text for the "Pickup/Strike Time Window" dropdown label on the Checkout page. (Used when pickup time selections are enabled.)', 'rentopian-sync')?>
                                                    </div>
                                                </div>
                                                <div class="clearfix"></div>
                                            </div>

                                            <div class="rental-form-group">
                                                <div class="rntp-sync-form-title">
                                                    <label class="rental-label" for="rntp-checkbox-<?php echo esc_attr($opt_miles_text)?>">
                                                        <?php _e('"Miles Bases Shipping" alternative text', 'rentopian-sync')?>
                                                    </label>
                                                </div>
                                                <div class="rntp-sync-field">
                                                    <div class="rntp-input-text">
                                                        <input type="text" id="rntp-checkbox-<?php echo esc_attr($opt_miles_text)?>"
                                                            name="<?php echo esc_attr($opt_miles_text); ?>"
                                                            value="<?php echo isset($val_miles_text) ? esc_attr($val_miles_text) : ""; ?>"/>
                                                    </div>
                                                </div>
                                                <div class="rntp-help-block">
                                                    <div class="rntp-help-block-content">
                                                        <?php _e('You can set custom text for "Miles Bases Shipping" label of delivery/shipping section.', 'rentopian-sync')?>
                                                    </div>
                                                </div>
                                                <div class="clearfix"></div>
                                            </div>

                                            <div class="rental-form-group">
                                                <div class="rntp-sync-form-title">
                                                    <label class="rental-label" for="rntp-checkbox-<?php echo esc_attr($opt_thank_you_message)?>">
                                                        <?php _e('"Order received / Thank You page " alternative message', 'rentopian-sync')?>
                                                    </label>
                                                </div>
                                                <div class="rntp-sync-field">
                                                    <div class="rntp-input-text">
                                                        <input type="text" id="rntp-checkbox-<?php echo esc_attr($opt_thank_you_message)?>"
                                                            name="<?php echo esc_attr($opt_thank_you_message); ?>"
                                                            value="<?php echo isset($val_thank_you_message) ? esc_attr($val_thank_you_message) : ""; ?>"/>
                                                    </div>
                                                </div>
                                                <div class="rntp-help-block">
                                                    <div class="rntp-help-block-content">
                                                        <?php _e('You can set custom text for "Thank You message" in Order received page.', 'rentopian-sync')?>
                                                    </div>
                                                </div>
                                                <div class="clearfix"></div>
                                            </div>

                                            <div class="rental-form-group">
                                                <div class="rntp-sync-form-title">
                                                    <label class="rental-label" for="rntp-checkbox-<?php echo esc_attr($opt_payment_tips_text)?>">
                                                        <?php _e('"Tip" alternative message', 'rentopian-sync')?>
                                                    </label>
                                                </div>
                                                <div class="rntp-sync-field">
                                                    <div class="rntp-input-text">
                                                        <input type="text" id="rntp-checkbox-<?php echo esc_attr($opt_payment_tips_text)?>"
                                                            name="<?php echo esc_attr($opt_payment_tips_text); ?>"
                                                            value="<?php echo isset($val_payment_tips_text) ? esc_attr($val_payment_tips_text) : ""; ?>"/>
                                                    </div>
                                                </div>
                                                <div class="rntp-help-block">
                                                    <div class="rntp-help-block-content">
                                                        <?php _e('You can set custom text for "Payment Tips" label of quote/order checkout section.', 'rentopian-sync')?>
                                                    </div>
                                                </div>
                                                <div class="clearfix"></div>
                                            </div>

                                            <div class="rental-form-group">
                                                <div class="rntp-sync-form-title">
                                                    <label class="rental-label" for="rntp-checkbox-<?php echo esc_attr($opt_billing_details_text)?>">
                                                        <?php _e('"Billing Details" alternative message', 'rentopian-sync')?>
                                                    </label>
                                                </div>
                                                <div class="rntp-sync-field">
                                                    <div class="rntp-input-text">
                                                        <input type="text" id="rntp-checkbox-<?php echo esc_attr($opt_billing_details_text)?>"
                                                            name="<?php echo esc_attr($opt_billing_details_text); ?>"
                                                            value="<?php echo isset($val_billing_details_text) ? esc_attr($val_billing_details_text) : ""; ?>"/>
                                                    </div>
                                                </div>
                                                <div class="rntp-help-block">
                                                    <div class="rntp-help-block-content">
                                                        <?php _e('You can set custom text for "Billing Details" label of quote/order checkout section.', 'rentopian-sync')?>
                                                    </div>
                                                </div>
                                                <div class="clearfix"></div>
                                            </div>

                                            <div class="rental-form-group">
                                                <div class="rntp-sync-form-title">
                                                    <label class="rental-label" for="rntp-checkbox-<?php echo esc_attr($opt_coupon_label_text)?>">
                                                        <?php _e('"Coupon" label alternative text', 'rentopian-sync')?>
                                                    </label>
                                                </div>
                                                <div class="rntp-sync-field">
                                                    <div class="rntp-input-text">
                                                        <input type="text" id="rntp-checkbox-<?php echo esc_attr($opt_coupon_label_text)?>"
                                                            name="<?php echo esc_attr($opt_coupon_label_text); ?>"
                                                            value="<?php echo isset($val_coupon_label_text) ? esc_attr($val_coupon_label_text) : ""; ?>"/>
                                                    </div>
                                                </div>
                                                <div class="rntp-help-block">
                                                    <div class="rntp-help-block-content">
                                                        <?php _e('You can set custom text for "Coupon" label.', 'rentopian-sync')?>
                                                    </div>
                                                </div>
                                                <div class="clearfix"></div>
                                            </div>

                                            <div class="rental-form-group">
                                                <div class="rntp-sync-form-title">
                                                    <label class="rental-label" for="rntp-checkbox-<?php echo esc_attr($opt_street_address_label_text)?>">
                                                        <?php _e('"Street Address" label alternative text', 'rentopian-sync')?>
                                                    </label>
                                                </div>
                                                <div class="rntp-sync-field">
                                                    <div class="rntp-input-text">
                                                        <input type="text" id="rntp-checkbox-<?php echo esc_attr($opt_street_address_label_text)?>"
                                                            name="<?php echo esc_attr($opt_street_address_label_text); ?>"
                                                            value="<?php echo isset($val_street_address_label_text) ? esc_attr($val_street_address_label_text) : ""; ?>"/>
                                                    </div>
                                                </div>
                                                <div class="rntp-help-block">
                                                    <div class="rntp-help-block-content">
                                                        <?php _e('You can set custom text for "Street Address" label in Checkout page.', 'rentopian-sync')?>
                                                    </div>
                                                </div>
                                                <div class="clearfix"></div>
                                            </div>

                                        
                                <div class="panel-footer settings-panel-footer">
                                    <div class="submit">
                                        <button type="submit"
                                                class="btn btn-success rental-settings-section-save"
                                                data-section="text_labels">
                                            <span class="dashicons dashicons-yes"></span>
                                            <?php _e('Save Textual Labels Settings', 'rentopian-sync'); ?>
                                        </button>
                                        <span class="rental-section-status" data-section="text_labels"></span>
                                    </div>
                                </div>
                            </form>

                        </div>

                        <!-- Tab: Calculation Tiers-->
                        <div class="rental-tab">
                            
                            <div class="section-wrapper">
                                <form method="post"
                                    class="rental-settings-section-form"
                                    data-section="tiers"
                                    id="rental_settings_tiers">

                                    <?php wp_nonce_field('rental_settings_section_save', 'rental_settings_section_nonce'); ?>
                                    <input type="hidden" name="action" value="rental_save_settings_section" />
                                    <input type="hidden" name="section" value="tiers" />

                                    <div class="rental-form-group">
                                        <div class="rntp-sync-form-title">
                                            <label class="rental-label">
                                                Days Calculation Tiers
                                            </label>
                                        </div>
                                        <div class="rntp-sync-field tier-field">
                                            <table id="rental_tiers" class="table table-hover table-bordered rental-log-table">
                                                <thead>
                                                <tr>
                                                    <th><?php _e('Minimum Hours', 'rentopian-sync'); ?></th>
                                                    <th><?php _e('Maximum Hours', 'rentopian-sync'); ?></th>
                                                    <th><?php _e('Days', 'rentopian-sync'); ?></th>
                                                    <th><?php _e('Action', 'rentopian-sync'); ?></th>
                                                </tr>
                                                </thead>
                                                <tbody id="rental_tiers_container"></tbody>
                                                <tfoot>
                                                <tr>
                                                    <td colspan="4">
                                                        <button id="rental_add_tier" type="button" class="btn btn-sm btn-inline-success"><?php _e('Add Another Tier', 'rentopian-sync')?></button>
                                                    </td>
                                                </tr>
                                                </tfoot>
                                            </table>
                                            <input type="hidden" name="rental_day_tiers" id="rental_day_tiers" value="<?php echo htmlspecialchars(json_encode($tiers)); ?>">
                                        </div>
                                        <div class="rntp-help-block">
                                            <div class="rntp-help-block-content">
                                                <?php _e('Please Refer to documentation for this setting\'s usage information: ', 'rentopian-sync')?>
                                                <a href="https://rentopian.com/system-assets/rentopian-sync-documentation#days-calculation-tiers"><?php _e('Days calculation tiers', 'rentopian-sync')?></a>
                                            </div>
                                        </div>
                                        <div class="clearfix"></div>
                                    </div>

                                    <div class="panel-footer settings-panel-footer">
                                        <div class="submit">
                                            <button type="submit"
                                                    class="btn btn-success rental-settings-section-save"
                                                    data-section="tiers">
                                                <span class="dashicons dashicons-yes"></span>
                                                <?php _e('Save Tiers', 'rentopian-sync') ?>
                                            </button>
                                            <span class="rental-section-status" data-section="tiers"></span>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>

                    </div>
                </div>
        </div>
    </div>
    <?php
}

// rental_log_page() displays the page content for the second submenu of the custom Rentopian Log menu
function rental_log_page() {
    $opt_rental_runtime_log = "rental_runtime_log_setting";
    $val_rental_runtime_log = get_option("rental_runtime_log_setting", 2); // 1:enable, 2:disable

    ?>
    <div class="wrap">
        <h2 class="rental-title"><?php _e("Synchronization Results Log", 'rentopian-sync'); ?></h2>
        <hr/>

        <?php
        // One log for every synchronization generation. The rows are read
        // from wherever each generation already records them, so a site
        // upgraded from an older version keeps its whole history.
        ?>
        <div id="rental_sync_log_panel" class="rntp-sync-panel panel-default">
            <div class="panel-heading">
                <h3><?php _e('Synchronization Log', 'rentopian-sync'); ?></h3>
                <button type="button" class="btn btn-inline-info btn-sm btn-icon-fixed rental-sl-refresh">
                    <span class="dashicons dashicons-update"></span>
                    <?php _e('Refresh', 'rentopian-sync'); ?>
                </button>
            </div>

            <div class="panel-body">

                <div class="rental-sl-notice" style="display:none"></div>

                <table id="rental_sync_log_table" class="table table-hover table-bordered rental-log-table">
                    <thead>
                        <tr>
                            <th><?php _e('Sync ID', 'rentopian-sync'); ?></th>
                            <th><?php _e('Type', 'rentopian-sync'); ?></th>
                            <th><?php _e('Status', 'rentopian-sync'); ?></th>
                            <th><?php _e('Started', 'rentopian-sync'); ?></th>
                            <th><?php _e('Duration', 'rentopian-sync'); ?></th>
                            <th><?php _e('Processed', 'rentopian-sync'); ?></th>
                            <th><?php _e('Action', 'rentopian-sync'); ?></th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>

                <div class="rental-pagination"></div>
            </div>
        </div>
        <div id="rental_orders_log_panel" data-action="rental_paginate_orders_log"  class="rntp-sync-panel panel-default">
            <div class="panel-heading">
                <h3><?php _e('Unregistered Orders', 'rentopian-sync'); ?></h3>
            </div>
            <div class="panel-body">
                <table id="rental_orders_log_table" class="table table-hover table-bordered rental-log-table">
                    <thead>
                    <tr>
                        <th><?php _e('Order Id', 'rentopian-sync'); ?></th>
                        <th><?php _e('Status', 'rentopian-sync'); ?></th>
                        <th><?php _e('Message', 'rentopian-sync'); ?></th>
                        <th><?php _e('Time', 'rentopian-sync'); ?></th>
                        <th><?php _e('Action', 'rentopian-sync'); ?></th>
                    </tr>
                    </thead>
                    <tbody>

                    </tbody>
                </table>
                <div class="rental-pagination"></div>
            </div>
        </div>

        <div id="rntp-log-settings" class="rntp-sync-panel">

            <div class="ajax-alert">
                <strong></strong>
            </div>

            <div class="panel-heading">
                <h3> <?php _e('Runtime Log Settings', 'rentopian-sync')?> </h3>
            </div>

            <div class="panel-body">
                <div class="rental-form-group">
                    <div class="rntp-sync-form-title">
                        <label class="rental-label">
                            <?php _e('Register runtime errors', 'rentopian-sync')?>
                        </label>
                    </div>
                    <div class="rntp-sync-field">
                        <div class="rntp-radio inline">
                            <input id="rntp-form-runtime-log-enable"
                                   name="<?php echo esc_attr($opt_rental_runtime_log); ?>"
                                   required="required"
                                   type="radio"
                                   value="1"
                                   <?php if( esc_attr( $val_rental_runtime_log ) == 1):?>checked="checked"<?php endif?>
                            />
                            <label for="rntp-form-runtime-log-enable" class="radio-label">Enable</label>
                        </div>
                        <div class="rntp-radio inline">
                            <input id="rntp-form-runtime-log-disable"
                                   required="required"
                                   name="<?php echo esc_attr($opt_rental_runtime_log); ?>"
                                   type="radio"
                                   value="0"
                                   <?php if( esc_attr( $val_rental_runtime_log ) == 0):?>checked="checked"<?php endif?>
                            />
                            <label for="rntp-form-runtime-log-disable" class="radio-label">Disable</label>
                        </div>
                    </div>
                    <div class="rntp-help-block">
                        <div class="rntp-help-block-content">
                            <?php _e('Enable this option to log all the systems runtime errors except deprecated errors.', 'rentopian-sync')?>
                        </div>
                    </div>
                    <div class="clearfix"></div>
                </div>
            </div>
            <div class="panel-footer settings-panel-footer log-settings-footer">
                <div class="submit">
                    <button type="submit" id="rental_save_log_settings" name="submit" class="btn btn-success">
                        <span class="dashicons dashicons-yes"></span><?php _e('Save Log settings', 'rentopian-sync') ?>
                    </button>
                </div>
            </div>
        </div>

        <div id="rental_runtime_log_panel" data-action="rental_paginate_runtime_log" class="rntp-sync-panel panel-default">
            <div class="panel-heading">
                <h3><?php _e('Runtime Log', 'rentopian-sync'); ?></h3>
                <button id="rental_delete_runtime_log" class="btn btn-danger" disabled="disabled">
                    <?php _e('Clear Log', 'rentopian-sync'); ?>
                </button>
            </div>
            <div class="panel-body">
                <table id="rental_runtime_log_table" class="table table-hover table-bordered rental-log-table">
                    <thead>
                        <tr>
                            <th><?php _e('Status', 'rentopian-sync'); ?></th>
                            <th><?php _e('File', 'rentopian-sync'); ?></th>
                            <th><?php _e('Line', 'rentopian-sync'); ?></th>
                            <th><?php _e('Message', 'rentopian-sync'); ?></th>
                            <th><?php _e('Time', 'rentopian-sync'); ?></th>
                        </tr>
                    </thead>
                    <tbody>

                    </tbody>
                </table>
                <div class="rental-pagination"></div>
            </div>
        </div>


    </div>
    <?php
}

// function ajax to delete log item
function wp_ajax_rental_delete_log_item() {
    global $wpdb, $rental_tables;
    $rental_error_log = $wpdb->prefix . $rental_tables["error_log"];

    if (isset($_POST['sync_time']) && $_POST['sync_time']) {
        $wpdb->delete($rental_error_log, ['sync_time' => $_POST['sync_time']]);

        if ($wpdb->last_error !== '') {
            throw new RentalException("SQL_ERROR: $wpdb->last_error (SQL: $wpdb->last_query)");
        }

        wp_send_json(true, 200);
    }
    wp_die();
}

// function ajax to delete runtime log
function wp_ajax_rental_delete_runtime_log() {
    global $wpdb, $rental_tables;
    $rental_error_log = $wpdb->prefix . $rental_tables["error_log"];

    if (isset($_POST['delete']) && $_POST['delete']) {
        $wpdb->query("DELETE FROM $rental_error_log WHERE `type` = 0 OR `sync_time` IS NULL");

        if ($wpdb->last_error !== '') {
            throw new RentalException("SQL_ERROR: $wpdb->last_error (SQL: $wpdb->last_query)");
        }

        wp_send_json(true, 200);
    }
    wp_die();
}

// function ajax to delete runtime log
function wp_ajax_rental_save_log_settings() {
    if (isset($_POST['rental_runtime_log_setting'])) {
        update_option('rental_runtime_log_setting', intval($_POST['rental_runtime_log_setting']));
        wp_send_json(true, 200);
    }
    wp_die();
}


function wp_ajax_rental_change_file_sync_type() {
    if (isset($_POST['file_sync_type'])) {

        // 1: base 64 method (old method), 2: nginx method (new method)
        $fileSyncType = $_POST['file_sync_type'] === 'nginx' ? 2 : 1;
        update_option('rental_file_sync_method', $fileSyncType); 

        $deleted_files_result = rental_delete_product_images();

        wp_send_json(["data" => $deleted_files_result, "count" => isset($deleted_files_result['deleted']) ? count($deleted_files_result['deleted']) : 0], 200);
    }
    wp_die();
}

// function ajax to pagination runtime log
function wp_ajax_rental_paginate_runtime_log() {
    global $wpdb, $rental_tables;
    $rental_error_log = $wpdb->prefix . $rental_tables["error_log"];

    if (isset($_GET['page'])) {

        $limit = 15;
        $start = ($_GET['page'] - 1) * $limit;
        $row_count = $wpdb->get_var("SELECT COUNT(`id`) FROM $rental_error_log WHERE `type` = 0 OR `sync_time` IS NULL");
        $page_count = ceil($row_count / $limit);
        $log = $wpdb->get_results("SELECT * FROM $rental_error_log WHERE `type` = 0 OR `sync_time` IS NULL ORDER BY `register_time` DESC LIMIT $start, $limit");

        if ($wpdb->last_error !== '') {

            throw new RentalException("SQL_ERROR: $wpdb->last_error (SQL: $wpdb->last_query)");
        }

        wp_send_json(['log' => $log, 'count' => $page_count], 200);
    }

    wp_die();
}

// function ajax to pagination runtime log
function wp_ajax_rental_paginate_orders_log() {
    global $wpdb, $rental_tables;
    $rental_order_relations = $wpdb->prefix . $rental_tables["order_relations"];

    if (isset($_GET['page'])) {

        $limit = 15;
        $start = ($_GET['page'] - 1) * $limit;
        $row_count = $wpdb->get_var("SELECT COUNT(`id`) FROM $rental_order_relations WHERE `rental_id` = 0");
        $page_count = ceil($row_count / $limit);
        $log = $wpdb->get_results("SELECT `id`, `http_code`, `message`, `register_time` FROM $rental_order_relations WHERE `rental_id` = 0 ORDER BY `id` DESC LIMIT $start, $limit");

        if ($wpdb->last_error !== '') {

            throw new RentalException("SQL_ERROR: $wpdb->last_error (SQL: $wpdb->last_query)");
        }

        wp_send_json(['log' => $log, 'count' => $page_count], 200);
    }

    wp_die();
}

// function ajax to resend unregistered order
function wp_ajax_rental_resend_order() {
    if (!isset($_POST['order_id']) || !$order_id = (int) $_POST['order_id']) {
        wp_send_json(['message' => 'order_id is required'], 400);
        wp_die();
    }

    $result = rental_resend_order_internal($order_id);

    if (!$result['success'] && $result['code'] !== 200) {
        wp_send_json(['message' => $result['message']], $result['code']);
        wp_die();
    }

    $result['log']['id'] = $order_id;

    wp_send_json(['log' => $result['log']], 200);
    wp_die();
}

/**
 * Core logic for resending an order to Rentopian.
 *
 * Used by both:
 *  - wp_ajax_rental_resend_order (manual admin action)
 *  - "order/retry-invalid" webhook coming from Rentopian
 *
 * @param int $order_id
 * @param int $recovery_api_log_id Optional Laravel ApiLog ID of the first failed attempt.
 *
 * @return array {
 *   success: bool,
 *   code:    int,
 *   message: string,
 *   log:     array
 * }
 */
function rental_resend_order_internal($order_id, $recovery_api_log_id = 0)
{
    global $wpdb, $rental_tables;
    $rental_order_relations = $wpdb->prefix . $rental_tables['order_relations'];

    $order_id = (int) $order_id;
    if (!$order_id) {
        return [
            'success' => false,
            'code'    => 400,
            'message' => 'order_id is required',
            'log'     => [],
        ];
    }

    // Try to find by table `id` first, then fall back to `wp_order_id` if stored
    $data = $wpdb->get_var(
        $wpdb->prepare("SELECT `data` FROM $rental_order_relations WHERE `id` = %d", $order_id)
    );

    // If not found by id, try searching by wp_order_id in the serialized data
    if (empty($data)) {
        $data = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT `data` FROM $rental_order_relations WHERE `data` LIKE %s LIMIT 1",
                '%"wp_order_id";i:' . $order_id . ';%'
            )
        );
    }

    if (empty($data)) {
        return [
            'success' => false,
            'code'    => 404,
            'message' => "record with id $order_id not found",
            'log'     => [],
        ];
    }

    $data = unserialize($data);
    if (!is_array($data)) {
        return [
            'success' => false,
            'code'    => 500,
            'message' => "invalid payload for order id $order_id",
            'log'     => [],
        ];
    }

    // Attach original ApiLog ID so Laravel can mark recovery status
    if ($recovery_api_log_id) {
        $data['recovery_api_log_id'] = (int) $recovery_api_log_id;

        
        /**
         * Recovery-mode logic:
         *  - Decode the existing "inventories" payload.
         *  - Refresh entity mappings (inventory_id, etc.) from current WP DB relations.
         */
        if (!empty($data['inventories'])) {
            $inventories = json_decode($data['inventories'], true);

            if (!is_array($inventories)) {
                return [
                    'success' => false,
                    'code'    => 500,
                    'message' => "invalid inventories payload for order id $order_id",
                    'log'     => [],
                ];
            }

            $rebuildResult = rental_rebuild_inventories_for_recovery($order_id, $inventories);

            if (!$rebuildResult['allowed']) {
                return [
                    'success' => false,
                    'code'    => 409,
                    'message' => $rebuildResult['message'],
                    'log'     => [],
                ];
            }

            // Use the refreshed inventories payload
            $data['inventories'] = json_encode($rebuildResult['inventories']);
        }
    }

   

    $log = rental_send_order($order_id, $data, true);

    if (!empty($log['rental_id'])) {
        rental_pay_order($order_id);
    }

    $log['id'] = $order_id;

    return [
        'success' => !empty($log['rental_id']),
        'code'    => !empty($log['rental_id']) ? 200 : 400,
        'message' => '',
        'log'     => $log,
    ];
}

/**
 * Rebuild the "inventories" payload for an order during automatic recovery.
 *
 * Responsibilities:
 *  - Refresh `inventory_id` values from current WP <-> Rentopian relations
 *    using the latest `_rental_inventory_id` post meta.
 *  - Validate every non-empty `sel_variant_id`:
 *      * If the row is part of a set (has set_id), ensure that sel_variant_id
 *        still exists inside that set's current `_rental_set_items` structure
 *        (items, optional_items, addons, variants_optional).
 *  - If any sel_variant_id is no longer valid, auto-recovery is blocked.
 *
 * @param int   $order_id
 * @param array $inventories
 *
 * @return array {
 *   allowed: bool,
 *   inventories: array,
 *   message: string
 * }
 */
function rental_rebuild_inventories_for_recovery($order_id, array $inventories)
{
    global $wpdb, $rental_tables;

    $order_id = (int) $order_id;

    $rental_product_relations = $wpdb->prefix . $rental_tables['product_relations'];
    $rental_variant_relations = $wpdb->prefix . $rental_tables['variant_relations'];
    $rental_set_relations     = $wpdb->prefix . $rental_tables['set_relations'];

    $rebuilt = [];

    // Collect sel_variant_id grouped by set, plus a global bucket
    $sel_by_set  = []; // [set_id => [sel_variant_id => true, ...]]
    $all_sel_ids = []; // union of all sel_variant_ids we see

    foreach ($inventories as $item) {
        $newItem = $item;

        // product_id in this payload is the WP product (or variation) ID
        $wp_product_id = !empty($item['product_id']) ? (int) $item['product_id'] : 0;

        if ($wp_product_id) {
            // Refresh inventory_id from the latest mapping (post meta)
            $current_inventory_id = get_post_meta($wp_product_id, '_rental_inventory_id', true);
            if ($current_inventory_id) {
                $newItem['inventory_id'] = (int) $current_inventory_id;
            }

            // Variation products still use the same meta key; this is just explicit
            // $post = get_post($wp_product_id);
            // if ($post && $post->post_type === 'product_variation') {
            //     $variant_inventory_id = get_post_meta($wp_product_id, '_rental_inventory_id', true);
            //     if ($variant_inventory_id) {
            //         $newItem['inventory_id'] = (int) $variant_inventory_id;
            //     }
            // }
        }

        // Track sel_variant_id for validation
        if (!empty($item['sel_variant_id'])) {
            $sel_id = (int) $item['sel_variant_id'];

            if ($sel_id > 0) {
                $all_sel_ids[$sel_id] = true;

                $set_id = !empty($item['set_id']) ? (int) $item['set_id'] : 0;
                if ($set_id > 0) {
                    if (!isset($sel_by_set[$set_id])) {
                        $sel_by_set[$set_id] = [];
                    }
                    $sel_by_set[$set_id][$sel_id] = true;
                }
            }
        }

        /**
         * unchanged order data:
         *
         *  - set_id / set_subtotal / sel_variant_id / set_multiplier_applied
         *  - parent_id / parent_inventory_id (add-on relations)
         *  - product_options (selected option values)
         *  - rental_by_day / rental_by_interval / rental_by_slot / time_slot_id
        */

        $rebuilt[] = $newItem;
    }

    // If there is no sel_variant_id in the payload, nothing extra to validate
    if (empty($all_sel_ids)) {
        return [
            'allowed'     => true,
            'inventories' => $rebuilt,
            'message'     => '',
        ];
    }

    $missing_sel_ids = [];

    /**
     * Validate sel_variant_id values that belong to a set
     *    against that set's refreshed `_rental_set_items` data.
     *
     * We use/check:
     *  - rental_set_relations to get WP set product id from `set_id` (Rentopian ID)
     *  - `_rental_set_items` postmeta to read the current set items from the latest sync
     */
    foreach ($sel_by_set as $set_id => $sel_ids_for_set_map) {
        $sel_ids_for_set = array_map('intval', array_keys($sel_ids_for_set_map));

        // Resolve WP set product id from relations
        $wp_set_id = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT `id` FROM $rental_set_relations WHERE `rental_id` = %d LIMIT 1",
                $set_id
            )
        );

        $valid_ids_for_set = [];

        if ($wp_set_id) {
            $set_items = get_post_meta($wp_set_id, '_rental_set_items', true);

            // get_post_meta should already unserialize arrays, but be defensive
            if (is_string($set_items)) {
                $maybe_unserialized = @maybe_unserialize($set_items);
                if (is_array($maybe_unserialized)) {
                    $set_items = $maybe_unserialized;
                }
            }

            if (is_array($set_items)) {
                foreach ($set_items as $set_item) {
                    if (!is_array($set_item)) {
                        continue;
                    }

                    // Top-level rental ids
                    if (!empty($set_item['rental_variant_id'])) {
                        $valid_ids_for_set[(int) $set_item['rental_variant_id']] = true;
                    }
                    if (!empty($set_item['rental_product_id'])) {
                        $valid_ids_for_set[(int) $set_item['rental_product_id']] = true;
                    }

                    // Optional items
                    if (!empty($set_item['optional_items']) && is_array($set_item['optional_items'])) {
                        foreach ($set_item['optional_items'] as $opt_item) {
                            if (!is_array($opt_item)) {
                                continue;
                            }

                            if (!empty($opt_item['rental_variant_id'])) {
                                $valid_ids_for_set[(int) $opt_item['rental_variant_id']] = true;
                            }
                            if (!empty($opt_item['rental_product_id'])) {
                                $valid_ids_for_set[(int) $opt_item['rental_product_id']] = true;
                            }
                        }
                    }

                    // Add-ons
                    if (!empty($set_item['addons']) && is_array($set_item['addons'])) {
                        foreach ($set_item['addons'] as $addon) {
                            if (!is_array($addon)) {
                                continue;
                            }

                            if (!empty($addon['rental_variant_id'])) {
                                $valid_ids_for_set[(int) $addon['rental_variant_id']] = true;
                            }
                            if (!empty($addon['rental_product_id'])) {
                                $valid_ids_for_set[(int) $addon['rental_product_id']] = true;
                            }
                            // if (!empty($addon['rental_inv_id'])) {
                            //     $valid_ids_for_set[(int) $addon['rental_inv_id']] = true;
                            // }

                            // Add-on optional variants
                            if (!empty($addon['variants_optional']) && is_array($addon['variants_optional'])) {
                                foreach ($addon['variants_optional'] as $addon_variant) {
                                    if (!is_array($addon_variant)) {
                                        continue;
                                    }

                                    if (!empty($addon_variant['rental_variant_id'])) {
                                        $valid_ids_for_set[(int) $addon_variant['rental_variant_id']] = true;
                                    }
                                    if (!empty($addon_variant['rental_product_id'])) {
                                        $valid_ids_for_set[(int) $addon_variant['rental_product_id']] = true;
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }

        if (!empty($valid_ids_for_set)) {
            // Make sure that sel_variant_id for this set still exists in its items
            foreach ($sel_ids_for_set as $sel_id) {
                if (empty($valid_ids_for_set[$sel_id])) {
                    $missing_sel_ids[$sel_id] = true;
                }
            }
        }
    }

    // If at least one sel_variant_id is no longer valid, do NOT auto-recover this order
    if (!empty($missing_sel_ids)) {
        $missing_str = implode(', ', array_map('intval', array_keys($missing_sel_ids)));

        return [
            'allowed'     => false,
            'inventories' => $inventories, // keeping original for debugging / logging
            'message'     => sprintf(
                'Auto recovery aborted: sel_variant_id value(s) %s are no longer present in the order\'s Set mappings.',
                $missing_str
            ),
        ];
    }

    return [
        'allowed'     => true,
        'inventories' => $rebuilt,
        'message'     => '',
    ];
}




// function ajax which is assigned to try again to upload images that failed to load
function wp_ajax_rental_upload_images_try_again() {
    global $wpdb, $rental_tables;
    $rental_error_log = $wpdb->prefix . $rental_tables["error_log"];

    if (isset($_GET['id']) && ($data = $wpdb->get_var("SELECT data FROM $rental_error_log WHERE `id` = $_GET[id]"))) {
        $data = unserialize($data);
        if (isset($data['start']) && isset($data['limit'])) {
            try {

                rental_upload_images($data['start'], $data['limit']);

                $updated = [
                    'status' => 200,
                    'message' => "Import of images $data[start]-" . ($data['start'] + $data['limit']) . " is completed!",
                    'data' => null
                ];
                $wpdb->update($rental_error_log, $updated, ['id' => $_GET['id']]);
                wp_send_json($updated, 200);

            } catch (RentalException $e) {
                $updated = [
                    'status' => $e->getStatusCode(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'message' => $e->getMessage(),
                ];
                $wpdb->update($rental_error_log, $updated, ['id' => $_GET['id']]);
                wp_send_json($updated, $e->getStatusCode());
            }
        }
    }
    wp_send_json(['message' => 'chka'], 500);

    wp_die();
}

$GlobalFileHandle = null;
// function for send curl
/**
 * Send request to the Rentopian API.
 *
 * @param string   $url                 Path (e.g. "orders/add")
 * @param string   $api_key             Bearer token
 * @param bool     $decode              Whether to json_decode the response
 * @param mixed    $body                POST body (null = GET)
 * @param mixed    $write_to_file       Stream response to file when set
 * @param bool     $check_url_existance Verify endpoint exists before calling
 * @param int|null $timeout             Max seconds for the whole request; null = no limit.
 *                                      Pass a value for calls inside user-facing requests
 *                                      (e.g. checkout) so a slow API cannot hang the page.
 * @return mixed Decoded or raw response, false on init failure
 * @throws RentalException on non-200 status (including timeout)
 */
function rental_curl($url, $api_key, $decode = true, $body = null, $write_to_file = null, $check_url_existance = false, $timeout = null) {

    if ($check_url_existance) {
        if (!url_exists(rental_url($url))) {
            return false; // Return false instead of stopping execution
        }
    }


    if ($write_to_file) {
        global $GlobalFileHandle;
        $GlobalFileHandle = fopen('curl_response.txt', 'w+');
    }


    if ($curl = curl_init()) {
        $url = rental_url($url);
        curl_setopt($curl, CURLOPT_URL, $url);
        if ($write_to_file) {
            curl_setopt($curl, CURLOPT_FILE, $GlobalFileHandle);
        }


        if ($body) {
            curl_setopt($curl, CURLOPT_CUSTOMREQUEST, "POST");
            curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
        }
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
        // Connecting must never block indefinitely; default cURL waits forever
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 30);
        if ($timeout !== null && (int) $timeout > 0) {
            curl_setopt($curl, CURLOPT_TIMEOUT, (int) $timeout);
        }
        $domain = get_option('siteurl');
        curl_setopt($curl, CURLOPT_HTTPHEADER, [
            "Accept: application/json",
            "Authorization: bearer $api_key",
            //            "Domain: http://$_SERVER[HTTP_HOST]"
            "Domain: $domain"
        ]);

        if ($write_to_file) {
            curl_setopt($curl, CURLOPT_WRITEFUNCTION, 'curlWriteFile');
        }
        

        $out = curl_exec($curl);
        $statusCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $curlError = curl_error($curl);
        curl_close($curl);

        if ($write_to_file) {
            fclose($GlobalFileHandle);
        }

        if ($decode) {
            $out = json_decode($out);
        }

        if ($statusCode != 200) {
            if ( !$decode) {
                $out = json_decode($out);
            }
            $message = isset($out->message)? (is_string($out->message)? $out->message: json_encode($out->message)): "status: $statusCode";
            if ($curlError) {
                $message .= ' (curl: ' . $curlError . ')';
            }
            throw new RentalException($message, RentalException::TYPE_SYNC_GLOBAL, $statusCode);
        }

        
        return $out;
    }

    return false;
}

/**
 * Send POST request with multipart/form-data (for file uploads).
 * Do not set Content-Type; cURL sets multipart with boundary when CURLOPT_POSTFIELDS has CURLFile.
 *
 * @param string     $url     Path (e.g. "orders/add")
 * @param string     $api_key Bearer token
 * @param array      $body    Form fields and CURLFile entries
 * @param bool       $decode  Whether to json_decode the response
 * @return object|string Decoded or raw response
 * @throws RentalException on non-200 status
 */
function rental_curl_multipart($url, $api_key, $body, $decode = true) {
    if ($curl = curl_init()) {
        $url = rental_url($url);
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
        // Extended timeout for multipart file uploads (photos may be large)
        curl_setopt($curl, CURLOPT_TIMEOUT, 120);
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 30);
        $domain = get_option('siteurl');
        curl_setopt($curl, CURLOPT_HTTPHEADER, [
            'Accept: application/json',
            'Authorization: bearer ' . $api_key,
            'Domain: ' . $domain,
            // Suppress Expect: 100-continue to prevent premature 413 from nginx/proxies
            'Expect: '
        ]);

        $out = curl_exec($curl);
        $statusCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $curlError = curl_error($curl);
        curl_close($curl);

        if ($decode) {
            $out = json_decode($out);
        }

        if ($statusCode != 200) {
            if (!$decode) {
                $out = json_decode($out);
            }
            $message = isset($out->message) ? (is_string($out->message) ? $out->message : json_encode($out->message)) : "status: $statusCode";
            if ($curlError) {
                $message .= ' (curl: ' . $curlError . ')';
            }
            throw new RentalException($message, RentalException::TYPE_SYNC_GLOBAL, $statusCode);
        }

        return $out;
    }

    return false;
}

function curlWriteFile($cp, $data) {
    global $GlobalFileHandle;
    $len = fwrite($GlobalFileHandle, $data);
    return $len;
}

/**
 * Compress a checkout photo before multipart upload to the Rentopian API.
 *
 * - Resizes images wider than 1920px (proportional).
 * - Converts PNG to JPEG quality 75 to reduce payload.
 * - Skips files already under 500 KB.
 * - Returns the compressed temp file path, or the original path on failure.
 *
 * @param string $path      Absolute path to the original image file.
 * @param string $mime_type Original MIME type (e.g. 'image/png', 'image/jpeg').
 * @return string Path to the (possibly compressed) file. Caller is responsible for cleanup.
 */
function rental_compress_photo_for_upload($path, $mime_type = 'image/jpeg') {
    if (!file_exists($path) || !is_readable($path)) {
        return $path;
    }
    // Skip small files (< 500 KB)
    if (filesize($path) < 512000) {
        return $path;
    }
    $image_info = @getimagesize($path);
    if (!$image_info) {
        return $path;
    }
    list($orig_w, $orig_h, $type) = $image_info;

    // Load image resource
    switch ($type) {
        case IMAGETYPE_JPEG:
            $img = @imagecreatefromjpeg($path);
            break;
        case IMAGETYPE_PNG:
            $img = @imagecreatefrompng($path);
            break;
        default:
            return $path; // unsupported type
    }
    if (!$img) {
        return $path;
    }

    // Resize if wider than 1920px
    $max_width = 1920;
    if ($orig_w > $max_width) {
        $new_w = $max_width;
        $new_h = (int) round($orig_h * ($max_width / $orig_w));
        $resized = imagecreatetruecolor($new_w, $new_h);
        imagecopyresampled($resized, $img, 0, 0, 0, 0, $new_w, $new_h, $orig_w, $orig_h);
        imagedestroy($img);
        $img = $resized;
    }

    // Create temp directory
    $upload_dir = wp_upload_dir();
    $temp_dir = $upload_dir['basedir'] . '/rental-checkout-photos/temp';
    if (!is_dir($temp_dir)) {
        wp_mkdir_p($temp_dir);
    }

    // Always output as JPEG for compression
    $temp_path = $temp_dir . '/' . uniqid('compressed_', true) . '.jpg';
    $quality = 75;
    $result = @imagejpeg($img, $temp_path, $quality);
    imagedestroy($img);

    if (!$result || !file_exists($temp_path)) {
        return $path;
    }

    // Only use compressed version if it's actually smaller
    if (filesize($temp_path) >= filesize($path)) {
        @unlink($temp_path);
        return $path;
    }

    return $temp_path;
}

// Function to check if a URL exists
function url_exists($url) {
    $curl = curl_init($url);
    curl_setopt($curl, CURLOPT_NOBODY, true);
    curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($curl, CURLOPT_TIMEOUT, 5); // Set a timeout to prevent long delays
    curl_exec($curl);
    $statusCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);

    return $statusCode >= 200 && $statusCode < 400; // Return true only if the URL responds with a valid status code
}

// function for create rentopian api url
function rental_url($url) {
    global $rental_api_url;

    return $rental_api_url . '/' . $url;
}

// function for removing all products
function rental_empty_products() {
    global $wpdb, $rental_tables;
    $rental_product_relations = $wpdb->prefix . $rental_tables["product_relations"];
    $rental_variant_relations = $wpdb->prefix . $rental_tables["variant_relations"];
    $rental_set_relations = $wpdb->prefix . $rental_tables["set_relations"];
    $rental_category_relations = $wpdb->prefix . $rental_tables["category_relations"];
    $rental_tag_relations = $wpdb->prefix . $rental_tables["tag_relations"];
    $rental_sets_tag_relations = $wpdb->prefix . $rental_tables["sets_tag_relations"];
    $rental_attribute_relations = $wpdb->prefix . $rental_tables["attribute_relations"];
    $rental_brand_relations = $wpdb->prefix . $rental_tables["brand_relations"];
    $rental_image_relations = $wpdb->prefix . $rental_tables["image_relations"];

    $wpdb->query("DELETE FROM $wpdb->terms WHERE term_id IN (SELECT term_id FROM $wpdb->term_taxonomy WHERE taxonomy LIKE 'pa_%' OR taxonomy IN ('product_cat', 'product_tag'))");
    $wpdb->query("DELETE FROM $wpdb->term_taxonomy WHERE taxonomy LIKE 'pa_%' OR taxonomy IN ('product_cat', 'product_tag', 'product_brand')");
    $wpdb->query("DELETE FROM $wpdb->termmeta WHERE term_id NOT IN (SELECT term_id FROM $wpdb->terms)");
    $wpdb->query("DELETE FROM $wpdb->term_relationships WHERE object_id IN (SELECT ID FROM $wpdb->posts WHERE post_type IN ('product','product_variation')) OR
        term_taxonomy_id NOT IN (SELECT term_taxonomy_id FROM $wpdb->term_taxonomy) OR term_taxonomy_id IN (SELECT term_taxonomy_id FROM $wpdb->term_taxonomy WHERE taxonomy IN ('product_type', 'product_visibility'))");
    $wpdb->query("DELETE FROM $wpdb->postmeta WHERE post_id IN (SELECT ID FROM $wpdb->posts WHERE post_type IN ('product','product_variation'))");
    $wpdb->query("DELETE FROM $wpdb->posts WHERE post_type IN ('product','product_variation')");
    //    $wpdb->query("DELETE pm FROM $wpdb->postmeta AS pm LEFT JOIN $wpdb->posts AS wp ON wp.ID = pm.post_id WHERE wp.ID IS NULL");
    $wpdb->query("TRUNCATE TABLE " . $wpdb->prefix . "woocommerce_attribute_taxonomies");
    if (isset($wpdb->wc_product_meta_lookup)) {
        $wpdb->query("TRUNCATE TABLE $wpdb->wc_product_meta_lookup");
    }
    if (defined('ZOO_CW_VERSION')) {
        $wpdb->query("TRUNCATE TABLE " . $wpdb->prefix . "zoo_cw_product_attribute_swatch_type");
    }
    $wpdb->query("TRUNCATE TABLE $rental_product_relations");
    $wpdb->query("TRUNCATE TABLE $rental_variant_relations");
    $wpdb->query("TRUNCATE TABLE $rental_set_relations");
    $wpdb->query("TRUNCATE TABLE $rental_category_relations");
    $wpdb->query("TRUNCATE TABLE $rental_tag_relations");
    $wpdb->query("TRUNCATE TABLE $rental_sets_tag_relations");
    $wpdb->query("TRUNCATE TABLE $rental_attribute_relations");
    $wpdb->query("TRUNCATE TABLE $rental_brand_relations");

    // The attribute value terms are gone, so their term-id map goes with them.
    // Group membership is keyed by Rentopian id and is left alone: the values
    // are about to be recreated, and a full pull replaces it wholesale.
    if (class_exists('Rental_Attribute_Groups')) {
        Rental_Attribute_Groups::ensure_tables();
        Rental_Attribute_Groups::empty_value_relations();
    }

    if (get_option('rental_file_sync_method', 1) == 1) {
        // base 64 method
        $wpdb->query("DELETE FROM $rental_image_relations WHERE id NOT IN (SELECT ID FROM $wpdb->posts WHERE post_type = 'attachment')");
    } else {
        // nginx method
        $wpdb->query("TRUNCATE TABLE $rental_image_relations");
    }
    
    $wpdb->query("DELETE FROM $wpdb->options WHERE
        option_name IN ('wc_products_onsale', 'wc_featured_products', 'wc_outofstock_count', 'wc_low_stock_count',
         '_transient_product-transient-version', '_transient_wc_attribute_taxonomies', 'product_cat_children') OR
        option_name LIKE '_transient_timeout_wc_product_children_%' OR
        option_name LIKE '_transient_wc_product_children_%' OR
        option_name LIKE '_transient_timeout_wc_var_prices_%' OR
        option_name LIKE '_transient_wc_var_prices_%' OR
        option_name LIKE '_transient_timeout_wc_child_has_weight_%' OR
        option_name LIKE '_transient_wc_child_has_weight_%' OR
        option_name LIKE '_transient_timeout_wc_child_has_dimensions_%' OR
        option_name LIKE '_transient_wc_child_has_dimensions_%' OR
        option_name LIKE '_transient_timeout_wc_related_%' OR
        option_name LIKE '_transient_wc_related_%'");

    //    update_option('_transient_wc_attribute_taxonomies', []);
    //    update_option('product_cat_children', '');

    if ($wpdb->last_error !== '') {
        throw new RentalException("SQL_ERROR: $wpdb->last_error (SQL: $wpdb->last_query)", RentalException::TYPE_SYNC_GLOBAL);
    }

    return true;
}




function rental_empty_yoast() {
    global $wpdb;

    update_option('rental_sync_yoast_seo_plugin_finished', 0);

    $yoast_indexable = $wpdb->prefix . 'yoast_indexable';
    $yoast_indexable_hierarchy = $wpdb->prefix . 'yoast_indexable_hierarchy';
    $yoast_migrations = $wpdb->prefix . 'yoast_migrations';
    $yoast_primary_term = $wpdb->prefix . 'yoast_primary_term';
    $yoast_seo_links = $wpdb->prefix . 'yoast_seo_links';

    $wpdb->query("TRUNCATE TABLE $yoast_indexable");
    $wpdb->query("TRUNCATE TABLE $yoast_indexable_hierarchy");
    $wpdb->query("TRUNCATE TABLE $yoast_migrations");
    $wpdb->query("TRUNCATE TABLE $yoast_primary_term");
    $wpdb->query("TRUNCATE TABLE $yoast_seo_links");

    if ($wpdb->last_error !== '') {
        throw new RentalException("SQL_ERROR: $wpdb->last_error (SQL: $wpdb->last_query)", RentalException::TYPE_SYNC_GLOBAL);
    }

    return true;
}

// function for removing all shipping zones
function rental_empty_shipping_zones() {
    global $wpdb, $rental_tables;
    $woocommerce_shipping_zones = $wpdb->prefix . 'woocommerce_shipping_zones';
    $woocommerce_shipping_zone_locations = $wpdb->prefix . 'woocommerce_shipping_zone_locations';
    $woocommerce_shipping_zone_methods = $wpdb->prefix . 'woocommerce_shipping_zone_methods';
    $rental_shipping_zone_relations = $wpdb->prefix . $rental_tables["shipping_zone_relations"];
    
    $wpdb->query("TRUNCATE TABLE $woocommerce_shipping_zones");
    $wpdb->query("TRUNCATE TABLE $woocommerce_shipping_zone_locations");
    $wpdb->query("TRUNCATE TABLE $woocommerce_shipping_zone_methods");
    $wpdb->query("TRUNCATE TABLE $rental_shipping_zone_relations");

    $wpdb->query("DELETE FROM $wpdb->options WHERE option_name LIKE 'woocommerce_reduced_rate_%_settings'");

    if ($wpdb->last_error !== '') {
        throw new RentalException("SQL_ERROR: $wpdb->last_error (SQL: $wpdb->last_query)", RentalException::TYPE_SYNC_GLOBAL);
    }

    return true;
}

function rental_empty_inventory_blocks() {
    global $wpdb, $rental_tables;
    $rental_inventory_blocks = $wpdb->prefix . $rental_tables["inventory_blocks"];
    $rental_inventory_block_relations = $wpdb->prefix . $rental_tables["inventory_block_relations"];
   
    if ($wpdb->get_var("show tables like '$rental_inventory_blocks'") == $rental_inventory_blocks) {
        // delete rental_inventory_blocks data
        $wpdb->query("TRUNCATE TABLE $rental_inventory_blocks");
        $wpdb->query("TRUNCATE TABLE $rental_inventory_block_relations");
    }

    if ($wpdb->last_error !== '') {
        throw new RentalException("SQL_ERROR: $wpdb->last_error (SQL: $wpdb->last_query)", RentalException::TYPE_SYNC_GLOBAL);
    }

    return true;
}

function rental_add_inventory_blocks() {
    global $wpdb, $rental_tables;
    $rental_inventory_blocks = $wpdb->prefix . $rental_tables["inventory_blocks"];
    $rental_inventory_block_relations = $wpdb->prefix . $rental_tables["inventory_block_relations"];
    $rental_product_relations = $wpdb->prefix . $rental_tables["product_relations"];
    $rental_variant_relations = $wpdb->prefix . $rental_tables["variant_relations"];

    if ($wpdb->get_var("show tables like '$rental_inventory_blocks'") == $rental_inventory_blocks) {
        $rental_inventory_blocks_sql = '';
        $rental_inventory_block_relations_sql = [];
        $inventory_blocks = json_decode(rental_curl('inventories/blocked', get_option('rental_api_key'), false), true);
        
        if (!empty($inventory_blocks)) {
            // all inventories blocked within date/time rages
            if (!empty($inventory_blocks["all"])) {
                foreach($inventory_blocks["all"] as $key => $inv_block) {
                    $id = $inv_block['id'];
                    $division_id = $inv_block['division_id'];
                    $start_date = $inv_block['start_date'];
                    $end_date = $inv_block['end_date'];
                    $type = $inv_block['type'];

                    $rental_inventory_blocks_sql .= $key == 0 ? "($id, $division_id, $start_date, $end_date, $type)" : ",($id, $division_id, $start_date, $end_date, $type)";
                }
            }

            // specific inventories blocked within date/time rages
            if (!empty($inventory_blocks["specific"])) {
                $wp_product_ids_pack = [];
                $wp_product_variant_ids_pack = [];
                foreach($inventory_blocks["specific"] as $key => $inv_block) {
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

            // rental inventory blocks
            if($rental_inventory_blocks_sql){
                $wpdb->query("INSERT INTO `$rental_inventory_blocks` (`id`, `division_id`, `start_date`, `end_date`, `type`) VALUES " . $rental_inventory_blocks_sql);
            }
       }

        if ($wpdb->last_error !== '') {
            throw new RentalException("SQL_ERROR: $wpdb->last_error (SQL: $wpdb->last_query)", RentalException::TYPE_SYNC_GLOBAL);
        }
    }
    return true;
}

// if ( ! function_exists( 'rental_empty_set_options' ) ) {
//     function rental_empty_set_options() {
//         global $wpdb, $rental_tables;
//         $rental_set_options = $wpdb->prefix . $rental_tables["set_options"];
//         $rental_set_option_relations = $wpdb->prefix . $rental_tables["set_option_relations"];
    
//         if ($wpdb->get_var("show tables like '$rental_set_option_relations'") == $rental_set_option_relations) {
//             // delete rental_product_options data
//             $wpdb->query("TRUNCATE TABLE $rental_set_options");
//             $wpdb->query("TRUNCATE TABLE $rental_set_option_relations");
//         }

//         if ($wpdb->last_error !== '') {
//             throw new RentalException("SQL_ERROR: $wpdb->last_error (SQL: $wpdb->last_query)", RentalException::TYPE_SYNC_GLOBAL);
//         }

//         return true;
//     }
// }


// if ( !function_exists( 'rental_add_set_options' ) ) {
//     /**
//      * Sync set options from Rentopian API.
//      *
//      * Fetches all set options and their relations, then bulk inserts them
//      * into the database using prepared statements for SQL injection prevention.
//      *
//      * @since 1.0.0
//      * @since 2.13.0 Refactored to use prepared statements for security.
//      *
//      * @global wpdb   $wpdb         WordPress database object.
//      * @global array  $rental_tables Rental table names configuration.
//      *
//      * @return bool Always returns true on completion.
//      * @throws RentalException If SQL error occurs during sync.
//      */
//     function rental_add_set_options() {
//         global $wpdb, $rental_tables;
//         $rental_set_options = $wpdb->prefix . $rental_tables["set_options"];
//         $rental_set_option_relations = $wpdb->prefix . $rental_tables["set_option_relations"];
//         $rental_set_relations = $wpdb->prefix . $rental_tables["set_relations"];

//         // Prepared statement arrays for SQL injection prevention
//         $rental_set_options_placeholders = [];
//         $rental_set_options_params = [];
//         $rental_set_option_relations_placeholders = [];
//         $rental_set_option_relations_params = [];

//         if ($wpdb->get_var("show tables like '$rental_set_option_relations'") == $rental_set_option_relations) {
//             $sets_options = json_decode(rental_curl('inventories/sets/options', get_option('rental_api_key'), false), true);

//             if ($sets_options) {

//                 // Collect every division's rental set ids
//                 $sql = "SELECT id, rental_id FROM {$rental_set_relations}";
//                 $set_ids = $wpdb->get_results($sql, ARRAY_A);

//                 $attached_items_grouped = [];
//                 foreach($sets_options as $option) {

//                     $id = intval($option["id"]);
//                     // Grouping attached sets based on option id
//                     $attached_sets = isset($option["attached_sets"]) ? json_decode($option["attached_sets"]) : [];

//                     $attached_items_grouped[$id] = [
//                         'sets' => $attached_sets,
//                     ];

//                     $title = sanitize_text_field($option["title"]);
//                     $once_per_order = intval($option["once_per_order"]);
//                     $values = $option["values"];
//                     $values_encoded = wp_json_encode($values);

//                     // Build set options SQL with placeholders (security fix)
//                     $rental_set_options_placeholders[] = '(%d, %s, %d, %s)';
//                     $rental_set_options_params[] = $id;
//                     $rental_set_options_params[] = $title;
//                     $rental_set_options_params[] = $once_per_order;
//                     $rental_set_options_params[] = $values_encoded;
//                 }

//                 // Each option's attached sets relation
//                 $_option_ids = [];
//                 foreach($attached_items_grouped as $option_id => $items) {
                    
//                     $set_wp_id_collection = [];
//                     // Collecting wp set ids related to rental set ids for the current option
//                     if (!empty($items['sets'])) {
//                         foreach($set_ids as $sid) {
//                             if (in_array($sid["rental_id"], $items['sets'])) {
//                                 // Supporting all divisions
//                                 $set_wp_id_collection[$sid["rental_id"]][] = $sid["id"];
//                             }
//                         }
//                     }

//                     if (!empty($set_wp_id_collection)) {
//                         // Build relation SQL with placeholders (security fix)
//                         foreach ($set_wp_id_collection as $rental_set_id => $wp_id_list) {
//                             foreach($wp_id_list as $wp_id) {
//                                 $_option_ids[$wp_id][] = $option_id;

//                                 $rental_set_option_relations_placeholders[] = '(%d, %d, %d)';
//                                 $rental_set_option_relations_params[] = (int) $option_id;
//                                 $rental_set_option_relations_params[] = (int) $rental_set_id;
//                                 $rental_set_option_relations_params[] = (int) $wp_id;
//                             }
//                         }
//                     }
//                 }

//                 // Execute options insert with prepared statement
//                 if (!empty($rental_set_options_placeholders)) {
//                     $wpdb->query($wpdb->prepare(
//                         "INSERT INTO `$rental_set_options` (`id`, `title`, `once_per_order`, `option_values`) VALUES " . implode(',', $rental_set_options_placeholders),
//                         $rental_set_options_params
//                     ));
//                 }

//                 // Execute relations insert with prepared statement
//                 if (!empty($rental_set_option_relations_placeholders)) {
//                     $wpdb->query($wpdb->prepare(
//                         "INSERT INTO `$rental_set_option_relations` (`set_option_id`, `rental_id`, `wp_id`) VALUES " . implode(',', $rental_set_option_relations_placeholders),
//                         $rental_set_option_relations_params
//                     ));
                
//                     // Build postmeta SQL with prepared statements (security fix)
//                     $setmeta_placeholders = [];
//                     $setmeta_params = [];
//                     foreach($_option_ids as $wp_id => $option_ids) {
//                         $option_ids_rearranged = array_values(array_unique($option_ids));
//                         $option_ids_json = wp_json_encode($option_ids_rearranged);
//                         $setmeta_placeholders[] = '(%d, %s, %s)';
//                         $setmeta_params[] = (int) $wp_id;
//                         $setmeta_params[] = '_set_options';
//                         $setmeta_params[] = $option_ids_json;
//                     }
//                     if (!empty($setmeta_placeholders)) {
//                         $wpdb->query($wpdb->prepare(
//                             "INSERT INTO `{$wpdb->postmeta}` (`post_id`, `meta_key`, `meta_value`) VALUES " . implode(',', $setmeta_placeholders),
//                             $setmeta_params
//                         ));
//                     }
//                 }

//                 if ($wpdb->last_error !== '') {
//                     throw new RentalException("SQL_ERROR: $wpdb->last_error (SQL: $wpdb->last_query)", RentalException::TYPE_SYNC_GLOBAL);
//                 }
//             }
//         }
//         return true;
//     }
// }

function rental_empty_product_options() {
    global $wpdb, $rental_tables;
    $rental_product_options = $wpdb->prefix . $rental_tables["product_options"];
    $rental_product_option_relations = $wpdb->prefix . $rental_tables["product_option_relations"];
   
    if ($wpdb->get_var("show tables like '$rental_product_option_relations'") == $rental_product_option_relations) {
        // delete rental_product_options data
        $wpdb->query("TRUNCATE TABLE $rental_product_options");
        $wpdb->query("TRUNCATE TABLE $rental_product_option_relations");
    }

    if ($wpdb->last_error !== '') {
        throw new RentalException("SQL_ERROR: $wpdb->last_error (SQL: $wpdb->last_query)", RentalException::TYPE_SYNC_GLOBAL);
    }

    return true;
}

function rental_add_product_options() {
    global $wpdb, $rental_tables;
    $rental_product_options = $wpdb->prefix . $rental_tables["product_options"];
    $rental_product_option_relations = $wpdb->prefix . $rental_tables["product_option_relations"];
    $rental_product_relations = $wpdb->prefix . $rental_tables["product_relations"];
    $rental_category_relations = $wpdb->prefix . $rental_tables["category_relations"];
    $rental_product_options_sql = '';
    $rental_product_option_relations_sql = '';
    $productmeta_sql = [];

    // params arrays for prepare()
    $rental_product_options_params = [];
    $rental_product_option_relations_params = [];

    if ($wpdb->get_var("show tables like '$rental_product_option_relations'") == $rental_product_option_relations) {
        $product_options = json_decode(rental_curl('products/options', get_option('rental_api_key'), false), true);

        if (!empty($product_options)) {

            // collect every division's rental product ids
            $sql = "SELECT id, rental_id FROM {$rental_product_relations}";
            $product_ids = $wpdb->get_results($sql, ARRAY_A);
            // collect rental category ids
            $sql2 = "SELECT id, rental_id FROM {$rental_category_relations}";
            $category_ids = $wpdb->get_results($sql2, ARRAY_A);

            $attached_items_grouped = [];
            // $values_grouped = [];
            foreach($product_options as $key=>$option) {

                $id = $option["id"];
                // grouping attached products and categories based on option id
                $attached_products = isset($option["attached_products"]) ? json_decode($option["attached_products"]) : '';
                $attached_categories = isset($option["attached_cats"]) ? json_decode($option["attached_cats"]) : '';

                $attached_items_grouped[$id] = [
                    'products' => $attached_products,
                    'cats' => $attached_categories,
                ];

                $title = $option["title"];
                $once_per_order = $option["once_per_order"];
                $values = $option["values"];
                $values_encoded = json_encode($values);

                // rental_product_options sql values (use placeholders; collect params) to avoid single / double quote value query errors
                $rental_product_options_sql .= empty($rental_product_options_sql) ? '(%d,%s,%d,%s)' : ',(%d,%s,%d,%s)';
                $rental_product_options_params[] = (int) $id;
                $rental_product_options_params[] = (string) $title;
                $rental_product_options_params[] = (int) $once_per_order;
                $rental_product_options_params[] = $values_encoded;
            }

            // each option's attached products and categories relation
            $_option_ids = [];
            foreach($attached_items_grouped as $option_id=>$items) {
                
                $cats_wp_id_collection = [];
                $cats_rental_wp_id_collection = [];
                $product_wp_id_collection = [];
                // collecting wp product ids related to rental product ids for the current option
                if (!empty($items['products'])) {
                    foreach($product_ids as $pid) {
                        if (in_array($pid["rental_id"], $items['products'])) {
                            // suporting all divisions
                            $product_wp_id_collection[$pid["rental_id"]][] = $pid["id"];
                        }
                    }
                }

                $category_product_ids=[];
                // collecting wp cat ids related to rental cat ids for the current option
                if (!empty($items['cats'])) {
                    // group wp products by rental category id
                    foreach($category_ids as $cid) {
                        if ( in_array($cid["rental_id"], $items['cats']) ) {
                            $cats_rental_wp_id_collection[$cid["rental_id"]] = $cid["id"];
                            $cats_wp_id_collection[] = $cid["id"];
                        }
                    }

                    if ($cats_wp_id_collection) {
                        $placeholders = array_fill(0, count($cats_wp_id_collection), '%d');
                        $placeholders_format = implode(', ', $placeholders);
                        $sql = "
                            SELECT 
                                object_id
                            FROM 
                                {$wpdb->term_relationships} terms
                            LEFT JOIN {$wpdb->posts} posts ON posts.id = terms.object_id
                            WHERE
                                term_taxonomy_id IN ({$placeholders_format})
                                AND posts.post_type = 'product'
                                AND posts.post_status = 'publish'
                        ";
                        $product_ids_by_category_ids = $wpdb->get_results($wpdb->prepare($sql, $cats_wp_id_collection), ARRAY_A);
        
                        if ($product_ids_by_category_ids) {
                            foreach($product_ids_by_category_ids as $pid) {
                                $category_product_ids[] = $pid["object_id"];
                            }
                        }
                    }
                }

                if (!empty($product_wp_id_collection)) {
                    // sql values of the option products (type 1 = product)
                    foreach ($product_wp_id_collection as $rental_product_id => $wp_id_list) {
                        foreach($wp_id_list as $wp_id) {
                            $_option_ids[$wp_id][] = $option_id;

                            // product option relation sql (use placeholders; collect params)
                            $rental_product_option_relations_sql .= empty($rental_product_option_relations_sql) ? '(%d,%d,%d,%d)' : ',(%d,%d,%d,%d)';
                            $rental_product_option_relations_params[] = (int) $option_id;
                            $rental_product_option_relations_params[] = (int) $rental_product_id;
                            $rental_product_option_relations_params[] = (int) $wp_id;
                            $rental_product_option_relations_params[] = 1; // type 1 = product
                        }
                    }
                }

                if (!empty($category_product_ids)) {
                    foreach ($category_product_ids as $wp_id) {
                        $_option_ids[$wp_id][] = $option_id;
                    }
                }

                if (!empty($cats_rental_wp_id_collection)) {
                    // sql values of the option categories (type 2 = category)
                    foreach ($cats_rental_wp_id_collection as $rental_cat_id => $wp_cat_id) {
                        $rental_product_option_relations_sql .= empty($rental_product_option_relations_sql) ? '(%d,%d,%d,%d)' : ',(%d,%d,%d,%d)';
                        $rental_product_option_relations_params[] = (int) $option_id;
                        $rental_product_option_relations_params[] = (int) $rental_cat_id;
                        $rental_product_option_relations_params[] = (int) $wp_cat_id;
                        $rental_product_option_relations_params[] = 2; // type 2 = category
                    }
                }
            }

            // execute options insert with prepare()
            if (!empty($rental_product_options_sql)) {
                $wpdb->query($wpdb->prepare(
                    "INSERT INTO `$rental_product_options` (`id`, `title`, `once_per_order`, `option_values`) VALUES " . $rental_product_options_sql,
                    $rental_product_options_params
                ));
            }

            if (!empty($rental_product_option_relations_sql)) {
                // execute relations insert with prepare()
                $wpdb->query($wpdb->prepare(
                    "INSERT INTO `$rental_product_option_relations` (`po_id`, `rental_id`, `wp_id`, `type`) VALUES " . $rental_product_option_relations_sql,
                    $rental_product_option_relations_params
                ));
            
                // Build postmeta SQL with prepared statements (security fix)
                $productmeta_placeholders = [];
                $productmeta_params = [];
                foreach($_option_ids as $wp_id => $option_ids) {
                    $option_ids_rearranged = array_values(array_unique($option_ids));
                    $option_ids_json = wp_json_encode($option_ids_rearranged);
                    $productmeta_placeholders[] = '(%d, %s, %s)';
                    $productmeta_params[] = (int) $wp_id;
                    $productmeta_params[] = '_product_options';
                    $productmeta_params[] = $option_ids_json;
                }
                if (!empty($productmeta_placeholders)) {
                    $wpdb->query($wpdb->prepare(
                        "INSERT INTO `{$wpdb->postmeta}` (`post_id`, `meta_key`, `meta_value`) VALUES " . implode(',', $productmeta_placeholders),
                        $productmeta_params
                    ));
                }
            }

            if ($wpdb->last_error !== '') {
                throw new RentalException("SQL_ERROR: $wpdb->last_error (SQL: $wpdb->last_query)", RentalException::TYPE_SYNC_GLOBAL);
            }
        }
    }
    return true;
}


function rental_set_no_customer_pickup_items($shipping_settings) {

    update_option('no_customer_pickup_products_list', []);
    
    if ($shipping_settings && isset($shipping_settings->no_customer_pickup)) {

        global $wpdb, $rental_tables;
        $rental_product_relations = $wpdb->prefix . $rental_tables["product_relations"];
        $rental_category_relations = $wpdb->prefix . $rental_tables["category_relations"];

        $rental_no_customer_pickup_product_ids = $shipping_settings->no_customer_pickup_products;
        $rental_no_customer_pickup_category_ids = $shipping_settings->no_customer_pickup_categories;

        // collect every division's rental product ids
        $sql = "SELECT id, rental_id FROM {$rental_product_relations}";
        $product_ids = $wpdb->get_results($sql, ARRAY_A);
        // collect rental category ids
        $sql2 = "SELECT id, rental_id FROM {$rental_category_relations}";
        $category_ids = $wpdb->get_results($sql2, ARRAY_A);


        if (
            $shipping_settings->no_customer_pickup == 1
            && (
                $rental_no_customer_pickup_product_ids 
                || $rental_no_customer_pickup_category_ids
            )
        ) {

            $cats_wp_id_collection = [];
            $cats_rental_id_mapper_collection = [];
            $product_wp_id_collection = [];
            
            if ($rental_no_customer_pickup_product_ids) {
                // collecting wp product ids related to rental product ids for the current option
                foreach($product_ids as $pid) {
                    if (in_array($pid["rental_id"], $rental_no_customer_pickup_product_ids)) {
                        // suporting all divisions
                        $product_wp_id_collection[$pid["rental_id"]][] = $pid["id"];
                    }
                }
            }


            $category_product_ids = [];
            
            if ($rental_no_customer_pickup_category_ids) {

                // collecting wp cat ids related to rental cat ids for the current option
                // group wp products by rental category id
                foreach($category_ids as $cid) {
                    if ( in_array($cid["rental_id"], $rental_no_customer_pickup_category_ids) ) {
                        
                        $cats_rental_id_mapper_collection[$cid["id"]] = $cid["rental_id"];

                        $cats_wp_id_collection[] = $cid["id"];
                    }
                }

                if ($cats_wp_id_collection) {
                    $placeholders = array_fill(0, count($cats_wp_id_collection), '%d');
                    $placeholders_format = implode(', ', $placeholders);
                    $sql = "
                        SELECT 
                            object_id
                        FROM 
                            {$wpdb->term_relationships} terms
                        LEFT JOIN {$wpdb->posts} posts ON posts.id = terms.object_id
                        WHERE
                            term_taxonomy_id IN ({$placeholders_format})
                            AND posts.post_type = 'product'
                            AND posts.post_status = 'publish'
                    ";
                    $product_ids_by_category_ids = $wpdb->get_results($wpdb->prepare($sql, $cats_wp_id_collection), ARRAY_A);

                    if ($product_ids_by_category_ids) {
                        foreach($product_ids_by_category_ids as $pid) {
                            $category_product_ids[] = $pid["object_id"];
                        }
                    }
                }
            }

            $wp_no_customer_pickup_products = [];
            if ($product_wp_id_collection) {
                foreach ($product_wp_id_collection as $rental_product_id => $wp_id_list) {
                    foreach($wp_id_list as $wp_id) {

                        $wp_no_customer_pickup_products[] = $wp_id;
                    }
                }
            }

            if ($category_product_ids) {
                foreach ($category_product_ids as $wp_id) {

                    $wp_no_customer_pickup_products[] = $wp_id;
                }
            }
                
            
            if ($wp_no_customer_pickup_products) {

                update_option('no_customer_pickup_products_list', $wp_no_customer_pickup_products);
            }
            
        } 

        // exclude the rest of the products
        if ($shipping_settings->no_customer_pickup == 0 && !$rental_no_customer_pickup_product_ids && !$rental_no_customer_pickup_category_ids) {
            update_option('no_customer_pickup_products_list', []);
        }
        
    }
}

function rental_empty_price_multipliers() {
    global $wpdb, $rental_tables;
    $rental_price_multipliers = $wpdb->prefix . $rental_tables["price_multipliers"];
   
    // delete rental_price_multipliers data
    $wpdb->query("TRUNCATE TABLE $rental_price_multipliers");

    // delete _price_multiplier_id (from wp_postmeta)
    $delete_query = "
        DELETE 
            pm
        FROM
            $wpdb->postmeta pm
        WHERE
            pm.meta_key = '_price_multiplier_id'
    ";
    $wpdb->query($delete_query);

    if ($wpdb->last_error !== '') {
        throw new RentalException("SQL_ERROR: $wpdb->last_error (SQL: $wpdb->last_query)", RentalException::TYPE_SYNC_GLOBAL);
    }

    return true;
}

function rental_add_price_multipliers() {
    global $wpdb, $rental_tables;
    $rental_price_multipliers = $wpdb->prefix . $rental_tables["price_multipliers"];
    $rental_price_multipliers_sql = '';

    if ($wpdb->get_var("show tables like '$rental_price_multipliers'") == $rental_price_multipliers) {
        $price_multipliers = json_decode(rental_curl('price_multipliers', get_option('rental_api_key'), false), true);
        if (!empty($price_multipliers)) {
    
            foreach($price_multipliers as $key=>$price_multiplier) {
    
                $id = $price_multiplier["id"];
                $title = $price_multiplier["title"];
                $slug = $price_multiplier["slug"];
                $is_monthly = isset($price_multiplier["is_monthly"]) ? $price_multiplier["is_monthly"] : 0;
                $is_repeat = isset($price_multiplier["is_repeat"]) ? $price_multiplier["is_repeat"] : 0;
                // $items = addslashes(serialize($price_multiplier["items"]));
                $items = serialize($price_multiplier["items"]);
                // rental price_multipliers sql values (insert each row into rental_price_multipliers table)
                if ($key == 0) {
                    $rental_price_multipliers_sql .= "($id, '$title', '$slug', '$is_monthly', '$is_repeat', '$items')";
                    continue;
                }
                $rental_price_multipliers_sql .= ",($id, '$title', '$slug', '$is_monthly', '$is_repeat', '$items')";
            }
    
            // rental price multipliers sql (insert into rental_price_multipliers table)
            $wpdb->query("INSERT INTO `$rental_price_multipliers` (`id`, `title`, `slug`, `is_monthly`, `is_repeat`, `items`) VALUES " . $rental_price_multipliers_sql);
        
            if ($wpdb->last_error !== '') {
                throw new RentalException("SQL_ERROR: $wpdb->last_error (SQL: $wpdb->last_query)", RentalException::TYPE_SYNC_GLOBAL);
            }
        }
    }
    return true;
}

// function for removing all coupons/rental_coupon_relations
function rental_empty_coupons() {
    global $wpdb, $rental_tables;
    $rental_coupon_relations = $wpdb->prefix . $rental_tables["coupon_relations"];
   
    // delete coupon rental relation
    $wpdb->query("TRUNCATE TABLE $rental_coupon_relations");

    // delete coupon (from wp_posts)
    $delete_query = "
        DELETE 
            p, pm
        FROM
            $wpdb->posts p
        LEFT JOIN $wpdb->postmeta pm ON pm.post_id = p.ID
        WHERE
            p.post_type = 'shop_coupon'
    ";
    $wpdb->query($delete_query);

    if ($wpdb->last_error !== '') {
        throw new RentalException("SQL_ERROR: $wpdb->last_error (SQL: $wpdb->last_query)", RentalException::TYPE_SYNC_GLOBAL);
    }

    return true;
}

function rental_add_coupons($last_insert_id, $exclude_product_ids = '') {
    global $wpdb, $rental_tables;
    $rental_coupon_relations = $wpdb->prefix . $rental_tables["coupon_relations"];
    $api_key = get_option('rental_api_key');
    $coupons_wp_posts_sql = [];
    $coupons_wp_postmeta_sql = [];
    $rental_coupon_relations_sql = [];

    $domain = get_option('siteurl');
    $date = date('Y-m-d H:i:s');
    $gmdate = gmdate('Y-m-d H:i:s');

    // add WP tables coupons data
    $rental_coupons = rental_curl('coupons', $api_key);

    if (!empty($rental_coupons)) {

        foreach($rental_coupons as $coupon) {
            $last_insert_id++;
    
            $slug = addslashes($coupon->coupon_id);
            $description = isset($coupon->description) ? esc_sql($coupon->description) : '';
    
            $post_status = $coupon->status == 1 ? 'publish' : 'trash';
            // rental coupons sql values (insert into wp_posts)
            $coupons_wp_posts_sql[$last_insert_id] = "($last_insert_id, 1, '$date', '$gmdate', '" . addslashes($coupon->coupon_id) . "',
            ' ', '$description', '$post_status', 'closed', 'closed',
            '$slug', '', '', '$date', '$gmdate', '', 0, '$domain/?post_type=shop_coupon&p=$last_insert_id', 'shop_coupon')";
    
            
            $discount_type = ($coupon->unit == 1) ? 'percent' : (($coupon->unit == 2) ? 'fixed_cart' : 'percent');
            $coupon_amount = $coupon->target;
            $usage_limit = !empty($coupon->unlimited_qty) ? '99999' : $coupon->quantity;
            $usage_limit_per_user = (!empty($coupon->coupon_use) && $coupon->coupon_use != 1) ? '99999' : 1;
            $date_expires = !empty($coupon->no_expiry) ? strtotime(date('Y-m-d', strtotime('+10 years'))) : $coupon->end_date;
    
            // rental coupons sql values (insert into wp_postmeta)
            $coupons_wp_postmeta_sql[$last_insert_id] = "($last_insert_id, 'discount_type', '$discount_type' ),
                ($last_insert_id, 'coupon_amount', '$coupon_amount'),
                ($last_insert_id, 'usage_limit', '$usage_limit'),
                ($last_insert_id, 'usage_limit_per_user', '$usage_limit_per_user'),
                ($last_insert_id, 'limit_usage_to_x_items', 0),
                ($last_insert_id, 'date_expires', '$date_expires'),
                ($last_insert_id, 'individual_use', 'yes'),
                ($last_insert_id, 'usage_count', 0),
                ($last_insert_id, 'free_shipping', 'no'),
                ($last_insert_id, 'exclude_sale_items', 'no')";

                if (!empty($exclude_product_ids)) {
                    $coupons_wp_postmeta_sql[$last_insert_id] .= ",($last_insert_id, 'exclude_product_ids', '$exclude_product_ids')";  
                }
    
                 
            $divisions = '';
            if (!empty($coupon->divisions) && !$coupon->all_divisions) {
                $divisions = $coupon->divisions;
            } else if ($coupon->all_divisions) {
                $divisions = 'all';
            }
            // rental coupon relations sql values (insert each row into rental relations table)
            $rental_coupon_relations_sql[] = "($last_insert_id, $coupon->id, '$divisions')";
        }
        
    
        // rental coupons sql (insert into wp_posts)
        rental_insert("INSERT INTO `$wpdb->posts` (`id`, `post_author`, `post_date`,
                    `post_date_gmt`, `post_title`, `post_content`, `post_excerpt`, `post_status`,
                    `comment_status`, `ping_status`, `post_name`, `to_ping`, `pinged`, `post_modified`,
                    `post_modified_gmt`, `post_content_filtered`, `post_parent`, `guid`,`post_type`) VALUES ", $coupons_wp_posts_sql);
        
        // rental coupons sql (insert into wp_postmeta)
        rental_insert("INSERT INTO `$wpdb->postmeta` (`post_id`, `meta_key`, `meta_value`) VALUES", $coupons_wp_postmeta_sql);
    
        // rental coupon relations sql (insert into rental_coupon_relations table)
        $wpdb->query("INSERT INTO `$rental_coupon_relations` (`id`, `rental_id`, `rental_coupon_divisions`) VALUES " . implode(", ", $rental_coupon_relations_sql));
    

        // enable coupon options (link : root/wp-admin/admin.php?page=wc-settings -> Enable coupons)
        update_option('woocommerce_enable_coupons', 'yes');
        update_option('woocommerce_calc_discounts_sequentially', 'no');
        
    
        if ($wpdb->last_error !== '') {
            throw new RentalException("SQL_ERROR: $wpdb->last_error (SQL: $wpdb->last_query)", RentalException::TYPE_SYNC_GLOBAL);
        }
    }

    return $last_insert_id;
}

// function for sync company settings
function rental_sync_company_settings($api_key) {
    $settings = rental_curl('settings/company', $api_key);

    //Check if overbooks are allowed and save right away. Then compare before any availability checks.
    //If allowed, do not check for availability.
    update_option('rental_allow_overbook', $settings->allow_overbook);

    if (isset($settings->product_type)) {
        update_option('rental_synchronized_product_type', $settings->product_type);
        if (
            $settings->product_type == 'sale'
            || $settings->product_type == 'hourly'
        ) {
            update_option('rental_hide_zip', 1);
            update_option('rental_event_start_time', 0);
            update_option('rental_hide_end_date', 1);
            update_option('rental_hide_time_pickers', 1);
            update_option('rental_min_start_date', '');
            update_option('rental_min_dates_range', '');
            update_option('rental_max_dates_range', '');
            update_option('rental_hide_damage_waiver', 1);
            update_option('rental_buy_damage_waiver_by_default', 0);

            // unset cookies related to daily rental
            if (isset($_COOKIE['rental_start_date'])) {
                unset($_COOKIE['rental_start_date']);
                setcookie('rental_start_date', '', time() - (31556952), "/", "", false, true);
            }

            if (isset($_COOKIE['rental_end_date'])) {
                unset($_COOKIE['rental_end_date']);
                setcookie('rental_end_date', '', time() - (31556952), "/", "", false, true);
            }

            $start_end_date_expire_time = time() + RENTOPIAN_DATE_EXPIRE_TIME;

            $encrypted_rental_zip = encrypt_data(true, get_option('rental_encryption_key'));
            $_COOKIE['rental_zip'] = $encrypted_rental_zip;
            setcookie('rental_zip', $encrypted_rental_zip, $start_end_date_expire_time, "/", "", false, false);

            // $_COOKIE['rental_zip'] = true;
            // setcookie('rental_zip', true, time() + (10800), "/", "", false, false);

        } else {
            // rental type
            if (isset($_COOKIE['rental_hourly_mode'])) {
                unset($_COOKIE['rental_hourly_mode']);
                setcookie('rental_hourly_mode', false, time() - (31556952), "/", "", false, false);
            }
        }

    } else {
        // all type
        if (isset($_COOKIE['rental_hourly_mode'])) {
            unset($_COOKIE['rental_hourly_mode']);
            setcookie('rental_hourly_mode', false, time() - (31556952), "/", "", false, false);
        }
        update_option('rental_synchronized_product_type', 'all');
    }
    update_option('rental_inactive_inv_by_quantity', !empty($settings->inactive_inv_by_quantity));
    if (isset($settings->theme_options)) {
        rental_set_theme_options($settings->theme_options);
    }

    if (isset($settings->auto_tax)) {
        // rental_tax_type : 1 = manual, 2 = auto tax  
        update_option('rental_tax_type', $settings->auto_tax == 1 ? 2 : 1);
    }

    return $settings;
}

// function for sync company divisions
function rental_sync_divisions($api_key) {
    $divisions = rental_curl('divisions', $api_key);
    update_option('rental_divisions', $divisions);

    // if (count($divisions) < 2) {
    //     $division = $divisions[0];

    //     update_option('rental_system_main_division', $division);

    // } else {

    //     foreach($divisions as $division) {

    //         if ($division->main_division == 1) {

    //             update_option('rental_system_main_division', $division);
    //         }
    //     }
    // }

}

// sync company main division address into woocommerce->settings->general section
function rental_sync_main_division_address($divisions) {
    
    if (count($divisions) < 2) {
        $division = $divisions[0];

        $address =  $division->address;
        update_option('woocommerce_store_address', $address->address);
        update_option('woocommerce_store_address_2', $address->address_2);
        update_option('woocommerce_store_city', $address->city);

        $country = empty($address->country) ? 'US' : $address->country;
        if (!empty($address->state)) {
            update_option('woocommerce_default_country', $country.':'.$address->state);
        } else {
            update_option('woocommerce_default_country', $country);
        }

        update_option('woocommerce_store_postcode', $address->zip);

    } else {
        foreach($divisions as $division) {
            if ($division->main_division == 1) {
                $address = $division->address;
                update_option('woocommerce_store_address', $address->address);
                update_option('woocommerce_store_address_2', $address->address_2);
                update_option('woocommerce_store_city', $address->city);

                $country = empty($address->country) ? 'US' : $address->country;
                if (!empty($address->state)) {
                    $country_state = $country.':'.$address->state;
                    update_option('woocommerce_default_country', $country_state);
                } else {
                    update_option('woocommerce_default_country', $country);
                }

                update_option('woocommerce_store_postcode', $address->zip);
            }
        }
    }

}

function rental_set_filter_duplicate_products_options($divisions_synced, $settings_location_based_filter) {
    $location_based_filter_division_id = 0;
    $location_based_filter = 0;
    if (count($divisions_synced) > 1) {
        $location_based_filter = $settings_location_based_filter;
        foreach($divisions_synced as $division) {
            if ($division->main_division == 1) {
                $location_based_filter_division_id = $division->id;
            }
        }
    }
    update_option('rental_location_based_duplicate_filter', $location_based_filter);
    update_option('rental_location_based_duplicate_filter_division_id', $location_based_filter_division_id);
}

// function for set theme options
function rental_set_theme_options($theme_options) {
    if ( !empty($theme_options)) {
        $theme_options_key = 'theme_' . strtolower(str_replace(" ", "_", 'eventorian')) . '_options';
        if($options = get_option($theme_options_key)) {
            $options['accent-color'] = isset($theme_options->primary_color)? $theme_options->primary_color: '';
            $options['highlight-color'] = isset($theme_options->secondary_color)? $theme_options->secondary_color: '';
            if (isset($theme_options->logo) && $theme_options->logo && isset($theme_options->logo->img_content)) {
                $upload = wp_upload_bits($theme_options->logo->filename, null, base64_decode($theme_options->logo->img_content));
                $options['logo'] = $upload['url'];
            } else {
                $options['logo'] = '';
            }
            update_option($theme_options_key, $options);
        }
    }

//    $theme = wp_get_theme('twentyfifteen');
//    $theme_options_key = 'theme_' . strtolower(str_replace(" ", "_", $theme->get('Name'))) . '_options';
}

// function for insert records
function rental_insert($query, array $records, $limit = null) {
    global $wpdb;

    if ($count = count($records)) {
        $offset = 0;
        do {
            if ($limit) {
                $data = array_slice($records, $offset, $limit);
                $data = implode(", ", $data);
            } else {
                $data = implode(", ", $records);
            }
            $wpdb->query($query . " " . $data);
            if ($wpdb->last_error !== '') {
                throw new RentalException("SQL_ERROR: $wpdb->last_error (SQL: $wpdb->last_query)", RentalException::TYPE_SYNC_GLOBAL);
            }
            $offset += $limit;
        } while ($limit && $count > $offset);
    }
}

// function for sync (adding products, variants, categories, tags)
function rental_synchronization($api_key) {
    global $wpdb, $rental_tables;
    $rental_product_relations = $wpdb->prefix . $rental_tables["product_relations"];
    $rental_variant_relations = $wpdb->prefix . $rental_tables["variant_relations"];
    $rental_set_relations = $wpdb->prefix . $rental_tables["set_relations"];
    $rental_category_relations = $wpdb->prefix . $rental_tables["category_relations"];
    $rental_tag_relations = $wpdb->prefix . $rental_tables["tag_relations"];
    $rental_sets_tag_relations = $wpdb->prefix . $rental_tables["sets_tag_relations"];
    $rental_attribute_relations = $wpdb->prefix . $rental_tables["attribute_relations"];
    $rental_brand_relations = $wpdb->prefix . $rental_tables["brand_relations"];
    // $rental_shipping_zone_relations = $wpdb->prefix . $rental_tables["shipping_zone_relations"];

    // set max_execution_time
    set_time_limit(800);

    rental_clear_cache();

    // sync company settings
    $settings = rental_sync_company_settings($api_key);
    $stock_status_despite_quantity = empty($settings->inactive_inv_by_quantity);

    set_default_settings();
    // removing filter on synchronization so the new products listing loads correctly
    update_option('rental_filter_unavailable_products', 0);

    // sync company divisions
    rental_sync_divisions($api_key);
    // $main_system_division = get_option('rental_system_main_division', 0);

    // sync company main division address
    $divisions_synced = get_option('rental_divisions');

    $settings_location_based_filter = isset($settings->location_based_filter) ? $settings->location_based_filter : 0;
    rental_set_filter_duplicate_products_options($divisions_synced, $settings_location_based_filter);
    
    if (isset($settings->api_seo_no_index_filter)) {
        update_option('rental_do_not_index_hidden_duplicate_products_for_seo', $settings->api_seo_no_index_filter);
    }
    $do_not_index_hidden_duplicate_products_for_seo = get_option('rental_do_not_index_hidden_duplicate_products_for_seo', 0);

    rental_sync_main_division_address($divisions_synced);

    // delivery/pickup option defaults to company deliver/return
    update_option("rental_pickup_delivery", "company_delivery_return");

    $referral_sources = rental_curl('referral_sources', $api_key);
    update_option('rental_referral_sources', $referral_sources);

    $event_types = rental_curl('event_types', $api_key);
    update_option('rental_event_types', $event_types);

    $payment_tips = rental_curl('payment_tips', $api_key, true, null, null, true);
    if ($payment_tips) {

        update_option('rental_payment_tips', $payment_tips);
    } else {

        ErrorHandler::registerErrorInLog("Failed to fetch payment tips: URL (payment_tips) not reachable.", "functions.php", "5443", RentalException::TYPE_SYNC_RUNTIME, null, "500");
    }


    $limit = 300;
    $start = 0;
    $products = [];
    do {
        $data = rental_curl('products', $api_key, true, [
            'start' => $start,
            'limit' => $limit,
        ]);
        $start += $limit;
        $products = array_merge($products, $data);
    } while (count($data) >= $limit);

    $start = 0;
    $product_variants = [];
    do {
        $data = rental_curl('products/variants', $api_key, true, [
            'start' => $start,
            'limit' => $limit,
        ]);
        $start += $limit;
        $product_variants = array_merge($product_variants, $data);
    } while (count($data) >= $limit);


    $sets = rental_curl('inventories/sets', $api_key);
    $sets_tags = rental_curl('inventories/sets/tags', $api_key);
    
    $attributes = rental_curl('products/attributes', $api_key);

    $attribute_values = rental_curl('products/attributes/values', $api_key);

    // Group membership is replaced wholesale further down, so it is only
    // touched when the endpoint answers. A core that does not serve it yet
    // makes rental_curl() throw, and treating that as "no groups" would wipe
    // every group on the site; false keeps the existing membership instead.
    // Fetched before the purge, so a failure costs nothing.
    $attribute_value_groups = false;
    try {
        $attribute_value_groups = rental_curl('products/attributes/value-groups', $api_key);
    } catch (Exception $e) {
        ErrorHandler::registerErrorInLog(
            "Attribute value groups not fetched: " . $e->getMessage() . " - the previous membership is kept",
            __FILE__, __LINE__,
            RentalException::TYPE_SYNC_RUNTIME
        );
    }

    $categories = rental_curl('products/categories', $api_key);

    $product_tags = rental_curl('products/tags', $api_key);

    $brands = rental_curl('products/brands', $api_key);

    $img_count = rental_curl('files/images/count', $api_key);

    $shipping_settings = '';
    if (!get_option('rental_do_not_use_rentopian_shipping')) {
        /**
         * Shipping settings
         */
        
        $shipping_settings = rental_curl('shipping/settings', $api_key);
        update_option('rental_shipping_settings', $shipping_settings);
    }
    

    // set plugin path
    rental_curl('settings/plugin_path/update', $api_key, false, [
        'plugin_path' => substr(RENTOPIAN_SYNC_PATH, strlen(ABSPATH))
    ]);


    // remove all the inventory blocks data
    rental_empty_inventory_blocks();

    // remove all the product options data
    rental_empty_product_options();

    // remove all the set options data
    rental_empty_set_options();

    // remove all the coupons/rental_coupon_relations
    rental_empty_coupons();

    // remove all the rental_price_multipliers
    rental_empty_price_multipliers();

    // removing all products
    rental_empty_products();

    if (!get_option('rental_do_not_use_rentopian_shipping')) {
        // removing all shipping zones
        rental_empty_shipping_zones();
    }

    if (defined('WPSEO_VERSION')) {
        rental_empty_yoast();
    }
    
    

    // save images count
    update_option('rental_products_img_count', $img_count);
    update_option('rental_sync_files_count', $img_count);

    $product_sql = [];
    $productmeta_sql = [];
    $variant_sql = [];
    $variantmeta_sql = [];
    $attribute_sql = [];
    $terms_sql = [];
    $termmeta_sql = [];
    $term_taxonomy_sql = [];
    $term_relation_sql = [];
    $product_relations_sql = [];
    $variant_relations_sql = [];
    $set_relations_sql = [];
    $category_relations_sql = [];
    $tag_relations_sql = [];
    $sets_tag_relations_sql = [];
    $attribute_relations_sql = [];
    $brand_relations_sql = [];
    if (isset($wpdb->wc_product_meta_lookup)) {
        $product_meta_lookup_sql = [];
    }
    $coupons_excluded_product_ids = [];

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    // lock tables
    dbDelta("LOCK TABLE $wpdb->terms WRITE;
        LOCK TABLE $wpdb->term_taxonomy WRITE;
        LOCK TABLE $wpdb->term_relationships WRITE;
        LOCK TABLE $wpdb->posts WRITE;
        LOCK TABLE $wpdb->prefix . 'woocommerce_shipping_zones' WRITE;
        LOCK TABLE $wpdb->prefix . 'woocommerce_shipping_zone_locations' WRITE;
        LOCK TABLE $wpdb->prefix . 'woocommerce_shipping_zone_methods' WRITE;");
    if ($wpdb->last_error !== '') {
        throw new RentalException("SQL_ERROR: $wpdb->last_error (SQL: $wpdb->last_query)", RentalException::TYPE_SYNC_GLOBAL);
    }

    // insert test product
    $new_product_id = wp_insert_post(['post_author' => '1', 'post_title' => "Rentopian", 'post_content' => "",
        'post_excerpt' => "", 'post_status' => 'trash', 'comment_status' => 'closed', 'post_type' => 'product',]);
    if ( !$new_product_id) {
        throw new RentalException(__('Failed to insert test product', 'rentopian-sync'), RentalException::TYPE_SYNC_GLOBAL);
    }

    $max_term_taxonomy_id = $wpdb->get_var("SELECT max(term_taxonomy_id) FROM $wpdb->term_taxonomy");
    $max_term_id = $wpdb->get_var("SELECT max(term_id) FROM $wpdb->terms");
    if ($max_term_id > $max_term_taxonomy_id) {
        $max_term_taxonomy_id = $max_term_id;
    }
    $simple = $wpdb->get_var("SELECT `term_id` FROM $wpdb->terms WHERE `name` = 'simple' AND `slug` = 'simple'");
    $variable = $wpdb->get_var("SELECT `term_id` FROM $wpdb->terms WHERE `name` = 'variable' AND `slug` = 'variable'");
    $outofstock = $wpdb->get_var("SELECT `term_id` FROM $wpdb->terms WHERE `name` = 'outofstock' AND `slug` = 'outofstock'");
    $featured = $wpdb->get_var("SELECT `term_id` FROM $wpdb->terms WHERE `name` = 'featured' AND `slug` = 'featured'");

    //    $domain = $_SERVER['HTTP_HOST'];
    $domain = get_option('siteurl');
    $date = date('Y-m-d H:i:s');
    $gmdate = gmdate('Y-m-d H:i:s');

    $attribute_types = [];
    $swatch_types = [
        1 =>'select',
        2 => 'color',
        3 => 'image',
        4 => 'text',
    ];
    $attr = [];
    $wc_attribute_taxonomies = [];
    $attr_id = 0;
    foreach ($attributes as $attribute) {
        $attr_id++;
        $attribute_type = isset($swatch_types[$attribute->type])? $swatch_types[$attribute->type]: $swatch_types[1];
        $attribute_type_to_assign = 'select';
        if (defined('RENTPRO_SWATCHES_PATH') || defined('ZOO_CW_VERSION') || defined('WOOF_PATH')) {
            $attribute_type_to_assign = $attribute_type;
        }
        $attribute_sql[] = "($attr_id, '" . $attribute->slug . "', '" . addslashes($attribute->title) . "', '$attribute_type_to_assign', 'menu_order', 0)";
        $attribute_relations_sql[] = "($attr_id, $attribute->id)";
        // collect attributes data for _transient_wc_attribute_taxonomies option
        $wc_attribute_taxonomies[] = (object) [
            "attribute_id" => $attr_id,
            "attribute_name" => $attribute->slug,
            "attribute_label" => $attribute->title,
            "attribute_type" => $attribute_type_to_assign,
            // "attribute_type" => "select",
            "attribute_orderby" => "menu_order",
            "attribute_public" => 0
        ];
        $attribute->new_id = $attr_id;
        $attr[$attribute->id] = [
            "new_id" => $attr_id,
            "id" => $attribute->id,
            "title" => $attribute->title,
            "slug" => $attribute->slug,
            "type" => $attribute_type,
        ];
        $attribute_types[] = "($attr_id, '$attribute_type')";
    }
    unset($attributes);
    if ( !empty($attribute_sql)) {
        rental_insert("INSERT INTO `" . $wpdb->prefix . "woocommerce_attribute_taxonomies`  (`attribute_id`, `attribute_name`, `attribute_label`, `attribute_type`, `attribute_orderby`, `attribute_public`) VALUES", $attribute_sql);
    }
    unset($attribute_sql);
    if ( !empty($attribute_relations_sql)) {
        $wpdb->query("INSERT INTO `$rental_attribute_relations` (`id`, `rental_id`) VALUES " . implode(", ", $attribute_relations_sql));
    }
    unset($attribute_relations_sql);
    update_option('_transient_wc_attribute_taxonomies', $wc_attribute_taxonomies);
    unset($wc_attribute_taxonomies);
    if (defined('ZOO_CW_VERSION') && !empty($attribute_types)) {
        $wpdb->query("INSERT INTO `" . $wpdb->prefix . "zoo_cw_product_attribute_swatch_type` (`attribute_id`, `swatch_type`) VALUES " . implode(", ", $attribute_types));
    }
    unset($attribute_types);

    $attr_val = [];
    $tt_id = $max_term_taxonomy_id;
    foreach ($attribute_values as $attribute_value) {
        if ( !isset($attr[$attribute_value->attribute_id])) {
            continue;
        }
        $tt_id++;
        $attribute_type = $attr[$attribute_value->attribute_id]["type"];
        $attr_val[$attribute_value->id] = [
            "term_taxonomy_id" => $tt_id,
            "id" => $attribute_value->id,
            "attribute_id" => $attribute_value->attribute_id,
            "attribute_slug" => $attr[$attribute_value->attribute_id]["slug"],
            "attribute_type" => $attribute_type,
            "title" => $attribute_value->title,
            "slug" => $attribute_value->slug,
            "color" => $attribute_type == "color"? $attribute_value->color: "",
            "img_id" => $attribute_type == "image"? $attribute_value->img_id: 0,
            "is_group" => empty($attribute_value->is_group)? 0: 1,
            "object_id" => [],
        ];
    }
    unset($attribute_values);

    $brand_ids = [];
    $rental_img_brand_rel = [];
    foreach ($brands as $brand) {
        $tt_id++;
        $brand->title;
        $terms_sql[] = "($tt_id, '" . addslashes($brand->title) . "', '" . sanitize_title($brand->title) . "', 0)";
        $term_taxonomy_sql[] = "($tt_id, $tt_id, 'product_brand', '', 0, 0)";
        $brand_relations_sql[] = "($tt_id, $brand->id)";
        $brand_ids[$brand->id] = $tt_id;
        if ($brand->logo_id) {
            if (isset($rental_img_brand_rel[$brand->logo_id])) {
                $rental_img_brand_rel[$brand->logo_id][] = $tt_id;
            } else {
                $rental_img_brand_rel[$brand->logo_id] = [$tt_id];
            }
        }
    }
    unset($brands);
    // save image brand relation in session
    update_option('rental_img_brand_rel', $rental_img_brand_rel);

    $products_data = [];
    $products_slug = [];
    $product_up_sells = [];
    $product_cross_sells = [];
    $product_ids_for_up_cross_sells = [];
    $product_attributes_count = []; 
    $products_to_hide = [];
    // $products_to_update_qty = [];
    $variants_to_update_qty = [];
    $has_duplicates_by_pid = [];
    $duplicate_product_ids = [];
    foreach ($products as $prod_key => $product) {

        $product_hidden = isset($product->hidden_from_api) ? filter_var($product->hidden_from_api, FILTER_VALIDATE_BOOLEAN) : false;
        $product_is_addon = isset($product->is_add_on) ? filter_var($product->is_add_on, FILTER_VALIDATE_BOOLEAN) : false;

        $is_add_on = ($product_is_addon || $product_hidden) ? 1 : 0;
        
        $products_data[$product->id] = [
            'ids' => [],
            'img_id' => $product->img_id ? $product->img_id : (isset($product->variant_img_id) && $product->variant_img_id ? $product->variant_img_id : ''),
            'exempt_waiver' => $product->exempt_waiver,
            'is_sale' => $product->is_sale,
            'is_add_on' => $is_add_on,
            'add_ons' => $product->add_ons,
        ];

        $divisions = json_decode($product->divisions);

        $possible_duplicate = false;
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

        $has_duplicates = $divisions && count($divisions) > 1 && $possible_duplicate ? 1 : 0;
        foreach ($divisions as $division_id) {
            $new_product_id++;
            $products_data[$product->id]['ids'][$division_id] = $new_product_id;

            $has_duplicates_by_pid[$new_product_id] = $has_duplicates;
            

            if ($has_duplicates && $division_id != get_option('rental_location_based_duplicate_filter_division_id', 0)) {
                $duplicate_product_ids[] = $new_product_id;
            }
            

            if ($product_hidden || $product_is_addon) {
                $products_to_hide[] = $new_product_id;
            }

            
            // collecting rental upsell product ids grouped by WP product id
            $product_up_sells[$new_product_id] = (isset($product->up_sells) && !empty($product->up_sells)) ? json_decode($product->up_sells, true) : [];
            // collecting rental crosssell product ids grouped by WP product id
            $product_cross_sells[$new_product_id] = (isset($product->cross_sells) && !empty($product->cross_sells)) ? json_decode($product->cross_sells, true) : [];

            $product_ids_for_up_cross_sells[$product->id][] = $new_product_id;

            $slug = sanitize_title($product->name);
            if (isset($products_slug[$slug])) {
                $products_slug[$slug]++;
                $prod_key++;
                $slug .= "-$products_slug[$slug]-" . $prod_key;
                // $slug .= "-$products_slug[$slug]";
            } else {
                $products_slug[$slug] = 1;
            }

            // add product data for insert in posts table
            $full_description = isset($product->full_description) ? addslashes($product->full_description) : '';
            $short_description = isset($product->description) ? addslashes($product->description) : '';

            $product_sql[$new_product_id] = "($new_product_id, 1, '$date', '$gmdate', '" . addslashes($product->name) . "',
            '" . $full_description . "', '" . $short_description . "', 'publish', 'open', 'closed',
            '$slug', '', '', '$date', '$gmdate', '', 0, '$domain/?post_type=product&p=$new_product_id', 'product')";

            $product_relations_sql[] = "($new_product_id, $product->id, $division_id)";

            
            $product_attributes = [];
            if ($product->attributes) {
                $attr_ids = json_decode($product->attributes);
                $product_attributes_count[$product->id] = count($attr_ids);

                $i = 0;
                foreach ($attr_ids as $attr_id) {
                    if ( !isset($attr[$attr_id])) {
                        continue;
                    }
                    // collect data for postmeta _product_attributes
                    $attribute = $attr[$attr_id]["slug"];
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

                $img_id = '';
                if ($product->img_id) {
                    $img_id = $product->img_id;
                }

                $product_sku = '';
                if (isset($product->SKUs) && $product->SKUs) {
                    $SKUs = json_decode($product->SKUs);
                    $product_sku = isset($SKUs[0]) ? addslashes($SKUs[0]) : '';
                }

                $product_weight = $product_length = $product_width = $product_height = $product_depth = '';
                if (isset($product->dimensions) && $product->dimensions) {
                    $product_dimensions = json_decode($product->dimensions);

                    $product_weight = $product_dimensions->weight;
                    $product_length = $product_dimensions->length;
                    $product_width = $product_dimensions->width;
                    $product_height = $product_dimensions->height;
                    $product_depth = isset($product_dimensions->depth) ? $product_dimensions->depth : '';
                }

                // add product data for insert in postmeta table
                $productmeta_sql[$new_product_id] = "($new_product_id, '_wc_review_count', '0' ),
                ($new_product_id, '_wc_rating_count', 'a:0:{}'),
                ($new_product_id, '_wc_average_rating', '0'),
                ($new_product_id, '_edit_last', ''),
                ($new_product_id, '_edit_lock', ''),
                ($new_product_id, '_sku', '$product_sku' ),
                ($new_product_id, '_regular_price', ''),
                ($new_product_id, '_sale_price', '' ),
                ($new_product_id, '_sale_price_dates_from', '' ),
                ($new_product_id, '_sale_price_dates_to', '' ),
                ($new_product_id, 'total_sales', '0' ),
                ($new_product_id, '_tax_status', 'taxable' ),
                ($new_product_id, '_tax_class', '' ),
                ($new_product_id, '_manage_stock', 'no' ),
                ($new_product_id, '_backorders', 'yes' ),
                ($new_product_id, '_sold_individually', 'no' ),
                ($new_product_id, '_weight', '$product_weight' ),
                ($new_product_id, '_length', '$product_length' ),
                ($new_product_id, '_width', '$product_width' ),
                ($new_product_id, '_height', '$product_height' ),
                ($new_product_id, '_depth', '$product_depth' ),
                ($new_product_id, '_upsell_ids', 'a:0:{}' ),
                ($new_product_id, '_crosssell_ids', 'a:0:{}' ),
                ($new_product_id, '_purchase_note', '' ),
                ($new_product_id, '_virtual', 'no' ),
                ($new_product_id, '_downloadable', 'no' ),
                ($new_product_id, '_product_image_gallery', '' ),
                ($new_product_id, '_download_limit', '-1' ),
                ($new_product_id, '_download_expiry', '-1' ),
                ($new_product_id, '_stock', '1' ),
                ($new_product_id, '_stock_status', 'instock'),
                ($new_product_id, '_product_version', '3.2.3'),
                ($new_product_id, '_price', ''),
                ($new_product_id, 'zoo_cw_product_swatch_data', 'a:0:{}'),
                ($new_product_id, '_thumbnail_id', '$img_id'),
                ($new_product_id, '_product_attributes', '$product_attributes'),
                ($new_product_id, '_rental_exempt_waiver', '$product->exempt_waiver'),
                ($new_product_id, '_rental_is_sale', '$product->is_sale'),
                ($new_product_id, '_rental_by_interval', '$product->rental_by_interval'),
                ($new_product_id, '_rental_by_slot', '$product->rental_by_slot'),
                ($new_product_id, '_rental_is_add_on', '$is_add_on')";
                // ($new_product_id, '_manage_stock', 'yes' ),

                $productmeta_sql[$new_product_id] .= ",($new_product_id, '_rental_is_duplicate', '$has_duplicates')";

                $term_relation_sql[] = "($new_product_id, $variable, 0)";
            } else {
                $term_relation_sql[] = "($new_product_id, $simple, 0)";
            }

            if ($product->is_featured) {
                $term_relation_sql[] = "($new_product_id, $featured, 0)";
            }

            if ($product->brand_id && isset($brand_ids[$product->brand_id])) {
                $term_relation_sql[] = "($new_product_id, " . $brand_ids[$product->brand_id] . ", 0)";
            }
        }
    }

    $product_prices = [];
    $rental_img_variant_rel = [];
    $variant_id = $new_product_id;
    $variant_ids = [];
    foreach ($product_variants as $var_key => $variant) {
        $product_id = $products_data[$variant->product_id]['ids'][$variant->division_id];

        $parent_is_add_on = !empty($products_data[$variant->product_id]['is_add_on']);

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


            if ($variant_has_duplicates && $variant->division_id != get_option('rental_location_based_duplicate_filter_division_id', 0)) {
                $duplicate_product_ids[] = $variant_id;
            }
        }

        // collecting non-discountable product ids for coupons 
        if ($variant->discountable == 0) {
            $coupons_excluded_product_ids[] = $product_id;
        }

        $stock_status = $variant->inventory && ($stock_status_despite_quantity || $variant->quantity)? 'instock': 'outofstock';

        $tax_status = $variant->taxable? 'taxable': 'none';

        $job_cost = $variant->job_cost? $variant->job_cost: 0;

        $sku = isset($variant->sku) ? addslashes($variant->sku) : '';

        $price = $variant->rental_price;
        $sale_price = '';
        if ($variant->sale_price > 0) {
            $price = $sale_price = $variant->sale_price;
        }

        if ($variant->attribute_values) {
            $variant_id++;

            if (get_option('rental_location_based_duplicate_filter', 0)) {
                $variants_to_update_qty[] = [
                    'division_id' => $variant->division_id,
                    'variant_id' => $variant_id,
                    'rental_product_id' => $variant->product_id,
                    'qty' => $variant_stock,
                ];
            }


            if ($parent_is_add_on) {
                $products_to_hide[] = $product_id; 
            }
            

            if (isset($variant_ids[$variant->id])) {
                $variant_ids[$variant->id][$variant->division_id] = $variant_id;
            } else {
                $variant_ids[$variant->id] = [$variant->division_id => $variant_id];
            }


            $variant_relations_sql[$variant_id] = "($variant_id, $variant->id, $variant->division_id)";

            if ($variant->img_id) {
                if (isset($rental_img_variant_rel[$variant->img_id])) {
                    $rental_img_variant_rel[$variant->img_id][] = $variant_id;
                } else {
                    $rental_img_variant_rel[$variant->img_id] = [$variant_id];
                }
            }

            $variantmeta_sql[$variant_id] = "";
            $default_attributes = [];
            $attr_val_ids = json_decode($variant->attribute_values);
            foreach ($attr_val_ids as $attr_val_id) {
                if ( !isset($attr_val[$attr_val_id])) {
                    continue;
                }
                $attribute = $attr_val[$attr_val_id]["attribute_slug"];
                $default_attributes["pa_$attribute"] = $attr_val[$attr_val_id]["slug"];
                $variantmeta_sql[$variant_id] .= "($variant_id, 'attribute_pa_$attribute', '" . addslashes($attr_val[$attr_val_id]["slug"]) . "'),";
                $attr_val[$attr_val_id]["object_id"][$product_id] = $product_id;
            }
            if (isset($product_attributes_count) && $product_attributes_count && !empty($product_attributes_count[$variant->product_id]) && $product_attributes_count[$variant->product_id] < 2) {
                if ($variant->default) {
                    $productmeta_sql[$product_id] .= ",($product_id, '_default_attributes', '" . addslashes(serialize($default_attributes)) . "')";
                }
            }
            $attr_val_title = implode(", ", $default_attributes);
            $attr_val_name = implode("-", $default_attributes);

            // add variant data for insert in posts table
            $variant_sql[$variant_id] = "($variant_id, 1, '$date', '$gmdate', '" . addslashes($variant->name) . " - $attr_val_title',
            '', '', 'publish', 'closed', 'closed', '" . sanitize_title($variant->name) . "-$attr_val_name-$product_id-$var_key',
            '', '', '$date', '$gmdate', '', '$product_id', '$domain/?post_type=product&p=$product_id', 'product_variation')";

            if ( !$variant->inventory) {
                $term_relation_sql[] = "($variant_id, $outofstock, 0)";
            }

            $variant_description = isset($variant->description) ? addslashes($variant->description) : '';

            // get variant price multiplier
            $price_multiplier_id = empty($variant->price_multiplier_id) ? 0 : $variant->price_multiplier_id;
            $replacement_price = empty($variant->rep_price) ? 0 : $variant->rep_price;

            // rental by interval/rental by time slot data
            $rental_interval = empty($variant->rental_interval) ? 0 : $variant->rental_interval;
            $interval_steps = empty($variant->interval_steps) ? 0 : $variant->interval_steps;
            $rental_time_slots = empty($variant->rental_time_slots) ? "" : $variant->rental_time_slots;

            // ($variant_id, '_stock', '$variant->quantity' ),

            // add variant data for insert in postmeta table
            $variantmeta_sql[$variant_id] .= "($variant_id, '_variation_description', '" . $variant_description . "'),
            ($variant_id, '_wc_rating_count', 'a:0:{}'),
            ($variant_id, '_wc_review_count', '0'),
            ($variant_id, '_wc_average_rating', '0'),
            ($variant_id, '_sku', '$sku'),
            ($variant_id, '_regular_price', '$variant->rental_price'),
            ($variant_id, '_rental_replacement_price', '$replacement_price'),
            ($variant_id, '_job_cost', '$job_cost'),
            ($variant_id, '_price_multiplier_id', '$price_multiplier_id'),
            ($variant_id, '_sale_price', '$sale_price'),
            ($variant_id, '_sale_price_dates_from', ''),
            ($variant_id, '_sale_price_dates_to', ''),
            ($variant_id, 'total_sales', '0' ),
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
            ($variant_id, '_thumbnail_id', ''),
            ($variant_id, '_rental_by_interval', '$variant->rental_by_interval'),
            ($variant_id, '_rental_by_slot', '$variant->rental_by_slot'),
            ($variant_id, '_rental_interval', '$rental_interval'),
            ($variant_id, '_rental_interval_steps', '$interval_steps'),
            ($variant_id, '_rental_interval_price', '$variant->rental_interval_price'),
            ($variant_id, '_rental_interval_sale_price', '$variant->rental_interval_sale_price'),
            ($variant_id, '_rental_additional_hourly_price', '$variant->additional_hourly_price'),
            ($variant_id, '_rental_time_slots', '$rental_time_slots'),
            ($variant_id, '_downloadable_files', 'a:0:{}')";

            $variantmeta_sql[$variant_id] .= ",($variant_id, '_rental_is_duplicate', '$variant_has_duplicates')";

            // ($variant_id, '_manage_stock', 'yes' ),
            if (isset($product_meta_lookup_sql)) {
                $on_sale = $sale_price? 1: 0;
                // $product_meta_lookup_sql[$variant_id] = "($variant_id, '$sku', 0, 0, $price, $price, $on_sale, $variant->quantity, '$stock_status', '$tax_status')";
                $product_meta_lookup_sql[$variant_id] = "($variant_id, '$sku', 0, 0, $price, $price, $on_sale, $variant_stock, '$stock_status', '$tax_status')";
                if (isset($product_prices[$product_id])) {
                    if ($price < $product_prices[$product_id]['min']) {
                        $product_prices[$product_id]['min'] = $price;
                    } elseif ($price > $product_prices[$product_id]['max']) {
                        $product_prices[$product_id]['max'] = $price;
                    }
                } else {
                    $product_prices[$product_id] = ['min' => $price, 'max' => $price];
                }
                $product_meta_lookup_sql[$product_id] = "($product_id, '$sku', 0, 0, " . $product_prices[$product_id]['min'] .
                    ", " . $product_prices[$product_id]['max'] . ", 0, $variant_stock, '$stock_status', '$tax_status')";
                    // ", " . $product_prices[$product_id]['max'] . ", 0, $variant->quantity, '$stock_status', '$tax_status')";
            }
        } else {

            if ($parent_is_add_on) {
                $products_to_hide[] = $product_id; 
            }

            if ( !$variant->inventory) {
                // key = [$product_id ."_". $outofstock] because there are product variants that do not have custom fields
                $term_relation_sql[$product_id ."_". $outofstock] = "($product_id, $outofstock, 0)";
            }


            // get variant price multiplier
            $price_multiplier_id = empty($variant->price_multiplier_id) ? 0 : $variant->price_multiplier_id;
            $replacement_price = empty($variant->rep_price) ? 0 : $variant->rep_price;

            // rental by interval/rental by time slot data
            $rental_interval = empty($variant->rental_interval) ? 0 : $variant->rental_interval;
            $interval_steps = empty($variant->interval_steps) ? 0 : $variant->interval_steps;
            $rental_time_slots = empty($variant->rental_time_slots) ? "" : $variant->rental_time_slots;

            $productmeta_sql[$product_id] = "($product_id, '_wc_review_count', '0' ),
            ($product_id, '_wc_rating_count', 'a:0:{}'),
            ($product_id, '_wc_average_rating', '0'),
            ($product_id, '_edit_last', ''),
            ($product_id, '_edit_lock', ''),
            ($product_id, '_sku', '$sku'),
            ($product_id, '_regular_price', '$variant->rental_price'),
            ($product_id, '_rental_replacement_price', '$replacement_price'),
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
            ($product_id, '_weight', '$variant->weight'),
            ($product_id, '_length', '$variant->length'),
            ($product_id, '_width', '$variant->width'),
            ($product_id, '_height', '$variant->height'),
            ($product_id, '_depth', '$variant->depth'),
            ($product_id, '_upsell_ids', 'a:0:{}' ),
            ($product_id, '_crosssell_ids', 'a:0:{}' ),
            ($product_id, '_purchase_note', '' ),
            ($product_id, '_default_attributes', 'a:0:{}' ),
            ($product_id, '_virtual', 'no' ),
            ($product_id, '_downloadable', 'no' ),
            ($product_id, '_product_image_gallery', '' ),
            ($product_id, '_download_limit', '-1' ),
            ($product_id, '_download_expiry', '-1' ),
            ($product_id, '_stock', '$variant_stock' ),
            ($product_id, '_stock_status', '$stock_status'),
            ($product_id, '_product_version', '3.2.3'),
            ($product_id, '_price', '$price'),
            ($product_id, '_rental_inventory_id', '$variant->inventory_id'),
            ($product_id, 'zoo_cw_product_swatch_data', 'a:0:{}'),
            ($product_id, '_thumbnail_id', '" . $products_data[$variant->product_id]['img_id'] . "'),
            ($product_id, '_rental_exempt_waiver', '" . $products_data[$variant->product_id]['exempt_waiver'] . "'),
            ($product_id, '_rental_is_sale', '" . $products_data[$variant->product_id]['is_sale'] . "'),
            ($product_id, '_rental_by_interval', '$variant->rental_by_interval'),
            ($product_id, '_rental_by_slot', '$variant->rental_by_slot'),
            ($product_id, '_rental_interval', '$rental_interval'),
            ($product_id, '_rental_interval_steps', '$interval_steps'),
            ($product_id, '_rental_interval_price', '$variant->rental_interval_price'),
            ($product_id, '_rental_interval_sale_price', '$variant->rental_interval_sale_price'),
            ($product_id, '_rental_additional_hourly_price', '$variant->additional_hourly_price'),
            ($product_id, '_rental_time_slots', '$rental_time_slots'),
            ($product_id, '_rental_is_add_on', '" . $products_data[$variant->product_id]['is_add_on'] . "')";

            $product_without_variants_has_duplicate = isset($has_duplicates_by_pid[$product_id]) ? $has_duplicates_by_pid[$product_id] : 0;
            $productmeta_sql[$product_id] .= ",($product_id, '_rental_is_duplicate', '$product_without_variants_has_duplicate')";

            if ($product_without_variants_has_duplicate && $variant->division_id != get_option('rental_location_based_duplicate_filter_division_id', 0)) {
                $duplicate_product_ids[] = $product_id;
            }

            //            ($product_id, '_manage_stock', 'yes' ),
            if (isset($product_meta_lookup_sql)) {
                $on_sale = $sale_price? 1: 0;
                $product_meta_lookup_sql[$product_id] = "($product_id, '$sku', 0, 0, $price" .
                    ", $price, $on_sale, $variant_stock, '$stock_status', '$tax_status')";
                    // ", $price, $on_sale, $variant->quantity, '$stock_status', '$tax_status')";
            }
        }

    }

    foreach ($products_data as $product) {

        if ($product['add_ons']) {
            
            foreach ($product['ids'] as $division_id => $product_id) {
                
                $product_add_ons = [];
                $add_ons = json_decode($product['add_ons']);

                foreach ($add_ons as $add_on) {
                    if (isset($products_data[$add_on->product_id]) && isset($products_data[$add_on->product_id]['ids'][$division_id])) {
                        
                        $add_on_id = $products_data[$add_on->product_id]['ids'][$division_id];
                        
                        $add_on_variant_id = 0;
                        if ($add_on->variant_id && isset($variant_ids[$add_on->variant_id]) && isset($variant_ids[$add_on->variant_id][$division_id])) {
                            $add_on_variant_id = $variant_ids[$add_on->variant_id][$division_id];
                        }

                        $product_add_ons[$add_on_variant_id ?: $add_on_id] = [
                            'product_id' => $add_on_id,
                            'variant_id' => $add_on_variant_id,
                            'rental_variant_id' => $add_on->variant_id,
                            'quantity' => $add_on->quantity,
                            'price' => $add_on->price,
                            'required' => $add_on->required,
                            'hidden' => isset($add_on->hidden) ? $add_on->hidden : 0,
                            'product_price' => isset($add_on->product_price) ? $add_on->product_price : 0,
                            'inherit_price' => isset($add_on->inherit_price) ? $add_on->inherit_price : 0,
                        ];
                    }
                }

                if ($product_add_ons) {
                    $productmeta_sql[$product_id] .= ",($product_id, '_rental_add_ons', '" . addslashes(serialize($product_add_ons)) . "')";
                }
            }
        }
    }


    
    // $set_id = $variant_id;
    // $set_ids = [];
    // $set_items_collection = [];
    // $set_up_sells_from_sets = $set_up_sells_from_products = $set_cross_sells_from_sets = $set_cross_sells_from_products = [];
    // update_option('rental_set_items_have_addons', false);
    // foreach ($sets as $set) {
    //     $set_id++;
    //     $set_ids[$set->id] = $set_id;

    //     $set_up_sells_from_sets[$set_id] = (isset($set->up_sells_sets) && !empty($set->up_sells_sets)) ? json_decode($set->up_sells_sets, true) : [];
    //     $set_up_sells_from_products[$set_id] = (isset($set->up_sells_products) && !empty($set->up_sells_products)) ? json_decode($set->up_sells_products, true) : [];

    //     $set_cross_sells_from_sets[$set_id] = (isset($set->cross_sells_sets) && !empty($set->cross_sells_sets)) ? json_decode($set->cross_sells_sets, true) : [];
    //     $set_cross_sells_from_products[$set_id] = (isset($set->cross_sells_products) && !empty($set->cross_sells_products)) ? json_decode($set->cross_sells_products, true) : [];

    //     // get set's price multiplier
    //     $price_multiplier_id = empty($set->price_multiplier_id) ? 0 : $set->price_multiplier_id;

    //     // collecting non-discountable product(set) ids for coupons 
    //     if ($set->discountable == 0) {
    //         $coupons_excluded_product_ids[] = $set_id;
    //     }

    //     $slug = sanitize_title($set->title);
    //     if (isset($products_slug[$slug])) {
    //         $products_slug[$slug]++;
    //         $slug .= "-$products_slug[$slug]";
    //     } else {
    //         $products_slug[$slug] = 1;
    //     }

    //     // add set data for insert in posts table
    //     $set_full_description = isset($set->full_description) ? addslashes($set->full_description) : '';
    //     $set_short_description = isset($set->description) ? addslashes($set->description) : '';

    //     $product_sql[$set_id] = "($set_id, 1, '$date', '$gmdate', '" . addslashes($set->title) . "',
    //         '" . $set_full_description . "', '" . $set_short_description . "', 'publish', 'open', 'closed',
    //         '$slug', '', '', '$date', '$gmdate', '', 0, '$domain/?post_type=product&p=$set_id', 'product')";

    //     $set_relations_sql[] = "($set_id, $set->id, $set->division_id)";
    //     $term_relation_sql[] = "($set_id, $simple, 0)";

    //     if ($set->img_id) {
    //         if (isset($rental_img_variant_rel[$set->img_id])) {
    //             $rental_img_variant_rel[$set->img_id][] = $set_id;
    //         } else {
    //             $rental_img_variant_rel[$set->img_id] = [$set_id];
    //         }
    //     }

    //     $set_items = [];
    //     $set_items_have_optional_items = false;
    //     $set_items_have_some_hidden_items = false;
    //     $items = json_decode($set->items);
    //     if($items){
    //         foreach ($items as $item) {

    //             if (
    //                 (isset($products_data[$item->product_id]) && isset($products_data[$item->product_id]['ids'][$item->division_id]))
    //                 || (isset($item->optional_items) && $item->product_id)
    //             ) {

    //                 $set_item_product_id = isset($products_data[$item->product_id]) && isset($products_data[$item->product_id]['ids'][$item->division_id]) ? $products_data[$item->product_id]['ids'][$item->division_id] : ($item->product_id ? $item->product_id : 0);
    //                 $set_item_variant_id = isset($item->optional_items) ? 0 : (isset($variant_ids[$item->variant_id][$item->division_id])? $variant_ids[$item->variant_id][$item->division_id]: null);
    //                 if (isset($item->has_selected) && $item->has_selected && $item->variant_id) {
    //                     $set_item_variant_id = isset($variant_ids[$item->variant_id][$item->division_id])? $variant_ids[$item->variant_id][$item->division_id]: null;
    //                 }

    //                 $optional_items = [];
    //                 if (isset($item->optional_items)) {
    //                     foreach ($item->optional_items as $optional_item) {

    //                         $optional_items[] = [
    //                             'rental_product_id' => $optional_item->product_id,
    //                             'rental_variant_id' => $optional_item->variant_id,
    //                             'product_id' => $products_data[$optional_item->product_id]['ids'][$optional_item->division_id],
    //                             'variant_id' => isset($variant_ids[$optional_item->variant_id][$optional_item->division_id]) ? $variant_ids[$optional_item->variant_id][$optional_item->division_id]: null,
    //                             'quantity' => $optional_item->quantity,
    //                             'is_selected' => isset($optional_item->is_selected) && $optional_item->is_selected ? 1 : 0,
    //                         ];
    //                     }


    //                     if ($optional_items) {
    //                         $set_items_have_optional_items = true;
    //                     }
    //                 }


    //                 $set_item_addons = [];
    //                 if (isset($item->addons) && $item->addons) {

    //                     // $set_item_product_id = isset($products_data[$item->product_id]) && isset($products_data[$item->product_id]['ids'][$item->division_id]) ? $products_data[$item->product_id]['ids'][$item->division_id] : 0;
    //                     foreach ($item->addons as $item_addon) {


    //                         // if ($item_addon->division_id == get_option('rental_system_main_division', 0)) {

    //                             $addon_variants_optional = [];
    //                             if (isset($item_addon->variants_optional) && $item_addon->variants_optional) {

    //                                 foreach ($item_addon->variants_optional as $addon_variant) {
                                        
    //                                     $optional_variant_product_id = isset($products_data[$addon_variant->product_id]) && isset($products_data[$addon_variant->product_id]['ids'][$addon_variant->division_id]) ? $products_data[$addon_variant->product_id]['ids'][$addon_variant->division_id] : 0;
    //                                     $optional_variant_variant_id = $addon_variant->variant_id && isset($variant_ids[$addon_variant->variant_id]) && isset($variant_ids[$addon_variant->variant_id][$addon_variant->division_id]) ? $variant_ids[$addon_variant->variant_id][$addon_variant->division_id] : 0;
                                        
    //                                     $addon_variants_optional[] = [
    //                                         'product_id' => $optional_variant_product_id,
    //                                         'rental_product_id' => $addon_variant->product_id,
    //                                         'variant_id' => $optional_variant_variant_id,
    //                                         'rental_variant_id' => $addon_variant->variant_id,
    //                                         'quantity' => $addon_variant->quantity,
    //                                         'default' => $addon_variant->default,
    //                                     ];
    //                                 }
    //                             }

    //                             $add_on_id = isset($products_data[$item_addon->product_id]) && isset($products_data[$item_addon->product_id]['ids'][$item_addon->division_id]) ? $products_data[$item_addon->product_id]['ids'][$item_addon->division_id] : 0;
    //                             $add_on_variant_id = $item_addon->add_on_variant_id && isset($variant_ids[$item_addon->add_on_variant_id]) && isset($variant_ids[$item_addon->add_on_variant_id][$item_addon->division_id]) ? $variant_ids[$item_addon->add_on_variant_id][$item_addon->division_id] : 0;
    
    //                             $set_item_addons[] = [
    //                                 'parent_set_id' => $set_id,
    //                                 'parent_set_product_id' => $set_item_product_id,
    //                                 'parent_set_variant_id' => $set_item_variant_id,
    //                                 'rental_inv_id' => $item_addon->id,
    //                                 'product_id' => $add_on_id,
    //                                 'rental_product_id' => $item_addon->product_id,
    //                                 'variant_id' => $add_on_variant_id,
    //                                 'rental_variant_id' => $item_addon->add_on_variant_id,
    //                                 'quantity' => $item_addon->add_on_quantity,
    //                                 'price' => $item_addon->price,
    //                                 'required' => $item_addon->required,
    //                                 'hidden' => isset($item_addon->hidden) ? $item_addon->hidden : 0,
    //                                 'product_price' => isset($item_addon->product_price) ? $item_addon->product_price : 0,
    //                                 'inherit_price' => isset($item_addon->inherit_price) ? $item_addon->inherit_price : 0,
    //                                 'variants_optional' => $addon_variants_optional,
    //                             ];
    //                         // }

                            
    //                     }


    //                     if ($set_item_addons && !get_option('rental_set_items_have_addons', false)) {
    //                         update_option('rental_set_items_have_addons', true);
    //                     }
    //                 }


    //                 // $set_item_variant_id = isset($item->optional_items) ? 0 : (isset($variant_ids[$item->variant_id][$item->division_id])? $variant_ids[$item->variant_id][$item->division_id]: null);
    //                 // if (isset($item->has_selected) && $item->has_selected && $item->variant_id) {
    //                 //     $set_item_variant_id = isset($variant_ids[$item->variant_id][$item->division_id])? $variant_ids[$item->variant_id][$item->division_id]: null;
    //                 // }
                    

    //                 if (isset($item->hidden) && $item->hidden) {
    //                     $set_items_have_some_hidden_items = true;
    //                 }

    //                 $optional_item_price_update_needed = (isset($item->has_selected) && $item->has_selected) || (count($optional_items) < 2) ? 1 : 0;
    //                 $set_items[] = [
    //                     // 'product_id' => $products_data[$item->product_id]['ids'][$item->division_id],
    //                     'rental_variant_id' => $item->variant_id ?? 0,
    //                     'rental_product_id' => $item->product_id ?? 0,
    //                     'parent_set_id' => $set_id,
    //                     'product_id' => $set_item_product_id,
    //                     'variant_id' => $set_item_variant_id,
    //                     'quantity' => $item->quantity,
    //                     'optional_items' => $optional_items,
    //                     'has_selected' => isset($item->has_selected) && $item->has_selected ? 1 : 0,
    //                     'optional_item_price_update_needed' => $optional_item_price_update_needed, // 0: no need to update, 1: needs update, 2: is updated
    //                     'hidden' => isset($item->hidden) && $item->hidden ? 1 : 0,
    //                     'price' => $item->price ?? 0,
    //                     'separate_price' => $item->separate_price ?? 0,
    //                     'required' => $item->required ?? 0,
    //                     'addons' => $set_item_addons,
    //                 ];


    //                 if (!isset($item->optional_items)) {
    //                     $set_items_collection[$products_data[$item->product_id]['ids'][$item->division_id]] = true;

    //                     if (isset($variant_ids[$item->variant_id][$item->division_id])) {
    //                         $set_items_collection[$variant_ids[$item->variant_id][$item->division_id]] = true;
    //                     }
    //                 }
                    
    //             }
    //         }
    //     }

    //     $sku = addslashes($set->number);
    //     $tax_status = $set->taxable? 'taxable': 'none';
    //     $hide_items_on_website = isset($set->hide_items_on_website) ? $set->hide_items_on_website : 0;
    //     $item_based_total = isset($set->item_based_total) ? $set->item_based_total : 0;
    //     $set_max_qty = $set->max_quantity ?? '';

    //     $productmeta_sql[$set_id] = "($set_id, '_wc_review_count', '0' ),
    //         ($set_id, '_wc_rating_count', 'a:0:{}'),
    //         ($set_id, '_wc_average_rating', '0'),
    //         ($set_id, '_edit_last', ''),
    //         ($set_id, '_edit_lock', ''),
    //         ($set_id, '_sku', '$sku'),
    //         ($set_id, '_regular_price', '$set->rental_price'),
    //         ($set_id, '_job_cost', '$set->job_cost'),
    //         ($set_id, '_price_multiplier_id', '$price_multiplier_id'),
    //         ($set_id, '_sale_price', '' ),
    //         ($set_id, '_sale_price_dates_from', '' ),
    //         ($set_id, '_sale_price_dates_to', '' ),
    //         ($set_id, 'total_sales', '0' ),
    //         ($set_id, '_tax_status', '$tax_status'),
    //         ($set_id, '_tax_class', '' ),
    //         ($set_id, '_manage_stock', 'no' ),
    //         ($set_id, '_backorders', 'yes' ),
    //         ($set_id, '_sold_individually', 'no' ),
    //         ($set_id, '_weight', '' ),
    //         ($set_id, '_length', '' ),
    //         ($set_id, '_width', '' ),
    //         ($set_id, '_height', '' ),
    //         ($set_id, '_depth', '' ),
    //         ($set_id, '_upsell_ids', 'a:0:{}' ),
    //         ($set_id, '_crosssell_ids', 'a:0:{}' ),
    //         ($set_id, '_purchase_note', '' ),
    //         ($set_id, '_default_attributes', 'a:0:{}' ),
    //         ($set_id, '_virtual', 'no' ),
    //         ($set_id, '_downloadable', 'no' ),
    //         ($set_id, '_product_image_gallery', '' ),
    //         ($set_id, '_download_limit', '-1' ),
    //         ($set_id, '_download_expiry', '-1' ),
    //         ($set_id, '_stock', '1' ),
    //         ($set_id, '_stock_status', 'instock'),
    //         ($set_id, '_product_version', '3.2.3'),
    //         ($set_id, '_price', '$set->rental_price'),
    //         ($set_id, '_price_default', '$set->rental_price'),
    //         ($set_id, 'zoo_cw_product_swatch_data', 'a:0:{}'),
    //         ($set_id, '_thumbnail_id', ''),
    //         ($set_id, '_rental_exempt_waiver', '$set->exempt_waiver'),
    //         ($set_id, '_rental_is_sale', '$set->is_sale'),
    //         ($set_id, '_rental_is_add_on', '0'),
    //         ($set_id, '_rental_set_items_have_optional_items', '" . $set_items_have_optional_items . "'),
    //         ($set_id, '_rental_set_items', '" . addslashes(serialize($set_items)) . "'),
    //         ($set_id, '_rental_set_items_default', '" . addslashes(serialize($set_items)) . "'),
    //         ($set_id, '_rental_item_based_total', '$item_based_total'),
    //         ($set_id, '_rental_hide_items_on_website', '$hide_items_on_website'),
    //         ($set_id, '_rental_some_hidden_items', '$set_items_have_some_hidden_items'),
    //         ($set_id, '_rental_max_quantity', '$set_max_qty'),
    //         ($set_id, '_rental_is_set', '1')";
    //     if (isset($product_meta_lookup_sql)) {
    //         $product_meta_lookup_sql[$set_id] = "($set_id, '$sku', 0, 0, $set->rental_price" .
    //             ", $set->rental_price, 0, 1, 'instock', '$tax_status')";
    //     }
    // }

    $sets_sync_result = rental_run_sets_sync_pass( [
        'sets'                         => $sets,
        'starting_id'                  => $variant_id,
        'products_data'                => $products_data,
        'variant_ids'                  => $variant_ids,
        'simple_tt_id'                 => $simple,
        'date'                         => $date,
        'gmdate'                       => $gmdate,
        'domain'                       => $domain,
        'products_slug'                => $products_slug,
        'rental_img_variant_rel'       => $rental_img_variant_rel,
        'coupons_excluded_product_ids' => $coupons_excluded_product_ids,
        'has_product_meta_lookup_sql'  => isset( $product_meta_lookup_sql ),
    ] );

    $set_id                         = $sets_sync_result->last_set_id();
    $set_ids                        = $sets_sync_result->set_ids();
    $set_items_collection           = $sets_sync_result->set_items_collection();
    $set_up_sells_from_sets         = $sets_sync_result->up_sells_from_sets();
    $set_up_sells_from_products     = $sets_sync_result->up_sells_from_products();
    $set_cross_sells_from_sets      = $sets_sync_result->cross_sells_from_sets();
    $set_cross_sells_from_products  = $sets_sync_result->cross_sells_from_products();
    $coupons_excluded_product_ids   = $sets_sync_result->coupons_excluded_product_ids();
    $products_slug                  = $sets_sync_result->products_slug();
    $rental_img_variant_rel         = $sets_sync_result->rental_img_variant_rel();

    foreach ( $sets_sync_result->product_sql() as $id => $row ) {
        $product_sql[ $id ] = $row;
    }
    foreach ( $sets_sync_result->productmeta_sql() as $id => $row ) {
        $productmeta_sql[ $id ] = $row;
    }
    if ( isset( $product_meta_lookup_sql ) ) {
        foreach ( $sets_sync_result->product_meta_lookup_sql() as $id => $row ) {
            $product_meta_lookup_sql[ $id ] = $row;
        }
    }
    $set_relations_sql = array_merge( $set_relations_sql, $sets_sync_result->set_relations_sql() );
    $term_relation_sql = array_merge( $term_relation_sql, $sets_sync_result->term_relation_sql() );

    // save image variant relation in session
    // update_option( 'rental_img_variant_rel', $rental_img_variant_rel);

    $category_products = [];
    $rental_img_category_rel = [];
    $rental_banner_img_category_rel = [];
    $cat_id = $tt_id;

    $existing_slugs = $wpdb->get_col( "SELECT slug FROM {$wpdb->terms}" );
    $existing_slugs = array_map('strval', $existing_slugs);

    // used_slugs will track slugs we already decided on inside this batch (prevent intra-batch collisions)
    $used_slugs = array_flip($existing_slugs); // keys = slug => value (we only use keys)

    // add the category data to the variables to insert to the required tables(terms, term_taxonomy)
    foreach ($categories as $category) {
        $cat_id = $tt_id + $category->id;

        $base_slug = sanitize_title($category->title);
        $slug = $base_slug;

        // Ensure uniqueness:
        // - If the slug is already in use (either from WP existing slugs OR by a previously processed category in this batch),
        //   then append the external category id
        // - If that still collides (extremely unlikely), append an incrementing suffix.
        if ( isset($used_slugs[$slug]) ) {
            $slug = $base_slug . '-' . $category->id;
            $suffix = 1;
            while ( isset($used_slugs[$slug]) ) {
                $slug = $base_slug . '-' . $category->id . '-' . $suffix;
                $suffix++;
            }
        }

        // Mark slug as used for subsequent items
        $used_slugs[$slug] = true;

        
        // Build the terms insert row using unique $slug
        $terms_sql[] = "($cat_id, '" . addslashes($category->title) . "', '" . addslashes($slug) . "', 0)";


        $parent_cat_id = 0;
        if (!empty($category->parent_id)) {
            $parent_cat_id = $category->parent_id + $tt_id;
            if ( !isset($category_products[$parent_cat_id])) {
                $category_products[$parent_cat_id] = [];
            }
        }
        if ( !isset($category_products[$cat_id])) {
            $category_products[$cat_id] = [];
        }

        $cat_products_count = 0;
        // relate products and categories with term_relation table
        if ($category->products && !empty($cat_products = explode(",", $category->products))) {
            foreach ($cat_products as $cat_product_id) {
                if (isset($products_data[$cat_product_id])) {
                    foreach ($products_data[$cat_product_id]['ids'] as $product_id) {
                        $term_relation_sql[] = "($product_id, $cat_id, 0)";
                        $cat_products_count++;
                        if ( !in_array($product_id, $category_products[$cat_id])) {
                            $category_products[$cat_id][] = $product_id;
                        }
                        if ($parent_cat_id && !in_array($product_id, $category_products[$parent_cat_id])) {
                            $category_products[$parent_cat_id][] = $product_id;
                        }
                    }
                }
            }
        }

        // relate sets and categories with term_relation table
        // if ($category->sets && !empty($cat_sets = explode(",", $category->sets))) {
        //     foreach ($cat_sets as $cat_set_id) {
        //         if (isset($set_ids[$cat_set_id])) {
        //             $term_relation_sql[] = "(" . $set_ids[$cat_set_id] . ", $cat_id, 0)";
        //             $cat_products_count++;
        //             if ( !in_array($set_ids[$cat_set_id], $category_products[$cat_id])) {
        //                 $category_products[$cat_id][] = $set_ids[$cat_set_id];
        //             }
        //             if ($parent_cat_id && !in_array($set_ids[$cat_set_id], $category_products[$parent_cat_id])) {
        //                 $category_products[$parent_cat_id][] = $set_ids[$cat_set_id];
        //             }
        //         }
        //     }
        // }

        rental_link_category_to_sets( $category, $cat_id, $parent_cat_id, $set_ids, $term_relation_sql, $cat_products_count, $category_products );

        $category_description = isset($category->description) ? addslashes($category->description) : '';
        $category_icon = isset($category->icon) ? addslashes($category->icon) : "";

        $term_taxonomy_sql[] = "($cat_id, $cat_id, 'product_cat', '" . $category_description . "', $parent_cat_id, $cat_products_count)";

        

        // add the category data to the variables to insert to the required tables(termmeta), plus save the original pretty slug
        $termmeta_sql[] = "($cat_id, 'order', 0)," .
            "($cat_id, 'display_type', '')," .
            "($cat_id, 'thumbnail_id', 0)," .
            "($cat_id, 'rental_icon', '" . $category_icon . "')," .
            // store the original/pretty slug for reference
            "($cat_id, 'rental_pretty_slug', '" . addslashes($base_slug) . "')";

        $category_relations_sql[] = "($cat_id, $category->id)";

        if ($category->img_id) {
            if (isset($rental_img_category_rel[$category->img_id])) {
                $rental_img_category_rel[$category->img_id][] = $cat_id;
            } else {
                $rental_img_category_rel[$category->img_id] = [$cat_id];
            }
        }

        if (isset($category->banner_img_id) && $category->banner_img_id) {
            if (isset($rental_banner_img_category_rel[$category->banner_img_id])) {
                $rental_banner_img_category_rel[$category->banner_img_id][] = $cat_id;
            } else {
                $rental_banner_img_category_rel[$category->banner_img_id] = [$cat_id];
            }
        }
    }

    // save image category relation in session
    update_option('rental_img_category_rel', $rental_img_category_rel);
    update_option('rental_banner_img_category_rel', $rental_banner_img_category_rel);

    // add products count for each category
    foreach ($category_products as $term_id => $objects) {
        $termmeta_sql[] = "($term_id, 'product_count_product_cat', " . count($objects) . ")";
    }

    $tags_slug = [];
    $similar_tags = [];
    // add the tag data to the variables to insert to the required table(termmeta)
    foreach ($product_tags as $product_tag) {
        $tag_id = $cat_id + $product_tag->id;

        $slug_tag = sanitize_title($product_tag->title);

        // if ($sets_tags) {
        //     foreach ($sets_tags as $set_tag) {
        //         $set_slug_tag = sanitize_title($set_tag->title);
    
        //         if ($slug_tag == $set_slug_tag) {
        //             $similar_tags[$set_slug_tag] = $set_slug_tag . '-sets';
        //         }
        //     }
        // }

        rental_detect_similar_sets_tags_for_product_tag( $product_tag->title, $sets_tags, $similar_tags );
       
        if (isset($tags_slug[$slug_tag])) {
            
            $tags_slug[$slug_tag]++;

            $slug_tag .= "-$tags_slug[$slug_tag]";
        } else {
            $tags_slug[$slug_tag] = 1;
        }

        $terms_sql[] = "($tag_id, '" . addslashes($product_tag->title) . "', '" . $slug_tag . "', 0)";

        $tag_products_count = 0;
        // relate products and tags with term_relation table
        if ($product_tag->products && !empty($tag_products = explode(",", $product_tag->products))) {
            foreach ($tag_products as $tag_product_id) {
                if (isset($products_data[$tag_product_id])) {
                    foreach ($products_data[$tag_product_id]['ids'] as $product_id) {
                        $term_relation_sql[] = "($product_id, $tag_id, 0)";
                        $tag_products_count++;
                    }
                }
            }
        }
        $termmeta_sql[] = "($tag_id, 'product_count_product_tag', $tag_products_count)";
        $term_taxonomy_sql[] = "($tag_id, $tag_id, 'product_tag', '', 0, $tag_products_count)";
        $tag_relations_sql[] = "($tag_id, $product_tag->id)";

    }


    // $set_tags_slug = [];
    // // Sets Tags
    // foreach ($sets_tags as $set_tag) {
    //     $tag_id = $cat_id + $set_tag->id;

    //     $set_slug_tag = sanitize_title($set_tag->title);
    //     if (isset($similar_tags[$set_slug_tag])) {
    //         $set_slug_tag = $similar_tags[$set_slug_tag];
    //     }
      
    //     if (isset($set_tags_slug[$set_slug_tag])) {
            
    //         $set_tags_slug[$set_slug_tag]++;

    //         $set_slug_tag .= "-$set_tags_slug[$set_slug_tag]";
    //     } else {
    //         $set_tags_slug[$set_slug_tag] = 1;
    //     }

    //     $terms_sql[] = "($tag_id, '" . addslashes($set_tag->title) . "', '" . $set_slug_tag . "', 0)";

    //     $tag_sets_count = 0;
    //     // relate sets and tags with term_relation table
    //     if ($set_tag->sets && !empty($tag_sets = explode(",", $set_tag->sets))) {
    //         foreach ($tag_sets as $tag_set_id) {
    //             if (isset($set_ids[$tag_set_id])) {
    //                 $term_relation_sql[] = "($set_ids[$tag_set_id], $tag_id, 0)";
    //                 $tag_sets_count++;
    //             }
    //         }
    //     }
    //     $termmeta_sql[] = "($tag_id, 'product_count_product_tag', $tag_sets_count)";
    //     $term_taxonomy_sql[] = "($tag_id, $tag_id, 'product_tag', '', 0, $tag_sets_count)";

    //     $sets_tag_relations_sql[] = "($tag_id, $set_tag->id)";
    // }

    rental_build_sets_tags_sql( $sets_tags, $cat_id, $similar_tags, $set_ids, $terms_sql, $termmeta_sql, $term_taxonomy_sql, $term_relation_sql, $sets_tag_relations_sql );

    // add the attributes data to the variables to insert to the required tables(terms, termmeta, term_taxonomy)
    $rental_img_attribute_value_rel = [];
    $attribute_value_relations_sql = [];
    foreach ($attr_val as $val) {
        $term_taxonomy_id = $val["term_taxonomy_id"];
        $attribute = $val["attribute_slug"];
        // value id -> term id map, which is what group membership resolves through
        $attribute_value_relations_sql[] = "($term_taxonomy_id, " . (int) $val["id"] . ", " .
            (int) $val["attribute_id"] . ", " . (int) $val["is_group"] . ")";
        $terms_sql[] = "($term_taxonomy_id, '" . addslashes($val["title"]) . "', '" . addslashes($val["slug"]) . "', 0)";
        $termmeta = "($term_taxonomy_id, 'order_pa_$attribute', 0)";
        if (defined('ZOO_CW_VERSION')) {
            if ($val["color"]) {
                $termmeta .= ", ($term_taxonomy_id, 'slctd_clr', '" . addslashes($val["color"]) . "')";
            } elseif ($val["img_id"]) {
                if (isset($rental_img_attribute_value_rel[$val["img_id"]])) {
                    $rental_img_attribute_value_rel[$val["img_id"]][] = $term_taxonomy_id;
                } else {
                    $rental_img_attribute_value_rel[$val["img_id"]] = [$term_taxonomy_id];
                }
            }
        }

        if (defined('RENTPRO_SWATCHES_PATH')) {
            if ($val["color"]) {
                $termmeta .= ", ($term_taxonomy_id, 'sw_color', '" . addslashes($val["color"]) . "')";
                $termmeta .= ", ($term_taxonomy_id, 'sw_tooltip', '" . addslashes($val["title"]) . "')";
            } else if ($val["img_id"]) {
                if (isset($rental_img_attribute_value_rel[$val["img_id"]])) {
                    $rental_img_attribute_value_rel[$val["img_id"]][] = $term_taxonomy_id;
                } else {
                    $rental_img_attribute_value_rel[$val["img_id"]] = [$term_taxonomy_id];
                }
                $termmeta .= ", ($term_taxonomy_id, 'sw_tooltip', '" . addslashes($val["title"]) . "')";
            }
        }
        
        if (defined('WOOF_PATH')) {
            require_once("includes/models/RTImage.php");
            $attribute_name = "pa_$attribute";
            $woof_settings = get_option('woof_settings');

            if (!empty($woof_settings) && isset($woof_settings["tax_type"]) && isset($woof_settings["tax_type"][$attribute_name])) {
                $attribute_type = $woof_settings["tax_type"][$attribute_name];
                if ($attribute_type == "color") {
                    if ( !isset($woof_settings["color"])) {
                        $woof_settings["color"] = [$attribute_name => []];
                    } elseif ( !isset($woof_settings["color"][$attribute_name])) {
                        $woof_settings["color"][$attribute_name] = [];
                    }
                    $woof_settings["color"][$attribute_name][$val["slug"]] = $val["color"]?: "#000000";
                    update_option("woof_settings", $woof_settings);

                } elseif ($attribute_type == "image") {
                    $_image = "";
                    if ($val["img_id"]) {
                        $images = (new RTImage())->getImages([$val["img_id"]]);
                        if (isset($images[$val["img_id"]])) {
                            $_image = wp_get_attachment_image_url($images[$val["img_id"]]);
                        }
                    }

                    $_term_id = $wpdb->get_var("SELECT `term_id` FROM $wpdb->terms WHERE `name` = '$attribute_name' AND `slug` = '".$val["slug"]."'");
                    if ( !isset($woof_settings["images_term_$_term_id"])) {
                        $woof_settings["images_term_$_term_id"] = [];
                    }
                    $woof_settings["images_term_$_term_id"]["image_url"] = $_image;
                    update_option("woof_settings", $woof_settings);
                }
                
            }
        }

        $termmeta_sql[] = $termmeta;
        $term_taxonomy_sql[] = "($term_taxonomy_id, $term_taxonomy_id, 'pa_$attribute', '', 0, " . count($val["object_id"]) . ")";
        // relate products and attributes with term_relation table
        foreach ($val["object_id"] as $object_id) {
            $term_relation_sql[] = "($object_id, $term_taxonomy_id, 0)";
        }
    }

    // save image attribute value relation in session
    update_option('rental_img_attribute_value_rel', $rental_img_attribute_value_rel);

    if (!get_option('rental_do_not_use_rentopian_shipping')) {
        
        /**
         * Shipping settings
         */
        // if ($shipping_settings->shipping_by === 'mile' || $shipping_settings->shipping_by === 'kilometre') {

        //     $shipping_title = $shipping_settings->shipping_by === 'mile' ? 'Miles' : 'Kilometre';
        //     $shipping_label = $shipping_title . ' Based Shipping';
        //     if ($rental_miles_shipping_label_text = get_option('rental_miles_shipping_label_text', '')) {
        //         $shipping_label = $rental_miles_shipping_label_text;
        //     }

        //     update_option('woocommerce_miles_based_settings', array(
        //         'enabled'           =>  'yes',
        //         'title'             =>  $shipping_label,
        //         'first_miles'       =>  $shipping_settings->min_miles,
        //         'first_miles_rate'  =>  $shipping_settings->min_miles_cost,
        //         'per_mile_rate'     =>  $shipping_settings->per_mile_cost,
        //     ));
        //     update_option('woocommerce_enable_shipping_calc', 'no');
        //     if (isset($shipping_settings->google_map_key) && $shipping_settings->google_map_key) {
        //         update_option('rental_google_distance_key', $shipping_settings->google_map_key);
        //     }

        // } else {

            // update_option('woocommerce_miles_based_settings', array(
            //     'enabled'           =>  'no',
            // ));
            // $shipping_zones = rental_curl('shipping/zones', $api_key);

            // woocommerce shipping zone related tables
            // $woocommerce_shipping_zones = $wpdb->prefix . 'woocommerce_shipping_zones';
            // $woocommerce_shipping_zone_locations = $wpdb->prefix . 'woocommerce_shipping_zone_locations';
            // $woocommerce_shipping_zone_methods = $wpdb->prefix . 'woocommerce_shipping_zone_methods';

            // if ( !empty($shipping_zones)) {

                /** Arrays to hold shipping zone related sqls */
                // $shipping_zone_sql = [];
                // $shipping_zone_locations_sql = [];
                // $shipping_zone_methods_sql = [];
                // $shipping_zone_options_sql = [];
                // $shipping_zone_relations_sql = [];

                /**
                 * Shipping zone entry in database
                 */
                // foreach ($shipping_zones as $zone) {
                //     $shipping_zone_sql[] = "($zone->id, '" . $zone->country . "', 0)";

                //     if ($zone->state) {
                //         $shipping_zone_locations_sql[] = "($zone->id, '{$zone->country}:{$zone->state}', 'state')";
                //     } else {
                //         $shipping_zone_locations_sql[] = "($zone->id, '$zone->country', 'country')";
                //     }
                //     if ( !empty($zone->zip)) {
                //         if (is_array($zone->zip)) {
                //             foreach ($zone->zip as $postcode) {
                //                 $shipping_zone_locations_sql[] = "($zone->id, '$postcode', 'postcode')";
                //             }
                //         } else {
                //             $shipping_zone_locations_sql[] = "($zone->id, '$zone->zip', 'postcode')";
                //         }
                //     }

                //     $shipping_zone_methods_sql[] = "($zone->id, $zone->id, 'reduced_rate', 1, 1)";

                //     $shipping_zone_options_sql[] = "('woocommerce_reduced_rate_" . $zone->id . "_settings', '" . addslashes(serialize([
                //             'min_order_price' => $zone->min_order_price,
                //             'shipping_rate' => $zone->shipping_rate,
                //             'regular_shipping_rate' => $zone->regular_shipping_rate
                //         ])) . "')";

                //     $shipping_zone_relations_sql[] = "($zone->id, $zone->id, $zone->division_id)";
                // }

                /**
                 * shipping zone relations insert queries
                 */
                // query to insert shipping zone
                // $wpdb->query("INSERT INTO `$woocommerce_shipping_zones` (`zone_id`, `zone_name`, `zone_order`) VALUES " . implode(", ", $shipping_zone_sql));
                // // query to insert shipping zone locations
                // $wpdb->query("INSERT INTO `$woocommerce_shipping_zone_locations` (`zone_id`, `location_code`, `location_type`) VALUES " . implode(", ", $shipping_zone_locations_sql));
                // // query to insert shipping zone methods
                // $wpdb->query("INSERT INTO `$woocommerce_shipping_zone_methods` (`zone_id`, `instance_id`, `method_id`, `method_order`, `is_enabled`) VALUES " . implode(", ", $shipping_zone_methods_sql));
                // // query to insert shipping zone options
                // $wpdb->query("INSERT INTO `$wpdb->options` (`option_name`, `option_value`) VALUES " . implode(", ", $shipping_zone_options_sql));
                // // query to insert shipping zone relations
                // $wpdb->query("INSERT INTO `$rental_shipping_zone_relations` (`id`, `rental_id`, `rental_division_id`) VALUES " . implode(", ", $shipping_zone_relations_sql));

                // if ($wpdb->last_error !== '') {
                //     throw new RentalException("SQL_ERROR: $wpdb->last_error (SQL: $wpdb->last_query)", RentalException::TYPE_SYNC_GLOBAL);
                // }


                
            // } else {
                // local pickup address (not based on zone nor mile)
        
                // insert Local Pickup shipping zone
                // $wpdb->insert($woocommerce_shipping_zones, [
                //     "zone_name" => 'Local Pickup',
                //     "zone_order" => 9999
                // ]);
                // $last_zone_id = $wpdb->insert_id;
                
                // foreach($divisions_synced as $division) {
                //     if ($division->main_division == 1) {
                //         $address = $division->address;

                //         // handle country/state
                //         $country = empty($address->country) ? 'US' : $address->country;
                //         $state = empty($address->state) ? '' : $address->state;
                //         if (empty($state)) {
                //             // insert only country
                //             $wpdb->insert($woocommerce_shipping_zone_locations, [
                //                 "zone_id" => $last_zone_id,
                //                 "location_code" => $country,
                //                 "location_type" => 'country'
                //             ]);
                //         } else {
                //             // insert country-state
                //             $country_state = $country.':'.$state;
                //             $wpdb->insert($woocommerce_shipping_zone_locations, [
                //                 "zone_id" => $last_zone_id,
                //                 "location_code" => $country_state,
                //                 "location_type" => 'state'
                //             ]);
                //         }

                //         // insert zip code
                //         // if (!empty($address->zip)) {
                //         //     $wpdb->insert($woocommerce_shipping_zone_locations, [
                //         //         "zone_id" => $last_zone_id,
                //         //         "location_code" => $address->zip,
                //         //         "location_type" => 'postcode'
                //         //     ]);
                //         // }


                //         // insert methods
                //         $wpdb->insert($woocommerce_shipping_zone_methods, [
                //             "zone_id" => $last_zone_id,
                //             "method_id" => 'local_pickup',
                //             "method_order" => 1,
                //             "is_enabled" => 1
                //         ]);
                        
                //     }
                // }
                
            // }
        // }

        // to bypass shipping rate cache
        // update_option('woocommerce_shipping_debug_mode', 'yes');
    }

    rental_insert("INSERT INTO `$wpdb->terms` (`term_id`, `name`, `slug`, `term_group`) VALUES", $terms_sql);

    rental_insert("INSERT INTO `$wpdb->termmeta` (`term_id`, `meta_key`, `meta_value`) VALUES", $termmeta_sql);

    rental_insert("INSERT INTO `$wpdb->term_taxonomy` (`term_taxonomy_id`, `term_id`, `taxonomy`, `description`, `parent`, `count`) VALUES", $term_taxonomy_sql);

    rental_insert("INSERT INTO `$wpdb->term_relationships` (`object_id`, `term_taxonomy_id`, `term_order`) VALUES", $term_relation_sql, 800);

    rental_insert("INSERT INTO `$wpdb->posts` (`id`, `post_author`, `post_date`,
                `post_date_gmt`, `post_title`, `post_content`, `post_excerpt`, `post_status`,
                `comment_status`, `ping_status`, `post_name`, `to_ping`, `pinged`, `post_modified`,
                `post_modified_gmt`, `post_content_filtered`, `post_parent`, `guid`,`post_type`) VALUES", $product_sql, 500);
    rental_insert("INSERT INTO `$wpdb->posts` (`id`, `post_author`, `post_date`,
                `post_date_gmt`, `post_title`, `post_content`, `post_excerpt`, `post_status`,
                `comment_status`, `ping_status`, `post_name`, `to_ping`, `pinged`, `post_modified`,
                `post_modified_gmt`, `post_content_filtered`, `post_parent`, `guid`,`post_type`) VALUES", $variant_sql, 500);

    rental_insert("INSERT INTO `$wpdb->postmeta` (`post_id`, `meta_key`, `meta_value`) VALUES", $productmeta_sql, 300);
    rental_insert("INSERT INTO `$wpdb->postmeta` (`post_id`, `meta_key`, `meta_value`) VALUES", $variantmeta_sql, 300);

    if (class_exists('Rental_Attribute_Groups')) {
        Rental_Attribute_Groups::ensure_tables();
        $rental_attribute_value_relations = $wpdb->prefix . $rental_tables["attribute_value_relations"];
        rental_insert("INSERT INTO `$rental_attribute_value_relations` (`id`, `rental_id`, `rental_attribute_id`, `is_group`) VALUES",
            $attribute_value_relations_sql, 800);

        if (is_array($attribute_value_groups)) {
            Rental_Attribute_Groups::replace_all_membership($attribute_value_groups);
        }
    }
    unset($attribute_value_relations_sql, $attribute_value_groups);

    rental_insert("INSERT INTO `$rental_product_relations` (`id`, `rental_id`, `rental_division_id`) VALUES", $product_relations_sql, 800);

    rental_insert("INSERT INTO `$rental_variant_relations` (`id`, `rental_id`, `rental_division_id`) VALUES", $variant_relations_sql, 800);

    rental_insert("INSERT INTO `$rental_set_relations` (`id`, `rental_id`, `rental_division_id`) VALUES", $set_relations_sql, 800);

    if (isset($product_meta_lookup_sql)) {
        rental_insert("INSERT INTO `$wpdb->wc_product_meta_lookup` (`product_id`, `sku`, `virtual`, `downloadable`, " .
            "`min_price`, `max_price`, `onsale`, `stock_quantity`, `stock_status`, `tax_status`) VALUES", $product_meta_lookup_sql, 500);
    }

    // passing excluded product ids into coupon add/sync function
    $exclude_product_ids = "";
    if (!empty($coupons_excluded_product_ids)) {
        $exclude_product_ids = implode(",", array_unique($coupons_excluded_product_ids) );
    }

    // inserting WP coupons/rental_coupon_relations
    $last_insert_id = rental_add_coupons($set_id, $exclude_product_ids);

    // inserting rental_price_multipliers
    rental_add_price_multipliers();

    
    // sync product up sells and cross sells
    rental_set_product_up_sells_cross_sells($product_up_sells, $product_cross_sells, $product_ids_for_up_cross_sells);
    
    // sync sets up sells and cross sells
    rental_set_sets_up_sells_cross_sells($set_up_sells_from_sets, $set_up_sells_from_products,
    $set_cross_sells_from_sets, $set_cross_sells_from_products, 
    $set_ids , $product_ids_for_up_cross_sells);


    // inserting rental inventory blocks
    rental_add_inventory_blocks();

    $sql= "";
    if ( !empty($category_relations_sql)) {
        $category_relations_sql = implode(", ", $category_relations_sql);
        $sql .= "INSERT INTO `$rental_category_relations` (`id`, `rental_id`) VALUES $category_relations_sql;";
    }
    if ( !empty($tag_relations_sql)) {
        $tag_relations_sql = implode(", ", $tag_relations_sql);
        $sql .= "INSERT INTO `$rental_tag_relations` (`id`, `rental_id`) VALUES $tag_relations_sql;";
    }

    if ( !empty($sets_tag_relations_sql)) {
        $sets_tag_relations_sql = implode(", ", $sets_tag_relations_sql);
        $sql .= "INSERT INTO `$rental_sets_tag_relations` (`id`, `rental_id`) VALUES $sets_tag_relations_sql;";
    }

    if ( !empty($brand_relations_sql)) {
        $brand_relations_sql = implode(", ", $brand_relations_sql);
        $sql .= "INSERT INTO `$rental_brand_relations` (`id`, `rental_id`) VALUES $brand_relations_sql;";
    }
    dbDelta($sql);

    // inserting rental product options
    rental_add_product_options();
    // inserting rental inventory sets options
    rental_add_set_options();

    rental_set_no_customer_pickup_items($shipping_settings);

    $delivery_time_selections = isset($shipping_settings->delivery_time_selections) && $shipping_settings->delivery_time_selections ? $shipping_settings->delivery_time_selections : [];
    update_option('delivery_time_selections', $delivery_time_selections);

    $no_index_items = [];
    if ($products_to_hide) {

        $no_index_items = $products_to_hide;

        foreach($products_to_hide as $product_id) {
            rental_set_product_catalog_visibility($product_id, 'hidden');
        }
    }

    if ( class_exists( 'WooCommerce_Single_Variations' ) && defined('WC_SINGLE_VAR_VERSION')) {
        $admin_wcs_variation = new WooCommerce_Single_Variations_Admin('plugin', WC_SINGLE_VAR_VERSION);
        $admin_wcs_variation->init();
        $admin_wcs_variation->update_variations(false, true);
        $admin_wcs_variation->reset_transients(false);
    }

    if ($do_not_index_hidden_duplicate_products_for_seo && get_option('rental_location_based_duplicate_filter', 0)) {

        if ($no_index_items) {
            $no_index_items = array_merge($no_index_items, $duplicate_product_ids);
        } else {
            $no_index_items = $duplicate_product_ids;
        }
    } 

    if (get_option('rental_no_index_items', [])) {
        update_option('rental_no_index_items', []);
    }

    if (get_option('rental_not_private_items', [])) {
        update_option('rental_not_private_items', []);
    }


    if ($no_index_items) {
        update_option('rental_no_index_items', $no_index_items);
    }

    if ($set_items_collection) {
        update_option('rental_not_private_items', $set_items_collection);
    }


    variants_stock_to_be_updated($variants_to_update_qty);

    // unlock tables
    $sql = "UNLOCK TABLES;";
    dbDelta($sql);

    // sorting the categories after inserting them relations
    sort_categories(json_decode(stripslashes(json_encode($categories))), false);

    if ($wpdb->last_error !== '') {
        throw new RentalException("SQL_ERROR: $wpdb->last_error (SQL: $wpdb->last_query)", RentalException::TYPE_SYNC_GLOBAL);
    }

    do_action( 'rental_after_synchronization' );
    
    return true;
}

function variants_stock_to_be_updated($variants_to_update_qty) {
    if ($variants_to_update_qty && get_option('rental_location_based_duplicate_filter', 0)) {
        global $wpdb, $rental_tables;
        $rental_product_relations = $wpdb->prefix . $rental_tables["product_relations"];

        foreach($variants_to_update_qty as $variant_data) {

            if (isset($variant_data['variant_id']) && $variant_data['variant_id'] 
                && isset($variant_data['qty']) && $variant_data['qty']
            ) {

                update_post_meta($variant_data['variant_id'], '_stock', $variant_data['qty']);

                $wpdb->update( $wpdb->wc_product_meta_lookup, [ 
                    'stock_quantity' => $variant_data['qty']
                ], ['product_id' => $variant_data['variant_id']] );
            }
           
            
            if (
                isset($variant_data['rental_product_id']) && $variant_data['rental_product_id'] 
                && isset($variant_data['division_id']) && $variant_data['division_id']
                && isset($variant_data['qty']) && $variant_data['qty']
            ) {

                $rental_product_id = $variant_data['rental_product_id'];
                $division_id = $variant_data['division_id'];

                $pid = $wpdb->get_var("SELECT `id` FROM $rental_product_relations WHERE `rental_id` = $rental_product_id AND `rental_division_id` = $division_id");

                if ($pid) {
                    update_post_meta($pid, '_stock', $variant_data['qty']);
    
                    $wpdb->update( $wpdb->wc_product_meta_lookup, [ 
                        'stock_quantity' => $variant_data['qty']
                    ], ['product_id' => $pid] );
                }
            }
           
        }
    }
}

function toggle_index_yoast_seo($product_id, $do_not_index = 1) {
    
    if (defined('WPSEO_VERSION')) {
        global $wpdb;
        $yoast_indexable = $wpdb->prefix . 'yoast_indexable';

        $meta_key_index = '_yoast_wpseo_meta-robots-noindex';
        $meta_key_follow = '_yoast_wpseo_meta-robots-nofollow';

        if ($wpdb->get_var( "SHOW TABLES LIKE '{$yoast_indexable}'" ) == $yoast_indexable) {

            if ($do_not_index) {

                $updatable_data = [
                    'is_robots_noindex' => 1,
                    'is_robots_nofollow' => 1,
                    'is_robots_noarchive' => 1,
                    'is_robots_noimageindex' => 1,
                    'is_robots_nosnippet' => 1,
                ];
                $wpdb->update($yoast_indexable, $updatable_data, ['object_id' => $product_id]);

                // Set noindex (1 = noindex, 0 = index)
                update_post_meta($product_id, $meta_key_index, '1');
                
                // Set nofollow (1 = nofollow, 0 = follow)
                update_post_meta($product_id, $meta_key_follow, '1');
        
            } else {
        
                $updatable_data = [
                    'is_robots_noindex' => null,
                    'is_robots_nofollow' => null,
                    'is_robots_noarchive' => null,
                    'is_robots_noimageindex' => null,
                    'is_robots_nosnippet' => null,
                ];
                $wpdb->update($yoast_indexable, $updatable_data, ['object_id' => $product_id]);

                // Set noindex (1 = noindex, 0 = index)
                update_post_meta($product_id, $meta_key_index, '0');
                
                // Set nofollow (1 = nofollow, 0 = follow)
                update_post_meta($product_id, $meta_key_follow, '0');
            }

        }
    }
}

function sort_categories($categories, $from_api = true) {

    if ($categories) {

        global $wpdb, $rental_tables;
        $rental_category_relations = $wpdb->prefix . $rental_tables["category_relations"];

        if ($from_api) {

            foreach($categories as $category_id => $category) {
                $cat_id = $wpdb->get_var($wpdb->prepare( "SELECT `id` FROM $rental_category_relations WHERE `rental_id` = %d", $category_id ));
                resort_categories($cat_id, $category, $wpdb, $rental_category_relations);
            }

        } else {

            foreach($categories as $category) {
                $cat_id = $wpdb->get_var($wpdb->prepare( "SELECT `id` FROM $rental_category_relations WHERE `rental_id` = %d", $category->id ));
                resort_categories($cat_id, $category, $wpdb, $rental_category_relations);
            }

        }
        
    }

}

function resort_categories($cat_id, $category, $wpdb, $rental_category_relations) {
    if ($cat_id) {
        // check if the category has a parent
        $parent = 0;
        if (isset($category->parent_id)) {
            $parent_id = $wpdb->get_var($wpdb->prepare("SELECT `id` FROM $rental_category_relations WHERE `rental_id` = %d", $category->parent_id));
            if ($parent_id) {
                $parent = $parent_id;
            }
        }

        // update category's order and parent
        wp_update_term($cat_id, 'product_cat', ['parent' => $parent]);
        update_term_meta($cat_id, "order", $category->order);
    }
}

// set product upsell / crosssell ids
function rental_set_product_up_sells_cross_sells($product_up_sells, $product_cross_sells, $product_ids_for_up_cross_sells) {

    // upsells
    foreach($product_up_sells as $wp_product_id=>$rental_up_sell_pack) {

        if ($rental_up_sell_pack) {
            // collect wp upsell product ids
            $wp_up_sell_pack = [];
            foreach($rental_up_sell_pack as $rental_product_id) {
                if (isset($product_ids_for_up_cross_sells[$rental_product_id]) ) {

                    if (count($product_ids_for_up_cross_sells[$rental_product_id]) > 1) {
                        // multiple division

                        foreach($product_ids_for_up_cross_sells[$rental_product_id] as $wp_product_id_with_up_sells) {

                            if (!in_array($wp_product_id_with_up_sells, $wp_up_sell_pack)) {
                                $wp_up_sell_pack[] = $wp_product_id_with_up_sells;
                            }
                        }

                    } else {
                        // single division

                        $wp_product_id_with_up_sells = isset($product_ids_for_up_cross_sells[$rental_product_id][0]) ? $product_ids_for_up_cross_sells[$rental_product_id][0] : 0;
                        
                        if ($wp_product_id_with_up_sells && !in_array($wp_product_id_with_up_sells, $wp_up_sell_pack)) {
                            $wp_up_sell_pack[] = $wp_product_id_with_up_sells;
                        }
                    }
                }
            }

            wp_set_object_terms($wp_product_id, $wp_up_sell_pack, '_upsell_ids');
            update_post_meta($wp_product_id, '_upsell_ids', $wp_up_sell_pack);
        } else {

            // remove all
            wp_set_object_terms($wp_product_id, [], '_upsell_ids');
            update_post_meta($wp_product_id, '_upsell_ids', []);
        }
    }

    // crosssells
    foreach($product_cross_sells as $wp_product_id=>$rental_cross_sell_pack) {

        if ($rental_cross_sell_pack) {
            // collect wp upsell product ids
            $wp_cross_sell_pack = [];
            foreach($rental_cross_sell_pack as $rental_product_id) {
                if (isset($product_ids_for_up_cross_sells[$rental_product_id]) ) {

                    if (count($product_ids_for_up_cross_sells[$rental_product_id]) > 1) {
                        // multiple division

                        foreach($product_ids_for_up_cross_sells[$rental_product_id] as $wp_product_id_with_cross_sells) {

                            if (!in_array($wp_product_id_with_cross_sells, $wp_cross_sell_pack)) {
                                $wp_cross_sell_pack[] = $wp_product_id_with_cross_sells;
                            }
                        }

                    } else {
                        // single division

                        $wp_product_id_with_cross_sells = isset($product_ids_for_up_cross_sells[$rental_product_id][0]) ? $product_ids_for_up_cross_sells[$rental_product_id][0] : 0;
                        
                        if ($wp_product_id_with_cross_sells && !in_array($wp_product_id_with_cross_sells, $wp_cross_sell_pack)) {
                            $wp_cross_sell_pack[] = $wp_product_id_with_cross_sells;
                        }
                    }
                }
            }

            wp_set_object_terms($wp_product_id, $wp_cross_sell_pack, '_crosssell_ids');
            update_post_meta($wp_product_id, '_crosssell_ids', $wp_cross_sell_pack);
        } else {

            // remove all
            wp_set_object_terms($wp_product_id, [], '_crosssell_ids');
            update_post_meta($wp_product_id, '_crosssell_ids', []);
        }
    }
}


// set product upsell / crosssell ids
function rental_set_product_up_sells_cross_sells_for_api($product_up_sells, $product_cross_sells) {

    // collecting & creating product ids lookup table
    if (!empty($product_up_sells) || !empty($product_cross_sells)) {
        global $wpdb, $rental_tables;
        $rental_product_relations = $wpdb->prefix . $rental_tables["product_relations"];

        $product_ids = $wpdb->get_results("SELECT `id`, `rental_id` FROM $rental_product_relations");
        $product_ids_look_up_table = [];
        foreach($product_ids as $product_row) {
            $product_ids_look_up_table[$product_row->rental_id] = $product_row->id;
        }
    }

    // upsells
    foreach($product_up_sells as $wp_product_id=>$rental_up_sell_pack) {

        if (!empty($rental_up_sell_pack)) {
            // collect wp upsell product ids
            $wp_up_sell_pack = [];
            foreach($rental_up_sell_pack as $rental_product_id) {
                if (isset($product_ids_look_up_table[$rental_product_id]) ) {
                    $wp_upsell_product_id = $product_ids_look_up_table[$rental_product_id];
                    if (!in_array($wp_upsell_product_id, $wp_up_sell_pack)) {
                        $wp_up_sell_pack[] = $wp_upsell_product_id;
                    }
                }
            }

            wp_set_object_terms($wp_product_id, $wp_up_sell_pack, '_upsell_ids');
            update_post_meta($wp_product_id, '_upsell_ids', $wp_up_sell_pack);
        } else {
            // remove all
            wp_set_object_terms($wp_product_id, [], '_upsell_ids');
            update_post_meta($wp_product_id, '_upsell_ids', []);
        }

    }

    // crosssells
    foreach($product_cross_sells as $wp_product_id=>$rental_cross_sell_pack) {

        if (!empty($rental_cross_sell_pack)) {
            // collect wp upsell product ids
            $wp_cross_sell_pack = [];
            foreach($rental_cross_sell_pack as $rental_product_id) {
                if (isset($product_ids_look_up_table[$rental_product_id]) ) {
                    $wp_cross_sell_product_id = $product_ids_look_up_table[$rental_product_id];
                    if (!in_array($wp_cross_sell_product_id, $wp_cross_sell_pack)) {
                        $wp_cross_sell_pack[] = $wp_cross_sell_product_id;
                    }
                }
            }

            wp_set_object_terms($wp_product_id, $wp_cross_sell_pack, '_crosssell_ids');
            update_post_meta($wp_product_id, '_crosssell_ids', $wp_cross_sell_pack);
        } else {
            // remove all
            wp_set_object_terms($wp_product_id, [], '_crosssell_ids');
            update_post_meta($wp_product_id, '_crosssell_ids', []);
        }

    }

}


// function rental_set_sets_up_sells_cross_sells($set_up_sells_from_sets, $set_up_sells_from_products,
//     $set_cross_sells_from_sets, $set_cross_sells_from_products, 
//     $set_ids , $product_ids_for_up_cross_sells) {

//     // upsells
//     $wp_up_sells_pack = [];
//     foreach($set_up_sells_from_sets as $wp_set_id => $rental_up_sell_pack) {

//         // remove all
//         wp_set_object_terms($wp_set_id, [], '_upsell_ids');
//         update_post_meta($wp_set_id, '_upsell_ids', []);

//         if ($rental_up_sell_pack) {
//             // collect wp upsell set ids
//             $wp_set_up_sell_pack = [];
//             foreach($rental_up_sell_pack as $rental_set_id) {
//                 if (isset($set_ids[$rental_set_id]) ) {

//                     $wp_set_id_with_up_sells = $set_ids[$rental_set_id];
                    
//                     if (!in_array($wp_set_id_with_up_sells, $wp_set_up_sell_pack)) {
//                         $wp_set_up_sell_pack[] = $wp_set_id_with_up_sells;
//                     }
//                 }
//             }

//             $wp_up_sells_pack[$wp_set_id] = $wp_set_up_sell_pack;
//         }
//     }

//     foreach($set_up_sells_from_products as $wp_set_id => $rental_up_sell_pack) {

//         // remove all
//         wp_set_object_terms($wp_set_id, [], '_upsell_ids');
//         update_post_meta($wp_set_id, '_upsell_ids', []);

//         $wp_product_up_sell_pack = [];
//         if ($rental_up_sell_pack) {
//             // collect wp upsell product ids
//             foreach($rental_up_sell_pack as $rental_product_id) {
//                 if (isset($product_ids_for_up_cross_sells[$rental_product_id]) ) {

//                     if (count($product_ids_for_up_cross_sells[$rental_product_id]) > 1) {
//                         // multiple division

//                         foreach($product_ids_for_up_cross_sells[$rental_product_id] as $wp_product_id_with_up_sells) {

//                             if (!in_array($wp_product_id_with_up_sells, $wp_product_up_sell_pack)) {
//                                 $wp_product_up_sell_pack[] = $wp_product_id_with_up_sells;
//                             }
//                         }

//                     } else {
//                         // single division

//                         $wp_product_id_with_up_sells = isset($product_ids_for_up_cross_sells[$rental_product_id][0]) ? $product_ids_for_up_cross_sells[$rental_product_id][0] : 0;

//                         if ($wp_product_id_with_up_sells && !in_array($wp_product_id_with_up_sells, $wp_product_up_sell_pack)) {
//                             $wp_product_up_sell_pack[] = $wp_product_id_with_up_sells;
//                         }
//                     }
//                 }
//             }
//         }


//         if ($wp_product_up_sell_pack) {
//             if (isset($wp_up_sells_pack[$wp_set_id]) && $wp_up_sells_pack[$wp_set_id]) {
//                 $wp_up_sells_pack[$wp_set_id] = array_merge($wp_up_sells_pack[$wp_set_id], $wp_product_up_sell_pack);
//             } else {
//                 $wp_up_sells_pack[$wp_set_id] = $wp_product_up_sell_pack;
//             }
//         }
            
//         if (isset($wp_up_sells_pack[$wp_set_id]) && $wp_up_sells_pack[$wp_set_id]) {
//             wp_set_object_terms($wp_set_id, $wp_up_sells_pack[$wp_set_id], '_upsell_ids');
//             update_post_meta($wp_set_id, '_upsell_ids', $wp_up_sells_pack[$wp_set_id]);
//         }
//     }

    
//     // cross sells
//     $wp_cross_sells_pack = [];
//     foreach($set_cross_sells_from_sets as $wp_set_id => $rental_cross_sell_pack) {

//         // remove all
//         wp_set_object_terms($wp_set_id, [], '_crosssell_ids');
//         update_post_meta($wp_set_id, '_crosssell_ids', []);

//         if ($rental_cross_sell_pack) {
//             // collect wp upsell set ids
//             $wp_cross_sells_pack = [];
//             foreach($rental_cross_sell_pack as $rental_set_id) {
//                 if (isset($set_ids[$rental_set_id]) ) {

//                     $wp_set_id_with_cross_sells = $set_ids[$rental_set_id];
                    
//                     if (!in_array($wp_set_id_with_cross_sells, $wp_cross_sells_pack)) {
//                         $wp_cross_sells_pack[] = $wp_set_id_with_cross_sells;
//                     }
//                 }
//             }

//             $wp_cross_sells_pack[$wp_set_id] = $wp_cross_sells_pack;

//         }
//     }


//     foreach($set_cross_sells_from_products as $wp_set_id => $rental_cross_sell_pack) {

//         // remove all
//         wp_set_object_terms($wp_set_id, [], '_crosssell_ids');
//         update_post_meta($wp_set_id, '_crosssell_ids', []);

//         $wp_product_cross_sell_pack = [];
//         if ($rental_cross_sell_pack) {
//             // collect wp upsell product ids
//             foreach($rental_cross_sell_pack as $rental_product_id) {
//                 if (isset($product_ids_for_up_cross_sells[$rental_product_id]) ) {

//                     if (count($product_ids_for_up_cross_sells[$rental_product_id]) > 1) {
//                         // multiple division

//                         foreach($product_ids_for_up_cross_sells[$rental_product_id] as $wp_product_id_with_cross_sells) {

//                             if (!in_array($wp_product_id_with_cross_sells, $wp_product_cross_sell_pack)) {
//                                 $wp_product_cross_sell_pack[] = $wp_product_id_with_cross_sells;
//                             }
//                         }

//                     } else {
//                         // single division

//                         $wp_product_id_with_cross_sells = isset($product_ids_for_up_cross_sells[$rental_product_id][0]) ? $product_ids_for_up_cross_sells[$rental_product_id][0] : 0;

//                         if ($wp_product_id_with_cross_sells && !in_array($wp_product_id_with_cross_sells, $wp_product_cross_sell_pack)) {
//                             $wp_product_cross_sell_pack[] = $wp_product_id_with_cross_sells;
//                         }
//                     }
//                 }
//             }
//         }

//         if ($wp_product_cross_sell_pack) {
//             if (isset($wp_cross_sells_pack[$wp_set_id]) && $wp_cross_sells_pack[$wp_set_id]) {
//                 $wp_cross_sells_pack[$wp_set_id] = array_merge($wp_cross_sells_pack[$wp_set_id], $wp_product_cross_sell_pack);
//             } else {
//                 $wp_cross_sells_pack[$wp_set_id] = $wp_product_cross_sell_pack;
//             }
//         }
            
//         if (isset($wp_cross_sells_pack[$wp_set_id]) && $wp_cross_sells_pack[$wp_set_id]) {
//             wp_set_object_terms($wp_set_id, $wp_cross_sells_pack[$wp_set_id], '_crosssell_ids');
//             update_post_meta($wp_set_id, '_crosssell_ids', $wp_cross_sells_pack[$wp_set_id]);
//         }
//     }

// }

function getDayNumberByDayName($day_name, $rentopian_system = true) {
    
    if ($rentopian_system) {

        $days = [
            'Monday' => 1,
            'Tuesday' => 2,
            'Wednesday' => 3,
            'Thursday' => 4,
            'Friday' => 5,
            'Saturday' => 6,
            'Sunday' => 7,
        ];
    } else {

        // Plugin system
        $days = [
            'Monday' => 1,
            'Tuesday' => 2,
            'Wednesday' => 3,
            'Thursday' => 4,
            'Friday' => 5,
            'Saturday' => 6,
            'Sunday' => 0,
        ];
    }
   
    return $days[$day_name] ?? -1;
}

function getDayNameByNumber($day_num, $rentopian_system = true) {

    if ($rentopian_system) {

        $days = [
            // '0' => 'Everyday',
            '1' => 'Monday',
            '2' => 'Tuesday',
            '3' => 'Wednesday',
            '4' => 'Thursday',
            '5' => 'Friday',
            '6' => 'Saturday',
            '7' => 'Sunday'
        ];

    } else {

        // Plugin system
        $days = [
            '0' => 'Sunday',
            '1' => 'Monday',
            '2' => 'Tuesday',
            '3' => 'Wednesday',
            '4' => 'Thursday',
            '5' => 'Friday',
            '6' => 'Saturday',
        ];
    }
   
    return $days[$day_num] ?? '';
}

/**
 * Check if event date offset mode is active.
 * Offsets are only active when:
 *   1. At least one offset is greater than 0
 *   2. The end date is hidden (rental_hide_end_date)
 *   3. The zip code is hidden (rental_hide_zip)
 *
 * @return array|false  Returns offsets array or false if not active.
 */
function rental_get_event_date_offsets() {
    if (!get_option('rental_hide_end_date') || !get_option('rental_hide_zip')) {
        return false;
    }

    $start_offset = (int) get_option('rental_start_date_offset', 0);
    $end_offset   = (int) get_option('rental_end_date_offset', 0);

    if ($start_offset > 0 || $end_offset > 0) {
        return array(
            'start_date_offset' => $start_offset,
            'end_date_offset'   => $end_offset,
        );
    }
    return false;
}

/**
 * Decrypted rental_event_date cookie when event-offset mode is on — use to rehydrate date pickers (not rental_start_date).
 *
 * @return string Empty when unavailable or invalid.
 */
function rental_get_decrypted_event_date_for_display() {
    if ( ! function_exists( 'rental_get_event_date_offsets' ) || ! rental_get_event_date_offsets() ) {
        return '';
    }
    if ( empty( $_COOKIE['rental_event_date'] ) || ! function_exists( 'decrypt_data' ) ) {
        return '';
    }
    $ek = get_option( 'rental_encryption_key' );
    if ( ! $ek ) {
        return '';
    }
    $raw = decrypt_data( wp_unslash( $_COOKIE['rental_event_date'] ), $ek );
    if ( ! is_string( $raw ) || $raw === '' || false === strtotime( $raw ) ) {
        return '';
    }
    return $raw;
}

/**
 * Build Unix timestamp for API order_exact_date from decrypted rental_event_date.
 * Parses in Rentopian product timezone (rental_get_timezone) when set — matches Core system division;
 * otherwise falls back to wp_timezone().
 * If time pickers are on but the string has no time, uses rental_default_start_time.
 *
 * @param string $event_date_val Decrypted rental_event_date.
 * @return int|false
 */
function rental_event_string_to_order_exact_timestamp( $event_date_val ) {
    if ( ! is_string( $event_date_val ) || trim( $event_date_val ) === '' ) {
        return false;
    }

    $s = trim( $event_date_val );
    $tz = null;
    if ( function_exists( 'rental_get_timezone' ) ) {
        $tz = rental_get_timezone();
    }
    if ( ! $tz instanceof DateTimeZone ) {
        $tz = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' );
    }
    $hide_time = (bool) get_option( 'rental_hide_time_pickers' );
    $default_start = trim( (string) get_option( 'rental_default_start_time', '9:00 AM' ) );

    $formats_with_time = array(
        'Y/m/d g:i A',
        'Y/m/d h:i A',
        'Y/n/j g:i A',
        'Y/n/j h:i A',
        'M j, Y g:i A',
        'M d, Y g:i A',
    );

    foreach ( $formats_with_time as $fmt ) {
        $dt = DateTimeImmutable::createFromFormat( $fmt, $s, $tz );
        if ( $dt instanceof DateTimeImmutable ) {
            $err = DateTimeImmutable::getLastErrors();
            $ec  = isset( $err['error_count'] ) ? (int) $err['error_count'] : 0;
            $wc  = isset( $err['warning_count'] ) ? (int) $err['warning_count'] : 0;
            if ( 0 === $ec && 0 === $wc ) {
                return $dt->getTimestamp();
            }
        }
    }

    $date_only_formats = array( 'Y/m/d', 'Y/n/j', 'M j, Y', 'M d, Y' );
    foreach ( $date_only_formats as $fmt ) {
        $dt = DateTimeImmutable::createFromFormat( $fmt, $s, $tz );
        if ( $dt instanceof DateTimeImmutable ) {
            $err = DateTimeImmutable::getLastErrors();
            $ec  = isset( $err['error_count'] ) ? (int) $err['error_count'] : 0;
            $wc  = isset( $err['warning_count'] ) ? (int) $err['warning_count'] : 0;
            if ( 0 === $ec && 0 === $wc ) {
                if ( ! $hide_time && $default_start !== '' ) {
                    $t = DateTimeImmutable::createFromFormat( 'g:i A', $default_start, $tz );
                    if ( ! $t ) {
                        $t = DateTimeImmutable::createFromFormat( 'h:i A', $default_start, $tz );
                    }
                    if ( $t instanceof DateTimeImmutable ) {
                        $dt = $dt->setTime( (int) $t->format( 'G' ), (int) $t->format( 'i' ), 0 );
                    }
                }
                return $dt->getTimestamp();
            }
        }
    }

    $prev_tz = date_default_timezone_get();
    $tz_name = $tz->getName();
    if ( ! ( $tz_name && date_default_timezone_set( $tz_name ) ) ) {
        if ( function_exists( 'wp_timezone_string' ) && wp_timezone_string() ) {
            date_default_timezone_set( wp_timezone_string() );
        }
    }
    $ts = strtotime( $s );
    date_default_timezone_set( $prev_tz );

    if ( false === $ts ) {
        return false;
    }

    // If strtotime returned midnight local and string had no time segment, apply default start (pickers on).
    if ( ! $hide_time && $default_start !== '' && false === strpos( $s, ':' ) ) {
        $mid = ( new DateTimeImmutable( '@' . $ts ) )->setTimezone( $tz );
        if ( '00:00:00' === $mid->format( 'H:i:s' ) ) {
            $t = DateTimeImmutable::createFromFormat( 'g:i A', $default_start, $tz );
            if ( ! $t ) {
                $t = DateTimeImmutable::createFromFormat( 'h:i A', $default_start, $tz );
            }
            if ( $t instanceof DateTimeImmutable ) {
                return $mid->setTime( (int) $t->format( 'G' ), (int) $t->format( 'i' ), 0 )->getTimestamp();
            }
        }
    }

    return $ts;
}


/**
 * Apply event date offsets to compute actual rental start/end dates.
 *
 * The user selects an "event date". The rental period is computed as:
 *   start = event_date - start_offset days (at default_start_time)
 *   end   = event_date + end_offset days   (at default_end_time)
 *
 * This mirrors the core Rentopian admin logic in orders.js:
 *   getStartDateTime(): momentDate.add(-getOrderStartDateOffset(), 'days')
 *   getEndDateTime():   momentDate.add(getOrderEndDateOffset(), 'days')
 *
 * @param string $event_date          The user-selected event date string.
 * @param int    $start_offset        Days to subtract for rental start.
 * @param int    $end_offset          Days to add for rental end.
 * @param string $default_start_time  Start time from settings (e.g. '8:00 AM').
 * @param string $default_end_time    End time from settings (e.g. '8:00 PM').
 * @return array  ['start_date' => string, 'end_date' => string, 'event_date' => string]
 */
function rental_apply_event_date_offsets($event_date, $start_offset, $end_offset, $default_start_time, $default_end_time) {
    $event_ts = strtotime($event_date);
    if ($event_ts === false) {
        return array(
            'start_date' => $event_date,
            'end_date'   => $event_date,
            'event_date' => $event_date,
        );
    }

    $event_date_only = date('Y/m/d', $event_ts);

    // Apply offsets: subtract start_offset days, add end_offset days
    $start_ts = strtotime("-{$start_offset} days", strtotime($event_date_only));
    $end_ts   = strtotime("+{$end_offset} days", strtotime($event_date_only));

    $start_date = date('Y/m/d', $start_ts) . ' ' . $default_start_time;
    $end_date   = date('Y/m/d', $end_ts) . ' ' . $default_end_time;

    return array(
        'start_date' => $start_date,
        'end_date'   => $end_date,
        'event_date' => $event_date_only,
    );
}

/**
 * Display line for the user-selected event date (checkout / summary when offsets are on).
 *
 * @param string $decrypted_event Raw decrypted rental_event_date value.
 * @param bool   $hide_time       Whether rental_hide_time_pickers is enabled.
 * @return string Plain text (not escaped).
 */
function rental_format_checkout_event_date_display_line( $decrypted_event, $hide_time ) {
    if ( ! is_string( $decrypted_event ) || $decrypted_event === '' ) {
        return '';
    }
    $ts = strtotime( $decrypted_event );
    if ( false === $ts ) {
        return '';
    }
    if ( $hide_time ) {
        return date_i18n( 'l, F jS, Y', $ts );
    }
    return date_i18n( 'l, F jS, Y g:i A', $ts );
}

/**
 * Markup for the computed rental period (checkout, emails, etc.). Uses weekday + ordinal date to match JS offset display.
 *
 * @param string $decrypted_rental_start Decrypted rental period start.
 * @param string $decrypted_rental_end   Decrypted rental period end.
 * @param bool   $hide_time              Whether rental_hide_time_pickers is enabled.
 * @param array  $args                   Optional: 'extra_class' (string), 'inline_style' (string).
 * @return string HTML fragment (empty if dates invalid).
 */
function rental_format_rental_period_markup( $decrypted_rental_start, $decrypted_rental_end, $hide_time, $args = array() ) {
    if ( ! is_string( $decrypted_rental_start ) || $decrypted_rental_start === '' ) {
        return '';
    }
    if ( ! is_string( $decrypted_rental_end ) || $decrypted_rental_end === '' ) {
        return '';
    }
    $ts_s = strtotime( $decrypted_rental_start );
    $ts_e = strtotime( $decrypted_rental_end );
    if ( false === $ts_s || false === $ts_e ) {
        return '';
    }
    $args = wp_parse_args(
        $args,
        array(
            'extra_class'   => 'rntp-checkout-rental-period',
            'inline_style'  => 'display:block',
        )
    );
    $fmt       = $hide_time ? 'l, F jS, Y' : 'l, F jS, Y g:i A';
    $start_txt = date_i18n( $fmt, $ts_s );
    $end_txt   = date_i18n( $fmt, $ts_e );
    $classes   = trim( 'rntp-rental-period-details order-dates-details ' . $args['extra_class'] );

    ob_start();
    ?>
    <blockquote class="<?php echo esc_attr( $classes ); ?>" style="<?php echo esc_attr( $args['inline_style'] ); ?>">
        <b><?php esc_html_e( 'Rental Period:', 'rentopian-sync' ); ?></b>
        <span class="rntp-rental-period-text"><?php echo esc_html( $start_txt . ' - ' . $end_txt ); ?></span>
    </blockquote>
    <?php
    return trim( ob_get_clean() );
}

/**
 * @deprecated Use rental_format_rental_period_markup() — alias for backward compatibility.
 */
function rental_format_checkout_rental_period_markup( $decrypted_rental_start, $decrypted_rental_end, $hide_time ) {
    return rental_format_rental_period_markup( $decrypted_rental_start, $decrypted_rental_end, $hide_time );
}

/**
 * Empty rental period container for front-end forms; JS fills .rntp-rental-period-text via RntpDateOffsets.updateDisplay().
 *
 * @param array $args {
 *     @type string $display CSS display value (default 'none').
 *     @type string $variant 'blockquote' (default) or 'modern_div'.
 * }
 */
function rental_render_rental_period_details_shell( $args = array()) {

    $type = '';
    if (isset($args['type']) && !empty($args['type'])) {
        $type = $args['type'];
    }

    $args = wp_parse_args(
        $args,
        array(
            'display' => 'none',
            'variant' => 'blockquote',
        )
    );
    $disp = $args['display'];

    if ( 'modern_div' === $args['variant'] ) {
        ?>
        <div class="rntp-rental-period-details order-dates-details" style="display:<?php echo esc_attr( $disp ); ?>;">
            <strong><?php esc_html_e( 'Rental Period:', 'rentopian-sync' ); ?></strong>
            <span class="rntp-rental-period-text"></span>
            <small class="rntp-offset-label" style="color:#888; margin-left:4px;"></small>
        </div>
        <?php
        return;
    }

    ?>

    <blockquote class="rntp-rental-period-details <?= $type == 'horizontal' ? 'rntp-rental-period-details-horizontal' : '' ?> order-dates-details" style="display:<?php echo esc_attr( $disp ); ?>;">
        <b><?php esc_html_e( 'Rental Period:', 'rentopian-sync' ); ?></b>
        <span class="rntp-rental-period-text"></span>
    </blockquote>
    <?php
}

/**
 * Timezone the store books in: the division timezone supplied by the core when
 * available, otherwise the WordPress timezone. Always returns a usable zone —
 * a bare gmt_offset such as "-8" is not a valid DateTimeZone name.
 *
 * @return DateTimeZone
 */
function rental_get_booking_timezone() {
    static $timezone = null;

    if ($timezone instanceof DateTimeZone) {
        return $timezone;
    }

    // Read the cached settings directly rather than through
    // rental_get_timezone(): that fetches them, and the fetch re-enters the
    // date seeding this resolver feeds.
    $product_settings = get_option('rental_product_settings');
    if (is_array($product_settings) && !empty($product_settings['timezone'])) {
        try {
            $timezone = new DateTimeZone($product_settings['timezone']);
            return $timezone;
        } catch (Exception $e) {
            // Fall through to the WordPress timezone.
        }
    }

    $timezone = function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone(date_default_timezone_get());

    return $timezone;
}

/**
 * Midnight today in the booking timezone. WordPress runs PHP in UTC, so a
 * plain strtotime('today') is the UTC day and can be a day ahead of the store.
 *
 * @return DateTime
 */
function rental_get_store_today() {
    $today = new DateTime('now', rental_get_booking_timezone());
    $today->setTime(0, 0, 0);

    return $today;
}

/**
 * Number of days from today that are blocked for new orders.
 *
 * @return int
 */
function rental_get_start_date_offset_days() {
    return max(0, (int) get_option('rental_min_start_date', 0));
}

/**
 * Earliest day a rental may start.
 *
 * @return DateTime Midnight in the booking timezone.
 */
function rental_get_earliest_start_date() {
    return rental_get_store_today()->modify('+' . rental_get_start_date_offset_days() . ' days');
}

/**
 * Day the calendars preselect, or null when no default day is configured.
 * Never earlier than the earliest allowed start.
 *
 * @return DateTime|null Midnight in the booking timezone.
 */
function rental_get_default_start_date() {
    if (1 != get_option('rental_select_a_day_by_default', 0)) {
        return null;
    }

    $earliest     = rental_get_earliest_start_date();
    $selected_day = max(1, (int) get_option('rental_selected_default_day', 1));
    $default      = rental_get_store_today()->modify('+' . ($selected_day - 1) . ' days');

    return $default < $earliest ? $earliest : $default;
}

/**
 * Day the calendars preselect as the return date: the default start plus the
 * minimum rental interval, so the preselected range is never shorter than the
 * configured minimum.
 *
 * @param bool $single_day Whether the return date is fixed to the start day.
 * @return DateTime|null Midnight in the booking timezone.
 */
function rental_get_default_end_date($single_day = false) {
    $start = rental_get_default_start_date();
    if (!$start) {
        return null;
    }

    if ($single_day) {
        return clone $start;
    }

    return (clone $start)->modify('+' . max(0, (int) get_option('rental_min_dates_range', 0)) . ' days');
}

/**
 * Drop stored dates that no longer clear the orders offset.
 *
 * Runs before output so the date forms, the summary above them and the
 * add-to-cart gate all see the same thing. Without it a cookie written before
 * the offset was raised keeps a blocked day selected, and the in-cart summary
 * disagrees with the calendar that refuses to show it.
 *
 * @return bool Whether stored dates were dropped.
 */
function rental_clear_blocked_start_date_cookie() {
    if (empty($_COOKIE['rental_start_date'])) {
        return false;
    }

    // Sale-only catalogues seed their own dates and never show a calendar.
    if (get_option('rental_synchronized_product_type') === 'sale') {
        return false;
    }

    // Checkout-only journeys regenerate their dates against the same floor.
    if (get_option('rental_dates_on_checkout', 0) == 1 && get_option('rental_allow_overbook', 1) == 1) {
        return false;
    }

    $start_date = decrypt_data($_COOKIE['rental_start_date'], get_option('rental_encryption_key'));
    if ( !rental_start_date_violates_offset($start_date)) {
        return false;
    }

    unset($_COOKIE['rental_start_date'], $_COOKIE['rental_end_date']);

    // Only expire the browser copy while a header can still be sent; otherwise
    // this render ignores them and the next request clears them for good.
    if ( !headers_sent()) {
        setcookie('rental_start_date', '', time() - 31556952, '/', '', false, true);
        setcookie('rental_end_date', '', time() - 31556952, '/', '', false, true);

        if (isset($_COOKIE['rental_form_filled'])) {
            unset($_COOKIE['rental_form_filled']);
            setcookie('rental_form_filled', '', time() - 31556952, '/', '', false);
        }

        update_option('rental_product_settings', '');
    }

    return true;
}

/**
 * Lowest "select a specific day by default" value the orders offset allows.
 * Value 1 is today, so an offset of N days makes N + 1 the first valid choice.
 *
 * @param int|string|null $offset_days Offset to measure against, defaults to the stored one.
 * @return int
 */
function rental_get_min_selected_default_day($offset_days = null) {
    $offset = null === $offset_days ? rental_get_start_date_offset_days() : max(0, (int) $offset_days);

    return $offset + 1;
}

/**
 * Highest value the "select a specific day by default" dropdown offers. The
 * list always reaches the first day the offset allows, so the two settings can
 * never be unrepresentable together.
 *
 * @param int|string|null $offset_days Offset to measure against, defaults to the stored one.
 * @return int
 */
function rental_get_max_selected_default_day($offset_days = null) {
    return max(8, rental_get_min_selected_default_day($offset_days));
}

/**
 * Label for a "select a specific day by default" option value.
 *
 * @param int $value 1 is today.
 * @return string
 */
function rental_get_selected_default_day_label($value) {
    $days = (int) $value - 1;

    if ($days <= 0) {
        return __('Today', 'rentopian-sync');
    }

    return sprintf(
        /* translators: %d: number of days after today. */
        _n('+%d day', '+%d days', $days, 'rentopian-sync'),
        $days
    );
}

/**
 * Force the configured business hours onto both endpoints when the time
 * pickers are hidden.
 *
 * In that mode the customer never sees or chooses a time, yet the endpoints
 * pick one up anyway: a preselected day carries the configured default time
 * while a day clicked in the calendar lands at midday. Mixing the two makes a
 * one-day selection measure as a part-day, which then fails the minimum
 * interval rule. Normalising here keeps validation and the stored dates on the
 * store's own hours.
 *
 * @param string $start_date Modified in place.
 * @param string $end_date   Modified in place.
 */
function rental_normalize_hidden_times(&$start_date, &$end_date) {
    if ( !get_option('rental_hide_time_pickers')) {
        return;
    }

    $start_timestamp = $start_date ? strtotime($start_date) : false;
    if (false !== $start_timestamp) {
        $start_date = date('Y/m/d', $start_timestamp) . ' ' . get_option('rental_default_start_time', '9:00 AM');
    }

    $end_timestamp = $end_date ? strtotime($end_date) : false;
    if (false !== $end_timestamp) {
        $end_date = date('Y/m/d', $end_timestamp) . ' ' . get_option('rental_default_end_time', '05:00 PM');
    }
}

/**
 * Whether a start date falls inside the blocked window.
 *
 * @param string $start_date Any parseable date string.
 * @return bool False when the date is missing or unparseable — emptiness is
 *              reported by the required-field checks, not here.
 */
function rental_start_date_violates_offset($start_date) {
    if (empty($start_date)) {
        return false;
    }

    $timestamp = strtotime($start_date);
    if (false === $timestamp) {
        return false;
    }

    return date('Y-m-d', $timestamp) < rental_get_earliest_start_date()->format('Y-m-d');
}

function rental_validate_dates($opt_hide_time, $opt_hide_zip, $start_date, $end_date, $zip, $error = '') {

    $error = $error ? $error : null;

    $start_date_timstamp = strtotime($start_date);

    $zip = $opt_hide_zip? null: $zip;
    
    $start_time = get_option('rental_default_start_time', '9:00 AM');
    $end_time = get_option('rental_default_end_time', '05:00 PM');
    
    $exploded_start_date = explode(" ", $start_date);
    if ($opt_hide_time && !isset($exploded_start_date[1])) {
        $start_date .= " $start_time";
        // Recompute after time is appended so the timestamp matches the final date string.
        $start_date_timstamp = strtotime($start_date);
    } else {
        $start_time = date('g:i A', $start_date_timstamp);
    }

    // Enforce the orders offset: the calendars block these days, so a start
    // date inside the window can only arrive from a stale cookie or a payload
    // that bypassed the picker.
    if (rental_start_date_violates_offset($start_date)) {
        $earliest_label = date_i18n(get_option('date_format'), rental_get_earliest_start_date()->getTimestamp());
        $error .= sprintf(
            /* translators: %s: earliest date a rental may start. */
            __('The earliest available start date is %s! ', 'rentopian-sync'),
            $earliest_label
        );
    }

    // Check if this is a single-day rental (multi-day checkbox unchecked or end_date empty)
    $is_single_day_rental = empty($end_date) || trim($end_date) === '';
    
    // Also check the multi_day_event cookie - if unchecked, treat as single day
    $multi_day_preference = isset($_COOKIE['rental_multi_day_preference']) ? $_COOKIE['rental_multi_day_preference'] : '1';
    $multi_day_event_enabled = get_option('rental_multi_day_event');
    
    // If multi-day feature is enabled but user unchecked it, or if end_date is empty
    if ($is_single_day_rental || ($multi_day_event_enabled && $multi_day_preference === '0')) {
        // Set end_date to start_date with end time for single-day rental
        $end_date = date('Y/m/d', $start_date_timstamp) . " $end_time";
    } else {
        // Multi-day rental - process end_date normally
        $exploded_end_date = explode(" ", $end_date);
        if ($opt_hide_time && !isset($exploded_end_date[1])) {
            $end_date .= " $end_time";
        }
    }
    
    // If admin has hidden end date (single-day UX) — but not event-offset mode, which uses a computed multi-day window.
    $event_offset_mode = function_exists('rental_get_event_date_offsets') && rental_get_event_date_offsets();
    if (get_option('rental_hide_end_date') && ! $event_offset_mode) {
        $end_date = date('Y/m/d', $start_date_timstamp) . " $end_time";
    }

    $end_date_timstamp = strtotime($end_date);

    $start_date_obj = new DateTime($start_date);
    $end_date_obj = new DateTime($end_date);

    // With the time pickers hidden the customer picks days, not times, so the
    // interval is counted in whole days. Otherwise the configured hours decide
    // it — a store closing earlier than it opens would turn a one-day booking
    // into a part-day one and fail its own minimum.
    if ($opt_hide_time) {
        $start_date_obj->setTime(0, 0, 0);
        $end_date_obj->setTime(0, 0, 0);
    }

    $interval = $start_date_obj->diff($end_date_obj);
    $opt_min_date_range = (int) get_option('rental_min_dates_range', 0);
    $opt_max_date_range = (int) get_option('rental_max_dates_range', 0);

    $opt_disabled_week_days = get_option('rental_disabled_week_days', '');

    // Only validate end > start for multi-day rentals when NOT a single-day rental
    $skip_multi_day_validation = $is_single_day_rental || 
                                  ($multi_day_event_enabled && $multi_day_preference === '0') || 
                                  get_option('rental_hide_end_date');
    
    if (!$skip_multi_day_validation) {
        $is_same_calendar_day_hidden = $opt_hide_time
            && $end_date_timstamp > 0
            && date('Y/m/d', $start_date_timstamp) === date('Y/m/d', $end_date_timstamp);

        if (!$is_same_calendar_day_hidden && $end_date_timstamp <= $start_date_timstamp) {
            $error.= __('Return date must be later than start date! ', 'rentopian-sync');
        }

        if ( !empty($opt_min_date_range) && $opt_min_date_range > $interval->days) {
            $error.= __("The minimum interval between start and end dates is {$opt_min_date_range} day(s)! ", 'rentopian-sync');
        }
        if ( !empty($opt_max_date_range) && $opt_max_date_range < $interval->days) {
            $error.= __("The maximum interval between start and end dates is {$opt_max_date_range} day(s)! ", 'rentopian-sync');
        }
    }
    
    if ($opt_disabled_week_days) {

        $start_date_day = date("l", $start_date_timstamp);
        $start_date_day_num = getDayNumberByDayName($start_date_day, false);
        $end_date_day = date("l", $end_date_timstamp);
        $end_date_day_num = getDayNumberByDayName($end_date_day, false);

        // start date restriction check
        if (isset($opt_disabled_week_days[$start_date_day_num])) {
            $error.= __("{$start_date_day}(".date('M j', $start_date_timstamp).") is a restricted day! ", 'rentopian-sync');
        }
        // end date restriction check (only for multi-day rentals)
        if (!$skip_multi_day_validation && isset($opt_disabled_week_days[$end_date_day_num])) {
            $error.= __("{$end_date_day}(".date('M j', $end_date_timstamp).") is a restricted day! ", 'rentopian-sync');
        }

    } 

    return $error;
}
// selecting start/end date, zip code and address + filtering available products automated
function rental_validate_dates_form_and_get_products_data_init($opt_hide_time, $opt_hide_zip, $opt_show_location, &$start_date, &$end_date, $address, $zip) {
    $error = null;

    // $rental_unavailable_items_opt = get_option('rental_unavailable_items');
    // if ($rental_unavailable_items_opt && is_array($rental_unavailable_items_opt)) {
    //     update_option('rental_unavailable_items', '');
    // }

    if ( !$start_date) {
        $error = __('Start date is required!', 'rentopian-sync');
    }
    if ($opt_show_location) {
        if (!isset($address) || !$address) {
            $error = __('Address is required!', 'rentopian-sync');
        }
    }
    if ( !$opt_hide_zip && ( !isset($zip) || !$zip)) {
        $error = __('ZIP code is required!', 'rentopian-sync');
    }
    if (isset($zip) && !$zip) {
        $error = __('ZIP code is invalid!', 'rentopian-sync');
    }


    $validateZipCodeDivisionsAndProductSettings = rental_request_product_settings($zip);

    // EVENT DATE OFFSET: Compute rental window from the event date only (never from pre-offset start/end — avoids double application when JS sends computed range).
    $event_date_offsets = rental_get_event_date_offsets();
    $posted_event       = isset($_POST['event_date']) ? sanitize_text_field(wp_unslash($_POST['event_date'])) : '';
    $cookie_event       = '';
    if ($event_date_offsets && !empty($_COOKIE['rental_event_date']) && function_exists('decrypt_data')) {
        $ek = get_option('rental_encryption_key');
        if ($ek) {
            $cookie_event = decrypt_data($_COOKIE['rental_event_date'], $ek);
        }
    }

    $event_date_raw = $start_date;
    if ($event_date_offsets) {
        $offset_default_start_time = get_option('rental_default_start_time', '9:00 AM');
        $offset_default_end_time   = get_option('rental_default_end_time', '05:00 PM');

        $event_source = '';
        if ($posted_event !== '') {
            $event_source = $posted_event;
        } elseif ($cookie_event !== '') {
            $event_source = $cookie_event;
        }

        if ($event_source !== '' && false !== strtotime($event_source)) {
            $event_date_raw = $event_source;
            $computed       = rental_apply_event_date_offsets(
                $event_source,
                $event_date_offsets['start_date_offset'],
                $event_date_offsets['end_date_offset'],
                $offset_default_start_time,
                $offset_default_end_time
            );
            $start_date = $computed['start_date'];
            $end_date   = $computed['end_date'];
        } else {
            // No event string: start/end are already the rental window (reload, cookie replay, or legacy). Never treat rental_start as event.
            $event_date_raw = $cookie_event !== '' ? $cookie_event : '';
        }
    }

    // Settle the times before validating, so the dates that are checked are the
    // same ones that get stored.
    rental_normalize_hidden_times($start_date, $end_date);

    try {

        $error = rental_validate_dates($opt_hide_time, $opt_hide_zip, $start_date, $end_date, $zip, $error);

        $address = $opt_show_location ? trim($address) : '';

        if (empty($error)) {
            // set data in cookies as well, to be able to work with WP Rocket
            
            $start_end_date_expire_time = time() + RENTOPIAN_DATE_EXPIRE_TIME;  

            $encrypted_rental_start_date = encrypt_data($start_date, get_option('rental_encryption_key'));
            $_COOKIE['rental_start_date'] = $encrypted_rental_start_date;
            setcookie('rental_start_date', $encrypted_rental_start_date, $start_end_date_expire_time, "/", "", false, true);

            // When the return date is hidden (and not in event-offset mode, which uses a
            // computed multi-day window), the end date is not user-selectable. Persist it
            // as the start day + default end time so an inverted or stale end can never be
            // stored. Mirrors the single-day rule applied during validation.
            $end_date_to_store = $end_date;
            if (get_option('rental_hide_end_date') && !$event_date_offsets) {
                $start_ts = strtotime($start_date);
                if (false !== $start_ts) {
                    $end_date_to_store = date('Y/m/d', $start_ts) . ' ' . get_option('rental_default_end_time', '05:00 PM');
                }
            }

            $encrypted_rental_end_date = encrypt_data($end_date_to_store, get_option('rental_encryption_key'));
            $_COOKIE['rental_end_date'] = $encrypted_rental_end_date;
            setcookie('rental_end_date', $encrypted_rental_end_date, $start_end_date_expire_time, "/", "", false, true);

            $encrypted_rental_zip = $zip ? encrypt_data($zip, get_option('rental_encryption_key')) : true;
            $_COOKIE['rental_zip'] = $encrypted_rental_zip;
            setcookie('rental_zip', $encrypted_rental_zip, $start_end_date_expire_time, "/", "", false, false);

            $encrypted_rental_address = encrypt_data($address, get_option('rental_encryption_key'));
            $_COOKIE['rental_address'] = $encrypted_rental_address;
            setcookie('rental_address', $encrypted_rental_address, $start_end_date_expire_time, "/", "", false, true);

            // EVENT DATE OFFSET: Store original event date for order sync (skip when empty — preserve existing cookie on no-op replay).
            if ($event_date_offsets) {
                if ($event_date_raw !== '') {
                    $encrypted_event_date = encrypt_data($event_date_raw, get_option('rental_encryption_key'));
                    $_COOKIE['rental_event_date'] = $encrypted_event_date;
                    setcookie('rental_event_date', $encrypted_event_date, $start_end_date_expire_time, "/", "", false, true);
                }
            } else {
                // Clear event date cookie when offsets are not active
                if (isset($_COOKIE['rental_event_date'])) {
                    unset($_COOKIE['rental_event_date']);
                    setcookie('rental_event_date', '', time() - 31556952, "/", "", false, true);
                }
            }

            
            update_option('rental_product_settings', $validateZipCodeDivisionsAndProductSettings);

            // update_option('rental_unavailable_items', get_option('rental_filter_unavailable_products', 1) ? get_unavailable_products($start_date, $end_date, $zip ? $zip : 1) : []);
        }
           
    } catch (RentalException $e) {

        ErrorHandler::registerErrorInLog($e->getMessage(), $e->getFile(), $e->getLine(), $e->getType(), null, $e->getStatusCode());

        update_option('rental_product_settings', '');
    }

    if (!is_null($error)) {

        update_option('rental_product_settings', '');

        if (isset($_COOKIE['rental_zip'])) {
            unset($_COOKIE['rental_zip']);
            setcookie('rental_zip', '', time() - (31556952), "/", "", false, false);
        }
    }

    return $error;
}

function rental_validate_dates_form_and_get_products_data_with_cookie($opt_hide_time, $opt_hide_zip) {
    
    if ( !isset($_COOKIE['rental_start_date']) || !$_COOKIE['rental_start_date']) {

        return false;
    } elseif ( !$opt_hide_zip && ( !isset($_COOKIE['rental_zip']) || !$_COOKIE['rental_zip'])) {

        return false;
    } elseif (isset($_COOKIE['rental_zip']) && !$_COOKIE['rental_zip']) {

        return false;
    } else {

        $validateZipCodeDivisionsAndProductSettings = rental_request_product_settings($_COOKIE['rental_zip']);

        try {

            $decrypted_rental_zip = decrypt_data($_COOKIE['rental_zip'], get_option('rental_encryption_key'));
            $zip = $opt_hide_zip? null: $decrypted_rental_zip;

            $start_time = get_option('rental_default_start_time', '9:00 AM');
            $end_time = get_option('rental_default_end_time', '05:00 PM');

            $decrypted_rental_start_date = decrypt_data($_COOKIE['rental_start_date'], get_option('rental_encryption_key'));
            $start_date = $decrypted_rental_start_date;

            if ($opt_hide_time) {
                $start_date .= " $start_time";
            } else {
                $start_time = date('g:i A', strtotime($start_date));
            }

            if (isset($_COOKIE['rental_end_date'])) {

                $decrypted_rental_end_date = decrypt_data($_COOKIE['rental_end_date'], get_option('rental_encryption_key'));
                $end_date = $decrypted_rental_end_date;

                if ($opt_hide_time) {
                    $end_date .= " $end_time";
                }
            } else {

                $end_date = date('Y/m/d', strtotime($decrypted_rental_start_date)) . " $end_time";
            }

            if (get_option('rental_hide_end_date')) {
                $end_date = date('Y/m/d', strtotime($decrypted_rental_start_date)) . " $end_time";
            }

            $start_date_ts = strtotime($start_date);
            $end_date_ts = strtotime($end_date);

            if ($end_date_ts <= $start_date_ts) {

                return false;
            } else {

                $start_end_date_expire_time = time() + RENTOPIAN_DATE_EXPIRE_TIME;  

                $encrypted_rental_start_date = encrypt_data($start_date, get_option('rental_encryption_key'));
                $_COOKIE['rental_start_date'] = $encrypted_rental_start_date;
                setcookie('rental_start_date', $encrypted_rental_start_date, $start_end_date_expire_time, "/", "", false, true);

                $encrypted_rental_end_date = encrypt_data($end_date, get_option('rental_encryption_key'));
                $_COOKIE['rental_end_date'] = $encrypted_rental_end_date;
                setcookie('rental_end_date', $encrypted_rental_end_date, $start_end_date_expire_time, "/", "", false, true);

                $encrypted_rental_zip = $zip ? encrypt_data($zip, get_option('rental_encryption_key')) : true;
                $_COOKIE['rental_zip'] = $encrypted_rental_zip;
                setcookie('rental_zip', $encrypted_rental_zip?: true, $start_end_date_expire_time, "/", "", false, false);

                update_option('rental_product_settings', $validateZipCodeDivisionsAndProductSettings);
            }

        } catch (RentalException $e) {
            ErrorHandler::registerErrorInLog($e->getMessage(), $e->getFile(), $e->getLine(), $e->getType(), null, $e->getStatusCode());
            // $error = $e->getMessage();

            update_option('rental_product_settings', '');
        }
        rental_remove_unavailable_cart_items();
    }

}

/**
 * Whether the customer has submitted the date form.
 *
 * Only the `rental_form_filled` cookie can answer this: in dates on checkout
 * mode the date cookies are seeded with system defaults before any selection is
 * made, so their presence says nothing about the customer's own choice. The
 * marker lives as long as the dates it describes and is cleared whenever those
 * dates are regenerated.
 */
function rental_is_date_form_filled() {

    return !empty($_COOKIE['rental_form_filled']);
}

// function for create date form
function rental_create_date_form() {
    static $already_rendered = false;
    if ($already_rendered) {
        return;
    }
    $already_rendered = true;

    global $product;
    global $wp;
    $product_id = 0;
    $url = add_query_arg( $wp->query_vars, home_url( $wp->request ) );
    if (isset($product) && !empty($product)) {
        if (!$product->has_child()) {
            $product_id = $product->get_id();
        }
    }
    $check_rental_select_a_day_by_default = get_option('rental_select_a_day_by_default', 0);
    $check_rental_start_date = isset($_COOKIE['rental_start_date']) && $_COOKIE['rental_start_date'] ? 1 : 0;
    $dates_on_checkout = get_option('rental_dates_on_checkout', 0) == 1 && get_option('rental_allow_overbook', 1) == 1 ? 1 : 0;
    $form_filled = rental_is_date_form_filled() ? 1 : 0;

    if ( !$form_filled && $dates_on_checkout === 1) {
        $zip_code_text = get_option('rental_hide_zip') ? '' : 'and your delivery zip code';
        $form_text_on_top = "<p id='rntp-date-blocker-text' class='rental-form-text-on-top'> Please enter your rental dates {$zip_code_text} to proceed </p>";
        $div_overlay = '<div id="rntp-date-blocker" class="rental-overlay"></div>';
        echo $form_text_on_top . $div_overlay;
    }
    $div = '<div data-form-filled="'.$form_filled.'" data-dates-on-checkout="'.$dates_on_checkout.'" data-has-start-date="'.$check_rental_start_date.'" data-has-default-date="'.$check_rental_select_a_day_by_default.'" data-url="'.$url.'" data-pid="'.$product_id.'" id="rntp-form-holder"></div>';
    echo $div;
}

// function rental_add_last_selected_product_to_cart() {
//     if (empty($_COOKIE['rental_last_selected_product'])) {
//         return;
//     }

//     $product = json_decode($_COOKIE['rental_last_selected_product'], true);
//     if (isset($product['time']) && ($product['time'] + 300) > time() && rental_validate_cart_item($product['product_id'], $product['variation_id'], $product['quantity'])) {
        
//         $cart = WC()->cart;
//         $cart->add_to_cart($product['product_id'], $product['quantity'], $product['variation_id']);
//         $_COOKIE['rental_product_added_to_cart_message'] = wc_add_to_cart_message([$product['product_id'] => $product['quantity']], true, true);
//         setcookie('rental_product_added_to_cart_message', wc_add_to_cart_message([$product['product_id'] => $product['quantity']], true, true) , time() + (10800), "/", "", false, true);
//         // reset cache of cart total
//         $cart->calculate_totals();
//     }

//     unset($_COOKIE['rental_last_selected_product']);
//     setcookie('rental_last_selected_product', '', time() - (31556952), "/", "", false, true);
// }

// function to remove unavailable cart items
function rental_remove_unavailable_cart_items() {
    global $woocommerce;

    $cart_contents = $woocommerce->cart->cart_contents;
    if (empty($cart_contents)) {
        return false;
    }

    $inventories = [];
    foreach ($cart_contents as $cart_item) {
        if (get_post_meta($cart_item['product_id'], '_rental_is_set', true)) {
            continue;
        }
        $inv_id = get_post_meta($cart_item['variation_id']?: $cart_item['product_id'], '_rental_inventory_id', true);
        if ( !$inv_id) {
            $removed_items[] = $cart_item['key'];
            unset($woocommerce->cart->cart_contents[$cart_item['key']]);
            continue;
        }
        if ( !in_array($inv_id, $inventories)) {
            $inventories[] = $inv_id;
        }
        $cart_contents[$cart_item["key"]]["rental_inventory_id"] = $inv_id;
    }

    if (empty($inventories) || is_null($availability = rental_check_availability($inventories)) || empty($availability["inventories"])) {
        $woocommerce->cart->empty_cart();
        return false;
    }

    $removed_items = [];
    foreach ($cart_contents as $cart_item) {
        if ( !isset($cart_item["rental_inventory_id"])) {
            continue;
        }
        $parent_item_key = null;
        if (isset($cart_item["rental_add_on_of"])){
            $parent_item_key = $cart_item["rental_add_on_of"];
            if(in_array($parent_item_key, $removed_items)) {
                continue;
            }
        }
        if ( !isset($availability["inventories"][$cart_item["rental_inventory_id"]])
            || ( !$availability["allow_overbook"] && $cart_item["quantity"] > $availability["inventories"][$cart_item["rental_inventory_id"]]['quantity'])) {
            $key = $parent_item_key && $cart_item["rental_add_on_required"]? $parent_item_key: $cart_item["key"];
            $removed_items[] = $key;
            unset($woocommerce->cart->cart_contents[$key]);
        } elseif( !$availability["allow_overbook"]) {
            $availability["inventories"][$cart_item["rental_inventory_id"]]['quantity'] -= $cart_item["quantity"];
        }
    }

    foreach ($removed_items as $item_key) {
        do_action('woocommerce_cart_item_removed', $item_key, $woocommerce->cart);
    }

    // reset cache of cart total
    $woocommerce->cart->calculate_totals();

    return true;
}

function get_unavailable_products($start_date, $end_date = null, $zip = null) {
    if ( !isset($zip) || !isset($start_date)) {
        return null;
    }
    if (!get_option('rental_hide_end_date') && !isset($end_date)) {
        return null;
    }
    if (strtotime($start_date) < strtotime("today")) {
        return 'not_valid';
    }

    if(get_option('rental_allow_overbook', 0) == 1){
        return [];
    }

    try {
        return json_decode(rental_curl('inventories/bulk-availability/check', get_option('rental_api_key'), false, [
            // 'calculate_available_quantity' => get_option('rental_overbook_text')? 1: 0,
            'gmt_offset' => get_option('timezone_string')?: get_option('gmt_offset'),
            'start_date' => $start_date,
            'end_date' => $end_date,
            'division_id' => get_option('rental_select_division') && isset($_COOKIE['rental_division_id'])? $_COOKIE['rental_division_id']: '',
            'zip' => get_option('rental_hide_zip')? '': $zip
        ]), true);
    } catch (Exception $e) {
        return $e;
    }
}

function rental_dates_on_checkout_page_option_effect() {
    
    $encryption_key = get_option('rental_encryption_key');
    $rental_hide_zip = get_option('rental_hide_zip');
    $rental_divisions = get_option('rental_divisions');

    // if we have allow overbook and rental dates on checkout page
    if ( !isset($_COOKIE['rental_zip']) ) {
        $zip = $rental_hide_zip ? TRUE : get_shop_address($rental_divisions);

        $start_end_date_expire_time = time() + RENTOPIAN_DATE_EXPIRE_TIME;

        $encrypted_rental_zip = encrypt_data($zip, $encryption_key);
        $_COOKIE['rental_zip'] = $encrypted_rental_zip;
        setcookie('rental_zip', $encrypted_rental_zip, $start_end_date_expire_time, "/", "", false, false);
    }

    $opt_default_start_time = get_option('rental_default_start_time', '09:00 AM');
    $opt_default_end_time = get_option('rental_default_end_time', '05:00 PM');

    // Seed from the same resolver the calendars render, so the dates handed to
    // a checkout-only journey obey the orders offset and the default day.
    $default_start = rental_get_default_start_date();
    $start_day = $default_start ? $default_start : rental_get_earliest_start_date();
    $end_day = (clone $start_day)->modify('+1 day');

    // Saved dates stay valid anywhere from the floor onwards, not just from the
    // day seeded here — the customer may have picked a later one.
    $min_start_date = rental_get_earliest_start_date()->format('Y-m-d');

    // Decrypt any saved dates and decide whether they are still usable.
    $saved_start = isset($_COOKIE['rental_start_date']) ? decrypt_data($_COOKIE['rental_start_date'], $encryption_key) : '';
    $saved_end = isset($_COOKIE['rental_end_date']) ? decrypt_data($_COOKIE['rental_end_date'], $encryption_key) : '';

    $saved_start_ts = $saved_start ? strtotime($saved_start) : 0;
    $saved_end_ts = $saved_end ? strtotime($saved_end) : 0;
    $saved_start_date = $saved_start_ts ? date('Y-m-d', $saved_start_ts) : '';

    // Regenerate when missing, unparseable, stale (start before the earliest
    // acceptable start) or out of order.
    $dates_need_reset = empty($saved_start_ts)
        || empty($saved_end_ts)
        || $saved_start_date < $min_start_date
        || $saved_end_ts <= $saved_start_ts;

    if (!$dates_need_reset) {
        return;
    }

    $today_start = $start_day->format('Y/m/d') . ' ' . $opt_default_start_time;
    $today_end = $start_day->format('Y/m/d') . ' ' . $opt_default_end_time;
    $tomorrow_end = $end_day->format('Y/m/d') . ' ' . $opt_default_end_time;

    // Enforce the configured min/max rental duration.
    $start_date_obj = new DateTime($today_start);
    $end_date_obj = new DateTime($tomorrow_end);
    $interval = $start_date_obj->diff($end_date_obj);

    $opt_min_date_range = (int) get_option('rental_min_dates_range', 0);
    $opt_max_date_range = (int) get_option('rental_max_dates_range', 0);

    if ($opt_min_date_range && $opt_min_date_range > $interval->days) {
        $calcDays = $opt_min_date_range - $interval->days;
        $tomorrow_end = date('Y/m/d h:ia', strtotime("$today_end +$calcDays day"));
    }

    if ($opt_max_date_range && $opt_max_date_range < $interval->days) {
        $calcDays = $opt_max_date_range - $interval->days;
        $tomorrow_end = date('Y/m/d h:ia', strtotime("$today_end +$calcDays day"));
    }

    $start_end_date_expire_time = time() + RENTOPIAN_DATE_EXPIRE_TIME;

    $encrypted_rental_start_date = encrypt_data($today_start, $encryption_key);
    $_COOKIE['rental_start_date'] = $encrypted_rental_start_date;
    setcookie("rental_start_date", $encrypted_rental_start_date, $start_end_date_expire_time, "/", "", false, true);

    $encrypted_rental_end_date = encrypt_data($tomorrow_end, $encryption_key);
    $_COOKIE['rental_end_date'] = $encrypted_rental_end_date;
    setcookie("rental_end_date", $encrypted_rental_end_date, $start_end_date_expire_time, "/", "", false, true);

    // These dates are system defaults, so the customer has to pick again
    if (isset($_COOKIE['rental_form_filled'])) {
        unset($_COOKIE['rental_form_filled']);
        setcookie('rental_form_filled', '', time() - (31556952), "/", "", false);
    }
}


// function to check available products real time from the core system
function rental_check_availability($inventories) {

    $allow_overbook = get_option('rental_allow_overbook', 1);
    $rental_encryption_key = get_option('rental_encryption_key');

    if (get_option('rental_dates_on_checkout', 0) == 1 && $allow_overbook == 1) {

        // if we allow overbook and rental dates on checkout page
        rental_dates_on_checkout_page_option_effect();

    } else {
        // usual journey

        if ( !isset($_COOKIE['rental_zip']) || !isset($_COOKIE['rental_start_date'])) {
            return 'not_set';
        }
        if (!get_option('rental_hide_end_date') && !isset($_COOKIE['rental_end_date'])) {
            return 'not_set';
        }

        // Only genuinely dead dates fail here. The orders offset is enforced by
        // the calendars, by rental_validate_dates() on submit and by the date
        // form clearing a stale cookie — failing it here would instead reach
        // the cart pruner and empty a cart the customer can still fix.
        $decrypted_rental_start_date = decrypt_data($_COOKIE['rental_start_date'], $rental_encryption_key);
        if (strtotime($decrypted_rental_start_date) < strtotime("today")) {
            return 'not_valid';
        }
    }
   

    try {

        $decrypted_rental_start_date = '';
        if (isset($_COOKIE['rental_start_date'])) {
            $decrypted_rental_start_date = decrypt_data($_COOKIE['rental_start_date'], $rental_encryption_key);
        }

        $decrypted_rental_end_date = '';
        if (isset($_COOKIE['rental_end_date'])) {
            $decrypted_rental_end_date = decrypt_data($_COOKIE['rental_end_date'], $rental_encryption_key);
        }

        $decrypted_rental_zip = '';
        if (isset($_COOKIE['rental_zip'])) {
            $decrypted_rental_zip = decrypt_data($_COOKIE['rental_zip'], $rental_encryption_key);
        }
        
        $availablity_response = json_decode(rental_curl('inventories/availability/check', get_option('rental_api_key'), false, [
            'calculate_available_quantity' => !$allow_overbook,
            'gmt_offset' => get_option('timezone_string')?: get_option('gmt_offset'),
            'start_date' => $decrypted_rental_start_date,
            'end_date' => $decrypted_rental_end_date,
            'division_id' => get_option('rental_select_division') && isset($_COOKIE['rental_division_id'])? $_COOKIE['rental_division_id']: '',
            'zip' => get_option('rental_hide_zip')? '': $decrypted_rental_zip,
            'inventories' => json_encode($inventories)
        ]), true);

        return $availablity_response;

    } catch (Exception $e) {

    }

    return null;
}

// function for get available hourly products
function rental_check_availability_hourly($inventories, $rental_start_date) {

    if ( get_option('rental_synchronized_product_type') != "hourly") {
        return null;
    }

    try {
        $result = json_decode(rental_curl('inventories/availability/check', get_option('rental_api_key'), false, [
            'calculate_available_quantity' => !get_option('rental_allow_overbook', 0),
            'gmt_offset' => get_option('timezone_string')?: get_option('gmt_offset'),
            'start_date' => $rental_start_date,
            'end_date' => '',
            'division_id' => get_option('rental_select_division') && isset($_COOKIE['rental_division_id'])? $_COOKIE['rental_division_id']: '',
            'zip' => '',
            'inventories' => json_encode($inventories)
        ]), true);

        return $result;
    } catch (Exception $e) {

    }

    return null;
}

// return true if the $haystack ends with the $needle
function rental_ends_with($haystack, $needle) {
    return substr($haystack, -strlen($needle)) === $needle;
}

function set_hourly_start_end_date() {
    $all_options = wp_load_alloptions();
    $dates_seconds = [];

    foreach ($all_options as $option_name => $option_value) {
        if (
            strpos($option_name, '_rental_by_interval') !== false ||
            strpos($option_name, '_rental_by_slot') !== false
        ) {
            $value = maybe_unserialize($option_value);
            if (is_array($value) && isset($value['active']) && $value['active'] == 1) {
                $dates_seconds[] = strtotime($value["start_date"]);
            }
        }
    }


    if ($dates_seconds) {
        $start_date = date("Y/m/d", min($dates_seconds));
        // $date_max_seconds = max($dates_seconds) + 86400;
        $date_max_seconds = max($dates_seconds);
        $end_date = date("Y/m/d", $date_max_seconds);

        $_COOKIE["rental_hourly_start_date"] = $start_date;
        $_COOKIE["rental_hourly_end_date"] = $end_date;
        setcookie("rental_hourly_start_date", $start_date, time() + (10800), "/", "", false, true);
        setcookie("rental_hourly_end_date", $end_date, time() + (10800), "/", "", false, true);
    }
}


function unset_temporary_data() {

    $_COOKIE["rental_order_submitted"] = 1;
    setcookie("rental_order_submitted", 1, time() + (10800), "/", "", false, true);

    if ($set_ids_with_optional_items = get_set_ids_with_optional_items()) {

        foreach($set_ids_with_optional_items as $set_with_optional_items_id => $selected_product_key_variant_or_product_value_array) {
                
            $rental_set_items_default = get_post_meta($set_with_optional_items_id, '_rental_set_items_default', true);
            update_post_meta($set_with_optional_items_id, '_rental_set_items', $rental_set_items_default);
        }

        delete_option('_rental_set_ids_with_optional_items');
    }

    if ($set_with_variant_addons_exists_in_process_data = get_option('_rental_sets_with_variant_addons_exist_in_process', [])) {

        foreach($set_with_variant_addons_exists_in_process_data as $set_with_variant_addons_id_data) {

            // $set_id = $set_with_variant_addons_id_data['set_id'];
            $set_id = $set_with_variant_addons_id_data;
            $rental_set_items_default = get_post_meta($set_id, '_rental_set_items_default', true);
            update_post_meta($set_id, '_rental_set_items', $rental_set_items_default);
        }


        update_option('_rental_sets_with_variant_addons_exist_in_process', []);
    }

    // if (isset($_COOKIE['rental_end_date'])) {
    //     unset($_COOKIE['rental_end_date']);
    //     setcookie('rental_end_date', '', time() - (31556952), "/", "", false, false);
    // }

    // if (isset($_COOKIE['rental_zip'])) {
    //     unset($_COOKIE['rental_zip']);
    //     setcookie('rental_zip', '', time() - (31556952), "/", "", false, false);
    // }

    /*
    Notice : commenting out unused code
    TODO : rewrite later with WC()->session for hourly products

    $all_options = wp_load_alloptions();

    foreach ($all_options as $option_name => $option_value) {
        if (
            strpos($option_name, '_rental_by_interval') !== false ||
            strpos($option_name, '_rental_by_slot') !== false ||
            strpos($option_name, '_rental_by_interval_removed') !== false ||
            strpos($option_name, '_rental_by_slot_removed') !== false ||
            strpos($option_name, '_rental_by_interval_updated') !== false ||
            strpos($option_name, '_rental_by_slot_updated') !== false
        ) {
            delete_option($option_name);
        }
    }
    */


    if (get_rental_session_data('rental_product_options_valuables')) {
        delete_rental_session_data('rental_product_options_valuables');
    }

    
    $tracked_keys = WC()->session->get('tracked_rental_keys', []);

    if ( !empty($tracked_keys) ) {
        $selected_options = [];
        $selected_options_of_set = [];

        // Loop through each tracked key.
        foreach ( $tracked_keys as $session_key ) {
            // Check if the key is one of the selected options.
            if ( strpos( $session_key, '_selected_options' ) !== false ) {
                $selected_options[] = $session_key;
            }

            // Check if the key is one of the selected options of set.
            if ( strpos( $session_key, '_selected_options_of_set' ) !== false ) {
                $selected_options_of_set[] = $session_key;
            }
        }

        // Delete session data for each selected option.
        if ( !empty($selected_options) ) {
            foreach ( $selected_options as $key ) {
                delete_rental_session_data($key);
            }
        }

        // Delete session data for each selected option of set.
        if ( !empty($selected_options_of_set) ) {
            foreach ( $selected_options_of_set as $key ) {
                delete_rental_session_data($key);
            }
        }
    }
    

    
    // product order options
    if (get_rental_session_data('rental_order_selected_options', [])) {
        delete_rental_session_data('rental_order_selected_options');
    }

    // set order options
    if (get_rental_session_data('rental_order_selected_options_of_sets', [])) {
        delete_rental_session_data('rental_order_selected_options_of_sets');
    }

    // product uncertain addons data on a cart process
    // if(get_rental_session_data('rental_uncertain_add_ons', [])) {
    //     delete_rental_session_data('rental_uncertain_add_ons');
    // }

    if(isset($_COOKIE['rental_coupon_code'])) {
        unset($_COOKIE['rental_coupon_code']);
        setcookie('rental_coupon_code', false, time() - (31556952), "/", "", false, false);
    }


    if (get_rental_session_data('wc_shipping_method', 0)) {
        delete_rental_session_data('wc_shipping_method');
    }

    if (isset($_COOKIE['rental_client_address_lat'])) {
        unset($_COOKIE['rental_client_address_lat']);
        setcookie('rental_client_address_lat', false, time() - (31556952), "/", "", false, false);
    }

    if (isset($_COOKIE['rental_client_address_lng'])) {
        unset($_COOKIE['rental_client_address_lng']);
        setcookie('rental_client_address_lng', false, time() - (31556952), "/", "", false, false);
    }

    if (isset($_COOKIE['rental_client_shipping_address_lat'])) {
        unset($_COOKIE['rental_client_shipping_address_lat']);
        setcookie('rental_client_shipping_address_lat', false, time() - (31556952), "/", "", false, false);
    }

    if (isset($_COOKIE['rental_client_shipping_address_lng'])) {
        unset($_COOKIE['rental_client_shipping_address_lng']);
        setcookie('rental_client_shipping_address_lng', false, time() - (31556952), "/", "", false, false);
    }

    if (isset($_COOKIE['rental_client_billing_address_lat'])) {
        unset($_COOKIE['rental_client_billing_address_lat']);
        setcookie('rental_client_billing_address_lat', false, time() - (31556952), "/", "", false, false);
    }
    
    if (isset($_COOKIE['rental_client_billing_address_lng'])) {
        unset($_COOKIE['rental_client_billing_address_lng']);
        setcookie('rental_client_billing_address_lng', false, time() - (31556952), "/", "", false, false);
    }


    if (get_rental_session_data('rental_delivery_venue_address_id', 0)) {
        delete_rental_session_data('rental_delivery_venue_address_id');
    }

    if (get_rental_session_data('charge_only_delivery_for_website', 0)) {
        delete_rental_session_data('charge_only_delivery_for_website');
    }
    
    if (get_rental_session_data('rental_pickup_venue_address_id', 0)) {
        delete_rental_session_data('rental_pickup_venue_address_id');
    }
    
    if (get_rental_session_data('rental_pickup_address_type', 1)) {
        delete_rental_session_data('rental_pickup_address_type');
    }
    
    if (get_rental_session_data('rental_delivery_cost', 0)) {
        delete_rental_session_data('rental_delivery_cost');
    }

    if (get_rental_session_data('rental_delivery_original_cost', 0)) {
        delete_rental_session_data('rental_delivery_original_cost');
    }

    if (get_rental_session_data('rental_pickup_original_cost', 0)) {
        delete_rental_session_data('rental_pickup_original_cost');
    }

    if (get_rental_session_data('rental_coupon_discount', 0)) {
        delete_rental_session_data('rental_coupon_discount');
    }

    if (get_rental_session_data('rental_pickup_cost', 0)) {
        delete_rental_session_data('rental_pickup_cost');
    }

    if (isset($_COOKIE['rental_different_pick_up_address'])) {
        unset($_COOKIE['rental_different_pick_up_address']);
        setcookie('rental_different_pick_up_address', false, time() - (31556952), "/", "", false, false);
    }

    if (isset($_COOKIE['rental_client_pickup_address_lat'])) {
        unset($_COOKIE['rental_client_pickup_address_lat']);
        setcookie('rental_client_pickup_address_lat', false, time() - (31556952), "/", "", false, false);
    }

    if (isset($_COOKIE['rental_client_pickup_address_lng'])) {
        unset($_COOKIE['rental_client_pickup_address_lng']);
        setcookie('rental_client_pickup_address_lng', false, time() - (31556952), "/", "", false, false);
    }

    if (isset($_COOKIE['rental_pick_up_city'])) {
        unset($_COOKIE['rental_pick_up_city']);
        setcookie('rental_pick_up_city', false, time() - (31556952), "/", "", false, false);
    }
    
    if (isset($_COOKIE['rental_pick_up_state'])) {
        unset($_COOKIE['rental_pick_up_state']);
        setcookie('rental_pick_up_state', false, time() - (31556952), "/", "", false, false);
    }

    if (isset($_COOKIE['rental_pick_up_address_1'])) {
        unset($_COOKIE['rental_pick_up_address_1']);
        setcookie('rental_pick_up_address_1', false, time() - (31556952), "/", "", false, false);
    }

    if (isset($_COOKIE['rental_pick_up_address_2'])) {
        unset($_COOKIE['rental_pick_up_address_2']);
        setcookie('rental_pick_up_address_2', false, time() - (31556952), "/", "", false, false);
    }

    if (isset($_COOKIE['rental_pick_up_country'])) {
        unset($_COOKIE['rental_pick_up_country']);
        setcookie('rental_pick_up_country', false, time() - (31556952), "/", "", false, false);
    }

    if (isset($_COOKIE['rental_pick_up_postcode'])) {
        unset($_COOKIE['rental_pick_up_postcode']);
        setcookie('rental_pick_up_postcode', false, time() - (31556952), "/", "", false, false);
    }

    unset_delivery_times_data();

    if (isset($_COOKIE['delivery_time_selections_id'])) {
        unset($_COOKIE['delivery_time_selections_id']);
        setcookie('delivery_time_selections_id', false, time() - (31556952), "/", "", false, false);
    }

    if (isset($_COOKIE['pickup_time_selections_id'])) {
        unset($_COOKIE['pickup_time_selections_id']);
        setcookie('pickup_time_selections_id', false, time() - (31556952), "/", "", false, false);
    }

}


function rental_request_product_settings($zip = null) {
	$data = [];
    if (get_option('rental_select_division')) {
        if ( !isset($_COOKIE['rental_division_id'])) {
            throw new RentalException(__('Please select location!', 'rentopian-sync'));
        }
        $data['division_id'] = $_COOKIE['rental_division_id'];
    }
	if ($zip && !get_option('rental_hide_zip')) {
		$data['zip'] = $zip;
	}
	$settings_data = json_decode(rental_curl('inventories', get_option('rental_api_key'), false, $data), true);
	$expiration_date = time() + 300; // 5 minutes from now
	if ( !isset($settings_data['expiration_date']) || $settings_data['expiration_date'] > $expiration_date) {
		$settings_data['expiration_date'] = $expiration_date;
	}

    // EVENT DATE OFFSET: Persist offset settings from API
	if (isset($settings_data['event_date_offsets']) && is_array($settings_data['event_date_offsets'])) {
		$offsets = $settings_data['event_date_offsets'];
		update_option('rental_start_date_offset', isset($offsets['start_date_offset']) ? (int) $offsets['start_date_offset'] : 0);
		update_option('rental_end_date_offset', isset($offsets['end_date_offset']) ? (int) $offsets['end_date_offset'] : 0);
	}

	return $settings_data;
}

/*
 * get product settings from rentopian
 * 
*/
function rental_get_product_settings() {
    // if (get_option('rental_synchronized_product_type') != "hourly") {

        if (get_option('rental_dates_on_checkout', 0) == 1 && get_option('rental_allow_overbook', 1) == 1) {
            // if we have allow overbook and rental dates on checkout page
            
            rental_dates_on_checkout_page_option_effect();
            
        } else {
            // usual journey

            if ( !isset($_COOKIE['rental_zip']) || !isset($_COOKIE['rental_start_date'])) {
                return null;
            }
        }
    // }

    $rental_product_settings = get_option('rental_product_settings');
    if ($rental_product_settings && isset($rental_product_settings['expiration_date']) && $rental_product_settings['expiration_date'] > time()) {
        return $rental_product_settings;
    }

    try {

        $decrypted_rental_zip = '';
        if (isset($_COOKIE['rental_zip'])) {
            $decrypted_rental_zip = decrypt_data($_COOKIE['rental_zip'], get_option('rental_encryption_key'));
        }

        $rental_product_settings = rental_request_product_settings($decrypted_rental_zip);
        // Store product settings data in options
        update_option('rental_product_settings', $rental_product_settings);
        return $rental_product_settings;
        
    } catch (Exception $e) {
        // Handle exception
    }

    return null;
}


// get price multipliers by variant or set id
function get_price_multiplier_items_by_post_id($post_id) {
    global $wpdb, $rental_tables;
    $rental_price_multipliers = $wpdb->prefix . $rental_tables["price_multipliers"];

    $sql = "
        SELECT 
            p.id
            , price_multipliers.title price_multiplier_title
            , price_multipliers.items price_multiplier_items
            , price_multipliers.is_monthly
            , price_multipliers.is_repeat
        FROM 
            {$wpdb->posts} p
        LEFT JOIN {$wpdb->postmeta} pm_price_multiplier ON pm_price_multiplier.post_id = p.id AND pm_price_multiplier.meta_key = '_price_multiplier_id'
        LEFT JOIN {$rental_price_multipliers} price_multipliers ON price_multipliers.id = pm_price_multiplier.meta_value
        WHERE
            p.id = %d
    ";

    $result = $wpdb->get_row( $wpdb->prepare($sql, [$post_id]), ARRAY_A );
    if ($result) {
        $result['price_multiplier_items'] = isset($result['price_multiplier_items']) && $result['price_multiplier_items'] ? unserialize($result['price_multiplier_items']) : '';
        return $result;
    } else {
        return '';
    }
}

// get rental supported divisions
function rental_get_supported_divisions() {
    $settings = rental_get_product_settings();
    return isset($settings['divisions']) ? $settings['divisions'] : [];
}


function rental_get_products_variants_divisions() {

    if ($data = get_rental_cache('rental_products_variants_divisions', 'rental_products_variants_divisions_expiration_date')) {
        return $data;
    }

    global $wpdb, $rental_tables;
    $rental_variant_relations = $wpdb->prefix . $rental_tables["variant_relations"];
    $rental_product_relations = $wpdb->prefix . $rental_tables["product_relations"];

    $result_final = [];
    $sql = "
        SELECT 
            id,
            rental_division_id
        FROM 
            {$rental_variant_relations}
    ";
    $result = $wpdb->get_results($sql, ARRAY_A);

    if ($result) {
        $result_final = array_merge($result_final, $result);
    }

    $sql_prods = "
        SELECT 
            id,
            rental_division_id
        FROM 
            {$rental_product_relations}
    ";
    $result_prods = $wpdb->get_results($sql_prods, ARRAY_A);
   
    if ($result_prods) {
        $result_final = array_merge($result_final, $result_prods);
    }

    set_rental_cache($result_final, 'rental_products_variants_divisions', 'rental_products_variants_divisions_expiration_date');
    
    return $result_final;
}

// if ( ! function_exists( 'rental_get_sets_divisions' ) ) {
//     function rental_get_sets_divisions() {

//         if ($data = get_rental_cache('rental_sets_divisions', 'rental_sets_divisions_expiration_date')) {
//             return $data;
//         }


//         global $wpdb, $rental_tables;
//         $rental_set_relations = $wpdb->prefix . $rental_tables["set_relations"];

//         $result_final = [];

//         $sql_set = "
//             SELECT 
//                 id,
//                 rental_division_id
//             FROM 
//                 {$rental_set_relations}
//         ";
//         $result_set = $wpdb->get_results($sql_set, ARRAY_A);

//         if ($result_set) {
//             $result_final = array_merge($result_final, $result_set);
//         }

//         set_rental_cache($result_final, 'rental_sets_divisions', 'rental_sets_divisions_expiration_date');

//         return $result_final;
//     }
// }

// function to calculate multiplier for 7 days
function rental_get_daily_prices($price_multiplier) {
    $days = [];
    if ($price_multiplier) {
        for ($i = 1; $i <= 7; $i++) {
            $days[$i] = rental_calculate_price_multiplier($price_multiplier, $i);
        }
    }
    return $days;
}

// function for calculate price multiplier
function rental_calculate_price_multiplier($items, $days, $options = []) {
    // Remove any service item up front
    $items = array_filter($items, function($i) {
        return !isset($i['we_rate']);
    });

    // Re-index so $items[0] is always the first remaining element
    $items = array_values($items);

    // If we have no items left, or days is too small for the first bracket, just return days
    if (empty($items) || !isset($items[0]['min']) || $days <= ($items[0]['min'] / 24)) {
        return $days;
    }

    // Apply any monthly/repeat adjustments
    if (!empty($options)) {
        if (!empty($options['is_monthly'])) {
            $items = get_monthly_price_multiplier_days($items, $days);
        } elseif (!empty($options['is_repeat'])) {
            $items = get_repeat_price_multiplier_days($items, $days);
        }
    }

    $coefficient = (($items[0]['min'] ?? 0) / 24);
    $currentDay  = (($items[0]['max'] ?? 0) / 24);

    foreach ($items as $item) {
        // if there’s a gap between brackets for monthly/repeat, add it in
        if (!empty($options['is_monthly']) || !empty($options['is_repeat'])) {
            if (!empty($item['max']) && $currentDay < $item['min'] / 24) {
                $coefficient += ($item['min'] / 24 - $currentDay);
            }
        }
        $currentDay = $item['max'] / 24;

        // if $days falls inside this bracket, add the partial segment and return
        if (empty($item['max']) || $days <= ($item['max'] / 24)) {
            $coefficient += ($days - ($item['min'] / 24)) * $item['multiplier'];
            return $coefficient;
        }

        // otherwise add the full segment for this bracket
        $coefficient += (($item['max'] - $item['min']) / 24) * $item['multiplier'];
    }

    // if we’ve fallen out of the loop, days exceeded all defined brackets
    // add the remainder at a 1× multiplier
    $lastMax = end($items)['max'] / 24;
    $coefficient += ($days - $lastMax);
    return $coefficient;
}

// function rental_calculate_price_multiplier($items, $days, $options = []) {
//     if (empty($items) || $days <= ($items[0]['min'] / 24)) {
//         return $days;
//     }
//     // we need to delete service item to avoid problems
//     foreach($items as $k=>$i) {
//         if(isset($i['we_rate'])) {unset($items[$k]); break;}
//     }    

//     if ($options) {
//         if ($options['is_monthly'] == 1) {
//             $items = get_monthly_price_multiplier_days($items, $days);
//         } else if ($options['is_repeat'] == 1) {
//             $items = get_repeat_price_multiplier_days($items, $days);
//         }
//     }

//     $coefficient = $items[0]['min'] / 24;
//     $currentDay = $items[0]['max'] / 24;
//     foreach($items as $item) {
//         if ($options) {
//             if(($options['is_monthly'] == 1 || $options['is_repeat'] == 1) && $item['max'] && $currentDay < $item['min'] / 24) {
//                 $coefficient += $item['min'] / 24 - $currentDay;
//             }
//         }
//         $currentDay = $item['max'] / 24;

//         if ( !$item['max'] || $days <= ($item['max'] / 24)) {
//             $coefficient += ($days - ($item['min'] / 24)) * $item['multiplier'];
//             return $coefficient;
//         } else {
//             $coefficient += (($item['max'] - $item['min']) / 24) * $item['multiplier'];
//         }
//     }
//     $coefficient += ($days - ($item['max'] / 24));
//     return $coefficient;
// }

function get_monthly_price_multiplier_days($originalItems, $days) {
    $items = [];
    $lastMonth = 0;
    $multipliersLength = count($originalItems);
    $daysLeft = $days;

    $tz = rental_get_timezone();
    // $date_obj = (new DateTime($_COOKIE['rental_start_date'], $tz));
    
    $decrypted_rental_start_date = '';
    if (isset($_COOKIE['rental_start_date'])) {
        $decrypted_rental_start_date = decrypt_data($_COOKIE['rental_start_date'], get_option('rental_encryption_key'));
    }

    if ($decrypted_rental_start_date) {

        $date_obj = (new DateTime($decrypted_rental_start_date, $tz));

        $month = $date_obj->getTimestamp();
        
        $monthDays = date('t', $month);
        if ($monthDays > $daysLeft) {
            $monthDays = $daysLeft;
        }
        for ($d = 0; $d < $days; $d += $lastMonth) {
            for ($multiplier = 0; $multiplier < $multipliersLength; $multiplier++) {
                $max = $originalItems[$multiplier]['max'];
                $min = $originalItems[$multiplier]['min'];
                $offset = ($days - $daysLeft) * 24;
                if($min / 24 < $monthDays) {
                    $maxMax = $offset + $monthDays * 24;
                    $item = unserialize(serialize($originalItems[$multiplier]));
                    $item['max'] = $max && ($max + $offset < $maxMax) ? $max + $offset : $maxMax;
                    $item['min'] = $min + $offset;
                    $items[] = $item;
                }
            }

            $lastMonth = $monthDays;
            $daysLeft -= $lastMonth;
            $date_obj->modify('+1 month'); 
            $month = $date_obj->getTimestamp();
            $monthDays = date('t', $month);
            if ($monthDays > $daysLeft) {
                $monthDays = $daysLeft;
            }
        }
    }
    
    return $items;
}

function get_repeat_price_multiplier_days($originalItems, $days) {
    $items = [];
    $multipliersLength = count($originalItems);
    $daysLeft = $days;
    $multiDays = 0;

    for($d = 0; $d < $days; $d += $multiDays) {
        $multiDays = $originalItems[0]['min'] / 24;
        for ($multiplier = 0; $multiplier < $multipliersLength; $multiplier++) {
            $max = $originalItems[$multiplier]['max'];
            $min = $originalItems[$multiplier]['min'];
            $multiDays += ($max - $min) / 24;
            $offset = ($days - $daysLeft) * 24;
            if ($min / 24 < $daysLeft) {
                $maxMax = $days * 24;
                $item = unserialize(serialize($originalItems[$multiplier]));
                $item['max'] = $max && ($max + $offset < $maxMax) ? $max + $offset : $maxMax;
                $item['min'] = $min + $offset;
                $items[] = $item;
            }
        }

        $daysLeft -= $multiDays;
        if ($daysLeft < 0) {
            $multiDays = $daysLeft + $multiDays;
            $daysLeft = 0;
        }
    }
    return $items;
}

// function for get tax
function rental_get_tax() {
    global $woocommerce;

    if (get_option('rental_synchronized_product_type') == "hourly") {
        if ( !isset($_COOKIE["rental_hourly_start_date"])) {
            return null;
        }
    } else {
        if (get_option('rental_dates_on_checkout', 0) == 1 && get_option('rental_allow_overbook', 1) == 1) {
            // if we have allow overbook and rental dates on checkout page
            rental_dates_on_checkout_page_option_effect();
            
        } else {
            // usual journey
            if ( !isset($_COOKIE['rental_zip']) || !isset($_COOKIE['rental_start_date'])) {
                return null;
            }

        }
    }

    try {

        $zip = strip_zip_code_extensions($woocommerce->customer->get_shipping_postcode());
        $zip_key = 'rental_taxes_' . md5( (string) $zip );
        $expire_time = 600; // 10 minutes
        $rental_tax_type = get_option('rental_tax_type', 1);
        
        $cached = get_transient($zip_key);
        if ( $cached && isset($rental_tax_type) && $rental_tax_type == 2 ) {
            return $cached;
        }

        $shipping_city = $woocommerce->customer->get_shipping_city();
        $shipping_address = $woocommerce->customer->get_shipping_address_1();
        if ($shipping_address && $woocommerce->customer->get_shipping_address_2()) {
            $shipping_address .= ' ' . $woocommerce->customer->get_shipping_address_2();
        }

        $taxes = NULL;
        if (!empty($shipping_city)
            && !empty($shipping_address) 
            && !empty($zip) 
        ) {

            $taxes = json_decode(rental_curl('taxes', get_option('rental_api_key'), false, [
                'zip' => $zip, 
                'address' => $shipping_address, 
                'city' => $shipping_city
            ]), true);
        }

        if ($taxes) {
            
            if ( isset($taxes['auto_tax']) && $taxes['auto_tax'] == 1 && !empty($taxes['auto_tax_rates']) ) {
                
                // auto tax
                if (isset($rental_tax_type) && $rental_tax_type != 2) {
                    update_option('rental_tax_type', 2);
                }
                
                set_transient($zip_key, $taxes, $expire_time);

            } else {

                // manual tax
                if (isset($rental_tax_type) && $rental_tax_type != 1) {
                    update_option('rental_tax_type', 1);
                }

                update_option('rental_auto_tax', 0);
            }

            return $taxes;
        }
        
    } catch (Exception $e) {

    }

    return null;
}

function get_shop_address($rental_divisions) {
    //check if rental division is only one then just send its address
    if( count($rental_divisions) < 2 ){
        $shipping_rental_divison = $rental_divisions[0];
    }
    //else find selected division or use main division
    else{
        // if rental select division option is enabled and divion set in session
        if(get_option('rental_select_division') && (isset($_COOKIE['rental_division_id']) && !empty($_COOKIE['rental_division_id'])) ){
            $selected_division = $_COOKIE['rental_division_id'];

            $shipping_rental_divison = '';
            foreach( $rental_divisions as $division ){
                if($division->id == $selected_division){
                    $shipping_rental_divison = $division;
                    break;
                }
            }
        }
        //retrieve main division form options
        else{
            $shipping_rental_divison = '';
            foreach( $rental_divisions as $division ){
                if($division->main_division == 1){
                    $shipping_rental_divison = $division;
                    break;
                }
            }
        }

    }
    // get divions address object
    // $store_divison = $shipping_rental_divison->address;
    return $shipping_rental_divison->address->zip;
}

// Get the damage waiver information
function rental_get_damage_waiver() {
    $product_settings = rental_get_product_settings();

    return (isset($product_settings['damage_waiver']))? $product_settings['damage_waiver']: null;
}

// Whether a damage waiver fee can apply to the current cart, ignoring the buy/opt out choice.
// Sale and waiver-exempt products are excluded, matching rental_calculate_order_total().
function rental_cart_has_damage_waiver_fee() {
    $damage_waiver = rental_get_damage_waiver();

    if (empty($damage_waiver['damage_waiver']) || !function_exists('WC') || !WC()->cart) {
        return false;
    }

    foreach (WC()->cart->get_cart() as $item) {

        if ( !get_post_meta($item['product_id'], '_rental_exempt_waiver', true)
            && !get_post_meta($item['product_id'], '_rental_is_sale', true)
        ) {
            return true;
        }
    }

    return false;
}

// Current damage waiver choice: 'buy' or 'exempt'
function rental_get_damage_waiver_choice() {
    if (isset($_COOKIE['rental_exempt_waiver'])) {
        return $_COOKIE['rental_exempt_waiver'] ? 'exempt' : 'buy';
    }

    return get_option('rental_buy_damage_waiver_by_default') ? 'buy' : 'exempt';
}

// Render the damage waiver "buy / opt out" selector
function rental_render_damage_waiver_field($wrapper_id) {
    $options = [
        'buy' => __('Buy Damage Waiver', 'rentopian-sync'),
        'exempt' => __('Opt out of Damage Waiver', 'rentopian-sync'),
    ];

    echo '<div id="' . esc_attr($wrapper_id) . '" class="rntp-form-block">';
    echo '<h3>' . esc_html__('Damage Waiver', 'rentopian-sync') . '</h3>';

    woocommerce_form_field('damage_waiver', [
        'type' => 'radio',
        'class' => ['radio-toolbar'],
        'required' => true,
        'label_class' => ['rntp-label'],
        'input_class' => ['rntp-radio'],
        'options' => $options,
    ], rental_get_damage_waiver_choice());

    echo '</div>';
}

// Whether the damage waiver selector is shown on the cart page
function rental_is_damage_waiver_shown_on_cart() {
    return (bool) get_option('rental_show_damage_waiver_on_cart')
        && function_exists('rental_is_damage_waiver_enabled')
        && rental_is_damage_waiver_enabled();
}

/**
 * Special terms as display-ready HTML.
 *
 * The editor stores paragraphs as blank lines, so the value is run through
 * wpautop() before it is sanitized for output. Every place that shows the
 * special terms must use this so the rendered markup matches what was saved.
 *
 * @return string Sanitized HTML, or an empty string when nothing is set.
 */
function rental_get_special_terms_html() {
    $special_terms = (string) get_option('rental_special_terms');

    if (trim($special_terms) === '') {
        return '';
    }

    return wp_kses_post(wpautop($special_terms));
}

// Get the date time zone information
function rental_get_timezone() {
    $product_settings = rental_get_product_settings();

    return isset($product_settings['timezone']) && $product_settings['timezone']? new DateTimeZone($product_settings['timezone']): null;
}

// Get the rush fee information
function rental_get_rush_fee() {
    $product_settings = rental_get_product_settings();

    return (isset($product_settings['rush_fee']))? $product_settings['rush_fee']: null;
}

// Get the rush fee information
function rental_get_delivery_tax_setting() {
    $product_settings = rental_get_product_settings();

    $settings = [
        'delivery_tax' => isset($product_settings['delivery_tax']) ? $product_settings['delivery_tax'] : NULL ,
        'exclude_delivery_cost_from_taxable' => isset($product_settings['exclude_delivery_cost_from_taxable']) ? $product_settings['exclude_delivery_cost_from_taxable'] : NULL,
    ];

    return $settings;
}

// Get suitable rush fee
function rental_get_suitable_rush_fee() {

    $start_date = "";
    if (get_option('rental_synchronized_product_type') == "hourly") {
        
        $start_date = (isset($_COOKIE["rental_hourly_start_date"]) && $_COOKIE["rental_hourly_start_date"]) ? $_COOKIE["rental_hourly_start_date"] : "";
    } else {

        $decrypted_rental_start_date = "";
        if ( (isset($_COOKIE['rental_start_date']) && $_COOKIE['rental_start_date']) ) {

            $decrypted_rental_start_date = decrypt_data($_COOKIE['rental_start_date'], get_option('rental_encryption_key'));
        }

        $start_date = $decrypted_rental_start_date;
    }

    if ($start_date && isset($_COOKIE['rental_zip']) && $_COOKIE['rental_zip']) {

        if ($rush_fee = rental_get_rush_fee()) {

            $tz = rental_get_timezone();
            $start = (new DateTime($start_date, $tz))->getTimestamp();
            $diff = $start - time();
            foreach ($rush_fee as $fee) {
                if ($diff <= $fee['by_hours'] * 3600) { // 1 hour = 3600 seconds
                    return $fee;
                }
            }
        }

    }

    return [
        'days' => 0,
        'hours' => 0,
        'type' => 0,
        'amount' => 0,
    ];
}

// Calculate rush fee
function rental_calculate_rush_fee($subtotal) {

    $rush_fee = rental_get_suitable_rush_fee();

    if ($rush_fee['type'] == 2) {
        return ($subtotal * $rush_fee['amount'] / 100);
    }

    return $rush_fee['amount'];
}

// Get the deposit information
function rental_get_deposit() {
    $product_settings = rental_get_product_settings();
    return (isset($product_settings['deposit']))? $product_settings['deposit']: null;
}

// Get the security deposit information
function rental_get_security_deposit() {
    $product_settings = rental_get_product_settings();
    return (isset($product_settings['security_deposit']))? $product_settings['security_deposit']: null;
}

// Get auto applied order fees
function rental_get_auto_applied_fees() {
    $product_settings = rental_get_product_settings();

    return (isset($product_settings['auto_applied_fees']))? $product_settings['auto_applied_fees']: null;
}

function rental_get_coupon_settings() {
    $product_settings = rental_get_product_settings();

    return (isset($product_settings['coupon_settings']))? $product_settings['coupon_settings']: null;
}

// Get applied coupons
function get_applied_coupons() {
    $coupons = [];
    $cart = WC()->cart;
    $coupons_applied = !empty($cart->applied_coupons) ? $cart->applied_coupons : [];
    if (!empty($coupons_applied)) {
        foreach($coupons_applied as $app_coupon) {
            $coupon = new WC_Coupon($app_coupon);
            $coupons[] = [
                'code' => $coupon->get_code(),
                'amount' => $coupon->get_amount(),
                'discount_type' => $coupon->get_discount_type(),
            ];
        }
    }

    return $coupons;
}

function get_coupon_id_by_title($coupon_title) {
    $coupons = get_posts([
        'post_type' => 'shop_coupon',
        'post_status' => 'publish',
        'posts_per_page' => 1, // We only need one result
        'fields' => 'ids', // We only need the post IDs
        'title' => $coupon_title // Filter by coupon title
    ]);

    if ($coupons) {
        return $coupons[0]; 
    } else {
        return false; 
    }
}

/**
 * Whether every chosen shipping rate is a local pickup rate.
 * Falls back to the WC session when no rates are passed in.
 * Returns false when no rate is chosen.
 *
 * @param array|null $chosen_methods Chosen rate ids, or null to read the session.
 *
 * @return bool
 */
function rental_is_chosen_shipping_all_local_pickup( $chosen_methods = null ) {
    if ( null === $chosen_methods ) {
        if ( ! function_exists( 'WC' ) || ! WC()->session ) {
            return false;
        }
        $chosen_methods = WC()->session->get( 'chosen_shipping_methods', array() );
    }
    $chosen_methods = array_filter( (array) $chosen_methods );
    if ( empty( $chosen_methods ) ) {
        return false;
    }
    foreach ( $chosen_methods as $chosen_id ) {
        if ( ! is_string( $chosen_id ) || $chosen_id === '' ) {
            return false;
        }
        $method_base = (string) current( explode( ':', $chosen_id, 2 ) );
        if ( ! in_array( $method_base, array( 'local_pickup', 'pickup_location' ), true ) ) {
            return false;
        }
    }
    return true;
}

/**
 * Whether checkout must be limited to the full payment option because the
 * customer picked a delivery rate. Only applies to Rentopian shipping, when
 * both delivery and pickup are offered and direct bookings are allowed.
 *
 * @param array|null $chosen_methods Chosen rate ids, or null to read the session.
 *
 * @return bool
 */
function rental_is_full_payment_required_for_delivery( $chosen_methods = null ) {
    if ( ! get_option( 'rental_full_payment_for_delivery' ) ) {
        return false;
    }
    if ( get_option( 'rental_do_not_use_rentopian_shipping' ) ) {
        return false;
    }
    if ( ! get_option( 'rental_direct_only_bookings' ) ) {
        return false;
    }
    if ( get_option( 'rental_pickup_delivery', 'company_delivery_return' ) !== 'company_client_delivery_return' ) {
        return false;
    }
    if ( ! function_exists( 'WC' ) || ! WC()->cart || ! WC()->cart->needs_shipping() ) {
        return false;
    }

    return ! rental_is_chosen_shipping_all_local_pickup( $chosen_methods );
}

// Calculate cart fees
function rental_calculate_order_total() {
    if( !rental_get_product_settings()) {
        return null;
    }

    $data = [
        'labor_cost' => 0,
        'shipping' => 0,
        'shipping_tax_id' => 0,
        'shipping_tax_rate' => 0,
        'shipping_tax' => 0,
        'rush_fee' => 0,
        'rental_tax' => 0,
        'rental_tax_id' => 0,
        'rental_tax_rate' => 0,
        'sale_tax' => 0,
        'sale_tax_id' => 0,
        'sale_tax_rate' => 0,
        'service_tax' => 0,
        'service_tax_id' => 0,
        'service_tax_rate' => 0,
        'damage_waiver' => 0,
        'damage_waiver_tax_id' => 0,
        'damage_waiver_tax_rate' => 0,
        'damage_waiver_tax' => 0,
        'discount_before_tax' => 0,
        'coupon_discount' => 0,
        'coupons' => [],
        'order_fees' => [],
        'security_deposit_fee' => 0,
        'tip' => 0,
    ];

    
    $rental_product_subtotal = get_rental_session_data('rental_product_subtotal', 0);
    // $rental_product_subtotal = get_option('rental_product_subtotal' . get_new_unique_id(), 0);

    $cart = WC()->cart;
    $subtotal = !empty($rental_product_subtotal) ? $rental_product_subtotal : $cart->get_subtotal();

    $total = $subtotal;
    $tax = rental_get_tax();
    $rental_taxable = 0;
    $sale_taxable = 0;
    $service_taxable = 0;
    $damage_waiver = rental_get_damage_waiver();
    if ($damage_waiver && !get_option('rental_hide_and_buy_damage_waiver_by_default')) {
        if (isset($_COOKIE['rental_exempt_waiver']) && !get_option('rental_hide_damage_waiver')) {
            if ($_COOKIE['rental_exempt_waiver']) {
                $damage_waiver = null;
            }
        } elseif ( !get_option('rental_buy_damage_waiver_by_default')) {
            $damage_waiver = null;
        }

        if (get_option('rental_hide_damage_waiver')) {
            $damage_waiver = null;
        }
    }


    $damage_waiver_subtotal = 0;
    foreach ($cart->get_cart() as $item) {
        
        $id = $item['variation_id']?: $item['product_id'];

        $price = 0;
        if ( !(isset($item['set_id']) && $item['set_id'])) {
            $price = $item['data']->get_price() * $item['quantity'];
        }

        // calculating set items with optional items price as taxable
        if ( isset($item['set_id']) && $item['set_id'] ) {
	
            $item_set_id = $item["set_id"];
            
            if ($set_ids_with_optional_items = get_set_ids_with_optional_items()) {
                
                foreach($set_ids_with_optional_items as $set_with_optional_items_id => $selected_product_key_variant_or_product_value_array) {

                    if ($set_with_optional_items_id == $item_set_id) {

                        foreach($selected_product_key_variant_or_product_value_array as $pid => $selected_variant_or_product_id) {

                            if ($pid == $item["product_id"]) {

                                if ($selected_variant_or_product_id == $item['variation_id']
                                    || $selected_variant_or_product_id == $item['product_id']
                                ) {

                                    // set item price (taxable value)
                                    $price = $item['data']->get_price() * $item['quantity'];
                                }

                                if (empty($price)) {

                                    $selected_variant_or_product_parent_id = 0;
                                    $selected_variant_or_product_parent = get_post($selected_variant_or_product_id);
                                    if ($selected_variant_or_product_parent->post_parent) {
                                        $selected_variant_or_product_parent_id = $selected_variant_or_product_parent->post_parent;
                                    }

                                    if ($selected_variant_or_product_parent_id == $item['product_id']) {

                                        // set item price (taxable value)
                                        $price = $item['data']->get_price() * $item['quantity'];
                                    }
                                }
                            
                            }
                        }
                    }
                }
            }
            
            if ($item['rental_add_on_price']) {
                // set item's addon price (taxable value)
                $price = $item['rental_add_on_price'] * $item['quantity'];
            }
        }

       
        $price_for_damage_waiver = $item['data']->get_price() * $item['quantity'];
        
        // calculate job cost of the product/variant/set item
        if ( $job_cost = get_post_meta($id, '_job_cost', true)) {
            $data['labor_cost'] += ($job_cost * $item['quantity']);
        }

        if (!empty($tax) && get_post_meta($id, '_tax_status', true) == 'taxable') {

            if ( !is_null($tax['sale_tax_rate']) && get_post_meta($item['product_id'], '_rental_is_sale', true)) {

                $sale_taxable += $price;
            } else {

                $rental_taxable += $price;
            }
        }

        if ($damage_waiver && !get_post_meta($item['product_id'], '_rental_exempt_waiver', true) && !get_post_meta($item['product_id'], '_rental_is_sale', true)) {
            $damage_waiver_subtotal += $price_for_damage_waiver;
        }
    }


    // Add labor cost to total
    $total += $data['labor_cost'];
    $labor_cost_taxable = 0;
    if(isset($tax['job_cost_taxable']) && $tax['job_cost_taxable']){
        $labor_cost_taxable = $data['labor_cost'];
    }
  

    // Add rush fee to total
    $data['rush_fee'] = rental_calculate_rush_fee($subtotal);

    $total += $data['rush_fee'];

    $rush_fee_taxable = 0;
    if(isset($tax['rush_fee_taxable']) && $tax['rush_fee_taxable']){
        $rush_fee_taxable = $data['rush_fee']; 
    }

    $rush_fee_calculated = 0;

    if ( get_option( 'rental_do_not_use_rentopian_shipping' ) ) {

        $shipping_fee = (float) $cart->get_shipping_total();
    } else {

        $session_delivery_cost = (float) get_rental_session_data( 'rental_delivery_cost', 0 );
        $session_pickup_cost   = (float) get_rental_session_data( 'rental_pickup_cost', 0 );
        $charge_only_delivery  = (int) get_rental_session_data( 'charge_only_delivery_for_website', 0 );

        if ( rental_is_chosen_shipping_all_local_pickup() ) {
            // When the customer chose Will Call, neither leg is charged.
            $shipping_fee = (float) $cart->get_shipping_total();
        } else {
            $shipping_fee = $session_delivery_cost
                + ( $charge_only_delivery ? 0 : $session_pickup_cost );
        }
    }
    $data['shipping'] = $shipping_fee;

    $total += $shipping_fee;
    if ($shipping_fee > 0 && !empty($tax) && !empty($tax['shipping_tax_id']) ) {
        $data['shipping_tax_id'] = $tax['shipping_tax_id'];
        $data['shipping_tax_rate'] = $tax['shipping_tax_rate'];
    }
    
    // Add damage waiver fee to total
    if ($damage_waiver_subtotal && !empty($damage_waiver['damage_waiver'])) {
        $damage_waiver_fee = $damage_waiver_subtotal * $damage_waiver['damage_waiver'] / 100;
        $data['damage_waiver'] = $damage_waiver_fee;

        $total += $damage_waiver_fee;
        if ( !empty($damage_waiver['tax_id'])) {
            $data['damage_waiver_tax_id'] = $damage_waiver['tax_id'];
            $data['damage_waiver_tax_rate'] = $damage_waiver['tax_rate'];
        }
    }

    // Get applied woocommerce coupons from cart
    $coupons = get_applied_coupons();

    // Add coupon discount fee to total before taxes
    $discount_before_tax = !empty(rental_get_coupon_settings()) ? rental_get_coupon_settings()["discount_before_tax"] : NULL;
    $exclude_delivery_from_coupon = !empty(rental_get_coupon_settings()) ? rental_get_coupon_settings()["exclude_delivery_from_coupon"] : NULL;
    

    $discountable_total = $total;
    $ratio = 0;
    if (!empty($coupons) && !empty($discount_before_tax) && $discount_before_tax == 1) {
        $coupon_discount = 0;
        foreach($coupons as $key=>$coupon) {
            if ($coupon['discount_type'] == 'percent') {

                if ($exclude_delivery_from_coupon == 1) {
                    $discountable_total -= $shipping_fee;

                    if ($data['shipping_tax']) {
                        $discountable_total -= $data['shipping_tax'];
                    }
                }

                $coupon_discount = $discountable_total * $coupon['amount'] / 100;

                // $coupon_discount = $total * $coupon['amount'] / 100;

            } else if ($coupon['discount_type'] == 'fixed_cart') {
                $coupon_discount = $coupon['amount'];
            }
            if ($coupon_discount > $discountable_total) {
                $coupon_discount = $discountable_total;
            }
            $data['discount_before_tax'] = 1;


            $ratio = ($discountable_total > 0) ? 1 - ($coupon_discount / $discountable_total) : 0;

            // effecting the discount on the products that were calculated before this line
            $rental_taxable = $rental_taxable * $ratio;

            $sale_taxable = $sale_taxable * $ratio;
            $service_taxable = $service_taxable * $ratio;

            if ($exclude_delivery_from_coupon != 1) {
                $shipping_fee *= $ratio;
            }

            if ($damage_waiver && $data['damage_waiver_tax_rate']) {
                $damage_waiver_fee *= $ratio;
            }

            if (isset($tax['rush_fee_taxable']) && $tax['rush_fee_taxable']){
                $rush_fee_taxable *= $ratio;
            }

            if(isset($tax['job_cost_taxable']) && $tax['job_cost_taxable']){
                $labor_cost_taxable *= $ratio;
            }

            $data['coupons'][$key] = [
                'discount_type' => $coupon['discount_type'],
                'coupon_discount' => $coupon_discount,
                'amount' => $coupon['amount'],
                'code' => $coupon['code'],
            ];

            $data['coupon_discount'] = $coupon_discount;

            set_rental_session_data('rental_coupon_discount', $coupon_discount);

            break;
        }

        $total -= $coupon_discount;
    }


    $delivery_tax_settings = rental_get_delivery_tax_setting();

    $delivery_taxable = 0;
    // Add tax fees to total
    if (!empty($tax)) {

        // add shipping fee to subtotal items price and then calculate Tax
        if ($shipping_fee && !$delivery_tax_settings['delivery_tax'] && !$delivery_tax_settings['exclude_delivery_cost_from_taxable']) {
            
            $delivery_taxable = $shipping_fee;
        }
        
        if (isset($tax['auto_tax']) && $tax['auto_tax'] == 1) {
            // auto tax
            $data['is_auto_tax'] = 1;
            if(!empty($tax['auto_tax_rates'])) {
                if (!empty($tax['auto_tax_rates']['totalRate'])) {
                    $data['auto_tax_rate'] = $tax['auto_tax_rates']['totalRate'] * 100;

                    $data['auto_tax'] = 0;
                    if (!empty($tax['sale_tax_rate'])) {

                        if(isset($tax['rush_fee_taxable']) && $tax['rush_fee_taxable']){
                            $sale_taxable += $rush_fee_taxable;
                            $rush_fee_calculated = 1;
                        }

                        // apply auto tax to damage waiver
                        if ($damage_waiver && isset($tax['auto_tax']) && $tax['auto_tax'] == 1 && isset($tax['apply_auto_tax_to_dw']) && $tax['apply_auto_tax_to_dw'] == 1) {
                            
                            $sale_taxable += $damage_waiver_fee;
                            
                            $data['damage_waiver_tax_rate'] = $data['auto_tax_rate'];
                            $data['damage_waiver_tax_id'] = 0;
                            $data['damage_waiver_tax'] = 0;

                        } 

                        $data['auto_tax'] = format_value_to_fixed_precision($sale_taxable * $tax['auto_tax_rates']['totalRate'], 2);

                    } 
                    
                    // if ($rental_taxable) {
                    
                        // rental taxable
                        if($rush_fee_calculated == 0 && isset($tax['rush_fee_taxable']) && $tax['rush_fee_taxable']){
                            $rental_taxable += $rush_fee_taxable;
                            $rush_fee_calculated = 1;
                        }

                        // apply auto tax to damage waiver
                        if ($damage_waiver && isset($tax['auto_tax']) && $tax['auto_tax'] == 1 && isset($tax['apply_auto_tax_to_dw']) && $tax['apply_auto_tax_to_dw'] == 1) {
                                          
                            $rental_taxable += $damage_waiver_fee;
                            
                            $data['damage_waiver_tax_rate'] = $data['auto_tax_rate'];
                            $data['damage_waiver_tax_id'] = 0;
                            $data['damage_waiver_tax'] = 0;

                        } 

                        if ($delivery_taxable) {
                            $rental_taxable += $delivery_taxable;
                        }

                        if(isset($tax['job_cost_taxable']) && $tax['job_cost_taxable']){
                            $rental_taxable += $labor_cost_taxable;
                        }

                        $data['auto_tax'] = format_value_to_fixed_precision($rental_taxable * $tax['auto_tax_rates']['totalRate'], 2);

                    // }

                    if (!empty($tax['auto_tax_rates']['rates'])) {
                        $data['rental_auto_tax_rates'] = [];
                        foreach($tax['auto_tax_rates']['rates'] as $rate) {
                            $rate_data = $rate['name'];
                            $rate_data .= ' - ' . $rate['type'];
                            $rate_data .= '(' . format_value_to_fixed_precision(($rate['rate'] * 100), 3) . '%)';
                            $data['rental_auto_tax_rates'][] = [
                                "name" => $rate_data,
                                "value" => $rental_taxable * $rate['rate'],
                            ];
                        }
                    }

                    $total += $data['auto_tax'];
                }

            }

        } else {
            // manual tax

            // service tax
            if (isset($tax['service_tax_rate']) && $tax['service_tax_rate']) {
                $data['service_tax_id'] = $tax['service_tax_id'];

                if (isset($tax['rush_fee_taxable']) && $tax['rush_fee_taxable']){
                    $service_taxable += $rush_fee_taxable;
                    $rush_fee_calculated = 1;
                }

                $data['service_tax_rate'] = $tax['service_tax_rate'];
                $data['service_tax'] = format_value_to_fixed_precision($service_taxable * $tax['service_tax_rate'] / 100, 2);

                $total += $data['service_tax'];
            }

            // sale tax
            if (!empty($tax['sale_tax_rate'])) {
                $data['sale_tax_id'] = $tax['sale_tax_id'];

                if ($rush_fee_calculated == 0 && isset($tax['rush_fee_taxable']) && $tax['rush_fee_taxable']){
                    $sale_taxable += $rush_fee_taxable;

                    $rush_fee_calculated = 1;
                }

                $data['sale_tax_rate'] = $tax['sale_tax_rate'];
                $data['sale_tax'] = format_value_to_fixed_precision($sale_taxable * $tax['sale_tax_rate'] / 100, 2);

                $total += $data['sale_tax'];
            }

            
            // rental tax
            $data['rental_tax_id'] = $tax['rental_tax_id'];
            if ($tax['rental_tax_rate']) {

                if ($rush_fee_calculated == 0 && isset($tax['rush_fee_taxable']) && $tax['rush_fee_taxable']){
                    $rental_taxable += $rush_fee_taxable;

                    $rush_fee_calculated = 1;
                }

                $data['rental_tax_rate'] = $tax['rental_tax_rate'];

                if ($delivery_taxable) {
                    $rental_taxable += $delivery_taxable;
                }

                if(isset($tax['job_cost_taxable']) && $tax['job_cost_taxable']){
                    $rental_taxable += $labor_cost_taxable;
                }

                $data['rental_tax'] = format_value_to_fixed_precision($rental_taxable * $tax['rental_tax_rate'] / 100, 2);
               
                $total += $data['rental_tax'];
            }

        }
    }

    

    if ($shipping_fee && $delivery_tax_settings['delivery_tax']) {
        if ($data['shipping_tax_rate']) {

            $data['shipping_tax'] = $shipping_fee * $data['shipping_tax_rate'] / 100;

            $total += $data['shipping_tax'];
        }
    }
    
    if ($damage_waiver && $data['damage_waiver_tax_rate']) {
        if ( !(isset($tax['auto_tax']) && $tax['auto_tax'] == 1 && isset($tax['apply_auto_tax_to_dw']) && $tax['apply_auto_tax_to_dw'] == 1)) {
            $data['damage_waiver_tax'] = format_value_to_fixed_precision($damage_waiver_fee * $data['damage_waiver_tax_rate'] / 100, 2);
            
            $total += $data['damage_waiver_tax'];
        }
    }
    
    $coupon_after_tax_is_caculated = false;
    $discountable_total = $total;
    $coupon_discount = 0;

    $auto_applied_fees = rental_get_auto_applied_fees();
    if ( $auto_applied_fees && !get_option('rental_exclude_order_fees', 0)) {

        $condition = RELATION_LOGISTICS_COMPANY_PICK_UP_COMPANY_RETURN;
        if (!get_option('rental_do_not_use_rentopian_shipping')) {

            if (get_option('rental_pickup_delivery') == 'company_delivery_return') {
                $condition = RELATION_LOGISTICS_COMPANY_PICK_UP_COMPANY_RETURN;
            } else if (get_option('rental_pickup_delivery') == 'client_pickup_return') {
                $condition = RELATION_LOGISTICS_CLIENT_PICK_UP_CLIENT_RETURN;
            } else if (get_option('rental_pickup_delivery') == 'company_client_delivery_return') {
                
                if (isset($_COOKIE['rental_shipping_method'])) {
                    if ($_COOKIE['rental_shipping_method'] == 1) {
                        $condition = RELATION_LOGISTICS_COMPANY_PICK_UP_COMPANY_RETURN;
                    } else {
                        $condition = RELATION_LOGISTICS_CLIENT_PICK_UP_CLIENT_RETURN;
                    }
                }
            }
        }


        // Temp calculating for coupon only (to have $coupon_discount)
        foreach ($auto_applied_fees as $key => $fee) {
            if ( !empty($fee['conditions']) && !in_array($condition, $fee['conditions'])) {
                continue;
            }

            if ($fee['is_percent']) {
                continue;
            }

            $fee['auto_fee_id'] = $fee['id'];
            $fee['percent'] = 0;

            // only used in fixed auto applied fee amounts
            $fee_amount_taxable = !$fee['is_percent'] ? $fee['amount'] : 0;

            if (!$fee['is_percent']) {
                
                if ($fee['taxable']) {
                    
                    if(isset($data['is_auto_tax']) && $data['is_auto_tax'] == 1) {
                        
                        if (isset($tax['auto_tax']) && $tax['auto_tax'] == 1 && isset($data['auto_tax'])) {

                            $temp_auto_tax = $data['auto_tax'];

                            $fee_amount_taxable = $fee['amount'];
                            if (!empty($coupons) && !empty($discount_before_tax) && $discount_before_tax == 1 && !empty($ratio)) {
                                $fee_amount_taxable *= $ratio;
                            }

                            $rental_taxable += $fee_amount_taxable;

                            if (!empty($tax['auto_tax_rates']) && !empty($tax['auto_tax_rates']['totalRate'])) {
                                $discountable_total -= $temp_auto_tax;
                                $temp_auto_tax = format_value_to_fixed_precision($rental_taxable * $tax['auto_tax_rates']['totalRate'], 2);
                                $discountable_total += $temp_auto_tax;
                            }

                            $rental_taxable -= $fee_amount_taxable;
                        
                        } 

                    } else {
                            // manual tax
                            // must prioritize the service and then sale and then rental tax in tax calculations

                            $fee_tax_calculated = false;
                            if (!empty($tax['service_tax_rate'])) {
                                $fee_tax_calculated = true;

                                $temp_service_tax = $data['service_tax'];

                                $fee_amount_taxable = $fee['amount'];
                                if (!empty($coupons) && !empty($discount_before_tax) && $discount_before_tax == 1 && !empty($ratio)) {
                                    $fee_amount_taxable *= $ratio;
                                }

                                $service_taxable += $fee_amount_taxable;

                                $discountable_total -= $temp_service_tax;
                                $temp_service_tax = format_value_to_fixed_precision($service_taxable * $tax['service_tax_rate'] / 100, 2);
                                $discountable_total += $temp_service_tax;

                                $service_taxable -= $fee_amount_taxable;
                            }

                            if (!empty($tax['sale_tax_rate']) && !$fee_tax_calculated) {
                                $fee_tax_calculated = true;

                                $temp_sale_tax = $data['sale_tax'];

                                $fee_amount_taxable = $fee['amount'];
                                if (!empty($coupons) && !empty($discount_before_tax) && $discount_before_tax == 1 && !empty($ratio)) {
                                    $fee_amount_taxable *= $ratio;
                                }

                                $sale_taxable += $fee_amount_taxable;

                                $discountable_total -= $temp_sale_tax;
                                $temp_sale_tax = format_value_to_fixed_precision($sale_taxable * $tax['sale_tax_rate'] / 100, 2);
                                $discountable_total += $temp_sale_tax;

                                $sale_taxable -= $fee_amount_taxable;
                            }

                            if (!empty($tax['rental_tax_rate']) && !$fee_tax_calculated) {

                                $temp_rental_tax = $data['rental_tax'];

                                $fee_amount_taxable = $fee['amount'];
                                if (!empty($coupons) && !empty($discount_before_tax) && $discount_before_tax == 1 && !empty($ratio)) {
                                    $fee_amount_taxable *= $ratio;
                                }

                                $rental_taxable += $fee_amount_taxable;

                                $discountable_total -= $temp_rental_tax;
                                $temp_rental_tax = format_value_to_fixed_precision($rental_taxable * $tax['rental_tax_rate'] / 100, 2);
                                $discountable_total += $temp_rental_tax;


                                $rental_taxable -= $fee_amount_taxable;
                            }

                    }

                }

                if (!$coupon_after_tax_is_caculated) {
                    if (!empty($coupons) && $discount_before_tax != 1) {
                      
                        foreach($coupons as $key=>$coupon) {
                            if ($coupon['discount_type'] == 'percent') {
            
                                if ($exclude_delivery_from_coupon == 1) {
                                    $discountable_total -= $shipping_fee;
            
                                    if ($data['shipping_tax']) {
                                        $discountable_total -= $data['shipping_tax'];
                                    }
                                }
            
                                $coupon_discount = $discountable_total * $coupon['amount'] / 100;
            
                            } else if ($coupon['discount_type'] == 'fixed_cart') {
                                $coupon_discount = $coupon['amount'];
                            }
                            if ($coupon_discount > $total) {
                                $coupon_discount = $total;
                            }
            
                            set_rental_session_data('rental_coupon_discount', $coupon_discount);
            
                            $data['coupon_discount'] = $coupon_discount;
                            break;
                        }
                    }
                }

            }
        }

        $total_fees = 0;
        foreach ($auto_applied_fees as $key => $fee) {
            if ( !empty($fee['conditions']) && !in_array($condition, $fee['conditions'])) {
                continue;
            }

            $fee['auto_fee_id'] = $fee['id'];
            $fee['percent'] = 0;

            // only used in fixed auto applied fee amounts
            $fee_amount_taxable = !$fee['is_percent'] ? $fee['amount'] : 0;

            if ($fee['is_percent']) {

                $included_total = 0;
                if ($fee['include_products']) {
                    $included_total += $subtotal;
                }
                if ($fee['include_job_cost']) {
                    $included_total += $data['labor_cost'];
                }

                
                if (isset($data['is_auto_tax']) && $data['is_auto_tax'] == 1) {

                    if (isset($tax['auto_tax']) && $tax['auto_tax'] == 1 && isset($data['auto_tax'])) {

                        if ($fee['include_rental_tax'] || $fee['include_sale_tax'] || $fee['taxable']) {
                            $included_total += $data['auto_tax'];
                        }

                    } 

                } else {
                    
                    if ($fee['include_rental_tax']) {
                        $included_total += $data['rental_tax'];
                    }
                    if ($fee['include_sale_tax']) {
                        $included_total += $data['sale_tax'];
                    }
                    if ($fee['include_service_tax'] && isset($data['service_tax'])) {
                        $included_total += $data['service_tax'];
                    }
                }
               
                if ($fee['include_delivery']) {
                    $included_total += $data['shipping'];
                }
                if ($fee['include_delivery_tax']) {
                    $included_total += $data['shipping_tax'];
                }
               

                if (isset($fee['include_arrival_cost']) && !empty($fee['include_arrival_cost'])) {
                    // TODO : after exact arrival cost integrated
                    // $included_total += $data['shipping_tax'];
                }

                if (isset($fee['include_pickup_cost']) && !empty($fee['include_pickup_cost'])) {
                    // TODO : after exact pickup cost integrated
                    // $included_total += $data['shipping_tax'];
                }

                if ($fee['include_damage_waiver']) {
                    $included_total += $data['damage_waiver'];
                }
                if ($fee['include_damage_waiver_tax']) {
                    $included_total += $data['damage_waiver_tax'];
                }
                if ($fee['include_rush_fee']) {
                    $included_total += $data['rush_fee'];
                }
                if ($fee['include_coupon']) {
                    $included_total -= $data['coupon_discount'];
                }
                if ($fee['include_fees']) {
                    $included_total += $total_fees;
                }

                $fee['percent'] = $fee['amount'];

                $fee['amount'] = format_value_to_fixed_precision($included_total * $fee['percent'] / 100, 2);

            } else {
                
                if ($fee['taxable']) {
                    
                    if (isset($data['is_auto_tax']) && $data['is_auto_tax'] == 1) {

                        if (isset($tax['auto_tax']) && $tax['auto_tax'] == 1 && isset($data['auto_tax'])) {

                            $fee_amount_taxable = $fee['amount'];
                            if (!empty($coupons) && !empty($discount_before_tax) && $discount_before_tax == 1 && !empty($ratio)) {
                                $fee_amount_taxable *= $ratio;
                            }

                            $rental_taxable += $fee_amount_taxable;

                            if (!empty($tax['auto_tax_rates']) && !empty($tax['auto_tax_rates']['totalRate'])) {
                                $total -= $data['auto_tax'];
                                $data['auto_tax'] = format_value_to_fixed_precision($rental_taxable * $tax['auto_tax_rates']['totalRate'], 2);
                                $total += $data['auto_tax'];
                            }
                        
                        } 

                    } else {
                        // manual tax
                        // must prioritize the service and then sale and then rental tax in tax calculations

                        $fee_tax_calculated = false;
                        if (!empty($tax['service_tax_rate'])) {
                            $fee_tax_calculated = true;

                            $fee_amount_taxable = $fee['amount'];
                            if (!empty($coupons) && !empty($discount_before_tax) && $discount_before_tax == 1 && !empty($ratio)) {
                                $fee_amount_taxable *= $ratio;
                            }

                            $service_taxable += $fee_amount_taxable;
    
                            $total -= $data['service_tax'];
                            $data['service_tax'] = format_value_to_fixed_precision($service_taxable * $tax['service_tax_rate'] / 100, 2);
                            $total += $data['service_tax'];

                            // when total is update, discountable total must be updated
                            $discountable_total = $total;

                        }

                        if (!empty($tax['sale_tax_rate']) && !$fee_tax_calculated) {
                            $fee_tax_calculated = true;

                            $fee_amount_taxable = $fee['amount'];
                            if (!empty($coupons) && !empty($discount_before_tax) && $discount_before_tax == 1 && !empty($ratio)) {
                                $fee_amount_taxable *= $ratio;
                            }

                            $sale_taxable += $fee_amount_taxable;

                            $total -= $data['sale_tax'];
                            $data['sale_tax'] = format_value_to_fixed_precision($sale_taxable * $tax['sale_tax_rate'] / 100, 2);
                            $total += $data['sale_tax'];

                            // when total is update, discountable total must be updated
                            $discountable_total = $total;

                        }

                        if (!empty($tax['rental_tax_rate']) && !$fee_tax_calculated) {

                            $fee_amount_taxable = $fee['amount'];
                            if (!empty($coupons) && !empty($discount_before_tax) && $discount_before_tax == 1 && !empty($ratio)) {
                                $fee_amount_taxable *= $ratio;
                            }

                            $rental_taxable += $fee_amount_taxable;

                            $total -= $data['rental_tax'];
                            $data['rental_tax'] = format_value_to_fixed_precision($rental_taxable * $tax['rental_tax_rate'] / 100, 2);
                            $total += $data['rental_tax'];

                            // when total is update, discountable total must be updated
                            $discountable_total = $total;
                        }

                    }

                }


                
                if (!$coupon_after_tax_is_caculated) {

                    if (!empty($coupons) && $discount_before_tax != 1) {

                        $coupon_discount = 0;
                        foreach($coupons as $key=>$coupon) {
                            if ($coupon['discount_type'] == 'percent') {

                                if (!$coupon_discount) {
                                    if ($exclude_delivery_from_coupon == 1) {
                                        $discountable_total -= $shipping_fee;
                
                                        if ($data['shipping_tax']) {
                                            $discountable_total -= $data['shipping_tax'];
                                        }
                                    }
                
                                    $coupon_discount = $discountable_total * $coupon['amount'] / 100;
                                }
            
                            } else if ($coupon['discount_type'] == 'fixed_cart') {
                                
                                if (!$coupon_discount) {
                                    $coupon_discount = $coupon['amount'];
                                }
                            }

                            if ($coupon_discount > $total) {
                                $coupon_discount = $total;
                            }
            
                            $total -= $coupon_discount;
            
                            $data['coupons'][$key] = [
                                'discount_type' => $coupon['discount_type'],
                                'coupon_discount' => $coupon_discount,
                                'amount' => $coupon['amount'],
                                'code' => $coupon['code'],
                            ];
            
                            set_rental_session_data('rental_coupon_discount', $coupon_discount);
            
                            $coupon_after_tax_is_caculated = true;

                            $data['coupon_discount'] = $coupon_discount;
                            break;
                        }
                    }
                }
                 

            }

            $total_fees += $fee['amount'];
            $data['order_fees'][] = $fee;
        }

        $total += $total_fees;
        
    } 


    if (!$coupon_after_tax_is_caculated) {
        // Add coupon discount fee to total after taxes
        if (!empty($coupons) && $discount_before_tax != 1) {
            $coupon_discount = 0;
            foreach($coupons as $key=>$coupon) {

                if ($coupon['discount_type'] == 'percent') {

                    if ($exclude_delivery_from_coupon == 1) {
                        $discountable_total -= $shipping_fee;

                        if ($data['shipping_tax']) {
                            $discountable_total -= $data['shipping_tax'];
                        }
                    }

                    $coupon_discount = $discountable_total * $coupon['amount'] / 100;

                } else if ($coupon['discount_type'] == 'fixed_cart') {
                    $coupon_discount = $coupon['amount'];
                }

                if ($coupon_discount > $total) {
                    $coupon_discount = $total;
                }

                $total -= $coupon_discount;

                $data['coupons'][$key] = [
                    'discount_type' => $coupon['discount_type'],
                    'coupon_discount' => $coupon_discount,
                    'amount' => $coupon['amount'],
                    'code' => $coupon['code'],
                ];

                $data['coupon_discount'] = $coupon_discount;

                set_rental_session_data('rental_coupon_discount', $coupon_discount);

                break;
            }
        }
    }
      
    

    // rental taxable amount of an order
    if ($rental_taxable) {
        set_rental_session_data('rental_taxable_amount', $rental_taxable);
    }
    
    // the total amount without security_deposit_fee and payment_tip_amount
    set_rental_session_data('total_excluded_extra_fees', $total);

    // if security deposit is enabled
    if (get_option('rental_allow_to_pay_security_deposit') && !empty($security_deposit = rental_get_security_deposit())) {
        $data['security_deposit_fee'] = rental_get_security_deposit_cart_calculations($cart, $total, $security_deposit);
        // Add security deposit fee to total
        $total += $data['security_deposit_fee'];
    }

    // Persist the security deposit fee so the payment sync can include it in the
    // amount reported to Rentopian (the charge — and the core invoice balance —
    // both include the security deposit).
    set_rental_session_data('rental_security_deposit_fee', $data['security_deposit_fee']);

    if (
        get_option("rental_direct_only_bookings", 0)
        && get_option("rental_payment_tips_enabled", 0)
    ) {

        $tip_amount = 0;
        if (!isset($_COOKIE['TIP_ID_SELECTED_BY_CUSTOMER' . get_new_unique_id()])) {
            // use default tip amount when no tip selected
            
            $tip_amount = calculate_tip_amount(0, true);

        } else {

            $tip_amount = calculate_tip_amount($_COOKIE['TIP_ID_SELECTED_BY_CUSTOMER' . get_new_unique_id()]);
        }
        
        $data['tip'] = $tip_amount;

        // Add tip amount to total
        $total += $data['tip'];
    }

    $data['total'] = $total;
    return $data;
}

// get calculated security deposit fee based on each cart item
function rental_get_security_deposit_cart_calculations($cart, $total, $security_deposit) {
    $data_security_deposit_fee = 0;
    
    // calculate based on each item's replacement price and quantity
    if ($security_deposit['on_replacement'] && $security_deposit['is_percent']) {
        foreach ($cart->get_cart() as $item) {
            $id = $item['variation_id']?: $item['product_id'];

            if (!get_post_meta($item['product_id'], '_rental_is_set', true)) {
                // replacement price is always calculated with percent rate
                $replacement_price = empty(get_post_meta($id, '_rental_replacement_price', true )) ? 0 : get_post_meta($id, '_rental_replacement_price', true );
                $replacement_price_amount = ($security_deposit['rate'] * $replacement_price)/100;
                $data_security_deposit_fee += ($replacement_price_amount * $item['quantity']);
            }
        }
    } 
    // calculate based on total and security deposit rate
    else {
        
        // calculate with percent rate
        if ($security_deposit['is_percent']) {
            $data_security_deposit_fee = ($security_deposit['rate'] * $total)/100;
        }
        // calculate with fixed amount
        else {
            $data_security_deposit_fee = $security_deposit['rate'];
        }
                    
    }

    return $data_security_deposit_fee;
}

function get_days_from_rental_dates($rental_start_date, $rental_end_date) {
        
    $start_date = $rental_start_date ? new DateTime($rental_start_date) : "";
    $end_date = $rental_end_date ? new DateTime($rental_end_date) : "";
    
    $days = 1;
    if ($start_date && $end_date) {
        $end_date->setTime(0, 0);
        $start_date->setTime(0, 0);
        $days = $end_date->diff($start_date);
        $days = $days->days + 1;
    }

    return $days;
}

/**
 * Sanitize event time input to extract only valid time format.
 * Prevents API parsing errors caused by users entering notes or invalid text.
 * 
 * @param string $raw_time The raw input from the event time field
 * @return string|null Sanitized time string (e.g., "7:00 AM") or null if invalid
 */
function rental_sanitize_event_time($raw_time) {
    if (empty($raw_time)) {
        return null;
    }
    
    $raw_time = trim($raw_time);
    
    // Pattern 1: Standard time format like "7:00 AM", "10:30 PM", "7:00AM", "10:30PM"
    if (preg_match('/^(\d{1,2}:\d{2}\s*(?:AM|PM|am|pm))/', $raw_time, $matches)) {
        return strtoupper(trim($matches[1]));
    }
    
    // Pattern 2: Time without colon like "7AM", "10PM"
    if (preg_match('/^(\d{1,2}\s*(?:AM|PM|am|pm))/', $raw_time, $matches)) {
        $time = trim($matches[1]);
        // Add :00 for minutes
        $time = preg_replace('/(\d{1,2})\s*(AM|PM|am|pm)/i', '$1:00 $2', $time);
        return strtoupper($time);
    }
    
    // Pattern 3: 24-hour format like "14:30", "09:00"
    if (preg_match('/^(\d{1,2}:\d{2})(?:\s|$)/', $raw_time, $matches)) {
        $time_24 = $matches[1];
        // Convert to 12-hour format
        $timestamp = strtotime($time_24);
        if ($timestamp !== false) {
            return date('g:i A', $timestamp);
        }
    }
    
    // Pattern 4: Try to parse the beginning of the string as a time
    // This handles cases like "8:30AM on Oct 21st..." - extracts "8:30 AM"
    if (preg_match('/(\d{1,2}:\d{2}\s*(?:AM|PM|am|pm)?)/i', $raw_time, $matches)) {
        $potential_time = trim($matches[1]);
        // If no AM/PM, try to parse and format
        if (!preg_match('/AM|PM/i', $potential_time)) {
            $timestamp = strtotime($potential_time);
            if ($timestamp !== false) {
                return date('g:i A', $timestamp);
            }
        } else {
            return strtoupper($potential_time);
        }
    }
    
    // If nothing matches, return null to skip event_time entirely
    // This prevents sending malformed data to the API
    return null;
}

// Calculate days
function rental_get_days() {
    global $wpdb, $rental_tables;
    $rental_day_tiers = $wpdb->prefix . $rental_tables["day_tiers"];

    if (get_option('rental_synchronized_product_type') == "hourly") {

        $end_date = new DateTime($_COOKIE["rental_hourly_end_date"]);
        $start_date = new DateTime($_COOKIE["rental_hourly_start_date"]);

    } else {

        if ( isset($_COOKIE['rental_start_date']) && $_COOKIE['rental_start_date'] ) {
            $enc_key    = get_option('rental_encryption_key');
            $tier_start = decrypt_data($_COOKIE['rental_start_date'], $enc_key);

            if ($tier_start) {
                if ( isset($_COOKIE['rental_end_date']) && $_COOKIE['rental_end_date'] ) {
                    $tier_end = decrypt_data($_COOKIE['rental_end_date'], $enc_key);
                } else {
                    $tier_end = $tier_start; // no end date → same-day → 0 hours
                }

                // Measure the same window the order payload declares: the times
                // sent to the core are forced to the store hours when the
                // pickers are hidden, so the duration must be too.
                rental_normalize_hidden_times($tier_start, $tier_end);

                $tier_interval = (new DateTime($tier_end ?: $tier_start))->diff(new DateTime($tier_start));
                $tier_hours    = $tier_interval->h + (24 * $tier_interval->days);
                $tier_result   = $wpdb->get_var($wpdb->prepare(
                    "SELECT `day` FROM `$rental_day_tiers` WHERE `min` <= %d AND `max` >= %d",
                    $tier_hours,
                    $tier_hours
                ));
                if ($tier_result) {
                    return (int) $tier_result;
                }
            }
        }

        if (get_option('rental_hide_end_date') || !isset($_COOKIE['rental_end_date']) || !$_COOKIE['rental_end_date']) {
            $min_range = (int) get_option('rental_min_dates_range');
            return $min_range > 1 ? $min_range : 1;
        }

        $decrypted_rental_start_date = "";
        if ( isset($_COOKIE['rental_start_date']) ) {
            $decrypted_rental_start_date = decrypt_data($_COOKIE['rental_start_date'], get_option('rental_encryption_key'));
        }

        $decrypted_rental_end_date = "";
        if ( isset($_COOKIE['rental_end_date']) ) {
            $decrypted_rental_end_date = decrypt_data($_COOKIE['rental_end_date'], get_option('rental_encryption_key'));
        }

        rental_normalize_hidden_times($decrypted_rental_start_date, $decrypted_rental_end_date);

        $end_date = $decrypted_rental_end_date ? new DateTime($decrypted_rental_end_date) : "";
        $start_date = $decrypted_rental_start_date ? new DateTime($decrypted_rental_start_date) : "";
    }

    $days = 1;
    if ($start_date && $end_date) {
        $days = $end_date->diff($start_date);
        $hours = $days->h + (24 * $days->days);
        $days = $wpdb->get_var("SELECT `day` FROM $rental_day_tiers WHERE `min` < $hours AND `max` >= $hours");
        if ( !$days) {
            $end_date->setTime(0, 0);
            $start_date->setTime(0, 0);
            $days = $end_date->diff($start_date);
            $days = $days->days + 1;
        }
    }

    return $days;
}

// Get order custom fields
function rental_get_custom_fields() {
    $api_key = get_option('rental_api_key');

    $rental_custom_fields = json_decode(rental_curl('custom-fields', $api_key, false), 1);
    update_option('rental_custom_fields', $rental_custom_fields);

    return $rental_custom_fields;
}

// stripping zip code(post code) extensions if exists 
function strip_zip_code_extensions($zip) {
    if ( $dash_position = strpos($zip, '-') ){
        $zip = trim(substr_replace($zip, "", $dash_position));
    }
    return $zip;
}

// override/format the value to a fixed [$decimal_number] precision
function format_value_to_fixed_precision($value, $decimal_number) {
    return number_format((float) $value, $decimal_number, '.', '');
}

function rental_sanitize($data) {
    if ($data) {
        $data = trim($data);
        $data = stripslashes($data);
        $data = htmlspecialchars($data);
    }
    return $data;
}
function rental_validate($data, $type = "", $check_empty = true) {
    if ($check_empty) {
        if (empty($data)) {
            return "empty_err";
        }
    }

    if ($type && $data) {
        switch($type) {
            case "numeric":
                if (!is_numeric($data)) {
                    return "numeric_err";
                }
                return true;
            case "not_numeric":
                if (is_numeric($data)) {
                    return "not_numeric_err";
                }
                return true;
            case "email":
                if (!filter_var($data, FILTER_VALIDATE_EMAIL)) {
                    return "email_err";
                }
                return true;
        }
    }
    return true;
}

function wp_ajax_rental_send_wishlist_form() {
    if (
        isset($_POST["name"])
        && isset($_POST["email"])
        && isset($_POST["date"])
        && isset($_POST["location"])
        && isset($_POST["phone"])
        && isset($_POST["guest_count"])
        && isset($_POST["note"])
    ) {

        $name = rental_sanitize($_POST["name"]);
        $email = rental_sanitize($_POST["email"]);
        $date = rental_sanitize($_POST["date"]);

        $location = rental_sanitize($_POST["location"]);
        $address_2 = rental_sanitize($_POST["address_2"]);
        $state = rental_sanitize($_POST["state"]);
        $city = rental_sanitize($_POST["city"]);
        $zip = rental_sanitize($_POST["zip"]);
        $country = rental_sanitize($_POST["country"]);
        
        $phone = rental_sanitize($_POST["phone"]);
        $guest_count = rental_sanitize($_POST["guest_count"]);
        $note = rental_sanitize($_POST["note"]);
        $err = rental_validate($name, "not_numeric");
        if (!$err) {
            wp_send_json(["err" => $err, "field" => "Name"], 401);
            wp_die();
        }
        $err = rental_validate($email, "email");
        if (!$err) {
            wp_send_json(["err" => $err, "field" => "Email"], 401);
            wp_die();
        }
        $err = rental_validate($date);
        if (!$err) {
            wp_send_json(["err" => $err, "field" => "Date"], 401);
            wp_die();
        }
        $err = rental_validate($location);
        if (!$err) {
            wp_send_json(["err" => $err, "field" => "Location"], 401);
            wp_die();
        }
        if ($guest_count) {
            $err = rental_validate($guest_count, "numeric");
            if (!$err) {
                wp_send_json(["err" => $err, "field" => "Guest Count"], 401);
                wp_die();
            }
        }

        $err = rental_validate($state, "not_numeric");
        if (!$err) {
            wp_send_json(["err" => $err, "field" => "State"], 401);
            wp_die();
        }

        $err = rental_validate($city, "not_numeric");
        if (!$err) {
            wp_send_json(["err" => $err, "field" => "City"], 401);
            wp_die();
        }

        $err = rental_validate($country, "not_numeric");
        if (!$err) {
            wp_send_json(["err" => $err, "field" => "Country"], 401);
            wp_die();
        }
        
        
        $data = [
            "name" => $name,
            "email" => $email,
            "date" => $date,
            "location" => $location,
            "address_2" => $address_2,
            "state" => $state,
            "city" => $city,
            "zip" => $zip,
            "country" => $country,
            "lat" => isset($_COOKIE['rental_client_wish_address_lat']) && $_COOKIE['rental_client_wish_address_lat'] ? $_COOKIE['rental_client_wish_address_lat'] : '',
            "lng" => isset($_COOKIE['rental_client_wish_address_lng']) && $_COOKIE['rental_client_wish_address_lng'] ? $_COOKIE['rental_client_wish_address_lng'] : '',
            "phone" => $phone,
            "guest_count" => $guest_count,
            "notes" => $note,
        ];

        
        global $wpdb, $rental_tables;
        $rental_lead_relations = $wpdb->prefix . $rental_tables["lead_relations"];

        $log = [
            "http_code" => 200,
            "message" => "",
            "rental_id" => 0,
            "register_time" => time(),
        ];

        $response = [];
        $http_code = 200;
        try {

            $rental_lead = rental_curl("leads/add", get_option("rental_api_key"), true, $data);
            
            if (isset($rental_lead->id)) {
                $response = ["data" => $rental_lead->id];
                $log["rental_id"] = $rental_lead->id;
                $log["data"] = serialize($rental_lead);
                
            }

        } catch (RentalException $e) {

            $http_code = $e->getStatusCode();
            $response = [["err" => "serverside", "field" => "serverside"]];
            $log["http_code"] = $http_code;
            $log["message"] = $e->getMessage();
        }

        $log["version"] = RENTOPIAN_SYNC_VERSION;
        $log["data"] = serialize($data);
        $wpdb->insert($rental_lead_relations, $log);
        if ($wpdb->last_error !== "") {
            throw new RentalException("SQL_ERROR: $wpdb->last_error (SQL: $wpdb->last_query)");
        }

        if ($http_code != 200) {
            $error_msg = json_decode($e->getMessage(), true);
            wp_send_json(["err" => false, "field" => " ", "msg" => $error_msg], 401);
            wp_die();
        }
        wp_send_json($response, $http_code);
        wp_die();
    }
}

function rental_create_order( $order_id ) {
	global $wpdb, $rental_tables;
	$rental_product_relations = $wpdb->prefix . $rental_tables["product_relations"];
	$rental_variant_relations = $wpdb->prefix . $rental_tables["variant_relations"];
	$rental_order_relations   = $wpdb->prefix . $rental_tables["order_relations"];

	$log_mode = get_option( 'rental_synchronized_product_type' ) == 'hourly' ? 'hourly' : 'daily';
	Rentopian_Order_Logger::order_start( $order_id, array( 'mode' => $log_mode ) );

	try {

		if ( get_option( 'rental_synchronized_product_type' ) == "hourly" ) {
			// It's on hourly mode, continue!
		} else {
			$zip_required = ! get_option( 'rental_hide_zip' );
			$zip_missing  = $zip_required && ! isset( $_COOKIE['rental_zip'] );
			if ( ! isset( $_COOKIE['rental_start_date'] ) || $zip_missing ) {
				$missing = array();
				if ( ! isset( $_COOKIE['rental_start_date'] ) ) {
					$missing[] = 'rental_start_date';
				}
				if ( $zip_missing ) {
					$missing[] = 'rental_zip';
				}

				Rentopian_Order_Logger::incident( $order_id, 'missing_cookies', array(
					'missing' => implode( ',', $missing ),
					'note'    => 'Continuing; healer will fill in fallbacks (WC date_created for start, billing postcode for zip).',
				), 'warning' );

			}
		}

		// Idempotency — already synced. The ONLY legitimate skip.
		$existing_rental_id = $wpdb->get_var( "SELECT `rental_id` FROM $rental_order_relations WHERE `id` = $order_id" );
		if ( $existing_rental_id ) {
			Rentopian_Order_Logger::order_already_synced( $order_id, $existing_rental_id );
			return;
		}

		// get order by id
		$wc_order = new WC_Order( $order_id );

		// get selected products and their quantity
		$cart                    = WC()->cart;
		$cart_contents           = $cart ? $cart->cart_contents : array();
		$inventories             = array();
		$time_date_compare_start = array();
		$time_date_compare_end   = array();
		foreach ( $cart_contents as $cart_item ) {

			$quantity           = $cart_item["quantity"];
			$product_variant_id = $cart_item['variation_id'] ? $cart_item['variation_id'] : $cart_item['product_id'];

			if ( get_post_meta( $cart_item["product_id"], "_rental_is_set", true )
				|| ! ( $product_id = $wpdb->get_var( "SELECT `rental_id` FROM " . $rental_product_relations . " WHERE `id` = " . $cart_item["product_id"] ) )
				|| ! ( $inventory_id = get_post_meta( $product_variant_id, "_rental_inventory_id", true ) ) ) {

				$is_set_line = (bool) get_post_meta( $cart_item["product_id"], "_rental_is_set", true );
				$skip_reason = $is_set_line ? 'is_set' : ( empty( $product_id ) ? 'no_rental_id' : 'no_inventory_id' );
				$skip_context = array();

				// A set parent carries no inventory of its own — its children do.
				// Record how many children back this parent so a childless set is
				// visible at the point it is skipped, not only in the totals.
				if ( $is_set_line ) {
					$set_children = 0;
					foreach ( $cart_contents as $sibling ) {
						if ( isset( $sibling['rental_add_on_of'] ) && $sibling['rental_add_on_of'] === $cart_item['key'] ) {
							$set_children++;
						}
					}
					$definition   = get_post_meta( $cart_item["product_id"], '_rental_set_items', true );
					$skip_context = array(
						'cart_children' => $set_children,
						'def_items'     => is_array( $definition ) ? count( $definition ) : 0,
						'set_qty'       => $cart_item['quantity'],
					);
				}

				Rentopian_Order_Logger::item_skip( $order_id, $cart_item['product_id'], $skip_reason, $skip_context );
				continue;
			}


			$rental_set_id              = $set_quantity = $parent_id = $parent_inventory_id = $wp_set_id = 0;
			$sel_variant_id             = $set_subtotal = 0; // used for sets having items with optional items and sets with items having addons
			$item_has_price_multiplier  = $set_has_price_multiplier = 0;
			$item_subtotal              = $cart_item["line_subtotal"]; // subtotal

			$item_has_price_multiplier = get_post_meta( $product_variant_id, '_price_multiplier_id', true );

			if ( isset( $cart_item["rental_add_on_of"] ) ) {

				// The parent line this child belongs to is gone, so the child
				// cannot be priced against a set. Dropping it silently costs an
				// inventory in the payload, so record it.
				if ( ! isset( $cart_contents[ $cart_item["rental_add_on_of"] ] ) ) {
					Rentopian_Order_Logger::item_skip(
						$order_id,
						$cart_item['product_id'],
						'orphan_child',
						array(
							'variation_id' => $cart_item['variation_id'],
							'quantity'     => $cart_item['quantity'],
							'parent_key'   => substr( (string) $cart_item["rental_add_on_of"], 0, 8 ),
						)
					);
					continue;
				}

				if ( isset( $cart_item["rental_set_id"] ) && $cart_item["rental_set_id"] ) {

					$rental_set_id = $cart_item["rental_set_id"];
					$wp_set_id     = $cart_item["set_id"];
					$set_quantity  = $cart_contents[ $cart_item["rental_add_on_of"] ]["quantity"];

					$set_has_price_multiplier = get_post_meta( $product_variant_id, '_price_multiplier_id', true );


					// sel_variant_id parameter is only for sets items having optional items
					if ( $set_ids_with_optional_items = get_set_ids_with_optional_items() ) {

						foreach ( $set_ids_with_optional_items as $set_with_optional_items_id => $selected_product_key_variant_or_product_value_array ) {

							if ( $set_with_optional_items_id == $wp_set_id ) {

								foreach ( $selected_product_key_variant_or_product_value_array as $pid => $selected_variant_or_product_id ) {

									if ( $pid == $cart_item["product_id"] ) {

										$rental_variant_id = 0;

										if ( $selected_variant_or_product_id == $cart_item['variation_id']
											|| $selected_variant_or_product_id == $cart_item['product_id']
										) {

											$rental_variant_id = $cart_item["rental_variant_id"] ?? 0;

											// $rental_variant_id = $wpdb->get_var("SELECT `rental_id` FROM $rental_variant_relations WHERE `id` = " . $selected_variant_or_product_id);

											if ( empty( $rental_variant_id ) ) {

												$rental_variant_id = $wpdb->get_var( "SELECT `rental_id` FROM $rental_product_relations WHERE `id` = " . $selected_variant_or_product_id );
											}
										}

										if ( empty( $rental_variant_id ) ) {

											$selected_variant_or_product_parent_id = 0;
											$selected_variant_or_product_parent    = get_post( $selected_variant_or_product_id );
											if ( $selected_variant_or_product_parent->post_parent ) {
												$selected_variant_or_product_parent_id = $selected_variant_or_product_parent->post_parent;
											}

											if ( $selected_variant_or_product_parent_id == $cart_item['product_id'] ) {

												$rental_variant_id = $wpdb->get_var( "SELECT `rental_id` FROM $rental_variant_relations WHERE `id` = " . $selected_variant_or_product_id );
												if ( empty( $rental_variant_id ) || ! $rental_variant_id ) {

													$rental_variant_id = $cart_item["rental_product_id"] ?? 0;

													if ( empty( $rental_variant_id ) ) {
														$rental_variant_id = $wpdb->get_var( "SELECT `rental_id` FROM $rental_product_relations WHERE `id` = " . $selected_variant_or_product_id );
													}
												}
											}
										}


										$sel_variant_id = $rental_variant_id;

										update_post_meta( $order_id, "_rental_sel_variant_id", $sel_variant_id );
									}
								}
							}
						}
					}

					$set_subtotal = $cart_contents[ $cart_item["rental_add_on_of"] ]["line_subtotal"]; // set subtotal

					if ( ( isset( $cart_item["parent_set_item_product_id"] )
						&& $cart_item["parent_set_item_product_id"] )
						|| ( isset( $cart_item["parent_set_item_variant_id"] )
						&& $cart_item["parent_set_item_variant_id"] )
					) {

						$parent_id           = $wpdb->get_var( "SELECT `rental_id` FROM $rental_product_relations WHERE `id` = " . $cart_item["parent_set_item_product_id"] );
						$parent_inventory_id = get_post_meta( $cart_item["parent_set_item_variant_id"] ? $cart_item["parent_set_item_variant_id"] : $cart_item["parent_set_item_product_id"], "_rental_inventory_id", true );
					}


				} else {

					$parent_id           = $wpdb->get_var( "SELECT `rental_id` FROM $rental_product_relations WHERE `id` = " . $cart_contents[ $cart_item["rental_add_on_of"] ]["product_id"] );
					$parent_inventory_id = get_post_meta( $cart_contents[ $cart_item["rental_add_on_of"] ]["variation_id"] ?: $cart_contents[ $cart_item["rental_add_on_of"] ]["product_id"], "_rental_inventory_id", true );
				}
			}

			// Two shapes the core accepts without erroring, and which cost a
			// line its place in the order. Neither is corrected here — the
			// payload ships as built — but both are recorded so a short or
			// mispriced order is traceable to the line that caused it.
			//
			//   set_id with set_quantity 0 → the line leaves its set and is
			//   filed as a loose product.
			//
			//   parent_id without parent_inventory_id (or the reverse) → the
			//   add-on relation lookup is keyed by the pair, so a half-filled
			//   pair always misses and the line is billed at the posted price
			//   instead of the relation price.
			if ( $rental_set_id && (int) $set_quantity < 1 ) {
				Rentopian_Order_Logger::log( $order_id, 'SET_QUANTITY_ZERO', array(
					'product_id'   => $cart_item['product_id'],
					'variation_id' => $cart_item['variation_id'],
					'inventory_id' => $inventory_id,
					'set_id'       => $rental_set_id,
				), 'warning' );
			}
			if ( (bool) $parent_id !== (bool) $parent_inventory_id ) {
				Rentopian_Order_Logger::log( $order_id, 'ADDON_PARENT_INCOMPLETE', array(
					'product_id'          => $cart_item['product_id'],
					'variation_id'        => $cart_item['variation_id'],
					'inventory_id'        => $inventory_id,
					'parent_id'           => $parent_id,
					'parent_inventory_id' => $parent_inventory_id,
				), 'warning' );
			}

			// override cart's line subtotal if it exceeds precision 2
			$item_subtotal = format_value_to_fixed_precision( $item_subtotal, 2 );
			$item_price    = 0;

			$product_options = array();
			$set_options     = array();

			if ( $wp_set_id ) {
				// This is a set child item - get set options from the PARENT set's cart item
				$parent_cart_item_key = isset( $cart_item['rental_add_on_of'] ) ? $cart_item['rental_add_on_of'] : null;
				$parent_cart_item     = ( $parent_cart_item_key && isset( $cart_contents[ $parent_cart_item_key ] ) )
					? $cart_contents[ $parent_cart_item_key ]
					: null;

				if ( $parent_cart_item && isset( $parent_cart_item['rental_selected_set_options'] ) && ! empty( $parent_cart_item['rental_selected_set_options'] ) ) {
					// PRIMARY: Read from the parent set's cart item data
					$set_options = $parent_cart_item['rental_selected_set_options'];
				} elseif ( isset( $cart_item['rental_selected_set_options'] ) && ! empty( $cart_item['rental_selected_set_options'] ) ) {
					// SECONDARY: Check the child item itself (unlikely but safe fallback)
					$set_options = $cart_item['rental_selected_set_options'];
				} elseif ( $parent_cart_item_key && class_exists( 'Rental_Options_Selection' ) ) {
					// LAST RESORT: the parent set line's own defaults.
					$set_options = Rental_Options_Selection::for_cart_line( $parent_cart_item_key, $parent_cart_item );
				}
			}

			// Get product-level options (for individual products, not sets)
			if ( ! $wp_set_id ) {
				if ( isset( $cart_item['rental_selected_options'] ) && ! empty( $cart_item['rental_selected_options'] ) ) {
					$product_options = $cart_item['rental_selected_options'];
				} elseif ( class_exists( 'Rental_Options_Selection' ) ) {
					$product_options = Rental_Options_Selection::for_cart_line(
						isset( $cart_item['key'] ) ? $cart_item['key'] : '',
						$cart_item
					);
				}
			}

			if ( get_option( 'rental_synchronized_product_type' ) == "hourly" ) {

				$item_price = (float) $item_subtotal / $quantity;

				$product_id            = $cart_item['variation_id'] ? $cart_item['variation_id'] : $cart_item['product_id'];
				$session_name_interval = $product_id . "_rental_by_interval";
				$session_name_slot     = $product_id . "_rental_by_slot";

				$rental_by_interval_opt = get_option( $session_name_interval );
				$rental_by_slot_opt     = get_option( $session_name_slot );

				if ( $rental_by_interval_opt !== false && $rental_by_interval_opt ) {
					// rental_by_interval

					// start date time
					$start_time = $rental_by_interval_opt["selected_times_prices"][0]["time"];

					$start_time_stripped_AM = explode( "AM", $start_time );
					$start_time_stripped_PM = explode( "PM", $start_time );
					if ( count( $start_time_stripped_AM ) == 2 ) {
						$start_time_stripped[0] = $start_time_stripped_AM[0]; // value
						$start_time_stripped[1] = "AM";
					} else if ( count( $start_time_stripped_PM ) == 2 ) {
						$start_time_stripped[0] = $start_time_stripped_PM[0]; // value
						$start_time_stripped[1] = "PM";
					}

					$start_time_type       = $start_time_stripped[1]; // AM/PM
					$start_time_stripped2  = explode( ":", $start_time_stripped[0] );
					if ( $start_time_type == "PM" ) {
						$hour_minute_to_seconds = ( ( ( $start_time_stripped2[0] != 12 ) ? $start_time_stripped2[0] + 12 : $start_time_stripped2[0] ) * 60 * 60 ) + ( $start_time_stripped2[1] * 60 );
					} else {
						$hour_minute_to_seconds = ( ( $start_time_stripped2[0] ) * 60 * 60 ) + ( $start_time_stripped2[1] * 60 );
					}
					$start_time_seconds = $hour_minute_to_seconds;

					// end date time
					$end_time = $rental_by_interval_opt["selected_times_prices"][ count( $rental_by_interval_opt["selected_times_prices"] ) - 1 ]["time"];

					$end_time_stripped_AM = explode( "AM", $end_time );
					$end_time_stripped_PM = explode( "PM", $end_time );
					if ( count( $end_time_stripped_AM ) == 2 ) {
						$end_time_stripped[0] = $end_time_stripped_AM[0]; // value
						$end_time_stripped[1] = "AM";
					} else if ( count( $end_time_stripped_PM ) == 2 ) {
						$end_time_stripped[0] = $end_time_stripped_PM[0]; // value
						$end_time_stripped[1] = "PM";
					}


					$end_time_type      = $end_time_stripped[1]; // AM/PM
					$end_time_stripped2 = explode( ":", $end_time_stripped[0] );
					if ( $end_time_type == "PM" ) {
						$hour_minute_to_seconds = ( ( ( $end_time_stripped2[0] != 12 ) ? $end_time_stripped2[0] + 12 : $end_time_stripped2[0] ) * 60 * 60 ) + ( $end_time_stripped2[1] * 60 );
					} else {
						$hour_minute_to_seconds = ( ( $end_time_stripped2[0] ) * 60 * 60 ) + ( $end_time_stripped2[1] * 60 );
					}
					$end_time_seconds = $hour_minute_to_seconds;

					$date = $rental_by_interval_opt["start_date"];

					$start_date_time_seconds = strtotime( $date ) + $start_time_seconds;
					$end_date_time_seconds   = strtotime( $date ) + $end_time_seconds;
					$start_date_time         = date( 'Y/m/d h:ia', $start_date_time_seconds );
					$end_date_time           = date( 'Y/m/d h:ia', $end_date_time_seconds );

					$time_date_compare_start[] = $start_date_time_seconds;
					$time_date_compare_end[]   = $end_date_time_seconds;

					$inventories[] = array(
						"product_id"          => $product_id,
						"inventory_id"        => $inventory_id,
						"quantity"            => $cart_item["quantity"],
						"hourly_price"        => $item_price,
						"subtotal"            => $item_subtotal,
						"set_id"              => $rental_set_id,
						"set_quantity"        => $set_quantity,
						"parent_id"           => $parent_id,
						"parent_inventory_id" => $parent_inventory_id,
						"rental_by_day"       => 0,
						"rental_by_interval"  => 1,
						"rental_by_slot"      => 0,
						"interval_steps"      => $rental_by_interval_opt["interval_steps"],
						"rental_interval"    => $rental_by_interval_opt["rental_interval"],
						"time_slot_id"        => 0,
						"start_date"          => $start_date_time,
						"end_date"            => $end_date_time,
						"product_options"     => $product_options,
					);

				} else if ( $rental_by_slot_opt !== false && $rental_by_slot_opt ) {
					// rental_by_slot

					$item_price = (float) $item_subtotal / $quantity;

					// start date time
					$start_time            = $rental_by_slot_opt["selected_times_prices"][0]["start_time"];
					$start_time_stripped   = explode( " ", $start_time );
					$start_time_type       = $start_time_stripped[1]; // AM/PM
					$start_time_stripped2  = explode( ":", $start_time_stripped[0] );
					if ( $start_time_type == "PM" ) {
						$hour_minute_to_seconds = ( ( ( $start_time_stripped2[0] != 12 ) ? $start_time_stripped2[0] + 12 : $start_time_stripped2[0] ) * 60 * 60 ) + ( $start_time_stripped2[1] * 60 );
					} else {
						$hour_minute_to_seconds = ( ( $start_time_stripped2[0] ) * 60 * 60 ) + ( $start_time_stripped2[1] * 60 );
					}
					$start_time_seconds = $hour_minute_to_seconds;

					// end date time
					$end_time            = $rental_by_slot_opt["selected_times_prices"][0]["end_time"];
					$end_time_stripped   = explode( " ", $end_time );
					$end_time_type       = $end_time_stripped[1]; // AM/PM
					$end_time_stripped2  = explode( ":", $end_time_stripped[0] );
					if ( $end_time_type == "PM" ) {
						$hour_minute_to_seconds = ( ( ( $end_time_stripped2[0] != 12 ) ? $end_time_stripped2[0] + 12 : $end_time_stripped2[0] ) * 60 * 60 ) + ( $end_time_stripped2[1] * 60 );
					} else {
						$hour_minute_to_seconds = ( ( $end_time_stripped2[0] ) * 60 * 60 ) + ( $end_time_stripped2[1] * 60 );
					}
					$end_time_seconds = $hour_minute_to_seconds;

					$date                    = $rental_by_slot_opt["start_date"];
					$start_date_time_seconds = strtotime( $date ) + $start_time_seconds;
					$end_date_time_seconds   = strtotime( $date ) + $end_time_seconds;
					$start_date_time         = date( 'Y/m/d h:ia', $start_date_time_seconds );
					$end_date_time           = date( 'Y/m/d h:ia', $end_date_time_seconds );

					$time_date_compare_start[] = $start_date_time_seconds;
					$time_date_compare_end[]   = $end_date_time_seconds;

					$inventories[] = array(
						"product_id"          => $product_id,
						"inventory_id"        => $inventory_id,
						"quantity"            => $cart_item["quantity"],
						"hourly_price"        => $item_price,
						"subtotal"            => $item_subtotal,
						"set_id"              => $rental_set_id,
						"set_quantity"        => $set_quantity,
						"parent_id"           => $parent_id,
						"parent_inventory_id" => $parent_inventory_id,
						"rental_by_day"       => 0,
						"rental_by_interval"  => 0,
						"rental_by_slot"      => 1,
						"interval_steps"      => 0,
						"rental_interval"     => 0,
						"time_slot_id"        => $rental_by_slot_opt["time_slot_id"],
						"start_date"          => $start_date_time,
						"end_date"            => $end_date_time,
						"product_options"     => $product_options,
					);

				}

			} else {
				// rental_by_day

				$inventories[] = array(
					"product_id"              => $product_id,
					"inventory_id"            => $inventory_id,
					"quantity"                => $cart_item["quantity"],
					"hourly_price"            => $item_price,
					"subtotal"                => $item_subtotal,
					"set_id"                  => $rental_set_id,
					"set_quantity"            => $set_quantity,
					"set_subtotal"            => (float) $set_subtotal > 0 ? (float) $set_subtotal : '0.00',
					"sel_variant_id"          => $sel_variant_id,
					"parent_id"               => $parent_id,
					"parent_inventory_id"     => $parent_inventory_id,
					"rental_by_day"           => 1,
					"rental_by_interval"      => 0,
					"rental_by_slot"          => 0,
					"interval_steps"          => 0,
					"rental_interval"         => 0,
					"time_slot_id"            => 0,
					"multiplier_applied"      => $item_has_price_multiplier ? 1 : 0,
					"set_multiplier_applied"  => $set_has_price_multiplier ? 1 : 0,
					"product_options"         => $product_options,
					"set_options"             => $set_options,
				);

			}

		}

		// send the necessary data for quotes
		if ( $inventories ) {

			$opt_hide_time_pickers  = get_option( 'rental_hide_time_pickers' );
			$opt_default_end_time   = get_option( 'rental_default_end_time', '05:00 PM' );
			$opt_default_start_time = get_option( 'rental_default_start_time', '09:00 AM' );
			$opt_hide_end_date      = get_option( 'rental_hide_end_date' );

			$start_date = isset( $_COOKIE['rental_start_date'] ) && $_COOKIE['rental_start_date'] ? decrypt_data( $_COOKIE['rental_start_date'], get_option( 'rental_encryption_key' ) ) : '';
			$end_date   = isset( $_COOKIE['rental_end_date'] ) && $_COOKIE['rental_end_date'] ? decrypt_data( $_COOKIE['rental_end_date'], get_option( 'rental_encryption_key' ) ) : '';

			Rentopian_Order_Logger::date_check(
				$order_id,
				'rental_start_date_raw',
				Rental_Date_Validator::is_valid( $start_date ),
				Rental_Date_Validator::diagnose( $start_date )
			);
			Rentopian_Order_Logger::date_check(
				$order_id,
				'rental_end_date_raw',
				Rental_Date_Validator::is_valid( $end_date ),
				Rental_Date_Validator::diagnose( $end_date )
			);

			if ( get_option( 'rental_synchronized_product_type' ) == "hourly" ) {
				$start_date = date( "Y/m/d h:ia", min( $time_date_compare_start ) );
			}

			$finilizedStartEndDate = RTDelivery::extractFinalRentalStartEndDate( $start_date, $end_date, $opt_hide_time_pickers, $opt_default_start_time, $opt_default_end_time, $opt_hide_end_date );

			$start_date = $finilizedStartEndDate['rental_start_date'];
			$end_date   = $finilizedStartEndDate['rental_end_date'];



			if ( get_option( 'rental_synchronized_product_type' ) == "hourly" ) {
				$end_date = date( "Y/m/d h:ia", max( $time_date_compare_end ) );
			}

			$start_heal = Rental_Data_Healer::heal_start_date( $start_date, $wc_order, $opt_default_start_time );
			if ( $start_heal['healed'] ) {
				Rentopian_Order_Logger::heal( $order_id, 'rental_start_date', $start_heal );
			}
			$start_date = $start_heal['value'];

			// Re-apply hourly start (was derived from cart selections, ground truth).
			if ( get_option( 'rental_synchronized_product_type' ) == "hourly" && ! empty( $time_date_compare_start ) ) {
				$start_date = date( "Y/m/d h:ia", min( $time_date_compare_start ) );
			}

			$end_heal = Rental_Data_Healer::heal_end_date( $end_date, $start_date, $wc_order, $opt_default_end_time );
			if ( $end_heal['healed'] ) {
				Rentopian_Order_Logger::heal( $order_id, 'rental_end_date', $end_heal );
			}
			$end_date = $end_heal['value'];

			// Re-apply hourly end (ground truth from cart).
			if ( get_option( 'rental_synchronized_product_type' ) == "hourly" && ! empty( $time_date_compare_end ) ) {
				$end_date = date( "Y/m/d h:ia", max( $time_date_compare_end ) );
			}

			// Final chronology safety net.
			$chrono = Rental_Data_Healer::ensure_chronological( $start_date, $end_date, $opt_default_end_time );
			if ( $chrono['healed'] ) {
				Rentopian_Order_Logger::heal( $order_id, 'chronology', array(
					'value'    => $chrono['end'],
					'healed'   => true,
					'source'   => 'derived_from_start',
					'original' => $end_date,
					'reason'   => $chrono['reason'],
				) );
				$end_date = $chrono['end'];
			}

			$days = rental_get_days();
			update_post_meta( $order_id, "_rental_start_date", $start_date );
			update_post_meta( $order_id, "_rental_end_date", $end_date );
			update_post_meta( $order_id, "_rental_days", $days );

			// Tag healed orders so admins can find them later.
			if ( $start_heal['healed'] || $end_heal['healed'] || $chrono['healed'] ) {
				update_post_meta( $order_id, '_rental_sync_healed', 1 );
				update_post_meta( $order_id, '_rental_sync_healed_at', current_time( 'mysql' ) );
			}

			Rentopian_Order_Logger::dates_resolved( $order_id, $start_date, $end_date, $days, array(
				'start_healed' => $start_heal['healed'],
				'end_healed'   => $end_heal['healed'],
				'chrono_fixed' => $chrono['healed'],
			) );

			$event_time = "";
			if ( get_option( "rental_event_start_time" ) && isset( $_COOKIE["rental_event_time"] ) && $_COOKIE["rental_event_time"] ) {
				// Sanitize event time - extract only valid time format to prevent API parsing errors
				$raw_event_time = trim( $_COOKIE["rental_event_time"] );
				$sanitized_time = rental_sanitize_event_time( $raw_event_time );

				if ( $sanitized_time ) {
					$event_time = date( "Y/m/d", strtotime( $start_date ) ) . " " . $sanitized_time;
				}

				unset( $_COOKIE["rental_event_time"] );
				setcookie( 'rental_event_time', '', time() - ( 31556952 ), "/", "", false, true );
			}
			update_post_meta( $order_id, "_rental_event_time", $event_time );

			$shipping_selected = get_option( 'rental_pickup_delivery' ) === 'company_delivery_return';
			$fees              = rental_calculate_order_total();

			$delivery_tax_settings = rental_get_delivery_tax_setting();

			$default_shipping = $delivery_cost = $delivery_tax_rate = $delivery_tax_value = $delivery_tax_id = $delivery_status = 0;
			if ( ! empty( $fees['shipping'] ) ) {
				$delivery_status = 1;
				// $delivery_cost = $fees['shipping']; // total delivery + pickup cost

				if ( $delivery_tax_settings['delivery_tax'] ) {
					$delivery_tax_id = $fees['shipping_tax_id'];
					// $delivery_tax_rate = $fees['shipping_tax_rate'];
					$delivery_tax_value = $fees['shipping_tax'];
				}
			}


			if ( get_option( "rental_do_not_use_rentopian_shipping" ) ) {
				$default_shipping = 1;
				if ( ! $delivery_status && ! empty( wc_get_chosen_shipping_method_ids() ) ) {
					$delivery_status = 1;
				}
			}

			$opt_pickup_delivery = get_option( 'rental_pickup_delivery', 'company_delivery_return' );
			if ( $opt_pickup_delivery == 'company_delivery_return' ) {
				$delivery_status = 1;
			} else if ( $opt_pickup_delivery == 'client_pickup_return' ) {
				$delivery_status = 2;
			} else if ( $opt_pickup_delivery == 'company_client_delivery_return' ) {

				if ( isset( $_COOKIE['rental_shipping_method'] ) ) {
					$delivery_status = $_COOKIE['rental_shipping_method'];
					setcookie( 'rental_shipping_method', 1, time() - 3600, "/", "", false, true ); // 1 hour expiration
					unset( $_COOKIE['rental_shipping_method'] );
				}
			}

			$zip_heal = Rental_Data_Healer::heal_zip(
				isset( $_COOKIE['rental_zip'] ) ? $_COOKIE['rental_zip'] : '',
				get_option( 'rental_encryption_key' ),
				$wc_order
			);
			if ( $zip_heal['healed'] ) {
				Rentopian_Order_Logger::heal( $order_id, 'rental_zip', $zip_heal );
			}
			$decrypted_rental_zip = $zip_heal['value'];
			$division_zip         = get_option( "rental_hide_zip" ) ? '' : $decrypted_rental_zip;

			$billing_address_2 = $wc_order->get_billing_address_2() ? $wc_order->get_billing_address_2() : '';
			$billing_address   = $wc_order->get_billing_address_1() ? trim( $wc_order->get_billing_address_1() ) : '';

			$shipping_address    = $wc_order->get_shipping_address_1() ? trim( $wc_order->get_shipping_address_1() ) : '';
			$shipping_address_2  = $wc_order->get_shipping_address_2() ? trim( $wc_order->get_shipping_address_2() ) : '';

			$rush_fee          = rental_get_suitable_rush_fee();
			$rush_fee["total"] = ! empty( $fees['rush_fee'] ) ? $fees['rush_fee'] : 0;

			$coupon_code = '';
			$coupons     = get_applied_coupons();
			if ( ! empty( $coupons ) ) {
				// if (count($coupons) > 1) {
				//     $coupon_codes_array = [];
				//     foreach($coupons as $coupon) {
				//         $coupon_codes_array[] = $coupon->get_code();
				//     }
				//     $coupon_code = implode(',', $coupon_codes_array);
				// } else {
				// $coupon_code = $coupons[0]['code'];
				// }

				$coupon_code = $coupons[0]['code'];

				$couponDiscount = get_rental_session_data( 'rental_coupon_discount', 0 );
				// update coupon in db
				update_post_meta( $order_id, "_cart_discount", ! empty( $couponDiscount ) ? $couponDiscount : $fees['coupon_discount'] );
			}


			$deposit_id = $deposit_amount_raw = $deposit_is_percent = $deposit_amount = 0;
			if ( $deposit = rental_get_deposit() ) {
				$deposit_id         = isset( $deposit['id'] ) && $deposit['id'] ? $deposit['id'] : 0;
				$deposit_amount_raw = isset( $deposit['amount'] ) && $deposit['amount'] ? $deposit['amount'] : 0;
				$deposit_is_percent = isset( $deposit['is_percent'] ) && $deposit['is_percent'] ? $deposit['is_percent'] : 0;

				if ( $total_excluded_extra_fees = get_rental_session_data( 'total_excluded_extra_fees', 0 ) ) {
					$deposit_amount = $deposit_is_percent == 1 ? format_value_to_fixed_precision( $total_excluded_extra_fees * ( $deposit_amount_raw / 100 ), 2 ) : $deposit_amount_raw;
				}
			}

			if ( isset( WC()->cart->rental_deposit['deposit_enabled'] ) && WC()->cart->rental_deposit['deposit_enabled'] ) {
				$deposit_id     = WC()->cart->rental_deposit['deposit_id'];
				$deposit_amount = WC()->cart->rental_deposit['deposit_amount'];
			}

			$custom_fields             = isset( $_POST["rental_custom_fields"] ) ? json_encode( $_POST["rental_custom_fields"] ) : "";
			$security_deposit_settings = rental_get_security_deposit();


			$rental_order_selected_options_opt     = get_rental_session_data( 'rental_order_selected_options', array() );
			$rental_order_selected_options_of_sets = get_rental_session_data( "rental_order_selected_options_of_sets", array() );

			// tracking wc shipping method only if rentopian shipping is disabled
			if (
				$wc_shipping_method = get_rental_session_data( 'wc_shipping_method', 0 )
				&& get_option( 'rental_do_not_use_rentopian_shipping' )
				&& get_option( 'rental_track_wc_shipping' )
			) {
				$delivery_status = $wc_shipping_method;
			}


			$charge_only_delivery_for_website  = get_rental_session_data( 'charge_only_delivery_for_website', 0 );
			$delivery_cost_with_additionals    = get_rental_session_data( 'rental_delivery_cost', 0 );
			$pickup_cost_with_additionals      = ! $charge_only_delivery_for_website ? get_rental_session_data( 'rental_pickup_cost', 0 ) : 0;

			$timezone                                       = rental_get_timezone();
			$exact_time                                     = $startDateTime = $endDateTime = '';
			if (
				isset( $_COOKIE['rental_selected_delivery_selection_time'] )
				&& $_COOKIE['rental_selected_delivery_selection_time']
			) {

				$deliveryTimesData = RTDelivery::extractDeliveryStartEndTime( $start_date, $timezone, $_COOKIE['rental_selected_delivery_selection_time'] );

				$startDateTime = $deliveryTimesData['start_time'];
				$endDateTime   = $deliveryTimesData['end_time'];
			}


			$pickup_exact_time = $pickupStartDateTime = $pickupEndDateTime = '';
			if (
				isset( $_COOKIE['rental_selected_pickup_selection_time'] )
				&& $_COOKIE['rental_selected_pickup_selection_time']
			) {

				$pickupTimesData = RTDelivery::extractPickupStartEndTime( $end_date, $timezone, $_COOKIE['rental_selected_pickup_selection_time'] );

				$pickupStartDateTime = $pickupTimesData['start_time'];
				$pickupEndDateTime   = $pickupTimesData['end_time'];
			}

			// echo "<pre>"; print_r($inventories); echo "</pre>"; exit;

			$exclude_delivery_from_coupon = ! empty( rental_get_coupon_settings() ) ? rental_get_coupon_settings()["exclude_delivery_from_coupon"] : null;

			$data = array(
				"wp_order_id"                  => $order_id,
				"inventories"                  => json_encode( $inventories ),
				"order_options"                => isset( $rental_order_selected_options_opt ) && $rental_order_selected_options_opt ? json_encode( $rental_order_selected_options_opt ) : "",
				"order_options_sets"           => isset( $rental_order_selected_options_of_sets ) ? json_encode( $rental_order_selected_options_of_sets ) : "",
				"total"                        => (float) $wc_order->get_total(),
				"type"                         => get_option( "rental_direct_only_bookings" ) ? "order" : "quote",
				"gmt_offset"                   => get_option( "timezone_string" ) ?: get_option( "gmt_offset" ),
				"start_date"                   => $start_date,
				"end_date"                     => $end_date,
				"days"                         => $days,
				"event_time"                   => $event_time,
				// "delivery_restriction_met" => 0,
				"new_delivery_system"          => 1, // TODO : Must be removed after updating all the clients websites
				"delivery_cost"                => $delivery_status == 1 ? $delivery_cost_with_additionals : 0,
				"total_delivery_cost"          => $delivery_status == 1 ? $delivery_cost_with_additionals : 0,
				"pickup_cost"                  => $delivery_status == 1 ? $pickup_cost_with_additionals : 0,
				"total_pickup_cost"            => $delivery_status == 1 ? $pickup_cost_with_additionals : 0,
				"delivery_tax"                 => $delivery_tax_value,
				"delivery_tax_id"              => $delivery_tax_id,
				"delivery_status"              => $delivery_status,
				"custom_delivery"              => $default_shipping,
				"rental_tax_id"                => ! empty( $fees['rental_tax_id'] ) ? $fees['rental_tax_id'] : 0,
				"rental_taxable_amount"        => (float) get_rental_session_data( 'rental_taxable_amount' ),
				"sale_tax_id"                  => ! empty( $fees['sale_tax_id'] ) ? $fees['sale_tax_id'] : 0,
				"service_tax_id"               => ! empty( $fees['service_tax_id'] ) ? $fees['service_tax_id'] : 0,
				"exempt_waiver"                => isset( $fees['damage_waiver'] ) && $fees['damage_waiver'] ? 0 : 1,
				"damage_waiver_tax_id"         => ! empty( $fees['damage_waiver_tax_id'] ) ? $fees['damage_waiver_tax_id'] : 0,
				"rush_fee"                     => $rush_fee["total"],
				"rush_fee_amount"              => $rush_fee["amount"],
				"rush_fee_type"                => $rush_fee["type"],
				"coupon_code"                  => $coupon_code,
				"discount_before_tax"          => ! empty( $fees['discount_before_tax'] ) ? $fees['discount_before_tax'] : 0,
				"order_fees"                   => empty( $fees['order_fees'] ) ? null : json_encode( $fees['order_fees'] ),
				"deposit_id"                   => $deposit_id,
				"deposit_amount"               => $deposit_amount,
				"firstname"                    => $wc_order->get_billing_first_name(),
				"lastname"                     => $wc_order->get_billing_last_name(),
				"company"                      => $wc_order->get_billing_company(),
				"email"                        => $wc_order->get_billing_email(),
				"phone"                        => $wc_order->get_billing_phone(),
				"address"                      => $billing_address,
				"address_2"                    => $billing_address_2,
				"city"                         => $wc_order->get_billing_city(),
				"state"                        => $wc_order->get_billing_state(),
				"country"                      => $wc_order->get_billing_country(),
				"zip"                          => $wc_order->get_billing_postcode() ?: $division_zip,
				"division_zip"                 => $division_zip,
				"division_id"                  => get_option( 'rental_select_division' ) && isset( $_COOKIE['rental_division_id'] ) ? $_COOKIE['rental_division_id'] : '',
				"shipping_address"             => $shipping_address,
				"shipping_address_2"           => $shipping_address_2,
				"shipping_zip"                 => $wc_order->get_shipping_postcode() ?: $division_zip,
				"shipping_city"                => $wc_order->get_shipping_city(),
				"shipping_state"               => $wc_order->get_shipping_state(),
				"shipping_country"             => $wc_order->get_shipping_country(),
				"send_email"                   => get_option( "rental_send_email" ) ? 1 : 0,
				"notes"                        => $wc_order->get_customer_note(),
				"custom_fields"                => $custom_fields,
				"referral_source_id"           => get_rental_session_data( 'rental_referral_source_id', 0 ),
				"event_type"                   => get_rental_session_data( 'rental_event_type_title', '' ),
				"exclude_delivery_from_coupon" => $exclude_delivery_from_coupon,
			);

			// EVENT DATE OFFSET: Add offset metadata for Rentopian core sync
			$order_offsets = rental_get_event_date_offsets();
			if ( $order_offsets ) {
				$data['order_dates_type']  = 1; // Event Date mode (matches core system)
				$data['start_date_offset'] = $order_offsets['start_date_offset'];
				$data['end_date_offset']   = $order_offsets['end_date_offset'];

				// Send the original event instant (WP timezone–aware; matches chosen/default time when pickers visible).
				if ( isset( $_COOKIE['rental_event_date'] ) ) {
					$event_date_val = decrypt_data( $_COOKIE['rental_event_date'], get_option( 'rental_encryption_key' ) );
					if ( is_string( $event_date_val ) && $event_date_val !== '' && function_exists( 'rental_event_string_to_order_exact_timestamp' ) ) {
						$event_ts_val = rental_event_string_to_order_exact_timestamp( $event_date_val );
						if ( false !== $event_ts_val ) {
							$data['order_exact_date'] = (int) $event_ts_val;
						}
					}
				}
			} else {
				$data['order_dates_type'] = 0; // Custom Date Range mode
			}

			// stripping zip (billing post code) and shipping zip (shipping post code) extensions if exists
			$data['zip']          = strip_zip_code_extensions( $data['zip'] );
			$data['shipping_zip'] = strip_zip_code_extensions( $data['shipping_zip'] );

			if ( $delivery_status == 1 ) {
				if ( $delivery_venue_address_id = get_rental_session_data( 'rental_delivery_venue_address_id', 0 ) ) {
					$data['venue_address_id'] = $delivery_venue_address_id;
				}

				// if (!$charge_only_delivery_for_website) {
				$pickup_address_type = get_rental_session_data( 'rental_pickup_address_type', 1 );
				if ( $pickup_venue_address_id = get_rental_session_data( 'rental_pickup_venue_address_id', 0 ) ) {
					$data['pickup_address_id'] = $pickup_address_type == 2 ? $pickup_venue_address_id : 0;
				}

				$data['pickup_address_type'] = $pickup_address_type;
				// }


				$data["delivery_date_time"] = json_encode( array(
					"type"       => "window",
					"start_time" => $startDateTime ? $startDateTime : '',
					"end_time"   => $endDateTime ? $endDateTime : '',
					// "exact_time" => 0,
				) );

				$data["pickup_data_time"] = json_encode( array(
					"pickup_date_type" => "window",
					"pickup_start_date" => $pickupStartDateTime ? $pickupStartDateTime : '',
					"pickup_end_date"   => $pickupEndDateTime ? $pickupEndDateTime : '',
					// "pickup_exact_date" => ""
				) );

			}


			// adding security deposit calculations and settings to order
			if ( ! empty( $fees['security_deposit_fee'] ) ) {
				$data["security_deposit_id"]             = ! empty( $security_deposit_settings ) ? $security_deposit_settings['id'] : 0;
				$data["security_deposit_rate"]           = ! empty( $security_deposit_settings ) ? $security_deposit_settings['rate'] : 0;
				$data["security_deposit_amount"]         = ! empty( $fees['security_deposit_fee'] ) ? $fees['security_deposit_fee'] : 0;
				$data["security_deposit_on_replacement"] = ! empty( $security_deposit_settings ) ? $security_deposit_settings['on_replacement'] : 0;
			}

			if ( ( $opt_pickup_delivery == 'company_delivery_return' || $opt_pickup_delivery == 'company_client_delivery_return' ) && isset( $_POST['rental_different_pick_up_address'] ) ) {

				$data['pick_up_address'] = $delivery_status == 1 ? $_POST['rental_pick_up_address_1'] : '';

				if ( ! empty( $_POST['rental_pick_up_address_2'] ) ) {
					$data['pick_up_address_2'] = $delivery_status == 1 ? $_POST['rental_pick_up_address_2'] : '';
				}

				$data['pick_up_zip']     = $delivery_status == 1 ? $_POST['rental_pick_up_postcode'] : '';
				$data['pick_up_city']    = $delivery_status == 1 ? $_POST['rental_pick_up_city'] : '';
				$data['pick_up_state']   = $delivery_status == 1 ? $_POST['rental_pick_up_state'] : '';
				$data['pick_up_country'] = $delivery_status == 1 ? $_POST['rental_pick_up_country'] : '';
			}

			// adding auto tax data to order
			if ( isset( $fees['is_auto_tax'] ) && $fees['is_auto_tax'] == 1 && isset( $fees['auto_tax'] ) ) {
				$data['is_auto_tax']   = $fees['is_auto_tax'];
				$data['auto_tax_rate'] = $fees['auto_tax_rate'];
				$data['auto_tax']      = $fees['auto_tax'];
			}

			// Allow photo upload handler to attach photos to order data
			$data = apply_filters( 'rental_order_data_before_send', $data, $order_id );

			Rentopian_Order_Logger::order_send( $order_id, array(
				'inventories'   => count( $inventories ),
				'total'         => (float) $wc_order->get_total(),
				'start'         => $start_date,
				'end'           => $end_date,
				'has_incidents' => Rentopian_Incident_Reporter::queued_count( $order_id ) > 0,
			) );

			// Optional: dump full payload to wc-logs/ for replay debugging.
			if ( get_option( 'rental_log_full_payload', 0 ) ) {
				Rentopian_Order_Logger::payload_dump( $order_id, $data );
			}

			rental_send_order( $order_id, $data );
			unset_temporary_data();

			Rentopian_Order_Logger::order_done( $order_id, array(
				'status'         => 'sent',
				'incident_count' => Rentopian_Incident_Reporter::queued_count( $order_id ),
			) );

		} else {

			// log incident
			Rentopian_Order_Logger::incident(
				$order_id,
				'empty_inventories_not_sent',
				array(
					'note'              => 'Order has NO syncable inventories. NOT sending to Laravel; manual review required.',
					'cart_items_count'  => count( $cart_contents ),
					'order_items_count' => isset( $wc_order ) && $wc_order ? count( $wc_order->get_items() ) : 0,
					'customer'          => isset( $wc_order ) && $wc_order ? trim( $wc_order->get_billing_first_name() . ' ' . $wc_order->get_billing_last_name() ) : '',
					'customer_email'    => isset( $wc_order ) && $wc_order ? $wc_order->get_billing_email() : '',
					'wc_order_total'    => isset( $wc_order ) && $wc_order ? (float) $wc_order->get_total() : 0,
				),
				'critical'
			);

			update_post_meta( $order_id, '_rental_sync_blocked', 1 );
			update_post_meta( $order_id, '_rental_sync_blocked_reason', 'empty_inventories' );
			update_post_meta( $order_id, '_rental_sync_blocked_at', current_time( 'mysql' ) );

			Rentopian_Order_Logger::log(
				$order_id,
				'ORDER_NOT_SENT',
				array( 'reason' => 'empty_inventories' ),
				'critical'
			);
		}

	} catch ( Throwable $e ) {
		// Catch anything unexpected.
		Rentopian_Order_Logger::order_exception( $order_id, $e );

	} finally {
		// Always dispatch the per-order incident summary email (if any).
		Rentopian_Order_Logger::dispatch_incidents( $order_id );
	}
}

function rental_send_order($order_id, $data, $resend = false) {
    global $wpdb, $rental_tables;
    $rental_order_relations = $wpdb->prefix . $rental_tables["order_relations"];
    $log_path = 'wp-content/uploads/wc-logs/rentopian-orders-' . date( 'Y-m-d' ) . '.log';

    $log = [
        "rental_id" => 0,
        "http_code" => 200,
        "message" => "",
        "register_time" => time()
    ];

    $photos_count = isset($data['checkout_photos_count']) ? (int) $data['checkout_photos_count'] : 0;
    $use_multipart = $photos_count > 0 && class_exists('Rental_Checkout_Photo_Upload');

    try {
        if ($use_multipart) {
            $photo_upload = Rental_Checkout_Photo_Upload::get_instance();
            $photos = $photo_upload->get_order_photos_for_multipart($order_id);
            if (!empty($photos)) {
                $body = $data;
                unset($body['checkout_photos']);
                $body['checkout_photos_count'] = count($photos);
                $compressed_temps = array();
                foreach ($photos as $i => $photo) {
                    $path = $photo['path'];
                    $mime = isset($photo['mime_type']) ? $photo['mime_type'] : 'image/jpeg';
                    $name = isset($photo['original_name']) ? $photo['original_name'] : 'photo.jpg';

                    // Compress to reduce payload and avoid 413
                    if (function_exists('rental_compress_photo_for_upload')) {
                        $compressed = rental_compress_photo_for_upload($path, $mime);
                        if ($compressed && $compressed !== $path) {
                            $compressed_temps[] = $compressed;
                            $path = $compressed;
                            if (strpos($mime, 'png') !== false) {
                                $name = pathinfo($name, PATHINFO_FILENAME) . '.jpg';
                                $mime = 'image/jpeg';
                            }
                        }
                    }

                    // Key format: checkout_photos[$i] — Laravel receives as $request->file('checkout_photos')
                    $body["checkout_photos[$i]"] = new \CURLFile($path, $mime, $name);
                }
                $rental_order = rental_curl_multipart('orders/add', get_option('rental_api_key'), $body, true);

                // Cleanup compressed temp files
                foreach ($compressed_temps as $tmp) {
                    if (file_exists($tmp)) @unlink($tmp);
                }
            } else {
                $rental_order = rental_curl('orders/add', get_option('rental_api_key'), true, $data, null, false, 60);
            }
        } else {
            $rental_order = rental_curl('orders/add', get_option('rental_api_key'), true, $data, null, false, 60);
        }

        if (isset($rental_order->id)) {
            $log['rental_id'] = $rental_order->id;
            Project_WP_Logger::write( "API_SUCCESS | wp_id={$order_id} | rental_id={$rental_order->id}" . ( $resend ? ' | resend=1' : '' ), 'info', 'rentopian-sync', $log_path );
        }
    } catch (RentalException $e) {
        $log['http_code'] = $e->getStatusCode();
        $log['message'] = $e->getMessage();
        Project_WP_Logger::write( "API_FAIL | wp_id={$order_id} | http={$log['http_code']} | error=" . substr( $log['message'], 0, 200 ), 'error', 'rentopian-sync', $log_path );
    }

    if ($resend) {
        // Don't update register_time on resend - keep original creation time
        $update_log = $log;
        unset($update_log["register_time"]);
        $wpdb->update($rental_order_relations, $update_log, ["id" => $order_id]);
    } else {
        $log["id"] = $order_id;
        $log["data"] = serialize($data);
        $log["version"] = RENTOPIAN_SYNC_VERSION;
        $wpdb->insert($rental_order_relations, $log);
    }

    if ($wpdb->last_error !== "") {
        Project_WP_Logger::write( "DB_ERROR | wp_id={$order_id} | rental_id={$log['rental_id']} | error=" . substr( $wpdb->last_error, 0, 200 ), 'error', 'rentopian-sync', $log_path );
        throw new RentalException("SQL_ERROR: $wpdb->last_error (SQL: $wpdb->last_query)");
    }

    return $log;
}

// send the payment data
function rental_pay_order($order_id) {
    global $wpdb, $rental_tables;
    $rental_order_relations = $wpdb->prefix . $rental_tables["order_relations"];

    $rental_order_id = $wpdb->get_var("SELECT `rental_id` FROM $rental_order_relations WHERE `id` = $order_id");
    if ( !$rental_order_id) {
        return;
    }

    $wc_order = (new WC_Order($order_id));
    $payment_method = $wc_order->get_payment_method();
    $transaction_id = $wc_order->get_transaction_id();
    if ($payment_method == "cod" || $payment_method == "cheque" || !$transaction_id) {
        return;
    }

    // Detect deposit-based order
    $deposit_amount_meta = (float) $wc_order->get_meta('_rental_deposit_amount', true);
    $balance_due_meta    = (float) $wc_order->get_meta('_rental_balance_due', true);

    $is_deposit_order = (
        get_option('rental_allow_to_pay_deposit') // feature enabled
        && $deposit_amount_meta > 0
        && $balance_due_meta > 0
    );

    if ($is_deposit_order) {
        // Customer paid only a deposit (or "other amount")

        $amount = (float) $wc_order->get_total();
    } else {
        // Full payment flow.

        // The recorded payment must match the amount actually charged, which
        // INCLUDES the refundable security deposit (added as a cart fee at
        // checkout) but EXCLUDES the tip (synced separately via tip_id/tip_amount
        // below). Using only total_excluded_extra_fees here under-reported the
        // payment by the security deposit amount, leaving it perpetually "owed".
        $base_total           = (float) get_rental_session_data('total_excluded_extra_fees', 0);
        $security_deposit_fee = (float) get_rental_session_data('rental_security_deposit_fee', 0);

        if ($base_total > 0) {
            
            $amount = $base_total + $security_deposit_fee;
        } else {
            // Fallback if session is gone: use the charged total minus the tip
            // (the tip is reported separately).

            $tip_amount = (float) get_rental_session_data('rental_payment_tip_amount', 0);
            $amount     = (float) $wc_order->get_total() - $tip_amount;
        }
    }

    $data_to_send = [
        "wp_order_id" => $order_id,
        "order_id" => $rental_order_id,
        "transaction_id" => $transaction_id,
        "amount" => (float) $amount,
    ];

    $payment_tip_id = get_rental_session_data('rental_payment_tip_id', 0);
    $payment_tip_amount = get_rental_session_data('rental_payment_tip_amount', 0);

    if ($payment_tip_id && $payment_tip_amount) {
        $data_to_send = [
            "wp_order_id" => $order_id,
            "order_id" => $rental_order_id,
            "transaction_id" => $transaction_id,
            "amount" => (float) $amount,
            "tip_id" => $payment_tip_id,
            "tip_amount" => $payment_tip_amount,
        ];
    }
    
    $rental_payment = rental_curl("orders/pay", get_option("rental_api_key"), true, $data_to_send, null, false, 60);
}

// send the payment data
function rental_delete_order($order_id) {
    global $wpdb, $rental_tables;
    $rental_order_relations = $wpdb->prefix . $rental_tables["order_relations"];

    $rental_order_id = $wpdb->get_var("SELECT `rental_id` FROM $rental_order_relations WHERE `id` = $order_id");
    if ( !$rental_order_id) {
        return;
    }

    $wc_order = (new WC_Order($order_id));
    $payment_method = $wc_order->get_payment_method();
    $transaction_id = $wc_order->get_transaction_id();
    if ($payment_method == "cod" || $payment_method == "cheque" || !$transaction_id) {
        $amount = 0;
    } else {
        $amount = $wc_order->get_total();
    }
    

    if (time() < $wc_order->get_date_created()->getTimestamp() + 5400) { // 5400 seconds is 90 minutes
        return;
    }

    rental_curl("orders/delete", get_option("rental_api_key"), true, [
        "wp_order_id" => $order_id,
        "order_id" => $rental_order_id,
        "amount" => $amount
    ]);
}

// Check if set, if its not set add an error.
function rental_checkout_process($order_id) {

    // $rental_shipping_has_error = get_rental_session_data('rental_shipping_has_error', 0);
    // if ($rental_shipping_has_error) {
    //     wc_add_notice(__('Please contact us for a quote since you are outside of our delivery zone', 'rentopian-sync'), 'error');
    // }

    if (isset($_POST['rental_event_time']) && $_POST['rental_event_time']) {
        $_COOKIE["rental_event_time"] = $_POST['rental_event_time'];
        setcookie('rental_event_time', $_POST['rental_event_time'], time() + (10800), "/", "", false, true);
    }


    if (get_option('rental_synchronized_product_type') == "hourly") {

        if ( !isset($_COOKIE["rental_hourly_start_date"])) {
            wc_add_notice(__('Rental Start date is required.', 'rentopian-sync'), 'error');
        }

    } else {

        if (!isset($_COOKIE['rental_start_date']) || !$_COOKIE['rental_start_date']) {
            wc_add_notice(__('Rental Start date is required.', 'rentopian-sync'), 'error');
        }

        if (isset($_COOKIE['rental_start_date']) && $_COOKIE['rental_start_date']
            // && strtotime($_COOKIE['rental_start_date']) < strtotime("today")
        ) {

            $decrypted_rental_start_date = "";
            if (isset($_COOKIE['rental_start_date']) && $_COOKIE['rental_start_date']) {
                $decrypted_rental_start_date = decrypt_data($_COOKIE['rental_start_date'], get_option('rental_encryption_key'));
            }

            if (empty($decrypted_rental_start_date) || ($decrypted_rental_start_date && strtotime($decrypted_rental_start_date) < strtotime("today"))) {
                wc_add_notice(__('Please select a valid rental start date.', 'rentopian-sync'), 'error');
            }
            
        }

        if (!isset($_COOKIE['rental_zip'])) {
            wc_add_notice(__('ZIP Code (submitted via rental dates selection form) is required.', 'rentopian-sync'), 'error');
        }

    }
    
    // Validate pick-up address fields when the "different pick-up address" checkbox is checked.
    if ( ! empty( $_POST['rental_different_pick_up_address'] ) ) {
        $pickup_required = [
            'rental_pick_up_address_1' => __( 'Pick-up street address', 'rentopian-sync' ),
            'rental_pick_up_city'      => __( 'Pick-up town / city', 'rentopian-sync' ),
            'rental_pick_up_state'     => __( 'Pick-up state', 'rentopian-sync' ),
            'rental_pick_up_postcode'  => __( 'Pick-up ZIP code', 'rentopian-sync' ),
            'rental_pick_up_country'   => __( 'Pick-up country', 'rentopian-sync' ),
        ];
        foreach ( $pickup_required as $field => $label ) {
            if ( empty( $_POST[ $field ] ) ) {
                /* translators: %s: field label */
                wc_add_notice(
                    sprintf( __( '<strong>%s</strong> is a required field.', 'rentopian-sync' ), $label ),
                    'error'
                );
            }
        }
    }

    // only when allow overbook is disabled validate inventories before checkout
    if (get_option('rental_allow_overbook', 0) != 1) {
        $_cart = WC()->cart;
        $cart_contents = $_cart->cart_contents;
        $sum_quantity_in_cart = [];
        $inventories = [];
        $set_inv_map = [];
        $item_cart_keys = [];
        foreach ($cart_contents as $cart_item_key => $cart_item) {
            global $wpdb, $rental_tables;
            $rental_set_relations = $wpdb->prefix . $rental_tables["set_relations"];
    
            $product_id = $cart_item['product_id'];
            $variation_id = $cart_item['variation_id'] ? $cart_item['variation_id'] : $cart_item['product_id'];

            $inv_id = 0;
            if (get_post_meta($product_id, '_rental_is_set', true)) {
                $set_id = $wpdb->get_var("SELECT `rental_id` FROM $rental_set_relations WHERE `id` = " . $product_id);
                $add_ons = get_post_meta($product_id, '_rental_set_items', true);
                foreach ($add_ons as $i => $add_on) {
                    if ($add_on['variant_id']) {
                        $add_on['inv_id'] = get_post_meta($add_on['variant_id'], '_rental_inventory_id', true);
                    } else {
                        $add_on['variant_id'] = 0;
                        $add_on['inv_id'] = get_post_meta($add_on['product_id'], '_rental_inventory_id', true);
                    }
                    $add_on['price'] = 0;
                    $add_on['required'] = true;
                    $add_on['set_id'] = $set_id;
                    $add_ons[$i] = $add_on;
                    $inventories[] = $add_on['inv_id'];

                    // Track each set member's required quantity (set qty x member qty) and map the
                    // member inventory back to the set's cart item, so the availability check below
                    // can block (and log) the whole set when a member is short for the selected dates.
                    $member_inv_id = $add_on['inv_id'];
                    if ($member_inv_id) {
                        $member_required_qty = $cart_item["quantity"] * (isset($add_on['quantity']) ? (int) $add_on['quantity'] : 1);
                        if (isset($sum_quantity_in_cart[$member_inv_id])) {
                            $sum_quantity_in_cart[$member_inv_id] += $member_required_qty;
                        } else {
                            $sum_quantity_in_cart[$member_inv_id] = $member_required_qty;
                        }
                        if ( !isset($item_cart_keys[$member_inv_id])) {
                            $item_cart_keys[$member_inv_id] = $cart_item_key;
                        }
                        $set_inv_map[$member_inv_id] = $product_id;
                    }
                }
            } else {
                $inventories[] = $inv_id = get_post_meta($variation_id?: $product_id, '_rental_inventory_id', true);
                $add_ons = get_post_meta($product_id, '_rental_add_ons', true);
                if ( !empty($add_ons)) {
                    foreach ($add_ons as $i => $add_on) {
                        $product = wc_get_product($add_on['product_id']);
                        if ($product->is_type('variable')) {
                            if ( !$add_on['variant_id']) {
                                $variants = $product->get_visible_children();
    
                                if ( !isset($variants[0])) {
                                    $error_msg = 'Sorry, this product is not available.';
                                    wc_add_notice(__($error_msg, 'rentopian-sync'), 'error');
                                    return;
                                }
                                if (isset($_POST['rental_add_on_variants']) && isset($_POST['rental_add_on_variants'][$add_on['product_id']]) &&
                                    in_array($_POST['rental_add_on_variants'][$add_on['product_id']], $variants)) {
                                    $add_on['variant_id'] = $_POST['rental_add_on_variants'][$add_on['product_id']];
                                } else {
                                    $add_on['variant_id'] = $variants[0];
                                }
                            }
                            $add_on['inv_id'] = get_post_meta($add_on['variant_id'], '_rental_inventory_id', true);
                        } else {
                            $add_on['inv_id'] = get_post_meta($add_on['product_id'], '_rental_inventory_id', true);
                        }
                        $add_on['set_id'] = 0;
                        $add_ons[$i] = $add_on;
                        $inventories[] = $add_on['inv_id'];
                    }
                }
            }
    
            // Sum quantity per inventory id across ALL cart lines so items sharing one
            // inventory (multiple lines, variations, duplicate copies) are added up
            // rather than overwritten.
            if (!isset($sum_quantity_in_cart[$inv_id])) {
                $sum_quantity_in_cart[$inv_id] = 0;
            }
            $sum_quantity_in_cart[$inv_id] += $cart_item["quantity"];
            $item_cart_keys[$inv_id] = $cart_item_key;
        }
    
        // only when allow overbook is disabled(on the fly) validate inventories before checkout 
        $availability = rental_check_availability($inventories);
        $validation_has_error = false;
        $validation_error_list = [];
        $return_to_shop_html_link = '<a target="_blank" href="' . esc_url( wc_get_page_permalink( 'shop' ) ) . '" class="wc-backward">' . __( 'return to shop', 'woocommerce' ) . '</a>';
        $custom_cart_label = get_option('rental_cart_button_text') ? lcfirst(get_option('rental_cart_button_text')) : 'cart';
        $custom_cart_label_html_link = "<a target='_blank' href='" . esc_url(wc_get_cart_url()) . "'>" .$custom_cart_label. "</a>"; 
        $core_allows_overbook = is_array($availability)
            && isset($availability['allow_overbook'])
            && $availability['allow_overbook'];

        $validation_ready = is_array($availability)
            && isset($availability['allow_overbook'])
            && !$availability['allow_overbook']
            && isset($availability['inventories']);

        // Overbook is disabled locally, so availability MUST be verified before an order
        // is placed. Unless the core either explicitly permits overbooking or returns a
        // usable availability result, fail closed and block checkout (e.g. core timeout /
        // error, 'not_set' / 'not_valid', or a malformed response) so a booking can never
        // slip through unvalidated.
        if (!empty($inventories) && !$core_allows_overbook && !$validation_ready) {
            wc_add_notice(__('Unfortunately, we could not verify product availability for your selected dates. Please, review your '.$custom_cart_label_html_link.' and try again.', 'rentopian-sync'), 'error');
            return;
        }

        if ($validation_ready) {
            foreach($availability["inventories"] as $inventory) {
                if(isset($inventory['id'])) {
                    $inv_id = $inventory['id'];
                    $available_quantity = $inventory["quantity"];
                    $name = isset($item_cart_keys[$inv_id]) && $item_cart_keys[$inv_id] && isset($cart_contents[$item_cart_keys[$inv_id]])? '"' . $cart_contents[$item_cart_keys[$inv_id]]['data']->get_name() . '" ': '';
                    if (empty($available_quantity)) {
                        $validation_has_error = true;
                        WC()->cart->remove_cart_item($item_cart_keys[$inv_id]);
                        $validation_error_list[] = __($name . ' : Removed from cart!', 'rentopian-sync');

                        if (isset($set_inv_map[$inv_id])) {
                            Rentopian_Order_Logger::set_add_blocked('n/a', $set_inv_map[$inv_id], array(
                                'stage'         => 'checkout_process',
                                'inv_id'        => $inv_id,
                                'requested_qty' => isset($sum_quantity_in_cart[$inv_id]) ? $sum_quantity_in_cart[$inv_id] : null,
                                'available_qty' => 0,
                            ));
                        }

                    } else if (isset($sum_quantity_in_cart[$inv_id]) && $sum_quantity_in_cart[$inv_id] > $available_quantity) {
                        $validation_has_error = true;
                        $validation_error_list[] = __($name . ' : Only', 'rentopian-sync') . " $available_quantity $name " . __('available. ', 'rentopian-sync');

                        if (isset($set_inv_map[$inv_id])) {
                            Rentopian_Order_Logger::set_add_blocked('n/a', $set_inv_map[$inv_id], array(
                                'stage'         => 'checkout_process',
                                'inv_id'        => $inv_id,
                                'requested_qty' => $sum_quantity_in_cart[$inv_id],
                                'available_qty' => $available_quantity,
                            ));
                        }
                    }
                }
            }
        }

        if ($validation_has_error && $validation_error_list) {
            $err_wrapper = __('Unfortunately, there was a change in product(s) availability. Please, review your '.$custom_cart_label_html_link.' again or ' . $return_to_shop_html_link, 'rentopian-sync');
            $err_wrapper .= "<br/> <ul>";
            foreach($validation_error_list as $err_msg) {
                $err_wrapper .= "<li> - " . $err_msg . "</li>";
            }
            $err_wrapper .= "</ul>";

            wc_add_notice($err_wrapper, 'error');
            return;
        }
    }
   
    $check = rental_check_selected_options();
    if ($check["check"] === false) {
        wc_add_notice(__('Please review the <a href="'.$check["url"].'" > options</a> before checking out.', 'rentopian-sync'), 'error');
    }

    // $min_order_amount = get_option('rental_min_order_amount');
    $delivery_settings = rental_get_delivery_settings();
    $min_order_amount = $delivery_settings['restriction_fixed_min_for_website'] ?? 0;
    if (
        $min_order_amount
        && WC()->cart->get_subtotal() < $min_order_amount
        // && (get_option('rental_pickup_delivery') === 'company_delivery_return' || !get_option('rental_min_order_pickup'))) {
        && (get_option('rental_pickup_delivery') === 'company_delivery_return' 
        || !$delivery_settings['restriction_fixed_min_allow_pickup_for_website'] )
    ) {
        
        // $message = get_option('rental_min_order_text');
        $message = $delivery_settings['restriction_fixed_min_message_for_website'];
        if ( !$message) {
            $message = 'You must have an order with a minimum of ' . wc_price($min_order_amount) . ' to place your order.';
        }
        wc_add_notice($message, 'error');
    }


    if (isset($_POST['rental_different_pick_up_address']) 
        && $delivery_settings['enable_different_pickup_delivery_address_for_website']
        // && get_option('rental_different_pick_up_addresses')
        && get_option('rental_pickup_delivery') === 'company_delivery_return'
    ) {
        if (empty($_POST['rental_pick_up_country'])) {
            wc_add_notice(__('Pick up country is required', 'rentopian-sync'), 'error');
        }
        if (empty($_POST['rental_pick_up_address_1'])) {
            wc_add_notice(__('Pick up street address is required', 'rentopian-sync'), 'error');
        }
        if (empty($_POST['rental_pick_up_city'])) {
            wc_add_notice(__('Pick up city is required', 'rentopian-sync'), 'error');
        }
        if (empty($_POST['rental_pick_up_state'])) {
            wc_add_notice(__('Pick up state is required', 'rentopian-sync'), 'error');
        }
        if (empty($_POST['rental_pick_up_postcode'])) {
            wc_add_notice(__('Pick up zip is required', 'rentopian-sync'), 'error');
        }
    }

    $email = isset($_POST['billing_email']) ? $_POST['billing_email'] : null;
    $phone = isset($_POST['billing_phone']) ? $_POST['billing_phone'] : null;
    $billing_first_name = isset($_POST['billing_first_name']) ? $_POST['billing_first_name'] : null;
    $billing_last_name = isset($_POST['billing_last_name']) ? $_POST['billing_last_name'] : null;
    
    $check_blocked_client = rental_check_blacklisted_client($email, $phone, $billing_first_name, $billing_last_name);
    if (is_array($check_blocked_client) && isset($check_blocked_client['message'])) {
        if ($check_blocked_client['message'] != 'no_match_found') {
            wc_add_notice(__($check_blocked_client['message'], 'rentopian-sync'), 'error');
        }
    }

    // check google map zip code against rental form zip code
    $billing_postcode = isset($_POST['billing_postcode']) ? $_POST['billing_postcode'] : null;
    $shipping_postcode = isset($_POST['shipping_postcode']) ? $_POST['shipping_postcode'] : null;
    $rental_pick_up_postcode = isset($_POST['rental_pick_up_postcode']) ? $_POST['rental_pick_up_postcode'] : null;
    $checkout_form_zip = $billing_postcode;
    if (is_null($billing_postcode)) {
        $checkout_form_zip = $shipping_postcode;
        if (is_null($shipping_postcode)) {
            $checkout_form_zip = $rental_pick_up_postcode;
        }
    }
    
    $decrypted_rental_zip = '';
    if (isset($_COOKIE['rental_zip'])) {
        $decrypted_rental_zip = decrypt_data($_COOKIE['rental_zip'], get_option('rental_encryption_key'));
    }

    $rental_zip = isset($_COOKIE['rental_zip']) && !get_option('rental_hide_zip') ? $decrypted_rental_zip : '';

    if (!empty($checkout_form_zip) && !empty($rental_zip) && ($checkout_form_zip != $rental_zip)) {
        wc_add_notice('The ZIP code provided in the rental form differs from the ZIP code of the specified address. Please either change the ZIP code on the rental form to match with the current address or change the current address to match the ZIP code provided in the rental form.', 'error');
    }

    if (isset($_POST['shipping_method']) && is_array($_POST['shipping_method'])) {
        
        $rental_shipping_method_value = str_contains($_POST['shipping_method'][0], 'local_pickup') ? 2 : 1;
        $_COOKIE['rental_shipping_method'] = $rental_shipping_method_value;
        setcookie('rental_shipping_method', $rental_shipping_method_value, time() + (3600), "/", "", false, true); // 1 hour expiration
    }

    if (isset($_POST['rental_referral_source_id']) && $_POST['rental_referral_source_id']) {
        set_rental_session_data('rental_referral_source_id', intval($_POST['rental_referral_source_id']));
    }

    if (isset($_POST['rental_event_types_id']) && $_POST['rental_event_types_id']) {
        $event_type_id = intval($_POST['rental_event_types_id']);
        $rental_event_types = get_option('rental_event_types', []);

        $rental_event_type_title = '';
        if ($rental_event_types) {
            foreach ($rental_event_types as $option) {
                if ($option->id == $event_type_id) {
                    $rental_event_type_title = $option->title;

                    break;
                }
            }
        }

        set_rental_session_data('rental_event_type_title', $rental_event_type_title);
    }

    if (
        isset($_POST['rental_payment_tip_id']) 
        && $_POST['rental_payment_tip_id']
        && get_option("rental_direct_only_bookings", 0)
        && get_option("rental_payment_tips_enabled", 0)
    ) {

        $tip_id = intval($_POST['rental_payment_tip_id']);
        calculate_tip_amount($tip_id);
    }

    $rental_custom_fields = get_option('rental_custom_fields');
    if ($rental_custom_fields !== false && $rental_custom_fields && isset($_POST['rental_custom_fields'])) {
        
        $groups = $rental_custom_fields;
        
        foreach ($groups as $group) {
            foreach ($group as $field) {
                $val = isset($_POST['rental_custom_fields'][$field['id']]) ? $_POST['rental_custom_fields'][$field['id']] : 0;

                if ($field['required'] == 1 && empty($val)) {
                    wc_add_notice(__($field['title'] . ' is required', 'rentopian-sync'), 'error');
                }
            }
        }
    }   


    if (get_option('rental_do_not_use_rentopian_shipping') && get_option('rental_track_wc_shipping')) {
        
        $wc_shipping_method = 1;
        if (str_contains($_POST['shipping_method'][0], 'local_pickup')) {
            $wc_shipping_method = 2;
        } else if (str_contains($_POST['shipping_method'][0], 'free_shipping') || str_contains($_POST['shipping_method'][0], 'flat_rate')) {
            $wc_shipping_method = 1;
        }
        
        set_rental_session_data('wc_shipping_method', $wc_shipping_method);

        // $_COOKIE['wc_shipping_method'] = $wc_shipping_method;
        // setcookie('wc_shipping_method', $wc_shipping_method, time() + (21600), "/", "", false, true); // 6 hour expiration
    }

   
    $coupons = get_applied_coupons();
    if (isset($coupons[0]) && isset($coupons[0]['code'])){

        $coupon_code = $coupons[0]['code'];
        if ($coupon_code) {
            $coupon_post_id = get_coupon_id_by_title($coupon_code);

            if ($coupon_post_id) {
                $coupon_usage_limit = get_post_meta($coupon_post_id, 'usage_limit', true);
                
                if ($coupon_usage_limit && $coupon_usage_limit != 99999) {
                    

                    $decrypted_rental_start_date = "";
                    if (isset($_COOKIE['rental_start_date']) && $_COOKIE['rental_start_date']) {
                        $decrypted_rental_start_date = decrypt_data($_COOKIE['rental_start_date'], get_option('rental_encryption_key'));
                    }

                    if ($decrypted_rental_start_date) {

                        // check for coupons with usage limitation 
                        // if the usage limit has been reached, the order will not proceed
                        rental_curl('coupons/check', get_option('rental_api_key'), false, [
                            'coupon_code' => $coupon_code,
                            'start_date' => $decrypted_rental_start_date,
                        ]);

                    } else {

                        wc_add_notice(__('Rental start date is required.', 'rentopian-sync'), 'error');
                    }
                }
            }
        }
    }

}

function rental_validate_payjunction_gateway_plugin() {
            
    if (class_exists('WC_Gateway_PayJunction') && get_option('rental_direct_only_bookings')) {

        $fees = rental_calculate_order_total();
        $coupons = get_applied_coupons();

        $coupon_discount = 0;
        if(isset($coupons[0])){
            $couponDiscount = get_rental_session_data('rental_coupon_discount', 0);

            if (empty($couponDiscount)) {

                if ($coupons[0]['discount_type'] == 'percent') {
                    $coupon_discount = $fees['total'] * $coupons[0]['amount'] / 100;
                } else if ($coupons[0]['discount_type'] == 'fixed_cart') {
                    $coupon_discount = $coupons[0]['amount'];
                }

            } else {

                $coupon_discount = $couponDiscount;
            }
           
        }

        if ( isset($fees['coupons']) && is_array($fees['coupons']) && isset($fees['coupons'][0]) ) {

            $coupon_discount_type = $fees['coupons'][0]['discount_type'];
            $coupon_amount = $fees['coupons'][0]['amount'];

            if ($coupon_discount_type === 'percent' && $coupon_amount == 100) {
                return true;
            }
        }

        if ($fees['total'] == $coupon_discount) {
            return true;
        }

        $error_msg = 'The payment required fields must be filled!';
        if (
            !isset($_POST['payjunction-card-number']) 
            || !isset($_POST['payjunction-card-expiry']) 
            || !isset($_POST['payjunction-card-cvc']) 
        ) {

            wc_add_notice(__($error_msg, 'rentopian-sync'), 'error');

        } else {

            if (
                empty($_POST['payjunction-card-number']) 
                || empty($_POST['payjunction-card-expiry']) 
                || empty($_POST['payjunction-card-cvc']) 
            ) {
                wc_add_notice(__($error_msg, 'rentopian-sync'), 'error');
            }

        }
        
    }
}

// Our hooked in function - $fields is passed via the filter!
function rental_override_checkout_fields($fields) {

    $fields['billing']['billing_phone']['required'] = true;

    if ( isset( $fields['order']['coupon_code'] ) ) {
        $rental_coupon_label_text = get_option('rental_coupon_label_text', 'Coupon');
        $fields['order']['coupon_code']['label']       = $rental_coupon_label_text;
        $fields['order']['coupon_code']['placeholder'] = sprintf(
            /* translators: %s = label */
            __( 'Enter your %s', 'dynamic-promo-label' ),
            strtolower( $rental_coupon_label_text )
        );
    }

    $rental_street_address_label_text = get_option('rental_street_address_label_text', 'Street address');
    if ( isset( $fields['billing']['billing_address_1']['label'] ) ) { 
        $fields['billing']['billing_address_1']['label']       = $rental_street_address_label_text;
        // $fields['billing']['billing_address_1']['placeholder'] = '';
    }

    if ( isset( $fields['shipping']['shipping_address_1']['label'] ) ) {
        $fields['shipping']['shipping_address_1']['label']       = $rental_street_address_label_text;
        // $fields['shipping']['shipping_address_1']['placeholder'] = '';
    }

    return $fields;
}

function rental_override_checkout_fields_labels() {
    $rental_street_address_label_text = get_option('rental_street_address_label_text', 'Street address');
    if ($rental_street_address_label_text) {
        wp_send_json_success(['label' => $rental_street_address_label_text]);
    }
    wp_send_json_success(['label' => '']);
}

// Check api key
function rental_check_api_key ($api_key) {

    $opt_api_key_is_valid = get_option('rental_api_key_is_valid', 0);
    // if key is set valid in WP then don't check with the API
    if ($opt_api_key_is_valid == 1) {
        return true;
    }

    try {
        $rental_check_api_key = rental_curl('check/api_key', $api_key);
        update_option('rental_api_key_is_valid', 1);
    } catch (RentalException $e) {
        $rental_check_api_key = false;
    }

    return $rental_check_api_key;
}

// Recalculate and store the cart totals. Only the cart and checkout pages recalculate
// on render, so without this a fee change stays invisible on every other page.
function rental_refresh_stored_cart_totals() {
    if (function_exists('WC') && WC()->cart) {
        WC()->cart->calculate_totals();
    }
}

// function ajax which is responsible for damage waiver
function wp_ajax_rental_damage_waiver() {
    if (isset($_POST['damage_waiver'])) {
        if ($_POST['damage_waiver'] == 'exempt') {
            $_COOKIE['rental_exempt_waiver'] = 1;
            setcookie('rental_exempt_waiver', 1, time() + (10800), "/", "", false, true);
            rental_refresh_stored_cart_totals();
            wp_send_json('exempt', 200);
            wp_die();
        } elseif ($_POST['damage_waiver'] == 'buy') {
            $_COOKIE['rental_exempt_waiver'] = 0;
            setcookie('rental_exempt_waiver', 0, time() + (10800), "/", "", false, true);
            rental_refresh_stored_cart_totals();
            wp_send_json('take', 200);
            wp_die();
        }
    }
    wp_send_json('error', 400);
    wp_die();
}

// function ajax which is responsible for applying coupon
function wp_ajax_rental_apply_coupon() {
    if (isset($_POST['coupon_code']) && $_POST['coupon_code']) {
        try {

            if (!WC()->cart->add_discount(sanitize_text_field($_POST['coupon_code']))) {
                unset($_COOKIE['rental_coupon_code']);
                setcookie('rental_coupon_code', false, time() - (31556952), "/", "", false, false);
    
                $error_msg = "The coupon code is invalid!";
                $_COOKIE['rental_coupon_code_is_invalid_error'] = $error_msg;
                setcookie('rental_coupon_code_is_invalid_error', $error_msg, time() + (3600), "/", "", false, true); // 1 hour expiration

                wp_send_json(['message' => 'The coupon code is not valid!']);
                wp_die();
            }

            $encrypted_rental_coupon_code = encrypt_data($_POST['coupon_code'], get_option('rental_encryption_key'));
            $_COOKIE['rental_coupon_code'] = $encrypted_rental_coupon_code;
            setcookie('rental_coupon_code', $encrypted_rental_coupon_code, time() + (3600), "/", "", false, true); // 1 hour expiration

            wp_send_json(['message' => __('Coupon code applied successfully.', 'rentopian-sync')], 200);
            wp_die();
        } catch (RentalException $e) {

            unset($_COOKIE['rental_coupon_code']);
            setcookie('rental_coupon_code', false, time() - (31556952), "/", "", false, false);

            wp_send_json(['message' => $e->getMessage()], $e->getStatusCode());
            wp_die();
        }
    }
    wp_send_json(['message' => __('Error', 'rentopian-sync')], 400);
    wp_die();
}

// function ajax which is responsible for removing coupon
function wp_ajax_rental_remove_coupon() {
    if (isset($_POST['coupon_code']) && !empty($_POST['coupon_code'])) {
        $coupon_code = sanitize_text_field($_POST['coupon_code']);

        WC()->cart->remove_coupon($coupon_code);
        do_action( 'woocommerce_removed_coupon', $coupon_code );
        WC()->cart->calculate_totals();

        setcookie('rental_coupon_code', '', time() - 3600, '/');

        wp_send_json([
            'message' => __('Coupon code removed successfully.', 'rentopian-sync'),
        ], 200);
    }

    wp_send_json(['message' => __('Invalid request.', 'rentopian-sync')], 400);
}

// function ajax which is responsible for getting multipliers
function wp_ajax_rental_get_multiplier() {

    if (isset($_GET['product_id']) && isset($_GET['variant_id'])) {

        $product_id = intval($_GET['product_id']);
        $variant_id = intval($_GET['variant_id']);
        $days = null;

        if (
            !rental_prices_are_hidden('catalog') &&
            !get_post_meta($product_id, '_rental_is_sale', true)
        ) {

            // get price multiplier items of the current variant 
            $price_multipliers = get_price_multiplier_items_by_post_id($variant_id);
            if (isset($price_multipliers) && $price_multipliers) {
                $price_multiplier_items = isset($price_multipliers['price_multiplier_items']) && $price_multipliers['price_multiplier_items'] ? $price_multipliers['price_multiplier_items'] : [];
                if (!empty($price_multiplier_items)) {
                    $days = rental_get_daily_prices($price_multiplier_items);
                }
            }
            
        }

        wp_send_json(['days' => $days], 200);
        wp_die();
    }

    wp_send_json(['message' => __('Error', 'rentopian-sync')], 400);
    wp_die();
}

/**
 * Whether submitting the date form on a product page also adds that product
 * to the cart.
 */
function rental_is_auto_add_to_cart_enabled() {

    return (int) get_option('rental_auto_add_to_cart_on_date_selection', 1) === 1;
}

/**
 * Whether a product needs a customer choice before it can be added to the cart.
 *
 * Sets, products carrying add-ons and variable products all expose a selection
 * the date form cannot make on the customer's behalf.
 */
function rental_product_requires_selection($product_id) {

    $product_id = (int) $product_id;
    if ( !$product_id) {
        return true;
    }

    if (get_post_meta($product_id, '_rental_is_set', true)) {
        return true;
    }

    $add_ons = get_post_meta($product_id, '_rental_add_ons', true);
    if ( !empty($add_ons)) {
        return true;
    }

    $product = wc_get_product($product_id);
    if ( !$product || $product->has_child()) {
        return true;
    }

    return false;
}

/**
 * Whether the product already has its own line in the cart.
 *
 * Add-on children are skipped: they belong to their parent line and say
 * nothing about the product having been added on its own.
 */
function rental_is_product_in_cart($product_id) {

    if ( !function_exists('WC') || !WC()->cart) {
        return false;
    }

    foreach (WC()->cart->get_cart() as $cart_item) {
        if ( !empty($cart_item['rental_add_on_of'])) {
            continue;
        }
        if ((int) $cart_item['product_id'] === (int) $product_id) {
            return true;
        }
    }

    return false;
}

/**
 * Whether the date form may add this product to the cart on its own.
 *
 * A product already in the cart is skipped so that resubmitting the dates
 * does not keep raising its quantity.
 */
function rental_can_auto_add_to_cart($product_id) {

    return rental_is_auto_add_to_cart_enabled()
        && !rental_product_requires_selection($product_id)
        && !rental_is_product_in_cart($product_id);
}

// function ajax which is responsible for setting rental dates
function wp_ajax_rental_dates() {
    if (isset($_POST['start_date'])) {

        unset_delivery_times_data();
        
        $opt_default_start_time = get_option('rental_default_start_time', '09:00 AM');
        $opt_default_end_time = get_option('rental_default_end_time', '05:00 PM');
        $opt_hide_time_pickers = get_option('rental_hide_time_pickers');

        $opt_hide_zip = get_option('rental_hide_zip');
        $opt_show_location = get_option('rental_show_location');
        
        // handling the ZIP code
        $zip = false;
        if ($opt_hide_zip) {
            $zip = true;
        } else {
            $zip = isset($_POST['zip']) && $_POST['zip'] ? $_POST['zip'] : false;
        }

        // handeling delivery input address
        $address = '';
        if ($opt_show_location) {
            if (isset($_POST['address']) && $_POST['address']) {
                $address = trim($_POST['address']);
            }
        }

        // handeling start/end date
        $rental_end_date = isset($_POST['end_date']) && $_POST['end_date'] ? $_POST['end_date'] : '';
        $rental_start_date = $_POST['start_date'] ? $_POST['start_date'] : '';

        $error = rental_validate_dates_form_and_get_products_data_init($opt_hide_time_pickers, $opt_hide_zip, $opt_show_location, $rental_start_date, $rental_end_date, $address, $zip); 
        if (is_null($error)) {

            $_COOKIE['rental_form_filled'] = ' rntp-form-filled';
            setcookie('rental_form_filled', ' rntp-form-filled', time() + RENTOPIAN_DATE_EXPIRE_TIME, "/", "", false);

            // From here the customer's pages are gated on their own dates, so
            // they must stop being answered from a shared page cache
            Rental_Page_Cache_Guard::ensure_bypass_cookie();

            // set start date time as start delivery time and end date time as pickup start time
            rental_set_selected_delivery_pickup_start_end_times($rental_start_date, $rental_end_date, $opt_default_start_time, $opt_default_end_time, $opt_hide_time_pickers);

            $add_to_cart_msg_success = $add_to_cart_msg_failure = $current_url ="";

            if (isset($_POST['pid']) && $_POST['pid'] && isset($_POST['url'])) {

                $current_url = $_POST['url'].'&added_to_cart=0';
                $product_id = intval($_POST['pid']);

                if (rental_can_auto_add_to_cart($product_id)) {

                    $cart = WC()->cart;
                    $validate = rental_validate_cart_item($product_id, 0, 1, null, false, true);

                    // check product options validity before add to cart
                    $options_check = rental_check_selected_options_of_product($product_id);

                    if ($validate == 'success' && $options_check) {

                        $current_url = $_POST['url'].'&added_to_cart=1';
                        $cart->add_to_cart($product_id, 1, 0);
                        calculate_cart_totals($cart);
                        $add_to_cart_msg_success = wc_add_to_cart_message([$product_id => 1], true, true);

                    } else {

                        if (!$options_check) {
                             $custom_cart_label = get_option('rental_cart_button_text') ? lcfirst(get_option('rental_cart_button_text')) : 'cart';
                            $add_to_cart_msg_failure = 'Please review the options before adding to '.$custom_cart_label.'.';
                        } else {
                            $add_to_cart_msg_failure = $validate;
                        }

                    }
                }

            }

            // $rental_unavailable_items_opt = get_option('rental_unavailable_items');

            wp_send_json([
                "current_url" => $current_url,
                "shop_page_url" => get_permalink(wc_get_page_id('shop')),
                // "data" => $rental_unavailable_items_opt && is_array($rental_unavailable_items_opt) ? $rental_unavailable_items_opt : '',
                "data" => '',
                "add_to_cart_msg_success" => $add_to_cart_msg_success,
                "add_to_cart_msg_failure" => $add_to_cart_msg_failure,
                "message" => 'success'
            ], 200);
            wp_die();

        } else {

            wp_send_json(['message' => $error], 400);
            wp_die();
        }
    }
    wp_send_json(['message' => __('Error', 'rentopian-sync')], 400);
    wp_die();
}

function wp_ajax_rental_date_form_api() {
    if (isset($_POST['start_date'])) {

        unset_delivery_times_data();
        
        $opt_default_start_time = get_option('rental_default_start_time', '09:00 AM');
        $opt_default_end_time = get_option('rental_default_end_time', '05:00 PM');
        $opt_hide_time_pickers = get_option('rental_hide_time_pickers');

        $opt_hide_zip = get_option('rental_hide_zip');
        $opt_show_location = get_option('rental_show_location');
        
        // handling zip code
        $zip = false;
        if ($opt_hide_zip) {
            $zip = true;
        } else {
            $zip = isset($_POST['zip']) && $_POST['zip'] ? $_POST['zip'] : false;
        }

        // handling delivery input address
        $address = '';
        if ($opt_show_location) {
            if (isset($_POST['address']) && $_POST['address']) {
                $address = trim($_POST['address']);
            }
        }

        // handling start/end date
        $rental_end_date = isset($_POST['end_date']) && $_POST['end_date'] ? $_POST['end_date'] : '';
        $rental_start_date = $_POST['start_date'] ? $_POST['start_date'] : '';
        
        $error = rental_validate_dates_form_and_get_products_data_init($opt_hide_time_pickers, $opt_hide_zip, $opt_show_location, $rental_start_date, $rental_end_date, $address, $zip);
        if (!is_null($error)) {
            // submit button class
            if(isset($_COOKIE['rental_form_filled'])) {
                unset($_COOKIE['rental_form_filled']);
                setcookie('rental_form_filled', '', time() - (31556952), "/", "", false);
            }

            wp_send_json(['message_title'=> __('Please check the following:', 'rentopian-sync'), 'message_txt' => $error, 'type' => 'error'], 400);
            wp_die();
        }


        // set start date time as start delivery time and end date time as pickup start time
        rental_set_selected_delivery_pickup_start_end_times($rental_start_date, $rental_end_date, $opt_default_start_time, $opt_default_end_time, $opt_hide_time_pickers);

    } else {
        wp_send_json(['message_title'=> __('Please check the following:', 'rentopian-sync'), 'message_txt' => 'Start date is required!', 'type' => 'error'], 400);
        wp_die();
    }

    // submit button class
    $_COOKIE['rental_form_filled'] = ' rntp-form-filled';
    setcookie('rental_form_filled', ' rntp-form-filled', time() + RENTOPIAN_DATE_EXPIRE_TIME, "/", "", false);

    // From here the customer's pages are gated on their own dates, so they must
    // stop being answered from a shared page cache
    Rental_Page_Cache_Guard::ensure_bypass_cookie();

    $add_to_cart_msg_success = $add_to_cart_msg_failure = $current_url ="";
    if (isset($_POST['pid']) && $_POST['pid'] && isset($_POST['url'])) {
        $current_url = $_POST['url'].'&added_to_cart=0';
        $product_id = intval($_POST['pid']);

        if (rental_can_auto_add_to_cart($product_id)) {
            $cart = WC()->cart;
            $validate = rental_validate_cart_item($product_id, 0, 1, null, false, true);
            $options_check = rental_check_selected_options_of_product($product_id);
            if ($validate == 'success' && $options_check) {
                // $cart->add_to_cart($product_id, 1, $product_variant_id);
                $current_url = $_POST['url'].'&added_to_cart=1';
                $cart->add_to_cart($product_id, 1, 0);
                calculate_cart_totals($cart);
                // $cart->calculate_totals();
                $add_to_cart_msg_success = wc_add_to_cart_message([$product_id => 1], true, true);
            } else {
                if (!$options_check) {
                    $add_to_cart_msg_failure = 'Please review the options before adding to cart.';
                } else {
                    $add_to_cart_msg_failure = $validate;
                }
            }
        }
    }

    // $rental_unavailable_items_opt = get_option('rental_unavailable_items');

    $response = [
        "current_url" => $current_url,
        "shop_page_url" => get_permalink(wc_get_page_id('shop')),
        // "data" => $rental_unavailable_items_opt && is_array($rental_unavailable_items_opt) ? $rental_unavailable_items_opt : '',
        "data" => '',
        "add_to_cart_msg_success" => $add_to_cart_msg_success,
        "add_to_cart_msg_failure" => $add_to_cart_msg_failure,
        "message" => 'success'
    ];

    wp_send_json($response, 200);
    wp_die();
}

function wp_ajax_rental_get_date_form() {
    nocache_headers();
    ob_start();
    include_once('components/rental_date_form.php');
    $html = ob_get_clean();
    echo $html;
    wp_die();
}

function wp_ajax_rental_collect_hourly_product_data() {
    if (isset($_POST['product_id']) && isset($_POST['type'])) {

        if ($_POST['type'] == "rental_by_interval") {
            $session_name = $_POST['product_id']."_rental_by_interval";
        } else if ($_POST['type'] == "rental_by_slot") {
            $session_name = $_POST['product_id']."_rental_by_slot";
        }

        $rental_hourly_opt = get_option($session_name);
        if ($rental_hourly_opt !== false) {
            // update
            
            $session_name = $session_name."_updated";

            $_POST['active'] = 0;
            update_option($session_name, $_POST);

        } else {
            // create
            $_POST['active'] = 0;
            update_option($session_name, $_POST);
        }

        wp_send_json("success!");
        wp_die();
    }
}


function wp_ajax_rental_get_hourly_product_variation_id() {
    if (isset($_POST['var_id'])) {
        $product_id = intval($_POST['var_id']);

        $rental_by_interval_product = (bool) get_post_meta($product_id, '_rental_by_interval', true);
        $rental_by_slot_product = (bool) get_post_meta($product_id, '_rental_by_slot', true);

        // getting main division working hours
        $divisions = get_option("rental_divisions");
        $main_division_working_days = [];
        $week_days = [1,2,3,4,5,6,7];
        // can change it to work with product division work day-hours
        foreach ($divisions as $division) {
            if (get_option('rental_select_division') && isset($_COOKIE['rental_division_id']) && !empty($_COOKIE['rental_division_id'])) {
                if ( is_array($divisions) && count($divisions) > 1 && ($_COOKIE['rental_division_id'] == $division->id)) {
                    // getting selected division work hours
                    $main_division_working_days = $division->working_hours;
                }
            } else {
                // default : getting main division work hours
                if ($division->main_division) {
                    $main_division_working_days = $division->working_hours;
                }
            }
        }

        $main_division_working_days_diff=[];
        if (!empty($main_division_working_days)) {
            foreach($main_division_working_days as $work_day) {
                if (in_array($work_day->week_day, $week_days)) {
                    $main_division_working_days_diff[] = $work_day->week_day;
                }
            }
        }
        // $disabled_working_days = !empty($main_division_working_days_diff) ? json_encode(array_values(array_diff($week_days,$main_division_working_days_diff))) : json_encode([]);
        $disabled_working_days = !empty($main_division_working_days_diff) ? array_values(array_diff($week_days,$main_division_working_days_diff)) : [];

        // getting the hourly data
        $hourly_data = [];
        if ($rental_by_interval_product) {
            $hourly_data['rental_interval'] = get_post_meta($product_id, '_rental_interval', true);
            $hourly_data['rental_interval_steps'] = get_post_meta($product_id, '_rental_interval_steps', true);
            $hourly_data['rental_interval_price'] = get_post_meta($product_id, '_rental_interval_price', true);
            $hourly_data['rental_interval_sale_price'] = get_post_meta($product_id, '_rental_interval_sale_price', true);
            $hourly_data['rental_by_interval'] = true;
            $hourly_data['rental_by_slot'] = false;

        } else if ($rental_by_slot_product) {
            $hourly_data['rental_additional_hourly_price'] = get_post_meta($product_id, '_rental_additional_hourly_price', true);
            $hourly_data['rental_time_slots'] = json_decode(get_post_meta($product_id, '_rental_time_slots', true));
            $hourly_data['rental_by_interval'] = false;
            $hourly_data['rental_by_slot'] = true;

        }

        wp_send_json([
            'disabled_week_days' => $disabled_working_days
            , 'work_days' => $main_division_working_days
            , 'hourly' => $hourly_data
            , 'product_id' => $product_id
        ]);
        wp_die();
    }
}


function rental_check_selected_options() {
    $check = true;
    $once_per_order_option_ids = [];
    $once_per_order_option_ids_of_sets = [];
    foreach (WC()->cart->get_cart() as $cart_item_key => $cart_item) {
        $product_id = $cart_item['variation_id'] ? $cart_item['variation_id'] : $cart_item['product_id'];
        $options = [];
        $is_set = false;
        if (get_post_meta($product_id, '_rental_is_set', true)) {
            $options = get_set_options($product_id);
            $is_set = true;
        } else {
            if (!isset($cart_item['rental_set_id'])) {
                // not a set's item
                $options = get_product_options($product_id);    
            }
        }
        
        if (!empty($options)) {
            foreach($options as $option) {
                // collecting once per order option ids related to cart products
                if ($option["once_per_order"] == 1) {
                    if ($is_set) {
                        $once_per_order_option_ids_of_sets[] = $option["id"];
                    } else {
                        $once_per_order_option_ids[] = $option["id"];
                    }
                    
                }
            }

            // Each line answers for itself. Looking the answer up by product id
            // let two lines of one product vouch for each other, so an
            // unanswered line could pass on the strength of a different one.
            if (class_exists('Rental_Options_Selection')
                && Rental_Options_Selection::unanswered_for_cart_line($cart_item_key, $cart_item)
            ) {
                $check = false;
            }
        }
    }

    if ($check) {
        if ($once_per_order_option_ids) {
            // check order options of products
            foreach(array_unique($once_per_order_option_ids) as $option_id) {

                // $rental_order_selected_options_opt = get_option("rental_order_selected_options", []);
                $rental_order_selected_options_opt = get_rental_session_data('rental_order_selected_options', []);
                
                if (
                    !isset($rental_order_selected_options_opt[$option_id])
                    || $rental_order_selected_options_opt[$option_id]["selected_value_id"] == -1
                ) {
                    $check = false;
                    break;
                }
            }
        }

        if ($once_per_order_option_ids_of_sets) {
            // check order options of sets
            foreach(array_unique($once_per_order_option_ids_of_sets) as $option_id) {

                $rental_order_selected_options_of_sets = get_rental_session_data('rental_order_selected_options_of_sets', []);
                
                if (
                    !isset($rental_order_selected_options_of_sets[$option_id])
                    || $rental_order_selected_options_of_sets[$option_id]["selected_value_id"] == -1
                ) {
                    $check = false;
                    break;
                }
            }
        }
    }
    return ["check" => $check, "url" => wc_get_cart_url()."?rntp_opt_err"];
} 

function wp_ajax_rental_get_all_options_of_cart_products() {
    $options_all = [];

    // CRITICAL: Get the most up-to-date cart data
    // The cart_contents might be stale if updated in another AJAX call
    // So we also check the WC session directly
    $cart_session_data = [];
    if (WC()->session) {
        $cart_session_data = WC()->session->get('cart', []);
    }
    
    foreach (WC()->cart->get_cart() as $cart_item_key => $cart_item) {
        $product_id = $cart_item['variation_id'] ? $cart_item['variation_id'] : $cart_item['product_id'];
        $options = [];
        $is_set = get_post_meta($product_id, '_rental_is_set', true);
        
        if ($is_set) {
            $options = get_set_options($product_id);
        } else {
            if (!isset($cart_item['rental_set_id'])) {
                // not a set's item
                $options = get_product_options($product_id);
            }
        }
        
        if (!empty($options)) {
            // =====================================================================
            // PRIMARY: Get selected options from cart item data
            // Check both cart_contents AND session data for most up-to-date values
            // CRITICAL: Use the correct key based on whether this is a set or regular product
            // =====================================================================
            $cart_item_options = [];
            
            // Determine the correct option key based on product type (set vs regular)
            $options_key = $is_set ? 'rental_selected_set_options' : 'rental_selected_options';
            
            // First try cart_contents (in-memory)
            if (isset($cart_item[$options_key]) && !empty($cart_item[$options_key])) {
                $cart_item_options = $cart_item[$options_key];
            }
            
            // Then check session data (might be more recent if updated in another AJAX call)
            if (isset($cart_session_data[$cart_item_key][$options_key]) && !empty($cart_session_data[$cart_item_key][$options_key])) {
                $session_cart_options = $cart_session_data[$cart_item_key][$options_key];
                // Use session data if it has more or different options
                // This handles the case where cart_contents is stale
                if (!empty($session_cart_options)) {
                    // Merge session options on top of cart_contents options
                    // Session is more authoritative as it's saved immediately
                    foreach ($session_cart_options as $opt_id => $opt_data) {
                        $cart_item_options[$opt_id] = $opt_data;
                    }
                }
            }
            
            // Handle corrupted data (numerically indexed instead of option_id keyed)
            if (!empty($cart_item_options)) {
                $first_key = array_key_first($cart_item_options);
                if (is_int($first_key) && $first_key < 10) {
                    // Data is corrupted - ignore cart item data, use session only
                    $cart_item_options = [];
                }
            }
            
            foreach($options as $option) {
                array_unshift($option["option_values"], [
                    "id" => -1,
                    "is_default" => 0,
                    "option_id" => 0,
                    "price" => -1,
                    "title" => "Please select an option",
                ]);

                $option_id = $option["id"];
                $option_id_str = strval($option_id);
                $selected_value_id = null;
                $is_selected = 0;
                
                // Check cart item data first (PRIMARY)
                // Check both integer and string keys since PHP/JSON can change key types
                $cart_option_data = null;
                if (isset($cart_item_options[$option_id])) {
                    $cart_option_data = $cart_item_options[$option_id];
                } elseif (isset($cart_item_options[$option_id_str])) {
                    $cart_option_data = $cart_item_options[$option_id_str];
                }
                
                if ($cart_option_data !== null) {
                    $value_id = isset($cart_option_data['value_id']) 
                        ? $cart_option_data['value_id']
                        : (isset($cart_option_data['selected_value_id']) 
                            ? $cart_option_data['selected_value_id'] 
                            : null);
                    
                    if ($value_id !== null && $value_id != -1) {
                        $selected_value_id = intval($value_id);
                        $is_selected = 1;
                    }
                }
                
                // A row answers only from its own line. When the line holds
                // nothing for an option, the option's own default is the answer.
                if ($selected_value_id === null && class_exists('Rental_Options_Defaults')) {
                    $default_value = Rental_Options_Defaults::resolve($option);

                    if (!empty($default_value['id']) && $default_value['id'] != Rental_Options_Defaults::PLACEHOLDER_VALUE_ID) {
                        $selected_value_id = intval($default_value['id']);
                        $is_selected = 1;
                    }
                }

                $options_all[] = [
                    "key" => $cart_item["key"],
                    "product_id" => $product_id,
                    "option_id" => $option_id,
                    "option_title" => $option["title"],
                    "option_values" => $option["option_values"],
                    "selected_value_id" => $selected_value_id !== null ? $selected_value_id : 0,
                    "is_selected" => $is_selected,
                    "once_per_order" => $option["once_per_order"] == 1 ? 1 : 0,
                    "currency" => get_woocommerce_currency_symbol()
                ];
            }
        }
    }
    
    wp_send_json($options_all);
    wp_die();
}

// must be modified
function wp_ajax_rental_get_all_options_of_product() {
    if (isset($_POST["product_id"])) {

        $options_all = [];
        $product_id = intval($_POST["product_id"]);
        $options = [];
        $is_set = false;

        if (isset($_POST["is_set"]) && intval($_POST["is_set"]) === 1) {
            $is_set = true;
            $options = get_set_options($product_id);
        } else {
            $options = get_product_options($product_id);
        }

        if (function_exists('rental_options_trace')) {
            rental_options_trace('get_all_options_of_product', array('pid'=>$product_id,'is_set'=>$is_set?1:0,'opt_count'=>count((array)$options)));
        }

        if ($options) {

            // One resolver decides what is selected: a rejected submission
            // waiting to be re-prefilled, then the cart line, then the session
            // stores, then the option default. Whatever it decides is written
            // back to BOTH session stores before the markup is sent, so the
            // add-to-cart gate can never disagree with what the page renders.
            $pending = Rental_Options_Prefill::pending($product_id, true);
            $override = null;
            if (!empty($pending)) {
                $override = [];
                foreach ($pending as $opt_id => $entry) {
                    $override[(int) $opt_id] = (int) $entry['value_id'];
                }
            }

            $options = Rental_Options_Repository::get($product_id, $is_set);
            $resolved = Rental_Options_Selection::resolve($product_id, $is_set, null, $override);

            Rental_Options_Selection::persist($product_id, $is_set, $resolved);

            if (function_exists('rental_options_trace')) {
                $rntp_sel = array();
                foreach ($resolved as $oid => $entry) {
                    $rntp_sel[$oid] = $entry['value_id'] . ':' . $entry['source'];
                }
                rental_options_trace('get_all_options_resolved', array(
                    'pid' => $product_id,
                    'is_set' => $is_set ? 1 : 0,
                    'resolved' => $rntp_sel,
                    'reprefill' => empty($pending) ? 0 : 1,
                ));
            }

            foreach ($options as $option) {

                $option_id = (int) $option['id'];
                $selected  = isset($resolved[$option_id]) ? (int) $resolved[$option_id]['value_id'] : 0;

                array_unshift($option["option_values"], [
                    "id" => -1,
                    "is_default" => 0,
                    "option_id" => 0,
                    "price" => -1,
                    "title" => "Please select an option",
                ]);

                $options_all[] = [
                    "product_id" => $product_id,
                    "option_id" => $option_id,
                    "option_title" => $option["title"],
                    "option_values" => $option["option_values"],
                    "selected_value_id" => $selected,
                    "is_selected" => $selected ? 1 : 0,
                    // The customer must pick before the item can be added.
                    // Drives the client-side gate so the button and the server
                    // agree about which options are still open.
                    "requires_choice" => (!$selected && empty($option['once_per_order'])) ? 1 : 0,
                    "once_per_order" => !empty($option["once_per_order"]) ? 1 : 0,
                    "currency" => get_woocommerce_currency_symbol(),
                ];
            }
        }
        wp_send_json($options_all);

    }
    wp_die();
}

/**
 * Get selected options for a product/set for validation.
 * Priority: cart item data (source of truth) then session (fallback).
 * Returns array option_id => [ 'selected_value_id' => int, 'price' => ..., ... ].
 */
function rental_get_selected_options_for_check($product_id, $is_set) {
    $product_id = (int) $product_id;
    $options_key = $is_set ? 'rental_selected_set_options' : 'rental_selected_options';
    $session_name = $product_id . ($is_set ? '_selected_options_of_set' : '_selected_options');

    // Cart first (source of truth in the system flow)
    if (function_exists('WC') && WC()->cart) {
        foreach (WC()->cart->get_cart() as $cart_item) {
            $pid = !empty($cart_item['variation_id']) ? (int) $cart_item['variation_id'] : (int) $cart_item['product_id'];
            if ($pid !== $product_id) {
                continue;
            }
            if (!empty($cart_item[$options_key]) && is_array($cart_item[$options_key])) {
                $out = [];
                foreach ($cart_item[$options_key] as $opt_id => $opt_data) {
                    $opt_data = is_array($opt_data) ? $opt_data : [];
                    $value_id = isset($opt_data['selected_value_id']) ? $opt_data['selected_value_id'] : (isset($opt_data['value_id']) ? $opt_data['value_id'] : null);
                    if ($value_id !== null) {
                        $out[(int) $opt_id] = [
                            'selected_value_id' => (int) $value_id,
                            'value_id' => (int) $value_id,
                            'price' => isset($opt_data['price']) ? $opt_data['price'] : 0,
                        ];
                    }
                }
                if (!empty($out)) {
                    return $out;
                }
            }
            break;
        }
    }

    // Fallback: session
    $item_selected_option = get_rental_session_data($session_name, []);
    if (!is_array($item_selected_option)) {
        return [];
    }
    $out = [];
    foreach ($item_selected_option as $opt_id => $opt_data) {
        $opt_data = is_array($opt_data) ? $opt_data : [];
        $value_id = isset($opt_data['selected_value_id']) ? $opt_data['selected_value_id'] : (isset($opt_data['value_id']) ? $opt_data['value_id'] : null);
        if ($value_id !== null) {
            $out[(int) $opt_id] = [
                'selected_value_id' => (int) $value_id,
                'value_id' => (int) $value_id,
                'price' => isset($opt_data['price']) ? $opt_data['price'] : 0,
            ];
        }
    }
    return $out;
}

/**
 * Check if required product/set options are selected. Used for add-to-cart and AJAX.
 * Uses cart item data first, then session (same as rest of the system).
 *
 * @param int      $product_id Product or set ID.
 * @param bool|null $is_set     True for set, false for product. Null = auto-detect from post meta.
 * @return bool True if valid (no options, once-per-order, or all required options selected).
 */
function rental_check_selected_options_of_product($product_id, $is_set = null) {
    $product_id = (int) $product_id;
    if (!$product_id) {
        return true;
    }

    // One predicate for "is this option chosen" across the whole plugin.
    // The previous implementation short-circuited the whole loop on the first
    // once-per-order option (`break`, not `continue`) and knew nothing about
    // a sole value being its own default, so it and the add-to-cart gate could
    // answer differently for the same product.
    if (class_exists('Rental_Options_Selection')) {
        if ($is_set === null) {
            $is_set = Rental_Options_Repository::is_set($product_id);
        }

        return array() === Rental_Options_Selection::unanswered($product_id, (bool) $is_set);
    }

    if ($is_set === null) {
        $is_set = (bool) get_post_meta($product_id, '_rental_is_set', true);
    }

    $options = $is_set ? get_set_options($product_id) : get_product_options($product_id);
    if (empty($options) || !is_array($options)) {
        return true;
    }

    $item_selected_option = rental_get_selected_options_for_check($product_id, $is_set);
    $check = true;

    foreach ($options as $option) {
        if (!empty($option['once_per_order'])) {
            continue;
        }
        $opt_id = isset($option['id']) ? (int) $option['id'] : 0;
        $sel = isset($item_selected_option[$opt_id]) ? $item_selected_option[$opt_id] : [];
        $value_id = isset($sel['selected_value_id']) ? $sel['selected_value_id'] : (isset($sel['value_id']) ? $sel['value_id'] : null);

        // If session/cart has no explicit pick for this option but the
        // option definition carries an `is_default` value, treat the
        // default as implicitly selected.
        if ($value_id === null || (int) $value_id === -1) {
            $has_default = false;
            if (!empty($option['option_values']) && is_array($option['option_values'])) {
                foreach ($option['option_values'] as $opt_val) {
                    if (!is_array($opt_val)) {
                        continue;
                    }
                    $is_default = (!empty($opt_val['is_default']))
                        || (!empty($opt_val['default']));
                    if ($is_default) {
                        $has_default = true;
                        break;
                    }
                }
            }
            if (!$has_default) {
                $check = false;
                break;
            }
        }
    }

    return $check;
}

function wp_ajax_rental_check_selected_options_of_product() {
    $check = true;
    if (isset($_POST['product_id'])) {
        $product_id = intval($_POST['product_id']);
        $is_set = isset($_POST['is_set']) && (int) $_POST['is_set'] === 1;
        $check = rental_check_selected_options_of_product($product_id, $is_set);
    }
    wp_send_json(['check' => $check]);
    wp_die();
}

function wp_ajax_rental_check_selected_options() {
    $check = true;
    $once_per_order_option_ids = [];
    $once_per_order_option_ids_of_sets = [];

    foreach (WC()->cart->get_cart() as $cart_item_key => $cart_item) {

        $product_id = $cart_item['variation_id'] ? $cart_item['variation_id'] : $cart_item['product_id'];

        $options = [];
        $is_set = false;
        if (get_post_meta($product_id, '_rental_is_set', true)) {
            $options = get_set_options($product_id);
            $is_set = true;
        } else {
            if (!isset($cart_item['rental_set_id'])) {
                // not a set's item
                $options = get_product_options($product_id);
            }
        }

        if (!empty($options)) {
            foreach($options as $option) {
                // collecting once per order option ids related to cart products
                if ($option["once_per_order"] == 1) {
                    if ($is_set) {
                        $once_per_order_option_ids_of_sets[] = $option["id"];
                    } else {
                        $once_per_order_option_ids[] = $option["id"];
                    }

                }
            }

            // Each line answers for itself. Looking the answer up by product id
            // let two lines of one product vouch for each other, so an
            // unanswered line could pass on the strength of a different one.
            if (class_exists('Rental_Options_Selection')
                && Rental_Options_Selection::unanswered_for_cart_line($cart_item_key, $cart_item)
            ) {
                $check = false;
            }
        }
    }

    if ($check) {
        if ($once_per_order_option_ids) {

            // $rental_order_selected_options_opt = get_option("rental_order_selected_options", []);
            $rental_order_selected_options_opt = get_rental_session_data('rental_order_selected_options', []);

            // check order options
            foreach(array_unique($once_per_order_option_ids) as $key => $option_id) {

                if (
                    !isset($rental_order_selected_options_opt[$option_id])
                    || $rental_order_selected_options_opt[$option_id]["selected_value_id"] == -1
                ) {
                    $check = false;
                    break;
                }
            }
        }

        if ($once_per_order_option_ids_of_sets) {

            // $rental_order_selected_options_of_sets = get_option('rental_order_selected_options_of_sets', []);
            $rental_order_selected_options_of_sets = get_rental_session_data('rental_order_selected_options_of_sets', []);
            
            // check order options of sets
            foreach(array_unique($once_per_order_option_ids_of_sets) as $option_id) {

                if (
                    !isset($rental_order_selected_options_of_sets[$option_id])
                    || $rental_order_selected_options_of_sets[$option_id]["selected_value_id"] == -1
                ) {
                    $check = false;
                    break;
                }
            }
        }
    }
    wp_send_json(["check" => $check, "url" => wc_get_checkout_url(), "url_cart" => wc_get_cart_url()."?rntp_opt_err"]);
    wp_die();
}   

function calculate_cart_totals($cart_instance = '') {

    if (get_option('rental_dates_on_checkout', 0) == 1 && get_option('rental_allow_overbook', 1) == 1) {
        // if we have allow overbook and rental dates on checkout page
        rental_dates_on_checkout_page_option_effect();
    }

    // daily products (rental by day)
    if (isset($_COOKIE['rental_start_date']) && $_COOKIE['rental_start_date'] && isset($_COOKIE['rental_zip']) && $_COOKIE['rental_zip']) {
        $product_options_valuables = [];
        $subtotal = 0;
        $subtotalOrderOption = 0;
        $order_selected_options_already_calculated = [];
        $order_selected_options_of_sets_already_calculated = [];
        $_cart = WC()->cart;

        $rental_order_selected_options_opt = get_rental_session_data('rental_order_selected_options', []);
        $rental_order_selected_options_of_sets = get_rental_session_data('rental_order_selected_options_of_sets', []);
        $rental_product_price_total_opt = get_rental_session_data('rental_product_price_total', []);

        $options_cache = [];
        $options_session_cache = [];

        foreach ($_cart->get_cart() as $cart_item_key => $cart_item) {


            $price = format_value_to_fixed_precision(rental_calculate_cart_item_price($cart_item),2);

            $product_id = $cart_item['variation_id'] ? $cart_item['variation_id'] : $cart_item['product_id'];

            // set item related (child)
            if (
                isset($cart_item['rental_add_on_of']) 
                && isset($cart_item['set_id']) 
                && $cart_item['set_id']
                && $cart_item['rental_add_on_of']
                // && $cart_item['variation_id']
            ) {

                $set_item_optional_item_custom_price = null;
                $set_item_optional_item_calculate_one_day_price = false;
                $set_item_optional_item_get_regular_price = true;

                if ($set_ids_with_optional_items = get_set_ids_with_optional_items()) {

                    foreach($set_ids_with_optional_items as $set_with_optional_items_id => $selected_product_key_variant_or_product_value_array) {
            
                        if ($set_with_optional_items_id == $cart_item['set_id']) {

                            foreach($selected_product_key_variant_or_product_value_array as $pid => $selected_variant_or_product_id) {

                                if ($pid == $cart_item["product_id"]) {

                                    if ($cart_item['variation_id']) {

                                        if ($selected_variant_or_product_id == $cart_item['variation_id']) {

                                            $price = rental_calculate_rental_item_price($selected_variant_or_product_id, $set_item_optional_item_custom_price, $set_item_optional_item_calculate_one_day_price, $set_item_optional_item_get_regular_price);
                                            break;
                                        }

                                    } else {

                                        if ($selected_variant_or_product_id == $cart_item['product_id']) {
                                           
                                            $price = rental_calculate_rental_item_price($selected_variant_or_product_id, $set_item_optional_item_custom_price, $set_item_optional_item_calculate_one_day_price, $set_item_optional_item_get_regular_price);
                                            break;
                                        }
                                    }
                                }
                            }
                        }
                    }
                }

                // Single source of truth for set-child SUBTOTALS 
                $effective_unit = function_exists('rental_set_child_effective_unit_price')
                    ? rental_set_child_effective_unit_price($cart_item)
                    : null;

                if ($effective_unit !== null) {
                    $price = (float) $effective_unit * rental_get_days();
                }
            }


            
            $options = [];
            $options_session_name = "";
            $is_set = false;
            // $set_items = [];
            if (get_post_meta($product_id, '_rental_is_set', true)) {
                $is_set = true;

                if (!isset($options_cache[$product_id])) {
                    $options_cache[$product_id] = get_set_options($product_id);
                }
                $options = $options_cache[$product_id];
                $options_session_name = $product_id."_selected_options_of_set";
                

            } else {
                if (!isset($cart_item['rental_set_id'])) {
                    // not a set's item
                    if (!isset($options_cache[$product_id])) {
                        $options_cache[$product_id] = get_product_options($product_id);
                    }
                    $options = $options_cache[$product_id];
                    $options_session_name = $product_id."_selected_options";
                }
                
            }



            // calculate price with product or set options additional cost (if exists)
            if (!empty($options)) {

                // Prefer cart item rental_selected_options as source of truth (persists in WC session; works across themes and page reloads)
                // CRITICAL: For sets, options are stored under 'rental_selected_set_options', not 'rental_selected_options'
                $item_options_from_cart = [];
                if ($is_set && isset($cart_item['rental_selected_set_options']) && is_array($cart_item['rental_selected_set_options'])) {
                    $item_options_from_cart = $cart_item['rental_selected_set_options'];
                } elseif (isset($cart_item['rental_selected_options']) && is_array($cart_item['rental_selected_options'])) {
                    $item_options_from_cart = $cart_item['rental_selected_options'];
                }

                foreach($options as $option) {
                    $value_price = 0;

                    // check once per order options of products
                    if (isset($rental_order_selected_options_opt[$option["id"]]) && !$is_set) {
                        if (!isset($order_selected_options_already_calculated[$option["id"]])
                            && !isset($order_selected_options_already_calculated[$option["id"]]["price"])
                            && !isset($order_selected_options_already_calculated[$option["id"]]["selected_value_id"])
                        ) {

                            $subtotalOrderOption += $rental_order_selected_options_opt[$option["id"]]["price"] >= 0 ? $rental_order_selected_options_opt[$option["id"]]["price"] : 0;
                            $order_selected_options_already_calculated[$option["id"]] = [
                                "price" => $rental_order_selected_options_opt[$option["id"]]["price"],
                                "selected_value_id" => $rental_order_selected_options_opt[$option["id"]]["selected_value_id"],
                            ];
                        }
                        continue;
                    }

                    // check once per order options of sets
                    if (isset($rental_order_selected_options_of_sets[$option["id"]])) {
                        if (!isset($order_selected_options_of_sets_already_calculated[$option["id"]])
                            && !isset($order_selected_options_of_sets_already_calculated[$option["id"]]["price"])
                            && !isset($order_selected_options_of_sets_already_calculated[$option["id"]]["selected_value_id"])
                        ) {

                            $subtotalOrderOption += $rental_order_selected_options_of_sets[$option["id"]]["price"] >= 0 ? $rental_order_selected_options_of_sets[$option["id"]]["price"] : 0;

                            $order_selected_options_of_sets_already_calculated[$option["id"]] = [
                                "price" => $rental_order_selected_options_of_sets[$option["id"]]["price"],
                                "selected_value_id" => $rental_order_selected_options_of_sets[$option["id"]]["selected_value_id"],
                            ];
                        }
                        continue;
                    }

                    // Per-item option price: cart item data first (source of truth), then session fallback
                    $opt_id = $option["id"];
                    if (!empty($item_options_from_cart) && isset($item_options_from_cart[$opt_id]) && is_array($item_options_from_cart[$opt_id])) {
                        
                        $sel = $item_options_from_cart[$opt_id];
                        $value_price = isset($sel['price']) ? floatval($sel['price']) : 0;
                        
                        $product_options_valuables[$product_id][$opt_id] = [
                            'value_id' => isset($sel['value_id']) ? $sel['value_id'] : (isset($sel['selected_value_id']) ? $sel['selected_value_id'] : 0),
                            'price' => $value_price,
                        ];

                    } else {
                        // The line holds nothing for this option, so the option's
                        // own default prices it.
                        foreach($option["option_values"] as $key => $value) {

                            if ($value['is_default'] == 1) {
                                $value_price = $value['price'];
                                $product_options_valuables[$product_id][$opt_id] = [
                                    'value_id' => $value["id"],
                                    'price' => $value_price,
                                ];
                            }
                        }
                    }

                    $price += $value_price; // add option price to rental price
                }
            }


            $rental_product_price_total_opt[$product_id] = $cart_item["quantity"] * $price;
            set_rental_session_data('rental_product_price_total', $rental_product_price_total_opt);

            $subtotal += ($cart_item["quantity"] * $price);

            // A cart line can carry a missing product object when its product
            // was deleted while the line existed. Price only what WooCommerce
            // can price; a fatal here takes down cart, checkout and mini-cart.
            if (isset($cart_item['data']) && $cart_item['data'] instanceof WC_Product) {
                $cart_item['data']->set_price(format_value_to_fixed_precision($price, 2));
            } elseif (class_exists('Rentopian_Set_Cart_Tracer', false)) {
                Rentopian_Set_Cart_Tracer::log('CART_LINE_NO_PRODUCT', [
                    'key'       => substr((string) $cart_item_key, 0, 8),
                    'product'   => (int) $cart_item['product_id'],
                    'variation' => (int) $cart_item['variation_id'],
                    'qty'       => (int) $cart_item['quantity'],
                    'parent'    => !empty($cart_item['rental_add_on_of'])
                        ? substr((string) $cart_item['rental_add_on_of'], 0, 8)
                        : '-',
                    'note'      => 'Cart line has no product object. Its price cannot be set.',
                ], 'critical');
            }
        }
        if ($subtotalOrderOption) {
            $subtotal = $subtotal + $subtotalOrderOption;
        }

        set_rental_session_data('rental_product_subtotal', format_value_to_fixed_precision($subtotal, 2));
        set_rental_session_data('rental_product_options_valuables', $product_options_valuables);
    }
}


function wp_ajax_rental_update_product_option() {
    if (
        isset($_POST["product_id"]) 
        && isset($_POST["option_id"]) 
        && isset($_POST["value_id"])
        && isset($_POST["price"])
    ) {
        $product_id = intval($_POST["product_id"]);
        $option_id = intval($_POST["option_id"]);
        $value_id = intval($_POST["value_id"]);
        $price = $_POST["price"];
        $is_set = isset($_POST["is_set"]) && intval($_POST["is_set"]) === 1 || get_post_meta($product_id, '_rental_is_set', true);
        
        $options_session_name = $product_id."_selected_options";
        if ($is_set) {
            $options_session_name = $product_id."_selected_options_of_set";
        }

        // =====================================================================
        // This endpoint serves the CART page, so it edits a committed line.
        // Resolving in the committed context fills the options the customer did
        // not touch from that line, and the write below puts the whole set back
        // on it — so the cart, mini-cart and checkout all move together.
        // =====================================================================
        $requested_cart_key = isset($_POST['cart_item_key'])
            ? sanitize_text_field(wp_unslash($_POST['cart_item_key']))
            : null;

        $item_selected_option = Rental_Options_Selection::resolve(
            $product_id,
            $is_set,
            $requested_cart_key,
            [$option_id => $value_id],
            Rental_Options_Selection::CONTEXT_COMMITTED
        );

        $rental_product_options_valuables = get_rental_session_data('rental_product_options_valuables', []);

        // =====================================================================
        // STEP 3: CRITICAL - Update WooCommerce cart item data directly if in cart
        // This ensures cart page, checkout, and order display all show correct options
        // =====================================================================
        $cart_update_result = null;
        if (function_exists('rental_update_single_cart_item_option')) {
            $cart_update_result = rental_update_single_cart_item_option($product_id, $option_id, $value_id, $price, $requested_cart_key);
        }

        rental_options_trace('single_update', array(
            'pid'            => $product_id,
            'option'         => $option_id,
            'value'          => $value_id,
            'price'          => $price,
            'is_set'         => $is_set ? 1 : 0,
            'session_key'    => $options_session_name,
            'in_cart'        => isset($cart_update_result['in_cart']) ? ( $cart_update_result['in_cart'] ? 1 : 0 ) : 'na',
            'cart_key'       => isset($cart_update_result['cart_item_key']) ? substr((string) $cart_update_result['cart_item_key'], 0, 6) : '-',
            'single_product' => isset($_POST['single_product']) ? 1 : 0,
        ));

        if (!isset($_POST["single_product"])) {
            // in cart product option update
            
            calculate_cart_totals('');

            $fees = rental_calculate_order_total();
            $security_deposit_updated = 0;
            $security_deposit_title = '';
            if (get_option('rental_allow_to_pay_security_deposit') && !empty($security_deposit = rental_get_security_deposit())) {
                $security_deposit_updated = $fees['security_deposit_fee'];
                $security_deposit_title = $security_deposit['title'];
            }
    
            $tax_value = $is_auto_tax = 0;
            $tax_title = '';
            // if (get_option('rental_combine_shipping_tax')) {
            $delivery_settings = rental_get_delivery_settings();
            if ($delivery_settings && $delivery_settings['enable_combined_shipping_tax_for_website']) {
                $tax_value = $fees['shipping_tax'];
            } 
                        
            if ($tax_value && !isset($fees['is_auto_tax'])) {
                $tax_value += $fees['rental_tax'] + $fees['sale_tax'];
                $tax_title = get_option('rental_tax_text')? : __('Tax', 'rentopian-sync');
            }
            

            $custom_tax_label = get_option('rental_tax_text');
            if (isset($fees['is_auto_tax']) && $fees['is_auto_tax'] == 1 && isset($fees['auto_tax'])) {

                $is_auto_tax = 1;
                $tax_value += $fees['auto_tax'];
                $tax_title = 'Auto '.$custom_tax_label;
                $tax_title = ($custom_tax_label == '') ? __('Auto Tax', 'rentopian-sync') : $custom_tax_label;

            }
            
    
            $damage_waiver_value = $damage_waiver_tax_value = 0;
            $damage_waiver_title = $damage_waiver_tax_title = '';
            if ($fees['damage_waiver']) {
                
                $damage_waiver_value = $fees['damage_waiver'];
                $damage_waiver_title = __('Damage Waiver', 'rentopian-sync');
                if ($fees['damage_waiver_tax']) {
                    $damage_waiver_tax_value = $fees['damage_waiver_tax'];
                    $damage_waiver_tax_title = __('Damage Waiver '.$custom_tax_label, 'rentopian-sync');
                }
               
            }
    
            if ( !empty($fees['order_fees'])) {
                foreach ($fees['order_fees'] as $key => $order_fee) {
                    $fees['order_fees'][$key]["amount"] = format_value_to_fixed_precision($order_fee['amount'], 2);
                }
            }
            
            $subtotal = get_rental_session_data('rental_product_subtotal', 0);
            // $subtotal = get_option('rental_product_subtotal' . get_new_unique_id(), 0);
            // $rental_product_price_total_opt = get_option('rental_product_price_total', []);
            $rental_product_price_total_opt = get_rental_session_data('rental_product_price_total', []);

            wp_send_json(
                [
                    'currency' => get_woocommerce_currency_symbol(), 
                    'subtotal' => format_value_to_fixed_precision($subtotal ,2),
                    'total' => format_value_to_fixed_precision( rental_calculate_order_total()['total'], 2),
                    'price_total' => format_value_to_fixed_precision($rental_product_price_total_opt[$product_id], 2),
                    'security_deposit_value' => format_value_to_fixed_precision($security_deposit_updated, 2),
                    'security_deposit_title' => $security_deposit_title,
                    'tax_value' => format_value_to_fixed_precision($tax_value, 2),
                    'tax_title' => $tax_title,
                    'is_auto_tax' => $is_auto_tax,
                    'damage_waiver_value' => format_value_to_fixed_precision($damage_waiver_value, 2),
                    'damage_waiver_title' => $damage_waiver_title,
                    'damage_waiver_tax_value' => format_value_to_fixed_precision($damage_waiver_tax_value, 2),
                    'damage_waiver_tax_title' => $damage_waiver_tax_title,
                    'auto_applied_fees' => $fees['order_fees'],
                    'rush_fee' => $fees['rush_fee'],
                ]
            );

        } else {

            // single product option update
            wp_send_json("success");
        }
        
    }
    wp_die();
}

function wp_ajax_rental_update_order_option() {
    if (
        isset($_POST["option_id"]) 
        && isset($_POST["value_id"])
        && isset($_POST["price"])
    ) {

        // $rental_order_selected_options = get_option("rental_order_selected_options", []);
        $rental_order_selected_options = get_rental_session_data('rental_order_selected_options', []);

        $option_id = intval($_POST["option_id"]);
        $value_id = intval($_POST["value_id"]);
        $price = $_POST["price"];

        $rental_order_selected_options[$option_id] = [
            'selected_value_id' => $value_id,
            'price' => $price
        ];

        // Serialize the updated options and update the option in the database
        // update_option("rental_order_selected_options", $rental_order_selected_options);
        set_rental_session_data("rental_order_selected_options", $rental_order_selected_options);

        calculate_cart_totals('');

        $fees = rental_calculate_order_total();
        $security_deposit_updated = 0;
        $security_deposit_title = '';
        if (get_option('rental_allow_to_pay_security_deposit') && !empty($security_deposit = rental_get_security_deposit())) {
            $security_deposit_updated = $fees['security_deposit_fee'];
            $security_deposit_title = $security_deposit['title'];
        }

        $tax_value = 0;
        // if (get_option('rental_combine_shipping_tax')) {
        $delivery_settings = rental_get_delivery_settings();
        if ($delivery_settings && $delivery_settings['enable_combined_shipping_tax_for_website']) {
            $tax_value = $fees['shipping_tax'];
        } 
        $tax_value += $fees['rental_tax'] + $fees['sale_tax'];
        $tax_title = get_option('rental_tax_text')?: __('Tax', 'rentopian-sync');

        $damage_waiver_value = $damage_waiver_tax_value = 0;
        $damage_waiver_title = $damage_waiver_tax_title = '';
        if ($fees['damage_waiver']) {
            $damage_waiver_value = $fees['damage_waiver'];
            $damage_waiver_title = __('Damage Waiver', 'rentopian-sync');
            if ($fees['damage_waiver_tax']) {
                $damage_waiver_tax_value = $fees['damage_waiver_tax'];
                $damage_waiver_tax_title = __('Damage Waiver Tax', 'rentopian-sync');
            }
            
        }

        $rush_fee_value = $rush_fee_title = "";
        if ($fees['rush_fee']) {
            $rush_fee_value = $fees['rush_fee'];
            $rush_fee_title = __('Rush Fee', 'rentopian-sync');
        }

        if ( !empty($fees['order_fees'])) {
            foreach ($fees['order_fees'] as $key => $order_fee) {
                $fees['order_fees'][$key]["amount"] = format_value_to_fixed_precision($order_fee['amount'], 2);
            }
        }
        
        $subtotal = get_rental_session_data('rental_product_subtotal', 0);
        // $subtotal = get_option('rental_product_subtotal' . get_new_unique_id(), 0);
        
        // $rental_order_selected_options_total = get_option("rental_order_selected_options_total", 0);
        $rental_order_selected_options_total = get_rental_session_data("rental_order_selected_options_total", 0);

        foreach($rental_order_selected_options as $order_selected_option) {
            $rental_order_selected_options_total += $order_selected_option["price"] >= 0 ? $order_selected_option["price"] : 0;
        }

        // update_option('rental_order_selected_options_total', $rental_order_selected_options_total);
        set_rental_session_data('rental_order_selected_options_total', $rental_order_selected_options_total);

        wp_send_json(
            [
                'currency' => get_woocommerce_currency_symbol(), 
                'subtotal' => format_value_to_fixed_precision($subtotal ,2),
                'total' => format_value_to_fixed_precision( rental_calculate_order_total()['total'], 2),
                'price_total' => format_value_to_fixed_precision($rental_order_selected_options_total, 2),
                'security_deposit_value' => format_value_to_fixed_precision($security_deposit_updated, 2),
                'security_deposit_title' => $security_deposit_title,
                'tax_value' => format_value_to_fixed_precision($tax_value, 2),
                'tax_title' => $tax_title,
                'damage_waiver_value' => format_value_to_fixed_precision($damage_waiver_value, 2),
                'damage_waiver_title' => $damage_waiver_title,
                'damage_waiver_tax_value' => format_value_to_fixed_precision($damage_waiver_tax_value, 2),
                'damage_waiver_tax_title' => $damage_waiver_tax_title,
                'auto_applied_fees' => $fees['order_fees'],
                'rush_fee_value' => format_value_to_fixed_precision($rush_fee_value, 2),
                'rush_fee_title' => $rush_fee_title,
            ]
        );
    }
    wp_die();
}

/**
 * BATCH update multiple product options at once (for single product page rapid changes)
 * This prevents race conditions by sending all option changes in a single request.
 * 
 * PRIMARY: Updates WooCommerce cart item data directly if product is in cart.
 * FALLBACK: Updates session data for products not yet in cart.
 */
function wp_ajax_rental_update_product_options_batch() {
    if (
        isset($_POST["product_id"]) 
        && isset($_POST["options"])
    ) {
        $product_id = intval($_POST["product_id"]);
        $options_json = stripslashes($_POST["options"]);
        $options = json_decode($options_json, true);
        $request_id = isset($_POST["request_id"]) ? intval($_POST["request_id"]) : 0;
        if (function_exists('rental_options_trace')) {
            rental_options_trace('batch_entry', array('pid'=>$product_id,'single_product'=>isset($_POST['single_product'])?1:0,'raw'=>$options_json));
        }
        $is_set = (isset($_POST["is_set"]) && intval($_POST["is_set"]) === 1) || get_post_meta($product_id, '_rental_is_set', true);
        
        if (!is_array($options) || empty($options)) {
            wp_send_json(['success' => false, 'message' => 'No options provided']);
            wp_die();
        }

        // =====================================================================
        // The product page only ever STAGES a selection.
        //
        // A change made there must not reach a line already in the cart: the
        // customer is configuring the next add, not editing what they already
        // bought. Letting it write the cart line is what made a product-page
        // change show up on checkout while the cart and mini-cart still showed
        // the committed values. The staged selection is committed to the cart
        // line at add-to-cart, replacing whatever was there.
        //
        // Cart-page edits are a different flow and DO write the line.
        // =====================================================================
        $is_staging = ! empty($_POST['single_product']);

        $cart_update_result = $is_staging
            ? ['in_cart' => false, 'cart_item_key' => null]
            : rental_update_batch_cart_item_options_with_titles($product_id, $options, $is_set);
        $in_cart = $cart_update_result['in_cart'];
        $cart_item_key = $cart_update_result['cart_item_key'] ?? null;

        $rntp_trace_opts = array();
        foreach ( (array) $options as $rntp_o ) {
            if ( isset( $rntp_o['option_id'], $rntp_o['value_id'] ) ) {
                $rntp_trace_opts[ (int) $rntp_o['option_id'] ] = (int) $rntp_o['value_id'];
            }
        }
        rental_options_trace('batch_update', array(
            'pid'      => $product_id,
            'is_set'   => $is_set ? 1 : 0,
            'in_cart'  => $in_cart ? 1 : 0,
            'cart_key' => $cart_item_key ? substr((string) $cart_item_key, 0, 6) : '-',
            'opts'     => $rntp_trace_opts,
        ));
        
        // =====================================================================
        // ALWAYS: Update session data (for display on single product page and as fallback)
        // =====================================================================
        $options_session_name = $is_set 
            ? $product_id . "_selected_options_of_set"
            : $product_id . "_selected_options";

        $item_selected_option = get_rental_session_data($options_session_name, []);
        $rental_product_options_valuables = get_rental_session_data('rental_product_options_valuables', []);
        
        if (!isset($rental_product_options_valuables[$product_id])) {
            $rental_product_options_valuables[$product_id] = [];
        }

        // Load option definitions to get titles
        $option_definitions = $is_set ? get_set_options($product_id) : get_product_options($product_id);
        $option_lookup = [];
        foreach ($option_definitions as $opt_def) {
            $option_lookup[(int)$opt_def['id']] = $opt_def;
        }

        // CRITICAL FIX: Re-read session data RIGHT BEFORE saving to get latest state
        // This prevents race conditions when multiple AJAX calls run concurrently
        $current_item_options = get_rental_session_data($options_session_name, []);
        $current_valuables = get_rental_session_data('rental_product_options_valuables', []);
        
        if (!isset($current_valuables[$product_id])) {
            $current_valuables[$product_id] = [];
        }
        
        // If product is in cart, use the cart item's options as the source of truth
        // This ensures session and cart stay in sync
        if ($in_cart && $cart_item_key && function_exists('WC') && WC()->cart) {
            $cart_contents = WC()->cart->get_cart();
            if (isset($cart_contents[$cart_item_key])) {
                $cart_item = $cart_contents[$cart_item_key];
                // Check both possible option keys
                if (isset($cart_item['rental_selected_set_options']) && !empty($cart_item['rental_selected_set_options'])) {
                    $current_item_options = $cart_item['rental_selected_set_options'];
                } elseif (isset($cart_item['rental_selected_options']) && !empty($cart_item['rental_selected_options'])) {
                    $current_item_options = $cart_item['rental_selected_options'];
                }
                // Also update the valuables from cart
                $current_valuables[$product_id] = $current_item_options;
            }
        }
        
        // Merge our updates INTO the current (latest) session data
        $processed_options = [];
        foreach ($options as $opt) {
            $option_id = intval($opt['option_id']);
            $value_id = intval($opt['value_id']);
            $price = $opt['price'];
            
            // Get titles from lookup
            $option_title = '';
            $value_title = '';
            if (isset($option_lookup[$option_id])) {
                $option_title = $option_lookup[$option_id]['title'] ?? '';
                if (!empty($option_lookup[$option_id]['option_values'])) {
                    foreach ($option_lookup[$option_id]['option_values'] as $val) {
                        if ((int)$val['id'] === $value_id) {
                            $value_title = $val['title'] ?? '';
                            break;
                        }
                    }
                }
            }
            
            // Update in current session data
            $current_item_options[$option_id] = [
                'selected_value_id' => $value_id,
                'value_id' => $value_id,
                'price' => $price,
                'option_title' => $option_title,
                'value_title' => $value_title,
                'option_id' => $option_id
            ];
            
            $current_valuables[$product_id][$option_id] = [
                'value_id' => $value_id,
                'selected_value_id' => $value_id,
                'price' => $price,
                'option_title' => $option_title,
                'value_title' => $value_title,
                'option_id' => $option_id
            ];
            
            $processed_options[] = ['option_id' => $option_id, 'value_id' => $value_id];
        }

        // Save merged session data.
        // Resolving from the merged map fills in every option the customer did
        // not touch, so a product with several options can never end up with
        // only the changed one recorded. Both stores are written together.
        $override = [];
        foreach ($current_item_options as $opt_id => $entry) {
            $value_id = is_array($entry)
                ? (isset($entry['value_id']) ? (int) $entry['value_id'] : (int) ($entry['selected_value_id'] ?? 0))
                : (int) $entry;
            if ($value_id > 0) {
                $override[(int) $opt_id] = $value_id;
            }
        }
        $current_item_options = Rental_Options_Selection::resolve(
            $product_id,
            $is_set,
            $cart_item_key,
            $override,
            $is_staging ? Rental_Options_Selection::CONTEXT_STAGING : Rental_Options_Selection::CONTEXT_COMMITTED
        );
        Rental_Options_Selection::persist($product_id, $is_set, $current_item_options);

        // Use the merged data for logging
        $item_selected_option = $current_item_options;
        $rental_product_options_valuables = get_rental_session_data('rental_product_options_valuables', []);
        
        // =====================================================================
        // Recalculate cart totals if product is in cart
        // =====================================================================
        $totals = null;
        if ($in_cart && function_exists('WC') && WC()->cart) {
            calculate_cart_totals('');
            $fees = rental_calculate_order_total();
            $subtotal = get_rental_session_data('rental_product_subtotal', 0);
            
            $totals = [
                'currency' => get_woocommerce_currency_symbol(),
                'subtotal' => format_value_to_fixed_precision($subtotal, 2),
                'total' => format_value_to_fixed_precision($fees['total'], 2)
            ];
        }

        wp_send_json([
            'success' => true,
            'in_cart' => $in_cart,
            'cart_updated' => $cart_update_result['success'],
            'cart_item_key' => $cart_item_key,
            'processed_count' => count($options),
            'processed_options' => $processed_options,
            'request_id' => $request_id,
            'totals' => $totals
        ]);
    }
    wp_die();
}

/**
 * Sync confirmation: Receive option values directly from the DOM (client-side selects)
 * and force-write them into WooCommerce cart item data.
 * This bypasses session entirely - the DOM is the single source of truth.
 *
 * Called from the single product page 500ms after the main batch update completes.
 */
function wp_ajax_rental_sync_cart_item_options() {
    if (!isset($_POST['product_id'])) {
        wp_send_json(['success' => false, 'message' => 'Missing product_id']);
        wp_die();
    }

    $product_id = intval($_POST['product_id']);
    $is_set = (isset($_POST['is_set']) && intval($_POST['is_set']) === 1) || get_post_meta($product_id, '_rental_is_set', true);
    $options_json = isset($_POST['options']) ? stripslashes($_POST['options']) : '[]';
    $dom_options = json_decode($options_json, true);

    if (!is_array($dom_options) || empty($dom_options)) {
        wp_send_json(['success' => false, 'message' => 'No options provided from DOM']);
        wp_die();
    }

    // Find the cart item for this product
    $cart_item_key = rental_find_cart_item_key_by_product_id($product_id);
    if (!$cart_item_key) {
        wp_send_json(['success' => false, 'in_cart' => false, 'message' => 'Product not in cart']);
        wp_die();
    }

    // Load option definitions to get titles (for display purposes)
    $option_definitions = $is_set ? get_set_options($product_id) : get_product_options($product_id);
    $option_lookup = [];
    foreach ($option_definitions as $opt_def) {
        $option_lookup[(int)$opt_def['id']] = $opt_def;
    }

    // Build properly formatted options with titles from the DOM-sourced data
    $options_to_write = [];
    foreach ($dom_options as $opt) {
        $option_id = intval($opt['option_id']);
        $value_id = intval($opt['value_id']);
        $price = isset($opt['price']) ? $opt['price'] : 0;

        // Get titles from definitions
        $option_title = '';
        $value_title = '';
        if (isset($option_lookup[$option_id])) {
            $option_title = $option_lookup[$option_id]['title'] ?? '';
            if (!empty($option_lookup[$option_id]['option_values'])) {
                foreach ($option_lookup[$option_id]['option_values'] as $val) {
                    if ((int)$val['id'] === $value_id) {
                        $value_title = $val['title'] ?? '';
                        // Use the price from definitions if DOM price is -1 or missing
                        if ($price == -1 || $price === 0) {
                            $price = $val['price'] ?? 0;
                        }
                        break;
                    }
                }
            }
        }

        $options_to_write[$option_id] = [
            'value_id' => $value_id,
            'selected_value_id' => $value_id,
            'price' => $price,
            'option_title' => $option_title,
            'value_title' => $value_title,
            'option_id' => $option_id
        ];
    }

    if (empty($options_to_write)) {
        wp_send_json(['success' => false, 'message' => 'No valid options parsed']);
        wp_die();
    }

    // Determine the correct cart item options key
    $options_key = $is_set ? 'rental_selected_set_options' : 'rental_selected_options';

    // Force-write to cart item data (REPLACE entirely - DOM is the single source of truth)
    if (isset(WC()->cart->cart_contents[$cart_item_key])) {
        WC()->cart->cart_contents[$cart_item_key][$options_key] = $options_to_write;
        WC()->cart->set_session();

        // Also update WC session directly for cross-request persistence
        if (WC()->session) {
            $cart_session = WC()->session->get('cart', []);
            if (isset($cart_session[$cart_item_key])) {
                $cart_session[$cart_item_key][$options_key] = $options_to_write;
                WC()->session->set('cart', $cart_session);
            }
        }

        // Also update the session-based stores so calculate_cart_totals picks up correct values
        $options_session_name = $is_set
            ? $product_id . '_selected_options_of_set'
            : $product_id . '_selected_options';
        $session_opts = [];
        $valuables = get_rental_session_data('rental_product_options_valuables', []);
        if (!isset($valuables[$product_id])) {
            $valuables[$product_id] = [];
        }
        foreach ($options_to_write as $oid => $odata) {
            $session_opts[$oid] = [
                'selected_value_id' => $odata['value_id'],
                'value_id' => $odata['value_id'],
                'price' => $odata['price']
            ];
            $valuables[$product_id][$oid] = $odata;
        }
        set_rental_session_data($options_session_name, $session_opts);
        set_rental_session_data('rental_product_options_valuables', $valuables);
    }

    wp_send_json([
        'success' => true,
        'in_cart' => true,
        'cart_item_key' => $cart_item_key,
        'synced_count' => count($options_to_write),
        'options_key' => $options_key
    ]);
    wp_die();
}

function wp_ajax_rental_update_order_option_of_set() {
    if (
        isset($_POST["option_id"]) 
        && isset($_POST["value_id"])
        && isset($_POST["price"])
    ) {
        
        // $rental_order_selected_options_of_sets = get_option('rental_order_selected_options_of_sets', []);
        $rental_order_selected_options_of_sets = get_rental_session_data('rental_order_selected_options_of_sets', []);

        $rental_order_selected_options_of_sets[intval($_POST["option_id"])] = [
            'selected_value_id' => intval($_POST["value_id"])
            , 'price' => $_POST["price"]
        ];

        // update_option('rental_order_selected_options_of_sets', $rental_order_selected_options_of_sets);
        set_rental_session_data('rental_order_selected_options_of_sets', $rental_order_selected_options_of_sets);

        rental_options_trace('order_option_of_set', array(
            'option'   => intval($_POST["option_id"]),
            'value'    => intval($_POST["value_id"]),
            'price'    => $_POST["price"],
            'pid'      => isset($_POST['product_id']) ? intval($_POST['product_id']) : '-',
            'cart_key' => isset($_POST['key']) ? substr(sanitize_text_field(wp_unslash($_POST['key'])), 0, 6) : '-',
            'note'     => 'writes_only_session_B(rental_order_selected_options_of_sets)',
        ));

        calculate_cart_totals('');

        $fees = rental_calculate_order_total();
        $security_deposit_updated = 0;
        $security_deposit_title = '';
        if (get_option('rental_allow_to_pay_security_deposit') && !empty($security_deposit = rental_get_security_deposit())) {
            $security_deposit_updated = $fees['security_deposit_fee'];
            $security_deposit_title = $security_deposit['title'];
        }

        $tax_value = 0;
        // if (get_option('rental_combine_shipping_tax')) {
        $delivery_settings = rental_get_delivery_settings();
        if ($delivery_settings && $delivery_settings['enable_combined_shipping_tax_for_website']) {
            $tax_value = $fees['shipping_tax'];
        } 
        $tax_value += $fees['rental_tax'] + $fees['sale_tax'];
        $tax_title = get_option('rental_tax_text')?: __('Tax', 'rentopian-sync');

        $damage_waiver_value = $damage_waiver_tax_value = 0;
        $damage_waiver_title = $damage_waiver_tax_title = '';
        if ($fees['damage_waiver']) {
            $damage_waiver_value = $fees['damage_waiver'];
            $damage_waiver_title = __('Damage Waiver', 'rentopian-sync');
            if ($fees['damage_waiver_tax']) {
                $damage_waiver_tax_value = $fees['damage_waiver_tax'];
                $damage_waiver_tax_title = __('Damage Waiver Tax', 'rentopian-sync');
            }
            
        }

        $rush_fee_value = $rush_fee_title = "";
        if ($fees['rush_fee']) {
            $rush_fee_value = $fees['rush_fee'];
            $rush_fee_title = __('Rush Fee', 'rentopian-sync');
        }

        if ( !empty($fees['order_fees'])) {
            foreach ($fees['order_fees'] as $key => $order_fee) {
                $fees['order_fees'][$key]["amount"] = format_value_to_fixed_precision($order_fee['amount'], 2);
            }
        }
        
        
        $subtotal = get_rental_session_data('rental_product_subtotal', 0);
        // $subtotal = get_option('rental_product_subtotal' . get_new_unique_id(), 0);

        // $rental_order_selected_options_total_of_sets = get_option('rental_order_selected_options_total_of_sets', 0);
        $rental_order_selected_options_total_of_sets = get_rental_session_data('rental_order_selected_options_total_of_sets', 0);

        foreach($rental_order_selected_options_of_sets as $order_selected_option) {
            $rental_order_selected_options_total_of_sets += $order_selected_option["price"] >= 0 ? $order_selected_option["price"] : 0;
        }

        // update_option('rental_order_selected_options_total_of_sets', $rental_order_selected_options_total_of_sets);
        set_rental_session_data('rental_order_selected_options_total_of_sets', $rental_order_selected_options_total_of_sets);

        wp_send_json(
            [
                'currency' => get_woocommerce_currency_symbol(), 
                'subtotal' => format_value_to_fixed_precision($subtotal ,2),
                'total' => format_value_to_fixed_precision( rental_calculate_order_total()['total'], 2),
                'price_total' => format_value_to_fixed_precision($rental_order_selected_options_total_of_sets ,2),
                'security_deposit_value' => format_value_to_fixed_precision($security_deposit_updated, 2),
                'security_deposit_title' => $security_deposit_title,
                'tax_value' => format_value_to_fixed_precision($tax_value, 2),
                'tax_title' => $tax_title,
                'damage_waiver_value' => format_value_to_fixed_precision($damage_waiver_value, 2),
                'damage_waiver_title' => $damage_waiver_title,
                'damage_waiver_tax_value' => format_value_to_fixed_precision($damage_waiver_tax_value, 2),
                'damage_waiver_tax_title' => $damage_waiver_tax_title,
                'auto_applied_fees' => $fees['order_fees'],
                'rush_fee_value' => format_value_to_fixed_precision($rush_fee_value, 2),
                'rush_fee_title' => $rush_fee_title,
            ]
        );
    }
    wp_die();
}

function check_options_existance() {
    global $woocommerce;
    foreach ($woocommerce->cart->cart_contents as $cart_item) {
        if (get_post_meta($cart_item['product_id'], '_rental_is_set', true)) {
            if (!empty(get_set_options($cart_item['product_id']))) {
                return true;
            }
        } else {
            if (!isset($cart_item['rental_set_id']) && !empty(get_product_options($cart_item['product_id']))) {
                // not a set's item
                return true;
            }
        }
    }
    return false;
}

/*
function get_product_options($product_id) {
    $is_add_on = get_post_meta($product_id, '_rental_is_add_on', true);
    if ($is_add_on) {
        return [];
    }
    $is_set = get_post_meta($product_id, '_rental_is_set', true);
    if ($is_set) {
        return [];
    }
    global $wpdb, $rental_tables;
    $rental_product_options = $wpdb->prefix . $rental_tables["product_options"];

    // all variants
    // get variant ids' parent id(main product id which may have option relations)
    $sql = "
        SELECT 
            post_parent
        FROM 
            {$wpdb->posts}
        WHERE 
            id = %d
            AND post_type = 'product_variation'
    ";
    $product_variant = $wpdb->get_row($wpdb->prepare($sql, [$product_id]), ARRAY_A);
    
    $product_ids[] = $product_id;
    if (!empty($product_variant)) {
        $product_ids[] = $product_variant["post_parent"];
    }

    $options=[];
    $option_ids_list=[];
    $option_ids=[];
    foreach($product_ids as $pid) {
        $option_ids_list = get_post_meta($pid, '_product_options');
        // if options found, break out!
        if (isset($option_ids_list[0]) && $option_ids_list[0]) {
            break;
        }
    }
    if ($option_ids_list) {
        $option_ids = json_decode($option_ids_list[0], true);
        $placeholders = array_fill(0, count($option_ids), '%d');
        $placeholders_format = implode(', ', $placeholders);
        $sql = "
            SELECT 
                op.* 
            FROM 
                {$rental_product_options} op
            WHERE 
                id IN ({$placeholders_format})
        ";
        $options = $wpdb->get_results($wpdb->prepare($sql, $option_ids), ARRAY_A);
        if ($options) {
            foreach($options as $key=>$option) {
                $options[$key]["option_values"] = json_decode($option["option_values"], true);
            }
        }
    }
    return $options;
}
*/

function get_product_options($product_id) {
    $is_add_on = get_post_meta($product_id, '_rental_is_add_on', true);
    if ($is_add_on) {
        return [];
    }
    $is_set = get_post_meta($product_id, '_rental_is_set', true);
    if ($is_set) {
        return [];
    }

    global $wpdb, $rental_tables;
    $rental_product_options = $wpdb->prefix . $rental_tables["product_options"];
    
    // Initialize product IDs array
    $product_ids = [$product_id];
    
    // Check if it's a variant and get parent ID in one query
    $product_variant = $wpdb->get_var($wpdb->prepare("
        SELECT post_parent 
        FROM {$wpdb->posts} 
        WHERE ID = %d AND post_type = 'product_variation' AND post_parent > 0
    ", $product_id));
    
    if ($product_variant) {
        $product_ids[] = $product_variant;
    }
    
    // Find option IDs for any of the product IDs (from post meta)
    $option_ids_list = null;
    foreach($product_ids as $pid) {
        $option_ids_list = get_post_meta($pid, '_product_options', true);
        if ($option_ids_list) {
            break;
        }
    }
    
    $option_ids = [];
    
    // Try post meta first (backward compatible)
    if ($option_ids_list) {
        $option_ids = json_decode($option_ids_list, true);
    }
    
    // Fallback: Query relations table directly if post meta is empty
    if (empty($option_ids)) {
        $rental_product_options_relations = $wpdb->prefix . $rental_tables["product_option_relations"];
        
        // Build placeholders for product IDs
        $pid_placeholders = array_fill(0, count($product_ids), '%d');
        $pid_format = implode(', ', $pid_placeholders);
        
        // Query relations table for option IDs (type=1 means product relation)
        $relation_sql = "SELECT DISTINCT po_id FROM {$rental_product_options_relations} WHERE wp_id IN ({$pid_format}) AND type = 1";
        $relation_results = $wpdb->get_col($wpdb->prepare($relation_sql, $product_ids));
        
        if ($relation_results) {
            $option_ids = array_map('intval', $relation_results);
            
            // Self-healing: Update post meta for faster future lookups
            update_post_meta($product_id, '_product_options', wp_json_encode($option_ids));
        }
    }
    
    if (empty($option_ids)) {
        return [];
    }
    
    // Get options in single query
    $placeholders = array_fill(0, count($option_ids), '%d');
    $placeholders_format = implode(', ', $placeholders);
    $sql = "
        SELECT op.*
        FROM {$rental_product_options} op
        WHERE id IN ({$placeholders_format})
    ";
    $options = $wpdb->get_results($wpdb->prepare($sql, $option_ids), ARRAY_A);
    
    // Decode option_values for each option
    if ($options) {
        foreach($options as $key => $option) {
            $options[$key]["option_values"] = json_decode($option["option_values"], true);
        }
    }
    
    return $options ? $options : [];
}

// product specific once per order options
function get_once_per_order_options_by_option_ids($option_ids) {
    if ($option_ids) {
        global $wpdb, $rental_tables;
        $rental_product_options = $wpdb->prefix . $rental_tables["product_options"];
        $placeholders = array_fill(0, count($option_ids), '%d');
        $placeholders_format = implode(', ', $placeholders);
        $sql = "
            SELECT 
                op.* 
            FROM 
                {$rental_product_options} op
            WHERE 
                once_per_order = 1
                AND id IN ({$placeholders_format})
        ";
        $options = $wpdb->get_results($wpdb->prepare($sql, $option_ids), ARRAY_A);
        if ($options) {
            foreach($options as $key=>$option) {
                $options[$key]["option_values"] = json_decode($option["option_values"], true);
            }
        }
        return $options;
    }

    return [];
}

// product specific order options
function rental_order_options_check() {
    foreach (WC()->cart->get_cart() as $cart_item_key => $cart_item) {
        if (!isset($cart_item['rental_set_id'])) {
            // not a set's item
            $product_id = $cart_item['variation_id'] ? $cart_item['variation_id'] : $cart_item['product_id'];
            $options = get_product_options($product_id);
            foreach($options as $option) {
                if ($option["once_per_order"]) {
                    return true;
                }
            }
        }
    }
    return false;
}
// product specific order options
function wp_ajax_rental_get_order_options() {
    $options_all = [];
    $products_option_ids = [];
    foreach (WC()->cart->get_cart() as $cart_item_key => $cart_item) {
        if (!isset($cart_item['rental_set_id'])) {
            // not a set's item
            $product_id = $cart_item['variation_id'] ? $cart_item['variation_id'] : $cart_item['product_id'];
            $options = get_product_options($product_id);
            if ($options) {
                foreach($options as $option) {
                    $products_option_ids[] = $option["id"];
                }
            }
        }
    }
    $products_option_ids = array_unique($products_option_ids);
    $all_options_data = get_once_per_order_options_by_option_ids($products_option_ids);

    if (!empty($all_options_data)) {
        foreach($all_options_data as $key => $option) {
            array_unshift($option["option_values"], [
                "id" => -1,
                "is_default" => 0,
                "option_id" => 0,
                "price" => -1,
                "title" => "Please select an option",
            ]);

            // $rental_order_selected_options_opt = get_option("rental_order_selected_options", []);
            $rental_order_selected_options_opt = get_rental_session_data("rental_order_selected_options", []);

            if ($rental_order_selected_options_opt && $rental_order_selected_options_opt[$option["id"]]
            ) {

                $options_all[] = [
                    "option_id" => $option["id"],
                    "option_title" => $option["title"],
                    "option_values" => $option["option_values"],
                    "selected_value_id" => $rental_order_selected_options_opt[$option["id"]]["selected_value_id"],
                    "is_selected" => 1,
                    "currency" => get_woocommerce_currency_symbol()
                ];
                        
            } else {

                // if a default value exists, select it
                $selected_option_value_id = 0;
                foreach($option["option_values"] as $option_value) {
                    if ($option_value["is_default"] == 1) {
                        $selected_option_value_id = $option_value["id"];

                        // $rental_order_selected_options = get_option("rental_order_selected_options", []);
                        $rental_order_selected_options = get_rental_session_data("rental_order_selected_options", []);

                        $rental_order_selected_options[$option["id"]] = [
                            'selected_value_id' => $selected_option_value_id,
                            'price' => $option_value["price"]
                        ];
                
                        // Serialize the updated options and update the option in the database
                        // update_option("rental_order_selected_options", $rental_order_selected_options);
                        set_rental_session_data("rental_order_selected_options", $rental_order_selected_options);
                    }
                }
                
                $options_all[] = [
                    "option_id" => $option["id"],
                    "option_title" => $option["title"],
                    "option_values" => $option["option_values"],
                    "selected_value_id" => $selected_option_value_id,
                    "is_selected" => $selected_option_value_id != 0 ? 1 : 0,
                    "currency" => get_woocommerce_currency_symbol()
                ];
            }
        }
    }
    wp_send_json($options_all);
    wp_die();
}

function wp_ajax_rental_get_order_options_effected_subtotal() {

    // if ($subtotal = get_option('rental_product_subtotal' . get_new_unique_id(), 0)) {
    if ($subtotal = get_rental_session_data('rental_product_subtotal', 0)) {
        wp_send_json([ "subtotal" => $subtotal, "currency" => get_woocommerce_currency_symbol()]);
    } else {
        wp_send_json([ "subtotal" => ""]);
    }

    wp_die();
}

// if ( ! function_exists( 'get_set_options' ) ) {
//     function get_set_options($set_id) {
//         $is_add_on = get_post_meta($set_id, '_rental_is_add_on', true);
//         if ($is_add_on) {
//             return [];
//         }
//         global $wpdb, $rental_tables;
//         $rental_set_options = $wpdb->prefix . $rental_tables["set_options"];
//         $rental_set_options_relations = $wpdb->prefix . $rental_tables["set_option_relations"];

//         $options = [];
//         $option_ids_list_json_encoded = get_post_meta($set_id, '_set_options', true);
        
//         $option_ids = [];
        
//         // Try post meta first (backward compatible)
//         if ($option_ids_list_json_encoded) {
//             $option_ids = json_decode($option_ids_list_json_encoded, true);
//         }
        
//         // Fallback: Query relations table directly if post meta is empty
//         if (empty($option_ids)) {
//             $relation_sql = "SELECT DISTINCT set_option_id FROM {$rental_set_options_relations} WHERE wp_id = %d";
//             $relation_results = $wpdb->get_col($wpdb->prepare($relation_sql, $set_id));
            
//             if ($relation_results) {
//                 $option_ids = array_map('intval', $relation_results);
                
//                 // Self-healing: Update post meta for faster future lookups
//                 update_post_meta($set_id, '_set_options', wp_json_encode($option_ids));
//             }
//         }
        
//         if (!empty($option_ids)) {
//             $option_ids_placeholders = array_fill(0, count($option_ids), '%d');
//             $option_ids_bind_format = implode(', ', $option_ids_placeholders);
//             $sql = "
//                 SELECT 
//                     op.* 
//                 FROM 
//                     {$rental_set_options} op
//                 WHERE 
//                     id IN ({$option_ids_bind_format})
//             ";
//             $options = $wpdb->get_results($wpdb->prepare($sql, $option_ids), ARRAY_A);
//             if ($options) {
//                 foreach($options as $key=>$option) {
//                     $options[$key]["option_values"] = json_decode($option["option_values"], true);
//                 }
//             }
//         }
//         return $options;
//     }
// }

// if ( ! function_exists( 'rental_order_options_of_sets_check' ) ) {
//     // set specific order options
//     function rental_order_options_of_sets_check() {
//         foreach (WC()->cart->get_cart() as $cart_item_key => $cart_item) {
//             $product_id = $cart_item['variation_id'] ? $cart_item['variation_id'] : $cart_item['product_id'];
//             if (get_post_meta($product_id, '_rental_is_set', true)) {
//                 $options = get_set_options($product_id);
//                 if ($options) {
//                     foreach($options as $option) {
//                         if ($option["once_per_order"]) {
//                             return true;
//                         }
//                     }
//                 }
//             }
//         }
//         return false;
//     }
// }

// set specific order options
function wp_ajax_rental_get_order_options_of_sets() {
    $options_all = [];
    $sets_option_ids = [];
    foreach (WC()->cart->get_cart() as $cart_item_key => $cart_item) {
        $product_id = $cart_item['variation_id'] ? $cart_item['variation_id'] : $cart_item['product_id'];
        if (get_post_meta($product_id, '_rental_is_set', true)) {
            $options = get_set_options($product_id);
            if ($options) {
                foreach($options as $option) {
                    $sets_option_ids[] = $option["id"];
                }
            }
        }
    }

    $all_options_data = [];
    if ($sets_option_ids) {
        $sets_option_ids = array_unique($sets_option_ids);
        $all_options_data = get_once_per_order_set_options_by_option_ids($sets_option_ids);
    }
    if (!empty($all_options_data)) {
        foreach($all_options_data as $key => $option) {
            array_unshift($option["option_values"], [
                "id" => -1,
                "is_default" => 0,
                "option_id" => 0,
                "price" => -1,
                "title" => "Please select an option",
            ]);


            // $rental_order_selected_options_of_sets = get_option('rental_order_selected_options_of_sets', []);
            $rental_order_selected_options_of_sets = get_rental_session_data('rental_order_selected_options_of_sets', []);


            if ( isset($rental_order_selected_options_of_sets[$option["id"]]) ) {

                $options_all[] = [
                    "option_id" => $option["id"],
                    "option_title" => $option["title"],
                    "option_values" => $option["option_values"],
                    "selected_value_id" => $rental_order_selected_options_of_sets[$option["id"]]["selected_value_id"],
                    "is_selected" => 1,
                    "currency" => get_woocommerce_currency_symbol()
                ];
                        
            } else {

                // if a default value exists, select it
                $selected_option_value_id = 0;
                foreach($option["option_values"] as $option_value) {
                    if ($option_value["is_default"] == 1) {
                        $selected_option_value_id = $option_value["id"];

                        $rental_order_selected_options_of_sets[$option["id"]] = [
                            'selected_value_id' => $selected_option_value_id,
                            'price' => $option_value["price"]
                        ];

                        // update_option('rental_order_selected_options_of_sets', $rental_order_selected_options_of_sets);
                        set_rental_session_data('rental_order_selected_options_of_sets', $rental_order_selected_options_of_sets);
                    }
                }
                
                $options_all[] = [
                    "option_id" => $option["id"],
                    "option_title" => $option["title"],
                    "option_values" => $option["option_values"],
                    "selected_value_id" => $selected_option_value_id,
                    "is_selected" => $selected_option_value_id != 0 ? 1 : 0,
                    "currency" => get_woocommerce_currency_symbol()
                ];
            }
        }
    }
    wp_send_json($options_all);
    wp_die();
}


// if ( ! function_exists( 'get_once_per_order_set_options_by_option_ids' ) ) {
// // set specific once per order options
// function get_once_per_order_set_options_by_option_ids($set_option_ids) {
//     if ($set_option_ids) {
//         global $wpdb, $rental_tables;
//         $rental_set_options = $wpdb->prefix . $rental_tables["set_options"];
//         $placeholders = array_fill(0, count($set_option_ids), '%d');
//         $placeholders_format = implode(', ', $placeholders);
//         $sql = "
//             SELECT 
//                 op.* 
//             FROM 
//                 {$rental_set_options} op
//             WHERE 
//                 once_per_order = 1
//                 AND id IN ({$placeholders_format})
//         ";
//         $options = $wpdb->get_results($wpdb->prepare($sql, $set_option_ids), ARRAY_A);
//         if ($options) {
//             foreach($options as $key=>$option) {
//                 $options[$key]["option_values"] = json_decode($option["option_values"], true);
//             }
//         }
//         return $options;
//     }

//     return [];
// }
// }


function wp_ajax_rental_replace_full_address_in_checkout() {
    // $uid = get_current_user_id();

    if( get_option('rental_show_location') && isset($_COOKIE["rental_google_map_address"]) && $_COOKIE["rental_google_map_address"] && isset($_COOKIE["rental_address"]) && $_COOKIE["rental_address"]) {
        
        $decrypted_rental_address = decrypt_data($_COOKIE['rental_address'], get_option('rental_encryption_key'));

        $address = '';
        $full_address_array = explode(',', $decrypted_rental_address);
        $full_address_length = count($full_address_array);
        if ($full_address_length == 1) {
            $address = implode($full_address_array);
        }

        // $zip = '';
        // if (!get_option('rental_hide_zip') && isset($_COOKIE['rental_zip']) && $_COOKIE['rental_zip'] && $_COOKIE['rental_zip'] != 1 ) {
        //     $zip = $_COOKIE['rental_zip'];
        // }
        // wp_send_json(['user_logged_in' => 0, 'zip' => $zip, 'address' => $address]);
        wp_send_json(['zip' => '', 'address' => $address]);

    } else {

        $zip = '';
        if (!get_option('rental_hide_zip') && isset($_COOKIE['rental_zip']) && $_COOKIE['rental_zip'] ) {
            
            $decrypted_rental_zip = decrypt_data($_COOKIE['rental_zip'], get_option('rental_encryption_key'));

            if ($decrypted_rental_zip != 1) {
                $zip = $decrypted_rental_zip;
            }
            // $zip = $_COOKIE['rental_zip'];
        }
        wp_send_json(['zip' => $zip, 'address' => '']);
    }
    
    wp_die();
}


function wp_ajax_rental_replace_cart_page_textual_labels() {
    $custom_text = get_option('rental_cart_button_text');
    wp_send_json(['cart_label' => !empty($custom_text) ? $custom_text : ""]);
    wp_die();
}

function wp_ajax_rental_replace_mini_cart_textual_labels_values() {

    $custom_checkout_button_text = get_option('rental_checkout_button_text');
    $custom_checkout_label = empty($custom_checkout_button_text) ? 'Checkout' : $custom_checkout_button_text;

    $response = [
        'checkout_label' => $custom_checkout_label,
        'subtotal' => 0,
        'total' => 0,
        'currency_symbol' => get_woocommerce_currency_symbol()
    ];

    if (WC()->cart->is_empty()) {
        wp_send_json($response);
        wp_die();
    }

    calculate_cart_totals();

    $cached_totals = get_transient('user_cart_totals_' . get_current_user_id());
    if ($cached_totals) {
        $fees = $cached_totals;
    } else {
        $fees = rental_calculate_order_total();
        set_transient('user_cart_totals_' . get_current_user_id(), $fees, 60); // Cache for 60 seconds
    }
   
    // $fees = rental_calculate_order_total();
    // $subtotal = get_option('rental_product_subtotal' . get_new_unique_id(), 0);
    $subtotal = get_rental_session_data('rental_product_subtotal', 0);

    $response['subtotal'] = $subtotal;
    $response['total'] = $fees ? format_value_to_fixed_precision($fees['total'], 2)  : 0;

    wp_send_json($response);
    wp_die();
}

function rental_replace_coupon_text_label() {
    $coupon_label = get_option('rental_coupon_label_text', 'Coupon');

    wp_send_json_success([
        'coupon_label' => $coupon_label,
    ]);
}

function rental_get_invalid_coupon_response() {
    $error_msg = '';
    if (isset($_COOKIE['rental_coupon_code_is_invalid_error']) && $_COOKIE['rental_coupon_code_is_invalid_error']) {

        $error_msg = $_COOKIE['rental_coupon_code_is_invalid_error'];

        unset($_COOKIE['rental_coupon_code_is_invalid_error']);
        setcookie('rental_coupon_code_is_invalid_error', false, time() - (31556952), "/", "", false, false);
    }

    wp_send_json_success([
        'msg' => $error_msg,
    ]);
}


function rental_check_blacklisted_client($billing_email, $billing_phone, $client_fname, $client_lname) {
    $email = rental_sanitize($billing_email);
    $phone = !empty($billing_phone) ? rental_sanitize($billing_phone) : null;
    
    try {
        $result_msg = json_decode(rental_curl('clients/blacklist-check', get_option('rental_api_key'), false, [
            'email' => $email,
            'phone' => $phone
        ], null, false, 30), true);

        
        if ($result_msg && isset($result_msg['message']) && $result_msg['message'] !== 'no_match_found') {

            // send email to notify admin(s)
            if (get_option('rental_client_blacklisted_notif_email')) {

                $main_admin_email = get_option('admin_email', null);
                $main_admin_new_email = get_option('new_admin_email', null);
                if ($main_admin_email) {
                    // main admin
                    $products = [];
                    foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) {
                        $_product = apply_filters( 'woocommerce_cart_item_product', $cart_item['data'], $cart_item, $cart_item_key );
                        $products[] = esc_html( $_product->get_name() );
                    }
                    if ($products) {
                        $products = implode(', ', $products);
                    }
                    
                    $full_name = "";
                    if ($client_fname) {
                        $full_name .= $client_fname;
                    }
                    if ($client_lname) {
                        $full_name .= ' '.$client_lname;
                    }
                    $phone_number_text = "";
                    if ($phone) {
                        $phone_number_text = "and phone number $phone";
                    }
                    $message = "Hello, a blacklisted client $full_name with email $email $phone_number_text tried to place an order with the following products : $products. The attempt was prevented by the website.";
                    wp_mail( sanitize_email($main_admin_email), "Blacklisted Client Activity", $message );
                    if ($main_admin_new_email && ($main_admin_new_email != $main_admin_email)) {
                        wp_mail( sanitize_email($main_admin_new_email), "Blacklisted Client Activity", $message );
                    }

                    // other admins
                    $query_part = $query_part2 = '';
                    $query_part = "AND `user`.user_email <> '$main_admin_email'";
                    if ($main_admin_new_email && ($main_admin_new_email != $main_admin_email)) {
                        $query_part2 = "AND `user`.user_email <> '$main_admin_new_email'";
                    }
                    global $wpdb;
                    $sql = "
                        SELECT 
                            `user`.user_email
                        FROM 
                            `$wpdb->users` `user` " .
                        "LEFT JOIN `$wpdb->usermeta` `meta` ON `meta`.`user_id` = `user`.`ID` AND `meta`.`meta_key` = 'wp_user_level' " .
                        "WHERE 
                            meta.meta_value = 10
                            $query_part
                            $query_part2
                    ";
                    $other_admins = $wpdb->get_results($sql, ARRAY_A);
                    if ($other_admins) {
                        foreach($other_admins as $admin_email) {
                            if ($admin_email["user_email"]) {
                                wp_mail( sanitize_email($admin_email["user_email"]), "Blacklisted Client Activity", $message );
                            }
                        }
                    }

                }
            }

            // blacklistedd client rejection message generate
            return ['message' => get_option('rental_client_blacklisted_reject_msg', 'Sorry, your request has been declined')];
        }

    } catch (Exception $e) {
        $error_msg = json_decode($e->getMessage(), true);
        return ['message' => is_array($error_msg) ? $error_msg[0] : $error_msg];
    }
}

function get_unavailable_items_sql_conditions($rental_unavailable_items, $unavailable_variant_sql, $unavailable_product_sql) {

    if ($rental_unavailable_items) {
        $unavailable_variant_ids_with_divs = !empty($rental_unavailable_items['unavailable_variant_ids_with_divs']) ? $rental_unavailable_items['unavailable_variant_ids_with_divs'] : [];
        if ($unavailable_variant_ids_with_divs) {
            foreach($unavailable_variant_ids_with_divs as $variant_id_with_div) {
                $unavailable_variant_sql .= " OR (rental_id = {$variant_id_with_div["variant_id"]} AND rental_division_id = {$variant_id_with_div["division_id"]} ) ";
            }
        }
        $unavailable_product_ids_with_divs = !empty($rental_unavailable_items['unavailable_product_ids_with_divs']) ? $rental_unavailable_items['unavailable_product_ids_with_divs'] : [];
        if ($unavailable_product_ids_with_divs) {
            foreach($unavailable_product_ids_with_divs as $product_id_with_div) {
                $unavailable_product_sql .= " OR (rental_id = {$product_id_with_div["product_id"]} AND rental_division_id = {$product_id_with_div["division_id"]} ) ";
            }
        }
    }

    return [
        'unavailable_variant_sql' => $unavailable_variant_sql,
        'unavailable_product_sql' => $unavailable_product_sql,
    ];
}

function get_unavailable_variant_ids($division_id, $unavailable_variant_sql, $rental_variant_relations, $left_join_post_meta_variant) {
    global $wpdb;

    $division_sql = '';
    if ($division_id) {
        $division_sql = " AND rental_division_id = $division_id ";
    }

    $needle = 'OR ';
    $pos = strpos($unavailable_variant_sql, $needle);
    if ($pos !== false) {
        $unavailable_variant_sql = substr_replace($unavailable_variant_sql, 'AND (', $pos, strlen($needle));
        $unavailable_variant_sql .= ')';
    }

    $sql = "
        select
            id
        from 
            {$rental_variant_relations} variant_rel
        {$left_join_post_meta_variant}
        where
            pm.meta_value = 'instock'
            $unavailable_variant_sql
            $division_sql
    ";
    $unavailable_variant_post_ids = $wpdb->get_col($sql);

    return $unavailable_variant_post_ids;
}

function get_unavailable_variant_ids_with_only_division_filter($division_id, $rental_variant_relations, $left_join_post_meta_variant) {
    global $wpdb;

    $division_id = intval($division_id);
    $division_sql = " AND rental_division_id <> $division_id ";

    $sql = "
        select
            id
        from 
            {$rental_variant_relations} variant_rel
        {$left_join_post_meta_variant}
        where
            pm.meta_value = 'instock'
            $division_sql
    ";
    $unavailable_variant_post_ids = $wpdb->get_col($sql);

    return $unavailable_variant_post_ids;
}

function get_unavailable_product_ids($division_id, $unavailable_product_sql, $rental_product_relations, $left_join_post_meta_product) {
    global $wpdb;

    $division_sql = '';
    if ($division_id) {
        $division_sql = " AND rental_division_id = $division_id ";
    }

    $needle = 'OR ';
    $pos = strpos($unavailable_product_sql, $needle);
    if ($pos !== false) {
        $unavailable_product_sql = substr_replace($unavailable_product_sql, 'AND (', $pos, strlen($needle));
        $unavailable_product_sql .= ')';
    }

    $sql = "
        select
            id
        from 
            {$rental_product_relations} product_rel
        {$left_join_post_meta_product}
        where
            pm.meta_value = 'instock'
            $unavailable_product_sql
            $division_sql
    ";
    $unavailable_product_post_ids = $wpdb->get_col($sql);

    return $unavailable_product_post_ids;
}

function get_unavailable_product_ids_with_only_division_filter($division_id, $rental_product_relations, $left_join_post_meta_product) {
    global $wpdb;

    $division_id = intval($division_id);
    $division_sql = " AND rental_division_id <> $division_id ";

    $sql = "
        select
            id
        from 
            {$rental_product_relations} product_rel
        {$left_join_post_meta_product}
        where
            pm.meta_value = 'instock'
            $division_sql
    ";
    $unavailable_product_post_ids = $wpdb->get_col($sql);

    return $unavailable_product_post_ids;
}

function get_published_products($unavailable_ids_all = []) {
    global $wpdb;

    $exclude_ids_condition = '';
    if ($unavailable_ids_all) {
        $exclude_ids_condition = " AND ID NOT IN ($unavailable_ids_all) ";
    }

    $sql = "
        select
            *
        from 
            $wpdb->posts
        where
            post_status = 'publish'
            AND post_type in ('product', 'product_variation')
            $exclude_ids_condition
    ";
    $available_ids_all = $wpdb->get_col($sql);

    return $available_ids_all;
}


function getInventoryBlockedRanges($rental_inventory_blocks, $start_date, $end_date) {
    global $wpdb;

    $sql = "
        SELECT COUNT(*)
        FROM {$rental_inventory_blocks}
        WHERE type = 1
          AND start_date <= %d
          AND end_date   >= %d
    ";
    return (int) $wpdb->get_var( $wpdb->prepare( $sql, [ $end_date, $start_date ] ) );
}

function getInventoryBlockedItems($rental_inventory_blocks, $rental_inventory_block_relations, $start_date, $end_date) {
    global $wpdb;

    $sql = "
        SELECT block_rel.id AS product_variant_id
        FROM {$rental_inventory_blocks} AS block
        LEFT JOIN {$rental_inventory_block_relations} AS block_rel ON block_rel.block_id = block.id
        WHERE block.type = 2
          AND block.start_date <= %d
          AND block.end_date   >= %d
    ";
    return $wpdb->get_col( $wpdb->prepare( $sql, [ $end_date, $start_date ] ) );
}


function get_unavailable_product_ids_with_blocked_inventory_items_filter($rental_product_relations, $left_join_post_meta_product,
    $placeholders_format, $unavailable_product_sql, $division_sql, $bind_data) {
    global $wpdb;

    $sql = "
        select
            id
        from 
            {$rental_product_relations} product_rel
        {$left_join_post_meta_product}
        where
            id IN ({$placeholders_format})
            {$unavailable_product_sql}
            AND pm.meta_value = 'instock'
            {$division_sql}
    ";
    $unavailable_product_ids = $wpdb->get_col($wpdb->prepare($sql, $bind_data));

    return $unavailable_product_ids;
}


function get_unavailable_variant_ids_with_blocked_inventory_items_filter($rental_variant_relations, $left_join_post_meta_variant,
    $placeholders_format, $unavailable_variant_sql, $division_sql, $bind_data) {
    global $wpdb;

    $sql = "
        select
            id
        from 
            {$rental_variant_relations} variant_rel
        {$left_join_post_meta_variant}
        where
            id IN ({$placeholders_format})
            {$unavailable_variant_sql}
            AND pm.meta_value = 'instock'
            {$division_sql}
    ";
    $unavailable_variant_ids = $wpdb->get_col($wpdb->prepare($sql, $bind_data));

    return $unavailable_variant_ids;
}



// check product availability functionality
function product_variant_list_filter($rental_unavailable_items, $division_id, $array = [], $item_id = 0, $duplicate_products_filter = []) {

    try {
        global $wpdb, $rental_tables;
        $rental_product_relations = $wpdb->prefix . $rental_tables["product_relations"];
        $rental_variant_relations = $wpdb->prefix . $rental_tables["variant_relations"];
        $rental_set_relations = $wpdb->prefix . $rental_tables["set_relations"];
                
        $rental_inventory_blocks = $wpdb->prefix . $rental_tables["inventory_blocks"];
        
        $left_join_post_meta_set = "LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = set_rel.id AND pm.meta_key = '_stock_status'";
        $left_join_post_meta_set_check = "LEFT JOIN {$wpdb->postmeta} pm_set ON pm_set.post_id = set_rel.id AND pm_set.meta_key = '_rental_is_set'";

        $left_join_post_meta_product = "LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = product_rel.id AND pm.meta_key = '_stock_status'";
        $left_join_post_meta_variant = "LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = variant_rel.id AND pm.meta_key = '_stock_status'";

        $left_join_post_meta_product_duplicate = "LEFT JOIN {$wpdb->postmeta} pm2 ON pm2.post_id = product_rel.id AND pm2.meta_key = '_rental_is_duplicate'";
        $left_join_post_meta_variant_duplicate = "LEFT JOIN {$wpdb->postmeta} pm2 ON pm2.post_id = variant_rel.id AND pm2.meta_key = '_rental_is_duplicate'";
        
        // collect add-on/hidden post IDs (covers parent & variation)
        $add_on_ids_to_exclude = rental_get_add_on_post_ids();

        $start_date = $end_date = 0;

        if ($item_id) {
            $is_set = false;
            if (get_post_meta($item_id, '_rental_is_set', true)) {
                $is_set = true;
            }
        }
        

        // inventory blocked items
        if ($wpdb->get_var("show tables like '$rental_inventory_blocks'") == $rental_inventory_blocks) {
            $rental_inventory_block_relations = $wpdb->prefix . $rental_tables["inventory_block_relations"];

            if (get_option('rental_synchronized_product_type') != "hourly") {
                // daily mode

                $decrypted_rental_start_date = "";
                if (isset($_COOKIE['rental_start_date']) && $_COOKIE['rental_start_date']) {
                    $decrypted_rental_start_date = decrypt_data($_COOKIE['rental_start_date'], get_option('rental_encryption_key'));
                }

                $decrypted_rental_end_date = "";
                if (isset($_COOKIE['rental_end_date']) && $_COOKIE['rental_end_date']) {
                    $decrypted_rental_end_date = decrypt_data($_COOKIE['rental_end_date'], get_option('rental_encryption_key'));
                }

                $start_date = $decrypted_rental_start_date ? strtotime($decrypted_rental_start_date) : 0;
                $end_date = isset($_COOKIE['rental_start_date']) && isset($_COOKIE['rental_end_date']) && $_COOKIE['rental_end_date'] ? strtotime($decrypted_rental_end_date) : $start_date;
            } 

            // else {
            //     // hourly mode
            //     $start_date = isset($_COOKIE["rental_hourly_start_date"]) && $_COOKIE["rental_hourly_start_date"] ? strtotime($_COOKIE["rental_hourly_start_date"]) : 0;
            //     $end_date = isset($_COOKIE["rental_hourly_start_date"]) && $_COOKIE["rental_hourly_end_date"] ? strtotime($_COOKIE["rental_hourly_end_date"]) : 0;
            // }

            if (empty($start_date) && !empty($item_id)) {
                return 2; // no selected date
            } 


            $blocked_ranges = getInventoryBlockedRanges($rental_inventory_blocks, $start_date, $end_date);
            if ( !($blocked_ranges > 0) ) {

                // check for specific blocked items
                $blocked_product_variant_ids = getInventoryBlockedItems($rental_inventory_blocks, $rental_inventory_block_relations, $start_date, $end_date);

                if ($blocked_product_variant_ids) {
                    $placeholders_format = implode(', ', array_fill(0, count($blocked_product_variant_ids), '%d'));
                    $division_sql = "";
                    $bind_data = $blocked_product_variant_ids;
                    if ($division_id) {
                        $division_sql = " AND rental_division_id = {$division_id} ";
                    }


                    // start/end date + zip code filter
                    $unavailable_variant_sql = $unavailable_product_sql = '';
                    if ($rental_unavailable_items) {
                        $unavailable_variant_ids_with_divs = !empty($rental_unavailable_items['unavailable_variant_ids_with_divs']) ? $rental_unavailable_items['unavailable_variant_ids_with_divs'] : [];
                        if ($unavailable_variant_ids_with_divs) {
                            foreach($unavailable_variant_ids_with_divs as $variant_id_with_div) {
                                $unavailable_variant_sql .= " OR (rental_id = {$variant_id_with_div["variant_id"]} AND rental_division_id = {$variant_id_with_div["division_id"]} ) ";
                            }
                        }
                        $unavailable_product_ids_with_divs = !empty($rental_unavailable_items['unavailable_product_ids_with_divs']) ? $rental_unavailable_items['unavailable_product_ids_with_divs'] : [];
                        if ($unavailable_product_ids_with_divs) {
                            foreach($unavailable_product_ids_with_divs as $product_id_with_div) {
                                $unavailable_product_sql .= " OR (rental_id = {$product_id_with_div["product_id"]} AND rental_division_id = {$product_id_with_div["division_id"]} ) ";
                            }
                        }
                    }


                    $unavailable_product_ids = get_unavailable_product_ids_with_blocked_inventory_items_filter($rental_product_relations, $left_join_post_meta_product,
                                                $placeholders_format, $unavailable_product_sql, $division_sql, $bind_data);
                    
                    $unavailable_variant_ids = get_unavailable_variant_ids_with_blocked_inventory_items_filter($rental_variant_relations, $left_join_post_meta_variant,
                                                $placeholders_format, $unavailable_variant_sql, $division_sql, $bind_data);
                    
                    $unavailable_ids_arr = array_merge(
                        (array) $unavailable_product_ids,
                        (array) $unavailable_variant_ids,
                        (array) $add_on_ids_to_exclude
                    );
                    $unavailable_ids_arr = array_map('intval', $unavailable_ids_arr);
                    $unavailable_ids_arr = array_unique($unavailable_ids_arr);
                    $unavailable_ids_all = $unavailable_ids_arr ? implode(',', $unavailable_ids_arr) : '';

                    $available_ids_all = [];


                    if (empty($duplicate_products_filter['location_based_duplicate_filter'])) {
                        if ($unavailable_ids_all) {

                            $available_ids_all = get_published_products($unavailable_ids_all);
                        }
                    }

                    // duplicate products/variants filter
                    $available_ids_duplicate_filtered = duplicate_products_filter($duplicate_products_filter, $rental_variant_relations, 
                    $left_join_post_meta_variant_duplicate, $left_join_post_meta_variant, $unavailable_ids_all,
                    $rental_product_relations, $left_join_post_meta_product_duplicate, $left_join_post_meta_product,
                    $rental_set_relations, $left_join_post_meta_set, $left_join_post_meta_set_check);

                    if ($available_ids_duplicate_filtered) {
                        $available_ids_all = $available_ids_duplicate_filtered;
                    }

                    if (!empty($item_id)) {

                        if (empty($available_ids_all)) {
                            return 1;
                        }
                        // if current product is not included in available/not blocked products, then return 0
                        if (!in_array($item_id, $available_ids_all)) {
                            return 0;
                        }

                    } else {
                        return $available_ids_all;
                    }
                    
                }
                
            } else {
                // all items must be blocked
                if (!empty($item_id)) {
                    return 0;
                } else {
                    return "";
                }
            }

        }


        // If no blocked inventory items exist check the set item availability
        if (!empty($item_id) && $is_set) {
                
            // start/end date + zip code filter
            $unavailable_set_ids = $available_set_ids = [];
            $unavailable_set_placeholders_format = $division_sql = '';
            if ($rental_unavailable_items) {
                $unavailable_set_ids = !empty($rental_unavailable_items['unavailable_sets']) ? $rental_unavailable_items['unavailable_sets'] : [];
                if ($unavailable_set_ids)
                    $unavailable_set_placeholders_format = implode(', ', array_fill(0, count($unavailable_set_ids), '%d'));
            }

            if ($unavailable_set_placeholders_format) {
                // select division mode
                $bind_data_set = $unavailable_set_ids;
                if ($division_id) {
                    $division_sql = " AND rental_division_id = $division_id ";
                }

                $sql = "
                    select
                        id
                    from 
                        {$rental_set_relations} set_rel
                    {$left_join_post_meta_set}
                    where
                        rental_id NOT IN ({$unavailable_set_placeholders_format})
                        AND pm.meta_value = 'instock'
                        $division_sql
                ";
                $available_set_ids = $wpdb->get_col($wpdb->prepare($sql, $bind_data_set));

            } else {
                // no filter - just check for specific location select option  
                $bind_data_set = $division_sql = '';
                if ($division_id) {
                    $division_sql = " AND rental_division_id = %d ";
                    $bind_data_set = $division_id;
                }

                $sql = "
                    select
                        id
                    from 
                        {$rental_set_relations} set_rel
                    {$left_join_post_meta_set}
                    where
                        pm.meta_value = 'instock'
                        $division_sql
                ";
                if ($bind_data_set) {
                    $available_set_ids = $wpdb->get_col($wpdb->prepare($sql, $bind_data_set));
                } else {
                    $available_set_ids = $wpdb->get_col($sql);
                }
            }

            if (!in_array($item_id, $available_set_ids)) {
                return 0;
            }
        }
        
        // If no blocked inventory items exist
        $unavailable_variant_sql = $unavailable_product_sql = '';
        $unavailable_items_sql_conditions = get_unavailable_items_sql_conditions($rental_unavailable_items, $unavailable_variant_sql, $unavailable_product_sql);
        if ($unavailable_items_sql_conditions) {
            $unavailable_variant_sql = $unavailable_items_sql_conditions['unavailable_variant_sql'];
            $unavailable_product_sql = $unavailable_items_sql_conditions['unavailable_product_sql'];
        }

        $unavailable_variant_post_ids = $unavailable_product_post_ids = [];
        if ($unavailable_variant_sql) {

            $unavailable_variant_post_ids = get_unavailable_variant_ids($division_id, $unavailable_variant_sql, $rental_variant_relations, $left_join_post_meta_variant);
        } else {

            // location / division filter - check for specific location/division selected option 
            if ($division_id) {

                $unavailable_variant_post_ids = get_unavailable_variant_ids_with_only_division_filter($division_id, $rental_variant_relations, $left_join_post_meta_variant);
            }
        }

       
        if ($unavailable_product_sql) {

            $unavailable_product_post_ids = get_unavailable_product_ids($division_id, $unavailable_product_sql, $rental_product_relations, $left_join_post_meta_product);
        } else {

            // location / division filter - check for specific location/division selected option 
            if ($division_id) {

                $unavailable_product_post_ids = get_unavailable_product_ids_with_only_division_filter($division_id, $rental_product_relations, $left_join_post_meta_product);
            }
        }

        $unavailable_ids_all = implode(',', array_merge($unavailable_variant_post_ids, $unavailable_product_post_ids, $add_on_ids_to_exclude));
        $available_ids_all = [];

        if (empty($duplicate_products_filter['location_based_duplicate_filter'])) {
            if ($unavailable_ids_all) {

                $available_ids_all = get_published_products($unavailable_ids_all);
            }
        }

        // duplicate products/variants filter
        $available_ids_duplicate_filtered = duplicate_products_filter($duplicate_products_filter, $rental_variant_relations, 
        $left_join_post_meta_variant_duplicate, $left_join_post_meta_variant, $unavailable_ids_all,
        $rental_product_relations, $left_join_post_meta_product_duplicate, $left_join_post_meta_product, 
        $rental_set_relations, $left_join_post_meta_set, $left_join_post_meta_set_check);

        if ($available_ids_duplicate_filtered) {
            $available_ids_all = $available_ids_duplicate_filtered;
        }

        if (!empty($item_id)) {

            if (empty($available_ids_all)) {
                return 1;
            }

            // if current product is not included in available/not blocked products, then return 0
            if (!in_array($item_id, $available_ids_all)) {
                return 0;
            }

        } else {

            return $available_ids_all;
        }
            

        if (!empty($item_id)) {
            return 1;
        }

    } catch (Throwable $th) {
        if (!empty($item_id)) {
            return 1;
        } else {
            return $array;
        }
    }
}

function duplicate_products_filter($duplicate_products_filter, $rental_variant_relations, 
    $left_join_post_meta_variant_duplicate, $left_join_post_meta_variant, $unavailable_ids_all,
    $rental_product_relations, $left_join_post_meta_product_duplicate, $left_join_post_meta_product,
    $rental_set_relations, $left_join_post_meta_set, $left_join_post_meta_set_check) {
    

    static $rntp_dup_memo = array();
    $rntp_dup_key = md5( serialize( array(
        ! empty( $duplicate_products_filter['location_based_duplicate_filter'] ) ? 1 : 0,
        isset( $duplicate_products_filter['location_based_duplicate_filter_division_id'] )
            ? (int) $duplicate_products_filter['location_based_duplicate_filter_division_id'] : 0,
        (string) $unavailable_ids_all,
    ) ) );
    if ( array_key_exists( $rntp_dup_key, $rntp_dup_memo ) ) {
        return $rntp_dup_memo[ $rntp_dup_key ];
    }

    // if ($data = get_rental_cache('rental_available_ids_duplicate_filtered', 'rental_available_ids_duplicate_filtered_expiration_date')) {
    //     return $data;
    // }


    global $wpdb;
    $available_ids_duplicate_filtered = [];

    // duplicate products/variants filter
    if ($duplicate_products_filter["location_based_duplicate_filter"]) {
        $primary_division_id = $duplicate_products_filter["location_based_duplicate_filter_division_id"];

        $sql_set = "
            select
                id
            from 
                {$rental_set_relations} set_rel
            {$left_join_post_meta_set}
            {$left_join_post_meta_set_check}
            where
                pm.meta_value = 'instock'
                AND pm_set.meta_value = 1
        ";
        $available_set_ids = $wpdb->get_col($sql_set);

        $available_variant_post_ids_duplicate_filtered = [];
        // variants duplicate check
        if ($primary_division_id) {
            $primary_division_id = intval($primary_division_id);
            $division_duplicate_sql1 = " AND rental_division_id = $primary_division_id";
            $division_duplicate_sql2 = " AND (rental_division_id <> $primary_division_id AND pm2.meta_value <> 1 )";

            $sql1 = "
                select
                    id
                from 
                    {$rental_variant_relations} variant_rel
                {$left_join_post_meta_variant_duplicate}
                {$left_join_post_meta_variant}
                where
                    pm.meta_value = 'instock'
                    $division_duplicate_sql1
            ";
            $available_variant_post_ids_1 = $wpdb->get_col($sql1);

            $sql2 = "
                select
                    id
                from 
                    {$rental_variant_relations} variant_rel
                {$left_join_post_meta_variant_duplicate}
                {$left_join_post_meta_variant}
                where
                    pm.meta_value = 'instock'
                    $division_duplicate_sql2
            ";
            $available_variant_post_ids_2 = $wpdb->get_col($sql2);

            $available_variant_post_ids_duplicate_filtered = array_merge($available_variant_post_ids_1, $available_variant_post_ids_2);
        }

        $available_variant_post_ids_duplicate_filtered_formatted = implode(',',$available_variant_post_ids_duplicate_filtered);
        $unavailable_ids_all_query = "";
        if ($unavailable_ids_all) {
            $unavailable_ids_all_query = "AND ID NOT IN ($unavailable_ids_all)";
        }

        $available_variant_ids_duplicate_filtered = [];
        if ($available_variant_post_ids_duplicate_filtered) {
            $sql = "
                select
                    *
                from 
                    $wpdb->posts
                where
                    post_status = 'publish'
                    AND ID IN ($available_variant_post_ids_duplicate_filtered_formatted)
                    $unavailable_ids_all_query
            ";
            $available_variant_ids_duplicate_filtered = $wpdb->get_col($sql);
        }

        $available_product_post_ids_duplicate_filtered = [];
        // products duplicate check
        if ($primary_division_id) {
            $primary_division_id = intval($primary_division_id);
            $division_duplicate_sql1 = " AND rental_division_id = $primary_division_id";
            $division_duplicate_sql2 = " AND (rental_division_id <> $primary_division_id AND pm2.meta_value <> 1 )";

            $sql1 = "
                select
                    id
                from 
                    {$rental_product_relations} product_rel
                {$left_join_post_meta_product_duplicate}
                {$left_join_post_meta_product}
                where
                    pm.meta_value = 'instock'
                    $division_duplicate_sql1
            ";
            $available_product_post_ids_1 = $wpdb->get_col($sql1);

            $sql2 = "
                select
                    id
                from 
                    {$rental_product_relations} product_rel
                {$left_join_post_meta_product_duplicate}
                {$left_join_post_meta_product}
                where
                    pm.meta_value = 'instock'
                    $division_duplicate_sql2
            ";
            $available_product_post_ids_2 = $wpdb->get_col($sql2);

            $available_product_post_ids_duplicate_filtered = array_merge($available_product_post_ids_1, $available_product_post_ids_2);
        }

        $available_product_post_ids_duplicate_filtered_formatted = implode(',',$available_product_post_ids_duplicate_filtered);
        $unavailable_ids_all_query = "";
        if ($unavailable_ids_all) {
            $unavailable_ids_all_query = "AND ID NOT IN ($unavailable_ids_all)";
        }
        $available_product_ids_duplicate_filtered = [];
        if ($available_product_post_ids_duplicate_filtered) {
            $sql = "
                select
                    *
                from 
                    $wpdb->posts
                where
                    post_status = 'publish'
                    AND ID IN ($available_product_post_ids_duplicate_filtered_formatted)
                    $unavailable_ids_all_query
            ";
            $available_product_ids_duplicate_filtered = $wpdb->get_col($sql);
        }

        $available_ids_duplicate_filtered = array_unique(array_merge($available_product_ids_duplicate_filtered, $available_variant_ids_duplicate_filtered, $available_set_ids));
    }

    $rntp_dup_memo[ $rntp_dup_key ] = $available_ids_duplicate_filtered;

    // set_rental_cache($available_ids_duplicate_filtered, 'rental_available_ids_duplicate_filtered', 'rental_available_ids_duplicate_filtered_expiration_date');

    return $available_ids_duplicate_filtered;
}

// check product availability in single product page
function wp_ajax_rental_check_product_availability() {

	// The availability service isn't loaded -> indeterminate. The script leaves the
	// (already-disabled) button untouched for any value other than 1 or 0, so this
	// neither enables a button prematurely nor shows a false error.
	if ( ! class_exists( 'Rentopian_Availability_Filter' ) ) {
		wp_send_json( array( 'is_available' => 2 ) );
	}

	$filter = Rentopian_Availability_Filter::instance();

	// Availability filtering disabled in settings -> never block on the product page.
	// (Enforcement of blocks/division is independent of the overbooking setting.)
	if ( ! $filter->is_active() ) {
		wp_send_json( array( 'is_available' => 1 ) );
	}

	$product_id = isset( $_POST['product_id'] ) ? intval( $_POST['product_id'] ) : 0;
	if ( ! $product_id ) {
		wp_send_json( array( 'is_available' => 2 ) );
	}

	wp_send_json(
		array(
			'is_available' => $filter->item_availability_code( $product_id ),
		)
	);
}

// set product catalog visibility
function rental_set_product_catalog_visibility($product_id, $visibility) {
    $product = wc_get_product($product_id);

    if ($product) {
        
        $product->set_catalog_visibility($visibility);

        $product->save();
    }
}

// set product status
function rental_set_product_general_visibility($product_id, $visibility) {
    wp_update_post([
        'ID'            => $product_id,
        'post_status'   => $visibility,
    ]);
}

function encrypt_data($data, $key) {
    return $data;

    $cipher = "aes-256-cbc";
    $iv_length = openssl_cipher_iv_length($cipher);
    $iv = openssl_random_pseudo_bytes($iv_length);

    // Ensure IV is exactly the required length
    if (strlen($iv) < $iv_length) {
        $iv = str_pad($iv, $iv_length, "\0");
    }

    $encrypted_data = openssl_encrypt(serialize($data), $cipher, $key, 0, $iv);
    return base64_encode($iv . $encrypted_data);
}

function decrypt_data($encrypted_data, $key) {
    return $encrypted_data;

    $cipher = "aes-256-cbc";
    $data = base64_decode($encrypted_data);
    $iv_length = openssl_cipher_iv_length($cipher);

    $iv = substr($data, 0, $iv_length);
    $encrypted_data = substr($data, $iv_length);

    // Ensure IV is exactly the required length
    if (strlen($iv) < $iv_length) {
        $iv = str_pad($iv, $iv_length, "\0");
    }

    $decrypted_data = openssl_decrypt($encrypted_data, $cipher, $key, 0, $iv);
    return unserialize($decrypted_data);
}

function get_variant_data($product_id, $variant_id) {
   
    $product = wc_get_product($product_id);
    $variation = new WC_Product_Variation($variant_id);

    $item_custom_price = null;
    $calculate_one_day_price = true;
    $get_regular_price = true;

    $data = [];
    if (!$variation || $variation->get_parent_id() != $product_id) {

        $data = [
            'name'      => $product->get_name(),  
            'item_url'  => $product->get_permalink(), 
            'image_url' => $product->get_image('medium'),  
            'product_id' => $product_id,
            'variant_id' => $variant_id,
            'price' => rental_calculate_rental_item_price($product_id, $item_custom_price, $calculate_one_day_price, $get_regular_price)
        ];

        return $data;
    }

    $variation_attributes = $variation->get_attributes();
    $variation_attributes_data = [];
    if ($variation_attributes) {
        foreach ($variation_attributes as $attribute_name => $attribute_value) {
            
            $attribute_label = wc_attribute_label($attribute_name);
            $attribute_term_name = get_term_by('slug', $attribute_value, $attribute_name)->name;
            
            $variation_attributes_data[] = esc_html($attribute_label) . ': ' . esc_html($attribute_term_name);
        }
    }

    // Get variant data
    $data = [
        'name'      => $variation->get_title(),  
        'item_url'  => $variation->get_permalink(), 
        'image_url' => $variation->get_image('medium'), 
        'attrs' => $variation_attributes_data, 
        'product_id' => $product_id,
        'variant_id' => $variant_id,
        'price' => rental_calculate_rental_item_price($variant_id, $item_custom_price, $calculate_one_day_price, $get_regular_price)
        // 'quantity'  => $variation->get_stock_quantity(),  // Stock quantity
        // 'image_url' => wp_get_attachment_url($variation->get_image_id()),  // Image URL
    ];

    return $data;
}

function wp_ajax_rental_update_set_items() {

    if (!isset($_POST["set_id"]) || !isset($_POST["product_id"]) || !isset($_POST["variant_id"])) {
        wp_send_json(['error' => 'The request is not valid'], 400);
        wp_die();
    }

    wp_send_json( Rental_Sets_Selection::select_optional_item(
        intval($_POST["set_id"]),
        intval($_POST["product_id"]),
        intval($_POST["variant_id"])
    ) );
    wp_die();
}


function wp_ajax_rental_update_set_items_addon_with_variants_optional() {

    if (!isset($_POST["set_id"]) || !isset($_POST["product_id"]) || !isset($_POST["variant_id"])
        || !isset($_POST["set_item_id"]) || !isset($_POST["rental_inv_id"])
    ) {
        wp_send_json(['error' => 'The request is not valid'], 400);
        wp_die();
    }

    wp_send_json( Rental_Sets_Selection::select_addon_variant(
        intval($_POST["set_id"]),
        intval($_POST["set_item_id"]),
        intval($_POST["product_id"]),
        intval($_POST["variant_id"]),
        intval($_POST["rental_inv_id"])
    ) );
    wp_die();
}

// using this function when there are hidden addons with optional variants 
function wp_ajax_rental_set_items_addons_with_variants_optional_default_update() {

    if (!isset($_POST["set_id"])) {
        wp_send_json(['error' => 'The request is not valid'], 400);
        wp_die();
    }

    wp_send_json( Rental_Sets_Selection::apply_addon_defaults( intval($_POST["set_id"]) ) );
    wp_die();
}


function store_set_ids_with_optional_items($id, $item_product_id, $selected_variant_id) {
    
    $stored_ids = get_option('_rental_set_ids_with_optional_items', []);

    if ($selected_variant_id) {
        if (!isset($stored_ids[$id][$item_product_id])) {
            $stored_ids[$id][$item_product_id] = 0;
        }
    
        if ($stored_ids[$id][$item_product_id] != $selected_variant_id) {
            $stored_ids[$id][$item_product_id] = $selected_variant_id;
        }

    } else {

        $stored_ids[$id][$item_product_id] = $item_product_id;
    }

    update_option('_rental_set_ids_with_optional_items', $stored_ids);
}

function get_set_ids_with_optional_items() {
    if ($stored_ids = get_option('_rental_set_ids_with_optional_items', [])) {
        return $stored_ids;
    }

    return [];
}


/**
 * [DEPRECATED] wp_ajax_rental_calculate_set_price_based_on_items_price
 *
 * Retired in favor of the JS-only total pipeline:
 *
 *   - Bottom panel:  rental-sets-modern.js -> updateSummary()
 *   - Top panel:     rental-sets-modern.js -> writeTopTotal()
 *
 * Both surfaces read the SAME computation (a single sum in
 * updateSummary), guaranteeing parity. The legacy AJAX path mutated
 * `_price` on the set product as a side effect of a pricing lookup,
 * wrote into `.entry-price-wrap` with a stale number when its
 * response landed after a customer interaction race, and duplicated
 * child-pricing logic that Rental_Sets_Price_Engine already owns.
 *
 * The implementation below returns a benign deprecated marker so any
 * stray third-party caller that invokes the function name directly
 * doesn't fatal. The original implementation is preserved further
 * down inside a PHP block comment for grep-able provenance — paste it
 * back out and re-register in rentopian-sync.php (~line 251) to
 * restore the legacy path.
 */
function wp_ajax_rental_calculate_set_price_based_on_items_price() {
    wp_send_json( array(
        'msg'            => 'deprecated',
        'set_total'      => 0,
        'set_total_html' => '',
    ), 200 );
    wp_die();
}
/*
function _DEPRECATED_wp_ajax_rental_calculate_set_price_based_on_items_price() {
    if (!isset($_POST["set_id"])) {
        wp_send_json(['error' => 'The request is not valid'], 400);
        wp_die();
    }

    $set_id = intval($_POST["set_id"]);

    $item_based_total = get_post_meta($set_id, '_rental_item_based_total', true);
    if (!$item_based_total) {
        wp_send_json(['msg' => 'The Set\'s price calculation is not based on items total'], 400);
        wp_die();
    }
    

    $set_total_price = 0;
    $current_set_items = rental_set_items_resolved($set_id);

    foreach($current_set_items as $key => $current_set_item) {

        $optional_items_count = isset($current_set_item['optional_items']) && $current_set_item['optional_items'] ? count($current_set_item['optional_items']) : 0;
        $select_the_only_one_option = $optional_items_count == 1 ? true : false;

        // items with optional items (variants)
        if (
            isset($current_set_item['optional_items'])
            && $current_set_item['optional_items']
            && $current_set_item['optional_item_price_update_needed'] == 2 // the price is already set
            &&
            (
                $current_set_item['has_selected'] == 1
                || $select_the_only_one_option
            )
        ) {

            $opt_items = $current_set_items[$key]['optional_items'];
            foreach($opt_items as $opt_item_key => $opt_item) {
                
                if ($select_the_only_one_option) {
                    
                    if ($opt_item_key == 0) {
                        if ($opt_item['variant_id']) {

                            $optional_item_price = rental_calculate_rental_item_price($opt_item['variant_id'], null, 1);
                            $set_total_price += $optional_item_price;

                        } else {
        
                            if ($opt_item['product_id']) {
                                
                                $optional_item_price = rental_calculate_rental_item_price($opt_item['product_id'], null, 1);
                                $set_total_price += $optional_item_price;
                            }
                        }
                    }

                } else {

                    if ($opt_item['is_selected'] == 1) {

                        if ($opt_item['variant_id']) {

                            if ($opt_item['variant_id'] == $current_set_item['variant_id']) {

                                $optional_item_price = rental_calculate_rental_item_price($opt_item['variant_id'], null, 1);
                                $set_total_price += $optional_item_price;
                            }
        
                        } else {
        
                            if ($opt_item['product_id']) {

                                if ($opt_item['product_id'] == $current_set_item['product_id']) {
                                    
                                    $optional_item_price = rental_calculate_rental_item_price($opt_item['product_id'], null, 1);
                                    $set_total_price += $optional_item_price;
                                }
                            }
                        }
                    }

                }
            }
        }

        // simple items
        if (
            ($current_set_item['product_id'] || $current_set_item['variant_id'])
            && $current_set_item['price']
        ) {

            $set_total_price += rental_calculate_rental_item_price($current_set_item['variant_id'] ? $current_set_item['variant_id'] : $current_set_item['product_id'], $current_set_item['price']);
        }

        // addons
        if (
            isset($current_set_item['addons'])
            && $current_set_item['addons']
        ) {

            foreach ($current_set_item['addons'] as $item_addon_key => $item_addon) {
                
                $custom_price = $item_addon['inherit_price'] ? null : $item_addon['price'];

                if (
                    isset($item_addon['variants_optional'])
                    && $item_addon['variants_optional']
                ) {

                    $item_addon_optional_items = $item_addon['variants_optional'];
                    $count_item_addon_variants_optional = 0;
                    if (!empty($item_addon_optional_items)) {
                        $count_item_addon_variants_optional = count($item_addon['variants_optional']);
                    }

                    if (
                        (isset($item_addon['has_selected'])
                        && $item_addon['has_selected'] == 1)
                        || (isset($item_addon['already_selected'])
                        && $item_addon['already_selected'] == 1)
                    ) {
                        
                       
                        foreach($item_addon_optional_items as $item_addon_optional_item_key => $item_addon_optional_item) {
    
                            if ($item_addon_optional_item['variant_id']) {
    
                                if ($item_addon_optional_item['is_selected'] == 1) {
                                    
                                    $optional_item_price = rental_calculate_rental_item_price($item_addon_optional_item['variant_id'], $custom_price);
                                    $set_total_price += $optional_item_price;
                                
                                }
                            } 
                            else {
            
                                if ($item_addon_optional_item['product_id']) {
            
                                    if ($item_addon_optional_item['is_selected'] == 1) {
            
                                        $optional_item_price = rental_calculate_rental_item_price($item_addon_optional_item['product_id'], $custom_price);
                                        $set_total_price += $optional_item_price;
                                    
                                    } 
                                }
                            }
    
                        }

                    } else if ($count_item_addon_variants_optional < 2) {

                        foreach($item_addon_optional_items as $item_addon_optional_item_key => $item_addon_optional_item) {
    
                            if ($item_addon_optional_item['variant_id']) {
    
                                if ($item_addon_optional_item_key == 0) {
                                    
                                    $optional_item_price = rental_calculate_rental_item_price($item_addon_optional_item['variant_id'], $custom_price);
                                    $set_total_price += $optional_item_price;
                                
                                }
            
                            } 
                            else {
            
                                if ($item_addon_optional_item['product_id']) {
            
                                    if ($item_addon_optional_item_key == 0) {
            
                                        $optional_item_price = rental_calculate_rental_item_price($item_addon_optional_item['product_id'], $custom_price);
                                        $set_total_price += $optional_item_price;
                                    
                                    }
                                }
                            }
    
                        }
                    }
                
                } else {

                    if ($item_addon['product_id'] || $item_addon['variant_id']) {
                        // simple addons (no variants optional)
                        $item_addon_price = rental_calculate_rental_item_price($item_addon['variant_id'] ? $item_addon['variant_id'] : $item_addon['product_id'], $custom_price);
                        $set_total_price += $item_addon_price;
                    }
                }

               
            }
        }

    }

    // align the product-page display total with the cart total.
    $rental_engine_parent_unit = null;
    if (class_exists('Rental_Sets_Price_Engine', false)) {
        $rental_engine_parent_unit = Rental_Sets_Price_Engine::parent_unit_price((int) $set_id);
        if (is_numeric($rental_engine_parent_unit) && $rental_engine_parent_unit > 0) {
            $set_total_price += (float) $rental_engine_parent_unit;
        }
    }

    if ($set_total_price) {
        // update_post_meta($set_id, '_price', $set_total_price);
        // will calculate the set total based on each item price
        update_post_meta($set_id, '_price', 0);
    }

    wp_send_json(['msg' => 'success', 'set_total' => $set_total_price, 'set_total_html' => wc_price( $set_total_price ),
        // 'current_set_items' => $current_set_items
    ]
    , 200);
    wp_die();
}
END_DEPRECATED_BODY*/


/* Sets Items' Optional Items default selection for hidden set items
*  description : rental set items' optional items' default selection for when there is
* '_rental_hide_items_on_website' setting enabled for a specific set
*
* DEPRECATED. Superseded by rental_set_apply_hidden_defaults(), which
* resolves hidden-item defaults server-side at add-to-cart for both classic
* and modern sets — no page-load AJAX required. The front-end no longer
* calls this endpoint; it is kept registered only for backward compatibility
* with any cached/legacy script. The server-side resolver is authoritative.
*/
function wp_ajax_rental_set_items_default_update() {

    if (!isset($_POST["set_id"])) {
        wp_send_json(['error' => 'The request is not valid'], 400);
        wp_die();
    }

    $set_id = intval($_POST["set_id"]);

    // if (!get_post_meta($set_id, '_rental_set_items_have_optional_items', true)) {
    //     wp_send_json(['success' => 'There are no optional items!']);
    //     wp_die();
    // }

    if (
        !get_post_meta($set_id, '_rental_hide_items_on_website', true) 
        && !get_post_meta($set_id, '_rental_some_hidden_items', true)
        && !get_option('rental_hide_set_items', 0)
    ) {
        wp_send_json(['success' => 'There are no hidden items!']);
        wp_die();
    }
  
    $current_set_items = rental_set_items_resolved($set_id);
  

    if (get_post_meta($set_id, '_rental_hide_items_on_website', true) || get_option('rental_hide_set_items', 0)) {
        // hiding all the set items

        foreach($current_set_items as $key => $current_set_item) {

            if (
                get_post_meta($set_id, '_rental_set_items_have_optional_items', true)
                && isset($current_set_item['optional_items'])
                && $current_set_item['optional_items']
                && $current_set_item['has_selected'] != 1
            ) {
    
                $default_variant_id = 0;
                $default_product_id = 0;
    
                $opt_items = $current_set_item['optional_items'];
                foreach($opt_items as $opt_item_key => $opt_item) {
                    
                    if ($opt_item['variant_id']) {
    
                        if ($opt_item_key == 0) {
    
                            $opt_items[$opt_item_key]['is_selected'] = 1;
    
                            $current_set_items[$key]['has_selected'] = 1;
                            $current_set_items[$key]['variant_id'] = $opt_item['variant_id'];
    
                            $default_variant_id = $opt_item['variant_id'];
                            $default_product_id = $opt_item['product_id'];
    
                            $optional_item_price = rental_calculate_rental_item_price($default_variant_id);
        
                            $opt_items[$opt_item_key]['price'] = $optional_item_price;
                            
                            $current_set_items[$key]['price'] = $optional_item_price;
                            $current_set_items[$key]['attribute'] = $optional_item_price;

                            // needed data to create an order for rentopian
                            $current_set_items[$key]['rental_variant_id'] = $opt_item['rental_variant_id'] ?? 0;
                            $current_set_items[$key]['rental_product_id'] = $opt_item['rental_product_id'] ?? 0;
                        
                        } else {
    
                            $opt_items[$opt_item_key]['is_selected'] = 0;
                            $opt_items[$opt_item_key]['price'] = 0;
                        }
    
                    } else {
    
                        if ($opt_item['product_id']) {
    
                            if ($opt_item_key == 0) {
    
                                $opt_items[$opt_item_key]['is_selected'] = 1;
        
                                $current_set_items[$key]['has_selected'] = 1;
                                $current_set_items[$key]['variant_id'] = $opt_item['product_id'];
    
                                $default_variant_id = $opt_item['product_id'];
                                $default_product_id = $opt_item['product_id'];
        
                                $optional_item_price = rental_calculate_rental_item_price($default_variant_id);
            
                                $opt_items[$opt_item_key]['price'] = $optional_item_price;
                                
                                $current_set_items[$key]['price'] = $optional_item_price;
                                $current_set_items[$key]['attribute'] = $optional_item_price;

                                // needed data to create an order for rentopian
                                $current_set_items[$key]['rental_variant_id'] = $opt_item['rental_variant_id'] ?? 0;
                                $current_set_items[$key]['rental_product_id'] = $opt_item['rental_product_id'] ?? 0;
        
                            } else {
                                
                                $opt_items[$opt_item_key]['is_selected'] = 0;
                                $opt_items[$opt_item_key]['price'] = 0;
                            }
    
                        }
                    }
                   
                }
    
                $current_set_items[$key]['optional_items'] = $opt_items;
    
                store_set_ids_with_optional_items($set_id, $default_product_id, $default_variant_id);
            }



            // checking/processing hidden addons of set items
            if (
                isset($current_set_item['addons'])
                && $current_set_item['addons']
            ) {
    
                foreach ($current_set_item['addons'] as $item_addon_key => $item_addon) {
                    
                    if (
                        isset($item_addon['variants_optional'])
                        && $item_addon['variants_optional']
                        // && isset($item_addon['hidden']) 
                        // && $item_addon['hidden']
                    ) {
    
                        if (
                            (isset($item_addon['has_selected'])
                            && $item_addon['has_selected'] == 1)
                            || (isset($item_addon['already_selected'])
                            && $item_addon['already_selected'] == 1)
                        ) {
                            continue;
                        }
    
                            
                        $current_set_item['addons'][$item_addon_key]['has_selected'] = 1;
                        $current_set_item['addons'][$item_addon_key]['already_selected'] = 1;
    
                        $item_addon_optional_items = $item_addon['variants_optional'];
                        foreach($item_addon_optional_items as $item_addon_optional_item_key => $item_addon_optional_item) {
    
                            if ($item_addon_optional_item['variant_id']) {
    
                                if ($item_addon_optional_item_key == 0) {
                                    
                                    $optional_item_price = rental_calculate_rental_item_price($item_addon_optional_item['variant_id']);
    
                                    $item_addon_optional_items[$item_addon_optional_item_key]['is_selected'] = 1;
                                    $item_addon_optional_items[$item_addon_optional_item_key]['price'] = $optional_item_price;
    
                                    $current_set_item['addons'][$item_addon_key]['variant_id'] = $item_addon_optional_item['variant_id'];
                                    $current_set_item['addons'][$item_addon_key]['product_id'] = $item_addon_optional_item['product_id'];

                                    // needed data to create an order for rentopian
                                    $current_set_item['addons'][$item_addon_key]['rental_variant_id'] = $item_addon_optional_item['rental_variant_id'] ?? 0;
                                    $current_set_item['addons'][$item_addon_key]['rental_product_id'] = $item_addon_optional_item['rental_product_id'] ?? 0;
                                
                                } else {
    
                                    $item_addon_optional_items[$item_addon_optional_item_key]['is_selected'] = 0;
                                    $item_addon_optional_items[$item_addon_optional_item_key]['price'] = 0;
                                }
            
                            } 
                            else {
        
                                if ($item_addon_optional_item['product_id']) {
            
                                    if ($item_addon_optional_item_key == 0) {
            
                                        $optional_item_price = rental_calculate_rental_item_price($item_addon_optional_item['product_id']);
    
                                        $item_addon_optional_items[$item_addon_optional_item_key]['is_selected'] = 1;
                                        $item_addon_optional_items[$item_addon_optional_item_key]['price'] = $optional_item_price;
                                    
                                        $current_set_item['addons'][$item_addon_key]['variant_id'] = $item_addon_optional_item['product_id'];
                                        $current_set_item['addons'][$item_addon_key]['product_id'] = $item_addon_optional_item['product_id'];

                                        // needed data to create an order for rentopian
                                        $current_set_item['addons'][$item_addon_key]['rental_variant_id'] = $item_addon_optional_item['rental_variant_id'] ?? 0;
                                        $current_set_item['addons'][$item_addon_key]['rental_product_id'] = $item_addon_optional_item['rental_product_id'] ?? 0;
                                    
                                    } else {
    
                                        $item_addon_optional_items[$item_addon_optional_item_key]['is_selected'] = 0;
                                        $item_addon_optional_items[$item_addon_optional_item_key]['price'] = 0;
                                    }
            
                                }
                            }
                        }
    
                        $current_set_item['addons'][$item_addon_key]['variants_optional'] = $item_addon_optional_items;
                    
                    } else {
    
                        if (!isset($current_set_item['addons'][$item_addon_key]['already_selected']) || (isset($current_set_item['addons'][$item_addon_key]['already_selected']) && $current_set_item['addons'][$item_addon_key]['already_selected'] != 1)) {
                            $current_set_item['addons'][$item_addon_key]['has_selected'] = 0;
                        }
                    }
    
                   
                }
    
                $current_set_items[$key]['addons'] = $current_set_item['addons'];
    
            }
            
        }

    }



    if (
        ( !get_post_meta($set_id, '_rental_hide_items_on_website', true) && !get_option('rental_hide_set_items', 0) )
        && get_post_meta($set_id, '_rental_some_hidden_items', true)
    ) {
        // hiding some of the set items based on the "hidden flagged" items  

        foreach($current_set_items as $key => $current_set_item) {

            if (
                isset($current_set_item['hidden'])
                && $current_set_item['hidden']
            ) {
        

                if (
                    get_post_meta($set_id, '_rental_set_items_have_optional_items', true)
                    && isset($current_set_item['optional_items'])
                    && $current_set_item['optional_items']
                    && $current_set_item['has_selected'] != 1
                ) {
        
                    $default_variant_id = 0;
                    $default_product_id = 0;
        
                    $opt_items = $current_set_item['optional_items'];
                    foreach($opt_items as $opt_item_key => $opt_item) {
                        
                        if ($opt_item['variant_id']) {
        
                            if ($opt_item_key == 0) {
        
                                $opt_items[$opt_item_key]['is_selected'] = 1;
        
                                $current_set_items[$key]['has_selected'] = 1;
                                $current_set_items[$key]['variant_id'] = $opt_item['variant_id'];
        
                                $default_variant_id = $opt_item['variant_id'];
                                $default_product_id = $opt_item['product_id'];
        
                                $optional_item_price = rental_calculate_rental_item_price($default_variant_id);
            
                                $opt_items[$opt_item_key]['price'] = $optional_item_price;
                                
                                $current_set_items[$key]['price'] = $optional_item_price;
                                $current_set_items[$key]['attribute'] = $optional_item_price;

                                // needed data to create an order for rentopian
                                $current_set_items[$key]['rental_variant_id'] = $opt_item['rental_variant_id'] ?? 0;
                                $current_set_items[$key]['rental_product_id'] = $opt_item['rental_product_id'] ?? 0;
                            
                            } else {
        
                                $opt_items[$opt_item_key]['is_selected'] = 0;
                                $opt_items[$opt_item_key]['price'] = 0;
                            }
        
                        } else {
        
                            if ($opt_item['product_id']) {
        
                                if ($opt_item_key == 0) {
        
                                    $opt_items[$opt_item_key]['is_selected'] = 1;
            
                                    $current_set_items[$key]['has_selected'] = 1;
                                    $current_set_items[$key]['variant_id'] = $opt_item['product_id'];
        
                                    $default_variant_id = $opt_item['product_id'];
                                    $default_product_id = $opt_item['product_id'];
            
                                    $optional_item_price = rental_calculate_rental_item_price($default_variant_id);
                
                                    $opt_items[$opt_item_key]['price'] = $optional_item_price;
                                    
                                    $current_set_items[$key]['price'] = $optional_item_price;
                                    $current_set_items[$key]['attribute'] = $optional_item_price;

                                    // needed data to create an order for rentopian
                                    $current_set_items[$key]['rental_variant_id'] = $opt_item['rental_variant_id'] ?? 0;
                                    $current_set_items[$key]['rental_product_id'] = $opt_item['rental_product_id'] ?? 0;
            
                                } else {
                                    
                                    $opt_items[$opt_item_key]['is_selected'] = 0;
                                    $opt_items[$opt_item_key]['price'] = 0;
                                }
        
                            }
                        }
                    
                    }
        
                    $current_set_items[$key]['optional_items'] = $opt_items;
        
                    store_set_ids_with_optional_items($set_id, $default_product_id, $default_variant_id);
                }


                // checking/processing hidden addons of set items
                if (
                    isset($current_set_item['addons'])
                    && $current_set_item['addons']
                ) {
        
                    foreach ($current_set_item['addons'] as $item_addon_key => $item_addon) {
                        
                        if (
                            isset($item_addon['variants_optional'])
                            && $item_addon['variants_optional']
                            // && isset($item_addon['hidden']) 
                            // && $item_addon['hidden']
                        ) {
        
                            if (
                                (isset($item_addon['has_selected'])
                                && $item_addon['has_selected'] == 1)
                                || (isset($item_addon['already_selected'])
                                && $item_addon['already_selected'] == 1)
                            ) {
                                continue;
                            }
        
                                
                            $current_set_item['addons'][$item_addon_key]['has_selected'] = 1;
                            $current_set_item['addons'][$item_addon_key]['already_selected'] = 1;
        
                            $item_addon_optional_items = $item_addon['variants_optional'];
                            foreach($item_addon_optional_items as $item_addon_optional_item_key => $item_addon_optional_item) {
        
                                if ($item_addon_optional_item['variant_id']) {
        
                                    if ($item_addon_optional_item_key == 0) {
                                        
                                        $optional_item_price = rental_calculate_rental_item_price($item_addon_optional_item['variant_id']);
        
                                        $item_addon_optional_items[$item_addon_optional_item_key]['is_selected'] = 1;
                                        $item_addon_optional_items[$item_addon_optional_item_key]['price'] = $optional_item_price;
        
                                        $current_set_item['addons'][$item_addon_key]['variant_id'] = $item_addon_optional_item['variant_id'];
                                        $current_set_item['addons'][$item_addon_key]['product_id'] = $item_addon_optional_item['product_id'];

                                        // needed data to create an order for rentopian
                                        $current_set_item['addons'][$item_addon_key]['rental_variant_id'] = $item_addon_optional_item['rental_variant_id'] ?? 0;
                                        $current_set_item['addons'][$item_addon_key]['rental_product_id'] = $item_addon_optional_item['rental_product_id'] ?? 0;
                                    
                                    } else {
        
                                        $item_addon_optional_items[$item_addon_optional_item_key]['is_selected'] = 0;
                                        $item_addon_optional_items[$item_addon_optional_item_key]['price'] = 0;
                                    }
                
                                } 
                                else {
        
                                    if ($item_addon_optional_item['product_id']) {
                
                                        if ($item_addon_optional_item_key == 0) {
                
                                            $optional_item_price = rental_calculate_rental_item_price($item_addon_optional_item['product_id']);
        
                                            $item_addon_optional_items[$item_addon_optional_item_key]['is_selected'] = 1;
                                            $item_addon_optional_items[$item_addon_optional_item_key]['price'] = $optional_item_price;
                                        
                                            $current_set_item['addons'][$item_addon_key]['variant_id'] = $item_addon_optional_item['product_id'];
                                            $current_set_item['addons'][$item_addon_key]['product_id'] = $item_addon_optional_item['product_id'];
                                        
                                            // needed data to create an order for rentopian
                                            $current_set_item['addons'][$item_addon_key]['rental_variant_id'] = $item_addon_optional_item['rental_variant_id'] ?? 0;
                                            $current_set_item['addons'][$item_addon_key]['rental_product_id'] = $item_addon_optional_item['rental_product_id'] ?? 0;
                                       
                                        } else {
        
                                            $item_addon_optional_items[$item_addon_optional_item_key]['is_selected'] = 0;
                                            $item_addon_optional_items[$item_addon_optional_item_key]['price'] = 0;
                                        }
                
                                    }
                                }
                            }
        
                            $current_set_item['addons'][$item_addon_key]['variants_optional'] = $item_addon_optional_items;
        
                            
                        
                        } else {
        
                            if (!isset($current_set_item['addons'][$item_addon_key]['already_selected']) || (isset($current_set_item['addons'][$item_addon_key]['already_selected']) && $current_set_item['addons'][$item_addon_key]['already_selected'] != 1)) {
                                $current_set_item['addons'][$item_addon_key]['has_selected'] = 0;
                            }
                        }
        
                    
                    }
        
                    $current_set_items[$key]['addons'] = $current_set_item['addons'];
        
                }

            }

        }
    }
    

    rental_set_items_store_override($set_id, $current_set_items);
    
    wp_send_json(['set\'s hidden optional items (and addons with hidden optional items) processed' => true]);
    wp_die();
}


/* Sets Items' Optional Items already selected action 
*  description : rental set items' optional items' already selected action for when there are
*  selectable items that have already a selected item (or set items with only one option) from the Rentopian system
*/
function wp_ajax_rental_update_set_already_selected_items() {

    if (!isset($_POST["set_id"])) {
        wp_send_json(['error' => 'The request is not valid'], 400);
        wp_die();
    }

    wp_send_json( Rental_Sets_Selection::refresh_already_selected( intval($_POST["set_id"]) ) );
    wp_die();
}

function wp_ajax_rental_product_with_addons_fix_variants() {

    if (!isset($_POST["product_id"])) {
        wp_send_json(['error' => 'The request is not valid'], 400);
        wp_die();
    }

    $product_id = intval($_POST["product_id"]);
    if (!$add_ons = get_post_meta($product_id, '_rental_add_ons', true)) {
        wp_send_json(['success' => 'The product has no addons!']);
        wp_die();
    }

    foreach($add_ons as $key => $add_on) {

        $variant_id = 0;
        if ($add_on['variant_id'] != 0 ) {
            continue;
        }

        if (empty($add_on['variant_id']) && isset($add_on['rental_variant_id']) && $add_on['rental_variant_id'] != 0) {
            global $wpdb, $rental_tables;
            $rental_variant_relations = $wpdb->prefix . $rental_tables["variant_relations"];

            $variant_id = $wpdb->get_var("SELECT `id` FROM $rental_variant_relations WHERE `rental_id` = ".$add_on['rental_variant_id']);
            $add_ons[$key]['variant_id'] = $variant_id ? $variant_id : 0;
        }

    }

    update_post_meta($product_id, '_rental_add_ons', $add_ons);

    wp_send_json(['addons\' variants fixed' => true]);
    wp_die();
}

function extract_address_parts($full_address_array) {
    $city = '';
    $state = '';
    $country = '';
    $zip = '';
    $address = '';
    $address_2 = '';

    foreach ($full_address_array as $item) {
        switch ($item['key']) {
            case 'city':
                $city = $item['value'];
                break;
            case 'state':
                $state = $item['value'];
                break;
            case 'country':
                $country = $item['value'];
                break;
            case 'zip':
                $zip = $item['value'];
                break;
            case 'address':
                $address = $item['value'];
                break;
            case 'address_2':
                $address_2 = $item['value'];
                break;
        }
    }

    return [
        'city' => $city,
        'state' => $state,
        'country' => $country,
        'zip' => $zip,
        'address' => $address,
        'address_2' => $address_2,
    ];
}

function wp_ajax_update_rental_shipping_cost() {
    if( 
        get_option('rental_show_location') 
        && isset($_COOKIE["rental_google_map_address"]) 
        && $_COOKIE["rental_google_map_address"] 
        && isset($_COOKIE["rental_address"]) 
        && $_COOKIE["rental_address"]
    ) {
        
        // Initialize the WooCommerce shipping methods
        do_action('woocommerce_shipping_init');

        // if (get_option('woocommerce_miles_based_settings')['enabled'] === 'yes') {

            // Include the shipping method class file if not autoloaded
            require_once RENTOPIAN_SYNC_PATH . '/includes/class-miles-based-shipping.php';
            if (!class_exists('Miles_Based_Shipping_Method')) {
                do_action('woocommerce_shipping_init');
            }
                
            $shipping_instance = new Miles_Based_Shipping_Method();

            $rental_google_map_address_cookie = stripslashes($_COOKIE["rental_google_map_address"]);
            $data = json_decode($rental_google_map_address_cookie, true);
            
            $extracted_address = extract_address_parts($data);
            $shipping_instance->set_destination_address($extracted_address['address'], $extracted_address['state'], $extracted_address['city'], $extracted_address['country'], $extracted_address['zip'], $extracted_address['address_2']);

            $shipping_rate = $shipping_instance->calculate_shipping([], true);
            $shipping_cost = $shipping_rate['cost'];

            wp_send_json([
                'shipping_label_cost' => $shipping_cost ? $shipping_rate['label'] . ' ' . wc_price($shipping_cost) : 'Not available',
            ], 200);
                
        // }
        
    }
    

    wp_die();
}

// function wp_ajax_rental_trigger_calculate_shipping() {
    
//     $delivery_settings = rental_get_delivery_settings();
//     if ($delivery_settings['enable_different_pickup_delivery_address_for_website']) {
        
//         require_once RENTOPIAN_SYNC_PATH . '/includes/class-miles-based-shipping.php';
//         if (!class_exists('Miles_Based_Shipping_Method')) {
//             do_action('woocommerce_shipping_init');
//         }
        
//         $shipping_instance = new Miles_Based_Shipping_Method();
//         $shipping_instance->calculate_shipping();

//         wp_send_json(['shipping_calculation_triggered' => true,], 200);
//     }
//     wp_die();
// }


function set_rental_cache($data_to_be_cached, $key, $expiration_key, $expiration_time = 0) {
    update_option($key, $data_to_be_cached);
    $expiration_date = $expiration_time ? $expiration_time : time() + (12 * 60 * 60); // 12 hours
    update_option($expiration_key, $expiration_date);
}

function get_rental_cache($key, $expiration_key) {
    $rental_data = get_option($key, []);
    $exp_date = get_option($expiration_key, 0);
    if ($rental_data && $exp_date > time()) {
        return $rental_data;
    }

    return false;
}

function rental_clear_cache() {
    delete_option("rental_products_variants_divisions");
    delete_option("rental_products_variants_divisions_expiration_date");
    
    delete_option("rental_sets_divisions");
    delete_option("rental_sets_divisions_expiration_date");
    
    delete_option("rental_addon_ids");
    delete_option("rental_addon_ids_expiration_date");

    delete_option("rental_available_ids_duplicate_filtered");
    delete_option("rental_available_ids_duplicate_filtered_expiration_date");

    delete_option("rental_product_variant_list_filter_av");
    delete_option("rental_product_variant_list_filter_av_expiration_date");

    delete_option("rental_product_variant_list_filter");
    delete_option("rental_product_variant_list_filter_expiration_date");
}


function rental_update_checkout_address_fields() {

    if (isset($_POST['address']) && isset($_POST['city']) && isset($_POST['state']) && isset($_POST['zip'])) {
        
        WC()->customer->set_billing_address_1(sanitize_text_field($_POST['address']));
        WC()->customer->set_billing_address_2(sanitize_text_field($_POST['address_2'] ?? ''));
        WC()->customer->set_billing_city(sanitize_text_field($_POST['city']));
        WC()->customer->set_billing_state(sanitize_text_field($_POST['state']));
        WC()->customer->set_billing_postcode(sanitize_text_field($_POST['zip']));
        WC()->customer->set_billing_country(sanitize_text_field($_POST['country']));

        WC()->customer->set_shipping_address_1(sanitize_text_field($_POST['address']));
        WC()->customer->set_shipping_address_2(sanitize_text_field($_POST['address_2'] ?? ''));
        WC()->customer->set_shipping_city(sanitize_text_field($_POST['city']));
        WC()->customer->set_shipping_state(sanitize_text_field($_POST['state']));
        WC()->customer->set_shipping_postcode(sanitize_text_field($_POST['zip']));
        WC()->customer->set_shipping_country(sanitize_text_field($_POST['country']));

        WC()->customer->save();

        wp_send_json_success();

    } else {
        
        wp_send_json_error('Address data missing');
    }
    wp_die();
}


function ensure_wc_session() {
    if ( WC()->session && !WC()->session->has_session() ) {
        WC()->session->set_customer_session_cookie(true);
    }
}

function get_new_unique_id() {
    if ( is_user_logged_in() ) {
        return 'cart_' . get_current_user_id(); 
    }

    if ( WC()->session ) {
        ensure_wc_session();

        $guest_cart_id = WC()->session->get('guest_cart_id');
    
        if ( ! $guest_cart_id ) {
            $guest_cart_id = 'guest_' . wp_generate_uuid4();
            WC()->session->set('guest_cart_id', $guest_cart_id);
        }

        return $guest_cart_id;
    }

    return 'guest_cart_id_fallback';
}

function set_rental_session_data($key, $value) {
    if ( WC()->session ) {
        ensure_wc_session();
        $unique_id = get_new_unique_id();
        $session_key = "{$key}_{$unique_id}";
        WC()->session->set($session_key, $value);


        $tracked_keys = WC()->session->get('tracked_rental_keys', []);
        $tracked_keys[] = $session_key;
        WC()->session->set('tracked_rental_keys', $tracked_keys);
    }
}

function get_rental_session_data($key, $default = null) {
    if ( WC()->session ) {
        ensure_wc_session();
        $unique_id = get_new_unique_id();
        return WC()->session->get("{$key}_{$unique_id}", $default);
    }
    return $default;
}

function delete_rental_session_data($key) {
    if ( WC()->session ) {
        ensure_wc_session();
        $unique_id = get_new_unique_id();
        WC()->session->set("{$key}_{$unique_id}", NULL);
        WC()->session->__unset("{$key}_{$unique_id}");
    }
}

/*
|--------------------------------------------------------------------------
| Set option selection store (per-user)
|--------------------------------------------------------------------------
| A set's `_rental_set_items` postmeta is the GLOBAL, read-only DEFINITION
| of the set. The customer's in-progress option selections must NEVER be
| written back to it — that postmeta is shared by every visitor, so a
| runtime write leaks one customer's choices into another's page, the
| cart, the order, and emails (the "send → approve → receive" mismatch).
|
| Instead, a customer's working selection lives in their own WC session as
| a full overlay of the items array, keyed per set. Reads that need the
| customer's current selection use rental_set_items_resolved(); everything
| else keeps reading the pristine postmeta definition. The overlay is the
| transient working copy — once the set is added to cart the selection is
| snapshotted onto the cart line (the durable source of truth) and the
| overlay is cleared.
*/
function rental_set_selection_session_key( $set_id ) {
    return Rental_Sets_Selection::session_key( $set_id );
}

/**
 * The set's items array resolved against the customer's session overlay.
 * Delegates to the Sets module; see Rental_Sets_Selection::resolve().
 *
 * @param int $set_id
 * @return array
 */
function rental_set_items_resolved( $set_id ) {
    return Rental_Sets_Selection::resolve( $set_id );
}

/**
 * Resolve default selections for HIDDEN set items at add-to-cart.
 * Delegates to Rental_Sets_Selection::apply_hidden_defaults().
 *
 * @param int   $set_id
 * @param array $items
 * @return array
 */
function rental_set_apply_hidden_defaults( $set_id, array $items ) {
    return Rental_Sets_Selection::apply_hidden_defaults( $set_id, $items );
}

/**
 * Delegates to Rental_Sets_Selection::resolve_selectable_default().
 *
 * @param array $optional_items
 * @return array|null
 */
function rental_set_resolve_selectable_default( array $optional_items ) {
    return Rental_Sets_Selection::resolve_selectable_default( $optional_items );
}

/**
 * Delegates to Rental_Sets_Selection::resolve_addon_default().
 *
 * @param array $variants_optional
 * @return array|null
 */
function rental_set_resolve_addon_default( array $variants_optional ) {
    return Rental_Sets_Selection::resolve_addon_default( $variants_optional );
}

/**
 * Persist the customer's working selection overlay to their session.
 * Delegates to Rental_Sets_Selection::store_override().
 *
 * @param int   $set_id
 * @param array $items
 * @return void
 */
function rental_set_items_store_override( $set_id, $items ) {
    Rental_Sets_Selection::store_override( $set_id, $items );
}

/**
 * Clear the customer's working selection overlay.
 * Delegates to Rental_Sets_Selection::clear_override().
 *
 * @param int $set_id
 * @return void
 */
function rental_set_items_clear_override( $set_id ) {
    Rental_Sets_Selection::clear_override( $set_id );
}

/**
 * Diagnostic trace for the Set/Product OPTION selection flow.
 *
 * The implementation lives in Rental_Options_Logger
 * (includes/product-options/). This wrapper stays because handlers across
 * functions.php, the manager and the WC integration call it by name.
 *
 * @param string $where   Short stage label.
 * @param array  $context key => value pairs (arrays are JSON-encoded).
 * @return void
 */
function rental_options_trace( $where, array $context = array() ) {
    if ( ! class_exists( 'Rental_Options_Logger' ) ) {
        return;
    }

    Rental_Options_Logger::trace( $where, $context );
}

/**
 * Once a set parent is committed to the cart, its selection is durably
 * snapshotted onto the cart line — so drop the per-user working overlay.
 * This keeps the next configuration starting clean and prevents a stale
 * overlay from leaking into a later page render.
 *
 * @return void
 */
function rental_set_clear_overlay_on_add( $cart_item_key, $product_id, $quantity, $variation_id, $variation, $cart_item_data ) {
    if ( ! empty( $cart_item_data['rental_add_on_of'] ) ) {
        return; // child line — only act on the set parent
    }
    if ( get_post_meta( (int) $product_id, '_rental_is_set', true ) ) {
        rental_set_items_clear_override( (int) $product_id );
    }
}
add_action( 'woocommerce_add_to_cart', 'rental_set_clear_overlay_on_add', 200, 6 );

/**
 * Guaranteed-fire diagnostic on add-to-cart: logs the OPTION-related keys
 * present in $_POST and what landed on the just-committed cart line, so we
 * can see exactly how options reach (or fail to reach) the cart snapshot
 * regardless of which option-specific hook fired.
 */
function rental_options_trace_on_add( $cart_item_key, $product_id, $quantity, $variation_id, $variation, $cart_item_data ) {
    if ( ! function_exists( 'rental_options_trace' ) ) {
        return;
    }
    if ( ! empty( $cart_item_data['rental_add_on_of'] ) ) {
        return; // child line
    }
    $post_keys = array();
    foreach ( array( 'rental_options', 'rental_options_post', 'rental_selected_set_options', 'rental_set_options', 'options' ) as $k ) {
        if ( isset( $_POST[ $k ] ) ) {
            $post_keys[ $k ] = is_array( $_POST[ $k ] ) ? wp_json_encode( $_POST[ $k ] ) : substr( (string) $_POST[ $k ], 0, 120 );
        }
    }
    $line_opts = array();
    if ( function_exists( 'WC' ) && WC() && WC()->cart && isset( WC()->cart->cart_contents[ $cart_item_key ] ) ) {
        $ci = WC()->cart->cart_contents[ $cart_item_key ];
        foreach ( array( 'rental_selected_set_options', 'rental_selected_options' ) as $ok ) {
            if ( ! empty( $ci[ $ok ] ) ) {
                $m = array();
                foreach ( (array) $ci[ $ok ] as $oid => $ov ) {
                    $m[ $oid ] = is_array( $ov ) ? ( isset( $ov['value_id'] ) ? (int) $ov['value_id'] : ( isset( $ov['selected_value_id'] ) ? (int) $ov['selected_value_id'] : 0 ) ) : (int) $ov;
                }
                $line_opts[ $ok ] = $m;
            }
        }
    }
    rental_options_trace( 'ON_ADD', array(
        'pid'       => (int) $product_id,
        'cart_key'  => substr( (string) $cart_item_key, 0, 6 ),
        'post_keys' => empty( $post_keys ) ? 'none' : $post_keys,
        'line_opts' => empty( $line_opts ) ? 'none' : $line_opts,
    ) );
}
add_action( 'woocommerce_add_to_cart', 'rental_options_trace_on_add', 210, 6 );

/**
 * Authoritative option capture at add-to-cart.
 *
 * The product-page option selects submit WITH the add-to-cart form under
 * `$_POST['rental_options_selection'][<option_id>] = <value_id>`. This
 * handler reads them straight from the submission and writes them onto the
 * cart line — so the customer's pick is captured the instant they add,
 * with NO dependency on the debounced option AJAX having completed first.
 * The cart line is the single source of truth; cart/mini-cart/checkout/
 * order all read it, and the product page now reads it back too.
 *
 * Runs late (after the merger/reconciler) and resolves the SURVIVING
 * parent line by product id, so options always land on the line that
 * stays in the cart.
 *
 * @return void
 */
function rental_capture_options_from_post_on_add( $cart_item_key, $product_id, $quantity, $variation_id, $variation, $cart_item_data ) {
    if ( ! empty( $cart_item_data['rental_add_on_of'] ) ) {
        return; // child line — options live on the parent
    }

    $actual_product_id = $variation_id ? (int) $variation_id : (int) $product_id;
    $is_set = Rental_Options_Repository::is_set( $actual_product_id, (int) $product_id );

    // Prefer the selection the gate accepted for this add, so what is stored
    // is exactly what was validated. Falling back to a fresh resolve covers
    // adds that bypassed the gate (a programmatic add, or a store that removed
    // the filter). Both paths include defaults, so an option the customer
    // never touched is still recorded rather than silently dropped.
    $options = Rental_Options_Cart_Validator::accepted_selection( $actual_product_id );
    if ( null === $options ) {
        $options = Rental_Options_Selection::resolve( $actual_product_id, $is_set, $cart_item_key );
    }

    if ( empty( $options ) ) {
        return;
    }

    // Resolve the surviving parent line (merger/reconciler may have moved it).
    $target_key = function_exists( 'rental_find_cart_item_key_by_product_id' )
        ? rental_find_cart_item_key_by_product_id( $actual_product_id )
        : $cart_item_key;
    if ( ! $target_key ) {
        $target_key = $cart_item_key;
    }

    // Authoritative REPLACE — the submission carries every option select.
    if ( function_exists( 'rental_update_cart_item_options' ) ) {
        rental_update_cart_item_options( $target_key, $options, false, $is_set );
    }

    // Keep both session stores aligned so the product-page read and the price
    // calc agree before the next cart-line read takes over.
    Rental_Options_Selection::persist( $actual_product_id, $is_set, $options );

    // The item is in the cart, so the picks kept for a rejected add have served
    // their purpose. Cleared here rather than at validation, because passing
    // this module's gate does not mean the add itself succeeded.
    Rental_Options_Prefill::clear( $actual_product_id );

    rental_options_trace( 'post_capture_on_add', array(
        'pid'      => $actual_product_id,
        'is_set'   => $is_set ? 1 : 0,
        'cart_key' => substr( (string) $target_key, 0, 6 ),
        'written'  => array_map( function ( $o ) { return (int) $o['value_id']; }, $options ),
    ) );
}
add_action( 'woocommerce_add_to_cart', 'rental_capture_options_from_post_on_add', 115, 6 );




function wp_ajax_rental_update_total_on_tip_amount_change() {

    if (!isset($_POST["tip_id"])) {
        wp_send_json(['error' => 'tip id not found'], 400);
        wp_die();
    }

    $tip_amount_final = 0;
    if (
        get_option("rental_direct_only_bookings", 0)
        && get_option("rental_payment_tips_enabled", 0)
    ) {

        $tip_id = intval($_POST["tip_id"]);

        $expire_time = time() + RENTOPIAN_DATE_EXPIRE_TIME;
        setcookie('TIP_ID_SELECTED_BY_CUSTOMER' . get_new_unique_id(), $tip_id, $expire_time, "/", "", false, true);
        $_COOKIE['TIP_ID_SELECTED_BY_CUSTOMER' . get_new_unique_id()] = $tip_id;

        $tip_amount_final = calculate_tip_amount($tip_id);

        rental_calculate_order_total();
    }

    wp_send_json([
        "payment_tip_amount" => $tip_amount_final,
        "currency" => get_woocommerce_currency_symbol()
    ], 200);
}

function calculate_tip_amount($rental_payment_tip_id, $calculate_based_on_default_option = false) {

    set_rental_session_data('rental_payment_tip_id', 0);
    set_rental_session_data('rental_payment_tip_amount', 0);

    // update_option('rental_payment_tip_id' . get_new_unique_id(), 0);
    // update_option('rental_payment_tip_amount' . get_new_unique_id(), 0);

    $tip_id = $rental_payment_tip_id;
    $tip_amount = 0;
    $tip_is_percent = 0;
    $rental_payment_tips = get_option('rental_payment_tips', []);

    foreach ($rental_payment_tips as $key => $option) {

        if ($calculate_based_on_default_option) {
            // calc based on default value

            if ($option->default) {
                $tip_amount = $option->amount;
                $tip_id = $option->id;
    
                if ($option->is_percent) {
                    $tip_is_percent = 1;
                }
    
                break;
                
            } else if ($key == 0) {

                $tip_amount = $option->amount ?? 0;
                $tip_id = $option->id ?? 0;

                if (isset($option->is_percent) && $option->is_percent) {
                    $tip_is_percent = 1;
                }

                break;
            }

        } else {
            // calc based on selected tip value
            
            if ($tip_id == $option->id) {
                $tip_amount = $option->amount;
    
                if ($option->is_percent) {
                    $tip_is_percent = 1;
                }
    
                break;
            }
        }
    }

    $tip_amount_final = 0;
    if ($tip_id && $tip_amount) {

        set_rental_session_data('rental_payment_tip_id', $tip_id);

        $order_total = get_rental_session_data('total_excluded_extra_fees', 0);
        $tip_amount_formatted = number_format((float) $tip_amount, 2, '.', '');

        $tip_amount_final = $tip_amount_formatted;
        if ($tip_is_percent) {
            $tip_amount_final = ($order_total * $tip_amount_formatted / 100);
        }

        set_rental_session_data('rental_payment_tip_amount', number_format((float) $tip_amount_final, 2, '.', ''));
        // update_option('rental_payment_tip_amount' . get_new_unique_id(), number_format((float) $tip_amount_final, 2, '.', ''));
    }

    return number_format((float) $tip_amount_final, 2, '.', '');
}

function rental_get_min_order_notice() {
    $message = WC()->session->get('rental_min_order_message');
    if ($message) {
        wp_send_json_success(['message' => $message]);
    }
    wp_send_json_success(['message' => '']);
}

// Seconds a failed delivery-settings lookup is remembered for, and the ceiling
// on the request itself. The shipping method that reads these settings is built
// while WooCommerce loads its shipping methods, which happens on shop, product,
// cart and checkout renders, so an unreachable API must cost one short request
// per few minutes rather than one long request per page view.
if (!defined('RENTAL_DELIVERY_SETTINGS_FAILURE_TTL')) {
    define('RENTAL_DELIVERY_SETTINGS_FAILURE_TTL', 5 * MINUTE_IN_SECONDS);
}
if (!defined('RENTAL_DELIVERY_SETTINGS_TIMEOUT')) {
    define('RENTAL_DELIVERY_SETTINGS_TIMEOUT', 8);
}

// get only the delivery settings (it has 30 minutes cache by default)
function rental_get_delivery_settings($force_refresh = false, $cache_duration = 30 * MINUTE_IN_SECONDS) {
    if (!$force_refresh) {
        $cached_settings = get_transient('rental_delivery_settings');
        if (false !== $cached_settings) {
            return $cached_settings;
        }
    }

    try {
        $shipping_data = json_decode(
            rental_curl(
                'shipping/calculate-shipping-cost',
                get_option('rental_api_key'),
                false,
                null,
                null,
                false,
                RENTAL_DELIVERY_SETTINGS_TIMEOUT
            ),
            true
        );
        $settings = $shipping_data['settings'] ?? [];
    } catch (Throwable $e) {
        // These settings decorate a page; they never justify failing one. An
        // uncaught RentalException reaches the global exception handler, which
        // ends the response where it stands and leaves the visitor a half
        // rendered page.
        ErrorHandler::registerErrorInLog(
            $e->getMessage(),
            $e->getFile(),
            $e->getLine(),
            $e instanceof RentalException ? $e->getType() : RentalException::TYPE_RUNTIME
        );

        // Remembering the failure is the point: without it the next request
        // repeats the same call, and a slow API turns into a slow site.
        set_transient('rental_delivery_settings', [], RENTAL_DELIVERY_SETTINGS_FAILURE_TTL);

        return [];
    }

    if (!$force_refresh) {
        set_transient('rental_delivery_settings', $settings, $cache_duration);
    }

    return $settings;
}

function rental_update_selected_start_end_times() {

    if (!isset($_POST["selected_time_id"])) {
        wp_send_json(['error' => 'selected_time_id id not found'], 400);
        wp_die();
    }

    $time_type = isset($_POST["time_type"]) && $_POST["time_type"] == 'pickup_time' ? 'pickup_time' : 'delivery_time';

    $selected_delivery_selection_time = $selected_pickup_selection_time = [];
    if ($delivery_time_selections = get_option('delivery_time_selections', '')) {

        $selected_time = '';
        $selected_time_id = intval($_POST["selected_time_id"]);
        foreach ($delivery_time_selections as $option) {

            if ($option->id == $selected_time_id) {

                if ($time_type == 'delivery_time') {
                    $selected_time = $option->start_time;

                    $selected_delivery_selection_time = [
                        'id' => $option->id,
                        'start_time' => $option->start_time,
                        'end_time' => $option->end_time,
                    ];

                } else {
                    // pickup_time
                    $selected_time = $option->start_time;

                    $selected_pickup_selection_time = [
                        'id' => $option->id,
                        'start_time' => $option->start_time,
                        'end_time' => $option->end_time
                    ];

                    // $selected_time = $option->end_time;
                }
                
                break;
            }
        }
    }

    if ($selected_time) {

        $opt_hide_zip = get_option('rental_hide_zip');
        $opt_hide_time = get_option('rental_hide_time_pickers');

        $date_format = 'M j';
        if ( !$opt_hide_time) {
            $date_format .= ', g:i A';
        }

        $decrypted_rental_zip = false;
        if (isset($_COOKIE['rental_zip']) && $_COOKIE['rental_zip']) {
            $decrypted_rental_zip = decrypt_data($_COOKIE['rental_zip'], get_option('rental_encryption_key'));
        }
        $zip = $decrypted_rental_zip;

        $rental_start_date = isset($_COOKIE['rental_start_date']) && $_COOKIE['rental_start_date'] ? $_COOKIE['rental_start_date'] : '';
        $rental_end_date = isset($_COOKIE['rental_end_date']) && $_COOKIE['rental_end_date'] ? $_COOKIE['rental_end_date'] : '';

        if ($time_type == 'delivery_time') {

            $multi_day = isset($_POST['multi_day']) ? (int) $_POST['multi_day'] : 0;
            
            if (!$multi_day && (isset($_COOKIE['rental_end_date']) && $_COOKIE['rental_end_date'])) {
                setcookie('rental_end_date', '', time() - RENTOPIAN_DATE_EXPIRE_TIME, "/", "", false, true);
                unset($_COOKIE['rental_end_date']);
            }

            if ($rental_start_date) {
    
                $date_part = DateTime::createFromFormat('Y/m/d h:i A', $rental_start_date);
                if (!$date_part) {
                    // If parsing failed, try with date-only
                    $date_part = DateTime::createFromFormat('Y/m/d', $rental_start_date);
                }
    
    
                if ($date_part) {
    
                    $new_datetime_str = $date_part->format('Y/m/d') . ' ' . $selected_time;
            
                    // Parse new datetime to ensure it's valid
                    $new_datetime = DateTime::createFromFormat('Y/m/d h:i A', $new_datetime_str);
                    $day_name = $new_datetime ? $new_datetime->format('l') : '';
                    $selected_delivery_selection_time['day'] = getDayNumberByDayName($day_name);

                    if ($new_datetime) {

                        $start_date = $new_datetime->format('Y/m/d h:i A');
                        $error = rental_validate_dates($opt_hide_time, $opt_hide_zip, $start_date, $rental_end_date, $zip);

                        if ($error) {

                            unset_delivery_times_data();

                            wp_send_json(['failure' => true, 'error' => $error], 400);
                            wp_die();
                        }
    
                        $encrypted_rental_start_date = encrypt_data($start_date, get_option('rental_encryption_key'));
                        $_COOKIE['rental_start_date'] = $encrypted_rental_start_date;
                        setcookie('rental_start_date', $encrypted_rental_start_date, time() + RENTOPIAN_DATE_EXPIRE_TIME, "/", "", false, true);
            

                        $_COOKIE['rental_selected_delivery_selection_time'] = json_encode($selected_delivery_selection_time);
                        setcookie('rental_selected_delivery_selection_time', json_encode($selected_delivery_selection_time), time() + RENTOPIAN_DATE_EXPIRE_TIME, "/", "", false, true);


                        // $checkout_dates_html = rntp_build_checkout_dates_html( 
                        //     $formatted_start_date, 
                        //     null, 
                        //     $rental_start_date, 
                        //     $rental_end_date, 
                        //     $date_format
                        // );

                        ob_start();
                        // do_action('woocommerce_checkout_before_order_review');
                        checkout_rental_dates();
                        $checkout_dates_html = ob_get_clean();

                        $formatted_start_date = date($date_format, strtotime($start_date));

                        wp_send_json([
                            'success' => true,
                            'checkout_dates_html' => $checkout_dates_html,
                            'rental_start_date' => $formatted_start_date,
                            'rental_end_date' => '',
                            'update_checkout_dates' => get_option('rental_dates_on_checkout', '') !== '' ? 1 : 0,
                        ], 200);

                    } else {
                        wp_send_json(['error' => 'Operation failed'], 400);
                    }
                    
                } else {
                    wp_send_json(['error' => 'Operation failed'], 400);
                }

                wp_die();
            }
    
        } else {

            // Pickup time: only update when we have an end date (multi-day).
            // When no end date (single-day), return current summary HTML and do not change any dates.
            if (!$rental_end_date) {
                ob_start();
                checkout_rental_dates();
                $checkout_dates_html = ob_get_clean();
                wp_send_json([
                    'success' => true,
                    'checkout_dates_html' => $checkout_dates_html,
                    'rental_start_date' => '',
                    'rental_end_date' => '',
                    'update_checkout_dates' => get_option('rental_dates_on_checkout', '') !== '' ? 1 : 0,
                ], 200);
                wp_die();
            }

            if ($rental_end_date) {
    
                $date_part = DateTime::createFromFormat('Y/m/d h:i A', $rental_end_date);
                if (!$date_part) {
                    // If parsing failed, try with date-only
                    $date_part = DateTime::createFromFormat('Y/m/d', $rental_end_date);
                }
    
    
                if ($date_part) {
    
                    $new_datetime_str = $date_part->format('Y/m/d') . ' ' . $selected_time;
            
                    // Parse new datetime to ensure it's valid
                    $new_datetime = DateTime::createFromFormat('Y/m/d h:i A', $new_datetime_str);
                    $day_name_pickup = $new_datetime ? $new_datetime->format('l') : '';
                    $selected_pickup_selection_time['day'] = getDayNumberByDayName($day_name_pickup);
            
                    if ($new_datetime) {

                        $end_date = '';
                        if (!empty($selected_pickup_selection_time['end_time'])) {
                            $new_end_datetime_str = $date_part->format('Y/m/d') . ' ' . $selected_pickup_selection_time['end_time'];
                            $new_end_datetime = DateTime::createFromFormat('Y/m/d h:i A', $new_end_datetime_str);
                            $end_date = $new_end_datetime->format('Y/m/d h:i A');
                        }
                        if (empty($end_date)) {
                            wp_send_json(['failure' => true, 'error' => 'No pickup time selected.'], 400);
                            wp_die();
                        }
                       
                        $error = rental_validate_dates($opt_hide_time, $opt_hide_zip, $rental_start_date, $end_date, $zip);

                        if ($error) {

                            unset_delivery_times_data();

                            wp_send_json(['failure' => true, 'error' => $error], 400);
                            wp_die();
                        }

                        $encrypted_rental_end_date = encrypt_data($end_date, get_option('rental_encryption_key'));
                        $_COOKIE['rental_end_date'] = $encrypted_rental_end_date;
                        setcookie('rental_end_date', $encrypted_rental_end_date, time() + RENTOPIAN_DATE_EXPIRE_TIME, "/", "", false, true);
            
                        $_COOKIE['rental_selected_pickup_selection_time'] = json_encode($selected_pickup_selection_time);
                        setcookie('rental_selected_pickup_selection_time', json_encode($selected_pickup_selection_time), time() + RENTOPIAN_DATE_EXPIRE_TIME, "/", "", false, true);


                        // $checkout_dates_html = rntp_build_checkout_dates_html( 
                        //     null, 
                        //     $formatted_end_date, 
                        //     $rental_start_date, 
                        //     $rental_end_date, 
                        //     $date_format
                        // );

                        ob_start();
                        // do_action('woocommerce_checkout_before_order_review');
                        checkout_rental_dates();
                        $checkout_dates_html = ob_get_clean();

                        $formatted_end_date = date($date_format, strtotime($end_date));

                        wp_send_json([
                            'success' => true,
                            'checkout_dates_html' => $checkout_dates_html,
                            'rental_start_date' => '',
                            'rental_end_date' => $formatted_end_date,
                            'update_checkout_dates' => get_option('rental_dates_on_checkout', '') !== '' ? 1 : 0,
                        ], 200);

                    } else {
                        wp_send_json(['error' => 'Operation failed'], 400);
                    }
                    
                } else {
                    wp_send_json(['error' => 'Operation failed'], 400);
                }
    
                wp_die();
            }
        }
      
    } else {

        unset_delivery_times_data();
    }

}


function isEmptyPrice($price) {
    return $price === null || $price === '' || floatval($price) <= 0.0;
}

function parseWithDefaultTime(string $input, string $defaultTime = '00:00:00'): DateTime {
    // does it contain something that looks like "HH:MM"?
    if (preg_match('/\d{1,2}:\d{2}/', $input)) {
        // assume the date string has a time (24‑hour or with AM/PM)
        return new DateTime($input);
    } else {
        // no time found, append the default
        return new DateTime("$input $defaultTime");
    }
}

function getTimeFromDate(string $date, string $defaultTime = '00:00:00'): string
{
    // regex breakdown:
    // (\d{1,2}:\d{2}(?::\d{2})?)  — matches "H:MM", "HH:MM", or "HH:MM:SS"
    // (?:\s?([AP]M)?              — optionally matches a space then "AM" or "PM"
    $pattern = '/(\d{1,2}:\d{2}(?::\d{2})?)(?:\s?([AP]M))?/i';

    if (preg_match($pattern, $date, $matches)) {
        // $matches[1] is the time (HH:MM or HH:MM:SS)
        // $matches[2] is AM or PM (if present)
        $time = $matches[1];
        if (!empty($matches[2])) {
            // append AM/PM in uppercase
            $time .= ' ' . strtoupper($matches[2]);
        }
        return $time;
    }

    return $defaultTime;
}

function rental_set_selected_delivery_pickup_start_end_times($rental_start_date, $rental_end_date, $opt_default_start_time, $opt_default_end_time, $opt_hide_time_pickers) {
    
    $opt_hide_end_date = get_option('rental_hide_end_date');
    $finilizedStartEndDate = RTDelivery::extractFinalRentalStartEndDate($rental_start_date, $rental_end_date, $opt_hide_time_pickers, $opt_default_start_time, $opt_default_end_time, $opt_hide_end_date);

    $rental_start_date = $finilizedStartEndDate['rental_start_date'];
    $rental_end_date = $finilizedStartEndDate['rental_end_date'];
    
    // set start date time as start delivery time and end date time as pickup start time
    $delivery_start_time = getTimeFromDate($rental_start_date, $opt_default_start_time);

    $rental_start_date_obj = parseWithDefaultTime($rental_start_date, $opt_default_start_time);
    $start_date_day_name = $rental_start_date_obj->format('l'); // Full name of the day

    $selected_delivery_selection_time = [
        'id' => 0,
        'start_time' => $delivery_start_time,
        'end_time' => '',
        'day' => getDayNumberByDayName($start_date_day_name),
    ];

    $_COOKIE['rental_selected_delivery_selection_time'] = json_encode($selected_delivery_selection_time);
    setcookie('rental_selected_delivery_selection_time', json_encode($selected_delivery_selection_time), time() + RENTOPIAN_DATE_EXPIRE_TIME, "/", "", false, true);

    $pickup_start_time = $end_date_day_name = '';
    if ($rental_end_date) {
        $pickup_start_time = getTimeFromDate($rental_end_date, $opt_default_end_time);

        $rental_end_date_obj = parseWithDefaultTime($rental_end_date, $opt_default_end_time);
        $end_date_day_name = $rental_end_date_obj->format('l'); // Full name of the day
    }
    $selected_pickup_selection_time = [
        'id' => 0,
        'start_time' => $pickup_start_time,
        'end_time' => '',
        'day' => getDayNumberByDayName($end_date_day_name),
    ];

    $_COOKIE['rental_selected_pickup_selection_time'] = json_encode($selected_pickup_selection_time);
    setcookie('rental_selected_pickup_selection_time', json_encode($selected_pickup_selection_time), time() + RENTOPIAN_DATE_EXPIRE_TIME, "/", "", false, true);
}

function unset_delivery_times_data() {
    if (isset($_COOKIE['rental_selected_delivery_selection_time'])) {
        unset($_COOKIE['rental_selected_delivery_selection_time']);
        setcookie('rental_selected_delivery_selection_time', false, time() - (31556952), "/", "", false, false);
    }

    if (isset($_COOKIE['rental_selected_pickup_selection_time'])) {
        unset($_COOKIE['rental_selected_pickup_selection_time']);
        setcookie('rental_selected_pickup_selection_time', false, time() - (31556952), "/", "", false, false);
    }
}

/**
 * A simple stemming function to get singular and plural forms.
 * Note: This won't handle irregular plurals like 'goose'/'geese'.
 *
 * @param string $term The search term.
 * @return array An array containing the singular and plural versions.
 */
function rental_simple_stemmer($term) {
    $term = strtolower(trim($term));
    if (empty($term)) {
        return [];
    }

    $singular = '';
    $plural = '';

    // Check for words ending in 'ies', change to 'y' for singular
    if (preg_match('/ies$/', $term)) {
        $singular = preg_replace('/ies$/', 'y', $term);
        $plural = $term;
    } 

    // Check for words ending in 'es'
    elseif (preg_match('/es$/', $term)) {
        $singular = substr($term, 0, -2);
        $plural = $term;
    }

    // Check for words ending in 's'
    elseif (preg_match('/s$/', $term)) {
        $singular = substr($term, 0, -1);
        $plural = $term;
    } 
    // The word is likely already singular
    else {
        $singular = $term;
        $plural = $term . 's';
    }

    // Return unique values, in case singular and plural are the same
    return array_unique([$singular, $plural]);
}


function get_stemmed_search_result_ids($search_term) {
    global $wpdb;
    $search_matched_ids = [];
    
    if ( !empty($search_term) ) {

        // Break into words, trim out any extra spaces
        $raw_terms = array_filter( preg_split( '/\s+/', trim( $search_term ) ) );
        
        // Include the entire phrase as one unit
        if ( count( $raw_terms ) > 1 ) {
            $raw_terms[] = trim( $search_term );
        }
        
        // Generate stemmed variations
        $all_variations = [];
        foreach ( array_unique($raw_terms) as $term ) {
            $vars = rental_simple_stemmer($term);
            if ( !empty( $vars ) ) {
                $all_variations = array_merge( $all_variations, $vars );
            }
        }
        $all_variations = array_unique( $all_variations );
        
        if ( !empty($all_variations) ) {
            // Build LIKE clauses for search terms (already prepared)
            $like_clauses = [];
            foreach ( $all_variations as $var ) {
                $like_clauses[] = $wpdb->prepare( "post_title LIKE %s", '%' . $wpdb->esc_like( $var ) . '%' );
            }
            $like_sql = implode( ' OR ', $like_clauses );

            // Get the term_taxonomy_id for 'exclude-from-search'
            $exclude_term = get_term_by( 'slug', 'exclude-from-search', 'product_visibility' );
            $exclude_term_id = $exclude_term ? $exclude_term->term_taxonomy_id : 0;

            /*
             * Exclude posts that:
             *  - are flagged in product_visibility taxonomy as 'exclude-from-search' (existing)
             *  - OR have postmeta _rental_is_add_on = '1'  (covers hidden_from_api mapped earlier to is_add_on)
             *
             * We LEFT JOIN postmeta and then require that meta_value IS NULL OR != '1' to keep only non-add-on items.
             */
            $search_matched_ids = $wpdb->get_col($wpdb->prepare("
                SELECT DISTINCT p.ID
                FROM {$wpdb->posts} p
                LEFT JOIN {$wpdb->term_relationships} tr 
                    ON p.ID = tr.object_id AND tr.term_taxonomy_id = %d
                LEFT JOIN {$wpdb->postmeta} pm_add_on
                    ON p.ID = pm_add_on.post_id AND pm_add_on.meta_key = '_rental_is_add_on'
                LEFT JOIN {$wpdb->postmeta} pm_parent_add_on
                    ON p.post_parent = pm_parent_add_on.post_id AND pm_parent_add_on.meta_key = '_rental_is_add_on'
                WHERE p.post_type IN ('product', 'product_variation')
                AND p.post_status = 'publish'
                AND ( $like_sql )
                AND tr.object_id IS NULL
                AND (pm_add_on.meta_value IS NULL OR pm_add_on.meta_value != '1')
                AND (pm_parent_add_on.meta_value IS NULL OR pm_parent_add_on.meta_value != '1')
            ", $exclude_term_id));
        }
    }
    
    return $search_matched_ids;
}

function rental_update_shipping_method() {
    if ( !isset($_POST['rental_shipping_method']) ) {
        wp_send_json_error( array('message' => 'Missing rental_shipping_method' ), 400);
    }

    $value = intval($_POST['rental_shipping_method'] );
    setcookie('rental_shipping_method', $value, time() + 3600, "/", "", false, true );
    $_COOKIE['rental_shipping_method'] = $value;

    wp_send_json_success( array( 'message' => 'Shipping method updated' ) );
}

function rental_is_set_child_item( $cart_item ) {
    if ( !empty($cart_item['rental_add_on_of']) && !empty($cart_item['set_id']) ) {
        return true;
    }
    return false;
}

/**
 * Try to find the parent set product_id for a given cart item.
 */
function rental_get_parent_set_product_id( $cart_item ) {
    // global $wpdb;

    if (!empty($cart_item['set_id'])) {
        
        return intval( $cart_item['set_id'] );
    }
    
    if (!empty($cart_item['parent_set_id'])) {

        return intval($cart_item['parent_set_id']);
    }

    // if ( !empty($cart_item['parent_set_product_id']) ) {
    //     return intval($cart_item['parent_set_product_id']);
    // }

    // If cart item references a parent cart key via rental_add_on_of, look up that cart item product ID
    if ( ! empty( $cart_item['rental_add_on_of'] ) && WC()->cart ) {

        $cart_contents = WC()->cart->get_cart();
        $key = (string) $cart_item['rental_add_on_of'];

        if ( isset( $cart_contents[ $key ] ) ) {

            $parent_item = $cart_contents[ $key ];
            $pid = !empty( $parent_item['variation_id'] ) ? $parent_item['variation_id'] : $parent_item['product_id'];

            if ($pid) {
                return intval( $pid );
            }
        }
    }

    return false;
}


/**
 * Parse _rental_set_items and detect if a cart set item (product_id/variant_id) or its addon is marked hidden.
 * Accepts both JSON (object/array) and PHP serialized or array formats.
 */
function rental_set_items_mark_hidden( $set_product_id, $set_item_product_id = 0, $set_item_variant_id = 0 ) {
    if (!$set_product_id) {
        return false;
    }

    $raw = get_post_meta( $set_product_id, '_rental_set_items', true );
    if ( empty($raw) ) {
        return false;
    }

    $items = null;

    // If stored as JSON string
    if ( is_string($raw) ) {
        $decoded = json_decode($raw);

        if ( $decoded !== null ) {

            $items = $decoded;
        } else {

            // try unserialize (older WP meta)
            $maybe = @unserialize( $raw );
            if ( $maybe !== false ) {
                $items = $maybe;
            } else {
                // try decode JSON that is stored as serialized-ish structure fallback
                $items = $raw;
            }
        }

    } else {
        // already array/object
        $items = $raw;
    }

    if ( empty( $items ) ) {
        return false;
    }

    // Normalize iterable
    if ( is_object( $items ) ) {
        $items = (array) $items;
    }

    foreach ( $items as $it ) {
        // normalize each item
        $item = (array) $it;

        // item-level hidden
        if ( isset( $item['hidden'] ) && $item['hidden'] ) {

            // match by product_id, rental_product_id, variant_id
            if ( isset( $item['product_id'] ) && intval( $item['product_id'] ) === intval( $set_item_product_id ) ) {
                if ( isset( $item['variant_id'] ) && intval( $item['variant_id'] ) === intval( $set_item_variant_id ) ) {
                    return true;
                }
            }
        }

        // check nested addons for hidden flag
        if ( !empty($item['addons']) && is_array($item['addons']) ) {

            foreach ( $item['addons'] as $ad ) {

                $addon = (array) $ad;

                if ( isset( $addon['hidden'] ) && $addon['hidden'] ) {

                    if ( isset( $addon['product_id'] ) && intval( $addon['product_id'] ) === intval( $set_item_product_id ) ) {
                        if ( isset( $addon['variant_id'] ) && intval( $addon['variant_id'] ) === intval( $set_item_variant_id ) ) {
                            return true;
                        }
                    }
                }
            }

        }

    }

    return false;
}


/**
 * AJAX Handler: Update Rental Dates Summary
 * 
 * Receives date values from the frontend and returns the formatted
 * summary HTML that matches the checkout_rental_dates() output.
 * 
 * @return void Outputs JSON response
 */
function rental_update_dates_summary_handler() {

    // Verify nonce for security
    if (!isset($_POST['security']) || !wp_verify_nonce($_POST['security'], 'rental_checkout_nonce')) {
        wp_send_json_error(array('message' => 'Security check failed'));
        return;
    }

    // Sanitize input data
    $start_date   = isset($_POST['start_date']) ? sanitize_text_field($_POST['start_date']) : '';
    $end_date     = isset($_POST['end_date']) ? sanitize_text_field($_POST['end_date']) : '';
    // $start_time   = isset($_POST['start_time']) ? sanitize_text_field($_POST['start_time']) : '';
    // $end_time     = isset($_POST['end_time']) ? sanitize_text_field($_POST['end_time']) : '';
    $zip          = isset($_POST['zip']) ? sanitize_text_field($_POST['zip']) : '';
    $event_date_post = isset($_POST['event_date']) ? sanitize_text_field(wp_unslash($_POST['event_date'])) : '';
    // $is_multi_day = isset($_POST['is_multi_day']) ? (bool) $_POST['is_multi_day'] : true;

    $encryption_key    = get_option('rental_encryption_key');
    $checkout_offsets  = function_exists('rental_get_event_date_offsets') ? rental_get_event_date_offsets() : false;
    $event_raw_for_display = $event_date_post;
    if ($checkout_offsets && $event_raw_for_display === '' && !empty($_COOKIE['rental_event_date']) && function_exists('decrypt_data') && $encryption_key) {
        $event_raw_for_display = decrypt_data($_COOKIE['rental_event_date'], $encryption_key);
    }

    // Combine date and time if time is provided separately
    // if ($start_time && strpos($start_date, ':') === false) {
    //     $start_date = trim($start_date . ' ' . $start_time);
    // }
    // if ($end_time && strpos($end_date, ':') === false) {
    //     $end_date = trim($end_date . ' ' . $end_time);
    // }

    // Get plugin settings
    $hide_time_pickers = get_option('rental_hide_time_pickers', false);
    $hide_end_date     = get_option('rental_hide_end_date', false);
    $hide_zip          = get_option('rental_hide_zip', false);
    $product_type      = get_option('rental_synchronized_product_type', 'rental');

    // Format the dates for display
    $date_format = 'M j';
    if (!$hide_time_pickers) {
        $date_format .= ', g:i A';
    }

    // Parse dates - handle both server format (YYYY/MM/DD h:mm A) and display format (M j, g:i A)
    $start_timestamp = false;
    $end_timestamp = false;
    
    if ($start_date) {
        // Try server format first (YYYY/MM/DD h:mm A)
        $start_timestamp = strtotime($start_date);
        if ($start_timestamp === false) {
            // Try display format (M j, g:i A) - e.g., "Jan 13, 10:00 AM"
            $parsed = date_create_from_format('M j, g:i A', $start_date);
            if ($parsed === false) {
                $parsed = date_create_from_format('M d, g:i A', $start_date);
            }
            if ($parsed !== false) {
                $start_timestamp = $parsed->getTimestamp();
            }
        }
    }
    
    if ($end_date) {
        // Try server format first (YYYY/MM/DD h:mm A)
        $end_timestamp = strtotime($end_date);
        if ($end_timestamp === false) {
            // Try display format (M j, g:i A) - e.g., "Jan 16, 10:00 PM"
            $parsed = date_create_from_format('M j, g:i A', $end_date);
            if ($parsed === false) {
                $parsed = date_create_from_format('M d, g:i A', $end_date);
            }
            if ($parsed !== false) {
                $end_timestamp = $parsed->getTimestamp();
            }
        }
    }

    // Offset mode: derive rental window from event only (POST may carry pre-offset range from JS).
    if ($checkout_offsets && is_string($event_raw_for_display) && $event_raw_for_display !== '') {
        $comp = rental_apply_event_date_offsets(
            $event_raw_for_display,
            $checkout_offsets['start_date_offset'],
            $checkout_offsets['end_date_offset'],
            get_option('rental_default_start_time', '9:00 AM'),
            get_option('rental_default_end_time', '05:00 PM')
        );
        $start_date      = $comp['start_date'];
        $end_date        = $comp['end_date'];
        $start_timestamp = strtotime($start_date);
        $end_timestamp   = strtotime($end_date);
    }

    // Build the date display string
    $date_display   = '';
    $period_markup  = '';

    if ($product_type !== 'sale' && $start_timestamp) {

        if ($checkout_offsets) {
            // Event date in <li>; rental period block is echoed after <ul> (matches mini-cart / checkout template).
            $primary_line = function_exists('rental_format_checkout_event_date_display_line')
                ? rental_format_checkout_event_date_display_line($event_raw_for_display, (bool) $hide_time_pickers)
                : '';
            if ($primary_line === '') {
                $primary_line = date_i18n($date_format, $start_timestamp);
            }
            $date_display = $primary_line;

            if ($end_timestamp && function_exists('rental_format_rental_period_markup')) {
                $period_markup = rental_format_rental_period_markup(
                    $start_date,
                    $end_date,
                    (bool) $hide_time_pickers
                );
            }
        } else {

            // Check if same day
            $start_date_only = date('Y-m-d', $start_timestamp);
            $end_date_only   = $end_timestamp ? date('Y-m-d', $end_timestamp) : '';
            $is_same_day     = ($start_date_only === $end_date_only);

            if ($is_same_day) {
                // Same day rental: "Jan 11 10:00 AM → 5:00 PM"
                $date_display = date('M j', $start_timestamp);

                if (!$hide_time_pickers) {
                    $date_display .= ' ' . date('g:i A', $start_timestamp);
                    if ($end_timestamp) {
                        $date_display .= ' &rarr; ' . date('g:i A', $end_timestamp);
                    }
                }
            } else {
                // Multi-day rental: "Jan 11, 10:00 AM → Jan 13, 10:00 AM"
                $date_display = date($date_format, $start_timestamp);

                if (!$hide_end_date && $end_timestamp) {
                    $date_display .= ' &rarr; ' . date($date_format, $end_timestamp);
                }
            }
        }
    }

    // Generate the summary list HTML (inner content)
    ob_start();
    ?>
    <?php if ($product_type !== 'sale' && $date_display) : ?>
        <li><?php echo $checkout_offsets ? esc_html($date_display) : $date_display; ?></li>
    <?php endif; ?>
    <?php if (!$hide_zip && $zip): ?>
        <li>
            <strong><?php _e('ZIP Code', 'rentopian-sync'); ?></strong>
            <?php echo esc_html($zip); ?>
        </li>
    <?php endif; ?>
    <?php
    $summary_html = ob_get_clean();

    // Generate the complete wrapper HTML (matches checkout_rental_dates() output)
    ob_start();
    ?>
    <div id="rntp-rental-dates-summary-wrapper" class="rental-dates-summary-wrapper">
        <strong><?php echo $checkout_offsets ? esc_html__('Event date', 'rentopian-sync') : esc_html__('Rental Date(s)', 'rentopian-sync'); ?></strong>
        
        <?php 
        // Show edit button if dates on checkout and rental_form_layout is in-cart
        if (!get_option('rental_dates_on_checkout') && get_option('rental_form_layout') === "in-cart"): 
        ?>
            <span title="Click here to change the dates!" class="dates-edit dashicons dashicons-edit"></span>
        <?php endif; ?>
        
        <ul class="rental-dates-summary">
            <?php echo $summary_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
        </ul>
        <?php
        if ($period_markup !== '') {
            echo $period_markup; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        }
        ?>
    </div>
    <?php
    $checkout_dates_html = ob_get_clean();

    // Update cookies with the new values (encrypted if encryption is enabled)
    if ($start_date && $encryption_key && function_exists('encrypt_data')) {
        $encrypted_start = encrypt_data($start_date, $encryption_key);
        setcookie('rental_start_date', $encrypted_start, time() + RENTOPIAN_DATE_EXPIRE_TIME, '/');
    } elseif ($start_date) {
        setcookie('rental_start_date', $start_date, time() + RENTOPIAN_DATE_EXPIRE_TIME, '/');
    }

    if ($end_date && $encryption_key && function_exists('encrypt_data')) {
        $encrypted_end = encrypt_data($end_date, $encryption_key);
        setcookie('rental_end_date', $encrypted_end, time() + RENTOPIAN_DATE_EXPIRE_TIME, '/');
    } elseif ($end_date) {
        setcookie('rental_end_date', $end_date, time() + RENTOPIAN_DATE_EXPIRE_TIME, '/');
    }

    if ($zip && $encryption_key && function_exists('encrypt_data')) {
        $encrypted_zip = encrypt_data($zip, $encryption_key);
        setcookie('rental_zip', $encrypted_zip, time() + RENTOPIAN_DATE_EXPIRE_TIME, '/');
    } elseif ($zip) {
        setcookie('rental_zip', $zip, time() + RENTOPIAN_DATE_EXPIRE_TIME, '/');
    }

    if ($checkout_offsets && $event_date_post !== '') {
        if ($encryption_key && function_exists('encrypt_data')) {
            setcookie('rental_event_date', encrypt_data($event_date_post, $encryption_key), time() + RENTOPIAN_DATE_EXPIRE_TIME, '/');
        } else {
            setcookie('rental_event_date', $event_date_post, time() + RENTOPIAN_DATE_EXPIRE_TIME, '/');
        }
    }

    // Send success response with both HTML formats
    wp_send_json_success([
        'checkout_dates_html' => $checkout_dates_html,  // Complete wrapper (for replaceWith)
        'summary_html'        => $summary_html,          // Just the list items (for .html())
        'formatted_dates'     => [
            'start' => $start_timestamp ? date($date_format, $start_timestamp) : '',
            'end'   => $end_timestamp ? date($date_format, $end_timestamp) : '',
        ],
        'settings'            => [
            'hide_time_pickers' => $hide_time_pickers,
            'hide_end_date'     => $hide_end_date,
            'hide_zip'          => $hide_zip,
            'product_type'      => $product_type,
        ],
    ]);
}

/**
 * ============================================================================
 * RENTAL OPTIONS - CART ITEM DATA MANAGEMENT
 * ============================================================================
 * These functions provide direct manipulation of rental options stored in
 * WooCommerce cart item data.
 * 
 * @since 2.14.0
 */

/**
 * Find a cart item key by product ID.
 *
 * @param int $product_id Product ID (or variation ID).
 * @return string|null Cart item key or null if not found.
 */
function rental_find_cart_item_key_by_product_id($product_id) {
    if (!function_exists('WC') || !WC()->cart) {
        return null;
    }
    
    $product_id = intval($product_id);
    
    foreach (WC()->cart->get_cart() as $cart_item_key => $cart_item) {
        $item_product_id = !empty($cart_item['variation_id']) 
            ? $cart_item['variation_id'] 
            : $cart_item['product_id'];
        
        if (intval($item_product_id) === $product_id) {
            return $cart_item_key;
        }
    }
    
    return null;
}

/**
 * Get rental selected options from a cart item.
 *
 * @param string $cart_item_key Cart item key.
 * @return array Selected options array or empty array.
 */
function rental_get_cart_item_options($cart_item_key) {
    if (!function_exists('WC') || !WC()->cart) {
        return [];
    }
    
    $cart_contents = WC()->cart->get_cart();
    
    if (!isset($cart_contents[$cart_item_key])) {
        return [];
    }
    
    return isset($cart_contents[$cart_item_key]['rental_selected_options']) 
        ? $cart_contents[$cart_item_key]['rental_selected_options'] 
        : [];
}

/**
 * Update rental selected options for a cart item directly in cart data.
 * This updates WooCommerce's cart session directly, ensuring persistence.
 *
 * @param string $cart_item_key Cart item key.
 * @param array  $options       Options array: [option_id => ['value_id' => X, 'price' => Y], ...]
 * @param bool   $merge         If true, merge with existing options. If false, replace entirely.
 * @param bool   $is_set        If true, use 'rental_selected_set_options' key. Defaults to auto-detect.
 * @return bool True on success, false on failure.
 */
function rental_update_cart_item_options($cart_item_key, $options, $merge = true, $is_set = null) {
    if (!function_exists('WC') || !WC()->cart) {
        return false;
    }
    
    // Get current cart contents
    if (!isset(WC()->cart->cart_contents[$cart_item_key])) {
        return false;
    }
    
    $cart_item = WC()->cart->cart_contents[$cart_item_key];
    $product_id = isset($cart_item['variation_id']) && $cart_item['variation_id'] 
        ? $cart_item['variation_id'] 
        : $cart_item['product_id'];
    
    // Auto-detect if this is a set based on cart item data or product meta
    if ($is_set === null) {
        $is_set = !empty($cart_item['rental_is_set']) || get_post_meta($product_id, '_rental_is_set', true);
    }
    
    // Use correct key based on whether this is a set
    $options_key = $is_set ? 'rental_selected_set_options' : 'rental_selected_options';
    
    // Get existing options if merging
    $existing_options = [];
    if ($merge && isset(WC()->cart->cart_contents[$cart_item_key][$options_key])) {
        $existing_options = WC()->cart->cart_contents[$cart_item_key][$options_key];
        // Ensure existing options is an associative array (fix for corrupted data)
        // Corrupted data has numeric indices (0, 1, 2, 3) instead of option_id keys (12, 13, 14)
        if (!empty($existing_options)) {
            $first_key = array_key_first($existing_options);
            // If first key is 0, 1, 2, etc. (sequential numeric), data is corrupted
            if (is_int($first_key) && $first_key < 10) {
                // Data is numerically indexed - this is corrupted, clear it
                // We'll rebuild fresh from the new $options being passed in
                $existing_options = [];
            }
        }
    }
    
    // Merge or replace options - use array_replace to preserve numeric keys!
    // array_merge reindexes numeric keys, which breaks our option_id keying
    $updated_options = $merge ? array_replace($existing_options, $options) : $options;
    
    // Update cart item data directly using the correct key
    WC()->cart->cart_contents[$cart_item_key][$options_key] = $updated_options;

    if ( function_exists( 'rental_options_trace' ) ) {
        $rntp_written = array();
        foreach ( (array) $updated_options as $oid => $ov ) {
            $rntp_written[ $oid ] = isset( $ov['value_id'] ) ? (int) $ov['value_id'] : ( isset( $ov['selected_value_id'] ) ? (int) $ov['selected_value_id'] : 0 );
        }
        rental_options_trace( 'cart_line_write', array(
            'cart_key'    => substr( (string) $cart_item_key, 0, 6 ),
            'pid'         => $product_id,
            'options_key' => $options_key,
            'merge'       => $merge ? 1 : 0,
            'written'     => $rntp_written,
        ) );
    }

    // CRITICAL: Force WooCommerce to persist the cart session immediately
    // This ensures the data is available when get_cart() is called later
    WC()->cart->set_session();
    
    // Also save to WC session directly to ensure persistence across AJAX calls
    if (WC()->session) {
        $cart_session = WC()->session->get('cart', []);
        if (isset($cart_session[$cart_item_key])) {
            $cart_session[$cart_item_key][$options_key] = $updated_options;
            WC()->session->set('cart', $cart_session);
        }
    }
    
    return true;
}

/**
 * Update a single option for a cart item and recalculate totals.
 *
 * @param int    $product_id Product ID.
 * @param int    $option_id  Option ID.
 * @param int    $value_id   Selected value ID.
 * @param mixed  $price      Option price.
 * @return array Result with 'success', 'cart_item_key', and 'message'.
 */
function rental_update_single_cart_item_option($product_id, $option_id, $value_id, $price, $cart_item_key = null) {
    // Prefer the line the caller named. Searching by product id returns the
    // first match and does not skip add-on children, so on a product that is
    // also an add-on the edit could land on the wrong line.
    $cart_item_key = $cart_item_key && isset(WC()->cart->cart_contents[$cart_item_key])
        ? $cart_item_key
        : null;

    if (!$cart_item_key && class_exists('Rental_Options_Selection')) {
        $cart_item_key = Rental_Options_Selection::find_cart_item_key($product_id);
    }

    if (!$cart_item_key) {
        $cart_item_key = rental_find_cart_item_key_by_product_id($product_id);
    }

    if (!$cart_item_key) {
        // Product not in cart - store in session for later
        return [
            'success' => false,
            'in_cart' => false,
            'message' => 'Product not in cart, storing in session'
        ];
    }
    
    // Determine if this is a set based on product meta
    $is_set = get_post_meta($product_id, '_rental_is_set', true);
    $options_key = $is_set ? 'rental_selected_set_options' : 'rental_selected_options';
    
    // Get option title and value title for display
    $option_title = '';
    $value_title = '';
    $option_definitions = $is_set ? get_set_options($product_id) : get_product_options($product_id);
    if (!empty($option_definitions)) {
        foreach ($option_definitions as $opt_def) {
            if ((int)$opt_def['id'] === (int)$option_id) {
                $option_title = $opt_def['title'] ?? '';
                if (!empty($opt_def['option_values'])) {
                    foreach ($opt_def['option_values'] as $val) {
                        if ((int)$val['id'] === (int)$value_id) {
                            $value_title = $val['title'] ?? '';
                            break;
                        }
                    }
                }
                break;
            }
        }
    }
    
    $options = [
        intval($option_id) => [
            'value_id' => intval($value_id),
            'selected_value_id' => intval($value_id), // Include both formats for compatibility
            'price' => $price,
            'option_title' => $option_title,
            'value_title' => $value_title,
            'option_id' => intval($option_id)
        ]
    ];
    
    // Pass $is_set to ensure the correct options key is used
    $result = rental_update_cart_item_options($cart_item_key, $options, true, $is_set);
    
    if ($result) {
        // Recalculate cart totals
        WC()->cart->calculate_totals();
    }
    
    return [
        'success' => $result,
        'in_cart' => true,
        'cart_item_key' => $cart_item_key,
        'message' => $result ? 'Cart item updated' : 'Failed to update cart item'
    ];
}

/**
 * Update multiple options for a cart item in batch and recalculate totals.
 *
 * @param int   $product_id Product ID.
 * @param array $options    Array of options: [['option_id' => X, 'value_id' => Y, 'price' => Z], ...]
 * @return array Result with 'success', 'cart_item_key', 'updated_count', and 'message'.
 */
/**
 * Update cart item options with full data including titles.
 * This version ensures option_title and value_title are included for mini-cart display.
 *
 * @param int   $product_id Product ID
 * @param array $options    Options to update
 * @param bool  $is_set     Whether product is a set
 * @return array Result with success status and details
 */
function rental_update_batch_cart_item_options_with_titles($product_id, $options, $is_set = false) {
    $cart_item_key = rental_find_cart_item_key_by_product_id($product_id);
    
    $result = [
        'success' => false,
        'in_cart' => false,
        'cart_item_key' => null,
        'updated_count' => 0,
        'message' => ''
    ];
    
    if (!$cart_item_key) {
        $result['message'] = 'Product not in cart';
        return $result;
    }
    
    $result['in_cart'] = true;
    $result['cart_item_key'] = $cart_item_key;
    
    // Load option definitions to get titles
    $option_definitions = $is_set ? get_set_options($product_id) : get_product_options($product_id);
    $option_lookup = [];
    foreach ($option_definitions as $opt_def) {
        $option_lookup[(int)$opt_def['id']] = $opt_def;
    }
    
    // Build options array for update - use string keys to preserve option IDs
    $options_to_update = [];
    foreach ($options as $opt) {
        $option_id = strval($opt['option_id']); // Keep as string to prevent numeric key issues
        $value_id = intval($opt['value_id']);
        
        // Get titles from definitions
        $option_title = '';
        $value_title = '';
        if (isset($option_lookup[(int)$option_id])) {
            $option_title = $option_lookup[(int)$option_id]['title'] ?? '';
            if (!empty($option_lookup[(int)$option_id]['option_values'])) {
                foreach ($option_lookup[(int)$option_id]['option_values'] as $val) {
                    if ((int)$val['id'] === $value_id) {
                        $value_title = $val['title'] ?? '';
                        break;
                    }
                }
            }
        }
        
        $options_to_update[$option_id] = [
            'value_id' => $value_id,
            'selected_value_id' => $value_id,
            'price' => $opt['price'],
            'option_title' => $option_title,
            'value_title' => $value_title,
            'option_id' => (int)$option_id
        ];
    }
    
    // First, update the cart item directly in memory
    if (!isset(WC()->cart->cart_contents[$cart_item_key])) {
        $result['message'] = 'Cart item not found';
        return $result;
    }
    
    // Determine the correct options key - check both possible keys
    $options_key = $is_set ? 'rental_selected_set_options' : 'rental_selected_options';
    
    // Get existing options - check both possible keys for backwards compatibility
    $existing_options = [];
    if (isset(WC()->cart->cart_contents[$cart_item_key][$options_key])) {
        $existing_options = WC()->cart->cart_contents[$cart_item_key][$options_key];
    } elseif (isset(WC()->cart->cart_contents[$cart_item_key]['rental_selected_options'])) {
        // Fallback to rental_selected_options if set key not found
        $existing_options = WC()->cart->cart_contents[$cart_item_key]['rental_selected_options'];
        // Update the key to use the correct one going forward
        $options_key = 'rental_selected_options';
    }
    
    // Handle corrupted data (numerically indexed with small keys like 0, 1, 2)
    if (!empty($existing_options)) {
        $first_key = array_key_first($existing_options);
        if (is_int($first_key) && $first_key < 10) {
            $existing_options = [];
        }
    }
    
    // Merge options - use integer keys to preserve option IDs
    // array_merge() reindexes numeric string keys, so use array_replace() instead
    $normalized_existing = [];
    foreach ($existing_options as $k => $v) {
        $normalized_existing[(int)$k] = $v;
    }
    
    // Convert new options to integer keys too
    $normalized_new = [];
    foreach ($options_to_update as $k => $v) {
        $normalized_new[(int)$k] = $v;
    }
    
    // Use array_replace to properly merge (preserves keys, new values overwrite old)
    $updated_options = $normalized_existing + $normalized_new;
    // Then overwrite with new values (+ gives priority to left operand, we want right)
    foreach ($normalized_new as $k => $v) {
        $updated_options[$k] = $v;
    }
    
    // Update in cart_contents
    WC()->cart->cart_contents[$cart_item_key][$options_key] = $updated_options;
    
    // CRITICAL: Save to session BEFORE calculate_totals() which might reload from session
    if (WC()->session) {
        $cart_session = WC()->session->get('cart', []);
        if (isset($cart_session[$cart_item_key])) {
            $cart_session[$cart_item_key][$options_key] = $updated_options;
            WC()->session->set('cart', $cart_session);
        }
    }
    
    // Force session save
    WC()->cart->set_session();
    
    // Now calculate totals (this might reload cart, but session is already saved)
    WC()->cart->calculate_totals();
    
    // Re-apply our options after calculate_totals in case it reloaded the cart
    WC()->cart->cart_contents[$cart_item_key][$options_key] = $updated_options;
    WC()->cart->set_session();
    
    // Double-check session has correct data
    if (WC()->session) {
        $cart_session = WC()->session->get('cart', []);
        if (isset($cart_session[$cart_item_key])) {
            $cart_session[$cart_item_key][$options_key] = $updated_options;
            WC()->session->set('cart', $cart_session);
        }
    }
    
    $result['success'] = true;
    $result['updated_count'] = count($options_to_update);
    $result['message'] = 'Cart item options updated';
    
    return $result;
}

function rental_update_batch_cart_item_options($product_id, $options) {
    $cart_item_key = rental_find_cart_item_key_by_product_id($product_id);
    
    $result = [
        'success' => false,
        'in_cart' => false,
        'cart_item_key' => null,
        'updated_count' => 0,
        'message' => ''
    ];
    
    if (!$cart_item_key) {
        $result['message'] = 'Product not in cart';
        return $result;
    }
    
    $result['in_cart'] = true;
    $result['cart_item_key'] = $cart_item_key;
    
    // Build options array for update - use string keys to preserve option IDs
    $options_to_update = [];
    foreach ($options as $opt) {
        $option_id = strval($opt['option_id']); // Keep as string to prevent numeric key issues
        $options_to_update[$option_id] = [
            'value_id' => intval($opt['value_id']),
            'selected_value_id' => intval($opt['value_id']),
            'price' => $opt['price']
        ];
    }
    
    // First, update the cart item directly in memory
    if (!isset(WC()->cart->cart_contents[$cart_item_key])) {
        $result['message'] = 'Cart item not found';
        return $result;
    }
    
    // Get existing options and merge
    $existing_options = isset(WC()->cart->cart_contents[$cart_item_key]['rental_selected_options']) 
        ? WC()->cart->cart_contents[$cart_item_key]['rental_selected_options'] 
        : [];
    
    // Handle corrupted data (numerically indexed)
    if (!empty($existing_options)) {
        $first_key = array_key_first($existing_options);
        if (is_int($first_key) && $first_key < 10) {
            $existing_options = [];
        }
    }
    
    // Merge options - convert existing keys to strings too for consistency
    $normalized_existing = [];
    foreach ($existing_options as $k => $v) {
        $normalized_existing[strval($k)] = $v;
    }
    
    // Use + operator to merge (preserves string keys and doesn't reindex)
    $updated_options = $options_to_update + $normalized_existing;
    // Then overwrite with new values (+ gives priority to left operand)
    foreach ($options_to_update as $k => $v) {
        $updated_options[$k] = $v;
    }
    
    // Update in cart_contents
    WC()->cart->cart_contents[$cart_item_key]['rental_selected_options'] = $updated_options;
    
    // CRITICAL: Save to session BEFORE calculate_totals() which might reload from session
    if (WC()->session) {
        $cart_session = WC()->session->get('cart', []);
        if (isset($cart_session[$cart_item_key])) {
            $cart_session[$cart_item_key]['rental_selected_options'] = $updated_options;
            WC()->session->set('cart', $cart_session);
        }
    }
    
    // Force session save
    WC()->cart->set_session();
    
    // Now calculate totals (this might reload cart, but session is already saved)
    WC()->cart->calculate_totals();
    
    // Re-apply our options after calculate_totals in case it reloaded the cart
    WC()->cart->cart_contents[$cart_item_key]['rental_selected_options'] = $updated_options;
    WC()->cart->set_session();
    
    // Double-check session has correct data
    if (WC()->session) {
        $cart_session = WC()->session->get('cart', []);
        if (isset($cart_session[$cart_item_key])) {
            $cart_session[$cart_item_key]['rental_selected_options'] = $updated_options;
            WC()->session->set('cart', $cart_session);
        }
    }
    
    $result['success'] = true;
    $result['updated_count'] = count($options_to_update);
    $result['message'] = 'Cart item options updated';
    
    return $result;
}

/**
 * Transfer pre-selected options from session to cart item data.
 * Called when a product is added to cart.
 *
 * @param int    $product_id    Product ID.
 * @param string $cart_item_key Cart item key of the newly added item.
 * @param bool   $is_set        Whether the product is a set.
 * @return bool True if options were transferred.
 */
function rental_transfer_session_options_to_cart_item($product_id, $cart_item_key, $is_set = false) {
    $product_id = intval($product_id);
    
    // Determine session key based on whether it's a set
    $session_key = $is_set 
        ? $product_id . '_selected_options_of_set'
        : $product_id . '_selected_options';
    
    // Get options from session
    $session_options = get_rental_session_data($session_key, []);
    
    if (empty($session_options)) {
        return false;
    }
    
    // Convert session format to cart item format if needed
    $cart_options = [];
    foreach ($session_options as $option_id => $selection) {
        $value_id = isset($selection['selected_value_id']) 
            ? $selection['selected_value_id'] 
            : (isset($selection['value_id']) ? $selection['value_id'] : null);
        
        if ($value_id !== null) {
            $cart_options[intval($option_id)] = [
                'value_id' => intval($value_id),
                'selected_value_id' => intval($value_id),
                'price' => isset($selection['price']) ? $selection['price'] : 0
            ];
        }
    }
    
    if (empty($cart_options)) {
        return false;
    }
    
    // Update cart item with session options
    $result = rental_update_cart_item_options($cart_item_key, $cart_options, false);
    
    // DO NOT clear the session data after transfer.
    // The session data is needed by the product page to restore the selected
    // options in the dropdowns after a full page reload (add-to-cart POST).
    // Clearing it causes the product page selects to reset to defaults.
    
    return $result;
}

// return WP post IDs that are add-ons or variations of add-on parents
function rental_get_add_on_post_ids() {
    global $wpdb;

    // meta key used across your sync
    $meta_key = '_rental_is_add_on';

    // posts that explicitly have the add-on meta
    $add_on_post_ids = $wpdb->get_col( $wpdb->prepare(
        "SELECT DISTINCT pm.post_id FROM {$wpdb->postmeta} pm WHERE pm.meta_key = %s AND pm.meta_value = '1'",
        $meta_key
    ) );

    if ( ! $add_on_post_ids ) {
        $add_on_post_ids = [];
    } else {
        $add_on_post_ids = array_map('intval', $add_on_post_ids);
    }

    // include variations whose parent is an add-on
    $variation_ids = [];
    if ( $add_on_post_ids ) {
        // create placeholders for prepare()
        $placeholders = implode( ',', array_fill( 0, count( $add_on_post_ids ), '%d' ) );
        $sql = "
            SELECT ID
            FROM {$wpdb->posts}
            WHERE post_parent IN ($placeholders)
              AND post_type = 'product_variation'
        ";
        $variation_ids = $wpdb->get_col( $wpdb->prepare( $sql, $add_on_post_ids ) );
        if ( $variation_ids ) {
            $variation_ids = array_map('intval', $variation_ids);
        } else {
            $variation_ids = [];
        }
    }

    $all = array_unique( array_merge( $add_on_post_ids, $variation_ids ) );
    return array_values( $all ); // array of ints
}

/**
 * Ensure every existing shipping zone has a local_pickup method.
 * Additive only — never deletes anything.
 *
 * @param wpdb   $wpdb
 * @param string $zones_table
 * @param string $methods_table
 */
function rental_ensure_local_pickup_on_all_zones( $wpdb, $zones_table, $methods_table ) {
    // Find zone IDs that already have local_pickup
    $zones_with_pickup = $wpdb->get_col(
        "SELECT DISTINCT zone_id FROM $methods_table WHERE method_id = 'local_pickup'"
    );

    // Find all zone IDs that are missing local_pickup
    if ( ! empty( $zones_with_pickup ) ) {
        $exclude_ids = implode( ',', array_map( 'intval', $zones_with_pickup ) );
        $zones_needing_pickup = $wpdb->get_col(
            "SELECT DISTINCT zone_id FROM $zones_table WHERE zone_id NOT IN ($exclude_ids)"
        );
    } else {
        $zones_needing_pickup = $wpdb->get_col(
            "SELECT DISTINCT zone_id FROM $zones_table"
        );
    }

    // Add local_pickup to each zone that needs it
    if ( ! empty( $zones_needing_pickup ) ) {
        foreach ( $zones_needing_pickup as $zone_id ) {
            $wpdb->insert( $methods_table, [
                'zone_id'      => (int) $zone_id,
                'method_id'    => 'local_pickup',
                'method_order' => 1,
                'is_enabled'   => 1,
            ] );
        }
    }
}

/**
 * Ensure a dedicated "Local Pickup" fallback zone exists.
 * Creates one only if no zone named "Local Pickup" with a local_pickup method exists.
 *
 * @param wpdb   $wpdb
 * @param string $zones_table
 * @param string $methods_table
 */
function rental_ensure_local_pickup_zone_exists( $wpdb, $zones_table, $methods_table ) {
    $existing = $wpdb->get_var( "
        SELECT wszm.zone_id
        FROM $methods_table AS wszm
        INNER JOIN $zones_table AS zones ON zones.zone_id = wszm.zone_id
        WHERE wszm.method_id = 'local_pickup'
          AND zones.zone_name = 'Local Pickup'
        LIMIT 1
    " );

    if ( empty( $existing ) ) {
        $wpdb->insert( $zones_table, [
            'zone_name'  => 'Local Pickup',
            'zone_order' => 9999,
        ] );

        $wpdb->insert( $methods_table, [
            'zone_id'      => $wpdb->insert_id,
            'method_id'    => 'local_pickup',
            'method_order' => 9999,
            'is_enabled'   => 1,
        ] );
    }
}

/**
 * Ensure miles_based shipping method is enabled and assigned to a zone.
 *
 *
 * @param wpdb   $wpdb
 * @param string $methods_table  e.g. wp_woocommerce_shipping_zone_methods
 */
function rental_ensure_miles_based_shipping_active( $wpdb, $methods_table ) {
    // Force enabled = 'yes' in the method settings option.
    // WC_Shipping_Method::is_available() checks this flag directly.
    $settings = get_option( 'woocommerce_miles_based_settings', [] );
    if ( ! is_array( $settings ) ) {
        $settings = [];
    }
    if ( ( isset( $settings['enabled'] ) ? $settings['enabled'] : '' ) !== 'yes' ) {
        $settings['enabled'] = 'yes';
        update_option( 'woocommerce_miles_based_settings', $settings );
    }

    // Ensure the method has at least one zone entry so WooCommerce
    // includes it in rate calculation.  Zone 0 = "Rest of the World".
    $existing = $wpdb->get_var(
        "SELECT instance_id FROM $methods_table WHERE method_id = 'miles_based' LIMIT 1"
    );

    if ( ! $existing ) {
        $wpdb->insert( $methods_table, [
            'zone_id'      => 0,
            'method_id'    => 'miles_based',
            'method_order' => 1,
            'is_enabled'   => 1,
        ] );
    }
}

/**
 * Self-healing: repair missing local_pickup zones/methods in the DB.
 * Called at runtime when we detect the rates are missing local_pickup.
 * Runs at most once per request to avoid repeated DB hits.
 */
function rental_repair_local_pickup_zones() {
    static $already_repaired = false;
    if ( $already_repaired ) {
        return;
    }
    $already_repaired = true;

    global $wpdb;

    $zones_table   = $wpdb->prefix . 'woocommerce_shipping_zones';
    $methods_table = $wpdb->prefix . 'woocommerce_shipping_zone_methods';

    if ( function_exists( 'rental_ensure_local_pickup_on_all_zones' ) ) {
        rental_ensure_local_pickup_on_all_zones( $wpdb, $zones_table, $methods_table );
    }

    if ( function_exists( 'rental_ensure_local_pickup_zone_exists' ) ) {
        rental_ensure_local_pickup_zone_exists( $wpdb, $zones_table, $methods_table );
    }

    // Flush WooCommerce shipping cache after repair
    rental_flush_shipping_cache();

    if ( class_exists( 'ErrorHandler' ) ) {
        ErrorHandler::registerErrorInLog(
            'Local pickup zone was missing — auto-repaired and cache flushed.',
            'rental_repair_local_pickup_zones',
            'rental-settings',
            [],
            RentalException::TYPE_SYNC_RUNTIME
        );
    }
}

/**
 * Flush all WooCommerce shipping-related caches.
 *
 * WooCommerce caches shipping rates in:
 * 1. Session data (per-customer shipping rate cache)
 * 2. Transients (shipping-transient-version controls cache invalidation)
 * 3. Object cache (if persistent caching is active)
 *
 * Without this flush, newly created local_pickup zones won't appear
 * on checkout until the cache expires naturally.
 */
function rental_flush_shipping_cache() {
    // Increment the shipping transient version — this invalidates
    //    all cached shipping rate transients across all sessions.
    WC_Cache_Helper::get_transient_version( 'shipping', true );

    // Clear any stored shipping packages in the current session
    if ( function_exists( 'WC' ) && isset( WC()->session ) && WC()->session ) {
        WC()->session->set( 'shipping_for_package_0', false );
        WC()->session->set( 'shipping_for_package', false );

        // Some setups store multiple packages
        for ( $i = 0; $i <= 5; $i++ ) {
            WC()->session->set( 'shipping_for_package_' . $i, false );
        }
    }

    // Delete any lingering shipping transients directly
    global $wpdb;
    $wpdb->query(
        "DELETE FROM {$wpdb->options} 
         WHERE option_name LIKE '%_transient_wc_ship%' 
            OR option_name LIKE '%_transient_timeout_wc_ship%'"
    );
}

/**
 * Remove ALL local pickup methods and clean up orphaned zones.
 *
 * This is the single source of truth for "no more local pickup".
 * It finds local_pickup by method_id (which WooCommerce always stores as
 * 'local_pickup' regardless of the display title/label like 'will-call').
 *
 * Steps:
 * 1. Find all zone_ids that have a local_pickup method
 * 2. Delete all local_pickup methods from ALL zones
 * 3. Delete zones that are now empty (no methods left) — these are
 *    pickup-only zones like "Local Pickup" that have no other purpose
 * 4. Delete zones explicitly named for pickup or Will Call (safety net)
 *
 * @param wpdb   $wpdb
 * @param string $zones_table   e.g. wp_woocommerce_shipping_zones
 * @param string $methods_table e.g. wp_woocommerce_shipping_zone_methods
 */
function rental_remove_all_local_pickup( $wpdb, $zones_table, $methods_table ) {

    // Identify zones that currently have local_pickup
    $zones_with_pickup = $wpdb->get_col(
        "SELECT DISTINCT zone_id FROM $methods_table WHERE method_id = 'local_pickup'"
    );

    // Delete ALL local_pickup methods from every zone
    $wpdb->query(
        "DELETE FROM $methods_table WHERE method_id = 'local_pickup'"
    );

    // Clean up orphaned zones — zones that now have ZERO methods
    // Only delete zones that HAD local_pickup (don't touch unrelated zones)
    if ( !empty( $zones_with_pickup ) ) {
        $zone_ids_csv = implode( ',', array_map( 'intval', $zones_with_pickup ) );

        // Find which of these zones now have no methods at all
        $orphaned_zones = $wpdb->get_col( "
            SELECT z.zone_id
            FROM $zones_table AS z
            LEFT JOIN $methods_table AS m ON m.zone_id = z.zone_id
            WHERE z.zone_id IN ($zone_ids_csv)
            GROUP BY z.zone_id
            HAVING COUNT(m.instance_id) = 0
        " );

        if ( !empty( $orphaned_zones ) ) {
            $orphaned_csv = implode( ',', array_map( 'intval', $orphaned_zones ) );

            // Delete orphaned zones
            $wpdb->query( "DELETE FROM $zones_table WHERE zone_id IN ($orphaned_csv)" );

            // Also clean up zone locations (wp_woocommerce_shipping_zone_locations)
            $locations_table = $wpdb->prefix . 'woocommerce_shipping_zone_locations';
            if ( $wpdb->get_var( "SHOW TABLES LIKE '$locations_table'" ) === $locations_table ) {
                $wpdb->query( "DELETE FROM $locations_table WHERE zone_id IN ($orphaned_csv)" );
            }
        }
    }

    // Safety net — also remove any zone whose name clearly indicates
    // it was a pickup-only zone (catches edge cases from manual creation)
    $pickup_named_zones = $wpdb->get_col( "
        SELECT z.zone_id
        FROM $zones_table AS z
        LEFT JOIN $methods_table AS m ON m.zone_id = z.zone_id
        WHERE (
            z.zone_name LIKE '%Pickup%'
            OR z.zone_name LIKE '%pickup%'
            OR z.zone_name LIKE '%Pick-up%'
            OR z.zone_name LIKE '%Will Call%'
            OR z.zone_name LIKE '%will-call%'
        )
        GROUP BY z.zone_id
        HAVING COUNT(m.instance_id) = 0
    " );

    if ( !empty( $pickup_named_zones ) ) {
        $pickup_csv = implode( ',', array_map( 'intval', $pickup_named_zones ) );
        $wpdb->query( "DELETE FROM $zones_table WHERE zone_id IN ($pickup_csv)" );

        $locations_table = $wpdb->prefix . 'woocommerce_shipping_zone_locations';
        if ( $wpdb->get_var( "SHOW TABLES LIKE '$locations_table'" ) === $locations_table ) {
            $wpdb->query( "DELETE FROM $locations_table WHERE zone_id IN ($pickup_csv)" );
        }
    }
}


/**
 * =============================================================================
 * RUNTIME DB ENFORCEMENT for company_delivery_return
 * =============================================================================
 *
 * If we detected stale local_pickup rates at runtime, clean the DB too.
 * Runs at most once per request. Only does DB work if local_pickup methods
 * actually exist (cheap check first).
 */
function rental_enforce_no_local_pickup_in_db() {
    static $already_checked = false;
    if ( $already_checked ) {
        return;
    }
    $already_checked = true;

    global $wpdb;

    $methods_table = $wpdb->prefix . 'woocommerce_shipping_zone_methods';
    $zones_table   = $wpdb->prefix . 'woocommerce_shipping_zones';

    // Quick check: are there any local_pickup methods in the DB?
    $count = (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM $methods_table WHERE method_id = 'local_pickup'"
    );

    if ( $count === 0 ) {
        return; // DB is clean, nothing to do
    }

    // There are stale local_pickup methods — clean them up
    rental_remove_all_local_pickup( $wpdb, $zones_table, $methods_table );
    rental_flush_shipping_cache();

    if ( class_exists( 'ErrorHandler' ) ) {
        ErrorHandler::registerErrorInLog(
            "Stale local_pickup methods found in DB while mode is company_delivery_return — cleaned up $count method(s).",
            'rental_enforce_no_local_pickup_in_db',
            'rental-settings',
            [],
            RentalException::TYPE_SYNC_RUNTIME
        );
    }
}

/**
 * Get a display name for a WC product/variation, with robust fallbacks.
 *
 * @param WC_Product $product
 * @return string
 */
function rental_get_variant_display_name( $product ) {
    $name = '';

    // Try attribute summary (e.g. "Color: Red, Size: Large")
    if ( is_callable( [ $product, 'get_attribute_summary' ] ) ) {
        $name = trim( $product->get_attribute_summary() );
    }

    // Fallback: formatted variation attributes as "Key: Value" pairs
    if ( empty( $name ) && $product->is_type( 'variation' ) && is_callable( [ $product, 'get_variation_attributes' ] ) ) {
        $attrs = $product->get_variation_attributes();
        if ( ! empty( $attrs ) ) {
            $parts = [];
            foreach ( $attrs as $attr_key => $attr_value ) {
                if ( ! empty( $attr_value ) ) {
                    $taxonomy = str_replace( 'attribute_', '', $attr_key );
                    $label    = wc_attribute_label( $taxonomy, $product );
                    $term     = taxonomy_exists( $taxonomy )
                        ? get_term_by( 'slug', $attr_value, $taxonomy )
                        : false;
                    $value    = ( $term && ! is_wp_error( $term ) ) ? $term->name : $attr_value;
                    $parts[]  = $label . ': ' . $value;
                }
            }
            $name = implode( ', ', $parts );
        }
    }

    // Fallback: product name / title
    if ( empty( $name ) ) {
        $name = $product->get_name();
    }

    return $name;
}
