<?php
/**
 * Plugin Name: Christocentric Rentals
 * Description: 24-hour camera & gear rentals — availability, pay-on-pickup, Paystack, SMTP, and optional Rentopian sync.
 * Version: 1.7.1
 * Author: Christocentric Rentals
 * Requires at least: 6.4
 * Requires PHP: 8.1
 * Text Domain: christocentric-rentals
 */

defined('ABSPATH') || exit;

define('CCR_VERSION', '1.7.1');
define('CCR_PLUGIN_FILE', __FILE__);
define('CCR_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('CCR_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once CCR_PLUGIN_DIR . 'includes/class-rental-pricing.php';
require_once CCR_PLUGIN_DIR . 'includes/class-rental-availability.php';
require_once CCR_PLUGIN_DIR . 'includes/class-rentopian-sync.php';
require_once CCR_PLUGIN_DIR . 'includes/class-product-meta.php';
require_once CCR_PLUGIN_DIR . 'includes/class-product-kits.php';
require_once CCR_PLUGIN_DIR . 'includes/class-settings.php';
require_once CCR_PLUGIN_DIR . 'includes/class-contact-form.php';
require_once CCR_PLUGIN_DIR . 'includes/class-newsletter.php';
require_once CCR_PLUGIN_DIR . 'includes/class-smtp.php';
require_once CCR_PLUGIN_DIR . 'includes/class-email.php';
require_once CCR_PLUGIN_DIR . 'includes/class-order-admin.php';
require_once CCR_PLUGIN_DIR . 'includes/class-compare.php';
require_once CCR_PLUGIN_DIR . 'includes/class-rental-due.php';
require_once CCR_PLUGIN_DIR . 'includes/class-late-notices.php';
require_once CCR_PLUGIN_DIR . 'includes/class-hold-expiry.php';
require_once CCR_PLUGIN_DIR . 'includes/class-legacy-redirects.php';
require_once CCR_PLUGIN_DIR . 'includes/class-google-auth.php';
require_once CCR_PLUGIN_DIR . 'includes/class-rental-agreement.php';
require_once CCR_PLUGIN_DIR . 'includes/class-private-media.php';
require_once CCR_PLUGIN_DIR . 'includes/class-admin-media-ui.php';
require_once CCR_PLUGIN_DIR . 'includes/class-verification-import.php';
require_once CCR_PLUGIN_DIR . 'includes/class-disable-email-confirm.php';
require_once CCR_PLUGIN_DIR . 'includes/class-studio-cpt.php';
require_once CCR_PLUGIN_DIR . 'includes/class-studio-settings.php';
require_once CCR_PLUGIN_DIR . 'includes/class-studio-booking.php';

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
        register_deactivation_hook(CCR_PLUGIN_FILE, [$this, 'deactivate']);
    }

    public function init(): void
    {
        CCR_Settings::init();
        CCR_Product_Meta::init();
        CCR_Product_Kits::init();
        CCR_Rentopian_Sync::init();
        CCR_Contact_Form::init();
        CCR_Newsletter::init();
        CCR_Smtp::init();
        CCR_Email::init();
        CCR_Compare::init();
        CCR_Hold_Expiry::init();
        CCR_Late_Notices::init();
        CCR_Legacy_Redirects::init();
        CCR_Google_Auth::init();
        CCR_Rental_Agreement::init();
        CCR_Private_Media::init();
        CCR_Admin_Media_Ui::init();
        CCR_Verification_Import::init();
        CCR_Disable_Email_Confirm::init();
        CCR_Studio_Cpt::init();
        CCR_Studio_Settings::init();
        CCR_Studio_Booking::init();

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
        CCR_Order_Admin::init();
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
        add_option('ccr_latest_return_time', '20:50');
        add_option('ccr_grace_minutes', 30);
        add_option('ccr_daily_rate_multiplier', 1);
        add_option('ccr_due_soon_hours', 2);
        add_option('ccr_global_discount_percent', 0);
        add_option('ccr_banner_title', 'Gear Sale');
        add_option('ccr_banner_line_1', '{percent}% off every rental — cameras, lenses, lights, and more.');
        add_option('ccr_banner_line_2', 'Discount applies automatically at checkout. No coupon needed.');
        add_option('ccr_banner_cta', 'Shop');
        add_option('ccr_newsletter_send_welcome', 'yes');
        add_option('ccr_newsletter_notify_admin', 'yes');
        add_option('ccr_newsletter_notify_email', 'support@christocentricrentals.com');
        add_option('ccr_newsletter_from_name', 'Christocentric Rentals');
        add_option('ccr_newsletter_from_email', 'support@christocentricrentals.com');
        add_option('ccr_smtp_enabled', 'no');
        add_option('ccr_smtp_port', 587);
        add_option('ccr_smtp_encryption', 'tls');
        add_option('ccr_smtp_auth', 'yes');
        add_option('ccr_google_enabled', 'no');
        update_option('woocommerce_enable_myaccount_registration', 'yes');

        CCR_Newsletter::install();
        CCR_Hold_Expiry::schedule();
        CCR_Late_Notices::schedule();
        CCR_Compare::ensure_page();
        CCR_Studio_Cpt::register();
        CCR_Studio_Cpt::maybe_seed_default();
        flush_rewrite_rules();
    }

    public function deactivate(): void
    {
        CCR_Hold_Expiry::unschedule();
        CCR_Late_Notices::unschedule();
    }
}

Christocentric_Rentals::instance();
