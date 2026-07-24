<?php
/**
 * Plugin Name: Christocentric Rentals
 * Description: Daily camera & gear rentals — availability, pay-on-pickup, Paystack hooks, and Rentopian order sync for Christocentric Rentals.
 * Version: 1.0.0
 * Author: Christocentric Rentals
 * Requires at least: 6.4
 * Requires PHP: 8.1
 * Text Domain: christocentric-rentals
 */

defined('ABSPATH') || exit;

define('CCR_VERSION', '1.0.0');
define('CCR_PLUGIN_FILE', __FILE__);
define('CCR_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('CCR_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once CCR_PLUGIN_DIR . 'includes/class-rental-pricing.php';
require_once CCR_PLUGIN_DIR . 'includes/class-rental-availability.php';
require_once CCR_PLUGIN_DIR . 'includes/class-rentopian-sync.php';
require_once CCR_PLUGIN_DIR . 'includes/class-product-meta.php';
require_once CCR_PLUGIN_DIR . 'includes/class-settings.php';
require_once CCR_PLUGIN_DIR . 'includes/class-contact-form.php';
require_once CCR_PLUGIN_DIR . 'includes/class-newsletter.php';

final class Christocentric_Rentals
{
    private static ?self $instance = null;

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    private function __construct()
    {
        add_action('plugins_loaded', [$this, 'init']);
        register_activation_hook(CCR_PLUGIN_FILE, [$this, 'activate']);
    }

    public function init(): void
    {
        CCR_Settings::init();
        CCR_Product_Meta::init();
        CCR_Rentopian_Sync::init();
        CCR_Contact_Form::init();
        CCR_Newsletter::init();

        if (! class_exists('WooCommerce')) {
            add_action('admin_notices', static function (): void {
                echo '<div class="notice notice-warning"><p><strong>Christocentric Rentals</strong> requires WooCommerce. Please install and activate WooCommerce.</p></div>';
            });

            return;
        }

        require_once CCR_PLUGIN_DIR . 'includes/class-pickup-cash-gateway.php';
        require_once CCR_PLUGIN_DIR . 'includes/class-rental-cart.php';

        CCR_Pickup_Cash_Gateway::init();
        CCR_Rental_Cart::init();
    }

    public function activate(): void
    {
        if (! class_exists('WooCommerce')) {
            deactivate_plugins(plugin_basename(CCR_PLUGIN_FILE));
            wp_die('Christocentric Rentals requires WooCommerce. Install WooCommerce first, then activate this plugin.');
        }

        add_option('ccr_pickup_cash_enabled', 'yes');
        add_option('ccr_pickup_cash_hold_hours', 72);
        add_option('ccr_online_hold_hours', 2);
        add_option('ccr_rentopian_base_url', 'https://api.rentopian.com');
        add_option('ccr_default_pickup_time', '09:00');
        add_option('ccr_default_return_time', '17:00');
        add_option('ccr_newsletter_send_welcome', 'yes');
        add_option('ccr_newsletter_notify_admin', 'yes');
        add_option('ccr_newsletter_notify_email', 'support@christocentricrentals.com');
        add_option('ccr_newsletter_from_name', 'Christocentric Rentals');
        add_option('ccr_newsletter_from_email', 'support@christocentricrentals.com');

        CCR_Newsletter::install();
    }
}

Christocentric_Rentals::instance();
