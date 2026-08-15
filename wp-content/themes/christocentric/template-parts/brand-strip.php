<?php
defined('ABSPATH') || exit;

$brands = function_exists('ccr_brand_logos') ? ccr_brand_logos() : [];
if ($brands === []) {
    return;
}
?>
<section class="home-section container-site ccr-brand-strip">
    <h2 class="ccr-brand-strip-title">Explore our Exclusive Brands</h2>
    <div class="ccr-brand-strip-row">
        <?php foreach ($brands as $brand) : ?>
            <?php
            $name = (string) ($brand['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $src = (string) ($brand['image_url'] ?? '');
            if ($src === '' && ! empty($brand['file'])) {
                $src = ccr_theme_asset('images/brands/' . ltrim((string) $brand['file'], '/')) . '?v=3.19';
            }
            if ($src === '') {
                continue;
            }
            ?>
            <a
                href="<?php echo esc_url(add_query_arg('s', $name, ccr_shop_url())); ?>"
                class="ccr-brand-strip-logo"
                aria-label="<?php echo esc_attr($name); ?>"
            >
                <img src="<?php echo esc_url($src); ?>" alt="<?php echo esc_attr($name); ?>" loading="lazy" decoding="async">
            </a>
        <?php endforeach; ?>
    </div>
</section>
