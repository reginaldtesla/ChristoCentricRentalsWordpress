<?php
/**
 * Front page — matches Laravel resources/views/home/index.blade.php
 */
get_header();

$popular = ccr_get_products([
    'limit' => 12,
    'orderby' => 'meta_value_num',
    'meta_key' => '_ccr_rating',
    'order' => 'DESC',
    'category' => [
        'cameras',
        'canon-cameras',
        'sony-cameras',
        'lens',
        'canon-lenses',
        'sigma-lenses',
        'sony-lenses',
    ],
]);
// Only products assigned to the New Arrivals category — no auto-fill fallbacks.
$newArrivals = ccr_get_products([
    'limit' => 12,
    'category' => ['new-arrivals'],
    'orderby' => 'date',
    'order' => 'DESC',
]);
$newArrivalsUrl = ccr_shop_url(['product_cat' => 'new-arrivals']);
$termNew = get_term_by('slug', 'new-arrivals', 'product_cat');
if ($termNew instanceof WP_Term) {
    $link = get_term_link($termNew, 'product_cat');
    if (! is_wp_error($link)) {
        $newArrivalsUrl = (string) $link;
    }
}
$panels = [
    'new' => ccr_get_products(['limit' => 6, 'orderby' => 'date', 'order' => 'DESC']),
    'featured' => ccr_get_products(['limit' => 6, 'meta_query' => [['key' => '_ccr_is_featured', 'value' => 'yes']]]),
    'top_rated' => ccr_get_products(['limit' => 6, 'orderby' => 'meta_value_num', 'meta_key' => '_ccr_rating', 'order' => 'DESC']),
];
$hero_slides = ccr_hero_slides();
$deals = ccr_deals_slides();
$banners = ccr_brand_banners();
$lighting = ccr_featured_lighting();
$shop = ccr_shop_url();
$pickedForYou = ccr_personalized_products(12);
$pickedForYouUrl = ccr_personalized_shop_url();
?>

<section class="ccr-promo-hero carousel-shell" data-hero-slider>
    <div class="ccr-promo-hero-track relative">
        <?php foreach ($hero_slides as $index => $slide) : ?>
            <?php
            $theme = sanitize_html_class((string) ($slide['theme'] ?? 'mist'));
            $isFirst = $index === 0;
            ?>
            <div
                data-hero-slide
                data-hero-theme="<?php echo esc_attr($theme); ?>"
                class="ccr-promo-hero-slide ccr-promo-hero-slide--<?php echo esc_attr($theme); ?> carousel-slide absolute inset-0 transition-opacity duration-500 <?php echo $isFirst ? 'opacity-100' : 'pointer-events-none opacity-0'; ?>"
            >
                <div class="container-site ccr-promo-hero-inner">
                    <div class="ccr-promo-hero-copy">
                        <?php if (! empty($slide['eyebrow'])) : ?>
                            <p class="ccr-promo-hero-eyebrow"><?php echo esc_html($slide['eyebrow']); ?></p>
                        <?php endif; ?>
                        <h1 class="ccr-promo-hero-title"><?php echo esc_html($slide['title'] ?? ''); ?></h1>
                        <?php if (! empty($slide['description'])) : ?>
                            <p class="ccr-promo-hero-desc"><?php echo esc_html($slide['description']); ?></p>
                        <?php endif; ?>
                        <?php if (! empty($slide['tagline'])) : ?>
                            <p class="ccr-promo-hero-tagline"><?php echo esc_html($slide['tagline']); ?></p>
                        <?php endif; ?>
                        <a href="<?php echo esc_url(ccr_resolve_url($slide['cta_url'] ?? '/shop/')); ?>" class="ccr-promo-hero-cta">
                            <?php echo esc_html($slide['cta_primary'] ?? 'Rent Now'); ?>
                        </a>
                    </div>
                    <div class="ccr-promo-hero-media<?php echo ! empty($slide['flip']) ? ' ccr-promo-hero-media--flip' : ''; ?>">
                        <img
                            src="<?php echo esc_url($slide['image_url'] ?? ccr_image_url($slide['image'] ?? '')); ?>"
                            alt="<?php echo esc_attr($slide['title'] ?? ''); ?>"
                            loading="<?php echo $isFirst ? 'eager' : 'lazy'; ?>"
                        >
                    </div>
                </div>
            </div>
        <?php endforeach; ?>

        <?php if (count($hero_slides) > 1) : ?>
            <button type="button" data-hero-prev class="carousel-arrow carousel-arrow-prev ccr-promo-hero-arrow" aria-label="Previous">
                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
            </button>
            <button type="button" data-hero-next class="carousel-arrow carousel-arrow-next ccr-promo-hero-arrow" aria-label="Next">
                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
            </button>
            <div class="ccr-promo-hero-dots" role="tablist" aria-label="<?php esc_attr_e('Hero slides', 'christocentric'); ?>">
                <?php foreach ($hero_slides as $index => $slide) : ?>
                    <button
                        type="button"
                        data-hero-dot
                        class="ccr-promo-hero-dot <?php echo $index === 0 ? 'w-6 bg-primary' : 'w-1.5 bg-gray-300'; ?>"
                        aria-label="<?php echo esc_attr(sprintf(/* translators: %d: slide number */ __('Go to slide %d', 'christocentric'), $index + 1)); ?>"
                    ></button>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</section>

<?php get_template_part('template-parts/trust-bar'); ?>

<section class="home-section container-site ccr-popular-picks">
    <?php get_template_part('template-parts/section-title', null, ['title' => "Today's Popular Picks", 'link' => $shop, 'link_text' => 'See All Products']); ?>
    <div class="ccr-popular-picks-grid">
        <div class="ccr-popular-picks-rail min-w-0">
            <?php get_template_part('template-parts/product-scroll', null, ['products' => $popular, 'variant' => 'popular']); ?>
        </div>
        <aside class="ccr-popular-picks-promo">
            <a
                href="<?php echo esc_url(ccr_shop_url(['product_cat' => 'continuous-light'])); ?>"
                class="ccr-gear-demand"
            >
                <div class="ccr-gear-demand-copy">
                    <p class="ccr-gear-demand-title">Gear On<br>Demand.</p>
                    <p class="ccr-gear-demand-tag">Best in lighting</p>
                    <span class="ccr-gear-demand-cta">Rent Now</span>
                </div>
                <div class="ccr-gear-demand-media">
                    <img
                        src="<?php echo esc_url(ccr_theme_asset('images/promo/gear-on-demand-softbox.png') . '?v=3.14'); ?>"
                        alt="<?php esc_attr_e('Studio softbox on light stand', 'christocentric'); ?>"
                        loading="lazy"
                    >
                </div>
            </a>
        </aside>
    </div>
</section>

<?php if ($newArrivals !== []) : ?>
<section class="home-section ccr-new-arrivals" data-ccr-pop-section>
    <div class="container-site">
        <div class="ccr-new-arrivals-panel" data-ccr-pop-panel>
            <div class="ccr-new-arrivals-head" data-ccr-pop-item>
                <span class="ccr-new-arrivals-badge"><?php esc_html_e('Just in', 'christocentric'); ?></span>
                <?php
                get_template_part('template-parts/section-title', null, [
                    'title' => __('New Arrivals', 'christocentric'),
                    'link' => $newArrivalsUrl,
                    'link_text' => __('See all new gear', 'christocentric'),
                ]);
                ?>
            </div>
            <div data-ccr-pop-item>
                <?php get_template_part('template-parts/product-scroll', null, ['products' => $newArrivals]); ?>
            </div>
        </div>
    </div>
</section>
<?php endif; ?>

<?php if ($pickedForYou !== []) : ?>
<section class="home-section container-site ccr-picked-for-you">
    <?php
    get_template_part('template-parts/section-title', null, [
        'title' => __('Picked for you', 'christocentric'),
        'link' => $pickedForYouUrl,
        'link_text' => __('See more like this', 'christocentric'),
    ]);
    ?>
    <p class="ccr-picked-for-you-note"><?php esc_html_e('Based on gear you recently viewed on this device.', 'christocentric'); ?></p>
    <?php get_template_part('template-parts/product-scroll', null, ['products' => $pickedForYou]); ?>
</section>
<?php endif; ?>

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
                                <img src="<?php echo esc_url($deal['image_url'] ?? ccr_image_url($deal['image'] ?? '')); ?>" alt="<?php echo esc_attr($deal['title'] ?? ''); ?>" class="max-h-52 object-contain md:max-h-64">
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
                    <img src="<?php echo esc_url($banner['image_url'] ?? ccr_image_url($banner['image'] ?? '')); ?>" alt="<?php echo esc_attr($banner['title'] ?? ''); ?>" class="h-full w-full object-cover transition duration-300 group-hover:scale-[1.02]" loading="lazy" decoding="async">
                </div>
                <div class="p-4">
                    <h3 class="font-medium text-gray-900"><?php echo esc_html($banner['title'] ?? ''); ?></h3>
                    <p class="mt-1 text-sm text-gray-600"><?php echo esc_html($banner['description'] ?? ''); ?></p>
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
                <a href="<?php echo esc_url($item['url'] ?? ccr_shop_url(['product_cat' => 'continuous-light'])); ?>" class="group block overflow-hidden rounded border border-gray-200 bg-white p-4 transition hover:border-gray-300">
                    <div class="aspect-square overflow-hidden rounded bg-gray-50 p-4">
                        <img src="<?php echo esc_url($item['image_url'] ?? ccr_image_url($item['image'] ?? '')); ?>" alt="<?php echo esc_attr($item['title'] ?? ''); ?>" class="h-full w-full object-contain">
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
