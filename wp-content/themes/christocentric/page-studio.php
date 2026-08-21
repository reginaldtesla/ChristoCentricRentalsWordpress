<?php
defined('ABSPATH') || exit;

$successOrderId = absint($_GET['order'] ?? 0); // phpcs:ignore
$bookingStatus = isset($_GET['booking']) ? sanitize_key(wp_unslash($_GET['booking'])) : ''; // phpcs:ignore
$isSuccess = $bookingStatus === 'success' && $successOrderId > 0;
$isPending = $bookingStatus === 'pending' && $successOrderId > 0;
$successOrder = null;
$waUrl = '';
$waContext = 'paid';

if (($isSuccess || $isPending) && function_exists('wc_get_order') && class_exists('CCR_Studio_Booking')) {
    $successOrder = wc_get_order($successOrderId);
    if ($successOrder instanceof WC_Order && (string) $successOrder->get_meta(CCR_Studio_Booking::META_FLAG) === '1') {
        $settings = class_exists('CCR_Studio_Settings') ? CCR_Studio_Settings::get() : [];
        $wa = preg_replace('/\D+/', '', (string) ($settings['whatsapp'] ?? '')) ?: '233532670582';
        $waContext = $isPending ? 'pending' : 'paid';
        $msg = CCR_Studio_Booking::whatsapp_message_for_order($successOrder, $waContext);
        $waUrl = 'https://wa.me/' . $wa . '?text=' . rawurlencode($msg);
    } else {
        $isSuccess = false;
        $isPending = false;
        $successOrder = null;
    }
}

get_header();

$studioSettings = class_exists('CCR_Studio_Settings') ? CCR_Studio_Settings::get() : [];
$heroImage = (string) ($studioSettings['hero_image_url'] ?? '');
$heroClass = 'ccr-studio-book-hero' . ($heroImage !== '' ? ' has-image' : '');
$heroStyle = $heroImage !== ''
    ? ' style="--ccr-studio-hero-image:url(\'' . esc_url($heroImage) . '\')"'
    : '';
$momoNumber = (string) ($studioSettings['momo_pay_number'] ?? '');
$momoNetwork = (string) ($studioSettings['momo_pay_network'] ?? 'MTN');
$momoReference = (string) ($studioSettings['momo_reference'] ?? 'Studio Rentals');
if ($successOrder instanceof WC_Order) {
    $orderMomo = (string) $successOrder->get_meta('_ccr_studio_momo_pay_number');
    if ($orderMomo !== '') {
        $momoNumber = $orderMomo;
    }
    $orderNet = (string) $successOrder->get_meta('_ccr_studio_momo_pay_network');
    if ($orderNet !== '') {
        $momoNetwork = $orderNet;
    }
    $orderRef = (string) $successOrder->get_meta('_ccr_studio_momo_reference');
    if ($orderRef !== '') {
        $momoReference = $orderRef;
    }
}
$amountDue = $successOrder instanceof WC_Order
    ? (float) ($successOrder->get_meta('_ccr_studio_deposit_due') ?: $successOrder->get_meta('_ccr_studio_full_total'))
    : 0.0;
$holdMinutes = class_exists('CCR_Studio_Booking') ? (int) CCR_Studio_Booking::AWAITING_HOLD_MINUTES : 10;
?>

<div class="ccr-studio-book-shell ccr-studio-book-shell--mcb" data-ccr-studio-book>
    <a href="<?php echo esc_url(home_url('/')); ?>" class="ccr-studio-book-back">
        <svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M12.79 5.23a.75.75 0 01-.02 1.06L8.832 10l3.938 3.71a.75.75 0 11-1.04 1.08l-4.5-4.25a.75.75 0 010-1.08l4.5-4.25a.75.75 0 011.06.02z" clip-rule="evenodd"/></svg>
        <?php esc_html_e('Back', 'christocentric'); ?>
    </a>

    <?php if ($isPending && $successOrder instanceof WC_Order) : ?>
        <div class="ccr-studio-book-layout">
            <aside class="<?php echo esc_attr($heroClass); ?>"<?php echo $heroStyle; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
                <div class="ccr-studio-book-hero-inner">
                    <a href="<?php echo esc_url(home_url('/')); ?>" class="ccr-studio-book-logo">
                        <img src="<?php echo esc_url(ccr_theme_asset('images/brand/logo.png')); ?>" alt="<?php echo esc_attr(get_bloginfo('name')); ?>">
                    </a>
                    <h1 class="ccr-studio-book-title">
                        <span><?php esc_html_e('Send', 'christocentric'); ?></span>
                        <em><?php esc_html_e('MoMo', 'christocentric'); ?></em>
                    </h1>
                    <p class="ccr-studio-book-lead"><?php echo esc_html(sprintf(__('Your slot is held for %d minutes while you pay. After that others can book it, but we can still confirm your payment later and lock the time again if it is still free.', 'christocentric'), $holdMinutes)); ?></p>
                </div>
            </aside>
            <section class="ccr-studio-book-panel">
                <div class="ccr-studio-book-card">
                    <h2 class="ccr-studio-book-heading"><?php esc_html_e('Pay by Mobile Money', 'christocentric'); ?></h2>
                    <p class="ccr-studio-book-sub"><?php echo esc_html(sprintf(__('Order #%s', 'christocentric'), $successOrder->get_order_number())); ?></p>

                    <div class="ccr-studio-momo-box">
                        <div class="ccr-studio-momo-row"><span><?php esc_html_e('Amount', 'christocentric'); ?></span><strong>GHS <?php echo esc_html(number_format($amountDue, 2)); ?></strong></div>
                        <div class="ccr-studio-momo-row"><span><?php esc_html_e('Network', 'christocentric'); ?></span><strong><?php echo esc_html($momoNetwork); ?></strong></div>
                        <div class="ccr-studio-momo-row"><span><?php esc_html_e('MoMo number', 'christocentric'); ?></span><strong><?php echo esc_html($momoNumber); ?></strong></div>
                        <div class="ccr-studio-momo-row"><span><?php esc_html_e('Reference', 'christocentric'); ?></span><strong><?php echo esc_html($momoReference); ?></strong></div>
                        <p class="ccr-studio-momo-note"><?php esc_html_e('Use this exact reference so we can match your payment.', 'christocentric'); ?></p>
                    </div>

                    <div class="ccr-studio-book-summary-static">
                        <?php echo class_exists('CCR_Studio_Booking') ? CCR_Studio_Booking::summary_html($successOrder) : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                    </div>
                    <div class="ccr-studio-book-actions">
                        <?php if ($waUrl !== '') : ?>
                            <a class="ccr-studio-book-btn ccr-studio-book-btn--solid" href="<?php echo esc_url($waUrl); ?>" target="_blank" rel="noopener noreferrer">
                                <?php esc_html_e('Send proof on WhatsApp', 'christocentric'); ?>
                            </a>
                        <?php endif; ?>
                        <a class="ccr-studio-book-btn ccr-studio-book-btn--ghost" href="<?php echo esc_url(home_url('/')); ?>">
                            <?php esc_html_e('Back to home', 'christocentric'); ?>
                        </a>
                    </div>
                </div>
            </section>
        </div>
    <?php elseif ($isSuccess && $successOrder instanceof WC_Order) : ?>
        <div class="ccr-studio-book-layout">
            <aside class="<?php echo esc_attr($heroClass); ?>"<?php echo $heroStyle; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
                <div class="ccr-studio-book-hero-inner">
                    <a href="<?php echo esc_url(home_url('/')); ?>" class="ccr-studio-book-logo">
                        <img src="<?php echo esc_url(ccr_theme_asset('images/brand/logo.png')); ?>" alt="<?php echo esc_attr(get_bloginfo('name')); ?>">
                    </a>
                    <h1 class="ccr-studio-book-title">
                        <span><?php esc_html_e('Booking', 'christocentric'); ?></span>
                        <em><?php esc_html_e('Confirmed', 'christocentric'); ?></em>
                    </h1>
                    <p class="ccr-studio-book-lead"><?php esc_html_e('Your MoMo payment is confirmed. We’ve emailed the details — you can also ping us on WhatsApp.', 'christocentric'); ?></p>
                </div>
            </aside>
            <section class="ccr-studio-book-panel">
                <div class="ccr-studio-book-card">
                    <h2 class="ccr-studio-book-heading"><?php esc_html_e('You’re booked', 'christocentric'); ?></h2>
                    <p class="ccr-studio-book-sub"><?php echo esc_html(sprintf(__('Order #%s', 'christocentric'), $successOrder->get_order_number())); ?></p>
                    <div class="ccr-studio-book-summary-static">
                        <?php echo class_exists('CCR_Studio_Booking') ? CCR_Studio_Booking::summary_html($successOrder) : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                    </div>
                    <div class="ccr-studio-book-actions">
                        <?php if ($waUrl !== '') : ?>
                            <a class="ccr-studio-book-btn ccr-studio-book-btn--solid" href="<?php echo esc_url($waUrl); ?>" target="_blank" rel="noopener noreferrer">
                                <?php esc_html_e('Message us on WhatsApp', 'christocentric'); ?>
                            </a>
                        <?php endif; ?>
                        <a class="ccr-studio-book-btn ccr-studio-book-btn--ghost" href="<?php echo esc_url(home_url('/')); ?>">
                            <?php esc_html_e('Back to home', 'christocentric'); ?>
                        </a>
                    </div>
                </div>
            </section>
        </div>
    <?php else : ?>
        <div class="ccr-studio-book-layout">
            <aside class="<?php echo esc_attr($heroClass); ?>"<?php echo $heroStyle; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
                <div class="ccr-studio-book-hero-inner">
                    <a href="<?php echo esc_url(home_url('/')); ?>" class="ccr-studio-book-logo">
                        <img src="<?php echo esc_url(ccr_theme_asset('images/brand/logo.png')); ?>" alt="<?php echo esc_attr(get_bloginfo('name')); ?>">
                    </a>
                    <p class="ccr-studio-book-eyebrow" data-ccr-studio-eyebrow></p>
                    <h1 class="ccr-studio-book-title">
                        <span data-ccr-studio-t1></span>
                        <span data-ccr-studio-t2></span>
                        <em data-ccr-studio-te></em>
                    </h1>
                    <p class="ccr-studio-book-lead" data-ccr-studio-lead></p>
                    <dl class="ccr-studio-book-stats" data-ccr-studio-stats></dl>
                    <p class="ccr-studio-book-address" data-ccr-studio-address></p>
                </div>
            </aside>

            <section class="ccr-studio-book-panel" aria-labelledby="ccr-studio-book-heading">
                <div class="ccr-studio-book-card" data-ccr-studio-wizard>
                    <div class="ccr-studio-book-progress" aria-live="polite">
                        <div class="ccr-studio-book-progress-meta">
                            <span data-ccr-studio-step-label><?php esc_html_e('Step 1 of 6', 'christocentric'); ?></span>
                            <span data-ccr-studio-step-pct>17%</span>
                        </div>
                        <div class="ccr-studio-book-progress-track" aria-hidden="true">
                            <span class="ccr-studio-book-progress-fill" data-ccr-studio-progress style="width:17%"></span>
                        </div>
                    </div>

                    <h2 id="ccr-studio-book-heading" class="ccr-studio-book-heading"><?php esc_html_e('New Reservation', 'christocentric'); ?></h2>
                    <p class="ccr-studio-book-sub" data-ccr-studio-step-sub><?php esc_html_e('Select a set to begin — takes under 2 minutes.', 'christocentric'); ?></p>

                    <div data-ccr-studio-mount></div>

                    <p class="ccr-studio-book-error" data-ccr-studio-error hidden></p>

                    <div class="ccr-studio-book-actions">
                        <button type="button" class="ccr-studio-book-btn ccr-studio-book-btn--ghost" data-ccr-studio-prev hidden>
                            <?php esc_html_e('Back', 'christocentric'); ?>
                        </button>
                        <button type="button" class="ccr-studio-book-btn ccr-studio-book-btn--solid" data-ccr-studio-next>
                            <?php esc_html_e('Continue', 'christocentric'); ?>
                        </button>
                        <button type="button" class="ccr-studio-book-btn ccr-studio-book-btn--solid" data-ccr-studio-pay hidden>
                            <?php esc_html_e('Confirm booking', 'christocentric'); ?>
                        </button>
                    </div>
                </div>
            </section>
        </div>
    <?php endif; ?>
</div>

<?php get_footer(); ?>
