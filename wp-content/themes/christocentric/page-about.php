<?php
defined('ABSPATH') || exit;

$page = ccr_page_json('about');
get_header();
get_template_part('template-parts/page-hero', null, [
    'title' => $page['hero_title'] ?? 'About Us',
    'subtitle' => $page['hero_subtitle'] ?? '',
]);
?>
<div class="container-site max-w-3xl py-12">
    <?php if (! empty($page['intro'])) : ?>
        <p class="text-lg leading-relaxed text-gray-600"><?php echo esc_html($page['intro']); ?></p>
    <?php endif; ?>

    <?php foreach ($page['sections'] ?? [] as $section) : ?>
        <h2 class="mt-10 text-xl font-semibold text-gray-900"><?php echo esc_html($section['heading'] ?? ''); ?></h2>
        <p class="leading-relaxed text-gray-600"><?php echo esc_html($section['body'] ?? ''); ?></p>
    <?php endforeach; ?>

    <?php if (! empty($page['bullets'])) : ?>
        <h2 class="mt-10 text-xl font-semibold text-gray-900"><?php echo esc_html($page['bullets_heading'] ?? 'Why Rent With Us'); ?></h2>
        <ul class="mt-4 space-y-3 text-gray-600">
            <?php foreach ($page['bullets'] as $bullet) : ?>
                <li><?php echo esc_html($bullet); ?></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <div class="mt-10">
        <a href="<?php echo esc_url(ccr_shop_url()); ?>" class="btn-solid"><?php echo esc_html($page['cta_text'] ?? 'Browse Our Gear'); ?></a>
    </div>
</div>
<?php get_footer(); ?>
