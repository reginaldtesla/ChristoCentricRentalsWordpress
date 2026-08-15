<?php

defined('ABSPATH') || exit;

function ccr_account_endpoint(): string
{
    if (! function_exists('WC') || ! WC()->query) {
        return '';
    }

    return (string) WC()->query->get_current_endpoint();
}

function ccr_account_hero(): void
{
    $endpoint = ccr_account_endpoint();
    $titles = [
        'orders'       => ['My Orders', 'Track your rental bookings.'],
        'view-order'   => ['Order Details', 'Rental booking summary.'],
        'edit-account' => ['My Profile', 'Update your account details.'],
        'edit-address' => ['Addresses', 'Billing and shipping addresses.'],
        'rental-agreement' => ['Client verification', 'Ghana Card, guarantor, and pickup identity details.'],
    ];

    [$title, $subtitle] = $titles[$endpoint] ?? ['My Account', 'Manage orders and account details.'];

    get_template_part('template-parts/page-hero', null, [
        'title'    => $title,
        'subtitle' => $subtitle,
    ]);
}

function ccr_account_logout_link(): void
{
    if (ccr_account_endpoint() === 'customer-logout') {
        return;
    }

    echo '<a href="' . esc_url(wc_logout_url()) . '" class="ccr-account-logout mt-8 inline-block text-sm text-gray-500 transition hover:text-red-600">';
    echo esc_html__('Log out', 'christocentric');
    echo '</a>';
}
