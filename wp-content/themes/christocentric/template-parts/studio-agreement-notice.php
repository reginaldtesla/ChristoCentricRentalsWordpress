<?php
defined('ABSPATH') || exit;

if (! (is_page('studio') || (function_exists('ccr_is_studio_subdomain') && ccr_is_studio_subdomain()))) {
    return;
}

$pdfUrl = ccr_theme_asset('docs/studio-rental-agreement.pdf');
$sections = [
    [
        'title' => __('Payment', 'christocentric'),
        'body' => __('Pay to confirm your booking. The full balance is due before the session starts. MoMo, bank transfer, or pre-confirmed cash.', 'christocentric'),
    ],
    [
        'title' => __('Time & overtime', 'christocentric'),
        'body' => __('The clock starts at your booked time, even if you arrive late. 30 minutes late with no notice is a no-show. 15 minutes packing grace, then overtime fees apply.', 'christocentric'),
    ],
    [
        'title' => __('Cancel or reschedule', 'christocentric'),
        'body' => __('48+ hours: full refund or store credit. 24–48 hours: 70% refunded. Under 24 hours or no-show: no refund. One free reschedule if you give 48+ hours’ notice.', 'christocentric'),
    ],
    [
        'title' => __('Studio care', 'christocentric'),
        'body' => __('GHS 200 refundable caution. No food, open drinks, smoking, or flames. On the CYC wall: shoe covers or bare feet — never stand on the curve.', 'christocentric'),
    ],
    [
        'title' => __('You’re responsible', 'christocentric'),
        'body' => __('Your crew, visitors, and your own gear. Content must be lawful. We may use behind-the-scenes shots for marketing unless you tell us otherwise.', 'christocentric'),
    ],
    [
        'title' => __('Before you shoot', 'christocentric'),
        'body' => __('Anyone under 18 needs a parent or guardian present. We walk through the space together; note existing damage or it is treated as accepted.', 'christocentric'),
    ],
];
?>
<div class="ccr-welcome-notice ccr-welcome-notice--studio" data-studio-agreement hidden aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="ccr-studio-agreement-title">
    <div class="ccr-welcome-notice__backdrop" data-studio-agreement-close tabindex="-1"></div>
    <div class="ccr-welcome-notice__panel" data-studio-agreement-panel>
        <button type="button" class="ccr-welcome-notice__close" data-studio-agreement-close aria-label="<?php esc_attr_e('Close', 'christocentric'); ?>">
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
            <p class="ccr-welcome-notice__kicker"><?php esc_html_e('Read before you book', 'christocentric'); ?></p>
            <h2 id="ccr-studio-agreement-title" class="ccr-welcome-notice__title"><?php esc_html_e('Studio rental agreement', 'christocentric'); ?></h2>
            <p class="ccr-welcome-notice__lead"><?php esc_html_e('A few house rules so your session goes smoothly. Full legal terms are in the PDF.', 'christocentric'); ?></p>
        </div>

        <div class="ccr-welcome-notice__body">
            <ol class="ccr-welcome-notice__list">
                <?php foreach ($sections as $index => $item) : ?>
                    <li class="ccr-welcome-notice__item" style="--i: <?php echo (int) $index; ?>">
                        <span class="ccr-welcome-notice__index" aria-hidden="true"><?php echo str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT); ?></span>
                        <div class="ccr-welcome-notice__copy">
                            <strong><?php echo esc_html($item['title']); ?></strong>
                            <span><?php echo esc_html($item['body']); ?></span>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ol>

            <div class="ccr-welcome-notice__actions">
                <button type="button" class="ccr-welcome-notice__cta" data-studio-agreement-close>
                    <span><?php esc_html_e('I agree — continue booking', 'christocentric'); ?></span>
                </button>
                <a href="<?php echo esc_url($pdfUrl); ?>" class="ccr-welcome-notice__link" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Download the full PDF', 'christocentric'); ?></a>
            </div>
        </div>
    </div>
</div>
