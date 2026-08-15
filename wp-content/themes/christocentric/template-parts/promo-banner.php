<?php
defined('ABSPATH') || exit;

$banner = function_exists('ccr_promo_banner') ? ccr_promo_banner() : null;
if (! is_array($banner)) {
    return;
}
?>
<div class="site-promo-banner" role="status">
    <div class="container-site site-promo-banner-inner">
        <p class="site-promo-banner-title"><?php echo esc_html($banner['title']); ?></p>
        <div class="site-promo-banner-copy">
            <?php if ($banner['line_1'] !== '') : ?>
                <p><?php echo esc_html($banner['line_1']); ?></p>
            <?php endif; ?>
            <?php if ($banner['line_2'] !== '') : ?>
                <p><?php echo esc_html($banner['line_2']); ?></p>
            <?php endif; ?>
        </div>
        <a href="<?php echo esc_url(ccr_shop_url()); ?>" class="site-promo-banner-cta"><?php echo esc_html($banner['cta']); ?></a>
    </div>
</div>
