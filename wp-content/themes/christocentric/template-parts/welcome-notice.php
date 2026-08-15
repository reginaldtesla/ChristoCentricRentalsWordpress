<?php
defined('ABSPATH') || exit;

$address = trim((string) ccr_site_config('contact.address', 'Bomso'));
$city = trim((string) ccr_site_config('contact.city', 'Kumasi'));
$location = trim($address . ($city !== '' ? ', ' . $city : ''), ', ');

$items = [
    [
        'q' => __('Do first-time clients get delivery?', 'christocentric'),
        'a' => __('No. First-time clients pick up in person. Delivery may be available for returning clients.', 'christocentric'),
    ],
    [
        'q' => __('What ID is required?', 'christocentric'),
        'a' => sprintf(
            /* translators: %s: pickup location */
            __('A valid Ghana Card is required at pickup (%s).', 'christocentric'),
            $location !== '' ? $location : __('our Kumasi office', 'christocentric')
        ),
    ],
    [
        'q' => __('How do I pay?', 'christocentric'),
        'a' => __('Pay online with Paystack (card / mobile money), or choose pay on pickup (cash) where offered.', 'christocentric'),
    ],
    [
        'q' => __('Can someone else pick up for me?', 'christocentric'),
        'a' => __('The account holder should be present with valid ID at pickup.', 'christocentric'),
    ],
    [
        'q' => __('Can I cancel?', 'christocentric'),
        'a' => __('Yes. Cancel up to 48 hours before pickup for a full refund (minus processing fees).', 'christocentric'),
    ],
];
?>
<div class="ccr-welcome-notice" data-welcome-notice hidden aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="ccr-welcome-title">
    <div class="ccr-welcome-notice__backdrop" data-welcome-close tabindex="-1"></div>
    <div class="ccr-welcome-notice__panel" data-welcome-panel>
        <button type="button" class="ccr-welcome-notice__close" data-welcome-close aria-label="<?php esc_attr_e('Close', 'christocentric'); ?>">
            <span aria-hidden="true">&times;</span>
        </button>

        <div class="ccr-welcome-notice__hero">
            <div class="ccr-welcome-notice__glow" aria-hidden="true"></div>
            <img
                src="<?php echo esc_url(ccr_theme_asset('images/brand/logo.png')); ?>"
                alt="<?php echo esc_attr(get_bloginfo('name')); ?>"
                class="ccr-welcome-notice__logo"
                width="160"
                height="48"
            >
            <p class="ccr-welcome-notice__kicker"><?php esc_html_e('Wait — don’t close yet', 'christocentric'); ?></p>
            <h2 id="ccr-welcome-title" class="ccr-welcome-notice__title"><?php esc_html_e('Make sure you read this', 'christocentric'); ?></h2>
            <p class="ccr-welcome-notice__lead"><?php esc_html_e('A few house rules so your first rental goes smoothly.', 'christocentric'); ?></p>
        </div>

        <div class="ccr-welcome-notice__body">
            <ol class="ccr-welcome-notice__list">
                <?php foreach ($items as $index => $item) : ?>
                    <li class="ccr-welcome-notice__item" style="--i: <?php echo (int) $index; ?>">
                        <span class="ccr-welcome-notice__index" aria-hidden="true"><?php echo str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT); ?></span>
                        <div class="ccr-welcome-notice__copy">
                            <strong><?php echo esc_html($item['q']); ?></strong>
                            <span><?php echo esc_html($item['a']); ?></span>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ol>

            <div class="ccr-welcome-notice__actions">
                <button type="button" class="ccr-welcome-notice__cta" data-welcome-close>
                    <span><?php esc_html_e('Got it — continue shopping', 'christocentric'); ?></span>
                </button>
                <a href="<?php echo esc_url(home_url('/faq/')); ?>" class="ccr-welcome-notice__link"><?php esc_html_e('Read full FAQ', 'christocentric'); ?></a>
            </div>
        </div>
    </div>
</div>
