<?php
/**
 * Attribute Groups Module — Bootstrap
 *
 * Loads the storage / read API for attribute value groups and makes sure its
 * two tables exist.
 *
 * The schema check runs on `admin_init` rather than only on activation:
 * `rental_create_tables()` is an activation hook, so a site that receives this
 * as an ordinary plugin update would never create the tables and every group
 * write would quietly do nothing. The webhook handler and the sync call
 * `ensure_tables()` themselves as well, so a site whose admin is never opened
 * still gets them on the first delivery.
 *
 * @package RentopianSync\AttributeGroups
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once __DIR__ . '/class-attribute-groups.php';

add_action( 'admin_init', [ 'Rental_Attribute_Groups', 'ensure_tables' ] );
