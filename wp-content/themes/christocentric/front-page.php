<?php
/**
 * Front page — matches Laravel resources/views/home/index.blade.php
 */
get_header();

$popular = ccr_get_products(['limit' => 12, 'orderby' => 'meta_value_num', 'meta_key' => '_ccr_rating', 'order' => 'DESC']);
$panels = [
    'new' => ccr_get_products(['limit' => 6, 'orderby' => 'date', 'order' => 'DESC']),
    'featured' => ccr_get_products(['limit' => 6, 'meta_query' => [['key' => '_ccr_is_featured', 'value' => 'yes']]]),
    'top_rated' => ccr_get_products(['limit' => 6, 'orderby' => 'meta_value_num', 'meta_key' => '_ccr_rating', 'order' => 'DESC']),
];
$hero_slides = ccr_site_config('hero_slides', []);
$deals = ccr_site_config('deals_slides', []);
$banners = ccr_site_config('brand_banners', []);
$weekly = ccr_site_config('weekly_deals', []);
$lighting = ccr_site_config('featured_lighting', []);
$shop = ccr_shop_url();
?>

<section class="carousel-shell" data-hero-slider>
    <div class="relative min-h-[380px] md:min-h-[440px]">
        <?php foreach ($hero_slides as $index => $slide) : ?>
            <div data-hero-slide class="carousel-slide absolute inset-0 transition-opacity duration-500 <?php echo $index === 0 ? 'opacity-100' : 'pointer-events-none opacity-0'; ?>">
                <div class="container-site flex h-full min-h-[380px] flex-col md:min-h-[440px] md:flex-row md:items-center md:gap-12">
                    <div class="flex flex-1 flex-col justify-center py-10 md:py-12 md:pr-4">
                        <p class="text-sm text-gray-500"><?php echo esc_html($slide['subtitle'] ?? ''); ?></p>
                        <h1 class="mt-1 text-3xl font-semibold leading-tight text-gray-900 md:text-4xl lg:text-[2.75rem]"><?php echo esc_html($slide['title'] ?? ''); ?></h1>
                        <p class="mt-3 max-w-md text-base leading-relaxed text-gray-600"><?php echo esc_html($slide['description'] ?? ''); ?></p>
                        <a href="<?php echo esc_url(ccr_resolve_url($slide['cta_url'] ?? '/shop/')); ?>" class="btn-solid mt-7 w-fit"><?php echo esc_html($slide['cta_primary'] ?? 'Browse gear'); ?></a>
                    </div>
                    <div class="carousel-product-stage flex-1 pb-8 md:pb-0">
                        <img src="<?php echo esc_url(ccr_image_url($slide['image'] ?? '')); ?>" alt="<?php echo esc_attr($slide['title'] ?? ''); ?>">
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
        <button type="button" data-hero-prev class="carousel-arrow carousel-arrow-prev" aria-label="Previous"><svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg></button>
        <button type="button" data-hero-next class="carousel-arrow carousel-arrow-next" aria-label="Next"><svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg></button>
    </div>
</section>

<?php get_template_part('template-parts/trust-bar'); ?>

<section class="home-section container-site">
    <?php get_template_part('template-parts/section-title', null, ['title' => 'Popular right now', 'link' => $shop, 'link_text' => 'All products']); ?>
    <div class="grid gap-8 lg:grid-cols-[minmax(0,1fr)_280px]">
        <div class="min-w-0"><?php get_template_part('template-parts/product-scroll', null, ['products' => $popular]); ?></div>
        <aside class="hidden lg:block">
            <div class="sidebar-note sticky top-24">
                <p class="text-sm font-medium text-gray-900">Lighting kits</p>
                <p class="mt-2 text-sm leading-relaxed text-gray-600">LED panels and modifiers for interviews, film sets and events.</p>
                <a href="<?php echo esc_url(ccr_shop_url(['product_cat' => 'continuous-light'])); ?>" class="mt-5 block text-sm font-medium text-primary hover:underline">Browse lights</a>
            </div>
        </aside>
    </div>
</section>

<section class="home-section home-section-alt">
    <div class="container-site">
        <?php get_template_part('template-parts/section-title', null, ['title' => 'Highlighted this week', 'link' => $shop, 'link_text' => 'See catalog', 'size' => 'large']); ?>
        <div class="relative px-12 md:px-14" data-deals-slider>
            <div class="deals-panel relative overflow-hidden rounded border border-gray-200 bg-white">
                <?php foreach ($deals as $index => $deal) : ?>
                    <div data-deals-slide class="transition-opacity duration-500 <?php echo $index === 0 ? 'opacity-100' : 'pointer-events-none absolute inset-0 opacity-0'; ?>">
                        <div class="grid items-center md:grid-cols-2">
                            <div class="p-8 md:p-10 md:pr-6">
                                <p class="text-sm text-gray-500"><?php echo esc_html($deal['badge'] ?? ''); ?></p>
                                <h3 class="mt-1 text-2xl font-semibold text-gray-900 md:text-3xl"><?php echo esc_html($deal['title'] ?? ''); ?></h3>
                                <?php if (! empty($deal['before'])) : ?><p class="mt-3 text-sm leading-relaxed text-gray-600"><?php echo esc_html($deal['before']); ?></p><?php endif; ?>
                                <a href="<?php echo esc_url(ccr_resolve_url($deal['url'] ?? '/shop/')); ?>" class="btn-solid mt-6">View in shop</a>
                            </div>
                            <div class="flex items-center justify-center bg-gray-50 p-8 md:p-10">
                                <img src="<?php echo esc_url(ccr_image_url($deal['image'] ?? '')); ?>" alt="<?php echo esc_attr($deal['title'] ?? ''); ?>" class="max-h-52 object-contain md:max-h-64">
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <button type="button" data-deals-prev class="carousel-arrow carousel-arrow-prev deals-carousel-arrow" aria-label="Previous"><svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg></button>
            <button type="button" data-deals-next class="carousel-arrow carousel-arrow-next deals-carousel-arrow" aria-label="Next"><svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg></button>
        </div>
    </div>
</section>

<section class="home-section container-site">
    <?php get_template_part('template-parts/section-title', null, ['title' => 'Browse by category', 'link' => $shop, 'link_text' => 'Full catalog']); ?>
    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <?php foreach ($banners as $banner) : ?>
            <a href="<?php echo esc_url(ccr_resolve_url($banner['url'] ?? '/shop/')); ?>" class="group block overflow-hidden rounded border border-gray-200 bg-white transition hover:border-gray-300">
                <div class="aspect-[4/3] overflow-hidden bg-gray-100">
                    <img src="<?php echo esc_url(ccr_image_url($banner['image'] ?? '')); ?>" alt="" class="h-full w-full object-cover transition duration-300 group-hover:scale-[1.02]">
                </div>
                <div class="p-4">
                    <h3 class="font-medium text-gray-900"><?php echo esc_html($banner['title'] ?? ''); ?></h3>
                    <p class="mt-1 text-sm text-gray-600"><?php echo esc_html($banner['description'] ?? ''); ?></p>
                </div>
            </a>
        <?php endforeach; ?>
    </div>
</section>

<section class="home-section container-site">
    <?php get_template_part('template-parts/section-title', null, ['title' => 'Staff picks', 'link' => $shop, 'link_text' => 'More gear', 'size' => 'large']); ?>
    <div class="grid gap-4 md:grid-cols-3">
        <?php foreach ($weekly as $deal) : ?>
            <a href="<?php echo esc_url(ccr_resolve_url($deal['url'] ?? '/shop/')); ?>" class="pick-card group block overflow-hidden rounded border border-gray-200 bg-white transition hover:border-gray-300">
                <div class="aspect-[16/10] overflow-hidden bg-gray-100">
                    <img src="<?php echo esc_url(ccr_image_url($deal['image'] ?? '')); ?>" alt="" class="h-full w-full object-cover object-center transition duration-300 group-hover:scale-[1.02]">
                </div>
                <div class="p-5">
                    <h3 class="font-medium text-gray-900"><?php echo esc_html($deal['title'] ?? ''); ?></h3>
                    <p class="mt-1 text-sm text-gray-600"><?php echo esc_html($deal['description'] ?? ''); ?></p>
                </div>
            </a>
        <?php endforeach; ?>
    </div>
</section>

<section class="home-section home-section-alt">
    <div class="container-site">
        <?php get_template_part('template-parts/section-title', null, ['title' => 'Lighting & grip', 'link' => ccr_shop_url(['product_cat' => 'continuous-light']), 'link_text' => 'All lighting']); ?>
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <?php foreach ($lighting as $item) : ?>
                <a href="<?php echo esc_url(ccr_shop_url(['product_cat' => 'continuous-light'])); ?>" class="group block overflow-hidden rounded border border-gray-200 bg-white p-4 transition hover:border-gray-300">
                    <div class="aspect-square overflow-hidden rounded bg-gray-50 p-4">
                        <img src="<?php echo esc_url(ccr_image_url($item['image'] ?? '')); ?>" alt="" class="h-full w-full object-contain">
                    </div>
                    <h3 class="mt-4 font-medium text-gray-900"><?php echo esc_html($item['title'] ?? ''); ?></h3>
                    <p class="mt-1 text-sm text-gray-600"><?php echo esc_html($item['description'] ?? ''); ?></p>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<?php get_template_part('template-parts/brand-strip'); ?>

<section class="home-section container-site" data-product-tabs data-tab-style="pill">
    <?php get_template_part('template-parts/section-title', null, ['title' => 'From the catalog']); ?>
    <div class="mb-5 flex flex-wrap gap-2">
        <?php foreach (['new' => 'New', 'featured' => 'Featured', 'top_rated' => 'Top Rated'] as $key => $label) : ?>
            <button type="button" data-tab-trigger="<?php echo esc_attr($key); ?>" class="tab-trigger rounded border px-3 py-1.5 text-sm font-medium transition <?php echo $key === 'new' ? 'border-gray-900 bg-gray-900 text-white' : 'border-gray-200 bg-white text-gray-600 hover:border-gray-300'; ?>"><?php echo esc_html($label); ?></button>
        <?php endforeach; ?>
    </div>
    <?php foreach ($panels as $key => $products) : ?>
        <div data-tab-panel="<?php echo esc_attr($key); ?>" class="<?php echo $key === 'new' ? '' : 'hidden'; ?>">
            <div class="grid grid-cols-2 gap-3 sm:gap-4 md:grid-cols-3 lg:grid-cols-4 xl:grid-cols-6">
                <?php foreach ($products as $product) : ?>
                    <?php get_template_part('template-parts/product-card', null, ['product' => $product]); ?>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endforeach; ?>
</section>

<section class="home-section border-t border-gray-200">
    <div class="container-site flex flex-col items-start justify-between gap-5 py-10 md:flex-row md:items-center">
        <div>
            <h2 class="text-lg font-semibold text-gray-900">Need help choosing gear?</h2>
            <p class="mt-1 text-sm text-gray-600">Call <?php echo esc_html(ccr_site_config('contact.phone_display', '')); ?> or read our <a href="<?php echo esc_url(home_url('/help/')); ?>" class="text-primary hover:underline">rental guide</a>.</p>
        </div>
        <a href="<?php echo esc_url(home_url('/contact/')); ?>" class="btn-solid">Contact us</a>
    </div>
</section>

<?php get_footer(); ?>
