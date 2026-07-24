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
    <?php if (! empty($page['footer_note'])) : ?>
        <p class="doc-footer-note"><?php echo esc_html($page['footer_note']); ?></p>
    <?php endif; ?>
</div>
<?php get_footer(); ?>
