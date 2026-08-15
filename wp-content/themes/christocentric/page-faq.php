<?php
defined('ABSPATH') || exit;

$page = ccr_page_json('faq');
get_header();
get_template_part('template-parts/page-hero', null, [
    'title' => $page['hero_title'] ?? 'FAQ',
    'subtitle' => $page['hero_subtitle'] ?? '',
]);
?>
<div class="container-site faq-page">
    <div class="faq-list">
        <?php foreach ($page['items'] ?? [] as $item) : ?>
            <details class="faq-item">
                <summary>
                    <?php echo esc_html($item['q'] ?? ''); ?>
                    <span class="faq-toggle" aria-hidden="true">+</span>
                </summary>
                <p><?php echo esc_html($item['a'] ?? ''); ?></p>
            </details>
        <?php endforeach; ?>
    </div>
    <div class="mt-10 border-t border-gray-200 pt-8 text-center text-sm text-gray-600">
        <p>
            Still need help?
            <a href="<?php echo esc_url(home_url('/help/')); ?>" class="text-primary hover:underline">Help Center</a>
            or
            <a href="<?php echo esc_url(home_url('/contact/')); ?>" class="text-primary hover:underline">Contact us</a>.
        </p>
    </div>
</div>
<?php get_footer(); ?>
