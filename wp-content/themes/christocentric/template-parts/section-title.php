<?php
defined('ABSPATH') || exit;
$title = $args['title'] ?? '';
$link = $args['link'] ?? null;
$link_text = $args['link_text'] ?? 'View all';
$size = $args['size'] ?? 'default';
$title_class = $size === 'large' ? 'text-xl md:text-2xl' : 'text-lg md:text-xl';
?>
<div class="home-section-head">
    <h2 class="font-semibold tracking-tight text-gray-900 <?php echo esc_attr($title_class); ?>"><?php echo esc_html($title); ?></h2>
    <?php if ($link) : ?>
        <a href="<?php echo esc_url($link); ?>" class="text-sm text-gray-500 transition hover:text-primary"><?php echo esc_html($link_text); ?></a>
    <?php endif; ?>
</div>
