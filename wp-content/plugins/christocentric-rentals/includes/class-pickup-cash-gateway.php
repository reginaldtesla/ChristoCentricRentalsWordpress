<?php

defined('ABSPATH') || exit;

class CCR_Gateway_Pickup_Cash extends WC_Payment_Gateway
{
    public function __construct()
    {
        $this->id = 'ccr_pickup_cash';
        $this->method_title = __('Pay on pickup (cash)', 'christocentric-rentals');
        $this->method_description = __('Customer pays at the Bomso office when collecting gear. Order reserves stock for a limited time.', 'christocentric-rentals');
        $this->has_fields = false;
        $this->supports = ['products'];

        $this->init_form_fields();
        $this->init_settings();

        $this->enabled = $this->get_option('enabled', 'yes');
        $this->title = $this->get_option('title', __('Pay on pickup (cash)', 'christocentric-rentals'));
        $this->description = $this->get_option('description', __('Reserve your gear and pay when you collect it at our Bomso office. Bring a valid Ghana Card.', 'christocentric-rentals'));

        add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
    }

    public function init_form_fields(): void
    {
        $this->form_fields = [
            'enabled' => [
                'title' => __('Enable', 'christocentric-rentals'),
                'type' => 'checkbox',
                'label' => __('Enable pay on pickup', 'christocentric-rentals'),
                'default' => 'yes',
            ],
            'title' => [
                'title' => __('Title', 'christocentric-rentals'),
                'type' => 'text',
                'default' => __('Pay on pickup (cash)', 'christocentric-rentals'),
            ],
            'description' => [
                'title' => __('Description', 'christocentric-rentals'),
                'type' => 'textarea',
                'default' => __('Reserve your gear and pay when you collect it at our Bomso office. Bring a valid Ghana Card.', 'christocentric-rentals'),
            ],
        ];
    }

    public function process_payment($order_id): array
    {
        $order = wc_get_order($order_id);

        if (! $order) {
            return ['result' => 'fail'];
        }

        $order->update_meta_data('_ccr_payment_method', 'pickup_cash');
        $order->set_status('on-hold', __('Awaiting payment on pickup.', 'christocentric-rentals'));
        $order->save();

        WC()->cart->empty_cart();

        return [
            'result' => 'success',
            'redirect' => $order->get_checkout_order_received_url(),
        ];
    }
}

final class CCR_Pickup_Cash_Gateway
{
    public static function init(): void
    {
        add_filter('woocommerce_payment_gateways', static function (array $gateways): array {
            $gateways[] = CCR_Gateway_Pickup_Cash::class;

            return $gateways;
        });
    }
}
