<?php defined('ABSPATH') || exit; $brands = array_slice(ccr_site_config('brands', []), 0, 8); ?>
<section class="home-section container-site">
    <div class="flex flex-wrap items-center justify-center gap-x-4 gap-y-2 text-sm text-gray-500">
        <?php foreach ($brands as $brand) : ?>
            <a href="<?php echo esc_url(add_query_arg('s', $brand, ccr_shop_url())); ?>" class="hover:text-primary"><?php echo esc_html($brand); ?></a>
        <?php endforeach; ?>
    </div>
</section>
