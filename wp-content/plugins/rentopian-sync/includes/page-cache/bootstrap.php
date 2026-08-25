<?php
/**
 * Page cache guard loader.
 *
 * Keeps the rental date gate correct on sites fronted by a full page cache.
 *
 * @package RentopianSync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-rental-page-cache-guard.php';

Rental_Page_Cache_Guard::register();
